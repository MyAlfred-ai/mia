<?php
/**
 * Volet « fiche d'un document » — UN seul markup pour la page d'une base
 * (show.php) et sa fiche (edit.php), tenu par public/assets/js/kb-doc.js.
 *
 * À droite, par-dessus la page (plein écran au téléphone) : le nom et l'état
 * du document, sa fiche rendue par le serveur (…/documents/ID : étapes,
 * problème et gestes), puis ses extraits page par page (…/chunks?doc=ID),
 * avec une recherche. Précédent / suivant parcourent la liste affichée.
 * Hors de tout formulaire : un fieldset désactivé (lecture seule) n'y
 * désactive rien.
 */
?>
<div class="kbf-scrim" id="kbDocScrim" hidden></div>
<aside class="kbf-drawer kbd-drawer" id="kbDocDrawer" role="dialog" aria-modal="true" aria-labelledby="kbDocDrawerTitle" hidden>
    <header class="kbf-drawer-head">
        <div class="kbf-drawer-titles">
            <span class="kbf-drawer-kicker"><?= te('Document') ?></span>
            <h2 class="kbf-drawer-title" id="kbDocDrawerTitle"></h2>
            <p class="kbf-drawer-meta" id="kbDocDrawerMeta"></p>
        </div>
        <div class="kbf-drawer-tools">
            <button type="button" class="kbf-drawer-btn" id="kbDocDrawerPrev" title="<?= te('Document précédent') ?>" aria-label="<?= te('Document précédent') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <button type="button" class="kbf-drawer-btn" id="kbDocDrawerNext" title="<?= te('Document suivant') ?>" aria-label="<?= te('Document suivant') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </button>
            <button type="button" class="kbf-drawer-btn" id="kbDocDrawerClose" title="<?= te('Fermer') ?>" aria-label="<?= te('Fermer') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </header>
    <div class="kbf-drawer-body" id="kbDocDrawerBody">
        <div id="kbDocDetail" aria-live="polite"></div>
        <div class="kbd-chunks" id="kbDocChunks" hidden>
            <input type="search" id="kbDocChunkSearch" class="form-input kbd-chunk-search" autocomplete="off" data-autosave-skip
                   placeholder="<?= te('Chercher dans ce document…') ?>" aria-label="<?= te('Chercher dans ce document') ?>">
            <div class="kb-chunk-list" id="kbDocChunkList" aria-live="polite"></div>
        </div>
    </div>
    <footer class="kb-chunk-pager kbf-drawer-foot" id="kbDocChunkPager" hidden>
        <button type="button" class="btn-secondary" id="kbDocChunkPrev"><?= te('← Précédents') ?></button>
        <span class="kb-chunk-page" id="kbDocChunkPage"></span>
        <button type="button" class="btn-secondary" id="kbDocChunkNext"><?= te('Suivants →') ?></button>
    </footer>
</aside>
