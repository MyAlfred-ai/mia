<?php

declare(strict_types=1);

/**
 * Tables PERMANENTES — les lignes d'une base de connaissances de nature
 * « table » (KnowledgeBase::BACKEND_TABLE, migration 112).
 *
 * Une base = une table. Le nom, la description, le partage, les assistants
 * liés vivent sur la ligne `knowledge_bases` ; ici, les colonnes, les lignes
 * et leur HISTORIQUE (migration 116, règles dans KbTableHistory).
 *
 * Chaque état de la table est une version : ses lignes dans
 * knowledge_base_table_rows, sa fiche (qui, quand, quoi) dans
 * knowledge_base_table_versions. Deux sortes d'écritures :
 *   - d'ENSEMBLE (replace) — fichier importé, colonne supprimée, MIA après
 *     accord, livraison d'un workflow : un nouvel état est écrit ;
 *   - à la MAIN (setCell, addRow, deleteRow, setColumns) : la première
 *     retouche COPIE l'état en service dans un nouvel état, les suivantes de
 *     la même personne s'y ajoutent pendant un quart d'heure
 *     (KbTableHistory::joinsEdit).
 * Revenir à un état (restore) le copie dans un nouvel état ; undoRestore le
 * défait. Les états trop anciens sortent (KbTableHistory::retention).
 *
 * Plafonds : MAX_ROWS lignes, WorkTableOps::MAX_COLUMNS colonnes. Au-delà,
 * l'écriture est REFUSÉE (TooLargeException) — jamais coupée sans le dire.
 *
 * `find()` rend la table dans la forme d'une table de travail (id, name,
 * title, columns, rows, origin) : les opérations (WorkTableOps), la grille
 * (WorkTableView) et l'export la lisent telle quelle. Son `id` EST
 * l'identifiant de la base.
 */
final class KbTableStore implements WorkTableRows
{
    /** Lignes au plus dans une table permanente — dit à l'écran. */
    public const MAX_ROWS = 20000;

    /** Tables permanentes au plus par créateur (« Gérer » : sans limite). */
    public const MAX_PER_CREATOR = 10;

