<?php

declare(strict_types=1);

/**
 * Boucle de function-calling au format Chat Completions, partagée par les
 * fournisseurs « compatibles OpenAI » : DeepSeek, Kimi (Moonshot), GLM (Z.ai),
 * Mistral et le LLM local (Ollama, LM Studio…).
 *
 * Avant elle, seuls Anthropic et OpenAI pilotaient des outils : avec les sept
 * autres fournisseurs, aucun connecteur, aucun outil d'assistant, aucune
 * capacité d'agent — l'interface les signalait « incompatibles ». Les six
 * adaptateurs concernés parlent le même format (tools, tool_calls, messages
 * `tool`) avec des écarts de DIALECTE, déclarés au catalogue
 * (LlmCatalog::toolCallingDialect) plutôt que recopiés dans chaque classe :
 *   - `tool_choice_none` : GLM n'accepte que `auto` — la synthèse forcée du
 *     dernier aller passe alors par une consigne ;
 *   - `tool_message_name` : Mistral EXIGE le nom de la fonction sur chaque
 *     résultat (Kimi le montre dans ses exemples) ;
 *   - `reasoning_echo` : Kimi refuse un appel d'outil rejoué sans son
 *     `reasoning_content` (« thinking is enabled but reasoning_content is
 *     missing in assistant tool call message ») ; le raisonnement capté est
 *     donc rendu sur le message qui porte les appels, et seulement là.
 *
 * OpenAIAdapter garde SES boucles : Responses API, `prompt_cache_key`,
 * écritures de cache, bascule sur le modèle de recherche — rien de tout cela
 * n'existe ailleurs. Même contrat que les siennes, en revanche : budget
 * d'allers-retours relu à chaque aller (LlmAdapterHelpers::roundsBudget),
 * outils relus à chaque aller (`tools_source`), synthèse forcée au dernier,
 * arrêt (Stop) contrôlé avant chaque appel, appels exécutés par le dispatch
 * unique de l'appelant.
 *
 * L'adaptateur fournit : toolLoopUrl(), toolLoopHeaders(), toolLoopLabel(),
 * toolLoopMessages() et toolLoopTemperature() ; il peut redéfinir
 * toolLoopReady() (le LLM local n'exige pas de clé), toolLoopEmit()
 * (Mistral nettoie ses fragments) et toolLoopPayloadExtras() (DeepSeek coupe
 * la réflexion et pose son plafond de sortie à chaque aller).
 */
trait ChatCompletionsToolLoopTrait
{
    /**
     * Fournisseurs qui ont refusé `tool_choice: "none"` pendant ce process :
     * la synthèse forcée passe ensuite par une consigne, sans payer un 400 par
     * message. Statique (worker long-vivant), même patron que les refus appris
     * d'AnthropicAdapter.
     *
     * @var array<string, true>
     */
    private static array $toolChoiceNoneRejected = [];

    /** URL du point Chat Completions. */
    abstract protected function toolLoopUrl(): string;

    /** @return list<string> lignes d'en-tête HTTP, autorisation comprise */
    abstract protected function toolLoopHeaders(): array;

    /** Nom du fournisseur dans les messages d'erreur (« DeepSeek », « Kimi »…). */
    abstract protected function toolLoopLabel(): string;

    /** Messages neutres → messages du fournisseur (pièces jointes comprises). */
    abstract protected function toolLoopMessages(array $messages): array;

    /** Température à envoyer pour ce modèle, ou null pour n'en envoyer aucune. */
    abstract protected function toolLoopTemperature(?float $temperature): ?float;

    public function supportsToolUse(): bool
    {
        return $this->toolLoopReady() && LlmCatalog::supportsToolUse($this->getProviderName(), $this->model);
    }

