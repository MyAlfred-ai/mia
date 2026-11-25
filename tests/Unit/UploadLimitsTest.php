<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Poids maximal d'un fichier joint selon sa nature : PDF 20 Mo (il part entier
 * au modèle), PowerPoint .pptx 50 Mo (seul son texte est lu), 10 Mo sinon. Un
 * refus dit le poids, la limite, sa raison et quoi faire — côté serveur
 * (FileExtractor::tooLargeMessage) comme dans le navigateur, qui refuse avant
 * d'envoyer.
 */
final class UploadLimitsTest extends TestCase
{
    private const MO = 1024 * 1024;

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    public function testChaqueNatureASaLimite(): void
    {
        $this->assertSame(20 * self::MO, FileExtractor::uploadLimit(FileExtractor::MIME_PDF));
        $this->assertSame(50 * self::MO, FileExtractor::uploadLimit(FileExtractor::MIME_PPTX));
        $this->assertSame(UPLOAD_MAX_SIZE, FileExtractor::uploadLimit('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertSame(UPLOAD_MAX_SIZE, FileExtractor::uploadLimit('application/vnd.ms-powerpoint'), 'l’ancien .ppt garde la limite commune');
        $this->assertSame(10 * self::MO, UPLOAD_MAX_SIZE);
        $this->assertSame(50 * self::MO, FileExtractor::largestUploadLimit());
    }

    public function testLaNatureSeDevineParLeTypeSinonParLExtension(): void
    {
        $this->assertSame('pdf', FileExtractor::uploadKind(FileExtractor::MIME_PDF, 'scan'));
        $this->assertSame('pptx', FileExtractor::uploadKind('', 'Deck.PPTX'));
        $this->assertSame('pdf', FileExtractor::uploadKind('', 'a.pdf'));
        $this->assertSame('other', FileExtractor::uploadKind('application/zip', 'a.pdf'), 'le type lu l’emporte sur le nom');
        $this->assertSame('other', FileExtractor::uploadKind('', 'note.docx'));
    }

    public function testLeRefusDitLePoidsLaLimiteEtLaRaison(): void
    {
        $pdf = FileExtractor::tooLargeMessage('Rapport.pdf', FileExtractor::MIME_PDF, (int)(34.2 * self::MO));
        $this->assertStringContainsString('« Rapport.pdf » pèse 34,2 Mo', $pdf);
        $this->assertStringContainsString('ne peut pas dépasser 20 Mo', $pdf);
        $this->assertStringContainsString('entier au modèle', $pdf);
        $this->assertStringContainsString('Réduisez sa taille', $pdf);

        $pptx = FileExtractor::tooLargeMessage('Deck.pptx', FileExtractor::MIME_PPTX, 60 * self::MO);
        $this->assertStringContainsString('ne peut pas dépasser 50 Mo', $pptx);
        $this->assertStringContainsString('Compresser les images', $pptx);

        $autre = FileExtractor::tooLargeMessage('Note.docx', 'application/msword', 12 * self::MO);
        $this->assertStringContainsString('ne peut pas dépasser 10 Mo (20 Mo pour un PDF, 50 Mo pour une présentation PowerPoint)', $autre);

        $inconnu = FileExtractor::tooLargeMessage('Gros.pptx', '', null);
        $this->assertStringStartsWith('« Gros.pptx » est trop lourd : une présentation PowerPoint', $inconnu);
    }

    public function testLeRefusSeTraduit(): void
    {
        I18n::use('en');
        $msg = FileExtractor::tooLargeMessage('Report.pdf', FileExtractor::MIME_PDF, 30 * self::MO);
        $this->assertStringContainsString('“Report.pdf” is 30.0 MB: a PDF cannot exceed 20 MB.', $msg);
        $this->assertSame('MB', FileExtractor::clientUploadLimits()['unit']);
    }

    public function testLeNavigateurRefuseAvantDEnvoyerAvecLeMemeMessage(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node indisponible');
        }
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/chat.js');
        $this->assertSame(1, preg_match('/function fileTooLargeMessage\(file, cfg\) \{.*?\n\}\n/s', $src, $m));
        $cfg = json_encode(FileExtractor::clientUploadLimits(), JSON_UNESCAPED_UNICODE);
        $js = $m[0] . "\nconst cfg = $cfg;\n"
            . "console.log(JSON.stringify([\n"
            . "  fileTooLargeMessage({name: 'Rapport.pdf', size: 34.2 * 1048576}, cfg),\n"
            . "  fileTooLargeMessage({name: 'Rapport.pdf', size: 19 * 1048576}, cfg),\n"
            . "  fileTooLargeMessage({name: 'Deck.pptx', size: 45 * 1048576}, cfg),\n"
            . "  fileTooLargeMessage({name: 'Deck.pptx', size: 51 * 1048576}, cfg),\n"
            . "  fileTooLargeMessage({name: 'note.docx', size: 11 * 1048576}, cfg),\n"
            . "]));\n";
        $tmp = tempnam(sys_get_temp_dir(), 'lim') . '.js';
        file_put_contents($tmp, $js);
        $out = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp)), true);
        @unlink($tmp);

        $this->assertIsArray($out);
        $this->assertSame(FileExtractor::tooLargeMessage('Rapport.pdf', FileExtractor::MIME_PDF, (int)(34.2 * self::MO)), $out[0], 'même message des deux côtés');
        $this->assertNull($out[1]);
        $this->assertNull($out[2], 'un .pptx de 45 Mo passe');
        $this->assertStringContainsString('50 Mo', (string) $out[3]);
        $this->assertStringContainsString('10 Mo', (string) $out[4]);
    }

    public function testLesPlafondsPhpCouvrentLaPlusGrandeLimite(): void
    {
        $docker = (string) file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        $this->assertSame(1, preg_match('/upload_max_filesize=(\d+)M/', $docker, $u));
        $this->assertSame(1, preg_match('/post_max_size=(\d+)M/', $docker, $p));
        $this->assertGreaterThanOrEqual(FileExtractor::largestUploadLimit(), (int)$u[1] * self::MO);
        $this->assertGreaterThan((int)$u[1], (int)$p[1], 'post_max_size au-dessus : l’enveloppe de l’envoi compte');
    }

    public function testLesEnvoisAppliquentLaLimiteDeLeurNature(): void
    {
        $root = dirname(__DIR__, 2);
        $chat = (string) file_get_contents($root . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('FileExtractor::uploadLimit($mime)', $chat);
        $this->assertStringNotContainsString('Fichier trop lourd (max 10Mo)', $chat);
        $wf = (string) file_get_contents($root . '/src/Controllers/WorkflowController.php');
        $this->assertStringContainsString('FileExtractor::uploadLimit($mime)', $wf);
        $this->assertStringContainsString('window.FILE_UPLOAD_LIMITS', (string) file_get_contents($root . '/templates/chat/index.php'));
        $this->assertStringContainsString('fileTooLargeMessage(file, window.FILE_UPLOAD_LIMITS)', (string) file_get_contents($root . '/public/assets/js/chat.js'));
    }
}
