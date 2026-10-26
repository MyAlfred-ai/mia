<?php

declare(strict_types=1);

/**
 * Valeurs des champs d'une Fiche et règles d'une condition — pur.
 *
 * Un modèle écrit « 180 000 € HT », « 30/10/2026 » ou « Oui » ; une règle
 * compare des nombres, des dates et des oui/non. Tout passe par `coerce()`,
 * des deux côtés : la valeur rangée dans la Fiche ET la valeur saisie dans la
 * règle. Une règle se calcule ainsi sans IA, avec la même réponse à chaque
 * fois — c'est sa raison d'être.
 */
final class WorkflowValues
{
    private const MONTHS = [
        'janvier' => 1, 'fevrier' => 2, 'février' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'aout' => 8, 'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11,
        'decembre' => 12, 'décembre' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
        'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /**
     * Valeur brute → valeur typée, ou null si elle ne se lit pas comme le type.
     * number → int|float ; date → 'AAAA-MM-JJ' ; yesno → bool ; list → list<string> ;
     * email → adresse valide ; text → chaîne.
     */
    public static function coerce(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'number' => self::number($value),
            'date'   => self::date($value),
            'yesno'  => self::yesno($value),
            'list'   => self::listOf($value),
            'email'  => self::email($value),
            default  => self::text($value),
        };
    }

