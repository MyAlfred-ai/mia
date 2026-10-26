<?php

declare(strict_types=1);

/**
 * Exécution d'UN job asynchrone (chemin dégradé : pas de worker dédié vivant,
 * mais fork POSIX disponible). Lancé par `JobQueue::dispatch()`.
 *
 * Usage : php scripts/run_job.php <jobId>
 */

require __DIR__ . '/_cli_bootstrap.php';

set_time_limit(0);

$jobId = (int)($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run_job.php <jobId>\n");
    exit(1);
}

// claimById échoue silencieusement si un worker a déjà pris le job — c'est la
// garantie anti-double-exécution, pas une erreur.
JobQueue::runById($jobId);
