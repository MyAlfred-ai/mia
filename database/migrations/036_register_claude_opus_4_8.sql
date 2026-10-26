-- Enregistre Claude Opus 4.8 comme instance LLM disponible dans le picker de
-- chat (table llm_models lue par LLMFactory::available()). Idempotent via la
-- clé unique (provider, model). Nécessite une clé API Anthropic configurée
-- pour être réellement utilisable.
INSERT INTO llm_models (provider, model, display_name, is_active, sort_order)
VALUES ('anthropic', 'claude-opus-4-8', 'Claude Opus 4.8', 1, 0)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), is_active = VALUES(is_active);
