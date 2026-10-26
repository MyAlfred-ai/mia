<?php

declare(strict_types=1);

class ToolTestRun
{
    public static function create(array $tools, ?int $createdBy = null): int
    {
        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO tool_test_runs (status, total_count, done_count, ok_count, fail_count, created_by)
             VALUES ('queued', ?, 0, 0, 0, ?)"
        )->execute([
            count($tools),
            $createdBy,
        ]);

        $runId = (int)$db->lastInsertId();
        $stmt = $db->prepare(
            'INSERT INTO tool_test_items (run_id, tool_id, tool_name, tool_type, status) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($tools as $tool) {
            $stmt->execute([
                $runId,
                (int)$tool['id'],
                (string)$tool['name'],
                (string)$tool['type'],
                'pending',
            ]);
        }

        return $runId;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM tool_test_runs WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findLatest(): ?array
    {
        $row = Database::getInstance()
            ->query('SELECT * FROM tool_test_runs ORDER BY id DESC LIMIT 1')
            ->fetch();
        return $row ?: null;
    }

    public static function findActive(): ?array
    {
        $stmt = Database::getInstance()->query(
            "SELECT * FROM tool_test_runs WHERE status IN ('queued', 'running') ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function items(int $runId): array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM tool_test_items WHERE run_id=? ORDER BY id ASC');
        $stmt->execute([$runId]);
        return $stmt->fetchAll();
    }

    public static function markRunning(int $runId): void
    {
        Database::getInstance()->prepare(
            "UPDATE tool_test_runs
             SET status='running', started_at=COALESCE(started_at, NOW()), updated_at=NOW()
             WHERE id=?"
        )->execute([$runId]);
    }

    public static function nextPendingItem(int $runId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT * FROM tool_test_items
             WHERE run_id=? AND status='pending'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute([$runId]);
        $item = $stmt->fetch();
        if (!$item) {
            return null;
        }

        $db->prepare(
            "UPDATE tool_test_items
             SET status='running', started_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([(int)$item['id']]);

        $item['status'] = 'running';
        $item['started_at'] = date('Y-m-d H:i:s');
        return $item;
    }

    public static function finishItem(int $itemId, string $status, string $message, int $durationMs): void
    {
        Database::getInstance()->prepare(
            "UPDATE tool_test_items
             SET status=?, message=?, duration_ms=?, finished_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([
            $status,
            mb_substr(trim($message), 0, 1000),
            $durationMs,
            $itemId,
        ]);
    }

    public static function refreshCounters(int $runId): void
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN status IN ('ok', 'failed', 'skipped') THEN 1 ELSE 0 END) AS done_count,
                SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS ok_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS fail_count
             FROM tool_test_items
             WHERE run_id=?"
        );
        $stmt->execute([$runId]);
        $agg = $stmt->fetch() ?: [];

        $total = (int)($agg['total_count'] ?? 0);
        $done  = (int)($agg['done_count'] ?? 0);
        $ok    = (int)($agg['ok_count'] ?? 0);
        $fail  = (int)($agg['fail_count'] ?? 0);
        $isDone = ($total > 0 && $done >= $total);

        Database::getInstance()->prepare(
            'UPDATE tool_test_runs SET total_count=?, done_count=?, ok_count=?, fail_count=?, status=?, finished_at=?, updated_at=NOW() WHERE id=?'
        )->execute([
            $total,
            $done,
            $ok,
            $fail,
            $isDone ? 'completed' : 'running',
            $isDone ? date('Y-m-d H:i:s') : null,
            $runId,
        ]);
    }

    public static function markFailed(int $runId): void
    {
        Database::getInstance()->prepare(
            "UPDATE tool_test_runs
             SET status='failed', finished_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([$runId]);
    }
}
