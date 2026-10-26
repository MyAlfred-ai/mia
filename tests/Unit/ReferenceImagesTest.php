<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Images de référence transmises au modèle image (ReferenceImages, ImageTools,
 * ImageGenerator::sourceImages).
 *
 * Le problème résolu : une image de base de connaissances n'arrivait au modèle
 * image que sous forme de DESCRIPTION, et des documents de l'assistant seule la
 * première image servait. On verrouille ici : le catalogue (clés uniques,
 * provenance, description), la désignation par le modèle (nom exact, sans
 * extension, partie unique — jamais un choix au hasard entre deux), la
 * recherche, la liste du prompt, le chargement confiné aux dossiers de
 * stockage (conversion, réduction), et l'outil `generer_image` exécuté avec
 * un faux générateur — ordre des images, référence par défaut, refus.
 */
final class ReferenceImagesTest extends TestCase
{
    /** @var list<string> dossiers de stockage créés pour un test */
    private array $dirs = [];

    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    // ---------------------------------------------------------------------
    // Catalogue
    // ---------------------------------------------------------------------

    private static function catalog(): array
    {
        return ReferenceImages::catalog(
            [
                ['file_name' => 'mascotte.png', 'mime_type' => 'image/png', 'file_size' => 10, 'path' => '/a'],
                ['file_name' => 'logo.png', 'mime_type' => 'image/png', 'file_size' => 10, 'path' => '/b'],
            ],
            [
                ['file_name' => 'logo.png', 'kb_name' => 'Charte', 'mime_type' => 'image/png', 'path' => '/c',
                    'extracted_text' => "Description de l'image logo.png :\nLogo bleu marine sur fond blanc, typographie arrondie."],
                ['file_name' => 'Usine Lyon — façade.jpg', 'kb_name' => 'Sites', 'mime_type' => 'image/jpeg', 'path' => '/d',
                    'extracted_text' => "Description de l'image Usine Lyon — façade.jpg :\nBâtiment industriel en briques rouges, quai de chargement."],
                ['file_name' => '', 'kb_name' => 'Sites', 'mime_type' => 'image/jpeg', 'path' => '/e'],
            ]
        );
    }

    public function testLeCatalogueMetLAssistantDAbordEtRendLesClesUniques(): void
    {
        $c = self::catalog();

        $this->assertCount(4, $c, 'Une image sans nom est écartée.');
        $this->assertSame(['assistant', 'assistant', 'kb', 'kb'], array_column($c, 'origin'));
        $this->assertSame(
            ['mascotte.png', 'logo.png (assistant)', 'logo.png (Charte)', 'Usine Lyon — façade.jpg'],
            array_column($c, 'key'),
            'Deux images du même nom sont distinguées par leur provenance.'
        );
        $this->assertSame('Logo bleu marine sur fond blanc, typographie arrondie.', $c[2]['description'],
            'L\'en-tête « Description de l\'image … : » de la base est retiré.');
        $this->assertSame('Charte', $c[2]['source']);
    }

    public function testUneCleRestantEnDoubleEstNumerotee(): void
    {
        $c = ReferenceImages::catalog([], [
            ['file_name' => 'a.png', 'kb_name' => 'X', 'mime_type' => 'image/png', 'path' => '/1'],
            ['file_name' => 'a.png', 'kb_name' => 'X', 'mime_type' => 'image/png', 'path' => '/2'],
        ]);
        $this->assertSame(['a.png (X)', 'a.png (X) #2'], array_column($c, 'key'));
    }

    public function testLaDescriptionEstBorneeAUneFrontiereDeMot(): void
    {
        $long = str_repeat('mot ', 80);
        $c = ReferenceImages::catalog([], [['file_name' => 'x.png', 'extracted_text' => $long, 'path' => '/x']]);
        $this->assertLessThanOrEqual(ReferenceImages::DESCRIPTION_CHARS + 1, mb_strlen($c[0]['description']));
        $this->assertStringEndsWith('mot…', $c[0]['description']);
    }

    // ---------------------------------------------------------------------
    // Désignation par le modèle
    // ---------------------------------------------------------------------

