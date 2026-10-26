<?php

declare(strict_types=1);

/**
 * Connexion Microsoft (Entra ID, plateforme d'identités v2).
 *
 * Flux « code d'autorisation » avec client confidentiel : le code revient par
 * le navigateur, mais l'`id_token` est récupéré par NOUS, en direct, sur le
 * endpoint token de Microsoft, en TLS, contre le secret client.
 *
 * POURQUOI PAS DE VÉRIFICATION DE SIGNATURE (JWKS) : vérifier la signature
 * protège d'un jeton reçu par un canal non fiable — typiquement le flux
 * implicite, où le jeton transite par le navigateur. Ici le jeton ne vient pas
 * du navigateur : il vient d'une connexion TLS que nous avons ouverte vers
 * login.microsoftonline.com, authentifiée par notre secret. Un attaquant
 * capable d'y injecter un jeton contrôlerait déjà le canal, et une signature
 * n'y changerait rien. C'est le raisonnement déjà assumé par
 * SharePointProvider::rolesFromJwt(). Les claims, eux, sont TOUS contrôlés
 * ci-dessous (audience, émetteur, expiration, nonce, annuaire).
 *
 * Les scopes demandés sont les scopes d'identité seuls (`openid profile
 * email`) : se connecter ne donne accès à aucune donnée Microsoft. Les
 * connecteurs personnels, eux, ont leurs propres scopes et leur propre écran.
 */
final class MicrosoftSsoProvider implements SsoProvider
{
    private const LOGIN_BASE = 'https://login.microsoftonline.com';
    private const SCOPES     = 'openid profile email';

    /** Tolérance d'horloge sur l'expiration du jeton (secondes). */
    private const CLOCK_SKEW = 60;

    public const SESSION_STATE = 'ms_oauth_state';
    public const SESSION_NONCE = 'ms_oauth_nonce';

    public function key(): string
    {
        return SsoRegistry::MICROSOFT;
    }

    public function label(): string
    {
        return 'Microsoft';
    }

    public function isConfigured(): bool
    {
        return SsoRegistry::isConfigured(SsoRegistry::MICROSOFT);
    }

    public function authUrl(): string
    {
        $state = 'sso_ms_' . bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        Session::set(self::SESSION_STATE, $state);
        Session::set(self::SESSION_NONCE, $nonce);

        return self::endpoint('authorize') . '?' . http_build_query([
            'client_id'     => SsoRegistry::microsoftClientId(),
            'redirect_uri'  => SsoRegistry::redirectUri(),
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope'         => self::SCOPES,
            'state'         => $state,
            'nonce'         => $nonce,
            'prompt'        => 'select_account',
        ]);
    }

    public function handleCallback(string $code, string $state): ?array
    {
        $attendu = (string)Session::get(self::SESSION_STATE, '');
        $nonce   = (string)Session::get(self::SESSION_NONCE, '');
        if ($state === '' || $attendu === '' || !hash_equals($attendu, $state)) {
            error_log('[SSO Microsoft] state invalide');
            return null;
        }
        Session::remove(self::SESSION_STATE);
        Session::remove(self::SESSION_NONCE);

        if ($code === '') {
            return null;
        }

        $reponse = $this->httpPost(self::endpoint('token'), [
            'client_id'     => SsoRegistry::microsoftClientId(),
            'client_secret' => SsoRegistry::microsoftClientSecret(),
            'code'          => $code,
            'redirect_uri'  => SsoRegistry::redirectUri(),
            'grant_type'    => 'authorization_code',
            'scope'         => self::SCOPES,
        ]);
        if ($reponse === null) {
            error_log('[SSO Microsoft] endpoint token injoignable');
            return null;
        }
        if ($reponse['status'] >= 400) {
            // Le corps peut contenir des identifiants : seul le code AADSTS est journalisé.
            error_log('[SSO Microsoft] échange de code refusé (HTTP ' . $reponse['status'] . ') : '
                . self::aadstsCode($reponse['body']));
            return null;
        }

        $data    = json_decode($reponse['body'], true);
        $idToken = is_array($data) ? (string)($data['id_token'] ?? '') : '';
        $claims  = self::claimsFromIdToken($idToken);
        if ($claims === null) {
            error_log('[SSO Microsoft] id_token absent ou illisible');
            return null;
        }

        $identite = self::mapClaims(
            $claims,
            SsoRegistry::microsoftClientId(),
            SsoRegistry::microsoftTenant(),
            $nonce,
            time()
        );
        if ($identite === null) {
            error_log('[SSO Microsoft] claims refusés (audience, émetteur, expiration, nonce ou annuaire)');
        }
        return $identite;
    }

