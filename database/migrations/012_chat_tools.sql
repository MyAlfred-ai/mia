CREATE TABLE IF NOT EXISTS chat_tools (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255) NOT NULL,
    type        VARCHAR(50)  NOT NULL DEFAULT 'image',
    provider    VARCHAR(50)  NOT NULL,
    model       VARCHAR(100) NOT NULL,
    config_json TEXT         DEFAULT NULL,
    api_key_enc TEXT         DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
