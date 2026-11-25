<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage des workflows — cf. CLAUDE.md « Workflows ». Garde : chaque classe
 * est chargée (pas d'autoloader : un oubli ne casse qu'en production, dans le
 * worker), les routes littérales précèdent les routes paramétrées, une étape
 * passe par le tour de chat ordinaire, la file de jobs prévient le moteur,
 * l'administration peut tout couper, et le rail se dessine (exécuté sous node).
 */
final class WorkflowContractTest extends TestCase
{
    private static function src(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    private static function methode(string $code, string $nom): string
    {
        $debut = strpos($code, 'function ' . $nom . '(');
        self::assertIsInt($debut, "Méthode absente : $nom");
        $suite = strpos($code, "\n    }\n", $debut);
        return substr($code, $debut, ($suite === false ? strlen($code) : $suite) - $debut);
    }

    public function testChaqueClasseEstChargeeDansLesDeuxListes(): void
    {
        $classes = self::src('config/classes.php');
        $boot = self::src('tests/bootstrap.php');
        $fichiers = array_map(static fn(string $f): string => '/src/Workflow/' . basename($f), glob(dirname(__DIR__, 2) . '/src/Workflow/*.php') ?: []);
        $fichiers = array_merge($fichiers, ['/src/Models/Workflow.php', '/src/Models/WorkflowRun.php', '/src/Jobs/WorkflowJob.php', '/src/Agent/Tools/WorkflowTools.php']);
        $this->assertGreaterThanOrEqual(10, count($fichiers));
        foreach ($fichiers as $f) {
            $this->assertStringContainsString("require ROOT . '" . $f . "';", $classes, "config/classes.php : $f");
            $this->assertStringContainsString("'" . $f . "'", $boot, "tests/bootstrap.php : $f");
        }
        $this->assertStringContainsString("require ROOT . '/src/Controllers/WorkflowController.php';", $classes);
        // Le moteur s'appuie sur le modèle et la spécification : chargés avant lui.
        $this->assertLessThan(strpos($classes, '/src/Workflow/WorkflowEngine.php'), strpos($classes, '/src/Workflow/WorkflowSpec.php'));
        $this->assertLessThan(strpos($classes, '/src/Workflow/WorkflowEngine.php'), strpos($classes, '/src/Models/WorkflowRun.php'));
    }

    public function testLesRoutesLitteralesPrecedentLesRoutesParametrees(): void
    {
        $routes = self::src('public/index.php');
        $premiereParametree = strpos($routes, "'/workflows/{id}/");
        $this->assertIsInt($premiereParametree);
        foreach (["get('/workflows/new'", "get('/workflows/launchable'", "get('/workflows/runs/{id}'", "post('/workflows/runs/{id}/gate'"] as $litterale) {
            $p = strpos($routes, $litterale);
            $this->assertIsInt($p, $litterale);
            $this->assertLessThan($premiereParametree, $p, "$litterale avant /workflows/{id}/…");
        }
        foreach (['edit', 'form', 'draft', 'publish', 'toggle', 'delete', 'duplicate', 'launch'] as $action) {
            $this->assertStringContainsString("'/workflows/{id}/$action'", $routes);
        }
    }

    public function testLaMigrationCreeLesQuatreTables(): void
    {
        $sql = self::src('database/migrations/088_workflows.sql');
        foreach (['workflows', 'workflow_runs', 'workflow_run_steps', 'workflow_artifacts'] as $table) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $table", $sql);
        }
        $this->assertStringContainsString('UNIQUE KEY', $sql);
        $this->assertStringContainsString('workflow_run_id', $sql);
        $this->assertStringNotContainsStringIgnoringCase(' manual ', $sql, '« manual » est réservé en MySQL 8.4.');
        $this->assertSame([], glob(dirname(__DIR__, 2) . '/database/migrations/088_*.sql') === false ? [] : array_values(array_filter(
            glob(dirname(__DIR__, 2) . '/database/migrations/088_*.sql'),
            static fn(string $f): bool => basename($f) !== '088_workflows.sql'
        )), 'Un seul fichier 088.');
    }

