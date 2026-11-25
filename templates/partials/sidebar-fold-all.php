<?php /* « Tout replier / Tout déplier » de l'en-tête du volet (page de discussion
   et volet des pages d'espace) : replie ou déplie d'un geste Espaces, Épinglées
   et Historique. Libellé et icône suivent l'état (sidebar-sections.js). */ ?>
<button type="button" class="sidebar-fold-all" data-sb-all
        data-label-fold="<?= te('Tout replier') ?>" data-label-unfold="<?= te('Tout déplier') ?>"
        title="<?= te('Tout replier') ?>" aria-label="<?= te('Tout replier') ?>">
    <svg class="sb-fold-close" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 4 5 5 5-5"/><path d="m7 20 5-5 5 5"/></svg>
    <svg class="sb-fold-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 9 5-5 5 5"/><path d="m7 15 5 5 5-5"/></svg>
</button>
