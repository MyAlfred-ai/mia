<?php

declare(strict_types=1);

/**
 * OAuth Microsoft PAR UTILISATEUR (plateforme d'identités Microsoft v2.0) —
 * connexion du OneDrive personnel / professionnel, utilisée comme source des
 * bases de connaissances.
 *
 * À NE PAS CONFONDRE avec SharePointProvider : celui-ci s'authentifie
 * « client credentials » (application Azure agissant SANS utilisateur, avec
 * une permission d'application Files.Read.All consentie par un admin) et ne
 * peut lire qu'un site/dossier explicitement désigné. Ici, l'application agit
 * AU NOM de l'utilisateur : elle voit son OneDrive comme lui, ce qui est la
 * seule façon de lui faire NAVIGUER dans ses propres dossiers.
 *
 * ── QUELLE APPLICATION AZURE ? ─────────────────────────────────────────────
 * Deux possibilités, et l'inscription DÉDIÉE l'emporte dès que son ID
 * d'application est saisi (Capacités → Sources synchronisées, réglages
 * `microsoft_*`, secret chiffré) :
 *   - inscription DÉDIÉE : URI de redirection propre
 *     (/settings/onedrive/callback). Utile pour cantonner Files.Read.All à
 *     une application à part, ou viser un autre annuaire que la connexion ;
 *   - à défaut, l'application du LOGIN Microsoft (`SsoRegistry`,
 *     Organisation → Authentification), comme Outlook, le calendrier Outlook,
 *     To Do et Teams : URI du login (/auth/callback, state `onedrive_` routé
 *     par AuthController::callback()), aucune inscription de plus — il suffit
 *     d'y déclarer Files.Read.All (l'écran /admin/sso le liste alors).
 * L'ID d'application dédié suffit à choisir, même sans secret : une
 * inscription à moitié saisie se signale comme incomplète, elle ne bascule
 * jamais en silence sur une autre application. Changer d'application impose
 * de reconnecter OneDrive : un jeton de rafraîchissement n'est valable que
 * pour l'application qui l'a émis (le rafraîchissement échoue, la connexion
 * passe « à reconnecter »).
 *
 * Microsoft renvoie un NOUVEAU refresh token à chaque rafraîchissement
 * (jetons « roulants »). Ne pas le persister condamnerait la connexion à
 * expirer silencieusement — cf. UserOneDriveService::getValidAccessToken.
 *
 * Logs : statut HTTP et code AADSTS uniquement — JAMAIS un jeton.
 */
class MicrosoftOAuth
{
    private const LOGIN_BASE = 'https://login.microsoftonline.com';
    private const GRAPH_ME   = 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,displayName';

    /** Clés Settings — application Azure (secret chiffré). */
    public const SETTING_TENANT = 'microsoft_tenant_id';
    public const SETTING_CLIENT = 'microsoft_client_id';
    public const SETTING_SECRET = 'microsoft_client_secret_enc';

    /** Tenant par défaut : comptes professionnels ET personnels. */
    public const DEFAULT_TENANT = 'common';

    /**
     * Permissions DÉLÉGUÉES. Files.Read.All (et non Files.Read) couvre aussi
     * « Partagés avec moi » et les bibliothèques SharePoint accessibles à
     * l'utilisateur. offline_access est ce qui produit le refresh token.
     */
    public const SCOPES = [
        'offline_access',
        'Files.Read.All',
        'User.Read',
    ];

    /**
     * Préfixe de state : AuthController::callback() route `onedrive_` vers le
     * callback OneDrive quand l'application du login est retenue.
     */
    public const STATE_PREFIX = 'onedrive_';

    /** Cause lisible du dernier échange de code refusé (cf. lastError()). */
    private static ?string $lastError = null;

    /**
     * L'inscription DÉDIÉE est-elle retenue ? Oui dès que son ID d'application
     * est saisi — même sans secret (elle est alors incomplète, et
     * isConfigured() le dit) : jamais de bascule silencieuse.
     */
    public static function usesDedicatedApp(): bool
    {
        return trim((string)Settings::get(self::SETTING_CLIENT, '')) !== '';
    }

    /** OneDrive passe-t-il par l'application du LOGIN Microsoft ? */
    public static function usesLoginApp(): bool
    {
        return !self::usesDedicatedApp();
    }

    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function tenant(): string
    {
        if (self::usesLoginApp()) {
            return SsoRegistry::microsoftTenant();
        }
        $t = trim((string)Settings::get(self::SETTING_TENANT, ''));
        return $t !== '' ? $t : self::DEFAULT_TENANT;
    }

    public static function clientId(): string
    {
        return self::usesLoginApp()
            ? SsoRegistry::microsoftClientId()
            : trim((string)Settings::get(self::SETTING_CLIENT, ''));
    }

