<?php
/**
 * Fin d'installation : ce qui reste à faire, avec l'état RÉEL de chaque point.
 *
 *   @var list<array{fait:bool,titre:string,detail:string,lien:string,action:string}> $etapes
 *
 * Remplace la redirection sèche vers /auth/login : rien n'indiquait qu'il
 * fallait encore enregistrer une clé API pour que l'assistant réponde.
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Installation terminée — MIA</title>
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

        .card {
            background: #161625;
            border: 1px solid #2d2d4e;
            border-radius: 16px;
            padding: 2.5rem;
            width: 100%;
            max-width: 620px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .6);
        }

        h1 { font-size: 1.6rem; font-weight: 700; color: #a78bfa; margin-bottom: .5rem; }
        .lead { color: #94a3b8; font-size: .95rem; margin-bottom: 1.75rem; }

        .etape {
            display: flex;
            gap: .9rem;
            padding: .9rem 0;
            border-top: 1px solid #2d2d4e;
        }
        .etape:first-of-type { border-top: none; }
        .puce { font-size: 1rem; line-height: 1.5; }
        .fait     { color: #4ade80; }
        .a-faire  { color: #fbbf24; }
        .etape strong { font-size: .95rem; display: block; }
        .etape p { color: #94a3b8; font-size: .875rem; margin-top: .15rem; }
        .etape a {
            align-self: center;
            white-space: nowrap;
            color: #a78bfa;
            font-size: .875rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #2d2d4e;
            border-radius: 8px;
            padding: .35rem .75rem;
        }
        .etape a:hover { border-color: #a78bfa; }

        .suite {
            display: block;
            margin-top: 1.75rem;
            padding: .8rem;
            text-align: center;
            background: #a78bfa;
            color: #0d0d14;
            border-radius: 8px;
            font-weight: 700;
            text-decoration: none;
        }
        .suite:hover { background: #b9a2fb; }
    </style>
</head>
<body>
<main class="card">
    <h1>Compte créé — MIA est prête</h1>
    <p class="lead">
        Connectez-vous avec le compte que vous venez de créer. Voici ce qui reste à configurer :
        seule la première ligne est indispensable.
    </p>

    <?php foreach ($etapes as $etape): ?>
        <div class="etape">
            <span class="puce <?= $etape['fait'] ? 'fait' : 'a-faire' ?>"><?= $etape['fait'] ? '✓' : '○' ?></span>
            <div style="flex:1;min-width:0">
                <strong><?= htmlspecialchars($etape['titre'], ENT_QUOTES) ?></strong>
                <p><?= htmlspecialchars($etape['detail'], ENT_QUOTES) ?></p>
            </div>
            <a href="<?= htmlspecialchars($etape['lien'], ENT_QUOTES) ?>"><?= htmlspecialchars($etape['action'], ENT_QUOTES) ?></a>
        </div>
    <?php endforeach; ?>

    <a class="suite" href="/auth/login">Se connecter</a>
</main>
</body>
</html>