    // ===================== Statiques pures (testables) =====================

    /**
     * Charge utile d'un JWT, sans vérification de signature (cf. en-tête de
     * classe). Null si le jeton n'a pas la forme attendue.
     *
     * @return array<string, mixed>|null
     */
    public static function claimsFromIdToken(string $jwt): ?array
    {
        $parties = explode('.', $jwt);
        if (count($parties) !== 3 || $parties[1] === '') {
            return null;
        }
        $decode = base64_decode(strtr($parties[1], '-_', '+/'), false);
        if (!is_string($decode) || $decode === '') {
            return null;
        }
        $claims = json_decode($decode, true);
        return is_array($claims) ? $claims : null;
    }

    /**
     * Contrôle des claims et normalisation (pur, testable).
     *
     * Contrôles : audience = notre client, émetteur Microsoft, jeton non
     * expiré, nonce conforme à celui déposé, annuaire attendu quand un
     * identifiant d'annuaire précis est configuré, et présence de `oid`.
     *
     * `email_verified` demande une attention particulière. Le claim `email`
     * d'Entra est projeté depuis l'annuaire émetteur : sur un annuaire ouvert
     * (`common`), n'importe qui peut créer un tenant, y déclarer un domaine
     * NON vérifié et poser l'adresse de son choix sur un utilisateur. Prendre
     * ce claim pour argent comptant offrirait le compte de la victime.
     * L'adresse n'est donc tenue pour attestée que dans deux cas :
     *  - `xms_edov` (Email Domain Owner Verified) vaut 1 : Microsoft affirme
     *    que le domaine appartient au tenant émetteur et qu'il est vérifié.
     *    Ce claim optionnel s'active dans le portail (Configuration du jeton) ;
     *  - un annuaire PRÉCIS est configuré : ses domaines ont été vérifiés par
     *    Microsoft à l'inscription, et lui seul émet des jetons acceptés.
     *
     * @param array<string, mixed> $claims
     * @return array{provider:string,subject:string,email:string,email_verified:bool,name:string,picture:string}|null
     */
    public static function mapClaims(array $claims, string $clientId, string $tenant, string $nonce, int $now): ?array
    {
        if ($clientId === '' || ($claims['aud'] ?? '') !== $clientId) {
            return null;
        }

        $exp = (int)($claims['exp'] ?? 0);
        if ($exp <= 0 || $exp + self::CLOCK_SKEW <= $now) {
            return null;
        }

        // Le nonce n'est contrôlé que s'il en a été posé un — un flux ancien
        // resté ouvert n'en a pas, et le state suffit alors à l'apparier.
        if ($nonce !== '') {
            $recu = (string)($claims['nonce'] ?? '');
            if ($recu === '' || !hash_equals($nonce, $recu)) {
                return null;
            }
        }

        $tid = (string)($claims['tid'] ?? '');
        if ($tid === '') {
            return null;
        }

        // L'émetteur doit désigner EXACTEMENT l'annuaire du jeton. Se contenter
        // de « ça commence par login.microsoftonline.com » laisserait passer un
        // jeu de claims où `iss` et `tid` désignent deux annuaires différents —
        // et c'est `tid` qui sert ensuite de clé d'identité.
        if (!hash_equals(self::LOGIN_BASE . '/' . $tid . '/v2.0', (string)($claims['iss'] ?? ''))) {
            return null;
        }
        // Annuaire nommé par un identifiant : on exige le même. Un domaine
        // vérifié (contoso.onmicrosoft.com) ne se compare pas à un `tid`, qui
        // est toujours un identifiant — le contrôle ne s'applique donc pas.
        $annuairePrecis = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tenant) === 1;
        if ($annuairePrecis && !hash_equals($tenant, strtolower($tid))) {
            return null;
        }

