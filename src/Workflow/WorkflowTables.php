<?php

declare(strict_types=1);

/**
 * Les workflows et les TABLES — source unique des quatre passages :
 *
 *  1. une ÉTAPE lit et produit des tables : les outils de tables du chat
 *     (WorkTableTools) y sont ouverts ; les pièces Tableau qu'elle lit sont
 *     aussi rangées en tables de travail de la conversation de l'exécution
 *     (`materialize`), et la pièce Tableau qu'elle remet peut être une table
 *     de travail désignée par la ligne « TABLE : nom » (`fromReply`) — le
 *     modèle ne recopie jamais des centaines de lignes ;
 *  2. une BOUCLE « pour chaque ligne » parcourt une table PERMANENTE (base
 *     de connaissances de nature « table ») : `loopGrid` ;
 *  3. une LIVRAISON écrit une pièce dans une table permanente : `deliver`,
 *     par la carte de `modifier_table_permanente` (WorkTableTools::PERM_WRITE)
 *     — même aperçu avant / après, même vérification au moment de l'accord,
 *     même état inscrit dans l'historique de la table ;
 *  4. ce que l'éditeur propose (`choices`) et ce que « À régler » contrôle.
 *
 * Les formes pures (référence, lignes d'une pièce, lignes d'une Fiche,
 * réglages d'une livraison) se testent sans base.
 */
final class WorkflowTables
{
    /** Lignes au plus d'une pièce Tableau remise depuis une table de travail. */
    public const PIECE_MAX_ROWS = WorkTableOps::MAX_ROWS;

    /** Lignes de données au-delà desquelles une pièce Tableau lue est aussi rangée en table. */
    public const MATERIALIZE_MIN_ROWS = 1;

    /** Pièces Tableau rangées en tables au plus, par étape. */
    public const MATERIALIZE_MAX = 6;

    /**
     * Modes d'écriture d'une livraison dans une table : ceux de KbTableWrite
     * qui reportent une table — la livraison écrit toujours d'après sa pièce.
     * Les modes directs du chat (définir, supprimer des lignes, une colonne)
     * n'ont pas de sens ici.
     */
    public const WRITE_MODES = KbTableWrite::SOURCE_MODES;

    /** Préfixe des tables de travail d'une livraison. */
    private const DELIVERY_PREFIX = 'livraison_';

    /**
     * La table de travail désignée par la réponse : la DERNIÈRE ligne
     * « TABLE : nom » (gras, code ou citation tolérés autour), ou null.
     */
    public static function tableRef(string $reply): ?string
    {
        if (!preg_match_all('/^[ \t>*_`]*TABLE[ \t]*:[ \t*_]*[`«" *_]*([A-Za-z0-9_\-]{1,60})[`»" ]*[ \t*_`.]*$/mu', $reply, $m)) {
            return null;
        }
        $name = WorkTableOps::slug((string)end($m[1]));
        return $name !== '' ? $name : null;
    }

    /**
     * Le contenu d'une pièce Tableau tiré d'une table : en-tête (libellés des
     * colonnes visibles), puis les lignes, au plus `$max`.
     *
     * @return array{rows: list<list<string>>, total: int, cut: bool}
     */
    public static function pieceRows(array $columns, array $rows, int $max = self::PIECE_MAX_ROWS): array
    {
        $visible = WorkTableView::visibleColumns($columns);
        $keys    = WorkTableOps::keys($visible);
        $out     = [array_map(static fn(array $c): string => (string)$c['label'], $visible)];
        $total   = count($rows);
        foreach (array_slice(array_values($rows), 0, max(0, $max)) as $row) {
            $line = [];
            foreach ($keys as $k) {
                $line[] = (string)($row[$k] ?? '');
            }
            $out[] = $line;
        }
        return ['rows' => $out, 'total' => $total, 'cut' => $total > $max];
    }

