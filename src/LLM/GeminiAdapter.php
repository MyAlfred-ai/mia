<?php

declare(strict_types=1);

/**
 * Adapter Google Gemini via l'API native GenerateContent.
 * https://ai.google.dev/gemini-api/docs/text-generation
 */
class GeminiAdapter implements LLMInterface
{
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * La recherche Google a été refusée À CÔTÉ des outils pendant ce process
     * (400 qui la vise) : les boucles suivantes partent sans, et le disent au
     * modèle, sans payer un 400 par message. Statique (worker long-vivant).
     */
    private static bool $searchWithToolsRejected = false;

    private string $apiKey;
    private string $model;

    public function __construct(?string $modelOverride = null)
    {
        $this->apiKey = ApiKey::getDecrypted('gemini') ?? '';
        $this->model  = $modelOverride ?? ApiKey::getModel('gemini') ?? LlmCatalog::defaultModel('gemini');
    }

    public function getProviderName(): string { return 'gemini'; }

    public function stream(array $messages, string $systemPrompt, callable $onChunk, ?float $temperature = null, bool $webSearch = false): array
    {
        if ($this->apiKey === '') {
            error_log('[GeminiAdapter] Aucune clé API Gemini configurée.');
            $onChunk('[Clé API Gemini manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]');
            return self::emptyTokens();
        }

        $payload = $this->buildPayload($messages, $systemPrompt, $temperature, $webSearch);

        $url = (LlmCatalog::endpoint('gemini', 'base') ?? self::API_BASE_URL)
            . rawurlencode($this->model) . ':streamGenerateContent?alt=sse';

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[GeminiAdapter] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur Gemini : requête invalide (encodage payload)]');
            return self::emptyTokens();
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ]),
            'content'       => $body,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);

        $stream = fopen($url, 'r', false, $ctx);
        if (!$stream) {
            error_log('[GeminiAdapter] Impossible d\'ouvrir le stream vers ' . $url);
            $onChunk('[Erreur : impossible de contacter Gemini]');
            return self::emptyTokens();
        }
        stream_set_read_buffer($stream, 0);

        $httpStatus = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            // Détail complet : chez Google, c'est `status` (RESOURCE_EXHAUSTED,
            // UNAVAILABLE…) et l'identifiant du quota touché qui disent la cause —
            // le message d'un 429 est le même pour une limite à la minute et
            // pour un quota journalier épuisé.
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[GeminiAdapter] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('Gemini', $httpStatus, $errMsg));
            return self::emptyTokens();
        }

        $usage = [];
        $searchQueries = [];
        $rawBuffer = '';
        $emittedText = '';
        $errorSent = false; // une erreur a-t-elle déjà été émise ?
        $finish    = null;  // finishReason du candidat, ou blockReason de la demande

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
                error_log('[GeminiAdapter] Erreur : ' . $msg);
                $onChunk('[Erreur Gemini : ' . $msg . ']');
                fclose($stream);
                return self::emptyTokens();
            }

            $data = trim(substr($line, 6));
            if ($data === '' || $data === '[DONE]') continue;

            $json = json_decode($data, true);
            if (!$json) continue;

            $events = (isset($json[0]) && is_array($json[0])) ? $json : [$json];
            foreach ($events as $event) {
                if (!is_array($event)) continue;

                if (isset($event['error'])) {
                    $errMsg = LlmAdapterHelpers::errorDetail($event);
                    error_log('[GeminiAdapter] Erreur SSE : ' . $errMsg);
                    $onChunk('[Erreur Gemini : ' . $errMsg . ']');
                    fclose($stream);
                    return self::emptyTokens();
                }

                $chunkText = $this->extractTextFromGeminiChunk($event);
                if ($chunkText !== '') {
                    $delta = $chunkText;
                    if ($emittedText !== '' && str_starts_with($chunkText, $emittedText)) {
                        $delta = substr($chunkText, strlen($emittedText));
                        $emittedText = $chunkText;
                    } elseif ($emittedText !== '' && str_starts_with($emittedText, $chunkText)) {
                        $delta = '';
                    } else {
                        $emittedText .= $chunkText;
                    }
                    if ($delta !== '') {
                        $onChunk($delta);
                        if (LlmAdapterHelpers::clientAborted()) {
                            break 2;
                        }
                    }
                }

                // Arrêt du modèle : `finishReason` du candidat (MAX_TOKENS,
                // SAFETY…), ou `promptFeedback.blockReason` quand la DEMANDE est
                // bloquée d'emblée — il n'y a alors aucun candidat, donc aucun
                // texte, et l'utilisateur lisait « réponse vide ».
                $blocked = $event['promptFeedback']['blockReason'] ?? null;
                if (is_string($blocked) && $blocked !== '') {
                    $finish = $blocked;
                }
                $reason = $event['candidates'][0]['finishReason'] ?? null;
                if (is_string($reason) && $reason !== '') {
                    $finish = $reason;
                }

                if (isset($event['usageMetadata']) && is_array($event['usageMetadata'])) {
                    // Dernière valeur connue de chaque compteur (le dernier
                    // évènement porte les totaux).
                    $usage = array_merge($usage, $event['usageMetadata']);
                }
                // Grounding Google Search : les requêtes lancées pour cette
                // réponse. Un même bloc de métadonnées peut revenir dans
                // plusieurs évènements — dédoublonnées par leur texte.
                $queries = $event['candidates'][0]['groundingMetadata']['webSearchQueries'] ?? [];
                foreach (is_array($queries) ? $queries : [] as $query) {
                    if (is_string($query) && trim($query) !== '') {
                        $searchQueries[trim($query)] = true;
                    }
                }
            }
        }

        $tokens = self::tokensFromUsage($usage);

        fclose($stream);

        // Erreur restée en tampon, arrêt anormal (plafond de sortie atteint —
        // la réflexion seule peut l'épuiser —, filtre de sécurité, demande
        // bloquée), ou flux vide : clôture commune aux adaptateurs.
        $stop = LlmAdapterHelpers::concludeChatStream(
            ['provider' => 'Gemini', 'tag' => 'GeminiAdapter', 'model' => $this->model, 'status' => $httpStatus, 'context' => [
                'systemPromptLen' => strlen($systemPrompt),
                'messagesCount'   => count($messages),
            ]],
            ['raw' => $rawBuffer, 'text' => $emittedText !== '', 'error' => $errorSent, 'finish' => $finish,
             'usage' => ($tokens['input'] + $tokens['output']) > 0],
            $onChunk
        );

        return $tokens + [
            'web_searches' => self::billedWebSearches($this->model, count($searchQueries)),
        ] + ($stop !== null ? ['stop' => $stop] : []);
    }

    // ============================================================
    // Function-calling (functionDeclarations)
    // ============================================================

    public function supportsToolUse(): bool
    {
        return $this->apiKey !== '' && LlmCatalog::supportsToolUse('gemini', $this->model);
    }

    /**
     * Boucle d'outils native Gemini. Même contrat que les autres boucles :
     * budget relu à chaque aller, outils relus (`tools_source`), synthèse
     * forcée au dernier (`functionCallingConfig.mode = NONE`), chaque appel
     * passé au dispatch unique de l'appelant.
     *
     * Trois règles de Google, toutes vérifiées dans sa documentation :
     *   - le tour du modèle est RENDU TEL QUEL au tour suivant : les
     *     `thoughtSignature` doivent revenir « in the exact part where it was
     *     received », et Gemini 3 refuse en 400 un appel de fonction sans la
     *     sienne (« the first functionCall part in each step of the current
     *     turn must include its thought_signature ») ;
     *   - chaque résultat porte l'`id` de son appel quand l'API en donne un ;
     *   - Gemini 3 cumule recherche Google et outils dans un même objet `tools`
     *     (`includeServerSideToolInvocations`) ; les parties des outils serveur
     *     reviennent, elles aussi, telles quelles.
     */
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
            return $this->stream($messages, $systemPrompt, $onChunk, $temperature, $webSearch);
        }

        $contents     = $this->formatMessages($messages);
        $declarations = self::functionDeclarations($tools);
        $withSearch   = $webSearch && !self::$searchWithToolsRejected
            && LlmCatalog::supportsWebSearchWithTools('gemini', $this->model);
        if ($webSearch && !$withSearch) {
            error_log('[GeminiAdapter/Outils] recherche Google impossible avec les outils sur ' . $this->model . ', réponse sans recherche.');
            $systemPrompt .= "\n\n" . LlmAdapterHelpers::webSearchWithToolsUnavailableNote();
        }

        $tokens = ['input' => 0, 'output' => 0, 'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0];
        $rounds = [];         // usage de chaque aller (palier de contexte)
        $billedSearches = 0;  // recherches facturées, aller par aller
        $textSent = false;
        $stop = null;
        $forcedSynthesis = false;
        $malformedRetried = false;

        for ($round = 0; $round < LlmAdapterHelpers::roundsBudget($options); $round++) {
            if (is_callable($options['tools_source'] ?? null)) {
                $declarations = self::functionDeclarations((array)($options['tools_source'])());
            }
            $lastRound = $round === LlmAdapterHelpers::roundsBudget($options) - 1;
            $forcedSynthesis = $forcedSynthesis || $lastRound;

            $payload = $this->toolPayload($contents, $systemPrompt, $temperature, $declarations, $withSearch, $lastRound);
            $res = $this->geminiToolRound($payload, $onChunk, $withSearch);
            if (!empty($res['retry_without_search'])) {
                // Recherche refusée à côté des outils : apprise pour le process,
                // même demande sans elle — et le modèle le sait.
                self::$searchWithToolsRejected = true;
                $withSearch = false;
                $systemPrompt .= "\n\n" . LlmAdapterHelpers::webSearchWithToolsUnavailableNote();
                $payload = $this->toolPayload($contents, $systemPrompt, $temperature, $declarations, false, $lastRound);
                $res = $this->geminiToolRound($payload, $onChunk, false);
            }
            foreach ($tokens as $k => $v) {
                $tokens[$k] = $v + (int)($res['tokens'][$k] ?? 0);
            }
            // Chaque aller est une requête : facturé selon SON palier de
            // contexte (cf. CreditService::longContextPart) et pour SES
            // recherches — la même requête Google relancée à l'aller suivant
            // est facturée deux fois, une réponse ancrée de 2.5 aussi.
            if (is_array($res['tokens'] ?? null) && $res['tokens'] !== []) {
                $rounds[] = $res['tokens'];
            }
            $billedSearches += self::billedWebSearches($this->model, count(array_unique($res['queries'])));
            $textSent = $textSent || $res['text'] !== '';

            // Appel mal formé par le modèle (`MALFORMED_FUNCTION_CALL`, sans
            // texte ni appel exploitable) : une seconde chance, une seule.
            if (!$res['error'] && $res['calls'] === [] && $res['text'] === '' && !$lastRound && !$malformedRetried
                && strtoupper((string)$res['finish']) === 'MALFORMED_FUNCTION_CALL') {
                $malformedRetried = true;
                error_log('[GeminiAdapter/Outils] appel de fonction mal formé par ' . $this->model . ', nouvel essai.');
                continue;
            }

            if ($res['error'] || $res['calls'] === [] || $lastRound) {
                if (!$res['error']) {
                    $stop = LlmAdapterHelpers::concludeStop('Gemini', $res['finish'], $textSent, false, $onChunk);
                }
                break;
            }

            $contents[] = ['role' => 'model', 'parts' => $res['parts']];
            $responses = [];
            foreach ($res['calls'] as $call) {
                // Arrêt demandé (Stop) : plus aucun appel — ni lecture, ni
                // carte d'approbation — sur une réponse coupée. Le contrôle
                // ne venait qu'APRÈS les outils, et le flux ne le fait qu'une
                // fois du texte écrit : un arrêt pendant qu'arrivait un appel
                // de fonction laissait partir tout le tour.
                if (LlmAdapterHelpers::clientAborted()) {
                    break 2;
                }
                $result = $onToolCall($call['name'], $call['args']);
                $response = ['name' => $call['name'], 'response' => self::functionResponseBody($result)];
                if ($call['id'] !== '') {
                    $response['id'] = $call['id'];
                }
                $responses[] = ['functionResponse' => $response];
            }
            $contents[] = ['role' => 'user', 'parts' => $responses];

            if (LlmAdapterHelpers::clientAborted()) {
                break;
            }
        }

        return $tokens
            + ['web_searches' => $billedSearches, 'rounds' => $rounds]
            + ($stop !== null ? ['stop' => $stop] : [])
            + ($forcedSynthesis ? ['rounds_exhausted' => true] : []);
    }

    /**
     * Corps d'un aller de la boucle d'outils.
     *
     * @param list<array> $contents
     * @param list<array> $declarations
     * @return array<string, mixed>
     */
    private function toolPayload(array $contents, string $systemPrompt, ?float $temperature, array $declarations, bool $withSearch, bool $lastRound): array
    {
        $payload = ['contents' => $contents];
        if ($systemPrompt !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }
        if ($temperature !== null && LlmCatalog::supportsTemperature('gemini', $this->model)) {
            $payload['generationConfig'] = ['temperature' => $temperature];
        }
        $tool = [];
        if ($declarations !== []) {
            $tool['functionDeclarations'] = $declarations;
        }
        if ($withSearch) {
            $tool['googleSearch'] = (object)[];
        }
        if ($tool !== []) {
            $payload['tools'] = [$tool];
        }
        $toolConfig = [];
        if ($declarations !== [] && $lastRound) {
            $toolConfig['functionCallingConfig'] = ['mode' => 'NONE'];
        }
        if ($declarations !== [] && $withSearch) {
            $toolConfig['includeServerSideToolInvocations'] = true;
        }
        if ($toolConfig !== []) {
            $payload['toolConfig'] = $toolConfig;
        }
        return $payload;
    }

    /**
     * Ouvre le flux d'un aller. Point de substitution des tests.
     *
     * @return array{0: resource|false, 1: ?int}
     */
    protected function openToolRoundStream(string $body): array
    {
        $url = (LlmCatalog::endpoint('gemini', 'base') ?? self::API_BASE_URL)
            . rawurlencode($this->model) . ':streamGenerateContent?alt=sse';
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey]),
            'content'       => $body,
            'timeout'       => 180,
            'ignore_errors' => true,
        ]]);
        $stream = @fopen($url, 'r', false, $ctx);
        return [$stream, LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null)];
    }

    /**
     * Un aller : texte émis au fil de l'eau, parties du modèle gardées TELLES
     * QUELLES pour être rendues (signatures, outils serveur), appels relevés.
     *
     * @return array{text:string, parts:list<array>, calls:list<array{name:string,args:array,id:string}>,
     *               tokens:array<string,int>, queries:list<string>, finish:?string, error:bool, retry_without_search?:bool}
     */
    private function geminiToolRound(array $payload, callable $onChunk, bool $withSearch): array
    {
        $fail = static fn(): array => ['text' => '', 'parts' => [], 'calls' => [], 'tokens' => [], 'queries' => [],
            'finish' => null, 'error' => true];

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[GeminiAdapter/Outils] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur Gemini : requête invalide (encodage payload)]');
            return $fail();
        }
        [$stream, $httpStatus] = $this->openToolRoundStream($body);
        if (!$stream) {
            error_log('[GeminiAdapter/Outils] Impossible d\'ouvrir le stream (' . $this->model . ').');
            $onChunk('[Erreur : impossible de contacter Gemini]');
            return $fail();
        }
        stream_set_read_buffer($stream, 0);

        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[GeminiAdapter/Outils] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            if ($withSearch && self::isSearchWithToolsRejection($httpStatus, $errMsg)) {
                return ['retry_without_search' => true] + $fail();
            }
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage('Gemini', $httpStatus, $errMsg));
            return $fail();
        }

        $text = '';
        $parts = [];
        $calls = [];
        $usage = [];
        $queries = [];
        $rawBuffer = '';
        $finish = null;

        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                continue;
            }
            if (!str_starts_with($line, 'data: ')) {
                if (LlmAdapterHelpers::isSseNoise($line)) {
                    continue;
                }
                $rawBuffer .= $line;
                continue;
            }
            if ($rawBuffer !== '') {
                $msg = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
                error_log('[GeminiAdapter/Outils] Erreur : ' . $msg);
                $onChunk('[Erreur Gemini : ' . $msg . ']');
                fclose($stream);
                return $fail();
            }
            $data = trim(substr($line, 6));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            $json = json_decode($data, true);
            if (!is_array($json)) {
                continue;
            }
            $events = (isset($json[0]) && is_array($json[0])) ? $json : [$json];
            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }
                if (isset($event['error'])) {
                    $errMsg = LlmAdapterHelpers::errorDetail($event);
                    error_log('[GeminiAdapter/Outils] Erreur SSE : ' . $errMsg);
                    $onChunk('[Erreur Gemini : ' . $errMsg . ']');
                    fclose($stream);
                    return $fail();
                }
                foreach ((array)($event['candidates'][0]['content']['parts'] ?? []) as $part) {
                    if (!is_array($part)) {
                        continue;
                    }
                    if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                        $fc = $part['functionCall'];
                        $calls[] = [
                            'name' => (string)($fc['name'] ?? ''),
                            'args' => is_array($fc['args'] ?? null) ? $fc['args'] : [],
                            'id'   => (string)($fc['id'] ?? ''),
                        ];
                        $parts[] = $part;
                        continue;
                    }
                    if (isset($part['text']) && is_string($part['text']) && empty($part['thought'])) {
                        if ($part['text'] !== '') {
                            $onChunk($part['text']);
                            $text .= $part['text'];
                        }
                        $parts = self::appendReplayPart($parts, $part);
                        continue;
                    }
                    // Raisonnement, outils serveur, signature seule : verbatim.
                    $parts[] = $part;
                }
                if ($text !== '' && LlmAdapterHelpers::clientAborted()) {
                    break 2;
                }
                $blocked = $event['promptFeedback']['blockReason'] ?? null;
                if (is_string($blocked) && $blocked !== '') {
                    $finish = $blocked;
                }
                $reason = $event['candidates'][0]['finishReason'] ?? null;
                if (is_string($reason) && $reason !== '') {
                    $finish = $reason;
                }
                if (isset($event['usageMetadata']) && is_array($event['usageMetadata'])) {
                    $usage = array_merge($usage, $event['usageMetadata']);
                }
                foreach ((array)($event['candidates'][0]['groundingMetadata']['webSearchQueries'] ?? []) as $query) {
                    if (is_string($query) && trim($query) !== '') {
                        $queries[trim($query)] = trim($query);
                    }
                }
            }
        }
        fclose($stream);

        if ($rawBuffer !== '' && $text === '' && $calls === []) {
            $msg = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
            error_log('[GeminiAdapter/Outils] Erreur (fin de flux) : ' . $msg);
            $onChunk('[Erreur Gemini : ' . $msg . ']');
            return $fail();
        }

        return [
            'text'    => $text,
            'parts'   => $parts,
            'calls'   => array_values(array_filter($calls, static fn(array $c): bool => $c['name'] !== '')),
            'tokens'  => self::tokensFromUsage($usage),
            'queries' => array_values($queries),
            'finish'  => $finish,
            'error'   => false,
        ];
    }

    /**
     * Ajoute une partie texte au tour à rendre. Pure. Les fragments de texte
     * successifs sont fusionnés — sauf une partie qui porte une signature (ou
     * quoi que ce soit d'autre que du texte), rendue telle quelle à sa place.
     *
     * @param list<array> $parts
     * @return list<array>
     */
    public static function appendReplayPart(array $parts, array $part): array
    {
        $last = $parts === [] ? null : $parts[count($parts) - 1];
        if (is_array($last) && array_keys($last) === ['text'] && array_keys($part) === ['text']) {
            $parts[count($parts) - 1]['text'] .= $part['text'];
            return $parts;
        }
        if (array_keys($part) === ['text'] && $part['text'] === '') {
            return $parts;
        }
        $parts[] = $part;
        return $parts;
    }

    /**
     * Outils agnostiques → `functionDeclarations`. Pure. Un outil sans
     * paramètre n'en déclare aucun (un objet sans propriétés est refusé).
     *
     * @param list<array> $tools
     * @return list<array>
     */
    public static function functionDeclarations(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $decl = [
                'name'        => (string)($t['name'] ?? ''),
                'description' => (string)($t['description'] ?? ''),
            ];
            if (isset($t['parameters'])) {
                $schema = self::geminiSchema($t['parameters'], true);
                if (!empty($schema['properties'])) {
                    $decl['parameters'] = $schema;
                }
            }
            $out[] = $decl;
        }
        return $out;
    }

    /**
     * Schéma JSON des outils → sous-ensemble OpenAPI accepté par Gemini. Pure.
     *
     * Gemini refuse ce qu'il ne connaît pas (`additionalProperties`, `$schema`,
     * un `type` en liste, une énumération non textuelle, un objet sans
     * propriétés). On ne garde que les mots-clés documentés, on traduit
     * `["string", "null"]` en `nullable`, et un objet IMBRIQUÉ sans propriétés
     * devient un texte JSON — les gestionnaires concernés acceptent les deux
     * formes (cf. N8nService::normalizePayload).
     *
     * @return array<string, mixed>
     */
    public static function geminiSchema(mixed $schema, bool $topLevel = false): array
    {
        if ($schema instanceof \stdClass) {
            $schema = get_object_vars($schema);
        }
        if (!is_array($schema)) {
            return ['type' => 'string'];
        }

        $out = [];
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $nonNull = array_values(array_filter($type, static fn($t): bool => $t !== 'null'));
            if (count($nonNull) < count($type)) {
                $out['nullable'] = true;
            }
            $type = $nonNull[0] ?? 'string';
        }
        $type = is_string($type) ? strtolower($type) : (isset($schema['properties']) ? 'object' : 'string');

        $description = is_string($schema['description'] ?? null) ? $schema['description'] : '';
        if (!empty($schema['nullable'])) {
            $out['nullable'] = true;
        }

        if ($type === 'object') {
            $props = $schema['properties'] ?? [];
            if ($props instanceof \stdClass) {
                $props = get_object_vars($props);
            }
            $props = is_array($props) ? $props : [];
            if ($props === [] && !$topLevel) {
                // Objet libre imbriqué : refusé par Gemini, passé en texte JSON.
                return ['type' => 'string', 'description' => trim($description . ' (objet JSON)')]
                    + (isset($out['nullable']) ? ['nullable' => true] : []);
            }
            $out = ['type' => 'object'] + $out;
            if ($description !== '') {
                $out['description'] = $description;
            }
            if ($props !== []) {
                $mapped = [];
                foreach ($props as $name => $propSchema) {
                    $mapped[(string)$name] = self::geminiSchema($propSchema);
                }
                $out['properties'] = $mapped;
                $required = array_values(array_filter(
                    (array)($schema['required'] ?? []),
                    static fn($r): bool => is_string($r) && array_key_exists($r, $mapped)
                ));
                if ($required !== []) {
                    $out['required'] = $required;
                }
            }
            return $out;
        }

        $out = ['type' => in_array($type, ['string', 'number', 'integer', 'boolean', 'array'], true) ? $type : 'string'] + $out;
        if ($description !== '') {
            $out['description'] = $description;
        }
        if ($out['type'] === 'array') {
            $out['items'] = isset($schema['items']) ? self::geminiSchema($schema['items']) : ['type' => 'string'];
            foreach (['minItems', 'maxItems'] as $k) {
                if (isset($schema[$k]) && is_numeric($schema[$k])) {
                    $out[$k] = (int)$schema[$k];
                }
            }
        }
        if ($out['type'] === 'string' && isset($schema['enum']) && is_array($schema['enum'])) {
            $enum = array_values(array_map('strval', array_filter($schema['enum'], 'is_scalar')));
            if ($enum !== []) {
                $out['enum'] = $enum;
            }
        }
        if (in_array($out['type'], ['number', 'integer'], true)) {
            foreach (['minimum', 'maximum'] as $k) {
                if (isset($schema[$k]) && is_numeric($schema[$k])) {
                    $out[$k] = $schema[$k] + 0;
                }
            }
        }
        return $out;
    }

    /**
     * Résultat d'outil → corps de `functionResponse.response`, qui DOIT être un
     * objet JSON : une liste, un scalaire ou un tableau vide est enveloppé.
     * Pure.
     */
    public static function functionResponseBody(mixed $result): array
    {
        if (is_array($result) && $result !== [] && !array_is_list($result)) {
            return $result;
        }
        return ['result' => $result];
    }

    /** 400 qui vise la recherche Google posée à côté des outils. Pure. */
    public static function isSearchWithToolsRejection(?int $status, string $message): bool
    {
        return $status === 400 && preg_match(
            '/google_?search|server_?side_?tool|include_?server|built-?in tools?|function calling is (?:not )?(?:unsupported|supported)/i',
            $message
        ) === 1;
    }

    /**
     * Corps de la requête GenerateContent.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(array $messages, string $systemPrompt, ?float $temperature, bool $webSearch): array
    {
        $payload = [
            'contents' => $this->formatMessages($messages),
        ];

        if ($systemPrompt !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        // Source unique = catalogue. Google a déprécié temperature sur Gemini 3
        // et déconseille toute valeur autre que 1,0 (boucles, raisonnement
        // dégradé) : ces modèles y sont marqués temperature=false, et les
        // 0,0 / 0,2 des appels auxiliaires (découpage, juges) ne partent plus.
        if ($temperature !== null && LlmCatalog::supportsTemperature('gemini', $this->model)) {
            $payload['generationConfig'] = ['temperature' => $temperature];
        }

        if ($webSearch) {
            $payload['tools'] = [['google_search' => (object)[]]];
        }

        return $payload;
    }

    /**
     * `usageMetadata` Gemini → jetons au format MIA — pure.
     *
     * `candidatesTokenCount` n'inclut PAS la réflexion (totalTokenCount =
     * prompt + thoughts + candidates), que Google facture pourtant au tarif de
     * sortie. Or CreditService, comme les usages OpenAI et Anthropic, tient le
     * raisonnement pour une PARTIE de la sortie et l'en retire avant de le
     * tarifer : sans l'addition, la plus petite des deux quantités (réponse ou
     * réflexion) n'était jamais facturée — et Gemini 3 réfléchit par défaut.
     *
     * @param array<string, mixed> $usage
     * @return array{input:int, output:int, input_cached:int, input_cache_write:int, output_reasoning:int}
     */
    public static function tokensFromUsage(array $usage): array
    {
        $thoughts = max(0, (int)($usage['thoughtsTokenCount'] ?? 0));

        return [
            'input'             => max(0, (int)($usage['promptTokenCount'] ?? 0)),
            'output'            => max(0, (int)($usage['candidatesTokenCount'] ?? 0)) + $thoughts,
            // Sous-ensemble du prompt, comme chez les autres fournisseurs.
            'input_cached'      => max(0, (int)($usage['cachedContentTokenCount'] ?? 0)),
            // MIA ne crée pas de cache explicite : aucune écriture à compter.
            'input_cache_write' => 0,
            'output_reasoning'  => $thoughts,
        ];
    }

    /**
     * Recherches Google facturées pour une réponse qui a lancé $queries
     * requêtes : chacune (génération 3.x), ou une seule quel que soit leur
     * nombre (génération 2.5, facturée au prompt ancré) — cf.
     * LlmCatalog::webSearchBilling.
     */
    public static function billedWebSearches(string $model, int $queries): int
    {
        if ($queries <= 0) {
            return 0;
        }
        return LlmCatalog::webSearchBilling('gemini', $model) === 'prompt' ? 1 : $queries;
    }

    private static function emptyTokens(): array
    {
        return [
            'input' => 0, 'output' => 0,
            'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0,
            'web_searches' => 0,
        ];
    }

    private function extractTextFromGeminiChunk(array $chunk): string
    {
        $parts = [];
        foreach (($chunk['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
        }
        return implode('', $parts);
    }

    private function formatMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            $role = (($msg['role'] ?? 'user') === 'assistant') ? 'model' : 'user';

            if (!is_array($msg['content'])) {
                return [
                    'role'  => $role,
                    'parts' => [['text' => (string)$msg['content']]],
                ];
            }

            $parts = [];
            foreach ($msg['content'] as $part) {
                if (($part['type'] ?? '') === 'text') {
                    $parts[] = ['text' => (string)$part['text']];
                } elseif (($part['type'] ?? '') === 'image') {
                    $parts[] = ['inline_data' => [
                        'mime_type' => (string)$part['mime'],
                        'data'      => (string)$part['data'],
                    ]];
                } elseif (($part['type'] ?? '') === 'document') {
                    $parts[] = ['inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data'      => (string)$part['data'],
                    ]];
                }
            }

            if (empty($parts)) {
                $parts[] = ['text' => ''];
            }

            return [
                'role'  => $role,
                'parts' => $parts,
            ];
        }, $messages);
    }
}
