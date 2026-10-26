<?php

declare(strict_types=1);

/**
 * Ce que « Regénérer » relit en base avant de relancer une image : la dernière
 * réponse (postérieure au dernier message utilisateur) et la demande d'origine.
 */
final class ImageRegenerationContextTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(7);
    }

    public function testLaDerniereReponseEtLaDemandeSontRetrouvees(): void
    {
        $conv = $this->conversation();
        Message::create($conv, 'user', 'un phare au crépuscule');
        Message::create($conv, 'assistant', '![Image générée](/assets/img/generated/a.png)', [], 'openai', 'gpt-image-2');
        $demande = Message::create($conv, 'user', 'la même en noir et blanc');
        $image   = Message::create($conv, 'assistant', '![Image générée](/assets/img/generated/b.png)', [], 'openai', 'gpt-image-2');

        $this->assertSame((int)$image['id'], (int)Message::lastAnswer($conv)['id']);
        $this->assertSame((int)$demande['id'], (int)Message::lastUserMessage($conv)['id']);
        $this->assertTrue(Message::isImageToolAnswer(Message::lastAnswer($conv)));
    }

    public function testSansReponseApresLaDerniereDemandeRienNEstRejoue(): void
    {
        $conv = $this->conversation();
        Message::create($conv, 'assistant', '![Image générée](/assets/img/generated/a.png)', [], 'openai', 'gpt-image-2');
        Message::create($conv, 'user', 'et maintenant ?');

        $this->assertNull(Message::lastAnswer($conv));
    }

    private function conversation(): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([7, 'Harnais — images']);
        return (int)$this->db()->lastInsertId();
    }
}
