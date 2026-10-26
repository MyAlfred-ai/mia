<?php

declare(strict_types=1);

/**
 * deleteLastAssistant (cible du « Regénérer ») ne doit JAMAIS emporter une
 * création riche (page HTML, carte, vidéo, deck) postérieure au dernier
 * message utilisateur — cas concret : « Regénérer » cliqué sous la bulle de
 * résumé d'une itération de page HTML.
 */
final class RegenerateKeepsCreationsTest extends DatabaseTestCase
{
    private function insertConversation(int $userId): int
    {
        $this->db()->prepare(
            "INSERT INTO conversations (user_id, title, llm_used) VALUES (?, 'Test', 'openai')"
        )->execute([$userId]);
        return (int)$this->db()->lastInsertId();
    }

    public function testRegenererEpargneLaPageMaisSupprimeLeResume(): void
    {
        $this->insertUser(1);
        $convId = $this->insertConversation(1);

        Message::create($convId, 'user', 'ajoute une section contact');
        $page    = Message::create($convId, 'assistant', '<!DOCTYPE html><html><body>ok</body></html>', [['type' => 'html_page', 'name' => 'page.html']]);
        $summary = Message::create($convId, 'assistant', 'Section contact ajoutée en pied de page.');

        Message::deleteLastAssistant($convId);

        $rows = Message::forConversation($convId);
        $ids  = array_map(static fn(array $m): int => (int)$m['id'], $rows);

        $this->assertContains((int)$page['id'], $ids, 'La page générée doit survivre au Regénérer');
        $this->assertNotContains((int)$summary['id'], $ids, 'La bulle de résumé, elle, est remplacée');
        $this->assertCount(2, $rows); // user + page
    }

    public function testRegenererSupprimeUneReponseTexteClassique(): void
    {
        $this->insertUser(1);
        $convId = $this->insertConversation(1);

        Message::create($convId, 'user', 'bonjour');
        Message::create($convId, 'assistant', 'Bonjour, comment puis-je aider ?');

        Message::deleteLastAssistant($convId);

        $rows = Message::forConversation($convId);
        $this->assertCount(1, $rows);
        $this->assertSame('user', $rows[0]['role']);
    }

    public function testConversationTermineeParUnMessageUserResteIntacte(): void
    {
        $this->insertUser(1);
        $convId = $this->insertConversation(1);

        Message::create($convId, 'user', 'question 1');
        Message::create($convId, 'assistant', 'réponse 1');
        Message::create($convId, 'user', 'question 2 restée sans réponse');

        Message::deleteLastAssistant($convId);

        $this->assertCount(3, Message::forConversation($convId));
    }
}
