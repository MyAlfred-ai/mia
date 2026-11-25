-- Tables de travail d'une conversation (src/Tables/, outils `collecter_dans_table`,
-- `croiser_tables`, `transformer_table`, `apercu_table`, `exporter_table`).
--
-- LE PROBLÈME RÉSOLU. Un recoupement exhaustif (« tous les emails de mes 240
-- clients », « quels fichiers du dossier ne sont cités dans aucun mail ») ne tient
-- pas dans la mémoire d'un modèle : il saute des lignes, résume, invente un
-- total. Ici la LISTE vit en base ; le modèle n'en tient que la poignée (nom,
-- colonnes, nombre de lignes, quelques exemples) et demande au serveur de
-- collecter, croiser, filtrer, compter, exporter.
--
-- Deux tables et non un blob par table : une ligne de travail se relit par
-- pages (aperçu) et s'écrira par lots (étapes suivantes : enrichissement et
-- actions ligne par ligne) sans réécrire tout le contenu.
--
-- Effacées avec la conversation (CASCADE) : c'est du contenu, comme les
-- messages — et, comme eux, hors de la sauvegarde de l'instance.
CREATE TABLE IF NOT EXISTS work_tables (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- INT UNSIGNED, comme `conversations.id` et `users.id` (MySQL 3780 sinon).
    conversation_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    -- Nom court donné par le modèle (minuscules, chiffres, « _ ») : le même
    -- nom REMPLACE la table.
    name            VARCHAR(60)  NOT NULL,
    title           VARCHAR(160) NOT NULL,
    -- [{key, label, type: text|number|date, tech?}]
    columns_json    TEXT         NOT NULL,
    row_count       INT UNSIGNED NOT NULL DEFAULT 0,
    -- D'où vient la table (collecte, croisement, transformation…) : dit à
    -- l'utilisateur et rappelé au modèle, jamais relu comme une consigne.
    origin_json     TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_work_table_name (conversation_id, name),
    KEY idx_work_table_user (user_id),
    CONSTRAINT fk_work_table_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_table_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_table_rows (
    table_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    -- Objet JSON clé de colonne → valeur (texte).
    data     MEDIUMTEXT   NOT NULL,
    PRIMARY KEY (table_id, position),
    CONSTRAINT fk_work_table_rows_table FOREIGN KEY (table_id)
        REFERENCES work_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
