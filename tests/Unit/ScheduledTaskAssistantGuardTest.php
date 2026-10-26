<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tâches programmées — l'assistant est PARTAGÉ, la tâche est PERSONNELLE.
 *
 * Deux garde-fous :
 *  1. un assistant devenu inaccessible (retiré d'un groupe, désactivé,
 *     supprimé) reste affiché « indisponible » dans le formulaire, et
 *     l'enregistrement est refusé tant qu'un autre choix n'est pas fait —
 *     sinon la liste retombait sur « sans assistant » et un simple
 *     enregistrement lui faisait perdre son assistant, en silence ;
 *  2. les outils qu'apporte l'assistant se voient (fiche, tuiles, schéma), et
 *     en « Agir sans validation » une automatisation n8n ou une présentation
 *     Gamma ne s'exécute seule que si son outil a été CHOISI pour la tâche —
 *     un outil ajouté à l'assistant après coup, par quelqu'un d'autre,
 *     n'agit pas au nom de la personne sans son accord.
 *
 * La partie rendu du schéma est exécutée sous node (skippée sans node).
 */
final class ScheduledTaskAssistantGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Contrôleur hors du bootstrap des tests (comme ScheduledTaskResourcesTest).
        require_once ROOT . '/src/Controllers/ScheduledTaskController.php';
    }

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    /** Corps d'une méthode (jusqu'à la méthode suivante). */
    private static function methode(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertNotFalse($debut, "Méthode $nom introuvable.");
        $suite = preg_match('/\n    (?:public|private|protected) (?:static )?function /', $source, $m, PREG_OFFSET_CAPTURE, $debut + 10);
        return $suite === 1 ? substr($source, $debut, $m[0][1] - $debut) : substr($source, $debut);
    }

    // ------------------------------------------------------------------
    // 1. Assistant devenu inaccessible
    // ------------------------------------------------------------------

    public function testUnAssistantQuiNEstPlusPermisEstSignale(): void
    {
        $permis = [7 => ['id' => 7], 8 => ['id' => 8]];
        $this->assertSame(42, ScheduledTaskController::missingProfileId(42, $permis));
        $this->assertSame(42, ScheduledTaskController::missingProfileId('42', $permis));
        $this->assertNull(ScheduledTaskController::missingProfileId(7, $permis));
        $this->assertNull(ScheduledTaskController::missingProfileId(null, $permis));
        $this->assertNull(ScheduledTaskController::missingProfileId(0, $permis));
        $this->assertNull(ScheduledTaskController::missingProfileId('', $permis));
    }

    public function testLeServeurRefuseToujoursUnAssistantNonPermis(): void
    {
        $allowed = ['profiles' => [7 => true], 'llms' => ['openai::gpt-5.6' => 'GPT-5.6'], 'sources' => []];
        $post = ['instruction' => 'Le point', 'profile_id' => '42', 'freq' => 'daily', 'time' => '08:00'];
        $this->expectException(\InvalidArgumentException::class);
        ScheduledTask::normalizeInput($post, $allowed);
    }

    public function testLeFormulaireGardeLAssistantIndisponibleChoisi(): void
    {
        $tpl = self::src('templates/settings/schedule-form.php');
        // L'option est envoyée telle quelle : jamais `disabled` (une option
        // désactivée n'est pas envoyée — la tâche passerait sans assistant).
        $this->assertMatchesRegularExpression(
            '/<option value="<\?= \(int\)\$missingProfileId \?>" selected data-missing="1">/',
            $tpl
        );
        preg_match('/<option value="<\?= \(int\)\$missingProfileId \?>"[^>]*>/', $tpl, $m);
        $this->assertStringNotContainsString('disabled', $m[0] ?? '');
        $this->assertStringContainsString("te('Assistant indisponible — à remplacer')", $tpl);
        $this->assertStringContainsString('id="schedProfileMissing"', $tpl);
        $this->assertStringContainsString("'missing'    => \$missingCard", $tpl);
        // Pas le logo de MIA pour un assistant disparu.
        $this->assertSame(2, substr_count($tpl, 'sched-assistant-dot is-missing'));

        $ctrl = self::src('src/Controllers/ScheduledTaskController.php');
        $render = self::methode($ctrl, 'renderForm');
        $this->assertStringContainsString("\$missingProfileId = self::missingProfileId(\$form['profile_id'] ?? null, \$choices['profiles']);", $render);
        $this->assertLessThan(strpos($render, "require ROOT . '/templates/settings/schedule-form.php';"), strpos($render, '$missingProfileId ='));
    }

    public function testLeScriptRefuseLEnvoiEtLEssaiTantQueRienNEstChoisi(): void
    {
        $js = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString("profileSel.selectedOptions[0].dataset.missing === '1'", $js);
        $validate = substr($js, (int)strpos($js, 'function validateForm() {'));
        $validate = substr($validate, 0, (int)strpos($validate, "\n        }\n"));
        $this->assertStringContainsString('if (missingSelected()) {', $validate);
        $this->assertStringContainsString('firstInvalid = profileSel;', $validate);
        // L'essai passe par la même validation.
        $this->assertStringContainsString('setupTrial(trial, validateForm)', $js);
        // La fiche dit « indisponible », pas « sans assistant ».
        $this->assertStringContainsString('formData.missing', $js);
        $this->assertStringContainsString("card.classList.toggle('is-missing', missing);", $js);
    }

    // ------------------------------------------------------------------
    // 2. Outils de l'assistant
    // ------------------------------------------------------------------

    public function testLesOutilsDeLAssistantSuiventLaRegleDuTour(): void
    {
        $tools = [
            3  => ['id' => 3, 'name' => 'Images HD', 'type' => 'image', 'reason' => null],
            5  => ['id' => 5, 'name' => 'n8n en panne', 'type' => 'n8n', 'reason' => 'Dernier test en échec — prévenir un administrateur'],
            6  => ['id' => 6, 'name' => 'n8n RH', 'type' => 'n8n', 'reason' => null],
            9  => ['id' => 9, 'name' => 'Gamma', 'type' => 'gamma', 'reason' => null],
            11 => ['id' => 11, 'name' => 'Autres images', 'type' => 'image', 'reason' => null],
        ];
        $bound = [
            7  => [9, 5, 6, 3, 11, 999], // 999 : outil hors des groupes de la personne
            8  => [999],
            12 => [11],
        ];
        $out = ScheduledTask::assistantTools($bound, $tools);

        // Un par type, dans l'ordre des types ; l'outil en échec est sauté.
        $this->assertSame([
            ['id' => 3, 'name' => 'Images HD', 'type' => 'image', 'acting' => false],
            ['id' => 6, 'name' => 'n8n RH', 'type' => 'n8n', 'acting' => true],
            ['id' => 9, 'name' => 'Gamma', 'type' => 'gamma', 'acting' => true],
        ], $out[7]);
        // Rien de visible pour la personne : rien d'affiché.
        $this->assertArrayNotHasKey(8, $out);
        $this->assertSame([['id' => 11, 'name' => 'Autres images', 'type' => 'image', 'acting' => false]], $out[12]);
    }

    public function testLesChoixPortentLesOutilsDeChaqueAssistant(): void
    {
        $model = self::src('src/Models/ScheduledTask.php');
        $choices = self::methode($model, 'choicesFor');
        $this->assertStringContainsString("'profile_tools' => self::assistantTools(ChatTool::idsForProfiles(array_keys(\$profiles)), \$tools),", $choices);
        // Une requête pour tous les assistants, pas une par option.
        $this->assertStringContainsString('WHERE profile_id IN ($placeholders)', self::methode(self::src('src/Models/ChatTool.php'), 'idsForProfiles'));
    }

    public function testLaFicheDeLAssistantNommeSesOutils(): void
    {
        $cards = ScheduledTaskController::assistantCards(
            [['id' => 7, 'name' => 'Veille', 'avatar' => '', 'color' => '', 'description' => '', 'default_llm' => 'openai::gpt-5.6']],
            [7 => [['id' => 10, 'name' => 'Clients']]],
            ['openai::gpt-5.6' => 'GPT-5.6'],
            [7 => [
                ['id' => 6, 'name' => 'n8n RH', 'type' => 'n8n', 'acting' => true],
                ['id' => 3, 'name' => 'Images HD', 'type' => 'image', 'acting' => false],
            ]]
        );
        $this->assertSame(
            'Modèle : GPT-5.6 · Bases de l’assistant : Clients · Outils de l’assistant : n8n RH (Automatisation n8n), Images HD (Image)',
            $cards[7]['meta']
        );
        $this->assertSame([
            ['id' => 6, 'name' => 'n8n RH', 'type' => 'n8n', 'acting' => true],
            ['id' => 3, 'name' => 'Images HD', 'type' => 'image', 'acting' => false],
        ], $cards[7]['tools']);

        // Sans outils : la fiche d'avant, à l'identique.
        $sans = ScheduledTaskController::assistantCards(
            [['id' => 8, 'name' => 'Rédacteur', 'avatar' => '', 'color' => '', 'description' => '', 'default_llm' => null]],
            [],
            []
        );
        $this->assertSame('Modèle : Modèle par défaut de l’application', $sans[8]['meta']);
        $this->assertSame([], $sans[8]['tools']);

        // Les deux appels du contrôleur passent les outils.
        $ctrl = self::src('src/Controllers/ScheduledTaskController.php');
        $this->assertSame(2, substr_count($ctrl, "\$choices['profile_tools']"));
    }

    public function testLesTuilesDOutilsDisentCeQuApporteLAssistant(): void
    {
        $tpl = self::src('templates/settings/schedule-form.php');
        $this->assertStringContainsString('data-role="note" data-default="<?= htmlspecialchars($toolNote) ?>"', $tpl);
        $this->assertStringContainsString("t('apporté par l’assistant')", $tpl);
        $this->assertStringContainsString('id="schedAssistantToolsHeld"', $tpl);

        $js = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString('function syncAssistantTools(list) {', $js);
        $this->assertStringContainsString("syncAssistantTools(info ? (info.tools || []) : []);", $js);
        $this->assertStringContainsString('function syncHeldTools() {', $js);
        // Recalculé quand le mode ou un outil change.
        $auto = substr($js, (int)strpos($js, 'function syncAutoOptions() {'), 200);
        $this->assertStringContainsString('syncHeldTools();', $auto);
    }

    public function testLeCatalogueDuSchemaPorteLesOutilsDeLAssistant(): void
    {
        $this->assertSame('ses actions attendent votre validation', TaskDiagram::labels()['tool_held']);
        $catalog = self::src('src/Schedule/TaskDiagram.php');
        $this->assertStringContainsString("'tools'       => array_map(static fn(array \$tool): array => [", $catalog);
    }

    // ------------------------------------------------------------------
    // Exécution « sans validation »
    // ------------------------------------------------------------------

    public function testLesActionsDesOutilsQuiAgissentSontCellesQueLeurOutilDepose(): void
    {
        $this->assertSame(
            ScheduledTask::ACTING_TOOL_TYPES,
            array_values(array_unique(array_values(ScheduledTask::ACTING_TOOL_ACTIONS)))
        );
        // Chaque outil dépose son action AVEC l'outil qui l'exécutera.
        foreach (['src/Agent/Tools/N8nTools.php' => 'executer_workflow_n8n', 'src/Agent/Tools/GammaTools.php' => 'generer_presentation_gamma'] as $fichier => $action) {
            $source = self::src($fichier);
            $this->assertMatchesRegularExpression(
                '/\$queueConnectorWrite\(\s*\'' . $action . '\'.*?\'tool_id\'\s*=>/s',
                $source,
                "$fichier : l'action $action doit porter tool_id."
            );
        }
    }

    public function testSeulUnOutilChoisiPourLaTacheAgitSansValidation(): void
    {
        // Ce qui n'est pas un outil qui agit : inchangé.
        $this->assertTrue(Scheduler::autoExecutable('creer_tache', ['titre' => 'x'], []));
        $this->assertTrue(Scheduler::autoExecutable('creer_brouillon_gmail', ['tool_id' => 4], []));
        // Outil choisi pour la tâche : il agit seul.
        $this->assertTrue(Scheduler::autoExecutable('executer_workflow_n8n', ['tool_id' => 6], [6, 3]));
        $this->assertTrue(Scheduler::autoExecutable('generer_presentation_gamma', ['tool_id' => '9'], [9]));
        // Outil apporté par l'assistant, non coché : carte.
        $this->assertFalse(Scheduler::autoExecutable('executer_workflow_n8n', ['tool_id' => 6], [3]));
        $this->assertFalse(Scheduler::autoExecutable('generer_presentation_gamma', ['tool_id' => 9], []));
        // Outil inconnu : carte, jamais une exécution.
        $this->assertFalse(Scheduler::autoExecutable('executer_workflow_n8n', [], [6]));
        $this->assertFalse(Scheduler::autoExecutable('executer_workflow_n8n', ['tool_id' => ['6']], [6]));
    }

    public function testLeTourDeposeUneCartePourLOutilDeLAssistant(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $runSend = self::methode($chat, 'runSend');
        $auto = substr($runSend, (int)strpos($runSend, 'if ($scheduledMode === ScheduledTask::MODE_AUTO) {'));
        $auto = substr($auto, 0, (int)strpos($auto, "\n        }\n"));

        $this->assertStringContainsString('$taskToolIds         = array_values(array_map(static fn(array $t): int => (int)$t[\'id\'], $taskTools));', $auto);
        $garde = strpos($auto, 'if (!Scheduler::autoExecutable($tool, $args, $taskToolIds)) {');
        $this->assertNotFalse($garde);
        // Avant la limite et avant l'exécution : une carte ne consomme aucun cran.
        $this->assertLessThan(strpos($auto, 'if ($this->turnAutoCount >= ScheduledTask::AUTO_ACTIONS_MAX) {'), $garde);
        $this->assertLessThan(strpos($auto, 'return $this->runAutoAction('), $garde);
        $carte = substr($auto, $garde, 300);
        $this->assertStringContainsString('$queueForLater($tool, $args, $fileRef);', $carte);
        $this->assertStringContainsString("'message' => Scheduler::AUTO_HELD_NOTE", $carte);
        // Les outils de la tâche sont lus avant le dépôt (re-vérifiés au moment T).
        $this->assertLessThan(strpos($runSend, 'if ($scheduledMode === ScheduledTask::MODE_AUTO) {'), strpos($runSend, '$taskTools = $scheduled !== null'));

        $this->assertStringContainsString('attend son approbation', Scheduler::AUTO_HELD_NOTE);
        $note = Scheduler::promptNote(['allow_actions' => true, 'auto_actions' => true], ScheduledTask::MODE_AUTO);
        $this->assertStringContainsString('outil de l\'assistant', $note);
    }

    // ------------------------------------------------------------------
    // Schéma, exécuté sous node
    // ------------------------------------------------------------------

    public function testLeSchemaMontreLesOutilsDeLAssistant(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        $cat = [
            'labels'     => TaskDiagram::labels(),
            'icons'      => TaskDiagram::icons(),
            'tool_icons' => ['image' => '<svg data-t="image"></svg>', 'n8n' => '<img src="/assets/img/n8n.svg" alt="">'],
            'mia'        => ['name' => 'MIA', 'avatar' => '/assets/img/logo.png'],
            'assistants' => [
                '7' => ['name' => 'Veille', 'avatar' => '', 'color' => '#8b5cf6', 'description' => '', 'llm' => '', 'kbs' => [],
                        'tools' => [
                            ['id' => 6, 'name' => 'n8n RH', 'type' => 'n8n', 'kind' => 'Automatisation n8n', 'acting' => true],
                            ['id' => 3, 'name' => 'Images HD', 'type' => 'image', 'kind' => 'Image', 'acting' => false],
                        ]],
            ],
            'llms'         => [],
            'allowed_llms' => [],
            'default_llm'  => '',
            'sources'      => [],
            'kbs'          => [],
            'tools'        => ['2' => ['name' => 'Illustrations', 'type' => 'image', 'kind' => 'Image']],
        ];
        $base = ['name' => 'T', 'instruction' => 'x', 'profile_id' => 7, 'llm' => '', 'sources' => [], 'kb_ids' => [],
                 'tool_ids' => [], 'destination' => 'thread', 'notify' => false, 'trigger' => ['sentence' => 'Chaque jour', 'active' => true]];
        $scenarios = [
            'auto'      => ['allow_actions' => true, 'auto_actions' => true] + $base,
            'approuver' => ['allow_actions' => true, 'auto_actions' => false] + $base,
            'remplace'  => ['allow_actions' => true, 'auto_actions' => true, 'tool_ids' => [2]] + $base,
        ];
        $script = "'use strict';\n"
            . self::src('public/assets/js/diagram.js') . "\n"
            . self::src('public/assets/js/schedule-diagram.js') . "\n"
            . 'const D = globalThis.MiaScheduleDiagram;' . "\n"
            . 'const CAT = ' . json_encode($cat) . ";\n"
            . 'const SC = ' . json_encode($scenarios) . ";\n"
            . 'const out = {};' . "\n"
            . 'for (const [k, spec] of Object.entries(SC)) { const v = D.model(spec, CAT); out[k] = (v.groups.find((g) => g.kind === "tools") || { items: [] }).items; }' . "\n"
            . 'process.stdout.write(JSON.stringify(out));';
        $fichier = tempnam(sys_get_temp_dir(), 'mia-garde-js-');
        file_put_contents($fichier, $script);
        try {
            $processus = proc_open([$node, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($fichier);
        }
        $s = json_decode((string)$sortie, true);
        $this->assertSame(0, $code, $erreurs);
        $L = TaskDiagram::labels();

        // Sans validation : l'outil qui agit de l'assistant dit qu'il attend.
        $this->assertSame(['n8n RH', 'Images HD'], array_column($s['auto'], 'label'));
        $this->assertSame($L['via_assistant'] . ' · Automatisation n8n · ' . $L['tool_held'], $s['auto'][0]['note']);
        $this->assertSame($L['via_assistant'] . ' · Image', $s['auto'][1]['note']);
        // À approuver : tout attend déjà, rien de plus à dire.
        $this->assertSame($L['via_assistant'] . ' · Automatisation n8n', $s['approuver'][0]['note']);
        // Un outil de la tâche du même type passe avant celui de l'assistant.
        $this->assertSame(['Illustrations', 'n8n RH'], array_column($s['remplace'], 'label'));
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Documentation
    // ------------------------------------------------------------------

    public function testLaDocumentationLeDit(): void
    {
        $user = self::src('docs/user/fonctionnalites/conversations/programmer-une-tache.md');
        $this->assertStringContainsString('Assistant indisponible — à remplacer', $user);
        $this->assertStringContainsString('Les outils de l\'assistant n\'agissent pas seuls', $user);
        $this->assertStringContainsString('Les outils de l\'assistant n\'agissent pas seuls', self::src('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('id: taches-assistant-outils', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('apporté par l\'assistant', self::src('docs/user/admin/outils/regler-les-taches-programmees.md'));
    }
}
