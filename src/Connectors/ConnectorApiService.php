<?php

declare(strict_types=1);

/**
 * Socle COMMUN aux services de connecteurs personnels, quel que soit le
 * fournisseur (Google, Microsoft…). Il porte ce qui ne dépend ni de l'API
 * appelée ni du métier :
 *
 *   - refresh lazy de l'access token (marge 60 s, cache chiffré en base),
 *   - appels Bearer avec retry unique sur 401 (refresh forcé),
 *   - squelette de présentation des erreurs HTTP en messages FR SANS FUITE
 *     (corps du fournisseur loggé tronqué, jamais renvoyé — et jamais un
 *     jeton en log),
 *   - refresh token invalidé → statut 'revoked' + message de reconnexion.
 *
 * Trois niveaux de spécialisation, du plus général au plus précis, et c'est
 * l'ORDRE dans lequel `presentHttpError` les consulte :
 *
 *   1. ce squelette (401, 404, 403 générique, 429, 400) ;
 *   2. `providerHttpError()` — les particularités d'un FOURNISSEUR, portées
 *      par son socle (Google : « API non activée sur le projet Cloud » ;
 *      Microsoft : boîte inexistante, consentement refusé par l'annuaire) ;
 *   3. `specificHttpError()` — les particularités d'UN connecteur (Drive :
 *      export trop volumineux), consultées AVANT tout le reste.
 *
 * Deux autres points de variation, tous deux nés d'une différence réelle
 * entre fournisseurs et non d'un goût pour l'abstraction :
 *   - `afterRefresh()` : Microsoft renvoie un refresh token ROULANT à chaque
 *     rafraîchissement, qu'il faut persister ; Google garde le même ;
 *   - `errorDetail()` : la forme du corps d'erreur diffère (`error.status`
 *     chez Google, `error.code` chez Microsoft).
 *
 * Mettre à jour la politique de retry, la marge de refresh ou la règle « aucun
 * corps brut vers le client » = modifier CE fichier uniquement.
 */
abstract class ConnectorApiService
{
    // ---- Identité API, à déclarer par chaque connecteur ---------------------

    /** Classe OAuth du connecteur (refresh + isConfigured). */
    protected const OAUTH_CLASS = '';

    /** Provider des jetons dans user_drive_tokens (UserDriveToken::PROVIDER_*). */
    protected const TOKEN_PROVIDER = '';

    /** Nom FR du service (« Google Drive », « Outlook »…) — messages génériques. */
    protected const API_LABEL = '';

    /** Préfixe des logs (« [Drive user] »…). */
    protected const LOG_LABEL = '';

    /** Clé Settings : interrupteur global admin du connecteur dans le chat. */
    public const SETTING_ENABLED = '';

    /** Connexion expirée/révoquée (401, invalid_grant) : message de reconnexion. */
    public const MSG_RECONNECT = '';

    /** 403 « scope insuffisant » : écriture non consentie, reconnexion nécessaire. */
    protected const MSG_SCOPE_INSUFFICIENT = '';

    /** 404 : la ressource visée n'existe pas (ou plus) pour ce compte. */
    protected const MSG_NOT_FOUND = '';

    /** 400 : requête refusée — le message guide vers ce qu'il faut vérifier. */
    protected const MSG_BAD_REQUEST = '';

    /** 403 générique : « Accès refusé par … » (sans le détail ni le point final). */
    protected const MSG_ACCESS_DENIED = '';

    // ---- État d'instance -----------------------------------------------------

    protected ?string $accessToken;
    protected ?int $accessTokenExpiresAt;

    /**
     * NON `readonly` : chez Microsoft, le refresh token est remplacé à chaque
     * rafraîchissement (cf. afterRefresh). Le figer condamnerait la connexion
     * à mourir à l'expiration du tout premier jeton.
     */
    protected string $refreshToken;

    /**
     * NON `readonly` non plus : les autorisations d'un utilisateur bougent
     * (élargies depuis un autre connecteur du même client OAuth, ou retirées
     * depuis son compte Google), et le fournisseur les redit à chaque
     * rafraîchissement. Les figer au jour de la connexion faisait refuser des
     * outils que le fournisseur aurait acceptés — et en exposer que le
     * fournisseur refusait ensuite en 403.
     */
    protected string $grantedScope;

    protected function __construct(
        protected readonly int $userId,
        string $refreshToken,
        ?string $accessToken,
        ?string $accessTokenExpiresAt,
        string $grantedScope,
        protected readonly string $accountEmail = ''
    ) {
        $this->grantedScope         = $grantedScope;
        $this->refreshToken         = $refreshToken;
        $this->accessToken          = $accessToken;
        $this->accessTokenExpiresAt = $accessTokenExpiresAt !== null ? strtotime($accessTokenExpiresAt) ?: null : null;
    }

