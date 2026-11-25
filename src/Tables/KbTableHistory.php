<?php

declare(strict_types=1);

/**
 * Historique d'une table PERMANENTE — les règles et les mots (migration 116).
 *
 * SOURCE UNIQUE, pure : aucune requête ici. KbTableStore écrit et lit les
 * états (table `knowledge_base_table_versions`), KbTableController les
 * sert, ce fichier décide :
 *   - QUAND une retouche à la main rejoint l'état en cours (joinsEdit) — une
 *     même personne, moins d'un quart d'heure : 200 cellules corrigées d'affilée
 *     font UN état, pas 200 ;
 *   - CE QUI EST GARDÉ (retention) — 90 jours, 30 états au moins, 60 au plus ;
 *     la création reste inscrite même quand ses lignes ne sont plus gardées ;
 *   - COMMENT LE DIRE (who, what, note, view) — dans la langue de l'écran : les
 *     états ne portent que des compteurs et des noms (`detail`), jamais une
 *     phrase figée ;
 *   - CE QUI DIFFÈRE entre un état et maintenant (diff, summary, confirmText).
 *
 * Revenir à un état n'efface rien : c'est un nouvel état (KIND_RESTORE), qu'on
 * peut annuler un quart d'heure (UNDO_SECONDS) tant que rien d'autre n'a été
 * écrit depuis.
 */
final class KbTableHistory
{
    public const KIND_CREATE   = 'create';
    public const KIND_IMPORT   = 'import';
    public const KIND_EDIT     = 'edit';
    public const KIND_COLUMN   = 'column';
    public const KIND_MIA      = 'mia';
    public const KIND_WORKFLOW = 'workflow';
    public const KIND_RESTORE  = 'restore';
    /** État antérieur à l'historique (repris par la migration 116). */
    public const KIND_EARLIER  = 'earlier';

    public const KINDS = [
        self::KIND_CREATE, self::KIND_IMPORT, self::KIND_EDIT, self::KIND_COLUMN,
        self::KIND_MIA, self::KIND_WORKFLOW, self::KIND_RESTORE, self::KIND_EARLIER,
    ];

    /** Une retouche à la main rejoint l'état en cours de la même personne pendant ce délai. */
    public const GROUP_SECONDS = 900;

    /** Un retour en arrière s'annule pendant ce délai, tant que rien n'a été écrit depuis. */
    public const UNDO_SECONDS = 900;

    /** Les états plus anciens sont retirés… */
    public const KEEP_DAYS = 90;

    /** … sauf les plus récents, gardés quel que soit leur âge. */
    public const KEEP_MIN = 30;

    /** Jamais plus d'états gardés que ceci : chacun copie toutes les lignes. */
    public const KEEP_MAX = 60;

    /** Cellules retenues pour compter juste les cellules corrigées d'un état regroupé. */
    private const CELL_KEYS_MAX = 300;

    private const DAYS_FR = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const DAYS_EN = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    private const MONTHS_FR = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const MONTHS_EN = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    private const MONTHS_FR_SHORT = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const MONTHS_EN_SHORT = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Ce qu'une écriture d'ensemble dit d'elle-même. */
    public static function change(string $kind, array $detail = []): array
    {
        return ['kind' => in_array($kind, self::KINDS, true) ? $kind : self::KIND_EARLIER, 'detail' => $detail];
    }

    // ===================== Regrouper les retouches =====================

    /**
     * La retouche rejoint-elle l'état en service ? Oui s'il est lui-même fait
     * de retouches à la main, par la MÊME personne, retouché il y a moins d'un
     * quart d'heure. Pure.
     *
     * @param array|null $entry état en service (kind, user_id), ou null s'il n'est pas inscrit
     * @param int        $sinceLast secondes depuis sa dernière retouche
     */
    public static function joinsEdit(?array $entry, int $userId, int $sinceLast): bool
    {
        return $entry !== null
            && (string)($entry['kind'] ?? '') === self::KIND_EDIT
            && $userId > 0
            && (int)($entry['user_id'] ?? 0) === $userId
            && $sinceLast >= 0
            && $sinceLast < self::GROUP_SECONDS;
    }

