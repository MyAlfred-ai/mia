<?php

declare(strict_types=1);

/**
 * Espaces → Tâches programmées (/settings/schedules).
 *
 * Une page de liste et un formulaire en trois questions — Quoi, Quand, Où voir
 * le résultat. Le formulaire s'ENREGISTRE À LA MAIN, exception assumée à
 * l'auto-save des écrans de configuration (comme Authentification et Budget) :
 * une tâche agit seule, elle ne doit jamais partir à moitié saisie — « 0 »
 * en route vers « 08:00 » la programmerait à minuit. Les interrupteurs de la
 * liste, eux, s'enregistrent au clic.
 *
 * Le contrôleur est mince : la forme vit dans ScheduledTask et Recurrence,
 * l'exécution dans Scheduler. Toute lecture et toute écriture sont bornées à
 * la personne connectée.
 */
class ScheduledTaskController
{
    // ------------------------------------------------------------------
    // Liste
    // ------------------------------------------------------------------

    public function index(): void
    {
        AuthMiddleware::requirePermission('schedules.view');
        AuthMiddleware::refreshUser();
        $user   = Session::currentUser();
        $userId = (int)$user['id'];

        $success = Session::get('schedules_success');
        $error   = Session::get('schedules_error');
        Session::remove('schedules_success');
        Session::remove('schedules_error');

        $featureEnabled = ScheduledTask::isEnabled();
        // Ce que le rôle permet sur ses tâches (Personnes → Rôles) : les
        // boutons absents disent ce que le serveur refuserait de toute façon.
        $canCreate      = Role::userCan($user, 'schedules.create');
        $canEdit        = Role::userCan($user, 'schedules.edit');
        $canRun         = Role::userCan($user, 'schedules.use');
        $tasks          = ScheduledTask::forUser($userId);
        $lastRuns       = ScheduledTaskRun::latestForTasks(array_column($tasks, 'id'));
        $choices        = ScheduledTask::choicesFor($user);
        $profileNames   = array_map(static fn(array $p): string => (string)$p['name'], $choices['profiles']);
        $sourceIssues   = $this->sourceIssues($user);
        $activeCount    = ScheduledTask::countActive($userId);
        $maxActive      = ScheduledTask::MAX_ACTIVE_PER_USER;

        $rows = [];
        foreach ($tasks as $task) {
            $rows[] = $this->listRow($task, $lastRuns[$task['id']] ?? null, $profileNames, $sourceIssues);
        }
        // Cartes préparées par une exécution (option « actions à approuver ») et
        // encore en attente : « 2 actions à approuver », sur la carte de la tâche.
        $pending = DrivePendingAction::countPendingByConversation(array_filter(array_column($rows, 'thread_id')), $userId);
        foreach ($rows as $i => $row) {
            $rows[$i]['pending'] = $row['thread_id'] !== null ? (int)($pending[$row['thread_id']] ?? 0) : 0;
        }

        // « Agir sans validation » retiré par l'administrateur : les tâches
        // réglées ainsi préparent leurs actions — la liste et le schéma le disent.
        $autoAllowed = ScheduledTask::autoAllowed();

        // Schéma affiché à droite au clic sur une tâche : le catalogue (libellés,
        // icônes, fiches) et la configuration de chaque tâche, dessinés par
        // schedule-diagram.js sans aller-retour.
        $diagram = null;
        if ($tasks !== []) {
            $cards = self::assistantCards(array_values($choices['profiles']), $choices['profile_kbs'], $choices['llms'], $choices['profile_tools']);
            $specs = [];
            foreach ($tasks as $task) {
                $specs[(int)$task['id']] = TaskDiagram::specFromTask($task, $autoAllowed);
            }
            $diagram = [
                'catalog' => TaskDiagram::catalog($choices, $cards, $sourceIssues),
                'tasks'   => (object)$specs,
            ];
        }

        // Notifications sur les appareils : clé publique de l'instance (créée
        // au premier besoin) et appareils déjà inscrits. Une clé impossible à
        // créer ne casse pas la page : la carte dit « indisponible ».
        $pushKey = '';
        if ($featureEnabled) {
            try {
                $pushKey = WebPush::publicKey();
            } catch (\Throwable $e) {
                error_log('[Push] clé publique indisponible : ' . $e->getMessage());
            }
        }
        $pushDevices = PushController::devices($userId);
        $isNative    = NativeApp::isNative();

        require ROOT . '/templates/settings/schedules.php';
    }

