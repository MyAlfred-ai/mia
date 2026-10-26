<?php

declare(strict_types=1);

/**
 * Connexion OneDrive PERSONNELLE d'un utilisateur (OAuth délégué Microsoft,
 * jetons dans user_drive_tokens avec provider='onedrive').
 *
 * Portée volontairement étroite : cette connexion sert UNIQUEMENT à alimenter
 * les bases de connaissances (navigation dans les dossiers + miroir). Elle
 * n'est pas exposée comme connecteur de chat — le service reste donc minimal,
 * là où UserDriveService porte en plus la lecture/écriture Docs & Sheets.
 *
 * Refresh lazy (marge 60 s) et PERSISTANCE DU NOUVEAU refresh token :
 * Microsoft renvoie un jeton roulant à chaque rafraîchissement. Ne pas
 * l'enregistrer ferait expirer la connexion au bout de la durée de vie du
 * premier jeton, sans rien dans les logs. invalid_grant → statut 'revoked' +
 * message de reconnexion.
 */
class UserOneDriveService
{
    /** Message unique de reconnexion (repris par le fournisseur et l'admin). */
    public const MSG_RECONNECT = 'Connexion OneDrive expirée ou révoquée. '
        . 'Reconnectez-la dans Réglages → Comptes connectés.';

    private ?string $accessToken;
    private ?int $accessTokenExpiresAt;
    private string $refreshToken;

    private function __construct(
        private readonly int $userId,
        string $refreshToken,
        ?string $accessToken,
        ?string $accessTokenExpiresAt
    ) {
        $this->refreshToken         = $refreshToken;
        $this->accessToken          = $accessToken;
        $this->accessTokenExpiresAt = $accessTokenExpiresAt !== null
            ? (strtotime($accessTokenExpiresAt) ?: null)
            : null;
    }

    /** Instance pour l'utilisateur, ou null si OneDrive non connecté (ou révoqué). */
    public static function forUser(int $userId): ?self
    {
        if (!MicrosoftOAuth::isConfigured()) {
            return null;
        }
        $row = UserDriveToken::forUser($userId, UserDriveToken::PROVIDER_ONEDRIVE);
        if ($row === null || (string)$row['status'] !== 'ok' || (string)$row['refresh_token'] === '') {
            return null;
        }
        return new self(
            $userId,
            (string)$row['refresh_token'],
            $row['access_token'] !== null ? (string)$row['access_token'] : null,
            $row['access_token_expires_at'] !== null ? (string)$row['access_token_expires_at'] : null
        );
    }

    /** L'utilisateur a-t-il une connexion OneDrive utilisable ? */
    public static function isConnected(int $userId): bool
    {
        return self::forUser($userId) !== null;
    }

    /**
     * Fournisseur de dossier (miroir + explorateur) portant les droits de CET
     * utilisateur. Le supplier rafraîchit le jeton à la demande : un cycle de
     * 100 fichiers dure plus longtemps qu'un access token.
     */
    public function folderProvider(): OneDriveProvider
    {
        return OneDriveProvider::withBearerSupplier(fn(): string => $this->getValidAccessToken());
    }

    /**
     * Access token valide, rafraîchi si expiré (marge 60 s). Le refresh token
     * renvoyé par Microsoft REMPLACE l'ancien en base — jetons roulants.
     */
    public function getValidAccessToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh
            && $this->accessToken !== null
            && $this->accessTokenExpiresAt !== null
            && $this->accessTokenExpiresAt > time() + 60) {
            return $this->accessToken;
        }

        $revoked = false;
        $fresh   = MicrosoftOAuth::refreshAccessToken($this->refreshToken, $revoked);
        if ($fresh === null) {
            if ($revoked) {
                UserDriveToken::markRevoked($this->userId, UserDriveToken::PROVIDER_ONEDRIVE);
                throw new \RuntimeException(self::MSG_RECONNECT);
            }
            throw new \RuntimeException('Microsoft injoignable — réessayez dans quelques instants.');
        }

        $this->accessToken          = (string)$fresh['access_token'];
        $this->accessTokenExpiresAt = time() + (int)$fresh['expires_in'];
        UserDriveToken::updateAccessToken(
            $this->userId,
            $this->accessToken,
            (int)$fresh['expires_in'],
            UserDriveToken::PROVIDER_ONEDRIVE
        );

        $rolled = $fresh['refresh_token'] ?? null;
        if (is_string($rolled) && $rolled !== '' && $rolled !== $this->refreshToken) {
            $this->refreshToken = $rolled;
            UserDriveToken::updateRefreshToken($this->userId, $rolled, UserDriveToken::PROVIDER_ONEDRIVE);
        }

        return $this->accessToken;
    }
}
