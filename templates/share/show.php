<?php
/**
 * Page d'affichage d'une création partagée (/share/{token}).
 * Variables fournies par ShareController::show :
 *   @var array       $shareInfo  ['type','shared_by','shared_at']
 *   @var string|null $shareHtml  document HTML sanitisé (type html_page)
 *   @var array|null  $shareMap   spec d'itinéraire géocodée (type map_route)
 *   @var array|null  $shareMedia ['url','name'] (types video_file / generated_image)
 * Standalone : aucune donnée de conversation, aucune dépendance à chat.js.
 */
$appName    = Settings::get('app_name', 'MIA');
$shareType  = (string)$shareInfo['type'];
$sharedBy   = trim((string)$shareInfo['shared_by']);
$sharedAtTs = strtotime((string)$shareInfo['shared_at']) ?: null;
$typeLabels = [
    'html_page'       => t('Page web'),
    'slide_deck'      => t('Présentation'),
    'map_route'       => t('Itinéraire'),
    'video_file'      => t('Vidéo'),
    'generated_image' => t('Image'),
];
$typeLabel = $typeLabels[$shareType] ?? t('Création');

// Payload lu par share.js — encodage hex complet : ni </script>, ni quotes évadables.
$sharePayload = json_encode([
    'type'  => $shareType,
    'html'  => $shareHtml,
    'map'   => $shareMap,
    'media' => $shareMedia,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$shareJsVersion = @filemtime(ROOT . '/public/assets/js/share.js') ?: 1;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($typeLabel) ?> <?= te('partagée -') ?> <?= htmlspecialchars($appName) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php if ($shareType === 'map_route'): ?>
    <link rel="stylesheet" href="/assets/js/vendor/leaflet/leaflet.css">
    <?php endif; ?>
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="share-page">

    <header class="share-header">
        <a href="/" class="share-logo-link" title="Ouvrir <?= htmlspecialchars($appName) ?>">
            <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>" alt="<?= htmlspecialchars($appName) ?>" class="share-logo">
        </a>
        <div class="share-header-info">
            <span class="share-title"><?= htmlspecialchars($typeLabel) ?> <?= te('partagée') ?></span>
            <span class="share-meta">
                <?php if ($sharedBy !== ''): ?><?= te('par {auteur}', ['auteur' => $sharedBy]) ?><?php endif; ?>
                <?php if ($sharedAtTs): ?> · <?= htmlspecialchars(Format::dateFr(date('Y-m-d', $sharedAtTs))) ?><?php endif; ?>
            </span>
        </div>
        <a href="/" class="btn-secondary share-open-app"><?= te('Ouvrir') ?> <?= htmlspecialchars($appName) ?></a>
    </header>

    <main class="share-main" data-share-type="<?= htmlspecialchars($shareType) ?>">
        <?php if ($shareType === 'html_page' || $shareType === 'slide_deck'): ?>
            <!-- Rendu dans une iframe blob: sandboxée (parité viewer chat) : le HTML
                 généré ne s'exécute JAMAIS same-origin avec les cookies de session.
                 Un deck est servi comme une page HTML : DeckRenderer embarque sa
                 propre navigation, rien à dupliquer ici. -->
            <iframe id="shareHtmlFrame" class="share-html-frame" title="<?= te('Page partagée') ?>"
                    sandbox="allow-scripts allow-forms" referrerpolicy="no-referrer"></iframe>
        <?php elseif ($shareType === 'map_route'): ?>
            <div class="share-map-wrap">
                <div id="shareMapCanvas" class="share-map-canvas"></div>
                <aside id="shareMapNotes" class="share-map-notes"></aside>
            </div>
        <?php elseif ($shareType === 'video_file'): ?>
            <div class="share-media-wrap">
                <video class="share-video" controls src="<?= htmlspecialchars((string)$shareMedia['url']) ?>"></video>
                <p class="share-media-name"><?= htmlspecialchars((string)$shareMedia['name']) ?></p>
            </div>
        <?php elseif ($shareType === 'generated_image'): ?>
            <div class="share-media-wrap">
                <img class="share-image" src="<?= htmlspecialchars((string)$shareMedia['url']) ?>" alt="<?= te('Image partagée') ?>">
                <p class="share-media-name"><?= htmlspecialchars((string)$shareMedia['name']) ?></p>
            </div>
        <?php endif; ?>
    </main>

    <script type="application/json" id="sharePayload"><?= $sharePayload ?></script>
    <?php if ($shareType === 'map_route'): ?>
    <script src="/assets/js/vendor/leaflet/leaflet.js"></script>
    <?php endif; ?>
    <script src="/assets/js/share.js?v=<?= (int)$shareJsVersion ?>"></script>
</body>
</html>
