<?php

declare(strict_types=1);

/**
 * Catalogue des images de référence lu en base (ReferenceImages::forTurn) :
 * images des documents de l'assistant d'abord, puis celles des bases LOCALES
 * reçues, dans l'ordre des bases ; une base en direct (MyBrain) n'apporte
 * rien ; les documents qui ne sont pas des images non plus. Le fichier
 * d'origine est relu sur le stockage de la base et part tel quel.
 */
final class ReferenceImagesPipelineTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    private static function png(): string
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    private function kb(string $name, string $backend = KnowledgeBase::BACKEND_LOCAL): array
    {
        $id = KnowledgeBase::create([
            'name' => $name, 'description' => null, 'backend' => $backend,
            'embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large', 'embedding_dims' => 1536,
            'is_active' => 1, 'created_by' => null,
        ]);
        $this->dirs[] = KnowledgeBase::storageDirFor($id);
        return KnowledgeBase::findById($id);
    }

    private function kbDoc(int $kbId, string $name, string $mime, ?string $text = null, ?string $binary = null): int
    {
        $stored = 'test_' . bin2hex(random_bytes(4)) . '.' . pathinfo($name, PATHINFO_EXTENSION);
        if ($binary !== null) {
            KnowledgeBase::ensureStorageDir($kbId);
            file_put_contents(KnowledgeBase::storageDirFor($kbId) . '/' . $stored, $binary);
        }
        $id = KnowledgeBaseDocument::create([
            'kb_id' => $kbId, 'file_name' => $name, 'stored_name' => $stored, 'mime_type' => $mime, 'file_size' => 100,
        ]);
        if ($text !== null) {
            KnowledgeBaseDocument::setExtractedText($id, $text);
        }
        return $id;
    }

    public function testLeCatalogueReunitAssistantEtBasesLocalesDansLOrdre(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD absent.');
        }
        $this->db()->prepare('INSERT INTO profiles (name, system_prompt, is_active, created_by, is_personal) VALUES (?,?,1,NULL,0)')
            ->execute(['Studio', 'Tu illustres.']);
        $profileId = (int)$this->db()->lastInsertId();
        $this->dirs[] = ProfileDocument::storageDirFor($profileId);
        ProfileDocument::ensureStorageDir($profileId);
        file_put_contents(ProfileDocument::storageDirFor($profileId) . '/m.png', self::png());
        ProfileDocument::create(['profile_id' => $profileId, 'file_name' => 'mascotte.png', 'stored_name' => 'm.png', 'mime_type' => 'image/png', 'file_size' => 10]);
        ProfileDocument::create(['profile_id' => $profileId, 'file_name' => 'charte.pdf', 'stored_name' => 'c.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10]);

        $sites  = $this->kb('Sites');
        $charte = $this->kb('Charte');
        $brain  = $this->kb('Cerveau', KnowledgeBase::BACKEND_MYBRAIN);

        $this->kbDoc((int)$charte['id'], 'logo.png', 'image/png', "Description de l'image logo.png :\nLogo bleu.", self::png());
        $this->kbDoc((int)$charte['id'], 'guide.txt', 'text/plain', 'Texte.');
        $this->kbDoc((int)$sites['id'], 'usine.jpg', 'image/jpeg', "Description de l'image usine.jpg :\nFaçade en briques.");
        $this->kbDoc((int)$brain['id'], 'ne-doit-pas.png', 'image/png');

        $profile = Profile::findById($profileId);
        // Ordre reçu : Charte (assistant) puis Sites (cochée) — pas l'ordre des ids.
        $catalog = ReferenceImages::forTurn($profile, [$charte, $sites, $brain]);

        $this->assertSame(['mascotte.png', 'logo.png', 'usine.jpg'], array_column($catalog, 'key'));
        $this->assertSame(['assistant', 'kb', 'kb'], array_column($catalog, 'origin'));
        $this->assertSame('Logo bleu.', $catalog[1]['description']);
        $this->assertSame('Charte', $catalog[1]['source']);

        // Les fichiers d'origine partent tels quels ; celui qui manque est nommé.
        $loaded = ReferenceImages::loadMany($catalog);
        $this->assertSame(['mascotte.png', 'logo.png'], array_column($loaded['images'], 'name'));
        $this->assertSame(self::png(), base64_decode($loaded['images'][1]['b64']));
        $this->assertSame([['name' => 'usine.jpg', 'reason' => 'fichier introuvable']], $loaded['failed']);
    }

    public function testSansAssistantNiBaseLeCatalogueEstVide(): void
    {
        $this->assertSame([], ReferenceImages::forTurn(null, []));
    }
}
