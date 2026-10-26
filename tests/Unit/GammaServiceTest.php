<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Outil Gamma — partie pure (aucune base, aucun réseau).
 *
 * Ce qui est vérifié ici est ce qui casse en silence en production : un
 * payload qui dépasse les bornes de l'API (400 muet), un champ vide émis
 * (idem), un 402 crédits présenté comme une erreur générique, et le câblage
 * du type d'outil — un maillon manquant ne produit aucune erreur, juste un
 * assistant qui « ne peut pas ».
 */
final class GammaServiceTest extends TestCase
{
    // ---------- Bornes et whitelists ----------

    public function testPlafondDeCartesAdminEstBorne(): void
    {
        $this->assertSame(GammaService::DEFAULT_MAX_CARDS, GammaService::sanitizeMaxCards(null));
        $this->assertSame(GammaService::DEFAULT_MAX_CARDS, GammaService::sanitizeMaxCards(0));
        $this->assertSame(GammaService::DEFAULT_MAX_CARDS, GammaService::sanitizeMaxCards(''));
        // Zéro ou négatif = « non renseigné » : on retombe sur le défaut.
        $this->assertSame(GammaService::DEFAULT_MAX_CARDS, GammaService::sanitizeMaxCards(-3));
        $this->assertSame(75, GammaService::sanitizeMaxCards(999));
        $this->assertSame(30, GammaService::sanitizeMaxCards('30'));
    }

    public function testNombreDeCartesDemandeEstClampeParLePlafond(): void
    {
        $this->assertSame(GammaService::DEFAULT_CARDS, GammaService::normalizeCardCount(null, 20));
        $this->assertSame(20, GammaService::normalizeCardCount(50, 20));
        $this->assertSame(GammaService::DEFAULT_CARDS, GammaService::normalizeCardCount(-2, 20));
        $this->assertSame(15, GammaService::normalizeCardCount('15', 20));
        // Un plafond hors bornes est lui-même ramené aux limites API.
        $this->assertSame(75, GammaService::normalizeCardCount(200, 500));
    }

    public function testModeTexteHorsWhitelistRetombeSurGenerate(): void
    {
        $this->assertSame('generate', GammaService::normalizeTextMode(''));
        $this->assertSame('generate', GammaService::normalizeTextMode('inventif'));
        $this->assertSame('preserve', GammaService::normalizeTextMode(' PRESERVE '));
        $this->assertSame('condense', GammaService::normalizeTextMode('condense'));
    }

    public function testExportEtSourceDImagesSontFiltres(): void
    {
        $this->assertSame('', GammaService::normalizeExportAs('docx'));
        $this->assertSame('pptx', GammaService::normalizeExportAs(' PPTX '));
        $this->assertSame('aiGenerated', GammaService::normalizeImageSource(''));
        $this->assertSame('aiGenerated', GammaService::normalizeImageSource('webAllImages'));
        $this->assertSame('noImages', GammaService::normalizeImageSource('noImages'));
    }

    public function testConfigFromPostNormaliseToutesLesEntrees(): void
    {
        $config = GammaService::configFromPost([
            'config_gamma_theme_id'             => '  theme-corpo  ',
            'config_gamma_export_as'            => 'docx',
            'config_gamma_image_source'         => 'pexels',
            'config_gamma_max_cards'            => '120',
            'config_gamma_default_instructions' => "  Ton sobre.\nCharte bleue.  ",
        ]);
        $this->assertSame('theme-corpo', $config['theme_id']);
        $this->assertSame('', $config['export_as']);
        $this->assertSame('pexels', $config['image_source']);
        $this->assertSame(75, $config['max_cards']);
        $this->assertSame("Ton sobre.\nCharte bleue.", $config['default_instructions']);
    }

    // ---------- Payload de génération ----------

    public function testSujetVideEstRefuseAvecUnMessageFrancais(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sujet');
        GammaService::buildGenerationPayload([], ['sujet' => '   ']);
    }

