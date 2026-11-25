-- Modèle préféré de chaque personne : il ouvre ses nouvelles conversations
-- sans assistant (étoile du sélecteur de modèle, Réglages → Préférences IA).
--
-- Même patron que ai_style (022), lang (071) et voice_* (075) : User fait des
-- SELECT *, la colonne remonte dans $user et dans la session sans une requête
-- de plus.
--
-- '' = « celui de votre organisation » (LLMFactory::appDefault), et NON une
-- recopie du défaut du jour : une personne qui n'a rien choisi suit les
-- changements de l'administrateur. Un modèle retiré de ses groupes ou arrêté
-- par son éditeur n'est pas effacé : il est ignoré à la lecture
-- (LlmPreference::resolve) et revient s'il est rendu.
ALTER TABLE users
    ADD COLUMN preferred_llm VARCHAR(100) NOT NULL DEFAULT '';