    public function testUneEtapeEstUnTourDeChatOrdinaire(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $run = self::methode($chat, 'runSend');
        $this->assertStringContainsString("\$workflowStep", $run);
        $this->assertMatchesRegularExpression('/\$unattended\s+= \$scheduled !== null \|\| \$workflowStep !== null;/', $run);
        $this->assertStringContainsString('WorkflowPrompt::isolatedHistory($history, $userMsgId)', $run);
        $this->assertStringContainsString('WorkflowPrompt::actionMode($workflowStep, Workflow::autoAllowed())', $run);
        $this->assertStringContainsString('WorkflowPrompt::modeNote(', $run);
        // Une étape ne prépare ni tâche, ni assistant, ni workflow.
        // Et seulement pour qui peut créer des workflows (Personnes → Rôles).
        $this->assertStringContainsString("\$workflowRequest = \$supportsTools && !\$unattended && Workflow::allows(\$user, 'create')", $run);

        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString("JobQueue::enqueue('chat'", $engine);
        $this->assertStringContainsString("\$payload['workflow'] = [", $engine);
        $this->assertStringContainsString('Scheduler::ACCESS_FLAGS', $engine);
        // L'administration coupe : l'étape suivante se met en pause.
        $this->assertStringContainsString('if (!Workflow::canUse($user)) {', self::methode($engine, 'startWork'));
    }

    public function testLesMessagesEcritsAuNomDeLaPersonneSontEnTexteSimple(): void
    {
        // Le chat n'interprète pas le Markdown d'un message utilisateur : des
        // astérisques s'y liraient en clair (constaté le 2026-10-16).
        I18n::use('fr');
        $def = WorkflowSpec::normalize([
            'objective' => 'Une réponse complète.',
            'start' => ['fiche_name' => 'Dossier', 'fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'urgent', 'label' => 'Urgent', 'type' => 'yesno'],
            ]],
            'nodes' => [['key' => 'redac', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'Réponds à @{depart}.',
                'output' => ['key' => 'reponse', 'name' => 'Réponse', 'form' => 'text']]],
            'end' => ['pieces' => ['reponse']],
        ]);
        $lancement = WorkflowEngine::launchMessage(['name' => 'Appel d’offres'], $def,
            ['fields' => ['client' => 'Ville de Lyon', 'urgent' => true]], 'email', ['headline' => "« Devis »\n— Paul"]);
        $this->assertSame("▶ Appel d’offres — Lancé par un email reçu\n\nUne réponse complète.\n\n- Client : Ville de Lyon\n- Urgent : oui\n\n« Devis » — Paul", $lancement);

        $etape = WorkflowEngine::stepMessage('Étape', 'Rédiger', 'Réponds à @{depart}.', WorkflowSpec::pieces($def), 'Plus court.', 'tour 2 sur 3');
        $this->assertSame("Étape — Rédiger · tour 2 sur 3\n\nRéponds à « Dossier ».\n\n↩ Remarque de la relecture : Plus court.", $etape);
        $this->assertSame("Création — Affiche\n\nUne affiche.", WorkflowEngine::stepMessage('Création', 'Affiche', 'Une affiche.', []));

        foreach ([$lancement, $etape] as $message) {
            $this->assertStringNotContainsString('**', $message);
            $this->assertDoesNotMatchRegularExpression('/^(?:> |_)/m', $message, 'Ni citation ni italique.');
        }

