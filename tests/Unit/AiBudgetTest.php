<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Budget IA mensuel : formes pures (sans base). La barrière elle-même
 * (CreditQuota + base) est gardée par tests/Functional/AiBudgetGateTest.php.
 */
final class AiBudgetTest extends TestCase
{
    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    private static function config(?float $base, float $extra = 0.0, string $onReach = 'block', int $alert = 80): array
    {
        return ['enabled' => $base !== null, 'base_eur' => $base, 'extra_eur' => $extra, 'alert_pct' => $alert, 'on_reach' => $onReach];
    }

    private static function spend(float $usage, float $maintenance = 0.0, int $unpriced = 0): array
    {
        return ['usage_eur' => $usage, 'maintenance_eur' => $maintenance, 'unpriced_ops' => $unpriced];
    }

    // ---- Saisie ---------------------------------------------------------

    public function testParseAmountAccepteLesFormesUsuelles(): void
    {
        $this->assertNull(AiBudget::parseAmount(''));
        $this->assertNull(AiBudget::parseAmount('   '));
        $this->assertSame(1234.5, AiBudget::parseAmount(" 1 234,50 € "));
        $this->assertSame(1234.5, AiBudget::parseAmount("1\u{202F}234,50\u{00A0}€"));
        $this->assertSame(500.0, AiBudget::parseAmount('500'));
        $this->assertSame(12.35, AiBudget::parseAmount('12.345'));
        $this->assertSame(0.0, AiBudget::parseAmount('0'), '0 = service suspendu, comme un quota à 0');
    }