    /**
     * Compte une retouche dans le détail d'un état regroupé. Pure.
     *
     * @param string $op cell | row_added | row_deleted | columns
     * @param array  $data cell : ['cell' => "rang:clé"] ; columns : ['before' => colonnes, 'after' => colonnes]
     */
    public static function noteEdit(array $detail, string $op, array $data = []): array
    {
        $detail += ['cells' => 0, 'cell_keys' => [], 'rows_added' => 0, 'rows_deleted' => 0,
            'columns_added' => [], 'columns_renamed' => [], 'columns_retyped' => []];
        switch ($op) {
            case 'cell':
                $cell = (string)($data['cell'] ?? '');
                $keys = (array)$detail['cell_keys'];
                if ($cell !== '' && !in_array($cell, $keys, true)) {
                    $detail['cells'] = (int)$detail['cells'] + 1;
                    if (count($keys) < self::CELL_KEYS_MAX) {
                        $keys[] = $cell;
                    }
                }
                $detail['cell_keys'] = array_values($keys);
                break;
            case 'row_added':
                $detail['rows_added'] = (int)$detail['rows_added'] + 1;
                break;
            case 'row_deleted':
                $detail['rows_deleted'] = (int)$detail['rows_deleted'] + 1;
                break;
            case 'columns':
                $before = [];
                foreach ((array)($data['before'] ?? []) as $c) {
                    $before[(string)$c['key']] = $c;
                }
                foreach ((array)($data['after'] ?? []) as $c) {
                    $key = (string)$c['key'];
                    if (!isset($before[$key])) {
                        $detail['columns_added'][] = (string)$c['label'];
                        continue;
                    }
                    if ((string)$before[$key]['label'] !== (string)$c['label']) {
                        $detail['columns_renamed'][] = [(string)$before[$key]['label'], (string)$c['label']];
                    }
                    if ((string)($before[$key]['type'] ?? '') !== (string)($c['type'] ?? '')) {
                        $detail['columns_retyped'][] = (string)$c['label'];
                    }
                }
                $detail['columns_added']   = array_values(array_unique($detail['columns_added']));
                $detail['columns_retyped'] = array_values(array_unique($detail['columns_retyped']));
                break;
        }
        return $detail;
    }

    // ===================== Conserver =====================

    /**
     * Ce qui sort de l'historique. Pure.
     *
     * Gardés : l'état en service, les KEEP_MIN plus récents, puis ceux de
     * moins de KEEP_DAYS jours dans la limite de KEEP_MAX. Au-delà, un état
     * part avec ses lignes (`drop`) — sauf la création, qui reste inscrite
     * sans ses lignes (`empty`).
     *
     * @param list<array{version:int, kind:string, created:int, rows_kept:bool}> $entries
     * @return array{drop: list<int>, empty: list<int>}
     */
    public static function retention(array $entries, int $current, int $now): array
    {
        usort($entries, static fn(array $a, array $b): int => (int)$b['version'] <=> (int)$a['version']);
        $limit = $now - self::KEEP_DAYS * 86400;
        $drop  = [];
        $empty = [];
        $kept  = 0;
        foreach ($entries as $e) {
            $version = (int)$e['version'];
            $keep = $version === $current
                || $kept < self::KEEP_MIN
                || ($kept < self::KEEP_MAX && (int)$e['created'] >= $limit);
            if ($keep) {
                $kept++;
                continue;
            }
            if ((string)$e['kind'] === self::KIND_CREATE) {
                if (!empty($e['rows_kept'])) {
                    $empty[] = $version;
                }
                continue;
            }
            $drop[] = $version;
        }
        return ['drop' => $drop, 'empty' => $empty];
    }

    /**
     * Le retour en arrière en service s'annule-t-il encore ? Il doit être
     * l'état en service, fait par cette personne il y a moins d'un quart
     * d'heure, et l'état qu'il a remplacé doit avoir gardé ses lignes. Pure.
     *
     * @param array      $current état en service
     * @param array|null $previous état qu'il a remplacé
     */
    public static function canUndo(array $current, ?array $previous, int $userId, int $age): bool
    {
        return (string)($current['kind'] ?? '') === self::KIND_RESTORE
            && $userId > 0
            && (int)($current['user_id'] ?? 0) === $userId
            && $age >= 0 && $age < self::UNDO_SECONDS
            && $previous !== null && !empty($previous['rows_kept']);
    }

