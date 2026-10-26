-- État « agent » d'une réponse : le plan de travail que le modèle a affiché
-- (outil plan_de_travail) et, le cas échéant, la raison pour laquelle la
-- réponse peut être poursuivie (plan inachevé, budget d'allers-retours
-- épuisé). Rejoué au rechargement (carte du plan, bouton « Continuer ») et
-- relu au tour suivant. Petit JSON, NULL pour toutes les réponses sans agent.
ALTER TABLE messages
    ADD COLUMN agent_json TEXT NULL DEFAULT NULL;
