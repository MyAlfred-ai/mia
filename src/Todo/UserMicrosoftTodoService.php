<?php

declare(strict_types=1);

/**
 * Accès à MICROSOFT TO DO d'un utilisateur (OAuth Microsoft par utilisateur,
 * jetons UserDriveToken provider 'microsoft_todo') : listes, tâches, lecture,
 * création et modification — ces deux dernières UNIQUEMENT via la file
 * d'approbation. Aucune suppression : l'outil n'existe pas (cf.
 * MicrosoftTodoOAuth pour le raisonnement sur `Tasks.ReadWrite`). « C'est
 * fait » se traduit par « marquer terminée », qui garde la trace.
 *
 * Plomberie commune (refresh lazy, jetons roulants, retry 401, erreurs FR sans
 * fuite) : MicrosoftUserApiService — ce fichier ne porte que le métier To Do.
 *
 * ── CINQ PIÈGES GRAPH, TOUS PAYÉS DANS LE CODE ─────────────────────────────
 *
 *  1. **Une tâche n'existe que DANS sa liste.** Graph n'a pas de
 *     `/me/todo/tasks/{id}` : toute lecture ou écriture exige l'id de la liste
 *     ET celui de la tâche. Les donner séparément au modèle, c'est l'inviter à
 *     les dépareiller (la tâche de « Courses » cherchée dans « Travail » → 404
 *     opaque). L'identifiant rendu au modèle est donc COMPOSÉ
 *     (`<liste>~<tâche>`, `composeTaskRef`) : une seule chaîne à recopier, et
 *     la mémoire de conversation (ConnectorContext) la retrouve telle quelle au
 *     tour suivant. `~` n'appartient pas à l'alphabet des identifiants Graph
 *     (base64, base64url) : la coupure est sans ambiguïté.
 *
 *  2. **L'échéance est une DATE déguisée en horodatage.** `dueDateTime` est un
 *     couple `{dateTime, timeZone}` dont l'heure ne veut rien dire pour
 *     l'utilisateur (To Do n'affiche qu'un jour). Selon le client qui l'a
 *     posée, Graph la rend à minuit dans le fuseau d'origine OU convertie en
 *     UTC (minuit à Paris → 22:00 UTC la VEILLE). Lire le jour brut décalerait
 *     donc une échéance d'un jour sur deux. `dueDate()` : heure à minuit →
 *     le jour tel quel ; sinon conversion dans le fuseau de l'utilisateur.
 *     À l'écriture, minuit DANS le fuseau de l'utilisateur.
 *
 *  3. **La liste « Emails marqués d'un indicateur » est en lecture seule.**
 *     Outlook la remplit (`wellknownListName = flaggedEmails`) ; y créer une
 *     tâche est refusé. `resolveList(…, forWrite: true)` le dit AVANT l'appel,
 *     plutôt qu'un 400 que personne ne saurait lire.
 *
 *  4. **Aucun lien web par tâche.** Graph ne rend pas de `webLink` sur une
 *     tâche, et l'adresse de l'application dépend du type de compte
 *     (to-do.office.com ou to-do.live.com). MIA n'en invente donc aucun : les
 *     messages de confirmation nomment la tâche et sa liste, sans lien.
 *
 *  5. **Pages de résultats.** `tasks` est paginé (`@odata.nextLink`). Le lien
 *     suivant n'est suivi QUE s'il vise Graph : le jeton part avec la requête,
 *     jamais vers une adresse rendue par une réponse.
 *
 * Un compte sans boîte Exchange Online n'a pas de To Do exploitable via Graph
 * (MSG_NO_MAILBOX, comme le calendrier).
 */
class UserMicrosoftTodoService extends MicrosoftUserApiService
{
    private const TODO = self::GRAPH_BASE . '/me/todo';

    /** Scopes Graph, en forme courte : `scopeGranted` gère le préfixe d'URI. */
    public const SCOPE_READ      = 'Tasks.Read';
    public const SCOPE_READWRITE = 'Tasks.ReadWrite';

    /** Clé Settings : interrupteur global admin de Microsoft To Do dans le chat. */
    public const SETTING_ENABLED = 'user_mstodo_enabled';

    /** Séparateur de l'identifiant composé liste~tâche (piège 1). */
    public const REF_SEPARATOR = '~';

