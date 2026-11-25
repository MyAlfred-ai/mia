-- Questions de MIA (mémoire, lot 2) : de temps en temps, sous une réponse
-- terminée, une carte discrète demande à la personne ce qu'elle préfère (forme
-- des réponses, façon de travailler, outils) ou lui fait confirmer ce que MIA
-- a cru comprendre. La question est écrite par le SERVEUR, la réponse est
-- enregistrée sans appel au modèle.
--
-- Une ligne = une question posée. `conversation_id` UNIQUE : jamais deux
-- questions dans une même conversation, même depuis deux onglets ou deux
-- workers (l'insertion concurrente perd). SET NULL partout sauf sur la
-- personne : le journal garde le rythme (dernière question posée) même quand
-- la conversation ou le souvenir disparaissent.
--
-- `users.memory_questions` : '' = rarement (le défaut), `often` = de temps en
-- temps, `off` = jamais.

CREATE TABLE user_memory_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    qkey VARCHAR(40) NOT NULL,
    memory_id INT UNSIGNED NULL DEFAULT NULL,
    conversation_id INT UNSIGNED NULL DEFAULT NULL,
    message_id INT UNSIGNED NULL DEFAULT NULL,
    asked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at DATETIME NULL DEFAULT NULL,
    outcome VARCHAR(12) NULL DEFAULT NULL,
    UNIQUE KEY uq_umq_conv (conversation_id),
    KEY idx_umq_user (user_id, asked_at),
    CONSTRAINT fk_umq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_umq_memory FOREIGN KEY (memory_id) REFERENCES user_memories(id) ON DELETE SET NULL,
    CONSTRAINT fk_umq_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_umq_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users
    ADD COLUMN memory_questions VARCHAR(10) NOT NULL DEFAULT '';
