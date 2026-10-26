<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Journaux') ?></title>
    <?php
        $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time();
        $logsCssVersion = @filemtime(ROOT . '/public/assets/css/logs.css') ?: time();
        $logsJsVersion  = @filemtime(ROOT . '/public/assets/js/admin-logs.js') ?: time();
    ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <link rel="stylesheet" href="/assets/css/logs.css?v=<?= (int)$logsCssVersion ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php $page = 'logs'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <?php $ongletsGroupe = AdminTabs::DIAGNOSTIC; require ROOT . '/templates/partials/admin-tabs.php'; ?>
    <?php
        /**
         * @var array{exists:bool,readable:bool,size:int,mtime:int} $stat
         * @var array{from:int,size:int,truncated:bool}              $read
         * @var LogQuery $query  @var array<string,mixed> $result
         * @var list<array<string,mixed>> $list  @var int|null $nextOffset
         * @var array<string,int> $sigCounts  @var int $now  @var int|null $coverageStart
         */
        $h   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $nb  = static fn(int $n): string => LogView::number($n);
        $tz  = $query->tz;
        $lc  = $result['levelCounts'];
        $spanLabel = LogQuery::spanLabel($query->period);

        // Verdict : ce que dit le journal en un mot.
        $problems = (int)$lc['critical'] + (int)$lc['error'];
        if ((int)$lc['critical'] > 0) {
            $verdict = ['critical', t('Pannes graves'), I18n::plural((int)$lc['critical'], '{n} critique sur {periode}', '{n} critiques sur {periode}', ['n' => $nb((int)$lc['critical']), 'periode' => $spanLabel])];
        } elseif ((int)$lc['error'] > 0) {
            $verdict = ['error', t('À surveiller'), I18n::plural((int)$lc['error'], '{n} erreur sur {periode}', '{n} erreurs sur {periode}', ['n' => $nb((int)$lc['error']), 'periode' => $spanLabel])];
        } elseif ((int)$lc['warning'] > 0) {
            $verdict = ['warning', t('Stable'), I18n::plural((int)$lc['warning'], 'aucune erreur, {n} avertissement sur {periode}', 'aucune erreur, {n} avertissements sur {periode}', ['n' => $nb((int)$lc['warning']), 'periode' => $spanLabel])];
        } else {
            $verdict = ['ok', t('Calme'), t('aucune erreur sur {periode}', ['periode' => $spanLabel])];
        }

        // Comparaison avec la période précédente (seulement si le journal la couvre).
        $trend = null;
        if ($result['previous'] !== null) {
            $prev = (int)$result['previous'];
            $cur  = (int)$result['current'];
            if ($prev === 0 && $cur === 0) {
                $trend = ['flat', t('0, comme la période précédente')];
            } elseif ($prev === 0) {
                $trend = ['up', t('0 sur la période précédente')];
            } else {
                $delta = (int)round(($cur - $prev) / $prev * 100);
                $trend = $delta === 0 ? ['flat', t('stable vs période précédente')]
                    : [$delta > 0 ? 'up' : 'down', t('{delta} % vs période précédente ({n})', ['delta' => ($delta > 0 ? '+' : '') . $delta, 'n' => $nb($prev)])];
            }
        }

        $last = $result['lastProblem'];
        $lastUrl = null;
        if ($last !== null) {
            $lastUrl = $last['incident'] !== null ? '/admin/logs?incident=' . $last['incident']
                : ($last['ts'] !== null ? $query->url([
                    'vue' => 'flux', 'periode' => 'perso', 'du' => LogQuery::formatLocal((int)$last['ts'] - 300, $tz),
                    'au' => LogQuery::formatLocal((int)$last['ts'] + 300, $tz), 'niveaux' => 'tous',
                    'domaine' => null, 'q' => null, 'sig' => null, 'incident' => null,
                ]) . '#t' . (int)$last['ts'] : null);
        }

        $histo   = $result['histogram'];
        $buckets = $histo['buckets'];
        $nBuckets = count($buckets);
        $tickEvery = max(1, (int)ceil($nBuckets / 6));
        $peak = null;
        foreach ($buckets as $b) {
            if ($b['total'] > 0 && ($peak === null || $b['total'] > $peak['total'])) {
                $peak = $b;
            }
        }
        $levelOrder = ['critical', 'error', 'warning', 'info'];

        $sigFilterGroup = null;
        if ($query->sig !== null) {
            foreach ($result['groups'] as $g) {
                if ($g['sig'] === $query->sig) { $sigFilterGroup = $g; break; }
            }
        }
        $hiddenParams = static function (array $omit) use ($query, $h): string {
            $out = '';
            foreach ($query->params() as $k => $v) {
                if (!in_array($k, $omit, true)) {
                    $out .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
                }
            }
            return $out;
        };
        $hasFilters = $query->source !== null || $query->search !== '' || $query->sig !== null
            || $query->incident !== null || !$query->levelsAreDefault() || $query->period === 'perso';
    ?>

    <div class="lgx" id="lgx"
         data-size="<?= (int)$read['size'] ?>"
         data-tz="<?= $h($tz->getName()) ?>"
         data-view="<?= $h($query->view) ?>"
         data-cookie="<?= $h(LogsController::TZ_COOKIE) ?>"
         data-search="<?= $h(implode("\n", $query->terms)) ?>">

        <header class="lgx-head">
            <div class="lgx-head-main">
                <div class="lgx-title-row">
                    <h1><?= te('Journaux') ?></h1>
                    <?php if ($stat['exists']): ?>
                        <span class="lgx-verdict is-<?= $h($verdict[0]) ?>" title="<?= $h($verdict[2]) ?>"><span class="lgx-verdict-dot" aria-hidden="true"></span><?= $h($verdict[1]) ?></span>
                    <?php endif; ?>
                </div>
                <p class="lgx-sub">
                    <?php if (!$stat['exists']): ?>
                        <?= te("Le journal serveur n'existe pas encore : il sera créé à la première entrée.") ?>
                    <?php elseif (!$stat['readable']): ?>
                        <?= te("Le journal serveur est illisible par l'application (droits du fichier).") ?>
                    <?php else: ?>
                        <span>errors.log · <?= $h(LogView::size($stat['size'])) ?></span>
                        <?php if ($coverageStart !== null): ?>
                            <span title="<?= $read['truncated'] ? te('Seule la fin du fichier est analysée ({taille})', ['taille' => LogView::size(LogFile::MAX_BYTES)]) : te('Le fichier entier est analysé') ?>">· <?= $read['truncated'] ? te('fin analysée depuis le {date}', ['date' => LogView::short($coverageStart, $tz, $now)]) : te('depuis le {date}', ['date' => LogView::short($coverageStart, $tz, $now)]) ?></span>
                        <?php endif; ?>
                        <?php if ($stat['mtime'] > 0): ?>
                            <span>· <?= te('dernière écriture') ?> <span id="lgxMtime" data-ts="<?= (int)$stat['mtime'] ?>"><?= $h(LogView::relative($stat['mtime'], $now)) ?></span></span>
                        <?php endif; ?>
                        <span class="lgx-tzlabel">· <?= te('heure de {fuseau}', ['fuseau' => str_replace('_', ' ', $tz->getName())]) ?></span>
                        <?php if ($stat['size'] > LogFile::LARGE_BYTES): ?>
                            <span class="lgx-warnchip" title="<?= te("Au-delà de {taille}, faites tourner le journal sur le serveur (logrotate) : l'écran n'analyse que la fin du fichier.", ['taille' => LogView::size(LogFile::LARGE_BYTES)]) ?>"><?= te('Fichier volumineux') ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="lgx-actions">
                <button type="button" class="lgx-live" id="lgxLive" aria-pressed="false" title="<?= te("Ajoute les nouvelles entrées au fil de l'eau") ?>"<?= $query->period === 'perso' ? ' disabled' : '' ?>>
                    <span class="lgx-live-dot" aria-hidden="true"></span><span class="lgx-live-label"><?= te('En direct') ?></span>
                </button>
                <a class="lgx-btn" href="<?= $h($query->url(['export' => '1'])) ?>" title="<?= te('Télécharger les entrées filtrées (.txt)') ?>">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2v8m0 0l-3-3m3 3l3-3M3 13h10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg><?= te('Exporter') ?>
                </a>
                <a class="lgx-btn" href="<?= $h($query->url()) ?>" title="<?= te('Relire le journal') ?>">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M13 8a5 5 0 1 1-1.5-3.6M13 2.5V5h-2.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg><?= te('Actualiser') ?>
                </a>
            </div>
        </header>

        <!-- Pouls : une tuile par gravité (bascule le filtre), problèmes distincts, dernière erreur. -->
        <section class="lgx-pulse" aria-label="<?= te('Synthèse de la période') ?>">
            <?php foreach ($levelOrder as $lv):
                $on = in_array($lv, $query->levels, true);
                $count = (int)$lc[$lv];
            ?>
                <a class="lgx-tile lv-<?= $lv ?><?= $on ? ' is-on' : '' ?><?= $count === 0 ? ' is-zero' : '' ?>"
                   href="<?= $h($query->url(['niveaux' => $query->toggledLevels($lv)])) ?>"
                   aria-pressed="<?= $on ? 'true' : 'false' ?>"
                   title="<?= $on ? te('Masquer les {gravite}', ['gravite' => mb_strtolower(LogCatalog::levelLabel($lv))]) : te('Afficher les {gravite}', ['gravite' => mb_strtolower(LogCatalog::levelLabel($lv))]) ?>">
                    <span class="lgx-tile-top"><span class="lgx-dot" aria-hidden="true"></span><?= $h(LogCatalog::levelLabel($lv)) ?><span class="lgx-tile-check" aria-hidden="true"></span></span>
                    <span class="lgx-tile-value"><?= $h($nb($count)) ?></span>
                    <?php if ($lv === 'error' && $trend !== null): ?>
                        <span class="lgx-tile-sub lgx-trend is-<?= $h($trend[0]) ?>"><?= $h($trend[1]) ?></span>
                    <?php else: ?>
                        <span class="lgx-tile-sub"><?= $on ? te('affichées') : te('masquées') ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <a class="lgx-tile lgx-tile-groups" href="<?= $h($query->url(['vue' => null, 'sig' => null])) ?>">
                <span class="lgx-tile-top"><?= te('Problèmes distincts') ?></span>
                <span class="lgx-tile-value"><?= $h($nb((int)$result['groupTotal'])) ?></span>
                <span class="lgx-tile-sub"><?= (int)$result['newCount'] > 0
                    ? str_replace(['{b}', '{/b}'], ['<span class="lgx-new">', '</span>'], htmlspecialchars(I18n::plural((int)$result['newCount'], '{b}{n} nouveau{/b} sur la période', '{b}{n} nouveaux{/b} sur la période', ['n' => $nb((int)$result['newCount'])])))
                    : te('aucun nouveau') ?></span>
            </a>
            <?php if ($last !== null && $last['ts'] !== null): ?>
                <a class="lgx-tile lgx-tile-last lv-<?= $h($last['level']) ?>"<?= $lastUrl !== null ? ' href="' . $h($lastUrl) . '"' : '' ?>>
                    <span class="lgx-tile-top"><?= te('Dernière erreur') ?></span>
                    <span class="lgx-tile-value lgx-tile-when" data-rel-ts="<?= (int)$last['ts'] ?>"><?= $h(LogView::relative((int)$last['ts'], $now)) ?></span>
                    <span class="lgx-tile-sub lgx-ellipsis"><?= $h(($last['tag'] !== null ? '[' . $last['tag'] . '] ' : '') . LogView::headline((string)($last['message'] ?: $last['raw']), 90)) ?></span>
                </a>
            <?php else: ?>
                <div class="lgx-tile lgx-tile-last is-zero">
                    <span class="lgx-tile-top"><?= te('Dernière erreur') ?></span>
                    <span class="lgx-tile-value">—</span>
                    <span class="lgx-tile-sub"><?= te('aucune dans le journal analysé') ?></span>
                </div>
            <?php endif; ?>
        </section>

        <!-- Barre d'outils : période, recherche. -->
        <section class="lgx-toolbar">
            <nav class="lgx-periods" aria-label="<?= te('Période') ?>">
                <?php foreach (array_keys(LogQuery::PERIOD_LABELS) as $p): if ($p === 'perso') continue; ?>
                    <a class="lgx-seg<?= $query->period === $p ? ' is-active' : '' ?>" href="<?= $h($query->url(['periode' => $p === LogQuery::DEFAULT_PERIOD ? null : $p, 'du' => null, 'au' => null])) ?>"<?= $query->period === $p ? ' aria-current="true"' : '' ?>><?= $h(LogQuery::periodLabel($p)) ?></a>
                <?php endforeach; ?>
                <details class="lgx-custom"<?= $query->period === 'perso' ? ' open' : '' ?>>
                    <summary class="lgx-seg<?= $query->period === 'perso' ? ' is-active' : '' ?>"><?= te('Personnalisé') ?></summary>
                    <form method="GET" action="/admin/logs" class="lgx-custom-form">
                        <?= $hiddenParams(['periode', 'du', 'au']) ?>
                        <input type="hidden" name="periode" value="perso">
                        <label><?= te('Du') ?> <input type="datetime-local" name="du" value="<?= $h($query->fromInput) ?>" class="form-input"></label>
                        <label><?= te('Au') ?> <input type="datetime-local" name="au" value="<?= $h($query->toInput) ?>" class="form-input"></label>
                        <button type="submit" class="btn-primary"><?= te('Appliquer') ?></button>
                    </form>
                </details>
            </nav>
            <form method="GET" action="/admin/logs" class="lgx-search" role="search">
                <?= $hiddenParams(['q', 'incident']) ?>
                <svg class="lgx-search-icon" viewBox="0 0 16 16" aria-hidden="true"><circle cx="7" cy="7" r="4.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <input type="search" name="q" id="lgxQ" value="<?= $h($query->search !== '' ? $query->search : ($query->incident ?? '')) ?>"
                       placeholder="<?= te("Rechercher : timeout, « mot exact », -exclure, ou un n° d'incident") ?>"
                       aria-label="<?= te('Rechercher dans le journal') ?>" autocomplete="off" spellcheck="false">
                <kbd class="lgx-kbd" aria-hidden="true">/</kbd>
            </form>
        </section>

        <nav class="lgx-sources" aria-label="<?= te('Domaine') ?>">
            <a class="lgx-source<?= $query->source === null ? ' is-active' : '' ?>" href="<?= $h($query->url(['domaine' => null])) ?>"><?= te('Tous les domaines') ?></a>
            <?php foreach (array_keys(LogCatalog::SOURCES) as $key):
                $count = (int)($result['sourceCounts'][$key] ?? 0);
                if ($count === 0 && $query->source !== $key) continue;
            ?>
                <a class="lgx-source<?= $query->source === $key ? ' is-active' : '' ?>" href="<?= $h($query->url(['domaine' => $query->source === $key ? null : $key])) ?>">
                    <?= $h(LogCatalog::sourceLabel($key)) ?> <span class="lgx-count"><?= $h($nb($count)) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($hasFilters): ?>
            <div class="lgx-active" aria-label="<?= te('Filtres actifs') ?>">
                <?php if ($query->incident !== null): ?>
                    <a class="lgx-pill" href="<?= $h($query->url(['incident' => null, 'periode' => null])) ?>" title="<?= te('Retirer ce filtre') ?>"><?= te('Incident') ?> <code><?= $h($query->incident) ?></code><span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php if ($query->sig !== null): ?>
                    <a class="lgx-pill" href="<?= $h($query->url(['sig' => null])) ?>" title="<?= te('Retirer ce filtre') ?>"><?= te('Problème : {texte}', ['texte' => LogView::headline($sigFilterGroup !== null ? (string)($sigFilterGroup['sample']['message'] ?: $sigFilterGroup['sample']['raw']) : $query->sig, 60)]) ?><span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php if ($query->search !== ''): ?>
                    <a class="lgx-pill" href="<?= $h($query->url(['q' => null])) ?>" title="<?= te('Retirer ce filtre') ?>"><?= te('« {label} »', ['label' => LogView::headline($query->search, 40)]) ?><span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php if ($query->source !== null): ?>
                    <a class="lgx-pill" href="<?= $h($query->url(['domaine' => null])) ?>" title="<?= te('Retirer ce filtre') ?>"><?= $h(LogCatalog::sourceLabel($query->source)) ?><span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php if (!$query->levelsAreDefault()): ?>
                    <a class="lgx-pill" href="<?= $h($query->url(['niveaux' => null])) ?>" title="<?= te('Revenir aux gravités par défaut') ?>"><?= $h(implode(' + ', array_map(static fn($l) => LogCatalog::levelLabel($l), $query->levels))) ?><span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php if ($query->period === 'perso'): ?>
                    <a class="lgx-pill" href="<?= $h($query->url(['periode' => null, 'du' => null, 'au' => null])) ?>" title="<?= te('Revenir aux 24 dernières heures') ?>"><?= $h(LogView::short($query->from, $tz, $now)) ?> → <?= $h(LogView::short($query->to, $tz, $now)) ?><span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <a class="lgx-clear" href="/admin/logs"><?= te('Tout effacer') ?></a>
            </div>
        <?php endif; ?>

        <!-- Frise : volume par tranche, empilé par gravité ; une barre = un zoom. -->
        <section class="lgx-card lgx-chart-card" aria-label="<?= te('Volume dans le temps') ?>">
            <div class="lgx-chart-head">
                <h2><?= te('Volume') ?> <span class="lgx-muted">· <?= te('par tranche de {duree}', ['duree' => LogView::span($histo['step'])]) ?></span></h2>
                <div class="lgx-legend">
                    <?php foreach ($levelOrder as $lv): if (!in_array($lv, $query->levels, true)) continue; ?>
                        <span class="lgx-legend-item lv-<?= $lv ?>"><span class="lgx-dot" aria-hidden="true"></span><?= $h(LogCatalog::levelLabel($lv)) ?></span>
                    <?php endforeach; ?>
                    <?php if ($peak !== null): ?><span class="lgx-muted lgx-peak"><?= te('pic : {n} · {quand}', ['n' => $nb($peak['total']), 'quand' => LogView::bucketRange($peak['from'], $peak['to'], $histo['step'], $tz)]) ?></span><?php endif; ?>
                </div>
            </div>
            <?php if ((int)$result['total'] === 0): ?>
                <div class="lgx-chart-empty"><?= te('Rien à tracer sur cette période avec ces filtres.') ?></div>
            <?php else: ?>
                <div class="lgx-chart" style="--n:<?= $nBuckets ?>">
                    <?php foreach ($buckets as $i => $b):
                        $pct = $histo['max'] > 0 ? round($b['total'] / $histo['max'] * 100, 2) : 0;
                        $tip = LogView::bucketRange($b['from'], $b['to'], $histo['step'], $tz) . ' — ' . ($b['total'] === 0 ? t('rien') : implode(', ', array_filter(array_map(
                            static fn($lv) => $b['counts'][$lv] > 0 ? LogView::levelCount($lv, (int)$b['counts'][$lv]) : null,
                            $levelOrder
                        ))));
                        $zoom = $query->url(['periode' => 'perso', 'du' => LogQuery::formatLocal($b['from'], $tz), 'au' => LogQuery::formatLocal($b['to'], $tz)]);
                    ?>
                        <a class="lgx-bar<?= $b['total'] === 0 ? ' is-empty' : '' ?>" href="<?= $h($zoom) ?>" data-tip="<?= $h($tip) ?>" aria-label="<?= $h($tip) ?>">
                            <span class="lgx-bar-stack" style="height:<?= $pct ?>%">
                                <?php foreach ($levelOrder as $lv): if ($b['counts'][$lv] <= 0) continue; ?>
                                    <span class="lgx-seg-<?= $lv ?>" style="flex-grow:<?= (int)$b['counts'][$lv] ?>"></span>
                                <?php endforeach; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="lgx-axis" aria-hidden="true" style="--n:<?= $nBuckets ?>">
                    <?php foreach ($buckets as $i => $b): ?>
                        <span><?= $i % $tickEvery === 0 ? $h(LogView::bucketLabel($b['from'], $histo['step'], $tz)) : '' ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Vue : problèmes regroupés, ou flux chronologique. -->
        <div class="lgx-viewbar">
            <nav class="lgx-views" aria-label="<?= te('Vue') ?>">
                <a class="lgx-view<?= $query->view === 'problemes' ? ' is-active' : '' ?>" href="<?= $h($query->url(['vue' => null])) ?>"<?= $query->view === 'problemes' ? ' aria-current="page"' : '' ?>>
                    <?= te('Problèmes') ?> <span class="lgx-count"><?= $h($nb((int)$result['groupTotal'])) ?></span>
                </a>
                <a class="lgx-view<?= $query->view === 'flux' ? ' is-active' : '' ?>" href="<?= $h($query->url(['vue' => 'flux'])) ?>"<?= $query->view === 'flux' ? ' aria-current="page"' : '' ?>>
                    <?= te('Flux') ?> <span class="lgx-count"><?= $h($nb((int)$result['total'])) ?></span>
                </a>
            </nav>
            <?php if ($query->view === 'problemes' && $result['groupTotal'] > 1): ?>
                <nav class="lgx-sorts" aria-label="<?= te('Tri') ?>">
                    <span class="lgx-muted"><?= te('Trier :') ?></span>
                    <?php foreach (array_keys(LogQuery::SORTS) as $s): ?>
                        <a class="lgx-sort<?= $query->sort === $s ? ' is-active' : '' ?>" href="<?= $h($query->url(['tri' => $s === 'gravite' ? null : $s])) ?>"><?= $h(LogQuery::sortLabel($s)) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php elseif ($query->view === 'flux'): ?>
                <span class="lgx-muted lgx-hint"><kbd>j</kbd> <kbd>k</kbd> <?= te('parcourir') ?> · <kbd><?= te('Entrée') ?></kbd> <?= te('ouvrir') ?> · <kbd>/</kbd> <?= te('chercher') ?></span>
            <?php endif; ?>
        </div>

        <div class="lgx-livebar" id="lgxLiveBar" hidden>
            <span class="lgx-live-dot" aria-hidden="true"></span>
            <span id="lgxLiveText"></span>
            <a href="<?= $h($query->url()) ?>" class="lgx-livebar-reload"><?= te('Actualiser') ?></a>
        </div>

        <?php if (!$stat['exists'] || !$stat['readable']): ?>
            <div class="lgx-empty">
                <div class="lgx-empty-icon is-muted" aria-hidden="true">∅</div>
                <p><?= !$stat['exists'] ? te("Aucune entrée : le journal serveur n'a encore rien écrit.") : te("Le fichier {fichier} existe mais l'application ne peut pas le lire.", ['fichier' => 'storage/logs/errors.log']) ?></p>
            </div>
        <?php elseif ($query->view === 'problemes'): ?>
            <?php if ($result['groupTotal'] === 0): ?>
                <div class="lgx-empty">
                    <div class="lgx-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <p class="lgx-empty-title"><?= $hasFilters ? te('Rien ne correspond à ces filtres.') : te('Aucun problème sur {periode}.', ['periode' => $spanLabel]) ?></p>
                    <p class="lgx-muted">
                        <?php if ($query->period !== 'tout'): ?><a href="<?= $h($query->url(['periode' => 'tout', 'du' => null, 'au' => null])) ?>"><?= te('Remonter tout le journal') ?></a><?php endif; ?>
                        <?php if (!in_array('info', $query->levels, true)): ?> · <a href="<?= $h($query->url(['niveaux' => 'tous'])) ?>"><?= te('Afficher aussi les infos') ?></a><?php endif; ?>
                        <?php if ($hasFilters): ?> · <a href="/admin/logs"><?= te('Retirer les filtres') ?></a><?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <ol class="lgx-issues">
                    <?php foreach ($result['groups'] as $g):
                        $sample = $g['sample'];
                        $title  = LogView::headline((string)($sample['message'] !== '' ? $sample['message'] : $sample['raw']), 260);
                        $isSel  = $query->sig === $g['sig'];
                    ?>
                        <li class="lgx-issue lv-<?= $h($g['level']) ?><?= $isSel ? ' is-selected' : '' ?>">
                            <div class="lgx-issue-main">
                                <a class="lgx-issue-title" href="<?= $h($query->url(['vue' => 'flux', 'sig' => $g['sig']])) ?>">
                                    <?php if ($g['tag'] !== null): ?><span class="lgx-tag"><?= $h($g['tag']) ?></span><?php endif; ?>
                                    <span class="lgx-issue-text"><?= $h($title) ?></span>
                                </a>
                                <div class="lgx-issue-meta">
                                    <span class="lgx-lv"><span class="lgx-dot" aria-hidden="true"></span><?= $h(LogCatalog::levelLabel($g['level'], true)) ?></span>
                                    <span><?= $h(LogCatalog::sourceLabel($g['source'])) ?></span>
                                    <?php if ($g['location'] !== null): ?><code class="lgx-loc"><?= $h($g['location']) ?></code><?php endif; ?>
                                    <?php if ($g['new']): ?><span class="lgx-new"><?= te('Nouveau') ?></span><?php endif; ?>
                                    <?php if ($g['incidents'] > 0): ?><span class="lgx-muted"><?= htmlspecialchars(I18n::plural((int)$g['incidents'], '{n} incident', '{n} incidents', ['n' => $nb((int)$g['incidents'])])) ?></span><?php endif; ?>
                                </div>
                            </div>
                            <div class="lgx-issue-trend" title="<?= te('Répartition sur la période') ?>"><?= LogView::sparkline($g['buckets']) ?></div>
                            <div class="lgx-issue-when">
                                <?php if ($g['last'] !== null): ?>
                                    <span data-rel-ts="<?= (int)$g['last'] ?>" title="<?= te('Dernière : {date}', ['date' => LogView::dateTime((int)$g['last'], $tz)]) ?>"><?= $h(LogView::relative((int)$g['last'], $now)) ?></span>
                                    <?php if ($g['first'] !== null && $g['count'] > 1): ?><span class="lgx-muted" title="<?= te('Première sur la période : {date}', ['date' => LogView::dateTime((int)$g['first'], $tz)]) ?>"><?= te('depuis {date}', ['date' => LogView::short((int)$g['first'], $tz, $now)]) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="lgx-issue-count"><strong><?= $h($nb((int)$g['count'])) ?></strong><span><?= (int)$g['count'] === 1 ? te('fois', [], 'une') : te('fois') ?></span></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($result['groupTotal'] > count($result['groups'])): ?>
                    <?php $moreGroups = (int)$result['groupTotal'] - count($result['groups']); ?>
                    <p class="lgx-more-note"><?= str_replace(['{a}', '{/a}'], ['<a href="' . $h($query->url(['vue' => 'flux'])) . '">', '</a>'], htmlspecialchars(I18n::plural($moreGroups, '{n} problème de plus, moins prioritaire — affinez les filtres ou passez au {a}Flux{/a}.', '{n} problèmes de plus, moins prioritaires — affinez les filtres ou passez au {a}Flux{/a}.', ['n' => $nb($moreGroups)]))) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($list === []): ?>
                <div class="lgx-empty">
                    <div class="lgx-empty-icon is-muted" aria-hidden="true">∅</div>
                    <p class="lgx-empty-title"><?= te('Aucune entrée sur cette période avec ces filtres.') ?></p>
                    <p class="lgx-muted">
                        <?php if ($query->period !== 'tout'): ?><a href="<?= $h($query->url(['periode' => 'tout', 'du' => null, 'au' => null])) ?>"><?= te('Remonter tout le journal') ?></a><?php endif; ?>
                        <?php if (!in_array('info', $query->levels, true)): ?> · <a href="<?= $h($query->url(['niveaux' => 'tous'])) ?>"><?= te('Afficher aussi les infos') ?></a><?php endif; ?>
                        <?php if ($hasFilters): ?> · <a href="/admin/logs?vue=flux"><?= te('Retirer les filtres') ?></a><?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
            <div class="lgx-flux" id="lgxFlux">
                <?php $prevDay = ''; require ROOT . '/templates/admin/logs/entries.php'; ?>
            </div>
            <?php if ($nextOffset !== null): ?>
                <div class="lgx-more">
                    <button type="button" class="lgx-btn" id="lgxMore" data-offset="<?= (int)$nextOffset ?>">
                        <?= te('Afficher plus') ?> <span class="lgx-muted">· <?= htmlspecialchars(I18n::plural((int)$result['total'] - $nextOffset, '{n} restante', '{n} restantes', ['n' => $nb((int)$result['total'] - $nextOffset)])) ?></span>
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <p class="lgx-footnote"><?= te("Le journal contient des adresses e-mail et des adresses IP : ne le transmettez qu'à MyAlfred. L'écran lit le fichier, il ne le modifie jamais.") ?></p>
    </div>
</div>
<div class="lgx-tip" id="lgxTip" role="tooltip" hidden></div>
<script src="/assets/js/admin-logs.js?v=<?= (int)$logsJsVersion ?>"></script>
</body>
</html>
