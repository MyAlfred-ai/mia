<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mise en page (LayoutEngine) et rendu HTML (DeckRenderer) d'une présentation.
 *
 * Ces deux classes garantissent qu'une sortie de LLM approximative produit
 * malgré tout des slides bien composées et lisibles.
 */
final class DeckRenderingTest extends TestCase
{
    /** @return array<string,mixed> */
    private function item(string $title, array $points, string $layout, string $imagePrompt = ''): array
    {
        return DeckSpec::normalizeOutline([[
            'id' => 's1', 'title' => $title, 'points' => $points,
            'layout' => $layout, 'image_prompt' => $imagePrompt,
        ]])[0];
    }

    private function elementTypes(array $slide): array
    {
        return array_column($slide['elements'], 'type');
    }

    // --------------------------------------------------------- mise en page

    public function testTousLesLayoutsProduisentDesElements(): void
    {
        foreach (DeckSpec::LAYOUTS as $layout) {
            $slide = LayoutEngine::build(
                $this->item('Titre', ['un point', 'deux points', 'trois points', 'quatre points'], $layout),
                1,
                8,
                false
            );
            $this->assertNotEmpty($slide['elements'], "layout {$layout}");
        }
    }

    public function testGeometrieToujoursDansLeCanvas(): void
    {
        foreach (DeckSpec::LAYOUTS as $layout) {
            $slide = LayoutEngine::build(
                $this->item('Titre', ['a', 'b', 'c', 'd'], $layout, 'un visuel'),
                2,
                8,
                true
            );
            foreach ($slide['elements'] as $el) {
                $this->assertGreaterThanOrEqual(0.0, $el['x'], "layout {$layout}");
                $this->assertGreaterThanOrEqual(0.0, $el['y'], "layout {$layout}");
                $this->assertLessThanOrEqual(100.01, $el['x'] + $el['w'], "layout {$layout}");
                $this->assertLessThanOrEqual(100.01, $el['y'] + $el['h'], "layout {$layout}");
            }
        }
    }

    /** @return array<string, array<string, mixed>> données de chaque mise en page supplémentaire */
    private static function extraItems(): array
    {
        return [
            'chiffres'    => ['kpis' => [['value' => '4,2 M€', 'label' => 'CA'], ['value' => '+18 %', 'label' => 'croissance'], ['value' => '320', 'label' => 'clients']]],
            'tableau'     => ['table' => ['header' => true, 'rows' => [['Région', 'CA'], ['Ouest', '1 M€']]], 'intro' => 'Par région.'],
            'comparaison' => ['columns' => [['title' => 'Avant', 'points' => ['a']], ['title' => 'Après', 'points' => ['b']]]],
            'etapes'      => ['steps' => ['Écouter', 'Prototyper', 'Déployer']],
            'chronologie' => ['events' => [['date' => '2019', 'label' => 'Création'], ['date' => '2024', 'label' => 'Lyon']]],
        ];
    }

    public function testMisesEnPageSupplementairesDansLeCanvas(): void
    {
        foreach (self::extraItems() as $layout => $data) {
            $slide = LayoutEngine::build(['id' => 's2', 'title' => 'Titre', 'points' => [], 'layout' => $layout] + $data, 1, 6, false);

            $this->assertSame($layout, $slide['layout']);
            $this->assertGreaterThan(2, count($slide['elements']), $layout);
            foreach ($slide['elements'] as $el) {
                $this->assertGreaterThanOrEqual(0.0, $el['x'], $layout);
                $this->assertGreaterThanOrEqual(0.0, $el['y'], $layout);
                $this->assertLessThanOrEqual(100.01, $el['x'] + $el['w'], $layout);
                $this->assertLessThanOrEqual(100.01, $el['y'] + $el['h'], $layout);
            }
            $this->assertCount(count($slide['elements']), DeckSpec::normalizeSlide($slide, 1)['elements'], "$layout : rien de perdu à la normalisation.");
        }
    }

    /** Sans ses données, une mise en page supplémentaire retombe sur le cas courant. */
    public function testMiseEnPageSupplementaireSansDonnees(): void
    {
        foreach (array_keys(self::extraItems()) as $layout) {
            $slide = LayoutEngine::build(['id' => 's2', 'title' => 'Titre', 'points' => ['un point'], 'layout' => $layout], 1, 6, false);
            $this->assertSame('titre-contenu', $slide['layout'], $layout);
        }
    }

    public function testTableauRenduEtEchappe(): void
    {
        $html = DeckRenderer::render(DeckSpec::normalize(['slides' => [['elements' => [
            ['type' => 'table', 'x' => 6, 'y' => 20, 'w' => 88, 'h' => 60, 'rows' => [['Nom', 'Note'], ['<script>x</script>', '12']]],
        ]]]]));

        $this->assertStringContainsString('<th>Nom</th>', $html);
        $this->assertStringContainsString('<td>&lt;script&gt;x&lt;/script&gt;</td>', $html);
        $this->assertStringNotContainsString('<script>x', $html);
    }

    public function testLogoEntierAncreAGauche(): void
    {
        $html = DeckRenderer::render(DeckSpec::normalize(['slides' => [['elements' => [
            ['type' => 'image', 'x' => 6, 'y' => 90, 'w' => 12, 'h' => 6, 'url' => '/assets/img/logo.png', 'fit' => 'contain', 'anchor' => 'left'],
        ]]]]));

        $this->assertStringContainsString('object-fit:contain;object-position:left center', $html);
    }

    /** Sans génération d'images, un layout visuel produirait un cadre vide. */
    public function testLayoutVisuelSansImageRetombeSurDuTexte(): void
    {
        $slide = LayoutEngine::build($this->item('Titre', ['a', 'b'], 'image-droite'), 1, 5, false);

        $this->assertNotContains('image', $this->elementTypes($slide));
        $this->assertNotSame('image-droite', $slide['layout']);
    }

