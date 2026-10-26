<?php

declare(strict_types=1);

/**
 * Adapter Z.ai (GLM) — API OpenAI-compatible.
 * https://docs.z.ai/
 * glm-5.2 → GLM 5.2 (MoE 744B open-weights, contexte 1M, texte uniquement)
 */
class ZaiAdapter implements LLMInterface
{
    use ChatCompletionsToolLoopTrait;

    private const API_URL = 'https://api.z.ai/api/paas/v4/chat/completions';

    private string $apiKey;
    private string $model;

    public function __construct(?string $modelOverride = null)
    {
        $this->apiKey = ApiKey::getDecrypted('zai') ?? '';
        $this->model  = $modelOverride ?? LlmCatalog::defaultModel('zai');
    }

    public function getProviderName(): string { return 'zai'; }

    // Boucle d'outils (ChatCompletionsToolLoopTrait) : GLM n'accepte que
    // `tool_choice: auto`, déclaré au catalogue.
    protected function toolLoopUrl(): string
    {
        return LlmCatalog::endpoint('zai', 'chat_completions') ?? self::API_URL;
    }

    protected function toolLoopHeaders(): array
    {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
    }

    protected function toolLoopLabel(): string { return 'GLM'; }

    protected function toolLoopMessages(array $messages): array
    {
        return $this->formatMessages($messages);
    }

    protected function toolLoopTemperature(?float $temperature): ?float
    {
        // GLM accepte temperature ∈ [0, 1] (curseur de MIA sur l'échelle 0-2) :
        // borne lue au catalogue (`temperature_max`).
        return LlmCatalog::temperatureFor('zai', $this->model, $temperature);
    }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if ($this->apiKey === '') {
            error_log('[ZaiAdapter] Aucune clé API Z.ai configurée.');
            $onChunk('[Clé API GLM manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }

        $payload = [
            'model'    => $this->model,
            'stream'   => true,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->formatMessages($messages)
            ),
        ];

        // Source unique de vérité = catalogue. GLM accepte temperature ∈ [0, 1] :
        // la valeur du curseur (échelle OpenAI 0-2) est bornée à 1.
        $sendTemperature = LlmCatalog::temperatureFor('zai', $this->model, $temperature);
        if ($sendTemperature !== null) {
            $payload['temperature'] = $sendTemperature;
        }

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[ZaiAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur GLM : requête invalide (encodage payload)]');
            return self::emptyTokens();
        }

        $url = LlmCatalog::endpoint('zai', 'chat_completions') ?? self::API_URL;

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

        $stream = fopen($url, 'r', false, $ctx);
        if (!$stream) {
            error_log('[ZaiAdapter] Impossible d\'ouvrir le stream vers ' . $url);
            $onChunk('[Erreur : impossible de contacter GLM]');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[ZaiAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('GLM', $httpStatus, $errMsg));
            return self::emptyTokens();
        }

        $tokensIn  = 0;
        $tokensOut = 0;
        $tokensInCached     = 0;
        $tokensOutReasoning = 0;
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
                error_log('[ZaiAdapter] Erreur : ' . $msg);
                $onChunk('[Erreur GLM : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[ZaiAdapter] Erreur SSE : ' . $errMsg);
                $onChunk('[Erreur GLM : ' . $errMsg . ']');
                $errorSent = true;
                break;
            }

            // glm-5.2 peut émettre reasoning_content puis content — on ne stream que content
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
                $usage = $json['usage'];
                $tokensIn  = (int)($usage['prompt_tokens']     ?? 0);
                $tokensOut = (int)($usage['completion_tokens'] ?? 0);
                $tokensInCached = (int)(
                    $usage['prompt_tokens_details']['cached_tokens']
                    ?? $usage['cached_tokens']
                    ?? 0
                );
                $tokensOutReasoning = (int)(
                    $usage['completion_tokens_details']['reasoning_tokens']
                    ?? $usage['reasoning_tokens']
                    ?? 0
                );
            }
        }

        fclose($stream);

        // Erreur restée en tampon, arrêt anormal du modèle (longueur, filtre…),
        // ou flux vide : une seule clôture pour tous les adaptateurs compatibles.
        $stop = LlmAdapterHelpers::concludeChatStream(
            ['provider' => 'GLM', 'tag' => 'ZaiAdapter', 'model' => $this->model, 'status' => $httpStatus, 'context' => [
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
     * GLM 5.3 Flash lit les images (`image_url`, URL ou data URL base64) ;
     * GLM 5.3 et 5.2 sont texte seul : leurs parts multipart sont réduites au
     * texte, comme avant. Capacité lue au catalogue (tag `vision`).
     */
    private function formatMessages(array $messages): array
    {
        return LlmAdapterHelpers::chatContent($messages, LlmCatalog::acceptsImages('zai', $this->model));
    }
}
