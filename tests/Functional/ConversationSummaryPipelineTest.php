<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Fenêtre de contexte sur base réelle : résumé du début d'une longue
 * conversation (migration 079) et relecture d'un fichier ancien.
 *
 * Ce que ces tests protègent : un résumé par conversation (remplacé, jamais
 * empilé), effacé avec elle, borné ; et la relecture qui ne lit QUE les
 * fichiers de CETTE conversation, par numéro ou par nom, page par page.
 */
final class ConversationSummaryPipelineTest extends DatabaseTestCase
{
    private function conversation(int $userId): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([$userId, 'Longue']);
        return (int)$this->db()->lastInsertId();
    }

    private function message(int $convId, string $role, string $content, array $files = []): int
    {
        $this->db()->prepare('INSERT INTO messages (conversation_id, role, content, files_json) VALUES (?, ?, ?, ?)')
            ->execute([$convId, $role, $content, $files !== [] ? json_encode($files, JSON_UNESCAPED_UNICODE) : null]);
        return (int)$this->db()->lastInsertId();
    }

    private function relire(int $convId, array $args, int $budget = 1000): array
    {
        $turn  = new AgentTurn(1, $convId, [], $budget, static function (array $e): void {}, static fn(string $t, array $a, ?array $r): array => []);
        $tools = new ConversationTools($turn, false, false, true);
        return $tools->handlers()['relire_fichier_conversation']($args);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(1);
    }

    public function testUnResumeParConversationRemplaceEtEffaceAvecElle(): void
    {
        $conv = $this->conversation(1);
        $this->assertNull(ConversationSummary::forConversation($conv));

        ConversationSummary::save($conv, 12, '- Objectif : budget 2026');
        ConversationSummary::save($conv, 30, '- Objectif : budget 2026' . "\n- Décision : plafond 40 k€");
        $this->assertSame(['upto' => 30, 'text' => "- Objectif : budget 2026\n- Décision : plafond 40 k€"], ConversationSummary::forConversation($conv));
        $this->assertSame(1, (int)$this->db()->query('SELECT COUNT(*) FROM conversation_context_summaries')->fetchColumn(), 'Remplacé, jamais empilé.');

        ConversationSummary::save($conv, 40, str_repeat('é', ConversationSummary::MAX_CHARS + 500));
        $this->assertSame(ConversationSummary::MAX_CHARS, mb_strlen(ConversationSummary::forConversation($conv)['text']));

        ConversationSummary::save($conv, 0, 'ignoré');
        ConversationSummary::save($conv, 50, '   ');
        $this->assertSame(40, ConversationSummary::forConversation($conv)['upto'], 'Un résumé vide ou sans coupure n\'écrase rien.');

        $this->db()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$conv]);
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM conversation_context_summaries')->fetchColumn(), 'Effacé avec la conversation.');
    }

    public function testLaRelectureLitLesFichiersDeCetteConversationSeulement(): void
    {
        $conv  = $this->conversation(1);
        $autre = $this->conversation(1);
        $this->message($conv, 'user', 'Voici', [
            ['name' => 'notes.txt', 'type' => 'text/plain', 'path' => '/x', 'extracted' => "Point 1\n\nPoint 2"],
            ['name' => 'plan.png', 'type' => 'image/png', 'path' => '/y', 'extracted' => base64_encode('png')],
        ]);
        $this->message($conv, 'assistant', 'Reçu.');
        $this->message($autre, 'user', 'Ailleurs', [['name' => 'secret.txt', 'type' => 'text/plain', 'path' => '/z', 'extracted' => 'confidentiel']]);

        $lu = $this->relire($conv, ['fichier' => 'notes']);
        $this->assertSame('notes.txt', $lu['nom']);
        $this->assertSame(1, $lu['numero']);
        $this->assertSame("Point 1\n\nPoint 2", $lu['contenu']);
        $this->assertFalse($lu['tronque']);

        $image = $this->relire($conv, ['fichier' => '2']);
        $this->assertStringContainsString('est une image', $image['error']);

        $absent = $this->relire($conv, ['fichier' => 'secret.txt']);
        $this->assertArrayHasKey('error', $absent, 'Jamais le fichier d\'une autre conversation.');
        $this->assertStringContainsString('1. notes.txt, 2. plan.png', $absent['error']);
    }

    public function testUnLongFichierSeRelitParParties(): void
    {
        $conv  = $this->conversation(1);
        $texte = implode("\n\n", array_map(static fn(int $i): string => 'Paragraphe ' . $i . ' ' . str_repeat('mot ', 120), range(1, 40)));
        $this->message($conv, 'user', 'Le rapport', [['name' => 'rapport.md', 'type' => 'text/markdown', 'path' => '/r', 'extracted' => $texte]]);

        $p1 = $this->relire($conv, ['fichier' => 'rapport.md'], 1000);
        $this->assertGreaterThan(1, $p1['parties']);
        $this->assertTrue($p1['tronque']);
        $this->assertStringContainsString('partie: 2', $p1['suite']);
        $p2 = $this->relire($conv, ['fichier' => 'rapport.md', 'partie' => 2], 1000);
        $this->assertSame(2, $p2['partie']);
        $this->assertNotSame($p1['contenu'], $p2['contenu']);

        $trop = $this->relire($conv, ['fichier' => 'rapport.md', 'partie' => 99], 1000);
        $this->assertStringContainsString('ne compte que', $trop['error']);
    }
}
