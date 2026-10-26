<?php

declare(strict_types=1);

/**
 * Backfill : chunke les documents de référence existants qui ont du texte
 * extrait mais aucun chunk (migration 039 — passage au RAG lexical).
 * Idempotent : relançable sans risque. À lancer une fois après déploiement.
 *
 * Usage : php scripts/backfill_document_chunks.php [profileId]
 */

require __DIR__ . '/_cli_bootstrap.php';

$profileId = isset($argv[1]) ? (int)$argv[1] : null;

$done = ProfileDocument::backfillChunks($profileId);

fwrite(STDOUT, sprintf("Backfill terminé : %d document(s) chunké(s).\n", $done));
