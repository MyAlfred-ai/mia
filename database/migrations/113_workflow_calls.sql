-- Workflows : un workflow en APPELLE un autre (brique « Appeler un workflow »,
-- src/Workflow/WorkflowCall.php, cf. section *Workflows* de CLAUDE.md).
--
-- L'exécution APPELÉE garde le passage de l'exécution appelante qui l'attend :
-- `caller_step_id` = workflow_run_steps.id de la brique d'appel (sans clé
-- étrangère, comme `parent_run_id` : supprimer l'appelante laisse l'appelée
-- dans l'historique). `parent_run_id` (migration 089) porte déjà l'exécution
-- appelante ; `trigger_kind` vaut « call », `trigger_ref` « call:<passage> »
-- (UNIQUE(workflow_id, trigger_ref) : un passage ne lance qu'UN appel).
--
-- NULL pour toute autre exécution — y compris celles lancées « quand un autre
-- workflow se termine », qui ont un parent mais que personne n'attend.
ALTER TABLE workflow_runs
    ADD COLUMN caller_step_id INT UNSIGNED NULL DEFAULT NULL AFTER parent_run_id,
    ADD KEY idx_wfr_caller (caller_step_id),
    ADD KEY idx_wfr_parent (parent_run_id);
