<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/FakeLlmStream.php';

/**
 * Tables permanentes SANS COPIE, sur MySQL réel et un faux modèle :
 *  - enrichir une table cochée ne crée aucune table visible dans la
 *    conversation : un brouillon interne (« _maj_… ») porte le calcul, que
 *    les lectures de la conversation ne voient jamais ;
 *  - les valeurs sont proposées dans UNE carte, ligne par ligne (par leur
 *    position), et l'accord les écrit dans la table ;
 *  - si la table a changé depuis le calcul, l'accord refuse d'écrire.
 *
 * Aucun appel réseau. Auto-skippé sans base.
 */
final class WorkTableClarityPipelineTest extends DatabaseTestCase
{
    private const USER = 62;

    /** @var list<array> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';

        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        LlmCatalog::setToday('2026-10-14');
        $this->insertUser(self::USER, 'creator');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        $this->events = [];
    }

    protected function tearDown(): void
    {
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
        }
        WorkTableEnricher::overrideCompletion(null);
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            Settings::refresh();
        }
        parent::tearDown();
    }

    private function user(): array
    {
        return User::findById(self::USER);
    }

    private function conversation(): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([self::USER, 'Suivi clients', 'openai::o3']);
        return (int)$this->db()->lastInsertId();
    }

    /** Table permanente « Clients 2026 » : un client sur quatre à Paris. */
    private function clients(int $n): int
    {
        $columns = WorkTableOps::columns(['Nom', 'Email', 'Ville']);
        $rows    = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['nom' => 'Client ' . $i, 'email' => 'c' . $i . '@ex.fr', 'ville' => $i % 4 === 0 ? 'Paris' : 'Lyon'];
        }
        return (new KbTableStore())->createBase($this->user(), 'Clients 2026', null, [
            'columns' => $columns, 'rows' => $rows, 'origin' => ['kind' => KbTableImport::ORIGIN_FILE, 'file' => 'clients.xlsx'], 'notes' => [],
        ]);
    }

    /** Le faux juge : « oui » pour une ligne parisienne. */
    private static function judge(): \Closure
    {
        return static function (string $system, string $user, string $llm): string {
            preg_match('/<<<\n(.*)\n>>>/s', $user, $m);
            $out = [];
            foreach (explode("\n", (string)($m[1] ?? '')) as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $out[] = ['n' => $row['n'], 'prioritaire' => str_contains($line, 'Paris') ? 'oui' : 'non'];
                }
            }
            return json_encode($out);
        };
    }

    private function enrichTurn(int $conv): void
    {
        FakeLlmStream::install();
        ChatController::setEmitSink(function (array $e): void {
            $this->events[] = $e;
        });
        $args = ['table' => 'clients_2026', 'consigne' => 'Ce client est-il prioritaire ?', 'colonnes' => [['nom' => 'Prioritaire', 'type' => 'oui_non']]];
        $call = 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => 0, 'id' => 'call_1', 'type' => 'function',
                'function' => ['name' => WorkTableTools::ENRICH, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
            ]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
            . "data: [DONE]\n\n";
        FakeLlmStream::$queue = [$call, FakeLlmStream::sse('Cinq clients sont prioritaires : la carte propose de l’écrire.')];
        WorkTableEnricher::overrideCompletion(self::judge());

        $text = 'Dis-moi pour chaque client s’il est prioritaire.';
        $q = Message::create($conv, 'user', $text);
        $r = (new ChatController())->runSend(['content' => $text, 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false], self::USER, $conv);
        $this->assertTrue($r['done'] ?? false);
    }

    private function decide(int $conv, int $actionId): array
    {
        $m = new ReflectionMethod(ChatController::class, 'decideKept');
        return $m->invoke(new ChatController(), $conv, $this->user(), $actionId, 'approve', false);
    }

    public function testEnrichirUneTablePermanenteNeCreeAucuneCopieEtProposeUneCarte(): void
    {
        $kbId = $this->clients(20);
        $conv = $this->conversation();
        ConversationKnowledgeBase::setForConversation($conv, [$kbId]);
        $this->enrichTurn($conv);

        // Aucune table visible : ni liste, ni nom, ni compte.
        $work = new SqlWorkTableStore();
        $this->assertSame([], $work->all($conv), 'Aucune copie de la table dans la conversation.');
        $this->assertSame([], SqlWorkTableStore::names($conv));
        // … mais un brouillon interne porte le calcul, ligne par ligne.
        $drafts = $this->db()->query("SELECT name FROM work_tables WHERE conversation_id = $conv")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['_maj_' . $kbId], $drafts);
        $this->assertTrue(WorkTableOps::isHidden($drafts[0]));

        // Une carte, par position ; rien n'est écrit avant l'accord.
        $pending = DrivePendingAction::pendingForConversation($conv, self::USER);
        $this->assertSame([WorkTableTools::PERM_WRITE], array_column($pending, 'tool'));
        $args = json_decode((string)$this->db()->query('SELECT args_json FROM drive_pending_actions WHERE id = ' . (int)$pending[0]['id'])->fetchColumn(), true);
        $this->assertTrue(KbTableWrite::byLine($args['spec']));
        $this->assertSame(KbTableStore::stamp((new KbTableStore())->find($kbId)), $args['target_stamp']);
        $this->assertNotContains('prioritaire', WorkTableOps::keys((array)(new KbTableStore())->find($kbId)['columns']));

        // La ligne sous la réponse nomme la table permanente, jamais le brouillon.
        $reply = $this->db()->query("SELECT * FROM messages WHERE conversation_id = $conv AND role = 'assistant' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['p' . $kbId], WorkTableView::refs(Message::agentOf($reply)['tables'] ?? null));

        // L'accord écrit la colonne dans la table, à la bonne ligne.
        $issue = $this->decide($conv, (int)$pending[0]['id']);
        $this->assertTrue($issue['ok'] ?? false, json_encode($issue, JSON_UNESCAPED_UNICODE));
        $rows = (new KbTableStore())->rows($kbId);
        $this->assertCount(20, $rows);
        foreach ($rows as $i => $row) {
            $this->assertSame(($i + 1) % 4 === 0 ? 'oui' : 'non', $row['prioritaire'] ?? null, 'Ligne ' . ($i + 1));
        }
    }

    public function testLAccordRefuseDEcrireSiLaTableAChangeDepuisLeCalcul(): void
    {
        $kbId = $this->clients(20);
        $conv = $this->conversation();
        ConversationKnowledgeBase::setForConversation($conv, [$kbId]);
        $this->enrichTurn($conv);
        $pending = DrivePendingAction::pendingForConversation($conv, self::USER);
        $this->assertCount(1, $pending);

        // Une ligne ajoutée entre le calcul et l'accord : les positions ne valent plus.
        (new KbTableStore())->addRow($kbId, ['nom' => 'Client 0', 'email' => 'c0@ex.fr', 'ville' => 'Lille'], self::USER);
        $issue = $this->decide($conv, (int)$pending[0]['id']);
        $this->assertFalse($issue['ok'] ?? false, 'Une table changée depuis le calcul ne reçoit rien.');
        $this->assertNotContains('prioritaire', WorkTableOps::keys((array)(new KbTableStore())->find($kbId)['columns']));
        $this->assertCount(21, (new KbTableStore())->rows($kbId));
    }
}
