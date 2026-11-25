/**
 * Volet « Avancement » d'une exécution de workflow, sur le côté de SA
 * conversation — la discussion d'abord (retour du 2026-11-04).
 *
 * On lance un workflow, on se retrouve dans sa conversation : ce volet montre
 * les étapes et leur état, rappelle ce qui vous attend (validation, cartes,
 * reprise) et le fait faire SANS quitter la discussion. Le HTML vient du
 * serveur (GET /workflows/runs/{id}/progress, traduit) : ce script n'a aucun
 * texte à lui. Il sonde tant que l'exécution n'est pas finie — vite quand elle
 * travaille, lentement quand elle vous attend, jamais dans un onglet masqué —
 * et, quand elle bouge, demande au chat d'ajouter ce que le moteur a écrit
 * (window.miaSyncConversation).
 *
 * Ordinateur large : volet à droite, la conversation se décale. Écran étroit :
 * un bouton « Avancement » en haut du fil ouvre le volet par-dessus.
 */
(function () {
    'use strict';

    var panel = document.getElementById('wfProgress');
    var toggle = document.getElementById('wfProgressToggle');
    var main = document.querySelector('.chat-main');
    if (!panel || !toggle || !main) { return; }

    var WIDE = window.matchMedia('(min-width: 1100px)');
    var runId = 0;
    var timer = null;
    var seq = 0;
    var lastSig = '';
    var lastHtml = '';
    var busy = false;
    // Masqué à la main (ordinateur) : retenu pour la page, par exécution.
    var dismissed = {};
    var openNarrow = false;

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function shownConvId() {
        var el = document.getElementById('convId');
        return el ? String(el.value || '') : '';
    }

    function setVisible() {
        var has = runId > 0 && panel.innerHTML !== '';
        var wide = WIDE.matches;
        var open = has && (wide ? !dismissed[runId] : openNarrow);
        panel.hidden = !open;
        toggle.hidden = !has || open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.classList.toggle('has-wf-progress', open && wide);
        document.body.classList.toggle('wfp-sheet-open', open && !wide);
    }

    function stop() {
        if (timer) { clearTimeout(timer); timer = null; }
    }

    function schedule(ms) {
        stop();
        if (ms > 0) { timer = setTimeout(load, ms); }
    }

    function load() {
        stop();
        if (runId <= 0) { return; }
        if (document.hidden) { return; } // repris au retour sur l'onglet
        var mine = ++seq;
        var id = runId;
        fetch('/workflows/runs/' + id + '/progress', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (mine !== seq || id !== runId) { return; }
                if (!j || !j.ok) { schedule(15000); return; }
                // La conversation affichée n'est plus celle de l'exécution.
                if (String(j.conversation_id) !== shownConvId()) { detach(); return; }
                // Rien n'a changé : on ne repose pas le volet (le focus clavier,
                // un message d'erreur affiché restent en place).
                if (j.html !== lastHtml) { panel.innerHTML = j.html; lastHtml = j.html; }
                setVisible();
                var stateEl = toggle.querySelector('[data-wfp-toggle-state]');
                var pill = panel.querySelector('.wfp-status .wfp-pill');
                if (stateEl) { stateEl.textContent = pill ? pill.textContent : ''; }
                if (lastSig !== '' && j.signature !== lastSig && window.miaSyncConversation) {
                    window.miaSyncConversation();
                }
                lastSig = j.signature;
                schedule(j.terminal ? 0 : (j.poll || 4000));
            })
            .catch(function () { if (mine === seq) { schedule(15000); } });
    }

    function attach(id) {
        id = Number(id) || 0;
        if (id === runId) { if (id > 0) { load(); } return; }
        runId = id;
        lastSig = '';
        lastHtml = '';
        panel.innerHTML = '';
        openNarrow = false;
        setVisible();
        if (runId > 0) { load(); } else { stop(); }
    }

    function detach() {
        runId = 0;
        lastSig = '';
        lastHtml = '';
        panel.innerHTML = '';
        stop();
        setVisible();
    }

    function showError(msg) {
        var el = panel.querySelector('.wfp-err');
        if (el) { el.textContent = msg || ''; el.hidden = !msg; }
    }

    function post(path, body) {
        if (busy) { return; }
        busy = true;
        var fd = new FormData();
        Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
        fetch('/workflows/runs/' + runId + '/' + path, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (j) {
                busy = false;
                if (!j.ok) { showError(j.error || ''); return; }
                if (window.miaSyncConversation) { window.miaSyncConversation(); }
                lastSig = '';
                load();
            })
            .catch(function () { busy = false; load(); });
    }

    function scrollToCard(actionId) {
        var sel = actionId ? '.drive-confirm-card[data-action-id="' + String(Number(actionId)) + '"]' : '.drive-confirm-card:not(.is-done):not(.is-cancelled)';
        var card = document.querySelector(sel);
        if (!card) { return; }
        if (!WIDE.matches) { openNarrow = false; setVisible(); }
        card.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
        card.classList.add('is-spotlit');
        setTimeout(function () { card.classList.remove('is-spotlit'); }, 1800);
    }

    panel.addEventListener('click', function (e) {
        var b = e.target.closest('[data-wfp-act]');
        if (!b) { return; }
        var act = b.getAttribute('data-wfp-act');
        if (act === 'close') {
            if (WIDE.matches) { dismissed[runId] = true; } else { openNarrow = false; }
            setVisible();
            toggle.focus();
            return;
        }
        if (act === 'scroll') { scrollToCard(b.getAttribute('data-wfp-card')); return; }
        if (act === 'retry') { post('retry', {}); return; }
        if (act === 'retry-latest') { post('retry', { latest: '1' }); return; }
        if (act === 'resume') { post('resume', {}); return; }
        if (act === 'cancel') {
            var c = b.getAttribute('data-wfp-confirm');
            if (c && !window.confirm(c)) { return; }
            post('cancel', {});
        }
    });

    toggle.addEventListener('click', function () {
        if (WIDE.matches) { dismissed[runId] = false; } else { openNarrow = true; }
        setVisible();
        var first = panel.querySelector('button, a');
        if (first) { first.focus(); }
    });

    // Écran étroit : toucher à côté du volet le referme.
    document.addEventListener('click', function (e) {
        if (!openNarrow || WIDE.matches) { return; }
        if (panel.contains(e.target) || toggle.contains(e.target)) { return; }
        openNarrow = false;
        setVisible();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openNarrow && !WIDE.matches) { openNarrow = false; setVisible(); toggle.focus(); }
    });

    var onWide = function () { setVisible(); };
    if (WIDE.addEventListener) { WIDE.addEventListener('change', onWide); } else if (WIDE.addListener) { WIDE.addListener(onWide); }

    window.addEventListener('mia:conversation-shown', function (e) {
        attach((e.detail || {}).workflowRunId || 0);
    });
    // Une carte tranchée (validation, action) fait repartir le workflow.
    window.addEventListener('mia:card-decided', function () {
        if (runId > 0) { setTimeout(load, 600); }
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && runId > 0) { load(); }
    });

    attach(main.getAttribute('data-workflow-run') || 0);

    window.MiaWorkflowProgress = { attach: attach, refresh: load };
})();
