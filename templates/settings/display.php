<?php
/**
 * Réglages → Affichage : thème clair ou sombre, taille d'affichage,
 * contraste renforcé, et touche d'envoi des messages du chat.
 *
 * Réglages PERSONNELS (colonnes users.ui_theme / ui_scale / ui_contrast /
 * ui_send_key), qui suivent
 * le compte sur tous les appareils. Source unique : DisplayPreferences — valeurs,
 * libellés, et les attributs posés sur <html> par tous les gabarits.
 *
 * Formulaire auto-enregistré (contrat AutosaveContractTest). Le choix s'applique
 * À LA PAGE dès le clic, avant même la réponse du serveur : la page elle-même
 * est l'aperçu, et c'est le seul réglage où l'on juge sur pièce. Le script en
 * bas de page rejoue exactement ce que fait le serveur au chargement suivant
 * (mêmes attributs de <html>), et le thème « Automatique » passe par
 * window.miaApplyTheme (head-meta.php) — une seule implémentation.
 *
 * Styles : bloc « Préférences IA » de main.css (.ai-prefs-*), vignettes de
 * thème partagées avec Marque & apparence (.brand-theme-*), bloc « Affichage »
 * (.display-*).
 */
$displayIcon = static function (string $paths): string {
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
};
$themeIcons = [
    ''       => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>',
    'light'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
    'dark'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
    'system' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
];
// Vignette de « Par défaut » : celle du thème de l'organisation.
$mockOf = static fn(string $slug): string => $slug === '' ? $instanceTheme : $slug;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Affichage —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Affichage'); $page = 'display'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<div class="admin-content ai-prefs-page">
    <div class="ai-prefs-wrap">

        <div class="ai-prefs-head">
            <div class="ai-prefs-head-text">
                <h1><?= te('Affichage') ?></h1>
                <p class="ai-prefs-lead">
                    <?= te('Le thème, la taille et le contraste de l\'interface, et la touche qui envoie vos messages. Ces réglages sont personnels : ils suivent votre compte sur tous vos appareils, sans rien changer pour les autres.') ?>
                </p>
            </div>
            <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom:20px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="margin-bottom:20px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/settings/display" id="displayPrefsForm" data-autosave
              data-instance-theme="<?= htmlspecialchars($instanceTheme) ?>">
            <?= Csrf::field() ?>

            <!-- 1. Thème -->
            <section class="ai-prefs-section" id="theme" aria-labelledby="displayThemeTitle">
                <div class="card ai-prefs-card">
                    <div class="card-head">
                        <span class="card-ic" aria-hidden="true"><?= $displayIcon('<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18Z" fill="currentColor"/>') ?></span>
                        <div class="card-head-text">
                            <h3 id="displayThemeTitle"><?= te('Thème') ?></h3>
                            <p><?= te('Clair ou sombre, ou celui de votre appareil, qui peut basculer de lui-même le soir.') ?></p>
                        </div>
                    </div>
                    <div class="brand-theme-options display-theme-options" role="radiogroup" aria-labelledby="displayThemeTitle">
                        <?php foreach ($themes as $slug => $info): ?>
                            <label class="brand-theme-option display-theme-option">
                                <input type="radio" name="ui_theme" value="<?= htmlspecialchars($slug) ?>" <?= $currentTheme === $slug ? 'checked' : '' ?>>
                                <span class="brand-theme-mock" data-mock="<?= htmlspecialchars($mockOf($slug)) ?>" aria-hidden="true">
                                    <span class="mk-side"></span>
                                    <span class="mk-body"><span class="mk-line"></span><span class="mk-line short"></span><span class="mk-btn"></span></span>
                                </span>
                                <span class="brand-theme-name"><?= $themeIcons[$slug] ?><?= htmlspecialchars($info['label']) ?></span>
                                <span class="display-option-hint"><?= htmlspecialchars($info['hint']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card ai-prefs-card" id="contraste">
                    <div class="card-head">
                        <span class="card-ic" aria-hidden="true"><?= $displayIcon('<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/><path d="M12 8h5"/><path d="M12 12h6"/><path d="M12 16h5"/>') ?></span>
                        <div class="card-head-text">
                            <h3 id="displayContrastTitle"><?= te('Contraste') ?></h3>
                            <p><?= te('Pour lire plus confortablement : textes et bordures plus marqués, dans le thème choisi.') ?></p>
                        </div>
                    </div>
                    <div class="ai-choice-grid display-choice-grid" role="radiogroup" aria-labelledby="displayContrastTitle">
                        <?php foreach ($contrasts as $value => $info): ?>
                            <label class="ai-choice">
                                <input type="radio" name="ui_contrast" value="<?= (int)$value ?>" <?= (int)$currentContrast === (int)$value ? 'checked' : '' ?>>
                                <span class="ai-choice-card">
                                    <span class="ai-choice-label"><?= htmlspecialchars($info['label']) ?></span>
                                    <span class="ai-choice-hint"><?= htmlspecialchars($info['hint']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- 2. Taille d'affichage : curseur à gauche, aperçu à droite -->
            <section class="ai-prefs-section" id="taille" aria-labelledby="displayScaleTitle">
                <div class="card ai-prefs-card">
                    <div class="card-head">
                        <span class="card-ic" aria-hidden="true"><?= $displayIcon('<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>') ?></span>
                        <div class="card-head-text">
                            <h3 id="displayScaleTitle"><?= te('Taille d\'affichage') ?></h3>
                            <p><?= te('Agrandit ou réduit toute l\'interface d\'un même facteur : texte, boutons, icônes et images.') ?></p>
                        </div>
                    </div>
                    <?php
                        $scaleMin  = DisplayPreferences::MIN_SCALE;
                        $scaleMax  = DisplayPreferences::MAX_SCALE;
                        // Position d'une valeur sur la course du curseur, en % (repères, partie remplie).
                        $scaleAt   = static fn(int $v): string => rtrim(rtrim(number_format(($v - $scaleMin) * 100 / ($scaleMax - $scaleMin), 3, '.', ''), '0'), '.') . '%';
                    ?>
                    <div class="display-scale-layout">
                        <div class="display-scale-control">
                            <div class="display-scale-head">
                                <label for="displayScale"><?= te('Taille') ?></label>
                                <output class="display-scale-value" id="displayScaleValue" for="displayScale"><?= htmlspecialchars($scaleLabels[$currentScale] ?? '') ?></output>
                            </div>
                            <input type="range"
                                   id="displayScale"
                                   name="ui_scale"
                                   class="display-scale-range"
                                   min="<?= $scaleMin ?>"
                                   max="<?= $scaleMax ?>"
                                   step="<?= DisplayPreferences::SCALE_STEP ?>"
                                   value="<?= (int)$currentScale ?>"
                                   style="--fill: <?= $scaleAt((int)$currentScale) ?>"
                                   aria-valuetext="<?= htmlspecialchars($scaleLabels[$currentScale] ?? '') ?>"
                                   data-labels="<?= htmlspecialchars(json_encode($scaleLabels, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                            <div class="display-scale-ticks">
                                <?php foreach (DisplayPreferences::SCALE_TICKS as $tick): ?>
                                    <button type="button" class="display-scale-tick<?= $tick === (int)$currentScale ? ' is-current' : '' ?>"
                                            data-scale="<?= $tick ?>" style="--at: <?= $scaleAt($tick) ?>"
                                            aria-label="<?= te('Taille {valeur}', ['valeur' => $scaleLabels[$tick]]) ?>"><?= htmlspecialchars($scaleLabels[$tick]) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <div class="display-scale-foot">
                                <button type="button" class="display-scale-reset" id="displayScaleReset"<?= (int)$currentScale === DisplayPreferences::DEFAULT_SCALE ? ' hidden' : '' ?>><?= te('Rétablir 100 %') ?></button>
                                <?php // Plafond lu dans la feuille de style (--ui-zoom-max) : texte complété par le script. ?>
                                <p class="display-scale-cap" id="displayScaleCap" data-template="<?= te('Sur cet écran, la taille est limitée à {max} pour que tout reste utilisable.') ?>" hidden></p>
                            </div>
                            <p class="ai-field-hint display-scale-note"><?= te('L\'aperçu suit le curseur ; la page entière s\'ajuste quand vous le relâchez. Les raccourcis de votre navigateur (Ctrl et +) restent possibles en plus, pour cet appareil seulement.') ?></p>
                        </div>

                        <?php // Aperçu décoratif (aria-hidden) : la valeur, elle, est annoncée par le curseur. ?>
                        <div class="display-preview" aria-hidden="true">
                            <div class="display-preview-label"><?= te('Aperçu') ?></div>
                            <div class="display-preview-stage">
                                <div class="display-preview-content" id="displayPreview">
                                    <div class="pv-msg">
                                        <img class="pv-avatar" src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>" alt="">
                                        <p class="pv-text"><?= teb('Voici le point de la semaine : {b}trois réunions{/b} et un contrat à relire avant jeudi.') ?></p>
                                    </div>
                                    <div class="pv-user"><?= te('Merci ! Préviens l\'équipe.') ?></div>
                                    <div class="pv-composer">
                                        <span class="pv-composer-text"><?= te('Envoyer un message…') ?></span>
                                        <span class="pv-send"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. Saisie : touche d'envoi des messages du chat -->
            <section class="ai-prefs-section" id="saisie" aria-labelledby="displaySendTitle">
                <div class="card ai-prefs-card">
                    <div class="card-head">
                        <span class="card-ic" aria-hidden="true"><?= $displayIcon('<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/>') ?></span>
                        <div class="card-head-text">
                            <h3 id="displaySendTitle"><?= te('Envoi des messages') ?></h3>
                            <p><?= te('La touche qui envoie votre message dans le chat. Pour rédiger de longs messages sans les envoyer trop tôt, choisissez Ctrl + Entrée.') ?></p>
                        </div>
                    </div>
                    <div class="ai-choice-grid display-choice-grid" role="radiogroup" aria-labelledby="displaySendTitle">
                        <?php foreach ($sendKeys as $slug => $info): ?>
                            <label class="ai-choice">
                                <input type="radio" name="ui_send_key" value="<?= htmlspecialchars($slug) ?>" <?= $currentSendKey === $slug ? 'checked' : '' ?>>
                                <span class="ai-choice-card">
                                    <?php // Les touches dessinées, depuis le libellé traduit (« Ctrl + Entrée »). ?>
                                    <span class="ai-choice-label display-keys"><?php foreach (explode(' + ', $info['label']) as $i => $touche): ?><?= $i > 0 ? '<span aria-hidden="true">+</span>' : '' ?><kbd><?= htmlspecialchars($touche) ?></kbd><?php endforeach; ?></span>
                                    <span class="ai-choice-hint"><?= htmlspecialchars($info['hint']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </form>

    </div><!-- /ai-prefs-wrap -->
</div><!-- /admin-content -->

<script>
// Le choix s'applique à la page dès le clic (taille : au relâchement du
// curseur). Mêmes attributs que DisplayPreferences::htmlAttributesFor() au
// chargement suivant — un écart se verrait comme un thème qui « saute » en
// changeant de page.
(function () {
    const form = document.getElementById('displayPrefsForm');
    if (!form) return;
    const root = document.documentElement;
    const instance = form.dataset.instanceTheme === 'light' ? 'light' : 'dark';

    function apply() {
        const theme = form.querySelector('input[name="ui_theme"]:checked')?.value ?? '';
        const scale = String(form.elements.ui_scale?.value ?? '100');
        const contrast = form.querySelector('input[name="ui_contrast"]:checked')?.value === '1';

        if (theme === '') root.removeAttribute('data-theme-pref');
        else root.setAttribute('data-theme-pref', theme);
        // « Automatique » : résolu par le script de head-meta.php, qui suit
        // ensuite l'appareil en direct.
        const resolved = (theme === 'light' || theme === 'dark') ? theme : instance;
        if (root.getAttribute('data-theme') !== resolved) {
            root.setAttribute('data-theme', resolved);
            const meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', resolved === 'light' ? '#f5f5f5' : '#212121');
            document.dispatchEvent(new CustomEvent('mia:themechange', { detail: { theme: resolved } }));
        }
        if (theme === 'system' && typeof window.miaApplyTheme === 'function') window.miaApplyTheme();

        if (scale === '100') {
            root.removeAttribute('data-ui-scale');
            root.style.removeProperty('--ui-scale');
        } else {
            root.setAttribute('data-ui-scale', scale);
            root.style.setProperty('--ui-scale', String(parseInt(scale, 10) / 100));
        }

        if (contrast) root.setAttribute('data-contrast', 'high');
        else root.removeAttribute('data-contrast');
    }

    // ---- Taille : curseur, repères, aperçu ----
    // Pendant le glissement (`input`), SEUL l'aperçu change : zoomer la page
    // en direct déplacerait le curseur sous le doigt. La page suit au
    // relâchement (`change`, ci-dessous) — l'enregistrement automatique, lui,
    // attend déjà la fin du geste.
    const range   = document.getElementById('displayScale');
    const output  = document.getElementById('displayScaleValue');
    const preview = document.getElementById('displayPreview');
    const reset   = document.getElementById('displayScaleReset');
    const cap     = document.getElementById('displayScaleCap');
    const ticks   = Array.from(form.querySelectorAll('.display-scale-tick'));
    let labels = {};
    try { labels = JSON.parse(range?.dataset.labels || '{}'); } catch (_) {}
    const zoomOk = !!(window.CSS && CSS.supports && CSS.supports('zoom', '1'));

    // Plafond selon la largeur de l'écran : la feuille de style en est la
    // seule source (--ui-zoom-max, bloc « Taille d'affichage » de main.css).
    function zoomMax() {
        const v = parseFloat(getComputedStyle(root).getPropertyValue('--ui-zoom-max'));
        return v > 0 ? v : 1.5;
    }

    function syncScale() {
        if (!range) return;
        const v = parseInt(range.value, 10);
        const min = parseInt(range.min, 10), max = parseInt(range.max, 10);
        const label = labels[v] || (v + ' %');
        output.textContent = label;
        range.setAttribute('aria-valuetext', label);
        range.style.setProperty('--fill', ((v - min) * 100 / (max - min)) + '%');
        ticks.forEach(tick => tick.classList.toggle('is-current', parseInt(tick.dataset.scale, 10) === v));
        if (reset) reset.hidden = v === 100;

        // L'aperçu montre la taille qui sera APPLIQUÉE (plafond compris),
        // rapportée au zoom actuel de la page, qui le porte déjà.
        const chosen = v / 100;
        const limit = zoomOk ? zoomMax() : 1;
        const effective = zoomOk ? Math.min(chosen, limit) : 1;
        const pageZoom = window.miaUiZoom ? window.miaUiZoom() : 1;
        if (preview) preview.style.zoom = String(effective / pageZoom);

        const capped = zoomOk && chosen > limit + 1e-9;
        if (cap) {
            cap.hidden = !capped;
            if (capped) {
                const maxPct = Math.round(limit * 100 / 5) * 5;
                cap.textContent = (cap.dataset.template || '').replace('{max}', labels[maxPct] || (maxPct + ' %'));
            }
        }
    }

    function setScale(v) {
        if (!range) return;
        range.value = String(v);
        // Comme un geste : l'aperçu (`input`) puis la page et l'enregistrement
        // (`change`, que l'enregistrement automatique écoute).
        range.dispatchEvent(new Event('input', { bubbles: true }));
        range.dispatchEvent(new Event('change', { bubbles: true }));
    }

    range?.addEventListener('input', syncScale);
    ticks.forEach(tick => tick.addEventListener('click', () => setScale(tick.dataset.scale)));
    reset?.addEventListener('click', () => { setScale(100); range.focus(); });
    // Le plafond dépend de la largeur de l'écran.
    window.addEventListener('resize', syncScale);
    syncScale();

    // La touche d'envoi n'a rien à montrer ici : elle vaut dans le chat.
    form.addEventListener('change', function (e) {
        if (!e.target || !['ui_theme', 'ui_scale', 'ui_contrast'].includes(e.target.name)) return;
        apply();
        if (e.target.name === 'ui_scale') syncScale();
    });
})();
</script>
</body>
</html>
