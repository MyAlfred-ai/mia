<?php

declare(strict_types=1);

/**
 * Adapter Perplexity — API Sonar Chat Completions (OpenAI-compatible).
 * Tous les modèles sonar intègrent la recherche web nativement.
 *
 * Fin de support de Sonar Chat Completions le 27/09/2026 : les requêtes
 * synchrones et en flux (celles-ci) restent servies, reformulées par
 * Perplexity en requêtes Agent API, sans garantie d'exacte compatibilité.
 * Le portage sur l'Agent API (`/v1/agent`) est l'étape suivante — cf. le
 * bloc `perplexity` de config/llm_catalog.php.
 * https://docs.perplexity.ai/docs/agent-api/migrate-from-sonar/overview
 */
class PerplexityAdapter implements LLMInterface
{
    use ToolUseUnsupportedTrait;

    private const API_URL = 'https://api.perplexity.ai/chat/completions';

    private string $apiKey;
    private string $model;

    public function __construct(?string $modelOverride = null)
    {
        $this->apiKey = ApiKey::getDecrypted('perplexity') ?? '';
        $this->model  = $modelOverride ?? 'sonar-pro';
    }

    public function getProviderName(): string { return 'perplexity'; }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if ($this->apiKey === '') {
            error_log('[PerplexityAdapter] Aucune clé API Perplexity configurée.');
            $onChunk('[Clé API Perplexity manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }

        $payload = [
            'model'    => $this->model,
            'stream'   => true,
            'stream_mode' => 'full',
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->formatMessages($messages)
            ),
        ];

        // Source unique de vérité = catalogue. Le modèle de raisonnement
        // (sonar-reasoning-pro) y est marqué temperature=false. NULL (assistant
        // « réinitialisé ») → on n'envoie rien non plus.
        if ($temperature !== null && LlmCatalog::supportsTemperature('perplexity', $this->model)) {
            $payload['temperature'] = $temperature;
        }

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[PerplexityAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur Perplexity : requête invalide (encodage payload)]');
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

        // Adresse lue au catalogue, comme les autres adaptateurs (la constante
        // reste le repli) : un catalogue qui déclare l'endpoint doit être obéi.
        $url = LlmCatalog::endpoint('perplexity', 'chat_completions') ?? self::API_URL;
        $stream = fopen($url, 'r', false, $ctx);
        if (!$stream) {
            error_log('[PerplexityAdapter] Impossible d\'ouvrir le stream vers ' . $url);
            $onChunk('[Erreur : impossible de contacter Perplexity]');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[PerplexityAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('Perplexity', $httpStatus, $errMsg));
            return self::emptyTokens();
        }

        $tokensIn  = 0;
        $tokensOut = 0;
        $tokensInCached = 0;
        $rawBuffer = '';
        $hasText   = false; // du texte est-il parti vers l'appelant ?
        $errorSent = false; // une erreur a-t-elle déjà été émise ?
        $finish    = null;  // finish_reason du dernier évènement
        $citationUrls = [];
        $searchResultUrls = [];

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
                error_log('[PerplexityAdapter] Erreur : ' . $msg);
                $onChunk('[Erreur Perplexity : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') break;

            $json = json_decode($data, true);
            if (!$json) continue;

            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[PerplexityAdapter] Erreur SSE : ' . $errMsg);
                $onChunk('[Erreur Perplexity : ' . $errMsg . ']');
                $errorSent = true;
                break;
            }

