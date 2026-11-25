<?php
/**
 * Lignes d'une table PERMANENTE — question 2 de sa fiche.
 *
 * Attend `$tableSection` (KbTableView::section) : colonnes, page de la
 * grille, origine — ou, `preview` posé, un état passé de l'historique en
 * lecture seule (cellules qui diffèrent de maintenant en ambre, la valeur
 * actuelle au survol ; ni exports ni origine). Rendu au chargement ET renvoyé tout fait
 * après chaque geste (KbTableController) : kb-table.js le pose sans connaître
 * un seul libellé.
 *
 * Aucun `<form>` ici (la zone est DANS le formulaire de la fiche : deux
 * formulaires ne s'imbriquent pas) et aucun champ nommé : rien ne part avec
 * l'enregistrement automatique de la fiche.
 */
$ts      = $tableSection;
$tsPage  = $ts['page'];
$tsEdit  = !empty($ts['can_edit']);
$tsTypes = $ts['types'];
$tsPast  = (int)($ts['preview'] ?? 0) > 0;
?>
<div class="kbt-bar">
    <span class="kbt-meta" data-kbt-meta><?= htmlspecialchars((string)$ts['meta']) ?></span>
    <span class="kbt-bar-tools">
        <?php if ((int)$ts['rows'] > 0 && !$tsPast): ?>
        <a class="kbt-btn" href="<?= htmlspecialchars((string)$ts['xlsx']) ?>" data-kbt-download data-filename="<?= htmlspecialchars((string)$ts['xlsx_name']) ?>"><?= te('Excel') ?></a>
        <a class="kbt-btn" href="<?= htmlspecialchars((string)$ts['csv']) ?>" data-kbt-download data-filename="<?= htmlspecialchars((string)$ts['csv_name']) ?>"><?= te('CSV') ?></a>
        <?php endif; ?>
    </span>
</div>

<p class="kbt-message" data-kbt-message role="status" aria-live="polite" hidden></p>

<div class="kbt-block">
    <h5 class="kbf-block-title"><?= te('Colonnes') ?></h5>
    <div class="kbt-columns" role="list">
        <?php foreach ($ts['columns'] as $tsCol): ?>
            <?php if ($tsEdit): ?>
            <button type="button" class="kbt-col" role="listitem" data-kbt-col="<?= htmlspecialchars($tsCol['key']) ?>"
                    data-label="<?= htmlspecialchars($tsCol['label']) ?>" data-type="<?= htmlspecialchars($tsCol['type']) ?>"
                    title="<?= te('Renommer la colonne, changer son type ou la supprimer') ?>">
                <span class="kbt-col-name"><?= htmlspecialchars($tsCol['label']) ?></span>
                <em><?= htmlspecialchars($tsCol['type_label']) ?></em>
            </button>
            <?php else: ?>
            <span class="kbt-col is-static" role="listitem">
                <span class="kbt-col-name"><?= htmlspecialchars($tsCol['label']) ?></span>
                <em><?= htmlspecialchars($tsCol['type_label']) ?></em>
            </span>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($tsEdit && !$ts['cols_full']): ?>
        <button type="button" class="kbt-col is-add" data-kbt-col-add><?= te('+ Colonne') ?></button>
        <?php endif; ?>
    </div>
    <?php if ($tsEdit): ?>
    <?php /* Éditeur d'UNE colonne (ajout ou retouche) : montré par kb-table.js
             sous la liste. Entrée valide, Échap referme. */ ?>
    <div class="kbt-col-form" data-kbt-col-form hidden>
        <input type="text" class="form-input" data-kbt-col-label maxlength="<?= (int)WorkTableOps::LABEL_MAX ?>"
               placeholder="<?= te('Nom de la colonne') ?>" aria-label="<?= te('Nom de la colonne') ?>" autocomplete="off">
        <select class="form-input" data-kbt-col-type aria-label="<?= te('Type de la colonne') ?>">
            <?php foreach ($tsTypes as $tsType => $tsTypeLabel): ?>
            <option value="<?= htmlspecialchars($tsType) ?>"><?= htmlspecialchars($tsTypeLabel) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn-primary kbt-sm" data-kbt-col-save><?= te('Valider') ?></button>
        <button type="button" class="btn-secondary kbt-sm" data-kbt-col-cancel><?= te('Annuler') ?></button>
        <button type="button" class="kbt-link is-danger" data-kbt-col-delete hidden
                data-confirm="<?= te('Supprimer cette colonne et toutes ses valeurs ? Vous pourrez revenir en arrière depuis l\'historique de la table.') ?>"><?= te('Supprimer la colonne') ?></button>
    </div>
    <p class="profile-hint"><?= te('Le type sert à trier et à compter juste : un nombre s\'additionne, une date se compare.') ?></p>
    <?php endif; ?>
