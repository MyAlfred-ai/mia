<?php

declare(strict_types=1);

/**
 * Outil Gamma — génération de présentations via l'API gamma.app, confiée à un
 * assistant comme un outil function-calling.
 *
 * MÊME FAMILLE QUE n8n : une ligne de `chat_tools` (type `gamma`, clé API dans
 * `api_key_enc`, options dans `config_json`), liée aux assistants autorisés via
 * `profile_tools` et soumise au périmètre de groupe. L'utilisateur n'a rien à
 * cocher : demander une présentation à l'assistant suffit.
 *
 * CHAQUE GÉNÉRATION EST PAYANTE (crédits du compte Gamma, plan Pro+ requis) :
 * l'exécution passe donc TOUJOURS par la file d'approbation humaine — l'outil
 * ne fait que déposer une carte pendant le stream, le `POST /generations` part
 * au clic « Approuver », et le résultat (1 à 3 minutes) est suivi par un job
 * asynchrone qui persiste le lien final dans la conversation.
 *
 * FORMAT UNIQUE : présentation. L'API sait aussi produire documents, pages web
 * et posts sociaux, volontairement hors périmètre.
 *
 * Le bouton « Tester » de l'administration passe par `GET /themes` — un
 * endpoint de lecture qui valide la clé SANS consommer un seul crédit. Aucun
 * test ne déclenche jamais de génération.
 */
final class GammaService
{
    /** Type de la ligne `chat_tools` qui porte une configuration Gamma. */
    public const TOOL_TYPE = 'gamma';

    /** Fournisseur/modèle stockés sur la ligne (colonnes NOT NULL, aucun LLM de MIA ici). */
    public const TOOL_PROVIDER = 'gamma';
    public const TOOL_MODEL    = 'presentation';

    /** Racine de l'API publique gamma.app (auth par en-tête X-API-KEY). */
    public const API_BASE = 'https://public-api.gamma.app';

    /** Bornes dures de l'API Gamma. */
    public const MAX_INPUT_CHARS        = 400000;
    public const MAX_INSTRUCTIONS_CHARS = 5000;
    public const MAX_TITLE_CHARS        = 500;
    public const MIN_CARDS              = 1;
    public const MAX_CARDS              = 75;

    /** Plafond de cartes par défaut (réglable par l'admin) et défaut d'une demande muette. */
    public const DEFAULT_MAX_CARDS = 20;
    public const DEFAULT_CARDS     = 10;

    /** Suivi post-approbation : un état toutes les 5 s, génération 1–3 min + marge. */
    public const POLL_INTERVAL_SEC = 5;
    public const POLL_TIMEOUT_SEC  = 480;

    /**
     * Présentation du chat confiée à Gamma (« Version soignée › Par Gamma » d'une
     * carte PowerPoint) : séparateur de cartes de `cardSplit: inputTextBreaks`
     * (doc Gamma : « splits on the separator \n---\n », numCards ignoré) et
     * format des cartes — 16:9, celui du fichier PowerPoint d'où l'on part
     * (le défaut de Gamma, `fluid`, s'exporte mal en .pptx).
     */
    public const CARD_BREAK      = "\n---\n";
    public const DECK_DIMENSIONS = '16x9';

    /** Langues de sortie Gamma (`textOptions.language`, défaut de l'API : `en`) par langue de l'interface. */
    private const LANGUAGES = ['fr' => 'fr', 'en' => 'en'];

    /** Valeurs admises par l'API — tout le reste est ramené au défaut, jamais transmis tel quel. */
    public const TEXT_MODES    = ['generate', 'condense', 'preserve'];
    public const EXPORT_KINDS  = ['', 'pdf', 'pptx'];
    public const IMAGE_SOURCES = ['aiGenerated', 'pexels', 'pictographic', 'noImages'];

    private const API_TIMEOUT = 30;

    private function __construct(private readonly string $apiKey)
    {
    }

    // ===================== Configuration (portée par l'outil) =====================