    /**
     * Une pièce du dossier en lignes de table : un Tableau (en-tête puis
     * lignes), une Fiche (une ligne, ses champs en colonnes), un classeur
     * rédigé (.xlsx). Null si la pièce ne se range pas en lignes.
     *
     * @return array{columns: list<array>, rows: list<array<string,string>>}|null
     */
    public static function pieceAsTable(array $piece, array $content): ?array
    {
        $grid = null;
        if (($piece['form'] ?? '') === 'table') {
            $grid = array_values((array)($content['rows'] ?? []));
        } elseif (($piece['form'] ?? '') === 'document' && (string)($content['format'] ?? '') === 'xlsx') {
            try {
                $grid = TabularData::parse((string)($content['source'] ?? ''))->rows();
            } catch (\Throwable) {
                $grid = null;
            }
        } elseif (($piece['form'] ?? '') === 'fiche') {
            $labels = [];
            $values = [];
            foreach ((array)($piece['fields'] ?? []) as $f) {
                $labels[] = (string)$f['label'];
                $v = $content['fields'][$f['key']] ?? '';
                $values[] = WorkTableOps::cell(is_array($v) ? implode(', ', array_map('strval', $v)) : $v);
            }
            $grid = $labels !== [] ? [$labels, $values] : null;
        }
        if ($grid === null || count($grid) < 1) {
            return null;
        }
        $header  = array_map(static fn($c): string => (string)$c, (array)array_shift($grid));
        $columns = WorkTableOps::columns($header);
        if ($columns === []) {
            return null;
        }
        $rows = WorkTableOps::rows($columns, $grid);
        return ['columns' => WorkTableOps::typed($columns, $rows), 'rows' => $rows];
    }

    /**
     * Les pièces Tableau qu'une brique LIT (clé réelle au dossier, nom,
     * nombre de lignes), dans l'ordre de la consigne — de quoi les ranger en
     * tables de travail. Pure.
     *
     * @return list<array{key: string, name: string, rows: int, table: ?string}>
     */
    public static function readTablePieces(array $def, array $node, array $artifacts): array
    {
        if (!in_array($node['type'] ?? '', [WorkflowSpec::TYPE_STEP, WorkflowSpec::TYPE_ACTION], true)) {
            return [];
        }
        $pieces = WorkflowSpec::pieces($def);
        $out = [];
        foreach (WorkflowSpec::readsOf($def, $node) as $k) {
            $p = $pieces[$k] ?? null;
            $art = $artifacts[$k] ?? null;
            if ($p === null || $art === null || ($p['form'] ?? '') !== 'table') {
                continue;
            }
            $count = max(0, count((array)($art['content']['rows'] ?? [])) - 1);
            if ($count < self::MATERIALIZE_MIN_ROWS) {
                continue;
            }
            $out[] = [
                'key'   => (string)($art['key'] ?? $k),
                'name'  => (string)$p['name'],
                'rows'  => $count,
                // Pièce remise depuis une table de travail : son nom, pour la retrouver.
                'table' => is_array($art['content']['table'] ?? null) ? (string)($art['content']['table']['name'] ?? '') : null,
            ];
            if (count($out) >= self::MATERIALIZE_MAX) {
                break;
            }
        }
        return $out;
    }

    /**
     * Réglages d'une livraison dans une table → arguments de KbTableWrite::spec :
     * toutes les colonnes de la pièce sont reportées (la clé exceptée). Pure.
     *
     * @param list<array> $sourceColumns colonnes de la pièce rangée en table
     */
    public static function specArgs(array $d, array $sourceColumns = []): array
    {
        $args = ['mode' => in_array($d['write'] ?? '', self::WRITE_MODES, true) ? (string)$d['write'] : KbTableWrite::MODE_APPEND];
        if ($args['mode'] === KbTableWrite::MODE_UPDATE) {
            $args['cle'] = (string)($d['key'] ?? '');
            $args['cle_source'] = trim((string)($d['key_source'] ?? '')) !== '' ? (string)$d['key_source'] : (string)($d['key'] ?? '');
            $args['ajouter_absents'] = !empty($d['add_missing']);
            $args['colonnes'] = WorkTableOps::keys(WorkTableView::visibleColumns($sourceColumns));
        }
        return $args;
    }

    // ==================================================================
    // Étapes : pièces Tableau lues, pièce Tableau remise
    // ==================================================================

