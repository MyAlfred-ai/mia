/**
 * Idées d'utilisation dans la zone de saisie vide.
 *
 * Le texte d'aide du champ (« Envoyer un message ou déposer vos fichiers… »)
 * s'affiche d'abord, puis laisse la place, en fondu, à des exemples de
 * demandes professionnelles — uniquement ce que la personne peut réellement
 * faire (outils, connecteurs reliés, tâches programmées, workflows…) :
 * window.COMPOSER_HINTS, calculé et traduit côté serveur (ComposerHints).
 * Ce script n'a aucun texte à lui.
 *
 * Le texte d'aide n'est PAS l'attribut placeholder (qu'on ne sait ni animer
 * ni faire défiler) mais un calque posé juste après le champ ; l'attribut
 * reste, invisible, pour deux raisons : il donne son nom au champ pour les
 * lecteurs d'écran, et `:placeholder-shown` dit au CSS si le champ est vide —
 * que la saisie vienne du clavier, de la dictée, d'un brouillon restauré ou
 * d'un envoi qui le vide. Aucun évènement à suivre : le calque disparaît à la
 * première lettre et revient quand le champ se vide.
 *
 * Les idées ne servent qu'à DÉMARRER : dès que la conversation est lancée
 * (un message dans le fil), elles s'arrêtent et le texte d'aide reste seul.
 *
 * Suggestion de suite (FollowUpSuggestion, côté serveur) : après une réponse
 * de MIA, chat.js demande la prochaine demande probable et la confie ici
 * (`setSuggestion`). Elle prend la place du texte d'aide, en clair, avec son
 * raccourci : Tab la pose dans le champ (rien ne part sans Entrée) ; au doigt,
 * le bouton « Utiliser ». Textes : window.COMPOSER_SUGGEST (traduits côté
 * serveur, null quand l'administrateur a coupé les suggestions).
 *
 * Partie PURE (ordre de passage des idées, mode affiché) exécutée sous node
 * par les tests.
 */
