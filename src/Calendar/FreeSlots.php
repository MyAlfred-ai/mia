<?php

declare(strict_types=1);

/**
 * Créneaux libres d'un agenda — SOURCE UNIQUE du calcul, partagée par Google
 * Agenda (`freeBusy`) et le calendrier Outlook (`calendarView` + `getSchedule`).
 *
 * LE PROBLÈME RÉSOLU. « Trouve-moi une heure libre mardi ou mercredi avec
 * Paul » demandait au modèle de lister les évènements puis de calculer les
 * trous lui-même : plusieurs allers-retours, des chevauchements oubliés, et
 * des heures fausses dès qu'un évènement journée entière ou un rendez-vous de
 * 7 h 45 se glissait dans la liste. Ici les occupations arrivent du
 * fournisseur, et le calcul est fait UNE fois, en PHP, de façon testable.
 *
 * Règles :
 *   - tout se calcule dans le FUSEAU DE L'UTILISATEUR (celui du navigateur,
 *     transmis par le tour de chat) : le serveur tourne en UTC, et une plage
 *     « 9 h – 18 h » lue en UTC tomberait à 11 h – 20 h à Paris ;
 *   - un créneau n'est rendu que s'il tient la durée demandée ENTIÈRE, dans la
 *     plage horaire du jour, et commence sur un quart d'heure ;
 *   - jamais dans le passé : la fenêtre commence au plus tôt maintenant
 *     (arrondi au quart d'heure suivant) ;
 *   - les jours ouvrés (lundi à vendredi) par défaut.
 *
 * Pure (aucun réseau, aucune base) : `request`, `compute`, `format` se testent
 * avec des dates fixes.
 */
final class FreeSlots
{
    public const DEFAULT_DURATION   = 60;
    public const MIN_DURATION       = 15;
    public const MAX_DURATION       = 8 * 60;
    public const DEFAULT_RANGE_DAYS = 7;
    public const MAX_RANGE_DAYS     = 31;
    public const STEP               = 15;
    public const DEFAULT_RESULTS    = 10;
    public const MAX_RESULTS        = 20;
    public const MAX_PARTICIPANTS   = 10;

