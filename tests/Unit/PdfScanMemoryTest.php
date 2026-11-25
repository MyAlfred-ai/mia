<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Lecture d'un PDF numérisé sans épuiser la mémoire de PHP.
 *
 * Le défaut corrigé (chez un client, 2026-10-29) : un PDF scanné — des pages en
 * images, aucun texte — faisait échouer l'envoi dans le chat en 500. smalot
 * gardait et décompressait chaque image, `pdftotext` (absent de l'image, et de
 * toute façon muet sur un scan) ne trouvait rien, puis le repli PHP
 * décompressait TOUS les flux d'un bloc dans une copie du fichier : une page
 * A4 numérisée pèse 26 Mo décompressée, quelques pages dépassaient les 256 Mo
 * (« Allowed memory size … exhausted », erreur fatale que rien ne rattrape).
 */
final class PdfScanMemoryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pdfscan_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    /**
     * Le vrai chemin (smalot, puis pdftotext s'il existe, puis le repli) dans
     * un processus à 96 Mo : 8 pages de 15 Mo décompressées chacune.
     * L'ancien code y mourait, dans smalot comme dans le repli.
     */
    public function testUnScanSansTexteSeLitSousUneMemoireBornee(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open indisponible');
        }
        $pdf = $this->tmp . '/scan.pdf';
        self::scannedPdf($pdf, 8, 2000, 2500, null);

        $root = dirname(__DIR__, 2);
        $script = $this->tmp . '/lire.php';
        file_put_contents($script, '<?php
            $root = ' . var_export($root, true) . ';
            if (is_file($root . "/vendor/autoload.php")) require $root . "/vendor/autoload.php";
            foreach (["LegacyOffice", "MailFile", "ArchiveReader", "FileExtractor"] as $c) {
                if (is_file($root . "/src/Helpers/$c.php")) require_once $root . "/src/Helpers/$c.php";
            }
            $texte = FileExtractor::extract($argv[1], "application/pdf");
            echo "OK ", (int)(memory_get_peak_usage(true) / 1048576), " ", var_export($texte, true);
        ');

        $cmd = [PHP_BINARY, '-d', 'memory_limit=96M', '-d', 'display_errors=stderr', $script, $pdf];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->assertSame(0, $code, "lecture du scan en échec : $err");
        $this->assertStringStartsWith('OK ', $out, $err);
        $this->assertStringNotContainsString('memory size', $err);
    }

    /** Le repli lit le texte des flux de contenu, compressés ou non, et rien des images. */
    public function testLeRepliLitLeTexteEtIgnoreLesImages(): void
    {
        $pdf = $this->tmp . '/mixte.pdf';
        self::scannedPdf($pdf, 2, 1200, 1600, 'Facture numero 4242');

        $texte = self::fallback($pdf);
        $this->assertNotNull($texte);
        $this->assertStringContainsString('Facture numero 4242', $texte);
        $this->assertStringNotContainsString('piege', $texte, 'un flux JPEG n’est jamais lu comme du texte');
    }

    /** Un scan sans texte rend null au lieu d’une erreur fatale. */
    public function testUnScanSansTexteRendNull(): void
    {
        $pdf = $this->tmp . '/vide.pdf';
        self::scannedPdf($pdf, 3, 2000, 2500, null);
        $this->assertNull(self::fallback($pdf));
    }

    private static function fallback(string $path): ?string
    {
        $m = new ReflectionMethod(FileExtractor::class, 'extractPdfFallback');
        return $m->invoke(null, $path);
    }

    /**
     * PDF « numérisé » : une image FlateDecode par page (très compressible,
     * comme le blanc d'une feuille scannée), un faux JPEG qui contient une
     * chaîne de texte piège, et, si $texte est donné, une ligne de texte dans
     * le flux de contenu compressé.
     */
    private static function scannedPdf(string $out, int $pages, int $w, int $h, ?string $texte): void
    {
        $objs = [];
        $add = static function (string $body) use (&$objs): int {
            $objs[] = $body;
            return count($objs);
        };
        $catalog = $add('');
        $pagesId = $add('');
        $font = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $page = gzcompress(str_repeat("\xF0\xF0\xF0", $w * $h), 6);
        $jpeg = "\xFF\xD8\xFF\xE0 (piege) Tj \xFF\xD9";
        $kids = [];
        for ($p = 1; $p <= $pages; $p++) {
            $img = $add("<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($page) . " >>\nstream\n" . $page . "\nendstream");
            $dct = $add("<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream");
            $ops = "q 595 0 0 842 0 0 cm /Im$p Do Q\n";
            if ($texte !== null) {
                $ops .= "BT /F1 12 Tf 72 720 Td ($texte) Tj ET\n";
            }
            $content = gzcompress($ops);
            $c = $add('<< /Length ' . strlen($content) . " /Filter /FlateDecode >>\nstream\n" . $content . "\nendstream");
            $kids[] = $add("<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 $font 0 R >> /XObject << /Im$p $img 0 R /J$p $dct 0 R >> >> /Contents $c 0 R >>");
        }
        $objs[$catalog - 1] = "<< /Type /Catalog /Pages $pagesId 0 R >>";
        $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $k): string => "$k 0 R", $kids)) . "] /Count $pages >>";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root $catalog 0 R >>\nstartxref\n$xref\n%%EOF\n";
        file_put_contents($out, $pdf);
    }
}
