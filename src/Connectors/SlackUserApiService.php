<?php

declare(strict_types=1);

/**
 * Socle des services SLACK par utilisateur — troisième famille, après Google
 * (`GoogleUserApiService`) et Microsoft (`MicrosoftUserApiService`). La
 * plomberie commune aux trois (refresh lazy, retry unique sur 401, erreurs FR
 * sans fuite) vit dans `ConnectorApiService` ; ce fichier ne porte que ce qui
 * est propre à Slack.
 *
 * ── LE PIÈGE QUI JUSTIFIE CE SOCLE : `ok:false` DANS UN HTTP 200 ───────────
 * Slack ne se sert quasiment pas des codes HTTP. Un canal inexistant, un scope
 * manquant, un jeton révoqué, une limite de débit : tout revient en **200**,
 * avec `{"ok":false,"error":"channel_not_found"}` dans le corps. Or
 * `ConnectorApiService::apiRaw()` ne lève que sur `status >= 400` — une erreur
 * Slack traversait donc toute la pile comme un SUCCÈS, et le modèle recevait
 * un résultat vide qu'il interprétait comme « il n'y a rien », au lieu de
 * « je n'ai pas le droit ». D'où `slackGet()` / `slackPost()` : AUCUN appel
 * Slack ne doit passer par `apiJson()` en direct, et `assertOk()` est le seul
 * endroit qui décide qu'une réponse est exploitable.
 *
 * Deux conséquences qui en découlent :
 *   - `invalid_auth` / `token_revoked` valent un 401 : le jeton est marqué
 *     révoqué ICI, puisque le squelette commun ne le verra jamais passer ;
 *   - `missing_scope` nomme le scope attendu (`needed`), ce qui permet de dire
 *     à l'utilisateur quoi ré-autoriser au lieu d'un « accès refusé » opaque.
 *
 * ── DEUXIÈME PIÈGE : LES SCOPES SONT SÉPARÉS PAR DES VIRGULES ─────────────
 * Google et Microsoft séparent par des espaces, Slack par des virgules. Un
 * `preg_split('/\s+/')` recopié rendait donc UN seul jeton
 * (`channels:read,chat:write,…`) qui ne correspondait à rien : toutes les
 * capacités étaient réputées non accordées, sans la moindre erreur. Et le
 * piège de préfixe existe aussi — `chat:write` préfixe `chat:write.public` —
 * d'où une comparaison EXACTE, jeton par jeton.
 *
 * Le Bearer voyage UNIQUEMENT en header : Slack accepte encore un jeton en
 * paramètre de requête, mais il finirait dans les journaux d'accès.
 */
abstract class SlackUserApiService extends ConnectorApiService
{
    /** Racine de l'API Web Slack — déclarée une fois, dans SlackApp. */
    protected const SLACK_BASE = SlackApp::API_BASE;

    /**
     * Refresh réussi : quand la ROTATION est active, Slack renvoie un nouveau
     * refresh token qui remplace l'ancien (jetons roulants, comme Microsoft).
     * Sans cette écriture, la connexion meurt à l'expiration du premier jeton
     * — 12 h — sans une ligne de log.
     */
    protected function afterRefresh(array $fresh): void
    {
        $roule = $fresh['refresh_token'] ?? null;
        if (is_string($roule) && $roule !== '' && $roule !== $this->refreshToken) {
            $this->refreshToken = $roule;
            UserDriveToken::updateRefreshToken($this->userId, $roule, static::TOKEN_PROVIDER);
        }
    }

    // ---- Appels Slack --------------------------------------------------------

    /**
     * GET sur une méthode de l'API Web, paramètres en query. Les valeurs vides
     * sont retirées : Slack refuse `cursor=` ou `team_id=` vides (400 déguisé
     * en `invalid_arguments`).
     */
    protected function slackGet(string $method, array $query = []): array
    {
        $query = array_filter($query, static fn($v): bool => $v !== '' && $v !== null);
        $url   = static::SLACK_BASE . '/' . $method . ($query !== [] ? '?' . http_build_query($query) : '');

        return $this->assertOk($this->apiJson('GET', $url), $method);
    }

