<?php

declare(strict_types=1);

/**
 * Usages → Vue d'ensemble : ce que MIA fait, qui s'en sert, et avec quelle
 * qualité. AUCUN montant en euros ici (ils vivent sur la page Budget) : la
 * répartition se lit en crédits, en opérations et en personnes.
 *
 * Deux étages, comme AiBudget : des formes PURES (catalogue des
 * fonctionnalités, adoption, tendance, médianes, carte proportionnelle),
 * testables sans base, et `overview()`, qui lit la base et les assemble.
 *
 * Sources, toutes déjà présentes — aucune migration :
 *   - le grand livre (`usage_logs.source`, `tool_usage_logs.tool_type`) ;
 *   - la trace des actions de chaque réponse (`messages.actions_json`, lue par
 *     JSON_TABLE et regroupée côté base) : bases de connaissances,
 *     connecteurs, mémoire, automatisations ;
 *   - les réponses elles-mêmes (`messages`) : modèle, temps de réponse,
 *     réponses en erreur (⚠), assistant de la conversation ;
 *   - les exécutions des tâches programmées.
 * La télémétrie de l'agent ne sert PAS : elle ne garde aucune identité, à dessein.
 */
final class UsageInsights
{
    /**
     * Catalogue des fonctionnalités : d'où se lit l'usage de chacune.
     * `llm` = sources du grand livre, `tools` = types d'outils, `actions` =
     * types de la trace d'actions, `connectors` / `assistants` / `scheduled` =
     * lectures dédiées. Un usage = une opération, une action ou une exécution.
     * `label` = clé de traduction : on le lit par `featureLabel()`.
     */
    public const FEATURES = [
        'chat'         => ['label' => 'Conversations', 'llm' => ['chat', 'regenerate']],
        'assistants'   => ['label' => 'Assistants', 'assistants' => true],
        'web'          => ['label' => 'Recherche Internet', 'llm' => ['web_search'], 'actions' => ['web_read']],
        'kb'           => ['label' => 'Bases de connaissances', 'actions' => [
            'searching_knowledge_base', 'searching_knowledge_bases', 'mybrain_search', 'mybrain_dig', 'mybrain_read',
        ]],
        'connectors'   => ['label' => 'Connecteurs', 'connectors' => true],
        'image'        => ['label' => 'Images', 'tools' => ['image']],
        'html'         => ['label' => 'Pages web', 'llm' => ['html_tool'], 'tools' => ['html', 'html_illustrated']],
        'presentation' => ['label' => 'Présentations', 'llm' => ['presentation_tool'], 'tools' => ['presentation', 'gamma']],
        'map'          => ['label' => 'Cartes et itinéraires', 'llm' => ['map_tool'], 'tools' => ['map']],
        'video'        => ['label' => 'Vidéos', 'tools' => ['video']],
        'voice'        => ['label' => 'Dictée et voix', 'llm' => ['transcribe', 'speech']],
        'memory'       => ['label' => 'Mémoire « MIA pour vous »', 'actions' => ['memorizing']],
        'automation'   => ['label' => 'Automatisations n8n', 'tools' => ['n8n'], 'actions' => ['n8n_run']],
        'scheduled'    => ['label' => 'Tâches programmées', 'scheduled' => true],
    ];

    /** Types de la trace d'actions qui disent « un connecteur a servi ». */
    private const CONNECTOR_PREFIXES = ['drive_', 'gmail_', 'outlook_', 'calendar_', 'mscalendar_', 'tasks_', 'mstodo_', 'slack_', 'teams_'];
    private const CONNECTOR_ACTIONS  = [
        'emails_search', 'agendas_search', 'everywhere_search', 'envoyer_message_slack', 'envoyer_message_teams', 'connecteur_active',
        'creer_google_doc', 'modifier_google_doc', 'creer_google_sheet', 'modifier_google_sheet',
        'renommer_google_drive', 'deplacer_google_drive', 'dupliquer_google_drive',
        'mettre_en_forme_google_doc', 'mettre_en_forme_google_sheet',
        'creer_brouillon_gmail', 'creer_brouillon_outlook', 'creer_evenement_agenda', 'creer_evenement_agenda_outlook',
        'creer_tache', 'modifier_tache', 'supprimer_tache', 'creer_tache_todo', 'modifier_tache_todo',
    ];

