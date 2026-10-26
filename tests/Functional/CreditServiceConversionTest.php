<?php

declare(strict_types=1);

/**
 * Conversion crédits → euros : brique unique CreditService::creditsToEur(),
 * pilotée par credits_config_json (Settings).
 */
final class CreditServiceConversionTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        // Ne pas toucher Settings si la base est indisponible (test skippé en setUp).
        if (self::$pdo !== null) {
            Settings::set('credits_config_json', '');
        }
        parent::tearDown();
    }

    public function testValeurParDefaut(): void
    {
        Settings::set('credits_config_json', '');
        $this->assertSame(0.001, CreditService::creditValueEur());
        $this->assertSame(1.5, CreditService::creditsToEur(1500));
    }

    public function testValeurConfiguree(): void
    {
        Settings::set('credits_config_json', json_encode([
            'version' => 1,
            'global' => ['credit_value_eur' => 0.002],
            'services' => [],
        ]));
        $this->assertSame(0.002, CreditService::creditValueEur());
        $this->assertSame(1.0, CreditService::creditsToEur(500));
        $this->assertSame(0.0, CreditService::creditsToEur(0));
    }
}
