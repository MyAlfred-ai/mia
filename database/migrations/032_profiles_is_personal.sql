-- Assistants "personnels" : créés par un utilisateur "creator", visibles
-- uniquement par lui dans le chat (contrairement aux assistants "organisation"
-- créés par un admin et distribués via les groupes).
-- is_personal = 1 → assistant personnel ; 0 → assistant organisation (défaut).

ALTER TABLE profiles
    ADD COLUMN is_personal TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by;

-- Backfill : tout assistant déjà créé par un compte actuellement "creator"
-- devient personnel. Les assistants admin / historiques restent organisation.
UPDATE profiles p
    JOIN users u ON u.id = p.created_by
    SET p.is_personal = 1
    WHERE u.role = 'creator';

CREATE INDEX idx_profiles_personal_owner ON profiles(is_personal, created_by);
