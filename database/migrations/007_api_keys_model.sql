-- Modèle LLM configuré par fournisseur (ex: gpt-4.5-preview, claude-opus-4-6...)
ALTER TABLE api_keys ADD COLUMN model VARCHAR(100) DEFAULT NULL;
