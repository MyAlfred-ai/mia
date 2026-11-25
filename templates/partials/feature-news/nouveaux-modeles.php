<?php
/**
 * Schéma animé de la nouveauté « Claude Fable 5.2, Gemini 4 Argon et GPT-6.1
 * Terra » (fenêtre « Quoi de neuf », pour qui ouvre Modèles IA).
 *
 * La page Modèles IA → Fournisseurs et modèles, réduite à l'essentiel : les
 * trois nouveaux modèles, chacun marqué « Nouveau », s'activent l'un après
 * l'autre ; puis « aussitôt proposés dans le chat ». Les noms de modèles sont
 * des noms de produit (jamais traduits) ; les pastilles de couleur portent
 * l'initiale de l'éditeur, pas son logo. Tout en HTML, tailles en unités du
 * conteneur (.fn-shot). Mouvement réduit : les trois activés, immobiles.
 */
$fnmModels = [
    // [éditeur, initiale, teinte, modèle, ce qui le distingue]
    ['Anthropic', 'A', '#d97757', 'Claude Fable 5.2', t('le plus capable')],
    ['Google',    'G', '#4285f4', 'Gemini 4 Argon',   t('le raisonnement')],
    ['OpenAI',    'O', '#10a37f', 'GPT-6.1 Terra',    t('rapide et équilibré')],
];
$fnmNames = implode(', ', array_map(static fn(array $m): string => $m[3], $fnmModels));
?>
<div class="fns fns-md" role="img" aria-label="<?= htmlspecialchars(t('Exemple : dans Modèles IA → Fournisseurs et modèles, vous activez {modeles} ; ils sont aussitôt proposés dans le chat.', ['modeles' => $fnmNames])) ?>">
    <span class="fns-tag">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
        <?= te('Exemple : proposer les nouveaux modèles') ?>
    </span>
    <div class="fns-md-win">
        <div class="fns-md-bar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 9h6v6H9z"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/></svg>
            <span><?= te('Modèles IA') ?></span>
            <span class="fns-md-sep" aria-hidden="true">›</span>
            <b><?= te('Fournisseurs et modèles') ?></b>
        </div>
        <ul class="fns-md-list">
            <?php foreach ($fnmModels as $fnmI => $fnmM): ?>
            <li class="fns-md-row fns-md-r<?= $fnmI + 1 ?>" style="--tone: <?= htmlspecialchars($fnmM[2]) ?>">
                <span class="fns-md-logo" aria-hidden="true"><?= htmlspecialchars($fnmM[1]) ?></span>
                <span class="fns-md-text">
                    <b><?= htmlspecialchars($fnmM[3]) ?></b>
                    <small><?= htmlspecialchars($fnmM[0]) ?> · <?= htmlspecialchars($fnmM[4]) ?></small>
                </span>
                <span class="fns-md-new"><?= te('Nouveau') ?></span>
                <span class="fns-md-sw" aria-hidden="true"><i></i></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <span class="fns-md-foot">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-5A8 8 0 1 1 21 12z"/><path d="M8.5 12.2l2.3 2.3 4.7-4.8"/></svg>
        <?= te('Aussitôt proposés dans le chat') ?>
    </span>
</div>