    /**
     * Le retour en arrière en service s'annule-t-il encore, d'après la liste
     * des états (KbTableStore::history) ? Pure.
     */
    public static function undoableFrom(array $entries, int $current, int $userId): bool
    {
        $byVersion = [];
        foreach ($entries as $e) {
            $byVersion[(int)$e['version']] = $e;
        }
        $cur = $byVersion[$current] ?? null;
        if ($cur === null) {
            return false;
        }
        $prev = $byVersion[(int)(self::detail($cur)['prev'] ?? 0)] ?? null;
        return self::canUndo($cur, $prev, $userId, (int)($cur['age'] ?? PHP_INT_MAX));
    }

    /**
     * L'en-tête de la page : « Créée le 4 nov. 2026 par Claire Martin » et
     * « Modifiée il y a 2 h par MIA » (rien si la table n'a pas changé depuis
     * sa création). Pure.
     *
     * @param list<array> $entries   KbTableStore::history(), le plus récent d'abord
     * @param int         $createdTs création de la base (repli si la création n'est pas inscrite)
     * @param string      $creator   nom du créateur de la base (même repli)
     * @param int         $now       horloge
     * @return array{created:string, modified:string, count:int}
     */
    public static function head(array $entries, int $viewerId, int $createdTs, string $creator, int $now): array
    {
        $creation = null;
        foreach ($entries as $e) {
            if ((string)$e['kind'] === self::KIND_CREATE) {
                $creation = $e;
            }
        }
        $by = $creation !== null ? self::person($creation, $viewerId, '') : ($creator !== '' ? $creator : '');
        $ts = $creation !== null ? (int)$creation['created'] : $createdTs;
        $created = $by !== ''
            ? t('Créée le {date} par {nom}', ['date' => self::date($ts), 'nom' => self::lcVous($by)])
            : t('Créée le {date}', ['date' => self::date($ts)]);

        $latest   = $entries[0] ?? null;
        $modified = '';
        if ($latest !== null && !((string)$latest['kind'] === self::KIND_CREATE && (int)$latest['updated'] - (int)$latest['created'] < 60)) {
            $who = self::who($latest, $viewerId);
            $ago = KbStatus::ago(max(0, $now - (int)$latest['updated']));
            $modified = $who !== ''
                ? t('Modifiée {quand} par {nom}', ['quand' => $ago, 'nom' => self::lcVous($who)])
                : t('Modifiée {quand}', ['quand' => $ago]);
        }
        return ['created' => $created, 'modified' => $modified, 'count' => count($entries)];
    }

    /** « 4 nov. 2026 » (FR) / « Nov 4, 2026 » (EN), en UTC — une date, sans heure. Pure. */
    public static function date(int $ts): string
    {
        $at    = new \DateTimeImmutable('@' . $ts);
        $month = (int)$at->format('n');
        return I18n::locale() === 'en'
            ? self::MONTHS_EN_SHORT[$month] . ' ' . $at->format('j') . ', ' . $at->format('Y')
            : $at->format('j') . ' ' . self::MONTHS_FR_SHORT[$month] . ' ' . $at->format('Y');
    }

    // ===================== Dire =====================

    /** Qui a écrit cet état. */
    public static function who(array $entry, int $viewerId): string
    {
        $kind = (string)($entry['kind'] ?? '');
        $d    = self::detail($entry);
        if ($kind === self::KIND_MIA) {
            return self::appName();
        }
        if ($kind === self::KIND_WORKFLOW) {
            $name = trim((string)($d['workflow'] ?? ''));
            return $name !== '' ? t('Workflow « {nom} »', ['nom' => $name]) : t('Un workflow');
        }
        return self::person($entry, $viewerId, $kind === self::KIND_EARLIER ? '' : t('Compte supprimé'));
    }

    /** Ce qui a changé, en une phrase. */
    public static function what(array $entry, \DateTimeZone $tz): string
    {
        $d = self::detail($entry);
        $phrase = match ((string)($entry['kind'] ?? '')) {
            self::KIND_CREATE   => t('Table créée'),
            self::KIND_IMPORT   => self::importWhat($d),
            self::KIND_COLUMN   => I18n::plural(count((array)($d['dropped'] ?? [])), 'colonne {noms} supprimée', 'colonnes {noms} supprimées', [
                'noms' => I18n::listeCitee(array_map('strval', (array)($d['dropped'] ?? []))),
            ]),
            self::KIND_EDIT     => self::editWhat($d),
            self::KIND_MIA, self::KIND_WORKFLOW => self::writeWhat($d),
            self::KIND_RESTORE  => self::restoreWhat($d, $tz),
            default             => trim((string)($d['reason'] ?? '')) !== '' ? (string)$d['reason'] : t('État antérieur à l’historique'),
        };
        return self::ucfirst($phrase);
    }

