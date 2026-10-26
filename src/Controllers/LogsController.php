<?php

declare(strict_types=1);

/**
 * Écran « Journaux » (Maintenance → Diagnostic → Journaux) : surveiller et
 * investiguer à partir de `storage/logs/errors.log`. Lecture seule — l'écran
 * n'écrit, ne tronque et n'efface jamais le fichier.
 *
 * Mince : la lecture est dans LogFile, l'analyse dans LogParser / LogQuery /
 * LogAnalysis, la mise en forme dans LogView.
 */
class LogsController
{
    /** Entrées du Flux rendues par page (« Afficher plus » pour la suite). */
    public const PAGE_SIZE = 120;

    /** Cookie posé par le navigateur : son fuseau horaire (le serveur est en UTC). */
    public const TZ_COOKIE = 'mia_logs_tz';

    /** GET /admin/logs */
    public function index(): void
    {
        AuthMiddleware::requirePermission('diagnostics.view');

        $now     = time();
        $path    = LogFile::path();
        $stat    = LogFile::stat($path);
        $read    = LogFile::tail($path);
        $entries = LogParser::parse($read['text'], $read['truncated']);
        $read['text'] = '';
        $coverageStart = LogAnalysis::firstTimestamp($entries);
        $query = LogQuery::fromRequest($_GET, self::cookieTz(), $now, $coverageStart);

        if (($_GET['export'] ?? '') !== '') {
            $result = LogAnalysis::run($entries, $query, $coverageStart);
            $this->export(array_reverse($result['matches']), $query);
            return;
        }

        $result   = LogAnalysis::run($entries, $query, $coverageStart);
        $list     = array_slice($result['matches'], 0, self::PAGE_SIZE);
        $nextOffset = $result['total'] > self::PAGE_SIZE ? self::PAGE_SIZE : null;
        $sigCounts = self::sigCounts($result);
        unset($entries);

        require ROOT . '/templates/admin/logs.php';
    }