    public static function number(mixed $v): int|float|null
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', '€', '$', '£', '%'], '', trim($v));
        $s = (string)preg_replace('/[^0-9,.\-]/u', '', $s);
        if ($s === '' || $s === '-' || !preg_match('/\d/', $s)) {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot   = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // Le dernier séparateur est la décimale.
            $s = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $s))
                : str_replace(',', '', $s);
        } elseif ($lastComma !== false) {
            // « 1,5 » = décimale ; « 1,234,567 » = milliers.
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($lastDot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }
        if (!is_numeric($s)) {
            return null;
        }
        $f = (float)$s;
        return (floor($f) === $f && abs($f) < PHP_INT_MAX) ? (int)$f : $f;
    }

    public static function date(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $s = mb_strtolower(trim($v));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return self::ymd((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $s, $m)) {
            $y = (int)$m[3];
            return self::ymd($y < 100 ? 2000 + $y : $y, (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/^(?:\p{L}+\s+)?(\d{1,2})(?:er)?\s+(\p{L}+)\s+(\d{4})$/u', $s, $m) && isset(self::MONTHS[$m[2]])) {
            return self::ymd((int)$m[3], self::MONTHS[$m[2]], (int)$m[1]);
        }
        return null;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    public static function yesno(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = mb_strtolower(trim($v, " \t\n.!"));
        return match ($s) {
            'oui', 'yes', 'vrai', 'true', 'o', 'y', '1' => true,
            'non', 'no', 'faux', 'false', 'n', '0'      => false,
            default                                     => null,
        };
    }

    /** @return list<string>|null */
    public static function listOf(mixed $v): ?array
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $item) {
                $t = self::text(is_array($item) ? implode(' — ', array_map('strval', array_filter($item, 'is_scalar'))) : $item);
                if ($t !== null && $t !== '') {
                    $out[] = $t;
                }
            }
            return $out;
        }
        if (!is_string($v)) {
            return null;
        }
        $parts = preg_split('/\n|;/', $v) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string)preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', $p));
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function email(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        if (preg_match('/<([^>]+)>/', $s, $m)) {
            $s = $m[1];
        }
        $s = (string)preg_replace('/^mailto:/i', '', $s);
        return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : null;
    }

    public static function text(mixed $v): ?string
    {
        if (is_bool($v)) {
            return $v ? 'oui' : 'non';
        }
        if (!is_scalar($v)) {
            return null;
        }
        return trim((string)$v);
    }

    /** Valeur → texte lisible (affichage, prompt). */
    public static function display(mixed $value, string $type): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }
        return match ($type) {
            'number' => is_float($value) ? rtrim(rtrim(number_format($value, 2, ',', "\u{202F}"), '0'), ',') : number_format((int)$value, 0, ',', "\u{202F}"),
            'date'   => (string)preg_replace('/^(\d{4})-(\d{2})-(\d{2})$/', '$3/$2/$1', (string)$value),
            'yesno'  => $value ? t('oui') : t('non'),
            'list'   => implode(' ; ', (array)$value),
            default  => (string)$value,
        };
    }

    // ==================================================================
    // Règles
    // ==================================================================

    /** Opérateurs proposés pour un type de champ. */
    public static function opsFor(string $type): array
    {
        return match ($type) {
            'number', 'date' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'empty', 'notempty'],
            'yesno'          => ['yes', 'no', 'empty'],
            'list'           => ['contains', 'empty', 'notempty'],
            default          => ['eq', 'ne', 'contains', 'empty', 'notempty'],
        };
    }

    /** Libellé d'un opérateur, pour l'écran et l'explication d'une décision. */
    public static function opLabel(string $op): string
    {
        return match ($op) {
            'eq'       => t('est égal à'),
            'ne'       => t('est différent de'),
            'gt'       => t('est supérieur à'),
            'gte'      => t('est supérieur ou égal à'),
            'lt'       => t('est inférieur à'),
            'lte'      => t('est inférieur ou égal à'),
            'contains' => t('contient'),
            'empty'    => t('est vide'),
            'notempty' => t('est renseigné'),
            'yes'      => t('est « oui »'),
            'no'       => t('est « non »'),
            default    => $op,
        };
    }

    /**
     * Évalue une règle sur les pièces. `$pieces` : clé → pièce ({form, fields}) ;
     * `$contents` : clé → contenu ({fields: clé → valeur}). Un champ absent
     * vaut vide. Rend le verdict et une phrase par condition, pour la trace.
     *
     * @return array{result: bool, lines: list<string>}
     */
    public static function evaluate(array $rule, array $pieces, array $contents): array
    {
        $results = [];
        $lines = [];
        foreach ((array)($rule['items'] ?? []) as $it) {
            $piece = $pieces[$it['piece']] ?? null;
            $fieldDef = null;
            foreach ((array)($piece['fields'] ?? []) as $f) {
                if ($f['key'] === $it['field']) {
                    $fieldDef = $f;
                }
            }
            $type  = (string)($fieldDef['type'] ?? 'text');
            $value = $contents[$it['piece']]['fields'][$it['field']] ?? null;
            $value = self::coerce($value, $type);
            $ok = self::compare($value, (string)$it['op'], (string)($it['value'] ?? ''), $type);
            $results[] = $ok;
            $lines[] = t('{champ} = {valeur} → {op}{cible} : {verdict}', [
                'champ'   => (string)($fieldDef['label'] ?? $it['field']),
                'valeur'  => self::display($value, $type),
                'op'      => self::opLabel((string)$it['op']),
                'cible'   => in_array($it['op'], ['empty', 'notempty', 'yes', 'no'], true) ? '' : ' ' . (string)$it['value'],
                'verdict' => $ok ? t('oui') : t('non'),
            ]);
        }
        if ($results === []) {
            return ['result' => false, 'lines' => []];
        }
        $result = ($rule['join'] ?? 'all') === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
        return ['result' => $result, 'lines' => $lines];
    }

    public static function compare(mixed $value, string $op, string $target, string $type): bool
    {
        $isEmpty = $value === null || $value === '' || $value === [];
        if ($op === 'empty') {
            return $isEmpty;
        }
        if ($op === 'notempty') {
            return !$isEmpty;
        }
        if ($op === 'yes' || $op === 'no') {
            return $value === ($op === 'yes');
        }
        if ($isEmpty) {
            return false;
        }
        if ($op === 'contains') {
            $hay = is_array($value) ? implode("\n", $value) : (string)$value;
            return $target !== '' && mb_stripos(self::fold($hay), self::fold($target)) !== false;
        }
        if ($type === 'number' || $type === 'date') {
            $b = self::coerce($target, $type);
            if ($b === null) {
                return false;
            }
            $cmp = $value <=> $b;
        } else {
            $cmp = strcmp(self::fold((string)$value), self::fold($target)) === 0 ? 0 : 1;
            if (!in_array($op, ['eq', 'ne'], true)) {
                return false;
            }
        }
        return match ($op) {
            'eq'  => $cmp === 0,
            'ne'  => $cmp !== 0,
            'gt'  => $cmp > 0,
            'gte' => $cmp >= 0,
            'lt'  => $cmp < 0,
            'lte' => $cmp <= 0,
            default => false,
        };
    }

    /** Minuscules sans accents, pour comparer des textes. */
    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y']);
    }
}
