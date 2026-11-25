<?php
/**
 * Fiche d'UN document d'une base (contenu du volet kb-doc.js) : ce qu'il est,
 * où en est son traitement (trois étapes, heure de la dernière indexation),
 * ce qui ne va pas, pourquoi et quoi
 * faire (KbDocIssue), les gestes possibles — nouvel essai, exclure, rétablir,
 * supprimer — puis ses extraits, chargés à part par le volet.
 *
 * Rendue par AdminController::kbDocumentDetail. Tous les libellés sont ici :
 * le volet ne fait que poser ce HTML et poster les gestes (data-doc-act).
 *
 * Attend : $doc (allForKb + stalled), $state (KbStatus::documentState),
 * $issue (KbDocIssue::of ou null), $docKb, $canManage, $docSynced, $docBusy,
 * $docCanDelete.
 */
$dName    = (string)($doc['file_name'] ?? '');
$dId      = (int)($doc['id'] ?? 0);
$dKbId    = (int)($docKb['id'] ?? 0);
$dSource  = (string)($doc['source'] ?? 'upload');
$dExcl    = $state['key'] === KbStatus::DOC_EXCLUDED;
$dChunks  = (int)($doc['chunk_count'] ?? 0);
$dTokens  = (int)($doc['token_count'] ?? 0);
$dAdded   = Format::dateFr((string)($doc['created_at'] ?? ''));
$dFacts   = [KbPages::fileBadge($dName), Format::sizeShort((int)($doc['file_size'] ?? 0))];
$dFacts[] = $dSource === 'upload'
    ? t('Déposé le {date}', ['date' => $dAdded])
    : t('Repris de {source} le {date}', ['source' => KbDiagram::sourceLabel($dSource), 'date' => $dAdded]);
