/* ==========================================================================
   Page de CONSULTATION d'une base ou d'une table (piste D).

   « Que sait-elle ? » (GET /admin/knowledge-bases/{id}/search), onglets des
   documents, synchronisation à la demande et état vivant (pour qui gère la
   base), et le schéma du bas (MiaKbDiagram). Aucun libellé ici : les textes
   viennent du serveur (attributs data-*, panneau d'état rendu).
   ========================================================================== */
(function () {
    'use strict';

    const conf = window.KB_SHOW || {};
    const page = document.getElementById('kbShow');
    if (!page) return;
    const kbId = Number(page.dataset.kbId) || 0;

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const esc  = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // ---------- Schéma ----------
    const host = document.getElementById('kbShowDiagram');
    if (host && conf.spec && conf.catalog && window.MiaKbDiagram) {
        window.MiaKbDiagram.mount(host, conf.spec, conf.catalog, { animate: true });
    }

    // ---------- Onglets des documents ----------
    const list = document.getElementById('kbShowDocList');
    const more = document.getElementById('kbShowDocMore');
    let docTab = 'all';
    let expanded = false;
    const rowMatches = (li) => docTab === 'all' || li.dataset[docTab] === '1';
    function paintDocs() {
        if (!list) return;
        let shown = 0;
        list.querySelectorAll('.kbx-doc').forEach((li) => {
            const extra = li.hasAttribute('data-extra') && !expanded && docTab === 'all';
            li.hidden = !rowMatches(li) || extra;
            if (!li.hidden) shown++;
        });
        if (more) more.hidden = expanded || docTab !== 'all';
        return shown;
    }
    /** Comptes des onglets, relus sur les lignes (après un geste ou un suivi). */
    function recount() {
        if (!list) return;
        const rows = Array.from(list.querySelectorAll('.kbx-doc'));
        const counts = {
            all: rows.length,
            ok: rows.filter((li) => li.dataset.ok === '1').length,
            check: rows.filter((li) => li.dataset.check === '1').length,
            excluded: rows.filter((li) => li.dataset.excluded === '1').length,
        };
        document.querySelectorAll('[data-doc-count]').forEach((span) => {
            const key = span.dataset.docCount;
            span.textContent = String(counts[key] ?? 0);
            const tab = span.closest('[data-doc-tab]');
            if (tab && (key === 'check' || key === 'excluded')) {
                tab.hidden = (counts[key] ?? 0) === 0;
                // Onglet vidé sous les yeux : retour à « Tous ».
                if (tab.hidden && docTab === key) document.querySelector('[data-doc-tab="all"]')?.click();
            }
        });
    }
    /** Remplace une ligne par sa version rendue par le serveur (repli conservé). */
    function replaceRow(id, html) {
        const old = list ? list.querySelector('.kbx-doc[data-doc-id="' + id + '"]') : null;
        if (!old || typeof html !== 'string' || html.trim() === '') return;
        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const fresh = tpl.content.firstElementChild;
        if (!fresh || fresh.outerHTML === old.outerHTML) return;
        if (old.hasAttribute('data-extra')) fresh.setAttribute('data-extra', '');
        else fresh.removeAttribute('data-extra');
        old.replaceWith(fresh);
        if (window.MiaKbDoc) window.MiaKbDoc.localTimes(fresh);
    }

    // ---------- Fiche d'un document (volet) ----------
    // Heures de dernière indexation : dans le fuseau du navigateur.
    if (list && window.MiaKbDoc) window.MiaKbDoc.localTimes(list);
    let docDrawer = null;
    if (list && window.MiaKbDoc) {
        const visibleIds = () => Array.from(list.querySelectorAll('.kbx-doc'))
            .filter(rowMatches)
            .map((li) => Number(li.dataset.docId));
        docDrawer = window.MiaKbDoc.drawer({
            kbId,
            ids: visibleIds,
            reveal(id) {
                const li = list.querySelector('.kbx-doc[data-doc-id="' + id + '"]');
                if (li && li.hidden && rowMatches(li)) { expanded = true; paintDocs(); }
                return li ? li.querySelector('.kbx-doc-open') : null;
            },
            onChange(data) {
                if (data && data.deleted) {
                    list.querySelector('.kbx-doc[data-doc-id="' + data.deleted + '"]')?.remove();
                } else if (data && data.document && data.row_html) {
                    replaceRow(Number(data.document.id), data.row_html);
                }
                recount();
                paintDocs();
                if (typeof refreshState === 'function') refreshState();
            },
        });
        list.addEventListener('click', (e) => {
            const link = e.target.closest('.kbx-doc-open');
            if (!link || !list.contains(link)) return;
            e.preventDefault();
            docDrawer.open(Number(link.dataset.docId), link);
        });
    }
    document.querySelectorAll('[data-doc-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            docTab = btn.dataset.docTab || 'all';
            document.querySelectorAll('[data-doc-tab]').forEach((b) => {
                const on = b === btn;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            paintDocs();
        });
    });
    if (more) more.addEventListener('click', () => { expanded = true; paintDocs(); });

    // ---------- Que sait-elle ? ----------
    const probe = document.getElementById('kbProbe');
    if (probe) {
        const input = document.getElementById('kbProbeInput');
        const msg   = document.getElementById('kbProbeMsg');
        const hits  = document.getElementById('kbProbeHits');
        let timer = null;
        let seq   = 0;

        const say = (key) => {
            const text = key ? (probe.dataset['msg' + key] || '') : '';
            msg.textContent = text;
            msg.hidden = text === '';
        };

        // Repli sans accents ni casse, caractère par caractère : les positions
        // du texte replié sont celles du texte affiché.
        const fold = (s) => Array.from(s).map((c) => c.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().charAt(0) || c).join('');

        function highlight(text, words) {
            const chars  = Array.from(text);
            const folded = fold(text);
            const marks  = new Array(chars.length).fill(false);
            words.forEach((w) => {
                const fw = fold(String(w));
                if (fw.length < 3) return;
                let at = folded.indexOf(fw);
                while (at !== -1) {
                    for (let i = at; i < at + fw.length && i < marks.length; i++) marks[i] = true;
                    at = folded.indexOf(fw, at + fw.length);
                }
            });
            let out = '';
            let open = false;
            chars.forEach((c, i) => {
                if (marks[i] && !open) { out += '<mark>'; open = true; }
                if (!marks[i] && open) { out += '</mark>'; open = false; }
                out += esc(c);
            });
            return open ? out + '</mark>' : out;
        }

        async function search() {
            const q = input.value.trim();
            const mine = ++seq;
            hits.innerHTML = '';
            if (q === '') { say(''); return; }
            if (!q.split(/\s+/).some((w) => Array.from(w).length >= 3)) { say('Short'); return; }
            say('Searching');
            try {
                const res  = await fetch(probe.dataset.url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (mine !== seq) return;
                if (!data || !data.ok) { say('Error'); return; }
                const results = Array.isArray(data.results) ? data.results : [];
                if (results.length === 0) { say('None'); return; }
                say('');
                const words = Array.isArray(data.words) ? data.words : [];
                const label = probe.dataset.labelExtract || '{n}';
                hits.innerHTML = results.map((r) => '<li class="kbx-hit"><div class="kbx-hit-top"><b>' + esc(r.file_name || '') + '</b><span>· '
                    + esc(label.replace('{n}', String((Number(r.chunk_index) || 0) + 1))) + '</span></div><p>'
                    + highlight(String(r.excerpt || ''), words) + '</p></li>').join('');
            } catch (_e) {
                if (mine === seq) say('Error');
            }
        }

        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(search, 320);
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); search(); }
        });
    }

    // ---------- Source et état : synchroniser, suivre ----------
    const panel = document.getElementById('kbStatusPanel');
    const state = document.getElementById('kbShowState');
    var refreshState = null;
    if (panel && panel.dataset.canManage === '1') {
        let pollTimer = null;

        async function refresh() {
            try {
                const res  = await fetch('/admin/knowledge-bases/' + kbId + '/sync-status' + (list ? '?rows=1' : ''), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (!data || !data.ok) return;
                if (typeof data.panel_html === 'string') panel.innerHTML = data.panel_html;
                // Les lignes des documents suivent (rendues par le serveur).
                if (list && data.rows && typeof data.rows === 'object') {
                    Object.keys(data.rows).forEach((id) => replaceRow(Number(id), data.rows[id]));
                    recount();
                    paintDocs();
                }
                if (docDrawer) docDrawer.refresh();
                if (state) {
                    const dot = state.querySelector('.kbx-dot');
                    const b   = state.querySelector('b');
                    if (dot && dot.dataset.tone !== 'off') dot.dataset.tone = data.tone || 'ok';
                    if (b && dot && dot.dataset.tone !== 'off') b.textContent = data.title || '';
                }
                clearTimeout(pollTimer);
                if (data.busy) pollTimer = setTimeout(refresh, Number(conf.poll_busy) || 3000);
            } catch (_e) { /* état inchangé jusqu'au prochain passage */ }
        }

        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-kb-sync-now]');
            if (!btn || !panel.contains(btn)) return;
            e.preventDefault();
            if (btn.disabled) return;
            btn.disabled = true;
            btn.classList.add('is-busy');
            const fd = new FormData();
            fd.append('csrf_token', csrf());
            try {
                const res  = await fetch('/admin/knowledge-bases/' + Number(btn.dataset.kbSyncNow) + '/sync', {
                    method: 'POST', body: fd, credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json().catch(() => ({}));
                if (!data.ok) {
                    btn.disabled = false;
                    btn.classList.remove('is-busy');
                    if (data.error) window.alert(data.error);
                    return;
                }
            } catch (_e) {
                btn.disabled = false;
                btn.classList.remove('is-busy');
                return;
            }
            refresh();
        });

        refreshState = refresh;
        // Une base qui travaille à l'ouverture de la page : on la suit.
        if (panel.querySelector('[data-busy="1"], .is-busy')) refresh();
    }

    // Un lien #document-ID ouvre la fiche du document au chargement.
    if (docDrawer) docDrawer.openFromHash();
})();
