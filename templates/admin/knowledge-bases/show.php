<?php
/**
 * Page de CONSULTATION d'une base de connaissances ou d'une table
 * permanente (/admin/knowledge-bases/{id}) — piste D.
 *
 * Ce qu'on veut savoir sans rien risquer de changer : son état, ce qu'elle
 * contient (documents — chacun s'ouvre dans sa fiche : étapes, pourquoi il
 * pose problème, nouvel essai ou exclusion, extraits —, ou lignes en lecture
 * seule), qui s'en sert, qui la voit, et « Que sait-elle ? » (les extraits qui répondent à une question,
 * sans appeler de modèle). « Modifier » mène à sa fiche ; « Interroger dans
 * le chat » ouvre une conversation où elle est déjà cochée. Le schéma de la
 * base (KbDiagram, dessiné par diagram.js + kb-diagram.js) ferme la page.
 *
 * Attend (AdminController::showKnowledgeBase) : $kb, $isLive, $isTable,
 * $isSystem, $isOwned, $canManage, $visibility, $docs, $linkedIds, $shares,
 * $kbStatus, $statusPanelHtml, $kbAdvice, $linkedProfiles, $hiddenProfiles,
 * $shareableUsers, $shareableGroups, $tableRecord, $tableView, $tableHead,
 * $tableUndo, $tableRestored, $chatUrl, $diagram, $success, $error, $currentUser.
 *
 * Table : « Historique » ouvre le volet de ses états (kb-history.js, partiels
 * table-history*.php) ; choisir un état montre la grille d'alors, bandeau
 * au-dessus ; `?historique=1` l'ouvre au chargement.
 */
$kbId      = (int)$kb['id'];
$kbName    = (string)$kb['name'];
$kbDesc    = trim((string)($kb['description'] ?? ''));
$editUrl   = '/admin/knowledge-bases/' . $kbId . '/edit';
$kbActive  = !empty($kb['is_active']);
$grants    = count($shares['users'] ?? []) + count($shares['groups'] ?? []);
$vis       = KbPages::visibility($visibility, $grants);
$uses      = (int)($kb['query_count'] ?? 0);
$lastAge   = $kb['last_used_age'] ?? null;
$lastUse   = $lastAge === null || $lastAge === '' ? t('jamais') : KbStatus::ago((int)$lastAge);
$stTone    = (string)($kbStatus['tone'] ?? 'ok');
$stTitle   = (string)($kbStatus['title'] ?? '');

// Documents : verdict de chacun (KbStatus::documentState, comme la fiche).
// Un clic ouvre la fiche du document (volet kb-doc.js) : étapes, pourquoi
// il pose problème, nouvel essai ou exclusion, et ses extraits.
$docRows   = [];
$docCounts = ['all' => 0, 'ok' => 0, 'check' => 0, 'excluded' => 0];
foreach ($docs as $doc) {
    $state = KbStatus::documentState($doc);
    $check = in_array($state['tone'], [KbStatus::TONE_WARN, KbStatus::TONE_ERROR], true);
    $docRows[] = ['doc' => $doc, 'state' => $state, 'check' => $check];
    $docCounts['all']++;
    if ($state['key'] === KbStatus::DOC_INDEXED) {
        $docCounts['ok']++;
    }
    if ($check) {
        $docCounts['check']++;
    }
    if ($state['key'] === KbStatus::DOC_EXCLUDED) {
        $docCounts['excluded']++;
    }
}
// Les documents à vérifier d'abord : ce sont eux qui demandent un geste ;
// les exclus en dernier.
$docRank = static fn(array $r): int => $r['check'] ? 0 : ($r['state']['key'] === KbStatus::DOC_EXCLUDED ? 2 : 1);
usort($docRows, static fn(array $a, array $b): int => $docRank($a) <=> $docRank($b));
$docsShown = 8;
$chunks    = (int)($kb['chunk_count'] ?? 0);
$probeOn   = !$isLive && !$isTable && $chunks > 0;

