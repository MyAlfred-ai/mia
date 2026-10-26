<?php

declare(strict_types=1);

/**
 * Mise en forme de l'écran des journaux. Pure. Dates, tailles et nombres
 * suivent la langue de l'écran (I18n::locale()), dans le fuseau de la
 * personne. Tables de mois et de jours écrites à la main, comme dans Format :
 * aucune dépendance à ext-intl.
 */
final class LogView
{
    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const MONTHS_LONG = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const DAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    private const MONTHS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    private const MONTHS_LONG_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    private const DAYS_EN = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** Vrai en anglais : un seul point de décision. */
    private static function en(): bool
    {
        return I18n::locale() === 'en';
    }

    public static function size(int $bytes): string
    {
        $units = self::en() ? ['B', 'KB', 'MB', 'GB'] : ['o', 'Ko', 'Mo', 'Go'];
        if ($bytes <= 0) {
            return '0 ' . $units[0];
        }
        $i = 0;
        $n = (float)$bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        $value = $i === 0
            ? (string)(int)$n
            : (self::en() ? number_format($n, $n >= 100 ? 0 : 1, '.', ',') : number_format($n, $n >= 100 ? 0 : 1, ',', ' '));
        return $value . "\u{00A0}" . $units[$i];
    }

    public static function number(int $n): string
    {
        return self::en() ? number_format($n, 0, '.', ',') : number_format($n, 0, ',', "\u{202F}");
    }

