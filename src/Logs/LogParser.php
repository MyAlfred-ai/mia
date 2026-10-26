<?php

declare(strict_types=1);

/**
 * Lecture du texte de `storage/logs/errors.log` en ENTRÉES structurées. Pure.
 *
 * Une entrée commence par un horodatage `[18-Oct-2026 09:12:03 UTC]` ; les
 * lignes qui suivent sans horodatage (pile d'appels, corps multi-lignes) lui
 * appartiennent. Les lignes d'un même INCIDENT d'ErrorHandler — le résumé,
 * puis la pile, puis les « causée par » — sont écrites par trois appels à
 * error_log : elles sont réunies en une seule entrée, sinon une panne se lit
 * comme trois.
 *
 * Forme d'une entrée :
 *   ts        int|null   horodatage Unix (null : ligne sans horodatage lisible)
 *   level     string     clé de LogCatalog::LEVELS
 *   source    string     clé de LogCatalog::SOURCES
 *   tag       string|null étiquette (« JobQueue », « PHP Warning », classe d'exception)
 *   incident  string|null identifiant d'incident (12 hexadécimaux)
 *   message   string     première ligne, sans horodatage, étiquette ni emplacement
 *   location  string|null « src/LLM/OpenAIAdapter.php:120 » (chemin relatif à l'application)
 *   trace     string     pile d'appels (lignes « #0 … »), vide sinon
 *   causes    list<string> chaîne des exceptions précédentes
 *   extra     string     autres lignes de suite
 *   raw       string     texte d'origine, tel qu'écrit dans le fichier
 *   sig       string     signature du problème (LogCatalog::fingerprint)
 */
final class LogParser
{
    /** `[18-Oct-2026 09:12:03 UTC]` (error_log de PHP) ou ISO 8601. */
    private const START = '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Za-z_\/+\-0-9]+)?|\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\]\s?(.*)$/s';

    /** Préfixes des chemins de l'application, retirés pour l'affichage. */
    private const ROOTS = ['/var/www/html/'];

    /** Deux lignes d'un même incident sont écrites dans la même seconde, à peu près. */
    private const INCIDENT_GAP_SEC = 5;

    /**
     * @param string $text            contenu (ou fin) du fichier
     * @param bool   $dropLeadingTail le texte commence au milieu d'une entrée
     *                                (lecture de la fin du fichier) : la jeter
     * @return list<array<string,mixed>> entrées dans l'ordre du fichier
     */
    public static function parse(string $text, bool $dropLeadingTail = false): array
    {
        if ($text === '') {
            return [];
        }
        $text  = str_replace("\r\n", "\n", $text);
        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        $entries = [];
        /** @var array<string,mixed>|null $current */
        $current = null;
        $tsCache = [];
        $started = !$dropLeadingTail;

        foreach ($lines as $line) {
            if (preg_match(self::START, $line, $m) === 1) {
                $started = true;
                if ($current !== null) {
                    self::push($entries, $current);
                }
                $rawTs = $m[1];
                if (!array_key_exists($rawTs, $tsCache)) {
                    if (count($tsCache) > 512) {
                        $tsCache = [];
                    }
                    $tsCache[$rawTs] = self::timestamp($rawTs);
                }
                $current = ['ts' => $tsCache[$rawTs], 'head' => $m[2], 'more' => [], 'raw' => [$line]];
                continue;
            }
            if (!$started) {
                continue;
            }
            if ($current === null) {
                // Texte sans horodatage en tête du fichier : une entrée à part entière.
                $current = ['ts' => null, 'head' => $line, 'more' => [], 'raw' => [$line]];
                continue;
            }
            $current['more'][] = $line;
            $current['raw'][]  = $line;
        }
        if ($current !== null) {
            self::push($entries, $current);
        }

        return $entries;
    }

    /**
     * Entrée achevée : analysée, puis fusionnée à la précédente si elle en
     * prolonge l'incident (pile d'appels, cause).
     *
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed>       $pending
     */
    private static function push(array &$entries, array $pending): void
    {
        $entry = self::build($pending);
        $last  = $entries !== [] ? count($entries) - 1 : null;

        if ($entry['incident'] !== null && $last !== null) {
            $prev = $entries[$last];
            $close = $prev['ts'] === null || $entry['ts'] === null
                || abs((int)$entry['ts'] - (int)$prev['ts']) <= self::INCIDENT_GAP_SEC;
            if ($prev['incident'] === $entry['incident'] && $close) {
                $entries[$last] = self::mergeIncident($prev, $entry);
                return;
            }
        }
        $entries[] = $entry;
    }

