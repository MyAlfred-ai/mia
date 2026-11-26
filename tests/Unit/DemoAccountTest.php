<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Comptes de démonstration (DemoAccount) : formes pures, statut transmis au
 * navigateur, refus, point à régler, et câblage des écrans. Les lectures et
 * écritures en base sont éprouvées par tests/Functional/DemoAccountPipelineTest.
 */
final class DemoAccountTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function tearDown(): void
    {
        I18n::use('fr');
        parent::tearDown();
    }

    private function read(string $path): string
    {
        $text = file_get_contents(self::ROOT . '/' . $path);
        $this->assertNotFalse($text, "$path introuvable");
        return (string)$text;
    }

    // ---- Formes pures ----------------------------------------------------------

    public function testUnCompteEstEnDemoSeulementAvecUneEnveloppe(): void
    {
        $this->assertFalse(DemoAccount::isDemo(null));
        $this->assertFalse(DemoAccount::isDemo(['id' => 1]));
        $this->assertFalse(DemoAccount::isDemo(['id' => 1, 'demo_credits' => null]));
        $this->assertTrue(DemoAccount::isDemo(['id' => 1, 'demo_credits' => '5000']));
        $this->assertSame(5000, DemoAccount::allowance(['demo_credits' => '5000']));
        $this->assertNull(DemoAccount::allowance(['demo_credits' => null]));
    }

    public function testLaSaisieDeLEnveloppeEstToleranteMaisBornee(): void
    {
        $this->assertSame(5000, DemoAccount::normalizeCredits('5000'));
        $this->assertSame(5000, DemoAccount::normalizeCredits('5 000'));
        $this->assertSame(5000, DemoAccount::normalizeCredits("5\u{00A0}000"));
        $this->assertSame(5000, DemoAccount::normalizeCredits('5,000'));
        $this->assertSame(12, DemoAccount::normalizeCredits(12));
        $this->assertNull(DemoAccount::normalizeCredits(''));
        $this->assertNull(DemoAccount::normalizeCredits('0'));
        $this->assertNull(DemoAccount::normalizeCredits('-5'));
        $this->assertNull(DemoAccount::normalizeCredits('abc'));
        $this->assertNull(DemoAccount::normalizeCredits((string)(DemoAccount::MAX_CREDITS + 1)));
        $this->assertSame(DemoAccount::MAX_CREDITS, DemoAccount::normalizeCredits((string)DemoAccount::MAX_CREDITS));
    }

    public function testJamaisUnAdministrateur(): void
    {
        $this->assertFalse(DemoAccount::allowedForRole('admin'));
        $this->assertTrue(DemoAccount::allowedForRole('user'));
        $this->assertTrue(DemoAccount::allowedForRole('creator'));
        $this->assertTrue(DemoAccount::allowedForRole('commercial'), 'Un rôle personnalisé peut porter une démo');
    }

    public function testLeMessageDeFinDitQuiContacter(): void
    {
        $avecAdresse = DemoAccount::endedMessage('contact@myalfred.ai', 'MyAlfred.ai');
        $this->assertStringContainsString('Merci', $avecAdresse);
        $this->assertStringContainsString('vos conversations restent consultables', $avecAdresse);
        $this->assertStringContainsString('contact@myalfred.ai', $avecAdresse);

        $sansAdresse = DemoAccount::endedMessage('', 'MyAlfred.ai');
        $this->assertStringContainsString('l’équipe MyAlfred.ai', $sansAdresse);

        $rien = DemoAccount::endedMessage('', '  ');
        $this->assertStringContainsString('votre interlocuteur habituel', $rien);

        I18n::use('en');
        $this->assertStringContainsString('write to us at contact@myalfred.ai', DemoAccount::endedMessage('contact@myalfred.ai'));
        $this->assertSame('Your MIA demo has ended', DemoAccount::endedTitle());
    }

    public function testLeLienDeContactPorteUnObjetEncode(): void
    {
        $this->assertSame('', DemoAccount::mailtoHref(''));
        $href = DemoAccount::mailtoHref('contact@myalfred.ai');
        $this->assertStringStartsWith('mailto:contact@myalfred.ai?subject=', $href);
        $this->assertStringNotContainsString(' ', $href, 'Objet encodé (rawurlencode)');
    }

    // ---- Statut et refus (CreditQuota) -----------------------------------------

    public function testLEnveloppeTientLieuDeQuota(): void
    {
        $s = CreditQuota::compose(4_000, null, [], false, '2026-11-01', 5_000);
        $this->assertTrue($s['demo']);
        $this->assertSame(5_000, $s['quota_max']);
        $this->assertSame(1_000, $s['remaining']);
        $this->assertSame(80, $s['percent']);
        $this->assertNull($s['reason']);
        $this->assertFalse(CreditQuota::isExceeded($s));

        $fin = CreditQuota::compose(5_000, null, [], false, '2026-11-01', 5_000);
        $this->assertSame(CreditQuota::REASON_QUOTA, $fin['reason'], 'Le plafond personnel : tâches, workflows et jobs le traitent déjà');
        $this->assertTrue(CreditQuota::isExceeded($fin));
        $this->assertSame(0, $fin['remaining']);

        $ordinaire = CreditQuota::compose(10, 10, [], false, '2026-11-01');
        $this->assertFalse($ordinaire['demo']);
    }

    public function testLeBudgetDeLOrganisationResteLeFiletGlobal(): void
    {
        $s = CreditQuota::compose(100, null, [], true, '2026-11-01', 5_000);
        $this->assertSame(CreditQuota::REASON_BUDGET, $s['reason']);
        $this->assertTrue(CreditQuota::isExceeded($s));
        // Démo épuisée ET budget atteint : le plafond personnel d'abord.
        $both = CreditQuota::compose(5_000, null, [], true, '2026-11-01', 5_000);
        $this->assertSame(CreditQuota::REASON_QUOTA, $both['reason']);
    }

    public function testLeRefusDUnJobDitQueLaDemoEstEpuisee(): void
    {
        $fin = CreditQuota::compose(5_000, null, [], false, '2026-11-01', 5_000);
        $r = CreditQuota::refusal($fin, true);
        $this->assertSame(CreditQuota::REASON_QUOTA, $r['kind']);
        $this->assertSame('Votre démo de MIA est terminée', $r['title']);
        $this->assertStringContainsString('démonstration sont épuisés', $r['message']);

        $quota = CreditQuota::compose(10_000, 10, [], false, '2026-11-01');
        $this->assertSame('Quota de crédits atteint', CreditQuota::refusal($quota, true)['title'], 'Un compte ordinaire garde son message');
    }

    // ---- Point à régler --------------------------------------------------------

    public function testUneDemoEpuiseeSignaleSaufMasqueeOuDesactivee(): void
    {
        $base = ['name' => 'Prospect A', 'email' => 'a@x.fr', 'is_active' => true, 'exhausted' => true, 'muted' => false];
        $sig = AdminAttention::demoSignals([$base]);
        $this->assertCount(1, $sig);
        $this->assertSame('demos_epuisees', $sig[0]['id']);
        $this->assertSame('users', $sig[0]['entry']);
        $this->assertSame(AdminAttention::WARN, $sig[0]['level']);
        $this->assertStringContainsString('Prospect A', $sig[0]['text']);
        $this->assertSame('1 démo épuisée', $sig[0]['short']);

        $this->assertSame([], AdminAttention::demoSignals([['muted' => true] + $base]), 'Alerte masquée depuis la fiche');
        $this->assertSame([], AdminAttention::demoSignals([['is_active' => false] + $base]), 'Compte désactivé : déjà traité');
        $this->assertSame([], AdminAttention::demoSignals([['exhausted' => false] + $base]));

        $deux = AdminAttention::demoSignals([$base, ['name' => '', 'email' => 'b@x.fr'] + $base]);
        $this->assertSame('2 démos épuisées', $deux[0]['short']);
        $this->assertStringContainsString('b@x.fr', $deux[0]['text'], 'Sans nom, l’adresse');
    }

    // ---- Câblage ---------------------------------------------------------------

    public function testLaMigrationAjouteLesTroisColonnes(): void
    {
        $sql = $this->read('database/migrations/094_demo_accounts.sql');
        foreach (['demo_credits', 'demo_started_at', 'demo_alert_muted'] as $col) {
            $this->assertStringContainsString("ADD COLUMN $col", $sql);
        }
    }

    public function testLaClasseEstChargeeDesDeuxCotes(): void
    {
        $this->assertStringContainsString("/src/Billing/DemoAccount.php'", $this->read('config/classes.php'));
        $this->assertStringContainsString("'/src/Billing/DemoAccount.php'", $this->read('tests/bootstrap.php'));
    }

    public function testLaBarriereLitLEnveloppe(): void
    {
        $src = $this->read('src/Helpers/CreditQuota.php');
        $this->assertStringContainsString('DemoAccount::allowance($user)', $src);
        $this->assertStringContainsString('DemoAccount::usedCredits($user)', $src);
        $this->assertStringContainsString('quota_exempt = 0', $this->read('src/Models/UsageLog.php'));
    }

    public function testRoutesEtControleur(): void
    {
        $routes = $this->read('public/index.php');
        $this->assertStringContainsString("'/admin/users/{id}/demo',", $routes);
        $this->assertStringContainsString("'/admin/users/{id}/demo/convert',", $routes);
        $ctrl = $this->read('src/Controllers/AdminController.php');
        foreach (['public function startDemo', 'public function convertDemo', "DemoAccount::start((int)\$createdUser['id']", 'DemoAccount::setCredits(', 'DemoAccount::accounts()'] as $needle) {
            $this->assertStringContainsString($needle, $ctrl);
        }
        // Une démo ne devient jamais administrateur, ni à la création ni en changeant de rôle.
        $this->assertGreaterThanOrEqual(3, substr_count($ctrl, 'DemoAccount::allowedForRole('));
    }

    public function testEcranComptes(): void
    {
        $tpl = $this->read('templates/admin/users.php');
        foreach (['name="is_demo"', 'name="demo_credits"', 'name="demo_alert_muted"', 'name="demo_form"', 'id="euDemoConvert"', 'id="euDemoStart"', 'data-stat="demoended"', "'/demo/convert'"] as $needle) {
            $this->assertStringContainsString($needle, $tpl);
        }
        // Le champ de départ d'une démo n'est jamais posté par l'auto-save de la fiche.
        $this->assertMatchesRegularExpression('/id="euDemoStartCredits"[^\n]*data-autosave-skip/', $tpl);
    }

    public function testCoteUtilisateur(): void
    {
        $chat = $this->read('templates/chat/index.php');
        $this->assertStringContainsString('data-quota-reason="demo"', $chat);
        $this->assertStringContainsString('id="demoEndedBanner"', $chat);

        $js = $this->read('public/assets/js/chat.js');
        $this->assertMatchesRegularExpression("/status\\.demo === true && reason === 'quota' \\? 'demo'/", $js);
        $this->assertStringContainsString("t('{n} crédits de démo restants'", $js);
        $this->assertStringContainsString('syncDemoEndedBanner(', $js);

        $this->assertStringContainsString("te('{n} crédits de démo restants'", $this->read('templates/partials/user-account-trigger.php'));
        $this->assertStringContainsString("te('crédits de démo restants sur {max}'", $this->read('templates/settings/usage.php'));
    }

    public function testLePointARegleEstCollecte(): void
    {
        $this->assertStringContainsString('self::demoSignals(array_values(DemoAccount::accounts()))', $this->read('src/Helpers/AdminAttention.php'));
    }

    public function testDocumentation(): void
    {
        $this->assertStringContainsString('<!-- id: comptes-demo |', $this->read('docs/NOUVEAUTES.md'));
        $admin = $this->read('docs/user/admin/utilisateurs/comptes-de-demonstration.md');
        $this->assertStringContainsString('Convertir en compte normal', $admin);
        $this->assertStringContainsString('comptes-de-demonstration.md', $this->read('docs/user/admin/utilisateurs/index.md'));
        $this->assertStringContainsString('Comptes de démonstration', $this->read('docs/GUIDE-ADMIN.md'));
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLeGuideInterneEnParle(): void
    {
        $this->assertStringContainsString('Comptes de démonstration', $this->read('CLAUDE.md'));
    }
}
