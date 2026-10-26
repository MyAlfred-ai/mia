<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Budget IA mensuel : câblage (lecture du code). Les calculs sont dans
 * AiBudgetTest (pur) et AiBudgetGateTest (base).
 */
final class AiBudgetContractTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    /** Code PHP sans commentaires (les commentaires citent volontiers ce qu'on interdit). */
    private static function code(string $chemin): string
    {
        $out = '';
        foreach (token_get_all(self::lire($chemin)) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($tok) ? $tok[1] : $tok;
        }
        return $out;
    }

    private static function corpsDeMethode(string $src, string $methode): string
    {
        $debut = strpos($src, "function {$methode}(");
        self::assertNotFalse($debut, "Méthode introuvable : {$methode}");
        $suite = strpos($src, "\n    public function ", $debut + 10);
        $suitePriv = strpos($src, "\n    private function ", $debut + 10);
        $suiteStat = strpos($src, "\n    private static function ", $debut + 10);
        $fins = array_filter([$suite, $suitePriv, $suiteStat], static fn($p) => $p !== false);
        return substr($src, $debut, $fins ? min($fins) - $debut : null);
    }

    // ---- Chargement ---------------------------------------------------------

    public function testLaClasseEstChargeeDansLesDeuxListes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $this->assertStringContainsString('/src/Billing/AiBudget.php', self::lire($liste), "Absent de $liste");
        }
        // AiBudget lit UsageLog et Settings, CreditQuota lit AiBudget : ordre de chargement indifférent
        // (appels statiques résolus à l'exécution), mais la classe doit exister dans le worker aussi.
    }

    public function testLaMigrationPoseLesIndexCouvrants(): void
    {
        $sql = self::lire('database/migrations/076_usage_budget_indexes.sql');
        $this->assertStringContainsString('ON usage_logs(created_at, quota_exempt, priced, cost_eur)', $sql);
        $this->assertStringContainsString('ON tool_usage_logs(created_at, quota_exempt, priced, cost_eur)', $sql);
    }

    // ---- Barrière commune ------------------------------------------------------

    public function testCreditQuotaEstLaSeuleBarriere(): void
    {
        $quota = self::code('src/Helpers/CreditQuota.php');
        $this->assertStringContainsString('AiBudget::status()', $quota);
        $this->assertStringContainsString("\$status['budget_blocked']", $quota);

        // Personne d'autre ne décide « budget atteint » pour une demande.
        foreach (['src/Controllers/ChatController.php', 'src/Jobs/JobQueue.php'] as $f) {
            $this->assertStringNotContainsString('AiBudget::', self::code($f), "$f doit passer par CreditQuota");
        }
    }

    public function testLesRefusSuiventLaRaison(): void
    {
        $job = self::code('src/Jobs/JobQueue.php');
        $this->assertStringContainsString('CreditQuota::refusal($quotaStatus, true)', $job);
        $this->assertStringNotContainsString("'Quota de crédits atteint'", $job);

        $chat = self::code('src/Controllers/ChatController.php');
        $this->assertStringContainsString("CreditQuota::refusal(\$quotaStatus)['message']", $chat);
        $this->assertStringNotContainsString("'Quota de crédits atteint.'", $chat);

        $prompt = self::corpsDeMethode(self::code('src/Controllers/AdminController.php'), 'generateProfilePrompt');
        $this->assertStringContainsString('CreditQuota::REASON_BUDGET', $prompt);
        $this->assertStringContainsString('CreditQuota::isExceeded', $prompt);
    }

    // ---- Synchronisation automatique ----------------------------------------------

    public function testLaSynchroAutomatiqueSeMetEnPauseEtLaManuelleNon(): void
    {
        $kbSync = self::code('src/Sync/KbSync.php');
        $corps  = self::corpsDeMethode($kbSync, 'maybeRunDue');
        $garde  = strpos($corps, 'dueForSync($interval, $providers, 1,');
        $pause  = strpos($corps, 'AiBudget::blocksAutomaticSync()');
        $verrou = strpos($corps, 'acquireLock(');
        $this->assertNotFalse($garde);
        $this->assertNotFalse($pause, 'La synchro automatique doit consulter le budget');
        $this->assertNotFalse($verrou);
        $this->assertLessThan($pause, $garde, 'Après la garde cheap (aucune lecture du budget sans base due)');
        $this->assertLessThan($verrou, $pause, 'Avant le verrou et l\'horodatage : reprise dès le déblocage');

        $this->assertSame(1, substr_count($kbSync, 'AiBudget::'), 'Seul le déclencheur automatique est suspendu');
        $this->assertStringNotContainsString('AiBudget', self::corpsDeMethode(self::code('src/Controllers/AdminController.php'), 'runKnowledgeBaseSync'));
    }

    public function testLeMemoNeVoyagePasDansLesSauvegardes(): void
    {
        $this->assertContains('ai_budget_memo_json', BackupService::EXCLUDED_SETTINGS_KEYS);
        $this->assertNotContains(AiBudget::SETTING_MONTHLY, BackupService::EXCLUDED_SETTINGS_KEYS, 'La règle, elle, s\'exporte');
    }

    // ---- Côté utilisateur : jamais un euro -------------------------------------------

    public function testAucunMontantDuBudgetNeFuiteCoteUtilisateur(): void
    {
        $surfaces = [
            'templates/chat/index.php',
            'templates/partials/user-account-trigger.php',
            'templates/settings/usage.php',
            'public/assets/js/chat.js',
        ];
        foreach ($surfaces as $f) {
            $src = self::lire($f);
            foreach (['spent_eur', 'limit_eur', 'AiBudget::status', 'Coût réel'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $src, "$f ne doit pas exposer « $interdit »");
            }
        }
    }

    public function testLaModaleDistingueQuotaEtBudget(): void
    {
        $tpl = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('data-quota-reason="quota"', $tpl);
        $this->assertStringContainsString('data-quota-reason="budget" hidden', $tpl);
        $this->assertStringContainsString("te('Service IA suspendu')", $tpl);
        $this->assertStringContainsString('href="/admin/budget"', $tpl, 'Un admin bloqué doit pouvoir débloquer');

        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('function showQuotaModal(reason)', $js);
        $this->assertStringContainsString("status.reason === 'budget'", $js);
        $this->assertStringContainsString('showQuotaModal(payload.quota_status ? payload.quota_status.reason : null)', $js);

        $trigger = self::lire('templates/partials/user-account-trigger.php');
        $this->assertStringContainsString('CreditQuota::REASON_BUDGET', $trigger);
    }

    // ---- Page Coûts → Budget --------------------------------------------------

    public function testLesRoutesEtLesGardes(): void
    {
        $routes = self::lire('public/index.php');
        foreach ([
            "get('/admin/budget',"               => 'index',
            "post('/admin/budget/settings',"     => 'saveSettings',
            "post('/admin/budget/extra',"        => 'grantExtra',
            "post('/admin/budget/extra/delete'," => 'removeExtra',
        ] as $route => $methode) {
            $this->assertStringContainsString($route, $routes);
            $this->assertStringContainsString("[BudgetController::class, '{$methode}']", $routes);
        }
        $this->assertStringContainsString('/src/Controllers/BudgetController.php', self::lire('config/classes.php'));
        $ctrl = self::code('src/Controllers/BudgetController.php');
        // Chaque action passe par un droit du rôle (Personnes → Rôles) : la
        // page se voit avec « Coûts — Voir », chaque écriture exige « Modifier ».
        $this->assertSame(1, substr_count($ctrl, "AuthMiddleware::requirePermission('costs.view');"), 'La page se voit avec « Coûts — Voir »');
        $this->assertSame(3, substr_count($ctrl, "AuthMiddleware::requirePermission('costs.edit');"), 'Chaque écriture exige « Coûts — Modifier »');
        $this->assertStringNotContainsString('AuthMiddleware::requireAuth();', $ctrl);
        $this->assertStringContainsString('AjaxResponse::finish', $ctrl);
    }

    /**
     * Enregistrement MANUEL : un montant à moitié saisi suspendrait tout le
     * monde à la frappe. La page n'est donc pas dans GABARITS_AUTOSAVE.
     */
    public function testLaPageSEnregistreALaMainAvecConfirmation(): void
    {
        $tpl = self::lire('templates/admin/budget.php');
        $sansCommentaires = (string)preg_replace('#<\?php\s*/\*\*.*?\*/#s', '<?php', $tpl);
        $this->assertStringNotContainsString('data-autosave', $sansCommentaires);
        $this->assertStringNotContainsString('autosave-hint.php', $sansCommentaires);
        $this->assertStringContainsString('id="budgetSettingsForm"', $tpl);
        preg_match_all('#<button[^>]*type="submit"[^>]*>(.*?)</button>#s', $tpl, $m);
        // Libellés traduits : l'appel te('Enregistrer') du gabarit se lit « Enregistrer ».
        $libelles = array_map(static fn(string $l): string => trim(strip_tags((string)preg_replace('/<\?= te\(\'([^\']*)\'\) \?>/', '$1', $l))), $m[1]);
        $this->assertSame(1, count(array_filter($libelles, static fn(string $l): bool => $l === 'Enregistrer')));
        $this->assertContains('Accorder', $libelles);
        $this->assertContains('Retirer la rallonge', $libelles);
        $this->assertStringContainsString('confirm(', $tpl, 'Un montant déjà dépassé demande confirmation');
        $this->assertStringContainsString('Répartir entre les comptes', $tpl);
        $this->assertStringContainsString('Ce que le budget ne voit pas', $tpl);
    }

    /**
     * Cockpit : jauge et rythme, trajectoire jour par jour, puis la section
     * « Dépenses » qui suit la période choisie. Le calcul vit dans AiBudget
     * (pace, trajectory, periodBars, periodSummary, spendBreakdown,
     * providerBreakdown) ; les gabarits ne posent que des positions. Les
     * formulaires restent dans la page, repliés.
     */
    public function testLaPageEstUnCockpit(): void
    {
        $src  = self::code('src/Controllers/BudgetController.php');
        $ctrl = self::corpsDeMethode($src, 'index');
        $this->assertStringContainsString('AiBudget::pace($budget)', $ctrl);
        $this->assertStringContainsString('AiBudget::trajectory(', $ctrl);
        $this->assertSame(2, substr_count($ctrl, 'UsageLog::dailyCostBetween('), 'Trajectoire : mois en cours ET mois précédent');
        $this->assertStringContainsString('AiBudget::eurPerThousandCredits($rate)', $ctrl);
        $this->assertStringNotContainsString('UsageLog::byCategory(', $ctrl, 'Les catégories suivent la période, pas le mois');

        $tpl = self::lire('templates/admin/budget.php') . self::lire('templates/admin/budget-period.php');
        foreach (['class="budget-gauge', 'class="budget-chart"', 'preserveAspectRatio="none"', 'Trajectoire du mois',
                  "Où part l'argent", 'Par fournisseur', 'AiBudget::PACE_FAST', 'id="budget-settings"', 'id="budget-extra"',
                  'class="bc-hit"', 'class="bc-bar', 'class="bc-ref-line"', "te('de coût réel pour {n} crédits', ['n' => \$num(1000, 0)])"] as $attendu) {
            $this->assertStringContainsString($attendu, $tpl, "Absent des gabarits : $attendu");
        }
        $this->assertStringContainsString('openFromHash', $tpl, 'Les liens du bandeau ouvrent les formulaires repliés');
        $this->assertStringNotContainsString('new Chart(', $tpl, 'Aucune bibliothèque de graphiques : SVG rendu côté serveur');

        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('vector-effect: non-scaling-stroke', $css, 'Traits non étirés par le SVG étiré');
        $debut = strpos($css, '.budget-cockpit {');
        $fin   = strpos($css, '/* Communs au bandeau du tableau de bord');
        $this->assertNotFalse($debut);
        $this->assertNotFalse($fin);
        $bloc = substr($css, $debut, $fin - $debut);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\\b/', $bloc, 'Couleurs du cockpit = jetons du thème');
        $this->assertStringContainsString('var(--kb-warn)', $bloc);
        $this->assertStringContainsString('var(--kb-error)', $bloc);
        $this->assertStringContainsString('.budget-period', $bloc, 'La section « Dépenses » parle le langage du cockpit');
    }

    /**
     * Section « Dépenses » : la période vient de UsagePeriod (même catalogue que
     * Consommation), une seule lecture par jour sert l'histogramme, et la
     * section se rend SEULE (`?fragment=period`) pour que le sélecteur la
     * remplace sans recharger : elle ne dépend d'aucune variable de la page.
     */
    public function testLaSectionDepensesSuitLaPeriodeChoisie(): void
    {
        $src   = self::code('src/Controllers/BudgetController.php');
        $index = self::corpsDeMethode($src, 'index');
        $this->assertStringContainsString('UsagePeriod::resolve($_GET, self::DEFAULT_PERIOD, $now)', $index);
        $this->assertStringContainsString("public const DEFAULT_PERIOD = 'current_month';", $src, 'Mois en cours par défaut');
        $this->assertStringContainsString("(\$_GET['fragment'] ?? '') === 'period'", $index);
        $this->assertStringContainsString("require ROOT . '/templates/admin/budget-period.php';", $index);
        $this->assertLessThan(
            strpos($index, 'AiBudget::status('),
            strpos($index, "=== 'period'"),
            'Le fragment part AVANT le reste de la page : il ne paie que ses propres lectures'
        );

        $data = self::corpsDeMethode($src, 'periodData');
        $this->assertSame(1, substr_count($data, 'UsageLog::dailyCostBetween('), 'Une lecture par jour pour tout l\'histogramme');
        foreach (['UsagePeriod::bounded(', 'UsageLog::firstActivityDay()', 'UsagePeriod::previous(', 'AiBudget::periodBars(',
                  'AiBudget::periodSummary(', 'AiBudget::spendBreakdown(UsageLog::byCategory(', 'AiBudget::providerBreakdown(UsageLog::costByProvider('] as $attendu) {
            $this->assertStringContainsString($attendu, $data, "periodData : $attendu");
        }

        $section = self::lire('templates/admin/budget-period.php');
        $this->assertStringNotContainsString('<html', $section, 'Fragment autonome, sans ossature');
        $this->assertDoesNotMatchRegularExpression('/\$(budget|b|t|eur0|svgIcon|nbsp)\b/', (string)preg_replace('#/\*\*.*?\*/#s', '', $section), 'Aucune variable de budget.php : la section se rend seule');
        $this->assertStringContainsString('id="depenses"', $section);
        $this->assertStringContainsString("'swap'    => '#depenses'", $section, 'Le sélecteur remplace cette section, sans recharger');
        $this->assertStringContainsString("require ROOT . '/templates/partials/period-picker.php';", $section);
        $this->assertStringContainsString('UsagePeriod::query($sp[\'period\'])', $section, 'Le lien vers Consommation garde la période');

        $page = self::lire('templates/admin/budget.php');
        $this->assertStringContainsString("require ROOT . '/templates/admin/budget-period.php';", $page);
        $this->assertStringContainsString('/assets/js/period-picker.js', $page);
        $section = strpos($page, "require ROOT . '/templates/admin/budget-period.php';");
        $this->assertLessThan(strpos($page, 'id="budgetRulesTitle"'), $section, 'Dépenses avant les règles');
        $this->assertGreaterThan(strpos($page, 'id="budgetTrendTitle"'), $section, 'Dépenses après la trajectoire du mois');
    }

    /** Le sélecteur est PARTAGÉ : Consommation le rend aussi, avec la même source de périodes. */
    public function testLeSelecteurEstPartageAvecConsommation(): void
    {
        $admin = self::code('src/Controllers/AdminController.php');
        $this->assertStringContainsString("UsagePeriod::resolve(\$_GET, 'last_12_months', new DateTimeImmutable('now'))", self::corpsDeMethode($admin, 'resolveUsageFilters'));
        $this->assertStringNotContainsString("'year_to_date', 'all'], true)", $admin, 'Plus de liste de tranches recopiée');
        $usage = self::corpsDeMethode($admin, 'usage');
        $this->assertStringContainsString('UsagePeriod::previous($period, $from, $to)', $usage, 'Même période de comparaison que le Budget');
        $page = self::lire('templates/admin/usage.php');
        $this->assertStringContainsString("require ROOT . '/templates/partials/period-picker.php';", $page);
        $this->assertStringContainsString('/assets/js/period-picker.js', $page);
        $this->assertStringNotContainsString('usage-preset', $page, 'Les anciens boutons de période ont disparu');

        $partial = self::lire('templates/partials/period-picker.php');
        $this->assertStringContainsString('UsagePeriod::PRESETS', $partial, 'Les tranches viennent du catalogue');
        $this->assertStringContainsString('method="GET"', $partial, 'Marche sans JavaScript');
        $this->assertStringContainsString('<details class="period-custom', $partial);
        $this->assertStringContainsString('type="date" name="from"', $partial);
        $this->assertStringContainsString('type="date" name="to"', $partial);

        $js = self::lire('public/assets/js/period-picker.js');
        foreach (["searchParams.set('fragment', 'period')", 'history.pushState', "addEventListener('popstate'", 'location.href = dest.href', "e.key !== 'Escape'"] as $attendu) {
            $this->assertStringContainsString($attendu, $js, "period-picker.js : $attendu");
        }

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $this->assertStringContainsString('/src/Billing/UsagePeriod.php', self::lire($liste), "Absent de $liste");
        }
    }

    public function testLeMenuSignaleLeBudget(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringContainsString("\$navGroup('costs',", $nav);
        $this->assertSame('/admin/budget', AdminAccess::PAGE_HREFS['budget']);
        $this->assertStringContainsString('AiBudget::status()', $nav);
        $this->assertStringContainsString("\$budgetDotHtml  = \$budgetDot ? '<span class=\"' . \$budgetDotClass . '\" aria-hidden=\"true\"></span>' : '';", $nav);
        $this->assertStringContainsString("\$navSub('budget', [\$budgetAttr, \$budgetDotHtml, \$budgetHint])", $nav, 'Pastille sur « Budget »…');
        $this->assertStringContainsString("[\$costGAttr, '', \$costGHint], \$budgetDotHtml,", $nav, '… ET sur la rubrique');
        $this->assertStringContainsString("'nav-budget-dot'", $nav);
        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.nav-budget-dot.is-reached', $css);
    }

    public function testLeRepereEstAffecheLaOuSeReglentLesQuotas(): void
    {
        foreach (['templates/admin/users.php', 'templates/admin/user-groups.php'] as $tpl) {
            $this->assertStringContainsString('$budgetRepere', self::lire($tpl), "$tpl : repère absent");
        }
        $admin = self::code('src/Controllers/AdminController.php');
        foreach (['users', 'userGroups'] as $m) {
            $this->assertStringContainsString('AiBudget::repereForUsers($users)', self::corpsDeMethode($admin, $m));
        }
        $this->assertStringContainsString('id="creditsRealEurRate"', self::lire('templates/admin/credits.php'));
        $this->assertStringContainsString("'/admin/budget'", self::lire('src/Controllers/SetupController.php'));
    }

    /** L'alerte du tableau de bord compare ce que CreditQuota applique, pas le total (maintenance comprise). */
    public function testLAlerteDeQuotaDuTableauDeBordSuitLeQuotaApplique(): void
    {
        $index = self::corpsDeMethode(self::code('src/Controllers/AdminController.php'), 'index');
        $this->assertStringContainsString("\$row['quota_credits'] / \$quotaCredits", $index);
        $this->assertStringNotContainsString("\$row['total_credits'] / \$quotaCredits", $index);
        $this->assertStringContainsString('User::effectiveCreditQuotaKFrom(', $index);
    }

    // ---- Signaux côté administration ------------------------------------------------

    public function testLeTableauDeBordProjetteLeCoutReelEtSignaleLeBudget(): void
    {
        $index = self::corpsDeMethode(self::code('src/Controllers/AdminController.php'), 'index');
        $this->assertStringContainsString('AiBudget::status(true)', $index);
        $this->assertStringNotContainsString('projectedCredits', $index, 'La projection est en coût réel, plus en valorisation');
        $tpl = self::lire('templates/admin/index.php');
        $this->assertStringContainsString('budget-band', $tpl);
        $this->assertStringContainsString('$budgetAlert', $tpl);
        $this->assertStringContainsString('href="/admin/budget"', $tpl);
    }

    public function testLeDiagnosticAvertitSansJamaisEchouer(): void
    {
        require_once ROOT . '/src/Helpers/Requirements.php';
        require_once ROOT . '/src/Helpers/Diagnostics.php';
        $now = new DateTimeImmutable('2026-09-25 12:00:00');
        $cfg = ['enabled' => true, 'base_eur' => 100.0, 'extra_eur' => 0.0, 'alert_pct' => 80, 'on_reach' => 'block'];
        $cas = [
            'off'     => [AiBudget::evaluate(['enabled' => false, 'base_eur' => null] + $cfg, ['usage_eur' => 5.0, 'maintenance_eur' => 0.0, 'unpriced_ops' => 0], $now), 'INFO'],
            'ok'      => [AiBudget::evaluate($cfg, ['usage_eur' => 10.0, 'maintenance_eur' => 0.0, 'unpriced_ops' => 0], $now), 'OK'],
            'alert'   => [AiBudget::evaluate($cfg, ['usage_eur' => 85.0, 'maintenance_eur' => 0.0, 'unpriced_ops' => 0], $now), 'WARN'],
            'reached' => [AiBudget::evaluate($cfg, ['usage_eur' => 150.0, 'maintenance_eur' => 0.0, 'unpriced_ops' => 0], $now), 'WARN'],
            'unpriced'=> [AiBudget::evaluate($cfg, ['usage_eur' => 10.0, 'maintenance_eur' => 0.0, 'unpriced_ops' => 3], $now), 'WARN'],
        ];
        foreach ($cas as $nom => [$status, $attendu]) {
            $c = Diagnostics::controleBudgetIa($status);
            $this->assertSame('budget_ia', $c['id']);
            $this->assertSame(constant('Diagnostics::' . $attendu), $c['statut'] ?? $c['status'] ?? null, "Cas $nom");
            $this->assertNotSame(Diagnostics::FAIL, $c['statut'] ?? $c['status'] ?? null);
        }
        $this->assertStringContainsString("'budget_ia'      => [['type' => 'link', 'label' => t('Ouvrir le budget'), 'value' => '/admin/budget']]", self::lire('src/Helpers/Diagnostics.php'));
    }

    public function testLesBasesExpliquentLaPause(): void
    {
        $tpl = self::lire('templates/admin/knowledge-bases.php');
        $this->assertStringContainsString('$autoSyncPaused', $tpl);
        $this->assertStringNotContainsString('spent_eur', $tpl, 'Les créateurs voient cette page : aucun montant');
        $status = self::code('src/Sync/KbStatus.php');
        $this->assertStringContainsString("'auto_paused'", $status);
        $this->assertStringContainsString('Synchronisation suspendue', $status);
    }
}
