<?php

declare(strict_types=1);

/**
 * Magasin de tables de travail EN MÉMOIRE : les outils s'éprouvent sans base,
 * sur le même contrat que SqlWorkTableStore (remplacement par nom, plafond,
 * cloisonnement par conversation).
 */
final class MemoryWorkTableStore implements WorkTableStore
{
    /** @var array<int, array> id → table (avec ses lignes sous `_rows`) */
    private array $tables = [];

    private int $nextId = 1;

    public function all(int $conversationId): array
    {
        $out = array_filter($this->tables, static fn(array $t): bool => $t['conversation_id'] === $conversationId && !WorkTableOps::isHidden((string)$t['name']));
        usort($out, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);
        return array_map([self::class, 'meta'], array_values($out));
    }

    public function find(int $conversationId, string $name): ?array
    {
        foreach ($this->tables as $t) {
            if ($t['conversation_id'] === $conversationId && $t['name'] === $name) {
                return self::meta($t);
            }
        }
        return null;
    }

    public function findById(int $conversationId, int $tableId): ?array
    {
        $t = $this->tables[$tableId] ?? null;
        return $t !== null && $t['conversation_id'] === $conversationId ? self::meta($t) : null;
    }

    public function save(int $conversationId, int $userId, string $name, string $title, array $columns, array $rows, array $origin = []): array
    {
        $rows = array_slice(array_values($rows), 0, WorkTableOps::MAX_ROWS);
        $existing = $this->find($conversationId, $name);
        if ($existing === null && count($this->all($conversationId)) >= WorkTableOps::MAX_TABLES) {
            throw new \RuntimeException('Cette conversation compte déjà ' . WorkTableOps::MAX_TABLES
                . ' tables de travail. Réutilise le nom d\'une table devenue inutile (elle sera remplacée).');
        }
        $id = $existing !== null ? (int)$existing['id'] : $this->nextId++;
        $this->tables[$id] = [
            'id' => $id, 'conversation_id' => $conversationId, 'user_id' => $userId, 'name' => $name, 'title' => $title,
            'columns' => $columns, 'rows' => count($rows), 'origin' => $origin, 'updated_at' => '', '_rows' => $rows,
        ];
        return self::meta($this->tables[$id]);
    }

    public function rows(int $tableId, int $offset = 0, ?int $limit = null): array
    {
        return array_slice($this->tables[$tableId]['_rows'] ?? [], max(0, $offset), $limit);
    }

    public function patch(int $conversationId, int $tableId, array $columns, array $rowsByPosition, ?string $ifStamp = null): ?array
    {
        $current = $this->findById($conversationId, $tableId);
        if ($current === null) {
            return null;
        }
        if ($ifStamp !== null && WorkTableOps::stateStamp($current) !== $ifStamp) {
            return $current + ['stale' => true];
        }
        $this->tables[$tableId]['columns'] = $columns;
        foreach ($rowsByPosition as $position => $row) {
            if (isset($this->tables[$tableId]['_rows'][(int)$position])) {
                $this->tables[$tableId]['_rows'][(int)$position] = $row;
            }
        }
        return self::meta($this->tables[$tableId]);
    }

    public function retitle(int $conversationId, int $tableId, string $title): ?array
    {
        if ($this->findById($conversationId, $tableId) === null) {
            return null;
        }
        $this->tables[$tableId]['title'] = $title;
        return self::meta($this->tables[$tableId]);
    }

    public function delete(int $conversationId, string $name): bool
    {
        $found = $this->find($conversationId, $name);
        if ($found === null) {
            return false;
        }
        unset($this->tables[(int)$found['id']]);
        return true;
    }

    private static function meta(array $table): array
    {
        unset($table['_rows']);
        return $table;
    }
}
