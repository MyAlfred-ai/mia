<?php

declare(strict_types=1);

/**
 * Budget IA mensuel de l'organisation — source unique.
 *
 * Le problème résolu : le seul levier de dépense était le quota mensuel en
 * crédits, PAR PERSONNE. Aucun plafond global : un administrateur qui voulait
 * « ne jamais dépasser 500 € par mois » convertissait à la main, et les comptes
 * sans quota comme la maintenance (alimentation des bases, tests) échappaient à
 * toute limite.
 *
 * Deux leviers complémentaires, qu'on ne confond jamais :
 *   - le BUDGET (euros, toute l'organisation) = filet de sécurité : ce que MIA
 *     peut dépenser au maximum chez les fournisseurs d'IA ce mois-ci ;
 *   - les QUOTAS (crédits, par personne) = partage : personne ne consomme la
 *     part des autres. Le repère (`repere()`) fait le pont entre les deux.
 *
 * Ce qui est mesuré : le coût RÉEL fournisseur (`cost_eur`, hors marge) de
 * TOUTES les lignes du grand livre du mois civil — usage des comptes,
 * maintenance (`quota_exempt = 1`), opérations d'instance (`user_id NULL`).
 * C'est ce que les fournisseurs facturent, quelle qu'en soit la cause.
 *
 * La barrière n'est PAS ici : `CreditQuota::status()` reste la source unique
 * de « peut-on lancer une opération IA ? », et consulte `status()`. Tous les
 * points qui appliquent le quota appliquent donc le budget sans être touchés.
 *
 * Fenêtre : mois civil au fuseau par défaut du process (aucun n'est posé dans
 * l'image, donc UTC) — exactement la fenêtre des quotas (`date('Y-m-01')`).
 *
 * Performance : `CreditQuota::status()` tourne à chaque page (barre latérale).
 * Sans budget, aucune requête. Avec budget, la dépense du mois est mémorisée en
 * base (réglage `ai_budget_memo_json`, 60 s), relue fraîche près du plafond en
 * mode « suspendre », effacée à chaque écriture de la configuration. Pas de
 * cache statique : le worker est un process long-vivant (cf. Settings).
 */
final class AiBudget
{
    public const SETTING_MONTHLY   = 'ai_budget_monthly_eur';
    public const SETTING_ALERT_PCT = 'ai_budget_alert_pct';
    public const SETTING_ON_REACH  = 'ai_budget_on_reach';
    /** Rallonge du mois : {month, eur, by, at}. Une seule clé : montant et mois ne divergent jamais. */
    public const SETTING_EXTRA     = 'ai_budget_extra_json';
    /** Mémo de la dépense du mois : {month, at, usage_eur, maintenance_eur, unpriced_ops}. */
    public const SETTING_MEMO      = 'ai_budget_memo_json';

    public const ON_REACH_BLOCK = 'block';
    public const ON_REACH_WARN  = 'warn';

    public const STATE_OFF     = 'off';
    public const STATE_OK      = 'ok';
    public const STATE_ALERT   = 'alert';
    public const STATE_REACHED = 'reached';

    public const DEFAULT_ALERT_PCT   = 80;
    public const MEMO_TTL_SEC        = 60;
    /** En mode « suspendre », au-delà de ce pourcentage le mémo n'est plus lu : dépense relue à chaque appel. */
    public const FRESH_FROM_PCT      = 95;
    /** Pas de projection avant ce nombre de jours écoulés : extrapoler un matin n'a aucun sens. */
    public const PROJECTION_MIN_DAYS = 2;
    public const MAX_AMOUNT_EUR      = 10_000_000.0;

    // =====================================================================
    // Formes pures (testables sans base)
    // =====================================================================

    /**
     * Montant saisi → euros. '' = aucun budget (null). Tolère espaces, espaces
     * insécables, « € » et la virgule décimale. `0` est accepté : service
     * suspendu (même logique qu'un quota à 0).
     *
     * @throws InvalidArgumentException message traduit, affichable tel quel
     */
    public static function parseAmount(string $raw): ?float
    {
        $clean = str_replace(["\u{00A0}", "\u{202F}", ' ', '€'], '', trim($raw));
        if ($clean === '') {
            return null;
        }
        $clean = str_replace(',', '.', $clean);
        if (preg_match('/^\d+(\.\d+)?$/', $clean) !== 1) {
            throw new InvalidArgumentException(t('Montant invalide : saisissez un nombre d\'euros, par exemple 500.'));
        }
        $value = round((float)$clean, 2);
        if ($value > self::MAX_AMOUNT_EUR) {
            throw new InvalidArgumentException(t('Montant trop élevé.'));
        }
        return $value;
    }

    public static function normalizeAlertPct(mixed $raw): int
    {
        if ($raw === null || trim((string)$raw) === '' || !is_numeric(trim((string)$raw))) {
            return self::DEFAULT_ALERT_PCT;
        }
        return max(1, min(99, (int)round((float)$raw)));
    }

    public static function normalizeOnReach(mixed $raw): string
    {
        return (string)$raw === self::ON_REACH_WARN ? self::ON_REACH_WARN : self::ON_REACH_BLOCK;
    }

    /**
     * Fenêtre du mois civil contenant $now.
     *
     * @return array{month:string, from:string, until:string, resets_on:string, days_in_month:int}
     */
    public static function monthWindow(DateTimeImmutable $now): array
    {
        $first = $now->setDate((int)$now->format('Y'), (int)$now->format('n'), 1)->setTime(0, 0, 0);
        $next  = $first->modify('first day of next month');
        return [
            'month'         => $first->format('Y-m'),
            'from'          => $first->format('Y-m-d H:i:s'),
            'until'         => $next->format('Y-m-d H:i:s'),
            'resets_on'     => $next->format('Y-m-d'),
            'days_in_month' => (int)$first->format('t'),
        ];
    }

