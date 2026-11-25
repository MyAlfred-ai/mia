-- Origine des opérations du grand livre (Réglages → Usage & crédits → Historique).
--
-- L'historique range chaque opération par ORIGINE : une conversation, un
-- workflow, une tâche programmée, Claude dans Office, l'alimentation d'une
-- base… Ce qui se déduit de la source (Office, bases) se calcule à la lecture
-- (UsageHistory). Le workflow et la tâche, eux, ne se lisent que sur la
-- conversation (`workflow_run_id`, `scheduled_task_id`) — et ce lien disparaît
-- avec elle : élagage, conversation supprimée, workflow ou tâche supprimés.
-- Des mois plus tard, l'opération serait rangée parmi les conversations.
--
-- `origin` garde donc ce que la conversation disait AU MOMENT de l'écriture :
-- 'workflow', 'task', ou '' (tout le reste, déduit à la lecture). Posée par
-- UsageLog::insertLlm / insertTool ; reprise ici pour l'existant.
-- MySQL 8 ajoute une colonne en fin de table sans recopie (INSTANT).
ALTER TABLE usage_logs ADD COLUMN origin VARCHAR(16) NOT NULL DEFAULT '';
ALTER TABLE tool_usage_logs ADD COLUMN origin VARCHAR(16) NOT NULL DEFAULT '';

UPDATE usage_logs l
  JOIN conversations c ON c.id = l.conversation_id
   SET l.origin = IF(c.workflow_run_id IS NOT NULL, 'workflow', 'task')
 WHERE c.workflow_run_id IS NOT NULL OR c.scheduled_task_id IS NOT NULL;

UPDATE tool_usage_logs t
  JOIN conversations c ON c.id = t.conversation_id
   SET t.origin = IF(c.workflow_run_id IS NOT NULL, 'workflow', 'task')
 WHERE c.workflow_run_id IS NOT NULL OR c.scheduled_task_id IS NOT NULL;
