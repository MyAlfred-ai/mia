<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// La définition d'exemple (facture, voie qui s'arrête) : celle du test de la brique d'arrêt.
require_once __DIR__ . '/WorkflowStopBrickTest.php';

/**
 * Embranchements dessinés en ARBRE (cf. CLAUDE.md « Workflows ») : les voies
 * d'une condition ou d'une décision côte à côte, en colonnes de même largeur,
 * une couleur par voie, des liaisons qui se séparent sous la question et se
 * rejoignent sur un point de jonction — sauf la voie qui arrête le workflow.
 * Le rendu reste pur (html) ; les liaisons sont tracées dans la page
 * (drawForks), d'après la position réelle des briques.
 */
final class WorkflowTreeTest extends TestCase
{
    private const RAIL = ROOT . '/public/assets/js/workflow-rail.js';
    private const CSS = ROOT . '/public/assets/css/workflows.css';

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    /** Exécute le rail sous node et rend la sortie JSON du script. */
    private function node(string $body): array
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $cat = ['icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES, 'labels' => WorkflowCatalog::labels(), 'colors' => WorkflowCatalog::FORM_COLORS];
        $script = 'require(' . json_encode(self::RAIL) . '); var R = globalThis.MiaWorkflowRail;'
            . 'var cat = ' . json_encode($cat, JSON_UNESCAPED_UNICODE) . ';' . $body;
        $file = tempnam(sys_get_temp_dir(), 'wftree') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertIsArray($got, 'workflow-rail.js a échoué sous node.');
        return $got;
    }

    public function testLesVoiesSontDesColonnesDeCouleurQuiSeRejoignent(): void
    {
        $def = WorkflowStopBrickTest::definition();
        $got = $this->node('var d = ' . json_encode($def, JSON_UNESCAPED_UNICODE) . ';'
            . 'process.stdout.write(JSON.stringify({ html: R.html(d, { catalog: cat, editable: true }) }));');
        $html = $got['html'];
        // Une colonne par voie, toutes sur une rangée ; le rail sait combien il en porte.
        $this->assertStringContainsString('class="wf-fork wf-fork-condition" style="--lanes:2" data-fork-of="seuil"', $html);
        $this->assertStringContainsString('style="--fork-max:2"', $html);
        $this->assertStringContainsString('<svg class="wf-wires" aria-hidden="true"', $html, 'Les liaisons sont un dessin, muet pour un lecteur d’écran.');
        // Une couleur par voie, dans l'ordre.
        $this->assertMatchesRegularExpression('/data-lane="yes" style="--lane:var\(--wf-l1\)"/', $html);
        $this->assertMatchesRegularExpression('/data-lane="no" style="--lane:var\(--wf-l2\)"/', $html);
        // « Si oui » rejoint le rail ; « Si non » finit par « Arrêter le workflow » : elle ne revient pas.
        $this->assertMatchesRegularExpression('/class="wf-lane wf-lane-yes is-exit"/', $html);
        $this->assertMatchesRegularExpression('/class="wf-lane wf-lane-no"/', $html);
        $this->assertStringContainsString('<div class="wf-lane-pill"><span class="wf-lane-key">', $html);
        $this->assertStringContainsString('<span class="wf-lane-end wf-sr">Reprend le rail</span>', $html, 'La reprise reste dite aux lecteurs d’écran.');
        $this->assertStringContainsString('<div class="wf-join" aria-hidden="true"><i></i></div>', $html);
        $this->assertStringNotContainsString('wf-join is-none', $html);
    }

    public function testUneJonctionQueRienNeRejointLeDit(): void
    {
        $def = WorkflowSpec::normalize([
            'nodes' => [['key' => 'c', 'type' => 'condition', 'title' => 'C', 'mode' => 'question', 'question' => 'Q ?',
                'yes' => ['nodes' => [], 'then' => 'stop'], 'no' => ['nodes' => [], 'then' => 'stop']]],
        ]);
        $got = $this->node('var d = ' . json_encode($def, JSON_UNESCAPED_UNICODE) . ';'
            . 'process.stdout.write(JSON.stringify({ html: R.html(d, { catalog: cat }) }));');
        $this->assertStringContainsString('wf-join is-none', $got['html']);
        $this->assertSame(2, substr_count($got['html'], 'wf-lane-end is-stop'), 'Une voie qui arrête sans brique le dit au bas de sa colonne.');
        $this->assertStringNotContainsString('is-exit', $got['html']);
    }