    /**
     * Instance prête à l'emploi depuis une ligne `chat_tools`, ou null si
     * l'outil est incomplet (clé API absente) — le chat n'expose alors rien
     * plutôt que d'échouer à la première demande.
     *
     * @param array<string,mixed>|null $tool ligne chat_tools
     */
    public static function fromTool(?array $tool): ?self
    {
        if (!$tool || (string)($tool['type'] ?? '') !== self::TOOL_TYPE) {
            return null;
        }
        // La clé voyage dans l'en-tête X-API-KEY : nettoyée des CR/LF comme
        // toute valeur d'en-tête, une clé mal collée ne peut pas en injecter.
        $enc    = (string)($tool['api_key_enc'] ?? '');
        $apiKey = $enc === '' ? '' : self::sanitizeHeaderValue(trim(Encryption::decrypt($enc)));
        return $apiKey === '' ? null : new self($apiKey);
    }

    /** `config_json` d'un outil gamma → tableau (jamais null). */
    public static function decodeToolConfig(?array $tool): array
    {
        $raw = json_decode((string)($tool['config_json'] ?? ''), true);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Formulaire d'administration → `config_json` de l'outil.
     * Contrairement à n8n, AUCUN secret ne vit ici : la clé API est portée par
     * la colonne `api_key_enc` (mécanique ChatTool::save), d'où l'absence de
     * paramètre `$ancien`.
     *
     * @param array<string,mixed> $post champs du formulaire
     */
    public static function configFromPost(array $post): array
    {
        return [
            'theme_id'             => mb_substr(trim((string)($post['config_gamma_theme_id'] ?? '')), 0, 100),
            'export_as'            => self::normalizeExportAs((string)($post['config_gamma_export_as'] ?? '')),
            'image_source'         => self::normalizeImageSource((string)($post['config_gamma_image_source'] ?? '')),
            'max_cards'            => self::sanitizeMaxCards($post['config_gamma_max_cards'] ?? null),
            'default_instructions' => mb_substr(trim((string)($post['config_gamma_default_instructions'] ?? '')), 0, self::MAX_INSTRUCTIONS_CHARS),
        ];
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /** Valeur d'en-tête → sans CR/LF/NUL (anti-injection d'en-têtes). */
    public static function sanitizeHeaderValue(string $raw): string
    {
        return trim((string)preg_replace('/[\r\n\x00]+/', '', $raw));
    }

    /** Plafond de cartes saisi par l'admin → borné aux limites API, défaut 20. */
    public static function sanitizeMaxCards(mixed $raw): int
    {
        $n = (int)($raw ?? 0);
        if ($n <= 0) {
            return self::DEFAULT_MAX_CARDS;
        }
        return max(self::MIN_CARDS, min(self::MAX_CARDS, $n));
    }

    /** Nombre de cartes demandé par le modèle → borné 1..plafond admin, défaut 10. */
    public static function normalizeCardCount(mixed $raw, int $plafond): int
    {
        $plafond = max(self::MIN_CARDS, min(self::MAX_CARDS, $plafond));
        $n       = (int)($raw ?? 0);
        if ($n <= 0) {
            $n = self::DEFAULT_CARDS;
        }
        return max(self::MIN_CARDS, min($plafond, $n));
    }

    /** Mode de texte demandé → whitelist API, défaut `generate` (développer le sujet). */
    public static function normalizeTextMode(string $raw): string
    {
        $mode = strtolower(trim($raw));
        return in_array($mode, self::TEXT_MODES, true) ? $mode : 'generate';
    }

    /** Export configuré → whitelist, '' = pas d'export automatique. */
    public static function normalizeExportAs(string $raw): string
    {
        $kind = strtolower(trim($raw));
        return in_array($kind, self::EXPORT_KINDS, true) ? $kind : '';
    }

    /** Source d'images configurée → whitelist, défaut `aiGenerated`. */
    public static function normalizeImageSource(string $raw): string
    {
        $source = trim($raw);
        return in_array($source, self::IMAGE_SOURCES, true) ? $source : 'aiGenerated';
    }

    /**
     * Config admin + arguments du LLM → body du `POST /generations`.
     * Tout est borné ici : quoi que demande le modèle, rien ne dépasse les
     * limites de l'API ni le plafond posé par l'administrateur. Aucune clé
     * vide n'est émise (Gamma répond 400 sur un champ vide).
     *
     * @param array<string,mixed> $config   config_json décodé
     * @param array<string,mixed> $args     arguments de l'appel d'outil
     * @param string              $language langue de sortie Gamma (cf. language()), '' = celle de l'API
     * @throws \RuntimeException message FR (renvoyé tel quel au modèle)
     */
    public static function buildGenerationPayload(array $config, array $args, string $language = ''): array
    {
        $sujet = trim((string)($args['sujet'] ?? ''));
        if ($sujet === '') {
            throw new \RuntimeException(
                'Le paramètre « sujet » est obligatoire : donne le sujet ou le contenu source de la présentation.'
            );
        }

        $payload = [
            'inputText' => mb_substr($sujet, 0, self::MAX_INPUT_CHARS),
            'textMode'  => self::normalizeTextMode((string)($args['mode_texte'] ?? '')),
            'format'    => 'presentation',
            'numCards'  => self::normalizeCardCount(
                $args['nombre_cartes'] ?? null,
                self::sanitizeMaxCards($config['max_cards'] ?? null)
            ),
        ];

        $titre = trim((string)($args['titre'] ?? ''));
        if ($titre !== '') {
            $payload['title'] = mb_substr($titre, 0, self::MAX_TITLE_CHARS);
        }

        // Les consignes de l'admin (ton, marque…) passent DEVANT celles du
        // modèle : elles cadrent toutes les générations de l'outil.
        $instructions = trim(
            trim((string)($config['default_instructions'] ?? '')) . "\n" . trim((string)($args['instructions'] ?? ''))
        );
        if ($instructions !== '') {
            $payload['additionalInstructions'] = mb_substr($instructions, 0, self::MAX_INSTRUCTIONS_CHARS);
        }

        $themeId = trim((string)($config['theme_id'] ?? ''));
        if ($themeId !== '') {
            $payload['themeId'] = $themeId;
        }
        $exportAs = self::normalizeExportAs((string)($config['export_as'] ?? ''));
        if ($exportAs !== '') {
            $payload['exportAs'] = $exportAs;
        }
        $payload['imageOptions'] = ['source' => self::normalizeImageSource((string)($config['image_source'] ?? ''))];
        if ($language !== '') {
            // Sans elle, Gamma écrit en anglais (défaut documenté de l'API),
            // quelle que soit la langue du sujet.
            $payload['textOptions'] = ['language' => $language];
        }

        return $payload;
    }

    /**
     * Langue de sortie Gamma pour une langue de l'interface ('' si l'API n'en
     * a pas d'équivalent : son défaut s'applique alors).
     */
    public static function language(string $locale): string
    {
        return self::LANGUAGES[strtolower(substr(trim($locale), 0, 2))] ?? '';
    }

    /**
     * Présentation du chat (cartes de MarkdownDeck::sourceCards) → body du
     * `POST /generations`, pour « Version soignée › Par Gamma » d'une carte
     * PowerPoint.
     *
     * Le texte est GARDÉ tel qu'écrit (`textMode: preserve`) et découpé aux
     * slides de la présentation (`cardSplit: inputTextBreaks`) : Gamma met en
     * page, il ne réécrit pas. Le plafond de cartes de l'administrateur vaut
     * ici comme pour l'outil — une présentation plus longue est REFUSÉE,
     * jamais amputée en silence. Sans export choisi par l'administrateur, un
     * .pptx est demandé : on part d'un fichier PowerPoint, c'en est un qu'on
     * attend en retour.
     *
     * @param array<string,mixed> $config config_json décodé de l'outil
     * @param list<string>        $cards  une carte par slide, Markdown d'origine
     * @throws \RuntimeException message traduit, affiché dans la carte du fichier
     */
    public static function deckPayload(array $config, array $cards, string $title, string $language = ''): array
    {
        // Une ligne « --- » restée DANS une carte (bloc de code) couperait la
        // carte en deux chez Gamma : elle devient un filet d'une autre écriture.
        $cards = array_values(array_filter(
            array_map(
                static fn ($c): string => trim((string)preg_replace('/^[ \t]*---[ \t]*$/m', '- - -', (string)$c)),
                $cards
            ),
            static fn (string $c): bool => $c !== ''
        ));
        if ($cards === []) {
            throw new \RuntimeException(t('Cette présentation est vide : rien à envoyer à Gamma.'));
        }
        $plafond = self::sanitizeMaxCards($config['max_cards'] ?? null);
        if (count($cards) > $plafond) {
            throw new \RuntimeException(t(
                'Cette présentation compte {n} slides : l’administrateur a fixé à {max} cartes le maximum d’une présentation Gamma. Raccourcissez-la, ou demandez-lui de relever ce plafond.',
                ['n' => (string)count($cards), 'max' => (string)$plafond]
            ));
        }
        $input = implode(self::CARD_BREAK, $cards);
        if (mb_strlen($input) > self::MAX_INPUT_CHARS) {
            throw new \RuntimeException(t('Cette présentation est trop longue pour Gamma : raccourcissez-la.'));
        }

        $payload = [
            'inputText' => $input,
            'textMode'  => 'preserve',
            'format'    => 'presentation',
            'cardSplit' => 'inputTextBreaks',
        ];
        $title = trim($title);
        if ($title !== '') {
            $payload['title'] = mb_substr($title, 0, self::MAX_TITLE_CHARS);
        }
        $instructions = trim((string)($config['default_instructions'] ?? ''));
        if ($instructions !== '') {
            $payload['additionalInstructions'] = mb_substr($instructions, 0, self::MAX_INSTRUCTIONS_CHARS);
        }
        $themeId = trim((string)($config['theme_id'] ?? ''));
        if ($themeId !== '') {
            $payload['themeId'] = $themeId;
        }
        $payload['exportAs']     = self::normalizeExportAs((string)($config['export_as'] ?? '')) ?: 'pptx';
        $payload['imageOptions'] = ['source' => self::normalizeImageSource((string)($config['image_source'] ?? ''))];
        $payload['cardOptions']  = ['dimensions' => self::DECK_DIMENSIONS];
        if ($language !== '') {
            $payload['textOptions'] = ['language' => $language];
        }

        return $payload;
    }

    /**
     * Outil Gamma qui sert « Version soignée › Par Gamma » : celui de
     * l'assistant de la conversation s'il en a un (son thème, ses consignes),
     * sinon le premier que les groupes de la personne lui ouvrent. Un outil
     * sans clé, ou dont le dernier test a échoué, n'est jamais retenu — même
     * règle que l'exposition au modèle (resolveProfileGammaTool).
     *
     * Pure : les deux listes viennent de ChatTool::activeForProfile et
     * ChatTool::activeByUser (la première est incluse dans la seconde).
     *
     * @param list<array<string,mixed>> $assistantTools
     * @param list<array<string,mixed>> $userTools
     */
    public static function pickTool(array $assistantTools, array $userTools): ?array
    {
        foreach ([$assistantTools, $userTools] as $tools) {
            foreach ($tools as $tool) {
                if ((string)($tool['type'] ?? '') !== self::TOOL_TYPE
                    || (isset($tool['is_active']) && (int)$tool['is_active'] !== 1)
                    || (isset($tool['last_test_ok']) && (int)$tool['last_test_ok'] === 0)
                    || trim((string)($tool['api_key_enc'] ?? '')) === '') {
                    continue;
                }
                return $tool;
            }
        }
        return null;
    }

    /** Message ⏳ persisté au lancement d'une génération (carte approuvée ou bouton d'un fichier). */
    public static function launchedMessage(string $titre): string
    {
        return '⏳ ' . t("Génération de la présentation Gamma « {titre} » lancée (1 à 3 minutes). Le lien sera ajouté ici dès qu'elle sera prête.", ['titre' => $titre]);
    }

    /**
     * Réponse du `POST /generations` → generationId.
     * @throws \RuntimeException message FR par famille d'erreur
     */
    public static function parseCreateResponse(int $status, string $body): string
    {
        if ($status === 0) {
            throw new \RuntimeException('gamma.app injoignable — réessayez dans quelques instants.');
        }
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException(
                'Clé API refusée par gamma.app (HTTP ' . $status . ') — vérifiez la clé et que le plan du compte '
                . 'Gamma inclut l\'accès API (Pro ou supérieur).'
            );
        }
        if ($status === 402) {
            throw new \RuntimeException(
                'Crédits Gamma épuisés : la génération a été refusée. Rechargez les crédits du compte gamma.app.'
            );
        }
        if ($status >= 400) {
            $detail = self::errorDetail($body);
            throw new \RuntimeException(
                'gamma.app a refusé la demande (HTTP ' . $status . ')' . ($detail !== '' ? ' : ' . $detail : '') . '.'
            );
        }
        $data = json_decode($body, true);
        $id   = is_array($data) ? trim((string)($data['generationId'] ?? '')) : '';
        if ($id === '') {
            throw new \RuntimeException('Réponse inattendue de gamma.app : aucun identifiant de génération.');
        }
        return $id;
    }

    /**
     * Réponse du `GET /generations/{id}` → état exploitable par le job de suivi.
     *
     * @return array{status:string,gammaUrl:string,exportUrl:string,credits_deducted:?int,credits_remaining:?int,error:string}
     * @throws \RuntimeException erreurs HTTP (message FR)
     */
    public static function parseStatusResponse(int $status, string $body): array
    {
        if ($status === 0) {
            throw new \RuntimeException('gamma.app injoignable pendant le suivi de la génération.');
        }
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('Clé API refusée par gamma.app pendant le suivi (HTTP ' . $status . ').');
        }
        if ($status === 404) {
            throw new \RuntimeException('Génération introuvable côté gamma.app (identifiant inconnu ou expiré).');
        }
        if ($status >= 400) {
            $detail = self::errorDetail($body);
            throw new \RuntimeException(
                'Erreur gamma.app pendant le suivi (HTTP ' . $status . ')' . ($detail !== '' ? ' : ' . $detail : '') . '.'
            );
        }

        $data  = json_decode($body, true);
        $data  = is_array($data) ? $data : [];
        $state = strtolower(trim((string)($data['status'] ?? '')));

        if ($state === 'completed') {
            $credits = is_array($data['credits'] ?? null) ? $data['credits'] : [];
            return [
                'status'            => 'completed',
                'gammaUrl'          => trim((string)($data['gammaUrl'] ?? '')),
                'exportUrl'         => trim((string)($data['exportUrl'] ?? '')),
                'credits_deducted'  => isset($credits['deducted']) ? (int)$credits['deducted'] : null,
                'credits_remaining' => isset($credits['remaining']) ? (int)$credits['remaining'] : null,
                'error'             => '',
            ];
        }
        if ($state === 'failed') {
            $err = is_array($data['error'] ?? null) ? trim((string)($data['error']['message'] ?? '')) : '';
            return [
                'status' => 'failed', 'gammaUrl' => '', 'exportUrl' => '',
                'credits_deducted' => null, 'credits_remaining' => null,
                'error'  => $err !== '' ? mb_substr($err, 0, 300) : 'erreur non détaillée par gamma.app',
            ];
        }
        // pending, ou tout état inconnu d'une future version d'API : on
        // continue d'attendre plutôt que d'échouer sur un libellé nouveau.
        return [
            'status' => 'pending', 'gammaUrl' => '', 'exportUrl' => '',
            'credits_deducted' => null, 'credits_remaining' => null, 'error' => '',
        ];
    }

