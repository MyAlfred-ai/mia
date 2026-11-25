<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Logo (et images de slides) ramenés à une taille d'impression AVANT Dompdf.
 *
 * Le défaut payé (recette, 2026-11-04) : MIA rédige un appel d'offres en PDF,
 * « Télécharger » répond « Une erreur interne est survenue ». Cause, lue dans
 * le journal : « Allowed memory size of 268435456 bytes exhausted … Cpdf.php
 * on line 6190 : imagepng() » — Dompdf sépare le canal alpha d'un PNG pixel
 * par pixel, dans deux images GD de la taille de l'original, et le logo de
 * l'entreprise faisait 6 319 × 3 224 pixels. Erreur FATALE, que ni la
 * fabrique ni le contrôleur ne peuvent rattraper : tout PDF portant le logo
 * échouait, quel que soit son texte.
 */
final class PrintImageBoundTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('Extension gd absente.');
        }
        $this->dir = sys_get_temp_dir() . '/mia-print-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,cache/}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
    }

    /** PNG à canal alpha : fond transparent, une forme opaque, une demi-transparente. */
    private function logo(int $w, int $h, string $name = 'logo.png'): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int)imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        imagefilledrectangle($im, (int)($w * 0.1), (int)($h * 0.2), (int)($w * 0.5), (int)($h * 0.8), (int)imagecolorallocatealpha($im, 140, 60, 200, 0));
        imagefilledrectangle($im, (int)($w * 0.6), (int)($h * 0.3), (int)($w * 0.9), (int)($h * 0.7), (int)imagecolorallocatealpha($im, 30, 30, 30, 60));
        $path = $this->dir . '/' . $name;
        imagepng($im, $path, 9);

        return $path;
    }

    public function testUnLogoDemesureEstReduitEnGardantSesProportionsEtSaTransparence(): void
    {
        $source = $this->logo(3200, 1600);

        $copie = ImageBytes::boundedFile($source, ImageBytes::PRINT_LOGO_SIDE, $this->dir . '/cache');

        $this->assertNotNull($copie);
        $this->assertNotSame($source, $copie, 'Une copie, jamais le fichier d’origine réécrit.');
        [$w, $h, $type] = getimagesize($copie);
        $this->assertSame(ImageBytes::PRINT_LOGO_SIDE, $w);
        $this->assertSame(400, $h, 'Proportions gardées.');
        $this->assertSame(IMAGETYPE_PNG, $type);
        $im = imagecreatefrompng($copie);
        $coin = imagecolorsforindex($im, imagecolorat($im, 2, 2));
        $this->assertSame(127, $coin['alpha'], 'Le fond reste transparent.');
        $forme = imagecolorsforindex($im, imagecolorat($im, (int)($w * 0.3), (int)($h * 0.5)));
        $this->assertSame(0, $forme['alpha']);
        $this->assertFileExists($source, 'L’original est gardé.');
    }

    public function testLaCopieEstFaiteUneFoisPuisRelue(): void
    {
        $source = $this->logo(2000, 1000);
        $a = ImageBytes::boundedFile($source, 800, $this->dir . '/cache');
        $mtime = filemtime((string)$a);
        $b = ImageBytes::boundedFile($source, 800, $this->dir . '/cache');

        $this->assertSame($a, $b);
        $this->assertSame($mtime, filemtime((string)$b));
        $this->assertCount(1, glob($this->dir . '/cache/*') ?: [], 'Aucun fichier temporaire laissé.');
    }

    public function testUneImageQuiTientDejaEstRendueTelleQuelle(): void
    {
        $source = $this->logo(640, 320);

        $this->assertSame($source, ImageBytes::boundedFile($source, 800, $this->dir . '/cache'));
        $this->assertSame([], glob($this->dir . '/cache/*') ?: []);
    }

    public function testUnJpegResteUnJpeg(): void
    {
        $im = imagecreatetruecolor(1800, 900);
        imagefill($im, 0, 0, (int)imagecolorallocate($im, 10, 120, 200));
        $source = $this->dir . '/logo.jpg';
        imagejpeg($im, $source, 90);

        $copie = ImageBytes::boundedFile($source, 800, $this->dir . '/cache');

        $this->assertStringEndsWith('.jpg', (string)$copie);
        $this->assertSame([800, 400, IMAGETYPE_JPEG], array_slice(getimagesize((string)$copie), 0, 3));
    }

    public function testUneImageIlisibleOuDemesureeEstEcarteeSansErreurFatale(): void
    {
        $faux = $this->dir . '/faux.png';
        file_put_contents($faux, 'pas une image');
        $this->assertNull(ImageBytes::boundedFile($faux, 800, $this->dir . '/cache'));
        $this->assertNull(ImageBytes::boundedFile($this->dir . '/absent.png', 800, $this->dir . '/cache'));

        // Au-delà de ce que la mémoire de PHP permet de décoder : écartée, jamais tentée.
        $this->assertFalse(ImageBytes::decodable(10_000, 10_000));
        $this->assertTrue(ImageBytes::decodable(6319, 3224), 'Le logo de la recette se réduit (phpunit.xml : 512 Mo).');
        $this->assertFalse(ImageBytes::decodable(0, 10));
    }

    public function testDompdfNeRecoitPlusQueDesImagesBornees(): void
    {
        $pdf = (string)file_get_contents(ROOT . '/src/Helpers/PdfExportService.php');
        $this->assertMatchesRegularExpression(
            '/ImageBytes::boundedFile\(\$logo, ImageBytes::PRINT_LOGO_SIDE\)[\s\S]*base64_encode/',
            $pdf,
            'Le logo d’en-tête est réduit AVANT d’être embarqué.'
        );

        $slides = (string)file_get_contents(ROOT . '/src/Slides/SlidesPdfExporter.php');
        $this->assertStringContainsString('ImageBytes::boundedFile($path, ImageBytes::PRINT_LOGO_SIDE)', $slides, 'Logo d’une slide.');
        $this->assertStringContainsString('ImageBytes::boundedFile($path, ImageBytes::PRINT_IMAGE_SIDE)', $slides, 'Illustration d’une slide.');
    }

    /**
     * Une panne imprévue du serveur (réponse JSON du gestionnaire global, sans
     * `code`) n'affiche plus « Une erreur interne est survenue » sans issue :
     * la carte dit que le fichier n'a pas pu être créé et propose les autres
     * formats de son menu.
     */
    public function testLaCarteNAffichePlusLErreurInterneSansIssue(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/generated-files.js');
        $this->assertMatchesRegularExpression("/if \\(!data \\|\\| !data\\.code\\) \\{\\s*throw buildError\\('failed', '', \\[\\]\\);/", $js);
        $this->assertStringContainsString(': (file.targets || []);', $js);
    }
}
