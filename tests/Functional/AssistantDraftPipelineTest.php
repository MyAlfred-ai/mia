<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * MIA prépare un assistant, sur la vraie base : le périmètre des bases et des
 * outils qu'une proposition peut lier, la création au clic « Approuver »
 * (personnel, liens bornés au périmètre), le quota d'un créateur, la
 * révision d'une proposition en attente, « Ajuster » qui ferme la carte, et
 * l'avatar créé par MIA importé dans la bibliothèque. Aucun appel externe.
 */
final class AssistantDraftPipelineTest extends DatabaseTestCase
{
    /** @var list<string> fichiers créés pendant le test (supprimés en tearDown) */
    private array $files = [];
    /** @var list<string> avatars enregistrés pendant le test */
    private array $avatars = [];

    protected function setUp(): void
    {
        parent::setUp();
        I18n::use('fr');
        $this->insertUser(1, 'creator');
        $this->insertUser(2, 'creator');
        $this->insertUser(3, 'admin');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (self::$pdo !== null) {
            foreach ($this->avatars as $file) {
                $this->db()->prepare('DELETE FROM assistant_avatars WHERE filename = ?')->execute([$file]);
            }
        }
        parent::tearDown();
    }

    private static function user(int $id, string $role = 'creator'): array
    {
        return ['id' => $id, 'role' => $role, 'is_active' => 1];
    }

    private function kb(string $name, ?int $createdBy, bool $system = false): int
    {
        $id = KnowledgeBase::create([
            'name'               => $name,
            'description'        => 'Base ' . $name,
            'embedding_provider' => 'openai',
            'embedding_model'    => 'text-embedding-3-large',
            'embedding_dims'     => 1536,
            'is_active'          => 1,
            'created_by'         => $createdBy,
        ]);
        if ($system) {
            $this->db()->exec('UPDATE knowledge_bases SET is_system = 1 WHERE id = ' . $id);
        }
        return $id;
    }

    private function share(int $kbId, int $userId): void
    {
        $this->db()->prepare('INSERT INTO knowledge_base_shares (kb_id, user_id, can_manage) VALUES (?, ?, 0)')
            ->execute([$kbId, $userId]);
    }

