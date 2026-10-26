CREATE TABLE IF NOT EXISTS tool_usage_logs (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED DEFAULT NULL,
    conversation_id INT UNSIGNED DEFAULT NULL,
    profile_id      INT UNSIGNED DEFAULT NULL,
    tool_id         INT UNSIGNED NOT NULL,
    tool_type       VARCHAR(50)  NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_tool_usage_user         FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE SET NULL,
    CONSTRAINT fk_tool_usage_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tool_usage_profile      FOREIGN KEY (profile_id)      REFERENCES profiles(id)      ON DELETE SET NULL,
    CONSTRAINT fk_tool_usage_tool         FOREIGN KEY (tool_id)         REFERENCES chat_tools(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_tool_usage_created ON tool_usage_logs(created_at);
CREATE INDEX idx_tool_usage_tool    ON tool_usage_logs(tool_id);
CREATE INDEX idx_tool_usage_user    ON tool_usage_logs(user_id);
