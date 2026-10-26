<?php

declare(strict_types=1);

/**
 * Lecture d'une page web PUBLIQUE à la demande du modèle (outil `lire_page_web`)
 * — SOURCE UNIQUE de la protection contre la falsification de requête côté
 * serveur (SSRF).
 *
 * LE PROBLÈME RÉSOLU. La recherche Internet des fournisseurs trouve des pages,
 * mais une adresse collée par l'utilisateur (« résume cet article ») ou une
 * page trouvée qu'il faudrait lire EN ENTIER n'avait aucun chemin : le modèle
 * répondait d'après un extrait, ou inventait.
 *
 * LE RISQUE. Un outil qui fait télécharger au SERVEUR l'adresse choisie par un
 * modèle est la porte d'entrée classique vers le réseau interne : base de
 * données, Qdrant, métadonnées du cloud (169.254.169.254), interface d'un
 * routeur. Le modèle lui-même peut avoir été piloté par une page ou un email
 * lus juste avant. D'où cinq verrous, tous testés :
 *
 *   1. http et https seulement, ports 80 et 443 seulement, aucun identifiant
 *      dans l'adresse ;
 *   2. nom d'hôte refusé s'il est interne par construction (`localhost`, nom
 *      sans point, `.local`, `.internal`…) ou si son dernier segment est
 *      numérique (les écritures exotiques d'une IP : `0x7f.1`, `0177.0.0.1`) ;
 *   3. TOUTES les adresses que le nom résout doivent être publiques (plages
 *      privées, réservées, de lien local, CGNAT, multidiffusion, IPv6 locales,
 *      IPv4 embarquée dans une IPv6 — vérifiée pour ce qu'elle cache) ;
 *   4. la connexion est ÉPINGLÉE sur l'adresse vérifiée (CURLOPT_RESOLVE) et
 *      tout proxy est écarté : entre la vérification et la connexion, une
 *      seconde résolution DNS ne peut pas substituer une adresse interne ;
 *   5. les redirections ne sont JAMAIS suivies par curl : chaque saut est
 *      revalidé (verrous 1 à 4), quatre au plus.
 *
 * Bornes : 5 Mo lus au plus, 5 s pour se connecter, 15 s en tout. Formats : HTML
 * (texte principal extrait, `<main>` ou `<article>` d'abord), texte, PDF et
 * documents bureautiques (via FileExtractor). Lecture paginée (TextPager).
 */
final class WebPageReader
{
    public const MAX_BYTES       = 5 * 1024 * 1024;
    public const MAX_REDIRECTS   = 4;
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT         = 15;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; MIA/1.0; +lecture à la demande d\'un utilisateur)';

    /** En-tête Accept d'une lecture de page. */
    private const ACCEPT_TEXT = 'text/html,application/xhtml+xml,application/pdf;q=0.9,text/plain;q=0.8,*/*;q=0.5';

