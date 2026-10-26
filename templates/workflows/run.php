<?php
/**
 * Workflows → une exécution, en direct (/workflows/runs/{id}).
 * Variables fournies par WorkflowController::run :
 *   @var array  $run          l'exécution (WorkflowRun::hydrate) — sa définition est figée
 *   @var ?array $workflow     le workflow (null s'il a été supprimé depuis)
 *   @var array  $state        état (WorkflowController::runState)
 *   @var array  $catalog      catalogue de l'éditeur (icônes, teintes, assistants…)
 *   @var int    $focusAction  carte de validation visée par le lien d'une carte du chat
 *
 * En tête, ce qui vous attend (décision, cause d'un arrêt, livraison) ; au
 * centre le rail de la version EXÉCUTÉE, avec l'état de chaque brique. Un clic
 * sur une brique ouvre son détail dans un volet, « Dossier » les pièces à
 * ouvrir, corriger ou télécharger. workflow-run.js sonde l'état tant que
 * l'exécution n'est pas finie.
 */
$mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$wfCssVersion   = @filemtime(ROOT . '/public/assets/css/workflows.css') ?: 1;
$railJsVersion  = @filemtime(ROOT . '/public/assets/js/workflow-rail.js') ?: 1;
$runJsVersion   = @filemtime(ROOT . '/public/assets/js/workflow-run.js') ?: 1;
$previewJsVersion = @filemtime(ROOT . '/public/assets/js/html-preview.js') ?: 1;
// L'historique des exécutions, ouvert sur ce lancement : la liste (tous les
// workflows) pour « revenir », celle de ce workflow pour « Historique ».
$historyBack = '/workflows?vue=historique&run=' . (int)$run['id'];
$historyUrl  = $workflow !== null ? '/workflows?vue=historique&workflow=' . (int)$workflow['id'] . '&run=' . (int)$run['id'] : $historyBack;
$icons = $catalog['icons'];
$name = $workflow['name'] ?? t('Workflow supprimé');
$color = $workflow['color'] ?? Workflow::DEFAULT_COLOR;
[$statusText, $statusTone] = WorkflowCatalog::statusLabel((string)$run['status'], WorkflowRun::timerAt($run['id']) !== null);
$payload = [
    'run' => [
        'id' => $run['id'], 'name' => $name, 'color' => $color, 'version' => $run['version'],
        'workflow_id' => $workflow !== null ? $workflow['id'] : null,
        'started' => WorkflowRun::startedLabel($run), 'trigger' => WorkflowCatalog::triggerLabel((string)$run['trigger_kind']),
    ],
    'definition' => $run['definition'],
    'state' => $state,
    'catalog' => $catalog,
    'focus_action' => $focusAction,
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= htmlspecialchars($name) ?> — <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <link rel="stylesheet" href="/assets/css/workflows.css?v=<?= (int)$wfCssVersion ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Workflows'); $page = 'workflows'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<div class="admin-content wf-content">
    <a class="wf-ed-back" href="<?= htmlspecialchars($historyBack) ?>" style="margin-bottom:12px"><?= $icons['back'] ?><?= te('Historique des exécutions') ?></a>
    <div class="wf-run-head">
        <span class="wf-cl-ic" style="--hue:<?= htmlspecialchars($color) ?>"><?= Workflow::icon(19) ?></span>
        <div class="grow">
            <h1><?= htmlspecialchars($name) ?></h1>
            <div class="sub"><?= te('Lancé le {date}', ['date' => WorkflowRun::startedLabel($run)]) ?> · <?= htmlspecialchars(WorkflowCatalog::triggerLabel((string)$run['trigger_kind'])) ?> · <?= te('version {v}', ['v' => (int)$run['version']]) ?> · <span id="wfRunCredits"></span></div>
        </div>
        <span class="wf-pill <?= $statusTone ?>" id="wfRunStatus"><?= htmlspecialchars($statusText) ?></span>
        <?php if (!empty($state['conversation_url'])): ?>
            <a class="wf-btn sm" href="<?= htmlspecialchars((string)$state['conversation_url']) ?>" title="<?= te('Conversation') ?>"><?= $icons['chat'] ?><span class="lbl"><?= te('Conversation') ?></span></a>
        <?php endif; ?>
        <?php if ($workflow !== null): ?>
            <a class="wf-btn sm ghost" href="/workflows/<?= (int)$workflow['id'] ?>/edit" title="<?= te('Modifier le workflow') ?>"><?= $icons['pencil'] ?><span class="lbl"><?= te('Modifier le workflow') ?></span></a>
        <?php endif; ?>
        <?php if ($workflow !== null): ?>
            <?php /* Les autres lancements du même workflow, et le journal de celui-ci (onglet « Historique des exécutions »). */ ?>
            <a class="wf-btn sm" id="wfRunHistory" href="<?= htmlspecialchars($historyUrl) ?>" title="<?= te('Journal de ce lancement et historique du workflow') ?>"><?= $icons['history'] ?><span class="lbl"><?= te('Historique') ?></span></a>
        <?php endif; ?>
        <button type="button" class="wf-btn sm" id="wfRunDossier" aria-haspopup="dialog"><?= $icons['folder'] ?><?= te('Dossier') ?><span class="wf-count is-muted" id="wfRunDossierCount" hidden></span></button>
        <button type="button" class="wf-btn sm danger" id="wfRunCancel" title="<?= te('Arrêter') ?>"<?= $state['terminal'] ? ' hidden' : '' ?>><?= $icons['stop'] ?><span class="lbl"><?= te('Arrêter') ?></span></button>
    </div>

    <div class="wf-run">
        <div class="wf-run-banner" id="wfRunBanner" aria-live="polite"></div>
        <div class="wf-run-canvas" id="wfRunCanvas">
            <div id="wfRunRail"></div>
            <noscript><p class="wf-hint"><?= te('Le suivi en direct a besoin de JavaScript ; la conversation de l’exécution reste lisible.') ?></p></noscript>
        </div>
    </div>
    <div class="wf-scrim" id="wfScrim"></div>
    <aside class="wf-drawer wf-ins" id="wfRunDrawer" aria-label="<?= te('Détail') ?>" aria-hidden="true"></aside>
</div>

<script>
window.WF_RUN = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
// Les anciens liens #historique (la fenêtre d'avant) mènent à l'onglet de l'historique.
(function (m) { if (m) { location.replace(<?= json_encode($historyUrl) ?>); } })(/^#historique(?:-\d+)?$/.exec(location.hash));
</script>
<script src="/assets/js/marked.min.js"></script>
<script src="/assets/js/workflow-rail.js?v=<?= (int)$railJsVersion ?>" defer></script>
<script src="/assets/js/html-preview.js?v=<?= (int)$previewJsVersion ?>" defer></script>
<script src="/assets/js/workflow-run.js?v=<?= (int)$runJsVersion ?>" defer></script>
</body>
</html>