    /**
     * État complet du mois à partir de la configuration et de la dépense.
     *
     * @param array{enabled:bool, base_eur:?float, extra_eur:float, alert_pct:int, on_reach:string} $config
     * @param array{usage_eur:float, maintenance_eur:float, unpriced_ops:int} $spend
     */
    public static function evaluate(array $config, array $spend, DateTimeImmutable $now): array
    {
        $window  = self::monthWindow($now);
        $enabled = !empty($config['enabled']) && $config['base_eur'] !== null;
        $base    = $enabled ? (float)$config['base_eur'] : null;
        $extra   = $enabled ? max(0.0, (float)($config['extra_eur'] ?? 0.0)) : 0.0;
        $limit   = $enabled ? round($base + $extra, 2) : null;
        $usage   = round(max(0.0, (float)($spend['usage_eur'] ?? 0.0)), 6);
        $maint   = round(max(0.0, (float)($spend['maintenance_eur'] ?? 0.0)), 6);
        $spent   = round($usage + $maint, 6);
        $alert   = self::normalizeAlertPct($config['alert_pct'] ?? null);
        $onReach = self::normalizeOnReach($config['on_reach'] ?? '');

        $pct   = null;
        $state = self::STATE_OFF;
        if ($limit !== null) {
            // Arrondi INFÉRIEUR : 99,6 % ne s'affiche jamais « 100 % » avant le plafond.
            $pct = $limit > 0 ? (int)floor($spent / $limit * 100) : 100;
            if ($spent >= $limit) {
                $state = self::STATE_REACHED;
            } elseif ($pct >= $alert) {
                $state = self::STATE_ALERT;
            } else {
                $state = self::STATE_OK;
            }
        }

        $until    = new DateTimeImmutable($window['until']);
        $daysLeft = max(0, (int)ceil(($until->getTimestamp() - $now->getTimestamp()) / 86400));

        return [
            'enabled'         => $enabled,
            'state'           => $state,
            'month'           => $window['month'],
            'from'            => $window['from'],
            'until'           => $window['until'],
            'resets_on'       => $window['resets_on'],
            'days_left'       => $daysLeft,
            'base_eur'        => $base,
            'extra_eur'       => $extra,
            'limit_eur'       => $limit,
            'alert_pct'       => $alert,
            'on_reach'        => $onReach,
            'spent_eur'       => $spent,
            'usage_eur'       => $usage,
            'maintenance_eur' => $maint,
            'unpriced_ops'    => max(0, (int)($spend['unpriced_ops'] ?? 0)),
            'pct'             => $pct,
            'remaining_eur'   => $limit !== null ? round(max(0.0, $limit - $spent), 2) : null,
            'blocks'          => $state === self::STATE_REACHED && $onReach === self::ON_REACH_BLOCK,
            'projection'      => self::projection($spent, $limit, $now),
            'unavailable'     => false,
        ];
    }

    /**
     * Projection linéaire de fin de mois, au rythme du mois en cours.
     *
     * @return array{projected_eur:float, daily_eur:float, reach_date:?string, over:bool}|null
     */
    public static function projection(float $spent, ?float $limit, DateTimeImmutable $now): ?array
    {
        $window  = self::monthWindow($now);
        $from    = new DateTimeImmutable($window['from']);
        $elapsed = ($now->getTimestamp() - $from->getTimestamp()) / 86400;
        if ($elapsed < self::PROJECTION_MIN_DAYS) {
            return null;
        }
        $daily     = $spent / $elapsed;
        $projected = round($daily * $window['days_in_month'], 2);
        $reachDate = null;
        if ($limit !== null && $spent < $limit && $projected > $limit && $daily > 0) {
            $reachDate = $from->modify('+' . (int)floor(($limit / $daily) * 86400) . ' seconds')->format('Y-m-d');
        }
        return [
            'projected_eur' => $projected,
            'daily_eur'     => round($daily, 2),
            'reach_date'    => $reachDate,
            'over'          => $limit !== null && $projected > $limit,
        ];
    }

