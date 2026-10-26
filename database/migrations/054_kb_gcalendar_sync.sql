-- Troisième source de synchronisation distante : Google Agenda ('gcalendar').
-- Un agenda public est reflété sous forme de documents markdown mensuels
-- virtuels (un par mois d'évènements, fenêtre glissante) : sync_file_id =
-- 'YYYY-MM', sync_hash = sha256 du contenu généré. Les colonnes sync_* de la
-- migration 049 couvrent déjà le besoin — seule l'ENUM de provenance s'élargit.

ALTER TABLE knowledge_base_documents
    MODIFY COLUMN source ENUM('upload','drive','sharepoint','gcalendar') NOT NULL DEFAULT 'upload';