    private const DAYS_FR = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
        5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    /**
     * Arguments de l'outil → demande normalisée. Throw RuntimeException à
     * message FR sur toute entrée invalide (le handler la rend au modèle).
     *
     * @return array{tz:\DateTimeZone, from:\DateTimeImmutable, to:\DateTimeImmutable, duree:int, jour_debut:int, jour_fin:int, jours_ouvres:bool, max:int, participants:list<string>}
     */
    public static function request(array $args, string $timeZone, \DateTimeImmutable $now): array
    {
        $tz    = self::zone($timeZone);
        $today = $now->setTimezone($tz)->setTime(0, 0);

        $from = self::parseDay((string)($args['debut'] ?? ''), $tz) ?? $today;
        $fin  = self::parseDay((string)($args['fin'] ?? ''), $tz);
        // `fin` est un jour INCLUS : la fenêtre s'arrête au lendemain minuit.
        $to   = $fin !== null ? $fin->modify('+1 day') : $from->modify('+' . self::DEFAULT_RANGE_DAYS . ' days');
        if ($to <= $from) {
            throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
        }
        if ($to <= $now) {
            throw new \RuntimeException('Cette période est passée : choisis des dates à venir.');
        }
        if (($to->getTimestamp() - $from->getTimestamp()) > (self::MAX_RANGE_DAYS + 1) * 86400) {
            throw new \RuntimeException('Fenêtre trop large : ' . self::MAX_RANGE_DAYS . ' jours au plus.');
        }

        $jourDebut = self::parseHour((string)($args['heure_debut'] ?? ''), 9 * 60);
        $jourFin   = self::parseHour((string)($args['heure_fin'] ?? ''), 18 * 60);
        if ($jourFin <= $jourDebut) {
            throw new \RuntimeException('L\'heure de fin de journée doit être postérieure à l\'heure de début.');
        }

        $duree = isset($args['duree_minutes']) && is_numeric($args['duree_minutes'])
            ? (int)$args['duree_minutes'] : self::DEFAULT_DURATION;
        $duree = max(self::MIN_DURATION, min(self::MAX_DURATION, $duree));
        if ($duree > $jourFin - $jourDebut) {
            throw new \RuntimeException('La durée demandée dépasse la plage horaire de la journée.');
        }

        $ouvres = !array_key_exists('jours_ouvres', $args)
            || filter_var($args['jours_ouvres'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

        $max = isset($args['max_resultats']) && is_numeric($args['max_resultats'])
            ? (int)$args['max_resultats'] : self::DEFAULT_RESULTS;

        return [
            'tz'           => $tz,
            'from'         => $from,
            'to'           => $to,
            'duree'        => $duree,
            'jour_debut'   => $jourDebut,
            'jour_fin'     => $jourFin,
            'jours_ouvres' => $ouvres,
            'max'          => max(1, min(self::MAX_RESULTS, $max)),
            'participants' => self::participants((string)($args['participants'] ?? '')),
        ];
    }

    /**
     * Exécution complète de l'outil, partagée par les deux agendas : demande
     * normalisée → occupations du fournisseur → calcul → forme rendue.
     *
     * `$calendar` expose `busyIntervals(from, to, participants)` (UserCalendarService
     * ou UserOutlookCalendarService) : même forme de retour, `busy` / `visibles`
     * / `non_visibles`.
     *
     * @throws \RuntimeException message FR (entrée invalide, erreur du fournisseur)
     */
    public static function find(object $calendar, array $args, string $timeZone, \DateTimeImmutable $now): array
    {
        $req  = self::request($args, $timeZone, $now);
        $data = $calendar->busyIntervals($req['from'], $req['to'], $req['participants']);
        $free = self::compute((array)($data['busy'] ?? []), $req, $now);

        $out = [
            'fuseau'        => $req['tz']->getName(),
            'fenetre'       => self::windowLabel($req),
            'duree_minutes' => $req['duree'],
            'creneaux'      => self::format($free, $req['tz']),
        ];
        if ($req['participants'] !== []) {
            $out['participants_pris_en_compte'] = array_values((array)($data['visibles'] ?? []));
            $absents = (array)($data['non_visibles'] ?? []);
            if ($absents !== []) {
                $out['participants_non_visibles'] = array_map(
                    static fn(string $qui, string $pourquoi): string => $qui . ' : ' . $pourquoi,
                    array_map('strval', array_keys($absents)),
                    array_map('strval', array_values($absents))
                );
                $out['note'] = 'Les disponibilités des participants non visibles ne sont PAS prises en compte : '
                    . 'dis-le à l\'utilisateur, ne présente pas ces créneaux comme communs.';
            }
        }
        return $out;
    }

    /**
     * Définition function-calling de l'outil, commune aux deux agendas — seuls
     * le nom et l'agenda nommé changent. Les créneaux rendus portent `debut`
     * au format de la création d'évènement.
     */
    public static function toolDefinition(string $name, string $agenda, string $participantsScope): array
    {
        return [
            'name'        => $name,
            'description' => "Trouve des créneaux LIBRES dans " . $agenda . " de l'utilisateur sur une période, "
                . "en tenant compte, si tu les nommes, des disponibilités de participants (" . $participantsScope . "). "
                . "Rend des plages libres d'au moins la durée demandée, dans les heures de travail, au quart "
                . "d'heure, dans le fuseau de l'utilisateur. Le calcul est fait pour toi : ne le refais pas à "
                . "partir d'une liste d'évènements. Chaque créneau donne `debut` au format attendu pour créer "
                . "l'évènement.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'debut'         => ['type' => 'string', 'description' => "Premier jour de la recherche, AAAA-MM-JJ (défaut : aujourd'hui)."],
                    'fin'           => ['type' => 'string', 'description' => "Dernier jour inclus, AAAA-MM-JJ (défaut : 7 jours ; " . self::MAX_RANGE_DAYS . " jours au plus)."],
                    'duree_minutes' => ['type' => 'integer', 'description' => "Durée du créneau en minutes (défaut : 60)."],
                    'participants'  => ['type' => 'string', 'description' => "Adresses email des personnes dont il faut aussi respecter les disponibilités, séparées par des virgules (facultatif)."],
                    'heure_debut'   => ['type' => 'string', 'description' => "Début de la journée de travail, HH:MM (défaut : 09:00)."],
                    'heure_fin'     => ['type' => 'string', 'description' => "Fin de la journée de travail, HH:MM (défaut : 18:00)."],
                    'jours_ouvres'  => ['type' => 'boolean', 'description' => "Lundi au vendredi seulement (défaut : true). false pour inclure le week-end."],
                    'max_resultats' => ['type' => 'integer', 'description' => "Nombre maximum de créneaux (1 à " . self::MAX_RESULTS . ", défaut : " . self::DEFAULT_RESULTS . ")."],
                ],
            ],
        ];
    }

    /**
     * Fenêtres libres d'au moins `duree` minutes, dans l'ordre chronologique.
     *
     * @param list<array{0:\DateTimeImmutable, 1:\DateTimeImmutable}> $busy occupations (tous fuseaux)
     * @return list<array{0:\DateTimeImmutable, 1:\DateTimeImmutable}>
     */
    public static function compute(array $busy, array $req, \DateTimeImmutable $now): array
    {
        $tz       = $req['tz'];
        $effStart = self::ceilToStep($now->setTimezone($tz));
        if ($effStart < $req['from']) {
            $effStart = $req['from'];
        }
        $intervals = self::merge($busy);
        $out = [];

        for ($day = $effStart->setTime(0, 0); $day < $req['to'] && count($out) < $req['max']; $day = $day->modify('+1 day')) {
            if ($req['jours_ouvres'] && (int)$day->format('N') > 5) {
                continue;
            }
            $start = self::atMinutes($day, $req['jour_debut']);
            $end   = self::atMinutes($day, $req['jour_fin']);
            if ($start < $effStart) {
                $start = $effStart;
            }
            if ($end > $req['to']) {
                $end = $req['to'];
            }
            if ($start >= $end) {
                continue;
            }
            $cursor = $start;
            foreach ($intervals as [$bs, $be]) {
                if ($be <= $cursor) {
                    continue;
                }
                if ($bs >= $end) {
                    break;
                }
                if ($bs > $cursor) {
                    self::push($out, $cursor, $bs < $end ? $bs : $end, $req['duree']);
                }
                if ($be > $cursor) {
                    $cursor = $be;
                }
                if ($cursor >= $end) {
                    break;
                }
            }
            if ($cursor < $end) {
                self::push($out, $cursor, $end, $req['duree']);
            }
        }
        return array_slice($out, 0, $req['max']);
    }

    /**
     * Forme rendue au modèle. `debut` est au format qu'attendent les outils de
     * création d'évènement (AAAA-MM-JJ HH:MM, dans le fuseau de l'utilisateur) :
     * « trouve un créneau puis bloque-le » s'enchaîne sans conversion.
     *
     * @return list<array{jour:string, de:string, a:string, duree:string, debut:string}>
     */
    public static function format(array $free, \DateTimeZone $tz): array
    {
        $out = [];
        foreach ($free as [$s, $e]) {
            $s = $s->setTimezone($tz);
            $e = $e->setTimezone($tz);
            $out[] = [
                'jour'  => self::DAYS_FR[(int)$s->format('N')] . ' ' . $s->format('d/m/Y'),
                'de'    => $s->format('H:i'),
                // Fin à minuit : « 24:00 » se lit mieux que « 00:00 » le lendemain.
                'a'     => $e->format('H:i') === '00:00' && $e > $s ? '24:00' : $e->format('H:i'),
                'duree' => self::humanDuration(intdiv($e->getTimestamp() - $s->getTimestamp(), 60)),
                'debut' => $s->format('Y-m-d H:i'),
            ];
        }
        return $out;
    }

    /** Fenêtre lisible : « du lundi 05/10/2026 au vendredi 09/10/2026 ». */
    public static function windowLabel(array $req): string
    {
        $last = $req['to']->modify('-1 day');
        return 'du ' . self::DAYS_FR[(int)$req['from']->format('N')] . ' ' . $req['from']->format('d/m/Y')
            . ' au ' . self::DAYS_FR[(int)$last->format('N')] . ' ' . $last->format('d/m/Y')
            . ', de ' . self::hourLabel($req['jour_debut']) . ' à ' . self::hourLabel($req['jour_fin'])
            . ($req['jours_ouvres'] ? ', jours ouvrés' : '');
    }

    /** « 1 h 30 », « 45 min », « 2 h ». */
    public static function humanDuration(int $minutes): string
    {
        $h = intdiv(max(0, $minutes), 60);
        $m = max(0, $minutes) % 60;
        if ($h === 0) {
            return $m . ' min';
        }
        return $h . ' h' . ($m > 0 ? ' ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) : '');
    }

    /**
     * Occupations triées et fusionnées (chevauchements et contiguïtés). Une
     * occupation à l'envers ou vide est ignorée plutôt que de fausser le calcul.
     *
     * @param list<array{0:\DateTimeImmutable, 1:\DateTimeImmutable}> $busy
     * @return list<array{0:\DateTimeImmutable, 1:\DateTimeImmutable}>
     */
    public static function merge(array $busy): array
    {
        $clean = [];
        foreach ($busy as $b) {
            if (is_array($b) && ($b[0] ?? null) instanceof \DateTimeImmutable
                && ($b[1] ?? null) instanceof \DateTimeImmutable && $b[1] > $b[0]) {
                $clean[] = [$b[0], $b[1]];
            }
        }
        usort($clean, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($clean as $b) {
            $last = count($out) - 1;
            if ($last >= 0 && $b[0] <= $out[$last][1]) {
                if ($b[1] > $out[$last][1]) {
                    $out[$last][1] = $b[1];
                }
                continue;
            }
            $out[] = $b;
        }
        return $out;
    }

    /** Fuseau valide, sinon celui du serveur. */
    public static function zone(string $timeZone): \DateTimeZone
    {
        $timeZone = trim($timeZone);
        if ($timeZone !== '') {
            try {
                return new \DateTimeZone($timeZone);
            } catch (\Throwable) {
                // Fuseau inconnu : celui du serveur, nommé dans le résultat.
            }
        }
        return new \DateTimeZone(date_default_timezone_get());
    }

    /**
     * Adresses des participants : séparées par virgules ou points-virgules,
     * validées, dédoublonnées. Throw sur la première adresse invalide.
     *
     * @return list<string>
     */
    public static function participants(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;\s]+/', trim($raw)) ?: [] as $addr) {
            $addr = trim($addr, " \t<>\"'");
            if ($addr === '') {
                continue;
            }
            if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Adresse de participant invalide : « ' . $addr . ' »');
            }
            $out[strtolower($addr)] = $addr;
        }
        if (count($out) > self::MAX_PARTICIPANTS) {
            throw new \RuntimeException('Trop de participants : ' . self::MAX_PARTICIPANTS . ' au plus.');
        }
        return array_values($out);
    }

