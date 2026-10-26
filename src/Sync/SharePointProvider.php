<?php

declare(strict_types=1);

/**
 * Fournisseur Microsoft SharePoint (API Microsoft Graph) pour la
 * synchronisation des bases de connaissances. Authentification OAuth2
 * « client credentials » : application Azure AD (tenant + client id + secret)
 * avec la permission d'application Files.Read.All (consentement admin).
 *
 * La référence de dossier stockée est l'URL SharePoint telle que saisie :
 * elle est résolue à chaque cycle via l'endpoint /shares de Graph (encodage
 * base64url « u! »), qui accepte aussi bien l'URL de la barre d'adresse que
 * les liens de partage. Un token est obtenu par instance (= par cycle/test).
 *
 * Le token et les corps d'erreur Microsoft sont loggés tronqués, JAMAIS
 * renvoyés dans les exceptions (messages utilisateur FR uniquement).
 */
class SharePointProvider implements RemoteFolderProvider
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const LOGIN_BASE = 'https://login.microsoftonline.com';

    /** Longueur max d'une URL de dossier (colonne sync_folder_ref VARCHAR(500)). */
    public const MAX_URL_LEN = 500;

    /** Permissions d'application Graph suffisantes pour LIRE les fichiers. */
    public const READ_ROLES = [
        'Files.Read.All', 'Files.ReadWrite.All',
        'Sites.Read.All', 'Sites.ReadWrite.All', 'Sites.FullControl.All',
    ];

    private ?string $token = null;

    /** Dossier racine résolu (mémorisé pour le cycle : listing + downloads). */
    private ?string $resolvedRef  = null;
    private ?string $driveId      = null;
    private ?string $rootItemId   = null;
    private ?string $resolvedName = null;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    /**
     * Valide la saisie utilisateur : URL https plausible (hôte avec un point),
     * bornée à MAX_URL_LEN (la colonne tronquerait silencieusement au-delà).
     * La validation réelle (dossier accessible ?) est faite par l'API /shares.
     */
    public static function parseFolderInput(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || mb_strlen($input) > self::MAX_URL_LEN) {
            return null;
        }
        if (!str_starts_with($input, 'https://')) {
            return null;
        }
        $host = parse_url($input, PHP_URL_HOST);
        if (!is_string($host) || !str_contains($host, '.')) {
            return null;
        }
        return $input;
    }

    /**
     * Encodage « sharing URL » → shareId Graph : base64url préfixé « u! »
     * (padding retiré, + / remplacés par - _). Cf. doc Graph /shares.
     */
    public static function shareIdFromUrl(string $url): string
    {
        return 'u!' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    /**
     * Item Graph brut → entrée normalisée de RemoteFolderProvider.
     * Dossier → null (traité par le parcours) ; élément sans facet file ni
     * folder (package OneNote…) → mime '' (compté « non supporté » par le
     * moteur de sélection).
     */
    public static function normalizeItem(array $item): ?array
    {
        $id = (string)($item['id'] ?? '');
        if ($id === '' || isset($item['folder'])) {
            return null;
        }
        $file = is_array($item['file'] ?? null) ? $item['file'] : [];
        $hash = $file['hashes']['quickXorHash'] ?? null;
        return [
            'id'       => $id,
            'name'     => (string)($item['name'] ?? $id),
            'mime'     => (string)($file['mimeType'] ?? ''),
            'size'     => (int)($item['size'] ?? 0),
            'hash'     => is_string($hash) && $hash !== '' ? $hash : null,
            'modified' => (string)($item['lastModifiedDateTime'] ?? ''),
            'export'   => null, // les fichiers Office SharePoint sont de vrais docx/xlsx/pptx
        ];
    }

    /**
     * Résout l'URL du dossier via /shares (bouton « Tester » + début de cycle).
     * Exige un dossier (facet folder) ; mémorise driveId + itemId pour le
     * listing et les téléchargements du cycle.
     */
    public function resolveFolder(string $folderRef): array
    {
        if ($this->resolvedRef === $folderRef && $this->driveId !== null && $this->rootItemId !== null) {
            return ['name' => $this->resolvedName ?? ''];
        }
        $item = $this->requestJson(
            '/shares/' . rawurlencode(self::shareIdFromUrl($folderRef))
            . '/driveItem?$select=id,name,folder,parentReference'
        );
        if (!isset($item['folder'])) {
            throw new \RuntimeException('Le lien fourni pointe un fichier, pas un dossier SharePoint.');
        }
        $driveId = (string)($item['parentReference']['driveId'] ?? '');
        $itemId  = (string)($item['id'] ?? '');
        if ($driveId === '' || $itemId === '') {
            throw new \RuntimeException('Réponse SharePoint inattendue — impossible d\'identifier le dossier.');
        }
        $this->resolvedRef  = $folderRef;
        $this->driveId      = $driveId;
        $this->rootItemId   = $itemId;
        $this->resolvedName = (string)($item['name'] ?? '');
        return ['name' => $this->resolvedName];
    }

    /**
     * Listing récursif (BFS) des FICHIERS du dossier et de ses sous-dossiers,
     * au format normalisé. Profondeur plafonnée, éléments dédupliqués par id,
     * pagination Graph suivie (@odata.nextLink).
     */
    public function listFolderRecursive(string $folderRef, int $maxDepth = 10): array
    {
        $this->resolveFolder($folderRef);

        $files   = [];
        $visited = [$this->rootItemId => true];
        $queue   = [[$this->rootItemId, 0]];

        while ($queue !== []) {
            [$itemId, $depth] = array_shift($queue);
            $url = self::GRAPH_BASE . '/drives/' . rawurlencode((string)$this->driveId)
                . '/items/' . rawurlencode((string)$itemId)
                . '/children?$select=id,name,size,file,folder,lastModifiedDateTime&$top=200';

            do {
                $data = $this->requestJsonAbsolute($url);
                foreach (($data['value'] ?? []) as $item) {
                    $id = (string)($item['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    if (isset($item['folder'])) {
                        if ($depth + 1 <= $maxDepth && !isset($visited[$id])) {
                            $visited[$id] = true;
                            $queue[] = [$id, $depth + 1];
                        }
                        continue;
                    }
                    $entry = self::normalizeItem($item);
                    if ($entry !== null) {
                        $files[$id] = $entry;
                    }
                }
                $url = isset($data['@odata.nextLink']) ? (string)$data['@odata.nextLink'] : null;
            } while ($url !== null);
        }

        return array_values($files);
    }

    /**
     * Télécharge une entrée normalisée. Graph répond 302 vers une URL
     * pré-signée : le redirect est suivi MANUELLEMENT, second GET SANS header
     * Authorization (le wrapper http de PHP ré-émettrait tous les headers sur
     * l'URL du CDN, qui peut rejeter un Bearer inattendu).
     */
    public function fetchTo(array $file, string $destPath): void
    {
        if ($this->driveId === null) {
            throw new \RuntimeException('Dossier SharePoint non résolu — cycle interne invalide.');
        }
        $url = self::GRAPH_BASE . '/drives/' . rawurlencode($this->driveId)
            . '/items/' . rawurlencode((string)$file['id']) . '/content';

        [$status, $headers, $body] = $this->requestRaw($url, [
            'Authorization: Bearer ' . $this->token(),
        ], 120, false);

        if ($status >= 300 && $status < 400) {
            $location = null;
            foreach ($headers as $h) {
                if (stripos($h, 'Location:') === 0) {
                    $location = trim(substr($h, 9));
                }
            }
            if ($location === null || $location === '') {
                throw new \RuntimeException('Réponse SharePoint inattendue au téléchargement.');
            }
            [$status, , $body] = $this->requestRaw($location, [], 120, true);
        }
        if ($status >= 400) {
            error_log('[KB Sync] SharePoint download HTTP ' . $status . ' : ' . mb_substr($body, 0, 300));
            throw $this->presentHttpError($status, $body);
        }
        if (@file_put_contents($destPath, $body) === false) {
            throw new \RuntimeException('Écriture impossible sur le stockage local.');
        }
    }

    // ===================== OAuth2 client credentials =====================

    /** Token applicatif Microsoft Graph (un par instance, durée ~1 h). */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $url  = self::LOGIN_BASE . '/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => $body,
            'timeout'       => 30,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($url, false, $ctx);
        $status   = self::statusFromHeaders($http_response_header ?? []);

        if ($response === false) {
            throw new \RuntimeException('Microsoft injoignable — nouvelle tentative au prochain cycle.');
        }
        if ($status >= 400) {
            error_log('[KB Sync] SharePoint token HTTP ' . $status . ' : ' . mb_substr($response, 0, 300));
            throw new \RuntimeException(self::tokenErrorMessage($status, $response));
        }
        $data  = json_decode($response, true);
        $token = is_array($data) ? (string)($data['access_token'] ?? '') : '';
        if ($token === '') {
            throw new \RuntimeException('Réponse d\'authentification Microsoft inattendue.');
        }
        return $this->token = $token;
    }

    /**
     * Corps d'erreur du endpoint token Microsoft → message utilisateur ciblé
     * (pur, testable). Les codes AADSTS identifient précisément l'identifiant
     * fautif ; le corps brut n'est jamais recopié dans le message.
     */
    public static function tokenErrorMessage(int $status, string $body): string
    {
        // Affiché à l'administrateur (boutons « Tester » de Sources
        // synchronisées et d'Authentification) : dans sa langue.
        if (str_contains($body, 'AADSTS90002')) {
            return t('ID de l’annuaire (tenant) introuvable chez Microsoft — vérifiez la valeur copiée depuis le portail Azure (page de l’application, « ID de l’annuaire »).');
        }
        if (str_contains($body, 'AADSTS700016')) {
            return t('ID d’application (client) inconnu de ce tenant — vérifiez la valeur, et que l’application est bien inscrite dans cet annuaire.');
        }
        if (str_contains($body, 'AADSTS7000215')) {
            return t('Secret client invalide — collez la VALEUR du secret (pas son ID), telle qu’affichée à sa création dans le portail Azure.');
        }
        if (str_contains($body, 'AADSTS7000222')) {
            return t('Secret client expiré — créez un nouveau secret dans le portail Azure (Certificats & secrets) et enregistrez sa valeur ici.');
        }
        return t('Identifiants Azure invalides (HTTP {code}) — vérifiez l’ID de tenant, l’ID client et le secret (et son expiration).', ['code' => $status]);
    }

    /**
     * Rôles applicatifs du claim « roles » d'un token Graph, null si le token
     * n'est pas un JWT décodable (pur, testable). Signature volontairement NON
     * vérifiée : on lit notre propre token, uniquement pour un diagnostic.
     *
     * @return ?list<string>
     */
    public static function rolesFromJwt(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'));
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($payload)) {
            return null;
        }
        $roles = $payload['roles'] ?? [];
        return is_array($roles) ? array_values(array_map('strval', $roles)) : [];
    }

    /**
     * Test de connexion (bouton « Tester la connexion » des Paramètres) :
     * obtient un token applicatif (valide tenant + client id + secret) puis
     * vérifie que la permission Files.Read.All figure dans ses rôles — le
     * consentement admin oublié est diagnostiqué AVANT le premier cycle.
     * Ne lève jamais.
     *
     * @return array{ok:bool, error?:string, detail?:string}
     */
    public function testConnection(): array
    {
        try {
            $token = $this->token();
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $roles = self::rolesFromJwt($token);
        if ($roles === null) {
            return ['ok' => true, 'detail' => 'Authentification Microsoft réussie (permissions non vérifiables sur ce token).'];
        }
        if (array_intersect(self::READ_ROLES, $roles) === []) {
            return [
                'ok'    => false,
                'error' => 'Authentification réussie, mais la permission d\'application Files.Read.All '
                    . '(Microsoft Graph) manque au token — accordez-la avec consentement administrateur '
                    . 'dans le portail Azure, puis réessayez après quelques minutes.',
            ];
        }
        return ['ok' => true, 'detail' => 'Authentification Azure acceptée et permission Files.Read.All présente.'];
    }

    // ===================== HTTP =====================

    /** Requête JSON Graph sur un chemin relatif à l'API. */
    private function requestJson(string $path, int $timeout = 30): array
    {
        return $this->requestJsonAbsolute(self::GRAPH_BASE . $path, $timeout);
    }

    /** Requête JSON Graph sur une URL absolue (pagination @odata.nextLink). */
    private function requestJsonAbsolute(string $url, int $timeout = 30): array
    {
        [$status, , $body] = $this->requestRaw($url, [
            'Authorization: Bearer ' . $this->token(),
            'Accept: application/json',
        ], $timeout, true);

        if ($status >= 400) {
            error_log('[KB Sync] SharePoint GET HTTP ' . $status . ' : ' . mb_substr($body, 0, 300));
            throw $this->presentHttpError($status, $body);
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * GET brut. Retourne [statut, headers bruts, corps]. $follow contrôle le
     * suivi des redirections (désactivé pour lire le Location d'un 302 Graph).
     */
    private function requestRaw(string $url, array $headers, int $timeout, bool $follow): array
    {
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => implode("\r\n", $headers),
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'follow_location' => $follow ? 1 : 0,
        ]]);

        $response = @file_get_contents($url, false, $ctx);
        $raw      = $http_response_header ?? [];

        if ($response === false) {
            throw new \RuntimeException('Microsoft Graph injoignable — nouvelle tentative au prochain cycle.');
        }
        return [self::statusFromHeaders($raw), $raw, $response];
    }

    /** Dernier statut HTTP d'une pile de headers de réponse (redirections incluses). */
    private static function statusFromHeaders(array $headers): int
    {
        $status = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
            }
        }
        return $status;
    }

    /** Statut HTTP Graph → exception à message utilisateur actionnable. */
    private function presentHttpError(int $status, string $body): \RuntimeException
    {
        if ($status === 401) {
            return new \RuntimeException(
                'Authentification Microsoft rejetée — vérifiez les identifiants Azure '
                . '(Administration → Capacités → Sources synchronisées) et l\'expiration du secret client.'
            );
        }
        if ($status === 403) {
            return new \RuntimeException(
                'Accès refusé par Microsoft : l\'application Azure doit avoir la permission d\'application '
                . 'Files.Read.All (Microsoft Graph) avec consentement administrateur.'
            );
        }
        if ($status === 400 || $status === 404) {
            return new \RuntimeException(
                'Dossier SharePoint introuvable : vérifiez l\'URL et qu\'elle appartient bien au tenant '
                . 'de l\'application Azure configurée.'
            );
        }
        if ($status === 429) {
            return new \RuntimeException('Limitation Microsoft Graph atteinte — nouvelle tentative au prochain cycle.');
        }
        return new \RuntimeException('Erreur SharePoint (HTTP ' . $status . ').');
    }
}
