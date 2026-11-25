<?php

declare(strict_types=1);

/**
 * Jeton d'accès Microsoft Entra ID reçu DU NAVIGATEUR — vérification complète,
 * signature comprise.
 *
 * Premier usage : l'add-in Claude d'Excel, de Word et de PowerPoint branché
 * sur MIA comme passerelle (OfficeGateway). Avec `gateway_auth_source=entra`,
 * l'add-in envoie à chaque requête le jeton d'accès Entra de la personne,
 * audiencé à l'application de l'organisation (`api://<id>/access_as_user`).
 *
 * POURQUOI LA SIGNATURE ICI, ALORS QUE LE SSO S'EN PASSE (MicrosoftSsoProvider) :
 * le SSO reçoit l'`id_token` en back-channel TLS, du endpoint token, contre
 * notre secret — personne d'autre ne peut l'avoir fabriqué. Ici le jeton
 * arrive d'Internet, posé par un client : sans signature vérifiée, n'importe
 * qui écrirait `{"oid": "<l'oid du DG>"}` et dépenserait ses crédits.
 *
 * Vérifié, dans cet ordre (verify(), pure — horloge et clés injectées) :
 *   - forme JWS compacte, `alg` = RS256 seulement (jamais `none`, jamais HS*),
 *     `kid` présent ;
 *   - signature RSA-SHA256 contre la clé `kid` du JWKS de l'ANNUAIRE configuré ;
 *   - `iss` = cet annuaire (v2.0, ou v1.0 si l'application n'a pas encore
 *     `accessTokenAcceptedVersion: 2`) et `tid` = cet annuaire ;
 *   - `aud` = l'application (`api://<id>` ou son identifiant : en v2.0, Entra
 *     met toujours l'identifiant) ;
 *   - `scp` contient la permission attendue (un jeton d'application, sans
 *     `scp`, est refusé : il n'est pas émis pour une personne) ;
 *   - `exp` / `nbf`, avec 2 minutes de tolérance d'horloge ;
 *   - `oid` présent : la clé STABLE de la personne (l'adresse peut changer).
 *
 * Les clés (JWKS) sont lues sur `login.microsoftonline.com/<tenant>/discovery/
 * v2.0/keys`, gardées 24 h sur disque, relues au plus toutes les 5 minutes
 * quand un `kid` inconnu arrive (Microsoft fait tourner ses clés) — jamais à
 * chaque requête, jamais sur demande d'un jeton forgé en boucle.
 *
 * Hors périmètre : clouds souverains (`login.microsoftonline.us`, `.cn`).
 */
final class EntraJwt
{
    /** Tolérance d'horloge sur `exp` / `nbf` (secondes). */
    public const LEEWAY_SEC = 120;

    /** Durée de vie du JWKS gardé sur disque. */
    public const JWKS_TTL_SEC = 86400;

    /** Délai minimal entre deux relectures forcées (kid inconnu). */
    public const JWKS_REFRESH_MIN_SEC = 300;

    /** Taille maximale d'un jeton accepté (octets) : au-delà, ce n'en est pas un. */
    public const MAX_TOKEN_BYTES = 16384;

    private const AUTHORITY = 'https://login.microsoftonline.com';

    /** Lecture HTTP de substitution (tests) : fn(string $url): ?string. */
    private static $fetcher = null;

    /** Dossier du cache de substitution (tests). */
    private static ?string $cacheDir = null;

    // ================================================================ Formes pures

