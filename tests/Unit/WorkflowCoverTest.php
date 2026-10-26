<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// La définition d'exemple (condition dont une voie s'arrête) : celle du test de la brique d'arrêt.
require_once __DIR__ . '/WorkflowStopBrickTest.php';

/**
 * Vignettes de la liste des workflows (proposition C, « la couverture ») :
 * une couverture à la couleur du workflow, son déroulé en ligne de métro,
 * « Quand » et « Résultat », et toute la vignette qui ouvre le workflow.
 * Cf. CLAUDE.md « Workflows ».
 */
final class WorkflowCoverTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    private static function linear(array $extra = []): array
    {
        return WorkflowSpec::normalize($extra + [
            'objective' => 'Remettre un compte rendu relu.',
            'start' => ['fields' => [['key' => 'notes', 'label' => 'Notes', 'type' => 'longtext']], 'chat' => false],
            'nodes' => [
                ['key' => 'cr', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'x', 'profile_id' => 7,
                    'output' => ['key' => 'compte_rendu', 'name' => 'Compte rendu', 'form' => 'text']],
                ['key' => 'ok', 'type' => 'gate', 'title' => 'Relire'],
                ['key' => 'tri', 'type' => 'step', 'title' => 'Tâches', 'instruction' => 'y', 'profile_id' => 7,
                    'output' => ['key' => 'taches', 'name' => 'Tâches', 'form' => 'list']],
            ],
            'end' => ['pieces' => ['compte_rendu', 'taches']],
        ]);
    }

    public function testLaCouvertureGardeUnBlancLisibleQuelleQueSoitLaTeinte(): void
    {
        $colors = array_merge(array_column(WorkflowTemplates::catalog(), 'color'), ['#ffffff', '#fde047', '#84cc16', Workflow::DEFAULT_COLOR]);
        foreach ($colors as $c) {
            $cover = WorkflowCover::coverColor($c);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $cover);
            $rgb = [hexdec(substr($cover, 1, 2)), hexdec(substr($cover, 3, 2)), hexdec(substr($cover, 5, 2))];
            $this->assertGreaterThanOrEqual(WorkflowCover::MIN_CONTRAST, WorkflowCover::contrastWithWhite($rgb), "Blanc illisible sur la couverture de {$c}.");
        }
        $this->assertSame('#1d4ed8', WorkflowCover::coverColor('#1d4ed8'), 'Une teinte déjà sombre ne bouge pas.');
        $this->assertSame(WorkflowCover::coverColor(Workflow::DEFAULT_COLOR), WorkflowCover::coverColor('pas une couleur'));
        // La teinte est gardée : on ne fait qu'assombrir.
        $lime = WorkflowCover::coverColor('#84cc16');
        $this->assertGreaterThan(hexdec(substr($lime, 1, 2)), hexdec(substr($lime, 3, 2)), 'Le vert reste vert.');
    }

    public function testUneCleDExecutionSeRameneASaBrique(): void
    {
        foreach (['redaction' => 'redaction', 'redaction#2' => 'redaction', 'redaction~review' => 'redaction',
                  'redaction~review#3' => 'redaction', 'boucle~until' => 'boucle', '@end:boucle' => 'boucle'] as $key => $base) {
            $this->assertSame($base, WorkflowCover::baseKey($key), $key);
        }
    }

    public function testUnDerouleLineaireEstUneLigneDeStations(): void
    {
        $plan = WorkflowCover::layout(self::linear());
        $this->assertSame(['start', 'station', 'station', 'station', 'end'], array_column($plan['nodes'], 't'));
        $this->assertSame([0.0, 30.0, 60.0, 90.0, 120.0], array_column($plan['nodes'], 'x'));
        $this->assertSame(4, count($plan['edges']));
        $this->assertSame(0.0, $plan['minY']);
        $this->assertSame(0.0, $plan['maxY']);
    }

    public function testUnEmbranchementSeSepareSArreteEtSeRejoint(): void
    {
        $plan = WorkflowCover::layout(WorkflowStopBrickTest::definition());
        $types = array_column($plan['nodes'], 't');
        $this->assertSame(['start', 'fork', 'station', 'stop', 'join', 'station', 'end'], $types);
        $yes = $plan['nodes'][2];
        $stop = $plan['nodes'][3];
        $this->assertSame(-11.0, $yes['y'], 'Deux voies, de part et d’autre du rail.');
        $this->assertSame(11.0, $stop['y']);
        // La voie qui s'arrête ne rejoint pas le rail.
        $toJoin = array_values(array_filter($plan['edges'], static fn(array $e): bool => $e['b'] === 4));
        $this->assertCount(1, $toJoin);
        $this->assertSame(2, $toJoin[0]['a']);
        // Une voie réglée sur « arrêter » sans brique d'arrêt se termine aussi.
        $def = WorkflowSpec::normalize(['nodes' => [['key' => 'c', 'type' => 'condition', 'title' => 'C', 'mode' => 'question', 'question' => 'Q ?',
            'yes' => ['nodes' => [], 'then' => 'continue'], 'no' => ['nodes' => [], 'then' => 'stop']]]]);
        $plan = WorkflowCover::layout($def);
        $this->assertSame(['start', 'fork', 'stop', 'join', 'end'], array_column($plan['nodes'], 't'));
        $empty = array_values(array_filter($plan['edges'], static fn(array $e): bool => $e['empty']));
        $this->assertCount(1, $empty, 'La voie sans brique qui reprend le rail : un tronçon en pointillés.');
        $this->assertSame(-11.0, $empty[0]['ly']);
    }

    public function testUnEmbranchementDontAucuneVoieNeRevientLaisseLaSuiteEnPointilles(): void
    {
        $def = WorkflowSpec::normalize(['nodes' => [
            ['key' => 'c', 'type' => 'condition', 'title' => 'C', 'mode' => 'question', 'question' => 'Q ?',
                'yes' => ['nodes' => [], 'then' => 'stop'], 'no' => ['nodes' => [], 'then' => 'stop']],
            ['key' => 'apres', 'type' => 'gate', 'title' => 'Après'],
        ]]);
        $plan = WorkflowCover::layout($def);
        $last = array_slice($plan['edges'], -2);
        $this->assertTrue($last[0]['empty'] && $last[1]['empty'], 'Plus rien n’atteint la suite.');
    }

    public function testUneBoucleEstEncadreeEtSesVoiesSeResserrentAuDelaDeTrois(): void
    {
        $def = WorkflowSpec::normalize(['nodes' => [
            ['key' => 'b', 'type' => 'loop', 'title' => 'Améliorer', 'mode' => 'until', 'body' => [
                ['key' => 'v1', 'type' => 'step', 'title' => 'Version', 'instruction' => 'x', 'output' => ['key' => 'version', 'name' => 'Version', 'form' => 'text']],
                ['key' => 'av', 'type' => 'notify', 'title' => 'Prévenir', 'message' => 'm'],
            ]],
            ['key' => 'r', 'type' => 'route', 'title' => 'Trier', 'routes' => [
                ['key' => 'a', 'label' => 'A', 'nodes' => []], ['key' => 'b2', 'label' => 'B', 'nodes' => []],
                ['key' => 'c', 'label' => 'C', 'nodes' => []], ['key' => 'd', 'label' => 'D', 'nodes' => []],
            ]],
        ]]);
        $plan = WorkflowCover::layout($def);
        $this->assertSame([['key' => 'b', 'x0' => 30.0, 'x1' => 60.0, 'first' => 1]], $plan['frames']);
        $this->assertSame(-24.0, $plan['minY'], 'Quatre voies : écart resserré (16).');
        $this->assertSame(24.0, $plan['maxY']);
    }

    public function testLeDessinEstMuetAEchelleFixeEtAllumeLaBriqueEnCours(): void
    {
        $def = WorkflowStopBrickTest::definition();
        $svg = WorkflowCover::svg($def);
        $this->assertStringContainsString('<svg class="wf-metro"', $svg);
        $this->assertStringContainsString('aria-hidden="true" focusable="false"', $svg);
        $plan = WorkflowCover::layout($def);
        $vw = $plan['maxX'] + 12;
        $this->assertStringContainsString('width="' . (int)round($vw * WorkflowCover::SCALE) . '"', $svg, 'Même taille de station d’une vignette à l’autre.');
        $this->assertStringNotContainsString('wf-m-ring', $svg);
        $this->assertStringContainsString('class="wf-m-line is-lane"', $svg);
        $this->assertStringContainsString('class="wf-m-ghost"', $svg);

        // Une relecture, un passage de boucle : la brique elle-même s'allume.
        $ring = WorkflowCover::svg($def, 'note~review', 'wait');
        $this->assertSame(1, substr_count($ring, 'wf-m-ring'));
        $this->assertStringContainsString('<circle class="wf-m-ring is-wait" cx="60" cy="-11" r="10"/>', $ring);
        $this->assertStringContainsString('class="wf-m-ring is-run"', WorkflowCover::svg($def, 'relire', 'run'));
        $this->assertStringNotContainsString('wf-m-ring', WorkflowCover::svg($def, 'inconnue', 'run'));
        $this->assertStringNotContainsString('wf-m-ring', WorkflowCover::svg($def, 'relire', ''), 'Sans ton, aucun anneau.');
    }

    public function testQuandEtResultatSeLisentDansLaVignette(): void
    {
        $def = self::linear();
        $this->assertSame('Quand vous le lancez', WorkflowCover::when($def));
        $this->assertSame('hand', WorkflowCover::triggerIcon($def));
        $this->assertSame('Compte rendu, Tâches', WorkflowCover::result($def));
        $this->assertSame([7], WorkflowCover::profileIds($def));

        $chat = self::linear(['start' => ['chat' => true]]);
        $this->assertSame('Quand vous le lancez, ici ou depuis le chat', WorkflowCover::when($chat));
        $this->assertSame('chat', WorkflowCover::triggerIcon($chat));

        $sched = self::linear(['start' => ['schedule' => ['rule' => ['freq' => 'weekly', 'days' => [1], 'time' => '08:00'], 'timezone' => 'Europe/Paris'], 'chat' => false]]);
        $this->assertSame(Recurrence::describe($sched['start']['schedule']['rule']), WorkflowCover::when($sched));
        $this->assertSame('clock', WorkflowCover::triggerIcon($sched));

        $mail = self::linear(['start' => ['event' => ['kind' => 'email', 'source' => 'gmail', 'query' => '']]]);
        $this->assertSame('mail', WorkflowCover::triggerIcon($mail));
        $this->assertStringStartsWith('À chaque email reçu dans Gmail', WorkflowCover::when($mail));

        $sent = $def;
        $sent['end']['deliveries'] = [['type' => 'email'], ['type' => 'drive'], ['type' => 'email']];
        $this->assertSame('Compte rendu, Tâches · par email, dans Drive', WorkflowCover::result($sent));

        $none = $def;
        $none['end']['pieces'] = [];
        $this->assertSame('Remettre un compte rendu relu.', WorkflowCover::result($none), 'Sans livrable : l’objectif.');
    }

    public function testLEtatDitOuEnEstLeWorkflowEtOuMeneSaPastille(): void
    {
        $def = self::linear();
        $base = ['published' => $def, 'is_active' => 1, 'last_run_status' => null, 'last_run_id' => null, 'last_run_key' => null];

        $this->assertSame(['label' => 'Brouillon', 'tone' => 'draft', 'href' => null, 'ring' => '', 'key' => ''], WorkflowCover::state(['published' => null] + $base));
        $this->assertSame('pause', WorkflowCover::state(['is_active' => 0] + $base)['tone']);
        $this->assertSame('pause', WorkflowCover::state(['is_active' => 0, 'last_run_status' => WorkflowRun::DONE, 'last_run_id' => 42] + $base)['tone']);
        // Workflow en pause, mais une exécution vous attend : c'est elle qu'on voit.
        $held = WorkflowCover::state(['is_active' => 0, 'last_run_status' => WorkflowRun::WAITING, 'last_run_id' => 42, 'last_run_key' => 'ok'] + $base);
        $this->assertSame(['Attend votre décision', 'wait', '/workflows/runs/42', 'wait'], [$held['label'], $held['tone'], $held['href'], $held['ring']]);
        // Une exécution plus ancienne attend votre décision : elle passe devant la dernière, terminée.
        $older = WorkflowCover::state(['last_run_status' => WorkflowRun::CANCELLED, 'last_run_id' => 50, 'last_run_key' => 'tri'] + $base, false, ['id' => 41, 'current_key' => 'cr~review']);
        $this->assertSame(['Attend votre décision', '/workflows/runs/41', 'wait', 'cr~review'], [$older['label'], $older['href'], $older['ring'], $older['key']]);
        $this->assertSame(['label' => 'Publié', 'tone' => 'idle', 'href' => null, 'ring' => '', 'key' => ''], WorkflowCover::state($base));

        $running = WorkflowCover::state(['last_run_status' => WorkflowRun::RUNNING, 'last_run_id' => 42, 'last_run_key' => 'ok'] + $base);
        $this->assertSame(['label' => 'En cours · 2/3', 'tone' => 'run', 'href' => '/workflows/runs/42', 'ring' => 'run', 'key' => 'ok'], $running);

        $this->assertSame('wait', WorkflowCover::state(['last_run_status' => WorkflowRun::WAITING, 'last_run_id' => 42, 'last_run_key' => 'ok'] + $base)['ring']);
        $timer = WorkflowCover::state(['last_run_status' => WorkflowRun::WAITING, 'last_run_id' => 42] + $base, true);
        $this->assertSame(['Reprendra seule', 'run'], [$timer['label'], $timer['ring']]);
        $this->assertSame('err', WorkflowCover::state(['last_run_status' => WorkflowRun::FAILED, 'last_run_id' => 42] + $base)['ring']);
        $done = WorkflowCover::state(['last_run_status' => WorkflowRun::DONE, 'last_run_id' => 42] + $base);
        $this->assertSame(['Terminé', 'ok', '/workflows/runs/42', ''], [$done['label'], $done['tone'], $done['href'], $done['ring']]);
        $this->assertSame('', $done['key'] ?? 'absent', 'Exécution finie : aucun anneau.');
        $this->assertSame([2, 3], WorkflowCover::progress($def, 'ok'));
        $this->assertNull(WorkflowCover::progress($def, WorkflowSpec::END_KEY));
    }

    public function testLaVignetteEntiereOuvreLeWorkflow(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/workflows/index.php');
        $this->assertStringContainsString('WorkflowCover::svg(', $tpl);
        $this->assertStringContainsString('WorkflowCover::coverColor(', $tpl);
        $this->assertStringContainsString('class="wf-cc-link"', $tpl, 'Le nom porte le lien qui couvre toute la vignette.');
        // Vue détaillée = l'éditeur ; sans droit de modifier, la dernière exécution.
        $this->assertMatchesRegularExpression('/\$wfCanEdit\s*\?\s*\'\/workflows\/\'\s*\.\s*\(int\)\$w\[\'id\'\]\s*\.\s*\'\/edit\'/', $tpl);
        // Lancer, la pastille d'état et la pause restent des gestes à part, au-dessus du lien.
        $this->assertStringContainsString('class="wf-btn sm primary wf-over" data-launch="', $tpl);
        $this->assertStringContainsString('wf-cc-state wf-over', $tpl);
        $this->assertStringNotContainsString('wf-card-list', $tpl);

        $css = (string)file_get_contents(ROOT . '/public/assets/css/workflows.css');
        $this->assertStringContainsString('.wf-cc-link::after', $css);
        $this->assertMatchesRegularExpression('/\.wf-over\s*\{[^}]*z-index:\s*2/', $css);
        $this->assertStringContainsString('.wf-cover-card:has(.wf-cc-link:focus-visible)', $css, 'Le focus clavier entoure toute la vignette.');
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.wf-m-ring/', $css);
    }

    public function testLesTextesDeLaVignetteSontTraduits(): void
    {
        $en = require ROOT . '/lang/en.php';
        foreach (['{rythme}, ou depuis le chat', 'Quand vous le lancez, ici ou depuis le chat', 'Quand vous le lancez', 'par email',
                  'dans Drive', 'dans Slack', 'dans une base de connaissances', '{statut} · {n}/{total}', 'Quand', 'Résultat'] as $key) {
            $this->assertArrayHasKey($key, $en, "Clé absente du dictionnaire anglais : {$key}");
        }
    }
}
