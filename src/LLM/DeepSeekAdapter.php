<?php

declare(strict_types=1);

/**
 * Adapter DeepSeek — API OpenAI-compatible.
 * https://api-docs.deepseek.com/
 *
 * Un seul modèle depuis le 10/09/2026 : `deepseek-flash` (DeepSeek V4.1 Flash,
 * vision native, outils, contexte 1M). Les deux anciens modèles (V3 et R1)
 * sont retirés et inaccessibles depuis le 24/07/2026 ; `deepseek-v4-pro` est
 * relayé sur V4.1 Flash jusqu'à la sortie de V4.1 Pro. Tout cela se lit au
 * catalogue (config/llm_catalog.php), source unique.
 *
 * Réflexion : ACTIVE PAR DÉFAUT chez DeepSeek depuis V4. MIA la coupe
 * (`thinking: {type: disabled}`, champ `thinking` du catalogue) — en mode
 * réflexion `temperature` est ignorée, et avec des outils le
 * `reasoning_content` de tous les tours passés doit être renvoyé (400 sinon),
 * que MIA ne conserve pas. Le plafond de sortie (`max_tokens`) vient aussi du
 * catalogue : sans lui, l'API borne à 8K en mode sans réflexion.
 *
 * Recherche Internet (13/11/2026, guide « Web Tools » relu le 2026-11-17) :
 * outil SERVEUR `deepseek_web_search` déclaré dans `tools` — DeepSeek cherche
 * lui-même, MIA n'exécute rien. Sans réflexion (le mode de MIA), le modèle
 * décide de chercher et cite ses sources : `annotations` (`url_citation`) du
 * dernier fragment, rendues en bloc « Sources » comme chez Claude. Recherches
 * facturées dans `usage.server_tool_use` (`tool_search`, `tool_fetch`,
 * `tool_image_search`, même prix). « Other tools cannot be used together with
 * `deepseek_web_search` … yet » : jamais avec les outils de MIA — le
 * catalogue le dit (`tools_with_web_search` faux), le contrôleur arbitre et la
 * boucle d'outils ne déclare pas l'outil. Ni `safety_check` (exigé par
 * DeepSeek d'un service ouvert au PUBLIC ; MIA est interne), ni
 * `deepseek_read_url` (MIA lit les pages collées avec son propre outil).
 */
class DeepSeekAdapter implements LLMInterface
{
    use ChatCompletionsToolLoopTrait;

    private const API_URL = 'https://api.deepseek.com/chat/completions';

    private string $apiKey;
    private string $model;

    public function __construct(?string $modelOverride = null)
    {
        $this->apiKey = ApiKey::getDecrypted('deepseek') ?? '';
        $this->model  = $modelOverride ?? LlmCatalog::defaultModel('deepseek');
    }

    public function getProviderName(): string { return 'deepseek'; }

    /**
     * Champs propres à DeepSeek, lus au catalogue pour le modèle appelé —
     * mode de réflexion (`thinking`) et plafond de sortie (`max_tokens`).
     * Partagés par le flux simple et par CHAQUE aller de la boucle d'outils :
     * deux chemins qui divergeraient feraient réfléchir le modèle sur un
     * message et pas sur l'autre. Pure.
     *
     * @return array<string, mixed>
     */
    public static function requestExtras(string $model): array
    {
        $extras = [];
        $thinking = LlmCatalog::thinkingMode('deepseek', $model);
        if ($thinking !== null) {
            $extras['thinking'] = ['type' => $thinking ? 'enabled' : 'disabled'];
        }
        $max = LlmCatalog::maxOutputTokens('deepseek', $model);
        if ($max !== null) {
            $extras['max_tokens'] = $max;
        }
        return $extras;
    }

    // Boucle d'outils (ChatCompletionsToolLoopTrait), en mode sans réflexion
    // (cf. requestExtras).
    protected function toolLoopUrl(): string
    {
        return LlmCatalog::endpoint('deepseek', 'chat_completions') ?? self::API_URL;
    }

    protected function toolLoopHeaders(): array
    {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
    }

    protected function toolLoopLabel(): string { return 'DeepSeek'; }

    protected function toolLoopMessages(array $messages): array
    {
        return $this->formatMessages($messages);
    }

    protected function toolLoopTemperature(?float $temperature): ?float
    {
        return ($temperature !== null && LlmCatalog::supportsTemperature('deepseek', $this->model)) ? $temperature : null;
    }

    protected function toolLoopPayloadExtras(): array
    {
        return self::requestExtras($this->model);
    }

