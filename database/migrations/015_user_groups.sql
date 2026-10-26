CREATE TABLE IF NOT EXISTS user_groups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255) NOT NULL,
    token_quota INT UNSIGNED DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN group_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE users ADD CONSTRAINT fk_users_group_id FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE SET NULL;

CREATE INDEX idx_users_group_id ON users(group_id);

CREATE TABLE IF NOT EXISTS user_group_profiles (
    group_id   INT UNSIGNED NOT NULL,
    profile_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, profile_id),
    CONSTRAINT fk_ugp_group   FOREIGN KEY (group_id)   REFERENCES user_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_ugp_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_ugp_profile_id ON user_group_profiles(profile_id);

CREATE TABLE IF NOT EXISTS user_group_tools (
    group_id INT UNSIGNED NOT NULL,
    tool_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, tool_id),
    CONSTRAINT fk_ugt_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_ugt_tool  FOREIGN KEY (tool_id)  REFERENCES chat_tools(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_ugt_tool_id ON user_group_tools(tool_id);
