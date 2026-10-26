<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars($settings['app_name'] ?? 'MIA') ?> — <?= te('Marque & apparence') ?></title>
    <link rel="icon" href="<?= htmlspecialchars($settings['logo_url'] ?? '/assets/img/logo.png') ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'settings'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
// Valeurs de rendu. Les défauts sont ceux des gabarits qui les affichent
// (chat, nav, login) : l'aperçu doit montrer ce que ces écrans montreront.
$appName     = (string)($settings['app_name'] ?? 'MIA');
$appTagline  = (string)($settings['app_tagline'] ?? 'Votre assistant IA multi-modèles');
$logoUrl     = (string)($settings['logo_url'] ?? '/assets/img/logo.png');
$hoverUrl    = (string)($settings['logo_hover_url'] ?? '');
$theme       = ($settings['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
$accent      = (string)($settings['accent_color'] ?? '#10a37f');
$presets     = ['#10a37f', '#3b82f6', '#a855f7', '#f97316', '#ef4444', '#eab308'];
// Langue par défaut de l'instance. Elle n'arbitre QUE les cas où personne n'a
// rien dit : un choix enregistré par l'utilisateur et la langue du navigateur
// passent avant (cf. I18n::resolve). D'où le libellé « à défaut ».
$langueDefaut = (string)($settings[I18n::SETTING_DEFAULT] ?? '');
// Les CHAMPS gardent la valeur enregistrée, défaut livré compris (en
// français) : écrire sa traduction ferait enregistrer un texte « réécrit »,
// que I18n::editorial() ne traduirait plus pour personne. Seul l'APERÇU
// montre ce que verra le lecteur.
$taglineVue  = I18n::editorial($appTagline, 'Votre assistant IA multi-modèles');
?>
<div class="admin-content brand-page">
    <div class="brand-page-head">
        <div>
            <h1><?= te('Marque & apparence') ?></h1>
            <p class="brand-lead">
                <?= str_replace(['{a}', '{/a}'], ['<a href="/admin/company">', '</a>'],
                    te("L'habillage de l'application : ce qu'on lit et ce qu'on voit — nom, logos, couleurs, liens de contact, page d'installation mobile. L'entreprise qui utilise MIA (nom, descriptif, vocabulaire, logo, modèles PowerPoint et Word) a sa page : {a}Entreprise{/a}.")) ?>
            </p>
        </div>
        <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
    </div>
    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="/admin/settings" enctype="multipart/form-data" data-autosave class="brand-form">
        <?= Csrf::field() ?>
        <div class="brand-grid">

            <!-- Identité -->
            <section class="card brand-card" aria-labelledby="brandIdentityTitle">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="brandIdentityTitle"><?= te('Identité') ?></h3>
                        <p><?= te('Le nom et les phrases qui accueillent vos utilisateurs.') ?></p>
                    </div>
                </div>
                <div class="brand-fields">
                    <div class="form-group">
                        <label for="appName"><?= te("Nom de l'application") ?></label>
                        <input type="text" name="app_name" id="appName" class="form-input"
                               value="<?= htmlspecialchars($appName) ?>" placeholder="MIA">
                        <p class="brand-hint"><?= te('Barre latérale, en-tête mobile, page de connexion, application installée.') ?></p>
                    </div>
                    <div class="form-group">
                        <label for="appTagline"><?= te('Punchline (sous le nom)') ?></label>
                        <input type="text" name="app_tagline" id="appTagline" class="form-input"
                               value="<?= htmlspecialchars($appTagline) ?>"
                               placeholder="<?= te("Ex : L'IA utile, enfin simple") ?>">
                        <p class="brand-hint"><?= te('Une ligne, sous le nom, dans la barre latérale et sur la page de connexion.') ?></p>
                    </div>
                    <div class="form-group">
                        <label for="welcomeMessage"><?= te("Message d'accueil") ?></label>
                        <input type="text" name="welcome_message" id="welcomeMessage" class="form-input"
                               value="<?= htmlspecialchars($settings['welcome_message'] ?? 'Comment puis-je vous aider ?') ?>">
                        <p class="brand-hint"><?= te("Écran d'accueil d'une nouvelle conversation, précédé du prénom.") ?></p>
                    </div>
                    <div class="form-group">
                        <label for="defaultLang"><?= te('Langue par défaut') ?></label>
                        <select name="<?= I18n::SETTING_DEFAULT ?>" id="defaultLang" class="form-input">
                            <option value=""<?= $langueDefaut === '' ? ' selected' : '' ?>><?= te('Langue du navigateur, sinon français') ?></option>
                            <?php foreach (I18n::LOCALES as $code => $infos): ?>
                                <option value="<?= htmlspecialchars($code) ?>"<?= $langueDefaut === $code ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($infos['drapeau'] . ' ' . $infos['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="brand-hint"><?= te("Sert aux visiteurs qui n'ont pas choisi de langue ; chacun garde la sienne via le sélecteur en haut à droite.") ?></p>
                    </div>
                </div>
                <p class="brand-note">
                    <?= te("Laissés tels qu'ils sont livrés, la punchline et le message d'accueil s'affichent traduits dans la langue de chacun. Réécrits, ils s'affichent tels quels, dans toutes les langues — c'est votre texte.") ?>
                </p>
            </section>

            <!-- Logos -->
            <section class="card brand-card" aria-labelledby="brandLogosTitle">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="brandLogosTitle"><?= te('Logos') ?></h3>
                        <p><?= te('Le principal partout ; le second le remplace au survol, dans la barre latérale.') ?></p>
                    </div>
                </div>
                <div class="brand-logo-tiles">
                    <div class="brand-logo-tile">
                        <div class="brand-logo-frame">
                            <img id="logoPreview" src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= te('Logo principal') ?>">
                        </div>
                        <div class="brand-logo-body">
                            <strong class="brand-logo-title"><?= te('Logo principal') ?></strong>
                            <input type="file" name="logo" id="logoInput" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml"
                                   hidden onchange="previewLogo(this, 'logoPreview')">
                            <div class="brand-logo-actions">
                                <button type="button" class="btn-secondary" onclick="document.getElementById('logoInput').click()">
                                    <?= te('Changer le logo') ?>
                                </button>
                            </div>
                            <p class="brand-hint"><?= te('PNG, JPG, SVG ou WebP — 256 × 256 px recommandé.') ?></p>
                        </div>
                    </div>
                    <div class="brand-logo-tile">
                        <div class="brand-logo-frame">
                            <img id="logoHoverPreview" src="<?= htmlspecialchars($hoverUrl) ?>" alt="<?= te('Logo au survol') ?>"<?= $hoverUrl === '' ? ' hidden' : '' ?>>
                            <span id="logoHoverEmpty" class="brand-logo-empty" aria-hidden="true"<?= $hoverUrl !== '' ? ' hidden' : '' ?>>+</span>
                        </div>
                        <div class="brand-logo-body">
                            <strong class="brand-logo-title"><?= te('Logo au survol') ?> <span class="brand-optional"><?= te('facultatif') ?></span></strong>
                            <input type="file" name="logo_hover" id="logoHoverInput" accept="image/png,image/jpeg,image/gif"
                                   hidden onchange="previewLogo(this, 'logoHoverPreview')">
                            <div class="brand-logo-actions">
                                <button type="button" class="btn-secondary" onclick="document.getElementById('logoHoverInput').click()">
                                    <?= $hoverUrl ? te('Changer le logo survol') : te('Ajouter le logo survol') ?>
                                </button>
                                <?php if ($hoverUrl): ?>
                                    <!-- Une affectation par script ne déclenche aucun
                                         évènement : sans le `change` explicite, la
                                         suppression ne partirait jamais. -->
                                    <button type="button" class="btn-secondary brand-btn-danger"
                                            onclick="const h=document.getElementById('removeLogoHover');h.value='1';h.dispatchEvent(new Event('change',{bubbles:true}));const i=document.getElementById('logoHoverPreview');i.hidden=true;i.removeAttribute('src');document.getElementById('logoHoverEmpty').hidden=false;this.hidden=true">
                                        <?= te('Supprimer') ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <!-- Drapeau à usage unique : remis à vide après
                                 l'enregistrement, sinon chaque frappe ultérieure
                                 redemanderait la suppression. -->
                            <input type="hidden" name="remove_logo_hover" id="removeLogoHover" value="" data-autosave-reset>
                            <p class="brand-hint"><?= te('PNG, JPG ou GIF — affiché au survol du logo dans la barre latérale.') ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Apparence -->
            <section class="card brand-card" aria-labelledby="brandLookTitle" id="brandLook" style="--pv-accent:<?= htmlspecialchars($accent) ?>">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="brandLookTitle"><?= te('Apparence') ?></h3>
                        <p><?= te("Thème et couleur d'accentuation, appliqués à toute l'interface — schémas compris.") ?></p>
                    </div>
                </div>
                <div class="brand-fields">
                    <div class="form-group">
                        <span class="brand-label"><?= te('Thème par défaut') ?></span>
                        <div class="brand-theme-options" role="radiogroup" aria-label="<?= te('Thème par défaut') ?>">
                            <?php foreach (['dark' => t('Sombre'), 'light' => t('Clair')] as $val => $label): ?>
                            <label class="brand-theme-option">
                                <input type="radio" name="theme" value="<?= $val ?>" <?= $theme === $val ? 'checked' : '' ?>>
                                <span class="brand-theme-mock" data-mock="<?= $val ?>" aria-hidden="true">
                                    <span class="mk-side"></span>
                                    <span class="mk-body"><span class="mk-line"></span><span class="mk-line short"></span><span class="mk-btn"></span></span>
                                </span>
                                <span class="brand-theme-name">
                                    <?php if ($val === 'dark'): ?>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                                    <?php else: ?>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($label) ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="brand-hint"><?= te("Celui de toute personne qui n'en a pas choisi d'autre : chacun peut imposer le sien, clair, sombre ou celui de son appareil, dans Réglages → Affichage.") ?></p>
                    </div>
                    <div class="form-group">
                        <label for="accentColor"><?= te("Couleur d'accentuation") ?></label>
                        <div class="brand-accent-row">
                            <input type="color" name="accent_color" id="accentColor" class="brand-color-input"
                                   value="<?= htmlspecialchars($accent) ?>" title="<?= te('Choisir une couleur') ?>">
                            <div class="brand-swatches" aria-label="<?= te('Couleurs proposées') ?>">
                                <?php foreach ($presets as $c): ?>
                                    <button type="button" class="brand-swatch<?= strtolower($accent) === $c ? ' is-current' : '' ?>"
                                            data-color="<?= $c ?>" style="background:<?= $c ?>" title="<?= $c ?>" aria-label="<?= te('Couleur {couleur}', ['couleur' => $c]) ?>"
                                            onclick="const a=document.getElementById('accentColor');a.value='<?= $c ?>';a.dispatchEvent(new Event('input',{bubbles:true}));a.dispatchEvent(new Event('change',{bubbles:true}))"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <p class="brand-hint"><?= te('Boutons, liens, interrupteurs, éléments actifs.') ?></p>
                    </div>
                </div>
                <div class="brand-preview-wrap">
                    <span class="brand-label"><?= te('Aperçu') ?></span>
                    <div class="brand-preview" id="brandPreview" data-theme="<?= $theme ?>" aria-hidden="true">
                        <div class="brand-preview-side">
                            <img id="brandPreviewLogo" src="<?= htmlspecialchars($logoUrl) ?>" alt="">
                            <div style="min-width:0">
                                <div class="brand-preview-name" id="brandPreviewName"><?= htmlspecialchars($appName) ?></div>
                                <div class="brand-preview-tagline" id="brandPreviewTagline"><?= htmlspecialchars($taglineVue) ?></div>
                            </div>
                        </div>
                        <div class="brand-preview-body">
                            <span class="brand-preview-btn"><?= te('Nouvelle conversation') ?></span>
                            <span class="brand-preview-link"><?= te('Un lien') ?></span>
                            <span class="brand-preview-switch"></span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Liens & contact -->
            <section class="card brand-card" aria-labelledby="brandLinksTitle">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="brandLinksTitle"><?= te('Liens & contact') ?></h3>
                        <p><?= te("Pied de page du chat et page À propos. Un champ vide masque simplement l'élément.") ?></p>
                    </div>
                </div>
                <div class="brand-fields">
                    <div class="form-group">
                        <label for="docsUrl"><?= te('Lien vers la documentation') ?></label>
                        <input type="url" name="docs_url" id="docsUrl" class="form-input"
                               value="<?= htmlspecialchars($settings['docs_url'] ?? '') ?>" placeholder="https://docs.example.test">
                        <p class="brand-hint"><?= te('Carte « Documentation » de la page À propos.') ?></p>
                    </div>
                    <div class="form-group">
                        <label for="feedbackUrl"><?= te('Lien « Déclarer une idée ou un bug »') ?></label>
                        <input type="url" name="feedback_url" id="feedbackUrl" class="form-input"
                               value="<?= htmlspecialchars($settings['feedback_url'] ?? '') ?>" placeholder="https://…">
                        <p class="brand-hint"><?= te('Pied de page du chat et page À propos.') ?></p>
                    </div>
                    <div class="form-group">
                        <label for="contactEmail"><?= te('Adresse de contact') ?></label>
                        <input type="email" name="contact_email" id="contactEmail" class="form-input"
                               value="<?= htmlspecialchars($settings['contact_email'] ?? '') ?>" placeholder="<?= te('contact@exemple.fr') ?>">
                        <p class="brand-hint"><?= te('Bloc « Contact » de la page À propos.') ?></p>
                    </div>
                    <div class="form-group">
                        <label for="vendorName"><?= te("Nom de l'éditeur") ?></label>
                        <input type="text" name="vendor_name" id="vendorName" class="form-input"
                               value="<?= htmlspecialchars($settings['vendor_name'] ?? '') ?>" placeholder="<?= te('Votre société') ?>">
                        <p class="brand-hint"><?= te('Signe la page À propos.') ?></p>
                    </div>
                    <div class="form-group">
                        <label for="vendorUrl"><?= te("Site web de l'éditeur") ?></label>
                        <input type="url" name="vendor_url" id="vendorUrl" class="form-input"
                               value="<?= htmlspecialchars($settings['vendor_url'] ?? '') ?>" placeholder="<?= te('https://exemple.fr') ?>">
                        <p class="brand-hint"><?= te("Avec l'adresse de contact, forme le bloc « Contact » ; les deux vides = bloc masqué.") ?></p>
                    </div>
                </div>
            </section>

            <!-- Application mobile -->
            <section class="card brand-card brand-card--wide" aria-labelledby="brandMobileTitle">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="brandMobileTitle"><?= te('Application mobile') ?></h3>
                        <p><?= str_replace('{lien}', '<a href="/app" target="_blank" rel="noopener">/app</a>', te("La page publique d'installation {lien} — iPhone, iPad et Android.")) ?></p>
                    </div>
                </div>
                <div class="brand-mobile">
                    <div class="form-group">
                        <label for="testflightUrl"><?= te('Lien public TestFlight (iPhone / iPad)') ?></label>
                        <input type="url" name="mobile_testflight_url" id="testflightUrl" class="form-input"
                               value="<?= htmlspecialchars($settings['mobile_testflight_url'] ?? '') ?>"
                               placeholder="https://testflight.apple.com/join/…">
                        <p class="brand-hint"><?= te("Vide = « Bientôt disponible », et l'installation PWA est proposée à la place.") ?></p>
                    </div>
                    <p class="brand-note">
                        <?= str_replace('{tag}', '<code>mobile-v*</code>', te("Affiché sur la page d'installation sous le bouton « Installer via TestFlight ». L'APK Android de la même page est publié automatiquement par la CI (tag {tag}) : rien à renseigner ici.")) ?>
                    </p>
                </div>
            </section>
        </div>

        <p class="brand-footnote">
            <?= str_replace(
                ['{a1}', '{/a1}', '{a2}', '{/a2}'],
                ['<a href="/admin/company">', '</a>', '<a href="/admin/llms/models#defaut">', '</a>'],
                te("Le nom, le descriptif, le vocabulaire, le logo et les modèles PowerPoint et Word de l'entreprise se règlent dans {a1}Organisation → Entreprise{/a1} ; le LLM par défaut et le LLM de secours dans {a2}Modèles IA → Modèles{/a2}.")
            ) ?>
        </p>
    </form>
</div>

<script>
function previewLogo(input, previewId) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById(previewId);
        img.src = e.target.result;
        img.hidden = false;
        if (previewId === 'logoHoverPreview') {
            const empty = document.getElementById('logoHoverEmpty');
            if (empty) empty.hidden = true;
        } else {
            const pv = document.getElementById('brandPreviewLogo');
            if (pv) pv.src = e.target.result;
        }
    };
    reader.readAsDataURL(file);
}

// ---- Aperçu vivant ----
// Nom, punchline, thème et couleur se reflètent dans la vignette AVANT
// l'enregistrement : on voit ce qu'on choisit, pas ce qu'on avait.
(function () {
    const byId = id => document.getElementById(id);
    const look = byId('brandLook'), preview = byId('brandPreview');
    if (!look || !preview) return;

    const miroir = (source, cible, defaut) => {
        const src = byId(source), dst = byId(cible);
        if (!src || !dst) return;
        src.addEventListener('input', () => { dst.textContent = src.value.trim() || defaut; });
    };
    miroir('appName', 'brandPreviewName', 'MIA');
    miroir('appTagline', 'brandPreviewTagline', '');

    document.querySelectorAll('input[name="theme"]').forEach(radio => {
        radio.addEventListener('change', () => { if (radio.checked) preview.dataset.theme = radio.value; });
    });

    const accent = byId('accentColor');
    const peindre = () => {
        look.style.setProperty('--pv-accent', accent.value);
        document.querySelectorAll('.brand-swatch').forEach(b => {
            b.classList.toggle('is-current', b.dataset.color.toLowerCase() === accent.value.toLowerCase());
        });
    };
    if (accent) { accent.addEventListener('input', peindre); peindre(); }
})();

// ---- Après un enregistrement automatique ----
// La page ne se rechargeant plus, thème, couleur d'accentuation et logos
// resteraient sur leur rendu serveur d'origine : on applique le résultat
// immédiatement, sinon l'écran contredit ce que la pastille vient d'annoncer.
document.addEventListener('mia:autosaved', function (e) {
    const data = e.detail?.data || {};

    // Le thème de l'instance n'habille cette page que si l'administrateur n'en
    // a pas choisi un pour lui-même (Réglages → Affichage → data-theme-pref) :
    // sinon l'écran contredirait son propre réglage.
    const theme = document.querySelector('input[name="theme"]:checked');
    if (theme && !document.documentElement.hasAttribute('data-theme-pref')) {
        document.documentElement.setAttribute('data-theme', theme.value);
    }

    // En contraste renforcé, --accent se DÉRIVE de --accent-base (main.css) :
    // poser --accent en style en ligne court-circuiterait cette dérivation.
    const accent = document.getElementById('accentColor');
    if (accent) {
        document.documentElement.style.setProperty('--accent-base', accent.value);
        if (!document.documentElement.hasAttribute('data-contrast')) {
            document.documentElement.style.setProperty('--accent', accent.value);
        }
    }

    // Les URL portent un cache-buster recalculé à chaque téléversement : c'est
    // la valeur renvoyée par le serveur qui fait foi, pas l'aperçu local.
    if (data.logo_url) {
        document.querySelectorAll('.admin-sidebar-logo img, .mobile-header-logo, .sidebar-logo').forEach(img => {
            img.src = data.logo_url;
        });
        ['logoPreview', 'brandPreviewLogo'].forEach(id => {
            const img = document.getElementById(id);
            if (img) { img.src = data.logo_url; }
        });
    }
    const hoverPreview = document.getElementById('logoHoverPreview');
    if (hoverPreview && data.logo_hover_url !== undefined) {
        const empty = document.getElementById('logoHoverEmpty');
        if (data.logo_hover_url) {
            hoverPreview.src = data.logo_hover_url;
            hoverPreview.hidden = false;
            if (empty) empty.hidden = true;
        } else {
            hoverPreview.removeAttribute('src');
            hoverPreview.hidden = true;
            if (empty) empty.hidden = false;
        }
    }
});
</script>
</body>
</html>