    /**
     * Ce que la liste affiche d'une tâche : phrase, prochaine exécution dans
     * le fuseau de la tâche, dernière issue, sources à reconnecter.
     */
    private function listRow(array $task, ?array $lastRun, array $profileNames, array $sourceIssues): array
    {
        $zone = Recurrence::zone((string)$task['timezone']);
        $next = ScheduledTask::fromSqlUtc($task['next_run_at'] ?? null);

        $issues = [];
        foreach ((array)$task['sources_list'] as $source) {
            if (isset($sourceIssues[$source])) {
                $issues[] = $sourceIssues[$source];
            }
        }

        $last = null;
        if ($lastRun !== null) {
            $slot = ScheduledTask::fromSqlUtc((string)$lastRun['slot_at']);
            $last = [
                'status'          => (string)$lastRun['status'],
                'label'           => ScheduledTask::runStatusLabel((string)$lastRun['status']),
                'reason'          => ScheduledTask::reasonLabel((string)$lastRun['reason']),
                'when'            => $slot !== null ? Format::shortDateTime($slot->setTimezone($zone)) : '',
                'conversation_id' => $lastRun['conversation_id'] !== null ? (int)$lastRun['conversation_id'] : null,
            ];
        }

        return [
            'id'          => (int)$task['id'],
            'name'        => (string)$task['name'],
            'active'      => $task['status'] === 'active',
            'pause'       => $task['status'] === 'paused' ? ScheduledTask::reasonLabel((string)$task['pause_reason']) : '',
            'pause_user'  => (string)$task['pause_reason'] === ScheduledTask::PAUSE_USER,
            'sentence'    => $task['rule'] !== null ? Recurrence::describe($task['rule']) : '',
            'next'        => ($task['status'] === 'active' && $next !== null) ? Format::shortDateTime($next->setTimezone($zone)) : '',
            'assistant'   => $task['profile_id'] !== null
                ? (string)($profileNames[$task['profile_id']] ?? t('Assistant indisponible'))
                : 'MIA',
            'issues'      => $issues,
            // « Agit sans validation » se voit sur la liste : c'est la seule
            // tâche qui fait des choses en votre nom sans vous demander.
            'auto'        => ScheduledTask::modeOf($task) === ScheduledTask::MODE_AUTO,
            'auto_send'   => ScheduledTask::modeOf($task) === ScheduledTask::MODE_AUTO && !empty($task['auto_send_mail']),
            'last'        => $last,
            'thread_id'   => $task['destination'] === ScheduledTask::DEST_THREAD
                ? ScheduledTask::threadConversationId((int)$task['id'], (int)$task['user_id'])
                : null,
        ];
    }

    // ------------------------------------------------------------------
    // Formulaire
    // ------------------------------------------------------------------

    /** Nouvelle tâche — vierge, ou pré-remplie depuis une réponse du chat (`?from=`). */
    public function create(): void
    {
        AuthMiddleware::requirePermission('schedules.create');
        AuthMiddleware::refreshUser();
        $user = Session::currentUser();

        if (!ScheduledTask::isEnabled()) {
            $this->redirectWith('/settings/schedules', 'schedules_error', t('Les tâches programmées ne sont pas proposées sur cette instance.'));
        }

        $choices = ScheduledTask::choicesFor($user);
        $form = $this->defaultForm();
        $fromMessage = false;
        $fromHadFiles = false;
        $fromAction = null;
        $messageId = (int)($_GET['from'] ?? 0);
        $actionId  = (int)($_GET['action'] ?? 0);
        if ($actionId > 0) {
            // « Ajuster » sur la carte d'une tâche préparée par MIA.
            $pending = DrivePendingAction::findPendingForUser($actionId, (int)$user['id']);
            if ($pending !== null && (string)$pending['tool'] === 'programmer_tache') {
                // Une carte déposée avant les notifications ne dit rien de
                // « Me prévenir » : le défaut (oui) s'applique. « Agir sans
                // validation » ne vient jamais d'une carte : c'est la personne
                // qui le coche, ici.
                $form = $this->formFromPost(ScheduledTask::withoutAutoMode((array)$pending['args'] + ['notify' => '1']));
                $fromAction = $actionId;
            }
        } elseif ($messageId > 0) {
            [$form, $fromMessage, $fromHadFiles] = $this->prefillFromMessage($form, $messageId, $user, $choices);
        } elseif (isset($_GET['compose'])) {
            $form = $this->prefillFromComposer($form, $choices);
        }

        $this->renderForm($user, $form, null, [
            'from_message'   => $fromMessage,
            'from_had_files' => $fromHadFiles,
            'from_action'    => $fromAction,
            'from_composer'  => isset($_GET['compose']) && $actionId <= 0 && $messageId <= 0,
        ]);
    }

    public function store(): void
    {
        AuthMiddleware::requirePermission('schedules.create');
        AuthMiddleware::refreshUser();
        $user   = Session::currentUser();
        $userId = (int)$user['id'];

        if (!ScheduledTask::isEnabled()) {
            $this->redirectWith('/settings/schedules', 'schedules_error', t('Les tâches programmées ne sont pas proposées sur cette instance.'));
        }
        $fromAction = (int)($_POST['from_action'] ?? 0) ?: null;
        if (ScheduledTask::countActive($userId) >= ScheduledTask::MAX_ACTIVE_PER_USER) {
            $this->renderForm($user, $this->formFromPost($_POST), $this->limitMessage(), ['from_action' => $fromAction]);
            return;
        }

        try {
            $data = ScheduledTask::normalizeInput($_POST, ScheduledTask::allowedFrom(ScheduledTask::choicesFor($user)));
        } catch (\InvalidArgumentException $e) {
            $this->renderForm($user, $this->formFromPost($_POST), $e->getMessage(), ['from_action' => $fromAction]);
            return;
        }

        $now = $this->now();
        $id  = ScheduledTask::create($userId, $data, $now);
        $next = Recurrence::next($data['rule'], $data['timezone'], $now);
        if ($fromAction !== null) {
            $this->closeAdjustedCard($fromAction, $userId, $id, $data, $next);
        }
        $this->redirectWith('/settings/schedules#task-' . $id, 'schedules_success', t('Tâche programmée. Première exécution : {date}.', [
            'date' => Format::shortDateTime($next->setTimezone(Recurrence::zone($data['timezone']))),
        ]));
    }

