-- Comptes de démonstration : une enveloppe de crédits utilisable une seule
-- fois, sans renouvellement mensuel (cf. src/Billing/DemoAccount.php).
--   demo_credits     NULL = compte ordinaire ; sinon l'enveloppe, en crédits.
--   demo_started_at  début du décompte : seule la consommation postérieure
--                    entame l'enveloppe (un compte existant passé en démo ne
--                    part pas déjà entamé).
--   demo_alert_muted l'administrateur ne veut plus voir la pastille « démo
--                    épuisée » de ce compte ; remis à 0 quand l'enveloppe change.
ALTER TABLE users
    ADD COLUMN demo_credits INT UNSIGNED NULL DEFAULT NULL AFTER credit_quota_k,
    ADD COLUMN demo_started_at DATETIME NULL DEFAULT NULL AFTER demo_credits,
    ADD COLUMN demo_alert_muted TINYINT(1) NOT NULL DEFAULT 0 AFTER demo_started_at;
