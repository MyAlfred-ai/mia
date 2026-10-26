<?php

declare(strict_types=1);

/**
 * Agrégats crédits de UsageLog (periodTotals / monthlySummary /
 * periodTotalsByUser / monthlyByUser) : fusion usage_logs + tool_usage_logs,
 * bornes de période, filtre utilisateur, bucket « Utilisateur supprimé ».
 */
final class UsageCreditAggregatesTest extends DatabaseTestCase
{
    private function seedFixtures(): void
    {
        $this->insertUser(1);
        $this->insertUser(2);

        // Outil requis par la FK tool_usage_logs.tool_id.
        $this->db()->exec(
            "INSERT INTO chat_tools (id, name, type, provider, model) VALUES (1, 'Outil test', 'image', 'openai', 'dall-e-3')"
        );

        $llm = $this->db()->prepare(
            'INSERT INTO usage_logs (user_id, llm_provider, tokens_input, tokens_output, credits_used, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        // Mai 2026
        $llm->execute([1, 'openai::gpt-5.5', 1000, 500, 100, '2026-05-10 10:00:00']);
        $llm->execute([2, 'anthropic', 300, 200, 30, '2026-05-20 12:00:00']);
        $llm->execute([null, 'mistral', 50, 50, 10, '2026-05-21 12:00:00']); // user supprimé
        // Borne haute inclusive (23:59:59)
        $llm->execute([1, 'openai', 100, 100, 5, '2026-05-31 23:30:00']);
        // Juin 2026
        $llm->execute([1, 'openai', 200, 100, 50, '2026-06-05 09:00:00']);

        $tool = $this->db()->prepare(
            'INSERT INTO tool_usage_logs (user_id, tool_id, tool_type, credits_used, created_at)
             VALUES (?, 1, ?, ?, ?)'
        );
        $tool->execute([1, 'image', 40, '2026-05-15 11:00:00']); // Mai
    }

    public function testPeriodTotalsFusionneLlmEtOutils(): void
    {
        $this->seedFixtures();
        $totals = UsageLog::periodTotals('2026-05-01', '2026-06-30');

        $this->assertSame(195, $totals['llm_credits']);   // 100+30+10+5+50
        $this->assertSame(40, $totals['tool_credits']);
        $this->assertSame(235, $totals['total_credits']);
        $this->assertSame(5, $totals['llm_requests']);
        $this->assertSame(1, $totals['tool_requests']);
        $this->assertSame(6, $totals['requests']);
        $this->assertSame(2, $totals['active_users']);    // user NULL ignoré
        $this->assertSame(2600, $totals['tokens']);       // 1500+500+100+200+300
    }

    public function testPeriodTotalsBorneHauteInclusive(): void
    {
        $this->seedFixtures();
        // La ligne du 31/05 23:30 doit être incluse avec to=2026-05-31.
        $mai = UsageLog::periodTotals('2026-05-01', '2026-05-31');
        $this->assertSame(145, $mai['llm_credits']);      // 100+30+10+5
        $this->assertSame(185, $mai['total_credits']);
    }

    public function testPeriodTotalsFiltreUtilisateur(): void
    {
        $this->seedFixtures();
        $totals = UsageLog::periodTotals('2026-05-01', '2026-06-30', 1);

        $this->assertSame(155, $totals['llm_credits']);   // 100+5+50
        $this->assertSame(40, $totals['tool_credits']);
        $this->assertSame(1, $totals['active_users']);
    }

    public function testMonthlySummary(): void
    {
        $this->seedFixtures();
        $rows = UsageLog::monthlySummary('2026-05-01', '2026-06-30');

        $this->assertCount(2, $rows);
        $this->assertSame('2026-05', $rows[0]['month']);
        $this->assertSame(145, $rows[0]['llm_credits']);
        $this->assertSame(40, $rows[0]['tool_credits']);
        $this->assertSame(185, $rows[0]['total_credits']);
        $this->assertSame(2, $rows[0]['active_users']);
        $this->assertSame('2026-06', $rows[1]['month']);
        $this->assertSame(50, $rows[1]['total_credits']);
        $this->assertSame(1, $rows[1]['active_users']);
    }

    public function testPeriodTotalsByUserAvecBucketSupprime(): void
    {
        $this->seedFixtures();
        $rows = UsageLog::periodTotalsByUser('2026-05-01', '2026-06-30');

        $this->assertCount(3, $rows);
        // Tri crédits DESC : user 1 (195), user 2 (30), supprimé (10).
        $this->assertSame(1, $rows[0]['user_id']);
        $this->assertSame(155, $rows[0]['llm_credits']);
        $this->assertSame(40, $rows[0]['tool_credits']);
        $this->assertSame(195, $rows[0]['total_credits']);
        $this->assertSame(4, $rows[0]['requests']);   // 3 requêtes LLM + 1 outil
        $this->assertSame('2026-06-05 09:00:00', $rows[0]['last_activity']);

        $this->assertSame(2, $rows[1]['user_id']);
        $this->assertSame(30, $rows[1]['total_credits']);

        // Bucket NULL : utilisateurs supprimés ET tâches de l'instance (seed de la
        // base système, sync sans propriétaire) — d'où le libellé double.
        $this->assertNull($rows[2]['user_id']);
        $this->assertSame('Instance / utilisateurs supprimés', $rows[2]['user_name']);
        $this->assertSame(10, $rows[2]['total_credits']);
    }

    public function testMonthlyByUser(): void
    {
        $this->seedFixtures();
        $rows = UsageLog::monthlyByUser('2026-05-01', '2026-06-30', 1);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-05', $rows[0]['month']);
        $this->assertSame(105, $rows[0]['llm_credits']);  // 100+5
        $this->assertSame(40, $rows[0]['tool_credits']);
        $this->assertSame(145, $rows[0]['total_credits']);
        $this->assertSame('2026-06', $rows[1]['month']);
        $this->assertSame(50, $rows[1]['total_credits']);
    }

    public function testPeriodeVideEtSansBornes(): void
    {
        $this->seedFixtures();

        $vide = UsageLog::periodTotals('2020-01-01', '2020-12-31');
        $this->assertSame(0, $vide['total_credits']);
        $this->assertSame(0, $vide['requests']);
        $this->assertSame(0, $vide['active_users']);

        // Sans bornes (preset « tout ») : tout l'historique.
        $tout = UsageLog::periodTotals();
        $this->assertSame(235, $tout['total_credits']);
        $this->assertSame([], UsageLog::monthlySummary('2020-01-01', '2020-12-31'));
    }
}