        $oid = (string)($claims['oid'] ?? '');
        if ($oid === '') {
            return null;
        }

        $email = trim((string)($claims['email'] ?? ''));
        if ($email === '') {
            // Compte professionnel sans attribut `mail` : `preferred_username`
            // porte alors le nom d'utilisateur principal, généralement une
            // adresse. On ne le retient que s'il en a la forme.
            $upn = trim((string)($claims['preferred_username'] ?? ''));
            if (filter_var($upn, FILTER_VALIDATE_EMAIL) !== false) {
                $email = $upn;
            }
        }

        $nom = trim((string)($claims['name'] ?? ''));

        // Cf. en-tête de méthode : claim d'attestation, ou annuaire précis.
        $edov     = $claims['xms_edov'] ?? null;
        $atteste  = ($edov === 1 || $edov === '1' || $edov === true) || $annuairePrecis;

        return [
            'provider'       => SsoRegistry::MICROSOFT,
            // `oid` est unique dans un annuaire, pas entre annuaires : la paire
            // annuaire + objet l'est, et reste stable si l'adresse change.
            'subject'        => strtolower($tid) . '.' . strtolower($oid),
            'email'          => strtolower($email),
            'email_verified' => $atteste && $email !== '',
            'name'           => $nom !== '' ? $nom : $email,
            // Graph exige un jeton porteur pour la photo : pas d'URL publique,
            // repli sur les initiales déjà géré par l'interface.
            'picture'        => '',
        ];
    }

    /**
     * Verdict du test d'identifiants (pur, testable).
     *
     * Principe commun aux deux sondes : si le serveur d'autorisation prend la
     * peine de dire « cette DEMANDE est invalide » (code d'autorisation bidon,
     * ressource sans permission), c'est qu'il a déjà accepté le CLIENT — donc
     * l'annuaire, l'ID client et le secret sont bons. Seuls les codes qui
     * accusent nommément un identifiant valent échec ; ils sont traduits en
     * français par SharePointProvider::tokenErrorMessage(), réutilisé et jamais
     * recopié.
     *
     * @return array{ok:bool, error?:string, detail?:string}
     */
    public static function credentialVerdict(int $status, string $body): array
    {
        if ($status < 400) {
            return ['ok' => true, 'detail' => t('Annuaire, ID client et secret acceptés par Microsoft.')];
        }

        foreach (['AADSTS90002', 'AADSTS700016', 'AADSTS7000215', 'AADSTS7000222'] as $code) {
            if (str_contains($body, $code)) {
                return ['ok' => false, 'error' => SharePointProvider::tokenErrorMessage($status, $body)];
            }
        }
        // `invalid_client` sans code AADSTS reconnu : l'identité du client est
        // en cause, même si Microsoft ne dit pas lequel des deux champs.
        if (str_contains($body, '"error":"invalid_client"')) {
            return ['ok' => false, 'error' => SharePointProvider::tokenErrorMessage($status, $body)];
        }
        if (str_contains($body, 'AADSTS50011') || str_contains($body, 'redirect_uri')) {
            return [
                'ok'    => false,
                'error' => t('URI de redirection non déclarée pour cette application — ajoutez celle affichée ci-dessus dans le portail Entra (Authentification → URI de redirection, type Web).'),
            ];
        }

        return [
            'ok'     => true,
            'detail' => t('Annuaire, ID client et secret acceptés (Microsoft refuse la demande de test elle-même, ce qui est attendu).'),
        ];
    }

    public static function endpointFor(string $tenant, string $chemin): string
    {
        return self::LOGIN_BASE . '/' . rawurlencode($tenant) . '/oauth2/v2.0/' . $chemin;
    }

    public static function discoveryUrlFor(string $tenant): string
    {
        return self::LOGIN_BASE . '/' . rawurlencode($tenant) . '/v2.0/.well-known/openid-configuration';
    }

    /** Code AADSTS d'un corps d'erreur, pour journaliser sans recopier le corps. */
    public static function aadstsCode(string $body): string
    {
        return preg_match('/AADSTS\d+/', $body, $m) === 1 ? $m[0] : 'code inconnu';
    }

    // ===================== Test =====================

    public function testCredentials(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => t('Renseignez l\'ID d\'application et le secret client Microsoft ci-dessus (ou enregistrez-les d\'abord).')];
        }

        $tenant = SsoRegistry::microsoftTenant();

        // 1) L'annuaire existe-t-il ? Le document de découverte le dit sans
        //    consommer le moindre identifiant, et donne un message net quand
        //    l'ID d'annuaire est simplement mal recopié.
        $decouverte = $this->httpGet(self::discoveryUrlFor($tenant));
        if ($decouverte === null) {
            return ['ok' => false, 'error' => t('Microsoft injoignable depuis ce serveur — vérifiez son accès Internet sortant.')];
        }
        if ($decouverte['status'] >= 400) {
            return [
                'ok'    => false,
                'error' => t('Annuaire « {tenant} » introuvable chez Microsoft — vérifiez l\'ID de l\'annuaire (locataire) copié depuis le portail Entra.', ['tenant' => $tenant]),
            ];
        }

        // 2) ID client et secret. La sonde dépend de l'annuaire : `common`,
        //    `organizations` et `consumers` sont des alias sans annuaire
        //    propre, où Microsoft REFUSE `client_credentials` — sonder ainsi y
        //    produirait un échec permanent sur des identifiants pourtant bons.
        //    On y demande donc un échange de code avec un code volontairement
        //    faux, qui exerce le même contrôle du client.
        $jeton = $this->httpPost(
            self::endpointFor($tenant, 'token'),
            SsoRegistry::microsoftTenantIsNamed()
                ? [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => SsoRegistry::microsoftClientId(),
                    'client_secret' => SsoRegistry::microsoftClientSecret(),
                    'scope'         => 'https://graph.microsoft.com/.default',
                ]
                : [
                    'grant_type'    => 'authorization_code',
                    'client_id'     => SsoRegistry::microsoftClientId(),
                    'client_secret' => SsoRegistry::microsoftClientSecret(),
                    'code'          => 'mia-verification-' . bin2hex(random_bytes(8)),
                    'redirect_uri'  => SsoRegistry::redirectUri(),
                    'scope'         => self::SCOPES,
                ]
        );
        if ($jeton === null) {
            return ['ok' => false, 'error' => t('Microsoft injoignable depuis ce serveur — vérifiez son accès Internet sortant.')];
        }

        return self::credentialVerdict($jeton['status'], $jeton['body']);
    }

    // ===================== HTTP =====================

    private static function endpoint(string $chemin): string
    {
        return self::endpointFor(SsoRegistry::microsoftTenant(), $chemin);
    }

    /** @return array{status:int, body:string}|null */
    private function httpPost(string $url, array $data): ?array
    {
        return $this->http($url, [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data),
        ]);
    }

    /** @return array{status:int, body:string}|null */
    private function httpGet(string $url): ?array
    {
        return $this->http($url, ['method' => 'GET']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status:int, body:string}|null
     */
    private function http(string $url, array $options): ?array
    {
        $ctx  = stream_context_create(['http' => $options + ['timeout' => 20, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach (($http_response_header ?? []) as $entete) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $entete, $m)) {
                $status = (int)$m[1];
            }
        }
        return ['status' => $status, 'body' => (string)$body];
    }
}
