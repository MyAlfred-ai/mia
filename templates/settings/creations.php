<?php
/**
 * Galerie « Mes créations » (/settings/creations).
 * Variables fournies par UserSettingsController::creations :
 *   @var array $creations  cartes construites par buildCreationItems()
 *   @var array $typeCounts [type => nombre] — seuls les types présents
 *
 * Grille servie côté serveur (data-* pré-normalisés pour le filtre JS) ;
 * aperçus html/deck posés en lazy par creations.js via les endpoints
 * /chat/message/{id}/html|deck (gardés par belongsToUser).
 */

// Libellés par type — copie de templates/share/show.php (source des libellés de partage).
$typeLabels = [
    'html_page'       => t('Page web'),
    'slide_deck'      => t('Présentation'),
    'map_route'       => t('Itinéraire'),
    'video_file'      => t('Vidéo'),
    'generated_image' => t('Image'),
];
// Couleur d'accent par type — mêmes teintes que les cartes de /admin/tools ($typeAccents).
$typeAccents = [
    'generated_image' => 'var(--accent)',
    'video_file'      => '#f87171',
    'html_page'       => '#818cf8',
    'map_route'       => '#34d399',
    'slide_deck'      => '#fbbf24',
];
// Icônes par type (feather 24×24, stroke currentColor) — tuiles carte + fallback d'aperçu.
$typeIcons = [
    'generated_image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    'video_file'      => '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
    'html_page'       => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    'map_route'       => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
    'slide_deck'      => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
];
// Normalisation recherche (minuscules, accents strippés) — miroir de norm() dans creations.js.
$crNorm = static function (string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ]);
};