    /** Évolution en % d'un montant par rapport au mois précédent (null sous 1 € : pourcentage absurde). */
    public static function evolutionPct(float $current, float $previous): ?float
    {
        if ($previous < 1.0) {
            return null;
        }
        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Le mémo peut-il tenir lieu de dépense ? Non s'il date d'un autre mois,
     * s'il a plus de MEMO_TTL_SEC, ou — en mode « suspendre » — s'il place la
     * dépense entre FRESH_FROM_PCT et 100 % : c'est là que la décision bascule,
     * et une valeur d'il y a une minute laisserait passer des opérations de trop.
     */
    public static function memoUsable(?array $memo, string $month, int $nowTs, ?float $limit, bool $blockMode): bool
    {
        if (!is_array($memo) || ($memo['month'] ?? '') !== $month) {
            return false;
        }
        $age = $nowTs - (int)($memo['at'] ?? 0);
        if ($age < 0 || $age >= self::MEMO_TTL_SEC) {
            return false;
        }
        if ($blockMode && $limit !== null && $limit > 0) {
            $spent = (float)($memo['usage_eur'] ?? 0) + (float)($memo['maintenance_eur'] ?? 0);
            $pct   = $spent / $limit * 100;
            if ($pct >= self::FRESH_FROM_PCT && $pct < 100) {
                return false;
            }
        }
        return true;
    }

    /**
     * Repère de quota : budget converti en crédits, partagé entre les comptes
     * actifs. Si chacun consomme tout son quota, les comptes dépensent
     * exactement le budget (la maintenance s'y ajoute).
     *
     * @return array{rate:float, accounts:int, total_credits:int, per_account_credits:int, per_account_k:int}|null
     */
    public static function repere(float $budgetEur, int $activeAccounts, float $creditsPerEur): ?array
    {
        if ($activeAccounts <= 0 || $budgetEur <= 0 || $creditsPerEur <= 0) {
            return null;
        }
        $total = (int)floor($budgetEur * $creditsPerEur);
        $per   = intdiv($total, $activeAccounts);
        return [
            'rate'                => $creditsPerEur,
            'accounts'            => $activeAccounts,
            'total_credits'       => $total,
            'per_account_credits' => $per,
            'per_account_k'       => intdiv($per, 1000),
        ];
    }

    /**
     * Consommation maximale des quotas en place (quotas effectifs des comptes
     * actifs, en milliers de crédits ; null = aucun quota → non borné).
     *
     * @param list<?int> $quotasK
     * @return array{accounts:int, credits:int, eur:float, unbounded:int}
     */
    public static function quotaCeiling(array $quotasK, float $creditsPerEur): array
    {
        $credits   = 0;
        $unbounded = 0;
        foreach ($quotasK as $k) {
            if ($k === null) {
                $unbounded++;
                continue;
            }
            $credits += max(0, (int)$k) * 1000;
        }
        return [
            'accounts'  => count($quotasK),
            'credits'   => $credits,
            'eur'       => $creditsPerEur > 0 ? round($credits / $creditsPerEur, 2) : 0.0,
            'unbounded' => $unbounded,
        ];
    }

    /**
     * Consommation réelle par compte actif (les comptes sans consommation
     * comptent pour zéro dans la moyenne).
     *
     * @param array<int,int> $creditsByUser
     * @param list<int> $activeIds
     * @return array{accounts:int, total:int, avg:int, max:int}
     */
    public static function consumptionStats(array $creditsByUser, array $activeIds): array
    {
        $total = 0;
        $max   = 0;
        foreach ($activeIds as $id) {
            $c      = max(0, (int)($creditsByUser[(int)$id] ?? 0));
            $total += $c;
            $max    = max($max, $c);
        }
        $n = count($activeIds);
        return ['accounts' => $n, 'total' => $total, 'avg' => $n > 0 ? intdiv($total, $n) : 0, 'max' => $max];
    }

    // =====================================================================
    // Page Budget : rythme, trajectoire, répartition (formes pures)
    // =====================================================================

    public const PACE_OFF     = 'off';      // aucun budget : pas de rythme à juger
    public const PACE_EARLY   = 'early';    // trop tôt dans le mois pour projeter
    public const PACE_STEADY  = 'steady';   // la projection tient sous le plafond
    public const PACE_FAST    = 'fast';     // la projection dépasse le plafond
    public const PACE_REACHED = 'reached';  // le plafond est atteint

    /**
     * Rythme du mois. Le problème résolu : l'état (`ok`, `alert`…) compare la
     * dépense au SEUIL — à 62 % pour un seuil de 80 %, la page disait « Sous
     * contrôle » alors que la projection dépassait le plafond de moitié. Le
     * rythme compare la dépense au TEMPS : il signale ce qui va arriver.
     *
     * `tenable_eur` = ce qu'on peut encore dépenser par jour, jours restants
     * compris aujourd'hui, pour finir le mois sous le plafond.
     *
     * @return array{key:string, daily_eur:?float, tenable_eur:?float}
     */
    public static function pace(array $status): array
    {
        $daily = isset($status['projection']['daily_eur']) ? (float)$status['projection']['daily_eur'] : null;
        $limit = $status['limit_eur'] ?? null;
        if (empty($status['enabled']) || $limit === null) {
            return ['key' => self::PACE_OFF, 'daily_eur' => $daily, 'tenable_eur' => null];
        }
        $remaining = max(0.0, (float)($status['remaining_eur'] ?? 0.0));
        $daysLeft  = max(0, (int)($status['days_left'] ?? 0));
        $tenable   = $daysLeft > 0 ? round($remaining / $daysLeft, 2) : null;

        $key = match (true) {
            ($status['state'] ?? '') === self::STATE_REACHED => self::PACE_REACHED,
            ($status['projection'] ?? null) === null          => self::PACE_EARLY,
            !empty($status['projection']['over'])             => self::PACE_FAST,
            default                                           => self::PACE_STEADY,
        };
        return ['key' => $key, 'daily_eur' => $daily, 'tenable_eur' => $tenable];
    }

    /**
     * Échelle « ronde » d'un axe : pas de 1, 2, 2,5 ou 5 × 10ⁿ, au plus `$ticks`
     * intervalles jusqu'à `$max`.
     *
     * @return array{max:float, step:float}
     */
    public static function niceScale(float $max, int $ticks = 4): array
    {
        $max   = max($max, 1.0);
        $ticks = max(1, $ticks);
        $raw   = $max / $ticks;
        $mag   = 10 ** floor(log10($raw));
        $norm  = $raw / $mag;
        $nice  = match (true) {
            $norm <= 1   => 1,
            $norm <= 2   => 2,
            $norm <= 2.5 => 2.5,
            $norm <= 5   => 5,
            default      => 10,
        };
        $step = $nice * $mag;
        return ['max' => round(ceil($max / $step - 1e-9) * $step, 6), 'step' => round($step, 6)];
    }

    /**
     * Trajectoire du mois : dépense cumulée jour par jour, projection au rythme
     * actuel, mois précédent en fond, plafond et seuil. Le temps est CONTINU :
     * t = 0 au 1er à 0 h, t = jours du mois à la fin ; la dépense cumulée à la
     * fin du jour d est posée en t = d, celle d'aujourd'hui en t = maintenant.
     * Le mois précédent est ramené à la même durée (t = d × jours ÷ jours du
     * mois précédent) : on compare le même moment du mois.
     *
     * Positions en POURCENTAGE de la zone de tracé (x de gauche à droite, y de
     * haut en bas) : le gabarit dessine en SVG étiré et pose les étiquettes en
     * HTML, sans aucun calcul.
     *
     * @param array<string,float> $daily     jours du mois en cours ('Y-m-d' → €)
     * @param array<string,float> $lastDaily jours du mois précédent
     */
    public static function trajectory(array $status, array $daily, array $lastDaily, DateTimeImmutable $now): array
    {
        $from    = new DateTimeImmutable((string)$status['from']);
        $days    = (int)$from->format('t');
        $elapsed = min((float)$days, max(0.0, ($now->getTimestamp() - $from->getTimestamp()) / 86400));
        $spent   = max(0.0, (float)($status['spent_eur'] ?? 0.0));
        $limit   = $status['limit_eur'] ?? null;
        // Service suspendu au plafond : la dépense ne suit plus le rythme, la
        // prolonger annoncerait une fin de mois qui n'aura pas lieu.
        $proj    = !empty($status['blocks']) ? null : ($status['projection'] ?? null);

        // Mois en cours : un point par jour révolu, puis maintenant.
        $points = [['t' => 0.0, 'v' => 0.0, 'day' => null, 'daily' => 0.0]];
        $cumul  = 0.0;
        $done   = (int)floor($elapsed);
        for ($d = 1; $d <= $done; $d++) {
            $date   = $from->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
            $amount = max(0.0, (float)($daily[$date] ?? 0.0));
            $cumul += $amount;
            $points[] = ['t' => (float)$d, 'v' => $cumul, 'day' => $date, 'daily' => $amount];
        }
        if ($elapsed > $done) {
            $date   = $from->modify('+' . $done . ' days')->format('Y-m-d');
            $amount = max(0.0, $spent - $cumul);
            $points[] = ['t' => $elapsed, 'v' => max($cumul, $spent), 'day' => $date, 'daily' => $amount];
        }

        // Mois précédent, ramené à la durée du mois en cours.
        $lastFirst = $from->modify('-1 month');
        $lastDays  = (int)$lastFirst->format('t');
        $lastPts   = [['t' => 0.0, 'v' => 0.0]];
        $lastTotal = 0.0;
        for ($d = 1; $d <= $lastDays; $d++) {
            $lastTotal += max(0.0, (float)($lastDaily[$lastFirst->modify('+' . ($d - 1) . ' days')->format('Y-m-d')] ?? 0.0));
            $lastPts[]  = ['t' => $d * $days / $lastDays, 'v' => $lastTotal];
        }

        $projected = $proj !== null ? (float)$proj['projected_eur'] : null;
        $scale = self::niceScale(max($limit ?? 0.0, $projected ?? 0.0, $lastTotal, $spent) * 1.08);
        $yMax  = $scale['max'];
        $x = static fn(float $t): float => round($t / $days * 100, 2);
        $y = static fn(float $v): float => round(100 - min($v, $yMax) / $yMax * 100, 2);
        $place = static function (array $pts) use ($x, $y): array {
            foreach ($pts as &$p) {
                $p['x'] = $x($p['t']);
                $p['y'] = $y($p['v']);
            }
            unset($p);
            return $pts;
        };

        $yTicks = [];
        for ($v = 0.0; $v <= $yMax + 1e-6; $v += $scale['step']) {
            $yTicks[] = ['v' => round($v, 2), 'y' => $y($v)];
        }
        $xTicks = [];
        foreach (array_unique([1, 8, 15, 22, $days]) as $d) {
            $xTicks[] = ['day' => $d, 'x' => $x($d - 0.5)];
        }

        $reach = null;
        if ($limit !== null && $proj !== null && !empty($proj['reach_date']) && (float)$proj['daily_eur'] > 0) {
            $t = min((float)$days, (float)$limit / (float)$proj['daily_eur']);
            $reach = ['x' => $x($t), 'y' => $y((float)$limit), 'date' => (string)$proj['reach_date']];
        }

        return [
            'days'       => $days,
            'elapsed'    => round($elapsed, 4),
            'today_x'    => $x($elapsed),
            'y_max'      => $yMax,
            'y_ticks'    => $yTicks,
            'x_ticks'    => $xTicks,
            'points'     => $place($points),
            'last'       => $lastTotal > 0
                ? ['points' => $place($lastPts), 'total' => round($lastTotal, 2), 'month' => $lastFirst->format('Y-m')]
                : null,
            'projection' => $projected !== null && $elapsed > 0
                ? ['x1' => $x($elapsed), 'y1' => $y($spent), 'x2' => 100.0, 'y2' => $y($projected), 'eur' => $projected]
                : null,
            'limit'      => $limit !== null ? ['eur' => (float)$limit, 'y' => $y((float)$limit)] : null,
            'alert'      => $limit !== null
                ? ['eur' => round((float)$limit * (int)$status['alert_pct'] / 100, 2), 'y' => $y((float)$limit * (int)$status['alert_pct'] / 100), 'pct' => (int)$status['alert_pct']]
                : null,
            'reach'      => $reach,
        ];
    }

    // ---- Dépenses d'une période (section « Dépenses » de la page) ----------

    /** Jusqu'à cette durée, une barre par jour ; au-delà, par semaine. */
    public const BARS_DAY_MAX  = 62;
    /** Jusqu'à cette durée, une barre par semaine ; au-delà, par mois. */
    public const BARS_WEEK_MAX = 182;
    /** Étiquettes de l'axe des temps, au plus. */
    public const BARS_TICKS    = 7;

    public const GRAIN_DAY   = 'day';
    public const GRAIN_WEEK  = 'week';
    public const GRAIN_MONTH = 'month';

    /** Barre par jour, par semaine ou par mois selon la durée de la période. */
    public static function grain(int $days): string
    {
        return match (true) {
            $days <= self::BARS_DAY_MAX  => self::GRAIN_DAY,
            $days <= self::BARS_WEEK_MAX => self::GRAIN_WEEK,
            default                      => self::GRAIN_MONTH,
        };
    }

    /**
     * Budget d'un mois : le montant mensuel ACTUEL, plus la rallonge si c'est
     * le mois qu'elle concerne. Aucun historique des budgets n'est gardé : un
     * mois passé se compare au budget d'aujourd'hui, et l'écran le dit.
     */
    public static function budgetForMonth(array $config, string $yearMonth): ?float
    {
        if (empty($config['enabled']) || ($config['base_eur'] ?? null) === null) {
            return null;
        }
        $extra = ($config['month'] ?? '') === $yearMonth ? max(0.0, (float)($config['extra_eur'] ?? 0.0)) : 0.0;
        return round((float)$config['base_eur'] + $extra, 2);
    }

    /**
     * Histogramme des dépenses d'une période : une barre par jour, semaine ou
     * mois (`grain()`), plus deux repères — la MOYENNE (le rythme de la
     * période, ramené à l'unité de la barre) et, avec un budget, la PART DU
     * BUDGET de chaque barre : budget du mois ÷ jours du mois, pour chacun de
     * ses jours. Une semaine à cheval sur deux mois, un mois entamé ou coupé
     * par la plage ont donc leur juste part ; le repère se dessine en marches
     * (droit quand toutes les barres ont la même). Une barre au-dessus de sa
     * part est `over`.
     *
     * Positions en POURCENTAGE de la zone de tracé, comme `trajectory()` : le
     * gabarit ne calcule rien.
     *
     * @param array{from:string,to:string} $range bornes incluses ('Y-m-d')
     * @param array<string,float>          $daily UsageLog::dailyCostBetween() ('Y-m-d' → €)
     * @param array                        $config AiBudget::config()
     */
    public static function periodBars(array $range, array $daily, array $config, DateTimeImmutable $now): array
    {
        $from  = new DateTimeImmutable($range['from']);
        $to    = new DateTimeImmutable($range['to']);
        $today = $now->format('Y-m-d');
        $days  = (int)$from->diff($to)->days + 1;
        $grain = self::grain($days);

        $budgetDay = [];
        $buckets   = [];
        $total     = 0.0;
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $ym  = $d->format('Y-m');
            $key = match ($grain) {
                self::GRAIN_DAY  => $ymd,
                self::GRAIN_WEEK => $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d'),
                default          => $ym,
            };
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['key' => $key, 'start' => $ymd, 'end' => $ymd, 'eur' => 0.0, 'days' => 0, 'ref' => null];
            }
            $eur = max(0.0, (float)($daily[$ymd] ?? 0.0));
            $total += $eur;
            $buckets[$key]['end']   = $ymd;
            $buckets[$key]['eur']  += $eur;
            $buckets[$key]['days'] += 1;
            if (!array_key_exists($ym, $budgetDay)) {
                $month = self::budgetForMonth($config, $ym);
                $budgetDay[$ym] = $month !== null ? $month / (int)$d->format('t') : null;
            }
            if ($budgetDay[$ym] !== null) {
                $buckets[$key]['ref'] = ($buckets[$key]['ref'] ?? 0.0) + $budgetDay[$ym];
            }
        }
        $buckets = array_values($buckets);

