-- Tâches programmées : bases de connaissances et outils choisis POUR la tâche,
-- en plus de ceux de son assistant. Listes d'identifiants séparés par des
-- virgules, comme `sources` : elles sont RE-VÉRIFIÉES à chaque exécution
-- (partage retiré, base désactivée, outil arrêté ou retiré du groupe) — ce
-- qui n'est plus permis sort du tour, la tâche ne casse pas.
--
-- Pas de table de liaison : une tâche porte au plus trois bases et un outil
-- par type, lus en entier à chaque exécution ; une clé étrangère ferait
-- échouer la suppression d'une base ou d'un outil encore cité par une tâche.
ALTER TABLE scheduled_tasks
    ADD COLUMN kb_ids   VARCHAR(255) NOT NULL DEFAULT '' AFTER sources,
    ADD COLUMN tool_ids VARCHAR(255) NOT NULL DEFAULT '' AFTER kb_ids;