    /**
     * GET /admin/logs/feed — deux usages, en JSON :
     *   - `after=<octets>` : suivi en direct, ce qui a été écrit depuis ;
     *   - `offset=<n>`     : suite du Flux (« Afficher plus »).
     */
    public function feed(): void
    {
        AuthMiddleware::requirePermission('diagnostics.view');
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $now  = time();
        $path = LogFile::path();

        if (isset($_GET['after'])) {
            $since = LogFile::since($path, (int)$_GET['after']);
            if ($since['reset'] || $since['overflow']) {
                echo json_encode(['reload' => true, 'size' => $since['size']]);
                return;
            }
            $query = LogQuery::fromRequest($_GET, self::cookieTz(), $now, null);
            $fresh = array_values(array_filter(
                LogParser::parse($since['text']),
                static fn(array $e): bool => $query->matches($e)
            ));
            $fresh = array_reverse($fresh);
            $levels = array_fill_keys(array_keys(LogCatalog::LEVELS), 0);
            foreach ($fresh as $e) {
                $levels[$e['level']]++;
            }
            $topDay = (string)($_GET['jour'] ?? '');
            [$newer, $sameDay] = self::splitByDay($fresh, $topDay, $query->tz);
            echo json_encode([
                'size'     => $since['size'],
                'count'    => count($fresh),
                'levels'   => $levels,
                'html_new' => $this->renderEntries($newer, $query, $now, '', null),
                'html_day' => $this->renderEntries($sameDay, $query, $now, $topDay, null),
                'top_day'  => $fresh !== [] && $fresh[0]['ts'] !== null ? LogView::dayKey((int)$fresh[0]['ts'], $query->tz) : $topDay,
                'mtime'    => LogFile::stat($path)['mtime'],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $read    = LogFile::tail($path);
        $entries = LogParser::parse($read['text'], $read['truncated']);
        $coverageStart = LogAnalysis::firstTimestamp($entries);
        $query  = LogQuery::fromRequest($_GET, self::cookieTz(), $now, $coverageStart);
        $result = LogAnalysis::run($entries, $query, $coverageStart);
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $list   = array_slice($result['matches'], $offset, self::PAGE_SIZE);
        $next   = $offset + self::PAGE_SIZE < $result['total'] ? $offset + self::PAGE_SIZE : null;
        $lastDay = (string)($_GET['jour'] ?? '');
        $end = $list !== [] ? end($list) : null;

        echo json_encode([
            'html'     => $this->renderEntries($list, $query, $now, $lastDay, self::sigCounts($result)),
            'next'     => $next,
            'total'    => $result['total'],
            'last_day' => $end !== null && $end['ts'] !== null ? LogView::dayKey((int)$end['ts'], $query->tz) : $lastDay,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Entrées rendues, séparées par journée. `$prevDay` : jour déjà affiché
     * juste avant (pas de séparateur en double).
     *
     * @param list<array<string,mixed>>   $list
     * @param array<string,int>|null      $sigCounts occurrences de chaque problème (null : inconnues)
     */
    private function renderEntries(array $list, LogQuery $query, int $now, string $prevDay, ?array $sigCounts): string
    {
        if ($list === []) {
            return '';
        }
        ob_start();
        require ROOT . '/templates/admin/logs/entries.php';
        return (string)ob_get_clean();
    }

    /**
     * Nouveautés du suivi en direct, réparties entre celles d'un jour plus
     * récent que le premier affiché (avec leur séparateur) et celles du même jour.
     *
     * @param list<array<string,mixed>> $fresh de la plus récente à la plus ancienne
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private static function splitByDay(array $fresh, string $topDay, \DateTimeZone $tz): array
    {
        if ($topDay === '') {
            return [$fresh, []];
        }
        $newer = [];
        $same  = [];
        foreach ($fresh as $e) {
            $day = $e['ts'] !== null ? LogView::dayKey((int)$e['ts'], $tz) : $topDay;
            if ($day === $topDay) {
                $same[] = $e;
            } else {
                $newer[] = $e;
            }
        }
        return [$newer, $same];
    }

    /** @return array<string,int> */
    private static function sigCounts(array $result): array
    {
        return $result['sigCounts'] ?? [];
    }

    private static function cookieTz(): ?string
    {
        $value = $_COOKIE[self::TZ_COOKIE] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Les entrées filtrées, en texte, dans l'ordre chronologique — à joindre
     * à un signalement. Le texte est celui du fichier, à l'octet près.
     *
     * @param list<array<string,mixed>> $entries
     */
    private function export(array $entries, LogQuery $query): void
    {
        $filename = 'journal-mia-' . gmdate('Ymd-His') . '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        // L'en-tête (lignes « # ») suit la langue de l'écran ; les entrées
        // restent le texte du fichier, jamais traduit.
        $levels  = array_map(static fn(string $l): string => LogCatalog::levelLabel($l), $query->levels);
        $filters = [t('Gravités : {liste}', ['liste' => implode(', ', $levels)])];
        if ($query->source !== null) {
            $filters[] = t('Domaine : {nom}', ['nom' => LogCatalog::sourceLabel($query->source)]);
        }
        if ($query->search !== '') {
            $filters[] = t('Recherche : {texte}', ['texte' => $query->search]);
        }
        if ($query->sig !== null) {
            $filters[] = t('Problème : {texte}', ['texte' => $query->sig]);
        }
        if ($query->incident !== null) {
            $filters[] = t('Incident : {id}', ['id' => $query->incident]);
        }
        echo '# ' . t('Journal MIA — export du {date} ({fuseau})', ['date' => LogView::dateTime(time(), $query->tz), 'fuseau' => $query->tz->getName()]) . "\n";
        echo '# ' . t('Période : {du} → {au}', ['du' => LogView::dateTime($query->from, $query->tz), 'au' => LogView::dateTime($query->to, $query->tz)]) . "\n";
        echo '# ' . implode(' | ', $filters) . "\n";
        echo '# ' . I18n::plural(count($entries), '{n} entrée', '{n} entrées') . "\n\n";
        foreach ($entries as $entry) {
            echo (string)$entry['raw'] . "\n";
        }
    }
}
