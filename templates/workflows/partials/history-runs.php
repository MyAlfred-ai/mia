<?php
/**
 * Historique des exécutions — la liste des lancements, rendue par le serveur
 * (WorkflowController::historyData) au premier affichage de l'onglet et par
 * GET /workflows/history ensuite : workflow-history.js la pose sans connaître
 * un libellé. Une page à la fois, rangée par jour ; les suivantes s'ajoutent
 * sous « Afficher les lancements plus anciens ».
 *
 * Tous workflows confondus, chaque ligne dit d'abord DE QUEL workflow il
 * s'agit (sa couleur, son nom) ; filtrée sur un seul, elle dit son numéro.
 * Dessous : de quoi il s'agissait (champs du départ, objet d'un email reçu),
 * comment il est parti, combien de temps il a pris, la version, la bande des
 * briques et, s'il y a lieu, la cause d'un échec ou la brique atteinte.
 *
 *   @var list<array> $items      WorkflowJournal::summary, du plus récent au plus ancien
 *   @var array       $groups     lancements par groupe d'état (all, ok, err, active, off)
 *   @var bool        $scoped     la liste est filtrée sur un seul workflow
 *   @var array       $query      ['workflow' => ?int, 'group' => string] (liens des lignes)
 *   @var array       $icons      WorkflowCatalog::icons
 *   @var bool        $firstPage  la première page (état vide)
 *   @var ?int        $moreBefore lancement à partir duquel charger la suite, ou null
 */
$pill = ['ok' => 'ok', 'run' => 'run', 'wait' => 'wait', 'warn' => 'wait', 'err' => 'err', 'off' => ''];
$byDay = [];
foreach ($items as $it) {
    $byDay[$it['day_key']]['label'] = $it['day'];
    $byDay[$it['day_key']]['runs'][] = $it;
}
// Sans JavaScript, une ligne rouvre l'onglet sur ce lancement (mêmes filtres).
$runHref = static function (int $runId) use ($query): string {
    return '/workflows?' . http_build_query(array_filter([
        'vue'      => 'historique',
        'workflow' => $query['workflow'] ?? null,
        'etat'     => ($query['group'] ?? 'all') !== 'all' ? $query['group'] : null,
        'run'      => $runId,
    ], static fn($v): bool => $v !== null && $v !== ''));
};
?>
<?php if ($firstPage && $items === []): ?>
<div class="wf-hist-none">
    <span class="wf-hist-empty-ic"><?= $icons['history'] ?></span>
    <?php if ($groups['all'] === 0 && $scoped): ?>
        <p><?= te('Ce workflow n’a pas encore été lancé : ses lancements apparaîtront ici, avec le détail de ce que chaque brique a fait.') ?></p>
    <?php else: ?>
        <p><?= te('Aucun lancement ne correspond à ces filtres.') ?></p>
    <?php endif; ?>
    <a class="wf-btn sm" href="/workflows?vue=historique" data-hist-reset><?= te('Tout afficher') ?></a>
</div>
<?php endif; ?>
<?php foreach ($byDay as $dayKey => $g): ?>
<section class="wf-hist-day" data-day="<?= htmlspecialchars((string)$dayKey) ?>">
    <h4 class="wf-hist-day-label"><?= htmlspecialchars((string)$g['label']) ?></h4>
    <ul class="wf-hist-runs">
        <?php foreach ($g['runs'] as $r): ?>
        <li class="wf-hist-item" data-group="<?= htmlspecialchars($r['group']) ?>">
            <a class="wf-hist-run tone-<?= htmlspecialchars($r['tone']) ?>" href="<?= htmlspecialchars($runHref((int)$r['id'])) ?>" data-run="<?= (int)$r['id'] ?>" style="--hue:<?= htmlspecialchars($r['workflow']['color']) ?>">
                <span class="wf-hist-run-time"><?= htmlspecialchars($r['time']) ?><?php if (!$scoped): ?><small><?= te('n° {n}', ['n' => (int)$r['n']]) ?></small><?php endif; ?></span>
                <span class="wf-hist-run-main">
                    <span class="wf-hist-run-title">
                        <?php if ($scoped): ?>
                            <b><?= te('Lancement n° {n}', ['n' => (int)$r['n']]) ?></b>
                        <?php else: ?>
                            <i class="wf-hist-wf-dot" aria-hidden="true"></i><b><?= htmlspecialchars($r['workflow']['name']) ?></b>
                        <?php endif; ?>
                        <span class="wf-pill <?= $pill[$r['tone']] ?? '' ?>"><?= htmlspecialchars($r['label']) ?></span>
                    </span>
                    <?php if ($r['context'] !== ''): ?>
                        <span class="wf-hist-run-context"><?= htmlspecialchars($r['context']) ?></span>
                    <?php endif; ?>
                    <span class="wf-hist-run-meta">
                        <span class="wf-hist-trigger"><?= $icons[$r['trigger_icon']] ?? '' ?><?= htmlspecialchars($r['trigger']) ?></span>
                        <?php if ($r['duration'] !== ''): ?><span><?= htmlspecialchars($r['duration']) ?></span><?php endif; ?>
                        <span<?= $r['old_version'] ? ' class="is-old" title="' . te('Une version plus récente est publiée depuis') . '"' : '' ?>><?= te('v{v}', ['v' => (int)$r['version']]) ?></span>
                    </span>
                    <span class="wf-hist-track" aria-hidden="true">
                        <?php foreach ($r['track'] as $seg): ?><i class="tone-<?= htmlspecialchars($seg['tone'] !== '' ? $seg['tone'] : 'none') ?>" title="<?= htmlspecialchars($seg['title']) ?>"></i><?php endforeach; ?>
                    </span>
                    <?php if ($r['error'] !== ''): ?>
                        <span class="wf-hist-run-note is-err"><?= $icons['alert'] ?><?= htmlspecialchars(mb_substr($r['error'], 0, 140)) ?></span>
                    <?php elseif ($r['where'] !== '' && $r['group'] === 'active'): ?>
                        <span class="wf-hist-run-note"><?= te('À « {brique} »', ['brique' => $r['where']]) ?></span>
                    <?php endif; ?>
                </span>
                <span class="wf-hist-run-chev" aria-hidden="true"><?= $icons['chev'] ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endforeach; ?>
<?php if ($moreBefore !== null): ?>
<button type="button" class="wf-hist-more" data-before="<?= (int)$moreBefore ?>"><?= te('Afficher les lancements plus anciens') ?></button>
<?php endif; ?>
