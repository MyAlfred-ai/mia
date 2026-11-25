/**
 * Volet « Tables de cette conversation » : ses tables de travail, et les
 * tables PERMANENTES qui y sont engagées (bases de nature « table », cochées
 * ou portées par l'assistant) — en lecture seule. Une table de travail est
 * désignée par son identifiant, une table permanente par « p » + celui de
 * sa base (`p12`) : `ref()` garde l'un comme l'autre.
 *
 * LA RÈGLE : une table ne s'affiche jamais d'elle-même dans le fil. Sous la
 * réponse qui l'a établie, une ligne discrète la nomme avec un bouton
 * « Voir » ; la puce de la zone de saisie dit combien la conversation en
 * compte. Le volet ne s'ouvre qu'à l'un de ces deux clics.
 *
 * AUCUN LIBELLÉ ICI : le volet (liste, lignes, filtre, exports, renommer,
 * supprimer) est rendu par le serveur — GET /chat/{id}/tables rend son HTML
 * tout fait (templates/partials/work-tables-panel.php), la ligne sous une
 * réponse aussi (work-table-line.php). Ce script pose, écoute et redemande.
 *
 * Chargé APRÈS chat.js : il en reprend la garde de session
 * (fetchWithSessionGuard), l'état des volets (setViewerLayoutState) et la
 * fermeture des autres volets — de façon défensive, chacun peut manquer.
 */
