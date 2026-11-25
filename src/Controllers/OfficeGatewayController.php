<?php

declare(strict_types=1);

/**
 * Passerelle de « Claude dans Office » : `/office/v1/…`, au format de l'API
 * Anthropic (cf. OfficeGateway pour le pourquoi, OfficeAddin pour les
 * réglages).
 *
 * Traitée AVANT la session et le contrôle CSRF (public/index.php) : l'add-in
 * est une page de `pivot.claude.ai`, elle n'a ni cookie ni jeton CSRF de MIA.
 * Ce qui fait foi, c'est l'identifiant qu'elle présente — jeton Microsoft
 * (signature vérifiée, EntraJwt) ou jeton personnel (OfficeAddinToken) — puis,
 * comme dans le chat : compte actif, droit `office_addin.use`, modèle permis
 * par ses groupes, quota, budget, démo.
 *
 * Routes :
 *   OPTIONS *                         préflight CORS
 *   GET  /office/v1/models            modèles Claude ouverts à la personne
 *   GET  /office/v1/models/{id}
 *   POST /office/v1/messages          relayé à Anthropic, flux compris, compté
 *   POST /office/v1/messages/count_tokens  relayé, gratuit
 */
final class OfficeGatewayController
{
    /** Base de l'API Anthropic de substitution (tests : faux serveur). */
    private static ?string $upstreamBase = null;

    public static function overrideUpstream(?string $baseUrl): void
    {
        self::$upstreamBase = $baseUrl;
    }

    /**
     * @param string|null $rawBody corps de la requête (tests) — sinon php://input
     * @param array<string,string>|null $headers en-têtes en minuscules (tests)
     */
    public static function handle(string $method, string $path, ?string $rawBody = null, ?array $headers = null): void
    {
        $headers ??= self::requestHeaders();
        foreach (OfficeGateway::corsHeaders((string)($headers['access-control-request-headers'] ?? '')) as $k => $v) {
            self::header($k . ': ' . $v);
        }
        self::header('X-Content-Type-Options: nosniff');
        I18n::use(I18n::instanceDefault());

        if ($method === 'OPTIONS') {
            self::status(204);
            return;
        }

        $route = '/' . trim(substr($path, strlen(OfficeAddin::PATH_PREFIX)), '/');
        try {
            if (!OfficeAddin::isEnabled()) {
                self::fail(403, t('Claude dans Office n’est pas activé sur cette instance MIA. Un administrateur l’active dans Capacités → Connecteurs → Claude dans Office.'));
                return;
            }
            if ($route === '/v1/models' || preg_match('#^/v1/models/([A-Za-z0-9._:-]{1,120})$#', $route, $m) === 1) {
                if ($method !== 'GET') {
                    self::fail(405, t('Méthode non prise en charge.'));
                    return;
                }
                $user = self::authenticate($headers);
                if ($user === null) {
                    return;
                }
                self::models($user, $m[1] ?? null);
                return;
            }
            if ($route === '/v1/messages' || $route === '/v1/messages/count_tokens') {
                if ($method !== 'POST') {
                    self::fail(405, t('Méthode non prise en charge.'));
                    return;
                }
                $user = self::authenticate($headers);
                if ($user === null) {
                    return;
                }
                self::messages($user, $headers, $rawBody, $route === '/v1/messages/count_tokens');
                return;
            }
            self::fail(404, t('Adresse inconnue de la passerelle MIA : {route}', ['route' => $route]));
        } catch (\Throwable $e) {
            error_log('[Claude dans Office] erreur inattendue : ' . $e->getMessage());
            if (!headers_sent()) {
                self::fail(500, t('Erreur inattendue de la passerelle MIA.'));
            }
        }
    }

    // ================================================================ Identité

