<?php

declare(strict_types=1);

/**
 * Opérations sur les tables de travail — PURES (aucune base, aucun réseau).
 *
 * Une table de travail = des colonnes `[{key, label, type, tech?}]` et des
 * lignes `[clé de colonne → texte]`. Tout ce qui doit être EXHAUSTIF se fait
 * ici, en code : filtrer, dédoublonner, regrouper et compter, trier, croiser
 * deux tables. Le modèle décide QUOI faire ; il ne compte ni ne recopie
 * jamais lui-même (il sauterait des lignes sans le savoir).
 *
 * Les erreurs de forme (colonne inconnue, test inconnu) lèvent une
 * InvalidArgumentException dont le message s'adresse AU MODÈLE : il nomme ce
 * qui existe, pour corriger à l'aller suivant.
 */
final class WorkTableOps
{
    /** Tables par conversation. */
    public const MAX_TABLES  = 20;
    /** Lignes par table. */
    public const MAX_ROWS    = 5000;
    public const MAX_COLUMNS = 40;
    /** Caractères par cellule (au-delà : coupée). */
    public const MAX_CELL    = 2000;
    public const NAME_MAX    = 40;
    public const TITLE_MAX   = 120;
    public const LABEL_MAX   = 60;

    /** Caractères d'une cellule RENDUE AU MODÈLE (aperçu, exemples). */
    public const PREVIEW_CELL         = 160;
    /** Lignes d'exemple jointes au résultat d'une opération. */
    public const SAMPLE_ROWS          = 3;
    public const PREVIEW_ROWS_DEFAULT = 20;
    public const PREVIEW_ROWS_MAX     = 50;
    /** Lignes qu'un appel de `ecrire_table` peut porter. */
    public const WRITE_ROWS_MAX       = 200;

    public const TYPES = ['text', 'number', 'date'];

    /** Tests d'un filtre (vocabulaire de l'outil) → opérateur interne. */
    public const TESTS = [
        'egal', 'different', 'contient', 'ne_contient_pas', 'commence_par',
        'vide', 'non_vide', 'superieur', 'au_moins', 'inferieur', 'au_plus', 'parmi',
    ];

    /** Façons de rapprocher deux clés. */
    public const MATCHES = ['exact', 'email', 'domaine', 'contient'];

    /** Ce qu'un croisement garde. */
    public const KEEPS = ['correspondances', 'tout_gauche', 'sans_correspondance'];

    /** Calculs d'un regroupement. */
    public const CALCS = ['compte', 'compte_distinct', 'somme', 'moyenne', 'min', 'max', 'liste', 'premier', 'dernier'];

    /** Valeurs distinctes qu'un calcul `liste` garde. */
    private const LIST_VALUES = 20;

    // ==================================================================
    // Noms, colonnes, lignes
    // ==================================================================

    /** Nom court d'une table ou clé d'une colonne : minuscules, chiffres, « _ ». */
    public static function slug(string $name, int $max = self::NAME_MAX): string
    {
        $s = str_replace(' ', '_', UserDriveService::normalizeForSearch($name));
        $s = trim((string)preg_replace('/_+/', '_', $s), '_');
        return rtrim(substr($s, 0, max(1, $max)), '_');
    }

    /**
     * Un brouillon interne (enrichissement ou actions sur une table
     * permanente) : son nom commence par « _ », ce que slug() ne laisse
     * jamais faire au modèle. Ni listé, ni compté, ni montré. Pure.
     */
    public static function isHidden(string $name): bool
    {
        return str_starts_with($name, '_');
    }

    /** Minuscules, sans accents ni ponctuation, espaces réduits — pour COMPARER. */
    public static function fold(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $folded = UserDriveService::normalizeForSearch($value);
        // Écriture non latine : la normalisation ne garde rien — on compare
        // alors le texte tel quel plutôt que de tout déclarer égal.
        return $folded !== '' ? $folded : mb_strtolower($value);
    }

