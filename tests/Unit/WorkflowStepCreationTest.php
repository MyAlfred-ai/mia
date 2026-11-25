<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Une ÉTAPE dont la pièce est une création (image, page web, carte) : sa
 * forme, ce qui bloque la publication (« À régler »), la consigne et la
 * lecture de sa réponse (la DEMANDE rédigée pour l'outil), sa vue en brique
 * Création, les boucles, l'ajustement à la personne, la création depuis le
 * chat, le rail du navigateur exécuté sous node, le câblage du moteur et des
 * écrans.
 */
final class WorkflowStepCreationTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    private static function definition(array $output = [], array $extra = []): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fields' => [['key' => 'produit', 'label' => 'Produit', 'type' => 'text']]],
            'nodes' => [
                ['key' => 'visu', 'type' => 'create', 'title' => 'Croquis', 'kind' => 'image', 'tool_id' => 5,
                    'instruction' => 'Un croquis de @{depart.produit}', 'output' => ['key' => 'croquis', 'name' => 'Croquis']],
                ['key' => 'graph', 'type' => 'step', 'title' => 'Préparer le visuel', 'instruction' => 'Le visuel final de @{depart.produit} d’après @{croquis}',
                    'output' => $output + ['key' => 'visuel', 'name' => 'Visuel', 'form' => 'image', 'tool_id' => 5, 'options' => ['format' => 'portrait']]] + $extra,
            ],
            'end' => ['pieces' => ['visuel']],
        ]);
    }

    private static function ctx(array $creations): array
    {
        return ['creations' => $creations, 'profiles' => [], 'llms' => ['openai::gpt-5.6' => 'GPT-5.6'], 'default_llm' => 'openai::gpt-5.6', 'auto' => true];
    }

    private static function tool(int $id, string $kind, ?string $reason = null, bool $canEdit = true): array
    {
        return ['id' => $id, 'name' => 'Outil ' . $id, 'type' => $kind === 'page' ? 'html' : $kind, 'kind' => $kind, 'reason' => $reason, 'can_edit' => $canEdit];
    }

    /** Points « À régler » d'une brique : cible → texte. */
    private static function issuesOf(array $def, array $ctx, string $key): array
    {
        $out = [];
        foreach (WorkflowSpec::issues($def, $ctx) as $i) {
            if ($i['node'] === $key) {
                $out[] = $i['target'] . '|' . $i['level'] . '|' . $i['text'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Forme
    // ------------------------------------------------------------------

    public function testUneEtapePeutRemettreUneCreation(): void
    {
        $o = WorkflowSpec::node(self::definition(), 'graph')['output'];
        $this->assertSame(['key' => 'visuel', 'name' => 'Visuel', 'form' => 'image', 'tool_id' => 5, 'options' => ['format' => 'portrait', 'background' => '', 'output_format' => '']], $o);
        $this->assertSame('image', WorkflowSpec::pieces(self::definition())['visuel']['form']);

        $page = WorkflowSpec::node(self::definition(['form' => 'page', 'tool_id' => 6, 'options' => ['style' => 'aquarelle', 'format' => 'square']]), 'graph')['output'];
        $this->assertSame(['form' => 'page', 'tool_id' => 6, 'options' => ['style' => 'aquarelle']], array_intersect_key($page, array_flip(['form', 'tool_id', 'options'])));
        $map = WorkflowSpec::node(self::definition(['form' => 'map', 'tool_id' => null]), 'graph')['output'];
        $this->assertSame(['map', null, []], [$map['form'], $map['tool_id'], $map['options']]);

        // Une forme rédigée ne porte ni outil ni réglages ; une forme inconnue retombe sur le texte.
        $text = WorkflowSpec::node(self::definition(['form' => 'text']), 'graph')['output'];
        $this->assertArrayNotHasKey('tool_id', $text);
        $this->assertArrayNotHasKey('options', $text);
        $this->assertSame('text', WorkflowSpec::node(self::definition(['form' => 'video']), 'graph')['output']['form']);
        $this->assertSame('', WorkflowSpec::node(self::definition(['options' => ['format' => 'cinemascope']]), 'graph')['output']['options']['format']);

        foreach (array_keys(WorkflowSpec::CREATE_KINDS) as $kind) {
            $this->assertTrue(WorkflowSpec::isCreationForm($kind));
        }
        foreach (WorkflowSpec::FORMS as $form) {
            $this->assertFalse(WorkflowSpec::isCreationForm($form));
        }
        $this->assertFalse(WorkflowSpec::isCorrectable(WorkflowSpec::pieces(self::definition())['visuel']), 'Une image se renvoie, elle ne se corrige pas à la main.');
    }

    public function testCeQuiBloqueLaPublication(): void
    {
        $ok = self::ctx([5 => self::tool(5, 'image')]);
        $this->assertSame([], self::issuesOf(self::definition(), $ok, 'graph'), 'Un outil en service : rien à régler.');

        $none = self::issuesOf(self::definition(['tool_id' => null]), $ok, 'graph');
        $this->assertSame(['form|block|Choisissez l’outil qui crée la pièce de l’étape.'], $none);
        $this->assertSame(['form|block|Cet outil ne vous est plus ouvert : choisissez-en un autre.'], self::issuesOf(self::definition(), self::ctx([]), 'graph'));
        $this->assertSame(['form|block|Cet outil ne fait pas la forme choisie : choisissez-en un autre.'], self::issuesOf(self::definition(['form' => 'page']), $ok, 'graph'));
        $this->assertSame(['form|block|Arrêté par son fournisseur.'], self::issuesOf(self::definition(), self::ctx([5 => self::tool(5, 'image', 'Arrêté par son fournisseur.')]), 'graph'));

        // Une image lue par un outil qui ne sait pas partir d'une image : seule sa description lui parvient.
        $warn = self::issuesOf(self::definition(), self::ctx([5 => self::tool(5, 'image', null, false)]), 'graph');
        $this->assertSame(['form|warn|Cet outil ne sait pas partir d’une image : « Croquis » ne lui sera pas montrée, seule sa description.'], $warn);
        $this->assertFalse(WorkflowSpec::publishable(WorkflowSpec::issues(self::definition(['tool_id' => null]), $ok)));
        $this->assertTrue(WorkflowSpec::publishable(WorkflowSpec::issues(self::definition(), $ok)));
    }

    // ------------------------------------------------------------------
    // Consigne et lecture de la réponse
    // ------------------------------------------------------------------

    public function testLaConsigneDemandeLaDemandeEtPasLaPiece(): void
    {
        $pieces = WorkflowSpec::pieces(self::definition());
        $image = ArtifactContract::instruction($pieces['visuel']);
        $this->assertStringContainsString('La pièce « Visuel » du dossier sera fabriquée par un outil de création juste après ton tour', $image);
        $this->assertStringContainsString('DESCRIPTION VISUELLE', $image);
        $this->assertStringContainsString('Ne fabrique pas la pièce toi-même', $image);
        $this->assertStringContainsString('TOUT LE CONTENU de la page web', ArtifactContract::instruction(['name' => 'Site', 'form' => 'page']));
        $this->assertStringContainsString('ITINÉRAIRE à tracer', ArtifactContract::instruction(['name' => 'Tournée', 'form' => 'map']));

        // La note de méthode de l'étape porte cette consigne, et l'image lue y est jointe.
        $def = self::definition();
        $note = WorkflowPrompt::note('Campagne', $def, WorkflowSpec::node($def, 'graph'), []);
        $this->assertStringContainsString('DESCRIPTION VISUELLE', $note);
    }

    public function testLaReponseEstLaDemandeTransmiseALOutil(): void
    {
        $piece = ['key' => 'visuel', 'name' => 'Visuel', 'form' => 'image'];
        $read = ArtifactContract::extract($piece, "```\nUn vélo sur fond bleu, lumière de studio.\n```");
        $this->assertSame(['ok' => true, 'content' => ['text' => 'Un vélo sur fond bleu, lumière de studio.'], 'error' => ''], $read);
        $this->assertSame('Un vélo.', ArtifactContract::extract($piece, "![x](/assets/img/generated/a.png)\n\nUn vélo.")['content']['text'], 'Une image collée par le modèle n’est pas la demande.');
        $empty = ArtifactContract::extract($piece, '![x](/assets/img/generated/a.png)');
        $this->assertFalse($empty['ok']);
        $this->assertSame("la réponse ne contient pas la demande à transmettre à l'outil de création", $empty['error']);
        $this->assertFalse(ArtifactContract::extract($piece, '⚠️ Erreur')['ok']);
        $long = str_repeat('mot ', 3000);
        $this->assertSame(ArtifactContract::BRIEF_IMAGE_CHARS, mb_strlen(ArtifactContract::extract($piece, $long)['content']['text']));
        $this->assertSame(ArtifactContract::BRIEF_CHARS, mb_strlen(ArtifactContract::extract(['name' => 'S', 'form' => 'page'], str_repeat('mot ', 6000))['content']['text']));
    }

    public function testLEtapeEstVueCommeUneBriqueCreation(): void
    {
        $def = self::definition();
        $step = WorkflowSpec::node($def, 'graph');
        $create = WorkflowCreations::forStep($def, $step, 'Un vélo, affiche.');
        $this->assertSame(
            ['key' => 'graph', 'type' => 'create', 'kind' => 'image', 'tool_id' => 5, 'instruction' => 'Un vélo, affiche.', 'reads' => ['croquis'], 'options' => ['format' => 'portrait', 'background' => '', 'output_format' => '']],
            array_intersect_key($create, array_flip(['key', 'type', 'kind', 'tool_id', 'instruction', 'reads', 'options']))
        );
        // La demande rédigée part à l'outil ; l'image lue sert d'image de départ.
        $artifacts = ['croquis' => ['key' => 'croquis', 'form' => 'image', 'content' => ['url' => '/assets/img/generated/x.png', 'prompt' => 'Croquis']]];
        $req = WorkflowCreations::request($def, $create, $artifacts);
        $this->assertSame("Un vélo, affiche.\n\nFormat attendu : portrait (plus haut que large).", $req['prompt']);
        $this->assertSame(['croquis'], $req['sources']);
        // Une carte ne lit pas les images ; la Fiche de départ n'est relue que par le tour (déjà dans la demande).
        $mapDef = self::definition(['form' => 'map']);
        $this->assertSame([], WorkflowCreations::forStep($mapDef, WorkflowSpec::node($mapDef, 'graph'), 'x')['reads']);
    }

    // ------------------------------------------------------------------
    // Boucles, ajustement, création depuis le chat
    // ------------------------------------------------------------------

    public function testUneBouclePourChaqueNeRassemblePasLesCreationsDUneEtape(): void
    {
        $body = [
            ['key' => 'red', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'x', 'output' => ['key' => 'texte', 'name' => 'Texte', 'form' => 'text']],
            ['key' => 'img', 'type' => 'step', 'title' => 'Illustrer', 'instruction' => 'x', 'output' => ['key' => 'image', 'name' => 'Image', 'form' => 'image', 'tool_id' => 5]],
        ];
        $each = WorkflowSpec::normalize(['nodes' => [['key' => 'b', 'type' => 'loop', 'title' => 'B', 'over' => 'depart', 'body' => $body, 'output' => ['key' => 'out', 'name' => 'Out']]]]);
        $until = WorkflowSpec::normalize(['nodes' => [['key' => 'b', 'type' => 'loop', 'title' => 'B', 'mode' => 'until', 'body' => $body, 'output' => ['key' => 'out', 'name' => 'Out']]]]);
        $this->assertSame('texte', WorkflowSpec::loopCollect($each['nodes'][0]), 'Une image par élément sert à son tour, elle n’est pas rassemblée.');
        $this->assertSame('text', WorkflowSpec::pieces($each)['out']['form']);
        $this->assertSame('image', WorkflowSpec::loopCollect($until['nodes'][0]), '« Jusqu’à » garde la dernière version.');
        $this->assertSame('image', WorkflowSpec::pieces($until)['out']['form']);
    }

    public function testLOutilDeLaPieceSuitCeQueLaPersonneA(): void
    {
        $choices = ['creations' => [9 => self::tool(9, 'image'), 7 => self::tool(7, 'image', 'Hors service.')]];
        $notes = [];
        $fit = WorkflowFit::apply(self::definition(['tool_id' => 7]), $choices, $notes);
        $this->assertSame(9, WorkflowSpec::node($fit, 'graph')['output']['tool_id'], 'Un outil hors service cède la place au premier en service.');
        $kept = WorkflowFit::apply(self::definition(['tool_id' => 9]), $choices);
        $this->assertSame(9, WorkflowSpec::node($kept, 'graph')['output']['tool_id']);
    }

    public function testMiaPrepareUneEtapeQuiRemetUneImage(): void
    {
        $choices = [
            'profiles' => [], 'llms' => ['openai::gpt-5.6' => 'GPT-5.6'], 'tool_llms' => ['openai::gpt-5.6' => true], 'default_llm' => 'openai::gpt-5.6',
            'sources' => ['web' => ['label' => 'Internet', 'connected' => true]], 'kbs' => [], 'tools' => [], 'auto' => true,
            'creations' => [3 => ['id' => 3, 'name' => 'Images de marque', 'type' => 'image', 'kind' => 'image', 'reason' => null]],
        ];
        $args = ['nom' => 'Visuels', 'briques' => [
            ['type' => 'etape', 'titre' => 'Préparer le visuel', 'consigne' => 'Le visuel du lancement', 'sources' => ['web'],
                'piece' => ['nom' => 'Visuel', 'forme' => 'image', 'format' => 'paysage']],
            ['type' => 'etape', 'titre' => 'Préparer la page', 'consigne' => 'La page', 'piece' => ['nom' => 'Site', 'forme' => 'page_web']],
        ], 'livrable' => ['Visuel']];
        $d = WorkflowDraft::fromArgs($args, $choices);
        $o = $d['definition']['nodes'][0]['output'];
        $this->assertSame(['image', 3, ['format' => 'landscape', 'background' => '', 'output_format' => '']], [$o['form'], $o['tool_id'], $o['options']]);
        $page = $d['definition']['nodes'][1]['output'];
        $this->assertSame(['page', null], [$page['form'], $page['tool_id']]);
        $this->assertContains('« Préparer la page » : aucun outil de création de ce type ne vous est ouvert, à choisir dans l’éditeur.', $d['notes']);

        $guide = WorkflowDraft::schemaGuide($choices);
        $this->assertStringContainsString('"forme": "texte|liste|tableau|fiche|document|image"', $guide, 'Seules les natures ouvertes à la personne sont proposées.');
        $this->assertStringContainsString('une ÉTAPE dont la « forme » est image', $guide);
        $lines = WorkflowDraft::railLines($d['definition'], $choices);
        $this->assertContains('MIA · Images de marque', array_column($lines, 'sub'));
    }

    // ------------------------------------------------------------------
    // Rail du navigateur, écrans, moteur
    // ------------------------------------------------------------------

    public function testLeRailDuNavigateurCompteCommeLeServeur(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $body = [
            ['key' => 'red', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'x', 'output' => ['key' => 'texte', 'name' => 'Texte', 'form' => 'text']],
            ['key' => 'img', 'type' => 'step', 'title' => 'Illustrer', 'instruction' => '@{texte}', 'output' => ['key' => 'image', 'name' => 'Image', 'form' => 'image', 'tool_id' => 5]],
        ];
        $defs = [
            'etape' => self::definition(),
            'chaque' => WorkflowSpec::normalize(['nodes' => [['key' => 'b', 'type' => 'loop', 'title' => 'B', 'over' => 'depart', 'body' => $body, 'output' => ['key' => 'out', 'name' => 'Out']]]]),
            'jusqua' => WorkflowSpec::normalize(['nodes' => [['key' => 'b', 'type' => 'loop', 'title' => 'B', 'mode' => 'until', 'body' => $body, 'output' => ['key' => 'out', 'name' => 'Out']]]]),
        ];
        $expected = [];
        foreach ($defs as $name => $def) {
            $loop = null;
            foreach ($def['nodes'] as $n) {
                if ($n['type'] === 'loop') {
                    $loop = $n;
                }
            }
            $expected[$name] = [
                'pieces'  => array_map(static fn($p) => [$p['key'], $p['form'], $p['producer']], array_values(WorkflowSpec::pieces($def))),
                'collect' => $loop !== null ? WorkflowSpec::loopCollect($loop) : null,
                'creation' => array_map(static fn($f) => WorkflowSpec::isCreationForm($f), ['text', 'document', 'image', 'page', 'map', 'file']),
            ];
        }
        $script = 'require(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ');'
            . 'var R = globalThis.MiaWorkflowRail; var defs = ' . json_encode($defs, JSON_UNESCAPED_UNICODE) . '; var out = {};'
            . 'Object.keys(defs).forEach(function (n) { var d = defs[n]; var loop = null;'
            . ' d.nodes.forEach(function (x) { if (x.type === "loop") { loop = x; } });'
            . ' out[n] = { pieces: R.pieces(d).map(function (p) { return [p.key, p.form, p.producer]; }), collect: loop ? R.loopCollect(loop) : null,'
            . ' creation: ["text", "document", "image", "page", "map", "file"].map(R.isCreationForm) }; });'
            . 'process.stdout.write(JSON.stringify(out));';
        $file = tempnam(sys_get_temp_dir(), 'wfstep') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertSame($expected, $got);
    }

    public function testLEditeurProposeLesCreationsOuvertesALaPersonne(): void
    {
        $js = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString("var CREATE_KINDS = ['image', 'page', 'map'];", $js);
        // Une tuile par nature, grisée avec sa raison quand aucun outil n'est en service — jamais masquée.
        $this->assertStringContainsString("var ct = opts.noCreation ? '' : CREATE_KINDS.map(function (k) {", $js);
        $this->assertStringContainsString("small: off ? (none ? T('Aucun outil ouvert') : why) : '', off: off", $js);
        $this->assertStringContainsString("creationToolTiles(o.form, o.tool_id, 'otool')", $js);
        $this->assertStringContainsString("creationToolTiles(n.kind, n.tool_id, 'ctool')", $js, 'Une seule fabrique de tuiles pour la brique Création et la pièce d’une étape.');
        $this->assertStringContainsString("case 'otool': n.output.tool_id", $js);
        $this->assertStringContainsString("case 'oformat':", $js);
        $this->assertStringContainsString('R.loopCollectable(n, c)', $js);
        $rail = self::src('public/assets/js/workflow-rail.js');
        $this->assertStringContainsString('isCreationForm: isCreationForm', $rail);
        $this->assertStringContainsString("T('Créée par')", self::src('public/assets/js/workflow-run.js'));
        // Les libellés et aides des formes viennent du catalogue (traduits côté serveur).
        $labels = WorkflowCatalog::labels();
        foreach (array_keys(WorkflowSpec::CREATE_KINDS) as $kind) {
            $this->assertArrayHasKey($kind, $labels['forms']);
            $this->assertArrayHasKey($kind, $labels['formHelp']);
        }
    }

    public function testLeMoteurFaitLeTourPuisLaFabrication(): void
    {
        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString('private static function startStepCreation(array $run, array $node, ?array $step, array $opts): array', $engine);
        $this->assertStringContainsString("WorkflowRun::swapStepJob(\$step['id'], (int)(\$step['job_id'] ?? 0), \$jobId", $engine, 'Le passage change de job une seule fois.');
        $this->assertStringContainsString("'creates'        => \$creates,", $engine);
        $this->assertStringContainsString("if (\$creates === 'image') {", $engine, 'L’outil image de l’étape n’est pas exposé à son tour.');
        $this->assertStringContainsString("self::creationPayload(\$run, \$node, \$tool, \$request, \$passKey, (int)\$userMsg['id'])", $engine, 'Une seule charge pour la brique Création et l’étape.');
        $this->assertStringContainsString("'can_correct' => WorkflowSpec::isCorrectable(\$step['output'])", $engine);
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString("if ((\$workflowStep['creates'] ?? '') === 'image') {\n            \$imageTool = null;", $chat);
        $this->assertStringContainsString('public static function swapStepJob(int $stepId, int $fromJob, int $toJob, array $detail): bool', self::src('src/Models/WorkflowRun.php'));
    }

    public function testLaDocumentationLeDit(): void
    {
        $this->assertStringContainsString('id: workflows-etape-image', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Faite par un outil de création', self::src('docs/user/fonctionnalites/workflows/creer-une-image-une-page-ou-une-carte.md'));
        $this->assertStringContainsString('faite par un outil de création', self::src('docs/user/fonctionnalites/workflows/creer-un-workflow.md'));
        $this->assertStringContainsString('remettre une image', self::src('docs/GUIDE-CREATEUR.md'));
    }
}