        $unitDays = match ($grain) {
            self::GRAIN_DAY  => 1.0,
            self::GRAIN_WEEK => 7.0,
            default          => 365.25 / 12,
        };
        $average  = $total > 0 ? round($total / $days * $unitDays, 2) : null;
        $refs     = array_values(array_filter(array_column($buckets, 'ref'), static fn($r): bool => $r !== null));
        $refMax   = $refs !== [] ? max($refs) : null;
        $peakEur  = $buckets !== [] ? max(array_column($buckets, 'eur')) : 0.0;
        $scale    = self::niceScale(max($peakEur, $refMax ?? 0.0, $average ?? 0.0) * 1.1, 3);
        $yMax     = $scale['max'];
        $y = static fn(float $v): float => round(100 - min($v, $yMax) / $yMax * 100, 2);

        $n    = count($buckets);
        $slot = $n > 0 ? 100 / $n : 100.0;
        $bars = [];
        foreach ($buckets as $i => $b) {
            $full = match ($grain) {
                self::GRAIN_DAY  => 1,
                self::GRAIN_WEEK => 7,
                default          => (int)(new DateTimeImmutable($b['start']))->format('t'),
            };
            $current = $b['start'] <= $today && $today <= $b['end'];
            $ref     = $b['ref'] !== null ? round($b['ref'], 2) : null;
            $bars[] = [
                'key'     => $b['key'],
                'start'   => $b['start'],
                'end'     => $b['end'],
                'label'   => self::bucketLabel($grain, $b['start'], $b['end'], $b['days'] < $full),
                'x'       => round($i * $slot, 3),
                'w'       => round($slot, 3),
                'h'       => round(min($b['eur'], $yMax) / $yMax * 100, 2),
                'eur'     => round($b['eur'], 6),
                'ref'     => $ref,
                'ref_y'   => $ref !== null ? $y($ref) : null,
                'over'    => $ref !== null && $b['eur'] > $ref,
                'current' => $current,
                'partial' => $b['days'] < $full,
            ];
        }

