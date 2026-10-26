<?php

declare(strict_types=1);

/**
 * Socle des flux OAuth Google PAR UTILISATEUR (connecteurs personnels : Drive,
 * Gmail, Agenda, Tasks…). Un connecteur = une sous-classe DÉCLARATIVE qui ne
 * porte que ses scopes, son préfixe de state, sa clé de session et son
 * étiquette de log — toute la mécanique (endpoints Google, échange de code,
 * refresh, révocation, flux natif mobile) vit ICI, en un seul exemplaire.
 * Mettre à jour l'API OAuth de Google = modifier CE fichier uniquement.
 *
 * Chaque connecteur réutilise le client OAuth du login ET son URI de
 * redirection, tous deux lus dans SsoRegistry (Administration → Organisation
 * → Authentification, avec repli sur le `.env`) : AUCUNE configuration console
 * supplémentaire n'est requise, et le worker voit la même valeur que l'app.
 * Le state est préfixé (STATE_PREFIX) pour que AuthController::callback()
 * distingue un retour connecteur d'un retour login et route vers le bon
 * callback de UserSettingsController.
 *
 * access_type=offline + prompt=consent → refresh token, stocké chiffré par
 * UserDriveToken. Les scopes réellement accordés sont stockés (colonne scope)
 * et re-vérifiés par le service du connecteur : un consentement partiel donne
 * une connexion dégradée (lecture seule…) plutôt qu'un connecteur cassé.
 *
 * Logs : statut HTTP et code d'erreur Google uniquement — JAMAIS un token.
 */
abstract class GoogleUserOAuth
{
    private const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USER_URL   = 'https://www.googleapis.com/oauth2/v2/userinfo';

    // ---- À déclarer par chaque connecteur -----------------------------------

    /** Scopes demandés au consentement (le connecteur décide de son périmètre). */
    public const SCOPES = [];

    /**
     * Préfixe du state OAuth — c'est LUI que AuthController::callback()
     * reconnaît pour router le retour vers le bon connecteur. Unique par
     * connecteur, et jamais un préfixe l'un de l'autre.
     */
    public const STATE_PREFIX = '';

    /** Clé de session du state — une par connecteur, deux flux simultanés ne se marchent pas dessus. */
    protected const SESSION_KEY = '';

    /** Étiquette des logs (« Drive OAuth »…). */
    protected const LOG_LABEL = '';

    // ---- Mécanique commune ---------------------------------------------------

    public static function isConfigured(): bool
    {
        return SsoRegistry::isConfigured(SsoRegistry::GOOGLE);
    }

    /**
     * URI de redirection = celle du LOGIN Google (seule URI garantie déclarée
     * dans la console). Le retour est routé vers le flux du connecteur grâce
     * au préfixe du state — pas d'URI dédiée à déclarer.
     */
    public static function redirectUri(): string
    {
        return SsoRegistry::redirectUri();
    }

