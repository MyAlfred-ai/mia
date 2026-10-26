CREATE TABLE IF NOT EXISTS tool_usage_backfill_markers (
    message_id          INT UNSIGNED NOT NULL,
    estimated_tool_id   INT UNSIGNED DEFAULT NULL,
    estimated_tool_type VARCHAR(50)  NOT NULL,
    created_at          DATETIME     NOT NULL,
    backfilled_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    CONSTRAINT fk_tubm_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_tool_usage_backfill_created ON tool_usage_backfill_markers(created_at);