        // Les trois écritures (lancement, étape ou action, création) passent par ces deux formes.
        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString('self::launchMessage($workflow, $def, $inputs, $trigger, $event, ', self::methode($engine, 'launch'));
        foreach (['startWork', 'startCreate'] as $m) {
            $this->assertStringContainsString('self::stepMessage(', self::methode($engine, $m), $m);
        }
        foreach (['launchMessage', 'stepMessage', 'startWork', 'startCreate'] as $m) {
            $this->assertStringNotContainsString("'**", self::methode($engine, $m), "$m : aucun Markdown dans un message de la personne.");
        }
    }

    public function testLaFileDeJobsEtLeWorkerPreviennentLeMoteur(): void
    {
        $queue = self::src('src/Jobs/JobQueue.php');
        $this->assertStringContainsString('WorkflowEngine::onJobFinished($jobId, $status, $result, $error);', $queue);
        $this->assertStringContainsString("'workflow'     => new WorkflowJob(),", $queue);
        $this->assertStringContainsString('WorkflowEngine::tick();', $queue);
        $this->assertStringContainsString('WorkflowEngine::maintain();', $queue);
        $this->assertMatchesRegularExpression("/NON_IDEMPOTENT = \\[[^\\]]*'workflow'/", $queue, 'Une question rejouée re-facturerait : pas de rejeu.');

        $worker = self::src('scripts/worker.php');
        $this->assertStringContainsString('WorkflowEngine::tick()', $worker);
        $this->assertStringContainsString('WorkflowEngine::maintain()', $worker);
    }

    public function testLesCartesTrancheesFontRepartirLeRail(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString('WorkflowEngine::onCardDecided($convId, $actionId, true);', self::methode($chat, 'driveAction'));
        $this->assertStringContainsString('WorkflowEngine::onCardDecided($convId, $actionId);', self::methode($chat, 'driveActionsBatch'));
        $decide = self::methode($chat, 'decideAction');
        $this->assertStringContainsString('WorkflowEngine::GATE_TOOL', $decide);
        $this->assertStringContainsString('$this->approveWorkflowDraft(', $decide);
        $draft = self::methode($chat, 'approveWorkflowDraft');
        $this->assertStringContainsString('Workflow::create(', $draft);
        // Publié seulement s'il ne démarre qu'à la demande et que rien ne bloque.
        $this->assertSame(1, substr_count($draft, 'Workflow::publish('));
        $this->assertStringContainsString("if (\$plan['mode'] === WorkflowDraft::PLAN_PUBLISH && Workflow::allows(\$user, 'edit')) {", $draft);
    }

    public function testLAdministrationEtLaSauvegarde(): void
    {
        require_once ROOT . '/src/Controllers/AdminController.php';
        $this->assertContains(Workflow::SETTING_ENABLED, AdminController::BUILTIN_TOOL_KEYS);
        $this->assertContains(Workflow::SETTING_AUTO, AdminController::BUILTIN_TOOL_KEYS);
        $tools = self::src('templates/admin/tools.php');
        $this->assertStringContainsString('id="builtinWorkflowsCard"', $tools);
        $this->assertStringContainsString('data-builtin-key="<?= htmlspecialchars(Workflow::SETTING_AUTO) ?>"', $tools);

        foreach (['workflows', 'workflow_runs', 'workflow_run_steps', 'workflow_artifacts'] as $table) {
            $this->assertContains($table, BackupService::EXCLUDED_TABLES, "Sauvegarde : $table");
        }
        $this->assertSame(UsageSource::CAT_CHAT_ASSIST, UsageSource::category(UsageSource::WORKFLOW_JUDGE));
        $this->assertSame(UsageSource::CAT_DESIGN, UsageSource::category(UsageSource::WORKFLOW_COMPOSE));
        $this->assertFalse(UsageSource::isQuotaExempt(UsageSource::WORKFLOW_JUDGE), 'Dans le quota de la personne.');
    }

    public function testLaNavigationEtLeChat(): void
    {
        $this->assertSame('/workflows', NavRegistry::WORKSPACE['workflows']['href']);
        // Fonctionnalité proposée ET droit du rôle « Workflows — Voir ».
        $this->assertStringContainsString("'workflows' => Workflow::allows(\$user, 'view'),", self::src('src/Helpers/NavRegistry.php'));
        $this->assertContains('workflows', NavShell::PAGES, 'La page garde le volet du chat.');

        $chatTpl = self::src('templates/chat/index.php');
        $this->assertStringContainsString('data-workflow-id="<?= (int)$w[\'id\'] ?>"', $chatTpl, '« @ » propose les workflows lançables.');
        $this->assertStringContainsString('/assets/js/workflows.js', $chatTpl);
        $this->assertStringContainsString('window.WORKFLOWS_USABLE', $chatTpl);

        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString('async function launchWorkflowFromChat(workflowId)', $js);
        $this->assertStringContainsString("files[f.key] = { token: pf.token, name: pf.name };", $js, 'Les fichiers joints deviennent les fichiers de départ.');
        $this->assertStringContainsString('function workflowRailHtml(rail)', $js);
        $this->assertStringContainsString("'/workflows/new?from='", $js);
        $this->assertStringContainsString('href="/workflows/new?from=<?= (int)$msg[\'id\'] ?>"', self::src('templates/partials/message.php'));
    }

    public function testLeRailSeDessine(): void
    {
        $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node indisponible.');
        }
        I18n::use('fr');
        $tpl = WorkflowTemplates::get('facture');
        $def = WorkflowSpec::normalize($tpl['definition']);
        $cat = ['icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES, 'labels' => WorkflowCatalog::labels(), 'colors' => WorkflowCatalog::FORM_COLORS];
        $script = "'use strict';\n" . self::src('public/assets/js/workflow-rail.js') . "\n"
            . 'const R = globalThis.MiaWorkflowRail;' . "\n"
            . 'const def = ' . json_encode($def) . ";\n"
            . 'const cat = ' . json_encode($cat) . ";\n"
            . 'const first = def.nodes[0].key;' . "\n"
            . 'const out = { edit: R.html(def, { catalog: cat, editable: true, selected: first }),'
            . ' run: R.html(def, { catalog: cat, state: { status: "waiting", current: first, terminal: false, nodes: { [first]: { status: "waiting" } }, pieces: {} } }),'
            . ' avail: Object.keys(R.available(def, "end")), pieces: R.pieces(def, cat.colors).map(p => p.key),'
            . ' sil: R.silhouette("dscgxe", cat.hues),'
            . ' ava: R.avatar({ name: "Veille", avatar: "/assets/img/profils/x.png", color: "#6366f1" }, cat), mia: R.avatar(null, cat) };' . "\n"
            . 'process.stdout.write(JSON.stringify(out));';
        $fichier = tempnam(sys_get_temp_dir(), 'mia-rail-');
        file_put_contents($fichier, $script);
        try {
            $sortie = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier) . ' 2>&1');
        } finally {
            @unlink($fichier);
        }
        $r = json_decode($sortie, true);
        $this->assertIsArray($r, "workflow-rail.js a échoué sous node :\n" . $sortie);

        $this->assertStringContainsString('class="wf-rail is-editable', $r['edit']);
        $this->assertStringContainsString('data-node="start"', $r['edit']);
        $this->assertStringContainsString('data-node="end"', $r['edit']);
        foreach (WorkflowSpec::flatNodes($def) as $n) {
            $this->assertStringContainsString('data-node="' . $n['key'] . '"', $r['edit'], "Brique dessinée : {$n['key']}");
        }
        $this->assertStringContainsString('data-add="main"', $r['edit'], 'Le « + » entre deux briques.');
        $this->assertStringContainsString('class="wf-fork wf-fork-condition"', $r['edit'], 'Une condition ouvre deux voies.');
        $this->assertStringContainsString('class="wf-brick wf-k-step', $r['edit'], 'Une brique = une carte d’une ligne.');
        $this->assertStringContainsString('class="wf-dot"', $r['edit'], 'Les pièces lues : des points de leur couleur.');
        $this->assertStringContainsString('class="wf-out', $r['edit'], 'La pièce remise : une puce au bout de la ligne.');
        $this->assertStringNotContainsString('wf-ticket', $r['edit'], 'Plus de ticket pendu sous la carte : le détail est dans le volet.');
        $this->assertStringContainsString('is-sel', $r['edit'], 'La brique ouverte dans le volet est signalée.');
        $this->assertStringNotContainsString('data-add=', $r['run'], 'L’exécution ne s’édite pas.');
        $this->assertStringNotContainsString('data-tool=', $r['run'], 'Ni monter, ni dupliquer, ni supprimer pendant une exécution.');
        $this->assertStringContainsString('class="wf-ava', $r['ava'], 'Un assistant se montre par son avatar.');
        $this->assertStringContainsString('<img src="/assets/img/profils/x.png"', $r['ava']);
        $this->assertStringContainsString('wf-ava mia', $r['mia'], 'Sans assistant : MIA.');
        $this->assertStringContainsString('st-waiting', $r['run'], 'La brique qui attend est signalée.');
        $this->assertSame(array_keys(WorkflowSpec::pieces($def)), $r['pieces'], 'Mêmes pièces côté navigateur et côté serveur.');
        $this->assertSame(array_keys(WorkflowSpec::available($def, WorkflowSpec::END_KEY)), $r['avail'], 'Même disponibilité des deux côtés.');
        $this->assertSame(6, substr_count($r['sil'], '<i '), 'Un point par brique.');
    }

    /**
     * L'écran se lit d'un coup d'œil : le rail ne porte que des lignes, et ce
     * qui se règle (éditeur) ou se détaille (exécution) s'ouvre dans un VOLET.
     */
    public function testLeDetailEtLesReglagesSOuvrentDansUnVolet(): void
    {
        $edit = self::src('templates/workflows/edit.php');
        $this->assertStringContainsString('class="wf-drawer wf-ins" id="wfInspector"', $edit);
        $this->assertStringContainsString('id="wfScrim"', $edit);
        $this->assertStringContainsString('id="wfOverview"', $edit, 'Réglages du workflow : un bouton, pas une colonne.');
        $run = self::src('templates/workflows/run.php');
        $this->assertStringContainsString('class="wf-drawer wf-ins" id="wfRunDrawer"', $run);
        $this->assertStringContainsString('id="wfRunBanner"', $run, 'Ce qui vous attend, en tête.');
        $this->assertStringContainsString('id="wfRunDossier"', $run);
        $this->assertStringNotContainsString('wfRunSide', $run);

        $ed = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('function setDrawer(open)', $ed);
        $js = self::src('public/assets/js/workflow-run.js');
        $this->assertStringContainsString('function setPanel(p, focusPiece)', $js);
        $this->assertStringContainsString('function nodePanel(key)', $js);
        $this->assertStringContainsString('function dossierPanel()', $js);
        $this->assertStringContainsString("T('Qui fait le travail')", $js, 'Le détail d’une étape dit qui l’a faite.');
        $this->assertStringNotContainsString("'#wfRunSide'", $js);

        $css = self::src('public/assets/css/workflows.css');
        $this->assertStringContainsString('.wf-drawer.is-open', $css);
        $this->assertStringContainsString('body.wf-drawer-on .wf-scrim', $css);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1200px\)[^}]*\{[^@]*body\.wf-drawer-on \.wf-content > \.wf-run-head/s', $css,
            'Au-delà de 1 200 px le volet décale la page au lieu de la recouvrir — en-tête de l’exécution compris.');
        $this->assertStringNotContainsString('.wf-run-body', $css, 'Plus de colonne latérale fixe.');
    }

    /** Les workflows sont en bêta : on le dit partout où l'on y entre. */
    public function testLesEntreesWorkflowsPortentLaPastilleBeta(): void
    {
        I18n::use('fr');
        $chip = Workflow::betaChip();
        $this->assertStringContainsString('class="beta-chip"', $chip);
        $this->assertStringContainsString('>Bêta<', $chip);
        $this->assertStringContainsString('title="Fonctionnalité en phase de test', $chip);
        foreach (['templates/partials/sidebar-shortcuts.php', 'templates/workflows/index.php'] as $f) {
            $this->assertStringContainsString('Workflow::betaChip()', self::src($f), "$f : pastille Bêta sur l'entrée Workflows");
        }
        $this->assertTrue(NavRegistry::WORKSPACE['workflows']['beta'] ?? false, 'La tuile Workflows porte la pastille.');
        $chat = self::src('templates/partials/sidebar-shortcuts.php');
        $this->assertStringContainsString("<?php if (!empty(\$sbItem['beta'])): ?><?= Workflow::betaChip() ?><?php endif; ?>", $chat);
        $css = self::src('public/assets/css/main.css');
        $this->assertStringContainsString('.beta-chip {', $css);
        $this->assertStringContainsString('.space-tile .beta-chip {', $css);
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        $this->assertSame('Beta', $en['Bêta'] ?? null);
        $this->assertArrayHasKey('Fonctionnalité en phase de test : elle fonctionne, mais peut encore évoluer.', $en);
    }

    public function testLesEcransDuNavigateurNEcriventAucunFrancaisSansTraduction(): void
    {
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.client.php');
        foreach (['public/assets/js/workflow-editor.js', 'public/assets/js/workflow-run.js', 'public/assets/js/workflows.js', 'public/assets/js/workflow-rail.js'] as $f) {
            $code = self::src($f);
            preg_match_all("/(?<![\\w.])(?:T|t)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $code, $m);
            $this->assertNotSame([], $m[1], $f);
            foreach ($m[1] as $cle) {
                $cle = stripcslashes($cle);
                $this->assertArrayHasKey($cle, $en, "$f : traduction absente de lang/en.client.php — $cle");
            }
        }
    }

    /**
     * Historique du volet de gauche : une conversation née d'une exécution de
     * workflow porte l'icône des workflows à gauche de son titre, comme
     * l'horloge d'une tâche programmée — dans le chat ET dans le volet des
     * pages d'espace (deux rendus du même historique).
     */
    public function testLHistoriqueMarqueLesConversationsDeWorkflow(): void
    {
        foreach (['templates/chat/index.php', 'templates/partials/chat-shell-sidebar.php'] as $gabarit) {
            $src = self::src($gabarit);
            $this->assertStringContainsString("\$isWorkflow  = !\$isScheduled && !empty(\$conv['workflow_run_id']);", $src, "$gabarit : drapeau absent");
            $this->assertStringContainsString("<?= \$isWorkflow ? ' is-workflow' : '' ?>", $src, "$gabarit : classe de la ligne absente");
            $horloge  = strpos($src, '<?= ScheduledTask::icon(13) ?>');
            $workflow = strpos($src, '<span class="conv-workflow-icon" role="img" aria-label="<?= te(\'Workflow\') ?>"><?= Workflow::icon(13) ?></span>');
            $titre    = strpos($src, '<span class="conv-title">');
            $this->assertIsInt($horloge, $gabarit);
            $this->assertIsInt($workflow, "$gabarit : icône des workflows absente");
            $this->assertLessThan($titre, $workflow, "$gabarit : l'icône précède le titre");
            $this->assertGreaterThan($horloge, $workflow, "$gabarit : même place que l'horloge, en alternative");
        }
        $css = self::src('public/assets/css/main.css');
        $this->assertMatchesRegularExpression('/\.conv-scheduled-icon,\s*\.conv-workflow-icon\s*\{/', $css, 'Même habillage que l’horloge.');
        // L'historique est lu en `c.*` : la colonne y est, sans requête de plus.
        $this->assertStringContainsString('SELECT c.*', self::methode(self::src('src/Models/Conversation.php'), 'allForUser'));
    }

    // ------------------------------------------------------------------
    // Supprimer un workflow : une corbeille là où on gère ses workflows, et
    // jamais sans la fenêtre de confirmation.
    // ------------------------------------------------------------------

    /**
     * La suppression était enfouie au bas des Réglages de l'éditeur. Elle est
     * désormais sur chaque vignette de « Mes workflows » et dans l'en-tête de
     * l'éditeur — toutes les portes passent par LA fenêtre de workflows.js,
     * seule à poser `confirm`, sans lequel le serveur ne supprime rien.
     */
    public function testSupprimerPasseToujoursParLaFenetreDeConfirmation(): void
    {
        // Liste : un vrai formulaire par vignette, intercepté, droit « Supprimer ».
        $liste = self::src('templates/workflows/index.php');
        $this->assertStringContainsString("\$wfCanDelete = Workflow::allows(\$wfUser, 'delete');", $liste);
        $this->assertStringContainsString('<?php if ($wfCanDelete):', $liste);
        $this->assertStringContainsString('action="/workflows/<?= (int)$w[\'id\'] ?>/delete" class="wf-over" data-wf-delete', $liste);
        foreach (['data-name=', 'data-runs=', 'data-live=', 'data-pausable=', "\$icons['trash']", 'Supprimer « {nom} »'] as $attendu) {
            $this->assertStringContainsString($attendu, $liste);
        }
        $this->assertStringContainsString('$deletionFacts = $view === \'mes\'', self::methode(self::src('src/Controllers/WorkflowController.php'), 'index'));

        // Éditeur : bouton d'en-tête, loin de « Publier ».
        $edit = self::src('templates/workflows/edit.php');
        $this->assertStringContainsString('id="wfDelete"', $edit);
        $this->assertStringContainsString("<?php if (!empty(\$editor['can']['delete'])): ?>", $edit);
        $this->assertLessThan(strpos($edit, 'id="wfPublish"'), strpos($edit, 'id="wfDelete"'));
        $this->assertLessThan(strpos($edit, 'src="/assets/js/workflow-editor.js'), strpos($edit, 'src="/assets/js/workflows.js'), 'La fenêtre est chargée avant l’éditeur.');

        $js = self::src('public/assets/js/workflows.js');
        $this->assertStringContainsString("body.append('confirm', '1')", $js);
        $this->assertStringContainsString("closest('form[data-wf-delete]')", $js);
        $this->assertStringContainsString('role="alertdialog"', $js);
        $this->assertStringContainsString('cancelBtn.focus()', $js, 'Le focus arrive sur Annuler : Entrée ne supprime rien.');
        $this->assertStringContainsString('window.MiaWorkflowDelete', $js);

        $editeur = self::src('public/assets/js/workflow-editor.js');
        $supprimer = self::methode($editeur, 'deleteWorkflow');
        $this->assertStringContainsString('window.MiaWorkflowDelete.open(', $supprimer);
        $this->assertStringNotContainsString('window.confirm', $supprimer);
        $this->assertStringNotContainsString('fetch(', $supprimer, 'Un seul envoi : celui de la fenêtre.');
        $this->assertStringContainsString("\$('#wfDelete')", $editeur);
        $this->assertStringContainsString('if (state.deleting) { return Promise.resolve(); }', self::methode($editeur, 'save'), 'Plus d’enregistrement automatique d’un workflow en cours de suppression.');
        // Les Réglages gardent leur bouton, qui ouvre la même fenêtre.
        $this->assertStringContainsString('data-act="delete"', $editeur);
        $this->assertStringContainsString("case 'delete': return deleteWorkflow();", $editeur);

        // Serveur : confirmation exigée, exécutions en cours arrêtées AVANT la suppression.
        $ctl = self::methode(self::src('src/Controllers/WorkflowController.php'), 'delete');
        $garde = strpos($ctl, "(string)(\$_POST['confirm'] ?? '') !== '1'");
        $arret = strpos($ctl, 'WorkflowEngine::cancel($run, false, true)');
        $suppr = strpos($ctl, 'Workflow::delete(');
        $this->assertIsInt($garde);
        $this->assertIsInt($arret);
        $this->assertIsInt($suppr);
        $this->assertLessThan($arret, $garde);
        $this->assertLessThan($suppr, $arret);
        $this->assertStringContainsString('WorkflowRun::unfinishedFor($workflow[\'id\'], (int)$user[\'id\'])', $ctl);
        // Le message de la liste est posé AVANT MailPush::syncSoon, qui referme la session (JobQueue::dispatch).
        $this->assertLessThan(strpos($ctl, 'MailPush::syncSoon('), strpos($ctl, "\$this->flash('workflows_success'"));
        $this->assertStringContainsString("'deletion' => WorkflowRun::deletionFacts(", self::methode(self::src('src/Controllers/WorkflowController.php'), 'edit'));

        // L'ancienne confirmation du navigateur a disparu, ses traductions aussi.
        $this->assertStringNotContainsString('Supprimer ce workflow ? Ses exécutions passées', $editeur);
        $this->assertStringNotContainsString('Supprimer ce workflow ? Ses exécutions passées', self::src('lang/en.client.php'));
    }

    public function testLesFaitsDeSuppressionSeComptentParWorkflow(): void
    {
        $this->assertSame([], WorkflowRun::foldDeletionFacts([]));
        $this->assertSame(
            [3 => ['runs' => 7, 'live' => 3], 9 => ['runs' => 1, 'live' => 0]],
            WorkflowRun::foldDeletionFacts([
                ['workflow_id' => '3', 'status' => 'done', 'n' => '4'],
                ['workflow_id' => '3', 'status' => 'running', 'n' => '1'],
                ['workflow_id' => '3', 'status' => 'waiting', 'n' => '2'],
                ['workflow_id' => '9', 'status' => 'failed', 'n' => '1'],
            ]),
            'Une exécution en échec est un lancement, pas une exécution « en cours ».'
        );
        // Une suppression arrête aussi une exécution en échec ou en pause : plus rien ne la reprendrait.
        foreach ([WorkflowRun::RUNNING, WorkflowRun::WAITING, WorkflowRun::PAUSED, WorkflowRun::FAILED] as $statut) {
            $this->assertContains($statut, WorkflowRun::UNFINISHED);
        }
        foreach (WorkflowRun::TERMINAL as $statut) {
            $this->assertNotContains($statut, WorkflowRun::UNFINISHED);
        }
    }

    /**
     * La VRAIE fenêtre (workflows.js) exécutée sous node, dans un DOM minimal :
     * ce qu'elle dit, ce qu'elle échappe, ce qu'elle envoie — et qu'une
     * corbeille de vignette l'ouvre au lieu de soumettre.
     */
    public function testLaFenetreDeSuppressionDitCeQuiPartEtEnvoieLaConfirmation(): void
    {
        $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node indisponible.');
        }
        $harnais = <<<'JS'
'use strict';
globalThis.window = globalThis;
window.I18N = { lang: 'fr' };
window.t = (s, v) => String(s).replace(/\{(\w+)\}/g, (m, k) => (v && k in v ? String(v[k]) : m));
window.tn = (n, un, plusieurs, v) => window.t(n > 1 ? plusieurs : un, Object.assign({}, v || {}, { n }));
const docListeners = {};
const made = [];
function stub(extra) {
    return Object.assign({ disabled: false, textContent: '', lastChild: { textContent: '' }, focus() { focused = this; } }, extra || {});
}
let focused = null;
const goBtn = stub();
const cancelBtn = stub();
const err = stub();
globalThis.document = {
    activeElement: null,
    body: { appendChild(el) { made.push(el); } },
    addEventListener(t, f) { (docListeners[t] = docListeners[t] || []).push(f); },
    removeEventListener() {},
    getElementById() { return null; },
    querySelector(s) { return s === 'meta[name="csrf-token"]' ? { getAttribute: () => 'jeton' } : null; },
    contains() { return true; },
    createElement() {
        return {
            className: '', innerHTML: '', listeners: {},
            addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); },
            remove() { this.removed = true; },
            querySelector(s) { return s === '[data-confirm]' ? goBtn : s === 'footer [data-close]' ? cancelBtn : s === '.err' ? err : null; },
            querySelectorAll() { return [cancelBtn, goBtn]; },
        };
    },
};
window.location = { href: '' };
let sent = null;
globalThis.fetch = (url, init) => { sent = { url, init }; return Promise.resolve({ json: () => Promise.resolve({ ok: true, url: '/workflows' }) }); };
__SCRIPT__
const D = window.MiaWorkflowDelete;
const out = {};
out.none = D.lines({ runs: 0, live: 0 });
out.one = D.lines({ runs: 1, live: 1 });
out.many = D.lines({ runs: '12', live: '3' });

