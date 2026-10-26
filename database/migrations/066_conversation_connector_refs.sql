-- Objets connecteurs touchés dans une conversation : fichiers Drive, emails,
-- évènements d'agenda, tâches, workflows.
--
-- POURQUOI une table et non le seul historique : `ChatController::buildLLMMessages`
-- ne rejoue que le `content` des messages. Les `tool_call`/`tool_result` d'un
-- message ANTÉRIEUR ne sont jamais réinjectés — au tour suivant, le modèle a
-- donc perdu l'identifiant du fichier qu'il vient de lire, et « modifie ce
-- document » repartait d'une recherche à l'aveugle (ou d'un id inventé).
--
-- Une LIGNE PAR OBJET, et non un blob JSON sur `conversations` : (1) le chat
-- s'exécute dans le worker, deux messages concurrents sur la même conversation
-- perdraient des entrées en lecture-modification-écriture ; (2) `Conversation`
-- fait des `SELECT c.*` pour la liste de la barre latérale, une colonne TEXT y
-- serait tirée pour chaque conversation affichée.
CREATE TABLE IF NOT EXISTS conversation_connector_refs (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- INT UNSIGNED, comme `conversations.id` : un INT signé fait échouer la
    -- contrainte de clé étrangère (« incompatible », MySQL 3780).
    conversation_id INT UNSIGNED NOT NULL,
    connector       VARCHAR(32)  NOT NULL,
    kind            VARCHAR(32)  NOT NULL,
    ref_id          VARCHAR(400) NOT NULL,
    name            VARCHAR(300) NOT NULL,
    extra           VARCHAR(200) NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Revoir le même fichier RAFRAÎCHIT sa ligne au lieu d'en créer une 2e :
    -- l'objet le plus récemment touché doit rester en tête du fragment.
    UNIQUE KEY uniq_conv_ref (conversation_id, connector, ref_id),
    KEY idx_conv_recent (conversation_id, updated_at),
    CONSTRAINT fk_ccr_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
