<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Brique « Arrêter le workflow » (cf. CLAUDE.md « Workflows ») : elle ne vit
 * qu'au bout d'une voie, la voie qui la porte arrête le workflow (pièces
 * disponibles après l'embranchement comprises), et le rail du navigateur
 * compte comme le serveur. Plus le correctif du grisage : la surbrillance
 * d'une pièce ne survit ni à la fermeture du volet ni au changement de
 * brique.
 */
final class WorkflowStopBrickTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    /** Facture → condition : « oui » remet une note, « non » s'arrête par la brique. */
    public static function definition(array $stop = []): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fields' => [['key' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true]]],
            'nodes' => [
                ['key' => 'seuil', 'type' => 'condition', 'title' => 'Gros montant ?', 'mode' => 'rule',
                    'rule' => ['join' => 'all', 'items' => [['piece' => 'depart', 'field' => 'montant', 'op' => 'gt', 'value' => '5000']]],
                    'yes' => ['nodes' => [['key' => 'note', 'type' => 'step', 'title' => 'Rédiger la note', 'instruction' => 'Note sur @{depart}.',
                        'output' => ['key' => 'note_finale', 'name' => 'Note', 'form' => 'text']]], 'then' => 'continue'],
                    'no' => ['nodes' => [$stop + ['key' => 'fin', 'type' => 'stop', 'title' => 'Classer sans accord', 'message' => 'Montant : @{depart.montant}.']],
                        'then' => 'continue']],
                ['key' => 'relire', 'type' => 'step', 'title' => 'Relire', 'instruction' => 'Relis @{note_finale}.',
                    'output' => ['key' => 'relue', 'name' => 'Note relue', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['relue']],
        ]);
    }

    public function testLaBriqueNeVitQuAuBoutDUneVoie(): void
    {
        $stop = ['key' => 'fin', 'type' => 'stop', 'title' => 'Fin', 'message' => '', 'outcome' => 'stopped'];
        $def = WorkflowSpec::normalize([
            'nodes' => [
                $stop,
                ['key' => 'boucle', 'type' => 'loop', 'title' => 'Boucle', 'mode' => 'until', 'body' => [$stop + ['key' => 'fin2']]],
                ['key' => 'cond', 'type' => 'condition', 'title' => 'Cond', 'yes' => ['nodes' => [$stop + ['key' => 'fin3']]], 'no' => ['nodes' => []]],
                ['key' => 'route', 'type' => 'route', 'title' => 'Route', 'routes' => [
                    ['key' => 'a', 'label' => 'A', 'nodes' => [['key' => 'fin4', 'type' => 'stop', 'outcome' => 'n’importe quoi']]],
                    ['key' => 'b', 'label' => 'B', 'nodes' => []],
                ]],
            ],
        ]);
        $this->assertSame(['boucle', 'cond', 'route'], array_column($def['nodes'], 'key'), 'Sur le rail : écartée.');
        $this->assertSame([], $def['nodes'][0]['body'], 'Dans une boucle : écartée.');
        $this->assertSame('stop', $def['nodes'][1]['yes']['nodes'][0]['type'], 'Dans une voie de condition : gardée.');
        $this->assertSame(WorkflowSpec::STOP_STOPPED, $def['nodes'][1]['yes']['nodes'][0]['outcome']);
        $this->assertSame(WorkflowSpec::STOP_DONE, $def['nodes'][2]['routes'][0]['nodes'][0]['outcome'], 'Statut inconnu : « Terminée ».');
        $this->assertSame(2, WorkflowSpec::counts($def)['stops']);
    }

    public function testUneVoieQuiPorteLaBriqueArreteLeWorkflow(): void
    {
        $def = self::definition();
        $this->assertTrue(WorkflowSpec::laneStops($def['nodes'][0]['no']), 'La brique arrête la voie, quelle que soit sa suite.');
        $this->assertFalse(WorkflowSpec::laneStops($def['nodes'][0]['yes']));
        // Seule la voie « oui » continue : sa note est garantie après la condition.
        $this->assertArrayHasKey('note_finale', WorkflowSpec::available($def, 'relire'));
        $this->assertSame([], array_filter(WorkflowSpec::issues($def, []), static fn($i) => $i['node'] === 'relire' && $i['level'] === 'block'),
            'La relecture peut citer la note.');

        // Sans la brique, la voie « non » continuerait : la note ne serait pas garantie.
        $sans = $def;
        $sans['nodes'][0]['no']['nodes'] = [];
        $this->assertArrayNotHasKey('note_finale', WorkflowSpec::available($sans, 'relire'));
    }

    public function testCeQuiSuitLaBriqueEstSignale(): void
    {
        $def = self::definition();
        $def['nodes'][0]['no']['nodes'][] = ['key' => 'apres', 'type' => 'notify', 'title' => 'Après', 'message' => 'Jamais.'];
        $def = WorkflowSpec::normalize($def);
        $issues = WorkflowSpec::issues($def, []);
        $apres = array_values(array_filter($issues, static fn($i) => $i['node'] === 'apres'));
        $this->assertSame(WorkflowSpec::LEVEL_WARN, $apres[0]['level'] ?? null);
        $this->assertStringContainsString('« Classer sans accord »', $apres[0]['text']);

        // Le mot de la fin ne cite que ce qui existe à ce point.
        $faux = self::definition(['message' => 'Voir @{relue}.']);
        $this->assertNotEmpty(array_filter(WorkflowSpec::issues($faux, []), static fn($i) => $i['node'] === 'fin' && $i['level'] === WorkflowSpec::LEVEL_BLOCK));
    }

    public function testCreerWorkflowSaitArreterUneVoie(): void
    {
        $res = WorkflowDraft::fromArgs(['nom' => 'Factures', 'briques' => [
            ['type' => 'arreter', 'titre' => 'Trop tôt'],
            ['type' => 'condition', 'titre' => 'Gros ?', 'question' => 'Le montant dépasse-t-il 5 000 € ?',
                'si_oui' => ['briques' => [['type' => 'prevenir', 'message' => 'Gros montant.']]],
                'si_non' => ['briques' => [['type' => 'arreter', 'titre' => 'Classée', 'message' => 'Rien à faire.', 'statut' => 'Arrêtée']], 'ensuite' => 'continuer']],
        ]], ['profiles' => [], 'sources' => []]);
        $def = $res['definition'];
        $this->assertSame(['condition'], array_column($def['nodes'], 'type'), 'Hors d’une voie : écartée.');
        $this->assertNotEmpty(array_filter($res['notes'], static fn($n) => str_contains($n, 'Arrêter le workflow')));
        $fin = $def['nodes'][0]['no']['nodes'][0];
        $this->assertSame(['stop', 'Classée', WorkflowSpec::STOP_STOPPED], [$fin['type'], $fin['title'], $fin['outcome']]);
        $lines = WorkflowDraft::railLines($def, ['profiles' => [], 'creations' => []]);
        $stops = array_values(array_filter($lines, static fn($l) => $l['kind'] === 'stop'));
        $this->assertCount(1, $stops, 'Une ligne pour la brique, pas une seconde « fin du workflow ».');
        $this->assertSame('statut Arrêtée', $stops[0]['sub']);
        $this->assertStringContainsString('"arreter"', WorkflowDraft::schemaGuide([]));
    }

    public function testLaNotificationDitLaFinSansContenu(): void
    {
        $fin = WorkflowNotifier::message('Factures', 4, WorkflowNotifier::ENDED, 'Classée sans accord');
        $this->assertSame('Le workflow s’est terminé : « Classée sans accord ».', $fin['body']);
        $this->assertSame('Le workflow s’est terminé, sans livrable.', WorkflowNotifier::message('Factures', 4, WorkflowNotifier::ENDED, '')['body']);
        $this->assertSame('Le workflow s’est arrêté comme prévu.', WorkflowNotifier::message('Factures', 4, WorkflowNotifier::STOPPED, '')['body']);
        $this->assertSame('', WorkflowEngine::stopTitle(['title' => 'Arrêter le workflow']), 'Le titre par défaut n’apprend rien.');
        $this->assertSame('Classée', WorkflowEngine::stopTitle(['title' => 'Classée']));
        I18n::use('en');
        try {
            $this->assertSame('The workflow finished: “X”.', WorkflowNotifier::message('W', 1, WorkflowNotifier::ENDED, 'X')['body']);
        } finally {
            I18n::use('fr');
        }
    }

    public function testLeMoteurEtLesEcransConnaissentLaBrique(): void
    {
        $engine = (string)file_get_contents(ROOT . '/src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString('WorkflowSpec::TYPE_STOP      => self::doStop($run, $node)', $engine);
        $this->assertMatchesRegularExpression('/function doStop\b[\s\S]{0,2500}WorkflowRun::STOPPED : WorkflowRun::DONE/', $engine);
        $this->assertDoesNotMatchRegularExpression('/function doStop\b[\s\S]{0,2500}afterRunDone/', $engine, 'Aucun workflow enchaîné : rien n’est livré.');

        $editor = (string)file_get_contents(ROOT . '/public/assets/js/workflow-editor.js');
        $this->assertStringContainsString("type: 'stop', title: T('Arrêter le workflow')", $editor);
        $this->assertStringContainsString("canStop ? item('stop') : null", $editor, 'Proposée dans une voie seulement, une fois.');
        $this->assertStringContainsString('stop: stopInspector', $editor);
        $this->assertStringContainsString("indexOf('stop')", $editor, 'Une voie qui finit par l’arrêt : on ajoute avant lui.');

        $run = (string)file_get_contents(ROOT . '/public/assets/js/workflow-run.js');
        $this->assertStringContainsString("endNode.type === 'stop'", $run);
        $this->assertStringContainsString("node.type === 'stop'", $run);
    }

    public function testLeRailDuNavigateurCompteLArretCommeLeServeur(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $def = self::definition();
        $keys = ['seuil', 'note', 'fin', 'relire', 'end'];
        $expected = [];
        foreach ($keys as $k) {
            $expected[$k] = array_keys(WorkflowSpec::available($def, $k));
        }
        $cat = ['icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES, 'labels' => WorkflowCatalog::labels(), 'colors' => WorkflowCatalog::FORM_COLORS];
        $script = 'require(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ');'
            . 'var R = globalThis.MiaWorkflowRail; var d = ' . json_encode($def, JSON_UNESCAPED_UNICODE) . '; var cat = ' . json_encode($cat, JSON_UNESCAPED_UNICODE) . ';'
            . 'var av = {}; ' . json_encode($keys) . '.forEach(function (k) { av[k] = Object.keys(R.available(d, k)); });'
            . 'process.stdout.write(JSON.stringify({ available: av, html: R.html(d, { catalog: cat, editable: true }), stops: R.laneStops(d.nodes[0].no) }));';
        $file = tempnam(sys_get_temp_dir(), 'wfstop') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertIsArray($got, 'workflow-rail.js a échoué sous node.');
        $this->assertSame($expected, $got['available']);
        $this->assertTrue($got['stops']);
        $html = $got['html'];
        $this->assertStringContainsString('Classer sans accord', $html);
        $this->assertStringContainsString('Fin du workflow · Terminée', $html, 'La brique dit la fin et le statut.');
        // La voie « non » finit par la brique : ni « Reprend le rail » ni seconde « Fin du workflow ».
        $no = substr($html, (int)strpos($html, 'data-lane="no"'));
        $this->assertStringNotContainsString('wf-lane-end', substr($no, 0, (int)strpos($no, 'class="wf-link') ?: strlen($no)));
        $this->assertStringNotContainsString('data-tool="dup" data-node="fin"', $html, 'Une brique d’arrêt ne se duplique pas.');
        $this->assertStringNotContainsString('has-hl', $html, 'Le rail ne pose jamais la surbrillance lui-même.');
    }

    public function testLaSurbrillanceNeSurvitPasAuVolet(): void
    {
        $editor = (string)file_get_contents(ROOT . '/public/assets/js/workflow-editor.js');
        // Le rail redessiné reprend la surbrillance par highlight(), qui pose
        // has-hl ET is-hl ensemble : jamais un rail gris sans pièce allumée.
        $this->assertMatchesRegularExpression('/function renderRail\(\) \{\s*railHost\.innerHTML = R\.html\([^;]+;\s*(?:\/\/[^\n]*\n\s*)?R\.highlight\(railHost, state\.highlight\);/', $editor);
        // Fermer le volet ou changer de brique éteint la surbrillance.
        $this->assertMatchesRegularExpression('/function select\(key, spot\) \{\s*if \(key === null \|\| key !== state\.selected\) \{ state\.highlight = null; \}/', $editor);
        $this->assertStringContainsString('function clearHighlight()', $editor);

        $run = (string)file_get_contents(ROOT . '/public/assets/js/workflow-run.js');
        $this->assertStringContainsString('if (p !== panel) { editing = null; highlight = null; }', $run);
        $this->assertStringContainsString('R.highlight(railHost, highlight);', $run);

        $rail = (string)file_get_contents(ROOT . '/public/assets/js/workflow-rail.js');
        $this->assertStringNotContainsString("'has-hl'", substr($rail, 0, (int)strpos($rail, 'function highlight(')), 'has-hl n’est posé que par highlight().');
    }
}
