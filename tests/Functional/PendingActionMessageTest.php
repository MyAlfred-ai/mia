<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Cartes d'approbation rattachées à la réponse qui les a proposées
 * (migration 077), sur base réelle.
 *
 * Avant : au rechargement, toutes les cartes en attente étaient empilées en
 * fin de conversation, loin de la réponse qui les expliquait.
 */
final class PendingActionMessageTest extends DatabaseTestCase
{
    private function conversation(): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (1, ?)')->execute(['Test']);
        return (int)$this->db()->lastInsertId();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(1);
        $this->insertUser(2);
    }

    public function testUneCarteRevientSousSaReponse(): void
    {
        $conv = $this->conversation();
        $a = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'A'], ['action' => 'Créer une tâche']);
        $b = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'B'], ['action' => 'Créer une tâche']);
        $msg = Message::create($conv, 'assistant', 'Deux tâches préparées.');

        DrivePendingAction::attachToMessage([$a, $b, $b, 0, -3], (int)$msg['id'], 1);

        $pending = DrivePendingAction::pendingForConversation($conv, 1);
        $this->assertSame([(int)$msg['id'], (int)$msg['id']], array_column($pending, 'message_id'));
    }

    public function testLeRattachementNeTouchePasAuxActionsDAutrui(): void
    {
        $conv = $this->conversation();
        $autre = DrivePendingAction::create(2, $conv, 'creer_tache', [], []);
        $msg = Message::create($conv, 'assistant', 'R');
        DrivePendingAction::attachToMessage([$autre], (int)$msg['id'], 1);

        $row = $this->db()->query('SELECT message_id FROM drive_pending_actions WHERE id = ' . $autre)->fetch();
        $this->assertNull($row['message_id']);
    }

    public function testUneReponseSupprimeeRameneLaCarteEnFinDeFil(): void
    {
        $conv = $this->conversation();
        $a = DrivePendingAction::create(1, $conv, 'creer_tache', [], []);
        $msg = Message::create($conv, 'assistant', 'R');
        DrivePendingAction::attachToMessage([$a], (int)$msg['id'], 1);

        // Régénération : la réponse disparaît, pas l'action (ON DELETE SET NULL).
        $this->db()->prepare('DELETE FROM messages WHERE id = ?')->execute([(int)$msg['id']]);
        $pending = DrivePendingAction::pendingForConversation($conv, 1);
        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]['message_id']);
    }

    public function testUnRattachementDejaFaitNEstPasDeplace(): void
    {
        $conv = $this->conversation();
        $a = DrivePendingAction::create(1, $conv, 'creer_tache', [], []);
        $premier = Message::create($conv, 'assistant', 'R1');
        $second  = Message::create($conv, 'assistant', 'R2');
        DrivePendingAction::attachToMessage([$a], (int)$premier['id'], 1);
        DrivePendingAction::attachToMessage([$a], (int)$second['id'], 1);

        $this->assertSame((int)$premier['id'], DrivePendingAction::pendingForConversation($conv, 1)[0]['message_id']);
    }
}
