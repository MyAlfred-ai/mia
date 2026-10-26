-- Connexion Google Drive par utilisateur (OAuth, tokens chiffrés AES-256).
-- Table dédiée (et non colonnes sur users) : prépare d'autres connecteurs
-- (colonne provider) et l'évolution des scopes (écriture plus tard).
CREATE TABLE user_drive_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider VARCHAR(20) NOT NULL DEFAULT 'google_drive',
    google_email VARCHAR(255) NULL DEFAULT NULL,
    refresh_token_enc TEXT NOT NULL,
    access_token_enc TEXT NULL DEFAULT NULL,
    access_token_expires_at DATETIME NULL DEFAULT NULL,
    scope VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'ok',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_udt_user_provider (user_id, provider),
    CONSTRAINT fk_udt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
