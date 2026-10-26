<?php

declare(strict_types=1);

/**
 * Publie la version de l'application au démarrage du conteneur `app`
 * (docker/entrypoint.sh, branche Apache), sans attendre une première page.
 *
 * Le worker compare sa version à celle que l'app publie (JobQueue::
 * workerOutdated) : publiée seulement à la première requête, elle restait
 * celle d'AVANT le déploiement — après un retour arrière, le worker se serait
 * cru périmé et aurait cessé de lancer les tâches programmées jusqu'à ce que
 * quelqu'un ouvre une page. Best-effort : un échec n'empêche jamais Apache de
 * démarrer (la première requête publiera).
 *
 * Usage : php scripts/publish_version.php
 */

require __DIR__ . '/_cli_bootstrap.php';

try {
    JobQueue::publishCodeVersion();
    fwrite(STDOUT, '[publish-version] ' . APP_VERSION . "\n");
} catch (\Throwable $e) {
    fwrite(STDERR, '[publish-version] impossible : ' . $e->getMessage() . "\n");
}
exit(0);
