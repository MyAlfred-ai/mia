-- Télémétrie de l'agent (src/Agent/AgentTelemetry.php) : une ligne par
-- réponse passée par la boucle d'outils — allers-retours avec le modèle,
-- appels d'outils, erreurs, répétitions bloquées, cartes proposées, jetons et
-- cache, durée, et par outil (tools_json) appels / erreurs / durées.
--
-- AUCUN contenu ni identifiant de personne ou de conversation : des nombres,
-- des noms d'outils et le modèle. L'écran « Agent » (Configuration →
-- Diagnostic) n'en montre que des agrégats. Lignes de plus de 180 jours
-- effacées au fil de l'eau par AgentTelemetry::record.
CREATE TABLE IF NOT EXISTS agent_turn_stats (
    id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    provider           VARCHAR(40)       NOT NULL DEFAULT '',
    model              VARCHAR(120)      NOT NULL DEFAULT '',
    rounds             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tool_calls         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tool_errors        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    duplicates_blocked SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    unknown_tools      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    cards              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    condensed          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    input_tokens       INT UNSIGNED      NOT NULL DEFAULT 0,
    cached_tokens      INT UNSIGNED      NOT NULL DEFAULT 0,
    cache_write_tokens INT UNSIGNED      NOT NULL DEFAULT 0,
    output_tokens      INT UNSIGNED      NOT NULL DEFAULT 0,
    duration_ms        INT UNSIGNED      NOT NULL DEFAULT 0,
    rounds_exhausted   TINYINT(1)        NOT NULL DEFAULT 0,
    failover           TINYINT(1)        NOT NULL DEFAULT 0,
    failed             TINYINT(1)        NOT NULL DEFAULT 0,
    follow_up          VARCHAR(10)       NOT NULL DEFAULT '',
    tools_json         TEXT              NULL,
    KEY idx_ats_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
