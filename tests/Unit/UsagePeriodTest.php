<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Périodes d'analyse partagées par Coûts → Budget et Coûts → Consommation
 * (UsagePeriod, pur) : catalogue, résolution, comparaison, libellés.
 */
final class UsagePeriodTest extends TestCase
{
    private static function today(string $day = '2026-10-18'): DateTimeImmutable
    {
        return new DateTimeImmutable($day . ' 15:30:00');
    }

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    public function testLeCatalogueGardeLesTranchesDeConsommation(): void
    {
        // Les cinq tranches historiques de Consommation (liens et favoris déjà en circulation).
        foreach (['current_month', 'last_30_days', 'last_12_months', 'year_to_date', 'all'] as $key) {
            $this->assertTrue(UsagePeriod::isPreset($key), $key);
        }
        $this->assertTrue(UsagePeriod::isPreset('last_month'));
        $this->assertTrue(UsagePeriod::isPreset('last_7_days'));
        $this->assertFalse(UsagePeriod::isPreset('custom'));
        $this->assertSame('current_month', array_key_first(UsagePeriod::PRESETS), 'Le calendrier d\'abord');
        foreach (UsagePeriod::PRESETS as $key => $p) {
            $this->assertNotSame('', $p['short'], $key);
            $this->assertLessThanOrEqual(13, mb_strlen($p['short']), "$key : un segment reste court");
            $this->assertNotSame('', $p['label'], $key);
        }
    }

