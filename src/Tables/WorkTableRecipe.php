<?php

declare(strict_types=1);

/**
 * La RECETTE d'une vue : ce qu'il faut faire aux lignes d'une table
 * permanente pour obtenir une liste — filtrer, dédoublonner, trier, garder des
 * colonnes, limiter. Pure.
 *
 * LE PROBLÈME RÉSOLU. Trier une table permanente, en garder les clients de
 * Lyon ou en exporter une sélection faisait naître une COPIE dans la
 * conversation (« Clients tries ca desc », « Clients a relancer ») : figée au
 * moment du calcul, nommée comme la vraie table, ouverte par défaut à sa
 * place. La personne ne savait plus laquelle était la sienne, et les copies
 * gardaient les clients supprimés depuis.
 *
 * Une vue ne copie rien : elle garde la recette, et les lignes sont
 * recalculées à chaque lecture — elle suit la table, toujours à jour. Une
 * recette est une SUITE d'étapes (une vue d'une vue ajoute une étape), chaque
 * étape étant ce que fait transformer_table sans regroupement :
 *   {f: [{col, test, value}], any: bool, d: [clés], s: [{col, desc}], c: [clés], m: int}
 *
 * Chaque ligne d'une vue garde sa position dans la table d'origine
 * (LINE_KEY, 1 pour la première) : c'est elle qui permet d'écrire dans la
 * table permanente ce qui a été calculé sur la vue (une colonne enrichie, le
 * statut d'une action), sans clé à deviner.
 */
final class WorkTableRecipe
{
    /** Position d'une ligne dans la table d'origine (colonne technique, jamais montrée). */
    public const LINE_KEY = 'mia_ligne';
    /** Étapes d'une recette, au plus. */
    public const MAX_STEPS = 4;
    /** Conditions, colonnes, tris par étape, au plus. */
    private const MAX_ITEMS = 12;
    /** Caractères d'une valeur de filtre. */
    private const VALUE_MAX = 300;
    /** Une recette encodée (adresse d'un lien) ne dépasse pas cette longueur. */
    public const ENCODED_MAX = 1800;

    /**
     * Une étape, telle que transformer_table l'a vérifiée. Pure.
     *
     * @param list<array{col:string,test:string,value:string}> $conds
     * @param list<string>                                     $dedupe
     * @param list<array{col:string,desc:bool}>                $sort
     * @param list<string>                                     $kept
     */
    public static function step(array $conds, bool $any, array $dedupe, array $sort, array $kept, int $max): array
    {
        return [
            'f'   => array_values($conds),
            'any' => $any && count($conds) > 1,
            'd'   => array_values($dedupe),
            's'   => array_values($sort),
            'c'   => array_values($kept),
            'm'   => max(0, $max),
        ];
    }

    /** Une étape qui ne change rien (ni filtre, ni tri, ni colonnes, ni limite) ? Pure. */
    public static function isIdentity(array $step): bool
    {
        return ($step['f'] ?? []) === [] && ($step['d'] ?? []) === [] && ($step['s'] ?? []) === []
            && ($step['c'] ?? []) === [] && (int)($step['m'] ?? 0) === 0;
    }