    /**
     * POST sur une méthode de l'API Web, corps JSON. Slack l'accepte dès lors
     * que le jeton est en header — ce qui est toujours le cas ici.
     */
    protected function slackPost(string $method, array $body = []): array
    {
        return $this->assertOk(
            $this->apiJson('POST', static::SLACK_BASE . '/' . $method, $body),
            $method
        );
    }

    /**
     * Rend la réponse si `ok` est vrai, lève un message FR sinon. SEUL endroit
     * qui décide qu'une réponse Slack est exploitable.
     */
    protected function assertOk(array $data, string $method): array
    {
        if (($data['ok'] ?? false) === true) {
            return $data;
        }
        $code = trim((string)($data['error'] ?? ''));

        // Journal : la méthode appelée et le CODE, jamais le corps (il porte le
        // texte des messages lus) ni le jeton.
        error_log(static::LOG_LABEL . ' ' . $method . ' → ' . ($code !== '' ? $code : 'réponse illisible'));

        if (SlackUserOAuth::isRevocation($code)) {
            // Le squelette commun ne verra jamais ce cas : Slack l'a rangé dans
            // un HTTP 200. C'est donc ici que la connexion est marquée à
            // reconnecter, sinon l'utilisateur boucle sur une erreur muette.
            UserDriveToken::markRevoked($this->userId, static::TOKEN_PROVIDER);
            throw new \RuntimeException(static::MSG_RECONNECT);
        }
        if (self::isScopeFailure($code)) {
            // Message rendu AU MODÈLE (et à la carte d'échec) : il reste en
            // français, comme toutes les erreurs de service — le modèle le
            // reformule dans la langue de l'utilisateur.
            $manquant = trim((string)($data['needed'] ?? ''));
            throw new \RuntimeException(
                static::MSG_SCOPE_INSUFFICIENT
                . ($manquant !== '' ? ' (autorisation attendue : ' . $manquant . ')' : '')
            );
        }
        if (self::isNotFound($code)) {
            throw new \RuntimeException(static::MSG_NOT_FOUND);
        }
        if ($code === 'ratelimited' || $code === 'rate_limited') {
            throw new \RuntimeException(
                'Quota ' . static::API_LABEL . ' atteint — réessayez dans quelques instants.'
            );
        }

        throw new \RuntimeException(self::specificSlackMessage($code) ?? (static::MSG_BAD_REQUEST
            . ($code !== '' ? ' (' . $code . ')' : '')));
    }

    /**
     * Erreurs HTTP réelles — rares chez Slack, mais la limite de débit en fait
     * partie (429 avec `Retry-After`). Le squelette commun la traite déjà ;
     * on ne garde ici que le cas d'un corps `ok:false` rendu AVEC un statut
     * d'erreur, pour que le message nomme la cause Slack plutôt que le statut.
     */
    protected function providerHttpError(int $status, string $body): ?\RuntimeException
    {
        $code = self::slackErrorCode($body);
        if ($code === '') {
            return null;
        }
        if (SlackUserOAuth::isRevocation($code)) {
            UserDriveToken::markRevoked($this->userId, static::TOKEN_PROVIDER);
            return new \RuntimeException(static::MSG_RECONNECT);
        }
        if (self::isScopeFailure($code)) {
            return new \RuntimeException(static::MSG_SCOPE_INSUFFICIENT);
        }
        $message = self::specificSlackMessage($code);
        return $message !== null ? new \RuntimeException($message) : null;
    }

