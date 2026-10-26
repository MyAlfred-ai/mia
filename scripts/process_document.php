<?php

declare(strict_types=1);

/**
 * Worker d'extraction asynchrone d'un document de référence.
 * Usage : php scripts/process_document.php <documentId>
 *
 * Lancé en tâche de fond par AdminController::dispatchDocumentExtraction().
 * Extrait le texte (FileExtractor), met à jour le statut et (re)construit les
 * chunks FULLTEXT. N'émet aucune exception : tout échec → statut 'failed'.
 */

require __DIR__ . '/_cli_bootstrap.php';

$documentId = (int)($argv[1] ?? 0);
if ($documentId <= 0) {
    fwrite(STDERR, "Usage: php process_document.php <documentId>\n");
    exit(1);
}

ProfileDocument::processExtraction($documentId);
