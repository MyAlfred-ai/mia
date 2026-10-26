-- Propriétaire d'un assistant : permet au rôle "creator" de ne voir/éditer
-- que SES propres assistants. NULL = assistant historique / créé par un admin
-- (visible uniquement par les admins, qui voient tout de toute façon).
-- ON DELETE SET NULL : si le créateur est supprimé, l'assistant reste mais
-- devient orphelin (géré par les admins).

ALTER TABLE profiles
    ADD COLUMN created_by INT UNSIGNED NULL AFTER is_active,
    ADD CONSTRAINT fk_profiles_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_profiles_created_by ON profiles(created_by);
