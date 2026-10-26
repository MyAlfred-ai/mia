<?php

declare(strict_types=1);

/**
 * Client de l'API d'un cerveau MyBrain — l'application sœur de MIA, le wiki
 * d'équipe qui se tient à jour seul et où chaque fait garde sa source.
 *
 * MyBrain ouvre deux portes à une application, avec la même clé (mbk_…, émise
 * dans Administration → Clés d'API de MyBrain) : une API REST (/api/v1) et un
 * serveur MCP (/mcp). MIA prend la REST, et c'est un choix :
 *   - le contexte est ajouté au prompt AVANT l'appel au modèle, pour tous les
 *     fournisseurs — dont sept sur neuf n'ont pas d'outils. C'est un appel du
 *     serveur, pas un outil que le modèle déciderait d'appeler : MCP n'apporte
 *     rien là ;
 *   - MIA appelle toujours les mêmes points : un contrat versionné (/api/v1),
 *     aux réponses faites pour une application (liens absolus, erreurs en
 *     JSON, codes HTTP nets), tient mieux qu'un nom d'outil découvert à la
 *     volée. Les outils du chat qui creusent le cerveau (chercher_dans_mybrain,
 *     lire_fiche_mybrain) passent par ces mêmes points : c'est MIA qui les
 *     expose à son modèle, jamais la clé.
 * La clé ne part que dans l'en-tête Authorization — jamais dans l'URL, où elle
 * finirait dans les journaux des proxys —, jamais vers une redirection, et
 * jamais chez un fournisseur de modèles : tous les appels partent du serveur.
 *
 * Aucune dépendance à la base de données : le transport HTTP est injectable,
 * les tests rejouent des réponses sans réseau.
 */
final class MyBrainClient
{
    /** Au-delà, personne ne répond : inutile d'attendre la suite. */
    public const CONNECT_TIMEOUT = 3;

    /**
     * Le contexte est demandé à CHAQUE message où la base est engagée. MyBrain
     * le calcule en quelques centaines de millisecondes (embedding compris) ;
     * 8 s couvrent un mauvais jour sans suspendre la réponse indéfiniment.
     */
    public const TIMEOUT = 8;

    /** Le test de l'écran d'administration peut attendre un peu plus. */
    public const TEST_TIMEOUT = 12;

    /** Un contexte rendu pèse ~40 ko ; au-delà de 2 Mo, ce n'est pas une réponse. */
    public const MAX_BODY = 2_000_000;

    /** Les droits d'une clé MyBrain (cf. son écran Clés d'API). */
    public const RIGHT_READ = 'lire';
    public const RIGHT_FEED = 'nourrir';

    /** La forme d'une clé émise par MyBrain (cles-applications.ts, FORMAT_CLE). */
    private const KEY_PATTERN = '/^mbk_[A-Za-z0-9_-]{32,64}$/';

    /** Identifiant d'un dépôt MyBrain (jobs.ts : un UUID). */
    private const JOB_PATTERN = '/^[0-9a-f-]{36}$/i';

    /** @var callable(string, string, list<string>, ?string, int): array{0:int, 1:string, 2:string} */
    private $transport;

    /**
     * Transport de substitution pour les clients construits SANS transport —
     * ceux que fabrique KnowledgeBaseRemote::client() au fond d'une requête
     * ou d'un job, hors de portée d'un test. Posé par les tests seulement,
     * remis à null après usage.
     *
     * @var callable|null
     */
    private static $transportOverride = null;

    /**
     * @param string        $baseUrl   adresse du cerveau, déjà passée par normalizeBaseUrl()
     * @param callable|null $transport (méthode, url, en-têtes, corps, délai) → [statut, corps, erreur réseau]
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        ?callable $transport = null
    ) {
        $this->transport = $transport ?? self::$transportOverride ?? [self::class, 'curlTransport'];
    }

    /** Tests : le transport des clients construits sans transport explicite (null = le vrai). */
    public static function overrideTransport(?callable $transport): void
    {
        self::$transportOverride = $transport;
    }

    // ===================== Formes pures =====================

