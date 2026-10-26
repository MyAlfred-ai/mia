<?php

declare(strict_types=1);

/**
 * Période d'analyse des coûts et de la consommation — SOURCE UNIQUE, pure.
 *
 * Le problème résolu : la page Consommation avait ses tranches prédéfinies et
 * sa plage personnalisée (lues dans `AdminController::resolveUsageFilters`),
 * la page Budget n'en avait aucune — elle ne savait montrer que le mois en
 * cours. Deux écrans de la même rubrique « Coûts » auraient fini par proposer
 * des tranches différentes, aux noms différents, calculées différemment.
 * Ici : le catalogue des tranches, leur résolution en dates, la période de
 * comparaison et les libellés ; le sélecteur partagé
 * (`templates/partials/period-picker.php`) les rend sur les deux pages.
 *
 * Paramètres d'URL : `preset` (une clé de PRESETS), sinon `from` / `to`
 * ('Y-m-d', bornes INCLUSES) pour une plage personnalisée. Jours au fuseau du
 * process (UTC dans l'image), comme le grand livre et la fenêtre du budget.
 *
 * Rien ici ne lit la base : « Tout » n'a pas de borne (`from`/`to` à null) et
 * c'est l'appelant qui la pose s'il en a besoin (`bounded()`).
 */
final class UsagePeriod
{
    public const CUSTOM = 'custom';

    /**
     * Tranches prédéfinies, dans l'ordre d'affichage : le calendrier d'abord
     * (ce que l'on compare à une facture), les fenêtres glissantes ensuite.
     * `short` = libellé du sélecteur, `label` = libellé de la phrase de période.
     * Textes français = clés de traduction : on les lit par `shortLabel()` et
     * `label()`, qui les traduisent (une constante ne peut pas appeler t()).
     */
    public const PRESETS = [
        'current_month'  => ['short' => 'Mois en cours',  'label' => 'Mois en cours'],
        'last_month'     => ['short' => 'Mois dernier',   'label' => 'Mois dernier'],
        'last_7_days'    => ['short' => '7 jours',        'label' => '7 derniers jours'],
        'last_30_days'   => ['short' => '30 jours',       'label' => '30 derniers jours'],
        'last_12_months' => ['short' => '12 mois',        'label' => '12 derniers mois'],
        'year_to_date'   => ['short' => 'Cette année',    'label' => 'Depuis le 1er janvier'],
        'all'            => ['short' => 'Tout',           'label' => 'Depuis le début'],
    ];

    /** Plus tôt qu'une saisie raisonnable : une date de 0001 ne doit rien casser. */
    public const MIN_DAY = '2000-01-01';

    /** Vrai si la clé est une tranche prédéfinie. */
    public static function isPreset(string $key): bool
    {
        return isset(self::PRESETS[$key]);
    }

