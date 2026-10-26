<?php
/**
 * Coûts → Budget : plafond mensuel du coût réel de l'IA (BudgetController).
 *
 * Cockpit : jauge et rythme du mois, trajectoire jour par jour, puis la
 * section « Dépenses » (budget-period.php : période au choix — tranche
 * prédéfinie ou plage personnalisée —, histogramme, catégories, fournisseurs),
 * règles résumées (formulaires dépliés à la demande), répartition.
 * Tout le calcul est dans AiBudget (pace, trajectory, periodBars,
 * periodSummary, spendBreakdown) : ce gabarit ne fait que poser des positions
 * déjà calculées.
 *
 * ENREGISTREMENT MANUEL, sans data-autosave ni mention d'auto-save : un montant
 * à moitié saisi suspendrait l'IA de toute l'organisation (cf. AiBudgetContractTest).
 *
 * Variables : $budget (AiBudget::status(true)), $extra, $extraBy, $global, $rate,
 * $activeIds, $ceiling, $repere, $lastWindow, $lastStats, $lastCost, $pace,
 * $trajectory, $eurPer1k, $spend (BudgetController::periodData), $success, $error.
 */
$b          = $budget;
$t          = $trajectory;
$monthLabel = Format::monthLabel($b['month']);
$resetLabel = CreditQuota::resetLabel($b['resets_on']);
$hasLimit   = $b['limit_eur'] !== null;
$stateBadges = [
    AiBudget::STATE_OFF     => ['badge-gray', t('Aucun budget')],
    AiBudget::STATE_OK      => ['badge-green', t('Sous contrôle')],
    AiBudget::STATE_ALERT   => ['badge-yellow', t("Seuil d'alerte atteint")],
    AiBudget::STATE_REACHED => $b['blocks']
        ? ['badge-red', t('Budget atteint : service suspendu')]
        : ['badge-red', t('Budget dépassé : signalement seul')],
];
[$stateClass, $stateLabel] = $stateBadges[$b['state']] ?? $stateBadges[AiBudget::STATE_OFF];
$tone = match ($b['state']) {
    AiBudget::STATE_REACHED => 'is-danger',
    AiBudget::STATE_ALERT   => 'is-warn',
    default                 => '',
};
// Nombres dans la locale : virgule décimale et espace insécable en français,
// point décimal et virgule des milliers en anglais (comme Format::). `$grp`
// vide = aucun séparateur de milliers (valeur d'un champ de saisie : une
// virgule y serait lue comme décimale). `$trim` retire les zéros décimaux
// inutiles (« 12,50 » → « 12,5 », « 12,00 » → « 12 »).
$en   = I18n::locale() === 'en';
$nbsp = "\xC2\xA0";
$num  = static fn(float $v, int $d, string $grp = "\xC2\xA0"): string => $en
    ? number_format($v, $d, '.', $grp === '' ? '' : ',')
    : number_format($v, $d, ',', $grp);
$trimNum = static fn(float $v, int $d, string $grp = "\xC2\xA0"): string => $d > 0
    ? rtrim(rtrim($num($v, $d, $grp), '0'), $en ? '.' : ',')
    : $num($v, 0, $grp);
// Symbole après le montant en français (« 12 € »), avant en anglais (« €12 »).
$withEur = static fn(string $n): string => $en ? '€' . $n : $n . $nbsp . '€';
$monthlyValue = $b['base_eur'] !== null ? $trimNum((float)$b['base_eur'], 2, '') : '';

// Montants : à l'euro près au-delà de 10 €, au centime en dessous.
$eur0 = static fn(float $v): string => $v >= 10
    ? $withEur($num(round($v), 0))
    : Format::eur($v);
$eurPlain = static fn(float $v): string => abs($v - round($v)) < 0.005 ? $eur0($v) : Format::eur($v);
$axisEur = static fn(float $v): string => $withEur($trimNum($v, 2));
// Phrase traduite et échappée dont `{a}…{/a}` marque le lien, posé APRÈS l'échappement.
$withLink = static fn(string $escaped, string $href): string =>
    str_replace(['{a}', '{/a}'], ['<a href="' . htmlspecialchars($href) . '">', '</a>'], $escaped);
// Même geste pour `{b}…{/b}` sur un texte déjà traduit (pluriels).
$bold = static fn(string $text): string =>
    str_replace(['{b}', '{/b}'], ['<strong>', '</strong>'], htmlspecialchars($text));
$dayLabel = static fn(string $ymd): string => Format::shortDate(new DateTimeImmutable($ymd));
$reachLabel = !empty($b['projection']['reach_date'])
    ? Format::shortDate(new DateTimeImmutable($b['projection']['reach_date']))
    : null;