    /** Une précision sous la phrase (accord, fichier, durée des retouches), ou ''. */
    public static function note(array $entry, int $viewerId, \DateTimeZone $tz): string
    {
        $d = self::detail($entry);
        return match ((string)($entry['kind'] ?? '')) {
            self::KIND_MIA      => (int)($entry['user_id'] ?? 0) === $viewerId && $viewerId > 0
                ? t('Après votre accord')
                : t('Après accord de {nom}', ['nom' => self::person($entry, $viewerId, t('un compte supprimé'))]),
            self::KIND_WORKFLOW => (int)($entry['user_id'] ?? 0) === $viewerId && $viewerId > 0
                ? t('Lancé par vous')
                : t('Lancé par {nom}', ['nom' => self::person($entry, $viewerId, t('un compte supprimé'))]),
            self::KIND_IMPORT   => (string)($d['mode'] ?? 'replace') === 'replace' ? t('Le fichier a remplacé tout le contenu.') : '',
            self::KIND_EDIT     => self::editNote($entry, $tz),
            self::KIND_CREATE   => KbTableImport::originLabel((array)($d['origin'] ?? [])),
            self::KIND_EARLIER  => t('Avant la mise en place de l’historique.'),
            default             => '',
        };
    }

    /**
     * Ce que montre le volet : les états groupés par jour, le plus récent
     * d'abord. Pure (l'horloge et le fuseau sont passés).
     *
     * @param list<array> $entries   KbTableStore::history()
     * @param int         $selected  état ouvert (0 = aucun)
     * @param bool        $canRestore la personne peut modifier la table
     * @param bool        $undo      le retour en arrière en service s'annule encore
     * @return list<array{day:string, items:list<array>}>
     */
    public static function view(array $entries, int $current, int $viewerId, \DateTimeZone $tz, int $now, int $selected, bool $canRestore, bool $undo): array
    {
        $today  = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $groups = [];
        foreach ($entries as $e) {
            $version = (int)$e['version'];
            $at      = (new \DateTimeImmutable('@' . (int)$e['created']))->setTimezone($tz);
            $day     = self::dayLabel($at, $today);
            if ($groups === [] || $groups[count($groups) - 1]['day'] !== $day) {
                $groups[] = ['day' => $day, 'items' => []];
            }
            $isNow = $version === $current;
            $kept  = !empty($e['rows_kept']);
            $groups[count($groups) - 1]['items'][] = [
                'version'     => $version,
                'kind'        => (string)$e['kind'],
                'time'        => Format::time($at->format('H:i')),
                'who'         => self::who($e, $viewerId),
                'what'        => self::what($e, $tz),
                'note'        => $kept ? self::note($e, $viewerId, $tz) : t('Son contenu n’est plus conservé.'),
                'is_now'      => $isNow,
                'open'        => $version === $selected,
                'kept'        => $kept,
                'can_restore' => $canRestore && !$isNow && $kept,
                'can_undo'    => $isNow && $undo && $canRestore,
            ];
        }
        return $groups;
    }

    /** « Aujourd'hui », « Hier », « Mardi 17 novembre » (l'année si ce n'est pas celle d'aujourd'hui). Pure. */
    public static function dayLabel(\DateTimeImmutable $date, \DateTimeImmutable $today): string
    {
        $d = $date->format('Y-m-d');
        if ($d === $today->format('Y-m-d')) {
            return t('Aujourd’hui');
        }
        if ($d === $today->modify('-1 day')->format('Y-m-d')) {
            return t('Hier');
        }
        $en    = I18n::locale() === 'en';
        $year  = $date->format('Y') !== $today->format('Y') ? ' ' . $date->format('Y') : '';
        $wday  = (int)$date->format('N');
        $month = (int)$date->format('n');
        return $en
            ? self::DAYS_EN[$wday] . ', ' . self::MONTHS_EN[$month] . ' ' . $date->format('j') . ($year !== '' ? ',' . $year : '')
            : self::ucfirst(self::DAYS_FR[$wday]) . ' ' . $date->format('j') . ' ' . self::MONTHS_FR[$month] . $year;
    }

