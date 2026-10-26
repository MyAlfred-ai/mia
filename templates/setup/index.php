<?php
/**
 * Premier démarrage : création du compte administrateur.
 *
 * Variables attendues :
 *   @var list<string>            $erreurs
 *   @var array{name:string,email:string} $saisie
 *
 * Volontairement autonome (CSS en ligne, aucun appel à Settings) : cette page
 * s'affiche avant qu'aucun paramètre n'existe en base.
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Premier démarrage — MIA</title>
    <link rel="icon" href="/assets/img/logo.png">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d0d14;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            line-height: 1.55;
        }

        .setup-card {
            background: #161625;
            border: 1px solid #2d2d4e;
            border-radius: 16px;
            padding: 2.5rem;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .6);
        }

        .setup-header { text-align: center; margin-bottom: 2rem; }
        .setup-header img { width: 56px; height: 56px; margin-bottom: 1rem; }
        .setup-header h1 { font-size: 1.6rem; font-weight: 700; color: #a78bfa; margin-bottom: .5rem; }
        .setup-header p { color: #94a3b8; font-size: .925rem; }

        label { display: block; font-size: .875rem; font-weight: 600; margin-bottom: .35rem; }

        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: .7rem .9rem;
            margin-bottom: 1.1rem;
            background: #0d0d14;
            border: 1px solid #2d2d4e;
            border-radius: 8px;
            color: #e2e8f0;
            font-size: .95rem;
            font-family: inherit;
        }

        input:focus { outline: none; border-color: #a78bfa; }

        button {
            width: 100%;
            padding: .8rem;
            background: #a78bfa;
            color: #0d0d14;
            border: none;
            border-radius: 8px;
            font-size: .975rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
        }

        button:hover { background: #b9a2fb; }

        .erreurs {
            background: rgba(251, 113, 133, .1);
            border: 1px solid rgba(251, 113, 133, .35);
            border-radius: 8px;
            padding: .9rem 1rem;
            margin-bottom: 1.5rem;
            font-size: .9rem;
        }
        .erreurs ul { margin: 0; padding-left: 1.1rem; }

        .note {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid #2d2d4e;
            font-size: .825rem;
            color: #64748b;
        }
        .note code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            color: #7dd3fc;
        }
    </style>
</head>
<body>
<main class="setup-card">
    <div class="setup-header">
        <img src="/assets/img/logo.png" alt="">
        <h1>Bienvenue dans MIA</h1>
        <p>Créez le compte administrateur. C'est la dernière étape de l'installation.</p>
    </div>

    <?php if ($erreurs !== []): ?>
        <div class="erreurs">
            <ul>
                <?php foreach ($erreurs as $erreur): ?>
                    <li><?= htmlspecialchars($erreur, ENT_QUOTES) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="/setup">
        <?= Csrf::field() ?>

        <label for="admin_name">Nom affiché</label>
        <input type="text" id="admin_name" name="admin_name" required autofocus
               value="<?= htmlspecialchars($saisie['name'], ENT_QUOTES) ?>">

        <label for="admin_email">Adresse e-mail</label>
        <input type="email" id="admin_email" name="admin_email" required
               value="<?= htmlspecialchars($saisie['email'], ENT_QUOTES) ?>">

        <label for="admin_password">Mot de passe <span style="font-weight:400;color:#64748b">(8 caractères minimum)</span></label>
        <input type="password" id="admin_password" name="admin_password" required minlength="8" autocomplete="new-password">

        <label for="admin_password_confirm">Confirmer le mot de passe</label>
        <input type="password" id="admin_password_confirm" name="admin_password_confirm" required minlength="8" autocomplete="new-password">

        <button type="submit">Créer le compte et continuer</button>
    </form>

    <p class="note">
        La configuration du serveur (base de données, secrets, adresse publique) se trouve dans le
        fichier <code>.env</code>, généré par <code>./install.sh</code>. Cette page n'y touche pas.
        Pour vérifier l'installation : <code>php scripts/doctor.php</code>.
    </p>
</main>
</body>
</html>
