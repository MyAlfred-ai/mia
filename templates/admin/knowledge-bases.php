<?php
/**
 * Espaces → Bases de connaissances (/admin/knowledge-bases) : la liste —
 * piste D, « recherche d'abord ».
 *
 * Une grande recherche (nom, description, créateur, noms des documents),
 * deux portes de création (« Nouvelle base », « Nouvelle table »), puis tout
 * en deux colonnes : Bases | Tables. Chaque ligne dit ce qu'est la base, qui
 * la voit (Privée, Commune, Publique — une icône, la phrase en infobulle) et
 * son état, rafraîchi sans recharger (GET /admin/knowledge-bases/status). Une
 * ligne mène à la page de CONSULTATION (knowledge-bases/show.php) ; modifier
 * se fait depuis elle.
 *
 * Filtrer et chercher se jouent sur les lignes déjà rendues (kb-list.js) :
 * un second rendu ferait dériver le périmètre d'accès.
 *
 * Attend (AdminController::knowledgeBases) : $kbs, $ownedByKb, $grantsByKb,
 * $docNamesByKb, $kbStatuses, $embeddingProviders, $qdrantUp, $autoSyncPaused,
 * $budgetResetLabel, $isAdminManager, $kbCanCreate, $canSeeVector,
 * $canSeeBudget, $kbRemaining, $kbMax, $tableRemaining, $tableMax,
 * $success, $error.
 */
$version = static fn(string $file): int => (int)(@filemtime(ROOT . $file) ?: 1);

$rows    = ['base' => [], 'table' => []];
$visCnt  = [KnowledgeBase::VIS_PRIVATE => 0, KnowledgeBase::VIS_COMMON => 0, KnowledgeBase::VIS_PUBLIC => 0];
foreach ($kbs as $kb) {
    $kbId  = (int)$kb['id'];
    $kind  = KnowledgeBase::isTable($kb) ? 'table' : 'base';
    $vKey  = KnowledgeBase::visibility($kb);
    $owned = !empty($ownedByKb[$kbId]);
    $vis   = KbPages::visibility($vKey, (int)($grantsByKb[$kbId] ?? 0));
    // Une base commune qu'on ne possède pas : on la voit parce qu'on nous la partage.
    if ($vKey === KnowledgeBase::VIS_COMMON && !$owned) {
        $vis['hint'] = t('Partagée avec vous.');
    }
    $st     = $kbStatuses[$kbId] ?? null;
    $active = !empty($kb['is_active']);
    $visCnt[$vKey]++;
    $rows[$kind][] = [
        'kb'     => $kb,
        'id'     => $kbId,
        'vis'    => $vKey,
        'visTxt' => $vis,
        'tone'   => $active ? (string)($st['tone'] ?? 'ok') : 'off',
        'title'  => $active ? (string)($st['title'] ?? '') : t('Inactive'),
        'active' => $active,
        'search' => implode(' ', [
            (string)$kb['name'],
            (string)($kb['description'] ?? ''),
            (string)($kb['creator_name'] ?? ''),
            (string)($docNamesByKb[$kbId] ?? ''),
            KnowledgeBase::isRemote($kb) ? 'MyBrain' : KbDiagram::sourceLabel((string)($kb['sync_provider'] ?? '')),
        ]),
    ];
}
$baseCount  = count($rows['base']);
$tableCount = count($rows['table']);

// Créer : une base de documents demande une clé d'embedding (un
// administrateur peut toujours brancher un cerveau MyBrain) ; chaque
// créateur a son plafond de bases et, à part, de tables.
$kbCreateOffered = !empty($kbCanCreate);
$canCreateBase   = $isAdminManager || (!empty($embeddingProviders) && $kbRemaining > 0);
$baseWhy         = empty($embeddingProviders) ? t('Une clé API d\'embedding est requise.') : t('Limite de {n} bases atteinte.', ['n' => $kbMax]);
$canCreateTable  = $isAdminManager || $tableRemaining > 0;
$tableWhy        = t('Limite de {n} tables atteinte.', ['n' => $tableMax]);

$visLabels = [
    KnowledgeBase::VIS_PRIVATE => t('Privées'),
    KnowledgeBase::VIS_COMMON  => t('Communes'),
    KnowledgeBase::VIS_PUBLIC  => t('Publiques'),
];
$sourceLogos = ['drive', 'onedrive', 'sharepoint'];

