-- Épinglage de conversations : flag + timestamp pour tri stable.
-- Max 5 conversations épinglées par utilisateur (vérifié en applicatif).
-- pinned_at NULL ⇒ non épinglée ; sinon date d'épinglage (les plus récentes en haut).

ALTER TABLE conversations
    ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN pinned_at DATETIME DEFAULT NULL;

CREATE INDEX idx_conv_user_pinned ON conversations(user_id, is_pinned, pinned_at);
