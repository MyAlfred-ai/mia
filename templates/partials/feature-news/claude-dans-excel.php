<?php
/**
 * Schéma animé de la nouveauté « Claude dans Excel, Word et PowerPoint »
 * (fenêtre « Quoi de neuf »).
 *
 * Un classeur Excel, le volet Claude à droite : on pose une question sur le
 * tableau, Claude répond et ajoute un graphique, la bonne ligne s'allume ; en
 * bas du volet, « avec votre compte MIA ». Tout en HTML : mots traduits, nets
 * à toute taille, thème suivi. Tailles en unités du conteneur (.fn-shot).
 * Mouvement réduit : l'état final, immobile. Les « % » sont précédés d'une
 * espace insécable (U+00A0, invisible à l'éditeur).
 */
$fnxRows = [
    // [région, 2025, 2026, progression en %]
    [t('Nord'), '178 k', '186 k', 4],
    [t('Ouest'), '140 k', '152 k', 9],
    [t('Sud'), '172 k', '175 k', 2],
    [t('Île-de-France'), '231 k', '259 k', 12],
];
$fnxBest = 3; // la ligne dont parle la réponse
$fnxQuestion = t('Quelle région progresse le plus ?');
$fnxAnswer   = t('{region} : +{pct} %. J’ai ajouté un graphique.', ['region' => $fnxRows[$fnxBest][0], 'pct' => $fnxRows[$fnxBest][3]]);
?>
<div class="fns fns-xl" role="img" aria-label="<?= htmlspecialchars(t('Exemple : dans Excel, « {q} » — Claude répond « {a} »', ['q' => $fnxQuestion, 'a' => $fnxAnswer])) ?>">
    <div class="fns-xl-win">
        <div class="fns-xl-bar">
            <span class="fns-xl-lights" aria-hidden="true"><i></i><i></i><i></i></span>
            <span class="fns-xl-file"><?= te('Ventes 2026.xlsx') ?></span>
            <span class="fns-xl-apps" aria-hidden="true"><?= OfficeAddin::appIcons(null, 13) ?></span>
        </div>
        <div class="fns-xl-body">
            <div class="fns-xl-sheet">
                <table>
                    <thead><tr><th><?= te('Région') ?></th><th>2025</th><th>2026</th></tr></thead>
                    <tbody>
                        <?php foreach ($fnxRows as $fnxI => $fnxRow): ?>
                        <tr<?= $fnxI === $fnxBest ? ' class="is-best"' : '' ?>><td><?= htmlspecialchars($fnxRow[0]) ?></td><td><?= htmlspecialchars($fnxRow[1]) ?></td><td><?= htmlspecialchars($fnxRow[2]) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="fns-xl-chart" aria-hidden="true">
                    <?php foreach ($fnxRows as $fnxI => $fnxRow): ?>
                    <span class="fns-xl-col<?= $fnxI === $fnxBest ? ' is-best' : '' ?>" style="--h: <?= (int)round(20 + $fnxRow[3] * 6.5) ?>%; --i: <?= (int)$fnxI ?>">
                        <i></i><em><?= htmlspecialchars(t('+{pct} %', ['pct' => (int)$fnxRow[3]])) ?></em>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="fns-xl-claude">
                <div class="fns-xl-head">
                    <span class="fns-xl-logo" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l1.6 6.3 5.2-3.9-3.3 5.6 6.4.9-6.2 2 4.4 4.7-5.9-2.6.4 6.5L12 16.6 9.4 22l.4-6.5-5.9 2.6 4.4-4.7-6.2-2 6.4-.9-3.3-5.6 5.2 3.9z"/></svg></span>
                    Claude
                </div>
                <p class="fns-xl-q"><?= htmlspecialchars($fnxQuestion) ?></p>
                <div class="fns-xl-reply">
                    <p class="fns-xl-typing" aria-hidden="true"><i></i><i></i><i></i></p>
                    <p class="fns-xl-a"><?= htmlspecialchars($fnxAnswer) ?></p>
                </div>
                <span class="fns-xl-via">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10.5" width="16" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>
                    <?= te('Avec votre compte MIA') ?>
                </span>
            </div>
        </div>
    </div>
</div>