    public function testLesReferencesDemandeesSontLuesSousToutesLeursFormes(): void
    {
        $this->assertSame(['a.png', 'b.png'], ReferenceImages::normalizeWanted(['a.png', ' b.png ', 'A.PNG']));
        $this->assertSame(['a.png', 'b.png'], ReferenceImages::normalizeWanted('["a.png","b.png"]'));
        $this->assertSame(['a.png', 'b.png'], ReferenceImages::normalizeWanted('a.png, b.png'));
        $this->assertSame(['logo'], ReferenceImages::normalizeWanted(['« logo »']));
        $this->assertSame([], ReferenceImages::normalizeWanted(''));
        $this->assertSame([], ReferenceImages::normalizeWanted(null));
        $this->assertSame(['x'], ReferenceImages::normalizeWanted([['nested'], 'x']));
    }

    public function testUneReferenceSeDesigneParSaCleSonNomOuUnePartieUnique(): void
    {
        $c = self::catalog();

        $this->assertSame(['mascotte.png'], array_column(ReferenceImages::pick($c, ['mascotte.png'])['picked'], 'key'));
        $this->assertSame(['mascotte.png'], array_column(ReferenceImages::pick($c, ['MASCOTTE'])['picked'], 'key'),
            'Nom sans extension, casse ignorée.');
        $this->assertSame(['logo.png (Charte)'], array_column(ReferenceImages::pick($c, ['logo.png (Charte)'])['picked'], 'key'));
        $this->assertSame(['Usine Lyon — façade.jpg'], array_column(ReferenceImages::pick($c, ['usine lyon'])['picked'], 'key'),
            'Partie unique du nom, accents ignorés.');
        $this->assertSame(['Usine Lyon — façade.jpg'], array_column(ReferenceImages::pick($c, ['facade'])['picked'], 'key'));
    }

    public function testUnNomQuiEnDesignePlusieursEstAmbiguJamaisTranche(): void
    {
        $pick = ReferenceImages::pick(self::catalog(), ['logo.png']);
        $this->assertSame([], $pick['picked']);
        $this->assertSame('logo.png', $pick['ambiguous'][0]['wanted']);
        $this->assertSame(['logo.png (assistant)', 'logo.png (Charte)'], $pick['ambiguous'][0]['candidates']);

        $error = ReferenceImages::pickError($pick, self::catalog());
        $this->assertStringContainsString('« logo.png (assistant) »', $error);
        $this->assertStringContainsString('Aucune image n\'a été générée', $error);
    }

    public function testUnNomInconnuEstSignaleAvecLaListe(): void
    {
        $c = self::catalog();
        $pick = ReferenceImages::pick($c, ['mascotte.png', 'chat.png', 'mascotte']);
        $this->assertSame(['mascotte.png'], array_column($pick['picked'], 'key'), 'Une même image n\'est prise qu\'une fois.');
        $this->assertSame(['chat.png'], $pick['unknown']);
        $error = ReferenceImages::pickError($pick, $c);
        $this->assertStringContainsString('« chat.png »', $error);
        $this->assertStringContainsString('Images disponibles : « mascotte.png »', $error);
    }

    public function testAuDelaDeLaListeLErreurRenvoieALaRecherche(): void
    {
        $rows = [];
        for ($i = 1; $i <= ReferenceImages::MAX_LISTED + 3; $i++) {
            $rows[] = ['file_name' => "img$i.png", 'path' => "/$i"];
        }
        $c = ReferenceImages::catalog([], $rows);
        $error = ReferenceImages::pickError(ReferenceImages::pick($c, ['zzz']), $c);
        $this->assertStringContainsString('chercher_images_reference', $error);
    }

    // ---------------------------------------------------------------------
    // Recherche, classement, mention, défaut
    // ---------------------------------------------------------------------

    public function testLaRechercheTrouveParLeNomPuisParLaDescription(): void
    {
        $c = self::catalog();
        $this->assertSame(['Usine Lyon — façade.jpg'], array_column(ReferenceImages::search($c, 'briques rouges'), 'key'));
        $this->assertSame(['logo.png (assistant)', 'logo.png (Charte)'], array_column(ReferenceImages::search($c, 'le logo'), 'key'));
        $hits = ReferenceImages::search($c, 'logo bleu');
        $this->assertSame('logo.png (Charte)', $hits[0]['key'], 'Nom ET description l\'emportent sur le nom seul.');
        $this->assertSame([], ReferenceImages::search($c, 'xylophone'));
    }

