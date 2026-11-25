<?php
/**
 * Coquille commune des pages « Modèles IA » (clés / modèles et comportement par défaut / tests).
 * Attend : $page (slug nav), $llmTitle (texte, traduit), $llmIntro (HTML déjà
 * échappé et traduit : te() + liens posés par la page), $success et $error (flashs éventuels).
 * Facultatif : $llmHeadAction (HTML déjà échappé) — bouton posé à droite du titre.
 */
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= htmlspecialchars($llmTitle) ?></title>
    <?php $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <link rel="stylesheet" href="/assets/css/llms.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/llms.css') ?: time()) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <?php require ROOT . '/templates/partials/admin-topbar.php'; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success" id="llmFlash" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="llm-page-head page-head">
        <div class="page-head-text">
            <h1><?= htmlspecialchars($llmTitle) ?></h1>
            <p class="page-head-sub"><?= $llmIntro /* HTML déjà échappé par la page (te() + liens) */ ?></p>
        </div>
        <?= $llmHeadAction ?? '' /* HTML déjà échappé par la page */ ?>
    </div>
