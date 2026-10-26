-- Les anciens uploads logo-custom.* / logo-hover.* étaient écrits dans
-- /public/assets/img/, écrasés à chaque rebuild d'image Docker.
-- Désormais ils vivent dans /public/assets/img/branding/ (volume persistant).
-- Cette migration réinitialise les settings pointant vers l'ancien chemin
-- pour éviter les 404 (l'utilisateur uploade à nouveau, ça reste).

UPDATE settings
   SET `value` = '/assets/img/logo.png'
 WHERE `key` = 'logo_url'
   AND `value` LIKE '/assets/img/logo-custom%';

UPDATE settings
   SET `value` = ''
 WHERE `key` = 'logo_hover_url'
   AND `value` LIKE '/assets/img/logo-hover%';