    /**
     * Aperçu HUMAIN de la génération demandée, pour la carte d'approbation
     * (mêmes clés que UserDriveService::actionPreview + icone).
     *
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string}
     */
    public static function actionPreview(string $tool, array $args, array $config = []): array
    {
        $titre = trim((string)($args['titre'] ?? ''));
        $sujet = trim((string)($args['sujet'] ?? ''));
        $nom   = $titre !== '' ? $titre : ($sujet !== '' ? mb_substr($sujet, 0, 80) : t('(présentation sans titre)'));

        $cartes = self::normalizeCardCount(
            $args['nombre_cartes'] ?? null,
            self::sanitizeMaxCards($config['max_cards'] ?? null)
        );
        $lignes = [
            t('Sujet : {sujet}', ['sujet' => $sujet !== '' ? $sujet : '—']),
            t('Cartes : {n}', ['n' => (string) $cartes]),
        ];
        $instr  = trim((string)($args['instructions'] ?? ''));
        if ($instr !== '') {
            $lignes[] = t('Instructions : {instructions}', ['instructions' => $instr]);
        }
        $apercu = implode("\n", $lignes);
        if (mb_strlen($apercu) > 280) {
            $apercu = mb_substr($apercu, 0, 280) . '…';
        }

        return [
            'action'  => t('Générer une présentation Gamma'),
            'fichier' => $nom,
            'url'     => null,
            'impacts' => [
                t('La présentation sera générée par gamma.app et consommera des crédits Gamma (service payant).'),
                t('Le sujet et les instructions ci-dessous seront envoyés à gamma.app (service externe).'),
                t('La génération dure 1 à 3 minutes ; le lien sera ajouté à la conversation dès qu\'elle sera prête.'),
            ],
            'apercu'  => $apercu,
            'icone'   => '/assets/img/gamma.svg',
        ];
    }

