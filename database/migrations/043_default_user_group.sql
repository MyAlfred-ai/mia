-- Groupe « Par défaut » : contient TOUS les utilisateurs, non supprimable.
-- Ses droits (assistants, outils, LLMs) forment le socle commun de la plateforme.
ALTER TABLE user_groups ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0;

-- Promeut un éventuel groupe déjà nommé « Par défaut » (collation insensible à la casse/accents),
-- sinon le crée. Garantit exactement un groupe par défaut.
UPDATE user_groups SET is_default = 1 WHERE name = 'Par défaut' LIMIT 1;

INSERT INTO user_groups (name, is_default, is_active)
SELECT 'Par défaut', 1, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM user_groups WHERE is_default = 1);

-- Tous les utilisateurs existants deviennent membres du groupe par défaut.
INSERT IGNORE INTO user_group_members (user_id, group_id)
SELECT u.id, g.id FROM users u CROSS JOIN user_groups g WHERE g.is_default = 1;
