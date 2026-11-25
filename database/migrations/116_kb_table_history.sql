-- Historique des tables PERMANENTES (bases de connaissances de nature « table »).
--
-- Jusqu'ici une table ne gardait qu'UNE version d'avant (`prev_*` de
-- knowledge_base_tables) et les retouches à la main modifiaient la version en
-- service sans laisser de trace. Désormais chaque ÉTAT est une ligne ici :
--   - une écriture d'ensemble (fichier importé, colonne supprimée, MIA après
--     accord, livraison d'un workflow, retour en arrière) écrit un état ;
--   - les retouches à la main d'une même personne sont regroupées en un seul
--     état tant qu'elle y travaille (quart d'heure, KbTableHistory).
-- Les lignes de chaque état gardé restent dans knowledge_base_table_rows,
-- rangées par leur numéro de version : revenir à un état COPIE ses lignes dans
-- un nouvel état — rien n'est effacé, et le retour se défait.
--
-- `kind`   : create | import | edit | column | mia | workflow | restore | earlier
--            (earlier = état antérieur à l'historique, repris ci-dessous) ;
-- `user_id`: qui a agi (ou donné son accord à MIA, ou lancé le workflow) ;
-- `detail_json` : de quoi écrire la phrase à l'affichage, dans la langue de
--            l'écran (compteurs, noms de fichier, de colonne, de workflow) ;
-- `rows_kept` : 0 = les lignes de cet état ne sont plus gardées (la création
--            reste inscrite au-delà de la durée de conservation) ;
-- `updated_at` : dernière retouche d'un état regroupé.
CREATE TABLE IF NOT EXISTS knowledge_base_table_versions (
    kb_id        INT UNSIGNED      NOT NULL,
    version      INT UNSIGNED      NOT NULL,
    kind         VARCHAR(16)       NOT NULL,
    user_id      INT UNSIGNED      NULL,
    detail_json  TEXT              NULL,
    columns_json MEDIUMTEXT        NOT NULL,
    row_count    INT UNSIGNED      NOT NULL DEFAULT 0,
    column_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rows_kept    TINYINT(1)        NOT NULL DEFAULT 1,
    created_at   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (kb_id, version),
    CONSTRAINT fk_kb_table_versions_kb FOREIGN KEY (kb_id)
        REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    CONSTRAINT fk_kb_table_versions_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reprise : l'état en service de chaque table existante. Une table jamais
-- réécrite (version 1, aucune version d'avant) commence par sa création ;
-- sinon l'état est « antérieur à l'historique », avec ce qui l'avait écrit.
INSERT IGNORE INTO knowledge_base_table_versions
    (kb_id, version, kind, user_id, detail_json, columns_json, row_count, column_count, rows_kept, created_at, updated_at)
SELECT t.kb_id,
       t.version,
       IF(t.prev_version IS NULL AND t.version = 1, 'create', 'earlier'),
       IF(t.prev_version IS NULL AND t.version = 1,
          (SELECT u.id FROM users u WHERE u.id = kb.created_by),
          t.updated_by),
       IF(t.prev_version IS NULL AND t.version = 1,
          CONCAT('{"origin":', COALESCE(NULLIF(t.origin_json, ''), '{}'), '}'),
          JSON_OBJECT('reason', COALESCE(t.prev_reason, ''))),
       t.columns_json,
       t.row_count,
       t.column_count,
       1,
       IF(t.prev_version IS NULL AND t.version = 1, kb.created_at, COALESCE(t.prev_saved_at, t.updated_at)),
       t.updated_at
  FROM knowledge_base_tables t
  JOIN knowledge_bases kb ON kb.id = t.kb_id;

-- … et sa version d'avant, s'il y en avait une.
INSERT IGNORE INTO knowledge_base_table_versions
    (kb_id, version, kind, user_id, detail_json, columns_json, row_count, column_count, rows_kept, created_at, updated_at)
SELECT t.kb_id,
       t.prev_version,
       IF(t.prev_version = 1, 'create', 'earlier'),
       IF(t.prev_version = 1, (SELECT u.id FROM users u WHERE u.id = kb.created_by), NULL),
       -- L'origine en service a pu venir d'un fichier importé depuis : elle
       -- n'est pas celle de la création, mieux vaut n'en rien dire.
       '{}',
       t.prev_columns_json,
       COALESCE(t.prev_row_count, 0),
       COALESCE(JSON_LENGTH(t.prev_columns_json), 0),
       IF(EXISTS(SELECT 1 FROM knowledge_base_table_rows r WHERE r.kb_id = t.kb_id AND r.version = t.prev_version), 1, 0),
       kb.created_at,
       COALESCE(t.prev_saved_at, kb.created_at)
  FROM knowledge_base_tables t
  JOIN knowledge_bases kb ON kb.id = t.kb_id
 WHERE t.prev_version IS NOT NULL
   AND t.prev_columns_json IS NOT NULL;