    /** « 17 nov. à 9 h 12 » (FR) / « Nov 17 at 09:12 » (EN), l'année si elle diffère. Pure. */
    public static function moment(int $ts, \DateTimeZone $tz, ?int $now = null): string
    {
        $at    = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $yearN = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->format('Y');
        $year  = $at->format('Y') !== $yearN ? ' ' . $at->format('Y') : '';
        $month = (int)$at->format('n');
        $time  = Format::time($at->format('H:i'));
        return I18n::locale() === 'en'
            ? self::MONTHS_EN_SHORT[$month] . ' ' . $at->format('j') . ($year !== '' ? ',' . $year : '') . ' at ' . $time
            : $at->format('j') . ' ' . self::MONTHS_FR_SHORT[$month] . $year . ' à ' . $time;
    }

    // ===================== Comparer =====================

    /**
     * Ce qui diffère entre un état (`then`) et la table en service (`now`).
     * Les lignes se rapprochent par la valeur de leur PREMIÈRE colonne commune
     * (sans casse ni accents), dans l'ordre de leurs occurrences : une ligne
     * insérée en tête ne fait pas paraître toutes les suivantes changées. Pure.
     *
     * @return array{rows_then:int, rows_now:int, cols_then:int, cols_now:int, cells:int,
     *               marks: array<int, array{changed: array<string, string>, gone: bool}>}
     */
    public static function diff(array $colsThen, array $rowsThen, array $colsNow, array $rowsNow): array
    {
        $keysThen = WorkTableOps::keys($colsThen);
        $keysNow  = WorkTableOps::keys($colsNow);
        $common   = array_values(array_intersect($keysThen, $keysNow));
        $out = [
            'rows_then' => count($rowsThen), 'rows_now' => count($rowsNow),
            'cols_then' => count($colsThen), 'cols_now' => count($colsNow),
            'cells'     => 0, 'marks' => [],
        ];
        if ($common === []) {
            return $out;
        }
        $anchor = $common[0];
        $index  = [];
        foreach (array_values($rowsNow) as $i => $row) {
            $index[self::anchorOf($row, $anchor)][] = $i;
        }
        $seen    = [];
        $rowsNow = array_values($rowsNow);
        foreach (array_values($rowsThen) as $i => $row) {
            $k = self::anchorOf($row, $anchor);
            $n = $seen[$k] ?? 0;
            $seen[$k] = $n + 1;
            $match = $index[$k][$n] ?? null;
            if ($match === null) {
                $out['marks'][$i] = ['changed' => [], 'gone' => true];
                continue;
            }
            $changed = [];
            foreach ($common as $key) {
                $then = (string)($row[$key] ?? '');
                $now  = (string)($rowsNow[$match][$key] ?? '');
                if ($then !== $now) {
                    $changed[$key] = $now;
                }
            }
            if ($changed !== []) {
                $out['cells'] += count($changed);
                $out['marks'][$i] = ['changed' => $changed, 'gone' => false];
            }
        }
        return $out;
    }

    /** « 1 ligne de moins, 27 cellules différentes par rapport à maintenant. » Pure. */
    public static function summary(array $diff): string
    {
        $parts = [];
        $dr = (int)$diff['rows_then'] - (int)$diff['rows_now'];
        $dc = (int)$diff['cols_then'] - (int)$diff['cols_now'];
        if ($dr < 0) {
            $parts[] = I18n::plural(-$dr, '{n} ligne de moins', '{n} lignes de moins', ['n' => Format::credits(-$dr)]);
        } elseif ($dr > 0) {
            $parts[] = I18n::plural($dr, '{n} ligne de plus', '{n} lignes de plus', ['n' => Format::credits($dr)]);
        }
        if ($dc < 0) {
            $parts[] = I18n::plural(-$dc, '{n} colonne de moins', '{n} colonnes de moins');
        } elseif ($dc > 0) {
            $parts[] = I18n::plural($dc, '{n} colonne de plus', '{n} colonnes de plus');
        }
        if ((int)$diff['cells'] > 0) {
            $parts[] = I18n::plural((int)$diff['cells'], '{n} cellule différente', '{n} cellules différentes', ['n' => Format::credits((int)$diff['cells'])]);
        }
        return $parts === []
            ? t('Identique à maintenant.')
            : t('{changements} par rapport à maintenant.', ['changements' => implode(', ', $parts)]);
    }

