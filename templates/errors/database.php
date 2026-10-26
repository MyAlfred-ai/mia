<?php
/**
 * Base de données injoignable, ou migration en échec.
 *
 * Rendue par ErrorHandler. Variables attendues :
 *   @var string      $message   message français déjà expurgé (jamais le mot de passe)
 *   @var string      $incident  identifiant repris en tête de la ligne de journal
 *   @var string|null $detail    trace complète, seulement si APP_ENV est local
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Base de données indisponible — MIA</title>
    <link rel="icon" href="/assets/img/logo.png">
    <?php require __DIR__ . '/_style.php'; ?>
</head>
<body>
<main class="err-card">
    <span class="err-badge">Base de données</span>
    <h1>MIA ne peut pas joindre sa base de données</h1>
    <p class="err-lead"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>

    <div class="err-item">
        <strong>Que vérifier</strong>
        <p>
            Le service de base est-il démarré&nbsp;? Sous Docker&nbsp;:
            <code>docker compose ps</code> puis <code>docker compose logs db</code>.
        </p>
        <p>
            Les variables <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>,
            <code>DB_USER</code> et <code>DB_PASS</code> sont-elles cohérentes&nbsp;?
        </p>
        <p>
            Diagnostic complet&nbsp;: <code>php scripts/doctor.php</code>.
        </p>
    </div>

    <?php if ($detail !== null): ?>
        <details open>
            <summary>Trace technique (affichée car <code>APP_ENV</code> est un environnement de développement)</summary>
            <pre><?= htmlspecialchars($detail, ENT_QUOTES) ?></pre>
        </details>
    <?php endif; ?>

    <div class="err-foot">
        Identifiant d'incident&nbsp;: <code><?= htmlspecialchars($incident, ENT_QUOTES) ?></code><br>
        Le détail est journalisé dans <code>storage/logs/errors.log</code> sous ce même identifiant.
    </div>
</main>
</body>
</html>
