-- Sécurité : un assistant personnel (is_personal = 1) ne doit JAMAIS être
-- autorisé via un groupe (cf. Profile::accessibleByUser qui filtre déjà
-- is_personal=0, mais une promotion ultérieure ré-émergerait l'accès sans
-- nouvelle validation admin). On purge les liens accidentels existants.
--
-- À partir de cette migration :
-- - UserGroup::setProfileAccess refuse les profile_ids personnels en entrée.
-- - Profile::setPersonal(id, true) purge automatiquement les liens.
-- - L'UI admin (templates/admin/user-groups.php) ne propose plus les
--   assistants personnels dans la liste « Assistants autorisés ».
DELETE ugp FROM user_group_profiles ugp
INNER JOIN profiles p ON p.id = ugp.profile_id
WHERE p.is_personal = 1;
