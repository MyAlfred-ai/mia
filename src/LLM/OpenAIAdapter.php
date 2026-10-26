<?php

declare(strict_types=1);

class OpenAIAdapter implements LLMInterface
{
    use ToolUseUnsupportedTrait; // supportsToolUse()/streamWithTools() surchargés plus bas

    /**
     * Toutes les décisions de routage / de modèle / de tool sont lues depuis
     * `config/llm_catalog.php` via LlmCatalog. Pour ajouter un modèle, éditer
     * uniquement le catalogue.
     */
    private string $apiKey;
    private string $model;

    /**
     * `prompt_cache_key` refusé par l'API (compte, point d'accès compatible…) :
     * on cesse de l'envoyer. Statique à dessein, comme les apprentissages de
     * l'adapter Anthropic — le worker enchaîne les messages dans le même
     * process, inutile de payer un 400 à chaque fois.
     */
    private static bool $promptCacheKeyRejected = false;

    public function __construct(?string $modelOverride = null)
    {
        $this->apiKey = ApiKey::getDecrypted('openai') ?? '';
        $this->model  = $modelOverride
            ?? ApiKey::getModel('openai')
            ?? LlmCatalog::defaultModel('openai');
    }

    private function chatCompletionsUrl(): string
    {
        return LlmCatalog::endpoint('openai', 'chat_completions')
            ?? 'https://api.openai.com/v1/chat/completions';
    }

    private function responsesUrl(): string
    {
        return LlmCatalog::endpoint('openai', 'responses')
            ?? 'https://api.openai.com/v1/responses';
    }

    private function isSearchPreviewModel(string $model): bool
    {
        return LlmCatalog::routing('openai', $model) === 'search_preview';
    }

    /**
     * Le paramètre `temperature` doit-il être envoyé pour cette requête ?
     * - NULL (assistant « réinitialisé ») → non : on n'envoie rien, l'API
     *   utilise la valeur par défaut du modèle.
     * - Modèle marqué `temperature => false` au catalogue (ex: GPT-5.x
     *   raisonnement) → non : l'API rejetterait le paramètre (400).
     */
    private function appliesTemperature(string $model, ?float $temperature): bool
    {
        return $temperature !== null && LlmCatalog::supportsTemperature('openai', $model);
    }

