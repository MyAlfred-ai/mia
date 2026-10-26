<?php

declare(strict_types=1);

class MistralAdapter implements LLMInterface
{
    use ChatCompletionsToolLoopTrait;

    /** Endpoints et tools web_search lus depuis `config/llm_catalog.php`. */
    private const FILE_SIGNED_URL_EXPIRY_HOURS = 24;
    private const FILE_UPLOAD_PURPOSE = 'ocr';

    private string $apiKey;
    private string $model;
    /** @var array<string, string> */
    private array $signedUrlCache = [];
    private bool $disableFilesApi = false;

    public function __construct(?string $modelOverride = null)
    {
        $this->apiKey = ApiKey::getDecrypted('mistral') ?? '';
        $this->model  = $modelOverride
            ?? ApiKey::getModel('mistral')
            ?? LlmCatalog::defaultModel('mistral');
    }

    private function chatUrl(): string
    {
        return LlmCatalog::endpoint('mistral', 'chat')
            ?? 'https://api.mistral.ai/v1/chat/completions';
    }

    private function conversationsUrl(): string
    {
        return LlmCatalog::endpoint('mistral', 'conversations')
            ?? 'https://api.mistral.ai/v1/conversations';
    }

    private function filesUrl(): string
    {
        return LlmCatalog::endpoint('mistral', 'files')
            ?? 'https://api.mistral.ai/v1/files';
    }

    private function webSearchToolName(bool $preferPrimary): string
    {
        return LlmCatalog::webSearchTool('mistral', $preferPrimary)
            ?? ($preferPrimary ? 'web_search_premium' : 'web_search');
    }

    public function getProviderName(): string { return 'mistral'; }

    // Boucle d'outils (ChatCompletionsToolLoopTrait) via Chat Completions —
    // la recherche web, elle, vit dans la Conversations API : le contrôleur
    // arbitre quand les deux sont demandées (LlmCatalog::supportsWebSearchWithTools).
    protected function toolLoopUrl(): string { return $this->chatUrl(); }

    protected function toolLoopHeaders(): array
    {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
    }

    protected function toolLoopLabel(): string { return 'Mistral'; }

    protected function toolLoopMessages(array $messages): array
    {
        return $this->formatMessages($messages);
    }

    protected function toolLoopTemperature(?float $temperature): ?float
    {
        return LlmCatalog::temperatureFor('mistral', $this->model, $temperature);
    }

    protected function toolLoopEmit(callable $onChunk, string $text): bool
    {
        return $this->emitAssistantChunk($onChunk, $text);
    }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if ($this->apiKey === '') {
            error_log('[MistralAdapter] Aucune clé API Mistral configurée.');
            $onChunk('[Clé API Mistral manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }

        if ($webSearch) {
            return $this->streamViaConversations($messages, $systemPrompt, $onChunk, $temperature);
        }

        $payload = [
            'model'       => $this->model,
            'stream'      => true,
            'messages'    => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->formatMessages($messages)
            ),
        ];
        // NULL (assistant « réinitialisé ») → on n'envoie rien. Le curseur va
        // jusqu'à 2, Mistral refuse au-delà de 1,5 : borne lue au catalogue.
        $sendTemperature = LlmCatalog::temperatureFor('mistral', $this->model, $temperature);
        if ($sendTemperature !== null) {
            $payload['temperature'] = $sendTemperature;
        }

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[MistralAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur Mistral : requête invalide (encodage payload)]');
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

        $stream = fopen($this->chatUrl(), 'r', false, $ctx);
        if (!$stream) {
            error_log('[MistralAdapter] Impossible d\'ouvrir le stream vers ' . $this->chatUrl());
            $onChunk('[Erreur : impossible de contacter Mistral]');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[MistralAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('Mistral', $httpStatus, $errMsg));
            return self::emptyTokens();
        }

        $tokensIn  = 0;
        $tokensOut = 0;
        $tokensInCached = 0;
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
                error_log('[MistralAdapter] Erreur (rawBuffer) : ' . $msg);
                $onChunk('[Erreur Mistral : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[MistralAdapter] Erreur (SSE) : ' . $errMsg);
                $onChunk('[Erreur Mistral : ' . $errMsg . ']');
                $errorSent = true;
                break;
            }