    /** Lignes insérées par requête. */
    private const INSERT_CHUNK = 200;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * La table d'une base, ou null (base d'une autre nature, base disparue).
     *
     * @return array{id:int, kb_id:int, name:string, title:string, columns:list<array>, rows:int, origin:array,
     *               version:int, mia_write:bool, updated_at:string, updated_age:?int}|null
     */
    public function find(int $kbId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT t.*, kb.name AS kb_name,
                    TIMESTAMPDIFF(SECOND, t.updated_at, NOW()) AS updated_age
               FROM knowledge_base_tables t
               JOIN knowledge_bases kb ON kb.id = t.kb_id
              WHERE t.kb_id = ?'
        );
        $stmt->execute([$kbId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Crée la table d'une base qui vient de naître.
     *
     * @param list<array>                 $columns WorkTableOps::columns()
     * @param list<array<string, string>> $rows    WorkTableOps::rows()
     * @throws KbTableTooLargeException
     */
    public function create(int $kbId, array $columns, array $rows, array $origin, int $userId): array
    {
        $columns = self::checkedColumns($columns);
        $rows    = self::checkedRows($rows);
        $db      = Database::getInstance();
        $own     = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $db->prepare(
                'INSERT INTO knowledge_base_tables (kb_id, columns_json, row_count, column_count, version, origin_json, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, NOW())'
            )->execute([$kbId, json_encode($columns, self::JSON), count($rows), count($columns), json_encode($origin, self::JSON), $userId ?: null]);
            $this->insertRows($kbId, 1, $rows);
            $this->addEntry($kbId, 1, KbTableHistory::change(KbTableHistory::KIND_CREATE, ['origin' => $origin]), $userId, $columns, count($rows));
            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $this->find($kbId) ?? [];
    }

    public function rows(int $tableId, int $offset = 0, ?int $limit = null): array
    {
        $sql = 'SELECT r.data FROM knowledge_base_table_rows r
                  JOIN knowledge_base_tables t ON t.kb_id = r.kb_id AND t.version = r.version
                 WHERE r.kb_id = ? ORDER BY r.position';
        if ($limit !== null || $offset > 0) {
            $sql .= ' LIMIT ' . max(0, (int)($limit ?? self::MAX_ROWS)) . ' OFFSET ' . max(0, $offset);
        }
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute([$tableId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $json) {
            $row = json_decode((string)$json, true);
            if (is_array($row)) {
                $out[] = array_map(static fn($v): string => is_scalar($v) ? (string)$v : '', $row);
            }
        }
        return $out;
    }

    /**
     * Écriture d'ENSEMBLE : le contenu est remplacé par un nouvel état,
     * l'état d'avant reste dans l'historique. `$change` (KbTableHistory::
     * change) dit ce qui s'est passé. `$origin` null = l'origine ne change pas
     * (une modification n'est pas un nouvel import). `$ifStamp` : n'écrit que
     * si la table est encore dans l'état lu (stamp()) — sinon rend la table
     * avec `stale => true`.
     *
     * @param array{kind:string, detail:array} $change
     * @throws KbTableTooLargeException
     */
    public function replace(int $kbId, array $columns, array $rows, ?array $origin, int $userId, array $change, ?string $ifStamp = null): ?array
    {
        $columns = self::checkedColumns($columns);
        $rows    = self::checkedRows($rows);
        $db      = Database::getInstance();
        $own     = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $cur = $this->lock($kbId);
            if ($cur === null) {
                if ($own) {
                    $db->rollBack();
                }
                return null;
            }
            if ($ifStamp !== null && self::stamp(self::hydrate($cur)) !== $ifStamp) {
                if ($own) {
                    $db->rollBack();
                }
                return ($this->find($kbId) ?? []) + ['stale' => true];
            }
            $this->ensureEntry($kbId, $cur);
            $next = $this->nextVersion($kbId, (int)$cur['version']);
            $this->insertRows($kbId, $next, $rows);
            $db->prepare(
                'UPDATE knowledge_base_tables
                    SET version = ?, columns_json = ?, row_count = ?, column_count = ?, origin_json = ?,
                        updated_by = ?, updated_at = NOW()
                  WHERE kb_id = ?'
            )->execute([
                $next, json_encode($columns, self::JSON), count($rows), count($columns),
                $origin === null ? $cur['origin_json'] : json_encode($origin, self::JSON),
                $userId ?: null, $kbId,
            ]);
            $this->addEntry($kbId, $next, $change, $userId, $columns, count($rows));
            $this->prune($kbId, $next);
            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $this->find($kbId);
    }

    /**
     * Revient à un état de l'historique : ses lignes sont COPIÉES dans un
     * nouvel état, en service. Rien n'est effacé — l'état quitté reste dans
     * l'historique, et undoRestore() défait le retour.
     *
     * @throws \RuntimeException état en service, état disparu ou plus conservé (message pour la personne)
     */
    public function restore(int $kbId, int $version, int $userId): ?array
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $cur = $this->lock($kbId);
            if ($cur === null) {
                $db->rollBack();
                return null;
            }
            if ($version === (int)$cur['version']) {
                throw new \RuntimeException(t('La table est déjà dans cet état.'));
            }
            $target = $this->entry($kbId, $version);
            if ($target === null || empty($target['rows_kept']) || !$this->hasRows($kbId, $version, (int)$target['row_count'])) {
                throw new \RuntimeException(t('Cet état n’est plus conservé : on ne peut plus y revenir.'));
            }
            $this->ensureEntry($kbId, $cur);
            $next = $this->nextVersion($kbId, (int)$cur['version']);
            $this->copyRows($kbId, $version, $next);
            $columns = json_decode((string)$target['columns_json'], true);
            $columns = is_array($columns) ? WorkTableOps::columns($columns) : [];
            $db->prepare(
                'UPDATE knowledge_base_tables
                    SET version = ?, columns_json = ?, row_count = ?, column_count = ?, updated_by = ?, updated_at = NOW()
                  WHERE kb_id = ?'
            )->execute([$next, json_encode($columns, self::JSON), (int)$target['row_count'], count($columns), $userId ?: null, $kbId]);
            $this->addEntry($kbId, $next, KbTableHistory::change(KbTableHistory::KIND_RESTORE, [
                'from'    => $version,
                'from_at' => (int)$target['created'],
                'prev'    => (int)$cur['version'],
            ]), $userId, $columns, (int)$target['row_count']);
            $this->prune($kbId, $next);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $this->find($kbId);
    }

    /**
     * Défait un retour en arrière : la table reprend l'état qu'elle avait
     * juste avant, et le retour sort de l'historique. Seulement pour la
     * personne qui l'a fait, dans le quart d'heure, si rien n'a été écrit
     * depuis (KbTableHistory::canUndo). Null sinon.
     */
    public function undoRestore(int $kbId, int $userId): ?array
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $cur = $this->lock($kbId);
            $entry = $cur !== null ? $this->entry($kbId, (int)$cur['version']) : null;
            $prevVersion = (int)(self::detailOf($entry)['prev'] ?? 0);
            $prev = $prevVersion > 0 ? $this->entry($kbId, $prevVersion) : null;
            if ($entry === null || !KbTableHistory::canUndo($entry, $prev, $userId, (int)$entry['age'])
                || !$this->hasRows($kbId, $prevVersion, (int)$prev['row_count'])) {
                $db->rollBack();
                return null;
            }
            $columns = json_decode((string)$prev['columns_json'], true);
            $columns = is_array($columns) ? WorkTableOps::columns($columns) : [];
            $db->prepare(
                'UPDATE knowledge_base_tables
                    SET version = ?, columns_json = ?, row_count = ?, column_count = ?, updated_by = ?, updated_at = NOW()
                  WHERE kb_id = ?'
            )->execute([$prevVersion, json_encode($columns, self::JSON), (int)$prev['row_count'], count($columns), $userId ?: null, $kbId]);
            $db->prepare('DELETE FROM knowledge_base_table_rows WHERE kb_id = ? AND version = ?')->execute([$kbId, (int)$cur['version']]);
            $db->prepare('DELETE FROM knowledge_base_table_versions WHERE kb_id = ? AND version = ?')->execute([$kbId, (int)$cur['version']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $this->find($kbId);
    }

    // ===================== Historique =====================

    /**
     * Les états de la table, le plus récent d'abord, avec le nom de qui les a
     * écrits. L'état en service y figure toujours (une table d'avant la
     * migration 116, restaurée d'une ancienne sauvegarde, est complétée).
     *
     * @return list<array{version:int, kind:string, user_id:int, user_name:string, detail:array, row_count:int,
     *                    column_count:int, rows_kept:bool, created:int, updated:int, age:int, columns_json:string}>
     */
    public function history(int $kbId): array
    {
        $cur = $this->find($kbId);
        if ($cur === null) {
            return [];
        }
        $rows = $this->entries($kbId);
        if (!in_array((int)$cur['version'], array_map(static fn(array $e): int => (int)$e['version'], $rows), true)) {
            $db = Database::getInstance();
            $db->beginTransaction();
            try {
                $locked = $this->lock($kbId);
                if ($locked !== null) {
                    $this->ensureEntry($kbId, $locked);
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('[Tables] état en service de la table #' . $kbId . ' non inscrit : ' . $e->getMessage());
            }
            $rows = $this->entries($kbId);
        }
        return $rows;
    }

    /** Un état de la table (forme de history()), ou null. */
    public function entry(int $kbId, int $version): ?array
    {
        foreach ($this->entries($kbId, $version) as $e) {
            return $e;
        }
        return null;
    }

    /** Toutes les lignes d'un état, dans leur ordre. */
    public function versionRows(int $kbId, int $version): array
    {
        return (new KbTableVersionRows($version))->rows($kbId);
    }

    // ===================== Retouches à la main =====================

    /** Corrige UNE cellule de la version en service. Faux si la ligne ou la colonne n'existe pas. */
    public function setCell(int $kbId, int $position, string $key, string $value, int $userId): bool
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $cur = $this->lock($kbId);
            if ($cur === null || !in_array($key, WorkTableOps::keys(self::columnsOf($cur)), true)) {
                $db->rollBack();
                return false;
            }
            $sel = $db->prepare('SELECT data FROM knowledge_base_table_rows WHERE kb_id = ? AND version = ? AND position = ?');
            $sel->execute([$kbId, (int)$cur['version'], $position]);
            $json = $sel->fetchColumn();
            if ($json === false) {
                $db->rollBack();
                return false;
            }
            $row   = json_decode((string)$json, true);
            $row   = is_array($row) ? $row : [];
            $value = WorkTableOps::cell($value);
            if ((string)($row[$key] ?? '') === $value) {
                // Rien ne change : ni écriture, ni état de plus.
                $db->rollBack();
                return true;
            }
            [$version, $detail] = $this->editVersion($kbId, $cur, $userId);
            $row[$key] = $value;
            $db->prepare('UPDATE knowledge_base_table_rows SET data = ? WHERE kb_id = ? AND version = ? AND position = ?')
                ->execute([json_encode($row, self::JSON), $kbId, $version, $position]);
            $this->touch($kbId, $userId);
            $this->saveEdit($kbId, $version, KbTableHistory::noteEdit($detail, 'cell', ['cell' => $position . ':' . $key]));
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ajoute une ligne en fin de table. Rend son rang.
     *
     * @param array<string, string> $row
     * @throws KbTableTooLargeException
     */
    public function addRow(int $kbId, array $row, int $userId): ?int
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $cur = $this->lock($kbId);
            if ($cur === null) {
                $db->rollBack();
                return null;
            }
            $count = (int)$cur['row_count'];
            if ($count >= self::MAX_ROWS) {
                throw new KbTableTooLargeException($count + 1, 0);
            }
            $clean = [];
            foreach (WorkTableOps::keys(self::columnsOf($cur)) as $key) {
                $clean[$key] = WorkTableOps::cell($row[$key] ?? '');
            }
            [$version, $detail] = $this->editVersion($kbId, $cur, $userId);
            $this->insertRows($kbId, $version, [$count => $clean]);
            $db->prepare('UPDATE knowledge_base_tables SET row_count = row_count + 1 WHERE kb_id = ?')->execute([$kbId]);
            $this->touch($kbId, $userId);
            $this->saveEdit($kbId, $version, KbTableHistory::noteEdit($detail, 'row_added'));
            $db->commit();
            return $count;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Supprime une ligne ; les suivantes remontent d'un rang. */
    public function deleteRow(int $kbId, int $position, int $userId): bool
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $cur = $this->lock($kbId);
            $exists = $db->prepare('SELECT 1 FROM knowledge_base_table_rows WHERE kb_id = ? AND version = ? AND position = ?');
            $exists->execute([$kbId, (int)($cur['version'] ?? 0), $position]);
            if ($cur === null || $exists->fetchColumn() === false) {
                $db->rollBack();
                return false;
            }
            [$version, $detail] = $this->editVersion($kbId, $cur, $userId);
            $db->prepare('DELETE FROM knowledge_base_table_rows WHERE kb_id = ? AND version = ? AND position = ?')
                ->execute([$kbId, $version, $position]);
            // ORDER BY : chaque rang libère celui qu'occupe le suivant — sans
            // lui, la clé primaire (kb_id, version, position) se heurterait.
            $db->prepare('UPDATE knowledge_base_table_rows SET position = position - 1
                           WHERE kb_id = ? AND version = ? AND position > ? ORDER BY position ASC')
                ->execute([$kbId, $version, $position]);
            $db->prepare('UPDATE knowledge_base_tables SET row_count = GREATEST(row_count, 1) - 1 WHERE kb_id = ?')->execute([$kbId]);
            $this->touch($kbId, $userId);
            $this->saveEdit($kbId, $version, KbTableHistory::noteEdit($detail, 'row_deleted'));
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Change la LISTE des colonnes sans toucher aux lignes : en ajouter une
     * (ses cellules sont vides), la renommer (sa clé ne bouge pas), changer
     * son type. Retirer une colonne passe par replace() : c'est une écriture
     * d'ensemble.
     *
     * @throws KbTableTooLargeException
     */
    public function setColumns(int $kbId, array $columns, int $userId): ?array
    {
        $columns = self::checkedColumns($columns);
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $cur = $this->lock($kbId);
            if ($cur === null) {
                $db->rollBack();
                return null;
            }
            $before = self::columnsOf($cur);
            if (json_encode($before, self::JSON) === json_encode($columns, self::JSON)) {
                $db->rollBack();
                return $this->find($kbId);
            }
            [$version, $detail] = $this->editVersion($kbId, $cur, $userId);
            $db->prepare('UPDATE knowledge_base_tables SET columns_json = ?, column_count = ? WHERE kb_id = ?')
                ->execute([json_encode($columns, self::JSON), count($columns), $kbId]);
            $this->touch($kbId, $userId);
            $this->saveEdit($kbId, $version, KbTableHistory::noteEdit($detail, 'columns', ['before' => $before, 'after' => $columns]));
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $this->find($kbId);
    }

    /** MIA peut-elle écrire dans la table (sous carte d'approbation) ? Ne date pas la table. */
    public function setMiaWrite(int $kbId, bool $on): void
    {
        Database::getInstance()
            ->prepare('UPDATE knowledge_base_tables SET mia_write = ? WHERE kb_id = ?')
            ->execute([$on ? 1 : 0, $kbId]);
    }

    /**
     * Empreinte de l'état d'une table : une écriture préparée sur un état
     * (carte d'approbation) ne s'applique pas à un autre. Pure.
     */
    public static function stamp(array $table): string
    {
        return sha1(implode('|', [
            (int)($table['version'] ?? 0),
            (string)($table['updated_at'] ?? ''),
            (int)($table['rows'] ?? 0),
            implode(',', WorkTableOps::keys((array)($table['columns'] ?? []))),
        ]));
    }

    /**
     * Fiches légères des tables de plusieurs bases (liste, sélecteur du
     * chat) : colonnes comprises, sans une ligne. Une requête.
     *
     * @param list<int> $kbIds
     * @return array<int, array> identifiant de base → table (forme de find())
     */
    public function findMany(array $kbIds): array
    {
        $kbIds = array_values(array_unique(array_filter(array_map('intval', $kbIds), static fn(int $i): bool => $i > 0)));
        if ($kbIds === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT t.*, kb.name AS kb_name,
                    TIMESTAMPDIFF(SECOND, t.updated_at, NOW()) AS updated_age
               FROM knowledge_base_tables t
               JOIN knowledge_bases kb ON kb.id = t.kb_id
              WHERE t.kb_id IN (' . implode(',', array_fill(0, count($kbIds), '?')) . ')'
        );
        $stmt->execute($kbIds);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['kb_id']] = self::hydrate($row);
        }
        return $out;
    }

    /**
     * Tables permanentes parmi des bases ENGAGÉES (portées par un assistant,
     * cochées par la personne), dans l'ordre de ces bases : la forme de
     * find(), colonnes comprises, sans une ligne. Une requête au plus —
     * aucune s'il n'y a pas de table parmi elles.
     *
     * @param list<array> $kbs lignes `knowledge_bases`, déjà passées par les portes d'accès
     * @return list<array>
     */
    public static function fromKbs(array $kbs): array
    {
        $tableKbs = KnowledgeBase::partitionByBackend($kbs)[2];
        if ($tableKbs === []) {
            return [];
        }
        $found = (new self())->findMany(array_map(static fn(array $kb): int => (int)$kb['id'], $tableKbs));
        $out   = [];
        foreach ($tableKbs as $kb) {
            if (isset($found[(int)$kb['id']])) {
                $out[] = $found[(int)$kb['id']];
            }
        }
        return $out;
    }

    /**
     * Tables permanentes ENGAGÉES dans une conversation, pour l'ÉCRAN (ligne
     * « Voir » sous une réponse, puce, volet « Tables ») : celles de son
     * assistant et celles que la personne a cochées, avec les MÊMES portes
     * que le tour de chat (KnowledgeBase::forProfileAndUser,
     * ConversationKnowledgeBase::selectedKbsForTurn) — une table décochée,
     * désactivée ou dont le partage est retiré n'y figure plus. Ne lève
     * jamais : sans elles, la conversation s'affiche quand même.
     *
     * @return list<array>
     */
    public static function engaged(int $convId, ?array $user, int $profileId = 0): array
    {
        if ($convId <= 0 || empty($user['id'])) {
            return [];
        }
        try {
            return self::fromKbs(ConversationKnowledgeBase::mergeForTurn(
                $profileId > 0 ? KnowledgeBase::forProfileAndUser($profileId, $user) : [],
                ConversationKnowledgeBase::selectedKbsForTurn($convId, $user)
            ));
        } catch (\Throwable $e) {
            error_log('[Tables] tables permanentes de la conversation ' . $convId . ' illisibles : ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Crée une table permanente : la base (nature « table ») et ses lignes,
     * dans une même transaction — jamais une base sans table.
     *
     * L'appelant a vérifié le droit de créer ; le quota des créateurs est
     * revérifié ici (« Bases de connaissances — Gérer » : sans limite).
     *
     * @param array $import rendu par KbTableImport (columns, rows, origin)
     * @return int identifiant de la base
     * @throws \RuntimeException quota atteint, nom vide (message pour la personne)
     * @throws KbTableTooLargeException
     */
    public function createBase(array $user, string $name, ?string $description, array $import, bool $private = false, bool $public = false): int
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new \RuntimeException(t('Donnez un nom à la table.'));
        }
        $userId = (int)($user['id'] ?? 0);
        if (!Role::userCan($user, 'knowledge_bases.manage') && self::countByCreator($userId) >= self::MAX_PER_CREATOR) {
            throw new \RuntimeException(t('Limite atteinte : vous ne pouvez pas dépasser {n} tables. Supprimez-en une pour en créer une autre.', [
                'n' => self::MAX_PER_CREATOR,
            ]));
        }
        // Plafonds vérifiés AVANT d'écrire quoi que ce soit.
        $columns = self::checkedColumns((array)($import['columns'] ?? []));
        $rows    = self::checkedRows((array)($import['rows'] ?? []));

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $kbId = KnowledgeBase::create([
                'name'        => mb_substr($name, 0, 190),
                'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
                'backend'     => KnowledgeBase::BACKEND_TABLE,
                'is_active'   => 1,
                'is_private'  => $private,
                'is_public'   => $public,
                'created_by'  => $userId,
            ]);
            $this->create($kbId, $columns, $rows, (array)($import['origin'] ?? []), $userId);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $kbId;
    }

    /** Tables permanentes créées par ce compte (quota des créateurs). */
    public static function countByCreator(int $userId): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM knowledge_bases WHERE created_by = ? AND backend = ?'
        );
        $stmt->execute([$userId, KnowledgeBase::BACKEND_TABLE]);
        return (int)$stmt->fetchColumn();
    }

    // ===================== Interne =====================

    /** Verrouille la fiche le temps d'une écriture. */
    private function lock(int $kbId): ?array
    {
        $sel = Database::getInstance()->prepare('SELECT * FROM knowledge_base_tables WHERE kb_id = ? FOR UPDATE');
        $sel->execute([$kbId]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function touch(int $kbId, int $userId): void
    {
        Database::getInstance()
            ->prepare('UPDATE knowledge_base_tables SET updated_by = ?, updated_at = NOW() WHERE kb_id = ?')
            ->execute([$userId ?: null, $kbId]);
    }

    /**
     * L'état où écrire une retouche à la main : celui en service s'il est fait
     * des retouches en cours de la même personne (KbTableHistory::joinsEdit),
     * sinon une COPIE de l'état en service, qui devient l'état en service.
     * Dans la transaction de l'appelant, fiche verrouillée.
     *
     * @return array{0:int, 1:array} version, détail de l'état regroupé
     */
    private function editVersion(int $kbId, array $cur, int $userId): array
    {
        $version = (int)$cur['version'];
        $entry   = $this->entry($kbId, $version);
        if ($entry !== null && KbTableHistory::joinsEdit($entry, $userId, (int)$entry['since_update'])) {
            return [$version, (array)$entry['detail']];
        }
        $this->ensureEntry($kbId, $cur);
        $next = $this->nextVersion($kbId, $version);
        $this->copyRows($kbId, $version, $next);
        Database::getInstance()->prepare('UPDATE knowledge_base_tables SET version = ? WHERE kb_id = ?')->execute([$next, $kbId]);
        $this->addEntry($kbId, $next, KbTableHistory::change(KbTableHistory::KIND_EDIT), $userId, self::columnsOf($cur), (int)$cur['row_count']);
        $this->prune($kbId, $next);
        return [$next, []];
    }

    /** Recopie dans l'état regroupé son détail, et les colonnes et le compte de la table. */
    private function saveEdit(int $kbId, int $version, array $detail): void
    {
        Database::getInstance()->prepare(
            'UPDATE knowledge_base_table_versions v
               JOIN knowledge_base_tables t ON t.kb_id = v.kb_id
                SET v.detail_json = ?, v.columns_json = t.columns_json, v.row_count = t.row_count,
                    v.column_count = t.column_count, v.updated_at = NOW()
              WHERE v.kb_id = ? AND v.version = ?'
        )->execute([json_encode($detail, self::JSON), $kbId, $version]);
    }

    /** Inscrit un état. */
    private function addEntry(int $kbId, int $version, array $change, int $userId, array $columns, int $rows): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO knowledge_base_table_versions
                (kb_id, version, kind, user_id, detail_json, columns_json, row_count, column_count, rows_kept, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([
            $kbId, $version, (string)($change['kind'] ?? KbTableHistory::KIND_EARLIER), $userId > 0 ? $userId : null,
            json_encode((array)($change['detail'] ?? []), self::JSON), json_encode($columns, self::JSON), $rows, count($columns),
        ]);
    }

    /**
     * L'état en service est-il inscrit ? Sinon (table d'avant la migration
     * 116 revenue d'une sauvegarde, écriture interrompue), il l'est comme
     * « antérieur à l'historique » : on pourra y revenir.
     */
    private function ensureEntry(int $kbId, array $cur): void
    {
        Database::getInstance()->prepare(
            'INSERT IGNORE INTO knowledge_base_table_versions
                (kb_id, version, kind, user_id, detail_json, columns_json, row_count, column_count, rows_kept, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            $kbId, (int)$cur['version'], KbTableHistory::KIND_EARLIER, '{}', (string)$cur['columns_json'],
            (int)$cur['row_count'], (int)$cur['column_count'], (string)$cur['updated_at'], (string)$cur['updated_at'],
        ]);
    }

    /** Numéro du prochain état : au-delà de tout ce que la table a jamais porté. */
    private function nextVersion(int $kbId, int $current): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT GREATEST(
                COALESCE((SELECT MAX(version) FROM knowledge_base_table_versions WHERE kb_id = ?), 0),
                COALESCE((SELECT MAX(version) FROM knowledge_base_table_rows WHERE kb_id = ?), 0))'
        );
        $stmt->execute([$kbId, $kbId]);
        return max($current, (int)$stmt->fetchColumn()) + 1;
    }

    /** Copie les lignes d'un état dans un autre (côté serveur, sans les lire). */
    private function copyRows(int $kbId, int $from, int $to): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO knowledge_base_table_rows (kb_id, version, position, data)
             SELECT kb_id, ?, position, data FROM knowledge_base_table_rows WHERE kb_id = ? AND version = ?'
        )->execute([$to, $kbId, $from]);
    }

    /** Les lignes d'un état sont-elles encore là ? */
    private function hasRows(int $kbId, int $version, int $expected): bool
    {
        if ($expected === 0) {
            return true;
        }
        $stmt = Database::getInstance()->prepare('SELECT 1 FROM knowledge_base_table_rows WHERE kb_id = ? AND version = ? LIMIT 1');
        $stmt->execute([$kbId, $version]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Retire ce qui sort de l'historique (KbTableHistory::retention), et les
     * lignes d'états qui n'y figurent plus. Dans la transaction de l'appelant.
     */
    private function prune(int $kbId, int $current): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT version, kind, UNIX_TIMESTAMP(created_at) AS created, rows_kept, UNIX_TIMESTAMP() AS now
               FROM knowledge_base_table_versions WHERE kb_id = ?'
        );
        $stmt->execute([$kbId]);
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $now     = $entries !== [] ? (int)$entries[0]['now'] : time();
        $plan    = KbTableHistory::retention(array_map(static fn(array $e): array => [
            'version' => (int)$e['version'], 'kind' => (string)$e['kind'], 'created' => (int)$e['created'], 'rows_kept' => (int)$e['rows_kept'] === 1,
        ], $entries), $current, $now);
        $gone = array_merge($plan['drop'], $plan['empty']);
        if ($gone !== []) {
            $in = implode(',', array_fill(0, count($gone), '?'));
            $db->prepare('DELETE FROM knowledge_base_table_rows WHERE kb_id = ? AND version IN (' . $in . ')')->execute(array_merge([$kbId], $gone));
        }
        if ($plan['drop'] !== []) {
            $in = implode(',', array_fill(0, count($plan['drop']), '?'));
            $db->prepare('DELETE FROM knowledge_base_table_versions WHERE kb_id = ? AND version IN (' . $in . ')')->execute(array_merge([$kbId], $plan['drop']));
        }
        if ($plan['empty'] !== []) {
            $in = implode(',', array_fill(0, count($plan['empty']), '?'));
            $db->prepare('UPDATE knowledge_base_table_versions SET rows_kept = 0 WHERE kb_id = ? AND version IN (' . $in . ')')->execute(array_merge([$kbId], $plan['empty']));
        }
        // Lignes d'aucun état gardé (version d'avant l'historique, écriture
        // interrompue) : elles ne servent plus à rien.
        $db->prepare(
            'DELETE FROM knowledge_base_table_rows
              WHERE kb_id = ? AND version <> ?
                AND version NOT IN (SELECT v.version FROM knowledge_base_table_versions v WHERE v.kb_id = ? AND v.rows_kept = 1)'
        )->execute([$kbId, $current, $kbId]);
    }

    /** Les états inscrits (tous, ou un seul), le plus récent d'abord. */
    private function entries(int $kbId, ?int $version = null): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT v.version, v.kind, v.user_id, u.name AS user_name, v.detail_json, v.columns_json,
                    v.row_count, v.column_count, v.rows_kept,
                    UNIX_TIMESTAMP(v.created_at) AS created, UNIX_TIMESTAMP(v.updated_at) AS updated,
                    TIMESTAMPDIFF(SECOND, v.created_at, NOW()) AS age,
                    TIMESTAMPDIFF(SECOND, v.updated_at, NOW()) AS since_update
               FROM knowledge_base_table_versions v
               LEFT JOIN users u ON u.id = v.user_id
              WHERE v.kb_id = ?' . ($version !== null ? ' AND v.version = ?' : '') . '
              ORDER BY v.version DESC'
        );
        $stmt->execute($version !== null ? [$kbId, $version] : [$kbId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'version'      => (int)$r['version'],
                'kind'         => (string)$r['kind'],
                'user_id'      => (int)($r['user_id'] ?? 0),
                'user_name'    => (string)($r['user_name'] ?? ''),
                'detail'       => self::detailOf($r),
                'columns_json' => (string)$r['columns_json'],
                'row_count'    => (int)$r['row_count'],
                'column_count' => (int)$r['column_count'],
                'rows_kept'    => (int)$r['rows_kept'] === 1,
                'created'      => (int)$r['created'],
                'updated'      => (int)$r['updated'],
                'age'          => (int)$r['age'],
                'since_update' => (int)$r['since_update'],
            ];
        }
        return $out;
    }

    private static function detailOf(?array $entry): array
    {
        if ($entry === null) {
            return [];
        }
        $d = $entry['detail'] ?? $entry['detail_json'] ?? null;
        if (is_string($d)) {
            $d = json_decode($d, true);
        }
        return is_array($d) ? $d : [];
    }

    /** @param array<int, array<string, string>> $rows rang → ligne */
    private function insertRows(int $kbId, int $version, array $rows): void
    {
        $db = Database::getInstance();
        foreach (array_chunk($rows, self::INSERT_CHUNK, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $position => $row) {
                $values[] = '(?, ?, ?, ?)';
                array_push($params, $kbId, $version, (int)$position, json_encode($row, self::JSON));
            }
            $db->prepare('INSERT INTO knowledge_base_table_rows (kb_id, version, position, data) VALUES ' . implode(', ', $values))->execute($params);
        }
    }

    /** @throws KbTableTooLargeException */
    private static function checkedColumns(array $columns): array
    {
        // Compté AVANT la normalisation, qui s'arrête d'elle-même au plafond.
        if (count($columns) > WorkTableOps::MAX_COLUMNS) {
            throw new KbTableTooLargeException(0, count($columns));
        }
        return WorkTableOps::columns($columns);
    }

    /** @throws KbTableTooLargeException */
    private static function checkedRows(array $rows): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new KbTableTooLargeException(count($rows), 0);
        }
        return array_values($rows);
    }

    private static function columnsOf(array $row): array
    {
        $columns = json_decode((string)($row['columns_json'] ?? ''), true);
        return is_array($columns) ? WorkTableOps::columns($columns) : [];
    }

    private static function hydrate(array $row): array
    {
        $origin = json_decode((string)($row['origin_json'] ?? ''), true);
        $title  = (string)($row['kb_name'] ?? '');
        return [
            'id'          => (int)$row['kb_id'],
            'kb_id'       => (int)$row['kb_id'],
            'name'        => WorkTableOps::slug($title) ?: 'table_' . (int)$row['kb_id'],
            'title'       => $title,
            'columns'     => self::columnsOf($row),
            'rows'        => (int)$row['row_count'],
            'origin'      => is_array($origin) ? $origin : [],
            'version'     => (int)$row['version'],
            'mia_write'   => (int)($row['mia_write'] ?? 0) === 1,
            'updated_at'  => (string)($row['updated_at'] ?? ''),
            'updated_age' => isset($row['updated_age']) ? (int)$row['updated_age'] : null,
            'permanent'   => true,
        ];
    }
}

