-- Assistant "global" : visible par TOUS les utilisateurs, indépendamment des
-- groupes (contrairement aux assistants d'organisation, filtrés par groupe).
-- Utilisé par l'assistant système « MIA : mode d'emploi » (DefaultAssistant).
ALTER TABLE profiles
    ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER is_personal;