    /** Compte de la personne, ou null (refus déjà envoyé). */
    private static function authenticate(array $headers): ?array
    {
        $credential = OfficeGateway::credentialFrom($headers);
        if ($credential === '') {
            self::fail(401, t('Aucun identifiant reçu : connectez l’add-in avec votre jeton MIA, ou demandez à votre DSI le déploiement avec la connexion Microsoft.'));
            return null;
        }

        $user = null;
        if (OfficeAddinToken::looksValid($credential)) {
            if (!OfficeAddin::tokensAllowed()) {
                self::fail(401, t('Les jetons personnels sont désactivés sur cette instance : Claude dans Office s’ouvre avec votre compte Microsoft.'));
                return null;
            }
            $user = OfficeAddinToken::findUser($credential);
            if ($user === null) {
                self::fail(401, t('Jeton inconnu ou révoqué. Générez-en un nouveau dans MIA : Réglages → Comptes connectés → Claude dans Office.'));
                return null;
            }
        } elseif (EntraJwt::looksLikeJwt($credential)) {
            if (!OfficeAddin::entraAllowed()) {
                self::fail(401, t('La connexion Microsoft de Claude dans Office n’est pas configurée sur cette instance MIA.'));
                return null;
            }
            try {
                $claims = EntraJwt::verifyLive($credential, OfficeAddin::tenant(), OfficeAddin::clientId(), OfficeAddin::SCOPE);
            } catch (EntraJwtException $e) {
                error_log('[Claude dans Office] jeton Microsoft refusé : ' . $e->reason);
                self::fail(401, self::entraRefusal($e->reason));
                return null;
            }
            $user = self::userForClaims($claims);
            if ($user === null) {
                $email = EntraJwt::emailFrom($claims);
                self::fail(403, $email !== ''
                    ? t('Aucun compte MIA pour {email}. Demandez l’accès à votre administrateur MIA.', ['email' => $email])
                    : t('Aucun compte MIA ne correspond à votre compte Microsoft. Demandez l’accès à votre administrateur MIA.'));
                return null;
            }
        } else {
            self::fail(401, t('Identifiant non reconnu : ce n’est ni un jeton MIA (mia_office_…) ni un jeton Microsoft.'));
            return null;
        }

        I18n::use(I18n::forUser($user));
        if ((int)($user['is_active'] ?? 0) !== 1) {
            self::fail(403, t('Votre compte MIA est désactivé.'));
            return null;
        }
        if (!Role::userCan($user, 'office_addin.use')) {
            self::fail(403, t('Votre rôle MIA n’ouvre pas Claude dans Office. Demandez-le à votre administrateur MIA.'));
            return null;
        }
        return $user;
    }

    /**
     * Personne d'un jeton Microsoft vérifié : l'identité déjà rattachée
     * (même sujet que le SSO Microsoft, `{tid}.{oid}`), sinon son adresse —
     * l'annuaire est celui de l'organisation, configuré et vérifié : ses
     * adresses sont attestées par son administrateur (même règle que le SSO
     * sur un annuaire nommé). Aucune création de compte.
     */
    private static function userForClaims(array $claims): ?array
    {
        $subject = EntraJwt::subjectFrom($claims);
        $user = UserIdentity::findUser(UserIdentity::MICROSOFT, $subject);
        if ($user !== null) {
            return $user;
        }
        foreach (['email', 'preferred_username', 'upn', 'unique_name'] as $k) {
            $email = EntraJwt::emailFrom([$k => $claims[$k] ?? '']);
            if ($email === '') {
                continue;
            }
            $user = User::findByEmailAnyType($email);
            if ($user !== null) {
                try {
                    UserIdentity::link((int)$user['id'], ['provider' => UserIdentity::MICROSOFT, 'subject' => $subject, 'email' => $email]);
                } catch (\Throwable $e) {
                    error_log('[Claude dans Office] identité Microsoft non rattachée : ' . $e->getMessage());
                }
                return $user;
            }
        }
        return null;
    }

    private static function entraRefusal(string $reason): string
    {
        return match ($reason) {
            'expired', 'not_yet_valid' => t('Votre session Microsoft a expiré : fermez puis rouvrez l’add-in.'),
            'audience', 'scope', 'issuer' => t('Ce jeton Microsoft n’est pas destiné à MIA : le manifeste déployé ou l’application Entra ne correspondent pas à la configuration de MIA.'),
            'config' => t('La connexion Microsoft de Claude dans Office n’est pas configurée sur cette instance MIA.'),
            default => t('Jeton Microsoft refusé.'),
        };
    }

    // ================================================================ Modèles

    /** @return array<string, string> identifiant → libellé */
    public static function modelsFor(array $user): array
    {
        $scope   = UserGroup::membershipScopeForUser((int)$user['id']);
        $allowed = UserGroup::allowedLlmKeysForUser((array)($scope['active_group_ids'] ?? []));
        $llms    = LLMFactory::available();
        if ($allowed !== null) {
            $llms = array_intersect_key($llms, array_flip($allowed));
        }
        $fallback = ApiKey::getModel('anthropic') ?? LlmCatalog::defaultModel('anthropic');
        return OfficeGateway::claudeModels($llms, (string)$fallback);
    }

    private static function models(array $user, ?string $id): void
    {
        $models = self::modelsFor($user);
        $payload = OfficeGateway::modelsPayload($models);
        if ($id !== null) {
            foreach ($payload['data'] as $row) {
                if ($row['id'] === $id) {
                    self::json(200, $row);
                    return;
                }
            }
            self::fail(404, t('Modèle inconnu ou non ouvert à votre compte : {model}', ['model' => $id]));
            return;
        }
        self::json(200, $payload);
    }

    // ================================================================ Messages

