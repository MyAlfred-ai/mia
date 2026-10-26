<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>><head><meta charset="UTF-8"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><title>403</title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="error-page"><div class="error-box"><h1>403</h1><p>Accès refusé.</p>
<?php /* Raison du refus quand l'appelant la donne (AuthMiddleware::deny403 : droit du rôle manquant). */ ?>
<?php if (isset($message) && is_string($message) && $message !== ''): ?><p class="error-reason"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<a href="/" class="btn-primary">Retour à l'accueil</a></div></body>
</html>
