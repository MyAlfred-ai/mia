/**
 * Réglages → Usage & crédits, onglet « Historique » (templates/settings/usage-history.php).
 *
 * La page marche sans ce script : liens et formulaires GET. Ici :
 *  - un filtre changé (sélecteur, recherche tapée, puce d'origine, type,
 *    filtre retiré) REMPLACE la seule région #puHistory par celle de la page
 *    demandée (`?fragment=history`), sans recharger ni perdre la position ;
 *    l'adresse suit (history.replaceState : un filtre n'empile pas d'entrée
 *    dans l'historique du navigateur) et le focus revient sur le même
 *    contrôle — la recherche garde son curseur ;
 *  - « Afficher plus » ajoute la page suivante du journal
 *    (`?fragment=rows&apres=N`), en recousant le jour coupé entre deux pages
 *    et sans doublon (une opération arrivée entre-temps décale les pages) ;
 *  - une réponse périmée (filtre changé entre-temps) est abandonnée.
 * En cas d'échec (réseau, session expirée), on navigue normalement.
 *
 * Le sélecteur de période (period-picker.js) remplace la même région, avec
 * `?fragment=period` : les deux se partagent #puHistory.
 *
 * Aucun libellé ici : tout le texte vient du serveur.
 */
(function () {
    'use strict';

    var REGION = '#puHistory';
    var SEARCH_DELAY = 450;
    var seq = 0;
    var controller = null;
    var searchTimer = null;
    var selectsOpen = null; // téléphone : sélecteurs dépliés par la personne (gardé d'un remplacement à l'autre)

    document.documentElement.classList.add('hx-js');

    function region() { return document.querySelector(REGION); }

    function fragmentUrl(url, kind) {
        var u = new URL(url, location.href);
        u.hash = '';
        u.searchParams.delete('fragment');
        u.searchParams.delete('apres');
        u.searchParams.set('fragment', kind);
        return u;
    }

    function pageUrl(url) {
        var u = new URL(url, location.href);
        u.searchParams.delete('fragment');
        u.searchParams.delete('apres');
        u.hash = '';
        return u;
    }

    function fetchHtml(url, signal) {
        return fetch(url.href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: signal
        }).then(function (r) {
            var type = r.headers.get('content-type') || '';
            if (!r.ok || r.redirected || type.indexOf('text/html') === -1) throw new Error('HTTP ' + r.status);
            return r.text();
        });
    }

    /** Ce qui avait le focus, pour le rendre au même contrôle après le remplacement. */
    function focusMemo() {
        var el = document.activeElement;
        var root = region();
        if (!el || !root || !root.contains(el)) return null;
        if (el.matches('[data-history-search]')) {
            return { sel: '[data-history-search]', start: el.selectionStart, end: el.selectionEnd };
        }
        if (el.matches('select[name]')) return { sel: 'select[name="' + el.name + '"]' };
        if (el.hasAttribute('data-focus')) return { sel: '[data-focus="' + el.getAttribute('data-focus') + '"]' };
        return { sel: '[data-focus="o:"]' };
    }

    function restoreFocus(memo, root) {
        if (!memo) return;
        var el = root.querySelector(memo.sel) || root.querySelector('[data-focus="o:"]');
        if (!el) return;
        el.focus({ preventScroll: true });
        if (memo.start != null && typeof el.setSelectionRange === 'function') {
            try { el.setSelectionRange(memo.start, memo.end); } catch (e) { /* type search sans sélection */ }
        }
    }

    function load(url) {
        var root = region();
        if (!root || !window.fetch || !window.URL) { location.href = url; return; }
        var my = ++seq;
        if (controller) controller.abort();
        controller = window.AbortController ? new AbortController() : null;
        var memo = focusMemo();
        root.classList.add('is-loading');
        root.setAttribute('aria-busy', 'true');

        fetchHtml(fragmentUrl(url, 'history'), controller ? controller.signal : undefined)
            .then(function (html) {
                if (my !== seq) return;
                var tpl = document.createElement('template');
                tpl.innerHTML = html.trim();
                var fresh = tpl.content.querySelector(REGION);
                if (!fresh) throw new Error('fragment absent');
                root.replaceWith(fresh);
                if (selectsOpen) setSelects(fresh, true);
                var dest = pageUrl(url);
                history.replaceState(history.state, '', dest.pathname + dest.search);
                restoreFocus(memo, fresh);
                fresh.dispatchEvent(new CustomEvent('mia:history-changed', { bubbles: true }));
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (my === seq) location.href = pageUrl(url).href;
            });
    }

    function formUrl(form) {
        var u = new URL(form.getAttribute('action') || location.pathname, location.href);
        u.search = '';
        new FormData(form).forEach(function (value, name) {
            var v = String(value).trim();
            if (v !== '') u.searchParams.set(name, v);
        });
        return u.href;
    }

    // ---- Téléphone : sélecteurs repliés derrière « Filtres » ------------------------
    function setSelects(root, open) {
        var toggle = root.querySelector('[data-history-toggle]');
        var box = root.querySelector('#hxSelects');
        if (!toggle || !box) return;
        box.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-history-toggle]');
        if (!toggle) return;
        var root = region();
        if (!root) return;
        selectsOpen = toggle.getAttribute('aria-expanded') !== 'true';
        setSelects(root, selectsOpen);
        if (selectsOpen) {
            var first = root.querySelector('#hxSelects select');
            if (first) first.focus({ preventScroll: true });
        }
    });

    // ---- Filtres -----------------------------------------------------------------
    document.addEventListener('change', function (e) {
        var select = e.target.closest('[data-history-filters] [data-history-select]');
        if (!select) return;
        load(formUrl(select.form));
    });

    document.addEventListener('input', function (e) {
        var input = e.target.closest('[data-history-filters] [data-history-search]');
        if (!input) return;
        clearTimeout(searchTimer);
        var value = input.value.trim();
        // Un seul caractère ne cherche rien (le serveur l'ignore) : on attend la suite.
        if (value.length === 1) return;
        var form = input.form;
        searchTimer = setTimeout(function () { load(formUrl(form)); }, SEARCH_DELAY);
    });

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-history-filters]');
        if (!form) return;
        e.preventDefault();
        clearTimeout(searchTimer);
        load(formUrl(form));
    });

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-history-link]');
        if (!link || !link.closest(REGION)) return;
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        clearTimeout(searchTimer);
        if (!link.hasAttribute('data-focus') && link.closest('.hx-active')) {
            // Le filtre retiré disparaît : le focus va à la recherche.
            var search = region() && region().querySelector('[data-history-search]');
            if (search) search.focus({ preventScroll: true });
        }
        load(link.href);
    });

    // ---- « Afficher plus » ---------------------------------------------------------
    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-history-more] a[data-fragment]');
        if (!link) return;
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var days = link.closest('[data-history-days]');
        if (!days || !window.fetch) return;
        e.preventDefault();
        var wrap = link.closest('[data-history-more]');
        var my = seq;
        wrap.classList.add('is-loading');
        link.setAttribute('aria-busy', 'true');

        fetchHtml(new URL(link.getAttribute('data-fragment'), location.href))
            .then(function (html) {
                if (my !== seq || !document.contains(days)) return;
                var tpl = document.createElement('template');
                tpl.innerHTML = html.trim();
                var seen = {};
                days.querySelectorAll('.hx-op[data-id]').forEach(function (op) { seen[op.getAttribute('data-id')] = true; });
                var firstNew = null;
                tpl.content.querySelectorAll('.hx-op[data-id]').forEach(function (op) {
                    if (seen[op.getAttribute('data-id')]) op.remove();
                });
                tpl.content.querySelectorAll('.hx-day').forEach(function (day) {
                    var ops = day.querySelectorAll('.hx-op');
                    if (!ops.length) return;
                    var existing = days.querySelector('.hx-day[data-day="' + day.getAttribute('data-day') + '"]');
                    if (existing) {
                        var list = existing.querySelector('.hx-ops');
                        ops.forEach(function (op) { list.appendChild(op); if (!firstNew) firstNew = op; });
                    } else {
                        days.insertBefore(day, wrap);
                        if (!firstNew) firstNew = ops[0];
                    }
                });
                var next = tpl.content.querySelector('[data-history-more]');
                if (next) wrap.replaceWith(next); else wrap.remove();
                // Le clavier reprend à la première opération ajoutée.
                if (firstNew && e.detail === 0) {
                    var target = firstNew.querySelector('a, summary') || firstNew;
                    if (target === firstNew) firstNew.setAttribute('tabindex', '-1');
                    target.focus({ preventScroll: false });
                }
            })
            .catch(function () { location.href = link.href; });
    });
})();