$mainCssVersion    = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$creationsJsVersion = @filemtime(ROOT . '/public/assets/js/creations.js') ?: 1;
$total = count($creations);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Mes créations —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Mes créations'); $page = 'creations'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<!-- Contenu -->
<div class="admin-content">
    <div class="creations-wrap">
        <h1 style="margin-bottom:6px"><?= te('Mes créations') ?></h1>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:22px">
            <?= te('Toutes les images, vidéos, pages web, présentations et itinéraires que vous avez générés, toutes conversations confondues.') ?>
        </p>

        <?php if ($total === 0): ?>
            <div class="al-empty">
                <?= te('Aucune création pour l\'instant.') ?><br>
                <?= te('Générez une image, une page web, une vidéo, une présentation ou un itinéraire depuis le chat : tout apparaîtra ici.') ?>
            </div>
        <?php else: ?>

        <div class="al-toolbar">
            <input type="search" id="crSearch" class="form-input al-search" placeholder="<?= te('Rechercher (titre, conversation)…') ?>" autocomplete="off">
            <select id="crType" class="form-select" aria-label="<?= te('Filtrer par type') ?>">
                <option value=""><?= te('Tous les types') ?></option>
                <?php foreach ($typeLabels as $tKey => $tLabel): ?>
                    <?php if (!empty($typeCounts[$tKey])): ?>
                        <option value="<?= htmlspecialchars($tKey) ?>"><?= htmlspecialchars($tLabel) ?> (<?= (int)$typeCounts[$tKey] ?>)</option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <span class="al-count" id="crCount"><?= $total ?> <?= te('création') ?><?= $total > 1 ? 's' : '' ?></span>
        </div>

        <div class="creations-grid" id="crGrid">
            <?php foreach ($creations as $item):
                $type   = $item['type'];
                $label  = $typeLabels[$type] ?? t('Création');
                $accent = $typeAccents[$type] ?? 'var(--accent)';
                $icon   = $typeIcons[$type] ?? $typeIcons['generated_image'];
                $sub    = '';
                if ($type === 'slide_deck' && !empty($item['slides'])) {
                    $sub = I18n::plural((int)$item['slides'], '{n} slide', '{n} slides');
                } elseif ($type === 'map_route' && !empty($item['waypoints'])) {
                    $sub = I18n::plural((int)$item['waypoints'], '{n} étape', '{n} étapes');
                }
            ?>
            <article class="creation-card" tabindex="0" role="button"
                     aria-label="<?= htmlspecialchars($label . ' — ' . $item['title']) ?>"
                     style="--tool-accent:<?= htmlspecialchars($accent) ?>"
                     data-type="<?= htmlspecialchars($type) ?>"
                     data-label="<?= htmlspecialchars($label) ?>"
                     data-msg="<?= (int)$item['message_id'] ?>"
                     data-conv-id="<?= (int)$item['conversation_id'] ?>"
                     data-title="<?= htmlspecialchars($crNorm($item['title'])) ?>"
                     data-conv="<?= htmlspecialchars($crNorm($item['conv_title'])) ?>"
                     <?php if (!empty($item['url'])): ?>data-url="<?= htmlspecialchars($item['url']) ?>"<?php endif; ?>
                     <?php if ($type === 'html_page'): ?>data-preview="html"<?php elseif ($type === 'slide_deck'): ?>data-preview="deck"<?php endif; ?>>
                <div class="creation-thumb">
                    <?php if ($type === 'generated_image'): ?>
                        <img src="<?= htmlspecialchars($item['url']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy">
                    <?php elseif ($type === 'video_file'): ?>
                        <video src="<?= htmlspecialchars($item['url']) ?>" preload="metadata" muted playsinline></video>
                        <span class="creation-play"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 21 12 6 21 6 3"/></svg></span>
                    <?php elseif ($type === 'map_route'):
                        // Tracé réel des étapes géocodées ; à défaut (spec tronquée
                        // ou géocodage échoué), la tuile d'icône d'origine.
                        $routeSvg = RouteThumbnail::svg((array)($item['route'] ?? [])); ?>
                        <div class="creation-map-tile<?= $routeSvg !== null ? ' has-route' : '' ?>">
                            <?php if ($routeSvg !== null): ?>
                                <?= $routeSvg ?>
                                <?php if (!empty($item['route_ends'])): ?>
                                    <span class="creation-route-ends"><?= htmlspecialchars($item['route_ends']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><?= $typeIcons['map_route'] ?></svg>
                                <?php if ($sub !== ''): ?><span><?= htmlspecialchars($sub) ?></span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php else: /* html_page / slide_deck : iframe posée en lazy par creations.js */ ?>
                        <div class="creation-thumb-loading">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
                            <span><?= te('Aperçu…') ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="creation-info">
                    <div class="creation-chip-row">
                        <span class="creation-chip"><?= htmlspecialchars($label) ?></span>
                        <?php if ($sub !== ''): ?><span class="creation-sub"><?= htmlspecialchars($sub) ?></span><?php endif; ?>
                    </div>
                    <h3 class="creation-title"><?= htmlspecialchars($item['title']) ?></h3>
                    <div class="creation-meta">
                        <?= htmlspecialchars($item['date']) ?> ·
                        <a href="/chat/<?= (int)$item['conversation_id'] ?>" title="<?= te('Ouvrir la conversation') ?>"><?= htmlspecialchars($item['conv_title']) ?></a>
                    </div>
                    <?php $download = (string)($item['download'] ?? ''); ?>
                    <?php if ($download !== '' || !empty($item['shareable'])): ?>
                    <div class="creation-actions">
                        <?php if ($download !== ''): ?>
                            <button type="button" class="btn-download-creation btn-secondary"
                                    data-download="<?= htmlspecialchars($download) ?>"
                                    data-msg-id="<?= (int)$item['message_id'] ?>"
                                    data-filename="<?= htmlspecialchars((string)($item['file_name'] ?? '')) ?>"
                                    <?php if (!empty($item['url'])): ?>data-url="<?= htmlspecialchars($item['url']) ?>"<?php endif; ?>
                                    title="<?= te('Télécharger') ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <?= te('Télécharger') ?>
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($item['shareable'])):
                            $token = (string)($item['share_token'] ?? ''); ?>
                            <?php /* Même bouton que le chat (classes + data-* + états) : creations.js en reprend la logique. */ ?>
                            <button type="button" class="btn-share-creation btn-secondary<?= $token !== '' ? ' shared' : '' ?>"
                                    data-msg-id="<?= (int)$item['message_id'] ?>"
                                    data-share-token="<?= htmlspecialchars($token) ?>"
                                    title="<?= te('Partager par lien (visible uniquement par les utilisateurs connectés)') ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg><?= te($token !== '' ? 'Partagé' : 'Partager') ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <div class="al-empty-filtered" id="crNoResults" style="display:none">
            <?= te('Aucune création ne correspond à votre recherche.') ?>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- Visionneuse plein écran (une seule, réutilisée par toutes les cartes) -->
<div class="modal" id="creationViewer" style="display:none" role="dialog" aria-modal="true" aria-labelledby="cvTitle">
    <div class="modal-box creation-viewer-box">
        <div class="creation-viewer-head">
            <span class="creation-chip" id="cvChip"></span>
            <span class="creation-viewer-title" id="cvTitle"></span>
            <a href="#" id="cvOpenConv" class="btn-secondary creation-viewer-open"><?= te('Ouvrir la conversation') ?></a>
            <button type="button" id="cvClose" class="creation-viewer-close" aria-label="<?= te('Fermer') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="creation-viewer-body" id="cvBody"></div>
    </div>
</div>

<script src="/assets/js/creations.js?v=<?= (int)$creationsJsVersion ?>"></script>
</body>
</html>
