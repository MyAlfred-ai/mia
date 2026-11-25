<?php
/**
 * Contenu du volet « Tables » d'une conversation, en deux parties qu'on ne
 * confond pas (WorkTableView::panel) :
 *   - « Vos tables » : les tables PERMANENTES engagées (cochées, ou portées
 *     par l'assistant), chacune suivie de ses VUES — filtrées, triées par
 *     MIA, sans rien copier : toujours à jour ;
 *   - « Résultats de MIA » : les listes établies dans la conversation
 *     (temporaires), « tirées de… », signalées PÉRIMÉES quand leur table a
 *     changé — repliables.
 * Pour la table choisie : ses lignes (page par page, filtrables), ses
 * exports, et ce qui se tient d'ici (nom, suppression, « Garder »).
 *
 * Renvoyé tout fait par `GET /chat/{id}/tables` (et par les routes de
 * renommage, de suppression, de « Garder ») : work-tables.js le pose tel quel
 * et n'a aucun libellé. La zone `[data-tables-grid]` se remplace seule
 * pendant qu'on tape un filtre — le champ garde le curseur.
 *
 * @var array<string, mixed> $tablesPanel WorkTableView::panel()
 */
$tpSel = $tablesPanel['selected'];
$tpItem = static function (array $it): void { ?>
    <button type="button" class="wt-item wt-kind-<?= htmlspecialchars((string)$it['kind']) ?><?= $it['selected'] ? ' is-selected' : '' ?><?= !empty($it['nested']) ? ' is-nested' : '' ?><?= $it['stale'] !== '' ? ' is-stale' : '' ?>" data-table-select="<?= htmlspecialchars((string)$it['id']) ?>"<?= $it['selected'] ? ' aria-current="true"' : '' ?>>
        <span class="wt-item-ico" aria-hidden="true"><?php if (!empty($it['nested'])): ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54z"/></svg><?php else: ?><?= WorkTableView::icon(15) ?><?php endif; ?></span>
        <span class="wt-item-text">
            <span class="wt-item-name"><?= htmlspecialchars((string)$it['label']) ?><?php if ($it['kind'] === 'result'): ?> <span class="wt-badge wt-badge-temp"><?= te('Temporaire') ?></span><?php endif; ?><?php if ($it['stale'] !== ''): ?> <span class="wt-badge wt-badge-stale"><?= te('Périmé') ?></span><?php endif; ?></span>
            <span class="wt-item-meta"><?= htmlspecialchars((string)$it['meta']) ?><?= $it['origin'] !== '' ? ' · ' . htmlspecialchars((string)$it['origin']) : '' ?></span>
        </span>
    </button>
<?php };
?>
<?php if ((int)($tablesPanel['total'] ?? $tablesPanel['count']) === 0): ?>
<div class="wt-empty">
    <span class="wt-empty-ico" aria-hidden="true"><?= WorkTableView::icon(26) ?></span>
    <p class="wt-empty-title"><?= te('Aucune table dans cette conversation') ?></p>
    <p><?= te('Cochez une table de vos Bases de connaissances pour la consulter ici. MIA range aussi ses résultats ici quand vous lui demandez un travail exhaustif : tous les emails d’un client, chaque ligne d’un classeur, un recoupement entre deux sources.') ?></p>
</div>
<?php return; endif; ?>

<div class="wt-list" data-tables-list>
    <?php if ($tablesPanel['mine'] !== []): ?>
    <p class="wt-section-title"><?= te('Vos tables') ?></p>
    <?php foreach ($tablesPanel['mine'] as $tpIt) { $tpItem($tpIt); } ?>
    <?php endif; ?>
    <?php if ($tablesPanel['results'] !== []): ?>
    <details class="wt-results"<?= !empty($tablesPanel['results_open']) ? ' open' : '' ?>>
        <summary class="wt-section-title"><?= te('Résultats de MIA') ?> <span class="wt-section-count"><?= (int)count($tablesPanel['results']) ?></span></summary>
        <p class="wt-section-note"><?= te('Listes établies par MIA dans cette conversation : temporaires, elles disparaissent avec elle.') ?></p>
        <?php foreach ($tablesPanel['results'] as $tpIt) { $tpItem($tpIt); } ?>
    </details>
    <?php endif; ?>