    /** Plages IPv4 refusées : [réseau, longueur du préfixe]. */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],        // « ce réseau »
        ['10.0.0.0', 8],       // privé
        ['100.64.0.0', 10],    // CGNAT
        ['127.0.0.0', 8],      // bouclage
        ['169.254.0.0', 16],   // lien local (métadonnées du cloud)
        ['172.16.0.0', 12],    // privé
        ['192.0.0.0', 24],     // IETF
        ['192.0.2.0', 24],     // documentation
        ['192.88.99.0', 24],   // relais 6to4
        ['192.168.0.0', 16],   // privé
        ['198.18.0.0', 15],    // tests de performance
        ['198.51.100.0', 24],  // documentation
        ['203.0.113.0', 24],   // documentation
        ['224.0.0.0', 4],      // multidiffusion
        ['240.0.0.0', 4],      // réservé, diffusion
    ];

    /** Plages IPv6 refusées. Les IPv4 embarquées sont vérifiées à part. */
    private const BLOCKED_V6 = [
        ['::', 96],            // non spécifiée, bouclage, IPv4 « compatible »
        ['100::', 64],         // rebut
        ['2001::', 32],        // Teredo
        ['2001:db8::', 32],    // documentation
        ['2002::', 16],        // 6to4
        ['fc00::', 7],         // adresses locales uniques
        ['fe80::', 10],        // lien local
        ['fec0::', 10],        // site local (obsolète)
        ['ff00::', 8],         // multidiffusion
    ];

    /** Suffixes de noms internes par construction. */
    private const INTERNAL_SUFFIXES = [
        'localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp', 'localdomain', 'home.arpa', 'test', 'invalid',
    ];

    /** Formats lus comme du texte brut. */
    private const TEXT_MIMES = [
        'text/plain', 'text/markdown', 'text/csv', 'text/xml', 'application/xml',
        'application/json', 'application/ld+json', 'text/calendar',
    ];

    /** Documents passés à FileExtractor. */
    private const DOCUMENT_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/rtf',
    ];

    /**
     * @param \Closure(array, string, string): array|null $transport (cible validée, IP épinglée, en-tête Accept) → {status, headers, body, truncated}
     * @param \Closure(string): list<string>|null $resolver  nom d'hôte → adresses IP
     */
    public function __construct(
        private readonly ?\Closure $transport = null,
        private readonly ?\Closure $resolver = null,
    ) {
    }

    /**
     * Lit la page et rend la partie demandée de son texte.
     *
     * @return array{url:string, titre:string, type:string, contenu:string, partie:int, parties:int, tronque:bool, suite?:string}
     * @throws \RuntimeException message FR
     */
    public function read(string $url, int $maxChars, int $part = 1): array
    {
        [$target, $resp] = $this->fetch($url, self::ACCEPT_TEXT);

        [$mime, $charset] = self::contentType((string)($resp['headers']['content-type'] ?? ''));
        $body      = (string)($resp['body'] ?? '');
        $truncated = !empty($resp['truncated']);

        if ($mime === 'text/html' || $mime === 'application/xhtml+xml' || ($mime === '' && self::looksLikeHtml($body))) {
            $page  = self::htmlToReadable($body, $charset);
            $titre = $page['titre'];
            $texte = $page['texte'];
            $type  = 'page web';
        } elseif (in_array($mime, self::TEXT_MIMES, true) || str_starts_with($mime, 'text/')) {
            $titre = '';
            $texte = trim(self::toUtf8($body, $charset));
            $type  = 'texte';
        } elseif (in_array($mime, self::DOCUMENT_MIMES, true)) {
            if ($truncated) {
                throw new \RuntimeException('Document trop volumineux pour être lu ('
                    . (int)(self::MAX_BYTES / 1024 / 1024) . ' Mo au plus).');
            }
            $titre = '';
            $texte = self::extractDocument($body, $mime);
            $type  = $mime === 'application/pdf' ? 'PDF' : 'document';
        } else {
            throw new \RuntimeException('Ce lien mène à un fichier « ' . ($mime !== '' ? $mime : 'de type inconnu')
                . ' », qui ne se lit pas comme du texte.');
        }

        if (trim($texte) === '') {
            throw new \RuntimeException('Aucun texte lisible sur cette page (page construite en JavaScript, '
                . 'ou contenu réservé). Essaie plutôt la recherche Internet.');
        }
        if ($truncated) {
            $texte .= "\n\n[… page coupée : au-delà de " . (int)(self::MAX_BYTES / 1024 / 1024) . ' Mo]';
        }

        return ['url' => $target['url'], 'titre' => $titre, 'type' => $type]
            + TextPager::page($texte, $maxChars, $part);
    }

    /**
     * Télécharge une ressource PUBLIQUE telle quelle, sans l'interpréter (photo
     * de profil collée par son adresse) — à travers les MÊMES verrous que
     * `read()` : une adresse collée par un utilisateur est aussi dangereuse
     * qu'une adresse choisie par un modèle. Au plus `MAX_BYTES` lus
     * (`truncated` le signale au-delà).
     *
     * @return array{url:string, mime:string, body:string, truncated:bool}
     * @throws \RuntimeException message FR
     */
    public function download(string $url, string $accept = '*/*'): array
    {
        [$target, $resp] = $this->fetch($url, $accept);
        [$mime] = self::contentType((string)($resp['headers']['content-type'] ?? ''));

        return [
            'url'       => $target['url'],
            'mime'      => $mime,
            'body'      => (string)($resp['body'] ?? ''),
            'truncated' => !empty($resp['truncated']),
        ];
    }

    /**
     * Requête vérifiée, redirections revalidées une à une, statut contrôlé :
     * le chemin COMMUN de `read()` et `download()`.
     *
     * @return array{0: array{url:string, scheme:string, host:string, port:int, ip:?string}, 1: array}
     * @throws \RuntimeException message FR
     */
    private function fetch(string $url, string $accept): array
    {
        $target = self::validateUrl($url);
        for ($hop = 0; ; $hop++) {
            $ip   = $this->pinnedIp($target['host'], $target['ip']);
            $resp = $this->transport !== null
                ? ($this->transport)($target, $ip, $accept)
                : $this->curlFetch($target, $ip, $accept);
            $status = (int)($resp['status'] ?? 0);
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException('Trop de redirections : la page n\'a pas pu être atteinte.');
                }
                $location = trim((string)($resp['headers']['location'] ?? ''));
                if ($location === '') {
                    throw new \RuntimeException('Le site a répondu par une redirection sans destination.');
                }
                // Chaque saut repasse TOUS les verrous : une redirection vers
                // http://127.0.0.1/ est l'attaque la plus simple qui soit.
                $target = self::validateUrl(self::resolveRelative($target['url'], $location));
                continue;
            }
            break;
        }

        if ($status >= 400 || $status < 200) {
            throw new \RuntimeException(self::statusMessage($status));
        }
        return [$target, $resp];
    }

    // ===================== Verrous (purs, testés) =====================

    /**
     * Adresse validée (verrous 1 et 2). Une adresse collée sans schéma
     * (« exemple.fr/page ») est lue en https.
     *
     * @return array{url:string, scheme:string, host:string, port:int, ip:?string}
     * @throws \RuntimeException message FR
     */
    public static function validateUrl(string $raw): array
    {
        $raw = trim($raw, " \t\n\r\0\x0B<>\"'");
        if ($raw === '' || strlen($raw) > 2048) {
            throw new \RuntimeException('Adresse web invalide.');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) !== 1) {
            $raw = 'https://' . ltrim($raw, '/');
        }
        $p = parse_url($raw);
        if ($p === false || !isset($p['host']) || $p['host'] === '') {
            throw new \RuntimeException('Adresse web invalide : « ' . mb_substr($raw, 0, 120) . ' ».');
        }
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Seules les adresses http et https peuvent être lues.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \RuntimeException('Adresse refusée : elle contient des identifiants.');
        }
        $port = isset($p['port']) ? (int)$p['port'] : null;
        if ($port !== null && !in_array($port, [80, 443], true)) {
            throw new \RuntimeException('Adresse refusée : seuls les ports web standard (80 et 443) sont autorisés.');
        }

        $host = strtolower(rtrim((string)$p['host'], '.'));
        $ip   = null;
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ip = substr($host, 1, -1);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \RuntimeException('Adresse IPv6 invalide.');
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = $host;
        } else {
            if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
                $ascii = function_exists('idn_to_ascii') ? @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
                if (!is_string($ascii) || $ascii === '') {
                    throw new \RuntimeException('Nom de domaine invalide : « ' . $host . ' ».');
                }
                $host = strtolower($ascii);
            }
            if (preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host) !== 1) {
                throw new \RuntimeException('Adresse refusée : « ' . $host . ' » n\'est pas un nom de domaine public.');
            }
            if (self::isInternalName($host)) {
                throw new \RuntimeException('Adresse refusée : « ' . $host . ' » désigne un réseau interne.');
            }
        }
        if ($ip !== null && !self::isPublicIp($ip)) {
            throw new \RuntimeException('Adresse refusée : elle désigne un réseau interne ou réservé.');
        }

        $port ??= $scheme === 'https' ? 443 : 80;
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $url = $scheme . '://' . ($ip !== null && str_contains($ip, ':') ? '[' . $ip . ']' : $host)
            . ($default ? '' : ':' . $port)
            . (isset($p['path']) && $p['path'] !== '' ? $p['path'] : '/')
            . (isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '');

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ip];
    }

    /**
     * Le nom désigne-t-il un réseau interne par construction ? Dernier segment
     * numérique compris : un domaine de premier niveau ne l'est jamais, et
     * c'est la forme des écritures exotiques d'une IP (`0x7f.1`).
     */
    public static function isInternalName(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || !str_contains($host, '.')) {
            return true;
        }
        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }
        $last = substr($host, (int)strrpos($host, '.') + 1);
        return preg_match('/^(?:\d+|0x[0-9a-f]+)$/i', $last) === 1;
    }

    /** L'adresse IP est-elle publique (verrou 3) ? Toute adresse illisible est refusée. */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton(trim($ip, '[]'));
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            foreach (self::BLOCKED_V4 as [$net, $len]) {
                if (self::inCidr($bin, (string)inet_pton($net), $len)) {
                    return false;
                }
            }
            return true;
        }
        // IPv4 embarquée (::ffff:a.b.c.d, NAT64 64:ff9b::/96) : c'est elle qui
        // sera jointe, c'est donc elle qui décide.
        if (self::inCidr($bin, (string)inet_pton('::ffff:0:0'), 96) || self::inCidr($bin, (string)inet_pton('64:ff9b::'), 96)) {
            return self::isPublicIp((string)inet_ntop(substr($bin, 12)));
        }
        foreach (self::BLOCKED_V6 as [$net, $len]) {
            if (self::inCidr($bin, (string)inet_pton($net), $len)) {
                return false;
            }
        }
        return true;
    }

    /** Première adresse http(s) d'un texte (ponctuation finale retirée), ou null. */
    public static function firstUrl(string $text): ?string
    {
        if (preg_match('#\bhttps?://[^\s<>"\'`]+#iu', $text, $m) !== 1) {
            return null;
        }
        $url = rtrim($m[0], '.,;:!?»”’)]}');
        // Parenthèse fermante qui appartient à l'adresse (Wikipédia…) : gardée
        // si l'adresse en ouvre une.
        if (substr_count($url, '(') > substr_count($url, ')') && str_ends_with(rtrim($m[0], '.,;:!?»”’'), ')')) {
            $url .= ')';
        }
        return $url;
    }

    /** Adresse absolue d'une redirection, relative ou non. */
    public static function resolveRelative(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string)($b['path'] ?? '/');
        $dir  = substr($path, 0, (int)strrpos($path, '/') + 1);
        return $origin . ($dir !== '' ? $dir : '/') . $location;
    }

    /**
     * HTML → titre + texte lisible : bruit retiré (scripts, styles, menus,
     * pieds de page), zone principale préférée (`<main>`, `<article>`), titres
     * et listes gardés en Markdown léger.
     *
     * @return array{titre:string, texte:string}
     */
    public static function htmlToReadable(string $html, string $charset = ''): array
    {
        $html  = self::toUtf8($html, $charset !== '' ? $charset : self::metaCharset($html));
        $titre = '';
        if (preg_match('#<meta[^>]+property=["\']og:title["\'][^>]*content=["\']([^"\']{1,300})#i', $html, $m) === 1) {
            $titre = $m[1];
        } elseif (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1) {
            $titre = $m[1];
        }
        $titre = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($titre), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        $clean = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $clean = preg_replace('#<(script|style|noscript|template|svg|iframe|canvas|object|head)\b[^>]*>.*?</\1>#is', ' ', $clean) ?? $clean;

        foreach (['main', 'article'] as $tag) {
            if (preg_match('#<' . $tag . '\b[^>]*>(.*)</' . $tag . '>#is', $clean, $m) === 1) {
                $candidate = self::blocksToText($m[1]);
                if (mb_strlen($candidate) >= 400) {
                    return ['titre' => $titre, 'texte' => $candidate];
                }
            }
        }
        $body = preg_match('#<body\b[^>]*>(.*)</body>#is', $clean, $m) === 1 ? $m[1] : $clean;
        $body = preg_replace('#<(nav|footer|aside|form)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
        return ['titre' => $titre, 'texte' => self::blocksToText($body)];
    }

    // ===================== Interne =====================

    /** Adresse à laquelle se connecter : vérifiée, et la SEULE utilisée (verrous 3 et 4). */
    private function pinnedIp(string $host, ?string $literal): string
    {
        if ($literal !== null) {
            return $literal;
        }
        $ips = $this->resolver !== null ? ($this->resolver)($host) : self::resolve($host);
        $ips = array_values(array_filter(array_map('strval', (array)$ips), static fn(string $ip): bool => $ip !== ''));
        if ($ips === []) {
            throw new \RuntimeException('Site introuvable : « ' . $host . ' » ne correspond à aucune adresse.');
        }
        // UNE adresse interne parmi les réponses suffit à refuser : choisir la
        // « bonne » laisserait le DNS d'un attaquant décider du reste.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \RuntimeException('Adresse refusée : « ' . $host . ' » désigne un réseau interne ou réservé.');
            }
        }
        foreach ($ips as $ip) {
            if (!str_contains($ip, ':')) {
                return $ip;
            }
        }
        return $ips[0];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = [];
        $v4  = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = (string)$r['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Requête réelle : connexion épinglée, aucun proxy, aucune redirection
     * suivie, corps borné.
     *
     * @return array{status:int, headers:array<string,string>, body:string, truncated:bool}
     */
    private function curlFetch(array $target, string $ip, string $accept = self::ACCEPT_TEXT): array
    {
        $headers   = [];
        $body      = '';
        $truncated = false;
        $ch = curl_init($target['url']);
        curl_setopt_array($ch, [
            // Épinglage : le nom se connecte à l'adresse VÉRIFIÉE, jamais à une
            // seconde résolution. Une adresse IP littérale n'a rien à épingler.
            CURLOPT_RESOLVE        => $target['ip'] === null
                ? [$target['host'] . ':' . $target['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]
                : [],
            // Chaîne vide = AUCUN proxy, même si l'environnement en déclare un :
            // un proxy résoudrait le nom lui-même, l'épinglage ne vaudrait rien.
            CURLOPT_PROXY          => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: ' . $accept,
                'Accept-Language: fr,en;q=0.8',
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $line) === 1) {
                    $headers = [];
                } elseif (($pos = strpos($line, ':')) !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$truncated): int {
                $room = self::MAX_BYTES - strlen($body);
                if (strlen($chunk) > $room) {
                    $body     .= substr($chunk, 0, max(0, $room));
                    $truncated = true;
                    return 0; // interrompt le transfert
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok     = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);

        if ($ok === false && !$truncated) {
            throw new \RuntimeException(match ($errno) {
                CURLE_OPERATION_TIMEDOUT => 'Le site met trop de temps à répondre.',
                CURLE_COULDNT_CONNECT    => 'Impossible de joindre le site.',
                CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION => 'Connexion sécurisée refusée par le site (certificat invalide).',
                default                  => 'Le site n\'a pas pu être lu (erreur réseau ' . $errno . ').',
            });
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => $truncated];
    }

    private static function statusMessage(int $status): string
    {
        return match (true) {
            $status === 404 || $status === 410 => 'Page introuvable (HTTP ' . $status . ') : vérifie l\'adresse.',
            $status === 401 || $status === 403 => 'Cette page demande une connexion ou refuse l\'accès (HTTP ' . $status
                . ') : MIA ne lit que les pages publiques.',
            $status === 429                    => 'Le site limite les visites (HTTP 429) : réessaie plus tard.',
            $status >= 500                     => 'Le site est en erreur (HTTP ' . $status . ').',
            default                            => 'Le site a répondu HTTP ' . $status . '.',
        };
    }

    /** @return array{0:string, 1:string} [type MIME, jeu de caractères] */
    private static function contentType(string $header): array
    {
        $parts = array_map('trim', explode(';', $header));
        $mime  = strtolower((string)array_shift($parts));
        $charset = '';
        foreach ($parts as $p) {
            if (stripos($p, 'charset=') === 0) {
                $charset = trim(substr($p, 8), " \"'");
            }
        }
        return [$mime, $charset];
    }

    private static function looksLikeHtml(string $body): bool
    {
        return preg_match('#<(!doctype\s+html|html|body)\b#i', substr($body, 0, 2048)) === 1;
    }

    private static function metaCharset(string $html): string
    {
        $head = substr($html, 0, 4096);
        if (preg_match('#<meta[^>]+charset=["\']?([a-z0-9_-]+)#i', $head, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /** Texte en UTF-8 valide, quel que soit le jeu de caractères annoncé. */
    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset));
        if ($charset !== '' && !in_array($charset, ['utf-8', 'utf8'], true)) {
            try {
                $converted = mb_convert_encoding($text, 'UTF-8', $charset);
                if (is_string($converted)) {
                    return $converted;
                }
            } catch (\Throwable) {
                // Jeu inconnu de mbstring : on retombe sur la détection.
            }
        }
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        return is_string($converted) ? $converted : mb_scrub($text, 'UTF-8');
    }

    /** Blocs HTML → texte, titres et listes en Markdown léger. */
    private static function blocksToText(string $h): string
    {
        $h = preg_replace_callback('#<h([1-6])\b[^>]*>#i', static fn(array $m): string => "\n\n" . str_repeat('#', min(3, (int)$m[1])) . ' ', $h) ?? $h;
        $h = preg_replace('#</h[1-6]>#i', "\n\n", $h) ?? $h;
        $h = preg_replace('#<li\b[^>]*>#i', "\n- ", $h) ?? $h;
        $h = preg_replace('#<br\s*/?>#i', "\n", $h) ?? $h;
        $h = preg_replace('#</?(p|div|section|article|main|header|ul|ol|table|thead|tbody|tr|blockquote|pre|figure|figcaption|dl|dt|dd)\b[^>]*>#i', "\n", $h) ?? $h;
        $h = preg_replace('#</t[dh]>#i', ' · ', $h) ?? $h;
        $text = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/^(?:- *|#+ *|·)$/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** Document bureautique ou PDF → texte (FileExtractor, fichier temporaire supprimé). */
    private static function extractDocument(string $bytes, string $mime): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mia_web_');
        if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('Écriture temporaire impossible sur le serveur.');
        }
        try {
            $text = FileExtractor::extract($tmp, $mime);
        } finally {
            @unlink($tmp);
        }
        return trim(self::toUtf8((string)$text, 'utf-8'));
    }

    private static function inCidr(string $bin, string $net, int $len): bool
    {
        if ($net === '' || strlen($bin) !== strlen($net)) {
            return false;
        }
        $bytes = intdiv($len, 8);
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $bits = $len % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $bits)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
