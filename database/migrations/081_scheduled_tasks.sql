-- Tâches programmées (src/Schedule/, Paramètres → Tâches programmées) : une
-- consigne confiée à un assistant, rejouée à date fixe (chaque lundi à 8 h…).
-- Chaque exécution est un message ORDINAIRE de la conversation de la tâche,
-- envoyé par le planificateur du worker au lieu de l'être par le navigateur.
--
-- La règle de récurrence est un petit JSON structuré (Recurrence::normalize),
-- jamais une expression cron : il reflète un pour un ce que l'écran propose
-- (préréglage, jours, jour du mois, heure). `next_run_at` est en UTC, calculé
-- dans le fuseau de la personne (`timezone`, celui de son navigateur) : le
-- changement d'heure ne décale rien.
--
-- `profile_id` n'a PAS de clé étrangère, à dessein : un assistant supprimé ne
-- doit ni effacer la tâche (CASCADE) ni la faire tourner en silence sans lui
-- (SET NULL). Le planificateur constate son absence et met la tâche en pause,
-- avec la raison. `llm` ne sert qu'aux tâches SANS assistant ('' = modèle par
-- défaut de l'instance) — un assistant impose toujours le sien, comme au chat.
CREATE TABLE IF NOT EXISTS scheduled_tasks (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED     NOT NULL,
    profile_id    INT UNSIGNED     NULL DEFAULT NULL,
    llm           VARCHAR(120)     NOT NULL DEFAULT '',
    name          VARCHAR(120)     NOT NULL,
    instruction   TEXT             NOT NULL,
    rule_json     VARCHAR(255)     NOT NULL,
    timezone      VARCHAR(64)      NOT NULL DEFAULT '',
    sources       VARCHAR(255)     NOT NULL DEFAULT '',
    destination   ENUM('thread', 'new') NOT NULL DEFAULT 'thread',
    status        ENUM('active', 'paused') NOT NULL DEFAULT 'active',
    pause_reason  VARCHAR(32)      NOT NULL DEFAULT '',
    failures      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at   DATETIME         NULL DEFAULT NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_st_due (status, next_run_at),
    KEY idx_st_user (user_id),
    CONSTRAINT fk_st_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne par créneau traité, exécuté ou non (manqué, sauté, refusé) :
-- c'est l'historique que la page affiche, et surtout la GARANTIE qu'un
-- créneau ne part jamais deux fois — UNIQUE(task_id, slot_at), même avec
-- plusieurs workers et le déclencheur de secours qui tournent ensemble.
-- `job_id` sans clé étrangère : async_jobs est purgée à 30 jours, l'historique
-- doit lui survivre. Statut final recopié à la fin du job
-- (ScheduledTaskRun::onJobFinished, appelé par JobQueue).
CREATE TABLE IF NOT EXISTS scheduled_task_runs (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_id          INT UNSIGNED NOT NULL,
    slot_at          DATETIME     NOT NULL,
    is_manual        TINYINT(1)   NOT NULL DEFAULT 0,
    status           ENUM('queued', 'done', 'failed', 'cancelled', 'skipped', 'missed') NOT NULL,
    reason           VARCHAR(32)  NOT NULL DEFAULT '',
    job_id           INT UNSIGNED NULL DEFAULT NULL,
    conversation_id  INT UNSIGNED NULL DEFAULT NULL,
    message_id       INT UNSIGNED NULL DEFAULT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at      DATETIME     NULL DEFAULT NULL,
    UNIQUE KEY uq_str_slot (task_id, slot_at),
    KEY idx_str_job (job_id),
    KEY idx_str_created (created_at),
    CONSTRAINT fk_str_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_str_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_str_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversation née d'une tâche : icône dans la barre latérale, bandeau en
-- tête du fil, et reprise du même fil d'une exécution à l'autre. Supprimer la
-- tâche garde les conversations (SET NULL) : c'est un historique.
-- `unread_at` : un résultat arrivé en l'absence de la personne — pastille dans
-- la barre latérale jusqu'à l'ouverture de la conversation.
ALTER TABLE conversations
    ADD COLUMN scheduled_task_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN unread_at DATETIME NULL DEFAULT NULL,
    ADD KEY idx_conv_sched (scheduled_task_id),
    ADD CONSTRAINT fk_conv_sched FOREIGN KEY (scheduled_task_id) REFERENCES scheduled_tasks(id) ON DELETE SET NULL;