    public function testLeClassementGardeLAssistantEnTeteEtRapprocheLesBases(): void
    {
        $c = self::catalog();
        $ranked = ReferenceImages::rank($c, 'une scène devant l\'usine de Lyon');
        $this->assertSame(
            ['mascotte.png', 'logo.png (assistant)', 'Usine Lyon — façade.jpg', 'logo.png (Charte)'],
            array_column($ranked, 'key')
        );
        $this->assertSame(array_column($c, 'key'), array_column(ReferenceImages::rank($c, ''), 'key'),
            'Sans texte, l\'ordre d\'origine est gardé.');
    }

    public function testUnNomCiteDansLaDemandeEstRepere(): void
    {
        $c = self::catalog();
        $this->assertSame(['mascotte.png'], ReferenceImages::mentionedIn($c, 'Dessine la Mascotte à la plage'));
        $this->assertSame([], ReferenceImages::mentionedIn($c, 'Un chat sur un toit'));
    }

    public function testLaReferenceParDefautEstLaPremiereImageDeLAssistant(): void
    {
        $this->assertSame('mascotte.png', ReferenceImages::defaultReference(self::catalog())['key']);
        $kbOnly = ReferenceImages::catalog([], [['file_name' => 'a.png', 'path' => '/a']]);
        $this->assertNull(ReferenceImages::defaultReference($kbOnly), 'Jamais une image de base « par défaut ».');
    }

    public function testLaListeDuPromptNommeProvenanceEtDescription(): void
    {
        $list = ReferenceImages::promptList(self::catalog(), 3);
        $lines = explode("\n", $list);
        $this->assertCount(3, $lines);
        $this->assertSame('- « mascotte.png » (assistant)', $lines[0]);
        $this->assertSame('- « logo.png (Charte) » (base « Charte ») : Logo bleu marine sur fond blanc, typographie arrondie.', $lines[2]);
    }

    public function testLesCotesSontRamenesAuPlusGrandAutorise(): void
    {
        $this->assertSame([2048, 1024], ReferenceImages::fitWithin(4096, 2048, 2048));
        $this->assertSame([1000, 2000], ReferenceImages::fitWithin(1000, 2000, 2048));
        $this->assertSame([800, 600], ReferenceImages::fitWithin(800, 600, 2048));
        $this->assertSame([2048, 68], ReferenceImages::fitWithin(3000, 100, 2048));
    }

    // ---------------------------------------------------------------------
    // Chargement (disque)
    // ---------------------------------------------------------------------

    private function storageDir(): string
    {
        $dir = KnowledgeBase::storageRoot() . '/test-ref-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->dirs[] = $dir;
        return $dir;
    }

