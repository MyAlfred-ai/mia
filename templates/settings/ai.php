<?php
/**
 * Réglages → Préférences IA.
 *
 * Trois sections, dans l'ordre où l'utilisateur se pose les questions :
 *   1. Comment MIA vous répond   — le style de réponse ;
 *   2. Ce que MIA sait de vous   — « Votre contexte » (texte libre) puis
 *                                  « MIA pour vous » (souvenirs, carte AJAX
 *                                  partagée avec Mon profil) ;
 *   3. À l'oral                  — la carte « Voix », seulement si la
 *                                  conversation vocale est proposée.
 *
 * UN SEUL formulaire auto-enregistré, et il est VIDE : chaque contrôle s'y
 * rattache par form="aiPrefsForm". C'est ce qui permet de poser la carte
 * « MIA pour vous » — qui s'enregistre seule, en AJAX, et dont les champs ne
 * sont rattachés à rien — sous « Votre contexte », au milieu de la page, sans
 * l'imbriquer dans le formulaire (un <form> dans un <form> est invalide, et
 * les deux chemins d'enregistrement se marcheraient dessus). autosave.js
 * délègue sur `document` et résout le formulaire par `field.form` : un champ
 * hors de son <form> s'enregistre exactement comme les autres.
 *
 * Styles : bloc « Préférences IA » de main.css (.ai-prefs-*, .ai-choice-*).
 */
