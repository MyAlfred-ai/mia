-- Trace des actions effectuées par MIA avant de répondre (recherches dans les
-- bases de connaissances, sur Internet, lectures/écritures des connecteurs…).
-- JSON : liste d'entrées {t: type, l: libellé, d: détail facultatif, ok: bool},
-- normalisée par Message::normalizeActions(). NULL = message sans trace
-- (messages user, historique antérieur à la migration).
ALTER TABLE messages ADD COLUMN actions_json TEXT NULL DEFAULT NULL;