    public const MAX_TITLE_LEN = 255;
    public const MAX_NOTES_LEN = 8000;

    /** Lignes demandées par page, et pages lues au plus. */
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 5;

    /** Valeur Graph de la liste par défaut, et de la liste des emails marqués. */
    public const WELLKNOWN_DEFAULT = 'defaultList';
    public const WELLKNOWN_FLAGGED = 'flaggedEmails';

    public const MSG_RECONNECT = 'Connexion à Microsoft To Do expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter Microsoft To Do dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_WRITE = 'Autorisation de modification des tâches non accordée. '
        . 'L\'utilisateur doit reconnecter Microsoft To Do dans Réglages → Comptes connectés.';
    public const MSG_FLAGGED_READONLY = 'La liste « Emails marqués d\'un indicateur » est remplie par Outlook : '
        . 'on ne peut pas y ajouter ni y modifier de tâche. Choisis une autre liste (lister_listes_todo).';

    protected const OAUTH_CLASS       = MicrosoftTodoOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER_MSTODO;
    protected const API_LABEL         = 'Microsoft To Do';
    protected const LOG_LABEL         = '[To Do user]';
    protected const MSG_NOT_FOUND     = 'Liste ou tâche introuvable dans Microsoft To Do (supprimée, ou '
        . 'identifiant invalide — utilise lister_taches_todo pour obtenir l\'identifiant exact).';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par Microsoft To Do (HTTP 400) — vérifiez les dates '
        . 'et les identifiants fournis.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par Microsoft To Do';
    protected const MSG_SCOPE_INSUFFICIENT = 'Permission sur les tâches non accordée. L\'utilisateur doit '
        . 'reconnecter Microsoft To Do dans Réglages → Comptes connectés ; si le refus persiste, un administrateur '
        . 'de l\'annuaire Microsoft doit consentir à la permission Tasks.ReadWrite pour l\'application.';
    protected const MSG_NO_MAILBOX    = 'Ce compte Microsoft n\'a pas de boîte Exchange Online (licence absente, '
        . 'ou compte d\'annuaire sans messagerie), donc aucune liste Microsoft To Do accessible. Aucune '
        . 'reconnexion n\'y changera rien : il faut un compte disposant réellement d\'Outlook.';

    private const DAYS_FR = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
        5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    /** Listes du compte, mises en cache pour la durée de l'instance (un tour). */
    private ?array $listCache = null;

    /** Fuseau de l'utilisateur pour CE tour ; '' = celui du serveur. */
    private string $timeZone = '';

    /** Instance pour l'utilisateur, ou null si To Do n'est pas connecté (ou révoqué). */
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