    /** 'Y-m-d' valide, sinon ''. */
    public static function validDay(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) !== 1) {
            return '';
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $raw : '';
    }

    /**
     * Période demandée par la requête. Sans tranche ni date : `$default`.
     * Plage personnalisée : une seule borne vaut les deux, bornes inversées
     * remises dans l'ordre, rien après aujourd'hui (aucune dépense n'y vit, et
     * une moyenne par jour qui compterait des jours à venir serait fausse).
     *
     * @param array<string,mixed> $query typiquement $_GET
     * @return array{preset:string, custom:bool, from:?string, to:?string, fromInput:string, toInput:string}
     */
    public static function resolve(array $query, string $default, DateTimeImmutable $today): array
    {
        $day       = $today->format('Y-m-d');
        $fromInput = self::validDay((string)($query['from'] ?? ''));
        $toInput   = self::validDay((string)($query['to'] ?? ''));
        $preset    = trim((string)($query['preset'] ?? ''));
        if (!self::isPreset($preset)) {
            $preset = '';
        }
        if ($preset === '' && $fromInput === '' && $toInput === '') {
            $preset = self::isPreset($default) ? $default : 'current_month';
        }

        if ($preset !== '') {
            [$from, $to] = self::presetRange($preset, $today);
            return [
                'preset'    => $preset,
                'custom'    => false,
                'from'      => $from,
                'to'        => $to,
                'fromInput' => $from ?? '',
                'toInput'   => $to ?? '',
            ];
        }

        if ($fromInput === '') {
            $fromInput = $toInput;
        } elseif ($toInput === '') {
            $toInput = $fromInput;
        }
        if ($fromInput > $toInput) {
            [$fromInput, $toInput] = [$toInput, $fromInput];
        }
        $toInput   = min($toInput, $day);
        $fromInput = max(self::MIN_DAY, min($fromInput, $toInput));

        return [
            'preset'    => '',
            'custom'    => true,
            'from'      => $fromInput,
            'to'        => $toInput,
            'fromInput' => $fromInput,
            'toInput'   => $toInput,
        ];
    }

    /**
     * Bornes d'une tranche prédéfinie ('Y-m-d' incluses ; « Tout » : null).
     *
     * @return array{0:?string, 1:?string}
     */
    public static function presetRange(string $preset, DateTimeImmutable $today): array
    {
        $day   = $today->setTime(0, 0, 0);
        $first = $day->setDate((int)$day->format('Y'), (int)$day->format('n'), 1);
        return match ($preset) {
            'current_month'  => [$first->format('Y-m-d'), $day->format('Y-m-d')],
            'last_month'     => [$first->modify('-1 month')->format('Y-m-d'), $first->modify('-1 day')->format('Y-m-d')],
            'last_7_days'    => [$day->modify('-6 days')->format('Y-m-d'), $day->format('Y-m-d')],
            'last_30_days'   => [$day->modify('-29 days')->format('Y-m-d'), $day->format('Y-m-d')],
            // Départ au 1er du mois : douze mois entiers, sans débordement de fin de mois.
            'last_12_months' => [$first->modify('-11 months')->format('Y-m-d'), $day->format('Y-m-d')],
            'year_to_date'   => [$day->format('Y') . '-01-01', $day->format('Y-m-d')],
            default          => [null, null],
        };
    }

    /**
     * Bornes posées : « Tout » commence au premier jour d'activité connu (ou
     * aujourd'hui sans aucune activité) et finit aujourd'hui.
     *
     * @return array{from:string, to:string}
     */
    public static function bounded(array $period, ?string $firstDay, DateTimeImmutable $today): array
    {
        $day   = $today->format('Y-m-d');
        $first = $firstDay !== null ? self::validDay(substr($firstDay, 0, 10)) : '';
        $from  = $period['from'] ?? null;
        $to    = $period['to'] ?? null;
        $from  = $from ?? ($first !== '' ? min($first, $day) : $day);
        $to    = $to ?? $day;
        return ['from' => $from, 'to' => max($from, $to)];
    }

    /** Nombre de jours d'une plage, bornes incluses. */
    public static function days(string $from, string $to): int
    {
        return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    }

    /**
     * Période de comparaison. Calendrier pour les tranches calendaires (on
     * compare ce que l'on lira sur une facture) : mois en cours → mêmes jours
     * du mois précédent, mois dernier → le mois d'avant, année → même moment
     * de l'année précédente. Sinon la plage de même durée juste avant
     * (fenêtres glissantes, plage personnalisée). « Tout » : aucune.
     *
     * @return array{from:string, to:string}|null
     */
    public static function previous(array $period, string $from, string $to): ?array
    {
        $preset = (string)($period['preset'] ?? '');
        if ($preset === 'all') {
            return null;
        }
        $f = new DateTimeImmutable($from);
        $t = new DateTimeImmutable($to);
        switch ($preset) {
            case 'current_month':
                $prevFirst = $f->modify('first day of last month');
                $prevLast  = $prevFirst->modify('last day of this month');
                $prevTo    = $prevFirst->modify('+' . ((int)$t->format('j') - 1) . ' days');
                return ['from' => $prevFirst->format('Y-m-d'), 'to' => min($prevTo, $prevLast)->format('Y-m-d')];
            case 'last_month':
                $prevFirst = $f->modify('first day of last month');
                return ['from' => $prevFirst->format('Y-m-d'), 'to' => $prevFirst->modify('last day of this month')->format('Y-m-d')];
            case 'year_to_date':
                $year   = (int)$f->format('Y') - 1;
                $month  = (int)$t->format('n');
                $dayNum = min((int)$t->format('j'), (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'));
                return ['from' => sprintf('%04d-01-01', $year), 'to' => sprintf('%04d-%02d-%02d', $year, $month, $dayNum)];
        }
        $days   = self::days($from, $to);
        $prevTo = $f->modify('-1 day');
        return ['from' => $prevTo->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d')];
    }

    /**
     * Plage lisible : « 5 oct. 2026 », « 1 – 18 oct. 2026 »,
     * « 15 sept. – 3 oct. 2026 », « 15 déc. 2025 – 3 janv. 2026 ».
     * En anglais, l'ordre américain de Format::shortDate : « Oct 5, 2026 »,
     * « Oct 1 – 18, 2026 », « Sep 15 – Oct 3, 2026 »,
     * « Dec 15, 2025 – Jan 3, 2026 ». Mois abrégés de Format::monthLabel.
     */
    public static function rangeLabel(string $from, string $to): string
    {
        $f  = new DateTimeImmutable($from);
        $t  = new DateTimeImmutable($to);
        $nb = "\u{00A0}";
        $monthOf = static function (DateTimeImmutable $d): array {
            return explode(' ', Format::monthLabel($d->format('Y-m'))) + [1 => ''];
        };

        if (I18n::locale() === 'en') {
            $monthDay = static function (DateTimeImmutable $d, bool $withYear) use ($monthOf, $nb): string {
                [$month, $year] = $monthOf($d);
                $out = $month . $nb . $d->format('j');
                return $withYear ? $out . ',' . $nb . $year : $out;
            };
            if ($from === $to) {
                return $monthDay($f, true);
            }
            if ($f->format('Y-m') === $t->format('Y-m')) {
                return $monthDay($f, false) . ' – ' . $t->format('j') . ',' . $nb . $monthOf($t)[1];
            }
            if ($f->format('Y') === $t->format('Y')) {
                return $monthDay($f, false) . ' – ' . $monthDay($t, true);
            }
            return $monthDay($f, true) . ' – ' . $monthDay($t, true);
        }

        $dayMonth = static function (DateTimeImmutable $d, bool $withYear) use ($monthOf, $nb): string {
            [$month, $year] = $monthOf($d);
            $out = $d->format('j') . $nb . $month;
            return $withYear ? $out . $nb . $year : $out;
        };
        if ($from === $to) {
            return $dayMonth($f, true);
        }
        if ($f->format('Y-m') === $t->format('Y-m')) {
            return $f->format('j') . ' – ' . $dayMonth($t, true);
        }
        if ($f->format('Y') === $t->format('Y')) {
            return $dayMonth($f, false) . ' – ' . $dayMonth($t, true);
        }
        return $dayMonth($f, true) . ' – ' . $dayMonth($t, true);
    }

    /** Libellé de la tranche (« Mois en cours », « Plage personnalisée »), traduit. */
    public static function label(array $period): string
    {
        $preset = (string)($period['preset'] ?? '');
        return t(self::PRESETS[$preset]['label'] ?? 'Plage personnalisée');
    }

    /** Libellé court d'une tranche, celui du segment du sélecteur (« 7 jours »), traduit. */
    public static function shortLabel(string $preset): string
    {
        return isset(self::PRESETS[$preset]) ? t(self::PRESETS[$preset]['short']) : '';
    }

    /**
     * Paramètres d'URL qui redonnent la période (liens qui la gardent : export,
     * page sœur).
     *
     * @return array<string,string>
     */
    public static function query(array $period): array
    {
        if (!empty($period['custom'])) {
            return ['from' => (string)$period['from'], 'to' => (string)$period['to']];
        }
        return ['preset' => (string)($period['preset'] ?? '')];
    }
}