(function () {
    'use strict';

    const viewer = document.getElementById('tablesViewer');
    const body   = document.getElementById('tablesViewerBody');
    if (!viewer || !body) return;

    const loading = document.getElementById('tablesViewerLoading');
    const chip    = document.getElementById('tablesChip');

    /** Table choisie, filtre et page : ce que le volet montre. `seq` écarte une réponse périmée. */
    const state = { table: 0, q: '', page: 1, seq: 0, timer: null };

    /**
     * Référence d'une table : un identifiant (> 0), « p12 » pour une table
     * permanente, « p12.5.<recette> » pour une vue sans nom de celle-ci. 0 = aucune.
     */
    function ref(value) {
        const text = String(value == null ? '' : value);
        if (/^p[1-9]\d{0,9}(\.\d{1,6}\.[A-Za-z0-9_-]{4,1800})?$/.test(text)) return text;
        const n = Number(text);
        return Number.isInteger(n) && n > 0 ? n : 0;
    }

    /** Délai avant d'appliquer un filtre en cours de frappe (ms). */
    const FILTER_DELAY = 280;

    function conv() {
        return String(document.getElementById('convId')?.value || '');
    }

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    function isOpen() {
        return viewer.classList.contains('open');
    }

    function layout() {
        if (typeof window.setViewerLayoutState === 'function') window.setViewerLayoutState();
    }

    function guardedFetch(url, init) {
        return (typeof window.fetchWithSessionGuard === 'function' ? window.fetchWithSessionGuard : fetch)(url, init);
    }

    // ------------------------------------------------------------------
    // Puce de la zone de saisie et ligne sous une réponse
    // ------------------------------------------------------------------

    /** `info` = WorkTableView::chip() : {count, label, title}. Rien = aucune table. */
    function setChip(info) {
        if (!chip) return;
        const count = Number(info && info.count) || 0;
        const label = chip.querySelector('[data-tables-chip-label]');
        const n     = chip.querySelector('[data-tables-chip-count]');
        const title = String((info && info.title) || '');
        chip.hidden = count <= 0;
        if (label) label.textContent = String((info && info.label) || '');
        if (n) n.textContent = String(count);
        chip.title = title;
        chip.setAttribute('aria-label', title);
    }

    /** Pose la ligne « Voir » (HTML du serveur) sous le contenu d'une réponse — une seule par réponse. */
    function place(messageEl, html) {
        const host = messageEl && messageEl.querySelector ? messageEl.querySelector('.msg-body') : null;
        if (!host) return;
        Array.from(host.children).forEach(function (el) {
            if (el.classList.contains('msg-tables')) el.remove();
        });
        if (!html) return;
        const content = Array.from(host.children).find(function (el) { return el.classList.contains('msg-content'); });
        if (content) content.insertAdjacentHTML('afterend', String(html));
        else host.insertAdjacentHTML('beforeend', String(html));
    }

    /**
     * Après un renommage ou une suppression : `lines` (identifiant de message
     * → HTML) est la ligne à jour de chaque réponse qui nomme encore une
     * table. Une réponse affichée qui n'y figure plus perd la sienne.
     */
    function syncLines(lines) {
        const map = lines && typeof lines === 'object' ? lines : {};
        document.querySelectorAll('#chatMessages .message[data-message-id]').forEach(function (msg) {
            const html = map[String(msg.getAttribute('data-message-id'))] || '';
            if (html || msg.querySelector('.msg-tables')) place(msg, html);
        });
    }

    /** Fin d'un tour (ou d'une opération de fond) : `tables` = {chip, html}. */
    function afterTurn(messageEl, tables) {
        if (!tables) return;
        setChip(tables.chip || null);
        if (messageEl && tables.html) place(messageEl, tables.html);
        if (isOpen()) load();
    }

    // ------------------------------------------------------------------
    // Volet
    // ------------------------------------------------------------------

    function fail(message) {
        const p = document.createElement('p');
        p.className = 'wt-error';
        p.textContent = String(message || viewer.dataset.error || '');
        body.textContent = '';
        body.appendChild(p);
    }

    /** Message court dans l'en-tête de la table (refus d'un renommage, d'une suppression). */
    function say(message) {
        const el = body.querySelector('[data-table-status]');
        if (!el) { fail(message); return; }
        el.classList.remove('is-ok');
        el.textContent = String(message || viewer.dataset.error || '');
        el.hidden = false;
    }

    /** Message rendu par le serveur (lien compris), dans l'en-tête de la table. */
    function sayHtml(html) {
        const el = body.querySelector('[data-table-status]');
        if (!el) return;
        el.innerHTML = String(html || '');
        el.classList.add('is-ok');
        el.hidden = false;
    }

    function toggleKeep(show) {
        const form = body.querySelector('[data-table-keep-form]');
        if (!form) return;
        form.hidden = !show;
        if (show) {
            const input = form.querySelector('input[name="name"]');
            if (input) {
                input.focus();
                input.select();
            }
        }
    }

    /**
     * Compléter la table permanente d'origine avec les colonnes d'un résultat
     * tiré d'elle : le serveur dit ce qu'il fera (phrase à confirmer), puis
     * écrit — un nouvel état de l'historique de la table.
     */
    async function updateSource(button) {
        const id = selectedId();
        if (!id) return;
        button.disabled = true;
        const check = await post(id, 'update-source', {});
        if (!check || !check.ok) {
            if (button.isConnected) button.disabled = false;
            if (check) say(check.error);
            return;
        }
        if (!window.confirm(String(check.confirm || ''))) {
            if (button.isConnected) button.disabled = false;
            return;
        }
        const data = await post(id, 'update-source', { go: '1' });
        if (!data) return;
        if (!data.ok) {
            if (button.isConnected) button.disabled = false;
            say(data.error);
            return;
        }
        state.q = '';
        state.page = 1;
        apply(data, false);
        if (data.status_html) sayHtml(data.status_html);
    }

    /** Garder : une copie permanente (Bases de connaissances). */
    async function keep(form) {
        const id     = selectedId();
        const input  = form.querySelector('input[name="name"]');
        const submit = form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;
        const data = await post(id, 'keep', { name: input ? input.value : '' });
        if (submit && submit.isConnected) submit.disabled = false;
        if (!data) return;
        if (!data.ok) {
            say(data.error);
            return;
        }
        apply(data, false);
        if (data.status_html) sayHtml(data.status_html);
    }

    function apply(data, gridOnly) {
        setChip(data.chip || null);
        state.table = ref(data.selected);
        if (gridOnly) {
            // Filtre ou page : seules les lignes changent — le champ de filtre
            // garde le curseur, la liste ne bouge pas.
            const tpl = document.createElement('template');
            tpl.innerHTML = String(data.html || '');
            const fresh   = tpl.content.querySelector('[data-tables-grid]');
            const current = body.querySelector('[data-tables-grid]');
            if (fresh && current) {
                current.replaceWith(fresh);
                return;
            }
        }
        body.innerHTML = String(data.html || '');
    }

    async function load(opts) {
        const cid = conv();
        if (!cid) {
            body.textContent = '';
            return;
        }
        const seq      = ++state.seq;
        const gridOnly = !!(opts && opts.gridOnly);
        const params   = new URLSearchParams();
        if (state.table) params.set('table', String(state.table));
        if (state.q) params.set('q', state.q);
        if (state.page > 1) params.set('page', String(state.page));
        const query = params.toString();
        if (loading && !body.firstElementChild) loading.hidden = false;
        viewer.classList.add('is-busy');
        try {
            const res = await guardedFetch('/chat/' + cid + '/tables' + (query ? '?' + query : ''), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            // Réponse d'une demande dépassée, ou d'une autre conversation : ignorée.
            if (seq !== state.seq || cid !== conv()) return;
            if (!data || !data.ok) {
                fail(data && data.error);
                return;
            }
            apply(data, gridOnly);
        } catch (err) {
            if (seq === state.seq && !(err && err.message === 'SESSION_EXPIRED')) fail('');
        } finally {
            if (seq === state.seq) {
                viewer.classList.remove('is-busy');
                if (loading) loading.hidden = true;
            }
        }
    }

    function refreshCsrf() {
        return fetch('/auth/csrf', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.token) return false;
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) meta.setAttribute('content', data.token);
                return true;
            })
            .catch(function () { return false; });
    }

    /** Renommer, supprimer : la réponse porte le volet à jour. Jeton périmé → repris et rejoué une fois. */
    async function post(tableId, action, fields, retried) {
        const cid = conv();
        if (!cid || !tableId) return null;
        const form = new URLSearchParams(fields || {});
        if (state.q) form.set('q', state.q);
        form.set('page', String(state.page));
        try {
            const res = await guardedFetch('/chat/' + cid + '/tables/' + tableId + '/' + action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf(),
                },
                credentials: 'same-origin',
                body: form.toString(),
            });
            const data = await res.json();
            if (data && data.code === 'csrf' && !retried && await refreshCsrf()) {
                return post(tableId, action, fields, true);
            }
            return cid === conv() ? data : null;
        } catch (err) {
            return err && err.message === 'SESSION_EXPIRED' ? null : { ok: false, error: '' };
        }
    }

    /** Table de TRAVAIL affichée (renommer, supprimer, garder) — 0 pour une table permanente, qui ne se tient pas ici. */
    function selectedId() {
        return Number(body.querySelector('[data-table-id]')?.getAttribute('data-table-id')) || 0;
    }

    function toggleRename(show) {
        const form = body.querySelector('[data-table-rename-form]');
        const row  = body.querySelector('.wt-title-row');
        if (!form) return;
        form.hidden = !show;
        if (row) row.hidden = !!show;
        if (show) {
            const input = form.querySelector('input[name="title"]');
            if (input) {
                input.focus();
                input.select();
            }
        }
    }

    async function rename(form) {
        const id    = selectedId();
        const input = form.querySelector('input[name="title"]');
        const data  = await post(id, 'rename', { title: input ? input.value : '' });
        if (!data) return;
        if (!data.ok) {
            say(data.error);
            return;
        }
        apply(data, false);
        syncLines(data.lines);
    }

    async function remove(button) {
        const id = selectedId();
        if (!id || !window.confirm(String(button.dataset.confirm || ''))) return;
        button.disabled = true;
        const data = await post(id, 'delete', {});
        if (!data) {
            button.disabled = false;
            return;
        }
        if (!data.ok) {
            button.disabled = false;
            say(data.error);
            return;
        }
        state.table = 0;
        state.q = '';
        state.page = 1;
        apply(data, false);
        syncLines(data.lines);
    }

    function open(tableId) {
        if (!conv()) return;
        // Un seul volet à la fois (fonctions de chat.js, chacune peut manquer).
        ['closeHtmlViewer', 'closeVideoViewer', 'closeSourcesViewer', 'closeMapViewer', 'closeDeckViewer'].forEach(function (name) {
            if (typeof window[name] === 'function') window[name]();
        });
        viewer.classList.add('open');
        layout();
        state.table = ref(tableId);
        state.q = '';
        state.page = 1;
        body.textContent = '';
        load();
    }

    function close() {
        if (!isOpen()) return;
        clearTimeout(state.timer);
        state.seq++;
        viewer.classList.remove('open', 'is-busy');
        body.textContent = '';
        layout();
    }

    // ------------------------------------------------------------------
    // Gestes
    // ------------------------------------------------------------------

    viewer.addEventListener('click', function (event) {
        const target = event.target;
        if (!target || !target.closest) return;
        if (target.closest('#tablesViewerClose')) {
            close();
            return;
        }
        const pick = target.closest('[data-table-select]');
        if (pick) {
            state.table = ref(pick.getAttribute('data-table-select'));
            state.q = '';
            state.page = 1;
            load();
            return;
        }
        const pager = target.closest('[data-table-page]');
        if (pager) {
            if (pager.disabled) return;
            state.page = Math.max(1, Number(pager.getAttribute('data-table-page')) || 1);
            load({ gridOnly: true });
            return;
        }
        if (target.closest('[data-table-rename-cancel]')) {
            toggleRename(false);
            return;
        }
        if (target.closest('[data-table-rename]')) {
            toggleRename(true);
            return;
        }
        if (target.closest('[data-table-keep-cancel]')) {
            toggleKeep(false);
            return;
        }
        if (target.closest('[data-table-keep]')) {
            toggleKeep(true);
            return;
        }
        const upd = target.closest('[data-table-update-source]');
        if (upd && !upd.disabled) {
            updateSource(upd);
            return;
        }
        const del = target.closest('[data-table-delete]');
        if (del && !del.disabled) remove(del);
    });

    viewer.addEventListener('submit', function (event) {
        const keepForm = event.target && event.target.closest ? event.target.closest('[data-table-keep-form]') : null;
        if (keepForm) {
            event.preventDefault();
            keep(keepForm);
            return;
        }
        const form = event.target && event.target.closest ? event.target.closest('[data-table-rename-form]') : null;
        if (!form) return;
        event.preventDefault();
        rename(form);
    });

    viewer.addEventListener('input', function (event) {
        const field = event.target && event.target.closest ? event.target.closest('[data-table-filter]') : null;
        if (!field) return;
        clearTimeout(state.timer);
        state.timer = setTimeout(function () {
            state.q = field.value.trim();
            state.page = 1;
            load({ gridOnly: true });
        }, FILTER_DELAY);
    });

    viewer.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        const form = body.querySelector('[data-table-rename-form]');
        if (form && !form.hidden) {
            event.stopPropagation();
            toggleRename(false);
        }
    });

    // « Voir » sous une réponse (ou « + N autres tables ») : la table s'ouvre dans le volet.
    document.addEventListener('click', function (event) {
        const target = event.target;
        const opener = target && target.closest ? target.closest('#chatMessages [data-table-open]') : null;
        if (!opener) return;
        event.preventDefault();
        open(opener.getAttribute('data-table-open'));
    });

    if (chip) {
        chip.addEventListener('click', function () {
            if (isOpen()) close();
            else open(0);
        });
    }

    /**
     * Les tables de la conversation ont pu changer sans qu'un tour le dise :
     * une table permanente cochée ou décochée dans le sélecteur de bases,
     * une modification approuvée sur une carte. Volet ouvert : il se
     * recharge (la puce suit). Fermé : seule la puce est redemandée.
     */
    let syncSeq = 0;
    async function sync() {
        if (isOpen()) {
            load();
            return;
        }
        const cid = conv();
        if (!cid || !chip) return;
        const seq = ++syncSeq;
        try {
            const res = await guardedFetch('/chat/' + cid + '/tables?chip=1', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (seq === syncSeq && cid === conv() && data && data.ok) setChip(data.chip || null);
        } catch (err) { /* la puce garde son état : rien à dire */ }
    }

    // Une carte tranchée (une modification de table approuvée) ou un choix
    // de bases changé : chat.js l'annonce, le volet et la puce suivent.
    window.addEventListener('mia:card-decided', function () { sync(); });
    document.addEventListener('mia:kb-selection-changed', function () { sync(); });

    window.MiaTables = {
        open: open,
        close: close,
        place: place,
        setChip: setChip,
        afterTurn: afterTurn,
        refresh: function () { if (isOpen()) load(); },
        sync: sync,
    };
})();
