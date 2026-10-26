-- Bases de connaissances choisies par l'UTILISATEUR pour une conversation.
--
-- POURQUOI EN BASE, et non en localStorage comme l'outil actif ou les
-- interrupteurs de connecteurs : une base de connaissances est un PÉRIMÈTRE DE
-- DONNÉES, pas une préférence d'affichage. Deux conséquences.
--   (1) Le choix doit suivre le COMPTE, pas le navigateur — reprendre la
--       conversation sur un autre appareil sans ses bases donnerait une réponse
--       différente, sans un mot.
--   (2) C'est le SERVEUR qui doit le rejouer : le tour de chat s'exécute dans
--       le worker de jobs (JobQueue), qui ne voit aucun localStorage.
--
-- UNE LIGNE PAR BASE, et non un blob JSON sur `conversations` — mêmes raisons
-- qu'en 066, plus une troisième :
--   (1) `Conversation::allForUser()` fait un `SELECT c.*` pour la barre
--       latérale : une colonne TEXT y serait tirée à chaque ligne affichée ;
--   (2) le chat tourne dans le worker, un read-modify-write sur un blob JSON
--       perdrait des entrées entre deux messages concurrents ;
--   (3) une liste dénormalisée ne peut porter AUCUNE clé étrangère : une base
--       supprimée y laisserait un identifiant fantôme, et la pastille de
--       comptage mentirait jusqu'à la prochaine ouverture.
--
-- CASCADE DES DEUX CÔTÉS. La sélection meurt avec la conversation (comme 066)
-- et avec la base (comme `profile_knowledge_bases`). Un SET NULL est impossible
-- — kb_id fait partie de la clé primaire — et un RESTRICT ferait échouer
-- `KnowledgeBase::delete()` dès qu'une conversation aurait coché la base.
--
-- Une DÉSACTIVATION ou une RÉVOCATION DE PARTAGE, en revanche, ne touche PAS
-- cette table : il n'y a rien à supprimer. `ConversationKnowledgeBase::
-- selectedKbsForTurn()` re-filtre sur le périmètre COURANT à chaque message, le
-- choix survit donc à une coupure temporaire et se rétablit dès que l'accès
-- revient. La ligne ne porte qu'un identifiant, jamais de contenu.
CREATE TABLE IF NOT EXISTS conversation_knowledge_bases (
    -- INT UNSIGNED des deux côtés, comme `conversations.id` et
    -- `knowledge_bases.id` : un INT signé fait échouer la contrainte de clé
    -- étrangère (« incompatible », MySQL 3780).
    conversation_id INT UNSIGNED NOT NULL,
    kb_id           INT UNSIGNED NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- PK composite = l'unicité (une base ne se coche pas deux fois), même forme
    -- que `profile_knowledge_bases`. Aucun id de substitution : rien ici n'a
    -- besoin d'un ORDER BY … LIMIT comme la purge de 066. Et pas de colonne
    -- `position` : l'ordre d'affichage et de trace est `kb.name ASC`, comme
    -- partout ailleurs — un second critère d'ordre serait un second à maintenir.
    PRIMARY KEY (conversation_id, kb_id),
    KEY idx_ckb_kb (kb_id),
    CONSTRAINT fk_ckb_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ckb_kb FOREIGN KEY (kb_id)
        REFERENCES knowledge_bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
