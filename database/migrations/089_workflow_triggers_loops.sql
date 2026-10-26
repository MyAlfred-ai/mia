-- Workflows, second lot : déclencheurs, boucles, attentes, reprise sur erreur
-- et livraison du livrable (cf. section *Workflows* de CLAUDE.md).
--
-- Déclencheurs par ÉVÈNEMENT (WorkflowTriggers) :
--  - `watch_next_at` (UTC) : prochaine vérification d'une boîte mail ou d'un
--    dossier Drive surveillé par la version publiée, NULL sinon ;
--    `watch_state_json` : ce qui a déjà été vu (identifiants), la dernière
--    vérification et sa panne éventuelle — un email n'en lance qu'une fois ;
--  - `hook_token_hash` : empreinte SHA-256 du jeton de l'adresse d'appel
--    (webhook) — c'est par elle qu'une requête retrouve son workflow, jamais
--    par le jeton lui-même ; `hook_token_enc` : le jeton chiffré, pour que la
--    personne puisse relire son adresse.
ALTER TABLE workflows
    ADD COLUMN watch_next_at    DATETIME NULL DEFAULT NULL AFTER schedule_next_at,
    ADD COLUMN watch_state_json TEXT     NULL DEFAULT NULL AFTER watch_next_at,
    ADD COLUMN hook_token_hash  CHAR(64) NULL DEFAULT NULL AFTER watch_state_json,
    ADD COLUMN hook_token_enc   TEXT     NULL DEFAULT NULL AFTER hook_token_hash,
    ADD UNIQUE KEY uq_wf_hook (hook_token_hash),
    ADD KEY idx_wf_watch (is_active, watch_next_at);

-- `trigger_ref` : ce qui a lancé l'exécution (« gmail:<id> », « drive:<id> »,
-- « run:<id> », clé d'idempotence d'un appel) — UNIQUE(workflow_id,
-- trigger_ref) : un même évènement ne lance jamais deux exécutions, même avec
-- plusieurs workers (MySQL admet plusieurs NULL dans un index unique).
-- `parent_run_id` : exécution qui a lancé celle-ci (« quand un autre workflow
-- se termine ») — sert à borner une chaîne, sans clé étrangère.
ALTER TABLE workflow_runs
    ADD COLUMN trigger_ref   VARCHAR(190) NULL DEFAULT NULL AFTER trigger_kind,
    ADD COLUMN parent_run_id INT UNSIGNED NULL DEFAULT NULL AFTER trigger_ref,
    ADD UNIQUE KEY uq_wfr_ref (workflow_id, trigger_ref);

-- `resume_at` (UTC) : une brique « Attendre », ou un nouvel essai automatique
-- après une panne passagère, reprend à cette heure — lue par le planificateur.
ALTER TABLE workflow_run_steps
    ADD COLUMN resume_at DATETIME NULL DEFAULT NULL AFTER pending_action_id,
    ADD KEY idx_wfs_timer (status, resume_at);
