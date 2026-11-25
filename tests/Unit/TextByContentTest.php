<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tout fichier dont le contenu est du texte se lit, quelle que soit son
 * extension — et jamais un binaire. Gardes :
 *  - la détection par le CONTENU (`FileExtractor::isReadableText`,
 *    promotion d'un type générique ou texte en text/plain) ;
 *  - la règle commune des services (`readerFor` : pièces jointes d'email,
 *    Drive, pages web), qui ne TENTE le texte que pour que les octets
 *    tranchent ;
 *  - le chat accepte exactement les formats des bases de connaissances ;
 *  - un refus nomme le format reçu et dit ce que MIA lit ;
 *  - un très long fichier texte n'est envoyé au modèle qu'en partie, et le
 *    reste se relit.
 */
final class TextByContentTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mia_text_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function file(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    // ===================== Contenu =====================

    public function testDuTexteEstReconnuUnBinaireJamais(): void
    {
        $this->assertTrue(FileExtractor::isReadableText("Bonjour\nligne 2\tavec tabulation\r\n"));
        $this->assertTrue(FileExtractor::isReadableText("Couleurs de journal \x1b[31mrouge\x1b[0m " . str_repeat('texte ', 40)),
            'Un journal porte des codes de couleur : quelques contrôles sont tolérés.');
        $this->assertTrue(FileExtractor::isReadableText("\xFF\xFE" . mb_convert_encoding('Réunion', 'UTF-16LE', 'UTF-8')),
            'Un texte UTF-16 (Bloc-notes « Unicode ») porte des octets nuls : il reste du texte.');
        $this->assertFalse(FileExtractor::isReadableText(''));
        $this->assertFalse(FileExtractor::isReadableText("PK\x03\x04\x14\x00\x00\x00"), 'Une archive porte des octets nuls.');
        $this->assertFalse(FileExtractor::isReadableText(str_repeat("\x01\x02\x03abc", 50)), 'Trop de caractères de contrôle.');
    }

    public function testUneExtensionInconnueSeLitSiSonContenuEstDuTexte(): void
    {
        $txt = $this->file('notes.xyz', "Compte rendu\n- point 1\n- point 2\n");
        $this->assertSame('text/plain', FileExtractor::detectMime($txt, 'notes.xyz'));

        $bin = $this->file('image.xyz', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR" . random_bytes(64));
        $this->assertNotSame('text/plain', FileExtractor::detectMime($bin, 'image.xyz'),
            'Un binaire renommé n\'est jamais lu comme du texte.');
    }

    public function testLesFormatsTexteConnusSeLisentEnTexte(): void
    {
        foreach ([
            'data.json'    => '{"a": [1, 2, 3], "b": "texte"}',
            'config.yaml'  => "cle: valeur\nliste:\n  - un\n",
            'flux.ndjson'  => "{\"a\":1}\n{\"a\":2}\n",
            'agenda.ics'   => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n",
            'schema.svg'   => '<svg xmlns="http://www.w3.org/2000/svg"><text>Bonjour</text></svg>',
            'Main.swift'   => "import Foundation\nprint(\"Bonjour\")\n",
            'article.tex'  => "\\documentclass{article}\n\\begin{document}Bonjour\\end{document}\n",
        ] as $name => $bytes) {
            $this->assertSame('text/plain', FileExtractor::detectMime($this->file($name, $bytes), $name), $name);
        }
    }

    public function testUnTypeTexteDeLibmagicPasseEnTexte(): void
    {
        $this->assertTrue(FileExtractor::isTextualMime('application/json'));
        $this->assertTrue(FileExtractor::isTextualMime('application/x-yaml'));
        $this->assertTrue(FileExtractor::isTextualMime('image/svg+xml'));
        $this->assertTrue(FileExtractor::isTextualMime('application/vnd.api+json'));
        $this->assertTrue(FileExtractor::isTextualMime('text/x-python; charset=utf-8'));
        $this->assertFalse(FileExtractor::isTextualMime('message/rfc822'), 'Un email a son lecteur dédié (à venir), pas le texte brut.');
        $this->assertFalse(FileExtractor::isTextualMime('application/zip'));
        $this->assertFalse(FileExtractor::isTextualMime(''));
    }

    // ===================== Services (email, Drive, web) =====================

    public function testLaRegleCommuneDesServices(): void
    {
        $cases = [
            ['application/octet-stream', 'export.csv', 'text/plain'],
            ['application/octet-stream', 'rapport.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['application/octet-stream', 'livre.epub', 'application/epub+zip'],
            ['application/octet-stream', 'vieux.xls', LegacyOffice::MIME_XLS],
            ['application/octet-stream', 'sans-extension', 'text/plain'],
            ['application/octet-stream', 'archive.zip', 'application/zip'],
            ['application/octet-stream', 'archive.7z', null],
            ['application/octet-stream', 'photo.jpg', null],
            ['application/json', 'x', 'text/plain'],
            ['text/calendar', 'invitation.ics', 'text/plain'],
            ['application/x-msdos-program', 'lance.bat', 'text/plain'],
            ['text/x-python', 'script.py', 'text/plain'],
            ['application/xhtml+xml', '', 'text/html'],
            ['text/rtf', '', 'application/rtf'],
            ['image/png', 'p.png', null],
            ['application/zip', 'a.zip', 'application/zip'],
            ['application/x-7z-compressed', 'a.7z', null],
        ];
        foreach ($cases as [$mime, $name, $want]) {
            $this->assertSame($want, FileExtractor::readerFor($mime, $name), $mime . ' ' . $name);
        }
    }

    public function testUnePieceJointeTexteDeTypeGeneriqueSeLitUnBinaireNon(): void
    {
        foreach ([
            ['nom' => 'config.yaml', 'type' => 'application/octet-stream'],
            ['nom' => 'agenda.ics', 'type' => 'text/calendar'],
            ['nom' => 'script.py', 'type' => 'text/x-python'],
            ['nom' => 'notes', 'type' => 'application/octet-stream'],
        ] as $a) {
            $this->assertNull(MailAttachments::unreadableReason($a + ['taille' => 10, 'piece' => 'fichier']), $a['nom']);
        }

        $this->assertStringContainsString('Ordre du jour', MailAttachments::extractText(
            "Ordre du jour\n1. Budget\n", 'application/octet-stream', 'odj.notes'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pas un fichier texte');
        MailAttachments::extractText("MZ\x90\x00\x03\x00\x00\x00" . random_bytes(64), 'application/octet-stream', 'outil.bin2');
    }

    public function testDriveLitLesTypesTexteQuIlAnnonce(): void
    {
        $m = new \ReflectionMethod(UserDriveService::class, 'readMime');
        $this->assertSame('text/plain', $m->invoke(null, 'text/x-python', 'outil.py'));
        $this->assertSame('text/plain', $m->invoke(null, 'application/x-yaml', 'ci.yml'));
        $this->assertSame('text/plain', $m->invoke(null, 'text/csv', 'export.csv'));
        $this->assertSame('application/epub+zip', $m->invoke(null, 'application/epub+zip', 'livre.epub'));
        $this->assertNull($m->invoke(null, 'application/vnd.google-apps.form', 'Questionnaire'),
            'Un formulaire Google n\'a ni export ni octets lisibles.');
        $this->assertNull($m->invoke(null, 'application/x-7z-compressed', 'archive.7z'));

        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Drive/UserDriveService.php');
        $this->assertStringContainsString("FileExtractor::isReadableText(substr((string)\$body, 0, 65536))", $src,
            'Lu comme du texte, un fichier Drive doit en être.');
    }

    /** @param array<string, array> $pages */
    private function reader(array $pages): WebPageReader
    {
        return new WebPageReader(
            static fn(array $target): array => $pages[$target['url']] ?? ['status' => 404, 'headers' => [], 'body' => ''],
            static fn(string $host): array => ['93.184.216.34']
        );
    }

    public function testUnLienVersDuJsonOuUnFichierTexteSeLit(): void
    {
        $r = $this->reader(['https://exemple.fr/api' => [
            'status' => 200, 'headers' => ['content-type' => 'application/vnd.api+json'], 'body' => '{"nom": "Durand"}',
        ]])->read('https://exemple.fr/api', 4000);
        $this->assertSame('texte', $r['type']);
        $this->assertStringContainsString('Durand', $r['contenu']);

        $r = $this->reader(['https://exemple.fr/brut' => [
            'status' => 200, 'headers' => ['content-type' => 'application/octet-stream'], 'body' => "cle = valeur\n",
        ]])->read('https://exemple.fr/brut', 4000);
        $this->assertSame('texte', $r['type'], 'Un type générique dont les octets sont du texte se lit.');
    }

    public function testUnLienVersUnBinaireDeTypeGeneriqueEstRefuse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->reader(['https://exemple.fr/outil' => [
            'status' => 200, 'headers' => ['content-type' => 'application/octet-stream'],
            'body' => "\x7fELF\x02\x01\x01\x00" . str_repeat("\x00", 64),
        ]])->read('https://exemple.fr/outil', 4000);
    }

    // ===================== Chat = bases =====================

    public function testLeChatAccepteLesFormatsDesBases(): void
    {
        $chat = UPLOAD_ALLOWED_TYPES;
        $kb   = array_keys(KnowledgeBaseDocument::ALLOWED_MIMES);
        sort($chat);
        sort($kb);
        $this->assertSame($kb, $chat, 'Un format lu par une base l\'est aussi dans la conversation, et l\'inverse.');
    }

    public function testLeSelecteurProposeLesExtensionsTexte(): void
    {
        $accept = FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES);
        foreach (['.tex', '.svg', '.ipynb', '.ics', '.swift', '.yaml', '.pptx', '.epub', '.odt'] as $ext) {
            $this->assertStringContainsString($ext, $accept, $ext);
        }
        $this->assertStringNotContainsString('*', $accept, 'Jamais de joker (photos HEIC livrées telles quelles sur iOS).');
        foreach (FileExtractor::textExtensions() as $ext) {
            $this->assertTrue(FileExtractor::hasTextExtension('f' . $ext), $ext);
        }
    }

    public function testUnRefusNommeLeFormatEtCeQueMiaLit(): void
    {
        $msg = FileExtractor::unsupportedMessage('plan.dwg', 'application/octet-stream');
        $this->assertStringContainsString('plan.dwg', $msg);
        $this->assertStringContainsString('.dwg', $msg);
        $this->assertStringContainsString('tout fichier texte', $msg);
        $this->assertStringContainsString('est vide', FileExtractor::unsupportedMessage('vide.txt', 'application/x-empty'));

        $root = dirname(__DIR__, 2);
        foreach (['src/Controllers/ChatController.php', 'src/Controllers/WorkflowController.php', 'src/Controllers/AdminController.php'] as $f) {
            $this->assertStringContainsString('FileExtractor::unsupportedMessage(', (string)file_get_contents($root . '/' . $f), $f);
        }
    }

    // ===================== Très long fichier texte =====================

    public function testUnTresLongFichierTexteNEstEnvoyeQuEnPartie(): void
    {
        $long  = str_repeat("Ligne de journal numéro 42 avec du détail.\n", 20000);
        $court = "Petit fichier\n";
        $rows  = [
            ['role' => 'user', 'content' => 'Voici le journal', 'files_json' => json_encode([
                ['name' => 'serveur.log', 'type' => 'text/plain', 'extracted' => $long],
                ['name' => 'note.txt', 'type' => 'text/plain', 'extracted' => $court],
                ['name' => 'photo.png', 'type' => 'image/png', 'extracted' => base64_encode(str_repeat('x', 300000))],
            ])],
        ];
        [$out, $names, $now] = ContextWindow::boundFiles($rows, 20000);

        $this->assertSame(['serveur.log'], $names);
        $this->assertTrue($now, 'Le fichier coupé est dans le message en cours.');
        $files = json_decode((string)$out[0]['files_json'], true);
        $this->assertLessThan(mb_strlen($long), mb_strlen($files[0]['extracted']));
        $this->assertStringContainsString('serveur.log', $files[0]['extracted']);
        $this->assertStringContainsString('trop long', $files[0]['extracted']);
        $this->assertSame($court, $files[1]['extracted'], 'Un petit fichier reste entier.');
        $this->assertSame(base64_encode(str_repeat('x', 300000)), $files[2]['extracted'], 'Une image n\'est jamais coupée.');

        [, $none] = ContextWindow::boundFiles([['role' => 'user', 'content' => 'x', 'files_json' => json_encode([
            ['name' => 'a.txt', 'type' => 'text/plain', 'extracted' => $court],
        ])]], 20000);
        $this->assertSame([], $none);
    }

    public function testLaCoupeTombeSurUneFinDeLigne(): void
    {
        $text = "première ligne complète\nseconde ligne complète\ntroisième ligne complète\n";
        $cut  = ContextWindow::cutText($text, 28, 'x.txt');
        $this->assertStringStartsWith('première ligne complète', $cut);
        $this->assertStringNotContainsString('seconde', $cut, 'La coupe ne garde pas un bout de ligne.');
        $this->assertStringContainsString('x.txt', $cut);
        $this->assertSame($text, ContextWindow::cutText($text, 1000, 'x.txt'), 'Un texte qui tient reste entier.');
    }

    public function testLaSuiteDUnFichierCoupeSeRelit(): void
    {
        $note = ContextWindow::cutNote();
        $this->assertStringContainsString('relire_fichier_conversation', $note);
        $this->assertStringContainsString('invente', $note);

        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('ContextWindow::boundFiles(', $src);
        $this->assertStringContainsString("\$contextPlan['files_cut'] !== []", $src,
            'Un fichier coupé rend la relecture disponible.');
        $this->assertStringContainsString("\$contextPlan['files_cut_now']", $src,
            'Un fichier coupé dans le message en cours vaut demande explicite.');
    }
}