        // Repère du budget, en marches : chaque barre porte sa part.
        $refLine = null;
        if ($refMax !== null) {
            $points = [];
            foreach ($bars as $bar) {
                if ($bar['ref_y'] === null) {
                    continue;
                }
                $points[] = ['x' => $bar['x'], 'y' => $bar['ref_y']];
                $points[] = ['x' => round($bar['x'] + $bar['w'], 3), 'y' => $bar['ref_y']];
            }
            $full = array_values(array_filter($bars, static fn(array $b): bool => $b['ref'] !== null && !$b['partial']));
            $shown = $full !== [] ? max(array_column($full, 'ref')) : $refMax;
            $refLine = [
                'eur'     => round($shown, 2),
                'y'       => $y($shown),
                'points'  => $points,
                'uniform' => count(array_unique(array_map(static fn(array $b): string => (string)$b['ref'], $full !== [] ? $full : $bars))) <= 1,
            ];
        }

        $yTicks = [];
        for ($v = 0.0; $v <= $yMax + 1e-6; $v += $scale['step']) {
            $yTicks[] = ['v' => round($v, 2), 'y' => $y($v)];
        }

        $xTicks = [];
        $step   = max(1, (int)ceil($n / self::BARS_TICKS));
        $prev   = null;
        foreach ($bars as $i => $bar) {
            if ($i % $step !== 0) {
                continue;
            }
            $xTicks[] = ['x' => round(($i + 0.5) * $slot, 3), 'label' => self::tickLabel($grain, $bar['start'], $prev)];
            $prev = $bar['start'];
        }

