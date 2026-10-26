<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Modèles d'outils arrêtés par leur fournisseur (bloc `retirement` de
 * config/tools_catalog.php).
 *
 * Le problème : OpenAI coupe son API vidéo le 24/09/2026 sans remplaçant, et
 * DALL-E 2/3, Imagen 4 et ChatGPT 5.3 étaient déjà coupés tout en restant
 * proposés. Un outil sur un tel modèle échouait à chaque demande, avec le
 * message brut du fournisseur, en anglais, sans que rien ne prévienne ni
 * l'utilisateur ni l'administrateur. Désormais : outil grisé AVEC la raison,
 * aucun appel au fournisseur, alerte sur la carte et au Diagnostic, modèle
 * retiré des listes de création — le tout lu à la date, sans écriture en base.
 */
final class ToolRetirementTest extends TestCase
{
    private string $locale = 'fr';

    protected function setUp(): void
    {
        $this->locale = I18n::locale();
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        ToolCatalog::setToday(null);
        I18n::use($this->locale);
    }

    public function testChaqueArretDuCatalogueEstDateEtSource(): void
    {
        $vus = 0;
        foreach (['image', 'video'] as $type) {
            foreach (ToolCatalog::providers($type) as $provider => $entry) {
                $blocs = [];
                if (isset($entry['retirement'])) {
                    $blocs["$type.$provider"] = $entry['retirement'];
                }
                foreach ((array)($entry['models'] ?? []) as $model => $info) {
                    if (isset($info['retirement'])) {
                        $blocs["$type.$provider.$model"] = $info['retirement'];
                    }
                }
                foreach ($blocs as $id => $bloc) {
                    $vus++;
                    $this->assertIsArray($bloc, $id);
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string)($bloc['date'] ?? ''), "$id : date AAAA-MM-JJ attendue");
                    $this->assertMatchesRegularExpression('#^https://#', (string)($bloc['source_url'] ?? ''), "$id : annonce officielle attendue");
                    $this->assertNotSame('', trim((string)($bloc['note'] ?? '')), "$id : note pour l'administrateur attendue");
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string)($bloc['verified'] ?? ''), "$id : date de relecture de la source attendue");
                }
            }
        }
        $this->assertGreaterThan(0, $vus, 'Aucun arrêt déclaré au catalogue.');
    }

    public function testSoraSArreteLe24SeptembreEtSora1NEstPlusPropose(): void
    {
        $this->assertArrayNotHasKey('sora-1', ToolCatalog::models('video', 'openai'), 'sora-1 n\'a jamais été un modèle de l\'API.');
        $this->assertSame('2026-09-24', ToolCatalog::retirement('video', 'openai', 'sora-2')['date'] ?? null);
        // Arrêt du FOURNISSEUR : il vaut aussi pour un modèle hors catalogue
        // (un outil resté réglé sur « sora-1 »).
        $this->assertSame('2026-09-24', ToolCatalog::retirement('video', 'openai', 'sora-1')['date'] ?? null);
    }

    public function testLArretBasculeLeJourDitEtPasLaVeille(): void
    {
        $outil = ['type' => 'video', 'provider' => 'openai', 'model' => 'sora-2'];

        ToolCatalog::setToday('2026-09-23');
        $this->assertFalse(ChatTool::isRetired($outil));
        $this->assertNull(ChatTool::retiredNotice($outil), 'Avant la date, rien ne doit refuser la demande.');
        $this->assertFalse(ChatTool::retirement($outil)['retired'] ?? true);

        ToolCatalog::setToday('2026-09-24');
        $this->assertTrue(ChatTool::isRetired($outil));
        $this->assertStringContainsString('24/09/2026', (string)ChatTool::retiredNotice($outil));
    }

    public function testLesModelesImageDejaCoupesSontArretesEtPasLesAutres(): void
    {
        ToolCatalog::setToday('2026-09-10');
        $arretes = [
            ['openai', 'dall-e-3'],
            ['openai', 'dall-e-2'],
            ['openai', 'gpt-5.3-chat-latest'],
            ['gemini', 'imagen-4.0-generate-001'],
        ];
        foreach ($arretes as [$provider, $model]) {
            $this->assertTrue(
                ChatTool::isRetired(['type' => 'image', 'provider' => $provider, 'model' => $model]),
                "$provider/$model devrait être arrêté"
            );
        }
        $enService = [
            ['openai', 'gpt-image-2'],
            ['openai', 'gpt-5.4'],
            ['openai', 'gpt-5-mini'],
            ['gemini', 'gemini-3.1-flash-image'],
            ['gemini', 'gemini-3-pro-image'],
        ];
        foreach ($enService as [$provider, $model]) {
            $this->assertFalse(
                ChatTool::isRetired(['type' => 'image', 'provider' => $provider, 'model' => $model]),
                "$provider/$model est en service"
            );
        }
    }

    public function testSeulsLesOutilsAModeleDuCatalogueSontConcernes(): void
    {
        ToolCatalog::setToday('2026-12-31');
        foreach (['html', 'html_illustrated', 'map', 'presentation', 'n8n', 'gamma'] as $type) {
            $outil = ['type' => $type, 'provider' => 'openai', 'model' => 'dall-e-3'];
            $this->assertNull(ChatTool::retirement($outil), $type);
            $this->assertNull(ChatTool::retiredNotice($outil), $type);
        }
    }

    public function testLaCreationNeProposePlusLesModelesArretes(): void
    {
        ToolCatalog::setToday('2026-09-23');
        $this->assertArrayHasKey('openai', ToolCatalog::offerableProviders('video'));

        ToolCatalog::setToday('2026-09-24');
        $this->assertSame([], ToolCatalog::offerableProviders('video'), 'Plus aucun fournisseur vidéo en service.');

        $images = ToolCatalog::offerableModels('image', 'openai');
        $this->assertArrayHasKey('gpt-image-2', $images);
        foreach (['dall-e-3', 'dall-e-2', 'gpt-5.3-chat-latest'] as $model) {
            $this->assertArrayNotHasKey($model, $images);
        }
        $this->assertArrayNotHasKey('imagen-4.0-generate-001', ToolCatalog::offerableModels('image', 'gemini'));

        // La fiche d'un outil existant, elle, lit TOUT le catalogue : elle
        // doit pouvoir afficher le modèle actuel de l'outil, même arrêté.
        $this->assertArrayHasKey('dall-e-3', ToolCatalog::models('image', 'openai'));
    }

    public function testLesGenerateursNAppellentPlusUnModeleArrete(): void
    {
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Helpers/VideoGenerator.php';
        ToolCatalog::setToday('2026-09-24');

        // Ni clé ni base ici : si le contrôle ne passait pas EN PREMIER, la
        // recherche de la clé API ferait échouer le test.
        $video = VideoGenerator::generate(['type' => 'video', 'provider' => 'openai', 'model' => 'sora-2'], 'Un chat qui court');
        $this->assertStringContainsString('arrêté par son fournisseur le 24/09/2026', (string)($video['error'] ?? ''));

        $image = ImageGenerator::generate(['type' => 'image', 'provider' => 'openai', 'model' => 'dall-e-3'], 'Un chat');
        $this->assertStringContainsString('arrêté par son fournisseur le 12/05/2026', (string)($image['error'] ?? ''));
    }

    public function testLeRefusEstTraduit(): void
    {
        ToolCatalog::setToday('2026-09-24');
        I18n::use('en');
        $message = (string)ChatTool::retiredNotice(['type' => 'video', 'provider' => 'openai', 'model' => 'sora-2']);
        $this->assertStringContainsString('discontinued by its provider on 09/24/2026', $message);
    }

    public function testLeDiagnosticSignaleLesOutilsActifsSurUnModeleArrete(): void
    {
        require_once ROOT . '/src/Helpers/Requirements.php';
        require_once ROOT . '/src/Helpers/Diagnostics.php';
        ToolCatalog::setToday('2026-09-10');

        $vert = Diagnostics::controleOutilsArretes([
            ['name' => 'Affiches', 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2', 'is_active' => 1],
            // Inactif : proposé à personne, il ne compte pas.
            ['name' => 'Ancien', 'type' => 'image', 'provider' => 'openai', 'model' => 'dall-e-3', 'is_active' => 0],
        ]);
        $this->assertSame(Diagnostics::OK, $vert['statut']);

        $c = Diagnostics::controleOutilsArretes([
            ['name' => 'Illustrations', 'type' => 'image', 'provider' => 'openai', 'model' => 'dall-e-3', 'is_active' => 1],
            ['name' => 'Clips', 'type' => 'video', 'provider' => 'openai', 'model' => 'sora-2', 'is_active' => 1],
        ]);
        $this->assertSame(Diagnostics::WARN, $c['statut'], 'Un avertissement, jamais un échec : doctor.php ne doit pas sortir en erreur.');
        $this->assertStringContainsString('1 hors service', $c['valeur']);
        $this->assertStringContainsString('1 arrêt(s) annoncé(s)', $c['valeur']);
        $this->assertStringContainsString('« Illustrations » (12/05/2026)', $c['message']);
        $this->assertStringContainsString('« Clips » (24/09/2026)', $c['message']);
        $this->assertNotSame('', $c['remede']);
        $this->assertSame(
            [['type' => 'link', 'label' => 'Ouvrir les outils', 'value' => '/admin/tools']],
            Diagnostics::actions($c)
        );

        $source = (string)file_get_contents(ROOT . '/src/Helpers/Diagnostics.php');
        $this->assertStringContainsString('self::outilsArretes(),', $source, 'Le contrôle n\'est pas branché dans services().');
    }

    public function testLesRoutesRefusentAvantDEnfilerEtLesResolveursEcartent(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';

        foreach (['generateVideo', 'imagine'] as $route) {
            $corps = $this->corps($route);
            $refus = strpos($corps, 'ChatTool::retiredNotice(');
            $this->assertIsInt($refus, "$route ne refuse pas un modèle arrêté.");
            $this->assertLessThan(strpos($corps, 'Message::create('), $refus, "$route enregistre la demande avant de refuser.");
            $this->assertLessThan(strpos($corps, 'JobQueue::enqueue('), $refus, "$route enfile un job voué à l'échec.");
        }

        $deck = $this->corps('generateDeckImage');
        $this->assertIsInt(strpos($deck, 'ChatTool::retiredNotice('), 'generateDeckImage ne refuse pas un modèle arrêté.');
        $this->assertLessThan(strpos($deck, 'ImageGenerator::generate('), strpos($deck, 'ChatTool::retiredNotice('));

        foreach (['regenerateImage', 'resolvePresentationImageTool', 'resolveProfileImageTool', 'activeProfileToolBadges'] as $methode) {
            $this->assertStringContainsString('ChatTool::isRetired(', $this->corps($methode), "$methode garde un outil hors service.");
        }
        $this->assertStringContainsString(
            'ChatTool::isRetired($candidate)',
            (string)file_get_contents(ROOT . '/src/Jobs/HtmlPageJob.php'),
            'Un site illustré choisirait encore un outil image hors service.'
        );

        // Le bouton « Tester » ne relance pas une génération vouée à l'échec
        // (et ne la facture pas) : le contrôle précède l'aiguillage par type.
        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $debut = strpos($admin, 'private function executeToolTest(');
        $this->assertIsInt($debut);
        $corps = substr($admin, $debut, 1200);
        $this->assertLessThan(strpos($corps, 'return match ($tool[\'type\'])'), strpos($corps, 'ChatTool::retirement($tool)'));
    }

    public function testLeChatGriseLOutilAvecLaRaison(): void
    {
        $gabarit = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString('ChatTool::retirement($tool)', $gabarit);
        $this->assertStringContainsString("t('Arrêté par son fournisseur le {date} — prévenir un administrateur'", $gabarit);
        $this->assertStringContainsString("'retired'   => (string)(ChatTool::retiredNotice(\$t) ?? '')", $gabarit);

        $chat = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringNotContainsString("'sora-1'", $chat);
        $this->assertStringContainsString('!outil.retired', $chat, 'La popup « Site illustré » proposerait un outil hors service.');
        $this->assertStringContainsString('!outil.retired', (string)file_get_contents(ROOT . '/public/assets/js/deck.js'));
    }

    public function testLEcranOutilsNeProposePlusNiNeRemplaceUnModeleArrete(): void
    {
        $admin = (string)file_get_contents(ROOT . '/templates/admin/tools.php');
        $this->assertStringContainsString("ToolCatalog::offerableProviders('video')", $admin);
        $this->assertStringContainsString("ToolCatalog::offerableModels('image', 'openai')", $admin);
        // Plus aucune liste de modèles écrite en dur : le catalogue fait foi.
        $this->assertDoesNotMatchRegularExpression('/<option value="(sora-\d|dall-e-\d|gpt-5\.3-chat-latest)"/', $admin);
        $this->assertStringNotContainsString("'sora-2'", $admin);
        $this->assertStringNotContainsString("'gpt-5.3-chat-latest'", $admin);
        // La fiche garde le modèle ACTUEL d'un outil, même arrêté : sans quoi
        // l'enregistrement automatique le changerait en silence.
        $this->assertStringContainsString('function showCurrentModel(sel, current)', $admin);
        $this->assertStringContainsString('data-retired="1"', $admin);
        // La carte prévient avant la date, puis signale l'outil hors service.
        $this->assertStringContainsString('ChatTool::retirement($tool)', $admin);
        $this->assertStringContainsString('Arrêt annoncé le', $admin);
        $this->assertStringContainsString('Hors service', $admin);
    }

    public function testLaDocumentationLeDit(): void
    {
        foreach ([
            'docs/user/fonctionnalites/outils/generer-une-video.md',
            'docs/user/fonctionnalites/outils/generer-une-image.md',
            'docs/user/admin/outils/tester-et-entretenir-les-outils.md',
            'docs/user/admin/instance/consulter-les-logs-et-le-diagnostic.md',
        ] as $page) {
            $texte = (string)file_get_contents(ROOT . '/' . $page);
            $this->assertMatchesRegularExpression('/arrêté par son fournisseur/iu', $texte, "$page ne dit rien des modèles arrêtés.");
        }
        $this->assertStringContainsString(
            'id: outils-modeles-arretes',
            (string)file_get_contents(ROOT . '/docs/NOUVEAUTES.md')
        );
    }

    private function corps(string $methode): string
    {
        $m      = new ReflectionMethod(ChatController::class, $methode);
        $lignes = file((string)$m->getFileName()) ?: [];

        return implode('', array_slice($lignes, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }
}
