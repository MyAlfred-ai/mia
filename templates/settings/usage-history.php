<?php
/**
 * Réglages → Usage & crédits, onglet « Historique » : la région #puHistory.
 *
 * Rendue DANS la page (usage.php) et SEULE (`?fragment=period|history`), quand
 * le sélecteur de période ou les filtres la remplacent sans recharger : elle
 * ne dépend donc que de $hx (UserSettingsController::usageHistory). Tout le
 * calcul vit dans UsageHistory (catalogue, filtres, facettes, histogramme,
 * lignes du journal) et UsagePeriod ; ici, des valeurs déjà prêtes.
 *
 * Marche SANS JavaScript : liens et formulaires GET. usage-history.js ajoute
 * le filtrage à la volée, « Afficher plus » sans recharger, et garde le focus.
 * Aucun euro : la personne raisonne en crédits.
 */
$hxF      = $hx['filters'];
$hxSum    = $hx['summary'];
$hxBars   = $hx['bars'];
$hxFacets = $hx['facets'];
$hxRange  = $hx['range'];
$hxGrain  = $hxBars['grain'];
$hxFiltered = UsageHistory::isFiltered($hxF);

// Adresse de l'historique avec la période et les filtres d'aujourd'hui, un
// filtre changé (null ou '' = retiré). Jamais d'`apres` : un filtre repart
// de la première page.
$hxUrl = static function (array $changes = []) use ($hx): string {
    $q = array_merge($hx['base'], UsageHistory::query($hx['filters']));
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = (string)$v;
        }
    }
    return '/settings/usage?' . http_build_query($q);
};
$hxSvg = static fn(string $paths, int $size = 18): string =>
    '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$hxCredits = static fn(int $n): string => I18n::plural($n, '{n} crédit', '{n} crédits', ['n' => Format::credits($n)]);
// « par jour », « par semaine », « par mois » : la phrase entière, pour que l'ordre suive la langue.
$hxUnitTitle = match ($hxGrain) {
    AiBudget::GRAIN_WEEK  => t('Crédits par semaine'),
    AiBudget::GRAIN_MONTH => t('Crédits par mois'),
    default               => t('Crédits par jour'),
};
$hxWhen = static fn(array $bar): string => match ($hxGrain) {
    AiBudget::GRAIN_WEEK  => t('la semaine du {date}', ['date' => UsagePeriod::rangeLabel($bar['start'], $bar['start'])]),
    AiBudget::GRAIN_MONTH => t('en {mois}', ['mois' => Format::monthLabel(substr($bar['start'], 0, 7))]),
    default               => t('le {date}', ['date' => $bar['label']]),
};