    public function edit(string $id): void
    {
        AuthMiddleware::requirePermission('schedules.edit');
        AuthMiddleware::refreshUser();
        $user = Session::currentUser();
        $task = $this->ownedTask($id, $user);

        $this->renderForm($user, $this->formFromTask($task), null, ['task' => $task]);
    }

    public function update(string $id): void
    {
        AuthMiddleware::requirePermission('schedules.edit');
        AuthMiddleware::refreshUser();
        $user = Session::currentUser();
        $task = $this->ownedTask($id, $user);

        try {
            $data = ScheduledTask::normalizeInput($_POST, ScheduledTask::allowedFrom(ScheduledTask::choicesFor($user)));
        } catch (\InvalidArgumentException $e) {
            $this->renderForm($user, $this->formFromPost($_POST, (int)$task['id']), $e->getMessage(), ['task' => $task]);
            return;
        }

        ScheduledTask::update((int)$task['id'], (int)$user['id'], $data, $this->now());
        $this->redirectWith('/settings/schedules#task-' . (int)$task['id'], 'schedules_success', t('Tâche enregistrée.'));
    }

    /**
     * Aperçu de la règle pendant la saisie : la phrase, les trois prochaines
     * dates et le volume mensuel, calculés ICI — l'écran n'a aucune copie du
     * calcul des dates. Aucune écriture.
     */
    public function preview(): void
    {
        AuthMiddleware::requireAnyPermission('schedules.create', 'schedules.edit');
        AuthMiddleware::refreshUser();
        // L'aperçu n'a besoin ni de la session ni de son verrou.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $rule = Recurrence::normalize($_POST);
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::fail($e->getMessage());
        }

