<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($appName) ?> <?= te('— Installer l\'application') ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
    <style>
        /* `--safe-top` / `--safe-bottom` : réserve de zone sûre (barre d'état,
           barre home) — 0 en navigateur classique, cf. main.css. Cette page n'a
           ni header ni sidebar : sans réserve, son titre passe sous l'horloge. */
        .appdl-wrap { max-width: 680px; margin: 0 auto; padding: calc(32px + var(--safe-top)) 20px calc(48px + var(--safe-bottom)); }
        .appdl-head { text-align: center; margin-bottom: 28px; }
        .appdl-head img { width: 72px; height: 72px; border-radius: var(--radius-sm); object-fit: contain; }
        .appdl-head h1 { font-size: 22px; margin: 12px 0 4px; }
        .appdl-head p { color: var(--text-muted); font-size: 14px; }
        .appdl-card { background: var(--bg-sidebar); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px; margin-bottom: 18px; }
        .appdl-card h2 { font-size: 16px; display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .appdl-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px; border-radius: 10px; background: var(--accent, #10a37f); color: #fff; font-weight: 600; font-size: 15px; text-decoration: none; }
        .appdl-btn:active { opacity: .85; }
        .appdl-meta { font-size: 12px; color: var(--text-muted); margin-top: 8px; }
        .appdl-steps { font-size: 13px; color: var(--text-muted); margin: 12px 0 0; padding-left: 18px; line-height: 1.7; }
        .appdl-soon { font-size: 14px; color: var(--text-muted); }
        .appdl-row { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
        .appdl-row > div:first-child { flex: 1 1 260px; }
        .appdl-qr { width: 132px; flex: 0 0 auto; background: #fff; padding: 8px; border-radius: 8px; }
        .appdl-qr svg { display: block; width: 100%; height: auto; }
        .appdl-note { text-align: center; font-size: 12.5px; color: var(--text-muted); margin-top: 8px; }
        @media (max-width: 560px) { .appdl-qr { display: none; } } /* QR inutile : on est déjà sur le téléphone */
    </style>
</head>
<body class="admin-page">
    <div class="appdl-wrap">
        <div class="appdl-head">
            <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>" alt="">
            <h1><?= te('Installer {app} sur votre téléphone', ['app' => $appName]) ?></h1>
            <p><?= te('L\'application se met à jour toute seule : elle affiche toujours la dernière version de {app}.', ['app' => $appName]) ?></p>
        </div>

        <?php if (NativeApp::isNative()): ?>
            <div class="appdl-card" style="text-align:center">
                <h2 style="justify-content:center"><?= te('✅ Vous utilisez déjà l\'application') ?></h2>
                <p class="appdl-soon"><?= te('Rien à installer — cette page sert à équiper un autre appareil : partagez simplement ce lien.') ?></p>
            </div>
        <?php endif; ?>

        <!-- Android -->
        <div class="appdl-card">
            <h2><?= te('🤖 Android') ?></h2>
            <div class="appdl-row">
                <div>
                    <?php if ($apk): ?>
                        <a class="appdl-btn" href="<?= htmlspecialchars($apk['url']) ?>"><?= te('⬇ Télécharger l\'application (APK)') ?></a>
                        <div class="appdl-meta">
                            <?= number_format($apk['size'] / 1048576, 1, ',', ' ') ?> <?= te('Mo — version du') ?> <?= date('d/m/Y', $apk['mtime']) ?>
                        </div>
                        <ol class="appdl-steps">
                            <li><?= te('Téléchargez le fichier puis ouvrez-le (barre de notifications).') ?></li>
                            <li><?= te('Si demandé, autorisez l\'installation depuis votre navigateur (« Installer des applications inconnues »).') ?></li>
                            <li><?= te('Ouvrez {app} et connectez-vous.', ['app' => $appName]) ?></li>
                        </ol>
                    <?php else: ?>
                        <p class="appdl-soon"><?= te('📦 Bientôt disponible — l\'application Android n\'a pas encore été publiée sur ce serveur.') ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($apk): ?><div class="appdl-qr" id="qrAndroid" title="<?= te('Scanner avec le téléphone') ?>"></div><?php endif; ?>
            </div>
        </div>

        <!-- iPhone -->
        <div class="appdl-card">
            <h2><?= te('🍏 iPhone / iPad') ?></h2>
            <div class="appdl-row">
                <div>
                    <?php if ($testflightUrl !== ''): ?>
                        <a class="appdl-btn" href="<?= htmlspecialchars($testflightUrl) ?>"><?= te('⬇ Installer via TestFlight') ?></a>
                        <ol class="appdl-steps">
                            <?php /* Balises injectées APRÈS l'échappement : te() échapperait un <strong>
                                     passé en substituant, et l'utilisateur lirait « &lt;strong&gt; ».
                                     Le texte traduit est échappé, les balises sont les nôtres. */ ?>
                            <li><?= str_replace(['{b}', '{/b}'], ['<strong>', '</strong>'], te('Installez l\'app {b}TestFlight{/b} (App Store) si besoin.')) ?></li>
                            <li><?= te('Ouvrez le lien ci-dessus sur l\'iPhone et acceptez l\'invitation.') ?></li>
                            <li><?= te('Installez {app} depuis TestFlight, puis connectez-vous.', ['app' => $appName]) ?></li>
                        </ol>
                    <?php else: ?>
                        <p class="appdl-soon"><?= te('📦 Bientôt disponible sur TestFlight.') ?></p>
                        <ol class="appdl-steps">
                            <li><?= te('En attendant : ouvrez ce site dans') ?> <strong><?= te('Safari') ?></strong>.</li>
                            <li><?= te('Bouton') ?> <strong><?= te('Partager') ?></strong> → <strong><?= te('Sur l\'écran d\'accueil') ?></strong>.</li>
                            <li><?= htmlspecialchars($appName) ?> <?= te('s\'installe comme une application (PWA).') ?></li>
                        </ol>
                    <?php endif; ?>
                </div>
                <?php if ($testflightUrl !== ''): ?><div class="appdl-qr" id="qrIos" title="<?= te('Scanner avec le téléphone') ?>"></div><?php endif; ?>
            </div>
        </div>

        <p class="appdl-note"><?= te('Interface et fonctionnalités identiques au site web — chaque évolution est disponible immédiatement, sans mise à jour manuelle.') ?></p>
    </div>

    <script src="/assets/js/vendor/qrcode.js"></script>
    <script>
        // QR codes (scannés depuis un écran d'ordinateur — masqués sur mobile).
        function renderQr(elId, text) {
            var el = document.getElementById(elId);
            if (!el || typeof qrcode !== 'function') return;
            try {
                var qr = qrcode(0, 'M');
                qr.addData(text);
                qr.make();
                el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
            } catch (e) { el.style.display = 'none'; }
        }
        <?php if ($apk): ?>renderQr('qrAndroid', <?= json_encode((APP_URL ?: '') . $apk['url']) ?>);<?php endif; ?>
        <?php if ($testflightUrl !== ''): ?>renderQr('qrIos', <?= json_encode($testflightUrl) ?>);<?php endif; ?>
    </script>
</body>
</html>