    /** L'adaptateur peut-il appeler son fournisseur ? (clé présente, par défaut) */
    protected function toolLoopReady(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Champs propres au fournisseur ajoutés à CHAQUE aller de la boucle
     * (DeepSeek : `thinking` coupé, `max_tokens`). Vide par défaut. Les clés
     * de la boucle (model, stream, messages, tools, temperature, tool_choice)
     * gardent la main.
     *
     * @return array<string, mixed>
     */
    protected function toolLoopPayloadExtras(): array
    {
        return [];
    }

    /** Émet un fragment de réponse ; true si du texte est réellement parti. */
    protected function toolLoopEmit(callable $onChunk, string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $onChunk($text);
        return true;
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
            // Modèle sans function-calling : un stream classique, recherche web
            // comprise (l'éteindre ici la ferait perdre sans raison).
            return $this->stream($messages, $systemPrompt, $onChunk, $temperature, $webSearch);
        }

        $provider = $this->getProviderName();
        $label    = $this->toolLoopLabel();
        $dialect  = LlmCatalog::toolCallingDialect($provider);

        if ($webSearch) {
            // Chat Completions n'a pas d'outil de recherche serveur. Le contrôleur
            // tranche en amont (ChatController : les outils explicites gagnent,
            // la recherche s'éteint et le dit) ; arriver ici avec la recherche
            // demandée, c'est le LLM de secours — on le dit au modèle plutôt que
            // de le laisser prétendre avoir consulté le web.
            error_log('[' . $label . '/Outils] recherche Internet demandée avec des outils sur ' . $this->model
                . ' : impossible dans la même réponse, réponse sans recherche.');
            $systemPrompt .= "\n\n" . LlmAdapterHelpers::webSearchWithToolsUnavailableNote();
        }

        $conv = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $this->toolLoopMessages($messages)
        );
        $chatTools = self::chatTools($tools);
        $sendTemperature = $this->toolLoopTemperature($temperature);