    // ===================== Interne =====================

    private static function push(array &$out, \DateTimeImmutable $from, \DateTimeImmutable $to, int $duree): void
    {
        $start = self::ceilToStep($from);
        if ($to->getTimestamp() - $start->getTimestamp() >= $duree * 60) {
            $out[] = [$start, $to];
        }
    }

    /** Arrondi au quart d'heure SUIVANT (inchangé s'il y est déjà, à la seconde près). */
    private static function ceilToStep(\DateTimeImmutable $dt): \DateTimeImmutable
    {
        $minutes = (int)$dt->format('G') * 60 + (int)$dt->format('i');
        $seconds = (int)$dt->format('s');
        if ($minutes % self::STEP === 0 && $seconds === 0) {
            return $dt->setTime(intdiv($minutes, 60), $minutes % 60);
        }
        $next = (intdiv($minutes, self::STEP) + 1) * self::STEP;
        return self::atMinutes($dt->setTime(0, 0), $next);
    }

    /** Minuit du jour + N minutes (24 h = minuit du lendemain), heure locale. */
    private static function atMinutes(\DateTimeImmutable $midnight, int $minutes): \DateTimeImmutable
    {
        if ($minutes >= 24 * 60) {
            return $midnight->setTime(0, 0)->modify('+1 day');
        }
        return $midnight->setTime(intdiv($minutes, 60), $minutes % 60);
    }

    /** « AAAA-MM-JJ » → minuit dans le fuseau, null si vide. */
    private static function parseDay(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) === 1) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $tz);
            if ($d !== false && $d->format('Y-m-d') === $m[1]) {
                return $d;
            }
        }
        throw new \RuntimeException('Date invalide : « ' . $raw . ' » (format attendu : AAAA-MM-JJ).');
    }

    /** « 9:00 », « 09h30 », « 14 » → minutes depuis minuit ; vide → défaut. */
    private static function parseHour(string $raw, int $default): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $default;
        }
        if (preg_match('/^(\d{1,2})(?:\s*[:hH]\s*(\d{2})?)?$/', $raw, $m) === 1) {
            $h  = (int)$m[1];
            $mn = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
            if (($h < 24 && $mn < 60) || ($h === 24 && $mn === 0)) {
                return $h * 60 + $mn;
            }
        }
        throw new \RuntimeException('Heure invalide : « ' . $raw . ' » (format attendu : HH:MM).');
    }

    private static function hourLabel(int $minutes): string
    {
        return intdiv($minutes, 60) . ' h' . ($minutes % 60 > 0 ? ' ' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT) : '');
    }
}
