-- Carte d'approbation rattachée à la RÉPONSE qui l'a proposée : au
-- rechargement, une carte encore en attente revient sous son message au lieu
-- d'être empilée en fin de conversation. Posé après coup (la réponse n'existe
-- qu'une fois le flux terminé, la carte naît pendant) ; NULL pour les actions
-- d'avant, ou si la réponse a été supprimée (régénération) — la carte revient
-- alors en fin de conversation, comme avant.
ALTER TABLE drive_pending_actions
    ADD COLUMN message_id INT UNSIGNED NULL DEFAULT NULL AFTER conversation_id,
    ADD KEY idx_dpa_message (message_id),
    ADD CONSTRAINT fk_dpa_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL;
