<?php

declare(strict_types=1);

/**
 * Compteur UNIQUE des opérations IA — le seul chemin d'écriture du grand livre.
 *
 * Toute consommation d'un fournisseur (tokens d'un LLM, recherches web
 * exécutées par lui, embeddings, minutes de dictée, image ou vidéo générée)
 * passe par ici : le compteur calcule les crédits (`CreditService`), fixe
 * la nature de l'opération (`UsageSource`) et persiste la ligne (`UsageLog`).
 * Aucun contrôleur, job ou service n'appelle plus `CreditService` + `UsageLog`
 * à la main : c'est ce qui permettait à une dizaine d'appels (titres,
 * mémoire, embeddings, chunking, tests…) de ne jamais être comptés.
 *
 * Contrat :
 *   - ne JAMAIS faire échouer l'opération métier : une base injoignable au
 *     moment de journaliser est tracée (`[Usage]`) et le résultat porte
 *     `logged = false` — la réponse, l'image, le document partent quand même ;
 *   - ne rien journaliser quand rien n'a été consommé (appel en erreur avant
 *     le premier token) : une ligne à zéro ne serait ni un coût ni une
 *     opération ;
 *   - renvoyer le calcul de crédits de l'opération (badge du message, payload
 *     `done`, quota), recherches web comprises.
 *
 * Contexte d'imputation (`$ctx`) : `user_id` (null = instance, aucun
 * utilisateur à l'origine), `conversation_id`, `profile_id`, `message_id`,
 * `kb_id`. L'exemption de quota découle de la source (`UsageSource`).
 */
final class UsageMeter
{
    /** Résultat neutre d'une opération sans consommation (ou non journalisée). */
    private const EMPTY = [
        'total_credits'      => 0,
        'cost_eur'           => 0.0,
        'priced'             => true,
        'logged'             => false,
        'web_searches'       => 0,
        'web_search_credits' => 0,
        'usage_id'           => null,
    ];