    /** Corps d'erreur Slack : le code structuré, jamais de texte libre. */
    protected function errorDetail(string $body): string
    {
        return self::slackErrorCode($body);
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /**
     * Le scope est-il réellement accordé ?
     *
     * Deux raisons de ne pas recopier la version Google/Microsoft :
     *   - Slack sépare ses scopes par des VIRGULES (« channels:read,chat:write »),
     *     là où les deux autres séparent par des espaces ;
     *   - le piège de préfixe existe quand même : `chat:write` est un préfixe de
     *     `chat:write.public` et de `chat:write.customize`, `users:read` de
     *     `users:read.email`. Un `str_contains` afficherait « Publication ✓ »
     *     sur une connexion qui ne peut pas publier.
     * Les deux séparateurs sont tolérés en entrée : une colonne migrée d'un
     * autre fournisseur, ou une valeur recopiée à la main, ne doit pas faire
     * perdre toutes ses capacités à une connexion valide.
     */
    public static function scopeGranted(string $granted, string $scope): bool
    {
        foreach (preg_split('/[\s,]+/', trim($granted)) ?: [] as $jeton) {
            if ($jeton !== '' && strcasecmp($jeton, $scope) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Au moins un des scopes est-il accordé ? (familles `*:history`…) */
    public static function anyScopeGranted(string $granted, array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (self::scopeGranted($granted, (string)$scope)) {
                return true;
            }
        }
        return false;
    }

    /** Code d'erreur d'un corps de réponse Slack, '' si illisible ou succès. */
    public static function slackErrorCode(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data) || ($data['ok'] ?? false) === true) {
            return '';
        }
        return trim((string)($data['error'] ?? ''));
    }

    /** Autorisation non accordée (ou jeton du mauvais type). */
    public static function isScopeFailure(string $code): bool
    {
        return in_array($code, [
            'missing_scope',
            'not_allowed_token_type',
            'no_permission',
            'ekm_access_denied',
        ], true);
    }

    /** La ressource visée n'existe pas (ou n'est pas visible par ce compte). */
    public static function isNotFound(string $code): bool
    {
        return in_array($code, [
            'channel_not_found',
            'thread_not_found',
            'message_not_found',
            'user_not_found',
            'users_not_found',
        ], true);
    }

    /**
     * Messages FR des refus Slack qui ont un REMÈDE PROPRE — c'est toute leur
     * valeur : « accès refusé » enverrait chercher du côté de la connexion
     * alors que la réponse est, selon le cas, « rejoins le canal », « ce canal
     * est archivé » ou « ton administrateur l'interdit ». Null = suivre la
     * chaîne d'erreurs commune.
     */
    public static function specificSlackMessage(string $code): ?string
    {
        return match ($code) {
            'not_in_channel' => 'Vous n\'êtes pas membre de ce canal Slack : rejoignez-le dans Slack, '
                . 'puis relancez la demande. MIA ne voit que les conversations dont vous êtes membre.',
            'channel_not_found' => null, // traité par isNotFound (message du service)
            'is_archived' => 'Ce canal Slack est archivé : il se lit dans Slack, mais plus rien ne peut y être publié.',
            'restricted_action', 'restricted_action_read_only_channel' =>
                'Votre espace de travail Slack interdit cette action dans ce canal '
                . '(canal en lecture seule, ou règle posée par un administrateur).',
            'msg_too_long' => 'Message trop long pour Slack : raccourcissez-le (limite d\'environ 40 000 caractères).',
            'no_text' => 'Le message à publier est vide.',
            'cannot_dm_bot', 'cannot_reply_to_message' =>
                'Slack refuse de publier à cet endroit (message système, ou conversation fermée à la réponse).',
            'not_allowed_token_type' => null, // traité par isScopeFailure
            'team_access_not_granted' =>
                'Votre compte n\'a plus accès à cet espace de travail Slack. Reconnectez-le dans Réglages → Comptes connectés.',
            'fatal_error', 'internal_error', 'service_unavailable' =>
                'Slack a rencontré une erreur interne — réessayez dans quelques instants.',
            default => null,
        };
    }
}
