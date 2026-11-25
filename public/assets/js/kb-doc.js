/* ==========================================================================
   Fiche d'un document d'une base de connaissances — window.MiaKbDoc.

   UN module pour la page d'une base (kb-show.js) et sa fiche (kb-form.js) :
     - `chunkViewer(o)` : une vue d'extraits (liste, recherche, pagination),
       aussi celle de la section « Avancé » de la fiche ;
     - `drawer(o)`      : le volet d'un document — sa fiche rendue par le
       serveur (GET …/documents/ID : étapes, ce qui ne va pas et pourquoi,
       gestes), ses extraits, le passage au document précédent / suivant ;
     - `formatTime(iso)`, `localTimes(root)` : les heures (dernière
       indexation) dans le fuseau du navigateur — le serveur parle UTC.

   Les gestes (Réessayer, Exclure, Rétablir, Supprimer) sont des boutons
   `data-doc-act` de la fiche rendue : le volet poste leur adresse, pose la
   fiche renvoyée et prévient la page (`onChange`). Aucun libellé ici hors
   ceux des extraits (dictionnaire client, t()).
   ========================================================================== */
(function () {
    'use strict';

    // t() et tn() : ceux de head-meta (window.t, window.tn), jamais redéclarés.
    const EN     = !!(window.I18N && window.I18N.lang === 'en');
    const LOCALE = EN ? 'en-US' : 'fr-FR';
    const byId   = (id) => document.getElementById(id);
    const show   = (el, on) => { if (el) el.hidden = !on; };

    function csrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    /** Un instant ISO (UTC) dans le fuseau et la langue du navigateur : « 19/11/2026 14:32 ». */
    function formatTime(iso) {
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return '';
        return d.toLocaleString(LOCALE, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    /** Réécrit les <time data-local> rendus par le serveur (en UTC) dans l'heure du navigateur. */
    function localTimes(root) {
        (root || document).querySelectorAll('time[data-local][datetime]').forEach((el) => {
            const text = formatTime(el.getAttribute('datetime'));
            if (text) el.textContent = text;
        });
    }

    async function postJson(url) {
        const fd = new FormData();
        fd.append('csrf_token', csrf());
        const r = await fetch(url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await r.json().catch(() => ({}));
        return { r, data };
    }

    // =====================================================================
    // Extraits : un SEUL chargeur (…/chunks), un seul rendu
    // =====================================================================

    /** Le texte de l'extrait, le terme cherché surligné (nœuds texte : rien n'est interprété). */
    function highlighted(text, query) {
        const frag = document.createDocumentFragment();
        const q = (query || '').trim();
        if (!q) {
            frag.appendChild(document.createTextNode(text));
            return frag;
        }
        const lower = text.toLowerCase();
        const needle = q.toLowerCase();
        let at = 0;
        let hit = lower.indexOf(needle, at);
        while (hit !== -1) {
            frag.appendChild(document.createTextNode(text.slice(at, hit)));
            const mark = document.createElement('mark');
            mark.textContent = text.slice(hit, hit + q.length);
            frag.appendChild(mark);
            at = hit + q.length;
            hit = lower.indexOf(needle, at);
        }
        frag.appendChild(document.createTextNode(text.slice(at)));
        return frag;
    }

    function chunkRow(c, query, checked, showDoc) {
        const item = document.createElement('article');
        item.className = 'kb-chunk';
        item.dataset.vector = !checked ? 'unknown' : (c.vector ? 'yes' : 'no');

        const head = document.createElement('div');
        head.className = 'kb-chunk-head';
        const idx = document.createElement('span');
        idx.className = 'kb-chunk-idx';
        idx.textContent = '#' + (Number(c.chunk_index) + 1);
        head.appendChild(idx);
        // Dans le volet d'un document, son nom est déjà le titre.
        if (showDoc) {
            const doc = document.createElement('span');
            doc.className = 'kb-chunk-doc';
            doc.textContent = c.file_name;
            doc.title = c.file_name;
            head.appendChild(doc);
        }
        const meta = document.createElement('span');
        meta.className = 'kb-chunk-meta';
        meta.textContent = t('{tokens} tokens · {chars} caractères', {
            tokens: Number(c.token_count).toLocaleString(LOCALE),
            chars: Number(c.chars).toLocaleString(LOCALE),
        });
        const vec = document.createElement('span');
        vec.className = 'kb-chunk-vec';
        vec.textContent = !checked ? t('Vecteur non vérifié') : (c.vector ? t('Vectorisé') : t('Sans vecteur'));
        vec.title = !checked
            ? t('Le moteur vectoriel ne répond pas : la présence du vecteur n’a pas pu être vérifiée.')
            : (c.vector ? t('Son vecteur est dans le moteur : la recherche par le sens le retrouve.')
                : t('Aucun vecteur dans le moteur : seule la recherche par mots le retrouve. « Réindexer » le recalcule.'));
        head.append(meta, vec);

        const text = document.createElement('p');
        text.className = 'kb-chunk-text';
        text.appendChild(highlighted(c.content, query));
        if (c.cut) text.appendChild(document.createTextNode(' […]'));
        item.append(head, text);

        // Long : replié sur quatre lignes, à déplier.
        if (c.content.length > 320) {
            text.classList.add('is-clamped');
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'kb-chunk-more';
            more.textContent = t('Afficher tout');
            more.setAttribute('aria-expanded', 'false');
            more.addEventListener('click', () => {
                const open = text.classList.toggle('is-clamped') === false;
                more.textContent = open ? t('Replier') : t('Afficher tout');
                more.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            item.appendChild(more);
        }
        return item;
    }

    /**
     * Une vue d'extraits : liste, recherche, pagination. `kbId`, `docId()`
     * rend le document montré ('' = tous), `toTop()` ramène en haut.
     */
    function chunkViewer(o) {
        const st = { page: 1, pages: 1, seq: 0, loaded: false };

        function message(text) {
            o.list.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'kb-chunk-empty';
            p.textContent = text;
            o.list.appendChild(p);
            show(o.pager, false);
        }

        async function load() {
            if (!o.list || !o.kbId) return;
            const mine = ++st.seq;
            st.loaded = true;
            o.list.classList.add('is-loading');
            o.list.setAttribute('aria-busy', 'true');
            const query = o.search ? o.search.value.trim() : '';
            const params = new URLSearchParams({ page: String(st.page) });
            const doc = o.docId();
            if (doc) params.set('doc', doc);
            if (query) params.set('q', query);
            try {
                const r = await fetch('/admin/knowledge-bases/' + o.kbId + '/chunks?' + params.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                    credentials: 'same-origin',
                });
                const data = await r.json().catch(() => ({}));
                if (mine !== st.seq) return;
                if (!data || !data.ok) { message((data && data.error) || t('Lecture des extraits impossible.')); return; }
                st.page  = data.page;
                st.pages = data.pages;
                o.list.innerHTML = '';
                if (!data.chunks.length) {
                    message(query ? t('Aucun extrait ne contient « {q} ».', { q: query }) : t('Aucun extrait pour ce document.'));
                    return;
                }
                if (!data.vectors_checked) {
                    const warn = document.createElement('p');
                    warn.className = 'kb-chunk-warn';
                    warn.textContent = t('Le moteur vectoriel ne répond pas : la présence des vecteurs n’a pas pu être vérifiée.');
                    o.list.appendChild(warn);
                }
                data.chunks.forEach((c) => o.list.appendChild(chunkRow(c, query, !!data.vectors_checked, o.showDoc)));
                const many = data.pages > 1;
                show(o.pager, true);
                if (o.pageLabel) {
                    o.pageLabel.textContent = (many ? t('Page {page} / {pages}', { page: data.page, pages: data.pages }) + ' · ' : '')
                        + tn(data.total, '{total} extrait', '{total} extraits', { total: data.total.toLocaleString(LOCALE) });
                }
                show(o.prev, many);
                show(o.next, many);
                if (o.prev) o.prev.disabled = data.page <= 1;
                if (o.next) o.next.disabled = data.page >= data.pages;
            } catch (e) {
                if (mine === st.seq) message(t('Erreur réseau. Réessayez.'));
            } finally {
                if (mine === st.seq) {
                    o.list.classList.remove('is-loading');
                    o.list.removeAttribute('aria-busy');
                }
            }
        }

        let searchTimer = null;
        o.search?.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { st.page = 1; load(); }, 300);
        });
        o.prev?.addEventListener('click', () => { if (st.page > 1) { st.page--; load(); o.toTop(); } });
        o.next?.addEventListener('click', () => { if (st.page < st.pages) { st.page++; load(); o.toTop(); } });

        return {
            load,
            /** Repart de la première page (autre document, autre filtre). */
            restart(clearSearch) {
                clearTimeout(searchTimer);
                if (clearSearch && o.search) o.search.value = '';
                st.page = 1;
                return load();
            },
            /** Plus rien à montrer (document sans extrait) : la liste se vide. */
            clear() {
                st.seq++;
                clearTimeout(searchTimer);
                o.list.innerHTML = '';
                show(o.pager, false);
            },
            get loaded() { return st.loaded; },
        };
    }

    // =====================================================================
    // Le volet d'un document
    // =====================================================================

    /**
     * o.kbId, o.ids() (ordre de la liste affichée), o.onChange(data) après
     * un geste ou un changement d'état, o.reveal(id) (élément qui reprend le
     * focus à la fermeture). L'adresse porte #document-ID ; #extraits-ID
     * (ancien lien) y mène aussi.
     */
    function drawer(o) {
        const el    = byId('kbDocDrawer');
        const scrim = byId('kbDocScrim');
        if (!el || !scrim || !o || !o.kbId) return { open() {}, close() {}, refresh() {}, isOpen: () => false };
        const titleEl  = byId('kbDocDrawerTitle');
        const metaEl   = byId('kbDocDrawerMeta');
        const body     = byId('kbDocDrawerBody');
        const detail   = byId('kbDocDetail');
        const chunks   = byId('kbDocChunks');
        const search   = byId('kbDocChunkSearch');
        const prevBtn  = byId('kbDocDrawerPrev');
        const nextBtn  = byId('kbDocDrawerNext');
        const closeBtn = byId('kbDocDrawerClose');
        const pager    = byId('kbDocChunkPager');
        let docId = 0;
        let opener = null;
        let hideTimer = null;
        let pollTimer = null;
        let seq = 0;
        let lastChunks = -1;
        // Le message d'un geste survit aux rafraîchissements du suivi (même document).
        let note = null;

        const viewer = chunkViewer({
            kbId: o.kbId,
            list: byId('kbDocChunkList'),
            search,
            pager,
            prev: byId('kbDocChunkPrev'),
            next: byId('kbDocChunkNext'),
            pageLabel: byId('kbDocChunkPage'),
            docId: () => String(docId),
            toTop: () => { if (body && chunks) body.scrollTop = Math.max(0, chunks.offsetTop - 12); },
            showDoc: false,
        });

        const isOpen = () => !el.hidden && el.classList.contains('is-open');
        const ids = () => (typeof o.ids === 'function' ? o.ids() : []).map(Number);

        function nav() {
            const list = ids();
            const at = list.indexOf(docId);
            if (prevBtn) { prevBtn.disabled = at <= 0; prevBtn.hidden = list.length < 2; }
            if (nextBtn) { nextBtn.disabled = at < 0 || at >= list.length - 1; nextBtn.hidden = list.length < 2; }
        }

        function setMsg(text, tone) {
            note = text ? { text, tone, id: docId } : null;
            const box = detail ? detail.querySelector('[data-doc-msg]') : null;
            if (!box) return;
            box.textContent = text || '';
            box.dataset.tone = tone || '';
            box.hidden = !text;
        }

        /** Pose la fiche renvoyée par le serveur ; recharge les extraits s'ils ont changé. */
        function apply(data, keepChunks) {
            if (!detail || !data || typeof data.html !== 'string') return;
            const open = new Set(Array.from(detail.querySelectorAll('details[open]')).map((d) => d.className));
            detail.innerHTML = data.html;
            detail.querySelectorAll('details').forEach((d) => { if (open.has(d.className)) d.open = true; });
            if (note && note.id === docId) setMsg(note.text, note.tone);
            localTimes(detail);
            const card = detail.querySelector('.kbd');
            if (titleEl) titleEl.textContent = card ? (card.dataset.title || '') : '';
            if (metaEl) {
                metaEl.innerHTML = '';
                if (card) {
                    const chip = document.createElement('span');
                    chip.className = 'kb-doc-state';
                    chip.dataset.tone = card.dataset.tone || 'idle';
                    chip.textContent = card.dataset.label || '';
                    metaEl.appendChild(chip);
                }
            }
            const n = Number(data.chunk_count) || 0;
            show(chunks, n > 0);
            if (n > 0) {
                if (!keepChunks || n !== lastChunks) viewer.restart(!keepChunks);
            } else {
                viewer.clear();
            }
            lastChunks = n;
            nav();
            clearTimeout(pollTimer);
            if (data.busy && isOpen()) pollTimer = setTimeout(() => load(true), 2500);
        }

        async function load(quiet) {
            const mine = ++seq;
            if (!quiet && detail) detail.classList.add('is-loading');
            try {
                const r = await fetch('/admin/knowledge-bases/' + o.kbId + '/documents/' + docId, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                    credentials: 'same-origin',
                });
                const data = await r.json().catch(() => ({}));
                if (mine !== seq) return;
                if (!data || !data.ok) {
                    if (!quiet && detail) {
                        detail.innerHTML = '';
                        const p = document.createElement('p');
                        p.className = 'kb-chunk-empty';
                        p.textContent = (data && data.error) || t('Erreur réseau. Réessayez.');
                        detail.appendChild(p);
                        show(chunks, false);
                        show(pager, false);
                    }
                    return;
                }
                const before = detail ? (detail.querySelector('.kbd')?.dataset.busy || '') : '';
                apply(data, quiet);
                // Le suivi a vu le document changer d'état : la page suit.
                if (quiet && before === '1' && !data.busy && typeof o.onChange === 'function') o.onChange(data);
            } catch (_e) {
                if (mine === seq && quiet && isOpen()) pollTimer = setTimeout(() => load(true), 6000);
            } finally {
                if (mine === seq && detail) detail.classList.remove('is-loading');
            }
        }

        function display(id) {
            docId = id;
            lastChunks = -1;
            note = null;
            clearTimeout(pollTimer);
            if (titleEl) titleEl.textContent = '';
            if (metaEl) metaEl.innerHTML = '';
            if (detail) detail.innerHTML = '';
            show(chunks, false);
            show(pager, false);
            if (search) search.value = '';
            if (body) body.scrollTop = 0;
            nav();
            load(false);
            history.replaceState(null, '', location.pathname + location.search + '#document-' + id);
        }

        function open(id, from) {
            id = Number(id);
            if (!id) return;
            opener = from || null;
            clearTimeout(hideTimer);
            el.hidden = false;
            scrim.hidden = false;
            // Un reflow entre l'affichage et la classe : la glissade a lieu.
            void el.offsetWidth;
            el.classList.add('is-open');
            scrim.classList.add('is-open');
            document.body.classList.add('kbf-drawer-on');
            display(id);
            try { closeBtn.focus({ preventScroll: true }); } catch (_e) { /* rien */ }
        }

        function close() {
            if (el.hidden) return;
            clearTimeout(pollTimer);
            seq++;
            el.classList.remove('is-open');
            scrim.classList.remove('is-open');
            document.body.classList.remove('kbf-drawer-on');
            hideTimer = setTimeout(() => { el.hidden = true; scrim.hidden = true; }, 240);
            history.replaceState(null, '', location.pathname + location.search);
            // Le focus revient au document affiché (on a pu passer d'un
            // document à l'autre, et la liste a pu être redessinée).
            const back = (typeof o.reveal === 'function' ? o.reveal(docId) : null) || ((opener && opener.isConnected) ? opener : null);
            if (back) { try { back.focus({ preventScroll: true }); } catch (_e) { /* rien */ } }
            opener = null;
        }

        function step(delta) {
            const list = ids();
            const at = list.indexOf(docId);
            const next = list[at + delta];
            if (next) display(next);
        }

        // Gestes de la fiche : Réessayer, Exclure, Rétablir, Supprimer.
        detail?.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-doc-act]');
            if (!btn || !detail.contains(btn) || btn.disabled) return;
            e.preventDefault();
            const ask = btn.dataset.confirm || '';
            if (ask && !window.confirm(ask)) return;
            const act = btn.dataset.docAct;
            const card = detail.querySelector('.kbd');
            const failText = card ? (card.dataset.msgError || '') : '';
            detail.querySelectorAll('[data-doc-act]').forEach((b) => { b.disabled = true; });
            btn.classList.add('is-busy');
            try {
                const { data } = await postJson(btn.dataset.url);
                if (act === 'delete') {
                    if (data && data.ok) {
                        if (typeof o.onChange === 'function') o.onChange({ deleted: docId });
                        close();
                        return;
                    }
                    setMsg((data && data.error) || failText, 'error');
                    detail.querySelectorAll('[data-doc-act]').forEach((b) => { b.disabled = false; });
                    btn.classList.remove('is-busy');
                    return;
                }
                if (data && typeof data.html === 'string') apply(data, false);
                setMsg((data && (data.ok ? data.message : data.error)) || (data && data.ok ? '' : failText), data && data.ok ? 'ok' : 'error');
                if (typeof o.onChange === 'function') o.onChange(data || {});
            } catch (_e) {
                setMsg(failText, 'error');
                detail.querySelectorAll('[data-doc-act]').forEach((b) => { b.disabled = false; });
                btn.classList.remove('is-busy');
            }
        });

        closeBtn?.addEventListener('click', close);
        scrim.addEventListener('click', close);
        prevBtn?.addEventListener('click', () => step(-1));
        nextBtn?.addEventListener('click', () => step(1));
        const fromHash = () => {
            const m = /^#(?:document|extraits)-(\d+)$/.exec(location.hash);
            return m ? Number(m[1]) : 0;
        };
        window.addEventListener('hashchange', () => {
            const id = fromHash();
            if (id && id !== docId) open(id, null);
        });
        document.addEventListener('keydown', (e) => {
            if (!isOpen()) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                return;
            }
            // Le focus reste dans le volet (fenêtre modale).
            if (e.key === 'Tab') {
                const items = Array.from(el.querySelectorAll('button, input, a[href], summary, [tabindex="0"]'))
                    .filter((x) => !x.disabled && !x.hidden && x.offsetParent !== null);
                if (items.length === 0) return;
                const first = items[0];
                const last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });

        return {
            open,
            close,
            isOpen,
            /** L'état a pu changer ailleurs (suivi de la page) : la fiche ouverte se relit. */
            refresh() { if (isOpen()) load(true); },
            /** Document demandé par l'adresse au chargement (#document-ID). */
            openFromHash() { const id = fromHash(); if (id) open(id, null); },
            get docId() { return docId; },
        };
    }

    window.MiaKbDoc = { chunkViewer, drawer, formatTime, localTimes };
})();