    private function imageTool(string $name = 'Images', bool $active = true): int
    {
        $this->db()->prepare('INSERT INTO chat_tools (name, type, provider, model, is_active) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, 'image', 'openai', 'gpt-image-2', $active ? 1 : 0]);
        return (int)$this->db()->lastInsertId();
    }

    private function conversation(int $userId = 1): int
    {
        return (int)Conversation::create($userId, null, 'openai::gpt-5.6')['id'];
    }

    private function generatedPng(int $w, int $h): string
    {
        $dir = ROOT . '/public/assets/img/generated';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = 'test-avatar-' . bin2hex(random_bytes(6)) . '.png';
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 40, 90, 200));
        imagepng($img, $dir . '/' . $name);
        $this->files[] = $dir . '/' . $name;
        return '/assets/img/generated/' . $name;
    }

    private static function data(array $extra = []): array
    {
        return $extra + [
            'name'             => 'Préparateur de rendez-vous',
            'description'      => 'Prépare les rendez-vous clients',
            'system_prompt'    => "## Rôle\nTu es un assistant commercial.",
            'default_llm'      => null,
            'temperature'      => 0.3,
            'color'            => '#2563eb',
            'kb_ids'           => [],
            'tool_ids'         => [],
            'avatar'           => null,
            'avatar_generated' => null,
        ];
    }

    // ------------------------------------------------------------------

    public function testLePerimetreDesBasesEstCeluiDeLaFiche(): void
    {
        $mienne   = $this->kb('Catalogue', 1);
        $autre    = $this->kb('Contrats', 2);
        $partagee = $this->kb('Tarifs', 2);
        $this->share($partagee, 1);
        $systeme  = $this->kb('Aide MIA', null, true);

        $this->assertEqualsCanonicalizing([$mienne, $partagee], array_keys(AssistantDraft::kbScope(self::user(1))));
        // Un administrateur voit tout, sauf la base système (liaison posée ailleurs).
        $scopeAdmin = array_keys(AssistantDraft::kbScope(self::user(3, 'admin')));
        $this->assertEqualsCanonicalizing([$mienne, $autre, $partagee], $scopeAdmin);
        $this->assertNotContains($systeme, $scopeAdmin);

        $choices = AssistantDraft::choicesFor(self::user(1));
        $this->assertEqualsCanonicalizing([$mienne, $partagee], array_keys($choices['kbs']));
        $this->assertSame('Catalogue', $choices['kbs'][$mienne]['name']);
        $this->assertFalse($choices['admin']);
    }

    public function testSeulsLesOutilsActifsDUnTypeExposeSontLiables(): void
    {
        $actif   = $this->imageTool('Images');
        $inactif = $this->imageTool('Ancien', false);
        $this->db()->prepare('INSERT INTO chat_tools (name, type, provider, model, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute(['Page web', 'html', 'openai', 'gpt-5.6']);
        $html = (int)$this->db()->lastInsertId();

        $this->assertSame([$actif], array_keys(AssistantDraft::linkableTools()));
        $this->assertSame([$actif], AssistantDraft::sanitizeToolIds([$html, $actif, $inactif, $actif, 'x']));
    }

    public function testLApprobationCreeUnAssistantPersonnelLieDansLePerimetre(): void
    {
        $mienne = $this->kb('Catalogue', 1);
        $autre  = $this->kb('Contrats', 2);
        $tool   = $this->imageTool();

        $id = AssistantDraft::create(self::data(['kb_ids' => [$mienne, $autre], 'tool_ids' => [$tool]]), self::user(1));
        $profile = Profile::findById($id);
        $this->assertSame('Préparateur de rendez-vous', $profile['name']);
        $this->assertSame(1, (int)$profile['is_personal']);
        $this->assertSame(1, (int)$profile['is_active']);
        $this->assertSame(1, (int)$profile['created_by']);
        $this->assertNull($profile['default_llm'], 'défaut application : jamais recopié');
        $this->assertSame(0.3, (float)$profile['temperature']);
        $this->assertSame('#2563eb', $profile['color']);
        $this->assertSame([$tool], array_map('intval', ChatTool::idsForProfile($id)));
        // La base d'un autre, glissée dans la proposition, n'est pas liée.
        $this->assertSame([$mienne], array_map('intval', KnowledgeBase::kbIdsForProfile($id)));
        // Visible de son seul créateur.
        $this->assertSame([$id], array_map(static fn(array $p): int => (int)$p['id'], Profile::forManager(self::user(1))));
        $this->assertSame([], Profile::forManager(self::user(2)));
    }

    public function testLeQuotaDUnCreateurEstCompteMaisJamaisCeluiDUnAdministrateur(): void
    {
        for ($i = 0; $i < Profile::MAX_PERSONAL_PER_CREATOR; $i++) {
            $this->insertProfile('A' . $i, true, 1);
        }
        $this->assertTrue(AssistantDraft::quotaReached(self::user(1)));
        $this->assertFalse(AssistantDraft::quotaReached(self::user(2)));
        $this->assertFalse(AssistantDraft::quotaReached(self::user(3, 'admin')));
    }

    public function testUneRevisionRemplaceLaPropositionEnAttente(): void
    {
        $conv = $this->conversation();
        $premiere = DrivePendingAction::create(1, $conv, AssistantDraft::TOOL, ['name' => 'V1'], ['action' => 'Créer un assistant']);
        DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'X'], ['action' => 'Créer une tâche']);
        $seconde  = DrivePendingAction::create(1, $conv, AssistantDraft::TOOL, ['name' => 'V2'], ['action' => 'Créer un assistant']);

        $this->assertTrue(AssistantDraft::hasPending($conv, 1));
        $this->assertFalse(AssistantDraft::hasPending($conv, 2), 'jamais la proposition d’un autre');
        $latest = AssistantDraft::latestPending($conv, 1);
        $this->assertSame($seconde, (int)$latest['id']);
        $this->assertSame('V2', $latest['args']['name'], 'les arguments reviennent : la révision les reprend');

        $this->assertTrue(AssistantDraft::supersede($seconde, 1, $conv));
        $this->assertFalse(AssistantDraft::supersede($seconde, 1, $conv), 'une carte close ne se ferme pas deux fois');
        $statut = $this->db()->query('SELECT status FROM drive_pending_actions WHERE id = ' . $seconde)->fetchColumn();
        $this->assertSame('cancelled', $statut);
        $this->assertSame($premiere, (int)AssistantDraft::latestPending($conv, 1)['id']);
    }

    public function testAjusterFermeLaCarteEtLeDitDansLaConversation(): void
    {
        $conv = $this->conversation();
        $carte = DrivePendingAction::create(1, $conv, AssistantDraft::TOOL, ['name' => 'Veille'], ['action' => 'Créer un assistant']);
        $profile = $this->insertProfile('Veille', true, 1);

        // Un autre compte ne ferme rien.
        AssistantDraft::closeAdjustedCard($carte, 2, $profile, 'Veille');
        $this->assertNotNull(DrivePendingAction::findPendingForUser($carte, 1));

        AssistantDraft::closeAdjustedCard($carte, 1, $profile, 'Veille');
        $this->assertNull(DrivePendingAction::findPendingForUser($carte, 1));
        $dernier = Message::forConversation($conv);
        $this->assertStringContainsString('Assistant créé depuis sa fiche : [« Veille »](/admin/profiles/' . $profile . '/edit)', (string)end($dernier)['content']);
    }

    public function testLAvatarCreeParMiaEntreDansLaBibliothequeACreation(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD indisponible.');
        }
        $url = $this->generatedPng(1000, 600);
        $id = AssistantDraft::create(self::data(['avatar_generated' => $url]), self::user(1));

        $file = (string)Profile::findById($id)['avatar'];
        $this->assertMatchesRegularExpression('/^assistant-\d{8}-\d{6}-[0-9a-f]{8}\.png$/', $file);
        $path = ROOT . '/public/assets/img/profils/' . $file;
        $this->files[] = $path;
        $this->avatars[] = $file;
        $this->assertFileExists($path);
        [$w, $h] = getimagesize($path);
        $this->assertSame([AssistantAvatar::IMPORT_SIZE, AssistantAvatar::IMPORT_SIZE], [$w, $h], 'carré, réduit');

        $row = AssistantAvatar::findByFilename($file);
        $this->assertSame('Préparateur de rendez-vous', $row['name']);
        $this->assertSame(1, (int)$row['created_by']);
        $this->assertArrayHasKey($file, AssistantAvatar::catalog());
    }

    public function testUnAvatarDisparuNEmpechePasLaCreation(): void
    {
        $id = AssistantDraft::create(self::data(['avatar_generated' => '/assets/img/generated/absent-' . bin2hex(random_bytes(4)) . '.png']), self::user(1));
        $this->assertNull(Profile::findById($id)['avatar']);
    }

    public function testLesImagesDUneConversationSeulementPeuventServirDAvatar(): void
    {
        $conv  = $this->conversation();
        $autre = $this->conversation(2);
        Message::create($conv, 'assistant', "Voici :\n\n![Image générée](/assets/img/generated/a1.png)\n");
        Message::create($autre, 'assistant', '![Image générée](/assets/img/generated/b2.png)');
        DrivePendingAction::create(1, $conv, AssistantDraft::TOOL, ['avatar_generated' => '/assets/img/generated/c3.png'], []);

        $images = AssistantDraft::conversationImages($conv, 1);
        $this->assertEqualsCanonicalizing(['/assets/img/generated/a1.png', '/assets/img/generated/c3.png'], $images);
        $this->assertSame([], AssistantDraft::conversationImages($conv, 2), 'la conversation d’un autre ne donne rien');
    }
}
