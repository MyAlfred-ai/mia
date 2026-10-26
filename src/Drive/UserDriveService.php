<?php

declare(strict_types=1);

/**
 * Accès au Google Drive PERSONNEL d'un utilisateur (OAuth par utilisateur,
 * tokens UserDriveToken) : recherche et lecture de fichiers (Docs/Sheets
 * natifs exportés docx/xlsx, binaires supportés par FileExtractor), et
 * ÉCRITURE Google Docs / Sheets (création + modification via les APIs Docs
 * v1 et Sheets v4) si les scopes correspondants ont été accordés — vérifiés
 * par canWriteDocs()/canWriteSheets() sur la colonne scope (un utilisateur
 * connecté avant l'ajout des scopes écriture reste en lecture seule jusqu'à
 * reconnexion).
 *
 * Distinct de GoogleDriveProvider (sync KB, clé API globale, dossiers
 * publics). Plomberie commune (refresh lazy, retry 401, erreurs FR sans
 * fuite) : GoogleUserApiService — ce fichier ne porte que le métier Drive.
 */
class UserDriveService extends GoogleUserApiService
{
    private const API_BASE   = 'https://www.googleapis.com/drive/v3';
    private const DOCS_API   = 'https://docs.googleapis.com/v1';
    private const SHEETS_API = 'https://sheets.googleapis.com/v4';

    protected const OAUTH_CLASS       = GoogleDriveOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER;
    protected const API_LABEL         = 'Google Drive';
    protected const LOG_LABEL         = '[Drive user]';
    protected const MSG_NOT_FOUND     = 'Fichier introuvable ou non accessible avec ce compte Google.';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par Google (HTTP 400) — vérifiez la plage ou le contenu demandé.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par Google Drive sur ce fichier';
    protected const MSG_SCOPE_INSUFFICIENT = self::MSG_RECONNECT_WRITE;

    public const SCOPE_DOCS   = 'https://www.googleapis.com/auth/documents';
    public const SCOPE_SHEETS = 'https://www.googleapis.com/auth/spreadsheets';
    /**
     * LECTURE de tout le Drive. Vérifié comme les autres — il ne l'était pas,
     * et c'était le trou : sans lui l'application garde `drive.file` et voit un
     * Drive vide au lieu d'échouer (cf. MSG_READ_NOT_GRANTED).
     */
    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/drive.readonly';
    /** Renommer / déplacer : métadonnées seules (cf. GoogleDriveOAuth). */
    public const SCOPE_METADATA = 'https://www.googleapis.com/auth/drive.metadata';
    /** Dupliquer : accès PAR FICHIER, limité aux fichiers créés par l'app. */
    public const SCOPE_FILE = 'https://www.googleapis.com/auth/drive.file';

    private const MIME_GDOC   = 'application/vnd.google-apps.document';
    private const MIME_GSHEET = 'application/vnd.google-apps.spreadsheet';

    /** Dossier : le seul MIME qui ne se LIT pas, mais se PARCOURT (listFolder). */
    public const MIME_FOLDER  = 'application/vnd.google-apps.folder';

    /**
     * Balayage local de secours (cf. `scanFiles`).
     *
     * L'API Drive ne sait pas faire ce qu'on lui demande ici : `name contains`
     * ne matche qu'en PRÉFIXE (documenté : un fichier « HelloWorld » sort sur
     * `contains 'Hello'`, jamais sur `contains 'World'`), `fullText contains`
     * ne matche que des jetons ENTIERS, et ni l'un ni l'autre n'aplatit les
     * accents. Une recherche « reunion equipe » ne trouvait donc pas
     * « Compte-rendu Réunion Équipe ». Quand la requête serveur ne rend rien,
     * on liste et on filtre ici — coûteux, donc en dernier recours seulement.
     */
    private const SCAN_PAGE_SIZE = 1000;
    private const SCAN_MAX_PAGES = 3;

    /**
     * NAVIGATION plutôt que recherche : bornes du chemin « A/B/C » et de la
     * recherche scopée à un dossier.
     *
     * La descente se fait NIVEAU PAR NIVEAU, jamais dossier par dossier : Drive
     * accepte plusieurs `'id' in parents` dans un même `q`, donc un niveau =
     * UN appel. Un parcours naïf de « 01-CLIENTS » et ses 200 clients aurait
     * coûté 200 appels ; ici il en coûte deux.
     */
    private const PATH_MAX_SEGMENTS   = 6;
    private const SUBTREE_MAX_DEPTH   = 3;
    private const SUBTREE_MAX_FOLDERS = 150;
    /** Enfants ramenés d'un dossier (pages de 1000) avant tri et filtrage local. */
    private const CHILDREN_MAX_PAGES  = 3;

    /**
     * Page de la passe CONTENU, large exprès.
     *
     * `fullText contains 'INITECH'` ramène tout document qui *mentionne* INITECH —
     * des centaines sur un Drive d'entreprise. Google tronque côté serveur (et
     * **ignore `orderBy` dès que `q` contient `fullText`**, donc la coupe est
     * arbitraire) : aucun tri local ne peut rattraper ce que la page n'a pas
     * ramené. D'où une page large ici, et surtout une passe NOM séparée.
     */
    private const CONTENT_PAGE = 100;

    /**
     * Mots vides retirés des requêtes. Ils ne trient rien et, joints par `and`,
     * ils EXCLUENT : « le compte rendu de la réunion » ne devait pas exiger que
     * le nom du fichier contienne « le », « de » et « la ».
     */
    private const STOP_WORDS = [
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'au', 'aux', 'a',
        'et', 'ou', 'en', 'dans', 'sur', 'pour', 'par', 'avec', 'sans',
        'mon', 'ma', 'mes', 'notre', 'nos', 'ce', 'cet', 'cette', 'ces',
        'qui', 'que', 'quoi', 'dont', 'est', 'sont',
        'the', 'of', 'and', 'my', 'in', 'on', 'for',
    ];

    public const MSG_RECONNECT_WRITE = 'Autorisation d\'écriture non accordée. '
        . 'L\'utilisateur doit reconnecter son Google Drive dans Réglages → Comptes connectés '
        . 'pour accorder la modification de ses Docs/Sheets.';

    public const MSG_RECONNECT_ORGANIZE = 'Autorisation de rangement non accordée. '
        . 'L\'utilisateur doit reconnecter son Google Drive dans Réglages → Comptes connectés '
        . 'pour permettre le renommage et le déplacement de ses fichiers.';

    public const MSG_RECONNECT_COPY = 'Autorisation de duplication non accordée. '
        . 'L\'utilisateur doit reconnecter son Google Drive dans Réglages → Comptes connectés '
        . 'pour permettre la copie de ses fichiers.';

    /**
     * Lecture (`drive.readonly`) NON accordée — la panne la plus déroutante du
     * connecteur, parce qu'elle ne ressemble pas à une panne.
     *
     * Google présente ses autorisations en cases à cocher séparées, et celles
     * qui portent sur TOUT le Drive (lecture, métadonnées) sont des scopes
     * « restreints », les premiers qu'un utilisateur décoche et que la
     * politique d'une organisation peut refuser. Sans `drive.readonly` il
     * reste `drive.file`, un accès PAR FICHIER limité aux fichiers créés par
     * l'application : `files.list` répond 200 avec une liste vide et
     * `files.get` répond 404 sur un fichier pourtant bien présent. Aucune
     * erreur, donc — juste un Drive qui a l'air vide, avec le bon compte
     * connecté et le bon dossier partagé. D'où ce message, qui nomme la case.
     */
    public const MSG_READ_NOT_GRANTED = 'MIA n\'a pas l\'autorisation de voir les fichiers de ce Drive : '
        . 'sur l\'écran de consentement Google, la case « Voir et télécharger tous vos fichiers Google Drive » '
        . 'n\'a pas été accordée. Elle ne voit donc QUE les fichiers qu\'elle a elle-même créés — ce n\'est ni '
        . 'le compte, ni les droits de l\'utilisateur sur ses dossiers qui sont en cause. Pour y remédier : '
        . 'Réglages → Comptes connectés → « Reconnecter et autoriser », en laissant TOUTES les cases cochées.';

    /**
     * 403 « scope insuffisant » (ACCESS_TOKEN_SCOPE_INSUFFICIENT) : c'est
     * l'APPLICATION qui n'a pas l'autorisation, pas l'utilisateur qui manque
     * de droits. Message distinct de MSG_RECONNECT_WRITE, qui ne parle que des
     * Docs/Sheets : l'autorisation manquante peut aussi bien être la lecture
     * ou le rangement, et nommer la mauvaise envoie chercher au mauvais
     * endroit.
     */
    public const MSG_SCOPE_MISSING = 'Google a refusé l\'opération : l\'autorisation correspondante n\'a pas été '
        . 'accordée à MIA lors de la connexion du Drive (les cases de l\'écran Google sont décochables une à une). '
        . 'L\'utilisateur doit reconnecter son Google Drive dans Réglages → Comptes connectés en laissant TOUTES les '
        . 'cases cochées. Ses droits Google sur le fichier ne sont pas en cause.';

    /**
     * 403 sur `files.copy` alors que le scope EST accordé : `drive.file` est un
     * accès par fichier limité à ceux que l'application a créés, et le fichier
     * source n'en fait pas partie. Le dire, plutôt que de laisser croire à une
     * panne — et sans proposer de reconnexion, qui n'y changerait rien.
     */
    public const MSG_COPY_REFUSED = 'Google a refusé la copie de ce fichier : l\'autorisation accordée '
        . '(accès par fichier) ne couvre que les fichiers créés par cette application. '
        . 'L\'utilisateur peut dupliquer le fichier lui-même depuis Google Drive '
        . '(clic droit → « Créer une copie »).';

    /**
     * Accès PAR FICHIER refusé par Google (`appNotAuthorizedToFile`, « The user
     * has not granted the app … access to the file »).
     *
     * CE MESSAGE A ÉTÉ FAUX, et c'est ce qui a coûté le plus cher : il
     * affirmait que `drive.metadata` était, comme `drive.file`, un accès limité
     * aux fichiers créés par l'application — donc qu'un renommage ou un
     * déplacement était STRUCTURELLEMENT impossible ailleurs — et concluait que
     * se reconnecter n'y changerait rien. La documentation Google dit l'inverse :
     * `drive.metadata` est un scope RESTREINT de portée globale (« View and
     * manage metadata of files in your Drive »), et seul `drive.file` est un
     * accès par fichier.
     *
     * Cette erreur ne peut donc survenir que si `drive.metadata` (ou
     * `drive.readonly`) n'a PAS été accordé et qu'il ne reste que `drive.file` :
     * la reconnexion, toutes cases cochées, est bel et bien le remède. Le
     * message précédent envoyait l'utilisateur faire le travail à la main en
     * lui affirmant qu'il n'y avait rien à tenter, et la guidance du modèle
     * répétait la même chose à chaque échec.
     */
    public const MSG_PER_FILE_REFUSED = 'Google refuse cette action sur ce fichier : MIA n\'a reçu qu\'un '
        . 'accès PAR FICHIER (limité aux fichiers qu\'elle a elle-même créés), parce que l\'autorisation '
        . 'portant sur l\'ensemble du Drive n\'a pas été accordée à la connexion. Ce n\'est pas une question '
        . 'de droits de l\'utilisateur sur le fichier. Remède : Réglages → Comptes connectés → « Reconnecter et '
        . 'autoriser », en laissant TOUTES les cases cochées sur l\'écran Google. À défaut, l\'utilisateur '
        . 'peut faire l\'opération lui-même depuis Google Drive.';

    /** Taille max d'un binaire lu depuis Drive (avant téléchargement). */
    public const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /** Clé Settings : interrupteur global admin du Drive utilisateur dans le chat. */
    public const SETTING_ENABLED = 'user_drive_enabled';

    public const MSG_RECONNECT = 'Connexion Google Drive expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter son Drive dans Réglages → Comptes connectés.';

    /** Docs natifs Google → format d'export lisible par FileExtractor. */
    public const EXPORT_MAP = GoogleDriveProvider::EXPORT_MAP;

