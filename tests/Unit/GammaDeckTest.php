<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * « Créer avec Gamma » sur la carte d'une présentation livrée dans le chat.
 *
 * Trois étages :
 *   1. la présentation en CARTES de texte (MarkdownDeck::sourceCards) — même
 *      découpage que le .pptx de MIA, Markdown d'origine gardé, notes retirées ;
 *   2. la requête Gamma (GammaService::deckPayload) — texte préservé, une
 *      carte par slide, plafond de l'administrateur, export PowerPoint ;
 *   3. le câblage : route, contrôleur (outil re-choisi au clic, une seule
 *      génération pour deux clics), page, carte du fichier, documentation.
 */
final class GammaDeckTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    // ------------------------------------------------------------------
    // 1. Cartes
    // ------------------------------------------------------------------

    public function testUneCarteParSlideDeLaConvention(): void
    {
        $deck = MarkdownDeck::sourceCards(
            "# Bilan 2026\n\nUne année record.\n\n## Contexte\n\n- Marché en hausse\n- Deux pays\n\n---\n\n## Résultats\n\nTout va bien.",
            'plan'
        );

        $this->assertSame('Bilan 2026', $deck['title']);
        $this->assertSame([
            "# Bilan 2026\n\nUne année record.",
            "## Contexte\n\n- Marché en hausse\n- Deux pays",
            "## Résultats\n\nTout va bien.",
        ], $deck['cards']);
    }

    public function testLeMarkdownDOrigineEstGarde(): void
    {
        $source = "## Offre\n\nUne **remise** de [10 %](https://exemple.fr) :\n\n| Pack | Prix |\n|---|---|\n| A | 10 € |\n\n- point\n  - sous-point";
        $deck   = MarkdownDeck::sourceCards($source);

        $this->assertSame([$source], $deck['cards'], 'Emphase, lien, tableau et liste imbriquée doivent arriver intacts.');
    }

    public function testLesNotesDuPresentateurSontRetirees(): void
    {
        $deck = MarkdownDeck::sourceCards(
            "## Un\n\nTexte.\n\nNotes : rappeler le budget.\n\n## Deux\n\n**Notes :** insister.\n\nSuite.\n\n## Trois\n\nSpeaker notes: smile.\n\nNote : remarque visible."
        );

        $this->assertSame([
            "## Un\n\nTexte.",
            "## Deux\n\n\nSuite.",
            "## Trois\n\n\nNote : remarque visible.",
        ], $deck['cards']);
        foreach ($deck['cards'] as $card) {
            $this->assertStringNotContainsString('budget', $card);
            $this->assertStringNotContainsString('insister', $card);
            $this->assertStringNotContainsString('smile', $card);
        }
    }

    public function testUneSectionDeNotesSeulesNeFaitPasDeCarte(): void
    {
        $deck = MarkdownDeck::sourceCards("## Un\n\nTexte.\n\n---\n\nNotes : rien à montrer.\n\n## Deux\n\nFin.");

        $this->assertSame(["## Un\n\nTexte.", "## Deux\n\nFin."], $deck['cards']);
    }

    public function testUnBlocDeCodeNEstJamaisCoupe(): void
    {
        $deck = MarkdownDeck::sourceCards("## Code\n\n```yaml\n## pas un titre\n---\nclé: valeur\n```\n\n## Suite\n\nTexte.");

        $this->assertCount(2, $deck['cards']);
        $this->assertStringContainsString("## pas un titre\n---\nclé: valeur", $deck['cards'][0]);
    }

    public function testUnTitreSouligneOuvreUneCarteEcriteEnDiese(): void
    {
        $deck = MarkdownDeck::sourceCards("## Un\n\nTexte.\n\nDeux\n---\n\nSuite.");

        // Réécrit en `##` : chez Gamma, un soulignement `---` passerait pour un séparateur.
        $this->assertSame(["## Un\n\nTexte.", "## Deux\n\nSuite."], $deck['cards']);
    }

    public function testDesTitresDePremierNiveauSeulsFontChacunUneCarte(): void
    {
        $this->assertSame(
            ["# Un\n\n- a\n- b", "# Deux\n\n- c"],
            MarkdownDeck::sourceCards("# Un\n\n- a\n- b\n\n# Deux\n\n- c")['cards']
        );
        $this->assertSame(
            ["### Un\n\nx", "### Deux\n\ny"],
            MarkdownDeck::sourceCards("### Un\n\nx\n\n### Deux\n\ny")['cards']
        );
    }

    /**
     * Même découpage que le .pptx de MIA : tant qu'aucune slide ne déborde
     * (MIA la redécouperait en « (suite) », Gamma met en page lui-même), il y
     * a autant de cartes Gamma que de slides MIA.
     */
    public function testAutantDeCartesQueDeSlidesDansLeFichierPowerPoint(): void
    {
        $decks = [
            "# Titre\n\nSous-titre\n\n## A\n\n- 1\n- 2\n\n## B\n\n> Une citation\n\n— Auteur\n\n## C",
            "# Un\n\n- a\n\n# Deux\n\n- b\n\n# Trois\n\n| x | y |\n|---|---|\n| 1 | 2 |",
            "## A\n\nTexte.\n\n---\n\n## B\n\n1. un\n2. deux\n3. trois\n\nNotes : note.\n\n## C\n\n### Gauche\n- g\n### Droite\n- d",
            "Intro sans titre.\n\n---\n\nSuite sans titre.",
        ];
        foreach ($decks as $md) {
            $slides = MarkdownDeck::outline(MarkdownDocument::parse($md))['slides'];
            $this->assertCount(count($slides), MarkdownDeck::sourceCards($md)['cards'], $md);
        }
    }

    public function testLeTitreEstCeluiDuFichierPowerPoint(): void
    {
        // Même règle que le .pptx : le premier `#`, sinon le premier titre, sinon le nom du fichier.
        $this->assertSame('B', MarkdownDeck::sourceCards("## A\n\nx\n\n# B", 'plan')['title']);
        $this->assertSame('A', MarkdownDeck::sourceCards("## A\n\nx", 'plan')['title']);
        $this->assertSame('plan-commercial', MarkdownDeck::sourceCards("Texte seul.", 'plan-commercial')['title']);
    }

    // ------------------------------------------------------------------
    // 2. Requête Gamma
    // ------------------------------------------------------------------

    public function testLaRequeteGardeLeTexteEtCoupeAuxSlides(): void
    {
        $payload = GammaService::deckPayload([], ['# Titre', '## A', '## B'], 'Titre', 'fr');

        $this->assertSame("# Titre\n---\n## A\n---\n## B", $payload['inputText']);
        $this->assertSame('preserve', $payload['textMode']);
        $this->assertSame('presentation', $payload['format']);
        $this->assertSame('inputTextBreaks', $payload['cardSplit']);
        $this->assertSame('Titre', $payload['title']);
        $this->assertSame(['dimensions' => '16x9'], $payload['cardOptions']);
        $this->assertSame(['language' => 'fr'], $payload['textOptions']);
        $this->assertSame(['source' => 'aiGenerated'], $payload['imageOptions']);
        $this->assertArrayNotHasKey('numCards', $payload, 'Ignoré avec inputTextBreaks : ne pas l\'envoyer.');
        foreach ($payload as $key => $value) {
            $this->assertNotSame('', $value, "Gamma répond 400 sur un champ vide ($key).");
        }
    }

    public function testLExportEstUnPowerPointSaufChoixDeLAdministrateur(): void
    {
        $this->assertSame('pptx', GammaService::deckPayload([], ['## A'], '')['exportAs']);
        $this->assertSame('pptx', GammaService::deckPayload(['export_as' => ''], ['## A'], '')['exportAs']);
        $this->assertSame('pdf', GammaService::deckPayload(['export_as' => 'pdf'], ['## A'], '')['exportAs']);
    }

    public function testLaConfigurationDeLOutilSApplique(): void
    {
        $payload = GammaService::deckPayload([
            'theme_id'             => 'theme-maison',
            'image_source'         => 'noImages',
            'default_instructions' => 'Ton sobre.',
        ], ['## A'], '');

        $this->assertSame('theme-maison', $payload['themeId']);
        $this->assertSame(['source' => 'noImages'], $payload['imageOptions']);
        $this->assertSame('Ton sobre.', $payload['additionalInstructions']);
        $this->assertArrayNotHasKey('title', $payload);
        $this->assertArrayNotHasKey('textOptions', $payload);
    }

    public function testUnSeparateurResteDansUneCarteNeLaCoupePas(): void
    {
        $payload = GammaService::deckPayload([], ["## Code\n```\na\n---\nb\n```", '## Fin'], '');

        $this->assertSame(1, substr_count($payload['inputText'], GammaService::CARD_BREAK));
        $this->assertStringContainsString("a\n- - -\nb", $payload['inputText']);
    }

    public function testUnePresentationVideEstRefusee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vide');
        GammaService::deckPayload([], ['', "  \n "], '');
    }

    public function testLePlafondDeLAdministrateurRefuseSansCouper(): void
    {
        $cartes = array_map(static fn (int $i): string => '## Slide ' . $i, range(1, 6));
        GammaService::deckPayload(['max_cards' => 6], $cartes, ''); // au plafond : accepté

        try {
            GammaService::deckPayload(['max_cards' => 5], $cartes, '');
            $this->fail('Une présentation plus longue que le plafond doit être refusée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('6 slides', $e->getMessage());
            $this->assertStringContainsString('5 cartes', $e->getMessage());
        }

        // Plafond par défaut (20) quand l'administrateur n'en a pas fixé.
        $this->expectException(\RuntimeException::class);
        GammaService::deckPayload([], array_map(static fn (int $i): string => '## ' . $i, range(1, 21)), '');
    }

    public function testUnTexteTropLongEstRefuse(): void
    {
        $this->expectException(\RuntimeException::class);
        GammaService::deckPayload([], [str_repeat('x', GammaService::MAX_INPUT_CHARS + 1)], '');
    }

    public function testLeRefusEstTraduit(): void
    {
        I18n::use('en');
        try {
            GammaService::deckPayload(['max_cards' => 1], ['## A', '## B'], '');
            $this->fail('Refus attendu.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('This presentation has 2 slides', $e->getMessage());
        } finally {
            I18n::use('fr');
        }
    }

    public function testLaLangueDeSortieSuitLInterface(): void
    {
        $this->assertSame('fr', GammaService::language('fr'));
        $this->assertSame('en', GammaService::language('en'));
        $this->assertSame('en', GammaService::language('en-GB'));
        $this->assertSame('', GammaService::language('de'));

        // L'outil de l'assistant aussi : sans langue, Gamma écrit en anglais.
        $avec = GammaService::buildGenerationPayload([], ['sujet' => 'Plan'], 'fr');
        $this->assertSame(['language' => 'fr'], $avec['textOptions']);
        $sans = GammaService::buildGenerationPayload([], ['sujet' => 'Plan']);
        $this->assertArrayNotHasKey('textOptions', $sans);
    }

    public function testLOutilDeLAssistantPasseAvantCeluiDesGroupes(): void
    {
        $outil = static fn (int $id, array $extra = []): array => $extra + [
            'id' => $id, 'type' => 'gamma', 'is_active' => 1, 'last_test_ok' => 1, 'api_key_enc' => 'chiffre',
        ];

        $this->assertSame(7, GammaService::pickTool([$outil(7)], [$outil(3), $outil(7)])['id']);
        $this->assertSame(3, GammaService::pickTool([], [$outil(3), $outil(7)])['id']);
        // Test échoué, clé absente, inactif, autre type : jamais retenus.
        $this->assertSame(9, GammaService::pickTool(
            [$outil(1, ['last_test_ok' => 0]), $outil(2, ['api_key_enc' => ''])],
            [$outil(4, ['is_active' => 0]), $outil(5, ['type' => 'n8n']), $outil(9, ['last_test_ok' => null])]
        )['id']);
        $this->assertNull(GammaService::pickTool([], [$outil(1, ['last_test_ok' => 0])]));
    }

    public function testLeMessageDeLancementEstPartage(): void
    {
        $message = GammaService::launchedMessage('Bilan');
        $this->assertStringStartsWith('⏳ ', $message);
        $this->assertStringContainsString('« Bilan »', $message);

        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($chat, 'GammaService::launchedMessage('),
            'La carte approuvée et le bouton d\'un fichier annoncent le lancement avec le même message.');
        $this->assertStringContainsString('GammaService::language(I18n::locale())', $chat);
    }

    // ------------------------------------------------------------------
    // 3. Câblage
    // ------------------------------------------------------------------

    public function testLaRouteEstDeclaree(): void
    {
        $index = (string)file_get_contents(ROOT . '/public/index.php');
        $this->assertStringContainsString("\$router->post('/chat/{id}/gamma-deck',", $index);
        $this->assertStringContainsString("[ChatController::class, 'gammaFromFile']", $index);
    }

    public function testLeControleurReChoisitLOutilEtNeLancePasDeuxFois(): void
    {
        $chat  = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $debut = strpos($chat, 'public function gammaFromFile');
        $this->assertNotFalse($debut);
        $fin   = strpos($chat, 'private function gammaToolFor', (int)$debut);
        $bloc  = substr($chat, (int)$debut, (int)$fin - (int)$debut);

        $ordre = [
            'Conversation::belongsToUser',
            'session_write_close()',
            'FileFormats::isPresentation',
            '$this->gammaToolFor(',
            'MarkdownDeck::sourceCards(',
            'GammaService::deckPayload(',
            'JobQueue::activeByDedupKey(',
            'GET_LOCK',
            '->createGeneration($payload)',
            'GammaService::launchedMessage(',
            "JobQueue::enqueue('gamma'",
            'RELEASE_LOCK',
            'JobQueue::respondAndDispatch(',
        ];
        $position = 0;
        foreach ($ordre as $etape) {
            $trouve = strpos($bloc, $etape, $position);
            $this->assertNotFalse($trouve, "Étape absente ou hors d'ordre : $etape");
            $position = (int)$trouve;
        }
        $this->assertStringContainsString('$dedupKey);', $bloc, 'Le job de suivi porte la clé de dédoublonnage.');
        // L'outil n'est jamais désigné par la page.
        $this->assertStringNotContainsString("\$_POST['tool_id']", $bloc);

        $outil = substr($chat, (int)$fin, 900);
        $this->assertStringContainsString('ChatTool::activeForProfile', $outil);
        $this->assertStringContainsString('ChatTool::activeByUser', $outil);
        $this->assertStringContainsString('GammaService::pickTool', $outil);
    }

    public function testLaPageNeMontreLeBoutonQueSiGammaEstOuvert(): void
    {
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($chat, '$gammaForFiles = $this->gammaToolFor($user, 0) !== null;'),
            'index() et show() posent le drapeau du bouton, avec la règle du clic.');

        $page = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString("window.FILE_GAMMA = <?= !empty(\$gammaForFiles) ? 'true' : 'false' ?>;", $page);
    }

    public function testLaCarteDuFichierPorteLeBouton(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/generated-files.js');
        $this->assertStringContainsString('root.FILE_GAMMA === true && offersGamma(file)', $js);
        $this->assertStringContainsString("'/gamma-deck'", $js);
        $this->assertStringContainsString("if (item.dataset.act === 'gamma') {", $js, 'Le menu « Version soignée » propose Gamma.');
        $this->assertStringContainsString('createWithGamma(card, button);', $js);
        $this->assertStringContainsString('root.confirm(', $js, 'Externe et payant : une confirmation avant l\'envoi.');
        $this->assertStringContainsString('root.attachJob(', $js);
        $this->assertStringContainsString('root.showDriveOutcome(data)', $js);
        $this->assertStringContainsString("data.code === 'csrf' && !retried", $js);

        $css = (string)file_get_contents(ROOT . '/public/assets/css/files.css');
        $this->assertMatchesRegularExpression('/\.file-polish,(\s*\.file-[a-z-]+,?)*\s*\{/', $css, 'Style du bouton (partagé avec les autres boutons de présentation).');
        $this->assertStringContainsString('.file-card-status.is-info', $css);
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\)\s*\{[^}]*\.file-polish[^}]*44px/s', $css,
            'Cible tactile de 44 px, comme les autres boutons de la carte.');
    }

    /** Sous node : quelles cartes proposent Gamma (partie pure du module). */
    public function testSeulesLesPresentationsEnMarkdownProposentGamma(): void
    {
        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $cas = [
            ['plan.pptx', 'markdown', true],
            ['plan.pptx', '', true],
            ['plan.key', 'markdown', true],
            ['plan.ppt', '', true],
            ['plan.pptx', 'text', false],
            ['rapport.docx', 'markdown', false],
            ['notes.md', 'markdown', false],
            ['budget.xlsx', 'csv', false],
        ];
        $script = "'use strict';\n"
            . 'globalThis.FILE_FORMATS = ' . json_encode(FileFormats::clientCatalog()) . ";\n"
            . "globalThis.t = (s) => s;\nglobalThis.tn = (n, un, plusieurs) => (n > 1 ? plusieurs : un);\n"
            . (string)file_get_contents(ROOT . '/public/assets/js/generated-files.js') . "\n"
            . 'const CAS = ' . json_encode($cas) . ";\n"
            . "console.log(JSON.stringify(CAS.map(c => globalThis.MiaFiles.offersGamma(globalThis.MiaFiles.describe(c[0], c[1])))));\n";

        $fichier = tempnam(sys_get_temp_dir(), 'mia-gamma-js-');
        file_put_contents($fichier, $script);
        try {
            $sortie = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier) . ' 2>&1');
        } finally {
            @unlink($fichier);
        }

        $this->assertSame(array_column($cas, 2), json_decode((string)$sortie, true), (string)$sortie);
    }

    public function testLesTextesSontTraduits(): void
    {
        $serveur = (array)(require ROOT . '/lang/en.php');
        $client  = (array)(require ROOT . '/lang/en.client.php');
        foreach ([
            'Cette présentation est vide : rien à envoyer à Gamma.',
            'Cette présentation est trop longue pour Gamma : raccourcissez-la.',
            'Seule une présentation peut être créée avec Gamma.',
            'Gamma n’est pas proposé pour votre compte : un administrateur doit vous ouvrir un outil Gamma.',
        ] as $cle) {
            $this->assertArrayHasKey($cle, $serveur);
        }
        foreach (['Par Gamma', 'Créer cette présentation avec Gamma ?', 'Envoi à Gamma…', 'Version soignée'] as $cle) {
            $this->assertArrayHasKey($cle, $client);
        }
    }

    public function testLaDocumentationEnParle(): void
    {
        $nouveautes = (string)file_get_contents(ROOT . '/docs/NOUVEAUTES.md');
        $this->assertStringContainsString('<!-- id: presentation-chat-gamma |', $nouveautes);

        // Gamma se choisit dans le bouton « Version soignée » de la carte (« Par Gamma »).
        $page = (string)file_get_contents(ROOT . '/docs/user/fonctionnalites/conversations/telecharger-un-fichier.md');
        $this->assertStringContainsString('Par Gamma', $page);
        $admin = (string)file_get_contents(ROOT . '/docs/user/admin/outils/ajouter-un-outil.md');
        $this->assertStringContainsString('Par Gamma', $admin);
        $this->assertStringContainsString('Par Gamma', (string)file_get_contents(ROOT . '/docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('Par Gamma', (string)file_get_contents(ROOT . '/docs/GUIDE-ADMIN.md'));
        foreach (['docs/user/fonctionnalites/conversations/telecharger-un-fichier.md', 'docs/GUIDE-UTILISATEUR.md', 'docs/GUIDE-ADMIN.md'] as $doc) {
            $this->assertStringNotContainsString('Créer avec Gamma', (string)file_get_contents(ROOT . '/' . $doc), "$doc : l'ancien bouton n'existe plus.");
        }
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
}