    private static function pngBinary(int $w, int $h, bool $gif = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200));
        ob_start();
        $gif ? imagegif($im) : imagepng($im);
        return (string)ob_get_clean();
    }

    public function testUneImageAccepteePartTelleQuelle(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD absent.');
        }
        $path = $this->storageDir() . '/logo.png';
        file_put_contents($path, $bin = self::pngBinary(40, 20));

        $loaded = ReferenceImages::load(['path' => $path, 'mime' => 'image/png']);
        $this->assertSame('image/png', $loaded['mime']);
        $this->assertSame($bin, base64_decode($loaded['b64']));
    }

    public function testUnGifEstConvertiEtUneGrandeImageReduite(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD absent.');
        }
        $dir = $this->storageDir();
        file_put_contents($dir . '/anim.gif', self::pngBinary(30, 30, true));
        $gif = ReferenceImages::load(['path' => $dir . '/anim.gif', 'mime' => 'image/gif']);
        $this->assertSame('image/png', $gif['mime'], 'Le GIF n\'est pas accepté par OpenAI : converti en PNG.');

        file_put_contents($dir . '/large.png', self::pngBinary(3000, 100));
        $large = ReferenceImages::load(['path' => $dir . '/large.png', 'mime' => 'image/png']);
        $size  = getimagesizefromstring(base64_decode($large['b64']));
        $this->assertSame([2048, 68], [$size[0], $size[1]]);
    }

    public function testUnFichierHorsDesDossiersDeStockageEstRefuse(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'ref');
        file_put_contents($outside, 'x');
        try {
            $this->assertSame(['error' => 'fichier introuvable'], ReferenceImages::load(['path' => $outside]));

            $link = $this->storageDir() . '/lien.png';
            if (@symlink($outside, $link)) {
                $this->assertSame(['error' => 'fichier introuvable'], ReferenceImages::load(['path' => $link]),
                    'Un lien qui sort du stockage est refusé (chemin réel).');
            }
            $this->assertSame(['error' => 'fichier introuvable'], ReferenceImages::load(['path' => '']));
        } finally {
            @unlink($outside);
        }
    }

    public function testUneImageQuiNePeutPasPartirEstNommee(): void
    {
        $dir = $this->storageDir();
        file_put_contents($dir . '/note.png', 'pas une image');
        $res = ReferenceImages::loadMany([
            ['key' => 'note.png', 'path' => $dir . '/note.png', 'mime' => 'image/png'],
            ['key' => 'absent.png', 'path' => $dir . '/absent.png'],
        ]);
        $this->assertSame([], $res['images']);
        $this->assertSame(['note.png', 'absent.png'], array_column($res['failed'], 'name'));
    }

    // ---------------------------------------------------------------------
    // Générateur : plusieurs images source
    // ---------------------------------------------------------------------

    public function testLeGenerateurAccepteUneImageOuUneListe(): void
    {
        $this->assertSame([], ImageGenerator::sourceImages(null));
        $this->assertSame([], ImageGenerator::sourceImages([]));
        $this->assertSame([['b64' => 'AAA', 'mime' => 'image/png']], ImageGenerator::sourceImages(['b64' => "A A\nA", 'mime' => 'image/png']));
        $this->assertSame(
            [['b64' => 'AAA', 'mime' => 'image/jpeg'], ['b64' => 'BBB', 'mime' => 'image/png']],
            ImageGenerator::sourceImages([['b64' => 'AAA', 'mime' => 'IMAGE/JPEG'], ['b64' => ''], ['b64' => 'BBB', 'mime' => 'text/plain']])
        );
        $many = array_fill(0, 9, ['b64' => 'AAA', 'mime' => 'image/png']);
        $this->assertCount(ImageGenerator::MAX_SOURCE_IMAGES, ImageGenerator::sourceImages($many));
        $this->assertGreaterThanOrEqual(ReferenceImages::MAX_PER_GENERATION + 1, ImageGenerator::MAX_SOURCE_IMAGES,
            'Place pour l\'image de départ ET toutes les références.');
    }

    // ---------------------------------------------------------------------
    // Outil generer_image (faux générateur)
    // ---------------------------------------------------------------------

    /**
     * @return array{0: ImageTools, 1: \ArrayObject}
     */
    private function tools(array $catalog, ?array $editSource = null, bool $canEdit = true, array $failed = []): array
    {
        $calls = new \ArrayObject();
        $turn = new AgentTurn(1, 1, ['id' => 1], 4000, static function (array $e): void {}, static fn() => []);
        $tools = new ImageTools(
            $turn,
            ['id' => 9, 'provider' => 'openai', 'model' => 'gpt-image-2'],
            $editSource,
            $canEdit,
            null,
            static fn(?array $ref): ?array => $ref === null ? null : ['b64' => 'BASE', 'mime' => 'image/png'],
            static function (string $md): void {},
            $catalog,
            static function (array $entries) use ($failed): array {
                $images = [];
                $ko = [];
                foreach ($entries as $e) {
                    if (in_array($e['key'], $failed, true)) {
                        $ko[] = ['name' => $e['key'], 'reason' => 'trop lourde'];
                    } else {
                        $images[] = ['b64' => 'REF:' . $e['key'], 'mime' => 'image/png', 'name' => $e['key']];
                    }
                }
                return ['images' => $images, 'failed' => $ko];
            },
            static function (array $tool, string $prompt, ?array $images, ?string $instr) use ($calls): array {
                $calls[] = ['prompt' => $prompt, 'images' => array_column($images ?? [], 'b64')];
                return ['ok' => true, 'image_url' => '/assets/img/generated/x' . count($calls) . '.png'];
            }
        );
        return [$tools, $calls];
    }

    public function testSansChoixLePremierAppelPartDeLaReferenceDeLAssistant(): void
    {
        [$tools, $calls] = $this->tools(self::catalog());
        $gen = $tools->handlers()['generer_image'];

        $res = $gen(['prompt' => 'la mascotte à la plage']);
        $this->assertSame(['REF:mascotte.png'], $calls[0]['images'], 'Comportement d\'avant conservé.');
        $this->assertSame(['mascotte.png'], $res['references']);

        // Deuxième image du tour : part de celle produite, sans référence par défaut.
        $gen(['prompt' => 'en bleu', 'utiliser_reference' => true]);
        $this->assertSame(['BASE'], $calls[1]['images']);
    }

    public function testListeVideOuNouvelleImageNEnvoieAucuneReference(): void
    {
        [$tools, $calls] = $this->tools(self::catalog());
        $gen = $tools->handlers()['generer_image'];

        $gen(['prompt' => 'un phare', 'references' => []]);
        $this->assertSame([], $calls[0]['images']);

        [$tools2, $calls2] = $this->tools(self::catalog());
        $tools2->handlers()['generer_image'](['prompt' => 'un phare', 'utiliser_reference' => 'false']);
        $this->assertSame([], $calls2[0]['images']);
    }

    public function testLesReferencesSuiventLImageDeDepartDansLOrdreDonne(): void
    {
        [$tools, $calls] = $this->tools(self::catalog(), ['origin' => 'generated', 'url' => '/assets/img/generated/a.png']);
        $res = $tools->handlers()['generer_image']([
            'prompt'             => 'ajoute le logo de la première référence',
            'utiliser_reference' => true,
            'references'         => ['logo.png (Charte)', 'usine'],
        ]);
        $this->assertSame(['BASE', 'REF:logo.png (Charte)', 'REF:Usine Lyon — façade.jpg'], $calls[0]['images']);
        $this->assertSame(['logo.png (Charte)', 'Usine Lyon — façade.jpg'], $res['references']);
    }

    public function testUneReferenceInconnueTropNombreuseOuIllisibleNeGenereRien(): void
    {
        [$tools, $calls] = $this->tools(self::catalog());
        $gen = $tools->handlers()['generer_image'];

        $this->assertArrayHasKey('error', $gen(['prompt' => 'x', 'references' => ['chat.png']]));
        $this->assertArrayHasKey('error', $gen(['prompt' => 'x', 'references' => ['logo.png']]), 'Ambigu.');
        $this->assertArrayHasKey('error', $gen(['prompt' => 'x', 'references' => ['a', 'b', 'c', 'd', 'e']]));
        $this->assertCount(0, $calls);

        [$tools2, $calls2] = $this->tools(self::catalog(), null, true, ['mascotte.png']);
        $err = $tools2->handlers()['generer_image'](['prompt' => 'x', 'references' => ['mascotte.png']]);
        $this->assertStringContainsString('« mascotte.png » (trop lourde)', $err['error']);
        $this->assertCount(0, $calls2);
    }

    public function testUnModeleSansRetoucheNeRecoitNiParametreNiReference(): void
    {
        [$tools, $calls] = $this->tools(self::catalog(), null, false);
        $def = $tools->definitions()[0];
        $this->assertArrayNotHasKey('references', $def['parameters']['properties']);
        $this->assertArrayNotHasKey('utiliser_reference', $def['parameters']['properties']);
        $tools->handlers()['generer_image'](['prompt' => 'x', 'references' => ['mascotte.png']]);
        $this->assertSame([], $calls[0]['images']);
        $this->assertStringContainsString('ne peuvent pas lui être transmises', $tools->guidance());
    }

    public function testLaDefinitionFermeLesNomsQuandLaListeEstComplete(): void
    {
        [$tools] = $this->tools(self::catalog());
        $defs = $tools->definitions();
        $this->assertCount(1, $defs, 'Pas de recherche quand tout est listé.');
        $refs = $defs[0]['parameters']['properties']['references'];
        $this->assertSame('array', $refs['type']);
        $this->assertSame(ReferenceImages::MAX_PER_GENERATION, $refs['maxItems']);
        $this->assertSame(array_column(self::catalog(), 'key'), $refs['items']['enum']);
        $this->assertStringContainsString('« mascotte.png »', $refs['description']);

        $guidance = $tools->guidance();
        $this->assertStringContainsString('- « logo.png (Charte) » (base « Charte ») : Logo bleu marine', $guidance);
        $this->assertStringContainsString('Images de référence disponibles (4)', $guidance);

        // Une image de la conversation sert de base : le choix est exigé.
        [$withBase] = $this->tools(self::catalog(), ['origin' => 'user', 'b64' => 'X']);
        $this->assertContains('utiliser_reference', $withBase->definitions()[0]['parameters']['required']);
    }

    public function testAuDelaDeLaListeLaRechercheEstExposee(): void
    {
        $rows = [];
        for ($i = 1; $i <= ReferenceImages::MAX_LISTED + 5; $i++) {
            $rows[] = ['file_name' => "photo-$i.jpg", 'kb_name' => 'Photothèque', 'path' => "/$i",
                'extracted_text' => $i === 29 ? 'Camion de livraison rouge garé devant l\'entrepôt' : 'Paysage'];
        }
        [$tools] = $this->tools(ReferenceImages::catalog([], $rows));
        $defs = $tools->definitions();
        $this->assertSame(['generer_image', 'chercher_images_reference'], array_column($defs, 'name'));
        $this->assertArrayNotHasKey('enum', $defs[0]['parameters']['properties']['references']['items'],
            'Liste incomplète : les noms ne sont pas fermés.');
        $this->assertStringContainsString('(25 sur 30)', $tools->guidance());

        $found = $tools->handlers()['chercher_images_reference'](['requete' => 'camion rouge']);
        $this->assertSame('photo-29.jpg', $found['images'][0]['nom']);
        $this->assertSame(30, $found['total']);
        $none = $tools->handlers()['chercher_images_reference'](['requete' => 'xylophone']);
        $this->assertArrayHasKey('note', $none);
    }

    public function testSansReferenceLOutilResteCeQuIlEtait(): void
    {
        [$tools, $calls] = $this->tools([]);
        $def = $tools->definitions()[0];
        $this->assertArrayNotHasKey('references', $def['parameters']['properties']);
        $this->assertStringContainsString('Aucune image source n\'est disponible', $tools->guidance());
        $res = $tools->handlers()['generer_image'](['prompt' => 'un phare']);
        $this->assertArrayNotHasKey('references', $res);
        $this->assertSame([], $calls[0]['images']);
    }

    public function testLaTraceNommeLesReferencesTransmises(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $this->assertSame(
            'D’après « logo.png », « usine.jpg » — le logo sur la façade',
            ChatController::imageTraceDetail(['prompt' => 'le logo sur la façade'], ['references' => ['logo.png', 'usine.jpg']])
        );
        $this->assertSame('un phare', ChatController::imageTraceDetail(['prompt' => 'un phare'], ['ok' => true]));
        $this->assertSame('un phare', ChatController::imageTraceDetail(['prompt' => 'un phare', 'references' => ['a.png']], ['error' => 'x']),
            'Une image refusée ne revendique aucune référence.');
        $this->assertSame('D’après « a.png » — x', ChatController::imageTraceDetail(['prompt' => 'x', 'references' => ['a.png']], []),
            'Ligne « en cours » : les références demandées.');
        $this->assertSame(['t' => 'reference_images_search', 'd' => 'camion'],
            ChatController::actionEntryForTool('chercher_images_reference', ['requete' => 'camion'], []));
        $this->assertSame('Recherche d\'images de référence', ChatController::actionLabel('reference_images_search'));
        $this->assertSame('image', ChatController::actionIcon('reference_images_search'));
    }

    public function testLaPreparationDeLOutilImageRendLesReferencesChoisies(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $parsed = ChatController::parseImageRequestJson('{"prompt":"la mascotte au ski","modification":false,"references":["mascotte.png","mascotte.png"]}');
        $this->assertSame(['mascotte.png'], $parsed['references']);
        $this->assertSame([], ChatController::parseImageRequestJson('{"prompt":"un phare"}')['references']);
    }

    // ---------------------------------------------------------------------
    // Câblage
    // ---------------------------------------------------------------------

    /** Corps d'une méthode du contrôleur, jusqu'à la méthode suivante. */
    private static function method(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        self::assertNotFalse($start, "Méthode introuvable : $signature");
        $next = preg_match('/\n    (?:public|private|protected) (?:static )?function /', $src, $m, PREG_OFFSET_CAPTURE, $start + strlen($signature));
        return substr($src, $start, $next === 1 ? $m[0][1] - $start : null);
    }

    public function testLesDeuxCheminsDuChatLisentLeCatalogueDesBasesLocales(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');

        $send = self::method($src, 'public function runSend(');
        $this->assertStringContainsString('ReferenceImages::forTurn($profile, KnowledgeBase::partitionByBackend($kbs)[1])', $send);
        $this->assertStringNotContainsString('ProfileDocument::imageParts((int)$profile[\'id\'], 1)', $send,
            'La seule première image de l\'assistant ne sert plus de source.');
        $this->assertMatchesRegularExpression('/\$fullResp \.= \$markdown;\s*\},\s*\$referenceImages\s*\);/', $send,
            'Le catalogue est passé à l\'outil image.');

        $imagine = self::method($src, 'public function runImagine(');
        $this->assertStringContainsString('ConversationKnowledgeBase::selectedKbsForTurn($convId, $user)', $imagine);
        $this->assertStringContainsString('ReferenceImages::forTurn($profile, KnowledgeBase::partitionByBackend($kbs)[1])', $imagine);
        $this->assertStringContainsString('$this->prepareImageRequest($conv, $history, $prompt, $canEdit, $references, $profile)', $imagine);
        $this->assertStringContainsString('ReferenceImages::loadMany(', $imagine);
        $this->assertStringContainsString('Message::setActions((int)$msg[\'id\'], $this->actionTrace)', $imagine);
        $this->assertStringContainsString("'actions' => \$this->actionTrace", $imagine);

        $prepare = self::method($src, 'private function prepareImageRequest(');
        $this->assertStringContainsString("if (\$digest === '' && \$references === [])", $prepare,
            'Avec des références, la première demande passe aussi par le choix.');
        $this->assertStringContainsString('ReferenceImages::promptList($references', $prepare);
    }

    public function testLImageDuMenuPlusAfficheSaTraceEnDirect(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $start = strpos($js, 'JOB_TYPES.image = {');
        $block = substr($js, $start, (int)strpos($js, 'JOB_TYPES.video = {') - $start);
        $this->assertStringContainsString("renderActionTrace(el.closest('.msg-body'), json.actions)", $block);
    }

    public function testLaClasseEstChargeeEtDocumentee(): void
    {
        $this->assertStringContainsString("require ROOT . '/src/Helpers/ReferenceImages.php';", (string)file_get_contents(ROOT . '/config/classes.php'));
        $this->assertStringContainsString("'/src/Helpers/ReferenceImages.php'", (string)file_get_contents(ROOT . '/tests/bootstrap.php'));

        $en = require ROOT . '/lang/en.php';
        $this->assertArrayHasKey('D’après {references}', $en);
        $this->assertArrayHasKey('Recherche d\'images de référence', $en);

        foreach ([
            'docs/user/fonctionnalites/outils/generer-une-image.md' => 'Reprendre les images d\'un assistant ou d\'une base',
            'docs/GUIDE-UTILISATEUR.md'                         => 'Reprendre les images d\'un assistant ou d\'une base',
            'docs/GUIDE-CREATEUR.md'                            => 'Images de référence.',
            'docs/NOUVEAUTES.md'                                => 'id: images-de-reference',
        ] as $file => $needle) {
            $this->assertStringContainsString($needle, (string)file_get_contents(ROOT . '/' . $file), $file);
        }
    }
}