$currentStyle = (string)($user['ai_style'] ?? '');
$currentCtx   = (string)($user['ai_user_context'] ?? '');
// Icônes des cartes (20 px, trait courant) — même famille que « Marque &
// apparence » et les connecteurs : un seul vocabulaire visuel.
$aiIcon = static function (string $paths): string {
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Préférences IA —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Préférences IA'); $page = 'ai'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<!-- Contenu -->
<div class="admin-content ai-prefs-page">
    <div class="ai-prefs-wrap">

        <div class="ai-prefs-head">
            <div class="ai-prefs-head-text">
                <h1><?= te('Préférences IA') ?></h1>
                <p class="ai-prefs-lead">
                    <?= te('Comment MIA vous répond, ce qu\'elle sait de vous et comment elle vous parle. Ces réglages sont personnels et valent pour tous les assistants et tous les modèles.') ?>
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

        <!-- Sommaire : trois ancres, utiles surtout sur mobile où la liste des
             souvenirs peut allonger la page. -->
        <nav class="ai-prefs-nav" aria-label="<?= te('Sections de la page') ?>">
            <a href="#style"><?= te('Style de réponse') ?></a>
            <a href="#contexte"><?= te('Ce que MIA sait de vous') ?></a>
            <?php if (!empty($voiceEnabled)): ?>
                <a href="#voix"><?= te('Voix') ?></a>
            <?php endif; ?>
        </nav>

        <!-- Le formulaire, vide : ses contrôles vivent dans les cartes ci-dessous
             et s'y rattachent par form="aiPrefsForm" (cf. en-tête du fichier). -->
        <form method="POST" action="/settings/ai" id="aiPrefsForm" data-autosave>
            <?= Csrf::field() ?>
        </form>

        <!-- 1. Comment MIA vous répond -->
        <section class="ai-prefs-section" id="style" aria-labelledby="aiStyleTitle">
            <div class="ai-prefs-section-head">
                <h2 id="aiStyleTitle"><?= te('Comment MIA vous répond') ?></h2>
                <p><?= te('Le ton par défaut de ses réponses écrites. Un seul style à la fois ; il s\'ajoute aux consignes de l\'assistant choisi.') ?></p>
            </div>
            <div class="card ai-prefs-card">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true"><?= $aiIcon('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>') ?></span>
                    <div class="card-head-text">
                        <h3 id="aiStyleCardTitle"><?= te('Style de réponse') ?></h3>
                        <p><?= te('« Par défaut » laisse l\'assistant décider ; un style choisi vaut pour toutes vos conversations.') ?></p>
                    </div>
                </div>
                <div class="ai-choice-grid" role="radiogroup" aria-labelledby="aiStyleCardTitle">
                    <label class="ai-choice ai-choice--wide">
                        <input type="radio" name="ai_style" value="" form="aiPrefsForm" <?= $currentStyle === '' ? 'checked' : '' ?>>
                        <span class="ai-choice-card">
                            <span class="ai-choice-label"><?= te('Par défaut') ?></span>
                            <span class="ai-choice-hint"><?= te('Aucune consigne de votre part : le style de l\'assistant s\'applique seul.') ?></span>
                        </span>
                    </label>
                    <?php foreach ($styles as $slug => $info): ?>
                        <label class="ai-choice">
                            <input type="radio" name="ai_style" value="<?= htmlspecialchars($slug) ?>" form="aiPrefsForm" <?= $currentStyle === $slug ? 'checked' : '' ?>>
                            <span class="ai-choice-card">
                                <span class="ai-choice-label"><?= htmlspecialchars($info['label']) ?></span>
                                <span class="ai-choice-hint"><?= htmlspecialchars($info['hint']) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- 2. Ce que MIA sait de vous -->
        <section class="ai-prefs-section" id="contexte" aria-labelledby="aiKnowTitle">
            <div class="ai-prefs-section-head">
                <h2 id="aiKnowTitle"><?= te('Ce que MIA sait de vous') ?></h2>
                <p><?= te('Deux sources, relues au début de chaque conversation : le contexte que vous écrivez ici, et les souvenirs que MIA garde à votre demande. « Que sais-tu de moi ? » vous les restitue tels quels.') ?></p>
            </div>
            <div class="card ai-prefs-card">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true"><?= $aiIcon('<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>') ?></span>
                    <div class="card-head-text">
                        <h3><?= te('Votre contexte') ?></h3>
                        <p><?= te('Votre métier, vos outils, votre cible : ce qui aide MIA à viser juste dès le premier message.') ?></p>
                    </div>
                </div>
                <div class="ai-field">
                    <div class="ai-field-head">
                        <label for="aiUserContext"><?= te('En une ou deux phrases') ?></label>
                        <span class="ctx-counter" id="aiCtxCounter">
                            <?php /* Le compteur vivant garde son id ; le reste est UN message,
                                     car « caractères » seul ne se traduit pas (l'ordre des mots
                                     change, et l'unité peut précéder le nombre). */ ?>
                            <span id="aiCtxCount">0</span> <?= te('/ {max} caractères', ['max' => (int)$maxLen]) ?>
                        </span>
                    </div>
                    <textarea
                        id="aiUserContext"
                        name="ai_user_context"
                        form="aiPrefsForm"
                        class="form-input"
                        rows="4"
                        maxlength="<?= (int)$maxLen ?>"
                        placeholder="<?= te('Ex. : Chef de produit chez ACME (SaaS RH). Outils : Notion, Linear, Looker. Cible : équipes RH 50-500 personnes.') ?>"><?= htmlspecialchars($currentCtx) ?></textarea>
                    <p class="ai-field-hint"><?= te('MIA s\'en sert en arrière-plan, sans le citer. Laissé vide, il ne change rien.') ?></p>
                </div>
            </div>

            <!-- « MIA pour vous » : carte AJAX autonome, partagée avec Mon profil. -->
            <?php require ROOT . '/templates/settings/partials/memory-card.php'; ?>
        </section>

        <?php if (!empty($voiceEnabled)): ?>
        <!-- 3. À l'oral — carte absente quand la conversation vocale n'est pas
             proposée (réglage d'administration ou clé du fournisseur) : un
             réglage de lecture n'a rien à régler quand personne ne peut
             l'entendre. Corollaire côté handler : les champs absents du POST
             ne sont pas écrits (cf. updateAiPreferences). -->
        <section class="ai-prefs-section" id="voix" aria-labelledby="aiVoiceTitle">
            <div class="ai-prefs-section-head">
                <h2 id="aiVoiceTitle"><?= te('À l\'oral') ?></h2>
                <p><?= te('Pour la conversation vocale, le bouton onde sonore du champ de saisie. Sans effet sur les réponses écrites.') ?></p>
            </div>
            <div class="card ai-prefs-card">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true"><?= $aiIcon('<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5.5a9 9 0 0 1 0 13"/>') ?></span>
                    <div class="card-head-text">
                        <h3><?= te('Voix') ?></h3>
                        <p><?= te('Ces deux réglages suivent votre compte, sur tous vos appareils.') ?></p>
                    </div>
                </div>

                <?php
                    // Bornes du curseur DÉRIVÉES des crans (VoicePreferences) :
                    // ajouter un cran ne demande pas de retoucher le gabarit.
                    $rateValues = array_column($voiceRates, 'value');
                    $rateLabels = array_column($voiceRates, 'label', 'value');
                    $rateMin    = (int)min($rateValues);
                    $rateMax    = (int)max($rateValues);
                    $rateStep   = count($rateValues) > 1 ? (int)($rateValues[1] - $rateValues[0]) : 25;
                ?>
                <div class="ai-field">
                    <div class="ai-field-head">
                        <label for="voiceRate"><?= te('Vitesse de lecture') ?></label>
                        <span class="voice-rate-value" id="voiceRateValue"><?= htmlspecialchars($rateLabels[(int)$voiceRate] ?? '') ?></span>
                    </div>
                    <input type="range"
                           id="voiceRate"
                           name="voice_rate"
                           form="aiPrefsForm"
                           class="voice-rate-range"
                           min="<?= $rateMin ?>"
                           max="<?= $rateMax ?>"
                           step="<?= $rateStep ?>"
                           value="<?= (int)$voiceRate ?>"
                           data-labels="<?= htmlspecialchars(json_encode($rateLabels, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                           aria-describedby="voiceRateValue">
                    <?php // Repères sous le curseur : les crans, tels que le serveur les libelle. ?>
                    <div class="voice-rate-ticks" aria-hidden="true">
                        <?php foreach ($voiceRates as $opt): ?>
                            <span><?= htmlspecialchars($opt['label']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="ai-field-hint"><?= te('Appliquée par votre navigateur à la voix de MIA et à ses annonces : rien n\'est re-synthétisé, rien ne coûte plus.') ?></p>
                </div>

                <div class="ai-field">
                    <div class="ai-field-head">
                        <span class="ai-field-label" id="voiceLengthTitle"><?= te('Longueur des réponses à l\'oral') ?></span>
                    </div>
                    <div class="ai-choice-grid ai-choice-grid--3" role="radiogroup" aria-labelledby="voiceLengthTitle">
                        <?php foreach ($voiceLengths as $slug => $info): ?>
                            <label class="ai-choice">
                                <input type="radio" name="voice_length" value="<?= htmlspecialchars($slug) ?>" form="aiPrefsForm" <?= $voiceLength === $slug ? 'checked' : '' ?>>
                                <span class="ai-choice-card">
                                    <span class="ai-choice-label"><?= htmlspecialchars($info['label']) ?></span>
                                    <span class="ai-choice-hint"><?= htmlspecialchars($info['hint']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="ai-field-hint"><?= te('Une réponse lue se subit, là où une réponse écrite se parcourt : MIA répond donc plus court à la voix. Réglable aussi en pleine conversation, depuis le bandeau du chat.') ?></p>
                </div>
            </div>
        </section>
        <?php endif; ?>

    </div><!-- /ai-prefs-wrap -->
</div><!-- /admin-content -->

<script>
// Compteur de caractères du contexte
(function () {
    const textarea = document.getElementById('aiUserContext');
    const counter  = document.getElementById('aiCtxCount');
    const wrapper  = document.getElementById('aiCtxCounter');
    if (!textarea || !counter || !wrapper) return;
    const max = parseInt(textarea.getAttribute('maxlength') || '500', 10);
    function update() {
        const len = [...textarea.value].length;
        counter.textContent = len;
        wrapper.classList.toggle('is-warn',  len >= max * 0.8 && len < max);
        wrapper.classList.toggle('is-error', len >= max);
    }
    textarea.addEventListener('input', update);
    update();
})();

// Vitesse de lecture : la valeur lue vient de la table posée par le serveur
// (data-labels), jamais d'un formatage refait ici — le séparateur décimal
// suit la langue de l'interface, et il ne peut pas diverger d'un écran à
// l'autre.
(function () {
    const range  = document.getElementById('voiceRate');
    const sortie = document.getElementById('voiceRateValue');
    if (!range || !sortie) return;
    let labels = {};
    try { labels = JSON.parse(range.dataset.labels || '{}'); } catch (_) {}
    function refresh() {
        const label = labels[range.value];
        if (label) sortie.textContent = label;
    }
    range.addEventListener('input', refresh);
    refresh();
})();
</script>
</body>
</html>
