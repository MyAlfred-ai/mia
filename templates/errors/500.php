<?php
/**
 * Erreur interne non rattrapée.
 *
 * Rendue par ErrorHandler. Variables attendues :
 *   @var string      $message   message public, volontairement générique
 *   @var string      $incident  identifiant repris en tête de la ligne de journal
 *   @var string|null $detail    trace complète, seulement si APP_ENV est local
 *
 * L'identifiant d'incident est le cœur de cette page : il permet à un
 * utilisateur de signaler la panne et à l'exploitant de retrouver la trace
 * exacte, sans avoir à corréler des horodatages ni à exposer quoi que ce soit.
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur interne — MIA</title>
    <link rel="icon" href="/assets/img/logo.png">
    <?php require __DIR__ . '/_style.php'; ?>
</head>
<body>
<main class="err-card">
    <span class="err-badge">Erreur</span>
    <h1><?= htmlspecialchars($message, ENT_QUOTES) ?></h1>
    <p class="err-lead">
        L'incident a été enregistré. Communiquez l'identifiant ci-dessous à votre
        administrateur&nbsp;: il permet de retrouver la trace complète.
    </p>

    <?php if ($detail !== null): ?>
        <details open>
            <summary>Trace technique (affichée car <code>APP_ENV</code> est un environnement de développement)</summary>
            <pre><?= htmlspecialchars($detail, ENT_QUOTES) ?></pre>
        </details>
    <?php endif; ?>

    <div class="err-foot">
        Identifiant d'incident&nbsp;: <code><?= htmlspecialchars($incident, ENT_QUOTES) ?></code><br>
        <a href="/">Retour à l'accueil</a>
    </div>
</main>
</body>
</html>
