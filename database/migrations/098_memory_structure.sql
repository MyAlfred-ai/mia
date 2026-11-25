-- Mémoire de MIA, gérée par MIA (lot 1) : un souvenir porte désormais un THÈME,
-- un STATUT (actif, à confirmer, refusé), un VERROU (MIA ne modifie ni ne
-- supprime jamais d'elle-même ce que la personne a écrit, demandé ou confirmé),
-- la conversation où il a été appris, un compteur d'occurrences et une
-- empreinte (souvenir refusé ou retiré, gardé sans son texte pour ne pas être
-- réappris).
--
-- Reprise des souvenirs existants par les seuls DÉFAUTS de colonne — aucun
-- UPDATE : `updated_at` (ON UPDATE CURRENT_TIMESTAMP) prendrait sinon la date
-- du déploiement. Résultat : thème « autre », verrouillés (ils ont tous été
-- écrits ou demandés par la personne), actifs.
ALTER TABLE user_memories
    ADD COLUMN theme VARCHAR(16) NOT NULL DEFAULT 'autre' AFTER content,
    ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active' AFTER source,
    ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN conversation_id INT UNSIGNED NULL DEFAULT NULL AFTER is_locked,
    ADD COLUMN hits TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER conversation_id,
    ADD COLUMN fingerprint CHAR(40) NULL DEFAULT NULL AFTER hits,
    ADD COLUMN seen_at DATETIME NULL DEFAULT NULL AFTER fingerprint,
    ADD KEY idx_um_conv (conversation_id),
    ADD CONSTRAINT fk_um_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL;

-- Dernière visite de la page Mémoire : les souvenirs appris depuis portent la
-- mention « Nouveau ».
ALTER TABLE users
    ADD COLUMN memory_seen_at DATETIME NULL DEFAULT NULL;
