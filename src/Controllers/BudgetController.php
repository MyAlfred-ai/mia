<?php

declare(strict_types=1);

/**
 * Page « Coûts → Budget » (admins seuls). Le moteur est dans AiBudget ;
 * ici : gardes, lecture des repères, enregistrement.
 *
 * ENREGISTREMENT MANUEL, exception assumée à l'auto-save (comme la page
 * Authentification) : un montant à moitié saisi — « 5 » en route vers « 500 » —
 * suspendrait l'IA de toute l'organisation à la frappe.
 */
class BudgetController
{
    /** Tranche affichée quand l'adresse n'en demande aucune. */
    public const DEFAULT_PERIOD = 'current_month';

    /**
     * GET /admin/budget(?preset=…|from=…&to=…)
     *
     * `?fragment=period` : la seule section « Dépenses », pour le sélecteur de
     * période qui la remplace sans recharger la page (period-picker.js).
     */
    public function index(): void
    {
        AuthMiddleware::requirePermission('costs.view');

        $now    = new DateTimeImmutable('now');
        $period = UsagePeriod::resolve($_GET, self::DEFAULT_PERIOD, $now);
        $spend  = self::periodData($period, $now);

        if (($_GET['fragment'] ?? '') === 'period') {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            require ROOT . '/templates/admin/budget-period.php';
            return;
        }

        $budget = AiBudget::status(true, $now);
        $extra  = AiBudget::extra($now);
        $extraBy = null;
        if ($extra !== null && $extra['by'] !== null) {
            $extraBy = User::findById((int)$extra['by']);
        }

        // Repères de répartition : comptes actifs, quotas en place, mois dernier.
        $global = CreditService::globalConfig();
        $rate   = CreditService::creditsPerRealEur($global);
        $active = array_values(array_filter(User::all(), static fn(array $u): bool => (int)($u['is_active'] ?? 0) === 1));
        $activeIds   = array_map(static fn(array $u): int => (int)$u['id'], $active);
        $groupQuotas = UserGroup::effectiveQuotasByUserIds($activeIds);
        $quotasK = [];
        foreach ($active as $u) {
            $quotasK[] = User::effectiveCreditQuotaKFrom($u, $groupQuotas[(int)$u['id']] ?? null);
        }
        $ceiling = AiBudget::quotaCeiling($quotasK, $rate);
        $repere  = $budget['enabled'] ? AiBudget::repere((float)$budget['base_eur'], count($activeIds), $rate) : null;

        $lastWindow = AiBudget::monthWindow($now->modify('first day of last month'));
        $lastStats  = AiBudget::consumptionStats(
            UsageLog::quotaCreditsByUserBetween($lastWindow['from'], $lastWindow['until']),
            $activeIds
        );
        $lastCost = UsageLog::costBetween($lastWindow['from'], $lastWindow['until']);

        // Cockpit : rythme et trajectoire du mois (la section « Dépenses »,
        // elle, suit la période choisie : periodData()).
        $pace       = AiBudget::pace($budget);
        $trajectory = AiBudget::trajectory(
            $budget,
            UsageLog::dailyCostBetween($budget['from'], $budget['until']),
            UsageLog::dailyCostBetween($lastWindow['from'], $lastWindow['until']),
            $now
        );
        $eurPer1k   = AiBudget::eurPerThousandCredits($rate);

        $success = Session::getFlash('budget_success');
        $error   = Session::getFlash('budget_error');

        require ROOT . '/templates/admin/budget.php';
    }

