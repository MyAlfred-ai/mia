<?php

declare(strict_types=1);

/**
 * Génération d'images via OpenAI (DALL-E / ChatGPT natif) ou Gemini (Nano Banana / Imagen).
 */
class ImageGenerator
{
    /**
     * Images source au plus par appel : l'image de départ et quatre
     * références (ReferenceImages::MAX_PER_GENERATION). Bien en deçà de ce
     * qu'acceptent les fournisseurs — 16 pour les modèles GPT Image
     * (référence de /v1/images/edits), 14 pour Nano Banana (guide Gemini
     * « image generation ») —, relus le 2026-10-12 : chaque image d'entrée
     * se paie en tokens et en latence.
     */
    public const MAX_SOURCE_IMAGES = 5;

    /**
     * Point d'entrée unique.
     * $tool est une ligne de la table chat_tools.
     * $editImage = image(s) de départ : une seule ({b64, mime}) ou une liste,
     * dans l'ordre où le modèle doit les lire (l'image à retoucher d'abord,
     * puis les références). Cf. sourceImages().
     * $instructions = persona/style/contraintes à appliquer côté modèle image
     * (envoyées via le champ `instructions` de la Responses API pour OpenAI ;
     * fusionnées dans le prompt visuel pour les autres providers).
     * Retourne ['ok' => true, 'image_url' => '...', 'revised_prompt' => '...']
     * ou       ['error' => 'message'].
     */
    public static function generate(array $tool, string $prompt, ?array $editImage = null, ?string $instructions = null): array
    {
        $provider = $tool['provider'];
        $model    = $tool['model'];

        // Modèle arrêté par son fournisseur (catalogue, `retirement`) : aucun
        // appel, un message qui dit quoi faire. Point de passage de TOUTES les
        // images (chat, assistants, sites illustrés, présentations, tests).
        $arret = ToolCatalog::retiredNotice('image', (string)$provider, (string)$model);
        if ($arret !== null) {
            return ['error' => $arret];
        }

        $config   = json_decode($tool['config_json'] ?? '{}', true) ?: [];

        // Clé dédiée à l'outil en priorité, sinon clé globale du fournisseur
        $apiKey = !empty($tool['api_key_enc'])
            ? Encryption::decrypt($tool['api_key_enc'])
            : ApiKey::getDecrypted($provider);

        if (!$apiKey) {
            return ['error' => "Clé API {$provider} non configurée. Ajoutez-la dans Administration → Modèles IA."];
        }

        $instructions = $instructions !== null ? trim($instructions) : null;
        if ($instructions === '') $instructions = null;

        $sources = self::sourceImages($editImage);

        return match ($provider) {
            'openai' => self::generateOpenAi($apiKey, $model, $prompt, $config, $sources, $instructions),
            'gemini' => self::generateGemini($apiKey, $model, $prompt, $config, $sources, $instructions),
            default  => ['error' => "Fournisseur de génération d'image inconnu : {$provider}"],
        };
    }

    /**
     * Images source d'un appel, sous une forme unique pour les trois chemins
     * de retouche : une image seule ({b64, mime}) ou une liste. Blancs du
     * base64 retirés, type non image ramené à PNG, image vide écartée,
     * bornée à MAX_SOURCE_IMAGES. Pure.
     *
     * @return list<array{b64:string, mime:string}>
     */
    public static function sourceImages(?array $images): array
    {
        if ($images === null || $images === []) {
            return [];
        }
        $list = array_is_list($images) ? $images : [$images];
        $out  = [];
        foreach ($list as $img) {
            if (!is_array($img)) {
                continue;
            }
            $b64 = (string)(preg_replace('/\s+/', '', (string)($img['b64'] ?? '')) ?? '');
            if ($b64 === '') {
                continue;
            }
            $mime = strtolower(trim((string)($img['mime'] ?? 'image/png')));
            if (!str_starts_with($mime, 'image/')) {
                $mime = 'image/png';
            }
            $out[] = ['b64' => $b64, 'mime' => $mime];
            if (count($out) >= self::MAX_SOURCE_IMAGES) {
                break;
            }
        }
        return $out;
    }

    /**
     * L'outil sait-il partir d'une image source (image-to-image / retouche) ?
     * Source unique consultée par le chat AVANT de joindre une image : sans
     * cela, un outil non capable (DALL-E, Imagen) partirait en erreur dure.
     *
     *  - OpenAI Responses API (GPT-5.x natifs) : image en `input_image`.
     *  - OpenAI gpt-image-*                    : endpoint /v1/images/edits.
     *  - Gemini generateContent (Nano Banana)  : `inlineData`.
     *  - DALL-E 2/3, Imagen :predict           : non.
     */
    public static function supportsImageEdit(array $tool): bool
    {
        $provider = (string)($tool['provider'] ?? '');
        $model    = trim((string)($tool['model'] ?? ''));
        if ($model === '') {
            return false;
        }

        if ($provider === 'openai') {
            return self::usesResponsesImageTool($model) || str_starts_with($model, 'gpt-image');
        }
        if ($provider === 'gemini') {
            $routing = ToolCatalog::routing('image', 'gemini', $model);
            if ($routing !== null) {
                return $routing === 'generate_content';
            }
            return !str_contains($model, 'imagen'); // heuristique de secours
        }

        return false;
    }

