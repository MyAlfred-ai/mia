<?php /* Fin de flux OAuth natif (app mobile) — rendue dans le NAVIGATEUR
         SYSTÈME, sans session. Variables : $ok (bool), $message (string). */ ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> <?= te('— Connexion Google') ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="login-page">
    <div class="login-box" style="text-align:center">
        <div class="login-logo">
            <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>" alt="" width="72" height="72" style="border-radius:var(--radius-sm);object-fit:contain">
        </div>
        <h1 style="font-size:26px"><?= $ok ? '✅' : '⚠️' ?></h1>
        <p style="font-size:15px;margin:10px 0 4px"><?= htmlspecialchars($message) ?></p>
        <p style="font-size:13px;color:var(--text-muted);margin-top:14px">
            <?= te('Vous pouvez fermer cet onglet et') ?> <strong><?= te('revenir dans l\'application') ?>
            <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></strong><?= $ok ? te(' — la connexion apparaîtra dans Réglages → Comptes connectés.') : '.' ?>
        </p>
    </div>
</body>
</html>
