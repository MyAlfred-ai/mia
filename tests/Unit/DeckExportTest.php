<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Briques partagées par les trois rendus d'un deck (HTML, PPTX, PDF).
 *
 * Les exporteurs eux-mêmes dépendent de vendor/ (PHPPresentation, Dompdf) que le
 * bootstrap de tests ne charge pas ; on couvre ici ce qui décide de leur
 * résultat : l'échelle typographique et la préparation des images.
 */
final class DeckExportTest extends TestCase
{
    // ------------------------------------------------ échelle typographique

    public function testTailleDeBaseSansContexte(): void
    {
        $this->assertSame(6.8, DeckRenderer::fontSizeCqw('xxl'));
        $this->assertSame(2.5, DeckRenderer::fontSizeCqw('inconnue'));
    }

    public function testTexteCourtGardeSaTaille(): void
    {
        $this->assertSame(4.0, DeckRenderer::fontSizeCqw('l', 'Chiffres clés', 84, 13));
    }

    /** Un titre long déborderait sur le filet d'accent : il doit rétrécir. */
    public function testTitreLongRetreci(): void
    {
        $petit = DeckRenderer::fontSizeCqw('l', 'Une filière industrielle qui se structure enfin', 50, 13);

        $this->assertLessThan(4.0, $petit);
        $this->assertGreaterThanOrEqual(4.0 * 0.6, $petit);
    }

    /** Le rétrécissement est borné : un texte démesuré ne rend pas la slide illisible. */
    public function testPlancherRespecte(): void
    {
        $taille = DeckRenderer::fontSizeCqw('xxl', str_repeat('mot ', 200), 40, 8);

        $this->assertEqualsWithDelta(6.8 * 0.6, $taille, 0.01);
    }

    /** Sans boîte connue (appel historique), la taille de base est conservée. */
    public function testSansBoiteAucunAjustement(): void
    {
        $this->assertSame(4.0, DeckRenderer::fontSizeCqw('l', str_repeat('x', 300)));
    }

    /** Les trois rendus doivent lire la MÊME taille pour un même élément. */
    public function testResultatDeterministe(): void
    {
        $args = ['l', "Une filière qui se structure", 50.0, 13.0];

        $this->assertSame(
            DeckRenderer::fontSizeCqw(...$args),
            DeckRenderer::fontSizeCqw(...$args)
        );
    }

    // ------------------------------------------------------------- couleurs

    public function testCouleurDeTexteSelonLeFond(): void
    {
        $theme = DeckSpec::normalizeTheme(['preset' => 'audacieux']);   // accent ambre

        // Aplat d'accent clair → texte sombre, jamais la couleur de texte du thème.
        $this->assertSame('#141414', DeckRenderer::textColor(['role' => 'titre'], 'section', $theme));
        // Image pleine page → texte blanc.
        $this->assertSame('#ffffff', DeckRenderer::textColor(['role' => 'titre'], 'image-pleine', $theme));
        // Rôles secondaires → couleur atténuée.
        $this->assertSame($theme['muted'], DeckRenderer::textColor(['role' => 'numero'], 'titre-contenu', $theme));
        $this->assertSame($theme['text'], DeckRenderer::textColor(['role' => 'titre'], 'titre-contenu', $theme));
    }

    /**
     * Couleur choisie dans l'éditeur : elle l'emporte sur les règles
     * automatiques, sinon un texte recoloré redeviendrait blanc sur une slide
     * à image pleine page — l'utilisateur croirait son clic ignoré.
     */
    public function testCouleurExpliciteGagneSurLeLayout(): void
    {
        $theme = DeckSpec::normalizeTheme(['preset' => 'audacieux']);
        $el    = ['role' => 'titre', 'color' => '#00FF88'];

        $this->assertSame('#00ff88', DeckRenderer::textColor($el, 'image-pleine', $theme));
        $this->assertSame('#00ff88', DeckRenderer::textColor($el, 'section', $theme));
        $this->assertSame('#00ff88', DeckRenderer::textColor($el, 'titre-contenu', $theme));
    }

    public function testPileDePoliceLueAuCatalogue(): void
    {
        $this->assertSame('', DeckRenderer::fontStack(''), 'défaut = police du thème');
        $this->assertSame(DeckSpec::FONTS['serif']['css'], DeckRenderer::fontStack('serif'));
        $this->assertSame('', DeckRenderer::fontStack('inconnue'));
    }

