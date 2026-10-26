-- Suppression du rôle "pending" : les nouveaux comptes naissent désormais en
-- rôle "user" (toujours inactifs jusqu'à validation admin via is_active).
-- On migre les comptes existants encore en "pending" vers "user". Leur
-- is_active est préservé : un compte non encore validé (is_active=0) reste
-- bloqué à la connexion comme avant — seul le libellé du rôle change.

UPDATE users SET role = 'user' WHERE role = 'pending';