    public function getProviderName(): string { return 'openai'; }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if ($this->apiKey === '') {
            error_log('[OpenAIAdapter] Aucune clé API OpenAI configurée.');
            $onChunk('[Clé API OpenAI manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }

        // Responses API : obligatoire pour certains modèles (ex: GPT-5.x)
        // et préférable pour exploiter la web search sur les modèles compatibles.
        if ($this->shouldUseResponsesApi($webSearch)) {
            return $this->streamViaResponsesApi($messages, $systemPrompt, $onChunk, $temperature, $webSearch);
        }

        // Web search demandé mais le modèle courant ne la fait pas via la
        // Responses API (routing chat_completions, ex. o3). Les anciens modèles
        // *-search-preview étant dépréciés par OpenAI, on délègue la recherche au
        // modèle de recherche par défaut du catalogue, exécuté via la Responses
        // API (outil web_search natif).
        if ($webSearch) {
            $searchModel = LlmCatalog::fallbackSearchModel('openai') ?: 'gpt-5.5';
            return $this->streamViaResponsesApi($messages, $systemPrompt, $onChunk, $temperature, true, $searchModel);
        }

        $model = $this->model;
        $isSearchModel = $this->isSearchPreviewModel($model);

        $payload = [
            'model'   => $model,
            'stream'  => true,
            // Nécessaire pour recevoir l'usage (prompt_tokens / completion_tokens) en streaming :
            // sans ce flag l'API ne l'inclut pas, ce qui rendrait la facturation nulle.
            'stream_options' => ['include_usage' => true],
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->formatMessages($messages, $model)
            ),
        ];

        // Temperature envoyée seulement si réglée (non-null) ET acceptée par le
        // modèle (les search-preview et GPT-5.x raisonnement la rejettent).
        if (!$isSearchModel && $this->appliesTemperature($model, $temperature)) {
            $payload['temperature'] = $temperature;
        }

        // JSON_INVALID_UTF8_SUBSTITUTE : en cas d'octet UTF-8 invalide dans le
        // systemPrompt (texte extrait de PDF bancal par ex.), on substitue par
        // U+FFFD plutôt que de voir json_encode() retourner false — ce qui
        // produirait un corps POST vide et un LLM qui ne répond rien.
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            error_log('[OpenAIAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur OpenAI : requête invalide (encodage payload)]');
            return self::emptyTokens();
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ]),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $stream = fopen($this->chatCompletionsUrl(), 'r', false, $ctx);
        if (!$stream) {
            error_log('[OpenAIAdapter] Impossible d\'ouvrir le stream vers ' . $this->chatCompletionsUrl());
            $onChunk('[Erreur : impossible de contacter OpenAI]');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        // Détection du code HTTP (le stream wrapper expose les headers via $http_response_header)
        $httpStatus = self::extractHttpStatus($http_response_header ?? []);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            // Détail = code machine + message : c'est `insufficient_quota` qui
            // distingue un compte sans crédit d'une limite de débit (deux 429),
            // et `context_length_exceeded` une conversation trop longue d'un
            // paramètre invalide (deux 400).
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[OpenAIAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . ($model ?? $this->model));
            $onChunk('[Erreur OpenAI (' . $httpStatus . ') : ' . $errMsg . ']');
            return self::emptyTokens();
        }

        $tokensIn  = 0;
        $tokensOut = 0;
        $tokensInCached    = 0;
        $tokensInCacheWrite = 0;
        $tokensOutReasoning = 0;
        $rawBuffer = '';
        $hasText   = false; // du texte est-il parti vers l'appelant ?
        $errorSent = false; // une erreur a-t-elle déjà été émise ?
        $finish    = null;  // finish_reason du dernier évènement

        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) continue;

            // Détection d'une réponse d'erreur JSON (pas un SSE)
            if (!str_starts_with($line, 'data: ')) {
                if (LlmAdapterHelpers::isSseNoise($line)) continue;
                $rawBuffer .= $line;
                continue;
            }

            // Si on arrive ici après avoir bufferisé du JSON, c'est une erreur
            if ($rawBuffer !== '') {
                $msg = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
                error_log('[OpenAIAdapter] Erreur (rawBuffer) : ' . $msg);
                $onChunk('[Erreur OpenAI : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            // Erreur dans le flux SSE
            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[OpenAIAdapter] Erreur (SSE) : ' . $errMsg);
                $onChunk('[Erreur OpenAI : ' . $errMsg . ']');
                $errorSent = true;
                break;
            }

            $content = $json['choices'][0]['delta']['content'] ?? '';
            if ($content !== '') {
                $onChunk($content);
                $hasText = true;
            }

            // `length` (plafond de sortie), `content_filter`… : lu à la clôture.
            $finish = LlmAdapterHelpers::chatFinishReason($json) ?? $finish;

            // Stop côté utilisateur : on a flush() un chunk juste avant via $onChunk,
            // donc PHP peut maintenant détecter une connexion abandonnée.
            if (LlmAdapterHelpers::clientAborted()) {
                break;
            }

            if (isset($json['usage'])) {
                $usage = $json['usage'];
                $tokensIn  = (int)($usage['prompt_tokens']     ?? 0);
                $tokensOut = (int)($usage['completion_tokens'] ?? 0);
                $tokensInCached    = (int)($usage['prompt_tokens_details']['cached_tokens']         ?? 0);
                $tokensInCacheWrite = self::cacheWriteTokens($usage);
                $tokensOutReasoning = (int)($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);
            }
        }

        fclose($stream);

        // Clôture commune : corps d'erreur resté en tampon (réponse non-SSE),
        // arrêt anormal du modèle — plafond de sortie atteint, filtre de
        // contenu —, ou flux terminé SANS contenu ni erreur ($http_response_header
        // non exposé par certains SAPI, flux fermé avant le premier chunk) : une
        // erreur explicite remonte, au lieu d'une bulle vide ou d'une réponse
        // coupée sans un mot.
        $stop = LlmAdapterHelpers::concludeChatStream(
            ['provider' => 'OpenAI', 'tag' => 'OpenAIAdapter', 'model' => $model, 'status' => $httpStatus, 'context' => [
                'systemPromptLen' => strlen($systemPrompt),
                'messagesCount'   => count($messages),
            ]],
            ['raw' => $rawBuffer, 'text' => $hasText, 'error' => $errorSent, 'finish' => $finish,
             'usage' => ($tokensIn + $tokensOut) > 0],
            $onChunk
        );

        return [
            'input' => $tokensIn, 'output' => $tokensOut,
            'input_cached' => $tokensInCached,
            'input_cache_write' => $tokensInCacheWrite,
            'output_reasoning' => $tokensOutReasoning,
            // Un modèle *-search-preview cherche à CHAQUE appel (Chat Completions
            // ne détaille pas les recherches) : une recherche par réponse.
            'web_searches' => ($isSearchModel && $tokensOut > 0) ? 1 : 0,
            'model' => $model,
        ] + ($stop !== null ? ['stop' => $stop] : []);
    }

    private static function emptyTokens(): array
    {
        return [
            'input' => 0, 'output' => 0,
            'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0,
            'web_searches' => 0,
        ];
    }

    /**
     * Tokens ÉCRITS en cache par la requête. Depuis GPT-5.6, OpenAI facture
     * l'écriture 1,25× le tarif d'entrée et la détaille : Responses API
     * `input_tokens_details.cache_write_tokens`, Chat Completions
     * `prompt_tokens_details.cache_write_tokens`. Comme `cached_tokens`, c'est
     * un SOUS-ENSEMBLE de l'entrée — la sémantique qu'attend CreditService
     * (entrée plein tarif = total − lu − écrit). Lu à 0 jusqu'ici : ces tokens
     * passaient au tarif d'entrée simple, 20 % sous le coût réel. Absent sur
     * les modèles antérieurs → 0.
     */
    private static function cacheWriteTokens(array $usage): int
    {
        return (int)($usage['input_tokens_details']['cache_write_tokens']
            ?? $usage['prompt_tokens_details']['cache_write_tokens']
            ?? 0);
    }

    /**
     * Nombre d'items `web_search_call` d'une réponse Responses API : chaque
     * item = une recherche exécutée par OpenAI, facturée à l'appel en plus
     * des tokens. Lu sur `response.output` à la complétion.
     */
    private static function countWebSearchCalls(array $response): int
    {
        $count = 0;
        foreach ((array)($response['output'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'web_search_call') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Stream via la Responses API d'OpenAI (GPT-5.x).
     * Format SSE : {"type":"response.output_text.delta","delta":"..."} et {"type":"response.completed"}.
     */
    private function streamViaResponsesApi(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature, bool $webSearch, ?string $modelOverride = null): array
    {
        $model = $modelOverride ?? $this->model;
        $payload = [
            'model'        => $model,
            'input'        => $this->formatMessagesForResponses($messages),
            'instructions' => $systemPrompt,
            'stream'       => true,
        ];

        if ($this->appliesTemperature($model, $temperature)) {
            $payload['temperature'] = $temperature;
        }

        $tools = [];
        if ($webSearch) {
            $tools[] = ['type' => 'web_search'];
        }
        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            error_log('[OpenAIAdapter/Responses] json_encode a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur OpenAI : requête invalide (encodage payload)]');
            return self::emptyTokens();
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ]),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $stream = fopen($this->responsesUrl(), 'r', false, $ctx);
        if (!$stream) {
            error_log('[OpenAIAdapter/Responses] Impossible d\'ouvrir le stream. model=' . $this->model);
            $onChunk('[Erreur : impossible de contacter OpenAI Responses API]');
            return self::emptyTokens();
        }

        $httpStatus = self::extractHttpStatus($http_response_header ?? []);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[OpenAIAdapter/Responses] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            $onChunk('[Erreur OpenAI (' . $httpStatus . ') : ' . $errMsg . ']');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        $tokensIn  = 0;
        $tokensOut = 0;
        $tokensInCached    = 0;
        $tokensInCacheWrite = 0;
        $tokensOutReasoning = 0;
        $webSearches = 0;
        $rawBuffer = '';
        $hasEmittedText = false;
        $errorSent = false; // une erreur a-t-elle déjà été émise ?
        $finish    = null;  // raison d'une réponse incomplète (`incomplete_details.reason`)

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
                error_log('[OpenAIAdapter/Responses] Erreur (rawBuffer) : ' . $msg . ' | model=' . $this->model);
                $onChunk('[Erreur OpenAI : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            $type = $json['type'] ?? '';

            // Chunk texte
            if ($type === 'response.output_text.delta') {
                $delta = $json['delta'] ?? '';
                if ($delta !== '') {
                    $onChunk($delta);
                    $hasEmittedText = true;
                    if (LlmAdapterHelpers::clientAborted()) {
                        break;
                    }
                }
            }

            // Certains flux n'envoient pas de delta mais du texte finalisé par item/part.
            if (!$hasEmittedText && $type === 'response.output_text.done') {
                $text = (string)($json['text'] ?? '');
                if ($text !== '') {
                    $onChunk($text);
                    $hasEmittedText = true;
                }
            }
            if (!$hasEmittedText && $type === 'response.content_part.done') {
                $part = $json['part'] ?? [];
                $text = is_array($part) ? (string)($part['text'] ?? '') : '';
                if ($text !== '') {
                    $onChunk($text);
                    $hasEmittedText = true;
                }
            }
            if (!$hasEmittedText && $type === 'response.output_item.done') {
                $item = $json['item'] ?? [];
                $text = is_array($item) ? $this->extractTextFromResponsesItem($item) : '';
                if ($text !== '') {
                    $onChunk($text);
                    $hasEmittedText = true;
                }
            }

            // Fin du stream + usage. Une réponse peut aussi se terminer
            // INCOMPLÈTE (plafond de sortie atteint, filtre de contenu) ou en
            // ÉCHEC : ces deux fins n'étaient pas lues. La boucle courait alors
            // jusqu'à la fin du flux — usage jamais relevé, donc jamais
            // facturé —, et une réponse coupée l'était sans un mot.
            if (in_array($type, self::RESPONSE_END_EVENTS, true)) {
                $response  = $json['response'] ?? [];
                $usage     = $response['usage'] ?? [];
                $tokensIn  = (int)($usage['input_tokens']  ?? 0);
                $tokensOut = (int)($usage['output_tokens'] ?? 0);
                $tokensInCached    = (int)($usage['input_tokens_details']['cached_tokens']      ?? 0);
                $tokensInCacheWrite = self::cacheWriteTokens($usage);
                $tokensOutReasoning = (int)($usage['output_tokens_details']['reasoning_tokens'] ?? 0);
                $webSearches = self::countWebSearchCalls($response);

                if ($type === 'response.failed') {
                    $errMsg = LlmAdapterHelpers::errorDetail(['error' => $response['error'] ?? null]);
                    error_log('[OpenAIAdapter/Responses] Réponse en échec : ' . $errMsg . ' | model=' . $this->model);
                    $onChunk('[Erreur OpenAI : ' . $errMsg . ']');
                    $errorSent = true;
                    break;
                }
                if ($type === 'response.incomplete') {
                    $finish = self::incompleteReason($response);
                }

                // Fallback : certains modèles n'envoient pas toujours de delta.
                if (!$hasEmittedText) {
                    $finalText = $this->extractResponsesOutputText($response);
                    if ($finalText !== '') {
                        $onChunk($finalText);
                        $hasEmittedText = true;
                    }
                }
                break;
            }

            // Erreur dans le flux
            if ($type === 'error') {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[OpenAIAdapter/Responses] Erreur SSE : ' . $errMsg . ' | model=' . $this->model);
                $onChunk('[Erreur OpenAI : ' . $errMsg . ']');
                $errorSent = true;
                break;
            }
        }

        fclose($stream);

        // Même clôture que la voie Chat Completions : erreur restée en tampon,
        // réponse incomplète, ou flux vide.
        $stop = LlmAdapterHelpers::concludeChatStream(
            ['provider' => 'OpenAI', 'tag' => 'OpenAIAdapter/Responses', 'model' => $model, 'status' => $httpStatus, 'context' => [
                'systemPromptLen' => strlen($systemPrompt),
                'messagesCount'   => count($messages),
            ]],
            ['raw' => $rawBuffer, 'text' => $hasEmittedText, 'error' => $errorSent, 'finish' => $finish,
             'usage' => ($tokensIn + $tokensOut) > 0],
            $onChunk
        );

        return [
            'input' => $tokensIn, 'output' => $tokensOut,
            'input_cached' => $tokensInCached,
            'input_cache_write' => $tokensInCacheWrite,
            'output_reasoning' => $tokensOutReasoning,
            // Recherches exécutées par l'outil serveur (facturées à l'appel)
            // et modèle RÉELLEMENT appelé : sur une bascule vers le modèle de
            // recherche du catalogue, c'est lui que le compteur doit tarifer.
            'web_searches' => $webSearches,
            'model' => $model,
        ] + ($stop !== null ? ['stop' => $stop] : []);
    }

    /**
     * Évènements qui TERMINENT une réponse de la Responses API. Tous portent
     * l'objet `response` et son `usage` : une réponse incomplète ou en échec
     * a consommé des tokens comme une autre.
     */
    private const RESPONSE_END_EVENTS = ['response.completed', 'response.incomplete', 'response.failed'];

    /**
     * Raison d'une réponse terminée incomplète (`incomplete_details.reason` :
     * `max_output_tokens`, `content_filter`). Sans raison lisible, « incomplete »
     * — que LlmAdapterHelpers::stopKind traduit en arrêt anormal générique.
     */
    private static function incompleteReason(array $response): string
    {
        $reason = $response['incomplete_details']['reason'] ?? null;
        return is_string($reason) && $reason !== '' ? $reason : 'incomplete';
    }

    /**
     * Extrait le texte final de la réponse Responses API (fallback sans deltas).
     */
    private function extractResponsesOutputText(array $response): string
    {
        $parts = [];

        // Nouveau format le plus fréquent: response.output[].content[].text
        foreach (($response['output'] ?? []) as $item) {
            if (isset($item['text']) && is_string($item['text'])) {
                $parts[] = (string)$item['text'];
            }
            foreach (($item['content'] ?? []) as $contentPart) {
                if (isset($contentPart['text']) && is_string($contentPart['text'])) {
                    $parts[] = (string)$contentPart['text'];
                }
            }
        }

        // Fallbacks de compatibilité si l'API renvoie une variante
        if (empty($parts) && isset($response['output_text']) && is_string($response['output_text'])) {
            $parts[] = $response['output_text'];
        }
        if (empty($parts) && isset($response['content']) && is_array($response['content'])) {
            foreach ($response['content'] as $contentPart) {
                if (($contentPart['type'] ?? '') === 'output_text' && isset($contentPart['text'])) {
                    $parts[] = (string)$contentPart['text'];
                }
            }
        }

        return implode('', $parts);
    }

    private function extractTextFromResponsesItem(array $item): string
    {
        $parts = [];
        if (isset($item['text']) && is_string($item['text'])) {
            $parts[] = $item['text'];
        }
        foreach (($item['content'] ?? []) as $contentPart) {
            if (isset($contentPart['text']) && is_string($contentPart['text'])) {
                $parts[] = $contentPart['text'];
            }
        }
        return implode('', $parts);
    }

    /**
     * Convertit le format neutre → format Responses API (pas de role system, content types input_text/input_image).
     */
    private function formatMessagesForResponses(array $messages): array
    {
        return array_map(function (array $msg) {
            if (!is_array($msg['content'])) {
                return ['role' => $msg['role'], 'content' => $msg['content']];
            }

            $parts = [];
            foreach ($msg['content'] as $part) {
                if ($part['type'] === 'text') {
                    $parts[] = ['type' => 'input_text', 'text' => $part['text']];
                } elseif ($part['type'] === 'image') {
                    $parts[] = ['type' => 'input_image', 'image_url' => 'data:' . $part['mime'] . ';base64,' . $part['data']];
                } elseif ($part['type'] === 'document') {
                    $parts[] = [
                        'type'      => 'input_file',
                        'filename'  => 'document.pdf',
                        'file_data' => 'data:application/pdf;base64,' . $part['data'],
                    ];
                }
            }
            return ['role' => $msg['role'], 'content' => $parts];
        }, $messages);
    }

    private function shouldUseResponsesApi(bool $webSearch): bool
    {
        $routing = LlmCatalog::routing('openai', $this->model);
        if ($routing === 'responses_api') {
            return true;
        }
        if ($routing === 'responses_web_search' && $webSearch) {
            return true;
        }
        return false;
    }

    /**
     * Utilisé par la Responses API pour formater certains messages différemment
     * quand le modèle configuré est un search-preview (ex: file parts non supportés).
     */
    private function isGpt5FamilyModel(string $model): bool
    {
        return str_starts_with(strtolower(trim($model)), 'gpt-5');
    }

    /**
     * Convertit le format neutre → format OpenAI Chat Completions.
     * image    → {"type":"image_url","image_url":{"url":"data:...;base64,..."}}
     * document → {"type":"file","file":{"filename":"document.pdf","file_data":"data:application/pdf;base64,..."}}
     *            (search models non compatibles → note texte)
     */
    private function formatMessages(array $messages, string $model): array
    {
        return array_map(function (array $msg) use ($model) {
            if (!is_array($msg['content'])) return $msg;

            $parts = [];
            foreach ($msg['content'] as $part) {
                if ($part['type'] === 'text') {
                    $parts[] = ['type' => 'text', 'text' => $part['text']];
                } elseif ($part['type'] === 'image') {
                    $parts[] = ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:' . $part['mime'] . ';base64,' . $part['data'],
                    ]];
                } elseif ($part['type'] === 'document') {
                    if ($this->isSearchPreviewModel($model)) {
                        $parts[] = ['type' => 'text', 'text' => '[Note : PDF joint — modèle search non compatible PDF natif.]'];
                    } else {
                        $parts[] = ['type' => 'file', 'file' => [
                            'filename'  => 'document.pdf',
                            'file_data' => 'data:application/pdf;base64,' . $part['data'],
                        ]];
                    }
                }
            }
            return ['role' => $msg['role'], 'content' => $parts];
        }, $messages);
    }

    /**
     * Extrait le code HTTP du tableau $http_response_header renseigné par le
     * stream wrapper PHP après un fopen() sortant. Retourne null si absent.
     *
     * @param array<int, string> $headers
     */
    private static function extractHttpStatus(array $headers): ?int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                return (int)$m[1];
            }
        }
        return null;
    }

    // ============================================================
    // Function-calling (tool-use) — voie Chat Completions
    // ============================================================

    // TOOL_MAX_ROUNDS est hérité de LLMInterface (source unique — la
    // guidance agent du ChatController annonce le même budget au modèle).

    public function supportsToolUse(): bool
    {
        // Tool-use supporté pour Chat Completions ET Responses API.
        // (les modèles `responses_web_search` retombent eux sur chat_completions
        // quand webSearch n'est pas demandé, donc le tool-use y passe aussi.)
        return $this->apiKey !== '' && LlmCatalog::supportsToolUse('openai', $this->model);
    }

    /**
     * Clé de routage du cache (`prompt_cache_key`) demandée par l'appelant, ou
     * null. OpenAI met en cache de lui-même tout préfixe d'au moins 1 024
     * jetons ; la clé fait arriver les allers-retours d'une même conversation
     * sur la même machine, où le préfixe est déjà en cache. Bornée à 64
     * caractères. Pure.
     */
    public static function promptCacheKey(array $options): ?string
    {
        $key = $options['cache_key'] ?? null;
        if (!is_string($key) || ($options['cache'] ?? null) === 'off') {
            return null;
        }
        $key = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $key) ?? '', 0, 64);
        return $key !== '' ? $key : null;
    }

    /** 400 qui vise `prompt_cache_key` (paramètre inconnu de ce point d'accès). Pure. */
    public static function isPromptCacheKeyRejection(?int $status, string $message): bool
    {
        return $status === 400 && stripos($message, 'prompt_cache_key') !== false;
    }

    public function streamWithTools(
        array $messages,
        string $systemPrompt,
        array $tools,
        callable $onChunk,
        callable $onToolCall,
        ?float $temperature = null,
        bool $webSearch = false,
        array $options = []
    ): array {
        if (!$this->supportsToolUse()) {
            // Modèle non couvert (ou pas de clé) : on retombe sur un stream classique.
            return $this->stream($messages, $systemPrompt, $onChunk, $temperature, $webSearch);
        }
        $cacheKey = self::promptCacheKey($options);

        // Modèles Responses API (GPT-5.x) → chemin dédié function-calling.
        // C'est le SEUL chemin OpenAI où l'outil serveur `web_search` cohabite
        // avec des outils applicatifs : Chat Completions ne le connaît pas.
        $routing = LlmCatalog::routing('openai', $this->model);
        if ($routing === 'responses_api') {
            return $this->streamWithToolsResponsesApi($messages, $systemPrompt, $tools, $onChunk, $onToolCall, $temperature, $webSearch, null, $cacheKey, $options);
        }
        if ($webSearch) {
            // Routage chat_completions : le modèle sait piloter des outils mais
            // pas chercher sur le web. Comme dans stream(), la recherche part
            // sur le modèle de recherche du catalogue via la Responses API —
            // qui porte AUSSI les outils applicatifs, donc rien n'est perdu.
            if ($routing === 'responses_web_search') {
                return $this->streamWithToolsResponsesApi($messages, $systemPrompt, $tools, $onChunk, $onToolCall, $temperature, true, null, $cacheKey, $options);
            }
            $searchModel = LlmCatalog::fallbackSearchModel('openai') ?: 'gpt-5.5';
            error_log('[OpenAIAdapter/Tools] recherche web demandée sur ' . $this->model
                . ' (sans web natif) → bascule sur ' . $searchModel . ' via la Responses API.');
            return $this->streamWithToolsResponsesApi($messages, $systemPrompt, $tools, $onChunk, $onToolCall, $temperature, true, $searchModel, $cacheKey, $options);
        }

        $oaTools = $this->buildOpenAiTools($tools);
        $conv = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $this->formatMessages($messages, $this->model)
        );

        $totIn = 0; $totOut = 0; $totCached = 0; $totCacheWrite = 0; $totReasoning = 0;
        $rounds = []; // usage de chaque aller : le palier « contexte long » vaut par requête
        $textSent = false; // du texte est-il parti, tous rounds confondus ?
        $stop = null;      // arrêt anormal du dernier round (longueur, filtre…)

        $forcedSynthesis = false;
        for ($round = 0; $round < LlmAdapterHelpers::roundsBudget($options); $round++) {
            // Outils relus à chaque aller : un connecteur activé par le modèle
            // (« Laisser MIA choisir ») y apparaît dès l'aller suivant.
            if (is_callable($options['tools_source'] ?? null)) {
                $oaTools = $this->buildOpenAiTools((array)($options['tools_source'])());
            }
            // Dernier round : synthèse forcée (`tool_choice: none`) — des
            // outils appelés au dernier tour seraient exécutés sans que le
            // modèle voie jamais leurs résultats (réponse amputée).
            $dernierRound = $round === LlmAdapterHelpers::roundsBudget($options) - 1;
            $forcedSynthesis = $forcedSynthesis || $dernierRound;
            $payload = [
                'model'          => $this->model,
                'stream'         => true,
                'stream_options' => ['include_usage' => true],
                'messages'       => $conv,
                'tools'          => $oaTools,
                'tool_choice'    => $dernierRound ? 'none' : 'auto',
            ];
            if ($this->appliesTemperature($this->model, $temperature)) {
                $payload['temperature'] = $temperature;
            }
            if ($cacheKey !== null && !self::$promptCacheKeyRejected) {
                $payload['prompt_cache_key'] = $cacheKey;
            }

            $res = $this->streamChatRound($payload, $onChunk);
            if (!empty($res['retry_cache_key'])) {
                unset($payload['prompt_cache_key']);
                $res = $this->streamChatRound($payload, $onChunk);
            }
            $totIn        += $res['input'];
            $totOut       += $res['output'];
            $totCached    += $res['input_cached'];
            $totCacheWrite += $res['input_cache_write'];
            $totReasoning += $res['output_reasoning'];
            $rounds[] = self::roundUsage($res);
            $textSent = $textSent || $res['text'] !== '';

            if ($res['error'] || empty($res['tool_calls'])) {
                // Réponse finale : un arrêt anormal se dit (chunk d'erreur si
                // rien n'a été écrit, clé `stop` sinon). Round en erreur : le
                // chunk est déjà parti.
                if (!$res['error']) {
                    $stop = LlmAdapterHelpers::concludeStop('OpenAI', $res['finish'], $textSent, false, $onChunk);
                }
                break;
            }

            // Tour assistant portant les appels d'outils.
            $assistantTurn = ['role' => 'assistant', 'content' => $res['text'] !== '' ? $res['text'] : null, 'tool_calls' => []];
            foreach ($res['tool_calls'] as $tc) {
                $assistantTurn['tool_calls'][] = [
                    'id'       => $tc['id'],
                    'type'     => 'function',
                    'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments'] !== '' ? $tc['arguments'] : '{}'],
                ];
            }
            $conv[] = $assistantTurn;

            // Exécution de chaque appel → message tool_result réinjecté.
            foreach ($res['tool_calls'] as $tc) {
                // Arrêt demandé (Stop) : plus aucun appel — ni lecture, ni
                // carte d'approbation — sur une réponse coupée. Le contrôle
                // ne venait qu'APRÈS les outils, et le flux ne le fait que sur
                // un fragment de texte : un arrêt pendant qu'arrivaient les
                // arguments d'un appel laissait partir tout le tour.
                if (LlmAdapterHelpers::clientAborted()) {
                    break 2;
                }
                $args = json_decode($tc['arguments'] !== '' ? $tc['arguments'] : '{}', true);
                if (!is_array($args)) { $args = []; }
                $result = $onToolCall($tc['name'], $args);
                $conv[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content'      => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                ];
            }

            if (LlmAdapterHelpers::clientAborted()) {
                break;
            }
        }

        return [
            'input' => $totIn, 'output' => $totOut,
            'input_cached' => $totCached, 'input_cache_write' => $totCacheWrite,
            'output_reasoning' => $totReasoning,
            'rounds' => $rounds,
        ] + ($stop !== null ? ['stop' => $stop] : [])
          + ($forcedSynthesis ? ['rounds_exhausted' => true] : []);
    }

    /** Usage d'un aller de boucle d'outils (cf. CreditService::longContextPart). */
    private static function roundUsage(array $res): array
    {
        return [
            'input'             => (int)($res['input'] ?? 0),
            'output'            => (int)($res['output'] ?? 0),
            'input_cached'      => (int)($res['input_cached'] ?? 0),
            'input_cache_write' => (int)($res['input_cache_write'] ?? 0),
            'output_reasoning'  => (int)($res['output_reasoning'] ?? 0),
        ];
    }

    /**
     * Function-calling via Responses API (GPT-5.x). Boucle modèle ↔ outil :
     * on stream les deltas texte au client en direct et on accumule les items
     * `function_call`. À la fin de chaque round, on exécute chaque appel et
     * on réinjecte un `function_call_output` via `previous_response_id` pour
     * laisser le modèle continuer.
     */
    private function streamWithToolsResponsesApi(
        array $messages,
        string $systemPrompt,
        array $tools,
        callable $onChunk,
        callable $onToolCall,
        ?float $temperature,
        bool $webSearch = false,
        ?string $modelOverride = null,
        ?string $cacheKey = null,
        array $options = []
    ): array {
        $model = $modelOverride ?? $this->model;
        $rspTools = $this->buildResponsesTools($tools);
        // Outil serveur `web_search` en tête : la Responses API accepte outils
        // serveur et outils applicatifs dans le même tableau.
        if ($webSearch) {
            array_unshift($rspTools, ['type' => 'web_search']);
        }
        $input = $this->formatMessagesForResponses($messages);
        $previousResponseId = null;

        $totIn = 0; $totOut = 0; $totCached = 0; $totCacheWrite = 0; $totReasoning = 0; $totSearches = 0;
        $rounds = []; // usage de chaque aller : le palier « contexte long » vaut par requête
        $textSent = false; // du texte est-il parti, tous rounds confondus ?
        $stop = null;      // arrêt anormal du dernier round (réponse incomplète)

        $forcedSynthesis = false;
        for ($round = 0; $round < LlmAdapterHelpers::roundsBudget($options); $round++) {
            if (is_callable($options['tools_source'] ?? null)) {
                $rspTools = $this->buildResponsesTools((array)($options['tools_source'])());
                if ($webSearch) {
                    array_unshift($rspTools, ['type' => 'web_search']);
                }
            }
            // Dernier round : synthèse forcée — même raison que la voie
            // Chat Completions (résultats du tour précédent jamais vus).
            $dernierRound = $round === LlmAdapterHelpers::roundsBudget($options) - 1;
            $forcedSynthesis = $forcedSynthesis || $dernierRound;
            $payload = [
                'model'        => $model,
                'instructions' => $systemPrompt,
                'stream'       => true,
                'tools'        => $rspTools,
                'tool_choice'  => $dernierRound ? 'none' : 'auto',
                'input'        => $input,
            ];
            if ($previousResponseId !== null) {
                $payload['previous_response_id'] = $previousResponseId;
            }
            // Sur le modèle réellement appelé, pas sur celui configuré : un
            // modèle de recherche peut refuser une température acceptée par
            // l'autre (et inversement).
            if ($this->appliesTemperature($model, $temperature)) {
                $payload['temperature'] = $temperature;
            }
            if ($cacheKey !== null && !self::$promptCacheKeyRejected) {
                $payload['prompt_cache_key'] = $cacheKey;
            }

            $res = $this->streamResponsesToolRound($payload, $onChunk);
            if (!empty($res['retry_cache_key'])) {
                unset($payload['prompt_cache_key']);
                $res = $this->streamResponsesToolRound($payload, $onChunk);
            }
            $totIn        += $res['input'];
            $totOut       += $res['output'];
            $totCached    += $res['input_cached'];
            $totCacheWrite += $res['input_cache_write'];
            $totReasoning += $res['output_reasoning'];
            $totSearches  += $res['web_searches'];
            $rounds[] = self::roundUsage($res);
            $textSent = $textSent || $res['text'] !== '';

            if ($res['response_id'] !== null) {
                $previousResponseId = $res['response_id'];
            }

            if ($res['error'] || empty($res['function_calls'])) {
                // Réponse finale : même règle que la voie Chat Completions.
                if (!$res['error']) {
                    $stop = LlmAdapterHelpers::concludeStop('OpenAI', $res['finish'], $textSent, false, $onChunk);
                }
                break;
            }

            // Exécute chaque appel d'outil et prépare l'input du round suivant.
            $input = [];
            foreach ($res['function_calls'] as $fc) {
                // Arrêt demandé (Stop) : même contrôle que la voie Chat
                // Completions, avant CHAQUE appel.
                if (LlmAdapterHelpers::clientAborted()) {
                    break 2;
                }
                $argsRaw = $fc['arguments'] !== '' ? $fc['arguments'] : '{}';
                $args = json_decode($argsRaw, true);
                if (!is_array($args)) $args = [];
                $output = $onToolCall($fc['name'], $args);
                $outputJson = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
                $input[] = [
                    'type'    => 'function_call_output',
                    'call_id' => $fc['call_id'],
                    'output'  => $outputJson,
                ];
            }

            if (LlmAdapterHelpers::clientAborted()) {
                break;
            }
        }

        return [
            'input' => $totIn, 'output' => $totOut,
            'input_cached' => $totCached, 'input_cache_write' => $totCacheWrite,
            'output_reasoning' => $totReasoning,
            'web_searches' => $totSearches,
            'model' => $model,
            'rounds' => $rounds,
        ] + ($stop !== null ? ['stop' => $stop] : [])
          + ($forcedSynthesis ? ['rounds_exhausted' => true] : []);
    }

    /**
     * Un round Responses API en streaming avec outils :
     * - Émet les deltas texte via $onChunk en temps réel.
     * - Accumule les items `function_call` (id, name, arguments) pour le tour suivant.
     * - Capture usage tokens + response_id pour chaîner via previous_response_id.
     *
     * @return array{
     *   text: string,
     *   function_calls: list<array{call_id:string,name:string,arguments:string}>,
     *   input: int, output: int, input_cached: int, input_cache_write: int,
     *   output_reasoning: int, response_id: ?string, error: bool
     * }
     */
    private function streamResponsesToolRound(array $payload, callable $onChunk): array
    {
        $fail = static fn(): array => [
            'text' => '', 'function_calls' => [],
            'input' => 0, 'output' => 0, 'input_cached' => 0, 'input_cache_write' => 0,
            'output_reasoning' => 0, 'web_searches' => 0, 'response_id' => null, 'error' => true,
            'finish' => null,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            error_log('[OpenAIAdapter/ToolsResponses] json_encode payload échoué : ' . json_last_error_msg());
            $onChunk('[Erreur OpenAI : requête invalide (encodage payload)]');
            return $fail();
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ]),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $stream = fopen($this->responsesUrl(), 'r', false, $ctx);
        if (!$stream) {
            error_log('[OpenAIAdapter/ToolsResponses] Impossible d\'ouvrir le stream. model=' . $this->model);
            $onChunk('[Erreur : impossible de contacter OpenAI Responses API]');
            return $fail();
        }

        $httpStatus = self::extractHttpStatus($http_response_header ?? []);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[OpenAIAdapter/ToolsResponses] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            if (isset($payload['prompt_cache_key']) && self::isPromptCacheKeyRejection($httpStatus, $errMsg)) {
                self::$promptCacheKeyRejected = true;
                return ['retry_cache_key' => true] + $fail();
            }
            $onChunk('[Erreur OpenAI (' . $httpStatus . ') : ' . $errMsg . ']');
            return $fail();
        }
        stream_set_read_buffer($stream, 0);

        $text = '';
        $fcByItem = []; // item_id => {call_id,name,arguments}
        $tokensIn = 0; $tokensOut = 0;
        $tokensInCached = 0; $tokensInCacheWrite = 0; $tokensOutReasoning = 0;
        $webSearches = 0;
        $responseId = null;
        $finish = null;  // raison d'une réponse incomplète
        $failed = false; // `response.failed` : erreur émise, usage conservé

        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) continue;
            if (!str_starts_with($line, 'data: ')) continue;

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            $type = $json['type'] ?? '';

            if (in_array($type, ['response.created', 'response.in_progress'], true)) {
                $responseId = $json['response']['id'] ?? $responseId;
                continue;
            }

            // Texte streamé du modèle.
            if ($type === 'response.output_text.delta') {
                $delta = (string)($json['delta'] ?? '');
                if ($delta !== '') {
                    $text .= $delta;
                    $onChunk($delta);
                    if (LlmAdapterHelpers::clientAborted()) break;
                }
                continue;
            }

            // Apparition d'un item function_call : on initialise le buffer.
            if ($type === 'response.output_item.added') {
                $item = $json['item'] ?? [];
                if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                    $itemId = (string)($item['id'] ?? '');
                    if ($itemId !== '') {
                        $fcByItem[$itemId] = [
                            'call_id'   => (string)($item['call_id'] ?? ''),
                            'name'      => (string)($item['name'] ?? ''),
                            'arguments' => (string)($item['arguments'] ?? ''),
                        ];
                    }
                }
                continue;
            }
            // Streaming des arguments JSON de l'appel d'outil.
            if ($type === 'response.function_call_arguments.delta') {
                $itemId = (string)($json['item_id'] ?? '');
                if ($itemId !== '' && isset($fcByItem[$itemId])) {
                    $fcByItem[$itemId]['arguments'] .= (string)($json['delta'] ?? '');
                }
                continue;
            }
            if ($type === 'response.function_call_arguments.done') {
                $itemId = (string)($json['item_id'] ?? '');
                if ($itemId !== '' && isset($fcByItem[$itemId]) && isset($json['arguments'])) {
                    $fcByItem[$itemId]['arguments'] = (string)$json['arguments'];
                }
                continue;
            }
            // Item function_call finalisé : on prend la version définitive.
            if ($type === 'response.output_item.done') {
                $item = $json['item'] ?? [];
                if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                    $itemId = (string)($item['id'] ?? '');
                    if ($itemId !== '') {
                        $fcByItem[$itemId] = [
                            'call_id'   => (string)($item['call_id']   ?? ($fcByItem[$itemId]['call_id']   ?? '')),
                            'name'      => (string)($item['name']      ?? ($fcByItem[$itemId]['name']      ?? '')),
                            'arguments' => (string)($item['arguments'] ?? ($fcByItem[$itemId]['arguments'] ?? '')),
                        ];
                    }
                }
                continue;
            }

            // Fin de la réponse — complète, incomplète ou en échec (cf.
            // RESPONSE_END_EVENTS) : l'usage est relevé dans les trois cas.
            if (in_array($type, self::RESPONSE_END_EVENTS, true)) {
                $response  = $json['response'] ?? [];
                $usage     = $response['usage'] ?? [];
                $tokensIn  = (int)($usage['input_tokens']  ?? 0);
                $tokensOut = (int)($usage['output_tokens'] ?? 0);
                $tokensInCached     = (int)($usage['input_tokens_details']['cached_tokens']      ?? 0);
                $tokensInCacheWrite = self::cacheWriteTokens($usage);
                $tokensOutReasoning = (int)($usage['output_tokens_details']['reasoning_tokens'] ?? 0);
                $webSearches = self::countWebSearchCalls($response);
                $responseId = $response['id'] ?? $responseId;

                if ($type === 'response.failed') {
                    $errMsg = LlmAdapterHelpers::errorDetail(['error' => $response['error'] ?? null]);
                    error_log('[OpenAIAdapter/ToolsResponses] Réponse en échec : ' . $errMsg . ' | model=' . $this->model);
                    $onChunk('[Erreur OpenAI : ' . $errMsg . ']');
                    $failed = true;
                    break;
                }
                if ($type === 'response.incomplete') {
                    $finish = self::incompleteReason($response);
                }

                // Filet de sécurité : si certains événements de delta ont été
                // omis, on récupère les function_call directement depuis output[].
                foreach (($response['output'] ?? []) as $item) {
                    if (!is_array($item) || ($item['type'] ?? '') !== 'function_call') {
                        continue;
                    }
                    $itemId = (string)($item['id'] ?? '');
                    if ($itemId === '' || isset($fcByItem[$itemId])) {
                        continue;
                    }
                    $fcByItem[$itemId] = [
                        'call_id'   => (string)($item['call_id'] ?? ''),
                        'name'      => (string)($item['name'] ?? ''),
                        'arguments' => (string)($item['arguments'] ?? ''),
                    ];
                }
                break;
            }

            if ($type === 'error') {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[OpenAIAdapter/ToolsResponses] Erreur SSE : ' . $errMsg . ' | model=' . $this->model);
                $onChunk('[Erreur OpenAI : ' . $errMsg . ']');
                fclose($stream);
                return $fail();
            }
        }

        fclose($stream);

        $functionCalls = [];
        foreach ($fcByItem as $fc) {
            if ($fc['name'] !== '' && $fc['call_id'] !== '') {
                $functionCalls[] = $fc;
            }
        }

        return [
            'text'             => $text,
            // Réponse en échec : ses appels d'outils appartiennent à une sortie
            // abandonnée, ils ne sont pas exécutés.
            'function_calls'   => $failed ? [] : $functionCalls,
            'input'            => $tokensIn,
            'output'           => $tokensOut,
            'input_cached'     => $tokensInCached,
            'input_cache_write' => $tokensInCacheWrite,
            'output_reasoning' => $tokensOutReasoning,
            'web_searches'     => $webSearches,
            'response_id'      => $responseId,
            'error'            => $failed,
            'finish'           => $finish,
        ];
    }

    /**
     * Schéma d'outil agnostique → format Responses API (champs à plat, pas
     * d'enveloppe `function: {}` comme dans Chat Completions).
     */
    private function buildResponsesTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $params = $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            $out[] = [
                'type'        => 'function',
                'name'        => (string)($t['name'] ?? ''),
                'description' => (string)($t['description'] ?? ''),
                'parameters'  => $params,
            ];
        }
        return $out;
    }

    /**
     * Traduit le schéma d'outil agnostique → format OpenAI function tools.
     */
    private function buildOpenAiTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $params = $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            $out[] = ['type' => 'function', 'function' => [
                'name'        => (string)($t['name'] ?? ''),
                'description' => (string)($t['description'] ?? ''),
                'parameters'  => $params,
            ]];
        }
        return $out;
    }

    /**
     * Un aller : POST chat_completions en streaming, émet le texte via $onChunk,
     * accumule les éventuels tool_calls (fragmentés par index) et l'usage.
     *
     * @return array{text:string,tool_calls:list<array{id:string,name:string,arguments:string}>,input:int,output:int,input_cached:int,output_reasoning:int,error:bool}
     */
    private function streamChatRound(array $payload, callable $onChunk): array
    {
        $fail = static function (): array {
            return ['text' => '', 'tool_calls' => [], 'input' => 0, 'output' => 0,
                    'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0, 'error' => true,
                    'finish' => null];
        };

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            error_log('[OpenAIAdapter/Tools] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur OpenAI : requête invalide (encodage payload)]');
            return $fail();
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ]),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $stream = fopen($this->chatCompletionsUrl(), 'r', false, $ctx);
        if (!$stream) {
            error_log('[OpenAIAdapter/Tools] Impossible d\'ouvrir le stream.');
            $onChunk('[Erreur : impossible de contacter OpenAI]');
            return $fail();
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = self::extractHttpStatus($http_response_header ?? []);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[OpenAIAdapter/Tools] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            if (isset($payload['prompt_cache_key']) && self::isPromptCacheKeyRejection($httpStatus, $errMsg)) {
                self::$promptCacheKeyRejected = true;
                return ['retry_cache_key' => true] + $fail();
            }
            $onChunk('[Erreur OpenAI (' . $httpStatus . ') : ' . $errMsg . ']');
            return $fail();
        }

        $text = '';
        $toolCalls = []; // index => ['id','name','arguments']
        $in = 0; $out = 0; $cached = 0; $cacheWrite = 0; $reasoning = 0;
        $rawBuffer = '';
        $finish = null; // finish_reason du dernier évènement

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
                error_log('[OpenAIAdapter/Tools] Erreur (rawBuffer) : ' . $msg);
                $onChunk('[Erreur OpenAI : ' . $msg . ']');
                fclose($stream);
                return $fail();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[OpenAIAdapter/Tools] Erreur (SSE) : ' . $errMsg);
                $onChunk('[Erreur OpenAI : ' . $errMsg . ']');
                fclose($stream);
                return $fail();
            }

            $delta = $json['choices'][0]['delta'] ?? [];
            $finish = LlmAdapterHelpers::chatFinishReason($json) ?? $finish;

            $content = $delta['content'] ?? '';
            if ($content !== '') {
                $onChunk($content);
                $text .= $content;
                if (LlmAdapterHelpers::clientAborted()) break;
            }

            if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    $idx = (int)($tcDelta['index'] ?? 0);
                    if (!isset($toolCalls[$idx])) {
                        $toolCalls[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                    }
                    if (!empty($tcDelta['id'])) {
                        $toolCalls[$idx]['id'] = (string)$tcDelta['id'];
                    }
                    $fn = $tcDelta['function'] ?? [];
                    if (isset($fn['name']) && $fn['name'] !== '') {
                        $toolCalls[$idx]['name'] .= (string)$fn['name'];
                    }
                    if (isset($fn['arguments'])) {
                        $toolCalls[$idx]['arguments'] .= (string)$fn['arguments'];
                    }
                }
            }

            if (isset($json['usage'])) {
                $usage = $json['usage'];
                $in       = (int)($usage['prompt_tokens']     ?? 0);
                $out      = (int)($usage['completion_tokens'] ?? 0);
                $cached   = (int)($usage['prompt_tokens_details']['cached_tokens']         ?? 0);
                $cacheWrite = self::cacheWriteTokens($usage);
                $reasoning = (int)($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);
            }
        }

        fclose($stream);

        // Corps d'erreur resté en tampon (réponse JSON sans aucune ligne `data:`) :
        // ce round ne l'émettait pas, et la boucle concluait sur un flux vide.
        if ($rawBuffer !== '' && $text === '' && $toolCalls === []) {
            $msg = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
            error_log('[OpenAIAdapter/Tools] Erreur (fin de flux) : ' . $msg);
            $onChunk('[Erreur OpenAI : ' . $msg . ']');
            return $fail();
        }

        // Normalise : tri par index, drop des appels sans nom, id de secours.
        ksort($toolCalls);
        $final = [];
        foreach ($toolCalls as $tc) {
            if (trim($tc['name']) === '') continue;
            if ($tc['id'] === '') $tc['id'] = 'call_' . count($final);
            $final[] = $tc;
        }

        return ['text' => $text, 'tool_calls' => $final, 'input' => $in, 'output' => $out,
                'input_cached' => $cached, 'input_cache_write' => $cacheWrite,
                'output_reasoning' => $reasoning, 'error' => false, 'finish' => $finish];
    }
}
