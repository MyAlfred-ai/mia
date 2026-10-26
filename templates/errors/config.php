<?php
/**
 * Configuration incomplète — l'application ne peut pas démarrer.
 *
 * Rendue par Bootstrap::renderConfigError(). Variables attendues :
 *   @var list<array{variable:string,message:string,remede:string}> $problemes
 *   @var bool $sousDocker
 *
 * Aucune dépendance : ni base, ni Settings, ni main.css.
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration incomplète — MIA</title>
    <link rel="icon" href="/assets/img/logo.png">
    <?php require __DIR__ . '/_style.php'; ?>
</head>
<body>
<main class="err-card">
    <span class="err-badge">Configuration</span>
    <h1>MIA n'est pas encore configurée</h1>
    <p class="err-lead">
        <?= count($problemes) === 1 ? 'Une variable obligatoire est absente.' : count($problemes) . ' variables obligatoires sont absentes.' ?>
        Renseignez-les, puis rechargez cette page.
    </p>

    <?php foreach ($problemes as $probleme): ?>
        <div class="err-item">
            <strong><code><?= htmlspecialchars($probleme['variable'], ENT_QUOTES) ?></code></strong>
            <p><?= htmlspecialchars($probleme['message'], ENT_QUOTES) ?></p>
            <p><?= htmlspecialchars($probleme['remede'], ENT_QUOTES) ?></p>
        </div>
    <?php endforeach; ?>

    <?php if ($sousDocker): ?>
        <p class="err-lead" style="margin-top:1.5rem">
            Vous tournez dans un conteneur : ces valeurs viennent du fichier <code>.env</code>
            <em>de la machine hôte</em>, lu par <code>docker compose</code>. Les écrire depuis
            l'application serait sans effet — le fichier disparaîtrait à la reconstruction de l'image.
        </p>
        <code class="err-block">cd &lt;dossier de l'application&gt;
./install.sh            # génère un .env complet, ou bien à la main :
printf 'APP_SECRET=%s\nENCRYPTION_KEY=%s\n' "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" &gt;&gt; .env
docker compose up -d</code>
    <?php else: ?>
        <p class="err-lead" style="margin-top:1.5rem">
            Installation native : ces valeurs se placent dans le fichier <code>.env</code>
            à la racine de l'application.
        </p>
        <code class="err-block">cd &lt;dossier de l'application&gt;
printf 'APP_SECRET=%s\nENCRYPTION_KEY=%s\n' "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" &gt;&gt; .env</code>
    <?php endif; ?>

    <div class="err-foot">
        Diagnostic complet en ligne de commande : <code>php scripts/doctor.php</code>.
        Procédure détaillée : <code>docs/INSTALLATION.md</code>.
    </div>
</main>
</body>
</html>
