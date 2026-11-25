/* ==========================================================================
   Espaces → Assistants : la liste (piste C, recherche d'abord).

   Recherche (sans casse ni accents, tous les mots), portée (Tous /
   Organisation / Personnels) et « Inactifs » filtrent les lignes DÉJÀ rendues
   par le serveur. « / » met le curseur dans la recherche ; Entrée ouvre la
   fiche quand une seule ligne reste. Sur chaque ligne : la description en
   bulle (survol, « ? », clavier) et le menu ⋯, posés sur la page (position
   fixe). La bibliothèque d'avatars vit dans une fenêtre.
   « Le décrire à MIA » ouvre une conversation avec une demande déjà écrite.
   Aucun libellé ici : tout vient du gabarit.
   ========================================================================== */
(function () {
    'use strict';

    const root = document.getElementById('asxList');
    if (!root) return;

    const input  = document.getElementById('asxSearch');
    const none   = document.getElementById('asxNone');
    const groups = document.getElementById('asxGroups');
    const rows   = Array.from(root.querySelectorAll('[data-asx-row]'));

    const fold = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    rows.forEach((row) => { row.dataset.fold = fold(row.dataset.search); });

    const state = { q: '', scope: 'all', off: false };

    function apply() {
        const words = fold(state.q).split(/\s+/).filter(Boolean);
        let total = 0;
        root.querySelectorAll('[data-group]').forEach((section) => {
            const inScope = state.scope === 'all' || state.scope === section.dataset.group;
            section.hidden = !inScope;
            let shown = 0;
            section.querySelectorAll('[data-asx-row]').forEach((row) => {
                const ok = inScope
                    && (!state.off || row.dataset.active === '0')
                    && words.every((w) => row.dataset.fold.includes(w));
                row.hidden = !ok;
                if (ok) shown++;
            });
            const count = section.querySelector('[data-group-count]');
            if (count) count.textContent = String(shown);
            const empty = section.querySelector('[data-group-empty]');
            if (empty) empty.hidden = shown > 0;
            if (inScope) total += shown;
        });
        // Une seule colonne : portée choisie, ou une seule colonne rendue.
        if (groups) groups.classList.toggle('is-one', state.scope !== 'all' || root.querySelectorAll('[data-group]').length < 2);
        if (none) none.hidden = total > 0 || rows.length === 0;
        closeMenus();
        hideTip();
    }

    if (input) {
        input.addEventListener('input', () => { state.q = input.value; apply(); });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && input.value !== '') { input.value = ''; state.q = ''; apply(); }
            if (e.key === 'Enter') {
                // Une seule ligne trouvée : on ouvre sa page.
                const visible = rows.filter((r) => !r.hidden);
                const link = visible.length === 1 ? visible[0].querySelector('.asx-ln-link') : null;
                if (link) { e.preventDefault(); window.location.href = link.href; }
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
            const t = e.target;
            if (t && (t.isContentEditable || /^(input|textarea|select)$/i.test(t.tagName))) return;
            if (openModal()) return;
            e.preventDefault();
            input.focus();
        });
    }

    root.querySelectorAll('[data-scope]').forEach((btn) => {
        btn.addEventListener('click', () => {
            state.scope = btn.dataset.scope;
            root.querySelectorAll('[data-scope]').forEach((b) => {
                const on = b === btn;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            apply();
        });
    });
    const offBtn = root.querySelector('[data-off-filter]');
    if (offBtn) {
        offBtn.addEventListener('click', () => {
            state.off = !state.off;
            offBtn.classList.toggle('is-on', state.off);
            offBtn.setAttribute('aria-pressed', state.off ? 'true' : 'false');
            apply();
        });
    }

    // ---------- Menu ⋯ d'une ligne ----------
    // Posé en position fixe : la ligne se masque, défile, et la colonne est
    // étroite. Les positions mesurées sont en pixels d'écran : divisées par
    // la taille d'affichage (miaUiZoom, head-meta.php) avant d'être écrites.
    function closeMenus() {
        root.querySelectorAll('.asx-menu .kebab-menu:not([hidden])').forEach((m) => {
            m.hidden = true;
            const b = m.parentElement && m.parentElement.querySelector('[data-asx-menu]');
            if (b) b.setAttribute('aria-expanded', 'false');
            const row = m.closest('[data-asx-row]');
            if (row) row.classList.remove('is-menu');
        });
    }
    root.querySelectorAll('[data-asx-menu]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const menu = btn.parentElement.querySelector('.kebab-menu');
            if (!menu) return;
            const wasOpen = !menu.hidden;
            closeMenus();
            hideTip();
            if (wasOpen) return;
            menu.hidden = false;
            const z  = window.miaUiZoom ? window.miaUiZoom() : 1;
            const r  = btn.getBoundingClientRect();
            const mw = (menu.offsetWidth || 230) * z;
            const mh = (menu.offsetHeight || 120) * z;
            const left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8));
            const below = r.bottom + 4 + mh <= window.innerHeight - 8;
            const top = below ? r.bottom + 4 : Math.max(8, r.top - 4 - mh);
            menu.style.top = top / z + 'px';
            menu.style.left = left / z + 'px';
            btn.setAttribute('aria-expanded', 'true');
            const row = btn.closest('[data-asx-row]');
            if (row) row.classList.add('is-menu');
            const first = menu.querySelector('a, button');
            if (first) first.focus();
        });
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.asx-menu')) closeMenus();
    });
    window.addEventListener('scroll', closeMenus, true);
    window.addEventListener('resize', closeMenus);

    // ---------- Description en bulle ----------
    // Survol d'une ligne : la description vient après un court délai (parcourir
    // la liste ne fait pas clignoter une bulle par ligne), tout de suite sur le
    // « ? » ou quand une bulle vient d'être vue. Clavier : avec le focus visible
    // du lien. Doigt (et clic sur le « ? ») : la bulle s'ouvre ou se referme sans
    // ouvrir la fiche. Le texte est celui que lit un lecteur d'écran
    // ([data-asx-desc], relié au lien par aria-describedby) : la bulle, purement
    // visuelle, est masquée aux lecteurs d'écran. Elle vit sur <body>, en
    // position fixe, au-dessus du « ? » (dessous faute de place) ; positions
    // mesurées en pixels d'écran, divisées par miaUiZoom à l'écriture.
    const hoverable = window.matchMedia ? window.matchMedia('(hover: hover)') : null;
    const TIP_ROW_MS  = 450;
    const TIP_FAST_MS = 70;
    const TIP_WARM_MS = 350;
    const TIP_GAP     = 9;
    const TIP_EDGE    = 8;
    let tip = null;
    let tipRow = null;
    let tipPending = null;
    let tipTimer = null;
    let tipPinned = false;
    let tipHiddenAt = 0;
    let lastPointer = 'mouse';

    const descOf = (row) => {
        const d = row ? row.querySelector('[data-asx-desc]') : null;
        return d ? d.textContent.trim() : '';
    };
    function tipEl() {
        if (tip) return tip;
        tip = document.createElement('div');
        tip.className = 'asx-tip';
        tip.setAttribute('aria-hidden', 'true');
        tip.hidden = true;
        document.body.appendChild(tip);
        return tip;
    }
    function markHelp(row, on) {
        const help = row ? row.querySelector('[data-asx-help]') : null;
        if (help) help.classList.toggle('is-on', on);
    }
    function placeTip(b, anchor) {
        const z  = window.miaUiZoom ? window.miaUiZoom() : 1;
        const r  = anchor.getBoundingClientRect();
        const vw = document.documentElement.clientWidth;
        const vh = document.documentElement.clientHeight;
        const w  = b.offsetWidth * z;
        const h  = b.offsetHeight * z;
        const center = r.left + r.width / 2;
        let left = Math.round(center - w / 2);
        left = Math.max(TIP_EDGE, Math.min(left, vw - w - TIP_EDGE));
        let top = Math.round(r.top - h - TIP_GAP);
        let below = false;
        if (top < TIP_EDGE) {
            top = Math.round(r.bottom + TIP_GAP);
            below = true;
        }
        top = Math.max(TIP_EDGE, Math.min(top, vh - h - TIP_EDGE));
        b.style.left = left / z + 'px';
        b.style.top = top / z + 'px';
        // La flèche reste pointée sur le « ? » quand la bulle est décalée.
        b.style.setProperty('--asx-tip-arrow', Math.round((center - left) / z) + 'px');
        b.classList.toggle('is-below', below);
    }
    function showTip(row, pinned) {
        clearTimeout(tipTimer);
        tipPending = null;
        const text = descOf(row);
        if (!text || row.hidden) return;
        if (tipRow && tipRow !== row) markHelp(tipRow, false);
        const b = tipEl();
        b.textContent = text;
        b.classList.remove('is-visible');
        b.hidden = false;
        placeTip(b, row.querySelector('[data-asx-help]') || row.querySelector('.asx-name') || row);
        void b.offsetWidth; // l'apparition part de l'état masqué
        b.classList.add('is-visible');
        tipRow = row;
        tipPinned = !!pinned;
        markHelp(row, true);
    }
    function hideTip() {
        clearTimeout(tipTimer);
        tipTimer = null;
        tipPending = null;
        if (!tipRow) return;
        markHelp(tipRow, false);
        tipRow = null;
        tipPinned = false;
        tipHiddenAt = Date.now();
        if (tip) {
            tip.classList.remove('is-visible');
            tip.hidden = true;
        }
    }

    document.addEventListener('pointerdown', (e) => { lastPointer = e.pointerType || 'mouse'; }, true);

    root.addEventListener('mouseover', (e) => {
        if ((hoverable && !hoverable.matches) || lastPointer === 'touch' || lastPointer === 'pen') return;
        if (tipPinned) return;
        const t = e.target;
        const row = t && t.closest ? t.closest('[data-asx-row]') : null;
        // Icône de visibilité, pastilles et commandes ont leurs propres infobulles.
        const onLine = row && !t.closest('.asx-vis, .asx-acts, [data-tip], .nav-signal-dot') && descOf(row) !== '';
        if (!onLine) {
            if (tipRow || tipPending) hideTip();
            return;
        }
        const onHelp = !!t.closest('[data-asx-help]');
        if (row === tipRow) return;
        if (row === tipPending && !onHelp) return;
        const warm = tipRow !== null || Date.now() - tipHiddenAt < TIP_WARM_MS;
        if (tipRow) hideTip();
        clearTimeout(tipTimer);
        tipPending = row;
        tipTimer = setTimeout(() => showTip(row, false), onHelp || warm ? TIP_FAST_MS : TIP_ROW_MS);
    });
    root.addEventListener('mouseleave', () => { if (!tipPinned) hideTip(); });

    // Le « ? » se touche ou se clique : la bulle, jamais la fiche. Capture : la
    // ligne (lien étiré) ne voit pas ce clic.
    root.addEventListener('click', (e) => {
        const help = e.target && e.target.closest ? e.target.closest('[data-asx-help]') : null;
        if (!help) return;
        e.preventDefault();
        e.stopPropagation();
        const row = help.closest('[data-asx-row]');
        if (tipRow === row && tipPinned) { hideTip(); return; }
        closeMenus();
        showTip(row, true);
    }, true);
    document.addEventListener('click', (e) => {
        if (tipPinned && !(e.target.closest && e.target.closest('[data-asx-help]'))) hideTip();
    });

    // Clavier : la bulle suit le focus VISIBLE du lien (un clic donne aussi le focus).
    root.addEventListener('focusin', (e) => {
        const link = e.target && e.target.closest ? e.target.closest('.asx-ln-link') : null;
        if (!link) return;
        let keyboard = true;
        try { keyboard = e.target.matches(':focus-visible'); } catch (_e) { /* moteur ancien : on montre */ }
        if (keyboard) showTip(link.closest('[data-asx-row]'), false);
    });
    root.addEventListener('focusout', (e) => {
        const row = e.target && e.target.closest ? e.target.closest('[data-asx-row]') : null;
        if (row && row === tipRow && !tipPinned) hideTip();
    });
    window.addEventListener('scroll', hideTip, true);
    window.addEventListener('resize', hideTip);

    // Suppression : la phrase de confirmation vient du gabarit.
    root.querySelectorAll('form[data-asx-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.asxConfirm || '')) e.preventDefault();
        });
    });

    // ---------- « Le décrire à MIA » ----------
    // Même relais que le choix d'un assistant depuis le chat : le brouillon
    // attend en sessionStorage, la page du chat le pose dans la zone de saisie.
    root.querySelectorAll('[data-mia-draft]').forEach((link) => {
        link.addEventListener('click', () => {
            try { sessionStorage.setItem('composerDraft', link.dataset.miaDraft || ''); } catch (_e) { /* sans brouillon */ }
        });
    });

    // ---------- Retour sur un assistant (#assistant-ID) ----------
    const fromHash = /^#assistant-(\d+)$/.exec(location.hash || '');
    if (fromHash) {
        const row = document.getElementById('assistant-' + fromHash[1]);
        if (row) {
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            row.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
            row.classList.add('is-spotlit');
            setTimeout(() => row.classList.remove('is-spotlit'), 1800);
        }
    }

    // ---------- Bibliothèque d'avatars ----------
    const avatars   = document.getElementById('asxAvatars');
    const delModal  = document.getElementById('deleteAvatarModal');
    const delForm   = document.getElementById('deleteAvatarForm');
    const delInput  = document.getElementById('deleteAvatarInput');
    const delImg    = document.getElementById('deleteAvatarPreview');
    const delName   = document.getElementById('deleteAvatarFilename');
    let opener = null;
    let pendingAvatar = '';

    function openModal() {
        return (avatars && !avatars.hidden) || (delModal && !delModal.hidden);
    }
    function showAvatars(from) {
        if (!avatars) return;
        opener = from || null;
        avatars.hidden = false;
        const close = avatars.querySelector('[data-asx-close]');
        if (close) close.focus();
    }
    function hideAvatars() {
        if (!avatars || avatars.hidden) return;
        avatars.hidden = true;
        // Une fenêtre rouverte par ?avatars=1 : l'adresse redevient celle de la liste.
        if (/[?&]avatars=1/.test(location.search)) {
            try { history.replaceState(null, '', location.pathname); } catch (_e) { /* adresse inchangée */ }
        }
        if (opener) opener.focus();
    }
    root.querySelectorAll('[data-asx-avatars]').forEach((btn) => {
        btn.addEventListener('click', () => showAvatars(btn));
    });
    if (avatars) {
        avatars.querySelectorAll('[data-asx-close]').forEach((b) => b.addEventListener('click', hideAvatars));
        avatars.addEventListener('click', (e) => { if (e.target === avatars) hideAvatars(); });
        if (!avatars.hidden) {
            opener = root.querySelector('[data-asx-avatars]');
            const close = avatars.querySelector('[data-asx-close]');
            if (close) close.focus();
        }
    }

    function hideDelete() {
        pendingAvatar = '';
        if (delModal) delModal.hidden = true;
    }
    document.querySelectorAll('.avatar-delete-btn').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!delModal || !delInput || !delImg || !delName) return;
            pendingAvatar = String(btn.dataset.avatar || '').trim();
            if (!pendingAvatar) return;
            delInput.value = pendingAvatar;
            delName.textContent = String(btn.dataset.name || pendingAvatar);
            delImg.src = '/assets/img/profils/' + encodeURIComponent(pendingAvatar);
            delModal.hidden = false;
        });
    });
    const delCancel  = document.getElementById('deleteAvatarCancelBtn');
    const delConfirm = document.getElementById('deleteAvatarConfirmBtn');
    if (delCancel) delCancel.addEventListener('click', hideDelete);
    if (delModal) delModal.addEventListener('click', (e) => { if (e.target === delModal) hideDelete(); });
    if (delConfirm) {
        delConfirm.addEventListener('click', () => {
            if (pendingAvatar && delForm) delForm.submit();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (delModal && !delModal.hidden) { hideDelete(); return; }
        if (avatars && !avatars.hidden) { hideAvatars(); return; }
        closeMenus();
        hideTip();
    });

    apply();
})();
