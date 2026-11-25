-- Historique des versions d'un workflow (retour du 2026-11-04 : « Publié ·
-- version 6 », mais aucun moyen de revoir les cinq précédentes).
--
-- Une ligne par version PUBLIÉE : la définition telle qu'elle a été publiée,
-- son nom, sa date. Workflow::publish l'écrit dans la même requête que la
-- version publiée. Une version peut servir de point de départ d'un nouveau
-- brouillon (« Repartir de cette version ») — elle n'est jamais modifiée.
--
-- Reprise de l'existant : la version publiée actuelle de chaque workflow, et
-- chaque version passée qu'une exécution a FIGÉE (workflow_runs garde la
-- définition lancée) — `recovered` = 1, datée de son premier lancement.
-- Une version publiée puis jamais lancée avant cette migration est perdue.
CREATE TABLE IF NOT EXISTS workflow_versions (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    workflow_id      INT UNSIGNED NOT NULL,
    version          INT UNSIGNED NOT NULL,
    name             VARCHAR(120) NOT NULL DEFAULT '',
    definition_json  MEDIUMTEXT   NOT NULL,
    published_at     DATETIME     NULL DEFAULT NULL,
    published_by     INT UNSIGNED NULL DEFAULT NULL,
    recovered        TINYINT(1)   NOT NULL DEFAULT 0,
    UNIQUE KEY uq_wfv_version (workflow_id, version),
    CONSTRAINT fk_wfv_workflow FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO workflow_versions (workflow_id, version, name, definition_json, published_at, published_by, recovered)
SELECT w.id, w.published_version, w.name, w.published_json, w.published_at, w.user_id, 0
FROM workflows w
WHERE w.published_json IS NOT NULL AND w.published_version > 0;

INSERT IGNORE INTO workflow_versions (workflow_id, version, name, definition_json, published_at, published_by, recovered)
SELECT r.workflow_id, r.version, '',
       (SELECT r2.definition_json FROM workflow_runs r2 WHERE r2.workflow_id = r.workflow_id AND r2.version = r.version ORDER BY r2.id LIMIT 1),
       MIN(r.started_at), NULL, 1
FROM workflow_runs r
JOIN workflows w ON w.id = r.workflow_id
WHERE r.version > 0
GROUP BY r.workflow_id, r.version;
