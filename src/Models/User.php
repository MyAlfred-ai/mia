<?php

declare(strict_types=1);

class User
{
    public static function findByGoogleId(string $googleId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE google_id = ?');
        $stmt->execute([$googleId]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Recherche par e-mail SANS le filtre `password_hash IS NOT NULL`.
     *
     * À ne pas confondre avec findByEmail(), qui est le lookup de la connexion
     * par mot de passe et doit garder ce filtre. Celle-ci sert au rapprochement
     * d'une identité SSO avec un compte déjà présent, quel que soit son mode de
     * connexion — un compte Google pur est invisible de l'autre.
     */
    public static function findByEmailAnyType(string $email): ?array
    {
        if (trim($email) === '') {
            return null;
        }
        $stmt = Database::getInstance()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Crée un compte depuis une connexion SSO (aucun mot de passe, aucune
     * identité écrite ici : c'est UserIdentity::link qui la pose).
     *
     * Premier utilisateur = admin actif — MÊME prédicat que
     * Bootstrap::needsFirstAdmin(), qui décide de l'affichage de /setup : les
     * deux chemins d'amorçage ne peuvent pas se contredire.
     *
     * Les suivants : rôle « user », actifs ou non selon le réglage « Activer
     * automatiquement les nouveaux comptes » de l'administration. Ce réglage
     * était autrefois écrit et affiché mais JAMAIS lu : la case restait sans
     * effet, tout nouveau compte arrivait inactif.
     */
    public static function createFromSso(string $email, string $name, string $avatarUrl): array
    {
        $db = Database::getInstance();

        $count  = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $role   = ($count == 0) ? 'admin' : 'user';
        $active = ($count == 0) ? 1 : (Settings::get('new_users_active', '0') === '1' ? 1 : 0);

        $db->prepare('INSERT INTO users (google_id, email, name, avatar_url, role, is_active) VALUES (NULL,?,?,?,?,?)')
           ->execute([$email, $name, $avatarUrl, $role, $active]);

        $userId = (int)$db->lastInsertId();

        // Tout nouveau compte rejoint automatiquement le groupe par défaut.
        UserGroup::addUserToDefaultGroup($userId);

        return self::findById($userId);
    }

    /** Rafraîchit l'affichage et la date de connexion à chaque passage SSO. */
    public static function touchLogin(int $id, string $name, string $avatarUrl): void
    {
        Database::getInstance()
            ->prepare('UPDATE users SET name=?, avatar_url=?, last_login=NOW() WHERE id=?')
            ->execute([$name, $avatarUrl, $id]);
    }

    public static function all(): array
    {
        return Database::getInstance()->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
    }

    public static function update(int $id, array $data): void
    {
        $db = Database::getInstance();
        // credit_quota_k : null = illimité, entier = milliers de crédits / mois
        $quotaK = self::extractQuotaK($data);
        $shouldUpdateQuota = $quotaK !== false;

        if ($shouldUpdateQuota) {
            $db->prepare('UPDATE users SET role=?, is_active=?, credit_quota_k=?, token_quota=? WHERE id=?')
               ->execute([$data['role'], $data['is_active'], $quotaK, $quotaK, $id]);
        } else {
            $db->prepare('UPDATE users SET role=?, is_active=? WHERE id=?')
               ->execute([$data['role'], $data['is_active'], $id]);
        }
    }

    /** Trouve un utilisateur par email (uniquement ceux avec mot de passe local). */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM users WHERE email = ? AND password_hash IS NOT NULL'
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    /** Crée un utilisateur avec authentification email/mot de passe. */
    public static function createWithPassword(
        string $name,
        string $email,
        string $password,
        string $role,
        int $active,
        ?int $legacyGroupId = null
    ): array
    {
        $db   = Database::getInstance();
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // `google_id` reste NULL : depuis la migration 062 elle n'est plus
        // NOT NULL et n'a plus à recevoir un identifiant synthétique `pw_…`
        // inventé pour la satisfaire. Les identités externes vivent dans
        // `user_identities`.
        $db->prepare(
            'INSERT INTO users (google_id, email, name, avatar_url, password_hash, role, is_active, group_id) VALUES (NULL,?,?,?,?,?,?,?)'
        )->execute([$email, $name, '', $hash, $role, $active, $legacyGroupId]);

        $userId = (int)$db->lastInsertId();

        // Tout nouveau compte rejoint automatiquement le groupe par défaut.
        UserGroup::addUserToDefaultGroup($userId);

        return self::findById($userId);
    }

    /** Met à jour le mot de passe d'un utilisateur. */
    public static function updatePassword(int $id, string $password): void
    {
        Database::getInstance()->prepare('UPDATE users SET password_hash=? WHERE id=?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
    }

    /** Supprime un utilisateur et toutes ses données associées. */
    public static function delete(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    }

    /** Vérifie si un email est déjà utilisé. */
    public static function emailExists(string $email): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function effectiveCreditQuotaK(array $user): ?int
    {
        $userQuota = isset($user['credit_quota_k']) && $user['credit_quota_k'] !== null
            ? (int)$user['credit_quota_k']
            : null;
        if ($userQuota === null && isset($user['token_quota']) && $user['token_quota'] !== null) {
            $userQuota = (int)$user['token_quota'];
        }
        if ($userQuota !== null) {
            return $userQuota;
        }

        if (isset($user['effective_group_credit_quota_k']) && $user['effective_group_credit_quota_k'] !== null) {
            return (int)$user['effective_group_credit_quota_k'];
        }
        if (isset($user['effective_group_quota']) && $user['effective_group_quota'] !== null) {
            return (int)$user['effective_group_quota'];
        }

        if (!isset($user['id'])) {
            return null;
        }

        return UserGroup::effectiveQuotaForUserId((int)$user['id']);
    }

    /**
     * Même cascade que effectiveCreditQuotaK() (individuel → hérité → groupes)
     * quand le quota des groupes est déjà connu (UserGroup::effectiveQuotasByUserIds) :
     * pure, sans requête par ligne pour les listes.
     */
    public static function effectiveCreditQuotaKFrom(array $user, ?int $groupQuotaK): ?int
    {
        if (isset($user['credit_quota_k']) && $user['credit_quota_k'] !== null) {
            return (int)$user['credit_quota_k'];
        }
        if (isset($user['token_quota']) && $user['token_quota'] !== null) {
            return (int)$user['token_quota'];
        }
        return $groupQuotaK;
    }

    public static function effectiveCreditQuota(array $user): ?int
    {
        $quotaK = self::effectiveCreditQuotaK($user);
        return $quotaK !== null ? $quotaK * 1_000 : null;
    }

    public static function effectiveTokenQuota(array $user): ?int
    {
        // Compat legacy: ancien appelant attendait "millions de tokens".
        return self::effectiveCreditQuotaK($user);
    }

    private static function extractQuotaK(array $data): int|null|false
    {
        if (array_key_exists('credit_quota_k', $data)) {
            return self::normalizeQuotaK($data['credit_quota_k']);
        }
        if (array_key_exists('token_quota', $data)) {
            return self::normalizeQuotaK($data['token_quota']);
        }
        return false;
    }

    private static function normalizeQuotaK(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $quota = (int)$value;
        return $quota >= 0 ? $quota : null;
    }
}