        $peak = null;
        foreach ($bars as $bar) {
            if ($bar['eur'] > 0 && ($peak === null || $bar['eur'] > $peak['eur'])) {
                $peak = $bar;
            }
        }
        $refTotal = $refs !== [] ? round(array_sum($refs), 2) : null;

        return [
            'grain'     => $grain,
            'days'      => $days,
            'total'     => round($total, 6),
            'y_max'     => $yMax,
            'y_ticks'   => $yTicks,
            'x_ticks'   => $xTicks,
            'bars'      => $bars,
            'peak'      => $peak,
            'average'   => $average !== null ? ['eur' => $average, 'y' => $y($average)] : null,
            'ref'       => $refLine,
            'ref_total' => $refTotal,
            'over'      => count(array_filter($bars, static fn(array $b): bool => $b['over'])),
        ];
    }

    /**
     * Ce que montre la bulle d'une barre : le jour (« lun. 5 oct. »), la
     * semaine (« 6 – 12 oct. 2026 ») ou le mois (« oct. 2026 ») ; une barre
     * coupée par la plage dit ses vraies bornes.
     */
    public static function bucketLabel(string $grain, string $start, string $end, bool $partial): string
    {
        if ($grain === self::GRAIN_DAY) {
            return Format::shortDate(new DateTimeImmutable($start));
        }
        if ($grain === self::GRAIN_MONTH && !$partial) {
            return Format::monthLabel(substr($start, 0, 7));
        }
        return UsagePeriod::rangeLabel($start, $end);
    }

    /**
     * Étiquette de l'axe des temps, la plus courte qui reste claire : le mois
     * n'est écrit qu'à la première étiquette et quand il change (« 1 oct. »,
     * « 8 », « 15 »… « 1 nov. ») ; l'année, à la première et en janvier.
     * En anglais, le mois précède le jour (« Oct 1 », « 8 »… « Nov 1 »), comme
     * Format::shortDate.
     */
    public static function tickLabel(string $grain, string $start, ?string $previous): string
    {
        $d = new DateTimeImmutable($start);
        [$month, $year] = explode(' ', Format::monthLabel($d->format('Y-m'))) + [1 => ''];
        $newMonth = $previous === null || substr($previous, 0, 7) !== substr($start, 0, 7);
        $newYear  = $previous === null || substr($previous, 0, 4) !== substr($start, 0, 4);
        $dayMonth = I18n::locale() === 'en'
            ? $month . "\u{00A0}" . $d->format('j')
            : $d->format('j') . "\u{00A0}" . $month;
        return match ($grain) {
            self::GRAIN_DAY  => $newMonth ? $dayMonth : $d->format('j'),
            self::GRAIN_WEEK => $dayMonth,
            default          => $newYear ? $month . "\u{00A0}" . $year : $month,
        };
    }

    /**
     * Les chiffres de la période, en tête de section : total, rythme par jour,
     * évolution face à la période de comparaison (`UsagePeriod::previous`),
     * part du budget consommée au prorata des jours (Σ des parts des barres).
     *
     * @param array $cost     UsageLog::costBetween() de la période
     * @param ?array $previous ['from','to','cost' => UsageLog::costBetween()] ou null
     * @param array $bars     periodBars()
     */
    public static function periodSummary(array $cost, ?array $previous, array $bars): array
    {
        $usage = max(0.0, (float)($cost['usage_eur'] ?? 0.0));
        $maint = max(0.0, (float)($cost['maintenance_eur'] ?? 0.0));
        $total = round($usage + $maint, 6);
        $days  = max(1, (int)($bars['days'] ?? 1));

        $prev = null;
        if ($previous !== null) {
            $pc   = $previous['cost'] ?? [];
            $prev = [
                'from'  => (string)$previous['from'],
                'to'    => (string)$previous['to'],
                'total' => round(max(0.0, (float)($pc['usage_eur'] ?? 0.0)) + max(0.0, (float)($pc['maintenance_eur'] ?? 0.0)), 6),
            ];
        }
        $refTotal = $bars['ref_total'] ?? null;

        return [
            'total'           => $total,
            'usage_eur'       => round($usage, 6),
            'maintenance_eur' => round($maint, 6),
            'operations'      => max(0, (int)($cost['operations'] ?? 0)),
            'unpriced_ops'    => max(0, (int)($cost['unpriced_ops'] ?? 0)),
            'days'            => $days,
            'per_day'         => round($total / $days, 2),
            'previous'        => $prev,
            'evolution'       => $prev !== null ? self::evolutionPct($total, $prev['total']) : null,
            // Arrondi INFÉRIEUR, comme la jauge : 99,6 % ne s'écrit pas « 100 % ».
            'budget_share'    => $refTotal !== null && $refTotal > 0 ? (int)floor($total / $refTotal * 100) : null,
            'budget_total'    => $refTotal,
        ];
    }

    /**
     * « Par fournisseur » : ce que chaque fournisseur d'IA a coûté sur la
     * période, classé, les `$limit` premiers et le reste regroupé.
     *
     * @param list<array{provider:string, cost_eur:float, operations:int}> $rows UsageLog::costByProvider()
     * @return array{rows:list<array{provider:string,cost_eur:float,operations:int,share:float}>, other:?array, total:float}
     */
    public static function providerBreakdown(array $rows, int $limit = 6): array
    {
        $rows = array_values(array_filter($rows, static fn(array $r): bool => (float)($r['cost_eur'] ?? 0) > 0));
        usort($rows, static fn(array $a, array $b): int => (float)$b['cost_eur'] <=> (float)$a['cost_eur']);
        $total = array_sum(array_map(static fn(array $r): float => (float)$r['cost_eur'], $rows));
        $share = static fn(float $eur): float => $total > 0 ? round($eur / $total * 100, 1) : 0.0;

        $shown = [];
        foreach (array_slice($rows, 0, $limit) as $r) {
            $shown[] = [
                'provider'   => (string)$r['provider'],
                'cost_eur'   => round((float)$r['cost_eur'], 6),
                'operations' => (int)($r['operations'] ?? 0),
                'share'      => $share((float)$r['cost_eur']),
            ];
        }
        $rest  = array_slice($rows, $limit);
        $other = null;
        if ($rest !== []) {
            $eur   = array_sum(array_map(static fn(array $r): float => (float)$r['cost_eur'], $rest));
            $other = ['count' => count($rest), 'cost_eur' => round($eur, 6), 'share' => $share($eur)];
        }
        return ['rows' => $shown, 'other' => $other, 'total' => round($total, 6)];
    }

    /**
     * Ce que valent environ 1 000 crédits en coût réel (le pendant de « 1 € ≈ N
     * crédits ») : lecture directe pour qui fixe des quotas en milliers de crédits.
     */
    public static function eurPerThousandCredits(float $creditsPerEur): ?float
    {
        return $creditsPerEur > 0 ? round(1000 / $creditsPerEur, 4) : null;
    }

    /**
     * « Où part l'argent » : catégories du grand livre classées par coût réel,
     * les `$limit` premières, le reste regroupé. Part = part de la dépense.
     *
     * @param list<array> $byCategory sortie de UsageLog::byCategory()
     * @return array{rows:list<array{category:string,label:string,color:string,cost_eur:float,share:float,maintenance:bool}>,
     *               other:?array{count:int,cost_eur:float,share:float}, total:float}
     */
    public static function spendBreakdown(array $byCategory, int $limit = 5): array
    {
        $rows = array_values(array_filter($byCategory, static fn(array $c): bool => (float)($c['cost_eur'] ?? 0) > 0));
        usort($rows, static fn(array $a, array $b): int => (float)$b['cost_eur'] <=> (float)$a['cost_eur']);
        $total = array_sum(array_map(static fn(array $c): float => (float)$c['cost_eur'], $rows));
        $share = static fn(float $eur): float => $total > 0 ? round($eur / $total * 100, 1) : 0.0;

        $shown = [];
        foreach (array_slice($rows, 0, $limit) as $c) {
            $shown[] = [
                'category'    => (string)$c['category'],
                'label'       => (string)$c['label'],
                'color'       => (string)$c['color'],
                'cost_eur'    => round((float)$c['cost_eur'], 6),
                'share'       => $share((float)$c['cost_eur']),
                'maintenance' => !empty($c['quota_exempt']),
            ];
        }
        $rest  = array_slice($rows, $limit);
        $other = null;
        if ($rest !== []) {
            $eur   = array_sum(array_map(static fn(array $c): float => (float)$c['cost_eur'], $rest));
            $other = ['count' => count($rest), 'cost_eur' => round($eur, 6), 'share' => $share($eur)];
        }
        return ['rows' => $shown, 'other' => $other, 'total' => round($total, 6)];
    }

    // =====================================================================
    // Configuration et dépense (base, réglages)
    // =====================================================================

    /** @return array{enabled:bool, base_eur:?float, extra_eur:float, extra:?array, alert_pct:int, on_reach:string, month:string} */
    public static function config(?DateTimeImmutable $now = null): array
    {
        $now   = $now ?? new DateTimeImmutable('now');
        $month = self::monthWindow($now)['month'];
        $raw   = (string)Settings::get(self::SETTING_MONTHLY, '');
        $base  = null;
        if (trim($raw) !== '' && is_numeric($raw)) {
            $base = max(0.0, round((float)$raw, 2));
        }
        $extra = self::extra($now);
        return [
            'enabled'   => $base !== null,
            'base_eur'  => $base,
            'extra_eur' => $base !== null && $extra !== null ? (float)$extra['eur'] : 0.0,
            'extra'     => $base !== null ? $extra : null,
            'alert_pct' => self::normalizeAlertPct(Settings::get(self::SETTING_ALERT_PCT, (string)self::DEFAULT_ALERT_PCT)),
            'on_reach'  => self::normalizeOnReach(Settings::get(self::SETTING_ON_REACH, self::ON_REACH_BLOCK)),
            'month'     => $month,
        ];
    }

    /**
     * Rallonge du mois courant, ou null. Une rallonge d'un autre mois est
     * ignorée : c'est ainsi qu'elle expire, sans tâche planifiée.
     *
     * @return array{month:string, eur:float, by:?int, at:string}|null
     */
    public static function extra(?DateTimeImmutable $now = null): ?array
    {
        $now     = $now ?? new DateTimeImmutable('now');
        $decoded = json_decode((string)Settings::get(self::SETTING_EXTRA, ''), true);
        if (!is_array($decoded) || ($decoded['month'] ?? '') !== self::monthWindow($now)['month']) {
            return null;
        }
        $eur = round((float)($decoded['eur'] ?? 0), 2);
        if ($eur <= 0) {
            return null;
        }
        return [
            'month' => (string)$decoded['month'],
            'eur'   => $eur,
            'by'    => isset($decoded['by']) ? (int)$decoded['by'] : null,
            'at'    => (string)($decoded['at'] ?? ''),
        ];
    }

    /**
     * État du mois. Sans budget et sans `$fresh`, aucune requête (barre
     * latérale, menu, barrière). `$fresh` = écrans d'administration : la
     * dépense est relue et affichée même sans budget (elle aide à en choisir un).
     *
     * RÉSERVÉ à l'administration : ce tableau porte des montants en euros,
     * jamais transmis à une vue utilisateur (cf. CreditQuota::compose).
     *
     * En cas d'erreur, la barrière reste OUVERTE (`blocks = false`) : une
     * panne de base ne doit jamais suspendre tout le monde.
     */
    public static function status(bool $fresh = false, ?DateTimeImmutable $now = null): array
    {
        $now    = $now ?? new DateTimeImmutable('now');
        $zero   = ['usage_eur' => 0.0, 'maintenance_eur' => 0.0, 'unpriced_ops' => 0];
        $config = ['enabled' => false, 'base_eur' => null, 'extra_eur' => 0.0,
                   'alert_pct' => self::DEFAULT_ALERT_PCT, 'on_reach' => self::ON_REACH_BLOCK];
        try {
            $config = self::config($now);
            if (!$config['enabled'] && !$fresh) {
                return self::evaluate($config, $zero, $now);
            }
            return self::evaluate($config, self::spend($config, $now, $fresh), $now);
        } catch (\Throwable $e) {
            error_log('[Budget IA] état indisponible, barrière ouverte : ' . $e->getMessage());
            $status = self::evaluate($config, $zero, $now);
            $status['blocks']      = false;
            $status['unavailable'] = true;
            return $status;
        }
    }

    /**
     * Repère affiché à côté des champs de quota (comptes, groupes) : null sans
     * budget. Aucune lecture du grand livre — la configuration et la liste des
     * comptes déjà chargée suffisent.
     *
     * @param list<array> $users lignes `users` (seuls les comptes actifs comptent)
     * @return array{rate:float, accounts:int, total_credits:int, per_account_credits:int, per_account_k:int, budget_eur:float}|null
     */
    public static function repereForUsers(array $users): ?array
    {
        try {
            $config = self::config();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$config['enabled']) {
            return null;
        }
        $active = count(array_filter($users, static fn(array $u): bool => (int)($u['is_active'] ?? 0) === 1));
        $repere = self::repere((float)$config['base_eur'], $active, CreditService::creditsPerRealEur());
        return $repere === null ? null : $repere + ['budget_eur' => (float)$config['base_eur']];
    }

    /** Les nouvelles demandes des personnes sont-elles suspendues ? */
    public static function blocksRequests(): bool
    {
        return self::status()['blocks'];
    }

    /**
     * La synchronisation AUTOMATIQUE des bases est-elle en pause ? Même règle
     * que les demandes : c'est la seule dépense qui part sans geste humain.
     */
    public static function blocksAutomaticSync(): bool
    {
        return self::status()['blocks'];
    }

    /** @return array{usage_eur:float, maintenance_eur:float, unpriced_ops:int} */
    private static function spend(array $config, DateTimeImmutable $now, bool $fresh): array
    {
        $window = self::monthWindow($now);
        $limit  = $config['enabled'] ? round((float)$config['base_eur'] + (float)$config['extra_eur'], 2) : null;
        $memo   = json_decode((string)Settings::get(self::SETTING_MEMO, ''), true);
        $block  = ($config['on_reach'] ?? '') === self::ON_REACH_BLOCK;

        if (!$fresh && self::memoUsable(is_array($memo) ? $memo : null, $window['month'], $now->getTimestamp(), $limit, $block)) {
            return [
                'usage_eur'       => (float)$memo['usage_eur'],
                'maintenance_eur' => (float)$memo['maintenance_eur'],
                'unpriced_ops'    => (int)($memo['unpriced_ops'] ?? 0),
            ];
        }

        $cost  = UsageLog::costBetween($window['from'], $window['until']);
        $spend = [
            'usage_eur'       => (float)$cost['usage_eur'],
            'maintenance_eur' => (float)$cost['maintenance_eur'],
            'unpriced_ops'    => (int)$cost['unpriced_ops'],
        ];
        try {
            Settings::set(self::SETTING_MEMO, json_encode(
                ['month' => $window['month'], 'at' => $now->getTimestamp()] + $spend,
                JSON_UNESCAPED_UNICODE
            ));
        } catch (\Throwable $e) {
            error_log('[Budget IA] mémo non enregistré : ' . $e->getMessage());
        }
        return $spend;
    }

    /** Oublie la dépense mémorisée : la prochaine lecture relit le grand livre. */
    public static function forgetMemo(): void
    {
        Settings::set(self::SETTING_MEMO, '');
    }

    // =====================================================================
    // Écritures (administration)
    // =====================================================================

    /** Enregistre la règle du budget. Retirer le budget retire aussi la rallonge. */
    public static function save(?float $monthlyEur, int $alertPct, string $onReach, ?int $adminId = null): void
    {
        Settings::set(self::SETTING_MONTHLY, $monthlyEur === null ? '' : number_format($monthlyEur, 2, '.', ''));
        Settings::set(self::SETTING_ALERT_PCT, (string)self::normalizeAlertPct($alertPct));
        Settings::set(self::SETTING_ON_REACH, self::normalizeOnReach($onReach));
        if ($monthlyEur === null) {
            Settings::set(self::SETTING_EXTRA, '');
        }
        self::forgetMemo();
        error_log(sprintf(
            '[Budget IA] règle enregistrée par #%d : %s, alerte %d %%, au plafond : %s',
            (int)$adminId,
            $monthlyEur === null ? 'aucun budget' : number_format($monthlyEur, 2, '.', '') . ' €',
            self::normalizeAlertPct($alertPct),
            self::normalizeOnReach($onReach)
        ));
    }

    /** Accorde (ou remplace) la rallonge du mois en cours. */
    public static function grantExtra(float $eur, int $adminId, ?DateTimeImmutable $now = null): void
    {
        $now = $now ?? new DateTimeImmutable('now');
        Settings::set(self::SETTING_EXTRA, json_encode([
            'month' => self::monthWindow($now)['month'],
            'eur'   => round($eur, 2),
            'by'    => $adminId,
            'at'    => $now->format('Y-m-d H:i:s'),
        ]));
        self::forgetMemo();
        error_log(sprintf('[Budget IA] rallonge de %s € accordée par #%d', number_format($eur, 2, '.', ''), $adminId));
    }

    public static function removeExtra(?int $adminId = null): void
    {
        Settings::set(self::SETTING_EXTRA, '');
        self::forgetMemo();
        error_log(sprintf('[Budget IA] rallonge retirée par #%d', (int)$adminId));
    }
}
