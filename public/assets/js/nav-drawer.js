// Drawer de navigation mobile partagé (coquille chat ET coquille admin/settings/about).
// Ouvre/ferme la sidebar en tiroir via les boutons [data-drawer-toggle],
// gère l'overlay, Escape, le verrou de scroll et les états ARIA.
(function () {
    'use strict';
    if (window.__navDrawerInit) return;
    window.__navDrawerInit = true;

    const sidebar = document.getElementById('sidebar') || document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay') || document.getElementById('adminOverlay');
    if (!sidebar) return;

    let lastToggle = null;

    function isOpen() {
        return sidebar.classList.contains('open');
    }

    function syncToggles(open) {
        document.querySelectorAll('[data-drawer-toggle]').forEach(btn => {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function open() {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('active');
        document.body.classList.add('drawer-open');
        syncToggles(true);
    }

    function close() {
        if (!isOpen()) return;
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.classList.remove('drawer-open');
        syncToggles(false);
        if (lastToggle && document.contains(lastToggle)) lastToggle.focus();
        lastToggle = null;
    }

    function toggle(btn) {
        if (isOpen()) {
            close();
        } else {
            lastToggle = btn || null;
            open();
        }
    }

    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-drawer-toggle]');
        if (btn) {
            e.preventDefault();
            toggle(btn);
            return;
        }
        if (overlay && e.target === overlay) close();
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && isOpen()) close();
    });

    // ── Sous-menus rétractables de la sidebar admin ────────────────────────
    // L'état est rendu par le serveur (ouvert sur la page courante) : pas de
    // clignotement au chargement. Le choix de l'utilisateur, lui, est
    // mémorisé et prime — le repère de la page courante reste porté par le
    // bouton parent (classe .active), donc replier ne perd rien.
    const GROUP_PREFIX = 'adminNavGroup:';

    function applyGroup(group, open) {
        group.classList.toggle('open', open);
        const btn = group.querySelector('.admin-nav-group-toggle');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    document.querySelectorAll('.admin-nav-group[data-nav-group]').forEach(group => {
        // Alerte de configuration (pastille pulsante, cf. admin-nav.php) : le
        // groupe reste déplié quel que soit le pli mémorisé — replier
        // masquerait « Clés API », la cible même du signal. Le pli redevient
        // effectif dès l'alerte éteinte ; un repli manuel en cours de page
        // reste possible (le clic ci-dessous n'est pas filtré).
        if (group.classList.contains('nav-attention')) return;
        let stored = null;
        try {
            stored = localStorage.getItem(GROUP_PREFIX + group.dataset.navGroup);
        } catch (e) { /* stockage indisponible : on garde le rendu serveur */ }
        if (stored !== null) applyGroup(group, stored === '1');
    });

    document.addEventListener('click', e => {
        const btn = e.target.closest('.admin-nav-group-toggle');
        if (!btn) return;
        const group = btn.closest('.admin-nav-group[data-nav-group]');
        if (!group) return;
        const open = !group.classList.contains('open');
        applyGroup(group, open);
        try {
            localStorage.setItem(GROUP_PREFIX + group.dataset.navGroup, open ? '1' : '0');
        } catch (e) { /* stockage indisponible : le pli reste valable pour la page */ }
    });

    window.NavDrawer = { open, close, toggle, isOpen };
})();
