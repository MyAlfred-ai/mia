-- Bibliothèque nommée d'avatars d'assistants.
-- Avant : le « nom » d'un avatar était dérivé du nom de fichier (fragile).
-- Désormais chaque avatar (fichier dans public/assets/img/profils/) porte un
-- nom propre, éditable, et trace son créateur.

CREATE TABLE IF NOT EXISTS assistant_avatars (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename   VARCHAR(255) NOT NULL,
    name       VARCHAR(120) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assistant_avatars_filename (filename),
    CONSTRAINT fk_assistant_avatars_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Noms lisibles pour les avatars système (les fichiers vivent dans
-- profils-defaults/ et sont semés dans profils/ au démarrage). INSERT IGNORE :
-- ré-applicable sans écraser un nom déjà personnalisé.
INSERT IGNORE INTO assistant_avatars (filename, name) VALUES
    ('alfred-calcul.png',  'Alfred Calcul'),
    ('alfred-lecteur.png', 'Alfred Lecteur'),
    ('alfred-loupe.png',   'Alfred Loupe'),
    ('alfred-peintre.png', 'Alfred Peintre'),
    ('alfred-poete.png',   'Alfred Poète'),
    ('alfred-robot.png',   'Alfred Robot'),
    ('alfred-serveur.png', 'Alfred Serveur'),
    ('alfred-vieux.png',   'Alfred Sage'),
    ('alfred-yoda.png',    'Alfred Yoda');
