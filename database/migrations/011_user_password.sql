-- Support authentification email/mot de passe
ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL;
