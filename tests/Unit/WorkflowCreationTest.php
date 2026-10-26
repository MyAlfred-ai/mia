<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Brique « Création » d'un workflow : une image, une page web, une carte faite
 * par un outil du chat, qui remet une pièce au dossier (WorkflowSpec,
 * WorkflowCreations, rendus, fichiers, format d'image, rail du navigateur,
 * câblage du moteur et des jobs du chat).
 */
final class WorkflowCreationTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        $this->files = [];
    }

    private static function src(string $rel): string
    {
        return (string)file_get_contents(ROOT . '/' . $rel);
    }

    /** Un workflow qui crée : un article, son image (relue), une page, une carte dans une boucle « jusqu'à ». */
    private static function definition(): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fields' => [
                ['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text'],
                ['key' => 'logo', 'label' => 'Logo', 'type' => 'file'],
            ]],
            'nodes' => [
                ['key' => 'art', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'Écris sur @{depart.sujet}',
                    'output' => ['key' => 'article', 'name' => 'Article', 'form' => 'text']],
                ['key' => 'vis', 'type' => 'create', 'title' => 'Illustrer', 'kind' => 'image', 'tool_id' => 5,
                    'instruction' => 'Une image pour @{article} sur @{depart.sujet}, avec @{logo}', 'options' => ['format' => 'landscape'],
                    'review' => true, 'output' => ['key' => 'visuel', 'name' => 'Visuel']],
                ['key' => 'pg', 'type' => 'create', 'title' => 'Mettre en page', 'kind' => 'page', 'tool_id' => 6,
                    'instruction' => 'Une page avec @{article} et @{visuel}', 'options' => ['style' => 'aquarelle'], 'output' => ['key' => 'page', 'name' => 'Page']],
                ['key' => 'boucle', 'type' => 'loop', 'title' => 'Affiner', 'mode' => 'until', 'max' => 3,
                    'body' => [['key' => 'trajet', 'type' => 'create', 'kind' => 'map', 'title' => 'Tracer', 'tool_id' => 7,
                        'instruction' => 'Itinéraire de la tournée', 'output' => ['key' => 'carte', 'name' => 'Itinéraire']]],
                    'output' => ['key' => 'carte_finale', 'name' => 'Carte finale']],
            ],
            'end' => ['pieces' => ['visuel', 'page'], 'deliveries' => [
                ['type' => 'drive', 'pieces' => ['visuel', 'page']],
                ['type' => 'slack', 'channel' => '#com', 'message' => 'Voici', 'pieces' => ['visuel']],
            ]],
        ]);
    }

    // ------------------------------------------------------------------
    // Forme
    // ------------------------------------------------------------------

    public function testUneCreationSeNormaliseSelonCeQuElleCree(): void
    {
        $def = self::definition();
        $vis = WorkflowSpec::node($def, 'vis');
        $this->assertSame('image', $vis['kind']);
        $this->assertSame(5, $vis['tool_id']);
        $this->assertSame(['format' => 'landscape'], $vis['options']);
        $this->assertTrue($vis['review']);
        $this->assertSame(['key' => 'visuel', 'name' => 'Visuel', 'form' => 'image'], $vis['output'], 'La pièce a la forme de ce qui est créé.');
        $this->assertSame(['style' => 'aquarelle'], WorkflowSpec::node($def, 'pg')['options']);
        $this->assertSame([], WorkflowSpec::node($def, 'trajet')['options']);

        // Une nature inconnue retombe sur l'image, un format inconnu sur celui de l'outil.
        $odd = WorkflowSpec::normalize(['nodes' => [['type' => 'create', 'title' => 'X', 'kind' => 'video', 'options' => ['format' => 'cinemascope'], 'output' => ['form' => 'text']]]]);
        $this->assertSame('image', $odd['nodes'][0]['kind']);
        $this->assertSame('image', $odd['nodes'][0]['output']['form']);
        $this->assertSame('', $odd['nodes'][0]['options']['format']);
        $this->assertNull($odd['nodes'][0]['tool_id']);
        $this->assertContains(WorkflowSpec::TYPE_CREATE, WorkflowSpec::INNER_TYPES, 'Une création va dans une voie ou une boucle.');
        $this->assertSame(['image', 'page', 'map'], array_keys(WorkflowSpec::CREATE_KINDS));
    }

    public function testUneCreationRemetUnePieceDeSaNature(): void
    {
        $pieces = WorkflowSpec::pieces(self::definition());
        $this->assertSame('image', $pieces['visuel']['form']);
        $this->assertSame('vis', $pieces['visuel']['producer']);
        $this->assertSame('page', $pieces['page']['form']);
        $this->assertSame('map', $pieces['carte']['form']);
        $this->assertSame('loop:boucle', $pieces['carte']['branch']);
        // « Jusqu'à » rassemble la dernière version d'une création ; « pour chaque » non.
        $this->assertSame('carte', $pieces['carte_finale']['collect']);
        $this->assertSame('map', $pieces['carte_finale']['form']);
        $each = WorkflowSpec::normalize(['nodes' => [['key' => 'l', 'type' => 'loop', 'title' => 'L', 'mode' => 'each', 'over' => 'x',
            'body' => [['key' => 'c', 'type' => 'create', 'kind' => 'image', 'title' => 'C', 'instruction' => 'x', 'output' => ['key' => 'img', 'name' => 'Img']]]]]]);
        $this->assertNull(WorkflowSpec::loopCollect($each['nodes'][0]));
        $this->assertContains('img', WorkflowLoop::bodyPieceKeys($each['nodes'][0]), 'Les briques du même tour voient l’image de leur tour.');

        $this->assertSame('dskgkle', WorkflowSpec::silhouette(self::definition()));
        $counts = WorkflowSpec::counts(self::definition());
        $this->assertSame(3, $counts['creations']);
        $this->assertSame(1, $counts['steps']);
    }

    public function testUneCreationRelueSeRefaitDApresLaRemarque(): void
    {
        $def = self::definition();
        $gate = WorkflowEngine::nodeFor($def, 'vis~review');
        $this->assertSame('gate', $gate['type']);
        $this->assertSame(['visuel'], $gate['reviews']);
        $this->assertSame('vis', WorkflowSpec::returnTarget($def, $gate), '« Renvoyer » refait la création.');
        $this->assertFalse($gate['can_correct'], 'La relecture d’une création ne propose pas « Corriger ».');
        $this->assertTrue(WorkflowEngine::nodeFor($def, 'art~review')['can_correct'], 'Celle d’une étape, si.');
        $explicit = WorkflowSpec::normalize(['nodes' => array_merge(self::definition()['nodes'], [
            ['key' => 'relis', 'type' => 'gate', 'title' => 'Relire la page', 'reviews' => ['page']],
        ])]);
        $this->assertSame('pg', WorkflowSpec::returnTarget($explicit, WorkflowSpec::node($explicit, 'relis')));

        // Une seule règle dit ce qui se corrige à la main : ni une création, ni un fichier reçu.
        $pieces = WorkflowSpec::pieces($def);
        $this->assertTrue(WorkflowSpec::isCorrectable($pieces['article']));
        foreach (['visuel', 'page', 'carte', 'logo'] as $k) {
            $this->assertFalse(WorkflowSpec::isCorrectable($pieces[$k]), $k);
        }
        $ctl = self::src('src/Controllers/WorkflowController.php');
        $this->assertSame(2, substr_count($ctl, 'WorkflowSpec::isCorrectable('), 'correctPiece et l’état de la validation.');
        $this->assertStringContainsString('WorkflowSpec::isCorrectable($pieces[$k])', self::src('src/Workflow/WorkflowEngine.php'), 'La carte du chat ne propose « corriger » que pour une pièce qui se corrige.');
    }

    public function testCeQuiEmpecheDePublierUneCreation(): void
    {
        $def = self::definition();
        $ctx = ['creations' => [
            5 => ['id' => 5, 'name' => 'Images', 'type' => 'image', 'kind' => 'image', 'reason' => null, 'can_edit' => false],
            6 => ['id' => 6, 'name' => 'Photos', 'type' => 'image', 'kind' => 'image', 'reason' => null, 'can_edit' => true],
            7 => ['id' => 7, 'name' => 'Cartes', 'type' => 'map', 'kind' => 'map', 'reason' => 'Dernier test en échec — prévenir un administrateur'],
        ]];
        $byNode = [];
        foreach (WorkflowSpec::issues($def, $ctx) as $i) {
            $byNode[$i['node']][] = $i;
        }
        $texts = static fn(string $k): string => implode(' | ', array_map(static fn($i) => $i['level'] . ':' . $i['target'] . ':' . $i['text'], $byNode[$k] ?? []));
        $this->assertStringContainsString('block:tool:Cet outil ne fait pas ce que la brique crée', $texts('pg'), 'Un outil d’image pour une page.');
        $this->assertStringContainsString('block:tool:Dernier test en échec', $texts('trajet'));
        $this->assertStringNotContainsString('block', $texts('vis'));
        // L'image lit une pièce image ? Pas ici : « logo » est un fichier. Une page qui lit une image ne prévient de rien.
        $this->assertStringContainsString('warn:delivery:0:Drive n’enregistre ici que des documents et des tableaux : l’image « Visuel »', $texts('end'));
        $this->assertStringContainsString('warn:delivery:1:Slack ne reçoit ici que du texte : l’image « Visuel »', $texts('end'));

        $bare = WorkflowSpec::normalize(['nodes' => [['key' => 'c', 'type' => 'create', 'title' => 'C', 'kind' => 'image']], 'end' => ['pieces' => []]]);
        $all = array_map(static fn($i) => $i['target'] . ':' . $i['text'], WorkflowSpec::issues($bare, ['creations' => []]));
        $this->assertContains('tool:Choisissez l’outil qui fait la création.', $all);
        $this->assertContains('instruction:Décrivez ce qu’il faut créer.', $all);
        $this->assertContains('output:Nommez la pièce que la création remet.', $all);
        $this->assertNotContains('nodes:Aucune étape ne remet de pièce : le workflow ne produira rien à relire.', $all, 'Une création remet une pièce.');

        // Une image qui part d'une image, avec un outil qui ne sait pas : dit.
        $chain = WorkflowSpec::normalize(['nodes' => [
            ['key' => 'a', 'type' => 'create', 'title' => 'A', 'kind' => 'image', 'tool_id' => 6, 'instruction' => 'x', 'output' => ['key' => 'img1', 'name' => 'Image 1']],
            ['key' => 'b', 'type' => 'create', 'title' => 'B', 'kind' => 'image', 'tool_id' => 5, 'instruction' => 'Décline @{img1}', 'output' => ['key' => 'img2', 'name' => 'Image 2']],
        ]]);
        $warn = array_filter(WorkflowSpec::issues($chain, $ctx), static fn($i) => $i['node'] === 'b' && $i['level'] === 'warn');
        $this->assertCount(1, $warn);
        $this->assertStringContainsString('« Image 1 » ne lui sera pas montrée', array_values($warn)[0]['text']);
    }

    // ------------------------------------------------------------------
    // Demande
    // ------------------------------------------------------------------

    private static function art(string $key, string $form, array $content, ?string $real = null): array
    {
        return ['key' => $real ?? $key, 'node_key' => 'x', 'name' => $key, 'form' => $form, 'content' => $content, 'provenance' => [], 'version' => 1];
    }

    public function testLaDemandeDUneImageViendDuDossier(): void
    {
        $def = self::definition();
        $artifacts = [
            'depart'  => self::art('depart', 'fiche', ['fields' => ['sujet' => 'Le salon du vélo']]),
            'article' => self::art('article', 'text', ['text' => "Un salon à Lyon.\n\nDes vélos partout."]),
            'logo'    => self::art('logo', 'file', ['name' => 'logo.png', 'type' => 'image/png', 'path' => '/storage/uploads/logo.png', 'text' => '']),
        ];
        $req = WorkflowCreations::request($def, WorkflowSpec::node($def, 'vis'), $artifacts);
        $this->assertStringContainsString('Une image pour « Article » sur Le salon du vélo, avec « Logo »', $req['prompt'], 'Un champ cité devient sa valeur.');
        $this->assertStringContainsString('Format attendu : paysage', $req['prompt']);
        $this->assertSame(['logo'], $req['sources'], 'Un fichier image reçu au départ sert d’image de départ.');
        $this->assertNull($req['base']);
        $this->assertStringContainsString('« Article » : Un salon à Lyon. Des vélos partout.', $req['context']);
        $this->assertStringContainsString('« Logo » : image 1 jointe à la demande', $req['context']);

        // Renvoyée avec une remarque : elle repart de SA version, la remarque d'abord.
        $again = WorkflowCreations::request($def, WorkflowSpec::node($def, 'vis'), $artifacts + [
            'visuel' => self::art('visuel', 'image', ['url' => '/assets/img/generated/a.png', 'prompt' => 'Un vélo rouge']),
        ], 'Fond bleu', 'visuel');
        $this->assertSame(['visuel', 'logo'], $again['sources']);
        $this->assertStringStartsWith('Correction demandée à la relecture (à appliquer en priorité) : Fond bleu', $again['prompt']);
    }

    public function testLaDemandeDUnePageDonneLesPiecesEnDonneesEtLesImagesEnBalise(): void
    {
        $def = self::definition();
        $artifacts = [
            'article' => self::art('article', 'text', ['text' => 'Le salon ouvre samedi.']),
            'visuel'  => self::art('visuel', 'image', ['url' => '/assets/img/generated/v.png', 'prompt' => 'Un vélo']),
        ];
        $req = WorkflowCreations::request($def, WorkflowSpec::node($def, 'pg'), $artifacts);
        $this->assertStringContainsString('DONNÉES DU DOSSIER', $req['prompt']);
        $this->assertStringContainsString("<<< PIÈCE « Article »\nLe salon ouvre samedi.", $req['prompt']);
        $this->assertStringContainsString('<img src="/assets/img/generated/v.png" alt="Visuel">', $req['prompt'], 'La page peut mettre l’image en page.');
        $this->assertSame([], $req['sources']);
        $this->assertNull($req['base']);

        // Une page lue est la base à modifier, jamais recopiée dans les données.
        $base = WorkflowSpec::normalize(['nodes' => [
            ['key' => 'p1', 'type' => 'create', 'title' => 'P1', 'kind' => 'page', 'tool_id' => 1, 'instruction' => 'x', 'output' => ['key' => 'page1', 'name' => 'Page 1']],
            ['key' => 'p2', 'type' => 'create', 'title' => 'P2', 'kind' => 'page', 'tool_id' => 1, 'instruction' => 'Raccourcis @{page1}', 'output' => ['key' => 'page2', 'name' => 'Page 2']],
        ]]);
        $r2 = WorkflowCreations::request($base, WorkflowSpec::node($base, 'p2'), ['page1' => self::art('page1', 'page', ['html' => '<html><body>x</body></html>'], 'page1#2')]);
        $this->assertSame('page1#2', $r2['base'], 'La clé RÉELLE de la pièce (tour de boucle compris).');
        $this->assertStringNotContainsString('DONNÉES DU DOSSIER', $r2['prompt']);
    }

    // ------------------------------------------------------------------
    // Pièce remise, rendus, fichiers
    // ------------------------------------------------------------------

    private function generatedPng(int $w = 40, int $h = 20): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int)imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);
        $bytes = (string)ob_get_clean();
        $name = 'wfcreation_' . bin2hex(random_bytes(6)) . '.png';
        $path = ROOT . '/public/assets/img/generated/' . $name;
        file_put_contents($path, $bytes);
        $this->files[] = $path;
        return '/assets/img/generated/' . $name;
    }

    public function testLaPieceRemiseSeLitDansLeResultatDuJob(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD absent');
        }
        $url = $this->generatedPng(40, 20);
        $img = WorkflowCreations::contentFromResult('image', ['msg_id' => 9, 'image_url' => $url, 'visual_prompt' => 'Un vélo'],
            ['content' => "![Image générée]($url)\n\n*Prompt enrichi : Un vélo rouge au soleil*"], 'Images');
        $this->assertSame($url, $img['url']);
        $this->assertSame([40, 20, 'image/png'], [$img['width'], $img['height'], $img['mime']]);
        $this->assertSame('Un vélo rouge au soleil', $img['revised_prompt']);
        $this->assertSame('Images', $img['tool']);
        $this->assertNull(WorkflowCreations::contentFromResult('image', ['image_url' => '/etc/passwd'], ['content' => ''], 'x'), 'Jamais une adresse hors des images générées.');

        $page = WorkflowCreations::contentFromResult('page', ['msg_id' => 3, 'illustrated' => true], ['content' => '<html><head><title>Salon</title></head><body><h1>Bienvenue</h1><p>Le salon ouvre.</p></body></html>'], 'Pages');
        $this->assertSame(['Salon', true, 3], [$page['title'], $page['illustrated'], $page['msg_id']]);
        $this->assertNull(WorkflowCreations::contentFromResult('page', [], ['content' => ' '], 'x'));

        $spec = ['title' => 'Tournée', 'summary' => 'Trois arrêts.', 'waypoints' => [['name' => 'Lyon', 'query' => 'Lyon, France', 'note' => 'Départ'], ['name' => 'Vienne', 'query' => 'Vienne, France']], 'notes' => ['Pause déjeuner']];
        $map = WorkflowCreations::contentFromResult('map', ['msg_id' => 4, 'map' => $spec], null, 'Cartes');
        $this->assertSame($spec, $map['spec']);
        $this->assertNull(WorkflowCreations::contentFromResult('map', ['map' => ['waypoints' => []]], null, 'x'));

        // Rendus : ce qu'une étape suivante lit, ce que l'écran résume.
        $pieces = WorkflowSpec::pieces(self::definition());
        $this->assertSame('Image : Un vélo rouge au soleil', ArtifactContract::render($pieces['visuel'], $img));
        $this->assertSame('Image · 40 × 20', ArtifactContract::summary($pieces['visuel'], $img));
        $this->assertStringStartsWith("# Salon\n\n", ArtifactContract::render($pieces['page'], $page));
        $this->assertStringContainsString('Le salon ouvre.', ArtifactContract::render($pieces['page'], $page));
        $this->assertStringStartsWith('Page web · ', ArtifactContract::summary($pieces['page'], $page));
        $md = ArtifactContract::render($pieces['carte'], $map);
        $this->assertStringContainsString("# Tournée\n\nTrois arrêts.", $md);
        $this->assertStringContainsString('1. **Lyon** — Lyon, France : Départ', $md);
        $this->assertStringContainsString('- Pause déjeuner', $md);
        $this->assertSame('Itinéraire · 2 étapes', ArtifactContract::summary($pieces['carte'], $map));

        // Le livrable : l'image elle-même, le fichier de chaque création, l'itinéraire sans titre géant.
        $dl = '/workflows/runs/9/pieces/visuel/download';
        $this->assertSame("![Visuel]($url)\n\nImage · 40 × 20 — [Télécharger]($dl)", WorkflowCreations::deliverable($pieces['visuel'], $img, $dl));
        $this->assertSame('Image : Un vélo rouge au soleil', WorkflowCreations::deliverable($pieces['visuel'], ['url' => '/assets/img/generated/absente.png'] + $img, $dl), 'Image retirée : sa description, sans lien mort.');
        $pageDeliv = WorkflowCreations::deliverable($pieces['page'], $page, $dl);
        $this->assertStringStartsWith('Page web · ', $pageDeliv);
        $this->assertStringNotContainsString('Le salon ouvre.', $pageDeliv, 'Une page s’ouvre depuis sa carte : son texte n’est pas recopié.');
        $mapDeliv = WorkflowCreations::deliverable($pieces['carte'], $map, $dl);
        $this->assertStringContainsString("**Tournée**\n\nTrois arrêts.", $mapDeliv);
        $this->assertStringNotContainsString("\n#", $mapDeliv, 'Sous la section de la pièce, des titres en gras.');
        $this->assertSame('Image', ArtifactContract::formLabel('image'));
        $this->assertSame('Page web', ArtifactContract::formLabel('page'));
        $this->assertSame('Carte', ArtifactContract::formLabel('map'));
    }

    public function testLeFichierDUneCreation(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD absent');
        }
        $url = $this->generatedPng(60, 30);
        $piece = ['key' => 'visuel', 'name' => 'Visuel / salon', 'form' => 'image'];
        $orig = WorkflowCreations::file($piece, ['url' => $url], '');
        $this->assertSame('image/png', $orig['mime']);
        $this->assertSame('Visuel salon.png', $orig['name'], 'Nom nettoyé, extension de son type réel.');
        $jpg = WorkflowCreations::file($piece, ['url' => $url], 'jpg');
        $this->assertSame('image/jpeg', $jpg['mime']);
        $this->assertStringStartsWith("\xFF\xD8", $jpg['bytes']);
        $this->assertSame('image/png', WorkflowCreations::file($piece, ['url' => $url], WorkflowFiles::FOR_EMAIL)['mime'], 'Petite : jointe telle quelle.');
        try {
            WorkflowCreations::file($piece, ['url' => '/assets/img/generated/absente.png'], '');
            $this->fail('Une image absente se dit.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Image introuvable', $e->getMessage());
        }

        $page = WorkflowCreations::file(['key' => 'p', 'name' => 'Page', 'form' => 'page'],
            ['html' => '<html><body><img src="' . $url . '?e=1&s=x"></body></html>'], 'html');
        $this->assertSame(['Page.html', 'text/html'], [$page['name'], $page['mime']]);
        $this->assertStringContainsString('src="data:image/png;base64,', $page['bytes'], 'Les images de la page voyagent avec elle.');
    }

    public function testLOctetLegerDUneImage(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD absent');
        }
        $img = imagecreatetruecolor(3000, 1000);
        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        $this->assertSame('image/png', ImageBytes::mime($png));
        $jpg = ImageBytes::jpeg($png, 1600);
        $this->assertNotNull($jpg);
        $this->assertSame('image/jpeg', ImageBytes::mime($jpg));
        $this->assertSame([1600, 533], array_slice((array)getimagesizefromstring($jpg), 0, 2), 'Plus grand côté borné, proportions gardées.');
        $this->assertNull(ImageBytes::jpeg('pas une image'));
        $this->assertSame('jpg', ImageBytes::extension('image/jpeg'));
        $this->assertSame('png', ImageBytes::extension('image/inconnu'));
    }

    public function testLeFormatDUneImageSuitCeQueLeModeleAccepte(): void
    {
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        $this->assertSame('1536x1024', ImageGenerator::sizeFor(['auto', '1024x1024', '1536x1024', '1024x1536', '2048x1152', '3840x2160'], 'landscape'), 'La plus petite : la moins chère.');
        $this->assertSame('1024x1536', ImageGenerator::sizeFor(['1024x1024', '1024x1536', '2160x3840'], 'portrait'));
        $this->assertSame('1024x1024', ImageGenerator::sizeFor(['auto', '2048x2048', '1024x1024'], 'square'));
        $this->assertNull(ImageGenerator::sizeFor(['1024x1024'], 'landscape'));

        $tool = ['provider' => 'openai', 'model' => 'gpt-image-2', 'config_json' => json_encode(['quality' => 'high'])];
        $wide = json_decode(ImageGenerator::orientedTool($tool, 'landscape')['config_json'], true);
        $this->assertSame(['quality' => 'high', 'size' => '1536x1024'], $wide, 'Le reste du réglage de l’outil ne bouge pas.');
        $this->assertSame($tool, ImageGenerator::orientedTool($tool, ''), 'Sans format : l’outil tel quel.');
        $gemini = json_decode(ImageGenerator::orientedTool(['provider' => 'gemini', 'model' => 'imagen-4.0-generate-001', 'config_json' => '{}'], 'portrait')['config_json'], true);
        $this->assertSame('9:16', $gemini['aspect_ratio']);
    }

    // ------------------------------------------------------------------
    // Rail du navigateur (miroir de WorkflowSpec)
    // ------------------------------------------------------------------

    public function testLeRailDuNavigateurCompteLesCreationsCommeLeServeur(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $def = self::definition();
        $keys = array_merge(array_map(static fn($n) => $n['key'], WorkflowSpec::flatNodes($def)), ['end']);
        $expected = ['pieces' => array_map(static fn($p) => [$p['key'], $p['form'], $p['branch'], $p['producer']], array_values(WorkflowSpec::pieces($def))), 'available' => []];
        foreach ($keys as $k) {
            $expected['available'][$k] = array_keys(WorkflowSpec::available($def, $k));
        }
        $cat = ['icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES, 'labels' => WorkflowCatalog::labels(), 'colors' => WorkflowCatalog::FORM_COLORS,
            'creations' => [['id' => 5, 'name' => 'Images de marque', 'type' => 'image', 'kind' => 'image', 'reason' => null]]];
        $script = 'require(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ');'
            . 'var R = globalThis.MiaWorkflowRail; var d = ' . json_encode($def, JSON_UNESCAPED_UNICODE) . '; var cat = ' . json_encode($cat, JSON_UNESCAPED_UNICODE) . ';'
            . 'var keys = ' . json_encode($keys) . '; var av = {};'
            . 'keys.forEach(function (k) { av[k] = Object.keys(R.available(d, k)); });'
            . 'process.stdout.write(JSON.stringify({ pieces: R.pieces(d).map(function (p) { return [p.key, p.form, p.branch, p.producer]; }), available: av,'
            . ' collect: R.loopCollect(d.nodes[3]), html: R.html(d, { catalog: cat, editable: true }), sil: R.silhouette("dke", cat.hues),'
            . ' colors: R.pieces(d, cat.colors).map(function (p) { return [p.form, p.color]; }) }));';
        $file = tempnam(sys_get_temp_dir(), 'wfcreate') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertIsArray($got, 'workflow-rail.js a échoué sous node.');
        $this->assertSame($expected['pieces'], $got['pieces']);
        $this->assertSame($expected['available'], $got['available']);
        $this->assertSame('carte', $got['collect']);
        $this->assertStringContainsString('class="wf-brick wf-k-create', $got['html']);
        $this->assertStringContainsString('Images de marque', $got['html'], 'La brique nomme son outil.');
        $this->assertStringContainsString('Relue par vous', $got['html']);
        $this->assertStringContainsString('--k:' . WorkflowCatalog::HUES['create'], $got['sil']);
        foreach ($got['colors'] as [$form, $color]) {
            $this->assertSame(WorkflowCatalog::FORM_COLORS[$form], $color, "Couleur de la nature « {$form} ».");
        }
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLaCreationPasseParLesJobsDuChat(): void
    {
        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString('WorkflowSpec::TYPE_STEP, WorkflowSpec::TYPE_ACTION, WorkflowSpec::TYPE_CREATE => self::startWork(', $engine);
        $this->assertStringContainsString("if (\$node['type'] === WorkflowSpec::TYPE_CREATE) {\n            return self::startCreate(\$run, \$node, \$opts);", $engine, 'Renvois et nouveaux essais passent par startWork.');
        $this->assertStringContainsString('JobQueue::enqueue(WorkflowCreations::jobType((string)$node[\'kind\'])', $engine);
        $this->assertStringContainsString('WorkflowCreations::resolveTool($user, $node)', $engine, 'Outil revérifié au moment T.');
        $this->assertStringContainsString('WorkflowCreations::contentFromResult((string)$node[\'kind\']', $engine);
        $this->assertStringContainsString("in_array(\$step['type'], WorkflowSpec::PRODUCING_TYPES, true)", $engine, 'Relecture d’une création.');
        $this->assertStringContainsString('WorkflowCreations::deliverable($p, $a[\'content\'], $download)', $engine, 'Le livrable montre ce qui a été créé.');
        $this->assertStringContainsString('return Message::withoutRepeatedImages($items);', self::src('src/Controllers/UserSettingsController.php'), 'L’image reprise par le livrable n’a qu’une carte dans la galerie.');
        $this->assertSame('image', WorkflowCreations::jobType('image'));
        $this->assertSame('html', WorkflowCreations::jobType('page'));
        $this->assertSame('map', WorkflowCreations::jobType('map'));

        $chat = self::src('src/Controllers/ChatController.php');
        $imagine = substr($chat, (int)strpos($chat, 'public function runImagine('), 9000);
        $this->assertStringContainsString('WorkflowCreations::sourceImages($workflow, $userId)', $imagine, 'Images de départ relues dans le dossier.');
        $this->assertStringContainsString("\$this->prepareImageRequest(\$conv, [], \$prompt, \$images !== [], [], null, (string)(\$workflow['context'] ?? ''))", $imagine, 'Contexte : les pièces, pas la conversation.');
        $this->assertStringContainsString('ImageGenerator::orientedTool($tool', $imagine);
        $this->assertStringContainsString("'visual_prompt' => \$visualPrompt", $imagine);
        $this->assertStringContainsString('public function runMap(array $payload, int $userId, int $convId, ?array $tool = null): array', $chat);
        $this->assertStringContainsString("\$this->runMap(['prompt' => \$prompt], (int)\$user['id'], \$convId, \$tool)", $chat, 'La route du chat et le job, une seule implémentation.');
        $this->assertStringContainsString('WorkflowCreations::baseMap($workflow, $userId)', $chat);
        $this->assertStringContainsString('WorkflowCreations::basePage($workflow, $userId)', self::src('src/Jobs/HtmlPageJob.php'));
        $this->assertStringContainsString('return GeneratedHtml::inlineLocalImages($html);', $chat, 'Embarquer les images : une seule implémentation.');

        $queue = self::src('src/Jobs/JobQueue.php');
        $this->assertStringContainsString("'map'          => new MapJob(),", $queue);
        $this->assertMatchesRegularExpression("/NON_IDEMPOTENT = \[[^\]]*'map'/", $queue, 'Rejouer une carte la refacturerait.');
        $this->assertMatchesRegularExpression("/CONVERSATION_LANE_TYPES = \[[^\]]*'map'/", $queue);
        $classes = self::src('config/classes.php');
        $this->assertLessThan(strpos($classes, "/src/Jobs/MapJob.php"), strpos($classes, "/src/Jobs/ChatJob.php"), 'MapJob après son socle.');
        foreach (['/src/Workflow/WorkflowCreations.php', '/src/Helpers/ImageBytes.php'] as $f) {
            $this->assertStringContainsString($f, $classes);
            $this->assertStringContainsString($f, self::src('tests/bootstrap.php'));
        }
    }

    public function testLesEcransMontrentLesCreations(): void
    {
        $editor = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('function createInspector(n)', $editor);
        $this->assertStringContainsString("{ key: 'create', items: creates }", $editor, 'Le menu d’ajout a sa famille « Créer ».');
        $this->assertStringContainsString("case 'ckind':", $editor);
        $this->assertStringContainsString("create: createInspector", $editor);
        $run = self::src('public/assets/js/workflow-run.js');
        $this->assertStringContainsString('function creationContent(p)', $run);
        $this->assertStringContainsString('!isCreation(p.form)', $run, 'Une création ne se corrige pas à la main.');
        $this->assertStringContainsString("node.type === 'create'", $run);
        $this->assertStringContainsString('.wf-piece-img img', self::src('public/assets/css/workflows.css'));
        $this->assertStringContainsString("'create', 'condition', 'route', 'loop', 'gate', 'wait', 'notify'", self::src('public/assets/js/chat.js'), 'La carte du chat dessine une création.');

        $ctl = self::src('src/Controllers/WorkflowController.php');
        $this->assertStringContainsString('!WorkflowSpec::isCorrectable($piece)', $ctl, 'correctPiece refuse une création.');
        $this->assertStringContainsString('private static function creationItem(', $ctl);
        $this->assertStringContainsString('RouteThumbnail::svg(', $ctl);

        foreach (['image', 'page', 'map', 'create'] as $ic) {
            $this->assertArrayHasKey($ic, WorkflowCatalog::icons());
        }
        $this->assertArrayHasKey('create', WorkflowCatalog::HUES);
        $labels = WorkflowCatalog::labels();
        $this->assertSame(['image', 'page', 'map'], array_keys($labels['createKinds']));
        $this->assertSame(WorkflowSpec::IMAGE_FORMATS, array_keys($labels['imageFormats']));
        $this->assertArrayHasKey('create', $labels['groups']);
        $this->assertSame('Création', $labels['kinds']['create']);
    }

    public function testLesLivraisonsEtLElagageConnaissentLesCreations(): void
    {
        $del = self::src('src/Workflow/WorkflowDeliveries.php');
        $this->assertStringContainsString("WorkflowFiles::build(\$run, (string)\$ref['piece'], WorkflowFiles::FOR_EMAIL)", $del, 'Une pièce jointe allégée au besoin.');
        $this->assertStringContainsString("\$p['form'] === 'image'", $del);
        $this->assertSame('« Visuel »', WorkflowDeliveries::fill('@{visuel}', WorkflowSpec::pieces(self::definition()), [
            'visuel' => self::art('visuel', 'image', ['url' => '/assets/img/generated/v.png', 'prompt' => 'Un vélo']),
        ], true), 'Une image ne s’écrit pas dans un texte : son nom.');

        $pruning = self::src('src/Maintenance/PruningService.php');
        $this->assertStringContainsString('FROM workflow_artifacts', $pruning, 'Une pièce du dossier garde son fichier.');
        $this->assertStringContainsString('readableFile($path)', self::src('src/Workflow/WorkflowPrompt.php'), 'Une étape voit une image créée.');
    }
}
