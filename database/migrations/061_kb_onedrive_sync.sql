-- Quatrième source de synchronisation distante : Microsoft OneDrive
-- ('onedrive'), lu avec les droits DÉLÉGUÉS d'un utilisateur connecté
-- (MicrosoftOAuth + UserOneDriveService) et non avec une application
-- « sans utilisateur » comme SharePoint.
--
-- Les colonnes sync_* (migration 049) et sync_owner_user_id (migration 059)
-- couvrent déjà tout le besoin : sync_folder_ref porte « {driveId}:{itemId} »
-- (ou une URL de partage), sync_owner_user_id désigne la connexion Microsoft
-- qui lit le dossier — OBLIGATOIRE ici, une base OneDrive sans propriétaire
-- n'a aucun moyen de s'authentifier. Seule l'ENUM de provenance s'élargit.

ALTER TABLE knowledge_base_documents
    MODIFY COLUMN source ENUM('upload','drive','sharepoint','gcalendar','onedrive') NOT NULL DEFAULT 'upload';