$picker = [
    'period'  => $hx['period'],
    'range'   => $hxRange,
    'compare' => $hx['compare'] !== null ? ['from' => $hx['compare']['from'], 'to' => $hx['compare']['to']] : null,
    'path'    => '/settings/usage',
    'keep'    => ['vue' => UserSettingsController::USAGE_HISTORY_VIEW] + UsageHistory::query($hxF),
    'swap'    => '#puHistory',
    'today'   => $hx['today'],
];
$hxOriginTotal = array_sum(array_column($hxFacets['origins'], 'credits'));
?>
<section class="pu-history" id="puHistory" aria-labelledby="puHistoryTitle" data-history-region>
    <h2 class="hx-sr" id="puHistoryTitle"><?= te('Historique de vos usages') ?></h2>

    <?php require ROOT . '/templates/partials/period-picker.php'; ?>
    <?php if ($hx['first'] !== null && ($hx['period']['preset'] ?? '') !== 'all'): ?>
        <p class="hx-since"><?= te('Votre historique remonte au {date}.', ['date' => UsagePeriod::rangeLabel($hx['first'], $hx['first'])]) ?>
            <a href="<?= htmlspecialchars('/settings/usage?' . http_build_query(['vue' => UserSettingsController::USAGE_HISTORY_VIEW, 'preset' => 'all'] + UsageHistory::query($hxF))) ?>" data-history-link><?= te('Tout afficher') ?></a></p>
    <?php endif; ?>

    <!-- ================= Filtres ================= -->
    <form class="card hx-filters" method="get" action="/settings/usage" role="search" data-history-filters
          aria-label="<?= te('Filtrer l’historique') ?>">
        <input type="hidden" name="vue" value="<?= htmlspecialchars(UserSettingsController::USAGE_HISTORY_VIEW) ?>">
        <?php foreach (UsagePeriod::query($hx['period']) as $hxName => $hxValue): ?>
            <input type="hidden" name="<?= htmlspecialchars($hxName) ?>" value="<?= htmlspecialchars($hxValue) ?>">
        <?php endforeach; ?>
        <?php if ($hxF['origin'] !== ''): ?>
            <input type="hidden" name="origine" value="<?= htmlspecialchars($hxF['origin']) ?>">
        <?php endif; ?>

        <?php
        // Sélecteurs actifs : sur téléphone, ils restent dépliés (on voit ce qui filtre).
        $hxSelectCount = (int)($hxF['type'] !== '') + (int)($hxF['model'] !== '') + (int)($hxF['profile'] !== '');
        ?>
        <div class="hx-searchrow">
        <label class="hx-search">
            <span class="hx-sr"><?= te('Rechercher dans l’historique') ?></span>
            <?= $hxSvg('<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>', 17) ?>
            <input type="search" name="q" value="<?= htmlspecialchars($hxF['q']) ?>" maxlength="<?= UsageHistory::SEARCH_MAX ?>"
                   autocomplete="off" enterkeyhint="search" data-history-search
                   placeholder="<?= te('Conversation, workflow, tâche, assistant, modèle, outil…') ?>">
        </label>
        <button type="button" class="hx-toggle" data-history-toggle aria-controls="hxSelects" aria-expanded="<?= $hxSelectCount > 0 ? 'true' : 'false' ?>">
            <?= $hxSvg('<line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/>', 17) ?>
            <span><?= te('Filtres') ?></span>
            <?php if ($hxSelectCount > 0): ?><b><?= $hxSelectCount ?></b><?php endif; ?>
        </button>
        </div>

        <div class="hx-selects<?= $hxSelectCount > 0 ? ' is-open' : '' ?>" id="hxSelects">
            <label class="hx-select">
                <span><?= te('Type d’usage') ?></span>
                <select name="type" class="form-input" data-history-select>
                    <option value=""><?= te('Tous les types') ?></option>
                    <?php foreach ($hxFacets['types'] as $hxKey => $hxType): ?>
                        <option value="<?= htmlspecialchars($hxKey) ?>"<?= $hxF['type'] === $hxKey ? ' selected' : '' ?>><?= htmlspecialchars(t('{libelle} ({n})', ['libelle' => UsageHistory::typeLabel($hxKey), 'n' => Format::credits((int)$hxType['credits'])])) ?></option>
                    <?php endforeach; ?>
                    <?php if ($hxF['type'] !== '' && !isset($hxFacets['types'][$hxF['type']])): ?>
                        <option value="<?= htmlspecialchars($hxF['type']) ?>" selected><?= htmlspecialchars(UsageHistory::typeLabel($hxF['type'])) ?></option>
                    <?php endif; ?>
                </select>
            </label>
            <label class="hx-select">
                <span><?= te('Modèle d’IA') ?></span>
                <select name="ia" class="form-input" data-history-select>
                    <option value=""><?= te('Tous les modèles') ?></option>
                    <?php foreach ($hxFacets['models'] as $hxModel): ?>
                        <option value="<?= htmlspecialchars($hxModel['key']) ?>"<?= $hxF['model'] === $hxModel['key'] ? ' selected' : '' ?>><?= htmlspecialchars($hxModel['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hx-select">
                <span><?= te('Assistant') ?></span>
                <select name="assistant" class="form-input" data-history-select>
                    <option value=""><?= te('Tous les assistants') ?></option>
                    <?php foreach ($hxFacets['profiles'] as $hxProfile): ?>
                        <option value="<?= htmlspecialchars($hxProfile['key']) ?>"<?= $hxF['profile'] === $hxProfile['key'] ? ' selected' : '' ?>><?= htmlspecialchars($hxProfile['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn-primary hx-submit"><?= te('Filtrer') ?></button>
        </div>

        <nav class="hx-origins" aria-label="<?= te('Origine') ?>">
            <a href="<?= htmlspecialchars($hxUrl(['origine' => null])) ?>" data-history-link data-focus="o:"
               class="hx-chip<?= $hxF['origin'] === '' ? ' is-active' : '' ?>"<?= $hxF['origin'] === '' ? ' aria-current="true"' : '' ?>>
                <span><?= te('Toutes les origines') ?></span><b><?= Format::credits((int)$hxOriginTotal) ?></b>
            </a>
            <?php foreach (UsageHistory::origins() as $hxOrigin):
                $hxFacet = $hxFacets['origins'][$hxOrigin] ?? null;
                if ($hxFacet === null && $hxF['origin'] !== $hxOrigin) { continue; }
                $hxActive = $hxF['origin'] === $hxOrigin; ?>
                <a href="<?= htmlspecialchars($hxUrl(['origine' => $hxActive ? null : $hxOrigin])) ?>" data-history-link data-focus="o:<?= htmlspecialchars($hxOrigin) ?>"
                   class="hx-chip<?= $hxActive ? ' is-active' : '' ?>" style="--o:<?= htmlspecialchars(UsageHistory::originColor($hxOrigin)) ?>"
                   title="<?= htmlspecialchars(UsageHistory::originHint($hxOrigin)) ?>"<?= $hxActive ? ' aria-current="true"' : '' ?>>
                    <span class="hx-chip-ic"><?= UsageHistory::icon($hxOrigin, 14) ?></span>
                    <span><?= htmlspecialchars(UsageHistory::originLabel($hxOrigin)) ?></span>
                    <b><?= Format::credits((int)($hxFacet['credits'] ?? 0)) ?></b>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($hxFiltered): ?>
            <div class="hx-active" aria-label="<?= te('Filtres actifs') ?>">
                <?php
                $hxActiveChips = [];
                if ($hxF['origin'] !== '') { $hxActiveChips[] = [UsageHistory::originLabel($hxF['origin']), 'origine']; }
                if ($hxF['type'] !== '')   { $hxActiveChips[] = [UsageHistory::typeLabel($hxF['type']), 'type']; }
                if ($hxF['model'] !== '') {
                    $hxModelLabel = '';
                    foreach ($hxFacets['models'] as $hxModel) { if ($hxModel['key'] === $hxF['model']) { $hxModelLabel = $hxModel['label']; } }
                    $hxActiveChips[] = [$hxModelLabel !== '' ? $hxModelLabel : $hxF['model'], 'ia'];
                }
                if ($hxF['profile'] !== '') {
                    $hxProfileLabel = '';
                    foreach ($hxFacets['profiles'] as $hxProfile) { if ($hxProfile['key'] === $hxF['profile']) { $hxProfileLabel = $hxProfile['label']; } }
                    $hxActiveChips[] = [$hxProfileLabel !== '' ? $hxProfileLabel : t('Assistant'), 'assistant'];
                }
                if ($hxF['q'] !== '') { $hxActiveChips[] = [t('« {texte} »', ['texte' => $hxF['q']]), 'q']; }
                ?>
                <?php foreach ($hxActiveChips as [$hxLabel, $hxParam]): ?>
                    <a class="hx-active-chip" href="<?= htmlspecialchars($hxUrl([$hxParam => null])) ?>" data-history-link
                       title="<?= te('Retirer ce filtre') ?>"><span><?= htmlspecialchars($hxLabel) ?></span><span aria-hidden="true">×</span><span class="hx-sr"><?= te('Retirer ce filtre') ?></span></a>
                <?php endforeach; ?>
                <a class="hx-clear" href="<?= htmlspecialchars('/settings/usage?' . http_build_query($hx['base'])) ?>" data-history-link><?= te('Effacer les filtres') ?></a>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($hxSum['operations'] === 0): ?>
        <!-- ================= Rien sur la période ================= -->
        <div class="card hx-empty">
            <span class="hx-empty-ic"><?= $hxSvg('<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>', 26) ?></span>
            <p class="hx-empty-title"><?= $hxFiltered ? te('Aucune opération ne correspond à ces filtres.') : te('Aucune opération sur cette période.') ?></p>
            <p class="pu-muted"><?= $hxFiltered
                ? te('Élargissez la période ou retirez un filtre.')
                : te('Vos réponses, recherches, créations et tâches apparaîtront ici, jour après jour.') ?></p>
            <?php if ($hxFiltered): ?>
                <p><a class="btn-secondary" href="<?= htmlspecialchars('/settings/usage?' . http_build_query($hx['base'])) ?>" data-history-link><?= te('Effacer les filtres') ?></a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>

    <!-- ================= Chiffres et histogramme ================= -->
    <section class="card hx-overview" aria-labelledby="hxOverviewTitle">
        <h3 class="hx-sr" id="hxOverviewTitle"><?= te('Vue d’ensemble de la période') ?></h3>
        <div class="hx-tiles">
            <div class="hx-tile">
                <span class="hx-tile-label"><?= te('Crédits') ?></span>
                <span class="hx-tile-value"><?= Format::credits((int)$hxSum['credits']) ?></span>
                <span class="hx-tile-sub"><?= (int)$hxSum['exempt'] > 0
                    ? te('dont {n} hors quota', ['n' => Format::credits((int)$hxSum['exempt'])])
                    : htmlspecialchars(I18n::plural((int)$hxBars['days'], 'sur {n} jour', 'sur {n} jours')) ?></span>
            </div>
            <div class="hx-tile">
                <span class="hx-tile-label"><?= te('Opérations') ?></span>
                <span class="hx-tile-value"><?= Format::credits((int)$hxSum['operations']) ?></span>
                <span class="hx-tile-sub"><?= te('≈ {n} crédits par opération', ['n' => Format::credits((int)$hxSum['per_op'])]) ?></span>
            </div>
            <div class="hx-tile">
                <span class="hx-tile-label"><?= te('Par jour') ?></span>
                <span class="hx-tile-value"><?= Format::credits((int)$hxSum['per_day']) ?></span>
                <span class="hx-tile-sub"><?= htmlspecialchars(I18n::plural((int)$hxBars['days'], 'en moyenne sur {n} jour', 'en moyenne sur {n} jours')) ?></span>
            </div>
            <?php
            $hxEvo = $hxSum['evolution'];
            $hxEvoTone = $hxEvo === null ? '' : ($hxEvo > 5 ? 'is-up' : ($hxEvo < -5 ? 'is-down' : ''));
            ?>
            <div class="hx-tile <?= $hxEvoTone ?>">
                <span class="hx-tile-label"><?= te('Évolution') ?></span>
                <?php if ($hxSum['previous'] === null): ?>
                    <span class="hx-tile-value">—</span>
                    <span class="hx-tile-sub"><?= ($hx['period']['preset'] ?? '') === 'all' ? te('aucune période avant') : te('pas encore d’historique avant') ?></span>
                <?php elseif ($hxEvo === null): ?>
                    <span class="hx-tile-value">—</span>
                    <span class="hx-tile-sub"><?= te('{n} crédits avant : trop peu pour comparer', ['n' => Format::credits((int)$hxSum['previous']['credits'])]) ?></span>
                <?php else: ?>
                    <span class="hx-tile-value"><?= ($hxEvo > 0 ? '+' : ($hxEvo < 0 ? '−' : '')) . htmlspecialchars(t('{n} %', ['n' => number_format(abs((float)$hxEvo), abs((float)$hxEvo) < 10 ? 1 : 0, I18n::locale() === 'en' ? '.' : ',', "\u{00A0}")])) ?></span>
                    <span class="hx-tile-sub" title="<?= htmlspecialchars(UsagePeriod::rangeLabel($hx['compare']['from'], $hx['compare']['to'])) ?>"><?= te('contre {n} crédits avant', ['n' => Format::credits((int)$hxSum['previous']['credits'])]) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $hxChartLabel = t('{titre}, {periode} : {n} au total', [
            'titre'   => $hxUnitTitle,
            'periode' => UsagePeriod::rangeLabel($hxRange['from'], $hxRange['to']),
            'n'       => $hxCredits((int)$hxBars['total']),
        ]);
        if ($hxBars['peak'] !== null) {
            $hxChartLabel .= ', ' . t('pic {quand} ({n})', ['quand' => $hxWhen($hxBars['peak']), 'n' => $hxCredits((int)$hxBars['peak']['credits'])]);
        }
        ?>
        <div class="hx-chart-head">
            <h4><?= htmlspecialchars($hxUnitTitle) ?></h4>
            <?php if ($hxBars['peak'] !== null): ?>
                <p><?= teb('Pic {quand} : {b}{n}{/b}', ['quand' => $hxWhen($hxBars['peak']), 'n' => $hxCredits((int)$hxBars['peak']['credits'])]) ?><?php if ($hxBars['average'] !== null): ?>
                    · <?= htmlspecialchars(match ($hxGrain) {
                        AiBudget::GRAIN_WEEK  => t('moyenne {n} par semaine', ['n' => Format::credits((int)$hxBars['average'])]),
                        AiBudget::GRAIN_MONTH => t('moyenne {n} par mois', ['n' => Format::credits((int)$hxBars['average'])]),
                        default               => t('moyenne {n} par jour', ['n' => Format::credits((int)$hxBars['average'])]),
                    }) ?><?php endif; ?></p>
            <?php endif; ?>
        </div>
        <div class="hx-chart is-<?= htmlspecialchars($hxGrain) ?>" role="img" aria-label="<?= htmlspecialchars($hxChartLabel) ?>">
            <div class="hx-plot">
                <?php foreach ($hxBars['y_ticks'] as $hxTick): ?>
                    <span class="hx-gridline" style="top:<?= $hxTick['y'] ?>%"></span>
                    <span class="hx-ytick" style="top:<?= $hxTick['y'] ?>%"><?= Format::credits((int)$hxTick['v']) ?></span>
                <?php endforeach; ?>
                <?php foreach ($hxBars['bars'] as $hxBar):
                    $hxTip = $hxBar['label'] . ' : ' . $hxCredits((int)$hxBar['credits']);
                    foreach ($hxBar['segments'] as $hxSeg) {
                        $hxTip .= "\n" . UsageHistory::originLabel($hxSeg['origin']) . ' : ' . Format::credits((int)$hxSeg['credits']);
                    }
                    if ($hxBar['current']) { $hxTip = t('{texte} (en cours)', ['texte' => $hxTip]); } ?>
                    <span class="hx-bar<?= $hxBar['current'] ? ' is-current' : '' ?>" style="left:<?= $hxBar['x'] ?>%;width:<?= $hxBar['w'] ?>%;height:<?= $hxBar['h'] ?>%"
                          title="<?= htmlspecialchars($hxTip) ?>">
                        <?php foreach ($hxBar['segments'] as $hxSeg): ?>
                            <i style="background:<?= htmlspecialchars(UsageHistory::originColor($hxSeg['origin'])) ?>;flex-grow:<?= (int)$hxSeg['credits'] ?>"></i>
                        <?php endforeach; ?>
                    </span>
                <?php endforeach; ?>
                <?php foreach ($hxBars['x_ticks'] as $hxI => $hxTick): // une sur deux masquée sur téléphone ?>
                    <span class="hx-xtick<?= $hxI % 2 === 1 ? ' is-alt' : '' ?>" style="left:<?= $hxTick['x'] ?>%"><?= htmlspecialchars($hxTick['label']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (count($hxBars['origins']) > 1): ?>
            <ul class="hx-legend">
                <?php foreach ($hxBars['origins'] as $hxOrigin): ?>
                    <li><i style="background:<?= htmlspecialchars(UsageHistory::originColor($hxOrigin)) ?>"></i><?= htmlspecialchars(UsageHistory::originLabel($hxOrigin)) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- ================= Par type d'usage ================= -->
    <?php
    $hxTypes = $hxFacets['types'];
    uasort($hxTypes, static fn(array $a, array $b): int => $b['credits'] <=> $a['credits']);
    $hxTypeTotal = max(1, array_sum(array_column($hxTypes, 'credits')));
    ?>
    <?php if (count($hxTypes) > 1 || $hxF['type'] !== ''): ?>
    <section class="card hx-types" aria-labelledby="hxTypesTitle">
        <div class="card-head">
            <span class="card-ic"><?= $hxSvg('<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="14" y2="12"/><line x1="4" y1="18" x2="9" y2="18"/>', 20) ?></span>
            <div class="card-head-text">
                <h3 id="hxTypesTitle"><?= te('Par type d’usage') ?></h3>
                <p><?= te('Touchez un type pour n’afficher que lui.') ?></p>
            </div>
        </div>
        <ul class="hx-type-list">
            <?php foreach ($hxTypes as $hxKey => $hxType):
                $hxActive = $hxF['type'] === $hxKey;
                $hxShare  = $hxType['credits'] / $hxTypeTotal * 100; ?>
                <li>
                    <a href="<?= htmlspecialchars($hxUrl(['type' => $hxActive ? null : $hxKey])) ?>" data-history-link data-focus="t:<?= htmlspecialchars($hxKey) ?>"
                       class="hx-type<?= $hxActive ? ' is-active' : '' ?>" style="--op:<?= htmlspecialchars(UsageHistory::typeColor($hxKey)) ?>"<?= $hxActive ? ' aria-current="true"' : '' ?>>
                        <span class="hx-type-ic"><?= UsageHistory::icon($hxKey, 14) ?></span>
                        <span class="hx-type-name"><?= htmlspecialchars(UsageHistory::typeLabel($hxKey)) ?></span>
                        <span class="hx-type-num"><?= Format::credits((int)$hxType['credits']) ?></span>
                        <span class="hx-type-bar" aria-hidden="true"><span style="width:<?= max(1.5, round($hxShare, 1)) ?>%"></span></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <!-- ================= Journal ================= -->
    <section class="card hx-journal" aria-labelledby="hxJournalTitle">
        <div class="hx-journal-head">
            <div>
                <h3 id="hxJournalTitle"><?= te('Opérations') ?></h3>
                <p class="pu-muted"><?= htmlspecialchars(I18n::plural((int)$hxSum['operations'], '{n} opération, la plus récente d’abord', '{n} opérations, les plus récentes d’abord', ['n' => Format::credits((int)$hxSum['operations'])])) ?></p>
            </div>
            <a class="btn-secondary hx-export" href="<?= htmlspecialchars($hxUrl(['export' => 'csv'])) ?>" download
               title="<?= te('Les opérations filtrées, une ligne par opération facturée (au plus {n}).', ['n' => Format::credits(UsageHistory::EXPORT_MAX)]) ?>">
                <?= $hxSvg('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>', 16) ?>
                <span><?= te('Exporter (CSV)') ?></span>
            </a>
        </div>
        <?php if ($hx['offset'] > 0): ?>
            <p class="hx-newer"><a href="<?= htmlspecialchars($hxUrl(['apres' => max(0, $hx['offset'] - UsageHistory::PAGE) ?: null])) ?>"><?= te('← Opérations plus récentes') ?></a></p>
        <?php endif; ?>
        <div class="hx-days" data-history-days>
            <?php require ROOT . '/templates/settings/usage-history-rows.php'; ?>
        </div>
    </section>
    <?php endif; ?>
</section>