    /** Une cellule : du texte, sur une forme stable, bornée. */
    public static function cell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'oui' : 'non';
        }
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn($v): string => is_scalar($v) ? (string)$v : '', $value));
        }
        if (!is_scalar($value)) {
            return '';
        }
        $s = str_replace(["\r\n", "\r"], "\n", (string)$value);
        $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = trim($s);
        return mb_strlen($s) > self::MAX_CELL ? mb_substr($s, 0, self::MAX_CELL - 1) . '…' : $s;
    }

    /**
     * Colonnes normalisées depuis des libellés (chaînes) ou des définitions
     * `{key?, label, type?, tech?}` : clés uniques, bornées.
     *
     * @return list<array{key:string,label:string,type:string,tech:bool}>
     */
    public static function columns(array $defs): array
    {
        $out  = [];
        $seen = [];
        foreach (array_values($defs) as $i => $def) {
            if (count($out) >= self::MAX_COLUMNS) {
                break;
            }
            $label = is_array($def) ? (string)($def['label'] ?? ($def['key'] ?? '')) : (is_scalar($def) ? (string)$def : '');
            $label = trim((string)preg_replace('/\s+/u', ' ', $label));
            $label = $label !== '' ? mb_substr($label, 0, self::LABEL_MAX) : 'Colonne ' . ($i + 1);
            $key   = is_array($def) && is_string($def['key'] ?? null) ? self::slug($def['key']) : '';
            if ($key === '') {
                $key = self::slug($label);
            }
            if ($key === '') {
                $key = 'col_' . ($i + 1);
            }
            $base = $key;
            $n = 2;
            while (isset($seen[$key])) {
                $key = substr($base, 0, self::NAME_MAX - 3) . '_' . $n++;
            }
            $seen[$key] = true;
            $type = is_array($def) && in_array($def['type'] ?? '', self::TYPES, true) ? (string)$def['type'] : 'text';
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'tech' => is_array($def) && !empty($def['tech'])];
        }
        return $out;
    }

    /** @return list<string> */
    public static function keys(array $columns): array
    {
        return array_values(array_map(static fn(array $c): string => (string)$c['key'], $columns));
    }

    /**
     * Lignes ramenées aux colonnes : une ligne est une LISTE (dans l'ordre
     * des colonnes) ou un objet (par clé ou par libellé). Lignes vides retirées.
     *
     * @return list<array<string, string>>
     */
    public static function rows(array $columns, array $rows): array
    {
        $keys = self::keys($columns);
        $byLabel = [];
        foreach ($columns as $c) {
            $byLabel[self::fold((string)$c['label'])] = (string)$c['key'];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = array_fill_keys($keys, '');
            if (array_is_list($row)) {
                foreach ($keys as $i => $key) {
                    $line[$key] = self::cell($row[$i] ?? '');
                }
            } else {
                foreach ($row as $k => $v) {
                    $key = isset($line[(string)$k]) ? (string)$k : ($byLabel[self::fold((string)$k)] ?? null);
                    if ($key !== null) {
                        $line[$key] = self::cell($v);
                    }
                }
            }
            if (implode('', $line) !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Colonne désignée par sa clé ou son libellé (casse et accents ignorés).
     *
     * @throws \InvalidArgumentException colonne inconnue (les colonnes sont nommées)
     */
    public static function column(array $columns, mixed $ref): string
    {
        $ref = is_scalar($ref) ? trim((string)$ref) : '';
        $wanted = self::slug($ref);
        $folded = self::fold($ref);
        foreach ($columns as $c) {
            if ($ref === $c['key'] || ($wanted !== '' && $wanted === $c['key'])) {
                return (string)$c['key'];
            }
        }
        foreach ($columns as $c) {
            if ($folded !== '' && $folded === self::fold((string)$c['label'])) {
                return (string)$c['key'];
            }
        }
        throw new \InvalidArgumentException('Colonne « ' . $ref . ' » inconnue. Colonnes de la table : '
            . implode(', ', self::keys($columns)) . '.');
    }

    /**
     * Colonnes typées d'après leurs valeurs (une colonne déclarée `text` dont
     * toutes les valeurs sont des nombres ou des dates se trie comme telle).
     */
    public static function typed(array $columns, array $rows): array
    {
        foreach ($columns as $i => $c) {
            if (($c['type'] ?? 'text') === 'text') {
                $columns[$i]['type'] = self::guessType($rows, (string)$c['key']);
            }
        }
        return $columns;
    }

    private static function guessType(array $rows, string $key): string
    {
        $numbers = 0;
        $dates   = 0;
        $seen    = 0;
        foreach ($rows as $row) {
            $v = (string)($row[$key] ?? '');
            if ($v === '') {
                continue;
            }
            $seen++;
            if (self::number($v) !== null) {
                $numbers++;
            } elseif (self::dateKey($v) !== null) {
                $dates++;
            } else {
                return 'text';
            }
            if ($seen >= 400) {
                break;
            }
        }
        if ($seen === 0) {
            return 'text';
        }
        return $numbers === $seen ? 'number' : ($dates === $seen ? 'date' : 'text');
    }

    // ==================================================================
    // Valeurs
    // ==================================================================

    /**
     * Nombre écrit dans une cellule (« 1 250,50 € », « 12% », « -3.5 ») ; null
     * pour tout le reste — un code « AB12 » ou un code postal « 01000 » n'est
     * pas un nombre.
     */
    public static function number(string $value): ?float
    {
        $s = trim($value);
        if ($s === '' || preg_match('/^[+\-]?[\d\s\x{00A0}\x{202F}.,]+\s*(?:€|\$|£|%|k€|eur|usd)?$/iu', $s) !== 1
            || preg_match('/\d/', $s) !== 1) {
            return null;
        }
        if (preg_match('/^0\d+$/', $s) === 1) {
            return null;
        }
        $n = WorkflowValues::number($s);
        return $n === null ? null : (float)$n;
    }

    /** Date (et heure) d'une cellule sous une forme TRIABLE : « 2026-11-16 09:30 », ou null. */
    public static function dateKey(string $value): ?string
    {
        $s = trim($value);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $s, $m) === 1) {
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return null;
            }
            return $m[1] . '-' . $m[2] . '-' . $m[3] . (isset($m[4]) ? ' ' . $m[4] . ':' . $m[5] : '');
        }
        if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})(?:\s+(\d{1,2})[:h](\d{2}))?$#', $s, $m) === 1) {
            if (!checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1])
                . (isset($m[4]) ? sprintf(' %02d:%02d', (int)$m[4], (int)$m[5]) : '');
        }
        return WorkflowValues::date($s);
    }

    /** Horodatage → « 2026-11-16 09:30 » dans le fuseau donné (celui de la personne). */
    public static function stamp(int $timestamp, ?\DateTimeZone $tz = null): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz ?? new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
    }

    /** Adresses email citées dans une cellule (minuscules, sans doublon). @return list<string> */
    public static function emails(string $value): array
    {
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m) < 1) {
            return [];
        }
        return array_values(array_unique(array_map('mb_strtolower', $m[0])));
    }

    /** Domaines cités dans une cellule : ceux des adresses, sinon celui d'un site. @return list<string> */
    public static function domains(string $value): array
    {
        $out = [];
        foreach (self::emails($value) as $mail) {
            $out[] = substr($mail, (int)strrpos($mail, '@') + 1);
        }
        if ($out === [] && preg_match('#^(?:https?://)?(?:www\.)?([a-z0-9][a-z0-9\-]*(?:\.[a-z0-9\-]+)+)(?:[/:?\#].*)?$#i', trim($value), $m) === 1) {
            $out[] = mb_strtolower($m[1]);
        }
        return array_values(array_unique(array_map(
            static fn(string $d): string => str_starts_with($d, 'www.') ? substr($d, 4) : $d,
            $out
        )));
    }

    // ==================================================================
    // Filtrer
    // ==================================================================

    /**
     * Une cellule passe-t-elle le test ? Nombres et dates se comparent comme
     * tels quand les DEUX côtés en sont ; sinon le texte, sans casse ni accents.
     */
    public static function test(string $cell, string $test, string $target): bool
    {
        $empty = trim($cell) === '';
        switch ($test) {
            case 'vide':
                return $empty;
            case 'non_vide':
                return !$empty;
            case 'parmi':
                foreach (preg_split('/\s*[|;]\s*|\s*\n\s*/u', $target) ?: [] as $one) {
                    if ($one !== '' && self::test($cell, 'egal', $one)) {
                        return true;
                    }
                }
                return false;
            case 'different':
                return !self::test($cell, 'egal', $target);
            case 'ne_contient_pas':
                return !self::test($cell, 'contient', $target);
        }
        if ($empty) {
            return false;
        }
        $a = self::fold($cell);
        $b = self::fold($target);
        if ($test === 'contient') {
            return $b !== '' && str_contains($a, $b);
        }
        if ($test === 'commence_par') {
            return $b !== '' && str_starts_with($a, $b);
        }
        $cmp = self::compare($cell, $target);
        return match ($test) {
            'egal'      => $cmp === 0,
            'superieur' => $cmp > 0,
            'au_moins'  => $cmp >= 0,
            'inferieur' => $cmp < 0,
            'au_plus'   => $cmp <= 0,
            default     => false,
        };
    }

    /** Ordre de deux valeurs : nombres, puis dates, sinon texte (sans casse ni accents). */
    public static function compare(string $a, string $b): int
    {
        $na = self::number($a);
        $nb = self::number($b);
        if ($na !== null && $nb !== null) {
            return $na <=> $nb;
        }
        $da = self::dateKey($a);
        $db = self::dateKey($b);
        if ($da !== null && $db !== null) {
            // Une date sans heure se compare au JOUR de l'autre.
            $len = min(strlen($da), strlen($db));
            return substr($da, 0, $len) <=> substr($db, 0, $len);
        }
        return strnatcmp(self::fold($a), self::fold($b)) <=> 0;
    }

    /**
     * Filtres du modèle → conditions vérifiées `[{col, test, value}]`.
     *
     * @throws \InvalidArgumentException
     */
    public static function conditions(array $columns, mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $out = [];
        foreach ((array)$raw as $f) {
            if (!is_array($f)) {
                continue;
            }
            $test = is_scalar($f['test'] ?? null) ? self::slug((string)$f['test']) : '';
            if (!in_array($test, self::TESTS, true)) {
                throw new \InvalidArgumentException('Test « ' . $test . ' » inconnu. Tests possibles : ' . implode(', ', self::TESTS) . '.');
            }
            $value = $f['valeur'] ?? '';
            $out[] = [
                'col'   => self::column($columns, $f['colonne'] ?? ''),
                'test'  => $test,
                'value' => is_array($value) ? implode(' | ', array_map('strval', array_filter($value, 'is_scalar'))) : (is_scalar($value) ? (string)$value : ''),
            ];
        }
        return $out;
    }

    /** @return list<array<string, string>> lignes qui passent toutes les conditions (ou au moins une) */
    public static function filter(array $rows, array $conditions, bool $any = false): array
    {
        if ($conditions === []) {
            return array_values($rows);
        }
        $out = [];
        foreach ($rows as $row) {
            $ok = !$any;
            foreach ($conditions as $c) {
                $pass = self::test((string)($row[$c['col']] ?? ''), $c['test'], $c['value']);
                if ($any && $pass) {
                    $ok = true;
                    break;
                }
                if (!$any && !$pass) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $row;
            }
        }
        return $out;
    }

    // ==================================================================
    // Dédoublonner, trier, choisir des colonnes
    // ==================================================================

    /** Première ligne de chaque valeur (sans casse ni accents) des colonnes données. */
    public static function dedupe(array $rows, array $keys): array
    {
        $seen = [];
        $out  = [];
        foreach ($rows as $row) {
            $sig = implode("\x1f", array_map(static fn(string $k): string => self::fold((string)($row[$k] ?? '')), $keys));
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Tri stable sur plusieurs colonnes ; les cellules vides vont à la fin.
     *
     * @param list<array{col:string, desc:bool}> $by
     */
    public static function sort(array $rows, array $by): array
    {
        if ($by === []) {
            return array_values($rows);
        }
        $indexed = [];
        foreach (array_values($rows) as $i => $row) {
            $indexed[] = [$i, $row];
        }
        usort($indexed, static function (array $x, array $y) use ($by): int {
            foreach ($by as $b) {
                $a = (string)($x[1][$b['col']] ?? '');
                $c = (string)($y[1][$b['col']] ?? '');
                if ($a === '' || $c === '') {
                    if ($a === $c) {
                        continue;
                    }
                    return $a === '' ? 1 : -1;
                }
                $cmp = self::compare($a, $c);
                if ($cmp !== 0) {
                    return $b['desc'] ? -$cmp : $cmp;
                }
            }
            return $x[0] <=> $y[0];
        });
        return array_map(static fn(array $p): array => $p[1], $indexed);
    }

    /**
     * Tri demandé par le modèle → `[{col, desc}]`.
     *
     * @throws \InvalidArgumentException
     */
    public static function sortSpec(array $columns, mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : ($raw !== '' ? [['colonne' => $raw]] : []);
        }
        $out = [];
        foreach ((array)$raw as $s) {
            if (is_scalar($s)) {
                $s = ['colonne' => (string)$s];
            }
            if (!is_array($s)) {
                continue;
            }
            $sens = is_scalar($s['sens'] ?? null) ? self::fold((string)$s['sens']) : '';
            $out[] = [
                'col'  => self::column($columns, $s['colonne'] ?? ''),
                'desc' => in_array($sens, ['decroissant', 'desc', 'descendant', 'z a', 'recent'], true),
            ];
        }
        return $out;
    }

    /** Colonnes gardées, dans l'ordre demandé. @return array{0: list<array>, 1: list<array<string,string>>} */
    public static function select(array $columns, array $rows, array $keys): array
    {
        $byKey = [];
        foreach ($columns as $c) {
            $byKey[(string)$c['key']] = $c;
        }
        $kept = [];
        foreach ($keys as $k) {
            if (isset($byKey[$k]) && !isset($kept[$k])) {
                $kept[$k] = $byKey[$k];
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($kept) as $k) {
                $line[$k] = (string)($row[$k] ?? '');
            }
            $out[] = $line;
        }
        return [array_values($kept), $out];
    }

    // ==================================================================
    // Regrouper et calculer
    // ==================================================================

    /**
     * Calculs demandés par le modèle → `[{op, col, key, label}]`.
     *
     * @throws \InvalidArgumentException
     */
    public static function calcs(array $columns, mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $out  = [];
        $seen = [];
        foreach ((array)$raw as $c) {
            if (is_scalar($c)) {
                $c = ['operation' => (string)$c];
            }
            if (!is_array($c)) {
                continue;
            }
            $op = is_scalar($c['operation'] ?? null) ? self::slug((string)$c['operation']) : '';
            if (!in_array($op, self::CALCS, true)) {
                throw new \InvalidArgumentException('Calcul « ' . $op . ' » inconnu. Calculs possibles : ' . implode(', ', self::CALCS) . '.');
            }
            $col = '';
            if ($op !== 'compte') {
                $col = self::column($columns, $c['colonne'] ?? '');
            }
            $name  = is_scalar($c['nom'] ?? null) ? trim((string)$c['nom']) : '';
            $label = $name !== '' ? mb_substr($name, 0, self::LABEL_MAX) : ($op === 'compte' ? 'Nombre' : ucfirst(str_replace('_', ' ', $op)) . ' ' . $col);
            $key   = self::slug($label);
            $base  = $key !== '' ? $key : $op;
            $key   = $base;
            $n = 2;
            while (isset($seen[$key])) {
                $key = $base . '_' . $n++;
            }
            $seen[$key] = true;
            $out[] = ['op' => $op, 'col' => $col, 'key' => $key, 'label' => $label];
        }
        return $out;
    }

    /**
     * Une ligne par valeur des colonnes `$by`, avec ses calculs (un
     * regroupement sans colonne rend UNE ligne : les totaux de la table).
     *
     * @return array{0: list<array>, 1: list<array<string,string>>} colonnes, lignes
     */
    public static function group(array $columns, array $rows, array $by, array $calcs): array
    {
        if ($calcs === []) {
            $calcs = [['op' => 'compte', 'col' => '', 'key' => 'nombre', 'label' => 'Nombre']];
        }
        $groups = [];
        foreach ($rows as $row) {
            $sig = implode("\x1f", array_map(static fn(string $k): string => self::fold((string)($row[$k] ?? '')), $by));
            if (!isset($groups[$sig])) {
                $groups[$sig] = ['first' => $row, 'rows' => []];
            }
            $groups[$sig]['rows'][] = $row;
        }

        [$outCols] = self::select($columns, [], $by);
        $taken = array_fill_keys($by, true);
        foreach ($calcs as $i => $c) {
            $key = $c['key'];
            $n = 2;
            while (isset($taken[$key])) {
                $key = $c['key'] . '_' . $n++;
            }
            $taken[$key] = true;
            $calcs[$i]['key'] = $key;
            $outCols[] = [
                'key' => $key, 'label' => $c['label'],
                'type' => in_array($c['op'], ['compte', 'compte_distinct', 'somme', 'moyenne'], true) ? 'number' : 'text',
                'tech' => false,
            ];
        }

        $out = [];
        foreach ($groups as $g) {
            $line = [];
            foreach ($by as $k) {
                $line[$k] = (string)($g['first'][$k] ?? '');
            }
            foreach ($calcs as $c) {
                $line[$c['key']] = self::calc($c['op'], $c['col'], $g['rows']);
            }
            $out[] = $line;
        }
        return [$outCols, $out];
    }

    private static function calc(string $op, string $col, array $rows): string
    {
        if ($op === 'compte') {
            return (string)count($rows);
        }
        $values = [];
        foreach ($rows as $row) {
            $v = (string)($row[$col] ?? '');
            if ($v !== '') {
                $values[] = $v;
            }
        }
        if ($values === []) {
            return in_array($op, ['compte_distinct', 'somme'], true) ? '0' : '';
        }
        switch ($op) {
            case 'compte_distinct':
                return (string)count(array_unique(array_map([self::class, 'fold'], $values)));
            case 'somme':
            case 'moyenne':
                $nums = array_values(array_filter(array_map([self::class, 'number'], $values), static fn($n): bool => $n !== null));
                if ($nums === []) {
                    return '';
                }
                $total = array_sum($nums);
                return self::formatNumber($op === 'somme' ? $total : $total / count($nums));
            case 'min':
            case 'max':
                $best = $values[0];
                foreach ($values as $v) {
                    $cmp = self::compare($v, $best);
                    if (($op === 'min' && $cmp < 0) || ($op === 'max' && $cmp > 0)) {
                        $best = $v;
                    }
                }
                return $best;
            case 'premier':
                return $values[0];
            case 'dernier':
                return $values[count($values) - 1];
            case 'liste':
                $distinct = [];
                foreach ($values as $v) {
                    $distinct[self::fold($v)] ??= $v;
                }
                $shown = array_slice(array_values($distinct), 0, self::LIST_VALUES);
                $more  = count($distinct) - count($shown);
                return self::cell(implode(' ; ', $shown) . ($more > 0 ? ' ; (+' . $more . ')' : ''));
        }
        return '';
    }

    private static function formatNumber(float $n): string
    {
        if (floor($n) === $n && abs($n) < 1e15) {
            return (string)(int)$n;
        }
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    // ==================================================================
    // Croiser deux tables
    // ==================================================================

    /**
     * Clés de rapprochement d'une cellule. Une cellule peut en porter
     * plusieurs (« a@x.fr, b@y.fr » : les destinataires d'un email).
     *
     * @return list<string>
     */
    public static function matchKeys(string $value, string $mode): array
    {
        return match ($mode) {
            'email'   => self::emails($value),
            'domaine' => self::domains($value),
            default   => ($k = self::fold($value)) !== '' ? [$k] : [],
        };
    }

    /** Le plus court des deux textes (3 caractères au moins) figure-t-il, en MOTS ENTIERS, dans l'autre ? */
    public static function containsWords(string $a, string $b): bool
    {
        [$short, $long] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];
        return strlen($short) >= 3 && str_contains(' ' . $long . ' ', ' ' . $short . ' ');
    }

    /**
     * Croise deux tables sur une clé.
     *
     * `$keep` : `correspondances` (lignes de gauche qui ont une correspondance,
     * avec les colonnes de droite), `tout_gauche` (toutes les lignes de gauche,
     * colonnes de droite vides sans correspondance), `sans_correspondance`
     * (lignes de gauche que rien ne rapproche — « qui manque »).
     * `$perLeft` : une ligne par ligne de gauche (première correspondance +
     * leur nombre) au lieu d'une ligne par paire.
     *
     * @return array{columns: list<array>, rows: list<array<string,string>>, stats: array<string,int>, truncated: bool}
     */
    public static function join(
        array $leftCols,
        array $left,
        array $rightCols,
        array $right,
        string $leftKey,
        string $rightKey,
        string $mode,
        string $keep,
        bool $perLeft,
        string $rightName,
        string $rightTitle,
        int $maxRows = self::MAX_ROWS
    ): array {
        $rightKeys = [];
        $index     = [];
        foreach (array_values($right) as $i => $row) {
            $keys = self::matchKeys((string)($row[$rightKey] ?? ''), $mode);
            $rightKeys[$i] = $keys;
            if ($mode !== 'contient') {
                foreach ($keys as $k) {
                    $index[$k][] = $i;
                }
            }
        }
        $right = array_values($right);

        // Colonnes du résultat : celles de gauche, puis celles de droite
        // (renommées si une clé existe déjà à gauche).
        $columns = array_values($leftCols);
        $map     = [];
        if ($keep !== 'sans_correspondance') {
            $taken = array_fill_keys(self::keys($leftCols), true);
            if ($perLeft) {
                $countKey = 'nb_correspondances';
                while (isset($taken[$countKey])) {
                    $countKey .= '_2';
                }
                $taken[$countKey] = true;
                $columns[] = ['key' => $countKey, 'label' => 'Nombre de correspondances', 'type' => 'number', 'tech' => false];
            }
            foreach ($rightCols as $c) {
                if (count($columns) >= self::MAX_COLUMNS) {
                    break;
                }
                $key = (string)$c['key'];
                $new = $key;
                if (isset($taken[$new])) {
                    $new = substr($rightName . '_' . $key, 0, self::NAME_MAX);
                    $n = 2;
                    while (isset($taken[$new])) {
                        $new = substr($rightName . '_' . $key, 0, self::NAME_MAX - 3) . '_' . $n++;
                    }
                }
                $taken[$new] = true;
                $map[$key] = $new;
                $columns[] = [
                    'key' => $new,
                    'label' => $new === $key ? (string)$c['label'] : mb_substr((string)$c['label'] . ' (' . $rightTitle . ')', 0, self::LABEL_MAX),
                    'type' => (string)($c['type'] ?? 'text'), 'tech' => !empty($c['tech']),
                ];
            }
        }
        $emptyRight = array_fill_keys(array_values($map), '');

        $rows = [];
        $with = 0;
        $without = 0;
        $pairs = 0;
        $matchedRight = [];
        $truncated = false;
        $push = static function (array $line) use (&$rows, &$truncated, $maxRows): void {
            if (count($rows) >= $maxRows) {
                $truncated = true;
                return;
            }
            $rows[] = $line;
        };
        $mapRight = static function (array $row) use ($map): array {
            $out = [];
            foreach ($map as $from => $to) {
                $out[$to] = (string)($row[$from] ?? '');
            }
            return $out;
        };

        foreach ($left as $lrow) {
            $matches = [];
            foreach (self::matchKeys((string)($lrow[$leftKey] ?? ''), $mode) as $k) {
                if ($mode === 'contient') {
                    foreach ($rightKeys as $i => $keys) {
                        if (isset($keys[0]) && self::containsWords($k, $keys[0])) {
                            $matches[$i] = true;
                        }
                    }
                } else {
                    foreach ($index[$k] ?? [] as $i) {
                        $matches[$i] = true;
                    }
                }
            }
            $matches = array_keys($matches);
            sort($matches);

            if ($matches === []) {
                $without++;
                if ($keep === 'sans_correspondance') {
                    $push($lrow);
                } elseif ($keep === 'tout_gauche') {
                    $push($lrow + ($perLeft ? [$countKey => '0'] : []) + $emptyRight);
                }
                continue;
            }
            $with++;
            $pairs += count($matches);
            foreach ($matches as $i) {
                $matchedRight[$i] = true;
            }
            if ($keep === 'sans_correspondance') {
                continue;
            }
            if ($perLeft) {
                $push($lrow + [$countKey => (string)count($matches)] + $mapRight($right[$matches[0]]));
                continue;
            }
            foreach ($matches as $i) {
                $push($lrow + $mapRight($right[$i]));
            }
        }

        return [
            'columns'   => $columns,
            'rows'      => $rows,
            'truncated' => $truncated,
            'stats'     => [
                'lignes_gauche'              => count($left),
                'lignes_droite'              => count($right),
                'gauche_avec_correspondance' => $with,
                'gauche_sans_correspondance' => $without,
                'droite_sans_correspondance' => count($right) - count($matchedRight),
                'paires'                     => $pairs,
            ],
        ];
    }

    // ==================================================================
    // Rendu au modèle, export
    // ==================================================================

    /**
     * Lignes rendues AU MODÈLE : cellules coupées, cellules vides omises
     * (elles ne lui apprennent rien et se paient à chaque aller-retour).
     *
     * @return list<array<string, string>>
     */
    public static function forModel(array $rows, int $cellMax = self::PREVIEW_CELL): array
    {
        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $k => $v) {
                $v = (string)$v;
                if ($v === '') {
                    continue;
                }
                $line[(string)$k] = mb_strlen($v) > $cellMax ? mb_substr($v, 0, $cellMax - 1) . '…' : $v;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Empreinte de l'ÉTAT d'une table (date de dernière écriture, nombre de
     * lignes, origine, colonnes) : une opération de fond n'écrit dans une
     * table que si elle est telle qu'elle l'a laissée — remplacée ou
     * transformée entre-temps par un autre message, ses rangs ne désignent
     * plus les mêmes lignes (cf. WorkTableStore::patch). Pure.
     */
    public static function stateStamp(array $table): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        return sha1((string)($table['updated_at'] ?? '') . '|' . (int)($table['rows'] ?? 0) . '|'
            . json_encode($table['origin'] ?? [], $flags) . '|' . json_encode(self::keys((array)($table['columns'] ?? [])), $flags));
    }

    /** Fiche d'une table pour le modèle : nom, titre, lignes, colonnes. */
    public static function describe(array $table): array
    {
        return [
            'table'    => (string)$table['name'],
            'titre'    => (string)$table['title'],
            'lignes'   => (int)$table['rows'],
            'colonnes' => self::keys((array)$table['columns']),
        ];
    }

    /**
     * La table en CSV (cellules toutes entre guillemets, en-tête = libellés).
     * Les colonnes techniques (identifiants) n'y figurent que sur demande.
     */
    public static function csv(array $columns, array $rows, string $delimiter = ';', string $eol = "\n", bool $withTech = false): string
    {
        $cols = array_values(array_filter($columns, static fn(array $c): bool => $withTech || empty($c['tech'])));
        if ($cols === []) {
            $cols = array_values($columns);
        }
        $quote = static function (string $v): string {
            if (class_exists('SpreadsheetFormula', false)) {
                $v = SpreadsheetFormula::neutralize($v);
            }
            return '"' . str_replace('"', '""', $v) . '"';
        };
        $lines = [implode($delimiter, array_map(static fn(array $c): string => $quote((string)$c['label']), $cols))];
        foreach ($rows as $row) {
            $lines[] = implode($delimiter, array_map(static fn(array $c): string => $quote((string)($row[$c['key']] ?? '')), $cols));
        }
        return implode($eol, $lines) . $eol;
    }

    // ==================================================================
    // Le message demande-t-il un travail sur une table ?
    // ==================================================================

    /**
     * Le message parle-t-il d'un travail de table (croiser, dédoublonner,
     * filtrer, compter, exporter…) ou nomme-t-il une table de la conversation ?
     * Sert à exposer les outils quand rien d'autre n'engage la boucle (un
     * classeur joint, des tables déjà construites) — jamais sur un message
     * ordinaire. Pure, FR et EN.
     *
     * @param list<string> $names noms des tables de la conversation
     */
    public static function looksLikeTableRequest(string $content, array $names = []): bool
    {
        $text = ' ' . self::fold($content) . ' ';
        if (trim($text) === '') {
            return false;
        }
        foreach ($names as $name) {
            $n = self::fold(str_replace('_', ' ', (string)$name));
            if (strlen($n) >= 3 && str_contains($text, ' ' . $n . ' ')) {
                return true;
            }
        }
        return preg_match('/ (?:crois\w*|recoup\w*|rapproch\w*|dedoublonn\w*|doublons?|filtr\w*|tri(?:e|er|es|ez)|regroup\w*|fusionn\w*'
            . '|compar\w*|tables?|tableaux?|listes?|lignes?|colonnes?|export\w*|excel|csv|xlsx|classeurs?|combien|comptes?|compter|manqu\w*'
            . '|cross ?referenc\w*|match\w*|merg\w*|dedup\w*|duplicates?|filter\w*|sort\w*|group\w*|lists?|rows?|columns?|spreadsheets?|count\w*|missing) /u', $text) === 1;
    }
}
