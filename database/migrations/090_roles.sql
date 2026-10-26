-- Rôles et droits (Utilisateurs → Rôles, src/Models/Role.php,
-- src/Auth/Permissions.php).
--
-- `users.role` garde l'identifiant (slug) du rôle : `admin`, `creator`,
-- `user`, ou celui d'un rôle personnalisé. Aucune colonne de `users` ne
-- change, aucun compte n'est touché : les droits des trois rôles livrés sont
-- définis dans le code (Permissions::DEFAULTS) et reproduisent exactement
-- ceux d'avant cette migration.
--
-- `permissions` : carte JSON droit → oui/non. NULL = droits par défaut d'un
-- rôle livré. Pour le créateur et l'utilisateur, une surcharge éventuelle ;
-- pour un rôle personnalisé, ses droits (un droit absent de la carte — ajouté
-- par une version future — est refusé). L'administrateur est verrouillé : sa
-- ligne n'est là que pour l'inventaire.
CREATE TABLE IF NOT EXISTS roles (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug         VARCHAR(20)  NOT NULL,
    name         VARCHAR(60)  NOT NULL DEFAULT '',
    description  VARCHAR(255) NOT NULL DEFAULT '',
    color        VARCHAR(7)   NOT NULL DEFAULT '',
    permissions  TEXT         NULL DEFAULT NULL,
    is_system    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (slug, name, is_system) VALUES
    ('admin',   'Administrateur', 1),
    ('creator', 'Créateur',       1),
    ('user',    'Utilisateur',    1);