// Noms du partage (connus du seul propriétaire).
$userNames  = [];
foreach ($shareableUsers as $u) {
    $userNames[(int)$u['id']] = (string)$u['name'];
}
$groupNames = [];
foreach ($shareableGroups as $g) {
    $groupNames[(int)$g['id']] = (string)$g['name'];
}

$adviceCount = is_array($kbAdvice) ? count($kbAdvice) : 0;
$version = static fn(string $file): int => (int)(@filemtime(ROOT . $file) ?: 1);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= htmlspecialchars($kbName) ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= $version('/public/assets/css/main.css') ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
    <link rel="stylesheet" href="/assets/css/kb-pages.css?v=<?= $version('/public/assets/css/kb-pages.css') ?>">
    <?php if ($isTable): ?><link rel="stylesheet" href="/assets/css/kb-table.css?v=<?= $version('/public/assets/css/kb-table.css') ?>"><?php endif; ?>
</head>
<body class="admin-page">
<?php $page = 'knowledge-bases'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content kb-content kbx-page">
<div class="kbx kbx-show<?= $isTable ? ' is-table' : '' ?>" id="kbShow" data-kb-id="<?= $kbId ?>">

    <a class="kbx-back" href="/admin/knowledge-bases"><?= KbPages::icon('back', '') ?><?= te('Bases de connaissances') ?></a>

    <?php if ($success): ?><div class="alert alert-success" style="margin-top:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-top:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <header class="kbx-head">
        <?= KbPages::tile($kb, true) ?>
        <div class="kbx-head-main">
            <h1><?= htmlspecialchars($kbName) ?></h1>
            <?php if ($kbDesc !== ''): ?><p class="kbx-desc"><?= htmlspecialchars($kbDesc) ?></p><?php endif; ?>
            <div class="kbx-status">
                <span id="kbShowState"><span class="kbx-dot" data-tone="<?= htmlspecialchars($kbActive ? $stTone : 'off') ?>"></span><b><?= htmlspecialchars($kbActive ? $stTitle : t('Inactive')) ?></b></span>
                <span class="kbx-vischip" title="<?= htmlspecialchars($vis['hint']) ?>"><?= KbPages::visibilityIcon($visibility) ?><?= htmlspecialchars($vis['label']) ?></span>
                <?php if ($isTable): ?>
                    <span><?= htmlspecialchars(KbTableImport::originLabel((array)($tableRecord['origin'] ?? []))) ?></span>
                <?php elseif (!$isLive && (string)($kb['sync_provider'] ?? '') !== ''): ?>
                    <span><?= te('Synchronisée avec {source}', ['source' => KbDiagram::sourceLabel((string)$kb['sync_provider'])]) ?></span>
                <?php endif; ?>
                <span><?= te('Dernière utilisation : {quand}', ['quand' => $lastUse]) ?></span>
            </div>
            <?php if ($isTable && $tableHead !== null): ?>
            <p class="kbx-dates">
                <span><?= htmlspecialchars($tableHead['created']) ?></span>
                <?php if ($tableHead['modified'] !== ''): ?>
                <button type="button" class="kbx-dates-link" data-kbh-open aria-controls="kbHistory"><?= htmlspecialchars($tableHead['modified']) ?></button>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <div class="kbx-actions">
            <?php if ($isTable && $tableHead !== null): ?>
                <button type="button" class="kbx-btn" data-kbh-open aria-controls="kbHistory" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg><?= te('Historique') ?>
                </button>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <a class="kbx-btn" href="<?= htmlspecialchars($editUrl) ?>"><?= KbPages::icon('pencil', '') ?><?= te('Modifier') ?></a>
            <?php endif; ?>
            <?php if ($chatUrl !== ''): ?>
                <a class="kbx-btn is-primary" href="<?= htmlspecialchars($chatUrl) ?>"><?= KbPages::icon('chat', '') ?><?= te('Interroger dans le chat') ?></a>
            <?php endif; ?>
        </div>
    </header>

    <div class="kbx-stats">
        <?php if ($isTable):
            $rows    = (int)($tableRecord['rows'] ?? 0);
            $maxRows = KbTableStore::MAX_ROWS;
            $cols    = count((array)($tableRecord['columns'] ?? []));
            $pct     = $maxRows > 0 ? min(100, (int)round($rows * 100 / $maxRows)) : 0; ?>
            <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($rows)) ?></b><small><?= te('lignes sur {max}', ['max' => Format::number($maxRows)]) ?></small><div class="kbx-gauge"><i style="width:<?= $pct ?>%"></i></div></div>
            <div class="kbx-stat"><b><?= $cols ?></b><small><?= te('colonnes') ?></small></div>
            <div class="kbx-stat"><b><?= !empty($tableRecord['mia_write']) ? te('Après accord') : te('Lecture') ?></b><small><?= te('ce que MIA peut y faire') ?></small></div>
            <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($uses)) ?></b><small><?= te('utilisations') ?></small></div>
        <?php elseif ($isLive): ?>
            <div class="kbx-stat"><b><?= te('En direct') ?></b><small><?= te('interrogée à chaque question') ?></small></div>
            <div class="kbx-stat"><b><?= count($linkedIds) ?></b><small><?= te('assistants liés') ?></small></div>
            <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($uses)) ?></b><small><?= te('utilisations') ?></small></div>
            <div class="kbx-stat"><b><?= htmlspecialchars($lastUse) ?></b><small><?= te('dernière utilisation') ?></small></div>
        <?php else: ?>
            <div class="kbx-stat"><b><?= $docCounts['all'] ?></b><small><?= te('documents') ?></small></div>
            <div class="kbx-stat"><b><?= $docCounts['ok'] ?></b><small><?= te('interrogeables') ?></small></div>
            <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($chunks)) ?></b><small><?= te('extraits') ?></small></div>
            <div class="kbx-stat"><b><?= htmlspecialchars(Format::number($uses)) ?></b><small><?= te('utilisations') ?></small></div>
        <?php endif; ?>
    </div>

    <?php if ($probeOn): ?>
    <section class="kbx-probe" id="kbProbe" aria-labelledby="kbProbeTitle"
             data-url="/admin/knowledge-bases/<?= $kbId ?>/search"
             data-msg-searching="<?= te('Recherche…') ?>"
             data-msg-none="<?= te('Aucun extrait ne contient ces mots. Essayez d\'autres termes : MIA, elle, cherche aussi par le sens.') ?>"
             data-msg-short="<?= te('Tapez au moins un mot de trois lettres.') ?>"
             data-msg-error="<?= te('La recherche n\'a pas abouti. Réessayez.') ?>"
             data-label-extract="<?= te('extrait {n}') ?>">
        <h2 id="kbProbeTitle"><?= te('Que sait-elle ?') ?></h2>
        <p><?= te('Tapez une question ou des mots-clés : les extraits qui les contiennent s\'affichent. Rien n\'est envoyé à un modèle.') ?></p>
        <form class="kbx-probe-form" role="search" onsubmit="return false">
            <?= KbPages::icon('search', '') ?>
            <input type="search" id="kbProbeInput" autocomplete="off" placeholder="<?= te('Ex. : délai de paiement des fournisseurs') ?>" aria-label="<?= te('Rechercher dans la base') ?>">
        </form>
        <p class="kbx-probe-msg" id="kbProbeMsg" role="status" aria-live="polite" hidden></p>
        <ul class="kbx-hits" id="kbProbeHits"></ul>
    </section>
    <?php endif; ?>

    <div class="kbx-cols">
        <div class="kbx-main">
            <?php if ($isTable): ?>
                <div class="kbh-banner-zone" id="kbHistBanner" hidden></div>
                <section class="kbx-card kbx-tablecard" aria-labelledby="kbRowsTitle">
                    <div class="kbx-card-head" style="padding:0 0 12px">
                        <h2 id="kbRowsTitle"><?= te('Ses lignes') ?></h2>
                        <?php if ($canManage): ?><a class="kbx-btn is-sm" href="<?= htmlspecialchars($editUrl) ?>#lignes"><?= KbPages::icon('pencil', '') ?><?= te('Corriger les lignes') ?></a><?php endif; ?>
                    </div>
                    <?php if ($tableView !== null): ?>
                        <div id="kbTableSection" class="kbt" data-kb-id="<?= $kbId ?>" data-readonly="1">
                            <?= KbTableView::sectionHtml($tableView) ?>
                        </div>
                    <?php else: ?>
                        <p class="kbx-muted"><?= te('Table vide') ?></p>
                    <?php endif; ?>
                </section>
            <?php elseif ($isLive): ?>
                <section class="kbx-card kbx-box">
                    <h3><?= te('Cerveau MyBrain') ?></h3>
                    <p class="kbx-muted"><?= te('Rien n\'est copié dans MIA : le cerveau est interrogé en direct à chaque question, avec ses propres contrôles. Ses fiches se consultent dans MyBrain.') ?></p>
                </section>
            <?php else: ?>
                <section class="kbx-card" id="kbShowDocs" aria-labelledby="kbDocsTitle">
                    <div class="kbx-card-head">
                        <h2 id="kbDocsTitle"><?= te('Documents') ?> <span>· <?= $docCounts['all'] ?></span></h2>
                        <?php if ($docCounts['all'] > 0): ?>
                        <div class="kbx-tabs" role="tablist" aria-label="<?= te('Filtrer les documents') ?>">
                            <button type="button" class="kbx-tab is-on" role="tab" aria-selected="true" data-doc-tab="all"><?= te('Tous') ?> · <span data-doc-count="all"><?= $docCounts['all'] ?></span></button>
                            <button type="button" class="kbx-tab" role="tab" aria-selected="false" data-doc-tab="ok"><?= te('Indexés') ?> · <span data-doc-count="ok"><?= $docCounts['ok'] ?></span></button>
                            <button type="button" class="kbx-tab" role="tab" aria-selected="false" data-doc-tab="check"<?= $docCounts['check'] > 0 ? '' : ' hidden' ?>><?= te('À vérifier') ?> · <span data-doc-count="check"><?= $docCounts['check'] ?></span></button>
                            <button type="button" class="kbx-tab" role="tab" aria-selected="false" data-doc-tab="excluded"<?= $docCounts['excluded'] > 0 ? '' : ' hidden' ?>><?= te('Exclus') ?> · <span data-doc-count="excluded"><?= $docCounts['excluded'] ?></span></button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($docRows === []): ?>
                        <p class="kbx-muted" style="padding:0 18px 18px"><?= te('Aucun document pour l\'instant.') ?>
                            <?php if ($canManage): ?> <a href="<?= htmlspecialchars($editUrl) ?>#documents"><?= te('Ajouter des documents') ?></a><?php endif; ?></p>
                    <?php else: ?>
                        <p class="kbx-docs-hint"><?= te('Touchez un document pour voir son détail, ses extraits et, s\'il pose problème, pourquoi.') ?></p>
                        <ul class="kbx-docs" id="kbShowDocList">
                            <?php foreach ($docRows as $i => $row):
                                $rowExtra = $i >= $docsShown;
                                require ROOT . '/templates/admin/knowledge-bases/partials/show-doc-row.php';
                            endforeach; ?>
                        </ul>
                        <?php if (count($docRows) > $docsShown): ?>
                            <button type="button" class="kbx-more" id="kbShowDocMore"><?= te('Voir les {n} documents', ['n' => count($docRows)]) ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="kbx-side">
            <?php if (!$isTable && $statusPanelHtml !== ''): ?>
                <section class="kbx-card kbx-box">
                    <h3><?= te('Source et état') ?></h3>
                    <div id="kbStatusPanel" data-can-manage="<?= $canManage ? 1 : 0 ?>"><?= $statusPanelHtml ?></div>
                </section>
            <?php endif; ?>

            <section class="kbx-card kbx-box">
                <h3><?= te('Qui s\'en sert') ?></h3>
                <div class="kbx-people">
                    <?php foreach ($linkedProfiles as $p):
                        $entry = KbDiagram::assistantEntry($p); ?>
                        <div class="kbx-person">
                            <span class="kbx-av" style="<?= $entry['color'] !== '' ? 'background:' . htmlspecialchars($entry['color']) : '' ?>">
                                <?php if ($entry['avatar'] !== ''): ?><img src="<?= htmlspecialchars($entry['avatar']) ?>" alt=""><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr($entry['name'], 0, 1))) ?><?php endif; ?>
                            </span>
                            <div><?= htmlspecialchars($entry['name']) ?><?php if (!$entry['active']): ?><small><?= te('Inactif') ?></small><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($hiddenProfiles > 0): ?>
                        <p class="kbx-muted"><?= htmlspecialchars(I18n::plural($hiddenProfiles, '+ {n} assistant hors de votre périmètre', '+ {n} assistants hors de votre périmètre')) ?></p>
                    <?php endif; ?>
                    <?php if ($linkedIds === []): ?>
                        <p class="kbx-muted"><?= te('Aucun assistant lié. On la coche aussi, au besoin, dans le chat.') ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="kbx-card kbx-box">
                <h3><?= te('Qui la voit') ?></h3>
                <div class="kbx-person">
                    <span class="kbx-grp"><?= KbPages::visibilityIcon($visibility) ?></span>
                    <div><?= htmlspecialchars($vis['label']) ?><small><?= htmlspecialchars($vis['hint']) ?></small></div>
                </div>
                <?php if ($visibility === KnowledgeBase::VIS_COMMON && $isOwned && $grants > 0): ?>
                    <div class="kbx-people" style="margin-top:12px">
                        <?php foreach (array_keys($shares['groups'] ?? []) as $gid): ?>
                            <div class="kbx-person"><span class="kbx-grp"><?= KbPages::icon('group', '') ?></span><div><?= htmlspecialchars($groupNames[(int)$gid] ?? t('Groupe supprimé')) ?><?php if (!empty($shares['groups'][$gid])): ?><small><?= te('peut modifier') ?></small><?php endif; ?></div></div>
                        <?php endforeach; ?>
                        <?php foreach (array_keys($shares['users'] ?? []) as $uid): ?>
                            <div class="kbx-person"><span class="kbx-av"><?= htmlspecialchars(mb_strtoupper(mb_substr($userNames[(int)$uid] ?? '?', 0, 1))) ?></span><div><?= htmlspecialchars($userNames[(int)$uid] ?? t('Compte supprimé')) ?><?php if (!empty($shares['users'][$uid])): ?><small><?= te('peut modifier') ?></small><?php endif; ?></div></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($adviceCount > 0 && $canManage): ?>
                <p class="kbx-foot kbx-advice"><?= KbPages::icon('check') ?> <a href="<?= htmlspecialchars($editUrl) ?>#conseils"><?= htmlspecialchars(I18n::plural($adviceCount, '{n} point à améliorer', '{n} points à améliorer')) ?></a></p>
            <?php endif; ?>
        </aside>
    </div>

    <section class="kbx-ensemble" aria-labelledby="kbEnsembleTitle">
        <div class="kbx-ensemble-h">
            <div>
                <h2 id="kbEnsembleTitle"><?= te('Vue d\'ensemble') ?></h2>
                <p><?= $isTable
                    ? te('Ce qui la remplit, comment MIA la lit, qui s\'en sert et qui la voit.')
                    : te('Ce qui l\'alimente, comment elle est cherchée, qui s\'en sert et qui la voit.') ?></p>
            </div>
        </div>
        <div class="kbx-diagram sched-diagram" id="kbShowDiagram"></div>
    </section>

