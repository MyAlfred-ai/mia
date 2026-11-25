<?php
/**
 * Barre du haut des pages d'administration et de réglages — le « socle
 * commun » de toutes ces pages : où l'on est (fil d'Ariane), si ce qu'on règle
 * est enregistré, et la recherche ⌘K. Incluse en PREMIER enfant de
 * `.admin-content` (le menu latéral, rendu avant, ne peut pas l'y poser).
 *
 * Attend : $page, $navVariant ('admin' | 'settings') — posés pour admin-nav.php.
 * Chemin : AdminBreadcrumb (source unique), libellés déjà traduits.
 * État d'enregistrement : public/assets/js/admin-ui.js le montre si la page
 * porte un formulaire auto-enregistré, et le suit (mia:autosave-before,
 * mia:autosaved, mia:autosave-failed). Les textes viennent d'ici, traduits.
 */
$topbarTrail = AdminBreadcrumb::trail((string)($page ?? ''), (string)($navVariant ?? 'admin'));
?>
<div class="admin-topbar" id="adminTopbar">
    <nav class="admin-crumbs" aria-label="<?= te('Fil d’Ariane') ?>">
        <ol>
            <?php foreach ($topbarTrail as $crumb): ?>
                <li<?= $crumb['href'] === null ? ' aria-current="page"' : '' ?>>
                    <?php if ($crumb['href'] !== null): ?>
                        <a href="<?= htmlspecialchars($crumb['href']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
                    <?php else: ?>
                        <span><?= htmlspecialchars($crumb['label']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <div class="admin-topbar-tools">
        <span class="admin-save-state" id="adminSaveState" role="status" aria-live="polite" hidden
              data-idle="<?= te('Enregistrement automatique') ?>"
              data-saving="<?= te('Enregistrement…') ?>"
              data-saved="<?= te('Enregistré') ?>"
              data-error="<?= te('Non enregistré') ?>">
            <span class="admin-save-dot" aria-hidden="true"></span>
            <span class="admin-save-text"><?= te('Enregistrement automatique') ?></span>
        </span>
        <button type="button" class="admin-topbar-search" data-palette-open aria-haspopup="dialog" aria-expanded="false" title="<?= te('Rechercher ou aller à…') ?>">
            <?= AdminMenu::icon('search', 14) ?>
            <span class="admin-topbar-search-label"><?= te('Rechercher') ?></span>
            <kbd data-palette-kbd aria-hidden="true"></kbd>
        </button>
    </div>
</div>
<?php unset($topbarTrail); ?>