    public function testPayloadMinimalEstCompletEtSansClesVides(): void
    {
        $payload = GammaService::buildGenerationPayload([], ['sujet' => 'Lancement produit Q4']);
        $this->assertSame('Lancement produit Q4', $payload['inputText']);
        $this->assertSame('generate', $payload['textMode']);
        $this->assertSame('presentation', $payload['format']);
        $this->assertSame(GammaService::DEFAULT_CARDS, $payload['numCards']);
        $this->assertSame(['source' => 'aiGenerated'], $payload['imageOptions']);
        // Aucune clé vide : Gamma répond 400 sur un champ vide.
        $this->assertArrayNotHasKey('title', $payload);
        $this->assertArrayNotHasKey('additionalInstructions', $payload);
        $this->assertArrayNotHasKey('themeId', $payload);
        $this->assertArrayNotHasKey('exportAs', $payload);
    }

    public function testPayloadCompletRespecteLaConfigAdmin(): void
    {
        $config = [
            'theme_id'             => 'theme-corpo',
            'export_as'            => 'pptx',
            'image_source'         => 'pictographic',
            'max_cards'            => 12,
            'default_instructions' => 'Ton sobre, charte bleue.',
        ];
        $payload = GammaService::buildGenerationPayload($config, [
            'sujet'         => 'Bilan annuel',
            'titre'         => 'Bilan 2026',
            'nombre_cartes' => 40,
            'instructions'  => 'Une slide de conclusion chiffrée.',
            'mode_texte'    => 'condense',
        ]);
        $this->assertSame('Bilan 2026', $payload['title']);
        $this->assertSame('condense', $payload['textMode']);
        $this->assertSame(12, $payload['numCards'], 'Le plafond admin doit clamper la demande du modèle.');
        $this->assertSame('theme-corpo', $payload['themeId']);
        $this->assertSame('pptx', $payload['exportAs']);
        $this->assertSame('pictographic', $payload['imageOptions']['source']);
        // Les consignes admin passent DEVANT celles du modèle.
        $this->assertSame(
            "Ton sobre, charte bleue.\nUne slide de conclusion chiffrée.",
            $payload['additionalInstructions']
        );
    }

    public function testPayloadTronqueLesChampsAuxBornesApi(): void
    {
        $payload = GammaService::buildGenerationPayload(
            ['default_instructions' => str_repeat('a', 6000)],
            ['sujet' => str_repeat('s', 500000), 'titre' => str_repeat('t', 900)]
        );
        $this->assertSame(GammaService::MAX_INPUT_CHARS, mb_strlen($payload['inputText']));
        $this->assertSame(GammaService::MAX_TITLE_CHARS, mb_strlen($payload['title']));
        $this->assertSame(GammaService::MAX_INSTRUCTIONS_CHARS, mb_strlen($payload['additionalInstructions']));
    }

    // ---------- Réponses de l'API ----------

    public function testCreationReussieRenvoieLIdentifiant(): void
    {
        $this->assertSame(
            'gen-123',
            GammaService::parseCreateResponse(200, '{"generationId":"gen-123"}')
        );
    }

