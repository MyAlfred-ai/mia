<?php

declare(strict_types=1);

/**
 * Comptes de démonstration sur la vraie base : décompte depuis le début de la
 * démo (ce qui entame un quota seulement), barrière commune, conversion,
 * alerte masquable rallumée par une nouvelle enveloppe, message de fin.
 *
 * Les réglages ne sont PAS vidés entre les tests (DatabaseTestCase) : adresse
 * de contact et budget nettoyés en setUp ET tearDown.
 */
final class DemoAccountPipelineTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetSettings();
        $this->db()->exec(
            "INSERT INTO chat_tools (id, name, type, provider, model) VALUES (1, 'Outil test', 'image', 'openai', 'dall-e-3')"
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->forgetSettings();
        }
        parent::tearDown();
    }

    private function forgetSettings(): void
    {
        $this->db()->exec("DELETE FROM settings WHERE `key` LIKE 'ai_budget_%' OR `key` IN ('contact_email', 'vendor_name')");
        Settings::refresh();
    }

    private function llmRow(int $userId, int $credits, string $at, int $exempt = 0): void
    {
        $this->db()->prepare(
            'INSERT INTO usage_logs (user_id, llm_provider, tokens_input, tokens_output, credits_used, cost_eur, quota_exempt, priced, created_at)
             VALUES (?, \'openai\', 10, 10, ?, 0.01, ?, 1, ?)'
        )->execute([$userId, $credits, $exempt, $at]);
    }

    private function toolRow(int $userId, int $credits, string $at): void
    {
        $this->db()->prepare(
            'INSERT INTO tool_usage_logs (user_id, tool_id, tool_type, credits_used, cost_eur, created_at)
             VALUES (?, 1, \'image\', ?, 0.01, ?)'
        )->execute([$userId, $credits, $at]);
    }

    private function setStart(int $userId, string $at): void
    {
        $this->db()->prepare('UPDATE users SET demo_started_at = ? WHERE id = ?')->execute([$at, $userId]);
    }

    private function user(int $id): array
    {
        $u = User::findById($id);
        $this->assertNotNull($u);
        return $u;
    }

    public function testLeDecompteNeCompteQueCeQuiSuitLeDebutDeLaDemo(): void
    {
        $this->insertUser(1);
        DemoAccount::start(1, 5_000);
        $this->setStart(1, '2026-01-10 12:00:00');

        $this->llmRow(1, 900, '2026-01-10 11:59:59');           // avant la démo : ignoré
        $this->llmRow(1, 1_000, '2026-01-10 12:00:00');         // borne incluse
        $this->llmRow(1, 7_000, '2026-01-11 09:00:00', 1);      // hors quota (bases) : ignoré
        $this->toolRow(1, 500, '2026-02-20 09:00:00');          // un autre mois : compté (aucun renouvellement)

        $user = $this->user(1);
        $this->assertSame(1_500, DemoAccount::usedCredits($user));

        $status = CreditQuota::status($user);
        $this->assertTrue($status['demo']);
        $this->assertSame(5_000, $status['quota_max']);
        $this->assertSame(3_500, $status['remaining']);
        $this->assertFalse(CreditQuota::isExceeded($status));
    }

    public function testLEnveloppeRemplaceLeQuotaMensuelEtBloqueUneFoisEpuisee(): void
    {
        $this->insertUser(1);
        // Quota mensuel individuel généreux : sans effet pendant la démo.
        $this->db()->exec('UPDATE users SET credit_quota_k = 1000 WHERE id = 1');
        DemoAccount::start(1, 2_000);
        $this->setStart(1, '2026-01-01 00:00:00');
        $this->llmRow(1, 2_000, '2026-01-05 10:00:00');

        $status = CreditQuota::status($this->user(1));
        $this->assertSame(CreditQuota::REASON_QUOTA, $status['reason']);
        $this->assertTrue(CreditQuota::isExceeded($status));

        // Message de fin : l'adresse de contact de l'instance.
        Settings::set('contact_email', 'contact@myalfred.ai');
        $refus = CreditQuota::refusal($status);
        $this->assertSame('Votre démo de MIA est terminée', $refus['title']);
        $this->assertStringContainsString('contact@myalfred.ai', $refus['message']);

        // Une adresse invalide n'est jamais citée : l'éditeur à la place.
        Settings::set('contact_email', 'pas-une-adresse');
        Settings::set('vendor_name', 'MyAlfred.ai');
        $this->assertSame('', DemoAccount::contactEmail());
        $this->assertStringContainsString('l’équipe MyAlfred.ai', CreditQuota::refusal($status)['message']);
    }

    public function testLaConversionGardeLHistoriqueEtRendLeQuotaMensuel(): void
    {
        $this->insertUser(1);
        $this->db()->exec('UPDATE users SET credit_quota_k = 50 WHERE id = 1');
        DemoAccount::start(1, 100);
        $this->setStart(1, date('Y-m-01 00:00:00'));
        $this->llmRow(1, 100, date('Y-m-d H:i:s'));
        $this->assertTrue(CreditQuota::isExceeded(CreditQuota::status($this->user(1))));

        DemoAccount::convert(1);
        $user = $this->user(1);
        $this->assertFalse(DemoAccount::isDemo($user));
        $this->assertNull($user['demo_started_at']);
        $status = CreditQuota::status($user);
        $this->assertFalse($status['demo']);
        $this->assertSame(50_000, $status['quota_max'], 'Son quota individuel reprend');
        $this->assertFalse(CreditQuota::isExceeded($status));
        $this->assertSame(1, (int)$this->db()->query('SELECT COUNT(*) FROM usage_logs WHERE user_id = 1')->fetchColumn(), 'Historique conservé');
    }

    public function testListeDesDemosEtAlerteMasquable(): void
    {
        $this->insertUser(1);
        $this->insertUser(2);
        $this->insertUser(3);
        DemoAccount::start(1, 1_000);
        DemoAccount::start(2, 1_000);
        foreach ([1, 2] as $id) {
            $this->setStart($id, '2026-01-01 00:00:00');
        }
        $this->llmRow(1, 1_200, '2026-01-02 00:00:00');   // épuisée
        $this->llmRow(2, 300, '2026-01-02 00:00:00');     // en cours
        $this->llmRow(3, 9_999, '2026-01-02 00:00:00');   // compte ordinaire : absent

        $accounts = DemoAccount::accounts();
        $this->assertSame([1, 2], array_keys($accounts));
        $this->assertTrue($accounts[1]['exhausted']);
        $this->assertSame(0, $accounts[1]['remaining']);
        $this->assertFalse($accounts[2]['exhausted']);
        $this->assertSame(700, $accounts[2]['remaining']);

        $signals = AdminAttention::demoSignals(array_values($accounts));
        $this->assertCount(1, $signals);
        $this->assertStringContainsString('User 1', $signals[0]['text']);

        DemoAccount::setAlertMuted(1, true);
        $this->assertSame([], AdminAttention::demoSignals(array_values(DemoAccount::accounts())));

        // Même enveloppe : l'alerte reste masquée.
        DemoAccount::setCredits(1, 1_000);
        $this->assertTrue(DemoAccount::accounts()[1]['muted']);

        // Nouvelle enveloppe : l'alerte se rallume quand la démo s'épuise de nouveau.
        DemoAccount::setCredits(1, 1_100);
        $after = DemoAccount::accounts()[1];
        $this->assertFalse($after['muted']);
        $this->assertSame(1_100, $after['credits']);
        $this->assertTrue($after['exhausted']);
        $this->assertCount(1, AdminAttention::demoSignals(array_values(DemoAccount::accounts())));
    }

    public function testUnCompteOrdinaireNeBougePas(): void
    {
        $this->insertUser(1);
        DemoAccount::setCredits(1, 5_000);   // sans démo : aucun effet
        DemoAccount::setAlertMuted(1, true);
        $user = $this->user(1);
        $this->assertFalse(DemoAccount::isDemo($user));
        $this->assertSame(0, (int)$user['demo_alert_muted']);
        $this->assertSame([], DemoAccount::accounts());
    }
}
