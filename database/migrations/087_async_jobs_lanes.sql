-- Plusieurs workers en parallèle : la file n'est plus traitée par un seul
-- process, donc l'ordre FIFO global ne protège plus rien. Deux notions
-- remplacent ce que le worker unique garantissait sans le dire :
--
--   priority : ordre de réclamation (petit = d'abord). Une personne qui attend
--              une réponse passe avant une tâche programmée, qui passe avant la
--              maintenance (ingestion, synchronisation, réindexation).
--   lane     : couloir sériel. Deux jobs du même couloir ne tournent jamais en
--              même temps, et dans l'ordre d'arrivée — une conversation
--              (`conv:<id>`, chat / image / page web : chaque tour lit le
--              précédent) ou la maintenance (`maintenance` : une seule à la
--              fois, il reste toujours des workers pour les conversations).
--              NULL = aucun couloir, le job part dès qu'un worker est libre.
--
-- `priority` et `lane` ne sont pas des mots réservés de MySQL 8.4.
ALTER TABLE async_jobs
    ADD COLUMN priority TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER status,
    ADD COLUMN lane VARCHAR(40) NULL DEFAULT NULL AFTER priority,
    ADD KEY idx_jobs_claim_priority (status, priority, id),
    ADD KEY idx_jobs_lane (lane, status, id);

-- Jobs en file ou en cours au moment de la migration : mêmes règles que
-- JobQueue::priorityFor() / laneFor(), sinon un job en cours ne bloquerait
-- pas son couloir et le suivant partirait à côté de lui.
UPDATE async_jobs SET priority = 90, lane = 'maintenance'
 WHERE type = 'maintenance' AND status IN ('pending', 'running');
UPDATE async_jobs SET priority = 10, lane = CONCAT('conv:', conversation_id)
 WHERE type IN ('chat', 'image', 'html') AND conversation_id IS NOT NULL
   AND status IN ('pending', 'running');
UPDATE async_jobs SET priority = 10
 WHERE type NOT IN ('chat', 'image', 'html', 'maintenance')
   AND status IN ('pending', 'running');
