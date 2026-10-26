CREATE TABLE IF NOT EXISTS tool_test_runs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    status       VARCHAR(20)  NOT NULL DEFAULT 'queued',
    total_count  INT          NOT NULL DEFAULT 0,
    done_count   INT          NOT NULL DEFAULT 0,
    ok_count     INT          NOT NULL DEFAULT 0,
    fail_count   INT          NOT NULL DEFAULT 0,
    created_by   INT UNSIGNED DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at   DATETIME     DEFAULT NULL,
    finished_at  DATETIME     DEFAULT NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tool_test_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id       INT UNSIGNED NOT NULL,
    tool_id      INT UNSIGNED NOT NULL,
    tool_name    VARCHAR(255) NOT NULL,
    tool_type    VARCHAR(50)  NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
    message      TEXT         DEFAULT NULL,
    duration_ms  INT          DEFAULT NULL,
    started_at   DATETIME     DEFAULT NULL,
    finished_at  DATETIME     DEFAULT NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_tool_test_items_run  FOREIGN KEY (run_id)  REFERENCES tool_test_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_tool_test_items_tool FOREIGN KEY (tool_id) REFERENCES chat_tools(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_tool_test_runs_status ON tool_test_runs(status);
CREATE INDEX idx_tool_test_items_run   ON tool_test_items(run_id);
