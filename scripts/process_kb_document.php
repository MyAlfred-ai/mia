<?php

declare(strict_types=1);

/**
 * Worker d'ingestion d'un document de base de connaissances (tâche de fond).
 * Extraction (texte ou description vision pour les images) → chunks MySQL →
 * embeddings → upsert Qdrant. Statuts suivis en base, pollés par l'admin.
 *
 * Usage : php scripts/process_kb_document.php <documentId>
 */

require __DIR__ . '/_cli_bootstrap.php';

set_time_limit(0);

$documentId = (int)($argv[1] ?? 0);
if ($documentId <= 0) {
    fwrite(STDERR, "Usage: php scripts/process_kb_document.php <documentId>\n");
    exit(1);
}

KnowledgeBaseDocument::processIngestion($documentId);
