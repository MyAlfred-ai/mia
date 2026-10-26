<?php

declare(strict_types=1);

/**
 * Workflows (/workflows) — réservé aux créateurs et aux administrateurs.
 *
 *   GET  /workflows                       liste (onglets : mes workflows, historique des exécutions, modèles) ;
 *                                         ?vue=historique [&workflow=][&etat=][&run=] — `executions` en est l'alias
 *   GET  /workflows/history               la liste des lancements (JSON, tout rendue) : workflow, etat, before, upto
 *   GET  /workflows/new                   créer (décrire à MIA, modèle, vide) ;
 *                                         ?action= une carte « Créer un workflow » à ajuster,
 *                                         ?from=  une réponse menée selon un plan de travail
 *   POST /workflows                       créer (mode : blank | template | describe | from_message)
 *   GET  /workflows/launchable            lançables depuis le chat (JSON)
 *   GET  /workflows/runs/{id}             une exécution, en direct
 *   GET  /workflows/runs/{id}/state       son état (JSON, sondé par la page)
 *   POST /workflows/runs/{id}/gate        décision d'une validation (approve | return | stop)
 *   POST /workflows/runs/{id}/retry       reprendre à la brique (latest=1 : avec la dernière version publiée)
 *   POST /workflows/runs/{id}/skip        ignorer la brique en échec et continuer
 *   POST /workflows/runs/{id}/restart     refaire depuis une brique du rail (from, latest)
 *   POST /workflows/runs/{id}/resume      ne plus attendre (attente, nouvel essai automatique)
 *   POST /workflows/runs/{id}/cancel      arrêter
 *   POST /workflows/runs/{id}/pieces/{key}            corriger une pièce (à une validation)
 *   GET  /workflows/runs/{id}/pieces/{key}/download   télécharger une pièce
 *   GET  /workflows/{id}/edit             l'éditeur
 *   POST /workflows/{id}/draft            enregistrer le brouillon (JSON)
 *   POST /workflows/{id}/publish          publier
 *   POST /workflows/{id}/toggle | delete | duplicate
 *   GET  /workflows/{id}/form             formulaire de lancement (JSON)
 *   POST /workflows/{id}/launch           lancer
 *   POST /workflows/{id}/hook             l'adresse d'appel (renew=1 : en changer)
 *
 * L'adresse d'appel elle-même (POST /hooks/workflows/<jeton>) est servie par
 * WorkflowHookController, hors session.
 * Le brouillon s'enregistre au fil de l'édition (JSON, par l'éditeur) : il ne
 * s'exécute jamais. Seule la version PUBLIÉE tourne — c'est ce qui permet de
 * retoucher un workflow sans crainte.
 */
class WorkflowController
{
    // ==================================================================
    // Liste et création
    // ==================================================================

    public function index(): void
    {
        $user = $this->requireUser('view');
        $userId = (int)$user['id'];
        // « executions » : l'adresse de l'ancien onglet, fondu dans l'historique.
        $view = match ((string)($_GET['vue'] ?? '')) {
            'historique', 'executions' => 'historique',
            'modeles'                  => 'modeles',
            default                    => 'mes',
        };
        $workflows = Workflow::forUser($userId);
        $history = $view === 'historique' ? $this->historyPage($user, $workflows) : null;
        $waiting = WorkflowRun::waitingForUser($userId);
        $templates = WorkflowTemplates::catalog();
        $success = Session::get('workflows_success');
        $error = Session::get('workflows_error');
        Session::remove('workflows_success');
        Session::remove('workflows_error');
        $catalog = ['icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES, 'labels' => WorkflowCatalog::labels()];
        $coverProfiles = $view === 'mes' && $workflows !== [] ? $this->coverProfiles($user) : [];
        require ROOT . '/templates/workflows/index.php';
    }

    public function newPage(): void
    {
        $user = $this->requireUser('create');
        $userId = (int)$user['id'];

        // Carte « Créer un workflow » à ajuster : le brouillon naît maintenant,
        // la carte est close dans sa conversation.
        $actionId = (int)($_GET['action'] ?? 0);
        if ($actionId > 0) {
            $pending = DrivePendingAction::findPendingForUser($actionId, $userId);
            if ($pending !== null && (string)$pending['tool'] === WorkflowDraft::TOOL) {
                $id = $this->createFromCardArgs($user, (array)$pending['args']);
                if ($id !== null) {
                    $this->closeAdjustedCard($pending, $userId, $id);
                    header('Location: /workflows/' . $id . '/edit');
                    exit;
                }
            }
            $this->flash('workflows_error', t('Cette proposition n’est plus en attente : elle a été approuvée, annulée ou a expiré.'));
        }

        $fromMessage = (int)($_GET['from'] ?? 0);
        $plan = $fromMessage > 0 ? $this->planFromMessage($fromMessage, $userId) : null;
        $templates = WorkflowTemplates::catalog();
        $count = Workflow::countForUser($userId);
        $max = Workflow::MAX_PER_USER;
        $error = Session::get('workflows_error');
        Session::remove('workflows_error');
        $catalog = ['icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES];
        require ROOT . '/templates/workflows/new.php';
    }

    public function create(): void
    {
        $user = $this->requireUser('create');
        $userId = (int)$user['id'];
        if (Workflow::countForUser($userId) >= Workflow::MAX_PER_USER) {
            $this->failOr('/workflows/new', t('Vous avez atteint le nombre maximal de workflows ({max}). Supprimez-en un pour en créer un autre.', ['max' => Workflow::MAX_PER_USER]));
            return;
        }
        $mode = (string)($_POST['mode'] ?? 'blank');
        $id = null;
        switch ($mode) {
            case 'template':
                // Le modèle prend les connecteurs et les outils de création
                // de la personne (Outlook plutôt que Gmail, etc.).
                $tpl = WorkflowTemplates::get((string)($_POST['template'] ?? ''), WorkflowCatalog::choices($user));
                if ($tpl === null) {
                    $this->failOr('/workflows/new', t('Modèle introuvable.'));
                    return;
                }
                $id = Workflow::create($userId, $tpl['name'], $tpl['definition'], (string)$_POST['template'], $tpl['color']);
                break;

            case 'describe':
                $description = trim((string)($_POST['description'] ?? ''));
                if (mb_strlen($description) < 15) {
                    $this->failOr('/workflows/new', t('Décrivez le processus en une ou deux phrases : ce qui arrive au départ, qui fait quoi, ce que vous voulez à la fin.'));
                    return;
                }
                $quota = CreditQuota::status($user);
                if (CreditQuota::isExceeded($quota)) {
                    $this->failOr('/workflows/new', CreditQuota::refusal($quota)['message'], 429);
                    return;
                }
                session_write_close();
                $choices = WorkflowCatalog::choices($user);
                $res = WorkflowDraft::compose($description, $choices, $user, (string)($_POST['tz'] ?? ''));
                if ($res['args'] === null) {
                    $this->failOr('/workflows/new', (string)($res['error']['message'] ?? t('MIA n’a pas pu composer ce workflow.')), 502);
                    return;
                }
                $draft = WorkflowDraft::fromArgs($res['args'], $choices);
                $id = Workflow::create($userId, $draft['name'], $draft['definition'], null, WorkflowDraft::colorFor($draft['name']));
                break;

            case 'from_message':
                $plan = $this->planFromMessage((int)($_POST['message_id'] ?? 0), $userId);
                if ($plan === null) {
                    $this->failOr('/workflows/new', t('Cette réponse ne porte pas de plan de travail.'));
                    return;
                }
                $id = Workflow::create($userId, $plan['name'], $plan['definition']);
                break;

            default:
                $id = Workflow::create($userId, t('Nouveau workflow'), WorkflowSpec::blank());
        }

        $url = '/workflows/' . $id . '/edit';
        if (AjaxResponse::wanted()) {
            AjaxResponse::ok(['url' => $url, 'id' => $id]);
            return;
        }
        header('Location: ' . $url);
        exit;
    }

    // ==================================================================
    // Éditeur
    // ==================================================================

    public function edit(string $id): void
    {
        $user = $this->requireUser('edit');
        $workflow = $this->ownWorkflow((int)$id, $user);
        $choices = $this->choicesFor($user, $workflow['id']);
        $editor = [
            'workflow' => $this->workflowPayload($workflow),
            'definition' => $workflow['draft'],
            'issues' => WorkflowSpec::issues($workflow['draft'], $choices),
            'estimate' => WorkflowCatalog::estimate($workflow['draft'], $choices),
            'catalog' => WorkflowCatalog::forEditor($choices) + [
                // « Ensuite : … » — les workflows qui démarrent quand celui-ci se termine.
                'followers' => array_map(static fn(array $w): string => (string)$w['name'], Workflow::followersOf($workflow['id'], (int)$user['id'])),
            ],
            'tz' => '',
            // Droits du rôle (Personnes → Rôles) : dupliquer = créer,
            // supprimer, lancer — boutons absents plutôt qu'un refus au clic.
            'can' => [
                'create' => Workflow::allows($user, 'create'),
                'delete' => Workflow::allows($user, 'delete'),
                'use'    => Workflow::allows($user, 'use'),
            ],
            // Ouvert depuis la carte d'une conversation (« Approuver ») : le
            // retour à cette conversation, et les points à régler montrés d'office.
            'origin' => $this->originConversation((int)($_GET['conv'] ?? 0), (int)$user['id']),
        ];
        $historyCount = array_sum(WorkflowRun::historyCounts((int)$user['id'], $workflow['id']));
        require ROOT . '/templates/workflows/edit.php';
    }

    /** La conversation d'où vient la personne (`?conv=`), si elle est à elle. */
    private function originConversation(int $convId, int $userId): ?array
    {
        if ($convId <= 0 || !Conversation::belongsToUser($convId, $userId)) {
            return null;
        }
        $conv = Conversation::findById($convId);
        return ['conv_id' => $convId, 'url' => '/chat/' . $convId, 'title' => (string)($conv['title'] ?? '')];
    }

    /** Brouillon enregistré au fil de l'édition. Corps JSON : name, color, definition. */
    public function saveDraft(string $id): void
    {
        $user = $this->requireUser('edit');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body) || !is_array($body['definition'] ?? null)) {
            AjaxResponse::fail(t('Requête invalide.'), 400);
            return;
        }
        $def = WorkflowSpec::normalize($body['definition']);
        Workflow::saveDraft($workflow['id'], (int)$user['id'], (string)($body['name'] ?? $workflow['name']), (string)($body['color'] ?? ''), $def);
        $fresh = Workflow::findById($workflow['id']);
        $choices = $this->choicesFor($user, $workflow['id']);
        AjaxResponse::ok([
            'definition' => $fresh['draft'],
            'issues' => WorkflowSpec::issues($fresh['draft'], $choices),
            'estimate' => WorkflowCatalog::estimate($fresh['draft'], $choices),
            'workflow' => $this->workflowPayload($fresh),
        ]);
    }

