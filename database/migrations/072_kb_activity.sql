-- Activité de fond EN COURS d'une base de connaissances : synchronisation
-- d'un dossier distant ou réindexation. Le passage l'écrit lui-même à chaque
-- étape (lecture du dossier, fichier N sur M, retrait des fichiers disparus)
-- et la remet à NULL en finissant : l'écran des bases la lit pour afficher
-- une progression vivante (« 12 fichiers sur 40 — « Contrat.pdf » »).
--
-- Séparée de `sync_report` à dessein : le rapport décrit le DERNIER passage
-- terminé et doit survivre pendant le suivant ; la progression ne vaut que le
-- temps du passage. Une valeur restée en place après un arrêt brutal n'est
-- jamais prise pour un passage vivant : KbStatus exige aussi le verrou MySQL
-- du passage (sync) ou un job en cours (réindexation).
ALTER TABLE knowledge_bases
    ADD COLUMN activity_json TEXT NULL DEFAULT NULL AFTER sync_report;
