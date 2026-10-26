<?php

declare(strict_types=1);

/**
 * Adapter Moonshot (Kimi) — API OpenAI-compatible.
 * https://platform.moonshot.ai/
 * kimi-k3 → Kimi K3 (MoE 2,8T, raisonnement natif, vision base64, pas de temperature)
 */
class MoonshotAdapter implements LLMInterface
{
    use ChatCompletionsToolLoopTrait;

    private const API_URL = 'https://api.moonshot.ai/v1/chat/completions';

    /**
     * Kimi ne rapporte l'usage d'un flux que sur demande (« If you need token
     * usage in a streaming response, also pass stream_options: {include_usage:
     * true} », guide Streaming). Sans lui, l'usage n'arrive au mieux que
     * dans le choix de la dernière tranche — que le chat simple ne lisait
     * pas : une réponse de Kimi pouvait ne laisser aucune ligne au grand livre.
     */
    private const STREAM_USAGE = ['stream_options' => ['include_usage' => true]];

    private string $apiKey;
    private string $model;

    public function __construct(?string $modelOverride = null)
    {
        $this->apiKey = ApiKey::getDecrypted('moonshot') ?? '';
        $this->model  = $modelOverride ?? LlmCatalog::defaultModel('moonshot');
    }

    public function getProviderName(): string { return 'moonshot'; }

    // Boucle d'outils (ChatCompletionsToolLoopTrait). Kimi K3 raisonne
    // toujours : le dialecte du catalogue fait rendre son raisonnement sur
    // chaque appel d'outil rejoué.
    protected function toolLoopPayloadExtras(): array
    {
        return self::STREAM_USAGE;
    }

    protected function toolLoopUrl(): string
    {
        return LlmCatalog::endpoint('moonshot', 'chat_completions') ?? self::API_URL;
    }

    protected function toolLoopHeaders(): array
    {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
    }

    protected function toolLoopLabel(): string { return 'Kimi'; }

    protected function toolLoopMessages(array $messages): array
    {
        return $this->formatMessages($messages);
    }

    protected function toolLoopTemperature(?float $temperature): ?float
    {
        return ($temperature !== null && LlmCatalog::supportsTemperature('moonshot', $this->model)) ? $temperature : null;
    }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if ($this->apiKey === '') {
            error_log('[MoonshotAdapter] Aucune clé API Moonshot configurée.');
            $onChunk('[Clé API Kimi manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }

        $payload = [
            'model'    => $this->model,
            'stream'   => true,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->formatMessages($messages)
            ),
        ] + self::STREAM_USAGE;

        // Source unique de vérité = catalogue (kimi-k3 y est marqué
        // temperature=false : échantillonnage figé côté API).
        if ($temperature !== null && LlmCatalog::supportsTemperature('moonshot', $this->model)) {
            $payload['temperature'] = $temperature;
        }

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[MoonshotAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur Kimi : requête invalide (encodage payload)]');
            return self::emptyTokens();
        }

        $url = LlmCatalog::endpoint('moonshot', 'chat_completions') ?? self::API_URL;

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ]),
            'content'       => $body,
            'timeout'       => 180, // raisonnement toujours actif → premières réponses lentes
            'ignore_errors' => true,
        ]]);

        $stream = fopen($url, 'r', false, $ctx);
        if (!$stream) {
            error_log('[MoonshotAdapter] Impossible d\'ouvrir le stream vers ' . $url);
            $onChunk('[Erreur : impossible de contacter Kimi]');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[MoonshotAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('Kimi', $httpStatus, $errMsg));
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
                error_log('[MoonshotAdapter] Erreur : ' . $msg);
                $onChunk('[Erreur Kimi : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[MoonshotAdapter] Erreur SSE : ' . $errMsg);
                $onChunk('[Erreur Kimi : ' . $errMsg . ']');
                $errorSent = true;
                break;
            }

            // kimi-k3 émet reasoning_content puis content — on ne stream que content
            $content = $json['choices'][0]['delta']['content'] ?? '';
            if ($content !== '') {
                $onChunk($content);
                $hasText = true;
                if (LlmAdapterHelpers::clientAborted()) {
                    break;
                }
            }

            $finish = LlmAdapterHelpers::chatFinishReason($json) ?? $finish;

            // Usage final : à la racine (« include_usage »), ou dans le choix
            // de la dernière tranche (forme historique de Moonshot).
            $usage = $json['usage'] ?? $json['choices'][0]['usage'] ?? null;
            if (is_array($usage)) {
                $tokensIn  = (int)($usage['prompt_tokens']     ?? 0);
                $tokensOut = (int)($usage['completion_tokens'] ?? 0);
                // Moonshot expose les tokens lus depuis le cache façon OpenAI
                // (prompt_tokens_details.cached_tokens) ou cached_tokens direct.
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
            ['provider' => 'Kimi', 'tag' => 'MoonshotAdapter', 'model' => $this->model, 'status' => $httpStatus, 'context' => [
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
     * Format neutre → format OpenAI chat/completions.
     * Kimi K3 accepte les images en data URL base64 (pas d'URL publique) ;
     * les autres types de parts sont réduits au texte.
     */
    private function formatMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            if (!is_array($msg['content'])) return $msg;

            $parts = [];
            foreach ($msg['content'] as $part) {
                if (!is_array($part)) continue;
                $type = (string)($part['type'] ?? '');

                if ($type === 'text') {
                    $txt = (string)($part['text'] ?? '');
                    if ($txt !== '') $parts[] = ['type' => 'text', 'text' => $txt];
                    continue;
                }

                if ($type === 'image') {
                    $mime = (string)($part['mime'] ?? 'image/jpeg');
                    $b64  = preg_replace('/\s+/', '', (string)($part['data'] ?? '')) ?? '';
                    if ($b64 !== '' && str_starts_with($mime, 'image/')) {
                        $parts[] = [
                            'type'      => 'image_url',
                            'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64],
                        ];
                    }
                }
            }

            if ($parts === []) {
                return ['role' => $msg['role'], 'content' => ''];
            }
            if (count($parts) === 1 && ($parts[0]['type'] ?? '') === 'text') {
                return ['role' => $msg['role'], 'content' => $parts[0]['text']];
            }
            return ['role' => $msg['role'], 'content' => $parts];
        }, $messages);
    }
}