</div>

<div class="kbt-block">
    <div class="kbt-grid-head">
        <h5 class="kbf-block-title"><?= te('Lignes') ?></h5>
        <?php if ((int)$ts['rows'] > 0): ?>
        <label class="kbt-filter">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
            <input type="search" data-kbt-filter value="<?= htmlspecialchars((string)$tsPage['q']) ?>" placeholder="<?= te('Filtrer les lignes') ?>" aria-label="<?= te('Filtrer les lignes') ?>" autocomplete="off">
        </label>
        <?php endif; ?>
    </div>

    <div class="kbt-grid-zone" data-kbt-grid data-page="<?= (int)$tsPage['page'] ?>">
        <?php if ((int)$ts['rows'] === 0): ?>
        <p class="kbt-none"><?= $tsEdit ? te('Aucune ligne pour le moment. Ajoutez-en une, ou importez un fichier Excel ou CSV.') : te('Cette table est vide.') ?></p>
        <?php elseif ($tsPage['rows'] === []): ?>
        <p class="kbt-none"><?= te('Aucune ligne ne contient « {q} ».', ['q' => (string)$tsPage['q']]) ?></p>
        <?php else: ?>
        <div class="kbt-grid-scroll">
            <table class="kbt-grid<?= $tsEdit ? ' is-editable' : '' ?>">
                <thead>
                    <tr>
                        <th class="kbt-num" scope="col">#</th>
                        <?php foreach ($ts['columns'] as $tsCol): ?>
                        <th scope="col"<?= $tsCol['type'] === 'number' ? ' class="is-num"' : '' ?>><?= htmlspecialchars($tsCol['label']) ?></th>
                        <?php endforeach; ?>
                        <?php if ($tsEdit): ?><th class="kbt-tools" scope="col"><span class="sr-only"><?= te('Actions') ?></span></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tsPage['rows'] as $tsRow): ?>
                    <tr data-pos="<?= (int)$tsRow['n'] - 1 ?>"<?= !empty($tsRow['gone']) ? ' class="is-gone" title="' . te('Absente aujourd’hui : elle reviendrait avec cet état') . '"' : '' ?>>
                        <td class="kbt-num"><?= (int)$tsRow['n'] ?></td>
                        <?php foreach ($tsRow['cells'] as $tsCell):
                            $tsChanged = !empty($tsCell['changed']);
                            $tsTitle   = $tsChanged ? t('Maintenant : {valeur}', ['valeur' => (string)$tsCell['now']]) : (string)$tsCell['full']; ?>
                        <td class="kbt-cell<?= $tsCell['type'] === 'number' ? ' is-num' : '' ?><?= $tsChanged ? ' is-changed' : '' ?>"
                            <?php if ($tsEdit): ?>tabindex="0" data-key="<?= htmlspecialchars($tsCell['key']) ?>" data-raw="<?= htmlspecialchars($tsCell['raw']) ?>"<?php endif; ?>
                            <?= $tsTitle !== '' ? ' title="' . htmlspecialchars($tsTitle) . '"' : '' ?>><?= htmlspecialchars($tsCell['text']) ?></td>
                        <?php endforeach; ?>
                        <?php if ($tsEdit): ?>
                        <td class="kbt-tools">
                            <button type="button" class="kbt-ico" data-kbt-row-delete
                                    data-confirm="<?= te('Supprimer la ligne {n} ?', ['n' => (int)$tsRow['n']]) ?>"
                                    title="<?= te('Supprimer la ligne') ?>" aria-label="<?= te('Supprimer la ligne {n}', ['n' => (int)$tsRow['n']]) ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="kbt-pager">
            <?php if ($tsPast): ?>
            <span class="kbt-pager-hint"><i aria-hidden="true"></i><?= te('Différent de maintenant — survolez pour voir la valeur actuelle') ?></span>
            <?php endif; ?>
            <span class="kbt-pager-count"><?= $tsPage['q'] !== ''
                ? te('{from} – {to} sur {matched} trouvées ({total} en tout)', ['from' => $tsPage['from'], 'to' => $tsPage['to'], 'matched' => Format::credits((int)$tsPage['matched']), 'total' => Format::credits((int)$tsPage['total'])])
                : te('{from} – {to} sur {total}', ['from' => $tsPage['from'], 'to' => $tsPage['to'], 'total' => Format::credits((int)$tsPage['total'])]) ?></span>
            <?php if ($tsPage['pages'] > 1): ?>
            <span class="kbt-pager-nav">
                <button type="button" class="kbt-ico" data-kbt-page="<?= (int)$tsPage['page'] - 1 ?>"<?= $tsPage['page'] <= 1 ? ' disabled' : '' ?> title="<?= te('Page précédente') ?>" aria-label="<?= te('Page précédente') ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <span class="kbt-pager-page"><?= (int)$tsPage['page'] ?> / <?= (int)$tsPage['pages'] ?></span>
                <button type="button" class="kbt-ico" data-kbt-page="<?= (int)$tsPage['page'] + 1 ?>"<?= $tsPage['page'] >= $tsPage['pages'] ? ' disabled' : '' ?> title="<?= te('Page suivante') ?>" aria-label="<?= te('Page suivante') ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </span>
            <?php endif; ?>
        </footer>
        <?php endif; ?>
    </div>

    <?php if ($tsEdit): ?>
    <div class="kbt-row-actions">
        <button type="button" class="kbt-btn" data-kbt-row-add<?= $ts['full'] ? ' disabled' : '' ?>><?= te('+ Ligne') ?></button>
        <span class="profile-hint"><?= te('Cliquez une cellule pour la corriger : Entrée enregistre, Échap annule.') ?></span>
    </div>
    <?php endif; ?>
