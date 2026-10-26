<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <?php $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php $deckCssVersion = @filemtime(ROOT . '/public/assets/css/deck.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/deck.css?v=<?= (int)$deckCssVersion ?>">
    <?php $filesCssVersion = @filemtime(ROOT . '/public/assets/css/files.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/files.css?v=<?= (int)$filesCssVersion ?>">
    <?php $choicesCssVersion = @filemtime(ROOT . '/public/assets/css/choices.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/choices.css?v=<?= (int)$choicesCssVersion ?>">
    <?php if (Workflow::canUse(Session::currentUser())): ?>
    <?php $wfCssVersion = @filemtime(ROOT . '/public/assets/css/workflows.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/workflows.css?v=<?= (int)$wfCssVersion ?>">
    <?php endif; ?>
    <!-- Leaflet (outil Carte / Itinéraire) — vendorisé, chargé systématiquement :
         une conversation historique peut contenir une carte même si l'outil a été retiré -->
    <link rel="stylesheet" href="/assets/js/vendor/leaflet/leaflet.css">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="app">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <?php $appTagline = I18n::editorial(Settings::get('app_tagline', ''), 'Votre assistant IA multi-modèles'); ?>
    <div class="sidebar-header">
        <?php $hoverLogoUrl = Settings::get('logo_hover_url', ''); ?>
        <a href="/" class="sidebar-logo-link" title="<?= te('Accueil') ?>" aria-label="<?= te('Retour à l\'accueil') ?>">
            <div class="sidebar-logo-wrap<?= $hoverLogoUrl ? ' has-hover-logo' : '' ?>">
                <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>" alt="<?= te('logo') ?>" class="sidebar-logo">
                <?php if ($hoverLogoUrl): ?>
                    <img src="<?= htmlspecialchars($hoverLogoUrl) ?>" alt="" class="sidebar-logo-hover">
                <?php endif; ?>
            </div>
        </a>
        <div class="logo-brand">
            <span class="logo-text"><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></span>
            <span class="logo-tagline" title="<?= htmlspecialchars($appTagline) ?>"><?= htmlspecialchars($appTagline) ?></span>
        </div>
        <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn"
                title="<?= te('Masquer le volet') ?>" aria-label="<?= te('Masquer le volet') ?>"
                aria-controls="sidebar" aria-expanded="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" aria-hidden="true"><path d="M9.944 2.25c-1.838 0-3.294 0-4.433.153c-1.172.158-2.121.49-2.87 1.238c-.748.749-1.08 1.698-1.238 2.87c-.153 1.14-.153 2.595-.153 4.433v2.112c0 1.838 0 3.294.153 4.433c.158 1.172.49 2.121 1.238 2.87c.749.748 1.698 1.08 2.87 1.238c1.14.153 2.595.153 4.433.153h5.022l.072-.001c1.384-.004 2.523-.027 3.451-.152c1.172-.158 2.121-.49 2.87-1.238c.748-.749 1.08-1.698 1.238-2.87c.153-1.14.153-2.595.153-4.433v-2.112c0-1.838 0-3.294-.153-4.433c-.158-1.172-.49-2.121-1.238-2.87c-.749-.748-1.698-1.08-2.87-1.238c-.928-.125-2.067-.148-3.45-.152h-.073l-.91-.001zm4.306 1.5H10c-1.907 0-3.261.002-4.29.14c-1.005.135-1.585.389-2.008.812S3.025 5.705 2.89 6.71c-.138 1.029-.14 2.383-.14 4.29v2c0 1.907.002 3.262.14 4.29c.135 1.005.389 1.585.812 2.008s1.003.677 2.009.812c1.028.138 2.382.14 4.289.14h4.25zm1.5 16.494c1.034-.01 1.858-.042 2.54-.134c1.005-.135 1.585-.389 2.008-.812s.677-1.003.812-2.009c.138-1.027.14-2.382.14-4.289v-2c0-1.907-.002-3.261-.14-4.29c-.135-1.005-.389-1.585-.812-2.008s-1.003-.677-2.009-.812c-.68-.092-1.505-.123-2.539-.134z"/></svg>
        </button>
    </div>

    <!-- Bloc de départ (desktop comme tiroir mobile) : « Nouvelle conversation »
         et, juste dessous, la recherche dans l'historique — deux moitiés d'un
         même bloc (patron « Nouvelle réunion » de MyBrief, aux couleurs du site).
         Dessous, le bouton « Rechercher ou aller à… » ouvre la palette
         (command-palette.php) : pages, actions et conversations ; ⌘K / Ctrl+K
         l'ouvre depuis n'importe où (le rappel de la touche est rempli selon la
         plateforme ; il n'est affiché qu'au pointeur fin). -->
    <div class="sidebar-start">
        <button class="sidebar-new-btn" id="newChatBtn" data-new-chat title="<?= te('Nouvelle conversation') ?>">
            <span class="sidebar-new-btn__icon" aria-hidden="true">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M12 2.75A9.25 9.25 0 0 0 2.75 12c0 1.481.348 2.879.965 4.118c.248.498.343 1.092.187 1.677l-.596 2.225a.55.55 0 0 0 .673.674l2.227-.596a2.38 2.38 0 0 1 1.676.187A9.2 9.2 0 0 0 12 21.25a9.25 9.25 0 0 0 0-18.5M1.25 12C1.25 6.063 6.063 1.25 12 1.25S22.75 6.063 22.75 12S17.937 22.75 12 22.75c-1.718 0-3.344-.404-4.787-1.122a.9.9 0 0 0-.62-.08l-2.226.595c-1.524.408-2.918-.986-2.51-2.51l.596-2.226a.9.9 0 0 0-.08-.62A10.7 10.7 0 0 1 1.25 12m6-1.5A.75.75 0 0 1 8 9.75h8a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75m0 3.5a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75"/></svg>
            </span>
            <span class="sidebar-nav-label"><?= te('Nouvelle conversation') ?></span>
        </button>
        <button type="button" class="conv-search conv-search-trigger" data-palette-open aria-haspopup="dialog" aria-expanded="false">
            <svg class="conv-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <span class="conv-search-label"><?= te('Rechercher ou aller à…') ?></span>
            <kbd class="conv-search-kbd" data-palette-kbd aria-hidden="true"></kbd>
        </button>
    </div>

    <?php require ROOT . '/templates/partials/sidebar-shortcuts.php'; ?>

    <!-- Générations en cours (file de jobs). Masqué tant qu'il n'y a rien :
         un tiroir vide en permanence serait du bruit. Alimenté par
         GET /jobs/active, donc il voit AUSSI les générations lancées depuis un
         autre onglet ou un autre appareil. -->
    <div class="jobs-tray" id="jobsTray" hidden>
        <button type="button" class="jobs-tray-toggle" id="jobsTrayToggle"
                aria-expanded="false" aria-controls="jobsTrayPanel">
            <span class="jobs-tray-dot" aria-hidden="true"></span>
            <span class="jobs-tray-label" id="jobsTrayLabel"><?= te('Génération en cours') ?></span>
            <svg class="jobs-tray-caret" width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="jobs-tray-panel" id="jobsTrayPanel" hidden>
            <ul class="jobs-tray-list" id="jobsTrayList"></ul>
            <button type="button" class="jobs-tray-notify" id="jobsTrayNotify" hidden>
                <?= te('🔔 M\'avertir quand c\'est terminé') ?>
            </button>
        </div>
    </div>

    <!-- Historique : chat.js range les conversations en groupes (.conv-group) —
         Épinglées, puis par période — et pose le libellé de chacun. -->
    <nav class="conv-list" id="convList" data-max-pinned="<?= Conversation::MAX_PINNED ?>">
        <?php foreach ($conversations as $conv): ?>
            <?php
                $isPinned    = (int)($conv['is_pinned'] ?? 0) === 1;
                // Conversation née d'une tâche programmée, et résultat arrivé
                // en l'absence de la personne (pastille, éteinte à l'ouverture).
                $isScheduled = !empty($conv['scheduled_task_id']);
                $isUnread    = !empty($conv['unread_at']) && !(isset($conversation) && $conversation['id'] == $conv['id']);
            ?>
            <a href="/chat/<?= $conv['id'] ?>"
               class="conv-item <?= (isset($conversation) && $conversation['id'] == $conv['id']) ? 'active' : '' ?> <?= $isPinned ? 'is-pinned' : '' ?><?= $isScheduled ? ' is-scheduled' : '' ?><?= $isUnread ? ' is-unread' : '' ?>"
               data-id="<?= $conv['id'] ?>"
               data-pinned="<?= $isPinned ? '1' : '0' ?>"
               data-ts="<?= (int)strtotime((string)($conv['updated_at'] ?? 'now')) ?>"
               <?php if (!empty($conv['profile_color'])): ?>style="--conv-color:<?= htmlspecialchars($conv['profile_color']) ?>"<?php endif; ?>>
                <?php if (!empty($conv['profile_color'])): ?>
                    <span class="conv-color-bar"></span>
                <?php endif; ?>
                <?php if ($isPinned): ?>
                    <svg class="conv-pin-icon" width="11" height="13" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M32 32C32 14.3 46.3 0 64 0L320 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-29.5 0 10.3 134.1c37.1 21.2 65.8 56.4 78.2 99.7l3.8 13.4c2.8 9.7 .8 20-5.2 28.1S362 352 352 352L32 352c-10 0-19.5-4.7-25.5-12.7s-8-18.4-5.2-28.1L5 297.8c12.4-43.3 41-78.5 78.2-99.7L93.5 64 64 64C46.3 64 32 49.7 32 32zM160 400l64 0 0 112c0 17.7-14.3 32-32 32s-32-14.3-32-32l0-112z"/></svg>
                <?php endif; ?>
                <?php if ($isScheduled): ?>
                    <span class="conv-scheduled-icon" role="img" aria-label="<?= te('Tâche programmée') ?>"><?= ScheduledTask::icon(13) ?></span>
                <?php endif; ?>
                <span class="conv-title"><?= htmlspecialchars(Conversation::displayTitle($conv['title'] ?? null)) ?></span>
                <?php if ($isUnread): ?><span class="conv-unread-dot" title="<?= te('Nouveau résultat') ?>" aria-label="<?= te('Nouveau résultat') ?>"></span><?php endif; ?>
                <button type="button" class="conv-menu-btn" data-conv-menu data-id="<?= $conv['id'] ?>" aria-label="<?= te('Actions') ?>" aria-haspopup="true" aria-expanded="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                </button>
            </a>
        <?php endforeach; ?>
        <?php if (empty($conversations)): ?>
            <p class="conv-empty"><?= te('Aucune conversation') ?></p>
        <?php endif; ?>
    </nav>


    <div class="sidebar-footer">
        <?php require ROOT . '/templates/partials/user-account-trigger.php'; ?>
    </div>
</aside>

<?php require ROOT . '/templates/partials/command-palette.php'; ?>
<?php require ROOT . '/templates/partials/lang-switcher.php'; ?>

<!-- Overlay sidebar mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Réaffichage du volet une fois replié (desktop) : la sidebar a une largeur nulle,
     ce bouton flottant est alors le seul moyen de la faire revenir. -->
<button type="button" class="sidebar-reopen-btn" id="sidebarReopenBtn"
        title="<?= te('Afficher le volet') ?>" aria-label="<?= te('Afficher le volet') ?>"
        aria-controls="sidebar" aria-expanded="false">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" aria-hidden="true"><path d="M9.944 2.25c-1.838 0-3.294 0-4.433.153c-1.172.158-2.121.49-2.87 1.238c-.748.749-1.08 1.698-1.238 2.87c-.153 1.14-.153 2.595-.153 4.433v2.112c0 1.838 0 3.294.153 4.433c.158 1.172.49 2.121 1.238 2.87c.749.748 1.698 1.08 2.87 1.238c1.14.153 2.595.153 4.433.153h5.022l.072-.001c1.384-.004 2.523-.027 3.451-.152c1.172-.158 2.121-.49 2.87-1.238c.748-.749 1.08-1.698 1.238-2.87c.153-1.14.153-2.595.153-4.433v-2.112c0-1.838 0-3.294-.153-4.433c-.158-1.172-.49-2.121-1.238-2.87c-.749-.748-1.698-1.08-2.87-1.238c-.928-.125-2.067-.148-3.45-.152h-.073l-.91-.001zm4.306 1.5H10c-1.907 0-3.261.002-4.29.14c-1.005.135-1.585.389-2.008.812S3.025 5.705 2.89 6.71c-.138 1.029-.14 2.383-.14 4.29v2c0 1.907.002 3.262.14 4.29c.135 1.005.389 1.585.812 2.008s1.003.677 2.009.812c1.028.138 2.382.14 4.289.14h4.25zm1.5 16.494c1.034-.01 1.858-.042 2.54-.134c1.005-.135 1.585-.389 2.008-.812s.677-1.003.812-2.009c.138-1.027.14-2.382.14-4.289v-2c0-1.907-.002-3.261-.14-4.29c-.135-1.005-.389-1.585-.812-2.008s-1.003-.677-2.009-.812c-.68-.092-1.505-.123-2.539-.134z"/></svg>
</button>

<!-- Menu contextuel conversation (popover) — placé hors sidebar pour éviter
     les soucis de position:fixed dans un ancêtre transform:translate (mobile). -->
<div id="convMenu" class="conv-menu" role="menu" hidden>
    <button type="button" class="conv-menu-item" data-action="rename" role="menuitem">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4z"/></svg>
        <span><?= te('Renommer') ?></span>
    </button>
    <button type="button" class="conv-menu-item" data-action="pin" role="menuitem">
        <svg width="13" height="15" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M32 32C32 14.3 46.3 0 64 0L320 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-29.5 0 10.3 134.1c37.1 21.2 65.8 56.4 78.2 99.7l3.8 13.4c2.8 9.7 .8 20-5.2 28.1S362 352 352 352L32 352c-10 0-19.5-4.7-25.5-12.7s-8-18.4-5.2-28.1L5 297.8c12.4-43.3 41-78.5 78.2-99.7L93.5 64 64 64C46.3 64 32 49.7 32 32zM160 400l64 0 0 112c0 17.7-14.3 32-32 32s-32-14.3-32-32l0-112z"/></svg>
        <span data-pin-label><?= te('Épingler') ?></span>
    </button>
    <div class="conv-menu-sep"></div>
    <button type="button" class="conv-menu-item conv-menu-item-danger" data-action="delete" role="menuitem">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
        <span><?= te('Supprimer') ?></span>
    </button>
</div>

<!-- Modale confirmation suppression conversation -->
<div class="modal" id="convDeleteModal" style="display:none">
    <div class="modal-box" style="max-width:420px">
        <h3><?= te('Supprimer cette conversation ?') ?></h3>
        <p class="modal-subtitle" style="line-height:1.5">
            <strong id="convDeleteTitle"><?= te('Conversation') ?></strong> <?= te('sera définitivement supprimée. Cette action est irréversible.') ?>
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" id="convDeleteCancel"><?= te('Annuler') ?></button>
            <button type="button" class="btn-primary" id="convDeleteConfirm" style="background:#ef4444"><?= te('Supprimer') ?></button>
        </div>
    </div>
</div>

    <!-- Assistant actif, LLM courant : calculés une fois, servis au <main>, au composer et au bandeau LLM -->
    <?php
        $logos = LLMFactory::logos();
        // Même règle que send() et la liste blanche des groupes (défaut
        // effectif, restreint au périmètre de l'utilisateur).
        $defaultChatLlm = LLMFactory::appDefault($llms) ?? 'openai';

        $activeProfile = null;
        if (isset($conversation) && !empty($conversation['profile_id'])) {
            foreach ($profiles as $p) {
                if ($p['id'] == $conversation['profile_id']) {
                    $activeProfile = $p;
                    break;
                }
            }
        }
        $profileLocked = (bool)$activeProfile;
        // Raccourci d'édition depuis le chat : droit « Assistants — Modifier »,
        // sur un assistant de son périmètre (tous, ou les siens).
        $canEditActiveProfile = $profileLocked
            && Session::can('assistants.edit')
            && Profile::manageableBy((int)$activeProfile['id'], Session::currentUser());

        $currentLlm = trim((string)($conversation['llm_used'] ?? ''));
        // Conversation à assistant : on affiche le default_llm COURANT de
        // l'assistant (et non un LLM figé à la création), cohérent avec ce que
        // send()/regenerate() utilisent réellement. Le LLM verrouillé par
        // l'admin pour l'assistant s'applique même s'il n'est pas dans la
        // whitelist du groupe utilisateur (le filtre concerne uniquement le
        // choix LIBRE dans le picker, pas le LLM imposé par l'assistant).
        $catalogLlms = $profileLocked ? \LLMFactory::available() : $llms;
        if ($profileLocked) {
            $profileDefaultLlm = trim((string)($activeProfile['default_llm'] ?? ''));
            if ($profileDefaultLlm !== '' && isset($catalogLlms[$profileDefaultLlm])) {
                $currentLlm = $profileDefaultLlm;
            }
        }
        if ($currentLlm === '' || (!isset($llms[$currentLlm]) && !isset($catalogLlms[$currentLlm]))) {
            $currentLlm = $defaultChatLlm;
        }
        // Logo du LLM actif (extraire le provider depuis le slug)
        $currentProvider = LLMFactory::provider($currentLlm);
        $toolbarAvatar = !empty($activeProfile['avatar'] ?? null) ? $activeProfile['avatar'] : null;

        $llmProviderName = LLMFactory::providerLabel($currentProvider);

        if (str_contains($currentLlm, '::')) {
            [, $llmVersion] = explode('::', $currentLlm, 2);
        } elseif ($currentLlm === 'local') {
            $localCfg = ApiKey::findByProvider('local');
            $llmVersion = $localCfg['model'] ?? 'local';
        } else {
            $llmVersion = ApiKey::getModel($currentLlm) ?: LLMFactory::defaultModel($currentLlm);
        }
        $currentLlmLabel = $llms[$currentLlm] ?? ($catalogLlms[$currentLlm] ?? $currentLlm);
        $currentLlmStars = (int)($llmStars[$currentLlm] ?? 0);
        $currentLlmCostBadge = $currentLlmStars > 0 ? str_repeat('€', $currentLlmStars) : '—';
        // Coût d'un modèle en clair (CreditEstimate : les tarifs de la
        // facturation appliqués aux réponses réelles) — les « € » ne disent
        // qu'un rang entre les modèles proposés.
        $llmCostTitle = static function (string $key) use ($llmStars, $llmCredits, $llmSearchCredits): string {
            $parts = [];
            if (!empty($llmCredits[$key])) {
                $parts[] = t('≈ {n} crédits par réponse', ['n' => Format::credits(CreditEstimate::round((int)$llmCredits[$key]))]);
            }
            if (!empty($llmSearchCredits[$key])) {
                $parts[] = t('≈ {n} par recherche Internet', ['n' => Format::credits(CreditEstimate::round((int)$llmSearchCredits[$key]))]);
            }
            $level = !empty($llmStars[$key]) ? t('Coût relatif : {n}/5', ['n' => (int)$llmStars[$key]]) : t('Coût inconnu : aucun tarif pour ce modèle');
            return $level . ($parts !== [] ? ' — ' . implode(', ', $parts) : '');
        };
        $historyCount = isset($messages) && is_array($messages) ? count($messages) : 0;

        // Couleur de l'assistant → variable CSS posée sur <main> dès le rendu
        // serveur (le JS la reposera au changement de conversation). Validée :
        // une valeur libre en base ne doit pas pouvoir casser l'attribut style.
        $profileAccent = trim((string)($activeProfile['color'] ?? ''));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $profileAccent)) {
            $profileAccent = '';
        }
        $identityAvatarSrc = $toolbarAvatar
            ? '/assets/img/profils/' . basename($toolbarAvatar)
            : (string)Settings::get('logo_url', '/assets/img/logo.png');
        $identityDescription = !empty($activeProfile['description'])
            ? (string)$activeProfile['description']
            : t('Cet assistant applique des instructions dédiées à ce cas d\'usage.');
    ?>
<!-- MAIN CHAT -->
<main class="chat-main<?= !isset($conversation) ? ' is-home' : '' ?><?= $profileLocked ? ' has-assistant' : '' ?>"<?= $profileAccent !== '' ? ' style="--profile-accent:' . htmlspecialchars($profileAccent) . '"' : '' ?>>

    <!-- Header mobile (logo à la place du burger quand la toolbar est visible) -->
    <div class="mobile-header" id="mobileHeader">
        <button type="button" class="btn-sidebar-toggle" data-drawer-toggle title="<?= te('Ouvrir le menu') ?>"
                aria-label="<?= te('Ouvrir le menu') ?>" aria-controls="sidebar" aria-expanded="false">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"
             alt="" class="mobile-header-logo">
        <span class="mobile-header-title"><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></span>
        <button type="button" class="mobile-header-newchat" data-new-chat title="<?= te('Nouvelle conversation') ?>"
                aria-label="<?= te('Nouvelle conversation') ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        </button>
        <?php require ROOT . '/templates/partials/header-avatar-btn.php'; ?>
    </div>

    <!-- Toolbar -->
    <?php // Bandeau REPLIÉ par défaut (rendu ainsi, sans attendre le script : aucun
          // clignotement au chargement) ; chat.js le déplie si la personne l'a déplié. ?>
    <div class="chat-toolbar is-collapsed" id="chatToolbar" <?= !isset($conversation) ? 'style="display:none"' : '' ?>>
        <button type="button" class="btn-sidebar-toggle" data-drawer-toggle title="<?= te('Menu') ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <!-- Inputs communs aux deux états du toolbar -->
        <input type="hidden" id="llmSelect" value="<?= htmlspecialchars($currentLlm) ?>">
        <select id="profileSelect" class="selector" style="display:none" aria-hidden="true" tabindex="-1">
            <?php if ($profileLocked): ?>
            <option
                value="<?= (int)$activeProfile['id'] ?>"
                data-color="<?= htmlspecialchars($activeProfile['color'] ?? '') ?>"
                data-avatar="<?= htmlspecialchars($activeProfile['avatar'] ?? '') ?>"
                selected>
                <?= htmlspecialchars($activeProfile['name'] ?? t('Assistant')) ?>
            </option>
            <?php endif; ?>
        </select>

        <!-- L'identité de l'assistant vit dans le composer (#assistantIdentity) :
             en mode assistant la toolbar entière est masquée (CSS .has-assistant). -->

        <!-- Bandeau infos LLM (visible si pas d'assistant) -->
        <div class="toolbar-selectors" id="toolbarSelectors" <?= $profileLocked ? 'style="display:none"' : '' ?>>
            <div class="toolbar-model-logo-wrap" id="toolbarModelLogoWrap" aria-hidden="true">
                <img
                    src="<?= htmlspecialchars($logos[$currentProvider] ?? Settings::get('logo_url', '/assets/img/logo.png')) ?>"
                    alt="<?= te('Logo du modèle') ?>"
                    class="toolbar-model-logo"
                    id="topActiveModelLogo">
            </div>
            <div class="toolbar-selectors-stack">
                <div class="llm-caps-bar" id="llmCapsBar"></div>
                <div class="toolbar-meta-bar" id="toolbarMetaBar">
                    <span class="toolbar-meta-chip toolbar-meta-model">
                        <strong><?= te('Modèle:') ?></strong>
                        <span id="topActiveModelLabel"><?= htmlspecialchars($currentLlmLabel) ?></span>
                    </span>
                    <span class="toolbar-meta-chip toolbar-meta-cost" id="topActiveModelCost" title="<?= htmlspecialchars($llmCostTitle((string)$currentLlm)) ?>">
                        <?= htmlspecialchars($currentLlmCostBadge) ?>
                    </span>
                    <span class="toolbar-meta-chip toolbar-meta-history">
                        <strong><?= te('Historique:') ?></strong>
                        <span id="topHistoryCount"><?= (int)$historyCount ?></span> <?= te('messages') ?>
                    </span>
                </div>
                <p class="toolbar-disclaimer"><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> <?= te('peut faire des erreurs. Vérifiez les informations importantes.') ?></p>
            </div><!-- /.toolbar-selectors-stack -->
        </div><!-- /#toolbarSelectors -->

        <button type="button" class="toolbar-collapse-btn" id="toolbarCollapseBtn"
                title="<?= te('Masquer / afficher le bandeau') ?>"
                aria-label="<?= te('Afficher le bandeau') ?>" aria-expanded="false">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
    </div>

    <!-- Zone messages -->
    <div class="chat-messages" id="chatMessages">
        <?php if (!isset($conversation)): ?>
            <?php
                // Salutation personnalisée : « {Prénom}, {message d'accueil} ».
                // La 1re lettre du message n'est mise en minuscule que si la 2e
                // l'est déjà (évite de casser un sigle type « MIA vous écoute »).
                // `editorial()` et non un simple repli : la valeur est SEMÉE en base
                // (004_settings.sql), donc le défaut d'un `Settings::get` n'est jamais
                // atteint et la salutation restait française en pleine interface anglaise.
                $welcomeMsg = I18n::editorial(Settings::get('welcome_message', ''), 'Comment puis-je vous aider ?');
                $firstName  = trim((string)strtok(trim((string)($currentUser['name'] ?? '')), ' '));
                $welcomeGreeting = $welcomeMsg;
                if ($firstName !== '' && $welcomeMsg !== '') {
                    $second = mb_substr($welcomeMsg, 1, 1);
                    $msgBody = ($second !== '' && $second === mb_strtolower($second))
                        ? mb_strtolower(mb_substr($welcomeMsg, 0, 1)) . mb_substr($welcomeMsg, 1)
                        : $welcomeMsg;
                    $welcomeGreeting = $firstName . ', ' . $msgBody;
                }
            ?>
            <div class="chat-welcome">
                <div class="welcome-icon">
                    <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>" alt="<?= te('logo') ?>" width="110" height="110" style="border-radius:var(--radius-sm);object-fit:contain">
                </div>
                <h2 class="welcome-greeting"><?= htmlspecialchars($welcomeGreeting) ?></h2>
            </div>
        <?php elseif ($profileLocked && empty($messages)): ?>
            <!-- Conversation ouverte avec un assistant, encore vide : la présentation
                 de l'assistant occupe l'espace laissé libre par le bandeau, le temps
                 du premier message (appendMessage retire toute .chat-welcome). -->
            <div class="chat-welcome assistant-welcome" id="assistantWelcome">
                <img class="assistant-welcome-avatar" src="<?= htmlspecialchars($identityAvatarSrc) ?>" alt="">
                <p class="assistant-welcome-kicker"><?= te('Assistant') ?></p>
                <h2 class="assistant-welcome-name"><?= htmlspecialchars($activeProfile['name'] ?? t('Assistant')) ?></h2>
                <?php if (!empty($activeProfile['description'])): ?>
                <p class="assistant-welcome-desc"><?= htmlspecialchars($activeProfile['description']) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php
                $profileAvatar = null;
                if (!empty($conversation['profile_id'])) {
                    foreach ($profiles as $p) {
                        if ($p['id'] == $conversation['profile_id']) {
                            $profileAvatar = $p['avatar'] ?? null;
                            break;
                        }
                    }
                }
            ?>
            <?php if (!empty($scheduledBanner)): ?>
                <?php // Même balisage que buildScheduledBannerHtml() (chat.js, chemin JSON). ?>
                <div class="sched-banner" id="scheduledBanner">
                    <?= ScheduledTask::icon(16, 'sched-banner-icon') ?>
                    <span class="sched-banner-text">
                        <span class="sched-banner-kicker"><?= te('Tâche programmée') ?></span>
                        <strong><?= htmlspecialchars((string)$scheduledBanner['name']) ?></strong>
                        <?php if ($scheduledBanner['sentence'] !== ''): ?><span class="sched-banner-sentence"><?= htmlspecialchars((string)$scheduledBanner['sentence']) ?></span><?php endif; ?>
                        <?php if (!$scheduledBanner['active']): ?><span class="sched-banner-paused"><?= te('En pause') ?></span><?php endif; ?>
                    </span>
                    <a class="sched-banner-link" href="<?= htmlspecialchars((string)$scheduledBanner['url']) ?>"><?= te('Gérer la tâche') ?></a>
                </div>
            <?php endif; ?>
            <?php foreach ($messages as $msg): ?>
                <?php include ROOT . '/templates/partials/message.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Input -->
    <div class="chat-input-wrap">
        <button type="button" id="resumeFollowBtn" class="resume-follow-btn" hidden aria-label="<?= te('Revenir à la génération en cours') ?>" title="<?= te('Revenir à la génération en cours') ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>
        </button>
        <p class="conversation-credits-note" id="conversationCreditsBox" <?= ((int)($conversationCreditsTotal ?? 0)) > 0 ? '' : 'style="display:none"' ?> title="<?= te('Crédits débités pour cette conversation : réponses, recherches, créations, titre, voix et suggestions de suite') ?>">
            <?= te('Crédits utilisés dans cette conversation :') ?> <strong id="conversationCreditsValue"><?= Format::credits((int)($conversationCreditsTotal ?? 0)) ?></strong>
        </p>

        <?php
        // Icônes SVG par type d'outil (style unifié : trait 24px, stroke 2).
        // SOURCE UNIQUE : menu « + », badges d'outils de l'identité de
        // l'assistant (composer) et, via window.TOOL_TYPE_ICONS, leur re-rendu
        // JS au changement de conversation.
        $toolIcons = [
            'image' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
            'html'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
            'video' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m10 9 5 3-5 3z"/></svg>',
            'map'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 8 3 16 6 23 3 23 18 16 21 8 18 1 21"/><line x1="8" y1="3" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="21"/></svg>',
            'presentation' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="13" rx="2"/><path d="M8 21h8M12 16v5"/></svg>',
            'n8n'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'gamma' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="13" rx="2"/><path d="M8 21h8M12 16v5"/></svg>',
            'generic' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        ];
        $toolHints = [
            'image' => t('Génère une image à partir de votre description'),
            'html'  => t('Génère une page web complète'),
            'video' => t('Génère un court clip vidéo'),
            'map'   => t('Dessine un itinéraire sur une carte'),
            'presentation' => t('Crée un diaporama : plan, puis slides 16:9'),
            'html_illustrated' => t('Génère une page web illustrée d\'images'),
        ];
        // Site illustré : sa propre icône (une page et son image), pour qu'il ne
        // se confonde pas avec la page web dans le menu « + ».
        $toolIcons['html_illustrated'] = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 8h18"/><rect x="6.5" y="11" width="6" height="6" rx="1"/><path d="M15 12h3M15 15.5h3"/></svg>';
        ?>
        <form class="chat-input-form" id="chatForm">
            <input type="hidden" id="convId" value="<?= $conversation['id'] ?? '' ?>">

            <div class="input-box" id="inputBox">
                <!-- Identité de l'assistant : rangée haute de la pilule, à gauche
                     (cf. CLAUDE.md « Identité de l'assistant dans le composer »).
                     Toujours rendue, masquée hors assistant : switchConversation la
                     remplit au changement de fil sans recréer de DOM. Repliée par
                     défaut ; le chevron déploie description, modèle, version, outils
                     et bases. Le bouton Modifier n'existe que pour qui gère
                     l'assistant (droit tranché côté serveur, assistant par assistant). -->
                <div class="assistant-identity" id="assistantIdentity" <?= $profileLocked ? '' : 'hidden' ?>>
                    <div class="assistant-identity-row">
                        <button type="button" class="assistant-identity-toggle" id="assistantIdentityToggle"
                                aria-expanded="false" aria-controls="assistantIdentityDetails"
                                title="<?= te('Afficher ou masquer les caractéristiques de l\'assistant') ?>">
                            <img class="assistant-identity-avatar" id="assistantIdentityAvatar"
                                 src="<?= htmlspecialchars($identityAvatarSrc) ?>" alt="">
                            <span class="assistant-identity-kicker"><?= te('Assistant') ?></span>
                            <span class="assistant-identity-name" id="assistantIdentityName"><?= htmlspecialchars($activeProfile['name'] ?? t('Assistant')) ?></span>
                            <svg class="assistant-identity-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <?php if (ScheduledTask::allows(Session::currentUser(), 'create')): ?>
                        <?php /* L'horloge des tâches programmées : programmer une routine AVEC
                                 cet assistant. Un lien (marche sans JavaScript) ; chat.js y ajoute
                                 le texte en cours de saisie et les sources cochées. Rangée des
                                 actions qui concernent l'assistant, à côté de « Modifier ». */ ?>
                        <a class="assistant-identity-edit assistant-identity-schedule" id="assistantScheduleBtn"
                           href="/settings/schedules/new?compose=1<?= $profileLocked ? '&amp;profile=' . (int)$activeProfile['id'] : '' ?>"
                           title="<?= te('Programmer une tâche avec cet assistant : elle se répétera toute seule') ?>">
                            <?= ScheduledTask::icon(13) ?>
                            <span><?= te('Programmer') ?></span>
                        </a>
                        <?php endif; ?>
                        <a class="assistant-identity-edit" id="profileEditBtn"
                           href="<?= $profileLocked ? '/admin/profiles/' . (int)$activeProfile['id'] . '/edit' : '/admin/profiles' ?>"
                           title="<?= te('Ouvrir la fiche de cet assistant dans l\'administration') ?>"
                           <?= $canEditActiveProfile ? '' : 'hidden' ?>>
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4z"/></svg>
                            <span><?= te('Modifier') ?></span>
                        </a>
                    </div>
                    <div class="assistant-identity-details" id="assistantIdentityDetails" hidden>
                        <p class="assistant-identity-desc" id="assistantIdentityDesc"><?= htmlspecialchars($identityDescription) ?></p>
                        <div class="assistant-identity-meta">
                            <span class="assistant-identity-chip assistant-identity-model" title="<?= te('Modèle imposé par l\'assistant (le sélecteur de modèle est masqué)') ?>">
                                <img src="<?= htmlspecialchars($logos[$currentProvider] ?? '') ?>" alt="" class="llm-icon" id="assistantIdentityLlmIcon">
                                <span id="assistantIdentityLlmProvider"><?= htmlspecialchars($llmProviderName) ?></span>
                                <code id="assistantIdentityLlmVersion"><?= htmlspecialchars($llmVersion) ?></code>
                            </span>
                            <span class="assistant-identity-tools" id="assistantIdentityTools">
                                <?php foreach (($activeProfileTools ?? []) as $apt): ?>
                                <span class="assistant-identity-chip" title="<?= te('Outil de cet assistant — appelé automatiquement par le modèle') ?>">
                                    <?= $toolIcons[$apt['type']] ?? $toolIcons['generic'] ?>
                                    <span><?= htmlspecialchars($apt['name']) ?></span>
                                </span>
                                <?php endforeach; ?>
                            </span>
                            <?php
                                $identityKbNames = array_map(static fn(array $k): string => (string)$k['name'], $chatProfileKbs ?? []);
                                $identityKbCount = count($identityKbNames);
                            ?>
                            <span class="assistant-identity-chip assistant-identity-kbs" id="assistantIdentityKbs"
                                  title="<?= htmlspecialchars(implode(', ', $identityKbNames)) ?>" <?= $identityKbCount > 0 ? '' : 'hidden' ?>>
                                <?php /* Même tracé que $kbIcon de templates/partials/admin-nav.php (livre ouvert). */ ?>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                <?php /* Message ENTIER, et non « base » + « s » + « de connaissances » : le
                                         pluriel et l'ordre des mots changent d'une langue à l'autre,
                                         et un fragment n'est pas traduisible seul. */ ?>
                                <span id="assistantIdentityKbsLabel"><?= htmlspecialchars(I18n::plural($identityKbCount, '{n} base de connaissances', '{n} bases de connaissances')) ?></span>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="file-chips" id="fileChips" style="display:none"></div>
                <div class="input-row">
                    <?php // Touche d'envoi choisie dans Réglages → Affichage (lue par chat.js,
                          // isSendKeystroke) ; aria-keyshortcuts l'annonce aux lecteurs d'écran. ?>
                    <?php $sendKey = DisplayPreferences::sendKeyOf(Session::currentUser()); ?>
                    <textarea
                        id="msgInput"
                        class="msg-textarea"
                        placeholder="<?= te('Envoyer un message ou déposer vos fichiers…') ?>"
                        rows="1"
                        data-send-key="<?= htmlspecialchars($sendKey) ?>"
                        aria-keyshortcuts="<?= $sendKey === 'mod_enter' ? 'Control+Enter Meta+Enter' : 'Enter' ?>"
                        autofocus></textarea>
                </div>
                <?php if (!empty($hasOpenaiKey) && !empty($voiceChatEnabled)): ?>
                <!-- Conversation vocale : bandeau d'état de la boucle « j'écoute →
                     j'envoie → MIA répond → je lis » (cf. CLAUDE.md « Conversation
                     vocale »). Masqué hors conversation ; rempli par chat.js. -->
                <div class="voice-chat-bar" id="voiceChatBar" hidden aria-live="polite">
                    <span class="voice-chat-wave" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                    <span class="voice-chat-status" id="voiceChatStatus"></span>
                    <span class="voice-chat-live" id="voiceChatLive"></span>
                    <!-- Volume de la voix (et des repères sonores) : masqué par chat.js
                         là où le navigateur ne laisse pas régler le volume d'un média
                         (iOS — les boutons de l'appareil règlent). -->
                    <label class="voice-chat-volume" id="voiceChatVolumeWrap" title="<?= te('Volume de la voix') ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5.5a9 9 0 0 1 0 13"/></svg>
                        <input type="range" id="voiceChatVolume" min="0" max="100" step="5" value="100" aria-label="<?= te('Volume de la voix') ?>">
                    </label>
                    <!-- Longueur des réponses à l'oral : réglable EN PLEINE
                         conversation, et c'est la même valeur que la carte
                         « Voix » des Préférences IA (users.voice_length) —
                         une seule source, jamais deux états à accorder. -->
                    <label class="voice-chat-length" id="voiceChatLengthWrap" title="<?= te('Longueur des réponses à l\'oral') ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="16" y2="12"/><line x1="3" y1="18" x2="10" y2="18"/></svg>
                        <select id="voiceChatLength" aria-label="<?= te('Longueur des réponses à l\'oral') ?>">
                            <?php foreach (($voicePrefs['lengths'] ?? []) as $opt): ?>
                                <option value="<?= htmlspecialchars((string)$opt['value']) ?>" <?= ($voicePrefs['length'] ?? '') === $opt['value'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$opt['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <!-- Interruption à la voix : pendant la lecture, « stop » fait
                         taire MIA. Réglage PAR APPAREIL (le micro reste alors ouvert
                         pendant qu'elle parle — sur iPhone et iPad, sa voix en est
                         moins forte) : l'état et l'infobulle sont posés par chat.js. -->
                    <button type="button" class="voice-chat-barge" id="voiceChatBargeIn" aria-pressed="false" aria-label="<?= te('Interrompre MIA à la voix') ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                        <span><?= te('« Stop »') ?></span>
                    </button>
                    <button type="button" class="voice-chat-skip" id="voiceChatSkip" hidden><?= te('Passer') ?></button>
                    <button type="button" class="voice-chat-end" id="voiceChatEnd" title="<?= te('Terminer la conversation vocale') ?>"><?= te('Terminer') ?></button>
                </div>
                <?php endif; ?>

            <!-- Barre d'actions DANS la pilule (micro/envoyer à droite, menus à
                 gauche) : une seule zone compacte, au lieu d'une rangée séparée
                 sous la pilule qui doublait la hauteur du composer sur mobile. -->
            <div class="input-actions">
                <div class="input-actions-left">
                    <div class="plus-menu" id="plusMenu">
                        <button type="button" class="composer-btn plus-menu-btn" id="plusMenuBtn"
                                title="<?= te('Fichiers, outils et connecteurs') ?>"
                                aria-haspopup="true" aria-expanded="false" aria-controls="plusMenuPanel">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                            <span class="plus-menu-dot" id="plusMenuDot" hidden></span>
                        </button>
                        <!-- Inputs fichiers : restent dans le DOM (handlers JS par id), déclenchés depuis le menu -->
                        <?php /* `accept` dérivé de la liste du serveur, images en tête : l'ORDRE compte dans
                                 l'application Android (le premier type MIME choisit les applications
                                 proposées), cf. FileExtractor::acceptAttribute. */ ?>
                        <input type="file" id="fileInput" accept="<?= htmlspecialchars(FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES)) ?>" multiple style="display:none">
                        <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display:none">
                        <div class="plus-menu-panel" id="plusMenuPanel" hidden>
                            <?php

                                // Rendu d'un item outil : UNE ligne (icône, nom, coût en crédits,
                                // coche). La description passe dans l'infobulle ; elle ne reste à
                                // l'écran que si l'outil est hors service (arrêté par son
                                // fournisseur, dernier test en échec) — c'est alors une information.
                                $renderToolItem = function (array $tool, string $type) use ($toolIcons, $toolHints, $toolStars, $toolCredits): void {
                                    $failed  = isset($tool['last_test_ok']) && (int)$tool['last_test_ok'] === 0;
                                    // Modèle arrêté par son fournisseur : grisé AVEC la raison,
                                    // jamais masqué — une absence ne s'explique pas. Il prime sur
                                    // l'échec de test, dont il est alors la vraie cause.
                                    $arret   = ChatTool::retirement($tool);
                                    $retired = $arret !== null && $arret['retired'];
                                    $off     = $failed || $retired;
                                    $stars   = (int)($toolStars[(int)$tool['id']] ?? 0);
                                    $toolCost = (int)($toolCredits[(int)$tool['id']] ?? 0);
                                    $iconKey = in_array($type, ['image', 'video', 'map', 'presentation', 'html_illustrated'], true) ? $type : 'html';
                                    if ($retired) {
                                        $hint  = t('Arrêté par son fournisseur le {date} — prévenir un administrateur', ['date' => Format::dateFr($arret['date'])]);
                                        $title = (string)ChatTool::retiredNotice($tool);
                                    } else {
                                        $hint  = $failed ? t('Dernier test en échec — prévenir un administrateur') : '';
                                        $title = $failed
                                            ? t('Dernier test en échec') . (!empty($tool['last_test_message']) ? ' : ' . mb_substr($tool['last_test_message'], 0, 120) : '')
                                            : (string)($toolHints[$iconKey] ?? '');
                                    }
                                    $costTitle = $toolCost > 0
                                        ? t('≈ {n} crédits par génération', ['n' => Format::credits(CreditEstimate::round($toolCost))])
                                        : '';
                                    if (!$off && $costTitle !== '') {
                                        $title = $title !== '' ? $title . ' — ' . $costTitle : $costTitle;
                                    }
                                    ?>
                                <li class="tool-picker-item <?= $off ? 'is-disabled' : '' ?>"
                                    data-id="<?= (int)$tool['id'] ?>"
                                    data-type="<?= htmlspecialchars($type) ?>"
                                    data-name="<?= htmlspecialchars($tool['name']) ?>"
                                    data-cost="<?= $stars ?>"
                                    data-disabled="<?= $off ? '1' : '0' ?>"
                                    role="menuitemradio" aria-checked="false" tabindex="0"
                                    title="<?= htmlspecialchars($title) ?>">
                                    <span class="plus-menu-icon"><?= $toolIcons[$iconKey] ?></span>
                                    <span class="plus-menu-info">
                                        <strong><?= htmlspecialchars($tool['name']) ?></strong>
                                        <?php if ($hint !== ''): ?><span class="plus-menu-hint"><?= htmlspecialchars($hint) ?></span><?php endif; ?>
                                    </span>
                                    <?php if (!$off && $toolCost > 0): ?>
                                    <span class="plus-menu-cost" aria-hidden="true"><?= te('≈ {n} cr.', ['n' => Format::credits(CreditEstimate::round($toolCost))]) ?></span>
                                    <?php endif; ?>
                                    <svg class="plus-menu-check" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </li>
                                    <?php
                                };
                            ?>
                            <ul class="plus-menu-list">
                                <?php /* Joindre : une rangée de boutons, pas une liste. Sur ordinateur un
                                         seul bouton (le sélecteur prend fichiers et photos) ; au téléphone,
                                         « Fichiers » et « Photo » côte à côte — la photo ouvre l'appareil.
                                         Les identifiants sont ceux que chat.js écoute. */ ?>
                                <li class="plus-menu-actions" role="none">
                                    <span class="plus-menu-action" id="plusMenuAddFiles" role="button" tabindex="0" title="<?= te('Joindre des fichiers ou des photos (max 10)') ?>">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                        <span class="plus-menu-desktop-only"><?= te('Joindre des fichiers') ?></span>
                                        <span class="plus-menu-mobile-only"><?= te('Fichiers') ?></span>
                                        <span class="plus-menu-action-hint plus-menu-desktop-only"><?= te('10 max') ?></span>
                                    </span>
                                    <span class="plus-menu-action plus-menu-mobile-only" id="plusMenuTakePhoto" role="button" tabindex="0" title="<?= te('Prendre une photo avec l\'appareil') ?>">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                        <?= te('Photo') ?>
                                    </span>
                                </li>
                                <?php
                                    // Plus d'entrée « Présentation » : une présentation se demande
                                    // dans la conversation, et « Modifier les slides » sur sa carte
                                    // ouvre l'éditeur (generated-files.js). Les présentations déjà
                                    // créées par l'ancien outil restent ouvrables depuis leur carte.
                                ?>
                                <?php if (!empty($imageTools) || !empty($htmlTools) || !empty($videoTools) || !empty($mapTools)): ?>
                                <li class="picker-section-label"><?= te('Créer') ?></li>
                                <?php foreach (($imageTools ?? []) as $imgTool) { $renderToolItem($imgTool, 'image'); } ?>
                                <?php foreach (($htmlTools ?? []) as $htmlTool) { $renderToolItem($htmlTool, (string)($htmlTool['type'] ?? 'html')); } ?>
                                <?php foreach (($mapTools ?? []) as $mapTool) { $renderToolItem($mapTool, 'map'); } ?>
                                <?php foreach (($videoTools ?? []) as $videoTool) { $renderToolItem($videoTool, 'video'); } ?>
                                <?php endif; ?>
                                <?php
                                    // Connecteurs personnels. Une ligne n'est rendue QUE si
                                    // l'administrateur a configuré ET autorisé la source
                                    // (OAuth déclaré + fonctionnalité activée) : pas d'option
                                    // morte dans le menu. Jamais cochée côté serveur (opt-in
                                    // explicite) — le JS restaure le choix mémorisé.
                                    // La compatibilité du modèle n'entre PAS ici : un
                                    // connecteur reste toujours activable, l'activation sur
                                    // un modèle sans function-calling ouvre la modale de
                                    // bascule de modèle (chat.js).
                                    $connectorRows = [];
                                    if (!empty($driveFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'drive',
                                            'id'        => 'plusMenuDriveRow',
                                            'toggle'    => 'driveAccessToggle',
                                            'label'     => t('Fichiers Google Drive'),
                                            'icon'      => '<img src="/assets/img/google-drive.svg" width="16" height="16" alt="">',
                                            'hint'      => t('Recherche et lit vos fichiers Drive'),
                                            'title'     => t('Autoriser l\'assistant à chercher et lire vos fichiers Google Drive'),
                                            'connect'   => t('Connectez votre Google Drive (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($driveConnected),
                                        ];
                                    }
                                    if (!empty($gmailFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'gmail',
                                            'id'        => 'plusMenuGmailRow',
                                            'toggle'    => 'gmailAccessToggle',
                                            'label'     => t('Emails Gmail'),
                                            'icon'      => '<img src="/assets/img/gmail.svg" width="16" height="12" alt="">',
                                            'hint'      => t('Recherche et lit vos emails Gmail'),
                                            'title'     => t('Autoriser l\'assistant à rechercher et lire vos emails Gmail'),
                                            'connect'   => t('Connectez votre Gmail (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($gmailConnected),
                                        ];
                                    }
                                    if (!empty($calendarFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'calendar',
                                            'id'        => 'plusMenuCalendarRow',
                                            'toggle'    => 'calendarAccessToggle',
                                            'label'     => t('Agenda Google'),
                                            'icon'      => '<img src="/assets/img/google-calendar.svg" width="15" height="15" alt="">',
                                            'hint'      => t('Consulte vos agendas et évènements'),
                                            'title'     => t('Autoriser l\'assistant à consulter votre agenda Google'),
                                            'connect'   => t('Connectez votre Google Agenda (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($calendarConnected),
                                        ];
                                    }
                                    if (!empty($tasksFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'tasks',
                                            'id'        => 'plusMenuTasksRow',
                                            'toggle'    => 'tasksAccessToggle',
                                            'label'     => t('Tâches Google'),
                                            'icon'      => '<img src="/assets/img/google-tasks.svg" width="15" height="15" alt="">',
                                            'hint'      => t('Consulte et met à jour vos tâches'),
                                            'title'     => t('Autoriser l\'assistant à consulter et mettre à jour vos tâches Google'),
                                            'connect'   => t('Connectez vos tâches Google (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($tasksConnected),
                                        ];
                                    }
                                    if (!empty($outlookFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'outlook',
                                            'id'        => 'plusMenuOutlookRow',
                                            'toggle'    => 'outlookAccessToggle',
                                            'label'     => t('Emails Outlook'),
                                            'icon'      => '<img src="/assets/img/outlook.svg" width="16" height="16" alt="">',
                                            'hint'      => t('Recherche et lit vos emails Outlook'),
                                            'title'     => t('Autoriser l\'assistant à rechercher et lire vos emails Outlook'),
                                            'connect'   => t('Connectez votre Outlook (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($outlookConnected),
                                        ];
                                    }
                                    if (!empty($msCalendarFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'mscalendar',
                                            'id'        => 'plusMenuMsCalendarRow',
                                            'toggle'    => 'mscalendarAccessToggle',
                                            'label'     => t('Agenda Outlook'),
                                            'icon'      => '<img src="/assets/img/outlook-calendar.svg" width="15" height="15" alt="">',
                                            'hint'      => t('Consulte vos évènements Outlook'),
                                            'title'     => t('Autoriser l\'assistant à consulter votre calendrier Outlook'),
                                            'connect'   => t('Connectez votre calendrier Outlook (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($msCalendarConnected),
                                        ];
                                    }
                                    if (!empty($msTodoFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'mstodo',
                                            'id'        => 'plusMenuMsTodoRow',
                                            'toggle'    => 'mstodoAccessToggle',
                                            'label'     => t('Tâches Microsoft To Do'),
                                            'icon'      => '<img src="/assets/img/microsoft-todo.svg" width="15" height="15" alt="">',
                                            'hint'      => t('Consulte et met à jour vos tâches To Do'),
                                            'title'     => t('Autoriser l\'assistant à consulter et mettre à jour vos tâches Microsoft To Do'),
                                            'connect'   => t('Connectez Microsoft To Do (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($msTodoConnected),
                                        ];
                                    }
                                    if (!empty($teamsFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'teams',
                                            'id'        => 'plusMenuTeamsRow',
                                            'toggle'    => 'teamsAccessToggle',
                                            'label'     => t('Messages Teams'),
                                            'icon'      => '<img src="/assets/img/microsoft-teams.svg" width="15" height="15" alt="">',
                                            'hint'      => t('Recherche et lit vos conversations et canaux Teams'),
                                            'title'     => t('Autoriser l\'assistant à rechercher et lire vos messages Teams'),
                                            'connect'   => t('Connectez Microsoft Teams (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($teamsConnected),
                                        ];
                                    }
                                    if (!empty($slackFeatureEnabled)) {
                                        $connectorRows[] = [
                                            'key'       => 'slack',
                                            'id'        => 'plusMenuSlackRow',
                                            'toggle'    => 'slackAccessToggle',
                                            'label'     => t('Messages Slack'),
                                            'icon'      => '<img src="/assets/img/slack.svg" width="15" height="15" alt="">',
                                            'hint'      => t('Recherche et lit vos conversations Slack'),
                                            'title'     => t('Autoriser l\'assistant à rechercher et lire vos messages Slack'),
                                            'connect'   => t('Connectez votre Slack (Réglages → Comptes connectés) pour activer cette option'),
                                            'connected' => !empty($slackConnected),
                                        ];
                                    }
                                ?>
                                <?php
                                    // Volet « Connecteurs », replié à chaque ouverture du menu (seule
                                    // la puce des connecteurs l'ouvre déplié). Il porte les comptes RELIÉS et les
                                    // connexions EXPIRÉES (elles disent pourquoi et mènent à leur
                                    // carte) ; les services jamais reliés tiennent en une ligne
                                    // « Relier N services ». Replié, son en-tête montre les logos des
                                    // connecteurs allumés (chat.js, depuis les interrupteurs).
                                    $foldRows = [];
                                    $linkRows = [];
                                    foreach ($connectorRows as $c) {
                                        $expired = empty($c['connected']) && in_array($c['key'], $connectorsToReconnect ?? [], true);
                                        if (!empty($c['connected']) || $expired) { $foldRows[] = $c; } else { $linkRows[] = $c; }
                                    }
                                    $foldExpired = count($foldRows) - count(array_filter($foldRows, static fn(array $c): bool => !empty($c['connected'])));
                                    $renderLinkRow = static function (array $rows): void {
                                        if ($rows === []) { return; }
                                        $names = array_map(static fn(array $c): string => t(ConnectorRegistry::label($c['key'])), $rows);
                                        ?>
                                <li class="plus-menu-link-item" role="none">
                                    <a class="plus-menu-row plus-menu-link" id="plusMenuLinkRow" href="/settings/connections"
                                       title="<?= htmlspecialchars(t('À relier dans Réglages → Comptes connectés : {services}', ['services' => implode(', ', $names)])) ?>">
                                        <span class="plus-menu-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22v-5"/><path d="M9 8V2M15 8V2"/><path d="M18 8v5a6 6 0 0 1-12 0V8z"/></svg></span>
                                        <span class="plus-menu-info"><strong><?= htmlspecialchars(I18n::plural(count($rows), 'Relier un service', 'Relier {n} services')) ?></strong></span>
                                        <span class="plus-menu-link-logos" aria-hidden="true"><?php foreach (array_slice($rows, 0, 4) as $c) { echo $c['icon']; } ?></span>
                                        <svg class="plus-menu-link-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                                    </a>
                                </li>
                                        <?php
                                    };
                                    // « Laisser MIA choisir » (ConnectorLoaderTools) : les connecteurs
                                    // RELIÉS mais éteints deviennent activables par MIA elle-même, au
                                    // besoin. Ligne rendue seulement si l'administrateur le permet et
                                    // qu'au moins un connecteur est relié — sinon elle ne ferait rien.
                                    $autoRowUseful = ConnectorRegistry::isAutoModeEnabled()
                                        && array_filter($connectorRows, static fn(array $c): bool => !empty($c['connected'])) !== [];
                                ?>
                                <?php if (!empty($webFeatureEnabled) || $connectorRows): ?>
                                <li class="picker-section-label"><?= te('Sources') ?></li>
                                <?php endif; ?>
                                <?php if (!empty($webFeatureEnabled)): // masqué par l'admin = ligne absente du DOM ?>
                                <li class="plus-menu-row" id="plusMenuWebRow" title="<?= te('Accès Internet (recherche web avant réponse)') ?>">
                                    <span class="plus-menu-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span>
                                    <span class="plus-menu-info">
                                        <strong><?= te('Recherche Internet') ?></strong>
                                        <span class="plus-menu-hint"><?= te('Consulte le web avant de répondre') ?></span>
                                    </span>
                                    <label class="ios-switch">
                                        <input type="checkbox" id="webAccessToggle" checked>
                                        <span class="ios-switch-slider"></span>
                                    </label>
                                </li>
                                <?php endif; ?>
                                <?php if ($foldRows !== []): ?>
                                <li class="plus-menu-fold" role="none">
                                    <button type="button" class="plus-menu-fold-btn" id="plusMenuFoldBtn"
                                            aria-expanded="false" aria-controls="plusMenuFoldBody">
                                        <span class="plus-menu-fold-chev" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg></span>
                                        <span class="plus-menu-fold-name"><?= te('Connecteurs') ?></span>
                                        <span class="plus-menu-fold-count" id="plusMenuFoldCount"></span>
                                        <?php if ($foldExpired > 0): ?>
                                        <span class="reconnect-dot plus-menu-fold-alert" title="<?= htmlspecialchars(I18n::plural($foldExpired, 'Une connexion à reconnecter', '{n} connexions à reconnecter')) ?>"></span>
                                        <?php endif; ?>
                                        <span class="plus-menu-fold-logos" id="plusMenuFoldLogos" aria-hidden="true"></span>
                                    </button>
                                    <ul class="plus-menu-fold-body" id="plusMenuFoldBody" hidden>
                                <?php if ($autoRowUseful): ?>
                                    <li class="plus-menu-row plus-menu-auto" id="plusMenuAutoRow"
                                        data-connector="auto"
                                        data-label="<?= te('Laisser MIA choisir') ?>"
                                        title="<?= te('MIA active d’elle-même vos connecteurs reliés quand votre demande en a besoin') ?>">
                                        <span class="plus-menu-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg></span>
                                        <span class="plus-menu-info">
                                            <strong><?= te('Laisser MIA choisir') ?></strong>
                                            <span class="plus-menu-hint"><?= te('Active vos connecteurs reliés quand la demande le justifie') ?></span>
                                        </span>
                                        <label class="ios-switch">
                                            <input type="checkbox" id="connectorsAutoToggle">
                                            <span class="ios-switch-slider"></span>
                                        </label>
                                    </li>
                                <?php endif; ?>
                                <?php foreach ($foldRows as $c):
                                    $notConnected = !$c['connected'];
                                    // Compte relié un jour, jeton révoqué depuis par le fournisseur
                                    // (data-reason="reconnect") : la ligne reste grisée comme pour un
                                    // compte jamais relié, mais elle dit POURQUOI — pastille ambre,
                                    // mention — et mène droit à la carte à reconnecter (chat.js).
                                    $reconnect = $notConnected && in_array($c['key'], $connectorsToReconnect ?? [], true);
                                    $service   = t(ConnectorRegistry::label($c['key']));
                                ?>
                                <li class="plus-menu-row <?= $notConnected ? 'is-disabled' : '' ?><?= $reconnect ? ' needs-reconnect' : '' ?>" id="<?= $c['id'] ?>"
                                    data-connector="<?= htmlspecialchars($c['key']) ?>"
                                    data-label="<?= htmlspecialchars($c['label']) ?>"
                                    data-service="<?= htmlspecialchars($service) ?>"
                                    data-disabled="<?= $notConnected ? '1' : '0' ?>"
                                    data-reason="<?= $reconnect ? 'reconnect' : ($notConnected ? 'connect' : '') ?>"
                                    title="<?= htmlspecialchars($reconnect ? t('La connexion à {service} a expiré ou a été révoquée — cliquer pour la reconnecter (Réglages → Comptes connectés)', ['service' => $service]) : ($notConnected ? $c['connect'] : $c['title'])) ?>">
                                    <span class="plus-menu-icon"><?= $c['icon'] ?><?php if ($reconnect): ?><span class="reconnect-dot" aria-hidden="true"></span><?php endif; ?></span>
                                    <span class="plus-menu-info">
                                        <strong><?= htmlspecialchars($c['label']) ?></strong>
                                        <span class="plus-menu-hint"><?= htmlspecialchars($reconnect ? t('Connexion expirée — cliquer pour reconnecter') : ($notConnected ? t('À connecter dans Réglages → Comptes connectés') : $c['hint'])) ?></span>
                                    </span>
                                    <label class="ios-switch">
                                        <input type="checkbox" id="<?= $c['toggle'] ?>" <?= $notConnected ? 'disabled' : '' ?>>
                                        <span class="ios-switch-slider"></span>
                                    </label>
                                </li>
                                <?php endforeach; ?>
                                <?php $renderLinkRow($linkRows); ?>
                                    </ul>
                                </li>
                                <?php else: ?>
                                <?php $renderLinkRow($linkRows); ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    <?php /* Connecteurs allumés, menu fermé : leurs logos à côté du « + ».
                             Rempli par chat.js depuis les interrupteurs (source unique) ; un
                             clic ouvre le menu sur le volet « Connecteurs ». */ ?>
                    <button type="button" class="composer-connectors-chip" id="connectorsChip" hidden></button>
                    <!-- Chip outil actif : témoin visible menu fermé, croix pour désélectionner -->
                    <div class="composer-tool-chip" id="toolChip" hidden>
                        <span class="composer-tool-chip-name" id="toolChipName"></span>
                        <button type="button" class="composer-tool-chip-remove" id="toolChipRemove" title="<?= te('Désactiver l\'outil') ?>" aria-label="<?= te('Désactiver l\'outil') ?>">×</button>
                    </div>
                    <?php
                        // Workflows publiés, actifs et ouverts au chat : la mention
                        // « @ » les propose après les assistants (créateurs et
                        // administrateurs seulement).
                        $chatWorkflows = Workflow::canUse(Session::currentUser())
                            ? Workflow::launchableForUser((int)Session::currentUser()['id']) : [];
                    ?>
                    <?php if (!empty($profiles) || $chatWorkflows !== []): ?>
                    <?php
                        // Assistants groupés : personnels d'abord, puis organisation.
                        $composerMyProfiles  = [];
                        $composerOrgProfiles = [];
                        foreach ($profiles ?? [] as $p) {
                            if (!empty($p['is_personal'])) { $composerMyProfiles[] = $p; }
                            else { $composerOrgProfiles[] = $p; }
                        }
                    ?>
                    <div class="assistant-picker" id="assistantPicker">
                        <button type="button" class="composer-btn" id="assistantPickerBtn"
                                title="<?= te('Démarrer une conversation avec un assistant') ?>"
                                aria-haspopup="true" aria-expanded="false">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="9" width="16" height="11" rx="2"/><path d="M12 9V6"/><circle cx="12" cy="4" r="1.6"/><path d="M9 13.5v2M15 13.5v2"/></svg>
                        </button>
                        <?php
                            // Rendu factorisé : la mention « @ » du composer filtre CES
                            // lignes (même liste, même filtrage d'accès) — deux blocs
                            // recopiés dériveraient au premier attribut ajouté.
                            $renderAssistantItem = static function (array $p): void { ?>
                            <li class="assistant-picker-item" data-id="<?= (int)$p['id'] ?>"
                                data-name="<?= htmlspecialchars($p['name']) ?>" role="button" tabindex="0">
                                <?php if (!empty($p['avatar'])): ?>
                                    <img src="/assets/img/profils/<?= htmlspecialchars(basename($p['avatar'])) ?>" alt="" class="assistant-picker-avatar">
                                <?php else: ?>
                                    <span class="assistant-picker-dot" style="background:<?= htmlspecialchars($p['color'] ?? 'var(--accent)') ?>"></span>
                                <?php endif; ?>
                                <span class="assistant-picker-info">
                                    <strong><?= htmlspecialchars($p['name']) ?></strong>
                                    <?php if (!empty($p['description'])): ?><span><?= htmlspecialchars($p['description']) ?></span><?php endif; ?>
                                </span>
                                <?php if (!empty($p['kb_count'])): ?><span class="badge badge-gray assistant-picker-badge" title="<?= te('Assistant relié à une base de connaissances') ?>"><?= te('RAG') ?></span><?php endif; ?>
                            </li>
                        <?php }; ?>
                        <ul class="assistant-picker-list" id="assistantPickerList" style="display:none">
                            <li class="picker-section-label picker-section-first" id="assistantPickerHeader"><?= te('Démarrer avec un assistant') ?></li>
                            <?php if (!empty($composerMyProfiles)): ?>
                            <li class="picker-section-label"><?= te('👤 Mes assistants personnels') ?></li>
                            <?php foreach ($composerMyProfiles as $p) { $renderAssistantItem($p); } ?>
                            <?php endif; ?>
                            <?php if (!empty($composerOrgProfiles)): ?>
                            <li class="picker-section-label"><?= te('🏢 Assistants de l\'organisation') ?></li>
                            <?php foreach ($composerOrgProfiles as $p) { $renderAssistantItem($p); } ?>
                            <?php endif; ?>
                            <?php if ($chatWorkflows !== []): ?>
                            <li class="picker-section-label"><?= te('Lancer un workflow') ?></li>
                            <?php foreach ($chatWorkflows as $w): ?>
                            <li class="assistant-picker-item assistant-picker-workflow" data-workflow-id="<?= (int)$w['id'] ?>"
                                data-name="<?= htmlspecialchars($w['name']) ?>" role="button" tabindex="0">
                                <span class="assistant-picker-wf" style="--wf-color:<?= htmlspecialchars($w['color']) ?>"><?= Workflow::icon(15) ?></span>
                                <span class="assistant-picker-info">
                                    <strong><?= htmlspecialchars($w['name']) ?></strong>
                                    <?php if ((string)$w['published']['objective'] !== ''): ?><span><?= htmlspecialchars((string)$w['published']['objective']) ?></span><?php endif; ?>
                                </span>
                                <span class="badge badge-gray assistant-picker-badge"><?= te('Workflow') ?></span>
                            </li>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <li class="assistant-picker-empty" id="assistantPickerEmpty" hidden><?= te('Aucun assistant ne correspond') ?></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($chatKnowledgeBases)): ?>
                    <?php
                        // Sélecteur de bases de connaissances. Rendu SEULEMENT si
                        // l'utilisateur en possède ou en reçoit au moins une : un
                        // contrôle qui ne peut que dire « rien à choisir » est du
                        // bruit, et coûte un cran sur une rangée qui défile déjà
                        // en mobile (même logique que le sélecteur d'assistants).
                        // Corollaire technique : sa présence ne dépend JAMAIS de
                        // la conversation, donc switchConversation n'a ni à le
                        // créer ni à le détruire.
                        //
                        // ARIA : convention de #plusMenuPanel — attribut `hidden`
                        // sur le panneau + aria-expanded sur le bouton. Les deux
                        // autres popovers du composer pilotent style.display, ce
                        // qui ne dit rien à un lecteur d'écran.
                        $kbLockedIds = array_map(
                            static fn(array $k): int => (int)$k['id'],
                            $chatProfileKbs ?? []
                        );
                    ?>
                    <div class="kb-picker" id="kbPicker">
                        <button type="button" class="composer-btn kb-picker-btn" id="kbPickerBtn"
                                title="<?= te('Bases de connaissances à interroger') ?>"
                                aria-haspopup="true" aria-expanded="false" aria-controls="kbPickerPanel">
                            <?php /* Même tracé que $kbIcon de templates/partials/admin-nav.php
                                     (livre ouvert) : un seul vocabulaire visuel pour les bases,
                                     de l'administration au composer. Les deux sont à garder
                                     synchronisés. */ ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                            <span class="kb-picker-count" id="kbPickerCount" hidden></span>
                        </button>
                        <div class="kb-picker-panel" id="kbPickerPanel" hidden>
                            <ul class="kb-picker-list" id="kbPickerList">
                                <li class="picker-section-label picker-section-first"><?= te('Bases de connaissances') ?></li>

                                <?php /* Bases de l'ASSISTANT : cochées, verrouillées, rendues en
                                        premier. AVANT le dédoublonnage, car elles peuvent être hors
                                        du périmètre personnel de l'utilisateur. */ ?>
                                <?php foreach (($chatProfileKbs ?? []) as $kbRow): ?>
                                <li class="kb-picker-row is-locked" data-kb-id="<?= (int)$kbRow['id'] ?>"
                                    data-locked="1" data-disabled="1"
                                    data-name="<?= htmlspecialchars(mb_strtolower((string)$kbRow['name'])) ?>"
                                    title="<?= te('Base de l\'assistant : toujours interrogée, elle ne se retire pas ici.') ?>">
                                    <label class="kb-picker-label">
                                        <?php /* `disabled` : une case désactivée n'est jamais soumise —
                                                sans effet ici, la charge utile est construite en JS depuis
                                                les data-kb-id. Ne pas le « corriger » en le retirant. */ ?>
                                        <input type="checkbox" checked disabled>
                                        <span class="kb-picker-meta">
                                            <span class="kb-picker-name"><?= htmlspecialchars((string)$kbRow['name']) ?></span>
                                            <span class="kb-picker-sub"><?= te('Fournie par l\'assistant') ?></span>
                                        </span>
                                    </label>
                                    <span class="kb-picker-badge kb-picker-badge-lock"><?= te('Assistant') ?></span>
                                </li>
                                <?php endforeach; ?>

                                <?php foreach ($chatKnowledgeBases as $kbRow):
                                    if (in_array((int)$kbRow['id'], $kbLockedIds, true)) { continue; }
                                    // SOURCE UNIQUE du grisé : le contrôleur valide avec la
                                    // même méthode, une seconde règle ici afficherait une
                                    // case cochable que le serveur refuserait.
                                    $kbReason  = KnowledgeBase::unavailableReason($kbRow);
                                    $kbOff     = $kbReason !== null;
                                    $kbChecked = !$kbOff && in_array((int)$kbRow['id'], $chatSelectedKbIds ?? [], true);
                                    $kbDocs    = (int)($kbRow['doc_count'] ?? 0);
                                    // Base en direct (MyBrain) : pas de documents à compter, un
                                    // cerveau interrogé à chaque message.
                                    $kbLive    = KnowledgeBase::isRemote($kbRow);
                                ?>
                                <li class="kb-picker-row<?= $kbOff ? ' is-unavailable' : '' ?>"
                                    data-kb-id="<?= (int)$kbRow['id'] ?>" data-locked="0"
                                    data-disabled="<?= $kbOff ? '1' : '0' ?>"
                                    data-reason="<?= htmlspecialchars((string)$kbReason) ?>"
                                    data-name="<?= htmlspecialchars(mb_strtolower((string)$kbRow['name'])) ?>"
                                    title="<?= htmlspecialchars($kbOff
                                            ? t((string)$kbReason) . t(' — cette base ne peut pas encore être interrogée.')
                                            : ($kbLive && trim((string)($kbRow['description'] ?? '')) === ''
                                                ? t('Cerveau MyBrain de l\'équipe, interrogé à chaque message : les réponses citent ses fiches.')
                                                : (string)($kbRow['description'] ?? $kbRow['name']))) ?>">
                                    <label class="kb-picker-label">
                                        <input type="checkbox"<?= $kbOff ? ' disabled' : '' ?><?= $kbChecked ? ' checked' : '' ?>>
                                        <span class="kb-picker-meta">
                                            <span class="kb-picker-name"><?php if ($kbLive): ?><img class="kb-picker-live-ico" src="/assets/img/mybrain.png" alt=""><?php endif; ?><?= htmlspecialchars((string)$kbRow['name']) ?></span>
                                            <span class="kb-picker-sub<?= $kbOff ? ' kb-picker-sub-warn' : '' ?>">
                                                <?= $kbOff
                                                    ? te((string)$kbReason)
                                                    : ($kbLive
                                                        ? te('Interrogé en direct')
                                                        : $kbDocs . ' document' . ($kbDocs > 1 ? 's' : '')) ?>
                                            </span>
                                        </span>
                                    </label>
                                    <?php if ($kbLive): ?>
                                    <span class="kb-picker-badge kb-picker-badge-live">MyBrain</span>
                                    <?php elseif (!empty($kbRow['is_shared'])): ?>
                                    <span class="kb-picker-badge"><?= te('Partagée') ?></span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>

                                <li class="kb-picker-empty" id="kbPickerEmpty" hidden><?= te('Aucune base ne correspond') ?></li>
                                <li class="kb-picker-hint" id="kbPickerHint"><?= te('{n} bases maximum', ['n' => ConversationKnowledgeBase::MAX_SELECTED]) ?></li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                </div><!-- /.input-actions-left -->
                <div class="input-actions-right">
                    <div class="llm-picker llm-inline-picker" id="llmInlinePickerWrap" <?= $profileLocked ? 'style="display:none"' : '' ?>>
                            <button type="button" class="llm-picker-btn" id="llmPickerBtn" title="<?= te('Choisir le modèle LLM') ?>">
                                <img src="<?= htmlspecialchars($logos[$currentProvider] ?? '') ?>" alt="" class="llm-icon">
                                <span id="llmPickerLabel"><?= htmlspecialchars($llms[$currentLlm] ?? '') ?></span>
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <ul class="llm-picker-list" id="llmPickerList" style="display:none">
                                <li class="llm-picker-list-header"><?= te('Coût LLM (pas performance)') ?></li>
                                <?php $lastGroup = null; foreach ($llms as $key => $label):
                                    $keyProvider = LLMFactory::provider($key);
                                    if ($keyProvider !== $lastGroup):
                                        $lastGroup = $keyProvider; ?>
                                    <li class="llm-picker-list-header llm-picker-group"><?= htmlspecialchars(LLMFactory::providerLabel($keyProvider)) ?></li>
                                    <?php endif; ?>
                                    <li class="llm-picker-item <?= $key === $currentLlm ? 'active' : '' ?>"
                                        data-value="<?= htmlspecialchars($key) ?>"
                                        data-logo="<?= htmlspecialchars($logos[$keyProvider] ?? '') ?>"
                                        data-cost="<?= (int)($llmStars[$key] ?? 0) ?>"
                                        data-cost-title="<?= htmlspecialchars($llmCostTitle((string)$key)) ?>"
                                        data-label="<?= htmlspecialchars($label) ?>">
                                        <img src="<?= htmlspecialchars($logos[$keyProvider] ?? '') ?>" alt="" class="llm-icon">
                                        <span class="llm-picker-item-label"><?= htmlspecialchars($label) ?></span>
                                        <?php if (!empty($llmStars[$key])): ?>
                                        <span class="llm-cost-stars" title="<?= htmlspecialchars($llmCostTitle((string)$key)) ?>"><?= str_repeat('€', $llmStars[$key]) ?><?= str_repeat('·', 5 - $llmStars[$key]) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                    </div>
                    <?php if (!empty($hasOpenaiKey) && !empty($micEnabled)): ?>
                    <button type="button" class="btn-icon btn-mic" id="micBtn" title="<?= te('Dicter le message') ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="3" width="6" height="12" rx="3"/>
                            <path d="M5 11a7 7 0 0 0 14 0"/>
                            <line x1="12" y1="18" x2="12" y2="22"/>
                            <line x1="8" y1="22" x2="16" y2="22"/>
                        </svg>
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($hasOpenaiKey) && !empty($voiceChatEnabled)): ?>
                    <!-- Conversation vocale (à côté du micro) : onde sonore —
                         cinq barres arrondies centrées sur l'axe, dessinées à
                         la main (aucune icône tierce). Accordée au MICRO qui la
                         précède : même trait (2), mêmes caps arrondis et même
                         empreinte horizontale (x 5 → 19) — plus large ou plus
                         dense, elle pèserait deux fois le voisin dans la
                         pilule. Réglage admin voice_chat_enabled — bouton
                         absent si coupé. -->
                    <button type="button" class="btn-icon btn-voice" id="voiceChatBtn" title="<?= te('Converser à la voix') ?>" aria-pressed="false">
                        <svg class="voice-wave-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M5 8.5v7"/>
                            <path d="M8.5 5.5v13"/>
                            <path d="M12 3.5v17"/>
                            <path d="M15.5 7.5v9"/>
                            <path d="M19 6v12"/>
                        </svg>
                    </button>
                    <?php endif; ?>
                    <button type="submit" class="btn-send" id="sendBtn" title="<?= te('Envoyer') ?>" disabled>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                    <button type="button" class="btn-send btn-stop" id="stopBtn" title="<?= te('Arrêter la génération') ?>" style="display:none">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><rect x="6" y="6" width="12" height="12" rx="1.5"/></svg>
                    </button>
                </div><!-- /.input-actions-right -->
            </div><!-- /.input-actions -->
            </div><!-- /#inputBox -->
        </form>
    </div>

    <!-- Sur mobile, le footer se réduit à UNE ligne compacte : le préfixe
         « Fièrement propulsé par » et les libellés longs sont masqués en CSS
         (.footer-powered / .footer-long ↔ .footer-short). -->
    <footer class="chat-footer">
        <div class="chat-footer-line">
            <span class="footer-powered"><?= te('Fièrement propulsé par') ?> </span><strong><?= te('MyAlfred.ai') ?></strong> &mdash;
            <button type="button" id="versionBtn" class="chat-footer-link"
                    title="<?= te('Voir les dernières améliorations') ?>"
                    style="background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer;text-decoration:underline dotted;text-underline-offset:2px">
                v<?= htmlspecialchars(APP_VERSION) ?>
            </button>
            <?php $lienRetour = (string) Settings::get('feedback_url', ''); ?>
            <?php if ($lienRetour !== ''): ?>
            · <a href="<?= htmlspecialchars($lienRetour) ?>" target="_blank" rel="noopener" class="chat-footer-link"><span class="footer-long"><?= te('Déclarer une idée ou un bug') ?></span><span class="footer-short"><?= te('Idée ou bug') ?></span></a>
            <?php endif; ?>
            · <a href="/a-propos" class="chat-footer-link"><?= te('À propos') ?></a>
        </div>
    </footer>
</main>

<?php require ROOT . '/templates/partials/whats-new-modal.php'; ?>

<!-- Modale quota épuisé -->
<div class="modal" id="quotaModal" style="display:none">
    <div class="modal-box" style="max-width:420px;text-align:center">
        <div style="font-size:40px;margin-bottom:12px">🚫</div>
        <?php $quotaResetLabel = te('1er') . ' ' . htmlspecialchars(Format::monthLabel(date('Y-m', strtotime('first day of next month')))); ?>
        <!-- Deux raisons, deux gestes : le quota PERSONNEL (un admin l'augmente)
             ou le budget IA de l'ORGANISATION (un admin accorde une rallonge).
             showQuotaModal(reason) montre le bloc de la raison reçue. -->
        <div data-quota-reason="quota">
            <h3 style="margin-bottom:12px"><?= te('Quota mensuel atteint') ?></h3>
            <p style="color:var(--text-muted);font-size:14px;line-height:1.6;margin-bottom:20px">
                <?= te('Vous avez consommé l\'intégralité de votre quota mensuel de crédits.') ?><br>
                <?= te('Contactez un administrateur pour l\'augmenter ou attendez le') ?>
                <strong><?= $quotaResetLabel ?></strong>.
            </p>
        </div>
        <div data-quota-reason="budget" hidden>
            <h3 style="margin-bottom:12px"><?= te('Service IA suspendu') ?></h3>
            <p style="color:var(--text-muted);font-size:14px;line-height:1.6;margin-bottom:20px">
                <?= te('Le budget IA mensuel de votre organisation est atteint : les nouvelles demandes sont suspendues jusqu\'au') ?>
                <strong><?= $quotaResetLabel ?></strong>.<br>
                <?php if (Session::can('costs.edit')): ?>
                    <a href="/admin/budget"><?= te('Accorder une rallonge ou relever le budget') ?></a>
                <?php else: ?>
                    <?= te('Contactez un administrateur si c\'est urgent.') ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="modal-actions" style="justify-content:center">
            <button class="btn-primary" id="quotaModalClose"><?= te('Fermer') ?></button>
        </div>
    </div>
</div>

<!-- Modale « connecteur / modèle incompatible » : le modèle courant ne sait pas
     piloter le function-calling, on propose de basculer sur un modèle qui le sait.
     Refuser est un choix valide — le connecteur reste simplement éteint. -->
<div class="modal" id="connectorLlmModal" style="display:none">
    <div class="modal-box modal-box-compact" style="max-width:460px;width:calc(92vw / var(--ui-zoom, 1))">
        <h3 style="margin:0 0 6px;font-size:15px"><?= te('Ce modèle ne gère pas les connecteurs') ?></h3>
        <p class="modal-subtitle" id="connectorLlmMsg" style="margin:0 0 12px;font-size:13px;line-height:1.5"></p>
        <ul class="llm-picker-list connector-llm-list" id="connectorLlmList"></ul>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" id="connectorLlmCancel"><?= te('Garder ce modèle') ?></button>
            <button type="button" class="btn-primary" id="connectorLlmConfirm" disabled><?= te('Changer de modèle') ?></button>
        </div>
    </div>
</div>

<!-- Modale configuration vidéo -->
<div class="modal" id="videoConfigModal" style="display:none">
    <div class="modal-box" style="max-width:460px">
        <h3><?= te('Paramètres de la vidéo') ?></h3>
        <p class="modal-subtitle">
            <?= te('Outil sélectionné:') ?> <strong id="videoConfigToolName"><?= te('Vidéo') ?></strong>
        </p>
        <form id="videoConfigForm" class="new-chat-form-block">
            <label>
                <?= te('API fournisseur') ?>
                <input type="text" id="videoConfigProvider" class="form-input" readonly>
            </label>
            <label>
                <?= te('Modèle') ?>
                <select id="videoConfigModel" class="form-select"></select>
            </label>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <label style="flex:1;min-width:140px">
                    <?= te('Durée') ?>
                    <select id="videoConfigSeconds" class="form-select"></select>
                </label>
                <label style="flex:1;min-width:140px">
                    <?= te('Taille') ?>
                    <select id="videoConfigSize" class="form-select"></select>
                </label>
            </div>
            <label>
                <?= te('Format de sortie') ?>
                <select id="videoConfigFormat" class="form-select"></select>
            </label>
            <!-- Section image (affichée conditionnellement selon les capacités du provider) -->
            <div id="videoImageSection" style="display:none">
                <div style="border-top:1px solid var(--border);margin:4px 0 12px"></div>
                <label style="margin-bottom:6px;font-weight:500;display:block"><?= te('Image de référence') ?> <span style="font-weight:400;color:var(--text-muted);font-size:12px"><?= te('(optionnel)') ?></span></label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                    <label class="video-image-role-label" style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer">
                        <input type="radio" name="video_image_role_radio" value="none" checked> <?= te('Aucune') ?>
                    </label>
                    <label class="video-image-role-label" style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer">
                        <input type="radio" name="video_image_role_radio" value="first_frame"> <?= te('Image de début') ?>
                    </label>
                    <label class="video-image-role-label" style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer">
                        <input type="radio" name="video_image_role_radio" value="last_frame"> <?= te('Image de fin') ?>
                    </label>
                </div>
                <div id="videoImagePicker" style="display:none">
                    <label style="display:block;cursor:pointer">
                        <div id="videoImageDropZone" style="border:1.5px dashed var(--border);border-radius:8px;padding:14px;text-align:center;font-size:13px;color:var(--text-muted);transition:border-color .15s;cursor:pointer">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:6px;display:block;margin-inline:auto"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            <span id="videoImageDropLabel"><?= te('Cliquer ou glisser une image ici') ?></span>
                        </div>
                        <input type="file" id="videoImageInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
                    </label>
                    <div id="videoImagePreview" style="display:none;margin-top:8px;position:relative">
                        <img id="videoImagePreviewImg" style="max-width:100%;max-height:120px;border-radius:6px;display:block" alt="<?= te('Aperçu') ?>">
                        <button type="button" id="videoImageClear" style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,.6);border:none;color:#fff;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:14px;line-height:1;display:flex;align-items:center;justify-content:center">&times;</button>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="videoConfigCancel"><?= te('Annuler') ?></button>
                <button type="submit" class="btn-primary"><?= te('Générer') ?></button>
            </div>
        </form>
    </div>
</div>

<?php
$allModelCatalog = LLMFactory::models();
$llmInfoData     = [];
$videoToolCapabilities = [];
foreach (LLMFactory::available() as $llmKey => $llmLabel) {
    if ($llmKey === 'local') {
        $localRow = ApiKey::findByProvider('local');
        $llmInfoData[$llmKey] = [
            'label'      => $localRow['model'] ?? t('LLM Local'),
            'provider'   => LLMFactory::providerLabel('local'),
            'version'    => $localRow['model'] ?? 'local',
            'logo'       => LLMFactory::logos()['local'] ?? '',
            'tags'       => ['OpenAI-compatible'],
            'limits'     => [t('Pas de recherche web native')],
            'web_native' => false,
            'image_gen'  => false,
            // Case de l'administrateur (Clés API → LLM local).
            'tool_use'   => LlmCatalog::supportsToolUse('local', (string)($localRow['model'] ?? '')),
        ];
    } elseif (str_contains($llmKey, '::')) {
        // Nouveau format slug : "provider::model"
        [$provider, $modelId] = explode('::', $llmKey, 2);
        $info = $allModelCatalog[$provider][$modelId] ?? null;
        $llmInfoData[$llmKey] = [
            'label'      => $info['label'] ?? $modelId,
            'provider'   => LLMFactory::providerLabel($provider),
            'version'    => $modelId,
            'logo'       => LLMFactory::logos()[$provider] ?? '',
            'tags'       => $info['tags']  ?? [],
            'limits'     => $info['limits'] ?? [],
            'web_native' => $info['web_native'] ?? false,
            'image_gen'  => ($provider === 'openai'),
            'tool_use'   => LlmCatalog::supportsToolUse($provider, $modelId),
            // « Version soignée » sur la carte d'une présentation ou d'un classeur.
            'native_pptx' => LlmCatalog::supportsNativePptx($provider, $modelId),
        ];
    } else {
        // Ancien format fallback (provider seul)
        $modelId = ApiKey::getModel($llmKey) ?: LLMFactory::defaultModel($llmKey);
        $info    = $allModelCatalog[$llmKey][$modelId] ?? null;
        $llmInfoData[$llmKey] = [
            'label'      => $info['label'] ?? $modelId,
            'provider'   => LLMFactory::providerLabel($llmKey),
            'version'    => $modelId,
            'logo'       => LLMFactory::logos()[$llmKey] ?? '',
            'tags'       => $info['tags']  ?? [],
            'limits'     => $info['limits'] ?? [],
            'web_native' => $info['web_native'] ?? false,
            'image_gen'  => ($llmKey === 'openai'),
            'tool_use'   => LlmCatalog::supportsToolUse($llmKey, $modelId),
            'native_pptx' => LlmCatalog::supportsNativePptx($llmKey, $modelId),
        ];
    }
}
// Coût estimé d'une réponse et d'une recherche Internet (bandeau du modèle) :
// mêmes chiffres que le sélecteur, arrondis en ordre de grandeur.
foreach (array_keys($llmInfoData) as $llmKey) {
    $llmInfoData[$llmKey]['credits'] = !empty($llmCredits[$llmKey]) ? CreditEstimate::round((int)$llmCredits[$llmKey]) : null;
    $llmInfoData[$llmKey]['search_credits'] = !empty($llmSearchCredits[$llmKey]) ? CreditEstimate::round((int)$llmSearchCredits[$llmKey]) : null;
}
foreach (array_values($videoTools ?? []) as $videoTool) {
    $toolId = (string)($videoTool['id'] ?? '');
    if ($toolId === '') {
        continue;
    }
    $videoToolCapabilities[$toolId] = VideoGenerator::capabilitiesForTool($videoTool);
}
?>
<script>
window.LOGO_URL        = <?= json_encode(Settings::get('logo_url', '/assets/img/logo.png')) ?>;
window.USER_AVATAR_URL = <?= json_encode($currentUser['avatar_url'] ?? '') ?>;
// Sert à cloisonner les préférences locales (connecteurs) par compte
window.CURRENT_USER_ID = <?= json_encode((int)($currentUser['id'] ?? 0)) ?>;
// Tâches programmées : bouton « Refaire régulièrement » sous les réponses.
// Le serveur reste juge (la page de création refuse si l'option est coupée).
// « Refaire régulièrement », horloge de l'assistant : droit « Tâches programmées — Créer ».
window.SCHEDULES_ENABLED = <?= json_encode(ScheduledTask::allows(Session::currentUser(), 'create')) ?>;
// Workflows (créateurs et administrateurs) : « Refaire en workflow » sous un
// plan de travail, et « @ » dans la zone de saisie pour en lancer un.
// « Refaire en workflow » : créer un workflow (droit « Workflows — Créer »).
window.WORKFLOWS_USABLE = <?= json_encode(Workflow::allows(Session::currentUser(), 'create')) ?>;
window.WORKFLOW_ICON = <?= json_encode(Workflow::ICON_PATHS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
// Horloge des tâches programmées : même tracé côté serveur et côté script.
window.SCHEDULE_ICON = <?= json_encode(ScheduledTask::ICON_PATHS) ?>;
// Réglages de voix du compte (vitesse de lecture, longueur des réponses
// orales) : la vitesse est appliquée par le lecteur audio, la longueur est
// une consigne que le SERVEUR relit à chaque envoi — le front ne fait
// qu'afficher et écrire (POST /settings/voice).
window.VOICE_PREFS = <?= json_encode($voicePrefs ?? ChatController::voicePrefsPayload(null), JSON_UNESCAPED_UNICODE) ?>;
// Expressions qui font suggérer un connecteur pendant la frappe (tool-suggest.js).
// Source unique ConnectorRegistry, éditable sur /admin/connectors : la détection
// n'est plus figée dans le JavaScript.
window.CONNECTOR_TRIGGERS = <?= json_encode(ConnectorRegistry::allTriggers(), JSON_UNESCAPED_UNICODE) ?>;
// Idem pour les OUTILS de génération : source unique ToolTriggers, éditable
// sur /admin/tools (carte de chaque outil).
window.TOOL_TRIGGERS = <?= json_encode(ToolTriggers::all(), JSON_UNESCAPED_UNICODE) ?>;
// Conversation portée par un assistant : le LLM est imposé par l'assistant,
// l'utilisateur ne peut pas en changer (picker masqué côté serveur).
window.PROFILE_LOCKED_LLM = <?= json_encode($profileLocked) ?>;
window.LLM_INFO        = <?= json_encode($llmInfoData, JSON_UNESCAPED_UNICODE) ?>;
// Titres des encadrés d'erreur → genre. Seul le texte d'une erreur est
// enregistré : à la relecture, c'est son titre qui rend à l'encadré son ton
// (ambre : quelque chose à régler ; rouge : le fournisseur ne répond pas).
window.LLM_ERROR_TITLES = <?= json_encode(LlmErrorPresenter::titleKinds(), JSON_UNESCAPED_UNICODE) ?>;
// Encadré d'erreur : « Prévenir un administrateur » (genres concernés, et
// administrateurs actifs à qui écrire — nom et adresse, rien d'autre), ou,
// pour un administrateur, le lien de la page où régler la cause.
window.LLM_ERROR_ACTIONS = <?= json_encode(LlmErrorPresenter::clientActions(), JSON_UNESCAPED_UNICODE) ?>;
// « Administrateur » au sens de l'encadré d'erreur : qui peut régler la cause
// (clés API, modèles — droit « Gestion des LLM — Modifier »).
window.IS_ADMIN          = <?= json_encode(Session::can('llms.edit')) ?>;
window.ADMIN_CONTACTS    = <?= json_encode(AdminContacts::forViewer(isset($currentUser['id']) ? (int)$currentUser['id'] : null), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.APP_NAME          = <?= json_encode((string)Settings::get('app_name', 'MIA'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
// Pannes que seul le navigateur voit (dictée : canal direct avec OpenAI) —
// mêmes titres et messages que côté serveur, retrouvés par leur genre.
window.LLM_ERROR_OPENAI  = <?= json_encode(LlmErrorPresenter::clientPayloads('openai'), JSON_UNESCAPED_UNICODE) ?>;
<?php
// Outils image exposés au JS (sélecteur du « Site illustré ») : on n'expose PAS la clé
// chiffrée, et on calcule « connected » = fournisseur avec clé API enregistrée active,
// ou clé dédiée à l'outil. Sert à griser les outils non branchés côté chat.
$keyedProviders = [];
foreach (ApiKey::all() as $k) {
    if (!empty($k['key_enc']) && (int)($k['is_active'] ?? 1) === 1) {
        $keyedProviders[strtolower((string)$k['provider'])] = true;
    }
}
$imageToolsJs = array_map(static function (array $t) use ($keyedProviders) {
    return [
        'id'        => (int)$t['id'],
        'name'      => (string)($t['name'] ?? ''),
        'provider'  => (string)($t['provider'] ?? ''),
        'model'     => (string)($t['model'] ?? ''),
        'connected' => isset($keyedProviders[strtolower((string)($t['provider'] ?? ''))]) || !empty($t['api_key_enc']),
        // Raison (traduite) quand le modèle est arrêté par son fournisseur :
        // la popup « Site illustré » grise la ligne et l'affiche ; '' sinon.
        'retired'   => (string)(ChatTool::retiredNotice($t) ?? ''),
    ];
}, array_values($imageTools ?? []));
?>
window.IMAGE_TOOLS     = <?= json_encode($imageToolsJs, JSON_UNESCAPED_UNICODE) ?>;
window.IMAGE_STYLES    = <?= json_encode(ToolCatalog::imageStyles(), JSON_UNESCAPED_UNICODE) ?>;
window.HTML_TOOLS      = <?= json_encode(array_values($htmlTools  ?? []), JSON_UNESCAPED_UNICODE) ?>;
window.VIDEO_TOOLS     = <?= json_encode(array_values($videoTools ?? []), JSON_UNESCAPED_UNICODE) ?>;
window.MAP_TOOLS       = <?= json_encode(array_values($mapTools   ?? []), JSON_UNESCAPED_UNICODE) ?>;
window.PRESENTATION_TOOLS = <?= json_encode(array_values($presentationTools ?? []), JSON_UNESCAPED_UNICODE) ?>;
<?php
// Thèmes et options proposés par le volet présentation : lus depuis DeckSpec
// (source unique côté serveur) plutôt que redéclarés en JavaScript.
$deckThemesJs = [];
foreach (DeckSpec::THEMES as $key => $theme) {
    $deckThemesJs[] = [
        'key'    => $key,
        'label'  => $theme['label'],
        'bg'     => $theme['bg'],
        'text'   => $theme['text'],
        'muted'  => $theme['muted'],
        'accent' => $theme['accent'],
    ];
}
// Mise en forme de l'éditeur : mêmes listes fermées côté serveur et côté volet.
// `cqw` = taille de base du cran, appliquée en direct dans l'aperçu ; le rendu
// définitif (qui réduit un texte trop long pour sa boîte) revient du serveur.
$deckSizesJs = [];
foreach (DeckSpec::SIZES as $size) {
    $deckSizesJs[] = [
        'key'   => $size,
        'label' => DeckSpec::SIZE_LABELS[$size] ?? $size,
        'cqw'   => DeckRenderer::fontSizeCqw($size),
    ];
}
$deckFontsJs = [];
foreach (DeckSpec::FONTS as $key => $font) {
    $deckFontsJs[] = ['key' => $key, 'label' => $font['label'], 'css' => $font['css']];
}
?>
window.DECK_THEMES     = <?= json_encode($deckThemesJs, JSON_UNESCAPED_UNICODE) ?>;
window.DECK_SIZES      = <?= json_encode($deckSizesJs, JSON_UNESCAPED_UNICODE) ?>;
window.DECK_FONTS      = <?= json_encode($deckFontsJs, JSON_UNESCAPED_UNICODE) ?>;
window.DECK_DEFAULTS   = <?= json_encode([
    'slide_count' => DeckSpec::DEFAULT_SLIDES,
    'min_slides'  => DeckSpec::MIN_SLIDES,
    'max_slides'  => DeckSpec::MAX_SLIDES,
], JSON_UNESCAPED_UNICODE) ?>;
window.VIDEO_TOOL_CAPABILITIES = <?= json_encode($videoToolCapabilities, JSON_UNESCAPED_UNICODE) ?>;
window.ACTIVE_PROFILE_AVATAR = <?= json_encode($toolbarAvatar ?? '') ?>;
window.DRIVE_PENDING_ACTIONS = <?= json_encode($drivePendingActions ?? [], JSON_UNESCAPED_UNICODE) ?>;
<?php
// Types d'outils liés à l'assistant actif : tool-suggest.js s'abstient de
// suggérer une image quand le LLM la génère déjà lui-même (function-calling).
$profileToolTypes = array_values(array_unique(array_map(static fn(array $t) => (string)$t['type'], $activeProfileTools ?? [])));
?>
window.PROFILE_TOOL_TYPES = <?= json_encode($profileToolTypes, JSON_UNESCAPED_UNICODE) ?>;
window.TOOL_TYPE_ICONS = <?= json_encode($toolIcons, JSON_UNESCAPED_UNICODE) ?>;
<?php // Sélecteur de bases du composer. `max` vient de la constante PHP : un 3
      // en dur côté JavaScript ferait mentir l'écran le jour où le plafond bouge.
      // `profile` porte les NOMS pour que le sélecteur puisse réinjecter les
      // lignes verrouillées au changement de conversation (elles peuvent être
      // hors du périmètre personnel, donc absentes du DOM rendu ici). ?>
window.KB_PICKER = <?= json_encode([
    'max'      => ConversationKnowledgeBase::MAX_SELECTED,
    'selected' => array_values(array_map('intval', $chatSelectedKbIds ?? [])),
    // Bases « cochées par défaut » pour les groupes du compte (migration 073) :
    // elles amorcent le brouillon d'une conversation pas encore créée.
    'defaults' => array_values(array_map('intval', $chatDefaultKbIds ?? [])),
    'profile'  => array_map(
        static fn(array $k): array => ['id' => (int)$k['id'], 'name' => (string)$k['name']],
        $chatProfileKbs ?? []
    ),
], JSON_UNESCAPED_UNICODE) ?>;
<?php // Idées d'utilisation de la zone de saisie vide (composer-hints.js) :
      // seulement ce que la personne peut réellement faire — outils en service,
      // connecteurs reliés, bases interrogeables, droits de son rôle —, depuis
      // les listes déjà calculées pour le menu « + » et le sélecteur de bases.
    $hintUser = Session::currentUser();
    $hintKb   = !empty($chatProfileKbs);
    foreach (($chatKnowledgeBases ?? []) as $hintKbRow) {
        if (KnowledgeBase::unavailableReason($hintKbRow) === null) { $hintKb = true; break; }
    }
?>
window.COMPOSER_HINTS = <?= json_encode(ComposerHints::forChat([
    'tools'      => ComposerHints::usableToolTypes([
        'image' => $imageTools ?? [], 'html' => $htmlTools ?? [], 'map' => $mapTools ?? [], 'video' => $videoTools ?? [],
    ]),
    'connectors' => array_values(array_column(array_filter(
        $connectorRows ?? [], static fn(array $c): bool => !empty($c['connected'])
    ), 'key')),
    'web'        => !empty($webFeatureEnabled),
    'kb'         => $hintKb,
    'schedule'   => ScheduledTask::allows($hintUser, 'create'),
    'workflow'   => Workflow::allows($hintUser, 'create'),
    'assistant'  => AssistantDraft::canCreate($hintUser),
    'company'    => CompanyProfile::name(),
]), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
<?php // Suggestion de suite après une réponse (FollowUpSuggestion, Tab pour la
      // reprendre) : textes du calque ; null quand l'administrateur l'a coupée. ?>
window.COMPOSER_SUGGEST = <?= json_encode(FollowUpSuggestion::isEnabled() ? ComposerHints::suggestionTexts() : null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
<?php // Fichiers livrés dans la conversation : formats, libellés (déjà traduits)
      // et formats de rechange viennent du catalogue serveur — generated-files.js
      // n'en recopie aucun. ?>
window.FILE_FORMATS = <?= json_encode(FileFormats::clientCatalog(), JSON_UNESCAPED_UNICODE) ?>;
<?php // Choix proposés en boutons : langages de bloc et bornes du contrat
      // (ChoiceButtons) — choice-buttons.js n'en recopie aucun. ?>
window.CHOICE_BUTTONS = <?= json_encode(ChoiceButtons::clientConfig(), JSON_UNESCAPED_UNICODE) ?>;
<?php // « Version soignée › Par Gamma » des cartes de présentation : un outil Gamma
      // est ouvert à ce compte (le serveur re-choisit l'outil au clic). ?>
window.FILE_GAMMA = <?= !empty($gammaForFiles) ? 'true' : 'false' ?>;
<?php // Bouton « Version soignée » : un modèle permis au compte sait fabriquer
      // une présentation ou un classeur lui-même (le serveur choisit lequel). ?>
window.FILE_NATIVE = <?= !empty($nativeForFiles) ? 'true' : 'false' ?>;
<?php // Modèle PowerPoint de l'entreprise : la carte d'une présentation dit
      // « au modèle de … » (null = aucun modèle, la mise en page est celle de MIA). ?>
window.FILE_DECK_TEMPLATE = <?= json_encode(CompanyProfile::templatePath() !== null ? CompanyProfile::name() : null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.CONVERSATION_CREDITS_TOTAL = <?= json_encode((int)($conversationCreditsTotal ?? 0)) ?>;
window.CREDIT_QUOTA_STATUS = <?= json_encode($creditQuotaStatus, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/marked.min.js"></script>
<script src="/assets/js/mermaid.min.js"></script>
<?php // Après marked (il y branche le rendu des cartes de fichier), AVANT chat.js
      // (qui rend les messages dès son chargement). ?>
<?php $filesJsVersion = @filemtime(ROOT . '/public/assets/js/generated-files.js') ?: time(); ?>
<script src="/assets/js/generated-files.js?v=<?= (int)$filesJsVersion ?>"></script>
<?php // Après generated-files.js (il en reprend la lecture d'un bloc refermé),
      // AVANT chat.js (qui rend les messages dès son chargement). ?>
<?php $choicesJsVersion = @filemtime(ROOT . '/public/assets/js/choice-buttons.js') ?: time(); ?>
<script src="/assets/js/choice-buttons.js?v=<?= (int)$choicesJsVersion ?>"></script>
<?php $userMenuJsVersion = @filemtime(ROOT . '/public/assets/js/user-menu.js') ?: time(); ?>
<script src="/assets/js/user-menu.js?v=<?= (int)$userMenuJsVersion ?>"></script>
<?php $navDrawerJsVersion = @filemtime(ROOT . '/public/assets/js/nav-drawer.js') ?: time(); ?>
<script src="/assets/js/nav-drawer.js?v=<?= (int)$navDrawerJsVersion ?>"></script>
<script src="/assets/js/vendor/leaflet/leaflet.js"></script>
<script src="/assets/js/vendor/leaflet/leaflet-simple-map-screenshoter.js"></script>
<?php if (!empty($hasOpenaiKey) && !empty($voiceChatEnabled)): ?>
<?php /* Reconnaissance des ordres dits en conversation vocale (« approuve », « envoie-le », « stop ») :
         module pur, chargé AVANT chat.js qui le lit à l'initialisation. */ ?>
<?php $voiceCommandsJsVersion = @filemtime(ROOT . '/public/assets/js/voice-commands.js') ?: time(); ?>
<script src="/assets/js/voice-commands.js?v=<?= (int)$voiceCommandsJsVersion ?>"></script>
<?php endif; ?>
<?php if (Workflow::canUse(Session::currentUser())): ?>
<?php /* Fenêtre de lancement d'un workflow (« @ » dans la zone de saisie). */ ?>
<?php $wfJsVersion = @filemtime(ROOT . '/public/assets/js/workflows.js') ?: time(); ?>
<script src="/assets/js/workflows.js?v=<?= (int)$wfJsVersion ?>"></script>
<?php endif; ?>
<?php $chatJsVersion = @filemtime(ROOT . '/public/assets/js/chat.js') ?: time(); ?>
<script src="/assets/js/chat.js?v=<?= (int)$chatJsVersion ?>"></script>
<?php // Après chat.js : il lit le texte d'aide du champ, que chat.js raccourcit sur téléphone. ?>
<?php $composerHintsJsVersion = @filemtime(ROOT . '/public/assets/js/composer-hints.js') ?: time(); ?>
<script src="/assets/js/composer-hints.js?v=<?= (int)$composerHintsJsVersion ?>"></script>
<?php $toolSuggestJsVersion = @filemtime(ROOT . '/public/assets/js/tool-suggest.js') ?: time(); ?>
<script src="/assets/js/tool-suggest.js?v=<?= (int)$toolSuggestJsVersion ?>"></script>

<!-- Viewer HTML (panneau droit) -->
<div id="htmlViewer" class="html-viewer">
    <!-- Poignée de redimensionnement (bord gauche) -->
    <div class="html-viewer-resizer" id="htmlViewerResizer" title="<?= te('Glisser pour redimensionner · double-clic pour réinitialiser') ?>"></div>
    <div class="html-viewer-bar">
        <!-- Icône page HTML -->
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:var(--accent)"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        <span class="html-viewer-title" id="htmlViewerTitle"><?= te('Page HTML') ?></span>

        <!-- Onglets Code / Aperçu -->
        <div class="html-viewer-tabs">
            <button class="html-viewer-tab" id="htmlTabCode">
                <!-- Icône </> code -->
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="margin-right:5px;vertical-align:-2px"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg><?= te('Code') ?>
            </button>
            <button class="html-viewer-tab active" id="htmlTabPreview">
                <!-- Icône œil aperçu -->
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="margin-right:5px;vertical-align:-2px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><?= te('Aperçu') ?>
            </button>
        </div>

        <!-- Actions -->
        <div style="margin-left:auto;display:flex;gap:2px;align-items:center">
            <!-- Aperçu bureau / mobile -->
            <div class="html-viewer-device" role="group" aria-label="<?= te('Format d\'aperçu') ?>">
                <button class="html-viewer-device-btn active" id="htmlDeviceDesktop" title="<?= te('Aperçu bureau') ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </button>
                <button class="html-viewer-device-btn" id="htmlDeviceMobile" title="<?= te('Aperçu mobile') ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="2" width="12" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
                </button>
            </div>
            <!-- Indicateur de sauvegarde (mode édition) -->
            <span id="htmlViewerSaveState" class="html-save-state" style="display:none"></span>
            <!-- Basculer le mode édition du contenu -->
            <button id="htmlViewerEdit" class="html-viewer-btn" title="<?= te('Modifier le contenu de la page') ?>" style="display:none">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
            </button>
            <!-- Rafraîchir l'aperçu -->
            <button id="htmlViewerRefresh" class="html-viewer-btn" title="<?= te('Rafraîchir l\'aperçu') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            </button>
            <!-- Télécharger -->
            <button id="htmlViewerDownload" class="html-viewer-btn" title="<?= te('Télécharger le fichier HTML') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </button>
            <!-- Ouvrir dans un nouvel onglet -->
            <button id="htmlViewerNewTab" class="html-viewer-btn" title="<?= te('Ouvrir dans un nouvel onglet') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </button>
            <!-- Fermer le panneau -->
            <button id="htmlViewerClose" class="html-viewer-btn" title="<?= te('Fermer le panneau') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <!-- Loading -->
    <div id="htmlViewerLoading" class="html-viewer-loading">
        <div class="html-viewer-spinner"></div>
        <span><?= te('Génération en cours…') ?></span>
    </div>
    <!-- Code view -->
    <div id="htmlViewerCode" class="html-viewer-code" style="display:none">
        <pre id="htmlViewerCodePre"></pre>
    </div>
    <!-- Preview iframe -->
    <iframe id="htmlViewerFrame"
            title="<?= te('Aperçu de la page HTML générée') ?>"
            sandbox="allow-scripts allow-forms"
            referrerpolicy="no-referrer"
            class="html-viewer-iframe"
            style="display:none"></iframe>
    <!-- Sélecteur d'image du mode édition (fichier local ou URL) -->
    <div id="htmlImagePicker" class="html-img-picker" style="display:none">
        <div class="html-img-picker-box">
            <h4 id="htmlImagePickerTitle"><?= te('Insérer une image') ?></h4>
            <label class="btn-secondary html-img-picker-file">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:5px;vertical-align:-2px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><?= te('Depuis l\'ordinateur') ?>
                <input type="file" id="htmlImagePickerFile" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            </label>
            <div class="html-img-picker-or"><?= te('ou par URL') ?></div>
            <input type="url" id="htmlImagePickerUrl" placeholder="https://exemple.com/image.jpg">
            <div class="html-img-picker-actions">
                <button type="button" id="htmlImagePickerInsert" class="btn-primary"><?= te('Insérer') ?></button>
                <button type="button" id="htmlImagePickerCancel" class="btn-secondary"><?= te('Annuler') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Viewer carte / itinéraire (panneau droit) — réutilise les styles .html-viewer -->
<div id="mapViewer" class="html-viewer map-viewer">
    <div class="html-viewer-resizer" id="mapViewerResizer" title="<?= te('Glisser pour redimensionner · double-clic pour réinitialiser') ?>"></div>
    <div class="html-viewer-bar">
        <!-- Icône carte -->
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:var(--accent)"><polygon points="1 6 8 3 16 6 23 3 23 18 16 21 8 18 1 21"/><line x1="8" y1="3" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="21"/></svg>
        <span class="html-viewer-title" id="mapViewerTitle"><?= te('Itinéraire') ?></span>

        <!-- Actions -->
        <div style="margin-left:auto;display:flex;gap:2px;align-items:center">
            <!-- Afficher / masquer les notes -->
            <button id="mapViewerNotesToggle" class="html-viewer-btn" title="<?= te('Afficher / masquer les notes du trajet') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
            </button>
            <!-- Export PDF (carte + notes) -->
            <button id="mapViewerPdf" class="html-viewer-btn" title="<?= te('Exporter la carte et ses notes en PDF') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </button>
            <!-- Ouvrir dans Google Maps -->
            <button id="mapViewerGmaps" class="html-viewer-btn" title="<?= te('Ouvrir l\'itinéraire dans Google Maps') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </button>
            <!-- Fermer le panneau -->
            <button id="mapViewerClose" class="html-viewer-btn" title="<?= te('Fermer le panneau') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <!-- Loading -->
    <div id="mapViewerLoading" class="html-viewer-loading">
        <div class="html-viewer-spinner"></div>
        <span id="mapViewerLoadingText"><?= te('Calcul de l\'itinéraire…') ?></span>
    </div>
    <!-- Carte + notes -->
    <div id="mapViewerBody" class="map-viewer-body" style="display:none">
        <div id="mapCanvas" class="map-canvas" aria-label="<?= te('Carte de l\'itinéraire') ?>"></div>
        <div id="mapNotes" class="map-notes">
            <div class="map-notes-head">
                <strong id="mapNotesTitle"></strong>
                <span id="mapNotesStats" class="map-notes-stats"></span>
            </div>
            <p id="mapNotesSummary" class="map-notes-summary"></p>
            <div id="mapNotesWarnings" class="map-notes-warnings" style="display:none"></div>
            <ol id="mapNotesSteps" class="map-notes-steps"></ol>
            <ul id="mapNotesGeneral" class="map-notes-general"></ul>
        </div>
    </div>
</div>

<!-- Viewer vidéo (panneau droit) -->
<div id="videoViewer" class="video-viewer">
    <div class="video-viewer-bar">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:#f87171"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 16 12 10 15"/></svg>
        <span class="video-viewer-title"><?= te('Vidéo générée') ?></span>
        <div style="margin-left:auto;display:flex;gap:2px;align-items:center">
            <button id="videoViewerDownload" class="video-viewer-btn" title="<?= te('Télécharger la vidéo') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </button>
            <button id="videoViewerNewTab" class="video-viewer-btn" title="<?= te('Ouvrir dans un nouvel onglet') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </button>
            <button id="videoViewerClose" class="video-viewer-btn" title="<?= te('Fermer le panneau') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <div id="videoViewerLoading" class="video-viewer-loading">
        <div class="video-viewer-spinner"></div>
        <span><?= te('Rendu vidéo en cours… cela peut prendre 1 à 3 minutes.') ?></span>
    </div>
    <div id="videoViewerContent" class="video-viewer-content" style="display:none">
        <video id="videoViewerPlayer" class="video-viewer-player" controls playsinline></video>
        <div id="videoViewerMeta" class="video-viewer-meta"></div>
    </div>
</div>

<!-- Viewer sources Sonar Pro (panneau droit) -->
<div id="sourcesViewer" class="sources-viewer" aria-live="polite">
    <div class="sources-viewer-bar">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:#22c55e">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 1 0 7.07 7.07l1.71-1.71"/>
        </svg>
        <span class="sources-viewer-title"><?= te('Sources Sonar Pro') ?></span>
        <span class="sources-viewer-count" id="sourcesViewerCount">0</span>
        <div style="margin-left:auto;display:flex;gap:2px;align-items:center">
            <button id="sourcesViewerOpen" class="sources-viewer-btn" title="<?= te('Ouvrir la source sélectionnée dans un nouvel onglet') ?>" disabled>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </button>
            <button id="sourcesViewerCopy" class="sources-viewer-btn" title="<?= te('Copier toutes les URL des sources') ?>" disabled>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            </button>
            <button id="sourcesViewerClose" class="sources-viewer-btn" title="<?= te('Fermer le panneau') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <div id="sourcesViewerEmpty" class="sources-viewer-empty">
        <?= te('Aucune source détectée pour ce message.') ?>
    </div>
    <div id="sourcesViewerList" class="sources-viewer-list" style="display:none"></div>
</div>

<!--
  Volet Présentation (panneau droit).
  Réutilise la coque .html-viewer (largeur, resize, responsive) comme le fait
  déjà #mapViewer. Trois états successifs dans le même panneau — options, plan,
  deck — pilotés par deck.js.
-->
<div id="deckViewer" class="html-viewer deck-viewer">
    <div class="html-viewer-resizer" id="deckViewerResizer" title="<?= te('Glisser pour redimensionner (double-clic : réinitialiser)') ?>"></div>

    <div class="html-viewer-bar">
        <span class="html-viewer-title" id="deckViewerTitle"><?= te('Présentation') ?></span>
        <div style="margin-left:auto;display:flex;gap:2px;align-items:center">
            <span class="html-save-state" id="deckSaveState"></span>
            <button id="deckViewerEdit" class="html-viewer-btn" title="<?= te('Modifier les slides (déplacer, redimensionner, réécrire)') ?>" hidden>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
            </button>
            <button id="deckViewerFullscreen" class="html-viewer-btn" title="<?= te('Plein écran') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
            </button>
            <button id="deckViewerClose" class="html-viewer-btn" title="<?= te('Fermer le panneau') ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>

    <!-- Fil d'étapes : les étapes franchies sont cliquables pour revenir en arrière -->
    <div class="deck-steps" id="deckSteps" role="tablist">
        <button type="button" class="deck-step is-current" data-step="options" role="tab"><span class="deck-step-num">1</span><?= te('Options') ?></button>
        <span class="deck-step-sep" aria-hidden="true"></span>
        <button type="button" class="deck-step" data-step="outline" role="tab" disabled><span class="deck-step-num">2</span><?= te('Plan') ?></button>
        <span class="deck-step-sep" aria-hidden="true"></span>
        <button type="button" class="deck-step" data-step="deck" role="tab" disabled><span class="deck-step-num">3</span><?= te('Diaporama') ?></button>
    </div>

    <div class="deck-body">
        <!-- ÉTAPE 1 — Options -->
        <section class="deck-pane is-active" id="deckPaneOptions">
            <div class="deck-scrollarea">
                <label class="deck-field">
                    <span class="deck-field-label"><?= te('Sujet') ?></span>
                    <textarea id="deckSubject" class="deck-subject" rows="2" placeholder="<?= te('De quoi parle votre présentation ?') ?>"></textarea>
                </label>

                <div class="deck-field">
                    <span class="deck-field-label"><?= te('Nombre de slides') ?></span>
                    <div class="deck-chips" id="deckSlideChips" role="radiogroup" aria-label="<?= te('Nombre de slides') ?>"></div>
                </div>

                <div class="deck-field">
                    <span class="deck-field-label"><?= te('Longueur du texte') ?></span>
                    <div class="deck-cards" id="deckLengthCards" role="radiogroup" aria-label="<?= te('Longueur du texte') ?>"></div>
                </div>

                <div class="deck-field">
                    <span class="deck-field-label"><?= te('Ton') ?></span>
                    <div class="deck-cards deck-cards-compact" id="deckToneCards" role="radiogroup" aria-label="<?= te('Ton') ?>"></div>
                </div>

                <div class="deck-field">
                    <span class="deck-field-label"><?= te('Style visuel') ?></span>
                    <div class="deck-themes" id="deckThemeCards" role="radiogroup" aria-label="<?= te('Style visuel') ?>"></div>
                </div>

                <div class="deck-field" id="deckImagesField" hidden>
                    <div class="deck-switch-row">
                        <span class="deck-field-label" style="margin:0"><?= te('Générer des images') ?></span>
                        <label class="ios-switch">
                            <input type="checkbox" id="deckImagesToggle">
                            <span class="ios-switch-slider"></span>
                        </label>
                    </div>
                    <div class="deck-chips" id="deckImageStyleChips" role="radiogroup" aria-label="<?= te('Style d\'image') ?>" hidden></div>
                </div>
            </div>
            <div class="deck-actions">
                <button type="button" class="btn-primary deck-btn-main" id="deckGenOutline"><?= te('Générer le plan') ?></button>
            </div>
        </section>

        <!-- ÉTAPE 2 — Plan -->
        <section class="deck-pane" id="deckPaneOutline">
            <div class="deck-scrollarea">
                <div class="deck-outline-head">
                    <span class="deck-count" id="deckOutlineCount"><?= te('0 slide') ?></span>
                    <button type="button" class="deck-link" id="deckRegenOutline"><?= te('Régénérer le plan') ?></button>
                </div>
                <ol class="deck-outline" id="deckOutlineList"></ol>
                <button type="button" class="deck-add" id="deckAddSlide"><?= te('+ Ajouter une slide') ?></button>
            </div>
            <div class="deck-actions">
                <button type="button" class="btn-primary deck-btn-main" id="deckBuild"><?= te('Créer la présentation') ?></button>
            </div>
        </section>

        <!-- ÉTAPE 3 — Diaporama -->
        <section class="deck-pane" id="deckPaneDeck">
            <!-- Barre de mise en forme : visible uniquement en mode édition.
                 Elle vit dans le volet et non dans l'aperçu — l'iframe est
                 sandboxée, les contrôles natifs (couleur, listes) y seraient
                 bridés. -->
            <div class="deck-format" id="deckFormatBar" hidden>
                <button type="button" class="deck-fmt-btn deck-fmt-add" id="deckAddText" title="<?= te('Insérer une zone de texte') ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <?= te('Texte') ?>
                </button>
                <div class="deck-imgpick-wrap" id="deckImagePickWrap">
                    <button type="button" class="deck-fmt-btn deck-fmt-add" id="deckAddImage" title="<?= te('Insérer une image') ?>" aria-haspopup="true" aria-expanded="false">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <?= te('Image') ?>
                    </button>
                    <!-- Deux sources : un fichier de l'ordinateur, ou une génération
                         par l'outil image de la présentation. -->
                    <div class="deck-imgpick" id="deckImagePicker" hidden>
                        <button type="button" class="deck-imgpick-file" id="deckImageFileBtn"><?= te('Depuis mon ordinateur…') ?></button>
                        <input type="file" id="deckImageFile" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                        <div class="deck-imgpick-gen" id="deckImageGenRow">
                            <input type="text" id="deckImagePrompt" placeholder="<?= te('Décrire l\'image à générer…') ?>" maxlength="400">
                            <button type="button" class="btn-primary deck-btn-small" id="deckImageGen"><?= te('Générer') ?></button>
                        </div>
                        <span class="deck-imgpick-note" id="deckImageNote" hidden></span>
                    </div>
                </div>
                <span class="deck-fmt-sep" aria-hidden="true"></span>
                <button type="button" class="deck-fmt-btn" data-fmt="bold" title="<?= te('Gras') ?>" aria-pressed="false"><b>G</b></button>
                <button type="button" class="deck-fmt-btn" data-fmt="italic" title="<?= te('Italique') ?>" aria-pressed="false"><i>I</i></button>
                <select class="deck-fmt-select" id="deckFmtSize" title="<?= te('Taille du texte') ?>" aria-label="<?= te('Taille du texte') ?>"></select>
                <select class="deck-fmt-select" id="deckFmtFont" title="<?= te('Police') ?>" aria-label="<?= te('Police') ?>"></select>
                <label class="deck-fmt-color" title="<?= te('Couleur du texte') ?>">
                    <input type="color" id="deckFmtColor" value="#ffffff" aria-label="<?= te('Couleur du texte') ?>">
                </label>
                <button type="button" class="deck-fmt-btn" id="deckFmtColorReset" title="<?= te('Revenir à la couleur du thème') ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                </button>
            </div>
            <div class="deck-stage">
                <iframe id="deckFrame" class="deck-frame" title="<?= te('Aperçu de la présentation') ?>"
                        sandbox="allow-scripts" referrerpolicy="no-referrer"></iframe>
            </div>
            <div class="deck-filmstrip-wrap">
                <button type="button" class="deck-nav" id="deckPrev" title="<?= te('Slide précédente') ?>" aria-label="<?= te('Slide précédente') ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <div class="deck-filmstrip" id="deckFilmstrip"></div>
                <button type="button" class="deck-nav" id="deckNext" title="<?= te('Slide suivante') ?>" aria-label="<?= te('Slide suivante') ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </div>
            <div class="deck-actions deck-actions-deck">
                <span class="deck-position" id="deckPosition">—</span>
                <div class="deck-export" id="deckExportWrap">
                    <!-- Menu ouvert vers le HAUT : la barre d'actions est collée en bas du volet -->
                    <div class="deck-export-menu" id="deckExportMenu" hidden role="menu">
                        <button type="button" role="menuitem" data-format="pptx"><?= te('PowerPoint (.pptx)') ?></button>
                        <button type="button" role="menuitem" data-format="pdf"><?= te('PDF (paysage)') ?></button>
                        <button type="button" role="menuitem" data-format="html"><?= te('Page HTML') ?></button>
                    </div>
                    <button type="button" class="btn-secondary deck-btn-small" id="deckExport" aria-haspopup="true" aria-expanded="false">
                        <?= te('Exporter') ?>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="6 15 12 9 18 15"/></svg>
                    </button>
                </div>
            </div>
        </section>
    </div>

    <!-- Voile de progression, commun aux étapes longues -->
    <div class="deck-loading" id="deckLoading" hidden>
        <div class="html-viewer-spinner"></div>
        <span class="deck-loading-text" id="deckLoadingText"><?= te('Génération en cours…') ?></span>
        <!-- Barre + compteur : affichés seulement quand l'avancement est chiffrable -->
        <div class="deck-progress" id="deckProgress" hidden>
            <div class="deck-progress-track"><span class="deck-progress-fill" id="deckProgressFill"></span></div>
            <span class="deck-progress-count" id="deckProgressCount"></span>
        </div>
        <span class="deck-progress-elapsed" id="deckLoadingElapsed" hidden></span>
    </div>
</div>

<?php $deckJsVersion = @filemtime(ROOT . '/public/assets/js/deck.js') ?: time(); ?>
<script src="/assets/js/deck.js?v=<?= (int)$deckJsVersion ?>"></script>

<!-- Lightbox image : agrandissement au clic sur une image de message -->
<div class="image-lightbox" id="imageLightbox" style="display:none">
    <button class="image-lightbox-close" id="imageLightboxClose" title="<?= te('Fermer') ?>" aria-label="<?= te('Fermer') ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <?php /* Téléchargement depuis l'image agrandie : sur un écran tactile il n'y
             a pas de survol, c'est ici que le bouton se trouve le plus sûrement.
             Comportement dans generated-files.js (il intercepte le clic avant
             que la visionneuse ne se referme). */ ?>
    <button type="button" class="image-lightbox-download" id="imageLightboxDownload" title="<?= te('Télécharger l\'image') ?>" aria-label="<?= te('Télécharger l\'image') ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    </button>
    <img class="image-lightbox-img" id="imageLightboxImg" src="" alt="">
</div>
</body>
</html>
