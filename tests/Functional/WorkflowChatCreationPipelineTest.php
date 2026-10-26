<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * « Approuver » sur la carte d'un workflow que MIA a préparé dans une
 * conversation, sur la vraie base (ChatController::decideAction, par le
 * chemin d'un clic) — cf. CLAUDE.md « Workflows » :
 * - à la demande et sans point bloquant : créé, publié, prêt à lancer ;
 * - programmé : créé, laissé à publier (il démarrerait seul) ;
 * - un point bloquant : brouillon, le message dit combien ;
 * - la réponse porte l'adresse de l'éditeur, avec le retour à la conversation.
 */
final class WorkflowChatCreationPipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Controllers/WorkflowController.php';
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, 'default_llm', 'connector_web_enabled'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        Settings::set('connector_web_enabled', '1');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save([
            'provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'),
            'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0,
        ]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
        $this->insertUser(7, 'creator');
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, (string)($valeur ?? ''));
            }
        }
        parent::tearDown();
    }

    private function conversation(): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([7, 'Contrats']);
        return (int)$this->db()->lastInsertId();
    }

    private static function definition(array $start = [], string $instruction = 'Résume @{depart}.'): array
    {
        return WorkflowSpec::normalize([
            'objective' => 'Une synthèse.',
            'start' => $start + ['fiche_name' => 'Demande', 'chat' => true, 'fields' => [
                // Une valeur par défaut : un départ programmé n'a personne pour la saisir.
                ['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true, 'default' => 'IA'],
            ]],
            'nodes' => [[
                'key' => 'synth', 'type' => 'step', 'title' => 'Synthétiser', 'instruction' => $instruction,
                'sources' => ['web'], 'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'text'],
            ]],
            'end' => ['pieces' => ['synthese']],
        ]);
    }

    /** La carte déposée par l'outil, puis le clic « Approuver ». @return array{0: array, 1: int} */
    private function approuver(int $convId, array $definition, string $nom = 'Synthèse de veille'): array
    {
        $actionId = DrivePendingAction::create(7, $convId, WorkflowDraft::TOOL, [
            'name' => $nom, 'color' => WorkflowDraft::colorFor($nom), 'definition' => $definition,
        ], ['action' => 'Créer un workflow', 'fichier' => $nom]);
        $chat = new ChatController();
        $m = new ReflectionMethod(ChatController::class, 'decideKept');
        return [$m->invoke($chat, $convId, User::findById(7), $actionId, 'approve', false), $actionId];
    }

    private function dernierMessage(int $convId): string
    {
        $stmt = $this->db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$convId]);
        return (string)$stmt->fetchColumn();
    }

    public function testUnWorkflowALaDemandeEstPublieEtOuvertDansLEditeur(): void
    {
        $convId = $this->conversation();
        [$res, $actionId] = $this->approuver($convId, self::definition());
        $this->assertTrue($res['ok'] ?? false, json_encode($res));
        $id = (int)$res['resultat']['workflow_id'];
        $wf = Workflow::findById($id);
        $this->assertNotNull($wf['published'], 'Publié aussitôt : prêt à lancer.');
        $this->assertSame(1, $wf['published_version']);
        $this->assertTrue($wf['is_active']);
        $this->assertNull($wf['schedule_next_at'] ?? null, 'Rien ne démarre seul.');
        $this->assertSame(WorkflowDraft::colorFor('Synthèse de veille'), $wf['color']);
        $this->assertSame('/workflows/' . $id . '/edit?conv=' . $convId, $res['resultat']['open']);
        $this->assertSame('executed', $this->db()->query('SELECT status FROM drive_pending_actions WHERE id = ' . $actionId)->fetchColumn());
        $this->assertStringContainsString('Workflow créé et publié', $this->dernierMessage($convId));
        // Il apparaît dans le « @ » du chat.
        $this->assertContains($id, array_column(Workflow::launchableForUser(7), 'id'));
    }

    public function testUnWorkflowProgrammeResteAPublier(): void
    {
        $convId = $this->conversation();
        $def = self::definition(['schedule' => ['rule' => ['freq' => 'daily', 'days' => [], 'day' => 1, 'time' => '08:00'], 'timezone' => 'Europe/Paris']]);
        [$res] = $this->approuver($convId, $def);
        $wf = Workflow::findById((int)$res['resultat']['workflow_id']);
        $this->assertNull($wf['published'], 'Il démarrerait seul : la personne le met en service.');
        $this->assertStringContainsString('Il démarrera seul une fois publié', $this->dernierMessage($convId));
    }

    public function testUnPointBloquantLaisseUnBrouillonEtLeDit(): void
    {
        $convId = $this->conversation();
        [$res] = $this->approuver($convId, self::definition([], ''));
        $wf = Workflow::findById((int)$res['resultat']['workflow_id']);
        $this->assertNull($wf['published']);
        $this->assertMatchesRegularExpression('/Workflow créé en brouillon .* \d+ points? à régler avant de le publier/u', $this->dernierMessage($convId));
    }

    public function testLeRetourALaConversationNeVautQuePourLaSienne(): void
    {
        $convId = $this->conversation();
        $this->insertUser(8);
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([8, 'Autre']);
        $autre = (int)$this->db()->lastInsertId();
        $wc = new WorkflowController();
        $m = new ReflectionMethod(WorkflowController::class, 'originConversation');
        $this->assertSame(['conv_id' => $convId, 'url' => '/chat/' . $convId, 'title' => 'Contrats'], $m->invoke($wc, $convId, 7));
        $this->assertNull($m->invoke($wc, $autre, 7), 'La conversation d’un autre compte ne s’affiche pas.');
        $this->assertNull($m->invoke($wc, 0, 7));
    }
}
