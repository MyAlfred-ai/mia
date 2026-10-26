<!-- Ossature commune admin / paramètres / à propos.
     Variables : $navVariant ('admin'|'settings'|'none'), $navTitle, $page.

     NOTE i18n — entièrement traduit (FR/EN), les trois variantes. Les libellés
     des rubriques et des pages sont lus TRADUITS à leur source (AdminMenu::label,
     AdminMenu::pageText), les mêmes que la palette ⌘K ; le texte des bulles vient
     déjà traduit d'AdminAttention et d'AdminMenu::budgetSignal. -->
<?php
// Cette ossature sert AUSSI /settings et « À propos », traduits : la punchline
// livrée suit donc la langue du lecteur, celle qu'un administrateur a écrite non.
$appTagline = I18n::editorial(Settings::get('app_tagline', ''), 'Votre assistant IA multi-modèles');
$navVariant = $navVariant ?? 'admin';
$navTitle   = $navTitle   ?? Settings::get('app_name', 'MIA');
$page       = $page       ?? '';
// Connexions révoquées par leur fournisseur : rappel porté par l'avatar
// (header mobile, pied de barre latérale), le menu du compte et, dans la
// variante « réglages », le lien « Comptes connectés ». Calculé ICI, avant le
// header : une requête pour les trois partials, dans l'ordre où ils sont rendus.
$navUser = Session::currentUser();
// Pages des espaces du créateur atteintes depuis la discussion : elles gardent
// LE VOLET DU CHAT (NavShell, chat-shell-sidebar.php) au lieu du menu latéral
// de l'administration ou des paramètres.
$navChatShell = NavShell::resolve($page);
if (!isset($connectorsToReconnect)) {
    $connectorsToReconnect = $navUser
        ? ConnectorRegistry::reconnectNeeded((int)$navUser['id'], (string)($navUser['role'] ?? 'user'))
        : [];
}
// Points à régler de l'administration (AdminAttention, source unique) :
// pastille sur l'entrée où l'on corrige ET sur le groupe rétractable qui la
// contient, bulle d'explication au-dessus (nav-signals.js ; texte sous l'entrée
// au doigt). Comptes qui ADMINISTRENT (bouclier), variante « admin » seule, et
// seulement les constats des pages que leur rôle ouvre (AdminAccess). Calculés
// ICI, avant le header mobile : le bouton du tiroir porte lui aussi une
// pastille. Mémorisés pour la requête — le menu du compte et le tableau de
// bord relisent la liste.
$navAttention = ($navVariant === 'admin' && AdminAccess::hasAdministration($navUser))
    ? AdminAccess::filterAttention(AdminAttention::current(), $navUser)
    : [];
// Volet du chat : aucune entrée d'administration où poser une pastille.
if ($navChatShell) {
    $navAttention = [];
}
// Pages de l'administration que ce rôle ouvre (Personnes → Rôles) : une
// entrée n'est rendue que si sa page s'ouvre ; un groupe, que si l'une des
// siennes s'ouvre. Le serveur refuse de toute façon les autres.
$navCan = [];
foreach (array_keys(AdminAccess::PAGES) as $navPageKey) {
    $navCan[$navPageKey] = $navVariant === 'admin' && AdminAccess::canOpen($navPageKey, $navUser);
}
// Lecture seule : la page s'ouvre, mais le rôle n'a aucun des droits qui y
// écrivent (bandeau + contrôles des formulaires désactivés, plus bas).
$navReadOnly = $navVariant === 'admin' && $page !== '' && AdminAccess::readOnly($page, $navUser);
// [attributs de l'élément, pastille, bulle] pour un état de forEntry/forGroup
// (null = rien). La bulle suit l'élément : `aria-describedby` la lie, le
// script la lit, et au doigt elle s'affiche sous l'entrée.
$navSignal = static function (?array $etat, string $id): array {
    if ($etat === null) {
        return ['', '', ''];
    }
    $ton = $etat['level'] === AdminAttention::ALERT ? 'is-alert' : 'is-warn';
    return [
        ' data-nav-signal aria-describedby="' . $id . '"',
        '<span class="nav-signal-dot ' . $ton . '" aria-hidden="true"></span>',
        '<div class="nav-signal-hint ' . $ton . '" id="' . $id . '">' . htmlspecialchars((string)$etat['text']) . '</div>',
    ];
};
$navBurgerLevel = AdminAttention::worstLevel($navAttention);
?>
<div class="admin-mobile-header" id="adminMobileHeader">
    <button type="button" class="admin-burger" data-drawer-toggle title="<?= te('Ouvrir le menu') ?>"
            aria-label="<?= te('Ouvrir le menu') ?>" aria-controls="adminSidebar" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        <?= $navBurgerLevel !== null ? '<span class="nav-signal-dot ' . ($navBurgerLevel === AdminAttention::ALERT ? 'is-alert' : 'is-warn') . '" aria-hidden="true"></span>' : '' ?>
    </button>
    <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"
         alt="" class="mobile-header-logo">
    <span class="admin-mobile-title"><?= htmlspecialchars($navTitle) ?></span>
    <?php require ROOT . '/templates/partials/header-avatar-btn.php'; ?>
