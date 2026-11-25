/**
 * Fenêtre « Quoi de neuf » — nouveautés MAJEURES (src/Changelog/MajorFeatures.php).
 *
 * Le gabarit (templates/partials/feature-news-modal.php) rend la fenêtre
 * CACHÉE, déjà traduite ; ce script ne porte aucun texte. Il :
 *   - l'ouvre une fois la page posée (OPEN_DELAY_MS), jamais par-dessus une
 *     autre fenêtre ouverte ni pendant une conversation vocale ;
 *   - dit AUSSITÔT au serveur qu'elle a été vue (POST /news/seen) : attendre
 *     la fermeture la rouvrirait chez qui recharge la page sans la fermer ;
 *   - la referme par « J'ai compris », ×, Échap ou un clic sur le voile, en
 *     rendant le focus là où il était ; Tab reste dans la fenêtre.
 * « Aller voir » est un lien ordinaire : la page visée éteint la pastille.
 */
(function () {
    'use strict';

    var OPEN_DELAY_MS = 700;
    var modal = document.getElementById('featureNews');
    if (!modal) return;
    var dialog = modal.querySelector('.fn-dialog');
    var lastFocus = null;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function ids() {
        try {
            var v = JSON.parse(modal.getAttribute('data-ids') || '[]');
            return Array.isArray(v) ? v : [];
        } catch (e) {
            return [];
        }
    }

    function markSeen() {
        var list = ids();
        if (!list.length) return;
        var meta = document.querySelector('meta[name="csrf-token"]');
        var body = list.map(function (id) { return 'ids[]=' + encodeURIComponent(id); }).join('&');
        try {
            fetch('/news/seen', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type':     'application/x-www-form-urlencoded',
                    'X-CSRF-Token':     meta ? meta.content : '',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept':           'application/json'
                },
                body: body
            }).catch(function () { /* la fenêtre reviendra au prochain passage */ });
        } catch (e) { /* idem */ }
    }

    function otherDialogOpen() {
        if (document.body.classList.contains('voice-chat-on')) return true;
        var open = document.querySelectorAll('.modal, [role="dialog"]');
        for (var i = 0; i < open.length; i++) {
            var el = open[i];
            if (el === modal || modal.contains(el) || el.hidden) continue;
            // getClientRects() est vide pour un élément (ou un parent) en
            // display:none ; offsetParent ne servirait pas : une fenêtre en
            // position fixe n'en a jamais.
            var st = window.getComputedStyle(el);
            if (st.display !== 'none' && st.visibility !== 'hidden' && el.getClientRects().length > 0) return true;
        }
        return false;
    }

    function focusables() {
        return Array.prototype.filter.call(
            dialog.querySelectorAll('a[href], button:not([disabled])'),
            function (el) { return el.offsetParent !== null; }
        );
    }

    function open() {
        if (otherDialogOpen()) {
            // Une autre fenêtre est ouverte : on réessaie plus tard, sans rien
            // marquer — elle n'a pas encore été vue.
            setTimeout(open, 2000);
            return;
        }
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('fn-open');
        // Reflow, puis la classe : la transition part de l'état fermé.
        void modal.offsetWidth;
        modal.classList.add('is-open');
        var ok = dialog.querySelector('.fn-ok');
        (ok || dialog).focus({ preventScroll: true });
        markSeen();
    }

    function close() {
        if (modal.hidden) return;
        modal.classList.remove('is-open');
        document.body.classList.remove('fn-open');
        var done = function () {
            modal.hidden = true;
            if (lastFocus && typeof lastFocus.focus === 'function' && document.contains(lastFocus)) {
                lastFocus.focus({ preventScroll: true });
            }
        };
        if (reduce) {
            done();
        } else {
            setTimeout(done, 200);
        }
    }

    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.closest('[data-fn-close]')) {
            e.preventDefault();
            close();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (modal.hidden) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close();
            return;
        }
        if (e.key === 'Tab') {
            var list = focusables();
            if (!list.length) return;
            var first = list[0];
            var last = list[list.length - 1];
            if (e.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }, true);

    function start() {
        setTimeout(open, OPEN_DELAY_MS);
    }
    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start, { once: true });
    }

    window.MiaFeatureNews = { open: open, close: close };
})();
