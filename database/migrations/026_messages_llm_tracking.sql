-- Trace LLM ayant généré chaque message assistant.
-- Permet d'afficher provider + modèle sous chaque bulle de réponse.
-- NULL pour messages historiques (avant migration) et messages utilisateur.

ALTER TABLE messages
  ADD COLUMN llm_provider VARCHAR(40)  NULL AFTER role,
  ADD COLUMN llm_model    VARCHAR(120) NULL AFTER llm_provider;
