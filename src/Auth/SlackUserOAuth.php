<?php

declare(strict_types=1);

/**
 * Socle des flux OAuth SLACK par utilisateur — troisième famille de
 * connecteurs personnels, après Google (`GoogleUserOAuth`) et Microsoft
 * (`MicrosoftUserOAuth`). Un connecteur = une sous-classe DÉCLARATIVE qui ne
 * porte que ses scopes, son préfixe de state, sa clé de session et son
 * étiquette de log ; toute la mécanique vit ICI, en un seul exemplaire.
 *
 * ── QUATRE PARTICULARITÉS SLACK, TOUTES PAYÉES DANS LE CODE ────────────────
 *
 *  1. **`user_scope`, et surtout PAS `scope`.** OAuth v2 de Slack demande deux
 *     jeux d'autorisations dans la même requête : `scope` = celles du JETON DE
 *     BOT (l'application parle en son nom propre), `user_scope` = celles du
 *     JETON UTILISATEUR (l'application agit AU NOM de la personne). MIA veut le
 *     second — il ne voit que ce que la personne voit, et ne publie qu'en son
 *     nom. Passer nos scopes dans `scope` « marche » : l'écran de consentement
 *     s'affiche, le retour est un succès… et `authed_user.access_token` est
 *     ABSENT. Aucune erreur, un connecteur muet. D'où `user_scope` seul, et
 *     aucun jeton de bot demandé nulle part.
 *
 *  2. **Un succès HTTP ne dit RIEN.** Slack répond 200 à presque tout, la
 *     réussite étant portée par `ok` dans le corps JSON. `oauth.v2.access` ne
 *     fait pas exception : un `client_secret` faux rend un 200 avec
 *     `{"ok":false,"error":"invalid_client_id"}`. Chaque réponse est donc lue,
 *     jamais présumée (cf. `SlackUserApiService` pour le même piège côté API).
 *
 *  3. **La ROTATION des jetons est optionnelle, et désactivée par défaut.**
 *     Quand elle est active, le jeton utilisateur expire en 12 h et un
 *     `refresh_token` (`xoxe-…`) l'accompagne — jetons ROULANTS, comme chez
 *     Microsoft. Quand elle ne l'est pas — le cas de la quasi-totalité des
 *     applications —, le jeton (`xoxp-…`) ne périme jamais et il N'Y A AUCUN
 *     refresh token. Or la plomberie commune exige un « jeton durable » en base
 *     (`ConnectorApiService::tokenRowForUser` refuse une ligne sans lui). On y
 *     range donc le jeton réellement durable : le refresh token s'il existe,
 *     sinon le jeton utilisateur lui-même. `refreshAccessToken()` le reconnaît
 *     à son préfixe et le rend tel quel, SANS appel réseau — un « rafraîchi-
 *     ssement » qui ne fait que reposer la même valeur en cache.
 *
 *  4. **La révocation existe vraiment** (`auth.revoke`), contrairement à
 *     Microsoft : se déconnecter de MIA retire aussi l'autorisation côté Slack.
 *
 * Le state est préfixé (STATE_PREFIX) pour que `AuthController::callback()`
 * distingue un retour connecteur d'un retour login et route vers le bon
 * callback de `UserSettingsController`.
 *
 * Logs : code d'erreur Slack uniquement — JAMAIS un jeton.
 */
abstract class SlackUserOAuth
{
    private const AUTHORIZE_URL = 'https://slack.com/oauth/v2/authorize';
    private const TOKEN_URL     = SlackApp::API_BASE . '/oauth.v2.access';
    private const REVOKE_URL    = SlackApp::API_BASE . '/auth.revoke';
    private const IDENTITY_URL  = SlackApp::API_BASE . '/auth.test';

    /**
     * Durée de vie annoncée pour un jeton NON rotatif. Slack ne le fait pas
     * expirer ; la valeur ne sert qu'à rythmer la ré-écriture du cache local,
     * jamais à un appel réseau.
     */
    public const NO_ROTATION_TTL = 43200;

    // ---- À déclarer par chaque connecteur -----------------------------------

    /** Scopes UTILISATEUR demandés au consentement (`user_scope`). */
    public const SCOPES = [];

    /**
     * Préfixe du state OAuth — c'est LUI que `AuthController::callback()`
     * reconnaît. Unique par connecteur, et jamais préfixe d'un autre.
     */
    public const STATE_PREFIX = '';

    /** Clé de session du state — une par connecteur. */
    protected const SESSION_KEY = '';

    /** Étiquette des logs (« Slack OAuth »…). */
    protected const LOG_LABEL = '';

    // ---- Mécanique commune ---------------------------------------------------

    /** Prérequis technique : les identifiants de l'application Slack. */
    public static function isConfigured(): bool
    {
        return SlackApp::isConfigured();
    }

    /** URI de redirection = celle du login (aucune URI dédiée à déclarer). */
    public static function redirectUri(): string
    {
        return SlackApp::redirectUri();
    }

