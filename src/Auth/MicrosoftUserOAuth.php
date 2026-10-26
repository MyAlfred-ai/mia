<?php

declare(strict_types=1);

/**
 * Socle des flux OAuth MICROSOFT par utilisateur (connecteurs personnels :
 * Outlook, et les suivants). Frère de GoogleUserOAuth : un connecteur = une
 * sous-classe DÉCLARATIVE qui ne porte que ses scopes, son préfixe de state,
 * sa clé de session et son étiquette de log — toute la mécanique (endpoints
 * Entra, échange de code, refresh, flux natif mobile) vit ICI, en un seul
 * exemplaire.
 *
 * ── QUELLE APPLICATION AZURE ? ─────────────────────────────────────────────
 * Celle du LOGIN Microsoft (`SsoRegistry`, Administration → Organisation →
 * Authentification), exactement comme les connecteurs Google réutilisent le
 * client OAuth du login Google. Conséquences voulues :
 *   - l'URI de redirection est CELLE DU LOGIN (`/auth/callback`), déjà
 *     déclarée dès que la connexion Microsoft est configurée : AUCUNE URI
 *     supplémentaire à déclarer dans le portail Azure ;
 *   - les identifiants vivent en base, donc `app` et `worker` voient la même
 *     valeur (le chat s'exécutant dans le worker, des identifiants venus de
 *     l'environnement y auraient été absents — panne muette déjà payée côté
 *     Google) ;
 *   - **la connexion Microsoft n'a pas besoin d'être ACTIVÉE** pour que les
 *     connecteurs fonctionnent : `isConfigured()` ne regarde que les
 *     identifiants. Une organisation sous login Google peut donc renseigner
 *     l'application Azure et laisser le bouton de connexion Microsoft éteint.
 *
 * À NE PAS CONFONDRE avec `SharePointProvider` (client credentials
 * applicatif, synchronisation de bases). `MicrosoftOAuth` (OneDrive
 * personnel) peut avoir sa PROPRE inscription (réglages `microsoft_*`, URI
 * `/settings/onedrive/callback`) et retombe sinon sur cette même application
 * du login ; ses scopes restent distincts — chaque connecteur demande son
 * propre consentement, personne ne se voit demander les fichiers pour lire
 * ses mails.
 *
 * Le state est préfixé (STATE_PREFIX) pour que AuthController::callback()
 * distingue un retour connecteur d'un retour login et route vers le bon
 * callback de UserSettingsController.
 *
 * `offline_access` produit le refresh token. Les scopes réellement accordés
 * sont stockés (colonne `scope`) et re-vérifiés par le service du connecteur :
 * un consentement partiel donne une connexion dégradée (lecture seule…)
 * plutôt qu'un connecteur cassé.
 *
 * Logs : statut HTTP et code AADSTS uniquement — JAMAIS un jeton.
 */
abstract class MicrosoftUserOAuth
{
    private const LOGIN_BASE = 'https://login.microsoftonline.com';
    private const GRAPH_ME   = 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,displayName';

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

    /** Étiquette des logs (« Outlook OAuth »…). */
    protected const LOG_LABEL = '';

    /**
     * Cause lisible du dernier échange de code refusé (cf. lastError()).
     * Statique du SOCLE, partagée par les sous-classes : une requête ne traite
     * qu'un retour de consentement.
     */
    private static ?string $lastError = null;

    /**
     * Valeur du paramètre `prompt` de l'autorisation : `select_account`, comme
     * la connexion Microsoft. JAMAIS `consent`.
     *
     * `prompt=consent` force l'écran de consentement à CHAQUE liaison. Or
     * Entra bloque cet écran pour toute personne qui n'a pas le droit de
     * consentir elle-même (réglage par défaut de Microsoft, consentement
     * utilisateur interdit, « Affectation requise ») : elle voit « Approbation
     * de l'administrateur requise » (AADSTS90094) MÊME quand un administrateur
     * a déjà consenti pour toute l'organisation. Constaté chez un client : comptes
     * reliés à MIA, droits accordés, mais ni Outlook ni le calendrier reliables
     * par un utilisateur ordinaire.
     *
     * Rien n'exige ce forçage chez Microsoft : le jeton de rafraîchissement
     * vient du scope `offline_access`, pas de l'écran ; et un scope pas encore
     * accordé (élargissement de périmètre) fait apparaître l'écran de lui-même
     * quand la personne peut consentir, ou l'erreur de consentement qui le dit
     * quand elle ne le peut pas. `select_account` laisse choisir le compte
     * (plusieurs comptes ouverts dans le navigateur) sans rien forcer d'autre.
     *
     * Un connecteur peut encore surcharger cette constante ; aucun ne doit y
     * remettre `consent`.
     */
    protected const PROMPT = 'select_account';