    private static function at(int $ts, \DateTimeZone $tz): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
    }

    /** « 18 oct. » / « Oct 18 ». */
    private static function dayMonth(\DateTimeImmutable $d): string
    {
        $m = (int)$d->format('n') - 1;
        return self::en()
            ? self::MONTHS_EN[$m] . "\u{00A0}" . (int)$d->format('j')
            : (int)$d->format('j') . "\u{00A0}" . self::MONTHS[$m];
    }

    public static function clock(int $ts, \DateTimeZone $tz): string
    {
        return self::at($ts, $tz)->format('H:i:s');
    }

    /** « 18/10/2026 14:22:03 » (FR) / « 10/18/2026 14:22:03 » (EN), comme Format::dateFr(). */
    public static function dateTime(int $ts, \DateTimeZone $tz): string
    {
        $d = self::at($ts, $tz);
        return Format::dateFr($d->format('Y-m-d')) . ' ' . $d->format('H:i:s');
    }

    /** « 18 oct. 14:22 » / « Oct 18 14:22 » (année ajoutée si ce n'est pas celle de `$now`). */
    public static function short(int $ts, \DateTimeZone $tz, int $now): string
    {
        $d = self::at($ts, $tz);
        $year = $d->format('Y') !== self::at($now, $tz)->format('Y') ? (self::en() ? ', ' : ' ') . $d->format('Y') : '';
        return self::dayMonth($d) . $year . ' ' . $d->format('H:i');
    }

    /** Clé du jour local (« 2026-10-18 ») : sépare le Flux en journées. */
    public static function dayKey(int $ts, \DateTimeZone $tz): string
    {
        return self::at($ts, $tz)->format('Y-m-d');
    }

    /** « Aujourd'hui », « Hier », « lundi 12 octobre » / « Monday, October 12 » (année si autre). */
    public static function dayLabel(int $ts, \DateTimeZone $tz, int $now): string
    {
        $d = self::at($ts, $tz);
        $today = self::at($now, $tz);
        if ($d->format('Y-m-d') === $today->format('Y-m-d')) {
            return t("Aujourd'hui");
        }
        if ($d->format('Y-m-d') === $today->modify('-1 day')->format('Y-m-d')) {
            return t('Hier');
        }
        $otherYear = $d->format('Y') !== $today->format('Y');
        if (self::en()) {
            $label = self::DAYS_EN[(int)$d->format('N') - 1] . ', ' . self::MONTHS_LONG_EN[(int)$d->format('n') - 1] . ' ' . (int)$d->format('j');
            return $otherYear ? $label . ', ' . $d->format('Y') : $label;
        }
        $label = self::DAYS[(int)$d->format('N') - 1] . ' ' . (int)$d->format('j') . ' ' . self::MONTHS_LONG[(int)$d->format('n') - 1];
        return $otherYear ? $label . ' ' . $d->format('Y') : $label;
    }

    /** « à l'instant », « il y a 5 min », « il y a 3 h », « il y a 2 j ». */
    public static function relative(int $ts, int $now): string
    {
        $sec = $now - $ts;
        if ($sec < 45) {
            return t("à l'instant");
        }
        if ($sec < 3600) {
            return t('il y a {n} min', ['n' => max(1, (int)round($sec / 60))]);
        }
        if ($sec < 86400) {
            return t('il y a {n} h', ['n' => (int)round($sec / 3600)]);
        }
        return t('il y a {n} j', ['n' => (int)round($sec / 86400)]);
    }

    /** Durée humaine d'une fenêtre (« 24 h », « 3 jours »). */
    public static function span(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(1, (int)round($seconds / 60)) . "\u{00A0}min";
        }
        if ($seconds < 172800) {
            return (int)round($seconds / 3600) . "\u{00A0}h";
        }
        return t('{n} jours', ['n' => (int)round($seconds / 86400)]);
    }

    /**
     * « 3 erreurs », « 1 avertissement » : le compte d'une gravité dans une
     * phrase (bulle de la frise). Le pluriel diffère entre FR et EN.
     */
    public static function levelCount(string $level, int $n): string
    {
        $vars = ['n' => self::number($n)];
        return match ($level) {
            'critical' => I18n::plural($n, '{n} critique', '{n} critiques', $vars),
            'error'    => I18n::plural($n, '{n} erreur', '{n} erreurs', $vars),
            'warning'  => I18n::plural($n, '{n} avertissement', '{n} avertissements', $vars),
            'info'     => I18n::plural($n, '{n} info', '{n} infos', $vars),
            default    => self::number($n) . ' ' . $level,
        };
    }

    /** Étiquette d'une tranche de la frise. */
    public static function bucketLabel(int $from, int $step, \DateTimeZone $tz): string
    {
        $d = self::at($from, $tz);
        if ($step >= 86400) {
            return self::dayMonth($d);
        }
        return $d->format('H:i');
    }

    /** Détail d'une tranche (bulle) : « 18 oct. 14:00 → 14:30 ». */
    public static function bucketRange(int $from, int $to, int $step, \DateTimeZone $tz): string
    {
        $a = self::at($from, $tz);
        $b = self::at($to, $tz);
        $day = self::dayMonth($a);
        if ($step >= 86400) {
            return $step > 86400 ? $day . ' → ' . self::dayMonth($b) : $day;
        }
        return $day . ' ' . $a->format('H:i') . ' → ' . $b->format('H:i');
    }

    /**
     * Pile d'appels lisible : une ligne par cadre. `app` = code de MIA
     * (src/, public/, scripts/…), hors bibliothèques (vendor/).
     *
     * @return list<array{n:string,call:string,file:string,line:string,app:bool,raw:string}>
     */
    public static function frames(string $trace): array
    {
        $frames = [];
        foreach (explode("\n", $trace) as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with($line, 'Stack trace:')) {
                continue;
            }
            if (preg_match('/^#(\d+)\s+(.*)$/', $line, $m) !== 1) {
                $frames[] = ['n' => '', 'call' => trim($line), 'file' => '', 'line' => '', 'app' => false, 'raw' => $line];
                continue;
            }
            $rest = $m[2];
            $file = '';
            $ln   = '';
            $call = $rest;
            if (preg_match('/^(\S+?)\((\d+)\):\s*(.*)$/', $rest, $f) === 1) {
                $file = LogParser::shortPath($f[1]);
                $ln   = $f[2];
                $call = $f[3];
            } elseif (preg_match('/^\[internal function\]:\s*(.*)$/', $rest, $f) === 1) {
                $file = 'interne';
                $call = $f[1];
            }
            $app = $file !== '' && $file !== 'interne' && !str_starts_with($file, 'vendor/') && !str_starts_with($file, '/');
            $frames[] = ['n' => $m[1], 'call' => $call, 'file' => $file, 'line' => $ln, 'app' => $app, 'raw' => $line];
        }
        return $frames;
    }

    /**
     * Mini-courbe d'un problème : une barre par tranche de la frise. SVG
     * autonome, teinté par `currentColor`.
     *
     * @param list<int> $counts
     */
    public static function sparkline(array $counts, int $width = 120, int $height = 26): string
    {
        $n = count($counts);
        if ($n === 0) {
            return '';
        }
        $max = max(1, max($counts));
        $bar = $width / $n;
        $gap = $bar > 3 ? 1.0 : 0.0;
        $rects = '';
        foreach ($counts as $i => $c) {
            if ($c <= 0) {
                continue;
            }
            $h = max(2.0, round(($c / $max) * ($height - 2), 1));
            $x = round($i * $bar, 2);
            $rects .= '<rect x="' . $x . '" y="' . round($height - $h, 1) . '" width="' . round(max(1.0, $bar - $gap), 2) . '" height="' . $h . '" rx="1"/>';
        }
        return '<svg class="lgx-spark" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<line x1="0" y1="' . ($height - 0.5) . '" x2="' . $width . '" y2="' . ($height - 0.5) . '" class="lgx-spark-base"/>'
            . $rects . '</svg>';
    }

    /** Premier paragraphe d'un message, borné (titre d'un problème, ligne du Flux). */
    public static function headline(string $message, int $max = 220): string
    {
        $line = trim(strtok($message, "\n") ?: $message);
        return mb_strlen($line) > $max ? rtrim(mb_substr($line, 0, $max - 1)) . '…' : $line;
    }
}
