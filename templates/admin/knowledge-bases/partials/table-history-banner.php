<?php
/**
 * Bandeau d'un état passé, au-dessus de la grille : une seule ligne — de
 * quel moment il s'agit, ce qui diffère de maintenant, « Revenir au présent »
 * et « Revenir à cet état » (ce dernier soumet le formulaire du volet, avec
 * sa confirmation). Attend `$historyView` (KbTableController::history), dont
 * `preview` est posé.
 */
$hbPv = (array)$historyView['preview'];
?>
<div class="kbh-banner" role="status">
    <svg class="kbh-banner-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>
    <span class="kbh-banner-text"><b><?= te('Table du {moment}', ['moment' => (string)$hbPv['moment']]) ?></b> — <?= htmlspecialchars((string)$hbPv['summary']) ?></span>
    <button type="button" class="kbh-link" data-kbh-present><?= te('Revenir au présent') ?></button>
    <?php if (!empty($historyView['can_write'])): ?>
    <button type="button" class="kbx-btn is-sm kbh-amber" data-kbh-restore="<?= (int)$hbPv['version'] ?>"><?= te('Revenir à cet état') ?></button>
    <?php endif; ?>
</div>
