<?php

declare(strict_types=1);

/**
 * Accès aux tâches Google PERSONNELLES d'un utilisateur (OAuth par utilisateur,
 * tokens UserDriveToken provider 'google_tasks') : listes de tâches, lecture,
 * création, modification et suppression.
 *
 * Contrairement aux autres connecteurs, celui-ci SUPPRIME — c'est la demande
 * explicite du produit, et Google Tasks n'offre de toute façon aucun scope
 * intermédiaire (« écrire sans supprimer » n'existe pas côté Google). Trois
 * garde-fous applicatifs compensent :
 *   1. toute écriture (créer / modifier / supprimer) passe par la file
 *      d'approbation humaine — rien n'est exécuté pendant le stream ;
 *   2. l'utilisateur peut couper la suppression dans les préférences du
 *      connecteur (PREF_ALLOW_DELETE) : l'outil n'est alors même pas exposé ;
 *   3. l'aperçu d'approbation d'une suppression annonce explicitement son
 *      irréversibilité.
 *
 * Plomberie commune (refresh lazy, retry 401, erreurs FR sans fuite) :
 * GoogleUserApiService — ce fichier ne porte que le métier Tâches.
 */
class UserTasksService extends GoogleUserApiService
{
    private const API_BASE = 'https://tasks.googleapis.com/tasks/v1';

    protected const OAUTH_CLASS       = GoogleTasksOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER_TASKS;
    protected const API_LABEL         = 'Google Tasks';
    protected const LOG_LABEL         = '[Tâches user]';
    protected const MSG_NOT_FOUND     = 'Liste ou tâche introuvable (déjà supprimée ou identifiant invalide).';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par Google Tasks (HTTP 400) — vérifiez les dates et identifiants fournis.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par Google Tasks';
    protected const MSG_SCOPE_INSUFFICIENT = self::MSG_RECONNECT_WRITE;

    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/tasks.readonly';
    public const SCOPE_WRITE    = 'https://www.googleapis.com/auth/tasks';

    /** Clé Settings : interrupteur global admin des Tâches utilisateur dans le chat. */
    public const SETTING_ENABLED = 'user_tasks_enabled';

    /** Clé du connecteur dans user_connector_prefs (= clé ConnectorRegistry). */
    public const PREFS_KEY = 'tasks';

    /** Liste par défaut côté Google quand l'utilisateur n'en a choisi aucune. */
    public const DEFAULT_LIST = '@default';

    // --- Bornes Google (dépassées, l'API répond 400) ---
    public const MAX_TITLE_LEN = 1024;
    public const MAX_NOTES_LEN = 8192;

    // --- Préférences utilisateur (cf. sanitizePrefs) ---
    public const PREF_LIST_ID      = 'liste_defaut_id';
    public const PREF_LIST_NAME    = 'liste_defaut_nom';
    public const PREF_HIDE_DONE    = 'masquer_terminees';
    public const PREF_HORIZON      = 'horizon_jours';
    public const PREF_ALLOW_DELETE = 'autoriser_suppression';
    /** Hérité : blob unique de consignes, absorbé dans PREF_RULES au 1er enregistrement. */
    public const PREF_NOTES        = 'consignes';
    public const PREF_RULES        = 'regles';
    public const MAX_HORIZON_DAYS  = 365;
    public const MAX_NOTES_PREF    = 500;

    /**
     * Règles d'organisation : une ligne = une consigne courte et autoportante
     * sur la FAÇON de ranger les tâches (« les relances client vont dans
     * Travail »). Bornées comme les souvenirs de UserMemory, et pour la même
     * raison : elles sont injectées au system prompt de chaque message où le
     * connecteur est actif.
     */
    public const MAX_RULES    = 15;
    public const MAX_RULE_LEN = 200;

    public const RULE_SOURCE_CHAT   = 'chat';
    public const RULE_SOURCE_MANUEL = 'manuel';

    public const MSG_RECONNECT = 'Connexion Google Tasks expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter ses tâches dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_WRITE = 'Autorisation de modification des tâches non accordée. '
        . 'L\'utilisateur doit reconnecter ses tâches dans Réglages → Comptes connectés.';

    private const DAYS_FR = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
        5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    /** Cache d'instance des listes (résolution d'un nom → id sans re-appeler). */
    private ?array $listCache = null;

    private function __construct(
        int $userId,
        string $refreshToken,
        ?string $accessToken,
        ?string $accessTokenExpiresAt,
        string $grantedScope,
        string $accountEmail = '',
        private readonly array $prefs = []
    ) {
        parent::__construct($userId, $refreshToken, $accessToken, $accessTokenExpiresAt, $grantedScope, $accountEmail);
    }

