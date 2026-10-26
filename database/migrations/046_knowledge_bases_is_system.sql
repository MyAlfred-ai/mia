-- Base de connaissances "système" : semée et resynchronisée automatiquement au
-- démarrage depuis les guides de docs/ (assistant « MIA : mode d'emploi »).
-- Verrouillée en administration : ni suppression, ni ajout/retrait de documents,
-- ni déliaison de son assistant — le contenu ne vient que des guides versionnés.
-- La réindexation reste autorisée (changement de modèle d'embedding).
ALTER TABLE knowledge_bases
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
