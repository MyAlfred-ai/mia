-- Mémoire, apprendre des GESTES (lot 1 de « mieux répondre au fil de ce
-- qu'on apprend ») : MIA compte ce que la personne FAIT — une réponse qu'elle
-- fait raccourcir, un brouillon d'email qu'elle passe au vouvoiement, un
-- préréglage « Plus formel » choisi encore et encore — et, quand un geste se
-- répète, lui propose d'en faire une préférence.
--
-- 1. Un souvenir porte une PORTÉE : `all` (partout, le défaut — tous les
--    souvenirs existants le reçoivent par la seule valeur de colonne, aucun
--    UPDATE : `updated_at` prendrait sinon la date du déploiement), `email`,
--    `document` ou `code`. « Préfère des réponses plus courtes » ne vaut
--    peut-être que pour les emails.
-- 2. `signal_key` = le geste qui a fait naître la proposition (« shorter:email ») :
--    jamais reproposée tant que la ligne existe — empreinte d'un refus comprise.
-- 3. `memory_signal_events` : une ligne par geste constaté, SANS AUCUN TEXTE
--    (le geste, sa portée, une référence d'unicité, la conversation). Pas de clé
--    étrangère sur la conversation : COUNT(DISTINCT) compterait sinon moins
--    après une suppression. Purgée au-delà de 90 jours par l'entretien de la
--    mémoire.
ALTER TABLE user_memories
    ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'all' AFTER theme,
    ADD COLUMN signal_key VARCHAR(40) NULL DEFAULT NULL AFTER fingerprint,
    ADD KEY idx_um_signal (user_id, signal_key);

CREATE TABLE memory_signal_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    gesture VARCHAR(20) NOT NULL,
    scope VARCHAR(10) NOT NULL,
    ref VARCHAR(40) NOT NULL,
    conversation_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mse_ref (user_id, gesture, scope, ref),
    KEY idx_mse_count (user_id, gesture, scope, created_at),
    KEY idx_mse_age (created_at),
    CONSTRAINT fk_mse_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