</div>
<?php if (!$isLive && !$isTable): ?>
    <?php require ROOT . '/templates/admin/knowledge-bases/partials/doc-drawer.php'; ?>
<?php endif; ?>
<?php if ($isTable && $tableHead !== null): ?>
    <?php /* Volet « Historique » : à droite, la grille reste visible (la page
             se resserre sur grand écran) ; plein écran au téléphone. Rempli par
             …/table/history (partiel table-history.php). */ ?>
    <aside class="kbh" id="kbHistory" aria-labelledby="kbHistTitle" hidden
           data-url="/admin/knowledge-bases/<?= $kbId ?>/table/history"
           data-error="<?= te('L’historique n’a pas pu être chargé. Réessayez.') ?>">
        <header class="kbh-head">
            <div>
                <h2 id="kbHistTitle"><?= te('Historique') ?></h2>
                <p><?= te('Gardé {n} jours. Revenir en arrière n’efface rien.', ['n' => KbTableHistory::KEEP_DAYS]) ?></p>
            </div>
            <button type="button" class="kbf-drawer-btn" data-kbh-close title="<?= te('Fermer l’historique') ?>" aria-label="<?= te('Fermer l’historique') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </header>
        <div class="kbh-body" id="kbHistBody" aria-live="polite"></div>
    </aside>
    <dialog class="kbh-dialog" id="kbHistConfirm" aria-labelledby="kbHistConfirmTitle">
        <h3 id="kbHistConfirmTitle"></h3>
        <p id="kbHistConfirmText"></p>
        <p class="kbh-safe"><?= te('L’état actuel reste dans l’historique : vous pourrez y revenir.') ?></p>
        <div class="kbh-dialog-row">
            <button type="button" class="kbx-btn is-sm" data-kbh-cancel><?= te('Annuler') ?></button>
            <button type="button" class="kbx-btn is-sm kbh-amber" data-kbh-confirm><?= te('Revenir à cet état') ?></button>
        </div>
    </dialog>
    <?php if ($tableRestored !== null && $tableUndo): ?>
    <div class="kbh-toast" id="kbHistToast" role="status">
        <span><?= htmlspecialchars($tableRestored) ?></span>
        <form method="post" action="/admin/knowledge-bases/<?= $kbId ?>/table/history/undo">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
            <button type="submit"><?= te('Annuler') ?></button>
        </form>
        <button type="button" class="kbh-toast-x" data-kbh-toast-close aria-label="<?= te('Fermer') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
window.KB_SHOW = <?= json_encode([
    'catalog'    => $diagram['catalog'],
    'spec'       => $diagram['spec'],
    'can_manage' => $canManage,
    'poll_busy'  => KbStatus::POLL_BUSY_MS,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= $version('/public/assets/js/diagram.js') ?>"></script>
<script src="/assets/js/kb-diagram.js?v=<?= $version('/public/assets/js/kb-diagram.js') ?>"></script>
<?php if ($isTable && $tableView !== null): ?><script src="/assets/js/kb-table.js?v=<?= $version('/public/assets/js/kb-table.js') ?>"></script><?php endif; ?>
<?php if ($isTable && $tableHead !== null): ?><script src="/assets/js/kb-history.js?v=<?= $version('/public/assets/js/kb-history.js') ?>"></script><?php endif; ?>
<?php if (!$isLive && !$isTable): ?><script src="/assets/js/kb-doc.js?v=<?= $version('/public/assets/js/kb-doc.js') ?>"></script><?php endif; ?>
<script src="/assets/js/kb-show.js?v=<?= $version('/public/assets/js/kb-show.js') ?>"></script>
</body>
</html>