    /** Nombre de lignes détaillées avant « Autres » (modèles, assistants). */
    public const TOP_ROWS = 6;
    /** Une base n'ayant nourri aucune réponse depuis ce délai est signalée. */
    public const DORMANT_DAYS = 30;

    // =====================================================================
    // Formes pures
    // =====================================================================

    /** Libellé affiché d'une fonctionnalité, traduit (clé inconnue : la clé elle-même). */
    public static function featureLabel(string $key): string
    {
        return isset(self::FEATURES[$key]) ? t(self::FEATURES[$key]['label']) : $key;
    }

    public static function isConnectorAction(string $type): bool
    {
        if (in_array($type, self::CONNECTOR_ACTIONS, true)) {
            return true;
        }
        foreach (self::CONNECTOR_PREFIXES as $p) {
            if (str_starts_with($type, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Personnes et usages par fonctionnalité, depuis les lignes regroupées.
     * Une personne compte une fois par fonctionnalité, même si elle passe par
     * plusieurs sources (ex. une page web par l'outil ET par le grand livre).
     *
     * @param list<array{source:string,user_id:?int,n:int}>    $llm
     * @param list<array{tool_type:string,user_id:?int,n:int}> $tools
     * @param list<array{t:string,user_id:?int,n:int}>         $actions
     * @param list<array{user_id:?int,n:int}>                  $scheduled
     * @param list<array{user_id:?int,n:int}>                  $assistants
     * @return array<string, array{people:int, uses:int}>
     */
    public static function aggregateFeatures(array $llm, array $tools, array $actions, array $scheduled, array $assistants): array
    {
        $bySource = $byTool = $byAction = [];
        foreach ($llm as $r)   { $bySource[(string)$r['source']][] = $r; }
        foreach ($tools as $r) { $byTool[(string)$r['tool_type']][] = $r; }
        foreach ($actions as $r) { $byAction[(string)$r['t']][] = $r; }

        $out = [];
        foreach (self::FEATURES as $key => $def) {
            $rows = [];
            foreach ($def['llm'] ?? [] as $s)   { array_push($rows, ...($bySource[$s] ?? [])); }
            foreach ($def['tools'] ?? [] as $t) { array_push($rows, ...($byTool[$t] ?? [])); }
            foreach ($def['actions'] ?? [] as $a) { array_push($rows, ...($byAction[$a] ?? [])); }
            if (!empty($def['connectors'])) {
                foreach ($byAction as $type => $list) {
                    if (self::isConnectorAction((string)$type)) {
                        array_push($rows, ...$list);
                    }
                }
            }
            if (!empty($def['scheduled']))  { array_push($rows, ...$scheduled); }
            if (!empty($def['assistants'])) { array_push($rows, ...$assistants); }

            $people = [];
            $uses   = 0;
            foreach ($rows as $r) {
                $uses += max(0, (int)$r['n']);
                if (($r['user_id'] ?? null) !== null && (int)$r['n'] > 0) {
                    $people[(int)$r['user_id']] = true;
                }
            }
            $out[$key] = ['people' => count($people), 'uses' => $uses];
        }
        return $out;
    }

    /** Évolution d'un usage : pourcentage, 'new' (rien avant), ou null (rien à comparer). */
    public static function trend(int $current, ?int $previous): int|string|null
    {
        if ($previous === null) {
            return null;
        }
        if ($previous === 0) {
            return $current > 0 ? 'new' : null;
        }
        return (int)round(($current - $previous) / $previous * 100);
    }

    /**
     * Lignes du tableau d'adoption : utilisées d'abord (par personnes puis
     * usages), les autres à part — on les nomme, on ne les dessine pas.
     *
     * @param array<string,array{people:int,uses:int}> $current
     * @param array<string,array{people:int,uses:int}>|null $previous
     * @return array{used:list<array>, unused:list<string>}
     */
    public static function adoption(array $current, ?array $previous, int $accounts): array
    {
        $used = $unused = [];
        foreach (self::FEATURES as $key => $def) {
            $c = $current[$key] ?? ['people' => 0, 'uses' => 0];
            if ($c['uses'] <= 0) {
                $unused[] = self::featureLabel($key);
                continue;
            }
            $used[] = [
                'key'    => $key,
                'label'  => self::featureLabel($key),
                'people' => $c['people'],
                'uses'   => $c['uses'],
                'share'  => $accounts > 0 ? round(min(100, $c['people'] / $accounts * 100), 1) : 0.0,
                'trend'  => self::trend($c['uses'], $previous !== null ? ($previous[$key]['uses'] ?? 0) : null),
            ];
        }
        usort($used, static fn(array $a, array $b): int => [$b['people'], $b['uses']] <=> [$a['people'], $a['uses']]);
        return ['used' => $used, 'unused' => $unused];
    }

    /** Quantile (0..1) d'une liste de valeurs, interpolé ; null si vide. */
    public static function quantile(array $values, float $q): ?float
    {
        $values = array_values(array_filter(array_map('floatval', $values), static fn(float $v): bool => $v >= 0));
        if ($values === []) {
            return null;
        }
        sort($values);
        $pos  = ($count = count($values)) > 1 ? max(0.0, min(1.0, $q)) * ($count - 1) : 0.0;
        $low  = (int)floor($pos);
        $high = (int)ceil($pos);
        return $values[$low] + ($values[$high] - $values[$low]) * ($pos - $low);
    }

    /** Taux de relance : régénérations ÷ (réponses + régénérations), en %. */
    public static function regenRate(int $chat, int $regen): ?float
    {
        $total = $chat + $regen;
        return $total > 0 ? round($regen / $total * 100, 1) : null;
    }

    /**
     * Modèles : part des réponses, temps médian et 9ᵉ décile, taux de relance.
     *
     * @param list<array{llm_provider:?string,llm_model:?string,response_ms:?int}> $responses réponses abouties
     * @param list<array{llm_provider:string,model:string,source:string,n:int}>    $regen     lignes chat/regenerate du grand livre
     * @param callable(string,string):string $label
     */
    public static function modelStats(array $responses, array $regen, callable $label): array
    {
        $groups = [];
        foreach ($responses as $r) {
            $p = (string)($r['llm_provider'] ?? '');
            $m = (string)($r['llm_model'] ?? '');
            if ($p === '' && $m === '') {
                continue;
            }
            $k = $p . '::' . $m;
            $groups[$k] ??= ['provider' => $p, 'model' => $m, 'responses' => 0, 'ms' => []];
            $groups[$k]['responses']++;
            if ((int)($r['response_ms'] ?? 0) > 0) {
                $groups[$k]['ms'][] = (int)$r['response_ms'];
            }
        }
        $regenBy = [];
        foreach ($regen as $r) {
            $k = (string)$r['llm_provider'] . '::' . (string)$r['model'];
            $regenBy[$k][(string)$r['source']] = ($regenBy[$k][(string)$r['source']] ?? 0) + (int)$r['n'];
        }
        $total = array_sum(array_column($groups, 'responses'));
        $rows  = [];
        foreach ($groups as $k => $g) {
            $rows[] = [
                'provider'   => $g['provider'],
                'model'      => $g['model'],
                'label'      => $label($g['provider'], $g['model']),
                'responses'  => $g['responses'],
                'share'      => $total > 0 ? round($g['responses'] / $total * 100, 1) : 0.0,
                'median_ms'  => self::quantile($g['ms'], 0.5),
                'p90_ms'     => self::quantile($g['ms'], 0.9),
                'regen_rate' => self::regenRate((int)($regenBy[$k]['chat'] ?? 0), (int)($regenBy[$k]['regenerate'] ?? 0)),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $b['responses'] <=> $a['responses']);
        return self::withOthers($rows, 'responses', $total);
    }

    /**
     * Assistants : conversations, personnes, réponses, taux de relance.
     * `profile_id` null = conversations sans assistant.
     *
     * @param list<array{profile_id:?int,user_id:?int,conversation_id:int}> $responses
     * @param array<int|string,array{chat:int,regenerate:int}> $regenByProfile clé '' = sans assistant
     * @param array<int,string> $names
     */
    public static function assistantStats(array $responses, array $regenByProfile, array $names): array
    {
        $groups = [];
        foreach ($responses as $r) {
            $k = $r['profile_id'] !== null ? (int)$r['profile_id'] : '';
            $groups[$k] ??= ['conversations' => [], 'people' => [], 'responses' => 0];
            $groups[$k]['conversations'][(int)$r['conversation_id']] = true;
            if ($r['user_id'] !== null) {
                $groups[$k]['people'][(int)$r['user_id']] = true;
            }
            $groups[$k]['responses']++;
        }
        $rows = [];
        foreach ($groups as $k => $g) {
            $rows[] = [
                'profile_id'    => $k === '' ? null : (int)$k,
                'label'         => $k === '' ? t('Sans assistant') : ($names[(int)$k] ?? t('Assistant supprimé')),
                'conversations' => count($g['conversations']),
                'people'        => count($g['people']),
                'responses'     => $g['responses'],
                'regen_rate'    => self::regenRate((int)($regenByProfile[$k]['chat'] ?? 0), (int)($regenByProfile[$k]['regenerate'] ?? 0)),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$b['conversations'], $b['responses']] <=> [$a['conversations'], $a['responses']]);
        return $rows;
    }

    /**
     * Carte proportionnelle (treemap) : découpage binaire équilibré, le côté
     * le plus long coupé en premier, dans un cadre de `$widthPx` × `$heightPx`.
     * Rectangles en POURCENTAGE du cadre ; `compact` = trop petit pour deux
     * lignes, `tiny` = trop petit pour une étiquette.
     *
     * @param list<array{value:float}> $items (autres clés conservées)
     * @return list<array> items + x, y, w, h, share, compact, tiny
     */
    public static function treemap(array $items, float $widthPx = 900.0, float $heightPx = 200.0): array
    {
        $items = array_values(array_filter($items, static fn(array $i): bool => (float)$i['value'] > 0));
        usort($items, static fn(array $a, array $b): int => (float)$b['value'] <=> (float)$a['value']);
        $total = array_sum(array_map(static fn(array $i): float => (float)$i['value'], $items));
        if ($items === [] || $total <= 0) {
            return [];
        }
        $out = [];
        $split = static function (array $list, float $x, float $y, float $w, float $h) use (&$split, &$out, $widthPx, $heightPx, $total): void {
            if (count($list) === 1) {
                $wPx = $w / 100 * $widthPx;
                $hPx = $h / 100 * $heightPx;
                $out[] = $list[0] + [
                    'x' => round($x, 3), 'y' => round($y, 3), 'w' => round($w, 3), 'h' => round($h, 3),
                    'share'   => round((float)$list[0]['value'] / $total * 100, 1),
                    'compact' => $wPx < 110 || $hPx < 54,
                    'tiny'    => $wPx < 46 || $hPx < 26,
                ];
                return;
            }
            $sum  = array_sum(array_map(static fn(array $i): float => (float)$i['value'], $list));
            $acc  = 0.0;
            $cut  = 1;
            $best = INF;
            foreach ($list as $i => $item) {
                if ($i === count($list) - 1) {
                    break;
                }
                $acc += (float)$item['value'];
                $gap  = abs($sum / 2 - $acc);
                if ($gap < $best) {
                    $best = $gap;
                    $cut  = $i + 1;
                }
            }
            $a = array_slice($list, 0, $cut);
            $b = array_slice($list, $cut);
            $ratio = array_sum(array_map(static fn(array $i): float => (float)$i['value'], $a)) / $sum;
            if ($w / 100 * $widthPx >= $h / 100 * $heightPx) {
                $split($a, $x, $y, $w * $ratio, $h);
                $split($b, $x + $w * $ratio, $y, $w * (1 - $ratio), $h);
            } else {
                $split($a, $x, $y, $w, $h * $ratio);
                $split($b, $x, $y + $h * $ratio, $w, $h * (1 - $ratio));
            }
        };
        $split($items, 0.0, 0.0, 100.0, 100.0);
        return $out;
    }

    /** Fenêtre précédente de même durée ('Y-m-d'), ou null sans période bornée. */
    public static function previousWindow(?string $from, ?string $to): ?array
    {
        if ($from === null || $to === null || $from === '' || $to === '') {
            return null;
        }
        $f = new DateTimeImmutable($from);
        $t = new DateTimeImmutable($to);
        $days = (int)$f->diff($t)->days + 1;
        $prevTo = $f->modify('-1 day');
        return ['from' => $prevTo->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d')];
    }

    /** Les `TOP_ROWS` premières lignes, le reste regroupé (somme de `$field`). */
    private static function withOthers(array $rows, string $field, int $total): array
    {
        $shown = array_slice($rows, 0, self::TOP_ROWS);
        $rest  = array_slice($rows, self::TOP_ROWS);
        $other = null;
        if ($rest !== []) {
            $n = array_sum(array_column($rest, $field));
            $other = ['count' => count($rest), $field => $n, 'share' => $total > 0 ? round($n / $total * 100, 1) : 0.0];
        }
        return ['rows' => $shown, 'other' => $other, 'total' => $total];
    }

    // =====================================================================
    // Lectures (base)
    // =====================================================================

    /**
     * Tout ce que la vue d'ensemble affiche, pour une période ('Y-m-d', bornes
     * incluses, ou null = tout) et éventuellement une personne.
     *
     * @param list<array> $byCategory sortie de UsageLog::byCategory() pour la même période
     * @param array{from:string,to:string}|null|false $compare période de comparaison
     *        (UsagePeriod::previous) ; false = la plage de même durée juste avant
     */
    public static function overview(?string $from, ?string $to, ?int $userId, array $byCategory, array|null|false $compare = false): array
    {
        $db       = Database::getInstance();
        $accounts = $userId !== null ? 1 : (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();

        $current  = self::featureRows($from, $to, $userId);
        $prevWin  = $compare === false ? self::previousWindow($from, $to) : $compare;
        $previous = $prevWin !== null ? self::featureRows($prevWin['from'], $prevWin['to'], $userId) : null;

        // Réponses de la période : modèle, temps, erreur, assistant — une seule lecture.
        [$where, $params] = self::messageWindow($from, $to, $userId);
        $stmt = $db->prepare(
            "SELECT m.llm_provider, m.llm_model, m.response_ms, (m.content LIKE '⚠%') AS err,
                    c.profile_id, c.user_id, c.id AS conversation_id
             FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE m.role = 'assistant'$where"
        );
        $stmt->execute($params);
        $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $errors    = count(array_filter($responses, static fn(array $r): bool => (int)$r['err'] === 1));
        $ok        = array_values(array_filter($responses, static fn(array $r): bool => (int)$r['err'] === 0));
        $allMs     = array_map(static fn(array $r): int => (int)$r['response_ms'], array_filter($ok, static fn(array $r): bool => (int)$r['response_ms'] > 0));

        // Relances : grand livre, par modèle et par assistant.
        [$lw, $lp] = self::ledgerWindow($from, $to, $userId);
        $stmt = $db->prepare(
            "SELECT llm_provider, model, profile_id, source, COUNT(*) AS n FROM usage_logs
             WHERE source IN ('chat', 'regenerate')$lw GROUP BY llm_provider, model, profile_id, source"
        );
        $stmt->execute($lp);
        $regenRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $regenByProfile = [];
        $chatTotal = $regenTotal = 0;
        foreach ($regenRows as $r) {
            $k = $r['profile_id'] !== null ? (int)$r['profile_id'] : '';
            $regenByProfile[$k][(string)$r['source']] = ($regenByProfile[$k][(string)$r['source']] ?? 0) + (int)$r['n'];
            if ($r['source'] === 'chat') { $chatTotal += (int)$r['n']; } else { $regenTotal += (int)$r['n']; }
        }

        $names = [];
        $activeProfiles = [];
        foreach ($db->query('SELECT id, name, is_active FROM profiles')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $names[(int)$p['id']] = (string)$p['name'];
            if ((int)$p['is_active'] === 1) { $activeProfiles[] = (int)$p['id']; }
        }
        $assistants = self::assistantStats(
            array_map(static fn(array $r): array => [
                'profile_id' => $r['profile_id'] !== null ? (int)$r['profile_id'] : null,
                'user_id' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                'conversation_id' => (int)$r['conversation_id'],
            ], $ok),
            $regenByProfile,
            $names
        );
        $usedProfiles = array_filter(array_column($assistants, 'profile_id'));
        $idleProfiles = count(array_diff($activeProfiles, $usedProfiles));

        $models = self::modelStats(
            $ok,
            array_map(static fn(array $r): array => ['llm_provider' => (string)$r['llm_provider'], 'model' => (string)$r['model'],
                'source' => (string)$r['source'], 'n' => (int)$r['n']], $regenRows),
            static function (string $provider, string $model): string {
                $m = $model !== '' ? LlmCatalog::model($provider, $model) : null;
                return (string)($m['label'] ?? ($model !== '' ? $model : ucfirst($provider)));
            }
        );

        // Bases : contributions depuis leur création, et celles qui dorment.
        $kbs = $db->query(
            "SELECT id, name, query_count, last_used_at,
                    (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL " . self::DORMANT_DAYS . " DAY) AS dormant
             FROM knowledge_bases WHERE is_active = 1 ORDER BY query_count DESC, name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $categories = [];
        foreach ($byCategory as $c) {
            $categories[] = ['category' => (string)$c['category'], 'label' => (string)$c['label'],
                             'color' => (string)$c['color'], 'value' => (float)$c['credits'], 'maintenance' => !empty($c['quota_exempt'])];
        }

        return [
            'accounts'     => $accounts,
            'adoption'     => self::adoption($current, $previous, $accounts),
            'has_previous' => $previous !== null,
            'responses'    => count($responses),
            'errors'       => $errors,
            'error_rate'   => count($responses) > 0 ? round($errors / count($responses) * 100, 1) : null,
            'regen_rate'   => self::regenRate($chatTotal, $regenTotal),
            'median_ms'    => self::quantile($allMs, 0.5),
            'p90_ms'       => self::quantile($allMs, 0.9),
            'models'       => $models,
            'assistants'   => array_slice($assistants, 0, self::TOP_ROWS),
            'assistants_more' => max(0, count($assistants) - self::TOP_ROWS),
            'idle_assistants' => $idleProfiles,
            'kbs'          => array_slice(array_values(array_filter($kbs, static fn(array $k): bool => (int)$k['query_count'] > 0)), 0, 5),
            'kb_dormant'   => array_values(array_map(static fn(array $k): string => (string)$k['name'],
                                  array_filter($kbs, static fn(array $k): bool => (int)$k['dormant'] === 1))),
            'kb_total'     => count($kbs),
            'treemap'      => self::treemap($categories),
        ];
    }

    /** Lignes regroupées (source/outil/action/exécution × personne) d'une période. */
    private static function featureRows(?string $from, ?string $to, ?int $userId): array
    {
        $db = Database::getInstance();
        [$lw, $lp] = self::ledgerWindow($from, $to, $userId);

        $llm = $db->prepare("SELECT source, user_id, COUNT(*) AS n FROM usage_logs WHERE 1 = 1$lw GROUP BY source, user_id");
        $llm->execute($lp);
        $tools = $db->prepare("SELECT tool_type, user_id, COUNT(*) AS n FROM tool_usage_logs WHERE 1 = 1$lw GROUP BY tool_type, user_id");
        $tools->execute($lp);

        // Trace d'actions : JSON_TABLE regroupe côté base (une ligne par personne × type).
        [$mw, $mp] = self::messageWindow($from, $to, $userId);
        $actions = $db->prepare(
            "SELECT j.t, c.user_id, COUNT(*) AS n
             FROM messages m JOIN conversations c ON c.id = m.conversation_id,
                  JSON_TABLE(IF(JSON_VALID(m.actions_json), m.actions_json, '[]'), '\$[*]'
                             COLUMNS (t VARCHAR(64) PATH '\$.t')) j
             WHERE m.actions_json IS NOT NULL AND j.t IS NOT NULL$mw
             GROUP BY j.t, c.user_id"
        );
        $actions->execute($mp);

        $sw = '';
        $sp = [];
        if ($from !== null && $to !== null && $from !== '' && $to !== '') {
            $sw .= ' AND r.slot_at BETWEEN ? AND ?';
            $sp[] = $from . ' 00:00:00';
            $sp[] = $to . ' 23:59:59';
        }
        if ($userId !== null) {
            $sw .= ' AND t.user_id = ?';
            $sp[] = $userId;
        }
        $scheduled = $db->prepare(
            "SELECT t.user_id, COUNT(*) AS n FROM scheduled_task_runs r JOIN scheduled_tasks t ON t.id = r.task_id
             WHERE r.status = 'done' AND r.is_manual = 0$sw GROUP BY t.user_id"
        );
        $scheduled->execute($sp);

        $assistants = $db->prepare(
            "SELECT c.user_id, COUNT(*) AS n FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE m.role = 'assistant' AND c.profile_id IS NOT NULL AND m.content NOT LIKE '⚠%'$mw GROUP BY c.user_id"
        );
        $assistants->execute($mp);

        $norm = static fn(array $rows): array => array_map(static function (array $r): array {
            $r['user_id'] = $r['user_id'] !== null ? (int)$r['user_id'] : null;
            $r['n'] = (int)$r['n'];
            return $r;
        }, $rows);

        return self::aggregateFeatures(
            $norm($llm->fetchAll(PDO::FETCH_ASSOC)),
            $norm($tools->fetchAll(PDO::FETCH_ASSOC)),
            $norm($actions->fetchAll(PDO::FETCH_ASSOC)),
            $norm($scheduled->fetchAll(PDO::FETCH_ASSOC)),
            $norm($assistants->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    /** Filtre période/personne du grand livre (« AND … » + paramètres). */
    private static function ledgerWindow(?string $from, ?string $to, ?int $userId): array
    {
        $w = '';
        $p = [];
        if ($from !== null && $to !== null && $from !== '' && $to !== '') {
            $w .= ' AND created_at BETWEEN ? AND ?';
            $p[] = $from . ' 00:00:00';
            $p[] = $to . ' 23:59:59';
        }
        if ($userId !== null) {
            $w .= ' AND user_id = ?';
            $p[] = $userId;
        }
        return [$w, $p];
    }

    /**
     * Filtre des réponses. `c.updated_at >= début` ramène la lecture aux
     * conversations actives depuis le début de la période (chaque message
     * touche sa conversation) : les messages se lisent alors par l'index
     * (conversation_id, created_at), sans parcourir toute la table.
     */
    private static function messageWindow(?string $from, ?string $to, ?int $userId): array
    {
        $w = '';
        $p = [];
        if ($from !== null && $to !== null && $from !== '' && $to !== '') {
            $w .= ' AND c.updated_at >= ? AND m.created_at BETWEEN ? AND ?';
            $p[] = $from . ' 00:00:00';
            $p[] = $from . ' 00:00:00';
            $p[] = $to . ' 23:59:59';
        }
        if ($userId !== null) {
            $w .= ' AND c.user_id = ?';
            $p[] = $userId;
        }
        return [$w, $p];
    }
}
