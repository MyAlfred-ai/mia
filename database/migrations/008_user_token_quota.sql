-- Quota mensuel en millions de tokens (NULL = illimité)
ALTER TABLE users ADD COLUMN token_quota INT UNSIGNED DEFAULT NULL;
