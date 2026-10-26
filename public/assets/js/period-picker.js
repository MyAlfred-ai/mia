/**
 * Sélecteur de période partagé (templates/partials/period-picker.php) —
 * Coûts → Budget et Coûts → Consommation.
 *
 * Le sélecteur marche sans ce script (liens et formulaire GET). Ici :
 *  - le panneau « Personnalisée » se referme par Échap ou un clic ailleurs ;
 *  - les dates sont contrôlées avant l'envoi (les deux, dans l'ordre, pas
 *    après aujourd'hui) avec un message dans le panneau, jamais une alerte ;
 *  - quand le sélecteur porte `data-period-swap`, la région désignée est
 *    REMPLACÉE par celle de la page demandée (`?fragment=period`), sans
 *    recharger ni perdre la position de lecture ; l'adresse suit
 *    (history.pushState) et le bouton Retour rejoue la période d'avant.
 *    En cas d'échec (réseau, session expirée), on navigue normalement.
 *
 * Aucun libellé ici, hormis les deux messages d'erreur des dates, traduits
 * par window.t (dictionnaire client).
 */
(function () {
    'use strict';

    var finePointer = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;

    function closeCustom(except) {
        document.querySelectorAll('.period-custom[open]').forEach(function (d) {
            if (d !== except) d.open = false;
        });
    }

    // Rangée qui défile (téléphone) : fondu du côté où il reste des tranches.
    function markOverflow(nav) {
        var max = nav.scrollWidth - nav.clientWidth;
        nav.classList.toggle('has-more-start', max > 1 && nav.scrollLeft > 1);
        nav.classList.toggle('has-more-end', max > 1 && nav.scrollLeft < max - 1);
    }

    // Ramène le segment actif dans la vue quand la rangée défile.
    function revealActive(root) {
        (root || document).querySelectorAll('.period-presets').forEach(function (nav) {
            var active = nav.querySelector('.period-preset.is-active');
            if (active && nav.scrollWidth > nav.clientWidth) {
                nav.scrollLeft = Math.max(0, active.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2);
            }
            markOverflow(nav);
            if (!nav.dataset.overflowWatch) {
                nav.dataset.overflowWatch = '1';
                nav.addEventListener('scroll', function () { markOverflow(nav); }, { passive: true });
            }
        });
    }
    window.addEventListener('resize', function () {
        document.querySelectorAll('.period-presets').forEach(markOverflow);
    });

    // ---- Panneau « Personnalisée » ------------------------------------------
    document.addEventListener('toggle', function (e) {
        var d = e.target;
        if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('period-custom') || !d.open) return;
        closeCustom(d);
        // Le panneau s'ouvre vers la droite ; près du bord, il s'aligne à droite.
        d.classList.remove('is-right');
        var panel = d.querySelector('.period-custom-panel');
        if (panel && panel.getBoundingClientRect().right > document.documentElement.clientWidth - 8) {
            d.classList.add('is-right');
        }
        var first = d.querySelector('input[type="date"]');
        if (first && finePointer) first.focus({ preventScroll: true });
    }, true);

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.period-custom')) closeCustom(null);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.period-custom[open]');
        if (!open) return;
        open.open = false;
        var summary = open.querySelector('summary');
        if (summary) summary.focus();
    });

    function showError(form, message) {
        var box = form.querySelector('.period-custom-error');
        if (!box) return;
        box.textContent = message || '';
        box.hidden = !message;
    }

    /** Dates du panneau, contrôlées ; null (et message) si elles ne vont pas. */
    function readRange(form) {
        var from = form.elements.from, to = form.elements.to;
        var max  = from.getAttribute('max') || '';
        var a = from.value, b = to.value;
        if (!a && !b) { showError(form, t('Choisissez au moins une date.')); return null; }
        if (!a) a = b;
        if (!b) b = a;
        if (a > b) { var swap = a; a = b; b = swap; }
        if (max && a > max) { showError(form, t('Choisissez une date passée : aucune dépense n\'existe encore après aujourd\'hui.')); return null; }
        if (max && b > max) b = max;
        from.value = a; to.value = b;
        showError(form, '');
        return { from: a, to: b };
    }

    // ---- Remplacement sans rechargement ---------------------------------------
    var currentSearch = location.search;

    function swapTarget(picker) {
        var sel = picker && picker.getAttribute('data-period-swap');
        return sel ? { sel: sel, el: document.querySelector(sel) } : null;
    }

    function load(url, picker, push, focusKey) {
        var target = swapTarget(picker);
        if (!target || !target.el || !window.fetch) { location.href = url; return; }
        var dest = new URL(url, location.href);
        var fetchUrl = new URL(dest.href);
        fetchUrl.hash = '';
        fetchUrl.searchParams.set('fragment', 'period');

        target.el.classList.add('is-loading');
        target.el.setAttribute('aria-busy', 'true');
        fetch(fetchUrl.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                var type = r.headers.get('content-type') || '';
                if (!r.ok || r.redirected || type.indexOf('text/html') === -1) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var tpl = document.createElement('template');
                tpl.innerHTML = html.trim();
                var fresh = tpl.content.querySelector(target.sel);
                if (!fresh) throw new Error('fragment absent');
                target.el.replaceWith(fresh);
                currentSearch = dest.search;
                if (push) history.pushState({ miaPeriod: true }, '', dest.pathname + dest.search + dest.hash);
                revealActive(fresh);
                if (focusKey) {
                    var again = fresh.querySelector(focusKey === 'custom'
                        ? '.period-custom > summary'
                        : '.period-preset[data-period="' + focusKey + '"]');
                    if (again) again.focus({ preventScroll: true });
                }
                fresh.dispatchEvent(new CustomEvent('mia:period-changed', { bubbles: true }));
            })
            .catch(function () { location.href = dest.href; });
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a.period-preset[data-period]');
        if (!link) return;
        var picker = link.closest('[data-period-picker]');
        if (!picker || !picker.hasAttribute('data-period-swap')) return;
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        // Un clic au clavier (Entrée) garde le focus sur le segment choisi.
        load(link.href, picker, true, e.detail === 0 ? link.getAttribute('data-period') : null);
    });

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.period-custom-panel');
        if (!form) return;
        var range = readRange(form);
        if (!range) { e.preventDefault(); return; }
        var picker = form.closest('[data-period-picker]');
        if (!picker || !picker.hasAttribute('data-period-swap') || !window.URL) return;
        e.preventDefault();
        var url = new URL(form.getAttribute('action') || location.pathname, location.href);
        new FormData(form).forEach(function (value, name) { url.searchParams.set(name, value); });
        url.searchParams.delete('preset');
        form.closest('.period-custom').open = false;
        load(url.href, picker, true, 'custom');
    });

    // Retour / Avancer : seulement quand la période a changé (un lien vers une
    // ancre de la page, #budget-settings par exemple, déclenche aussi popstate).
    window.addEventListener('popstate', function () {
        if (location.search === currentSearch) return;
        var picker = document.querySelector('[data-period-picker][data-period-swap]');
        if (!picker) { location.reload(); return; }
        load(location.href, picker, false, null);
    });

    revealActive(document);
})();