</div>

<!-- Overlay -->
<div class="admin-overlay" id="adminOverlay"></div>

<?php if ($navChatShell): ?>
<aside class="admin-sidebar admin-sidebar--chat" id="adminSidebar">
    <?php require ROOT . '/templates/partials/chat-shell-sidebar.php'; ?>
</aside>
<?php else: ?>
<aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-head">
        <?php $hoverLogoUrl = Settings::get('logo_hover_url', ''); ?>
        <a href="/" class="sidebar-logo-link" title="<?= te('Accueil') ?>" aria-label="<?= te('Retour à l\'accueil') ?>">
            <div class="sidebar-logo-wrap<?= $hoverLogoUrl ? ' has-hover-logo' : '' ?>">
                <img src="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>" alt="logo" class="sidebar-logo">
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
    <!-- Sortie de l'administration / des paramètres : on doit toujours pouvoir
         revenir au chat sans passer par le logo. Habillée comme les raccourcis
         du volet du chat (panneau encadré, icône en tuile — cf. .sidebar-nav),
         et posée HORS de la liste qui défile : elle reste en vue.
         Pas sur « À propos » (nav vide, page hors chrome applicatif). -->
    <?php if ($navVariant !== 'none'): ?>
    <div class="sidebar-nav admin-nav-back-panel">
        <a href="/" class="sidebar-nav-link admin-nav-back">
            <span class="sidebar-nav-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg></span>
            <span class="sidebar-nav-label"><?= te('Retour au chat') ?></span>
        </a>
    </div>
    <?php endif; ?>
    <nav class="admin-nav-scroll">
        <?php if ($navVariant === 'settings'): ?>
        <?php
            // Mon compte : les seules pages de réglages personnels
            // (NavRegistry::ACCOUNT, source unique). Le bouton « Réglages » du
            // bas de la barre latérale mène ICI directement. Tâches, workflows
            // et créations sont des pages de l'espace de travail (volet du chat).
            // En bas : À propos et déconnexion, qui n'ont pas d'autre place.
        ?>
        <div class="admin-nav-label"><?= te('Mon compte') ?></div>
        <?php foreach (NavRegistry::accountFor($navUser) as $navKey => $navItem): ?>
        <a href="<?= htmlspecialchars($navItem['href']) ?>" class="admin-nav-link <?= $page === $navItem['page'] ? 'active' : '' ?>"<?= $navKey === 'connections' && $connectorsToReconnect !== [] ? ' title="' . htmlspecialchars(ConnectorRegistry::reconnectSummary($connectorsToReconnect)) . '"' : '' ?>>
            <?= NavRegistry::icon($navItem['icon'], 15) ?>
            <?= te($navItem['label']) ?>
            <?php // Pastille discrète (sans pulsation) : une connexion expirée à rétablir. ?>
            <?php if ($navKey === 'connections' && $connectorsToReconnect !== []): ?><span class="reconnect-dot" aria-hidden="true"></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <div class="admin-nav-sep" role="separator"></div>
        <a href="/a-propos" class="admin-nav-link"><?= NavRegistry::icon('info', 15) ?><?= te('À propos de MIA') ?></a>
        <?php // tj() rend un littéral JSON entre GUILLEMETS DOUBLES : l'attribut se
              // délimite par des apostrophes, sinon le premier « " » le fermerait
              // et la confirmation ne s'afficherait jamais. ?>
        <a href="/auth/logout" class="admin-nav-link is-danger" onclick='return confirm(<?= tj('Se déconnecter ?') ?>);'><?= NavRegistry::icon('logout', 15) ?><?= te('Se déconnecter') ?></a>
        <?php elseif ($navVariant === 'admin'): ?>
        <?php
            // Les sept rubriques d'AdminMenu (source unique : libellé, icône ;
            // pages et adresses : AdminAccess::ENTRY_PAGES / PAGE_HREFS). Chaque
            // page n'y figure qu'une fois ; assistants et bases vivent dans
            // l'espace de travail (NavRegistry). Une rubrique n'est rendue que si
            // l'une de ses pages s'ouvre, une page que si elle s'ouvre.
            // « Diagnostic » et « Données » réunissent chacune des pages sœurs
            // reliées par une barre d'onglets (AdminTabs) : l'entrée s'allume sur
            // toutes les pages du groupe.
            $diagActive = in_array($page, AdminTabs::pages(AdminTabs::DIAGNOSTIC), true);
            $dataActive = in_array($page, AdminTabs::pages(AdminTabs::DONNEES), true);
            $navCurrent = $diagActive ? 'diagnostics' : ($dataActive ? 'backup' : $page);
            $navTitles  = [
                'roles'       => t('Ce que chaque rôle peut voir, utiliser, créer, modifier, supprimer'),
                'company'     => t('Nom, descriptif, logo, modèle PowerPoint'),
                'settings'    => t('Nom, logos et couleurs de l\'application'),
                'diagnostics' => t('Contrôles, journaux, moteur vectoriel, agent'),
                'backup'      => t('Sauvegarde, élagage'),
            ];
            // Une page de rubrique : son signal [attributs, pastille, bulle].
            $navSub = static function (string $p, array $sig = ['', '', '']) use ($navCurrent, $navCan, $navTitles): string {
                if (empty($navCan[$p])) {
                    return '';
                }
                [$attr, $dot, $hint] = $sig;
                $title = ($attr === '' && isset($navTitles[$p])) ? ' title="' . htmlspecialchars($navTitles[$p]) . '"' : '';
                return '<a href="' . htmlspecialchars(AdminAccess::PAGE_HREFS[$p]) . '" class="admin-nav-link admin-nav-sublink' . ($navCurrent === $p ? ' active' : '') . '"' . $title . $attr . '>'
                    . htmlspecialchars(AdminMenu::pageText($p)) . $dot . '</a>' . $hint;
            };
            // Une rubrique repliable. $sig : signal du groupe ; $dots : pastilles
            // propres (clé API absente, budget) posées avant celle du groupe.
            $navGroup = static function (string $key, string $sub, array $sig = ['', '', ''], string $dots = '', bool $force = false, string $extraClass = '') use ($page, $navCurrent): string {
                if ($sub === '') {
                    return '';
                }
                $pages  = AdminAccess::ENTRY_PAGES[$key] ?? [];
                $active = in_array($navCurrent, $pages, true) || in_array($page, $pages, true);
                $open   = $active || $force;
                $id     = 'adminNavGroup' . ucfirst($key);
                [$attr, $dot, $hint] = $sig;
                return '<div class="admin-nav-group' . ($open ? ' open' : '') . $extraClass . '" data-nav-group="' . $key . '">'
                    . '<button type="button" class="admin-nav-link admin-nav-group-toggle' . ($active ? ' active' : '') . '" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . $id . '"' . $attr . '>'
                    . AdminMenu::icon(AdminMenu::ENTRIES[$key]['icon'], 15)
                    . '<span class="admin-nav-group-label">' . htmlspecialchars(AdminMenu::label($key)) . '</span>'
                    . $dots . $dot
                    . '<svg class="admin-nav-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>'
                    . '</button>' . $hint
                    . '<div class="admin-nav-sub" id="' . $id . '">' . $sub . '</div></div>';
            };

            // Modèles IA — configuration initiale : tant qu'AUCUNE clé API n'est
            // active, MIA ne peut répondre à personne. La rubrique porte alors une
            // pastille ambre pulsante (idem « Clés API »), reste dépliée —
            // `nav-attention` prime aussi sur le pli mémorisé (nav-drawer.js) —
            // et le tableau de bord affiche le bandeau de démarrage
            // ($llmSetupNeeded est réutilisé par les gabarits rendus après ce
            // partial). Source unique : ApiKey::hasActiveLlm(). Sans clé,
            // AdminAttention se tait sur les modèles : les deux bulles ne
            // coexistent pas.
            $llmSetupNeeded = !ApiKey::hasActiveLlm();
            $llmSetupTitle  = AdminAttention::texteAucuneCle();
            $llmSetupEtat   = $llmSetupNeeded ? ['level' => AdminAttention::WARN, 'text' => $llmSetupTitle] : null;
            $llmSetupDot    = $llmSetupNeeded ? '<span class="nav-attention-dot" aria-hidden="true"></span>' : '';
            [$llmSetupAttr, , $llmSetupHint] = $navSignal($llmSetupEtat, 'navHintLlmSetup');
            [$llmKeyAttr, , $llmKeyHint]     = $navSignal($llmSetupEtat, 'navHintLlmKeys');
            [$llmGAttr, $llmGDot, $llmGHint] = $navSignal($llmSetupNeeded ? null : AdminAttention::forGroup($navAttention, 'models'), 'navHintGroupModels');

            // Coûts : budget mensuel, consommation, tarifs. Pastille dès le seuil
            // d'alerte du budget (ambre), rouge pulsée au plafond ; en suspension,
            // la rubrique reste dépliée (`nav-attention`, cf. nav-drawer.js).
            // AiBudget::status() lit le mémo : aucune requête sans budget, au plus
            // une par minute sinon.
            $budgetNav      = AiBudget::status();
            $budgetEtat     = AdminMenu::budgetSignal($budgetNav);
            $budgetDot      = $budgetEtat !== null;
            $budgetDotClass = 'nav-budget-dot' . ($budgetNav['state'] === AiBudget::STATE_REACHED ? ' is-reached' : '');
            $budgetBlocking = (bool)$budgetNav['blocks'];
            $budgetDotHtml  = $budgetDot ? '<span class="' . $budgetDotClass . '" aria-hidden="true"></span>' : '';
            [$costGAttr, , $costGHint]   = $navSignal($budgetEtat, 'navHintGroupCosts');
            [$budgetAttr, , $budgetHint] = $navSignal($budgetEtat, 'navHintBudget');
        ?>
        <?php if ($navCan['dashboard']): ?>
        <a href="/admin" class="admin-nav-link <?= $page === 'dashboard' ? 'active' : '' ?>">
            <?= AdminMenu::icon('dashboard', 15) ?>
            <?= htmlspecialchars(AdminMenu::label('dashboard')) ?>
        </a>
        <?php endif; ?>
        <?= $navGroup('people',
            $navSub('users', $navSignal(AdminAttention::forEntry($navAttention, 'users'), 'navHintUsers'))
            . $navSub('user-groups', $navSignal(AdminAttention::forEntry($navAttention, 'user-groups'), 'navHintUserGroups'))
            . $navSub('roles'),
            $navSignal(AdminAttention::forGroup($navAttention, 'people'), 'navHintGroupPeople')) ?>
        <?= $navGroup('models',
            $navSub('llms-keys', [$llmKeyAttr, $llmSetupDot, $llmKeyHint])
            . $navSub('llms-models', $navSignal(AdminAttention::forEntry($navAttention, 'llms-models'), 'navHintLlmModels'))
            . $navSub('llms-tests'),
            [$llmSetupAttr . $llmGAttr, $llmGDot, $llmSetupHint . $llmGHint], $llmSetupDot, $llmSetupNeeded, $llmSetupNeeded ? ' nav-attention' : '') ?>
        <?= $navGroup('capabilities',
            $navSub('tools', $navSignal(AdminAttention::forEntry($navAttention, 'tools'), 'navHintTools'))
            . $navSub('connectors', $navSignal(AdminAttention::forEntry($navAttention, 'connectors'), 'navHintConnectors'))
            . $navSub('knowledge-bases-sync', $navSignal(AdminAttention::forEntry($navAttention, 'knowledge-bases-sync'), 'navHintKbSync')),
            $navSignal(AdminAttention::forGroup($navAttention, 'capabilities'), 'navHintGroupCapabilities')) ?>
        <?= $navGroup('costs',
            $navSub('budget', [$budgetAttr, $budgetDotHtml, $budgetHint])
            . $navSub('usage')
            . $navSub('credits'),
            [$costGAttr, '', $costGHint], $budgetDotHtml, $budgetBlocking, $budgetBlocking ? ' nav-attention' : '') ?>
        <?= $navGroup('organisation',
            $navSub('company')
            . $navSub('settings')
            . $navSub('sso', $navSignal(AdminAttention::forEntry($navAttention, 'sso'), 'navHintSso')),
            $navSignal(AdminAttention::forGroup($navAttention, 'organisation'), 'navHintGroupOrganisation')) ?>
        <?= $navGroup('maintenance',
            $navSub('diagnostics', $navSignal(AdminAttention::forEntry($navAttention, 'diagnostics'), 'navHintDiagnostics'))
            . $navSub('backup'),
            $navSignal(AdminAttention::forGroup($navAttention, 'maintenance'), 'navHintGroupMaintenance')) ?>
        <?php endif; // $navVariant 'none' : nav vide ?>
    </nav>
    <div class="admin-sidebar-footer">
        <?php require ROOT . '/templates/partials/user-account-trigger.php'; ?>
    </div>
