<?php

declare(strict_types=1);

/**
 * Tokens OAuth des connecteurs personnels (table user_drive_tokens, migration
 * 050). Le nom de la table est historique : elle porte aujourd'hui les jetons
 * de TOUS les fournisseurs, Google comme Microsoft.
 * Multi-provider via la colonne `provider` (UNIQUE user_id+provider) : Google
 * Drive par défaut, Gmail via PROVIDER_GMAIL, Google Agenda via
 * PROVIDER_CALENDAR, Google Tasks via PROVIDER_TASKS, OneDrive via
 * PROVIDER_ONEDRIVE, Outlook via PROVIDER_OUTLOOK, calendrier Outlook via
 * PROVIDER_MSCALENDAR, Microsoft To Do via PROVIDER_MSTODO, Microsoft Teams via
 * PROVIDER_TEAMS — chaque méthode accepte
 * le provider en dernier argument (rétro-compatible). Ajouter un connecteur
 * ne demande donc AUCUNE migration.
 * Refresh token et access token chiffrés AES-256 (Encryption), jamais en clair
 * en base. Statut 'revoked' = refresh token invalidé côté Google (invalid_grant)
 * → l'utilisateur doit se reconnecter depuis Réglages → Comptes connectés.
 */
class UserDriveToken
{
    public const PROVIDER          = 'google_drive';
    public const PROVIDER_GMAIL    = 'gmail';
    public const PROVIDER_CALENDAR = 'google_calendar';
    public const PROVIDER_TASKS    = 'google_tasks';
    /** OneDrive : OAuth Microsoft délégué (MicrosoftOAuth), source de bases de connaissances. */
    public const PROVIDER_ONEDRIVE = 'onedrive';
    /** Outlook : OAuth Microsoft par utilisateur (MicrosoftOutlookOAuth), connecteur du chat. */
    public const PROVIDER_OUTLOOK  = 'outlook';
    /**
     * Calendrier Outlook : OAuth Microsoft par utilisateur
     * (MicrosoftCalendarOAuth), connecteur du chat. Jeton DISTINCT de celui
     * d'Outlook : les deux connecteurs se connectent, se déconnectent et se
     * consentent séparément — les fusionner ferait demander l'accès aux mails à
     * qui ne veut que son agenda.
     */
    public const PROVIDER_MSCALENDAR = 'outlook_calendar';

    /**
     * Microsoft To Do : OAuth Microsoft par utilisateur (MicrosoftTodoOAuth),
     * connecteur du chat. Jeton DISTINCT de ceux d'Outlook et du calendrier,
     * pour la même raison : un consentement par source.
     */
    public const PROVIDER_MSTODO = 'microsoft_todo';

    /**
     * Microsoft Teams : OAuth Microsoft par utilisateur (MicrosoftTeamsOAuth),
     * connecteur du chat. Jeton DISTINCT des autres connecteurs Microsoft :
     * un consentement par source — et celui-ci exige l'accord d'un
     * administrateur de l'annuaire.
     */
    public const PROVIDER_TEAMS = 'microsoft_teams';

    /**
     * Slack : OAuth Slack par utilisateur (SlackMessagesOAuth), connecteur du
     * chat. Particularité du fournisseur, décrite en détail dans
     * `SlackUserOAuth` : la rotation des jetons y est OPTIONNELLE. Sans elle
     * (le cas courant), Slack ne rend aucun refresh token et son jeton
     * utilisateur ne périme pas — c'est donc CE jeton qui est rangé dans la
     * colonne du jeton durable. La colonne porte « le credential qui survit »,
     * pas « un refresh token » au sens strict.
     */
    public const PROVIDER_SLACK = 'slack';

    /**
     * Taille de la colonne `scope` (migration 070). L'autorisation
     * incrémentale de Google fait porter au jeton l'UNION des autorisations de
     * TOUS les connecteurs reliés au même client OAuth : la liste dépassait les
     * 500 caractères d'origine dès le quatrième connecteur (cf. fitScope).
     */
    public const SCOPE_MAX_LEN = 2000;