        $tokens = ['input' => 0, 'output' => 0, 'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0];
        $rounds = []; // usage de chaque aller : un palier de contexte vaut par requête
        $textSent = false; // du texte est-il parti, tous allers confondus ?
        $stop = null;      // arrêt anormal du dernier aller (longueur, filtre…)
        $forcedSynthesis = false;

        for ($round = 0; $round < LlmAdapterHelpers::roundsBudget($options); $round++) {
            // Outils relus à chaque aller : un connecteur activé par le modèle
            // (« Laisser MIA choisir ») y apparaît dès l'aller suivant.
            if (is_callable($options['tools_source'] ?? null)) {
                $chatTools = self::chatTools((array)($options['tools_source'])());
            }
            // Dernier aller : synthèse forcée — des outils appelés au dernier
            // tour seraient exécutés sans que le modèle voie leurs résultats.
            $lastRound = $round === LlmAdapterHelpers::roundsBudget($options) - 1;
            $forcedSynthesis = $forcedSynthesis || $lastRound;

            $payload = [
                'model'    => $this->model,
                'stream'   => true,
                'messages' => $conv,
                'tools'    => $chatTools,
            ] + $this->toolLoopPayloadExtras();
            if ($sendTemperature !== null) {
                $payload['temperature'] = $sendTemperature;
            }
            $noneAllowed = $dialect['tool_choice_none'] && !isset(self::$toolChoiceNoneRejected[$provider]);
            if ($lastRound) {
                if ($noneAllowed) {
                    $payload['tool_choice'] = 'none';
                } else {
                    $payload['messages'][] = ['role' => 'system', 'content' => LlmAdapterHelpers::finalRoundNote()];
                }
            }

            $res = $this->toolLoopRound($payload, $onChunk, $label);
            if (!empty($res['retry_tool_choice'])) {
                // `none` refusé par ce fournisseur : appris pour le process, et
                // la même demande repart avec la consigne à la place.
                self::$toolChoiceNoneRejected[$provider] = true;
                unset($payload['tool_choice']);
                $payload['messages'][] = ['role' => 'system', 'content' => LlmAdapterHelpers::finalRoundNote()];
                $res = $this->toolLoopRound($payload, $onChunk, $label);
            }
            foreach ($tokens as $k => $v) {
                $tokens[$k] = $v + (int)($res['tokens'][$k] ?? 0);
            }
            if ($res['tokens'] !== []) {
                $rounds[] = $res['tokens'];
            }
            $textSent = $textSent || $res['text'] !== '';

            if ($res['error'] || $res['tool_calls'] === [] || $lastRound) {
                if (!$res['error']) {
                    if ($lastRound && $res['tool_calls'] !== []) {
                        // Consigne ignorée au dernier aller : les appels ne sont
                        // PAS exécutés (le modèle n'en verrait pas le résultat).
                        error_log('[' . $label . '/Outils] appels d\'outils au dernier aller ignorés (' . $this->model . ').');
                    }
                    // Réponse finale : un arrêt anormal se dit (chunk d'erreur si
                    // rien n'a été écrit, clé `stop` sinon).
                    $stop = LlmAdapterHelpers::concludeStop($label, $res['finish'], $textSent, false, $onChunk);
                }
                break;
            }

            // Tour assistant portant les appels — le raisonnement capté y est
            // rendu quand le fournisseur l'exige (Kimi), jamais ailleurs.
            $assistantTurn = ['role' => 'assistant', 'content' => $res['text'] !== '' ? $res['text'] : null, 'tool_calls' => []];
            if ($dialect['reasoning_echo'] && $res['reasoning'] !== '') {
                $assistantTurn['reasoning_content'] = $res['reasoning'];
            }
            foreach ($res['tool_calls'] as $tc) {
                $assistantTurn['tool_calls'][] = [
                    'id'       => $tc['id'],
                    'type'     => 'function',
                    'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments'] !== '' ? $tc['arguments'] : '{}'],
                ];
            }
            $conv[] = $assistantTurn;

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
                if (!is_array($args)) {
                    $args = [];
                }
                $result = $onToolCall($tc['name'], $args);
                $toolMsg = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content'      => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                ];
                if ($dialect['tool_message_name']) {
                    $toolMsg['name'] = $tc['name'];
                }
                $conv[] = $toolMsg;
            }

            if (LlmAdapterHelpers::clientAborted()) {
                break;
            }
        }

        return $tokens
            + ['rounds' => $rounds]
            + ($stop !== null ? ['stop' => $stop] : [])
            + ($forcedSynthesis ? ['rounds_exhausted' => true] : []);
    }

    /**
     * Ouvre le flux d'un aller. Point de substitution des tests (un faux
     * serveur par fichiers), comme AnthropicAdapter::openRoundStream.
     *
     * @return array{0: resource|false, 1: ?int} flux, statut HTTP
     */
    protected function openToolRoundStream(string $body): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $this->toolLoopHeaders()),
            'content'       => $body,
            'timeout'       => 180,
            'ignore_errors' => true,
        ]]);
        $stream = @fopen($this->toolLoopUrl(), 'r', false, $ctx);
        return [$stream, LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null)];
    }

    /**
     * Un aller : POST en streaming, texte émis au fil de l'eau, appels d'outils
     * réassemblés, raisonnement et usage relevés.
     *
     * @return array{text:string, reasoning:string, tool_calls:list<array{id:string,name:string,arguments:string}>,
     *               tokens:array<string,int>, error:bool, finish:?string, retry_tool_choice?:bool}
     */
    private function toolLoopRound(array $payload, callable $onChunk, string $label): array
    {
        $fail = static fn(): array => ['text' => '', 'reasoning' => '', 'tool_calls' => [], 'tokens' => [],
            'error' => true, 'finish' => null];

        $body = LlmAdapterHelpers::encodeBody($payload);
        if ($body === false) {
            error_log('[' . $label . '/Outils] json_encode payload a échoué : ' . json_last_error_msg());
            $onChunk('[Erreur ' . $label . ' : requête invalide (encodage payload)]');
            return $fail();
        }

        [$stream, $httpStatus] = $this->openToolRoundStream($body);
        if (!$stream) {
            error_log('[' . $label . '/Outils] Impossible d\'ouvrir le stream (' . $this->model . ').');
            $onChunk('[Erreur : impossible de contacter ' . $label . ']');
            return $fail();
        }
        stream_set_read_buffer($stream, 0);

        if ($httpStatus !== null && $httpStatus >= 400) {
            $errBody = stream_get_contents($stream) ?: '';
            fclose($stream);
            $errMsg = LlmAdapterHelpers::errorDetail(json_decode($errBody, true), $errBody, $httpStatus);
            error_log('[' . $label . '/Outils] HTTP ' . $httpStatus . ' : ' . $errMsg . ' | model=' . $this->model);
            if (isset($payload['tool_choice']) && self::isToolChoiceRejection($httpStatus, $errMsg)) {
                return ['retry_tool_choice' => true] + $fail();
            }
            $onChunk(LlmAdapterHelpers::buildHttpErrorMessage($label, $httpStatus, $errMsg));
            return $fail();
        }

        $text = '';
        $reasoning = '';
        $calls = [];      // index => ['id','name','arguments']
        $lastIndex = -1;  // dernier emplacement rempli (fournisseurs sans `index`)
        $tokens = [];
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
                error_log('[' . $label . '/Outils] Erreur : ' . $msg);
                $onChunk('[Erreur ' . $label . ' : ' . $msg . ']');
                fclose($stream);
                return $fail();
            }

            $data = trim(substr($line, 6));
            if ($data === '[DONE]') {
                break;
            }
            $json = json_decode($data, true);
            if (!is_array($json)) {
                continue;
            }
            if (isset($json['error'])) {
                $errMsg = LlmAdapterHelpers::errorDetail($json);
                error_log('[' . $label . '/Outils] Erreur SSE : ' . $errMsg);
                $onChunk('[Erreur ' . $label . ' : ' . $errMsg . ']');
                fclose($stream);
                return $fail();
            }

            $delta = $json['choices'][0]['delta'] ?? [];
            $finish = LlmAdapterHelpers::chatFinishReason($json) ?? $finish;

            $content = self::deltaText($delta['content'] ?? '');
            if ($content !== '' && $this->toolLoopEmit($onChunk, $content)) {
                $text .= $content;
                if (LlmAdapterHelpers::clientAborted()) {
                    break;
                }
            }
            if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content'])) {
                $reasoning .= $delta['reasoning_content'];
            }

            foreach ((array)($delta['tool_calls'] ?? []) as $tcDelta) {
                if (!is_array($tcDelta)) {
                    continue;
                }
                $idx = self::toolCallSlot($tcDelta, $calls, $lastIndex);
                $calls[$idx] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                $lastIndex = $idx;
                if (!empty($tcDelta['id'])) {
                    $calls[$idx]['id'] = (string)$tcDelta['id'];
                }
                $fn = (array)($tcDelta['function'] ?? []);
                if (isset($fn['name']) && $fn['name'] !== '') {
                    $calls[$idx]['name'] .= (string)$fn['name'];
                }
                if (isset($fn['arguments'])) {
                    // Mistral peut livrer les arguments déjà décodés.
                    $calls[$idx]['arguments'] .= is_string($fn['arguments'])
                        ? $fn['arguments']
                        : (json_encode($fn['arguments'], JSON_UNESCAPED_UNICODE) ?: '');
                }
            }

            // Kimi range l'usage dans le choix, les autres à la racine.
            $usage = $json['usage'] ?? $json['choices'][0]['usage'] ?? null;
            if (is_array($usage)) {
                $tokens = self::chatUsageTokens($usage);
            }
        }
        fclose($stream);

        // Corps d'erreur resté en tampon (réponse JSON sans aucune ligne `data:`).
        if ($rawBuffer !== '' && $text === '' && $calls === []) {
            $msg = LlmAdapterHelpers::errorDetail(json_decode($rawBuffer, true), $rawBuffer, $httpStatus);
            error_log('[' . $label . '/Outils] Erreur (fin de flux) : ' . $msg);
            $onChunk('[Erreur ' . $label . ' : ' . $msg . ']');
            return $fail();
        }

        return ['text' => $text, 'reasoning' => $reasoning, 'tool_calls' => self::finalizeToolCalls($calls),
                'tokens' => $tokens, 'error' => false, 'finish' => $finish];
    }

    /**
     * Emplacement d'un fragment d'appel d'outil. Pure.
     *
     * Le format OpenAI numérote les appels (`index`) et les découpe en
     * fragments. Certains fournisseurs livrent chaque appel ENTIER sans index :
     * un nouvel identifiant ouvre alors un nouvel emplacement, un fragment sans
     * identifiant complète le dernier.
     *
     * @param array<int, array> $calls
     */
    public static function toolCallSlot(array $tcDelta, array $calls, int $lastIndex): int
    {
        if (isset($tcDelta['index']) && is_numeric($tcDelta['index'])) {
            return (int)$tcDelta['index'];
        }
        $id = (string)($tcDelta['id'] ?? '');
        if ($id !== '') {
            foreach ($calls as $i => $c) {
                if ($c['id'] === $id) {
                    return (int)$i;
                }
            }
            return $calls === [] ? 0 : max(array_keys($calls)) + 1;
        }
        return $lastIndex >= 0 ? $lastIndex : 0;
    }

    /**
     * Appels réassemblés : tri par emplacement, appels sans nom écartés,
     * identifiant de secours. Pure.
     *
     * @param array<int, array{id:string,name:string,arguments:string}> $calls
     * @return list<array{id:string,name:string,arguments:string}>
     */
    public static function finalizeToolCalls(array $calls): array
    {
        ksort($calls);
        $final = [];
        foreach ($calls as $tc) {
            if (trim($tc['name']) === '') {
                continue;
            }
            if ($tc['id'] === '') {
                $tc['id'] = 'call' . count($final) . substr(md5($tc['name'] . $tc['arguments']), 0, 4);
            }
            $final[] = $tc;
        }
        return $final;
    }

    /**
     * Texte d'un delta : une chaîne, ou une liste de morceaux typés (Mistral,
     * modèles de raisonnement) dont seuls les morceaux `text` sont la réponse.
     * Pure.
     */
    public static function deltaText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $text = '';
        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        return $text;
    }

    /**
     * Usage d'un fournisseur compatible → jetons au format MIA. Pure.
     * Cache lu : `prompt_tokens_details.cached_tokens` (OpenAI, Mistral, GLM),
     * `cached_tokens` (Kimi), `prompt_cache_hit_tokens` (DeepSeek).
     *
     * @param array<string, mixed> $usage
     * @return array{input:int, output:int, input_cached:int, input_cache_write:int, output_reasoning:int}
     */
    public static function chatUsageTokens(array $usage): array
    {
        return [
            'input'             => max(0, (int)($usage['prompt_tokens'] ?? 0)),
            'output'            => max(0, (int)($usage['completion_tokens'] ?? 0)),
            'input_cached'      => max(0, (int)(
                $usage['prompt_tokens_details']['cached_tokens']
                ?? $usage['cached_tokens']
                ?? $usage['prompt_cache_hit_tokens']
                ?? 0
            )),
            'input_cache_write' => 0,
            'output_reasoning'  => max(0, (int)(
                $usage['completion_tokens_details']['reasoning_tokens']
                ?? $usage['reasoning_tokens']
                ?? 0
            )),
        ];
    }

    /**
     * Outils agnostiques → format Chat Completions. Pure.
     *
     * @param list<array> $tools
     * @return list<array>
     */
    public static function chatTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $out[] = ['type' => 'function', 'function' => [
                'name'        => (string)($t['name'] ?? ''),
                'description' => (string)($t['description'] ?? ''),
                'parameters'  => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ]];
        }
        return $out;
    }

    /** 400 qui vise `tool_choice` (valeur refusée par ce fournisseur). Pure. */
    public static function isToolChoiceRejection(?int $status, string $message): bool
    {
        return ($status === 400 || $status === 422) && stripos($message, 'tool_choice') !== false;
    }
}
