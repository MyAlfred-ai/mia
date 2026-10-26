<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mise en forme des Google Docs (DocsFormat) : conversion Markdown → requêtes
 * batchUpdate, et stylage de texte existant (outil mettre_en_forme_google_doc).
 *
 * LE piège central, testé partout ici : les index de l'API Docs se comptent en
 * unités UTF-16 (un emoji en vaut DEUX). Un offset compté en caractères
 * (mb_strlen) décalerait chaque style d'un cran après le premier emoji — et le
 * décalage s'accumulerait sur tout le document.
 *
 * DocsFormat est entièrement pure (aucun réseau) : les conversions se testent
 * sur leurs valeurs exactes, le câblage chat par vérification de source.
 */
final class DocsFormatTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // UTF-16
    // ---------------------------------------------------------------------

    public function testLesLongueursSontEnUnitesUtf16(): void
    {
        $this->assertSame(3, DocsFormat::utf16Len('abc'));
        $this->assertSame(1, DocsFormat::utf16Len('é'));
        $this->assertSame(2, DocsFormat::utf16Len('😀'), 'Un emoji = paire de substituts = DEUX unités UTF-16.');
        $this->assertSame(0, DocsFormat::utf16Len(''));
    }

    // ---------------------------------------------------------------------
    // Markdown → requêtes
    // ---------------------------------------------------------------------

    public function testConversionComplete(): void
    {
        $conv = DocsFormat::convertir("## Titre\nTexte **gras** ici\n- un\n- deux", 1);

        // Marqueurs retirés du texte inséré, pas de \n final (le corps du
        // document fournit déjà son saut de ligne terminal).
        $this->assertSame("Titre\nTexte gras ici\nun\ndeux", $conv['texte']);

        $parStyles = array_values(array_filter($conv['requetes'], fn($r) => isset($r['updateParagraphStyle'])));
        $this->assertCount(4, $parStyles, 'Chaque paragraphe reçoit un style EXPLICITE (une insertion hérite du style au point d\'insertion).');
        $this->assertSame('HEADING_2', $parStyles[0]['updateParagraphStyle']['paragraphStyle']['namedStyleType']);
        $this->assertSame(['startIndex' => 1, 'endIndex' => 6], $parStyles[0]['updateParagraphStyle']['range']);
        $this->assertSame('NORMAL_TEXT', $parStyles[1]['updateParagraphStyle']['paragraphStyle']['namedStyleType']);

        $puces = array_values(array_filter($conv['requetes'], fn($r) => isset($r['createParagraphBullets'])));
        $this->assertCount(1, $puces, 'Deux items contigus = UN bloc de puces.');
        $this->assertSame(['startIndex' => 22, 'endIndex' => 29], $puces[0]['createParagraphBullets']['range']);
        $this->assertSame('BULLET_DISC_CIRCLE_SQUARE', $puces[0]['createParagraphBullets']['bulletPreset']);

        $texteStyles = array_values(array_filter($conv['requetes'], fn($r) => isset($r['updateTextStyle'])));
        $this->assertCount(1, $texteStyles);
        $this->assertSame(['startIndex' => 13, 'endIndex' => 17], $texteStyles[0]['updateTextStyle']['range'],
            '« gras » dans « Texte gras ici » : base 1 + paragraphe 6 + offset 6.');
        $this->assertTrue($texteStyles[0]['updateTextStyle']['textStyle']['bold']);
    }

    public function testUnEmojiDecaleLesIndexDeDeuxUnites(): void
    {
        $conv = DocsFormat::convertir('😀 **ok**', 10);
        $this->assertSame('😀 ok', $conv['texte']);
        $style = array_values(array_filter($conv['requetes'], fn($r) => isset($r['updateTextStyle'])))[0];
        // 😀(2) + espace(1) = 3 → gras sur [13,15], PAS [12,14].
        $this->assertSame(['startIndex' => 13, 'endIndex' => 15], $style['updateTextStyle']['range']);
    }

    public function testInlineImbriqueEtGardesDItalique(): void
    {
        $p = DocsFormat::parseInline('**gras *italique*** et `code` et [lien](https://ex.fr)');
        $this->assertSame('gras italique et code et lien', $p['texte']);

        $styles = array_map(fn($s) => $s[2], $p['spans']);
        $this->assertContains(['italique' => true], $styles, 'Italique interne au gras : span propre.');
        $this->assertContains(['gras' => true], $styles, 'Gras englobant : les deux spans se chevauchent, l\'API cumule.');
        $this->assertContains(['mono' => true], $styles);
        $this->assertContains(['lien' => 'https://ex.fr'], $styles);

        // snake_case et produits `2*3*4` ne sont pas des emphases.
        $brut = DocsFormat::parseInline('la variable user_drive_tokens vaut 2*3*4');
        $this->assertSame([], $brut['spans']);
        $this->assertSame('la variable user_drive_tokens vaut 2*3*4', $brut['texte']);
    }

    public function testBlocsDeCodeSansInterpretationEtCitationEnItalique(): void
    {
        $conv = DocsFormat::convertir("```php\n\$a = '**pas gras**';\n```\n> citation", 1);
        $this->assertSame("\$a = '**pas gras**';\ncitation", $conv['texte'],
            'Fences retirés (langue comprise), contenu du bloc laissé BRUT, marqueur de citation retiré.');
        $texteStyles = array_values(array_filter($conv['requetes'], fn($r) => isset($r['updateTextStyle'])));
        $this->assertCount(2, $texteStyles);
        $this->assertSame('Courier New', $texteStyles[0]['updateTextStyle']['textStyle']['weightedFontFamily']['fontFamily']);
        $this->assertTrue($texteStyles[1]['updateTextStyle']['textStyle']['italic']);
    }

    public function testTexteSansMarkdownResteIntact(): void
    {
        $conv = DocsFormat::convertir("Bonjour.\nDeux lignes simples.", 1);
        $this->assertSame("Bonjour.\nDeux lignes simples.", $conv['texte']);
        $this->assertSame([], array_filter($conv['requetes'], fn($r) => isset($r['updateTextStyle'])));
        $this->assertSame('', DocsFormat::convertir("\n \n", 1)['texte']);
    }

    // ---------------------------------------------------------------------
    // Arguments de l'outil de mise en forme
    // ---------------------------------------------------------------------

    public function testStyleDepuisArgsNormaliseLesBooleensTexte(): void
    {
        // Les modèles envoient "true"/"false" en chaînes.
        $style = DocsFormat::styleDepuisArgs(['gras' => 'true', 'couleur' => 'rouge', 'taille' => '14']);
        $this->assertTrue($style['gras']);
        $this->assertSame(14, $style['taille']);
        $this->assertEqualsWithDelta(0.7529, $style['couleur']['red'], 0.001);

        $this->expectException(RuntimeException::class); // aucun style = appel inutile
        DocsFormat::styleDepuisArgs(['fichier' => 'x', 'rechercher' => 'y']);
    }

    public function testCouleursEtTaillesInvalidesSontRefusees(): void
    {
        $this->assertSame(['red' => 0.0, 'green' => 0.0, 'blue' => 1.0], DocsFormat::parseCouleur('#0000FF'));
        $this->assertNotEmpty(DocsFormat::parseCouleur('BLEU'), 'Nom FR insensible à la casse.');
        try {
            DocsFormat::parseCouleur('turquoise-pailleté');
            $this->fail('Couleur inconnue acceptée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rouge', $e->getMessage(), 'Le message liste les noms acceptés.');
        }
        $this->expectException(RuntimeException::class);
        DocsFormat::styleDepuisArgs(['taille' => 400]);
    }

    public function testStyleParagrapheInconnuRefuse(): void
    {
        $this->assertSame('titre_2', DocsFormat::styleDepuisArgs(['style_paragraphe' => 'titre_2'])['style_paragraphe']);
        $this->expectException(RuntimeException::class);
        DocsFormat::styleDepuisArgs(['style_paragraphe' => 'titre_9']);
    }

    // ---------------------------------------------------------------------
    // Localisation de texte existant
    // ---------------------------------------------------------------------

    private static function paragraphe(int $debut, int $fin, array $runs): array
    {
        return ['startIndex' => $debut, 'endIndex' => $fin, 'paragraph' => ['elements' => array_map(
            fn(array $r) => ['startIndex' => $r[0], 'textRun' => ['content' => $r[1]]],
            $runs
        )]];
    }

    public function testLocaliseATraversLesRunsScindes(): void
    {
        // Une mise en forme existante SCINDE les runs : l'occurrence peut
        // chevaucher la frontière.
        $contenu = [self::paragraphe(1, 21, [[1, 'Bonjour '], [9, "MONDE cruel\n"]])];
        $loc = DocsFormat::localiserOccurrences($contenu, 'jour mon');
        $this->assertSame([[4, 12]], $loc['occurrences'], 'Recherche insensible à la casse, à cheval sur deux runs.');
        $this->assertSame([[1, 21]], $loc['paragraphes']);
    }

    public function testUnEmojiEnAmontDecaleLesOccurrences(): void
    {
        $contenu = [self::paragraphe(5, 12, [[5, "a😀b\n"]])];
        $loc = DocsFormat::localiserOccurrences($contenu, 'b');
        $this->assertSame([[8, 9]], $loc['occurrences'], 'a(5) 😀(6–7, DEUX unités) b(8).');
    }

    public function testOccurrencesSansChevauchementEtIntrouvable(): void
    {
        $contenu = [self::paragraphe(1, 8, [[1, "aaaa\n"]])];
        $this->assertCount(2, DocsFormat::localiserOccurrences($contenu, 'aa')['occurrences']);
        $this->assertSame([], DocsFormat::localiserOccurrences($contenu, 'zz')['occurrences']);
        $this->assertSame([], DocsFormat::localiserOccurrences($contenu, '')['occurrences']);
    }

    // ---------------------------------------------------------------------
    // Câblage chat
    // ---------------------------------------------------------------------

    public function testLOutilEstExposeGardeEtExecutable(): void
    {
        $src = AgentSource::chat();
        $this->assertStringContainsString("'name'        => 'mettre_en_forme_google_doc'", $src);
        $this->assertStringContainsString("case 'mettre_en_forme_google_doc':", $src, 'Pas exécutable après approbation.');
        $this->assertStringContainsString("\$queueConnectorWrite('mettre_en_forme_google_doc'", $src,
            'La mise en forme passe par la file d\'approbation, jamais exécutée pendant le stream.');
        $this->assertArrayHasKey('mettre_en_forme_google_doc', ChatController::ACTION_LABELS);
        $this->assertStringContainsString("'phase' => 'drive_format'", $src);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('drive_format:', $js, 'Une phase sans libellé front n\'affiche rien.');
    }

    public function testLesEcrituresDocsPassentParLaConversionMarkdown(): void
    {
        $srv = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $this->assertSame(
            3,
            substr_count($srv, 'DocsFormat::convertir('),
            'createDoc + updateDoc ajouter + remplacer_tout : les trois écritures de contenu rendent le Markdown. '
            . '(remplacer_texte reste brut : replaceAllText ne porte aucun style.)'
        );
        $this->assertStringContainsString('docsBatchUpdate', $srv);

        // La guidance et les définitions annoncent le Markdown rendu — sans
        // ça, le modèle continue d'écrire du texte plat « par prudence ».
        $ctrl = AgentSource::chat();
        $this->assertStringContainsString('MARKDOWN RENDU', $ctrl);
    }

    // ---------------------------------------------------------------------
    // Puces héritées (« des puces partout »)
    // ---------------------------------------------------------------------

    public function testLesPucesHeriteesSontRetireesAvantToutAutreStyle(): void
    {
        $conv = DocsFormat::convertir("# Titre\nUn paragraphe\n- item", 5);

        $types = array_map(fn($r) => array_key_first($r), $conv['requetes']);
        $this->assertSame('deleteParagraphBullets', $types[0],
            'Un paragraphe créé par insertText HÉRITE de la liste du point d\'insertion : '
            . 'sans ce retrait EN PREMIER, tout le texte inséré arrive à puces.');

        // Toute la plage insérée, pas seulement les paragraphes de liste.
        $this->assertSame(
            ['startIndex' => 5, 'endIndex' => 5 + DocsFormat::utf16Len($conv['texte'])],
            $conv['requetes'][0]['deleteParagraphBullets']['range']
        );

        // …et le createParagraphBullets des VRAIES listes vient après, sinon
        // le retrait effacerait les puces qu'on vient de poser.
        $iDelete = array_search('deleteParagraphBullets', $types, true);
        $iCreate = array_search('createParagraphBullets', $types, true);
        $this->assertIsInt($iCreate);
        $this->assertLessThan($iCreate, $iDelete);
    }

    public function testLIndentationLaisseeParLeRetraitDePucesEstRemiseAZero(): void
    {
        $conv = DocsFormat::convertir("Texte\n- item", 1);
        $par  = array_values(array_filter($conv['requetes'], fn($r) => isset($r['updateParagraphStyle'])));

        // deleteParagraphBullets « préserve visuellement » l'imbrication en
        // posant une indentation : un paragraphe normal la garderait.
        $this->assertStringContainsString('indentStart', $par[0]['updateParagraphStyle']['fields']);
        $this->assertSame(
            ['magnitude' => 0, 'unit' => 'PT'],
            $par[0]['updateParagraphStyle']['paragraphStyle']['indentStart']
        );

        // Un paragraphe de liste, lui, est indenté par createParagraphBullets.
        $this->assertSame('namedStyleType', $par[1]['updateParagraphStyle']['fields'],
            'Toucher à l\'indentation d\'un item de liste concurrencerait createParagraphBullets.');
    }

    public function testAucuneRequeteDePucesSurUnTexteVide(): void
    {
        $conv = DocsFormat::convertir("\n\n", 1);
        $this->assertSame('', $conv['texte']);
        $this->assertSame([], $conv['requetes'], 'Une plage vide serait refusée par l\'API.');
    }

    public function testDocsFormatEstChargePartout(): void
    {
        // Pas d'autoloader : une classe absente d'une des DEUX listes ne
        // planterait que dans le worker, en production.
        $this->assertStringContainsString("/src/Drive/DocsFormat.php", (string)file_get_contents(ROOT . '/config/classes.php'));
        $this->assertStringContainsString("/src/Drive/DocsFormat.php", (string)file_get_contents(ROOT . '/tests/bootstrap.php'));
    }
}