    public function testEnExecutionLaVoiePriseEtLesAutresSontNommees(): void
    {
        $def = WorkflowStopBrickTest::definition();
        $state = ['status' => 'running', 'nodes' => ['seuil' => ['status' => 'done', 'outcome' => 'yes']]];
        $got = $this->node('var d = ' . json_encode($def, JSON_UNESCAPED_UNICODE) . '; var st = ' . json_encode($state) . ';'
            . 'process.stdout.write(JSON.stringify({ html: R.html(d, { catalog: cat, state: st }) }));');
        $html = $got['html'];
        $this->assertMatchesRegularExpression('/class="wf-lane wf-lane-yes is-taken is-exit"/', $html);
        $this->assertMatchesRegularExpression('/class="wf-lane wf-lane-no is-skipped"/', $html);
        $this->assertStringContainsString('<span class="wf-lane-state is-taken">voie prise</span>', $html);
        $this->assertStringContainsString('<span class="wf-lane-state">écartée</span>', $html);
    }

    public function testLesBranchesDUnMemeCoteSeSuiventSansSeSuperposer(): void
    {
        $got = $this->node('process.stdout.write(JSON.stringify({'
            . 'two: R.branchOrigins([100, 300], 200),'
            . 'three: R.branchOrigins([50, 200, 350], 200),'
            . 'four: R.branchOrigins([50, 150, 250, 350], 200),'
            . 'six: R.branchOrigins([10, 30, 50, 70, 90, 110], 60),'
            . 'straight: R.splitPath(200, 17, 200, 48),'
            . 'right: R.splitPath(200, 17, 300, 48),'
            . 'merge: R.mergePath(100, 200, 215, 200)'
            . '}));');
        // Deux et trois voies : toutes partent du tronc (une à gauche, une à droite, une tout droit).
        $this->assertSame([200, 200], $got['two']);
        $this->assertSame([200, 200, 200], $got['three']);
        // Au-delà : la plus proche du centre part du tronc, la suivante de sa voisine.
        $this->assertSame([150, 200, 200, 250], $got['four']);
        $this->assertSame([30, 50, 60, 60, 70, 90], $got['six']);
        $this->assertSame('M200 17V48', $got['straight']);
        $this->assertSame('M200 17H288Q300 17 300 29V48', $got['right'], 'Un coude arrondi, rayon 12.');
        $this->assertSame('M100 200V203Q100 215 112 215H200', $got['merge']);
    }

    public function testLesLiaisonsSontTraceesApresChaqueRendu(): void
    {
        $rail = (string)file_get_contents(self::RAIL);
        $drawFork = substr($rail, (int)strpos($rail, 'function drawFork('), 6000);
        // Positions lues par offsetLeft/offsetTop : en pixels de page, le zoom d'affichage
        // et les animations n'y changent rien (cf. « Affichage par utilisateur »).
        $this->assertStringContainsString('offsetIn(', $drawFork);
        $this->assertStringNotContainsString('getBoundingClientRect', $rail);
        $this->assertStringContainsString('ResizeObserver', $rail, 'Volet ouvert, fenêtre redimensionnée : les liaisons suivent.');
        foreach (['workflow-editor.js', 'workflow-run.js'] as $f) {
            $js = (string)file_get_contents(ROOT . '/public/assets/js/' . $f);
            $this->assertMatchesRegularExpression('/R\.highlight\(railHost, (state\.)?highlight\);\s*(\/\/[^\n]*\n\s*)?R\.drawForks\(railHost\);/', $js, "{$f} trace les liaisons après chaque rendu du rail.");
        }
    }

    public function testCouleursDesVoiesEtTroncCentre(): void
    {
        $css = (string)file_get_contents(self::CSS);
        // Six couleurs de voie, dans les deux thèmes, avec la couleur du texte posé dessus.
        for ($i = 1; $i <= 6; $i++) {
            $this->assertSame(2, preg_match_all('/--wf-l' . $i . ': #[0-9a-f]{6}/', $css), "Couleur de voie {$i} dans les deux thèmes.");
        }
        $this->assertSame(2, substr_count($css, '--wf-on-lane:'));
        // Le tronc est au milieu : liens, paquets et « + » suivent --lx.
        $this->assertMatchesRegularExpression('/\.wf-rail \{\s*--lx: 50%;/', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(var(--lanes, 2), minmax(200px, 1fr))', $css, 'Des colonnes de même largeur.');
        // Au téléphone, les voies passent l'une sous l'autre.
        $phone = substr($css, (int)strpos($css, '@media (max-width: 640px)'));
        $this->assertStringContainsString('.wf-lanes { grid-template-columns: minmax(0, 1fr);', $phone);
        // En exécution : la voie prise s'épaissit, les autres pâlissent en pointillés.
        $this->assertStringContainsString('.wf-wire.is-skipped { opacity: .32; stroke-dasharray: 2 8; }', $css);
        $this->assertStringContainsString('.wf-wire.is-taken { stroke-width: 4; }', $css);
    }
}
