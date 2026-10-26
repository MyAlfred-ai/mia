-- Détail granulaire des tokens (cached, cache write, reasoning) + coût € persisté
-- Granularité par modèle (colonne `model`) en plus de `llm_provider`.
-- `source` distingue chat / regenerate / html_tool pour ne pas muter `profile_id`.

ALTER TABLE usage_logs
  ADD COLUMN model VARCHAR(120) NULL AFTER llm_provider,
  ADD COLUMN tokens_input_cached      INT NOT NULL DEFAULT 0 AFTER tokens_input,
  ADD COLUMN tokens_input_cache_write INT NOT NULL DEFAULT 0 AFTER tokens_input_cached,
  ADD COLUMN tokens_output_reasoning  INT NOT NULL DEFAULT 0 AFTER tokens_output,
  ADD COLUMN cost_eur DECIMAL(12,6) NOT NULL DEFAULT 0 AFTER credits_used,
  ADD COLUMN source   VARCHAR(20)   NOT NULL DEFAULT 'chat' AFTER cost_eur;

ALTER TABLE tool_usage_logs
  ADD COLUMN cost_eur  DECIMAL(12,6) NOT NULL DEFAULT 0 AFTER credits_used,
  ADD COLUMN meta_json JSON          NULL          AFTER quantity_units;

CREATE INDEX idx_usage_user_model ON usage_logs(user_id, model);
