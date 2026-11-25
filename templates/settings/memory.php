<?php
/**
 * Réglages → Mémoire : « Ce que MIA sait de vous ».
 *
 * Tout ce que MIA relit au début de chaque conversation, au même endroit :
 *   - « À confirmer » : ce que MIA a déduit, jamais utilisé avant un « oui » ;
 *   - « Votre contexte » : le texte libre de la personne (formulaire
 *     auto-enregistré, contrôles rattachés par form="memoryPrefsForm") ;
 *   - « Vos souvenirs », par thème : édition en place, origine, date,
 *     conversation d'origine, retrait ;
 *   - « Ce que MIA apprend » : accepter ou refuser l'apprentissage
 *     automatique (MemoryLearner) ;
 *   - la place occupée dans le prompt et « Tout effacer ».
 *
 * Les souvenirs sont rendus par public/assets/js/memory.js depuis la charge
 * utile JSON ci-dessous (MemoryController::payload) : chaque geste renvoie la
 * liste à jour, la page se re-rend depuis la source de vérité. Un <noscript>
 * garde le contenu lisible sans JavaScript.
 *
 * Variables : $user, $payload, $context, $maxLen, $enabled, $success, $error,
 * $questionsOffered, $questionFreq, $frequencies, $frequencyHints,
 * $learnOffered, $learnOn.
 * Styles : bloc « Préférences IA » (.ai-prefs-*) et bloc « Mémoire » (.mem-*)
 * de main.css.
 */
