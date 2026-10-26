-- RAG lexical sans base vectorielle : on découpe le texte extrait des documents
-- de référence en « chunks » indexés FULLTEXT (parseur MySQL par défaut, adapté
-- à la prose française). À chaque message, on n'injecte plus le dump intégral
-- mais seulement les chunks les plus pertinents pour la question, dans la limite
-- d'un budget de tokens calé sur le modèle actif (cf. ChatController + LlmCatalog).
--
-- On ajoute aussi un statut d'extraction : l'extraction de texte (souvent lente
-- pour de gros PDF) devient asynchrone — l'upload répond immédiatement et un
-- worker en tâche de fond extrait puis chunke. Défaut 'done' → les documents
-- déjà en base restent considérés comme traités.

ALTER TABLE profile_documents
    ADD COLUMN extraction_status ENUM('pending','processing','done','failed')
        NOT NULL DEFAULT 'done',
    ADD COLUMN extraction_error VARCHAR(500) NULL;

CREATE TABLE IF NOT EXISTS profile_document_chunks (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id  INT UNSIGNED NOT NULL,
    profile_id   INT UNSIGNED NOT NULL,
    chunk_index  INT UNSIGNED NOT NULL DEFAULT 0,
    content      MEDIUMTEXT   NOT NULL,
    token_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_pdc_document FOREIGN KEY (document_id)
        REFERENCES profile_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_pdc_profile FOREIGN KEY (profile_id)
        REFERENCES profiles(id) ON DELETE CASCADE,
    FULLTEXT KEY ft_pdc_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_pdc_profile ON profile_document_chunks(profile_id, document_id, chunk_index);
