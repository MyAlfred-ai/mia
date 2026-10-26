<?php

declare(strict_types=1);

/**
 * Fournisseur Microsoft OneDrive (Microsoft Graph) pour la synchronisation
 * des bases de connaissances, lu avec les droits DÉLÉGUÉS d'un utilisateur
 * (MicrosoftOAuth + UserOneDriveService) : son OneDrive personnel, ses
 * dossiers « Partagés avec moi », les bibliothèques auxquelles il a accès.
 *
 * Différence de fond avec SharePointProvider (même API, autre monde) : là-bas
 * l'application agit SANS utilisateur (client credentials) et ne peut que
 * suivre une URL donnée ; ici elle agit AU NOM de l'utilisateur, donc elle
 * peut lui faire NAVIGUER dans ses dossiers — d'où BrowsableFolderProvider.
 *
 * Le jeton n'est jamais détenu par le fournisseur : il reçoit un CALLABLE
 * (supplier) et le redemande à chaque requête, parce qu'un cycle de 100
 * fichiers dépasse la durée de vie d'un access token (même choix que
 * GoogleDriveProvider::withBearerSupplier).
 *
 * Référence de dossier (`sync_folder_ref`), trois formes acceptées :
 *   - « {driveId}:{itemId} » — ce que produit l'explorateur (le driveId est
 *     indispensable : un dossier partagé vit dans le drive de QUELQU'UN
 *     D'AUTRE, /me/drive/items/{id} y répondrait 404) ;
 *   - une URL https de partage OneDrive/SharePoint, résolue via /shares ;
 *   - « shared » / '' : racines virtuelles de l'explorateur, jamais
 *     enregistrables comme dossier synchronisé.
 *
 * Les corps d'erreur Microsoft sont loggés tronqués, JAMAIS renvoyés.
 */
