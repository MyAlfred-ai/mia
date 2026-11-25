<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Versions soignées dans « Mes créations », sur la vraie base : un fichier
 * réalisé par un modèle (rangé par NativeDeckStore) et une présentation Gamma
 * deviennent des cartes, un fichier échu disparaît, et la galerie d'une autre
 * personne n'en voit rien.
 */
final class PolishedCreationsPipelineTest extends DatabaseTestCase
{
    private const USER  = 4201;
    private const OTHER = 4202;

    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        // Hors de tests/bootstrap.php, comme les autres contrôleurs.
        require_once ROOT . '/src/Controllers/UserSettingsController.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        I18n::use('fr');
        $this->insertUser(self::USER);
        $this->insertUser(self::OTHER);
        $this->dir = sys_get_temp_dir() . '/mia-polished-' . bin2hex(random_bytes(6));
        NativeDeckStore::overrideDir($this->dir);
    }

    protected function tearDown(): void
    {
        NativeDeckStore::overrideDir(null);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Message tel que NativeDeckJob l'écrit, état de carte compris. */
    private function nativeFile(int $userId, string $name): array
    {
        $convId = (int)Conversation::create($userId, null, 'openai::gpt-6.1-sol')['id'];
        $token  = NativeDeckStore::save("PK\x03\x04", $name, $userId, $convId, 'openai::gpt-6.1-sol');
        $msg    = Message::create($convId, 'assistant',
            '✅ Présentation réalisée par GPT-6.1 Sol : [' . $name . '](' . NativeDeckStore::url($convId, $token) . ') — conservée 30 jours.');
        Message::setRun((int)$msg['id'], NativeDeckRun::finish(
            NativeDeckRun::start('pptx', 'openai::gpt-6.1-sol', 'GPT-6.1 Sol', 'Bilan', 0, time() - 60),
            NativeDeckRun::DONE,
            time()
        ));

        return [$convId, $token, (int)$msg['id']];
    }

    /** @return list<array<string, mixed>> */
    private function items(int $userId): array
    {
        $build = new ReflectionMethod(UserSettingsController::class, 'buildPolishedItems');

        return $build->invoke(new UserSettingsController(), Message::polishedForUser($userId), $userId);
    }

    public function testUnFichierRealiseParUnModeleDevientUneCarte(): void
    {
        [$convId, $token, $msgId] = $this->nativeFile(self::USER, 'bilan-2026.pptx');

        $items = $this->items(self::USER);
        $this->assertCount(1, $items);
        $this->assertSame('polished_file', $items[0]['type']);
        $this->assertSame($msgId, $items[0]['message_id']);
        $this->assertSame('bilan-2026', $items[0]['title']);
        $this->assertSame('pptx', $items[0]['format']);
        $this->assertSame('slides', $items[0]['tone']);
        $this->assertSame('Par GPT-6.1 Sol', $items[0]['by']);
        $this->assertSame('Disponible encore 30 jours', $items[0]['keep']);
        $this->assertSame('/chat/' . $convId . '/presentation/' . $token, $items[0]['url']);
        $this->assertSame('direct', $items[0]['download']);
        $this->assertSame('bilan-2026.pptx', $items[0]['file_name']);
        $this->assertFalse($items[0]['shareable']);

        $this->assertSame([], $this->items(self::OTHER), 'La galerie d\'une autre personne n\'en voit rien.');
    }

    public function testUnClasseurPrendSaCouleur(): void
    {
        $this->nativeFile(self::USER, 'ventes.xlsx');

        $items = $this->items(self::USER);
        $this->assertSame('xlsx', $items[0]['format']);
        $this->assertSame('Excel', $items[0]['format_label']);
        $this->assertSame('sheet', $items[0]['tone']);
    }

    public function testUnFichierEchuNEstPlusPropose(): void
    {
        [, $token] = $this->nativeFile(self::USER, 'ancien.pptx');
        touch($this->dir . '/' . $token . '.pptx', time() - (NativeDeckStore::RETENTION_DAYS + 1) * 86400);

        $this->assertSame([], $this->items(self::USER));
    }

    public function testUnePresentationGammaDevientUneCarte(): void
    {
        $convId = (int)Conversation::create(self::USER, null, 'openai::gpt-6.1-sol')['id'];
        $msg    = Message::create($convId, 'assistant', GammaService::summarizeResultMessage('Plan 2027', [
            'gammaUrl'  => 'https://gamma.app/docs/plan-2027-abc',
            'exportUrl' => 'https://assets.api.gamma.app/export/pdf/xyz/Plan-2027.pdf',
        ]));

        $items = $this->items(self::USER);
        $this->assertCount(1, $items);
        $this->assertSame((int)$msg['id'], $items[0]['message_id']);
        $this->assertSame('Plan 2027', $items[0]['title']);
        $this->assertSame('https://gamma.app/docs/plan-2027-abc', $items[0]['open_url']);
        $this->assertSame('external', $items[0]['download']);
        $this->assertSame('Télécharger le PDF', $items[0]['download_label']);
        $this->assertSame('pdf', $items[0]['tone']);

        // Lien d'export expiré au bout d'une semaine : la carte reste, sans téléchargement.
        $this->db()->prepare('UPDATE messages SET created_at = NOW() - INTERVAL 8 DAY WHERE id = ?')->execute([(int)$msg['id']]);
        $items = $this->items(self::USER);
        $this->assertSame('', $items[0]['download']);
        $this->assertSame('https://gamma.app/docs/plan-2027-abc', $items[0]['open_url']);
    }

    public function testUnMessageOrdinaireNEstPasUneVersionSoignee(): void
    {
        $convId = (int)Conversation::create(self::USER, null, 'openai::gpt-6.1-sol')['id'];
        Message::create($convId, 'assistant', 'Voici le plan de la présentation, sans fichier.');
        Message::create($convId, 'user', '[x](/chat/' . $convId . '/presentation/' . str_repeat('ab', 16) . ')');

        $this->assertSame([], $this->items(self::USER));
    }
}