    /**
     * Appel LLM (tokens) + recherches web éventuellement exécutées par le
     * fournisseur pendant cet appel (`$tokens['web_searches']`, journalisées
     * dans une ligne séparée `web_search`, même message).
     *
     * `$tokens['model']` : modèle qui a RÉELLEMENT produit la réponse, s'il
     * diffère de celui demandé (cf. effectiveLlmKey). `$tokens['additional']` :
     * consommations facturées sur d'AUTRES modèles pendant le même appel, une
     * ligne du grand livre chacune.
     *
     * @param array{input?:int,output?:int,input_cached?:int,input_cache_write?:int,output_reasoning?:int,web_searches?:int,model?:string,additional?:list<array{model:string,input?:int,output?:int,input_cached?:int,input_cache_write?:int}>} $tokens
     * @return array{total_credits:int, cost_eur:float, priced:bool, logged:bool, web_searches:int, web_search_credits:int, usage_id:?int}
     */
    public static function llm(string $llmKey, array $tokens, string $source, array $ctx = []): array
    {
        $source   = UsageSource::normalize($source);
        $llmKey   = self::effectiveLlmKey($llmKey, $tokens);
        $in       = max(0, (int)($tokens['input'] ?? 0));
        $out      = max(0, (int)($tokens['output'] ?? 0));
        $cached   = max(0, (int)($tokens['input_cached'] ?? 0));
        $cacheWr  = max(0, (int)($tokens['input_cache_write'] ?? 0));
        $reason   = max(0, (int)($tokens['output_reasoning'] ?? 0));
        $searches = max(0, (int)($tokens['web_searches'] ?? 0));
        // Sessions de conteneur de code (cf. CreditService) : sur la MÊME
        // ligne que les jetons de l'appel, qu'elles accompagnent toujours.
        $containers = max(0, (int)($tokens['containers'] ?? 0));

        $result = self::EMPTY;
        // Une opération = plusieurs lignes possibles (réponse, autres modèles
        // facturés, recherches) : frais fixes, arrondi et minimum ne valent
        // qu'UNE fois pour elle (cf. CreditService::additionalCredits).
        $op = ['billed' => 0.0, 'credits' => 0];
        if ($in + $out > 0) {
            $credits = CreditService::computeLlmCredits($llmKey, $in, $out, $cached, $cacheWr, $reason,
                CreditService::longContextPart($llmKey, $tokens), $containers);
            $op = ['billed' => (float)$credits['billed_eur'], 'credits' => (int)$credits['total_credits']];
            [$provider, $model] = CreditService::splitLlmKey($llmKey);
            $usageId = self::persistLlm([
                'llm_provider'             => $llmKey !== '' ? $llmKey : $provider,
                'model'                    => $model !== '' ? $model : null,
                'tokens_input'             => $in,
                'tokens_input_cached'      => $cached,
                'tokens_input_cache_write' => $cacheWr,
                'tokens_output'            => $out,
                'tokens_output_reasoning'  => $reason,
                'quantity'                 => 0.0,
                'credits_used'             => (int)$credits['total_credits'],
                'cost_eur'                 => (float)$credits['cost_eur'],
                'priced'                   => (bool)$credits['priced'],
                'source'                   => $source,
            ], $ctx, $source);
            $result['total_credits'] = (int)$credits['total_credits'];
            $result['cost_eur']      = (float)$credits['cost_eur'];
            $result['priced']        = (bool)$credits['priced'];
            $result['logged']        = $usageId !== null;
            $result['usage_id']      = $usageId;
        }

        // Autres modèles facturés pendant le MÊME appel — tentative déclinée en
        // cours de réponse avant qu'un modèle de relais ne reprenne, tour d'une
        // boucle d'outils servi par le relais (`additional`, cf. AnthropicAdapter).
        // Une ligne chacun, à LEUR tarif : additionner leurs tokens à ceux du
        // modèle servi fausserait le coût dans un sens ou dans l'autre.
        [$servedProvider] = CreditService::splitLlmKey($llmKey);
        foreach ((array)($tokens['additional'] ?? []) as $extra) {
            $extraModel = is_array($extra) ? trim((string)($extra['model'] ?? '')) : '';
            if ($extraModel === '' || $servedProvider === '') {
                continue;
            }
            $line = self::extraLlmLine($servedProvider . '::' . $extraModel, [
                'input'             => (int)($extra['input'] ?? 0),
                'output'            => (int)($extra['output'] ?? 0),
                'input_cached'      => (int)($extra['input_cached'] ?? 0),
                'input_cache_write' => (int)($extra['input_cache_write'] ?? 0),
                'output_reasoning'  => (int)($extra['output_reasoning'] ?? 0),
                'rounds'            => is_array($extra['rounds'] ?? null) ? $extra['rounds'] : [],
            ], $source, $ctx, $op);
            $result['total_credits'] += (int)$line['total_credits'];
            $result['cost_eur']       = round($result['cost_eur'] + (float)$line['cost_eur'], 6);
            $result['priced']         = $result['priced'] && $line['priced'];
            $result['logged']         = $result['logged'] || $line['logged'];
        }

        if ($searches > 0) {
            $ws = self::webSearchLine($llmKey, $searches, $ctx, $op);
            $result['web_searches']        = $searches;
            $result['web_search_credits']  = (int)$ws['total_credits'];
            $result['total_credits']      += (int)$ws['total_credits'];
            $result['cost_eur']            = round($result['cost_eur'] + (float)$ws['cost_eur'], 6);
            $result['priced']              = $result['priced'] && $ws['priced'];
            if ($in + $out <= 0) {
                $result['logged'] = $ws['logged'];
            }
        }

        return $result;
    }