    /** Consultation possible ? (l'écriture l'implique) */
    public function canRead(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_READ)
            || self::scopeGranted($this->grantedScope, self::SCOPE_READWRITE);
    }

    /** Création et modification accordées lors du consentement ? */
    public function canWrite(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_READWRITE);
    }

    /**
     * Fuseau de l'utilisateur (celui du navigateur) : échéances lues et posées,
     * heures de rappel, date du jour de la consigne. Le serveur tourne en UTC.
     */
    public function setTimeZone(string $timeZone): void
    {
        $this->timeZone = UserCalendarService::validTimeZone($timeZone);
    }

    /** Fuseau effectif : celui de l'utilisateur, sinon celui du serveur. */
    public function timeZoneName(): string
    {
        return $this->timeZone !== '' ? $this->timeZone : date_default_timezone_get();
    }

    // ===================== Lecture =====================

    /**
     * Listes du compte : `{id, nom, defaut, partagee, lecture_seule}`.
     * Throw RuntimeException à message FR.
     */
    public function listTaskLists(): array
    {
        if ($this->listCache !== null) {
            return $this->listCache;
        }
        $out = [];
        $url = self::TODO . '/lists?$top=' . self::PAGE_SIZE;
        for ($page = 0; $page < self::MAX_PAGES && $url !== ''; $page++) {
            $data = $this->apiJson('GET', $url);
            foreach ((array)($data['value'] ?? []) as $l) {
                if (is_array($l) && ($row = self::summarizeList($l)) !== null) {
                    $out[] = $row;
                }
            }
            $url = self::nextPage($data);
        }
        return $this->listCache = $out;
    }

    /**
     * Tâches d'une liste. `$statut` ∈ ''|a_faire|terminee|toutes (défaut :
     * à faire). Fenêtre d'échéance optionnelle : quand elle s'applique, les
     * tâches SANS échéance sont écartées (annoncé au modèle).
     * Retour : `{liste, liste_nom, taches[], filtre_echeance}`.
     * Throw RuntimeException à message FR.
     */
    public function listTasks(string $listRef, string $statut, string $from, string $to, int $limit = 20): array
    {
        $list   = $this->resolveList($listRef);
        $limit  = max(1, min(100, $limit));
        $statut = self::normalizeStatusFilter($statut);
        $window = self::dueWindow($from, $to);

        $rows = [];
        $url  = self::TODO . '/lists/' . rawurlencode($list['id']) . '/tasks?$top=' . self::PAGE_SIZE;
        for ($page = 0; $page < self::MAX_PAGES && $url !== ''; $page++) {
            $data = $this->apiJson('GET', $url);
            foreach ((array)($data['value'] ?? []) as $t) {
                if (!is_array($t) || (string)($t['id'] ?? '') === '') {
                    continue;
                }
                $done = (string)($t['status'] ?? '') === 'completed';
                if (($statut === 'a_faire' && $done) || ($statut === 'terminee' && !$done)) {
                    continue;
                }
                if ($window !== null && !self::inWindow(self::dueDate($t['dueDateTime'] ?? null, $this->timeZone), $window)) {
                    continue;
                }
                $rows[] = $t;
            }
            $url = self::nextPage($data);
        }

        $out = [];
        foreach (self::sortTasks($rows, $this->timeZone) as $t) {
            $out[] = self::summarizeTask($t, $list['id'], $this->timeZone);
            if (count($out) >= $limit) {
                break;
            }
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
     * Une tâche complète (notes intégrales tronquées au budget, étapes,
     * rappel, importance). Throw RuntimeException à message FR.
     */
    public function readTask(string $taskRef, string $listRef, int $tokenBudget): array
    {
        [$listPart, $taskId] = self::splitTaskRef($taskRef, $listRef);
        $list = $this->resolveList($listPart);
        $base = self::TODO . '/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode($taskId);
        $t    = $this->apiJson('GET', $base);

        $notes     = self::notesText($t);
        $maxChars  = max(1000, $tokenBudget * 4);
        $truncated = mb_strlen($notes) > $maxChars;
        if ($truncated) {
            $notes = mb_substr($notes, 0, $maxChars) . "\n[… contenu tronqué]";
        }

        // Étapes (checklist) : best-effort — une lecture qui échoue ne doit pas
        // priver le modèle de la tâche elle-même.
        $etapes = [];
        try {
            $items = $this->apiJson('GET', $base . '/checklistItems');
            foreach (array_slice((array)($items['value'] ?? []), 0, 50) as $it) {
                if (is_array($it) && trim((string)($it['displayName'] ?? '')) !== '') {
                    $etapes[] = (!empty($it['isChecked']) ? '[x] ' : '[ ] ') . trim((string)$it['displayName']);
                }
            }
        } catch (\RuntimeException) {
            // Étapes illisibles : la tâche reste lisible.
        }

        return self::summarizeTask($t, $list['id'], $this->timeZone) + [
            'liste_nom' => $list['nom'],
            'notes'     => $notes,
            'etapes'    => $etapes,
            'tronque'   => $truncated,
        ];
    }

    // ===================== Écriture (file d'approbation uniquement) =====================

    /**
     * Crée une tâche. Appelé UNIQUEMENT depuis la file d'approbation, au clic
     * « Approuver ».
     *
     * @return array{id:string,titre:string,liste:string,liste_nom:string,echeance:string}
     */
    public function createTask(array $args): array
    {
        $this->assertWritable();
        $list    = $this->resolveList((string)($args['liste'] ?? ''), true);
        $payload = self::buildTaskPayload($args);

        $t = $this->apiJson('POST', self::TODO . '/lists/' . rawurlencode($list['id']) . '/tasks', $payload);
        if ((string)($t['id'] ?? '') === '') {
            throw new \RuntimeException('Création de la tâche refusée par Microsoft To Do.');
        }
        error_log(self::LOG_LABEL . ' tâche créée id=' . (string)$t['id']
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return $this->writeResult($t, $list);
    }

    /**
     * Modifie une tâche existante (titre, notes, échéance, rappel, importance,
     * statut). Champs absents = inchangés ; « aucune » retire l'échéance ou le
     * rappel.
     *
     * @return array{id:string,titre:string,liste:string,liste_nom:string,echeance:string}
     */
    public function updateTask(array $args): array
    {
        $this->assertWritable();
        [$listPart, $taskId] = self::splitTaskRef((string)($args['tache'] ?? ''), (string)($args['liste'] ?? ''));
        $list  = $this->resolveList($listPart, true);
        $patch = self::buildTaskPatch($args);
        if ($patch === []) {
            throw new \RuntimeException('Aucune modification demandée (titre, notes, échéance, rappel, importance ou statut).');
        }

        $t = $this->apiJson(
            'PATCH',
            self::TODO . '/lists/' . rawurlencode($list['id']) . '/tasks/' . rawurlencode($taskId),
            $patch
        );
        if ((string)($t['id'] ?? '') === '') {
            throw new \RuntimeException('Modification de la tâche refusée par Microsoft To Do.');
        }
        error_log(self::LOG_LABEL . ' tâche modifiée id=' . $taskId
            . ($this->accountEmail !== '' ? ' compte=' . $this->accountEmail : ''));

        return $this->writeResult($t, $list);
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
            'id'        => self::composeTaskRef($list['id'], (string)$t['id']),
            'titre'     => trim((string)($t['title'] ?? '')) ?: '(sans titre)',
            'liste'     => $list['id'],
            'liste_nom' => $list['nom'],
            'echeance'  => self::formatDue($t['dueDateTime'] ?? null, $this->timeZone),
        ];
    }

    /**
     * Référence de liste (id, nom, vide) → `{id, nom}`. Vide → la liste par
     * défaut du compte (« Tâches »), lue sur Graph : elle n'a pas d'alias
     * comme le `@default` de Google. Un nom est résolu sans casse ni accents.
     * `$forWrite` refuse la liste des emails marqués (piège 3).
     *
     * @return array{id:string,nom:string}
     */
    private function resolveList(string $ref, bool $forWrite = false): array
    {
        $ref   = self::normalizeListRef($ref);
        $lists = $this->listTaskLists();

        $found = null;
        if ($ref === '') {
            foreach ($lists as $l) {
                if ($l['defaut']) {
                    $found = $l;
                    break;
                }
            }
            // Aucun marquage « liste par défaut » rendu : la première liste
            // possédée tient lieu de liste par défaut plutôt qu'un refus.
            $found ??= $lists[0] ?? null;
            if ($found === null) {
                throw new \RuntimeException('Aucune liste Microsoft To Do sur ce compte.');
            }
        } else {
            foreach ($lists as $l) {
                if ($l['id'] === $ref) {
                    $found = $l;
                    break;
                }
            }
            if ($found === null) {
                $fold = UserOutlookCalendarService::foldText($ref);
                foreach ($lists as $l) {
                    if (UserOutlookCalendarService::foldText($l['nom']) === $fold) {
                        $found = $l;
                        break;
                    }
                }
            }
            if ($found === null) {
                throw new \RuntimeException('Liste Microsoft To Do introuvable : « ' . $ref
                    . ' » — utilise lister_listes_todo d\'abord.');
            }
        }
        if ($forWrite && $found['lecture_seule']) {
            throw new \RuntimeException(self::MSG_FLAGGED_READONLY);
        }
        return ['id' => $found['id'], 'nom' => $found['nom']];
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /** Ligne de liste Graph → `{id, nom, defaut, partagee, lecture_seule}`, null si inexploitable. */
    public static function summarizeList(array $l): ?array
    {
        $id = (string)($l['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $wellknown = (string)($l['wellknownListName'] ?? '');
        return [
            'id'            => $id,
            'nom'           => trim((string)($l['displayName'] ?? '')) ?: '(sans nom)',
            'defaut'        => $wellknown === self::WELLKNOWN_DEFAULT,
            'partagee'      => !empty($l['isShared']),
            'lecture_seule' => $wellknown === self::WELLKNOWN_FLAGGED,
        ];
    }

    /** Lien de page suivante, seulement s'il vise Graph (piège 5). */
    public static function nextPage(array $data): string
    {
        $next = (string)($data['@odata.nextLink'] ?? '');
        return str_starts_with($next, self::GRAPH_BASE . '/') ? $next : '';
    }

    /** Identifiant composé rendu au modèle (piège 1). */
    public static function composeTaskRef(string $listId, string $taskId): string
    {
        return $listId . self::REF_SEPARATOR . $taskId;
    }

    /**
     * Identifiant donné par le modèle → [référence de liste, id de tâche].
     * Composé (`liste~tâche`) : la liste qu'il porte prime ; nu : la liste
     * passée à part (vide = liste par défaut). Throw si l'id est implausible.
     *
     * @return array{0:string,1:string}
     */
    public static function splitTaskRef(string $ref, string $listRef = ''): array
    {
        $ref = trim($ref);
        $pos = strrpos($ref, self::REF_SEPARATOR);
        if ($pos !== false) {
            $listRef = substr($ref, 0, $pos);
            $ref     = substr($ref, $pos + 1);
        }
        return [self::normalizeListRef($listRef), self::assertTaskId($ref)];
    }

    /** Référence de liste nettoyée ; vide ou « principale » → '' (liste par défaut). */
    public static function normalizeListRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || in_array(mb_strtolower($ref), ['principale', 'default', 'defaut', 'défaut'], true)) {
            return '';
        }
        if (mb_strlen($ref) > 300) {
            throw new \RuntimeException('Identifiant de liste Microsoft To Do invalide (trop long).');
        }
        return $ref;
    }

    /** Identifiant de tâche plausible (alphabet base64/base64url de Graph), sinon throw. */
    public static function assertTaskId(string $id): string
    {
        $id = trim($id);
        if (preg_match('#^[A-Za-z0-9_\-=+/]{5,400}$#', $id) !== 1) {
            throw new \RuntimeException('Identifiant de tâche invalide — utilise lister_taches_todo d\'abord.');
        }
        return $id;
    }

    /** Le modèle demande-t-il le RETRAIT de ce champ ? (« aucune », « sans », « - ») */
    public static function asksClear(array $args, string $key): bool
    {
        if (!array_key_exists($key, $args)) {
            return false;
        }
        return in_array(mb_strtolower(trim((string)$args[$key])), ['aucune', 'aucun', 'sans', 'none', '-'], true);
    }

    /**
     * Échéance saisie → jour `AAAA-MM-JJ`. To Do ne retient qu'un JOUR : une
     * heure fournie est ignorée (le rappel, lui, porte une heure). Vide → null,
     * illisible → throw.
     */
    public static function parseDueDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $raw, $m) === 1) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], new \DateTimeZone('UTC'));
            if ($d !== false && $d->format('Y-m-d') === $m[1]) {
                return $m[1];
            }
        }
        throw new \RuntimeException('Échéance invalide : « ' . $raw . ' » (format attendu : AAAA-MM-JJ).');
    }

    /**
     * Rappel saisi (`AAAA-MM-JJ HH:MM`) → instant dans le fuseau de
     * l'utilisateur. Un rappel SANS heure n'a pas de sens : throw. Vide → null.
     */
    public static function parseReminder(string $raw, string $tzName): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $raw) === 1) {
            $tz = new \DateTimeZone($tzName !== '' ? $tzName : date_default_timezone_get());
            $d  = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', $raw), $tz);
            if ($d !== false && $d->format('Y-m-d H:i') === str_replace('T', ' ', $raw)) {
                return $d;
            }
        }
        throw new \RuntimeException('Rappel invalide : « ' . $raw . ' » (format attendu : AAAA-MM-JJ HH:MM).');
    }

    /** Statut du modèle → statut Graph, ou throw. */
    public static function normalizeStatus(string $raw): string
    {
        return match (mb_strtolower(trim($raw))) {
            'terminee', 'terminée', 'completed', 'faite', 'fait' => 'completed',
            'en_cours', 'en cours', 'inprogress'                 => 'inProgress',
            'a_faire', 'à faire', 'a faire', 'notstarted', 'todo' => 'notStarted',
            default => throw new \RuntimeException('Statut invalide : attendu « a_faire », « en_cours » ou « terminee ».'),
        };
    }

    /** Filtre de statut d'un listing ∈ a_faire|terminee|toutes (défaut : à faire). */
    public static function normalizeStatusFilter(string $raw): string
    {
        $raw = mb_strtolower(trim($raw));
        if (in_array($raw, ['terminee', 'terminée', 'completed', 'faites'], true)) {
            return 'terminee';
        }
        if (in_array($raw, ['toutes', 'tout', 'all'], true)) {
            return 'toutes';
        }
        return 'a_faire';
    }

    /**
     * Fenêtre d'échéance `[min, max]` en jours `AAAA-MM-JJ` (bornes incluses),
     * ou null si aucune borne n'est demandée. Une seule borne : l'autre
     * s'ouvre. Throw si fin < début.
     *
     * @return array{0:string,1:string}|null
     */
    public static function dueWindow(string $from, string $to): ?array
    {
        $min = self::parseDueDate($from);
        $max = self::parseDueDate($to);
        if ($min === null && $max === null) {
            return null;
        }
        $min ??= '0000-01-01';
        $max ??= '9999-12-31';
        if ($max < $min) {
            throw new \RuntimeException('La date de fin doit être postérieure ou égale à la date de début.');
        }
        return [$min, $max];
    }

    /** Une échéance (jour ou null) tombe-t-elle dans la fenêtre ? Sans échéance : non. */
    public static function inWindow(?string $day, array $window): bool
    {
        return $day !== null && $day >= $window[0] && $day <= $window[1];
    }

    /**
     * Jour d'échéance d'un couple Graph `{dateTime, timeZone}` (piège 2) :
     * minuit → le jour tel quel ; autre heure (échéance convertie en UTC par
     * le client qui l'a posée) → le jour dans le fuseau de l'utilisateur.
     */
    public static function dueDate(mixed $node, string $timeZone = ''): ?string
    {
        if (!is_array($node)) {
            return null;
        }
        $brut = trim((string)($node['dateTime'] ?? ''));
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})/', $brut, $m) !== 1) {
            return null;
        }
        if ($m[2] === '00' && $m[3] === '00') {
            return $m[1];
        }
        $local = UserOutlookCalendarService::parseBoundary($node, $timeZone);
        return $local?->format('Y-m-d');
    }

    /** Échéance Graph → « vendredi 07/08/2026 », vide sans échéance. */
    public static function formatDue(mixed $node, string $timeZone = ''): string
    {
        $day = self::dueDate($node, $timeZone);
        if ($day === null) {
            return '';
        }
        $d = new \DateTimeImmutable($day);
        return self::DAYS_FR[(int)$d->format('N')] . ' ' . $d->format('d/m/Y');
    }

    /** Rappel Graph → « vendredi 07/08/2026 09:00 » dans le fuseau de l'utilisateur, vide sinon. */
    public static function formatReminder(array $t, string $timeZone = ''): string
    {
        if (empty($t['isReminderOn'])) {
            return '';
        }
        $local = UserOutlookCalendarService::parseBoundary($t['reminderDateTime'] ?? null, $timeZone);
        return $local === null ? '' : self::DAYS_FR[(int)$local->format('N')] . ' ' . $local->format('d/m/Y H:i');
    }

    /** Notes d'une tâche (corps Text ou HTML) → texte. */
    public static function notesText(array $t): string
    {
        return UserOutlookCalendarService::plainText(
            (string)($t['body']['content'] ?? ''),
            (string)($t['body']['contentType'] ?? 'text')
        );
    }

    /**
     * Ordre de présentation : à faire avant terminées, puis échéance la plus
     * proche (sans échéance en dernier), puis titre. Graph ne garantit aucun
     * ordre utile, et `$orderby` n'est pas accepté partout : tri en PHP.
     */
    public static function sortTasks(array $tasks, string $timeZone = ''): array
    {
        usort($tasks, static function (array $a, array $b) use ($timeZone): int {
            $doneA = (string)($a['status'] ?? '') === 'completed';
            $doneB = (string)($b['status'] ?? '') === 'completed';
            if ($doneA !== $doneB) {
                return $doneA ? 1 : -1;
            }
            $dueA = self::dueDate($a['dueDateTime'] ?? null, $timeZone) ?? '9999-99-99';
            $dueB = self::dueDate($b['dueDateTime'] ?? null, $timeZone) ?? '9999-99-99';
            if ($dueA !== $dueB) {
                return strcmp($dueA, $dueB);
            }
            return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });
        return $tasks;
    }

    /**
     * Arguments de `creer_tache_todo` → corps du POST. Échéance à minuit DANS
     * le fuseau de l'utilisateur (piège 2), notes en texte brut (jamais de HTML
     * fabriqué depuis le texte du modèle). Throw à message FR sur entrée invalide.
     */
    public static function buildTaskPayload(array $args): array
    {
        $titre = trim((string)($args['titre'] ?? ''));
        if ($titre === '') {
            throw new \RuntimeException('Titre de la tâche manquant.');
        }
        $tzName  = UserCalendarService::argTimeZone($args);
        $payload = ['title' => mb_substr($titre, 0, self::MAX_TITLE_LEN)];

        $notes = trim((string)($args['notes'] ?? ''));
        if ($notes !== '') {
            $payload['body'] = ['content' => mb_substr($notes, 0, self::MAX_NOTES_LEN), 'contentType' => 'text'];
        }
        if (!self::asksClear($args, 'echeance')) {
            $due = self::parseDueDate((string)($args['echeance'] ?? ''));
            if ($due !== null) {
                $payload['dueDateTime'] = ['dateTime' => $due . 'T00:00:00', 'timeZone' => $tzName];
            }
        }
        if (!self::asksClear($args, 'rappel')) {
            $rappel = self::parseReminder((string)($args['rappel'] ?? ''), $tzName);
            if ($rappel !== null) {
                $payload['isReminderOn']     = true;
                $payload['reminderDateTime'] = ['dateTime' => $rappel->format('Y-m-d\TH:i:s'), 'timeZone' => $tzName];
            }
        }
        if (array_key_exists('importante', $args) && self::toBool($args['importante'])) {
            $payload['importance'] = 'high';
        }
        $statut = trim((string)($args['statut'] ?? ''));
        if ($statut !== '') {
            $payload['status'] = self::normalizeStatus($statut);
        }
        return $payload;
    }

    /**
     * Arguments de `modifier_tache_todo` → corps du PATCH : uniquement les
     * champs réellement fournis. Retirer l'échéance = `dueDateTime: null`,
     * retirer le rappel = `isReminderOn: false`.
     */
    public static function buildTaskPatch(array $args): array
    {
        $tzName = UserCalendarService::argTimeZone($args);
        $patch  = [];

        if (array_key_exists('titre', $args) && trim((string)$args['titre']) !== '') {
            $patch['title'] = mb_substr(trim((string)$args['titre']), 0, self::MAX_TITLE_LEN);
        }
        if (array_key_exists('notes', $args)) {
            $patch['body'] = ['content' => mb_substr(trim((string)$args['notes']), 0, self::MAX_NOTES_LEN), 'contentType' => 'text'];
        }
        if (self::asksClear($args, 'echeance')) {
            $patch['dueDateTime'] = null;
        } elseif (array_key_exists('echeance', $args)) {
            $due = self::parseDueDate((string)$args['echeance']);
            if ($due !== null) {
                $patch['dueDateTime'] = ['dateTime' => $due . 'T00:00:00', 'timeZone' => $tzName];
            }
        }
        if (self::asksClear($args, 'rappel')) {
            $patch['isReminderOn'] = false;
        } elseif (array_key_exists('rappel', $args)) {
            $rappel = self::parseReminder((string)$args['rappel'], $tzName);
            if ($rappel !== null) {
                $patch['isReminderOn']     = true;
                $patch['reminderDateTime'] = ['dateTime' => $rappel->format('Y-m-d\TH:i:s'), 'timeZone' => $tzName];
            }
        }
        if (self::hasValue($args, 'importante')) {
            $patch['importance'] = self::toBool($args['importante']) ? 'high' : 'normal';
        }
        if (array_key_exists('statut', $args) && trim((string)$args['statut']) !== '') {
            $patch['status'] = self::normalizeStatus((string)$args['statut']);
        }
        return $patch;
    }

    /** Ligne de tâche Graph → résumé FR stable (id composé, cf. piège 1). */
    public static function summarizeTask(array $t, string $listId, string $timeZone = ''): array
    {
        $notes  = self::notesText($t);
        $status = (string)($t['status'] ?? '');
        return [
            'id'         => self::composeTaskRef($listId, (string)($t['id'] ?? '')),
            'liste'      => $listId,
            'titre'      => trim((string)($t['title'] ?? '')) ?: '(sans titre)',
            'statut'     => match ($status) {
                'completed'  => 'terminee',
                'inProgress' => 'en_cours',
                default      => 'a_faire',
            },
            'echeance'   => self::formatDue($t['dueDateTime'] ?? null, $timeZone),
            'rappel'     => self::formatReminder($t, $timeZone),
            'importante' => (string)($t['importance'] ?? '') === 'high',
            'apercu'     => $notes === '' ? '' : (mb_strlen($notes) > 200 ? mb_substr($notes, 0, 200) . '…' : $notes),
        ];
    }

    /**
     * Aperçu HUMAIN d'une écriture pour la carte d'approbation (mêmes clés que
     * UserTasksService::actionPreview). `$ctx` porte le nom de la liste
     * résolue et le titre actuel de la tâche visée, quand ils sont connus.
     *
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string}
     */
    public static function actionPreview(string $tool, array $args, array $ctx = []): array
    {
        $titre    = trim((string)($ctx['titre'] ?? $args['titre'] ?? '')) ?: t('(sans titre)');
        $liste    = trim((string)($ctx['liste_nom'] ?? '')) ?: t('liste par défaut');
        $echeance = self::asksClear($args, 'echeance') ? '' : trim((string)($args['echeance'] ?? ''));
        $rappel   = self::asksClear($args, 'rappel') ? '' : trim((string)($args['rappel'] ?? ''));
        $notes    = trim((string)($args['notes'] ?? ''));
        $fuseau   = UserCalendarService::validTimeZone((string)($args['fuseau'] ?? ''));

        $impacts = [];
        if ($tool === 'modifier_tache_todo') {
            $action = t('Modifier une tâche Microsoft To Do');
            $champs = [];
            if (trim((string)($args['titre'] ?? '')) !== '')      { $champs[] = t('titre'); }
            if (array_key_exists('notes', $args))                 { $champs[] = t('notes'); }
            if (array_key_exists('echeance', $args))              { $champs[] = t('échéance'); }
            if (array_key_exists('rappel', $args))                { $champs[] = t('rappel'); }
            if (self::hasValue($args, 'importante'))             { $champs[] = t('importance'); }
            if (trim((string)($args['statut'] ?? '')) !== '')     { $champs[] = t('statut'); }
            $impacts[] = t('Champs modifiés : {champs}.', ['champs' => $champs !== [] ? implode(', ', $champs) : t('aucun')]);
            $impacts[] = t('Liste « {liste} ». La version précédente n\'est pas conservée.', ['liste' => $liste]);
        } else {
            $action    = t('Créer une tâche Microsoft To Do');
            $impacts[] = t('Une tâche sera ajoutée à la liste « {liste} ».', ['liste' => $liste]);
            if ($rappel !== '') {
                $impacts[] = t('Rappel : {date}{fuseau} — Microsoft To Do vous préviendra à cette heure.', [
                    'date'   => $rappel,
                    'fuseau' => $fuseau !== '' ? ' (' . $fuseau . ')' : '',
                ]);
            }
            $impacts[] = t('Vous pourrez la modifier ou la supprimer à tout moment dans Microsoft To Do.');
        }

        return [
            'action'  => $action,
            'fichier' => $titre . ($echeance !== '' ? ' — ' . $echeance : ''),
            'url'     => null,
            'impacts' => $impacts,
            'apercu'  => $notes === '' ? null : (mb_strlen($notes) > 280 ? mb_substr($notes, 0, 280) . '…' : $notes),
            'icone'   => '/assets/img/microsoft-todo.svg',
        ];
    }

    /** Champ fourni avec une valeur (un booléen `false` en est une, une chaîne vide non). */
    private static function hasValue(array $args, string $key): bool
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            return false;
        }
        return is_bool($args[$key]) || trim((string)$args[$key]) !== '';
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(mb_strtolower(trim((string)$v)), ['1', 'true', 'on', 'oui', 'yes', 'haute', 'high'], true);
    }
}
