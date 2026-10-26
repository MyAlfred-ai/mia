-- Quand un nouveau modèle phare arrive (ici Claude Opus 4.8) il est ajouté
-- dans `llm_models` (migration 036) et activé côté admin, mais les groupes
-- utilisateurs existants qui exposent une whitelist (`user_group_llms`)
-- continuent d'afficher uniquement les modèles qu'ils listent — donc le
-- picker LLM du chat n'affiche pas 4.8 même si l'admin l'a "actif".
--
-- Politique retenue : si un groupe autorise déjà la génération Opus
-- précédente (4.7), il hérite automatiquement de 4.8 (upgrade logique
-- du flagship Anthropic). Les groupes qui n'avaient aucun Opus restent
-- inchangés — l'admin doit explicitement ouvrir l'accès le cas échéant.
--
-- Idempotent grâce à INSERT IGNORE sur la PRIMARY KEY (group_id, llm_key).
INSERT IGNORE INTO user_group_llms (group_id, llm_key)
SELECT group_id, 'anthropic::claude-opus-4-8'
FROM user_group_llms
WHERE llm_key = 'anthropic::claude-opus-4-7';