</div>

<?php if (!$tsPast): ?>
<div class="kbt-block">
    <h5 class="kbf-block-title"><?= te('D\'où viennent les lignes') ?></h5>
    <div class="kbt-origin">
        <span class="kbt-origin-text"><?= $ts['origin'] !== '' ? htmlspecialchars((string)$ts['origin']) : te('Origine non renseignée') ?></span>
        <?php if ($tsEdit): ?>
        <span class="kbt-origin-tools">
            <?php /* Sans `name` : le fichier part par kb-table.js, jamais avec la fiche. */ ?>
            <input type="file" data-kbt-file accept=".xlsx,.xlsm,.xls,.ods,.csv,.tsv" hidden>
            <button type="button" class="kbt-btn" data-kbt-import="replace"
                    data-confirm="<?= (int)$ts['rows'] > 0 ? te('Remplacer tout le contenu de la table par celui du fichier ? Vous pourrez revenir en arrière depuis l\'historique de la table.') : '' ?>"><?= te('Remplacer par un fichier') ?></button>
            <button type="button" class="kbt-btn" data-kbt-import="append"><?= te('Ajouter les lignes d\'un fichier') ?></button>
        </span>
        <?php endif; ?>
    </div>
    <p class="kbt-cap">
        <?= teb('{b}{lignes} lignes et {colonnes} colonnes au plus.{/b} Un fichier plus long est refusé, avec le nombre de lignes trouvé — jamais coupé sans le dire.', [
            'lignes' => Format::credits((int)$ts['max_rows']), 'colonnes' => (int)$ts['max_cols'],
        ]) ?>
    </p>
    <?php if ($tsEdit): ?>
    <p class="kbt-history-link">
        <?= te('Chaque changement s\'inscrit dans l\'historique de la table : on peut revenir à un état précédent.') ?>
        <a href="<?= htmlspecialchars((string)$ts['history']) ?>"><?= te('Voir l\'historique') ?></a>
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>