    /**
     * La pièce Tableau d'une étape tirée de la table de travail que désigne
     * sa réponse (« TABLE : nom »), ou null : la réponse ne désigne rien, ou
     * une table qui n'existe pas dans la conversation de l'exécution (la
     * lecture ordinaire du tableau Markdown prend alors le relais).
     *
     * @return array{rows: list<list<string>>, table: array}|null
     */
    public static function fromReply(string $reply, int $convId, ?WorkTableStore $store = null): ?array
    {
        $name = self::tableRef($reply);
        if ($name === null || $convId <= 0) {
            return null;
        }
        try {
            $store ??= new SqlWorkTableStore();
            $table = $store->find($convId, $name);
            if ($table === null) {
                return null;
            }
            $columns = (array)$table['columns'];
            $rows    = $store->rows((int)$table['id']);
            // Une VUE d'une table permanente n'a pas de lignes à elle : elles
            // sont recalculées sur sa table (lue dans sa base — l'étape l'avait
            // engagée pour l'établir).
            if (WorkTableView::isView($table)) {
                $kbStore = new KbTableStore();
                $perm    = $kbStore->find(WorkTableView::viewKb($table));
                if ($perm === null) {
                    return null;
                }
                $data = WorkTableView::viewData($perm, WorkTableView::viewSteps($table), $kbStore);
                if ($data['error'] !== '') {
                    return null;
                }
                $columns = $data['columns'];
                $rows    = WorkTableRecipe::strip($data['rows']);
            }
            $read = self::pieceRows($columns, $rows);
        } catch (\Throwable $e) {
            error_log('[Workflows] table de travail « ' . $name . ' » illisible : ' . $e->getMessage());
            return null;
        }
        if (count($read['rows']) < 1 || $read['rows'][0] === []) {
            return null;
        }
        return [
            'rows'  => $read['rows'],
            'table' => [
                'name'  => (string)$table['name'],
                'title' => (string)$table['title'],
                'total' => $read['total'],
                'cut'   => $read['cut'],
                'stamp' => WorkTableOps::stateStamp($table),
            ],
        ];
    }

    /**
     * Range les pièces Tableau lues par une étape en tables de travail de la
     * conversation de l'exécution (une pièce venue d'une table de travail
     * encore là la désigne telle quelle). Rend le nom de chaque table → le
     * nom de sa pièce, pour la consigne. Ne lève jamais.
     *
     * @param list<array{key: string, name: string, rows: int, table: ?string}> $list
     * @return array<string, string>
     */
    public static function materialize(int $convId, int $userId, int $runId, array $list, ?WorkTableStore $store = null): array
    {
        if ($list === [] || $convId <= 0 || $runId <= 0) {
            return [];
        }
        $out = [];
        try {
            $store ??= new SqlWorkTableStore();
            foreach (array_slice($list, 0, self::MATERIALIZE_MAX) as $item) {
                $ref = (string)($item['table'] ?? '');
                if ($ref !== '' && $store->find($convId, $ref) !== null) {
                    $out[$ref] = (string)$item['name'];
                    continue;
                }
                $art = WorkflowRun::artifact($runId, (string)$item['key']);
                if ($art === null) {
                    continue;
                }
                $table = self::pieceAsTable(['form' => 'table'], (array)$art['content']);
                if ($table === null || $table['rows'] === []) {
                    continue;
                }
                $name = self::freeName($store, $convId, WorkTableOps::slug((string)$item['name']) ?: 'piece', (string)$item['key']);
                $store->save($convId, $userId, $name, mb_substr((string)$item['name'], 0, WorkTableOps::TITLE_MAX), $table['columns'], $table['rows'],
                    ['kind' => 'piece', 'run' => $runId, 'key' => (string)$item['key']]);
                $out[$name] = (string)$item['name'];
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] pièces Tableau non rangées en tables : ' . $e->getMessage());
        }
        return $out;
    }

    /** Un nom libre : le même s'il porte déjà cette pièce, sinon suffixé. */
    private static function freeName(WorkTableStore $store, int $convId, string $base, string $key): string
    {
        $name = $base;
        for ($n = 2; $n < 50; $n++) {
            $existing = $store->find($convId, $name);
            if ($existing === null || (($existing['origin']['kind'] ?? '') === 'piece' && (string)($existing['origin']['key'] ?? '') === $key)) {
                return $name;
            }
            $name = substr($base, 0, WorkTableOps::NAME_MAX - 3) . '_' . $n;
        }
        return $name;
    }

    // ==================================================================
    // Boucles : les lignes d'une table permanente
    // ==================================================================

    /**
     * La table permanente d'une boucle, en grille (en-tête = libellés des
     * colonnes visibles) — accessible à la personne au moment T (partage,
     * « Uniquement pour moi », base active), jamais par son seul identifiant.
     *
     * @return array{header: list<string>, rows: list<list<string>>, header_row: int, table: string}
     */
    public static function loopGrid(int $kbId, array $user): array
    {
        $kb = self::selectableTable($kbId, $user);
        $store = new KbTableStore();
        $table = $store->find($kbId);
        if ($table === null) {
            throw new \RuntimeException(t('La table « {nom} » n’existe plus.', ['nom' => (string)$kb['name']]));
        }
        $read = self::pieceRows((array)$table['columns'], $store->rows($kbId), KbTableStore::MAX_ROWS);
        KnowledgeBase::markUsed([$kbId]);
        $header = (array)array_shift($read['rows']);
        return ['header' => $header, 'rows' => $read['rows'], 'header_row' => 1, 'table' => (string)$kb['name'], 'sheet' => '', 'sheets' => []];
    }

