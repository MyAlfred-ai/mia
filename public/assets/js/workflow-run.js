/**
 * Une exécution de workflow, en direct (/workflows/runs/{id}).
 *
 * En tête, ce qui vous attend (validation, doute d'une condition, cartes
 * d'actions, cause d'un arrêt, livraison) ; au centre le rail de la version
 * exécutée, avec l'état de chaque brique. Un clic sur une brique ouvre son
 * détail dans un VOLET ; « Dossier » ouvre les pièces. L'état est sondé
 * (GET …/state) tant que l'exécution n'est pas finie : vite quand une étape
 * tourne, lentement quand elle vous attend, jamais dans un onglet masqué.
 *
 * Données : window.WF_RUN = { run, definition, state, catalog, focus_action }.
 */
(function () {
    'use strict';

    var D = window.WF_RUN;
    var R = window.MiaWorkflowRail;
    if (!D || !R) { return; }
    var cat = D.catalog;
    var L = cat.labels || {};
    var T = function (s, v) { return window.t ? window.t(s, v) : s; };
    var esc = R.esc;
    var $ = function (sel, el) { return (el || document).querySelector(sel); };

    var state = D.state;
    var def = D.definition;
    var open = {};
    var editing = null;
    var highlight = null;
    var pollTimer = null;
    var busy = false;
    var panel = null; // volet ouvert : 'dossier', la clé d'une brique, ou null

    var railHost = $('#wfRunRail');
    var banner = $('#wfRunBanner');
    var drawer = $('#wfRunDrawer');
    var scrim = $('#wfScrim');
    var dossierBtn = $('#wfRunDossier');
    var dossierCount = $('#wfRunDossierCount');
    var statusEl = $('#wfRunStatus');
    var creditsEl = $('#wfRunCredits');
    var cancelBtn = $('#wfRunCancel');

    function icon(n) { return (cat.icons || {})[n] || ''; }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function pieces() { var m = {}; R.pieces(def, cat.colors).forEach(function (p) { m[p.key] = p; }); return m; }

    /** Markdown d'une pièce → HTML sûr (aucune balise active, aucune ressource d'un autre site). */
    function md(text) {
        var raw = window.marked ? window.marked.parse(String(text || ''), { breaks: true }) : esc(text).replace(/\n/g, '<br>');
        var tpl = document.createElement('template');
        tpl.innerHTML = raw;
        tpl.content.querySelectorAll('script,style,iframe,frame,frameset,object,embed,link,meta,base,form,input,button,video,audio,source,svg').forEach(function (el) { el.remove(); });
        tpl.content.querySelectorAll('*').forEach(function (el) {
            Array.prototype.slice.call(el.attributes).forEach(function (a) {
                var n = a.name.toLowerCase();
                var v = String(a.value || '').trim();
                if (n.indexOf('on') === 0 || n === 'style' || n === 'srcdoc') { el.removeAttribute(a.name); return; }
                if ((n === 'href' || n === 'src') && /^\s*(javascript|data|vbscript):/i.test(v)) { el.removeAttribute(a.name); }
            });
            if (el.tagName === 'IMG') {
                var src = el.getAttribute('src') || '';
                if (!/^\/(?!\/)/.test(src)) {
                    var a = document.createElement('a');
                    a.href = src;
                    a.textContent = el.getAttribute('alt') || src;
                    a.rel = 'noopener noreferrer';
                    a.target = '_blank';
                    el.replaceWith(a);
                }
            }
            if (el.tagName === 'A') { el.setAttribute('target', '_blank'); el.setAttribute('rel', 'noopener noreferrer'); }
        });
        return tpl.innerHTML;
    }

    /**
     * Pose un morceau d'écran que le sondage redessine — seulement s'il a
     * changé : une saisie en cours reste, l'aperçu d'une page ne se recharge
     * pas, et les dépliants ouverts le restent (repérés par `data-view`).
     * Rend vrai s'il a été reposé.
     */
    function setHtml(el, html) {
        if (el.__wfHtml === html) { return false; }
        var keyOf = function (x, i) { return x.getAttribute('data-view') || ('#' + i); };
        var opened = {};
        el.querySelectorAll('details').forEach(function (x, i) { opened[keyOf(x, i)] = x.open; });
        el.innerHTML = html;
        el.__wfHtml = html;
        el.querySelectorAll('details').forEach(function (x, i) {
            var k = keyOf(x, i);
            if (opened[k] !== undefined) { x.open = opened[k]; }
        });
        if (window.MiaHtmlPreview) { window.MiaHtmlPreview.scan(el); }
        return true;
    }

    /** Le contenu entier d'une action ou d'une pièce, à déplier (HTML sûr fait par le serveur). */
    function viewBox(html, label, key) {
        if (!html) { return ''; }
        return '<details class="wf-tl-view" data-view="' + esc(key) + '"><summary>' + esc(label) + '</summary><div class="wf-view">' + html + '</div></details>';
    }

    /** Les actions proposées par une brique : intitulé, objet, sort, contenu (l'email écrit…). */
    function cardList(list) {
        if (!list || !list.length) { return ''; }
        return '<div class="wf-tl-outcomes wf-cards"><ul>' + list.map(function (c) {
            return '<li class="tone-' + esc(c.tone) + '"><span class="grow"><b>' + esc(c.label) + '</b>'
                + (c.object ? '<small>' + esc(c.object) + '</small>' : '') + (c.error ? '<small class="is-err">' + esc(c.error) + '</small>' : '') + '</span>'
                + '<span class="wf-tl-state">' + esc(c.state) + '</span>' + viewBox(c.view, c.view_label, 'card:' + c.id) + '</li>';
        }).join('') + '</ul></div>';
    }

    function statusLabel(s, timer) {
        if (s === 'waiting' && timer) { return [T('Reprendra seule'), 'run']; }
        return {
            running: [T('En cours'), 'run'], waiting: [T('Attend votre décision'), 'wait'], paused: [T('En pause'), 'wait'],
            done: [T('Terminé'), 'ok'], stopped: [T('Arrêté comme prévu'), ''], failed: [T('En échec'), 'err'], cancelled: [T('Arrêté'), ''],
        }[s] || [s, ''];
    }

    /** « redaction~review#3 » → ['redaction~review', 3]. */
    function splitIter(key) {
        var m = String(key || '').match(/^(.+)#(\d{1,3})$/);
        return m ? [m[1], parseInt(m[2], 10)] : [String(key || ''), null];
    }

    function nodeTitle(key) {
        var it = splitIter(key);
        key = it[0];
        var suffix = it[1] ? ' · ' + T('tour {n}', { n: it[1] }) : '';
        if (key === 'start') { return (def.start || {}).title || T('Départ'); }
        if (key === 'end') { return (def.end || {}).title || T('Livrable'); }
        var base = String(key || '').split('~')[0];
        var found = findNode(base);
        if (!found) { return ''; }
        return (key.indexOf('~') > 0 ? T('Relecture de « {titre} »', { titre: found.title }) : found.title) + suffix;
    }

    /** Une pièce du dossier, ou celle d'un tour de boucle (« memoire#2 »). */
    function getPiece(k) {
        if (state.pieces[k]) { return state.pieces[k]; }
        var it = splitIter(k);
        return it[1] && state.iter_pieces && state.iter_pieces[it[0]] ? state.iter_pieces[it[0]][it[1]] || null : null;
    }

    // ------------------------------------------------------------------
    // Rendu
    // ------------------------------------------------------------------

    function render() {
        railHost.innerHTML = R.html(def, { catalog: cat, state: state, selected: panel && panel !== 'dossier' ? panel : null });
        // Pièce surlignée : reposée à chaque redessin (le rail se redessine à chaque sondage).
        R.highlight(railHost, highlight);
        R.drawForks(railHost);
        var st = statusLabel(state.status, !!state.timer);
        statusEl.className = 'wf-pill ' + st[1];
        statusEl.textContent = st[0];
        creditsEl.textContent = state.credits ? T('{n} crédits', { n: state.credits }) : T('aucun crédit pour l’instant');
        cancelBtn.hidden = !!state.terminal;
        setHtml(banner, decision());
        pickCount();
        var n = Object.keys(state.pieces || {}).length;
        dossierCount.hidden = n === 0;
        dossierCount.textContent = String(n);
        renderDrawer();
    }

    function renderDrawer() {
        if (!panel) { return; }
        if (!setHtml(drawer, panel === 'dossier' ? dossierPanel() : nodePanel(panel))) { return; }
        if (editing && open[editing]) {
            var ta = drawer.querySelector('[data-edit-input]');
            if (ta) { ta.focus(); }
        }
    }

    /** Ouvre (ou ferme, null) le volet : le dossier, ou le détail d'une brique. */
    function setPanel(p, focusPiece) {
        // Le volet change ou se ferme : la pièce surlignée part avec lui.
        if (p !== panel) { editing = null; highlight = null; }
        panel = p;
        drawer.classList.toggle('is-open', !!p);
        drawer.setAttribute('aria-hidden', p ? 'false' : 'true');
        document.body.classList.toggle('wf-drawer-on', !!p);
        render();
        if (!p) { return; }
        var card = focusPiece ? drawer.querySelector('[data-card="' + focusPiece + '"]') : null;
        if (card) { card.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } else { drawer.scrollTop = 0; }
    }

    function decision() {
        var s = state;
        if (s.gate) { return gatePanel(s.gate); }
        if (s.timer) { return timerPanel(s.timer); }
        if (s.waiting_cards) {
            var atEnd = splitIter(s.current)[0] === 'end';
            return '<section class="wf-gate"><div class="wf-ins-kicker" style="--hue:var(--wf-gate)"><span class="wf-node-ic">' + icon('hand') + '</span>' + esc(T('À vous')) + '</div>'
                + '<h3>' + esc(atEnd ? T('Le livrable attend votre accord pour partir') : T('Des actions attendent votre accord')) + '</h3>'
                + '<p>' + esc(atEnd
                    ? T('Les livraisons (email, Drive, Slack) sont prêtes : relisez-les et approuvez-les dans la conversation. Le workflow se termine ensuite.')
                    : T('« {titre} » a préparé des actions (email, évènement…). Approuvez-les ou annulez-les dans la conversation : le workflow reprend ensuite tout seul.', { titre: nodeTitle(s.current) })) + '</p>'
                + (s.conversation_url ? '<div class="actions"><a class="wf-btn primary" href="' + esc(s.conversation_url) + '">' + icon('chat') + esc(T('Ouvrir la conversation')) + '</a></div>' : '') + '</section>';
        }
        if (s.status === 'running') {
            return '<section class="wf-panel"><h3><span class="wf-spinner"></span>' + esc(T('En cours : {titre}', { titre: nodeTitle(s.current) })) + '</h3>'
                + '<p class="wf-hint">' + esc(T('Vous pouvez quitter cette page : le workflow continue, et vous serez prévenu s’il a besoin de vous.')) + '</p>'
                + (s.conversation_url ? '<p class="wf-hint"><a href="' + esc(s.conversation_url) + '">' + esc(T('Suivre le détail dans la conversation')) + '</a></p>' : '') + '</section>';
        }
        if (s.status === 'failed' || s.status === 'paused') {
            var err = s.error || {};
            var msg = err.message || T('Cause inconnue.');
            var atEndF = splitIter(s.current)[0] === 'end';
            // Cause présentée par le modèle (titre + explication) : le titre
            // dans la phrase, l'explication dessous.
            var acts = '';
            if (s.resumable) {
                acts += '<button type="button" class="wf-btn primary sm" data-do="retry">' + icon('retry') + esc(atEndF ? T('Relancer les livraisons en échec') : T('Reprendre à cette brique')) + '</button>';
                if (s.latest_version) {
                    acts += '<button type="button" class="wf-btn sm" data-do="retry-latest">' + icon('spark') + esc(T('Reprendre avec la version {v}', { v: s.latest_version })) + '</button>';
                }
                if (s.can_skip) {
                    acts += '<button type="button" class="wf-btn sm" data-do="skip">' + icon('skip') + esc(atEndF ? T('Terminer sans elles') : T('Ignorer et continuer')) + '</button>';
                }
            }
            if (s.conversation_url) { acts += '<a class="wf-btn ghost sm" href="' + esc(s.conversation_url) + '">' + esc(T('Voir la conversation')) + '</a>'; }
            return '<section class="wf-err-box"><b>' + esc(s.status === 'paused' ? T('Exécution en pause') : T('L’exécution s’est arrêtée')) + '</b>'
                + '<div>' + esc(T('À « {titre} » : {cause}', { titre: nodeTitle(s.current), cause: err.title || msg })) + '</div>'
                + (err.title && err.message ? '<p class="wf-hint">' + esc(err.message) + '</p>' : '')
                + (atEndF ? deliveryList() : '')
                + '<div class="actions">' + acts + '</div>'
                + (s.latest_version ? '<p class="wf-hint">' + esc(T('Vous avez corrigé le workflow depuis ? « Reprendre avec la version {v} » applique vos réglages à cette exécution.', { v: s.latest_version })) + '</p>' : '')
                + loopRetryBox() + restartBox() + '</section>';
        }
        // Fin par « Arrêter le workflow » réglée sur « Terminée » : pas de livrable.
        var endNode = s.status === 'done' ? findNode(splitIter(s.current)[0]) : null;
        if (endNode && endNode.type === 'stop') {
            var said = ((s.nodes || {})[endNode.key] || {}).detail || {};
            return '<section class="wf-done-box"><b>' + icon('check') + ' ' + esc(T('Terminé à « {titre} »', { titre: nodeTitle(endNode.key) })) + '</b>'
                + (said.reason ? '<div class="wf-why">' + esc(said.reason) + '</div>' : '')
                + '<p class="wf-hint">' + esc(T('Le workflow s’est terminé sur cette voie, comme prévu, sans livrable. Ce qui a été produit reste dans le dossier.')) + '</p>'
                + restartBox() + '</section>';
        }
        if (s.status === 'done') {
            var delivered = ((def.end || {}).pieces || []).filter(function (k) { return s.pieces[k]; });
            return '<section class="wf-done-box"><b>' + icon('check') + ' ' + esc(T('Livré')) + '</b>'
                + '<p class="wf-hint">' + esc(delivered.length ? T('Vos pièces sont prêtes : ouvrez-les ici, ou retrouvez-les dans la conversation.') : T('Le workflow est allé au bout.')) + '</p>'
                + (s.end_note ? '<p>' + esc(s.end_note) + '</p>' : '')
                + (delivered.length ? '<div class="wf-chips">' + openChips(delivered) + '</div>' : '')
                + deliveryList() + loopRetryBox() + restartBox() + '</section>';
        }
        if (s.status === 'stopped') {
            var why = ((s.nodes || {})[s.current] || {}).detail || {};
            return '<section class="wf-panel"><h3>' + icon('stop') + esc(T('Arrêté par « {titre} »', { titre: nodeTitle(s.current) })) + '</h3>'
                + (why.reason || (why.lines || []).length ? '<div class="wf-why">' + esc(why.reason || why.lines.join(' · ')) + '</div>' : '')
                + '<p class="wf-hint">' + esc(T('C’est la voie prévue : rien d’anormal.')) + '</p>' + restartBox() + '</section>';
        }
        if (s.status === 'cancelled') {
            return '<section class="wf-panel"><h3>' + icon('stop') + esc(T('Exécution arrêtée')) + '</h3><p class="wf-hint">' + esc(T('Ce qui a été produit jusque-là reste dans le dossier.')) + '</p>' + loopRetryBox() + restartBox() + '</section>';
        }
        return '';
    }

    /** Une attente en cours : la fin d'une brique « Attendre », ou un nouvel essai automatique. */
    function timerPanel(tm) {
        var retry = tm.kind === 'retry';
        return '<section class="wf-panel wf-timer"><h3>' + icon(retry ? 'retry' : 'wait') + esc(retry
            ? T('Nouvel essai automatique : {quand}', { quand: tm.at })
            : T('En attente jusqu’au {quand}', { quand: tm.at })) + '</h3>'
            + '<p class="wf-hint">' + esc(retry
                ? T('« {titre} » a rencontré une panne passagère ({cause}). Essai {n} sur {max}.', { titre: nodeTitle(state.current), cause: tm.cause || T('cause inconnue'), n: tm.auto, max: tm.of })
                : T('« {titre} » : le workflow reprendra seul, même si personne n’ouvre MIA.', { titre: nodeTitle(state.current) })) + '</p>'
            + '<div class="actions"><button type="button" class="wf-btn sm" data-do="resume">' + icon(retry ? 'retry' : 'skip') + esc(retry ? T('Réessayer maintenant') : T('Ne plus attendre')) + '</button></div></section>';
    }

    /** L'issue de chaque livraison du livrable. */
    function deliveryList() {
        var st = (state.nodes || {}).end;
        var list = st && st.deliveries ? st.deliveries : [];
        if (!list.length) { return ''; }
        var tone = { done: ['ok', 'check', T('Fait')], card: ['wait', 'hand', T('À valider')], skipped: ['', 'skip', T('Rien à livrer')], failed: ['err', 'alert', T('Échec')] };
        return '<ul class="wf-dl-status">' + list.map(function (d) {
            var t = tone[d.status] || ['', 'wait', T('À venir')];
            var cards = d.cards || [];
            return '<li><span class="wf-pill ' + t[0] + '">' + icon(t[1]) + esc(t[2]) + '</span><span>' + esc(d.label) + '</span>' + (d.error ? '<small>' + esc(d.error) + '</small>' : '')
                + cards.map(function (c) {
                    return viewBox(c.view, cards.length > 1 && c.object ? c.view_label + ' — ' + c.object : c.view_label, 'card:' + c.id);
                }).join('') + '</li>';
        }).join('') + '</ul>';
    }

    /** « Refaire les éléments en échec » de chaque boucle qui en a. */
    function loopRetryBox() {
        var out = '';
        Object.keys(state.loops || {}).forEach(function (k) {
            var lp = state.loops[k];
            if (!lp.can_retry_failed) { return; }
            out += '<div class="wf-loop-retry">' + icon('alert') + '<span>' + esc(T('« {titre} » : {n} en échec.', { titre: nodeTitle(k), n: lp.failed })) + '</span>'
                + '<button type="button" class="wf-btn sm" data-do="retry-loop" data-loop="' + esc(k) + '">' + icon('retry') + esc(T('Refaire les éléments en échec')) + '</button></div>';
        });
        return out;
    }

    /** Refaire depuis une brique du rail (exécution arrêtée, en échec, terminée). */
    function restartBox() {
        var pts = state.restart || [];
        if (!pts.length) { return ''; }
        return '<details class="wf-restart"><summary>' + icon('retry') + esc(T('Refaire depuis une brique…')) + '</summary>'
            + '<div class="wf-restart-body"><select class="wf-select" id="wfRestartFrom">' + pts.map(function (p, i) {
                return '<option value="' + esc(p.key) + '"' + (i === pts.length - 1 ? ' selected' : '') + '>' + esc(p.title) + '</option>';
            }).join('') + '</select>'
            + (state.latest_version ? '<label class="wf-check"><input type="checkbox" id="wfRestartLatest" checked><span>' + esc(T('Avec la version {v} du workflow', { v: state.latest_version })) + '</span></label>' : '')
            + '<p class="wf-hint">' + esc(T('Les pièces remises à partir de cette brique sont refaites ; celles d’avant sont gardées.')) + '</p>'
            + '<button type="button" class="wf-btn sm" data-do="restart">' + icon('retry') + esc(T('Refaire')) + '</button></div></details>';
    }

    /** Puces qui ouvrent une pièce dans le volet du dossier. */
    function openChips(keys) {
        var pm = pieces();
        return keys.map(function (k) {
            var p = getPiece(k);
            var base = pm[k] || pm[splitIter(k)[0]];
            if (!base) { return ''; }
            var it = splitIter(k)[1];
            return '<button type="button" class="wf-out wf-open" data-open="' + esc(k) + '" style="--c:' + esc(base.color) + '">' + icon(base.form === 'file' ? 'file' : base.form) + '<span>' + esc((p || base).name) + (it ? ' · ' + esc(T('tour {n}', { n: it })) : '') + '</span>' + icon('eye') + '</button>';
        }).join('');
    }

    /** Relecture d'une boucle : la liste des éléments, ou les actions préparées, à cocher. */
    // Ce qui est coché dans une relecture de boucle : gardé d'un redessin à l'autre (le sondage
    // redessine le bandeau), oublié quand la relecture change.
    var picks = { gate: null, off: {}, mail: 'approve' };

    function loopGatePanel(g) {
        var isList = g.loop_gate === 'list';
        if (picks.gate !== g.key) { picks = { gate: g.key, off: {}, mail: 'approve' }; }
        var items = isList ? (g.elements || []) : (g.actions || []);
        var head = '<div class="wf-ins-kicker" style="--hue:var(--wf-gate)"><span class="wf-node-ic">' + icon(isList ? 'list' : 'hand') + '</span>'
            + esc(T('Relecture')) + '</div><h3>' + esc(g.title) + '</h3>';
        var rows = items.map(function (it) {
            var id = isList ? it.i : it.id;
            var row = '<label class="wf-pick-row"><input type="checkbox" data-pick="' + esc(id) + '"' + (picks.off[id] ? '' : ' checked') + '>'
                + '<span>' + (!isList && it.element ? '<em class="wf-pick-el">' + esc(it.element) + '</em>' : '')
                + '<b>' + esc(isList ? it.label : (it.subject || it.target || it.action)) + '</b>'
                + (isList ? '' : '<small>' + esc([it.action, it.to].filter(Boolean).join(' · ')) + '</small>'
                    + (it.preview ? '<span class="wf-pick-prev">' + esc(it.preview) + '</span>' : '')) + '</span></label>';
            // L'action entière (l'email à relire avant de le laisser partir), hors de l'étiquette :
            // la déplier ne coche ni ne décoche rien.
            return isList ? row : '<div class="wf-pick-item">' + row + viewBox(it.view, it.view_label, 'card:' + it.id) + '</div>';
        }).join('');
        var hasMail = !isList && items.some(function (it) { return it.mail; });
        return '<section class="wf-gate" id="wfGate">' + head
            + '<p>' + esc(isList
                ? T('Décochez ce qu’il ne faut pas traiter : seuls les éléments cochés passent dans la boucle.')
                : T('Chaque tour a préparé son action sans attendre. Cochez celles qui partent : les autres sont écartées.')) + '</p>'
            + '<div class="wf-pick-tools"><button type="button" class="wf-btn ghost sm" data-pick-all="1">' + esc(T('Tout cocher')) + '</button>'
            + '<button type="button" class="wf-btn ghost sm" data-pick-all="0">' + esc(T('Tout décocher')) + '</button>'
            + '<span class="wf-hint" id="wfPickCount"></span></div>'
            + '<div class="wf-pick">' + rows + '</div>'
            + (hasMail ? '<div class="wf-seg" role="radiogroup" aria-label="' + esc(T('Les emails')) + '">'
                + '<button type="button" data-mailmode="approve"' + (picks.mail === 'send' ? '' : ' class="is-on"') + '>' + icon('pencil') + esc(T('Créer les brouillons')) + '</button>'
                + '<button type="button" data-mailmode="send"' + (picks.mail === 'send' ? ' class="is-on"' : '') + '>' + icon('send') + esc(T('Envoyer les emails')) + '</button></div>' : '')
            + '<div class="actions"><button type="button" class="wf-btn primary" data-gate="approve">' + icon('check') + esc(isList ? T('Lancer avec la sélection') : T('Exécuter la sélection')) + '</button>'
            + (isList ? '' : '<button type="button" class="wf-btn" data-gate="none">' + icon('x') + esc(T('N’en exécuter aucune')) + '</button>')
            + '<button type="button" class="wf-btn danger" data-gate="stop">' + icon('stop') + esc(T('Arrêter le workflow')) + '</button></div>'
            + '<p class="wf-hint wf-gate-err" id="wfGateErr" role="alert"></p></section>';
    }

    function gatePanel(g) {
        if (g.loop_gate) { return loopGatePanel(g); }
        var chips = openChips(g.reviews || []);
        var isRoute = (g.routes || []).length > 0;
        var head = '<div class="wf-ins-kicker" style="--hue:var(--wf-gate)"><span class="wf-node-ic">' + icon(isRoute ? 'route' : (g.doubt ? 'condition' : 'gate')) + '</span>'
            + esc(g.doubt ? T('MIA hésite') : T('Validation')) + '</div><h3>' + esc(g.title) + '</h3>';
        if (isRoute) {
            // Une décision : la personne choisit la voie, celle que MIA juge la plus proche en tête.
            return '<section class="wf-gate" id="wfGate">' + head
                + (g.question ? '<p>' + esc(g.question) + '</p>' : '')
                + (g.reason ? '<div class="wf-why">' + esc(g.reason) + '</div>' : '')
                + '<p>' + esc(T('Choisissez la voie à sa place : le workflow la suit.')) + '</p>'
                + '<div class="wf-route-pick">' + g.routes.map(function (r, i) {
                    return '<button type="button" class="wf-route-btn' + (r.key === g.suggested ? ' is-sug' : '') + '" data-gate="route" data-route="' + esc(r.key) + '">'
                        + '<span class="wf-lane-num" style="--lane:var(--wf-l' + ((i % 6) + 1) + ')">' + (i + 1) + '</span><span><b>' + esc(r.label) + '</b>' + (r.when ? '<small>' + esc(r.when) + '</small>' : '') + '</span>'
                        + (r.key === g.suggested ? '<em>' + esc(T('la plus proche')) + '</em>' : '') + '</button>';
                }).join('') + '</div>'
                + '<div class="actions"><button type="button" class="wf-btn danger" data-gate="stop">' + icon('stop') + esc(T('Arrêter le workflow')) + '</button></div>'
                + '<p class="wf-hint wf-gate-err" id="wfGateErr" role="alert"></p></section>';
        }
        if (g.doubt) {
            return '<section class="wf-gate" id="wfGate">' + head
                + (g.question ? '<p>« ' + esc(g.question) + ' »</p>' : '')
                + (g.reason ? '<div class="wf-why">' + esc(g.reason) + '</div>' : '')
                + '<p>' + esc(T('Répondez à sa place : le workflow suit la voie choisie.')) + '</p>'
                + '<div class="actions"><button type="button" class="wf-btn primary" data-gate="approve">' + icon('check') + esc(T('Oui')) + '</button>'
                + '<button type="button" class="wf-btn" data-gate="stop">' + icon('x') + esc(T('Non')) + '</button></div></section>';
        }
        return '<section class="wf-gate" id="wfGate">' + head
            + (g.note ? '<p>' + esc(g.note) + '</p>' : '<p>' + esc(T('Relisez les pièces, puis décidez.')) + '</p>')
            + (chips ? '<div class="wf-chips">' + chips + '</div>' : '')
            + (g.can_correct ? '<p class="wf-hint" style="margin-top:8px">' + esc(T('Vous pouvez corriger une pièce directement : ouvrez-la.')) + '</p>' : '')
            + '<div class="actions"><button type="button" class="wf-btn primary" data-gate="approve">' + icon('check') + esc(T('Approuver et continuer')) + '</button>'
            + (g.can_return ? '<button type="button" class="wf-btn" data-gate="return-open">' + icon('back') + esc(T('Renvoyer avec une remarque')) + '</button>' : '')
            + '<button type="button" class="wf-btn danger" data-gate="stop">' + icon('stop') + esc(T('Arrêter')) + '</button></div>'
            + '<div id="wfReturnBox" hidden><textarea class="wf-textarea" id="wfRemark" maxlength="2000" placeholder="' + esc(T('Ce qu’il faut changer, en une ou deux phrases.')) + '"></textarea>'
            + '<div class="actions"><button type="button" class="wf-btn primary sm" data-gate="return">' + esc(T('Renvoyer à l’étape')) + '</button>'
            + '<button type="button" class="wf-btn ghost sm" data-gate="return-close">' + esc(T('Annuler')) + '</button></div></div>'
            + '<p class="wf-hint wf-gate-err" id="wfGateErr" role="alert"></p></section>';
    }

    function canCorrect(key) {
        var g = state.gate;
        return !!(g && !g.doubt && g.can_correct && (g.reviews || []).indexOf(key) >= 0);
    }

    /** Une pièce remise par une brique Création : image, page web, carte. */
    function isCreation(form) {
        return form === 'image' || form === 'page' || form === 'map';
    }

    /** Couleur d'une pièce (celle d'un tour : la couleur de sa pièce). */
    function colorOf(k, pm) {
        var p = pm[k] || pm[splitIter(k)[0]];
        return p ? p.color : 'var(--accent)';
    }

    function downloads(p) {
        var base = p.download;
        var list;
        if (p.form === 'file') { return '<a class="wf-btn sm" href="' + esc(base) + '">' + icon('download') + esc(T('Télécharger')) + '</a>'; }
        if (p.form === 'image') {
            if (!(p.image || {}).url) { return ''; }
            list = [['', T('Télécharger')], ['jpg', 'JPEG']];
        } else if (p.form === 'page') { list = [['html', T('Page (.html)')], ['pdf', 'PDF'], ['docx', 'Word']]; }
        else if (p.form === 'map') { list = [['docx', 'Word'], ['pdf', 'PDF'], ['md', 'Markdown']]; }
        else if (p.form === 'table') { list = [['xlsx', 'Excel'], ['csv', 'CSV']]; }
        else if (p.form === 'document') { list = [[p.format || 'docx', (L.docFormats || {})[p.format] || (p.format || 'docx').toUpperCase()]].concat([['pdf', 'PDF'], ['md', 'Markdown']].filter(function (x) { return x[0] !== p.format; })); }
        else { list = [['docx', 'Word'], ['pdf', 'PDF'], ['md', 'Markdown']]; }
        return list.map(function (x, i) {
            return '<a class="wf-btn sm' + (i === 0 ? '' : ' ghost') + '" href="' + esc(x[0] ? base + '?format=' + x[0] : base) + '">' + (i === 0 ? icon('download') : '') + esc(x[1]) + '</a>';
        }).join('');
    }

    function pieceContent(p) {
        if (editing === p.key) {
            var val = p.form === 'list' ? (p.items || []).join('\n') : p.form === 'document' ? (p.source || '') : p.form === 'table' ? (p.text || '') : (p.text || '');
            if (p.form === 'fiche') {
                return '<div class="wf-kv" style="grid-template-columns:minmax(0,1fr)">' + (p.fields || []).map(function (f) {
                    return '<label class="wf-check" style="flex-direction:column;gap:4px"><span>' + esc(f.label) + '</span>'
                        + (f.type === 'list' || f.type === 'longtext'
                            ? '<textarea class="wf-textarea" style="min-height:60px" data-edit-field="' + esc(f.key) + '">' + esc(f.raw) + '</textarea>'
                            : '<input class="wf-input" data-edit-field="' + esc(f.key) + '" value="' + esc(f.raw) + '">') + '</label>';
                }).join('') + '</div>';
            }
            return '<textarea class="wf-textarea" style="min-height:220px" data-edit-input>' + esc(val) + '</textarea>'
                + (p.form === 'list' ? '<p class="wf-hint">' + esc(T('Un élément par ligne.')) + '</p>' : '')
                + (p.form === 'table' ? '<p class="wf-hint">' + esc(T('Gardez la ligne d’en-tête et la ligne de tirets.')) + '</p>' : '');
        }
        if (p.form === 'fiche') {
            return '<dl class="wf-kv">' + (p.fields || []).map(function (f) {
                return '<dt>' + esc(f.label) + '</dt><dd>' + (f.value !== '' ? esc(f.value) : '<span class="wf-hint">—</span>') + '</dd>';
            }).join('') + '</dl>' + (p.text ? '<p class="wf-hint" style="margin-top:8px">' + esc(p.text) + '</p>' : '');
        }
        if (p.form === 'file') {
            return '<p class="wf-hint">' + esc((p.file || {}).name || '') + ((p.file || {}).url ? ' · <a href="' + esc(p.file.url) + '" target="_blank" rel="noopener noreferrer">' + esc(T('Ouvrir dans Drive')) + '</a>' : '') + '</p>'
                + (p.text ? '<div class="content">' + md(p.text.slice(0, 4000)) + '</div>' : '');
        }
        if (isCreation(p.form)) { return creationContent(p); }
        return '<div class="content">' + md(p.form === 'document' ? (p.source || p.text) : p.text) + '</div>';
    }

    /**
     * Une création au dossier : l'image elle-même (servie à la session, comme
     * dans le chat), le texte d'une page — la page se voit dans la
     * conversation —, le tracé d'un itinéraire (SVG fait par le serveur, sans
     * tuile) et ses étapes.
     */
    function creationContent(p) {
        var convLink = function (url, label) {
            return url ? '<p><a class="wf-btn sm ghost" href="' + esc(url) + '">' + icon('chat') + esc(label) + '</a></p>' : '';
        };
        if (p.form === 'image') {
            var im = p.image || {};
            return (im.url
                ? '<a class="wf-piece-img" href="' + esc(im.url) + '" target="_blank" rel="noopener"><img src="' + esc(im.url) + '" alt="' + esc(p.name) + '" loading="lazy"></a>'
                : '<p class="wf-hint">' + esc(T('Image retirée avec la conversation de l’exécution : seule sa description reste.')) + '</p>')
                + (im.prompt ? '<p class="wf-hint wf-piece-prompt">' + esc(im.prompt.length > 400 ? im.prompt.slice(0, 400) + '…' : im.prompt) + '</p>' : '');
        }
        if (p.form === 'page') {
            var pg = p.page || {};
            // La page elle-même, dans un cadre isolé (html-preview.js : sandbox, origine opaque).
            var frame = p.download && p.download.charAt(0) === '/'
                ? '<div class="wf-view-page" data-html-preview="' + esc(p.download + '?format=html') + '"><iframe sandbox="allow-scripts" referrerpolicy="no-referrer" title="' + esc(pg.title || p.name) + '"></iframe>'
                    + '<p class="wf-view-note" data-preview-error hidden>' + esc(T('Aperçu indisponible : téléchargez la page pour l’ouvrir.')) + '</p></div>'
                : '';
            return (pg.title ? '<p><b>' + esc(pg.title) + '</b></p>' : '') + frame
                + convLink(pg.conversation_url, T('Voir la page dans la conversation'));
        }
        var mp = p.map || {};
        return (mp.svg ? '<div class="wf-piece-map">' + mp.svg + '</div>' : '')
            + (mp.title ? '<p><b>' + esc(mp.title) + '</b></p>' : '')
            + ((mp.steps || []).length ? '<ol class="wf-piece-steps">' + mp.steps.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ol>' : '')
            + convLink(mp.conversation_url, T('Voir la carte dans la conversation'));
    }

    function pieceCard(k, pm) {
        var src = getPiece(k);
        if (!src) { return ''; }
        var p = Object.assign({}, src, { color: colorOf(k, pm) });
        var by = p.node === 'start' ? T('Départ') : nodeTitle(p.iter ? p.node + '#' + p.iter : p.node);
        var isOpen = !!open[k];
        var edit = canCorrect(k) && p.form !== 'file' && !isCreation(p.form)
            ? (editing === k
                ? '<button type="button" class="wf-btn primary sm" data-save="' + esc(k) + '">' + icon('check') + esc(T('Enregistrer')) + '</button><button type="button" class="wf-btn ghost sm" data-edit-cancel>' + esc(T('Annuler')) + '</button>'
                : '<button type="button" class="wf-btn sm" data-edit="' + esc(k) + '">' + icon('pencil') + esc(T('Corriger')) + '</button>')
            : '';
        return '<div class="wf-piece-card' + (isOpen ? ' is-open' : '') + '" data-card="' + esc(k) + '" style="--c:' + esc(p.color) + '">'
            + '<button type="button" data-toggle="' + esc(k) + '" aria-expanded="' + (isOpen ? 'true' : 'false') + '"><span class="di">' + icon(p.form === 'file' ? 'file' : p.form) + '</span>'
            + '<div><b>' + esc(p.name) + (p.version > 1 ? ' <span class="wf-pill">v' + p.version + '</span>' : '') + '</b><small>' + esc(p.summary || '') + ' · ' + esc(T('par {qui}', { qui: by })) + '</small></div>'
            + icon(isOpen ? 'up' : 'down') + '</button>'
            + '<div class="wf-piece-body">' + (isOpen ? pieceContent(p) : '') + '<div class="row-acts">' + (editing === k ? '' : downloads(p)) + edit + '</div></div></div>';
    }

    function pieceList(keys) {
        var pm = pieces();
        return '<div class="wf-pieces">' + keys.map(function (k) { return pieceCard(k, pm); }).join('') + '</div>';
    }

    /** En-tête d'un volet : nature, titre, bouton de fermeture. */
    function head(kind, kicker, title, hue, extra) {
        return '<div class="wf-ins-head" style="--hue:' + esc(hue || 'var(--accent)') + '"><div class="wf-ins-kicker"><span class="wf-node-ic">' + icon(kind) + '</span>' + esc(kicker)
            + '<button type="button" class="wf-drawer-x" data-close title="' + esc(T('Fermer')) + '" aria-label="' + esc(T('Fermer')) + '">' + icon('x') + '</button></div>'
            + (title ? '<h3>' + esc(title) + '</h3>' : '') + (extra || '') + '</div>';
    }
    function sec(title, body) {
        return '<section class="wf-sec"><h4>' + esc(title) + '</h4>' + body + '</section>';
    }

    function dossierPanel() {
        var pm = pieces();
        var keys = Object.keys(pm).filter(function (k) { return state.pieces[k]; });
        var n = keys.length;
        return head('folder', T('Le dossier'), window.tn ? window.tn(n, '{n} pièce au dossier', '{n} pièces au dossier') : String(n), 'var(--accent)')
            + '<section class="wf-sec">' + (n ? pieceList(keys) : '<p class="wf-hint">' + esc(T('Les pièces apparaîtront ici à mesure que les étapes les remettent.')) + '</p>') + '</section>';
    }

    function findNode(key) {
        var found = null;
        var walk = function (list) {
            (list || []).forEach(function (n) {
                if (n.key === key) { found = n; }
                R.branchesOf(n).forEach(function (b) { walk(b.nodes); });
                if (n.type === 'loop') { walk(n.body); }
            });
        };
        walk(def.nodes);
        return found;
    }

    /** Ce que chaque tour d'une boucle a remis (la pièce suivie), cliquable. */
    function turnsHtml(loopKey) {
        var lp = (state.loops || {})[loopKey];
        if (!lp || !lp.turns || !lp.turns.length) { return '<p class="wf-hint">' + esc(T('Aucun tour pour l’instant.')) + '</p>'; }
        var pm = pieces();
        return '<ol class="wf-turns">' + lp.turns.map(function (t) {
            var p = t.piece;
            var chip = p ? '<button type="button" class="wf-out wf-open" data-open="' + esc(p.key) + '" style="--c:' + esc(colorOf(p.key, pm)) + '">' + icon(p.form) + '<span>' + esc(p.summary || p.name) + '</span>' + icon('eye') + '</button>'
                : '<span class="wf-hint">' + esc(T('rien de remis')) + '</span>';
            var round = (lp.rounds || []).filter(function (r) { return r.i === t.i; })[0];
            var st = { failed: ['err', T('en échec')], skipped: ['', T('écarté')], running: ['run', T('en cours')], pending: ['', T('à venir')] }[t.status];
            if (st && t.status !== 'done') { chip = '<span class="wf-pill ' + st[0] + '">' + esc(st[1]) + '</span>' + (t.status === 'failed' && t.cause ? '<small class="err">' + esc(t.cause) + '</small>' : ''); }
            return '<li class="is-' + esc(t.status || 'done') + '"><b>' + esc(t.label) + '</b>' + chip + (round ? '<small class="' + (round.met ? 'ok' : '') + '">' + esc(round.met ? T('condition remplie') : T('pas encore')) + (round.reason ? ' — ' + esc(round.reason) : '') + '</small>' : '') + '</li>';
        }).join('') + '</ol>';
    }
    /** Ce qu'une étape ou une création remet : sa pièce, ou celle de chaque tour dans une boucle. */
    function outputSection(node) {
        var loopOfNode = null;
        (def.nodes || []).forEach(function (n) { if (n.type === 'loop' && (n.body || []).some(function (c) { return c.key === node.key; })) { loopOfNode = n; } });
        if (loopOfNode) {
            var its = (state.iter_pieces || {})[node.output.key] || {};
            var keysIt = Object.keys(its).sort(function (a, b) { return a - b; }).map(function (i) { return node.output.key + '#' + i; });
            return sec(T('Remet à chaque tour'), keysIt.length ? pieceList(keysIt) : '<p class="wf-hint">' + esc(T('Pas encore remise.')) + '</p>');
        }
        return sec(T('Remet au dossier'), node.output && state.pieces[node.output.key]
            ? pieceList([node.output.key])
            : '<p class="wf-hint">' + esc(T('Pas encore remise.')) + '</p>');
    }

    function findIn(list, prop, val) {
        for (var i = 0; i < (list || []).length; i++) { if (list[i][prop] === val) { return list[i]; } }
        return null;
    }

    /** Le détail d'une brique : qui, ce qu'on lui a demandé, ce qu'elle a lu et remis. */
    function nodePanel(key) {
        var pm = pieces();
        var hues = cat.hues || {};
        var kinds = L.kinds || {};
        if (key === 'start' || key === 'end') {
            var keys = key === 'start'
                ? Object.keys(pm).filter(function (k) { return pm[k].producer === 'start' && state.pieces[k]; })
                : ((def.end || {}).pieces || []).filter(function (k) { return state.pieces[k]; });
            return head(key, kinds[key] || '', nodeTitle(key), hues[key])
                + (key === 'start' && state.trigger ? sec(T('Lancé'), '<p class="wf-hint">' + esc(state.trigger) + '</p>') : '')
                + (key === 'end' && state.end_note ? sec(T('Un mot à la fin'), '<p class="wf-hint">' + esc(state.end_note) + '</p>') : '')
                + sec(key === 'start' ? T('Ce qui a été fourni') : T('Ce que vous obtenez'),
                    keys.length ? pieceList(keys) : '<p class="wf-hint">' + esc(T('Les pièces apparaîtront ici à mesure que les étapes les remettent.')) + '</p>')
                + (key === 'end' && (def.end.deliveries || []).length ? sec(T('Livraisons'), deliveryList() || '<p class="wf-hint">' + esc(T('Après le livrable.')) + '</p>') : '');
        }
        var node = findNode(key);
        if (!node) { return head('step', '', '', hues.step); }
        var st = (state.nodes || {})[key] || null;
        var hue = hues[node.type];
        var kindIcon = node.type;
        var body = '';
        var reads = R.readsOf(def, node).filter(function (k) { return pm[k]; });
        var readHtml = function (title) {
            if (!reads.length) { return ''; }
            return sec(title, '<div class="wf-chips">' + reads.map(function (k) {
                return state.pieces[k] ? openChips([k]) : R.pieceChip(pm[k], 'read');
            }).join('') + '</div>');
        };
        if (node.type === 'step') {
            var prof = node.profile_id ? findIn(cat.profiles, 'id', node.profile_id) : null;
            if (prof && prof.color) { hue = prof.color; }
            var llm = prof ? prof.model : ((findIn(cat.llms, 'slug', node.llm || cat.default_llm) || {}).label || '');
            body += sec(T('Qui fait le travail'), '<div class="wf-who">' + R.avatar(prof, cat) + '<div><b>' + esc(prof ? prof.name : 'MIA') + '</b>'
                + (llm ? '<small>' + esc(llm) + '</small>' : '') + '</div></div>');
            body += sec(T('Consigne'), '<div class="wf-preview">' + R.richText(node.instruction || '', pm) + '</div>');
            body += readHtml(T('Lit'));
            body += outputSection(node);
            if (st && st.cards && st.cards.length) { body += sec(T('Actions proposées'), cardList(st.cards)); }
        } else if (node.type === 'create') {
            // L'outil réellement employé (celui du passage), sinon celui de la brique.
            var ct = R.findCreation(cat, node.tool_id);
            var used = (st && st.detail && st.detail.tool) || (ct ? ct.name : '');
            kindIcon = node.kind || 'create';
            body += sec(T('Outil'), '<p>' + esc(((L.createKinds || {})[node.kind] || '') + (used ? ' · ' + used : '')) + '</p>');
            body += sec(T('Ce qu’il fallait créer'), '<div class="wf-preview">' + R.richText(node.instruction || '', pm) + '</div>');
            body += readHtml(T('Lit'));
            body += outputSection(node);
        } else if (node.type === 'loop') {
            var lp = (state.loops || {})[node.key] || {};
            var ign = lp.ignored || {};
            var why = [];
            if (ign.filter) { why.push(T('{n} hors filtre', { n: ign.filter })); }
            if (ign.dup) { why.push(T('{n} doublons', { n: ign.dup })); }
            if (ign.label) { why.push(T('{n} total ou sans nom', { n: ign.label })); }
            body += sec(T('Comment'), '<p class="wf-hint">' + esc(node.mode === 'until'
                ? T('Recommence jusqu’à une condition, {n} tours au plus.', { n: node.max })
                : R.loopSentence(node, R.readsPiece(node.source) ? pm[node.over] : null) + ' · ' + T('{n} au plus', { n: node.max })) + '</p>'
                + (node.mode !== 'until' && lp.found ? '<p class="wf-hint">' + esc(T('{n} éléments lus', { n: lp.found }) + (why.length ? ' · ' + why.join(' · ') : '')) + '</p>' : '')
                + ((lp.notes || []).length ? '<p class="wf-hint">' + esc(lp.notes.join(' ')) + '</p>' : '')
                + (lp.total > lp.count && node.mode !== 'until' ? '<p class="wf-hint">' + esc(T('{n} éléments sur {total} traités : la limite de la boucle.', { n: lp.count, total: lp.total })) + '</p>' : '')
                + (node.mode !== 'until' && (lp.failed || lp.skipped) ? '<p class="wf-hint">' + esc([lp.done_count ? T('{n} traités', { n: lp.done_count }) : '', lp.failed ? T('{n} en échec', { n: lp.failed }) : '', lp.skipped ? T('{n} écartés', { n: lp.skipped }) : ''].filter(Boolean).join(' · ')) + '</p>' : '')
                + (lp.can_retry_failed ? '<div class="actions"><button type="button" class="wf-btn sm" data-do="retry-loop" data-loop="' + esc(node.key) + '">' + icon('retry') + esc(T('Refaire les éléments en échec')) + '</button></div>' : ''));
            body += sec(node.mode === 'until' ? T('Les tours') : T('Les éléments'), turnsHtml(node.key));
            body += sec(T('Remet au dossier'), node.output && state.pieces[node.output.key] ? pieceList([node.output.key]) : '<p class="wf-hint">' + esc(T('À la fin de la boucle.')) + '</p>');
        } else if (node.type === 'wait') {
            body += sec(T('Attendre'), '<p class="wf-hint">' + esc(R.waitText(node, pm, L)) + '</p>' + (st && st.resume ? '<p>' + esc(T('Reprise : {quand}', { quand: st.resume })) + '</p>' : ''));
        } else if (node.type === 'notify') {
            body += sec(T('Le message'), '<div class="wf-preview">' + R.richText(node.message || '', pm) + '</div>');
        } else if (node.type === 'stop') {
            body += sec(T('Statut de l’exécution'), '<p>' + esc(((L.stopOutcomes || {})[node.outcome === 'stopped' ? 'stopped' : 'done']) || '') + '</p>'
                + '<p class="wf-hint">' + esc(T('Le workflow se termine ici, sans livrable.')) + '</p>');
            if (st && st.detail && st.detail.reason) {
                body += sec(T('Le mot de la fin'), '<div class="wf-why">' + esc(st.detail.reason) + '</div>');
            } else if (node.message) {
                body += sec(T('Le mot de la fin'), '<div class="wf-preview">' + R.richText(node.message, pm) + '</div>');
            }
        } else if (node.type === 'action') {
            var tg = findIn(cat.targets, 'key', node.target);
            body += sec(T('Outil'), '<p>' + esc(tg ? tg.label : '') + ' · ' + esc(node.write === 'auto' ? T('Sans validation') : T('À approuver')) + '</p>');
            body += sec(T('Consigne'), '<div class="wf-preview">' + R.richText(node.instruction || '', pm) + '</div>');
            body += readHtml(T('Lit'));
            body += sec(T('Ce qui a été fait'), st && st.cards && st.cards.length
                ? cardList(st.cards)
                : '<p class="wf-hint">' + esc(st ? T('Aucune action proposée.') : T('Pas encore faite.')) + '</p>');
        } else if (node.type === 'condition') {
            body += sec(node.mode === 'rule' ? T('Règle') : T('Question à MIA'), node.mode === 'rule'
                ? '<p class="wf-hint">' + esc(((st || {}).detail || {}).lines ? st.detail.lines.join(' · ') : '') + '</p>'
                : '<div class="wf-preview">« ' + R.richText(node.question || '', pm) + ' »</div>');
            if (st && st.detail && st.detail.reason) { body += sec(T('Réponse de MIA'), '<div class="wf-why">' + esc(st.detail.reason) + '</div>'); }
            body += readHtml(T('Lit'));
        } else if (node.type === 'route') {
            body += sec(T('Sur quoi MIA choisit'), '<div class="wf-preview">' + R.richText(node.instruction || '', pm) + '</div>');
            var chosenKey = st && st.status === 'done' ? st.outcome : '';
            // MIA hésitait et la personne a tranché : la raison dite est celle du doute.
            var byPerson = !!(st && st.detail && st.detail.decided_by === 'person');
            body += sec(T('Les voies'), '<div class="wf-route-pick is-static">' + (node.routes || []).map(function (r, i) {
                return '<div class="wf-route-btn' + (r.key === chosenKey ? ' is-sug' : (chosenKey ? ' is-out' : '')) + '"><span class="wf-lane-num" style="--lane:var(--wf-l' + ((i % 6) + 1) + ')">' + (i + 1) + '</span><span><b>' + esc(r.label) + '</b>'
                    + (r.when ? '<small>' + R.richText(r.when, pm) + '</small>' : '') + '</span>' + (r.key === chosenKey ? '<em>' + esc(byPerson ? T('choisie par vous') : T('choisie')) + '</em>' : '') + '</div>';
            }).join('') + '</div>');
            if (st && st.detail && st.detail.reason) { body += sec(byPerson ? T('Ce qui a fait hésiter MIA') : T('Pourquoi'), '<div class="wf-why">' + esc(st.detail.reason) + (st.detail.fallback ? ' — ' + esc(T('voie de repli : MIA n’a pas su choisir')) : '') + '</div>'); }
            body += readHtml(T('Lit'));
        } else {
            body += sec(T('Ce qu’on relit'), (node.reviews || []).length ? '<div class="wf-chips">' + openChips((node.reviews || []).filter(function (k) { return state.pieces[k]; })) + '</div>' : '');
        }
        if (state.conversation_url) {
            body += '<section class="wf-sec"><a class="wf-btn sm" href="' + esc(state.conversation_url) + '">' + icon('chat') + esc(T('Voir la conversation')) + '</a></section>';
        }
        var pill = R.statusPill(state, node, cat);
        return head(kindIcon, kinds[node.type] || '', node.title || T('Sans titre'), hue, pill ? '<div class="wf-head-pill">' + pill + '</div>' : '') + body;
    }

    // ------------------------------------------------------------------
    // Gestes
    // ------------------------------------------------------------------

    function post(url, data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (v && typeof v === 'object') { Object.keys(v).forEach(function (s) { fd.append(k + '[' + s + ']', v[s]); }); }
            else { fd.append(k, v); }
        });
        return fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: T('Réponse illisible.') }; }); });
    }

    function applyState(j) {
        if (j && j.state) { state = j.state; render(); schedule(); }
    }

    function gateError(msg) {
        var el = $('#wfGateErr');
        if (el) { el.textContent = msg || ''; } else if (msg) { window.alert(msg); }
    }

    function onPanelClick(e) {
        var b;
        if (e.target.closest('[data-close]')) { setPanel(null); return; }
        if ((b = e.target.closest('[data-toggle]'))) {
            var k = b.getAttribute('data-toggle');
            open[k] = !open[k];
            if (!open[k] && editing === k) { editing = null; }
            render();
            return;
        }
        if ((b = e.target.closest('[data-open]'))) {
            var key = b.getAttribute('data-open');
            open[key] = true;
            setPanel('dossier', key);
            return;
        }
        if ((b = e.target.closest('[data-edit]'))) { editing = b.getAttribute('data-edit'); open[editing] = true; render(); return; }
        if (e.target.closest('[data-edit-cancel]')) { editing = null; render(); return; }
        if ((b = e.target.closest('[data-save]'))) { return savePiece(b.getAttribute('data-save'), b); }
        if ((b = e.target.closest('[data-pick-all]'))) {
            var on = b.getAttribute('data-pick-all') === '1';
            banner.querySelectorAll('[data-pick]').forEach(function (x) { x.checked = on; picks.off[x.getAttribute('data-pick')] = !on; });
            pickCount();
            return;
        }
        if ((b = e.target.closest('[data-mailmode]'))) {
            banner.querySelectorAll('[data-mailmode]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            picks.mail = b.getAttribute('data-mailmode');
            return;
        }
        if ((b = e.target.closest('[data-do="retry-loop"]'))) {
            var lk = b.getAttribute('data-loop');
            var lpN = ((state.loops || {})[lk] || {}).failed || 0;
            if (busy || !window.confirm(T('Refaire les {n} éléments en échec de « {titre} » ? Les éléments traités gardent leur pièce ; ce qui suit la boucle est refait.', { n: lpN, titre: nodeTitle(lk) }))) { return; }
            busy = true;
            b.disabled = true;
            post('/workflows/runs/' + D.run.id + '/loops/' + encodeURIComponent(lk) + '/retry', {}).then(function (j) {
                busy = false;
                if (!j.ok) { window.alert(j.error || T('Reprise impossible.')); b.disabled = false; return; }
                applyState(j);
            });
            return;
        }
        if ((b = e.target.closest('[data-do]'))) {
            var what = b.getAttribute('data-do');
            var route = { retry: 'retry', 'retry-latest': 'retry', skip: 'skip', resume: 'resume', restart: 'restart' }[what];
            if (!route || busy) { return; }
            var data = {};
            if (what === 'retry-latest') { data.latest = 1; }
            if (what === 'skip' && !window.confirm(T('Ignorer cette brique ? Le workflow continue sans ce qu’elle devait remettre.'))) { return; }
            if (what === 'restart') {
                var from = ($('#wfRestartFrom') || {}).value || '';
                if (!from) { return; }
                if (!window.confirm(T('Refaire depuis « {titre} » ? Les pièces remises à partir de cette brique seront refaites.', { titre: nodeTitle(from) }))) { return; }
                data.from = from;
                if (($('#wfRestartLatest') || {}).checked) { data.latest = 1; }
            }
            busy = true;
            b.disabled = true;
            post('/workflows/runs/' + D.run.id + '/' + route, data).then(function (j) {
                busy = false;
                if (!j.ok) { window.alert(j.error || T('Reprise impossible.')); b.disabled = false; return; }
                // Une autre version du workflow : le rail affiché n'est plus le bon.
                if (j.state && j.state.version !== state.version) { window.location.reload(); return; }
                applyState(j);
            });
            return;
        }
        if ((b = e.target.closest('[data-gate]'))) {
            var g = b.getAttribute('data-gate');
            if (g === 'return-open') { $('#wfReturnBox').hidden = false; $('#wfRemark').focus(); return; }
            if (g === 'return-close') { $('#wfReturnBox').hidden = true; return; }
            // « Non » d'une condition n'arrête rien ; arrêter (validation, décision) se confirme.
            var stops = !state.gate.doubt || (state.gate.routes || []).length > 0;
            if (g === 'stop' && stops && !window.confirm(T('Arrêter l’exécution ? Ce qui a été produit reste dans le dossier.'))) { return; }
            var remark = g === 'return' ? ($('#wfRemark').value || '').trim() : '';
            if (g === 'return' && !remark) { gateError(T('Dites ce qu’il faut changer : la remarque guide la nouvelle version.')); return; }
            if (busy) { return; }
            var data = { decision: g, remark: remark, route: b.getAttribute('data-route') || '' };
            if (state.gate.loop_gate && g === 'approve') {
                var picked = [];
                banner.querySelectorAll('[data-pick]').forEach(function (x) { if (x.checked) { picked.push(x.getAttribute('data-pick')); } });
                if (!picked.length) { gateError(state.gate.loop_gate === 'list' ? T('Cochez au moins un élément, ou arrêtez le workflow.') : T('Cochez au moins une action, ou choisissez « N’en exécuter aucune ».')); return; }
                data.keep = picked;
                var mm = banner.querySelector('[data-mailmode].is-on');
                data.mail_mode = mm ? mm.getAttribute('data-mailmode') : 'approve';
                if (data.mail_mode === 'send' && !window.confirm(T('Envoyer les emails cochés ? Un email envoyé ne se reprend pas.'))) { return; }
            }
            busy = true;
            banner.querySelectorAll('[data-gate]').forEach(function (x) { x.disabled = true; });
            post('/workflows/runs/' + D.run.id + '/gate', data).then(function (j) {
                busy = false;
                if (!j.ok) {
                    banner.querySelectorAll('[data-gate]').forEach(function (x) { x.disabled = false; });
                    gateError(j.error || T('Décision impossible.'));
                    return;
                }
                editing = null;
                applyState(j);
            });
        }
    }
    /** Combien d'éléments ou d'actions sont cochés (relecture d'une boucle). */
    function pickCount() {
        var el = $('#wfPickCount');
        if (!el) { return; }
        var all = banner.querySelectorAll('[data-pick]').length;
        var on = banner.querySelectorAll('[data-pick]:checked').length;
        el.textContent = T('{n} sur {total} cochés', { n: on, total: all });
    }
    banner.addEventListener('change', function (e) {
        if (!e.target.matches('[data-pick]')) { return; }
        picks.off[e.target.getAttribute('data-pick')] = !e.target.checked;
        pickCount();
    });
    banner.addEventListener('click', onPanelClick);
    drawer.addEventListener('click', onPanelClick);

    function savePiece(key, btn) {
        var p = getPiece(key);
        var data = {};
        if (p.form === 'fiche') {
            var fields = {};
            drawer.querySelectorAll('[data-edit-field]').forEach(function (el) { fields[el.getAttribute('data-edit-field')] = el.value; });
            data.fields = fields;
        } else {
            var v = (drawer.querySelector('[data-edit-input]') || {}).value || '';
            data[{ text: 'text', list: 'items', table: 'table', document: 'source' }[p.form] || 'text'] = v;
        }
        btn.disabled = true;
        post('/workflows/runs/' + D.run.id + '/pieces/' + encodeURIComponent(key), data).then(function (j) {
            if (!j.ok) { btn.disabled = false; window.alert(j.error || T('Correction impossible.')); return; }
            editing = null;
            applyState(j);
        });
    }

    cancelBtn.addEventListener('click', function () {
        if (!window.confirm(T('Arrêter l’exécution ? Ce qui a été produit reste dans le dossier.'))) { return; }
        post('/workflows/runs/' + D.run.id + '/cancel', {}).then(applyState);
    });

    railHost.addEventListener('click', function (e) {
        var p = e.target.closest('[data-piece]');
        if (p) {
            var k = p.getAttribute('data-piece');
            if (state.pieces[k]) { open[k] = true; setPanel('dossier', k); return; }
            highlight = highlight === k ? null : k;
            render();
            return;
        }
        var card = e.target.closest('[data-node]');
        if (card) {
            var nk = card.getAttribute('data-node');
            var node = findNode(nk);
            if (node && node.output) { open[node.output.key] = true; }
            setPanel(panel === nk ? null : nk);
            return;
        }
        // Un clic dans le vide du canevas lève la surbrillance d'une pièce.
        if (highlight) { highlight = null; render(); }
    });
    railHost.addEventListener('keydown', function (e) {
        var card = e.target.closest('.wf-brick[data-node]');
        if (card && (e.key === 'Enter' || e.key === ' ') && e.target === card) {
            e.preventDefault();
            card.click();
        }
    });
    dossierBtn.addEventListener('click', function () { setPanel(panel === 'dossier' ? null : 'dossier'); });
    scrim.addEventListener('click', function () { setPanel(null); });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || (e.target && e.target.closest && e.target.closest('input, textarea, select'))) { return; }
        if (panel) { setPanel(null); } else if (highlight) { highlight = null; render(); }
    });
    railHost.addEventListener('mouseover', function (e) {
        var p = e.target.closest('[data-piece]');
        if (!highlight && p) { R.highlight(railHost, p.getAttribute('data-piece')); }
    });
    railHost.addEventListener('mouseout', function (e) {
        if (!highlight && e.target.closest('[data-piece]')) { R.highlight(railHost, null); }
    });

    // ------------------------------------------------------------------
    // Sondage
    // ------------------------------------------------------------------

    function schedule() {
        clearTimeout(pollTimer);
        if (state.terminal) { return; }
        var delay = state.status === 'running' ? 2500 : 9000;
        if (state.timer && state.timer.in > 0 && state.timer.in < 60) { delay = Math.max(2500, state.timer.in * 1000 + 1500); }
        pollTimer = setTimeout(poll, delay);
    }

    function poll() {
        if (document.hidden) { schedule(); return; }
        // Une correction en cours de saisie ne se laisse pas écraser.
        if (editing) { schedule(); return; }
        fetch('/workflows/runs/' + D.run.id + '/state', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.state) {
                    if (j.state.version !== state.version) { window.location.reload(); return; }
                    var changed = JSON.stringify(j.state) !== JSON.stringify(state);
                    state = j.state;
                    if (changed) { render(); }
                }
                schedule();
            })
            .catch(function () { pollTimer = setTimeout(poll, 15000); });
    }
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !state.terminal) { poll(); } });

    render();
    schedule();
    if (D.focus_action && state.gate) {
        var gate = $('#wfGate');
        if (gate) { gate.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }
})();
