<?php

declare(strict_types=1);

/**
 * Réglages → Usage & crédits : ce que la page montre à CHAQUE personne — son solde,
 * la prévision de fin de mois, ses camemberts d'utilisation, le coût moyen de
 * chaque action et des conseils tirés de son propre usage. Aucun euro : la
 * personne raisonne en crédits, le quota aussi.
 *
 * Formes PURES (aucune base, aucune traduction) : le contrôleur fournit les
 * lectures du grand livre, le gabarit traduit. Les conseils sont rendus comme
 * des CLÉS + paramètres, pour que le texte reste dans le gabarit (i18n).
 */
final class PersonalUsage
{
    public const STATE_UNLIMITED = 'unlimited'; // aucun quota
    public const STATE_EARLY     = 'early';     // trop tôt pour prévoir
    public const STATE_OK        = 'ok';        // le quota tiendra jusqu'au bout du mois
    public const STATE_TIGHT     = 'tight';     // au rythme actuel, le quota sera atteint avant la fin du mois
    public const STATE_REACHED   = 'reached';   // le quota est atteint

    /** Rayon des anneaux (viewBox 0 0 100 100) : circonférence = 2πr. */
    public const RING_RADIUS = 40;
    /** Parts au-delà desquelles le reste est regroupé en « Autres ». */
    public const RING_SLICES = 5;
    /** Au plus ce nombre de conseils. */
    public const MAX_TIPS = 3;

    /**
     * Solde et prévision du mois. Le rythme est celui du budget de
     * l'organisation (AiBudget::projection) appliqué aux crédits de la
     * personne : même calcul, même seuil de deux jours avant de prévoir.
     *
     * @return array{state:string, used:int, quota:?int, remaining:?int, pct:?int, daily:?float,
     *               projected:?int, proj_pct:?int, reach_date:?string, per_day_left:?int, days_left:int}
     */
    public static function forecast(?int $quota, int $used, DateTimeImmutable $now): array
    {
        $window   = AiBudget::monthWindow($now);
        $until    = new DateTimeImmutable($window['until']);
        $daysLeft = max(0, (int)ceil(($until->getTimestamp() - $now->getTimestamp()) / 86400));
        $used     = max(0, $used);
        $proj     = AiBudget::projection((float)$used, $quota !== null ? (float)$quota : null, $now);

        $remaining = $quota !== null ? max(0, $quota - $used) : null;
        $state = match (true) {
            $quota === null                  => self::STATE_UNLIMITED,
            $used >= $quota                  => self::STATE_REACHED,
            $proj === null                   => self::STATE_EARLY,
            !empty($proj['over'])            => self::STATE_TIGHT,
            default                          => self::STATE_OK,
        };

        return [
            'state'        => $state,
            'used'         => $used,
            'quota'        => $quota,
            'remaining'    => $remaining,
            'pct'          => $quota !== null && $quota > 0 ? (int)min(100, floor($used / $quota * 100)) : null,
            'daily'        => $proj !== null ? (float)$proj['daily_eur'] : null,
            'projected'    => $proj !== null ? (int)round((float)$proj['projected_eur']) : null,
            'proj_pct'     => $proj !== null && $quota !== null && $quota > 0
                ? (int)min(100, floor((float)$proj['projected_eur'] / $quota * 100)) : null,
            'reach_date'   => $proj['reach_date'] ?? null,
            'per_day_left' => $remaining !== null && $daysLeft > 0 ? (int)floor($remaining / $daysLeft) : null,
            'days_left'    => $daysLeft,
        ];
    }

    /**
     * Catégories du grand livre ramenées à ce qui entame le quota : chaque
     * catégorie perd ses sources hors quota (la vectorisation des bases dans
     * « Bases de connaissances », les tests d'outils dans « Administration »).
     * Même base que le solde : les parts se lisent contre lui.
     *
     * @param list<array> $byCategory UsageLog::byCategory()
     * @return list<array> catégories (clés d'origine) avec `credits` et `requests` du quota seulement
     */
    public static function quotaCategories(array $byCategory): array
    {
        $out = [];
        foreach ($byCategory as $c) {
            $credits = $requests = 0;
            foreach ($c['sources'] ?? [] as $s) {
                if (!UsageSource::isQuotaExempt((string)($s['source'] ?? ''))) {
                    $credits  += (int)$s['credits'];
                    $requests += (int)$s['requests'];
                }
            }
            if ($credits > 0) {
                $out[] = ['credits' => $credits, 'requests' => $requests] + $c;
            }
        }
        return $out;
    }

    /**
     * Anneau : parts classées, les plus petites regroupées en « Autres » au-delà
     * de `RING_SLICES`. Chaque part porte son tracé (`dash`, `offset` pour
     * `stroke-dasharray`/`stroke-dashoffset`) et un léger espace entre parts.
     *
     * @param list<array{label:string, value:float|int, color:string}> $items (autres clés conservées)
     * @return array{total:float, slices:list<array>, circumference:float}
     */
    public static function ring(array $items, string $othersLabel, string $othersColor = '#94a3b8'): array
    {
        $items = array_values(array_filter($items, static fn(array $i): bool => (float)$i['value'] > 0));
        usort($items, static fn(array $a, array $b): int => (float)$b['value'] <=> (float)$a['value']);
        if (count($items) > self::RING_SLICES) {
            $rest  = array_slice($items, self::RING_SLICES - 1);
            $items = array_slice($items, 0, self::RING_SLICES - 1);
            $items[] = [
                'label' => $othersLabel, 'value' => array_sum(array_map(static fn(array $i): float => (float)$i['value'], $rest)),
                'color' => $othersColor, 'others' => count($rest),
            ];
        }
        $total = array_sum(array_map(static fn(array $i): float => (float)$i['value'], $items));
        $c     = 2 * M_PI * self::RING_RADIUS;
        $gap   = count($items) > 1 ? 1.2 : 0.0;
        $acc   = 0.0;
        $slices = [];
        foreach ($items as $i) {
            $len = $total > 0 ? (float)$i['value'] / $total * $c : 0.0;
            $slices[] = $i + [
                'share'  => $total > 0 ? round((float)$i['value'] / $total * 100, 1) : 0.0,
                'dash'   => round(max(0.4, $len - $gap), 2),
                'offset' => round(-$acc, 2),
            ];
            $acc += $len;
        }
        return ['total' => $total, 'slices' => $slices, 'circumference' => round($c, 2)];
    }

