<?php
/**
 * Mention « enregistrement automatique » — partial partagé par TOUS les écrans
 * de configuration, pour que la formulation et le picto soient les mêmes
 * partout.
 *
 * Elle remplace le bouton « Enregistrer » disparu : sans elle, un écran sans
 * bouton ni pastille au repos laisse croire qu'il manque une action à faire.
 *
 * Variable facultative : $autosaveHintText (formulation adaptée au contexte).
 */
$autosaveHintText = $autosaveHintText ?? t('Modifications enregistrées automatiquement.');
?>
<span class="autosave-hint">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10Z"/>
        <polyline points="9 14 11 16 15 12"/>
    </svg>
    <?= htmlspecialchars($autosaveHintText) ?>
    <span data-autosave-status class="autosave-inline"></span>
</span>
<?php unset($autosaveHintText); ?>
