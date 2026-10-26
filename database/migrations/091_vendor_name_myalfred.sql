-- Nom de l'éditeur de l'application, affiché sur la page À propos
-- (« Découvrez l'univers … ») : MyAlfred.ai, le nom de la société, et non plus
-- « My Alfred ».
--
-- Seule la valeur LIVRÉE par 058 change : une instance qui a écrit son propre
-- nom d'éditeur (Configuration → Marque & apparence) le garde.
UPDATE settings SET `value` = 'MyAlfred.ai' WHERE `key` = 'vendor_name' AND `value` = 'My Alfred';
