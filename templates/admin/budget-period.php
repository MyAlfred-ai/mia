<?php
/**
 * Coûts → Budget, section « Dépenses » : ce que l'IA a coûté sur la période
 * choisie (tranche prédéfinie ou plage personnalisée, sélecteur partagé avec
 * Consommation). Chiffres et comparaison, histogramme par jour, semaine ou
 * mois, catégories, fournisseurs.
 *
 * Rendue DANS la page (budget.php) et SEULE (`?fragment=period`), quand le
 * sélecteur la remplace sans recharger : elle ne dépend donc d'aucune variable
 * de la page — seulement de $spend (BudgetController::periodData). Tout le
 * calcul est dans AiBudget (periodBars, periodSummary, spendBreakdown,
 * providerBreakdown) et UsagePeriod ; ici, des positions déjà calculées.
 */
$sp      = $spend;
$spBars  = $sp['bars'];
$spSum   = $sp['summary'];
$spRange = $sp['range'];
$spPrev  = $spSum['previous'];
$spGrain = $spBars['grain'];

// Nombres dans la locale (mêmes règles que budget.php, sans en dépendre) :
// virgule décimale et espace insécable en français, point et virgule des
// milliers en anglais ; symbole € après (FR) ou avant (EN) le montant.
// `$spTrim` retire les zéros décimaux inutiles, jamais ceux d'un entier.
$spEn   = I18n::locale() === 'en';
$spNbsp = "\xC2\xA0";
$spNum  = static fn(float $v, int $d, string $grp = "\xC2\xA0"): string => $spEn
    ? number_format($v, $d, '.', $grp === '' ? '' : ',')
    : number_format($v, $d, ',', $grp);
$spTrim = static fn(float $v, int $d, string $grp = "\xC2\xA0"): string => $d > 0
    ? rtrim(rtrim($spNum($v, $d, $grp), '0'), $spEn ? '.' : ',')
    : $spNum($v, 0, $grp);
$spWithEur = static fn(string $n): string => $spEn ? '€' . $n : $n . $spNbsp . '€';
$spPct  = static fn(string $n): string => $spEn ? $n . '%' : $n . $spNbsp . '%';
$spEur  = static fn(float $v): string => $v >= 10
    ? $spWithEur($spNum(round($v), 0))
    : Format::eur($v);
$spAxis = static fn(float $v): string => $spWithEur($spTrim($v, 2));
$spIcon = static fn(string $paths, int $size = 20): string =>
    '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
// `{b}…{/b}` d'un texte déjà traduit (pluriels) : balises posées APRÈS l'échappement.
$spBold = static fn(string $text): string =>
    str_replace(['{b}', '{/b}'], ['<strong>', '</strong>'], htmlspecialchars($text));