            $citationUrls = $this->mergeCitationUrls($citationUrls, $this->extractTopLevelCitations($json));
            $searchResultUrls = $this->mergeCitationUrls($searchResultUrls, $this->extractTopLevelSearchResultUrls($json));

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
                $tokensInCached = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
            }
        }

        $referenceUrls = $citationUrls !== [] ? $citationUrls : $searchResultUrls;
        $referencesBlock = $this->buildReferenceDefinitionsMarkdown($referenceUrls);
        // Jamais de sources sans réponse : émises seules, elles passeraient
        // pour le début du texte et l'erreur qui suit ne serait plus présentée
        // comme telle.
        if ($referencesBlock !== '' && $hasText) {
            $onChunk($referencesBlock);
        }

        fclose($stream);

        // Erreur restée en tampon, arrêt anormal du modèle (longueur, filtre…),
        // ou flux vide : une seule clôture pour tous les adaptateurs compatibles.
        $stop = LlmAdapterHelpers::concludeChatStream(
            ['provider' => 'Perplexity', 'tag' => 'PerplexityAdapter', 'model' => $this->model, 'status' => $httpStatus, 'context' => [
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
            'output_reasoning' => 0,
            // Sonar facture UNE requête par réponse en plus des jetons (5 à
            // 14 $ les 1 000) : comptée comme une recherche, au tarif du
            // modèle (`web_search_pricing_usd_per_1k` du catalogue). Jamais
            // pour une réponse vide ou refusée d'emblée — rien n'a été servi.
            'web_searches' => self::billedRequests($hasText, $errorSent),
        ] + ($stop !== null ? ['stop' => $stop] : []);
    }

    /**
     * Requêtes Sonar facturées pour une réponse — pure. Une dès que du texte
     * est parti : la requête a été servie, et Perplexity la facture même si
     * le flux se coupe ensuite (elle n'était pas comptée quand une erreur
     * suivait le texte). Rien pour une réponse vide ou refusée d'emblée.
     */
    public static function billedRequests(bool $hasText, bool $errorSent): int
    {
        return $hasText ? 1 : 0;
    }

    private static function emptyTokens(): array
    {
        return [
            'input' => 0, 'output' => 0,
            'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractTopLevelCitations(array $payload): array
    {
        $urls = [];
        $raw = $payload['citations'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $normalized = $this->normalizeUrl($candidate);
            if ($normalized !== null && !in_array($normalized, $urls, true)) {
                $urls[] = $normalized;
            }
        }
        return $urls;
    }

    /**
     * @return array<int, string>
     */
    private function extractTopLevelSearchResultUrls(array $payload): array
    {
        $urls = [];
        $raw = $payload['search_results'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidate = $item['url'] ?? null;
            if (!is_string($candidate)) {
                continue;
            }
            $normalized = $this->normalizeUrl($candidate);
            if ($normalized !== null && !in_array($normalized, $urls, true)) {
                $urls[] = $normalized;
            }
        }
        return $urls;
    }

    private function normalizeUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $trimmed = rtrim($trimmed, ".,);]");
        if (!preg_match('/^https?:\/\//i', $trimmed)) {
            return null;
        }

        return filter_var($trimmed, FILTER_VALIDATE_URL) ? $trimmed : null;
    }

    /**
     * @param array<int, string> $existing
     * @param array<int, string> $incoming
     * @return array<int, string>
     */
    private function mergeCitationUrls(array $existing, array $incoming): array
    {
        foreach ($incoming as $url) {
            if (!in_array($url, $existing, true)) {
                $existing[] = $url;
            }
        }
        return $existing;
    }

    /**
     * @param array<int, string> $urls
     */
    private function buildReferenceDefinitionsMarkdown(array $urls): string
    {
        if ($urls === []) {
            return '';
        }

        $defs = [];
        $max = count($urls);
        for ($i = 0; $i < $max; $i++) {
            $index = $i + 1;
            $url = $urls[$i];
            $defs[] = '[' . $index . ']: ' . $url;
        }

        return "\n\n" . implode("\n", $defs) . "\n";
    }

    private function formatMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            $role = (string)($msg['role'] ?? 'user');
            if (!is_array($msg['content'])) {
                return ['role' => $role, 'content' => (string)($msg['content'] ?? '')];
            }

            $parts = [];
            foreach ($msg['content'] as $part) {
                if (is_string($part)) {
                    $txt = $part;
                    if ($txt !== '') {
                        $parts[] = ['type' => 'text', 'text' => $txt];
                    }
                    continue;
                }
                if (!is_array($part)) {
                    continue;
                }

                $type = (string)($part['type'] ?? '');
                if ($type === 'text') {
                    $txt = (string)($part['text'] ?? '');
                    if ($txt !== '') {
                        $parts[] = ['type' => 'text', 'text' => $txt];
                    }
                    continue;
                }

                if ($type === 'image') {
                    $mime = (string)($part['mime'] ?? 'image/jpeg');
                    $b64 = $this->extractBase64Payload((string)($part['data'] ?? ''));
                    if ($b64 !== '' && str_starts_with($mime, 'image/')) {
                        $parts[] = [
                            'type' => 'image_url',
                            'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64],
                        ];
                    }
                    continue;
                }

                if ($type === 'document') {
                    $b64 = $this->extractBase64Payload((string)($part['data'] ?? ''));
                    if ($b64 !== '') {
                        $parts[] = [
                            'type' => 'file_url',
                            'file_url' => ['url' => $b64],
                        ];
                    }
                }
            }

            if ($parts === []) {
                return ['role' => $role, 'content' => ''];
            }

            if (count($parts) === 1 && ($parts[0]['type'] ?? '') === 'text') {
                return ['role' => $role, 'content' => $parts[0]['text']];
            }

            return ['role' => $role, 'content' => $parts];
        }, $messages);
    }

    private function extractBase64Payload(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        $pos = stripos($raw, ';base64,');
        if ($pos !== false) {
            return substr($raw, $pos + 8);
        }

        return $raw;
    }
}
