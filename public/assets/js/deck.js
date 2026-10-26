/**
 * Volet Présentation — parcours en trois étapes : options → plan → diaporama.
 *
 * Fichier séparé de chat.js (déjà très long) mais chargé APRÈS lui : les
 * fonctions et constantes déclarées au niveau racine de chat.js (convId,
 * appendMessage, createConversation, showToast…) sont donc visibles ici. Tous
 * les accès restent défensifs — deck.js ne doit jamais casser le chat s'il
 * évolue.
 *
 * Le rendu du diaporama n'est PAS réimplémenté ici : le serveur renvoie le HTML
 * produit par DeckRenderer, seule implémentation du rendu (volet, partage,
 * export). Le front ne fabrique que les vignettes, à partir de la spec.
 */
(function () {
    'use strict';

    // Ce script est chargé avant la déclaration du volet dans la page : on
    // diffère donc l'initialisation jusqu'à ce que le DOM soit complet. Un
    // simple `return` ici laisserait window.Deck indéfini, et le dispatch
    // d'outil échouerait SANS AUCUN message — panne invisible.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    function init() {

    const viewer = document.getElementById('deckViewer');
    if (!viewer) {
        console.error('[Deck] volet #deckViewer absent de la page — outil présentation indisponible.');
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const DEFAULTS = window.DECK_DEFAULTS || { slide_count: 10, min_slides: 1, max_slides: 30 };
    const THEMES = window.DECK_THEMES || [];

    const LENGTHS = [
        { key: 'court', label: 'Court', desc: t('Quelques mots par point — pour appuyer un discours.') },
        { key: 'moyen', label: 'Moyen', desc: t('Une phrase courte par point. Le plus courant.') },
        { key: 'detaille', label: t('Détaillé'), desc: t('Une à deux phrases — pour un document qui se lit seul.') },
    ];

    const TONES = [
        { key: 'professionnel', label: 'Professionnel', desc: 'Factuel, sobre.' },
        { key: 'pedagogique', label: t('Pédagogique'), desc: t('Progressif, avec exemples.') },
        { key: 'inspirant', label: 'Inspirant', desc: t('Vision et bénéfices.') },
        { key: 'direct', label: 'Direct', desc: t('Phrases courtes, verbes d\'action.') },
    ];

    const SLIDE_PRESETS = [6, 8, 10, 12, 15];

    // Libellés des phases SSE : l'utilisateur voit ce que MIA consulte.
    const PHASE_LABELS = {
        outline: t('Construction du plan…'),
        searching_knowledge_base: t('Lecture des documents de l\u2019assistant…'),
        searching_knowledge_bases: t('Recherche dans les bases de connaissances…'),
        slides: t('Rédaction des slides…'),
        images: t('Génération des illustrations…'),
    };

    // Libellé du voile quand on rouvre le volet sur une construction déjà
    // lancée : la phase exacte n'est connue qu'au prochain évènement du flux.
    const RESTORE_LABEL = t('Construction de la présentation…');

    // ------------------------------------------------------------------ état

    const state = {
        toolId: null,
        imageToolId: 0,
        subject: '',
        step: 'options',
        sel: null,          // élément sélectionné dans l'éditeur {slideId, elId}
        options: {
            slide_count: DEFAULTS.slide_count,
            text_length: 'moyen',
            tone: 'professionnel',
            image_style: '',
            image_tool_id: 0,
        },
        theme: THEMES.length ? THEMES[0].key : 'sobre',
        withImages: false,
        files: [],
        outline: [],
        deck: null,
        msgId: null,
        index: 0,
        total: 0,
        busy: false,
        blobUrl: null,
    };

    // -------------------------------------------------------------- éléments

    const el = (id) => document.getElementById(id);
    const stepsBar = el('deckSteps');
    const panes = {
        options: el('deckPaneOptions'),
        outline: el('deckPaneOutline'),
        deck: el('deckPaneDeck'),
    };
    const subjectInput = el('deckSubject');
    const loading = el('deckLoading');
    const loadingText = el('deckLoadingText');
    const frame = el('deckFrame');
    const filmstrip = el('deckFilmstrip');
    const outlineList = el('deckOutlineList');
    const outlineCount = el('deckOutlineCount');
    const btnOutline = el('deckGenOutline');
    const btnBuild = el('deckBuild');

    // ------------------------------------------------------------- utilitaires

    function convIdValue() {
        if (typeof convId === 'function') { try { return convId(); } catch (e) { /* noop */ } }
        return el('convId')?.value || '';
    }

    function toast(msg, kind) {
        if (typeof showToast === 'function') showToast(msg, kind);
    }

    function themeByKey(key) {
        return THEMES.find((theme) => theme.key === key) || THEMES[0] || { bg: '#fff', text: '#111', muted: '#888', accent: '#2563eb' };
    }

    function esc(text) {
        const d = document.createElement('div');
        d.textContent = String(text ?? '');
        return d.innerHTML;
    }

    /** Lecture d'un flux SSE (même format que les autres outils : lignes `data: {...}`). */
    async function readSse(response, onEvent) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                let json;
                try { json = JSON.parse(line.slice(6)); } catch (e) { continue; }
                onEvent(json);
            }
        }
    }

    /** Erreur affichée dans le volet ET dans le fil, comme les autres outils. */
    function fail(message, detail) {
        setLoading(false);
        toast(message, 'error');
        if (detail) console.warn('[Deck]', detail);
    }

    function setLoading(on, text) {
        state.busy = !!on;
        if (loading) loading.hidden = !on;
        if (on && text && loadingText) loadingText.textContent = text;
        if (btnOutline) btnOutline.disabled = !!on;
        if (btnBuild) btnBuild.disabled = !!on;
        if (on) { startElapsed(); } else { stopElapsed(); setProgress(null); }
    }

    /**
     * Avancement chiffré du voile. `setProgress(null)` le masque : toutes les
     * étapes ne sont pas quantifiables (construction du plan).
     */
    function setProgress(done, total) {
        const bar = el('deckProgress');
        if (!bar) return;
        if (done === null || !total || total < 1) {
            bar.hidden = true;
            return;
        }
        const ratio = Math.max(0, Math.min(1, done / total));
        bar.hidden = false;
        const fill = el('deckProgressFill');
        if (fill) fill.style.width = Math.round(ratio * 100) + '%';
        const count = el('deckProgressCount');
        if (count) count.textContent = done + ' / ' + total;
    }

    /**
     * Temps écoulé, affiché seulement au-delà de 8 s : une génération peut
     * rester plusieurs dizaines de secondes sur la même phase (un lot de
     * slides, une image), et un écran figé passe pour une panne.
     */
    let elapsedTimer = null;
    function startElapsed() {
        if (elapsedTimer) return;
        const node = el('deckLoadingElapsed');
        const t0 = Date.now();
        if (node) { node.hidden = true; node.textContent = ''; }
        elapsedTimer = setInterval(() => {
            const s = Math.round((Date.now() - t0) / 1000);
            if (!node || s < 8) return;
            node.hidden = false;
            node.textContent = s < 60 ? s + ' s' : Math.floor(s / 60) + ' min ' + (s % 60) + ' s';
        }, 1000);
    }

    function stopElapsed() {
        clearInterval(elapsedTimer);
        elapsedTimer = null;
        const node = el('deckLoadingElapsed');
        if (node) { node.hidden = true; node.textContent = ''; }
    }

    // ------------------------------------------------------------ navigation

    function goStep(name, back) {
        state.step = name;
        Object.keys(panes).forEach((key) => {
            const pane = panes[key];
            if (!pane) return;
            pane.classList.toggle('is-active', key === name);
            pane.classList.remove('is-entering', 'is-entering-back');
            if (key === name) pane.classList.add(back ? 'is-entering-back' : 'is-entering');
        });

        stepsBar?.querySelectorAll('.deck-step').forEach((btn) => {
            const step = btn.dataset.step;
            btn.classList.toggle('is-current', step === name);
            btn.classList.toggle('is-done', step !== name && stepReady(step));
            // Une étape est accessible dès que son contenu EXISTE, quelle que
            // soit l'étape affichée : après une génération complète, revenir au
            // plan ne doit pas condamner le retour au diaporama.
            btn.disabled = step !== name && !stepReady(step);
        });
    }

    /** Une étape a-t-elle un contenu à montrer ? */
    function stepReady(step) {
        if (step === 'outline') return state.outline.length > 0;
        if (step === 'deck') return !!state.deck;

        return true;   // les options sont toujours consultables
    }

    stepsBar?.addEventListener('click', (e) => {
        const btn = e.target.closest('.deck-step');
        if (!btn || btn.disabled || state.busy) return;
        goStep(btn.dataset.step, true);
    });

    // -------------------------------------------------- ouverture / fermeture

    function open() {
        // Un seul volet à la fois (les autres sont pilotés par chat.js).
        if (typeof closeHtmlViewer === 'function') closeHtmlViewer();
        if (typeof closeMapViewer === 'function') closeMapViewer();
        if (typeof closeVideoViewer === 'function') closeVideoViewer();
        if (typeof closeSourcesViewer === 'function') closeSourcesViewer();
        viewer.classList.add('open');
        if (typeof setViewerLayoutState === 'function') setViewerLayoutState();
    }

    function close() {
        editMode = false;
        viewer.classList.remove('open');
        if (state.blobUrl) { URL.revokeObjectURL(state.blobUrl); state.blobUrl = null; }
        if (frame) frame.removeAttribute('src');
        if (typeof setViewerLayoutState === 'function') setViewerLayoutState();
    }

    el('deckViewerClose')?.addEventListener('click', close);

    el('deckViewerFullscreen')?.addEventListener('click', () => {
        const stage = viewer.querySelector('.deck-stage');
        if (!stage) return;
        if (document.fullscreenElement) document.exitFullscreen();
        else stage.requestFullscreen?.();
    });

    // ------------------------------------------------------- étape 1 : options

    function buildOptionsUi() {
        // Nombre de slides : préréglages + saisie libre.
        const chips = el('deckSlideChips');
        if (chips && !chips.dataset.built) {
            chips.dataset.built = '1';
            SLIDE_PRESETS.forEach((n) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'deck-chip';
                b.textContent = String(n);
                b.dataset.value = String(n);
                b.setAttribute('role', 'radio');
                b.addEventListener('click', () => setSlideCount(n));
                chips.appendChild(b);
            });
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'deck-chip-input';
            input.id = 'deckSlideCustom';
            input.min = String(DEFAULTS.min_slides);
            input.max = String(DEFAULTS.max_slides);
            input.setAttribute('aria-label', t('Nombre de slides personnalisé'));
            input.addEventListener('input', () => {
                const n = parseInt(input.value, 10);
                if (!isNaN(n)) setSlideCount(n, true);
            });
            chips.appendChild(input);
        }

        buildCards(el('deckLengthCards'), LENGTHS, 'text_length');
        buildCards(el('deckToneCards'), TONES, 'tone');
        buildThemes();
        buildImageOptions();
        syncOptionsUi();
    }

    function buildCards(container, items, optionKey) {
        if (!container || container.dataset.built) return;
        container.dataset.built = '1';
        items.forEach((item) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'deck-card';
            b.dataset.value = item.key;
            b.setAttribute('role', 'radio');
            b.innerHTML = '<span class="deck-card-title">' + esc(item.label) + '</span>'
                + '<span class="deck-card-desc">' + esc(item.desc) + '</span>';
            b.addEventListener('click', () => {
                state.options[optionKey] = item.key;
                syncOptionsUi();
            });
            container.appendChild(b);
        });
    }

    /** Vignettes de thème : mini-slides réelles, pour choisir à l'œil. */
    function buildThemes() {
        const container = el('deckThemeCards');
        if (!container || container.dataset.built) return;
        container.dataset.built = '1';
        THEMES.forEach((theme) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'deck-theme';
            b.dataset.value = theme.key;
            b.setAttribute('role', 'radio');
            b.innerHTML =
                '<span class="deck-theme-preview" style="background:' + esc(theme.bg) + '">'
                + '<span class="deck-theme-bar" style="background:' + esc(theme.accent) + '"></span>'
                + '<span class="deck-theme-title" style="background:' + esc(theme.text) + '"></span>'
                + '<span class="deck-theme-line" style="top:56%;width:46%;background:' + esc(theme.muted) + '"></span>'
                + '<span class="deck-theme-line" style="top:70%;width:34%;background:' + esc(theme.muted) + '"></span>'
                + '</span>'
                + '<span class="deck-theme-name">' + esc(theme.label) + '</span>';
            b.addEventListener('click', () => { state.theme = theme.key; syncOptionsUi(); });
            container.appendChild(b);
        });
    }

    function buildImageOptions() {
        const field = el('deckImagesField');
        const toggle = el('deckImagesToggle');
        const chips = el('deckImageStyleChips');
        if (!field || !toggle || !chips) return;

        // Ligne masquée si l'outil n'a aucun outil image associé : pas d'option morte.
        field.hidden = !state.imageToolId;
        if (!state.imageToolId) { state.withImages = false; return; }

        if (!chips.dataset.built) {
            chips.dataset.built = '1';
            (window.IMAGE_STYLES || []).forEach((style) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'deck-chip';
                b.dataset.value = style.key;
                b.textContent = style.label;
                b.setAttribute('role', 'radio');
                b.addEventListener('click', () => { state.options.image_style = style.key; syncOptionsUi(); });
                chips.appendChild(b);
            });
            toggle.addEventListener('change', () => {
                state.withImages = toggle.checked;
                if (state.withImages && !state.options.image_style) {
                    const first = (window.IMAGE_STYLES || [])[0];
                    state.options.image_style = first ? first.key : '';
                }
                syncOptionsUi();
            });
        }
    }

    function setSlideCount(n, fromInput) {
        const min = DEFAULTS.min_slides, max = DEFAULTS.max_slides;
        state.options.slide_count = Math.max(min, Math.min(max, n));
        if (!fromInput) {
            const input = el('deckSlideCustom');
            if (input) input.value = '';
        }
        syncOptionsUi();
    }

    function syncOptionsUi() {
        const count = state.options.slide_count;
        el('deckSlideChips')?.querySelectorAll('.deck-chip').forEach((b) => {
            b.classList.toggle('is-selected', parseInt(b.dataset.value, 10) === count);
        });
        const custom = el('deckSlideCustom');
        if (custom && !SLIDE_PRESETS.includes(count) && document.activeElement !== custom) {
            custom.value = String(count);
        }

        markSelected(el('deckLengthCards'), state.options.text_length);
        markSelected(el('deckToneCards'), state.options.tone);
        markSelected(el('deckThemeCards'), state.theme);

        const toggle = el('deckImagesToggle');
        const chips = el('deckImageStyleChips');
        if (toggle) toggle.checked = state.withImages;
        if (chips) {
            chips.hidden = !state.withImages;
            markSelected(chips, state.options.image_style);
        }
    }

    function markSelected(container, value) {
        container?.querySelectorAll('[data-value]').forEach((b) => {
            b.classList.toggle('is-selected', b.dataset.value === value);
        });
    }

    // ----------------------------------------------------------- étape 2 : plan

    function renderOutline() {
        if (!outlineList) return;
        outlineList.innerHTML = '';
        state.outline.forEach((item, i) => outlineList.appendChild(slideCard(item, i)));
        updateOutlineCount();
    }

    /** Déplacement d'une slide d'un cran (équivalent tactile du glisser-déposer). */
    function moveSlide(index, delta) {
        const to = index + delta;
        if (to < 0 || to >= state.outline.length) return;
        const [item] = state.outline.splice(index, 1);
        state.outline.splice(to, 0, item);
        renderOutline();
        // La carte suit le déplacement : sans ça, un appui répété sur « monter »
        // viserait la slide qui vient de prendre la place.
        outlineList.children[to]?.scrollIntoView({ block: 'nearest' });
    }

    function slideCard(item, index) {
        const li = document.createElement('li');
        li.className = 'deck-slide-card';
        li.draggable = true;
        li.dataset.index = String(index);

        const head = document.createElement('div');
        head.className = 'deck-slide-head';
        head.innerHTML =
            '<span class="deck-slide-btn deck-slide-drag" title="Glisser pour réordonner">'
            + '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/></svg></span>'
            // Le glisser-déposer HTML5 est inopérant au doigt : sur écran
            // tactile, ces deux boutons sont le SEUL moyen de réordonner.
            + '<span class="deck-slide-move">'
            + '<button type="button" class="deck-slide-btn" data-act="up" title="Monter cette slide" aria-label="Monter">'
            + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 15 12 9 18 15"/></svg></button>'
            + '<button type="button" class="deck-slide-btn" data-act="down" title="Descendre cette slide" aria-label="Descendre">'
            + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg></button>'
            + '</span>'
            + '<span class="deck-slide-num">' + (index + 1) + '</span>'
            + '<span class="deck-slide-title" contenteditable="true" role="textbox">' + esc(item.title) + '</span>'
            + (item.image_prompt ? '<span class="deck-slide-badge" title="Cette slide sera illustrée">image</span>' : '')
            + '<button type="button" class="deck-slide-btn is-danger" data-act="del" title="Supprimer cette slide">'
            + '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>';
        li.appendChild(head);

        const titleEl = head.querySelector('.deck-slide-title');
        titleEl.addEventListener('input', () => { item.title = titleEl.textContent.trim(); });
        // Entrée valide le titre au lieu d'insérer un saut de ligne.
        titleEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); } });

        head.querySelector('[data-act="del"]').addEventListener('click', () => {
            state.outline.splice(index, 1);
            renderOutline();
        });

        head.querySelector('[data-act="up"]').addEventListener('click', () => moveSlide(index, -1));
        head.querySelector('[data-act="down"]').addEventListener('click', () => moveSlide(index, 1));

        const ul = document.createElement('ul');
        ul.className = 'deck-slide-points';
        (item.points || []).forEach((point, pi) => {
            const pl = document.createElement('li');
            pl.className = 'deck-slide-point';
            pl.contentEditable = 'true';
            pl.setAttribute('role', 'textbox');
            pl.textContent = point;
            pl.addEventListener('input', () => { item.points[pi] = pl.textContent.trim(); });
            pl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); pl.blur(); }
                // Retour arrière sur un point vidé : on le retire de la liste.
                if (e.key === 'Backspace' && pl.textContent === '' && item.points.length > 1) {
                    e.preventDefault();
                    item.points.splice(pi, 1);
                    renderOutline();
                }
            });
            ul.appendChild(pl);
        });
        li.appendChild(ul);

        bindDrag(li);
        return li;
    }

    /** Réordonnancement par glisser-déposer, sur la liste du plan. */
    function bindDrag(li) {
        li.addEventListener('dragstart', (e) => {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', li.dataset.index);
            li.classList.add('is-dragging');
        });
        li.addEventListener('dragend', () => {
            li.classList.remove('is-dragging');
            outlineList?.querySelectorAll('.deck-slide-card').forEach((c) => c.classList.remove('is-drop-target'));
        });
        li.addEventListener('dragover', (e) => { e.preventDefault(); li.classList.add('is-drop-target'); });
        li.addEventListener('dragleave', () => li.classList.remove('is-drop-target'));
        li.addEventListener('drop', (e) => {
            e.preventDefault();
            const from = parseInt(e.dataTransfer.getData('text/plain'), 10);
            const to = parseInt(li.dataset.index, 10);
            if (isNaN(from) || isNaN(to) || from === to) return;
            const [moved] = state.outline.splice(from, 1);
            state.outline.splice(to, 0, moved);
            renderOutline();
        });
    }

    function updateOutlineCount() {
        if (!outlineCount) return;
        const n = state.outline.length;
        const wanted = state.options.slide_count;
        outlineCount.textContent = n + ' slide' + (n > 1 ? 's' : '') + (n !== wanted ? t(' (demandé : ') + wanted + ')' : '');
        outlineCount.classList.toggle('is-off', n !== wanted);
    }

    el('deckAddSlide')?.addEventListener('click', () => {
        state.outline.push({ id: 's' + (state.outline.length + 1), title: '', points: [''], layout: 'titre-contenu', image_prompt: '' });
        renderOutline();
        outlineList?.lastElementChild?.querySelector('.deck-slide-title')?.focus();
    });

    el('deckRegenOutline')?.addEventListener('click', () => { if (!state.busy) requestOutline(); });

    // --------------------------------------------------------- appels serveur

    async function requestOutline() {
        const subject = (subjectInput?.value || state.subject || '').trim();
        if (!subject) { toast(t('Indiquez le sujet de la présentation.'), 'error'); subjectInput?.focus(); return; }
        state.subject = subject;

        if (!convIdValue() && typeof createConversation === 'function') {
            if (!await createConversation()) return;
        }

        setLoading(true, t('Construction du plan…'));
        state.options.image_tool_id = state.withImages ? state.imageToolId : 0;
        if (!state.withImages) state.options.image_style = '';

        const fd = new FormData();
        fd.append('message', subject);
        fd.append('tool_id', String(state.toolId));
        Object.keys(state.options).forEach((k) => fd.append('options[' + k + ']', String(state.options[k])));
        // Documents joints au message d'origine : ils nourrissent le plan
        // ET la rédaction (le serveur en conserve le texte entre les deux).
        state.files.forEach((f) => fd.append('file_tokens[]', f.token));

        try {
            const res = await fetch('/chat/' + convIdValue() + '/presentation/outline', {
                method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd,
            });
            if (!res.ok || !res.body) {
                const payload = await res.json().catch(() => ({}));
                fail(payload.error || t('Génération du plan impossible.'), payload);
                return;
            }

            let errored = false;
            await readSse(res, (json) => {
                if (json.llm_error) { errored = true; fail(json.llm_error.message || t('Le modèle n\'a pas répondu.'), json.llm_error); }
                if (json.phase && PHASE_LABELS[json.phase]) setLoading(true, PHASE_LABELS[json.phase]);
                if (json.outline) {
                    state.outline = (json.outline.slides || []).map((s) => ({
                        id: s.id, title: s.title || '', points: Array.isArray(s.points) ? s.points : [],
                        layout: s.layout || 'titre-contenu', image_prompt: s.image_prompt || '',
                    }));
                    state.subject = json.outline.title || subject;
                }
                if (json.quota_status && typeof applyQuotaStatus === 'function') applyQuotaStatus(json.quota_status);
            });

            setLoading(false);
            if (!errored && state.outline.length) {
                renderOutline();
                goStep('outline');
            }
        } catch (e) {
            fail(t('Génération du plan impossible.'), e);
        }
    }

    async function requestBuild() {
        if (!state.outline.length) return;

        setLoading(true, PHASE_LABELS.slides);
        // Le total est connu du client (le plan est sous ses yeux) : la barre
        // part donc à 0 / N dès le clic, sans attendre le premier lot rédigé.
        setProgress(0, state.outline.length);
        prefillFilmstrip();

        // Le serveur persiste le message utilisateur à cette étape seulement :
        // on l'affiche donc ici, pour que le fil reflète la demande réelle.
        if (typeof appendMessage === 'function') appendMessage('user', state.subject, state.files);

        const cid = convIdValue();
        const fd = new FormData();
        fd.append('tool_id', String(state.toolId));
        fd.append('message', state.subject);
        fd.append('title', state.subject);
        fd.append('theme', state.theme);
        fd.append('outline', JSON.stringify(state.outline));
        Object.keys(state.options).forEach((k) => fd.append('options[' + k + ']', String(state.options[k])));

        // Bulle d'attente dans le fil : c'est elle qui deviendra la carte de la
        // présentation, et c'est elle qu'on retrouve si on revient plus tard.
        const bubble = (typeof appendMessage === 'function')
            ? appendMessage('assistant', '', [], true)
            : null;
        if (bubble && typeof setJobStatus === 'function') {
            setJobStatus(bubble, t('📊 Construction de la présentation…'));
        }

        try {
            const res = await fetch('/chat/' + cid + '/presentation/build', {
                method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd,
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok || !payload.job_id) {
                bubble?.closest('.message')?.remove();
                fail(payload.error || t('Création de la présentation impossible.'), payload);
                return;
            }
            // La construction continue côté serveur : fermer le volet, changer
            // de conversation ou recharger ne l'interrompt plus.
            window.attachJob(payload.job_id, {
                type: 'presentation',
                convId: cid,
                el: bubble,
                data: { total: state.outline.length, live: true },
            });
        } catch (e) {
            bubble?.closest('.message')?.remove();
            fail(t('Création de la présentation impossible.'), e);
        }
    }

    /**
     * Le volet appartient-il à CE job ? `live` marque une construction suivie
     * dans le volet ; la comparaison de conversation évite qu'un job lancé
     * ailleurs continue d'écrire dans le volet d'une autre conversation (les
     * deux jobs restent `live` en parallèle, un seul est à l'écran).
     */
    function ownsPanel(job) {
        return !!job.data.live && String(job.convId) === String(convIdValue());
    }

    /**
     * Suivi d'une construction de deck (file de jobs). Le volet est piloté
     * quand il appartient au job (cf. `ownsPanel`) ; la carte du fil, elle,
     * est mise à jour dans tous les cas.
     */
    window.JOB_TYPES.presentation = {
        statusLabel: () => t('📊 Construction de la présentation…'),

        /**
         * Retour sur la conversation (« Voir » du tiroir, changement de
         * conversation) ou rechargement de page pendant la construction :
         * `switchConversation` vient de fermer le volet, il faut le ROUVRIR
         * sur l'avancement. Sans ça, « Voir » ne montrait qu'une bulle
         * « Construction de la présentation… » et la génération paraissait
         * perdue — le volet ne revenait jamais, même une fois le deck prêt.
         */
        onRestore(job) {
            job.data.live = true;
            open();
            // Après un rechargement, le plan n'est plus en mémoire : la bande
            // de vignettes reste vide et seul le voile informe, jusqu'au
            // premier `slide_progress` qui redonne l'avancement chiffré.
            setLoading(true, RESTORE_LABEL);
            if (job.data.total) setProgress(job.data.done || 0, job.data.total);
        },

        onEvent(job, json) {
            // Avancement mémorisé même volet fermé : au retour, la barre
            // repart de l'état réel au lieu de zéro.
            if (json.slide_progress || json.image_progress) {
                const p = json.slide_progress || json.image_progress;
                job.data.done  = p.done;
                job.data.total = p.total;
            }
            if (!ownsPanel(job)) return;
            // La barre n'est PAS réinitialisée sur un changement de phase : le
            // serveur envoie le compteur de la nouvelle phase dans la foulée,
            // et la masquer entre les deux produirait un clignotement.
            if (json.phase && PHASE_LABELS[json.phase]) setLoading(true, PHASE_LABELS[json.phase]);
            if (json.slide_progress) {
                const p = json.slide_progress;
                markThumbsReady(p.done);
                // `from`/`to` = lot en cours de rédaction : le libellé dit ce
                // que MIA écrit à cet instant, la barre ce qui est acquis.
                setLoading(true, p.from
                    ? (p.from === p.to
                        ? t('Rédaction de la slide ') + p.from + '…'
                        : t('Rédaction des slides ') + p.from + ' à ' + p.to + '…')
                    : PHASE_LABELS.slides);
                setProgress(p.done, p.total);
            }
            if (json.image_progress) {
                const p = json.image_progress;
                setLoading(true, p.done < p.total
                    ? 'Illustration ' + (p.done + 1) + ' sur ' + p.total + '…'
                    : PHASE_LABELS.images);
                setProgress(p.done, p.total);
            }
            // Illustrations en échec : la présentation reste produite, mais des
            // cadres vides sans explication seraient incompréhensibles.
            if (json.image_error) {
                job.data.imageNotice = json.image_error.failed
                    ? json.image_error.failed + t(' illustration(s) non générée(s) : ') + json.image_error.message
                    : json.image_error.message;
            }
        },

        onFail(job) {
            if (ownsPanel(job)) setLoading(false);
        },

        onDone(job, json) {
            const visible = String(job.convId) === String(convIdValue());
            if (typeof jobNeedsNotification === 'function' && jobNeedsNotification(job.convId)
                && typeof notifyJobDone === 'function') {
                notifyJobDone(t('Votre présentation est prête.'), job.convId);
            }

            if (ownsPanel(job)) {
                setLoading(false);
                if (job.data.imageNotice) toast(job.data.imageNotice, 'error');
                if (json.deck) state.deck = json.deck;
                if (json.msg_id) state.msgId = json.msg_id;
                if (json.html) showDeck(json.html);
                if (state.deck) {
                    renderFilmstrip();
                    goStep('deck');
                }
            }

            if (!visible) {
                if (typeof showToast === 'function') {
                    showToast(t('Présentation prête dans une autre conversation'), 'success');
                }
                return;
            }

            // La bulle d'attente devient la carte de la présentation.
            if (job.el && json.msg_id && json.deck && typeof appendMessage === 'function') {
                job.el.closest('.message')?.remove();
                appendMessage(
                    'assistant',
                    JSON.stringify(json.deck),
                    [{ type: 'slide_deck', name: 'presentation.json' }],
                    false,
                    { messageId: json.msg_id }
                );
            }
        },
    };

    btnOutline?.addEventListener('click', () => { if (!state.busy) requestOutline(); });
    btnBuild?.addEventListener('click', () => { if (!state.busy) requestBuild(); });

    // ------------------------------------------------------ étape 3 : diaporama

    /**
     * L'aperçu est chargé via une URL blob:, dont l'origine est opaque : un
     * chemin absolu /assets/img/generated/… n'y résout PAS vers l'origine de
     * l'app, et l'image reste vide. Même traitement que l'aperçu des pages web
     * (absolutizeGeneratedAssets, chat.js) — repris ici avec un repli, deck.js
     * ne devant pas dépendre durement de chat.js.
     */
    function absolutizeAssets(html) {
        if (typeof absolutizeGeneratedAssets === 'function') return absolutizeGeneratedAssets(html);
        return String(html || '').replace(
            /(\s(?:src|srcset)\s*=\s*["'])\/assets\/img\/generated\//gi,
            '$1' + location.origin + '/assets/img/generated/'
        );
    }

    function showDeck(html, editable) {
        if (!frame || !html) return;
        if (state.blobUrl) URL.revokeObjectURL(state.blobUrl);
        // state.html conserve le rendu PROPRE : l'éditeur n'est injecté que
        // dans la copie affichée, jamais dans ce qui est téléchargé ou partagé.
        // L'absolutisation suit la même règle (chemins relatifs conservés).
        state.html = html;
        const doc = absolutizeAssets(editable ? buildEditableDoc(html) : html);
        state.blobUrl = URL.createObjectURL(new Blob([doc], { type: 'text/html' }));
        frame.src = state.blobUrl;
        syncEditButton();
    }

    /** Le bouton n'a de sens qu'une fois le deck enregistré (il faut un msg_id). */
    function syncEditButton() {
        const btn = el('deckViewerEdit');
        if (btn) btn.hidden = !(state.deck && state.msgId);
    }

    /** Vignettes provisoires dès le plan : la progression devient visible. */
    function prefillFilmstrip() {
        if (!filmstrip) return;
        filmstrip.innerHTML = '';
        state.total = state.outline.length;
        state.outline.forEach((item, i) => {
            filmstrip.appendChild(thumb(item.title, i, true));
        });
    }

    function markThumbsReady(done) {
        filmstrip?.querySelectorAll('.deck-thumb').forEach((t, i) => {
            if (i < done) t.classList.remove('is-pending');
        });
        const next = filmstrip?.querySelector('.deck-thumb.is-pending');
        next?.scrollIntoView({ block: 'nearest', inline: 'center' });
    }

    function renderFilmstrip() {
        if (!filmstrip || !state.deck) return;
        filmstrip.innerHTML = '';
        state.total = state.deck.slides.length;
        state.deck.slides.forEach((slide, i) => {
            const titleEl = (slide.elements || []).find((e) => e.type === 'text' && e.role === 'titre');
            filmstrip.appendChild(thumb(titleEl ? titleEl.text : '', i, false));
        });
        setIndex(0);
    }

    function thumb(title, index, pending) {
        const theme = themeByKey(state.theme);
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'deck-thumb' + (pending ? ' is-pending' : '');
        b.dataset.index = String(index);
        b.title = title || ('Slide ' + (index + 1));
        b.style.background = theme.bg;
        b.innerHTML =
            '<span class="deck-thumb-bar" style="background:' + esc(theme.accent) + '"></span>'
            + '<span class="deck-thumb-title" style="color:' + esc(theme.text) + '">' + esc(title || '') + '</span>'
            + '<span class="deck-thumb-num" style="color:' + esc(theme.muted) + '">' + (index + 1) + '</span>';
        b.addEventListener('click', () => setIndex(index, true));
        return b;
    }

    function setIndex(i, push) {
        state.index = Math.max(0, Math.min(state.total - 1, i));
        filmstrip?.querySelectorAll('.deck-thumb').forEach((t, k) => {
            t.classList.toggle('is-active', k === state.index);
            if (k === state.index) t.scrollIntoView({ block: 'nearest', inline: 'center' });
        });
        const pos = el('deckPosition');
        if (pos) pos.textContent = state.total ? (state.index + 1) + ' / ' + state.total : '—';
        const prev = el('deckPrev'), next = el('deckNext');
        if (prev) prev.disabled = state.index <= 0;
        if (next) next.disabled = state.index >= state.total - 1;
        // `push` : navigation venue du volet → on pilote l'iframe. Sinon c'est
        // l'iframe qui nous a informés, et lui renvoyer l'ordre bouclerait.
        if (push && frame?.contentWindow) {
            frame.contentWindow.postMessage({ type: 'mia-deck-goto', index: state.index }, '*');
        }
    }

    el('deckPrev')?.addEventListener('click', () => setIndex(state.index - 1, true));
    el('deckNext')?.addEventListener('click', () => setIndex(state.index + 1, true));

    // L'iframe signale la slide courante (clavier, clic dans l'aperçu).
    window.addEventListener('message', (e) => {
        if (!frame || e.source !== frame.contentWindow) return;
        const d = e.data;
        if (!d || typeof d !== 'object') return;
        if (d.type === 'mia-deck-index' && typeof d.index === 'number') {
            state.total = d.total || state.total;
            setIndex(d.index, false);
            return;
        }
        if (d.type === 'mia-deck-editor-ready') {
            // Le document éditable vient d'être chargé : on arme le mode et on
            // revient sur la slide qui était affichée avant le rechargement.
            reloading = false;
            frame.contentWindow.postMessage({ type: 'mia-deck-edit-mode', on: true }, '*');
            frame.contentWindow.postMessage({ type: 'mia-deck-goto', index: state.index }, '*');
            // Une mise en forme recharge l'aperçu : sans cette reprise, la
            // sélection serait perdue à chaque clic de la barre.
            if (state.sel) {
                frame.contentWindow.postMessage({
                    type: 'mia-deck-select-el', slideId: state.sel.slideId, elId: state.sel.elId,
                }, '*');
            }
            syncFormatBar();
            return;
        }
        onEditorMessage(d);
    });

    // ---------------------------------------------------------------- exports

    function exportBaseName() {
        const base = (state.deck?.title || state.subject || 'presentation')
            .replace(/[^\p{L}\p{N}]+/gu, '-').slice(0, 60).toLowerCase().replace(/^-+|-+$/g, '');
        return base || 'presentation';
    }

    function saveBlob(blob, name) {
        if (window.NativeBridge?.saveAndShare) { window.NativeBridge.saveAndShare(blob, name); return; }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    }

    async function exportHtml() {
        if (!state.html) return;
        // Fichier autonome : le serveur ré-embarque les images en data-URI,
        // sinon les chemins /assets/… seraient morts une fois le fichier
        // ouvert depuis le disque. Deck non encore enregistré → repli sur le
        // rendu en mémoire (images absolues, chargées depuis l'app).
        let html = absolutizeAssets(state.html);
        if (state.msgId) {
            try {
                const res = await fetch('/chat/message/' + state.msgId + '/deck?inline=1', {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (res.ok && typeof data.html === 'string') html = data.html;
            } catch (e) { /* repli silencieux : le téléchargement reste possible */ }
        }
        saveBlob(new Blob([html], { type: 'text/html' }), exportBaseName() + '.html');
    }

    /**
     * PPTX et PDF sont fabriqués par le serveur À PARTIR DE LA SPEC ENREGISTRÉE
     * (jamais du HTML de l'aperçu) : les positions exportées sont exactement
     * celles qui sont persistées, éditions comprises.
     */
    async function exportBinary(format) {
        if (!state.msgId) {
            toast(t('La présentation doit être enregistrée avant l\'export.'), 'error');
            return;
        }
        try {
            const res = await fetch('/chat/message/' + state.msgId + '/deck/' + format, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
            });
            if (!res.ok) {
                const payload = await res.json().catch(() => ({}));
                fail(payload.error || t('Export impossible.'), payload);
                return;
            }
            saveBlob(await res.blob(), exportBaseName() + '.' + format);
        } catch (e) {
            fail(t('Export impossible.'), e);
        }
    }

    const exportBtn  = el('deckExport');
    const exportMenu = el('deckExportMenu');

    function toggleExportMenu(open) {
        if (!exportMenu || !exportBtn) return;
        const show = open === undefined ? exportMenu.hidden : open;
        exportMenu.hidden = !show;
        exportBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
    }

    exportBtn?.addEventListener('click', (e) => { e.stopPropagation(); toggleExportMenu(); });
    document.addEventListener('click', (e) => {
        if (exportMenu && !exportMenu.hidden && !el('deckExportWrap')?.contains(e.target)) toggleExportMenu(false);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && exportMenu && !exportMenu.hidden) toggleExportMenu(false);
    });

    exportMenu?.querySelectorAll('button[data-format]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const format = btn.dataset.format;
            toggleExportMenu(false);
            // Un export PPTX ou PDF prend quelques secondes : sans retour, le
            // clic paraît sans effet.
            const label = btn.textContent;
            btn.disabled = true;
            btn.textContent = t('Préparation…');
            try {
                if (format === 'html') await exportHtml();
                else await exportBinary(format);
            } finally {
                btn.disabled = false;
                btn.textContent = label;
            }
        });
    });

    // ------------------------------------------------- ouverture depuis le fil

    /**
     * Ouvre une présentation enregistrée. `opts.edit` : directement dans
     * l'éditeur (« Modifier les slides » d'une carte du chat) — sauf au doigt,
     * où l'édition n'est pas proposée (bouton masqué).
     */
    async function openFromMessage(msgId, opts) {
        open();
        setLoading(true, t('Chargement de la présentation…'));
        try {
            const res = await fetch('/chat/message/' + msgId + '/deck', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (!res.ok || !data.deck) { fail(data.error || t('Présentation introuvable.')); return; }
            state.deck = data.deck;
            state.msgId = msgId;
            state.subject = data.deck.title || '';
            state.theme = data.deck.theme?.preset || state.theme;
            state.outline = (data.deck.outline || []).map((s) => ({
                id: s.id, title: s.title || '', points: s.points || [],
                layout: s.layout || 'titre-contenu', image_prompt: s.image_prompt || '',
            }));
            if (data.deck.options) Object.assign(state.options, data.deck.options);
            state.withImages = !!(data.deck.options && data.deck.options.image_style);
            if (subjectInput) subjectInput.value = state.subject;
            syncOptionsUi();
            showDeck(data.html || '');
            renderFilmstrip();
            setLoading(false);
            goStep('deck');
            const editBtn = el('deckViewerEdit');
            if (opts && opts.edit && editBtn && getComputedStyle(editBtn).display !== 'none' && !editMode) {
                await setEditMode(true);
            }
        } catch (e) {
            fail(t('Présentation introuvable.'), e);
        }
    }

    // Délégation : la carte peut venir du rendu serveur comme du live.
    document.getElementById('chatMessages')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-open-deck');
        if (!btn) return;
        e.preventDefault();
        openFromMessage(btn.dataset.msgId);
    });

    // ============================================================== ÉDITEUR
    //
    // L'aperçu est une iframe d'origine opaque (blob + sandbox) : le volet ne
    // peut pas toucher son DOM. L'éditeur est donc SÉRIALISÉ et injecté dans
    // le document, et dialogue par postMessage — même approche que l'éditeur
    // de pages HTML (miaPreviewEditor).
    //
    // Il manipule le DOM pour le geste (fluide, sans aller-retour) et renvoie
    // au volet la géométrie en POURCENTAGE, seule unité de la spec. Le volet
    // reste maître de la donnée et de sa sauvegarde.

    /* eslint-disable */
    function miaDeckEditor() {
        'use strict';

        var MIN_SIZE = 0.5;   // taille minimale d'un élément, en % du canvas
        var SNAP = 0.7;       // distance d'accroche des guides, en % du canvas
        var STEP = 0.5;       // pas des flèches du clavier, en %
        var STEP_BIG = 5;

        var selected = null, dragMode = null, origin = null, editingText = false;
        var frame = null, guideV = null, guideH = null;

        function post(msg) { try { parent.postMessage(msg, '*'); } catch (e) {} }
        function slideOf(el) { return el ? el.closest('.slide') : null; }
        function activeSlide() {
            return document.querySelector('.slide.is-active') || document.querySelector('.slide');
        }
        function editing() { return document.documentElement.classList.contains('mia-deck-editing'); }

        /** Géométrie d'un élément en % du canvas, lue depuis son style inline. */
        function geom(el) {
            return {
                x: parseFloat(el.style.left) || 0,
                y: parseFloat(el.style.top) || 0,
                w: parseFloat(el.style.width) || 0,
                h: parseFloat(el.style.height) || 0,
            };
        }
        function applyGeom(el, g) {
            el.style.left = round(g.x) + '%';
            el.style.top = round(g.y) + '%';
            el.style.width = round(g.w) + '%';
            el.style.height = round(g.h) + '%';
        }
        function round(v) { return Math.round(v * 100) / 100; }
        function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

        // ------------------------------------------------------- cadre

        function ensureFrame() {
            if (frame) return frame;
            frame = document.createElement('div');
            frame.className = 'mia-sel';
            var handles = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
            for (var i = 0; i < handles.length; i++) {
                var h = document.createElement('span');
                h.className = 'mia-h mia-h-' + handles[i];
                h.dataset.dir = handles[i];
                frame.appendChild(h);
            }
            return frame;
        }

        function placeFrame() {
            if (!selected || !frame) return;
            var g = geom(selected);
            frame.style.left = g.x + '%';
            frame.style.top = g.y + '%';
            frame.style.width = g.w + '%';
            frame.style.height = g.h + '%';
        }

        function select(el) {
            if (selected === el) return;
            deselect();
            selected = el;
            el.classList.add('mia-selected');
            slideOf(el).appendChild(ensureFrame());
            placeFrame();
            post({
                type: 'mia-deck-select',
                slideId: slideOf(el).dataset.slideId,
                elId: el.dataset.elId,
                // Un tableau se déplace et se redimensionne comme une forme :
                // sa mise en forme de texte (barre du volet) ne s'y applique pas.
                kind: el.classList.contains('el-image') ? 'image'
                    : (el.classList.contains('el-shape') || el.classList.contains('el-table') ? 'shape' : 'text'),
            });
        }

        function deselect() {
            var had = selected !== null;
            stopTextEdit();
            if (selected) selected.classList.remove('mia-selected');
            if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
            selected = null;
            hideGuides();
            // Ne rien annoncer quand il n'y avait rien de sélectionné : le volet
            // reçoit sinon un message à chaque clic dans le vide.
            if (had) post({ type: 'mia-deck-select', elId: null });
        }

        // ------------------------------------------------------- guides

        function ensureGuides(slide) {
            if (!guideV) {
                guideV = document.createElement('div'); guideV.className = 'mia-guide mia-guide-v';
                guideH = document.createElement('div'); guideH.className = 'mia-guide mia-guide-h';
            }
            if (guideV.parentNode !== slide) { slide.appendChild(guideV); slide.appendChild(guideH); }
        }
        function hideGuides() {
            if (guideV) guideV.style.display = 'none';
            if (guideH) guideH.style.display = 'none';
        }

        /**
         * Accroche les bords et le centre de l'élément sur ceux des autres
         * éléments de la slide et sur les axes du canvas. C'est ce qui fait
         * qu'un déplacement « tombe juste » sans réglage au pixel.
         */
        function snap(slide, el, g) {
            var targetsX = [0, 50, 100], targetsY = [0, 50, 100];
            var others = slide.querySelectorAll('[data-el-id]');
            for (var i = 0; i < others.length; i++) {
                if (others[i] === el) continue;
                var o = geom(others[i]);
                targetsX.push(o.x, o.x + o.w / 2, o.x + o.w);
                targetsY.push(o.y, o.y + o.h / 2, o.y + o.h);
            }

            var edgesX = [g.x, g.x + g.w / 2, g.x + g.w];
            var edgesY = [g.y, g.y + g.h / 2, g.y + g.h];
            var bestX = null, bestY = null;

            for (var e = 0; e < 3; e++) {
                for (var ix = 0; ix < targetsX.length; ix++) {
                    var d = Math.abs(edgesX[e] - targetsX[ix]);
                    if (d <= SNAP && (bestX === null || d < bestX.d)) bestX = { d: d, delta: targetsX[ix] - edgesX[e], at: targetsX[ix] };
                }
                for (var u = 0; u < targetsY.length; u++) {
                    var dy = Math.abs(edgesY[e] - targetsY[u]);
                    if (dy <= SNAP && (bestY === null || dy < bestY.d)) bestY = { d: dy, delta: targetsY[u] - edgesY[e], at: targetsY[u] };
                }
            }

            ensureGuides(slide);
            if (bestX) { g.x += bestX.delta; guideV.style.left = bestX.at + '%'; guideV.style.display = 'block'; }
            else guideV.style.display = 'none';
            if (bestY) { g.y += bestY.delta; guideH.style.top = bestY.at + '%'; guideH.style.display = 'block'; }
            else guideH.style.display = 'none';

            return g;
        }

        // ------------------------------------------------ glisser / redimensionner

        /**
         * Éléments porteurs d'un id sous le pointeur, du dessus vers le
         * dessous. Sert à atteindre ce qui est recouvert : sur une slide
         * « image pleine page », le voile de lisibilité couvre l'image — sans
         * ça, elle serait impossible à sélectionner.
         */
        function stackAt(x, y) {
            var out = [];
            var nodes = document.elementsFromPoint(x, y) || [];
            for (var i = 0; i < nodes.length; i++) {
                var owner = nodes[i].closest ? nodes[i].closest('[data-el-id]') : null;
                if (owner && out.indexOf(owner) < 0) out.push(owner);
            }
            return out;
        }

        function onPointerDown(e) {
            if (!editing() || editingText) return;
            var handle = e.target.closest ? e.target.closest('.mia-h') : null;
            var stack = stackAt(e.clientX, e.clientY);
            var el = stack[0] || (e.target.closest ? e.target.closest('[data-el-id]') : null);

            // Re-cliquer au même endroit descend d'un cran dans la pile : c'est
            // le seul moyen d'attraper un élément entièrement recouvert.
            if (!handle && selected && stack.length > 1) {
                var pos = stack.indexOf(selected);
                if (pos >= 0) el = stack[(pos + 1) % stack.length];
            }

            if (handle && selected) {
                dragMode = 'resize:' + handle.dataset.dir;
            } else if (el) {
                select(el);
                dragMode = 'move';
            } else {
                deselect();
                return;
            }

            var slide = slideOf(selected);
            var rect = slide.getBoundingClientRect();
            origin = {
                px: e.clientX, py: e.clientY,
                rect: rect, g: geom(selected),
                ratio: null,
            };
            var g = origin.g;
            if (g.h > 0) origin.ratio = g.w / g.h;
            e.preventDefault();
            e.stopPropagation();
            document.addEventListener('pointermove', onPointerMove, true);
            document.addEventListener('pointerup', onPointerUp, true);
        }

        function onPointerMove(e) {
            if (!dragMode || !selected || !origin) return;
            var dx = (e.clientX - origin.px) / origin.rect.width * 100;
            var dy = (e.clientY - origin.py) / origin.rect.height * 100;
            var o = origin.g;
            var g = { x: o.x, y: o.y, w: o.w, h: o.h };

            if (dragMode === 'move') {
                g.x = clamp(o.x + dx, 0, 100 - o.w);
                g.y = clamp(o.y + dy, 0, 100 - o.h);
            } else {
                var dir = dragMode.slice(7);
                if (dir.indexOf('e') >= 0) g.w = clamp(o.w + dx, MIN_SIZE, 100 - o.x);
                if (dir.indexOf('s') >= 0) g.h = clamp(o.h + dy, MIN_SIZE, 100 - o.y);
                if (dir.indexOf('w') >= 0) {
                    var nx = clamp(o.x + dx, 0, o.x + o.w - MIN_SIZE);
                    g.w = o.w + (o.x - nx); g.x = nx;
                }
                if (dir.indexOf('n') >= 0) {
                    var ny = clamp(o.y + dy, 0, o.y + o.h - MIN_SIZE);
                    g.h = o.h + (o.y - ny); g.y = ny;
                }
                // Shift : conserve les proportions (utile sur les images).
                if (e.shiftKey && origin.ratio) {
                    g.h = clamp(g.w / origin.ratio, MIN_SIZE, 100 - g.y);
                }
            }

            if (!e.altKey) g = snap(slideOf(selected), selected, g);
            applyGeom(selected, g);
            placeFrame();
        }

        function onPointerUp() {
            document.removeEventListener('pointermove', onPointerMove, true);
            document.removeEventListener('pointerup', onPointerUp, true);
            hideGuides();
            if (dragMode && selected) emitGeom();
            dragMode = null; origin = null;
        }

        function emitGeom() {
            var g = geom(selected);
            post({
                type: 'mia-deck-move',
                slideId: slideOf(selected).dataset.slideId,
                elId: selected.dataset.elId,
                x: round(g.x), y: round(g.y), w: round(g.w), h: round(g.h),
            });
        }

        // ---------------------------------------------------------- texte

        function startTextEdit(el) {
            if (!el || el.classList.contains('el-image') || el.classList.contains('el-shape')
                || el.classList.contains('el-table')) return;
            editingText = true;
            el.setAttribute('contenteditable', 'true');
            el.focus();
            document.documentElement.classList.add('mia-deck-text');
        }

        function stopTextEdit() {
            if (!editingText || !selected) { editingText = false; return; }
            editingText = false;
            document.documentElement.classList.remove('mia-deck-text');
            selected.removeAttribute('contenteditable');

            var payload = {
                type: 'mia-deck-text',
                slideId: slideOf(selected).dataset.slideId,
                elId: selected.dataset.elId,
            };
            if (selected.tagName === 'UL') {
                var items = [];
                var lis = selected.querySelectorAll('li');
                for (var i = 0; i < lis.length; i++) {
                    var ligne = lis[i].textContent.trim();
                    if (ligne !== '') items.push(ligne);
                }
                payload.items = items;
            } else {
                payload.text = selected.textContent.trim();
            }
            post(payload);
        }

        // -------------------------------------------------------- clavier

        function onKeyDown(e) {
            if (!editing()) return;
            if (editingText) {
                if (e.key === 'Escape') { stopTextEdit(); e.preventDefault(); }
                return;
            }
            if (!selected) return;

            if (e.key === 'Escape') { deselect(); return; }
            if (e.key === 'Delete' || e.key === 'Backspace') {
                var slideId = slideOf(selected).dataset.slideId, elId = selected.dataset.elId;
                var victim = selected;
                deselect();
                victim.parentNode.removeChild(victim);
                post({ type: 'mia-deck-delete', slideId: slideId, elId: elId });
                e.preventDefault();
                return;
            }
            if (e.key === 'Enter') { startTextEdit(selected); e.preventDefault(); return; }

            var step = e.shiftKey ? STEP_BIG : STEP;
            var g = geom(selected), moved = false;
            if (e.key === 'ArrowLeft')  { g.x = clamp(g.x - step, 0, 100 - g.w); moved = true; }
            if (e.key === 'ArrowRight') { g.x = clamp(g.x + step, 0, 100 - g.w); moved = true; }
            if (e.key === 'ArrowUp')    { g.y = clamp(g.y - step, 0, 100 - g.h); moved = true; }
            if (e.key === 'ArrowDown')  { g.y = clamp(g.y + step, 0, 100 - g.h); moved = true; }
            if (moved) {
                applyGeom(selected, g);
                placeFrame();
                emitGeom();
                e.preventDefault();
            }
        }

        // ----------------------------------------------------------- mode

        function setMode(on) {
            document.documentElement.classList.toggle('mia-deck-editing', !!on);
            if (!on) deselect();
            post({ type: 'mia-deck-mode-ack', on: !!on });
        }

        document.addEventListener('pointerdown', onPointerDown, true);
        document.addEventListener('dblclick', function (e) {
            if (!editing()) return;
            var el = e.target.closest ? e.target.closest('[data-el-id]') : null;
            if (el) { select(el); startTextEdit(el); e.preventDefault(); }
        }, true);
        document.addEventListener('keydown', onKeyDown, true);
        // Clic hors zone d'édition de texte : on valide la saisie en cours.
        document.addEventListener('pointerdown', function (e) {
            if (editingText && selected && !selected.contains(e.target)) stopTextEdit();
        }, false);

        window.addEventListener('message', function (e) {
            var d = e.data;
            if (!d || typeof d !== 'object') return;
            if (d.type === 'mia-deck-edit-mode') setMode(d.on);
            // Changer de slide pendant l'édition : la sélection n'a plus de sens.
            if (d.type === 'mia-deck-goto') deselect();
            // Mise en forme décidée dans le volet : application immédiate, sans
            // attendre le rendu serveur (le geste doit répondre tout de suite).
            if (d.type === 'mia-deck-style') {
                var target = document.querySelector(
                    '.slide[data-slide-id="' + d.slideId + '"] [data-el-id="' + d.elId + '"]'
                );
                if (target && d.style) {
                    Object.keys(d.style).forEach(function (prop) { target.style[prop] = d.style[prop]; });
                    placeFrame();
                }
            }
            // Reprise de sélection après un rechargement de l'aperçu.
            if (d.type === 'mia-deck-select-el') {
                var again = document.querySelector(
                    '.slide[data-slide-id="' + d.slideId + '"] [data-el-id="' + d.elId + '"]'
                );
                if (again) select(again);
            }
            if (d.type === 'mia-deck-set-image' && selected && selected.classList.contains('el-image')) {
                var img = selected.querySelector('img');
                if (!img) {
                    selected.innerHTML = '';
                    img = document.createElement('img');
                    img.style.objectFit = 'cover';
                    selected.appendChild(img);
                }
                img.src = d.url;
                post({
                    type: 'mia-deck-image',
                    slideId: slideOf(selected).dataset.slideId,
                    elId: selected.dataset.elId,
                    url: d.url,
                });
            }
        });

        post({ type: 'mia-deck-editor-ready' });
    }
    /* eslint-enable */

    const DECK_EDITOR_STYLE = '<style data-mia-deck-editor>'
        + '.mia-deck-editing .el{cursor:move}'
        // Une <img> est glissable nativement : sans ça, tirer une poignée ou
        // déplacer une illustration lance le drag-and-drop du navigateur et le
        // geste est perdu. `pointer-events:none` fait porter le clic par le
        // cadre de l'élément, qui est ce que l'éditeur manipule.
        + '.mia-deck-editing .el-image img{pointer-events:none;-webkit-user-drag:none;user-select:none}'
        + '.mia-deck-editing .el:hover{outline:1px dashed rgba(124,58,237,.8);outline-offset:2px}'
        + '.mia-deck-editing .mia-selected{outline:none!important}'
        + '.mia-sel{position:absolute;border:1.5px solid #7c3aed;pointer-events:none;z-index:9998}'
        + '.mia-h{position:absolute;width:9px;height:9px;background:#fff;border:1.5px solid #7c3aed;border-radius:2px;pointer-events:auto;z-index:9999}'
        + '.mia-h-nw{left:-5px;top:-5px;cursor:nwse-resize}.mia-h-se{right:-5px;bottom:-5px;cursor:nwse-resize}'
        + '.mia-h-ne{right:-5px;top:-5px;cursor:nesw-resize}.mia-h-sw{left:-5px;bottom:-5px;cursor:nesw-resize}'
        + '.mia-h-n{left:calc(50% - 4.5px);top:-5px;cursor:ns-resize}.mia-h-s{left:calc(50% - 4.5px);bottom:-5px;cursor:ns-resize}'
        + '.mia-h-w{left:-5px;top:calc(50% - 4.5px);cursor:ew-resize}.mia-h-e{right:-5px;top:calc(50% - 4.5px);cursor:ew-resize}'
        + '.mia-guide{position:absolute;background:#ec4899;z-index:9997;pointer-events:none;display:none}'
        + '.mia-guide-v{top:0;bottom:0;width:1px}.mia-guide-h{left:0;right:0;height:1px}'
        + '.mia-deck-text .el[contenteditable]{outline:2px solid #7c3aed;outline-offset:2px;cursor:text}'
        + '</style>';

    const DECK_EDITOR_SCRIPT = '<scr' + t('ipt data-mia-deck-editor>(')
        + miaDeckEditor.toString() + ')();</scr' + 'ipt>';

    /** Injecte l'éditeur dans le HTML rendu par le serveur (jamais persisté). */
    function buildEditableDoc(html) {
        const injection = DECK_EDITOR_STYLE + DECK_EDITOR_SCRIPT;
        return html.includes('</body>')
            ? html.replace('</body>', injection + '</body>')
            : html + injection;
    }

    // ------------------------------------------------- édition côté volet

    let editMode = false;
    let saveTimer = null;
    // Une modification de mise en forme demande le rendu serveur : le drapeau
    // survit à la temporisation de l'enregistrement.
    let pendingReload = false;
    // Vrai entre la demande de rechargement de l'aperçu et l'annonce du
    // nouveau document : les messages de l'ancien ne comptent plus.
    let reloading = false;

    async function setEditMode(on) {
        // En quittant l'édition, on vide la file d'attente AVANT de recharger
        // l'aperçu : sinon state.html est encore la version d'avant et les
        // dernières retouches sembleraient perdues.
        if (!on && saveTimer) {
            clearTimeout(saveTimer);
            saveTimer = null;
            await saveDeck();
        }
        editMode = !!on;
        const btn = el('deckViewerEdit');
        btn?.classList.toggle('edit-active', editMode);
        if (btn) {
            btn.title = editMode
                ? t('Terminer les modifications')
                : t('Modifier les slides (déplacer, redimensionner, réécrire)');
        }
        if (!editMode) state.sel = null;
        toggleImagePicker(false);
        fillFormatSelects();
        syncFormatBar();
        // Le document éditable n'est chargé qu'en mode édition : l'aperçu
        // normal reste strictement ce que verra le destinataire d'un partage.
        showDeck(state.html || '', editMode);
    }

    el('deckViewerEdit')?.addEventListener('click', () => {
        if (!state.deck || !state.msgId) return;
        setEditMode(!editMode);
    });

    // ------------------------------------------------- mise en forme du texte

    const SIZES_LIST = window.DECK_SIZES || [];
    const FONTS_LIST = window.DECK_FONTS || [];
    const fmtBar = el('deckFormatBar');

    function fillFormatSelects() {
        const size = el('deckFmtSize');
        const font = el('deckFmtFont');
        if (size && !size.options.length) {
            SIZES_LIST.forEach((s) => size.add(new Option(s.label, s.key)));
        }
        if (font && !font.options.length) {
            FONTS_LIST.forEach((f) => font.add(new Option(f.label, f.key)));
        }
    }

    /** Élément actuellement sélectionné dans l'aperçu, ou null. */
    function selectedElement() {
        return state.sel ? findElement(state.sel.slideId, state.sel.elId) : null;
    }

    /**
     * Reflète la sélection dans la barre. Les contrôles de mise en forme ne
     * valent que pour du texte : sur une image ou un aplat, ils sont désactivés
     * plutôt que masqués (la barre ne saute pas d'une sélection à l'autre).
     */
    function syncFormatBar() {
        if (!fmtBar) return;
        fmtBar.hidden = !editMode;
        if (!editMode) return;

        const hit = selectedElement();
        const elm = hit && (hit.element.type === 'text' || hit.element.type === 'bullets') ? hit.element : null;

        fmtBar.querySelectorAll('[data-fmt], .deck-fmt-select, #deckFmtColor, #deckFmtColorReset').forEach((node) => {
            node.disabled = !elm;
        });
        fmtBar.querySelector('[data-fmt="bold"]')?.setAttribute('aria-pressed', elm && (elm.weight | 0) >= 600 ? 'true' : 'false');
        fmtBar.querySelector('[data-fmt="italic"]')?.setAttribute('aria-pressed', elm && elm.italic ? 'true' : 'false');
        const size = el('deckFmtSize');
        const font = el('deckFmtFont');
        const color = el('deckFmtColor');
        if (size) size.value = elm ? (elm.size || 'm') : 'm';
        if (font) font.value = elm ? (elm.font || '') : '';
        // L'input couleur n'a pas d'état « aucune » : sans couleur choisie, il
        // affiche celle du thème sans que ça vaille sélection.
        if (color) color.value = hexColor(elm && elm.color) || hexColor('text') || themeByKey(state.theme).text || '#ffffff';
    }

    /**
     * Couleur d'un élément en #rrggbb : une présentation du chat porte des
     * couleurs NOMMÉES (« on-deep », « accent-ink »…, DeckSpec::TEXT_TOKENS),
     * résolues ici dans le thème de la présentation — un <input type="color">
     * ne sait afficher qu'un code hexadécimal.
     */
    function hexColor(value) {
        const v = String(value || '');
        if (/^#[0-9a-f]{6}$/i.test(v)) return v;
        if (v === '') return '';
        const theme = (state.deck && state.deck.theme) || {};
        const found = theme[v.replace(/-/g, '_')];

        return /^#[0-9a-f]{6}$/i.test(String(found || '')) ? String(found) : '';
    }

    /** Propriétés CSS à appliquer en direct dans l'aperçu (retour immédiat). */
    function inlineStyleFor(elm) {
        const size = SIZES_LIST.find((s) => s.key === (elm.size || 'm'));
        const font = FONTS_LIST.find((f) => f.key === (elm.font || ''));

        return {
            fontWeight: String(elm.weight || 400),
            fontStyle: elm.italic ? 'italic' : 'normal',
            color: hexColor(elm.color) || '',
            fontFamily: (font && font.css) || '',
            // Taille de BASE : la réduction automatique d'un texte trop long
            // est calculée par le serveur, et revient avec le rendu enregistré.
            fontSize: size ? size.cqw + 'cqw' : '',
        };
    }

    function applyStyle(patch) {
        const hit = selectedElement();
        if (!hit) return;
        Object.assign(hit.element, patch);
        frame?.contentWindow?.postMessage({
            type: 'mia-deck-style',
            slideId: state.sel.slideId,
            elId: state.sel.elId,
            style: inlineStyleFor(hit.element),
        }, '*');
        // Rechargement après enregistrement : seul le rendu serveur connaît la
        // taille définitive et les règles de couleur du layout.
        scheduleDeckSave(true);
        syncFormatBar();
    }

    /** Nouvelle zone de texte au centre de la slide affichée. */
    async function insertTextBox() {
        const slide = (state.deck?.slides || [])[state.index];
        if (!slide) return;
        const id = 'txt' + Date.now().toString(36);
        slide.elements = slide.elements || [];
        slide.elements.push({
            id, type: 'text', text: t('Nouveau texte'), role: 'corps',
            size: 'm', align: 'left', weight: 400, x: 20, y: 42, w: 60, h: 12,
        });
        state.sel = { slideId: slide.id, elId: id };
        // Insertion enregistrée tout de suite : l'aperçu est reconstruit par
        // DeckRenderer, seule implémentation du rendu — rien n'est fabriqué
        // en JavaScript dans l'iframe.
        clearTimeout(saveTimer);
        saveTimer = null;
        setSaveState('saving');
        await saveDeck(true);
    }

    // ------------------------------------------------- insertion d'une image

    /** Outil image utilisable : celui du deck, sinon le premier outil branché. */
    function imageToolId() {
        if (state.imageToolId) return state.imageToolId;
        const opt = state.deck?.options?.image_tool_id;
        if (opt) return opt;
        // Branché ET en service : `retired` porte la raison d'un modèle arrêté.
        const first = (window.IMAGE_TOOLS || []).find((outil) => outil.connected && !outil.retired);

        return first ? first.id : 0;
    }

    function toggleImagePicker(open) {
        const picker = el('deckImagePicker');
        const btn = el('deckAddImage');
        if (!picker || !btn) return;
        const show = open === undefined ? picker.hidden : open;
        picker.hidden = !show;
        btn.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (!show) return;
        // Ligne « générer » masquée si aucun outil image n'est disponible :
        // pas de bouton qui échouerait une fois sur deux.
        const gen = el('deckImageGenRow');
        if (gen) gen.hidden = !imageToolId();
        const note = el('deckImageNote');
        if (note) {
            note.hidden = !!imageToolId();
            note.textContent = t("Aucun outil de génération d'image n'est disponible.");
        }
        el('deckImagePrompt')?.focus();
    }

    /** Pose l'image dans la slide affichée, puis enregistre (rendu serveur). */
    async function insertImage(url) {
        const slide = (state.deck?.slides || [])[state.index];
        if (!slide || !url) return;
        const id = 'img' + Date.now().toString(36);
        slide.elements = slide.elements || [];
        // Boîte proche du carré (les modèles rendent surtout du carré) ; le
        // recadrage « cover » évite toute déformation, la taille se règle
        // ensuite aux poignées.
        slide.elements.push({ id, type: 'image', url, prompt: '', fit: 'cover', x: 32, y: 22, w: 36, h: 56 });
        state.sel = { slideId: slide.id, elId: id };
        clearTimeout(saveTimer);
        saveTimer = null;
        setSaveState('saving');
        await saveDeck(true);
    }

    async function uploadDeckImage(file) {
        if (!file || !state.msgId) return;
        const fd = new FormData();
        fd.append('image', file);
        try {
            const res = await fetch('/chat/message/' + state.msgId + '/deck-image', {
                method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.url) { fail(data.error || t("Échec de l'envoi de l'image."), data); return; }
            toggleImagePicker(false);
            await insertImage(data.url);
        } catch (e) {
            fail("Échec de l'envoi de l'image.", e);
        }
    }

    async function generateDeckImage(prompt) {
        if (!prompt || !state.msgId) return;
        const btn = el('deckImageGen');
        const label = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = t('Génération…'); }
        try {
            const fd = new FormData();
            fd.append('prompt', prompt);
            fd.append('tool_id', String(imageToolId()));
            const res = await fetch('/chat/message/' + state.msgId + '/deck-image/generate', {
                method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd,
            });
            const data = await res.json().catch(() => ({}));
            if (data.quota_status && typeof applyQuotaStatus === 'function') applyQuotaStatus(data.quota_status);
            if (!res.ok || !data.url) { fail(data.error || t("La génération d'image a échoué."), data); return; }
            toggleImagePicker(false);
            const input = el('deckImagePrompt');
            if (input) input.value = '';
            await insertImage(data.url);
        } catch (e) {
            fail("La génération d'image a échoué.", e);
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = label; }
        }
    }

    el('deckAddImage')?.addEventListener('click', (e) => { e.stopPropagation(); toggleImagePicker(); });
    el('deckImageFileBtn')?.addEventListener('click', () => el('deckImageFile')?.click());
    el('deckImageFile')?.addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        e.target.value = '';           // même fichier réinséré : l'évènement doit repartir
        uploadDeckImage(file);
    });
    el('deckImageGen')?.addEventListener('click', () => generateDeckImage((el('deckImagePrompt')?.value || '').trim()));
    el('deckImagePrompt')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); generateDeckImage((e.target.value || '').trim()); }
    });
    document.addEventListener('click', (e) => {
        const picker = el('deckImagePicker');
        if (picker && !picker.hidden && !el('deckImagePickWrap')?.contains(e.target)) toggleImagePicker(false);
    });

    fmtBar?.querySelector('[data-fmt="bold"]')?.addEventListener('click', () => {
        const hit = selectedElement();
        if (hit) applyStyle({ weight: (hit.element.weight | 0) >= 600 ? 400 : 700 });
    });
    fmtBar?.querySelector('[data-fmt="italic"]')?.addEventListener('click', () => {
        const hit = selectedElement();
        if (hit) applyStyle({ italic: !hit.element.italic });
    });
    el('deckFmtSize')?.addEventListener('change', (e) => applyStyle({ size: e.target.value }));
    el('deckFmtFont')?.addEventListener('change', (e) => applyStyle({ font: e.target.value }));
    el('deckFmtColor')?.addEventListener('change', (e) => applyStyle({ color: e.target.value }));
    el('deckFmtColorReset')?.addEventListener('click', () => applyStyle({ color: '' }));
    el('deckAddText')?.addEventListener('click', () => { if (!state.busy) insertTextBox(); });

    /** Retrouve un élément de la spec par identifiants de slide et d'élément. */
    function findElement(slideId, elId) {
        const slide = (state.deck?.slides || []).find((s) => s.id === slideId);
        if (!slide) return null;
        const element = (slide.elements || []).find((e) => e.id === elId);

        return element ? { slide, element } : null;
    }

    function onEditorMessage(d) {
        if (d.type === 'mia-deck-move') {
            const hit = findElement(d.slideId, d.elId);
            if (!hit) return;
            Object.assign(hit.element, { x: d.x, y: d.y, w: d.w, h: d.h });
            scheduleDeckSave();
        } else if (d.type === 'mia-deck-text') {
            const hit = findElement(d.slideId, d.elId);
            if (!hit) return;
            if (Array.isArray(d.items)) hit.element.items = d.items;
            else if (typeof d.text === 'string') hit.element.text = d.text;
            scheduleDeckSave();
        } else if (d.type === 'mia-deck-image') {
            const hit = findElement(d.slideId, d.elId);
            if (!hit) return;
            hit.element.url = d.url;
            scheduleDeckSave();
        } else if (d.type === 'mia-deck-delete') {
            const slide = (state.deck?.slides || []).find((s) => s.id === d.slideId);
            if (!slide) return;
            slide.elements = (slide.elements || []).filter((e) => e.id !== d.elId);
            state.sel = null;
            syncFormatBar();
            scheduleDeckSave();
        } else if (d.type === 'mia-deck-select') {
            if (reloading) return;   // écho de l'aperçu en cours de remplacement
            state.sel = d.elId ? { slideId: d.slideId, elId: d.elId } : null;
            syncFormatBar();
        }
    }

    /** Sauvegarde débouncée : un glissement ne déclenche qu'un seul POST. */
    function scheduleDeckSave(reload) {
        setSaveState('saving');
        if (reload) pendingReload = true;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => saveDeck(), 800);
    }

    /**
     * @param {boolean} reload recharger l'aperçu avec le rendu renvoyé.
     *   Nécessaire dès que la spec change autrement que par la géométrie :
     *   l'aperçu ne sait pas recalculer une taille de police réduite ni la
     *   couleur qu'impose un layout — DeckRenderer, si.
     */
    async function saveDeck(reload) {
        if (!state.deck || !state.msgId) return;
        const wantReload = reload || pendingReload;
        pendingReload = false;
        try {
            const res = await fetch('/chat/message/' + state.msgId + '/deck', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ deck: state.deck }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) { setSaveState('error'); return; }
            // Le serveur renvoie la spec normalisée : elle fait foi.
            state.deck = data.deck || state.deck;
            state.html = data.html || state.html;
            setSaveState('saved');
            if (wantReload && editMode) {
                // Pendant le remplacement du document, les messages de
                // l'ancien sont ignorés : son `mia-deck-goto` provoquerait une
                // désélection qui effacerait la sélection à restaurer.
                reloading = true;
                const keep = state.index;
                renderFilmstrip();
                setIndex(keep, false);
                // La sélection est reprise sur `mia-deck-editor-ready`.
                showDeck(state.html, true);
            } else {
                renderFilmstripPreserveIndex();
            }
        } catch (e) {
            setSaveState('error');
        }
    }

    function setSaveState(kind) {
        const box = el('deckSaveState');
        if (!box) return;
        box.classList.remove('saved', 'error');
        if (kind === 'saving') { box.textContent = 'Enregistrement…'; }
        else if (kind === 'saved') { box.textContent = t('Enregistré ✓'); box.classList.add('saved'); }
        else { box.textContent = t('Échec de l’enregistrement'); box.classList.add('error'); }
        if (kind !== 'saving') setTimeout(() => { if (box.textContent !== 'Enregistrement…') box.textContent = ''; }, 2500);
    }

    /** Re-rend les vignettes sans perdre la slide affichée. */
    function renderFilmstripPreserveIndex() {
        const keep = state.index;
        renderFilmstrip();
        setIndex(keep, true);
    }

    // ------------------------------------------------------------------ API

    /** Appelé par le dispatch d'outil de chat.js. */
    function start(prompt, tool) {
        state.toolId = tool.id;
        state.subject = prompt || '';
        state.msgId = null;
        state.deck = null;
        state.outline = [];
        state.sel = null;

        // Outil image associé côté admin : conditionne l'offre d'illustrations.
        const meta = (window.PRESENTATION_TOOLS || []).find((outil) => String(outil.id) === String(tool.id));
        let cfg = {};
        try { cfg = meta && meta.config_json ? JSON.parse(meta.config_json) : {}; } catch (e) { cfg = {}; }
        state.imageToolId = parseInt(cfg.image_tool_id, 10) || 0;
        if (cfg.default_slides) state.options.slide_count = parseInt(cfg.default_slides, 10) || DEFAULTS.slide_count;

        // Fichiers joints au message : capturés AVANT le vidage du composer,
        // comme le font les autres outils (snapshot de pendingFiles).
        state.files = (typeof pendingFiles !== 'undefined' && Array.isArray(pendingFiles)) ? [...pendingFiles] : [];
        if (typeof clearFiles === 'function') clearFiles();

        if (subjectInput) subjectInput.value = state.subject;
        buildOptionsUi();
        open();
        goStep('options');

        // Le message reste dans le composer tant que rien n'est généré : on le
        // vide seulement une fois la demande prise en charge par le volet.
        if (typeof msgInput !== 'undefined' && msgInput) {
            msgInput.value = '';
            msgInput.style.height = 'auto';
        }
    }

    window.Deck = { start, open, close, openFromMessage, isOpen: () => viewer.classList.contains('open') };

    }
})();
