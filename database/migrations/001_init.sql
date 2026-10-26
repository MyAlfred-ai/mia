-- Utilisateurs
CREATE TABLE IF NOT EXISTS users (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    google_id   VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    name        VARCHAR(255) NOT NULL,
    avatar_url  TEXT         DEFAULT NULL,
    role        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    is_active   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login  DATETIME     DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_google_id (google_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clés API LLM
CREATE TABLE IF NOT EXISTS api_keys (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider    VARCHAR(50)  NOT NULL,
    key_enc     TEXT         NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bases vectorielles
CREATE TABLE IF NOT EXISTS vector_databases (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(255) NOT NULL,
    type            VARCHAR(50)  NOT NULL,
    url             VARCHAR(500) NOT NULL,
    api_key_enc     TEXT         DEFAULT NULL,
    collection_name VARCHAR(255) NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profils de chatbot
CREATE TABLE IF NOT EXISTS profiles (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(255) NOT NULL,
    description     TEXT         DEFAULT NULL,
    system_prompt   LONGTEXT     NOT NULL,
    default_llm     VARCHAR(100) DEFAULT NULL,
    vector_db_id    INT UNSIGNED DEFAULT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_profiles_vector_db FOREIGN KEY (vector_db_id) REFERENCES vector_databases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contrôle d'accès profils (par rôle)
CREATE TABLE IF NOT EXISTS profile_access (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id  INT UNSIGNED NOT NULL,
    role        VARCHAR(50)  NOT NULL DEFAULT 'user',
    user_id     INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_profile_access_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_access_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversations
CREATE TABLE IF NOT EXISTS conversations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    profile_id  INT UNSIGNED DEFAULT NULL,
    llm_used    VARCHAR(100) DEFAULT NULL,
    title       VARCHAR(500) DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_conversations_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_conversations_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages
CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    role            VARCHAR(20)  NOT NULL,
    content         LONGTEXT     NOT NULL,
    file_name       VARCHAR(500) DEFAULT NULL,
    file_type       VARCHAR(100) DEFAULT NULL,
    file_extracted  LONGTEXT     DEFAULT NULL,
    tokens_used     INT          DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Logs d'usage
CREATE TABLE IF NOT EXISTS usage_logs (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED DEFAULT NULL,
    conversation_id INT UNSIGNED DEFAULT NULL,
    profile_id      INT UNSIGNED DEFAULT NULL,
    llm_provider    VARCHAR(100) NOT NULL,
    tokens_input    INT          NOT NULL DEFAULT 0,
    tokens_output   INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_usage_logs_user         FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE SET NULL,
    CONSTRAINT fk_usage_logs_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_usage_logs_profile      FOREIGN KEY (profile_id)      REFERENCES profiles(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index utiles
CREATE INDEX idx_conversations_user    ON conversations(user_id);
CREATE INDEX idx_messages_conversation ON messages(conversation_id);
CREATE INDEX idx_usage_user            ON usage_logs(user_id);
CREATE INDEX idx_usage_created         ON usage_logs(created_at);
