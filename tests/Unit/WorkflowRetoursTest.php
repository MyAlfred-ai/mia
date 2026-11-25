<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Retours de recette des workflows du 2026-11-04 — formes pures et câblage.
 * Les scénarios de bout en bout sont dans tests/Functional/WorkflowRecetteScenariosTest.php.
 */
final class WorkflowRetoursTest extends TestCase
{
    private const DEPART = ['depart' => ['key' => 'depart', 'name' => 'Informations de départ', 'form' => 'fiche', 'fields' => [
        ['key' => 'reponse', 'label' => 'Départ', 'type' => 'text', 'options' => ['Oui', 'Non', 'Peut-être']],
        ['key' => 'ok', 'label' => 'Accord', 'type' => 'yesno'],
        ['key' => 'montant', 'label' => 'Montant', 'type' => 'number'],
    ]]];

    private static function rule(string $join, array ...$items): array
    {
        return ['join' => $join, 'items' => array_map(static fn(array $i): array => $i + ['piece' => 'depart', 'value' => ''], $items)];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    // ------------------------------------------------------------------
    // Règles d'une condition
    // ------------------------------------------------------------------

    public function testTroisValeursDUnMemeChampAvecToutesNeSontJamaisVraies(): void
    {
        $c = WorkflowValues::ruleConflicts(self::rule('all',
            ['field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'],
            ['field' => 'reponse', 'op' => 'eq', 'value' => 'Non'],
            ['field' => 'reponse', 'op' => 'eq', 'value' => 'Peut-être'],
        ), self::DEPART);
        $this->assertCount(1, $c);
        $this->assertSame('block', $c[0]['level']);
        $this->assertStringContainsString('« Départ » ne peut pas valoir à la fois « Oui », « Non » et « Peut-être »', $c[0]['text']);
        $this->assertStringContainsString('« Si oui » ne serait jamais prise', $c[0]['text']);
    }

    public function testLesAutresContradictionsSontVues(): void
    {
        $this->assertSame('block', WorkflowValues::ruleConflicts(self::rule('all',
            ['field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'], ['field' => 'reponse', 'op' => 'ne', 'value' => 'oui']), self::DEPART)[0]['level'] ?? '');
        $this->assertSame('block', WorkflowValues::ruleConflicts(self::rule('all',
            ['field' => 'ok', 'op' => 'yes'], ['field' => 'ok', 'op' => 'no']), self::DEPART)[0]['level'] ?? '');
        $this->assertSame('block', WorkflowValues::ruleConflicts(self::rule('all',
            ['field' => 'montant', 'op' => 'empty'], ['field' => 'montant', 'op' => 'gt', 'value' => '5']), self::DEPART)[0]['level'] ?? '');
        // Toujours vraie avec « Au moins une » : un avis, pas un blocage.
        $any = WorkflowValues::ruleConflicts(self::rule('any',
            ['field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'], ['field' => 'reponse', 'op' => 'ne', 'value' => 'Oui']), self::DEPART);
        $this->assertSame('warn', $any[0]['level']);
        $this->assertStringContainsString('« Si non » ne sera jamais prise', $any[0]['text']);
    }

    public function testDesReglesJustesNeDisentRien(): void
    {
        $this->assertSame([], WorkflowValues::ruleConflicts(self::rule('any',
            ['field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'], ['field' => 'reponse', 'op' => 'eq', 'value' => 'Peut-être']), self::DEPART));
        $this->assertSame([], WorkflowValues::ruleConflicts(self::rule('all',
            ['field' => 'montant', 'op' => 'gt', 'value' => '5'], ['field' => 'montant', 'op' => 'lt', 'value' => '50'],
            ['field' => 'reponse', 'op' => 'eq', 'value' => 'oui']), self::DEPART));
        $this->assertSame([], WorkflowValues::ruleConflicts(self::rule('all', ['field' => 'reponse', 'op' => 'eq', 'value' => 'peut-etre']), self::DEPART),
            'Casse et accents ignorés, comme à l’exécution.');
    }

    public function testUneValeurHorsDesChoixEstSignalee(): void
    {
        $c = WorkflowValues::ruleConflicts(self::rule('all', ['field' => 'reponse', 'op' => 'eq', 'value' => 'Bof']), self::DEPART);
        $this->assertSame('warn', $c[0]['level']);
        $this->assertStringContainsString('« Bof » n’est pas un des choix de « Départ » (Oui, Non, Peut-être)', $c[0]['text']);
        $this->assertStringContainsString('ne sera jamais vraie', $c[0]['text']);
        // Filtre et sortie de boucle : leurs propres mots.
        $f = WorkflowValues::ruleConflicts(self::rule('all',
            ['field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'], ['field' => 'reponse', 'op' => 'eq', 'value' => 'Non']), self::DEPART, 'filter');
        $this->assertStringContainsString('aucun élément ne serait gardé', $f[0]['text']);
    }

    public function testLaRegleSEvalueToujoursCommeAvant(): void
    {
        $pieces = self::DEPART;
        $contents = ['depart' => ['fields' => ['reponse' => 'Oui', 'ok' => true, 'montant' => 6200]]];
        $this->assertTrue(WorkflowValues::evaluate(self::rule('all', ['field' => 'reponse', 'op' => 'eq', 'value' => 'Oui']), $pieces, $contents)['result']);
        $this->assertFalse(WorkflowValues::evaluate(self::rule('all', ['field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'], ['field' => 'reponse', 'op' => 'eq', 'value' => 'Non']), $pieces, $contents)['result'],
            'Le défaut de la recette : jamais vraie ensemble.');
        $this->assertTrue(WorkflowValues::evaluate(self::rule('any', ['field' => 'reponse', 'op' => 'eq', 'value' => 'Non'], ['field' => 'reponse', 'op' => 'eq', 'value' => 'oui']), $pieces, $contents)['result']);
    }

    public function testLesOptionsDUnChoixSontConnuesDesDeuxCotes(): void
    {
        $def = WorkflowSpec::normalize(['start' => ['fields' => [
            ['key' => 'reponse', 'label' => 'Départ', 'type' => 'choice', 'options' => ['Oui', 'Non']],
            ['key' => 'client', 'label' => 'Client', 'type' => 'text'],
        ]], 'nodes' => []]);
        $fields = WorkflowSpec::pieces($def)['depart']['fields'];
        $this->assertSame(['Oui', 'Non'], $fields[0]['options']);
        $this->assertArrayNotHasKey('options', $fields[1], 'Seul un Choix porte des options.');
        $js = (string)file_get_contents(self::root() . '/public/assets/js/workflow-rail.js');
        $this->assertStringContainsString("if (f.type === 'choice') { fd.options =", $js, 'Miroir dans le rail du navigateur.');
        $ed = (string)file_get_contents(self::root() . '/public/assets/js/workflow-editor.js');
        $this->assertStringContainsString("data-prop=\"value\">'", $ed, 'Le Choix se règle par une liste, pas une saisie libre.');
        $this->assertStringContainsString("el.getAttribute('data-prop') === 'value'", $ed);
        $this->assertStringContainsString('wf-rule-lead', $ed, 'La phrase de tête dit comment les conditions se combinent.');
        $this->assertSame(8, WorkflowSpec::MAX_RULE_ITEMS);
    }

    // ------------------------------------------------------------------
    // Pré-contrôle
    // ------------------------------------------------------------------

    public function testLePreControleRetientCeQuiBloqueEtLesComptesARelier(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]],
            'nodes' => [['key' => 's', 'type' => 'step', 'title' => 'Chercher', 'instruction' => 'Cherche @{depart}.', 'sources' => ['drive'],
                'output' => ['key' => 'r', 'name' => 'Résultat', 'form' => 'text']]],
            'end' => ['pieces' => ['r'], 'deliveries' => [['type' => 'email', 'source' => 'gmail', 'to' => 'a@b.fr', 'subject' => 'x', 'message' => 'y', 'attach' => ['r'], 'mode' => 'approve']]],
        ]);
        $ctx = ['sources' => [
            'gmail' => ['label' => 'Gmail', 'connected' => false],
            'drive' => ['label' => 'Google Drive', 'connected' => false],
        ]];
        $items = WorkflowSpec::preflight($def, $ctx);
        $by = [];
        foreach ($items as $i) {
            $by[$i['connect'] ?? ''] = $i;
        }
        $this->assertSame('Livrable', $by['gmail']['brick'] ?? null, 'Livraison sans compte : bloquant.');
        $this->assertSame('block', $by['gmail']['level']);
        $this->assertSame('Chercher', $by['drive']['brick'] ?? null, 'Source d’étape non reliée : retenue aussi (l’étape travaillerait à l’aveugle).');
        $this->assertSame('warn', $by['drive']['level']);
        $ctx['sources']['gmail']['connected'] = true;
        $ctx['sources']['drive']['connected'] = true;
        $this->assertSame([], WorkflowSpec::preflight($def, $ctx), 'Tout relié : rien à régler.');
    }

    public function testLePreControleEstSurChaqueCheminDeLancement(): void
    {
        $r = self::root();
        $ctl = (string)file_get_contents($r . '/src/Controllers/WorkflowController.php');
        $this->assertStringContainsString('WorkflowEngine::assertReady($workflow, $user);', $ctl, 'Fenêtre de lancement et chat.');
        $this->assertStringContainsString("'preflight' => \$e->items()", $ctl);
        $this->assertStringContainsString("'preflight' => WorkflowEngine::preflight(\$workflow, \$user, \$choices)", $ctl, 'La fenêtre le montre avant le clic.');
        $this->assertStringContainsString('WorkflowEngine::assertReady($wf, $user)', (string)file_get_contents($r . '/src/Controllers/WorkflowHookController.php'));
        $trig = (string)file_get_contents($r . '/src/Workflow/WorkflowTriggers.php');
        $this->assertSame(2, substr_count($trig, 'WorkflowEngine::assertReady('), 'Surveillance et enchaînement.');
        $eng = (string)file_get_contents($r . '/src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString('self::assertReady($wf, $user)', $eng, 'Programmation.');
        $this->assertStringContainsString('WorkflowNotifier::notStarted', $eng);
        $js = (string)file_get_contents($r . '/public/assets/js/workflows.js');
        $this->assertStringContainsString('function preflightHtml(items)', $js);
        $this->assertStringContainsString("' disabled' : '') + '>' + esc(T('Lancer'))", $js, 'Lancer est grisé tant qu’il reste un point.');
        $this->assertStringContainsString('data-recheck', $js);
    }

    // ------------------------------------------------------------------
    // Document de référence
    // ------------------------------------------------------------------

    public function testUnDocumentDeReferenceNEstQuUnNomDeFichierMaitrise(): void
    {
        $ok = WorkflowSpec::referenceFile(['ref' => 'wfref_' . str_repeat('a', 32) . '.pdf', 'name' => 'Nos références.pdf', 'type' => 'application/pdf', 'size' => 42]);
        $this->assertSame('Nos références.pdf', $ok['name']);
        foreach (['../x', '/etc/passwd', 'wfref_123.pdf', 'wfref_' . str_repeat('a', 32) . '.pdf/../../x', str_repeat('a', 32)] as $bad) {
            $this->assertNull(WorkflowSpec::referenceFile(['ref' => $bad]), $bad);
        }
        $def = WorkflowSpec::normalize(['start' => [
            'fields' => [
                ['key' => 'doc', 'label' => 'Modèle', 'type' => 'file', 'required' => true, 'reference' => ['ref' => 'wfref_' . str_repeat('b', 32) . '.docx', 'name' => 'Modèle.docx']],
                ['key' => 'txt', 'label' => 'Note', 'type' => 'text', 'reference' => ['ref' => 'wfref_' . str_repeat('b', 32)]],
            ],
            'schedule' => ['rule' => ['freq' => 'daily', 'time' => '08:00'], 'timezone' => 'Europe/Paris'],
        ], 'nodes' => [['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => '@{doc}', 'output' => ['key' => 'o', 'name' => 'O', 'form' => 'text']]]]);
        $this->assertArrayHasKey('reference', $def['start']['fields'][0]);
        $this->assertArrayNotHasKey('reference', $def['start']['fields'][1], 'Seul un champ Fichier porte un document de référence.');
        $issues = implode(' ', array_column(WorkflowSpec::issues($def, []), 'text'));
        $this->assertStringNotContainsString('ne peut pas exiger de fichier', $issues, 'Programmé : le document de référence est toujours là.');
        $pr = (string)file_get_contents(self::root() . '/src/Maintenance/PruningService.php');
        $this->assertStringContainsString('wfref_[a-f0-9]{32}', $pr, 'Le balayage des orphelins épargne les documents de référence.');
    }

    // ------------------------------------------------------------------
    // Volet d'avancement
    // ------------------------------------------------------------------

    public function testLeVoletSuitLaVoiePriseEtCeQuiVousAttend(): void
    {
        $def = WorkflowSpec::normalize([
            'nodes' => [
                ['key' => 'a', 'type' => 'step', 'title' => 'Analyser', 'instruction' => 'x', 'output' => ['key' => 'p', 'name' => 'P', 'form' => 'text']],
                ['key' => 'c', 'type' => 'condition', 'title' => 'On continue ?', 'mode' => 'question', 'question' => '@{p}',
                    'yes' => ['nodes' => [['key' => 'y', 'type' => 'step', 'title' => 'Oui', 'instruction' => 'x', 'output' => ['key' => 'q', 'name' => 'Q', 'form' => 'text']]], 'then' => 'continue'],
                    'no' => ['nodes' => [['key' => 'n', 'type' => 'step', 'title' => 'Non', 'instruction' => 'x', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'text']]], 'then' => 'stop']],
                ['key' => 'g', 'type' => 'gate', 'title' => 'Relecture', 'reviews' => ['p']],
            ],
            'end' => ['pieces' => ['p']],
        ]);
        $state = ['status' => 'waiting', 'current' => 'g', 'terminal' => false, 'timer' => null, 'credits' => 12, 'error' => null,
            'nodes' => [
                'a' => ['status' => 'done', 'outcome' => 'done', 'seconds' => 7, 'attempt' => 1],
                'c' => ['status' => 'done', 'outcome' => 'no', 'seconds' => 1, 'attempt' => 1],
                'n' => ['status' => 'done', 'outcome' => 'done', 'seconds' => 3, 'attempt' => 1],
                'g' => ['status' => 'waiting', 'outcome' => null, 'attempt' => 1],
            ],
            'loops' => [], 'waiting_cards' => false, 'resumable' => false,
            'gate' => ['key' => 'g', 'title' => 'Relecture', 'doubt' => false, 'reason' => '', 'action_id' => 41, 'can_return' => true, 'routes' => [], 'suggested' => '', 'loop_gate' => ''],
        ];
        $v = WorkflowProgress::view($def, $state, ['run_id' => 9, 'name' => 'Test', 'color' => '#123456', 'version' => 2]);
        $keys = array_column($v['rows'], 'key');
        $this->assertSame(['start', 'a', 'c', 'c:no', 'n', 'g', 'end'], $keys, 'Seule la voie prise est déroulée.');
        $rows = array_column($v['rows'], null, 'key');
        $this->assertSame(['Non', 1], [$rows['c']['note'], $rows['c']['lane']]);
        $this->assertSame('7 s', $rows['a']['note']);
        $this->assertSame('wait', $rows['g']['state']);
        $this->assertSame(['gate', 41, true], [$v['attention']['kind'], $v['attention']['action_id'], $v['attention']['can_return']]);
        $this->assertSame(WorkflowProgress::POLL_WAITING_MS, $v['poll']);
        $this->assertSame('#123456', $v['color']);
        $this->assertSame(4, $v['done'], 'Départ, Analyser, la condition et la voie « non ».');

        $failed = WorkflowProgress::attention(['status' => 'failed', 'error' => ['title' => 'Crédit épuisé', 'message' => 'Rechargez.'], 'resumable' => true, 'latest_version' => 3]);
        $this->assertSame(['failed', 'Crédit épuisé', true, 3], [$failed['kind'], $failed['title'], $failed['resumable'], $failed['latest']]);
        $this->assertNull(WorkflowProgress::attention(['status' => 'running']));
        $this->assertSame('2 min', WorkflowProgress::duration(110));
    }

    public function testLaDiscussionDAbordEstCablee(): void
    {
        $r = self::root();
        $idx = (string)file_get_contents($r . '/public/index.php');
        foreach (["'/workflows/runs/{id}/progress'", "'/workflows/{id}/versions'", "'/workflows/{id}/versions/{version}'",
                     "'/workflows/{id}/versions/{version}/restore'", "'/workflows/{id}/reference'"] as $route) {
            $this->assertStringContainsString($route, $idx);
        }
        $tpl = (string)file_get_contents($r . '/templates/chat/index.php');
        $this->assertStringContainsString('data-workflow-run="<?= (int)($workflowRunId ?? 0) ?>"', $tpl);
        $this->assertStringContainsString('id="wfProgress"', $tpl);
        $this->assertLessThan(strpos($tpl, '/assets/js/workflow-progress.js'), strpos($tpl, '/assets/js/chat.js?v='), 'Le volet après chat.js (il s’appuie sur miaSyncConversation).');
        $chat = (string)file_get_contents($r . '/public/assets/js/chat.js');
        $this->assertStringContainsString('window.miaSyncConversation = syncConversationTail;', $chat);
        $this->assertStringContainsString("announceConversation(targetStr, data.workflow_run_id || 0);", $chat);
        $this->assertStringContainsString("window.location.href = r.conversation_url || r.url;", $chat, 'Lancé depuis le chat : on reste dans la conversation de l’exécution.');
        $this->assertStringContainsString('function workflowCardExtrasHtml(data)', $chat, 'Renvoyer, choisir une voie : dans la carte.');
        $this->assertStringContainsString('/workflows/runs/${Number(wf.run)}/gate', $chat);
        $this->assertStringContainsString("mia:card-decided", $chat);
        $js = (string)file_get_contents($r . '/public/assets/js/workflows.js');
        $this->assertStringContainsString('window.location.href = j.conversation_url || j.url;', $js, 'Lancé de la liste ou de l’éditeur : sa conversation.');
        $wp = (string)file_get_contents($r . '/public/assets/js/workflow-progress.js');
        $code = (string)preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $wp);
        $this->assertStringContainsString("'/workflows/runs/' + id + '/progress'", $wp);
        $this->assertDoesNotMatchRegularExpression("/'[^'\\n]*[éèàùç][^'\\n]*'/u", $code, 'Aucun texte dans le script : tout vient du serveur.');
        $ctl = (string)file_get_contents($r . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString("'workflow_run_id' => self::workflowRunIdFor(\$conversation, (int)\$user['id'])", $ctl);
        $eng = (string)file_get_contents($r . '/src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString("'wf'           => ['run' => (int)\$run['id'], 'kind' => 'gate'", $eng);
        $this->assertStringContainsString("'wf'           => ['run' => (int)\$run['id'], 'kind' => 'route'", $eng);
    }

    public function testLeVerdictPrendLaCouleurDeLaVoieEtLeSuccesEstVert(): void
    {
        $r = self::root();
        $rail = (string)file_get_contents($r . '/public/assets/js/workflow-rail.js');
        $this->assertStringContainsString("'<span class=\"wf-pill lane\" style=\"--lane:var(--wf-l' + (st.outcome === 'yes' ? 1 : 2) + ')\">'", $rail);
        $css = (string)file_get_contents($r . '/public/assets/css/workflows.css');
        $this->assertStringContainsString('.wf-pill.ok { color: var(--wf-ok);', $css, '« Lancé », « Fait », « Livré » en vert.');
        $this->assertStringContainsString('.wf-pill.lane { color: var(--wf-on-lane, #fff); background: var(--lane, var(--wf-l1)); }', $css);
        $this->assertMatchesRegularExpression('/--wf-ok: #15803d;/', $css);
        $this->assertMatchesRegularExpression('/--wf-ok: #4ade80;/', $css, 'Et en thème sombre.');
    }

    public function testLHistoriqueDesVersionsEstCable(): void
    {
        $r = self::root();
        $mig = (string)file_get_contents($r . '/database/migrations/102_workflow_versions.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS workflow_versions', $mig);
        $this->assertStringContainsString('UNIQUE KEY uq_wfv_version (workflow_id, version)', $mig);
        $this->assertStringContainsString('ON DELETE CASCADE', $mig);
        $this->assertStringNotContainsString('manual', strtolower($mig), 'Mot réservé de MySQL 8.4.');
        $model = (string)file_get_contents($r . '/src/Models/Workflow.php');
        $this->assertStringContainsString('INSERT INTO workflow_versions', $model, 'Chaque publication garde sa version.');
        $ed = (string)file_get_contents($r . '/public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('data-versions', $ed);
        $this->assertStringContainsString("'/versions/' + n + '/restore'", $ed);
        $edit = (string)file_get_contents($r . '/templates/workflows/edit.php');
        $this->assertStringContainsString("te('Lancements')", $edit, '« Historique » ne désigne plus deux choses.');
        $backup = (string)file_get_contents($r . '/src/Backup/BackupService.php');
        $this->assertStringContainsString("'workflow_versions'", $backup, 'Personnel, comme les workflows : hors de la sauvegarde.');
        // Heure de publication dans le fuseau du navigateur : un workflow sans
        // programmation n'en porte aucun (vu au banc : 18 h 32 au lieu de 19 h 32).
        $this->assertStringContainsString("'/versions' + (tz ? '?tz=' + encodeURIComponent(tz) : '')", $ed);
        $ctrl = (string)file_get_contents($r . '/src/Controllers/WorkflowController.php');
        $this->assertStringContainsString("is_string(\$_GET['tz'] ?? null) ? ScheduledTask::normalizeTimezone(\$_GET['tz'])", $ctrl);
    }

    public function testMiaSaitCeQuEstLEntrepriseQuandElleTranche(): void
    {
        $def = WorkflowSpec::normalize(['nodes' => [
            ['key' => 'c', 'type' => 'condition', 'title' => 'C', 'mode' => 'question', 'question' => 'x', 'kb_ids' => [3, 4, 5, 6]],
            ['key' => 'r', 'type' => 'route', 'title' => 'R', 'instruction' => 'x', 'kb_ids' => [2], 'routes' => [['label' => 'A'], ['label' => 'B']]],
        ]]);
        $this->assertSame([3, 4, 5], $def['nodes'][0]['kb_ids'], 'Trois bases au plus, comme une étape.');
        $this->assertSame([2], $def['nodes'][1]['kb_ids']);
        $eng = (string)file_get_contents(self::root() . '/src/Workflow/WorkflowEngine.php');
        $this->assertSame(2, substr_count($eng, 'self::decisionKnowledge($node'), 'Condition et décision.');
        $this->assertStringContainsString('CompanyProfile::promptFragment()', $eng);
        $ai = (string)file_get_contents(self::root() . '/src/Workflow/WorkflowAi.php');
        $this->assertSame(2, substr_count($ai, "CE QUE L'ON SAIT DE L'ENTREPRISE"));
    }
}
