-- « Claude dans Office » (add-in Office d'Anthropic) branché sur MIA comme
-- passerelle (OfficeGateway) : l'add-in envoie ses requêtes à MIA, qui les
-- relaie à Anthropic avec la clé de l'instance et les compte en crédits.
--
-- Jeton PERSONNEL, pour l'organisation qui ne règle pas Entra ID (sinon,
-- l'add-in envoie le jeton Microsoft de la personne, rien à stocker ici) :
-- un par personne, généré dans Réglages → Claude dans Office, collé une fois
-- dans l'add-in. Seule son empreinte SHA-256 est gardée ; `hint` = ses
-- derniers caractères, pour le reconnaître à l'écran.
CREATE TABLE office_addin_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    hint         VARCHAR(12)  NOT NULL DEFAULT '',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_office_addin_user (user_id),
    UNIQUE KEY uq_office_addin_hash (token_hash),
    CONSTRAINT fk_office_addin_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
