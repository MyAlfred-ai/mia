-- Lien « Documentation » de la page À propos, désormais réglable par chaque
-- installation. INSERT IGNORE : une valeur déjà présente n'est pas écrasée
-- (feedback_url existe partout depuis 058, la ligne ne sert qu'aux bases neuves).
INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('docs_url', 'https://docs.myalfred.ai/mia/'),
    ('feedback_url', '');
