-- Workflows (src/Workflow/, menu « Workflows » du volet de gauche) : un
-- processus en plusieurs étapes confiées à des assistants. Un DOSSIER de
-- pièces nommées circule d'une étape à l'autre ; des conditions décident de la
-- suite ; la personne relit là où une erreur coûte.
--
-- La définition est un JSON (WorkflowSpec::normalize, SOURCE UNIQUE de sa
-- forme). Deux exemplaires : le BROUILLON, enregistré au fil de l'édition, et
-- la version PUBLIÉE, la seule qui s'exécute. Chaque exécution recopie la
-- version publiée (`workflow_runs.definition_json`) : modifier ou republier un
-- workflow ne change jamais un dossier déjà en cours.
--
-- `schedule_next_at` (UTC) : prochaine exécution du départ programmé de la
-- version publiée, NULL sinon — lue par le planificateur du worker.
CREATE TABLE IF NOT EXISTS workflows (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    name               VARCHAR(120) NOT NULL,
    color              VARCHAR(7)   NOT NULL DEFAULT '',
    draft_json         MEDIUMTEXT   NOT NULL,
    published_json     MEDIUMTEXT   NULL DEFAULT NULL,
    published_version  INT UNSIGNED NOT NULL DEFAULT 0,
    published_at       DATETIME     NULL DEFAULT NULL,
    draft_changed      TINYINT(1)   NOT NULL DEFAULT 1,
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    schedule_next_at   DATETIME     NULL DEFAULT NULL,
    template_key       VARCHAR(40)  NULL DEFAULT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_wf_user (user_id),
    KEY idx_wf_due (is_active, schedule_next_at),
    CONSTRAINT fk_wf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une exécution. `slot_at` : créneau d'un départ programmé (NULL pour un
-- lancement à la main ou depuis le chat) — UNIQUE(workflow_id, slot_at) : un
-- créneau ne part qu'une fois, même avec plusieurs workers (MySQL admet
-- plusieurs NULL dans un index unique).
-- `status` : running | waiting (une personne doit décider) | paused (quota ou
-- budget atteint, reprenable) | done | stopped (voie « si non » qui s'arrête)
-- | failed (reprenable à l'étape) | cancelled.
CREATE TABLE IF NOT EXISTS workflow_runs (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    workflow_id      INT UNSIGNED NOT NULL,
    version          INT UNSIGNED NOT NULL DEFAULT 0,
    user_id          INT UNSIGNED NOT NULL,
    conversation_id  INT UNSIGNED NULL DEFAULT NULL,
    trigger_kind     VARCHAR(16)  NOT NULL DEFAULT 'manual',
    slot_at          DATETIME     NULL DEFAULT NULL,
    status           VARCHAR(16)  NOT NULL DEFAULT 'running',
    current_key      VARCHAR(40)  NOT NULL DEFAULT '',
    definition_json  MEDIUMTEXT   NOT NULL,
    inputs_json      MEDIUMTEXT   NULL DEFAULT NULL,
    error_json       TEXT         NULL DEFAULT NULL,
    timezone         VARCHAR(64)  NOT NULL DEFAULT '',
    started_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finished_at      DATETIME     NULL DEFAULT NULL,
    UNIQUE KEY uq_wfr_slot (workflow_id, slot_at),
    KEY idx_wfr_user (user_id, status),
    KEY idx_wfr_conv (conversation_id),
    CONSTRAINT fk_wfr_wf FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    CONSTRAINT fk_wfr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wfr_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Le passage d'une exécution par une brique : une ligne par TENTATIVE
-- (renvoi d'une validation, reprise après un échec). `outcome` : yes | no
-- pour une condition, approved | returned | stopped pour une validation.
-- `job_id` sans clé étrangère : async_jobs est purgée, l'historique reste.
CREATE TABLE IF NOT EXISTS workflow_run_steps (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id             INT UNSIGNED NOT NULL,
    node_key           VARCHAR(40)  NOT NULL,
    attempt            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status             VARCHAR(16)  NOT NULL DEFAULT 'running',
    outcome            VARCHAR(16)  NOT NULL DEFAULT '',
    job_id             BIGINT UNSIGNED NULL DEFAULT NULL,
    message_id         INT UNSIGNED NULL DEFAULT NULL,
    pending_action_id  INT UNSIGNED NULL DEFAULT NULL,
    detail_json        TEXT         NULL DEFAULT NULL,
    started_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at        DATETIME     NULL DEFAULT NULL,
    UNIQUE KEY uq_wfs_attempt (run_id, node_key, attempt),
    KEY idx_wfs_job (job_id),
    KEY idx_wfs_action (pending_action_id),
    CONSTRAINT fk_wfs_run FOREIGN KEY (run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_wfs_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les pièces du dossier d'une exécution : une ligne par pièce, réécrite
-- quand une étape est refaite ou qu'une personne la corrige (`version`).
-- `provenance_json` : contenus de tiers lus pour la produire (emails, pages,
-- fichiers) — une pièce qui en dérive reste une DONNÉE pour les étapes suivantes.
CREATE TABLE IF NOT EXISTS workflow_artifacts (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id           INT UNSIGNED NOT NULL,
    art_key          VARCHAR(40)  NOT NULL,
    node_key         VARCHAR(40)  NOT NULL,
    name             VARCHAR(120) NOT NULL,
    form             VARCHAR(12)  NOT NULL,
    content_json     MEDIUMTEXT   NOT NULL,
    provenance_json  TEXT         NULL DEFAULT NULL,
    version          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wfa_key (run_id, art_key),
    CONSTRAINT fk_wfa_run FOREIGN KEY (run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversation d'une exécution : chaque étape y laisse son message ordinaire
-- (trace, coût, relecture). Supprimer l'exécution garde la conversation.
ALTER TABLE conversations
    ADD COLUMN workflow_run_id INT UNSIGNED NULL DEFAULT NULL,
    ADD KEY idx_conv_wfrun (workflow_run_id),
    ADD CONSTRAINT fk_conv_wfrun FOREIGN KEY (workflow_run_id) REFERENCES workflow_runs(id) ON DELETE SET NULL;
