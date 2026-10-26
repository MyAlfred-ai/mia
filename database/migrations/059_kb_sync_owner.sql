-- Synchronisation Google Drive via la connexion PERSONNELLE d'un utilisateur
-- (OAuth, Paramètres → Connexions) : lit un dossier partagé NOMINATIVEMENT
-- avec lui — plus besoin du partage public « Tous les utilisateurs disposant
-- du lien » qu'impose la clé API simple.
--
-- sync_owner_user_id : NULL = accès par la clé API globale (comportement
-- historique) ; renseigné = accès avec les droits Drive de cet utilisateur.
--
-- VOLONTAIREMENT sans clé étrangère : un ON DELETE SET NULL ferait basculer
-- la base sur la clé API globale en silence à la suppression du propriétaire
-- (le dossier, partagé nominativement, n'est probablement PAS public — la
-- sync se mettrait à échouer avec un message hors sujet). L'id conservé fait
-- échouer le cycle avec le bon message : « connexion Drive absente ».
ALTER TABLE knowledge_bases
    ADD COLUMN sync_owner_user_id INT UNSIGNED NULL DEFAULT NULL AFTER sync_provider;