    /** Ce qu'un retour à cet état change, en deux phrases au plus. Pure. */
    public static function confirmText(array $diff): string
    {
        $out = t('La table repasse à {lignes} et {colonnes}.', [
            'lignes'   => WorkTableView::rowsLabel((int)$diff['rows_then']),
            'colonnes' => I18n::plural((int)$diff['cols_then'], '{n} colonne', '{n} colonnes'),
        ]);
        if ((int)$diff['cells'] > 0) {
            $out .= ' ' . I18n::plural((int)$diff['cells'], '{n} cellule reprend sa valeur d’alors.', '{n} cellules reprennent leur valeur d’alors.', [
                'n' => Format::credits((int)$diff['cells']),
            ]);
        }
        return $out;
    }

    // ===================== Interne =====================

    private static function detail(array $entry): array
    {
        $d = $entry['detail'] ?? null;
        if (is_string($d)) {
            $d = json_decode($d, true);
        }
        return is_array($d) ? $d : [];
    }

    private static function person(array $entry, int $viewerId, string $missing): string
    {
        $uid = (int)($entry['user_id'] ?? 0);
        if ($uid > 0 && $uid === $viewerId) {
            return t('Vous');
        }
        $name = trim((string)($entry['user_name'] ?? ''));
        return $name !== '' ? $name : $missing;
    }

    /** « Vous » au milieu d'une phrase s'écrit « vous ». */
    private static function lcVous(string $name): string
    {
        return $name === t('Vous') ? t('vous') : $name;
    }

    private static function importWhat(array $d): string
    {
        $file = trim((string)($d['file'] ?? ''));
        if ((string)($d['mode'] ?? 'replace') === 'append') {
            $n = (int)($d['added'] ?? 0);
            return $file !== ''
                ? I18n::plural($n, '{n} ligne ajoutée depuis « {fichier} »', '{n} lignes ajoutées depuis « {fichier} »', ['n' => Format::credits($n), 'fichier' => $file])
                : I18n::plural($n, '{n} ligne ajoutée depuis un fichier', '{n} lignes ajoutées depuis un fichier', ['n' => Format::credits($n)]);
        }
        return $file !== '' ? t('fichier « {fichier} » importé', ['fichier' => $file]) : t('fichier importé');
    }

    private static function editWhat(array $d): string
    {
        $parts = [];
        if ((int)($d['rows_added'] ?? 0) > 0) {
            $parts[] = I18n::plural((int)$d['rows_added'], '{n} ligne ajoutée', '{n} lignes ajoutées', ['n' => Format::credits((int)$d['rows_added'])]);
        }
        if ((int)($d['rows_deleted'] ?? 0) > 0) {
            $parts[] = I18n::plural((int)$d['rows_deleted'], '{n} ligne supprimée', '{n} lignes supprimées', ['n' => Format::credits((int)$d['rows_deleted'])]);
        }
        if ((int)($d['cells'] ?? 0) > 0) {
            $parts[] = I18n::plural((int)$d['cells'], '{n} cellule corrigée', '{n} cellules corrigées', ['n' => Format::credits((int)$d['cells'])]);
        }
        $added = array_map('strval', (array)($d['columns_added'] ?? []));
        if ($added !== []) {
            $parts[] = I18n::plural(count($added), 'colonne {noms} ajoutée', 'colonnes {noms} ajoutées', ['noms' => I18n::listeCitee($added)]);
        }
        foreach ((array)($d['columns_renamed'] ?? []) as $pair) {
            if (is_array($pair) && count($pair) === 2) {
                $parts[] = t('colonne « {avant} » renommée « {apres} »', ['avant' => (string)$pair[0], 'apres' => (string)$pair[1]]);
            }
        }
        $retyped = array_map('strval', (array)($d['columns_retyped'] ?? []));
        if ($retyped !== []) {
            $parts[] = I18n::plural(count($retyped), 'type de la colonne {noms} changé', 'type des colonnes {noms} changé', ['noms' => I18n::listeCitee($retyped)]);
        }
        return $parts === [] ? t('retouches à la main') : implode(', ', $parts);
    }

