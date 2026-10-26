-- Bases de connaissances « en direct » : le contenu ne vit pas dans MIA mais
-- dans un cerveau MyBrain, interrogé à chaque message par son API (clé
-- d'application, POST /api/v1/contexte). Rien n'est copié — ni documents, ni
-- extraits, ni vecteurs : MyBrain reste la seule source de vérité, et ses
-- propres contrôles d'accès (zone RH, sources brutes) s'appliquent à ce que
-- MIA reçoit. Une copie y échapperait, et vieillirait entre deux passages.
--
-- `backend` sépare les deux natures. Une base MyBrain garde une ligne
-- `knowledge_bases` ordinaire : partage par groupe, liaison aux assistants,
-- sélecteur du chat, compteurs d'utilisation — tout ce qui existe vaut pour
-- elle. Les chemins réservés aux documents (dépôt, réindexation,
-- synchronisation, collection Qdrant) la refusent. Le trio embedding_* y vaut
-- '' / '' / 0 : il n'y a rien à vectoriser.
ALTER TABLE knowledge_bases
    ADD COLUMN backend ENUM('local', 'mybrain') NOT NULL DEFAULT 'local' AFTER description;

-- La connexion d'une base en direct. Table à part, et non des colonnes de
-- plus : `knowledge_bases` est lue en `kb.*` partout (liste, sélecteur, chat,
-- sauvegarde), une clé chiffrée n'a rien à faire dans ces lectures.
--
-- La clé n'est jamais stockée en clair (Encryption, AES-256) ; `key_hint`
-- (« mbk_AbCd…wxyz ») sert seulement à la reconnaître dans l'écran.
-- `app_name` et `rights` recopient ce que MyBrain a répondu au dernier test
-- (GET /api/v1) : le nom que l'administrateur de MyBrain a donné à la clé et
-- ses droits (« lire », « nourrir »). `status` est le dernier constat —
-- `refused` (clé supprimée, droit retiré : la base se grise) ou `unreachable`
-- (panne passagère : la base reste cochable, le message part sans elle).
-- `failures` / `retry_after` forment le disjoncteur : après plusieurs échecs
-- d'affilée, on cesse d'appeler une minute plutôt que d'ajouter l'attente d'un
-- serveur absent à chaque message de chaque utilisateur.
CREATE TABLE IF NOT EXISTS knowledge_base_remotes (
    -- INT UNSIGNED, comme `knowledge_bases.id` : un INT signé fait échouer la
    -- clé étrangère (« incompatible », MySQL 3780).
    kb_id        INT UNSIGNED      NOT NULL,
    base_url     VARCHAR(500)      NOT NULL,
    api_key_enc  TEXT              NOT NULL,
    key_hint     VARCHAR(40)       NOT NULL DEFAULT '',
    app_name     VARCHAR(120)      NOT NULL DEFAULT '',
    rights       VARCHAR(60)       NOT NULL DEFAULT '',
    status       ENUM('ok', 'refused', 'unreachable') NULL DEFAULT NULL,
    status_error VARCHAR(500)      NULL DEFAULT NULL,
    checked_at   DATETIME          NULL DEFAULT NULL,
    failures     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    retry_after  DATETIME          NULL DEFAULT NULL,
    updated_at   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kb_id),
    CONSTRAINT fk_kbr_kb FOREIGN KEY (kb_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Cochée par défaut » : pour les membres de ce groupe (ou pour cette
-- personne), la base est déjà cochée dans chaque NOUVELLE conversation — et se
-- décoche comme une autre. Une conversation existante garde son choix : c'est
-- une intention enregistrée, pas une préférence qu'un réglage réécrirait.
-- Proposé par l'écran pour les seules bases en direct.
ALTER TABLE knowledge_base_shares
    ADD COLUMN default_on TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage;