// Jauge : cercle de rayon 48 (circonférence 2πr), seuil en repère.
$ringC   = 2 * M_PI * 48;
$ringPct = $hasLimit ? min(100, max(0, (int)$b['pct'])) : 0;

$svgIcon = static fn(string $paths): string =>
    '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$onReachLabel = $b['on_reach'] === AiBudget::ON_REACH_BLOCK ? t('Suspendre les demandes') : t('Signaler seulement');
$scaleX = static fn(float $pct): string => (string)round($pct * 10, 1);
$polyline = static fn(array $pts): string => implode(' ', array_map(
    static fn(array $p): string => round($p['x'] * 10, 1) . ',' . round($p['y'] * 10, 1), $pts
));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Budget IA') ?></title>
    <?php $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php $page = 'budget'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content budget-cockpit">
    <h1 style="margin-bottom:4px"><?= te('Budget IA') ?></h1>
    <p class="budget-intro">
        <?= te("Plafond mensuel de ce que l'IA coûte réellement à l'organisation : ce que facturent les fournisseurs, hors marge. Le budget protège l'organisation ; les quotas, en crédits, répartissent entre les personnes.") ?>
    </p>

    <?php if (!empty($success)): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!empty($b['unavailable'])): ?>
        <div class="alert alert-error" style="margin-bottom:16px"><?= te("La dépense du mois n'a pas pu être lue : en attendant, rien n'est suspendu. Consultez les journaux.") ?></div>
    <?php endif; ?>

    <?php if ($b['blocks']): ?>
        <div class="llm-setup-banner budget-blocked-banner" role="alert">
            <div class="llm-setup-banner-text">
                <div class="llm-setup-banner-title"><?= te('Service IA suspendu pour tous, administrateurs compris.') ?></div>
                <div class="llm-setup-banner-sub">
                    <?= te("Le budget de {mois} est atteint : les nouvelles demandes sont refusées jusqu'au {date} et la synchronisation automatique des bases est en pause.", ['mois' => $monthLabel, 'date' => $resetLabel]) ?>
                </div>
            </div>
            <div class="llm-setup-banner-actions">
                <a href="#budget-extra" class="btn-primary"><?= te('Accorder une rallonge') ?></a>
                <a href="#budget-settings" class="btn-secondary"><?= te('Relever le budget') ?></a>
            </div>
        </div>
    <?php endif; ?>

    <!-- ================= Ce mois-ci : jauge et rythme ================= -->
    <section class="card budget-hero" id="budget-month" aria-labelledby="budgetMonthTitle">
        <div class="budget-gauge <?= $tone ?>">
            <svg viewBox="0 0 120 120" role="img"
                 aria-label="<?= $hasLimit ? te('{n} % du budget consommé', ['n' => (int)$b['pct']]) : te('Aucun budget fixé') ?>">
                <circle class="budget-gauge-track" cx="60" cy="60" r="48"/>
                <?php if ($hasLimit && $ringPct > 0): ?>
                    <circle class="budget-gauge-fill" cx="60" cy="60" r="48"
                            stroke-dasharray="<?= round($ringC * $ringPct / 100, 2) ?> <?= round($ringC, 2) ?>"
                            transform="rotate(-90 60 60)"/>
                <?php endif; ?>
                <?php if ($hasLimit): ?>
                    <line class="budget-gauge-mark" x1="60" y1="5" x2="60" y2="17"
                          transform="rotate(<?= (int)$b['alert_pct'] * 3.6 ?> 60 60)"><title><?= te("Seuil d'alerte : {n} %", ['n' => (int)$b['alert_pct']]) ?></title></line>
                <?php endif; ?>
            </svg>
            <div class="budget-gauge-center">
                <?php if ($hasLimit): ?>
                    <span class="budget-gauge-value"><?= te('{n} %', ['n' => (int)$b['pct']]) ?></span>
                    <span class="budget-gauge-sub"><?= htmlspecialchars($eur0((float)$b['spent_eur'])) ?> / <?= htmlspecialchars($eur0((float)$b['limit_eur'])) ?></span>
                <?php else: ?>
                    <span class="budget-gauge-value"><?= htmlspecialchars($eur0((float)$b['spent_eur'])) ?></span>
                    <span class="budget-gauge-sub"><?= te('sans plafond') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="budget-hero-body">
            <div class="budget-hero-head">
                <h2 id="budgetMonthTitle"><?= htmlspecialchars(ucfirst($monthLabel)) ?></h2>
                <span class="badge <?= $stateClass ?>"><?= htmlspecialchars($stateLabel) ?></span>
                <?php if ($pace['key'] === AiBudget::PACE_FAST): ?>
                    <span class="budget-pace is-fast"><?= $svgIcon('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>') ?><?= te('Rythme trop rapide') ?></span>
                <?php elseif ($pace['key'] === AiBudget::PACE_STEADY && $b['state'] === AiBudget::STATE_ALERT): ?>
                    <span class="budget-pace is-steady"><?= te('Rythme tenable') ?></span>
                <?php endif; ?>
                <span class="budget-hero-reset"><?= te('Remise à zéro le {date}', ['date' => $resetLabel]) ?></span>
            </div>

            <div class="budget-tiles">
                <?php if ($hasLimit): ?>
                    <div class="budget-tile <?= $b['state'] === AiBudget::STATE_REACHED ? 'is-danger' : '' ?>">
                        <span class="budget-tile-label"><?= te('Reste') ?></span>
                        <span class="budget-tile-value"><?= htmlspecialchars($eur0((float)$b['remaining_eur'])) ?></span>
                        <span class="budget-tile-sub"><?= htmlspecialchars($b['state'] === AiBudget::STATE_REACHED ? t('plafond atteint') : I18n::plural((int)$b['days_left'], '{n} jour', '{n} jours')) ?></span>
                    </div>
                <?php else: ?>
                    <div class="budget-tile">
                        <span class="budget-tile-label"><?= te('Dépensé') ?></span>
                        <span class="budget-tile-value"><?= htmlspecialchars($eur0((float)$b['spent_eur'])) ?></span>
                        <span class="budget-tile-sub"><?= htmlspecialchars(I18n::plural((int)$b['days_left'], '{n} jour restant', '{n} jours restants')) ?></span>
                    </div>
                <?php endif; ?>

                <div class="budget-tile">
                    <span class="budget-tile-label"><?= te('Rythme', [], 'budget') ?></span>
                    <?php if ($pace['daily_eur'] !== null): ?>
                        <span class="budget-tile-value"><?= te('{montant}/j', ['montant' => $eur0((float)$pace['daily_eur'])]) ?></span>
                        <span class="budget-tile-sub"><?php
                            if ($pace['tenable_eur'] !== null && $b['state'] !== AiBudget::STATE_REACHED) {
                                echo te('tenable : {montant}/j', ['montant' => $eur0((float)$pace['tenable_eur'])]);
                            } else {
                                echo te('moyenne du mois');
                            }
                        ?></span>
                    <?php else: ?>
                        <span class="budget-tile-value">—</span>
                        <span class="budget-tile-sub"><?= te('mesuré à partir du 3') ?></span>
                    <?php endif; ?>
                </div>

                <?php $projTone = match ($pace['key']) {
                    AiBudget::PACE_FAST    => 'is-warn',
                    AiBudget::PACE_REACHED => 'is-danger',
                    default                => '',
                }; ?>
                <div class="budget-tile <?= $projTone ?>">
                    <span class="budget-tile-label"><?= te('Fin de mois') ?></span>
                    <?php if ($b['blocks']): ?>
                        <span class="budget-tile-value">≈ <?= htmlspecialchars($eur0((float)$b['spent_eur'])) ?></span>
                        <span class="budget-tile-sub"><?= te('demandes suspendues') ?></span>
                    <?php elseif ($b['projection'] !== null): ?>
                        <span class="budget-tile-value">≈ <?= htmlspecialchars($eur0((float)$b['projection']['projected_eur'])) ?></span>
                        <span class="budget-tile-sub"><?php
                            if ($reachLabel !== null) {
                                echo te('plafond le {date}', ['date' => $reachLabel]);
                            } elseif ($hasLimit && (float)$b['limit_eur'] > 0) {
                                echo te('{n} % du budget', ['n' => (int)floor($b['projection']['projected_eur'] / $b['limit_eur'] * 100)]);
                            } else {
                                echo te('au rythme actuel');
                            }
                        ?></span>
                    <?php else: ?>
                        <span class="budget-tile-value">—</span>
                        <span class="budget-tile-sub"><?= te('projection à partir du 3') ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$hasLimit): ?>
                <p class="budget-hero-cta"><?= te("Aucun plafond : l'IA peut dépenser sans limite ce mois-ci.") ?>
                    <a href="#budget-settings"><?= te('Fixer un budget') ?></a></p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ================= Trajectoire du mois ================= -->
    <section class="card budget-trend" aria-labelledby="budgetTrendTitle">
        <div class="card-head">
            <span class="card-ic" aria-hidden="true"><?= $svgIcon('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>') ?></span>
            <div class="card-head-text">
                <h3 id="budgetTrendTitle"><?= te('Trajectoire du mois') ?></h3>
                <p><?= $t['projection'] !== null ? te('Dépense cumulée jour par jour, prolongée au rythme actuel.') : te('Dépense cumulée jour par jour.') ?><span class="budget-hover-hint"> <?= te('Survolez un jour pour son détail.') ?></span></p>
            </div>
        </div>
        <?php
        // Une proposition par élément présent, jointe par des virgules : chacune se traduit entière.
        $chartParts = [t('Dépense cumulée de {mois} : {montant} à ce jour', ['mois' => $monthLabel, 'montant' => $eur0((float)$b['spent_eur'])])];
        if ($t['projection'] !== null) { $chartParts[] = t('environ {montant} projetés en fin de mois', ['montant' => $eur0((float)$t['projection']['eur'])]); }
        if ($t['limit'] !== null) {
            $chartParts[] = $t['reach'] !== null
                ? t('plafond {montant} atteint vers le {date}', ['montant' => $eur0((float)$t['limit']['eur']), 'date' => $dayLabel($t['reach']['date'])])
                : t('plafond {montant}', ['montant' => $eur0((float)$t['limit']['eur'])]);
        }
        $chartLabel = implode(', ', $chartParts);
        if ($t['last'] !== null) { $chartLabel = t('{texte} ; mois précédent : {montant}', ['texte' => $chartLabel, 'montant' => $eur0((float)$t['last']['total'])]); }
        $area = $t['points'];
        $lastPoint = end($area);
        ?>
        <div class="budget-chart" role="img" aria-label="<?= htmlspecialchars($chartLabel) ?>">
            <div class="budget-chart-plot">
                <svg viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true">
                    <?php foreach ($t['y_ticks'] as $tick): ?>
                        <line class="bc-grid" x1="0" x2="1000" y1="<?= $scaleX($tick['y']) ?>" y2="<?= $scaleX($tick['y']) ?>"/>
                    <?php endforeach; ?>
                    <?php if ($t['last'] !== null): ?>
                        <polyline class="bc-last" points="<?= $polyline($t['last']['points']) ?>"/>
                    <?php endif; ?>
                    <?php if ($t['alert'] !== null): ?>
                        <line class="bc-alert" x1="0" x2="1000" y1="<?= $scaleX($t['alert']['y']) ?>" y2="<?= $scaleX($t['alert']['y']) ?>"/>
                    <?php endif; ?>
                    <?php if ($t['limit'] !== null): ?>
                        <line class="bc-limit" x1="0" x2="1000" y1="<?= $scaleX($t['limit']['y']) ?>" y2="<?= $scaleX($t['limit']['y']) ?>"/>
                    <?php endif; ?>
                    <polygon class="bc-area" points="0,1000 <?= $polyline($area) ?> <?= $scaleX($lastPoint['x']) ?>,1000"/>
                    <polyline class="bc-line" points="<?= $polyline($t['points']) ?>"/>
                    <?php if ($t['projection'] !== null): $p = $t['projection']; ?>
                        <line class="bc-proj" x1="<?= $scaleX($p['x1']) ?>" y1="<?= $scaleX($p['y1']) ?>" x2="<?= $scaleX($p['x2']) ?>" y2="<?= $scaleX($p['y2']) ?>"/>
                    <?php endif; ?>
                    <line class="bc-today" x1="<?= $scaleX($t['today_x']) ?>" x2="<?= $scaleX($t['today_x']) ?>" y1="0" y2="1000"/>
                    <?php foreach ($t['points'] as $i => $pt): if ($i === 0) { continue; }
                        $prevX = $t['points'][$i - 1]['x']; ?>
                        <rect class="bc-hit" x="<?= $scaleX($prevX) ?>" y="0" width="<?= max(1, round(($pt['x'] - $prevX) * 10, 1)) ?>" height="1000"><title><?=
                            te('{jour} : +{montant} — {cumul} cumulés', ['jour' => $dayLabel((string)$pt['day']), 'montant' => $eur0((float)$pt['daily']), 'cumul' => $eur0((float)$pt['v'])])
                        ?></title></rect>
                    <?php endforeach; ?>
                </svg>
                <div class="bc-overlay" aria-hidden="true">
                    <?php foreach ($t['y_ticks'] as $tick): ?>
                        <span class="bc-ytick" style="top:<?= $tick['y'] ?>%"><?= htmlspecialchars($axisEur((float)$tick['v'])) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($t['x_ticks'] as $tick): ?>
                        <span class="bc-xtick" style="left:<?= $tick['x'] ?>%"><?= (int)$tick['day'] ?></span>
                    <?php endforeach; ?>
                    <?php if ($t['limit'] !== null): ?>
                        <span class="bc-label is-limit" style="top:<?= $t['limit']['y'] ?>%"><?= te('plafond {montant}', ['montant' => $eur0((float)$t['limit']['eur'])]) ?></span>
                    <?php endif; ?>
                    <?php if ($t['alert'] !== null && $t['limit'] !== null && ($t['alert']['y'] - $t['limit']['y']) >= 7): ?>
                        <span class="bc-label is-alert" style="top:<?= $t['alert']['y'] ?>%"><?= te('seuil {n} %', ['n' => (int)$t['alert']['pct']]) ?></span>
                    <?php endif; ?>
                    <span class="bc-today-label" style="left:<?= $t['today_x'] ?>%"><?= te("aujourd'hui") ?></span>
                    <span class="bc-dot" style="left:<?= $lastPoint['x'] ?>%;top:<?= $lastPoint['y'] ?>%"></span>
                    <?php if ($t['projection'] !== null): ?>
                        <span class="bc-proj-label" style="top:<?= $t['projection']['y2'] ?>%">≈ <?= htmlspecialchars($eur0((float)$t['projection']['eur'])) ?></span>
                    <?php endif; ?>
                    <?php if ($t['reach'] !== null): ?>
                        <span class="bc-dot is-danger" style="left:<?= $t['reach']['x'] ?>%;top:<?= $t['reach']['y'] ?>%"></span>
                        <span class="bc-reach-label" style="left:<?= $t['reach']['x'] ?>%;top:<?= $t['reach']['y'] ?>%"><?= htmlspecialchars($dayLabel($t['reach']['date'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="budget-legend">
            <span><i class="bl-swatch is-line"></i><?= te('Dépensé') ?></span>
            <?php if ($t['projection'] !== null): ?><span><i class="bl-swatch is-proj"></i><?= te('Projection') ?></span><?php endif; ?>
            <?php if ($t['last'] !== null): ?><span><i class="bl-swatch is-last"></i><?= htmlspecialchars(ucfirst(Format::monthLabel($t['last']['month']))) ?> (<?= htmlspecialchars($eur0((float)$t['last']['total'])) ?>)</span><?php endif; ?>
            <?php if ($t['limit'] !== null): ?><span><i class="bl-swatch is-limit"></i><?= te('Plafond') ?></span><?php endif; ?>
        </div>
    </section>

    <?php require ROOT . '/templates/admin/budget-period.php'; ?>

    <div class="budget-grid is-even">
        <!-- ================= Règles (enregistrement manuel) ================= -->
        <section class="card budget-rules" aria-labelledby="budgetRulesTitle">
            <div class="card-head">
                <span class="card-ic" aria-hidden="true"><?= $svgIcon('<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>') ?></span>
                <div class="card-head-text">
                    <h3 id="budgetRulesTitle"><?= te('Règles') ?></h3>
                    <p><?= te('Enregistrées au clic, jamais à la frappe.') ?></p>
                </div>
            </div>
            <dl class="budget-rows">
                <div><dt><?= te('Budget mensuel') ?></dt><dd><?= $b['base_eur'] !== null ? htmlspecialchars($eurPlain((float)$b['base_eur'])) : te('Aucun') ?></dd></div>
                <?php if ($b['enabled']): ?>
                    <div><dt><?= te('Alerte') ?></dt><dd><?= te('à {n} %', ['n' => (int)$b['alert_pct']]) ?></dd></div>
                    <div><dt><?= te('Au plafond') ?></dt><dd><?= htmlspecialchars($onReachLabel) ?></dd></div>
                    <div><dt><?= te('Rallonge de {mois}', ['mois' => $monthLabel]) ?></dt><dd><?php if ($extra !== null): ?>
                        <?= htmlspecialchars($eurPlain((float)$extra['eur'])) ?>
                        <small><?= $extraBy
                            ? te('le {date} par {nom}', ['date' => Format::dateFr($extra['at']), 'nom' => (string)$extraBy['name']])
                            : te('le {date}', ['date' => Format::dateFr($extra['at'])]) ?></small>
                    <?php else: ?><?= te('Aucune') ?><?php endif; ?></dd></div>
                <?php endif; ?>
            </dl>

            <div class="budget-edits">
                <?php // Sans budget, rien à résumer : le formulaire est posé ouvert, sans bouton qui le replierait.
                $settingsTag = $b['enabled'] ? 'details' : 'div'; ?>
                <<?= $settingsTag ?> class="budget-edit<?= $b['enabled'] ? '' : ' is-static' ?>" id="budget-settings"<?= $b['enabled'] ? ' name="budget-edit"' : '' ?>>
                    <?php if ($b['enabled']): ?><summary class="btn-secondary"><?= te('Modifier les règles') ?></summary><?php endif; ?>
                    <form method="POST" action="/admin/budget/settings" id="budgetSettingsForm"
                          data-spent="<?= htmlspecialchars(number_format((float)$b['spent_eur'], 2, '.', '')) ?>"
                          data-spent-label="<?= htmlspecialchars(Format::eur((float)$b['spent_eur'])) ?>"
                          data-extra="<?= htmlspecialchars(number_format((float)$b['extra_eur'], 2, '.', '')) ?>">
                        <?= Csrf::field() ?>
                        <div class="budget-fields">
                            <div class="form-group">
                                <label for="budgetMonthly"><?= te('Budget mensuel (€)') ?></label>
                                <input type="text" id="budgetMonthly" name="monthly_eur" class="form-input" inputmode="decimal"
                                       placeholder="<?= te('Aucun budget') ?>" value="<?= htmlspecialchars($monthlyValue) ?>" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="budgetAlert"><?= te('Alerte à (%)') ?></label>
                                <input type="number" id="budgetAlert" name="alert_pct" class="form-input" min="1" max="99"
                                       value="<?= (int)$b['alert_pct'] ?>">
                            </div>
                        </div>
                        <p class="profile-hint">
                            <?= $withLink(te("Coût réel facturé par les fournisseurs d'IA, hors taxes, estimé d'après les tarifs de {a}Tarifs & crédits{/a}. Vide = aucun budget ; 0 = service suspendu. L'alerte s'affiche au tableau de bord, dans le menu et au Diagnostic."), '/admin/credits') ?>
                        </p>
                        <fieldset class="form-group budget-fieldset">
                            <legend><?= te('Quand le budget est atteint') ?></legend>
                            <label class="budget-radio">
                                <input type="radio" name="on_reach" value="<?= AiBudget::ON_REACH_BLOCK ?>"<?= $b['on_reach'] === AiBudget::ON_REACH_BLOCK ? ' checked' : '' ?>>
                                <span><strong><?= te("Suspendre les nouvelles demandes jusqu'au 1er du mois suivant") ?></strong>
                                    <small><?= te('Recommandé. Tout le monde, administrateurs compris ; une rallonge débloque le mois.') ?></small></span>
                            </label>
                            <label class="budget-radio">
                                <input type="radio" name="on_reach" value="<?= AiBudget::ON_REACH_WARN ?>"<?= $b['on_reach'] === AiBudget::ON_REACH_WARN ? ' checked' : '' ?>>
                                <span><strong><?= te('Laisser passer, seulement signaler') ?></strong>
                                    <small><?= te('Utile le premier mois, pour calibrer le montant.') ?></small></span>
                            </label>
                        </fieldset>
                        <div class="budget-scope">
                            <p><?= teb("{b}Ce qui s'arrête{/b} : messages, régénérations, images, vidéos, pages web, cartes, présentations, dictée, rédaction assistée des assistants, synchronisations automatiques des bases.") ?></p>
                            <p><?= teb("{b}Ce qui continue, compté{/b} : dépôt de documents, « Synchroniser maintenant », réindexations, tests d'outils et de modèles, recherche des prix.") ?></p>
                        </div>
                        <button type="submit" class="btn-primary"><?= te('Enregistrer') ?></button>
                    </form>
                </<?= $settingsTag ?>>

                <?php if ($b['enabled']): ?>
                    <details class="budget-edit" id="budget-extra" name="budget-edit">
                        <summary class="btn-secondary"><?= $extra !== null ? te('Modifier la rallonge') : te('Accorder une rallonge') ?></summary>
                        <form method="POST" action="/admin/budget/extra" class="budget-inline-form">
                            <?= Csrf::field() ?>
                            <div class="form-group" style="margin:0;flex:1 1 160px">
                                <label for="budgetExtra"><?= $extra !== null ? te('Nouveau montant (€)') : te('Montant (€)') ?></label>
                                <input type="text" id="budgetExtra" name="extra_eur" class="form-input" inputmode="decimal" autocomplete="off"
                                       value="<?= $extra !== null ? htmlspecialchars($trimNum((float)$extra['eur'], 2, '')) : '' ?>" required>
                            </div>
                            <button type="submit" class="btn-secondary"><?= te('Accorder') ?></button>
                        </form>
                        <p class="profile-hint"><?= te("S'ajoute au budget de {mois} seulement ; elle expire d'elle-même le {date}.", ['mois' => $monthLabel, 'date' => $resetLabel]) ?></p>
                        <?php if ($extra !== null): ?>
                            <?php $sansRallonge = (float)$b['base_eur'] <= (float)$b['spent_eur'] && $b['on_reach'] === AiBudget::ON_REACH_BLOCK; ?>
                            <form method="POST" action="/admin/budget/extra/delete" style="margin-top:8px"
                                  <?= $sansRallonge ? 'data-confirm="' . te('Sans la rallonge, le budget est déjà atteint : le service sera suspendu immédiatement pour tous. Retirer quand même ?') . '"' : '' ?>
                                  onsubmit="return !this.dataset.confirm || confirm(this.dataset.confirm);">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-link-danger"><?= te('Retirer la rallonge') ?></button>
                            </form>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </div>
        </section>

        <!-- ================= Répartir entre les comptes ================= -->
        <section class="card budget-repere" id="budget-repere" aria-labelledby="budgetRepereTitle">
            <div class="card-head">
                <span class="card-ic" aria-hidden="true"><?= $svgIcon('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') ?></span>
                <div class="card-head-text">
                    <h3 id="budgetRepereTitle"><?= te('Répartir entre les comptes') ?></h3>
                    <p><?= te('Le quota qui garantit de rester sous le budget.') ?></p>
                </div>
            </div>
            <?php if ($repere !== null): ?>
                <p class="budget-repere-value"><?= te('≈ {n} crédits', ['n' => Format::credits($repere['per_account_credits'])]) ?>
                    <small><?= te('par compte et par mois ({n} dans un champ « k crédits »)', ['n' => (int)$repere['per_account_k']]) ?></small></p>
            <?php else: ?>
                <p class="budget-line"><?= te("Fixez un budget pour obtenir le quota qui garantit de ne jamais l'atteindre.") ?></p>
            <?php endif; ?>
            <?php if ($eurPer1k !== null): ?>
                <div class="budget-rates">
                    <div class="budget-rate">
                        <span class="budget-rate-value">≈ <?= htmlspecialchars($eurPer1k >= 0.1 ? $withEur($num($eurPer1k, 2, ' ')) : Format::eur($eurPer1k)) ?></span>
                        <span class="budget-rate-label"><?= te('de coût réel pour {n} crédits', ['n' => $num(1000, 0)]) ?> <span class="budget-help" title="<?= te('Coefficient {coef} ÷ valeur du crédit {valeur}. Approximatif : hors frais fixes, arrondi et minimum par opération, réglés dans Tarifs & crédits.', ['coef' => $num((float)$global['margin_multiplier'], 2, ' '), 'valeur' => Format::eur((float)$global['credit_value_eur'])]) ?>">?</span></span>
                    </div>
                    <div class="budget-rate">
                        <span class="budget-rate-value">≈ <?= htmlspecialchars(Format::credits((int)round($rate))) ?></span>
                        <span class="budget-rate-label"><?= te('crédits pour 1 € de coût réel') ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <p class="budget-line">
                <?= $bold(I18n::plural(count($activeIds), '{b}{n}{/b} compte actif', '{b}{n}{/b} comptes actifs', ['n' => Format::credits(count($activeIds))])) ?>
            </p>

            <?php
            $ceilPct = ($ceiling['credits'] > 0 && $b['enabled'] && (float)$b['base_eur'] > 0)
                ? (int)floor($ceiling['eur'] / (float)$b['base_eur'] * 100) : null;
            ?>
            <div class="budget-quota">
                <div class="budget-quota-head">
                    <span><?= te('Quotas en place') ?></span>
                    <span><?php
                        if ($ceiling['accounts'] === 0) {
                            echo te('aucun compte actif');
                        } elseif ($ceiling['credits'] > 0) {
                            echo te('≤ {montant} / mois', ['montant' => $eur0((float)$ceiling['eur'])]) . ($ceilPct !== null ? ' · ' . te('{n} %', ['n' => $ceilPct]) : '');
                        } else {
                            echo te('non bornés');
                        }
                    ?></span>
                </div>
                <?php if ($ceilPct !== null): ?>
                    <div class="budget-quota-bar <?= $ceilPct > 100 ? 'is-over' : '' ?>"><span style="width:<?= min(100, $ceilPct) ?>%"></span></div>
                <?php endif; ?>
                <?php if ($ceiling['unbounded'] > 0): ?>
                    <p class="budget-line"><?= htmlspecialchars(I18n::plural((int)$ceiling['unbounded'], '{n} compte sans aucun quota, que seul le budget limite.', '{n} comptes sans aucun quota, que seul le budget limite.')) ?></p>
                <?php endif; ?>
            </div>
            <p class="budget-line">
                <?= te('{mois} : {moyenne} crédits en moyenne par compte actif, {max} au maximum ; coût réel {montant}.', [
                    'mois'    => ucfirst(Format::monthLabel($lastWindow['month'])),
                    'moyenne' => Format::credits($lastStats['avg']),
                    'max'     => Format::credits($lastStats['max']),
                    'montant' => $eur0((float)$lastCost['usage_eur'] + (float)$lastCost['maintenance_eur']),
                ]) ?>
            </p>
            <p class="profile-hint">
                <?= $withLink(te("Un repère, pas une règle : les quotas se règlent dans {a}Personnes → Groupes{/a} (le plus généreux des groupes s'applique) ou sur un compte. La maintenance n'entame aucun quota mais compte dans le budget."), '/admin/user-groups') ?>
            </p>
        </section>
    </div>

    <details class="card budget-limits">
        <summary><?= te('Ce que le budget ne voit pas') ?></summary>
        <ul>
            <li><?= te('Les opérations sans tarif : leur coût réel est compté 0 € (elles sont signalées ci-dessus et dans Consommation).') ?></li>
            <li><?= te('Les modèles locaux, gratuits par construction.') ?></li>
            <li><?= te('Les crédits Gamma et les workflows n8n, facturés par ces services.') ?></li>
            <li><?= te("Les tests de clés de Modèles IA, qui n'entrent pas dans le grand livre.") ?></li>
            <li><?= te('Un léger dépassement reste possible : le contrôle a lieu avant chaque opération, donc une réponse en cours, une génération déjà en file ou une opération de maintenance peut finir au-delà du plafond.') ?></li>
            <li><?= te('Le mois est un mois civil en temps universel (UTC) : il bascule le 1er à 00 h 00 UTC. Les jours des dépenses aussi.') ?></li>
            <li><?= te("Aucun historique des budgets n'est gardé : sur une période passée, la part du budget est calculée avec le montant d'aujourd'hui.") ?></li>
            <li><?= te("La projection prolonge le rythme moyen du mois en cours : un pic ponctuel (réindexation d'une grande base) la gonfle jusqu'à ce que les jours suivants le diluent.") ?></li>
            <li><?= te("Les montants sont estimés d'après les tarifs configurés ou publics, convertis au taux de Tarifs & crédits : la facture du fournisseur peut différer.") ?></li>
        </ul>
    </details>
</div>
<script src="/assets/js/period-picker.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/period-picker.js') ?: 1) ?>"></script>
<script>
// Formulaires repliés : un lien vers #budget-settings ou #budget-extra (bandeau
// « service suspendu », « Fixer un budget ») les ouvre et les montre.
(function () {
    function openFromHash() {
        const id = (location.hash || '').slice(1);
        if (id !== 'budget-settings' && id !== 'budget-extra') return;
        const det = document.getElementById(id);
        if (!det) return;
        det.open = true;
        det.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        const field = det.querySelector('input[type="text"]');
        if (field && matchMedia('(hover: hover) and (pointer: fine)').matches) field.focus({ preventScroll: true });
    }
    window.addEventListener('hashchange', openFromHash);
    document.querySelectorAll('a[href="#budget-settings"], a[href="#budget-extra"]').forEach((a) => {
        a.addEventListener('click', () => setTimeout(openFromHash, 0));
    });
    openFromHash();
})();

// Confirmation : un montant déjà dépassé suspend l'IA de tout le monde, à l'instant.
(function () {
    const form = document.getElementById('budgetSettingsForm');
    if (!form) return;
    form.addEventListener('submit', (e) => {
        const raw = (form.monthly_eur.value || '').replace(/[\s\u00A0\u202F€]/g, '').replace(',', '.');
        const mode = (form.querySelector('input[name="on_reach"]:checked') || {}).value;
        if (raw === '' || mode !== 'block' || !/^\d+(\.\d+)?$/.test(raw)) return;
        const spent = parseFloat(form.dataset.spent || '0');
        const extra = parseFloat(form.dataset.extra || '0');
        if (parseFloat(raw) + extra <= spent) {
            const ok = confirm(<?= tj("Ce budget est déjà atteint ce mois-ci ({montant} dépensés) : toutes les nouvelles demandes seront suspendues immédiatement, administrateurs compris, jusqu'au 1er du mois ou jusqu'à une rallonge. Enregistrer quand même ?") ?>
                .replace('{montant}', form.dataset.spentLabel || ''));
            if (!ok) e.preventDefault();
        }
    });
})();
</script>
</body>
</html>
