-- Approbation humaine des écritures Google Drive demandées par le LLM en chat :
-- l'outil (creer/modifier doc/sheet) ne s'exécute plus pendant le stream, il
-- dépose une action en attente ; l'utilisateur approuve ou annule via une
-- carte dans la conversation (route /chat/{id}/drive-action).
CREATE TABLE drive_pending_actions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    conversation_id INT UNSIGNED NOT NULL,
    tool VARCHAR(40) NOT NULL,
    args_json MEDIUMTEXT NOT NULL,
    preview_json TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|processing|executed|cancelled|failed|expired
    error VARCHAR(500) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL DEFAULT NULL,
    KEY idx_dpa_conv_status (conversation_id, status),
    CONSTRAINT fk_dpa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dpa_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