    // ---- Mécanique commune ---------------------------------------------------

    /**
     * Prérequis technique : les identifiants de l'application Azure du login.
     * Volontairement indépendant de l'ACTIVATION de la connexion Microsoft —
     * cf. l'en-tête de ce fichier.
     */
    public static function isConfigured(): bool
    {
        return SsoRegistry::isConfigured(SsoRegistry::MICROSOFT);
    }

    /** URI de redirection = celle du LOGIN (aucune URI dédiée à déclarer). */
    public static function redirectUri(): string
    {
        return SsoRegistry::redirectUri();
    }

    /** Annuaire (tenant) de l'application Azure du login. */
    public static function tenant(): string
    {
        return SsoRegistry::microsoftTenant();
    }

    public static function getAuthUrl(): string
    {
        // Préfixe : permet à /auth/callback de reconnaître ce retour.
        $state = static::STATE_PREFIX . bin2hex(random_bytes(16));
        Session::set(static::SESSION_KEY, $state);

        return self::authorizeUrl($state);
    }

    /**
     * Variante app mobile : le consentement s'ouvre dans le navigateur
     * système, le retour n'a pas la session WebView → state PORTEUR signé
     * (NativeOauthState), validé par le callback, pas de state en session.
     */
    public static function getAuthUrlNative(int $userId): string
    {
        return self::authorizeUrl(NativeOauthState::make(static::STATE_PREFIX, $userId));
    }

    private static function authorizeUrl(string $state): string
    {
        return self::LOGIN_BASE . '/' . rawurlencode(static::tenant()) . '/oauth2/v2.0/authorize?'
            . http_build_query([
                'client_id'     => SsoRegistry::microsoftClientId(),
                'redirect_uri'  => static::redirectUri(),
                'response_type' => 'code',
                'response_mode' => 'query',
                'scope'         => implode(' ', static::SCOPES),
                'state'         => $state,
                // Cf. PROMPT : jamais `consent`, qui bloquerait un
                // utilisateur sans droit de consentir.
                'prompt'        => static::PROMPT,
            ]);
    }

    /**
     * Échange du code pour le flux natif : le state a déjà été validé par
     * NativeOauthState::consume() (signature + TTL + usage unique) — aucune
     * session disponible ici.
     */
    public static function handleCallbackNative(string $code): ?array
    {
        self::$lastError = null;
        return $code !== '' ? static::exchangeCodeForTokens($code) : null;
    }

    /**
     * Échange le code contre les jetons + l'adresse du compte connecté.
     * Null si state invalide, échange refusé ou refresh token absent (scope
     * offline_access non accordé).
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:string,email:?string}|null
     */
    public static function handleCallback(string $code, string $state): ?array
    {
        self::$lastError = null;
        if ($state === '' || $state !== Session::get(static::SESSION_KEY)) {
            return null;
        }
        Session::remove(static::SESSION_KEY);

        return $code !== '' ? static::exchangeCodeForTokens($code) : null;
    }