// Vocabulaire de l'unité des barres : chaque phrase entière par unité, pour
// que l'ordre des mots et l'accord suivent la langue.
$spUnitTitle = match ($spGrain) {
    AiBudget::GRAIN_WEEK  => t('Dépense par semaine'),
    AiBudget::GRAIN_MONTH => t('Dépense par mois'),
    default               => t('Dépense par jour'),
};
// « 12 €/j », « 80 €/sem. », « 300 €/mois ».
$spPerUnit = static fn(string $montant): string => match ($spGrain) {
    AiBudget::GRAIN_WEEK  => t('{montant}/sem.', ['montant' => $montant]),
    AiBudget::GRAIN_MONTH => t('{montant}/mois', ['montant' => $montant]),
    default               => t('{montant}/j', ['montant' => $montant]),
};
// Barres au-dessus de leur part du budget (`{b}` = nombre en gras dans le
// sous-titre). Aucune : « aucun jour au-dessus de sa part du budget ».
$spOverText = static function (int $n, bool $strong) use ($spGrain): string {
    if ($n <= 0) {
        return match ($spGrain) {
            AiBudget::GRAIN_WEEK  => t('aucune semaine au-dessus de sa part du budget'),
            AiBudget::GRAIN_MONTH => t('aucun mois au-dessus de sa part du budget'),
            default               => t('aucun jour au-dessus de sa part du budget'),
        };
    }
    return match ($spGrain) {
        AiBudget::GRAIN_WEEK  => $strong
            ? I18n::plural($n, '{b}{n} semaine{/b} au-dessus de sa part du budget', '{b}{n} semaines{/b} au-dessus de leur part du budget')
            : I18n::plural($n, '{n} semaine au-dessus de sa part du budget', '{n} semaines au-dessus de leur part du budget'),
        AiBudget::GRAIN_MONTH => $strong
            ? I18n::plural($n, '{b}{n} mois{/b} au-dessus de sa part du budget', '{b}{n} mois{/b} au-dessus de leur part du budget')
            : I18n::plural($n, '{n} mois au-dessus de sa part du budget', '{n} mois au-dessus de leur part du budget'),
        default               => $strong
            ? I18n::plural($n, '{b}{n} jour{/b} au-dessus de sa part du budget', '{b}{n} jours{/b} au-dessus de leur part du budget')
            : I18n::plural($n, '{n} jour au-dessus de sa part du budget', '{n} jours au-dessus de leur part du budget'),
    };
};
$spPoints = static fn(array $pts): string => implode(' ', array_map(
    static fn(array $p): string => round($p['x'] * 10, 1) . ',' . round($p['y'] * 10, 1), $pts
));
$spConso = '/admin/usage?' . http_build_query(UsagePeriod::query($sp['period']));
// « le ven. 9 oct. », « la semaine du 6 oct. 2026 », « en sept. 2026 ».
$spWhen = static fn(array $bar): string => match ($spGrain) {
    AiBudget::GRAIN_WEEK  => t('la semaine du {date}', ['date' => UsagePeriod::rangeLabel($bar['start'], $bar['start'])]),
    AiBudget::GRAIN_MONTH => t('en {mois}', ['mois' => Format::monthLabel(substr($bar['start'], 0, 7))]),
    default               => t('le {date}', ['date' => $bar['label']]),
};

// Le budget de comparaison est celui d'AUJOURD'HUI : aucun historique n'est gardé.
$spPastMonths = substr($spRange['from'], 0, 7) !== (string)($sp['config']['month'] ?? '');
// Info-bulle d'une barre : « … — part du budget 12 € ».
$spRefTip   = static fn(string $texte, string $montant): string => $spPastMonths
    ? t('{texte} — part du budget actuel {montant}', ['texte' => $texte, 'montant' => $montant])
    : t('{texte} — part du budget {montant}', ['texte' => $texte, 'montant' => $montant]);
// Étiquette de la ligne de référence : « part du budget 12 €/j », « budget mensuel 500 € ».
$spRefLabel = static fn(string $montant): string => $spGrain === AiBudget::GRAIN_MONTH
    ? ($spPastMonths ? t('budget mensuel actuel {montant}', ['montant' => $montant]) : t('budget mensuel {montant}', ['montant' => $montant]))
    : ($spPastMonths ? t('part du budget actuel {montant}', ['montant' => $montant]) : t('part du budget {montant}', ['montant' => $montant]));

