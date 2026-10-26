-- Correctif de visibilité : re-synchronise is_personal d'après le rôle réel du
-- créateur. Symptôme corrigé : des assistants "organisation" (créés par un
-- admin, ou historiques sans créateur) se retrouvaient flaggés is_personal=1
-- (ex. créateur promu admin après coup, ou flag posé à tort), donc invisibles
-- pour tous les utilisateurs dans le chat alors qu'ils doivent être visibles
-- par tout le monde.
--
-- Règle : tout assistant dont le créateur N'EST PAS un "creator" (admin, autre
-- rôle, ou created_by NULL) est un assistant d'organisation → is_personal=0
-- (visible par tous). Les assistants appartenant à un "creator" ne sont pas
-- touchés : ils restent personnels (visibles par leur seul créateur).
--
-- Idempotent : peut être ré-appliqué sans effet de bord.

UPDATE profiles p
    LEFT JOIN users u ON u.id = p.created_by
    SET p.is_personal = 0
    WHERE p.is_personal = 1
      AND (p.created_by IS NULL OR u.role <> 'creator');