(function (root) {
    'use strict';

    /** Durée d'affichage du texte d'aide du champ, puis d'une idée (ms). */
    const BASE_MS = 4200;
    const HINT_MIN_MS = 3600;
    const HINT_MAX_MS = 6400;
    const MS_PER_CHAR = 55;
    /** Le texte d'aide du champ revient toutes les N idées. */
    const BASE_EVERY = 6;
    /** Durée de la transition (doit couvrir l'animation CSS). */
    const SWAP_MS = 700;

    // ------------------------------------------------------------------
    // 1. Ordre de passage — PUR
    // ------------------------------------------------------------------

    function shuffle(list, random) {
        const out = list.slice();
        for (let i = out.length - 1; i > 0; i--) {
            const j = Math.floor(random() * (i + 1));
            const tmp = out[i];
            out[i] = out[j];
            out[j] = tmp;
        }
        return out;
    }

    /**
     * Idées `[{g, t}]` → textes dans l'ordre de passage : une idée par groupe,
     * à tour de rôle, groupes et idées mélangés — deux exemples voisins ne
     * parlent jamais de la même chose tant qu'il reste un autre groupe.
     */
    function rotation(hints, random) {
        const rand = typeof random === 'function' ? random : Math.random;
        const groups = {};
        const order = [];
        (Array.isArray(hints) ? hints : []).forEach(function (h) {
            if (!h || typeof h.t !== 'string' || h.t.trim() === '') return;
            const g = String(h.g || '');
            if (!groups[g]) {
                groups[g] = [];
                order.push(g);
            }
            groups[g].push(h.t.trim());
        });

        const queues = shuffle(order, rand).map(function (g) { return shuffle(groups[g], rand); });
        const out = [];
        let left = true;
        while (left) {
            left = false;
            queues.forEach(function (queue) {
                if (queue.length) {
                    out.push(queue.shift());
                    left = left || queue.length > 0;
                }
            });
        }
        return out;
    }

    /** Temps de lecture d'une idée : plus elle est longue, plus elle reste. */
    function dwell(text) {
        return Math.max(HINT_MIN_MS, Math.min(HINT_MAX_MS, 2000 + String(text || '').length * MS_PER_CHAR));
    }

    /**
     * Ce que montre le calque : la suggestion de suite s'il y en a une, sinon
     * le texte d'aide seul dans une conversation lancée, sinon les idées.
     */
    function mode(state) {
        const st = state || {};
        if (typeof st.suggestion === 'string' && st.suggestion.trim() !== '') return 'suggestion';
        return st.started ? 'base' : 'ideas';
    }

    /** Phrase lue par les lecteurs d'écran (« Suggestion : {texte}… »). */
    function describe(template, text) {
        const tpl = typeof template === 'string' ? template : '';
        const value = String(text || '');
        return tpl.indexOf('{texte}') >= 0 ? tpl.replace('{texte}', function () { return value; }) : value;
    }

    const api = { rotation: rotation, dwell: dwell, mode: mode, describe: describe, BASE_EVERY: BASE_EVERY };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (typeof document === 'undefined') {
        root.MiaComposerHints = api;
        return;
    }

    // ------------------------------------------------------------------
    // 2. Calque animé
    // ------------------------------------------------------------------

    const input = document.getElementById('msgInput');
    const row = input ? input.closest('.input-row') : null;
    if (!input || !row) {
        root.MiaComposerHints = api;
        return;
    }

    const reduceMotion = root.matchMedia ? root.matchMedia('(prefers-reduced-motion: reduce)') : null;

    const overlay = document.createElement('div');
    overlay.className = 'composer-hint';
    overlay.setAttribute('aria-hidden', 'true');
    const track = document.createElement('div');
    track.className = 'composer-hint-track';
    overlay.appendChild(track);
    input.insertAdjacentElement('afterend', overlay);
    row.classList.add('has-composer-hint');

    // Suggestion de suite : texte + raccourci (Tab), bouton « Utiliser » au
    // doigt. Le calque reste aria-hidden : les lecteurs d'écran la reçoivent
    // par la description du champ (aria-describedby), avec son raccourci.
    const texts = root.COMPOSER_SUGGEST && typeof root.COMPOSER_SUGGEST === 'object' ? root.COMPOSER_SUGGEST : null;
    const suggestionEl = document.createElement('div');
    suggestionEl.className = 'composer-suggestion';
    suggestionEl.hidden = true;
    const suggestionText = document.createElement('span');
    suggestionText.className = 'composer-suggestion-text';
    suggestionEl.appendChild(suggestionText);
    const acceptBtn = document.createElement('button');
    acceptBtn.type = 'button';
    acceptBtn.className = 'composer-suggestion-accept';
    acceptBtn.tabIndex = -1;
    if (texts) {
        acceptBtn.title = String(texts.title || '');
        const key = document.createElement('kbd');
        key.className = 'composer-suggestion-key';
        key.textContent = String(texts.key || '');
        const use = document.createElement('span');
        use.className = 'composer-suggestion-use';
        use.textContent = String(texts.accept || '');
        acceptBtn.appendChild(key);
        acceptBtn.appendChild(use);
    }
    suggestionEl.appendChild(acceptBtn);
    overlay.appendChild(suggestionEl);

    const srEl = document.createElement('span');
    srEl.id = 'composerSuggestionSr';
    srEl.className = 'composer-suggestion-sr';
    overlay.insertAdjacentElement('afterend', srEl);
    const describedBy = input.getAttribute('aria-describedby') || '';

    let ideas = rotation(root.COMPOSER_HINTS);
    let next = 0;          // prochaine idée
    let sinceBase = 0;     // idées montrées depuis le dernier texte d'aide
    let current = null;    // ligne affichée
    let timer = null;
    // Suite proposée après la dernière réponse (vide = aucune), et dernier mode rendu.
    let suggestion = '';
    let shownMode = null;

    /** La conversation est lancée : un message (ou plus) dans le fil. */
    function started() {
        return !!document.querySelector('#chatMessages > .message');
    }

    function currentMode() {
        return mode({ suggestion: texts ? suggestion : '', started: started() });
    }

    function base() {
        return String(input.getAttribute('placeholder') || '').trim();
    }

    function lineFor(text) {
        const line = document.createElement('span');
        line.className = 'composer-hint-line';
        line.textContent = text;
        return line;
    }

    /** La phrase tient-elle sur la ligne ? (une idée coupée par « … » ne se lit pas) */
    function fits(text) {
        const probe = lineFor(text);
        probe.classList.add('is-probe');
        track.appendChild(probe);
        const ok = probe.scrollWidth <= probe.clientWidth + 1;
        probe.remove();
        return ok;
    }

    /** Remplace la ligne affichée, avec la transition (sauf au premier affichage). */
    function show(text, animate) {
        const line = lineFor(text);
        track.appendChild(line);
        if (animate && current) {
            const old = current;
            old.classList.add('is-leaving');
            line.classList.add('is-entering');
            setTimeout(function () { old.remove(); }, SWAP_MS);
            setTimeout(function () { line.classList.remove('is-entering'); }, SWAP_MS);
        } else if (current) {
            current.remove();
        }
        current = line;
        return line;
    }

    /**
     * Prochaine idée QUI TIENT sur la ligne (sur téléphone, une idée trop
     * longue est sautée plutôt que montrée coupée) ; null si aucune ne tient.
     */
    function nextIdea() {
        for (let n = ideas.length; n > 0; n--) {
            const text = ideas[next];
            next++;
            if (next >= ideas.length) {
                next = 0;
                ideas = rotation(root.COMPOSER_HINTS); // nouveau tour, nouvel ordre
            }
            if (fits(text)) return text;
        }
        return null;
    }

    /** Prochaine phrase : le texte d'aide du champ toutes les BASE_EVERY idées, sinon une idée. */
    function pick() {
        const text = sinceBase < BASE_EVERY ? nextIdea() : null;
        if (text === null) {
            sinceBase = 0;
            return { text: base(), isBase: true };
        }
        sinceBase++;
        return { text: text, isBase: false };
    }

    /** Le défilement n'a de sens que champ vide, page visible. */
    function idle() {
        return !document.hidden && input.value === '' && !document.body.classList.contains('voice-chat-on');
    }

    function schedule(ms) {
        clearTimeout(timer);
        timer = setTimeout(step, ms);
    }

    /** Lignes de texte que le champ vide laisse voir (la suggestion s'y enroule). */
    function visibleLines() {
        const cs = root.getComputedStyle ? root.getComputedStyle(input) : null;
        const lh = cs ? parseFloat(cs.lineHeight) : 0;
        if (!cs || !lh) return 1;
        const inner = input.clientHeight - (parseFloat(cs.paddingTop) || 0) - (parseFloat(cs.paddingBottom) || 0);
        return Math.max(1, Math.floor((inner + 1) / lh));
    }

    /**
     * Pose le mode courant : suggestion (le texte d'aide et les idées
     * s'effacent), texte d'aide seul (conversation lancée) ou idées.
     */
    function render() {
        const m = currentMode();
        const changed = m !== shownMode;
        shownMode = m;

        if (m === 'suggestion') {
            const fresh = suggestionText.textContent !== suggestion || suggestionEl.hidden;
            suggestionText.textContent = suggestion;
            overlay.style.setProperty('--composer-suggest-lines', String(visibleLines()));
            overlay.classList.add('has-suggestion');
            suggestionEl.hidden = false;
            if (fresh) {
                suggestionEl.classList.remove('is-entering');
                // Rejoue l'animation d'entrée.
                void suggestionEl.offsetWidth;
                suggestionEl.classList.add('is-entering');
            }
            srEl.textContent = describe(texts && texts.sr, suggestion);
            input.setAttribute('aria-describedby', (describedBy ? describedBy + ' ' : '') + srEl.id);
            return;
        }

        overlay.classList.remove('has-suggestion');
        suggestionEl.hidden = true;
        srEl.textContent = '';
        if (describedBy) input.setAttribute('aria-describedby', describedBy);
        else input.removeAttribute('aria-describedby');

        if (m === 'base') {
            // Conversation lancée : les idées s'arrêtent, le texte d'aide reste.
            if (!current || current.textContent !== base()) show(base(), changed);
            return;
        }
        if (changed) {
            // Retour à une conversation vide : on repart du texte d'aide.
            sinceBase = 0;
            show(base(), false);
            if (ideas.length) schedule(BASE_MS);
        }
    }

    function step() {
        if (currentMode() !== 'ideas') {
            render();
            schedule(1500);
            return;
        }
        if (!idle()) {
            schedule(1500);
            return;
        }
        const choice = pick();
        // Déjà à l'écran (aucune idée ne tient, ou une seule) : pas de fondu pour rien.
        if (!current || current.textContent !== choice.text) show(choice.text, true);
        schedule(choice.isBase ? BASE_MS : dwell(choice.text));
    }

    /** Pose la suggestion dans le champ : Tab, ou « Utiliser » au doigt. Rien n'est envoyé. */
    function accept() {
        if (currentMode() !== 'suggestion' || input.value !== '') return false;
        input.value = suggestion;
        // Même chemin qu'une frappe : hauteur du champ, bouton Envoyer,
        // brouillon, suggestion d'outil (tool-suggest.js).
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
        try { input.setSelectionRange(input.value.length, input.value.length); } catch (_e) {}
        return true;
    }

    // Le texte d'aide du champ d'abord, sans transition.
    show(base(), false);
    render();
    if (ideas.length || started()) schedule(BASE_MS);

    // Onglet de retour au premier plan : on reprend sur le texte d'aide.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden || !ideas.length || currentMode() !== 'ideas') return;
        sinceBase = 0;
        show(base(), false);
        schedule(BASE_MS);
    });

    // Premier message envoyé, conversation changée : les idées s'arrêtent (ou
    // reprennent) aussitôt, sans attendre le prochain passage.
    const thread = document.getElementById('chatMessages');
    if (thread && root.MutationObserver) {
        new MutationObserver(function () {
            if (currentMode() !== shownMode) render();
        }).observe(thread, { childList: true });
    }

    // Tab dans le champ VIDE pendant qu'une suggestion s'affiche : elle y
    // entre. Sinon Tab garde son rôle (passer au contrôle suivant).
    input.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey || e.isComposing) return;
        if (input.value !== '' || document.body.classList.contains('voice-chat-on')) return;
        if (accept()) e.preventDefault();
    });
    // Le bouton ne prend pas le focus (le clavier du téléphone resterait ouvert ou fermé tel quel).
    acceptBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
    acceptBtn.addEventListener('click', function (e) {
        e.preventDefault();
        accept();
    });

    root.addEventListener('resize', function () {
        if (shownMode === 'suggestion') overlay.style.setProperty('--composer-suggest-lines', String(visibleLines()));
    });

    // Texte d'aide changé ailleurs (chat.js le raccourcit sur téléphone) :
    // la ligne affichée suit s'il est à l'écran.
    if (root.MutationObserver) {
        new MutationObserver(function () {
            if (current && sinceBase === 0) current.textContent = base();
        }).observe(input, { attributes: true, attributeFilter: ['placeholder'] });
    }

    root.MiaComposerHints = Object.assign(api, {
        /** Forcer la phrase suivante (tests, débogage). */
        next: step,
        /** Suite proposée après la dernière réponse (chat.js) ; null ou '' l'efface. */
        setSuggestion: function (text) {
            const value = typeof text === 'string' ? text.trim() : '';
            if (value === suggestion) return;
            suggestion = value;
            render();
        },
        suggestion: function () { return suggestion; },
        /** La poser dans le champ (comme Tab) ; false s'il n'y en a pas ou si le champ n'est pas vide. */
        accept: accept,
        reduceMotion: function () { return !!(reduceMotion && reduceMotion.matches); },
    });

    // Chargé après chat.js : la conversation ouverte au chargement a peut-être
    // déjà sa suggestion (gardée avec la dernière réponse).
    if (typeof root.syncComposerSuggestion === 'function') root.syncComposerSuggestion();
})(typeof window !== 'undefined' ? window : globalThis);