    /** Chaque police doit être déclinée pour les trois rendus. */
    public function testCatalogueDePolicesComplet(): void
    {
        foreach (DeckSpec::FONTS as $key => $font) {
            $this->assertArrayHasKey('label', $font, $key);
            $this->assertArrayHasKey('css', $font, $key);
            $this->assertArrayHasKey('pptx', $font, $key);
            $this->assertArrayHasKey('pdf', $font, $key);
            // Une police nommée doit l'être partout, sinon l'export diverge.
            $declinee = ($font['css'] !== '') + ($font['pptx'] !== '') + ($font['pdf'] !== '');
            $this->assertContains($declinee, [0, 3], "police {$key} déclinée à moitié");
        }
    }

    public function testMiseEnFormeRendueEnHtml(): void
    {
        $deck = DeckSpec::normalize(['slides' => [['id' => 's1', 'layout' => 'titre-contenu', 'elements' => [
            ['id' => 't', 'type' => 'text', 'text' => 'Stylé', 'x' => 8, 'y' => 20, 'w' => 80, 'h' => 20,
             'color' => '#ff0000', 'italic' => true, 'font' => 'mono', 'weight' => 700],
        ]]]]);
        $html = DeckRenderer::render($deck);

        $this->assertStringContainsString('color:#ff0000', $html);
        $this->assertStringContainsString('font-style:italic', $html);
        $this->assertStringContainsString('font-weight:700', $html);
        $this->assertStringContainsString('Menlo', $html);
    }

    public function testRemplissageDeForme(): void
    {
        $theme = DeckSpec::normalizeTheme(['preset' => 'sobre']);

        $this->assertSame($theme['accent'], DeckRenderer::fillColor('accent', $theme));
        $this->assertSame('#000000', DeckRenderer::fillColor('#000000', $theme));
        // Valeur fantaisiste → accent, jamais injectée telle quelle.
        $this->assertSame($theme['accent'], DeckRenderer::fillColor('url(javascript:alert(1))', $theme));
    }

    // --------------------------------------------------------------- images

    public function testCheminHorsDossierRefuse(): void
    {
        $this->assertNull(SlideImage::resolvePath('https://exemple.invalid/a.png'));
        $this->assertNull(SlideImage::resolvePath('/assets/img/../../.env'));
        $this->assertNull(SlideImage::resolvePath('/etc/passwd'));
    }

    public function testCheminLocalExistantAccepte(): void
    {
        $logo = ROOT . '/public/assets/img/logo.png';
        if (!is_file($logo)) {
            $this->markTestSkipped('logo.png absent');
        }

        $this->assertSame(realpath($logo), SlideImage::resolvePath('/assets/img/logo.png'));
    }

    public function testFichierInexistantRefuse(): void
    {
        $this->assertNull(SlideImage::resolvePath('/assets/img/generated/n-existe-pas-42.png'));
    }

    /**
     * Recadrage « cover » : une image carrée placée dans une boîte portrait
     * doit être rognée, pas étirée (PPTX et PDF ignorent object-fit).
     */
    public function testRecadrageVersUneBoitePortrait(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD indisponible');
        }

        $dir    = sys_get_temp_dir() . '/deck-test-' . bin2hex(random_bytes(4));
        $source = $dir . '/carre.png';
        @mkdir($dir, 0775, true);
        $img = imagecreatetruecolor(400, 400);
        imagepng($img, $source);

        $ratio  = 30 / 78;                       // boîte de layoutImageDroite
        $cropped = SlideImage::coverCrop($source, $ratio, $dir);

        $this->assertNotSame($source, $cropped, 'un recadrage était nécessaire');
        [$w, $h] = getimagesize($cropped);
        $this->assertEqualsWithDelta($ratio, $w / $h, 0.02);

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testRatioDejaBonAucuneCopie(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD indisponible');
        }

        $dir    = sys_get_temp_dir() . '/deck-test-' . bin2hex(random_bytes(4));
        $source = $dir . '/large.png';
        @mkdir($dir, 0775, true);
        $img = imagecreatetruecolor(320, 180);
        imagepng($img, $source);

        $this->assertSame($source, SlideImage::coverCrop($source, 16 / 9, $dir));

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testImageIllisibleRenvoieLaSource(): void
    {
        $dir  = sys_get_temp_dir() . '/deck-test-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);
        $fake = $dir . '/pas-une-image.png';
        file_put_contents($fake, 'ceci n\'est pas une image');

        // Un export ne doit jamais échouer à cause d'une image : on retombe
        // sur le fichier d'origine.
        $this->assertSame($fake, SlideImage::coverCrop($fake, 1.0, $dir));

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
}