$memIcon = static function (string $paths): string {
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
};
$themes = UserMemory::themes();
$hints  = UserMemory::themeHints();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Mémoire —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Mémoire'); $page = 'memory'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<div class="admin-content ai-prefs-page mem-page">
    <?php require ROOT . '/templates/partials/admin-topbar.php'; ?>
    <div class="ai-prefs-wrap">

        <div class="ai-prefs-head page-head">
            <div class="ai-prefs-head-text page-head-text">
                <h1><?= te('Ce que MIA sait de vous') ?></h1>
                <p class="ai-prefs-lead page-head-sub">
                    <?= te('MIA relit ces informations au début de chaque conversation, quel que soit l\'assistant, pour vous répondre comme vous l\'aimez. Tout est ici, et tout reste modifiable.') ?>
                </p>
            </div>
            <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="margin-bottom:20px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!$enabled): ?>
            <div class="alert mem-alert-off" role="status">
                <?= te('La mémoire est désactivée par l\'administrateur : MIA ne s\'en sert plus et ne retient rien de nouveau. Vous pouvez encore relire et retirer vos souvenirs.') ?>
            </div>
        <?php endif; ?>

        <!-- Comment ça marche : l'utilité d'abord, en trois gestes. -->
        <section class="mem-how" aria-label="<?= te('Comment fonctionne la mémoire') ?>">
            <div class="mem-how-item">
                <span class="mem-how-ic"><?= $memIcon('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>') ?></span>
                <div>
                    <h2><?= te('Vous le dites') ?></h2>
                    <p><?= teb('Dans n\'importe quelle conversation : {b}« Souviens-toi que… »{/b}, {b}« Corrige ta mémoire : … »{/b} ou {b}« Oublie que… »{/b}.') ?></p>
                </div>
            </div>
            <?php if ($learnOffered): ?>
            <div class="mem-how-item">
                <span class="mem-how-ic"><?= $memIcon('<path d="M12 2a7 7 0 0 0-4 12.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26A7 7 0 0 0 12 2z"/><line x1="9" y1="21" x2="15" y2="21"/>') ?></span>
                <div>
                    <h2><?= te('MIA l\'apprend') ?></h2>
                    <p><?= te('Ce que vous dites de vous en conversation, MIA le retient d\'elle-même. Ce qu\'elle déduit attend votre accord, dans « À confirmer ».') ?></p>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($questionsOffered): ?>
            <div class="mem-how-item">
                <span class="mem-how-ic"><?= $memIcon('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>') ?></span>
                <div>
                    <h2><?= te('MIA vous demande') ?></h2>
                    <p><?= te('De temps en temps, sous une réponse, une courte question sur ce que vous préférez. Un clic suffit, et votre réponse rejoint votre mémoire.') ?></p>
                </div>
            </div>
            <?php endif; ?>
            <div class="mem-how-item">
                <span class="mem-how-ic"><?= $memIcon('<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>') ?></span>
                <div>
                    <h2><?= te('Vous décidez') ?></h2>
                    <p><?= te('Corrigez, rangez ou retirez chaque souvenir ici. « Que sais-tu de moi ? » vous les montre aussi, en conversation.') ?></p>
                </div>
            </div>
        </section>

        <nav class="ai-prefs-nav" aria-label="<?= te('Sections de la page') ?>">
            <a href="#confirmer" id="memNavConfirm" hidden><?= te('À confirmer') ?></a>
            <a href="#contexte"><?= te('Votre contexte') ?><?= UserPreferences::contextDot($user) ?></a>
            <a href="#souvenirs"><?= te('Vos souvenirs') ?></a>
            <?php if ($learnOffered): ?>
                <a href="#apprentissage"><?= te('Ce que MIA apprend') ?></a>
            <?php endif; ?>
            <?php if ($questionsOffered): ?>
                <a href="#questions"><?= te('Questions de MIA') ?></a>
            <?php endif; ?>
            <a href="#place"><?= te('Place et effacement') ?></a>
        </nav>

        <!-- Le formulaire, vide : ses contrôles s'y rattachent par
             form="memoryPrefsForm" (même patron que Préférences IA). -->
        <form method="POST" action="/settings/memory/prefs" id="memoryPrefsForm" data-autosave>
            <?= Csrf::field() ?>
        </form>

        <!-- À confirmer : rendu par memory.js, caché s'il n'y a rien. -->
        <section class="ai-prefs-section mem-confirm" id="confirmer" aria-labelledby="memConfirmTitle" hidden>
            <div class="ai-prefs-section-head">
                <h2 id="memConfirmTitle"><?= te('À confirmer') ?></h2>
                <p><?= te('Ce que MIA a cru comprendre. Rien de cela ne sert tant que vous ne l\'avez pas confirmé.') ?></p>
            </div>
            <div class="card ai-prefs-card">
                <div class="mem-list" id="memConfirmList"></div>
            </div>
        </section>

        <!-- Votre contexte — déplacé depuis Préférences IA. -->
        <section class="ai-prefs-section" id="contexte" aria-labelledby="memCtxTitle">
            <div class="card ai-prefs-card">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true"><?= $memIcon('<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>') ?></span>
                    <div class="card-head-text">
                        <h3 id="memCtxTitle"><?= te('Votre contexte') ?><?= UserPreferences::contextDot($user, false, true, 'is-anchor') ?></h3>
                        <p><?= te('Votre présentation, écrite par vous : métier, outils, cible. MIA s\'en sert en arrière-plan et ne la modifie jamais.') ?></p>
                    </div>
                </div>
                <div class="ai-field">
                    <div class="ai-field-head">
                        <label for="memUserContext"><?= te('En une ou deux phrases') ?></label>
                        <span class="ctx-counter" id="memCtxCounter">
                            <span id="memCtxCount">0</span> <?= te('/ {max} caractères', ['max' => (int)$maxLen]) ?>
                        </span>
                    </div>
                    <textarea
                        id="memUserContext"
                        name="ai_user_context"
                        form="memoryPrefsForm"
                        class="form-input"
                        rows="4"
                        maxlength="<?= (int)$maxLen ?>"
                        placeholder="<?= te('Ex. : Chef de produit chez ACME (SaaS RH). Outils : Notion, Linear, Looker. Cible : équipes RH 50-500 personnes.') ?>"><?= htmlspecialchars($context) ?></textarea>
                </div>
            </div>
        </section>

        <!-- Vos souvenirs, par thème : rendus par memory.js. -->
        <section class="ai-prefs-section" id="souvenirs" aria-labelledby="memListTitle">
            <div class="ai-prefs-section-head mem-section-head">
                <div>
                    <h2 id="memListTitle"><?= te('Vos souvenirs') ?></h2>
                    <p><?= te('Rangés par thème. Touchez un souvenir pour le corriger ; la corbeille le retire.') ?></p>
                </div>
                <span class="mem-count" id="memCount"></span>
            </div>

            <div class="mem-themes" id="memThemes">
                <?php foreach ($themes as $key => $label): ?>
                    <div class="card ai-prefs-card mem-theme" data-theme="<?= htmlspecialchars($key) ?>" id="theme-<?= htmlspecialchars($key) ?>" hidden>
                        <div class="mem-theme-head">
                            <div>
                                <h3><?= htmlspecialchars($label) ?></h3>
                                <p><?= htmlspecialchars($hints[$key] ?? '') ?></p>
                            </div>
                            <span class="mem-theme-count" data-role="count"></span>
                        </div>
                        <div class="mem-list" data-role="list"></div>
                    </div>
                <?php endforeach; ?>
                <div class="mem-empty" id="memEmpty" hidden>
                    <?= te('Aucun souvenir pour l\'instant. Dites à MIA « Souviens-toi que… » pendant une conversation, ou ajoutez-en un ci-dessous.') ?>
                </div>
            </div>

            <div class="card ai-prefs-card mem-add-card">
                <label for="memAddInput" class="ai-field-label"><?= te('Ajouter un souvenir') ?></label>
                <div class="mem-add">
                    <select id="memAddTheme" class="form-select" aria-label="<?= te('Thème') ?>">
                        <?php foreach ($themes as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $key === UserMemory::THEME_DEFAULT ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="memAddInput" class="form-input"
                           maxlength="<?= (int)UserMemory::MAX_LEN ?>"
                           placeholder="<?= te('Ex. : Travaille au département marketing') ?>">
                    <button type="button" class="btn-primary" id="memAddBtn"><?= te('Ajouter') ?></button>
                </div>
                <div class="mem-feedback" id="memFeedback" role="status" hidden></div>
            </div>

            <noscript>
                <div class="card ai-prefs-card">
                    <?php $shown = 0; foreach ($themes as $key => $label): ?>
                        <?php $inTheme = array_filter($payload['memories'], static fn(array $m): bool => $m['theme'] === $key && $m['status'] === 'active'); ?>
                        <?php if ($inTheme === []) { continue; } $shown++; ?>
                        <h3 style="font-size:14px;margin:8px 0 4px"><?= htmlspecialchars($label) ?></h3>
                        <ul style="margin:0 0 8px;font-size:13px;line-height:1.6">
                            <?php foreach ($inTheme as $m): ?><li><?= htmlspecialchars($m['content']) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                    <?php if ($shown === 0): ?><p style="color:var(--text-muted)"><?= te('Aucun souvenir enregistré.') ?></p><?php endif; ?>
                    <p style="font-size:12px;color:var(--text-muted)"><?= te('L\'édition de cette section nécessite JavaScript.') ?></p>
                </div>
            </noscript>
        </section>

        <?php if ($learnOffered): ?>
        <!-- Ce que MIA apprend : l'apprentissage automatique, accepté ou refusé.
             Deux boutons radio, toujours postés ensemble : rien n'est écrit
             quand la section n'est pas montrée. -->
        <section class="ai-prefs-section" id="apprentissage" aria-labelledby="memLearnTitle">
            <div class="ai-prefs-section-head">
                <h2 id="memLearnTitle"><?= te('Ce que MIA apprend') ?></h2>
                <p><?= te('Quelques minutes après une conversation, MIA relit vos derniers messages et retient ce que vous y avez dit de vous : votre métier, la forme de réponse que vous préférez, vos outils, vos projets du moment. Jamais de donnée sensible, rien sur d\'autres personnes ; les projets du moment s\'effacent d\'eux-mêmes après deux mois.') ?></p>
                <p><?= te('MIA remarque aussi vos gestes : une réponse que vous faites souvent raccourcir, un brouillon d\'email que vous passez au vouvoiement, le même bouton « Améliorer » choisi encore et encore. Quand un geste se répète, elle vous propose d\'en faire une préférence — rien ne change sans votre accord.') ?></p>
            </div>
            <div class="card ai-prefs-card">
                <div class="ai-choice-grid" role="radiogroup" aria-labelledby="memLearnTitle">
                    <label class="ai-choice">
                        <input type="radio" name="memory_learn" value="1" form="memoryPrefsForm" <?= $learnOn ? 'checked' : '' ?>>
                        <span class="ai-choice-card">
                            <span class="ai-choice-label"><?= te('Apprendre de mes conversations') ?></span>
                            <span class="ai-choice-hint"><?= te('MIA retient ce que vous dites de vous. Ce qu\'elle déduit attend votre accord.') ?></span>
                        </span>
                    </label>
                    <label class="ai-choice">
                        <input type="radio" name="memory_learn" value="0" form="memoryPrefsForm" <?= $learnOn ? '' : 'checked' ?>>
                        <span class="ai-choice-card">
                            <span class="ai-choice-label"><?= te('Ne pas apprendre') ?></span>
                            <span class="ai-choice-hint"><?= te('MIA ne retient que ce que vous lui demandez de retenir.') ?></span>
                        </span>
                    </label>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($questionsOffered): ?>
        <!-- Questions de MIA : leur rythme, réglé par la personne. -->
        <section class="ai-prefs-section" id="questions" aria-labelledby="memQuestionsTitle">
            <div class="ai-prefs-section-head">
                <h2 id="memQuestionsTitle"><?= te('Questions de MIA') ?></h2>
                <p><?= te('Pour mieux vous connaître, MIA pose parfois une courte question sous une réponse : la forme que vous préférez, votre façon de travailler, vos outils. Elle n\'en pose jamais deux dans une même conversation.') ?></p>
            </div>
            <div class="card ai-prefs-card">
                <div class="ai-choice-grid" role="radiogroup" aria-labelledby="memQuestionsTitle">
                    <?php foreach ($frequencies as $value => $label): ?>
                        <label class="ai-choice">
                            <input type="radio" name="memory_questions" value="<?= htmlspecialchars($value) ?>" form="memoryPrefsForm" <?= $questionFreq === $value ? 'checked' : '' ?>>
                            <span class="ai-choice-card">
                                <span class="ai-choice-label"><?= htmlspecialchars($label) ?></span>
                                <span class="ai-choice-hint"><?= htmlspecialchars($frequencyHints[$value] ?? '') ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Place occupée dans le prompt, et « Tout effacer ». -->
        <section class="ai-prefs-section" id="place" aria-labelledby="memPlaceTitle">
            <div class="ai-prefs-section-head">
                <h2 id="memPlaceTitle"><?= te('Place et effacement') ?></h2>
                <p><?= te('MIA relit vos souvenirs à chaque message : leur place est bornée, pour rester rapide et économe.') ?></p>
            </div>
            <div class="card ai-prefs-card">
                <div class="mem-gauge">
                    <div class="mem-gauge-bar" aria-hidden="true"><span id="memGaugeFill"></span></div>
                    <p class="ai-field-hint" id="memGaugeText"></p>
                </div>
                <div class="mem-danger">
                    <div>
                        <h3><?= te('Tout effacer') ?></h3>
                        <p><?= te('Retire tous vos souvenirs, y compris ce que MIA a appris et ce qui attend votre confirmation. « Votre contexte » reste tel quel.') ?></p>
                    </div>
                    <button type="button" class="btn-danger mem-clear-btn" id="memClearBtn"><?= te('Tout effacer…') ?></button>
                </div>
            </div>
        </section>

    </div><!-- /ai-prefs-wrap -->
</div><!-- /admin-content -->

<script type="application/json" id="memoryBootstrap"><?= json_encode($payload + [
    'themes'  => $themes,
    'scopes'  => UserMemory::scopes(),
    'maxLen'  => UserMemory::MAX_LEN,
    'enabled' => $enabled,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/js/memory.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/memory.js') ?: 1) ?>"></script>
</body>
</html>
