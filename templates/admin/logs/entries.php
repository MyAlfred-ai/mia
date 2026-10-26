<?php
/**
 * Entrées du Flux des journaux, séparées par journée. Rendu au chargement de
 * la page ET par GET /admin/logs/feed (suite, suivi en direct) : un seul
 * gabarit pour les trois.
 *
 * @var list<array<string,mixed>> $list
 * @var LogQuery                  $query
 * @var int                       $now
 * @var string                    $prevDay    jour déjà affiché juste avant ('' : aucun)
 * @var array<string,int>|null    $sigCounts  occurrences de chaque problème
 */
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tz = $query->tz;
$day = $prevDay;
foreach ($list as $e):
    $ts    = $e['ts'] !== null ? (int)$e['ts'] : null;
    $key   = $ts !== null ? LogView::dayKey($ts, $tz) : 'sans-date';
    if ($key !== $day):
        $day = $key; ?>
        <div class="lgx-day" data-day="<?= $h($key) ?>"><?= $ts !== null ? $h(LogView::dayLabel($ts, $tz, $now)) : te('Sans horodatage') ?></div>
    <?php endif;
    $level    = (string)$e['level'];
    $incident = $e['incident'];
    $frames   = $e['trace'] !== '' ? LogView::frames((string)$e['trace']) : [];
    $message  = (string)$e['message'] !== '' ? (string)$e['message'] : LogView::headline((string)$e['raw']);
    $occ      = $sigCounts[$e['sig']] ?? null;
    $around   = $ts !== null ? $query->url([
        'vue' => 'flux', 'periode' => 'perso',
        'du' => LogQuery::formatLocal($ts - 300, $tz), 'au' => LogQuery::formatLocal($ts + 300, $tz),
        'niveaux' => 'tous', 'domaine' => null, 'q' => null, 'sig' => null, 'incident' => null,
    ]) . '#t' . $ts : null;
    $sigUrl   = $query->url(['vue' => 'flux', 'sig' => $e['sig'], 'incident' => null]);
    $permalink = $incident !== null ? '/admin/logs?incident=' . $incident : $around;
