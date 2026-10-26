<?php

declare(strict_types=1);

/**
 * Banc de JobQueueLanesTest : UN worker réduit à sa réclamation. Réclame comme
 * le vrai (`JobQueue::claimNext`), « travaille » `$argv[1]` ms, clôt le job,
 * recommence — jusqu'à ce qu'il ne reste rien en attente ni en cours.
 * Une ligne JSON par job sur la sortie : {id, lane, start, end, pid}.
 *
 * Plusieurs exemplaires lancés EN MÊME TEMPS, contre la base de test : c'est
 * la course réelle entre workers (deux transactions MySQL distinctes), que la
 * lecture du SQL ne peut pas trancher.
 *
 * Usage : php tests/Support/claim_loop.php <ms de travail> <délai max en s>
 */

require __DIR__ . '/../../scripts/_cli_bootstrap.php';

$holdUs   = max(0, (int)($argv[1] ?? 200)) * 1000;
$deadline = microtime(true) + max(1, (int)($argv[2] ?? 30));
$db       = Database::getInstance();
$pid      = getmypid();

while (microtime(true) < $deadline) {
    $job = JobQueue::claimNext();
    if ($job === null) {
        $left = (int)$db->query(
            "SELECT COUNT(*) FROM async_jobs WHERE status IN ('pending', 'running')"
        )->fetchColumn();
        if ($left === 0) {
            break;
        }
        usleep(20_000);
        continue;
    }
    $start = microtime(true);
    usleep($holdUs);
    $end = microtime(true);
    $db->prepare("UPDATE async_jobs SET status = 'done', finished_at = NOW() WHERE id = ?")
       ->execute([(int)$job['id']]);
    echo json_encode([
        'id'    => (int)$job['id'],
        'lane'  => $job['lane'],
        'start' => $start,
        'end'   => $end,
        'pid'   => $pid,
    ]), "\n";
}
