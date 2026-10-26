<?php

declare(strict_types=1);

/**
 * Adapter pour un LLM local avec API OpenAI-compatible (Ollama, LM Studio, Jan…).
 * URL de base et modèle configurés dans l'interface admin.
 */
class LocalAdapter implements LLMInterface
{
    use ChatCompletionsToolLoopTrait;

    /**
     * Le modèle servi sait-il appeler des outils ? Impossible à deviner (Ollama
     * sert aussi bien un Llama 3.2, qui le sait, qu'un Phi-3, qui ne le sait
     * pas) : c'est une case de l'administrateur, carte « LLM local » de la page
     * Clés API. Décochée par défaut — cocher un modèle incapable ferait
     * échouer chaque message qui porte un outil.
     */
    public const SETTING_TOOL_USE = 'local_tool_use';

    private string $apiUrl;
    private string $model;
    private string $apiKey;

    public function __construct()
    {
        $row            = ApiKey::findByProvider('local');
        $baseUrl        = rtrim($row['base_url'] ?? '', '/');
        $this->apiUrl   = $baseUrl . '/chat/completions';
        $this->model    = $row['model'] ?? 'llama3';
        $decrypted      = $row ? Encryption::decrypt($row['key_enc']) : '';
        $this->apiKey   = ($decrypted !== '' && $decrypted !== 'none') ? $decrypted : '';
    }

    public function getProviderName(): string { return 'local'; }

    /** Case « sait appeler des outils » de l'administrateur (cf. SETTING_TOOL_USE). */
    public static function toolUseEnabled(): bool
    {
        try {
            return Settings::get(self::SETTING_TOOL_USE, '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    // Boucle d'outils (ChatCompletionsToolLoopTrait) : pas de clé exigée, une
    // adresse configurée suffit.
    protected function toolLoopReady(): bool
    {
        return $this->apiUrl !== '' && $this->apiUrl !== '/chat/completions';
    }

    protected function toolLoopUrl(): string { return $this->apiUrl; }

    protected function toolLoopHeaders(): array
    {
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        return $headers;
    }

    protected function toolLoopLabel(): string { return 'LLM local'; }

    protected function toolLoopMessages(array $messages): array
    {
        return $this->formatMessages($messages);
    }

    protected function toolLoopTemperature(?float $temperature): ?float
    {
        return $temperature;
    }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if (empty($this->apiUrl) || $this->apiUrl === '/chat/completions') {
            error_log('[LocalAdapter] URL du LLM local non configurée');
            $onChunk('[Erreur : URL du LLM local non configurée]');
            return self::emptyTokens();
        }

        $payload = [
            'model'       => $this->model,
            'stream'      => true,
            'messages'    => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->formatMessages($messages)
            ),
        ];
        // NULL (assistant « réinitialisé ») → on n'envoie rien.
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[LocalAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur LLM local : requête invalide (encodage payload)]');
            return self::emptyTokens();
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $stream = @fopen($this->apiUrl, 'r', false, $ctx);
        if (!$stream) {
            error_log('[LocalAdapter] Impossible de contacter le LLM local à ' . $this->apiUrl);
            $onChunk('[Erreur : impossible de contacter le LLM local à ' . $this->apiUrl . ']');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[LocalAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('LLM local', $httpStatus, $errMsg));
            return self::emptyTokens();
        }

        $tokensIn  = 0;
        $tokensOut = 0;
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
                error_log('[LocalAdapter] Erreur : ' . $msg);
                $onChunk('[Erreur LLM local : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[LocalAdapter] Erreur SSE : ' . $errMsg);
                $onChunk('[Erreur LLM local : ' . $errMsg . ']');
                $errorSent = true;
                break;
            }

            $content = $json['choices'][0]['delta']['content'] ?? '';
            if ($content !== '') {
                $onChunk($content);
                $hasText = true;
                if (LlmAdapterHelpers::clientAborted()) {
                    break;
                }
            }

            $finish = LlmAdapterHelpers::chatFinishReason($json) ?? $finish;

            if (isset($json['usage'])) {
                $tokensIn  = $json['usage']['prompt_tokens']     ?? 0;
                $tokensOut = $json['usage']['completion_tokens'] ?? 0;
            }
        }

        fclose($stream);

        // Erreur restée en tampon, arrêt anormal du modèle (longueur, filtre…),
        // ou flux vide : une seule clôture pour tous les adaptateurs compatibles.
        $stop = LlmAdapterHelpers::concludeChatStream(
            ['provider' => 'LLM local', 'tag' => 'LocalAdapter', 'model' => $this->model, 'status' => $httpStatus, 'context' => [
                'url'             => $this->apiUrl,
                'systemPromptLen' => strlen($systemPrompt),
                'messagesCount'   => count($messages),
            ]],
            ['raw' => $rawBuffer, 'text' => $hasText, 'error' => $errorSent, 'finish' => $finish,
             'usage' => ((int)$tokensIn + (int)$tokensOut) > 0],
            $onChunk
        );

        return [
            'input' => $tokensIn, 'output' => $tokensOut,
            'input_cached' => 0,
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

    private function formatMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            if (!is_array($msg['content'])) return $msg;
            // Extraire uniquement le texte pour les LLMs locaux (pas de vision universelle)
            $text = '';
            foreach ($msg['content'] as $part) {
                if ($part['type'] === 'text') $text .= $part['text'];
            }
            return ['role' => $msg['role'], 'content' => $text];
        }, $messages);
    }
}
