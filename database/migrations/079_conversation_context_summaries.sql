-- Résumé du début d'une LONGUE conversation (fenêtre de contexte bornée,
-- cf. src/Agent/ContextWindow.php) : les messages jusqu'à `upto_message_id`
-- ne sont plus envoyés en entier au modèle, ce résumé les remplace.
--
-- Table à part, et non une colonne de `conversations` : la barre latérale lit
-- `conversations` en `SELECT c.*`, un TEXT y serait tiré pour chaque ligne
-- affichée. Une ligne par conversation, effacée avec elle.
CREATE TABLE IF NOT EXISTS conversation_context_summaries (
    conversation_id  INT UNSIGNED NOT NULL,
    upto_message_id  INT UNSIGNED NOT NULL,
    summary          TEXT         NOT NULL,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id),
    CONSTRAINT fk_ctx_summary_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