    public function testCreditsEpuisesOntLeurPropreMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Crédits Gamma épuisés');
        GammaService::parseCreateResponse(402, '{"message":"insufficient credits"}');
    }

    public function testCleRefuseeNommeLePlanRequis(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pro');
        GammaService::parseCreateResponse(401, '');
    }

    public function testCreationSansIdentifiantEstUneErreur(): void
    {
        $this->expectException(RuntimeException::class);
        GammaService::parseCreateResponse(200, '{"warnings":"ok mais vide"}');
    }

    public function testStatutCompletedPorteLiensEtCredits(): void
    {
        $etat = GammaService::parseStatusResponse(200, (string)json_encode([
            'status'    => 'completed',
            'gammaUrl'  => 'https://gamma.app/docs/abc',
            'exportUrl' => 'https://export.gamma.app/abc.pptx',
            'credits'   => ['deducted' => 60, 'remaining' => 940],
        ]));
        $this->assertSame('completed', $etat['status']);
        $this->assertSame('https://gamma.app/docs/abc', $etat['gammaUrl']);
        $this->assertSame('https://export.gamma.app/abc.pptx', $etat['exportUrl']);
        $this->assertSame(60, $etat['credits_deducted']);
        $this->assertSame(940, $etat['credits_remaining']);
    }

    public function testStatutFailedRemonteLeMotif(): void
    {
        $etat = GammaService::parseStatusResponse(200,
            '{"status":"failed","error":{"message":"content policy","statusCode":422}}');
        $this->assertSame('failed', $etat['status']);
        $this->assertSame('content policy', $etat['error']);
    }

    public function testStatutInconnuVautPendingPlutotQuUnEchec(): void
    {
        // Une future version d'API qui ajoute un état intermédiaire ne doit
        // pas faire échouer un suivi en cours.
        $this->assertSame('pending', GammaService::parseStatusResponse(200, '{"status":"rendering"}')['status']);
        $this->assertSame('pending', GammaService::parseStatusResponse(200, '{"status":"pending"}')['status']);
        $this->assertSame('pending', GammaService::parseStatusResponse(200, 'pas du json')['status']);
    }

    public function testThemesTolereLesDeuxEnveloppes(): void
    {
        $this->assertCount(2, (array)GammaService::themesFromBody('[{"id":"a"},{"id":"b"}]'));
        $this->assertCount(1, (array)GammaService::themesFromBody('{"data":[{"id":"a"}]}'));
        $this->assertNull(GammaService::themesFromBody('{"autre":"forme"}'));
        $this->assertNull(GammaService::themesFromBody('pas du json'));
        $this->assertTrue(GammaService::themeExists([['id' => 'Theme-A']], 'theme-a'));
        $this->assertFalse(GammaService::themeExists([['id' => 'a']], 'b'));
    }

    // ---------- Carte d'approbation et message final ----------

    public function testActionPreviewNommeLaPresentationEtLesCredits(): void
    {
        $preview = GammaService::actionPreview('generer_presentation_gamma', [
            'sujet'         => 'Stratégie 2027',
            'titre'         => 'Cap 2027',
            'nombre_cartes' => 8,
            'instructions'  => 'Insister sur les jalons.',
        ], ['max_cards' => 20]);

        $this->assertSame('Générer une présentation Gamma', $preview['action']);
        $this->assertSame('Cap 2027', $preview['fichier']);
        $this->assertNull($preview['url']);
        $this->assertSame('/assets/img/gamma.svg', $preview['icone']);
        $this->assertStringContainsString('crédits Gamma', implode(' ', $preview['impacts']));
        $this->assertStringContainsString('Stratégie 2027', (string)$preview['apercu']);
        $this->assertStringContainsString('Cartes : 8', (string)$preview['apercu']);
    }

    public function testActionPreviewResisteAUneDemandeVide(): void
    {
        $preview = GammaService::actionPreview('generer_presentation_gamma', []);
        $this->assertSame('(présentation sans titre)', $preview['fichier']);
        $this->assertNotEmpty($preview['impacts']);
    }

    public function testMessageFinalPorteLesLiens(): void
    {
        $texte = GammaService::summarizeResultMessage('Cap 2027', [
            'gammaUrl'          => 'https://gamma.app/docs/abc',
            'exportUrl'         => 'https://export.gamma.app/abc.pptx',
            'credits_remaining' => 940,
        ]);
        $this->assertStringContainsString('[« Cap 2027 »](https://gamma.app/docs/abc)', $texte);
        $this->assertStringContainsString('(https://export.gamma.app/abc.pptx)', $texte);
        $this->assertStringContainsString('940', $texte);
    }

    // ---------- Configuration de l'outil ----------

    public function testFromToolRefuseUnAutreTypeOuUneCleAbsente(): void
    {
        $this->assertNull(GammaService::fromTool(null));
        $this->assertNull(GammaService::fromTool(['type' => 'image', 'api_key_enc' => 'x']));
        $this->assertNull(GammaService::fromTool(['type' => 'gamma', 'api_key_enc' => '']));
    }

    // ---------- Câblage : le type d'outil existe de bout en bout ----------

    /**
     * Un maillon manquant ne produit aucune erreur, juste un outil qu'on peut
     * créer mais que personne n'expose : carte de choix côté administration,
     * config lue/écrite par le contrôleur, résolution côté chat, test dédié.
     */
    public function testLeTypeDOutilEstBrancheDeBoutEnBout(): void
    {
        $tools = (string)file_get_contents(ROOT . '/templates/admin/tools.php');
        $this->assertStringContainsString('data-t="gamma"', $tools, 'Aucune carte de type gamma.');
        $this->assertStringContainsString('config_gamma_theme_id', $tools);
        $this->assertStringContainsString('config_gamma_max_cards', $tools);

        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString('GammaService::configFromPost', $admin);
        $this->assertStringContainsString("'gamma' => \$this->runGammaToolTest(\$tool)", $admin,
            'Le bouton Tester de la liste ne saurait pas quoi faire d\'un outil gamma.');
        // Outils liables à un assistant : source unique partagée par la fiche
        // (sanitizeProfileToolIds) et l'assistant préparé par MIA.
        $this->assertStringContainsString('AssistantDraft::sanitizeToolIds($raw)', $admin);
        $this->assertStringContainsString("N8nService::TOOL_TYPE, GammaService::TOOL_TYPE",
            (string)file_get_contents(ROOT . '/src/Assistants/AssistantDraft.php'),
            'sanitizeProfileToolIds ignorerait les liaisons gamma (jetées en silence au save).');

        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('private function resolveProfileGammaTool', $chat);
        $this->assertStringContainsString('GammaService::fromTool', $chat);
    }

    /**
     * L'outil exposé au modèle doit être défini ET nommé dans la consigne,
     * avec son handler — et le handler ne fait AUCUN réseau : il dépose une
     * action d'approbation (send() tourne dans le worker).
     */
    public function testLOutilEstDefiniDocumenteEtGate(): void
    {
        $chat = AgentSource::chat();
        $this->assertStringContainsString("'name'        => 'generer_presentation_gamma'", $chat,
            '« generer_presentation_gamma » non défini.');
        $this->assertStringContainsString("\$toolHandlers['generer_presentation_gamma']", $chat);
        $this->assertStringContainsString('gammaToolGuidance', $chat);
        // La carte d'approbation est le SEUL chemin : le handler ne doit pas
        // appeler createGeneration (ça vivrait dans le stream, côté worker).
        $this->assertStringContainsString("queueConnectorWrite('generer_presentation_gamma'", $chat);
    }

    /**
     * L'exécution approuvée doit être dispatchée dans la route d'approbation,
     * re-résoudre l'OUTIL (droits au moment du clic) et enfiler le job de
     * suivi — le POST part ici, jamais pendant le stream.
     */
    public function testLExecutionApprouveeReResoutLOutilEtEnfileLeSuivi(): void
    {
        $chat  = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $debut = strpos($chat, 'public function driveAction');
        $fin   = strpos($chat, 'private function executeDriveWrite');
        $bloc  = substr($chat, (int)$debut, (int)$fin - (int)$debut);
        $this->assertStringContainsString('generer_presentation_gamma', $bloc);
        $this->assertStringContainsString('ChatTool::findActiveByIdForUser', $bloc);
        $this->assertStringContainsString('GammaService::fromTool', $bloc);
        $this->assertStringContainsString('createGeneration', $bloc);
        $this->assertStringContainsString("JobQueue::enqueue('gamma'", $bloc);
    }

    /**
     * Le job de suivi doit être branché dans la file : un handler absent fait
     * échouer tous les jobs `gamma` en « type inconnu », et sans exemption de
     * quota un utilisateur à quota MIA atteint perdrait le lien d'une
     * présentation pourtant déjà facturée chez Gamma.
     */
    public function testLeJobDeSuiviEstBrancheDansLaFile(): void
    {
        $queue = (string)file_get_contents(ROOT . '/src/Jobs/JobQueue.php');
        $this->assertMatchesRegularExpression("/'gamma'\s*=>\s*new GammaGenerationJob\(\)/", $queue,
            'JobQueue::handlerFor ne connaît pas le type gamma.');
        $this->assertStringContainsString('QUOTA_EXEMPT', $queue);
        $this->assertMatchesRegularExpression('/QUOTA_EXEMPT\s*=\s*\[[^\]]*\'gamma\'/', $queue,
            'Le job gamma doit être exempté du quota MIA (crédits déjà consommés chez Gamma).');
        // Le poll est un GET rejouable : le reaper a le DROIT de le relancer.
        $this->assertDoesNotMatchRegularExpression('/NON_IDEMPOTENT\s*=\s*\[[^\]]*\'gamma\'/', $queue,
            'Le suivi gamma est idempotent, il n\'a rien à faire dans NON_IDEMPOTENT.');
    }
}