    /**
     * Ligne d'un AUTRE modèle facturé pendant la même opération (tentative
     * déclinée avant un relais…), à SON tarif ; frais fixes, arrondi et
     * minimum restent ceux de l'opération.
     *
     * @param array{billed:float, credits:int} $op
     */
    private static function extraLlmLine(string $llmKey, array $tokens, string $source, array $ctx, array &$op): array
    {
        $in  = max(0, (int)($tokens['input'] ?? 0));
        $out = max(0, (int)($tokens['output'] ?? 0));
        if ($in + $out <= 0) {
            return self::EMPTY;
        }
        $cached  = max(0, (int)($tokens['input_cached'] ?? 0));
        $cacheWr = max(0, (int)($tokens['input_cache_write'] ?? 0));
        $reason  = max(0, (int)($tokens['output_reasoning'] ?? 0));
        $credits = CreditService::computeLlmCredits($llmKey, $in, $out, $cached, $cacheWr, $reason,
            CreditService::longContextPart($llmKey, $tokens));
        $credits['total_credits'] = CreditService::additionalCredits($op, $credits);
        [$provider, $model] = CreditService::splitLlmKey($llmKey);
        $usageId = self::persistLlm([
            'llm_provider'             => $llmKey !== '' ? $llmKey : $provider,
            'model'                    => $model !== '' ? $model : null,
            'tokens_input'             => $in,
            'tokens_input_cached'      => $cached,
            'tokens_input_cache_write' => $cacheWr,
            'tokens_output'            => $out,
            'tokens_output_reasoning'  => $reason,
            'quantity'                 => 0.0,
            'credits_used'             => (int)$credits['total_credits'],
            'cost_eur'                 => (float)$credits['cost_eur'],
            'priced'                   => (bool)$credits['priced'],
            'source'                   => UsageSource::normalize($source),
        ], $ctx, UsageSource::normalize($source));

        return [
            'total_credits'      => (int)$credits['total_credits'],
            'cost_eur'           => (float)$credits['cost_eur'],
            'priced'             => (bool)$credits['priced'],
            'logged'             => $usageId !== null,
            'web_searches'       => 0,
            'web_search_credits' => 0,
            'usage_id'           => $usageId,
        ];
    }

    /**
     * Recherches web exécutées par le fournisseur : une ligne `web_search`
     * dont la quantité est le nombre de recherches (tokens = 0).
     *
     * @return array{total_credits:int, cost_eur:float, priced:bool, logged:bool, web_searches:int, web_search_credits:int, usage_id:?int}
     */
    public static function webSearch(string $llmKey, int $searches, array $ctx = []): array
    {
        $op = ['billed' => 0.0, 'credits' => 0];
        return self::webSearchLine($llmKey, $searches, $ctx, $op);
    }

    /**
     * Ligne `web_search` d'une opération déjà entamée ($op : ce qu'ont déjà
     * réglé ses autres lignes).
     *
     * @param array{billed:float, credits:int} $op
     */
    private static function webSearchLine(string $llmKey, int $searches, array $ctx, array &$op): array
    {
        $searches = max(0, $searches);
        if ($searches <= 0) {
            return self::EMPTY;
        }
        $credits = CreditService::computeWebSearchCredits($llmKey, $searches);
        $credits['total_credits'] = CreditService::additionalCredits($op, $credits);
        [$provider, $model] = CreditService::splitLlmKey($llmKey);
        $usageId = self::persistLlm([
            'llm_provider' => $llmKey !== '' ? $llmKey : $provider,
            'model'        => $model !== '' ? $model : null,
            'quantity'     => (float)$searches,
            'credits_used' => (int)$credits['total_credits'],
            'cost_eur'     => (float)$credits['cost_eur'],
            'priced'       => (bool)$credits['priced'],
            'source'       => UsageSource::WEB_SEARCH,
        ], $ctx, UsageSource::WEB_SEARCH);

        return [
            'total_credits'      => (int)$credits['total_credits'],
            'cost_eur'           => (float)$credits['cost_eur'],
            'priced'             => (bool)$credits['priced'],
            'logged'             => $usageId !== null,
            'web_searches'       => $searches,
            'web_search_credits' => (int)$credits['total_credits'],
            'usage_id'           => $usageId,
        ];
    }

    /**
     * Embeddings (vectorisation d'un document, embedding d'une question) :
     * les tokens sont comptés en entrée, le modèle d'embedding tient lieu
     * de modèle.
     *
     * @return array{total_credits:int, cost_eur:float, priced:bool, logged:bool, web_searches:int, web_search_credits:int, usage_id:?int}
     */
    public static function embeddings(string $provider, string $model, int $tokens, string $source, array $ctx = []): array
    {
        $tokens = max(0, $tokens);
        if ($tokens <= 0) {
            return self::EMPTY;
        }
        $source  = UsageSource::normalize($source);
        $credits = CreditService::computeEmbeddingCredits($provider, $model, $tokens);
        $usageId = self::persistLlm([
            'llm_provider' => strtolower(trim($provider)) . '::' . trim($model),
            'model'        => trim($model) !== '' ? trim($model) : null,
            'tokens_input' => $tokens,
            'credits_used' => (int)$credits['total_credits'],
            'cost_eur'     => (float)$credits['cost_eur'],
            'priced'       => (bool)$credits['priced'],
            'source'       => $source,
        ], $ctx, $source);

        return [
            'total_credits'      => (int)$credits['total_credits'],
            'cost_eur'           => (float)$credits['cost_eur'],
            'priced'             => (bool)$credits['priced'],
            'logged'             => $usageId !== null,
            'web_searches'       => 0,
            'web_search_credits' => 0,
            'usage_id'           => $usageId,
        ];
    }

