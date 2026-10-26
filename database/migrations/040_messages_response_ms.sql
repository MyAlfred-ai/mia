-- Temps de génération de la réponse assistant (millisecondes).
-- NULL pour les messages historiques + tous les messages non-assistant.
ALTER TABLE messages
  ADD COLUMN response_ms INT UNSIGNED NULL AFTER llm_model;
