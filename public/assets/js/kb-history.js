/* ==========================================================================
   Historique d'une table PERMANENTE — volet de la page de consultation
   (templates/admin/knowledge-bases/show.php).

   Le volet, le bandeau d'un état passé et leurs libellés sont rendus par le
   serveur (…/table/history, partiels table-history*.php) : ce script n'a
   AUCUN libellé. Il ouvre et ferme le volet, demande la liste dans le fuseau
   du navigateur, ouvre un état (la grille l'affiche : évènement
   `mia:kb-table-version`, écouté par kb-table.js), et confirme un retour en
   arrière dans une fenêtre avant d'envoyer le formulaire — qui marche aussi
   sans script.
   ========================================================================== */
(function () {
    'use strict';

    const panel = document.getElementById('kbHistory');
    if (!panel) return;

    const body    = document.getElementById('kbHistBody');
    const banner  = document.getElementById('kbHistBanner');
    const card    = document.querySelector('.kbx-tablecard');
    const dialog  = document.getElementById('kbHistConfirm');
    const openers = Array.from(document.querySelectorAll('[data-kbh-open]'));
    const url     = panel.dataset.url || '';
    const tz      = (() => {
        try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; }
    })();

    const state = { open: false, selected: 0, seq: 0, pending: null, opener: null };

    function showVersion(version) {
        document.dispatchEvent(new CustomEvent('mia:kb-table-version', { detail: { version } }));
    }

    function setBanner(html) {
        if (!banner) return;
        banner.innerHTML = html || '';
        banner.hidden = !html;
        if (card) card.classList.toggle('is-past', !!html);
    }

    /** La liste, l'état `version` ouvert (0 = aucun). */
    async function load(version) {
        const mine = ++state.seq;
        body.classList.add('is-loading');
        try {
            const res  = await fetch(url + '?version=' + encodeURIComponent(version) + '&tz=' + encodeURIComponent(tz), {
                credentials: 'same-origin', headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();
            if (mine !== state.seq) return;
            if (!data || !data.ok) throw new Error('history');
            body.innerHTML = data.html || '';
            body.querySelectorAll('[data-kbh-tz]').forEach((el) => { el.value = tz; });
            const selected = Number(data.selected) || 0;
            setBanner(data.banner || '');
            if (selected !== state.selected) {
                state.selected = selected;
                showVersion(selected);
            }
            const open = body.querySelector('.kbh-ent.is-open');
            if (open && version) open.scrollIntoView({ block: 'nearest' });
        } catch (e) {
            if (mine !== state.seq) return;
            body.textContent = panel.dataset.error || '';
        } finally {
            if (mine === state.seq) body.classList.remove('is-loading');
        }
    }

    function setOpen(on, opener) {
        state.open = on;
        panel.hidden = !on;
        document.body.classList.toggle('kbh-on', on);
        openers.forEach((b) => { if (b.hasAttribute('aria-expanded')) b.setAttribute('aria-expanded', on ? 'true' : 'false'); });
        if (on) {
            state.opener = opener || null;
            load(state.selected);
            panel.querySelector('[data-kbh-close]')?.focus({ preventScroll: true });
        } else {
            present(false);
            if (state.opener && state.opener.isConnected) state.opener.focus({ preventScroll: true });
        }
    }

    /** Retour à la table en service. */
    function present(reload) {
        setBanner('');
        if (state.selected !== 0) {
            state.selected = 0;
            showVersion(0);
        }
        if (reload && state.open) load(0);
    }

    /** Confirmation d'un retour en arrière, puis envoi du formulaire. */
    function confirmRestore(form) {
        if (!form) return;
        form.querySelectorAll('[data-kbh-tz]').forEach((el) => { el.value = tz; });
        if (!dialog || typeof dialog.showModal !== 'function') { form.submit(); return; }
        dialog.querySelector('#kbHistConfirmTitle').textContent = form.dataset.confirmTitle || '';
        dialog.querySelector('#kbHistConfirmText').textContent  = form.dataset.confirmText || '';
        state.pending = form;
        dialog.showModal();
        dialog.querySelector('[data-kbh-cancel]')?.focus();
    }

    openers.forEach((b) => b.addEventListener('click', () => {
        if (state.open && b.hasAttribute('aria-expanded')) setOpen(false);
        else setOpen(true, b);
    }));

    panel.addEventListener('click', (e) => {
        const t = e.target;
        if (t.closest('[data-kbh-close]')) { setOpen(false); return; }
        const ent = t.closest('[data-kbh-version]');
        if (ent) {
            // Toucher l'état ouvert le referme (retour au présent). Ouvrir
            // l'état en service ne montre que son détail : c'est le présent.
            if (ent.getAttribute('aria-expanded') === 'true') {
                present(false);
                load(0);
                return;
            }
            load(Number(ent.dataset.kbhVersion) || 0);
            return;
        }
        if (t.closest('[data-kbh-look]')) {
            // Au téléphone : le volet laisse la place à la grille d'alors,
            // bandeau compris (il dit de quel moment il s'agit).
            panel.hidden = true;
            document.body.classList.remove('kbh-on');
            state.open = false;
            const target = banner && !banner.hidden ? banner : card;
            const still  = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            target?.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'start' });
        }
    });

    panel.addEventListener('submit', (e) => {
        const form = e.target.closest('form.kbh-restore');
        if (!form) return;
        e.preventDefault();
        confirmRestore(form);
    });

    banner?.addEventListener('click', (e) => {
        const t = e.target;
        if (t.closest('[data-kbh-present]')) { present(true); return; }
        const restore = t.closest('[data-kbh-restore]');
        if (restore) {
            confirmRestore(body.querySelector('form[data-kbh-restore-form="' + Number(restore.dataset.kbhRestore) + '"]'));
        }
    });

    dialog?.addEventListener('click', (e) => {
        if (e.target.closest('[data-kbh-cancel]')) { state.pending = null; dialog.close(); return; }
        if (e.target.closest('[data-kbh-confirm]')) {
            const form = state.pending;
            state.pending = null;
            dialog.close();
            if (form) form.submit();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !state.open || (dialog && dialog.open)) return;
        setOpen(false);
    });

    document.querySelector('[data-kbh-toast-close]')?.addEventListener('click', (e) => {
        e.target.closest('.kbh-toast')?.remove();
    });

    // `?historique=1` (après un retour en arrière, lien de la fiche) : ouvert d'emblée.
    try {
        if (new URLSearchParams(window.location.search).get('historique') === '1') setOpen(true);
    } catch (e) { /* adresse illisible : volet fermé */ }
})();
