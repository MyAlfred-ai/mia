<?php

declare(strict_types=1);

/**
 * Attache d'un client au flux d'un job asynchrone.
 *
 * `stream()` est un GET → le navigateur peut utiliser `EventSource`, qui gère
 * nativement la reconnexion ET l'en-tête `Last-Event-ID`. Le curseur `seq` de
 * `job_events` sert d'identifiant d'évènement : recharger la page, changer de
 * conversation ou perdre le réseau reprend le flux exactement là où il en
 * était, sans jamais interrompre la génération elle-même.
 */
class JobController
{
    /** Fenêtre max d'une connexion : au-delà, EventSource se reconnecte tout seul. */
    private const MAX_STREAM_SEC = 300;
    private const POLL_US        = 300_000;
    private const KEEPALIVE_SEC  = 15;

    public function stream(string $id): void
    {
        AuthMiddleware::requireAuth();
        $user  = Session::currentUser();
        $jobId = (int)$id;

        $job = JobQueue::findForUser($jobId, (int)$user['id']);
        if ($job === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Tâche introuvable.']);
            return;
        }

        // Curseur : en-tête standard d'EventSource, sinon paramètre explicite
        // (première attache, ou client qui ne passe pas par EventSource).
        $cursor = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0);
        if ($cursor <= 0) {
            $cursor = max(0, (int)($_GET['from'] ?? 0));
        }

        // Libère le verrou de session : ce flux dure, les autres requêtes de
        // l'onglet (navigation, envoi d'un message) ne doivent pas l'attendre.
        session_write_close();

        @set_time_limit(0);
        ini_set('zlib.output_compression', 'Off');
        while (ob_get_level() > 0) { ob_end_clean(); }
        ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        // Reconnexion rapide si le navigateur perd le flux en cours de job.
        echo "retry: 2000\n\n";
        flush();

        $startedAt     = microtime(true);
        $lastKeepalive = microtime(true);
        $delivered     = 0;

        while (true) {
            $events = JobQueue::eventsSince($jobId, $cursor);
            foreach ($events as $event) {
                $cursor = (int)$event['seq'];
                $delivered++;
                echo 'id: ' . $cursor . "\n";
                echo 'data: ' . $event['payload'] . "\n\n";
            }
            if ($events !== []) {
                flush();
                $lastKeepalive = microtime(true);
            }

            if (connection_aborted() === 1) {
                return; // le client est parti : le job, lui, continue
            }

            $live = JobQueue::liveStatus($jobId);
            if ($live === null) {
                return; // conversation supprimée entre-temps (cascade)
            }
            $status = (string)$live['status'];

            // Terminé ET tout le flux délivré → clôture explicite.
            if (JobQueue::isTerminal($status) && $cursor >= (int)$live['last_seq']) {
                $this->closeStream($jobId, $status, $delivered, $cursor);
                return;
            }

            if ((microtime(true) - $startedAt) >= self::MAX_STREAM_SEC) {
                return; // EventSource rouvrira avec Last-Event-ID
            }
            if ((microtime(true) - $lastKeepalive) >= self::KEEPALIVE_SEC) {
                echo ": keepalive\n\n";   // commentaire SSE : traverse les proxys sans polluer le client
                flush();
                $lastKeepalive = microtime(true);
            }

            usleep(self::POLL_US);
        }
    }

    /**
     * Émet la fin de flux. Si le job est terminé depuis longtemps, ses events
     * ont été purgés (GC) : on re-sert alors le résultat stocké, pour qu'un
     * client qui s'attache tard ne voie pas une génération vide.
     */
    private function closeStream(int $jobId, string $status, int $delivered, int $cursor): void
    {
        if ($delivered === 0 && $cursor === 0) {
            $job = JobQueue::find($jobId);
            if ($job !== null && is_array($job['result']) && $job['result'] !== []) {
                echo 'data: ' . json_encode($job['result'], JSON_UNESCAPED_UNICODE) . "\n\n";
            } elseif ($job !== null && is_array($job['error']) && $job['error'] !== []) {
                echo 'data: ' . json_encode(['llm_error' => $job['error']], JSON_UNESCAPED_UNICODE) . "\n\n";
            }
        }
        echo 'data: ' . json_encode(['job_end' => true, 'status' => $status]) . "\n\n";
        flush();
    }

    /** Jobs encore vivants de l'utilisateur — restauration à l'ouverture d'une page. */
    public function active(): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();

        $jobs = array_map(static function (array $row): array {
            return [
                'id'              => (int)$row['id'],
                'conversation_id' => $row['conversation_id'] !== null ? (int)$row['conversation_id'] : null,
                'type'            => (string)$row['type'],
                'label'           => (string)$row['label'],
                'status'          => (string)$row['status'],
                'phase'           => $row['phase'] !== null ? (string)$row['phase'] : null,
                'progress'        => (int)$row['progress'],
            ];
        }, JobQueue::activeForUser((int)$user['id']));

        header('Content-Type: application/json');
        echo json_encode(['jobs' => $jobs]);
    }

    /** Demande d'arrêt : le worker la voit à son prochain tick (cf. JobContext). */
    public function cancel(string $id): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();

        $ok = JobQueue::requestCancel((int)$id, (int)$user['id']);
        header('Content-Type: application/json');
        echo json_encode(['cancelled' => $ok]);
    }
}
