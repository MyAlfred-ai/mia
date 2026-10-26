<?php
/* Volet du chat, rendu par l'ossature commune (admin-nav.php) sur les pages des
   espaces du créateur atteintes depuis la discussion (NavShell) : Assistants,
   Tâches programmées, Workflows, Bases de connaissances. Le volet ne change pas
   en quittant la conversation — même en-tête, même bloc de départ, mêmes
   raccourcis (partial partagé, le raccourci de la page allumé), même historique.

   RÉPLIQUE de templates/chat/index.php (en-tête, bloc de départ, historique) :
   à garder synchronisée. Différences assumées, faute de chat.js sur ces pages :
   « Nouvelle conversation » est un lien vers `/`, une conversation est un
   simple lien (ni menu « … », ni repli du volet) ; groupes de l'historique et
   recherche viennent de chat-shell.js.

   Variables : $appTagline, $page (posées par admin-nav.php). */
$shellUser          = Session::currentUser();
$shellConversations = $shellUser ? Conversation::allForUser((int)$shellUser['id']) : [];
$hoverLogoUrl       = Settings::get('logo_hover_url', '');
$sbNavActive        = NavShell::SHORTCUT_OF[$page] ?? '';
?>
<div class="sidebar-header">
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
</div>

<div class="sidebar-start">
    <a href="/" class="sidebar-new-btn" title="<?= te('Nouvelle conversation') ?>">
        <span class="sidebar-new-btn__icon" aria-hidden="true">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M12 2.75A9.25 9.25 0 0 0 2.75 12c0 1.481.348 2.879.965 4.118c.248.498.343 1.092.187 1.677l-.596 2.225a.55.55 0 0 0 .673.674l2.227-.596a2.38 2.38 0 0 1 1.676.187A9.2 9.2 0 0 0 12 21.25a9.25 9.25 0 0 0 0-18.5M1.25 12C1.25 6.063 6.063 1.25 12 1.25S22.75 6.063 22.75 12S17.937 22.75 12 22.75c-1.718 0-3.344-.404-4.787-1.122a.9.9 0 0 0-.62-.08l-2.226.595c-1.524.408-2.918-.986-2.51-2.51l.596-2.226a.9.9 0 0 0-.08-.62A10.7 10.7 0 0 1 1.25 12m6-1.5A.75.75 0 0 1 8 9.75h8a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75m0 3.5a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75"/></svg>
        </span>
        <span class="sidebar-nav-label"><?= te('Nouvelle conversation') ?></span>
    </a>
    <button type="button" class="conv-search conv-search-trigger" data-palette-open aria-haspopup="dialog" aria-expanded="false">
        <svg class="conv-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span class="conv-search-label"><?= te('Rechercher ou aller à…') ?></span>
        <kbd class="conv-search-kbd" data-palette-kbd aria-hidden="true"></kbd>
    </button>
</div>

<?php require ROOT . '/templates/partials/sidebar-shortcuts.php'; ?>

<nav class="conv-list" id="convList" data-max-pinned="<?= Conversation::MAX_PINNED ?>">
    <?php foreach ($shellConversations as $conv): ?>
        <?php
            $isPinned    = (int)($conv['is_pinned'] ?? 0) === 1;
            $isScheduled = !empty($conv['scheduled_task_id']);
            $isUnread    = !empty($conv['unread_at']);
        ?>
        <a href="/chat/<?= (int)$conv['id'] ?>"
           class="conv-item<?= $isPinned ? ' is-pinned' : '' ?><?= $isScheduled ? ' is-scheduled' : '' ?><?= $isUnread ? ' is-unread' : '' ?>"
           data-id="<?= (int)$conv['id'] ?>"
           data-pinned="<?= $isPinned ? '1' : '0' ?>"
           data-ts="<?= (int)strtotime((string)($conv['updated_at'] ?? 'now')) ?>"
           <?php if (!empty($conv['profile_color'])): ?>style="--conv-color:<?= htmlspecialchars($conv['profile_color']) ?>"<?php endif; ?>>
            <?php if (!empty($conv['profile_color'])): ?>
                <span class="conv-color-bar"></span>
            <?php endif; ?>
            <?php if ($isScheduled): ?>
                <span class="conv-scheduled-icon" role="img" aria-label="<?= te('Tâche programmée') ?>"><?= ScheduledTask::icon(13) ?></span>
            <?php endif; ?>
            <span class="conv-title"><?= htmlspecialchars(Conversation::displayTitle($conv['title'] ?? null)) ?></span>
            <?php if ($isUnread): ?><span class="conv-unread-dot" title="<?= te('Nouveau résultat') ?>" aria-label="<?= te('Nouveau résultat') ?>"></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
    <?php if (empty($shellConversations)): ?>
        <p class="conv-empty"><?= te('Aucune conversation') ?></p>
    <?php endif; ?>
</nav>

<div class="sidebar-footer">
    <?php require ROOT . '/templates/partials/user-account-trigger.php'; ?>
</div>
<?php $chatShellJsVersion = @filemtime(ROOT . '/public/assets/js/chat-shell.js') ?: time(); ?>
<script defer src="/assets/js/chat-shell.js?v=<?= (int)$chatShellJsVersion ?>"></script>