    /**
     * Les lignes de la vue. Chaque ligne garde sa position d'origine
     * (LINE_KEY) ; les colonnes rendues ne la portent pas. Pure.
     *
     * @param list<array>                $columns colonnes de la table d'origine
     * @param list<array<string,string>> $rows    ses lignes, dans l'ordre
     * @param list<array>                $steps
     * @return array{columns: list<array>, rows: list<array<string,string>>}
     */
    public static function apply(array $columns, array $rows, array $steps): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $out[] = $row + [self::LINE_KEY => (string)($i + 1)];
        }
        foreach ($steps as $step) {
            $out = WorkTableOps::filter($out, (array)($step['f'] ?? []), !empty($step['any']));
            if (($step['d'] ?? []) !== []) {
                $out = WorkTableOps::dedupe($out, (array)$step['d']);
            }
            $out = WorkTableOps::sort($out, (array)($step['s'] ?? []));
            if (($step['c'] ?? []) !== []) {
                $lines = array_map(static fn(array $r): string => (string)($r[self::LINE_KEY] ?? ''), $out);
                [$columns, $out] = WorkTableOps::select($columns, $out, (array)$step['c']);
                foreach ($out as $i => $r) {
                    $out[$i][self::LINE_KEY] = $lines[$i];
                }
            }
            if ((int)($step['m'] ?? 0) > 0) {
                $out = array_slice($out, 0, (int)$step['m']);
            }
        }
        return ['columns' => array_values($columns), 'rows' => array_values($out)];
    }

    /** Une ligne sans sa position d'origine (ce que lisent le modèle et la personne). Pure. */
    public static function strip(array $rows): array
    {
        return array_map(static function (array $r): array {
            unset($r[self::LINE_KEY]);
            return $r;
        }, $rows);
    }

    /**
     * Une recette reçue de l'extérieur (adresse d'un lien, état d'une table)
     * revérifiée contre les colonnes de la table : tests connus, colonnes
     * existantes à chaque étape, bornes. Pure.
     *
     * @return list<array>
     * @throws \InvalidArgumentException la recette ne s'applique plus (colonne disparue) ou est illisible
     */
    public static function normalize(array $columns, mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_STEPS) {
            throw new \InvalidArgumentException(t('Cette vue est illisible.'));
        }
        $steps = [];
        foreach ($raw as $s) {
            if (!is_array($s)) {
                throw new \InvalidArgumentException(t('Cette vue est illisible.'));
            }
            $keys = WorkTableOps::keys($columns);
            $need = static function (mixed $k) use ($keys, $columns): string {
                $k = is_scalar($k) ? (string)$k : '';
                if (!in_array($k, $keys, true)) {
                    $label = $k;
                    throw new \InvalidArgumentException(t('La colonne « {colonne} » n’existe plus dans la table : cette vue ne s’applique plus.', ['colonne' => $label]));
                }
                return $k;
            };
            $conds = [];
            foreach (array_slice((array)($s['f'] ?? []), 0, self::MAX_ITEMS) as $c) {
                $test = is_scalar($c['test'] ?? null) ? (string)$c['test'] : '';
                if (!in_array($test, WorkTableOps::TESTS, true)) {
                    throw new \InvalidArgumentException(t('Cette vue est illisible.'));
                }
                $conds[] = ['col' => $need($c['col'] ?? ''), 'test' => $test, 'value' => mb_substr(is_scalar($c['value'] ?? null) ? (string)$c['value'] : '', 0, self::VALUE_MAX)];
            }
            $dedupe = array_map($need, array_slice((array)($s['d'] ?? []), 0, self::MAX_ITEMS));
            $sort   = [];
            foreach (array_slice((array)($s['s'] ?? []), 0, self::MAX_ITEMS) as $o) {
                $sort[] = ['col' => $need(is_array($o) ? ($o['col'] ?? '') : ''), 'desc' => is_array($o) && !empty($o['desc'])];
            }
            $kept = array_values(array_unique(array_map($need, array_slice((array)($s['c'] ?? []), 0, WorkTableOps::MAX_COLUMNS))));
            $step = self::step($conds, !empty($s['any']), $dedupe, $sort, $kept, is_numeric($s['m'] ?? null) ? min((int)$s['m'], WorkTableOps::MAX_ROWS * 4) : 0);
            $steps[] = $step;
            // Les colonnes de l'étape suivante sont celles que celle-ci garde.
            if ($kept !== []) {
                $columns = array_values(array_filter($columns, static fn(array $c): bool => in_array((string)$c['key'], $kept, true)));
                usort($columns, static fn(array $a, array $b): int => array_search($a['key'], $kept, true) <=> array_search($b['key'], $kept, true));
            }
        }
        return $steps;
    }

    /** Recette compacte, pour une adresse (base64url d'un JSON). '' si elle serait trop longue. Pure. */
    public static function encode(array $steps): string
    {
        $json = json_encode(array_values($steps), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return '';
        }
        $code = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return strlen($code) <= self::ENCODED_MAX ? $code : '';
    }

    /** Recette d'une adresse, ou null (illisible) — à repasser par normalize(). Pure. */
    public static function decode(mixed $code): ?array
    {
        if (!is_string($code) || $code === '' || strlen($code) > self::ENCODED_MAX || preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            return null;
        }
        $json = base64_decode(strtr($code, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) && array_is_list($data) ? $data : null;
    }

    /**
     * Ce que fait la recette, en mots, pour la personne (« Ville = Lyon »,
     * « triée par CA annuel, décroissant »). Pure.
     *
     * @return list<string>
     */
    public static function describe(array $columns, array $steps): array
    {
        $label = static function (string $key) use ($columns): string {
            foreach ($columns as $c) {
                if ((string)$c['key'] === $key) {
                    return (string)$c['label'];
                }
            }
            return $key;
        };
        $out = [];
        foreach ($steps as $s) {
            $conds = [];
            foreach ((array)($s['f'] ?? []) as $c) {
                $conds[] = self::condition($label((string)$c['col']), (string)$c['test'], (string)$c['value']);
            }
            if ($conds !== []) {
                $out[] = implode(!empty($s['any']) ? ' ' . t('ou') . ' ' : ' · ', $conds);
            }
            if (($s['d'] ?? []) !== []) {
                $out[] = t('sans doublon sur {colonnes}', ['colonnes' => implode(', ', array_map($label, (array)$s['d']))]);
            }
            foreach ((array)($s['s'] ?? []) as $o) {
                $out[] = !empty($o['desc'])
                    ? t('triée par {colonne}, décroissant', ['colonne' => $label((string)$o['col'])])
                    : t('triée par {colonne}', ['colonne' => $label((string)$o['col'])]);
            }
            if (($s['c'] ?? []) !== []) {
                $out[] = I18n::plural(count((array)$s['c']), '{n} colonne', '{n} colonnes');
            }
            if ((int)($s['m'] ?? 0) > 0) {
                $out[] = I18n::plural((int)$s['m'], 'la première ligne', 'les {n} premières lignes');
            }
        }
        return $out;
    }

    /** Une condition en mots. Pure. */
    public static function condition(string $column, string $test, string $value): string
    {
        $v = mb_strimwidth($value, 0, 40, '…');
        return match ($test) {
            'egal'            => $column . ' = ' . $v,
            'different'       => $column . ' ≠ ' . $v,
            'contient'        => t('{colonne} contient « {valeur} »', ['colonne' => $column, 'valeur' => $v]),
            'ne_contient_pas' => t('{colonne} ne contient pas « {valeur} »', ['colonne' => $column, 'valeur' => $v]),
            'commence_par'    => t('{colonne} commence par « {valeur} »', ['colonne' => $column, 'valeur' => $v]),
            'vide'            => t('{colonne} vide', ['colonne' => $column]),
            'non_vide'        => t('{colonne} renseignée', ['colonne' => $column]),
            'superieur'       => $column . ' > ' . $v,
            'au_moins'        => $column . ' ≥ ' . $v,
            'inferieur'       => $column . ' < ' . $v,
            'au_plus'         => $column . ' ≤ ' . $v,
            'parmi'           => t('{colonne} parmi {valeurs}', ['colonne' => $column, 'valeur' => $v, 'valeurs' => str_replace(' | ', ', ', $v)]),
            default           => $column,
        };
    }

    /**
     * Titre d'une vue que le modèle n'a pas nommée : la table d'origine et la
     * première chose que fait la recette (« Clients 2026 — Ville = Lyon »). Pure.
     */
    public static function title(string $source, array $columns, array $steps): string
    {
        $what = self::describe($columns, $steps);
        $title = $what !== [] ? $source . ' — ' . $what[0] : $source;
        return mb_substr($title, 0, WorkTableOps::TITLE_MAX);
    }
}