    /** Fonctionnalité autorisée par l'administrateur ? (défaut : oui, continuité) */
    public static function isGloballyEnabled(): bool
    {
        return Settings::get(static::SETTING_ENABLED, '1') === '1';
    }

    /** Adresse du compte connecté (vide si inconnue). */
    public function accountEmail(): string
    {
        return $this->accountEmail;
    }

    /**
     * Ligne de jeton exploitable pour cet utilisateur, ou null si le connecteur
     * n'est pas utilisable (OAuth non configuré, jamais connecté, révoqué).
     * Socle des fabriques forUser() : chaque service construit ensuite son
     * instance avec ses propres extras (préférences…).
     */
    protected static function tokenRowForUser(int $userId): ?array
    {
        $oauth = static::OAUTH_CLASS;
        if ($oauth === '' || !$oauth::isConfigured()) {
            return null;
        }
        $row = UserDriveToken::forUser($userId, static::TOKEN_PROVIDER);
        if ($row === null || (string)$row['status'] !== 'ok' || (string)$row['refresh_token'] === '') {
            return null;
        }
        return $row;
    }

    // ---- HTTP ----------------------------------------------------------------

    /** Access token valide, rafraîchi si expiré (marge 60 s). */
    protected function getValidAccessToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh
            && $this->accessToken !== null
            && $this->accessTokenExpiresAt !== null
            && $this->accessTokenExpiresAt > time() + 60) {
            return $this->accessToken;
        }

        $revoked = false;
        $oauth   = static::OAUTH_CLASS;
        $fresh   = $oauth::refreshAccessToken($this->refreshToken, $revoked);
        if ($fresh === null) {
            if ($revoked) {
                UserDriveToken::markRevoked($this->userId, static::TOKEN_PROVIDER);
                throw new \RuntimeException(static::MSG_RECONNECT);
            }
            throw new \RuntimeException(static::API_LABEL . ' injoignable — réessayez dans quelques instants.');
        }
        $this->accessToken          = (string)$fresh['access_token'];
        $this->accessTokenExpiresAt = time() + (int)$fresh['expires_in'];
        UserDriveToken::updateAccessToken(
            $this->userId,
            $this->accessToken,
            (int)$fresh['expires_in'],
            static::TOKEN_PROVIDER
        );
        $this->syncGrantedScope((string)($fresh['scope'] ?? ''));
        $this->afterRefresh($fresh);
        return $this->accessToken;
    }

    /**
     * Aligne les autorisations connues sur ce que le fournisseur vient de
     * redire. Écrit UNIQUEMENT sur changement réel, et jamais sur une valeur
     * vide : tous les fournisseurs ne renvoient pas `scope` à chaque
     * rafraîchissement, et prendre un silence pour un retrait ferait perdre
     * ses outils à une connexion parfaitement valide.
     *
     * Best-effort absolu (try/catch) : c'est un alignement de confort, il ne
     * doit jamais faire échouer l'appel qui l'a déclenché.
     */
    private function syncGrantedScope(string $scope): void
    {
        $scope = trim($scope);
        if ($scope === '' || $scope === trim($this->grantedScope)) {
            return;
        }
        $this->grantedScope = $scope;
        try {
            UserDriveToken::updateScope($this->userId, $scope, static::TOKEN_PROVIDER);
        } catch (\Throwable $e) {
            error_log(static::LOG_LABEL . ' scope non persisté : ' . $e->getMessage());
        }
    }

    /**
     * Suite d'un rafraîchissement réussi, au-delà de la mise en cache de
     * l'access token. No-op chez Google (le refresh token ne bouge pas) ;
     * Microsoft y persiste le jeton roulant.
     */
    protected function afterRefresh(array $fresh): void
    {
    }

    /**
     * Requête brute authentifiée Bearer, corps de réponse en string. Un 401
     * déclenche UN refresh forcé + retry unique (token expiré côté fournisseur
     * malgré le cache) ; s'il persiste, le refresh token est considéré révoqué.
     * Toute erreur devient une RuntimeException à message FR (presentHttpError).
     */
    protected function apiRaw(string $method, string $url, ?array $body = null, int $timeout = 60): string
    {
        $token = $this->getValidAccessToken();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $headers = 'Authorization: Bearer ' . $token;
            $payload = '';
            if ($body !== null) {
                $headers .= "\r\nContent-Type: application/json";
                // `[]` = objet JSON VIDE (`{}`), jamais une liste : c'est le
                // corps d'une action Graph sans paramètre (`/messages/{id}/send`).
                // Un POST sans corps n'emporte pas de Content-Length avec le
                // flux HTTP de PHP, et le front Azure le refuse (411) ; `[]`
                // encodé tel quel serait un tableau là où l'API attend un objet.
                $payload  = $body === [] ? '{}' : (json_encode($body, JSON_UNESCAPED_UNICODE) ?: '');
            }
            if (ParallelHttp::active()) {
                // Sous le planificateur des outils par intention : la requête
                // part avec celles des autres sources, la Fiber attend sa
                // réponse sans bloquer les autres. Même contrat qu'en dessous.
                [$status, $response] = ParallelHttp::request($method, $url, explode("\r\n", $headers), $payload, $timeout);
            } else {
                $ctx = stream_context_create(['http' => [
                    'method'        => $method,
                    'header'        => $headers,
                    'content'       => $payload,
                    'timeout'       => $timeout,
                    'ignore_errors' => true,
                ]]);

                $response = @file_get_contents($url, false, $ctx);
                $status   = 0;
                foreach ($http_response_header ?? [] as $h) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                        $status = (int)$m[1];
                    }
                }
            }

            if ($response === false) {
                throw new \RuntimeException(static::API_LABEL . ' injoignable — réessayez dans quelques instants.');
            }
            if ($status === 401 && $attempt === 0) {
                $token = $this->getValidAccessToken(true); // refresh forcé, throw si révoqué
                continue;
            }
            if ($status >= 400) {
                $path = (string)(parse_url($url, PHP_URL_PATH) ?? $url);
                error_log(static::LOG_LABEL . ' ' . $method . ' ' . $path . ' HTTP ' . $status . ' : ' . mb_substr($response, 0, 300));
                throw $this->presentHttpError($status, $response);
            }
            return $response;
        }
        // Inatteignable (retour ou throw dans la boucle), garde pour l'analyseur.
        throw new \RuntimeException('Erreur ' . static::API_LABEL . '.');
    }

    /** Appel JSON authentifié Bearer — même politique que apiRaw. */
    protected function apiJson(string $method, string $url, ?array $body = null, int $timeout = 60): array
    {
        $data = json_decode($this->apiRaw($method, $url, $body, $timeout), true);
        return is_array($data) ? $data : [];
    }

    // ---- Erreurs -------------------------------------------------------------

    /**
     * Erreur HTTP → RuntimeException à message FR, sans fuite du corps du
     * fournisseur. Ordre : connecteur (specificHttpError), puis fournisseur
     * (providerHttpError), puis ce squelette.
     */
    protected function presentHttpError(int $status, string $body): \RuntimeException
    {
        if ($status === 401) {
            UserDriveToken::markRevoked($this->userId, static::TOKEN_PROVIDER);
            return new \RuntimeException(static::MSG_RECONNECT);
        }
        if ($status === 404) {
            return new \RuntimeException(static::MSG_NOT_FOUND);
        }
        $specific = $this->specificHttpError($status, $body);
        if ($specific !== null) {
            return $specific;
        }
        $provider = $this->providerHttpError($status, $body);
        if ($provider !== null) {
            return $provider;
        }
        if ($status === 403) {
            // Détail STRUCTURÉ uniquement (code d'erreur du fournisseur) —
            // jamais le corps brut vers le client.
            $detail = $this->errorDetail($body);
            return new \RuntimeException(static::MSG_ACCESS_DENIED . ($detail !== '' ? ' (' . $detail . ')' : '') . '.');
        }
        if ($status === 429) {
            return new \RuntimeException('Quota ' . static::API_LABEL . ' atteint — réessayez dans quelques instants.');
        }
        if ($status === 400) {
            return new \RuntimeException(static::MSG_BAD_REQUEST);
        }
        return new \RuntimeException('Erreur ' . static::API_LABEL . ' (HTTP ' . $status . ').');
    }

    /**
     * Cas d'erreur propres à UN connecteur, prioritaires sur tout le reste.
     * Null = suivre la chaîne.
     */
    protected function specificHttpError(int $status, string $body): ?\RuntimeException
    {
        return null;
    }

    /**
     * Cas d'erreur propres à UN FOURNISSEUR (portés par son socle), consultés
     * après le connecteur et avant le squelette. Null = suivre le squelette.
     */
    protected function providerHttpError(int $status, string $body): ?\RuntimeException
    {
        return null;
    }

    /**
     * Code d'erreur STRUCTURÉ du fournisseur, à joindre entre parenthèses au
     * message d'accès refusé. Chaîne vide = aucun détail exploitable. Ne doit
     * JAMAIS renvoyer de texte libre du fournisseur (fuite potentielle).
     */
    protected function errorDetail(string $body): string
    {
        return '';
    }
}
