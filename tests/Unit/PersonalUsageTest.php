<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Réglages → Usage & crédits : formes pures (PersonalUsage) et câblage de la page.
 * Les lectures (top 10 enrichi, réponses par modèle) sont gardées par
 * tests/Functional/PersonalUsagePipelineTest.php.
 */
final class PersonalUsageTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $chemin);
    }

    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    public function testPrevisionDuSolde(): void
    {
        // 13 oct. midi : 12,5 jours écoulés, 31 jours dans le mois.
        $ok = PersonalUsage::forecast(15000, 5400, self::at('2026-10-13 12:00:00'));
        $this->assertSame(PersonalUsage::STATE_OK, $ok['state']);
        $this->assertSame(9600, $ok['remaining']);
        $this->assertSame(36, $ok['pct']);
        $this->assertSame(13392, $ok['projected'], '5 400 ÷ 12,5 × 31');
        $this->assertSame(89, $ok['proj_pct']);
        $this->assertSame(19, $ok['days_left']);
        $this->assertSame(505, $ok['per_day_left'], '9 600 sur 19 jours');
        $this->assertNull($ok['reach_date']);

        $tight = PersonalUsage::forecast(15000, 9000, self::at('2026-10-13 12:00:00'));
        $this->assertSame(PersonalUsage::STATE_TIGHT, $tight['state']);
        $this->assertSame('2026-10-21', $tight['reach_date'], '15 000 ÷ 720 par jour = 20,8 jours');
        $this->assertSame(100, $tight['proj_pct'], 'Plafonné à la jauge');

        $this->assertSame(PersonalUsage::STATE_REACHED, PersonalUsage::forecast(15000, 15000, self::at('2026-10-13 12:00:00'))['state']);
        $this->assertSame(0, PersonalUsage::forecast(15000, 16000, self::at('2026-10-13 12:00:00'))['remaining']);
        $this->assertSame(PersonalUsage::STATE_EARLY, PersonalUsage::forecast(15000, 300, self::at('2026-10-02 08:00:00'))['state']);

        $free = PersonalUsage::forecast(null, 5400, self::at('2026-10-13 12:00:00'));
        $this->assertSame(PersonalUsage::STATE_UNLIMITED, $free['state']);
        $this->assertNull($free['remaining']);
        $this->assertNull($free['pct']);
        $this->assertSame(13392, $free['projected'], 'Sans quota, la fin de mois estimée reste utile');
    }

    public function testAnneauProportionnelEtRegroupe(): void
    {
        $items = [];
        foreach ([50, 20, 10, 8, 7, 5, 0] as $i => $v) {
            $items[] = ['label' => "L$i", 'value' => $v, 'color' => "#00000$i"];
        }
        $r = PersonalUsage::ring($items, 'Autres');
        $this->assertSame(100.0, $r['total']);
        $this->assertCount(PersonalUsage::RING_SLICES, $r['slices']);
        $this->assertSame(['L0', 'L1', 'L2', 'L3', 'Autres'], array_column($r['slices'], 'label'));
        $this->assertEqualsWithDelta(12.0, (float)$r['slices'][4]['value'], 0.001, '7 + 5, la part nulle écartée');
        $this->assertSame(2, $r['slices'][4]['others']);
        $this->assertSame(50.0, $r['slices'][0]['share']);
        $this->assertEqualsWithDelta(2 * M_PI * 40, $r['circumference'], 0.01);
        $this->assertSame(0.0, $r['slices'][0]['offset']);
        $this->assertEqualsWithDelta(-$r['circumference'] * 0.5, $r['slices'][1]['offset'], 0.01, 'Chaque part commence où finit la précédente');

        $one = PersonalUsage::ring([['label' => 'Seul', 'value' => 3, 'color' => '#111111']], 'Autres');
        $this->assertEqualsWithDelta($one['circumference'], $one['slices'][0]['dash'], 0.01, 'Une part seule fait le tour, sans espace');
        $this->assertSame([], PersonalUsage::ring([], 'Autres')['slices']);
    }

    /** Camemberts sur la base du solde : les sources hors quota sortent de leur catégorie. */
    public function testCategoriesRameneesAuQuota(): void
    {
        $cats = PersonalUsage::quotaCategories([
            ['category' => UsageSource::CAT_KNOWLEDGE, 'label' => 'Bases', 'credits' => 1600, 'requests' => 12, 'sources' => [
                ['source' => UsageSource::KB_EMBEDDING, 'credits' => 1560, 'requests' => 6],
                ['source' => UsageSource::KB_QUERY, 'credits' => 40, 'requests' => 6],
            ]],
            ['category' => UsageSource::CAT_ADMIN, 'label' => 'Admin', 'credits' => 90, 'requests' => 3, 'sources' => [
                ['source' => UsageSource::TOOL_TEST, 'credits' => 90, 'requests' => 3],
            ]],
            ['category' => UsageSource::CAT_CHAT, 'label' => 'Conversations', 'credits' => 300, 'requests' => 10, 'sources' => [
                ['source' => UsageSource::CHAT, 'credits' => 300, 'requests' => 10],
            ]],
        ]);
        $this->assertSame([UsageSource::CAT_KNOWLEDGE, UsageSource::CAT_CHAT], array_column($cats, 'category'), 'Une catégorie entièrement hors quota disparaît');
        $this->assertSame(40, $cats[0]['credits']);
        $this->assertSame(6, $cats[0]['requests']);
        $this->assertSame('Bases', $cats[0]['label']);
    }

    public function testCoutsMoyens(): void
    {
        $cats = [
            ['category' => UsageSource::CAT_CHAT, 'requests' => 88, 'credits' => 3000, 'quantity' => 0],
            ['category' => UsageSource::CAT_WEB_SEARCH, 'requests' => 19, 'credits' => 480, 'quantity' => 37],
            ['category' => UsageSource::CAT_VOICE, 'requests' => 5, 'credits' => 280, 'quantity' => 14],
            ['category' => UsageSource::CAT_DESIGN, 'requests' => 0, 'credits' => 0, 'quantity' => 0],
        ];
        $tools = [['tool_type' => 'image', 'uses' => 20, 'credits' => 1200], ['tool_type' => 'video', 'uses' => 1, 'credits' => 900]];
        $this->assertSame(['answer' => 34, 'image' => 60, 'search' => 13, 'minute' => 20], PersonalUsage::unitCosts($cats, $tools));
        $this->assertSame([], PersonalUsage::unitCosts([], []));
    }

    public function testConseilsTiresDeLUsage(): void
    {
        $cats = [
            ['category' => UsageSource::CAT_CHAT, 'credits' => 3000, 'requests' => 100, 'quota_exempt' => false,
             'sources' => [['source' => UsageSource::CHAT, 'requests' => 85], ['source' => UsageSource::REGENERATE, 'requests' => 15]]],
            ['category' => UsageSource::CAT_WEB_SEARCH, 'credits' => 1000, 'requests' => 20, 'quota_exempt' => false, 'sources' => []],
            ['category' => UsageSource::CAT_CREATION, 'credits' => 2000, 'requests' => 20, 'quota_exempt' => false, 'sources' => []],
            ['category' => UsageSource::CAT_KNOWLEDGE, 'credits' => 9000, 'requests' => 50, 'quota_exempt' => true, 'sources' => []],
        ];
        $models = [
            ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'responses' => 60, 'credits' => 2040],
            ['provider' => 'mistral', 'model' => 'mistral-large-latest', 'responses' => 8, 'credits' => 64],
            ['provider' => 'openai', 'model' => 'gpt-5.6', 'responses' => 2, 'credits' => 4],    // trop peu de réponses
        ];
        $tips = PersonalUsage::tips($cats, $models, static fn(string $p, string $m): string => strtoupper($m));
        $this->assertSame(['model', 'web', 'regen'], array_column($tips, 'key'), 'Au plus trois, dans l\'ordre d\'utilité');
        $this->assertSame(['cher' => 'CLAUDE-OPUS-5', 'n_cher' => 34, 'econome' => 'MISTRAL-LARGE-LATEST', 'n_econome' => 8], $tips[0]['params']);
        $this->assertSame(17, $tips[1]['params']['part'], 'Part du quota : la maintenance hors quota ne compte pas');
        $this->assertSame(15, $tips[2]['params']['part']);

        $sobre = [['category' => UsageSource::CAT_CHAT, 'credits' => 500, 'requests' => 20, 'quota_exempt' => false,
                   'sources' => [['source' => UsageSource::CHAT, 'requests' => 20]]]];
        $this->assertSame([], PersonalUsage::tips($sobre, [], static fn(string $p, string $m): string => $m), 'Rien à conseiller : aucun conseil');
    }

    public function testCablageDeLaPage(): void
    {
        $this->assertStringContainsString("require ROOT . '/src/Billing/PersonalUsage.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Billing/PersonalUsage.php',", self::lire('tests/bootstrap.php'));

        $ctrl = self::lire('src/Controllers/UserSettingsController.php');
        foreach (['PersonalUsage::forecast(', 'PersonalUsage::ring(', 'PersonalUsage::unitCosts(', 'PersonalUsage::tips(', 'UsageLog::responsesByModel(',
                  'PersonalUsage::quotaCategories($byCategory)', 'UsageLog::quotaCreditsSplit(', 'UsageLog::topOperations($monthStart, $monthEnd, $userId, 10, true)'] as $appel) {
            $this->assertStringContainsString($appel, $ctrl);
        }

        $tpl = self::lire('templates/settings/usage.php');
        foreach (['class="card pu-hero"', 'Ce qui a consommé vos crédits', "t('Par usage')", "t('Par assistant')", "t('Par modèle')",
                  'Top 10 — opérations les plus coûteuses ce mois', 'href="/chat/<?= (int)$row[\'conversation_id\'] ?>"',
                  'Pour faire durer vos crédits', 'Détail technique par modèle', 'hors quota'] as $attendu) {
            $this->assertStringContainsString($attendu, $tpl, "Absent : $attendu");
        }
        foreach (['Format::eur', 'creditsToEur', '€', 'chart.umd', 'new Chart('] as $interdit) {
            $this->assertStringNotContainsString($interdit, $tpl, "La page de l'utilisateur ne montre aucun euro et ne charge plus Chart.js ($interdit)");
        }
    }
}