    /**
     * Outils serveur déclarés quand la recherche Internet est demandée : la
     * recherche seule (cf. en-tête). Pure.
     *
     * @return list<array{type:string}>
     */
    public static function webSearchTools(): array
    {
        return [['type' => 'deepseek_web_search']];
    }

    /**
     * Corps de la demande du flux simple : messages, réglages du catalogue,
     * température si le modèle en règle une, et l'outil de recherche si la
     * recherche est demandée ET que le modèle sait chercher.
     *
     * @return array<string, mixed>
     */
    public function streamPayload(array $messages, string $systemPrompt, ?float $temperature, bool $webSearch): array
    {
        $payload = [
            'model'    => $this->model,
            'stream'   => true,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->formatMessages($messages)
            ),
        ] + self::requestExtras($this->model);

        // Source unique de vérité = catalogue (`temperature` du modèle). NULL
        // (assistant « réinitialisé ») → rien envoyé. Avec l'outil de
        // recherche, DeepSeek l'ignore (sans erreur).
        if ($temperature !== null && LlmCatalog::supportsTemperature('deepseek', $this->model)) {
            $payload['temperature'] = $temperature;
        }
        if ($webSearch && LlmCatalog::supportsWebSearch('deepseek', $this->model)) {
            $payload['tools'] = self::webSearchTools();
        }
        return $payload;
    }

    /**
     * Sources CITÉES par la réponse : `annotations` de type `url_citation`,
     * adresses http(s) seulement, sans doublon, dans l'ordre. DeepSeek met
     * l'adresse en guise de titre quand il ne le connaît pas : titre vide
     * alors (le bloc Sources affiche l'adresse). Pure.
     *
     * @return list<array{url:string,title:string}>
     */
    public static function sourcesFrom(mixed $annotations): array
    {
        $sources = [];
        $seen = [];
        foreach (is_array($annotations) ? $annotations : [] as $a) {
            if (!is_array($a) || ($a['type'] ?? '') !== 'url_citation' || !is_array($a['url_citation'] ?? null)) {
                continue;
            }
            $url = trim((string)($a['url_citation']['url'] ?? ''));
            if ($url === '' || preg_match('#^https?://#i', $url) !== 1 || isset($seen[$url])) {
                continue;
            }
            $title = trim((string)($a['url_citation']['title'] ?? ''));
            $sources[] = ['url' => $url, 'title' => $title === $url ? '' : $title];
            $seen[$url] = true;
        }
        return $sources;
    }

    /**
     * Recherches FACTURÉES d'une réponse : `tool_search` + `tool_fetch` +
     * `tool_image_search` de `usage.server_tool_use` (même prix chez
     * DeepSeek). `web_search_requests` / `web_fetch_requests` ne servent qu'à
     * l'affichage (« not billed counts ») et sont ignorés. Pure.
     */
    public static function billedSearches(mixed $usage): int
    {
        $tools = is_array($usage) ? ($usage['server_tool_use'] ?? null) : null;
        if (!is_array($tools)) {
            return 0;
        }
        $n = 0;
        foreach (['tool_search', 'tool_fetch', 'tool_image_search'] as $key) {
            $n += max(0, (int)($tools[$key] ?? 0));
        }
        return $n;
    }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if ($this->apiKey === '') {
            error_log('[DeepSeekAdapter] Aucune clé API DeepSeek configurée.');
            $onChunk('[Clé API DeepSeek manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }

        $payload = $this->streamPayload($messages, $systemPrompt, $temperature, $webSearch);

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[DeepSeekAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur DeepSeek : requête invalide (encodage payload)]');
            return self::emptyTokens();
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ]),
            'content'       => $body,
            'timeout'       => 180,
            'ignore_errors' => true,
        ]]);

        // Adresse lue au catalogue, comme les autres adaptateurs (la constante
        // reste le repli) : un catalogue qui déclare l'endpoint doit être obéi.
        $url = LlmCatalog::endpoint('deepseek', 'chat_completions') ?? self::API_URL;
        $stream = fopen($url, 'r', false, $ctx);
        if (!$stream) {
            error_log('[DeepSeekAdapter] Impossible d\'ouvrir le stream vers ' . $url);
            $onChunk('[Erreur : impossible de contacter DeepSeek]');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[DeepSeekAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('DeepSeek', $httpStatus, $errMsg));
            return self::emptyTokens();
        }

        $tokensIn  = 0;
        $tokensOut = 0;
        $tokensInCached    = 0;
        $tokensOutReasoning = 0;
        $searches  = 0;     // recherches facturées (usage.server_tool_use)
        $sources   = [];    // sources citées (annotations du dernier fragment)
        $rawBuffer = '';
        $hasText   = false; // du texte est-il parti vers l'appelant ?
        $errorSent = false; // une erreur a-t-elle déjà été émise ?
        $finish    = null;  // finish_reason du dernier évènement

        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) continue;

            if (!str_starts_with($line, 'data: ')) {
                if (LlmAdapterHelpers::isSseNoise($line)) continue;
                $rawBuffer .= $line;
                continue;
            }

            if ($rawBuffer !== '') {
                $msg = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
                error_log('[DeepSeekAdapter] Erreur : ' . $msg);
                $onChunk('[Erreur DeepSeek : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            // Usage lu AVANT l'erreur : une réponse qui échoue en route porte
            // encore son usage, et ce qui a tourné (recherches comprises) est
            // facturé par DeepSeek (« billed as usual »).
            if (isset($json['usage'])) {
                $usage = $json['usage'];
                $tokensIn  = (int)($usage['prompt_tokens']     ?? 0);
                $tokensOut = (int)($usage['completion_tokens'] ?? 0);
                // DeepSeek expose prompt_cache_hit_tokens : tokens lus depuis le cache.
                $tokensInCached     = (int)($usage['prompt_cache_hit_tokens']            ?? 0);
                $tokensOutReasoning = (int)(
                    $usage['completion_tokens_details']['reasoning_tokens']
                    ?? $usage['reasoning_tokens']
                    ?? 0
                );
                $searches = max($searches, self::billedSearches($usage));
            }

            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[DeepSeekAdapter] Erreur SSE : ' . $errMsg);
                $onChunk('[Erreur DeepSeek : ' . $errMsg . ']');
                $errorSent = true;
                break;
            }

            // En mode réflexion, DeepSeek émet reasoning_content puis content —
            // seul content est la réponse (la réflexion est coupée par défaut,
            // cf. requestExtras, mais un catalogue peut la rallumer).
            $content = $json['choices'][0]['delta']['content'] ?? '';
            // Sources citées : une seule fois, dans le dernier fragment.
            foreach (self::sourcesFrom($json['choices'][0]['delta']['annotations'] ?? null) as $src) {
                if (!in_array($src['url'], array_column($sources, 'url'), true)) {
                    $sources[] = $src;
                }
            }
            if ($content !== '') {
                $onChunk($content);
                $hasText = true;
                if (LlmAdapterHelpers::clientAborted()) {
                    break;
                }
            }

            $finish = LlmAdapterHelpers::chatFinishReason($json) ?? $finish;
        }

        fclose($stream);

        // Sources en fin de réponse, comme chez Claude — seulement sous un
        // texte écrit, jamais après une erreur ni un arrêt demandé.
        if ($hasText && !$errorSent && $sources !== [] && !LlmAdapterHelpers::clientAborted()) {
            $onChunk(LlmAdapterHelpers::sourcesMarkdown($sources));
        }

        // Erreur restée en tampon, arrêt anormal du modèle (longueur, filtre…),
        // ou flux vide : une seule clôture pour tous les adaptateurs compatibles.
        $stop = LlmAdapterHelpers::concludeChatStream(
            ['provider' => 'DeepSeek', 'tag' => 'DeepSeekAdapter', 'model' => $this->model, 'status' => $httpStatus, 'context' => [
                'systemPromptLen' => strlen($systemPrompt),
                'messagesCount'   => count($messages),
            ]],
            ['raw' => $rawBuffer, 'text' => $hasText, 'error' => $errorSent, 'finish' => $finish,
             'usage' => ((int)$tokensIn + (int)$tokensOut) > 0],
            $onChunk
        );

        return [
            'input' => $tokensIn, 'output' => $tokensOut,
            'input_cached' => $tokensInCached,
            'input_cache_write' => 0,
            'output_reasoning' => $tokensOutReasoning,
            'web_searches' => $searches,
        ] + ($stop !== null ? ['stop' => $stop] : []);
    }

    private static function emptyTokens(): array
    {
        return [
            'input' => 0, 'output' => 0,
            'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0,
        ];
    }

    /**
     * Images en data URL (JPEG, PNG, GIF, WebP — format détecté sur le
     * contenu) quand le modèle les lit, texte seul sinon ; les PDF ne passent
     * pas (cf. LlmAdapterHelpers::chatContent).
     */
    private function formatMessages(array $messages): array
    {
        return LlmAdapterHelpers::chatContent($messages, LlmCatalog::acceptsImages('deepseek', $this->model));
    }
}