    private static function editNote(array $entry, \DateTimeZone $tz): string
    {
        $from = (int)($entry['created'] ?? 0);
        $to   = (int)($entry['updated'] ?? $from);
        if ($to - $from < 60) {
            return t('Retouche à la main.');
        }
        $start = (new \DateTimeImmutable('@' . $from))->setTimezone($tz);
        $end   = (new \DateTimeImmutable('@' . $to))->setTimezone($tz);
        return t('Retouches à la main, de {debut} à {fin}.', [
            'debut' => Format::time($start->format('H:i')),
            'fin'   => Format::time($end->format('H:i')),
        ]);
    }

    /** Ce qu'a écrit MIA ou un workflow (mode et compteurs de KbTableWrite). */
    private static function writeWhat(array $d): string
    {
        $mode = (string)($d['mode'] ?? '');
        $n = static fn(string $k): int => (int)($d[$k] ?? 0);
        $added = array_map('strval', (array)($d['columns_added'] ?? []));
        $parts = [];
        switch ($mode) {
            case KbTableWrite::MODE_DELETE:
                $parts[] = I18n::plural($n('deleted'), '{n} ligne supprimée', '{n} lignes supprimées', ['n' => Format::credits($n('deleted'))]);
                break;
            case KbTableWrite::MODE_RENAME:
                $pair = (array)($d['columns_renamed'] ?? []);
                $parts[] = t('colonne « {avant} » renommée « {apres} »', ['avant' => (string)($pair[0] ?? ''), 'apres' => (string)($pair[1] ?? '')]);
                break;
            case KbTableWrite::MODE_DROP:
                $dropped = array_map('strval', (array)($d['columns_dropped'] ?? []));
                $parts[] = I18n::plural(count($dropped), 'colonne {noms} supprimée', 'colonnes {noms} supprimées', ['noms' => I18n::listeCitee($dropped)]);
                break;
            case KbTableWrite::MODE_REPLACE:
                $parts[] = t('contenu remplacé ({lignes})', ['lignes' => WorkTableView::rowsLabel($n('after'))]);
                break;
            case KbTableWrite::MODE_APPEND:
                $parts[] = I18n::plural($n('added'), '{n} ligne ajoutée', '{n} lignes ajoutées', ['n' => Format::credits($n('added'))]);
                break;
            default:
                if ($n('touched') > 0) {
                    $parts[] = $mode === KbTableWrite::MODE_UPDATE
                        ? I18n::plural($n('touched'), '{n} ligne mise à jour', '{n} lignes mises à jour', ['n' => Format::credits($n('touched'))])
                        : I18n::plural($n('touched'), '{n} ligne modifiée', '{n} lignes modifiées', ['n' => Format::credits($n('touched'))]);
                }
                if ($mode === KbTableWrite::MODE_UPDATE && $n('added') > 0) {
                    $parts[] = I18n::plural($n('added'), '{n} ligne ajoutée', '{n} lignes ajoutées', ['n' => Format::credits($n('added'))]);
                }
        }
        if ($added !== [] && !in_array($mode, [KbTableWrite::MODE_REPLACE, KbTableWrite::MODE_RENAME, KbTableWrite::MODE_DROP], true)) {
            $parts[] = I18n::plural(count($added), 'colonne {noms} ajoutée', 'colonnes {noms} ajoutées', ['noms' => I18n::listeCitee($added)]);
        }
        return $parts === [] ? t('table modifiée') : implode(', ', $parts);
    }

    private static function restoreWhat(array $d, \DateTimeZone $tz): string
    {
        $at = (int)($d['from_at'] ?? 0);
        return $at > 0
            ? t('retour à l’état du {moment}', ['moment' => self::moment($at, $tz)])
            : t('retour à un état précédent');
    }

    private static function anchorOf(array $row, string $key): string
    {
        return WorkTableOps::fold(trim((string)($row[$key] ?? '')));
    }

    private static function appName(): string
    {
        try {
            $name = trim((string)Settings::get('app_name', 'MIA'));
        } catch (\Throwable) {
            $name = '';
        }
        return $name !== '' ? $name : 'MIA';
    }

    private static function ucfirst(string $s): string
    {
        return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
