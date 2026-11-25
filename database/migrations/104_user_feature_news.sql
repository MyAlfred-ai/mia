-- Nouveautés MAJEURES vues par chaque personne (MajorFeatures).
--
-- Une ligne par personne × nouveauté majeure (identifiant de docs/NOUVEAUTES.md
-- marqué `majeure: oui`) :
--   - `shown_at`   : la fenêtre « Quoi de neuf » l'a montrée (ou la personne
--                    est allée la voir avant) — elle ne revient plus ;
--   - `visited_at` : la personne a ouvert la page où elle se trouve — la
--                    pastille verte s'éteint.
-- Aucune ligne n'est créée au déploiement : une nouveauté sans ligne est
-- encore à montrer (si la personne y a accès et existait avant elle).
CREATE TABLE user_feature_news (
    user_id     INT UNSIGNED NOT NULL,
    feature_id  VARCHAR(64)  NOT NULL,
    shown_at    DATETIME     NULL,
    visited_at  DATETIME     NULL,
    PRIMARY KEY (user_id, feature_id),
    CONSTRAINT fk_feature_news_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
