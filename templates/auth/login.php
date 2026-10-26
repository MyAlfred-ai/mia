<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> <?= te('— Connexion') ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="login-page">
    <div class="login-box">
        <div class="login-logo">
            <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>" alt="<?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?>" width="96" height="96" style="border-radius:var(--radius-sm);object-fit:contain">
        </div>
        <h1><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></h1>
        <p class="login-subtitle"><?= htmlspecialchars(I18n::editorial(Settings::get('app_tagline', ''), 'Votre assistant IA multi-modèles')) ?></p>
<?php
        // Message libre de l'administrateur (Organisation → Authentification),
        // vide par défaut. Contenu d'instance : jamais traduit, comme une
        // punchline personnalisée (cf. I18n::editorial). Les retours à la ligne
        // saisis sont rendus par `white-space: pre-line`, sans balise ajoutée.
        $loginMessage = SsoRegistry::loginMessage();
?>
<?php if ($loginMessage !== ''): ?>
        <p class="login-notice"><?= htmlspecialchars($loginMessage) ?></p>
<?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <?php echo match($error) {
                    'inactive'             => te('Votre compte est en attente de validation par un administrateur.'),
                    'pending'              => te('Votre compte est en attente de validation par un administrateur.'),
                    'oauth_failed'         => te('Erreur lors de la connexion. Veuillez réessayer.'),
                    'invalid'              => te('Requête invalide.'),
                    'expired'              => te('Session expirée. Merci de réessayer.'),
                    'invalid_password'     => te('Mot de passe incorrect.'),
                    'invalid_credentials'  => te('Email ou mot de passe incorrect.'),
                    'too_many_attempts'    => te('Trop de tentatives. Réessayez dans 15 minutes.'),
                    'provider_disabled'    => te('Ce moyen de connexion n\'est plus disponible. Essayez-en un autre.'),
                    // Le fournisseur a refusé AVANT de nous rendre un code : la
                    // cause est chez lui, pas ici. Chez Microsoft (Entra) c'est
                    // le cas le plus courant en entreprise — d'où un message qui
                    // désigne l'administrateur de l'annuaire, et non un
                    // « réessayez » qui ne mènerait nulle part.
                    // Messages d'une seule pièce : une phrase coupée en trois
                    // littéraux n'est pas traduisible — l'ordre des propositions
                    // change d'une langue à l'autre.
                    'consent_required'     => te('Votre organisation n\'a pas autorisé cette application. Un administrateur doit accorder le consentement (ou vous attribuer l\'application) côté Microsoft, puis vous pourrez vous connecter.'),
                    'oauth_denied'         => te('Connexion refusée par le fournisseur d\'identité. Réessayez ; si le refus persiste, un administrateur trouvera la cause exacte dans les logs.'),
                    'domain_denied'        => te('Votre adresse e-mail n\'appartient pas à un domaine autorisé sur cette application.'),
                    'no_email'             => te('Aucune adresse e-mail n\'a été transmise par le fournisseur : impossible de créer votre compte.'),
                    'email_taken'          => te('Un compte existe déjà avec cette adresse. Connectez-vous avec votre méthode habituelle, ou demandez à un administrateur de rattacher ce mode de connexion.'),
                    default                => te('Une erreur est survenue.'),
                }; ?>
            </div>
        <?php endif; ?>

<?php
        // Boutons de connexion rendus depuis le REGISTRE, jamais en dur : un
        // fournisseur n'apparaît que s'il est activé en administration ET
        // réellement configuré. Auparavant le bouton Google s'affichait même
        // sans identifiants et menait à un écran d'erreur Google.
        $ssoProviders = SsoRegistry::loginProviders();
        $ssoIcons = [
            SsoRegistry::GOOGLE => '<svg width="20" height="20" viewBox="0 0 48 48">'
                . '<path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.2l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.6 5.6 2.7 13.7l7.9 6.1C12.5 13.5 17.8 9.5 24 9.5z"/>'
                . '<path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8C43.8 37.5 46.5 31.4 46.5 24.5z"/>'
                . '<path fill="#FBBC05" d="M10.6 28.2A14.5 14.5 0 0 1 9.5 24c0-1.5.2-2.9.6-4.3l-7.9-6.1A23.9 23.9 0 0 0 0 24c0 3.9.9 7.5 2.5 10.8l8.1-6.6z"/>'
                . '<path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.2 1.5-5 2.4-8.4 2.4-6.2 0-11.5-4.2-13.4-9.8l-8.1 6.6C6.6 42.4 14.6 48 24 48z"/></svg>',
            SsoRegistry::MICROSOFT => '<svg width="20" height="20" viewBox="0 0 23 23">'
                . '<path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M12 1h10v10H12z"/>'
                . '<path fill="#00A4EF" d="M1 12h10v10H1z"/><path fill="#FFB900" d="M12 12h10v10H12z"/></svg>',
        ];