    /**
     * Tout ce que montre la section « Dépenses » pour une période : bornes
     * posées (« Tout » part du premier jour d'activité), histogramme, chiffres
     * et comparaison, catégories, fournisseurs. Une lecture par jour sert
     * l'histogramme ; le reste vient des agrégats du grand livre.
     */
    private static function periodData(array $period, DateTimeImmutable $now): array
    {
        $range    = UsagePeriod::bounded($period, $period['from'] === null ? UsageLog::firstActivityDay() : null, $now);
        $from     = $range['from'] . ' 00:00:00';
        $until    = (new DateTimeImmutable($range['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $config   = AiBudget::config($now);
        $bars     = AiBudget::periodBars($range, UsageLog::dailyCostBetween($from, $until), $config, $now);
        $previous = UsagePeriod::previous($period, $range['from'], $range['to']);
        if ($previous !== null) {
            $previous['cost'] = UsageLog::costBetween(
                $previous['from'] . ' 00:00:00',
                (new DateTimeImmutable($previous['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'
            );
        }

        return [
            'period'    => $period,
            'range'     => $range,
            'config'    => $config,
            'bars'      => $bars,
            'summary'   => AiBudget::periodSummary(UsageLog::costBetween($from, $until), $previous, $bars),
            'breakdown' => AiBudget::spendBreakdown(UsageLog::byCategory($range['from'], $range['to'])),
            'providers' => AiBudget::providerBreakdown(UsageLog::costByProvider($from, $until)),
            'today'     => $now->format('Y-m-d'),
        ];
    }

    /** POST /admin/budget/settings — montant, seuil, comportement au plafond. */
    public function saveSettings(): void
    {
        AuthMiddleware::requirePermission('costs.edit');
        $json = AjaxResponse::wanted();

        try {
            $amount = AiBudget::parseAmount((string)($_POST['monthly_eur'] ?? ''));
        } catch (InvalidArgumentException $e) {
            AjaxResponse::abort($json, 'budget_error', $e->getMessage(), '/admin/budget');
            return;
        }
        $admin = Session::currentUser();
        AiBudget::save(
            $amount,
            AiBudget::normalizeAlertPct($_POST['alert_pct'] ?? null),
            AiBudget::normalizeOnReach($_POST['on_reach'] ?? ''),
            isset($admin['id']) ? (int)$admin['id'] : null
        );

        AjaxResponse::finish(
            $json,
            'budget_success',
            $amount === null ? t('Budget IA retiré : aucun plafond ce mois-ci.') : t('Budget IA enregistré.'),
            '/admin/budget'
        );
    }

    /** POST /admin/budget/extra — accorde (ou remplace) la rallonge du mois. */
    public function grantExtra(): void
    {
        AuthMiddleware::requirePermission('costs.edit');
        $json = AjaxResponse::wanted();

        if (!AiBudget::config()['enabled']) {
            AjaxResponse::abort($json, 'budget_error', t('Fixez d\'abord un budget mensuel.'), '/admin/budget');
            return;
        }
        try {
            $amount = AiBudget::parseAmount((string)($_POST['extra_eur'] ?? ''));
        } catch (InvalidArgumentException $e) {
            AjaxResponse::abort($json, 'budget_error', $e->getMessage(), '/admin/budget');
            return;
        }
        if ($amount === null || $amount <= 0) {
            AjaxResponse::abort($json, 'budget_error', t('Indiquez le montant de la rallonge, en euros.'), '/admin/budget');
            return;
        }
        $admin = Session::currentUser();
        AiBudget::grantExtra($amount, isset($admin['id']) ? (int)$admin['id'] : 0);
        $window = AiBudget::monthWindow(new DateTimeImmutable('now'));

        AjaxResponse::finish(
            $json,
            'budget_success',
            t('Rallonge de {montant} accordée pour {mois} : elle expire d\'elle-même le {date}.', [
                'montant' => Format::eur($amount),
                'mois'    => Format::monthLabel($window['month']),
                'date'    => CreditQuota::resetLabel($window['resets_on']),
            ]),
            '/admin/budget'
        );
    }

    /** POST /admin/budget/extra/delete */
    public function removeExtra(): void
    {
        AuthMiddleware::requirePermission('costs.edit');
        $admin = Session::currentUser();
        AiBudget::removeExtra(isset($admin['id']) ? (int)$admin['id'] : null);
        AjaxResponse::finish(AjaxResponse::wanted(), 'budget_success', t('Rallonge retirée.'), '/admin/budget');
    }
}
