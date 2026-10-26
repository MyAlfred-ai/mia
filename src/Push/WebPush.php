<?php

declare(strict_types=1);

/**
 * Notifications sur les appareils (Web Push) — SOURCE UNIQUE du protocole.
 *
 * Trois normes, toutes implémentées ici sans bibliothèque (openssl et
 * hash_hkdf suffisent) :
 *  - RFC 8291 + RFC 8188 : le message est CHIFFRÉ pour l'appareil (ECDH P-256,
 *    HKDF-SHA-256, AES-128-GCM, codage `aes128gcm`). Le service de
 *    notification (Google, Apple, Mozilla, Microsoft) ne lit rien de ce
 *    qu'il transporte. Vecteur de l'annexe A rejoué par les tests.
 *  - RFC 8292 (VAPID) : chaque envoi est signé (jeton ES256) par la paire de
 *    clés de l'instance ; un appareil inscrit avec notre clé publique ne
 *    reçoit que des messages signés par notre clé privée.
 *  - RFC 8030 : un POST par appareil, `TTL`, `Urgency`, `Topic`.
 *
 * L'adresse d'envoi vient du NAVIGATEUR de l'utilisateur : sans contrôle, ce
 * serait une porte ouverte pour faire écrire le serveur n'importe où (réseau
 * interne compris). `isAllowedEndpoint()` = liste blanche des services de
 * notification des navigateurs, en https sur le port 443 ; l'envoi ne suit
 * aucune redirection et ne passe par aucun proxy.
 */
final class WebPush
{
    /** Un message non distribué en un jour n'a plus de sens (« le résultat est arrivé »). */
    public const TTL = 86400;

    /** Taille de bloc annoncée dans l'en-tête aes128gcm : un seul bloc, toujours. */
    private const RECORD_SIZE = 4096;

    /** Charge utile chiffrable : 4096 octets au plus, en-tête et étiquette compris. */
    public const MAX_PAYLOAD = 3000;

    /** Clés de l'instance (Settings). La clé privée est chiffrée (Encryption). */
    public const SETTING_PUBLIC  = 'push_vapid_public';
    public const SETTING_PRIVATE = 'push_vapid_private_enc';

