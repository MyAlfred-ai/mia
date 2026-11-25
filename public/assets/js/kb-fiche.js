/* ==========================================================================
   Fiche d'une base ou d'une table — la mise en page de la piste D, posée
   par-dessus kb-form.js (qui garde tous les réglages eux-mêmes) :

   - le titre de la barre du haut suit le champ « Nom » ;
   - le menu des sections allume la section en vue et y mène ;
   - la « Vue d'ensemble » (schéma de la base, MiaKbDiagram) se recompose à
     chaque réglage — nom, active, qui la voit, partage, assistants, fichiers
     choisis, source — et chacun de ses nœuds mène à la section qui le règle.

   Aucun libellé ici : intitulés et schéma viennent du serveur (window.KB_FICHE).
   ========================================================================== */
(function () {
    'use strict';

    const page = document.getElementById('kbFiche');
    const form = document.getElementById('editKbForm') || document.getElementById('kbTableNew');
    if (!page || !form) return;
    const conf = window.KB_FICHE || {};
    const byId = (id) => document.getElementById(id);
    const reducedMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    // ---------- Titre ----------
    const nameInput = byId('kbName') || byId('kbtName');
    const title     = byId('kbFicheTitle');
    if (nameInput && title) {
        nameInput.addEventListener('input', () => {
            const v = nameInput.value.trim();
            title.textContent = v !== '' ? v : (title.dataset.empty || '');
            title.classList.toggle('is-missing', v === '');
        });
    }

    // ---------- Menu des sections ----------
    const nav = page.querySelector('.kbx-nav');
    const links = nav ? Array.from(nav.querySelectorAll('a[href^="#"]')) : [];
    const sections = links.map((a) => byId(a.getAttribute('href').slice(1))).filter(Boolean);

    function spotlight(sec) {
        if (!sec) return;
        if (sec.tagName === 'DETAILS') sec.open = true;
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

    // ---------- Vue d'ensemble ----------
    const host = byId('kbFormDiagram');
    if (!host || !conf.spec || !conf.catalog || !window.MiaKbDiagram) return;
    const base = JSON.parse(JSON.stringify(conf.spec));

    const checked = (name) => Array.from(form.querySelectorAll('input[name="' + name + '"]:checked')).map((i) => Number(i.value) || i.value);

    function compose() {
        const spec = JSON.parse(JSON.stringify(base));
        if (nameInput) spec.name = nameInput.value.trim();
        const desc = byId('kbDescription') || byId('kbtDescription');
        if (desc) spec.description = desc.value.trim().slice(0, 240);
        const active = byId('kbIsActive');
        if (active) spec.active = active.checked;

        const vis = form.querySelector('input[name="visibility"]:checked');
        if (vis) {
            spec.visibility = vis.value;
            spec.access = spec.access || {};
            spec.access.private = vis.value === 'private';
            spec.access.public = vis.value === 'public';
            const common = vis.value === 'common';
            const manageG = checked('share_group_manage[]');
            const manageU = checked('share_user_manage[]');
            spec.access.groups = common ? checked('share_group_ids[]').map((id) => ({ id, manage: manageG.includes(id), default: false, feed: false })) : [];
            spec.access.users  = common ? checked('share_user_ids[]').map((id) => ({ id, manage: manageU.includes(id), default: false, feed: false })) : [];
        }
        if (form.querySelector('input[name="profile_ids[]"]')) spec.assistant_ids = checked('profile_ids[]');

        // Création : les fichiers choisis et la source cochée.
        const files = byId('kbDocsCreateInput');
        if (files && files.files) {
            spec.files = Array.from(files.files).map((f) => ({ name: f.name, image: /^image\//.test(f.type || ''), tone: 'idle', label: '' }));
        }
        const src = form.querySelector('input[name="sync_provider"]:checked');
        if (src && spec.creating) {
            spec.live = src.value === 'mybrain';
            spec.source = spec.source || {};
            spec.source.provider = src.value !== '' && src.value !== 'mybrain' ? src.value : '';
            spec.source.linked = spec.source.provider !== '';
        }
        const mia = form.querySelector('input[name="mia_write"]:checked');
        if (mia && spec.table) spec.table.mia_write = mia.value === '1';
        // Nouvelle table : les colonnes saisies, ou le fichier choisi.
        const how = form.querySelector('input[name="table_source"]:checked');
        if (how && spec.table) {
            const cols = byId('kbtColumns');
            const file = byId('kbtFile');
            spec.table.columns = how.value === 'manual' && cols
                ? cols.value.split(',').map((c) => c.trim()).filter(Boolean).slice(0, 40)
                : [];
            spec.table.cols = spec.table.columns.length;
            spec.table.origin = how.value === 'file' && file && file.files && file.files[0] ? file.files[0].name : '';
        }
        return spec;
    }

    let timer = null;
    function paint(animate) {
        window.MiaKbDiagram.mount(host, compose(), conf.catalog, { animate: !!animate, jump: conf.jump || null });
    }
    paint(true);
    const later = () => { clearTimeout(timer); timer = setTimeout(() => paint(false), 220); };
    form.addEventListener('input', later);
    form.addEventListener('change', later);
    // Les pastilles de partage et d'assistants se retirent par script.
    document.addEventListener('click', (e) => { if (e.target.closest('.kbf-chip, .kbf-chips button')) later(); });

    // Un nœud du schéma mène à la section qui le règle.
    const TARGETS = {
        docs: ['documents', 'lignes', 'contenu'], connectors: ['documents'], live: ['documents'], state: ['documents', 'lignes'],
        chunks: ['avance'], llm: ['avance'], embedding: ['avance'], columns: ['lignes', 'contenu'],
        assistant: ['identite'], active: ['identite'],
        assistants: ['assistants'], sharing: ['visibilite'],
    };
    host.addEventListener('click', (e) => {
        const el = e.target.closest('[data-target]');
        if (!el || !host.contains(el)) return;
        const ids = TARGETS[el.dataset.target] || [];
        const sec = ids.map(byId).find(Boolean);
        if (!sec) return;
        e.preventDefault();
        setCurrent(sec.id);
        spotlight(sec);
    });
})();
