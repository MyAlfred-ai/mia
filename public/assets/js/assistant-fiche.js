/* ==========================================================================
   Fiche d'un assistant (création et modification) — la mise en page, même
   grammaire que la fiche d'une base (kb-fiche.js), posée par-dessus le
   script de la page (qui garde les réglages eux-mêmes : prompt, modèle,
   documents, schéma) :

   - la barre du haut suit la fiche : le nom, l'avatar (ou l'initiale sur la
     couleur), l'état Actif / Inactif ;
   - le menu des sections allume la section en vue et y mène ;
   - « … » ouvre ses actions (suppression).

   Aucun libellé ici : tout vient du gabarit (data-*).
   ========================================================================== */
(function () {
    'use strict';

    const page = document.getElementById('pfFiche');
    const form = document.getElementById('editProfileForm');
    if (!page || !form) return;
    const byId = (id) => document.getElementById(id);
    const reducedMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    // ---------- Barre du haut ----------
    const nameInput = byId('mpName');
    const title     = byId('pfFicheTitle');
    const headAva   = byId('pfHeadAva');
    const color     = byId('mpColor');
    const active    = byId('mpIsActive');
    const state     = byId('pfHeadState');

    function initialOf(name) {
        const n = String(name || '').trim();
        return n === '' ? '?' : Array.from(n)[0].toUpperCase();
    }
    function avatarUrl() {
        const radio = form.querySelector('input[name="avatar"]:checked');
        if (!radio) return '';
        if (radio.dataset.src) return radio.dataset.src;      // avatar créé par MIA
        return radio.value ? '/assets/img/profils/' + encodeURIComponent(radio.value) : '';
    }
    function syncHead() {
        const name = nameInput ? nameInput.value.trim() : '';
        if (title) {
            title.textContent = name !== '' ? name : (title.dataset.empty || '');
            title.classList.toggle('is-missing', name === '');
        }
        if (headAva) {
            const img  = headAva.querySelector('img');
            const ini  = headAva.querySelector('span');
            const url  = avatarUrl();
            if (img) { img.hidden = url === ''; if (url !== '' && img.getAttribute('src') !== url) img.setAttribute('src', url); }
            if (ini) { ini.hidden = url !== ''; ini.textContent = initialOf(name); }
            headAva.classList.toggle('is-initial', url === '');
            if (color && /^#[0-9a-f]{6}$/i.test(color.value)) headAva.style.setProperty('--asx-c', color.value);
        }
        if (state && active) {
            state.textContent = active.checked ? (state.dataset.on || '') : (state.dataset.off || '');
        }
    }
    document.addEventListener('input', syncHead);
    document.addEventListener('change', syncHead);
    syncHead();

    // ---------- Menu des sections ----------
    const nav      = page.querySelector('.kbx-nav');
    const links    = nav ? Array.from(nav.querySelectorAll('a[href^="#"]')) : [];
    const sections = links.map((a) => byId(a.getAttribute('href').slice(1))).filter(Boolean);

    function spotlight(sec) {
        sec.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
        sec.classList.remove('is-spotlit');
        void sec.offsetWidth;
        sec.classList.add('is-spotlit');
        setTimeout(() => sec.classList.remove('is-spotlit'), 1700);
    }
    function setCurrent(id) {
        links.forEach((a) => {
            const on = a.getAttribute('href') === '#' + id;
            a.classList.toggle('is-on', on);
            if (on) a.setAttribute('aria-current', 'true');
            else a.removeAttribute('aria-current');
        });
    }
    links.forEach((a) => {
        a.addEventListener('click', (e) => {
            const sec = byId(a.getAttribute('href').slice(1));
            if (!sec) return;
            e.preventDefault();
            setCurrent(sec.id);
            spotlight(sec);
            try { history.replaceState(null, '', '#' + sec.id); } catch (_e) { /* adresse inchangée */ }
        });
    });
    if ('IntersectionObserver' in window && sections.length) {
        const seen = new Map();
        const io = new IntersectionObserver((entries) => {
            entries.forEach((en) => seen.set(en.target.id, en.isIntersecting ? en.intersectionRatio : 0));
            let best = '';
            let ratio = 0;
            sections.forEach((sec) => {
                const r = seen.get(sec.id) || 0;
                if (r > ratio) { ratio = r; best = sec.id; }
            });
            if (best) setCurrent(best);
        }, { rootMargin: '-90px 0px -45% 0px', threshold: [0, 0.15, 0.4, 0.75] });
        sections.forEach((sec) => io.observe(sec));
    }
    // Arrivée sur une section (« Modifier » de la page de l'assistant) : elle s'allume.
    const fromHash = sections.find((sec) => '#' + sec.id === location.hash);
    if (fromHash) {
        setCurrent(fromHash.id);
        setTimeout(() => spotlight(fromHash), 60);
    }

    // ---------- Documents choisis à la création ----------
    // Ils partent avec « Créer l'assistant » ; leur liste se relit et se
    // corrige avant (un fichier retiré l'est du champ). La zone de dépôt
    // s'allume quand on y glisse des fichiers (le champ la recouvre : c'est
    // le navigateur qui dépose).
    const createIn = byId('mpDocsCreateInput');
    const pending  = byId('mpPendingFiles');
    function sizeOf(bytes) {
        const units = String((pending && pending.dataset.units) || 'Ko|Mo').split('|');
        const fr = (document.documentElement.lang || 'fr').indexOf('fr') === 0;
        if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' ' + units[0];
        const mo = (bytes / (1024 * 1024)).toFixed(1);
        return (fr ? mo.replace('.', ',') : mo) + ' ' + (units[1] || units[0]);
    }
    function renderPending() {
        if (!createIn || !pending) return;
        const files = Array.from(createIn.files || []);
        pending.innerHTML = '';
        pending.hidden = files.length === 0;
        files.forEach((f, i) => {
            const li = document.createElement('li');
            li.className = 'kb-pending-file';
            const name = document.createElement('span');
            name.className = 'kb-pending-name';
            name.textContent = f.name;
            const size = document.createElement('span');
            size.className = 'kb-pending-size';
            size.textContent = sizeOf(f.size);
            li.append(name, size);
            if (typeof DataTransfer === 'function') {
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'kb-doc-del';
                del.title = String(pending.dataset.remove || '').replace('{nom}', f.name);
                del.setAttribute('aria-label', del.title);
                del.textContent = '×';
                del.addEventListener('click', () => {
                    const dt = new DataTransfer();
                    Array.from(createIn.files || []).forEach((x, k) => { if (k !== i) dt.items.add(x); });
                    createIn.files = dt.files;
                    createIn.dispatchEvent(new Event('change', { bubbles: true }));
                });
                li.appendChild(del);
            }
            pending.appendChild(li);
        });
    }
    if (createIn) createIn.addEventListener('change', renderPending);
    const drop = byId('mpDocDrop');
    if (drop) {
        let depth = 0;
        drop.addEventListener('dragenter', () => { depth++; drop.classList.add('is-drag'); });
        drop.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (!depth) drop.classList.remove('is-drag'); });
        drop.addEventListener('drop', () => { depth = 0; drop.classList.remove('is-drag'); });
    }

    // ---------- « … » ----------
    const moreBtn  = byId('pfMoreBtn');
    const moreMenu = byId('pfMoreMenu');
    const moreItems = () => (moreMenu ? Array.from(moreMenu.querySelectorAll('[role="menuitem"]')) : []);
    function setMore(on, focusFirst) {
        if (!moreBtn || !moreMenu) return;
        moreMenu.hidden = !on;
        moreBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (on && focusFirst && moreItems()[0]) moreItems()[0].focus();
    }
    if (moreBtn && moreMenu) {
        moreBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            // detail === 0 : ouvert au clavier, le focus entre dans le menu.
            setMore(moreMenu.hidden, e.detail === 0);
        });
        document.addEventListener('click', (e) => {
            if (!moreMenu.hidden && !e.target.closest('.kbf-more')) setMore(false);
        });
        moreMenu.addEventListener('keydown', (e) => {
            const items = moreItems();
            const at = items.indexOf(document.activeElement);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const step = e.key === 'ArrowDown' ? 1 : -1;
                if (items.length) items[(at + step + items.length) % items.length].focus();
            } else if (e.key === 'Escape') {
                setMore(false);
                moreBtn.focus();
            } else if (e.key === 'Tab') {
                setMore(false);
            }
        });
        moreMenu.addEventListener('click', () => setMore(false));
    }
})();