$picker = [
    'period'  => $sp['period'],
    'range'   => $spRange,
    'compare' => $spPrev !== null ? ['from' => $spPrev['from'], 'to' => $spPrev['to']] : null,
    'path'    => '/admin/budget',
    'anchor'  => 'depenses',
    'swap'    => '#depenses',
    'today'   => $sp['today'],
];
?>
<section class="budget-period" id="depenses" aria-labelledby="budgetPeriodTitle">
    <div class="card-head budget-period-head">
        <span class="card-ic" aria-hidden="true"><?= $spIcon('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>') ?></span>
        <div class="card-head-text">
            <h2 id="budgetPeriodTitle"><?= te('Dépenses') ?></h2>
            <p><?= te("Ce que l'IA a coûté sur la période de votre choix : une tranche toute prête ou vos propres dates.") ?></p>
        </div>
    </div>

    <?php require ROOT . '/templates/partials/period-picker.php'; ?>

    <div class="card budget-period-card">
        <div class="budget-tiles is-four">
            <div class="budget-tile">
                <span class="budget-tile-label"><?= te('Dépensé') ?></span>
                <span class="budget-tile-value"><?= htmlspecialchars($spEur((float)$spSum['total'])) ?></span>
                <span class="budget-tile-sub"<?= $spSum['budget_share'] !== null
                    ? ' title="' . ($spPastMonths
                        ? te('Part du budget actuel pour ces {n} jours (budget du mois ÷ ses jours) : {montant}', ['n' => (int)$spSum['days'], 'montant' => $spEur((float)$spSum['budget_total'])])
                        : te('Part du budget pour ces {n} jours (budget du mois ÷ ses jours) : {montant}', ['n' => (int)$spSum['days'], 'montant' => $spEur((float)$spSum['budget_total'])])) . '"'
                    : '' ?>><?php
                    if ($spSum['budget_share'] !== null) {
                        echo te('{n} % de la part du budget', ['n' => (int)$spSum['budget_share']]);
                    } else {
                        echo htmlspecialchars(I18n::plural((int)$spSum['operations'], '{n} opération', '{n} opérations', ['n' => Format::credits((int)$spSum['operations'])]));
                    }
                ?></span>
            </div>
            <div class="budget-tile">
                <span class="budget-tile-label"><?= te('Par jour') ?></span>
                <span class="budget-tile-value"><?= te('{montant}/j', ['montant' => $spEur((float)$spSum['per_day'])]) ?></span>
                <span class="budget-tile-sub"><?= htmlspecialchars(I18n::plural((int)$spSum['days'], 'en moyenne sur {n} jour', 'en moyenne sur {n} jours')) ?></span>
            </div>
            <?php
            $spEvo  = $spSum['evolution'];
            $spEvoTone = $spEvo === null ? '' : ($spEvo > 0.05 ? 'is-up' : ($spEvo < -0.05 ? 'is-down' : ''));
            ?>
            <div class="budget-tile <?= $spEvoTone ?>">
                <span class="budget-tile-label"><?= te('Évolution') ?></span>
                <?php if ($spPrev === null): ?>
                    <span class="budget-tile-value">—</span>
                    <span class="budget-tile-sub"><?= te('aucune période avant') ?></span>
                <?php elseif ($spEvo === null): ?>
                    <span class="budget-tile-value">—</span>
                    <span class="budget-tile-sub"><?= te('contre {montant} : trop peu pour comparer', ['montant' => $spEur((float)$spPrev['total'])]) ?></span>
                <?php else: ?>
                    <span class="budget-tile-value budget-evo"><?=
                        $spEvoTone === 'is-up'
                            ? $spIcon('<line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/>', 18)
                            : ($spEvoTone === 'is-down' ? $spIcon('<line x1="7" y1="7" x2="17" y2="17"/><polyline points="17 7 17 17 7 17"/>', 18) : '')
                    ?><?= ($spEvo > 0 ? '+' : ($spEvo < 0 ? '−' : '')) . htmlspecialchars($spPct($spTrim(abs((float)$spEvo), abs((float)$spEvo) < 10 ? 1 : 0))) ?></span>
                    <span class="budget-tile-sub" title="<?= htmlspecialchars(UsagePeriod::rangeLabel($spPrev['from'], $spPrev['to'])) ?>"><?= te('contre {montant} avant', ['montant' => $spEur((float)$spPrev['total'])]) ?></span>
                <?php endif; ?>
            </div>
            <div class="budget-tile">
                <span class="budget-tile-label"><?= te('Plus forte dépense') ?></span>
                <?php if ($spBars['peak'] !== null): ?>
                    <span class="budget-tile-value"><?= htmlspecialchars($spEur((float)$spBars['peak']['eur'])) ?></span>
                    <span class="budget-tile-sub"><?= htmlspecialchars($spWhen($spBars['peak'])) ?></span>
                <?php else: ?>
                    <span class="budget-tile-value">—</span>
                    <span class="budget-tile-sub"><?= te('aucune dépense') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Une proposition par élément présent, jointe par des virgules : chacune se traduit entière.
        $spParts = [t('{titre}, {periode} : {montant} au total', [
            'titre'   => $spUnitTitle,
            'periode' => UsagePeriod::rangeLabel($spRange['from'], $spRange['to']),
            'montant' => $spEur((float)$spSum['total']),
        ])];
        if ($spBars['peak'] !== null) { $spParts[] = t('pic {quand} ({montant})', ['quand' => $spWhen($spBars['peak']), 'montant' => $spEur((float)$spBars['peak']['eur'])]); }
        if ($spBars['average'] !== null) {
            $spAvg     = $spEur((float)$spBars['average']['eur']);
            $spParts[] = match ($spGrain) {
                AiBudget::GRAIN_WEEK  => t('moyenne {montant} par semaine', ['montant' => $spAvg]),
                AiBudget::GRAIN_MONTH => t('moyenne {montant} par mois', ['montant' => $spAvg]),
                default               => t('moyenne {montant} par jour', ['montant' => $spAvg]),
            };
        }
        if ($spBars['ref'] !== null) { $spParts[] = $spOverText((int)$spBars['over'], false); }
        $spLabel = implode(', ', $spParts);
        ?>
        <div class="budget-subhead">
            <h4><?= htmlspecialchars($spUnitTitle) ?></h4>
            <p><?php
                $bits = [];
                if ($spBars['peak'] !== null) {
                    $bits[] = teb('Pic {quand} : {b}{montant}{/b}', ['quand' => $spWhen($spBars['peak']), 'montant' => $spEur((float)$spBars['peak']['eur'])]);
                }
                if ($spBars['ref'] !== null && $spBars['peak'] !== null) {
                    $bits[] = $spBold($spOverText((int)$spBars['over'], true));
                }
                echo $bits !== [] ? implode(' · ', $bits) . '.' : te('Aucune dépense sur cette période.');
            ?></p>
        </div>
        <div class="budget-chart is-bars is-period is-<?= htmlspecialchars($spGrain) ?>" role="img" aria-label="<?= htmlspecialchars($spLabel) ?>">
            <div class="budget-chart-plot">
                <?php foreach ($spBars['y_ticks'] as $tick): ?>
                    <span class="bc-gridline" style="top:<?= $tick['y'] ?>%"></span>
                <?php endforeach; ?>
                <?php foreach ($spBars['bars'] as $bar):
                    $tip = t('{libelle} : {montant}', ['libelle' => $bar['label'], 'montant' => $spEur((float)$bar['eur'])]);
                    if ($bar['ref'] !== null) { $tip = $spRefTip($tip, $spEur((float)$bar['ref'])); }
                    if ($bar['current']) { $tip = t('{texte} (en cours)', ['texte' => $tip]); } ?>
                    <span class="bc-bar<?= $bar['over'] ? ' is-over' : '' ?><?= $bar['current'] ? ' is-today' : '' ?>"
                          style="left:<?= $bar['x'] ?>%;width:<?= $bar['w'] ?>%;height:<?= $bar['h'] ?>%"
                          title="<?= htmlspecialchars($tip) ?>"></span>
                <?php endforeach; ?>
                <?php if ($spBars['ref'] !== null && $spBars['ref']['points'] !== []): ?>
                    <svg viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true">
                        <polyline class="bc-ref-line" points="<?= $spPoints($spBars['ref']['points']) ?>"/>
                    </svg>
                <?php endif; ?>
                <?php if ($spBars['average'] !== null): ?>
                    <span class="bc-ref is-avg" style="top:<?= $spBars['average']['y'] ?>%"></span>
                <?php endif; ?>
                <div class="bc-overlay" aria-hidden="true">
                    <?php foreach ($spBars['y_ticks'] as $tick): ?>
                        <span class="bc-ytick" style="top:<?= $tick['y'] ?>%"><?= htmlspecialchars($spAxis((float)$tick['v'])) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($spBars['x_ticks'] as $tick): ?>
                        <span class="bc-xtick" style="left:<?= $tick['x'] ?>%"><?= htmlspecialchars($tick['label']) ?></span>
                    <?php endforeach; ?>
                    <?php if ($spBars['ref'] !== null): ?>
                        <span class="bc-label is-limit" style="top:<?= $spBars['ref']['y'] ?>%"><?= htmlspecialchars($spRefLabel($spGrain === AiBudget::GRAIN_MONTH ? $spEur((float)$spBars['ref']['eur']) : $spPerUnit($spEur((float)$spBars['ref']['eur'])))) ?></span>
                    <?php endif; ?>
                    <?php if ($spBars['average'] !== null): ?>
                        <span class="bc-label is-avg" style="top:<?= $spBars['average']['y'] ?>%"><?= te('moyenne {montant}', ['montant' => $spPerUnit($spEur((float)$spBars['average']['eur']))]) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="budget-legend">
            <span><i class="bl-swatch is-bar"></i><?= $spGrain === AiBudget::GRAIN_MONTH ? te('Dépense du mois') : ($spGrain === AiBudget::GRAIN_WEEK ? te('Dépense de la semaine') : te('Dépense du jour')) ?></span>
            <?php if ($spBars['ref'] !== null): ?>
                <span><i class="bl-swatch is-bar is-over"></i><?= te('Au-dessus de sa part du budget') ?></span>
                <span><i class="bl-swatch is-limit"></i><?= $spGrain === AiBudget::GRAIN_MONTH
                    ? ($spPastMonths ? te("Budget mensuel (au prorata d'un mois entamé), montant actuel") : te("Budget mensuel (au prorata d'un mois entamé)"))
                    : ($spPastMonths ? te('Part du budget (budget du mois ÷ ses jours), montant actuel') : te('Part du budget (budget du mois ÷ ses jours)')) ?></span>
            <?php endif; ?>
            <?php if ($spBars['average'] !== null): ?><span><i class="bl-swatch is-avg"></i><?= te('Moyenne de la période') ?></span><?php endif; ?>
        </div>
    </div>

    <div class="budget-grid is-even">
        <!-- ================= Où part l'argent ================= -->
        <section class="card budget-spend" aria-labelledby="budgetSpendTitle">
            <div class="card-head">
                <span class="card-ic" aria-hidden="true"><?= $spIcon('<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>') ?></span>
                <div class="card-head-text">
                    <h3 id="budgetSpendTitle"><?= te("Où part l'argent") ?></h3>
                    <p><?= te("Coût réel de la période par catégorie d'opération.") ?></p>
                </div>
            </div>
            <?php if ((float)$spSum['total'] > 0): ?>
                <div class="budget-split" role="img"
                     aria-label="<?= te('Usage des comptes {usage}, maintenance {maintenance}', ['usage' => $spEur((float)$spSum['usage_eur']), 'maintenance' => $spEur((float)$spSum['maintenance_eur'])]) ?>">
                    <span class="is-usage" style="flex-grow:<?= max(0.0, round((float)$spSum['usage_eur'], 4)) ?>"></span>
                    <span class="is-maint" style="flex-grow:<?= max(0.0, round((float)$spSum['maintenance_eur'], 4)) ?>"></span>
                </div>
                <div class="budget-legend is-compact">
                    <span><i class="bl-swatch is-usage"></i><?= te('Usage des comptes {montant}', ['montant' => $spEur((float)$spSum['usage_eur'])]) ?></span>
                    <span><i class="bl-swatch is-maint"></i><?= te('Maintenance {montant}', ['montant' => $spEur((float)$spSum['maintenance_eur'])]) ?></span>
                </div>
            <?php endif; ?>

            <?php $bd = $sp['breakdown']; ?>
            <?php if ($bd['rows'] === []): ?>
                <p class="budget-empty"><?= te('Aucune dépense sur cette période.') ?></p>
            <?php else: ?>
                <ul class="budget-cats">
                    <?php foreach ($bd['rows'] as $row): ?>
                        <li>
                            <span class="budget-cat-name"><i class="budget-cat-dot" style="background:<?= htmlspecialchars($row['color']) ?>"></i><?= htmlspecialchars($row['label']) ?><?php if ($row['maintenance']): ?><span class="budget-cat-tag" title="<?= te("N'entame aucun quota, compte dans le budget") ?>"><?= te('maintenance') ?></span><?php endif; ?></span>
                            <span class="budget-cat-amount"><?= htmlspecialchars($spEur((float)$row['cost_eur'])) ?></span>
                            <span class="budget-cat-bar"><span style="width:<?= max(1, (float)$row['share']) ?>%;background:<?= htmlspecialchars($row['color']) ?>"></span></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($bd['other'] !== null): ?>
                        <li class="is-other">
                            <span class="budget-cat-name"><i class="budget-cat-dot"></i><?= te('Autres ({n})', ['n' => (int)$bd['other']['count']]) ?></span>
                            <span class="budget-cat-amount"><?= htmlspecialchars($spEur((float)$bd['other']['cost_eur'])) ?></span>
                            <span class="budget-cat-bar"><span style="width:<?= max(1, (float)$bd['other']['share']) ?>%"></span></span>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>

            <?php if ((int)$spSum['unpriced_ops'] > 0): ?>
                <p class="budget-note is-warn">
                    <?= $spIcon('<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>') ?>
                    <span><?= htmlspecialchars(I18n::plural((int)$spSum['unpriced_ops'], '{n} opération sans tarif sur la période : son coût réel n\'est pas compté.', '{n} opérations sans tarif sur la période : leur coût réel n\'est pas compté.', ['n' => Format::credits((int)$spSum['unpriced_ops'])])) ?>
                    <a href="<?= htmlspecialchars($spConso) ?>#categories"><?= te('Voir lesquelles') ?></a>
                    · <a href="/admin/credits"><?= te('Tarifs & crédits') ?></a></span>
                </p>
            <?php endif; ?>
            <p class="budget-more"><a href="<?= htmlspecialchars($spConso) ?>"><?= te('Détail de la période dans Consommation →') ?></a></p>
        </section>

        <!-- ================= Par fournisseur ================= -->
        <?php
        $pv       = $sp['providers'];
        $pvLogos  = LLMFactory::logos() + ['gamma' => '/assets/img/gamma.svg', 'n8n' => '/assets/img/n8n.svg'];
        $pvLabel  = static fn(string $p): string => match ($p) {
            'other' => t('Outils supprimés depuis'),
            'n8n'   => 'n8n',
            'gamma' => 'Gamma',
            default => LLMFactory::providerLabel($p),
        };
        ?>
        <section class="card budget-providers" aria-labelledby="budgetProvidersTitle">
            <div class="card-head">
                <span class="card-ic" aria-hidden="true"><?= $spIcon('<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>') ?></span>
                <div class="card-head-text">
                    <h3 id="budgetProvidersTitle"><?= te('Par fournisseur') ?></h3>
                    <p><?= te("Ce que chaque fournisseur d'IA facturera pour la période, estimé d'après les tarifs.") ?></p>
                </div>
            </div>
            <?php if ($pv['rows'] === []): ?>
                <p class="budget-empty"><?= te('Aucune dépense sur cette période.') ?></p>
            <?php else: ?>
                <ul class="budget-cats budget-provs">
                    <?php foreach ($pv['rows'] as $row):
                        $logo = $pvLogos[$row['provider']] ?? null; ?>
                        <li title="<?= htmlspecialchars(I18n::plural((int)$row['operations'], '{n} opération', '{n} opérations', ['n' => Format::credits((int)$row['operations'])]) . ' · ' . t('{n} % de la dépense', ['n' => $spTrim((float)$row['share'], 1, '')])) ?>">
                            <span class="budget-cat-name"><?php if ($logo !== null): ?><img class="budget-prov-logo" src="<?= htmlspecialchars($logo) ?>" alt="" width="20" height="20" loading="lazy"><?php else: ?><span class="budget-prov-logo is-blank" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($pvLabel($row['provider']), 0, 1))) ?></span><?php endif; ?><?= htmlspecialchars($pvLabel($row['provider'])) ?></span>
                            <span class="budget-cat-amount"><?= htmlspecialchars($spEur((float)$row['cost_eur'])) ?></span>
                            <span class="budget-cat-bar"><span style="width:<?= max(1, (float)$row['share']) ?>%"></span></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($pv['other'] !== null): ?>
                        <li class="is-other">
                            <span class="budget-cat-name"><span class="budget-prov-logo is-blank" aria-hidden="true">+</span><?= te('Autres ({n})', ['n' => (int)$pv['other']['count']]) ?></span>
                            <span class="budget-cat-amount"><?= htmlspecialchars($spEur((float)$pv['other']['cost_eur'])) ?></span>
                            <span class="budget-cat-bar"><span style="width:<?= max(1, (float)$pv['other']['share']) ?>%"></span></span>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
            <p class="budget-more"><a href="/admin/credits"><?= te('Tarifs appliqués dans Tarifs & crédits →') ?></a></p>
        </section>
    </div>
</section>