    /** MIMEs binaires/texte non natifs acceptés en lecture (extraction FileExtractor). */
    private const READABLE_MIMES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text'         => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet'  => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'application/pdf'   => 'pdf',
        'application/rtf'   => 'rtf',
        'text/rtf'          => 'rtf',
        'text/plain'        => 'txt',
        'text/markdown'     => 'md',
        'text/html'         => 'html',
        'text/csv'          => 'csv',
    ];

    /** Identifiant réel du dossier racine, résolu à la demande (cf. resolveRootAlias). */
    private ?string $rootFolderId = null;

    /** Instance pour l'utilisateur, ou null si Drive non connecté (ou révoqué). */
    public static function forUser(int $userId): ?self
    {
        $row = self::tokenRowForUser($userId);
        if ($row === null) {
            return null;
        }
        return new self(
            $userId,
            (string)$row['refresh_token'],
            $row['access_token'] !== null ? (string)$row['access_token'] : null,
            $row['access_token_expires_at'] !== null ? (string)$row['access_token_expires_at'] : null,
            (string)($row['scope'] ?? ''),
            (string)($row['google_email'] ?? '')
        );
    }

    /**
     * Fournisseur de dossier pour la synchronisation des bases de
     * connaissances : même moteur que la clé API (listing récursif, exports,
     * hash md5), mais avec les droits Drive de CET utilisateur — un dossier
     * partagé nominativement avec lui suffit, aucun partage public. Le
     * supplier rafraîchit l'access token à la demande (cycle long).
     */
    public function syncFolderProvider(): GoogleDriveProvider
    {
        return GoogleDriveProvider::withBearerSupplier(fn(): string => $this->getValidAccessToken());
    }

    /**
     * Scopes réellement accordés — statiques pures, SOURCE UNIQUE partagée
     * avec la page Connexions (qui n'a que la colonne `scope` et n'instancie
     * pas le service). Deux comparaisons divergentes finiraient par afficher
     * « ✓ » sur une connexion qui ne peut rien faire.
     *
     * `scopeGranted` (socle) compare la liste EXACTE : indispensable ici, où
     * `.../auth/drive.metadata` est un PRÉFIXE de `drive.metadata.readonly` —
     * un `str_contains` exposerait les outils de rangement à une connexion en
     * lecture seule, que Google refuserait ensuite en 403.
     */
    public static function grantsDocs(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_DOCS);
    }

    public static function grantsSheets(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_SHEETS);
    }

    /** Renommer / déplacer (métadonnées) accordé ? */
    public static function grantsOrganize(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_METADATA);
    }

    /** Dupliquer un fichier accordé ? */
    public static function grantsCopy(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_FILE);
    }

    /**
     * Lecture de l'ensemble du Drive accordée ?
     *
     * Le scope `drive` complet n'est jamais demandé par cette application,
     * mais il implique la lecture : une connexion qui le porterait (héritée
     * d'un autre consentement au même client OAuth) lit parfaitement, et la
     * déclarer aveugle afficherait un avertissement mensonger.
     */
    public static function grantsRead(string $scope): bool
    {
        return self::scopeGranted($scope, self::SCOPE_READONLY)
            || self::scopeGranted($scope, 'https://www.googleapis.com/auth/drive');
    }

    /** Scope écriture Google Docs accordé lors du consentement ? */
    public function canWriteDocs(): bool
    {
        return self::grantsDocs($this->grantedScope);
    }

    /** Scope écriture Google Sheets accordé lors du consentement ? */
    public function canWriteSheets(): bool
    {
        return self::grantsSheets($this->grantedScope);
    }

    /** Renommer / déplacer un fichier du Drive (métadonnées) accordé ? */
    public function canOrganize(): bool
    {
        return self::grantsOrganize($this->grantedScope);
    }

    /** Dupliquer un fichier du Drive accordé ? */
    public function canCopy(): bool
    {
        return self::grantsCopy($this->grantedScope);
    }

    /** Lecture de l'ensemble du Drive accordée ? (cf. MSG_READ_NOT_GRANTED) */
    public function canRead(): bool
    {
        return self::grantsRead($this->grantedScope);
    }

    /**
     * Un résultat vide vient-il d'une autorisation manquante plutôt que d'un
     * Drive réellement sans correspondance ? Renvoie le message à remonter, ou
     * null quand la lecture est bien accordée (le vide est alors une vraie
     * information).
     *
     * Appelée sur les seuls chemins d'ÉCHEC : tant qu'un résultat arrive, rien
     * ne justifie de parler d'autorisations.
     */
    private function readScopeNote(): ?string
    {
        return $this->canRead() ? null : self::MSG_READ_NOT_GRANTED;
    }

    /**
     * Extrait l'id de fichier d'une saisie : URL Google (Docs/Sheets/Slides/
     * Drive) ou id brut. Null si non reconnu. Validation stricte : l'id est
     * réinjecté dans des chemins d'API.
     */
    public static function parseFileInput(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (preg_match('#/(?:document|spreadsheets|presentation|file)/d/([a-zA-Z0-9_-]{10,})#', $input, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,})#', $input, $m) === 1) {
            return $m[1];
        }
        // URL de DOSSIER (destination d'un déplacement) : Drive n'utilise pas
        // le motif `/d/` pour les dossiers.
        if (preg_match('#/folders/([a-zA-Z0-9_-]{10,})#', $input, $m) === 1) {
            return $m[1];
        }
        if (self::isBareId($input)) {
            return $input;
        }
        return null;
    }

    /**
     * L'entrée est-elle un id NU (ni URL, ni nom de fichier) ?
     *
     * Distinction indispensable au repli par nom : un nom d'un seul mot d'au
     * moins 10 caractères sans accent (« Budget2026 ») passe le motif d'id et
     * partait donc en 404 opaque. Une URL qui répond 404, elle, désigne un
     * fichier réellement inaccessible avec ce compte — le chercher par son nom
     * n'aurait aucun sens.
     */
    public static function isBareId(string $input): bool
    {
        return preg_match('#^[a-zA-Z0-9_-]{10,}$#', trim($input)) === 1;
    }

    // ===================== RECHERCHE =====================
    //
    // Trois passes, de la plus précise à la plus large, et on s'arrête dès
    // qu'une passe donne quelque chose : « tous les mots » (le cas normal),
    // « au moins un mot » (l'utilisateur a ajouté un mot qui n'est pas dans le
    // nom), puis le balayage local, qui seul sait chercher au milieu d'un mot
    // et ignorer les accents (cf. SCAN_PAGE_SIZE).

    /** Minuscules, accents retirés, ponctuation et séparateurs ramenés à des espaces.
     *
     * Appliquée des DEUX côtés — la requête ET le nom renvoyé par Google — pour
     * que « reunion equipe » retrouve « Réunion_Équipe-2026.docx ».
     *
     * Volontairement distincte de `stripAccents()` (iconv //TRANSLIT, dont la
     * sortie dépend de la locale du système : « e » ici, « \'e » ou « ? »
     * ailleurs — inutilisable pour comparer) et de
     * `UserMemory::normalizeForCompare()` (bornée à 300 caractères et taillée
     * pour dédupliquer des phrases, pas des noms de fichiers).
     */
    public static function normalizeForSearch(string $text): string
    {
        $s = mb_strtolower($text);
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        ]);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /**
     * Mots significatifs d'une requête, forme d'origine ET forme normalisée.
     *
     * La forme d'origine sert la requête Drive (le nom stocké chez Google est
     * accentué), la normalisée sert le filtrage local et la comparaison.
     *
     * @return list<array{brut:string,norme:string}>
     */
    public static function searchTokens(string $query): array
    {
        $bruts = preg_split('/[^\p{L}\p{N}]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out   = [];
        $vus   = [];
        foreach ($bruts as $brut) {
            $norme = self::normalizeForSearch((string)$brut);
            if ($norme === '' || mb_strlen($norme) < 2 || in_array($norme, self::STOP_WORDS, true)) {
                continue;
            }
            if (isset($vus[$norme])) {
                continue;
            }
            $vus[$norme] = true;
            $out[] = ['brut' => (string)$brut, 'norme' => $norme];
            if (count($out) >= 8) {
                break; // au-delà, la requête Drive devient énorme sans rien gagner
            }
        }
        return $out;
    }

    /** Littéral sûr pour le paramètre `q` de Drive (apostrophes et antislashs). */
    private static function qLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Requête Drive pour une liste de jetons. `$all` = tous les mots (précis)
     * plutôt qu'au moins un (large).
     *
     * Chaque jeton est cherché sous sa forme d'origine ET sous sa forme sans
     * accent quand elles diffèrent : Google n'aplatit pas les diacritiques,
     * « reunion » ne trouve pas « Réunion ».
     *
     * `$nameOnly` = nom seulement, sans le contenu : c'est le mode des
     * RÉSOLUTIONS (« modifie le compte rendu »), où élargir au contenu
     * ramènerait tous les documents qui PARLENT du sujet et transformerait une
     * désignation claire en ambiguïté. La recherche, elle, veut le contenu.
     *
     * @param list<array{brut:string,norme:string}> $tokens
     */
    public static function buildTokenQuery(array $tokens, bool $all, string $extra = '', bool $nameOnly = false): string
    {
        $clauses = [];
        foreach ($tokens as $t) {
            $formes = array_unique(array_filter([$t['brut'] ?? '', $t['norme'] ?? '']));
            $ou = [];
            foreach ($formes as $forme) {
                $lit = self::qLiteral((string)$forme);
                $ou[] = "name contains '{$lit}'";
                if (!$nameOnly) {
                    $ou[] = "fullText contains '{$lit}'";
                }
            }
            if ($ou !== []) {
                $clauses[] = '(' . implode(' or ', $ou) . ')';
            }
        }
        $q = $clauses === [] ? '' : '(' . implode($all ? ' and ' : ' or ', $clauses) . ') and ';
        return $q . 'trashed=false' . ($extra !== '' ? ' and ' . $extra : '');
    }

    /**
     * Recherche des fichiers du Drive de l'utilisateur (nom OU contenu), large
     * et insensible à la casse comme aux accents. Les DOSSIERS en font partie :
     * ils ne se lisent pas (`lisible: false`) mais se parcourent avec
     * `listFolder()` — les exclure laissait l'utilisateur devant un dossier
     * introuvable, et les inclure sans le dire faisait conclure au modèle qu'il
     * n'y avait rien à en tirer.
     *
     * Retour : liste `{id, nom, type, modifie, taille, lisible, dossier}` triée
     * par pertinence puis par date de modification décroissante.
     * Throw RuntimeException à message FR.
     */
    public function search(string $query, int $limit = 10, string $dansDossier = '', string $type = ''): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \RuntimeException('Requête de recherche vide.');
        }
        $limit  = max(1, min(20, $limit));
        $tokens = self::searchTokens($query);
        if ($tokens === []) {
            // Requête réduite à des mots vides ou à de la ponctuation : on
            // retombe sur la chaîne brute plutôt que de ne rien chercher.
            $tokens = [['brut' => $query, 'norme' => self::normalizeForSearch($query)]];
        }

        $vise  = $limit * 3; // marge pour le tri par pertinence avant découpe
        $extra = self::typeClause($type);

        // Périmètre demandé : on ne cherche PLUS dans tout le Drive. Un point
        // d'entrée donné par l'utilisateur est une information plus sûre que
        // n'importe quel score de pertinence — chercher « INITECH » partout
        // remonte le bruit avant le dossier client, alors que le parent était
        // connu.
        if (trim($dansDossier) !== '') {
            return $this->searchInFolder($tokens, trim($dansDossier), $limit, $vise, $extra);
        }

        // PASSE NOM, toujours jouée et fusionnée EN PREMIER.
        //
        // C'est LE correctif : une seule requête `name or fullText` faisait
        // disparaître un dossier nommé exactement « INITECH » derrière trente
        // documents qui se contentent de mentionner INITECH — Google tronque
        // avant que le tri local ne voie quoi que ce soit. Et un DOSSIER n'a
        // pas de contenu : il ne peut JAMAIS remonter par `fullText`, il est
        // donc le premier évincé. Une passe nom-seul est petite, précise, et
        // ne peut pas être noyée.
        $rows = $this->listFiles(self::buildTokenQuery($tokens, true, $extra, true), $vise);
        if (count($tokens) > 1) {
            $rows = self::mergeById($rows, $this->listFiles(self::buildTokenQuery($tokens, false, $extra, true), $vise));
        }

        // PASSE CONTENU ensuite : « résume mon doc sur X » ne donne pas le nom.
        $rows = self::mergeById($rows, $this->listFiles(self::buildTokenQuery($tokens, true, $extra), self::CONTENT_PAGE));
        if (count($rows) < $limit && count($tokens) > 1) {
            $rows = self::mergeById($rows, $this->listFiles(self::buildTokenQuery($tokens, false, $extra), self::CONTENT_PAGE));
        }

        if ($rows === []) {
            $rows = $this->scanFiles($tokens, self::scanMime($type), $type === 'fichier', $vise);
        }
        // Rien trouvé, ET la lecture n'est pas accordée : le Drive n'est pas
        // vide, MIA est aveugle. Sans ce contrôle, la seule sortie possible
        // était « aucun résultat » — le modèle en concluait que le fichier
        // n'existait pas, l'utilisateur qu'il n'avait « pas accès », et
        // personne ne pouvait deviner qu'une case du consentement Google
        // manquait. Testé APRÈS la recherche : une connexion partielle voit
        // quand même les fichiers créés par l'application, et tant qu'elle
        // trouve, il n'y a rien à signaler.
        if ($rows === [] && ($note = $this->readScopeNote()) !== null) {
            throw new \RuntimeException($note);
        }

        return self::describeAll(self::rankByRelevance($rows, $tokens), $limit);
    }

    /**
     * Restriction de type demandée par l'appelant. « Recherche un DOSSIER
     * nommé INITECH » est une contrainte dure : la faire porter par `q` évite
     * que les fichiers homonymes n'occupent la page.
     */
    private static function typeClause(string $type): string
    {
        return match (trim(mb_strtolower($type))) {
            'dossier' => "mimeType='" . self::MIME_FOLDER . "'",
            'fichier' => "mimeType!='" . self::MIME_FOLDER . "'",
            default   => '',
        };
    }

    /** Même restriction, pour le balayage local (qui filtre par MIME exact). */
    private static function scanMime(string $type): ?string
    {
        return trim(mb_strtolower($type)) === 'dossier' ? self::MIME_FOLDER : null;
    }

    /**
     * Fusion de deux pages de résultats, sans doublon et SANS réordonner :
     * l'ordre d'arrivée porte déjà la priorité (nom avant contenu), et
     * `rankByRelevance` s'en sert pour départager les égalités.
     *
     * @return list<array>
     */
    private static function mergeById(array $rows, array $ajout): array
    {
        $vus = [];
        foreach ($rows as $f) {
            $vus[(string)($f['id'] ?? '')] = true;
        }
        foreach ($ajout as $f) {
            $id = (string)($f['id'] ?? '');
            if ($id === '' || isset($vus[$id])) {
                continue;
            }
            $vus[$id] = true;
            $rows[]   = $f;
        }
        return array_values($rows);
    }

    /**
     * Recherche bornée à un dossier et à sa descendance, niveau par niveau.
     *
     * On s'arrête au PREMIER niveau qui donne quelque chose : un client cherché
     * sous « 01-CLIENTS » est un enfant direct, inutile de descendre dans les
     * 200 sous-arbres pour le confirmer.
     *
     * @param list<array{brut:string,norme:string}> $tokens
     */
    private function searchInFolder(array $tokens, string $dossier, int $limit, int $vise, string $extra = ''): array
    {
        $ref     = $this->resolveFolderPath($dossier);
        $niveau  = [$ref['id']];
        $vus     = 0;

        for ($profondeur = 0; $profondeur < self::SUBTREE_MAX_DEPTH; $profondeur++) {
            $clause = self::parentsClause($niveau) . ($extra !== '' ? ' and ' . $extra : '');
            // Nom d'abord ici aussi : dans un dossier de projet, tout le monde
            // « mentionne » le sujet, seul le nom désigne.
            $rows = $this->listFiles(self::buildTokenQuery($tokens, true, $clause, true), $vise);
            $rows = self::mergeById($rows, $this->listFiles(self::buildTokenQuery($tokens, true, $clause), $vise));
            if ($rows === [] && count($tokens) > 1) {
                $rows = $this->listFiles(self::buildTokenQuery($tokens, false, $clause), $vise);
            }
            if ($rows !== []) {
                return self::describeAll(self::rankByRelevance($rows, $tokens), $limit);
            }

            // Rien à ce niveau : on prépare le suivant (un appel, pas un par
            // dossier), et on s'arrête si l'arborescence s'épuise ou déborde.
            $suivant = [];
            $qSous = self::parentsClause($niveau) . " and trashed=false and mimeType='" . self::MIME_FOLDER . "'";
            foreach ($this->listFiles($qSous, 1000, 'name') as $f) {
                $suivant[] = (string)($f['id'] ?? '');
                if (++$vus >= self::SUBTREE_MAX_FOLDERS) {
                    break;
                }
            }
            $suivant = array_values(array_filter($suivant));
            if ($suivant === [] || $vus >= self::SUBTREE_MAX_FOLDERS) {
                break;
            }
            $niveau = $suivant;
        }
        return [];
    }

    /** Clause `q` couvrant plusieurs parents : Drive les accepte dans un seul appel. */
    private static function parentsClause(array $folderIds): string
    {
        $ou = [];
        foreach ($folderIds as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $ou[] = "'" . self::qLiteral($id) . "' in parents";
            }
        }
        return $ou === [] ? '' : '(' . implode(' or ', $ou) . ')';
    }

    /** Entrées Drive brutes → forme rendue au modèle, bornée. */
    private static function describeAll(array $rows, int $limit): array
    {
        $out = [];
        foreach (array_slice($rows, 0, $limit) as $f) {
            $out[] = self::describeFile($f);
        }
        return $out;
    }

    /**
     * Contenu direct d'un dossier (UN niveau), dossiers d'abord puis par nom.
     *
     * Sans cet outil, un dossier trouvé par la recherche était un cul-de-sac :
     * il ressort forcément `lisible: false` (on ne « lit » pas un dossier) et
     * rien ne permettait d'en atteindre les fichiers.
     *
     * @return array{dossier:array{id:string,nom:string},elements:list<array>,tronque:bool}
     */
    public function listFolder(string $input, int $limit = 50, string $filtre = ''): array
    {
        $limit = max(1, min(200, $limit));
        $ref   = $this->resolveFolderPath($input);
        $rows  = $this->listChildren($ref['id']);
        $total = count($rows);

        // Filtre par nom : sans lui, un dossier de 200 clients tronqué à 50 par
        // ordre alphabétique rendait « INITECH » inatteignable — le modèle voyait
        // `tronque: true` et n'avait AUCUN moyen d'aller plus loin.
        $filtre = trim($filtre);
        if ($filtre !== '') {
            $tokens = self::searchTokens($filtre);
            if ($tokens === []) {
                $tokens = [['brut' => $filtre, 'norme' => self::normalizeForSearch($filtre)]];
            }
            $rows = self::rankByRelevance(self::filterByName($rows, $tokens), $tokens);
        }

        $retenus = count($rows);
        return [
            'dossier'  => ['id' => $ref['id'], 'nom' => $ref['nom']],
            'chemin'   => $ref['chemin'],
            'total'    => $total,
            'retenus'  => $retenus,
            'elements' => self::describeAll($rows, $limit),
            'tronque'  => $retenus > $limit,
        ];
    }

    /**
     * Tous les enfants directs d'un dossier, dossiers d'abord puis par nom.
     * Paginé : `listFiles` ne rend qu'une page, et une page manquante se lisait
     * comme un dossier plus petit qu'il n'est.
     *
     * @return list<array>
     */
    private function listChildren(string $folderId, bool $foldersOnly = false): array
    {
        $q = self::parentsClause([$folderId]) . ' and trashed=false';
        if ($foldersOnly) {
            $q .= " and mimeType='" . self::MIME_FOLDER . "'";
        }

        $out  = [];
        $page = null;
        for ($i = 0; $i < self::CHILDREN_MAX_PAGES; $i++) {
            $params = [
                'q'        => $q,
                'corpora'  => 'allDrives',
                'includeItemsFromAllDrives' => 'true',
                'supportsAllDrives'         => 'true',
                'fields'   => 'nextPageToken,files(id,name,mimeType,modifiedTime,size)',
                'orderBy'  => 'folder,name',
                'pageSize' => (string)self::SCAN_PAGE_SIZE,
            ];
            if ($page !== null && $page !== '') {
                $params['pageToken'] = $page;
            }
            $data = $this->requestJson('/files', $params, 60);
            foreach ((array)($data['files'] ?? []) as $f) {
                if (is_array($f)) {
                    $out[] = $f;
                }
            }
            $page = isset($data['nextPageToken']) ? (string)$data['nextPageToken'] : null;
            if ($page === null || $page === '') {
                break;
            }
        }
        return $out;
    }

    /**
     * Filtre local sur le NOM : tous les mots, à défaut au moins un.
     *
     * @param list<array{brut:string,norme:string}> $tokens
     * @return list<array>
     */
    private static function filterByName(array $rows, array $tokens): array
    {
        $tous = [];
        $part = [];
        foreach ($rows as $f) {
            $nom  = self::normalizeForSearch((string)($f['name'] ?? ''));
            $hits = 0;
            foreach ($tokens as $t) {
                if ($t['norme'] !== '' && str_contains($nom, $t['norme'])) {
                    $hits++;
                }
            }
            if ($hits === 0) {
                continue;
            }
            if ($hits === count($tokens)) {
                $tous[] = $f;
            } else {
                $part[] = $f;
            }
        }
        return $tous !== [] ? $tous : $part;
    }

    /**
     * Résout « 01-CLIENTS/INITECH/03-CR et echanges » en UN appel d'outil.
     *
     * La descente segment par segment fait un MATCH EXACT parmi les enfants du
     * niveau courant : c'est la donnée la plus sûre qui soit quand l'utilisateur
     * connaît son arborescence, alors qu'une recherche globale sur « INITECH »
     * remonte d'abord le bruit du reste du Drive. Sans ce chemin, chaque niveau
     * coûtait un appel d'outil, donc un round du budget (TOOL_MAX_ROUNDS).
     *
     * Une URL ou un identifiant n'est JAMAIS découpé : ils contiennent des « / ».
     *
     * @return array{id:string,nom:string,chemin:string}
     */
    public function folderByPath(string $input): array
    {
        // Point d'entrée public : le dossier d'un filtre de workflow s'écrit
        // comme l'utilisateur le connaît (id, lien, nom ou chemin), et ses
        // créations y sont rangées avec la MÊME résolution que ses recherches.
        return $this->resolveFolderPath($input);
    }

    /** @return array{id:string,nom:string,chemin:string} */
    private function resolveFolderPath(string $input): array
    {
        $input = trim($input);
        if ($input === '' || self::parseFileInput($input) !== null) {
            $ref = $this->folderRef($input);
            return ['id' => $ref['id'], 'nom' => $ref['nom'], 'chemin' => $ref['nom']];
        }

        $segments = array_values(array_filter(
            array_map('trim', explode('/', $input)),
            static fn(string $seg): bool => $seg !== ''
        ));
        if (count($segments) <= 1) {
            $ref = $this->folderRef($input);
            return ['id' => $ref['id'], 'nom' => $ref['nom'], 'chemin' => $ref['nom']];
        }
        if (count($segments) > self::PATH_MAX_SEGMENTS) {
            throw new \RuntimeException(
                'Chemin trop profond (' . count($segments) . ' niveaux, maximum '
                . self::PATH_MAX_SEGMENTS . ') : donne le dossier de départ le plus proche.'
            );
        }

        try {
            $ref    = $this->folderRef(array_shift($segments));
            $chemin = [$ref['nom']];
            foreach ($segments as $segment) {
                $ref      = $this->childFolder($ref['id'], $segment, implode('/', $chemin));
                $chemin[] = $ref['nom'];
            }
            return ['id' => $ref['id'], 'nom' => $ref['nom'], 'chemin' => implode('/', $chemin)];
        } catch (\RuntimeException $e) {
            // Drive AUTORISE le « / » dans un nom : « R&D / Innovation » est un
            // dossier valide, pas un chemin. On ne le tente qu'en rattrapage
            // (un appel de plus sur le seul chemin d'échec), et l'erreur de
            // chemin reste celle qu'on remonte — elle liste les sous-dossiers
            // réellement présents, donc elle est plus utile.
            try {
                $ref = $this->folderRef($input);
                return ['id' => $ref['id'], 'nom' => $ref['nom'], 'chemin' => $ref['nom']];
            } catch (\RuntimeException) {
                throw $e;
            }
        }
    }

    /**
     * Un sous-dossier par son nom, parmi les enfants DIRECTS du parent :
     * correspondance exacte normalisée d'abord, sinon un seul candidat partiel.
     * Plusieurs candidats = erreur listant les noms — deviner ferait descendre
     * dans la mauvaise branche, et la suite du chemin échouerait sans dire où.
     *
     * @return array{id:string,nom:string}
     */
    private function childFolder(string $parentId, string $segment, string $cheminParcouru): array
    {
        $enfants = $this->listChildren($parentId, true);
        $cible   = self::normalizeForSearch($segment);

        $exact = array_values(array_filter(
            $enfants,
            static fn(array $f): bool => self::normalizeForSearch((string)($f['name'] ?? '')) === $cible
        ));
        if (count($exact) === 1) {
            return ['id' => (string)$exact[0]['id'], 'nom' => (string)$exact[0]['name']];
        }

        $tokens  = self::searchTokens($segment);
        $partiel = $tokens === [] ? [] : self::filterByName($enfants, $tokens);
        $retenus = $exact !== [] ? $exact : $partiel;

        if ($retenus === []) {
            $dispo = array_slice(array_map(
                static fn(array $f): string => '« ' . (string)($f['name'] ?? '') . ' »',
                $enfants
            ), 0, 15);
            throw new \RuntimeException(
                'Aucun sous-dossier « ' . $segment . ' » dans ' . $cheminParcouru . '. '
                . ($dispo === []
                    ? 'Ce dossier ne contient aucun sous-dossier.'
                    : 'Sous-dossiers disponibles : ' . implode(', ', $dispo)
                        . (count($enfants) > 15 ? ' … (' . count($enfants) . ' au total)' : '') . '.')
            );
        }
        if (count($retenus) > 1) {
            $noms = implode(', ', array_map(
                static fn(array $f): string => '« ' . (string)($f['name'] ?? '') . ' »',
                array_slice($retenus, 0, 5)
            ));
            throw new \RuntimeException(
                'Plusieurs sous-dossiers de ' . $cheminParcouru . ' correspondent à « ' . $segment
                . ' » : ' . $noms . '. Demande à l\'utilisateur lequel viser.'
            );
        }
        return ['id' => (string)$retenus[0]['id'], 'nom' => (string)$retenus[0]['name']];
    }

    /** Entrée Drive brute → forme rendue au modèle (une seule définition). */
    private static function describeFile(array $f): array
    {
        $mime     = (string)($f['mimeType'] ?? '');
        $isFolder = $mime === self::MIME_FOLDER;
        return [
            'id'      => (string)($f['id'] ?? ''),
            'nom'     => (string)($f['name'] ?? ''),
            'type'    => self::humanType($mime),
            'modifie' => (string)($f['modifiedTime'] ?? ''),
            'taille'  => isset($f['size']) ? (int)$f['size'] : null,
            'dossier' => $isFolder,
            'lisible' => !$isFolder && (isset(self::EXPORT_MAP[$mime]) || isset(self::READABLE_MIMES[$mime])),
        ];
    }

    /**
     * Tri par pertinence : nom exact, puis nom portant TOUS les mots, puis nom
     * en portant une partie, puis le reste (trouvé par le contenu). À égalité,
     * l'ordre de Google (date de modification décroissante) est conservé.
     *
     * @param list<array{brut:string,norme:string}> $tokens
     */
    private static function rankByRelevance(array $rows, array $tokens): array
    {
        $requete = trim(implode(' ', array_column($tokens, 'norme')));
        $scored  = [];
        foreach ($rows as $i => $f) {
            $nom = self::normalizeForSearch((string)($f['name'] ?? ''));
            $hits = 0;
            foreach ($tokens as $t) {
                if ($t['norme'] !== '' && str_contains($nom, $t['norme'])) {
                    $hits++;
                }
            }
            $score = match (true) {
                $nom === $requete && $requete !== '' => 4,
                $hits === count($tokens) && $hits > 0 => 3,
                $hits > 0                             => 2,
                default                               => 1,
            };
            $scored[] = ['ordre' => $i, 'score' => $score, 'row' => $f];
        }
        usort($scored, static fn(array $a, array $b): int
            => $b['score'] <=> $a['score'] ?: $a['ordre'] <=> $b['ordre']);
        return array_column($scored, 'row');
    }

    /**
     * `files.list` avec les paramètres communs (Mon Drive + « Partagés avec
     * moi » + Drives partagés). Une seule page : au-delà, c'est `scanFiles`.
     *
     * @return list<array>
     */
    private function listFiles(string $q, int $limit, string $orderBy = 'modifiedTime desc'): array
    {
        $data = $this->requestJson('/files', [
            'q'        => $q,
            'corpora'  => 'allDrives',
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives'         => 'true',
            'fields'   => 'files(id,name,mimeType,modifiedTime,size)',
            'orderBy'  => $orderBy,
            'pageSize' => (string)max(1, min(1000, $limit)),
        ]);
        return array_values(array_filter(
            (array)($data['files'] ?? []),
            static fn($f): bool => is_array($f)
        ));
    }

    /**
     * Dernier recours : on liste le Drive et on filtre ICI, où l'on sait
     * chercher au milieu d'un mot et ignorer les accents (cf. SCAN_PAGE_SIZE).
     * Un fichier est retenu quand son nom porte TOUS les mots de la requête ;
     * à défaut de résultat, quand il en porte au moins un.
     *
     * @param list<array{brut:string,norme:string}> $tokens
     * @return list<array>
     */
    private function scanFiles(array $tokens, ?string $mimeEquals, bool $excludeFolders, int $limit): array
    {
        $q = 'trashed=false';
        if ($mimeEquals !== null) {
            $q .= " and mimeType='" . self::qLiteral($mimeEquals) . "'";
        } elseif ($excludeFolders) {
            $q .= " and mimeType!='" . self::MIME_FOLDER . "'";
        }

        $tous    = [];
        $partiel = [];
        $page    = null;
        for ($i = 0; $i < self::SCAN_MAX_PAGES; $i++) {
            $params = [
                'q'        => $q,
                'corpora'  => 'allDrives',
                'includeItemsFromAllDrives' => 'true',
                'supportsAllDrives'         => 'true',
                'fields'   => 'nextPageToken,files(id,name,mimeType,modifiedTime,size)',
                'orderBy'  => 'modifiedTime desc',
                'pageSize' => (string)self::SCAN_PAGE_SIZE,
            ];
            if ($page !== null && $page !== '') {
                $params['pageToken'] = $page;
            }
            $data = $this->requestJson('/files', $params, 60);
            foreach ((array)($data['files'] ?? []) as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $nom  = self::normalizeForSearch((string)($f['name'] ?? ''));
                $hits = 0;
                foreach ($tokens as $t) {
                    if ($t['norme'] !== '' && str_contains($nom, $t['norme'])) {
                        $hits++;
                    }
                }
                if ($hits === 0) {
                    continue;
                }
                if ($hits === count($tokens)) {
                    $tous[] = $f;
                } else {
                    $partiel[] = $f;
                }
            }
            if (count($tous) >= $limit) {
                break;
            }
            $page = isset($data['nextPageToken']) ? (string)$data['nextPageToken'] : null;
            if ($page === null || $page === '') {
                break;
            }
        }

        $out = $tous !== [] ? $tous : $partiel;
        return array_slice($out, 0, $limit);
    }

    /**
     * Lit un fichier du Drive de l'utilisateur : Doc/Sheet/Slides natif exporté
     * (docx/xlsx/pptx), sinon binaire téléchargé si le MIME est supporté.
     * Texte extrait via FileExtractor, rendu PAR PARTIES au budget (≈ tokens
     * × 4 caractères, TextPager) : `partie` lit la suite d'un long document,
     * qui était jusque-là inaccessible au-delà de la coupe.
     *
     * @return array{nom:string,type:string,contenu:string,tronque:bool,partie:int,parties:int,suite?:string}
     */
    public function readFile(string $idOrUrl, int $tokenBudget, int $part = 1): array
    {
        $meta   = $this->resolveFileMeta($idOrUrl, 'id,name,mimeType,size', null, 'fichier');
        $fileId = (string)$meta['id'];
        $name   = (string)($meta['name'] ?? $fileId);
        $mime   = (string)($meta['mimeType'] ?? '');

        // Un dossier n'a pas de contenu à extraire : le message générique
        // « type non pris en charge » se lisait comme un refus d'accès et
        // faisait abandonner le modèle. Il y a une suite, il faut la nommer.
        if ($mime === self::MIME_FOLDER) {
            throw new \RuntimeException(
                '« ' . $name . ' » est un DOSSIER : il ne se lit pas, il se parcourt. '
                . 'Utilise `lister_dossier_google_drive` pour voir ce qu\'il contient, '
                . 'puis lis le fichier voulu.'
            );
        }

        if (isset(self::EXPORT_MAP[$mime])) {
            $export = self::EXPORT_MAP[$mime];
            $body   = $this->requestRaw('/files/' . rawurlencode($fileId) . '/export', [
                'mimeType' => (string)$export['mime'],
            ]);
            $extractMime = (string)$export['mime'];
        } elseif (isset(self::READABLE_MIMES[$mime])) {
            if ((int)($meta['size'] ?? 0) > self::MAX_FILE_SIZE) {
                throw new \RuntimeException('Fichier trop volumineux (limite 20 Mo) : ' . $name);
            }
            $body = $this->requestRaw('/files/' . rawurlencode($fileId), [
                'alt'               => 'media',
                'supportsAllDrives' => 'true',
            ]);
            // text/csv n'a pas de branche dédiée dans FileExtractor : lu comme texte brut.
            $extractMime = $mime === 'text/csv' ? 'text/plain' : $mime;
        } else {
            throw new \RuntimeException(
                'Type de fichier non pris en charge en lecture : ' . self::humanType($mime) . ' (' . $name . ').'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'udrive_');
        if ($tmp === false || @file_put_contents($tmp, $body) === false) {
            throw new \RuntimeException('Écriture temporaire impossible sur le serveur.');
        }
        try {
            $text = FileExtractor::extract($tmp, $extractMime);
        } finally {
            @unlink($tmp);
        }
        if ($text === null || trim($text) === '') {
            throw new \RuntimeException('Aucun texte extractible dans ce fichier : ' . $name);
        }

        return [
            'nom'  => $name,
            'type' => self::humanType($mime),
        ] + TextPager::page($text, max(1000, $tokenBudget * 4), $part);
    }

    /**
     * Les OCTETS d'un fichier (boucles de workflow) : Google Docs, Sheets,
     * Slides exportés en docx, xlsx, pptx ; les autres téléchargés tels quels
     * (20 Mo au plus). Désigné par son lien, son identifiant, son nom ou son
     * chemin.
     *
     * @return array{id:string,nom:string,mime:string,bytes:string,lien:string}
     */
    public function downloadFile(string $idOrUrl): array
    {
        $meta   = $this->resolveFileMeta($idOrUrl, 'id,name,mimeType,size,webViewLink', null, 'fichier');
        $fileId = (string)$meta['id'];
        $name   = (string)($meta['name'] ?? $fileId);
        $mime   = (string)($meta['mimeType'] ?? '');
        if ($mime === self::MIME_FOLDER) {
            throw new \RuntimeException('« ' . $name . ' » est un dossier, pas un fichier.');
        }
        if (isset(self::EXPORT_MAP[$mime])) {
            $export = self::EXPORT_MAP[$mime];
            $bytes = $this->requestRaw('/files/' . rawurlencode($fileId) . '/export', ['mimeType' => (string)$export['mime']]);
            return ['id' => $fileId, 'nom' => $name . '.' . $export['ext'], 'mime' => (string)$export['mime'], 'bytes' => $bytes, 'lien' => (string)($meta['webViewLink'] ?? '')];
        }
        if ((int)($meta['size'] ?? 0) > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('Fichier trop volumineux (limite 20 Mo) : ' . $name);
        }
        $bytes = $this->requestRaw('/files/' . rawurlencode($fileId), ['alt' => 'media', 'supportsAllDrives' => 'true']);
        return ['id' => $fileId, 'nom' => $name, 'mime' => $mime, 'bytes' => $bytes, 'lien' => (string)($meta['webViewLink'] ?? '')];
    }

    /**
     * Les FICHIERS d'un dossier (boucles de workflow) : ses enfants directs,
     * dossiers exclus, par nom. Désigné par son lien, son nom ou son chemin.
     *
     * @return array{dossier:array{id:string,nom:string},fichiers:list<array{id:string,nom:string,mime:string,type:string,modifie:string,taille:?int,lien:string}>,total:int}
     */
    public function folderFiles(string $input, int $limit = 200): array
    {
        $ref  = $this->resolveFolderPath($input);
        $rows = array_values(array_filter($this->listChildren($ref['id']), static fn(array $f): bool => (string)($f['mimeType'] ?? '') !== self::MIME_FOLDER));
        usort($rows, static fn(array $a, array $b): int => strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        $out = [];
        foreach (array_slice($rows, 0, max(1, $limit)) as $f) {
            $id = (string)($f['id'] ?? '');
            $out[] = [
                'id'      => $id,
                'nom'     => (string)($f['name'] ?? ''),
                'mime'    => (string)($f['mimeType'] ?? ''),
                'type'    => self::humanType((string)($f['mimeType'] ?? '')),
                'modifie' => (string)($f['modifiedTime'] ?? ''),
                'taille'  => isset($f['size']) ? (int)$f['size'] : null,
                'lien'    => 'https://drive.google.com/file/d/' . rawurlencode($id) . '/view',
            ];
        }
        return ['dossier' => ['id' => $ref['id'], 'nom' => $ref['nom']], 'fichiers' => $out, 'total' => count($rows)];
    }

    // ===================== ÉCRITURE (Docs API v1 / Sheets API v4) =====================

    /**
     * Référence affichable d'un fichier Google natif (pour la carte
     * d'approbation) : résout id + nom et vérifie le type attendu.
     * $kind : 'doc' | 'sheet'. Throw RuntimeException à message FR.
     *
     * @return array{id:string,nom:string,url:string}
     */
    public function fileRef(string $idOrUrl, string $kind): array
    {
        [$mime, $label, $urlBase] = $kind === 'sheet'
            ? [self::MIME_GSHEET, 'Google Sheets', 'https://docs.google.com/spreadsheets/d/']
            : [self::MIME_GDOC, 'Google Docs', 'https://docs.google.com/document/d/'];
        [$fileId, $name] = $this->resolveGoogleFile($idOrUrl, $mime, $label);
        return ['id' => $fileId, 'nom' => $name, 'url' => $urlBase . $fileId . '/edit'];
    }

    // ===================== RANGEMENT (renommer / déplacer) =====================
    //
    // Métadonnées seules (`name`, `parents`) via Drive API v3 `files.update`,
    // sous le scope drive.metadata (cf. GoogleDriveOAuth). Le CONTENU n'est
    // jamais touché, et aucune méthode ne supprime : `trashed` reste hors de
    // portée du modèle, faute de demi-droit côté Google.

    /** Nom de fichier proposé par le LLM → nom sûr, ou exception FR. */
    public static function sanitizeFileName(string $name): string
    {
        // Caractères de contrôle et séparateurs de chemin retirés : le nom part
        // dans un corps JSON, mais il est aussi RÉAFFICHÉ dans la carte
        // d'approbation et dans la conversation.
        $clean = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        $clean = str_replace(['/', '\\'], '-', $clean);
        $clean = trim((string)preg_replace('/\s+/u', ' ', $clean));
        if ($clean === '') {
            throw new \RuntimeException('Nouveau nom de fichier vide.');
        }
        // 255 = limite Drive ; tronquer vaut mieux qu'un 400 opaque de Google.
        return mb_strlen($clean) > 255 ? mb_substr($clean, 0, 255) : $clean;
    }

    /**
     * Résout N'IMPORTE QUEL fichier du Drive (pas seulement un Doc/Sheet
     * natif : on range aussi des PDF, des images…) pour la carte
     * d'approbation — d'où une méthode distincte de `fileRef()`, qui, elle,
     * EXIGE un type précis parce qu'écrire dans un Doc n'a de sens que sur un
     * Doc.
     *
     * @return array{id:string,nom:string,type:string,url:string,parents:string[],dossier:string}
     */
    public function anyFileRef(string $idOrUrl): array
    {
        // `capabilities` est ramené ICI, dans le `files.get` que la résolution
        // fait de toute façon : Google y dit à l'avance ce que le compte peut
        // faire du fichier (renommer, déplacer, copier). Le lire ne coûte rien
        // et évite la pire séquence du connecteur — carte d'approbation
        // affichée, utilisateur qui approuve, échec APRÈS coup, sur un message
        // incapable de distinguer « vous êtes seulement Lecteur » de « MIA n'a
        // pas l'autorisation ».
        $meta    = $this->resolveFileMeta(
            $idOrUrl,
            'id,name,mimeType,parents,webViewLink,driveId,'
                . 'capabilities(canRename,canEdit,canCopy,canMoveItemWithinDrive,canMoveItemOutOfDrive)',
            null,
            'fichier'
        );
        $fileId  = (string)$meta['id'];
        $parents = array_values(array_filter(array_map('strval', (array)($meta['parents'] ?? []))));

        return [
            'id'      => (string)($meta['id'] ?? $fileId),
            'nom'     => (string)($meta['name'] ?? $fileId),
            'type'    => self::humanType((string)($meta['mimeType'] ?? '')),
            'url'     => (string)($meta['webViewLink'] ?? ('https://drive.google.com/file/d/' . $fileId . '/view')),
            'parents' => $parents,
            'dossier' => $parents === [] ? '' : $this->folderName($parents[0]),
            // Drive partagé : le déplacement vers Mon Drive y est souvent
            // interdit par la politique de l'organisation, et c'est
            // `canMoveItemOutOfDrive` qui le dit.
            'partage' => trim((string)($meta['driveId'] ?? '')) !== '',
            'droits'  => is_array($meta['capabilities'] ?? null) ? $meta['capabilities'] : [],
        ];
    }

    /**
     * Ce que le compte Google interdit sur CE fichier, d'après `capabilities` :
     * message FR prêt à remonter, ou null quand rien ne s'y oppose.
     *
     * Statique pure (testable sans réseau) et SOURCE UNIQUE : le contrôle est
     * joué deux fois, dans le handler (avant la carte, pour que le modèle
     * l'annonce pendant qu'il rédige) et à l'exécution (les droits ont pu
     * changer entre l'approbation et le clic).
     *
     * Une capacité ABSENTE n'est JAMAIS tenue pour refusée : Google ne renvoie
     * que les sous-champs demandés dans `fields`, et traiter le silence comme
     * un non ferait refuser des opérations parfaitement légales à la première
     * évolution de la liste des champs. Seul un `false` explicite bloque.
     */
    public static function organizeBlocker(array $capabilities, string $action, string $nom = ''): ?string
    {
        $refuse = static fn(string $cle): bool => ($capabilities[$cle] ?? null) === false;
        $sujet  = $nom !== '' ? '« ' . $nom .' »' : 'ce fichier';

        $manque = match ($action) {
            'renommer'  => $refuse('canRename') ? 'le renommer' : null,
            'deplacer'  => $refuse('canMoveItemWithinDrive') ? 'le déplacer' : null,
            'dupliquer' => $refuse('canCopy') ? 'le dupliquer' : null,
            default     => null,
        };
        if ($manque === null) {
            return null;
        }
        return 'Votre compte Google ne permet pas de ' . $manque . ' ' . $sujet . ' : vous n\'en êtes ni '
            . 'propriétaire ni éditeur (rôle Lecteur ou Commentateur), ou la politique du Drive partagé '
            . 'l\'interdit. Demandez le rôle « Éditeur » au propriétaire du fichier — reconnecter MIA n\'y '
            . 'changerait rien, l\'autorisation manquante est du côté de votre compte Google.';
    }

    /**
     * Résout un dossier de destination : id, URL `/folders/…`, mot désignant la
     * racine, ou NOM de dossier (« Factures ») — c'est la forme naturelle en
     * conversation, et l'exiger sous forme d'id obligerait l'utilisateur à
     * aller le chercher lui-même.
     *
     * Une recherche par nom qui ramène plusieurs dossiers est une ERREUR
     * explicite listant les candidats : deviner déplacerait le fichier au
     * mauvais endroit, et un déplacement ne laisse aucune trace visible.
     *
     * @return array{id:string,nom:string}
     */
    public function folderRef(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new \RuntimeException('Dossier de destination non précisé.');
        }
        // Racine : Drive l'adresse par l'alias `root`, elle n'a pas de nom d'id.
        $normalise = mb_strtolower($input);
        $normalise = (string)preg_replace('/[^a-z ]/', '', self::stripAccents($normalise));
        if (in_array(trim($normalise), ['racine', 'root', 'mon drive', 'my drive', 'drive'], true)) {
            // Identifiant RÉEL, pas l'alias : Drive accepte `root` en écriture
            // mais ne le renvoie jamais en lecture, si bien que tout appelant
            // qui compare cette destination aux `parents` d'un fichier (« est-il
            // déjà à la racine ? », « a-t-il bien bougé ? ») concluait à
            // l'envers. Un appel, mis en cache d'instance, et l'alias disparaît
            // du reste du service.
            return ['id' => $this->resolveRootAlias('root'), 'nom' => 'Mon Drive'];
        }

        $folderMime = self::MIME_FOLDER;
        $id = self::parseFileInput($input);
        if ($id !== null) {
            try {
                $meta = $this->requestJson('/files/' . rawurlencode($id), [
                    'fields'            => 'id,name,mimeType',
                    'supportsAllDrives' => 'true',
                ]);
                if ((string)($meta['mimeType'] ?? '') !== $folderMime) {
                    throw new \RuntimeException('La destination indiquée n\'est pas un dossier Google Drive.');
                }
                return ['id' => (string)($meta['id'] ?? $id), 'nom' => (string)($meta['name'] ?? $id)];
            } catch (\RuntimeException $e) {
                // Même piège que resolveFileMeta : un NOM de dossier d'un seul
                // mot d'au moins 10 caractères sans accent (« Comptabilite »)
                // passe le motif d'id générique et partait en 404 opaque. Toute
                // autre erreur (URL réellement inaccessible, connexion révoquée)
                // remonte telle quelle.
                if (!self::isBareId($input) || $e->getMessage() !== self::MSG_NOT_FOUND) {
                    throw $e;
                }
            }
        }

        // Recherche serveur (rapide, mais préfixe seul et sensible aux accents),
        // puis balayage local qui, lui, trouve au milieu d'un mot.
        $tokens = self::searchTokens($input);
        if ($tokens === []) {
            $tokens = [['brut' => $input, 'norme' => self::normalizeForSearch($input)]];
        }
        $found = $this->listFiles(self::buildTokenQuery($tokens, true, "mimeType='{$folderMime}'", true), 20);
        if ($found === []) {
            $found = $this->scanFiles($tokens, $folderMime, false, 20);
        }
        if ($found === []) {
            // Un dossier introuvable alors que la lecture n'est pas accordée
            // n'est pas un problème d'orthographe : le dire, sinon l'utilisateur
            // ré-épelle indéfiniment le nom d'un dossier que MIA ne peut pas voir.
            if (($note = $this->readScopeNote()) !== null) {
                throw new \RuntimeException($note);
            }
            throw new \RuntimeException(
                'Aucun dossier « ' . $input . ' » trouvé dans ce Drive. '
                . 'Vérifie l\'orthographe, essaie un seul mot distinctif du nom, ou demande son lien '
                . 'à l\'utilisateur.'
            );
        }
        // Correspondance EXACTE prioritaire : « Factures » ne doit pas être
        // ambigu du seul fait qu'il existe aussi « Factures 2025 ». Comparaison
        // normalisée : « Réunions » et « reunions » désignent le même dossier.
        $cible = self::normalizeForSearch($input);
        $exact = array_values(array_filter(
            $found,
            static fn(array $f): bool => self::normalizeForSearch((string)($f['name'] ?? '')) === $cible
        ));
        $retenus = $exact !== [] ? $exact : $found;
        if (count($retenus) > 1) {
            // Borné à 5 : le balayage local peut en ramener vingt, et une liste
            // à rallonge dans un message d'erreur n'aide personne à choisir.
            $noms = implode(', ', array_map(
                static fn(array $f): string => '« ' . (string)($f['name'] ?? '') . ' »',
                array_slice($retenus, 0, 5)
            ));
            throw new \RuntimeException(
                'Plusieurs dossiers correspondent à « ' . $input .' » : ' . $noms
                . '. Demandez à l\'utilisateur lequel viser (ou fournissez son lien).'
            );
        }
        return ['id' => (string)($retenus[0]['id'] ?? ''), 'nom' => (string)($retenus[0]['name'] ?? $input)];
    }

    /**
     * `root` → identifiant RÉEL du dossier racine (les autres identifiants
     * passent inchangés). Drive accepte l'alias en écriture mais ne le renvoie
     * JAMAIS en lecture : comparer un `parents` à la chaîne « root » ne peut
     * qu'échouer. Un appel, mis en cache d'instance, et seulement sur le
     * chemin qui en a besoin.
     */
    private function resolveRootAlias(string $folderId): string
    {
        if ($folderId !== 'root') {
            return $folderId;
        }
        if ($this->rootFolderId === null) {
            try {
                $meta = $this->requestJson('/files/root', ['fields' => 'id']);
                $this->rootFolderId = (string)($meta['id'] ?? '') !== '' ? (string)$meta['id'] : 'root';
            } catch (\RuntimeException) {
                // Best-effort : l'alias reste utilisable en écriture, seule la
                // comparaison perd en finesse. Mieux vaut ça qu'un déplacement
                // refusé parce qu'un GET de confort a échoué.
                $this->rootFolderId = 'root';
            }
        }
        return $this->rootFolderId;
    }

    /** Nom d'un dossier par son id, best-effort (l'aperçu ne doit jamais échouer dessus). */
    private function folderName(string $folderId): string
    {
        if ($folderId === '') {
            return '';
        }
        try {
            $meta = $this->requestJson('/files/' . rawurlencode($folderId), [
                'fields'            => 'id,name',
                'supportsAllDrives' => 'true',
            ]);
            return (string)($meta['name'] ?? '');
        } catch (\RuntimeException) {
            return '';
        }
    }

    /**
     * Renomme un fichier (métadonnée `name`). Le contenu n'est pas touché.
     * @return array{id:string,nom:string,ancien_nom:string,type:string,url:string}
     */
    public function renameFile(string $idOrUrl, string $newName): array
    {
        if (!$this->canOrganize()) {
            throw new \RuntimeException(self::MSG_RECONNECT_ORGANIZE);
        }
        $name = self::sanitizeFileName($newName);
        $ref  = $this->anyFileRef($idOrUrl);

        if (($blocage = self::organizeBlocker($ref['droits'] ?? [], 'renommer', $ref['nom'])) !== null) {
            throw new \RuntimeException($blocage);
        }

        $res = $this->apiJson(
            'PATCH',
            self::API_BASE . '/files/' . rawurlencode($ref['id']) . '?' . http_build_query([
                'supportsAllDrives' => 'true',
                'fields'            => 'id,name',
            ]),
            ['name' => $name]
        );
        // Règle maison : une écriture SANS EFFET lève, elle ne se conclut jamais
        // par un ✅ (cf. updateDoc/updateSheet) — un renommage annoncé mais non
        // appliqué se constaterait dans le Drive, pas dans la conversation.
        //
        // Le contrôle porte sur le seul cas « rien n'a bougé » : Google peut
        // normaliser un nom à la marge (espaces), et transformer ces
        // renommages-là en échec serait un faux négatif — l'opération a bien eu
        // lieu, le retour dit exactement sous quel nom.
        $obtenu = (string)($res['name'] ?? '');
        if ($obtenu !== '' && $obtenu !== $name && $obtenu === $ref['nom']) {
            throw new \RuntimeException(
                'Google a accepté la demande sans renommer « ' . $ref['nom'] . ' » : le fichier a gardé '
                . 'son nom. Vérifiez vos droits de modification sur ce fichier.'
            );
        }

        return [
            'id'         => $ref['id'],
            'nom'        => $name,
            'ancien_nom' => $ref['nom'],
            'type'       => $ref['type'],
            'url'        => $ref['url'],
        ];
    }

    /**
     * Déplace un fichier vers un dossier (métadonnée `parents`).
     *
     * Drive v3 exige `removeParents` EXPLICITE : un simple `addParents`
     * AJOUTERAIT un emplacement sans retirer l'ancien (un fichier Drive peut
     * vivre dans plusieurs dossiers), et l'utilisateur retrouverait son
     * fichier aux deux endroits sans comprendre pourquoi.
     *
     * @return array{id:string,nom:string,type:string,url:string,dossier:string,ancien_dossier:string}
     */
    public function moveFile(string $idOrUrl, string $folderInput): array
    {
        if (!$this->canOrganize()) {
            throw new \RuntimeException(self::MSG_RECONNECT_ORGANIZE);
        }
        $ref    = $this->anyFileRef($idOrUrl);
        $folder = $this->folderRef($folderInput);

        // `root` est un ALIAS, pas un identifiant : le fichier, lui, porte
        // l'identifiant RÉEL de la racine dans ses parents. Sans cette
        // résolution, un fichier déjà à la racine passait la garde
        // ci-dessous, puis partait en PATCH avec la même racine des deux
        // côtés (`addParents=root`, `removeParents=<id réel>`) — un
        // déplacement fantôme, sans effet et sans erreur.
        $destId = $this->resolveRootAlias($folder['id']);

        if (in_array($destId, $ref['parents'], true)) {
            throw new \RuntimeException(
                'Le fichier « ' . $ref['nom'] . ' » est déjà dans le dossier « ' . $folder['nom'] . ' ».'
            );
        }
        if (($blocage = self::organizeBlocker($ref['droits'] ?? [], 'deplacer', $ref['nom'])) !== null) {
            throw new \RuntimeException($blocage);
        }
        // Sortir d'un Drive partagé est une capacité DISTINCTE, très souvent
        // refusée par la politique de l'organisation. Le contrôle ne s'applique
        // qu'à ce cas : la destination n'appartient au même Drive partagé que
        // si elle en vient, et `folderRef` ne le dit pas sans un appel de plus.
        if (($ref['partage'] ?? false) === true && ($ref['droits']['canMoveItemOutOfDrive'] ?? null) === false) {
            throw new \RuntimeException(
                'Le fichier « ' . $ref['nom'] . ' » appartient à un Drive partagé dont la politique interdit '
                . 'de sortir les fichiers. Si la destination est dans ce même Drive partagé, le déplacement '
                . 'reste possible depuis Google Drive ; sinon, demandez à l\'administrateur du Drive partagé.'
            );
        }

        $query = [
            'addParents'        => $destId,
            'supportsAllDrives' => 'true',
            // `parents` est relu : c'est la seule preuve que le déplacement a
            // réellement eu lieu (cf. vérification d'effet, plus bas).
            'fields'            => 'id,name,parents',
        ];
        // La destination est retirée de la liste à enlever : un identifiant
        // présent des deux côtés est un ordre contradictoire, dont Drive ne
        // garantit pas le résultat.
        $aRetirer = array_values(array_diff($ref['parents'], [$destId]));
        if ($aRetirer !== []) {
            $query['removeParents'] = implode(',', $aRetirer);
        }
        // Corps NON vide, et c'est délibéré : `json_encode([])` produit `[]`,
        // un tableau JSON là où Drive attend un objet — 400 garanti. On y
        // remet le nom courant (écriture idempotente : rien ne change) plutôt
        // que d'élargir la plomberie partagée du socle pour un cas isolé.
        $res = $this->apiJson(
            'PATCH',
            self::API_BASE . '/files/' . rawurlencode($ref['id']) . '?' . http_build_query($query),
            ['name' => $ref['nom']]
        );
        // Effet RÉEL vérifié : c'est la demande d'origine (« des déplacements
        // ne s'opèrent pas comme il faut »). Drive répond 200 à un PATCH qui
        // n'a rien déplacé — la conversation affichait alors « ✅ déplacé »
        // sur un fichier resté en place, le pire des trois mondes puisque
        // l'utilisateur ne sait même pas qu'il doit réessayer.
        $nouveaux = array_map('strval', (array)($res['parents'] ?? []));
        if ($nouveaux !== [] && !in_array($destId, $nouveaux, true)) {
            throw new \RuntimeException(
                'Google a accepté la demande sans déplacer « ' . $ref['nom'] . ' » vers « ' . $folder['nom']
                . ' » : le fichier est resté à son emplacement. Vérifiez vos droits sur le dossier de '
                . 'destination (il faut pouvoir y ajouter des fichiers).'
            );
        }

        return [
            'id'             => $ref['id'],
            'nom'            => $ref['nom'],
            'type'           => $ref['type'],
            'url'            => $ref['url'],
            'dossier'        => $folder['nom'],
            'ancien_dossier' => $ref['dossier'],
        ];
    }

    /** Nom par défaut d'une copie. Statique pure : la carte annonce ce nom EXACT. */
    public static function defaultCopyName(string $sourceName): string
    {
        $nom = 'Copie de ' . trim($sourceName);
        return mb_strlen($nom) > 255 ? mb_substr($nom, 0, 255) : $nom;
    }

    /**
     * Duplique un fichier via Drive `files.copy` : la copie est faite CÔTÉ
     * GOOGLE, donc fidèle — mise en forme, images, tableaux, en-têtes. Un
     * contournement « lire puis recréer » aurait aplati tout ça en texte brut
     * (la lecture passe par un export docx puis FileExtractor).
     *
     * `parents` est TOUJOURS posé explicitement (dossier demandé, sinon celui
     * de la source) : sans lui, l'emplacement de la copie dépend de Google et
     * la carte d'approbation ne pourrait pas l'annoncer.
     *
     * @return array{id:string,nom:string,type:string,url:string,source:string,dossier:string}
     */
    public function copyFile(string $idOrUrl, string $newName = '', string $folderInput = ''): array
    {
        if (!$this->canCopy()) {
            throw new \RuntimeException(self::MSG_RECONNECT_COPY);
        }
        $ref  = $this->anyFileRef($idOrUrl);
        if (($blocage = self::organizeBlocker($ref['droits'] ?? [], 'dupliquer', $ref['nom'])) !== null) {
            throw new \RuntimeException($blocage);
        }
        $nom  = trim($newName) !== '' ? self::sanitizeFileName($newName) : self::defaultCopyName($ref['nom']);

        if (trim($folderInput) !== '') {
            $folder  = $this->folderRef($folderInput);
            $parents = [$folder['id']];
            $dossier = $folder['nom'];
        } else {
            // À côté de l'original. La source peut n'avoir aucun parent
            // visible (élément « Partagé avec moi ») : la racine fait alors
            // un emplacement prévisible, que la carte annonce.
            $parents = $ref['parents'] !== [] ? [$ref['parents'][0]] : ['root'];
            $dossier = $ref['parents'] !== [] ? ($ref['dossier'] !== '' ? $ref['dossier'] : 'dossier de l\'original') : 'Mon Drive';
        }

        try {
            $copie = $this->apiJson(
                'POST',
                self::API_BASE . '/files/' . rawurlencode($ref['id']) . '/copy?' . http_build_query([
                    'supportsAllDrives' => 'true',
                    'fields'            => 'id,name,mimeType,webViewLink',
                ]),
                ['name' => $nom, 'parents' => $parents]
            );
        } catch (\RuntimeException $e) {
            // Le scope EST accordé (canCopy) mais Google refuse : `drive.file`
            // ne couvre pas un fichier que l'app n'a pas créé. Le socle a
            // traduit le 403 en « reconnectez-vous », ce qui serait un
            // mauvais conseil ici — rien n'y changerait.
            if ($e->getMessage() === self::MSG_RECONNECT_WRITE
                || $e->getMessage() === self::MSG_PER_FILE_REFUSED) {
                throw new \RuntimeException(self::MSG_COPY_REFUSED);
            }
            throw $e;
        }

        $id = (string)($copie['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Copie refusée par Google (aucun fichier créé).');
        }
        return [
            'id'      => $id,
            'nom'     => (string)($copie['name'] ?? $nom),
            'type'    => self::humanType((string)($copie['mimeType'] ?? '')),
            'url'     => (string)($copie['webViewLink'] ?? ('https://drive.google.com/file/d/' . $id . '/view')),
            'source'  => $ref['nom'],
            'dossier' => $dossier,
        ];
    }

    /** Minuscules sans accents (comparaison de mots-clés de destination). */
    private static function stripAccents(string $text): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        return $converted !== false ? $converted : $text;
    }

    /**
     * Aperçu HUMAIN d'une action d'écriture demandée par le LLM, affiché dans
     * la carte d'approbation côté chat : libellé de l'action, fichier visé,
     * liste d'impacts en français (dont l'irréversibilité éventuelle) et
     * extrait du contenu. Fonction pure : la résolution réseau du fichier
     * (nom/url) est passée par l'appelant via $fileRef pour les modifications.
     *
     * @param array{nom:string,url:string}|null $fileRef Fichier résolu (modifications), null pour une création.
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string}
     */
    public static function actionPreview(string $tool, array $args, ?array $fileRef = null): array
    {
        $excerpt = static function (string $text, int $max = 280): ?string {
            $text = trim($text);
            if ($text === '') {
                return null;
            }
            return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
        };
        $tableSummary = static function (mixed $values): ?string {
            try {
                $rows = self::normalizeValues($values);
            } catch (\RuntimeException) {
                return null;
            }
            $cols = max(array_map('count', $rows));
            // I18n::plural : le français pluralise à > 1, l'anglais à != 1.
            return I18n::plural(count($rows), '{n} ligne', '{n} lignes', ['n' => (string) count($rows)])
                . ' × ' . I18n::plural($cols, '{n} colonne', '{n} colonnes', ['n' => (string) $cols]);
        };

        $nom = $fileRef['nom'] ?? '';
        $url = $fileRef['url'] ?? null;

        switch ($tool) {
            case 'creer_google_doc':
                $contenu = (string)($args['contenu'] ?? '');
                return [
                    'action'  => t('Créer un Google Doc'),
                    'fichier' => trim((string)($args['titre'] ?? '')) !== '' ? trim((string)$args['titre']) : t('Document sans titre'),
                    'url'     => null,
                    'impacts' => array_values(array_filter([
                        t('Un nouveau document sera créé à la racine de votre Drive.'),
                        trim($contenu) === '' ? t('Le document sera créé vide.') : null,
                        t('Aucun fichier existant ne sera touché.'),
                    ])),
                    'apercu'  => $excerpt($contenu),
                ];

            case 'modifier_google_doc':
                $mode    = (string)($args['mode'] ?? '');
                $impacts = match ($mode) {
                    'ajouter'         => [t('Le texte sera ajouté en FIN de document.'),
                                          t('Le contenu existant est conservé intégralement.')],
                    'remplacer_texte' => [t('Toutes les occurrences de « {texte} » seront remplacées.', ['texte' => (string)($args['rechercher'] ?? '')]),
                                          t('Le reste du document est conservé.')],
                    'remplacer_tout'  => [t('⚠ L\'INTÉGRALITÉ du contenu actuel sera remplacée.'),
                                          t('L\'ancien contenu restera récupérable via l\'historique des versions Google.')],
                    default           => [t('Mode de modification : {mode}', ['mode' => $mode])],
                };
                return [
                    'action'  => t('Modifier un Google Doc'),
                    'fichier' => $nom,
                    'url'     => $url,
                    'impacts' => $impacts,
                    'apercu'  => $excerpt((string)($args['texte'] ?? '')),
                ];

            case 'creer_google_sheet':
                $dims = isset($args['valeurs']) && $args['valeurs'] !== [] ? $tableSummary($args['valeurs']) : null;
                return [
                    'action'  => t('Créer un Google Sheet'),
                    'fichier' => trim((string)($args['titre'] ?? '')) !== '' ? trim((string)$args['titre']) : t('Feuille sans titre'),
                    'url'     => null,
                    'impacts' => array_values(array_filter([
                        t('Une nouvelle feuille de calcul sera créée à la racine de votre Drive.'),
                        $dims !== null ? t('Données écrites depuis A1 : {dimensions}.', ['dimensions' => $dims]) : t('La feuille sera créée vide.'),
                        t('Aucun fichier existant ne sera touché.'),
                    ])),
                    'apercu'  => null,
                ];

            case 'modifier_google_sheet':
                $mode    = (string)($args['mode'] ?? '');
                $dims    = $tableSummary($args['valeurs'] ?? []);
                $plage   = trim((string)($args['plage'] ?? ''));
                $impacts = match ($mode) {
                    'ecrire_plage'   => array_values(array_filter([
                        t('Écriture sur la plage {plage}{dimensions}.', [
                            'plage'      => $plage !== '' ? $plage : '?',
                            'dimensions' => $dims !== null ? ' (' . $dims . ')' : '',
                        ]),
                        t('⚠ Les cellules existantes de cette plage seront écrasées.'),
                        t('Récupérable via l\'historique des versions Google.'),
                    ])),
                    'ajouter_lignes' => array_values(array_filter([
                        $dims !== null
                            ? t('Ajout de {dimensions} APRÈS les données existantes.', ['dimensions' => $dims])
                            : t('Ajout APRÈS les données existantes.'),
                        t('Les données existantes sont conservées intégralement.'),
                    ])),
                    default          => [t('Mode de modification : {mode}', ['mode' => $mode])],
                };
                return [
                    'action'  => t('Modifier un Google Sheet'),
                    'fichier' => $nom,
                    'url'     => $url,
                    'impacts' => $impacts,
                    'apercu'  => null,
                ];

            case 'mettre_en_forme_google_doc':
                // $fileRef porte le repérage fait par le handler : nombre réel
                // d'occurrences et résumé humain du style demandé.
                $occ = (int)($fileRef['occurrences'] ?? 0);
                return [
                    'action'  => t('Mettre en forme un Google Doc'),
                    'fichier' => $nom,
                    'url'     => $url,
                    'impacts' => array_values(array_filter([
                        t('Mise en forme : {styles}.', ['styles' => (string)($fileRef['styles'] ?? '')]),
                        I18n::plural($occ,
                            '{n} occurrence de « {texte} » concernée.',
                            '{n} occurrences de « {texte} » concernées.',
                            ['n' => (string) $occ, 'texte' => trim((string)($args['rechercher'] ?? ''))]
                        ),
                        t('Le texte lui-même n\'est pas modifié.'),
                        t('Récupérable via l\'historique des versions Google.'),
                    ])),
                    'apercu'  => null,
                ];

            // Rangement : $fileRef porte le fichier DÉJÀ résolu par le handler
            // (nom réel, type, dossier actuel) et, pour un déplacement, le nom
            // du dossier de destination — sinon la carte annoncerait
            // « déplacer 1a2B3c… vers 9xY… » et l'utilisateur approuverait à
            // l'aveugle ce qu'il ne peut pas relire.
            case 'renommer_google_drive':
                $nouveau = trim((string)($args['nouveau_nom'] ?? ''));
                return [
                    'action'  => t('Renommer un fichier Drive'),
                    'fichier' => $nom,
                    'url'     => $url,
                    'impacts' => array_values(array_filter([
                        t('Nouveau nom : « {nom} ».', ['nom' => $nouveau]),
                        isset($fileRef['type']) && (string)$fileRef['type'] !== ''
                            ? t('Type : {type} — le contenu du fichier n\'est pas modifié.', ['type' => (string)$fileRef['type']])
                            : t('Le contenu du fichier n\'est pas modifié.'),
                        t('Les liens de partage existants continuent de fonctionner.'),
                    ])),
                    'apercu'  => null,
                ];

            case 'mettre_en_forme_google_sheet':
                // $fileRef porte le repérage du handler : onglet et plage
                // RÉSOLUS (« plage B2:D5 » plutôt que la saisie du modèle) et
                // le nombre de cellules touchées.
                $cellules = $fileRef['cellules'] ?? null;
                return [
                    'action'  => t('Mettre en forme un Google Sheet'),
                    'fichier' => $nom,
                    'url'     => $url,
                    'impacts' => array_values(array_filter([
                        t('Mise en forme : {styles}.', ['styles' => (string)($fileRef['styles'] ?? '')]),
                        t('Onglet « {onglet} », {plage}{cellules}.', [
                            'onglet'   => (string)($fileRef['onglet'] ?? ''),
                            'plage'    => (string)($fileRef['plage'] ?? ''),
                            'cellules' => $cellules === null ? '' : ' (' . I18n::plural(
                                (int) $cellules, '{n} cellule', '{n} cellules', ['n' => (string) (int) $cellules]
                            ) . ')',
                        ]),
                        t('Les valeurs des cellules ne sont pas modifiées.'),
                        t('Récupérable via l\'historique des versions Google.'),
                    ])),
                    'apercu'  => null,
                ];

            case 'dupliquer_google_drive':
                // Seule écriture de la famille qui ne touche PAS l'original :
                // la carte le dit, c'est ce qui rassure avant d'approuver.
                return [
                    'action'  => t('Dupliquer un fichier Drive'),
                    'fichier' => $nom,
                    'url'     => $url,
                    'impacts' => array_values(array_filter([
                        t('Nom de la copie : « {nom} ».', ['nom' => (string)($fileRef['copie'] ?? '')]),
                        t('Emplacement : {dossier}.', ['dossier' => (string)($fileRef['destination'] ?? '')]),
                        t('Le fichier d\'origine n\'est pas modifié.'),
                        t('Copie fidèle : mise en forme, images et tableaux conservés.'),
                    ])),
                    'apercu'  => null,
                ];

            case 'deplacer_google_drive':
                $dest   = trim((string)($fileRef['destination'] ?? ''));
                $depuis = trim((string)($fileRef['dossier'] ?? ''));
                return [
                    'action'  => t('Déplacer un fichier Drive'),
                    'fichier' => $nom,
                    'url'     => $url,
                    'impacts' => array_values(array_filter([
                        t('Destination : {dossier}.', ['dossier' => $dest !== '' ? '« ' . $dest . ' »' : t('dossier demandé')]),
                        $depuis !== '' ? t('Retiré de « {dossier} ».', ['dossier' => $depuis]) : null,
                        t('Le contenu du fichier n\'est pas modifié.'),
                        t('⚠ Un déplacement ne laisse aucune trace dans le Drive : notez la destination si vous devez revenir en arrière.'),
                    ])),
                    'apercu'  => null,
                ];
        }
        return ['action' => $tool, 'fichier' => $nom, 'url' => $url, 'impacts' => [], 'apercu' => null];
    }

    /**
     * Crée un Google Doc (titre + contenu texte optionnel).
     * @return array{id:string,nom:string,url:string}
     */
    public function createDoc(string $title, string $text = ''): array
    {
        if (!$this->canWriteDocs()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $title = trim($title) !== '' ? trim($title) : 'Document sans titre';
        $doc   = $this->apiJson('POST', self::DOCS_API . '/documents', ['title' => $title]);
        $id    = (string)($doc['documentId'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Création du document refusée par Google.');
        }
        if (trim($text) !== '') {
            // Le contenu est du Markdown RENDU (titres, gras, listes…) : un
            // insertText brut laissait les ## et ** en toutes lettres.
            // Corps d'un document neuf : l'insertion se fait à l'index 1.
            $conv = DocsFormat::convertir($text, 1);
            if ($conv['texte'] !== '') {
                $this->docsBatchUpdate($id, array_merge(
                    [['insertText' => ['location' => ['index' => 1], 'text' => $conv['texte']]]],
                    $conv['requetes']
                ));
            }
        }
        return ['id' => $id, 'nom' => $title, 'url' => 'https://docs.google.com/document/d/' . $id . '/edit'];
    }

    /**
     * batchUpdate Docs par tranches de 400 requêtes (plafond API 500).
     * Les tranches restent séquentielles : l'insertText part en premier, les
     * styles qui suivent ne changent aucune longueur — les index calculés
     * restent donc valides d'une tranche à l'autre.
     */
    private function docsBatchUpdate(string $docId, array $requests): array
    {
        $endpoint = self::DOCS_API . '/documents/' . rawurlencode($docId) . ':batchUpdate';
        $last = [];
        foreach (array_chunk($requests, 400) as $chunk) {
            $last = $this->apiJson('POST', $endpoint, ['requests' => $chunk]);
        }
        return $last;
    }

    /**
     * Modifie un Google Doc existant. Modes :
     *  - 'ajouter'          : insère $text en fin de document
     *  - 'remplacer_texte'  : remplace toutes les occurrences de $search par $text
     *  - 'remplacer_tout'   : remplace l'intégralité du contenu par $text
     *
     * @return array{nom:string,url:string,mode:string,occurrences?:int}
     */
    public function updateDoc(string $idOrUrl, string $mode, string $text, string $search = ''): array
    {
        if (!$this->canWriteDocs()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        [$fileId, $name] = $this->resolveGoogleFile($idOrUrl, self::MIME_GDOC, 'Google Docs');
        $endpoint = self::DOCS_API . '/documents/' . rawurlencode($fileId);
        $result   = ['nom' => $name, 'url' => 'https://docs.google.com/document/d/' . $fileId . '/edit', 'mode' => $mode];

        switch ($mode) {
            case 'ajouter':
                if (trim($text) === '') {
                    throw new \RuntimeException('Texte à ajouter vide.');
                }
                // Fin du corps lue d'abord : la conversion Markdown a besoin
                // d'un index d'insertion EXPLICITE pour poser ses styles
                // (endOfSegmentLocation ne dit pas où le texte atterrit).
                $docData  = $this->apiJson('GET', $endpoint . '?fields=' . rawurlencode('body(content(endIndex))'));
                $endIndex = 1;
                foreach (($docData['body']['content'] ?? []) as $el) {
                    $endIndex = max($endIndex, (int)($el['endIndex'] ?? 1));
                }
                // L'insertion se fait AVANT le saut de ligne final obligatoire
                // du corps ; document non vide → un \n de séparation pour que
                // l'ajout démarre sur un paragraphe frais.
                $insertAt = max(1, $endIndex - 1);
                $prefix   = $endIndex > 2 ? "\n" : '';
                $conv     = DocsFormat::convertir($text, $insertAt + DocsFormat::utf16Len($prefix));
                if ($conv['texte'] === '') {
                    // Markdown qui ne produit AUCUN texte (un fence seul, une
                    // ligne d'ornement…). Sans cette levée, rien n'était envoyé
                    // à Google et l'action se concluait par un ✅ mensonger.
                    throw new \RuntimeException(
                        'Rien à insérer : le texte fourni ne produit aucun contenu une fois le Markdown interprété.'
                    );
                }
                $this->docsBatchUpdate($fileId, array_merge(
                    [['insertText' => ['location' => ['index' => $insertAt], 'text' => $prefix . $conv['texte']]]],
                    $conv['requetes']
                ));
                $result['caracteres'] = mb_strlen($conv['texte']);
                break;

            case 'remplacer_texte':
                if ($search === '') {
                    throw new \RuntimeException('Texte à rechercher vide pour le remplacement.');
                }
                $resp = $this->apiJson('POST', $endpoint . ':batchUpdate', [
                    'requests' => [[
                        'replaceAllText' => [
                            'containsText' => ['text' => $search, 'matchCase' => false],
                            'replaceText'  => $text,
                        ],
                    ]],
                ]);
                $result['occurrences'] = (int)($resp['replies'][0]['replaceAllText']['occurrencesChanged'] ?? 0);
                if ($result['occurrences'] === 0) {
                    // LE bug signalé : Google répond 200 même quand il n'a rien
                    // trouvé, et l'action se concluait par « ✅ Google Doc
                    // modifié — 0 occurrence(s) remplacée(s) ». Aucun message
                    // d'erreur, aucune modification, et un utilisateur persuadé
                    // du contraire. Le texte extrait par la LECTURE (export
                    // docx) ne coïncide pas toujours au caractère près avec le
                    // document (apostrophes typographiques, espaces
                    // insécables, tirets longs), d'où des recherches qui
                    // tombent à côté sans que rien ne le signale.
                    throw new \RuntimeException(
                        'Aucune occurrence de « ' . $search . ' » dans « ' . $name . ' » : le document n\'a PAS été '
                        . 'modifié. Google exige le texte EXACT — la casse est ignorée, mais pas les accents, les '
                        . 'apostrophes typographiques (’ et \'), les espaces insécables ni les tirets longs. Relisez le '
                        . 'document et reprenez le passage tel qu\'il y figure, ou réécrivez la section entière avec '
                        . 'le mode remplacer_tout.'
                    );
                }
                break;

            case 'remplacer_tout':
                // Fin du corps actuel (le dernier index n'est pas supprimable :
                // Google impose de garder le saut de ligne final).
                $docData  = $this->apiJson('GET', $endpoint . '?fields=' . rawurlencode('body(content(endIndex))'));
                $endIndex = 1;
                foreach (($docData['body']['content'] ?? []) as $el) {
                    $endIndex = max($endIndex, (int)($el['endIndex'] ?? 1));
                }
                $requests = [];
                if ($endIndex > 2) {
                    $requests[] = ['deleteContentRange' => ['range' => ['startIndex' => 1, 'endIndex' => $endIndex - 1]]];
                }
                if (trim($text) !== '') {
                    // Un batchUpdate applique ses requêtes en SÉQUENCE : après
                    // le delete, le corps recommence à l'index 1 — la
                    // conversion Markdown pose donc ses styles sur cette base.
                    $conv = DocsFormat::convertir($text, 1);
                    if ($conv['texte'] === '') {
                        // Piège : la suppression, elle, était déjà empilée. En
                        // sautant seulement l'insertion, on VIDAIT le document
                        // au lieu de le réécrire — et l'action se concluait par
                        // un ✅. Une remise à blanc doit être demandée, jamais
                        // subie.
                        throw new \RuntimeException(
                            'Rien à écrire : le texte fourni ne produit aucun contenu une fois le Markdown '
                            . 'interprété. Le document a été laissé intact (le réécrire l\'aurait vidé).'
                        );
                    }
                    $requests[] = ['insertText' => ['location' => ['index' => 1], 'text' => $conv['texte']]];
                    $requests   = array_merge($requests, $conv['requetes']);
                }
                if ($requests === []) {
                    // Document déjà vide ET texte sans contenu : rien à faire,
                    // et surtout rien à annoncer comme fait.
                    throw new \RuntimeException(
                        'Rien à écrire : le texte fourni ne produit aucun contenu et le document est déjà vide.'
                    );
                }
                $this->docsBatchUpdate($fileId, $requests);
                $result['caracteres'] = isset($conv) ? mb_strlen($conv['texte']) : 0;
                break;

            default:
                throw new \RuntimeException('Mode de modification inconnu : ' . $mode);
        }
        // Diagnostic : sans cette ligne, un « ça n'a pas marché » rapporté par
        // un utilisateur n'est rattachable à rien côté serveur (même esprit que
        // le journal de création des tâches).
        error_log('[Drive user] doc modifié id=' . $fileId . ' mode=' . $mode
            . ' occurrences=' . (string)($result['occurrences'] ?? '-')
            . ' caracteres=' . (string)($result['caracteres'] ?? '-'));
        return $result;
    }

    /** Champs du corps demandés pour localiser du texte à mettre en forme. */
    private const DOC_BODY_FIELDS = 'body(content(startIndex,endIndex,paragraph(elements(startIndex,endIndex,textRun(content)))))';

    /**
     * Repérage AVANT la carte d'approbation : résout le Doc et compte les
     * occurrences du texte visé — la carte annonce ainsi exactement ce qui
     * sera stylé, et une cible introuvable est signalée au modèle pendant le
     * stream plutôt que découverte après le clic.
     *
     * @return array{id:string,nom:string,url:string,occurrences:int}
     */
    public function inspectDocText(string $idOrUrl, string $recherche): array
    {
        if (!$this->canWriteDocs()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $recherche = trim($recherche);
        if ($recherche === '') {
            throw new \RuntimeException('Texte à mettre en forme vide.');
        }
        [$fileId, $name] = $this->resolveGoogleFile($idOrUrl, self::MIME_GDOC, 'Google Docs');
        $doc = $this->apiJson('GET', self::DOCS_API . '/documents/' . rawurlencode($fileId)
            . '?fields=' . rawurlencode(self::DOC_BODY_FIELDS));
        $loc = DocsFormat::localiserOccurrences((array)($doc['body']['content'] ?? []), $recherche);

        return [
            'id'          => $fileId,
            'nom'         => $name,
            'url'         => 'https://docs.google.com/document/d/' . $fileId . '/edit',
            'occurrences' => count($loc['occurrences']),
        ];
    }

    /**
     * Met en forme un texte DÉJÀ présent dans un Google Doc (gras, italique,
     * souligné, couleur, taille, style de paragraphe), sans le réécrire.
     * Le document est RELU au moment de l'exécution : les index sont calculés
     * sur son état réel, pas sur celui d'avant l'approbation.
     *
     * @param array{gras:?bool,italique:?bool,souligne:?bool,couleur:?array,taille:?int,style_paragraphe:?string} $style
     * @return array{nom:string,url:string,occurrences:int,styles:string}
     */
    public function formatDocText(string $idOrUrl, string $recherche, array $style, string $portee = 'toutes'): array
    {
        if (!$this->canWriteDocs()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $recherche = trim($recherche);
        if ($recherche === '') {
            throw new \RuntimeException('Texte à mettre en forme vide.');
        }
        [$fileId, $name] = $this->resolveGoogleFile($idOrUrl, self::MIME_GDOC, 'Google Docs');
        $doc = $this->apiJson('GET', self::DOCS_API . '/documents/' . rawurlencode($fileId)
            . '?fields=' . rawurlencode(self::DOC_BODY_FIELDS));
        $loc = DocsFormat::localiserOccurrences((array)($doc['body']['content'] ?? []), $recherche);
        if ($loc['occurrences'] === []) {
            throw new \RuntimeException('Texte « ' . $recherche . ' » introuvable dans « ' . $name
                . ' » — il a peut-être été modifié depuis. Relisez le document.');
        }
        $occurrences = $loc['occurrences'];
        $paragraphes = $loc['paragraphes'];
        if ($portee === 'premiere') {
            $occurrences = [reset($occurrences)];
            $paragraphes = [reset($paragraphes)];
        }

        $requests = [];
        [$textStyle, $fields] = DocsFormat::texteStyle($style);
        if ($fields !== '') {
            foreach ($occurrences as [$debut, $fin]) {
                $requests[] = ['updateTextStyle' => [
                    'range'     => ['startIndex' => $debut, 'endIndex' => $fin],
                    'textStyle' => $textStyle,
                    'fields'    => $fields,
                ]];
            }
        }
        if (($style['style_paragraphe'] ?? null) !== null) {
            // Un paragraphe visé par plusieurs occurrences n'est stylé qu'une fois.
            $vus = [];
            foreach ($paragraphes as [$pDebut, $pFin]) {
                if ($pFin <= $pDebut || isset($vus[$pDebut])) {
                    continue;
                }
                $vus[$pDebut] = true;
                $requests[] = ['updateParagraphStyle' => [
                    'range'          => ['startIndex' => $pDebut, 'endIndex' => $pFin],
                    'paragraphStyle' => ['namedStyleType' => DocsFormat::STYLES_PARAGRAPHE[$style['style_paragraphe']]],
                    'fields'         => 'namedStyleType',
                ]];
            }
        }
        if ($requests !== []) {
            $this->docsBatchUpdate($fileId, $requests);
        }

        return [
            'id'          => $fileId,
            'nom'         => $name,
            'url'         => 'https://docs.google.com/document/d/' . $fileId . '/edit',
            'occurrences' => count($occurrences),
            'styles'      => DocsFormat::resumeStyles($style),
        ];
    }

    /**
     * Crée un Google Sheet (titre + valeurs optionnelles écrites depuis A1).
     * $header : première ligne présentée en en-tête (gras, fond, gelée,
     * colonnes ajustées) — vrai par défaut, la première ligne d'un tableau
     * généré étant presque toujours un en-tête.
     * @return array{id:string,nom:string,url:string}
     */
    public function createSheet(string $title, array $values = [], bool $header = true): array
    {
        if (!$this->canWriteSheets()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $title = trim($title) !== '' ? trim($title) : 'Feuille sans titre';
        $sheet = $this->apiJson('POST', self::SHEETS_API . '/spreadsheets', ['properties' => ['title' => $title]]);
        $id    = (string)($sheet['spreadsheetId'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Création de la feuille refusée par Google.');
        }
        if ($values !== []) {
            $rows = self::normalizeValues($values);
            $this->apiJson(
                'PUT',
                self::SHEETS_API . '/spreadsheets/' . rawurlencode($id) . '/values/'
                    . rawurlencode('A1') . '?valueInputOption=USER_ENTERED',
                ['values' => $rows]
            );
            // Présentation de la première ligne en en-tête (gras, fond, ligne
            // gelée, colonnes ajustées) : le pendant du Markdown rendu des
            // Docs — sans elle, l'en-tête ne se distinguait pas des données et
            // les colonnes tronquaient le contenu. Best-effort : les valeurs
            // sont déjà écrites, une feuille non décorée vaut mieux qu'une
            // création annoncée en échec.
            if ($header && count($rows) > 1) {
                try {
                    $this->sheetsBatchUpdate($id, SheetsFormat::headerRequests(0, SheetsFormat::largeur($rows)));
                } catch (\RuntimeException $e) {
                    error_log(self::LOG_LABEL . ' en-tête non appliqué sur ' . $id . ' : ' . $e->getMessage());
                }
            }
        }
        return ['id' => $id, 'nom' => $title, 'url' => 'https://docs.google.com/spreadsheets/d/' . $id . '/edit'];
    }

    /** batchUpdate Sheets par tranches (le plafond de requêtes reste large). */
    private function sheetsBatchUpdate(string $spreadsheetId, array $requests): array
    {
        $endpoint = self::SHEETS_API . '/spreadsheets/' . rawurlencode($spreadsheetId) . ':batchUpdate';
        $last = [];
        foreach (array_chunk($requests, 100) as $chunk) {
            $last = $this->apiJson('POST', $endpoint, ['requests' => $chunk]);
        }
        return $last;
    }

    /**
     * Résout l'onglet visé : les requêtes batchUpdate emploient un `sheetId`
     * NUMÉRIQUE (le gid), jamais le nom — d'où cette lecture préalable.
     * Onglet non nommé → le premier de la feuille.
     *
     * @return array{id:int,titre:string,colonnes:int}
     */
    private function resolveSheetTab(string $spreadsheetId, ?string $titre): array
    {
        $data = $this->apiJson('GET', self::SHEETS_API . '/spreadsheets/' . rawurlencode($spreadsheetId)
            . '?fields=' . rawurlencode('sheets(properties(sheetId,title,gridProperties(columnCount)))'));
        $onglets = (array)($data['sheets'] ?? []);
        if ($onglets === []) {
            throw new \RuntimeException('Cette feuille de calcul ne contient aucun onglet.');
        }
        if ($titre === null || trim($titre) === '') {
            $p = (array)($onglets[0]['properties'] ?? []);
            return ['id' => (int)($p['sheetId'] ?? 0), 'titre' => (string)($p['title'] ?? ''),
                    'colonnes' => (int)($p['gridProperties']['columnCount'] ?? 26)];
        }
        $noms = [];
        foreach ($onglets as $onglet) {
            $p = (array)($onglet['properties'] ?? []);
            $nom = (string)($p['title'] ?? '');
            $noms[] = $nom;
            if (mb_strtolower($nom) === mb_strtolower(trim($titre))) {
                return ['id' => (int)($p['sheetId'] ?? 0), 'titre' => $nom,
                        'colonnes' => (int)($p['gridProperties']['columnCount'] ?? 26)];
            }
        }
        throw new \RuntimeException('Onglet « ' . trim($titre) . ' » introuvable. Onglets disponibles : '
            . implode(', ', array_map(static fn(string $n): string => '« ' . $n . ' »', $noms)) . '.');
    }

    /**
     * Repérage AVANT la carte d'approbation : résout la feuille, l'onglet et la
     * plage, pour que la carte annonce exactement ce qui sera mis en forme et
     * qu'un onglet ou une plage invalide revienne au modèle pendant le stream.
     *
     * @return array{nom:string,url:string,onglet:string,plage:string,cellules:?int}
     */
    public function inspectSheetRange(string $idOrUrl, string $plage): array
    {
        if (!$this->canWriteSheets()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $bornes = SheetsFormat::parseA1($plage);
        [$fileId, $name] = $this->resolveGoogleFile($idOrUrl, self::MIME_GSHEET, 'Google Sheets');
        $onglet = $this->resolveSheetTab($fileId, $bornes['onglet']);

        return [
            'nom'      => $name,
            'url'      => 'https://docs.google.com/spreadsheets/d/' . $fileId . '/edit',
            'onglet'   => $onglet['titre'],
            'plage'    => SheetsFormat::decrirePlage($bornes),
            'cellules' => SheetsFormat::compterCellules($bornes),
        ];
    }

    /**
     * Met en forme une plage d'un Google Sheet (gras, italique, taille,
     * couleurs, alignement, format de nombre) sans toucher aux valeurs.
     * La feuille est RELUE à l'exécution : le sheetId est résolu sur son état
     * réel, pas sur celui d'avant l'approbation.
     *
     * @param array<string,mixed> $style Issu de SheetsFormat::styleDepuisArgs()
     * @return array{nom:string,url:string,onglet:string,plage:string,cellules:?int,styles:string}
     */
    public function formatSheetRange(string $idOrUrl, string $plage, array $style, bool $geler = false): array
    {
        if (!$this->canWriteSheets()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        $bornes = SheetsFormat::parseA1($plage);
        [$fileId, $name] = $this->resolveGoogleFile($idOrUrl, self::MIME_GSHEET, 'Google Sheets');
        $onglet = $this->resolveSheetTab($fileId, $bornes['onglet']);
        $range  = SheetsFormat::gridRange($bornes, $onglet['id']);

        $requests = [];
        $format = SheetsFormat::cellFormatRequest($style, $range);
        if ($format !== null) {
            $requests[] = $format;
        }
        if ($geler) {
            // Gel jusqu'à la dernière ligne de la plage : geler « A1:C1 » fige
            // la ligne 1, ce qu'attend l'utilisateur qui met son en-tête en forme.
            $requests[] = ['updateSheetProperties' => [
                'properties' => ['sheetId' => $onglet['id'],
                                 'gridProperties' => ['frozenRowCount' => (int)($bornes['endRowIndex'] ?? 1)]],
                'fields'     => 'gridProperties.frozenRowCount',
            ]];
        }
        if ($requests === []) {
            throw new \RuntimeException('Aucune mise en forme à appliquer.');
        }
        $this->sheetsBatchUpdate($fileId, $requests);

        return [
            'id'       => $fileId,
            'nom'      => $name,
            'url'      => 'https://docs.google.com/spreadsheets/d/' . $fileId . '/edit',
            'onglet'   => $onglet['titre'],
            'plage'    => SheetsFormat::decrirePlage($bornes),
            'cellules' => SheetsFormat::compterCellules($bornes),
            'styles'   => SheetsFormat::resumeStyles($style) . ($geler ? ', ligne(s) gelée(s)' : ''),
        ];
    }

    /**
     * Modifie un Google Sheet existant. Modes :
     *  - 'ecrire_plage'   : écrit $values sur $range (ex. 'Feuille1!B2:D5', 'A1')
     *  - 'ajouter_lignes' : ajoute $values après les données existantes
     *
     * @return array{nom:string,url:string,mode:string,cellules_modifiees:int}
     */
    public function updateSheet(string $idOrUrl, string $mode, string $range, array $values): array
    {
        if (!$this->canWriteSheets()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
        [$fileId, $name] = $this->resolveGoogleFile($idOrUrl, self::MIME_GSHEET, 'Google Sheets');
        $rows = self::normalizeValues($values);
        $base = self::SHEETS_API . '/spreadsheets/' . rawurlencode($fileId) . '/values/';

        switch ($mode) {
            case 'ecrire_plage':
                if (trim($range) === '') {
                    throw new \RuntimeException('Plage requise pour ecrire_plage (ex. Feuille1!B2 ou A1:C10).');
                }
                $resp  = $this->apiJson('PUT', $base . rawurlencode(trim($range)) . '?valueInputOption=USER_ENTERED', [
                    'values' => $rows,
                ]);
                $cells = (int)($resp['updatedCells'] ?? 0);
                break;

            case 'ajouter_lignes':
                $target = trim($range) !== '' ? trim($range) : 'A1';
                $resp   = $this->apiJson(
                    'POST',
                    $base . rawurlencode($target) . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
                    ['values' => $rows]
                );
                $cells = (int)($resp['updates']['updatedCells'] ?? 0);
                break;

            default:
                throw new \RuntimeException('Mode de modification inconnu : ' . $mode);
        }

        if ($cells === 0) {
            // Même piège que replaceAllText côté Docs : l'API répond 200 sans
            // avoir rien écrit (valeurs vides, plage hors feuille). Annoncer
            // « ✅ Google Sheet modifié — 0 cellule(s) » est un mensonge poli.
            throw new \RuntimeException(
                'Aucune cellule écrite dans « ' . $name . ' » : la feuille n\'a PAS été modifiée. '
                . 'Vérifiez la plage (onglet existant, notation A1 correcte) et que les valeurs ne sont pas vides.'
            );
        }
        error_log('[Drive user] feuille modifiée id=' . $fileId . ' mode=' . $mode . ' cellules=' . $cells);

        return [
            'id'                 => $fileId,
            'nom'                => $name,
            'url'                => 'https://docs.google.com/spreadsheets/d/' . $fileId . '/edit',
            'mode'               => $mode,
            'cellules_modifiees' => $cells,
        ];
    }

    /**
     * Normalise les valeurs tabulaires venues du LLM : tableau 2D de scalaires
     * (une chaîne JSON est décodée, une ligne scalaire est enveloppée).
     * USER_ENTERED côté Google interprète nombres, dates et formules.
     */
    public static function normalizeValues(mixed $values): array
    {
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Valeurs illisibles : tableau 2D attendu (lignes de cellules).');
            }
            $values = $decoded;
        }
        if (!is_array($values) || $values === []) {
            throw new \RuntimeException('Valeurs vides : tableau 2D attendu (lignes de cellules).');
        }
        $rows = [];
        foreach ($values as $row) {
            if (is_scalar($row) || $row === null) {
                $row = [$row];
            }
            if (!is_array($row)) {
                throw new \RuntimeException('Ligne illisible : chaque ligne doit être une liste de cellules.');
            }
            $rows[] = array_map(
                static fn($cell) => is_scalar($cell) ? $cell : json_encode($cell, JSON_UNESCAPED_UNICODE),
                array_values($row)
            );
        }
        return $rows;
    }

    /**
     * SOURCE UNIQUE de la résolution « ce que l'utilisateur désigne » → fichier
     * Drive : id brut, URL Google, **ou NOM**.
     *
     * Le nom est la forme naturelle en conversation (« modifie le compte rendu
     * de lundi »), et surtout la SEULE dont le modèle dispose encore au tour
     * suivant : les résultats d'outils ne sont pas réinjectés dans l'historique
     * (cf. buildLLMMessages), donc l'id d'un fichier trouvé au message
     * précédent est perdu. Sans ce repli, `lire_google_drive` et les écritures
     * répondaient « Identifiant ou URL Google Drive non reconnu » — le message
     * ne disait même pas de chercher d'abord. Symétrique de `folderRef()`, qui
     * accepte un nom de dossier depuis toujours.
     *
     * Ambiguïté = ERREUR listant les candidats, jamais un choix arbitraire :
     * écrire dans le mauvais fichier ne se rejoue pas.
     *
     * @param string      $fields       champs Drive à ramener (uniformise l'appelant)
     * @param string|null $expectedMime restreint la recherche par nom à ce type natif
     * @param string      $label        libellé FR du type attendu, pour les messages
     * @return array metadata Drive, clé `id` garantie
     */
    private function resolveFileMeta(string $input, string $fields, ?string $expectedMime, string $label): array
    {
        $input  = trim($input);
        $fileId = self::parseFileInput($input);

        if ($fileId !== null) {
            try {
                $meta = $this->requestJson('/files/' . rawurlencode($fileId), [
                    'fields'            => $fields,
                    'supportsAllDrives' => 'true',
                ]);
                return $meta + ['id' => (string)($meta['id'] ?? $fileId)];
            } catch (\RuntimeException $e) {
                // Un id NU introuvable est très souvent un NOM d'un seul mot
                // (« Budget2026 ») pris pour un id par le motif générique : on
                // retente par le nom. Toute autre erreur (URL inaccessible,
                // connexion révoquée, quota) remonte telle quelle — la
                // masquer par une recherche donnerait un message faux.
                if (!self::isBareId($input) || $e->getMessage() !== self::MSG_NOT_FOUND) {
                    // Une URL ou un id qui répond 404 alors que la lecture
                    // n'est pas accordée décrit exactement le régime
                    // `drive.file` : le fichier existe, MIA n'y a pas accès.
                    // « Fichier introuvable ou non accessible avec ce compte
                    // Google » désignait alors le compte de l'utilisateur —
                    // le seul élément hors de cause.
                    if ($e->getMessage() === self::MSG_NOT_FOUND && ($note = $this->readScopeNote()) !== null) {
                        throw new \RuntimeException($note);
                    }
                    throw $e;
                }
            }
        }

        $found = $this->findFileByName($input, $expectedMime, $label);
        $meta  = $this->requestJson('/files/' . rawurlencode($found), [
            'fields'            => $fields,
            'supportsAllDrives' => 'true',
        ]);
        return $meta + ['id' => (string)($meta['id'] ?? $found)];
    }

    /**
     * Cherche UN fichier par son nom et renvoie son id, ou lève un message FR
     * actionnable. Même mécanique que `folderRef()` : correspondance exacte
     * prioritaire (« Budget » ne doit pas être ambigu du seul fait qu'il existe
     * aussi « Budget 2025 »), puis erreur listant les candidats.
     */
    private function findFileByName(string $name, ?string $expectedMime, string $label): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Aucun fichier indiqué : donne son nom, son identifiant ou son lien.');
        }
        // Un dossier n'est jamais une cible de lecture ni d'écriture — et sans
        // ce filtre, « Factures » ramènerait le dossier ET les fichiers qu'il
        // contient, donc une ambiguïté artificielle.
        $extra = $expectedMime !== null
            ? "mimeType='" . self::qLiteral($expectedMime) . "'"
            : "mimeType!='" . self::MIME_FOLDER . "'";

        // Mêmes trois passes que `search()` : le nom donné par le modèle est
        // rarement le nom exact, et Drive ne matche qu'en préfixe, accents
        // compris.
        $tokens = self::searchTokens($name);
        if ($tokens === []) {
            $tokens = [['brut' => $name, 'norme' => self::normalizeForSearch($name)]];
        }
        $found = $this->listFiles(self::buildTokenQuery($tokens, true, $extra, true), 20);
        if ($found === [] && count($tokens) > 1) {
            $found = $this->listFiles(self::buildTokenQuery($tokens, false, $extra, true), 20);
        }
        if ($found === []) {
            $found = $this->scanFiles($tokens, $expectedMime, $expectedMime === null, 20);
        }
        if ($found === []) {
            // Le nom désigne peut-être un DOSSIER : ils sont exclus d'office
            // ci-dessus, et « aucun fichier de ce nom » se lisait alors comme
            // « ce dossier n'existe pas ». Un appel de plus, sur le seul chemin
            // d'échec, pour renvoyer vers le bon outil.
            if ($expectedMime === null && $this->folderExists($tokens)) {
                throw new \RuntimeException(
                    '« ' . $name . ' » est un DOSSIER, pas un fichier : il ne se lit pas, il se parcourt. '
                    . 'Utilise `lister_dossier_google_drive` pour voir ce qu\'il contient, '
                    . 'puis lis le fichier voulu.'
                );
            }
            // Même raison qu'à la recherche : conseiller de mieux chercher un
            // fichier que MIA n'a pas le droit de voir ne mène nulle part.
            if (($note = $this->readScopeNote()) !== null) {
                throw new \RuntimeException($note);
            }
            throw new \RuntimeException(
                'Aucun ' . $label . ' « ' . $name . ' » trouvé dans ce Drive. '
                . 'Utilise `rechercher_google_drive` pour retrouver le bon fichier, puis reprends '
                . 'son identifiant ou son lien — et si la recherche ne donne rien non plus, '
                . 'dis-le à l\'utilisateur au lieu d\'insister.'
            );
        }
        $cible = self::normalizeForSearch($name);
        $exact = array_values(array_filter(
            $found,
            static fn(array $f): bool => self::normalizeForSearch((string)($f['name'] ?? '')) === $cible
        ));
        $retenus = $exact !== [] ? $exact : $found;
        if (count($retenus) > 1) {
            $noms = implode(', ', array_map(
                static fn(array $f): string => '« ' . (string)($f['name'] ?? '') . ' » (' . self::humanType((string)($f['mimeType'] ?? '')) . ')',
                array_slice($retenus, 0, 5)
            ));
            throw new \RuntimeException(
                'Plusieurs fichiers correspondent à « ' . $name . ' » : ' . $noms
                . '. Demande à l\'utilisateur lequel viser (ou son lien) — ne choisis pas à sa place.'
            );
        }
        return (string)($retenus[0]['id'] ?? '');
    }

    /**
     * Un DOSSIER porte-t-il ce nom ? Best-effort : sur le chemin d'échec de
     * `findFileByName`, une panne ici ne doit pas remplacer le message utile
     * par une erreur réseau.
     *
     * @param list<array{brut:string,norme:string}> $tokens
     */
    private function folderExists(array $tokens): bool
    {
        try {
            $q = self::buildTokenQuery($tokens, true, "mimeType='" . self::MIME_FOLDER . "'", true);
            return $this->listFiles($q, 1) !== [] || $this->scanFiles($tokens, self::MIME_FOLDER, false, 1) !== [];
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Résout id + nom d'un fichier et vérifie son type Google natif.
     * @return array{0:string,1:string} [fileId, name]
     */
    private function resolveGoogleFile(string $idOrUrl, string $expectedMime, string $label): array
    {
        // Le MIME attendu est transmis à la résolution : une recherche PAR NOM
        // filtrée sur le type ne peut pas ramener un PDF là où un Doc est
        // exigé. Le contrôle ci-dessous garde tout son sens pour un id ou une
        // URL, qui ne passent par aucun filtre.
        $meta   = $this->resolveFileMeta($idOrUrl, 'id,name,mimeType', $expectedMime, $label);
        $fileId = (string)$meta['id'];
        $mime   = (string)($meta['mimeType'] ?? '');
        if ($mime !== $expectedMime) {
            throw new \RuntimeException(
                'Ce fichier n\'est pas un ' . $label . ' (type : ' . self::humanType($mime) . ').'
            );
        }
        return [$fileId, (string)($meta['name'] ?? $fileId)];
    }

    // ===================== HTTP (spécificités Drive) =====================

    /** GET JSON sur l'API Drive v3 (chemin relatif + query). */
    private function requestJson(string $path, array $query, int $timeout = 30): array
    {
        $data = json_decode($this->requestRaw($path, $query, $timeout), true);
        return is_array($data) ? $data : [];
    }

    /** GET brut sur l'API Drive v3 (download / export) — plomberie apiRaw. */
    private function requestRaw(string $path, array $query, int $timeout = 120): string
    {
        $url = self::API_BASE . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        return $this->apiRaw('GET', $url, null, $timeout);
    }

    /** Un export de Doc natif trop gros a son message dédié (403 particulier). */
    protected function specificHttpError(int $status, string $body): ?\RuntimeException
    {
        if ($status === 403 && str_contains($body, 'exportSizeLimitExceeded')) {
            return new \RuntimeException('Export impossible : document Google trop volumineux (limite Google ~10 Mo).');
        }
        // SCOPE MANQUANT, testé AVANT tout le reste — le bug qui rendait ce
        // connecteur incompréhensible. Le corps d'un 403 « scope insuffisant »
        // porte `"status": "PERMISSION_DENIED"`, exactement comme un 403
        // « droits insuffisants sur le fichier » : la branche ci-dessous
        // l'attrapait donc la première et répondait « demandez le rôle Éditeur
        // au propriétaire, se reconnecter n'y changera rien » — le contraire
        // du remède, à un utilisateur qui a déjà tous les droits sur son
        // fichier. Les deux cas restent distincts, l'ordre décide.
        if ($status === 403 && self::isScopeError($body)) {
            return new \RuntimeException(self::MSG_SCOPE_MISSING);
        }
        // Accès par fichier (cf. MSG_PER_FILE_REFUSED). Testé AVANT le cas des
        // droits insuffisants : les deux sont des 403, mais les remèdes sont
        // opposés — ici l'utilisateur a tous les droits Google sur son fichier,
        // c'est MIA qui n'y a pas accès.
        if ($status === 403
            && (str_contains($body, 'appNotAuthorizedToFile')
                || str_contains($body, 'has not granted the app'))
        ) {
            return new \RuntimeException(self::MSG_PER_FILE_REFUSED);
        }
        // Droits insuffisants SUR CE FICHIER — le cas type sur un Drive partagé
        // d'entreprise, où l'on est souvent Lecteur ou Commentateur sans le
        // savoir. Le message générique (« Accès refusé ») envoie chercher du
        // côté de la connexion, alors que reconnecter n'y changerait rien : ce
        // sont les droits Google du fichier qu'il faut faire élargir.
        //
        // La détection « API non activée » reste PRIORITAIRE (elle arrive aussi
        // en 403) : l'intercepter ici la rendrait muette, exactement le piège
        // que le commentaire de cette méthode signale depuis toujours.
        if ($status === 403
            && self::disabledApiFromBody($body) === null
            && (str_contains($body, 'insufficientFilePermissions')
                || str_contains($body, 'does not have permission')
                || str_contains($body, 'PERMISSION_DENIED'))
        ) {
            return new \RuntimeException(
                'Droits insuffisants sur ce fichier : votre compte Google peut le lire mais pas le modifier. '
                . 'Sur un Drive partagé d\'entreprise, demandez le rôle « Éditeur » (ou « Contributeur ») au '
                . 'propriétaire du fichier ou du Drive — se reconnecter à MIA n\'y changera rien.'
            );
        }
        return null;
    }

    /** Libellé FR court d'un MIME pour le LLM et les messages d'erreur. */
    private static function humanType(string $mime): string
    {
        return match (true) {
            $mime === 'application/vnd.google-apps.document'     => 'Google Docs',
            $mime === 'application/vnd.google-apps.spreadsheet'  => 'Google Sheets',
            $mime === 'application/vnd.google-apps.presentation' => 'Google Slides',
            $mime === 'application/vnd.google-apps.folder'       => 'Dossier',
            str_contains($mime, 'wordprocessingml')              => 'Word (docx)',
            str_contains($mime, 'spreadsheetml')                 => 'Excel (xlsx)',
            str_contains($mime, 'presentationml')                => 'PowerPoint (pptx)',
            $mime === 'application/pdf'                          => 'PDF',
            str_starts_with($mime, 'image/')                     => 'Image',
            str_starts_with($mime, 'text/')                      => 'Texte',
            default                                              => ($mime !== '' ? $mime : 'inconnu'),
        };
    }
}