    /**
     * L'adresse d'un cerveau, ramenée à sa racine — ou null si elle n'est pas
     * acceptable. PURE.
     *
     * - http(s) seulement, sans identifiants dans l'URL ;
     * - https obligatoire, SAUF sur la machine ou le réseau interne (localhost,
     *   nom de conteneur sans point) : la clé voyagerait en clair sur Internet ;
     * - l'adresse de l'API ou du serveur MCP collée à la place de celle du
     *   cerveau (MyBrain les affiche toutes les deux) est ramenée à la racine ;
     * - requête et fragment tombent : ils n'ont rien à faire dans une base.
     */
    public static function normalizeBaseUrl(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 400) {
            return null;
        }
        $parts = parse_url($raw);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower((string)$parts['host']);
        if ($scheme === 'http' && !self::isInternalHost($host)) {
            return null;
        }
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $path = (string)preg_replace('#/(?:api/v1|mcp)$#i', '', $path);
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Hôte joignable sans passer par Internet : la machine elle-même, ou un
     * nom de conteneur (un seul label, sans point) sur le réseau Docker.
     */
    public static function isInternalHost(string $host): bool
    {
        $host = trim(strtolower($host), '[]');
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === 'host.docker.internal'
            || ($host !== '' && !str_contains($host, '.') && !str_contains($host, ':'));
    }

