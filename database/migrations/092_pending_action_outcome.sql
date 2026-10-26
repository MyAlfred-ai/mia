-- Issue d'une action exécutée, pour la relire après coup (historique d'un
-- workflow, page de l'exécution). Le statut `executed` ne disait pas si un
-- email préparé était PARTI ou simplement rangé en brouillon — la seule
-- question qui compte quand on enquête sur ce qu'un workflow a fait.
--
-- `outcome` : `sent` (email envoyé) | `draft` (brouillon créé) ; NULL pour
-- toute autre action, et pour les actions exécutées avant cette colonne.
-- `result_url` : le lien vers ce que l'action a produit (l'email dans les
-- Envoyés ou les Brouillons, le document créé, l'évènement, le message
-- publié) ; NULL quand le service n'en rend pas.
ALTER TABLE drive_pending_actions
    ADD COLUMN outcome VARCHAR(20) NULL DEFAULT NULL AFTER status,
    ADD COLUMN result_url VARCHAR(1000) NULL DEFAULT NULL AFTER outcome;
