<?php
/**
 * Workflows → l'éditeur (/workflows/{id}/edit).
 * Variables fournies par WorkflowController::edit :
 *   @var array      $workflow    le workflow (Workflow::hydrate)
 *   @var array      $editor      données de l'éditeur (workflow, definition, issues, estimate, catalog)
 *   @var int        $historyCount lancements de ce workflow (lien « Historique »)
 *
 * Le RAIL au centre (workflow-rail.js), une ligne par brique ; un clic ouvre
 * le VOLET de la brique (workflow-editor.js), « Réglages » celui du workflow.
 * Le brouillon s'enregistre au fil de l'édition, en JSON ; seule la version
 * publiée s'exécute.
 */
$mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$wfCssVersion   = @filemtime(ROOT . '/public/assets/css/workflows.css') ?: 1;
$railJsVersion  = @filemtime(ROOT . '/public/assets/js/workflow-rail.js') ?: 1;
$edJsVersion    = @filemtime(ROOT . '/public/assets/js/workflow-editor.js') ?: 1;
$wfJsVersion    = @filemtime(ROOT . '/public/assets/js/workflows.js') ?: 1;
$historyUrl     = '/workflows?vue=historique&workflow=' . (int)$workflow['id'];
$icons = WorkflowCatalog::icons();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= htmlspecialchars($workflow['name']) ?> — <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <link rel="stylesheet" href="/assets/css/workflows.css?v=<?= (int)$wfCssVersion ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Workflows'); $page = 'workflows'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<div class="admin-content wf-content">
    <div class="wf-editor">
        <div class="wf-ed-top">
            <a class="wf-ed-back" href="/workflows"><?= $icons['back'] ?><?= te('Workflows') ?></a>
            <div class="wf-ed-name">
                <label class="wf-ed-color" style="background:<?= htmlspecialchars($workflow['color']) ?>" title="<?= te('Couleur du workflow') ?>">
                    <input type="color" id="wfColor" value="<?= htmlspecialchars($workflow['color']) ?>" aria-label="<?= te('Couleur du workflow') ?>">
                </label>
                <input id="wfName" value="<?= htmlspecialchars($workflow['name']) ?>" maxlength="120" aria-label="<?= te('Nom du workflow') ?>">
            </div>
            <div class="wf-ed-actions">
                <span id="wfStatus"></span>
                <span class="wf-save-state" id="wfSaveState" aria-live="polite"></span>
                <?php /* Ses lancements et le journal de chacun : l'onglet « Historique des exécutions », filtré sur ce workflow. */ ?>
                <a class="wf-btn" id="wfHistoryBtn" href="<?= htmlspecialchars($historyUrl) ?>" title="<?= $historyCount === 0 ? te('Aucun lancement pour l’instant') : te('Historique des exécutions de ce workflow') ?>"><?= $icons['history'] ?><span class="lbl"><?= te('Historique') ?></span><?php if ($historyCount > 0): ?><span class="wf-count is-muted"><?= (int)$historyCount ?></span><?php endif; ?></a>
                <button type="button" class="wf-btn" id="wfOverview" aria-haspopup="dialog"><?= $icons['settings'] ?><?= te('Réglages') ?><span class="wf-count" id="wfIssueCount" hidden></span></button>
                <?php /* Lancer = droit « Workflows — Utiliser » (Personnes → Rôles). */ ?>
                <?php if (!empty($editor['can']['use'])): ?>
                <button type="button" class="wf-btn" id="wfLaunch"<?= $workflow['published'] === null ? ' hidden' : '' ?>><?= $icons['spark'] ?><?= te('Lancer') ?></button>
                <?php endif; ?>
                <button type="button" class="wf-btn primary" id="wfPublish"><?= $icons['check'] ?><?= te('Publier') ?></button>
            </div>
        </div>
        <button type="button" class="wf-ed-objective" id="wfObjectiveLine"></button>
        <?php if (!empty($editor['origin'])): $origin = $editor['origin']; ?>
        <?php /* Ouvert depuis la carte d'une conversation : où en est le workflow, et le chemin du retour. */ ?>
        <div class="wf-ed-origin" id="wfOrigin" role="status">
            <span class="wf-ed-origin-icon"><?= $icons['spark'] ?></span>
            <span class="wf-ed-origin-text" id="wfOriginText"></span>
            <a class="wf-ed-origin-back" href="<?= htmlspecialchars($origin['url']) ?>"<?= $origin['title'] !== '' ? ' title="' . htmlspecialchars($origin['title']) . '"' : '' ?>><?= $icons['back'] ?><?= te('Retour à la conversation') ?></a>
        </div>
        <?php endif; ?>

        <div class="wf-canvas" id="wfCanvas">
            <div id="wfRail"></div>
            <noscript><p class="wf-hint"><?= te('L’éditeur de workflows a besoin de JavaScript.') ?></p></noscript>
        </div>

    </div>
    <div class="wf-scrim" id="wfScrim"></div>
    <aside class="wf-drawer wf-ins" id="wfInspector" aria-label="<?= te('Réglages de la brique') ?>" aria-hidden="true"></aside>
</div>

<script>
window.WF_EDITOR = <?= json_encode($editor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
// Les anciens liens #historique (la fenêtre d'avant) mènent à l'onglet de l'historique.
(function (m) { if (m) { location.replace(<?= json_encode($historyUrl) ?> + (m[1] ? '&run=' + m[1] : '')); } })(/^#historique(?:-(\d+))?$/.exec(location.hash));
</script>
<script src="/assets/js/workflow-rail.js?v=<?= (int)$railJsVersion ?>" defer></script>
<script src="/assets/js/workflows.js?v=<?= (int)$wfJsVersion ?>" defer></script>
<script src="/assets/js/workflow-editor.js?v=<?= (int)$edJsVersion ?>" defer></script>
</body>
</html>
