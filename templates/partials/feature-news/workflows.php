<?php
/**
 * Schéma animé de la nouveauté « Les workflows » (fenêtre « Quoi de neuf »).
 *
 * Un exemple concret, de gauche à droite : un email arrive, MIA rédige, vous
 * validez, c'est envoyé. Un document parcourt le fil et allume chaque étape.
 * Tout est en HTML : les mots sont traduits, nets à toute taille, et suivent le
 * thème. Les tailles sont en unités du conteneur (.fn-shot) : le schéma se
 * redimensionne comme une image. Mouvement réduit : l'état final, immobile.
 */
// « Email reçu » dans le contexte « schema » : en anglais, un libellé plus court
// que celui des pièces de workflow (« Email received » passerait sur deux lignes).
$fnwSteps = [
    ['tone' => '#6366f1', 'label' => t('Email reçu', [], 'schema'), 'sub' => t('un appel d’offres'),
     'icon' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 7l8.5 6 8.5-6"/>'],
    ['tone' => '#8b5cf6', 'label' => t('MIA rédige'), 'sub' => t('la réponse'),
     'icon' => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>'],
    ['tone' => '#f59e0b', 'label' => t('Vous validez'), 'sub' => t('en un clic'),
     'icon' => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20c.6-3.4 3-5.5 6-5.5 1.6 0 3 .5 4 1.5"/><path d="M15 18l2 2 4-4.5"/>'],
    ['tone' => '#22c55e', 'label' => t('C’est envoyé'), 'sub' => t('au client'),
     'icon' => '<path d="M21 3L10 14"/><path d="M21 3l-6.5 18-4.5-7-7-4.5z"/>'],
];
$fnwSummary = t('Exemple : {etapes}.', ['etapes' => implode(', ', array_map(
    static fn(array $s): string => $s['label'] . ' ' . $s['sub'],
    $fnwSteps
))]);
?>
<div class="fns fns-wf" role="img" aria-label="<?= htmlspecialchars($fnwSummary) ?>">
    <span class="fns-tag">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.5 6H14a4 4 0 0 1 4 4v5.5"/></svg>
        <?= te('Exemple : répondre à un appel d’offres') ?>
    </span>
    <div class="fns-wf-track" aria-hidden="true">
        <i class="fns-wf-fill"></i>
        <span class="fns-wf-token">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>
        </span>
    </div>
    <ol class="fns-wf-steps">
        <?php foreach ($fnwSteps as $fnwI => $fnwStep): ?>
        <li class="fns-wf-step fns-wf-s<?= $fnwI + 1 ?>" style="--tone: <?= htmlspecialchars($fnwStep['tone']) ?>">
            <span class="fns-wf-dot">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $fnwStep['icon'] ?></svg>
                <?php if ($fnwI === 3): ?>
                <span class="fns-wf-ok" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
                <?php endif; ?>
            </span>
            <b><?= htmlspecialchars($fnwStep['label']) ?></b>
            <small><?= htmlspecialchars($fnwStep['sub']) ?></small>
        </li>
        <?php endforeach; ?>
    </ol>
    <span class="fns-foot">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 21v-5h5"/></svg>
        <?= te('Puis à chaque fois, de la même façon') ?>
    </span>
</div>
