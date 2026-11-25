/* ==========================================================================
   Tables PERMANENTES — fiche d'une table (Bases de connaissances) et page
   « Nouvelle table ».

   La zone #kbTableSection est rendue par le serveur (KbTableView,
   partiel table-section.php) et lui revient toute faite après chaque geste
   (KbTableController) : ce script ne porte AUCUN libellé. Il pose le HTML
   reçu, garde la page et le filtre, et corrige une cellule sur place sans
   re-rendre la grille (le curseur reste où il est).

   Rien ici ne passe par l'enregistrement automatique de la fiche : la zone
   est marquée data-autosave-skip et ses contrôles n'ont pas de nom.

   Historique (page de consultation) : l'évènement `mia:kb-table-version`
   (kb-history.js) fait montrer un état passé — même grille, en lecture
   seule, `version` ajouté aux demandes ; 0 = revenir à la table en service.
   ========================================================================== */
(function () {
    'use strict';

    // ---------- Page « Nouvelle table » : fichier OU colonnes saisies ----------
    const creation = document.getElementById('kbTableNew');
    if (creation) {
        const sync = () => {
            const chosen = creation.querySelector('[data-kbt-new-source]:checked');
            const value  = chosen ? chosen.value : 'file';
            creation.querySelectorAll('[data-kbt-new-block]').forEach((block) => {
                const on = block.getAttribute('data-kbt-new-block') === value;
                block.hidden = !on;
                // Un champ masqué ne part pas, et ne bloque pas l'envoi.
                block.querySelectorAll('input').forEach((input) => { input.disabled = !on; });
            });
        };
        // Le partage aux groupes et aux personnes ne vaut que pour une table
        // commune : privée ou publique, il disparaît et ne part pas.
        const syncShare = () => {
            const vis   = creation.querySelector('[data-kbt-visibility]:checked');
            const share = creation.querySelector('[data-kbt-share]');
            if (!share) return;
            const on = !vis || vis.value === 'common';
            share.hidden = !on;
            share.querySelectorAll('input').forEach((input) => { input.disabled = !on; });
        };
        creation.addEventListener('change', (e) => {
            if (e.target.matches('[data-kbt-new-source]')) sync();
            if (e.target.matches('[data-kbt-visibility]')) syncShare();
        });
        sync();
        syncShare();
    }

    const zone = document.getElementById('kbTableSection');
    if (!zone) return;

    const kbId = Number(zone.dataset.kbId) || 0;
    const base = '/admin/knowledge-bases/' + kbId + '/table/';
    const FILTER_DELAY = 280;
    const CELL_CHARS   = 140;

    const state = { q: '', page: 1, seq: 0, timer: null, importMode: 'replace', column: '', version: 0 };
    const grid0 = zone.querySelector('[data-kbt-grid]');
    if (grid0) state.page = Number(grid0.dataset.page) || 1;
    const filter0 = zone.querySelector('[data-kbt-filter]');
    if (filter0) state.q = filter0.value || '';

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    async function refreshCsrf() {
        try {
            const res  = await fetch('/auth/csrf', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            const token = data && (data.token || data.csrf_token);
            if (!token) return false;
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) meta.content = token;
            document.querySelectorAll('input[name="csrf_token"]').forEach((el) => { el.value = token; });
            return true;
        } catch (e) {
            return false;
        }
    }

    function say(text, isError) {
        const box = zone.querySelector('[data-kbt-message]');
        if (!box) return;
        box.textContent = text || '';
        box.hidden = !text;
        box.classList.toggle('is-error', !!isError);
    }

    function fail(data) {
        say((data && data.error) || zone.dataset.error || '', true);
    }

    /** Pose la fiche rendue par le serveur, et tient l'en-tête de la page à jour. */
    function apply(data) {
        if (!data || !data.ok || typeof data.html !== 'string') return false;
        zone.innerHTML = data.html;
        state.page = Number(data.page) || 1;
        if (typeof data.meta === 'string') {
            zone.dataset.summary = data.meta;
            const head = document.getElementById('kbTableHeadMeta');
            if (head) head.textContent = data.meta;
        }
        document.dispatchEvent(new CustomEvent('mia:kb-table-changed'));
        if (data.message) say(data.message, false);
        return true;
    }

    async function get(gridOnly) {
        const mine = ++state.seq;
        // Page de consultation : la grille reste en lecture seule (le serveur
        // ne rend aucun contrôle d'écriture quand `readonly` est posé).
        const url = base + 'rows?page=' + encodeURIComponent(state.page) + '&q=' + encodeURIComponent(state.q)
            + (zone.dataset.readonly === '1' ? '&readonly=1' : '')
            + (state.version > 0 ? '&version=' + encodeURIComponent(state.version) : '');
        try {
            const res  = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (mine !== state.seq) return;
            if (!data || !data.ok) { fail(data); return; }
            if (gridOnly) {
                // Filtre ou page : seule la grille change — le champ de filtre
                // garde son curseur.
                const tmp = document.createElement('div');
                tmp.innerHTML = data.html;
                const next = tmp.querySelector('[data-kbt-grid]');
                const cur  = zone.querySelector('[data-kbt-grid]');
                if (next && cur) {
                    cur.replaceWith(next);
                    state.page = Number(data.page) || 1;
                    return;
                }
            }
            apply(data);
        } catch (e) {
            if (mine === state.seq) fail(null);
        }
    }

    async function post(action, fields, retried) {
        const isForm = fields instanceof FormData;
        const body   = isForm ? fields : new URLSearchParams(fields || {});
        if (!body.has('q')) body.set('q', state.q);
        if (!body.has('page')) body.set('page', String(state.page));
        const headers = { 'Accept': 'application/json', 'X-CSRF-Token': csrf() };
        if (!isForm) headers['Content-Type'] = 'application/x-www-form-urlencoded';
        try {
            const res  = await fetch(base + action, { method: 'POST', credentials: 'same-origin', headers, body: isForm ? body : body.toString() });
            const data = await res.json().catch(() => null);
            if (data && data.code === 'csrf' && !retried && await refreshCsrf()) {
                return post(action, fields, true);
            }
            return data || { ok: false };
        } catch (e) {
            return { ok: false };
        }
    }

    /** Un geste qui re-rend la fiche : le bouton est désactivé le temps de l'aller-retour. */
    async function act(action, fields, button) {
        if (button) button.disabled = true;
        say('', false);
        const data = await post(action, fields);
        if (button && button.isConnected) button.disabled = false;
        if (!apply(data)) fail(data);
        return data;
    }

    // ------------------------------ Cellules ------------------------------

    function shown(value) {
        const flat = String(value || '').replace(/\s+/g, ' ').trim();
        return flat.length > CELL_CHARS ? flat.slice(0, CELL_CHARS - 1) + '…' : flat;
    }

    function editCell(cell) {
        if (!cell || cell.querySelector('input')) return;
        const before = cell.dataset.raw || '';
        const input  = document.createElement('input');
        input.type = 'text';
        input.className = 'kbt-cell-input';
        input.value = before;
        input.setAttribute('aria-label', cell.closest('table')?.querySelectorAll('thead th')[cell.cellIndex]?.textContent || '');
        cell.textContent = '';
        cell.appendChild(input);
        cell.classList.add('is-editing');
        input.focus();
        input.select();

        let done = false;
        const close = (value) => {
            done = true;
            cell.classList.remove('is-editing');
            cell.dataset.raw = value;
            cell.textContent = shown(value);
            if (String(value).replace(/\s+/g, ' ').trim().length > CELL_CHARS) cell.title = value;
            else cell.removeAttribute('title');
        };
        const save = async (refocus) => {
            if (done) return;
            const value = input.value;
            if (value === before) { close(before); if (refocus) cell.focus(); return; }
            done = true;
            cell.classList.add('is-saving');
            const pos  = cell.parentElement.dataset.pos;
            const data = await post('cell', { position: pos, key: cell.dataset.key, value });
            cell.classList.remove('is-saving');
            done = false;
            if (data && data.ok) {
                close(typeof data.value === 'string' ? data.value : value);
                say('', false);
                document.dispatchEvent(new CustomEvent('mia:kb-table-changed'));
            } else {
                close(before);
                fail(data);
            }
            if (refocus && cell.isConnected) cell.focus();
        };
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); save(true); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(before); cell.focus(); }
        });
        input.addEventListener('blur', () => { save(false); });
    }

    // ------------------------------ Colonnes ------------------------------

    function columnForm(show, key, label, type) {
        const form = zone.querySelector('[data-kbt-col-form]');
        if (!form) return;
        form.hidden = !show;
        state.column = show ? (key || '') : '';
        const input = form.querySelector('[data-kbt-col-label]');
        const sel   = form.querySelector('[data-kbt-col-type]');
        const del   = form.querySelector('[data-kbt-col-delete]');
        if (del) del.hidden = !key;
        if (!show) return;
        if (input) { input.value = label || ''; input.focus(); input.select(); }
        if (sel) sel.value = type || 'text';
    }

    function saveColumn(button) {
        const form  = zone.querySelector('[data-kbt-col-form]');
        if (!form) return;
        const label = form.querySelector('[data-kbt-col-label]')?.value || '';
        const type  = form.querySelector('[data-kbt-col-type]')?.value || 'text';
        if (state.column) act('column-update', { key: state.column, label, type }, button);
        else act('column', { label, type }, button);
    }

    // ------------------------------ Écoutes ------------------------------

    zone.addEventListener('click', (e) => {
        const t = e.target;
        const cell = t.closest('.kbt-grid.is-editable td.kbt-cell');
        if (cell && !t.closest('input')) { editCell(cell); return; }

        const pageBtn = t.closest('[data-kbt-page]');
        if (pageBtn && !pageBtn.disabled) { state.page = Number(pageBtn.dataset.kbtPage) || 1; get(true); return; }

        const addRow = t.closest('[data-kbt-row-add]');
        if (addRow) {
            state.q = '';
            act('row', { q: '' }, addRow).then((data) => {
                if (!data || !data.ok) return;
                const row = zone.querySelector('tr[data-pos="' + Number(data.focus) + '"]');
                const first = row ? row.querySelector('td.kbt-cell') : null;
                if (first) { first.scrollIntoView({ block: 'nearest' }); editCell(first); }
            });
            return;
        }

        const delRow = t.closest('[data-kbt-row-delete]');
        if (delRow) {
            if (delRow.dataset.confirm && !window.confirm(delRow.dataset.confirm)) return;
            act('row-delete', { position: delRow.closest('tr').dataset.pos }, delRow);
            return;
        }

        const col = t.closest('[data-kbt-col]');
        if (col) { columnForm(true, col.dataset.kbtCol, col.dataset.label, col.dataset.type); return; }
        if (t.closest('[data-kbt-col-add]')) { columnForm(true, '', '', 'text'); return; }
        if (t.closest('[data-kbt-col-cancel]')) { columnForm(false); return; }
        const saveCol = t.closest('[data-kbt-col-save]');
        if (saveCol) { saveColumn(saveCol); return; }
        const delCol = t.closest('[data-kbt-col-delete]');
        if (delCol && state.column) {
            if (delCol.dataset.confirm && !window.confirm(delCol.dataset.confirm)) return;
            act('column-delete', { key: state.column }, delCol);
            return;
        }

        const imp = t.closest('[data-kbt-import]');
        if (imp) {
            if (imp.dataset.confirm && !window.confirm(imp.dataset.confirm)) return;
            state.importMode = imp.dataset.kbtImport === 'append' ? 'append' : 'replace';
            const file = zone.querySelector('[data-kbt-file]');
            if (file) { file.value = ''; file.click(); }
            return;
        }

    });

    // Un état de l'historique, ou la table en service (0) : la grille entière
    // est re-rendue (les colonnes d'alors peuvent différer).
    document.addEventListener('mia:kb-table-version', (e) => {
        const version = Number(e.detail && e.detail.version) || 0;
        if (version === state.version) return;
        state.version = version;
        state.page = 1;
        state.q = '';
        get(false);
    });

    zone.addEventListener('change', (e) => {
        if (!e.target.matches('[data-kbt-file]')) return;
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        const form = new FormData();
        form.set('file', file);
        form.set('mode', state.importMode);
        form.set('q', '');
        state.q = '';
        zone.classList.add('is-busy');
        act('import', form, null).finally(() => zone.classList.remove('is-busy'));
    });

    zone.addEventListener('input', (e) => {
        if (!e.target.matches('[data-kbt-filter]')) return;
        clearTimeout(state.timer);
        const value = e.target.value;
        state.timer = setTimeout(() => {
            state.q = value;
            state.page = 1;
            get(true);
        }, FILTER_DELAY);
    });

    zone.addEventListener('keydown', (e) => {
        const t = e.target;
        // Entrée ou F2 sur une cellule : la corriger. Entrée dans l'éditeur
        // d'une colonne : valider — jamais soumettre la fiche.
        if (t.matches('td.kbt-cell') && (e.key === 'Enter' || e.key === 'F2') && t.closest('.is-editable')) {
            e.preventDefault();
            editCell(t);
            return;
        }
        if (t.closest('[data-kbt-col-form]')) {
            if (e.key === 'Enter' && !e.isComposing && !t.matches('button')) { e.preventDefault(); saveColumn(null); }
            else if (e.key === 'Escape') { e.preventDefault(); columnForm(false); }
            return;
        }
        if (t.matches('[data-kbt-filter]') && e.key === 'Enter') e.preventDefault();
    });
})();
