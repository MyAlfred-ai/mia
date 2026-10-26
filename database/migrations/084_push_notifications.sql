-- Notifications sur les appareils (Web Push) : un résultat de tâche programmée
-- arrive alors que la personne n'est pas devant MIA.
--
-- `scheduled_tasks.notify` : la tâche prévient-elle quand son résultat
-- arrive ? Oui par défaut — sans appareil inscrit, cela ne fait rien.
--
-- `push_subscriptions` : une ligne par navigateur inscrit (l'adresse que lui a
-- donnée son service de notification, et les deux clés de chiffrement de
-- RFC 8291). L'adresse est un secret : qui la possède peut écrire à cet
-- appareil — d'où son empreinte UNIQUE, qui sert aussi à la retrouver sans
-- l'écrire dans une requête. Un navigateur n'appartient qu'à UN compte à la
-- fois : réinscrit par un autre, il change de propriétaire.
ALTER TABLE scheduled_tasks
    ADD COLUMN notify TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_actions;

CREATE TABLE push_subscriptions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    endpoint        VARCHAR(2048) NOT NULL,
    endpoint_hash   CHAR(64)     NOT NULL,
    p256dh          VARCHAR(128) NOT NULL,
    auth            VARCHAR(64)  NOT NULL,
    label           VARCHAR(120) NOT NULL DEFAULT '',
    failures        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_success_at DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY idx_push_user (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
