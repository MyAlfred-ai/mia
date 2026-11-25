/* ==========================================================================
   Page d'un assistant (/admin/profiles/{id}) — consultation.

   - « Posez-lui une question » : la question attend en sessionStorage
     (`composerDraft`, le relais que le chat lit déjà), le formulaire ouvre
     la conversation avec l'assistant ; la question y est prête à partir.
   - Le menu ⋯, posé sur la page (position fixe) ; ses gestes demandent
     confirmation (phrase venue du gabarit).
   - « Afficher toute la consigne » déplie la consigne repliée.
   - Le schéma de l'assistant, en lecture (MiaAssistantDiagram, sans saut
     vers un réglage).

   Aucun libellé ici : tout vient du gabarit (data-*) ou du serveur.
   ========================================================================== */
(function () {
    'use strict';

    const root = document.getElementById('asxShow');
    if (!root) return;

    // ---------- Posez-lui une question ----------
    const ask = root.querySelector('form[data-asx-ask]');
    if (ask) {
        const input = ask.querySelector('#asxAskInput');
        ask.addEventListener('submit', () => {
            const q = input ? input.value.trim() : '';
            try {
                if (q !== '') sessionStorage.setItem('composerDraft', q);
            } catch (_e) { /* sans brouillon : la conversation s'ouvre vide */ }
        });
    }

    // ---------- Consigne repliée ----------
    const more   = document.getElementById('asxPromptMore');
    const prompt = document.getElementById('asxPrompt');
    if (more && prompt) {
        more.addEventListener('click', () => {
            const open = prompt.classList.toggle('is-open');
            more.setAttribute('aria-expanded', open ? 'true' : 'false');
            more.textContent = open ? (more.dataset.less || '') : (more.dataset.more || '');
            if (!open) prompt.scrollIntoView({ block: 'nearest' });
        });
    }

    // ---------- Menu ⋯ ----------
    // Position fixe : mesurée en pixels d'écran, divisée par la taille
    // d'affichage (miaUiZoom, head-meta.php) avant d'être écrite.
    const menuBtn = root.querySelector('[data-asx-menu]');
    const menu    = menuBtn ? menuBtn.parentElement.querySelector('.kebab-menu') : null;
    function closeMenu() {
        if (!menu || menu.hidden) return;
        menu.hidden = true;
        menuBtn.setAttribute('aria-expanded', 'false');
    }
    if (menuBtn && menu) {
        menuBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!menu.hidden) { closeMenu(); return; }
            menu.hidden = false;
            const z  = window.miaUiZoom ? window.miaUiZoom() : 1;
            const r  = menuBtn.getBoundingClientRect();
            const mw = (menu.offsetWidth || 230) * z;
            const mh = (menu.offsetHeight || 120) * z;
            const left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8));
            const below = r.bottom + 4 + mh <= window.innerHeight - 8;
            const top = below ? r.bottom + 4 : Math.max(8, r.top - 4 - mh);
            menu.style.top = top / z + 'px';
            menu.style.left = left / z + 'px';
            menuBtn.setAttribute('aria-expanded', 'true');
            const first = menu.querySelector('a, button');
            if (first) first.focus();
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.asx-menu')) closeMenu();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !menu.hidden) { closeMenu(); menuBtn.focus(); }
        });
        window.addEventListener('scroll', closeMenu, true);
        window.addEventListener('resize', closeMenu);
    }
    root.querySelectorAll('form[data-asx-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.asxConfirm || '')) e.preventDefault();
        });
    });

    // ---------- Vue d'ensemble ----------
    const conf = window.ASSISTANT_SHOW;
    const host = document.getElementById('asxShowDiagram');
    if (conf && host && window.MiaAssistantDiagram) {
        window.MiaAssistantDiagram.mount(host, conf.spec, conf.catalog, { animate: true });
    }
})();
