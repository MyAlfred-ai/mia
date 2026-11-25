<?php

declare(strict_types=1);

/**
 * Ce que MIA écrit dans une table PERMANENTE — calcul PUR, sans base.
 *
 * Le modèle ne tient jamais les lignes de la table : il dit CE QU'IL FAUT
 * CHANGER, et plan() calcule le résultat ET ce qui change (lignes touchées,
 * ajoutées, supprimées, colonnes créées, exemples avant / après) — c'est ce
 * que la carte d'approbation montre, et ce qui est écrit après l'accord. Le
 * même calcul est rejoué à l'accord, sur la table du moment.
 *
 * Modes DIRECTS — la demande suffit, aucune table de travail n'est créée
 * (« passe les clients de Lyon en statut à relancer », « supprime les
 * doublons marqués », « ajoute ce client ») :
 *   - `definir` : des valeurs fixes (`definir`) pour les lignes que retiennent
 *     des `filtres` — sans filtre, toutes. Une colonne absente est créée.
 *   - `supprimer_lignes` : les lignes que retiennent des `filtres` (exigés).
 *   - `ajouter` avec `lignes` : quelques lignes données dans la demande.
 *   - `renommer_colonne`, `supprimer_colonne`.
 *
 * Modes qui LISENT une table de la conversation (`depuis`) — pour reporter
 * le résultat d'une collecte ou d'un croisement :
 *   - `mettre_a_jour` : les lignes de la table permanente dont la clé se
 *     retrouve dans `depuis` reçoivent les valeurs de ses colonnes désignées
 *     et / ou des valeurs fixes (`definir`). Les lignes de `depuis` sans
 *     correspondance sont ignorées, ou ajoutées (`ajouter_absents`).
 *   - `ajouter` : les lignes de `depuis` s'ajoutent en fin de table.
 *   - `remplacer` : le contenu de `depuis` remplace celui de la table.
 *
 * Les colonnes techniques d'une table de travail (identifiant d'un mail, d'un
 * fichier) ne sont jamais reportées. Un résultat au-delà du plafond lève
 * (KbTableTooLargeException) : rien n'est coupé.
 */
final class KbTableWrite
{
    public const MODE_UPDATE  = 'mettre_a_jour';
    public const MODE_APPEND  = 'ajouter';
    public const MODE_REPLACE = 'remplacer';
    /** Valeurs fixes pour des lignes filtrées — sans table de travail. */
    public const MODE_SET     = 'definir';
    public const MODE_DELETE  = 'supprimer_lignes';
    public const MODE_RENAME  = 'renommer_colonne';
    public const MODE_DROP    = 'supprimer_colonne';
    /** Les modes directs d'abord : ce sont eux qui répondent à « modifie ma table ». */
    public const MODES = [
        self::MODE_SET, self::MODE_APPEND, self::MODE_DELETE, self::MODE_RENAME, self::MODE_DROP,
        self::MODE_UPDATE, self::MODE_REPLACE,
    ];

    /**
     * Modes qui REPORTENT une table (une table de travail, la pièce d'une
     * livraison de workflow) : ce que propose une livraison dans une table,
     * qui écrit toujours d'après sa pièce.
     */
    public const SOURCE_MODES = [self::MODE_APPEND, self::MODE_UPDATE, self::MODE_REPLACE];

    /** Lignes données dans la demande (`ajouter` sans `depuis`), au plus. */
    public const INLINE_ROWS_MAX = 200;
    /** Lignes ajoutées ou supprimées montrées sur la carte, et colonnes de cet aperçu. */
    public const LINES_SHOWN = 8;
    public const LINES_COLUMNS = 5;

    /**
     * Ce mode lit-il une table de la conversation (`depuis`) ? `ajouter` en
     * lit une, sauf si la demande porte elle-même les lignes. Pure.
     */
    public static function usesSource(string $mode, array $args): bool
    {
        if ($mode === self::MODE_UPDATE || $mode === self::MODE_REPLACE) {
            return true;
        }
        return $mode === self::MODE_APPEND && self::rowsOf($args['lignes'] ?? null) === [];
    }

    /** Exemples avant / après montrés sur la carte. */
    public const SAMPLES = 8;

    /** Valeurs fixes au plus dans une écriture. */
    public const SET_MAX = 8;

    /**
     * Demande d'écriture vérifiée. Pure.
     *
     * @param list<array> $target  colonnes de la table permanente
     * @param list<array> $source  colonnes de la table de la conversation ([] pour un mode direct)
     * @param array       $args    {mode, cle?, cle_source?, colonnes?, definir?, ajouter_absents?, filtres?, condition?, lignes?, colonne?, nouveau_nom?}
     * @return array{mode:string, key:string, source_key:string, columns:list<string>, set:array<string,string>, add_missing:bool,
     *               filters:list<array{col:string,test:string,value:string}>, any:bool,
     *               inline:?array{labels:list<string>, rows:list<list<string>>}, column:string, new_label:string}
     * @throws \InvalidArgumentException message pour le modèle
     */
    public static function spec(array $target, array $source, array $args): array
    {
        $mode = WorkTableOps::slug(is_scalar($args['mode'] ?? null) ? (string)$args['mode'] : '');
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Paramètre `mode` : ' . implode(', ', self::MODES) . '.');
        }
        $direct = [
            'filters' => [], 'any' => false, 'inline' => null, 'column' => '', 'new_label' => '',
        ];
        $base = ['mode' => $mode, 'key' => '', 'source_key' => '', 'columns' => [], 'set' => [], 'add_missing' => false] + $direct;

