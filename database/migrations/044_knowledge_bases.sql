-- Bases de connaissances vectorisées, partagées entre assistants (N-N).
-- Les vecteurs vivent dans Qdrant (collection kb_{id}, point id = chunk id) ;
-- les chunks restent en MySQL (source de vérité + fallback lexical FULLTEXT).

CREATE TABLE IF NOT EXISTS knowledge_bases (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name               VARCHAR(255) NOT NULL,
    description        TEXT NULL,
    embedding_provider VARCHAR(50)  NOT NULL,
    embedding_model    VARCHAR(100) NOT NULL,
    embedding_dims     SMALLINT UNSIGNED NOT NULL,
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_kb_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_base_documents (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kb_id             INT UNSIGNED NOT NULL,
    file_name         VARCHAR(255) NOT NULL,
    stored_name       VARCHAR(255) NOT NULL,
    mime_type         VARCHAR(100) NOT NULL,
    file_size         INT UNSIGNED NOT NULL DEFAULT 0,
    extracted_text    LONGTEXT NULL,
    token_count       INT UNSIGNED NOT NULL DEFAULT 0,
    extraction_status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    extraction_error  VARCHAR(500) NULL,
    vector_status     ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    vector_error      VARCHAR(500) NULL,
    chunk_count       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_kbd_kb (kb_id),
    CONSTRAINT fk_kbd_kb FOREIGN KEY (kb_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_base_chunks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    kb_id       INT UNSIGNED NOT NULL,
    chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
    content     MEDIUMTEXT NOT NULL,
    token_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_kbc_kb (kb_id, document_id, chunk_index),
    FULLTEXT KEY ft_kbc_content (content),
    CONSTRAINT fk_kbc_document FOREIGN KEY (document_id) REFERENCES knowledge_base_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_kbc_kb FOREIGN KEY (kb_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_knowledge_bases (
    profile_id INT UNSIGNED NOT NULL,
    kb_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (profile_id, kb_id),
    KEY idx_pkb_kb (kb_id),
    CONSTRAINT fk_pkb_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_pkb_kb FOREIGN KEY (kb_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
