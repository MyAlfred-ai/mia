-- Document EXCLU d'une base de connaissances : il reste listé (avec qui l'a
-- exclu, quand et pourquoi) mais ne compte plus parmi les problèmes, n'a plus
-- d'extraits et n'est plus cherché. Le fichier est gardé : « Rétablir » le
-- relit. Une synchronisation ne le reprend pas tant qu'il ne change pas à la
-- source ; modifié, il est réessayé (KnowledgeBaseDocument::updateSyncedVersion).
-- Aucun document existant ne change : NULL par défaut.
--
-- Et l'heure de la DERNIÈRE indexation d'un document (`indexed_at`), posée à
-- chaque vectorisation menée au bout (KnowledgeBaseDocument::setVectorStatus).
-- Les documents déjà indexés reprennent leur date de dépôt : la meilleure
-- approximation connue (un fichier synchronisé modifié depuis a pu être
-- réindexé plus tard).
ALTER TABLE knowledge_base_documents
    ADD COLUMN excluded_at     DATETIME     NULL DEFAULT NULL AFTER chunk_count,
    ADD COLUMN excluded_by     INT UNSIGNED NULL DEFAULT NULL AFTER excluded_at,
    ADD COLUMN excluded_reason VARCHAR(255) NULL DEFAULT NULL AFTER excluded_by,
    ADD COLUMN indexed_at      DATETIME     NULL DEFAULT NULL AFTER excluded_reason;

UPDATE knowledge_base_documents SET indexed_at = created_at WHERE vector_status = 'done';
