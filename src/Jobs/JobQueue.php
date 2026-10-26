<?php

declare(strict_types=1);

/**
 * File de jobs asynchrones (MySQL, sans broker).
 *
 * Trois chemins d'exécution, du plus souhaitable au plus dégradé — le job
 * n'est JAMAIS perdu, seule sa latence change :
 *   1. worker dédié (`scripts/worker.php`, conteneur `worker`) : boucle et
 *      réclame les jobs en attente ;
 *   2. pas de worker vivant mais fork POSIX possible → `scripts/run_job.php` ;
 *   3. ni l'un ni l'autre (Windows / `exec` désactivé) → exécution en ligne
 *      APRÈS envoi de la réponse HTTP.
 * La réclamation étant atomique (`FOR UPDATE OF j SKIP LOCKED`), déclencher les
 * trois en même temps ne peut pas exécuter un job deux fois.
 *
 * Plusieurs workers tournent en parallèle (`MIA_WORKERS` du compose). Ce que le
 * worker unique garantissait sans le dire — un job à la fois, dans l'ordre —
 * est désormais explicite, porté par chaque job dès l'enfilage :
 *   - `priority` (priorityFor) : qui attend une réponse passe avant une tâche
 *     programmée, qui passe avant la maintenance ;
 *   - `lane` (laneFor) : couloir sériel. Les tours d'une même conversation
 *     (chat, image, page web) s'exécutent un par un, dans l'ordre — chacun lit
 *     le précédent ; la maintenance aussi, une seule à la fois — il reste donc
 *     toujours des workers pour les conversations, et une réindexation ne
 *     tourne jamais sous les pieds d'une ingestion de la même base.
 * La règle de couloir (LANE_FREE_SQL) est la même pour les trois chemins.
 */
class JobQueue
{
    /** Un worker plus vieux que ça est considéré mort → on déclenche nous-même. */
    private const WORKER_STALE_SEC = 45;
    /** Un job `running` sans heartbeat depuis ça est repris/échoué (crash worker). */
    private const STALE_JOB_SEC = 120;
    /** Rejeu impossible sans double facturation → un seul essai pour ces types. */
    private const NON_IDEMPOTENT = ['html', 'image', 'video', 'presentation', 'chat', 'native_deck', 'workflow', 'map'];
    /**
     * Jobs qui ne consomment AUCUN crédit MIA (suivi d'un service externe déjà
     * facturé) : le quota ne doit pas les bloquer — il ferait perdre un
     * résultat déjà payé ailleurs (ex. génération Gamma partie à l'approbation).
     * `credits_refresh` est une tâche d'administration : rien n'est journalisé
     * en consommation, la bloquer sur le quota personnel de l'admin n'aurait
     * aucun sens. `mybrain_feed` suit un dépôt au cerveau MyBrain déjà fait :
     * aucun crédit MIA en jeu, et le bloquer ferait perdre son issue.
     */
    private const QUOTA_EXEMPT = ['gamma', 'credits_refresh', 'mybrain_feed'];
    private const MAX_ATTEMPTS = 2;
    /** Purge du flux rejouable une fois le job terminé depuis un moment. */
    private const EVENTS_TTL_MIN = 60;
    /** Rétention des jobs terminés (historique / diagnostic). */
    private const JOBS_TTL_DAYS = 30;

    private const HEARTBEAT_KEY   = 'job_worker_heartbeat_at';
    private const MAINTENANCE_KEY = 'job_maintenance_at';

    /** Version du code que sert l'app web (posée par publishCodeVersion, lue par le worker). */
    private const CODE_VERSION_KEY = 'app_code_version';

    /** Ordre de réclamation : plus petit = d'abord. */
    public const PRIORITY_INTERACTIVE = 10;  // quelqu'un attend devant l'écran
    public const PRIORITY_SCHEDULED   = 50;  // tâche programmée partie seule
    public const PRIORITY_MAINTENANCE = 90;  // ingestion, synchronisation, réindexation

    /** Couloir de la maintenance : une seule à la fois, quel que soit le nombre de workers. */
    public const LANE_MAINTENANCE = 'maintenance';
    /**
     * Types qui écrivent un tour de conversation que le tour suivant relit
     * (historique, dernière image, dernière page) : sérialisés par conversation.
     * Vidéo, présentation, Gamma, MyBrain restent libres — longs et autonomes,
     * ils ne doivent pas retenir la question suivante pendant des minutes.
     */
    public const CONVERSATION_LANE_TYPES = ['chat', 'image', 'html', 'workflow', 'map'];

