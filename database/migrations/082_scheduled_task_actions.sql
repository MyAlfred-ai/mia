-- Tâches programmées, lot 2 : une tâche peut PRÉPARER des actions (brouillon
-- d'email, évènement, tâche, message Slack…) qui attendent l'approbation de la
-- personne dans la conversation de la tâche. Décochée par défaut : une tâche
-- reste en lecture seule tant que sa propriétaire ne l'a pas voulu autrement.
ALTER TABLE scheduled_tasks
    ADD COLUMN allow_actions TINYINT(1) NOT NULL DEFAULT 0 AFTER destination;

-- Durée de vie PROPRE à une carte d'approbation. NULL = la règle de toujours
-- (30 minutes après sa création, DrivePendingAction::TTL_MINUTES) : une carte
-- proposée pendant qu'on lit la réponse se tranche dans la foulée. Une carte
-- déposée par une tâche programmée attend une semaine — la personne n'était
-- pas là quand elle est née. Posée en heure MySQL (NOW() + INTERVAL), comparée
-- à NOW() : jamais d'écart entre l'horloge de PHP et celle de la base.
ALTER TABLE drive_pending_actions
    ADD COLUMN expires_at DATETIME NULL DEFAULT NULL AFTER status;
