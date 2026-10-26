-- Jobs SANS utilisateur : la file accueille désormais aussi les tâches
-- système (ingestion de documents, réindexation, synchronisation de dossier
-- distant) déclenchées par l'application elle-même — pas par une action d'un
-- utilisateur identifié. `user_id` devient donc facultatif.
ALTER TABLE async_jobs DROP FOREIGN KEY fk_jobs_user;
ALTER TABLE async_jobs MODIFY user_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE async_jobs ADD CONSTRAINT fk_jobs_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Déduplication des tâches système : une base déjà en file d'attente de
-- synchronisation ne doit pas y entrer une seconde fois.
ALTER TABLE async_jobs ADD COLUMN dedup_key VARCHAR(80) NULL DEFAULT NULL AFTER type;
ALTER TABLE async_jobs ADD KEY idx_jobs_dedup (dedup_key, status);
