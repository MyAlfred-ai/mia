<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Parties pures du fournisseur Google Drive (parse de saisie, verdict du test de connexion). */
final class GoogleDriveProviderTest extends TestCase
{
    public function testConnectionVerdict404EstUnSucces(): void
    {
        // La sonde vise un id inexistant : 404 = clé acceptée + API activée.
        $v = GoogleDriveProvider::connectionVerdict(404, '{"error":{"code":404}}');
        $this->assertTrue($v['ok']);
        $this->assertNotSame('', $v['detail']);
    }

    public function testConnectionVerdictCleInvalide(): void
    {
        foreach ([400, 401] as $status) {
            $v = GoogleDriveProvider::connectionVerdict($status, '{"error":{"status":"INVALID_ARGUMENT"}}');
            $this->assertFalse($v['ok']);
            $this->assertStringContainsString('Clé API Google invalide', $v['error']);
            $this->assertStringContainsString((string)$status, $v['error']);
        }
    }

    public function testConnectionVerdict403Diagnostics(): void
    {
        $cas = [
            ['SERVICE_DISABLED',              'non activée'],
            ['accessNotConfigured',           'non activée'],
            ['API_KEY_HTTP_REFERRER_BLOCKED', 'référents HTTP'],
            ['API_KEY_IP_ADDRESS_BLOCKED',    'adresses IP'],
            ['API_KEY_SERVICE_BLOCKED',       'restrictions d\'API'],
            ['autre_raison_inconnue',         'Accès refusé par Google'],
        ];
        foreach ($cas as [$marqueur, $attendu]) {
            $v = GoogleDriveProvider::connectionVerdict(403, '{"error":{"reason":"' . $marqueur . '"}}');
            $this->assertFalse($v['ok']);
            $this->assertStringContainsString($attendu, $v['error'], 'marqueur ' . $marqueur);
        }
    }

    public function testConnectionVerdictStatutsResiduels(): void
    {
        $this->assertStringContainsString('Quota', GoogleDriveProvider::connectionVerdict(429, '')['error']);
        $this->assertStringContainsString('HTTP 500', GoogleDriveProvider::connectionVerdict(500, '')['error']);
    }

    public function testUrlDossier(): void
    {
        $this->assertSame(
            '1AbCdEfGhIjKlMnOp',
            GoogleDriveProvider::parseFolderInput('https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOp')
        );
        $this->assertSame(
            '1AbCdEfGhIjKlMnOp',
            GoogleDriveProvider::parseFolderInput('https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOp?usp=sharing')
        );
    }

    public function testUrlParametreId(): void
    {
        $this->assertSame(
            '1AbCdEfGhIjKlMnOp',
            GoogleDriveProvider::parseFolderInput('https://drive.google.com/open?id=1AbCdEfGhIjKlMnOp')
        );
    }

    public function testIdBrut(): void
    {
        $this->assertSame('1AbCdEfGhIjKlMnOp', GoogleDriveProvider::parseFolderInput('  1AbCdEfGhIjKlMnOp  '));
    }

    public function testRejets(): void
    {
        $this->assertNull(GoogleDriveProvider::parseFolderInput(''));
        $this->assertNull(GoogleDriveProvider::parseFolderInput('court'));
        $this->assertNull(GoogleDriveProvider::parseFolderInput('id avec espaces interdits'));
        $this->assertNull(GoogleDriveProvider::parseFolderInput('https://example.com/pas-drive'));
    }
}
