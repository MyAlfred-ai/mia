<?php

declare(strict_types=1);

/**
 * Réindexation complète d'une base de connaissances (outil CLI de secours —
 * l'application passe désormais par la file de jobs, cf. MaintenanceJob).
 *
 * Usage : php scripts/reindex_kb.php <kbId>
 */

require __DIR__ . '/_cli_bootstrap.php';

set_time_limit(0);

$kbId = (int)($argv[1] ?? 0);
if ($kbId <= 0) {
    fwrite(STDERR, "Usage: php scripts/reindex_kb.php <kbId>\n");
    exit(1);
}

if (!KnowledgeBase::findById($kbId)) {
    fwrite(STDERR, "Base de connaissances $kbId introuvable.\n");
    exit(1);
}

KnowledgeBase::reindex($kbId);
