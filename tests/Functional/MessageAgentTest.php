<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/** État « agent » d'une réponse (migration 078), sur base réelle. */
final class MessageAgentTest extends DatabaseTestCase
{
    public function testLePlanEtLaSuiteSontRelusTelsQuels(): void
    {
        $this->insertUser(1);
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (1, ?)')->execute(['T']);
        $conv = (int)$this->db()->lastInsertId();
        $msg = Message::create($conv, 'assistant', 'Je prépare.');

        $plan = ['etapes' => [['titre' => 'Lire le mail de Marie', 'statut' => 'fait']]];
        Message::setAgent((int)$msg['id'], ['plan' => $plan, 'suite' => 'plan']);
        $row = Message::findById((int)$msg['id']);
        $this->assertSame(['plan' => $plan, 'suite' => 'plan'], Message::agentOf($row));

        $vide = Message::create($conv, 'assistant', 'Rien.');
        Message::setAgent((int)$vide['id'], ['plan' => null, 'suite' => null]);
        $this->assertNull(Message::findById((int)$vide['id'])['agent_json'], 'Réponse sans agent : NULL, pas un JSON vide.');
        $this->assertSame([], Message::agentOf(Message::findById((int)$vide['id'])));
    }
}