    private static function messages(array $user, array $headers, ?string $rawBody, bool $countOnly): void
    {
        $raw = $rawBody ?? (string)file_get_contents('php://input', false, null, 0, OfficeGateway::MAX_BODY_BYTES + 1);
        if (strlen($raw) > OfficeGateway::MAX_BODY_BYTES) {
            self::fail(413, t('Requête trop lourde : {n} Mo au plus.', ['n' => (int)(OfficeGateway::MAX_BODY_BYTES / 1048576)]));
            return;
        }
        $body = json_decode($raw, true);
        if (!is_array($body) || array_is_list($body)) {
            self::fail(400, t('Corps illisible : un objet JSON au format de l’API Anthropic est attendu.'));
            return;
        }

        $model  = (string)($body['model'] ?? '');
        $models = self::modelsFor($user);
        if ($models === []) {
            self::fail(403, t('Aucun modèle Claude n’est ouvert à votre compte MIA. Demandez à votre administrateur d’activer un modèle Claude pour vos groupes.'));
            return;
        }
        if (!isset($models[$model])) {
            self::fail(403, t('Le modèle « {model} » n’est pas ouvert à votre compte MIA. Modèles disponibles : {list}.', [
                'model' => $model,
                'list'  => implode(', ', array_keys($models)),
            ]));
            return;
        }

        if (!$countOnly) {
            $status = CreditQuota::status($user);
            if (CreditQuota::isExceeded($status)) {
                $refusal = CreditQuota::refusal($status);
                self::fail(403, $refusal['title'] . ' — ' . $refusal['message']);
                return;
            }
        }

        $apiKey = (string)(ApiKey::getDecrypted('anthropic') ?? '');
        if ($apiKey === '') {
            self::fail(503, t('MIA n’a pas de clé Anthropic : un administrateur doit l’ajouter dans Modèles IA → Fournisseurs et modèles.'));
            return;
        }

        $url = self::upstreamMessagesUrl() . ($countOnly ? '/count_tokens' : '');
        $upHeaders = OfficeGateway::upstreamHeaders($headers, $apiKey, LlmCatalog::apiVersion('anthropic') ?? '2023-06-01');
        self::relay($user, $url, $upHeaders, $raw, $body, $model, $countOnly);
    }

    private static function upstreamMessagesUrl(): string
    {
        if (self::$upstreamBase !== null) {
            return rtrim(self::$upstreamBase, '/') . '/v1/messages';
        }
        return LlmCatalog::endpoint('anthropic', 'messages') ?? 'https://api.anthropic.com/v1/messages';
    }

    /**
     * Relais vers Anthropic. La réponse part à l'add-in au fil de l'eau, à
     * l'octet près ; le relevé d'usage la lit au passage. Une réponse en
     * erreur (petite) est gardée entière : un crédit épuisé ou une clé
     * refusée chez Anthropic se disent à la personne avec les mots de MIA, et
     * se consignent pour l'administration (ProviderIncident).
     */
    private static function relay(array $user, string $url, array $upHeaders, string $raw, array $body, string $model, bool $countOnly): void
    {
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(0);
            ignore_user_abort(true);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }

        $state       = OfficeGateway::usageState();
        $status      = 0;
        $respHeaders = [];
        $started     = false;
        $isError     = false;
        $isSse       = false;
        $errorBody   = '';
        $jsonBody    = '';
        $aborted     = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $raw,
            CURLOPT_HTTPHEADER      => $upHeaders,
            CURLOPT_RETURNTRANSFER  => false,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_TIMEOUT         => 0,
            // Un raisonnement long peut rester muet plusieurs minutes (cf.
            // AnthropicAdapter::SILENCE_MAX_SEC) : au-delà, la réponse est perdue.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => 900,
            CURLOPT_HEADERFUNCTION  => static function ($ch, string $line) use (&$status, &$respHeaders): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int)$m[1];
                    $respHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION   => static function ($ch, string $data) use (&$status, &$respHeaders, &$started, &$isError, &$isSse, &$errorBody, &$jsonBody, &$state, &$aborted): int {
                if (!$started) {
                    $started = true;
                    $isError = $status >= 400;
                    $isSse   = str_starts_with(strtolower((string)($respHeaders['content-type'] ?? '')), 'text/event-stream');
                    if (!$isError) {
                        self::status($status > 0 ? $status : 200);
                        self::header('Content-Type: ' . ($respHeaders['content-type'] ?? 'application/json'));
                        self::header('Cache-Control: no-cache');
                        self::header('X-Accel-Buffering: no');
                        if (isset($respHeaders['request-id'])) {
                            self::header('request-id: ' . preg_replace('/[^A-Za-z0-9_\-]/', '', $respHeaders['request-id']));
                        }
                    }
                }
                if ($isError) {
                    if (strlen($errorBody) < 65536) {
                        $errorBody .= $data;
                    }
                    return strlen($data);
                }
                echo $data;
                if (PHP_SAPI !== 'cli') {
                    @flush();
                    if (connection_aborted()) {
                        $aborted = true;
                        return 0; // l'add-in est parti : on coupe l'appel chez Anthropic
                    }
                }
                if ($isSse) {
                    $state = OfficeGateway::feedSse($state, $data);
                } elseif (strlen($jsonBody) < 8 * 1048576) {
                    $jsonBody .= $data;
                }
                return strlen($data);
            },
        ]);
        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        if (!$started) {
            if ($status >= 400) {
                $isError = true;
            } elseif ($ok === false || $errno !== 0) {
                error_log('[Claude dans Office] Anthropic injoignable : ' . $error);
                self::fail(502, t('Anthropic est injoignable depuis MIA pour le moment. Réessayez dans un instant.'));
                return;
            } else {
                // Réponse vide, sans erreur : rien à relayer de plus que le statut.
                self::status($status > 0 ? $status : 502);
                return;
            }
        }

        if ($isError) {
            self::upstreamError($status, $respHeaders, $errorBody);
            return;
        }
        if ($countOnly) {
            return;
        }

        if (!$isSse) {
            $state = OfficeGateway::stateFromJson($jsonBody);
        }
        $cut = $aborted || ($ok === false && $errno !== 0);
        $tokens = OfficeGateway::tokensFor($state, $model, $cut, OfficeGateway::inputEstimate($body));
        $billed = ['total_credits' => 0];
        try {
            $billed = UsageMeter::llm('anthropic::' . $model, $tokens, UsageSource::OFFICE_ADDIN, ['user_id' => (int)$user['id']]);
        } catch (\Throwable $e) {
            error_log('[Claude dans Office] comptage impossible : ' . $e->getMessage());
        }
        error_log(sprintf(
            '[Claude dans Office] #%d %s — entrée %d (cache lu %d, écrit %d dont 1 h %d), sortie %d, recherches %d%s, %d crédits%s',
            (int)$user['id'],
            $model,
            (int)($tokens['input'] ?? 0),
            (int)($tokens['input_cached'] ?? 0),
            (int)($tokens['input_cache_write'] ?? 0),
            (int)($tokens['input_cache_write_1h'] ?? 0),
            (int)($tokens['output'] ?? 0),
            (int)($tokens['web_searches'] ?? 0),
            isset($tokens['price_factor']) ? sprintf(', tarif ×%s', (string)$tokens['price_factor']) : '',
            (int)($billed['total_credits'] ?? 0),
            $cut ? ' (coupée)' : ''
        ));
    }

    /**
     * Réponse en erreur d'Anthropic. Le corps part tel quel (l'add-in connaît
     * ces erreurs et sait réessayer une surcharge), sauf pour un problème du
     * COMPTE de l'organisation — crédit épuisé, clé refusée : la personne ne
     * peut rien y faire, le message brut d'Anthropic ne lui dirait pas vers
     * qui se tourner. Ceux-là se consignent pour l'administration.
     */
    private static function upstreamError(int $status, array $respHeaders, string $body): void
    {
        $desc = LlmErrorPresenter::fromHttp($status, $body, 'anthropic');
        if (in_array($desc['kind'] ?? '', ['quota', 'auth'], true)) {
            ProviderIncident::note($desc);
            self::fail($status >= 400 && $status < 600 ? $status : 502, (string)$desc['title'] . ' — ' . (string)$desc['message']);
            return;
        }
        self::status($status);
        self::header('Content-Type: ' . ($respHeaders['content-type'] ?? 'application/json'));
        if (isset($respHeaders['retry-after']) && preg_match('/^\d{1,6}$/', $respHeaders['retry-after']) === 1) {
            self::header('retry-after: ' . $respHeaders['retry-after']);
        }
        echo $body;
    }

    // ================================================================ HTTP

    /** En-têtes de la requête, en minuscules (Authorization compris, quel que soit le SAPI). */
    private static function requestHeaders(): array
    {
        $out = [];
        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $k => $v) {
                $out[strtolower((string)$k)] = (string)$v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with((string)$k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string)$k, 5)));
                $out[$name] ??= $v;
            }
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
            if (!isset($out['authorization']) && !empty($_SERVER[$k])) {
                $out['authorization'] = (string)$_SERVER[$k];
            }
        }
        if (!isset($out['content-type']) && !empty($_SERVER['CONTENT_TYPE'])) {
            $out['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        }
        return $out;
    }

    private static function fail(int $status, string $message): void
    {
        self::status($status);
        self::header('Content-Type: application/json; charset=utf-8');
        echo OfficeGateway::errorBody($status, $message);
    }

    private static function json(int $status, array $payload): void
    {
        self::status($status);
        self::header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function status(int $code): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }
    }

    private static function header(string $line): void
    {
        if (!headers_sent()) {
            header($line);
        }
    }
}