$renderRow = static function (array $r): string {
    $kb = $r['kb'];
    ob_start(); ?>
    <a class="kbx-ln<?= $r['active'] ? '' : ' is-off' ?>" href="/admin/knowledge-bases/<?= $r['id'] ?>"
       id="base-<?= $r['id'] ?>" data-kb-row="<?= $r['id'] ?>" data-vis="<?= htmlspecialchars($r['vis']) ?>"
       data-search="<?= htmlspecialchars(mb_strtolower($r['search'])) ?>">
        <?= KbPages::tile($kb) ?>
        <span>
            <b><?= htmlspecialchars((string)$kb['name']) ?><?= AdminAttention::itemDot('kb:' . $r['id'], false) ?></b>
            <small><?= htmlspecialchars(KbPages::listMeta($kb)) ?></small>
        </span>
        <span class="kbx-vis" title="<?= htmlspecialchars($r['visTxt']['label'] . ' — ' . $r['visTxt']['hint']) ?>" role="img" aria-label="<?= htmlspecialchars($r['visTxt']['label'] . ' — ' . $r['visTxt']['hint']) ?>"><?= KbPages::visibilityIcon($r['vis']) ?></span>
        <span class="kbx-st"><span class="kbx-dot" data-tone="<?= htmlspecialchars($r['tone']) ?>"></span><span data-kb-state><?= htmlspecialchars($r['title']) ?></span></span>
    </a>
    <?php return (string)ob_get_clean();
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Bases de connaissances') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= $version('/public/assets/css/main.css') ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
    <link rel="stylesheet" href="/assets/css/kb-pages.css?v=<?= $version('/public/assets/css/kb-pages.css') ?>">
</head>
<body class="admin-page">
<?php $page = 'knowledge-bases'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content kb-content kbx-page">
<div class="kbx kbx-list" id="kbList" data-poll-idle="<?= (int)KbStatus::POLL_IDLE_MS ?>" data-poll-busy="<?= (int)KbStatus::POLL_BUSY_MS ?>">

    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($qdrantUp)): ?>
        <div class="alert alert-error" style="margin-bottom:16px">
            ⚠ <?= te('Le moteur vectoriel (Qdrant) est indisponible : les recherches passent en mode dégradé (recherche texte) et les nouveaux documents ne seront vectorisés qu\'après son retour (« Réindexer », dans la section « Avancé » de chaque base).') ?>
            <?php if (!empty($canSeeVector)): ?>
                <a href="/admin/knowledge-bases/vector"><?= te('Voir le moteur vectoriel') ?></a>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($autoSyncPaused)): ?>
        <!-- Budget IA atteint (KbSync::maybeRunDue) : sans montant, les créateurs voient cette page. -->
        <div class="llm-setup-banner" role="status" style="margin-bottom:16px">
            <div class="llm-setup-banner-text">
                <div class="llm-setup-banner-title"><?= te('Synchronisations automatiques suspendues jusqu\'au {date}', ['date' => (string)$budgetResetLabel]) ?></div>
                <div class="llm-setup-banner-sub"><?= te('Le budget IA du mois est atteint. « Synchroniser maintenant » reste disponible, et son coût est compté.') ?></div>
            </div>
            <?php if (!empty($canSeeBudget)): ?>
                <div class="llm-setup-banner-actions"><a href="/admin/budget" class="btn-secondary"><?= te('Voir le budget') ?></a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="kbx-hero">
        <div class="kbx-kicker"><?= te('{bases} · {tables}', [
            'bases'  => I18n::plural($baseCount, '{n} base', '{n} bases'),
            'tables' => I18n::plural($tableCount, '{n} table', '{n} tables'),
        ]) ?></div>
        <h1><?= te('Que cherchez-vous ?') ?></h1>
        <label class="kbx-search">
            <?= KbPages::icon('search', '') ?>
            <input type="search" id="kbListSearch" autocomplete="off"
                   placeholder="<?= te('Une base, une table, un document, une personne…') ?>"
                   aria-label="<?= te('Rechercher une base ou une table') ?>">
            <kbd aria-hidden="true">/</kbd>
        </label>
        <div class="kbx-scope" role="group" aria-label="<?= te('Filtrer') ?>">
            <button type="button" class="kbx-sc is-on" data-scope="all" aria-pressed="true"><?= te('Tout') ?></button>
            <button type="button" class="kbx-sc" data-scope="base" aria-pressed="false"><?= KbPages::icon('book', '') ?><?= te('Bases') ?> <em><?= $baseCount ?></em></button>
            <button type="button" class="kbx-sc" data-scope="table" aria-pressed="false"><?= KbPages::icon('table', '') ?><?= te('Tables') ?> <em><?= $tableCount ?></em></button>
            <span class="kbx-sep" aria-hidden="true"></span>
            <?php foreach ($visLabels as $vKey => $vLabel): if ($visCnt[$vKey] === 0) continue; ?>
                <button type="button" class="kbx-sc" data-vis-filter="<?= htmlspecialchars($vKey) ?>" aria-pressed="false"><?= KbPages::visibilityIcon($vKey) ?><?= htmlspecialchars($vLabel) ?> <em><?= $visCnt[$vKey] ?></em></button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($kbCreateOffered): ?>
    <div class="kbx-create">
        <?php if ($canCreateBase): ?>
            <a class="kbx-mk" href="/admin/knowledge-bases/new">
        <?php else: ?>
            <span class="kbx-mk is-off" aria-disabled="true" title="<?= htmlspecialchars($baseWhy) ?>">
        <?php endif; ?>
                <span class="kbx-mk-ico"><?= KbPages::icon('book', '') ?></span>
                <span>
                    <b><?= te('Nouvelle base') ?></b>
                    <small><?= $canCreateBase ? te('Des documents que vos assistants consultent : déposés, ou tenus à jour depuis un dossier.') : htmlspecialchars($baseWhy) ?></small>
                    <span class="kbx-mk-row">
                        <?= KbPages::icon('upload', '') ?>
                        <?php foreach ($sourceLogos as $src): ?><img src="<?= htmlspecialchars(KbDiagram::SOURCES[$src]['logo']) ?>" alt="" title="<?= htmlspecialchars(KbDiagram::sourceLabel($src)) ?>"><?php endforeach; ?>
                    </span>
                </span>
                <span class="kbx-mk-go"><?= KbPages::icon('plus', '') ?></span>
        <?= $canCreateBase ? '</a>' : '</span>' ?>

        <?php if ($canCreateTable): ?>
            <a class="kbx-mk is-table" href="/admin/knowledge-bases/new?genre=table">
        <?php else: ?>
            <span class="kbx-mk is-table is-off" aria-disabled="true" title="<?= htmlspecialchars($tableWhy) ?>">
        <?php endif; ?>
                <span class="kbx-mk-ico"><?= KbPages::icon('table', '') ?></span>
                <span>
                    <b><?= te('Nouvelle table') ?></b>
                    <small><?= $canCreateTable ? te('Des lignes et des colonnes que MIA compte, filtre et croise, jusqu\'à 20 000 lignes.') : htmlspecialchars($tableWhy) ?></small>
                    <span class="kbx-mk-row"><span class="kbx-fmt">XLSX</span><span class="kbx-fmt">CSV</span><span class="kbx-fmt">ODS</span></span>
                </span>
                <span class="kbx-mk-go"><?= KbPages::icon('plus', '') ?></span>
        <?= $canCreateTable ? '</a>' : '</span>' ?>
    </div>
    <?php if (!$isAdminManager): ?>
        <p class="kbx-note"><?= te('Vous pouvez encore créer {bases} et {tables}.', [
            'bases'  => I18n::plural($kbRemaining, '{n} base', '{n} bases'),
            'tables' => I18n::plural($tableRemaining, '{n} table', '{n} tables'),
        ]) ?></p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($kbs === []): ?>
        <p class="kbx-nothing"><?= te('Aucune base pour l\'instant. Créez-en une, ou demandez qu\'on vous en partage une.') ?></p>
    <?php else: ?>
        <div class="kbx-groups" id="kbGroups">
            <section data-group="base" aria-labelledby="kbGroupBases">
                <div class="kbx-gtitle" id="kbGroupBases"><i></i><?= te('Bases') ?> · <span data-group-count><?= $baseCount ?></span></div>
                <?php foreach ($rows['base'] as $r) echo $renderRow($r); ?>
                <p class="kbx-empty" data-group-empty<?= $baseCount > 0 ? ' hidden' : '' ?>><?= te('Aucune base ici.') ?></p>
            </section>
            <section data-group="table" aria-labelledby="kbGroupTables">
                <div class="kbx-gtitle is-table" id="kbGroupTables"><i></i><?= te('Tables') ?> · <span data-group-count><?= $tableCount ?></span></div>
                <?php foreach ($rows['table'] as $r) echo $renderRow($r); ?>
                <p class="kbx-empty" data-group-empty<?= $tableCount > 0 ? ' hidden' : '' ?>><?= te('Aucune table ici.') ?></p>
            </section>
        </div>
        <p class="kbx-results" id="kbListNone" hidden><?= te('Rien ne correspond. Essayez un autre mot, ou retirez un filtre.') ?></p>
        <div class="kbx-legend" aria-label="<?= te('Qui voit une base') ?>">
            <span><?= KbPages::visibilityIcon(KnowledgeBase::VIS_PRIVATE) ?><?= te('Privée : vous seul') ?></span>
            <span><?= KbPages::visibilityIcon(KnowledgeBase::VIS_COMMON) ?><?= te('Commune : vous, les administrateurs et ceux à qui vous la partagez') ?></span>
            <span><?= KbPages::visibilityIcon(KnowledgeBase::VIS_PUBLIC) ?><?= te('Publique : toute l\'organisation') ?></span>
        </div>
    <?php endif; ?>

    <?php if ($isAdminManager): ?>
        <div class="kbx-after">
            <a href="/admin/knowledge-bases/sync"><?= te('Sources synchronisées') ?></a>
            <?php if (!empty($canSeeVector)): ?><a href="/admin/knowledge-bases/vector"><?= te('Moteur vectoriel') ?></a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</div>
<script src="/assets/js/kb-list.js?v=<?= $version('/public/assets/js/kb-list.js') ?>"></script>
</body>
</html>
