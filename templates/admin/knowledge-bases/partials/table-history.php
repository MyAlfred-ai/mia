<?php
/**
 * Volet « Historique » d'une table permanente — la liste de ses états.
 *
 * Attend `$historyView` (KbTableController::history) : `kb_id`, `groups`
 * (KbTableHistory::view : jour → états), `preview` (l'état ouvert : moment,
 * phrase de ce qui diffère, texte de la confirmation) et `can_write`.
 * Renvoyé tout rendu au script de la page (kb-history.js), qui n'a aucun
 * libellé : un état = un point, une heure, un nom, une phrase ; le détail
 * et « Revenir à cet état » seulement sur l'état ouvert.
 *
 * « Revenir à cet état » et « Annuler ce retour » sont de vrais formulaires :
 * ils marchent sans script (le script ne fait qu'ajouter la confirmation et
 * le fuseau du navigateur).
 */
$hv    = $historyView;
$hvKb  = (int)$hv['kb_id'];
$hvPv  = is_array($hv['preview'] ?? null) ? $hv['preview'] : null;
$hvUrl = '/admin/knowledge-bases/' . $hvKb . '/table/history/';
?>
<div class="kbh-list">
<?php foreach ($hv['groups'] as $hvGroup): ?>
    <p class="kbh-day"><?= htmlspecialchars((string)$hvGroup['day']) ?></p>
    <?php foreach ($hvGroup['items'] as $hvIt):
        $hvOpen = !empty($hvIt['open']); ?>
    <div class="kbh-ent k-<?= htmlspecialchars((string)$hvIt['kind']) ?><?= $hvOpen ? ' is-open' : '' ?><?= !empty($hvIt['is_now']) ? ' is-now' : '' ?><?= empty($hvIt['kept']) ? ' is-empty' : '' ?>">
        <button type="button" class="kbh-ent-b" data-kbh-version="<?= (int)$hvIt['version'] ?>" aria-expanded="<?= $hvOpen ? 'true' : 'false' ?>">
            <span class="kbh-pt" aria-hidden="true"></span>
            <span class="kbh-eb">
                <span class="kbh-et"><b><?= htmlspecialchars((string)$hvIt['time']) ?></b><?php if ((string)$hvIt['who'] !== ''): ?> · <?= htmlspecialchars((string)$hvIt['who']) ?><?php endif; ?><?php if (!empty($hvIt['is_now'])): ?><span class="kbh-now"><?= te('Maintenant') ?></span><?php endif; ?></span>
                <span class="kbh-ew"><?= htmlspecialchars((string)$hvIt['what']) ?></span>
            </span>
        </button>
        <?php if ($hvOpen || !empty($hvIt['can_undo'])): ?>
        <div class="kbh-more">
            <?php if ($hvOpen && (string)$hvIt['note'] !== ''): ?><p class="kbh-note"><?= htmlspecialchars((string)$hvIt['note']) ?></p><?php endif; ?>
            <?php if ($hvOpen && $hvPv !== null && (int)$hvPv['version'] === (int)$hvIt['version']): ?>
                <p class="kbh-sum"><?= htmlspecialchars((string)$hvPv['summary']) ?></p>
                <?php if (!empty($hvIt['can_restore'])): ?>
                <form method="post" action="<?= htmlspecialchars($hvUrl . 'restore') ?>" class="kbh-restore" data-kbh-restore-form="<?= (int)$hvIt['version'] ?>"
                      data-confirm-title="<?= te('Revenir à l’état du {moment} ?', ['moment' => (string)$hvPv['moment']]) ?>"
                      data-confirm-text="<?= htmlspecialchars((string)$hvPv['confirm']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                    <input type="hidden" name="version" value="<?= (int)$hvIt['version'] ?>">
                    <input type="hidden" name="tz" value="" data-kbh-tz>
                    <button type="submit" class="kbx-btn is-sm kbh-amber"><?= te('Revenir à cet état') ?></button>
                    <button type="button" class="kbx-btn is-sm kbh-look" data-kbh-look><?= te('Voir la table d’alors') ?></button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($hvIt['can_undo'])): ?>
            <form method="post" action="<?= htmlspecialchars($hvUrl . 'undo') ?>" class="kbh-undo">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                <button type="submit" class="kbh-link"><?= te('Annuler ce retour') ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endforeach; ?>
</div>