</div>

<?php if ($tpSel !== null): $tpPage = $tpSel['page']; $tpPerm = !empty($tpSel['permanent']); $tpView = $tpSel['view']; $tpOwn = !empty($tpSel['own']); ?>
<section class="wt-detail wt-detail-<?= htmlspecialchars((string)$tpSel['kind']) ?>" data-table-id="<?= htmlspecialchars((string)$tpSel['id']) ?>">
    <header class="wt-head">
        <?php if ($tpView !== null && $tpView['source'] !== ''): ?>
        <p class="wt-crumb">
            <?php if ($tpView['source_ref'] !== ''): ?>
            <button type="button" class="wt-link" data-table-select="<?= htmlspecialchars((string)$tpView['source_ref']) ?>"><?= htmlspecialchars((string)$tpView['source']) ?></button>
            <?php else: ?><?= htmlspecialchars((string)$tpView['source']) ?><?php endif; ?>
            <span aria-hidden="true">›</span>
        </p>
        <?php endif; ?>
        <div class="wt-title-row">
            <h3 class="wt-title" data-table-title><?= htmlspecialchars($tpSel['label']) ?></h3>
            <?php if ($tpPerm): ?>
            <span class="wt-badge"><?= te('Votre table') ?></span>
            <?php elseif ($tpSel['kind'] === 'result'): ?>
            <span class="wt-badge wt-badge-temp"><?= te('Temporaire') ?></span>
            <?php endif; ?>
            <?php if ($tpOwn): ?>
            <button type="button" class="wt-ico-btn" data-table-rename title="<?= te('Renommer') ?>" aria-label="<?= te('Renommer') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
            </button>
            <?php endif; ?>
        </div>
        <?php if ($tpOwn): ?>
        <form class="wt-rename" data-table-rename-form hidden>
            <input type="text" name="title" maxlength="<?= (int)WorkTableOps::TITLE_MAX ?>" value="<?= htmlspecialchars($tpSel['label']) ?>" aria-label="<?= te('Nom') ?>" autocomplete="off">
            <button type="submit" class="wt-btn wt-btn-primary"><?= te('Enregistrer') ?></button>
            <button type="button" class="wt-btn" data-table-rename-cancel><?= te('Annuler') ?></button>
        </form>
        <?php endif; ?>
        <p class="wt-meta"><?= htmlspecialchars($tpSel['meta']) ?><?= $tpSel['origin'] !== '' ? ' · ' . htmlspecialchars($tpSel['origin']) : '' ?></p>

        <?php if ($tpView !== null): ?>
        <?php /* Bandeau d'une vue : ce que MIA a filtré, sur combien, et le retour à la table entière. */ ?>
        <div class="wt-viewbar" role="note">
            <?php if ($tpView['error'] !== ''): ?>
            <span class="wt-viewbar-text"><?= htmlspecialchars((string)$tpView['error']) ?></span>
            <?php else: ?>
            <span class="wt-viewbar-text"><strong><?= te('Filtrée par MIA') ?></strong><?= $tpView['what'] !== [] ? ' : ' . htmlspecialchars(implode(' · ', $tpView['what'])) : '' ?>
                — <?= te('{lignes} sur {total}', ['lignes' => WorkTableView::rowsLabel((int)$tpPage['total']), 'total' => Format::credits((int)$tpView['total'])]) ?>.
                <?= te('Rien n’est copié : la vue suit la table.') ?></span>
            <?php endif; ?>
            <?php if ($tpView['source_ref'] !== ''): ?>
            <button type="button" class="wt-btn wt-btn-small" data-table-select="<?= htmlspecialchars((string)$tpView['source_ref']) ?>"><?= te('Tout afficher') ?></button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($tpSel['stale'] !== ''): ?>
        <p class="wt-stale" role="note"><?= te('Périmé : « {table} » a changé depuis que MIA a établi ce résultat. Demandez-lui de le refaire sur la table du moment.', ['table' => (string)$tpSel['stale']]) ?></p>
        <?php endif; ?>
        <p class="wt-status" data-table-status role="status" hidden></p>

        <div class="wt-actions">
            <?php if (!$tpSel['empty']): ?>
            <a class="wt-btn" href="<?= htmlspecialchars($tpSel['xlsx']) ?>" data-filename="<?= htmlspecialchars(basename(strtok(rawurldecode($tpSel['xlsx']), '?'))) ?>" title="<?= te('Télécharger en classeur Excel') ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Excel
            </a>
            <a class="wt-btn" href="<?= htmlspecialchars($tpSel['csv']) ?>" data-filename="<?= htmlspecialchars(basename(strtok(rawurldecode($tpSel['csv']), '?'))) ?>" title="<?= te('Télécharger en fichier CSV') ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV
            </a>
            <?php endif; ?>
            <?php if ($tpPerm && $tpSel['kb_url'] !== ''): ?>
            <a class="wt-btn" href="<?= htmlspecialchars($tpSel['kb_url']) ?>" title="<?= te('Ouvrir la fiche de la table, dans Bases de connaissances') ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg><?= te('Ouvrir sa fiche') ?>
            </a>
            <?php endif; ?>
            <?php if ($tpOwn && !empty($tablesPanel['can_keep']) && !$tpSel['empty']): ?>
            <button type="button" class="wt-btn wt-btn-keep" data-table-keep title="<?= te('En faire une table permanente, rangée dans Bases de connaissances') ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg><?= te('Garder') ?>
            </button>
            <?php endif; ?>
            <?php if ($tpOwn): ?>
            <button type="button" class="wt-btn wt-btn-danger" data-table-delete<?= $tablesPanel['busy'] ? ' disabled' : '' ?>
                    data-confirm="<?= $tpSel['kind'] === 'view'
                        ? te('Supprimer la vue « {nom} » ? La table « {table} » ne change pas.', ['nom' => $tpSel['label'], 'table' => (string)($tpView['source'] ?? '')])
                        : te('Supprimer « {nom} » ({lignes}) ? Ce résultat sera effacé, sans retour possible.', ['nom' => $tpSel['label'], 'lignes' => WorkTableView::rowsLabel((int)$tpPage['total'])]) ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg><?= te('Supprimer') ?>
            </button>
            <?php endif; ?>
        </div>
        <?php if ($tpPerm): ?>
        <p class="wt-permanent-note"><?= te('Votre table, rangée dans Bases de connaissances : elle se lit ici et se tient dans sa fiche. MIA ne la modifie qu’après votre accord.') ?></p>
        <?php endif; ?>
        <?php if ($tpOwn && !empty($tablesPanel['can_keep']) && !$tpSel['empty']): ?>
        <?php /* Garder : une COPIE permanente (Bases de connaissances). Tirée d'une
                 table permanente, le formulaire le dit — et propose plutôt de
                 compléter cette table quand le résultat lui apporte des colonnes. */ ?>
        <form class="wt-keep" data-table-keep-form hidden>
            <?php $tpFrom = $tpSel['kind'] === 'view' ? (string)($tpView['source'] ?? '') : (string)$tpSel['derived']; ?>
            <?php if ($tpFrom !== ''): ?>
            <p class="wt-keep-warn"><?= te('Tirée de « {table} » : la garder crée une NOUVELLE table permanente, à côté. « {table} » ne change pas.', ['table' => $tpFrom]) ?></p>
            <?php if ($tpSel['update'] !== null): ?>
            <p class="wt-keep-alt">
                <button type="button" class="wt-btn wt-btn-primary" data-table-update-source><?= htmlspecialchars((string)$tpSel['update']['label']) ?></button>
                <span><?= te('à la place : la table est complétée, rien n’est créé.') ?></span>
            </p>
            <?php endif; ?>
            <?php else: ?>
            <p class="wt-keep-text"><?= te('Une copie permanente, rangée dans Bases de connaissances : elle ne disparaît plus avec la conversation, se partage et se coche dans d\'autres conversations.') ?></p>
            <?php endif; ?>
            <div class="wt-keep-row">
                <input type="text" name="name" maxlength="190" value="<?= htmlspecialchars($tpSel['label']) ?>" aria-label="<?= te('Nom de la table permanente') ?>" autocomplete="off">
                <button type="submit" class="wt-btn<?= $tpSel['update'] !== null ? '' : ' wt-btn-primary' ?>"><?= $tpFrom !== '' ? te('Créer une nouvelle table') : te('Garder') ?></button>
                <button type="button" class="wt-btn" data-table-keep-cancel><?= te('Annuler') ?></button>
            </div>
        </form>
        <?php endif; ?>
        <?php if ($tablesPanel['busy'] && $tpOwn): ?>
        <p class="wt-busy"><?= te('Une opération est en cours sur les tables de cette conversation : la suppression attend sa fin.') ?></p>
        <?php endif; ?>

        <?php if (!$tpSel['empty']): ?>
        <label class="wt-filter">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
            <input type="search" data-table-filter value="<?= htmlspecialchars((string)$tpPage['q']) ?>" placeholder="<?= te('Filtrer les lignes') ?>" aria-label="<?= te('Filtrer les lignes') ?>" autocomplete="off">
        </label>
        <?php endif; ?>
    </header>

    <div class="wt-grid-zone" data-tables-grid>
        <?php if ($tpSel['empty']): ?>
        <p class="wt-none"><?= $tpView !== null ? te('Aucune ligne de la table ne passe ces filtres.') : te('Cette table est vide.') ?></p>
        <?php elseif ($tpPage['rows'] === []): ?>
        <p class="wt-none"><?= te('Aucune ligne ne contient « {q} ».', ['q' => (string)$tpPage['q']]) ?></p>
        <?php else: ?>
        <div class="wt-grid-scroll" tabindex="0">
            <table class="wt-grid">
                <thead>
                    <tr>
                        <th class="wt-num" scope="col">#</th>
                        <?php foreach ($tpSel['columns'] as $tpCol): ?>
                        <th scope="col"<?= $tpCol['type'] === 'number' ? ' class="is-num"' : '' ?>><?= htmlspecialchars($tpCol['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tpPage['rows'] as $tpRow): ?>
                    <tr>
                        <td class="wt-num"><?= (int)$tpRow['n'] ?></td>
                        <?php foreach ($tpRow['cells'] as $tpCell): ?>
                        <td<?= $tpCell['type'] === 'number' ? ' class="is-num"' : '' ?><?= $tpCell['full'] !== '' ? ' title="' . htmlspecialchars($tpCell['full']) . '"' : '' ?>><?= htmlspecialchars($tpCell['text']) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="wt-pager">
            <span class="wt-pager-count"><?= $tpPage['q'] !== ''
                ? te('{from} – {to} sur {matched} trouvées ({total} en tout)', ['from' => $tpPage['from'], 'to' => $tpPage['to'], 'matched' => Format::credits((int)$tpPage['matched']), 'total' => Format::credits((int)$tpPage['total'])])
                : te('{from} – {to} sur {total}', ['from' => $tpPage['from'], 'to' => $tpPage['to'], 'total' => Format::credits((int)$tpPage['total'])]) ?></span>
            <?php if ($tpPage['pages'] > 1): ?>
            <span class="wt-pager-nav">
                <button type="button" class="wt-ico-btn" data-table-page="<?= (int)$tpPage['page'] - 1 ?>"<?= $tpPage['page'] <= 1 ? ' disabled' : '' ?> title="<?= te('Page précédente') ?>" aria-label="<?= te('Page précédente') ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <span class="wt-pager-page"><?= (int)$tpPage['page'] ?> / <?= (int)$tpPage['pages'] ?></span>
                <button type="button" class="wt-ico-btn" data-table-page="<?= (int)$tpPage['page'] + 1 ?>"<?= $tpPage['page'] >= $tpPage['pages'] ? ' disabled' : '' ?> title="<?= te('Page suivante') ?>" aria-label="<?= te('Page suivante') ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </span>
            <?php endif; ?>
        </footer>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<p class="wt-foot">
    <?php if ((int)$tablesPanel['count'] > 0): ?>
    <?= htmlspecialchars(I18n::plural((int)$tablesPanel['count'], '{n} résultat ou vue sur {max} au plus par conversation.', '{n} résultats ou vues sur {max} au plus par conversation.', ['max' => (int)$tablesPanel['max']])) ?>
    <?= te('Ils vivent avec la conversation : supprimés avec elle.') ?>
    <?php endif; ?>
    <?php if ((int)($tablesPanel['permanent_count'] ?? 0) > 0): ?>
    <?= te('Votre table reste dans Bases de connaissances, quoi qu’il arrive à la conversation.') ?>
    <?php endif; ?>
</p>
