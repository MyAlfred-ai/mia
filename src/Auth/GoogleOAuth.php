<?php

declare(strict_types=1);

class GoogleOAuth
{
    private const AUTH_URL      = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    private const USER_URL      = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    /** Identifiants et URI : SsoRegistry (base, puis repli sur le .env). */
    public static function isConfigured(): bool
    {
        return SsoRegistry::isConfigured(SsoRegistry::GOOGLE);
    }

    public static function getAuthUrl(): string
    {
        // Préfixe `sso_google_` : le callback unique distingue une connexion
        // Google d'un retour de connecteur personnel (drive_/gmail_/gcal_).
        $state = 'sso_google_' . bin2hex(random_bytes(16));
        Session::set('oauth_state', $state);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => SsoRegistry::googleClientId(),
            'redirect_uri'  => SsoRegistry::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }

    public static function handleCallback(string $code, string $state): ?array
    {
        // hash_equals : comparaison en temps constant, comme partout ailleurs
        // dans le code (Csrf::verify, NativeOauthState::consume).
        $attendu = (string)Session::get('oauth_state', '');
        if ($state === '' || $attendu === '' || !hash_equals($attendu, $state)) {
            return null;
        }
        Session::remove('oauth_state');

        $token = self::exchangeCode($code);
        if (!$token) return null;

        return self::getUserInfo($token['access_token']);
    }

    /**
     * Vérifie un idToken Google (connexion native de l'app mobile) via
     * l'endpoint tokeninfo : Google valide la signature, on ne contrôle que
     * les claims. Retourne les infos utilisateur normalisées au même format
     * que getUserInfo() (id, email, name, picture), ou null si invalide.
     *
     * Audiences acceptées : client web (GOOGLE_CLIENT_ID — c'est aussi
     * l'audience des idTokens ANDROID, Credential Manager étant configuré
     * avec le client web en serverClientId) et client iOS
     * (GOOGLE_CLIENT_ID_IOS). Aucun client Android côté PHP : son client ID
     * n'apparaît jamais comme audience.
     */
    public static function verifyIdToken(string $idToken): ?array
    {
        if ($idToken === '' || strlen($idToken) > 4096) {
            return null;
        }

        $json = @file_get_contents(self::TOKENINFO_URL . '?id_token=' . urlencode($idToken));
        if (!$json) {
            return null;
        }
        $claims = json_decode($json, true);
        if (!is_array($claims)) {
            return null;
        }

        $audiences = array_values(array_filter([
            SsoRegistry::googleClientId(),
            defined('GOOGLE_CLIENT_ID_IOS') ? GOOGLE_CLIENT_ID_IOS : '',
        ]));

        if (!self::validateIdTokenClaims($claims, $audiences, time())) {
            return null;
        }

        // tokeninfo renvoie `sub` : normalisation vers le format userinfo
        // attendu par le flux de connexion existant (clé `id`).
        return [
            'id'      => (string)$claims['sub'],
            'email'   => (string)($claims['email'] ?? ''),
            'name'    => (string)($claims['name'] ?? ($claims['email'] ?? '')),
            'picture' => (string)($claims['picture'] ?? ''),
        ];
    }

    /**
     * Validation pure des claims d'un idToken (testable unitairement) :
     * audience connue, émetteur Google, non expiré, email vérifié.
     */
    public static function validateIdTokenClaims(array $claims, array $allowedAudiences, int $now): bool
    {
        if (empty($claims['sub']) || !is_string($claims['sub'])) {
            return false;
        }
        if (empty($allowedAudiences) || !in_array($claims['aud'] ?? '', $allowedAudiences, true)) {
            return false;
        }
        $issuers = ['https://accounts.google.com', 'accounts.google.com'];
        if (!in_array($claims['iss'] ?? '', $issuers, true)) {
            return false;
        }
        if ((int)($claims['exp'] ?? 0) <= $now) {
            return false;
        }
        // tokeninfo sérialise les booléens en chaînes.
        $verified = $claims['email_verified'] ?? false;
        if ($verified !== 'true' && $verified !== true) {
            return false;
        }
        return true;
    }

    private static function exchangeCode(string $code): ?array
    {
        $response = self::httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => SsoRegistry::googleClientId(),
            'client_secret' => SsoRegistry::googleClientSecret(),
            'redirect_uri'  => SsoRegistry::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);

        return $response['access_token'] ?? null ? $response : null;
    }

    private static function getUserInfo(string $accessToken): ?array
    {
        $ctx = stream_context_create(['http' => [
            'header' => 'Authorization: Bearer ' . $accessToken,
        ]]);
        $json = @file_get_contents(self::USER_URL, false, $ctx);
        if (!$json) return null;

        $data = json_decode($json, true);
        return ($data && isset($data['id'])) ? $data : null;
    }

    private static function httpPost(string $url, array $data): array
    {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data),
            // Sans délai maximal, un Google injoignable bloquait la requête de
            // connexion jusqu'au timeout PHP ; sans ignore_errors, le corps
            // d'erreur (invalid_grant…) était perdu. Les trois classes de
            // connecteurs portaient déjà ces deux options, pas celle-ci.
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $json = @file_get_contents($url, false, $ctx);
        return $json ? (json_decode($json, true) ?? []) : [];
    }
}
