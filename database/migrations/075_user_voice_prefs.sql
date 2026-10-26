-- Réglages de voix par utilisateur (conversation vocale).
--
-- Même patron que ai_style (022) et lang (071) : User fait des SELECT *, les
-- colonnes remontent donc dans $user et dans la session sans une requête de
-- plus — côté web comme côté worker (JobQueue::execute charge déjà
-- l'utilisateur du job, qui a besoin de la longueur pour sa consigne orale).
--
-- voice_rate : vitesse de LECTURE de la réponse, en pourcentage (100 = normal).
-- Appliquée par le navigateur (playbackRate) : aucune re-synthèse, donc aucun
-- coût, et le cache des annonces reste valable quelle que soit la vitesse.
--
-- voice_length : longueur voulue des réponses à l'oral. Défaut '' et NON
-- 'bref' : distinguer « n'a jamais choisi » de « a choisi bref » permet de
-- faire évoluer le défaut applicatif sans écraser un choix explicite (même
-- raisonnement que lang). Une réponse lue à voix haute est plus courte qu'une
-- réponse écrite par défaut — cf. VoicePreferences::DEFAULT_LENGTH.
ALTER TABLE users
    ADD COLUMN voice_rate SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    ADD COLUMN voice_length VARCHAR(16) NOT NULL DEFAULT '';
