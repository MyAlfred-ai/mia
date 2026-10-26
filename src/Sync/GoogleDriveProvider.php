<?php

declare(strict_types=1);

/**
 * Fournisseur Google Drive (API v3) pour la synchronisation des bases de
 * connaissances. Deux modes d'authentification :
 *
 *  - CLÉ API simple (constructeur) : lecture anonyme, suffisante pour un
 *    dossier partagé « Tous les utilisateurs disposant du lien » ;
 *  - JETON OAUTH d'un utilisateur (withBearerSupplier) : lecture avec SES
 *    droits Drive — dossier partagé nominativement avec lui, aucun partage
 *    public. Le fournisseur reçoit un CALLABLE et non le jeton : un cycle de
 *    sync peut durer plus que la vie d'un access token, le supplier rafraîchit
 *    à chaque requête (UserDriveService::getValidAccessToken, marge 60 s).
 *
 * Le secret est transporté en header (x-goog-api-key ou Authorization —
 * jamais dans l'URL) : les messages d'exception peuvent citer le path sans
 * risquer de le fuiter. Les corps d'erreur Google sont loggés, JAMAIS
 * renvoyés dans l'exception.
 *
 * Piège connu : Google renvoie 404 (et non 403) sur un dossier privé accédé
 * anonymement — le message utilisateur couvre « introuvable OU non partagé ».
 * En mode OAuth, 404 signifie « introuvable OU non partagé avec ce compte ».
 *
 * Drive partagés : `supportsAllDrives` (files.get, alt=media) et
 * `includeItemsFromAllDrives` (files.list) sont posés sur CHAQUE requête —
 * sans eux, un fichier de Drive partagé répond 404 et un dossier de Drive
 * partagé se liste vide. Et un dossier de Drive partagé se liste dans le
 * corpus DE SON DRIVE (cf. listPage) : le corpus par défaut ne couvre, côté
 * Drive partagé, que les fichiers que le compte a déjà ouverts.
 */
class GoogleDriveProvider implements RemoteFolderProvider, BrowsableFolderProvider
{
    private const API_BASE = 'https://www.googleapis.com/drive/v3';

    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** Racines virtuelles de l'explorateur (ne sont pas des dossiers Drive). */
    public const REF_MY_DRIVE      = 'root';
    public const REF_SHARED        = 'sharedWithMe';
    public const REF_SHARED_DRIVES = 'sharedDrives';

    /**
     * `drives.list` : 100 Drive partagés par page au plus (plafond de l'API,
     * 10 si le paramètre manque). Pages bornées : une liste de Drive
     * partagés ne justifie pas une boucle ouverte.
     */
    private const DRIVES_PAGE_SIZE = 100;
    private const DRIVES_MAX_PAGES = 10;

    /** Code d'exception dédié : export d'un Google Doc natif trop volumineux (limite Google ~10 Mo). */
    public const CODE_EXPORT_TOO_BIG = 413;