class OneDriveProvider implements RemoteFolderProvider, BrowsableFolderProvider
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /** Longueur max d'une référence (colonne sync_folder_ref VARCHAR(500)). */
    public const MAX_REF_LEN = 500;

    /** Racines virtuelles de l'explorateur (ne sont pas des dossiers). */
    public const REF_MY_DRIVE = 'me';
    public const REF_SHARED   = 'shared';

    /** @var (callable():string) Fournit un access token délégué frais. */
    private $bearerSupplier;

    /** Dossier résolu, mémorisé pour le cycle (listing + téléchargements). */
    private ?string $resolvedRef  = null;
    private ?string $driveId      = null;
    private ?string $rootItemId   = null;
    private ?string $resolvedName = null;

    /** @param callable():string $supplier */
    public function __construct(callable $supplier)
    {
        $this->bearerSupplier = $supplier;
    }

    /** @param callable():string $supplier */
    public static function withBearerSupplier(callable $supplier): self
    {
        return new self($supplier);
    }

    /**
     * Valide la saisie utilisateur (pure, testable) : référence
     * « driveId:itemId » de l'explorateur, ou URL de partage https. Les
     * racines virtuelles sont explicitement refusées — elles ne désignent
     * aucun dossier réel.
     */
    public static function parseFolderInput(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || mb_strlen($input) > self::MAX_REF_LEN) {
            return null;
        }
        if ($input === self::REF_MY_DRIVE || $input === self::REF_SHARED) {
            return null;
        }
        if (str_starts_with($input, 'https://')) {
            $host = parse_url($input, PHP_URL_HOST);
            return is_string($host) && str_contains($host, '.') ? $input : null;
        }
        // Les ids Graph mélangent alphanumérique, ! - _ . % ~ ; le séparateur
        // « : » n'y apparaît jamais, il découpe donc sans ambiguïté.
        if (preg_match('#^[A-Za-z0-9!\-_.%~]{1,240}:[A-Za-z0-9!\-_.%~]{1,240}$#', $input) === 1) {
            return $input;
        }
        return null;
    }

    /** Référence composite depuis un item Graph (pure, testable). */
    public static function refFor(string $driveId, string $itemId): string
    {
        return $driveId . ':' . $itemId;
    }

    /**
     * Item Graph brut → entrée normalisée de RemoteFolderProvider (pure,
     * testable). Dossier → null. Les fichiers Office de OneDrive sont de
     * vrais docx/xlsx/pptx : aucun export à prévoir, contrairement à Google.
     */
    public static function normalizeItem(array $item): ?array
    {
        $id = (string)($item['id'] ?? '');
        if ($id === '' || isset($item['folder'])) {
            return null;
        }
        $file = is_array($item['file'] ?? null) ? $item['file'] : [];
        $hash = $file['hashes']['quickXorHash'] ?? ($file['hashes']['sha256Hash'] ?? null);
        return [
            'id'       => $id,
            'name'     => (string)($item['name'] ?? $id),
            'mime'     => (string)($file['mimeType'] ?? ''),
            'size'     => (int)($item['size'] ?? 0),
            'hash'     => is_string($hash) && $hash !== '' ? $hash : null,
            'modified' => (string)($item['lastModifiedDateTime'] ?? ''),
            'export'   => null,
        ];
    }

    /**
     * Résout la référence en (driveId, itemId) et vérifie que c'est bien un
     * dossier. Mémorisé pour la durée du cycle.
     */
    public function resolveFolder(string $folderRef): array
    {
        if ($this->resolvedRef === $folderRef && $this->driveId !== null && $this->rootItemId !== null) {
            return ['name' => $this->resolvedName ?? ''];
        }

        $item = $this->fetchItem($folderRef);
        if (!self::isFolderLike($item)) {
            throw new \RuntimeException('Le lien fourni pointe un fichier, pas un dossier OneDrive.');
        }
        [$driveId, $itemId] = self::identityOf($item);
        if ($driveId === '' || $itemId === '') {
            throw new \RuntimeException('Réponse Microsoft inattendue — impossible d\'identifier le dossier.');
        }

        $this->resolvedRef  = $folderRef;
        $this->driveId      = $driveId;
        $this->rootItemId   = $itemId;
        $this->resolvedName = (string)($item['name'] ?? '');
        return ['name' => $this->resolvedName];
    }

    /**
     * Listing récursif (BFS) des FICHIERS du dossier et de ses sous-dossiers.
     * Un sous-dossier peut être un `remoteItem` (raccourci vers le drive d'un
     * autre compte) : on repart alors sur SON drive, sinon le parcours
     * s'arrêterait net sur un 404.
     */
    public function listFolderRecursive(string $folderRef, int $maxDepth = 10): array
    {
        $this->resolveFolder($folderRef);

        $files   = [];
        $visited = [(string)$this->driveId . ':' . (string)$this->rootItemId => true];
        $queue   = [[(string)$this->driveId, (string)$this->rootItemId, 0]];

        while ($queue !== []) {
            [$driveId, $itemId, $depth] = array_shift($queue);
            $url = self::GRAPH_BASE . '/drives/' . rawurlencode($driveId)
                . '/items/' . rawurlencode($itemId)
                . '/children?$select=id,name,size,file,folder,remoteItem,lastModifiedDateTime&$top=200';

            do {
                $data = $this->requestJsonAbsolute($url);
                foreach (($data['value'] ?? []) as $item) {
                    if (self::isFolderLike($item)) {
                        [$childDrive, $childItem] = self::identityOf($item, $driveId);
                        $key = $childDrive . ':' . $childItem;
                        if ($childItem !== '' && $depth + 1 <= $maxDepth && !isset($visited[$key])) {
                            $visited[$key] = true;
                            $queue[] = [$childDrive, $childItem, $depth + 1];
                        }
                        continue;
                    }
                    $entry = self::normalizeItem($item);
                    if ($entry !== null) {
                        // Clé de diff = id d'item. Un fichier vu deux fois
                        // (raccourci) ne doit pas produire deux documents.
                        $files[$entry['id']] = $entry + ['drive_id' => $driveId];
                    }
                }
                $url = isset($data['@odata.nextLink']) ? (string)$data['@odata.nextLink'] : null;
            } while ($url !== null);
        }

        return array_values($files);
    }

    /**
     * Un niveau d'arborescence pour l'explorateur. `''` rend une racine
     * virtuelle : « Mon OneDrive » et « Partagés avec moi » — le second est
     * exactement le cas d'usage d'une base alimentée par le dossier d'un
     * collègue.
     */
    public function browse(string $folderRef = '', int $limit = 300): array
    {
        $folderRef = trim($folderRef);
        $limit     = max(1, min(500, $limit));

        if ($folderRef === '') {
            return [
                'ref'        => '',
                'name'       => 'OneDrive',
                'parent_ref' => null,
                'selectable' => false,
                'folders'    => [
                    ['ref' => self::REF_MY_DRIVE, 'name' => t('Mon OneDrive')],
                    ['ref' => self::REF_SHARED,   'name' => t('Partagés avec moi')],
                ],
                'files'      => [],
                'truncated'  => false,
            ];
        }

        if ($folderRef === self::REF_SHARED) {
            return $this->browseSharedWithMe($limit);
        }

        $item = $this->fetchItem($folderRef);
        if (!self::isFolderLike($item)) {
            throw new \RuntimeException('Cet élément est un fichier, pas un dossier OneDrive.');
        }
        [$driveId, $itemId] = self::identityOf($item);

        $parentId  = (string)($item['parentReference']['id'] ?? '');
        $parentRef = $parentId !== '' ? self::refFor($driveId, $parentId) : null;

        // Ni $orderby ni $top au-delà du strict nécessaire : le tri
        // d'affichage se fait en PHP (un paramètre OData refusé rendrait un
        // 400 pour un simple ordre alphabétique).
        $url = self::GRAPH_BASE . '/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($itemId)
            . '/children?$select=id,name,size,file,folder,remoteItem,lastModifiedDateTime&$top=' . $limit;
        $data = $this->requestJsonAbsolute($url);

        $folders = [];
        $files   = [];
        foreach (($data['value'] ?? []) as $child) {
            if (self::isFolderLike($child)) {
                [$cd, $ci] = self::identityOf($child, $driveId);
                if ($ci !== '') {
                    $folders[] = ['ref' => self::refFor($cd, $ci), 'name' => (string)($child['name'] ?? $ci)];
                }
                continue;
            }
            $entry = self::normalizeItem($child);
            if ($entry !== null) {
                $files[] = $entry;
            }
        }
        GoogleDriveProvider::sortByName($folders);
        GoogleDriveProvider::sortByName($files);

        return [
            'ref'        => self::refFor($driveId, $itemId),
            'name'       => (string)($item['name'] ?? 'Dossier'),
            // La racine du drive n'a pas de parent utile : y « remonter »
            // ramènerait à elle-même. On renvoie vers la racine virtuelle.
            'parent_ref' => isset($item['root']) ? '' : $parentRef,
            'selectable' => true,
            'folders'    => $folders,
            'files'      => $files,
            'truncated'  => isset($data['@odata.nextLink']),
        ];
    }

    /**
     * Télécharge une entrée normalisée. Graph répond 302 vers une URL
     * pré-signée : redirect suivi MANUELLEMENT, second GET SANS header
     * Authorization (le CDN rejette un Bearer inattendu) — même piège que
     * SharePointProvider.
     */
    public function fetchTo(array $file, string $destPath): void
    {
        $driveId = (string)($file['drive_id'] ?? $this->driveId ?? '');
        if ($driveId === '') {
            throw new \RuntimeException('Dossier OneDrive non résolu — cycle interne invalide.');
        }
        $url = self::GRAPH_BASE . '/drives/' . rawurlencode($driveId)
            . '/items/' . rawurlencode((string)$file['id']) . '/content';

        [$status, $headers, $body] = $this->requestRaw($url, true, 120, false);

        if ($status >= 300 && $status < 400) {
            $location = null;
            foreach ($headers as $h) {
                if (stripos($h, 'Location:') === 0) {
                    $location = trim(substr($h, 9));
                }
            }
            if ($location === null || $location === '') {
                throw new \RuntimeException('Réponse OneDrive inattendue au téléchargement.');
            }
            [$status, , $body] = $this->requestRaw($location, false, 120, true);
        }
        if ($status >= 400) {
            error_log('[KB Sync] OneDrive download HTTP ' . $status . ' : ' . mb_substr($body, 0, 300));
            throw self::presentHttpError($status);
        }
        if (@file_put_contents($destPath, $body) === false) {
            throw new \RuntimeException('Écriture impossible sur le stockage local.');
        }
    }

    /**
     * Test de connexion : lit l'identité du drive de l'utilisateur. Ne lève
     * jamais.
     *
     * @return array{ok:bool, error?:string, detail?:string}
     */
    public function testConnection(): array
    {
        try {
            $me = $this->requestJsonAbsolute(self::GRAPH_BASE . '/me/drive?$select=id,name,driveType');
            $type = (string)($me['driveType'] ?? '');
            return [
                'ok'     => true,
                'detail' => 'Connexion OneDrive opérationnelle'
                    . ($type !== '' ? ' (' . $type . ')' : '') . '.',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ===================== Résolution d'items =====================

    /** Un item Graph est-il un dossier (ou un raccourci vers un dossier) ? */
    public static function isFolderLike(array $item): bool
    {
        return isset($item['folder'])
            || (isset($item['remoteItem']) && isset($item['remoteItem']['folder']));
    }

    /**
     * (driveId, itemId) d'un item Graph, en suivant un éventuel `remoteItem`
     * (raccourci vers le drive d'un autre compte). Pure, testable.
     *
     * @return array{0:string,1:string}
     */
    public static function identityOf(array $item, string $fallbackDriveId = ''): array
    {
        $remote = is_array($item['remoteItem'] ?? null) ? $item['remoteItem'] : null;
        if ($remote !== null) {
            $driveId = (string)($remote['parentReference']['driveId'] ?? '');
            $itemId  = (string)($remote['id'] ?? '');
            if ($driveId !== '' && $itemId !== '') {
                return [$driveId, $itemId];
            }
        }
        $driveId = (string)($item['parentReference']['driveId'] ?? '');
        return [$driveId !== '' ? $driveId : $fallbackDriveId, (string)($item['id'] ?? '')];
    }

    /** Métadonnées d'un item depuis une référence (composite, URL de partage, racine). */
    private function fetchItem(string $folderRef): array
    {
        $select = '?$select=id,name,folder,file,root,remoteItem,parentReference';

        if ($folderRef === self::REF_MY_DRIVE) {
            return $this->requestJsonAbsolute(self::GRAPH_BASE . '/me/drive/root' . $select);
        }
        if (str_starts_with($folderRef, 'https://')) {
            return $this->requestJsonAbsolute(
                self::GRAPH_BASE . '/shares/' . rawurlencode(SharePointProvider::shareIdFromUrl($folderRef))
                . '/driveItem' . $select
            );
        }
        $pos = strpos($folderRef, ':');
        if ($pos === false) {
            throw new \RuntimeException('Référence de dossier OneDrive non reconnue.');
        }
        $driveId = substr($folderRef, 0, $pos);
        $itemId  = substr($folderRef, $pos + 1);
        return $this->requestJsonAbsolute(
            self::GRAPH_BASE . '/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($itemId) . $select
        );
    }

    /**
     * Racine « Partagés avec moi » : Graph ne rend là que des raccourcis
     * (`remoteItem`), dont l'id N'EST PAS celui du dossier réel — d'où
     * identityOf(), qui suit le raccourci avant de fabriquer la référence.
     */
    private function browseSharedWithMe(int $limit): array
    {
        // sharedWithMe est une FONCTION Graph : son support d'OData est
        // partiel, on ne lui passe donc aucun paramètre et on borne en PHP.
        $data = $this->requestJsonAbsolute(self::GRAPH_BASE . '/me/drive/sharedWithMe');

        $folders = [];
        $files   = [];
        foreach (($data['value'] ?? []) as $item) {
            if (self::isFolderLike($item)) {
                [$driveId, $itemId] = self::identityOf($item);
                if ($driveId !== '' && $itemId !== '') {
                    $folders[] = ['ref' => self::refFor($driveId, $itemId), 'name' => (string)($item['name'] ?? $itemId)];
                }
                continue;
            }
            $entry = self::normalizeItem($item);
            if ($entry !== null) {
                $files[] = $entry;
            }
        }
        GoogleDriveProvider::sortByName($folders);
        GoogleDriveProvider::sortByName($files);
        $truncated = count($folders) > $limit || count($files) > $limit;
        $folders   = array_slice($folders, 0, $limit);
        $files     = array_slice($files, 0, $limit);

        return [
            'ref'        => self::REF_SHARED,
            'name'       => t('Partagés avec moi'),
            'parent_ref' => '',
            'selectable' => false, // ce n'est pas un dossier : rien à synchroniser
            'folders'    => $folders,
            'files'      => $files,
            'truncated'  => $truncated || isset($data['@odata.nextLink']),
        ];
    }

    // ===================== HTTP =====================

    private function requestJsonAbsolute(string $url, int $timeout = 30): array
    {
        [$status, , $body] = $this->requestRaw($url, true, $timeout, true);
        if ($status >= 400) {
            error_log('[KB Sync] OneDrive GET HTTP ' . $status . ' : ' . mb_substr($body, 0, 300));
            throw self::presentHttpError($status);
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * GET brut. $auth contrôle l'envoi du Bearer (jamais sur l'URL pré-signée
     * d'un téléchargement), $follow le suivi des redirections.
     *
     * @return array{0:int,1:array,2:string}
     */
    private function requestRaw(string $url, bool $auth, int $timeout, bool $follow): array
    {
        $headers = ['Accept: application/json'];
        if ($auth) {
            // Jeton redemandé à CHAQUE requête : un cycle dépasse sa durée de vie.
            $headers[] = 'Authorization: Bearer ' . ($this->bearerSupplier)();
        }
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

        $status = 0;
        foreach ($raw as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
            }
        }
        return [$status, $raw, $response];
    }

    /** Statut HTTP Graph → exception à message utilisateur FR (pure, testable). */
    public static function presentHttpError(int $status): \RuntimeException
    {
        if ($status === 401) {
            return new \RuntimeException(
                'Connexion OneDrive expirée ou révoquée — reconnectez-la dans Réglages → Comptes connectés.'
            );
        }
        if ($status === 403) {
            return new \RuntimeException(
                'Accès refusé par Microsoft : le compte connecté n\'a pas accès à cet élément, '
                . 'ou l\'autorisation Files.Read.All n\'a pas été accordée à la connexion.'
            );
        }
        if ($status === 404) {
            return new \RuntimeException(
                'Dossier OneDrive introuvable ou non partagé avec le compte Microsoft connecté.'
            );
        }
        if ($status === 429) {
            return new \RuntimeException('Limitation Microsoft Graph atteinte — nouvelle tentative au prochain cycle.');
        }
        return new \RuntimeException('Erreur OneDrive (HTTP ' . $status . ').');
    }
}