    public static function getAuthUrl(): string
    {
        // Préfixe : permet à /auth/callback de reconnaître ce retour.
        $state = static::STATE_PREFIX . bin2hex(random_bytes(16));
        Session::set(static::SESSION_KEY, $state);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => SsoRegistry::googleClientId(),
            'redirect_uri'           => self::redirectUri(),
            'response_type'          => 'code',
            'scope'                  => implode(' ', static::SCOPES),
            'state'                  => $state,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    /**
     * Variante app mobile : le consentement s'ouvre dans le navigateur
     * système, le retour n'a pas la session WebView → state PORTEUR signé
     * (NativeOauthState), validé par le callback, pas de state en session.
     */
    public static function getAuthUrlNative(int $userId): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => SsoRegistry::googleClientId(),
            'redirect_uri'           => self::redirectUri(),
            'response_type'          => 'code',
            'scope'                  => implode(' ', static::SCOPES),
            'state'                  => NativeOauthState::make(static::STATE_PREFIX, $userId),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    /**
     * Échange du code pour le flux natif : le state a déjà été validé par
     * NativeOauthState::consume() (signature + TTL + usage unique) — aucune
     * session disponible ici.
     */
    public static function handleCallbackNative(string $code): ?array
    {
        return $code !== '' ? static::exchangeCodeForTokens($code) : null;
    }

    /**
     * Échange le code contre les tokens + récupère l'email du compte connecté.
     * Null si state invalide, échange refusé, ou refresh token absent (Google
     * ne le renvoie qu'avec access_type=offline + prompt=consent).
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:string,email:?string}|null
     */
    public static function handleCallback(string $code, string $state): ?array
    {
        if ($state === '' || $state !== Session::get(static::SESSION_KEY)) {
            return null;
        }
        Session::remove(static::SESSION_KEY);

        return static::exchangeCodeForTokens($code);
    }

    /** Échange code → tokens + email (commun aux flux web et natif). */
    private static function exchangeCodeForTokens(string $code): ?array
    {
        $token = self::httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => SsoRegistry::googleClientId(),
            'client_secret' => SsoRegistry::googleClientSecret(),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (empty($token['access_token']) || empty($token['refresh_token'])) {
            error_log('[' . static::LOG_LABEL . '] échange de code refusé : ' . (string)($token['error'] ?? 'refresh_token absent'));
            return null;
        }

        return [
            'access_token'  => (string)$token['access_token'],
            'refresh_token' => (string)$token['refresh_token'],
            'expires_in'    => (int)($token['expires_in'] ?? 3600),
            'scope'         => (string)($token['scope'] ?? ''),
            'email'         => self::fetchEmail((string)$token['access_token']),
        ];
    }

    /**
     * Rafraîchit l'access token. Retourne {access_token, expires_in, scope},
     * ou null avec distinction : $revoked=true si Google répond invalid_grant
     * (refresh token révoqué/expiré → reconnexion nécessaire), false sinon
     * (erreur réseau ou transitoire, réessayable).
     *
     * `scope` est remonté parce que Google le renvoie à CHAQUE rafraîchissement
     * et qu'il fait foi : les autorisations d'un utilisateur bougent sans que
     * l'application en soit informée — élargies depuis un autre connecteur
     * (`include_granted_scopes=true` : consentir Gmail ré-accorde l'union),
     * ou retirées depuis son compte Google. Figer la liste au jour de la
     * connexion, c'est refuser un outil que Google accepterait, ou en exposer
     * un qu'il refusera. Le socle le persiste (cf. ConnectorApiService).
     */
    public static function refreshAccessToken(string $refreshToken, bool &$revoked = false): ?array
    {
        $revoked = false;
        $resp = self::httpPost(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => SsoRegistry::googleClientId(),
            'client_secret' => SsoRegistry::googleClientSecret(),
            'grant_type'    => 'refresh_token',
        ]);
        if (!empty($resp['access_token'])) {
            return [
                'access_token' => (string)$resp['access_token'],
                'expires_in'   => (int)($resp['expires_in'] ?? 3600),
                'scope'        => (string)($resp['scope'] ?? ''),
            ];
        }
        $error = (string)($resp['error'] ?? '');
        if ($error === 'invalid_grant') {
            $revoked = true;
        }
        error_log('[' . static::LOG_LABEL . '] refresh échoué : ' . ($error !== '' ? $error : 'réseau/inconnu'));
        return null;
    }

    /** Révocation best-effort (déconnexion) : un échec n'est pas bloquant. */
    public static function revoke(string $token): void
    {
        self::httpPost(self::REVOKE_URL, ['token' => $token]);
    }

    private static function fetchEmail(string $accessToken): ?string
    {
        $ctx = stream_context_create(['http' => [
            'header'  => 'Authorization: Bearer ' . $accessToken,
            'timeout' => 15,
        ]]);
        $json = @file_get_contents(self::USER_URL, false, $ctx);
        if (!$json) {
            return null;
        }
        $data = json_decode($json, true);
        return isset($data['email']) ? (string)$data['email'] : null;
    }

    private static function httpPost(string $url, array $data): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query($data),
            'timeout'       => 20,
            'ignore_errors' => true, // lire le corps d'erreur (invalid_grant…)
        ]]);
        $json = @file_get_contents($url, false, $ctx);
        return $json ? (json_decode($json, true) ?? []) : [];
    }
}
