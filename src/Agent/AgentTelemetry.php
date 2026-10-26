<?php

declare(strict_types=1);

/**
 * Télémétrie de l'agent — ce que fait la boucle d'outils, mesuré (migration
 * 080, écran « Agent » de Maintenance → Diagnostic).
 *
 * LE PROBLÈME RÉSOLU. La boucle d'outils pouvait s'enliser (budget
 * d'allers-retours épuisé), un connecteur échouer une fois sur trois, une
 * recherche prendre vingt secondes, les cartes d'approbation être refusées à
 * la chaîne… sans que personne le voie : rien n'était compté, et l'on
 * n'améliorait la conduite de l'agent qu'à l'impression.
 *
 * Une ligne par réponse passée par la boucle d'outils : allers-retours avec
 * le modèle, appels d'outils (comptés au dispatch unique, ToolRegistry::stats),
 * erreurs, répétitions bloquées, noms d'outils inconnus, cartes proposées,
 * condensations, jetons et cache, durée, budget épuisé, LLM de secours,
 * réponse en erreur, raison de poursuivre. Le devenir des cartes (approuvées,
 * refusées, en échec, restées sans décision) se lit dans
 * `drive_pending_actions`, qui le garde déjà.
 *
 * AUCUN contenu, aucun identifiant de personne ou de conversation : des
 * nombres, des noms d'outils et le modèle. Écriture best-effort — une
 * télémétrie ne fait jamais échouer un tour.
 */
final class AgentTelemetry
{
    /** Ancienneté maximale conservée (jours). */
    public const RETENTION_DAYS = 180;

    /** Périodes proposées par l'écran (jours). */
    public const PERIODS = [7, 30, 90];

    /** Une écriture sur N purge les lignes trop anciennes (pas de tâche planifiée). */
    private const PURGE_EVERY = 200;

    /** Lignes relues au plus pour le détail par outil (le reste est agrégé en SQL). */
    private const TOOL_ROWS_MAX = 20000;