    public static function getAuthUrl(): string
    {
        $state = static::STATE_PREFIX . bin2hex(random_bytes(16));
        Session::set(static::SESSION_KEY, $state);

        return self::authorizeUrl($state);
    }

    /**
     * Variante app mobile : le consentement s'ouvre dans le navigateur système,
     * le retour n'a pas la session de la WebView → state PORTEUR signé
     * (NativeOauthState), validé par le callback.
     */
    public static function getAuthUrlNative(int $userId): string
    {
        return self::authorizeUrl(NativeOauthState::make(static::STATE_PREFIX, $userId));
    }

    private static function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id'    => SlackApp::clientId(),
            // `user_scope` et non `scope` : cf. l'en-tête de ce fichier. `scope`
            // est volontairement ABSENT — MIA ne demande aucun jeton de bot.
            'user_scope'   => implode(',', static::SCOPES),
            'redirect_uri' => static::redirectUri(),
            'state'        => $state,
        ]);
    }

    /**
     * Échange du code pour le flux natif : le state a déjà été validé par
     * `NativeOauthState::consume()` (signature + TTL + usage unique).
     */
    public static function handleCallbackNative(string $code): ?array
    {
        return $code !== '' ? static::exchangeCodeForTokens($code) : null;
    }

    /**
     * Échange le code contre les jetons + l'identité du compte connecté.
     * Null si state invalide ou échange refusé.
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:string,email:?string}|null
     */
    public static function handleCallback(string $code, string $state): ?array
    {
        if ($state === '' || $state !== Session::get(static::SESSION_KEY)) {
            return null;
        }
        Session::remove(static::SESSION_KEY);

        return $code !== '' ? static::exchangeCodeForTokens($code) : null;
    }

    /** Échange code → jetons + identité (commun aux flux web et natif). */
    private static function exchangeCodeForTokens(string $code): ?array
    {
        $resp = self::httpPost([
            'code'          => $code,
            'client_id'     => SlackApp::clientId(),
            'client_secret' => SlackApp::clientSecret(),
            'redirect_uri'  => static::redirectUri(),
        ]);

        $jetons = self::userTokensFromResponse($resp);
        if ($jetons === null) {
            // `ok:false` OU `authed_user.access_token` absent (scopes passés
            // dans `scope` au lieu de `user_scope` : Slack rend alors un jeton
            // de bot, inutilisable ici).
            error_log('[' . static::LOG_LABEL . '] échange de code refusé : '
                . (self::errorCode($resp) !== '' ? self::errorCode($resp) : 'jeton utilisateur absent'));
            return null;
        }

        return $jetons + ['email' => self::fetchIdentity($jetons['access_token'])];
    }

    /**
     * Rafraîchit le jeton utilisateur.
     *
     * Deux régimes, distingués par la FORME du jeton durable stocké :
     *   - `xoxe-…` : refresh token de la rotation Slack → véritable appel
     *     `oauth.v2.access` (`grant_type=refresh_token`). Slack renvoie un
     *     NOUVEAU refresh token, que `SlackUserApiService::afterRefresh()`
     *     persiste — sans quoi la connexion mourrait en 12 h, en silence.
     *   - `xoxp-…` : jeton utilisateur durable (rotation désactivée, le cas
     *     courant) → rendu tel quel, sans appel réseau. Le `scope`, lui, reste
     *     vide : Slack ne le redit qu'à la connexion, et prendre ce silence
     *     pour un retrait ferait perdre ses outils à une connexion valide
     *     (`syncGrantedScope` ignore une valeur vide, exprès).
     *
     * $revoked = true si Slack invalide le jeton (autorisation retirée,
     * application désinstallée de l'espace de travail) → reconnexion.
     */
    public static function refreshAccessToken(string $refreshToken, bool &$revoked = false): ?array
    {
        $revoked = false;
        if (!self::isRotating($refreshToken)) {
            return [
                'access_token'  => $refreshToken,
                'expires_in'    => self::NO_ROTATION_TTL,
                'scope'         => '',
                'refresh_token' => null,
            ];
        }

        $resp = self::httpPost([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => SlackApp::clientId(),
            'client_secret' => SlackApp::clientSecret(),
        ]);
        $jetons = self::userTokensFromResponse($resp);
        if ($jetons !== null) {
            return [
                'access_token'  => $jetons['access_token'],
                'expires_in'    => $jetons['expires_in'],
                'scope'         => $jetons['scope'],
                'refresh_token' => $jetons['refresh_token'] !== '' ? $jetons['refresh_token'] : null,
            ];
        }

        $error = self::errorCode($resp);
        if (self::isRevocation($error)) {
            $revoked = true;
        }
        error_log('[' . static::LOG_LABEL . '] refresh échoué : ' . ($error !== '' ? $error : 'réseau/inconnu'));
        return null;
    }

    /**
     * Révocation à la déconnexion. Slack l'expose vraiment (`auth.revoke`) :
     * retirer la connexion dans MIA retire aussi l'autorisation côté Slack,
     * contrairement à Microsoft où la méthode est un no-op assumé.
     * Best-effort : l'échec ne doit pas empêcher la suppression en base.
     */
    public static function revoke(string $token): void
    {
        if ($token === '') {
            return;
        }
        // Un refresh token de rotation ne se révoque pas : c'est le jeton
        // d'ACCÈS que `auth.revoke` attend. Sans rotation, le jeton durable EST
        // le jeton d'accès, et c'est le cas courant.
        if (self::isRotating($token)) {
            return;
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Authorization: Bearer ' . $token,
            'content'       => '',
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);
        @file_get_contents(self::REVOKE_URL, false, $ctx);
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /**
     * Le jeton durable est-il un refresh token de ROTATION ? Slack préfixe ses
     * refresh tokens par `xoxe-` et ses jetons utilisateur par `xoxp-` (ou
     * `xoxe.xoxp-` quand la rotation est active). C'est cette forme — et non un
     * drapeau en base — qui décide du régime : une application dont
     * l'administrateur active la rotation après coup reconnecte ses
     * utilisateurs, et chaque ligne porte alors le bon type sans migration.
     */
    public static function isRotating(string $token): bool
    {
        return str_starts_with(trim($token), 'xoxe-');
    }

    /**
     * Jetons UTILISATEUR d'une réponse `oauth.v2.access`, null si la réponse
     * n'en porte pas.
     *
     * Slack range ces champs à DEUX endroits selon l'appel : sous `authed_user`
     * pour un échange de code, à la RACINE pour un rafraîchissement. Lire un
     * seul des deux donnait un connecteur qui se connecte puis meurt à la
     * première expiration. Le jeton durable renvoyé est le refresh token s'il
     * existe (rotation active), sinon le jeton d'accès lui-même.
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:string}|null
     */
    public static function userTokensFromResponse(array $resp): ?array
    {
        if (($resp['ok'] ?? false) !== true) {
            return null;
        }
        $user = is_array($resp['authed_user'] ?? null) ? $resp['authed_user'] : [];
        $bloc = ($user['access_token'] ?? '') !== '' ? $user : $resp;

        $acces = trim((string)($bloc['access_token'] ?? ''));
        if ($acces === '') {
            return null;
        }
        $refresh = trim((string)($bloc['refresh_token'] ?? ''));

        return [
            'access_token'  => $acces,
            'refresh_token' => $refresh !== '' ? $refresh : $acces,
            'expires_in'    => (int)($bloc['expires_in'] ?? self::NO_ROTATION_TTL),
            'scope'         => trim((string)($bloc['scope'] ?? '')),
        ];
    }

    /** Code d'erreur Slack d'une réponse, '' si la réponse est un succès. */
    public static function errorCode(array $resp): string
    {
        return ($resp['ok'] ?? false) === true ? '' : trim((string)($resp['error'] ?? ''));
    }

    /**
     * L'erreur signifie-t-elle que le jeton ne vaut plus rien ? Seuls ces
     * codes valent reconnexion : un `ratelimited` ou un `internal_error`
     * marqués « révoqués » couperaient une connexion parfaitement valide.
     */
    public static function isRevocation(string $error): bool
    {
        return in_array($error, [
            'invalid_grant', 'invalid_refresh_token', 'token_revoked', 'token_expired',
            'invalid_auth', 'account_inactive', 'not_authed',
        ], true);
    }

    /**
     * Libellé du compte connecté, affiché sur la page Connexions : « @alice ·
     * Acme » plutôt qu'une adresse — Slack n'expose pas l'email sans le scope
     * `users:read.email`, que MIA ne demande pas. Pur.
     */
    public static function accountLabel(array $authTest): string
    {
        $user = trim((string)($authTest['user'] ?? ''));
        $team = trim((string)($authTest['team'] ?? ''));
        if ($user === '' && $team === '') {
            return '';
        }
        $nom = $user !== '' ? '@' . ltrim($user, '@') : '';
        return $team !== '' ? trim($nom . ' · ' . $team, ' ·') : $nom;
    }

    // ---- Réseau --------------------------------------------------------------

    /** Identité du compte connecté (`auth.test`), null si indisponible. */
    private static function fetchIdentity(string $accessToken): ?string
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Authorization: Bearer ' . $accessToken,
            'content'       => '',
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $json = @file_get_contents(self::IDENTITY_URL, false, $ctx);
        if (!$json) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            return null;
        }
        $label = self::accountLabel($data);
        return $label !== '' ? $label : null;
    }

    /** POST form-urlencoded vers `oauth.v2.access`. */
    private static function httpPost(array $data): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query($data),
            'timeout'       => 20,
            'ignore_errors' => true, // lire le corps d'erreur ({"ok":false,…})
        ]]);
        $json = @file_get_contents(self::TOKEN_URL, false, $ctx);
        return $json ? (json_decode($json, true) ?? []) : [];
    }
}
