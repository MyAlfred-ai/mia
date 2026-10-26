<?php
/**
 * Workflows → créer (/workflows/new).
 * Variables fournies par WorkflowController::newPage :
 *   @var ?array      $plan       plan de travail d'une réponse (« Refaire en workflow »), ou null
 *   @var int         $fromMessage identifiant de cette réponse
 *   @var list<array> $templates  modèles livrés
 *   @var int         $count, $max  workflows de la personne / plafond
 *   @var ?string     $error
 *   @var array       $catalog    icônes, teintes
 *
 * Trois départs : décrire le processus à MIA (elle compose un brouillon), un
 * modèle, ou une page blanche. Tous ouvrent l'éditeur sur un BROUILLON : rien
 * ne s'exécute avant la publication.
 */
$mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$wfCssVersion   = @filemtime(ROOT . '/public/assets/css/workflows.css') ?: 1;
$wfJsVersion    = @filemtime(ROOT . '/public/assets/js/workflows.js') ?: 1;
$icons = $catalog['icons'];
$full = $count >= $max;
$examples = [
    t('Quand je dépose un appel d’offres, MIA en tire une fiche (client, budget, date limite) ; si le budget dépasse 50 000 €, je valide ; puis elle rédige une réponse au modèle de l’entreprise.'),
    t('À partir des notes d’une réunion, MIA rédige le compte rendu, je le relis, puis elle prépare l’email aux participants.'),
    t('Chaque lundi, MIA fait une veille de notre secteur sur Internet, en tire les cinq faits marquants et me les envoie sur Slack.'),
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Nouveau workflow —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <link rel="stylesheet" href="/assets/css/workflows.css?v=<?= (int)$wfCssVersion ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Workflows'); $page = 'workflows'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<div class="admin-content wf-content">
    <a class="wf-ed-back" href="/workflows" style="margin-bottom:12px"><?= $icons['back'] ?><?= te('Workflows') ?></a>
    <div class="wf-page-head">
        <div>
            <h1><span class="wf-h-ic"><?= Workflow::icon(20) ?></span><?= te('Nouveau workflow') ?></h1>
            <p class="wf-lead"><?= te('Le plus rapide : décrivez le processus comme à un collègue. MIA compose un brouillon que vous ajustez ensuite, brique par brique.') ?></p>
        </div>
    </div>

    <?php if (!empty($error)): ?><div class="wf-flash err" role="alert"><?= htmlspecialchars((string)$error) ?></div><?php endif; ?>
    <?php if ($full): ?><div class="wf-flash err" role="alert"><?= te('Vous avez atteint le nombre maximal de workflows ({max}). Supprimez-en un pour en créer un autre.', ['max' => $max]) ?></div><?php endif; ?>

    <?php if ($plan !== null): ?>
    <div class="wf-new-box" style="margin-bottom:18px;--hue:var(--accent)">
        <h2><?= $icons['workflow'] ?><?= te('À partir de la réponse du chat') ?></h2>
        <p><?= te('Cette réponse a suivi un plan en {n} étapes. Il devient un workflow : chaque étape remet son résultat à la suivante, et la demande d’origine devient le champ de départ.', ['n' => (int)$plan['steps']]) ?></p>
        <form method="post" action="/workflows">
            <?= Csrf::field() ?>
            <input type="hidden" name="mode" value="from_message">
            <input type="hidden" name="message_id" value="<?= (int)$fromMessage ?>">
            <button type="submit" class="wf-btn primary"<?= $full ? ' disabled' : '' ?>><?= $icons['check'] ?><?= te('Créer ce workflow') ?></button>
        </form>
    </div>
    <?php endif; ?>

    <div class="wf-new">
        <section class="wf-new-box">
            <h2><?= $icons['spark'] ?><?= te('Décrire à MIA') ?></h2>
            <p><?= te('Ce qui arrive au départ, qui fait quoi, quand vous voulez valider, ce que vous voulez obtenir à la fin.') ?></p>
            <form method="post" action="/workflows" id="wfCompose">
                <?= Csrf::field() ?>
                <input type="hidden" name="mode" value="describe">
                <input type="hidden" name="tz" value="">
                <textarea name="description" required minlength="15" maxlength="4000" placeholder="<?= htmlspecialchars($examples[0]) ?>"></textarea>
                <div class="wf-new-examples" aria-label="<?= te('Exemples') ?>">
                    <?php foreach ($examples as $i => $ex): ?>
                        <button type="button" data-example="<?= htmlspecialchars($ex) ?>"><?= te('Exemple {n}', ['n' => $i + 1]) ?></button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="wf-btn primary"<?= $full ? ' disabled' : '' ?>><?= $icons['spark'] ?><?= te('Composer le workflow') ?></button>
                <div class="wf-composing" id="wfComposing" role="status"><span class="wf-spinner"></span><span><?= te('MIA compose votre workflow : étapes, pièces, validations… Cela prend quelques secondes.') ?></span></div>
                <p class="wf-hint" style="margin-top:10px"><?= te('La composition consomme quelques crédits. Rien ne s’exécute : vous relisez le brouillon avant de le publier.') ?></p>
            </form>
        </section>

        <div style="display:flex;flex-direction:column;gap:18px;min-width:0">
            <section class="wf-new-box">
                <h2><?= $icons['copy'] ?><?= te('Partir d’un modèle') ?></h2>
                <p><?= te('Des processus courants, prêts à régler.') ?></p>
                <div class="wf-tpl-list">
                    <?php foreach ($templates as $tpl): ?>
                    <form method="post" action="/workflows">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="mode" value="template">
                        <input type="hidden" name="template" value="<?= htmlspecialchars($tpl['key']) ?>">
                        <button type="submit" class="wf-tpl-btn" style="--hue:<?= htmlspecialchars($tpl['color']) ?>"<?= $full ? ' disabled' : '' ?>>
                            <b><?= htmlspecialchars($tpl['name']) ?></b>
                            <?= WorkflowCatalog::silhouetteHtml($tpl['silhouette']) ?>
                            <small><?= htmlspecialchars($tpl['description']) ?></small>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="wf-new-box">
                <h2><?= $icons['plus'] ?><?= te('Page blanche') ?></h2>
                <p><?= te('Un départ, un livrable, et vous ajoutez les briques une à une.') ?></p>
                <form method="post" action="/workflows">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="mode" value="blank">
                    <button type="submit" class="wf-btn"<?= $full ? ' disabled' : '' ?>><?= te('Commencer') ?></button>
                </form>
            </section>
        </div>
    </div>
</div>

<script src="/assets/js/workflows.js?v=<?= (int)$wfJsVersion ?>" defer></script>
</body>
</html>