    /**
     * Dictée vocale : minutes d'audio transcrites (durée rapportée par le
     * navigateur à la fin d'une session Realtime).
     *
     * @return array{total_credits:int, cost_eur:float, priced:bool, logged:bool, web_searches:int, web_search_credits:int, usage_id:?int}
     */
    public static function transcription(string $model, float $seconds, array $ctx = []): array
    {
        $minutes = round(max(0.0, $seconds) / 60.0, 4);
        if ($minutes <= 0) {
            return self::EMPTY;
        }
        $credits = CreditService::computeTranscriptionCredits($model, $minutes);
        $usageId = self::persistLlm([
            'llm_provider' => 'openai::' . trim($model),
            'model'        => trim($model) !== '' ? trim($model) : null,
            'quantity'     => $minutes,
            'credits_used' => (int)$credits['total_credits'],
            'cost_eur'     => (float)$credits['cost_eur'],
            'priced'       => (bool)$credits['priced'],
            'source'       => UsageSource::TRANSCRIBE,
        ], $ctx, UsageSource::TRANSCRIBE);

        return [
            'total_credits'      => (int)$credits['total_credits'],
            'cost_eur'           => (float)$credits['cost_eur'],
            'priced'             => (bool)$credits['priced'],
            'logged'             => $usageId !== null,
            'web_searches'       => 0,
            'web_search_credits' => 0,
            'usage_id'           => $usageId,
        ];
    }

    /**
     * Lecture à voix haute (conversation vocale) : minutes d'audio produites
     * par la synthèse vocale, mesurées côté serveur sur l'audio reçu.
     *
     * @return array{total_credits:int, cost_eur:float, priced:bool, logged:bool, web_searches:int, web_search_credits:int, usage_id:?int}
     */
    public static function speech(string $model, float $seconds, array $ctx = []): array
    {
        $minutes = round(max(0.0, $seconds) / 60.0, 4);
        if ($minutes <= 0) {
            return self::EMPTY;
        }
        $credits = CreditService::computeSpeechCredits($model, $minutes);
        $usageId = self::persistLlm([
            'llm_provider' => 'openai::' . trim($model),
            'model'        => trim($model) !== '' ? trim($model) : null,
            'quantity'     => $minutes,
            'credits_used' => (int)$credits['total_credits'],
            'cost_eur'     => (float)$credits['cost_eur'],
            'priced'       => (bool)$credits['priced'],
            'source'       => UsageSource::SPEECH,
        ], $ctx, UsageSource::SPEECH);

        return [
            'total_credits'      => (int)$credits['total_credits'],
            'cost_eur'           => (float)$credits['cost_eur'],
            'priced'             => (bool)$credits['priced'],
            'logged'             => $usageId !== null,
            'web_searches'       => 0,
            'web_search_credits' => 0,
            'usage_id'           => $usageId,
        ];
    }

    /**
     * Génération par un outil facturé à l'unité (image, vidéo) : calcul en
     * cascade sur les paramètres réels du rendu, puis ligne `tool_usage_logs`.
     *
     * @param array<string, mixed> $tool   ligne `chat_tools`
     * @param array<string, mixed> $params modèle, taille, qualité, secondes, format, count
     * @return array{total_credits:int, cost_eur:float, priced:bool, logged:bool, quantity_units:float, meta:array<string, mixed>}
     */
    public static function tool(array $tool, array $params, array $ctx = [], string $source = UsageSource::TOOL_DEFAULT): array
    {
        $toolId   = (int)($tool['id'] ?? 0);
        $toolType = (string)($tool['type'] ?? 'other');
        $credits  = CreditService::computeToolCredits($toolId, $toolType, $params);

        $logged = self::persistTool([
            'tool_id'        => $toolId,
            'tool_type'      => $toolType,
            'source'         => $source,
            'credits_used'   => (int)$credits['total_credits'],
            'quantity_units' => (float)$credits['quantity_units'],
            'cost_eur'       => (float)$credits['cost_eur'],
            'priced'         => (bool)$credits['priced'],
            'meta'           => $credits['meta'] ?? null,
        ], $ctx, $source);

        return [
            'total_credits'  => (int)$credits['total_credits'],
            'cost_eur'       => (float)$credits['cost_eur'],
            'priced'         => (bool)$credits['priced'],
            'logged'         => $logged,
            'quantity_units' => (float)$credits['quantity_units'],
            'meta'           => is_array($credits['meta'] ?? null) ? $credits['meta'] : [],
        ];
    }

