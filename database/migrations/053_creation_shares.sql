-- Partage d'une création par lien unique.
--
-- Une création générée dans le chat (page HTML, image, vidéo, carte/itinéraire)
-- peut être partagée via une URL /share/{token}, visible uniquement par les
-- utilisateurs CONNECTÉS à l'application. Le lien ne donne jamais accès à la
-- conversation : seulement à la création elle-même.
--
-- token       = bin2hex(random_bytes(32)) → 64 hex, non devinable.
-- UNIQUE(message_id) → un seul lien actif par création ; régénérer = DELETE+INSERT
-- (l'ancien token devient immédiatement invalide). Révoquer = DELETE de la ligne.
-- Suppression du message ou de la conversation → cascade.

CREATE TABLE IF NOT EXISTS creation_shares (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    token      CHAR(64)     NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cs_message (message_id),
    UNIQUE KEY uq_cs_token   (token),
    CONSTRAINT fk_cs_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_user    FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
