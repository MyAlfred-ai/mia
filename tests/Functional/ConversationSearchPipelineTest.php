<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Recherche dans les anciennes conversations, sur base réelle.
 *
 * Ce que ces tests protègent : le cloisonnement (jamais la conversation d'un
 * autre compte, jamais celle en cours), l'exclusion des réponses en erreur, la
 * collation (accents ignorés) et la complétion « au moins un mot » quand
 * « tous les mots » ne suffit pas.
 */
final class ConversationSearchPipelineTest extends DatabaseTestCase
{
    private function conversation(int $userId, string $titre): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([$userId, $titre]);
        return (int)$this->db()->lastInsertId();
    }

    private function message(int $convId, string $role, string $content): void
    {
        $this->db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)')
            ->execute([$convId, $role, $content]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(1);
        $this->insertUser(2);
    }

    public function testSeulesLesAutresConversationsDuCompteSontFouillees(): void
    {
        $courante = $this->conversation(1, 'En cours');
        $this->message($courante, 'user', 'Parlons du plan marketing');
        $ancienne = $this->conversation(1, 'Lancement produit');
        $this->message($ancienne, 'assistant', 'Le plan marketing prévoit trois vagues de communication.');
        $autrui = $this->conversation(2, 'Plan marketing concurrent');
        $this->message($autrui, 'assistant', 'Le plan marketing de quelqu\'un d\'autre.');

        $r = ConversationSearch::search(1, $courante, 'plan marketing');
        $this->assertSame(['/chat/' . $ancienne], array_column($r['conversations'], 'lien'));
        $this->assertStringContainsString('trois vagues', $r['conversations'][0]['extraits'][0]['texte']);
    }

    public function testLesAccentsSontIgnoresEtLesErreursEcartees(): void
    {
        $conv = $this->conversation(1, 'Réunion');
        $this->message($conv, 'assistant', "⚠️ Service saturé\n\nLe fournisseur ne répond pas (réunion budgétaire).");
        $this->message($conv, 'assistant', 'La réunion budgétaire est fixée au mardi.');

        $r = ConversationSearch::search(1, 0, 'reunion budgetaire');
        $this->assertCount(1, $r['conversations']);
        $this->assertCount(1, $r['conversations'][0]['extraits'], 'Une réponse en erreur n\'est pas un souvenir.');
        $this->assertStringContainsString('mardi', $r['conversations'][0]['extraits'][0]['texte']);
    }

    public function testLeTitreCompteEtTousLesMotsPassentDevant(): void
    {
        $parTitre = $this->conversation(1, 'Devis Globex');
        $this->message($parTitre, 'user', 'Peux-tu relire le document ?');
        $unSeulMot = $this->conversation(1, 'Divers');
        $this->message($unSeulMot, 'assistant', 'Le devis est prêt.');

        $r = ConversationSearch::search(1, 0, 'devis globex');
        $liens = array_column($r['conversations'], 'lien');
        $this->assertSame('/chat/' . $parTitre, $liens[0], 'Tous les mots (titre compris) d\'abord.');
        $this->assertContains('/chat/' . $unSeulMot, $liens, 'Puis la complétion « au moins un mot ».');
    }

    public function testLesJokersSQLDeLaRequeteNeMatchentRienDAutre(): void
    {
        $conv = $this->conversation(1, 'Taux');
        $this->message($conv, 'assistant', 'Le taux de conversion est de 3 pour cent.');
        $this->assertSame([], ConversationSearch::search(1, 0, '100%_')['conversations'] ?? []);
    }
}