    public function publish(string $id): void
    {
        $user = $this->requireUser('edit');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        $issues = WorkflowSpec::issues($workflow['draft'], $this->choicesFor($user, $workflow['id']));
        if (!WorkflowSpec::publishable($issues)) {
            $this->failOr('/workflows/' . $workflow['id'] . '/edit', t('Réglez d’abord les points signalés « À régler ».'));
            return;
        }
        $version = Workflow::publish($workflow['id'], (int)$user['id'], $this->now());
        $fresh = Workflow::findById($workflow['id']);
        if (AjaxResponse::wanted()) {
            AjaxResponse::ok(['version' => $version, 'workflow' => $this->workflowPayload($fresh)]);
            return;
        }
        $this->flash('workflows_success', t('Workflow publié (version {v}).', ['v' => $version]));
        header('Location: /workflows/' . $workflow['id'] . '/edit');
        exit;
    }

    public function toggle(string $id): void
    {
        $user = $this->requireUser('edit');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        $active = !$workflow['is_active'];
        Workflow::setActive($workflow['id'], (int)$user['id'], $active, $this->now());
        if (AjaxResponse::wanted()) {
            AjaxResponse::ok(['active' => $active]);
            return;
        }
        header('Location: /workflows');
        exit;
    }

    public function delete(string $id): void
    {
        $user = $this->requireUser('delete');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        Workflow::delete($workflow['id'], (int)$user['id']);
        if (AjaxResponse::wanted()) {
            AjaxResponse::ok(['url' => '/workflows']);
            return;
        }
        $this->flash('workflows_success', t('Workflow supprimé. Ses conversations restent dans votre historique.'));
        header('Location: /workflows');
        exit;
    }

    public function duplicate(string $id): void
    {
        $user = $this->requireUser('create');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        if (Workflow::countForUser((int)$user['id']) >= Workflow::MAX_PER_USER) {
            $this->failOr('/workflows', t('Vous avez atteint le nombre maximal de workflows ({max}). Supprimez-en un pour en créer un autre.', ['max' => Workflow::MAX_PER_USER]));
            return;
        }
        $newId = Workflow::create((int)$user['id'], t('Copie de {nom}', ['nom' => $workflow['name']]), $workflow['draft'], $workflow['template_key'], $workflow['color']);
        $url = '/workflows/' . $newId . '/edit';
        if (AjaxResponse::wanted()) {
            AjaxResponse::ok(['url' => $url]);
            return;
        }
        header('Location: ' . $url);
        exit;
    }

    // ==================================================================
    // Lancement
    // ==================================================================

    /** Formulaire de lancement d'un workflow publié (JSON). */
    public function form(string $id): void
    {
        $user = $this->requireUser('use');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        if ($workflow['published'] === null) {
            AjaxResponse::fail(t('Publiez le workflow avant de le lancer.'), 409);
            return;
        }
        AjaxResponse::ok(['form' => $this->launchForm($workflow, $user)]);
    }