    /** Rapport largeur:hauteur d'un format d'image (Imagen, aspect_ratio). */
    private const FORMAT_RATIOS = ['square' => '1:1', 'landscape' => '16:9', 'portrait' => '9:16'];

    /**
     * L'outil réglé pour un FORMAT d'image (`square`, `landscape`,
     * `portrait` ; autre valeur : l'outil tel quel) : la plus petite taille
     * de cette orientation parmi celles que le modèle ACCEPTE (catalogue — la
     * moins chère, une valeur hors liste serait refusée), et le rapport
     * largeur:hauteur pour les modèles qui le prennent. Ne change rien d'autre
     * de l'outil. Pure (lit le catalogue).
     */
    public static function orientedTool(array $tool, string $format): array
    {
        if (!isset(self::FORMAT_RATIOS[$format])) {
            return $tool;
        }
        $config = json_decode((string)($tool['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        $provider = (string)($tool['provider'] ?? '');
        $model    = (string)($tool['model'] ?? '');
        if ($provider === 'openai') {
            $sizes = self::usesResponsesImageTool($model)
                ? (ToolCatalog::options('image', 'openai', 'responses_sizes') ?: ['auto', '1024x1024', '1024x1536', '1536x1024'])
                : self::sizesFor($model);
            $size = self::sizeFor($sizes, $format);
            if ($size !== null) {
                $config['size'] = $size;
            }
        }
        if (in_array(self::FORMAT_RATIOS[$format], ToolCatalog::modelOptions('image', $provider, $model, 'aspect_ratios'), true)) {
            $config['aspect_ratio'] = self::FORMAT_RATIOS[$format];
        }
        $tool['config_json'] = (string)json_encode($config, JSON_UNESCAPED_UNICODE);
        return $tool;
    }

    /** La plus petite taille « LxH » d'une orientation, parmi une liste ; null si aucune. Pure. */
    public static function sizeFor(array $sizes, string $format): ?string
    {
        $best = null;
        $bestArea = PHP_INT_MAX;
        foreach ($sizes as $size) {
            if (!preg_match('/^(\d+)x(\d+)$/', (string)$size, $m)) {
                continue;
            }
            [$w, $h] = [(int)$m[1], (int)$m[2]];
            $fits = match ($format) {
                'square'    => $w === $h,
                'landscape' => $w > $h,
                'portrait'  => $h > $w,
                default     => false,
            };
            if ($fits && $w * $h < $bestArea) {
                [$best, $bestArea] = [(string)$size, $w * $h];
            }
        }
        return $best;
    }

    // -------------------------------------------------------------------------
    // OpenAI — DALL-E / ChatGPT natif
    // -------------------------------------------------------------------------

    /**
     * Qualités acceptées par un modèle image OpenAI — lues au catalogue, qui
     * en est la SOURCE UNIQUE (cf. ToolCatalog::modelOptions). Le repli ne
     * sert qu'à un modèle absent du catalogue : les valeurs communes à toute
     * la famille gpt-image-*, sans xhigh ni max, que seuls les 2.5 acceptent.
     *
     * @return list<string>
     */
    private static function qualitiesFor(string $model): array
    {
        $liste = ToolCatalog::modelOptions('image', 'openai', $model, 'qualities');
        if ($liste !== []) {
            return $liste;
        }
        return str_starts_with($model, 'gpt-image')
            ? ['auto', 'low', 'medium', 'high']
            : ['standard', 'hd'];
    }

    /**
     * Tailles acceptées par un modèle image OpenAI (même source unique).
     * @return list<string>
     */
    private static function sizesFor(string $model): array
    {
        $liste = ToolCatalog::modelOptions('image', 'openai', $model, 'sizes');
        return $liste !== [] ? $liste : ['auto', '1024x1024', '1024x1536', '1536x1024'];
    }

    /** @param list<array{b64:string, mime:string}> $sources */
    private static function generateOpenAi(
        string $apiKey,
        string $model,
        string $prompt,
        array $config,
        array $sources = [],
        ?string $instructions = null
    ): array
    {
        // Les modèles ChatGPT (ex: GPT-5.x) génèrent l'image via Responses API + image_generation.
        if (self::usesResponsesImageTool($model)) {
            return self::generateViaResponsesApi($apiKey, $model, $prompt, $config, $sources, $instructions);
        }
        // DALL-E / images_generations : pas de champ instructions, on fusionne en
        // préfixe court du prompt visuel pour conserver l'intention de style.
        if ($instructions !== null && $instructions !== '') {
            $prompt = $instructions . "\n\n" . $prompt;
        }

        // Image source : /v1/images/generations ne la prend pas. La famille
        // gpt-image-* passe par /v1/images/edits (multipart) ; DALL-E 2/3 n'a
        // pas d'équivalent exploitable ici.
        if ($sources !== []) {
            if (str_starts_with($model, 'gpt-image')) {
                return self::editViaImagesApi($apiKey, $model, $prompt, $config, $sources);
            }
            return ['error' => "Le modèle « {$model} » ne supporte pas l'édition d'une image existante. Utilisez GPT Image 2.5 (Sunburst ou Flare), GPT Image 2, un modèle ChatGPT image natif (GPT-5.x) ou Gemini Nano Banana."];
        }

        // NB : 'response_format' retiré — l'API OpenAI le rejette désormais (Unknown parameter).
        // La réponse contient b64_json (gpt-image-*) ou url (dall-e-*).
        $payloadArr = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => $config['size'] ?? '1024x1024',
        ];
        // 'quality' : chaque modèle a les siennes ('standard'/'hd' pour DALL-E,
        // low/medium/high/auto pour GPT Image 2, plus xhigh/max pour les 2.5).
        // Une valeur hors liste est refusée par OpenAI : on ne l'envoie pas et
        // on laisse le défaut serveur. Liste lue au catalogue, jamais recopiée.
        $quality = (string)($config['quality'] ?? '');
        if ($quality !== '' && in_array($quality, self::qualitiesFor($model), true)) {
            $payloadArr['quality'] = $quality;
        }
        $payload = json_encode($payloadArr);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]),
            'content'       => $payload,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $url = ToolCatalog::endpoint('image', 'openai', 'images_generations')
            ?? 'https://api.openai.com/v1/images/generations';
        $res = file_get_contents($url, false, $ctx);
        if ($res === false) {
            error_log('[ImageGenerator] DALL-E — impossible de contacter l\'API. model=' . $model);
            return ['error' => 'Impossible de contacter l\'API OpenAI (timeout ou réseau).'];
        }

        $data = json_decode($res, true);
        if (isset($data['error'])) {
            $httpStatus = $http_response_header[0] ?? '?';
            error_log('[ImageGenerator] DALL-E — erreur API. model=' . $model . ' | status=' . $httpStatus . ' | réponse=' . $res);
            return self::apiError($data['error'], 'OpenAI', LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null));
        }

