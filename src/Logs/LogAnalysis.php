<?php

declare(strict_types=1);

/**
 * Ce que l'écran des journaux affiche, calculé en UN passage sur les entrées
 * lues. Pure : aucune lecture, testable sur des entrées fabriquées.
 *
 *   - compteurs par gravité et par domaine (facettes : chacun ignore son propre filtre) ;
 *   - entrées correspondant à tous les filtres (le Flux), de la plus récente à la plus ancienne ;
 *   - PROBLÈMES : entrées regroupées par signature (2 757 fois la même panne = une
 *     ligne, avec sa fréquence, sa première et sa dernière apparition, sa courbe) ;
 *   - frise : volume par tranche de temps, empilé par gravité ;
 *   - comparaison avec la période précédente de même durée, quand le journal la couvre.
 */
final class LogAnalysis
{
    /** Tranches possibles de la frise, en secondes. */
    public const STEPS = [60, 300, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 604800];

    /** Nombre de tranches au plus. */
    public const MAX_BUCKETS = 72;

    /** Problèmes affichés au plus (le compte total reste dit). */
    public const MAX_GROUPS = 150;

    /**
     * @param list<array<string,mixed>> $entries        dans l'ordre du fichier
     * @param int|null                  $coverageStart  première entrée datée du texte analysé
     * @return array<string,mixed>
     */
    public static function run(array $entries, LogQuery $q, ?int $coverageStart): array
    {
        $levelCounts  = array_fill_keys(array_keys(LogCatalog::LEVELS), 0);
        $sourceCounts = array_fill_keys(array_keys(LogCatalog::SOURCES), 0);
        $histogram    = self::histogram($q);
        $step         = $histogram['step'];
        $start        = $histogram['start'];
        $nBuckets     = count($histogram['buckets']);

        $matches     = [];
        $groups      = [];
        $beforeSigs  = [];
        $lastProblem = null;
        $current     = 0;
        $previous    = 0;
        $prevFrom    = $q->from - $q->span();
        $comparable  = $q->period !== 'tout' && $coverageStart !== null && $coverageStart <= $prevFrom;
        $search      = $q->hasSearch();

        foreach ($entries as $e) {
            if ($search) {
                $e['lower'] = mb_strtolower((string)$e['raw']);
            }
            $ts   = $e['ts'];
            $rank = LogCatalog::rank($e['level']);

            if ($ts !== null && $ts < $q->from) {
                $beforeSigs[$e['sig']] = true;
            }
            if ($rank >= 2) {
                $lastProblem = $e;
            }
            if ($comparable && $rank >= 2 && $ts !== null && $ts >= $prevFrom && $ts < $q->from
                && $q->matches($e, ['time', 'level'])) {
                $previous++;
            }

            $inWindow = $q->matches($e, ['level', 'source', 'search', 'sig', 'incident']);
            if (!$inWindow) {
                continue;
            }
            if ($q->matches($e, ['level'])) {
                $levelCounts[$e['level']]++;
                if ($rank >= 2) {
                    $current++;
                }
            }
            if ($q->matches($e, ['source'])) {
                $sourceCounts[$e['source']]++;
            }
            if (!$q->matches($e, ['sig'])) {
                continue;
            }

            $bucket = $ts !== null ? (int)floor(($ts - $start) / $step) : null;
            if ($bucket !== null && ($bucket < 0 || $bucket >= $nBuckets)) {
                $bucket = null;
            }

            // Problèmes : tous les filtres sauf la signature.
            $sig = $e['sig'];
            if (!isset($groups[$sig])) {
                $groups[$sig] = [
                    'sig'       => $sig,
                    'level'     => $e['level'],
                    'source'    => $e['source'],
                    'tag'       => $e['tag'],
                    'location'  => $e['location'],
                    'count'     => 0,
                    'first'     => $ts,
                    'last'      => $ts,
                    'sample'    => $e,
                    'incidents' => 0,
                    'buckets'   => array_fill(0, $nBuckets, 0),
                ];
            }
            $g = &$groups[$sig];
            $g['count']++;
            if ($e['incident'] !== null) {
                $g['incidents']++;
            }
            if (LogCatalog::rank($e['level']) > LogCatalog::rank($g['level'])) {
                $g['level'] = $e['level'];
            }
            if ($ts !== null) {
                $g['first'] = $g['first'] === null ? $ts : min($g['first'], $ts);
                if ($g['last'] === null || $ts >= $g['last']) {
                    $g['last']   = $ts;
                    $g['sample'] = $e;
                }
            }
            if ($bucket !== null) {
                $g['buckets'][$bucket]++;
            }
            unset($g);

            if ($q->sig !== null && $sig !== $q->sig) {
                continue;
            }
            unset($e['lower']);
            $matches[] = $e;
            if ($bucket !== null) {
                $histogram['buckets'][$bucket]['counts'][$e['level']]++;
                $histogram['buckets'][$bucket]['total']++;
            }
        }

        $newCount = 0;
        $knowsBefore = $coverageStart !== null && $coverageStart < $q->from;
        foreach ($groups as &$g) {
            $g['new'] = $knowsBefore && !isset($beforeSigs[$g['sig']]);
            if ($g['new'] && LogCatalog::rank($g['level']) >= 1) {
                $newCount++;
            }
        }
        unset($g);

        $groups = array_values($groups);
        usort($groups, self::groupComparator($q->sort));
        $sigCounts = array_column($groups, 'count', 'sig');
        $groupTotal = count($groups);

        // Du plus récent au plus ancien. Un tri, pas un simple renversement :
        // plusieurs processus écrivent dans le fichier, l'ordre n'y est pas garanti.
        $matches = array_reverse($matches);
        usort($matches, static fn(array $a, array $b): int => ($b['ts'] ?? PHP_INT_MIN) <=> ($a['ts'] ?? PHP_INT_MIN));
        $histogram['max'] = 0;
        foreach ($histogram['buckets'] as $b) {
            $histogram['max'] = max($histogram['max'], $b['total']);
        }

        return [
            'levelCounts'  => $levelCounts,
            'sourceCounts' => $sourceCounts,
            'matches'      => $matches,
            'total'        => count($matches),
            'groups'       => array_slice($groups, 0, self::MAX_GROUPS),
            'groupTotal'   => $groupTotal,
            'sigCounts'    => $sigCounts,
            'newCount'     => $newCount,
            'histogram'    => $histogram,
            'current'      => $current,
            'previous'     => $comparable ? $previous : null,
            'lastProblem'  => $lastProblem,
        ];
    }

