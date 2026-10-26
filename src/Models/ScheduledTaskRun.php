<?php

declare(strict_types=1);

/**
 * Exécutions des tâches programmées (migration 081) : une ligne par créneau
 * traité, qu'il ait donné une réponse ou non.
 *
 * Deux rôles :
 *   - GARANTIE : `UNIQUE(task_id, slot_at)` — un créneau ne part jamais deux
 *     fois, quel que soit le nombre de processus qui le voient échu ;
 *   - HISTORIQUE : ce que la page des tâches affiche (réussie, sautée parce
 *     que le quota était atteint, manquée parce que le serveur était arrêté…).
 *
 * Le statut final est recopié depuis le job de chat à sa clôture
 * (`onJobFinished`, appelé par JobQueue pour CHAQUE job : une seule requête
 * indexée, qui ne trouve rien pour un job ordinaire).
 */
final class ScheduledTaskRun
{
    public const QUEUED    = 'queued';
    public const DONE      = 'done';
    public const FAILED    = 'failed';
    public const CANCELLED = 'cancelled';
    public const SKIPPED   = 'skipped';
    public const MISSED    = 'missed';

    /** Historique conservé : au-delà, les lignes partent au fil de l'eau. */
    public const RETENTION_DAYS = 180;

    /**
     * Enregistre le traitement d'un créneau. Null si ce créneau a DÉJÀ été
     * traité (violation de l'unicité) : l'appelant n'a alors rien à faire.
     */
    public static function insert(
        int $taskId,
        \DateTimeImmutable $slot,
        bool $manual,
        string $status,
        string $reason = ''
    ): ?int {
        $db = Database::getInstance();
        try {
            $db->prepare(
                'INSERT INTO scheduled_task_runs (task_id, slot_at, is_manual, status, reason, finished_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $taskId,
                ScheduledTask::sqlUtc($slot),
                $manual ? 1 : 0,
                $status,
                mb_substr($reason, 0, 32),
                $status === self::QUEUED ? null : ScheduledTask::sqlUtc(new \DateTimeImmutable('now')),
            ]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
        return (int)$db->lastInsertId();
    }

    /** Le job de chat de cette exécution est enfilé : on le relie, pour en recevoir l'issue. */
    public static function attachJob(int $runId, int $jobId, int $conversationId): void
    {
        Database::getInstance()->prepare(
            'UPDATE scheduled_task_runs SET job_id = ?, conversation_id = ? WHERE id = ?'
        )->execute([$jobId, $conversationId, $runId]);
    }

    /**
     * Une exécution de cette tâche est-elle encore en cours ? Bornée dans le
     * temps : une ligne restée « en cours » plus de deux heures (job perdu)
     * ne doit pas bloquer la tâche pour toujours.
     */
    public static function hasRunning(int $taskId, \DateTimeImmutable $now, int $windowSec): bool
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT 1 FROM scheduled_task_runs WHERE task_id = ? AND status = ? AND slot_at >= ? LIMIT 1'
        );
        $stmt->execute([$taskId, self::QUEUED, ScheduledTask::sqlUtc($now->modify('-' . $windowSec . ' seconds'))]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Issue d'un job → statut de l'exécution, compteur d'échecs de la tâche,
     * pastille « non lu » sur la conversation. Appelé par JobQueue à la
     * clôture de CHAQUE job (et par le reaper) : ne lève jamais.
     */
    public static function onJobFinished(int $jobId, string $jobStatus, ?array $result, ?array $error): void
    {
        try {
            $db = Database::getInstance();
            $sel = $db->prepare(
                'SELECT id, task_id, conversation_id, is_manual FROM scheduled_task_runs WHERE job_id = ? AND status = ? LIMIT 1'
            );
            $sel->execute([$jobId, self::QUEUED]);
            $run = $sel->fetch();
            if (!$run) {
                return;
            }

            [$status, $reason] = self::outcome($jobStatus, $result, $error);
            $messageId = (int)($result['msg_id'] ?? 0);

            $db->prepare(
                'UPDATE scheduled_task_runs
                 SET status = ?, reason = ?, message_id = ?, finished_at = ?
                 WHERE id = ? AND status = ?'
            )->execute([
                $status,
                $reason,
                $messageId > 0 ? $messageId : null,
                ScheduledTask::sqlUtc(new \DateTimeImmutable('now')),
                (int)$run['id'],
                self::QUEUED,
            ]);

            if ($status === self::DONE || $status === self::FAILED) {
                ScheduledTask::recordOutcome((int)$run['task_id'], $status === self::DONE);
                // Quelque chose à lire — réponse ou explication de l'échec.
                if (!empty($run['conversation_id'])) {
                    Conversation::markUnread((int)$run['conversation_id']);
                }
            }

            // Sur les appareils de la personne, si la tâche le demande (après
            // recordOutcome : une pause pour trois échecs se dit aussi).
            TaskNotifier::runRecorded(
                (int)$run['task_id'],
                $status,
                $reason,
                !empty($run['conversation_id']) ? (int)$run['conversation_id'] : null,
                (int)($run['is_manual'] ?? 0) === 1,
                $messageId
            );
        } catch (\Throwable $e) {
            error_log('[Tâches programmées] issue du job #' . $jobId . ' non enregistrée : ' . $e->getMessage());
        }
    }

    /**
     * Statut du job → [statut, raison] de l'exécution. Pure.
     *
     * Un quota ou un budget atteint au démarrage du job n'est PAS un échec :
     * la tâche n'y est pour rien, et trois mois sans crédit ne doivent pas la
     * mettre en pause. Une réponse terminée « en erreur » (le fournisseur a
     * refusé, le modèle a planté) en est un.
     *
     * @return array{0: string, 1: string}
     */
    public static function outcome(string $jobStatus, ?array $result, ?array $error): array
    {
        if ($jobStatus === 'done') {
            return !empty($result['error']) ? [self::FAILED, 'model_error'] : [self::DONE, ''];
        }
        if ($jobStatus === 'cancelled') {
            return [self::CANCELLED, ''];
        }
        $kind = (string)($error['kind'] ?? '');
        if (in_array($kind, [CreditQuota::REASON_QUOTA, CreditQuota::REASON_BUDGET], true)) {
            return [self::SKIPPED, $kind];
        }
        if (($error['scheduled_reason'] ?? '') === 'late') {
            return [self::MISSED, 'late'];
        }
        return [self::FAILED, mb_substr($kind !== '' ? $kind : 'generic', 0, 32)];
    }

    /**
     * Dernière exécution de chaque tâche (une requête pour toute la liste).
     *
     * @param list<int> $taskIds
     * @return array<int, array> par identifiant de tâche
     */
    public static function latestForTasks(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
        if ($taskIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT r.* FROM scheduled_task_runs r
             JOIN (SELECT task_id, MAX(id) AS id FROM scheduled_task_runs WHERE task_id IN ($in) GROUP BY task_id) last
               ON last.id = r.id"
        );
        $stmt->execute($taskIds);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(int)$row['task_id']] = $row;
        }
        return $out;
    }

    /** @return list<array> les dernières exécutions d'une tâche, la plus récente d'abord */
    public static function recentForTask(int $taskId, int $limit = 10): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM scheduled_task_runs WHERE task_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$taskId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Entretien (worker, toutes les minutes ; repli paresseux sans worker) :
     *   - une exécution restée « en cours » dont le job n'existe plus ou s'est
     *     terminé sans prévenir passe en échec « perdue » (sinon elle
     *     bloquerait la tâche jusqu'à la fin de sa fenêtre, sans explication) ;
     *   - l'historique au-delà de RETENTION_DAYS est effacé, par paquets.
     */
    public static function maintain(\DateTimeImmutable $now): void
    {
        $db = Database::getInstance();
        $db->prepare(
            "UPDATE scheduled_task_runs r
             LEFT JOIN async_jobs j ON j.id = r.job_id
             SET r.status = 'failed', r.reason = 'lost', r.finished_at = ?
             WHERE r.status = 'queued' AND r.slot_at < ?
               AND (j.id IS NULL OR j.status IN ('done', 'failed', 'cancelled'))"
        )->execute([
            ScheduledTask::sqlUtc($now),
            ScheduledTask::sqlUtc($now->modify('-1 hour')),
        ]);
        $db->prepare('DELETE FROM scheduled_task_runs WHERE slot_at < ? LIMIT 1000')
            ->execute([ScheduledTask::sqlUtc($now->modify('-' . self::RETENTION_DAYS . ' days'))]);
    }
}