    /** Un identifiant d'annuaire ou d'application est un GUID. */
    public static function isGuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** Ressemble à un JWT (trois segments base64url) — ne dit rien de sa validité. */
    public static function looksLikeJwt(string $value): bool
    {
        return strlen($value) <= self::MAX_TOKEN_BYTES
            && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $value) === 1;
    }

    public static function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $value) !== 1) {
            return null;
        }
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $out = base64_decode($padded, true);
        return $out === false ? null : $out;
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Émetteurs acceptés pour un annuaire : v2.0 d'abord, v1.0 ensuite. */
    public static function issuersFor(string $tenantId): array
    {
        $tenant = strtolower($tenantId);
        return [
            self::AUTHORITY . '/' . $tenant . '/v2.0',
            'https://sts.windows.net/' . $tenant . '/',
        ];
    }

    /** Audiences acceptées pour une application. */
    public static function audiencesFor(string $clientId): array
    {
        $id = strtolower($clientId);
        return [$id, 'api://' . $id];
    }

    /**
     * Vérifie un jeton et rend ses claims. Pure : $keyFor(kid) rend la clé
     * publique (ressource OpenSSL, PEM) ou null ; $now l'heure.
     *
     * @param array{tenant:string, client_id:string, scope:string} $expect
     * @param callable(string): mixed $keyFor
     * @return array<string, mixed>
     * @throws EntraJwtException
     */
    public static function verify(string $jwt, array $expect, callable $keyFor, int $now): array
    {
        if (!self::looksLikeJwt($jwt)) {
            throw new EntraJwtException('malformed');
        }
        [$h64, $p64, $s64] = explode('.', $jwt);
        $header  = json_decode((string)self::base64UrlDecode($h64), true);
        $claims  = json_decode((string)self::base64UrlDecode($p64), true);
        $sig     = self::base64UrlDecode($s64);
        if (!is_array($header) || !is_array($claims) || $sig === null || $sig === '') {
            throw new EntraJwtException('malformed');
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new EntraJwtException('algorithm');
        }
        $kid = (string)($header['kid'] ?? '');
        if ($kid === '' || preg_match('/^[A-Za-z0-9_\-=.]{1,200}$/', $kid) !== 1) {
            throw new EntraJwtException('malformed');
        }

        $key = $keyFor($kid);
        if ($key === null || $key === false) {
            throw new EntraJwtException('unknown_key');
        }
        $ok = openssl_verify($h64 . '.' . $p64, $sig, $key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new EntraJwtException('signature');
        }

        $tenant = strtolower((string)($expect['tenant'] ?? ''));
        if (!self::isGuid($tenant)) {
            throw new EntraJwtException('config');
        }
        if (!in_array((string)($claims['iss'] ?? ''), self::issuersFor($tenant), true)
            || strtolower((string)($claims['tid'] ?? '')) !== $tenant) {
            throw new EntraJwtException('issuer');
        }

        $clientId = strtolower((string)($expect['client_id'] ?? ''));
        $aud = $claims['aud'] ?? '';
        $auds = is_array($aud) ? array_map('strval', $aud) : [(string)$aud];
        if ($clientId === '' || array_intersect(array_map('strtolower', $auds), self::audiencesFor($clientId)) === []) {
            throw new EntraJwtException('audience');
        }

        $scope = (string)($expect['scope'] ?? '');
        $scp = preg_split('/\s+/', trim((string)($claims['scp'] ?? ''))) ?: [];
        if ($scope === '' || !in_array($scope, $scp, true)) {
            throw new EntraJwtException('scope');
        }

        $exp = $claims['exp'] ?? null;
        if (!is_numeric($exp) || (int)$exp + self::LEEWAY_SEC < $now) {
            throw new EntraJwtException('expired');
        }
        $nbf = $claims['nbf'] ?? null;
        if (is_numeric($nbf) && (int)$nbf - self::LEEWAY_SEC > $now) {
            throw new EntraJwtException('not_yet_valid');
        }

        $oid = (string)($claims['oid'] ?? '');
        if ($oid === '' || preg_match('/^[0-9a-f-]{8,64}$/i', $oid) !== 1) {
            throw new EntraJwtException('subject');
        }

        return $claims;
    }

    /**
     * Adresse de la personne d'après les claims (v2.0 : `preferred_username`,
     * parfois `email` ; v1.0 : `upn`, `unique_name`). Vide si aucune n'est une
     * adresse : une identité sans adresse ne se rattache que par son `oid`.
     */
    public static function emailFrom(array $claims): string
    {
        foreach (['email', 'preferred_username', 'upn', 'unique_name'] as $k) {
            $v = strtolower(trim((string)($claims[$k] ?? '')));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return $v;
            }
        }
        return '';
    }

    /** Sujet d'identité, le même que le SSO Microsoft : `{tid}.{oid}`. */
    public static function subjectFrom(array $claims): string
    {
        return strtolower((string)($claims['tid'] ?? '')) . '.' . strtolower((string)($claims['oid'] ?? ''));
    }

    /**
     * Clé publique PEM d'une JWK RSA : le certificat `x5c` s'il est là
     * (Microsoft le fournit), sinon le module et l'exposant (`n`, `e`).
     */
    public static function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA') {
            return null;
        }
        $x5c = $jwk['x5c'][0] ?? null;
        if (is_string($x5c) && preg_match('/^[A-Za-z0-9+\/=]+$/', $x5c) === 1) {
            $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($x5c, 64, "\n") . "-----END CERTIFICATE-----\n";
            $key = openssl_pkey_get_public($cert);
            if ($key !== false) {
                $details = openssl_pkey_get_details($key);
                if (is_array($details) && isset($details['key'])) {
                    return (string)$details['key'];
                }
            }
        }
        $n = self::base64UrlDecode((string)($jwk['n'] ?? ''));
        $e = self::base64UrlDecode((string)($jwk['e'] ?? ''));
        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }
        $rsa = self::derSequence(self::derInteger($n) . self::derInteger($e));
        $algo = self::derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
        $spki = self::derSequence($algo . "\x03" . self::derLength(strlen($rsa) + 1) . "\x00" . $rsa);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    // ================================================================ Clés (JWKS)

    /**
     * Vérifie un jeton contre l'annuaire configuré, clés lues (et gardées) sur
     * Microsoft. @throws EntraJwtException
     */
    public static function verifyLive(string $jwt, string $tenant, string $clientId, string $scope, ?int $now = null): array
    {
        $now ??= time();
        $keyFor = static function (string $kid) use ($tenant, $now) {
            $pem = self::keyFor($tenant, $kid, $now);
            return $pem === null ? null : openssl_pkey_get_public($pem);
        };
        return self::verify($jwt, ['tenant' => $tenant, 'client_id' => $clientId, 'scope' => $scope], $keyFor, $now);
    }

    /** Clé publique PEM du `kid` pour cet annuaire, null si introuvable. */
    public static function keyFor(string $tenant, string $kid, int $now): ?string
    {
        if (!self::isGuid($tenant)) {
            return null;
        }
        $cache = self::readCache($tenant);
        $keys = $cache['keys'] ?? [];
        $fresh = $now - (int)($cache['fetched_at'] ?? 0) < self::JWKS_TTL_SEC;
        if ($fresh && isset($keys[$kid])) {
            return (string)$keys[$kid];
        }
        // Clé inconnue ou cache périmé : une relecture, bornée dans le temps —
        // un jeton forgé avec un kid inventé ne doit pas faire appeler
        // Microsoft à chaque requête.
        $lastTry = (int)($cache['tried_at'] ?? 0);
        if ($fresh && $now - $lastTry < self::JWKS_REFRESH_MIN_SEC) {
            return null;
        }
        $fetched = self::fetchKeys($tenant);
        if ($fetched === null) {
            self::writeCache($tenant, $keys, (int)($cache['fetched_at'] ?? 0), $now);
            // Microsoft injoignable : les clés connues servent encore.
            return isset($keys[$kid]) ? (string)$keys[$kid] : null;
        }
        self::writeCache($tenant, $fetched, $now, $now);
        return $fetched[$kid] ?? null;
    }

    /**
     * Relit les clés de l'annuaire tout de suite (bouton « Tester » de
     * l'administration) et rend leur nombre : 0 = annuaire introuvable ou
     * Microsoft injoignable.
     */
    public static function probe(string $tenant): int
    {
        if (!self::isGuid($tenant)) {
            return 0;
        }
        $now = time();
        $keys = self::fetchKeys($tenant);
        if ($keys === null) {
            return 0;
        }
        self::writeCache($tenant, $keys, $now, $now);
        return count($keys);
    }

    /** @return array<string, string>|null kid → PEM */
    public static function keysFromJwks(string $json): ?array
    {
        $doc = json_decode($json, true);
        if (!is_array($doc) || !is_array($doc['keys'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($doc['keys'] as $jwk) {
            if (!is_array($jwk) || ($jwk['use'] ?? 'sig') !== 'sig') {
                continue;
            }
            $kid = (string)($jwk['kid'] ?? '');
            $pem = $kid !== '' ? self::jwkToPem($jwk) : null;
            if ($pem !== null) {
                $out[$kid] = $pem;
            }
        }
        return $out;
    }

    private static function fetchKeys(string $tenant): ?array
    {
        $url = self::AUTHORITY . '/' . strtolower($tenant) . '/discovery/v2.0/keys';
        try {
            if (self::$fetcher !== null) {
                $body = (self::$fetcher)($url);
            } else {
                $ctx = stream_context_create(['http' => [
                    'method'        => 'GET',
                    'timeout'       => 8,
                    'ignore_errors' => true,
                    'header'        => "Accept: application/json\r\n",
                ]]);
                $body = @file_get_contents($url, false, $ctx);
                $status = LlmAdapterHelpers::extractHttpStatus($http_response_header ?? null);
                if ($status !== 200) {
                    $body = null;
                }
            }
        } catch (\Throwable $e) {
            $body = null;
        }
        if (!is_string($body) || $body === '') {
            error_log('[Entra] clés de l’annuaire ' . $tenant . ' illisibles');
            return null;
        }
        return self::keysFromJwks($body);
    }

    private static function cachePath(string $tenant): string
    {
        $dir = self::$cacheDir ?? (ROOT . '/storage/cache');
        return rtrim($dir, '/') . '/entra-jwks-' . strtolower($tenant) . '.json';
    }

    private static function readCache(string $tenant): array
    {
        $raw = @file_get_contents(self::cachePath($tenant));
        $doc = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($doc) ? $doc : [];
    }

    private static function writeCache(string $tenant, array $keys, int $fetchedAt, int $triedAt): void
    {
        $path = self::cachePath($tenant);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = @tempnam($dir, 'jwks');
        if ($tmp === false) {
            return;
        }
        $json = (string)json_encode(['fetched_at' => $fetchedAt, 'tried_at' => $triedAt, 'keys' => $keys], JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp, $json) !== false) {
            @chmod($tmp, 0664);
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    }

    // ================================================================ Tests

    /** @param null|callable(string): ?string $fetcher */
    public static function overrideFetcher(?callable $fetcher): void
    {
        self::$fetcher = $fetcher;
    }

    public static function overrideCacheDir(?string $dir): void
    {
        self::$cacheDir = $dir;
    }
}

/**
 * Jeton refusé. `reason` est un code stable (jamais montré tel quel) :
 * malformed, algorithm, unknown_key, signature, config, issuer, audience,
 * scope, expired, not_yet_valid, subject.
 */
final class EntraJwtException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Jeton Entra refusé : ' . $reason);
    }
}