    /** @return array<string, array{0:string, 1:?string, 2:?string}> */
    public static function presets(): array
    {
        return [
            'mois en cours'    => ['current_month', '2026-10-01', '2026-10-18'],
            'mois dernier'     => ['last_month', '2026-09-01', '2026-09-30'],
            '7 jours'          => ['last_7_days', '2026-10-12', '2026-10-18'],
            '30 jours'         => ['last_30_days', '2026-09-19', '2026-10-18'],
            '12 mois'          => ['last_12_months', '2025-11-01', '2026-10-18'],
            'année'            => ['year_to_date', '2026-01-01', '2026-10-18'],
            'tout'             => ['all', null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('presets')]
    public function testChaqueTrancheDonneSesBornes(string $preset, ?string $from, ?string $to): void
    {
        $p = UsagePeriod::resolve(['preset' => $preset], 'current_month', self::today());
        $this->assertSame($preset, $p['preset']);
        $this->assertFalse($p['custom']);
        $this->assertSame($from, $p['from']);
        $this->assertSame($to, $p['to']);
    }

    public function testLeMoisDernierEnMarsNeDebordePas(): void
    {
        $p = UsagePeriod::resolve(['preset' => 'last_month'], 'current_month', self::today('2026-03-31'));
        $this->assertSame(['2026-02-01', '2026-02-28'], [$p['from'], $p['to']]);
        $p = UsagePeriod::resolve(['preset' => 'last_month'], 'current_month', self::today('2026-01-15'));
        $this->assertSame(['2025-12-01', '2025-12-31'], [$p['from'], $p['to']], 'Changement d\'année');
    }

    public function testSansRienLeDefautDeLaPage(): void
    {
        $this->assertSame('current_month', UsagePeriod::resolve([], 'current_month', self::today())['preset'], 'Budget');
        $this->assertSame('last_12_months', UsagePeriod::resolve([], 'last_12_months', self::today())['preset'], 'Consommation');
        $this->assertSame('current_month', UsagePeriod::resolve(['preset' => 'n_importe'], 'n_importe', self::today())['preset']);
    }

    public function testPlagePersonnalisee(): void
    {
        $p = UsagePeriod::resolve(['from' => '2026-09-03', 'to' => '2026-09-17'], 'current_month', self::today());
        $this->assertTrue($p['custom']);
        $this->assertSame('', $p['preset']);
        $this->assertSame(['2026-09-03', '2026-09-17'], [$p['from'], $p['to']]);

        $inv = UsagePeriod::resolve(['from' => '2026-09-17', 'to' => '2026-09-03'], 'current_month', self::today());
        $this->assertSame(['2026-09-03', '2026-09-17'], [$inv['from'], $inv['to']], 'Bornes remises dans l\'ordre');

        $one = UsagePeriod::resolve(['from' => '2026-09-03'], 'current_month', self::today());
        $this->assertSame(['2026-09-03', '2026-09-03'], [$one['from'], $one['to']], 'Une borne vaut les deux');

        $future = UsagePeriod::resolve(['from' => '2026-10-10', 'to' => '2027-01-01'], 'current_month', self::today());
        $this->assertSame(['2026-10-10', '2026-10-18'], [$future['from'], $future['to']], 'Rien après aujourd\'hui');

        $allFuture = UsagePeriod::resolve(['from' => '2027-01-01', 'to' => '2027-02-01'], 'current_month', self::today());
        $this->assertSame(['2026-10-18', '2026-10-18'], [$allFuture['from'], $allFuture['to']]);

        $bad = UsagePeriod::resolve(['from' => '2026-02-30', 'to' => 'demain'], 'current_month', self::today());
        $this->assertSame('current_month', $bad['preset'], 'Dates invalides : la tranche par défaut');

        $this->assertSame('preset', array_key_first(UsagePeriod::query(UsagePeriod::resolve(['preset' => 'last_month'], 'x', self::today()))));
        $this->assertSame(['from' => '2026-09-03', 'to' => '2026-09-17'], UsagePeriod::query($p));
    }

    public function testToutCommenceAuPremierJourDActivite(): void
    {
        $all = UsagePeriod::resolve(['preset' => 'all'], 'current_month', self::today());
        $this->assertSame(['from' => '2026-03-04', 'to' => '2026-10-18'], UsagePeriod::bounded($all, '2026-03-04 09:12:00', self::today()));
        $this->assertSame(['from' => '2026-10-18', 'to' => '2026-10-18'], UsagePeriod::bounded($all, null, self::today()), 'Aucune activité : aujourd\'hui');
        $month = UsagePeriod::resolve(['preset' => 'current_month'], 'x', self::today());
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-18'], UsagePeriod::bounded($month, '2020-01-01', self::today()));
        $this->assertSame(18, UsagePeriod::days('2026-10-01', '2026-10-18'));
        $this->assertSame(1, UsagePeriod::days('2026-10-01', '2026-10-01'));
    }

    public function testPeriodeDeComparaison(): void
    {
        $at = self::today();
        $prev = static function (array $q) use ($at): ?array {
            $p = UsagePeriod::resolve($q, 'current_month', $at);
            $r = UsagePeriod::bounded($p, '2024-01-01', $at);
            return UsagePeriod::previous($p, $r['from'], $r['to']);
        };

        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-18'], $prev(['preset' => 'current_month']), 'Mêmes jours du mois précédent');
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-31'], $prev(['preset' => 'last_month']), 'Le mois d\'avant, entier');
        $this->assertSame(['from' => '2025-01-01', 'to' => '2025-10-18'], $prev(['preset' => 'year_to_date']), 'Même moment de l\'année précédente');
        $this->assertSame(['from' => '2026-10-05', 'to' => '2026-10-11'], $prev(['preset' => 'last_7_days']), 'Fenêtre glissante : juste avant');
        $this->assertSame(['from' => '2026-08-03', 'to' => '2026-09-02'], $prev(['from' => '2026-09-03', 'to' => '2026-10-03']));
        $this->assertNull($prev(['preset' => 'all']), 'Depuis le début : rien avant');

        // 31 mars : le mois précédent n'a que 28 jours.
        $mar = UsagePeriod::resolve(['preset' => 'current_month'], 'x', self::today('2026-03-31'));
        $this->assertSame(['from' => '2026-02-01', 'to' => '2026-02-28'], UsagePeriod::previous($mar, '2026-03-01', '2026-03-31'));
        // 29 février : l'année précédente s'arrête au 28.
        $leap = UsagePeriod::resolve(['preset' => 'year_to_date'], 'x', self::today('2028-02-29'));
        $this->assertSame(['from' => '2027-01-01', 'to' => '2027-02-28'], UsagePeriod::previous($leap, '2028-01-01', '2028-02-29'));
    }

    public function testLibellesDePlage(): void
    {
        $nb = "\u{00A0}";
        $this->assertSame("5{$nb}oct.{$nb}2026", UsagePeriod::rangeLabel('2026-10-05', '2026-10-05'));
        $this->assertSame("1 – 18{$nb}oct.{$nb}2026", UsagePeriod::rangeLabel('2026-10-01', '2026-10-18'));
        $this->assertSame("15{$nb}sept. – 3{$nb}oct.{$nb}2026", UsagePeriod::rangeLabel('2026-09-15', '2026-10-03'));
        $this->assertSame("15{$nb}déc.{$nb}2025 – 3{$nb}janv.{$nb}2026", UsagePeriod::rangeLabel('2025-12-15', '2026-01-03'));
        $this->assertSame('Plage personnalisée', UsagePeriod::label(['preset' => '', 'custom' => true]));
        $this->assertSame('12 derniers mois', UsagePeriod::label(['preset' => 'last_12_months']));
    }
}