    /** @return iterable<array{string}> */
    public static function montantsInvalides(): iterable
    {
        yield ['-5'];
        yield ['abc'];
        yield ['5 000 000 000'];
        yield ['1,2,3'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('montantsInvalides')]
    public function testParseAmountRefuseLeReste(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        AiBudget::parseAmount($raw);
    }

    public function testNormalisationDuSeuilEtDuComportement(): void
    {
        $this->assertSame(80, AiBudget::normalizeAlertPct(''));
        $this->assertSame(80, AiBudget::normalizeAlertPct(null));
        $this->assertSame(1, AiBudget::normalizeAlertPct('0'));
        $this->assertSame(99, AiBudget::normalizeAlertPct('150'));
        $this->assertSame(75, AiBudget::normalizeAlertPct('75'));
        $this->assertSame('block', AiBudget::normalizeOnReach(''));
        $this->assertSame('block', AiBudget::normalizeOnReach('n\'importe quoi'));
        $this->assertSame('warn', AiBudget::normalizeOnReach('warn'));
    }

    // ---- Fenêtre ---------------------------------------------------------

    public function testFenetreDuMois(): void
    {
        $w = AiBudget::monthWindow(self::at('2026-09-25 15:00:00'));
        $this->assertSame('2026-09', $w['month']);
        $this->assertSame('2026-09-01 00:00:00', $w['from']);
        $this->assertSame('2026-10-01 00:00:00', $w['until']);
        $this->assertSame('2026-10-01', $w['resets_on']);
        $this->assertSame(30, $w['days_in_month']);

        $dec = AiBudget::monthWindow(self::at('2026-12-31 23:59:59'));
        $this->assertSame('2027-01-01', $dec['resets_on'], 'Décembre bascule sur janvier de l\'année suivante');

        $this->assertSame(29, AiBudget::monthWindow(self::at('2028-02-10 00:00:00'))['days_in_month']);
    }

    // ---- États ------------------------------------------------------------

    public function testSansBudgetRienNeBloque(): void
    {
        $s = AiBudget::evaluate(self::config(null), self::spend(9999), self::at('2026-09-25 12:00:00'));
        $this->assertFalse($s['enabled']);
        $this->assertSame(AiBudget::STATE_OFF, $s['state']);
        $this->assertFalse($s['blocks']);
        $this->assertNull($s['limit_eur']);
        $this->assertNull($s['pct']);
    }

    public function testEtatsAuSeuilEtAuPlafondExacts(): void
    {
        $now = self::at('2026-09-25 12:00:00');
        $this->assertSame(AiBudget::STATE_OK, AiBudget::evaluate(self::config(500), self::spend(399.99), $now)['state']);
        $this->assertSame(AiBudget::STATE_ALERT, AiBudget::evaluate(self::config(500), self::spend(400), $now)['state'], '80 % exactement = alerte');
        $reached = AiBudget::evaluate(self::config(500), self::spend(450, 50), $now);
        $this->assertSame(AiBudget::STATE_REACHED, $reached['state'], '100 % exactement = atteint (maintenance comprise)');
        $this->assertTrue($reached['blocks']);
        $this->assertSame(0.0, $reached['remaining_eur']);
    }

    public function testLePourcentageEstArrondiALInferieur(): void
    {
        $s = AiBudget::evaluate(self::config(100), self::spend(99.6), self::at('2026-09-25 12:00:00'));
        $this->assertSame(99, $s['pct'], '99,6 % ne s\'affiche jamais 100 % avant le plafond');
        $this->assertFalse($s['blocks']);
    }

    public function testModeSignalementNeBloqueJamais(): void
    {
        $s = AiBudget::evaluate(self::config(100, 0.0, 'warn'), self::spend(250), self::at('2026-09-25 12:00:00'));
        $this->assertSame(AiBudget::STATE_REACHED, $s['state']);
        $this->assertFalse($s['blocks']);
        $this->assertSame(250, $s['pct'], 'Le dépassement reste lisible (250 %)');
    }

    public function testLaRallongeReleveLePlafond(): void
    {
        $s = AiBudget::evaluate(self::config(500, 100), self::spend(550), self::at('2026-09-25 12:00:00'));
        $this->assertSame(600.0, $s['limit_eur']);
        $this->assertFalse($s['blocks']);
        $this->assertSame(AiBudget::STATE_ALERT, $s['state']);
    }

    public function testUnBudgetAZeroSuspend(): void
    {
        $s = AiBudget::evaluate(self::config(0.0), self::spend(0), self::at('2026-09-25 12:00:00'));
        $this->assertTrue($s['blocks']);
        $this->assertSame(100, $s['pct']);
    }

    // ---- Projection ---------------------------------------------------------

    public function testPasDeProjectionAvantDeuxJours(): void
    {
        $this->assertNull(AiBudget::projection(40.0, 500.0, self::at('2026-09-02 23:00:00')));
        $p = AiBudget::projection(40.0, 500.0, self::at('2026-09-03 00:00:00'));
        $this->assertNotNull($p);
        $this->assertSame(20.0, $p['daily_eur']);
        $this->assertSame(600.0, $p['projected_eur']);
        $this->assertTrue($p['over']);
        $this->assertSame('2026-09-26', $p['reach_date'], '500 € à 20 €/jour : atteint le 25e jour écoulé');
    }

    public function testProjectionSousLePlafondNaPasDeDate(): void
    {
        $p = AiBudget::projection(100.0, 500.0, self::at('2026-09-11 00:00:00'));
        $this->assertSame(300.0, $p['projected_eur']);
        $this->assertFalse($p['over']);
        $this->assertNull($p['reach_date']);
    }

    public function testEvolution(): void
    {
        $this->assertNull(AiBudget::evolutionPct(50, 0.5));
        $this->assertSame(50.0, AiBudget::evolutionPct(150, 100));
    }

    // ---- Mémo -----------------------------------------------------------------

    public function testMemo(): void
    {
        $memo = ['month' => '2026-09', 'at' => 1000, 'usage_eur' => 100.0, 'maintenance_eur' => 0.0, 'unpriced_ops' => 0];
        $this->assertTrue(AiBudget::memoUsable($memo, '2026-09', 1030, 500.0, true));
        $this->assertFalse(AiBudget::memoUsable($memo, '2026-10', 1030, 500.0, true), 'Autre mois');
        $this->assertFalse(AiBudget::memoUsable($memo, '2026-09', 1060, 500.0, true), 'Périmé');
        $this->assertFalse(AiBudget::memoUsable(null, '2026-09', 1030, 500.0, true));

        $proche = ['usage_eur' => 480.0] + $memo;
        $this->assertFalse(AiBudget::memoUsable($proche, '2026-09', 1030, 500.0, true), 'Zone 95-100 % : relecture fraîche');
        $this->assertTrue(AiBudget::memoUsable($proche, '2026-09', 1030, 500.0, false), 'Mode signalement : pas de zone critique');
        $atteint = ['usage_eur' => 510.0] + $memo;
        $this->assertTrue(AiBudget::memoUsable($atteint, '2026-09', 1030, 500.0, true));
    }

    // ---- Repères ---------------------------------------------------------------

    public function testRepereParCompte(): void
    {
        $r = AiBudget::repere(500.0, 50, 1300.0);
        $this->assertSame(650000, $r['total_credits']);
        $this->assertSame(13000, $r['per_account_credits']);
        $this->assertSame(13, $r['per_account_k']);
        $this->assertNull(AiBudget::repere(500.0, 0, 1300.0));
        $this->assertNull(AiBudget::repere(0.0, 10, 1300.0));
    }

    public function testPlafondDesQuotas(): void
    {
        $c = AiBudget::quotaCeiling([10, 10, 50, null], 1300.0);
        $this->assertSame(70000, $c['credits']);
        $this->assertSame(53.85, $c['eur']);
        $this->assertSame(1, $c['unbounded']);
        $this->assertSame(4, $c['accounts']);
    }

    public function testConsommationParCompte(): void
    {
        $s = AiBudget::consumptionStats([1 => 1000, 2 => 5000, 9 => 99999], [1, 2, 3]);
        $this->assertSame(3, $s['accounts']);
        $this->assertSame(2000, $s['avg'], 'Compte sans consommation = 0 dans la moyenne ; compte inactif ignoré');
        $this->assertSame(5000, $s['max']);
    }

    // ---- Page Budget : rythme, trajectoire, répartition -------------------

    /** Le rythme compare la dépense au TEMPS : sous le seuil, on peut déjà aller trop vite. */
    public function testLeRythmeSignaleUnDepassementAVenirSousLeSeuil(): void
    {
        $now = self::at('2026-10-13 12:00:00');
        $s   = AiBudget::evaluate(self::config(500.0), self::spend(312.0), $now);
        $this->assertSame(AiBudget::STATE_OK, $s['state'], '62 % < 80 % : état « sous contrôle »');
        $p = AiBudget::pace($s);
        $this->assertSame(AiBudget::PACE_FAST, $p['key'], 'Mais la projection dépasse le plafond');
        $this->assertEqualsWithDelta(312.0 / 12.5, (float)$p['daily_eur'], 0.01);
        $this->assertEqualsWithDelta(188.0 / 19, (float)$p['tenable_eur'], 0.01, '188 € sur les 19 jours restants');

        $this->assertSame(AiBudget::PACE_STEADY, AiBudget::pace(AiBudget::evaluate(self::config(900.0), self::spend(312.0), $now))['key']);
        $this->assertSame(AiBudget::PACE_REACHED, AiBudget::pace(AiBudget::evaluate(self::config(300.0), self::spend(312.0), $now))['key']);
        $this->assertSame(AiBudget::PACE_EARLY, AiBudget::pace(AiBudget::evaluate(self::config(500.0), self::spend(20.0), self::at('2026-10-01 12:00:00')))['key']);
        $off = AiBudget::pace(AiBudget::evaluate(self::config(null), self::spend(312.0), $now));
        $this->assertSame(AiBudget::PACE_OFF, $off['key']);
        $this->assertNull($off['tenable_eur']);
        $this->assertNotNull($off['daily_eur'], 'Sans budget, le rythme reste mesuré');
    }

    public function testEchelleRonde(): void
    {
        $this->assertSame(['max' => 800.0, 'step' => 200.0], AiBudget::niceScale(781.0));
        $this->assertSame(['max' => 1000.0, 'step' => 250.0], AiBudget::niceScale(830.0));
        $this->assertSame(['max' => 10.0, 'step' => 2.5], AiBudget::niceScale(9.2));
        $this->assertSame(['max' => 1.0, 'step' => 0.25], AiBudget::niceScale(0.0), 'Jamais une échelle nulle : 1 € au moins');
    }

    public function testLaTrajectoirePoseLesJoursLaProjectionEtLeMoisPrecedent(): void
    {
        $now    = self::at('2026-10-03 12:00:00');
        $status = AiBudget::evaluate(self::config(100.0), self::spend(50.0), $now);
        $daily  = ['2026-10-01' => 20.0, '2026-10-02' => 10.0, '2026-10-03' => 20.0, '2026-11-01' => 999.0];
        $last   = ['2026-09-01' => 30.0, '2026-09-30' => 30.0];
        $t = AiBudget::trajectory($status, $daily, $last, $now);

        $this->assertSame(31, $t['days']);
        $this->assertEqualsWithDelta(2.5, $t['elapsed'], 0.0001);
        $pts = $t['points'];
        $this->assertCount(4, $pts, 'Origine, deux jours révolus, maintenant');
        $this->assertSame([0.0, 20.0, 30.0, 50.0], array_map(static fn(array $p): float => $p['v'], $pts));
        $this->assertSame('2026-10-03', $pts[3]['day']);
        $this->assertEqualsWithDelta(20.0, $pts[3]['daily'], 0.0001, 'Aujourd\'hui = dépense totale moins les jours révolus');
        $this->assertEqualsWithDelta(2.5 / 31 * 100, $pts[3]['x'], 0.01);

        // Projection : 50 € en 2,5 jours → 20 €/j → 620 € ; plafond 100 € atteint en t = 5.
        $this->assertNotNull($t['projection']);
        $this->assertEqualsWithDelta(620.0, $t['projection']['eur'], 0.01);
        $this->assertSame(100.0, $t['projection']['x2']);
        $this->assertSame(800.0, $t['y_max'], 'Échelle ronde au-dessus de la projection');
        $this->assertNotNull($t['reach']);
        $this->assertEqualsWithDelta(5 / 31 * 100, $t['reach']['x'], 0.01);
        $this->assertSame('2026-10-06', $t['reach']['date']);
        $this->assertEqualsWithDelta(100 - 100 / 800 * 100, $t['limit']['y'], 0.01);
        $this->assertEqualsWithDelta(80.0, $t['alert']['eur'], 0.01);

        // Mois précédent ramené à 31 jours, total 60 €.
        $this->assertNotNull($t['last']);
        $this->assertSame('2026-09', $t['last']['month']);
        $this->assertEqualsWithDelta(60.0, $t['last']['total'], 0.0001);
        $lastPt = end($t['last']['points']);
        $this->assertEqualsWithDelta(100.0, $lastPt['x'], 0.01, 'La fin de septembre tombe à la fin du mois affiché');

        $this->assertSame(1, $t['x_ticks'][0]['day']);
        $this->assertSame(31, end($t['x_ticks'])['day']);
        foreach ($t['y_ticks'] as $tick) {
            $this->assertGreaterThanOrEqual(0.0, $tick['y']);
            $this->assertLessThanOrEqual(100.0, $tick['y']);
        }
    }

    public function testSansBudgetNiProjectionLaTrajectoireResteDessinable(): void
    {
        $now = self::at('2026-10-01 06:00:00');
        $t = AiBudget::trajectory(AiBudget::evaluate(self::config(null), self::spend(0.0), $now), [], [], $now);
        $this->assertNull($t['projection'], 'Pas de projection avant deux jours');
        $this->assertNull($t['limit']);
        $this->assertNull($t['alert']);
        $this->assertNull($t['reach']);
        $this->assertNull($t['last'], 'Mois précédent vide : rien à tracer');
        $this->assertCount(2, $t['points']);
        $this->assertGreaterThan(0.0, $t['y_max']);
    }

    /** Service suspendu au plafond : la dépense ne suit plus le rythme, la projection mentirait. */
    public function testServiceSuspenduPasDeProjection(): void
    {
        $now = self::at('2026-10-13 12:00:00');
        $blocked = AiBudget::evaluate(self::config(250.0), self::spend(312.0), $now);
        $this->assertTrue($blocked['blocks']);
        $this->assertNull(AiBudget::trajectory($blocked, [], [], $now)['projection']);
        $warn = AiBudget::evaluate(self::config(250.0, 0.0, 'warn'), self::spend(312.0), $now);
        $this->assertNotNull(AiBudget::trajectory($warn, [], [], $now)['projection'], 'En mode signalement, la dépense continue');
    }

    private static function periodConfig(?float $base, float $extra = 0.0, string $month = '2026-10'): array
    {
        return ['enabled' => $base !== null, 'base_eur' => $base, 'extra_eur' => $extra, 'alert_pct' => 80, 'on_reach' => 'block', 'month' => $month];
    }

    /** Une barre par jour jusqu'à deux mois ; part du budget = budget du mois ÷ ses jours. */
    public function testHistogrammeDUnePeriodeParJour(): void
    {
        I18n::use('fr');
        $now = self::at('2026-10-03 12:00:00');
        $h = AiBudget::periodBars(['from' => '2026-10-01', 'to' => '2026-10-03'],
            ['2026-09-30' => 50.0, '2026-10-01' => 20.0, '2026-10-02' => 5.0, '2026-10-03' => 25.0, '2026-10-09' => 99.0],
            self::periodConfig(310.0), $now);

        $this->assertSame(AiBudget::GRAIN_DAY, $h['grain']);
        $this->assertSame(3, $h['days']);
        $this->assertEqualsWithDelta(50.0, $h['total'], 0.001, 'Seuls les jours de la plage');
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], array_column($h['bars'], 'start'));
        $this->assertEqualsWithDelta(0.0, $h['bars'][0]['x'], 0.001);
        $this->assertEqualsWithDelta(100 / 3, $h['bars'][1]['x'], 0.01);
        $this->assertSame([10.0, 10.0, 10.0], array_column($h['bars'], 'ref'), '310 € ÷ 31 jours');
        $this->assertSame([true, false, true], array_column($h['bars'], 'over'));
        $this->assertSame(2, $h['over']);
        $this->assertSame([false, false, true], array_column($h['bars'], 'current'), 'Aujourd\'hui, en cours');
        $this->assertTrue($h['ref']['uniform']);
        $this->assertEqualsWithDelta(10.0, $h['ref']['eur'], 0.001);
        $this->assertCount(6, $h['ref']['points'], 'Une marche par barre');
        $this->assertEqualsWithDelta(30.0, $h['ref_total'], 0.001);
        $this->assertEqualsWithDelta(50 / 3, $h['average']['eur'], 0.01, 'Moyenne de la période par jour');
        $this->assertSame('2026-10-03', $h['peak']['start']);
        $this->assertSame(Format::shortDate(new DateTimeImmutable('2026-10-03')), $h['peak']['label']);
        $this->assertEqualsWithDelta(30.0, $h['y_max'], 0.001, 'Échelle ronde au-dessus du pic');
        $this->assertEqualsWithDelta(25 / 30 * 100, $h['bars'][2]['h'], 0.01);
        $this->assertSame("1\u{00A0}oct.", $h['x_ticks'][0]['label'], 'Le mois à la première étiquette');
        $this->assertSame('2', $h['x_ticks'][1]['label'], 'Puis le jour seul');

