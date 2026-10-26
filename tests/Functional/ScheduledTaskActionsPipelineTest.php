<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Tâches programmées, lot 2, sur la vraie base : une carte d'approbation a sa
 * PROPRE durée de vie (migration 082). Une carte ordinaire meurt au bout de
 * 30 minutes ; une carte déposée par une tâche programmée attend une semaine,
 * la personne n'étant pas là quand elle est née. Toutes les lectures de la
 * file (approbation, rechargement, liste des tâches, télémétrie) suivent la
 * même règle.
 */
final class ScheduledTaskActionsPipelineTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(1);
        $this->insertUser(2);
    }

    private function conversation(int $userId = 1): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([$userId, 'Test']);
        return (int)$this->db()->lastInsertId();
    }

    private function carte(int $convId, int $userId = 1, string $tool = 'creer_tache'): int
    {
        return DrivePendingAction::create($userId, $convId, $tool, ['titre' => 'A'], ['action' => 'Créer une tâche']);
    }

    /** Vieillit une carte : `created_at` reculé, comme si elle était née plus tôt. */
    private function vieillir(int $id, string $intervalle): void
    {
        $this->db()->exec('UPDATE drive_pending_actions SET created_at = NOW() - INTERVAL ' . $intervalle . ' WHERE id = ' . $id);
    }

    private function expiresAt(int $id): ?string
    {
        $v = $this->db()->query('SELECT expires_at FROM drive_pending_actions WHERE id = ' . $id)->fetchColumn();
        return $v === null || $v === false ? null : (string)$v;
    }

    public function testUneCarteOrdinaireMeurtAuBoutDeTrenteMinutes(): void
    {
        $conv = $this->conversation();
        $id = $this->carte($conv);
        $this->vieillir($id, (DrivePendingAction::TTL_MINUTES + 1) . ' MINUTE');

        $this->assertSame([], DrivePendingAction::pendingForConversation($conv, 1));
        $this->assertNull(DrivePendingAction::findPendingForUser($id, 1));
        $this->assertSame([], DrivePendingAction::countPendingByConversation([$conv], 1));
        $this->assertNull(DrivePendingAction::claim($id, 1, $conv));
    }

    public function testUneCarteDeTacheProgrammeeAttendUneSemaine(): void
    {
        $conv = $this->conversation();
        $id = $this->carte($conv);
        DrivePendingAction::extend($id, 1, ScheduledTask::ACTION_TTL_MINUTES);
        // Deux jours plus tard, elle est toujours là…
        $this->vieillir($id, '2 DAY');

        $this->assertCount(1, DrivePendingAction::pendingForConversation($conv, 1));
        $this->assertSame([$conv => 1], DrivePendingAction::countPendingByConversation([$conv], 1));
        $this->assertSame('creer_tache', DrivePendingAction::findPendingForUser($id, 1)['tool'] ?? null);
        // … et elle s'approuve comme une autre.
        $this->assertNotNull(DrivePendingAction::claim($id, 1, $conv));
    }

    public function testUneCarteEchueNeSApprouvePlus(): void
    {
        $conv = $this->conversation();
        $id = $this->carte($conv);
        // Née il y a une minute mais échue : son échéance propre fait foi.
        $this->db()->exec('UPDATE drive_pending_actions SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ' . $id);

        $this->assertSame([], DrivePendingAction::pendingForConversation($conv, 1));
        $this->assertNull(DrivePendingAction::findPendingForUser($id, 1));
        $this->assertNull(DrivePendingAction::claim($id, 1, $conv));
        $this->assertFalse(DrivePendingAction::updatePending($id, 1, $conv, ['titre' => 'B'], []));
    }

    public function testProlongerNeVautQuePourSaPropreCarteEnAttente(): void
    {
        $conv = $this->conversation();
        $autrui = $this->carte($this->conversation(2), 2);
        $tranchee = $this->carte($conv);
        DrivePendingAction::resolve($tranchee, 'executed');

        DrivePendingAction::extend($autrui, 1, ScheduledTask::ACTION_TTL_MINUTES);
        DrivePendingAction::extend($tranchee, 1, ScheduledTask::ACTION_TTL_MINUTES);

        $this->assertNull($this->expiresAt($autrui));
        $this->assertNull($this->expiresAt($tranchee));
    }

    public function testAjusterRetrouveLaCarteSansConnaitreSaConversation(): void
    {
        $conv = $this->conversation();
        $id = $this->carte($conv, 1, 'programmer_tache');

        $carte = DrivePendingAction::findPendingForUser($id, 1);
        $this->assertSame('programmer_tache', $carte['tool'] ?? null);
        $this->assertSame($conv, (int)$carte['conversation_id']);
        $this->assertNull(DrivePendingAction::findPendingForUser($id, 2), 'Jamais la carte d’autrui.');

        DrivePendingAction::resolve($id, 'cancelled');
        $this->assertNull(DrivePendingAction::findPendingForUser($id, 1), 'Une carte tranchée ne s’ajuste plus.');
    }

    public function testLaListeDesTachesCompteLesActionsEnAttenteParConversation(): void
    {
        $a = $this->conversation();
        $b = $this->conversation();
        $vide = $this->conversation();
        $this->carte($a);
        $this->carte($a);
        DrivePendingAction::resolve($this->carte($a), 'executed');
        $this->vieillir($this->carte($a), '1 HOUR');
        $this->carte($b);
        $this->carte($b, 2); // l'action d'autrui ne compte pas

        $this->assertSame([$a => 2, $b => 1], DrivePendingAction::countPendingByConversation([$a, $b, $vide, 0, -4], 1));
        $this->assertSame([], DrivePendingAction::countPendingByConversation([], 1));
    }

    public function testLaTelemetrieSuitLaDureeDeViePropreDesCartes(): void
    {
        $conv = $this->conversation();
        $prolongee = $this->carte($conv, 1, 'creer_brouillon_gmail');
        DrivePendingAction::extend($prolongee, 1, ScheduledTask::ACTION_TTL_MINUTES);
        $this->vieillir($prolongee, '2 DAY');
        $oubliee = $this->carte($conv, 1, 'creer_tache');
        $this->vieillir($oubliee, '1 HOUR');

        $parOutil = array_column(AgentTelemetry::cards(30), null, 'tool');
        $this->assertSame(1, $parOutil['creer_brouillon_gmail']['pending'], 'Prolongée : toujours en attente.');
        $this->assertSame(0, $parOutil['creer_brouillon_gmail']['expired']);
        $this->assertSame(1, $parOutil['creer_tache']['expired'], 'Ordinaire, vieille d’une heure : sans décision.');
    }
}
