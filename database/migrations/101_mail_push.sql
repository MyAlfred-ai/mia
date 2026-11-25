-- Déclencheur « email reçu » des workflows en mode PUSH : Gmail (users.watch
-- → Google Cloud Pub/Sub) et Outlook (abonnement Microsoft Graph) préviennent
-- MIA dès qu'un email arrive, au lieu d'une vérification toutes les 5 minutes.
--
-- Une ligne par boîte suivie (une personne, un fournisseur), partagée par
-- tous ses workflows qui surveillent cette boîte. La notification ne fait que
-- RÉVEILLER la vérification existante (WorkflowTriggers) : les messages sont
-- relus avec le compte de la personne, jamais pris dans la notification.
--
--  - account     : adresse de la boîte (Gmail : Pub/Sub ne dit que l'adresse) ;
--  - external_id : identifiant de l'abonnement Graph (Outlook) ;
--  - secret_hash : SHA-256 du clientState qui signe les notifications Graph ;
--  - endpoint    : adresse prévenue (Outlook) ou topic (Gmail) — changés,
--                  l'abonnement est recréé ;
--  - expires_at, renewed_at, retry_at : UTC, posés par PHP.
CREATE TABLE mail_push_subscriptions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    provider     VARCHAR(16)  NOT NULL,
    account      VARCHAR(255) NOT NULL DEFAULT '',
    external_id  VARCHAR(255) NULL,
    secret_hash  CHAR(64)     NULL,
    endpoint     VARCHAR(500) NOT NULL DEFAULT '',
    status       VARCHAR(16)  NOT NULL DEFAULT 'active',
    error        VARCHAR(300) NOT NULL DEFAULT '',
    expires_at   DATETIME     NULL,
    renewed_at   DATETIME     NULL,
    retry_at     DATETIME     NULL,
    notified_at  DATETIME     NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mail_push_user (user_id, provider),
    KEY idx_mail_push_external (external_id),
    KEY idx_mail_push_account (provider, account),
    CONSTRAINT fk_mail_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
