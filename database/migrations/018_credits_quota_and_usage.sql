ALTER TABLE users ADD COLUMN credit_quota_k INT UNSIGNED DEFAULT NULL;
UPDATE users SET credit_quota_k = token_quota WHERE credit_quota_k IS NULL AND token_quota IS NOT NULL;

ALTER TABLE user_groups ADD COLUMN credit_quota_k INT UNSIGNED DEFAULT NULL;
UPDATE user_groups SET credit_quota_k = token_quota WHERE credit_quota_k IS NULL AND token_quota IS NOT NULL;

ALTER TABLE usage_logs ADD COLUMN credits_used INT NOT NULL DEFAULT 0;

ALTER TABLE tool_usage_logs ADD COLUMN credits_used   INT    NOT NULL DEFAULT 0;
ALTER TABLE tool_usage_logs ADD COLUMN quantity_units DOUBLE NOT NULL DEFAULT 1;

CREATE INDEX idx_usage_user_created      ON usage_logs(user_id, created_at);
CREATE INDEX idx_tool_usage_user_created ON tool_usage_logs(user_id, created_at);
