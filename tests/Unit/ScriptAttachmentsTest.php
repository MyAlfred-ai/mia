<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scripts joints (.bat, .cmd, .ps1…) — lus comme du texte, dans le chat comme
 * dans les bases de connaissances.
 *
 * Le défaut corrigé : libmagic classe un .bat qui commence par `@echo off`
 * en `text/x-msdos-batch`, type absent des listes du serveur — l'envoi était
 * refusé (« Type de fichier non supporté »), alors qu'un .bat sans cette ligne
 * passait. Et les sélecteurs de fichiers ne proposaient pas ces extensions.
 * Les scripts Windows arrivent en plus souvent en UTF-16 (PowerShell ISE) ou
 * en « ANSI » (Windows-1252), que la lecture brute rendait illisibles.
 */
final class ScriptAttachmentsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mia_scripts_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function file(string $name, string $bytes): string
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    // ---- Détection : un script est du texte ----

    public function testUnBatQuiCommenceParEchoOffEstAccepteCommeTexte(): void
    {
        $p = $this->file('sauvegarde.bat', "@echo off\r\nREM Sauvegarde\r\nxcopy C:\\data D:\\backup /E /Y\r\npause\r\n");
        $mime = FileExtractor::detectMime($p, 'sauvegarde.bat');
        $this->assertSame('text/plain', $mime);
        $this->assertContains($mime, UPLOAD_ALLOWED_TYPES, 'Pièce jointe du chat refusée.');
        $this->assertArrayHasKey($mime, KnowledgeBaseDocument::ALLOWED_MIMES, 'Document de base refusé.');
    }

    #[DataProvider('scriptProvider')]
    public function testChaqueScriptCourantEstLuCommeTexte(string $name, string $content): void
    {
        $p = $this->file($name, $content);
        $this->assertSame('text/plain', FileExtractor::detectMime($p, $name), "$name n'est pas lu comme du texte");
        $this->assertStringContainsString('MARQUEUR', (string)FileExtractor::extract($p, 'text/plain'));
    }

    public static function scriptProvider(): array
    {
        return [
            'bat'   => ['a.bat', "@echo off\r\necho MARQUEUR\r\n"],
            'cmd'   => ['a.cmd', "@echo off\r\nset X=MARQUEUR\r\n"],
            'ps1'   => ['a.ps1', "#Requires -Version 5\r\nWrite-Host 'MARQUEUR'\r\n"],
            'psm1'  => ['a.psm1', "function Get-Marqueur { 'MARQUEUR' }\r\n"],
            'psd1'  => ['a.psd1', "@{ ModuleVersion = '1.0'; Description = 'MARQUEUR' }\r\n"],
            'vbs'   => ['a.vbs', "WScript.Echo \"MARQUEUR\"\r\n"],
            'sh'    => ['a.sh', "#!/bin/sh\necho MARQUEUR\n"],
            'bash'  => ['a.bash', "#!/usr/bin/env bash\necho MARQUEUR\n"],
            'zsh'   => ['a.zsh', "#!/bin/zsh\necho MARQUEUR\n"],
            'ksh'   => ['a.ksh', "#!/bin/ksh\necho MARQUEUR\n"],
            'fish'  => ['a.fish', "#!/usr/bin/env fish\necho MARQUEUR\n"],
            'py'    => ['a.py', "#!/usr/bin/env python3\nprint('MARQUEUR')\n"],
            'rb'    => ['a.rb', "#!/usr/bin/env ruby\nputs 'MARQUEUR'\n"],
            'pl'    => ['a.pl', "#!/usr/bin/perl\nprint \"MARQUEUR\\n\";\n"],
            'lua'   => ['a.lua', "print('MARQUEUR')\n"],
            'casse' => ['LANCER.BAT', "@ECHO OFF\r\nECHO MARQUEUR\r\n"],
        ];
    }

    public function testUnExecutableRenommeEnBatNEstPasLuCommeTexte(): void
    {
        // En-tête MZ + octets nuls des deux côtés : un binaire, pas un texte.
        $exe = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00" . str_repeat("\x00\xB8\x00\x00\x40\x00\x1A\x00", 200);
        $p = $this->file('virus.bat', $exe);
        $this->assertFalse(FileExtractor::looksLikeText($p));
        $mime = FileExtractor::detectMime($p, 'virus.bat');
        $this->assertNotSame('text/plain', $mime);
        $this->assertNotContains($mime, UPLOAD_ALLOWED_TYPES, 'Un binaire déguisé en script ne doit pas passer.');
    }

    // ---- Lecture : encodages de Windows ----

    public function testUnPowerShellEnUtf16AvecBomEstLuEnClair(): void
    {
        $text  = "# Rapport d'équipe\r\nWrite-Host 'Été réussi'\r\n";
        $bytes = "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $p = $this->file('rapport.ps1', $bytes);

        $this->assertTrue(FileExtractor::looksLikeText($p), 'UTF-16 pris pour un binaire.');
        $this->assertSame('text/plain', FileExtractor::detectMime($p, 'rapport.ps1'));
        $this->assertSame("# Rapport d'équipe\nWrite-Host 'Été réussi'\n", FileExtractor::extract($p, 'text/plain'));
    }

    public function testUnTexteUtf16SansBomEstReconnu(): void
    {
        $le = mb_convert_encoding("echo Café\r\n", 'UTF-16LE', 'UTF-8');
        $be = mb_convert_encoding("echo Café\r\n", 'UTF-16BE', 'UTF-8');
        $this->assertSame("echo Café\n", FileExtractor::decodeText($le));
        $this->assertSame("echo Café\n", FileExtractor::decodeText($be));
        $this->assertSame("echo Café\n", FileExtractor::decodeText("\xFE\xFF" . $be));
    }

    public function testUnBatEnAnsiWindows1252EstConvertiEnUtf8(): void
    {
        $p = $this->file('noel.bat', "@echo off\r\necho Joyeux No\xEBl \xE0 tous\r\n");
        $text = (string)FileExtractor::extract($p, FileExtractor::detectMime($p, 'noel.bat'));
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringContainsString('Joyeux Noël à tous', $text);
        $this->assertStringNotContainsString("\r", $text, 'Fins de ligne Windows ramenées à \n.');
    }

    public function testLeBomUtf8EstRetireEtLeTexteResteIntact(): void
    {
        $this->assertSame("echo déjà\n\tindenté", FileExtractor::decodeText("\xEF\xBB\xBFecho déjà\r\n\tindenté"));
        $this->assertSame('', FileExtractor::decodeText(''));
    }

    public function testLeTexteExtraitPartEnJson(): void
    {
        // Le texte d'une pièce jointe voyage dans files_json et dans le corps
        // des requêtes aux modèles : un octet invalide ferait tout échouer.
        $p = $this->file('ansi.cmd', "echo \xE9t\xE9\x00\r\n");
        $text = (string)FileExtractor::extract($p, 'text/plain');
        $this->assertNotFalse(json_encode(['extracted' => $text]));
        $this->assertStringNotContainsString("\0", $text);
    }

    // ---- Sélecteurs de fichiers ----

    public function testLeSelecteurDuChatProposeLesScripts(): void
    {
        $tokens = explode(',', FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES));
        foreach (['.bat', '.cmd', '.ps1', '.vbs', '.sh', '.py'] as $ext) {
            $this->assertContains($ext, $tokens, "$ext manque au sélecteur du chat");
        }
    }

    public function testChaqueExtensionProposeeEstAccepteeParLeServeur(): void
    {
        foreach (FileExtractor::scriptExtensions() as $ext) {
            $name = 'essai' . $ext;
            $p = $this->file($name, "echo MARQUEUR\n");
            $mime = FileExtractor::detectMime($p, $name);
            $this->assertContains($mime, UPLOAD_ALLOWED_TYPES, "$ext proposé mais refusé par le chat");
            $this->assertArrayHasKey($mime, KnowledgeBaseDocument::ALLOWED_MIMES, "$ext proposé mais refusé par les bases");
        }
    }

    public function testLeSelecteurDesBasesSuitLaMemeListe(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/admin/knowledge-bases/edit.php');
        $this->assertStringContainsString('FileExtractor::acceptAttribute(array_keys(KnowledgeBaseDocument::ALLOWED_MIMES))', $tpl,
            'Le champ des bases de connaissances doit tirer ses formats de la source unique.');
    }

    public function testLeGuideUtilisateurCiteLesScripts(): void
    {
        $doc = (string)file_get_contents(ROOT . '/docs/user/fonctionnalites/conversations/joindre-des-fichiers.md');
        $this->assertStringContainsString('.bat', $doc);
        $this->assertStringContainsString('.ps1', $doc);
    }
}