?>
<?php foreach ($ssoProviders as $ssoProvider): ?>
    <?php $cle = $ssoProvider->key(); ?>
    <?php if ($cle === SsoRegistry::GOOGLE && NativeApp::isNative()): ?>
        <?php /* App mobile : l'OAuth web est bloqué par Google en WebView
                 (disallowed_useragent) → bouton branché sur le plugin natif
                 Google Sign-In par native-bridge.js (POST /auth/google-token).
                 Microsoft n'impose pas cette restriction et emprunte le flux
                 web habituel, y compris dans l'application. */ ?>
        <script>
            window.GOOGLE_NATIVE_CLIENT_IDS = {
                web: <?= json_encode(SsoRegistry::googleClientId()) ?>,
                ios: <?= json_encode(GOOGLE_CLIENT_ID_IOS) ?>
            };
        </script>
        <button type="button" id="nativeGoogleBtn" class="btn-google btn-sso">
            <?= $ssoIcons[$cle] ?? '' ?>
            <?= te('Continuer avec') ?> <?= htmlspecialchars($ssoProvider->label()) ?>
        </button>
    <?php else: ?>
        <a href="<?= htmlspecialchars($ssoProvider->authUrl()) ?>" class="btn-google btn-sso">
            <?= $ssoIcons[$cle] ?? '' ?>
            <?= te('Continuer avec') ?> <?= htmlspecialchars($ssoProvider->label()) ?>
        </a>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($passwordEnabled): ?>
        <?php if ($ssoProviders !== []): ?>
            <div class="login-divider"><span><?= te('ou') ?></span></div>
        <?php endif; ?>

        <!-- Connexion email / mot de passe -->
        <form method="POST" action="/auth/login" style="width:100%;display:flex;flex-direction:column;gap:8px">
            <?= Csrf::field() ?>
            <input type="email" name="email" class="form-input" placeholder="<?= te('Email') ?>"
                   value="<?= htmlspecialchars($_GET['email'] ?? '') ?>"
                   style="width:100%" autocomplete="email">
            <div style="position:relative">
                <input type="password" name="password" class="form-input" placeholder="<?= te('Mot de passe') ?>"
                       required style="width:100%;padding-right:78px" autocomplete="current-password">
                <button type="button" class="btn-toggle-password" aria-label="<?= te('Afficher le mot de passe') ?>"
                        style="position:absolute;right:44px;top:50%;transform:translateY(-50%);background:transparent;border:0;padding:6px;cursor:pointer;color:var(--text-muted);display:flex;align-items:center;justify-content:center">
                    <svg class="icon-eye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="icon-eye-off" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a19.77 19.77 0 0 1 4.06-5.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a19.77 19.77 0 0 1-2.16 3.19M9.88 9.88a3 3 0 1 0 4.24 4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
                <button type="submit" class="btn-send" style="position:absolute;right:8px;top:50%;transform:translateY(-50%)" title="<?= te('Se connecter') ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </div>
        </form>
<?php endif; ?>

<?php if ($ssoProviders === [] && !$passwordEnabled): ?>
        <?php /* Cas normalement impossible : la garde anti-verrouillage de
                 /admin/sso rétablit le mot de passe. Le message évite tout de
                 même une page muette si l'on y arrive autrement (identifiants
                 effacés à la main en base). */ ?>
        <div class="alert alert-error" style="margin:0">
            <?= te('Aucun moyen de connexion n\'est disponible. Un administrateur doit en réactiver un depuis la configuration de l\'application.') ?>
        </div>
<?php endif; ?>

<?php if (LOCAL_PASSWORD_HASH !== ''): ?>
        <!-- Accès de secours : mot de passe global sans e-mail. Volontairement
             indépendant des réglages, c'est le recours si une connexion
             externe cesse de fonctionner. -->
        <details style="width:100%;margin-top:4px">
            <summary style="font-size:12px;color:var(--text-muted);cursor:pointer;text-align:center;list-style:none">
                <?= te('Connexion admin locale ›') ?>
            </summary>
            <form method="POST" action="/auth/login" style="width:100%;margin-top:8px">
                <?= Csrf::field() ?>
                <div style="position:relative">
                    <input type="password" name="password" class="form-input" placeholder="<?= te('Mot de passe admin') ?>"
                           required style="width:100%;padding-right:78px" autocomplete="off">
                    <button type="button" class="btn-toggle-password" aria-label="<?= te('Afficher le mot de passe') ?>"
                            style="position:absolute;right:44px;top:50%;transform:translateY(-50%);background:transparent;border:0;padding:6px;cursor:pointer;color:var(--text-muted);display:flex;align-items:center;justify-content:center">
                        <svg class="icon-eye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-eye-off" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a19.77 19.77 0 0 1 4.06-5.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a19.77 19.77 0 0 1-2.16 3.19M9.88 9.88a3 3 0 1 0 4.24 4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                    <button type="submit" class="btn-send" style="position:absolute;right:8px;top:50%;transform:translateY(-50%)" title="<?= te('Connexion admin') ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>
            </form>
        </details>
<?php endif; ?>
    </div>
<?php require ROOT . '/templates/partials/lang-switcher.php'; ?>
<?php $lsJs = @filemtime(ROOT . '/public/assets/js/user-menu.js') ?: time(); ?>
<script src="/assets/js/user-menu.js?v=<?= (int)$lsJs ?>"></script>
    <script>
        document.querySelectorAll('.btn-toggle-password').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var input = btn.parentElement.querySelector('input[type="password"], input[type="text"]');
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.querySelector('.icon-eye').style.display = show ? 'none' : '';
                btn.querySelector('.icon-eye-off').style.display = show ? '' : 'none';
                btn.setAttribute('aria-label', show ? <?= tj('Masquer le mot de passe') ?> : <?= tj('Afficher le mot de passe') ?>);
            });
        });
    </script>
</body>
</html>
