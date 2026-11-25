-- Apprentissage automatique de la mémoire (lot 3) : MIA retient, d'elle-même,
-- ce qu'une personne dit d'elle et de ses préférences en conversation.
--
-- `conversation_memory_scans` : une ligne par conversation à relire. Posée à
-- la fin d'un tour dont le message porte un signal (« je suis… », « je
-- préfère… »), avec une échéance (`due_at`) ; le planificateur du worker
-- confie les conversations échues à un job d'apprentissage, qui ne relit que
-- ce qui suit `upto_message_id`. Aucune ligne n'est créée au déploiement :
-- l'historique d'avant n'est jamais rattrapé. CASCADE des deux côtés.
--
-- `users.memory_learn` : la personne accepte qu'on apprenne de ses échanges
-- (1 par défaut, décision du 2026-11-03). `users.memory_informed_at` : la
-- carte d'information lui a été montrée (ou elle a ouvert la page Mémoire) —
-- rien n'est appris avant.

CREATE TABLE conversation_memory_scans (
    conversation_id INT UNSIGNED NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    upto_message_id INT UNSIGNED NOT NULL DEFAULT 0,
    due_at DATETIME NULL DEFAULT NULL,
    scanned_at DATETIME NULL DEFAULT NULL,
    KEY idx_cms_due (due_at),
    KEY idx_cms_user (user_id, scanned_at),
    CONSTRAINT fk_cms_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users
    ADD COLUMN memory_learn TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN memory_informed_at DATETIME NULL DEFAULT NULL;
