-- Base « Uniquement pour moi » : réservée à son créateur. Personne d'autre —
-- pas même un administrateur ni un rôle portant « Bases de connaissances —
-- Gérer » — ne la voit, ne la coche dans le chat, ne la partage ni ne
-- l'interroge à travers un assistant (KnowledgeBase::hiddenFrom). Seul son
-- créateur pose ou retire l'option ; la poser retire les partages accordés.
-- 0 par défaut : rien ne change pour les bases existantes.
ALTER TABLE knowledge_bases
    ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER is_system;
