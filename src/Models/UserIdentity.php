<?php

declare(strict_types=1);

/**
 * Identités externes d'un compte (migration 062).
 *
 * Un utilisateur peut porter PLUSIEURS identités — se connecter tantôt par
 * Google, tantôt par Microsoft, sur le même compte. C'est ce que l'ancienne
 * colonne `users.google_id`, UNIQUE, rendait impossible.
 *
 * Cette classe est l'ÉCRIVAIN UNIQUE de la table, et aussi du miroir legacy
 * `users.google_id` : deux écrivains dériveraient au premier oubli.
 */
final class UserIdentity
{
    public const GOOGLE    = 'google';
    public const MICROSOFT = 'microsoft';

    /** Méthodes de connexion qui ne sont pas des identités externes. */
    public const METHOD_PASSWORD    = 'password';
    public const METHOD_LOCAL_ADMIN = 'local_admin';

    /** Libellés d'affichage (liste d'administration, page de profil). */
    public const LABELS = [
        self::GOOGLE    => 'Google',
        self::MICROSOFT => 'Microsoft',
    ];

    /** Utilisateur portant cette identité, null si le sujet est inconnu. */
    public static function findUser(string $provider, string $subject): ?array
    {
        if ($provider === '' || $subject === '') {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT u.* FROM user_identities i
             JOIN users u ON u.id = i.user_id
             WHERE i.provider = ? AND i.subject = ?
             LIMIT 1'
        );
        $stmt->execute([$provider, $subject]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Rattache une identité à un compte (idempotent : une reconnexion met
     * simplement à jour l'adresse vue et l'horodatage).
     *
     * @param array{provider:string,subject:string,email:string} $identite
     */
    public static function link(int $userId, array $identite): void
    {
        $provider = (string)$identite['provider'];
        $subject  = (string)$identite['subject'];
        if ($userId <= 0 || $provider === '' || $subject === '') {
            return;
        }

        $db = Database::getInstance();
        $db->prepare(
            'INSERT INTO user_identities (user_id, provider, subject, email, last_login)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), email = VALUES(email), last_login = NOW()'
        )->execute([$userId, $provider, $subject, (string)($identite['email'] ?? '')]);

        // Miroir legacy : `users.google_id` reste renseignée pour le seul
        // fournisseur Google. Elle n'est plus lue au login, mais un retour
        // arrière du code la retrouverait, et l'accès de secours s'y ancre
        // toujours (`local_admin`). À retirer une version plus tard.
        if ($provider === self::GOOGLE) {
            $db->prepare('UPDATE users SET google_id = ? WHERE id = ? AND (google_id IS NULL OR google_id = ?)')
               ->execute([$subject, $userId, $subject]);
        }
    }

    /**
     * Fournisseurs d'un utilisateur.
     *
     * @return list<string>
     */
    public static function providersForUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT provider FROM user_identities WHERE user_id = ? ORDER BY provider'
        );
        $stmt->execute([$userId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Fournisseurs de PLUSIEURS utilisateurs, en une seule requête : la liste
     * d'administration affiche une centaine de lignes, une requête par ligne y
     * serait une régression franche.
     *
     * @param list<int> $userIds
     * @return array<int, list<string>>
     */
    public static function providersForUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT user_id, provider FROM user_identities WHERE user_id IN ($placeholders) ORDER BY provider"
        );
        $stmt->execute($ids);

        $parUtilisateur = [];
        foreach ($stmt->fetchAll() as $ligne) {
            $parUtilisateur[(int)$ligne['user_id']][] = (string)$ligne['provider'];
        }
        return $parUtilisateur;
    }

    /**
     * Libellé de chaque méthode de connexion : fournisseurs externes, accès
     * de secours, mot de passe. SOURCE UNIQUE des libellés de `authLabel()`
     * ET des options du filtre de la liste d'administration — le filtre
     * proposait « Email » quand les comptes affichaient « Mot de passe », et
     * ne connaissait pas Microsoft : deux listes, deux vocabulaires.
     *
     * @return array<string, string> méthode => libellé, dans l'ordre d'affichage
     */
    public static function methodLabels(): array
    {
        return self::LABELS + [
            self::METHOD_LOCAL_ADMIN => t('Admin local'),
            self::METHOD_PASSWORD    => t('Mot de passe'),
        ];
    }

    /**
     * Méthodes de connexion d'un compte (pur, testable) : une clé par moyen
     * d'entrer, sans doublon. Un compte peut en porter plusieurs (Google ET
     * mot de passe) — c'est pourquoi le filtre de la liste teste
     * l'APPARTENANCE à cette liste, jamais l'égalité avec le libellé assemblé.
     *
     * @param list<string> $providers
     * @return list<string>
     */
    public static function authMethods(array $providers, bool $hasPassword, bool $isBreakGlass = false): array
    {
        $methodes = [];
        foreach ($providers as $provider) {
            $provider = (string)$provider;
            if ($provider !== '' && !in_array($provider, $methodes, true)) {
                $methodes[] = $provider;
            }
        }
        if ($isBreakGlass) {
            $methodes[] = self::METHOD_LOCAL_ADMIN;
        }
        if ($hasPassword) {
            $methodes[] = self::METHOD_PASSWORD;
        }
        return $methodes;
    }

    /**
     * Libellé du mode de connexion d'un compte (pur, testable).
     *
     * Remplace le reniflage de `users.google_id` qui traînait dans deux
     * gabarits : cette colonne portait trois encodages différents (identifiant
     * Google réel, `pw_…`, `local_admin`) et ne pouvait pas décrire un compte
     * Microsoft.
     *
     * @param list<string> $providers
     */
    public static function authLabel(array $providers, bool $hasPassword, bool $isBreakGlass = false): string
    {
        $libelles = self::methodLabels();
        return implode(' + ', array_map(
            static fn(string $methode): string => $libelles[$methode] ?? $methode,
            self::authMethods($providers, $hasPassword, $isBreakGlass)
        ));
    }
}