    /**
     * Tables que la personne peut faire parcourir (bases de nature « table »
     * de ses choix, interrogeables), avec leurs colonnes visibles : l'éditeur
     * les reprend comme champs de l'élément. Ne lève jamais.
     *
     * @param array<int, array> $kbs choix de la personne (ScheduledTask::choicesFor)
     * @return list<array{id: int, name: string, rows: int, columns: list<array{label: string, type: string}>}>
     */
    public static function loopTables(array $kbs): array
    {
        $ids = [];
        foreach ($kbs as $id => $kb) {
            if (!empty($kb['table']) && ($kb['reason'] ?? null) === null) {
                $ids[] = (int)$id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $out = [];
        try {
            $tables = (new KbTableStore())->findMany($ids);
            foreach ($ids as $id) {
                $t = $tables[$id] ?? null;
                if ($t === null) {
                    continue;
                }
                $out[] = [
                    'id'      => $id,
                    'name'    => (string)$kbs[$id]['name'],
                    'rows'    => (int)($t['rows'] ?? 0),
                    'columns' => array_map(static fn(array $c): array => ['label' => (string)$c['label'], 'type' => self::ficheType((string)($c['type'] ?? 'text'))],
                        WorkTableView::visibleColumns((array)$t['columns'])),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] tables à parcourir : ' . $e->getMessage());
        }
        return $out;
    }

    /** Type d'une colonne de table → type d'un champ de Fiche. */
    public static function ficheType(string $type): string
    {
        return match ($type) {
            'number' => 'number',
            'date'   => 'date',
            default  => 'text',
        };
    }

    /** La base de nature « table » si la personne peut l'interroger, sinon une erreur qui la nomme. */
    private static function selectableTable(int $kbId, array $user): array
    {
        foreach (KnowledgeBase::selectableForUser($user) as $kb) {
            if ((int)$kb['id'] === $kbId) {
                if (!KnowledgeBase::isTable($kb)) {
                    break;
                }
                if (empty($kb['is_active'] ?? 1)) {
                    throw new \RuntimeException(t('La table « {nom} » est désactivée.', ['nom' => (string)$kb['name']]));
                }
                return $kb;
            }
        }
        throw new \RuntimeException(t('Cette table n’existe plus ou ne vous est plus partagée : choisissez-en une autre dans l’éditeur, publiez, puis reprenez.'));
    }

    // ==================================================================
    // Livraisons : écrire une pièce dans une table permanente
    // ==================================================================

    /**
     * Tables où une livraison peut écrire pour cette personne : celles
     * qu'elle gère, avec le droit « Bases — Modifier », ouvertes à
     * l'écriture par MIA (réglage de leur fiche).
     *
     * @return array<int, array{name: string, columns: list<array{key: string, label: string}>, rows: int}>
     */
    public static function deliveryTables(array $user): array
    {
        $out = [];
        try {
            if (!Role::userCan($user, 'knowledge_bases.edit')) {
                return [];
            }
            $tables = [];
            foreach (KnowledgeBase::forManager($user) as $kb) {
                if (KnowledgeBase::isTable($kb) && !KnowledgeBase::hiddenFrom($kb, $user)) {
                    $tables[(int)$kb['id']] = $kb;
                }
            }
            if ($tables === []) {
                return [];
            }
            foreach ((new KbTableStore())->findMany(array_keys($tables)) as $id => $t) {
                if (empty($t['mia_write'])) {
                    continue;
                }
                $out[(int)$id] = [
                    'name'    => (string)$tables[(int)$id]['name'],
                    'columns' => array_map(static fn(array $c): array => ['key' => (string)$c['key'], 'label' => (string)$c['label']],
                        WorkTableView::visibleColumns((array)$t['columns'])),
                    'rows'    => (int)($t['rows'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] tables de livraison : ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Mène une livraison dans une table : chaque pièce rangée en table de
     * travail de l'exécution, le changement calculé (KbTableWrite), une carte
     * déposée par pièce — laissée à la personne, ou approuvée par le moteur
     * (« sans validation ») par le chemin d'un clic : tout y est revérifié
     * au moment de l'accord, sur la table du moment.
     *
     * @param \Closure(string, array, array): int $deposit dépose une action (outil, arguments, aperçu) → son identifiant
     * @param \Closure(int): array                $decide  l'approuve → issue {status, error?}
     * @return array{status: string, action_ids?: list<int>, note?: string, error?: string}
     */
    public static function deliver(array $run, array $user, array $d, int $messageId, \Closure $deposit, \Closure $decide): array
    {
        $kbId = (int)($d['kb_id'] ?? 0);
        $kb = $kbId > 0 ? KnowledgeBase::findById($kbId) : null;
        $store = new KbTableStore();
        $table = $kb !== null && KnowledgeBase::isTable($kb) ? $store->find($kbId) : null;
        if ($table === null) {
            throw new \RuntimeException(t('la table n’existe plus.'));
        }
        if (!Role::userCan($user, 'knowledge_bases.edit') || !KnowledgeBase::manageableBy($kbId, $user)) {
            throw new \RuntimeException(t('vous ne gérez plus la table « {nom} ».', ['nom' => (string)$kb['name']]));
        }
        if (empty($table['mia_write'])) {
            throw new \RuntimeException(t('la table « {nom} » est en lecture seule pour MIA (réglage de sa fiche).', ['nom' => (string)$kb['name']]));
        }
        $pieces = WorkflowSpec::pieces($run['definition']);
        $artifacts = WorkflowLoop::view(WorkflowRun::artifacts($run['id']), [], 0);
        $convId = (int)$run['conversation_id'];
        $work = new SqlWorkTableStore();
        $shares = KnowledgeBase::sharesFor($kbId);
        $shared = count($shares['users'] ?? []) + count($shares['groups'] ?? []);
        $table['title'] = (string)$kb['name'];
        $ids = [];
        $notes = [];
        foreach ((array)($d['pieces'] ?? []) as $key) {
            $p = $pieces[$key] ?? null;
            $a = $artifacts[$key] ?? null;
            if ($p === null || $a === null) {
                continue;
            }
            $source = self::pieceAsTable($p, (array)$a['content']);
            if ($source === null || $source['rows'] === []) {
                $notes[] = t('la pièce « {nom} » n’a aucune ligne à écrire', ['nom' => $p['name']]);
                continue;
            }
            $name = substr(self::DELIVERY_PREFIX . (WorkTableOps::slug((string)$p['name']) ?: 'piece'), 0, WorkTableOps::NAME_MAX);
            $saved = $work->save($convId, (int)$user['id'], $name, mb_substr((string)$p['name'], 0, WorkTableOps::TITLE_MAX),
                $source['columns'], $source['rows'], ['kind' => 'livraison', 'run' => (int)$run['id'], 'key' => (string)$key]);
            $spec = KbTableWrite::spec((array)$table['columns'], (array)$saved['columns'], self::specArgs($d, (array)$saved['columns']));
            $plan = KbTableWrite::plan((array)$table['columns'], $store->rows($kbId), (array)$saved['columns'], $work->rows((int)$saved['id']), $spec);
            if (!KbTableWrite::changes($plan)) {
                $notes[] = $spec['mode'] === KbTableWrite::MODE_UPDATE && $plan['stats']['unmatched'] > 0
                    ? t('aucune ligne de « {piece} » ne se retrouve dans la table « {table} »', ['piece' => $p['name'], 'table' => (string)$kb['name']])
                    : t('la table « {nom} » porte déjà les valeurs de « {piece} »', ['nom' => (string)$kb['name'], 'piece' => $p['name']]);
                continue;
            }
            $preview = KbTableWrite::preview($table, $spec, $plan, (string)$p['name'], $shared);
            $preview['workflow'] = true;
            $ids[] = $deposit(WorkTableTools::PERM_WRITE, [
                'kb_id'        => $kbId,
                'table'        => (string)$kb['name'],
                'source_id'    => (int)$saved['id'],
                'source'       => (string)$saved['name'],
                'source_stamp' => WorkTableOps::stateStamp($saved),
                'spec'         => $spec,
            ], $preview);
        }
        if ($ids === []) {
            return ['status' => 'skipped', 'note' => $notes !== [] ? implode(' ; ', $notes) : t('aucune pièce à écrire')];
        }
        if (($d['mode'] ?? 'approve') === 'approve') {
            return ['status' => 'card', 'action_ids' => $ids];
        }
        foreach ($ids as $id) {
            $res = $decide($id);
            if (($res['status'] ?? '') === 'failed') {
                return $res + ['action_ids' => $ids];
            }
        }
        return ['status' => 'done', 'action_ids' => $ids];
    }
}