/**
 * Les lignes d'UN état de l'historique, lues comme une table : la grille
 * (WorkTableView::page) montre un état passé sans savoir que c'en est un.
 */
final class KbTableVersionRows implements WorkTableRows
{
    public function __construct(private readonly int $version)
    {
    }

    public function rows(int $tableId, int $offset = 0, ?int $limit = null): array
    {
        $sql = 'SELECT data FROM knowledge_base_table_rows WHERE kb_id = ? AND version = ? ORDER BY position';
        if ($limit !== null || $offset > 0) {
            $sql .= ' LIMIT ' . max(0, (int)($limit ?? KbTableStore::MAX_ROWS)) . ' OFFSET ' . max(0, $offset);
        }
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute([$tableId, $this->version]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $json) {
            $row = json_decode((string)$json, true);
            if (is_array($row)) {
                $out[] = array_map(static fn($v): string => is_scalar($v) ? (string)$v : '', $row);
            }
        }
        return $out;
    }
}

/**
 * Une table permanente refusée parce qu'elle dépasse un plafond : le nombre
 * trouvé est dit, rien n'est coupé.
 */
final class KbTableTooLargeException extends \RuntimeException
{
    public function __construct(public readonly int $rowsFound, public readonly int $columnsFound)
    {
        parent::__construct($columnsFound > 0
            ? t('Cette table compterait {n} colonnes : {max} au plus.', ['n' => $columnsFound, 'max' => WorkTableOps::MAX_COLUMNS])
            : t('Cette table compterait {n} lignes : {max} au plus. Rien n\'a été écrit.', [
                'n' => Format::credits($rowsFound), 'max' => Format::credits(KbTableStore::MAX_ROWS),
            ]));
    }
}
