<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Mémoire des objets connecteurs, sur base réelle.
 *
 * Ce que ces tests protègent : le scénario qui échouait avant, à savoir
 * « lis ce document » puis, AU MESSAGE SUIVANT, « ajoute-lui une conclusion ».
 * Les résultats d'outils n'étant pas réinjectés dans l'historique, l'identifiant
 * du fichier n'existait plus nulle part — d'où une recherche à l'aveugle, ou un
 * id inventé.
 */
final class ConnectorContextPipelineTest extends DatabaseTestCase
{
    private function conversation(string $titre = 'Test'): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (1, ?)')->execute([$titre]);
        return (int)$this->db()->lastInsertId();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(1);
    }

    public function testUnObjetLuAuTourPrecedentResteDesignableAuSuivant(): void
    {
        $conv = $this->conversation();

        // Tour 1 : le modèle lit un Doc désigné par un nom approximatif.
        ConnectorContext::recordToolCall($conv, 'lire_google_drive', ['fichier' => 'budget'], [
            'id' => '1a2B3c', 'nom' => 'Budget 2026', 'type' => 'Google Doc', 'contenu' => '…',
        ]);

        // Tour 2 : « ajoute-lui une conclusion ». Le fragment porte l'identifiant.
        $fragment = ConnectorContext::promptFragment($conv, ['drive']);
        $this->assertStringContainsString('1a2B3c', $fragment);
        $this->assertStringContainsString('Budget 2026', $fragment);
    }

    public function testLeMemeObjetRevuNEstPasDuplique(): void
    {
        $conv = $this->conversation();
        foreach (['Budget 2026', 'Budget 2026 (renommé)'] as $nom) {
            ConnectorContext::record($conv, 'drive_file', '1a2B3c', $nom, 'Google Doc');
        }

        $rows = ConnectorContext::forConversation($conv);
        $this->assertCount(1, $rows, 'Revoir un fichier le rafraîchit, il ne s\'empile pas.');
        // Le nom suit un renommage : citer l'ancien tromperait l'utilisateur.
        $this->assertSame('Budget 2026 (renommé)', $rows[0]['name']);
    }

    public function testLObjetLePlusRecemmentToucheEstEnTete(): void
    {
        $conv = $this->conversation();
        ConnectorContext::record($conv, 'drive_file', 'ancien', 'Vieux doc', 'Google Doc');
        // updated_at est en secondes : sans décalage, l'ordre viendrait de l'id.
        $this->db()->prepare(
            'UPDATE conversation_connector_refs SET updated_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE ref_id = ?'
        )->execute(['ancien']);
        ConnectorContext::record($conv, 'drive_file', 'recent', 'Doc du jour', 'Google Doc');

        $rows = ConnectorContext::forConversation($conv);
        $this->assertSame('recent', $rows[0]['ref_id'], '« ce document » désigne le plus récent.');
    }

    public function testLaMemoireEstBorneeEtGardeLesPlusRecents(): void
    {
        $conv = $this->conversation();
        for ($i = 0; $i < ConnectorContext::MAX_ENTRIES + 6; $i++) {
            ConnectorContext::record($conv, 'drive_file', 'id' . $i, 'Doc ' . $i, 'Google Doc');
            // Les enregistrements se suivent dans la même seconde : on étage les
            // horodatages pour que « le plus récent » ait un sens.
            $this->db()->prepare(
                'UPDATE conversation_connector_refs SET updated_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE ref_id = ?'
            )->execute([ConnectorContext::MAX_ENTRIES + 6 - $i, 'id' . $i]);
        }

        $rows = ConnectorContext::forConversation($conv);
        $this->assertLessThanOrEqual(ConnectorContext::MAX_ENTRIES, count($rows));
        $refs = array_column($rows, 'ref_id');
        $this->assertContains('id' . (ConnectorContext::MAX_ENTRIES + 5), $refs, 'Le dernier objet touché doit survivre.');
        $this->assertNotContains('id0', $refs, 'Les plus anciens sortent.');
    }

    public function testLaMemoireEstCloisonneeParConversation(): void
    {
        $a = $this->conversation('A');
        $b = $this->conversation('B');
        ConnectorContext::record($a, 'drive_file', 'docA', 'Doc de A', 'Google Doc');

        $this->assertSame('', ConnectorContext::promptFragment($b, ['drive']), 'Aucune fuite d\'une conversation à l\'autre.');
        $this->assertStringContainsString('docA', ConnectorContext::promptFragment($a, ['drive']));
    }

    public function testUneConversationSupprimeeEmporteSaMemoire(): void
    {
        $conv = $this->conversation();
        ConnectorContext::record($conv, 'drive_file', '1a2B3c', 'Budget 2026', 'Google Doc');
        $this->db()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$conv]);

        $this->assertSame(
            0,
            (int)$this->db()->query('SELECT COUNT(*) FROM conversation_connector_refs')->fetchColumn(),
            'La cascade doit nettoyer : sinon la table enfle sans jamais être purgée.'
        );
    }

    public function testUnObjetSupprimeEstOublie(): void
    {
        $conv = $this->conversation();
        ConnectorContext::record($conv, 'task', 't42', 'Relancer le fournisseur', 'Perso');
        ConnectorContext::forget($conv, 'task', 't42');

        $this->assertSame('', ConnectorContext::promptFragment($conv, ['tasks']),
            'Proposer l\'identifiant d\'une tâche effacée enverrait le modèle sur un 404 opaque.');
    }

    public function testLeFragmentNeCiteQueLesConnecteursEngages(): void
    {
        $conv = $this->conversation();
        ConnectorContext::record($conv, 'drive_file', '1a2B3c', 'Budget 2026', 'Google Doc');
        ConnectorContext::record($conv, 'gmail_msg', '18f2c', 'Devis chantier', 'alice@ex.fr');

        $drive = ConnectorContext::promptFragment($conv, ['drive']);
        $this->assertStringContainsString('1a2B3c', $drive);
        $this->assertStringNotContainsString('18f2c', $drive);
    }

    public function testUneCaptureImpossibleNeFaitJamaisEchouerLAppelant(): void
    {
        // Conversation inexistante : la FK refuse l'insertion. Le chat ne doit
        // pas tomber pour autant — la mémoire est un confort, pas une dépendance.
        ConnectorContext::record(999999, 'drive_file', '1a2B3c', 'Budget 2026', 'Google Doc');
        $this->assertSame([], ConnectorContext::forConversation(999999));
    }
}