    /** Secret déchiffré ; chaîne vide si absent OU illisible (clé de chiffrement changée). */
    public static function clientSecret(): string
    {
        try {
            if (self::usesLoginApp()) {
                return SsoRegistry::microsoftClientSecret();
            }
            $enc = (string)Settings::get(self::SETTING_SECRET, '');
            return $enc === '' ? '' : Encryption::decrypt($enc);
        } catch (\Throwable $e) {
            error_log('[Microsoft OAuth] secret client illisible : ' . $e->getMessage());
            return '';
        }
    }

    /**
     * URI de redirection de l'application retenue : la dédiée, sinon celle du
     * login (state `onedrive_` routé par AuthController::callback()).
     */
    public static function redirectUri(): string
    {
        return self::usesLoginApp() ? SsoRegistry::redirectUri() : self::dedicatedRedirectUri();
    }

    /**
     * URI à déclarer sur l'inscription DÉDIÉE (affichée dans sa marche à
     * suivre, page « Sources synchronisées »). La changer casse les connexions.
     */
    public static function dedicatedRedirectUri(): string
    {
        return rtrim(APP_URL, '/') . '/settings/onedrive/callback';
    }

    /**
     * Cause lisible du dernier échange de code refusé (secret invalide ou
     * expiré, URI non déclarée, jeton de rafraîchissement absent…), null si
     * elle n'est pas connue — l'appelant affiche alors son message générique.
     * Sans elle, un AADSTS7000215 renvoyé par le endpoint token ne laissait
     * qu'une ligne de log et « Échec de la connexion OneDrive ».
     */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Réponse du endpoint token (échange de code) refusée → message lisible,
     * null si rien d'exploitable. Pur, testable ; partagé avec le socle des
     * connecteurs Microsoft. Le corps brut n'est jamais recopié.
     */
    public static function exchangeFailureMessage(array $token, ?string $redirectUri = null): ?string
    {
        $desc = (string)($token['error_description'] ?? '') . ' ' . (string)($token['error'] ?? '');
        if (str_contains($desc, 'AADSTS')) {
            return self::authErrorMessage($desc, $redirectUri);
        }
        if (!empty($token['access_token']) && empty($token['refresh_token'])) {
            return 'Microsoft n\'a pas délivré de jeton de rafraîchissement : la permission offline_access '
                . 'n\'est pas accordée à l\'application Azure. Ajoutez-la (Autorisations d\'API → Microsoft '
                . 'Graph → permissions déléguées) puis reconnectez-vous.';
        }
        return null;
    }

    public static function getAuthUrl(): string
    {
        $state = self::STATE_PREFIX . bin2hex(random_bytes(16));
        Session::set('onedrive_oauth_state', $state);

        return self::LOGIN_BASE . '/' . rawurlencode(self::tenant()) . '/oauth2/v2.0/authorize?'
            . http_build_query([
                'client_id'     => self::clientId(),
                'redirect_uri'  => self::redirectUri(),
                'response_type' => 'code',
                'response_mode' => 'query',
                'scope'         => implode(' ', self::SCOPES),
                'state'         => $state,
                // Jamais `consent` : cf. MicrosoftUserOAuth::PROMPT.
                'prompt'        => 'select_account',
            ]);
    }

