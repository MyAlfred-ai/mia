-- Tâches programmées : « Agir sans validation ». Une tâche peut EXÉCUTER
-- elle-même les actions qu'elle prépare (évènement, tâche, message Slack,
-- fichier, workflow, email…) au lieu de les laisser en cartes d'approbation —
-- sur le choix explicite de sa propriétaire, dans le formulaire de la tâche,
-- et tant que l'administrateur le permet (réglage scheduled_tasks_auto_enabled).
--
-- `auto_actions` n'a de sens qu'avec `allow_actions` = 1 (le code tient
-- l'invariant). `auto_send_mail` : un email préparé part réellement ; sinon il
-- est enregistré en brouillon — le défaut, l'envoi étant le seul geste
-- qu'aucune corbeille ne rattrape.
ALTER TABLE scheduled_tasks
    ADD COLUMN auto_actions TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_actions,
    ADD COLUMN auto_send_mail TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_actions;

-- L'action a été exécutée par une tâche, sans carte. Elle passe par la file
-- comme les autres (même chemin d'exécution, mêmes vérifications, même trace)
-- mais sort des statistiques des cartes : personne n'a décidé, un taux
-- d'approbation ou un délai de décision n'aurait pas de sens.
ALTER TABLE drive_pending_actions
    ADD COLUMN auto_executed TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
