-- Renommage produit : myLLM → MIA.
-- Ne touche que les installations restées sur les valeurs par défaut
-- (un nom ou un logo personnalisé par l'admin est conservé).
UPDATE settings SET `value` = 'MIA' WHERE `key` = 'app_name' AND `value` = 'myLLM';

-- Le fichier logo.png par défaut a changé (nouvelle icône MIA) :
-- cache-busting pour les navigateurs qui ont l'ancien en cache.
UPDATE settings SET `value` = '/assets/img/logo.png?v=mia' WHERE `key` = 'logo_url' AND `value` = '/assets/img/logo.png';
