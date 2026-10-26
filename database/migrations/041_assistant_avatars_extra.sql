-- Trois avatars système supplémentaires (prénoms anciens, consonance anglaise).
-- Fichiers bakés dans profils-defaults/, semés dans profils/ au démarrage.
-- INSERT IGNORE : ré-applicable sans écraser un nom déjà personnalisé.
INSERT IGNORE INTO assistant_avatars (filename, name) VALUES
    ('alfred-mabel.png', 'Mabel'),
    ('alfred-edith.png', 'Edith'),
    ('alfred-agnes.png', 'Agnes');