    /**
     * Un job ne part que si son couloir est libre : aucun autre job du même
     * couloir en cours, ni en attente arrivé AVANT lui. La seconde condition
     * n'est pas une coquetterie : deux workers qui réclament en même temps
     * verraient tous deux le couloir « sans job en cours » (le premier n'a pas
     * encore validé sa réclamation) et lanceraient les deux tours d'une même
     * conversation à la fois. Avec elle, le second voit le premier, encore
     * « en attente » et plus ancien, et passe son tour.
     * Alias imposés : `j` = le candidat, `o` = les autres jobs.
     */
    private const LANE_FREE_SQL = "(j.lane IS NULL OR NOT EXISTS (
        SELECT 1 FROM async_jobs o
         WHERE o.lane = j.lane AND o.id <> j.id
           AND (o.status = 'running' OR (o.status = 'pending' AND o.id < j.id))))";
    /** Chemin fork / en ligne : attente maximale d'un couloir occupé avant d'abandonner au worker. */
    private const LANE_WAIT_SEC = 900;

    /** Clé de la charge utile qui porte la langue de l'écran ayant lancé le job. */
    public const LOCALE_KEY = 'ui_locale';

    /**
     * Langue d'exécution d'un job : celle de l'écran qui l'a lancé (charge
     * utile), sinon celle du compte (I18n::forUser). Pure à base près.
     *
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>      $payload
     */
    public static function localeFor(?array $user, array $payload): string
    {
        $demandee = strtolower(trim((string)($payload[self::LOCALE_KEY] ?? '')));
        return I18n::isSupported($demandee) ? $demandee : I18n::forUser($user);
    }

    // ---------------------------------------------------------------- Enfilage

    public static function enqueue(
        string $type,
        ?int $userId,
        ?int $conversationId,
        array $payload,
        string $label = '',
        ?string $dedupKey = null
    ): int {
        $db = Database::getInstance();

        // Tâche identique déjà en file ou en cours : on ne l'y remet pas.
        // Sans ça, dix requêtes concurrentes enfileraient dix synchronisations
        // de la même base (le verrou MySQL les sérialiserait ensuite pour rien).
        if ($dedupKey !== null) {
            $sel = $db->prepare(
                "SELECT id FROM async_jobs
                 WHERE dedup_key = ? AND status IN ('pending','running') LIMIT 1"
            );
            $sel->execute([$dedupKey]);
            $existing = $sel->fetchColumn();
            if ($existing) {
                return (int)$existing;
            }
        }

        // Langue de l'écran qui a lancé le job : la réponse, le titre et les
        // messages du job doivent parler la langue que la personne voit — y
        // compris quand elle vient du navigateur (aucun choix enregistré), que
        // le worker ne connaît pas. Jobs enfilés hors requête (planificateur,
        // suite d'un workflow) : rien, la langue du compte s'applique.
        if ($userId !== null && $userId > 0 && PHP_SAPI !== 'cli' && !isset($payload[self::LOCALE_KEY])) {
            $payload[self::LOCALE_KEY] = I18n::locale();
        }

        $stmt = $db->prepare(
            'INSERT INTO async_jobs (user_id, conversation_id, type, priority, lane, dedup_key, label, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $conversationId,
            $type,
            self::priorityFor($type, $payload),
            self::laneFor($type, $conversationId),
            $dedupKey,
            mb_substr($label, 0, 120),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Ordre de réclamation d'un job (pure). Une tâche programmée lancée par
     * « Exécuter maintenant » ou un essai (`manual`) a quelqu'un devant
     * l'écran : elle est interactive, pas programmée.
     */
    public static function priorityFor(string $type, array $payload = []): int
    {
        if ($type === 'maintenance') {
            return self::PRIORITY_MAINTENANCE;
        }
        $scheduled = $payload['scheduled'] ?? null;
        if (is_array($scheduled) && $scheduled !== [] && empty($scheduled['manual'])) {
            return self::PRIORITY_SCHEDULED;
        }
        // Étape d'un workflow parti seul (départ programmé) : même rang.
        $workflow = $payload['workflow'] ?? null;
        if (is_array($workflow) && !empty($workflow['scheduled'])) {
            return self::PRIORITY_SCHEDULED;
        }
        return self::PRIORITY_INTERACTIVE;
    }

    /** Couloir sériel d'un job, null = aucun (pure). Cf. LANE_FREE_SQL. */
    public static function laneFor(string $type, ?int $conversationId): ?string
    {
        if ($type === 'maintenance') {
            return self::LANE_MAINTENANCE;
        }
        if ($conversationId !== null && $conversationId > 0
            && in_array($type, self::CONVERSATION_LANE_TYPES, true)) {
            return 'conv:' . $conversationId;
        }
        return null;
    }

    /**
     * Enfile une tâche système (hors chat) et la déclenche.
     *
     * `$allowInline = false` interdit le repli « exécution dans la requête
     * courante » : une synchronisation de 100 fichiers n'a rien à faire dans
     * une requête utilisateur. Le job reste alors en attente et sera pris par
     * le worker (ou dispatché plus tard par `maybeMaintain`).
     */
    public static function enqueueMaintenance(
        string $op,
        int $targetId,
        string $label = '',
        bool $allowInline = true
    ): int {
        $jobId = self::enqueue(
            'maintenance',
            null,
            null,
            ['op' => $op, 'id' => $targetId],
            $label !== '' ? $label : ($op . ' #' . $targetId),
            $op . ':' . $targetId
        );
        self::dispatch($jobId, $allowInline);
        return $jobId;
    }

    /** Une exécution hors requête est-elle possible ici et maintenant ? */
    public static function canRunOutOfBand(): bool
    {
        return self::workerAlive() || BackgroundJob::canSpawn();
    }

    /**
     * Renvoie la réponse HTTP au client PUIS déclenche l'exécution. L'ordre
     * compte : dans le mode dégradé (3), l'exécution se fait dans cette même
     * requête — sans avoir refermé la réponse, le navigateur attendrait la fin
     * du job avant de pouvoir s'attacher au flux.
     */
    public static function respondAndDispatch(array $responsePayload, int $jobId): void
    {
        $body = json_encode($responsePayload, JSON_UNESCAPED_UNICODE);
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Content-Length: ' . strlen((string)$body));
            header('Connection: close');
        }
        echo $body;
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();

        self::dispatch($jobId);
    }

    /** Choisit le chemin d'exécution (cf. en-tête de classe). */
    public static function dispatch(int $jobId, bool $allowInline = true): void
    {
        // Le verrou de session bloquerait la requête d'attache au flux qui suit
        // immédiatement côté navigateur.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (self::workerAlive()) {
            return; // le worker dédié prendra le job à sa prochaine boucle
        }

        $script = ROOT . '/scripts/run_job.php';
        if (BackgroundJob::canSpawn()) {
            BackgroundJob::dispatch($script, $jobId);
            return;
        }

        if (!$allowInline) {
            return; // le job reste en attente : il sera pris plus tard
        }

        // Dernier recours : ici même, la réponse est déjà partie.
        @set_time_limit(0);
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        self::runById($jobId);
    }

    // ------------------------------------------------------------- Réclamation

    /** Réclame le plus ancien job en attente. Atomique entre workers concurrents. */
    public static function claimNext(): ?array
    {
        $db = Database::getInstance();
        try {
            $db->beginTransaction();
            // `FOR UPDATE OF j` : seul le candidat est verrouillé. Sans `OF`,
            // la sous-requête de couloir poserait aussi ses verrous (et sauterait
            // les lignes verrouillées par un autre worker — donc ne verrait plus
            // le job en cours de réclamation qu'elle doit justement voir).
            $sel = $db->query(
                "SELECT j.* FROM async_jobs j
                 WHERE j.status = 'pending' AND " . self::LANE_FREE_SQL . "
                 ORDER BY j.priority, j.id LIMIT 1 FOR UPDATE OF j SKIP LOCKED"
            );
            $row = $sel ? $sel->fetch() : null;
            if (!$row) {
                $db->commit();
                return null;
            }
            $db->prepare(
                "UPDATE async_jobs
                 SET status = 'running', started_at = NOW(), heartbeat_at = NOW(), attempts = attempts + 1
                 WHERE id = ?"
            )->execute([(int)$row['id']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[JobQueue] claimNext : ' . $e->getMessage());
            return null;
        }

        $row['status']  = 'running';
        $row['payload'] = json_decode((string)$row['payload_json'], true) ?: [];
        return $row;
    }

    /**
     * Réclame un job précis (chemins 2 et 3). Null si déjà pris par un autre —
     * ou si son couloir est occupé (`$blocked` vaut alors true : le job est
     * toujours en attente, il partira quand le couloir se libérera).
     */
    public static function claimById(int $jobId, ?bool &$blocked = null): ?array
    {
        $blocked = false;
        $db = Database::getInstance();
        try {
            $db->beginTransaction();
            // Pas un UPDATE avec sous-requête : MySQL refuse de lire la table
            // qu'il modifie (erreur 1093). Le candidat est verrouillé, le
            // couloir lu à côté — même règle que claimNext.
            $sel = $db->prepare(
                "SELECT j.id, " . self::LANE_FREE_SQL . " AS lane_free
                   FROM async_jobs j
                  WHERE j.id = ? AND j.status = 'pending'
                  FOR UPDATE OF j"
            );
            $sel->execute([$jobId]);
            $row = $sel->fetch();
            if (!$row) {
                $db->commit();
                return null;
            }
            if (!(int)$row['lane_free']) {
                $db->commit();
                $blocked = true;
                return null;
            }
            $db->prepare(
                "UPDATE async_jobs
                 SET status = 'running', started_at = NOW(), heartbeat_at = NOW(), attempts = attempts + 1
                 WHERE id = ? AND status = 'pending'"
            )->execute([$jobId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[JobQueue] claimById #' . $jobId . ' : ' . $e->getMessage());
            return null;
        }
        return self::find($jobId);
    }

    // -------------------------------------------------------------- Exécution

    /**
     * Chemins fork et en ligne : réclame puis exécute. Couloir de conversation
     * occupé (le tour précédent tourne encore) → on attend qu'il se libère :
     * quelqu'un attend cette réponse, et sans worker personne d'autre ne la
     * lancerait avant le prochain entretien (une minute au mieux). Un worker
     * qui la prend entre-temps met fin à l'attente (le job n'est plus en
     * attente). Une maintenance n'attend pas : elle reste en file, reprise
     * par un worker ou par l'entretien — vingt documents déposés d'un coup ne
     * laissent pas vingt processus en sommeil.
     */
    public static function runById(int $jobId): void
    {
        $deadline = time() + self::LANE_WAIT_SEC;
        while (true) {
            $job = self::claimById($jobId, $blocked);
            if ($job !== null) {
                self::execute($job);
                return;
            }
            if (!$blocked || time() >= $deadline) {
                return;
            }
            if (!str_starts_with((string)(self::find($jobId)['lane'] ?? ''), 'conv:')) {
                return;
            }
            sleep(1);
        }
    }

    /**
     * Exécute `$fn` si aucun autre process ne le fait en ce moment (verrou
     * MySQL non bloquant, relâché à la fin ou à la mort du process). Sert aux
     * corvées périodiques que chaque worker déclenche : avec plusieurs
     * workers, une seule passe à la fois — sans quoi deux reapers pourraient
     * clore le même job orphelin deux fois. @return bool `$fn` exécutée
     */
    public static function exclusively(string $lock, callable $fn): bool
    {
        $db = Database::getInstance();
        $get = $db->prepare('SELECT GET_LOCK(?, 0)');
        $get->execute([$lock]);
        if ((int)$get->fetchColumn() !== 1) {
            return false;
        }
        try {
            $fn();
        } finally {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
        return true;
    }

    /** Exécute un job DÉJÀ réclamé (status = running). */
    public static function execute(array $job): void
    {
        // Le worker est un process long-vivant : sans rechargement, chaque job
        // s'exécuterait avec les réglages du démarrage du process (clé API
        // re-saisie invisible, LLM par défaut périmé). Un SELECT par job,
        // négligeable devant le job lui-même.
        Settings::refresh();
        // Rôles et droits : même raison — un droit retiré pendant que le job
        // attendait en file vaut au moment où il s'exécute.
        Role::forget();

        $jobId  = (int)$job['id'];
        // Job système (maintenance) : aucun utilisateur, donc aucun quota.
        $userId = isset($job['user_id']) && $job['user_id'] !== null ? (int)$job['user_id'] : 0;
        $convId = isset($job['conversation_id']) && $job['conversation_id'] !== null
            ? (int)$job['conversation_id'] : null;
        $payload = $job['payload'] ?? (json_decode((string)$job['payload_json'], true) ?: []);

        $ctx = new JobContext($jobId, $userId, $convId, (int)($job['last_seq'] ?? 0));

        try {
            $handler = self::handlerFor((string)$job['type']);
            if ($handler === null) {
                // Typiquement : worker resté sur une image antérieure au type
                // demandé. « Réessayez » serait trompeur — rien ne changera.
                throw new JobErrorException([
                    'kind'    => 'server',
                    'title'   => 'Génération indisponible',
                    'message' => "Ce type de génération n'est pas pris en charge par le serveur "
                        . "(mise à jour incomplète). Signalez-le à votre administrateur.",
                ], 'type de job inconnu : ' . $job['type']);
            }

            // Le quota a pu basculer pendant l'attente en file.
            $user = $userId > 0 ? User::findById($userId) : null;
            if ($userId > 0 && !$user) {
                throw new \RuntimeException('Utilisateur introuvable');
            }
            // Langue de l'utilisateur DU JOB, reposée à chaque tour : le worker
            // enchaîne les jobs de plusieurs comptes dans le même process, et
            // I18n::$locale est un statique. La poser une seule fois au démarrage
            // rejouerait la panne documentée en tête de Settings.php (cache
            // empoisonné pour la vie du process) — d'où sa place ici, à côté du
            // Settings::refresh() ci-dessus, et non dans _cli_bootstrap.php.
            I18n::use(self::localeFor($user, $payload));
            $quotaStatus = $user !== null ? CreditQuota::status($user) : null;
            if ($quotaStatus !== null
                && !in_array((string)$job['type'], self::QUOTA_EXEMPT, true)
                && CreditQuota::isExceeded($quotaStatus)) {
                $ctx->emit(['quota_exceeded' => true, 'quota_status' => $quotaStatus]);
                // Quota personnel OU budget IA de l'organisation (CreditQuota
                // les distingue) : titre et message suivent la raison.
                self::finish($jobId, 'failed', null, CreditQuota::refusal($quotaStatus, true));
                return;
            }

            $result = $handler->run($payload, $ctx);
            $ctx->flush();

            // La fin du flux (`job_end`) n'est PAS un évènement stocké : elle est
            // synthétisée par la route d'attache à partir du statut. Sinon un job
            // tué net (process abattu) ne la porterait jamais et le client
            // attendrait indéfiniment.
            self::finish($jobId, $ctx->shouldStop() ? 'cancelled' : 'done', $result, null);
        } catch (\Throwable $e) {
            error_log('[JobQueue] job #' . $jobId . ' (' . $job['type'] . ') échoué : ' . $e->getMessage());
            // Cause connue (panne d'API traduite par LlmErrorPresenter) → on la
            // remonte telle quelle ; sinon message générique, jamais le détail
            // technique brut (il peut contenir la clé API).
            $error = ($e instanceof JobErrorException) ? $e->payload() : [
                'kind'    => 'generic',
                'title'   => 'Génération impossible',
                'message' => 'La génération n\'a pas abouti. Réessayez.',
            ];
            // Crédit épuisé, clé refusée (image, page web, présentation…) :
            // consigné pour l'administration, comme depuis le chat.
            ProviderIncident::note($error);
            try {
                // Job déjà clos par le reaper pendant que ce process était
                // muet (bloqué, puis reparti) : l'erreur et le message ⚠️ sont
                // déjà dans le fil — ne pas les doubler.
                if (self::isTerminal((string)(self::liveStatus($jobId)['status'] ?? 'running'))) {
                    return;
                }
                $ctx->flush();
                $ctx->emit(['llm_error' => $error]);
                // Trace dans le fil : sans elle, un échec survenu pendant que
                // l'utilisateur était ailleurs ne laisse RIEN à son retour — il
                // ne voit ni résultat ni explication.
                if ($convId !== null) {
                    // Titre + message : c'est le titre qui nomme la cause
                    // (« Crédit épuisé chez OpenAI »), et c'est tout ce que
                    // lira quelqu'un qui revient après coup.
                    Message::create($convId, 'assistant', LlmErrorPresenter::toPlainText(
                        $error + ['message' => 'Génération interrompue.']
                    ));
                }
                self::finish($jobId, 'failed', null, $error);
            } catch (\Throwable $inner) {
                error_log('[JobQueue] clôture impossible pour #' . $jobId . ' : ' . $inner->getMessage());
            }
        }
    }

    private static function finish(int $jobId, string $status, ?array $result, ?array $error): void
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE async_jobs
             SET status = ?, finished_at = NOW(), progress = ?, result_json = ?, error_json = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $status === 'done' ? 100 : 0,
            $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
            $error  !== null ? json_encode($error,  JSON_UNESCAPED_UNICODE) : null,
            $jobId,
        ]);

        // Exécution d'une tâche programmée : son historique, son compteur
        // d'échecs et la pastille « non lu » suivent l'issue du job. Une seule
        // requête indexée, sans effet pour un job ordinaire ; ne lève jamais.
        ScheduledTaskRun::onJobFinished($jobId, $status, $result, $error);
        // Étape d'un workflow : sa pièce est lue et la brique suivante démarre.
        WorkflowEngine::onJobFinished($jobId, $status, $result, $error);
    }

    private static function handlerFor(string $type): ?JobHandler
    {
        return match ($type) {
            'html'         => new HtmlPageJob(),
            'image'        => new ImageJob(),
            'video'        => new VideoJob(),
            'map'          => new MapJob(),
            'presentation' => new PresentationJob(),
            'chat'         => new ChatSendJob(),
            'gamma'        => new GammaGenerationJob(),
            'native_deck'  => new NativeDeckJob(),
            'mybrain_feed' => new MyBrainFeedJob(),
            'maintenance'  => new MaintenanceJob(),
            'credits_refresh' => new CreditsRefreshJob(),
            'workflow'     => new WorkflowJob(),
            default        => null,
        };
    }

    // ------------------------------------------------------------ Consultation

    public static function find(int $jobId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM async_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['payload'] = json_decode((string)$row['payload_json'], true) ?: [];
        $row['result']  = $row['result_json'] !== null ? (json_decode((string)$row['result_json'], true) ?: []) : null;
        $row['error']   = $row['error_json']  !== null ? (json_decode((string)$row['error_json'],  true) ?: []) : null;
        return $row;
    }

    /** Un job n'est lisible que par son propriétaire. */
    public static function findForUser(int $jobId, int $userId): ?array
    {
        $job = self::find($jobId);
        return ($job !== null && (int)$job['user_id'] === $userId) ? $job : null;
    }

    /**
     * Job encore en file ou en cours portant cette clé de déduplication, ou
     * null — pour REJOINDRE une exécution unique (l'essai d'une tâche
     * programmée) au lieu d'en lancer une seconde.
     *
     * @return array{id: int, conversation_id: ?int}|null
     */
    public static function activeByDedupKey(string $dedupKey): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, conversation_id FROM async_jobs
             WHERE dedup_key = ? AND status IN ('pending','running')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$dedupKey]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'              => (int)$row['id'],
            'conversation_id' => $row['conversation_id'] !== null ? (int)$row['conversation_id'] : null,
        ];
    }

    /** Jobs encore vivants d'un utilisateur — restauration à l'ouverture d'une page. */
    public static function activeForUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, conversation_id, type, label, status, phase, progress, last_seq, created_at
             FROM async_jobs
             WHERE user_id = ? AND status IN ('pending','running')
             ORDER BY id"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    /** Tranche du flux rejouable après le curseur donné. */
    public static function eventsSince(int $jobId, int $seq, int $limit = 200): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT seq, payload FROM job_events WHERE job_id = ? AND seq > ? ORDER BY seq LIMIT ' . (int)$limit
        );
        $stmt->execute([$jobId, $seq]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Flux COMPLET d'un job, charges utiles dans l'ordre — relu par la lecture
     * à voix haute en direct (ChatController::speakLive), qui a besoin de tout
     * le texte déjà produit, pas d'une tranche.
     *
     * @return list<string>
     */
    public static function eventPayloads(int $jobId): array
    {
        $stmt = Database::getInstance()->prepare('SELECT payload FROM job_events WHERE job_id = ? ORDER BY seq');
        $stmt->execute([$jobId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** Statut minimal (le flux SSE le relit en boucle : pas de payload). */
    public static function liveStatus(int $jobId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT status, last_seq FROM async_jobs WHERE id = ?'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, ['done', 'failed', 'cancelled'], true);
    }

    // ------------------------------------------------------------- Annulation

    public static function requestCancel(int $jobId, int $userId): bool
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE async_jobs SET cancel_requested = 1
             WHERE id = ? AND user_id = ? AND status IN ('pending','running')"
        );
        $stmt->execute([$jobId, $userId]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------- Entretien / crash

    /**
     * Le worker signale qu'il est vivant ET sur quelle version il tourne.
     * La version compte autant que l'horodatage : un conteneur resté sur une
     * image périmée (déploiement qui n'a pas reconstruit son image) ignorerait
     * les types de jobs ajoutés depuis, et les ferait tous échouer.
     */
    public static function markWorkerAlive(): void
    {
        Settings::set(self::HEARTBEAT_KEY, time() . '|' . (defined('APP_VERSION') ? APP_VERSION : '?'));
    }

    /**
     * Dernier battement du worker, tel qu'écrit par markWorkerAlive : quand
     * (horodatage PHP) et sur quelle version. Null = jamais vu. Lecture seule
     * (réglage en cache mémoire) : les points à régler de l'administration la
     * lisent à chaque page.
     *
     * @return array{at:int, version:string}|null
     */
    public static function workerHeartbeat(): ?array
    {
        $raw = (string)Settings::get(self::HEARTBEAT_KEY, '');
        if ($raw === '') {
            return null;
        }
        [$ts, $version] = array_pad(explode('|', $raw, 2), 2, '');
        return ['at' => (int)$ts, 'version' => (string)$version];
    }

    /**
     * L'app web publie la version de code qu'elle sert (appelé à chaque
     * requête depuis public/index.php, n'écrit que si la valeur change : un
     * UPDATE par déploiement, pas par requête). `workerAlive()` protège l'app
     * d'un worker périmé, mais rien n'informait le worker LUI-MÊME : resté sur
     * une image antérieure, il continuait de gagner la course au claim et
     * exécutait les jobs sur du vieux code. Avec cette clé, il peut se
     * comparer et cesser de réclamer (cf. workerOutdated). Ne lève jamais.
     */
    public static function publishCodeVersion(): void
    {
        try {
            $version = defined('APP_VERSION') ? (string)APP_VERSION : '';
            if ($version !== '' && (string)Settings::get(self::CODE_VERSION_KEY, '') !== $version) {
                Settings::set(self::CODE_VERSION_KEY, $version);
            }
        } catch (\Throwable $e) {
            error_log('[JobQueue] publication version : ' . $e->getMessage());
        }
    }

    /**
     * Ce process exécute-t-il une version PLUS ANCIENNE que celle servie par
     * l'app ? Consulté par le worker à chaque battement (après
     * Settings::refresh(), sinon il lirait sa propre version publiée au
     * boot) : si oui, il cesse de réclamer des jobs — ils repartent par le
     * fork, qui tourne forcément sur le code courant. Version jamais publiée
     * (première installation, app pas encore sollicitée) = pas d'avis.
     */
    public static function workerOutdated(): bool
    {
        return self::isOutdated(
            (string)Settings::get(self::CODE_VERSION_KEY, ''),
            defined('APP_VERSION') ? (string)APP_VERSION : ''
        );
    }

    /**
     * Le worker en version `$own` est-il périmé face à l'app qui a publié
     * `$published` ? Pure.
     *
     * PLUS RÉCENTE seulement, jamais « différente » : juste après un
     * déploiement, app et worker tournent sur la même image neuve, mais la
     * version publiée est encore l'ANCIENNE tant que l'app n'a rien servi.
     * L'égalité stricte faisait alors croire le worker périmé : il cessait de
     * lancer les tâches programmées jusqu'à ce que quelqu'un ouvre une page.
     * (L'app publie aussi sa version au démarrage de son conteneur — cf.
     * scripts/publish_version.php —, ce qui couvre un retour arrière.)
     * Versions non numériques (développement) : la différence suffit, comme
     * avant.
     */
    public static function isOutdated(string $published, string $own): bool
    {
        if ($published === '' || $own === '' || $published === $own) {
            return false;
        }
        $numeric = static fn(string $v): bool => preg_match('/^\d+(?:\.\d+)*$/', $v) === 1;
        if ($numeric($published) && $numeric($own)) {
            return version_compare($published, $own, '>');
        }
        return true;
    }

    /**
     * Un worker est « vivant » s'il bat ET s'il exécute la MÊME version que
     * cette application. Sinon on le considère absent : les jobs repartent par
     * le fork ou l'exécution en ligne, qui eux tournent forcément sur le code
     * courant. Le système se répare tout seul au lieu d'échouer en silence.
     */
    public static function workerAlive(): bool
    {
        $raw = (string)Settings::get(self::HEARTBEAT_KEY, '');
        if ($raw === '') {
            return false;
        }
        [$ts, $version] = array_pad(explode('|', $raw, 2), 2, '');
        if ((int)$ts <= 0 || (time() - (int)$ts) >= self::WORKER_STALE_SEC) {
            return false;
        }
        $appVersion = defined('APP_VERSION') ? APP_VERSION : '?';
        if ($version !== $appVersion) {
            error_log('[JobQueue] worker en version ' . ($version ?: 'inconnue')
                . ' alors que l\'app est en ' . $appVersion . ' — worker ignoré (image non reconstruite ?)');
            return false;
        }
        return true;
    }

    /**
     * Jobs `running` dont le worker est mort (heartbeat figé) : remis en file
     * s'ils sont rejouables sans effet de bord, échoués sinon — un `html` à
     * moitié fait a déjà pu générer (et facturer) des images.
     *
     * Un job échoué ici est clos EXACTEMENT comme un échec ordinaire
     * (execute) : erreur dans le flux — le navigateur resté attaché affiche la
     * cause au lieu d'un flux qui s'arrête net —, message ⚠️ dans la
     * conversation — sans lui, la personne qui revient ne trouve ni réponse ni
     * explication —, puis la suite d'une clôture (tâche programmée). Le tout
     * dans UNE transaction, ligne verrouillée et condition « en cours et
     * muet » relue : entre la lecture et l'écriture, le job a pu battre de
     * nouveau ou être clos par un autre process, et il ne l'est jamais deux
     * fois.
     */
    public static function reapStale(): int
    {
        $db = Database::getInstance();
        $stillStale = "status = 'running'
               AND (heartbeat_at IS NULL OR heartbeat_at < NOW() - INTERVAL " . self::STALE_JOB_SEC . " SECOND)";
        $stmt = $db->prepare("SELECT id, type, attempts FROM async_jobs WHERE " . $stillStale);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $reaped = 0;
        foreach ($rows as $row) {
            $retryable = !in_array((string)$row['type'], self::NON_IDEMPOTENT, true)
                && (int)$row['attempts'] < self::MAX_ATTEMPTS;
            if ($retryable) {
                $upd = $db->prepare(
                    "UPDATE async_jobs SET status = 'pending', heartbeat_at = NULL WHERE id = ? AND " . $stillStale
                );
                $upd->execute([(int)$row['id']]);
                if ($upd->rowCount() === 1) {
                    $reaped++;
                }
                continue;
            }
            $error = self::failInterrupted((int)$row['id'], $stillStale);
            if ($error !== null) {
                // Même suite qu'une clôture normale (cf. finish()), hors de la
                // transaction : elle peut notifier les appareils.
                ScheduledTaskRun::onJobFinished((int)$row['id'], 'failed', null, $error);
                WorkflowEngine::onJobFinished((int)$row['id'], 'failed', null, $error);
                $reaped++;
            }
        }
        return $reaped;
    }

    /**
     * Clôt en échec un job interrompu côté serveur, s'il l'est toujours.
     * @return array|null l'erreur posée, null si un autre process l'a devancé
     */
    private static function failInterrupted(int $jobId, string $stillStale): ?array
    {
        $db = Database::getInstance();
        $locale = I18n::locale();
        try {
            $db->beginTransaction();
            $sel = $db->prepare(
                "SELECT id, user_id, conversation_id, last_seq FROM async_jobs WHERE id = ? AND " . $stillStale . " FOR UPDATE"
            );
            $sel->execute([$jobId]);
            $job = $sel->fetch();
            if (!$job) {
                $db->commit();
                return null;
            }
            // Le reaper tourne dans le process d'un worker, qui sert tous les
            // comptes : la langue est celle de la personne du job.
            $user = $job['user_id'] !== null ? User::findById((int)$job['user_id']) : null;
            I18n::use(I18n::forUser($user));
            $error = self::interruptedError();

            $db->prepare(
                "UPDATE async_jobs SET status = 'failed', finished_at = NOW(), progress = 0, error_json = ? WHERE id = ?"
            )->execute([json_encode($error, JSON_UNESCAPED_UNICODE), $jobId]);

            // Le flux rejouable reprend après le dernier évènement du worker
            // disparu : statut et évènement sont validés ensemble, la route
            // d'attache délivre donc l'erreur avant de clore le flux.
            $convId = $job['conversation_id'] !== null ? (int)$job['conversation_id'] : null;
            $ctx = new JobContext($jobId, (int)($job['user_id'] ?? 0), $convId, (int)$job['last_seq']);
            $ctx->emit(['llm_error' => $error]);
            if ($convId !== null) {
                Message::create($convId, 'assistant', LlmErrorPresenter::toPlainText($error));
            }
            $db->commit();
            return $error;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[JobQueue] clôture du job interrompu #' . $jobId . ' impossible : ' . $e->getMessage());
            return null;
        } finally {
            I18n::use($locale);
        }
    }

    /** Erreur d'une génération perdue avec son worker (traduite dans la locale courante). */
    public static function interruptedError(): array
    {
        return [
            'kind'    => 'server',
            'title'   => t('Génération interrompue'),
            'message' => t('Le serveur qui menait cette génération s\'est arrêté avant la fin. Relancez votre demande.'),
        ];
    }

    /**
     * Entretien paresseux, déclenché depuis `public/index.php` — même patron
     * que `KbSync::maybeRunDue()`. Ne sert QUE quand aucun worker dédié ne
     * tourne (dev Laragon, déploiement sans conteneur `worker`) : sinon le
     * worker fait déjà le ménage, et on ne paie pas la requête pour rien.
     */
    public static function maybeMaintain(): void
    {
        try {
            if (self::workerAlive()) {
                return;
            }
            $last = (int)Settings::get(self::MAINTENANCE_KEY, 0);
            if ($last > 0 && (time() - $last) < 60) {
                return;
            }
            Settings::set(self::MAINTENANCE_KEY, (string)time());
            self::reapStale();
            self::gcEvents();
            self::dispatchOrphanPending();
            // Tâches programmées : sans worker, leur seul déclencheur. Les jobs
            // qu'il enfile partent par le fork (jamais dans cette requête).
            Scheduler::tick();
            WorkflowEngine::tick();
            WorkflowEngine::maintain();
            ScheduledTaskRun::maintain(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            NativeDeckStore::purgeExpired();
        } catch (\Throwable $e) {
            // L'entretien ne doit jamais casser une requête utilisateur.
            error_log('[JobQueue] entretien paresseux : ' . $e->getMessage());
        }
    }

    /**
     * Rattrape les jobs restés `pending` sans jamais être déclenchés : worker
     * mort juste après l'enfilage, ou tâche système enfilée avec
     * `$allowInline = false` alors qu'aucun worker ne tournait. Sans ce filet,
     * ces jobs attendraient indéfiniment.
     *
     * Appelé uniquement depuis `maybeMaintain()` — donc jamais quand un worker
     * est vivant — et borné pour ne pas forker en masse.
     */
    private static function dispatchOrphanPending(): void
    {
        if (!BackgroundJob::canSpawn()) {
            return;
        }
        // Seulement des jobs dont le couloir est libre : sinon les trois
        // places pourraient revenir sans fin à des jobs qui ne peuvent pas
        // partir, pendant que d'autres attendent derrière eux.
        $stmt = Database::getInstance()->query(
            "SELECT j.id FROM async_jobs j
             WHERE j.status = 'pending' AND j.created_at < NOW() - INTERVAL 1 MINUTE
               AND " . self::LANE_FREE_SQL . "
             ORDER BY j.priority, j.id LIMIT 3"
        );
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []) as $jobId) {
            BackgroundJob::dispatch(ROOT . '/scripts/run_job.php', (int)$jobId);
        }
    }

    /**
     * Purge du flux rejouable des jobs terminés (le résultat, lui, est
     * conservé) puis des jobs eux-mêmes passé JOBS_TTL_DAYS — sans quoi la
     * table croît indéfiniment.
     */
    public static function gcEvents(): void
    {
        $db = Database::getInstance();
        $db->exec(
            'DELETE je FROM job_events je
             JOIN async_jobs j ON j.id = je.job_id
             WHERE j.finished_at IS NOT NULL
               AND j.finished_at < NOW() - INTERVAL ' . self::EVENTS_TTL_MIN . ' MINUTE'
        );
        $db->exec(
            'DELETE FROM async_jobs
             WHERE finished_at IS NOT NULL
               AND finished_at < NOW() - INTERVAL ' . self::JOBS_TTL_DAYS . ' DAY'
        );
    }
}
