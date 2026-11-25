-- Sections repliées du volet de gauche, gardées sur le COMPTE
-- (cf. SidebarPreferences) : Espaces, Épinglées, Historique, carte du compte
-- et périodes de l'historique. Elles vivaient en localStorage, donc par
-- navigateur : perdues d'un appareil à l'autre ou après un nettoyage.
--
-- JSON des seuls CHOIX de la personne ; NULL = rien de choisi, les défauts
-- s'appliquent (Espaces et Épinglées repliés). Même patron que ui_theme (086) :
-- User fait des SELECT *, la colonne remonte dans la session sans requête.
ALTER TABLE users
    ADD COLUMN ui_sidebar VARCHAR(2000) NULL DEFAULT NULL;