    /**
     * Variante app mobile : le consentement s'ouvre dans le navigateur
     * système, le retour n'a pas la session WebView → state PORTEUR signé
     * (NativeOauthState), validé par le callback, pas de state en session.
     */
    public static function getAuthUrlNative(int $userId): string
    {
        return self::LOGIN_BASE . '/' . rawurlencode(self::tenant()) . '/oauth2/v2.0/authorize?'
            . http_build_query([
                'client_id'     => self::clientId(),
                'redirect_uri'  => self::redirectUri(),
                'response_type' => 'code',
                'response_mode' => 'query',
                'scope'         => implode(' ', self::SCOPES),
                'state'         => NativeOauthState::make(self::STATE_PREFIX, $userId),
                // Jamais `consent` : cf. MicrosoftUserOAuth::PROMPT.
                'prompt'        => 'select_account',
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
        return $code !== '' ? self::exchangeCodeForTokens($code) : null;
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
        if ($state === '' || $state !== Session::get('onedrive_oauth_state')) {
            return null;
        }
        Session::remove('onedrive_oauth_state');

        return $code !== '' ? self::exchangeCodeForTokens($code) : null;
    }

    /** Échange code → jetons + adresse (commun aux flux web et natif). */
    private static function exchangeCodeForTokens(string $code): ?array
    {
        $token = self::httpPost([
            'code'          => $code,
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
            'scope'         => implode(' ', self::SCOPES),
        ]);
        if (empty($token['access_token']) || empty($token['refresh_token'])) {
            self::$lastError = self::exchangeFailureMessage($token, self::redirectUri());
            error_log('[Microsoft OAuth] échange de code refusé : '
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
     * Rafraîchit l'access token. Retourne {access_token, expires_in,
     * refresh_token} — le refresh token renvoyé REMPLACE l'ancien (jetons
     * roulants Microsoft) ; il peut être absent, auquel cas l'ancien reste
     * valable. $revoked = true si Microsoft répond invalid_grant (consentement
     * retiré, mot de passe changé, jeton trop ancien) → reconnexion.
     */
    public static function refreshAccessToken(string $refreshToken, bool &$revoked = false): ?array
    {
        $revoked = false;
        $resp = self::httpPost([
            'refresh_token' => $refreshToken,
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'grant_type'    => 'refresh_token',
            'scope'         => implode(' ', self::SCOPES),
        ]);
        if (!empty($resp['access_token'])) {
            return [
                'access_token'  => (string)$resp['access_token'],
                'expires_in'    => (int)($resp['expires_in'] ?? 3600),
                // Entra redit les permissions du jeton à chaque
                // rafraîchissement : rendu ici pour que tout appelant puisse
                // aligner ce qu'il croit accordé (cf. MicrosoftUserOAuth).
                'scope'         => (string)($resp['scope'] ?? ''),
                'refresh_token' => isset($resp['refresh_token']) ? (string)$resp['refresh_token'] : null,
            ];
        }
        $error = (string)($resp['error'] ?? '');
        if ($error === 'invalid_grant' || $error === 'interaction_required') {
            $revoked = true;
        }
        error_log('[Microsoft OAuth] refresh échoué : ' . ($error !== '' ? $error : 'réseau/inconnu'));
        return null;
    }

    /**
     * Corps d'erreur Entra (endpoint token OU `error_description` du retour
     * d'autorisation) → message utilisateur FR (pur, testable). Les codes
     * AADSTS nomment la cause ; le corps brut n'est jamais recopié dans le
     * message rendu à l'écran.
     *
     * $redirectUri : l'URI à déclarer dans Azure, citée par le message
     * AADSTS50011. Elle diffère selon le flux (OneDrive peut avoir la sienne,
     * les connecteurs Microsoft réutilisent celle du login) — d'où le
     * paramètre plutôt qu'un troisième traducteur AADSTS recopié.
     *
     * ── POURQUOI CETTE LISTE EST LONGUE ────────────────────────────────────
     * Un refus Entra ne vient pas seulement d'un identifiant mal recopié. Dans
     * un annuaire d'entreprise, les trois causes les plus fréquentes n'ont RIEN
     * à voir avec les identifiants, et le message générique « vérifiez les
     * identifiants de l'application Azure » envoyait chercher au mauvais
     * endroit — parfois pendant des heures :
     *   - le consentement utilisateur est interdit par l'annuaire, un
     *     administrateur doit accorder les permissions (65001 / 90094) ;
     *   - l'application d'entreprise exige l'attribution nominative des
     *     utilisateurs (50105) ;
     *   - l'URI de redirection est déclarée en « Application monopage (SPA) »
     *     au lieu de « Web » (9002326) — l'écran Azure propose les deux, et le
     *     mauvais choix ne se voit qu'ici.
     * Chaque code retenu ci-dessous nomme un REMÈDE DIFFÉRENT : un code qui
     * mènerait au même geste n'aurait rien à faire dans cette liste.
     *
     * ORDRE : du plus spécifique au plus général. Le filet `redirect_uri` est
     * une sous-chaîne libre, présente dans plusieurs descriptions — il reste
     * donc EN DERNIER, sinon il attraperait les codes précis avant eux.
     */
    public static function authErrorMessage(string $body, ?string $redirectUri = null): string
    {
        // ---- Identité de l'application (ce que l'administrateur a saisi) ----
        if (str_contains($body, 'AADSTS90002')) {
            return 'Annuaire (tenant) Microsoft introuvable — vérifiez l\'ID d\'annuaire de l\'application Azure.';
        }
        if (str_contains($body, 'AADSTS700016') || str_contains($body, 'unauthorized_client')) {
            return 'Application Microsoft inconnue de cet annuaire — vérifiez l\'ID d\'application (client).';
        }
        if (str_contains($body, 'AADSTS7000215')) {
            return 'Secret client Microsoft invalide — collez la VALEUR du secret (pas son ID).';
        }
        if (str_contains($body, 'AADSTS7000222')) {
            return 'Secret client Microsoft expiré — créez-en un nouveau dans le portail Azure.';
        }
        if (str_contains($body, 'AADSTS7000218')) {
            return 'Secret client Microsoft absent de la demande — l\'application Azure est déclarée comme '
                . 'client PUBLIC. Dans le portail Entra (Authentification), déclarez l\'URI de redirection '
                . 'sous la plateforme « Web » et créez un secret client.';
        }

        // ---- Consentement et attribution (rien à voir avec les identifiants) ----
        if (str_contains($body, 'AADSTS65001') || str_contains($body, 'AADSTS90094')
            || str_contains($body, 'consent_required')) {
            return 'Permissions non consenties dans cet annuaire Microsoft — votre organisation n\'autorise '
                . 'pas les utilisateurs à consentir eux-mêmes. Un administrateur doit accorder le '
                . 'consentement pour l\'application (portail Entra → Applications d\'entreprise → '
                . 'Autorisations → « Accorder le consentement administrateur »).';
        }
        if (str_contains($body, 'AADSTS65004')) {
            return 'Consentement refusé sur l\'écran Microsoft — les permissions demandées sont nécessaires '
                . 'au connecteur. Relancez la connexion et acceptez, ou demandez à un administrateur de '
                . 'consentir pour l\'organisation.';
        }
        if (str_contains($body, 'AADSTS50105')) {
            return 'Ce compte n\'est pas autorisé à utiliser l\'application Microsoft — l\'application '
                . 'd\'entreprise exige l\'attribution nominative des utilisateurs. Un administrateur doit '
                . 'l\'ajouter (portail Entra → Applications d\'entreprise → Utilisateurs et groupes), ou '
                . 'passer « Attribution requise » à Non.';
        }
        if (str_contains($body, 'AADSTS50020') || str_contains($body, 'AADSTS500200')) {
            return 'Ce compte Microsoft n\'appartient pas à l\'annuaire configuré (compte personnel ou '
                . 'invité d\'une autre organisation). Connectez-vous avec un compte de l\'organisation, ou '
                . 'faites élargir l\'annuaire de l\'application Azure.';
        }

        // ---- Déclaration de l'application dans le portail ----
        if (str_contains($body, 'AADSTS9002326')) {
            return 'URI de redirection déclarée en « Application monopage (SPA) » dans le portail Entra — '
                . 'elle doit l\'être sous la plateforme « Web ». Supprimez-la de la section SPA et '
                . 'ajoutez ' . ($redirectUri ?? self::redirectUri()) . ' sous « Web ».';
        }
        if (str_contains($body, 'AADSTS500011')) {
            return 'Service Microsoft Graph introuvable dans cet annuaire — vérifiez les permissions '
                . 'déclarées pour l\'application (portail Entra → Autorisations d\'API → Microsoft Graph, '
                . 'permissions DÉLÉGUÉES).';
        }
        if (str_contains($body, 'AADSTS650053')) {
            return 'Permission Microsoft inconnue ou mal orthographiée — vérifiez les autorisations d\'API '
                . 'déclarées pour l\'application (Microsoft Graph, permissions déléguées).';
        }

        // Filet le plus large EN DERNIER : « redirect_uri » apparaît dans
        // plusieurs descriptions Entra, il masquerait les codes ci-dessus.
        if (str_contains($body, 'AADSTS50011') || str_contains($body, 'redirect_uri')) {
            return 'URI de redirection non déclarée dans l\'application Azure — ajoutez '
                . ($redirectUri ?? self::redirectUri()) . ' (plateforme « Web ») puis réessayez.';
        }
        return 'Connexion Microsoft refusée — vérifiez les identifiants de l\'application Azure.';
    }

    /**
     * Le refus tient-il au CONSENTEMENT ou à l'ATTRIBUTION de l'utilisateur,
     * plutôt qu'à la configuration de l'application ? (pur, testable)
     *
     * Sert au flux de CONNEXION, où le message détaillé ne peut pas voyager
     * dans l'URL (il serait ré-affiché tel quel) : la page de connexion n'a
     * qu'un code d'erreur, il doit au moins désigner le bon interlocuteur —
     * l'administrateur de l'annuaire, pas celui de MIA.
     */
    public static function isConsentOrAssignmentError(string $body): bool
    {
        foreach (['AADSTS65001', 'AADSTS90094', 'AADSTS65004', 'AADSTS50105', 'consent_required'] as $code) {
            if (str_contains($body, $code)) {
                return true;
            }
        }
        return false;
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

    /** POST form-urlencoded vers le endpoint token du tenant courant. */
    private static function httpPost(array $data): array
    {
        $url = self::LOGIN_BASE . '/' . rawurlencode(self::tenant()) . '/oauth2/v2.0/token';
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
