-- « Peut nourrir » (bases en direct, MyBrain) : les membres de ce groupe — ou
-- cette personne — peuvent proposer un texte au cerveau depuis une conversation.
-- Le texte n'y entre jamais directement : il part, après approbation dans MIA,
-- dans la file de revue de MyBrain, où un humain le tranche.
--
-- Une case par groupe, décision produit du 2026-09-15 : pouvoir lire le cerveau
-- ne donne pas le droit de le nourrir. Il faut en plus que la clé d'application
-- porte le droit « nourrir » côté MyBrain — la clé fixe le maximum, les groupes
-- décident qui. Proposé par l'écran pour les seules bases en direct ; ignoré
-- sur une base locale (KnowledgeBase::setShares).
ALTER TABLE knowledge_base_shares
    ADD COLUMN can_feed TINYINT(1) NOT NULL DEFAULT 0 AFTER default_on;
