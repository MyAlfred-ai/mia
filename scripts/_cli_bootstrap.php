<?php

declare(strict_types=1);

/**
 * Bootstrap minimal pour les scripts CLI (workers, backfill).
 * Charge la config (.env / env Docker), l'autoload Composer, puis EXACTEMENT
 * les mêmes classes que la façade web (`config/classes.php`) : deux listes
 * séparées dérivaient, et une classe oubliée ici ne se voyait qu'en
 * production, dans le worker.
 */

define('ROOT', dirname(__DIR__));

// Même gestionnaire qu'en web, variante CLI : sans lui, worker.php, run_job.php
// et les scripts de maintenance mouraient en silence — le process s'arrêtait,
// rien ne s'affichait, et la seule trace était l'absence de résultat.
require ROOT . '/src/Helpers/ErrorHandler.php';
ErrorHandler::registerCli();

require ROOT . '/config/app.php';
ErrorHandler::setEnvironment(APP_ENV);

$autoload = ROOT . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

require ROOT . '/config/classes.php';