?>
<details class="lgx-entry lv-<?= $h($level) ?>"<?= $query->incident !== null && $incident === $query->incident ? ' open' : '' ?><?= $ts !== null ? ' data-ts="' . $ts . '"' : '' ?> data-sig="<?= $h($e['sig']) ?>">
    <summary class="lgx-entry-row">
        <time class="lgx-time"<?= $ts !== null ? ' datetime="' . gmdate('c', $ts) . '" title="' . $h(LogView::dateTime($ts, $tz) . ' · ' . LogView::relative($ts, $now)) . '"' : '' ?>><?= $ts !== null ? $h(LogView::clock($ts, $tz)) : '—' ?></time>
        <span class="lgx-lv" title="<?= $h(LogCatalog::levelLabel($level, true)) ?>"><span class="lgx-dot" aria-hidden="true"></span><span class="lgx-lv-text"><?= $h(LogCatalog::levelLabel($level, true)) ?></span></span>
        <?php if ($e['tag'] !== null): ?><span class="lgx-tag"><?= $h($e['tag']) ?></span><?php endif; ?>
        <span class="lgx-msg"><?= $h(LogView::headline($message, 400)) ?></span>
        <span class="lgx-row-side">
            <?php if ($frames !== []): ?><span class="lgx-chip lgx-chip-trace" title="<?= te("Pile d'appels") ?>"><?= te('pile') ?></span><?php endif; ?>
            <?php if ($incident !== null): ?><span class="lgx-chip lgx-chip-incident" title="<?= te("Identifiant d'incident") ?>"><?= $h($incident) ?></span><?php endif; ?>
            <svg class="lgx-chevron" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
    </summary>
    <div class="lgx-detail">
        <dl class="lgx-facts">
            <div><dt><?= te('Quand') ?></dt><dd><?= $ts !== null ? $h(LogView::dateTime($ts, $tz)) . ' <span class="lgx-muted">' . $h(LogView::relative($ts, $now)) . '</span>' : te('Sans horodatage') ?></dd></div>
            <div><dt><?= te('Gravité') ?></dt><dd><span class="lgx-lv"><span class="lgx-dot" aria-hidden="true"></span><?= $h(LogCatalog::levelLabel($level, true)) ?></span></dd></div>
            <div><dt><?= te('Domaine') ?></dt><dd><?= $h(LogCatalog::sourceLabel((string)$e['source'])) ?></dd></div>
            <?php if ($e['location'] !== null): ?><div><dt><?= te('Emplacement') ?></dt><dd><code><?= $h($e['location']) ?></code></dd></div><?php endif; ?>
            <?php if ($incident !== null): ?><div><dt><?= te('Incident') ?></dt><dd><code><?= $h($incident) ?></code></dd></div><?php endif; ?>
            <div><dt><?= te('Problème') ?></dt><dd><a href="<?= $h($sigUrl) ?>"><?= $occ !== null ? htmlspecialchars(I18n::plural((int)$occ, '{n} occurrence sur la période', '{n} occurrences sur la période', ['n' => LogView::number((int)$occ)])) : te('Toutes les occurrences') ?></a></dd></div>
        </dl>

        <?php if (str_contains($message, "\n") || mb_strlen($message) > 400): ?>
            <pre class="lgx-pre lgx-message"><?= $h($message) ?></pre>
        <?php endif; ?>
        <?php if ((string)$e['extra'] !== ''): ?>
            <pre class="lgx-pre lgx-extra"><?= $h($e['extra']) ?></pre>
        <?php endif; ?>

        <?php if ($frames !== []): ?>
            <div class="lgx-trace">
                <div class="lgx-trace-title"><?= te("Pile d'appels") ?> <span class="lgx-muted">· <?= htmlspecialchars(I18n::plural(count($frames), '{n} cadre', '{n} cadres')) ?></span></div>
                <ol class="lgx-frames">
                    <?php foreach ($frames as $f): ?>
                        <li class="lgx-frame<?= $f['app'] ? ' is-app' : '' ?>">
                            <span class="lgx-frame-n"><?= $f['n'] !== '' ? '#' . $h($f['n']) : '' ?></span>
                            <span class="lgx-frame-call"><?= $h($f['call']) ?></span>
                            <?php if ($f['file'] !== ''): ?><span class="lgx-frame-file"><?= $f['file'] === 'interne' ? te('interne') : $h($f['file']) ?><?= $f['line'] !== '' ? ':' . $h($f['line']) : '' ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <?php foreach ($e['causes'] as $cause): ?>
            <div class="lgx-cause"><span class="lgx-cause-label"><?= te('Causée par') ?></span> <code><?= $h(preg_replace('/^causée par\s+/u', '', (string)$cause)) ?></code></div>
        <?php endforeach; ?>

        <div class="lgx-detail-actions">
            <button type="button" class="lgx-btn" data-copy="raw"><?= te("Copier l'entrée") ?></button>
            <?php if ($permalink !== null): ?><button type="button" class="lgx-btn" data-copy-link="<?= $h($permalink) ?>"><?= te('Copier le lien') ?></button><?php endif; ?>
            <?php if ($around !== null): ?><a class="lgx-btn" href="<?= $h($around) ?>"><?= te('Autour de cet instant') ?></a><?php endif; ?>
            <a class="lgx-btn" href="<?= $h($sigUrl) ?>"><?= te('Même problème') ?></a>
            <?php if ($incident !== null): ?><a class="lgx-btn" href="<?= $h('/admin/logs?incident=' . $incident) ?>"><?= te("Tout l'incident") ?></a><?php endif; ?>
        </div>
        <details class="lgx-raw">
            <summary><?= te('Texte brut') ?></summary>
            <pre class="lgx-pre" data-raw><?= $h($e['raw']) ?></pre>
        </details>
    </div>
</details>
<?php endforeach;
