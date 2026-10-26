/*
 * Palette « Rechercher ou aller à… » (⌘K / Ctrl+K) — la SEULE recherche de
 * MIA : pages des trois espaces, quelques actions (nouvelle conversation,
 * créer un assistant, reconnecter un service…) et conversations.
 *
 * Données : window.MIA_PALETTE, posé par templates/partials/command-palette.php
 * depuis NavRegistry::paletteEntries — libellés déjà traduits, droits déjà
 * appliqués. Ce script n'a AUCUN libellé : les textes de l'interface viennent
 * aussi du serveur (MIA_PALETTE.text).
 *
 * Conversations : GET /chat/search?q= (ChatController::search), 250 ms après
 * la dernière frappe, réponses périmées écartées par un numéro de séquence.
 * Dans le chat, une conversation trouvée s'ouvre sans recharger
 * (switchConversation) ; ailleurs, par son adresse.
 *
 * Deux parties : les FORMES PURES (normalize, score, filter, suggestions,
 * highlightParts) exportées pour les tests sous node, puis le comportement
 * de la page.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.MiaPalette = api;
    }
})(typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    /** Minuscules, sans accents ni blancs en trop : « Réglages » = « reglages ». */
    function normalize(text) {
        return String(text == null ? '' : text)
            .normalize('NFD')
            .replace(/\p{M}+/gu, '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    /**
     * Pertinence d'une entrée pour une recherche (0 = écartée). Chaque mot
     * cherché doit se trouver dans le libellé, le chemin ou les mots-clés ;
     * le libellé compte plus que le reste.
     */
    function score(entry, query) {
        var q = normalize(query);
        if (q === '') return 0;
        var label = normalize(entry.label);
        var hay = label + ' ' + normalize(entry.path) + ' ' + normalize(entry.keys);
        var words = q.split(' ');
        for (var i = 0; i < words.length; i++) {
            if (hay.indexOf(words[i]) === -1) return 0;
        }
        if (label === q) return 100;
        if (label.indexOf(q) === 0) return 80;
        if ((' ' + label).indexOf(' ' + q) !== -1) return 60;
        if (label.indexOf(q) !== -1) return 40;
        var inLabel = 0;
        for (var j = 0; j < words.length; j++) {
            if (label.indexOf(words[j]) !== -1) inLabel++;
        }
        return 10 + inLabel * 5;
    }

    /** Entrées qui répondent, les plus pertinentes d'abord (ordre d'origine à égalité). */
    function filter(entries, query, limit) {
        var scored = [];
        for (var i = 0; i < entries.length; i++) {
            var s = score(entries[i], query);
            if (s > 0) scored.push({ e: entries[i], s: s, i: i });
        }
        scored.sort(function (a, b) { return b.s - a.s || a.i - b.i; });
        var out = scored.map(function (x) { return x.e; });
        return typeof limit === 'number' ? out.slice(0, limit) : out;
    }

    /**
     * Ce que propose la palette avant toute frappe : les actions (nouvelle
     * conversation, créer, reconnecter…), puis les pages de l'espace de
     * travail.
     */
    function suggestions(entries, limit) {
        var actions = entries.filter(function (e) { return e.kind === 'action' && e.space !== 'admin'; });
        var work = entries.filter(function (e) { return e.kind === 'page' && e.space === 'work'; });
        return actions.concat(work).slice(0, typeof limit === 'number' ? limit : 12);
    }

    /**
     * Découpe un texte en morceaux à surligner là où il contient la recherche
     * (sans tenir compte des accents ni de la casse). Les positions sont
     * calculées sur le texte d'origine, caractère par caractère.
     * @return Array<{text:string, hit:boolean}>
     */
    function highlightParts(text, query) {
        var src = String(text == null ? '' : text);
        var q = normalize(query);
        if (q === '' || src === '') return [{ text: src, hit: false }];
        // Forme normalisée caractère par caractère, avec la position d'origine.
        var chars = Array.from(src);
        var norm = '';
        var map = [];
        for (var i = 0; i < chars.length; i++) {
            var n = normalize(chars[i]);
            if (chars[i] === ' ') n = ' ';
            for (var k = 0; k < n.length; k++) {
                norm += n[k];
                map.push(i);
            }
        }
        var hits = new Array(chars.length).fill(false);
        var words = q.split(' ').filter(Boolean);
        words.forEach(function (w) {
            var from = 0;
            var at;
            while ((at = norm.indexOf(w, from)) !== -1) {
                for (var p = at; p < at + w.length; p++) hits[map[p]] = true;
                from = at + w.length;
            }
        });
        var parts = [];
        for (var c = 0; c < chars.length; c++) {
            var last = parts[parts.length - 1];
            if (last && last.hit === hits[c]) last.text += chars[c];
            else parts.push({ text: chars[c], hit: hits[c] });
        }
        return parts;
    }

    return { normalize: normalize, score: score, filter: filter, suggestions: suggestions, highlightParts: highlightParts };
});