    /** Instance pour l'utilisateur, ou null si les tâches ne sont pas connectées (ou révoquées). */
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
            (string)($row['google_email'] ?? ''),
            self::prefsForUser($userId)
        );
    }

    /** Préférences effectives de cet utilisateur (défauts fusionnés). */
    public function prefs(): array
    {
        return $this->prefs;
    }

    /**
     * Un scope accordé est-il présent ? Comparaison sur la liste EXACTE des
     * scopes (séparés par des espaces) et non par `str_contains` : la chaîne
     * `.../auth/tasks` est un PRÉFIXE de `.../auth/tasks.readonly`, donc une
     * connexion en lecture seule passerait pour une connexion en écriture — et
     * le connecteur exposerait des outils que Google refuserait ensuite en 403.
     */
    private function hasScope(string $scope): bool
    {
        return self::scopeGranted($this->grantedScope, $scope);
    }

    // `scopeGranted()` vit sur GoogleUserApiService : le piège de préfixe est
    // le MÊME pour Drive (`.../auth/drive.metadata` est un préfixe de
    // `.../auth/drive.metadata.readonly`). Elle reste adressable en
    // `UserTasksService::scopeGranted()` par héritage — c'est ainsi que la
    // page Connexions l'appelle.

    /** Lecture possible ? (tasks.readonly, ou tasks qui couvre la lecture) */
    public function canRead(): bool
    {
        return $this->hasScope(self::SCOPE_READONLY) || $this->hasScope(self::SCOPE_WRITE);
    }

    /** Scope d'écriture (création / modification / suppression) accordé ? */
    public function canWrite(): bool
    {
        return $this->hasScope(self::SCOPE_WRITE);
    }

    /**
     * Suppression exposée au modèle ? Écriture accordée par Google ET non
     * refusée par l'utilisateur dans les préférences du connecteur. C'est le
     * seul « demi-droit » possible : Google ne sait pas le donner, MIA si.
     */
    public function canDelete(): bool
    {
        return $this->canWrite() && !empty($this->prefs[self::PREF_ALLOW_DELETE]);
    }

    // ===================== Lecture =====================

    /**
     * Listes de tâches du compte : `{id, nom, defaut}`.
     * Throw RuntimeException à message FR.
     */
    public function listTaskLists(): array
    {
        if ($this->listCache !== null) {
            return $this->listCache;
        }
        $data = $this->apiJson('GET', self::API_BASE . '/users/@me/lists?maxResults=100');

        $defaultId = trim((string)($this->prefs[self::PREF_LIST_ID] ?? ''));
        $out = [];
        foreach (($data['items'] ?? []) as $l) {
            $id = (string)($l['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id'     => $id,
                'nom'    => (string)($l['title'] ?? $id),
                'defaut' => $defaultId !== '' && $defaultId === $id,
            ];
        }
        return $this->listCache = $out;
    }

    /**
     * Tâches d'une liste. `$statut` ∈ ''|a_faire|terminee|toutes ; fenêtre
     * d'échéance optionnelle (ATTENTION : une fenêtre EXCLUT les tâches sans
     * échéance — contrainte de l'API Google, annoncée au modèle).
     * Retour : `{liste, liste_nom, taches[], filtre_echeance}`.
     * Throw RuntimeException à message FR.
     */
    public function listTasks(string $listRef, string $statut, string $from, string $to, int $limit = 20): array
    {
        $list  = $this->resolveListId($listRef);
        $limit = max(1, min(100, $limit));

        $statut = self::normalizeStatusFilter($statut, $this->prefs);
        $params = [
            'maxResults'    => (string)$limit,
            // Les tâches cochées deviennent « masquées » côté Google : sans
            // showHidden, showCompleted seul ne ramène rien de terminé.
            'showCompleted' => $statut === 'a_faire' ? 'false' : 'true',
            'showHidden'    => $statut === 'a_faire' ? 'false' : 'true',
        ];

        $window = self::dueWindow($from, $to, $this->prefs);
        if ($window !== null) {
            $params['dueMin'] = $window[0];
            $params['dueMax'] = $window[1];
        }

        $data = $this->apiJson(
            'GET',
            self::API_BASE . '/lists/' . rawurlencode($list['id']) . '/tasks?' . http_build_query($params)
        );

        $out = [];
        foreach (($data['items'] ?? []) as $t) {
            if (!is_array($t) || !empty($t['deleted'])) {
                continue;
            }
            $done = (string)($t['status'] ?? '') === 'completed';
            if ($statut === 'terminee' && !$done) {
                continue;
            }
            if ($statut === 'a_faire' && $done) {
                continue;
            }
            $out[] = self::summarizeTask($t, $list['id']);
        }

        return [
            'liste'           => $list['id'],
            'liste_nom'       => $list['nom'],
            'taches'          => $out,
            'filtre_echeance' => $window !== null
                ? 'Fenêtre d\'échéance appliquée : les tâches SANS échéance ne sont pas listées.'
                : '',
        ];
    }

    /**
     * Une tâche complète (notes intégrales, tronquées au budget).
     * Throw RuntimeException à message FR.
     */
    public function readTask(string $listRef, string $taskId, int $tokenBudget): array
    {
        $list = $this->resolveListId($listRef);
        $t    = $this->apiJson(
            'GET',
            self::API_BASE . '/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode(self::assertTaskId($taskId))
        );

        $notes     = trim((string)($t['notes'] ?? ''));
        $maxChars  = max(1000, $tokenBudget * 4);
        $truncated = mb_strlen($notes) > $maxChars;
        if ($truncated) {
            $notes = mb_substr($notes, 0, $maxChars) . "\n[… contenu tronqué]";
        }

        return self::summarizeTask($t, $list['id']) + [
            'liste_nom' => $list['nom'],
            'notes'     => $notes,
            'tronque'   => $truncated,
        ];
    }

    // ===================== Écriture (file d'approbation uniquement) =====================

    /**
     * Crée une tâche. Appelé UNIQUEMENT depuis la file d'approbation, au clic
     * « Approuver ».
     *
     * @return array{id:string,titre:string,liste:string,liste_nom:string,echeance:string,url:string}
     */
    public function createTask(array $args): array
    {
        $this->assertWritable();
        $list    = $this->resolveListId((string)($args['liste'] ?? ''));
        $payload = self::buildTaskPayload($args);

        $t = $this->apiJson('POST', self::API_BASE . '/lists/' . rawurlencode($list['id']) . '/tasks', $payload);
        if ((string)($t['id'] ?? '') === '') {
            throw new \RuntimeException('Création de la tâche refusée par Google Tasks.');
        }
        error_log('[Tâches user] tâche créée id=' . (string)$t['id']
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return $this->writeResult($t, $list);
    }

    /**
     * Modifie une tâche existante (titre, notes, échéance, statut). Champs
     * absents = inchangés.
     *
     * Cas particulier : « retirer l'échéance ». Un PATCH avec `due: null` est
     * ignoré par l'API Google — la seule voie est un PUT (update) portant la
     * tâche entière SANS le champ `due`. D'où la relecture préalable.
     *
     * @return array{id:string,titre:string,liste:string,liste_nom:string,echeance:string,url:string}
     */
    public function updateTask(array $args): array
    {
        $this->assertWritable();
        $list   = $this->resolveListId((string)($args['liste'] ?? ''));
        $taskId = self::assertTaskId((string)($args['tache'] ?? ''));
        $patch  = self::buildTaskPatch($args);
        // Le retrait d'échéance ne produit aucun champ de patch (il se joue par
        // ABSENCE de `due` dans un PUT) : il compte pourtant comme une
        // modification à part entière.
        $clearDue = self::clearsDueDate($args);
        if ($patch === [] && !$clearDue) {
            throw new \RuntimeException('Aucune modification demandée (titre, notes, échéance ou statut).');
        }

        $url = self::API_BASE . '/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode($taskId);

        if ($clearDue) {
            // Relecture puis PUT complet sans `due` : un PATCH ne l'effacerait pas.
            $current = $this->apiJson('GET', $url);
            $body    = array_merge($current, $patch);
            unset($body['due']);
            $t = $this->apiJson('PUT', $url, $body);
        } else {
            $t = $this->apiJson('PATCH', $url, $patch);
        }

        if ((string)($t['id'] ?? '') === '') {
            throw new \RuntimeException('Modification de la tâche refusée par Google Tasks.');
        }
        error_log('[Tâches user] tâche modifiée id=' . $taskId
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return $this->writeResult($t, $list);
    }

    /**
     * Supprime définitivement une tâche. Appelé UNIQUEMENT après approbation
     * explicite, et seulement si l'utilisateur n'a pas coupé la suppression
     * dans les préférences du connecteur.
     *
     * @return array{id:string,titre:string,liste:string,liste_nom:string,echeance:string,url:string}
     */
    public function deleteTask(array $args): array
    {
        $this->assertWritable();
        if (!$this->canDelete()) {
            throw new \RuntimeException('La suppression de tâches est désactivée dans vos préférences '
                . '(Réglages → Comptes connectés → Google Tasks).');
        }
        $list   = $this->resolveListId((string)($args['liste'] ?? ''));
        $taskId = self::assertTaskId((string)($args['tache'] ?? ''));
        $url    = self::API_BASE . '/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode($taskId);

        // Relecture AVANT suppression : après, le titre est perdu et le message
        // de confirmation ne pourrait plus nommer ce qui a disparu.
        $titre = '';
        try {
            $before = $this->apiJson('GET', $url);
            $titre  = trim((string)($before['title'] ?? ''));
        } catch (\RuntimeException) {
            // Tâche déjà absente ou illisible : la suppression reste tentée.
        }

        $this->apiJson('DELETE', $url); // 204 sans corps
        error_log('[Tâches user] tâche supprimée id=' . $taskId
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return [
            'id'        => $taskId,
            'titre'     => $titre !== '' ? $titre : '(sans titre)',
            'liste'     => $list['id'],
            'liste_nom' => $list['nom'],
            'echeance'  => '',
            'url'       => 'https://tasks.google.com/',
        ];
    }

    private function assertWritable(): void
    {
        if (!$this->canWrite()) {
            throw new \RuntimeException(self::MSG_RECONNECT_WRITE);
        }
    }

    /** @param array{id:string,nom:string} $list */
    private function writeResult(array $t, array $list): array
    {
        return [
            'id'        => (string)$t['id'],
            'titre'     => trim((string)($t['title'] ?? '')) ?: '(sans titre)',
            'liste'     => $list['id'],
            'liste_nom' => $list['nom'],
            'echeance'  => self::formatDue($t['due'] ?? null),
            'url'       => 'https://tasks.google.com/',
        ];
    }

    /**
     * Référence de liste (id, nom, vide) → `{id, nom}`.
     * Vide → liste par défaut des préférences, sinon `@default` (liste
     * principale Google). Un nom est résolu par comparaison insensible à la
     * casse et aux accents sur les listes du compte.
     */
    private function resolveListId(string $ref): array
    {
        $ref = self::normalizeListRef($ref);
        if ($ref === '') {
            $ref = trim((string)($this->prefs[self::PREF_LIST_ID] ?? ''));
        }
        if ($ref === '' || $ref === self::DEFAULT_LIST) {
            // Nom affichable : la liste par défaut Google n'est connue qu'après
            // un listing — inutile d'en payer un pour un simple libellé.
            return ['id' => self::DEFAULT_LIST, 'nom' => trim((string)($this->prefs[self::PREF_LIST_NAME] ?? '')) ?: 'Mes tâches'];
        }

        foreach ($this->listTaskLists() as $l) {
            if ($l['id'] === $ref) {
                return ['id' => $l['id'], 'nom' => $l['nom']];
            }
        }
        $fold = self::foldForCompare($ref);
        foreach ($this->listTaskLists() as $l) {
            if (self::foldForCompare($l['nom']) === $fold) {
                return ['id' => $l['id'], 'nom' => $l['nom']];
            }
        }
        throw new \RuntimeException('Liste de tâches introuvable : « ' . $ref . ' » — utilise lister_listes_taches d\'abord.');
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /** Référence de liste nettoyée ; vide ou « principale » → chaîne vide (défaut appliqué par l'appelant). */
    public static function normalizeListRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || strcasecmp($ref, 'principale') === 0 || strcasecmp($ref, 'default') === 0) {
            return '';
        }
        if (mb_strlen($ref) > 200) {
            throw new \RuntimeException('Identifiant de liste de tâches invalide (trop long).');
        }
        return $ref;
    }

    /** Identifiant de tâche plausible, sinon throw (évite un appel Google inutile). */
    public static function assertTaskId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[A-Za-z0-9_\-]{5,200}$/', $id) !== 1) {
            throw new \RuntimeException('Identifiant de tâche invalide — utilise lister_taches d\'abord.');
        }
        return $id;
    }

    /**
     * Échéance saisie par le modèle → horodatage RFC3339 attendu par Google.
     *
     * Google Tasks ne stocke QUE la date : l'heure éventuellement fournie est
     * ignorée côté Google. On normalise donc à minuit UTC, et on ne laisse pas
     * croire à une heure d'échéance qui n'existe pas.
     * Vide → null. Throw RuntimeException si illisible.
     */
    public static function parseDueDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})([ T]\d{2}:\d{2})?/', $raw, $m) === 1) {
            $day = $m[1];
            $d   = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
            if ($d !== false && $d->format('Y-m-d') === $day) {
                return $d->format('Y-m-d\T00:00:00.000\Z');
            }
        }
        throw new \RuntimeException('Échéance invalide : « ' . $raw . ' » (format attendu : AAAA-MM-JJ).');
    }

    /** Le modèle demande-t-il le RETRAIT de l'échéance ? (« aucune », « sans », « -»)  */
    public static function clearsDueDate(array $args): bool
    {
        if (!array_key_exists('echeance', $args)) {
            return false;
        }
        $raw = mb_strtolower(trim((string)$args['echeance']));
        return in_array($raw, ['aucune', 'aucun', 'sans', 'none', '-'], true);
    }

    /** Statut du modèle (`a_faire`|`terminee`) → statut Google, ou throw. */
    public static function normalizeStatus(string $raw): string
    {
        return match (mb_strtolower(trim($raw))) {
            'terminee', 'terminée', 'completed', 'faite', 'fait' => 'completed',
            'a_faire', 'à faire', 'a faire', 'needsaction', 'todo' => 'needsAction',
            default => throw new \RuntimeException('Statut invalide : attendu « a_faire » ou « terminee ».'),
        };
    }

    /**
     * Filtre de statut d'un listing : valeur du modèle, sinon préférence
     * utilisateur (masquer les tâches terminées), sinon tout.
     * Retour ∈ a_faire|terminee|toutes.
     */
    public static function normalizeStatusFilter(string $raw, array $prefs): string
    {
        $raw = mb_strtolower(trim($raw));
        if (in_array($raw, ['a_faire', 'a faire', 'à faire', 'todo', 'needsaction'], true)) {
            return 'a_faire';
        }
        if (in_array($raw, ['terminee', 'terminée', 'completed', 'faites'], true)) {
            return 'terminee';
        }
        if (in_array($raw, ['toutes', 'tout', 'all'], true)) {
            return 'toutes';
        }
        return !empty($prefs[self::PREF_HIDE_DONE]) ? 'a_faire' : 'toutes';
    }

    /**
     * Fenêtre d'échéance `[dueMin, dueMax]` RFC3339, ou null si aucune borne
     * (ni demandée par le modèle, ni imposée par la préférence d'horizon).
     *
     * Une fenêtre EXCLUT les tâches sans échéance côté Google : elle n'est donc
     * jamais appliquée « pour faire joli » — seulement sur demande explicite ou
     * horizon configuré.
     *
     * @return array{0:string,1:string}|null
     */
    public static function dueWindow(string $from, string $to, array $prefs): ?array
    {
        $min = self::parseDueDate($from);
        $max = self::parseDueDate($to);

        $horizon = (int)($prefs[self::PREF_HORIZON] ?? 0);
        if ($min === null && $max === null) {
            if ($horizon <= 0) {
                return null;
            }
            $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
            $min   = $today->format('Y-m-d\T00:00:00.000\Z');
            $max   = $today->modify('+' . $horizon . ' days')->format('Y-m-d\T00:00:00.000\Z');
            return [$min, $max];
        }

        // Une seule borne fournie : l'autre s'ouvre largement plutôt que de
        // refuser la demande.
        $min ??= '1970-01-01T00:00:00.000Z';
        $max ??= (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
            ->modify('+' . self::MAX_HORIZON_DAYS . ' days')->format('Y-m-d\T00:00:00.000\Z');

        if ($max < $min) {
            throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
        }
        return [$min, $max];
    }

    /**
     * Arguments de `creer_tache` → corps de tasks.insert.
     * Throw RuntimeException à message FR sur toute entrée invalide.
     */
    public static function buildTaskPayload(array $args): array
    {
        $titre = trim((string)($args['titre'] ?? ''));
        if ($titre === '') {
            throw new \RuntimeException('Titre de la tâche manquant.');
        }
        $payload = ['title' => mb_substr($titre, 0, self::MAX_TITLE_LEN)];

        $notes = trim((string)($args['notes'] ?? ''));
        if ($notes !== '') {
            $payload['notes'] = mb_substr($notes, 0, self::MAX_NOTES_LEN);
        }
        // À la création, « aucune échéance » est l'absence de champ : rien à effacer.
        if (!self::clearsDueDate($args)) {
            $due = self::parseDueDate((string)($args['echeance'] ?? ''));
            if ($due !== null) {
                $payload['due'] = $due;
            }
        }
        $statut = trim((string)($args['statut'] ?? ''));
        if ($statut !== '') {
            $payload['status'] = self::normalizeStatus($statut);
        }
        return $payload;
    }

    /**
     * Arguments de `modifier_tache` → corps du PATCH : uniquement les champs
     * réellement fournis (un champ absent ne doit pas écraser l'existant).
     * Le retrait d'échéance n'est PAS dans le patch (cf. updateTask).
     */
    public static function buildTaskPatch(array $args): array
    {
        $patch = [];

        if (array_key_exists('titre', $args) && trim((string)$args['titre']) !== '') {
            $patch['title'] = mb_substr(trim((string)$args['titre']), 0, self::MAX_TITLE_LEN);
        }
        if (array_key_exists('notes', $args)) {
            $patch['notes'] = mb_substr(trim((string)$args['notes']), 0, self::MAX_NOTES_LEN);
        }
        if (array_key_exists('echeance', $args) && !self::clearsDueDate($args)) {
            $due = self::parseDueDate((string)$args['echeance']);
            if ($due !== null) {
                $patch['due'] = $due;
            }
        }
        if (array_key_exists('statut', $args) && trim((string)$args['statut']) !== '') {
            $status = self::normalizeStatus((string)$args['statut']);
            $patch['status'] = $status;
            // Repasser une tâche « à faire » sans effacer `completed` laisse
            // Google la considérer comme terminée : les deux champs vont ensemble.
            if ($status === 'needsAction') {
                $patch['completed'] = null;
            }
        }
        return $patch;
    }

    /** Ligne de tâche Google → résumé FR stable. */
    public static function summarizeTask(array $t, string $listId): array
    {
        $notes = trim((string)($t['notes'] ?? ''));
        return [
            'id'       => (string)($t['id'] ?? ''),
            'liste'    => $listId,
            'titre'    => trim((string)($t['title'] ?? '')) ?: '(sans titre)',
            'statut'   => (string)($t['status'] ?? '') === 'completed' ? 'terminee' : 'a_faire',
            'echeance' => self::formatDue($t['due'] ?? null),
            'apercu'   => $notes === '' ? '' : (mb_strlen($notes) > 200 ? mb_substr($notes, 0, 200) . '…' : $notes),
        ];
    }

    /**
     * Échéance Google (RFC3339, heure non significative) → texte FR lisible :
     * « vendredi 07/08/2026 ». Vide si aucune échéance.
     */
    public static function formatDue(mixed $due): string
    {
        if (!is_string($due) || trim($due) === '') {
            return '';
        }
        try {
            $d = new \DateTimeImmutable($due);
        } catch (\Throwable) {
            return $due; // horodatage illisible : brut plutôt que crash
        }
        return self::DAYS_FR[(int)$d->format('N')] . ' ' . $d->format('d/m/Y');
    }

    // ===================== Préférences utilisateur =====================

    /** Valeurs par défaut du connecteur (un compte sans réglage se comporte ainsi). */
    public static function defaultPrefs(): array
    {
        return [
            self::PREF_LIST_ID      => '',
            self::PREF_LIST_NAME    => '',
            self::PREF_HIDE_DONE    => true,
            self::PREF_HORIZON      => 0,   // 0 = aucun filtre d'échéance
            self::PREF_ALLOW_DELETE => true,
            self::PREF_NOTES        => '', // hérité : toujours vidé au profit de PREF_RULES
            self::PREF_RULES        => [],
        ];
    }

    /**
     * Texte d'une règle → forme propre : une seule ligne, bornée.
     * PURE — testable sans base.
     */
    public static function sanitizeRuleText(string $raw): string
    {
        $clean = trim((string)preg_replace('/\s+/u', ' ', $raw));
        if ($clean === '') {
            return '';
        }
        if (mb_strlen($clean) > self::MAX_RULE_LEN) {
            $clean = rtrim(mb_substr($clean, 0, self::MAX_RULE_LEN - 1)) . '…';
        }
        return $clean;
    }

    /**
     * Liste de règles quelle qu'en soit la provenance → forme canonique
     * `[{id, texte, source}]` : dédupliquée, bornée à MAX_RULES.
     *
     * Deux formes acceptées, parce qu'il y a deux entrées : le JSON déjà
     * stocké (liste d'objets) et le formulaire de la page Connexions (map
     * id => texte, une règle vidée = une règle supprimée).
     *
     * Déduplication par `UserMemory::normalizeForCompare` — même besoin, même
     * règle : « Les Relances vont dans Travail. » et « les relances vont dans
     * travail » sont la même consigne, et deux exemplaires mangeraient deux
     * fois le budget de prompt.
     *
     * PURE — testable sans base.
     */
    public static function normalizeRules(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out  = [];
        $seen = [];
        foreach ($raw as $cle => $item) {
            if (is_array($item)) {
                $texte  = self::sanitizeRuleText((string)($item['texte'] ?? ''));
                $id     = (string)($item['id'] ?? '');
                $source = (string)($item['source'] ?? self::RULE_SOURCE_MANUEL);
            } else {
                // Forme formulaire : la clé porte l'id, la valeur le texte.
                $texte  = self::sanitizeRuleText((string)$item);
                $id     = (string)$cle;
                $source = self::RULE_SOURCE_MANUEL;
            }
            if ($texte === '') {
                continue; // règle vidée = règle supprimée
            }
            $fold = UserMemory::normalizeForCompare($texte);
            if ($fold === '' || isset($seen[$fold])) {
                continue;
            }
            $seen[$fold] = true;

            if (preg_match('/^[a-f0-9]{8}$/', $id) !== 1) {
                $id = self::newRuleId();
            }
            $out[] = [
                'id'     => $id,
                'texte'  => $texte,
                'source' => $source === self::RULE_SOURCE_CHAT ? self::RULE_SOURCE_CHAT : self::RULE_SOURCE_MANUEL,
            ];
            if (count($out) >= self::MAX_RULES) {
                break;
            }
        }
        return $out;
    }

    /**
     * Ajoute une règle à un jeu de préférences et renvoie l'issue.
     * PURE (hors tirage d'id) : c'est l'appelant qui persiste.
     *
     * @return array{status:'ok'|'duplicate'|'full'|'empty', prefs:array, texte:string}
     */
    public static function addRule(array $prefs, string $raw, string $source): array
    {
        $texte = self::sanitizeRuleText($raw);
        if ($texte === '') {
            return ['status' => 'empty', 'prefs' => $prefs, 'texte' => ''];
        }

        $regles = self::normalizeRules($prefs[self::PREF_RULES] ?? []);
        $fold   = UserMemory::normalizeForCompare($texte);
        foreach ($regles as $r) {
            if (UserMemory::normalizeForCompare($r['texte']) === $fold) {
                return ['status' => 'duplicate', 'prefs' => $prefs, 'texte' => $texte];
            }
        }
        if (count($regles) >= self::MAX_RULES) {
            return ['status' => 'full', 'prefs' => $prefs, 'texte' => $texte];
        }

        $regles[] = ['id' => self::newRuleId(), 'texte' => $texte, 'source' => $source];
        $prefs[self::PREF_RULES] = $regles;

        return ['status' => 'ok', 'prefs' => $prefs, 'texte' => $texte];
    }

    /** Ajoute une règle et persiste. @return array{status:string,texte:string} */
    public static function addRuleForUser(int $userId, string $raw, string $source): array
    {
        $result = self::addRule(self::prefsForUser($userId), $raw, $source);
        if ($result['status'] === 'ok') {
            self::savePrefsForUser($userId, $result['prefs']);
        }
        return ['status' => $result['status'], 'texte' => $result['texte']];
    }

    private static function newRuleId(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * Saisie du formulaire (ou contenu stocké) → préférences propres et
     * complètes. PURE — testable sans base.
     */
    public static function sanitizePrefs(array $raw): array
    {
        $d = self::defaultPrefs();

        $listId = trim((string)($raw[self::PREF_LIST_ID] ?? ''));
        if (mb_strlen($listId) > 200) {
            $listId = '';
        }
        $listName = trim((string)($raw[self::PREF_LIST_NAME] ?? ''));

        $horizon = (int)($raw[self::PREF_HORIZON] ?? $d[self::PREF_HORIZON]);
        $horizon = max(0, min(self::MAX_HORIZON_DAYS, $horizon));

        $regles = self::normalizeRules($raw[self::PREF_RULES] ?? []);

        // Migration du blob hérité : les consignes saisies en une seule zone de
        // texte deviennent une règle. Le champ est ensuite laissé vide POUR
        // TOUJOURS — deux endroits disant la même chose finiraient par diverger,
        // et seul l'un des deux serait injecté au modèle.
        $notes = trim((string)preg_replace('/\s+/u', ' ', (string)($raw[self::PREF_NOTES] ?? '')));
        if ($notes !== '') {
            $migre = self::addRule([self::PREF_RULES => $regles], $notes, self::RULE_SOURCE_MANUEL);
            $regles = self::normalizeRules($migre['prefs'][self::PREF_RULES] ?? $regles);
        }

        return [
            self::PREF_LIST_ID      => $listId,
            self::PREF_LIST_NAME    => $listId === '' ? '' : mb_substr($listName, 0, 120),
            self::PREF_HIDE_DONE    => self::toBool($raw[self::PREF_HIDE_DONE] ?? $d[self::PREF_HIDE_DONE]),
            self::PREF_HORIZON      => $horizon,
            self::PREF_ALLOW_DELETE => self::toBool($raw[self::PREF_ALLOW_DELETE] ?? $d[self::PREF_ALLOW_DELETE]),
            self::PREF_NOTES        => '',
            self::PREF_RULES        => $regles,
        ];
    }

    /** Préférences enregistrées d'un utilisateur, défauts fusionnés. */
    public static function prefsForUser(int $userId): array
    {
        return self::sanitizePrefs(UserConnectorPrefs::get($userId, self::PREFS_KEY));
    }

    public static function savePrefsForUser(int $userId, array $raw): array
    {
        $clean = self::sanitizePrefs($raw);
        UserConnectorPrefs::set($userId, self::PREFS_KEY, $clean);
        return $clean;
    }

    /**
     * Fragment ajouté à la consigne des outils : ce que l'utilisateur a
     * stipulé dans la configuration du connecteur. Vide → 0 token.
     * PURE — testable sans base.
     */
    public static function prefsPromptFragment(array $prefs): string
    {
        $bits = [];

        $listName = trim((string)($prefs[self::PREF_LIST_NAME] ?? ''));
        if (trim((string)($prefs[self::PREF_LIST_ID] ?? '')) !== '') {
            $bits[] = t("sa liste de tâches par défaut est « {liste} » — travaille dedans sauf s'il en désigne une autre",
                ['liste' => $listName !== '' ? $listName : t('celle qu\'il a choisie')]);
        }
        if (!empty($prefs[self::PREF_HIDE_DONE])) {
            $bits[] = t("il ne veut PAS voir les tâches déjà terminées, sauf s'il les demande explicitement");
        }
        $horizon = (int)($prefs[self::PREF_HORIZON] ?? 0);
        if ($horizon > 0) {
            $bits[] = t("sans période précisée, limite-toi aux échéances des {n} prochains jours (les tâches sans échéance sont alors exclues — dis-le si c'est ce qu'il cherchait)",
                ['n' => (string) $horizon]);
        }
        if (empty($prefs[self::PREF_ALLOW_DELETE])) {
            $bits[] = t("il a DÉSACTIVÉ la suppression de tâches : si on t'en demande une, explique qu'il faut la réactiver dans Réglages → Comptes connectés, ou propose de marquer la tâche comme terminée");
        }

        $txt = '';
        if ($bits !== []) {
            $txt .= "\n" . t("Préférences de l'utilisateur pour ses tâches : {liste}.", ['liste' => implode(' ; ', $bits)]);
        }

        $regles = self::normalizeRules($prefs[self::PREF_RULES] ?? []);
        if ($regles !== []) {
            // Texte libre de l'utilisateur : cadré comme une consigne d'usage,
            // jamais comme une instruction pouvant élargir les droits.
            $txt .= "\n" . t("Règles d'organisation que l'utilisateur a lui-même édictées, à respecter dans tes choix de liste et tes formulations, sans jamais élargir ce que tu as le droit de faire :");
            foreach ($regles as $r) {
                $txt .= "\n- " . $r['texte'];
            }
            $txt .= "\n" . t("Ces règles viennent de lui : ne les contredis pas, et ne prétends jamais en avoir modifié ou supprimé une — il les gère dans Réglages → Comptes connectés → Google Tasks.");
        }
        return $txt;
    }

    /**
     * Consigne qui pousse l'assistant à PROPOSER de retenir une règle. Sans
     * elle, la capture existe mais ne sert jamais : personne ne pense à dicter
     * ses conventions de rangement, on les corrige au cas par cas.
     *
     * Deux déclencheurs seulement, pour ne pas transformer chaque échange en
     * sollicitation : une correction de l'utilisateur, ou un choix identique
     * répété. Et la proposition doit rester une question — c'est la réponse de
     * l'utilisateur qui déclenche la capture, jamais l'assistant seul.
     */
    public static function ruleNudgeFragment(array $prefs): string
    {
        if (count(self::normalizeRules($prefs[self::PREF_RULES] ?? [])) >= self::MAX_RULES) {
            return ''; // plus de place : proposer serait promettre pour rien
        }

        return "\n" . t("Si l'utilisateur te CORRIGE sur le rangement d'une tâche (mauvaise liste, échéance oubliée), ou s'il fait le MÊME choix pour la troisième fois dans la conversation, propose-lui en UNE phrase de le retenir pour la suite — par exemple : « Je retiens que les relances client vont dans Travail ? ». Attends sa réponse : c'est elle qui enregistre, pas toi. N'affirme jamais avoir retenu quelque chose de ta propre initiative, et ne propose pas plus d'une fois par réponse.");
    }

    /**
     * Aperçu HUMAIN d'une écriture pour la carte d'approbation (mêmes clés que
     * UserCalendarService::actionPreview). `$ctx` porte le nom de la liste
     * résolue et le titre connu de la tâche visée, quand ils sont disponibles.
     *
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string}
     */
    public static function actionPreview(string $tool, array $args, array $ctx = []): array
    {
        $titre    = trim((string)($ctx['titre'] ?? $args['titre'] ?? '')) ?: t('(sans titre)');
        $liste    = trim((string)($ctx['liste_nom'] ?? $args['liste'] ?? '')) ?: t('Mes tâches');
        $echeance = trim((string)($args['echeance'] ?? ''));
        $notes    = trim((string)($args['notes'] ?? ''));

        $action  = t('Créer une tâche Google');
        $impacts = [];

        if ($tool === 'modifier_tache') {
            $action = t('Modifier une tâche Google');
            $champs = [];
            if (trim((string)($args['titre'] ?? '')) !== '') { $champs[] = t('titre'); }
            if (array_key_exists('notes', $args))            { $champs[] = t('notes'); }
            if ($echeance !== '')                            { $champs[] = t('échéance'); }
            if (trim((string)($args['statut'] ?? '')) !== '') { $champs[] = t('statut'); }
            $impacts[] = t('Champs modifiés : {champs}.', ['champs' => $champs !== [] ? implode(', ', $champs) : t('aucun')]);
            $impacts[] = t('Liste « {liste} ». La version précédente n\'est pas conservée.', ['liste' => $liste]);
        } elseif ($tool === 'supprimer_tache') {
            $action    = t('Supprimer une tâche Google');
            $impacts[] = t('La tâche sera DÉFINITIVEMENT supprimée de la liste « {liste} ».', ['liste' => $liste]);
            $impacts[] = t('Cette action est IRRÉVERSIBLE : ni MIA ni Google ne peuvent la restaurer.');
            $impacts[] = t('Pour garder une trace, annulez et demandez plutôt à la marquer comme terminée.');
        } else {
            $impacts[] = t('Une tâche sera ajoutée à la liste « {liste} ».', ['liste' => $liste]);
            if ($echeance !== '') {
                $impacts[] = t('Échéance : {date} (Google Tasks ne retient que la date, pas l\'heure).', ['date' => $echeance]);
            }
            $impacts[] = t('Vous pourrez la modifier ou la supprimer à tout moment dans Google Tasks.');
        }

        return [
            'action'  => $action,
            'fichier' => $titre . ($echeance !== '' && $tool !== 'supprimer_tache' ? ' — ' . $echeance : ''),
            'url'     => null,
            'impacts' => $impacts,
            'apercu'  => $notes === '' ? null : (mb_strlen($notes) > 280 ? mb_substr($notes, 0, 280) . '…' : $notes),
            'icone'   => '/assets/img/google-tasks.svg',
        ];
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array((string)$v, ['1', 'true', 'on', 'oui'], true);
    }

    /** Minuscules sans accents — comparaison des noms de listes. */
    private static function foldForCompare(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe', '’' => "'", 'ʼ' => "'",
        ]);
    }
}