    /** Ligne déchiffrée de l'utilisateur, ou null si jamais connecté. */
    public static function forUser(int $userId, string $provider = self::PROVIDER): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM user_drive_tokens WHERE user_id = ? AND provider = ?'
        );
        $stmt->execute([$userId, $provider]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['refresh_token'] = Encryption::decrypt((string)$row['refresh_token_enc']);
        $row['access_token']  = $row['access_token_enc'] !== null && $row['access_token_enc'] !== ''
            ? Encryption::decrypt((string)$row['access_token_enc'])
            : null;
        unset($row['refresh_token_enc'], $row['access_token_enc']);
        return $row;
    }

    public static function isConnected(int $userId, string $provider = self::PROVIDER): bool
    {
        $row = self::forUser($userId, $provider);
        return $row !== null && (string)$row['status'] === 'ok';
    }

    /** Enregistre (ou remplace) la connexion après le callback OAuth. */
    public static function saveConnection(
        int $userId,
        string $refreshToken,
        string $accessToken,
        int $expiresIn,
        string $scope,
        ?string $email,
        string $provider = self::PROVIDER
    ): void {
        Database::getInstance()->prepare(
            'INSERT INTO user_drive_tokens
                (user_id, provider, google_email, refresh_token_enc, access_token_enc,
                 access_token_expires_at, scope, status)
             VALUES (?,?,?,?,?,?,?,\'ok\')
             ON DUPLICATE KEY UPDATE
                google_email = VALUES(google_email),
                refresh_token_enc = VALUES(refresh_token_enc),
                access_token_enc = VALUES(access_token_enc),
                access_token_expires_at = VALUES(access_token_expires_at),
                scope = VALUES(scope),
                status = \'ok\''
        )->execute([
            $userId,
            $provider,
            $email,
            Encryption::encrypt($refreshToken),
            Encryption::encrypt($accessToken),
            date('Y-m-d H:i:s', time() + max(60, $expiresIn)),
            self::fitScope($scope),
        ]);
    }

    /**
     * Borne la liste d'autorisations à la taille de la colonne, EN COUPANT À LA
     * FRONTIÈRE d'un scope.
     *
     * Un `mb_substr` brut coupait au milieu d'une URL : le scope amputé ne
     * correspondait plus à rien (la comparaison est exacte, et doit l'être —
     * `.../auth/drive.metadata` est un préfixe de `drive.metadata.readonly`),
     * et l'application se croyait privée d'une autorisation que Google avait
     * bel et bien accordée. Avec l'autorisation incrémentale, la liste porte
     * l'union de TOUS les connecteurs Google reliés : elle dépassait 500
     * caractères dès le quatrième, et l'ordre des scopes n'étant pas garanti,
     * la capacité perdue changeait d'une connexion à l'autre.
     *
     * Statique pure, testable sans base.
     */
    public static function fitScope(string $scope, int $max = self::SCOPE_MAX_LEN): string
    {
        $scope = trim($scope);
        if ($scope === '' || mb_strlen($scope) <= $max) {
            return $scope;
        }
        $garde = [];
        $len   = 0;
        foreach (preg_split('/\s+/', $scope) ?: [] as $un) {
            $ajout = ($garde === [] ? 0 : 1) + mb_strlen($un);
            if ($len + $ajout > $max) {
                break;
            }
            $garde[] = $un;
            $len    += $ajout;
        }
        return implode(' ', $garde);
    }

    /** Met en cache le nouvel access token après un refresh. */
    public static function updateAccessToken(int $userId, string $accessToken, int $expiresIn, string $provider = self::PROVIDER): void
    {
        Database::getInstance()->prepare(
            'UPDATE user_drive_tokens SET access_token_enc = ?, access_token_expires_at = ?
             WHERE user_id = ? AND provider = ?'
        )->execute([
            Encryption::encrypt($accessToken),
            date('Y-m-d H:i:s', time() + max(60, $expiresIn)),
            $userId,
            $provider,
        ]);
    }

    /**
     * Aligne les autorisations enregistrées sur celles que le fournisseur
     * vient de redire au rafraîchissement (cf. ConnectorApiService).
     *
     * Sans cette écriture, la colonne restait figée au jour de la connexion :
     * un utilisateur ayant élargi ses autorisations depuis un AUTRE connecteur
     * du même client OAuth gardait des outils désactivés — MIA lui refusait un
     * renommage que Google aurait accepté — et, à l'inverse, un accès retiré
     * côté Google laissait des outils exposés, qui échouaient en 403.
     */
    public static function updateScope(int $userId, string $scope, string $provider = self::PROVIDER): void
    {
        Database::getInstance()->prepare(
            'UPDATE user_drive_tokens SET scope = ? WHERE user_id = ? AND provider = ?'
        )->execute([self::fitScope($scope), $userId, $provider]);
    }

    /**
     * Remplace le refresh token stocké. Microsoft renvoie un jeton ROULANT à
     * chaque rafraîchissement : sans cette écriture, la connexion OneDrive
     * mourrait à l'expiration du tout premier jeton. (Google, lui, garde le
     * même refresh token — d'où une méthode dédiée plutôt qu'un paramètre de
     * plus sur updateAccessToken, qu'aucun appelant Google n'utiliserait.)
     */
    public static function updateRefreshToken(int $userId, string $refreshToken, string $provider = self::PROVIDER): void
    {
        Database::getInstance()->prepare(
            'UPDATE user_drive_tokens SET refresh_token_enc = ? WHERE user_id = ? AND provider = ?'
        )->execute([Encryption::encrypt($refreshToken), $userId, $provider]);
    }

    /** Refresh token invalidé côté fournisseur : reconnexion nécessaire. */
    public static function markRevoked(int $userId, string $provider = self::PROVIDER): void
    {
        Database::getInstance()->prepare(
            'UPDATE user_drive_tokens SET status = \'revoked\', access_token_enc = NULL,
                    access_token_expires_at = NULL
             WHERE user_id = ? AND provider = ?'
        )->execute([$userId, $provider]);
    }

    public static function delete(int $userId, string $provider = self::PROVIDER): void
    {
        Database::getInstance()->prepare(
            'DELETE FROM user_drive_tokens WHERE user_id = ? AND provider = ?'
        )->execute([$userId, $provider]);
    }

    /**
     * Providers dont la connexion de cet utilisateur a été RÉVOQUÉE par son
     * fournisseur (ligne présente, statut 'revoked') : il avait relié son
     * compte, il ne l'est plus, et seule une reconnexion y remédie.
     *
     * UNE requête pour tous les connecteurs, sans déchiffrement — elle sert au
     * rappel de reconnexion (ConnectorRegistry::reconnectNeeded), calculé sur
     * chaque page rendue avec le menu du compte.
     *
     * @return list<string>
     */
    public static function revokedProvidersForUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT provider FROM user_drive_tokens WHERE user_id = ? AND status = \'revoked\' ORDER BY provider'
        );
        $stmt->execute([$userId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Nombre de comptes actuellement reliés à ce fournisseur (statut 'ok').
     * Une connexion révoquée n'est pas comptée : elle n'ouvre plus rien.
     */
    public static function countConnected(string $provider = self::PROVIDER): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM user_drive_tokens WHERE provider = ? AND status = \'ok\''
        );
        $stmt->execute([$provider]);
        return (int)$stmt->fetchColumn();
    }
}
