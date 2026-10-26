<?php

declare(strict_types=1);

/**
 * Coûts → Budget, section « Dépenses », sur la vraie base : coût par
 * fournisseur (les deux tables), premier jour d'activité, données d'une
 * période (BudgetController::periodData) et rendu de la section seule
 * (`?fragment=period`).
 *
 * Les réglages ne sont pas vidés entre les tests (DatabaseTestCase) : un budget
 * oublié ici suspendrait les tests suivants. D'où le ménage en setUp ET tearDown.
 */
final class BudgetPeriodPipelineTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/BudgetController.php';
        $this->forgetBudget();
        I18n::use('fr');
        $this->db()->exec(
            "INSERT INTO chat_tools (id, name, type, provider, model) VALUES
                (1, 'Images', 'image', 'gemini', 'imagen'),
                (2, 'Présentations', 'gamma', 'gamma', 'presentation')"
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

    private function llmRow(string $slug, float $cost, string $at, int $exempt = 0, int $priced = 1): void
    {
        $this->db()->prepare(
            'INSERT INTO usage_logs (user_id, llm_provider, tokens_input, tokens_output, credits_used, cost_eur, quota_exempt, priced, created_at)
             VALUES (NULL, ?, 10, 10, 10, ?, ?, ?, ?)'
        )->execute([$slug, $cost, $exempt, $priced, $at]);
    }

    private function toolRow(int $toolId, float $cost, string $at): void
    {
        $this->db()->prepare(
            'INSERT INTO tool_usage_logs (user_id, tool_id, tool_type, credits_used, cost_eur, created_at)
             VALUES (NULL, ?, \'image\', 50, ?, ?)'
        )->execute([$toolId, $cost, $at]);
    }

    private static function periodData(array $query, DateTimeImmutable $now): array
    {
        $m = new ReflectionMethod(BudgetController::class, 'periodData');
        return $m->invoke(null, UsagePeriod::resolve($query, BudgetController::DEFAULT_PERIOD, $now), $now);
    }

    public function testCoutParFournisseurEtPremierJour(): void
    {
        $this->assertNull(UsageLog::firstActivityDay(), 'Grand livre vide');

        $this->llmRow('openai::gpt-5.6', 10.0, '2026-09-03 10:00:00');
        $this->llmRow('openai', 2.0, '2026-09-04 10:00:00');                 // ancien format, sans modèle
        $this->llmRow('anthropic::claude-opus-5', 30.0, '2026-09-05 10:00:00');
        $this->toolRow(1, 4.0, '2026-09-06 10:00:00');                       // outil Gemini
        $this->toolRow(2, 0.0, '2026-09-06 11:00:00');                       // Gamma : facturé ailleurs
        $this->db()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->toolRow(99, 1.5, '2026-09-07 10:00:00');                      // outil supprimé depuis
        $this->db()->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->llmRow('mistral::large', 99.0, '2026-08-31 23:59:59');        // hors période

        $rows = UsageLog::costByProvider('2026-09-01 00:00:00', '2026-10-01 00:00:00');
        $byProvider = array_column($rows, null, 'provider');
        $this->assertSame(['anthropic', 'openai', 'gemini', 'other', 'gamma'], array_column($rows, 'provider'), 'Classés par coût');
        $this->assertEqualsWithDelta(12.0, $byProvider['openai']['cost_eur'], 0.0001, 'Les deux formats de slug fusionnés');
        $this->assertSame(2, $byProvider['openai']['operations']);
        $this->assertEqualsWithDelta(4.0, $byProvider['gemini']['cost_eur'], 0.0001, 'Le fournisseur déclaré de l\'outil');
        $this->assertEqualsWithDelta(1.5, $byProvider['other']['cost_eur'], 0.0001);
        $this->assertArrayNotHasKey('mistral', $byProvider);

        $this->assertSame('2026-08-31', UsageLog::firstActivityDay());
    }

    public function testLesDonneesDUnePeriodeEtSaComparaison(): void
    {
        Settings::set(AiBudget::SETTING_MONTHLY, '310');
        $now = new DateTimeImmutable('2026-10-18 12:00:00');
        $this->llmRow('openai::gpt-5.6', 20.0, '2026-10-02 10:00:00');
        $this->llmRow('openai::gpt-5.6', 6.0, '2026-10-02 18:00:00', 1);    // maintenance
        $this->llmRow('anthropic::claude-opus-5', 14.0, '2026-10-17 09:00:00', 0, 0);
        $this->llmRow('openai::gpt-5.6', 50.0, '2026-09-10 10:00:00');      // mêmes jours du mois précédent
        $this->llmRow('openai::gpt-5.6', 500.0, '2026-09-25 10:00:00');     // après le 18 : hors comparaison

        $d = self::periodData([], $now);
        $this->assertSame('current_month', $d['period']['preset'], 'Mois en cours par défaut');
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-18'], $d['range']);
        $this->assertSame(AiBudget::GRAIN_DAY, $d['bars']['grain']);
        $this->assertCount(18, $d['bars']['bars']);
        $this->assertEqualsWithDelta(40.0, $d['summary']['total'], 0.001);
        $this->assertEqualsWithDelta(6.0, $d['summary']['maintenance_eur'], 0.001);
        $this->assertSame(1, $d['summary']['unpriced_ops']);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-18', 'total' => 50.0], $d['summary']['previous']);
        $this->assertEqualsWithDelta(-20.0, $d['summary']['evolution'], 0.001);
        $this->assertSame((int)floor(40 / (310 / 31 * 18) * 100), $d['summary']['budget_share']);
        $this->assertSame(['openai', 'anthropic'], array_column($d['providers']['rows'], 'provider'));
        $this->assertNotSame([], $d['breakdown']['rows']);

        $custom = self::periodData(['from' => '2026-09-01', 'to' => '2026-09-30'], $now);
        $this->assertTrue($custom['period']['custom']);
        $this->assertEqualsWithDelta(550.0, $custom['summary']['total'], 0.001);
        $this->assertSame(['from' => '2026-08-02', 'to' => '2026-08-31', 'total' => 0.0], $custom['summary']['previous']);

        $all = self::periodData(['preset' => 'all'], $now);
        $this->assertSame('2026-09-10', $all['range']['from'], 'Tout : depuis le premier jour d\'activité');
        $this->assertNull($all['summary']['previous']);
    }

    public function testLaSectionSeRendSeule(): void
    {
        Settings::set(AiBudget::SETTING_MONTHLY, '310');
        $now = new DateTimeImmutable('now');
        $this->llmRow('openai::gpt-5.6', 12.0, $now->format('Y-m-d') . ' 00:00:01');

        foreach ([[], ['preset' => 'last_12_months'], ['from' => $now->modify('-3 days')->format('Y-m-d'), 'to' => $now->format('Y-m-d')]] as $query) {
            $spend = self::periodData($query, $now);
            ob_start();
            require ROOT . '/templates/admin/budget-period.php';
            $html = (string)ob_get_clean();
            foreach (['id="depenses"', 'data-period-swap="#depenses"', 'class="period-preset', 'Personnalisée',
                      'class="bc-bar', 'Où part l\'argent', 'Par fournisseur', 'llm-openai.png'] as $attendu) {
                $this->assertStringContainsString($attendu, $html, json_encode($query) . " : $attendu");
            }
            $this->assertStringNotContainsString('<html', $html);
            $this->assertSame(1, substr_count($html, 'aria-current="true"'), 'Une seule période active');
        }
        $this->assertStringContainsString('<summary class="period-preset period-custom-toggle" aria-current="true">', $html, 'Plage personnalisée : le bouton est actif');
    }
}
