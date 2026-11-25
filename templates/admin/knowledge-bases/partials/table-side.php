<?php
/**
 * Aperçu d'une table PERMANENTE dans la liste des bases — à la place du
 * schéma d'une base, à droite de la liste.
 *
 * Attend `$tableSide` (KbTableView::side, complété par le contrôleur :
 * `assistants`, `assistants_more`, `edit_url`). Rendu une fois par table,
 * masqué : le script de la liste montre celui de la carte choisie.
 */
$sd = $tableSide;
?>
<div class="kb-table-side" data-table-side="<?= (int)$sd['kb_id'] ?>" hidden>
    <div class="kb-table-side-head">
        <span class="kb-table-side-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 10h18M3 15h18M9.5 10v10"/></svg></span>
        <div>
            <b><?= htmlspecialchars((string)$sd['title']) ?></b>
            <small><?= $sd['origin'] !== '' ? htmlspecialchars((string)$sd['origin']) : te('Table') ?></small>
        </div>
    </div>

    <div class="kb-table-side-stats">
        <div><b><?= Format::credits((int)$sd['rows']) ?></b><span><?= htmlspecialchars(I18n::plural((int)$sd['rows'], 'ligne', 'lignes')) ?></span></div>
        <div><b><?= (int)$sd['columns'] ?></b><span><?= htmlspecialchars(I18n::plural((int)$sd['columns'], 'colonne', 'colonnes')) ?></span></div>
        <div><b><?= $sd['modified'] !== '' ? htmlspecialchars((string)$sd['modified']) : '—' ?></b><span><?= te('dernière modification') ?></span></div>
    </div>

    <?php if ($sd['lines'] === []): ?>
        <p class="kbt-none"><?= te('Cette table est vide.') ?></p>
    <?php else: ?>
        <div class="kbt-grid-scroll">
            <table class="kbt-grid">
                <thead><tr>
                    <?php foreach ($sd['head'] as $sdLabel): ?><th scope="col"><?= htmlspecialchars($sdLabel) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($sd['lines'] as $sdLine): ?>
                    <tr><?php foreach ($sdLine as $sdCell): ?><td><?= htmlspecialchars($sdCell) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($sd['more_rows'] !== '' || $sd['more_columns'] !== ''): ?>
        <p class="kb-table-side-more"><?= htmlspecialchars(implode(' · ', array_filter([(string)$sd['more_rows'], (string)$sd['more_columns']]))) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <div>
        <div class="kb-table-side-label"><?= te('Qui s\'en sert') ?></div>
        <div class="kb-table-side-tags">
            <?php if (($sd['assistants'] ?? []) === [] && ($sd['assistants_more'] ?? 0) === 0): ?>
                <span class="kb-table-side-tag"><?= te('Aucun assistant lié') ?></span>
            <?php endif; ?>
            <?php foreach (($sd['assistants'] ?? []) as $sdName): ?>
                <span class="kb-table-side-tag"><?= htmlspecialchars($sdName) ?></span>
            <?php endforeach; ?>
            <?php if (($sd['assistants_more'] ?? 0) > 0): ?>
                <span class="kb-table-side-tag"><?= htmlspecialchars(I18n::plural((int)$sd['assistants_more'], '+ {n} autre assistant', '+ {n} autres assistants')) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="kb-table-side-label"><?= te('Ce que MIA peut faire') ?></div>
        <div class="kb-table-side-tags">
            <span class="kb-table-side-tag is-ok"><?= te('Lire') ?></span>
            <span class="kb-table-side-tag"><?= $sd['mia_write'] ? te('Écrire : après accord') : te('Écrire : non') ?></span>
        </div>
    </div>

    <div class="kb-table-side-actions">
        <?php if (($sd['edit_url'] ?? '') !== ''): ?>
            <a class="btn-primary" href="<?= htmlspecialchars((string)$sd['edit_url']) ?>"><?= te('Ouvrir la fiche') ?></a>
        <?php endif; ?>
        <?php if ((int)$sd['rows'] > 0): ?>
            <a class="kbt-btn" href="<?= htmlspecialchars((string)$sd['xlsx']) ?>" download="<?= htmlspecialchars((string)$sd['xlsx_name']) ?>"><?= te('Excel') ?></a>
        <?php endif; ?>
    </div>
</div>