            // Une chaîne, OU une liste de morceaux typés : les modèles qui
            // raisonnent (Small 4, Medium 3.5, Large 4 avec `reasoning_effort`)
            // envoient leur réflexion en morceau `thinking`, puis la transition
            // dans une liste [thinking fermé, text]. Seuls les morceaux `text`
            // sont la réponse — passer la liste telle quelle à
            // emitAssistantChunk(string) levait un TypeError et coupait la
            // réponse net.
            $content = self::deltaText($json['choices'][0]['delta']['content'] ?? '');
            if ($content !== '') {
                $hasText = $this->emitAssistantChunk($onChunk, $content) || $hasText;
                if (LlmAdapterHelpers::clientAborted()) {
                    break;
                }
            }

            $finish = LlmAdapterHelpers::chatFinishReason($json) ?? $finish;

            if (isset($json['usage'])) {
                $usage = $json['usage'];
                $tokensIn  = (int)($usage['prompt_tokens']     ?? 0);
                $tokensOut = (int)($usage['completion_tokens'] ?? 0);
                $tokensInCached = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
            }
        }

        fclose($stream);

        // Erreur restée en tampon, arrêt anormal du modèle (longueur, contexte…),
        // ou flux vide : clôture commune aux adaptateurs Chat Completions.
        $stop = LlmAdapterHelpers::concludeChatStream(
            ['provider' => 'Mistral', 'tag' => 'MistralAdapter', 'model' => $this->model, 'status' => $httpStatus, 'context' => [
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
            'input_cache_write' => 0,
            'output_reasoning' => 0,
        ] + ($stop !== null ? ['stop' => $stop] : []);
    }

    private static function emptyTokens(): array
    {
        return [
            'input' => 0, 'output' => 0,
            'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0,
        ];
    }

    private static function emptyTokensWithRetry(bool $retry): array
    {
        return [
            'input' => 0, 'output' => 0,
            'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0,
            'retry_with_fallback' => $retry,
        ];
    }

    /** Réduit le tableau interne (avec retry_with_fallback) au contrat public. */
    private static function projectTokens(array $r): array
    {
        return [
            'input'             => (int)($r['input']             ?? 0),
            'output'            => (int)($r['output']            ?? 0),
            'input_cached'      => (int)($r['input_cached']      ?? 0),
            'input_cache_write' => (int)($r['input_cache_write'] ?? 0),
            'output_reasoning'  => (int)($r['output_reasoning']  ?? 0),
            'web_searches'      => (int)($r['web_searches']      ?? 0),
        ];
    }

    /**
     * Usage d'une réponse de la Conversations API — pure. Les résultats de la
     * recherche Internet arrivent en `connector_tokens` (comptés dans
     * `total_tokens`, hors `prompt_tokens`) et le nombre d'appels dans
     * `connectors.web_search` (exemple de la page « Websearch » de Mistral :
     * 188 jetons de prompt, 7 112 de connecteur, une recherche). Ni l'un ni
     * l'autre n'étaient lus : une réponse appuyée sur Internet était facturée
     * comme si elle n'avait rien lu, et ses recherches — facturées à l'appel
     * par Mistral — ne laissaient aucune trace. Les jetons de résultats sont
     * de l'entrée, comme chez OpenAI et Anthropic.
     *
     * @return array{input:int, output:int, input_cached:int, web_searches:int}|null null = pas d'usage
     */
    public static function conversationUsage(array $usage): ?array
    {
        if (!isset($usage['input_tokens']) && !isset($usage['prompt_tokens'])
            && !isset($usage['output_tokens']) && !isset($usage['completion_tokens'])) {
            return null;
        }
        $searches = 0;
        foreach ((array)($usage['connectors'] ?? []) as $name => $count) {
            if (is_string($name) && str_starts_with($name, 'web_search') && is_numeric($count)) {
                $searches += max(0, (int)$count);
            }
        }
        return [
            'input'        => max(0, (int)($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0))
                + max(0, (int)($usage['connector_tokens'] ?? 0)),
            'output'       => max(0, (int)($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0)),
            'input_cached' => max(0, (int)($usage['prompt_tokens_details']['cached_tokens']
                ?? $usage['input_tokens_details']['cached_tokens'] ?? 0)),
            'web_searches' => $searches,
        ];
    }

    /**
     * Recherche web native Mistral via Conversations API.
     * Préfère web_search_premium et bascule automatiquement sur web_search si indisponible.
     * Utilise le flux SSE de Conversations (stream=true) pour afficher la réponse en direct.
     *
     * @return array{input: int, output: int}
     */
    private function streamViaConversations(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null): array
    {
        $primary = $this->runConversationStreamWithTool(
            $messages,
            $systemPrompt,
            $onChunk,
            $temperature,
            $this->webSearchToolName(true),
            true
        );

        if ($primary['retry_with_fallback']) {
            error_log('[MistralAdapter] web_search_premium indisponible, fallback vers web_search.');
            $fallback = $this->runConversationStreamWithTool(
                $messages,
                $systemPrompt,
                $onChunk,
                $temperature,
                $this->webSearchToolName(false),
                false
            );
            return self::projectTokens($fallback);
        }

        return self::projectTokens($primary);
    }

    /**
     * @return array{input:int, output:int, retry_with_fallback:bool}
     */
    private function runConversationStreamWithTool(
        array $messages,
        string $systemPrompt,
        callable $onChunk,
        ?float $temperature,
        string $toolType,
        bool $suppressToolErrors
    ): array {
        $payload = [
            'model'           => $this->model,
            'stream'          => true,
            'inputs'          => $this->formatMessages($messages),
            'tools'           => [['type' => $toolType]],
            'store'           => false,
        ];
        if ($systemPrompt !== '') {
            $payload['instructions'] = $systemPrompt;
        }
        $sendTemperature = LlmCatalog::temperatureFor('mistral', $this->model, $temperature);
        if ($sendTemperature !== null) {
            $payload['completion_args'] = ['temperature' => $sendTemperature];
        }

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[MistralAdapter/Conversations] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur Mistral : requête invalide (encodage payload)]');
            return self::emptyTokensWithRetry(false);
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
            ]),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $stream = fopen($this->conversationsUrl(), 'r', false, $ctx);
        if (!$stream) {
            error_log('[MistralAdapter] Impossible d\'ouvrir le stream vers ' . $this->conversationsUrl());
            $onChunk('[Erreur : impossible de contacter Mistral]');
            return self::emptyTokensWithRetry(false);
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[MistralAdapter/Conversations] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            // Un outil de recherche non reconnu est refusé par un HTTP 400,
            // AVANT le flux : sans ce test, le repli vers l'outil standard
            // n'était jamais tenté et l'option Internet rendait une erreur.
            if ($suppressToolErrors && $this->shouldFallbackToStandardWebSearch($errMsg)) {
                return self::emptyTokensWithRetry(true);
            }
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('Mistral', $httpStatus, $errMsg));
            return self::emptyTokensWithRetry(false);
        }

        $tokensIn  = 0;
        $tokensOut = 0;
        $tokensInCached = 0;
        $webSearches = 0;
        $rawBuffer = '';
        $sawSseData = false;
        $hasDelta = false;
        $eventName = '';
        $dataLines = [];
        $fatalError = null;

        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) continue;

            $trimmed = rtrim($line, "\r\n");

            // Fin d'un event SSE : traiter le bloc (event + data)
            if ($trimmed === '') {
                if ($dataLines !== []) {
                    $sawSseData = true;
                    $stop = $this->handleConversationSseEvent(
                        $eventName,
                        implode("\n", $dataLines),
                        $onChunk,
                        $tokensIn,
                        $tokensOut,
                        $tokensInCached,
                        $webSearches,
                        $hasDelta,
                        $fatalError,
                        !$suppressToolErrors
                    );
                    $eventName = '';
                    $dataLines = [];
                    if ($stop) {
                        break;
                    }
                    if (LlmAdapterHelpers::clientAborted()) {
                        break;
                    }
                }
                continue;
            }

            if (str_starts_with($trimmed, 'event:')) {
                $eventName = trim(substr($trimmed, 6));
                continue;
            }
            if (str_starts_with($trimmed, 'data:')) {
                $dataLines[] = ltrim(substr($trimmed, 5));
                continue;
            }
            if (str_starts_with($trimmed, ':') || str_starts_with($trimmed, 'id:') || str_starts_with($trimmed, 'retry:')) {
                continue;
            }

            // Réponse non-SSE (erreur JSON), on bufferise
            $rawBuffer .= $line;
        }

        // Dernier bloc SSE éventuel sans ligne vide finale
        if ($dataLines !== []) {
            $sawSseData = true;
            $this->handleConversationSseEvent(
                $eventName,
                implode("\n", $dataLines),
                $onChunk,
                $tokensIn,
                $tokensOut,
                $tokensInCached,
                $webSearches,
                $hasDelta,
                $fatalError,
                !$suppressToolErrors
            );
        }

        fclose($stream);

        // Fallback: si pas de SSE (ou réponse d'erreur JSON), on conserve le comportement "bloc"
        if (!$sawSseData && $rawBuffer !== '') {
            $json = json_decode($rawBuffer, true);
            if (!is_array($json)) {
                error_log('[MistralAdapter] Réponse invalide Conversations API: ' . substr($rawBuffer, 0, 800));
                $onChunk('[Erreur Mistral : réponse invalide]');
                return self::emptyTokensWithRetry(false);
            }

            // Erreur à la façon d'OpenAI ({error: {…}}) ou à plat, comme
            // Mistral la sert ({object: "error", message, type, code}).
            if (isset($json['error']) || ($json['object'] ?? '') === 'error') {
                $fatalError = LlmAdapterHelpers::errorDetail($json, $rawBuffer, $httpStatus);
                $retry = $suppressToolErrors && !$hasDelta && $this->shouldFallbackToStandardWebSearch($fatalError);
                if (!$retry) {
                    error_log('[MistralAdapter] Erreur Conversations API: ' . $fatalError);
                    $onChunk('[Erreur Mistral : ' . $fatalError . ']');
                }
                return self::emptyTokensWithRetry($retry);
            }

            $text = $this->extractConversationText($json);
            if ($text !== '') {
                $this->emitAssistantChunk($onChunk, $text);
                $hasDelta = true;
            }

            $usage = self::conversationUsage((array)($json['usage'] ?? []));
            if ($usage !== null) {
                $tokensIn       = $usage['input'];
                $tokensOut      = $usage['output'];
                $tokensInCached = $usage['input_cached'];
                $webSearches    = $usage['web_searches'];
            }
        }

        $retryWithFallback = $suppressToolErrors && !$hasDelta && $fatalError !== null && $this->shouldFallbackToStandardWebSearch($fatalError);
        $needsErrorEmission = $fatalError !== null
            && !$retryWithFallback
            && ($suppressToolErrors || !$sawSseData);
        if ($needsErrorEmission) {
            error_log('[MistralAdapter] Erreur Conversations API: ' . $fatalError);
            $onChunk('[Erreur Mistral : ' . $fatalError . ']');
        }

        if ($tokensIn === 0 && $tokensOut === 0 && !$hasDelta && $fatalError === null && !$retryWithFallback) {
            LlmAdapterHelpers::logEmptyStream('MistralAdapter/Conversations', [
                'http_status'     => $httpStatus ?? 'inconnu',
                'model'           => $this->model,
                'systemPromptLen' => strlen($systemPrompt),
                'messagesCount'   => count($messages),
            ]);
            $onChunk(LlmAdapterHelpers::buildEmptyStreamMessage('Mistral', $this->model, $httpStatus));
        }

        return [
            'input' => $tokensIn, 'output' => $tokensOut,
            'input_cached' => $tokensInCached,
            'input_cache_write' => 0,
            'output_reasoning' => 0,
            'web_searches' => $webSearches,
            'retry_with_fallback' => $retryWithFallback,
        ];
    }

    private function handleConversationSseEvent(
        string $eventName,
        string $data,
        callable $onChunk,
        int &$tokensIn,
        int &$tokensOut,
        int &$tokensInCached,
        int &$webSearches,
        bool &$hasDelta,
        ?string &$fatalError,
        bool $emitErrors
    ): bool {
        $data = trim($data);
        if ($data === '' || $data === '[DONE]') {
            return false;
        }

        $json = json_decode($data, true);
        if (!is_array($json)) {
            return false;
        }

        // Erreur au fil du flux : {error: {…}}, ou évènement `error` /
        // `conversation.response.error` ({message, code}). Ce dernier n'était
        // pas reconnu : le flux se terminait sans texte et passait pour vide.
        $type = (string)($json['type'] ?? $eventName);
        if (isset($json['error']) || in_array($type, ['error', 'conversation.response.error'], true)) {
            $fatalError = LlmAdapterHelpers::errorDetail($json);
            if ($emitErrors) {
                error_log('[MistralAdapter] Erreur Conversations SSE: ' . $fatalError);
                $onChunk('[Erreur Mistral : ' . $fatalError . ']');
            }
            return true;
        }

        if ($type === 'message.output.delta') {
            $delta = $this->extractConversationSseDelta($json);
            if ($delta !== '') {
                $this->emitAssistantChunk($onChunk, $delta);
                $hasDelta = true;
            }
        }

        // Fallback de compatibilité : certaines variantes peuvent n'envoyer que le message final.
        if (!$hasDelta && in_array($type, ['message.output', 'conversation.response'], true)) {
            $text = $this->extractConversationSseText($json);
            if ($text !== '') {
                $this->emitAssistantChunk($onChunk, $text);
                $hasDelta = true;
            }
        }

        if ($type === 'conversation.response.done') {
            $usage = [];
            if (isset($json['usage']) && is_array($json['usage'])) {
                $usage = $json['usage'];
            } elseif (isset($json['response']['usage']) && is_array($json['response']['usage'])) {
                $usage = $json['response']['usage'];
            }
            $relu = self::conversationUsage($usage);
            if ($relu !== null) {
                $tokensIn       = $relu['input'];
                $tokensOut      = $relu['output'];
                $tokensInCached = $relu['input_cached'];
                $webSearches    = $relu['web_searches'];
            }
            return true;
        }

        return false;
    }

    private function shouldFallbackToStandardWebSearch(string $errorMessage): bool
    {
        $msg = strtolower(trim($errorMessage));
        if ($msg === '') {
            return false;
        }

        return str_contains($msg, 'web_search_premium')
            || str_contains($msg, 'unknown tool')
            || str_contains($msg, 'invalid tool')
            || str_contains($msg, 'unsupported tool')
            || str_contains($msg, 'not support');
    }

    private function extractConversationSseDelta(array $event): string
    {
        if (isset($event['delta']) && is_array($event['delta'])) {
            return $this->extractConversationContentText($event['delta']);
        }
        if (isset($event['content']) && is_string($event['content'])) {
            return $event['content'];
        }
        if (isset($event['delta']) && is_string($event['delta'])) {
            return $event['delta'];
        }
        if (isset($event['text']) && is_string($event['text'])) {
            return $event['text'];
        }
        if (isset($event['content']) && is_array($event['content'])) {
            return $this->extractConversationContentText($event['content']);
        }
        return '';
    }

    private function extractConversationSseText(array $event): string
    {
        if (isset($event['content']) && is_string($event['content'])) {
            return trim($event['content']);
        }
        if (isset($event['content']) && is_array($event['content'])) {
            return trim($this->extractConversationContentText($event['content']));
        }
        if (isset($event['output']) && is_array($event['output'])) {
            return $this->extractConversationText(['outputs' => $event['output']]);
        }
        if (isset($event['outputs']) && is_array($event['outputs'])) {
            return $this->extractConversationText(['outputs' => $event['outputs']]);
        }
        if (isset($event['message']) && is_array($event['message'])) {
            $msg = $event['message'];
            if (isset($msg['content']) && is_array($msg['content'])) {
                return trim($this->extractConversationContentText($msg['content']));
            }
            if (isset($msg['content']) && is_string($msg['content'])) {
                return trim($msg['content']);
            }
        }
        return '';
    }

    private function extractConversationContentText(array $content): string
    {
        // Un objet « part » unique (tableau associatif : ex. {type:tool_reference,
        // url:..., title:...}) peut être passé directement plutôt qu'une liste de
        // parts. Sans ce garde-fou, le foreach ci-dessous itère ses VALEURS scalaires
        // (l'URL, le titre…) comme s'il s'agissait de parts texte → fuite de l'URL
        // brute et du titre dans la réponse. On le traite comme une part unique.
        if (!array_is_list($content)) {
            return $this->sanitizeAssistantText($this->extractConversationTextPart($content));
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $this->sanitizeAssistantText($part);
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $text = $this->extractConversationTextPart($part);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return $this->sanitizeAssistantText(implode('', $parts));
    }

    private function extractConversationText(array $response): string
    {
        $chunks = [];
        foreach (($response['outputs'] ?? []) as $entry) {
            $content = $entry['content'] ?? null;
            if (is_string($content)) {
                $chunks[] = $content;
                continue;
            }
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (is_string($part)) {
                    $chunks[] = $this->sanitizeAssistantText($part);
                    continue;
                }
                if (!is_array($part)) {
                    continue;
                }
                $text = $this->extractConversationTextPart($part);
                if ($text !== '') {
                    $chunks[] = $text;
                }
            }
        }

        return trim($this->sanitizeAssistantText(implode('', $chunks)));
    }

    /** Émet un fragment de réponse ; true si du texte est réellement parti. */
    private function emitAssistantChunk(callable $onChunk, string $chunk): bool
    {
        $clean = $this->sanitizeAssistantText($chunk);
        if ($clean === '') {
            return false;
        }
        $onChunk($clean);
        return true;
    }

    private function extractConversationTextPart(array $part): string
    {
        $type = strtolower((string)($part['type'] ?? ''));

        // Référence web (citation web_search) → lien hypertexte markdown propre
        // « [titre](url) » au lieu de laisser fuiter le titre puis l'URL brute.
        if ($type === 'tool_reference' || $type === 'reference' || isset($part['url'])) {
            return $this->formatReferenceLink($part);
        }

        if ($type !== '' && !$this->isConversationTextPartType($type)) {
            return '';
        }

        if (isset($part['text']) && is_string($part['text'])) {
            return $this->sanitizeAssistantText($part['text']);
        }
        if (isset($part['content']) && is_string($part['content'])) {
            return $this->sanitizeAssistantText($part['content']);
        }

        return '';
    }

    /**
     * Construit un lien markdown « [titre](url) » à partir d'une référence
     * web_search Mistral (chunk `tool_reference`). Le titre devient le texte
     * cliquable ; à défaut de titre on retombe sur le domaine. Retourne '' si
     * l'URL est absente ou non http(s).
     *
     * @param array<string,mixed> $part
     */
    private function formatReferenceLink(array $part): string
    {
        $url = trim((string)($part['url'] ?? ($part['source'] ?? '')));
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }

        $title = trim((string)($part['title'] ?? ($part['source_name'] ?? '')));
        if ($title === '') {
            $title = $this->referenceDomain($url);
        }
        // Neutralise les crochets qui casseraient la syntaxe du lien markdown.
        $title = str_replace(['[', ']'], ['(', ')'], $title);
        // Espaces et parenthèses dans l'URL → encodés (sinon markdown tronque le lien).
        $url = str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url);

        return ' [' . $title . '](' . $url . ')';
    }

    /** Domaine lisible d'une URL (sans « www. »), pour le texte du lien à défaut de titre. */
    private function referenceDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $url;
        }
        return preg_replace('/^www\./i', '', $host) ?? $host;
    }

    private function isConversationTextPartType(string $type): bool
    {
        return in_array($type, ['text', 'output_text', 'message_text'], true);
    }

    private function sanitizeAssistantText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Filet défensif : retire un token `tool_reference` qui aurait fuité en
        // texte. On NE retire PLUS « web_search » / « web_search_premium » : ces
        // tokens ne fuyaient que via l'itération erronée d'un objet référence
        // (corrigée dans extractConversationContentText), et les supprimer
        // mutilait la prose légitime (ex. un titre « How web search works »).
        $clean = str_ireplace('tool_reference', ' ', $text);
        // Désengluage d'une éventuelle URL nue collée à du texte (« le sitehttps://… »).
        // Le `(` est exclu pour ne pas casser la syntaxe d'un lien markdown [txt](https://…).
        $clean = preg_replace('/(?<=[^\s(])https?:\/\//i', ' $0', $clean) ?? $clean;

        return $clean;
    }

    /**
     * Convertit le format neutre → format Mistral (Chat Completions).
     * image/document: tente d'abord un upload Files API + URL signée, sinon fallback data URL base64.
     */
    private function formatMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            if (!is_array($msg['content'])) return $msg;

            $parts = [];
            foreach ($msg['content'] as $part) {
                if ($part['type'] === 'text') {
                    $parts[] = ['type' => 'text', 'text' => $part['text']];
                } elseif ($part['type'] === 'image') {
                    $imageUrl = $this->resolveMediaUrl($part);
                    if ($imageUrl === '') {
                        continue;
                    }
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => $imageUrl,
                    ];
                } elseif ($part['type'] === 'document') {
                    $documentUrl = $this->resolveMediaUrl($part);
                    if ($documentUrl === '') {
                        continue;
                    }
                    $parts[] = [
                        'type' => 'document_url',
                        'document_url' => $documentUrl,
                    ];
                    $name = trim((string)($part['name'] ?? ''));
                    if ($name !== '') {
                        $parts[count($parts) - 1]['document_name'] = $name;
                    }
                }
            }

            // Si une seule part texte, simplifier en string
            if (count($parts) === 1 && $parts[0]['type'] === 'text') {
                return ['role' => $msg['role'], 'content' => $parts[0]['text']];
            }
            return ['role' => $msg['role'], 'content' => $parts];
        }, $messages);
    }

    private function resolveMediaUrl(array $part): string
    {
        $mime = trim((string)($part['mime'] ?? ''));
        if ($mime === '') {
            return '';
        }

        $data = trim((string)($part['data'] ?? ''));
        if ($data === '') {
            return '';
        }

        $fallbackDataUrl = 'data:' . $mime . ';base64,' . $data;
        $signedUrl = $this->maybeUploadAndSignFile($part, $mime);
        return $signedUrl !== null && $signedUrl !== '' ? $signedUrl : $fallbackDataUrl;
    }

    private function maybeUploadAndSignFile(array $part, string $mime): ?string
    {
        if ($this->disableFilesApi) {
            return null;
        }

        $binary = $this->resolvePartBinary($part);
        if ($binary === null || $binary === '') {
            return null;
        }

        $cacheKey = $this->buildSignedUrlCacheKey($part, $mime, $binary);
        if (array_key_exists($cacheKey, $this->signedUrlCache)) {
            return $this->signedUrlCache[$cacheKey] !== '' ? $this->signedUrlCache[$cacheKey] : null;
        }

        $filename = $this->resolveUploadFilename($part, $mime);
        $fileId = $this->uploadBinaryFile($binary, $filename, $mime);
        if ($fileId === null) {
            $this->signedUrlCache[$cacheKey] = '';
            return null;
        }

        $signedUrl = $this->getSignedUrl($fileId);
        $this->signedUrlCache[$cacheKey] = $signedUrl ?? '';
        return $signedUrl;
    }

    private function resolvePartBinary(array $part): ?string
    {
        $path = trim((string)($part['path'] ?? ''));
        if ($path !== '' && is_readable($path)) {
            $content = @file_get_contents($path);
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        $data = trim((string)($part['data'] ?? ''));
        if ($data === '') {
            return null;
        }

        $decoded = base64_decode($data, true);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private function buildSignedUrlCacheKey(array $part, string $mime, string $binary): string
    {
        $path = trim((string)($part['path'] ?? ''));
        if ($path !== '' && is_readable($path)) {
            $size = @filesize($path);
            $mtime = @filemtime($path);
            return 'path:' . $path . ':' . (int)($size ?: 0) . ':' . (int)($mtime ?: 0);
        }

        return 'bin:' . $mime . ':' . sha1($binary);
    }

    private function resolveUploadFilename(array $part, string $mime): string
    {
        $name = trim((string)($part['name'] ?? ''));
        if ($name !== '') {
            return preg_replace('/[^\w.\- ]+/u', '_', $name) ?? $name;
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        return 'upload.' . $ext;
    }

    private function uploadBinaryFile(string $binary, string $filename, string $mime): ?string
    {
        $boundary = '----myllm-' . bin2hex(random_bytes(12));

        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="purpose"' . "\r\n\r\n";
        $body .= self::FILE_UPLOAD_PURPOSE . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $safeFilename = str_replace(['"', "\r", "\n"], ['_', '', ''], $filename);
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $safeFilename . '"' . "\r\n";
        $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
        $body .= $binary . "\r\n";
        $body .= '--' . $boundary . '--' . "\r\n";

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Accept: application/json',
            ]),
            'content'       => $body,
            'timeout'       => 45,
            'ignore_errors' => true,
        ]]);

        $res = @file_get_contents($this->filesUrl(), false, $ctx);
        $headers = $http_response_header ?? [];
        $statusCode = $this->extractHttpStatusCode($headers);

        if (!is_string($res) || $res === '') {
            error_log('[MistralAdapter] Upload fichier impossible (status=' . $statusCode . ')');
            $this->disableFilesApi = true;
            return null;
        }

        $json = json_decode($res, true);
        if (!is_array($json)) {
            error_log('[MistralAdapter] Upload fichier: réponse JSON invalide');
            $this->disableFilesApi = true;
            return null;
        }

        if (isset($json['error'])) {
            $msg = LlmAdapterHelpers::errorDetail($json);
            error_log('[MistralAdapter] Upload fichier: ' . $msg);
            $this->disableFilesApi = true;
            return null;
        }

        $fileId = trim((string)($json['id'] ?? ''));
        if ($fileId === '') {
            error_log('[MistralAdapter] Upload fichier: id absent');
            $this->disableFilesApi = true;
            return null;
        }

        return $fileId;
    }

    private function getSignedUrl(string $fileId): ?string
    {
        $url = $this->filesUrl() . '/' . rawurlencode($fileId) . '/url?expiry=' . self::FILE_SIGNED_URL_EXPIRY_HOURS;
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ]),
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);

        $res = @file_get_contents($url, false, $ctx);
        $headers = $http_response_header ?? [];
        $statusCode = $this->extractHttpStatusCode($headers);

        if (!is_string($res) || $res === '') {
            error_log('[MistralAdapter] URL signée indisponible (status=' . $statusCode . ')');
            return null;
        }

        $json = json_decode($res, true);
        if (!is_array($json)) {
            error_log('[MistralAdapter] URL signée: réponse JSON invalide');
            return null;
        }

        if (isset($json['error'])) {
            $msg = LlmAdapterHelpers::errorDetail($json);
            error_log('[MistralAdapter] URL signée: ' . $msg);
            return null;
        }

        $signedUrl = trim((string)($json['url'] ?? ''));
        return $signedUrl !== '' ? $signedUrl : null;
    }

    private function extractHttpStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', (string)$header, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }
}