    /** Symétrique : un prompt d'image sur un layout texte serait perdu en silence. */
    public function testPromptImageForceUnLayoutVisuel(): void
    {
        $slide = LayoutEngine::build(
            $this->item('Titre', ['a', 'b'], 'titre-contenu', 'un visuel abstrait'),
            1,
            5,
            true
        );

        $this->assertContains('image', $this->elementTypes($slide));
        $this->assertSame('image-droite', $slide['layout']);
    }

    public function testPromptImageSansPointsDonneUnePleinePage(): void
    {
        $slide = LayoutEngine::build($this->item('Titre', [], 'section', 'un paysage'), 3, 6, true);

        $this->assertSame('image-pleine', $slide['layout']);
        $this->assertContains('image', $this->elementTypes($slide));
    }

    public function testDeuxColonnesSansAssezDePointsRetombe(): void
    {
        $slide = LayoutEngine::build($this->item('Titre', ['a', 'b'], 'deux-colonnes'), 1, 5, false);

        $this->assertSame('titre-contenu', $slide['layout']);
    }

    public function testNumeroDeSlideAbsentDesPleinesPages(): void
    {
        foreach (['titre', 'section', 'image-pleine'] as $layout) {
            $slide = LayoutEngine::build($this->item('Titre', [], $layout, 'visuel'), 0, 5, true);
            $roles = array_column($slide['elements'], 'role');
            $this->assertNotContains('numero', $roles, "layout {$layout}");
        }
    }

    public function testNumeroDeSlidePresentSurLesAutres(): void
    {
        $slide = LayoutEngine::build($this->item('Titre', ['a', 'b'], 'titre-contenu'), 2, 8, false);
        $roles = array_column($slide['elements'], 'role');

        $this->assertContains('numero', $roles);
    }

    /** Le voile doit rester noir : sur thème sombre, `--text` éclaircirait l'image. */
    public function testVoileDeLimagePleinePageEstNoir(): void
    {
        $slide = LayoutEngine::build($this->item('Titre', [], 'image-pleine', 'un paysage'), 1, 5, true);

        $voile = null;
        foreach ($slide['elements'] as $el) {
            if ($el['type'] === 'shape' && $el['id'] === 'voile') {
                $voile = $el;
            }
        }

        $this->assertNotNull($voile);
        $this->assertSame('#000000', $voile['fill']);
    }

    // -------------------------------------------------------------- rendu

    private function deck(string $theme = 'sobre'): array
    {
        $items = [
            $this->item('Couverture', ['sous-titre'], 'titre'),
            $this->item('Contenu', ['un point', 'deux points'], 'titre-contenu'),
        ];

        $slides = [];
        foreach ($items as $i => $item) {
            $slides[] = LayoutEngine::build($item, $i, count($items), false);
        }

        return DeckSpec::normalize([
            'title' => 'Mon deck', 'theme' => ['preset' => $theme], 'slides' => $slides,
        ]);
    }

    public function testDocumentHtmlComplet(): void
    {
        $html = DeckRenderer::render($this->deck());

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringEndsWith('</html>', trim($html));
        $this->assertSame(2, substr_count($html, '<section class="slide'));
    }

    public function testTexteEchappe(): void
    {
        $deck = DeckSpec::normalize(['slides' => [['elements' => [
            ['id' => 'a', 'type' => 'text', 'text' => '<script>alert(1)</script>', 'x' => 5, 'y' => 5, 'w' => 50, 'h' => 10],
        ]]]]);

        $html = DeckRenderer::render($deck);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testUnitesRelativesAuCanvas(): void
    {
        $html = DeckRenderer::render($this->deck());

        $this->assertStringContainsString('container-type:size', $html);
        $this->assertStringContainsString('cqw', $html);
        $this->assertStringContainsString('aspect-ratio:16/9', $html);
    }

    /** Un accent clair doit recevoir du texte sombre, sinon la slide « section » est illisible. */
    public function testContrasteSurAccentClair(): void
    {
        $html = DeckRenderer::render($this->deck('audacieux')); // accent ambre

        $this->assertStringContainsString('--on-accent:#141414', $html);
    }

    public function testContrasteSurAccentFonce(): void
    {
        $html = DeckRenderer::render($this->deck('sobre')); // accent bleu

        $this->assertStringContainsString('--on-accent:#ffffff', $html);
    }

    public function testImageSansUrlRendUnPlaceholder(): void
    {
        $deck = DeckSpec::normalize(['slides' => [['elements' => [
            ['id' => 'i', 'type' => 'image', 'prompt' => 'à générer', 'x' => 10, 'y' => 10, 'w' => 30, 'h' => 30],
        ]]]]);

        $this->assertStringContainsString('el-image-placeholder', DeckRenderer::render($deck));
    }

    public function testDeckVideRendUneSlideDeSecours(): void
    {
        $html = DeckRenderer::render(DeckSpec::normalize([]));

        $this->assertStringContainsString('<section class="slide', $html);
    }

    /** Le mode par défaut affiche une slide à la fois (le partage et le volet en dépendent). */
    public function testModeSingleParDefaut(): void
    {
        $this->assertStringContainsString('class="deck deck-single"', DeckRenderer::render($this->deck()));
        $this->assertStringContainsString('class="deck deck-scroll"', DeckRenderer::render($this->deck(), ['mode' => 'scroll']));
    }

    public function testNavigationDesactivable(): void
    {
        $this->assertStringNotContainsString('<script>', DeckRenderer::render($this->deck(), ['nav' => false]));
    }
}