</aside>
<?php endif; // $navChatShell ?>

<?php require ROOT . '/templates/partials/command-palette.php'; ?>
<?php require ROOT . '/templates/partials/lang-switcher.php'; ?>
<?php $userMenuJsVersion = @filemtime(ROOT . '/public/assets/js/user-menu.js') ?: time(); ?>
<script src="/assets/js/user-menu.js?v=<?= (int)$userMenuJsVersion ?>"></script>
<?php $navDrawerJsVersion = @filemtime(ROOT . '/public/assets/js/nav-drawer.js') ?: time(); ?>
<script src="/assets/js/nav-drawer.js?v=<?= (int)$navDrawerJsVersion ?>"></script>
<?php if ($navReadOnly): ?>
<?php
/* Lecture seule (AdminAccess::readOnly) : le rôle ouvre cette page mais n'a
   aucun des droits qui y écrivent. Bandeau en tête du contenu, contrôles des
   formulaires POST désactivés (ceux rattachés par form="…" compris, via
   `form.elements`), enregistrement automatique suspendu. Le serveur refuse de
   toute façon chaque écriture : ce n'est qu'un confort, on ne tape pas dans un
   champ qui ne s'enregistrera pas. Les formulaires GET (filtres, recherche)
   restent utilisables. */
?>
<script>
(function () {
    'use strict';
    function apply() {
        var content = document.querySelector('.admin-content');
        if (content && !document.getElementById('adminReadonlyBanner')) {
            var banner = document.createElement('div');
            banner.id = 'adminReadonlyBanner';
            banner.className = 'admin-readonly-banner';
            banner.setAttribute('role', 'status');
            banner.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>'
                + '<span><strong></strong> </span>';
            // Textes posés par textContent : une traduction n'est jamais lue comme du HTML.
            banner.querySelector('strong').textContent = <?= tj('Lecture seule.') ?>;
            banner.querySelector('span').appendChild(document.createTextNode(<?= tj('Votre rôle vous permet de consulter cette page, pas de la modifier.') ?>));
            content.insertBefore(banner, content.firstChild);
        }
        document.body.classList.add('is-admin-readonly');
        document.querySelectorAll('form').forEach(function (form) {
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
            if (form.closest('.admin-sidebar, .user-menu, .lang-switcher')) return;
            form.setAttribute('data-autosave', 'off');
            Array.prototype.forEach.call(form.elements, function (el) {
                // Les boutons d'interface (fermer, annuler, onglets) restent
                // utilisables ; champs et boutons d'envoi sont désactivés.
                if (el.type === 'hidden' || (el.tagName === 'BUTTON' && el.type === 'button')) return;
                el.disabled = true;
            });
            form.addEventListener('submit', function (e) { e.preventDefault(); e.stopImmediatePropagation(); }, true);
        });
        document.querySelectorAll('.admin-content .ios-switch input, [data-admin-write]').forEach(function (el) {
            el.disabled = true;
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
    else apply();
})();
</script>
<?php endif; ?>
<?php if ($navVariant === 'admin'): // Bulles des pastilles du menu (points à régler, clé API, budget). ?>
<?php $navSignalsJsVersion = @filemtime(ROOT . '/public/assets/js/nav-signals.js') ?: time(); ?>
<script src="/assets/js/nav-signals.js?v=<?= (int)$navSignalsJsVersion ?>"></script>
<?php endif; ?>
<?php
/* Enregistrement automatique des formulaires de configuration. Chargé ici parce
   que ce partial est la seule ossature commune à /admin/* ET à /settings/*.
   `defer` obligatoire : la nav est rendue AVANT les formulaires, un script
   synchrone ne trouverait aucun `form[data-autosave]` à brancher. */
$autosaveJsVersion = @filemtime(ROOT . '/public/assets/js/autosave.js') ?: time();
?>
<script defer src="/assets/js/autosave.js?v=<?= (int)$autosaveJsVersion ?>"></script>
