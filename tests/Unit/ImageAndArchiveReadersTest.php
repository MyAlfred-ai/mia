<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Images que les modèles ne lisent pas (HEIC, AVIF, TIFF, BMP) converties en
 * JPEG à l'envoi (ImageConvert), et archives ZIP lues fichier par fichier,
 * bornées contre les archives piégées (ArchiveReader).
 *
 * La conversion par ImageMagick est éprouvée ici par une commande de
 * substitution qui vérifie ce qu'on lui passe (codeur imposé, pas de shell) ;
 * la vraie commande l'est dans l'image Docker.
 */
final class ImageAndArchiveReadersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mia_imgzip_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        ImageConvert::overrideBinary(null);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    // ===================== Images =====================

    public function testUnBmpDevientUnJpeg(): void
    {
        if (!function_exists('imagebmp') || !function_exists('imagecreatefrombmp')) {
            $this->markTestSkipped('GD sans BMP.');
        }
        $img = imagecreatetruecolor(40, 20);
        imagefill($img, 0, 0, (int)imagecolorallocate($img, 10, 120, 200));
        $path = $this->dir . '/scan.bmp';
        imagebmp($img, $path);

        [$mime, $name, $err] = ImageConvert::forUpload($path, 'scan.bmp', 'image/bmp');
        $this->assertNull($err);
        $this->assertSame('image/jpeg', $mime);
        $this->assertSame('scan.jpg', $name);
        $info = getimagesize($path);
        $this->assertSame([40, 20, 'image/jpeg'], [$info[0], $info[1], $info['mime']], 'Converti sur place, dimensions gardées.');
    }

    public function testHeicEtTiffPassentParImageMagickAvecLeCodeurImpose(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open indisponible.');
        }
        // Commande de substitution : note ses arguments, écrit un JPEG minimal.
        $log = $this->dir . '/args.txt';
        $fake = $this->dir . '/magick';
        file_put_contents($fake, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\" >> " . escapeshellarg($log) . "; done\n"
            . "printf '\\377\\330\\377\\340JPEG'\n");
        chmod($fake, 0755);
        ImageConvert::overrideBinary($fake);

        $path = $this->dir . '/photo.heic';
        file_put_contents($path, 'contenu HEIC');
        [$mime, $name, $err] = ImageConvert::forUpload($path, 'IMG_0042.HEIC', 'image/heic');
        $this->assertNull($err);
        $this->assertSame(['image/jpeg', 'IMG_0042.jpg'], [$mime, $name]);
        $this->assertStringStartsWith("\xFF\xD8", (string)file_get_contents($path));

        $args = (string)file_get_contents($log);
        $this->assertStringContainsString('heic:' . $path . '[0]', $args, 'Codeur imposé, première image seulement : jamais deviné d\'après le contenu.');
        $this->assertStringContainsString("-limit\nmemory", $args, 'Ressources bornées.');
        $this->assertStringContainsString('jpeg:-', $args);

        file_put_contents($log, '');
        $tiff = $this->dir . '/scan.tif';
        file_put_contents($tiff, 'contenu TIFF');
        $this->assertSame('image/jpeg', ImageConvert::forUpload($tiff, 'scan.tif', 'image/tiff')[0]);
        $this->assertStringContainsString('tiff:' . $tiff . '[0]', (string)file_get_contents($log));
    }

    public function testSansMoteurLEnvoiEstRefuseAvecUnMessageClair(): void
    {
        ImageConvert::overrideBinary('');
        $path = $this->dir . '/photo.heic';
        file_put_contents($path, 'contenu HEIC');
        [$mime, $name, $err] = ImageConvert::forUpload($path, 'photo.heic', 'image/heic');
        $this->assertSame(['image/heic', 'photo.heic'], [$mime, $name]);
        $this->assertStringContainsString('photo.heic', (string)$err);
        $this->assertStringContainsString('JPEG', (string)$err);
        $this->assertSame('contenu HEIC', file_get_contents($path), 'Le fichier n\'est pas touché.');
    }

    public function testUneImageOrdinaireOuUnDocumentNeChangePas(): void
    {
        $this->assertSame(['image/png', 'a.png', null], ImageConvert::forUpload('/nulle/part', 'a.png', 'image/png'));
        $this->assertSame(['application/pdf', 'a.pdf', null], ImageConvert::forUpload('/nulle/part', 'a.pdf', 'application/pdf'));
        $this->assertTrue(ImageConvert::isConvertible('image/heif'));
        $this->assertFalse(ImageConvert::isConvertible('image/svg+xml'), 'Jamais un format qui exécute du code.');
    }

    public function testLaConversionEstBrancheeAuxQuatreEnvois(): void
    {
        $root = dirname(__DIR__, 2);
        $chat = (string)file_get_contents($root . '/src/Controllers/ChatController.php');
        $this->assertSame(1, substr_count($chat, 'ImageConvert::forUpload('));
        $this->assertSame(2, substr_count((string)file_get_contents($root . '/src/Controllers/AdminController.php'), 'ImageConvert::forUpload('),
            'Bases de connaissances et documents d\'assistant.');
        $this->assertSame(2, substr_count((string)file_get_contents($root . '/src/Controllers/WorkflowController.php'), 'ImageConvert::forUpload('),
            'Départ d\'un workflow et document de référence d\'un champ Fichier.');
        $docker = (string)file_get_contents($root . '/Dockerfile');
        $this->assertMatchesRegularExpression('/install -y --no-install-recommends imagemagick/', $docker);
        $this->assertLessThan(strpos($docker, 'imagemagick'), strpos($docker, 'docker-php-ext-install'),
            'ImageMagick dans une couche À PART, après les extensions (leur cache reste valable).');
    }

    // ===================== Archives =====================

    private function zip(array $entries): string
    {
        $path = $this->dir . '/a' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $data) {
            $zip->addFromString($name, $data);
        }
        $zip->close();
        return $path;
    }

    public function testUneArchiveEstListeeEtSesFichiersLus(): void
    {
        $inner = $this->zip(['dedans.txt' => 'ne pas ouvrir']);
        $path = $this->zip([
            'compte-rendu.md'   => "# Réunion\nDécision : lancer l'appel d'offres.",
            'data/budget.csv'   => "Poste;Montant\nLocation;1200\n",
            'photo.png'         => "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32),
            'autre.zip'         => (string)file_get_contents($inner),
            '__MACOSX/._compte-rendu.md' => 'bruit',
            '.DS_Store'         => 'bruit',
        ]);
        $this->assertSame(ArchiveReader::MIME, FileExtractor::detectMime($path, 'dossier.zip'));
        $text = (string)FileExtractor::extract($path, ArchiveReader::MIME);

        $this->assertStringContainsString('# Archive ZIP — 4 fichiers', $text, 'Les fichiers système ne comptent pas.');
        $this->assertStringContainsString('Contenu : compte-rendu.md, data/budget.csv, photo.png, autre.zip', $text);
        $this->assertStringContainsString("## Fichier : compte-rendu.md\n# Réunion", $text);
        $this->assertStringContainsString("## Fichier : data/budget.csv\nPoste;Montant", $text);
        $this->assertStringNotContainsString('## Fichier : photo.png', $text, 'Une image est listée, pas lue.');
        $this->assertStringNotContainsString('ne pas ouvrir', $text, 'Une archive dans l\'archive n\'est pas ouverte.');
        $this->assertStringContainsString("autre.zip (archive dans l'archive)", $text);
        $this->assertStringNotContainsString('bruit', $text);
    }

    public function testUneArchivePiegeeNEstPasDecompresseeEnEntier(): void
    {
        // 30 Mo de zéros : quelques Ko compressés, au-delà de la borne d'un fichier.
        $path = $this->zip(['bombe.txt' => str_repeat("\0", 30 * 1024 * 1024), 'lisible.txt' => 'Bonjour']);
        $this->assertLessThan(200000, filesize($path));
        $text = (string)ArchiveReader::zipText($path);
        $this->assertStringContainsString('bombe.txt (trop volumineux)', $text);
        $this->assertStringContainsString("## Fichier : lisible.txt\nBonjour", $text);
    }

    public function testUneArchiveVideOuIllisibleRendNull(): void
    {
        $this->assertNull(ArchiveReader::zipText($this->zip(['dossier/' => ''])));
        file_put_contents($this->dir . '/faux.zip', 'PK pas une archive');
        $this->assertNull(ArchiveReader::zipText($this->dir . '/faux.zip'));
    }

    public function testUneArchiveSeJointMaisNeSeSynchronisePas(): void
    {
        $this->assertContains(ArchiveReader::MIME, UPLOAD_ALLOWED_TYPES);
        $this->assertArrayHasKey(ArchiveReader::MIME, KnowledgeBaseDocument::ALLOWED_MIMES);
        $this->assertFalse(KbSync::isSyncable(['mime' => 'application/zip', 'size' => 10, 'export' => null]),
            'Une archive d\'un dossier synchronisé n\'est pas ingérée d\'office.');
        $this->assertTrue(KbSync::isSyncable(['mime' => 'application/vnd.ms-powerpoint', 'size' => 10, 'export' => null]));
        $this->assertNull(MailAttachments::unreadableReason(['nom' => 'pieces.zip', 'type' => 'application/x-zip-compressed', 'taille' => 10, 'piece' => 'fichier']));
    }
}