$dFacts   = array_values(array_filter($dFacts, static fn(string $f): bool => trim($f) !== ''));
$dSteps   = KbDocIssue::steps($doc);
$dTech    = $issue !== null ? $issue['technical'] : [];
$dIndexed = KbPages::withTime(t('Dernière indexation : {date}'), isset($doc['indexed_ts']) ? (int)$doc['indexed_ts'] : null);
$actUrl   = '/admin/knowledge-bases/' . $dKbId . '/documents/' . $dId;
$stepIcon = static fn(string $tone): string => match ($tone) {
    'ok'    => KbPages::icon('check', 'kbd-step-ic'),
    'error' => KbPages::icon('alert', 'kbd-step-ic'),
    'warn'  => KbPages::icon('alert', 'kbd-step-ic'),
    default => '<span class="kbd-step-dot" aria-hidden="true"></span>',
};
?>
<div class="kbd" data-doc-id="<?= $dId ?>" data-busy="<?= $docBusy ? 1 : 0 ?>" data-chunks="<?= $dChunks ?>"
     data-title="<?= htmlspecialchars($dName) ?>" data-tone="<?= htmlspecialchars($state['tone']) ?>" data-label="<?= htmlspecialchars($state['label']) ?>"
     data-msg-error="<?= te('Le geste n\'a pas abouti. Réessayez.') ?>"
     data-msg-load="<?= te('Lecture du document impossible. Réessayez.') ?>">

    <p class="kbd-facts"><?= htmlspecialchars(implode(' · ', $dFacts)) ?></p>

    <p class="kbd-msg" data-doc-msg role="status" aria-live="polite" hidden></p>

    <?php if ($dExcl): ?>
        <section class="kbd-card is-excluded" aria-labelledby="kbdExclTitle-<?= $dId ?>">
            <div class="kbd-card-head"><?= KbPages::icon('ban', 'kbd-card-ic') ?><h3 id="kbdExclTitle-<?= $dId ?>"><?= te('Exclu de la base') ?></h3></div>
            <p><?= te('Il n\'est plus cherché et ne compte plus parmi les problèmes de la base. Le fichier est gardé : on peut le rétablir.') ?></p>
            <dl class="kbd-dl">
                <?php if (!empty($doc['excluded_by_name'])): ?>
                    <div><dt><?= te('Par') ?></dt><dd><?= htmlspecialchars((string)$doc['excluded_by_name']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($doc['excluded_at'])): ?>
                    <div><dt><?= te('Le') ?></dt><dd><?= htmlspecialchars(Format::dateFr((string)$doc['excluded_at'])) ?></dd></div>
                <?php endif; ?>
                <?php if (trim((string)($doc['excluded_reason'] ?? '')) !== ''): ?>
                    <div><dt><?= te('Motif') ?></dt><dd><?= htmlspecialchars((string)$doc['excluded_reason']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ($docSynced): ?>
                <p class="kbd-note"><?= te('La synchronisation ne le reprend pas. S\'il est modifié dans le dossier en ligne, il est réessayé de lui-même.') ?></p>
            <?php endif; ?>
            <?php if ($issue !== null): ?>
                <p class="kbd-note"><?= htmlspecialchars($issue['why']) ?></p>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <div class="kbd-actions">
                    <button type="button" class="kbx-btn is-primary" data-doc-act="restore" data-url="<?= htmlspecialchars($actUrl . '/restore') ?>"><?= KbPages::icon('retry', '') ?><?= te('Rétablir') ?></button>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($issue !== null): ?>
        <section class="kbd-card is-<?= htmlspecialchars($issue['tone']) ?>" aria-labelledby="kbdIssueTitle-<?= $dId ?>">
            <div class="kbd-card-head"><?= KbPages::icon('alert', 'kbd-card-ic') ?><h3 id="kbdIssueTitle-<?= $dId ?>"><?= htmlspecialchars($issue['title']) ?></h3></div>
            <p><?= htmlspecialchars($issue['why']) ?></p>
            <p class="kbd-todo"><b><?= te('Que faire') ?></b> <?= htmlspecialchars($issue['todo']) ?></p>
            <?php if ($canManage): ?>
                <div class="kbd-actions">
                    <?php if ($issue['retry'] !== null): ?>
                        <button type="button" class="kbx-btn is-primary" data-doc-act="retry" data-url="<?= htmlspecialchars($actUrl . '/retry') ?>"><?= KbPages::icon('retry', '') ?><?= te('Réessayer') ?></button>
                    <?php endif; ?>
                    <?php if ($issue['exclude']): ?>
                        <button type="button" class="kbx-btn<?= $issue['retry'] === null ? ' is-primary' : '' ?>" data-doc-act="exclude" data-url="<?= htmlspecialchars($actUrl . '/exclude') ?>"
                                data-confirm="<?= te('Exclure « {nom} » de la base ? Il ne sera plus cherché ; vous pourrez le rétablir.', ['nom' => $dName]) ?>"><?= KbPages::icon('ban', '') ?><?= te('Exclure de la base') ?></button>
                    <?php endif; ?>
                </div>
                <?php if ($issue['retry'] === null): ?>
                    <p class="kbd-note"><?= te('Un nouvel essai ne changerait rien : c\'est le fichier qu\'il faut corriger.') ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p class="kbd-note"><?= te('Seul qui gère la base peut relancer ou exclure ce document.') ?></p>
            <?php endif; ?>
            <?php if ($canManage && $dTech !== []): ?>
                <details class="kbd-tech">
                    <summary><?= te('Détail technique') ?></summary>
                    <?php foreach ($dTech as $line): ?><code><?= htmlspecialchars($line) ?></code><?php endforeach; ?>
                </details>
            <?php endif; ?>
        </section>
    <?php elseif ($docBusy): ?>
        <p class="kbd-busy"><span class="kbd-spin" aria-hidden="true"></span><?= htmlspecialchars($state['detail']) ?></p>
    <?php else: ?>
        <p class="kbd-ok"><?= KbPages::icon('check', 'kbd-ok-ic') ?><?= htmlspecialchars($state['detail']) ?></p>
    <?php endif; ?>

    <?php if (!$dExcl): ?>
        <ol class="kbd-steps" aria-label="<?= te('Étapes du traitement') ?>">
            <?php foreach ($dSteps as $step): ?>
                <li class="kbd-step" data-tone="<?= htmlspecialchars($step['tone']) ?>">
                    <?= $stepIcon($step['tone']) ?>
                    <span class="kbd-step-label"><?= htmlspecialchars($step['label']) ?></span>
                    <span class="kbd-step-detail"><?= htmlspecialchars($step['detail']) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <p class="kbd-indexed"><?= $dIndexed !== '' ? $dIndexed : ($docBusy ? te('Pas encore indexé.') : te('Jamais indexé.')) ?></p>

    <?php if ($canManage && !$dExcl && $issue === null && $docSynced): ?>
        <div class="kbd-actions is-quiet">
            <button type="button" class="kbx-btn is-sm" data-doc-act="exclude" data-url="<?= htmlspecialchars($actUrl . '/exclude') ?>"
                    data-confirm="<?= te('Exclure « {nom} » de la base ? Il ne sera plus cherché ; vous pourrez le rétablir.', ['nom' => $dName]) ?>"><?= KbPages::icon('ban', '') ?><?= te('Exclure de la base') ?></button>
            <span class="kbd-note"><?= te('Repris d\'un dossier synchronisé : on l\'écarte ici, ou on le retire du dossier.') ?></span>
        </div>
    <?php endif; ?>

    <?php if ($docCanDelete): ?>
        <div class="kbd-danger">
            <button type="button" class="kbd-delete" data-doc-act="delete" data-url="<?= htmlspecialchars($actUrl . '/delete') ?>"
                    data-confirm="<?= te('Supprimer « {nom} » de la base ? Le fichier et ses extraits seront effacés.', ['nom' => $dName]) ?>"><?= KbPages::icon('trash', '') ?><?= te('Supprimer le document') ?></button>
        </div>
    <?php endif; ?>

    <h3 class="kbd-chunks-title"><?= te('Ses extraits') ?> <span>· <?= htmlspecialchars(Format::number($dChunks)) ?></span></h3>
    <?php if ($dChunks === 0): ?>
        <p class="kbd-note"><?= $dExcl
            ? te('Ses extraits ont été retirés à l\'exclusion.')
            : ($docBusy ? te('Ses extraits apparaîtront une fois le texte lu et découpé.') : te('Aucun extrait : rien de ce document ne ressort d\'une recherche.')) ?></p>
    <?php endif; ?>

</div>
