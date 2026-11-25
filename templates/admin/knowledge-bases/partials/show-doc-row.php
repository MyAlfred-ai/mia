<?php
/**
 * Une ligne de la liste des documents, sur la page d'une base (show.php).
 * Toute la ligne ouvre la fiche du document (volet, kb-doc.js) ; l'adresse
 * #document-ID y mène aussi. Rendue au chargement ET renvoyée toute faite
 * après un geste (AdminController::kbDocumentDetail → row_html) ou par le
 * suivi de l'état (…/sync-status?rows=1) : la page ne connaît aucun libellé.
 *
 * Porte l'heure de la dernière indexation (« indexé le … », dans le fuseau du
 * navigateur : kb-doc.js réécrit la balise <time>).
 *
 * Attend : $row = ['doc' => ligne allForKb, 'state' => KbStatus::documentState,
 *                  'check' => bool], $rowExtra (bool, ligne repliée sous « Voir les N »).
 */
$rowDoc   = $row['doc'];
$rowState = $row['state'];
$rowName  = (string)($rowDoc['file_name'] ?? '');
$rowId    = (int)($rowDoc['id'] ?? 0);
$rowExcl  = $rowState['key'] === KbStatus::DOC_EXCLUDED;
$rowChunks = (int)($rowDoc['chunk_count'] ?? 0);
$rowFacts = [Format::sizeShort((int)($rowDoc['file_size'] ?? 0))];
if (!$rowExcl) {
    $rowFacts[] = I18n::plural($rowChunks, '{n} extrait', '{n} extraits');
}
if ((string)($rowDoc['source'] ?? 'upload') !== 'upload') {
    $rowFacts[] = KbDiagram::sourceLabel((string)$rowDoc['source']);
}
$rowFacts = array_values(array_filter($rowFacts, static fn(string $f): bool => trim($f) !== ''));
$rowFactsHtml = htmlspecialchars(implode(' · ', $rowFacts));
// Dernière indexation (heure du navigateur, cf. KbPages::timeTag). Pas sur un
// document sans extrait : « indexé » s'y lirait comme une réussite (sa fiche
// garde l'heure).
$rowIndexed = ($rowExcl || $rowChunks === 0) ? '' : KbPages::withTime(t('indexé le {date}'), isset($rowDoc['indexed_ts']) ? (int)$rowDoc['indexed_ts'] : null);
if ($rowIndexed !== '') {
    $rowFactsHtml .= ' · ' . $rowIndexed;
}
?>
<li class="kbx-doc" data-doc-id="<?= $rowId ?>" data-ok="<?= $rowState['key'] === KbStatus::DOC_INDEXED ? 1 : 0 ?>" data-check="<?= !empty($row['check']) ? 1 : 0 ?>" data-excluded="<?= $rowExcl ? 1 : 0 ?>"<?= !empty($rowExtra) ? ' data-extra hidden' : '' ?>>
    <a class="kbx-doc-open" href="#document-<?= $rowId ?>" data-doc-id="<?= $rowId ?>" aria-haspopup="dialog">
        <span class="kbx-ft" data-ext="<?= htmlspecialchars(KbPages::fileKind($rowName)) ?>"><?= htmlspecialchars(KbPages::fileBadge($rowName)) ?></span>
        <span class="kbx-doc-main">
            <b title="<?= htmlspecialchars($rowName) ?>"><?= htmlspecialchars($rowName) ?></b>
            <small><?= $rowFactsHtml ?></small>
        </span>
        <span class="kbx-dst" data-tone="<?= htmlspecialchars($rowState['tone']) ?>" data-key="<?= htmlspecialchars($rowState['key']) ?>" title="<?= htmlspecialchars($rowState['detail']) ?>">
            <?php if (!in_array($rowState['tone'], ['warn', 'error'], true)): ?><span class="kbx-dot" data-tone="<?= htmlspecialchars($rowExcl ? 'off' : $rowState['tone']) ?>"></span><?php endif; ?>
            <?= htmlspecialchars($rowState['label']) ?>
        </span>
        <?= KbPages::icon('chev', 'kbx-doc-go') ?>
    </a>
</li>
