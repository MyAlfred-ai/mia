-- Un connecteur RELIÉ (ou relié à nouveau) s'allume tout seul dans le menu
-- « + » du chat : la connexion pose ce drapeau, le premier affichage du chat
-- qui suit le lit et l'efface (UserDriveToken::takeChatEnablePending), puis
-- le navigateur allume l'interrupteur — que la personne peut éteindre ensuite.
-- Porté par la ligne du jeton : il disparaît avec la déconnexion, et la
-- connexion native (navigateur système, sans session) le pose aussi.
-- 0 par défaut : les comptes déjà reliés ne s'allument pas au déploiement.
ALTER TABLE user_drive_tokens
    ADD COLUMN chat_enable_pending TINYINT(1) NOT NULL DEFAULT 0;
