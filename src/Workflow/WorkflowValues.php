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
        if ($type !== 'number' && $type !== 'date' && in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
            // « Supérieur à 0 » sur un champ resté texte (règle écrite par MIA,
            // colonne d'un tableau non typée) : comparé comme une date ou un
            // nombre si les DEUX côtés en sont — sinon la règle serait
            // toujours fausse, sans un mot.
            foreach (['date', 'number'] as $as) {
                $a = self::coerce(is_array($value) ? null : (string)$value, $as);
                $b = self::coerce($target, $as);
                if ($a !== null && $b !== null) {
                    return self::compare($a, $op, $target, $as);
                }
            }
            return false;
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
    /**
     * « « Oui », « Non » et « Peut-être » » : les valeurs citées, la dernière
     * jointe par « et » — « ne peut pas valoir à la fois « Haute », « Basse » »
     * se lisait comme une énumération coupée (recette du 2026-11-09).
     *
     * @param list<string> $values
     */
    private static function citedValues(array $values): string
    {
        if (count($values) < 2) {
            return I18n::listeCitee($values);
        }
        $last = array_pop($values);
        return t('{list} et {last}', ['list' => I18n::listeCitee($values), 'last' => I18n::listeCitee([$last])]);
    }

    /**
     * Ce qu'une règle ne peut pas faire, dit AVANT de l'exécuter. Pure.
     *
     * Le défaut payé (retour du 2026-11-04) : trois conditions « Départ est
     * égal à Oui », « … à Non », « … à Peut-être », jointes par « Toutes les
     * conditions » — la personne pensait décrire trois réponses possibles, la
     * règle n'était jamais vraie et la voie « Si non » partait à chaque fois,
     * « Oui » saisi compris. Une condition ne répond qu'oui ou non ; ses
     * conditions se COMBINENT en une réponse.
     *
     * Rend une liste de constats {level: block|warn, text} :
     * - « toutes » : deux valeurs différentes pour un même champ, une valeur et
     *   son contraire, « oui » et « non », « vide » et autre chose → jamais vraie ;
     * - « au moins une » : une valeur et son contraire, « vide » et
     *   « renseigné » → toujours vraie ;
     * - un champ Choix comparé à une valeur qui n'est pas une de ses options.
     *
     * @param array $pieces clé → pièce ({fields: [{key, label, type, options?}]})
     * @param string $target rule (condition) | filter (filtre d'une boucle) | until (sortie d'une boucle)
     * @return list<array{level: string, text: string}>
     */
    public static function ruleConflicts(array $rule, array $pieces, string $target = 'rule'): array
    {
        $never = match ($target) {
            'filter' => t('aucun élément ne serait gardé'),
            'until'  => t('la boucle n’en sortirait jamais d’elle-même'),
            default  => t('la voie « Si oui » ne serait jamais prise'),
        };
        $always = match ($target) {
            'filter' => t('tous les éléments seront gardés'),
            'until'  => t('la boucle s’arrêtera dès le premier tour'),
            default  => t('la voie « Si non » ne sera jamais prise'),
        };
        $out = [];
        $groups = [];
        foreach ((array)($rule['items'] ?? []) as $it) {
            $piece = $pieces[(string)($it['piece'] ?? '')] ?? null;
            $def = null;
            foreach ((array)($piece['fields'] ?? []) as $f) {
                if (($f['key'] ?? null) === ($it['field'] ?? null)) {
                    $def = $f;
                }
            }
            if ($def === null) {
                continue;
            }
            $label = (string)($def['label'] ?? '') !== '' ? (string)$def['label'] : (string)$it['field'];
            $op = (string)($it['op'] ?? 'eq');
            $value = trim((string)($it['value'] ?? ''));
            $options = array_values(array_filter(array_map('strval', (array)($def['options'] ?? [])), static fn(string $o): bool => $o !== ''));
            if ($options !== [] && in_array($op, ['eq', 'ne'], true) && $value !== '') {
                $known = array_map(static fn(string $o): string => self::fold($o), $options);
                if (!in_array(self::fold($value), $known, true)) {
                    $out[] = ['level' => 'warn', 'text' => t('« {valeur} » n’est pas un des choix de « {champ} » ({choix}) : cette condition {verdict}.', [
                        'valeur' => $value, 'champ' => $label, 'choix' => implode(', ', $options),
                        'verdict' => $op === 'eq' ? t('ne sera jamais vraie') : t('sera toujours vraie'),
                    ])];
                }
            }
            $k = (string)$it['piece'] . '|' . (string)$it['field'];
            $groups[$k]['label'] = $label;
            $groups[$k]['items'][] = ['op' => $op, 'value' => self::fold($value), 'raw' => $value];
        }
        $any = ($rule['join'] ?? 'all') === 'any';
        foreach ($groups as $g) {
            if (count($g['items']) < 2) {
                continue;
            }
            $eq = [];
            $ne = [];
            $ops = [];
            foreach ($g['items'] as $i) {
                $ops[$i['op']] = true;
                if ($i['op'] === 'eq' && $i['value'] !== '') {
                    $eq[$i['value']] = $i['raw'];
                }
                if ($i['op'] === 'ne' && $i['value'] !== '') {
                    $ne[$i['value']] = $i['raw'];
                }
            }
            $both = array_intersect_key($eq, $ne);
            if ($any) {
                if ($both !== [] || (isset($ops['empty']) && isset($ops['notempty']))) {
                    $out[] = ['level' => 'warn', 'text' => t('Avec « Au moins une », ces conditions sur « {champ} » sont toujours vraies : {suite}.', ['champ' => $g['label'], 'suite' => $always])];
                }
                continue;
            }
            if (count($eq) > 1) {
                $out[] = ['level' => 'block', 'text' => ($target === 'rule'
                    ? t('« {champ} » ne peut pas valoir à la fois {valeurs} : avec « Toutes les conditions », {suite}. Une condition répond oui ou non : gardez la valeur qui mène à « Si oui » (les autres iront dans « Si non »), ou choisissez « Au moins une ».', [
                        'champ' => $g['label'], 'valeurs' => self::citedValues(array_values($eq)), 'suite' => $never,
                    ])
                    : t('« {champ} » ne peut pas valoir à la fois {valeurs} : avec « Toutes les conditions », {suite}. Choisissez « Au moins une ».', [
                        'champ' => $g['label'], 'valeurs' => self::citedValues(array_values($eq)), 'suite' => $never,
                    ]))];
                continue;
            }
            $contradicts = $both !== []
                || (isset($ops['yes']) && isset($ops['no']))
                || (isset($ops['empty']) && count($ops) > 1);
            if ($contradicts) {
                $out[] = ['level' => 'block', 'text' => t('Ces conditions sur « {champ} » se contredisent : avec « Toutes les conditions », {suite}.', ['champ' => $g['label'], 'suite' => $never])];
            }
        }
        return $out;
    }

    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y']);
    }
}
