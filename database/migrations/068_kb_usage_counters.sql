-- Utilisation réelle d'une base de connaissances.
--
-- Le grand livre (usage_logs.kb_id) ne peut PAS servir de mesure d'usage :
-- l'embedding de la question est imputé à la PREMIÈRE base d'un groupe
-- provider|modèle (une seule vectorisation sert toutes les bases du groupe),
-- donc trier là-dessus afficherait « 0 interrogation » sur des bases qui
-- répondent à chaque message. D'où deux compteurs propres, incrémentés
-- seulement quand la base a réellement injecté des extraits dans un prompt :
-- une base liée à un assistant mais dont rien ne ressort jamais se repère.
ALTER TABLE knowledge_bases
    ADD COLUMN query_count  INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN last_used_at DATETIME NULL;
