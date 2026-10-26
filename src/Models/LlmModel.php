<?php

declare(strict_types=1);

class LlmModel
{
    public static function all(): array
    {
        return Database::getInstance()
            ->query('SELECT * FROM llm_models ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    public static function active(): array
    {
        return Database::getInstance()
            ->query('SELECT * FROM llm_models WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM llm_models WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function save(array $data): void
    {
        $db = Database::getInstance();
        if (!empty($data['id'])) {
            $db->prepare('UPDATE llm_models SET provider=?, model=?, display_name=?, is_active=?, sort_order=? WHERE id=?')
               ->execute([
                   $data['provider'],
                   $data['model'],
                   $data['display_name'],
                   (int)($data['is_active'] ?? 1),
                   (int)($data['sort_order'] ?? 0),
                   (int)$data['id'],
               ]);
        } else {
            $db->prepare('INSERT INTO llm_models (provider, model, display_name, is_active, sort_order) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), is_active=VALUES(is_active), sort_order=VALUES(sort_order)')
               ->execute([
                   $data['provider'],
                   $data['model'],
                   $data['display_name'],
                   (int)($data['is_active'] ?? 1),
                   (int)($data['sort_order'] ?? 0),
               ]);
        }
    }

    public static function delete(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM llm_models WHERE id=?')->execute([$id]);
    }

    /** Instances actives pour un provider donné, dans l'ordre `sort_order`. */
    public static function activeByProvider(string $provider): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM llm_models WHERE provider = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$provider]);
        return $stmt->fetchAll();
    }
}
