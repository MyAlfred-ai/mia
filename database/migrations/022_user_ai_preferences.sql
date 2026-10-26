-- Préférences IA par utilisateur (style de réponse + contexte personnel)
ALTER TABLE users
    ADD COLUMN ai_style VARCHAR(20) NOT NULL DEFAULT '',
    ADD COLUMN ai_user_context VARCHAR(600) NOT NULL DEFAULT '';
