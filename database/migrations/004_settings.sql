CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(255) NOT NULL,
    `value` TEXT         DEFAULT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('app_name',        'MIA'),
    ('logo_url',        '/assets/img/logo.png'),
    ('theme',           'dark'),
    ('accent_color',    '#10a37f'),
    ('welcome_message', 'Comment puis-je vous aider ?'),
    ('default_llm',     'openai'),
    ('new_users_active','1');