    /** Le secret a-t-il la forme d'une clé émise par MyBrain ? PURE. */
    public static function isKeyShaped(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /** « mbk_AbCd…wxyz » : de quoi reconnaître une clé, pas de quoi s'en servir. PURE. */
    public static function keyHint(string $key): string
    {
        return strlen($key) < 16 ? '' : substr($key, 0, 8) . '…' . substr($key, -4);
    }

    /**
     * La réponse de /api/v1/contexte, bornée et nettoyée. PURE.
     *
     * Seuls les liens http(s) survivent : ils finissent cliquables dans une
     * réponse du chat, un `javascript:` n'y a rien à faire.
     *
     * @return array{fiches: list<array{chemin:string, titre:string, url:string, derniere_maj:?string}>,
     *               tronque: bool, contexte: string}
     */
    public static function parseContexte(array $data): array
    {
        $fiches = [];
        foreach ((array)($data['fiches'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $url  = trim((string)($f['url'] ?? ''));
            $maj  = $f['derniere_maj'] ?? null;
            $fiches[] = [
                'chemin'       => mb_substr((string)($f['chemin'] ?? ''), 0, 400),
                'titre'        => mb_substr(trim((string)($f['titre'] ?? '')), 0, 200),
                'url'          => preg_match('#^https?://#i', $url) === 1 ? $url : '',
                'derniere_maj' => is_scalar($maj) && (string)$maj !== '' ? mb_substr((string)$maj, 0, 40) : null,
            ];
            if (count($fiches) >= 60) {
                break;
            }
        }

        return [
            'fiches'   => $fiches,
            'tronque'  => !empty($data['tronque']),
            'contexte' => (string)($data['contexte'] ?? ''),
        ];
    }

    /**
     * La réponse de /api/v1/fiche, bornée et nettoyée. PURE.
     *
     * Le contenu n'est PAS coupé ici : c'est l'appelant qui connaît la place
     * dont il dispose (MyBrainContext::readForTool). Même règle de liens que
     * parseContexte.
     *
     * @return array{chemin: string, titre: string, contenu: string, url: string}
     */
    public static function parseFiche(array $data): array
    {
        $url = trim((string)($data['url'] ?? ''));
        return [
            'chemin'  => mb_substr((string)($data['chemin'] ?? ''), 0, 400),
            'titre'   => mb_substr(trim((string)($data['titre'] ?? '')), 0, 200),
            'contenu' => (string)($data['contenu'] ?? ''),
            'url'     => preg_match('#^https?://#i', $url) === 1 ? $url : '',
        ];
    }

    /**
     * Les fiches proposées par MyBrain quand un NOM en désigne plusieurs, ou
     * aucune exactement (404 « aucune fiche ne porte ce nom », champ
     * `candidats`). PURE.
     *
     * @return list<array{chemin: string, titre: string}>
     */
    public static function parseCandidats(array $data): array
    {
        $out = [];
        foreach ((array)($data['candidats'] ?? []) as $c) {
            if (!is_array($c) || trim((string)($c['chemin'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                'chemin' => mb_substr((string)$c['chemin'], 0, 400),
                'titre'  => mb_substr(trim((string)($c['titre'] ?? '')), 0, 200),
            ];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    /**
     * La réponse de POST /api/v1/nourrir. PURE.
     *
     * Le chemin de suivi que MyBrain renvoie (`suivi`) n'est PAS repris : MIA
     * reconstruit le sien depuis l'identifiant, vérifié — la clé ne part
     * jamais vers une adresse dictée par une réponse.
     *
     * @return array{job: string, pour: ?string, avertissements: list<string>}
     * @throws MyBrainException identifiant de dépôt absent ou malformé
     */
    public static function parseDepot(array $data): array
    {
        $job = trim((string)($data['job'] ?? ''));
        if (preg_match(self::JOB_PATTERN, $job) !== 1) {
            throw new MyBrainException(
                'Réponse inattendue de MyBrain : aucun identifiant de dépôt.',
                MyBrainException::INVALID,
                202
            );
        }
        $avert = [];
        foreach ((array)($data['avertissements'] ?? []) as $a) {
            if (is_string($a) && trim($a) !== '') {
                $avert[] = mb_substr(trim($a), 0, 300);
            }
        }
        $pour = is_string($data['pour'] ?? null) && trim($data['pour']) !== '' ? mb_substr(trim($data['pour']), 0, 200) : null;

        return ['job' => strtolower($job), 'pour' => $pour, 'avertissements' => array_slice($avert, 0, 5)];
    }

    /**
     * La réponse de GET /api/v1/nourrir/{job}. PURE.
     *
     * `statut` ∈ en-attente | en-cours | fait | erreur (jobs.ts de MyBrain) ;
     * un statut inconnu vaut « en-cours » — le suivi continue, il ne conclut
     * jamais sur ce qu'il ne comprend pas. Un dépôt rangé en zone RH n'apprend
     * que cela : ni page, ni proposition (réservées à la direction de MyBrain).
     *
     * @return array{statut: string, position: ?int, erreur: ?string, zone_restreinte: bool,
     *               proposition_url: string, source_url: string, pages: list<string>}
     */
    public static function parseSuivi(array $data): array
    {
        $statut = (string)($data['statut'] ?? '');
        if (!in_array($statut, ['en-attente', 'en-cours', 'fait', 'erreur'], true)) {
            $statut = 'en-cours';
        }
        $http = static function (mixed $v): string {
            $u = is_array($v) ? trim((string)($v['url'] ?? '')) : '';
            return preg_match('#^https?://#i', $u) === 1 ? mb_substr($u, 0, 1000) : '';
        };
        $pages = [];
        foreach ((array)($data['pages'] ?? []) as $p) {
            if (is_scalar($p) && trim((string)$p) !== '') {
                $pages[] = mb_substr((string)$p, 0, 400);
            }
        }

        return [
            'statut'          => $statut,
            'position'        => is_numeric($data['position'] ?? null) ? (int)$data['position'] : null,
            'erreur'          => is_string($data['erreur'] ?? null) && trim($data['erreur']) !== ''
                ? mb_substr(trim($data['erreur']), 0, 300) : null,
            'zone_restreinte' => !empty($data['zone_restreinte']),
            'proposition_url' => $http($data['proposition'] ?? null),
            'source_url'      => $http($data['source'] ?? null),
            'pages'           => array_slice($pages, 0, 50),
        ];
    }

    /**
     * L'erreur d'une réponse HTTP qui n'est pas un succès. PURE.
     *
     * Le message de MyBrain (`erreur`) est repris quand il existe : il est
     * écrit pour un développeur ou un administrateur, en français, et nomme
     * souvent le remède (« un admin peut créer une clé qui l'a »).
     */
    public static function errorFor(int $status, ?array $data): MyBrainException
    {
        $said = is_array($data) && is_string($data['erreur'] ?? null)
            ? mb_substr(trim((string)$data['erreur']), 0, 300)
            : '';

        return match (true) {
            $status >= 300 && $status < 400 => new MyBrainException(
                'Cette adresse redirige ailleurs : indiquez l\'adresse finale du MyBrain '
                    . '(la clé n\'est jamais envoyée à une redirection).',
                MyBrainException::INVALID,
                $status
            ),
            $status === 401 => new MyBrainException(
                'Clé refusée par MyBrain : supprimée, mal recopiée, ou émise par un autre MyBrain.',
                MyBrainException::REFUSED,
                $status
            ),
            $status === 403 => new MyBrainException(
                $said !== '' ? 'Refusé par MyBrain : ' . $said : 'Refusé par MyBrain : la clé n\'a pas le droit demandé.',
                MyBrainException::REFUSED,
                $status
            ),
            $status === 404 && $said === '' => new MyBrainException(
                'Aucune API MyBrain à cette adresse (/api/v1 introuvable) : vérifiez l\'URL.',
                MyBrainException::INVALID,
                $status
            ),
            $status === 429 => new MyBrainException(
                'MyBrain limite les appels pour le moment' . ($said !== '' ? ' : ' . $said : '.'),
                MyBrainException::UNREACHABLE,
                $status
            ),
            $status >= 500 || $status === 0 => new MyBrainException(
                'MyBrain a rencontré une erreur (HTTP ' . $status . ').',
                MyBrainException::UNREACHABLE,
                $status
            ),
            default => new MyBrainException(
                $said !== '' ? 'MyBrain a refusé la demande : ' . $said : 'MyBrain a refusé la demande (HTTP ' . $status . ').',
                MyBrainException::REQUEST,
                $status
            ),
        };
    }

    // ===================== Appels =====================

    /**
     * « Qui suis-je » : l'application que la clé désigne et ses droits. Point
     * en LECTURE SEULE de MyBrain (GET /api/v1) — c'est le test de connexion.
     *
     * @return array{application: string, identite: string, droits: list<string>}
     * @throws MyBrainException
     */
    public function whoami(): array
    {
        $data = $this->request('GET', '/api/v1', null, self::TEST_TIMEOUT);
        if (!isset($data['application']) || !is_string($data['application'])) {
            throw new MyBrainException(
                'Réponse inattendue : ce serveur ne se présente pas comme un MyBrain (GET /api/v1).',
                MyBrainException::INVALID,
                200
            );
        }
        $droits = [];
        foreach ((array)($data['droits'] ?? []) as $d) {
            if (in_array($d, [self::RIGHT_READ, self::RIGHT_FEED], true) && !in_array($d, $droits, true)) {
                $droits[] = $d;
            }
        }

        return [
            'application' => mb_substr(trim($data['application']), 0, 120),
            'identite'    => mb_substr((string)($data['identite'] ?? ''), 0, 120),
            'droits'      => $droits,
        ];
    }

    /**
     * Ce que le cerveau sait autour d'une question — fiches pertinentes,
     * sections utiles, faits datés —, sans rédaction ni modèle côté MyBrain.
     *
     * @param list<array{role:string, content:string}> $historique tours PRÉCÉDENTS
     * @param int|null $budget plafond des extraits (caractères), null = défaut MyBrain
     * @return array{fiches: list<array{chemin:string, titre:string, url:string, derniere_maj:?string}>,
     *               tronque: bool, contexte: string}
     * @throws MyBrainException
     */
    public function contexte(string $question, array $historique = [], ?int $budget = null): array
    {
        $body = ['question' => mb_substr($question, 0, 4000)];
        if ($historique !== []) {
            $body['historique'] = array_values($historique);
        }
        if ($budget !== null) {
            $body['budget'] = $budget;
        }

        return self::parseContexte($this->request('POST', '/api/v1/contexte', $body, self::TIMEOUT));
    }

    /**
     * Une fiche entière, par son chemin (`wiki/clients/x.md`) ou par le nom de
     * ce qu'elle décrit.
     *
     * Une fiche introuvable est une RÉPONSE, pas une panne : 404 avec un
     * message de MyBrain → `trouvee: false` (et les candidats qu'il propose),
     * sans rien lever — sinon errorFor() la rangerait parmi les échecs et un
     * nom mal orthographié finirait par couper la base. Un 404 SANS message,
     * lui, dit qu'il n'y a pas d'API à cette adresse : l'erreur habituelle.
     *
     * @return array{trouvee: true, chemin: string, titre: string, contenu: string, url: string}
     *        |array{trouvee: false, message: string, candidats: list<array{chemin: string, titre: string}>}
     * @throws MyBrainException
     */
    public function fiche(string $chemin, string $nom = ''): array
    {
        $chemin = trim($chemin);
        $query  = $chemin !== ''
            ? ['chemin' => mb_substr($chemin, 0, 400)]
            : ['nom' => mb_substr(trim($nom), 0, 200)];
        [$status, $data] = $this->exchange('GET', '/api/v1/fiche?' . http_build_query($query), null, self::TIMEOUT);

        if ($status === 404 && is_array($data) && is_string($data['erreur'] ?? null)) {
            return [
                'trouvee'   => false,
                'message'   => mb_substr(trim((string)$data['erreur']), 0, 300),
                'candidats' => self::parseCandidats($data),
            ];
        }
        return ['trouvee' => true] + self::parseFiche(self::successOrThrow($status, $data));
    }

    /**
     * Donne un texte (ou une page web) à manger au cerveau. MyBrain en fait une
     * page source et des faits vers les bonnes fiches — TOUJOURS en proposition
     * dans sa revue : rien n'entre dans le wiki sans qu'un humain le tranche.
     * Asynchrone : 202 et l'identifiant du dépôt à suivre (suivi()).
     *
     * @param array{titre?: string, texte?: string, lien?: string, url?: string, pour?: string} $entree
     * @return array{job: string, pour: ?string, avertissements: list<string>}
     * @throws MyBrainException
     */
    public function nourrir(array $entree): array
    {
        $body = [];
        foreach (['titre', 'texte', 'lien', 'url', 'pour'] as $champ) {
            $valeur = trim((string)($entree[$champ] ?? ''));
            if ($valeur !== '') {
                $body[$champ] = $valeur;
            }
        }
        return self::parseDepot($this->request('POST', '/api/v1/nourrir', $body, self::TIMEOUT));
    }

    /**
     * Où en est un dépôt de CETTE clé. L'adresse est reconstruite depuis
     * l'identifiant, vérifié : jamais un chemin reçu.
     *
     * @return array{statut: string, position: ?int, erreur: ?string, zone_restreinte: bool,
     *               proposition_url: string, source_url: string, pages: list<string>}
     * @throws MyBrainException
     */
    public function suivi(string $job): array
    {
        if (preg_match(self::JOB_PATTERN, $job) !== 1) {
            throw new MyBrainException('Identifiant de dépôt invalide.', MyBrainException::REQUEST, 0);
        }
        return self::parseSuivi($this->request('GET', '/api/v1/nourrir/' . strtolower($job), null, self::TIMEOUT));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws MyBrainException
     */
    private function request(string $method, string $path, ?array $body, int $timeout): array
    {
        [$status, $data] = $this->exchange($method, $path, $body, $timeout);
        return self::successOrThrow($status, $data);
    }

    /**
     * Le corps d'une réponse réussie, ou l'erreur qui la décrit.
     *
     * @return array<string, mixed>
     * @throws MyBrainException
     */
    private static function successOrThrow(int $status, mixed $data): array
    {
        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new MyBrainException(
                    'Réponse illisible : cette adresse ne répond pas comme une API MyBrain.',
                    MyBrainException::INVALID,
                    $status
                );
            }
            return $data;
        }
        throw self::errorFor($status, is_array($data) ? $data : null);
    }

    /**
     * Un échange HTTP : statut et corps décodé. Seule la panne RÉSEAU lève
     * ici ; le sens d'un statut est l'affaire de l'appelant.
     *
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: mixed}
     * @throws MyBrainException
     */
    private function exchange(string $method, string $path, ?array $body, int $timeout): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];
        $payload = null;
        if ($body !== null) {
            $payload   = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $headers[] = 'Content-Type: application/json';
        }

        [$status, $raw, $netError] = ($this->transport)($method, $this->baseUrl . $path, $headers, $payload, $timeout);

        if ($netError !== '') {
            throw new MyBrainException(
                'MyBrain injoignable (' . mb_substr($netError, 0, 160) . ').',
                MyBrainException::UNREACHABLE,
                0
            );
        }
        return [$status, json_decode($raw, true)];
    }

    /**
     * Transport réel (curl). Aucune redirection suivie : l'en-tête
     * Authorization partirait avec, vers une adresse que personne n'a choisie.
     * Le corps est coupé au-delà de MAX_BODY.
     *
     * @param list<string> $headers
     * @return array{0:int, 1:string, 2:string} statut, corps, erreur réseau ('' si aucune)
     */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, '', 'initialisation impossible'];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_HTTPHEADER       => $headers,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_CONNECTTIMEOUT   => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT          => $timeout,
            CURLOPT_FOLLOWLOCATION   => false,
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_USERAGENT        => 'MIA (base de connaissances MyBrain)',
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_XFERINFOFUNCTION => static fn($c, $dlTotal, $dlNow): int => $dlNow > self::MAX_BODY ? 1 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw    = curl_exec($ch);
        $error  = $raw === false ? (curl_error($ch) !== '' ? curl_error($ch) : 'erreur réseau') : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, is_string($raw) ? $raw : '', $error];
    }
}
