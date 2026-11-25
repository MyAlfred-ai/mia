-- Tables PERMANENTES, dans les bases de connaissances.
--
-- Une table de travail vit avec sa conversation (migration 111). Une table
-- permanente est une base de connaissances d'une troisième nature : ni
-- documents vectorisés (`local`), ni cerveau interrogé en direct (`mybrain`),
-- mais des LIGNES — un fichier clients, une grille de tarifs — que MIA compte,
-- filtre et croise côté serveur. Elle garde une ligne `knowledge_bases`
-- ordinaire : partage par groupe, « Uniquement pour moi », liaison aux
-- assistants, sélecteur du chat, compteurs d'utilisation — tout ce qui existe
-- pour une base vaut pour elle. Les chemins réservés aux documents (dépôt,
-- réindexation, synchronisation, collection Qdrant) la refusent. Son trio
-- embedding_* vaut '' / '' / 0 : il n'y a rien à vectoriser.
ALTER TABLE knowledge_bases
    MODIFY COLUMN backend ENUM('local', 'mybrain', 'table') NOT NULL DEFAULT 'local';

-- La fiche d'une table : ses colonnes, son nombre de lignes, d'où elle vient.
-- Table à part, comme knowledge_base_remotes : `knowledge_bases` est lue en
-- `kb.*` partout, la liste des colonnes n'a rien à faire dans ces lectures.
--
-- `version` désigne les lignes EN SERVICE dans knowledge_base_table_rows.
-- Une écriture d'ensemble (fichier importé, colonne supprimée, modification
-- par MIA après accord) écrit une nouvelle version et garde la précédente
-- (`prev_*`) : un retour en arrière reste possible depuis la fiche, une fois.
-- Les retouches à la main (une cellule, une ligne) modifient la version en
-- service, sans copie.
--
-- `mia_write` : MIA peut-elle y écrire (toujours sous carte d'approbation) ?
-- 0 = lecture seule.
--
-- `updated_at` est posée explicitement à chaque changement de CONTENU (pas de
-- ON UPDATE) : régler `mia_write` ne doit pas dater la table d'aujourd'hui.
CREATE TABLE IF NOT EXISTS knowledge_base_tables (
    -- INT UNSIGNED, comme `knowledge_bases.id` (MySQL 3780 sinon).
    kb_id             INT UNSIGNED      NOT NULL,
    -- [{key, label, type: text|number|date}]
    columns_json      MEDIUMTEXT        NOT NULL,
    row_count         INT UNSIGNED      NOT NULL DEFAULT 0,
    column_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    version           INT UNSIGNED      NOT NULL DEFAULT 1,
    mia_write         TINYINT(1)        NOT NULL DEFAULT 1,
    -- {kind: fichier|saisie|conversation, file?, sheet?, conversation_id?, table?}
    origin_json       TEXT              NULL,
    prev_version      INT UNSIGNED      NULL,
    prev_columns_json MEDIUMTEXT        NULL,
    prev_row_count    INT UNSIGNED      NULL,
    prev_saved_at     DATETIME          NULL,
    -- Ce qui a remplacé la version d'avant (« Fichier x.xlsx importé »…).
    prev_reason       VARCHAR(190)      NULL,
    updated_by        INT UNSIGNED      NULL,
    updated_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (kb_id),
    CONSTRAINT fk_kb_tables_kb FOREIGN KEY (kb_id)
        REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    CONSTRAINT fk_kb_tables_user FOREIGN KEY (updated_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les lignes, une par enregistrement, rangées par version : la version en
-- service et, au plus, celle d'avant.
CREATE TABLE IF NOT EXISTS knowledge_base_table_rows (
    kb_id    INT UNSIGNED NOT NULL,
    version  INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    -- Objet JSON clé de colonne → valeur (texte).
    data     MEDIUMTEXT   NOT NULL,
    PRIMARY KEY (kb_id, version, position),
    CONSTRAINT fk_kb_table_rows_kb FOREIGN KEY (kb_id)
        REFERENCES knowledge_bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
