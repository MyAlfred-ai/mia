-- Générations asynchrones : la production (page HTML, deck, vidéo…) ne vit plus
-- dans la requête HTTP du navigateur. Le contrôleur valide puis ENFILE un job ;
-- un worker l'exécute et écrit son flux dans `job_events`, que le client
-- (ré)attache par SSE — changer de conversation, changer de page ou recharger
-- n'interrompt plus rien.
CREATE TABLE async_jobs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    conversation_id  INT UNSIGNED NULL DEFAULT NULL,  -- NULL = job hors chat (refresh prix, réindex…)
    type             VARCHAR(40) NOT NULL,            -- html | (à venir) presentation, video, image, chat_send…
    label            VARCHAR(120) NOT NULL DEFAULT '',-- libellé humain (tiroir « tâches en cours »)
    payload_json     MEDIUMTEXT NOT NULL,             -- contexte COMPLET : le worker n'a pas de session PHP
    status           VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|running|done|failed|cancelled
    phase            VARCHAR(40) NULL DEFAULT NULL,
    progress         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    result_json      MEDIUMTEXT NULL DEFAULT NULL,
    error_json       TEXT NULL DEFAULT NULL,          -- charge utile LlmErrorPresenter::present()
    cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_seq         INT UNSIGNED NOT NULL DEFAULT 0, -- dernier `seq` écrit dans job_events
    heartbeat_at     DATETIME NULL DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at       DATETIME NULL DEFAULT NULL,
    finished_at      DATETIME NULL DEFAULT NULL,
    KEY idx_jobs_claim (status, id),
    KEY idx_jobs_user_live (user_id, status),
    KEY idx_jobs_conv (conversation_id, status),
    KEY idx_jobs_gc (finished_at),
    CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_jobs_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Flux rejouable : un event = exactement l'objet SSE que le front lit déjà
-- ({chunk}, {image_progress}, {done…}). Le curseur `seq` sert de Last-Event-ID,
-- donc une reconnexion reprend là où elle s'était arrêtée.
CREATE TABLE job_events (
    job_id  BIGINT UNSIGNED NOT NULL,
    seq     INT UNSIGNED NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    PRIMARY KEY (job_id, seq),
    CONSTRAINT fk_job_events_job FOREIGN KEY (job_id) REFERENCES async_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