    /**
     * Message assistant final ✅ persisté par le job de suivi.
     * Statique et pure : testable sans réseau.
     *
     * @param array<string,mixed> $status sortie de parseStatusResponse (completed)
     */
    public static function summarizeResultMessage(string $titre, array $status): string
    {
        $titre = trim($titre) !== '' ? trim($titre) : 'Présentation';
        $url   = trim((string)($status['gammaUrl'] ?? ''));
        $texte = $url !== ''
            ? '✅ Présentation Gamma prête : [« ' . $titre . ' »](' . $url . ').'
            : '✅ Présentation Gamma « ' . $titre . ' » générée.';

        $export = trim((string)($status['exportUrl'] ?? ''));
        if ($export !== '') {
            $texte .= ' [Télécharger l\'export](' . $export . ') (lien valable environ une semaine).';
        }
        if (isset($status['credits_remaining']) && $status['credits_remaining'] !== null) {
            $texte .= ' Crédits Gamma restants : ' . (int)$status['credits_remaining'] . '.';
        }
        return $texte;
    }

    // ===================== Appels API =====================

    /**
     * Lance la génération. À n'appeler QUE depuis l'exécution approuvée
     * (driveAction) — jamais pendant le stream.
     *
     * @return string generationId
     * @throws \RuntimeException
     */
    public function createGeneration(array $payload): string
    {
        [$status, $body] = self::request(
            'POST',
            self::API_BASE . '/v1.0/generations',
            $this->headers(['Content-Type: application/json']),
            (string)json_encode($payload, JSON_UNESCAPED_UNICODE),
            self::API_TIMEOUT
        );
        if ($status >= 400) {
            error_log('[gamma] POST /generations HTTP ' . $status . ' : ' . mb_substr($body, 0, 300));
        }
        return self::parseCreateResponse($status, $body);
    }