    /**
     * Tranches de la frise : la plus fine qui tient en MAX_BUCKETS, alignée
     * sur l'heure (ou minuit) du fuseau de la personne.
     *
     * @return array{step:int,start:int,buckets:list<array{from:int,to:int,counts:array<string,int>,total:int}>,max:int}
     */
    public static function histogram(LogQuery $q): array
    {
        $span = $q->span();
        $step = self::STEPS[count(self::STEPS) - 1];
        foreach (self::STEPS as $candidate) {
            if ((int)ceil($span / $candidate) <= self::MAX_BUCKETS) {
                $step = $candidate;
                break;
            }
        }
        $offset = $q->tz->getOffset(new \DateTimeImmutable('@' . $q->from));
        $start  = (int)(floor(($q->from + $offset) / $step) * $step) - $offset;
        $count  = max(1, (int)ceil(($q->to - $start + 1) / $step));
        $buckets = [];
        for ($i = 0; $i < $count; $i++) {
            $buckets[] = [
                'from'   => $start + $i * $step,
                'to'     => $start + ($i + 1) * $step,
                'counts' => array_fill_keys(array_keys(LogCatalog::LEVELS), 0),
                'total'  => 0,
            ];
        }
        return ['step' => $step, 'start' => $start, 'buckets' => $buckets, 'max' => 0];
    }

    /** @return callable(array<string,mixed>,array<string,mixed>):int */
    private static function groupComparator(string $sort): callable
    {
        return static function (array $a, array $b) use ($sort): int {
            $last = ((int)($b['last'] ?? 0)) <=> ((int)($a['last'] ?? 0));
            return match ($sort) {
                'frequence' => ($b['count'] <=> $a['count']) ?: $last,
                'recent'    => $last,
                default     => [LogCatalog::rank($b['level']), (int)($b['last'] ?? 0)]
                    <=> [LogCatalog::rank($a['level']), (int)($a['last'] ?? 0)],
            };
        };
    }

    /**
     * Plus ancienne entrée datée du texte analysé : début de ce que l'écran
     * peut montrer. Le minimum, pas la première ligne — plusieurs processus
     * écrivent dans le même fichier et l'ordre n'y est pas garanti à la seconde.
     *
     * @param list<array<string,mixed>> $entries
     */
    public static function firstTimestamp(array $entries): ?int
    {
        $min = null;
        foreach ($entries as $e) {
            if ($e['ts'] !== null && ($min === null || $e['ts'] < $min)) {
                $min = (int)$e['ts'];
            }
        }
        return $min;
    }
}
