<?php

declare(strict_types=1);

/**
 * Budget IA mensuel sur la vraie base : mesure du mois (deux tables, toutes
 * les lignes), barrière commune (CreditQuota), rallonge, mémo.
 *
 * Les réglages ne sont PAS vidés entre les tests (DatabaseTestCase) : un budget
 * oublié ici suspendrait les tests suivants. D'où le ménage en setUp ET tearDown.
 */
final class AiBudgetGateTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetBudget();
        $this->db()->exec(
            "INSERT INTO chat_tools (id, name, type, provider, model) VALUES (1, 'Outil test', 'image', 'openai', 'dall-e-3')"
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->forgetBudget();
        }
        parent::tearDown();
    }

    private function forgetBudget(): void
    {
        $this->db()->exec("DELETE FROM settings WHERE `key` LIKE 'ai_budget_%'");
        Settings::refresh();
    }

    private function llmRow(?int $userId, float $cost, int $exempt, string $at, int $priced = 1, int $credits = 10): void
    {
        $this->db()->prepare(
            'INSERT INTO usage_logs (user_id, llm_provider, tokens_input, tokens_output, credits_used, cost_eur, quota_exempt, priced, created_at)
             VALUES (?, \'openai\', 10, 10, ?, ?, ?, ?, ?)'
        )->execute([$userId, $credits, $cost, $exempt, $priced, $at]);
    }

    private function toolRow(?int $userId, float $cost, string $at): void
    {
        $this->db()->prepare(
            'INSERT INTO tool_usage_logs (user_id, tool_id, tool_type, credits_used, cost_eur, created_at)
             VALUES (?, 1, \'image\', 50, ?, ?)'
        )->execute([$userId, $cost, $at]);
    }

    private function thisMonth(string $dayTime): string
    {
        return date('Y-m-') . $dayTime;
    }

    public function testCostBetweenCompteToutesLesLignesDuMois(): void
    {
        $this->insertUser(1);
        $this->llmRow(1, 10.0, 0, '2026-05-01 00:00:00');          // borne basse incluse
        $this->llmRow(null, 2.5, 1, '2026-05-15 12:00:00');        // maintenance d'instance
        $this->llmRow(1, 1.0, 0, '2026-05-20 12:00:00', 0);        // sans tarif
        $this->toolRow(1, 4.0, '2026-05-31 23:59:59');             // dernière seconde du mois
        $this->llmRow(1, 99.0, 0, '2026-04-30 23:59:59');          // mois précédent : exclu
        $this->llmRow(1, 99.0, 0, '2026-06-01 00:00:00');          // mois suivant : exclu

        $cost = UsageLog::costBetween('2026-05-01 00:00:00', '2026-06-01 00:00:00');
        $this->assertEqualsWithDelta(15.0, $cost['usage_eur'], 0.0001);
        $this->assertEqualsWithDelta(2.5, $cost['maintenance_eur'], 0.0001);
        $this->assertSame(1, $cost['unpriced_ops']);
        $this->assertSame(4, $cost['operations']);
    }

    /** Trajectoire de la page Budget : un montant par jour, les deux tables, bornes du mois respectées. */
    public function testDailyCostBetweenRegroupeParJour(): void
    {
        $this->insertUser(1);
        $this->llmRow(1, 10.0, 0, '2026-05-01 00:00:00');
        $this->llmRow(null, 2.5, 1, '2026-05-01 18:00:00');       // maintenance : comptée aussi
        $this->toolRow(1, 4.0, '2026-05-01 23:59:59');
        $this->llmRow(1, 1.0, 0, '2026-05-20 12:00:00', 0);       // sans tarif : 1 € compté tel quel
        $this->llmRow(1, 99.0, 0, '2026-04-30 23:59:59');
        $this->llmRow(1, 99.0, 0, '2026-06-01 00:00:00');

        $days = UsageLog::dailyCostBetween('2026-05-01 00:00:00', '2026-06-01 00:00:00');
        $this->assertSame(['2026-05-01', '2026-05-20'], array_keys($days), 'Jours sans opération absents, ordre chronologique');
        $this->assertEqualsWithDelta(16.5, $days['2026-05-01'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $days['2026-05-20'], 0.0001);
        $cost = UsageLog::costBetween('2026-05-01 00:00:00', '2026-06-01 00:00:00');
        $this->assertEqualsWithDelta($cost['usage_eur'] + $cost['maintenance_eur'], array_sum($days), 0.0001, 'Même total que la jauge');
    }

    public function testSansBudgetAucuneBarriere(): void
    {
        $user = User::findById($this->insertUser(1));
        $this->llmRow(1, 5000.0, 0, $this->thisMonth('02 10:00:00'));
        $status = CreditQuota::status($user);
        $this->assertNull($status['reason']);
        $this->assertFalse(CreditQuota::isExceeded($status));
        $this->assertSame(AiBudget::STATE_OFF, AiBudget::status()['state']);
    }

    public function testBudgetAtteintBloqueToutLeMondeAdminsCompris(): void
    {
        $user  = User::findById($this->insertUser(1));
        $admin = User::findById($this->insertUser(2, 'admin'));
        $this->llmRow(1, 80.0, 0, $this->thisMonth('02 10:00:00'));
        $this->llmRow(null, 25.0, 1, $this->thisMonth('02 11:00:00')); // la maintenance compte aussi
        AiBudget::save(100.0, 80, AiBudget::ON_REACH_BLOCK);

        foreach ([$user, $admin] as $who) {
            $status = CreditQuota::status($who);
            $this->assertSame(CreditQuota::REASON_BUDGET, $status['reason']);
            $this->assertTrue(CreditQuota::isExceeded($status));
        }
        $this->assertTrue(AiBudget::blocksAutomaticSync());
    }

    public function testModeSignalementLaissePasser(): void
    {
        $user = User::findById($this->insertUser(1));
        $this->llmRow(1, 150.0, 0, $this->thisMonth('02 10:00:00'));
        AiBudget::save(100.0, 80, AiBudget::ON_REACH_WARN);

        $this->assertFalse(CreditQuota::isExceeded(CreditQuota::status($user)));
        $this->assertSame(AiBudget::STATE_REACHED, AiBudget::status(true)['state']);
    }

    public function testLeQuotaPersonnelEstLaRaisonPrioritaire(): void
    {
        $user = User::findById($this->insertUser(1));
        $this->db()->exec('UPDATE users SET credit_quota_k = 1 WHERE id = 1');
        $user = User::findById(1);
        $this->llmRow(1, 150.0, 0, $this->thisMonth('02 10:00:00'), 1, 2000);
        AiBudget::save(100.0, 80, AiBudget::ON_REACH_BLOCK);
        $this->assertSame(CreditQuota::REASON_QUOTA, CreditQuota::status($user)['reason']);
    }

    public function testLaRallongeDuMoisDebloqueCelleDunAutreMoisNon(): void
    {
        $user = User::findById($this->insertUser(1));
        $this->llmRow(1, 120.0, 0, $this->thisMonth('02 10:00:00'));
        AiBudget::save(100.0, 80, AiBudget::ON_REACH_BLOCK);
        $this->assertTrue(CreditQuota::isExceeded(CreditQuota::status($user)));

        AiBudget::grantExtra(50.0, 2);
        $this->assertFalse(CreditQuota::isExceeded(CreditQuota::status($user)), 'Rallonge du mois : débloqué');
        $this->assertSame(150.0, AiBudget::status(true)['limit_eur']);

        Settings::set(AiBudget::SETTING_EXTRA, json_encode(['month' => '2001-01', 'eur' => 500, 'by' => 2, 'at' => '2001-01-05 10:00:00']));
        AiBudget::forgetMemo();
        $this->assertTrue(CreditQuota::isExceeded(CreditQuota::status($user)), 'Rallonge d\'un autre mois : ignorée');

        AiBudget::grantExtra(50.0, 2);
        AiBudget::removeExtra(2);
        $this->assertTrue(CreditQuota::isExceeded(CreditQuota::status($user)));
    }

    public function testRetirerLeBudgetRetireLaRallonge(): void
    {
        AiBudget::save(100.0, 80, AiBudget::ON_REACH_BLOCK);
        AiBudget::grantExtra(50.0, 2);
        AiBudget::save(null, 80, AiBudget::ON_REACH_BLOCK);
        $this->assertNull(AiBudget::extra());
        $this->assertFalse(AiBudget::config()['enabled']);
    }

    public function testLeMemoEstReutiliseLoinDuPlafondEtRelupres(): void
    {
        $this->insertUser(1);
        AiBudget::save(100.0, 80, AiBudget::ON_REACH_BLOCK);
        $this->llmRow(1, 10.0, 0, $this->thisMonth('02 10:00:00'));
        $this->assertEqualsWithDelta(10.0, AiBudget::status()['spent_eur'], 0.001);

        // Nouvelle dépense : loin du plafond, le mémo (60 s) tient lieu de mesure.
        $this->llmRow(1, 30.0, 0, $this->thisMonth('02 11:00:00'));
        $this->assertEqualsWithDelta(10.0, AiBudget::status()['spent_eur'], 0.001);
        $this->assertEqualsWithDelta(40.0, AiBudget::status(true)['spent_eur'], 0.001, 'Lecture fraîche');

        // Zone critique (≥ 95 %) : relu à chaque appel.
        $this->llmRow(1, 56.0, 0, $this->thisMonth('02 12:00:00'));
        $this->assertEqualsWithDelta(96.0, AiBudget::status(true)['spent_eur'], 0.001);
        $this->llmRow(1, 5.0, 0, $this->thisMonth('02 13:00:00'));
        $this->assertTrue(AiBudget::status()['blocks'], 'Le mémo à 96 % n\'est pas réutilisé');

        // Une écriture de configuration efface le mémo.
        AiBudget::save(1000.0, 80, AiBudget::ON_REACH_BLOCK);
        $this->assertSame('', (string)Settings::get(AiBudget::SETTING_MEMO, ''));
    }
}