    /**
     * @param array<string,mixed> $pending
     * @return array<string,mixed>
     */
    private static function build(array $pending): array
    {
        $head     = rtrim((string)$pending['head']);
        $more     = $pending['more'];
        $tag      = null;
        $incident = null;
        $location = null;
        $part     = 'summary';

        if (preg_match('/^\[incident ([0-9a-f]{12})\]\s?(.*)$/s', $head, $m) === 1) {
            $incident = $m[1];
            $head     = $m[2];
            if (preg_match('/^#\d+\s/', $head) === 1) {
                $part = 'trace';
            } elseif (preg_match('/^causée par\s/u', $head) === 1) {
                $part = 'cause';
            } elseif (preg_match('/^([^:\s][^:]{0,118}?): (.*)$/s', $head, $c) === 1) {
                $tag  = trim($c[1]);
                $head = $c[2];
            }
        } elseif (preg_match('/^(PHP [A-Za-z ]+?):\s+(.*)$/s', $head, $m) === 1) {
            $tag  = $m[1];
            $head = $m[2];
        } elseif (preg_match('/^\[([^\]\[:]{1,48})\]\s?(.*)$/su', $head, $m) === 1) {
            $tag  = trim($m[1]);
            $head = $m[2];
        }

        if ($part === 'summary') {
            [$head, $location] = self::splitLocation($head);
        }

        // Suite : pile d'appels (#0 …, « Stack trace: », « thrown in ») ou texte libre.
        $traceLines = [];
        $extraLines = [];
        foreach ($more as $line) {
            if (preg_match('/^(?:#\d+\s|Stack trace:|\s+thrown in\s|\{main\})/', $line) === 1) {
                $traceLines[] = $line;
            } else {
                $extraLines[] = $line;
            }
        }
        if ($part === 'trace') {
            array_unshift($traceLines, $head);
        }
        if ($location === null && $traceLines !== []) {
            foreach ($traceLines as $line) {
                if (preg_match('/^\s+thrown in (\S+) on line (\d+)/', $line, $t) === 1) {
                    $location = self::shortPath($t[1]) . ':' . $t[2];
                    break;
                }
            }
        }

        $message = $part === 'summary' ? trim($head) : '';
        $level   = LogCatalog::levelOf($tag, $message, $incident !== null);
        $source  = LogCatalog::sourceOf($tag, self::locationFile($location));

        return [
            'ts'       => $pending['ts'],
            'level'    => $level,
            'source'   => $source,
            'tag'      => $tag,
            'incident' => $incident,
            'message'  => $message,
            'location' => $location,
            'trace'    => implode("\n", $traceLines),
            'causes'   => $part === 'cause' ? [trim($head)] : [],
            'extra'    => implode("\n", $extraLines),
            'raw'      => implode("\n", $pending['raw']),
            'sig'      => LogCatalog::fingerprint($tag, $message !== '' ? $message : (string)$head),
            'part'     => $part,
        ];
    }

    /**
     * Ligne suivante d'un même incident : la pile d'appels ou une cause
     * rejoint l'entrée du résumé ; un résumé arrivé APRÈS sa pile (ordre
     * inattendu) prend sa place.
     *
     * @param array<string,mixed> $prev
     * @param array<string,mixed> $next
     * @return array<string,mixed>
     */
    private static function mergeIncident(array $prev, array $next): array
    {
        $merged = $prev;
        $merged['raw'] = $prev['raw'] . "\n" . $next['raw'];
        if ($next['trace'] !== '') {
            $merged['trace'] = trim($prev['trace'] . "\n" . $next['trace']);
        }
        if ($next['causes'] !== []) {
            $merged['causes'] = array_merge($prev['causes'], $next['causes']);
        }
        if ($next['extra'] !== '') {
            $merged['extra'] = trim($prev['extra'] . "\n" . $next['extra']);
        }
        if ($prev['part'] !== 'summary' && $next['part'] === 'summary') {
            foreach (['level', 'source', 'tag', 'message', 'location', 'sig'] as $key) {
                $merged[$key] = $next[$key];
            }
            $merged['part'] = 'summary';
        }
        return $merged;
    }

    /**
     * Sépare l'emplacement de fin de message : « … @ /chemin/x.php:12 »
     * (ErrorHandler) ou « … in /chemin/x.php on line 12 » / « … in /chemin/x.php:12 » (PHP).
     *
     * @return array{0:string,1:?string}
     */
    public static function splitLocation(string $message): array
    {
        if (preg_match('/^(.*?)\s+@\s+(\S+?):(\d+)\s*$/s', $message, $m) === 1) {
            return [$m[1], self::shortPath($m[2]) . ':' . $m[3]];
        }
        if (preg_match('/^(.*?)\s+in\s+(\/\S+?)\s+on line\s+(\d+)\s*$/s', $message, $m) === 1) {
            return [$m[1], self::shortPath($m[2]) . ':' . $m[3]];
        }
        if (preg_match('/^(.*?)\s+in\s+(\/\S+?\.php):(\d+)\s*$/s', $message, $m) === 1) {
            return [$m[1], self::shortPath($m[2]) . ':' . $m[3]];
        }
        return [$message, null];
    }

    /** Chemin relatif à l'application (« src/LLM/OpenAIAdapter.php »). */
    public static function shortPath(string $path): string
    {
        $roots = self::ROOTS;
        if (defined('ROOT')) {
            $roots[] = rtrim((string)ROOT, '/') . '/';
        }
        foreach ($roots as $root) {
            if ($root !== '/' && str_starts_with($path, $root)) {
                return substr($path, strlen($root));
            }
        }
        return $path;
    }

    private static function locationFile(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }
        return preg_replace('/:\d+$/', '', $location) ?? $location;
    }

    /** Horodatage d'error_log (`18-Oct-2026 09:12:03 UTC`) ou ISO 8601. */
    public static function timestamp(string $raw): ?int
    {
        $raw = trim($raw);
        $utc = new \DateTimeZone('UTC');
        foreach (['d-M-Y H:i:s e', 'd-M-Y H:i:s T', 'd-M-Y H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw, $utc);
            if ($date instanceof \DateTimeImmutable) {
                return $date->getTimestamp();
            }
        }
        try {
            return (new \DateTimeImmutable($raw, $utc))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
