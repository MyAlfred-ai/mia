-- Base « Publique » : toute l'organisation la voit et la coche dans le chat.
-- Avec « Privée » (is_private, 095) et « Commune » (ni l'une ni l'autre), ce
-- sont les trois niveaux de visibilité d'une base ou d'une table. Les deux
-- colonnes s'excluent (KnowledgeBase::setVisibility) ; aucune base existante
-- ne change : 0 par défaut.
ALTER TABLE knowledge_bases
    ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER is_private;