        // Selon le modèle : b64_json (gpt-image-*) ou url (dall-e-2/3, défaut sans response_format).
        $item = $data['data'][0] ?? [];
        if (!empty($item['b64_json'])) {
            $filename = self::saveImage(base64_decode((string)$item['b64_json']), 'png');
        } elseif (!empty($item['url'])) {
            $dl = self::downloadImageFromUrl((string)$item['url']);
            if ($dl === null) {
                error_log('[ImageGenerator] DALL-E — téléchargement de l\'image (url) échoué. model=' . $model . ' | réponse=' . $res);
                return ['error' => 'Image générée mais téléchargement impossible.'];
            }
            $filename = self::saveImage($dl['binary'], (string)($dl['ext'] ?? 'png'));
        } else {
            error_log('[ImageGenerator] DALL-E — aucune image dans la réponse. model=' . $model . ' | réponse=' . $res);
            return ['error' => 'Aucune image reçue depuis DALL-E.'];
        }

        return [
            'ok'             => true,
            'image_url'      => '/assets/img/generated/' . $filename,
            'revised_prompt' => $data['data'][0]['revised_prompt'] ?? null,
            'model'          => $model,
            // Taille et qualité RÉELLEMENT produites (renvoyées par les modèles
            // GPT Image ; la qualité « auto » y est résolue), sinon la config.
            'size'           => (string)($data['size']    ?? $config['size']    ?? '1024x1024'),
            'quality'        => (string)($data['quality'] ?? $config['quality'] ?? 'standard'),
            // Consommation facturée par OpenAI : sert au coût réel quand
            // l'outil n'a pas de prix renseigné (tarif au million de tokens).
            'usage'          => is_array($data) ? self::usageFromResponse($data) : null,
        ];
    }

    /**
     * Consommation d'une réponse de l'API images d'OpenAI (modèles GPT Image),
     * ramenée aux trois grandeurs du tarif public : tokens texte et image en
     * ENTRÉE, tokens image en SORTIE. Sans détail, l'entrée est du texte et la
     * sortie de l'image. Null si la réponse n'en porte pas (DALL-E). Pure.
     *
     * @param array<string, mixed> $data réponse décodée
     * @return array{text_input:int, image_input:int, image_output:int}|null
     */
    public static function usageFromResponse(array $data): ?array
    {
        $usage = $data['usage'] ?? null;
        if (!is_array($usage)) {
            return null;
        }
        $in  = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : null;
        $out = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : null;

        return [
            'text_input'   => (int)($in['text_tokens']  ?? ($in === null ? ($usage['input_tokens'] ?? 0) : 0)),
            'image_input'  => (int)($in['image_tokens'] ?? 0),
            'image_output' => (int)($out['image_tokens'] ?? ($usage['output_tokens'] ?? 0)),
        ];
    }

    /**
     * Édition d'une image existante via /v1/images/edits (multipart).
     * Réservé à la famille gpt-image-* : c'est ce qui rend possible « modifie
     * l'image précédente » avec le modèle OpenAI par défaut (gpt-image-2).
     * Le prompt décrit les transformations, les images source sont envoyées
     * en pièces jointes binaires : `image` pour une seule (la forme de
     * toujours), `image[]` répété pour plusieurs — la forme du guide
     * « Create a new image using image references » d'OpenAI.
     *
     * @param list<array{b64:string, mime:string}> $sources
     */
    private static function editViaImagesApi(
        string $apiKey,
        string $model,
        string $prompt,
        array $config,
        array $sources
    ): array
    {
        $files = [];
        foreach ($sources as $i => $src) {
            $binary = base64_decode((string)$src['b64'], true);
            if ($binary === false || $binary === '') {
                return ['error' => "Image source illisible : impossible de la retoucher."];
            }
            $files[] = [
                'binary' => $binary,
                'mime'   => $src['mime'],
                'name'   => 'source' . ($i > 0 ? '-' . ($i + 1) : '') . '.' . self::mimeToExtension($src['mime']),
            ];
        }
        if ($files === []) {
            return ['error' => "Image source illisible : impossible de la retoucher."];
        }
        $field = count($files) > 1 ? 'image[]' : 'image';

        // /v1/images/edits (gpt-image-*) n'accepte PAS les tailles DALL-E
        // (1792x1024…) ni les qualités standard/hd : on n'envoie que des
        // valeurs déclarées pour CE modèle au catalogue (les 2.5 y ajoutent
        // xhigh et max), sinon on laisse le défaut serveur (auto).
        $fields = ['model' => $model, 'prompt' => $prompt, 'n' => '1'];
        $size   = (string)($config['size'] ?? '');
        if (in_array($size, self::sizesFor($model), true)) {
            $fields['size'] = $size;
        }
        $quality = (string)($config['quality'] ?? '');
        if (in_array($quality, self::qualitiesFor($model), true)) {
            $fields['quality'] = $quality;
        }

        $boundary = 'mia' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
                . $value . "\r\n";
        }
        foreach ($files as $file) {
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$field}\"; filename=\"{$file['name']}\"\r\n"
                . "Content-Type: {$file['mime']}\r\n\r\n"
                . $file['binary'] . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Authorization: Bearer ' . $apiKey,
            ]),
            'content'       => $body,
            'timeout'       => 180,
            'ignore_errors' => true,
        ]]);

        $url = ToolCatalog::endpoint('image', 'openai', 'images_edits')
            ?? 'https://api.openai.com/v1/images/edits';
        $res = file_get_contents($url, false, $ctx);
        if ($res === false) {
            error_log('[ImageGenerator] images/edits — impossible de contacter l\'API. model=' . $model);
            return ['error' => 'Impossible de contacter l\'API OpenAI (timeout ou réseau).'];
        }

        $data = json_decode($res, true);
        if (isset($data['error'])) {
            $httpStatus = $http_response_header[0] ?? '?';
            error_log('[ImageGenerator] images/edits — erreur API. model=' . $model . ' | status=' . $httpStatus . ' | réponse=' . $res);
            return self::apiError($data['error'], 'OpenAI', LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null));
        }

        $item = $data['data'][0] ?? [];
        if (!empty($item['b64_json'])) {
            $filename = self::saveImage(base64_decode((string)$item['b64_json']), 'png');
        } elseif (!empty($item['url'])) {
            $dl = self::downloadImageFromUrl((string)$item['url']);
            if ($dl === null) {
                error_log('[ImageGenerator] images/edits — téléchargement de l\'image (url) échoué. model=' . $model);
                return ['error' => 'Image retouchée mais téléchargement impossible.'];
            }
            $filename = self::saveImage($dl['binary'], (string)($dl['ext'] ?? 'png'));
        } else {
            error_log('[ImageGenerator] images/edits — aucune image dans la réponse. model=' . $model . ' | réponse=' . $res);
            return ['error' => "Aucune image retouchée reçue depuis OpenAI."];
        }

        return [
            'ok'             => true,
            'image_url'      => '/assets/img/generated/' . $filename,
            'revised_prompt' => $item['revised_prompt'] ?? null,
            'model'          => $model,
            'size'           => (string)($data['size']    ?? $fields['size']    ?? ($config['size']    ?? '')),
            'quality'        => (string)($data['quality'] ?? $fields['quality'] ?? ($config['quality'] ?? '')),
            'usage'          => is_array($data) ? self::usageFromResponse($data) : null,
        ];
    }

    // -------------------------------------------------------------------------
    // OpenAI Responses API — GPT-5.x image generation
    // -------------------------------------------------------------------------

    /**
     * Génération d'image via Responses API (modèles ChatGPT natifs image).
     *
     * @param list<array{b64:string, mime:string}> $sources
     */
    private static function generateViaResponsesApi(
        string $apiKey,
        string $model,
        string $prompt,
        array $config,
        array $sources = [],
        ?string $instructions = null
    ): array
    {
        $tool = ['type' => 'image_generation'];
        $allowedSizes     = ToolCatalog::options('image', 'openai', 'responses_sizes')
            ?: ['auto', '1024x1024', '1024x1536', '1536x1024'];
        $allowedQualities = ToolCatalog::options('image', 'openai', 'responses_qualities')
            ?: ['auto', 'low', 'medium', 'high'];

        if (!empty($config['size']) && is_string($config['size']) && in_array($config['size'], $allowedSizes, true)) {
            $tool['size'] = $config['size'];
        }
        if (!empty($config['quality']) && is_string($config['quality']) && in_array($config['quality'], $allowedQualities, true)) {
            $tool['quality'] = $config['quality'];
        }

        // Images source en TÊTE du content : meilleure attention du modèle
        // sur les images (le texte qui suit décrit alors les modifications).
        $content = [];
        foreach ($sources as $src) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => 'data:' . $src['mime'] . ';base64,' . $src['b64'],
            ];
        }
        // Prompt concis : on fait confiance à l'assistant LLM appelant pour
        // avoir déjà intégré persona/style/intention dans `prompt`. On préfixe
        // simplement par une formulation explicite d'édition si une image
        // source est jointe (sinon génération from scratch) ; plusieurs
        // images = des références, que le prompt désigne par leur rang.
        $messageText = match (true) {
            count($sources) > 1 => "Appuie-toi sur les images fournies ci-dessus, dans l'ordre où elles apparaissent, "
                . "et reprends fidèlement ce qu'elles montrent en appliquant la consigne suivante. " . $prompt,
            count($sources) === 1 => "Modifie l'image fournie ci-dessus en appliquant les changements demandés (préserve le sujet, applique uniquement les transformations décrites). " . $prompt,
            default => $prompt,
        };
        $content[] = ['type' => 'input_text', 'text' => $messageText];

        $payload = [
            'model'       => $model,
            'input'       => [[
                'role'    => 'user',
                'content' => $content,
            ]],
            'tools'       => [$tool],
            // Force l'invocation de l'outil image_generation. Sans cela, les
            // modèles récents (GPT-5.x via Responses API) émettent souvent un
            // texte explicatif au lieu d'appeler l'outil, ce qui aboutit à
            // "Aucune image reçue depuis la Responses API".
            'tool_choice' => 'required',
        ];
        // Persona/style/contraintes : passées comme `instructions` (le rôle
        // "system" de Responses API). C'est la seule façon propre de faire
        // respecter la persona par l'orchestrateur image SANS polluer le
        // prompt visuel envoyé à gpt-image-1 sous le capot.
        if ($instructions !== null && $instructions !== '') {
            $payload['instructions'] = $instructions;
        }
        $payload = json_encode($payload);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]),
            'content'       => $payload,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $url = ToolCatalog::endpoint('image', 'openai', 'responses')
            ?? 'https://api.openai.com/v1/responses';
        $res = file_get_contents($url, false, $ctx);
        if ($res === false) {
            error_log('[ImageGenerator] Responses API — impossible de contacter l\'API. model=' . $model);
            return ['error' => 'Impossible de contacter OpenAI Responses API (timeout ou réseau).'];
        }

        $data = json_decode($res, true);
        if (isset($data['error'])) {
            $httpStatus = $http_response_header[0] ?? '?';
            error_log('[ImageGenerator] Responses API — erreur. model=' . $model . ' | status=' . $httpStatus . ' | réponse=' . $res);
            return self::apiError($data['error'], 'OpenAI', LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null));
        }

        // Capture l'usage tokens éventuel (gpt-image-1 retourne usage.input_tokens / output_tokens)
        $imageUsage = is_array($data['usage'] ?? null) ? [
            'input_tokens'  => (int)($data['usage']['input_tokens']  ?? 0),
            'output_tokens' => (int)($data['usage']['output_tokens'] ?? 0),
        ] : null;
        $sizeUsed    = (string)($tool['size']    ?? '');
        $qualityUsed = (string)($tool['quality'] ?? '');

        $image = self::extractResponsesImage($data);
        if ($image !== null) {
            if (isset($image['b64'])) {
                $binary = base64_decode((string)$image['b64'], true);
                if ($binary !== false && $binary !== '') {
                    $filename = self::saveImage($binary, (string)($image['ext'] ?? 'png'));
                    return [
                        'ok' => true,
                        'image_url' => '/assets/img/generated/' . $filename,
                        'model'   => $model,
                        'size'    => $sizeUsed,
                        'quality' => $qualityUsed,
                        'usage'   => $imageUsage,
                    ];
                }
            }
            if (isset($image['url']) && is_string($image['url'])) {
                $downloaded = self::downloadImageFromUrl($image['url']);
                if ($downloaded !== null) {
                    $filename = self::saveImage($downloaded['binary'], $downloaded['ext']);
                    return [
                        'ok' => true,
                        'image_url' => '/assets/img/generated/' . $filename,
                        'model'   => $model,
                        'size'    => $sizeUsed,
                        'quality' => $qualityUsed,
                        'usage'   => $imageUsage,
                    ];
                }
            }
        }

        // Pas d'image : on relève tout statut "incomplete" ou texte que le modèle
        // a produit pour expliquer ce qu'il a fait/refusé. Sans ça, le user voit
        // un message générique "Aucune image reçue" sans aucune piste.
        $diagnostic = self::extractResponsesDiagnostic($data);
        error_log('[ImageGenerator] Responses API — aucune image reçue. model=' . $model . ' | diagnostic=' . $diagnostic . ' | réponse=' . $res);
        $msg = "Aucune image reçue depuis la Responses API (outil image_generation).";
        if ($diagnostic !== '') {
            $msg .= ' ' . $diagnostic;
        }
        return ['error' => $msg];
    }

    /**
     * Tire un diagnostic exploitable de la réponse Responses API quand
     * aucune image n'a pu être extraite : statut `incomplete`, refus, texte
     * libre du modèle (souvent une explication de pourquoi il n'a pas
     * généré l'image). On limite la longueur pour éviter d'inonder l'UI.
     */
    private static function extractResponsesDiagnostic(array $data): string
    {
        $parts = [];

        $status = (string)($data['status'] ?? '');
        if ($status !== '' && $status !== 'completed') {
            $parts[] = "Statut OpenAI : {$status}.";
        }
        $reason = (string)($data['incomplete_details']['reason'] ?? '');
        if ($reason !== '') {
            $parts[] = "Raison : {$reason}.";
        }

        $modelText = '';
        foreach ($data['output'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }
                $type = (string)($contentItem['type'] ?? '');
                if ($type === 'output_text' || $type === 'text') {
                    $text = (string)($contentItem['text'] ?? '');
                    if ($text !== '') {
                        $modelText .= ($modelText !== '' ? ' ' : '') . $text;
                    }
                } elseif ($type === 'refusal') {
                    $refusal = (string)($contentItem['refusal'] ?? '');
                    if ($refusal !== '') {
                        $parts[] = "Refus du modèle : {$refusal}";
                    }
                }
            }
        }
        if ($modelText !== '') {
            $modelText = trim(preg_replace('/\s+/u', ' ', $modelText) ?? $modelText);
            if (mb_strlen($modelText) > 240) {
                $modelText = mb_substr($modelText, 0, 240) . '…';
            }
            $parts[] = "Réponse texte du modèle : « {$modelText} ».";
        }

        return implode(' ', $parts);
    }

    private static function extractResponsesImage(array $data): ?array
    {
        foreach ($data['output'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $candidate = self::extractImageCandidate($item);
            if ($candidate !== null) {
                return $candidate;
            }

            foreach ($item['content'] ?? [] as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }
                $candidate = self::extractImageCandidate($contentItem);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function extractImageCandidate(array $item): ?array
    {
        if (($item['type'] ?? '') === 'image_generation_call') {
            $result = $item['result'] ?? null;
            $candidate = self::extractImageFromMixed($result);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return self::extractImageFromMixed($item);
    }

    private static function extractImageFromMixed(mixed $value, int $depth = 0): ?array
    {
        if ($depth > 8 || $value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            $dataUrl = self::parseDataUrlImage($trimmed);
            if ($dataUrl !== null) {
                return $dataUrl;
            }

            if (self::looksLikeBase64Image($trimmed)) {
                return ['b64' => preg_replace('/\s+/', '', $trimmed), 'ext' => 'png'];
            }

            if ($trimmed[0] === '{' || $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return self::extractImageFromMixed($decoded, $depth + 1);
                }
            }

            if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
                return ['url' => $trimmed, 'ext' => 'png'];
            }

            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        $b64 = (string)($value['b64_json'] ?? $value['image_base64'] ?? $value['base64'] ?? '');
        if ($b64 !== '') {
            $mime = (string)($value['mime_type'] ?? $value['mimeType'] ?? 'image/png');
            return ['b64' => preg_replace('/\s+/', '', $b64), 'ext' => self::mimeToExtension($mime)];
        }

        foreach (['data', 'image_url', 'url'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $parsed = self::extractImageFromMixed($value[$key], $depth + 1);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        foreach ($value as $child) {
            $parsed = self::extractImageFromMixed($child, $depth + 1);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private static function parseDataUrlImage(string $value): ?array
    {
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,([a-z0-9+/_=\-\s]+)$#i', $value, $m) !== 1) {
            return null;
        }

        $mime = (string)$m[1];
        $b64 = preg_replace('/\s+/', '', (string)$m[2]);
        if ($b64 === '') {
            return null;
        }

        return ['b64' => $b64, 'ext' => self::mimeToExtension($mime)];
    }

    private static function looksLikeBase64Image(string $value): bool
    {
        $normalized = preg_replace('/\s+/', '', $value);
        if ($normalized === '' || strlen($normalized) < 128) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9+\/=_-]+$/', $normalized) === 1;
    }

    private static function downloadImageFromUrl(string $url): ?array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => 60,
            'ignore_errors' => true,
        ]]);

        $binary = @file_get_contents($url, false, $ctx);
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        $mime = 'image/png';
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $candidate = trim((string)substr($header, strlen('Content-Type:')));
                if ($candidate !== '') {
                    $mime = strtolower(trim(explode(';', $candidate, 2)[0]));
                }
                break;
            }
        }

        if (!str_starts_with($mime, 'image/')) {
            $detected = '';
            if (function_exists('finfo_open') && function_exists('finfo_buffer')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detected = (string)finfo_buffer($finfo, $binary);
                }
            }
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                $mime = $detected;
            } else {
                return null;
            }
        }

        return ['binary' => $binary, 'ext' => self::mimeToExtension($mime)];
    }

    private static function mimeToExtension(string $mime): string
    {
        $parts = explode('/', strtolower(trim($mime)));
        $ext = $parts[1] ?? 'png';
        if ($ext === 'jpeg') {
            return 'jpg';
        }
        return preg_replace('/[^a-z0-9]/', '', $ext) ?: 'png';
    }

    private static function usesResponsesImageTool(string $model): bool
    {
        // Source de vérité : config/tools_catalog.php (routing par modèle).
        $routing = ToolCatalog::routing('image', 'openai', $model);
        if ($routing === 'responses_api') {
            return true;
        }
        if ($routing === 'images_generations') {
            return false;
        }
        // Modèle non listé : heuristique conservative (GPT-5.x / o* → Responses API,
        // DALL-E / gpt-image-* → images/generations).
        if (str_starts_with($model, 'dall-e-') || str_starts_with($model, 'gpt-image-')) {
            return false;
        }
        return str_starts_with($model, 'gpt-') || str_starts_with($model, 'o');
    }

    // -------------------------------------------------------------------------
    // Google Gemini — Nano Banana (generateContent) ou Imagen (predict)
    // -------------------------------------------------------------------------

    /** @param list<array{b64:string, mime:string}> $sources */
    private static function generateGemini(string $apiKey, string $model, string $prompt, array $config, array $sources = [], ?string $instructions = null): array
    {
        // Gemini n'a pas d'équivalent direct au champ instructions ; on
        // préfixe le prompt visuel avec la persona/style si fournie.
        if ($instructions !== null && $instructions !== '') {
            $prompt = $instructions . "\n\n" . $prompt;
        }
        // Routing depuis le catalogue, avec heuristique de secours sur le nom.
        $routing = ToolCatalog::routing('image', 'gemini', $model);
        if ($routing === 'predict' || ($routing === null && str_contains($model, 'imagen'))) {
            // Imagen :predict ne supporte pas l'image-to-image, on log et on
            // fait quand même un text-to-image (mieux que de tout planter).
            if ($sources !== []) {
                error_log('[ImageGenerator] Gemini Imagen — image source ignorée (predict ne supporte pas image-to-image). model=' . $model);
            }
            return self::generateImagen($apiKey, $model, $prompt, $config);
        }
        return self::generateGeminiContent($apiKey, $model, $prompt, $sources);
    }

    /**
     * Gemini generateContent avec modalité IMAGE (modeles Nano Banana).
     * Les images source suivent le texte, dans l'ordre reçu — jusqu'à 14
     * références selon le guide Gemini, MIA en envoie au plus cinq.
     *
     * @param list<array{b64:string, mime:string}> $sources
     */
    private static function generateGeminiContent(string $apiKey, string $model, string $prompt, array $sources = []): array
    {
        $parts = [['text' => $prompt]];
        foreach ($sources as $src) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $src['mime'],
                    'data'     => $src['b64'],
                ],
            ];
        }

        $payload = json_encode([
            'contents'         => [['parts' => $parts]],
            'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
        ]);

        // L'API native Gemini requiert ?key= dans l'URL, pas un header Authorization
        $base = ToolCatalog::endpoint('image', 'gemini', 'generate_content', $model)
            ?? "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent";
        $url  = $base . '?key=' . urlencode($apiKey);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/json',
            'content'       => $payload,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $res = file_get_contents($url, false, $ctx);
        if ($res === false) {
            error_log('[ImageGenerator] Gemini generateContent — impossible de contacter l\'API. model=' . $model);
            return ['error' => 'Impossible de contacter l\'API Gemini (timeout ou réseau).'];
        }

        $data = json_decode($res, true);
        if (isset($data['error'])) {
            $httpStatus = $http_response_header[0] ?? '?';
            error_log('[ImageGenerator] Gemini generateContent — erreur API. model=' . $model . ' | status=' . $httpStatus . ' | réponse=' . $res);
            return self::apiError($data['error'], 'Gemini', LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null));
        }

        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                $mime     = $part['inlineData']['mimeType'] ?? 'image/png';
                $ext      = explode('/', $mime)[1] ?? 'png';
                $filename = self::saveImage(base64_decode($part['inlineData']['data']), $ext);
                return [
                    'ok' => true,
                    'image_url' => '/assets/img/generated/' . $filename,
                    'model' => $model,
                ];
            }
        }

        error_log('[ImageGenerator] Gemini generateContent — aucune image dans la réponse. model=' . $model . ' | réponse=' . $res);
        return ['error' => 'Aucune image reçue depuis Gemini (Nano Banana).'];
    }

    /** Imagen via endpoint :predict */
    private static function generateImagen(string $apiKey, string $model, string $prompt, array $config): array
    {
        $parameters = ['sampleCount' => 1];
        if (!empty($config['aspect_ratio'])) {
            $parameters['aspectRatio'] = $config['aspect_ratio'];
        }

        $payload = json_encode([
            'instances'  => [['prompt' => $prompt]],
            'parameters' => $parameters,
        ]);

        // L'API native Gemini requiert ?key= dans l'URL, pas un header Authorization
        $base = ToolCatalog::endpoint('image', 'gemini', 'predict', $model)
            ?? "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":predict";
        $url  = $base . '?key=' . urlencode($apiKey);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/json',
            'content'       => $payload,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $res = file_get_contents($url, false, $ctx);
        if ($res === false) {
            error_log('[ImageGenerator] Imagen predict — impossible de contacter l\'API. model=' . $model);
            return ['error' => 'Impossible de contacter l\'API Imagen (timeout ou réseau).'];
        }

        $data = json_decode($res, true);
        if (isset($data['error'])) {
            $httpStatus = $http_response_header[0] ?? '?';
            error_log('[ImageGenerator] Imagen predict — erreur API. model=' . $model . ' | status=' . $httpStatus . ' | réponse=' . $res);
            return self::apiError($data['error'], 'Imagen', LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null));
        }

        $b64  = $data['predictions'][0]['bytesBase64Encoded'] ?? null;
        $mime = $data['predictions'][0]['mimeType']           ?? 'image/png';
        if (!$b64) {
            error_log('[ImageGenerator] Imagen predict — aucune image dans la réponse. model=' . $model . ' | réponse=' . $res);
            return ['error' => 'Aucune image reçue depuis Imagen.'];
        }

        $ext      = explode('/', $mime)[1] ?? 'png';
        $filename = self::saveImage(base64_decode($b64), $ext);
        return [
            'ok' => true,
            'image_url' => '/assets/img/generated/' . $filename,
            'model' => $model,
            'aspect_ratio' => (string)($config['aspect_ratio'] ?? ''),
        ];
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    /**
     * Causes que le présentateur d'erreurs LLM sait nommer et expliquer : même
     * compte, même clé, mêmes incidents que pour un modèle de conversation —
     * donc les mêmes mots. Le reste (paramètre refusé, erreur inconnue) garde
     * le message du fournisseur : il nomme le réglage en cause.
     */
    private const NAMED_ERROR_KINDS = [
        'quota', 'rate_limit', 'overloaded', 'server', 'timeout',
        'auth', 'permission', 'model', 'filtered',
    ];

    /**
     * Traduit un objet d'erreur API en message lisible pour l'utilisateur.
     *
     * La cause est lue par LlmErrorPresenter::classify (source unique) : avant,
     * tout 429 devenait « quota dépassé (tier gratuit) » — y compris un compte
     * OpenAI sans crédit — et le reste partait en anglais, tel que le
     * fournisseur l'écrit (« Billing hard limit has been reached », « Your
     * request was rejected as a result of our safety system »).
     *
     * `$httpStatus` : statut de la réponse ; à défaut, le `code` numérique que
     * Google recopie dans l'erreur.
     */
    private static function formatApiError(array $errorData, string $provider = '', ?int $httpStatus = null): string
    {
        return self::describeApiError($errorData, $provider, $httpStatus)[0];
    }

    /**
     * Résultat d'échec d'un appel : `error` (message lisible) et, quand la
     * cause est nommée, `error_payload` — la charge utile complète du
     * présentateur (titre, genre). C'est elle que le job image affiche : sans
     * elle, « Crédit épuisé chez OpenAI » s'intitulait « Image non générée »,
     * en rouge, sans proposer de prévenir un administrateur.
     *
     * @return array{error:string, error_payload?:array}
     */
    private static function apiError(array $errorData, string $provider = '', ?int $httpStatus = null): array
    {
        [$message, $payload] = self::describeApiError($errorData, $provider, $httpStatus);
        return $payload !== null ? ['error' => $message, 'error_payload' => $payload] : ['error' => $message];
    }

    /** @return array{0:string, 1:?array} message lisible, charge utile du présentateur si la cause est nommée */
    private static function describeApiError(array $errorData, string $provider, ?int $httpStatus): array
    {
        $code = $errorData['code'] ?? null;
        if ($httpStatus === null && is_int($code) && $code >= 400 && $code < 600) {
            $httpStatus = $code;
        }

        $detail = LlmAdapterHelpers::errorDetail(['error' => $errorData], '', $httpStatus);
        $label  = $provider !== '' ? $provider : 'API';
        // Même chemin que les adaptateurs : le chunk d'erreur, relu par le
        // présentateur (cause + délai annoncé par le fournisseur).
        $desc = LlmErrorPresenter::parse(
            $httpStatus !== null
                ? LlmAdapterHelpers::buildHttpErrorMessage($label, $httpStatus, $detail)
                : '[Erreur ' . $label . ' : ' . $detail . ']'
        );
        if ($desc !== null && in_array($desc['kind'], self::NAMED_ERROR_KINDS, true)) {
            if ($provider === '') {
                $desc['provider'] = null;
            }
            $payload = LlmErrorPresenter::present($desc);
            return [(string)$payload['message'], $payload];
        }

        // Message trop long → tronquer à 180 caractères
        $message = $errorData['message'] ?? 'Erreur inconnue';
        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE) ?: 'Erreur inconnue';
        }
        $clean = preg_replace('/\s+/', ' ', (string)$message);
        if (mb_strlen($clean) > 180) {
            $clean = mb_substr($clean, 0, 180) . '…';
        }
        return [$clean, null];
    }

    private static function saveImage(string $binaryData, string $ext): string
    {
        $dir = ROOT . '/public/assets/img/generated/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . ltrim($ext, '.');
        file_put_contents($dir . $filename, $binaryData);
        return $filename;
    }
}
