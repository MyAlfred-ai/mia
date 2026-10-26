<?php

declare(strict_types=1);

/**
 * Grand livre des opérations IA — lecture et écriture de `usage_logs`
 * (tokens, recherches web, embeddings, minutes de dictée) et de
 * `tool_usage_logs` (images, vidéos, usages d'outils).
 *
 * ÉCRITURE : `insertLlm()` / `insertTool()` ne sont appelées que par
 * `UsageMeter` (le compteur unique) — c'est lui qui calcule les crédits et
 * fixe la nature de l'opération. Ici, aucun calcul : on persiste ce qu'on
 * reçoit.
 *
 * LECTURE : tous les agrégats des écrans d'usage, du quota et du tableau de
 * bord. Deux grandeurs € distinctes, jamais confondues :
 *   - `cost_eur` (colonne) = coût RÉEL fournisseur, sommé tel quel ;
 *   - la valorisation (crédits × valeur du crédit) est dérivée par l'appelant
 *     via `CreditService::creditsToEur()`, jamais stockée.
 * Le quota ne compte que les lignes `quota_exempt = 0` (`quota_credits`) ;
 * les totaux (`total_credits`) incluent tout.
 */
class UsageLog
{
    // =====================================================================
    // Écriture (réservée à UsageMeter)
    // =====================================================================

    /**
     * Insère une ligne `usage_logs`. Clés reconnues (les absentes prennent
     * la valeur neutre) : user_id, conversation_id, message_id, profile_id,
     * kb_id, llm_provider, model, tokens_input, tokens_input_cached,
     * tokens_input_cache_write, tokens_output, tokens_output_reasoning,
     * quantity, credits_used, cost_eur, priced, quota_exempt, source.
     *
     * @return int identifiant de la ligne
     */
    public static function insertLlm(array $row): int
    {
        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO usage_logs
                (user_id, conversation_id, message_id, profile_id, kb_id, llm_provider, model,
                 tokens_input, tokens_input_cached, tokens_input_cache_write,
                 tokens_output, tokens_output_reasoning, quantity,
                 credits_used, cost_eur, priced, quota_exempt, source)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            self::nullableId($row['user_id'] ?? null),
            self::nullableId($row['conversation_id'] ?? null),
            self::nullableId($row['message_id'] ?? null),
            self::nullableId($row['profile_id'] ?? null),
            self::nullableId($row['kb_id'] ?? null),
            (string)($row['llm_provider'] ?? ''),
            isset($row['model']) && (string)$row['model'] !== '' ? (string)$row['model'] : null,
            max(0, (int)($row['tokens_input'] ?? 0)),
            max(0, (int)($row['tokens_input_cached'] ?? 0)),
            max(0, (int)($row['tokens_input_cache_write'] ?? 0)),
            max(0, (int)($row['tokens_output'] ?? 0)),
            max(0, (int)($row['tokens_output_reasoning'] ?? 0)),
            max(0.0, (float)($row['quantity'] ?? 0.0)),
            max(0, (int)($row['credits_used'] ?? 0)),
            max(0.0, (float)($row['cost_eur'] ?? 0.0)),
            !array_key_exists('priced', $row) || !empty($row['priced']) ? 1 : 0,
            !empty($row['quota_exempt']) ? 1 : 0,
            UsageSource::normalize((string)($row['source'] ?? UsageSource::CHAT)),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Insère une ligne `tool_usage_logs`. Clés : user_id, conversation_id,
     * message_id, profile_id, tool_id, tool_type, source, credits_used,
     * quantity_units, cost_eur, priced, quota_exempt, meta (tableau → JSON).
     *
     * @return int identifiant de la ligne
     */
    public static function insertTool(array $row): int
    {
        $metaJson = null;
        if (is_array($row['meta'] ?? null) && $row['meta'] !== []) {
            $encoded = json_encode($row['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $metaJson = $encoded;
            }
        }
        $source = trim((string)($row['source'] ?? UsageSource::TOOL_DEFAULT));
        if ($source === '') {
            $source = UsageSource::TOOL_DEFAULT;
        }

        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO tool_usage_logs
                (user_id, conversation_id, message_id, profile_id, tool_id, tool_type, source,
                 credits_used, quantity_units, cost_eur, priced, quota_exempt, meta_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            self::nullableId($row['user_id'] ?? null),
            self::nullableId($row['conversation_id'] ?? null),
            self::nullableId($row['message_id'] ?? null),
            self::nullableId($row['profile_id'] ?? null),
            (int)($row['tool_id'] ?? 0),
            (string)($row['tool_type'] ?? 'other'),
            $source,
            max(0, (int)($row['credits_used'] ?? 0)),
            max(0.0, (float)($row['quantity_units'] ?? 1.0)),
            max(0.0, (float)($row['cost_eur'] ?? 0.0)),
            !array_key_exists('priced', $row) || !empty($row['priced']) ? 1 : 0,
            !empty($row['quota_exempt']) ? 1 : 0,
            $metaJson,
        ]);
        return (int)$db->lastInsertId();
    }

    private static function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    // =====================================================================
    // Clauses communes
    // =====================================================================

    /**
     * Clause WHERE commune période + utilisateur pour les agrégats.
     * Convention : borne haute inclusive via ' 23:59:59'.
     *
     * @return array{0:string, 1:array} ['' ou 'WHERE …', params]
     */
    private static function usagePeriodWhere(?string $from, ?string $to, ?int $userId, string $alias = ''): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $parts = [];
        $params = [];
        if ($from && $to) {
            $parts[] = $prefix . 'created_at BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to . ' 23:59:59';
        }
        if ($userId !== null && $userId > 0) {
            $parts[] = $prefix . 'user_id = ?';
            $params[] = $userId;
        }
        return [$parts !== [] ? 'WHERE ' . implode(' AND ', $parts) : '', $params];
    }

