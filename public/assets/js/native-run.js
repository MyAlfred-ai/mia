/**
 * Carte de réalisation d'une « Version soignée » (NativeDeckRun) : anime ce
 * que le serveur a rendu (templates/partials/native-run-card.php).
 *
 * Aucun texte ici : temps écoulé, étapes, messages d'arrêt et d'échec sont
 * posés par le serveur en attributs data-* de la carte. Le serveur donne le
 * temps déjà écoulé (`data-elapsed`) : l'horloge du navigateur ne sert qu'à
 * compter la suite, jamais à dater le début.
 *
 * API (window.MiaNativeRun), appelée par chat.js :
 *   hydrate(scope)            — démarre les cartes « en cours » présentes ;
 *   replace(msgId, html, o)   — remplace la carte par son nouvel état (prête,
 *                               arrêtée) ; o.justDone joue l'arrivée ;
 *   fail(jobId)               — la réalisation a échoué en direct ;
 *   reveal(el)                — ramène à l'écran la carte d'un message.
 */
(function (root) {
    'use strict';

    const cards = new Set();
    let timer = null;

    function reducedMotion() {
        return !!(root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    /**
     * « 42 s », « 1 min 05 s » — mêmes formes que NativeDeckRun::duration(),
     * espaces insécables compris (une durée ne se coupe pas en fin de ligne).
     */
    function formatElapsed(card, seconds) {
        const s = Math.max(0, Math.floor(seconds));
        const text = s < 60
            ? String(card.dataset.fmtSec || '{s} s').replace('{s}', String(s))
            : String(card.dataset.fmtMin || '{m} min {s} s')
                .replace('{m}', String(Math.floor(s / 60)))
                .replace('{s}', String(s % 60).padStart(2, '0'));
        return text.replace(/ /g, '\u00a0');
    }

    /** Même estimation que NativeDeckRun::estimate() (constantes posées par le serveur). */
    function estimate(card, seconds) {
        const cap = Number(card.dataset.cap) || 92;
        const tau = Number(card.dataset.tau) || 75;
        return Math.round(cap * (1 - Math.exp(-Math.max(0, seconds) / tau)));
    }

    function phaseAt(card, seconds) {
        const slowAfter = Number(card.dataset.slowAfter) || 0;
        if (slowAfter > 0 && seconds >= slowAfter && card.dataset.slowText) return card.dataset.slowText;
        let text = '';
        (card.__phases || []).forEach(function (phase) {
            if (Array.isArray(phase) && seconds >= Number(phase[0])) text = String(phase[1] || '');
        });
        return text;
    }

    function setPhase(card, text) {
        const el = card.querySelector('[data-run-phase]');
        if (!el || !text || el.textContent === text) return;
        if (reducedMotion()) { el.textContent = text; return; }
        el.classList.add('is-changing');
        setTimeout(function () {
            el.textContent = text;
            el.classList.remove('is-changing');
        }, 250);
    }

    function tick() {
        const now = Date.now();
        cards.forEach(function (card) {
            if (!card.isConnected || card.dataset.status !== 'running') {
                cards.delete(card);
                return;
            }
            const seconds = card.__base + (now - card.__t0) / 1000;
            const elapsed = card.querySelector('[data-run-elapsed]');
            if (elapsed) elapsed.textContent = formatElapsed(card, seconds);
            card.style.setProperty('--run-progress', estimate(card, seconds) + '%');
            if (!card.__stopping) setPhase(card, phaseAt(card, seconds));
        });
        if (cards.size === 0 && timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function hydrate(scope) {
        const base = scope && scope.querySelectorAll ? scope : document;
        base.querySelectorAll('.native-run[data-status="running"]').forEach(function (card) {
            if (card.__hydrated) return;
            card.__hydrated = true;
            card.__base = Number(card.dataset.elapsed) || 0;
            card.__t0 = Date.now();
            try { card.__phases = JSON.parse(card.dataset.phases || '[]'); } catch (_e) { card.__phases = []; }
            cards.add(card);
        });
        if (cards.size && !timer) {
            timer = setInterval(tick, 1000);
            tick();
        }
    }

    function findByMessage(msgId) {
        const id = Number(msgId);
        return id > 0 ? document.querySelector('#chatMessages .native-run[data-message-id="' + id + '"]') : null;
    }

    function findByJob(jobId) {
        const id = Number(jobId);
        return id > 0 ? document.querySelector('#chatMessages .native-run[data-job-id="' + id + '"]') : null;
    }

    /** Nouvel état de la carte d'un message. Faux si la carte n'est pas affichée. */
    function replace(msgId, html, opts) {
        const card = findByMessage(msgId);
        if (!card || !html) return false;
        const holder = document.createElement('div');
        holder.innerHTML = String(html).trim();
        const next = holder.firstElementChild;
        if (!next || !next.classList.contains('native-run')) return false;
        const justDone = !!(opts && opts.justDone);
        const swap = function () {
            cards.delete(card);
            if (justDone) next.classList.add('is-just-done');
            if (card.isConnected) card.replaceWith(next);
            hydrate(next.parentNode);
        };
        // La barre finit sa course avant que la carte devienne « prête ».
        if (justDone && card.dataset.status === 'running' && !reducedMotion()) {
            cards.delete(card);
            card.classList.add('is-finishing');
            card.style.setProperty('--run-progress', '100%');
            setTimeout(swap, 480);
        } else {
            swap();
        }
        return true;
    }

    /** Échec en direct : la carte cesse de tourner (le détail suit en message). */
    function fail(jobId) {
        const card = findByJob(jobId);
        if (!card || card.dataset.status !== 'running') return false;
        cards.delete(card);
        card.dataset.status = 'failed';
        card.classList.remove('is-running', 'is-stopping');
        card.classList.add('is-failed');
        const state = card.querySelector('.native-run-state');
        if (state && card.dataset.failedState) state.textContent = card.dataset.failedState;
        const by = card.querySelector('[data-run-by]');
        if (by && card.dataset.failedBy) by.textContent = card.dataset.failedBy;
        card.querySelectorAll('.native-run-track, .native-run-foot, .native-run-actions').forEach(function (el) { el.remove(); });
        return true;
    }

    /** Ramène à l'écran la carte du message `el` (élément du message ou de son contenu). */
    function reveal(el) {
        const message = el && el.closest ? el.closest('.message') : null;
        const card = message ? message.querySelector('.native-run') : null;
        if (!card) return;
        card.classList.add('is-arriving');
        try {
            card.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
        } catch (_e) {
            card.scrollIntoView(false);
        }
    }

    function csrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    // « Arrêter » : la même route que le tiroir des générations. Le job rend
    // la carte « arrêtée » (son nouvel état arrive par le flux du job).
    document.addEventListener('click', function (event) {
        const target = event.target;
        const button = target && target.closest ? target.closest('.native-run-stop') : null;
        if (!button || button.disabled) return;
        const card = button.closest('.native-run');
        const jobId = card ? Number(card.dataset.jobId) : 0;
        if (!card || jobId <= 0 || card.dataset.status !== 'running') return;
        if (card.dataset.confirm && !root.confirm(card.dataset.confirm)) return;
        button.disabled = true;
        card.__stopping = true;
        card.classList.add('is-stopping');
        const phase = card.querySelector('[data-run-phase]');
        if (phase && card.dataset.stopping) phase.textContent = card.dataset.stopping;
        fetch('/jobs/' + jobId + '/cancel', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (res) {
            if (!res.ok) throw new Error('cancel');
            return res.json().catch(function () { return {}; });
        }).then(function (data) {
            // Refus (job déjà fini, ou d'un autre compte) : la carte reprend.
            // Fini, son nouvel état arrive de toute façon par le flux du job.
            if (data && data.cancelled === false) throw new Error('cancel');
        }).catch(function () {
            button.disabled = false;
            card.__stopping = false;
            card.classList.remove('is-stopping');
        });
    });

    root.MiaNativeRun = { hydrate: hydrate, replace: replace, fail: fail, reveal: reveal };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { hydrate(document); }, { once: true });
    } else {
        hydrate(document);
    }
})(window);
