<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Ce qu'une action a réellement fait, relu après coup (migration 092), sur
 * base réelle : un email ENVOYÉ ou rangé en brouillon, le lien vers le
 * résultat, et le contenu entier (les arguments) que l'historique d'un
 * workflow déplie.
 */
final class PendingActionOutcomeTest extends DatabaseTestCase
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

    public function testLIssueEtLeLienSontGardes(): void
    {
        $conv = $this->conversation();
        $corps = str_repeat('Une phrase du message. ', 200) . 'FIN';
        $sent = DrivePendingAction::create(1, $conv, 'creer_brouillon_gmail', ['destinataire' => 'a@x.fr', 'objet' => 'Devis', 'corps' => $corps], ['action' => 'Nouvel email Gmail', 'apercu' => 'Une phrase…']);
        $draft = DrivePendingAction::create(1, $conv, 'creer_brouillon_outlook', ['destinataire' => 'b@x.fr', 'corps' => 'Bonjour'], ['action' => 'Nouvel email Outlook']);
        $doc = DrivePendingAction::create(1, $conv, 'creer_google_doc', ['titre' => 'CR', 'contenu' => '# CR'], ['action' => 'Créer un Google Doc']);
        $refused = DrivePendingAction::create(1, $conv, 'envoyer_message_slack', ['canal' => '#x', 'texte' => 'y'], ['action' => 'Publier un message Slack']);

        DrivePendingAction::resolve($sent, 'executed', null, DrivePendingAction::OUTCOME_SENT, 'https://mail.google.com/mail/u/0/#sent/1');
        DrivePendingAction::resolve($draft, 'executed', null, DrivePendingAction::OUTCOME_DRAFT, 'https://outlook.office.com/mail/drafts/id/2');
        DrivePendingAction::resolve($doc, 'executed', null, 'inconnu', 'javascript:alert(1)');
        DrivePendingAction::resolve($refused, 'cancelled');

        $cards = DrivePendingAction::journalForConversation($conv, 1);
        $this->assertSame('sent', $cards[$sent]['outcome']);
        $this->assertSame('https://mail.google.com/mail/u/0/#sent/1', $cards[$sent]['url']);
        $this->assertStringEndsWith('FIN', $cards[$sent]['args']['corps'], 'Le corps entier, pas l’extrait de la carte.');
        $this->assertSame('draft', $cards[$draft]['outcome']);
        $this->assertNull($cards[$doc]['outcome'], 'Une issue inconnue n’est pas écrite.');
        $this->assertNull($cards[$doc]['url'], 'Un lien qui n’est pas http(s) n’est pas écrit.');
        $this->assertNull($cards[$refused]['outcome']);
        $this->assertSame('# CR', $cards[$doc]['args']['contenu']);

        $line = WorkflowJournal::cardLine($cards[$sent]);
        $this->assertSame('Envoyé', $line['state']);
        $this->assertStringContainsString('a@x.fr', $line['view']);
        $this->assertStringContainsString('FIN', $line['view']);
        $this->assertSame('Brouillon créé', WorkflowJournal::cardLine($cards[$draft])['state']);
    }

    public function testLeJournalNeMontreQueLesActionsDeLaPersonne(): void
    {
        $conv = $this->conversation();
        DrivePendingAction::create(2, $conv, 'creer_brouillon_gmail', ['corps' => 'secret'], []);
        $this->assertSame([], DrivePendingAction::journalForConversation($conv, 1));
    }
}