    /**
     * Les deux tables ramenées à un même schéma de ligne, pour les agrégats
     * qui doivent les fusionner : user_id, conversation_id, created_at,
     * category, source, kind ('llm'|'tool'), credits, cost_eur, quota_exempt,
     * priced, tokens, quantity.
     *
     * @return array{0:string, 1:array} [SQL de la sous-requête, params]
     */
    private static function ledgerUnion(?string $from, ?string $to, ?int $userId): array
    {
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId);
        $llmCategory  = UsageSource::sqlCategoryCase('source');
        $toolCategory = UsageSource::sqlToolCategoryCase('source');
        $sql = "SELECT user_id, conversation_id, created_at, source, 'llm' AS kind,
                       $llmCategory AS category,
                       credits_used AS credits, cost_eur, quota_exempt, priced,
                       tokens_input + tokens_output AS tokens, quantity
                FROM usage_logs $where
                UNION ALL
                SELECT user_id, conversation_id, created_at, source, 'tool' AS kind,
                       $toolCategory AS category,
                       credits_used AS credits, cost_eur, quota_exempt, priced,
                       0 AS tokens, quantity_units AS quantity
                FROM tool_usage_logs $where";
        return [$sql, array_merge($params, $params)];
    }

    // =====================================================================
    // Conversation et messages (chat)
    // =====================================================================

    /**
     * Crédits regroupés par message_id pour une conversation (LLM, recherches
     * web, embeddings de question et outils confondus). Les lignes orphelines
     * (message_id NULL) sont ignorées.
     */
    public static function creditsByMessage(int $conversationId, ?int $userId = null): array
    {
        if ($conversationId <= 0) {
            return [];
        }

        $db = Database::getInstance();
        $where = 'conversation_id = ? AND message_id IS NOT NULL';
        $params = [$conversationId];
        if ($userId !== null && $userId > 0) {
            $where .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $map = [];
        foreach (['usage_logs', 'tool_usage_logs'] as $table) {
            $stmt = $db->prepare(
                "SELECT message_id, COALESCE(SUM(credits_used), 0) AS c
                 FROM $table WHERE $where GROUP BY message_id"
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mid = (int)$row['message_id'];
                $map[$mid] = ($map[$mid] ?? 0) + (int)$row['c'];
            }
        }
        return $map;
    }

    /** Dernière ligne du grand livre LLM (repère de début d'un tour). */
    public static function lastId(): int
    {
        try {
            return (int)Database::getInstance()->query('SELECT COALESCE(MAX(id), 0) FROM usage_logs')->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[Usage] repère de tour illisible : ' . $e->getMessage());
            return PHP_INT_MAX;
        }
    }

    /**
     * Rattache à la réponse les appels auxiliaires de SON tour (titre, mémoire,
     * question vectorisée, résumé du contexte… — UsageSource::TURN_HELPERS),
     * journalisés avant qu'elle n'existe. Sans cela, le badge d'une réponse
     * ignorait une part de ce qu'elle avait coûté, et le total de la
     * conversation dépassait la somme des badges sans que rien ne l'explique.
     * Bornée aux lignes du tour (`id > $afterId`), de cette conversation et de
     * cette personne ; ne lève jamais.
     */
    public static function attachTurn(int $conversationId, int $userId, int $messageId, int $afterId): void
    {
        if ($conversationId <= 0 || $messageId <= 0 || $afterId === PHP_INT_MAX) {
            return;
        }
        $sources = UsageSource::TURN_HELPERS;
        try {
            Database::getInstance()->prepare(
                'UPDATE usage_logs SET message_id = ?
                 WHERE conversation_id = ? AND user_id = ? AND message_id IS NULL AND id > ?
                   AND source IN (' . implode(',', array_fill(0, count($sources), '?')) . ')'
            )->execute(array_merge([$messageId, $conversationId, $userId, $afterId], $sources));
        } catch (\Throwable $e) {
            error_log('[Usage] rattachement du tour au message ' . $messageId . ' : ' . $e->getMessage());
        }
    }

    /** Crédits d'une réponse, toutes lignes confondues — ce que son badge affiche, en direct comme au rechargement. */
    public static function creditsForMessage(int $messageId): int
    {
        if ($messageId <= 0) {
            return 0;
        }
        $total = 0;
        try {
            $db = Database::getInstance();
            foreach (['usage_logs', 'tool_usage_logs'] as $table) {
                $stmt = $db->prepare("SELECT COALESCE(SUM(credits_used), 0) FROM $table WHERE message_id = ?");
                $stmt->execute([$messageId]);
                $total += (int)$stmt->fetchColumn();
            }
        } catch (\Throwable $e) {
            error_log('[Usage] crédits du message ' . $messageId . ' illisibles : ' . $e->getMessage());
        }
        return $total;
    }

    /** Total de crédits consommés pour un fil de discussion (LLM + outils). */
    public static function conversationCreditsTotal(int $conversationId, ?int $userId = null): int
    {
        $conversationId = max(0, $conversationId);
        if ($conversationId <= 0) {
            return 0;
        }

        $db = Database::getInstance();
        $where = 'conversation_id = ?';
        $params = [$conversationId];
        if ($userId !== null && $userId > 0) {
            $where .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $total = 0;
        foreach (['usage_logs', 'tool_usage_logs'] as $table) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(credits_used), 0) FROM $table WHERE $where");
            $stmt->execute($params);
            $total += (int)$stmt->fetchColumn();
        }
        return max(0, $total);
    }

    // =====================================================================
    // Quota et page « Usage » (Paramètres)
    // =====================================================================

    /**
     * Instantané du mois calendaire en cours pour un utilisateur.
     *
     * `quota_credits` = ce qui entame le quota (lignes non exemptées) ;
     * `total_credits` = tout ce qui lui est imputé, exploitation comprise ;
     * `categories` = détail par catégorie (UsageSource), dans l'ordre
     * d'affichage, catégories vides comprises.
     *
     * @return array{
     *   total_credits:int, quota_credits:int, exempt_credits:int,
     *   total_cost_eur:float, requests:int, tokens_input:int, tokens_output:int,
     *   tokens_input_cached:int, tokens_output_reasoning:int, tokens_total:int,
     *   web_searches:int, unpriced_ops:int,
     *   categories:array<string, array{credits:int, requests:int, cost_eur:float, tokens:int, quantity:float, quota_exempt:bool}>
     * }
     */
    public static function creditsBreakdownThisMonth(int $userId): array
    {
        $monthStart = date('Y-m-01');
        $monthEnd   = date('Y-m-t');
        $db = Database::getInstance();

        [$union, $params] = self::ledgerUnion($monthStart, $monthEnd, $userId);
        $stmt = $db->prepare(
            "SELECT t.category,
                    COUNT(*)                                   AS requests,
                    COALESCE(SUM(t.credits), 0)                AS credits,
                    COALESCE(SUM(t.cost_eur), 0)               AS cost_eur,
                    COALESCE(SUM(t.tokens), 0)                 AS tokens,
                    COALESCE(SUM(t.quantity), 0)               AS quantity,
                    COALESCE(SUM(IF(t.quota_exempt = 1, t.credits, 0)), 0) AS exempt_credits,
                    COALESCE(SUM(IF(t.priced = 0, 1, 0)), 0)   AS unpriced_ops
             FROM ($union) t
             GROUP BY t.category"
        );
        $stmt->execute($params);

        $categories = [];
        foreach (UsageSource::categories() as $cat) {
            $categories[$cat] = [
                'credits' => 0, 'requests' => 0, 'cost_eur' => 0.0, 'tokens' => 0, 'quantity' => 0.0,
                'quota_exempt' => UsageSource::isCategoryQuotaExempt($cat),
            ];
        }
        $total = 0; $exempt = 0; $cost = 0.0; $requests = 0; $unpriced = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cat = (string)$row['category'];
            if (!isset($categories[$cat])) {
                $categories[$cat] = ['credits' => 0, 'requests' => 0, 'cost_eur' => 0.0, 'tokens' => 0, 'quantity' => 0.0, 'quota_exempt' => false];
            }
            $categories[$cat]['credits']  += (int)$row['credits'];
            $categories[$cat]['requests'] += (int)$row['requests'];
            $categories[$cat]['cost_eur'] += (float)$row['cost_eur'];
            $categories[$cat]['tokens']   += (int)$row['tokens'];
            $categories[$cat]['quantity'] += (float)$row['quantity'];
            $total    += (int)$row['credits'];
            $exempt   += (int)$row['exempt_credits'];
            $cost     += (float)$row['cost_eur'];
            $requests += (int)$row['requests'];
            $unpriced += (int)$row['unpriced_ops'];
        }

        // Détail des tokens (usage_logs seul : les outils n'en portent pas).
        $tok = $db->prepare(
            'SELECT COALESCE(SUM(tokens_input), 0)            AS tokens_in,
                    COALESCE(SUM(tokens_output), 0)           AS tokens_out,
                    COALESCE(SUM(tokens_input_cached), 0)     AS tokens_in_cached,
                    COALESCE(SUM(tokens_output_reasoning), 0) AS tokens_out_reasoning,
                    COALESCE(SUM(IF(source = ?, quantity, 0)), 0) AS web_searches
             FROM usage_logs
             WHERE user_id = ? AND created_at BETWEEN ? AND ?'
        );
        $tok->execute([UsageSource::WEB_SEARCH, $userId, $monthStart, $monthEnd . ' 23:59:59']);
        $t = $tok->fetch(PDO::FETCH_ASSOC) ?: [];

        $tokensIn  = (int)($t['tokens_in'] ?? 0);
        $tokensOut = (int)($t['tokens_out'] ?? 0);

        return [
            'total_credits'          => $total,
            'quota_credits'          => $total - $exempt,
            'exempt_credits'         => $exempt,
            'total_cost_eur'         => round($cost, 6),
            'requests'               => $requests,
            'tokens_input'           => $tokensIn,
            'tokens_output'          => $tokensOut,
            'tokens_input_cached'    => (int)($t['tokens_in_cached'] ?? 0),
            'tokens_output_reasoning' => (int)($t['tokens_out_reasoning'] ?? 0),
            'tokens_total'           => $tokensIn + $tokensOut,
            'web_searches'           => (int)round((float)($t['web_searches'] ?? 0)),
            'unpriced_ops'           => $unpriced,
            'categories'             => $categories,
        ];
    }

    /**
     * Répartition par modèle (fournisseur · modèle) sur une période : requêtes,
     * crédits, coût réel, tokens détaillés. Les lignes sans tokens (recherches
     * web, dictée) sont incluses : elles coûtent aussi.
     *
     * @return list<array{provider:string, model:string, requests:int, credits:int, cost_eur:float,
     *                    tokens_in:int, tokens_out:int, tokens_in_cached:int, tokens_out_reasoning:int}>
     */
    public static function byModel(?string $from, ?string $to, ?int $userId = null): array
    {
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId);
        $stmt = Database::getInstance()->prepare(
            "SELECT llm_provider, model,
                    COUNT(*)                                  AS requests,
                    COALESCE(SUM(credits_used), 0)            AS credits,
                    COALESCE(SUM(cost_eur), 0)                AS cost_eur,
                    COALESCE(SUM(tokens_input), 0)            AS tokens_in,
                    COALESCE(SUM(tokens_output), 0)           AS tokens_out,
                    COALESCE(SUM(tokens_input_cached), 0)     AS tokens_in_cached,
                    COALESCE(SUM(tokens_output_reasoning), 0) AS tokens_out_reasoning
             FROM usage_logs $where
             GROUP BY llm_provider, model
             ORDER BY credits DESC"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$provider, $model] = self::splitSlug((string)$row['llm_provider'], (string)($row['model'] ?? ''));
            $rows[] = [
                'provider'             => $provider,
                'model'                => $model,
                'requests'             => (int)$row['requests'],
                'credits'              => (int)$row['credits'],
                'cost_eur'             => (float)$row['cost_eur'],
                'tokens_in'            => (int)$row['tokens_in'],
                'tokens_out'           => (int)$row['tokens_out'],
                'tokens_in_cached'     => (int)$row['tokens_in_cached'],
                'tokens_out_reasoning' => (int)$row['tokens_out_reasoning'],
            ];
        }
        return $rows;
    }

    /**
     * Opérations les plus coûteuses (LLM + outils), triées par crédits.
     *
     * @return list<array{op_type:string, source:string, op_name:string, model:string, credits_used:int,
     *                    cost_eur:float, tokens_total:int, quantity:float, created_at:string}>
     */
    public static function topOperations(?string $from, ?string $to, ?int $userId, int $limit = 10, bool $quotaOnly = false): array
    {
        // $quotaOnly : seulement ce qui entame un quota (Réglages → Usage & crédits) —
        // la maintenance hors quota d'un créateur noierait sinon son top 10.
        $exempt = $quotaOnly ? ' AND %s.quota_exempt = 0' : '';
        $db = Database::getInstance();
        $limit = max(1, min(100, $limit));

        [$where, $params] = self::usagePeriodWhere($from, $to, $userId, 'l');
        $llm = $db->prepare(
            "SELECT 'llm' AS op_type, l.source, l.llm_provider AS op_name, COALESCE(l.model, '') AS model,
                    l.credits_used, l.cost_eur, (l.tokens_input + l.tokens_output) AS tokens_total, l.quantity, l.created_at,
                    l.conversation_id, p.name AS profile_name
             FROM usage_logs l LEFT JOIN profiles p ON p.id = l.profile_id
             $where " . ($where !== '' ? 'AND' : 'WHERE') . " l.credits_used > 0" . sprintf($exempt, 'l') . "
             ORDER BY l.credits_used DESC LIMIT $limit"
        );
        $llm->execute($params);
        $rows = $llm->fetchAll(PDO::FETCH_ASSOC);

        [$whereT, $paramsT] = self::usagePeriodWhere($from, $to, $userId, 't');
        $tool = $db->prepare(
            "SELECT 'tool' AS op_type, t.source, COALESCE(ct.name, CONCAT('Outil #', t.tool_id)) AS op_name,
                    '' AS model, t.credits_used, t.cost_eur, 0 AS tokens_total, t.quantity_units AS quantity, t.created_at,
                    t.conversation_id, p.name AS profile_name, ct.type AS tool_type
             FROM tool_usage_logs t
             LEFT JOIN chat_tools ct ON t.tool_id = ct.id
             LEFT JOIN profiles p ON p.id = t.profile_id
             $whereT " . ($whereT !== '' ? 'AND' : 'WHERE') . " t.credits_used > 0" . sprintf($exempt, 't') . "
             ORDER BY t.credits_used DESC LIMIT $limit"
        );
        $tool->execute($paramsT);
        foreach ($tool->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $row;
        }

        usort($rows, static fn(array $a, array $b): int => ((int)$b['credits_used'] <=> (int)$a['credits_used'])
            ?: strcmp((string)$b['created_at'], (string)$a['created_at']));
        $rows = array_slice($rows, 0, $limit);

        return array_map(static fn(array $r): array => [
            'op_type'      => (string)$r['op_type'],
            'source'       => (string)$r['source'],
            'op_name'      => (string)$r['op_name'],
            'model'        => (string)$r['model'],
            'credits_used' => (int)$r['credits_used'],
            'cost_eur'     => (float)$r['cost_eur'],
            'tokens_total' => (int)$r['tokens_total'],
            'quantity'     => (float)$r['quantity'],
            'created_at'   => (string)$r['created_at'],
            // Pour rouvrir l'opération et nommer son assistant (Réglages → Usage & crédits).
            'conversation_id' => isset($r['conversation_id']) ? (int)$r['conversation_id'] : null,
            'profile_name'    => isset($r['profile_name']) ? (string)$r['profile_name'] : null,
            'tool_type'       => isset($r['tool_type']) ? (string)$r['tool_type'] : null,
        ], $rows);
    }

    /**
     * Crédits qui entament le quota (`quota_exempt = 0`), par assistant (les
     * deux tables : une image générée dans une conversation d'assistant compte
     * pour lui) et par modèle (opérations IA seulement). Anneaux de
     * Réglages → Usage & crédits : même base que le solde affiché au-dessus.
     *
     * @return array{profiles:list<array{profile_id:?int, name:?string, color:?string, credits:int}>,
     *               models:list<array{provider:string, model:string, credits:int}>}
     */
    public static function quotaCreditsSplit(?string $from, ?string $to, ?int $userId = null): array
    {
        $db = Database::getInstance();
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId);
        $where = ($where !== '' ? $where . ' AND' : 'WHERE') . ' quota_exempt = 0';
        $stmt = $db->prepare(
            "SELECT t.profile_id, p.name, p.color, SUM(t.credits) AS credits
             FROM (SELECT profile_id, credits_used AS credits FROM usage_logs $where
                   UNION ALL
                   SELECT profile_id, credits_used AS credits FROM tool_usage_logs $where) t
             LEFT JOIN profiles p ON p.id = t.profile_id
             GROUP BY t.profile_id, p.name, p.color
             HAVING credits > 0 ORDER BY credits DESC"
        );
        $stmt->execute(array_merge($params, $params));
        $profiles = array_map(static fn(array $r): array => [
            'profile_id' => $r['profile_id'] !== null ? (int)$r['profile_id'] : null,
            'name'       => $r['name'] !== null ? (string)$r['name'] : null,
            'color'      => $r['color'] !== null ? (string)$r['color'] : null,
            'credits'    => (int)$r['credits'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $db->prepare(
            "SELECT llm_provider, model, SUM(credits_used) AS credits FROM usage_logs $where
             GROUP BY llm_provider, model HAVING credits > 0 ORDER BY credits DESC"
        );
        $stmt->execute($params);
        $models = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$provider, $model] = self::splitSlug((string)$row['llm_provider'], (string)($row['model'] ?? ''));
            $models[] = ['provider' => $provider, 'model' => $model, 'credits' => (int)$row['credits']];
        }
        return ['profiles' => $profiles, 'models' => $models];
    }

    /**
     * Réponses de conversation par modèle (sources chat + regenerate) : nombre
     * et crédits, d'où le coût moyen d'une réponse — que les titres, la
     * mémoire ou les embeddings, comptés par `byModel`, fausseraient.
     *
     * @return list<array{provider:string, model:string, responses:int, credits:int}>
     */
    public static function responsesByModel(?string $from, ?string $to, ?int $userId = null): array
    {
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId);
        $where = ($where !== '' ? $where . ' AND' : 'WHERE') . " source IN ('" . UsageSource::CHAT . "', '" . UsageSource::REGENERATE . "')";
        $stmt = Database::getInstance()->prepare(
            "SELECT llm_provider, model, COUNT(*) AS responses, COALESCE(SUM(credits_used), 0) AS credits
             FROM usage_logs $where GROUP BY llm_provider, model ORDER BY credits DESC"
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$provider, $model] = self::splitSlug((string)$row['llm_provider'], (string)($row['model'] ?? ''));
            $rows[] = ['provider' => $provider, 'model' => $model, 'responses' => (int)$row['responses'], 'credits' => (int)$row['credits']];
        }
        return $rows;
    }

    /** Dernière activité d'un utilisateur (LLM ou outil), ou null. */
    public static function lastActivityForUser(int $userId): ?string
    {
        $db = Database::getInstance();
        $last = null;
        foreach (['usage_logs', 'tool_usage_logs'] as $table) {
            $stmt = $db->prepare("SELECT MAX(created_at) FROM $table WHERE user_id = ?");
            $stmt->execute([$userId]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && $value !== '' && ($last === null || $value > $last)) {
                $last = $value;
            }
        }
        return $last;
    }

    // =====================================================================
    // Liste des utilisateurs (admin)
    // =====================================================================

    /**
     * Crédits cumulés (historique complet) comptant pour le quota, indexés
     * par user_id. Les lignes d'exploitation (hors quota) sont exclues :
     * cette colonne se lit face au quota de la fiche.
     *
     * @return array<int, int>
     */
    public static function creditsByUser(): array
    {
        return self::quotaCreditsByUser(null);
    }

    /**
     * Crédits du mois calendaire en cours comptant pour le quota, indexés par
     * user_id. Agrégat unique (2 GROUP BY) — remplace un appel par ligne dans
     * la liste admin des utilisateurs (N+1).
     *
     * @return array<int, int>
     */
    public static function creditsThisMonthByUser(): array
    {
        return self::quotaCreditsByUser(date('Y-m-01 00:00:00'));
    }

    /**
     * Crédits comptant pour le quota sur une période [from, until[, indexés par
     * user_id (statistiques « mois dernier » de la page Budget).
     *
     * @return array<int, int>
     */
    public static function quotaCreditsByUserBetween(string $from, string $until): array
    {
        return self::quotaCreditsByUser($from, $until);
    }

    /**
     * Coût réel fournisseur sur [from, until[ — TOUTES les lignes des deux
     * tables (maintenance et opérations d'instance comprises), séparées en
     * « usage des comptes » (quota_exempt = 0) et « maintenance ». Requête
     * lean du budget IA : ni catégorie, ni jointure, servie par les index
     * couvrants de la migration 076.
     *
     * @return array{usage_eur:float, maintenance_eur:float, unpriced_ops:int, operations:int}
     */
    public static function costBetween(string $from, string $until): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN t.quota_exempt = 0 THEN t.cost_eur ELSE 0 END), 0) AS usage_eur,
                    COALESCE(SUM(CASE WHEN t.quota_exempt = 1 THEN t.cost_eur ELSE 0 END), 0) AS maintenance_eur,
                    COALESCE(SUM(CASE WHEN t.priced = 0 THEN 1 ELSE 0 END), 0) AS unpriced_ops,
                    COUNT(*) AS operations
             FROM (
                SELECT quota_exempt, priced, cost_eur FROM usage_logs
                 WHERE created_at >= ? AND created_at < ?
                UNION ALL
                SELECT quota_exempt, priced, cost_eur FROM tool_usage_logs
                 WHERE created_at >= ? AND created_at < ?
             ) t'
        );
        $stmt->execute([$from, $until, $from, $until]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'usage_eur'       => round((float)($row['usage_eur'] ?? 0), 6),
            'maintenance_eur' => round((float)($row['maintenance_eur'] ?? 0), 6),
            'unpriced_ops'    => (int)($row['unpriced_ops'] ?? 0),
            'operations'      => (int)($row['operations'] ?? 0),
        ];
    }

    /**
     * Coût réel fournisseur par JOUR (UTC, comme la fenêtre du budget), les deux
     * tables confondues. Trajectoire de la page Budget : un GROUP BY par table,
     * servi par les index couvrants de la migration 076 (created_at … cost_eur).
     * Les jours sans opération sont absents.
     *
     * @return array<string, float> 'Y-m-d' → euros, dans l'ordre des jours
     */
    public static function dailyCostBetween(string $from, string $until): array
    {
        $db  = Database::getInstance();
        $map = [];
        foreach (['usage_logs', 'tool_usage_logs'] as $table) {
            $stmt = $db->prepare(
                "SELECT DATE(created_at) AS d, COALESCE(SUM(cost_eur), 0) AS eur
                 FROM $table
                 WHERE created_at >= ? AND created_at < ?
                 GROUP BY DATE(created_at)"
            );
            $stmt->execute([$from, $until]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $day = (string)$row['d'];
                $map[$day] = round(($map[$day] ?? 0.0) + (float)$row['eur'], 6);
            }
        }
        ksort($map);
        return $map;
    }

    /**
     * Coût réel par FOURNISSEUR d'IA (UTC, bornes [from, until[), les deux
     * tables confondues : le fournisseur d'un appel est le préfixe de son slug
     * (`openai::gpt-5.6` → `openai`), celui d'un outil son fournisseur déclaré
     * (`chat_tools.provider`) ; un outil supprimé depuis se range en `other`.
     * Section « Dépenses » de la page Budget : ce que chacun facturera.
     *
     * @return list<array{provider:string, cost_eur:float, operations:int}>
     */
    public static function costByProvider(string $from, string $until): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT t.provider, COALESCE(SUM(t.cost_eur), 0) AS cost_eur, COALESCE(SUM(t.ops), 0) AS operations
             FROM (
                SELECT SUBSTRING_INDEX(llm_provider, '::', 1) AS provider, SUM(cost_eur) AS cost_eur, COUNT(*) AS ops
                  FROM usage_logs
                 WHERE created_at >= ? AND created_at < ?
                 GROUP BY SUBSTRING_INDEX(llm_provider, '::', 1)
                UNION ALL
                SELECT COALESCE(NULLIF(ct.provider, ''), 'other') AS provider, SUM(tl.cost_eur) AS cost_eur, COUNT(*) AS ops
                  FROM tool_usage_logs tl
                  LEFT JOIN chat_tools ct ON ct.id = tl.tool_id
                 WHERE tl.created_at >= ? AND tl.created_at < ?
                 GROUP BY COALESCE(NULLIF(ct.provider, ''), 'other')
             ) t
             GROUP BY t.provider"
        );
        $stmt->execute([$from, $until, $from, $until]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $provider = strtolower(trim((string)$row['provider']));
            $out[] = [
                'provider'   => $provider !== '' ? $provider : 'other',
                'cost_eur'   => round((float)$row['cost_eur'], 6),
                'operations' => (int)$row['operations'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['cost_eur'] <=> $a['cost_eur']);
        return $out;
    }

    /**
     * Premier jour d'activité du grand livre ('Y-m-d', les deux tables), ou
     * null s'il est vide : début de la tranche « Tout » de la page Budget.
     * Deux MIN servis par l'index sur `created_at`.
     */
    public static function firstActivityDay(): ?string
    {
        $row = Database::getInstance()->query(
            'SELECT LEAST(COALESCE((SELECT MIN(created_at) FROM usage_logs), \'9999-12-31\'),
                          COALESCE((SELECT MIN(created_at) FROM tool_usage_logs), \'9999-12-31\')) AS first'
        )->fetch(PDO::FETCH_ASSOC);
        $first = substr((string)($row['first'] ?? ''), 0, 10);
        return ($first === '' || $first === '9999-12-31') ? null : $first;
    }

    /** @return array<int, int> */
    private static function quotaCreditsByUser(?string $since, ?string $until = null): array
    {
        $db  = Database::getInstance();
        $map = [];
        foreach (['usage_logs', 'tool_usage_logs'] as $table) {
            $sql = "SELECT user_id, COALESCE(SUM(credits_used), 0) AS credits
                    FROM $table
                    WHERE user_id IS NOT NULL AND quota_exempt = 0" . ($since !== null ? ' AND created_at >= ?' : '')
                    . ($until !== null ? ' AND created_at < ?' : '') . '
                    GROUP BY user_id';
            $params = [];
            if ($since !== null) { $params[] = $since; }
            if ($until !== null) { $params[] = $until; }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $uid = (int)$row['user_id'];
                $map[$uid] = ($map[$uid] ?? 0) + (int)$row['credits'];
            }
        }
        return $map;
    }

    /**
     * Dernière activité (dernier log LLM ou outil), indexée par user_id.
     * Repère les comptes dormants dans la liste admin des utilisateurs.
     *
     * @return array<int, string> user_id → datetime 'Y-m-d H:i:s'
     */
    public static function lastActivityByUser(): array
    {
        $db  = Database::getInstance();
        $map = [];
        foreach (['usage_logs', 'tool_usage_logs'] as $table) {
            $rows = $db->query(
                "SELECT user_id, MAX(created_at) AS last_at FROM $table WHERE user_id IS NOT NULL GROUP BY user_id"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $uid  = (int)$row['user_id'];
                $last = (string)$row['last_at'];
                if (!isset($map[$uid]) || $last > $map[$uid]) {
                    $map[$uid] = $last;
                }
            }
        }
        return $map;
    }

    // =====================================================================
    // Export brut
    // =====================================================================

    public static function query(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId, 'l');
        $stmt = Database::getInstance()->prepare(
            "SELECT u.name as user_name, u.email, p.name as profile_name,
                    l.source, l.llm_provider, l.model,
                    l.tokens_input, l.tokens_output,
                    l.tokens_input + l.tokens_output as total_tokens,
                    l.quantity, l.credits_used, l.cost_eur, l.priced, l.quota_exempt, l.created_at
             FROM usage_logs l
             LEFT JOIN users u ON l.user_id = u.id
             LEFT JOIN profiles p ON l.profile_id = p.id
             $where
             ORDER BY l.created_at DESC
             LIMIT 500"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // =====================================================================
    // Répartitions (page Usages)
    // =====================================================================

    /**
     * Répartition par fournisseur LLM (toutes sources d'`usage_logs`).
     *
     * @return list<array{llm_provider:string, requests:int, total_tokens:int, credits:int, cost_eur:float}>
     */
    public static function byProvider(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId);
        $stmt = Database::getInstance()->prepare(
            "SELECT llm_provider,
                    COUNT(*) as requests,
                    SUM(COALESCE(tokens_input, 0) + COALESCE(tokens_output, 0)) as total_tokens,
                    COALESCE(SUM(credits_used), 0) AS credits,
                    COALESCE(SUM(cost_eur), 0)     AS cost_eur
             FROM usage_logs $where
             GROUP BY llm_provider"
        );
        $stmt->execute($params);

        // Normalise les slugs : "openai::gpt-4o" → provider "openai" afin de
        // fusionner ancien format ("openai") et nouveau ("openai::gpt-4o").
        $merged = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$provider] = self::splitSlug((string)$row['llm_provider'], '');
            if (!isset($merged[$provider])) {
                $merged[$provider] = ['llm_provider' => $provider, 'requests' => 0, 'total_tokens' => 0, 'credits' => 0, 'cost_eur' => 0.0];
            }
            $merged[$provider]['requests']     += (int)$row['requests'];
            $merged[$provider]['total_tokens'] += (int)$row['total_tokens'];
            $merged[$provider]['credits']      += (int)$row['credits'];
            $merged[$provider]['cost_eur']     += (float)$row['cost_eur'];
        }

        usort($merged, static fn(array $a, array $b): int => ($b['credits'] <=> $a['credits']) ?: ($b['total_tokens'] <=> $a['total_tokens']));
        return array_values($merged);
    }

    /**
     * Répartition par assistant (lignes d'`usage_logs` portant un profil).
     *
     * @return list<array{profile_id:int, profile_name:string, profile_color:string, profile_avatar:string,
     *                    requests:int, total_tokens:int, credits:int, cost_eur:float}>
     */
    public static function byProfile(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId, 'l');
        $where = ($where !== '' ? $where . ' AND' : 'WHERE') . ' l.profile_id IS NOT NULL';

        $stmt = Database::getInstance()->prepare(
            "SELECT l.profile_id,
                    COALESCE(p.name, CONCAT('Assistant supprimé #', l.profile_id)) as profile_name,
                    COALESCE(p.color, '') as profile_color,
                    COALESCE(p.avatar, '') as profile_avatar,
                    COUNT(*) as requests,
                    SUM(COALESCE(l.tokens_input, 0) + COALESCE(l.tokens_output, 0)) as total_tokens,
                    COALESCE(SUM(l.credits_used), 0) AS credits,
                    COALESCE(SUM(l.cost_eur), 0)     AS cost_eur
             FROM usage_logs l
             LEFT JOIN profiles p ON l.profile_id = p.id
             $where
             GROUP BY l.profile_id, p.name, p.color, p.avatar
             ORDER BY credits DESC, total_tokens DESC"
        );
        $stmt->execute($params);
        return array_map(static fn(array $r): array => [
            'profile_id'     => (int)$r['profile_id'],
            'profile_name'   => (string)$r['profile_name'],
            'profile_color'  => (string)$r['profile_color'],
            'profile_avatar' => (string)$r['profile_avatar'],
            'requests'       => (int)$r['requests'],
            'total_tokens'   => (int)$r['total_tokens'],
            'credits'        => (int)$r['credits'],
            'cost_eur'       => (float)$r['cost_eur'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Répartition par outil (`tool_usage_logs`).
     *
     * @return list<array{tool_id:int, tool_name:string, tool_type:string, uses:int, credits:int, cost_eur:float}>
     */
    public static function byTool(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId, 't');
        $stmt = Database::getInstance()->prepare(
            "SELECT t.tool_id,
                    COALESCE(ct.name, CONCAT('Outil supprimé #', t.tool_id)) as tool_name,
                    COALESCE(ct.type, t.tool_type) as tool_type,
                    COUNT(*) as uses,
                    COALESCE(SUM(t.credits_used), 0) AS credits,
                    COALESCE(SUM(t.cost_eur), 0)     AS cost_eur
             FROM tool_usage_logs t
             LEFT JOIN chat_tools ct ON t.tool_id = ct.id
             $where
             GROUP BY t.tool_id, ct.name, ct.type, t.tool_type
             ORDER BY credits DESC, uses DESC"
        );
        $stmt->execute($params);
        return array_map(static fn(array $r): array => [
            'tool_id'   => (int)$r['tool_id'],
            'tool_name' => (string)$r['tool_name'],
            'tool_type' => (string)$r['tool_type'],
            'uses'      => (int)$r['uses'],
            'credits'   => (int)$r['credits'],
            'cost_eur'  => (float)$r['cost_eur'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Répartition par catégorie puis par source (UsageSource), tables
     * fusionnées. Toutes les catégories sont présentes (à zéro si vides),
     * dans l'ordre d'affichage ; les sources ne sont listées que si elles
     * ont des lignes.
     *
     * @return list<array{category:string, label:string, description:string, color:string, family:string,
     *                    quota_exempt:bool, requests:int, credits:int, cost_eur:float, tokens:int,
     *                    quantity:float, unpriced_ops:int,
     *                    sources:list<array{source:string, label:string, unit:string, kind:string, requests:int,
     *                                        credits:int, cost_eur:float, tokens:int, quantity:float, unpriced_ops:int}>}>
     */
    public static function byCategory(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$union, $params] = self::ledgerUnion($from, $to, $userId);
        $stmt = Database::getInstance()->prepare(
            "SELECT t.category, t.source, t.kind,
                    COUNT(*)                                 AS requests,
                    COALESCE(SUM(t.credits), 0)              AS credits,
                    COALESCE(SUM(t.cost_eur), 0)             AS cost_eur,
                    COALESCE(SUM(t.tokens), 0)               AS tokens,
                    COALESCE(SUM(t.quantity), 0)             AS quantity,
                    COALESCE(SUM(IF(t.priced = 0, 1, 0)), 0) AS unpriced_ops
             FROM ($union) t
             GROUP BY t.category, t.source, t.kind"
        );
        $stmt->execute($params);

        $out = [];
        foreach (UsageSource::categories() as $cat) {
            $out[$cat] = [
                'category'     => $cat,
                'label'        => UsageSource::categoryLabel($cat),
                'description'  => UsageSource::categoryDescription($cat),
                'color'        => UsageSource::categoryColor($cat),
                'family'       => UsageSource::categoryFamily($cat),
                'quota_exempt' => UsageSource::isCategoryQuotaExempt($cat),
                'requests' => 0, 'credits' => 0, 'cost_eur' => 0.0, 'tokens' => 0, 'quantity' => 0.0, 'unpriced_ops' => 0,
                'sources'  => [],
            ];
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cat = (string)$row['category'];
            if (!isset($out[$cat])) {
                continue;
            }
            $kind   = (string)$row['kind'];
            $source = (string)$row['source'];
            $entry = [
                'source'       => $source,
                'label'        => $kind === 'tool'
                    ? ($source === UsageSource::TOOL_TEST ? t('Tests d\'outils (générations)') : t('Images, vidéos et usages d\'outils'))
                    : UsageSource::label($source),
                'unit'         => $kind === 'tool' ? 'units' : UsageSource::unit($source),
                'kind'         => $kind,
                'requests'     => (int)$row['requests'],
                'credits'      => (int)$row['credits'],
                'cost_eur'     => (float)$row['cost_eur'],
                'tokens'       => (int)$row['tokens'],
                'quantity'     => (float)$row['quantity'],
                'unpriced_ops' => (int)$row['unpriced_ops'],
            ];
            $out[$cat]['sources'][]     = $entry;
            $out[$cat]['requests']     += $entry['requests'];
            $out[$cat]['credits']      += $entry['credits'];
            $out[$cat]['cost_eur']     += $entry['cost_eur'];
            $out[$cat]['tokens']       += $entry['tokens'];
            $out[$cat]['quantity']     += $entry['quantity'];
            $out[$cat]['unpriced_ops'] += $entry['unpriced_ops'];
        }
        foreach ($out as &$cat) {
            usort($cat['sources'], static fn(array $a, array $b): int => $b['credits'] <=> $a['credits']);
        }
        unset($cat);
        return array_values($out);
    }

    /**
     * Coût d'alimentation par base de connaissances (lignes `kb_id`), toutes
     * sources confondues (chunking, vision, embeddings, questions).
     *
     * @return list<array{kb_id:?int, kb_name:string, requests:int, credits:int, cost_eur:float, tokens:int}>
     */
    public static function byKnowledgeBase(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId, 'l');
        $where = ($where !== '' ? $where . ' AND' : 'WHERE') . ' l.kb_id IS NOT NULL';
        $stmt = Database::getInstance()->prepare(
            "SELECT l.kb_id,
                    COALESCE(kb.name, CONCAT('Base supprimée #', l.kb_id)) AS kb_name,
                    COUNT(*)                       AS requests,
                    COALESCE(SUM(l.credits_used), 0) AS credits,
                    COALESCE(SUM(l.cost_eur), 0)     AS cost_eur,
                    COALESCE(SUM(l.tokens_input + l.tokens_output), 0) AS tokens
             FROM usage_logs l
             LEFT JOIN knowledge_bases kb ON kb.id = l.kb_id
             $where
             GROUP BY l.kb_id, kb.name
             ORDER BY credits DESC, tokens DESC"
        );
        $stmt->execute($params);
        return array_map(static fn(array $r): array => [
            'kb_id'    => $r['kb_id'] !== null ? (int)$r['kb_id'] : null,
            'kb_name'  => (string)$r['kb_name'],
            'requests' => (int)$r['requests'],
            'credits'  => (int)$r['credits'],
            'cost_eur' => (float)$r['cost_eur'],
            'tokens'   => (int)$r['tokens'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Opérations consommées SANS tarif trouvable, regroupées par service
     * (fournisseur · modèle ou outil) — à afficher avec un lien vers l'écran
     * Crédits. Vide = tout est tarifé.
     *
     * @return list<array{kind:string, name:string, source:string, ops:int, credits:int}>
     */
    public static function unpricedOperations(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        $db = Database::getInstance();
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId);
        $where = ($where !== '' ? $where . ' AND' : 'WHERE') . ' priced = 0';
        $stmt = $db->prepare(
            "SELECT 'llm' AS kind, llm_provider AS name, source, COUNT(*) AS ops, COALESCE(SUM(credits_used), 0) AS credits
             FROM usage_logs $where
             GROUP BY llm_provider, source"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        [$whereT, $paramsT] = self::usagePeriodWhere($from, $to, $userId, 't');
        $whereT = ($whereT !== '' ? $whereT . ' AND' : 'WHERE') . ' t.priced = 0';
        $tool = $db->prepare(
            "SELECT 'tool' AS kind, COALESCE(ct.name, CONCAT('Outil #', t.tool_id)) AS name, t.source,
                    COUNT(*) AS ops, COALESCE(SUM(t.credits_used), 0) AS credits
             FROM tool_usage_logs t
             LEFT JOIN chat_tools ct ON ct.id = t.tool_id
             $whereT
             GROUP BY t.tool_id, ct.name, t.source"
        );
        $tool->execute($paramsT);
        foreach ($tool->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $row;
        }

        usort($rows, static fn(array $a, array $b): int => (int)$b['ops'] <=> (int)$a['ops']);
        return array_map(static fn(array $r): array => [
            'kind'    => (string)$r['kind'],
            'name'    => (string)$r['name'],
            'source'  => (string)$r['source'],
            'ops'     => (int)$r['ops'],
            'credits' => (int)$r['credits'],
        ], $rows);
    }

    /**
     * Détail consolidé par utilisateur (onglet Répartition) : requêtes, tokens,
     * dernière activité, puis répartition LLM / assistants / outils.
     */
    public static function detailByUser(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        $db = Database::getInstance();

        [$usageWhere, $usageParams] = self::usagePeriodWhere($from, $to, $userId, 'l');
        $usageWhere = ($usageWhere !== '' ? $usageWhere . ' AND' : 'WHERE') . ' l.user_id IS NOT NULL';

        $users = [];
        $stmt = $db->prepare(
            "SELECT l.user_id,
                    COALESCE(u.name, CONCAT('Utilisateur #', l.user_id)) as user_name,
                    COALESCE(u.email, '') as email,
                    COUNT(*) as requests,
                    SUM(COALESCE(l.tokens_input, 0)) as tokens_input,
                    SUM(COALESCE(l.tokens_output, 0)) as tokens_output,
                    SUM(COALESCE(l.tokens_input, 0) + COALESCE(l.tokens_output, 0)) as total_tokens,
                    COALESCE(SUM(l.credits_used), 0) as credits,
                    MAX(l.created_at) as last_activity
             FROM usage_logs l
             LEFT JOIN users u ON l.user_id = u.id
             $usageWhere
             GROUP BY l.user_id, u.name, u.email"
        );
        $stmt->execute($usageParams);
        foreach ($stmt->fetchAll() as $row) {
            $uid = (int)$row['user_id'];
            $users[$uid] = [
                'user_id'          => $uid,
                'user_name'        => $row['user_name'],
                'email'            => $row['email'],
                'requests'         => (int)$row['requests'],
                'tokens_input'     => (int)$row['tokens_input'],
                'tokens_output'    => (int)$row['tokens_output'],
                'total_tokens'     => (int)$row['total_tokens'],
                'credits'          => (int)$row['credits'],
                'last_activity'    => (string)$row['last_activity'],
                'llm_breakdown'    => [],
                'profile_breakdown'=> [],
                'tool_breakdown'   => [],
            ];
        }

        $stmt = $db->prepare(
            "SELECT l.user_id,
                    l.llm_provider,
                    COUNT(*) as requests,
                    SUM(COALESCE(l.tokens_input, 0) + COALESCE(l.tokens_output, 0)) as total_tokens
             FROM usage_logs l
             $usageWhere
             GROUP BY l.user_id, l.llm_provider"
        );
        $stmt->execute($usageParams);
        foreach ($stmt->fetchAll() as $row) {
            $uid = (int)$row['user_id'];
            if (!isset($users[$uid])) {
                continue;
            }
            [$provider] = self::splitSlug((string)$row['llm_provider'], '');
            if (!isset($users[$uid]['llm_breakdown'][$provider])) {
                $users[$uid]['llm_breakdown'][$provider] = [
                    'provider'     => $provider,
                    'requests'     => 0,
                    'total_tokens' => 0,
                ];
            }
            $users[$uid]['llm_breakdown'][$provider]['requests'] += (int)$row['requests'];
            $users[$uid]['llm_breakdown'][$provider]['total_tokens'] += (int)$row['total_tokens'];
        }

        $profileWhere = $usageWhere . ' AND l.profile_id IS NOT NULL';
        $stmt = $db->prepare(
            "SELECT l.user_id,
                    l.profile_id,
                    COALESCE(p.name, CONCAT('Assistant supprimé #', l.profile_id)) as profile_name,
                    COUNT(*) as requests,
                    SUM(COALESCE(l.tokens_input, 0) + COALESCE(l.tokens_output, 0)) as total_tokens
             FROM usage_logs l
             LEFT JOIN profiles p ON l.profile_id = p.id
             $profileWhere
             GROUP BY l.user_id, l.profile_id, p.name"
        );
        $stmt->execute($usageParams);
        foreach ($stmt->fetchAll() as $row) {
            $uid = (int)$row['user_id'];
            if (!isset($users[$uid])) {
                continue;
            }
            $profileId = (int)$row['profile_id'];
            $users[$uid]['profile_breakdown'][$profileId] = [
                'profile_id'   => $profileId,
                'profile_name' => (string)$row['profile_name'],
                'requests'     => (int)$row['requests'],
                'total_tokens' => (int)$row['total_tokens'],
            ];
        }

        [$toolWhere, $toolParams] = self::usagePeriodWhere($from, $to, $userId, 't');
        $toolWhere = ($toolWhere !== '' ? $toolWhere . ' AND' : 'WHERE') . ' t.user_id IS NOT NULL';

        $toolUsersStmt = $db->prepare(
            "SELECT t.user_id,
                    COALESCE(u.name, CONCAT('Utilisateur #', t.user_id)) as user_name,
                    COALESCE(u.email, '') as email,
                    COALESCE(SUM(t.credits_used), 0) as credits,
                    MAX(t.created_at) as last_activity
             FROM tool_usage_logs t
             LEFT JOIN users u ON t.user_id = u.id
             $toolWhere
             GROUP BY t.user_id, u.name, u.email"
        );
        $toolUsersStmt->execute($toolParams);
        foreach ($toolUsersStmt->fetchAll() as $row) {
            $uid = (int)$row['user_id'];
            if (!isset($users[$uid])) {
                $users[$uid] = [
                    'user_id'           => $uid,
                    'user_name'         => $row['user_name'],
                    'email'             => $row['email'],
                    'requests'          => 0,
                    'tokens_input'      => 0,
                    'tokens_output'     => 0,
                    'total_tokens'      => 0,
                    'credits'           => 0,
                    'last_activity'     => (string)$row['last_activity'],
                    'llm_breakdown'     => [],
                    'profile_breakdown' => [],
                    'tool_breakdown'    => [],
                ];
            } elseif ((string)$row['last_activity'] > (string)$users[$uid]['last_activity']) {
                $users[$uid]['last_activity'] = (string)$row['last_activity'];
            }
            $users[$uid]['credits'] += (int)$row['credits'];
        }

        $toolStmt = $db->prepare(
            "SELECT t.user_id,
                    t.tool_id,
                    COALESCE(ct.name, CONCAT('Outil supprimé #', t.tool_id)) as tool_name,
                    COALESCE(ct.type, t.tool_type) as tool_type,
                    COUNT(*) as uses
             FROM tool_usage_logs t
             LEFT JOIN chat_tools ct ON t.tool_id = ct.id
             $toolWhere
             GROUP BY t.user_id, t.tool_id, ct.name, ct.type, t.tool_type"
        );
        $toolStmt->execute($toolParams);
        foreach ($toolStmt->fetchAll() as $row) {
            $uid = (int)$row['user_id'];
            if (!isset($users[$uid])) {
                continue;
            }
            $toolId = (int)$row['tool_id'];
            $users[$uid]['tool_breakdown'][$toolId] = [
                'tool_id'    => $toolId,
                'tool_name'  => (string)$row['tool_name'],
                'tool_type'  => (string)$row['tool_type'],
                'uses'       => (int)$row['uses'],
            ];
        }

        foreach ($users as &$user) {
            $user['llm_breakdown'] = array_values($user['llm_breakdown']);
            usort($user['llm_breakdown'], fn(array $a, array $b): int => $b['total_tokens'] <=> $a['total_tokens']);

            $user['profile_breakdown'] = array_values($user['profile_breakdown']);
            usort($user['profile_breakdown'], fn(array $a, array $b): int => $b['total_tokens'] <=> $a['total_tokens']);

            $user['tool_breakdown'] = array_values($user['tool_breakdown']);
            usort($user['tool_breakdown'], fn(array $a, array $b): int => $b['uses'] <=> $a['uses']);
        }
        unset($user);

        $rows = array_values($users);
        usort(
            $rows,
            fn(array $a, array $b): int => ($b['credits'] <=> $a['credits'])
                ?: ($b['total_tokens'] <=> $a['total_tokens'])
                ?: ($b['requests'] <=> $a['requests'])
                ?: strcmp((string)$a['user_name'], (string)$b['user_name'])
        );

        return $rows;
    }

    // =====================================================================
    // KPIs et séries (page Usages, tableau de bord, exports)
    // =====================================================================

    /**
     * KPIs période fusionnés LLM + outils. Agrégats BRUTS : `cost_eur` est le
     * coût réel fournisseur, la valorisation € se dérive des crédits via
     * `CreditService::creditsToEur()`.
     *
     * @return array{llm_credits:int, tool_credits:int, total_credits:int, quota_credits:int, exempt_credits:int,
     *               cost_eur:float, llm_requests:int, tool_requests:int, requests:int,
     *               active_users:int, tokens:int, unpriced_ops:int}
     */
    public static function periodTotals(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        $db = Database::getInstance();
        [$where, $params] = self::usagePeriodWhere($from, $to, $userId);

        // Sous-requêtes pré-agrégées par user pour un COUNT(DISTINCT) exact
        // à travers les deux tables sans matérialiser les lignes brutes.
        $branches = [
            "SELECT user_id,
                    COALESCE(SUM(credits_used), 0) AS llm_credits,
                    0 AS tool_credits,
                    COALESCE(SUM(IF(quota_exempt = 1, credits_used, 0)), 0) AS exempt_credits,
                    COALESCE(SUM(cost_eur), 0) AS cost_eur,
                    COUNT(*) AS llm_requests,
                    0 AS tool_requests,
                    COALESCE(SUM(tokens_input + tokens_output), 0) AS tokens,
                    COALESCE(SUM(IF(priced = 0, 1, 0)), 0) AS unpriced_ops
             FROM usage_logs $where GROUP BY user_id",
            "SELECT user_id,
                    0 AS llm_credits,
                    COALESCE(SUM(credits_used), 0) AS tool_credits,
                    COALESCE(SUM(IF(quota_exempt = 1, credits_used, 0)), 0) AS exempt_credits,
                    COALESCE(SUM(cost_eur), 0) AS cost_eur,
                    0 AS llm_requests,
                    COUNT(*) AS tool_requests,
                    0 AS tokens,
                    COALESCE(SUM(IF(priced = 0, 1, 0)), 0) AS unpriced_ops
             FROM tool_usage_logs $where GROUP BY user_id",
        ];

        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(t.llm_credits), 0)    AS llm_credits,
                    COALESCE(SUM(t.tool_credits), 0)   AS tool_credits,
                    COALESCE(SUM(t.exempt_credits), 0) AS exempt_credits,
                    COALESCE(SUM(t.cost_eur), 0)       AS cost_eur,
                    COALESCE(SUM(t.llm_requests), 0)   AS llm_requests,
                    COALESCE(SUM(t.tool_requests), 0)  AS tool_requests,
                    COUNT(DISTINCT t.user_id)          AS active_users,
                    COALESCE(SUM(t.tokens), 0)         AS tokens,
                    COALESCE(SUM(t.unpriced_ops), 0)   AS unpriced_ops
             FROM (' . implode(' UNION ALL ', $branches) . ') t'
        );
        $stmt->execute(array_merge($params, $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $llmCredits   = (int)($row['llm_credits'] ?? 0);
        $toolCredits  = (int)($row['tool_credits'] ?? 0);
        $exempt       = (int)($row['exempt_credits'] ?? 0);
        $llmRequests  = (int)($row['llm_requests'] ?? 0);
        $toolRequests = (int)($row['tool_requests'] ?? 0);

        return [
            'llm_credits'    => $llmCredits,
            'tool_credits'   => $toolCredits,
            'total_credits'  => $llmCredits + $toolCredits,
            'quota_credits'  => $llmCredits + $toolCredits - $exempt,
            'exempt_credits' => $exempt,
            'cost_eur'       => round((float)($row['cost_eur'] ?? 0), 6),
            'llm_requests'   => $llmRequests,
            'tool_requests'  => $toolRequests,
            'requests'       => $llmRequests + $toolRequests,
            'active_users'   => (int)($row['active_users'] ?? 0),
            'tokens'         => (int)($row['tokens'] ?? 0),
            'unpriced_ops'   => (int)($row['unpriced_ops'] ?? 0),
        ];
    }

    /**
     * Série mensuelle globale (ou d'un utilisateur) : crédits par famille
     * (UsageSource::families), crédits LLM/outils, coût réel, requêtes,
     * utilisateurs actifs, tokens. Mois 'YYYY-MM', tri ASC ; les mois sans
     * données ne sont pas présents (comblés par l'appelant).
     *
     * @return list<array{month:string, llm_credits:int, tool_credits:int, total_credits:int, exempt_credits:int,
     *                    cost_eur:float, requests:int, active_users:int, tokens:int, families:array<string, int>}>
     */
    public static function monthlySummary(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$union, $params] = self::ledgerUnion($from, $to, $userId);
        $stmt = Database::getInstance()->prepare(
            "SELECT DATE_FORMAT(t.created_at, '%Y-%m') AS ym,
                    t.category,
                    COALESCE(SUM(IF(t.kind = 'llm', t.credits, 0)), 0)   AS llm_credits,
                    COALESCE(SUM(IF(t.kind = 'tool', t.credits, 0)), 0)  AS tool_credits,
                    COALESCE(SUM(IF(t.quota_exempt = 1, t.credits, 0)), 0) AS exempt_credits,
                    COALESCE(SUM(t.cost_eur), 0) AS cost_eur,
                    COUNT(*)                     AS requests,
                    COUNT(DISTINCT t.user_id)    AS active_users,
                    COALESCE(SUM(t.tokens), 0)   AS tokens
             FROM ($union) t
             GROUP BY ym, t.category
             ORDER BY ym ASC"
        );
        $stmt->execute($params);

        // Les utilisateurs actifs d'un mois se comptent sur TOUTES les
        // catégories : un second passage, groupé par mois seul.
        $active = Database::getInstance()->prepare(
            "SELECT DATE_FORMAT(t.created_at, '%Y-%m') AS ym, COUNT(DISTINCT t.user_id) AS active_users
             FROM ($union) t GROUP BY ym"
        );
        $active->execute($params);
        $activeByMonth = [];
        foreach ($active->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $activeByMonth[(string)$row['ym']] = (int)$row['active_users'];
        }

        $months = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ym = (string)$row['ym'];
            if (!isset($months[$ym])) {
                $months[$ym] = self::emptyMonth($ym);
                $months[$ym]['active_users'] = $activeByMonth[$ym] ?? 0;
            }
            $family = UsageSource::categoryFamily((string)$row['category']);
            $llm  = (int)$row['llm_credits'];
            $tool = (int)$row['tool_credits'];
            $months[$ym]['llm_credits']    += $llm;
            $months[$ym]['tool_credits']   += $tool;
            $months[$ym]['total_credits']  += $llm + $tool;
            $months[$ym]['exempt_credits'] += (int)$row['exempt_credits'];
            $months[$ym]['cost_eur']        = round($months[$ym]['cost_eur'] + (float)$row['cost_eur'], 6);
            $months[$ym]['requests']       += (int)$row['requests'];
            $months[$ym]['tokens']         += (int)$row['tokens'];
            $months[$ym]['families'][$family] = ($months[$ym]['families'][$family] ?? 0) + $llm + $tool;
        }
        ksort($months);
        return array_values($months);
    }

    /** Ligne mensuelle à zéro, toutes familles présentes (pour combler les mois vides). */
    public static function emptyMonth(string $ym): array
    {
        $families = [];
        foreach (UsageSource::families() as $family) {
            $families[$family] = 0;
        }
        return [
            'month'          => $ym,
            'llm_credits'    => 0,
            'tool_credits'   => 0,
            'total_credits'  => 0,
            'exempt_credits' => 0,
            'cost_eur'       => 0.0,
            'requests'       => 0,
            'active_users'   => 0,
            'tokens'         => 0,
            'families'       => $families,
        ];
    }

    /**
     * Cumul période par utilisateur (LLM + outils). Les lignes user_id NULL
     * sont incluses (bucket « Instance / utilisateurs supprimés ») pour une
     * analyse de coûts exhaustive. Tri : crédits DESC puis requêtes DESC.
     *
     * @return list<array{user_id:?int, user_name:string, email:string,
     *                    llm_credits:int, tool_credits:int, total_credits:int, quota_credits:int, exempt_credits:int,
     *                    cost_eur:float, requests:int, last_activity:string}>
     */
    public static function periodTotalsByUser(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$union, $params] = self::ledgerUnion($from, $to, $userId);
        $stmt = Database::getInstance()->prepare(
            "SELECT t.user_id,
                    COALESCE(u.name, IF(t.user_id IS NULL, 'Instance / utilisateurs supprimés', CONCAT('Utilisateur #', t.user_id))) AS user_name,
                    COALESCE(u.email, '') AS email,
                    COALESCE(SUM(IF(t.kind = 'llm', t.credits, 0)), 0)  AS llm_credits,
                    COALESCE(SUM(IF(t.kind = 'tool', t.credits, 0)), 0) AS tool_credits,
                    COALESCE(SUM(t.credits), 0)                         AS total_credits,
                    COALESCE(SUM(IF(t.quota_exempt = 1, t.credits, 0)), 0) AS exempt_credits,
                    COALESCE(SUM(t.cost_eur), 0) AS cost_eur,
                    COUNT(*)                     AS requests,
                    MAX(t.created_at)            AS last_activity
             FROM ($union) t
             LEFT JOIN users u ON u.id = t.user_id
             GROUP BY t.user_id, u.name, u.email
             ORDER BY total_credits DESC, requests DESC"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total  = (int)$row['total_credits'];
            $exempt = (int)$row['exempt_credits'];
            $rows[] = [
                'user_id'        => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'user_name'      => (string)$row['user_name'],
                'email'          => (string)$row['email'],
                'llm_credits'    => (int)$row['llm_credits'],
                'tool_credits'   => (int)$row['tool_credits'],
                'total_credits'  => $total,
                'quota_credits'  => $total - $exempt,
                'exempt_credits' => $exempt,
                'cost_eur'       => round((float)$row['cost_eur'], 6),
                'requests'       => (int)$row['requests'],
                'last_activity'  => (string)$row['last_activity'],
            ];
        }
        return $rows;
    }

    /**
     * Lignes (utilisateur × mois) pour le détail mensuel dépliable et l'export
     * « par utilisateur ». Le cumul progressif est calculé en PHP par l'appelant.
     * Tri : user ASC (NULL en premier), mois ASC.
     *
     * @return list<array{user_id:?int, month:string, llm_credits:int, tool_credits:int,
     *                    total_credits:int, exempt_credits:int, cost_eur:float, requests:int}>
     */
    public static function monthlyByUser(?string $from = null, ?string $to = null, ?int $userId = null): array
    {
        [$union, $params] = self::ledgerUnion($from, $to, $userId);
        $stmt = Database::getInstance()->prepare(
            "SELECT t.user_id,
                    DATE_FORMAT(t.created_at, '%Y-%m') AS month,
                    COALESCE(SUM(IF(t.kind = 'llm', t.credits, 0)), 0)  AS llm_credits,
                    COALESCE(SUM(IF(t.kind = 'tool', t.credits, 0)), 0) AS tool_credits,
                    COALESCE(SUM(IF(t.quota_exempt = 1, t.credits, 0)), 0) AS exempt_credits,
                    COALESCE(SUM(t.cost_eur), 0) AS cost_eur,
                    COUNT(*)                     AS requests
             FROM ($union) t
             GROUP BY t.user_id, month
             ORDER BY t.user_id ASC, month ASC"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $llmCredits  = (int)$row['llm_credits'];
            $toolCredits = (int)$row['tool_credits'];
            $rows[] = [
                'user_id'        => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'month'          => (string)$row['month'],
                'llm_credits'    => $llmCredits,
                'tool_credits'   => $toolCredits,
                'total_credits'  => $llmCredits + $toolCredits,
                'exempt_credits' => (int)$row['exempt_credits'],
                'cost_eur'       => round((float)$row['cost_eur'], 6),
                'requests'       => (int)$row['requests'],
            ];
        }
        return $rows;
    }

    /**
     * « provider::model » (slug historique ou non) + colonne `model` →
     * [provider, model] pour l'affichage.
     *
     * @return array{0:string, 1:string}
     */
    private static function splitSlug(string $slug, string $model): array
    {
        $provider = $slug;
        if (str_contains($slug, '::')) {
            [$provider, $slugModel] = explode('::', $slug, 2);
            if ($model === '') {
                $model = $slugModel;
            }
        }
        return [strtolower(trim($provider)), trim($model)];
    }

    // =====================================================================
    // Backfill historique des outils
    // =====================================================================

    /**
     * Backfill estimatif des usages outils à partir des messages historiques.
     * Idempotent via la table de marqueurs `tool_usage_backfill_markers`.
     */
    public static function backfillEstimatedToolUsage(): array
    {
        $db = Database::getInstance();

        // Marqueurs idempotents (les messages déjà backfillés ne sont pas retraités).
        $db->exec(
            "CREATE TABLE IF NOT EXISTS tool_usage_backfill_markers (
                message_id          INT UNSIGNED NOT NULL,
                estimated_tool_id   INT UNSIGNED DEFAULT NULL,
                estimated_tool_type VARCHAR(50)  NOT NULL,
                created_at          DATETIME     NOT NULL,
                backfilled_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (message_id),
                CONSTRAINT fk_tubm_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_tool_usage_backfill_created ON tool_usage_backfill_markers(created_at)'
        );

        $toolRows = $db->query(
            'SELECT id, type, model, is_active, sort_order
             FROM chat_tools
             ORDER BY is_active DESC, sort_order ASC, id ASC'
        )->fetchAll();
        $toolsByType = [];
        foreach ($toolRows as $toolRow) {
            $type = trim((string)($toolRow['type'] ?? ''));
            if ($type === '') continue;
            $toolsByType[$type][] = [
                'id'    => (int)$toolRow['id'],
                'model' => trim((string)($toolRow['model'] ?? '')),
            ];
        }

        // Cutoff mémorisé : borne "début tracking fiable" avant le 1er backfill.
        $cutoff = trim((string)Settings::get('tool_usage_backfill_cutoff', ''));
        if ($cutoff === '') {
            $firstTracked = $db->query('SELECT MIN(created_at) FROM tool_usage_logs')->fetchColumn();
            if (!empty($firstTracked)) {
                $cutoff = (string)$firstTracked;
                Settings::set('tool_usage_backfill_cutoff', $cutoff);
            }
        }

        $where = 'WHERE m.role = "assistant" AND bm.message_id IS NULL';
        $params = [];
        if ($cutoff !== '') {
            $where .= ' AND m.created_at < ?';
            $params[] = $cutoff;
        }

        $stmt = $db->prepare(
            "SELECT m.id as message_id,
                    m.conversation_id,
                    m.content,
                    m.files_json,
                    m.created_at,
                    c.user_id,
                    c.profile_id
             FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             LEFT JOIN tool_usage_backfill_markers bm ON bm.message_id = m.id
             $where
             ORDER BY m.id ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $insertUsageStmt = $db->prepare(
            'INSERT INTO tool_usage_logs (user_id, conversation_id, profile_id, tool_id, tool_type, source, created_at)
             VALUES (?,?,?,?,?,?,?)'
        );
        $insertMarkerStmt = $db->prepare(
            'INSERT IGNORE INTO tool_usage_backfill_markers (message_id, estimated_tool_id, estimated_tool_type, created_at)
             VALUES (?,?,?,?)'
        );

        $stats = [
            'ok' => true,
            'message' => '',
            'cutoff' => $cutoff,
            'scanned' => 0,
            'matched' => 0,
            'inserted' => 0,
            'skipped_no_tool' => 0,
            'skipped_no_user' => 0,
        ];

        foreach ($rows as $row) {
            $stats['scanned']++;
            $content = (string)($row['content'] ?? '');
            $filesJson = (string)($row['files_json'] ?? '');
            $toolType = self::detectEstimatedToolType($content, $filesJson);
            if ($toolType === null) {
                continue;
            }
            $stats['matched']++;

            $toolId = self::resolveEstimatedToolId($toolType, $content, $toolsByType);
            if ($toolId === null) {
                $stats['skipped_no_tool']++;
                continue;
            }

            $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
            if ($userId <= 0) {
                $stats['skipped_no_user']++;
                continue;
            }

            $insertUsageStmt->execute([
                $userId,
                (int)$row['conversation_id'],
                isset($row['profile_id']) ? (int)$row['profile_id'] : null,
                $toolId,
                $toolType,
                UsageSource::TOOL_DEFAULT,
                (string)$row['created_at'],
            ]);
            $insertMarkerStmt->execute([
                (int)$row['message_id'],
                $toolId,
                $toolType,
                (string)$row['created_at'],
            ]);
            $stats['inserted']++;
        }

        $stats['message'] = t('Backfill terminé.');
        return $stats;
    }

    private static function detectEstimatedToolType(string $content, string $filesJson): ?string
    {
        $files = [];
        if ($filesJson !== '') {
            $decoded = json_decode($filesJson, true);
            if (is_array($decoded)) {
                $files = $decoded;
            }
        }

        foreach ($files as $file) {
            $type = trim((string)($file['type'] ?? ''));
            if ($type === 'html_page') return 'html';
            if ($type === 'video_file') return 'video';
        }

        if (stripos($content, '![Image générée](') !== false) return 'image';
        if (stripos($content, '[Vidéo générée](') !== false) return 'video';

        return null;
    }

    private static function resolveEstimatedToolId(string $toolType, string $content, array $toolsByType): ?int
    {
        $tools = $toolsByType[$toolType] ?? [];
        if (empty($tools)) {
            return null;
        }
        if (count($tools) === 1) {
            return (int)$tools[0]['id'];
        }

        // Heuristique pour la vidéo : le contenu peut inclure le modèle entre parenthèses.
        if ($toolType === 'video') {
            if (preg_match('/\(([^)]+)\)/u', $content, $matches) === 1) {
                $parts = preg_split('/\s*·\s*/u', (string)$matches[1]);
                $modelHint = trim((string)($parts[0] ?? ''));
                if ($modelHint !== '') {
                    foreach ($tools as $tool) {
                        if (strcasecmp((string)$tool['model'], $modelHint) === 0) {
                            return (int)$tool['id'];
                        }
                    }
                }
            }
        }

        // Fallback estimatif : premier outil de ce type selon priorité active/sort_order/id.
        return (int)$tools[0]['id'];
    }
}
