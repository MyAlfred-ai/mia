<?php

declare(strict_types=1);

/**
 * Tables de travail en base (migration 111 : `work_tables`, `work_table_rows`).
 *
 * Une table appartient à UNE conversation : toute lecture passe par
 * l'identifiant de la conversation, jamais par celui de la table seul — la
 * table d'une autre conversation n'existe pas pour celle-ci.
 *
 * `save` remplace d'un bloc (une transaction) : une table à moitié écrite
 * ferait compter faux, sans une erreur.
 */
final class SqlWorkTableStore implements WorkTableStore
{
    /** Lignes insérées par requête. */
    private const INSERT_CHUNK = 200;
    /**
     * Brouillons internes (WorkTableOps::isHidden : nom commençant par « _ »,
     * qu'aucun nom donné par le modèle ne peut porter) : ni listés, ni
     * comptés, ni nommés — seul le code qui les a créés les retrouve, par
     * leur nom ou leur identifiant.
     */
    private const HIDDEN_LIKE = "'\\_%'";

    public function all(int $conversationId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM work_tables WHERE conversation_id = ? AND name NOT LIKE ' . self::HIDDEN_LIKE . ' ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([$conversationId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $conversationId, string $name): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM work_tables WHERE conversation_id = ? AND name = ?');
        $stmt->execute([$conversationId, $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function findById(int $conversationId, int $tableId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM work_tables WHERE conversation_id = ? AND id = ?');
        $stmt->execute([$conversationId, $tableId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function save(int $conversationId, int $userId, string $name, string $title, array $columns, array $rows, array $origin = []): array
    {
        $db   = Database::getInstance();
        $rows = array_slice(array_values($rows), 0, WorkTableOps::MAX_ROWS);
        $existing = $this->find($conversationId, $name);
        if ($existing === null && $this->count($conversationId) >= WorkTableOps::MAX_TABLES) {
            throw new \RuntimeException('Cette conversation compte déjà ' . WorkTableOps::MAX_TABLES
                . ' tables de travail. Réutilise le nom d\'une table devenue inutile (elle sera remplacée).');
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        // L'appelant peut déjà tenir une transaction (job repris) : PDO n'en imbrique pas.
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            if ($existing !== null) {
                $tableId = (int)$existing['id'];
                $db->prepare('DELETE FROM work_table_rows WHERE table_id = ?')->execute([$tableId]);
                $db->prepare('UPDATE work_tables SET title = ?, columns_json = ?, row_count = ?, origin_json = ?, user_id = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$title, json_encode($columns, $flags), count($rows), json_encode($origin, $flags), $userId, $tableId]);
            } else {
                $db->prepare('INSERT INTO work_tables (conversation_id, user_id, name, title, columns_json, row_count, origin_json) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$conversationId, $userId, $name, $title, json_encode($columns, $flags), count($rows), json_encode($origin, $flags)]);
                $tableId = (int)$db->lastInsertId();
            }
            foreach (array_chunk($rows, self::INSERT_CHUNK, true) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $position => $row) {
                    $values[] = '(?, ?, ?)';
                    array_push($params, $tableId, (int)$position, json_encode($row, $flags));
                }
                $db->prepare('INSERT INTO work_table_rows (table_id, position, data) VALUES ' . implode(', ', $values))->execute($params);
            }
            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $this->findById($conversationId, $tableId) ?? [];
    }

    public function rows(int $tableId, int $offset = 0, ?int $limit = null): array
    {
        $sql = 'SELECT data FROM work_table_rows WHERE table_id = ? ORDER BY position';
        if ($limit !== null || $offset > 0) {
            $sql .= ' LIMIT ' . max(0, (int)($limit ?? WorkTableOps::MAX_ROWS)) . ' OFFSET ' . max(0, $offset);
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

    public function patch(int $conversationId, int $tableId, array $columns, array $rowsByPosition, ?string $ifStamp = null): ?array
    {
        $db    = Database::getInstance();
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $own   = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            // La ligne de la table est verrouillée le temps de l'écriture :
            // l'empreinte lue est celle sur laquelle on écrit.
            $sel = $db->prepare('SELECT * FROM work_tables WHERE conversation_id = ? AND id = ? FOR UPDATE');
            $sel->execute([$conversationId, $tableId]);
            $row = $sel->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                if ($own) {
                    $db->rollBack();
                }
                return null;
            }
            $current = self::hydrate($row);
            if ($ifStamp !== null && WorkTableOps::stateStamp($current) !== $ifStamp) {
                if ($own) {
                    $db->rollBack();
                }
                return $current + ['stale' => true];
            }
            $db->prepare('UPDATE work_tables SET columns_json = ?, updated_at = NOW() WHERE id = ?')
                ->execute([json_encode($columns, $flags), $tableId]);
            $stmt = $db->prepare('UPDATE work_table_rows SET data = ? WHERE table_id = ? AND position = ?');
            foreach ($rowsByPosition as $position => $data) {
                $stmt->execute([json_encode($data, $flags), $tableId, (int)$position]);
            }
            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $this->findById($conversationId, $tableId);
    }

    public function retitle(int $conversationId, int $tableId, string $title): ?array
    {
        // `updated_at = updated_at` : la colonne porte ON UPDATE CURRENT_TIMESTAMP.
        // Sans cette affectation, renommer changerait la date d'écriture, donc
        // l'empreinte de la table — et arrêterait une opération de fond en
        // cours sur elle, alors que pas une ligne n'a bougé.
        Database::getInstance()
            ->prepare('UPDATE work_tables SET title = ?, updated_at = updated_at WHERE conversation_id = ? AND id = ?')
            ->execute([$title, $conversationId, $tableId]);
        return $this->findById($conversationId, $tableId);
    }

    public function delete(int $conversationId, string $name): bool
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM work_tables WHERE conversation_id = ? AND name = ?');
        $stmt->execute([$conversationId, $name]);
        return $stmt->rowCount() > 0;
    }

    /** Noms des tables d'une conversation (une requête légère, lue à chaque message). @return list<string> */
    public static function names(int $conversationId): array
    {
        try {
            $stmt = Database::getInstance()->prepare('SELECT name FROM work_tables WHERE conversation_id = ? AND name NOT LIKE ' . self::HIDDEN_LIKE . ' ORDER BY id');
            $stmt->execute([$conversationId]);
            return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) {
            // Table absente (migration pas encore passée) ou base en panne :
            // la conversation continue, sans tables.
            error_log('[Tables] lecture des tables impossible : ' . $e->getMessage());
            return [];
        }
    }

    private function count(int $conversationId): int
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM work_tables WHERE conversation_id = ? AND name NOT LIKE ' . self::HIDDEN_LIKE);
        $stmt->execute([$conversationId]);
        return (int)$stmt->fetchColumn();
    }

    private static function hydrate(array $row): array
    {
        $columns = json_decode((string)($row['columns_json'] ?? ''), true);
        $origin  = json_decode((string)($row['origin_json'] ?? ''), true);
        return [
            'id'              => (int)$row['id'],
            'conversation_id' => (int)$row['conversation_id'],
            'user_id'         => (int)$row['user_id'],
            'name'            => (string)$row['name'],
            'title'           => (string)$row['title'],
            'columns'         => is_array($columns) ? WorkTableOps::columns($columns) : [],
            'rows'            => (int)$row['row_count'],
            'origin'          => is_array($origin) ? $origin : [],
            'updated_at'      => (string)($row['updated_at'] ?? ''),
        ];
    }
}
