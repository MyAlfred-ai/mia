<?php

declare(strict_types=1);

/**
 * Banc de JobQueueLanesTest : UN worker réduit à sa réclamation. Réclame comme
 * le vrai (`JobQueue::claimNext`), « travaille » `$argv[1]` ms, clôt le job,
 * recommence — jusqu'à ce qu'il ne reste rien en attente ni en cours.
 * Une ligne JSON par job sur la sortie :
 * {id, lane, claim, claimed, start, end, closed, pid}
 * — `claim` / `claimed` : début et fin de l'appel à claimNext qui a rendu le
 * job ; `closed` : après sa clôture. Ces deux bouts, hors travail, sont le
 * coût de l'enchaînement d'un job au suivant sur la machine du test.
 *
 * Plusieurs exemplaires lancés EN MÊME TEMPS, contre la base de test : c'est
 * la course réelle entre workers (deux transactions MySQL distinctes), que la
 * lecture du SQL ne peut pas trancher.
 *
 * Barrière de départ (3e argument) : le démarrage d'un process PHP (bootstrap,
 * connexion MySQL) prend un temps qui dépend de la machine. Sans barrière, le
 * premier worker prêt prenait de l'avance sur les autres et le test mesurait
 * ce décalage au lieu du parallélisme (constaté en CI, 2026-11-10). Le worker
 * se prépare, crée le fichier « prêt », puis attend « go » sur son entrée
 * standard — le test ne l'écrit qu'une fois TOUS les workers prêts. Une entrée
 * fermée sans « go » (le test a renoncé) : le worker sort sans rien réclamer.
 *
 * Les erreurs PHP (dont `[JobQueue] claimNext : …`, journalisée puis rendue
 * null) partent sur la sortie d'erreur : le test les lit, au lieu qu'elles
 * finissent dans storage/logs/errors.log, où personne ne les voyait.
 *
 * Usage : php tests/Support/claim_loop.php <ms de travail> <délai max en s> [fichier prêt]
 */

require __DIR__ . '/../../scripts/_cli_bootstrap.php';

ini_set('error_log', '');

$holdUs = max(0, (int)($argv[1] ?? 200)) * 1000;
$limit  = max(1, (int)($argv[2] ?? 30));
$ready  = (string)($argv[3] ?? '');
$db     = Database::getInstance();
$pid    = getmypid();

if ($ready !== '') {
    // Tout ce qui coûte au premier appel est payé AVANT la barrière : la
    // connexion (et sa vérification des migrations), la première lecture de
    // la file.
    $db->query("SELECT COUNT(*) FROM async_jobs WHERE status IN ('pending', 'running')")->fetchColumn();
    touch($ready);
    if (trim((string)fgets(STDIN)) !== 'go') {
        exit(0);
    }
}

$deadline = microtime(true) + $limit;

while (microtime(true) < $deadline) {
    $claim = microtime(true);
    $job = JobQueue::claimNext();
    $claimed = microtime(true);
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
        'id'      => (int)$job['id'],
        'lane'    => $job['lane'],
        'claim'   => $claim,
        'claimed' => $claimed,
        'start'   => $start,
        'end'     => $end,
        'closed'  => microtime(true),
        'pid'     => $pid,
    ]), "\n";
}