(function () {
    'use strict';
    if (typeof document === 'undefined' || typeof window === 'undefined') return;

    var P = window.MiaPalette;
    var data = window.MIA_PALETTE;
    var root = document.getElementById('cmdPalette');
    var backdrop = document.getElementById('cmdPaletteBackdrop');
    var input = document.getElementById('cmdPaletteInput');
    var listEl = document.getElementById('cmdPaletteList');
    if (!P || !data || !root || !input || !listEl) return;

    var entries = Array.isArray(data.entries) ? data.entries : [];
    var text = data.text || {};
    var groups = data.groups || {};
    var LOCAL_LIMIT = 8;
    var CONV_LIMIT = 6;

    var opener = null;
    var items = [];        // résultats affichés, dans l'ordre
    var active = -1;
    var convState = { query: '', status: 'idle', results: [] };
    var seq = 0;
    var timer = null;

    var isMacLike = /mac|iphone|ipad|ipod/i.test(
        (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || ''
    );
    var shortcut = isMacLike ? '⌘K' : 'Ctrl K';
    // Rappel de touche des déclencheurs (bouton de la barre latérale) : le
    // gabarit ne connaît pas la plateforme.
    document.querySelectorAll('[data-palette-kbd]').forEach(function (k) { k.textContent = shortcut; });

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function marked(textValue, query) {
        return P.highlightParts(textValue, query).map(function (part) {
            return part.hit ? '<mark>' + esc(part.text) + '</mark>' : esc(part.text);
        }).join('');
    }

    function iconFor(item) {
        var key = item.kind === 'conversation' ? 'chat' : item.icon;
        return (data.icons && data.icons[key]) || '';
    }

    function isOpen() { return !root.hidden; }

    function open(trigger) {
        if (isOpen()) { input.focus(); input.select(); return; }
        opener = trigger || document.activeElement;
        root.hidden = false;
        if (backdrop) backdrop.hidden = false;
        document.body.classList.add('palette-open');
        document.querySelectorAll('[data-palette-open]').forEach(function (t) { t.setAttribute('aria-expanded', 'true'); });
        input.value = '';
        render();
        input.focus();
    }

    function close() {
        if (!isOpen()) return;
        root.hidden = true;
        if (backdrop) backdrop.hidden = true;
        document.body.classList.remove('palette-open');
        document.querySelectorAll('[data-palette-open]').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
        seq++;
        if (timer) { clearTimeout(timer); timer = null; }
        convState = { query: '', status: 'idle', results: [] };
        if (opener && typeof opener.focus === 'function' && document.contains(opener)) opener.focus();
        opener = null;
    }

    function searchConversations(query) {
        if (timer) { clearTimeout(timer); timer = null; }
        if (P.normalize(query).length < 2) {
            seq++;
            convState = { query: '', status: 'idle', results: [] };
            return;
        }
        if (convState.query === query && convState.status !== 'idle') return;
        convState = { query: query, status: 'loading', results: [] };
        var mySeq = ++seq;
        timer = setTimeout(function () {
            var meta = document.querySelector('meta[name="csrf-token"]');
            fetch('/chat/search?q=' + encodeURIComponent(query), {
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': meta ? meta.content : '' },
                credentials: 'same-origin'
            }).then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            }).then(function (json) {
                if (mySeq !== seq) return; // réponse périmée
                convState = { query: query, status: 'done', results: (json && json.results) || [] };
                render(true);
            }).catch(function () {
                if (mySeq !== seq) return;
                convState = { query: query, status: 'error', results: [] };
                render(true);
            });
        }, 250);
    }

    function render(keepActive) {
        var query = input.value.trim();
        var local = query === '' ? P.suggestions(entries, 12) : P.filter(entries, query, LOCAL_LIMIT);
        if (!keepActive) searchConversations(query);
        var previousHref = keepActive && items[active] ? items[active].href : null;

        items = [];
        var html = '';
        var order = ['work', 'me', 'admin'];
        if (query === '') {
            if (local.length) {
                html += '<div class="cmdp-group" role="presentation">' + esc(text.suggestions || '') + '</div>';
                local.forEach(function (e) { html += option(e, query); });
            }
        } else {
            order.forEach(function (space) {
                var inSpace = local.filter(function (e) { return e.space === space; });
                if (!inSpace.length) return;
                html += '<div class="cmdp-group" role="presentation">' + esc(groups[space] || '') + '</div>';
                inSpace.forEach(function (e) { html += option(e, query); });
            });
        }

        if (convState.query !== '' && convState.query === query) {
            var convHtml = '';
            if (convState.status === 'loading') {
                convHtml = '<div class="cmdp-note" role="presentation">' + esc(text.searching || '') + '</div>';
            } else if (convState.status === 'error') {
                convHtml = '<div class="cmdp-note" role="presentation">' + esc(text.unavailable || '') + '</div>';
            } else if (convState.results.length) {
                convState.results.slice(0, CONV_LIMIT).forEach(function (r) {
                    convHtml += option({
                        kind: 'conversation',
                        space: 'conv',
                        label: r.title || '',
                        path: r.snippet || '',
                        href: '/chat/' + encodeURIComponent(String(r.id)),
                        id: String(r.id),
                        pinned: Number(r.is_pinned) === 1,
                        color: /^#[0-9a-f]{3,8}$/i.test(String(r.profile_color || '')) ? r.profile_color : ''
                    }, query);
                });
            }
            if (convHtml !== '') {
                html += '<div class="cmdp-group" role="presentation">' + esc(groups.conv || '') + '</div>' + convHtml;
            }
        }

        if (items.length === 0 && query !== '' && convState.status !== 'loading') {
            html += '<div class="cmdp-empty" role="presentation">' + esc(text.empty || '') + '</div>';
        }
        listEl.innerHTML = html;

        active = -1;
        if (previousHref) {
            for (var i = 0; i < items.length; i++) {
                if (items[i].href === previousHref) { active = i; break; }
            }
        }
        if (active === -1 && items.length) active = 0;
        paintActive();
    }

    function option(e, query) {
        var index = items.length;
        items.push(e);
        var color = e.color ? ' style="--cmdp-conv-color:' + esc(e.color) + '"' : '';
        var badge = e.kind === 'action' ? '<span class="cmdp-badge">' + esc(text.action || '') + '</span>' : '';
        var pin = e.pinned ? '<span class="cmdp-pin" aria-hidden="true">' + ((data.icons && data.icons.pin) || '') + '</span>' : '';
        return '<a class="cmdp-item cmdp-' + esc(e.space) + '" role="option" id="cmdp-opt-' + index + '" data-index="' + index + '" href="' + esc(e.href) + '" tabindex="-1"' + color + '>'
            + '<span class="cmdp-icon" aria-hidden="true">' + iconFor(e) + '</span>'
            + '<span class="cmdp-text">'
            +   '<span class="cmdp-label">' + pin + '<span class="cmdp-label-text">' + marked(e.label, query) + '</span></span>'
            +   (e.path ? '<span class="cmdp-path">' + (e.kind === 'conversation' ? marked(e.path, query) : esc(e.path)) + '</span>' : '')
            + '</span>'
            + badge
            + '<span class="cmdp-enter" aria-hidden="true">↵</span>'
            + '</a>';
    }

    function paintActive() {
        var nodes = listEl.querySelectorAll('.cmdp-item');
        nodes.forEach(function (n) {
            var on = Number(n.getAttribute('data-index')) === active;
            n.classList.toggle('is-active', on);
            n.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on && typeof n.scrollIntoView === 'function') n.scrollIntoView({ block: 'nearest' });
        });
        if (active >= 0) input.setAttribute('aria-activedescendant', 'cmdp-opt-' + active);
        else input.removeAttribute('aria-activedescendant');
    }

    function go(index, newTab) {
        var item = items[index];
        if (!item) return;
        if (newTab) { window.open(item.href, '_blank', 'noopener'); return; }
        close();
        // Dans le chat : une conversation s'ouvre sans recharger, et
        // « Nouvelle conversation » passe par son bouton (brouillon gardé).
        if (item.kind === 'conversation' && typeof window.switchConversation === 'function' && document.getElementById('convList')) {
            window.switchConversation(item.id);
            if (window.NavDrawer && typeof window.NavDrawer.close === 'function') window.NavDrawer.close();
            return;
        }
        var newChat = document.getElementById('newChatBtn');
        if (item.href === '/' && newChat && newChat.tagName === 'BUTTON') {
            newChat.click();
            return;
        }
        window.location.href = item.href;
    }

    input.addEventListener('input', function () { render(false); });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!items.length) return;
            active = (active + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
            paintActive();
        } else if (e.key === 'Home' && items.length && input.value === '') {
            e.preventDefault(); active = 0; paintActive();
        } else if (e.key === 'End' && items.length && input.value === '') {
            e.preventDefault(); active = items.length - 1; paintActive();
        } else if (e.key === 'Enter') {
            if (e.isComposing) return;
            e.preventDefault();
            if (active >= 0) go(active, e.metaKey || e.ctrlKey);
        }
    });

    listEl.addEventListener('mousemove', function (e) {
        var a = e.target.closest('.cmdp-item');
        if (!a) return;
        var i = Number(a.getAttribute('data-index'));
        if (i !== active) { active = i; paintActive(); }
    });
    listEl.addEventListener('click', function (e) {
        var a = e.target.closest('.cmdp-item');
        if (!a) return;
        // Clic du milieu ou avec ⌘ / Ctrl : le navigateur ouvre l'onglet.
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        go(Number(a.getAttribute('data-index')), false);
    });

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-palette-open]');
        if (trigger) {
            e.preventDefault();
            open(trigger);
            return;
        }
        if (e.target.closest('[data-palette-close]')) close();
    });

    document.addEventListener('keydown', function (e) {
        if (isOpen() && e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close();
            return;
        }
        if (isOpen() && e.key === 'Tab') {
            // Le focus reste dans la palette : le champ, puis le bouton Fermer.
            var focusables = Array.prototype.slice.call(root.querySelectorAll('input, button:not([hidden])'));
            if (!focusables.length) return;
            var first = focusables[0], last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
            else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
            return;
        }
        if (e.altKey || e.shiftKey || !(e.metaKey || e.ctrlKey)) return;
        if (String(e.key || '').toLowerCase() !== 'k') return;
        e.preventDefault();
        if (isOpen()) close(); else open(null);
    }, true);

    // Raccourci public : d'autres scripts ouvrent la palette sans connaître son balisage.
    window.MiaPalette.open = function () { open(null); };
    window.MiaPalette.close = close;
})();
