/* ==========================================================================
   Espaces → Bases de connaissances : la liste (piste D, recherche d'abord).

   Recherche (sans casse ni accents, tous les mots), portée (Tout / Bases /
   Tables) et visibilité (Privées / Communes / Publiques) filtrent les lignes
   DÉJÀ rendues par le serveur. « / » met le curseur dans la recherche. L'état
   de chaque base se rafraîchit sans recharger (GET /admin/knowledge-bases/
   status). Aucun libellé ici : tout vient du gabarit.
   ========================================================================== */
(function () {
    'use strict';

    const root = document.getElementById('kbList');
    if (!root) return;

    const input  = document.getElementById('kbListSearch');
    const none   = document.getElementById('kbListNone');
    const groups = document.getElementById('kbGroups');
    const rows   = Array.from(root.querySelectorAll('[data-kb-row]'));

    const fold = (s) => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    rows.forEach((row) => { row.dataset.fold = fold(row.dataset.search); });

    const state = { q: '', scope: 'all', vis: '' };

    function apply() {
        const words = fold(state.q).split(/\s+/).filter(Boolean);
        let total = 0;
        root.querySelectorAll('[data-group]').forEach((section) => {
            const kind = section.dataset.group;
            const inScope = state.scope === 'all' || state.scope === kind;
            section.hidden = !inScope;
            let shown = 0;
            section.querySelectorAll('[data-kb-row]').forEach((row) => {
                const ok = inScope
                    && (state.vis === '' || row.dataset.vis === state.vis)
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
        if (groups) groups.classList.toggle('is-one', state.scope !== 'all');
        if (none) none.hidden = total > 0 || rows.length === 0;
    }

    if (input) {
        input.addEventListener('input', () => { state.q = input.value; apply(); });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && input.value !== '') { input.value = ''; state.q = ''; apply(); }
            if (e.key === 'Enter') {
                // Une seule ligne trouvée : on l'ouvre.
                const visible = rows.filter((r) => !r.hidden);
                if (visible.length === 1) { e.preventDefault(); window.location.href = visible[0].href; }
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
            const t = e.target;
            if (t && (t.isContentEditable || /^(input|textarea|select)$/i.test(t.tagName))) return;
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
    root.querySelectorAll('[data-vis-filter]').forEach((btn) => {
        btn.addEventListener('click', () => {
            state.vis = state.vis === btn.dataset.visFilter ? '' : btn.dataset.visFilter;
            root.querySelectorAll('[data-vis-filter]').forEach((b) => {
                const on = b.dataset.visFilter === state.vis;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            apply();
        });
    });

    // ---------- État vivant ----------
    const IDLE = Number(root.dataset.pollIdle) || 45000;
    const BUSY = Number(root.dataset.pollBusy) || 3000;
    let timer = null;
    let inflight = false;

    async function refresh() {
        clearTimeout(timer);
        if (document.hidden || inflight || rows.length === 0) return;
        inflight = true;
        let next = IDLE;
        try {
            const r = await fetch('/admin/knowledge-bases/status', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            const data = await r.json().catch(() => ({}));
            if (data && data.ok) {
                (data.bases || []).forEach((b) => {
                    const row = root.querySelector('[data-kb-row="' + Number(b.id) + '"]');
                    if (!row || row.classList.contains('is-off')) return;
                    const dot = row.querySelector('.kbx-st .kbx-dot');
                    const txt = row.querySelector('[data-kb-state]');
                    if (dot) dot.dataset.tone = b.tone || 'ok';
                    if (txt) txt.textContent = b.title || '';
                });
                next = Number(data.poll_ms) || next;
            }
        } catch (_e) {
            next = 15000;
        } finally {
            inflight = false;
        }
        timer = setTimeout(refresh, next);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    const busyNow = rows.some((r) => {
        const dot = r.querySelector('.kbx-st .kbx-dot');
        return dot && dot.dataset.tone === 'busy';
    });
    timer = setTimeout(refresh, busyNow ? BUSY : IDLE);

    apply();
})();
