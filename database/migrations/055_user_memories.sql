-- Mémoire long terme par utilisateur (« MIA pour vous »).
-- Une ligne = un souvenir court et autoportant, alimenté depuis le chat
-- (« souviens-toi que… ») ou saisi à la main dans les paramètres.
-- Table dédiée (et non une colonne texte sur users) : édition/suppression
-- unitaire, traçabilité de la provenance, et quota par nombre d'entrées.
CREATE TABLE user_memories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    content VARCHAR(500) NOT NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'chat', -- chat | manuel
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_um_user (user_id, created_at),
    CONSTRAINT fk_um_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
