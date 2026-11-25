<?php

declare(strict_types=1);

/**
 * Ce que la personne voit d'une table PERMANENTE : sa fiche (colonnes,
 * grille modifiable, origine — ou un état passé de son historique, en lecture
 * seule, les cellules qui diffèrent de maintenant signalées) et son aperçu dans la liste
 * des bases. SOURCE UNIQUE — rendu par le serveur, les scripts n'ont aucun
 * libellé (templates/admin/knowledge-bases/partials/table-section.php,
 * table-side.php).
 */
final class KbTableView
{
    /** Lignes montrées dans l'aperçu de la liste des bases. */
    public const SIDE_ROWS = 5;

    /** Colonnes montrées dans cet aperçu. */
    public const SIDE_COLUMNS = 4;

    /** Libellé d'un type de colonne. */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'number' => t('Nombre'),
            'date'   => t('Date'),
            default  => t('Texte'),
        };
    }

    /** @return array<string, string> type → libellé, dans l'ordre proposé */
    public static function types(): array
    {
        $out = [];
        foreach (WorkTableOps::TYPES as $type) {
            $out[$type] = self::typeLabel($type);
        }
        return $out;
    }

    /**
     * « 240 lignes sur 20 000 · 6 colonnes · modifiée il y a 3 jours » :
     * l'en-tête de la fiche et le résumé de son volet. Pure.
     */
    public static function metaLine(array $table): string
    {
        $parts = [
            t('{lignes} sur {max}', [
                'lignes' => WorkTableView::rowsLabel((int)$table['rows']),
                'max'    => Format::credits(KbTableStore::MAX_ROWS),
            ]),
            I18n::plural(count((array)$table['columns']), '{n} colonne', '{n} colonnes'),
        ];
        if (($table['updated_age'] ?? null) !== null) {
            $parts[] = t('modifiée {quand}', ['quand' => KbStatus::ago((int)$table['updated_age'])]);
        }
        return implode(' · ', $parts);
    }

    /**
     * La fiche d'une table : tout ce que le partiel affiche.
     *
     * @param array      $kb      ligne `knowledge_bases`
     * @param array      $table   KbTableStore::find()
     * @param array|null $preview un état passé : ['entry' => KbTableStore::entry(), 'diff' => KbTableHistory::diff()]
     */
    public static function section(KbTableStore $store, array $kb, array $table, mixed $q, int $page, bool $canEdit, ?array $preview = null): array
    {
        $rowsOf = $store;
        if ($preview !== null) {
            $entry = (array)$preview['entry'];
            $cols  = json_decode((string)$entry['columns_json'], true);
            $table = [
                'id'      => (int)$table['id'],
                'title'   => (string)$table['title'],
                'name'    => (string)$table['name'],
                'columns' => is_array($cols) ? WorkTableOps::columns($cols) : [],
                'rows'    => (int)$entry['row_count'],
                'origin'  => (array)$table['origin'],
            ];
            $rowsOf  = new KbTableVersionRows((int)$entry['version']);
            $canEdit = false;
        }
        $columns = [];
        foreach ((array)$table['columns'] as $c) {
            $columns[] = [
                'key'        => (string)$c['key'],
                'label'      => (string)$c['label'],
                'type'       => (string)$c['type'],
                'type_label' => self::typeLabel((string)$c['type']),
            ];
        }
        $kbId = (int)$kb['id'];
        $grid = WorkTableView::page($rowsOf, $table, $q, $page);
        if ($preview !== null) {
            $grid = self::marked($grid, (array)($preview['diff']['marks'] ?? []));
        }
        return [
            'kb_id'      => $kbId,
            'title'      => (string)$kb['name'],
            'meta'       => self::metaLine($table),
            'rows'       => (int)$table['rows'],
            'max_rows'   => KbTableStore::MAX_ROWS,
            'max_cols'   => WorkTableOps::MAX_COLUMNS,
            'full'       => (int)$table['rows'] >= KbTableStore::MAX_ROWS,
            'cols_full'  => count($columns) >= WorkTableOps::MAX_COLUMNS,
            'columns'    => $columns,
            'types'      => self::types(),
            'page'       => $grid,
            'origin'     => KbTableImport::originLabel((array)$table['origin']),
            'preview'    => $preview !== null ? (int)$preview['entry']['version'] : 0,
            'history'    => '/admin/knowledge-bases/' . $kbId . '?historique=1',
            'can_edit'   => $canEdit,
            'xlsx'       => self::exportLink($kbId, $table, 'xlsx'),
            'csv'        => self::exportLink($kbId, $table, 'csv'),
            'xlsx_name'  => WorkTableExport::fileName($table, '', 'xlsx'),
            'csv_name'   => WorkTableExport::fileName($table, '', 'csv'),
        ];
    }

    /**
     * Signale, dans une page de la grille d'un état passé, ce qui diffère de
     * maintenant : la valeur actuelle d'une cellule changée, une ligne absente
     * aujourd'hui. Pure.
     *
     * @param array $marks KbTableHistory::diff()['marks'] (rang dans l'état → marque)
     */
    public static function marked(array $grid, array $marks): array
    {
        foreach ($grid['rows'] as $i => $row) {
            $mark = $marks[(int)$row['n'] - 1] ?? null;
            if ($mark === null) {
                continue;
            }
            $grid['rows'][$i]['gone'] = !empty($mark['gone']);
            foreach ($row['cells'] as $j => $cell) {
                if (array_key_exists((string)$cell['key'], (array)$mark['changed'])) {
                    $now = (string)$mark['changed'][$cell['key']];
                    $grid['rows'][$i]['cells'][$j]['changed'] = true;
                    $grid['rows'][$i]['cells'][$j]['now'] = $now === '' ? t('vide') : $now;
                }
            }
        }
        return $grid;
    }

    /** Rendu de la fiche (partiel), prêt à poser dans la page. */
    public static function sectionHtml(array $view): string
    {
        $tableSection = $view;
        ob_start();
        require ROOT . '/templates/admin/knowledge-bases/partials/table-section.php';
        return (string)ob_get_clean();
    }

    /** Lien de téléchargement d'une table permanente depuis l'administration. */
    public static function exportLink(int $kbId, array $table, string $format): string
    {
        return '/admin/knowledge-bases/' . $kbId . '/table/export/' . rawurlencode(WorkTableExport::fileName($table, '', $format));
    }

    /**
     * Aperçu d'une table dans la liste des bases, à la place du schéma : ses
     * premières lignes, ce que MIA peut y faire. Les colonnes au-delà de
     * SIDE_COLUMNS sont comptées, pas montrées.
     *
     * @param array $kb    ligne `knowledge_bases` (forManager)
     * @param array $table KbTableStore::find()
     */
    public static function side(KbTableStore $store, array $kb, array $table, bool $canWrite): array
    {
        $columns = array_slice(WorkTableView::visibleColumns((array)$table['columns']), 0, self::SIDE_COLUMNS);
        $keys    = WorkTableOps::keys($columns);
        $rows    = [];
        foreach ($store->rows((int)$table['id'], 0, self::SIDE_ROWS) as $row) {
            $line = [];
            foreach ($keys as $k) {
                $flat   = trim((string)preg_replace('/\s+/u', ' ', (string)($row[$k] ?? '')));
                $line[] = mb_strlen($flat) > 40 ? mb_substr($flat, 0, 39) . '…' : $flat;
            }
            $rows[] = $line;
        }
        $hidden = count((array)$table['columns']) - count($columns);
        return [
            'kb_id'    => (int)$kb['id'],
            'title'    => (string)$kb['name'],
            'origin'   => KbTableImport::originLabel((array)$table['origin']),
            'rows'     => (int)$table['rows'],
            'columns'  => count((array)$table['columns']),
            'modified' => ($table['updated_age'] ?? null) !== null ? KbStatus::ago((int)$table['updated_age']) : '',
            'head'     => array_map(static fn(array $c): string => (string)$c['label'], $columns),
            'lines'    => $rows,
            'more_columns' => $hidden > 0 ? I18n::plural($hidden, '+ {n} autre colonne', '+ {n} autres colonnes') : '',
            'more_rows'    => (int)$table['rows'] > count($rows)
                ? I18n::plural((int)$table['rows'] - count($rows), '+ {n} autre ligne', '+ {n} autres lignes', ['n' => Format::credits((int)$table['rows'] - count($rows))])
                : '',
            'mia_write' => !empty($table['mia_write']),
            'can_write' => $canWrite,
            'xlsx'      => self::exportLink((int)$kb['id'], $table, 'xlsx'),
            'xlsx_name' => WorkTableExport::fileName($table, '', 'xlsx'),
        ];
    }
}