        $off = AiBudget::periodBars(['from' => '2026-10-01', 'to' => '2026-10-01'], [], self::periodConfig(null), self::at('2026-10-01 06:00:00'));
        $this->assertNull($off['ref'], 'Sans budget, pas de part du budget');
        $this->assertNull($off['ref_total']);
        $this->assertNull($off['peak'], 'Aucune dépense : pas de pic');
        $this->assertNull($off['average']);
        $this->assertSame(0, $off['over']);
        $this->assertCount(1, $off['bars']);
    }

    /** Au-delà de deux mois : par semaine, du lundi au dimanche ; une semaine coupée par la plage a sa juste part. */
    public function testHistogrammeDUnePeriodeParSemaine(): void
    {
        I18n::use('fr');
        $h = AiBudget::periodBars(['from' => '2026-08-01', 'to' => '2026-10-18'],
            ['2026-08-01' => 7.0, '2026-08-02' => 3.0, '2026-08-03' => 40.0],
            self::periodConfig(310.0), self::at('2026-10-18 10:00:00'));

        $this->assertSame(AiBudget::GRAIN_WEEK, $h['grain']);
        $this->assertSame(79, $h['days']);
        $first = $h['bars'][0];
        $this->assertSame(['2026-08-01', '2026-08-02'], [$first['start'], $first['end']], 'Samedi et dimanche : la semaine est coupée');
        $this->assertTrue($first['partial']);
        $this->assertEqualsWithDelta(10.0, $first['eur'], 0.001);
        $this->assertEqualsWithDelta(round(2 * 310 / 31, 2), $first['ref'], 0.01, 'Deux jours d\'août');
        $this->assertSame('2026-08-03', $h['bars'][1]['start'], 'Puis du lundi au dimanche');
        $this->assertSame('2026-08-09', $h['bars'][1]['end']);
        $this->assertFalse($h['bars'][1]['partial']);
        $this->assertEqualsWithDelta(70.0, $h['bars'][1]['ref'], 0.01);
        // Semaine à cheval sur août et septembre : 1 jour à 10 €/j, 6 jours à 310/30 €/j.
        $cross = array_values(array_filter($h['bars'], static fn(array $b): bool => $b['start'] === '2026-08-31'))[0];
        $this->assertEqualsWithDelta(round(10 + 6 * 310 / 30, 2), $cross['ref'], 0.01);
        $this->assertFalse($h['ref']['uniform'], 'Parts différentes : le repère est en marches');
        $last = end($h['bars']);
        $this->assertTrue($last['current']);
        $this->assertSame('2026-10-12', $last['start']);
        $this->assertLessThanOrEqual(AiBudget::BARS_TICKS, count($h['x_ticks']));
        $this->assertEqualsWithDelta(round(50 / 79 * 7, 2), $h['average']['eur'], 0.01, 'Moyenne ramenée à la semaine');
    }

    /** Au-delà de six mois : par mois ; la rallonge ne compte que pour son mois, le mois entamé au prorata. */
    public function testHistogrammeDUnePeriodeParMois(): void
    {
        I18n::use('fr');
        $h = AiBudget::periodBars(['from' => '2025-11-01', 'to' => '2026-10-18'],
            ['2025-11-10' => 120.0, '2026-01-05' => 600.0, '2026-10-02' => 90.0],
            self::periodConfig(500.0, 62.0), self::at('2026-10-18 10:00:00'));

        $this->assertSame(AiBudget::GRAIN_MONTH, $h['grain']);
        $this->assertCount(12, $h['bars']);
        $this->assertSame('2025-11-01', $h['bars'][0]['start']);
        $this->assertSame(Format::monthLabel('2025-11'), $h['bars'][0]['label']);
        $this->assertEqualsWithDelta(500.0, $h['bars'][0]['ref'], 0.01, 'Mois entier : le budget mensuel');
        $this->assertTrue($h['bars'][2]['over'], 'Janvier : 600 € pour 500 €');
        $oct = end($h['bars']);
        $this->assertTrue($oct['current']);
        $this->assertTrue($oct['partial']);
        $this->assertEqualsWithDelta(round(562 * 18 / 31, 2), $oct['ref'], 0.01, 'Rallonge comprise, au prorata des jours');
        $this->assertSame(UsagePeriod::rangeLabel('2026-10-01', '2026-10-18'), $oct['label']);
        $this->assertEqualsWithDelta(500.0, $h['ref']['eur'], 0.01, 'Étiquette : la part des mois entiers');
        $this->assertTrue($h['ref']['uniform']);
        $this->assertSame(1, $h['over']);
        $this->assertSame("nov.\u{00A0}2025", $h['x_ticks'][0]['label'], 'L\'année à la première étiquette');
        $this->assertContains("janv.\u{00A0}2026", array_column($h['x_ticks'], 'label'), 'Et en janvier');
        $this->assertSame(AiBudget::GRAIN_WEEK, AiBudget::grain(AiBudget::BARS_WEEK_MAX));
        $this->assertSame(AiBudget::GRAIN_DAY, AiBudget::grain(AiBudget::BARS_DAY_MAX));
        $this->assertNull(AiBudget::budgetForMonth(self::periodConfig(null), '2026-10'));
        $this->assertSame(562.0, AiBudget::budgetForMonth(self::periodConfig(500.0, 62.0), '2026-10'));
        $this->assertSame(500.0, AiBudget::budgetForMonth(self::periodConfig(500.0, 62.0), '2026-09'));
    }

    public function testChiffresDUnePeriode(): void
    {
        $bars = ['days' => 18, 'ref_total' => 180.0];
        $cost = ['usage_eur' => 100.0, 'maintenance_eur' => 12.5, 'unpriced_ops' => 2, 'operations' => 340];
        $s = AiBudget::periodSummary($cost, ['from' => '2026-09-01', 'to' => '2026-09-18', 'cost' => ['usage_eur' => 90.0, 'maintenance_eur' => 10.0]], $bars);
        $this->assertEqualsWithDelta(112.5, $s['total'], 0.001);
        $this->assertEqualsWithDelta(6.25, $s['per_day'], 0.001);
        $this->assertEqualsWithDelta(12.5, $s['evolution'], 0.001, '112,50 € contre 100 €');
        $this->assertSame(62, $s['budget_share'], 'Arrondi inférieur : 62,5 %');
        $this->assertSame(2, $s['unpriced_ops']);
        $this->assertSame(340, $s['operations']);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-18', 'total' => 100.0], $s['previous']);

        $none = AiBudget::periodSummary($cost, null, ['days' => 18, 'ref_total' => null]);
        $this->assertNull($none['previous']);
        $this->assertNull($none['evolution']);
        $this->assertNull($none['budget_share'], 'Sans budget, aucune part');
        $tiny = AiBudget::periodSummary($cost, ['from' => 'a', 'to' => 'b', 'cost' => ['usage_eur' => 0.4]], $bars);
        $this->assertNull($tiny['evolution'], 'Sous 1 € avant, un pourcentage serait absurde');
    }

    public function testRepartitionParFournisseur(): void
    {
        $b = AiBudget::providerBreakdown([
            ['provider' => 'openai', 'cost_eur' => 30.0, 'operations' => 10],
            ['provider' => 'anthropic', 'cost_eur' => 60.0, 'operations' => 5],
            ['provider' => 'gamma', 'cost_eur' => 0.0, 'operations' => 3],
            ['provider' => 'mistral', 'cost_eur' => 10.0, 'operations' => 1],
        ], 2);
        $this->assertSame(['anthropic', 'openai'], array_column($b['rows'], 'provider'), 'Classés par coût, sans les gratuits');
        $this->assertEqualsWithDelta(60.0, $b['rows'][0]['share'], 0.01);
        $this->assertSame(1, $b['other']['count']);
        $this->assertEqualsWithDelta(10.0, $b['other']['cost_eur'], 0.001);
        $this->assertEqualsWithDelta(100.0, $b['total'], 0.001);
        $this->assertSame(['rows' => [], 'other' => null, 'total' => 0.0], AiBudget::providerBreakdown([]));
    }

    public function testCoutDeMilleCredits(): void
    {
        $this->assertEqualsWithDelta(0.7692, (float)AiBudget::eurPerThousandCredits(1300.0), 0.0001);
        $this->assertEqualsWithDelta(10.0, (float)AiBudget::eurPerThousandCredits(100.0), 0.0001);
        $this->assertNull(AiBudget::eurPerThousandCredits(0.0));
    }

    public function testRepartitionParCategorie(): void
    {
        $cats = [
            ['category' => 'chat', 'label' => 'Conversations', 'color' => '#6366f1', 'quota_exempt' => false, 'cost_eur' => 60.0],
            ['category' => 'knowledge', 'label' => 'Bases', 'color' => '#10b981', 'quota_exempt' => true, 'cost_eur' => 25.0],
            ['category' => 'voice', 'label' => 'Voix', 'color' => '#ec4899', 'quota_exempt' => false, 'cost_eur' => 0.0],
            ['category' => 'web_search', 'label' => 'Recherche', 'color' => '#0ea5e9', 'quota_exempt' => false, 'cost_eur' => 10.0],
            ['category' => 'admin', 'label' => 'Administration', 'color' => '#64748b', 'quota_exempt' => true, 'cost_eur' => 5.0],
        ];
        $b = AiBudget::spendBreakdown($cats, 2);
        $this->assertEqualsWithDelta(100.0, $b['total'], 0.0001);
        $this->assertSame(['chat', 'knowledge'], array_column($b['rows'], 'category'), 'Classées par coût, catégories à 0 € écartées');
        $this->assertSame(60.0, $b['rows'][0]['share']);
        $this->assertTrue($b['rows'][1]['maintenance']);
        $this->assertSame(['count' => 2, 'cost_eur' => 15.0, 'share' => 15.0], $b['other']);
        $this->assertSame(['rows' => [], 'other' => null, 'total' => 0.0], AiBudget::spendBreakdown([]));
    }

    public function testTauxDeConversion(): void
    {
        $this->assertEqualsWithDelta(1300.0, CreditService::creditsPerRealEur(['credit_value_eur' => 0.001, 'margin_multiplier' => 1.3]), 0.0001);
        $this->assertEqualsWithDelta(100.0, CreditService::creditsPerRealEur(['credit_value_eur' => 0.01, 'margin_multiplier' => 1.0]), 0.0001);
    }

    // ---- Statut transmis aux utilisateurs ----------------------------------------

    public function testLeStatutUtilisateurNePorteAucunMontant(): void
    {
        $s = CreditQuota::compose(10, null, ['total_credits' => 12], true, '2026-10-01');
        $this->assertSame(
            ['used', 'quota_k', 'quota_max', 'remaining', 'percent', 'total_credits', 'exempt_credits', 'requests', 'reason', 'budget_blocked', 'resets_on'],
            array_keys($s),
            'Liste FERMÉE : ce tableau part dans le navigateur de chaque utilisateur'
        );
        foreach (array_keys($s) as $key) {
            $this->assertStringNotContainsString('eur', $key);
            $this->assertStringNotContainsString('cost', $key);
        }
        $this->assertSame('budget', $s['reason']);
        $this->assertTrue(CreditQuota::isExceeded($s));
    }

    public function testLeQuotaPersonnelPasseAvantLeBudget(): void
    {
        $s = CreditQuota::compose(10_000, 10, [], true, '2026-10-01');
        $this->assertSame('quota', $s['reason']);
        $libre = CreditQuota::compose(10, 10, [], false, '2026-10-01');
        $this->assertNull($libre['reason']);
        $this->assertFalse(CreditQuota::isExceeded($libre));
        $this->assertTrue(CreditQuota::isExceeded(['used' => 5, 'quota_max' => 5]), 'Ancien format toujours compris');
    }

    public function testRefusSelonLaRaisonEtLaLangue(): void
    {
        $budget = CreditQuota::compose(0, null, [], true, '2026-10-01');
        $quota  = CreditQuota::compose(10_000, 10, [], false, '2026-10-01');
        try {
            I18n::use('fr');
            $r = CreditQuota::refusal($budget, true);
            $this->assertSame('budget', $r['kind']);
            $this->assertSame('Service IA suspendu', $r['title']);
            $this->assertStringContainsString('1er oct. 2026', $r['message']);
            $this->assertStringNotContainsString('€', $r['message']);
            $this->assertSame('quota', CreditQuota::refusal($quota)['kind']);

            I18n::use('en');
            $en = CreditQuota::refusal($budget);
            $this->assertSame('AI service suspended', $en['title']);
            $this->assertStringNotContainsString('€', $en['message']);
        } finally {
            I18n::use('fr');
        }
    }
}
