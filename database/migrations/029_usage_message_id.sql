-- Lien message ↔ usage : permet d'afficher les crédits consommés par chaque
-- réponse assistant (badge msg-llm-badge). ON DELETE SET NULL pour ne pas
-- détruire la trace de consommation si le message est supprimé.

ALTER TABLE usage_logs
    ADD COLUMN message_id INT UNSIGNED NULL AFTER conversation_id,
    ADD CONSTRAINT fk_usage_message
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL;

CREATE INDEX idx_usage_message ON usage_logs(message_id);

ALTER TABLE tool_usage_logs
    ADD COLUMN message_id INT UNSIGNED NULL AFTER conversation_id,
    ADD CONSTRAINT fk_tool_usage_message
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL;

CREATE INDEX idx_tool_usage_message ON tool_usage_logs(message_id);
