-- Identités externes d'un compte — une ligne par (fournisseur, sujet).
--
-- `users.google_id` était la SEULE colonne d'identité, et elle est UNIQUE : un
-- utilisateur ne pouvait donc porter qu'un seul fournisseur. Ajouter Microsoft
-- par une deuxième colonne aurait rejoué le même mur au fournisseur suivant, et
-- ajouté une branche à chacun des appels de findByGoogleId().
--
-- subject : `sub` chez Google ; `<tid>.<oid>` chez Microsoft Entra — l'`oid`
-- seul n'est unique QUE dans son annuaire (un invité présent dans deux
-- annuaires porte deux `oid`), l'annuaire doit donc entrer dans la clé.
CREATE TABLE user_identities (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    provider   VARCHAR(20)  NOT NULL,          -- 'google' | 'microsoft'
    subject    VARCHAR(255) NOT NULL,
    email      VARCHAR(255)     DEFAULT NULL,  -- adresse vue chez le fournisseur
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME         DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ui_provider_subject (provider, subject),
    KEY idx_ui_user (user_id, provider),
    CONSTRAINT fk_ui_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill des VRAIES identités Google uniquement.
--
-- `local_admin` et `pw_%` sont des valeurs SYNTHÉTIQUES, inventées pour
-- contourner le NOT NULL de la colonne : les reporter ici créerait des
-- identités Google qui n'existent pas, et le jour où un utilisateur Google
-- légitime porterait ce sujet, il serait rattaché au mauvais compte.
INSERT IGNORE INTO user_identities (user_id, provider, subject, email, created_at, last_login)
SELECT id, 'google', google_id, email, created_at, last_login
  FROM users
 WHERE google_id IS NOT NULL
   AND google_id <> ''
   AND google_id <> 'local_admin'
   AND google_id NOT LIKE 'pw\_%';

-- La colonne n'est plus la clé d'identité : un compte Microsoft ou un compte à
-- mot de passe n'en a aucune, et n'a plus à s'en inventer une.
--
-- Colonne CONSERVÉE, pas supprimée : les sauvegardes produites par les versions
-- antérieures la listent dans leurs INSERT (BackupService exporte colonne par
-- colonne), et `local_admin` reste l'ancre de l'accès de secours. InnoDB tolère
-- plusieurs NULL sous un index UNIQUE : uq_users_google_id reste valide.
ALTER TABLE users MODIFY google_id VARCHAR(255) NULL DEFAULT NULL;