    /**
     * État d'une génération en cours.
     * @throws \RuntimeException
     */
    public function getGeneration(string $generationId): array
    {
        [$status, $body] = self::request(
            'GET',
            self::API_BASE . '/v1.0/generations/' . rawurlencode(trim($generationId)),
            $this->headers(),
            null,
            self::API_TIMEOUT
        );
        return self::parseStatusResponse($status, $body);
    }

    // ===================== Test d'administration =====================

    /**
     * Bouton « Tester » : `GET /themes` valide la clé et l'accès API sans
     * consommer AUCUN crédit (jamais de génération dans un test). Statique
     * pour rester testable sur des identifiants passés en argument.
     *
     * @return array{ok:bool,detail?:string,error?:string}
     */
    public static function testConnection(string $apiKey, string $themeId = ''): array
    {
        $apiKey = self::sanitizeHeaderValue($apiKey);
        if ($apiKey === '') {
            return ['ok' => false, 'error' => t('Clé API Gamma manquante.')];
        }

        [$status, $body] = self::request('GET', self::API_BASE . '/v1.0/themes', [
            'Accept: application/json',
            'X-API-KEY: ' . $apiKey,
        ], null, self::API_TIMEOUT);

        if ($status === 0) {
            return ['ok' => false, 'error' => t(
                'gamma.app injoignable — vérifiez que le serveur MIA peut sortir vers {url}.',
                ['url' => self::API_BASE]
            )];
        }
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'error' => t(
                'Clé API refusée par gamma.app (HTTP {status}) — vérifiez la clé (format sk-gamma-…) et que le plan du compte inclut l\'accès API (Pro ou supérieur).',
                ['status' => $status]
            )];
        }
        if ($status >= 400) {
            return ['ok' => false, 'error' => t('gamma.app a répondu HTTP {status}.', ['status' => $status])];
        }

        $themes = self::themesFromBody($body);
        if ($themes === null) {
            return ['ok' => false, 'error' => t('Réponse inattendue : cette clé ne répond pas comme l\'API gamma.app.')];
        }

        $detail = I18n::plural(count($themes), 'clé valide — {n} thème disponible.', 'clé valide — {n} thèmes disponibles.');
        $themeId = trim($themeId);
        if ($themeId !== '' && !self::themeExists($themes, $themeId)) {
            $detail .= ' ⚠ ' . t(
                'Le thème « {theme} » est introuvable dans le workspace : la génération utilisera le thème par défaut du compte.',
                ['theme' => $themeId]
            );
        }
        return ['ok' => true, 'detail' => $detail];
    }

    /**
     * Corps du `GET /themes` → liste de thèmes, ou null si la réponse n'a pas
     * la forme attendue. Tolère la liste nue et l'enveloppe `{data: [...]}`.
     */
    public static function themesFromBody(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        if (array_is_list($data)) {
            return $data;
        }
        if (is_array($data['data'] ?? null) && array_is_list($data['data'])) {
            return $data['data'];
        }
        return null;
    }

    /** Le thème (id exact, insensible à la casse) existe-t-il dans la liste ? */
    public static function themeExists(array $themes, string $themeId): bool
    {
        $needle = strtolower(trim($themeId));
        foreach ($themes as $theme) {
            if (is_array($theme) && strtolower(trim((string)($theme['id'] ?? ''))) === $needle) {
                return true;
            }
        }
        return false;
    }

    // ===================== HTTP =====================

    /** @return array<int,string> en-têtes authentifiés */
    private function headers(array $extra = []): array
    {
        return array_merge(['Accept: application/json', 'X-API-KEY: ' . $this->apiKey], $extra);
    }

    /**
     * Requête HTTP brute. Renvoie [statut, corps] ; statut 0 = pas de réponse.
     * Aucune exception ici : chaque appelant présente l'erreur dans SES termes.
     *
     * @param array<int,string> $headers
     * @return array{0:int,1:string}
     */
    private static function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body ?? '',
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'max_redirects' => 1,
        ]]);
        $response = @file_get_contents($url, false, $ctx);
        $status   = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
            }
        }
        return [$status, $response === false ? '' : $response];
    }

    /** Message d'erreur exploitable dans un corps gamma.app, ou ''. */
    private static function errorDetail(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        foreach (['message', 'error', 'detail'] as $key) {
            $val = $decoded[$key] ?? null;
            if (is_array($val)) {
                $val = $val['message'] ?? null;
            }
            if (is_string($val) && trim($val) !== '') {
                return mb_substr(trim($val), 0, 200);
            }
        }
        return '';
    }
}