    public function launch(string $id): void
    {
        $user = $this->requireUser('use');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        if ($workflow['published'] === null) {
            AjaxResponse::fail(t('Publiez le workflow avant de le lancer.'), 409);
            return;
        }
        if (!$workflow['is_active']) {
            AjaxResponse::fail(t('Ce workflow est en pause : réactivez-le pour le lancer.'), 409);
            return;
        }
        $values = is_array($_POST['values'] ?? null) ? $_POST['values'] : [];
        try {
            $files = $this->collectFiles($workflow['published']);
            // Workflow à évènement lancé à la main (un essai) : ce que la
            // personne a collé ou joint tient lieu de l'évènement.
            $eventFile = $this->collectFiles(['start' => ['fields' => [['key' => '__event', 'type' => 'file', 'label' => t('Fichier reçu')]]]])['__event'] ?? null;
            $event = WorkflowTriggers::manualEvent($workflow['published'], (string)($_POST['event_text'] ?? ''), $eventFile);
            $res = WorkflowEngine::launch($workflow, $user, $values, $files, ($_POST['trigger'] ?? '') === 'chat' ? 'chat' : 'manual', null, (string)($_POST['tz'] ?? ''), $event);
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::fail($e->getMessage(), 422);
            return;
        }
        if ($res === null) {
            AjaxResponse::fail(t('Requête invalide.'), 409);
            return;
        }
        $payload = ['ok' => true, 'url' => '/workflows/runs/' . $res['run_id'], 'run_id' => $res['run_id'], 'conversation_id' => $res['conversation_id']];
        $jobs = $res['job_ids'];
        if ($jobs === []) {
            AjaxResponse::send($payload);
            return;
        }
        JobQueue::respondAndDispatch($payload, array_shift($jobs));
        foreach ($jobs as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
    }

    /** Workflows lançables depuis le chat (« @ » dans la zone de saisie). */
    public function launchable(): void
    {
        $user = Session::currentUser();
        if (!Workflow::canUse($user)) {
            AjaxResponse::ok(['workflows' => []]);
            return;
        }
        $list = [];
        foreach (Workflow::launchableForUser((int)$user['id']) as $w) {
            $list[] = $this->workflowPayload($w) + ['form' => $this->launchForm($w, $user)];
        }
        AjaxResponse::ok(['workflows' => $list]);
    }

    // ==================================================================
    // Exécutions
    // ==================================================================

    public function run(string $id): void
    {
        $user = $this->requireUser('view');
        $run = $this->ownRun((int)$id, $user);
        $workflow = Workflow::findById($run['workflow_id']);
        $state = $this->runState($run);
        $catalog = WorkflowCatalog::forEditor(WorkflowCatalog::choices($user));
        $focusAction = (int)($_GET['action'] ?? 0);
        require ROOT . '/templates/workflows/run.php';
    }

    public function state(string $id): void
    {
        $user = $this->requireUser('view');
        $run = $this->ownRun((int)$id, $user);
        session_write_close();
        AjaxResponse::ok(['state' => $this->runState($run)]);
    }

    public function gate(string $id): void
    {
        $user = $this->requireUser('use');
        $run = $this->ownRun((int)$id, $user);
        $decision = (string)($_POST['decision'] ?? '');
        $remark = trim(mb_substr((string)($_POST['remark'] ?? ''), 0, 2000));
        if (!in_array($decision, ['approve', 'return', 'stop', 'route', 'none'], true)) {
            AjaxResponse::fail(t('Requête invalide.'), 400);
            return;
        }
        $step = $this->waitingGateStep($run);
        if ($step === null) {
            AjaxResponse::fail(t('Aucune validation n’attend votre décision.'), 409);
            return;
        }
        if (str_ends_with($step['node_key'], WorkflowEngine::LIST_SUFFIX) || str_ends_with($step['node_key'], WorkflowEngine::ACTIONS_SUFFIX)) {
            $this->loopGate($run, $user, $step, $decision);
            return;
        }
        $gate = WorkflowEngine::nodeFor($run['definition'], $step['node_key']);
        $isDoubt = WorkflowSpec::isBranching($gate ?? []);
        $isRoute = ($gate['type'] ?? '') === WorkflowSpec::TYPE_ROUTE;
        // Une décision : la personne choisit la voie (« route » + sa clé).
        $routeKey = trim((string)($_POST['route'] ?? ''));
        if ($decision === 'route' && (!$isRoute || !isset(WorkflowSpec::branchesOf($gate)[$routeKey]))) {
            AjaxResponse::fail(t('Choisissez une des voies proposées.'), 422);
            return;
        }
        if ($decision === 'return') {
            if ($isDoubt || empty($gate['can_return']) || WorkflowSpec::returnTarget($run['definition'], $gate) === null) {
                AjaxResponse::fail(t('Cette validation ne peut pas renvoyer d’étape.'), 422);
                return;
            }
            if ($remark === '') {
                AjaxResponse::fail(t('Dites ce qu’il faut changer : la remarque guide la nouvelle version.'), 422);
                return;
            }
            if (WorkflowRun::countOutcome($run['id'], $step['node_key'], 'returned') >= WorkflowSpec::MAX_RETURNS) {
                AjaxResponse::fail(t('Cette étape a déjà été renvoyée {n} fois : corrigez la pièce ou approuvez.', ['n' => WorkflowSpec::MAX_RETURNS]), 422);
                return;
            }
        }
        $convId = (int)$run['conversation_id'];
        $claimed = $step['pending_action_id'] !== null ? DrivePendingAction::claim($step['pending_action_id'], (int)$user['id'], $convId) : null;
        if ($claimed === null) {
            AjaxResponse::fail(t('Action introuvable, déjà traitée ou expirée.'), 409);
            return;
        }
        $title = (string)($gate['title'] ?? '');
        $outcome = match ($decision) {
            'approve' => 'approved',
            'return'  => 'returned',
            'route'   => 'route:' . $routeKey,
            default   => 'stopped',
        };
        DrivePendingAction::resolve($claimed['id'], $outcome === 'stopped' ? 'cancelled' : ($outcome === 'returned' ? 'cancelled' : 'executed'), $outcome === 'returned' ? 'Renvoyée à l’étape.' : null);
        if ($isRoute) {
            $chosen = $decision === 'route' ? $routeKey : (string)($step['detail']['suggested'] ?? '');
            $message = $outcome === 'stopped'
                ? '🚫 ' . t('Décision : arrêt du workflow.')
                : '✅ ' . t('Voie choisie : « {voie} » — le workflow continue.', ['voie' => WorkflowSpec::laneLabel($gate, $chosen)]);
        } else {
            $message = match ($outcome) {
                'approved' => $isDoubt ? '✅ ' . t('Réponse : oui — le workflow continue.') : '✅ ' . t('Validation approuvée : « {titre} » — le workflow continue.', ['titre' => $title]),
                'returned' => '↩ ' . t('Renvoyée à l’étape avec une remarque : {remarque}', ['remarque' => $remark]),
                default    => $isDoubt ? '🚫 ' . t('Réponse : non.') : '🚫 ' . t('Validation refusée : « {titre} ».', ['titre' => $title]),
            };
        }
        Message::create($convId, 'assistant', $message);
        Conversation::touch($convId);
        $jobs = WorkflowEngine::gateResolved($run, $step, $outcome, $remark);
        $payload = ['ok' => true, 'state' => $this->runState(WorkflowRun::find($run['id']) ?? $run)];
        if ($jobs === []) {
            AjaxResponse::send($payload);
            return;
        }
        JobQueue::respondAndDispatch($payload, array_shift($jobs));
        foreach ($jobs as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
    }

    public function retry(string $id): void
    {
        $user = $this->requireUser('use');
        $run = $this->ownRun((int)$id, $user);
        $quota = CreditQuota::status($user);
        if (CreditQuota::isExceeded($quota)) {
            AjaxResponse::fail(CreditQuota::refusal($quota)['message'], 429);
            return;
        }
        try {
            $jobs = WorkflowEngine::retry($run, !empty($_POST['latest']));
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::fail($e->getMessage(), 409);
            return;
        }
        $this->respondJobs($run, $jobs);
    }

    /**
     * Relecture d'une boucle depuis la page de l'exécution : la liste des
     * éléments (garder ceux cochés) ou les actions préparées (exécuter celles
     * cochées, en brouillon ou à l'envoi pour un email ; aucune ; arrêter).
     */
    private function loopGate(array $run, array $user, array $step, string $decision): void
    {
        $isList = str_ends_with($step['node_key'], WorkflowEngine::LIST_SUFFIX);
        if (!in_array($decision, $isList ? ['approve', 'stop'] : ['approve', 'none', 'stop'], true)) {
            AjaxResponse::fail(t('Requête invalide.'), 400);
            return;
        }
        $keep = null;
        if ($decision === 'approve' && isset($_POST['keep'])) {
            $keep = array_values(array_filter(array_map('intval', (array)$_POST['keep']), static fn(int $v): bool => $v > 0));
            if ($keep === []) {
                AjaxResponse::fail($isList ? t('Cochez au moins un élément, ou arrêtez le workflow.') : t('Cochez au moins une action, ou choisissez « N’en exécuter aucune ».'), 422);
                return;
            }
        }
        $convId = (int)$run['conversation_id'];
        $claimed = $step['pending_action_id'] !== null ? DrivePendingAction::claim($step['pending_action_id'], (int)$user['id'], $convId) : null;
        if ($claimed === null) {
            AjaxResponse::fail(t('Action introuvable, déjà traitée ou expirée.'), 409);
            return;
        }
        DrivePendingAction::resolve($claimed['id'], $decision === 'approve' ? 'executed' : 'cancelled');
        $outcome = match ($decision) { 'approve' => 'approved', 'none' => 'none', default => 'stopped' };
        $jobs = WorkflowEngine::loopGateResolved($run, $step, $outcome, $keep, ($_POST['mail_mode'] ?? '') === 'send' ? 'send' : 'approve');
        $payload = ['ok' => true, 'state' => $this->runState(WorkflowRun::find($run['id']) ?? $run)];
        if ($jobs === []) {
            AjaxResponse::send($payload);
            return;
        }
        JobQueue::respondAndDispatch($payload, array_shift($jobs));
        foreach ($jobs as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
    }

    /** « Refaire les éléments en échec » d'une boucle. */
    public function retryFailed(string $id, string $key): void
    {
        $user = $this->requireUser('use');
        $run = $this->ownRun((int)$id, $user);
        if (!preg_match('/^[a-z0-9_]{1,' . WorkflowSpec::KEY_MAX . '}$/', $key)) {
            AjaxResponse::fail(t('Requête invalide.'), 400);
            return;
        }
        $quota = CreditQuota::status($user);
        if (CreditQuota::isExceeded($quota)) {
            AjaxResponse::fail(CreditQuota::refusal($quota)['message'], 429);
            return;
        }
        try {
            $jobs = WorkflowEngine::retryFailedItems($run, $key);
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::fail($e->getMessage(), 409);
            return;
        }
        $this->respondJobs($run, $jobs);
    }

    /**
     * Fichier EXEMPLE d'une boucle, dans l'éditeur : ses onglets, ses
     * colonnes (avec un type deviné), son nombre de lignes et les premières ;
     * ou le nombre de slides, de pages, de sections. Rien n'est gardé : le
     * fichier est lu puis oublié.
     */
    public function loopSample(): void
    {
        $this->requireUser('edit');
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            AjaxResponse::fail(t('Choisissez un fichier exemple.'), 422);
            return;
        }
        if ((int)$file['size'] > LoopFiles::MAX_BYTES) {
            AjaxResponse::fail(t('« {nom} » est trop lourd pour être parcouru (25 Mo au plus).', ['nom' => (string)$file['name']]), 422);
            return;
        }
        $name = mb_substr((string)$file['name'], 0, 200);
        $tmp = (string)$file['tmp_name'];
        $mime = FileExtractor::detectMime($tmp, $name);
        $kind = (string)($_POST['kind'] ?? LoopSources::ROWS);
        try {
            AjaxResponse::ok(['sample' => self::sampleOf($kind, $tmp, $mime, $name, (string)($_POST['sheet'] ?? ''), (int)($_POST['header'] ?? 0))]);
        } catch (\RuntimeException $e) {
            AjaxResponse::fail($e->getMessage(), 422);
        }
    }

    /**
     * Ce qu'un fichier donnerait à une boucle : colonnes et lignes (rows), ou
     * le nombre et les premiers éléments (slides, pages, sections).
     */
    public static function sampleOf(string $kind, string $path, string $mime, string $name, string $sheet = '', int $header = 0): array
    {
        if ($kind === LoopSources::SLIDES) {
            $slides = LoopFiles::slides($path, $name);
            return ['kind' => $kind, 'name' => $name, 'count' => count($slides), 'labels' => array_slice(array_map(static fn($s) => $s['titre'], $slides), 0, 5)];
        }
        if ($kind === LoopSources::PAGES) {
            return ['kind' => $kind, 'name' => $name, 'count' => count(LoopFiles::pages($path, $name)), 'labels' => []];
        }
        if ($kind === LoopSources::SECTIONS) {
            $sections = LoopFiles::sectionsOf(LoopFiles::documentMarkdown($path, $mime, $name));
            return ['kind' => $kind, 'name' => $name, 'count' => count($sections), 'labels' => array_slice(array_column($sections, 'titre'), 0, 5)];
        }
        if (!LoopFiles::isSheet($mime, $name)) {
            throw new \RuntimeException(t('« {nom} » n’est pas un classeur : joignez un fichier Excel (.xlsx) ou CSV.', ['nom' => $name]));
        }
        $grid = LoopFiles::sheet($path, $mime, $name, $sheet, $header);
        $columns = [];
        $used = [];
        foreach ($grid['header'] as $i => $label) {
            $values = array_values(array_filter(array_map(static fn(array $r): string => (string)($r[$i] ?? ''), array_slice($grid['rows'], 0, 50)), static fn(string $v): bool => $v !== ''));
            $key = WorkflowSpec::slug($label) ?: 'colonne';
            $base = $key;
            $n = 2;
            while (isset($used[$key])) {
                $key = substr($base, 0, WorkflowSpec::KEY_MAX - 3) . '_' . $n++;
            }
            $used[$key] = true;
            $columns[] = ['key' => $key, 'label' => $label, 'type' => self::guessType($values), 'examples' => array_slice($values, 0, 3)];
        }
        return [
            'kind' => $kind, 'name' => $name, 'sheets' => $grid['sheets'], 'sheet' => $grid['sheet'], 'header_row' => $grid['header_row'],
            'columns' => $columns, 'count' => $grid['total'], 'rows' => array_slice($grid['rows'], 0, 5),
        ];
    }

    /** Type d'une colonne d'après ses valeurs : nombre, date, email, sinon texte. */
    public static function guessType(array $values): string
    {
        if ($values === []) {
            return 'text';
        }
        foreach (['email' => [WorkflowValues::class, 'email'], 'number' => [WorkflowValues::class, 'number'], 'date' => [WorkflowValues::class, 'date']] as $type => $fn) {
            $ok = true;
            foreach ($values as $v) {
                if ($fn($v) === null) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $type;
            }
        }
        return 'text';
    }

    /**
     * Aperçu au lancement : pour chaque boucle qui parcourt un fichier du
     * départ, ce que le fichier joint donnerait (éléments lus, gardés par le
     * filtre, traités sous le plafond) et le coût estimé avec ces nombres.
     * Rien n'est lancé ni gardé.
     */
    public function preview(string $id): void
    {
        $user = $this->requireUser('use');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        $def = $workflow['published'] ?? null;
        if ($def === null) {
            AjaxResponse::fail(t('Publiez le workflow avant de le lancer.'), 409);
            return;
        }
        $pieces = WorkflowSpec::pieces($def);
        $loops = [];
        $counts = [];
        foreach (self::countableLoops($def) as $n) {
            // Fichier déposé dans la fenêtre, ou joint depuis la zone de saisie du
            // chat (jeton de session : lu ici sans être consommé, le lancement le prendra).
            $path = $name = null;
            $token = trim((string)($_POST['token_' . $n['over']] ?? ''));
            if ($token !== '' && preg_match('/^[a-f0-9]{8,64}$/', $token)) {
                $data = Session::get('file_' . $token);
                if (is_array($data) && WorkflowPrompt::readableUpload((string)($data['path'] ?? ''))) {
                    $path = (string)$data['path'];
                    $name = (string)$data['name'];
                }
            }
            $file = $_FILES['file_' . $n['over']] ?? null;
            if ($path === null && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && (int)$file['size'] <= UPLOAD_MAX_SIZE) {
                $path = (string)$file['tmp_name'];
                $name = (string)$file['name'];
            }
            if ($path === null) {
                continue;
            }
            $name = mb_substr($name, 0, 200);
            $entry = ['key' => $n['key'], 'title' => $n['title'], 'file' => $name];
            try {
                $mime = FileExtractor::detectMime($path, $name);
                $content = ['name' => $name, 'type' => $mime, 'path' => $path, 'text' => ''];
                $split = LoopRunner::split($def, $n, [$n['over'] => ['content' => $content, 'form' => 'file']], $user, ['user_id' => (int)$user['id']]);
                $itemPiece = $pieces[$n['item']['key']];
                $filtered = WorkflowLoop::filterElements($n, $itemPiece, $split['elements']);
                $cap = min((int)$n['max'], Workflow::loopMax());
                $count = min($cap, count($filtered['kept']));
                $entry += [
                    'found' => count($split['elements']), 'kept' => count($filtered['kept']), 'count' => $count, 'cap' => $cap,
                    'ignored' => $filtered['ignored'],
                    'labels' => array_slice(array_map(static fn(array $e): string => WorkflowLoop::label((string)$e['label'], 60), $filtered['kept']), 0, 3),
                ];
                $counts[$n['key']] = $count;
            } catch (\RuntimeException $e) {
                $entry['error'] = $e->getMessage();
            }
            $loops[] = $entry;
        }
        AjaxResponse::ok([
            'loops' => $loops,
            'estimate' => WorkflowCatalog::estimate($def, $this->choicesFor($user, (int)$workflow['id']), $counts),
            'confirm_over' => WorkflowSpec::LOOP_CONFIRM_OVER,
        ]);
    }

    /**
     * Les boucles qu'on sait compter avant de lancer : celles qui parcourent un
     * fichier du départ (lignes, slides, pages, sections) — rien à demander à
     * un service ni à un modèle, la fenêtre de lancement dit combien de tours.
     */
    public static function countableLoops(array $def): array
    {
        $pieces = WorkflowSpec::pieces($def);
        $out = [];
        foreach ($def['nodes'] as $n) {
            if ($n['type'] !== WorkflowSpec::TYPE_LOOP || $n['mode'] !== WorkflowSpec::LOOP_EACH) {
                continue;
            }
            $over = $pieces[$n['over']] ?? null;
            if (in_array((string)($n['source']['kind'] ?? ''), [LoopSources::ROWS, LoopSources::SLIDES, LoopSources::PAGES, LoopSources::SECTIONS], true)
                && LoopSources::readsPiece($n['source']) && ($over['form'] ?? '') === 'file' && ($over['producer'] ?? '') === WorkflowSpec::START_KEY) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Ignorer la brique en échec et continuer. */
    public function skip(string $id): void
    {
        $user = $this->requireUser('use');
        $run = $this->ownRun((int)$id, $user);
        try {
            $jobs = WorkflowEngine::skip($run);
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::fail($e->getMessage(), 409);
            return;
        }
        $this->respondJobs($run, $jobs);
    }

    /** Refaire depuis une brique du rail (ou depuis le départ). */
    public function restart(string $id): void
    {
        $user = $this->requireUser('use');
        $run = $this->ownRun((int)$id, $user);
        $quota = CreditQuota::status($user);
        if (CreditQuota::isExceeded($quota)) {
            AjaxResponse::fail(CreditQuota::refusal($quota)['message'], 429);
            return;
        }
        $from = (string)($_POST['from'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,' . WorkflowSpec::KEY_MAX . '}$/', $from)) {
            AjaxResponse::fail(t('Requête invalide.'), 400);
            return;
        }
        try {
            $jobs = WorkflowEngine::restartFrom($run, $from, !empty($_POST['latest']));
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::fail($e->getMessage(), 409);
            return;
        }
        $this->respondJobs($run, $jobs);
    }

    /** Ne plus attendre : l'attente (ou le nouvel essai) reprend tout de suite. */
    public function resume(string $id): void
    {
        $user = $this->requireUser('use');
        $run = $this->ownRun((int)$id, $user);
        try {
            $jobs = WorkflowEngine::resumeNow($run);
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::fail($e->getMessage(), 409);
            return;
        }
        $this->respondJobs($run, $jobs);
    }

    // ==================================================================
    // Historique : les lancements d'un workflow et le journal de chacun
    // ==================================================================

    /**
     * La liste de l'historique des exécutions, rendue toute faite
     * (WorkflowJournal) : l'écran n'a aucun libellé à connaître. Filtres :
     * `workflow` (un des siens), `etat` (WorkflowJournal::GROUPS) ;
     * `before` = page suivante, `upto` = recharger jusqu'à ce lancement
     * (la liste déjà affichée, ou un lancement choisi plus ancien).
     */
    public function historyList(): void
    {
        $user = $this->requireUser('view');
        $userId = (int)$user['id'];
        session_write_close();
        $query = $this->historyQuery($userId);
        $before = (int)($_GET['before'] ?? 0);
        $data = $this->historyData($userId, $query['workflow'], $query['group'], $before, (int)($_GET['upto'] ?? 0));
        AjaxResponse::ok([
            'html'   => $this->partial('history-runs', $data + ['firstPage' => $before <= 0, 'query' => $query]),
            'more'   => $data['moreBefore'],
            'counts' => $data['groups'],
            'first'  => $data['items'][0]['id'] ?? null,
        ]);
    }

    /** Le journal d'un lancement : chaque brique, ce qu'elle a fait, ce qu'il en est advenu. */
    public function journal(string $id): void
    {
        $user = $this->requireUser('view');
        $run = $this->ownRun((int)$id, $user);
        session_write_close();
        $data = $this->journalData($run, (int)$user['id'], $user);
        AjaxResponse::ok([
            'html' => $this->partial('history-journal', $data),
            'run'  => ['id' => $run['id'], 'status' => $run['status'], 'tone' => $data['head']['tone'], 'label' => $data['head']['label']],
            'live' => self::isLive($run),
        ]);
    }

    /** Lancements chargés au plus d'un coup pour garder un lancement choisi dans la liste. */
    private const HISTORY_UPTO_MAX = 200;

    /**
     * Un lancement qui avance SEUL (le journal et la liste se mettent à jour) :
     * en cours, ou une attente qui reprendra d'elle-même. Celui qui attend une
     * décision ne bouge pas sans vous.
     */
    private static function isLive(array $run): bool
    {
        return $run['status'] === WorkflowRun::RUNNING
            || ($run['status'] === WorkflowRun::WAITING && WorkflowRun::timerAt($run['id']) !== null);
    }

    /**
     * Les filtres de l'historique lus dans l'adresse : un workflow de la
     * personne (sinon aucun), un groupe d'états connu (sinon « Tous »).
     *
     * @return array{workflow: ?int, group: string}
     */
    private function historyQuery(int $userId): array
    {
        $wf = (int)($_GET['workflow'] ?? 0);
        $group = (string)($_GET['etat'] ?? 'all');
        return [
            'workflow' => $wf > 0 && Workflow::findForUser($wf, $userId) !== null ? $wf : null,
            'group'    => isset(WorkflowJournal::GROUPS[$group]) ? $group : 'all',
        ];
    }

    /**
     * L'onglet « Historique des exécutions » au premier affichage : la liste
     * (filtrée par l'adresse), et le journal du lancement demandé (`run`) —
     * à défaut le plus récent. Tout est rendu côté serveur ;
     * workflow-history.js prend le relais (filtres, choix, suite, direct).
     */
    private function historyPage(array $user, array $workflows): array
    {
        $userId = (int)$user['id'];
        $query = $this->historyQuery($userId);
        $asked = (int)($_GET['run'] ?? 0);
        $run = $asked > 0 ? WorkflowRun::findForUser($asked, $userId) : null;
        // Le lancement demandé reste dans la liste, même s'il est loin.
        $upto = $run !== null
            && ($query['workflow'] === null || $query['workflow'] === $run['workflow_id'])
            && ($query['group'] === 'all' || WorkflowJournal::statusGroup((string)$run['status']) === $query['group'])
            ? $run['id'] : 0;
        $data = $this->historyData($userId, $query['workflow'], $query['group'], 0, $upto);
        $explicit = $run !== null;
        if ($run === null && $data['items'] !== []) {
            $run = WorkflowRun::findForUser((int)$data['items'][0]['id'], $userId);
        }
        $total = array_sum(WorkflowRun::historyCounts($userId, null));
        // Le choix du workflow : ceux qui ont des lancements (et celui de l'adresse, même sans).
        $options = array_values(array_filter($workflows, static fn(array $w): bool => (int)($w['run_count'] ?? 0) > 0 || $w['id'] === $query['workflow']));
        usort($options, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
        $scopedWorkflow = null;
        foreach ($workflows as $w) {
            if ($w['id'] === $query['workflow']) {
                $scopedWorkflow = $w;
            }
        }
        return [
            'query'    => $query,
            'data'     => $data,
            'total'    => $total,
            'options'  => array_map(static fn(array $w): array => [
                'id' => $w['id'], 'name' => (string)$w['name'], 'color' => (string)$w['color'], 'count' => (int)($w['run_count'] ?? 0),
            ], $options),
            'workflow' => $scopedWorkflow,
            'selected' => $run !== null ? $run['id'] : 0,
            'explicit' => $explicit,
            'journal'  => $run !== null ? $this->journalData($run, $userId, $user) : null,
            'live'     => $run !== null && self::isLive($run),
        ];
    }

    /**
     * Une page de lancements (tous les workflows de la personne, ou un seul),
     * leur décompte par état dans ce périmètre, et la suite éventuelle.
     */
    private function historyData(int $userId, ?int $workflowId, string $group, int $before, int $upto = 0): array
    {
        $statuses = WorkflowJournal::statusesOf($group);
        $limit = WorkflowJournal::PAGE;
        if ($upto > 0 && $before <= 0) {
            $limit = max($limit, min(self::HISTORY_UPTO_MAX, WorkflowRun::countHistoryFrom($userId, $workflowId, $statuses, $upto)));
        }
        $runs = WorkflowRun::history($userId, $workflowId, $limit + 1, $before > 0 ? $before : null, $statuses);
        $more = count($runs) > $limit;
        $runs = array_slice($runs, 0, $limit);
        $ids = array_column($runs, 'id');
        $steps = WorkflowRun::stepsForRuns($ids);
        $events = WorkflowRun::eventHeadlines($ids);
        $now = $this->now();
        $items = array_map(
            static fn(array $r): array => WorkflowJournal::summary(
                $r, $steps[$r['id']] ?? [], (int)($r['ordinal'] ?? 0),
                isset($r['workflow_latest']) && $r['workflow_latest'] !== null ? (int)$r['workflow_latest'] : null,
                $now, $events[$r['id']] ?? null
            ),
            $runs
        );
        $groups = ['all' => 0] + array_fill_keys(array_keys(WorkflowJournal::GROUPS), 0);
        foreach (WorkflowRun::historyCounts($userId, $workflowId) as $status => $n) {
            $groups[WorkflowJournal::statusGroup($status)] += $n;
            $groups['all'] += $n;
        }
        return [
            'items'      => $items,
            'groups'     => $groups,
            'moreBefore' => $more && $items !== [] ? (int)$items[count($items) - 1]['id'] : null,
            'scoped'     => $workflowId !== null,
        ];
    }

    /**
     * Ce que le journal lit : les passages, le dossier, la trace d'actions des
     * réponses, les cartes de la conversation (et leur sort), les noms des
     * assistants et des modèles — et, pour l'en-tête, le workflow (le
     * modifier, le relancer avec les mêmes données) et ce qui a été remis.
     */
    private function journalData(array $run, int $userId, ?array $user = null): array
    {
        $user ??= User::findById($userId);
        $run['timer_at'] = WorkflowRun::timerAt($run['id']);
        $steps = WorkflowRun::steps($run['id']);
        $workflow = Workflow::findById($run['workflow_id']);
        $latest = $workflow !== null && $workflow['published'] !== null ? (int)$workflow['published_version'] : null;
        $convId = (int)($run['conversation_id'] ?? 0);

        $messageIds = [];
        foreach ($steps as $s) {
            $messageIds[] = (int)($s['detail']['reply_id'] ?? 0);
            $messageIds[] = (int)($s['message_id'] ?? 0);
        }
        $actions = [];
        if ($convId > 0) {
            foreach (Message::actionsFor($convId, $messageIds) as $msgId => $trace) {
                $actions[$msgId] = ChatController::withActionIcons($trace);
            }
        }
        $profiles = [];
        foreach (WorkflowSpec::flatNodes($run['definition']) as $n) {
            $pid = (int)($n['profile_id'] ?? 0);
            if ($pid > 0 && !isset($profiles[$pid])) {
                $profiles[$pid] = (string)(Profile::findById($pid)['name'] ?? '');
            }
        }
        $artifacts = WorkflowRun::artifacts($run['id']);
        $ctx = [
            'artifacts' => $artifacts,
            'actions'   => $actions,
            'cards'     => $convId > 0 ? DrivePendingAction::journalForConversation($convId, $userId) : [],
            'profiles'  => array_filter($profiles, static fn(string $n): bool => $n !== ''),
            'llms'      => LLMFactory::available(),
            'image'     => static fn(array $c): ?string => WorkflowCreations::imagePath('image', $c) !== null ? (string)$c['url'] : null,
        ];
        $now = $this->now();
        $event = $artifacts[WorkflowSpec::EVENT_PIECE]['content'] ?? null;
        $wfInfo = null;
        $relaunch = null;
        if ($workflow !== null) {
            $run += ['workflow_name' => $workflow['name'], 'workflow_color' => $workflow['color']];
            $wfInfo = [
                'id'    => $workflow['id'],
                'name'  => (string)$workflow['name'],
                'color' => (string)$workflow['color'],
                'edit'  => Workflow::allows($user, 'edit') ? '/workflows/' . $workflow['id'] . '/edit' : null,
            ];
            // Relancer = la fenêtre de lancement, pré-remplie des valeurs de ce lancement.
            if ($workflow['published'] !== null && $workflow['is_active'] && Workflow::allows($user, 'use')) {
                $relaunch = [
                    'id'     => $workflow['id'],
                    'values' => WorkflowJournal::relaunchValues($workflow['published'], (array)$run['inputs']),
                ];
            }
        }
        return [
            'head'     => WorkflowJournal::head($run, $steps, WorkflowRun::ordinal($run), $latest, WorkflowRun::credits($run['conversation_id']), $now, is_array($event) ? $event : null),
            'entries'  => WorkflowJournal::entries($run, $steps, $ctx, $now),
            'wfInfo'   => $wfInfo,
            'relaunch' => $relaunch,
            'outputs'  => WorkflowJournal::outputs($run, $artifacts, $ctx),
        ];
    }

    /** Un morceau d'écran des workflows, rendu en chaîne (templates/workflows/partials). */
    private function partial(string $name, array $vars): string
    {
        $icons = WorkflowCatalog::icons();
        extract($vars, EXTR_SKIP);
        ob_start();
        require ROOT . '/templates/workflows/partials/' . $name . '.php';
        return (string)ob_get_clean();
    }

    private function respondJobs(array $run, array $jobs): void
    {
        $payload = ['ok' => true, 'state' => $this->runState(WorkflowRun::find($run['id']) ?? $run)];
        if ($jobs === []) {
            AjaxResponse::send($payload);
            return;
        }
        JobQueue::respondAndDispatch($payload, array_shift($jobs));
        foreach ($jobs as $jobId) {
            JobQueue::dispatch($jobId, false);
        }
    }

    /** L'adresse d'appel du workflow, créée au premier besoin ; `renew` en change (l'ancienne cesse de marcher). */
    public function hook(string $id): void
    {
        $user = $this->requireUser('edit');
        $workflow = $this->ownWorkflow((int)$id, $user, true);
        $token = WorkflowTriggers::hookToken($workflow, !empty($_POST['renew']));
        AjaxResponse::ok(['url' => WorkflowTriggers::hookUrl($token)]);
    }

    public function cancel(string $id): void
    {
        $user = $this->requireUser('use');
        $run = $this->ownRun((int)$id, $user);
        WorkflowEngine::cancel($run);
        AjaxResponse::ok(['state' => $this->runState(WorkflowRun::find($run['id']) ?? $run)]);
    }

    /** Corriger une pièce, quand une validation qui la relit attend la personne. */
    public function correctPiece(string $id, string $key): void
    {
        $user = $this->requireUser('use');
        $run = $this->ownRun((int)$id, $user);
        $step = $this->waitingGateStep($run);
        $gate = $step !== null ? WorkflowEngine::nodeFor($run['definition'], $step['node_key']) : null;
        [$base, $iter] = WorkflowLoop::splitIter($key);
        [, $gateIter] = $step !== null ? WorkflowLoop::splitIter($step['node_key']) : [null, null];
        if ($gate === null || empty($gate['can_correct']) || !in_array($base, (array)($gate['reviews'] ?? []), true)
            || ($iter !== null && $iter !== $gateIter)) {
            AjaxResponse::fail(t('Cette pièce ne se corrige qu’à la validation qui la relit.'), 409);
            return;
        }
        $pieces = WorkflowSpec::pieces($run['definition']);
        $artifacts = WorkflowRun::artifacts($run['id']);
        $piece = $pieces[$base] ?? null;
        $art = $artifacts[$key] ?? null;
        if ($piece === null || $art === null || !WorkflowSpec::isCorrectable($piece)) {
            AjaxResponse::fail(t('Cette pièce ne se corrige pas ici.'), 422);
            return;
        }
        $content = $art['content'];
        switch ($piece['form']) {
            case 'text':
                $content['text'] = trim((string)($_POST['text'] ?? ''));
                break;
            case 'list':
                $content['items'] = ArtifactContract::listItems("- " . implode("\n- ", array_filter(array_map('trim', explode("\n", (string)($_POST['items'] ?? ''))))));
                break;
            case 'table':
                $rows = ArtifactContract::tableRows((string)($_POST['table'] ?? ''));
                if ($rows === null) {
                    AjaxResponse::fail(t('Le tableau doit garder sa ligne d’en-tête et sa ligne de tirets.'), 422);
                    return;
                }
                $content['rows'] = $rows;
                break;
            case 'fiche':
                $posted = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];
                foreach ($piece['fields'] as $f) {
                    if (array_key_exists($f['key'], $posted)) {
                        $raw = trim((string)$posted[$f['key']]);
                        $content['fields'][$f['key']] = $raw === '' ? null : WorkflowValues::coerce($f['type'] === 'list' ? implode("\n", array_map('trim', explode("\n", $raw))) : $raw, $f['type']);
                    }
                }
                break;
            case 'document':
                $content['source'] = (string)($_POST['source'] ?? '');
                break;
        }
        // Corrigée, la pièce reste celle de son étape : ses repères sont
        // remplis comme à sa remise (WorkflowInserts::expandContent).
        $producer = WorkflowSpec::node($run['definition'], (string)$piece['producer']);
        if ($producer !== null) {
            $content = WorkflowInserts::expandContent($run['definition'], $producer, $piece, $content, $artifacts, $iter);
        }
        WorkflowRun::saveArtifact($run['id'], $key, $art['node_key'], $piece['name'], $piece['form'], $content, $art['provenance']);
        Message::create((int)$run['conversation_id'], 'assistant', '✏️ ' . t('Pièce corrigée : « {nom} ».', ['nom' => $piece['name']]));
        AjaxResponse::ok(['state' => $this->runState($run)]);
    }

    public function download(string $id, string $key): void
    {
        $user = $this->requireUser('view');
        $run = $this->ownRun((int)$id, $user);
        session_write_close();
        try {
            $file = WorkflowFiles::build($run, $key, (string)($_GET['format'] ?? ''));
        } catch (FileBuildException $e) {
            http_response_code($e->httpStatus());
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($e->toPayload(), JSON_UNESCAPED_UNICODE);
            return;
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo $e->getMessage();
            return;
        }
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: ' . FileNaming::contentDisposition($file['name']));
        header('Content-Length: ' . strlen($file['bytes']));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $file['bytes'];
    }

    // ==================================================================
    // Données des écrans
    // ==================================================================

    /** Contexte de « À régler » d'un workflow : les choix de la personne, et lui-même. */
    private function choicesFor(array $user, int $workflowId): array
    {
        $choices = WorkflowCatalog::choices($user);
        $choices['self_id'] = $workflowId;
        return $choices;
    }

    private function workflowPayload(array $w): array
    {
        $def = $w['published'] ?? $w['draft'];
        $event = $def['start']['event'] ?? null;
        $watch = Workflow::watchState($w);
        return [
            'id' => $w['id'],
            'name' => $w['name'],
            'color' => $w['color'],
            'published_version' => $w['published_version'],
            'published' => $w['published'] !== null,
            'draft_changed' => $w['draft_changed'],
            'is_active' => $w['is_active'],
            'schedule' => $def['start']['schedule'] !== null ? Recurrence::describe($def['start']['schedule']['rule']) : '',
            'next_at' => !empty($w['schedule_next_at']) ? $this->localDate((string)$w['schedule_next_at'], (string)($def['start']['schedule']['timezone'] ?? '')) : '',
            'objective' => $def['objective'],
            'silhouette' => WorkflowSpec::silhouette($def),
            'counts' => WorkflowSpec::counts($def),
            'edit_url' => '/workflows/' . $w['id'] . '/edit',
            'event' => $event['kind'] ?? '',
            'event_label' => WorkflowCatalog::eventSentence($event),
            // Surveillance d'une boîte ou d'un dossier : dernière vérification, panne.
            'watch' => WorkflowTriggers::watches($w['published']) ? [
                'checked' => !empty($watch['checked_at']) ? $this->localDate((string)$watch['checked_at'], (string)($event['timezone'] ?? '')) : '',
                'error' => (string)($watch['error'] ?? ''),
                'launched' => (int)($watch['launched'] ?? 0),
                'active' => $w['is_active'] && ($w['published']['start']['event'] ?? null) !== null,
            ] : null,
            'has_hook' => !empty($w['hook_token_hash']),
        ];
    }

    private function localDate(string $utc, string $tz): string
    {
        $d = ScheduledTask::fromSqlUtc($utc);
        if ($d === null) {
            return '';
        }
        return Format::shortDateTime($d->setTimezone(Recurrence::zone(ScheduledTask::normalizeTimezone($tz))));
    }

    /** Formulaire de lancement : les champs du départ et ce qui va se passer. */
    private function launchForm(array $workflow, array $user): array
    {
        $def = $workflow['published'];
        $choices = WorkflowCatalog::choices($user);
        $event = $def['start']['event'];
        return [
            'id' => $workflow['id'],
            'name' => $workflow['name'],
            'objective' => $def['objective'],
            'fields' => $def['start']['fields'],
            // Workflow à évènement lancé à la main : l'évènement se remplace
            // par un texte collé (email, données) ou un fichier joint.
            'event' => $event === null ? null : [
                'kind' => $event['kind'],
                'name' => (string)$event['piece_name'],
                'sentence' => WorkflowCatalog::eventSentence($event),
                'help' => match ($event['kind']) {
                    'email'   => t('Pour un essai : collez le texte d’un email. Sinon, le workflow démarre sans « {piece} ».', ['piece' => $event['piece_name']]),
                    'file'    => t('Pour un essai : joignez un fichier. Sinon, le workflow démarre sans « {piece} ».', ['piece' => $event['piece_name']]),
                    'webhook' => t('Pour un essai : collez les données (JSON ou texte). Sinon, le workflow démarre sans « {piece} ».', ['piece' => $event['piece_name']]),
                    default   => t('Pour un essai : collez le texte attendu. Sinon, le workflow démarre sans « {piece} ».', ['piece' => $event['piece_name']]),
                },
            ],
            'lines' => WorkflowDraft::railLines($def, $choices),
            'estimate' => WorkflowCatalog::estimate($def, $choices),
            'notify' => $def['notify'],
            'launch_url' => '/workflows/' . $workflow['id'] . '/launch',
            // Champs « fichier » qu'une boucle parcourt : la fenêtre les compte dès le dépôt.
            'preview_url' => '/workflows/' . $workflow['id'] . '/preview',
            'preview_fields' => array_values(array_unique(array_map(static fn(array $n): string => (string)$n['over'], self::countableLoops($def)))),
            'confirm_over' => WorkflowSpec::LOOP_CONFIRM_OVER,
            'accept' => FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES),
        ];
    }

    /** État d'une exécution pour la page (et son sondage). */
    private function runState(array $run): array
    {
        $def = $run['definition'];
        $pieces = WorkflowSpec::pieces($def);
        $all = WorkflowRun::artifacts($run['id']);
        $tz = Recurrence::zone(ScheduledTask::normalizeTimezone((string)$run['timezone']));
        $detailKeys = array_flip(['reason', 'lines', 'remark', 'why', 'mode', 'unsure', 'kind', 'auto', 'of', 'cause', 'i', 'count', 'total', 'rounds', 'done', 'skipped', 'iter', 'skipped_by', 'fallback', 'decided_by', 'tool', 'failed', 'discarded', 'found', 'phase', 'kept', 'approved', 'refused', 'results']);
        // Les actions proposées par chaque brique (et les livraisons) : leur
        // sort et leur contenu entier — l'email écrit, le message publié.
        $cards = (int)$run['conversation_id'] > 0 ? DrivePendingAction::journalForConversation((int)$run['conversation_id'], (int)$run['user_id']) : [];
        $names = array_map(static fn(array $a): string => (string)$a['name'], $all);
        $images = WorkflowJournal::imageUrls($all, ['image' => static fn(array $c): ?string => WorkflowCreations::imagePath('image', $c) !== null ? (string)$c['url'] : null]);
        $byMessage = [];
        foreach ($cards as $c) {
            if (!empty($c['message_id']) && $c['tool'] !== WorkflowEngine::GATE_TOOL) {
                $byMessage[(int)$c['message_id']][] = $c;
            }
        }
        $nodes = [];
        $steps = WorkflowRun::steps($run['id']);
        foreach ($steps as $s) {
            $entry = [
                'status' => $s['status'],
                'outcome' => $s['outcome'],
                'attempt' => $s['attempt'],
                'started' => $s['started_at'],
                'finished' => $s['finished_at'],
                'seconds' => $s['finished_at'] !== null ? max(0, strtotime((string)$s['finished_at']) - strtotime((string)$s['started_at'])) : null,
                'detail' => array_intersect_key($s['detail'], $detailKeys),
                'action_id' => $s['pending_action_id'],
                'message_id' => $s['message_id'],
            ];
            if (!empty($s['detail']['error']) && is_array($s['detail']['error'])) {
                $entry['detail']['error'] = array_intersect_key($s['detail']['error'], array_flip(['title', 'message']));
            }
            if ($s['resume_at'] !== null) {
                $at = ScheduledTask::fromSqlUtc((string)$s['resume_at']);
                $entry['resume'] = $at !== null ? Format::shortDateTime($at->setTimezone($tz)) : '';
                $entry['resume_in'] = $at !== null ? max(0, $at->getTimestamp() - time()) : 0;
            }
            if ($s['node_key'] === WorkflowSpec::END_KEY && !empty($s['detail']['deliveries'])) {
                $entry['deliveries'] = array_values(array_map(static fn($r): array => [
                    'label' => (string)($r['label'] ?? ''), 'status' => (string)($r['status'] ?? ''), 'error' => (string)($r['error'] ?? ''),
                    'cards' => array_values(array_map(
                        static fn($id): array => WorkflowJournal::cardLine($cards[(int)$id], $names, $images),
                        array_filter((array)($r['action_ids'] ?? []), static fn($id): bool => isset($cards[(int)$id]))
                    )),
                ], (array)$s['detail']['deliveries']));
            }
            $reply = (int)($s['detail']['reply_id'] ?? 0) ?: ($s['status'] !== WorkflowRun::STEP_RUNNING ? (int)($s['message_id'] ?? 0) : 0);
            if ($reply > 0 && isset($byMessage[$reply]) && $s['node_key'] !== WorkflowSpec::END_KEY) {
                $entry['cards'] = array_map(static fn(array $c): array => WorkflowJournal::cardLine($c, $names, $images), $byMessage[$reply]);
            }
            $nodes[$s['node_key']] = $entry;
            // Une brique de boucle : sa clé simple porte le dernier tour.
            [$base, $iter] = WorkflowLoop::splitIter($s['node_key']);
            if ($iter !== null) {
                $nodes[$base] = $entry + ['iter' => $iter];
            }
        }

        $out = [];
        $iterPieces = [];
        foreach ($all as $k => $a) {
            if (str_ends_with((string)$k, WorkflowEngine::ITEMS_SUFFIX)) {
                continue;
            }
            [$base, $iter] = WorkflowLoop::splitIter((string)$k);
            $p = $pieces[$base] ?? ['form' => $a['form'], 'name' => $a['name'], 'fields' => []];
            $item = [
                'key' => $k, 'name' => $a['name'], 'form' => $a['form'], 'node' => $a['node_key'], 'version' => $a['version'],
                'summary' => ArtifactContract::summary($p, $a['content']),
                'download' => '/workflows/runs/' . $run['id'] . '/pieces/' . rawurlencode((string)$k) . '/download',
                'provenance' => $a['provenance'],
            ];
            if ($a['form'] === 'fiche') {
                $item['fields'] = array_map(static fn($f) => [
                    'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'],
                    'value' => WorkflowValues::display($a['content']['fields'][$f['key']] ?? null, $f['type']),
                    'raw' => is_array($a['content']['fields'][$f['key']] ?? null) ? implode("\n", $a['content']['fields'][$f['key']]) : (string)($a['content']['fields'][$f['key']] ?? ''),
                ], $p['fields'] ?? []);
                if (trim((string)($a['content']['summary'] ?? '')) !== '') {
                    $item['text'] = (string)$a['content']['summary'];
                }
            } elseif ($a['form'] === 'file') {
                $item['file'] = ['name' => (string)($a['content']['name'] ?? ''), 'type' => (string)($a['content']['type'] ?? ''), 'url' => (string)($a['content']['url'] ?? '')];
                $item['text'] = mb_substr((string)($a['content']['text'] ?? ''), 0, 20000);
            } elseif (isset(WorkflowSpec::CREATE_KINDS[$a['form']])) {
                $item += self::creationItem($p, $a['content'], (int)$run['conversation_id']);
            } else {
                $item['text'] = ArtifactContract::render($p, $a['content'], 20000);
                if ($a['form'] === 'document') {
                    $item['format'] = (string)($a['content']['format'] ?? 'docx');
                    $item['source'] = (string)($a['content']['source'] ?? '');
                }
                if ($a['form'] === 'list') {
                    $item['items'] = (array)($a['content']['items'] ?? []);
                }
                if ($a['form'] === 'table') {
                    $item['rows'] = (array)($a['content']['rows'] ?? []);
                }
            }
            if ($iter !== null) {
                $item['iter'] = $iter;
                $item['base'] = $base;
                $iterPieces[$base][$iter] = $item;
                continue;
            }
            $out[$k] = $item;
        }

        // Boucles : où en est chacune, chaque élément (son issue, sa pièce).
        $loops = [];
        $terminalish = !in_array($run['status'], [WorkflowRun::RUNNING, WorkflowRun::WAITING], true);
        foreach ($def['nodes'] as $n) {
            if ($n['type'] !== WorkflowSpec::TYPE_LOOP) {
                continue;
            }
            $st = $nodes[$n['key']] ?? null;
            $loopStep = WorkflowRun::lastStep($run['id'], $n['key']);
            $ld = (array)($loopStep['detail'] ?? []);
            $itemsArt = (array)($all[$n['key'] . WorkflowEngine::ITEMS_SUFFIX]['content'] ?? []);
            $itemsList = (array)($itemsArt['items'] ?? []);
            $collect = WorkflowSpec::loopCollect($n);
            $each = $n['mode'] === WorkflowSpec::LOOP_EACH;
            $marks = (string)($ld['st'] ?? '');
            $fails = (array)($ld['fail'] ?? []);
            $turns = [];
            $last = (int)($ld['i'] ?? 0);
            $count = (int)($ld['count'] ?? 0);
            $upto = $each ? $count : $last;
            for ($i = 1; $i <= $upto; $i++) {
                $mark = $each ? ($marks[$i - 1] ?? '.') : 'd';
                $status = match (true) {
                    $mark === 'f' => 'failed',
                    $mark === 's' => 'skipped',
                    $mark === 'd' => 'done',
                    $i === $last && ($loopStep['status'] ?? '') === WorkflowRun::STEP_RUNNING => 'running',
                    default => 'pending',
                };
                $turns[] = [
                    'i' => $i,
                    'label' => $each ? WorkflowLoop::label((string)($itemsList[$i - 1] ?? ''), 90) : t('Tour {n}', ['n' => $i]),
                    'status' => $status,
                    'cause' => (string)($fails[(string)$i] ?? ''),
                    'piece' => $collect !== null ? ($iterPieces[$collect][$i] ?? null) : null,
                ];
            }
            $failed = substr_count($marks, 'f');
            $loops[$n['key']] = [
                'mode' => $n['mode'], 'i' => $last, 'count' => $count, 'total' => (int)($ld['total'] ?? 0),
                'found' => (int)($itemsArt['found'] ?? ($ld['found'] ?? 0)), 'ignored' => (array)($itemsArt['ignored'] ?? []),
                'notes' => (array)($itemsArt['notes'] ?? []), 'source' => (string)($itemsArt['source'] ?? ''),
                'max' => (int)$n['max'], 'rounds' => (array)($ld['rounds'] ?? []), 'turns' => $turns,
                'failed' => $failed, 'skipped' => substr_count($marks, 's'), 'done_count' => substr_count($marks, 'd'),
                'phase' => (string)($ld['phase'] ?? ''),
                'can_retry_failed' => $each && $failed > 0 && $terminalish,
            ];
        }

        $gateStep = in_array($run['status'], [WorkflowRun::WAITING], true) ? $this->waitingGateStep($run) : null;
        $gate = $gateStep !== null ? WorkflowEngine::nodeFor($def, $gateStep['node_key']) : null;
        // Le dossier tel que la validation le voit (celui de SON tour, dans une boucle).
        $gateView = WorkflowLoop::view($all, [], 0);
        if ($gateStep !== null) {
            [$gateBase, $gateIter] = WorkflowLoop::splitIter((string)$gateStep['node_key']);
            $gateLoop = $gateIter !== null ? WorkflowSpec::loopOf($def, (string)strtok($gateBase, '~')) : null;
            if ($gateLoop !== null) {
                $gateView = WorkflowLoop::view($all, WorkflowLoop::bodyPieceKeys($gateLoop), (int)$gateIter);
            }
        }
        $currentStep = WorkflowRun::lastStep($run['id'], (string)$run['current_key']);
        $timer = null;
        if ($currentStep !== null && $currentStep['status'] === WorkflowRun::STEP_WAITING && $currentStep['resume_at'] !== null) {
            $timer = [
                'kind' => (string)($currentStep['detail']['kind'] ?? 'wait'),
                'at' => $nodes[$run['current_key']]['resume'] ?? '',
                'in' => $nodes[$run['current_key']]['resume_in'] ?? 0,
                'auto' => (int)($currentStep['detail']['auto'] ?? 0),
                'of' => (int)($currentStep['detail']['of'] ?? 0),
                'cause' => (string)($currentStep['detail']['cause'] ?? ''),
            ];
        }
        [$curBase] = WorkflowLoop::splitIter((string)$run['current_key']);
        $curNode = WorkflowSpec::node($def, $curBase);
        $resumable = in_array($run['status'], WorkflowRun::RESUMABLE, true);
        $workflow = Workflow::findById($run['workflow_id']);
        $latest = null;
        if ($workflow !== null && $workflow['published'] !== null && (int)$workflow['published_version'] > (int)$run['version']) {
            $latest = (int)$workflow['published_version'];
        }
        // Points de reprise : le départ et les briques du rail déjà passées.
        $restart = [];
        if (!in_array($run['status'], [WorkflowRun::RUNNING, WorkflowRun::WAITING], true)) {
            $restart[] = ['key' => WorkflowSpec::START_KEY, 'title' => t('Depuis le départ')];
            foreach ($def['nodes'] as $n) {
                if (isset($nodes[$n['key']])) {
                    $restart[] = ['key' => $n['key'], 'title' => $n['title']];
                }
            }
        }
        return [
            'id' => $run['id'],
            'status' => $run['status'],
            'current' => $run['current_key'],
            'error' => $run['error'],
            'nodes' => $nodes,
            'pieces' => $out,
            'iter_pieces' => $iterPieces,
            'loops' => $loops,
            'timer' => $timer,
            'trigger' => WorkflowCatalog::triggerLabel((string)$run['trigger_kind']),
            'credits' => WorkflowRun::credits($run['conversation_id']),
            // Le mot de la fin cite le dossier : rendu ici, comme dans la conversation.
            'end_note' => WorkflowDeliveries::fill((string)($def['end']['note'] ?? ''), $pieces, WorkflowLoop::view($all, [], 0), false),
            'conversation_url' => $run['conversation_id'] ? '/chat/' . $run['conversation_id'] : null,
            'gate' => $gate === null ? null : [
                'key' => $gateStep['node_key'],
                'title' => $gate['title'],
                'doubt' => WorkflowSpec::isBranching($gate),
                'reason' => (string)($gateStep['detail']['reason'] ?? ''),
                'question' => match ($gate['type']) {
                    WorkflowSpec::TYPE_CONDITION => WorkflowSpec::renderMentions((string)$gate['question'], $pieces),
                    WorkflowSpec::TYPE_ROUTE     => WorkflowSpec::renderMentions((string)$gate['instruction'], $pieces),
                    default                      => '',
                },
                // Une décision : la personne choisit parmi toutes ses voies.
                'routes' => $gate['type'] === WorkflowSpec::TYPE_ROUTE ? array_map(static fn(array $r): array => [
                    'key' => $r['key'], 'label' => $r['label'], 'when' => WorkflowSpec::renderMentions((string)$r['when'], $pieces),
                ], $gate['routes']) : [],
                'suggested' => (string)($gateStep['detail']['suggested'] ?? ''),
                // Validation d'un tour de boucle : elle relit les pièces DE CE TOUR.
                'reviews' => self::gateReviews((array)($gate['reviews'] ?? []), $gateStep['node_key'], $iterPieces),
                'note' => WorkflowDeliveries::fill((string)($gate['note'] ?? ''), $pieces, $gateView, false),
                // « Corriger » ne vaut que pour une pièce qui se corrige.
                'can_correct' => !empty($gate['can_correct']) && array_filter(
                    (array)($gate['reviews'] ?? []),
                    static fn($k): bool => isset($pieces[$k]) && WorkflowSpec::isCorrectable($pieces[$k])
                ) !== [],
                'can_return' => !empty($gate['can_return']) && WorkflowSpec::returnTarget($def, $gate) !== null
                    && WorkflowRun::countOutcome($run['id'], $gateStep['node_key'], 'returned') < WorkflowSpec::MAX_RETURNS,
                'action_id' => $gateStep['pending_action_id'],
                // Relecture d'une boucle : la liste des éléments, ou les actions préparées.
                'loop_gate' => (string)($gate['loop_gate'] ?? ''),
                'elements' => ($gate['loop_gate'] ?? '') === 'list' ? array_map(static fn($l, $i): array => ['i' => $i + 1, 'label' => WorkflowLoop::label((string)$l, 120)],
                    (array)($all[($gate['loop'] ?? '') . WorkflowEngine::ITEMS_SUFFIX]['content']['items'] ?? []),
                    array_keys((array)($all[($gate['loop'] ?? '') . WorkflowEngine::ITEMS_SUFFIX]['content']['items'] ?? []))) : [],
                'actions' => ($gate['loop_gate'] ?? '') === 'actions' ? self::gateActions($cards, (array)($gateStep['detail']['actions'] ?? []),
                    (array)($gateStep['detail']['iters'] ?? []), (array)($all[($gate['loop'] ?? '') . WorkflowEngine::ITEMS_SUFFIX]['content']['items'] ?? []), $names, $images) : [],
            ],
            'waiting_cards' => $run['status'] === WorkflowRun::WAITING && $gateStep === null && $timer === null,
            'resumable' => $resumable,
            'can_skip' => $resumable && ($curBase === WorkflowSpec::END_KEY
                || in_array($curNode['type'] ?? '', [WorkflowSpec::TYPE_STEP, WorkflowSpec::TYPE_ACTION, WorkflowSpec::TYPE_CREATE, WorkflowSpec::TYPE_WAIT, WorkflowSpec::TYPE_NOTIFY], true)),
            'latest_version' => $latest,
            'version' => (int)$run['version'],
            'restart' => $restart,
            'terminal' => in_array($run['status'], WorkflowRun::TERMINAL, true),
        ];
    }

    /**
     * Ce que la page montre d'une création : l'image (elle est servie à la
     * session, comme dans le chat), le texte d'une page et son lien vers la
     * conversation, le tracé d'un itinéraire (SVG fait ici, sans tuile).
     */
    private static function creationItem(array $piece, array $content, int $convId): array
    {
        $item = ['text' => WorkflowCreations::render($piece, $content, 20000)];
        $convUrl = $convId > 0 ? '/chat/' . $convId : null;
        switch ($piece['form']) {
            case 'image':
                $exists = WorkflowCreations::imagePath('image', $content) !== null;
                $item['image'] = [
                    'url'    => $exists ? (string)$content['url'] : '',
                    'prompt' => trim((string)($content['revised_prompt'] ?? '') ?: (string)($content['prompt'] ?? '')),
                    'tool'   => (string)($content['tool'] ?? ''),
                ];
                break;
            case 'page':
                $item['page'] = ['title' => (string)($content['title'] ?? ''), 'tool' => (string)($content['tool'] ?? ''), 'conversation_url' => $convUrl];
                break;
            default:
                $spec = is_array($content['spec'] ?? null) ? $content['spec'] : [];
                $item['map'] = [
                    'title' => (string)($spec['title'] ?? ''),
                    'svg'   => RouteThumbnail::svg((array)($spec['waypoints'] ?? [])) ?? '',
                    'steps' => array_values(array_map(static fn($w): string => trim((string)(is_array($w) ? ($w['name'] ?? '') : '')), (array)($spec['waypoints'] ?? []))),
                    'tool'  => (string)($content['tool'] ?? ''),
                    'conversation_url' => $convUrl,
                ];
        }
        return $item;
    }

    /** Pièces relues par une validation, à la clé du tour pour une validation de boucle. */
    private static function gateReviews(array $reviews, string $gateKey, array $iterPieces): array
    {
        [, $iter] = WorkflowLoop::splitIter($gateKey);
        return array_values(array_map(
            static fn(string $k): string => $iter !== null && isset($iterPieces[$k][$iter]) ? WorkflowLoop::iterKey($k, $iter) : $k,
            $reviews
        ));
    }

    /**
     * Les actions préparées d'une relecture groupée, telles que leurs cartes
     * les montrent — contenu entier compris (l'email à relire avant de le
     * laisser partir).
     *
     * @param array $cards DrivePendingAction::journalForConversation
     */
    private static function gateActions(array $cards, array $ids, array $iters = [], array $items = [], array $names = [], array $images = []): array
    {
        $ids = array_flip(array_map('intval', $ids));
        $out = [];
        foreach ($cards as $card) {
            if (!isset($ids[$card['id']]) || $card['status'] !== 'pending' || $card['expired']) {
                continue;
            }
            $p = (array)$card['preview'];
            $out[] = [
                'id' => $card['id'],
                'action' => (string)($p['action'] ?? ''),
                'target' => (string)($p['fichier'] ?? ''),
                'subject' => is_array($p['mail'] ?? null) ? (string)($p['mail']['objet'] ?? '') : '',
                'preview' => mb_substr((string)(is_array($p['mail'] ?? null) ? ($p['mail']['corps'] ?? '') : ($p['apercu'] ?? '')), 0, 600),
                'to' => is_array($p['mail'] ?? null) ? (string)($p['mail']['destinataire'] ?? '') : '',
                'mail' => MailDraftReview::isMailTool((string)$card['tool']),
                // L'élément du tour qui l'a préparée (« Boulangerie Martin »).
                'element' => isset($iters[$card['id']]) ? WorkflowLoop::label((string)($items[(int)$iters[$card['id']] - 1] ?? ''), 80) : '',
                'view' => WorkflowContentView::action($card, $names, $images),
                'view_label' => WorkflowContentView::actionLabel((string)$card['tool']),
            ];
        }
        return $out;
    }

    private function waitingGateStep(array $run): ?array
    {
        $step = WorkflowRun::lastStep($run['id'], (string)$run['current_key']);
        return $step !== null && $step['status'] === WorkflowRun::STEP_WAITING && $step['pending_action_id'] !== null ? $step : null;
    }

    /** Plan de travail d'une réponse → définition (« Refaire en workflow »). */
    private function planFromMessage(int $messageId, int $userId): ?array
    {
        $msg = $messageId > 0 ? Message::findById($messageId) : null;
        if ($msg === null || $msg['role'] !== 'assistant' || !Conversation::belongsToUser((int)$msg['conversation_id'], $userId)) {
            return null;
        }
        $plan = (array)(Message::agentOf($msg)['plan'] ?? []);
        $steps = array_values(array_filter((array)($plan['etapes'] ?? $plan), static fn($e) => is_array($e) && trim((string)($e['titre'] ?? '')) !== ''));
        if ($steps === []) {
            return null;
        }
        // La demande qui a produit la réponse devient le champ de départ.
        $nodes = [];
        $prev = WorkflowSpec::START_PIECE;
        foreach (array_slice($steps, 0, WorkflowSpec::MAX_NODES) as $i => $e) {
            $title = mb_substr(trim((string)$e['titre']), 0, WorkflowSpec::TITLE_MAX);
            $key = 'resultat_' . ($i + 1);
            $nodes[] = [
                'key' => 'etape_' . ($i + 1), 'type' => 'step', 'title' => $title,
                'instruction' => $title . ($prev !== '' ? ' — ' . t('à partir de') . ' @{' . $prev . '}.' : ''),
                'output' => ['key' => $key, 'name' => t('Résultat {n}', ['n' => $i + 1]), 'form' => 'text'],
            ];
            $prev = $key;
        }
        $def = WorkflowSpec::normalize([
            'objective' => '',
            'start' => ['fiche_name' => t('Demande'), 'fields' => [['key' => 'demande', 'label' => t('Demande'), 'type' => 'longtext', 'required' => true]]],
            'nodes' => $nodes,
            'end' => ['pieces' => [$prev]],
        ]);
        return ['name' => t('Workflow tiré d’une conversation'), 'definition' => $def, 'steps' => count($nodes)];
    }

    private function createFromCardArgs(array $user, array $args): ?int
    {
        if (!is_array($args['definition'] ?? null) || Workflow::countForUser((int)$user['id']) >= Workflow::MAX_PER_USER) {
            return null;
        }
        $name = (string)($args['name'] ?? t('Nouveau workflow'));
        $def = WorkflowFit::apply($args['definition'], WorkflowCatalog::choices($user));
        return Workflow::create((int)$user['id'], $name, $def, null, WorkflowDraft::colorFor($name));
    }

    private function closeAdjustedCard(array $pending, int $userId, int $workflowId): void
    {
        $convId = (int)$pending['conversation_id'];
        $claimed = DrivePendingAction::claim((int)$pending['id'], $userId, $convId);
        if ($claimed === null) {
            return;
        }
        DrivePendingAction::resolve((int)$pending['id'], 'executed');
        $wf = Workflow::findById($workflowId);
        Message::create($convId, 'assistant', '✅ ' . t('Workflow ouvert dans l’éditeur : [« {nom} »]({url}). Relisez-le puis publiez-le pour le lancer.', [
            'nom' => (string)($wf['name'] ?? ''), 'url' => '/workflows/' . $workflowId . '/edit',
        ]));
        Conversation::touch($convId);
    }

    /**
     * Fichiers du formulaire de lancement : téléversés (`file_<clé>`) ou joints
     * dans la zone de saisie du chat (`token_<clé>`, jeton de session à usage
     * unique). Mêmes contrôles que les pièces jointes du chat.
     *
     * @return array<string, array{name: string, type: string, path: string, extracted: string, text: string}>
     * @throws \InvalidArgumentException
     */
    private function collectFiles(array $def): array
    {
        $out = [];
        foreach ($def['start']['fields'] as $f) {
            if ($f['type'] !== 'file') {
                continue;
            }
            $key = $f['key'];
            $token = trim((string)($_POST['token_' . $key] ?? ''));
            if ($token !== '' && preg_match('/^[a-f0-9]{8,64}$/', $token)) {
                $data = Session::get('file_' . $token);
                if (is_array($data) && WorkflowPrompt::readableUpload((string)($data['path'] ?? ''))) {
                    Session::remove('file_' . $token);
                    $out[$key] = self::fileEntry((string)$data['name'], (string)$data['type'], (string)$data['path'], (string)($data['extracted'] ?? ''));
                    continue;
                }
            }
            $file = $_FILES['file_' . $key] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException(t('Le fichier « {nom} » n’a pas pu être reçu.', ['nom' => $f['label']]));
            }
            if ((int)$file['size'] > UPLOAD_MAX_SIZE) {
                throw new \InvalidArgumentException(t('Le fichier « {nom} » est trop lourd.', ['nom' => $f['label']]));
            }
            $mime = FileExtractor::detectMime((string)$file['tmp_name'], (string)$file['name']);
            if (!in_array($mime, UPLOAD_ALLOWED_TYPES, true)) {
                throw new \InvalidArgumentException(t('Type de fichier non pris en charge pour « {nom} ».', ['nom' => $f['label']]));
            }
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)));
            $dest = UPLOAD_DIR . bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
            if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
                throw new \InvalidArgumentException(t('Le fichier « {nom} » n’a pas pu être enregistré.', ['nom' => $f['label']]));
            }
            $out[$key] = self::fileEntry((string)$file['name'], $mime, $dest, '');
        }
        return $out;
    }

    private static function fileEntry(string $name, string $type, string $path, string $extracted): array
    {
        $native = str_starts_with($type, 'image/') || $type === 'application/pdf';
        // Le texte sert aux étapes qui lisent la pièce sur un modèle sans
        // lecture native, et à l'aperçu ; images et PDF partent aussi tels quels.
        $text = $native ? (string)(FileExtractor::extract($path, $type) ?? '') : ($extracted !== '' ? $extracted : (string)(FileExtractor::extract($path, $type) ?? ''));
        return ['name' => mb_substr($name, 0, 200), 'type' => $type, 'path' => $path, 'extracted' => $native ? '' : $text, 'text' => mb_substr($text, 0, 400000)];
    }

    // ==================================================================
    // Accès
    // ==================================================================

    /**
     * Compte connecté, à qui son rôle donne ce droit sur ses workflows
     * (`view`, `use`, `create`, `edit`, `delete` — Personnes → Rôles),
     * fonctionnalité proposée par l'administrateur. Sinon 403.
     */
    /**
     * Assistants montrés au pied des vignettes (ceux que la personne peut
     * ouvrir ; un assistant devenu inaccessible n'y paraît pas). Décor :
     * une panne laisse les vignettes sans avatars, jamais la page en erreur.
     *
     * @return array<int, array{name: string, avatar: string, color: string}>
     */
    private function coverProfiles(array $user): array
    {
        $out = [];
        try {
            $scope = UserGroup::membershipScopeForUser((int)$user['id']);
            foreach (Profile::accessibleByUser((int)$user['id'], (string)$user['role'], $scope['active_group_ids'], $scope['has_assignments']) as $p) {
                $avatar = trim((string)($p['avatar'] ?? ''));
                $color  = (string)($p['color'] ?? '');
                $out[(int)$p['id']] = [
                    'name'   => (string)$p['name'],
                    'avatar' => $avatar !== '' ? '/assets/img/profils/' . rawurlencode(basename($avatar)) : '',
                    'color'  => preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Workflows] assistants des vignettes : ' . $e->getMessage());
        }
        return $out;
    }

    private function requireUser(string $action = 'view'): array
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();
        $user = Session::currentUser();
        if (!Workflow::allows($user, $action)) {
            if (AjaxResponse::wanted() || $_SERVER['REQUEST_METHOD'] === 'POST') {
                AjaxResponse::fail(t('Les workflows ne vous sont pas ouverts.'), 403);
                exit;
            }
            http_response_code(403);
            require ROOT . '/templates/403.php';
            exit;
        }
        return $user;
    }

    private function ownWorkflow(int $id, array $user, bool $json = false): array
    {
        $wf = Workflow::findForUser($id, (int)$user['id']);
        if ($wf === null) {
            if ($json || AjaxResponse::wanted()) {
                AjaxResponse::fail(t('Workflow introuvable.'), 404);
                exit;
            }
            http_response_code(404);
            require ROOT . '/templates/404.php';
            exit;
        }
        return $wf;
    }

    private function ownRun(int $id, array $user): array
    {
        $run = WorkflowRun::findForUser($id, (int)$user['id']);
        if ($run === null) {
            if (AjaxResponse::wanted() || $_SERVER['REQUEST_METHOD'] === 'POST') {
                AjaxResponse::fail(t('Exécution introuvable.'), 404);
                exit;
            }
            http_response_code(404);
            require ROOT . '/templates/404.php';
            exit;
        }
        return $run;
    }

    private function failOr(string $url, string $message, int $status = 422): void
    {
        if (AjaxResponse::wanted()) {
            AjaxResponse::fail($message, $status);
            return;
        }
        $this->flash('workflows_error', $message);
        header('Location: ' . $url);
        exit;
    }

    private function flash(string $key, string $message): void
    {
        Session::set($key, $message);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