    /**
     * Google Docs natifs → format d'export. Les autres types vnd.google-apps.*
     * (forms, shortcuts, maps…) ne sont pas exportables en document texte et
     * sont ignorés par la synchronisation.
     */
    public const EXPORT_MAP = [
        'application/vnd.google-apps.document' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ext'  => 'docx',
        ],
        'application/vnd.google-apps.spreadsheet' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ext'  => 'xlsx',
        ],
        'application/vnd.google-apps.presentation' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ext'  => 'pptx',
        ],
    ];

    /**
     * Id volontairement inexistant, sondé par le test de connexion : Google
     * répond 404 uniquement si la clé est acceptée ET l'API Drive activée —
     * toute autre réponse identifie le problème d'identifiants.
     */
    private const PROBE_FILE_ID = 'myllm-test-connexion-000000000000';

    /** @var (callable():string)|null Fournit un access token OAuth frais ; null = mode clé API. */
    private $bearerSupplier = null;

    /**
     * Dossier → id de son Drive partagé ('' = hors Drive partagé), appris par
     * `files.get` : resolveFolder le mémorise pour le listage qui le suit
     * dans le même cycle, sans second appel.
     *
     * @var array<string,string>
     */
    private array $driveIdOf = [];

    /** @var array<string,true> Drive partagés dont le corpus a été refusé à ce compte (non membre). */
    private array $driveCorpusRefused = [];

    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * Variante OAuth : lit le Drive avec les droits de l'utilisateur dont le
     * supplier fournit l'access token. Le supplier peut lever (connexion
     * révoquée, Google injoignable) — l'appelant traite ça comme tout échec
     * de cycle.
     *
     * @param callable():string $supplier
     */
    public static function withBearerSupplier(callable $supplier): self
    {
        $p = new self('');
        $p->bearerSupplier = $supplier;
        return $p;
    }

    /**
     * Extrait l'id de dossier d'une saisie utilisateur : URL Drive
     * (…/drive/folders/{id}, …?id={id}) ou id brut. Null si non reconnu.
     * L'id est validé strictement (alphanumérique + tirets) : il est ensuite
     * injecté tel quel dans le paramètre de requête `q` du listing.
     */
    public static function parseFolderInput(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (preg_match('#/folders/([a-zA-Z0-9_-]{10,})#', $input, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,})#', $input, $m) === 1) {
            return $m[1];
        }
        // Les racines virtuelles de l'explorateur ne sont pas des dossiers :
        // `sharedWithMe` et `sharedDrives` passeraient le motif générique
        // ci-dessous et seraient enregistrés comme dossier à synchroniser,
        // pour échouer en 404 au premier cycle.
        if (in_array($input, [self::REF_MY_DRIVE, self::REF_SHARED, self::REF_SHARED_DRIVES], true)) {
            return null;
        }
        if (preg_match('#^[a-zA-Z0-9_-]{10,}$#', $input) === 1) {
            return $input;
        }
        return null;
    }

    /**
     * Un niveau d'arborescence pour l'explorateur (cf. BrowsableFolderProvider).
     * `''` rend une racine VIRTUELLE à trois entrées (Mon Drive / Drive
     * partagés / Partagés avec moi) : Drive n'a pas de dossier unique couvrant
     * les trois. Un dossier partagé nominativement — le cas d'usage du mode
     * « ma connexion » — vit hors de « Mon Drive », et un Drive partagé
     * n'apparaît dans AUCUN des deux : c'est l'organisation qui en est
     * propriétaire, l'utilisateur n'en est que membre.
     *
     * En mode clé API globale il n'y a AUCUNE identité : `''` et `root`
     * pointeraient le Drive de personne. L'explorateur exige donc le mode
     * OAuth, sauf pour descendre dans un dossier explicitement fourni.
     */
    public function browse(string $folderRef = '', int $limit = 300): array
    {
        $folderRef = trim($folderRef);

        if ($folderRef === '') {
            $this->requireIdentity();
            return [
                'ref'        => '',
                'name'       => 'Google Drive',
                'parent_ref' => null,
                'selectable' => false,
                // Ordre de la barre latérale de Google Drive.
                'folders'    => [
                    ['ref' => self::REF_MY_DRIVE,      'name' => t('Mon Drive')],
                    ['ref' => self::REF_SHARED_DRIVES, 'name' => t('Drive partagés')],
                    ['ref' => self::REF_SHARED,        'name' => t('Partagés avec moi')],
                ],
                'files'      => [],
                'truncated'  => false,
            ];
        }

        if ($folderRef === self::REF_SHARED_DRIVES) {
            $this->requireIdentity();
            return $this->browseSharedDrives($limit);
        }

        if ($folderRef === self::REF_SHARED) {
            $this->requireIdentity();
            return $this->browseQuery(
                'sharedWithMe and trashed=false',
                self::REF_SHARED,
                t('Partagés avec moi'),
                '',
                false,
                $limit
            );
        }

        if ($folderRef === self::REF_MY_DRIVE) {
            $this->requireIdentity();
            return $this->browseQuery(
                "'root' in parents and trashed=false",
                self::REF_MY_DRIVE,
                t('Mon Drive'),
                '',
                false,
                $limit
            );
        }

        // La référence vient du navigateur et finit dans la requête `q` :
        // un identifiant Drive ne contient ni apostrophe ni espace.
        if (preg_match('#^[a-zA-Z0-9_-]+$#', $folderRef) !== 1) {
            throw new \RuntimeException('Dossier Google Drive non reconnu.');
        }

        // Dossier réel : nom et parent lus d'abord (fil d'Ariane + bouton
        // « remonter » même quand l'explorateur s'ouvre au milieu de l'arbre).
        $meta   = $this->requestJson('/files/' . rawurlencode($folderRef), [
            'fields'            => 'id,name,mimeType,parents,driveId',
            'supportsAllDrives' => 'true',
        ]);
        if ((string)($meta['mimeType'] ?? '') !== self::FOLDER_MIME) {
            throw new \RuntimeException('Cet élément est un fichier, pas un dossier Google Drive.');
        }
        $parents = is_array($meta['parents'] ?? null) ? $meta['parents'] : [];
        // La racine d'un Drive partagé n'a pas de parent : au-dessus d'elle,
        // il y a la liste des Drive partagés.
        $parentRef = $parents !== []
            ? (string)$parents[0]
            : (self::isSharedDriveRoot($meta) ? self::REF_SHARED_DRIVES : '');

        return $this->browseQuery(
            "'" . $folderRef . "' in parents and trashed=false",
            $folderRef,
            $this->folderName($meta, $folderRef),
            $parentRef,
            true,
            $limit,
            (string)($meta['driveId'] ?? '')
        );
    }

    /**
     * Racine « Drive partagés » : les Drive partagés dont le compte est
     * MEMBRE (`drives.list`). Jamais `useDomainAdminAccess` : un
     * administrateur Google Workspace verrait défiler TOUS les Drive de
     * l'organisation, y compris ceux dont il ne peut pas lire le contenu.
     *
     * L'id d'un Drive partagé est aussi celui de son dossier racine (ressource
     * `drives` de l'API) : ouvrir un Drive, c'est descendre dans un dossier
     * ordinaire, et le choisir synchronise le Drive entier.
     */
    private function browseSharedDrives(int $limit): array
    {
        $limit     = max(1, min(1000, $limit));
        $drives    = [];
        $pageToken = null;
        for ($page = 0; $page < self::DRIVES_MAX_PAGES; $page++) {
            $query = [
                'pageSize' => (string)self::DRIVES_PAGE_SIZE,
                'fields'   => 'nextPageToken,drives(id,name)',
            ];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->requestJson('/drives', $query);
            foreach (($data['drives'] ?? []) as $d) {
                $id = is_array($d) ? (string)($d['id'] ?? '') : '';
                if ($id !== '') {
                    $drives[] = ['ref' => $id, 'name' => (string)($d['name'] ?? $id)];
                }
            }
            $pageToken = (string)($data['nextPageToken'] ?? '');
            $pageToken = $pageToken !== '' ? $pageToken : null;
            if ($pageToken === null || count($drives) >= $limit) {
                break;
            }
        }
        self::sortByName($drives);

        return [
            'ref'        => self::REF_SHARED_DRIVES,
            'name'       => t('Drive partagés'),
            'parent_ref' => null,
            'selectable' => false,
            'folders'    => array_slice($drives, 0, $limit),
            'files'      => [],
            'truncated'  => $pageToken !== null || count($drives) > $limit,
            // Sans explication, une liste vide passe pour une panne : un
            // compte Google personnel n'a souvent aucun Drive partagé, et un
            // dossier partagé sans ajout au Drive vit dans « Partagés avec moi ».
            'empty'      => t('Votre compte n’est membre d’aucun Drive partagé. Un dossier qu’on vous a partagé sans vous ajouter à son Drive se trouve dans « Partagés avec moi ».'),
        ];
    }

    /**
     * Nom affiché d'un dossier lu par `files.get`. La racine d'un Drive
     * partagé est un dossier comme un autre, mais le nom que l'utilisateur
     * connaît est celui du DRIVE : on le demande à `drives.get`, sans en
     * dépendre (mode clé API sans identité, erreur passagère → nom rendu par
     * `files.get`).
     */
    private function folderName(array $meta, string $fallback): string
    {
        if (self::isSharedDriveRoot($meta) && $this->bearerSupplier !== null) {
            try {
                $drive = $this->requestJson('/drives/' . rawurlencode((string)$meta['id']), ['fields' => 'name'], 15);
                if ((string)($drive['name'] ?? '') !== '') {
                    return (string)$drive['name'];
                }
            } catch (\RuntimeException) {
                // Best-effort : le nom rendu par files.get reste lisible.
            }
        }
        $name = (string)($meta['name'] ?? '');
        return $name !== '' ? $name : $fallback;
    }

    /**
     * Métadonnées `files.get` → racine d'un Drive partagé ? Son id est celui
     * du Drive (`driveId`, renseigné pour tout élément de Drive partagé).
     * Pure, testable.
     */
    public static function isSharedDriveRoot(array $meta): bool
    {
        $id = (string)($meta['id'] ?? '');
        return $id !== '' && (string)($meta['driveId'] ?? '') === $id;
    }

    /**
     * Un listing d'explorateur à partir d'une requête `q` Drive. $driveId =
     * Drive partagé du dossier listé ('' hors Drive partagé, cf. listPage).
     */
    private function browseQuery(
        string $q,
        string $ref,
        string $name,
        string $parentRef,
        bool $selectable,
        int $limit,
        string $driveId = ''
    ): array {
        $limit = max(1, min(1000, $limit));
        // Pas d'orderBy côté API : tous les critères ne sont pas acceptés sur
        // toutes les requêtes (« sharedWithMe » notamment), et un 400 pour un
        // simple tri d'affichage casserait la navigation. Tri en PHP.
        $data  = $this->listPage([
            'q'                         => $q,
            'fields'                    => 'incompleteSearch,files(id,name,mimeType,md5Checksum,modifiedTime,size)',
            'pageSize'                  => (string)$limit,
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
        ], $driveId);

        $folders = [];
        $files   = [];
        foreach (($data['files'] ?? []) as $f) {
            $id = (string)($f['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ((string)($f['mimeType'] ?? '') === self::FOLDER_MIME) {
                $folders[] = ['ref' => $id, 'name' => (string)($f['name'] ?? $id)];
                continue;
            }
            $files[] = self::normalizeFile($f);
        }
        self::sortByName($folders);
        self::sortByName($files);

        return [
            'ref'        => $ref,
            'name'       => $name,
            'parent_ref' => $parentRef !== '' ? $parentRef : null,
            'selectable' => $selectable,
            'folders'    => $folders,
            'files'      => $files,
            'truncated'  => count($folders) + count($files) >= $limit
                || ($data['incompleteSearch'] ?? false) === true,
        ];
    }

    /** Tri d'affichage par nom, insensible à la casse et aux accents. */
    public static function sortByName(array &$rows): void
    {
        usort($rows, static fn(array $a, array $b): int
            => strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    }

    /**
     * L'explorateur suppose une identité : une clé API globale n'est le Drive
     * de personne. Message explicite plutôt qu'un listing vide inexplicable.
     */
    private function requireIdentity(): void
    {
        if ($this->bearerSupplier === null) {
            throw new \RuntimeException(
                'La navigation dans les dossiers demande une connexion Google Drive personnelle '
                . '(« Ma connexion Google Drive »). Avec la clé API globale, collez le lien du dossier partagé.'
            );
        }
    }

    /**
     * Métadonnées du dossier (bouton « Tester » et début de cycle).
     * Throw RuntimeException à message utilisateur si inaccessible ou si
     * l'id ne pointe pas un dossier.
     */
    public function resolveFolder(string $folderRef): array
    {
        $data = $this->requestJson('/files/' . rawurlencode($folderRef), [
            'fields'            => 'id,name,mimeType,driveId',
            'supportsAllDrives' => 'true',
        ]);
        if (($data['mimeType'] ?? '') !== self::FOLDER_MIME) {
            throw new \RuntimeException('Le lien fourni pointe un fichier, pas un dossier Google Drive.');
        }
        $this->driveIdOf[$folderRef] = (string)($data['driveId'] ?? '');
        return ['name' => $this->folderName($data, $folderRef)];
    }

    /**
     * Listing récursif (BFS) des FICHIERS d'un dossier et de ses sous-dossiers,
     * au format normalisé de RemoteFolderProvider. Profondeur plafonnée,
     * dossiers visités dédupliqués (cycles via raccourcis), fichiers dédupliqués
     * par id (un fichier peut avoir plusieurs parents). Les éléments dans la
     * corbeille sont exclus par la requête. Tout le sous-arbre d'un dossier de
     * Drive partagé vit dans ce même Drive : un seul corpus pour le parcours.
     */
    public function listFolderRecursive(string $folderRef, int $maxDepth = 10): array
    {
        $files   = [];
        $visited = [$folderRef => true];
        $queue   = [[$folderRef, 0]];
        $driveId = $this->driveIdFor($folderRef);

        while ($queue !== []) {
            [$dirId, $depth] = array_shift($queue);
            $pageToken = null;

            do {
                $query = [
                    'q'                         => "'" . $dirId . "' in parents and trashed=false",
                    // `incompleteSearch` n'arrive QUE s'il est demandé : sans
                    // lui dans `fields`, la garde ci-dessous serait inerte.
                    'fields'                    => 'nextPageToken,incompleteSearch,files(id,name,mimeType,md5Checksum,modifiedTime,size)',
                    'pageSize'                  => '1000',
                    'supportsAllDrives'         => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ];
                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }
                $data = $this->listPage($query, $driveId);
                if (($data['incompleteSearch'] ?? false) === true) {
                    // Jamais de miroir sur un listage partiel : les fichiers
                    // absents de la réponse seraient SUPPRIMÉS de la base.
                    throw new \RuntimeException(
                        'Google Drive a renvoyé un listage incomplet du dossier — aucun document retiré, '
                        . 'nouvelle tentative au prochain cycle.'
                    );
                }

                foreach (($data['files'] ?? []) as $f) {
                    $id   = (string)($f['id'] ?? '');
                    $mime = (string)($f['mimeType'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    if ($mime === self::FOLDER_MIME) {
                        if ($depth + 1 <= $maxDepth && !isset($visited[$id])) {
                            $visited[$id] = true;
                            $queue[] = [$id, $depth + 1];
                        }
                        continue;
                    }
                    $files[$id] = self::normalizeFile($f);
                }
                $pageToken = isset($data['nextPageToken']) ? (string)$data['nextPageToken'] : null;
            } while ($pageToken !== null);
        }

        return array_values($files);
    }

    /**
     * Drive partagé d'un dossier ('' hors Drive partagé). Mémorisé par
     * resolveFolder, qui précède toujours le listage dans un cycle ; sinon un
     * `files.get`. Sans identité, inutile de le demander : le corpus d'un
     * Drive n'est ouvert qu'à ses membres (cf. listPage).
     */
    private function driveIdFor(string $folderRef): string
    {
        if ($this->bearerSupplier === null) {
            return '';
        }
        if (!isset($this->driveIdOf[$folderRef])) {
            $meta = $this->requestJson('/files/' . rawurlencode($folderRef), [
                'fields'            => 'id,driveId',
                'supportsAllDrives' => 'true',
            ]);
            $this->driveIdOf[$folderRef] = (string)($meta['driveId'] ?? '');
        }
        return $this->driveIdOf[$folderRef];
    }

    /**
     * Une page de `files.list`. Pour un dossier de Drive partagé, lu avec une
     * identité, la requête vise le corpus DE CE DRIVE (`corpora=drive` +
     * `driveId`) : le corpus par défaut (`user`) ne couvre, côté Drive
     * partagé, que « les fichiers que l'utilisateur a déjà ouverts » (doc
     * Google, « Implement shared drive support ») — un miroir qui omettrait
     * tout fichier jamais ouvert, sans la moindre erreur.
     *
     * Ce corpus est réservé aux MEMBRES du Drive. Un compte à qui un seul
     * dossier du Drive a été partagé se le voit refuser (403
     * `teamDriveMembershipRequired`, documenté) : repli sur le corpus par
     * défaut (le comportement d'avant), retenu pour la suite du parcours.
     */
    private function listPage(array $query, string $driveId): array
    {
        if ($driveId !== '' && $this->bearerSupplier !== null && !isset($this->driveCorpusRefused[$driveId])) {
            $scoped = $query + ['corpora' => 'drive', 'driveId' => $driveId];
            [$status, $body] = $this->httpGet(self::API_BASE . '/files?' . http_build_query($scoped), 30);
            if ($body === false || $status < 400 || !self::isDriveCorpusRefusal($status, $body)) {
                $data = json_decode($this->checkedBody('/files', $status, $body), true);
                return is_array($data) ? $data : [];
            }
            error_log('[KB Sync] Drive partagé ' . $driveId . ' : corpus refusé (HTTP ' . $status
                . '), repli sur le corpus par défaut');
            $this->driveCorpusRefused[$driveId] = true;
        }
        return $this->requestJson('/files', $query);
    }

    /**
     * Refus du corpus d'un Drive partagé, qui autorise le repli (pure,
     * testable) : 404 (Drive inconnu de ce compte) ou 403 d'accès. Large à
     * dessein — le repli n'est que le comportement d'avant —, sauf deux 403
     * qu'il ne réglerait pas : quota ou limite de débit (Google les range en
     * 403, domaine `usageLimits` ; se replier doublerait les appels refusés)
     * et scope manquant (le message doit nommer la reconnexion).
     */
    public static function isDriveCorpusRefusal(int $status, string $body): bool
    {
        if ($status === 404) {
            return true;
        }
        return $status === 403
            && preg_match('/limit_?exceeded|quota|usageLimits/i', $body) !== 1
            && !GoogleUserApiService::isScopeError($body);
    }

    /**
     * Fichier Drive brut → entrée normalisée de RemoteFolderProvider (pure,
     * testable). Partagée par le miroir et l'explorateur : deux conversions
     * recopiées auraient divergé au premier champ ajouté.
     */
    public static function normalizeFile(array $f): array
    {
        $id   = (string)($f['id'] ?? '');
        $mime = (string)($f['mimeType'] ?? '');
        return [
            'id'       => $id,
            'name'     => (string)($f['name'] ?? $id),
            'mime'     => $mime,
            'size'     => (int)($f['size'] ?? 0),
            'hash'     => isset($f['md5Checksum']) ? (string)$f['md5Checksum'] : null,
            'modified' => (string)($f['modifiedTime'] ?? ''),
            'export'   => self::EXPORT_MAP[$mime] ?? null,
        ];
    }

    /**
     * Télécharge une entrée normalisée : export (Google Docs natifs, limite
     * Google ~10 Mo → exception code CODE_EXPORT_TOO_BIG) ou binaire alt=media.
     */
    public function fetchTo(array $file, string $destPath): void
    {
        $export = $file['export'] ?? null;
        if ($export !== null) {
            $body = $this->requestRaw('/files/' . rawurlencode((string)$file['id']) . '/export', [
                'mimeType' => (string)$export['mime'],
            ]);
        } else {
            $body = $this->requestRaw('/files/' . rawurlencode((string)$file['id']), [
                'alt'               => 'media',
                'supportsAllDrives' => 'true',
            ]);
        }
        if (@file_put_contents($destPath, $body) === false) {
            throw new \RuntimeException('Écriture impossible sur le stockage local.');
        }
    }

    /**
     * Test de connexion (bouton « Tester la connexion » des Paramètres) :
     * sonde un fichier volontairement inexistant, sans dossier requis.
     * 404 = identifiants opérationnels ; tout autre statut est traduit en
     * diagnostic actionnable. Ne lève jamais.
     *
     * @return array{ok:bool, error?:string, detail?:string}
     */
    public function testConnection(): array
    {
        [$status, $body] = $this->httpGet(
            self::API_BASE . '/files/' . self::PROBE_FILE_ID . '?fields=id',
            15
        );
        if ($body === false) {
            return ['ok' => false, 'error' => 'Google Drive injoignable — vérifiez l\'accès réseau sortant du serveur.'];
        }
        if ($status >= 400 && $status !== 404) {
            error_log('[KB Sync] Drive test connexion HTTP ' . $status . ' : ' . mb_substr($body, 0, 300));
        }
        return self::connectionVerdict($status, $body);
    }

    /** Statut HTTP de la sonde → verdict à message utilisateur (pur, testable). */
    public static function connectionVerdict(int $status, string $body): array
    {
        if ($status === 404) {
            return ['ok' => true, 'detail' => 'Clé API valide et API Google Drive activée sur le projet.'];
        }
        if ($status === 400 || $status === 401) {
            return [
                'ok'    => false,
                'error' => 'Clé API Google invalide (HTTP ' . $status . ') — vérifiez la valeur copiée '
                    . 'depuis la console Google Cloud.',
            ];
        }
        if ($status === 403) {
            if (str_contains($body, 'SERVICE_DISABLED') || str_contains($body, 'accessNotConfigured')) {
                return [
                    'ok'    => false,
                    'error' => 'Clé reconnue mais API Google Drive non activée sur le projet — activez-la '
                        . 'dans la console Google Cloud (API et services → Bibliothèque) puis réessayez.',
                ];
            }
            if (str_contains($body, 'API_KEY_HTTP_REFERRER_BLOCKED')) {
                return [
                    'ok'    => false,
                    'error' => 'La clé refuse les appels serveur : retirez sa restriction « référents HTTP » '
                        . '(les appels viennent du serveur, sans en-tête de provenance).',
                ];
            }
            if (str_contains($body, 'API_KEY_IP_ADDRESS_BLOCKED')) {
                return [
                    'ok'    => false,
                    'error' => 'La clé restreint les adresses IP autorisées : ajoutez l\'IP du serveur '
                        . 'ou retirez la restriction.',
                ];
            }
            if (str_contains($body, 'API_KEY_SERVICE_BLOCKED')) {
                return [
                    'ok'    => false,
                    'error' => 'La clé n\'autorise pas l\'API Google Drive : ajoutez l\'API Drive dans '
                        . 'les restrictions d\'API de la clé.',
                ];
            }
            return [
                'ok'    => false,
                'error' => 'Accès refusé par Google (HTTP 403) — vérifiez les restrictions de la clé '
                    . 'et l\'activation de l\'API Drive sur le projet.',
            ];
        }
        if ($status === 429) {
            return ['ok' => false, 'error' => 'Quota de l\'API Google Drive atteint — réessayez dans quelques minutes.'];
        }
        return ['ok' => false, 'error' => 'Réponse inattendue de Google Drive (HTTP ' . $status . ').'];
    }

    // ===================== HTTP =====================

    /** Requête JSON (métadonnées, listing). */
    private function requestJson(string $path, array $query, int $timeout = 30): array
    {
        $data = json_decode($this->requestRaw($path, $query, $timeout), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Requête brute vers l'API Drive. Erreur réseau ou statut ≥ 400 →
     * RuntimeException à message utilisateur SANS URL ni clé (le corps
     * d'erreur Google est loggé, tronqué).
     */
    private function requestRaw(string $path, array $query, int $timeout = 120): string
    {
        $url = self::API_BASE . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        [$status, $response] = $this->httpGet($url, $timeout);
        return $this->checkedBody($path, $status, $response);
    }

    /** Réponse de httpGet → corps, ou exception à message utilisateur (corps d'erreur loggé, tronqué). */
    private function checkedBody(string $path, int $status, string|false $response): string
    {
        if ($response === false) {
            throw new \RuntimeException('Google Drive injoignable — nouvelle tentative au prochain cycle.');
        }
        if ($status >= 400) {
            error_log('[KB Sync] Drive GET ' . $path . ' HTTP ' . $status . ' : ' . mb_substr($response, 0, 300));
            throw $this->presentHttpError($status, $response);
        }
        return $response;
    }

    /**
     * GET brut, clé en header, sans exception : [statut, corps|false].
     * Protégée et non privée : les tests la remplacent par un transport
     * factice pour vérifier les requêtes émises, sans réseau.
     */
    protected function httpGet(string $url, int $timeout): array
    {
        // Le jeton OAuth est redemandé à CHAQUE requête : le supplier tient le
        // cache et le refresh, un cycle de 100 fichiers dépasse la durée de
        // vie d'un access token.
        $auth = $this->bearerSupplier !== null
            ? 'Authorization: Bearer ' . ($this->bearerSupplier)()
            : 'x-goog-api-key: ' . $this->apiKey;
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => $auth,
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
        return [$status, $response];
    }

    /** Statut HTTP Google → exception à message utilisateur actionnable. */
    private function presentHttpError(int $status, string $body): \RuntimeException
    {
        $oauth = $this->bearerSupplier !== null;
        if ($status === 404) {
            return new \RuntimeException($oauth
                ? 'Dossier introuvable ou non partagé avec le compte Google connecté. Vérifiez que le '
                    . 'dossier est bien partagé avec ce compte (ou lui appartient), et que le lien est le bon.'
                : 'Dossier introuvable ou non partagé. Dans Google Drive : clic droit sur le dossier → '
                    . 'Partager → Accès général : « Tous les utilisateurs disposant du lien » (Lecteur).');
        }
        if ($status === 403 && str_contains($body, 'exportSizeLimitExceeded')) {
            return new \RuntimeException(
                'Export impossible : document Google trop volumineux (limite Google ~10 Mo).',
                self::CODE_EXPORT_TOO_BIG
            );
        }
        // Scope manquant : c'est la CONNEXION qui n'a pas reçu la lecture du
        // Drive (case décochée à l'écran Google), pas le compte qui manque de
        // droits sur le dossier — reconnecter est le remède, et le dire.
        if ($status === 403 && $oauth && GoogleUserApiService::isScopeError($body)) {
            return new \RuntimeException(UserDriveService::MSG_READ_NOT_GRANTED);
        }
        if ($status === 403) {
            return new \RuntimeException($oauth
                ? 'Accès refusé par Google (HTTP 403). Le compte connecté n\'a pas accès à ce dossier, '
                    . 'ou l\'API Drive est désactivée sur le projet OAuth.'
                : 'Accès refusé par Google (HTTP 403). Vérifiez que l\'API Drive est activée sur le projet '
                    . 'de la clé, que la clé n\'a pas de restriction « référents HTTP », et que le dossier est '
                    . 'partagé « Tous les utilisateurs disposant du lien ».');
        }
        if ($status === 400 || $status === 401) {
            return new \RuntimeException($oauth
                ? 'Connexion Google Drive expirée ou révoquée (HTTP ' . $status . ') — reconnectez-la dans '
                    . 'Réglages → Comptes connectés.'
                : 'Clé API Google invalide ou requête rejetée (HTTP ' . $status . ').');
        }
        if ($status === 429) {
            return new \RuntimeException('Quota de l\'API Google Drive atteint — nouvelle tentative au prochain cycle.');
        }
        return new \RuntimeException('Erreur Google Drive (HTTP ' . $status . ').');
    }
}
