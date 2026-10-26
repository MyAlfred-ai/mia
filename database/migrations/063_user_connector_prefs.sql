-- Préférences PAR UTILISATEUR d'un connecteur du chat.
-- Table générique (clé = user + connecteur, valeur = JSON) et non des colonnes
-- sur user_drive_tokens : les préférences doivent SURVIVRE à une déconnexion /
-- reconnexion (la ligne de jeton, elle, est supprimée), et le prochain
-- connecteur qui en aura besoin n'exigera aucune migration.
-- Le contenu du JSON est validé par le service du connecteur (source unique),
-- jamais par le schéma : un réglage ajouté ne fait pas d'ALTER.
CREATE TABLE user_connector_prefs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    connector VARCHAR(32) NOT NULL,   -- clé ConnectorRegistry (tasks, …)
    prefs_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ucp_user_connector (user_id, connector),
    CONSTRAINT fk_ucp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
