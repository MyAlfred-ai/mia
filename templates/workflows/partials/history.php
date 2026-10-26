<?php
/**
 * Workflows → Historique des exécutions (/workflows?vue=historique) : le seul
 * endroit où l'on retrouve ce qui s'est passé. À gauche les lancements, tous
 * workflows confondus ou d'un seul, filtrables par état, rangés par jour ; à
 * droite le journal de celui qu'on a choisi (WorkflowJournal). Au-delà de
 * 1200 px les deux côte à côte (la liste reste en vue) ; en dessous, la liste
 * puis le journal (« Tous les lancements » pour revenir).
 *
 * Tout est rendu ici au premier affichage — l'adresse (`workflow`, `etat`,
 * `run`) suffit à rouvrir un journal ; workflow-history.js prend ensuite le
 * relais (filtres sans rechargement, choix, suite de la liste, direct).
 * Sans JavaScript, les filtres sont les boutons d'un formulaire GET et les
 * lignes des liens.
 *
 *   @var array $history WorkflowController::historyPage
 *   @var array $icons   WorkflowCatalog::icons
 */
$hq = $history['query'];
$hd = $history['data'];
$groupLabels = [
    'all'    => t('Tous'),
    'ok'     => t('Terminés'),
    'err'    => t('En échec'),
    'active' => t('En cours'),
    'off'    => t('Arrêtés'),
];
$wfColor = $history['workflow'] !== null ? (string)$history['workflow']['color'] : '';
?>
<?php if ($history['total'] === 0): ?>
<div class="wf-empty">
    <span class="wf-hist-empty-ic"><?= $icons['history'] ?></span>
    <h2><?= te('Aucune exécution pour l’instant') ?></h2>
    <p><?= te('Lancez un workflow publié : chaque lancement s’affichera ici, avec le détail de ce que chaque brique a fait, ce qu’elle a remis et ce qui a échoué.') ?></p>
    <a class="wf-btn" href="/workflows"><?= $icons['workflow'] ?><?= te('Mes workflows') ?></a>
</div>
<?php else: ?>
<div class="wf-hx<?= $history['explicit'] ? ' is-journal' : '' ?>" id="wfHistory"
     data-workflow="<?= (int)($hq['workflow'] ?? 0) ?>" data-etat="<?= htmlspecialchars($hq['group']) ?>"
     data-run="<?= (int)$history['selected'] ?>" data-live="<?= $history['live'] ? '1' : '0' ?>">
    <section class="wf-hx-side" aria-label="<?= te('Lancements') ?>">
        <form class="wf-hx-tools" id="wfHxFilters" method="get" action="/workflows">
            <input type="hidden" name="vue" value="historique">
            <label class="wf-hx-wf" style="<?= $wfColor !== '' ? '--hue:' . htmlspecialchars($wfColor) : '' ?>">
                <i class="wf-hx-wf-dot" aria-hidden="true"></i>
                <select class="wf-select" name="workflow" aria-label="<?= te('Workflow') ?>">
                    <option value=""><?= te('Tous les workflows') ?> (<?= (int)$history['total'] ?>)</option>
                    <?php foreach ($history['options'] as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" data-color="<?= htmlspecialchars($o['color']) ?>"<?= $o['id'] === $hq['workflow'] ? ' selected' : '' ?>><?= htmlspecialchars($o['name']) ?> (<?= (int)$o['count'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="wf-hist-filters" role="group" aria-label="<?= te('Filtrer les lancements') ?>">
                <?php foreach ($groupLabels as $g => $label):
                    $n = (int)($hd['groups'][$g] ?? 0); ?>
                <button type="submit" name="etat" value="<?= $g ?>" class="wf-hist-filter g-<?= $g ?>" data-filter="<?= $g ?>" aria-pressed="<?= $hq['group'] === $g ? 'true' : 'false' ?>"<?= $g !== 'all' && $n === 0 && $hq['group'] !== $g ? ' hidden' : '' ?>><?= htmlspecialchars($label) ?><span><?= $n ?></span></button>
                <?php endforeach; ?>
            </div>
        </form>
        <nav class="wf-hx-list" id="wfHistList" aria-label="<?= te('Lancements') ?>">
            <?php
            $items = $hd['items'];
            $groups = $hd['groups'];
            $scoped = $hd['scoped'];
            $query = $hq;
            $firstPage = true;
            $moreBefore = $hd['moreBefore'];
            require ROOT . '/templates/workflows/partials/history-runs.php';
            ?>
        </nav>
        <p class="wf-hist-fail" hidden><?= te('L’historique n’a pas pu être chargé.') ?> <button type="button" class="wf-btn sm" data-hist-retry><?= te('Réessayer') ?></button></p>
    </section>
    <article class="wf-hx-journal" id="wfHistJournal" aria-live="polite" tabindex="-1">
        <?php if ($history['journal'] !== null): ?>
            <?php
            $head = $history['journal']['head'];
            $entries = $history['journal']['entries'];
            $wfInfo = $history['journal']['wfInfo'];
            $relaunch = $history['journal']['relaunch'];
            $outputs = $history['journal']['outputs'];
            require ROOT . '/templates/workflows/partials/history-journal.php';
            ?>
        <?php else: ?>
            <div class="wf-hist-pick">
                <span class="wf-hist-empty-ic"><?= $icons['history'] ?></span>
                <p><?= te('Choisissez un lancement pour voir son déroulé.') ?></p>
            </div>
        <?php endif; ?>
    </article>
    <template id="wfHistPick">
        <div class="wf-hist-pick">
            <span class="wf-hist-empty-ic"><?= $icons['history'] ?></span>
            <p><?= te('Choisissez un lancement pour voir son déroulé.') ?></p>
        </div>
    </template>
</div>
<?php endif; ?>