    /**
     * Utilisation d'un outil dont le coût est porté AILLEURS (page HTML,
     * itinéraire, présentation : les tokens sont dans `usage_logs` ; Gamma,
     * n8n : crédits externes ou aucun) — une ligne à 0 crédit pour le
     * compteur d'usages par outil.
     *
     * @param array<string, mixed> $tool ligne `chat_tools`
     * @param array<string, mixed> $meta détail libre (modèle, tokens, identifiant externe…)
     */
    public static function toolUse(array $tool, array $meta, array $ctx = [], string $source = UsageSource::TOOL_DEFAULT): bool
    {
        return self::persistTool([
            'tool_id'        => (int)($tool['id'] ?? 0),
            'tool_type'      => (string)($tool['type'] ?? 'other'),
            'source'         => $source,
            'credits_used'   => 0,
            'quantity_units' => 1.0,
            'cost_eur'       => 0.0,
            'priced'         => true,
            'meta'           => $meta !== [] ? $meta : null,
        ], $ctx, $source);
    }

    // ------------------------------------------------------------------

    /**
     * Clé « provider::model » à TARIFER. Un adapter peut appeler un autre
     * modèle que celui configuré (OpenAI bascule sur le modèle de recherche
     * du catalogue quand Internet est demandé sur un modèle qui ne sait pas
     * chercher) et le signale dans `$tokens['model']` : c'est lui qu'on
     * facture et qu'on journalise, pas celui de la conversation.
     */
    public static function effectiveLlmKey(string $llmKey, array $tokens): string
    {
        $real = trim((string)($tokens['model'] ?? ''));
        if ($real === '') {
            return $llmKey;
        }
        [$provider, $model] = CreditService::splitLlmKey($llmKey);
        if ($model === $real) {
            return $llmKey;
        }
        return $provider . '::' . $real;
    }

    /** @return int|null identifiant de la ligne, null si la persistance a échoué */
    private static function persistLlm(array $row, array $ctx, string $source): ?int
    {
        $row += [
            'user_id'         => self::idOrNull($ctx['user_id'] ?? null),
            'conversation_id' => self::idOrNull($ctx['conversation_id'] ?? null),
            'message_id'      => self::idOrNull($ctx['message_id'] ?? null),
            'profile_id'      => self::idOrNull($ctx['profile_id'] ?? null),
            'kb_id'           => self::idOrNull($ctx['kb_id'] ?? null),
            'quota_exempt'    => UsageSource::isQuotaExempt($source),
        ];
        // Une opération facturée prouve que le compte du fournisseur répond
        // de nouveau : son constat « crédit épuisé » ou « clé refusée »
        // s'éteint (points à régler de l'administration). Ne lève jamais.
        ProviderIncident::resolve(explode('::', (string)($row['llm_provider'] ?? ''))[0]);
        try {
            return UsageLog::insertLlm($row);
        } catch (\Throwable $e) {
            error_log('[Usage] journalisation impossible (' . $source . ') : ' . $e->getMessage());
            return null;
        }
    }

    private static function persistTool(array $row, array $ctx, string $source): bool
    {
        if ((int)($row['tool_id'] ?? 0) <= 0) {
            error_log('[Usage] ligne d\'outil ignorée (' . $source . ') : identifiant d\'outil absent');
            return false;
        }
        $row += [
            'user_id'         => self::idOrNull($ctx['user_id'] ?? null),
            'conversation_id' => self::idOrNull($ctx['conversation_id'] ?? null),
            'message_id'      => self::idOrNull($ctx['message_id'] ?? null),
            'profile_id'      => self::idOrNull($ctx['profile_id'] ?? null),
            'quota_exempt'    => $source === UsageSource::TOOL_TEST,
        ];
        try {
            UsageLog::insertTool($row);
            return true;
        } catch (\Throwable $e) {
            error_log('[Usage] journalisation d\'outil impossible (' . $source . ') : ' . $e->getMessage());
            return false;
        }
    }

    private static function idOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }
}
