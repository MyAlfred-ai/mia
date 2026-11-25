<?php
/**
 * Fenêtre « Quoi de neuf » — les nouveautés MAJEURES arrivées depuis la
 * dernière visite (MajorFeatures, source unique), une seule fois par compte.
 *
 * Maquette C (choisie le 2026-11-09) : un récapitulatif, les nouveautés côte à
 * côte, chacune avec son visuel, deux phrases et « Aller voir » ; en pied, le
 * rappel de la pastille verte et « J'ai compris ».
 *
 * Le visuel est un SCHÉMA ANIMÉ (essai 1 retenu par Axel le 2026-11-09) :
 * templates/partials/feature-news/<schema>.php, du HTML aux vrais mots —
 * traduits, nets à toute taille, aux couleurs du thème —, mis en forme par
 * public/assets/css/feature-news.css. L'image du catalogue n'est qu'un repli.
 *
 * Rendue sur l'écran de conversation seulement, CACHÉE : feature-news.js
 * l'ouvre une fois la page posée et dit aussitôt au serveur qu'elle a été
 * vue (POST /news/seen). Aucun texte dans le script : tout est ici, traduit.
 */
$fnUser  = $currentUser ?? Session::currentUser();
$fnState = MajorFeatures::forViewer($fnUser);
$fnItems = $fnState['popup'];
if ($fnItems === []) {
    return;
}
// Prénom : premier mot du nom, jamais une adresse email (comptes créés sans nom).
$fnFirst = trim((string)(preg_split('/\s+/', trim((string)($fnUser['name'] ?? ''))) ?: [''])[0]);
if ($fnFirst === '' || str_contains($fnFirst, '@')) {
    $fnFirst = '';
}
$fnCount = count($fnItems);
$fnJs    = '/assets/js/feature-news.js?v=' . (@filemtime(ROOT . '/public/assets/js/feature-news.js') ?: 0);
$fnCss   = '/assets/css/feature-news.css?v=' . (@filemtime(ROOT . '/public/assets/css/feature-news.css') ?: 0);
// Schéma animé d'une nouveauté : templates/partials/feature-news/<schema>.php, sinon son image.
$fnSchema = static function (string $name): string {
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        return '';
    }
    $file = ROOT . '/templates/partials/feature-news/' . $name . '.php';
    return is_file($file) ? $file : '';
};
?>
<link rel="stylesheet" href="<?= htmlspecialchars($fnCss) ?>">
<div class="fn-modal" id="featureNews" hidden
     data-ids="<?= htmlspecialchars(json_encode(array_values($fnState['pending']), JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
    <div class="fn-dialog fn-count-<?= (int)min($fnCount, MajorFeatures::MAX_POPUP) ?>" role="dialog" aria-modal="true"
         aria-labelledby="featureNewsTitle" aria-describedby="featureNewsSub" tabindex="-1">
        <header class="fn-head">
            <span class="fn-head-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
            </span>
            <div class="fn-head-text">
                <h2 class="fn-title" id="featureNewsTitle"><?= $fnFirst !== '' ? te('Quoi de neuf, {prenom} ?', ['prenom' => $fnFirst]) : te('Quoi de neuf ?') ?></h2>
                <p class="fn-sub" id="featureNewsSub"><?= htmlspecialchars(I18n::plural($fnCount, 'Une nouveauté depuis votre dernière visite', '{n} nouveautés depuis votre dernière visite')) ?></p>
            </div>
            <button type="button" class="fn-x" data-fn-close aria-label="<?= te('Fermer') ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </header>

        <div class="fn-cards">
            <?php foreach ($fnItems as $fnI => $fnItem): ?>
            <article class="fn-card" style="--fn-i: <?= (int)$fnI ?>" aria-labelledby="featureNewsCard<?= (int)$fnI ?>">
                <div class="fn-shot">
                    <?php if (($fnSchemaFile = $fnSchema($fnItem['schema'])) !== ''): ?>
                    <?php require $fnSchemaFile; ?>
                    <?php elseif ($fnItem['image'] !== ''): ?>
                    <img src="<?= htmlspecialchars($fnItem['image']) ?>" alt="" width="320" height="180" decoding="async">
                    <?php endif; ?>
                </div>
                <div class="fn-card-body">
                    <h3 class="fn-card-title" id="featureNewsCard<?= (int)$fnI ?>"><?= htmlspecialchars($fnItem['titre']) ?></h3>
                    <p class="fn-card-text"><?= htmlspecialchars($fnItem['texte']) ?></p>
                </div>
                <a class="fn-go" href="<?= htmlspecialchars($fnItem['lien']) ?>" data-fn-go>
                    <?= te('Aller voir') ?>
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </article>
            <?php endforeach; ?>
        </div>

        <footer class="fn-foot">
            <p class="fn-hint"><span class="news-dot is-inline" aria-hidden="true"></span><?= te('Une pastille verte marque ce qui reste à découvrir.') ?></p>
            <button type="button" class="fn-ok" data-fn-close><?= te('J’ai compris') ?></button>
        </footer>
    </div>
</div>
<script src="<?= htmlspecialchars($fnJs) ?>" defer></script>
