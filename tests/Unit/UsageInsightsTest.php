<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Usages → Vue d'ensemble : formes pures (UsageInsights) et câblage de la page.
 * Les lectures en base sont gardées par tests/Functional/UsageInsightsPipelineTest.php.
 */
final class UsageInsightsTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $chemin);
    }

    public function testLesActionsDeConnecteurSontReconnues(): void
    {
        foreach (['gmail_search', 'drive_read', 'outlook_attachment', 'slack_read', 'mstodo_read', 'creer_brouillon_gmail',
                  'creer_evenement_agenda_outlook', 'emails_search', 'everywhere_search', 'connecteur_active'] as $t) {
            $this->assertTrue(UsageInsights::isConnectorAction($t), $t);
        }
        foreach (['programmer_tache', 'web_search', 'searching_knowledge_bases', 'memorizing', 'generating_image', 'mybrain_read'] as $t) {
            $this->assertFalse(UsageInsights::isConnectorAction($t), "$t n'est pas un connecteur");
        }
    }

    /** Une personne compte une fois par fonctionnalité, quelles que soient ses sources. */
    public function testAgregationParFonctionnalite(): void
    {
        $f = UsageInsights::aggregateFeatures(
            [['source' => 'chat', 'user_id' => 1, 'n' => 10], ['source' => 'regenerate', 'user_id' => 1, 'n' => 2],
             ['source' => 'chat', 'user_id' => 2, 'n' => 3], ['source' => 'html_tool', 'user_id' => 2, 'n' => 1],
             ['source' => 'title', 'user_id' => 3, 'n' => 9]],
            [['tool_type' => 'html', 'user_id' => 2, 'n' => 1], ['tool_type' => 'image', 'user_id' => null, 'n' => 4]],
            [['t' => 'gmail_search', 'user_id' => 1, 'n' => 3], ['t' => 'drive_read', 'user_id' => 3, 'n' => 1],
             ['t' => 'searching_knowledge_bases', 'user_id' => 2, 'n' => 5], ['t' => 'programmer_tache', 'user_id' => 1, 'n' => 1]],
            [['user_id' => 3, 'n' => 4]],
            [['user_id' => 1, 'n' => 6]]
        );
        $this->assertSame(['people' => 2, 'uses' => 15], $f['chat'], 'chat + regenerate, deux personnes');
        $this->assertSame(['people' => 1, 'uses' => 2], $f['html'], 'Même personne par deux sources : comptée une fois');
        $this->assertSame(['people' => 0, 'uses' => 4], $f['image'], 'Opérations d\'instance : usages sans personne');
        $this->assertSame(['people' => 2, 'uses' => 4], $f['connectors']);
        $this->assertSame(['people' => 1, 'uses' => 5], $f['kb']);
        $this->assertSame(['people' => 1, 'uses' => 4], $f['scheduled']);
        $this->assertSame(['people' => 1, 'uses' => 6], $f['assistants']);
        $this->assertSame(['people' => 0, 'uses' => 0], $f['video']);
        $this->assertSame(array_keys(UsageInsights::FEATURES), array_keys($f), 'Chaque fonctionnalité du catalogue a sa ligne');
    }

    public function testTendance(): void
    {
        $this->assertSame(25, UsageInsights::trend(125, 100));
        $this->assertSame(-20, UsageInsights::trend(80, 100));
        $this->assertSame(0, UsageInsights::trend(100, 100));
        $this->assertSame('new', UsageInsights::trend(5, 0));
        $this->assertNull(UsageInsights::trend(0, 0));
        $this->assertNull(UsageInsights::trend(5, null), 'Sans période précédente : rien à comparer');
    }

    public function testAdoptionClasseEtNommeLesInutilisees(): void
    {
        $cur = array_fill_keys(array_keys(UsageInsights::FEATURES), ['people' => 0, 'uses' => 0]);
        $cur['chat'] = ['people' => 10, 'uses' => 300];
        $cur['web']  = ['people' => 10, 'uses' => 500];
        $cur['image'] = ['people' => 3, 'uses' => 9];
        $prev = ['chat' => ['people' => 8, 'uses' => 200], 'web' => ['people' => 1, 'uses' => 0]];
        $a = UsageInsights::adoption($cur, $prev, 40);
        $this->assertSame(['web', 'chat', 'image'], array_column($a['used'], 'key'), 'Personnes, puis usages');
        $this->assertSame(25.0, $a['used'][0]['share']);
        $this->assertSame(50, $a['used'][1]['trend']);
        $this->assertSame('new', $a['used'][0]['trend']);
        $this->assertContains('Vidéos', $a['unused']);
        $this->assertNotContains('Images', $a['unused']);
        $this->assertNull(UsageInsights::adoption($cur, null, 40)['used'][0]['trend']);
    }

    public function testQuantilesEtRelances(): void
    {
        $this->assertSame(3.0, UsageInsights::quantile([5, 1, 3], 0.5));
        $this->assertSame(2.5, UsageInsights::quantile([1, 2, 3, 4], 0.5));
        $this->assertEqualsWithDelta(9.1, (float)UsageInsights::quantile(range(1, 10), 0.9), 0.0001);
        $this->assertNull(UsageInsights::quantile([], 0.5));
        $this->assertSame(5.0, UsageInsights::regenRate(95, 5));
        $this->assertNull(UsageInsights::regenRate(0, 0));
    }

    public function testStatistiquesParModeleAvecAutres(): void
    {
        $responses = [];
        foreach (range(1, 8) as $i) {
            $responses[] = ['llm_provider' => 'p' . $i, 'llm_model' => 'm', 'response_ms' => 1000 * $i];
        }
        $responses[] = ['llm_provider' => 'p1', 'llm_model' => 'm', 'response_ms' => 3000];
        $responses[] = ['llm_provider' => null, 'llm_model' => null, 'response_ms' => 0];
        $s = UsageInsights::modelStats($responses, [
            ['llm_provider' => 'p1', 'model' => 'm', 'source' => 'chat', 'n' => 9],
            ['llm_provider' => 'p1', 'model' => 'm', 'source' => 'regenerate', 'n' => 1],
        ], static fn(string $p, string $m): string => strtoupper($p));
        $this->assertSame(9, $s['total'], 'Une réponse sans modèle n\'est rangée nulle part');
        $this->assertCount(UsageInsights::TOP_ROWS, $s['rows']);
        $this->assertSame('P1', $s['rows'][0]['label']);
        $this->assertSame(2000.0, $s['rows'][0]['median_ms']);
        $this->assertSame(10.0, $s['rows'][0]['regen_rate']);
        $this->assertSame(2, $s['other']['count']);
    }

    public function testStatistiquesParAssistant(): void
    {
        $s = UsageInsights::assistantStats([
            ['profile_id' => 7, 'user_id' => 1, 'conversation_id' => 10],
            ['profile_id' => 7, 'user_id' => 1, 'conversation_id' => 10],
            ['profile_id' => 7, 'user_id' => 2, 'conversation_id' => 11],
            ['profile_id' => null, 'user_id' => 3, 'conversation_id' => 12],
            ['profile_id' => 99, 'user_id' => 3, 'conversation_id' => 13],
        ], [7 => ['chat' => 9, 'regenerate' => 1]], [7 => 'Juriste']);
        $this->assertSame('Juriste', $s[0]['label']);
        $this->assertSame(2, $s[0]['conversations']);
        $this->assertSame(2, $s[0]['people']);
        $this->assertSame(3, $s[0]['responses']);
        $this->assertSame(10.0, $s[0]['regen_rate']);
        $this->assertContains('Sans assistant', array_column($s, 'label'));
        $this->assertContains('Assistant supprimé', array_column($s, 'label'));
    }

    /** Carte proportionnelle : chaque aire égale sa part, rien ne déborde, tout est couvert. */
    public function testCarteProportionnelle(): void
    {
        $items = [['k' => 'a', 'value' => 50.0], ['k' => 'b', 'value' => 30.0], ['k' => 'c', 'value' => 15.0],
                  ['k' => 'd', 'value' => 5.0], ['k' => 'z', 'value' => 0.0]];
        $t = UsageInsights::treemap($items, 900, 200);
        $this->assertSame(['a', 'b', 'c', 'd'], array_column($t, 'k'), 'Plus grand d\'abord, zéro écarté');
        $area = 0.0;
        foreach ($t as $r) {
            $this->assertEqualsWithDelta($r['share'], $r['w'] * $r['h'] / 100, 0.1, "Aire de {$r['k']}");
            $this->assertGreaterThanOrEqual(0.0, $r['x']);
            $this->assertLessThanOrEqual(100.01, $r['x'] + $r['w']);
            $this->assertLessThanOrEqual(100.01, $r['y'] + $r['h']);
            $area += $r['w'] * $r['h'];
        }
        $this->assertEqualsWithDelta(10000.0, $area, 1.0);
        $this->assertFalse($t[0]['compact']);
        $this->assertTrue(end($t)['compact'], '5 % d\'une carte de 900 × 200 : trop petit pour deux lignes');
        $this->assertSame([], UsageInsights::treemap([['value' => 0.0]]));
    }

    public function testFenetrePrecedente(): void
    {
        $this->assertSame(['from' => '2026-08-15', 'to' => '2026-09-13'], UsageInsights::previousWindow('2026-09-14', '2026-10-13'));
        $this->assertSame(['from' => '2026-09-30', 'to' => '2026-09-30'], UsageInsights::previousWindow('2026-10-01', '2026-10-01'));
        $this->assertNull(UsageInsights::previousWindow(null, null));
    }

    public function testCablageDeLaPage(): void
    {
        $this->assertStringContainsString("require ROOT . '/src/Billing/UsageInsights.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Billing/UsageInsights.php',", self::lire('tests/bootstrap.php'));
        $this->assertStringContainsString('UsageInsights::overview($from, $to, $userId, $byCategory, $compare)', self::lire('src/Controllers/AdminController.php'));

        $page = self::lire('templates/admin/usage.php');
        $this->assertStringContainsString('<button class="usage-tab active" data-tab="overview"><?= te("Vue d\'ensemble") ?></button>', $page);
        $this->assertStringContainsString("|| 'overview');", $page, 'Vue d\'ensemble par défaut');
        $this->assertStringContainsString("templates/admin/usage-overview.php", $page);
        $this->assertStringContainsString('href="/admin/budget"', $page, 'Les euros sont renvoyés au Budget');
        $debut = strpos($page, '<!-- KPIs période');
        $fin   = strpos($page, 'usage-eur-note');
        $this->assertNotFalse($debut);
        $this->assertNotFalse($fin);
        $this->assertStringNotContainsString('Format::eur', substr($page, $debut, $fin - $debut), 'Tuiles d\'en-tête sans euros');

        $ov = self::lire('templates/admin/usage-overview.php');
        $this->assertStringNotContainsString('Format::eur', $ov, 'Vue d\'ensemble sans euros');
        $this->assertStringNotContainsString('€', $ov);
        foreach (['Répartition de l\'usage', 'Adoption des fonctionnalités', 'Modèles', 'Assistants', 'Bases de connaissances',
                  'usage-treemap', 'usage-split-list'] as $attendu) {
            $this->assertStringContainsString($attendu, $ov);
        }
    }
}
