-- Documents de référence attachés aux profils (assistants) pour RAG local.
-- V1 : injection directe du texte extrait dans le system prompt.
CREATE TABLE IF NOT EXISTS profile_documents (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    profile_id      INT UNSIGNED    NOT NULL,
    file_name       VARCHAR(255)    NOT NULL,
    stored_name     VARCHAR(255)    NOT NULL,
    mime_type       VARCHAR(100)    NOT NULL,
    file_size       INT UNSIGNED    NOT NULL DEFAULT 0,
    extracted_text  LONGTEXT,
    token_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_pd_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_profile_documents_profile ON profile_documents(profile_id);