// Corbeille d'une vignette : le formulaire n'est pas soumis, la fenêtre s'ouvre.
let prevented = false;
const form = { getAttribute: (n) => ({ action: '/workflows/42/delete', 'data-name': '<b>Veille & co</b>', 'data-runs': '2', 'data-live': '1', 'data-pausable': '1' })[n] ?? null };
docListeners.submit.forEach((f) => f({ target: { closest: (s) => (s === 'form[data-wf-delete]' ? form : null) }, preventDefault() { prevented = true; } }));
out.prevented = prevented;
out.html = made[0] ? made[0].innerHTML : '';
out.focusOnCancel = focused === cancelBtn;
out.sentBefore = sent;

// Annuler : rien n'est envoyé.
const back = made[0];
const target = (match) => ({ closest: (s) => (s === match ? {} : null) });
// Confirmer : l'envoi part, avec confirm=1, puis la liste.
back.listeners.click.forEach((f) => f({ target: target('[data-confirm]') }));
setTimeout(() => {
    out.url = sent && sent.url;
    out.method = sent && sent.init.method;
    out.confirm = sent && sent.init.body.get('confirm');
    out.csrf = sent && sent.init.headers['X-CSRF-Token'];
    out.after = window.location.href;
    out.busy = goBtn.disabled;
    process.stdout.write(JSON.stringify(out));
}, 20);
JS;
        $script = str_replace('__SCRIPT__', self::src('public/assets/js/workflows.js'), $harnais);
        $fichier = tempnam(sys_get_temp_dir(), 'mia-wfdel-');
        file_put_contents($fichier, $script);
        try {
            $sortie = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier) . ' 2>&1');
        } finally {
            @unlink($fichier);
        }
        $r = json_decode($sortie, true);
        $this->assertIsArray($r, "workflows.js a échoué sous node :\n" . $sortie);

        $textes = static fn(array $lignes): string => implode(' | ', array_column($lignes, 'text'));
        $this->assertStringContainsString('jamais été lancé', $textes($r['none']));
        $this->assertCount(2, $r['none'], 'Rien en cours : aucun avertissement.');
        $this->assertStringContainsString('L’historique de son lancement', $textes($r['one']));
        $this->assertStringContainsString('Son exécution en cours sera arrêtée', $textes($r['one']));
        $this->assertTrue($r['one'][2]['warn']);
        $this->assertStringContainsString('ses 12 lancements', $textes($r['many']));
        $this->assertStringContainsString('Ses 3 exécutions en cours', $textes($r['many']));

        $this->assertTrue($r['prevented'], 'La corbeille ne soumet jamais seule.');
        $this->assertNull($r['sentBefore'], 'Rien ne part avant la confirmation.');
        $this->assertTrue($r['focusOnCancel']);
        $this->assertStringContainsString('&lt;b&gt;Veille &amp; co&lt;/b&gt;', $r['html'], 'Le nom est échappé.');
        $this->assertStringNotContainsString('<b>Veille', $r['html']);
        $this->assertStringContainsString('Cette action est définitive', $r['html']);
        $this->assertStringContainsString('mettez-le plutôt en pause', $r['html']);
        $this->assertStringContainsString('Supprimer définitivement', $r['html']);

        $this->assertSame('/workflows/42/delete', $r['url']);
        $this->assertSame('POST', $r['method']);
        $this->assertSame('1', $r['confirm']);
        $this->assertSame('jeton', $r['csrf']);
        $this->assertSame('/workflows', $r['after']);
    }
}