        $tz = ScheduledTask::normalizeTimezone((string)($_POST['timezone'] ?? ''));
        AjaxResponse::ok(self::previewPayload($rule, $tz, $this->now()));
    }

    /** Phrase, prochaines dates et volume d'une règle. Rendu initial ET aperçu. */
    public static function previewPayload(array $rule, string $tz, \DateTimeImmutable $now): array
    {
        $zone = Recurrence::zone($tz);
        $perMonth = Recurrence::perMonth($rule);
        return [
            'sentence'  => Recurrence::describe($rule),
            'next'      => array_map(
                static fn(\DateTimeImmutable $d): string => Format::shortDateTime($d->setTimezone($zone)),
                Recurrence::upcoming($rule, $tz, $now, 3)
            ),
            'per_month' => I18n::plural($perMonth, 'Environ {n} exécution par mois', 'Environ {n} exécutions par mois'),
            'zone'      => t('heure de {ville}', ['ville' => ScheduledTask::zoneCity($tz)]),
        ];
    }

    // ------------------------------------------------------------------
    // Essai depuis le formulaire
    // ------------------------------------------------------------------

    /** Conversations d'essai créées dans CETTE session : seules à pouvoir être remplacées. */
    private const TRIAL_SESSION_KEY = 'schedule_trials';

    /**
     * « Lancer un essai » : la tâche telle que SAISIE, exécutée une fois tout
     * de suite sans rien enregistrer (Scheduler::startTrial). Mêmes règles que
     * l'enregistrement (normalizeInput) ; la réponse JSON part AVANT le
     * déclenchement du job, le formulaire suit ensuite son flux
     * (/jobs/{id}/stream). Un nouvel essai remplace le précédent de la même
     * page — s'il n'a servi à rien d'autre (cf. dropReplacedTrial).
     */
    public function trial(): void
    {
        AuthMiddleware::requirePermission('schedules.use');
        AuthMiddleware::refreshUser();
        $user   = Session::currentUser();
        $userId = (int)$user['id'];

        if (!ScheduledTask::isEnabled()) {
            AjaxResponse::fail(t('Les tâches programmées ne sont pas proposées sur cette instance.'));
        }
        try {
            $data = ScheduledTask::normalizeInput($_POST, ScheduledTask::allowedFrom(ScheduledTask::choicesFor($user)));
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::fail($e->getMessage());
        }
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId > 0 && ScheduledTask::findForUser($taskId, $userId) === null) {
            $taskId = 0;
        }

        $this->dropReplacedTrial((int)($_POST['replace'] ?? 0), $userId);

        $result = Scheduler::startTrial($user, $data, $taskId > 0 ? $taskId : null, $this->now());
        if (!$result['ok']) {
            AjaxResponse::fail((string)$result['error']);
        }
        $convId = (int)$result['conversation_id'];
        $trials = array_values(array_unique(array_merge(
            array_map('intval', (array)Session::get(self::TRIAL_SESSION_KEY, [])),
            [$convId]
        )));
        Session::set(self::TRIAL_SESSION_KEY, array_slice($trials, -20));

        JobQueue::respondAndDispatch([
            'ok'              => true,
            'job_id'          => (int)$result['job_id'],
            'conversation_id' => $convId,
            'url'             => '/chat/' . $convId,
            'rejoined'        => !empty($result['rejoined']),
            // « Agir sans validation » : pendant l'essai, les actions sont
            // préparées et attendent la personne (Scheduler::startTrial).
            'auto_held'       => ScheduledTask::modeOf($data) === ScheduledTask::MODE_AUTO,
        ], (int)$result['job_id']);
        exit;
    }

    /**
     * Résultat d'un essai, une fois le flux terminé : la réponse rendue côté
     * serveur (HTML sûr par construction, MarkdownHtml), l'erreur présentée
     * le cas échéant, et les actions préparées qui attendent une validation.
     */
    public function trialResult(string $id): void
    {
        AuthMiddleware::requirePermission('schedules.use');
        $user   = Session::currentUser();
        $convId = (int)$id;
        if ($convId <= 0 || !Conversation::belongsToUser($convId, (int)$user['id'])) {
            AjaxResponse::fail(t('Essai introuvable.'), 404);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $pending = DrivePendingAction::countPendingByConversation([$convId], (int)$user['id']);
        AjaxResponse::ok(self::trialResultPayload(Message::lastAnswer($convId), (int)($pending[$convId] ?? 0), $convId));
    }

    /**
     * Ce que le formulaire affiche d'un essai terminé. Pure.
     *
     * @return array{ready: bool, url: string, pending: int, html?: string,
     *               error?: array{title: string, message: string}, actions?: list<array>}
     */
    public static function trialResultPayload(?array $message, int $pending, int $convId): array
    {
        $out = ['ready' => $message !== null, 'url' => '/chat/' . $convId, 'pending' => $pending];
        if ($message === null) {
            return $out;
        }
        $content = trim((string)$message['content']);
        // Réponse en erreur (« ⚠️ Titre », ligne vide, message) : présentée,
        // jamais rendue comme une réponse.
        if (str_starts_with($content, '⚠')) {
            $text  = trim((string)preg_replace('/^⚠\x{FE0F}?\s*/u', '', $content));
            $parts = preg_split('/\R\s*\R/u', $text, 2) ?: [$text];
            $out['error'] = count($parts) === 2
                ? ['title' => trim($parts[0]), 'message' => trim($parts[1])]
                : ['title' => t('L’essai n’a pas abouti'), 'message' => trim($parts[0])];
            return $out;
        }
        $out['html']    = MarkdownHtml::render(MarkdownDocument::parse($content));
        $out['actions'] = Message::normalizeActions(json_decode((string)($message['actions_json'] ?? ''), true) ?: []);
        return $out;
    }

    /**
     * Retire l'essai précédent de la page quand on en lance un autre — à
     * QUATRE conditions, sinon il reste dans l'historique : il a été créé par
     * un essai de cette session, il appartient à la personne, on n'y a rien
     * écrit d'autre que la consigne, et rien n'y attend (génération en cours,
     * action préparée à valider).
     */
    private function dropReplacedTrial(int $convId, int $userId): void
    {
        if ($convId <= 0 || !in_array($convId, array_map('intval', (array)Session::get(self::TRIAL_SESSION_KEY, [])), true)) {
            return;
        }
        if (!Conversation::belongsToUser($convId, $userId)) {
            return;
        }
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND role = 'user'");
        $stmt->execute([$convId]);
        if ((int)$stmt->fetchColumn() !== 1) {
            return;
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM async_jobs WHERE conversation_id = ? AND status IN ('pending','running')");
        $stmt->execute([$convId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        if ((DrivePendingAction::countPendingByConversation([$convId], $userId)[$convId] ?? 0) > 0) {
            return;
        }
        Conversation::delete($convId);
    }

    // ------------------------------------------------------------------
    // Gestes de la liste
    // ------------------------------------------------------------------

    /** Interrupteur Active / En pause — enregistré au clic (JSON), repli sans JavaScript. */
    public function toggle(string $id): void
    {
        AuthMiddleware::requirePermission('schedules.edit');
        AuthMiddleware::refreshUser();
        $user   = Session::currentUser();
        $userId = (int)$user['id'];
        $task   = $this->ownedTask($id, $user);
        $wanted = !empty($_POST['active']);

        if ($wanted && $task['status'] !== 'active') {
            if (!ScheduledTask::isEnabled()) {
                $this->fail(t('Les tâches programmées ne sont pas proposées sur cette instance.'));
            }
            if (ScheduledTask::countActive($userId, (int)$task['id']) >= ScheduledTask::MAX_ACTIVE_PER_USER) {
                $this->fail($this->limitMessage());
            }
            if (!ScheduledTask::resume((int)$task['id'], $userId, $this->now())) {
                $this->fail(t('Récurrence illisible : ouvrez la tâche pour la reprendre.'));
            }
        } elseif (!$wanted && $task['status'] === 'active') {
            ScheduledTask::pauseForUser((int)$task['id'], $userId);
        }

        $fresh = ScheduledTask::findForUser((int)$task['id'], $userId) ?? $task;
        $row = $this->listRow($fresh, null, [], []);
        if (AjaxResponse::wanted()) {
            AjaxResponse::ok([
                'active' => $row['active'],
                'next'   => $row['next'],
                'pause'  => $row['pause'],
            ]);
        }
        header('Location: /settings/schedules#task-' . (int)$task['id']);
        exit;
    }

    /**
     * « Exécuter maintenant » : la consigne part tout de suite dans la
     * conversation de la tâche, hors calendrier. La réponse (JSON ou
     * redirection vers la conversation) part AVANT le déclenchement du job —
     * sans worker, le job s'exécute après elle, jamais en la retenant.
     */
    public function runNow(string $id): void
    {
        AuthMiddleware::requirePermission('schedules.use');
        AuthMiddleware::refreshUser();
        $user = Session::currentUser();
        $task = $this->ownedTask($id, $user);

        if (!ScheduledTask::isEnabled()) {
            $this->fail(t('Les tâches programmées ne sont pas proposées sur cette instance.'));
        }
        if ($task['rule'] === null) {
            $this->fail(t('Récurrence illisible : ouvrez la tâche pour la reprendre.'));
        }

        $result = Scheduler::runNow($task, $this->now());
        if (!$result['ok']) {
            $this->fail((string)$result['error']);
        }

        $url = '/chat/' . (int)$result['conversation_id'];
        if (AjaxResponse::wanted()) {
            JobQueue::respondAndDispatch(['ok' => true, 'url' => $url], (int)$result['job_id']);
            exit;
        }
        $this->redirectAndDispatch($url, (int)$result['job_id']);
    }

    public function delete(string $id): void
    {
        AuthMiddleware::requirePermission('schedules.delete');
        $user = Session::currentUser();
        $task = $this->ownedTask($id, $user);

        ScheduledTask::delete((int)$task['id'], (int)$user['id']);
        $this->redirectWith('/settings/schedules', 'schedules_success', t('Tâche supprimée. Ses conversations restent dans votre historique.'));
    }

    // ------------------------------------------------------------------
    // Rendu du formulaire
    // ------------------------------------------------------------------

    private function renderForm(array $user, array $form, ?string $error, array $extra = []): void
    {
        $task         = $extra['task'] ?? null;
        $isEdit       = $task !== null;
        $fromMessage  = (bool)($extra['from_message'] ?? false);
        $fromHadFiles = (bool)($extra['from_had_files'] ?? false);
        $fromAction   = $extra['from_action'] ?? null;
        $fromComposer = (bool)($extra['from_composer'] ?? false);

        $choices    = ScheduledTask::choicesFor($user);
        $profiles   = array_values($choices['profiles']);
        $llms       = $choices['llms'];
        $sources    = $choices['sources'];
        $kbs        = $choices['kbs'];
        $tools      = $choices['tools'];
        $profileKbs = $choices['profile_kbs'];
        $profileTools = $choices['profile_tools'];
        $assistantCards = self::assistantCards($profiles, $profileKbs, $llms, $profileTools);
        // Assistant enregistré (tâche, carte « Ajuster ») qui n'est plus
        // accessible — retiré, désactivé, plus partagé avec ses groupes : le
        // sélecteur le montre « indisponible » et l'enregistrement est refusé
        // tant qu'un autre choix n'est pas fait. Sans cette option, la liste
        // retombait sur « sans assistant » et un simple enregistrement (l'heure
        // changée) faisait perdre l'assistant à la tâche, en silence.
        $missingProfileId = self::missingProfileId($form['profile_id'] ?? null, $choices['profiles']);

        // Fuseau de la tâche, ou celui du navigateur (posé par schedules.js
        // avant tout envoi) — en attendant, l'aperçu initial le suppose UTC.
        $previewTz = ScheduledTask::normalizeTimezone((string)$form['timezone']);
        try {
            $preview = self::previewPayload(Recurrence::normalize($form), $previewTz, $this->now());
        } catch (\InvalidArgumentException) {
            $preview = null;
        }

        $runs = $isEdit ? array_map(fn(array $run): array => [
            'label'           => ScheduledTask::runStatusLabel((string)$run['status']),
            'status'          => (string)$run['status'],
            'reason'          => ScheduledTask::reasonLabel((string)$run['reason']),
            'manual'          => (int)$run['is_manual'] === 1,
            'when'            => ($slot = ScheduledTask::fromSqlUtc((string)$run['slot_at'])) !== null
                ? Format::shortDateTime($slot->setTimezone(Recurrence::zone((string)$task['timezone'])))
                : '',
            'conversation_id' => $run['conversation_id'] !== null ? (int)$run['conversation_id'] : null,
        ], ScheduledTaskRun::recentForTask((int)$task['id'], 8)) : [];

        $activeCount = ScheduledTask::countActive((int)$user['id'], $isEdit ? (int)$task['id'] : null);
        $maxActive   = ScheduledTask::MAX_ACTIVE_PER_USER;
        $limitReached = !$isEdit && $activeCount >= $maxActive;
        $autoAllowed  = !empty($choices['auto']);

        // Schéma de la tâche, à droite du formulaire : schedules.js le
        // recompose à chaque geste depuis les champs, et reprend le
        // déclencheur de chaque aperçu du serveur.
        $paused = $isEdit && $task['status'] !== 'active';
        $diagram = [
            'catalog' => TaskDiagram::catalog($choices, $assistantCards, $this->sourceIssues($user)),
            'trigger' => [
                'sentence'  => (string)($preview['sentence'] ?? ''),
                'next'      => (string)($preview['next'][0] ?? ''),
                'zone'      => (string)($preview['zone'] ?? ''),
                'per_month' => (string)($preview['per_month'] ?? ''),
                'active'    => !$paused,
                'pause'     => $paused && (string)$task['pause_reason'] !== ScheduledTask::PAUSE_USER
                    ? ScheduledTask::reasonLabel((string)$task['pause_reason'])
                    : '',
            ],
        ];

        // Droits du rôle : l'essai demande « Utiliser », la suppression « Supprimer ».
        $canRun    = Role::userCan($user, 'schedules.use');
        $canDelete = Role::userCan($user, 'schedules.delete');
        require ROOT . '/templates/settings/schedule-form.php';
    }

    /** Valeurs d'un formulaire vierge : chaque lundi à 8 h, à la suite, Internet coché. */
    private function defaultForm(): array
    {
        return [
            'id'          => null,
            'name'        => '',
            'instruction' => '',
            'profile_id'  => null,
            'llm'         => '',
            'freq'        => Recurrence::WEEKLY,
            'days'        => [1],
            'day'         => 1,
            'time'        => '08:00',
            'timezone'    => '',
            'sources'     => ConnectorRegistry::isEnabled(ConnectorRegistry::WEB) ? ['web'] : [],
            'kb_ids'      => [],
            'tool_ids'    => [],
            'destination' => ScheduledTask::DEST_THREAD,
            'allow_actions' => false,
            'action_mode' => ScheduledTask::MODE_READ,
            'auto_send_mail' => false,
            'notify'      => true,
        ];
    }

    private function formFromTask(array $task): array
    {
        $rule = $task['rule'] ?? ['freq' => Recurrence::WEEKLY, 'days' => [1], 'time' => '08:00'];
        return [
            'id'          => (int)$task['id'],
            'name'        => (string)$task['name'],
            'instruction' => (string)$task['instruction'],
            'profile_id'  => $task['profile_id'],
            'llm'         => (string)$task['llm'],
            'freq'        => (string)$rule['freq'],
            'days'        => (array)($rule['days'] ?? [1]),
            'day'         => $rule['day'] ?? 1,
            'time'        => (string)$rule['time'],
            'timezone'    => (string)$task['timezone'],
            'sources'     => (array)$task['sources_list'],
            'kb_ids'      => (array)$task['kb_list'],
            'tool_ids'    => (array)$task['tool_list'],
            'destination' => (string)$task['destination'],
            'allow_actions' => (bool)$task['allow_actions'],
            'action_mode' => ScheduledTask::modeOf($task),
            'auto_send_mail' => !empty($task['auto_send_mail']),
            'notify'      => (bool)$task['notify'],
        ];
    }

    /** Saisie renvoyée telle quelle après une erreur : rien de ce qu'on a tapé ne se perd. */
    private function formFromPost(array $post, ?int $id = null): array
    {
        $profile = (string)($post['profile_id'] ?? '');
        $rawMode = is_scalar($post['allow_actions'] ?? null) ? (string)$post['allow_actions'] : '';
        $mode    = $rawMode === ScheduledTask::MODE_AUTO
            ? ScheduledTask::MODE_AUTO
            : (filter_var($rawMode, FILTER_VALIDATE_BOOLEAN) ? ScheduledTask::MODE_APPROVE : ScheduledTask::MODE_READ);
        return [
            'id'          => $id,
            'name'        => (string)($post['name'] ?? ''),
            'instruction' => (string)($post['instruction'] ?? ''),
            'profile_id'  => ($profile !== '' && $profile !== '0') ? (int)$profile : null,
            'llm'         => (string)($post['llm'] ?? ''),
            'freq'        => (string)($post['freq'] ?? Recurrence::WEEKLY),
            'days'        => array_map('intval', (array)($post['days'] ?? [])),
            'day'         => (string)($post['day'] ?? '1'),
            'time'        => (string)($post['time'] ?? '08:00'),
            'timezone'    => (string)($post['timezone'] ?? ''),
            'sources'     => array_map('strval', (array)($post['sources'] ?? [])),
            'kb_ids'      => array_values(array_filter(array_map('intval', (array)($post['kb_ids'] ?? [])))),
            'tool_ids'    => array_values(array_filter(array_map('intval', (array)($post['tool_ids'] ?? [])))),
            'destination' => (string)($post['destination'] ?? ScheduledTask::DEST_THREAD),
            'allow_actions' => $mode !== ScheduledTask::MODE_READ,
            'action_mode' => $mode,
            'auto_send_mail' => filter_var($post['auto_send_mail'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'notify'      => filter_var($post['notify'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Pré-remplissage depuis une réponse du chat : la demande qui l'a
     * produite devient la consigne ; assistant, modèle, titre et sources
     * cochées suivent. Une réponse d'autrui ou introuvable donne un
     * formulaire vierge, sans message — c'est un raccourci, pas une porte.
     *
     * @return array{0: array, 1: bool, 2: bool} [formulaire, pré-rempli ?, demande avec fichiers ?]
     */
    private function prefillFromMessage(array $form, int $messageId, array $user, array $choices): array
    {
        $message = Message::findById($messageId);
        if ($message === null || ($message['role'] ?? '') !== 'assistant'
            || !Conversation::belongsToUser((int)$message['conversation_id'], (int)$user['id'])) {
            return [$form, false, false];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT content, files_json FROM messages
             WHERE conversation_id = ? AND role = \'user\' AND id < ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([(int)$message['conversation_id'], $messageId]);
        $request = $stmt->fetch();
        if (!$request || trim((string)$request['content']) === '') {
            return [$form, false, false];
        }

        $conv = Conversation::findById((int)$message['conversation_id']) ?? [];
        $profileId  = (int)($conv['profile_id'] ?? 0);

        $form['instruction'] = mb_substr(trim((string)$request['content']), 0, ScheduledTask::INSTRUCTION_MAX);
        $form['profile_id']  = isset($choices['profiles'][$profileId]) ? $profileId : null;
        if ($form['profile_id'] === null) {
            $llm = (string)($conv['llm_used'] ?? '');
            $form['llm'] = isset($choices['llms'][$llm]) ? $llm : '';
        }
        $title = trim((string)($conv['title'] ?? ''));
        $form['name'] = ($title !== '' && $title !== 'Nouvelle conversation')
            ? mb_substr($title, 0, ScheduledTask::NAME_MAX)
            : ScheduledTask::defaultName($form['instruction']);

        if (isset($_GET['src'])) {
            $wanted = array_filter(explode(',', (string)$_GET['src']));
            $form['sources'] = array_values(array_intersect(array_keys($choices['sources']), $wanted));
        }
        // Bases cochées dans la conversation d'origine : la tâche les reprend.
        $form['kb_ids'] = self::allowedKbIds(
            ConversationKnowledgeBase::idsForConversation((int)$message['conversation_id']),
            $choices,
            $form['profile_id']
        );

        $hadFiles = trim((string)($request['files_json'] ?? '')) !== '' && trim((string)$request['files_json']) !== '[]';
        return [$form, true, $hadFiles];
    }

    /**
     * Pré-remplissage depuis la zone de saisie (horloge de l'assistant) :
     * l'assistant, le modèle et les sources cochées viennent de l'adresse,
     * VALIDÉS ici ; le texte en cours de saisie, lui, ne voyage jamais dans
     * une adresse (journaux, historique) — schedules.js le reprend du
     * stockage de session de l'onglet.
     */
    private function prefillFromComposer(array $form, array $choices): array
    {
        $profileId = (int)($_GET['profile'] ?? 0);
        $form['profile_id'] = isset($choices['profiles'][$profileId]) ? $profileId : null;
        if ($form['profile_id'] === null) {
            $llm = (string)($_GET['llm'] ?? '');
            $form['llm'] = isset($choices['llms'][$llm]) ? $llm : '';
            if ($profileId === 0) {
                $form['name'] = '';
            }
        } else {
            $form['name'] = (string)$choices['profiles'][$profileId]['name'];
        }
        if (isset($_GET['src'])) {
            $wanted = array_filter(explode(',', (string)$_GET['src']));
            $form['sources'] = array_values(array_intersect(array_keys($choices['sources']), $wanted));
        }
        if (isset($_GET['kb'])) {
            $form['kb_ids'] = self::allowedKbIds(explode(',', (string)$_GET['kb']), $choices, $form['profile_id']);
        }
        return $form;
    }

    /**
     * Bases proposées au formulaire depuis une conversation ou une adresse :
     * seulement celles que la personne peut cocher, hors celles de
     * l'assistant (incluses d'office), bornées au plafond. Pure.
     *
     * @param list<int|string> $requested
     * @return list<int>
     */
    public static function allowedKbIds(array $requested, array $choices, ?int $profileId): array
    {
        $allowed = ScheduledTask::allowedFrom($choices);
        $kbAllowed = (array)($allowed['kbs'] ?? []);
        if ($profileId !== null) {
            $kbAllowed = array_values(array_diff($kbAllowed, (array)($allowed['profile_kbs'][$profileId] ?? [])));
        }
        return ConversationKnowledgeBase::normalizeSelection($requested, $kbAllowed)['ids'];
    }

    /**
     * Identifiant de l'assistant du formulaire s'il n'est PLUS parmi ceux que
     * la personne peut choisir, sinon null (aucun assistant, ou un assistant
     * permis). Pure.
     *
     * @param array<int, mixed> $profiles ScheduledTask::choicesFor()['profiles']
     */
    public static function missingProfileId(mixed $profileId, array $profiles): ?int
    {
        $id = is_scalar($profileId) ? (int)$profileId : 0;
        return $id > 0 && !isset($profiles[$id]) ? $id : null;
    }

    /**
     * Fiche de chaque assistant proposé, pour l'aperçu sous le sélecteur :
     * avatar (ou pastille de sa couleur), description, modèle imposé, bases et
     * outils qu'il apporte d'office. Construite côté serveur et lue telle
     * quelle par schedules.js — aucune règle dupliquée à l'écran.
     *
     * Les OUTILS se voient : un outil qui agit (workflow n8n, présentation
     * Gamma) apporté par l'assistant sert à la tâche sans que la personne l'ait
     * coché — elle doit le savoir (cf. Scheduler::autoExecutable).
     *
     * @param list<array> $profiles
     * @param array<int, list<array{id: int, name: string, type: string, acting: bool}>> $profileTools
     *        ScheduledTask::choicesFor()['profile_tools']
     * @return array<int, array{name: string, avatar: string, color: string, description: string,
     *               model: string, llm: string, kbs: list<string>, kb_ids: list<int>,
     *               tools: list<array{id: int, name: string, type: string, acting: bool}>, meta: string}>
     */
    public static function assistantCards(array $profiles, array $profileKbs, array $llms, array $profileTools = []): array
    {
        $cards = [];
        foreach ($profiles as $profile) {
            $id = (int)$profile['id'];
            $avatar = trim((string)($profile['avatar'] ?? ''));
            $color  = (string)($profile['color'] ?? '');
            $llm    = trim((string)($profile['default_llm'] ?? ''));
            $model  = $llm === '' ? t('Modèle par défaut de l’application') : (string)($llms[$llm] ?? $llm);
            $kbs    = array_map(static fn(array $kb): string => (string)$kb['name'], $profileKbs[$id] ?? []);
            $tools  = array_values((array)($profileTools[$id] ?? []));
            $meta   = t('Modèle : {modele}', ['modele' => $model]);
            if ($kbs !== []) {
                $meta .= ' · ' . t('Bases de l’assistant : {liste}', ['liste' => implode(', ', $kbs)]);
            }
            if ($tools !== []) {
                $meta .= ' · ' . t('Outils de l’assistant : {liste}', ['liste' => implode(', ', array_map(
                    static fn(array $tool): string => (string)$tool['name'] . ' (' . ScheduledTask::toolTypeLabel((string)$tool['type']) . ')',
                    $tools
                ))]);
            }
            $cards[$id] = [
                'name'        => (string)$profile['name'],
                'avatar'      => $avatar !== '' ? '/assets/img/profils/' . rawurlencode(basename($avatar)) : '',
                'color'       => preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '',
                'description' => mb_substr(trim((string)($profile['description'] ?? '')), 0, 240),
                'model'       => $model,
                // Slug du modèle imposé ('' = défaut de l'application) : le
                // schéma de la tâche en tire le logo du fournisseur.
                'llm'         => $llm,
                'kbs'         => $kbs,
                'kb_ids'      => array_map(static fn(array $kb): int => (int)$kb['id'], $profileKbs[$id] ?? []),
                'tools'       => array_map(static fn(array $tool): array => [
                    'id'     => (int)$tool['id'],
                    'name'   => (string)$tool['name'],
                    'type'   => (string)$tool['type'],
                    'acting' => !empty($tool['acting']),
                ], $tools),
                'meta'        => $meta,
            ];
        }
        return $cards;
    }

    /**
     * La tâche vient d'être créée depuis « Ajuster » : la carte d'origine est
     * close (sinon un « Approuver » tardif programmerait un doublon) et la
     * conversation le dit. Une carte déjà tranchée ou expirée est laissée
     * telle quelle — la tâche, elle, existe : c'était la demande.
     */
    private function closeAdjustedCard(int $actionId, int $userId, int $taskId, array $data, \DateTimeImmutable $next): void
    {
        $pending = DrivePendingAction::findPendingForUser($actionId, $userId);
        if ($pending === null || (string)$pending['tool'] !== 'programmer_tache') {
            return;
        }
        $convId = (int)$pending['conversation_id'];
        if (DrivePendingAction::claim($actionId, $userId, $convId) === null) {
            return;
        }
        DrivePendingAction::resolve($actionId, 'executed');
        Message::create($convId, 'assistant', '✅ ' . t('Tâche programmée depuis le formulaire : [« {nom} »]({url}) — {rythme}. Première exécution : {date}.', [
            'nom'    => $data['name'],
            'url'    => '/settings/schedules/' . $taskId . '/edit',
            'rythme' => Recurrence::describe($data['rule']),
            'date'   => Format::shortDateTime($next->setTimezone(Recurrence::zone((string)$data['timezone']))),
        ]));
        Conversation::touch($convId);
    }

    /**
     * Sources dont la connexion est à refaire ou absente : nommées sur la
     * carte de la tâche (« Gmail à reconnecter »), sinon la tâche répondrait
     * sans elles chaque semaine sans que rien ne l'explique.
     *
     * @return array<string, string> clé de source → libellé du problème
     */
    private function sourceIssues(array $user): array
    {
        $issues = [];
        $reconnect = array_flip(ConnectorRegistry::reconnectNeeded((int)$user['id'], (string)($user['role'] ?? 'user')));
        $allowed   = ConnectorRegistry::usableBy($user);
        foreach (ConnectorRegistry::chatFeatureState((int)$user['id']) as $key => $state) {
            if (!$allowed) {
                // Le rôle ne relie plus de comptes : la tâche ne lira pas cette source.
                $issues[$key] = t('{service} : votre rôle ne permet plus de vous en servir', ['service' => ConnectorRegistry::label($key)]);
            } elseif (isset($reconnect[$key])) {
                $issues[$key] = t('{service} à reconnecter', ['service' => ConnectorRegistry::label($key)]);
            } elseif (empty($state['feature'])) {
                $issues[$key] = t('{service} n’est plus proposé', ['service' => ConnectorRegistry::label($key)]);
            } elseif (empty($state['connected'])) {
                $issues[$key] = t('{service} non connecté', ['service' => ConnectorRegistry::label($key)]);
            }
        }
        return $issues;
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    private function ownedTask(string $id, array $user): array
    {
        $task = ScheduledTask::findForUser((int)$id, (int)$user['id']);
        if ($task === null) {
            if (AjaxResponse::wanted()) {
                AjaxResponse::fail(t('Tâche introuvable.'), 404);
            }
            $this->redirectWith('/settings/schedules', 'schedules_error', t('Tâche introuvable.'));
        }
        return $task;
    }

    private function limitMessage(): string
    {
        return t('Vous avez déjà {n} tâches actives : mettez-en une en pause pour en programmer une autre.', [
            'n' => ScheduledTask::MAX_ACTIVE_PER_USER,
        ]);
    }

    /** Échec d'un geste de la liste : JSON pour l'écran, message flash sans JavaScript. */
    private function fail(string $message): never
    {
        if (AjaxResponse::wanted()) {
            AjaxResponse::fail($message);
        }
        $this->redirectWith('/settings/schedules', 'schedules_error', $message);
    }

    private function redirectWith(string $location, string $key, string $message): never
    {
        Session::set($key, $message);
        header('Location: ' . $location);
        exit;
    }

    /** Redirection envoyée et fermée, PUIS déclenchement du job (cf. JobQueue::respondAndDispatch). */
    private function redirectAndDispatch(string $location, int $jobId): never
    {
        if (!headers_sent()) {
            http_response_code(302);
            header('Location: ' . $location);
            header('Content-Length: 0');
            header('Connection: close');
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        JobQueue::dispatch($jobId);
        exit;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
