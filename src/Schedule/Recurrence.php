<?php

declare(strict_types=1);

/**
 * Récurrence d'une tâche programmée — SOURCE UNIQUE, entièrement pure.
 *
 * Une règle est un petit tableau qui reflète UN POUR UN ce que l'écran
 * propose, et jamais une expression cron :
 *
 *   ['freq' => 'daily',    'time' => '08:00']                 tous les jours
 *   ['freq' => 'weekdays', 'time' => '08:00']                 du lundi au vendredi
 *   ['freq' => 'weekly',   'days' => [1, 4], 'time' => …]     jours ISO, 1 = lundi
 *   ['freq' => 'monthly',  'day' => 15 | 'last', 'time' => …] jour du mois
 *
 * Le jour du mois s'arrête à 28, plus « dernier jour » : « le 31 » dans un
 * mois de 30 jours n'a pas de réponse évidente, et l'écran n'a pas à la poser.
 *
 * Tout calcul se fait dans le FUSEAU DE LA PERSONNE (celui de son navigateur,
 * enregistré avec la tâche) puis rendu en UTC : « chaque lundi à 8 h » reste à
 * 8 h, heure de Paris, des deux côtés du changement d'heure. Deux cas limites,
 * réglés par PHP de façon déterministe et figés par les tests :
 *   - heure inexistante (passage à l'heure d'été, 02:30 le dernier dimanche de
 *     mars à Paris) : l'exécution a lieu une heure plus tard, à 03:30 ;
 *   - heure ambiguë (retour à l'heure d'hiver, 02:30 existe deux fois) : une
 *     seule exécution, à la seconde occurrence.
 *
 * Le planificateur, l'aperçu de l'écran (POST /settings/schedules/preview) et
 * la liste des tâches passent tous par ici : la phrase « Chaque lundi à
 * 8 h 00 » et les prochaines dates affichées sont celles qui serviront.
 */
final class Recurrence
{
    public const DAILY    = 'daily';
    public const WEEKDAYS = 'weekdays';
    public const WEEKLY   = 'weekly';
    public const MONTHLY  = 'monthly';

    public const FREQS = [self::DAILY, self::WEEKDAYS, self::WEEKLY, self::MONTHLY];

    /** Jour du mois : 1 à 28, ou le dernier jour du mois. */
    public const LAST_DAY      = 'last';
    public const MONTH_DAY_MAX = 28;

    /**
     * Fenêtre de recherche de la prochaine occurrence. Large marge : l'écart
     * maximal entre deux occurrences valides est de 31 jours (« le dernier
     * jour du mois »).
     */
    private const SEARCH_DAYS = 400;

    /** Noms des jours, clés de traduction (1 = lundi, ISO 8601). */
    private const DAY_NAMES = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
        5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    // ------------------------------------------------------------------
    // Forme
    // ------------------------------------------------------------------

    /**
     * Valide et met en forme une règle reçue de l'écran (ou relue en base).
     * Champs attendus : `freq`, `time`, et selon la fréquence `days` (liste
     * ou « 1,4 ») ou `day` (1-28 ou « last »). Les champs sans objet pour la
     * fréquence choisie sont IGNORÉS — l'écran poste tout le formulaire.
     *
     * @throws \InvalidArgumentException message affichable, déjà traduit
     */
    public static function normalize(array $input): array
    {
        $freq = (string)($input['freq'] ?? '');
        if (!in_array($freq, self::FREQS, true)) {
            throw new \InvalidArgumentException(t('Choisissez une fréquence.'));
        }

        $time = self::normalizeTime((string)($input['time'] ?? ''));
        if ($time === null) {
            throw new \InvalidArgumentException(t('Indiquez une heure valide, par exemple 08:00.'));
        }

        return match ($freq) {
            self::WEEKLY  => ['freq' => $freq, 'days' => self::normalizeDays($input['days'] ?? []), 'time' => $time],
            self::MONTHLY => ['freq' => $freq, 'day' => self::normalizeMonthDay($input['day'] ?? ''), 'time' => $time],
            default       => ['freq' => $freq, 'time' => $time],
        };
    }

