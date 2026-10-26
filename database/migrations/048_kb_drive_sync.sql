-- Synchronisation d'une base de connaissances avec un dossier Google Drive
-- partagé « Tous les utilisateurs disposant du lien » (lecture via clé API).
--
-- knowledge_bases : configuration (dossier, activation) + état du dernier
-- cycle de synchronisation (statut, erreur, rapport JSON).
-- knowledge_base_documents : provenance ('upload' | 'drive') + identifiants
-- Drive pour le diff — md5 pour les binaires, modifiedTime (RFC3339 brut,
-- comparé comme chaîne opaque) pour les Google Docs natifs qui n'ont pas
-- de checksum.

ALTER TABLE knowledge_bases
    ADD COLUMN drive_folder_id    VARCHAR(128) NULL DEFAULT NULL,
    ADD COLUMN drive_folder_name  VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN drive_sync_enabled TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN drive_last_sync_at DATETIME     NULL DEFAULT NULL,
    ADD COLUMN drive_sync_status  VARCHAR(20)  NULL DEFAULT NULL,
    ADD COLUMN drive_sync_error   VARCHAR(500) NULL DEFAULT NULL,
    ADD COLUMN drive_sync_report  TEXT         NULL,
    ADD KEY idx_kb_drive_due (drive_sync_enabled, drive_last_sync_at);

ALTER TABLE knowledge_base_documents
    ADD COLUMN source            ENUM('upload','drive') NOT NULL DEFAULT 'upload',
    ADD COLUMN drive_file_id     VARCHAR(128) NULL DEFAULT NULL,
    ADD COLUMN drive_md5         VARCHAR(64)  NULL DEFAULT NULL,
    ADD COLUMN drive_modified_at VARCHAR(40)  NULL DEFAULT NULL,
    ADD UNIQUE KEY uq_kbd_kb_drive_file (kb_id, drive_file_id);
