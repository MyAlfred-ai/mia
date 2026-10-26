<?php

declare(strict_types=1);

/**
 * Partage de créations par lien unique (modèle CreationShare).
 *
 * Couvre le cycle de vie complet du lien : création idempotente, régénération
 * (l'ancien token meurt), révocation, unicité par message, résolution par token
 * (sans fuite de la conversation) et cascades de suppression.
 */
final class CreationShareTest extends DatabaseTestCase
{
    private function insertConversation(int $userId): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')
            ->execute([$userId, 'Conversation de test']);
        return (int)$this->db()->lastInsertId();
    }

    private function insertHtmlMessage(int $convId): array
    {
        return Message::create(
            $convId,
            'assistant',
            '<!DOCTYPE html><html><body>Page</body></html>',
            [['type' => 'html_page', 'name' => 'page.html']]
        );
    }

    // ===================== Cycle de vie =====================

    public function testCreateOrGetEstIdempotent(): void
    {
        $userId = $this->insertUser(1);
        $convId = $this->insertConversation($userId);
        $msg    = $this->insertHtmlMessage($convId);

        $first  = CreationShare::createOrGet((int)$msg['id'], $userId);
        $second = CreationShare::createOrGet((int)$msg['id'], $userId);

        $this->assertSame($first['token'], $second['token']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$first['token']);
    }

    public function testRegenerateInvalideLAncienToken(): void
    {
        $userId = $this->insertUser(1);
        $convId = $this->insertConversation($userId);
        $msg    = $this->insertHtmlMessage($convId);

        $old = CreationShare::createOrGet((int)$msg['id'], $userId);
        $new = CreationShare::regenerate((int)$msg['id'], $userId);

        $this->assertNotSame($old['token'], $new['token']);
        $this->assertNull(CreationShare::findByToken((string)$old['token']));
        $this->assertNotNull(CreationShare::findByToken((string)$new['token']));
    }

    public function testRevokeRendLeTokenInconnu(): void
    {
        $userId = $this->insertUser(1);
        $convId = $this->insertConversation($userId);
        $msg    = $this->insertHtmlMessage($convId);

        $share = CreationShare::createOrGet((int)$msg['id'], $userId);
        CreationShare::revoke((int)$msg['id']);

        $this->assertNull(CreationShare::findByToken((string)$share['token']));
        $this->assertNull(CreationShare::forMessage((int)$msg['id']));
    }

    public function testUnSeulLienActifParMessage(): void
    {
        $userId = $this->insertUser(1);
        $convId = $this->insertConversation($userId);
        $msg    = $this->insertHtmlMessage($convId);

        CreationShare::createOrGet((int)$msg['id'], $userId);
        CreationShare::regenerate((int)$msg['id'], $userId);

        $count = (int)$this->db()->query(
            'SELECT COUNT(*) FROM creation_shares WHERE message_id = ' . (int)$msg['id']
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    // ===================== Résolution par token =====================

    public function testFindByTokenExposeLaCreationSansLaConversation(): void
    {
        $userId = $this->insertUser(1);
        $convId = $this->insertConversation($userId);
        $msg    = $this->insertHtmlMessage($convId);

        $share = CreationShare::createOrGet((int)$msg['id'], $userId);
        $found = CreationShare::findByToken((string)$share['token']);

        $this->assertNotNull($found);
        $this->assertSame('html_page', Message::creationType($found));
        $this->assertSame('User 1', $found['shared_by']);
        $this->assertArrayNotHasKey('conversation_id', $found);
        $this->assertArrayNotHasKey('title', $found);
    }

    public function testForConversationRetourneLaMapMessageToken(): void
    {
        $userId = $this->insertUser(1);
        $convId = $this->insertConversation($userId);
        $msg1   = $this->insertHtmlMessage($convId);
        $msg2   = $this->insertHtmlMessage($convId);

        $share1 = CreationShare::createOrGet((int)$msg1['id'], $userId);

        $map = CreationShare::forConversation($convId);
        $this->assertSame([(int)$msg1['id'] => (string)$share1['token']], $map);
        $this->assertArrayNotHasKey((int)$msg2['id'], $map);
    }

    // ===================== Cascades =====================

    public function testSuppressionDuMessageEmporteLePartage(): void
    {
        $userId = $this->insertUser(1);
        $convId = $this->insertConversation($userId);
        $msg    = $this->insertHtmlMessage($convId);

        $share = CreationShare::createOrGet((int)$msg['id'], $userId);
        $this->db()->exec('DELETE FROM messages WHERE id = ' . (int)$msg['id']);

        $this->assertNull(CreationShare::findByToken((string)$share['token']));
    }

    public function testSuppressionDeLaConversationEmporteLePartage(): void
    {
        $userId = $this->insertUser(1);
        $convId = $this->insertConversation($userId);
        $msg    = $this->insertHtmlMessage($convId);

        $share = CreationShare::createOrGet((int)$msg['id'], $userId);
        $this->db()->exec('DELETE FROM conversations WHERE id = ' . $convId);

        $this->assertNull(CreationShare::findByToken((string)$share['token']));
    }
}
