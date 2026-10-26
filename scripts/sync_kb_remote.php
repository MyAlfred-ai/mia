<?php

declare(strict_types=1);

/**
 * Worker CLI : synchronise une base de connaissances avec son dossier distant
 * (Google Drive ou SharePoint selon knowledge_bases.sync_provider — diff
 * complet + ingestion séquentielle des nouveautés).
 *
 * Usage : php scripts/sync_kb_remote.php <kbId>
 *
 * Lancé en tâche de fond par le déclencheur paresseux (KbSync::maybeRunDue)
 * ou par le bouton « Synchroniser maintenant » de l'admin.
 */

require __DIR__ . '/_cli_bootstrap.php';

set_time_limit(0);

$kbId = (int)($argv[1] ?? 0);
if ($kbId <= 0) {
    fwrite(STDERR, "Usage: php scripts/sync_kb_remote.php <kbId>\n");
    exit(1);
}

KbSync::syncKb($kbId);
