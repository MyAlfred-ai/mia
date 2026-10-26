-- Langue d'interface par utilisateur.
--
-- Même patron que ai_style (022) : User fait des SELECT *, la colonne remonte
-- donc seule dans $user et dans la session, sans une requête de plus — côté web
-- comme côté worker (JobQueue::execute charge déjà l'utilisateur du job).
--
-- Défaut '' et NON 'fr' : distinguer « n'a jamais choisi » de « a choisi le
-- français » est ce qui permet de continuer à honorer Accept-Language, et de
-- changer le défaut d'instance plus tard sans écraser un choix explicite.
ALTER TABLE users
    ADD COLUMN lang CHAR(2) NOT NULL DEFAULT '';
