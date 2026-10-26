CREATE TABLE IF NOT EXISTS user_group_members (
    user_id     INT UNSIGNED NOT NULL,
    group_id    INT UNSIGNED NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id),
    CONSTRAINT fk_ugm_user  FOREIGN KEY (user_id)  REFERENCES users(id)       ON DELETE CASCADE,
    CONSTRAINT fk_ugm_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO user_group_members (user_id, group_id)
SELECT id, group_id
FROM users
WHERE group_id IS NOT NULL;

CREATE INDEX idx_ugm_user_id  ON user_group_members(user_id);
CREATE INDEX idx_ugm_group_id ON user_group_members(group_id);