    /**
     * Ligne à écrire, depuis ce que le tour a mesuré. Pure : bornes,
     * types, outils triés par nom (lecture stable).
     *
     * @param array{provider?:string, model?:string, rounds?:int, registry?:array, cards?:int, condensed?:int,
     *              tokens?:array, duration_ms?:int, failover?:bool, failed?:bool, follow_up?:?string} $in
     */
    public static function turnRow(array $in): array
    {
        $small = static fn(mixed $v): int => max(0, min(65535, (int)$v));
        $big   = static fn(mixed $v): int => max(0, min(4294967295, (int)$v));
        $reg    = is_array($in['registry'] ?? null) ? $in['registry'] : [];
        $tokens = is_array($in['tokens'] ?? null) ? $in['tokens'] : [];
        $tools  = [];
        foreach ((array)($reg['tools'] ?? []) as $name => $s) {
            if (!is_array($s)) {
                continue;
            }
            $tools[mb_substr((string)$name, 0, 60)] = [
                'n'   => $big($s['n'] ?? 0),
                'err' => $big($s['err'] ?? 0),
                'ms'  => $big($s['ms'] ?? 0),
                'max' => $big($s['max'] ?? 0),
            ];
        }
        ksort($tools);
        $followUp = (string)($in['follow_up'] ?? '');
        return [
            'provider'           => mb_substr((string)($in['provider'] ?? ''), 0, 40),
            'model'              => mb_substr((string)($in['model'] ?? ''), 0, 120),
            'rounds'             => $small($in['rounds'] ?? 0),
            'tool_calls'         => $small($reg['calls'] ?? 0),
            'tool_errors'        => $small($reg['errors'] ?? 0),
            'duplicates_blocked' => $small($reg['duplicates'] ?? 0),
            'unknown_tools'      => $small($reg['unknown'] ?? 0),
            'cards'              => $small($in['cards'] ?? 0),
            'condensed'          => $small($in['condensed'] ?? 0),
            'input_tokens'       => $big($tokens['input'] ?? 0),
            'cached_tokens'      => $big($tokens['input_cached'] ?? 0),
            'cache_write_tokens' => $big($tokens['input_cache_write'] ?? 0),
            'output_tokens'      => $big($tokens['output'] ?? 0),
            'duration_ms'        => $big($in['duration_ms'] ?? 0),
            'rounds_exhausted'   => !empty($tokens['rounds_exhausted']) ? 1 : 0,
            'failover'           => !empty($in['failover']) ? 1 : 0,
            'failed'             => !empty($in['failed']) ? 1 : 0,
            'follow_up'          => in_array($followUp, ['budget', 'plan'], true) ? $followUp : '',
            'tools_json'         => $tools !== [] ? (string)json_encode($tools, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /** Écrit une ligne (cf. turnRow). Ne lève jamais. */
    public static function record(array $row): void
    {
        try {
            $cols = array_keys($row);
            $db   = Database::getInstance();
            $db->prepare(
                'INSERT INTO agent_turn_stats (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            )->execute(array_values($row));
            if ((int)$db->lastInsertId() % self::PURGE_EVERY === 0) {
                $db->prepare('DELETE FROM agent_turn_stats WHERE created_at < NOW() - INTERVAL ? DAY')
                    ->execute([self::RETENTION_DAYS]);
            }
        } catch (\Throwable $e) {
            error_log('[Télémétrie agent] ligne non enregistrée : ' . $e->getMessage());
        }
    }

    /** Période valide (jours) : une des PERIODS, 30 par défaut. */
    public static function period(mixed $days): int
    {
        $days = is_numeric($days) ? (int)$days : 0;
        return in_array($days, self::PERIODS, true) ? $days : 30;
    }

    // ===================== Lectures (écran « Agent ») =====================

    /**
     * Indicateurs de la période. `chat_turns` = toutes les réponses de
     * l'assistant (avec ou sans outils), pour la part qui passe par la boucle.
     */
    public static function summary(int $days): array
    {
        $st = Database::getInstance()->prepare(
            'SELECT COUNT(*) AS turns, COALESCE(SUM(rounds), 0) AS rounds, COALESCE(SUM(tool_calls), 0) AS tool_calls,
                    COALESCE(SUM(tool_errors), 0) AS tool_errors, COALESCE(SUM(duplicates_blocked), 0) AS duplicates,
                    COALESCE(SUM(unknown_tools), 0) AS unknown, COALESCE(SUM(cards), 0) AS cards,
                    COALESCE(SUM(condensed), 0) AS condensed, COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(cached_tokens), 0) AS cached_tokens, COALESCE(SUM(cache_write_tokens), 0) AS cache_write_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens, COALESCE(AVG(duration_ms), 0) AS avg_ms,
                    COALESCE(SUM(rounds_exhausted), 0) AS exhausted, COALESCE(SUM(failover), 0) AS failover,
                    COALESCE(SUM(failed), 0) AS failed, COALESCE(SUM(follow_up <> \'\'), 0) AS follow_up
               FROM agent_turn_stats WHERE created_at >= NOW() - INTERVAL ? DAY'
        );
        $st->execute([$days]);
        $row = array_map('intval', (array)$st->fetch(\PDO::FETCH_ASSOC));

        $st = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM messages WHERE role = 'assistant' AND created_at >= NOW() - INTERVAL ? DAY"
        );
        $st->execute([$days]);
        $row['chat_turns'] = (int)$st->fetchColumn();
        return $row + self::ratios($row);
    }

    /**
     * Rapports dérivés d'une ligne d'agrégats (pure) : allers-retours moyens,
     * taux d'erreur, part de cache, part de budget épuisé, part des réponses
     * qui passent par les outils. Null quand le dénominateur est nul.
     */
    public static function ratios(array $r): array
    {
        $div = static fn(int|float $a, int|float $b): ?float => $b > 0 ? $a / $b : null;
        return [
            'avg_rounds'     => $div((int)($r['rounds'] ?? 0), (int)($r['turns'] ?? 0)),
            'avg_tools'      => $div((int)($r['tool_calls'] ?? 0), (int)($r['turns'] ?? 0)),
            'error_rate'     => $div((int)($r['tool_errors'] ?? 0), (int)($r['tool_calls'] ?? 0)),
            'cache_rate'     => $div((int)($r['cached_tokens'] ?? 0), (int)($r['input_tokens'] ?? 0)),
            'exhausted_rate' => $div((int)($r['exhausted'] ?? 0), (int)($r['turns'] ?? 0)),
            'tool_share'     => isset($r['chat_turns']) ? $div((int)($r['turns'] ?? 0), (int)$r['chat_turns']) : null,
        ];
    }

    /** Par modèle : réponses, allers-retours, erreurs, cache, durée. */
    public static function byModel(int $days): array
    {
        $st = Database::getInstance()->prepare(
            'SELECT provider, model, COUNT(*) AS turns, SUM(rounds) AS rounds, SUM(tool_calls) AS tool_calls,
                    SUM(tool_errors) AS tool_errors, SUM(input_tokens) AS input_tokens, SUM(cached_tokens) AS cached_tokens,
                    SUM(rounds_exhausted) AS exhausted, AVG(duration_ms) AS avg_ms
               FROM agent_turn_stats WHERE created_at >= NOW() - INTERVAL ? DAY
              GROUP BY provider, model ORDER BY turns DESC LIMIT 50'
        );
        $st->execute([$days]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $num = ['provider' => (string)$r['provider'], 'model' => (string)$r['model']]
                + array_map('intval', array_diff_key($r, ['provider' => 1, 'model' => 1]));
            $out[] = $num + self::ratios($num);
        }
        return $out;
    }

    /** Par outil, depuis tools_json (cf. mergeToolStats). */
    public static function byTool(int $days): array
    {
        $st = Database::getInstance()->prepare(
            'SELECT tools_json FROM agent_turn_stats
              WHERE created_at >= NOW() - INTERVAL ? DAY AND tools_json IS NOT NULL
              ORDER BY id DESC LIMIT ' . self::TOOL_ROWS_MAX
        );
        $st->execute([$days]);
        return self::mergeToolStats($st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Fusionne les détails par outil de plusieurs réponses (pure) : appels,
     * erreurs, durée moyenne et maximale ; trié par nombre d'appels.
     *
     * @param list<string|null> $jsons
     * @return list<array{tool:string, n:int, err:int, avg_ms:int, max_ms:int, error_rate:float}>
     */
    public static function mergeToolStats(array $jsons): array
    {
        $acc = [];
        foreach ($jsons as $json) {
            $tools = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($tools)) {
                continue;
            }
            foreach ($tools as $name => $s) {
                if (!is_array($s)) {
                    continue;
                }
                $a = $acc[$name] ?? ['n' => 0, 'err' => 0, 'ms' => 0, 'max' => 0];
                $a['n']   += max(0, (int)($s['n'] ?? 0));
                $a['err'] += max(0, (int)($s['err'] ?? 0));
                $a['ms']  += max(0, (int)($s['ms'] ?? 0));
                $a['max']  = max($a['max'], (int)($s['max'] ?? 0));
                $acc[$name] = $a;
            }
        }
        $out = [];
        foreach ($acc as $name => $a) {
            if ($a['n'] <= 0) {
                continue;
            }
            $out[] = [
                'tool'       => (string)$name,
                'n'          => $a['n'],
                'err'        => $a['err'],
                'avg_ms'     => (int)round($a['ms'] / $a['n']),
                'max_ms'     => $a['max'],
                'error_rate' => $a['err'] / $a['n'],
            ];
        }
        usort($out, static fn(array $x, array $y): int => [$y['n'], $x['tool']] <=> [$x['n'], $y['tool']]);
        return $out;
    }

    /**
     * Devenir des cartes d'approbation de la période, par outil : proposées,
     * approuvées (exécutées), refusées, en échec, restées sans décision
     * (en attente au-delà de leur durée de vie), délai moyen de décision.
     */
    public static function cards(int $days): array
    {
        // Une action exécutée par une tâche « sans validation » n'est pas une
        // carte : personne n'a décidé (auto_executed, migration 085).
        $st = Database::getInstance()->prepare(
            "SELECT tool,
                    CASE WHEN status IN ('pending', 'processing')
                              AND COALESCE(expires_at, created_at + INTERVAL ? MINUTE) < NOW() THEN 'expired'
                         ELSE status END AS state,
                    COUNT(*) AS n,
                    AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, resolved_at) END) AS avg_s
               FROM drive_pending_actions
              WHERE created_at >= NOW() - INTERVAL ? DAY AND auto_executed = 0
              GROUP BY tool, state"
        );
        $st->execute([DrivePendingAction::TTL_MINUTES, $days]);
        return self::foldCards($st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Range les lignes (outil, état, nombre, délai) en une ligne par outil
     * (pure), triée par nombre de cartes ; `total` en dernière position.
     *
     * @return list<array{tool:string, proposed:int, executed:int, cancelled:int, failed:int, expired:int, pending:int, avg_decision_s:?int, approval_rate:?float}>
     */
    public static function foldCards(array $rows): array
    {
        $blank = ['proposed' => 0, 'executed' => 0, 'cancelled' => 0, 'failed' => 0, 'expired' => 0, 'pending' => 0, '_s' => 0.0, '_sn' => 0];
        $acc   = [];
        foreach ($rows as $r) {
            $tool  = (string)($r['tool'] ?? '');
            $state = (string)($r['state'] ?? '');
            $n     = max(0, (int)($r['n'] ?? 0));
            $a     = $acc[$tool] ?? $blank;
            $a['proposed'] += $n;
            $key = match ($state) {
                'executed' => 'executed', 'cancelled' => 'cancelled', 'failed' => 'failed',
                'expired'  => 'expired', default => 'pending',
            };
            $a[$key] += $n;
            if (isset($r['avg_s']) && in_array($key, ['executed', 'cancelled', 'failed'], true)) {
                $a['_s']  += (float)$r['avg_s'] * $n;
                $a['_sn'] += $n;
            }
            $acc[$tool] = $a;
        }
        $out   = [];
        $total = ['tool' => ''] + $blank;
        foreach ($acc as $tool => $a) {
            foreach (array_keys($blank) as $k) {
                $total[$k] += $a[$k];
            }
            $out[] = self::cardLine($tool, $a);
        }
        usort($out, static fn(array $x, array $y): int => [$y['proposed'], $x['tool']] <=> [$x['proposed'], $y['tool']]);
        if ($out !== []) {
            $out[] = self::cardLine('', $total);
        }
        return $out;
    }

    /** Volume minimal avant de tirer une conclusion d'un taux. */
    public const SIGNAL_MIN = 10;

    /**
     * Points à surveiller, en phrases (pure) : budget d'allers-retours
     * souvent épuisé, outil qui échoue ou traîne, cartes refusées ou laissées
     * sans décision, outils inexistants appelés, répétitions bloquées. Rien
     * en deçà de SIGNAL_MIN occurrences : un taux sur trois cas ne dit rien.
     * Phrases dans la langue de la page (clé = phrase française entière).
     *
     * @param list<array> $tools résultat de byTool / mergeToolStats
     * @param list<array> $cards résultat de cards / foldCards (total en dernier)
     * @param (callable(string): string)|null $label libellé lisible d'un outil (nom technique à défaut)
     * @return list<array{level:string, text:string}>
     */
    public static function signals(array $summary, array $tools, array $cards, ?callable $label = null): array
    {
        $label = $label ?? static fn(string $tool): string => $tool;
        $out   = [];
        $en    = I18n::locale() === 'en';
        $pct   = static fn(float $r): string => (string)round($r * 100) . ($en ? '%' : ' %');
        $turns = (int)($summary['turns'] ?? 0);
        if ($turns >= self::SIGNAL_MIN && ($summary['exhausted_rate'] ?? 0) >= 0.1) {
            $out[] = ['level' => 'warn', 'text' => t('{pct} des réponses atteignent le budget d\'allers-retours avant d\'avoir fini : l\'agent tourne en rond ou la demande dépasse un tour. Les utilisateurs voient alors le bouton « Continuer ».', [
                'pct' => $pct((float)$summary['exhausted_rate']),
            ])];
        }
        foreach ($tools as $t) {
            if ($t['n'] < self::SIGNAL_MIN) {
                continue;
            }
            if ($t['error_rate'] >= 0.2) {
                $out[] = ['level' => 'warn', 'text' => t('L\'outil « {outil} » échoue dans {pct} des appels : connexion à vérifier, droits manquants ou arguments mal formés par le modèle.', [
                    'outil' => $label($t['tool']),
                    'pct'   => $pct($t['error_rate']),
                ])];
            }
            if ($t['avg_ms'] >= 10000) {
                $out[] = ['level' => 'info', 'text' => t('L\'outil « {outil} » met en moyenne {secondes} s à répondre.', [
                    'outil'    => $label($t['tool']),
                    'secondes' => $en ? number_format($t['avg_ms'] / 1000, 1, '.', ',') : number_format($t['avg_ms'] / 1000, 1, ',', ' '),
                ])];
            }
        }
        foreach ($cards as $c) {
            if ($c['tool'] === '') {
                continue; // ligne de total
            }
            $decided = $c['executed'] + $c['failed'] + $c['cancelled'];
            if ($decided >= self::SIGNAL_MIN && $c['approval_rate'] !== null && $c['approval_rate'] < 0.5) {
                $out[] = ['level' => 'warn', 'text' => t('Les cartes « {outil} » sont refusées plus d\'une fois sur deux : l\'assistant propose peut-être des écritures que personne n\'a demandées.', [
                    'outil' => $label($c['tool']),
                ])];
            }
            if ($c['proposed'] >= self::SIGNAL_MIN && $c['expired'] / $c['proposed'] >= 0.3) {
                $out[] = ['level' => 'info', 'text' => t('{pct} des cartes « {outil} » restent sans décision : elles arrivent peut-être trop tard, ou sans explication suffisante.', [
                    'pct'   => $pct($c['expired'] / $c['proposed']),
                    'outil' => $label($c['tool']),
                ])];
            }
        }
        if ((int)($summary['unknown'] ?? 0) > 0) {
            $out[] = ['level' => 'info', 'text' => t('Le modèle a appelé {n} fois un outil qui n\'existe pas dans la réponse en cours (nom mal recopié) : il reçoit la liste des outils et se corrige d\'ordinaire.', [
                'n' => (int)$summary['unknown'],
            ])];
        }
        if ($turns >= self::SIGNAL_MIN && (int)($summary['duplicates'] ?? 0) >= $turns / 10) {
            $doublons = (int)$summary['duplicates'];
            $out[] = ['level' => 'info', 'text' => I18n::plural($doublons,
                '{n} appel identique répété a été bloqué : le modèle relance parfois la même recherche au lieu d\'élargir.',
                '{n} appels identiques répétés ont été bloqués : le modèle relance parfois la même recherche au lieu d\'élargir.')];
        }
        return $out;
    }

    /**
     * Une ligne du tableau des cartes. Taux d'approbation = cartes approuvées
     * (exécutées ou en échec à l'exécution : l'utilisateur a dit oui) sur
     * cartes tranchées ; les cartes restées sans décision n'y comptent pas.
     */
    private static function cardLine(string $tool, array $a): array
    {
        $approved = $a['executed'] + $a['failed'];
        $decided  = $approved + $a['cancelled'];
        return [
            'tool'           => $tool,
            'proposed'       => $a['proposed'],
            'executed'       => $a['executed'],
            'cancelled'      => $a['cancelled'],
            'failed'         => $a['failed'],
            'expired'        => $a['expired'],
            'pending'        => $a['pending'],
            'avg_decision_s' => $a['_sn'] > 0 ? (int)round($a['_s'] / $a['_sn']) : null,
            'approval_rate'  => $decided > 0 ? $approved / $decided : null,
        ];
    }
}
