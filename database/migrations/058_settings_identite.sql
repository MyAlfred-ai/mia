-- Identité et liens de l'éditeur, jusqu'ici codés en dur dans les gabarits.
--
-- Sur une installation ailleurs que chez My Alfred, ces valeurs étaient fausses
-- sans qu'aucun réglage ne permette de les corriger : le rôle « staff » se
-- déduisait du suffixe d'e-mail @myalfred.ai (donc personne n'était staff), le
-- lien « Déclarer une idée ou un bug » pointait vers un projet tiers, et la page
-- À propos affichait les coordonnées d'une autre société.
--
-- INSERT IGNORE : les valeurs par défaut reproduisent le comportement actuel,
-- sauf le lien de retour, à configurer par chaque éditeur. Une instance qui
-- aurait déjà personnalisé une valeur n'est pas écrasée.
--
-- Une valeur VIDE désactive proprement l'élément côté interface (aucun staff,
-- pas de lien de retour, pas de bloc contact) — c'est le réglage attendu pour
-- un déploiement chez un tiers.

INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('staff_email_domain', 'myalfred.ai'),
    ('docs_url',           'https://docs.myalfred.ai/mia/'),
    ('feedback_url',       ''),
    ('contact_email',      'contact@myalfred.ai'),
    ('vendor_url',         'https://myalfred.ai'),
    ('vendor_name',        'My Alfred');