    /** Échange code → jetons + adresse (commun aux flux web et natif). */
    private static function exchangeCodeForTokens(string $code): ?array
    {
        $token = self::httpPost([
            'code'          => $code,
            'client_id'     => SsoRegistry::microsoftClientId(),
            'client_secret' => SsoRegistry::microsoftClientSecret(),
            'redirect_uri'  => static::redirectUri(),
            'grant_type'    => 'authorization_code',
            'scope'         => implode(' ', static::SCOPES),
        ]);
        if (empty($token['access_token']) || empty($token['refresh_token'])) {
            self::$lastError = MicrosoftOAuth::exchangeFailureMessage($token, static::redirectUri());
            error_log('[' . static::LOG_LABEL . '] échange de code refusé : '
                . (string)($token['error'] ?? 'refresh_token absent'));
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
     * Cause lisible du dernier échange de code refusé (secret invalide ou
     * expiré, URI non déclarée…), null si inconnue : l'appelant garde alors
     * son message générique. Cf. MicrosoftOAuth::exchangeFailureMessage().
     */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Rafraîchit l'access token. Retourne {access_token, expires_in, scope,
     * refresh_token} — le refresh token renvoyé REMPLACE l'ancien (jetons
     * roulants Microsoft) ; il peut être absent, auquel cas l'ancien reste
     * valable. C'est MicrosoftUserApiService::afterRefresh() qui le persiste.
     * $revoked = true si Microsoft répond invalid_grant (consentement retiré,
     * mot de passe changé, jeton trop ancien) → reconnexion.
     *
     * `scope` N'EST PAS DÉCORATIF : le endpoint token d'Entra redit à chaque
     * rafraîchissement les permissions réellement portées par le jeton, et
     * c'est ConnectorApiService::syncGrantedScope() qui les persiste. L'omettre
     * figeait les autorisations au jour de la connexion — exactement la panne
     * muette déjà payée côté Google. Le cas est ORDINAIRE dans un annuaire
     * Entra : le consentement utilisateur y est souvent interdit, un
     * administrateur accorde `Mail.ReadWrite` APRÈS coup, et la connexion
     * restait en lecture seule pour toujours (badge « Brouillons — », outil non
     * exposé au modèle, aucune erreur nulle part).
     */
    public static function refreshAccessToken(string $refreshToken, bool &$revoked = false): ?array
    {
        $revoked = false;
        $resp = self::httpPost([
            'refresh_token' => $refreshToken,
            'client_id'     => SsoRegistry::microsoftClientId(),
            'client_secret' => SsoRegistry::microsoftClientSecret(),
            'grant_type'    => 'refresh_token',
            'scope'         => implode(' ', static::SCOPES),
        ]);
        if (!empty($resp['access_token'])) {
            return [
                'access_token'  => (string)$resp['access_token'],
                'expires_in'    => (int)($resp['expires_in'] ?? 3600),
                'scope'         => (string)($resp['scope'] ?? ''),
                'refresh_token' => isset($resp['refresh_token']) ? (string)$resp['refresh_token'] : null,
            ];
        }
        $error = (string)($resp['error'] ?? '');
        if ($error === 'invalid_grant' || $error === 'interaction_required') {
            $revoked = true;
        }
        error_log('[' . static::LOG_LABEL . '] refresh échoué : ' . ($error !== '' ? $error : 'réseau/inconnu'));
        return null;
    }

    /**
     * Révocation à la déconnexion : Microsoft n'expose AUCUN endpoint de
     * révocation d'un jeton délégué (contrairement à Google). Le retrait de
     * l'autorisation se fait par l'utilisateur sur myaccount.microsoft.com.
     * Méthode conservée — le socle de déconnexion des connecteurs l'appelle
     * pour tous les fournisseurs — et volontairement inopérante : c'est la
     * suppression du jeton en base qui coupe l'accès de MIA.
     */
    public static function revoke(string $token): void
    {
    }

    /**
     * Corps d'erreur AADSTS → message FR. Délègue au traducteur unique
     * (MicrosoftOAuth) en lui donnant l'URI de CE flux : le message
     * AADSTS50011 doit nommer l'URI à déclarer, et elle diffère de celle de
     * OneDrive.
     */
    public static function authErrorMessage(string $body): string
    {
        return MicrosoftOAuth::authErrorMessage($body, static::redirectUri());
    }

    /** Adresse du compte connecté (affichage sur la page Connexions). */
    private static function fetchEmail(string $accessToken): ?string
    {
        $ctx = stream_context_create(['http' => [
            'header'        => 'Authorization: Bearer ' . $accessToken,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $json = @file_get_contents(self::GRAPH_ME, false, $ctx);
        if (!$json) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['mail', 'userPrincipalName', 'displayName'] as $k) {
            if (!empty($data[$k])) {
                return (string)$data[$k];
            }
        }
        return null;
    }

    /** POST form-urlencoded vers le endpoint token de l'annuaire courant. */
    private static function httpPost(array $data): array
    {
        $url = self::LOGIN_BASE . '/' . rawurlencode(static::tenant()) . '/oauth2/v2.0/token';
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query($data),
            'timeout'       => 20,
            'ignore_errors' => true, // lire le corps d'erreur (AADSTS…)
        ]]);
        $json = @file_get_contents($url, false, $ctx);
        return $json ? (json_decode($json, true) ?? []) : [];
    }
}
