-- Généralisation de la synchronisation Google Drive (migration 048) en
-- synchronisation de dossier distant multi-fournisseurs :
-- 'drive' (Google Drive) | 'sharepoint' (Microsoft SharePoint via Graph).
--
-- Une base = UNE source distante à la fois (sync_provider) ; l'upload manuel
-- (source='upload') cohabite et n'est jamais touché par la synchronisation.
--
-- sync_folder_ref : id de dossier Google Drive OU URL du dossier SharePoint
-- (résolue à chaque cycle via l'API /shares de Microsoft Graph) — d'où
-- l'élargissement à VARCHAR(500).
-- sync_hash : md5Checksum (Drive) ou quickXorHash (Graph), comparé opaque.
-- sync_modified_at : horodatage distant brut, comparé comme chaîne opaque.

ALTER TABLE knowledge_bases
    ADD COLUMN sync_provider VARCHAR(20) NULL DEFAULT NULL AFTER is_system,
    DROP KEY idx_kb_drive_due,
    CHANGE COLUMN drive_folder_id    sync_folder_ref  VARCHAR(500) NULL DEFAULT NULL,
    CHANGE COLUMN drive_folder_name  sync_folder_name VARCHAR(255) NULL DEFAULT NULL,
    CHANGE COLUMN drive_sync_enabled sync_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
    CHANGE COLUMN drive_last_sync_at sync_last_at     DATETIME     NULL DEFAULT NULL,
    CHANGE COLUMN drive_sync_status  sync_status      VARCHAR(20)  NULL DEFAULT NULL,
    CHANGE COLUMN drive_sync_error   sync_error       VARCHAR(500) NULL DEFAULT NULL,
    CHANGE COLUMN drive_sync_report  sync_report      TEXT         NULL,
    ADD KEY idx_kb_sync_due (sync_enabled, sync_last_at);

-- Les bases déjà synchronisées avec Drive gardent leur configuration.
UPDATE knowledge_bases SET sync_provider = 'drive' WHERE sync_folder_ref IS NOT NULL;

ALTER TABLE knowledge_base_documents
    MODIFY COLUMN source ENUM('upload','drive','sharepoint') NOT NULL DEFAULT 'upload',
    DROP KEY uq_kbd_kb_drive_file,
    CHANGE COLUMN drive_file_id     sync_file_id     VARCHAR(128) NULL DEFAULT NULL,
    CHANGE COLUMN drive_md5         sync_hash        VARCHAR(64)  NULL DEFAULT NULL,
    CHANGE COLUMN drive_modified_at sync_modified_at VARCHAR(40)  NULL DEFAULT NULL,
    ADD UNIQUE KEY uq_kbd_kb_sync_file (kb_id, sync_file_id);