    /** Règle stockée → règle valide, ou null si le JSON est illisible ou invalide. */
    public static function fromJson(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        try {
            return self::normalize($decoded);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** Forme canonique stockée (clés dans un ordre fixe, liste de jours triée). */
    public static function toJson(array $rule): string
    {
        return (string)json_encode(self::normalize($rule), JSON_UNESCAPED_UNICODE);
    }

    /** « 8:5 », « 08:05 » → « 08:05 » ; null si ce n'est pas une heure. */
    private static function normalizeTime(string $time): ?string
    {
        if (preg_match('/^\s*(\d{1,2}):(\d{1,2})\s*$/', $time, $m) !== 1) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $i);
    }

    /** @return list<int> jours ISO uniques et triés, au moins un */
    private static function normalizeDays(mixed $days): array
    {
        if (is_string($days)) {
            $days = $days === '' ? [] : explode(',', $days);
        }
        $clean = [];
        foreach ((array)$days as $day) {
            $n = filter_var($day, FILTER_VALIDATE_INT);
            if ($n !== false && $n >= 1 && $n <= 7) {
                $clean[$n] = $n;
            }
        }
        if ($clean === []) {
            throw new \InvalidArgumentException(t('Choisissez au moins un jour.'));
        }
        ksort($clean);
        return array_values($clean);
    }

    private static function normalizeMonthDay(mixed $day): int|string
    {
        if ($day === self::LAST_DAY) {
            return self::LAST_DAY;
        }
        $n = filter_var($day, FILTER_VALIDATE_INT);
        if ($n === false || $n < 1 || $n > self::MONTH_DAY_MAX) {
            throw new \InvalidArgumentException(t('Choisissez un jour du mois entre le 1er et le 28, ou le dernier jour.'));
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Calcul
    // ------------------------------------------------------------------

    /** Fuseau IANA valide, sinon UTC (celui du serveur). Ne lève jamais. */
    public static function zone(string $timezone): \DateTimeZone
    {
        if ($timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return new \DateTimeZone($timezone);
        }
        return new \DateTimeZone('UTC');
    }

    /** La règle tombe-t-elle ce jour-là ? (date dont seuls le jour et le mois comptent) */
    public static function matchesDate(array $rule, \DateTimeInterface $date): bool
    {
        $weekday = (int)$date->format('N');

        return match ((string)$rule['freq']) {
            self::DAILY    => true,
            self::WEEKDAYS => $weekday <= 5,
            self::WEEKLY   => in_array($weekday, (array)$rule['days'], true),
            self::MONTHLY  => $rule['day'] === self::LAST_DAY
                ? $date->format('j') === $date->format('t')
                : (int)$date->format('j') === (int)$rule['day'],
            default        => false,
        };
    }

    /**
     * Première occurrence STRICTEMENT postérieure à `$after`, rendue en UTC.
     *
     * Les jours sont parcourus sur un calendrier UTC (aucun changement d'heure
     * ne peut y sauter ou doubler une date), puis chaque date candidate est
     * posée à l'heure voulue dans le fuseau de la personne.
     */
    public static function next(array $rule, string $timezone, \DateTimeImmutable $after): \DateTimeImmutable
    {
        $zone = self::zone($timezone);
        $utc  = new \DateTimeZone('UTC');
        $time = (string)$rule['time'];

        $cursor = new \DateTimeImmutable($after->setTimezone($zone)->format('Y-m-d') . ' 00:00:00', $utc);
        for ($i = 0; $i <= self::SEARCH_DAYS; $i++) {
            $date = $cursor->modify('+' . $i . ' days');
            if (!self::matchesDate($rule, $date)) {
                continue;
            }
            $candidate = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $time . ':00', $zone);
            if ($candidate > $after) {
                return $candidate->setTimezone($utc);
            }
        }

        // Inatteignable pour une règle normalisée : chaque fréquence tombe au
        // moins une fois par mois.
        throw new \LogicException('Aucune occurrence trouvée pour la règle ' . json_encode($rule));
    }

    /**
     * Les prochaines occurrences (UTC), dans l'ordre.
     *
     * @return list<\DateTimeImmutable>
     */
    public static function upcoming(array $rule, string $timezone, \DateTimeImmutable $after, int $count = 3): array
    {
        $out = [];
        $cursor = $after;
        for ($i = 0; $i < $count; $i++) {
            $cursor = self::next($rule, $timezone, $cursor);
            $out[] = $cursor;
        }
        return $out;
    }

    /**
     * Ordre de grandeur des exécutions par mois, affiché sous la règle :
     * c'est ce qui coûte, et l'utilisateur doit le voir en la choisissant.
     */
    public static function perMonth(array $rule): int
    {
        return match ((string)$rule['freq']) {
            self::DAILY    => 30,
            self::WEEKDAYS => 22,
            self::WEEKLY   => (int)round(count((array)$rule['days']) * 52 / 12),
            default        => 1,
        };
    }

    // ------------------------------------------------------------------
    // Phrase
    // ------------------------------------------------------------------

    /** Nom du jour, traduit (1 = lundi). Clés calculées : RecurrenceTest vérifie le dictionnaire. */
    public static function dayName(int $isoDay): string
    {
        return isset(self::DAY_NAMES[$isoDay]) ? t(self::DAY_NAMES[$isoDay]) : '';
    }

    /**
     * La règle en une phrase : « Chaque lundi et jeudi à 8 h 00 ». C'est elle
     * que l'écran met sous les choix, et que la liste des tâches affiche.
     */
    public static function describe(array $rule): string
    {
        $heure = Format::time((string)$rule['time']);
        $freq  = (string)$rule['freq'];

        if ($freq === self::MONTHLY) {
            if ($rule['day'] === self::LAST_DAY) {
                return t('Le dernier jour de chaque mois à {heure}', ['heure' => $heure]);
            }
            if ((int)$rule['day'] === 1) {
                return t('Le 1er de chaque mois à {heure}', ['heure' => $heure]);
            }
            return t('Le {n} de chaque mois à {heure}', ['n' => (int)$rule['day'], 'heure' => $heure]);
        }

        $days = match ($freq) {
            self::DAILY    => [1, 2, 3, 4, 5, 6, 7],
            self::WEEKDAYS => [1, 2, 3, 4, 5],
            default        => (array)$rule['days'],
        };
        if ($days === [1, 2, 3, 4, 5, 6, 7]) {
            return t('Tous les jours à {heure}', ['heure' => $heure]);
        }
        if ($days === [1, 2, 3, 4, 5]) {
            return t('Du lundi au vendredi à {heure}', ['heure' => $heure]);
        }

        $names = array_map([self::class, 'dayName'], $days);
        $last  = array_pop($names);
        $jours = $names === []
            ? $last
            : t('{liste} et {dernier}', ['liste' => implode(', ', $names), 'dernier' => $last]);

        return t('Chaque {jours} à {heure}', ['jours' => $jours, 'heure' => $heure]);
    }
}