    /**
     * Services de notification des navigateurs : Chrome, Edge (Chromium sur
     * Android), Opera, Samsung (Google) ; Firefox (Mozilla) ; Safari (Apple) ;
     * Edge sur Windows (Microsoft). Hôtes EXACTS, puis suffixes d'un domaine
     * qui leur appartient.
     */
    public const ALLOWED_HOSTS    = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];
    public const ALLOWED_SUFFIXES = ['.push.apple.com', '.notify.windows.com'];

    /** Délais d'un envoi : un appareil lent ne retient pas le worker. */
    private const CONNECT_TIMEOUT = 4;
    private const TIMEOUT         = 8;

    /** En-tête DER d'une clé publique P-256 (SubjectPublicKeyInfo, point non compressé). */
    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Transport de substitution (tests) : reçoit la liste des requêtes
     * (`url`, `headers`, `body`) et rend un statut HTTP par requête (0 = réseau).
     *
     * @var null|callable(list<array{url: string, headers: list<string>, body: string}>): list<int>
     */
    private static $transport = null;

    public static function overrideTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    // ==================================================================
    // Formes pures
    // ==================================================================

    public static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Décode le base64url (et le base64 ordinaire) ; '' si la chaîne est illisible. */
    public static function b64urlDecode(string $text): string
    {
        $text = strtr(trim($text), '-_', '+/');
        if ($text === '' || preg_match('#^[A-Za-z0-9+/]*=*$#', $text) !== 1) {
            return '';
        }
        $decoded = base64_decode(str_pad($text, (int)(ceil(strlen($text) / 4) * 4), '=', STR_PAD_RIGHT), true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * L'adresse d'envoi est-elle celle d'un service de notification connu ?
     * https, port 443, sans identifiants, hôte de la liste blanche. Pure.
     */
    public static function isAllowedEndpoint(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            return false;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || str_contains($host, '..')) {
            return false;
        }
        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }
        foreach (self::ALLOWED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $host) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Origine d'une adresse d'envoi : l'audience (`aud`) du jeton VAPID. Pure. */
    public static function audience(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        return strtolower((string)($parts['scheme'] ?? 'https')) . '://' . strtolower((string)($parts['host'] ?? ''));
    }

    /**
     * Clés d'un appareil, telles que le navigateur les donne (base64url) :
     * point P-256 non compressé de 65 octets, secret d'authentification de 16.
     * Null si l'une est illisible. Pure.
     *
     * @return array{p256dh: string, auth: string}|null octets bruts
     */
    public static function decodeKeys(string $p256dh, string $auth): ?array
    {
        $point  = self::b64urlDecode($p256dh);
        $secret = self::b64urlDecode($auth);
        if (strlen($point) !== 65 || $point[0] !== "\x04" || strlen($secret) !== 16) {
            return null;
        }
        return ['p256dh' => $point, 'auth' => $secret];
    }

    /**
     * Signature ECDSA DER (SEQUENCE de deux INTEGER) → 64 octets r‖s, la forme
     * qu'exige JWS (ES256). openssl rend du DER. Pure.
     */
    public static function derToRaw(string $der): string
    {
        $pos = 0;
        $read = static function () use ($der, &$pos): int {
            $len = ord($der[$pos++] ?? "\0");
            if ($len & 0x80) {
                $n = $len & 0x7F;
                $len = 0;
                for ($i = 0; $i < $n; $i++) {
                    $len = ($len << 8) | ord($der[$pos++] ?? "\0");
                }
            }
            return $len;
        };
        if (($der[$pos++] ?? '') !== "\x30") {
            throw new \RuntimeException('Signature ECDSA illisible.');
        }
        $read();
        $out = '';
        for ($k = 0; $k < 2; $k++) {
            if (($der[$pos++] ?? '') !== "\x02") {
                throw new \RuntimeException('Signature ECDSA illisible.');
            }
            $len = $read();
            $int = ltrim(substr($der, $pos, $len), "\0");
            $pos += $len;
            if (strlen($int) > 32) {
                throw new \RuntimeException('Signature ECDSA illisible.');
            }
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** Clé publique P-256 (point brut de 65 octets) au format PEM. Pure. */
    public static function publicKeyPem(string $point): string
    {
        $der = hex2bin(self::SPKI_P256_PREFIX) . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Clé privée P-256 (scalaire de 32 octets + point public) au format PEM (SEC 1). Pure. */
    public static function privateKeyPem(string $d, string $point): string
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20" . str_pad($d, 32, "\0", STR_PAD_LEFT)
             . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
             . "\xa1\x44\x03\x42\x00" . $point;
        return "-----BEGIN " . "EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END " . "EC PRIVATE KEY-----\n";
    }

    // ==================================================================
    // Clés et chiffrement
    // ==================================================================

    /**
     * Nouvelle paire P-256.
     *
     * @return array{private_pem: string, public: string} point public brut (65 octets)
     */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Génération de clé impossible : ' . (string)openssl_error_string());
        }
        return ['private_pem' => (string)$pem, 'public' => self::publicPoint($key)];
    }

    /** Point public non compressé (65 octets) d'une clé EC. */
    private static function publicPoint(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        $x = (string)($details['ec']['x'] ?? '');
        $y = (string)($details['ec']['y'] ?? '');
        if ($x === '' || $y === '') {
            throw new \RuntimeException('Clé EC illisible.');
        }
        return "\x04" . str_pad($x, 32, "\0", STR_PAD_LEFT) . str_pad($y, 32, "\0", STR_PAD_LEFT);
    }

    /**
     * Chiffre un message pour un appareil (RFC 8291, codage aes128gcm) et rend
     * le corps de la requête : en-tête (sel, taille de bloc, clé publique
     * éphémère) puis texte chiffré et étiquette.
     *
     * `$sender` et `$salt` ne servent qu'aux tests (vecteur de l'annexe A) :
     * en usage, la paire éphémère et le sel sont neufs à chaque message.
     *
     * @param array{private_pem: string, public: string}|null $sender
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?array $sender = null, ?string $salt = null): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('Clés d’appareil invalides.');
        }
        $sender ??= self::generateKeyPair();
        $salt   ??= random_bytes(16);

        $private = openssl_pkey_get_private($sender['private_pem']);
        $peer    = openssl_pkey_get_public(self::publicKeyPem($uaPublic));
        if ($private === false || $peer === false) {
            throw new \RuntimeException('Clé de chiffrement illisible : ' . (string)openssl_error_string());
        }
        $ecdh = openssl_pkey_derive($peer, $private);
        if ($ecdh === false || strlen($ecdh) !== 32) {
            throw new \RuntimeException('Échange de clés impossible : ' . (string)openssl_error_string());
        }

        // RFC 8291 §3.3 : le secret partagé, mêlé au secret d'authentification.
        $ikm   = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $sender['public'], $authSecret);
        // RFC 8188 §2.2 : clé et nonce du contenu.
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Un seul bloc, donc le dernier : délimiteur 0x02, sans bourrage.
        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Chiffrement impossible : ' . (string)openssl_error_string());
        }
        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($sender['public'])) . $sender['public'] . $cipher . $tag;
    }

    /**
     * Jeton VAPID (JWT ES256) pour une audience. `exp` : 24 h au plus (RFC
     * 8292) — on prend 12 h.
     */
    public static function vapidToken(string $audience, int $expires, string $subject, string $privatePem): string
    {
        $header = self::b64urlEncode('{"typ":"JWT","alg":"ES256"}');
        $claims = self::b64urlEncode((string)json_encode(['aud' => $audience, 'exp' => $expires, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $input  = $header . '.' . $claims;
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signature VAPID impossible : ' . (string)openssl_error_string());
        }
        return $input . '.' . self::b64urlEncode(self::derToRaw((string)$der));
    }

    /**
     * Contact déclaré aux services de notification (`sub` du jeton) :
     * l'adresse de l'instance si elle est en https, sinon une adresse
     * électronique de forme valide — Apple refuse un `sub` d'une autre forme.
     */
    public static function subject(): string
    {
        $url = rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/');
        if (str_starts_with($url, 'https://')) {
            return $url;
        }
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: 'localhost');
        return 'mailto:notifications@' . (str_contains($host, '.') ? $host : $host . '.invalid');
    }

    /**
     * Paire VAPID de l'instance, créée au premier besoin. Créée UNE fois même
     * si deux processus la demandent ensemble (verrou MySQL). Une paire
     * recréée (clé perdue, illisible, restauration ailleurs) rend caduques
     * toutes les inscriptions : elles sont effacées, chacun réactivera.
     *
     * @return array{public: string, private_pem: string} public = base64url du point
     */
    public static function keys(): array
    {
        $keys = self::storedKeys();
        if ($keys !== null) {
            return $keys;
        }
        $db = Database::getInstance();
        $locked = (int)$db->query("SELECT GET_LOCK('mia_vapid_keys', 10)")->fetchColumn() === 1;
        try {
            Settings::refresh();
            $keys = self::storedKeys();
            if ($keys !== null) {
                return $keys;
            }
            $pair = self::generateKeyPair();
            Settings::set(self::SETTING_PRIVATE, Encryption::encrypt($pair['private_pem']));
            Settings::set(self::SETTING_PUBLIC, self::b64urlEncode($pair['public']));
            PushSubscription::purgeAll();
            return ['public' => self::b64urlEncode($pair['public']), 'private_pem' => $pair['private_pem']];
        } finally {
            if ($locked) {
                $db->query("SELECT RELEASE_LOCK('mia_vapid_keys')");
            }
        }
    }

    /** Clé publique de l'instance (base64url), pour l'inscription d'un navigateur. */
    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    /** @return array{public: string, private_pem: string}|null */
    private static function storedKeys(): ?array
    {
        $public = (string)Settings::get(self::SETTING_PUBLIC, '');
        $enc    = (string)Settings::get(self::SETTING_PRIVATE, '');
        if ($public === '' || $enc === '') {
            return null;
        }
        try {
            $pem = Encryption::decrypt($enc);
        } catch (\Throwable) {
            return null;
        }
        $point = self::b64urlDecode($public);
        $key = $pem !== '' ? openssl_pkey_get_private($pem) : false;
        if ($key === false || strlen($point) !== 65 || !hash_equals(self::publicPoint($key), $point)) {
            return null;
        }
        return ['public' => $public, 'private_pem' => $pem];
    }

    // ==================================================================
    // Envoi
    // ==================================================================

    /**
     * Envoie un message à des appareils, EN PARALLÈLE, et rend le statut HTTP
     * de chacun (clé = identifiant d'inscription ; 0 = réseau ou refus local).
     * Ne lève jamais : une notification ne fait échouer personne.
     *
     * @param list<array{id: int, endpoint: string, p256dh: string, auth: string}> $subscriptions
     * @param array{title: string, body: string, url?: string, tag?: string} $message
     * @return array<int, int>
     */
    public static function send(array $subscriptions, array $message, string $topic = ''): array
    {
        $statuses = [];
        try {
            $keys = self::keys();
        } catch (\Throwable $e) {
            error_log('[Push] clés indisponibles : ' . $e->getMessage());
            return array_fill_keys(array_map(static fn(array $s): int => (int)$s['id'], $subscriptions), 0);
        }
        $payload = (string)json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($payload) > self::MAX_PAYLOAD) {
            $message['body'] = mb_strcut((string)($message['body'] ?? ''), 0, 400);
            $payload = (string)json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $topic = preg_replace('/[^A-Za-z0-9_-]/', '', $topic) ?? '';
        $exp = time() + 12 * 3600;

        $requests = [];
        $ids = [];
        foreach ($subscriptions as $sub) {
            $id = (int)$sub['id'];
            $statuses[$id] = 0;
            $endpoint = (string)$sub['endpoint'];
            $ua = self::decodeKeys((string)$sub['p256dh'], (string)$sub['auth']);
            if ($ua === null || !self::isAllowedEndpoint($endpoint)) {
                continue;
            }
            try {
                $body  = self::encrypt($payload, $ua['p256dh'], $ua['auth']);
                $token = self::vapidToken(self::audience($endpoint), $exp, self::subject(), $keys['private_pem']);
            } catch (\Throwable $e) {
                error_log('[Push] message non préparé pour #' . $id . ' : ' . $e->getMessage());
                continue;
            }
            $headers = [
                'TTL: ' . self::TTL,
                'Urgency: normal',
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Authorization: vapid t=' . $token . ', k=' . $keys['public'],
            ];
            if ($topic !== '') {
                $headers[] = 'Topic: ' . substr($topic, 0, 32);
            }
            $requests[] = ['url' => $endpoint, 'headers' => $headers, 'body' => $body];
            $ids[] = $id;
        }
        if ($requests === []) {
            return $statuses;
        }

        $codes = self::$transport !== null ? (self::$transport)($requests) : self::curlMany($requests);
        foreach ($ids as $i => $id) {
            $statuses[$id] = (int)($codes[$i] ?? 0);
        }
        return $statuses;
    }

    /**
     * Les requêtes, toutes à la fois : un appareil injoignable ne retarde pas
     * les autres. Aucune redirection, aucun proxy, https seulement.
     *
     * @param list<array{url: string, headers: list<string>, body: string}> $requests
     * @return list<int>
     */
    private static function curlMany(array $requests): array
    {
        if (!function_exists('curl_multi_init')) {
            return array_fill(0, count($requests), 0);
        }
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => $req) {
            $ch = curl_init($req['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST            => true,
                CURLOPT_POSTFIELDS      => $req['body'],
                CURLOPT_HTTPHEADER      => $req['headers'],
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_FOLLOWLOCATION  => false,
                CURLOPT_PROXY           => '',
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT         => self::TIMEOUT,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $codes = [];
        foreach ($handles as $i => $ch) {
            $codes[$i] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);
        return $codes;
    }

    /**
     * Ce que dit un statut du service de notification de l'inscription. Pure.
     *  - `ok` : distribué (201, ou 200/202 selon les services) ;
     *  - `gone` : l'inscription n'existe plus (404, 410) — à effacer ;
     *  - `retry` : tout le reste (réseau, 429, 5xx, mais aussi 400/401/403) —
     *    compté. Un refus du JETON n'efface rien d'emblée : un défaut de notre
     *    côté effacerait sinon les inscriptions de tout le monde au premier
     *    envoi. PushSubscription retire une inscription après une série
     *    d'échecs.
     */
    public static function outcome(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status === 404 || $status === 410) {
            return 'gone';
        }
        return 'retry';
    }
}