    /**
     * Coût moyen des actions courantes : une réponse, une image, une recherche
     * Internet, une minute de voix. Seulement celles qui ont servi.
     *
     * @param list<array> $byCategory UsageLog::byCategory()
     * @param list<array> $byTool     UsageLog::byTool()
     * @return array<string,int> clé → crédits (arrondis)
     */
    public static function unitCosts(array $byCategory, array $byTool): array
    {
        $cats = array_column($byCategory, null, 'category');
        $out  = [];
        $chat = $cats[UsageSource::CAT_CHAT] ?? null;
        if ($chat !== null && (int)$chat['requests'] > 0 && (int)$chat['credits'] > 0) {
            $out['answer'] = (int)round($chat['credits'] / $chat['requests']);
        }
        $images = array_filter($byTool, static fn(array $t): bool => ($t['tool_type'] ?? '') === 'image');
        $uses   = array_sum(array_column($images, 'uses'));
        $credit = array_sum(array_column($images, 'credits'));
        if ($uses > 0 && $credit > 0) {
            $out['image'] = (int)round($credit / $uses);
        }
        $web = $cats[UsageSource::CAT_WEB_SEARCH] ?? null;
        if ($web !== null && (float)$web['quantity'] > 0 && (int)$web['credits'] > 0) {
            $out['search'] = (int)round($web['credits'] / $web['quantity']);
        }
        $voice = $cats[UsageSource::CAT_VOICE] ?? null;
        if ($voice !== null && (float)$voice['quantity'] > 0 && (int)$voice['credits'] > 0) {
            $out['minute'] = (int)round($voice['credits'] / $voice['quantity']);
        }
        return $out;
    }

    /**
     * Conseils tirés de l'usage du mois, du plus utile au moins utile. Rendus
     * en clés : le gabarit écrit la phrase dans la langue de la personne.
     *
     * - `model`     : deux modèles DÉJÀ utilisés par la personne (donc permis),
     *                 l'un au moins deux fois plus cher par réponse ;
     * - `web`       : la recherche Internet pèse lourd (elle est cochée par défaut) ;
     * - `regen`     : beaucoup de réponses relancées ;
     * - `creations` : les créations pèsent lourd.
     *
     * @param list<array> $byCategory UsageLog::byCategory()
     * @param list<array{provider:string, model:string, responses:int, credits:int}> $responsesByModel
     * @param callable(string,string):string $label
     * @return list<array{key:string, params:array<string,string|int>}>
     */
    public static function tips(array $byCategory, array $responsesByModel, callable $label): array
    {
        $tips  = [];
        $cats  = array_column($byCategory, null, 'category');
        $quota = array_sum(array_map(static fn(array $c): int => !empty($c['quota_exempt']) ? 0 : (int)$c['credits'], $byCategory));

        $models = array_values(array_filter($responsesByModel, static fn(array $m): bool => (int)$m['responses'] >= 3 && (int)$m['credits'] > 0));
        if (count($models) >= 2) {
            usort($models, static fn(array $a, array $b): int => ($b['credits'] / $b['responses']) <=> ($a['credits'] / $a['responses']));
            $hi = $models[0];
            $lo = $models[count($models) - 1];
            $hiAvg = (int)round($hi['credits'] / $hi['responses']);
            $loAvg = max(1, (int)round($lo['credits'] / $lo['responses']));
            if ($hiAvg >= 2 * $loAvg) {
                $tips[] = ['key' => 'model', 'params' => [
                    'cher' => $label($hi['provider'], $hi['model']), 'n_cher' => $hiAvg,
                    'econome' => $label($lo['provider'], $lo['model']), 'n_econome' => $loAvg,
                ]];
            }
        }
        $share = static fn(string $cat): float => $quota > 0 ? (float)($cats[$cat]['credits'] ?? 0) / $quota * 100 : 0.0;
        if ($share(UsageSource::CAT_WEB_SEARCH) >= 15) {
            $tips[] = ['key' => 'web', 'params' => ['part' => (int)round($share(UsageSource::CAT_WEB_SEARCH))]];
        }
        $chat = $cats[UsageSource::CAT_CHAT] ?? null;
        if ($chat !== null) {
            $regen = 0;
            foreach ($chat['sources'] ?? [] as $s) {
                if (($s['source'] ?? '') === UsageSource::REGENERATE) {
                    $regen += (int)$s['requests'];
                }
            }
            if ((int)$chat['requests'] >= 10 && $regen / (int)$chat['requests'] >= 0.1) {
                $tips[] = ['key' => 'regen', 'params' => ['part' => (int)round($regen / (int)$chat['requests'] * 100)]];
            }
        }
        if ($share(UsageSource::CAT_CREATION) >= 25) {
            $tips[] = ['key' => 'creations', 'params' => ['part' => (int)round($share(UsageSource::CAT_CREATION))]];
        }
        return array_slice($tips, 0, self::MAX_TIPS);
    }
}
