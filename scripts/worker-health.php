<?php

declare(strict_types=1);

/**
 * HEALTHCHECK du conteneur `worker`.
 *
 * Le worker n'expose aucun port : impossible de le sonder en HTTP comme l'app.
 * On lit donc le battement de cœur qu'il écrit déjà en base, avec la MÊME règle
 * que `JobQueue::workerAlive()` — y compris le contrôle de version, qui est le
 * cas le plus pernicieux : un worker sur une image périmée répond « vivant »
 * tout en faisant échouer les types de jobs ajoutés depuis.
 *
 * Sortie 0 = sain, 1 = malade. Aucun affichage en cas de succès.
 */

require __DIR__ . '/_cli_bootstrap.php';

try {
    // Vivant ET à jour : un worker qui se sait périmé bat toujours, mais ne
    // réclame plus rien — il doit se voir « malade », pas « sain ».
    if (JobQueue::workerAlive() && !JobQueue::workerOutdated()) {
        exit(0);
    }
} catch (\Throwable $e) {
    // Base injoignable au démarrage : le conteneur n'est pas encore prêt, ce
    // n'est pas une panne du worker. Le start_period du HEALTHCHECK couvre ce
    // délai ; passé celui-ci, l'échec est légitime.
    fwrite(STDERR, 'worker-health : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDERR, 'worker-health : aucun battement récent, ou version divergente de celle de l\'application' . PHP_EOL);
exit(1);