        // ---- Colonnes : renommer, supprimer ----
        if ($mode === self::MODE_RENAME || $mode === self::MODE_DROP) {
            if (!is_scalar($args['colonne'] ?? null) || trim((string)$args['colonne']) === '') {
                throw new \InvalidArgumentException('Paramètre `colonne` : la colonne de la table permanente à '
                    . ($mode === self::MODE_RENAME ? 'renommer' : 'supprimer') . '.');
            }
            $base['column'] = WorkTableOps::column($target, $args['colonne']);
            if ($mode === self::MODE_DROP) {
                if (count($target) <= 1) {
                    throw new \InvalidArgumentException('Une table garde au moins une colonne : celle-ci ne peut pas être supprimée.');
                }
                return $base;
            }
            $label = is_scalar($args['nouveau_nom'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', (string)$args['nouveau_nom'])) : '';
            if ($label === '') {
                throw new \InvalidArgumentException('Paramètre `nouveau_nom` : le nouveau nom de la colonne.');
            }
            $label = mb_substr($label, 0, WorkTableOps::LABEL_MAX);
            foreach ($target as $c) {
                if ((string)$c['key'] !== $base['column'] && WorkTableOps::fold((string)$c['label']) === WorkTableOps::fold($label)) {
                    throw new \InvalidArgumentException('La table a déjà une colonne « ' . $c['label'] . ' » : choisis un autre nom.');
                }
            }
            $base['new_label'] = $label;
            return $base;
        }

        $set = [];
        foreach (self::assocOf($args['definir'] ?? []) as $label => $value) {
            $label = trim((string)$label);
            if ($label === '' || count($set) >= self::SET_MAX) {
                continue;
            }
            $set[mb_substr($label, 0, WorkTableOps::LABEL_MAX)] = WorkTableOps::cell($value);
        }

        // ---- Lignes filtrées : définir des valeurs, supprimer ----
        if ($mode === self::MODE_SET || $mode === self::MODE_DELETE) {
            $base['filters'] = WorkTableOps::conditions($target, $args['filtres'] ?? []);
            $base['any']     = WorkTableOps::slug(is_scalar($args['condition'] ?? null) ? (string)$args['condition'] : '') === 'au_moins_une';
            if ($mode === self::MODE_DELETE) {
                if ($base['filters'] === []) {
                    throw new \InvalidArgumentException('Paramètre `filtres` : dis quelles lignes supprimer (colonne, test, valeur). '
                        . 'Vider toute la table se fait dans sa fiche, pas ici.');
                }
                return $base;
            }
            if ($set === []) {
                throw new \InvalidArgumentException('Paramètre `definir` : les valeurs à écrire, en JSON — {"Statut": "à relancer"}. '
                    . 'Une colonne absente est créée ; `filtres` dit sur quelles lignes (sans filtre : toutes).');
            }
            $base['set'] = $set;
            return $base;
        }

        // ---- Ajouter des lignes données dans la demande ----
        $inlineRows = $mode === self::MODE_APPEND ? self::rowsOf($args['lignes'] ?? null) : [];
        if ($inlineRows !== []) {
            if (count($inlineRows) > self::INLINE_ROWS_MAX) {
                throw new \InvalidArgumentException(count($inlineRows) . ' lignes dans la demande : ' . self::INLINE_ROWS_MAX
                    . ' au plus. Range-les dans une table de travail, puis ajoute-les avec `depuis`.');
            }
            $labels = array_values(array_filter(
                array_map(static fn($l): string => is_scalar($l) ? trim((string)$l) : '', self::listOf($args['colonnes'] ?? [])),
                static fn(string $l): bool => $l !== ''
            ));
            if ($labels === []) {
                // Sans `colonnes` : les valeurs suivent l'ordre des colonnes de la table.
                $labels = array_map(static fn(array $c): string => (string)$c['label'], WorkTableView::visibleColumns($target));
            }
            $lines = [];
            foreach ($inlineRows as $line) {
                $values = [];
                if (array_is_list($line)) {
                    foreach ($labels as $i => $label) {
                        $values[] = WorkTableOps::cell($line[$i] ?? '');
                    }
                } else {
                    // Objet {colonne: valeur} : rangé d'après les libellés.
                    $byLabel = [];
                    foreach ($line as $k => $v) {
                        $byLabel[WorkTableOps::fold((string)$k)] = $v;
                    }
                    foreach ($labels as $label) {
                        $values[] = WorkTableOps::cell($byLabel[WorkTableOps::fold($label)] ?? '');
                    }
                }
                if (implode('', $values) !== '') {
                    $lines[] = $values;
                }
            }
            if ($lines === []) {
                throw new \InvalidArgumentException('Aucune ligne à ajouter : `lignes` attend une liste de valeurs par ligne, dans l\'ordre de `colonnes`.');
            }
            $base['inline']  = ['labels' => $labels, 'rows' => $lines];
            $base['columns'] = WorkTableOps::keys(WorkTableOps::columns($labels));
            $base['set']     = $set;
            return $base;
        }

        $visible = WorkTableView::visibleColumns($source);

        $columns = [];
        foreach (self::listOf($args['colonnes'] ?? []) as $ref) {
            $columns[] = WorkTableOps::column($source, $ref);
        }
        $columns = array_values(array_unique($columns));

        $key = $sourceKey = '';
        if ($mode === self::MODE_UPDATE) {
            if (!is_scalar($args['cle'] ?? null) || trim((string)$args['cle']) === '') {
                throw new \InvalidArgumentException('Paramètre `cle` : la colonne de la table permanente qui identifie une ligne (email, nom, référence).');
            }
            $key       = WorkTableOps::column($target, $args['cle']);
            $sourceRef = is_scalar($args['cle_source'] ?? null) && trim((string)$args['cle_source']) !== '' ? $args['cle_source'] : $args['cle'];
            $sourceKey = WorkTableOps::column($source, $sourceRef);
            $columns   = array_values(array_diff($columns, [$sourceKey]));
            if ($columns === [] && $set === []) {
                throw new \InvalidArgumentException('Rien à écrire : indique `colonnes` (colonnes de la table source à reporter) ou `definir` (valeurs fixes).');
            }
        } elseif ($columns === []) {
            // Ajouter ou remplacer : toutes les colonnes visibles de la source.
            $columns = WorkTableOps::keys($visible);
        }
        return [
            'mode'        => $mode,
            'key'         => $key,
            'source_key'  => $sourceKey,
            'columns'     => $columns,
            'set'         => $set,
            'add_missing' => $mode === self::MODE_UPDATE && self::truthy($args['ajouter_absents'] ?? false),
        ] + $direct;
    }

    /**
     * Report d'un calcul fait sur les lignes de la table elle-même (colonnes
     * enrichies d'une vue ou de toute la table) : chaque ligne de la source
     * porte sa position dans la table permanente (WorkTableRecipe::LINE_KEY),
     * c'est elle qui désigne la ligne à compléter — aucune clé à deviner.
     * Jamais proposé au modèle : la carte l'emploie seule, et l'accord
     * revérifie que la table n'a pas changé depuis le calcul (une ligne
     * ajoutée ou supprimée décalerait les positions). Pure.
     *
     * @param list<string> $columns clés des colonnes de la source à reporter
     */
    public static function lineUpdateSpec(array $columns): array
    {
        return [
            'mode' => self::MODE_UPDATE, 'key' => '', 'source_key' => '', 'columns' => array_values($columns), 'set' => [],
            'add_missing' => false, 'by_line' => true,
            'filters' => [], 'any' => false, 'inline' => null, 'column' => '', 'new_label' => '',
        ];
    }

    /** Ce report désigne-t-il les lignes par leur position ? Pure. */
    public static function byLine(array $spec): bool
    {
        return !empty($spec['by_line']) && (string)($spec['mode'] ?? '') === self::MODE_UPDATE;
    }

    /**
     * Le résultat de l'écriture et ce qu'elle change. Pure.
     *
     * @param list<array>                 $target     colonnes de la table permanente
     * @param list<array<string,string>>  $targetRows ses lignes
     * @param list<array>                 $source     colonnes de la table source
     * @param list<array<string,string>>  $sourceRows ses lignes
     * @param array                       $spec       rendu par spec()
     * @return array{columns: list<array>, rows: list<array<string,string>>,
     *               stats: array{before:int, after:int, touched:int, added:int, unmatched:int, cells:int, columns_added:list<string>, columns_touched:list<string>,
     *                            deleted:int, matched:int, columns_dropped:list<string>, columns_renamed:list<string>} (`columns_renamed` : [ancien, nouveau]),
     *               samples: list<array{line:string, column:string, before:string, after:string}>,
     *               lines: ?array{kind:string, columns:list<string>, rows:list<list<string>>, total:int}}
     * @throws KbTableTooLargeException
     * @throws \InvalidArgumentException une colonne visée n'existe plus (message pour le modèle et pour la personne)
     */
    public static function plan(array $target, array $targetRows, array $source, array $sourceRows, array $spec): array
    {
        $mode = (string)$spec['mode'];
        $spec += ['filters' => [], 'any' => false, 'inline' => null, 'column' => '', 'new_label' => '', 'set' => [], 'columns' => []];
        if ($mode === self::MODE_REPLACE) {
            return self::replacePlan($target, $targetRows, $source, $sourceRows, $spec);
        }
        if ($mode === self::MODE_DELETE) {
            return self::deletePlan($target, $targetRows, $spec);
        }
        if ($mode === self::MODE_RENAME || $mode === self::MODE_DROP) {
            return self::columnPlan($target, $targetRows, $spec);
        }
        // Lignes données dans la demande : elles tiennent lieu de table source.
        if (is_array($spec['inline']) && $mode === self::MODE_APPEND) {
            [$source, $sourceRows] = self::inlineSource($spec['inline']);
        }
        if ($mode === self::MODE_SET) {
            self::assertColumns($target, array_column((array)$spec['filters'], 'col'));
        }

        // Colonnes de destination : celle de la source par sa clé, sinon par
        // son libellé (sans casse ni accents), sinon une colonne nouvelle.
        $columns = array_values($target);
        $added   = [];
        $map     = [];
        $sourceByKey = [];
        foreach ($source as $c) {
            $sourceByKey[(string)$c['key']] = $c;
        }
        $place = static function (string $label, string $type) use (&$columns, &$added): string {
            foreach ($columns as $c) {
                if (WorkTableOps::fold((string)$c['label']) === WorkTableOps::fold($label) || (string)$c['key'] === WorkTableOps::slug($label)) {
                    return (string)$c['key'];
                }
            }
            if (count($columns) >= WorkTableOps::MAX_COLUMNS) {
                throw new KbTableTooLargeException(0, count($columns) + 1);
            }
            $next    = WorkTableOps::columns(array_merge($columns, [['label' => $label, 'type' => $type]]));
            $columns = $next;
            $new     = $next[count($next) - 1];
            $added[] = (string)$new['label'];
            return (string)$new['key'];
        };
        foreach ($spec['columns'] as $key) {
            $c = $sourceByKey[$key] ?? null;
            if ($c !== null) {
                $map[$key] = $place((string)$c['label'], (string)($c['type'] ?? 'text'));
            }
        }
        $setKeys = [];
        foreach ($spec['set'] as $label => $value) {
            $setKeys[$place((string)$label, 'text')] = (string)$value;
        }

        $keys    = WorkTableOps::keys($columns);
        $rows    = array_map(static fn(array $r): array => $r + array_fill_keys($keys, ''), array_values($targetRows));
        $labelOf = self::labeller($columns);
        $colName = [];
        foreach ($columns as $c) {
            $colName[(string)$c['key']] = (string)$c['label'];
        }

        $samples = [];
        $touchedCols = [];
        $cells   = 0;
        $touched = 0;
        $addedRows = 0;
        $unmatched = 0;
        $matched = 0;
        $addedLines = [];
        $write = static function (array &$row, string $key, string $value) use (&$samples, &$touchedCols, &$cells, $labelOf, $colName): bool {
            $before = (string)($row[$key] ?? '');
            if ($before === $value) {
                return false;
            }
            if (count($samples) < self::SAMPLES) {
                $samples[] = ['line' => $labelOf($row), 'column' => $colName[$key] ?? $key, 'before' => $before, 'after' => $value];
            }
            $row[$key] = $value;
            $touchedCols[$key] = true;
            $cells++;
            return true;
        };

        if ($mode === self::MODE_SET) {
            // Valeurs fixes pour les lignes que retiennent les filtres (toutes, sans filtre).
            foreach ($rows as $i => $row) {
                if (!self::passes($row, (array)$spec['filters'], !empty($spec['any']))) {
                    continue;
                }
                $matched++;
                $changed = false;
                foreach ($setKeys as $to => $value) {
                    $changed = $write($rows[$i], $to, $value) || $changed;
                }
                $touched += $changed ? 1 : 0;
            }
        } elseif ($mode === self::MODE_UPDATE) {
            $byLine = self::byLine($spec);
            $index = [];
            foreach ($rows as $i => $row) {
                $k = $byLine ? (string)($i + 1) : self::keyOf((string)($row[$spec['key']] ?? ''));
                if ($k !== '') {
                    $index[$k][] = $i;
                }
            }
            $seen = [];
            foreach ($sourceRows as $src) {
                $k = $byLine
                    ? (ctype_digit((string)($src[WorkTableRecipe::LINE_KEY] ?? '')) ? (string)(int)$src[WorkTableRecipe::LINE_KEY] : '')
                    : self::keyOf((string)($src[$spec['source_key']] ?? ''));
                if ($k === '' || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                if (!isset($index[$k])) {
                    $unmatched++;
                    if (!empty($spec['add_missing'])) {
                        $line = array_fill_keys($keys, '');
                        $line[$spec['key']] = (string)($src[$spec['source_key']] ?? '');
                        foreach ($map as $from => $to) {
                            $line[$to] = (string)($src[$from] ?? '');
                        }
                        foreach ($setKeys as $to => $value) {
                            $line[$to] = $value;
                        }
                        $rows[] = $line;
                        $addedRows++;
                    }
                    continue;
                }
                foreach ($index[$k] as $i) {
                    $changed = false;
                    foreach ($map as $from => $to) {
                        $changed = $write($rows[$i], $to, (string)($src[$from] ?? '')) || $changed;
                    }
                    foreach ($setKeys as $to => $value) {
                        $changed = $write($rows[$i], $to, $value) || $changed;
                    }
                    $touched += $changed ? 1 : 0;
                }
            }
        } else { // ajouter
            foreach ($sourceRows as $src) {
                $line = array_fill_keys($keys, '');
                foreach ($map as $from => $to) {
                    $line[$to] = (string)($src[$from] ?? '');
                }
                foreach ($setKeys as $to => $value) {
                    $line[$to] = $value;
                }
                if (implode('', $line) === '') {
                    continue;
                }
                $rows[] = $line;
                $addedRows++;
                if (count($addedLines) < self::LINES_SHOWN) {
                    $addedLines[] = $line;
                }
            }
        }
        if (count($rows) > KbTableStore::MAX_ROWS) {
            throw new KbTableTooLargeException(count($rows), 0);
        }

        return [
            'columns' => $columns,
            'rows'    => array_values($rows),
            'stats'   => [
                'before'          => count($targetRows),
                'after'           => count($rows),
                'touched'         => $touched,
                'added'           => $addedRows,
                'unmatched'       => $unmatched,
                'cells'           => $cells,
                'columns_added'   => $added,
                'columns_touched' => array_values(array_map(static fn(string $k): string => $colName[$k] ?? $k, array_keys($touchedCols))),
                'matched'         => $matched,
            ] + self::NO_DIRECT_STATS,
            'samples' => $samples,
            'lines'   => $addedLines !== [] ? self::linesOf('added', $columns, $addedLines, $addedRows) : null,
        ];
    }

    /** Compteurs des modes directs, à zéro pour les autres : la forme de `stats` ne dépend pas du mode. */
    private const NO_DIRECT_STATS = ['deleted' => 0, 'matched' => 0, 'columns_dropped' => [], 'columns_renamed' => []];

    /** L'écriture change-t-elle quelque chose ? Pure. */
    public static function changes(array $plan): bool
    {
        $s = $plan['stats'];
        return $s['touched'] > 0 || $s['added'] > 0 || $s['columns_added'] !== [] || $s['before'] !== $s['after'] || !empty($s['replaced'])
            || !empty($s['deleted']) || !empty($s['columns_dropped']) || !empty($s['columns_renamed']);
    }

    /**
     * Aperçu d'une écriture, pour la carte d'approbation. Pure.
     *
     * @param array  $table table permanente (KbTableStore::find)
     * @param string $from  titre de la table de la conversation ('' pour un mode direct)
     * @param int    $shared groupes et personnes avec qui elle est partagée
     */
    public static function preview(array $table, array $spec, array $plan, string $from, int $shared = 0): array
    {
        $s    = $plan['stats'] + self::NO_DIRECT_STATS;
        $name = (string)$table['title'];
        $mode = (string)$spec['mode'];
        $modified = I18n::plural((int)$s['touched'], '{n} ligne modifiée', '{n} lignes modifiées', ['n' => Format::credits((int)$s['touched'])]);
        $subtitle = match ($mode) {
            self::MODE_DELETE => I18n::plural((int)$s['deleted'], '{n} ligne supprimée', '{n} lignes supprimées', ['n' => Format::credits((int)$s['deleted'])]),
            self::MODE_RENAME => t('« {avant} » → « {apres} »', ['avant' => (string)($s['columns_renamed'][0] ?? ''), 'apres' => (string)($s['columns_renamed'][1] ?? '')]),
            self::MODE_DROP   => t('Colonne {noms}', ['noms' => I18n::listeCitee((array)$s['columns_dropped'])]),
            self::MODE_SET    => ((int)$s['touched'] > 0 || $s['columns_added'] === []
                    ? $modified
                    : I18n::plural(count($s['columns_added']), 'Colonne {noms} ajoutée', 'Colonnes {noms} ajoutées', ['noms' => I18n::listeCitee($s['columns_added'])]))
                . ((int)$s['touched'] > 0 && $s['columns_touched'] !== [] ? ' · ' . I18n::plural(
                    count($s['columns_touched']),
                    'colonne {noms}',
                    'colonnes {noms}',
                    ['noms' => I18n::listeCitee(array_slice($s['columns_touched'], 0, 3))]
                ) : ''),
            self::MODE_REPLACE => t('{avant} → {apres}', [
                'avant' => WorkTableView::rowsLabel((int)$s['before']),
                'apres' => WorkTableView::rowsLabel((int)$s['after']),
            ]),
            self::MODE_APPEND => I18n::plural((int)$s['added'], '{n} ligne ajoutée', '{n} lignes ajoutées', ['n' => Format::credits((int)$s['added'])]),
            default => I18n::plural((int)$s['touched'], '{n} ligne modifiée', '{n} lignes modifiées', ['n' => Format::credits((int)$s['touched'])])
                . ($s['columns_touched'] !== [] ? ' · ' . I18n::plural(
                    count($s['columns_touched']),
                    'colonne {noms}',
                    'colonnes {noms}',
                    ['noms' => I18n::listeCitee(array_slice($s['columns_touched'], 0, 3))]
                ) : ''),
        };

        $impacts = [];
        if ($mode === self::MODE_SET || $mode === self::MODE_DELETE) {
            $impacts[] = self::scopeLabel($table, $spec, $mode === self::MODE_DELETE ? (int)$s['deleted'] : (int)$s['matched']);
        }
        if ($mode === self::MODE_REPLACE) {
            $impacts[] = t('Tout le contenu actuel est remplacé par celui de « {source} ».', ['source' => $from]);
        } elseif ($mode === self::MODE_DELETE) {
            $impacts[] = t('Il restera {n} dans la table.', ['n' => WorkTableView::rowsLabel((int)$s['after'])]);
        } elseif ($mode === self::MODE_RENAME) {
            $impacts[] = t('Les valeurs de la colonne ne changent pas.');
        } elseif ($mode === self::MODE_DROP) {
            $impacts[] = t('La colonne et toutes ses valeurs sont retirées de la table.');
        } else {
            $impacts[] = $s['added'] > 0
                ? I18n::plural((int)$s['added'], '{n} ligne ajoutée en fin de table.', '{n} lignes ajoutées en fin de table.', ['n' => Format::credits((int)$s['added'])])
                : t('Aucune ligne ajoutée ni supprimée.');
            if ($spec['mode'] === self::MODE_UPDATE && $s['unmatched'] > 0 && empty($spec['add_missing'])) {
                $impacts[] = I18n::plural(
                    (int)$s['unmatched'],
                    '{n} ligne de « {source} » ne se retrouve pas dans la table : ignorée.',
                    '{n} lignes de « {source} » ne se retrouvent pas dans la table : ignorées.',
                    ['n' => Format::credits((int)$s['unmatched']), 'source' => $from]
                );
            }
        }
        if ($s['columns_added'] !== []) {
            $impacts[] = I18n::plural(
                count($s['columns_added']),
                'Colonne créée : {noms}.',
                'Colonnes créées : {noms}.',
                ['noms' => I18n::listeCitee($s['columns_added'])]
            );
        }
        if ($shared > 0) {
            $impacts[] = t('Table partagée : tous ceux qui la voient verront la modification.');
        }
        $impacts[] = t('Le changement s\'inscrit dans l\'historique de la table : on peut revenir en arrière.');

        $grid = null;
        if ($plan['samples'] !== []) {
            $lines = [];
            foreach ($plan['samples'] as $x) {
                $lines[] = [
                    self::clip($x['line'], 60),
                    self::clip($x['column'], 40),
                    $x['before'] === '' ? t('vide') : self::clip($x['before'], 80),
                    $x['after'] === '' ? t('vide') : self::clip($x['after'], 80),
                ];
            }
            $rest = max(0, (int)$s['cells'] - count($lines));
            $grid = [
                'colonnes' => [t('Ligne'), t('Colonne'), t('Avant'), t('Après')],
                'lignes'   => $lines,
                'suite'    => $rest > 0 ? I18n::plural($rest, '+ {n} autre valeur modifiée', '+ {n} autres valeurs modifiées', ['n' => Format::credits($rest)]) : '',
            ];
        } elseif (is_array($plan['lines'] ?? null) && $plan['lines']['rows'] !== []) {
            // Lignes ajoutées ou supprimées : on les montre telles quelles.
            $shown = $plan['lines'];
            $rest  = max(0, (int)$shown['total'] - count($shown['rows']));
            $grid  = [
                'colonnes' => $shown['columns'],
                'lignes'   => array_map(
                    static fn(array $line): array => array_map(static fn($v): string => self::clip((string)$v, 60), $line),
                    $shown['rows']
                ),
                'suite'    => $rest === 0 ? '' : ($shown['kind'] === 'deleted'
                    ? I18n::plural($rest, '+ {n} autre ligne supprimée', '+ {n} autres lignes supprimées', ['n' => Format::credits($rest)])
                    : I18n::plural($rest, '+ {n} autre ligne ajoutée', '+ {n} autres lignes ajoutées', ['n' => Format::credits($rest)])),
            ];
        }

        return array_filter([
            'action'  => match ($mode) {
                self::MODE_REPLACE => t('Remplacer le contenu de la table « {nom} »', ['nom' => $name]),
                self::MODE_APPEND  => t('Ajouter des lignes à la table « {nom} »', ['nom' => $name]),
                self::MODE_DELETE  => t('Supprimer des lignes de la table « {nom} »', ['nom' => $name]),
                self::MODE_RENAME  => t('Renommer une colonne de la table « {nom} »', ['nom' => $name]),
                self::MODE_DROP    => t('Supprimer une colonne de la table « {nom} »', ['nom' => $name]),
                default            => t('Modifier la table « {nom} »', ['nom' => $name]),
            },
            'fichier' => $subtitle,
            'impacts' => $impacts,
            'grille'  => $grid,
            'icone'   => '/assets/img/table.svg',
        ], static fn($v): bool => $v !== null);
    }

    /** Ce que le changement fait, en une phrase (journal, message d'issue). Pure. */
    public static function reason(array $spec, array $plan): string
    {
        $s = $plan['stats'] + self::NO_DIRECT_STATS;
        if ($spec['mode'] === self::MODE_SET && (int)$s['touched'] === 0 && $s['columns_added'] !== []) {
            return I18n::plural(count($s['columns_added']), 'Colonne {noms} ajoutée par MIA', 'Colonnes {noms} ajoutées par MIA', ['noms' => I18n::listeCitee($s['columns_added'])]);
        }
        return match ($spec['mode']) {
            self::MODE_DELETE  => I18n::plural((int)$s['deleted'], '{n} ligne supprimée par MIA', '{n} lignes supprimées par MIA', ['n' => Format::credits((int)$s['deleted'])]),
            self::MODE_RENAME  => t('Colonne « {avant} » renommée « {apres} » par MIA', ['avant' => (string)($s['columns_renamed'][0] ?? ''), 'apres' => (string)($s['columns_renamed'][1] ?? '')]),
            self::MODE_DROP    => t('Colonne {noms} supprimée par MIA', ['noms' => I18n::listeCitee((array)$s['columns_dropped'])]),
            self::MODE_REPLACE => t('Contenu remplacé par MIA ({n} lignes)', ['n' => Format::credits((int)$s['after'])]),
            self::MODE_APPEND  => I18n::plural((int)$s['added'], '{n} ligne ajoutée par MIA', '{n} lignes ajoutées par MIA', ['n' => Format::credits((int)$s['added'])]),
            default            => I18n::plural((int)$s['touched'], '{n} ligne modifiée par MIA', '{n} lignes modifiées par MIA', ['n' => Format::credits((int)$s['touched'])]),
        };
    }

    // ===================== Interne =====================

    /** Supprimer les lignes que retiennent les filtres. */
    private static function deletePlan(array $target, array $targetRows, array $spec): array
    {
        self::assertColumns($target, array_column((array)$spec['filters'], 'col'));
        $kept    = [];
        $removed = [];
        $deleted = 0;
        foreach (array_values($targetRows) as $row) {
            if ((array)$spec['filters'] !== [] && self::passes($row, (array)$spec['filters'], !empty($spec['any']))) {
                $deleted++;
                if (count($removed) < self::LINES_SHOWN) {
                    $removed[] = $row;
                }
                continue;
            }
            $kept[] = $row;
        }
        return [
            'columns' => array_values($target),
            'rows'    => $kept,
            'stats'   => [
                'before' => count($targetRows), 'after' => count($kept), 'touched' => 0, 'added' => 0, 'unmatched' => 0,
                'cells' => 0, 'columns_added' => [], 'columns_touched' => [],
                'deleted' => $deleted, 'matched' => $deleted, 'columns_dropped' => [], 'columns_renamed' => [],
            ],
            'samples' => [],
            'lines'   => $removed !== [] ? self::linesOf('deleted', $target, $removed, $deleted) : null,
        ];
    }

    /** Renommer une colonne (sa clé ne bouge pas, ses valeurs non plus) ou la supprimer. */
    private static function columnPlan(array $target, array $targetRows, array $spec): array
    {
        $key = (string)$spec['column'];
        self::assertColumns($target, [$key]);
        $columns = [];
        $old     = '';
        foreach (array_values($target) as $c) {
            if ((string)$c['key'] !== $key) {
                $columns[] = $c;
                continue;
            }
            $old = (string)$c['label'];
            if ($spec['mode'] === self::MODE_RENAME) {
                $columns[] = ['label' => (string)$spec['new_label']] + $c;
            }
        }
        $rows = array_values($targetRows);
        $stats = [
            'before' => count($rows), 'after' => count($rows), 'touched' => 0, 'added' => 0, 'unmatched' => 0,
            'cells' => 0, 'columns_added' => [], 'columns_touched' => [],
        ] + self::NO_DIRECT_STATS;
        if ($spec['mode'] === self::MODE_DROP) {
            if ($columns === []) {
                throw new \InvalidArgumentException('Une table garde au moins une colonne : celle-ci ne peut pas être supprimée.');
            }
            foreach ($rows as $i => $row) {
                unset($rows[$i][$key]);
            }
            $stats['columns_dropped'] = [$old];
        } elseif ($old !== (string)$spec['new_label']) {
            // [ancien nom, nouveau nom]
            $stats['columns_renamed'] = [$old, (string)$spec['new_label']];
        }
        return ['columns' => $columns, 'rows' => $rows, 'stats' => $stats, 'samples' => [], 'lines' => null];
    }

    /**
     * Lignes données dans la demande, sous la forme d'une table source.
     *
     * @param array{labels: list<string>, rows: list<list<string>>} $inline
     * @return array{0: list<array>, 1: list<array<string,string>>}
     */
    private static function inlineSource(array $inline): array
    {
        $columns = WorkTableOps::columns(array_values((array)($inline['labels'] ?? [])));
        $keys    = WorkTableOps::keys($columns);
        $rows    = [];
        foreach ((array)($inline['rows'] ?? []) as $line) {
            $row = [];
            foreach ($keys as $i => $k) {
                $row[$k] = is_array($line) && is_scalar($line[$i] ?? null) ? (string)$line[$i] : '';
            }
            $rows[] = $row;
        }
        return [$columns, $rows];
    }

    /**
     * Une écriture préparée sur une colonne qui n'existe plus (renommée ou
     * supprimée entre la carte et l'accord) ne s'applique pas.
     *
     * @param list<string> $keys
     */
    private static function assertColumns(array $target, array $keys): void
    {
        $known = array_flip(WorkTableOps::keys($target));
        foreach ($keys as $k) {
            if (!isset($known[(string)$k])) {
                throw new \InvalidArgumentException('La colonne « ' . $k . ' » n\'existe plus dans la table. Redemandez la modification.');
            }
        }
    }

    /** Une ligne passe-t-elle les filtres ? (toutes les conditions, ou au moins une) */
    private static function passes(array $row, array $filters, bool $any): bool
    {
        return WorkTableOps::filter([$row], $filters, $any) !== [];
    }

    /**
     * Lignes ajoutées ou supprimées, telles que la carte les montre : les
     * premières colonnes visibles, quelques lignes.
     *
     * @return array{kind:string, columns:list<string>, rows:list<list<string>>, total:int}
     */
    private static function linesOf(string $kind, array $columns, array $rows, int $total): array
    {
        $rows    = array_slice(array_values($rows), 0, self::LINES_SHOWN);
        $visible = WorkTableView::visibleColumns($columns);
        if ($kind === 'added') {
            // Lignes ajoutées : les colonnes qu'elles REMPLISSENT d'abord — une
            // valeur donnée dans la sixième colonne doit se voir sur la carte.
            $filled = array_values(array_filter($visible, static function (array $c) use ($rows): bool {
                foreach ($rows as $row) {
                    if (trim((string)($row[$c['key']] ?? '')) !== '') {
                        return true;
                    }
                }
                return false;
            }));
            $visible = $filled !== [] ? $filled : $visible;
        }
        $shown = array_slice($visible, 0, self::LINES_COLUMNS);
        return [
            'kind'    => $kind,
            'columns' => array_map(static fn(array $c): string => (string)$c['label'], $shown),
            'rows'    => array_map(
                static fn(array $row): array => array_map(static fn(array $c): string => (string)($row[$c['key']] ?? ''), $shown),
                $rows
            ),
            'total'   => $total,
        ];
    }

    /** Quelles lignes sont visées, en mots : « Ville = Lyon et CA 2026 > 10 000 (19 lignes) ». */
    private static function scopeLabel(array $table, array $spec, int $count): string
    {
        $filters = (array)($spec['filters'] ?? []);
        if ($filters === []) {
            return t('Toutes les lignes de la table sont visées.');
        }
        $labels = [];
        foreach ((array)($table['columns'] ?? []) as $c) {
            $labels[(string)$c['key']] = (string)$c['label'];
        }
        $parts = [];
        foreach ($filters as $f) {
            $col = $labels[(string)($f['col'] ?? '')] ?? (string)($f['col'] ?? '');
            $p   = ['colonne' => $col, 'valeur' => self::clip((string)($f['value'] ?? ''), 40)];
            $parts[] = match ((string)($f['test'] ?? '')) {
                'egal'            => t('{colonne} = « {valeur} »', $p),
                'different'       => t('{colonne} ≠ « {valeur} »', $p),
                'contient'        => t('{colonne} contient « {valeur} »', $p),
                'ne_contient_pas' => t('{colonne} ne contient pas « {valeur} »', $p),
                'commence_par'    => t('{colonne} commence par « {valeur} »', $p),
                'vide'            => t('{colonne} est vide', $p),
                'non_vide'        => t('{colonne} n’est pas vide', $p),
                'superieur'       => t('{colonne} > {valeur}', $p),
                'au_moins'        => t('{colonne} ≥ {valeur}', $p),
                'inferieur'       => t('{colonne} < {valeur}', $p),
                'au_plus'         => t('{colonne} ≤ {valeur}', $p),
                'parmi'           => t('{colonne} parmi « {valeur} »', $p),
                default           => $col,
            };
        }
        $all = !empty($spec['any'])
            ? implode(' ' . t('ou') . ' ', $parts)
            : implode(' ' . t('et') . ' ', $parts);
        return t('Lignes visées : {filtre} ({lignes}).', ['filtre' => $all, 'lignes' => WorkTableView::rowsLabel($count)]);
    }

    /** @return list<array> lignes données par le modèle : liste de listes (ou d'objets), ou sa forme JSON */
    private static function rowsOf(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private static function replacePlan(array $target, array $targetRows, array $source, array $sourceRows, array $spec): array
    {
        $kept = [];
        foreach ($source as $c) {
            if (in_array((string)$c['key'], $spec['columns'], true)) {
                $kept[] = ['key' => (string)$c['key'], 'label' => (string)$c['label'], 'type' => (string)($c['type'] ?? 'text'), 'tech' => false];
            }
        }
        if ($kept === []) {
            throw new \InvalidArgumentException('La table source n\'a aucune colonne à reporter.');
        }
        $keys = WorkTableOps::keys($kept);
        $rows = [];
        foreach ($sourceRows as $src) {
            $line = [];
            foreach ($keys as $k) {
                $line[$k] = (string)($src[$k] ?? '');
            }
            $rows[] = $line;
        }
        if (count($rows) > KbTableStore::MAX_ROWS) {
            throw new KbTableTooLargeException(count($rows), 0);
        }
        $old = array_map(static fn(array $c): string => WorkTableOps::fold((string)$c['label']), $target);
        $new = array_values(array_filter(
            array_map(static fn(array $c): string => (string)$c['label'], $kept),
            static fn(string $l): bool => !in_array(WorkTableOps::fold($l), $old, true)
        ));
        return [
            'columns' => $kept,
            'rows'    => $rows,
            'stats'   => [
                'before' => count($targetRows), 'after' => count($rows), 'touched' => 0, 'added' => 0, 'unmatched' => 0,
                'cells' => 0, 'columns_added' => $target === [] ? [] : $new, 'columns_touched' => [], 'replaced' => true,
            ] + self::NO_DIRECT_STATS,
            'samples' => [],
            'lines'   => null,
        ];
    }

    /** Clé de rapprochement d'une ligne : sans casse, ni accents, ni blancs de bord. */
    private static function keyOf(string $value): string
    {
        return WorkTableOps::fold(trim($value));
    }

    /** Comment nommer une ligne sur la carte : sa première valeur de texte non vide. */
    private static function labeller(array $columns): \Closure
    {
        $keys = WorkTableOps::keys(WorkTableView::visibleColumns($columns));
        return static function (array $row) use ($keys): string {
            foreach ($keys as $k) {
                if (trim((string)($row[$k] ?? '')) !== '') {
                    return (string)$row[$k];
                }
            }
            return '';
        };
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /** @return list<mixed> liste, ou texte JSON, ou texte à virgules */
    private static function listOf(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $raw)), static fn(string $s): bool => $s !== '');
        }
        return is_array($raw) ? array_values(array_filter($raw, 'is_scalar')) : [];
    }

    /** @return array<string, mixed> objet, ou texte JSON d'un objet */
    private static function assocOf(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || array_is_list($raw)) {
            return [];
        }
        return array_filter($raw, 'is_scalar');
    }

    private static function truthy(mixed $raw): bool
    {
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN) === true;
    }
}
