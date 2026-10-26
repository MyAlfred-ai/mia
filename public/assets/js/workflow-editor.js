/**
 * Éditeur d'un workflow (/workflows/{id}/edit).
 *
 * Au centre, le rail (MiaWorkflowRail) : une ligne par brique. Un clic sur
 * une brique ouvre son VOLET (ses réglages) ; « Réglages » ouvre celui du
 * workflow : objectif, ce qui reste « à régler », le dossier, le coût estimé.
 * Dans le volet, on ne voit que ce qui est RETENU : chaque choix (qui fait
 * le travail, ce qu'elle consulte, ce qu'elle peut faire…) se résume sur une
 * ligne et se déplie pour être changé (choice()). Chaque geste modifie la définition en mémoire,
 * redessine le rail et enregistre le BROUILLON (POST /workflows/{id}/draft,
 * débounce) ; le serveur rend la liste « À régler » (WorkflowSpec::issues),
 * la seule qui compte pour publier. Le brouillon ne s'exécute jamais.
 *
 * Une consigne cite une pièce par `@{clé}` ; dans le champ, la personne lit
 * et écrit `@[Nom de la pièce]`. Taper « @ » (ou toucher le bouton @ du
 * champ) propose les pièces disponibles À CET ENDROIT, filtrées par ce que le
 * champ accepte (une adresse n'accepte qu'un champ email) ; chaque citation
 * est surlignée de la couleur de sa nature (mentionField()).
 *
 * Données : window.WF_EDITOR = { workflow, definition, issues, estimate, catalog }.
 */
(function () {
    'use strict';

    var D = window.WF_EDITOR;
    // Droits du rôle sur ses workflows (absents = tout, comme avant l'écran des rôles).
    var can = (D && D.can) || { create: true, delete: true, use: true };
    var R = window.MiaWorkflowRail;
    if (!D || !R) { return; }
    var cat = D.catalog;
    var L = cat.labels || {};
    var T = function (s, v) { return window.t ? window.t(s, v) : s; };
    var esc = R.esc;
    var $ = function (sel, el) { return (el || document).querySelector(sel); };

    var state = {
        def: D.definition,
        name: D.workflow.name,
        color: D.workflow.color,
        workflow: D.workflow,
        issues: D.issues || [],
        estimate: D.estimate,
        selected: null,
        open: false,
        // Choix dépliés du volet : « <brique>:<choix> » → vrai.
        expanded: {},
        highlight: null,
        saveTimer: null,
        saving: false,
        pendingSave: false,
        scheduleText: D.workflow.schedule || '',
    };

    var railHost = $('#wfRail');
    var insHost = $('#wfInspector');
    var objectiveLine = $('#wfObjectiveLine');
    var overviewBtn = $('#wfOverview');
    var issueCount = $('#wfIssueCount');
    var scrim = $('#wfScrim');
    var saveEl = $('#wfSaveState');
    var statusEl = $('#wfStatus');
    var publishBtn = $('#wfPublish');
    var originText = $('#wfOriginText');
    var launchBtn = $('#wfLaunch');
    var nameInput = $('#wfName');
    var colorInput = $('#wfColor');

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function icon(n) { return (cat.icons || {})[n] || ''; }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }

    // ------------------------------------------------------------------
    // Définition
    // ------------------------------------------------------------------

    function allPieces() { return R.pieces(state.def, cat.colors); }
    /** Pièces disponibles avant une brique, avec la couleur de leur nature. */
    function availableFor(key) { return R.available(state.def, key, allPieces()); }
    function pieceMap() {
        var m = {};
        allPieces().forEach(function (p) { m[p.key] = p; });
        return m;
    }

    function usedKeys() {
        var used = { start: 1, end: 1, depart: 1, evenement: 1 };
        (state.def.start.fields || []).forEach(function (f) { used[f.key] = 1; });
        var walk = function (list) {
            (list || []).forEach(function (n) {
                used[n.key] = 1;
                if (n.output) { used[n.output.key] = 1; }
                if (n.item) { used[n.item.key] = 1; }
                R.branchesOf(n).forEach(function (b) { walk(b.nodes); });
                if (n.type === 'loop') { walk(n.body); }
            });
        };
        walk(state.def.nodes);
        return used;
    }
    function newKey(prefix) {
        var used = usedKeys();
        var k;
        do { k = (prefix || 'k') + Math.random().toString(36).slice(2, 7); } while (used[k]);
        return k;
    }

    /** Les briques d'une voie (condition : yes / no ; décision : la clé de la voie), tableau MODIFIABLE. */
    function laneNodes(node, laneKey) {
        if (node.type === 'condition') {
            var l = node[laneKey] || (node[laneKey] = { nodes: [], then: laneKey === 'yes' ? 'continue' : 'stop' });
            return l.nodes || (l.nodes = []);
        }
        var r = (node.routes || []).filter(function (x) { return x.key === laneKey; })[0];
        return r ? (r.nodes || (r.nodes = [])) : [];
    }

    /** Brique par clé, avec sa liste et sa position (rail, voie ou corps de boucle). */
    function find(key) {
        var nodes = state.def.nodes;
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].key === key) { return { node: nodes[i], list: nodes, index: i, lane: null, cond: null }; }
            var lanes = R.branchesOf(nodes[i]);
            for (var l = 0; l < lanes.length; l++) {
                var arr = laneNodes(nodes[i], lanes[l].key);
                for (var j = 0; j < arr.length; j++) {
                    if (arr[j].key === key) { return { node: arr[j], list: arr, index: j, lane: lanes[l].key, cond: nodes[i] }; }
                }
            }
            if (nodes[i].type === 'loop') {
                var body = nodes[i].body || (nodes[i].body = []);
                for (var b = 0; b < body.length; b++) {
                    if (body[b].key === key) { return { node: body[b], list: body, index: b, lane: 'body', cond: nodes[i] }; }
                }
            }
        }
        return null;
    }

    /** La liste où s'insère une brique (rail, voie, corps de boucle). */
    function listAt(where) {
        if (where.lane === 'body') { return find(where.cond).node.body; }
        if (where.lane) { return laneNodes(find(where.cond).node, where.lane); }
        return state.def.nodes;
    }

    function uniquePieceName(base) {
        var names = {};
        allPieces().forEach(function (p) { names[p.name.toLowerCase()] = 1; });
        if (!names[base.toLowerCase()]) { return base; }
        for (var i = 2; i < 50; i++) {
            var n = base + ' ' + i;
            if (!names[n.toLowerCase()]) { return n; }
        }
        return base;
    }

    /** Titre et nom de pièce d'une création neuve, par nature. */
    var CREATE_DEFAULTS = {
        image: [T('Créer une image'), T('Image')],
        page:  [T('Créer une page web'), T('Page web')],
        map:   [T('Tracer un itinéraire'), T('Itinéraire')],
    };

    /** Premier outil de création en service d'une nature (sinon null). */
    function firstCreationTool(kind) {
        var t = (cat.creations || []).filter(function (x) { return x.kind === kind && !x.reason; })[0];
        return t ? t.id : null;
    }

    function defaults(type, where, preset) {
        var key = newKey(type.charAt(0));
        var onError = { retries: 1, then: 'stop' };
        if (type === 'create') {
            var kind = preset && CREATE_DEFAULTS[preset.kind] ? preset.kind : 'image';
            return {
                key: key, type: 'create', title: CREATE_DEFAULTS[kind][0], kind: kind, tool_id: firstCreationTool(kind),
                instruction: '', reads: [], options: kind === 'image' ? { format: '' } : (kind === 'page' ? { style: '' } : {}),
                review: false, output: { key: newKey('p'), name: uniquePieceName(CREATE_DEFAULTS[kind][1]), form: kind }, on_error: onError,
            };
        }
        if (type === 'step') {
            return {
                key: key, type: 'step', title: T('Nouvelle étape'), profile_id: null, llm: '', instruction: '', reads: [], sources: [],
                kb_ids: [], tool_ids: [], write: 'none', auto_send_mail: false, review: false, filters: blankFilters(),
                output: { key: newKey('p'), name: uniquePieceName(T('Résultat')), form: 'text' }, on_error: onError,
            };
        }
        if (type === 'action') {
            return { key: key, type: 'action', title: T('Nouvelle action'), target: '', tool_id: null, llm: '', instruction: '', reads: [], write: 'approve', auto_send_mail: false, filters: blankFilters(), on_error: onError };
        }
        if (type === 'route') {
            return {
                key: key, type: 'route', title: T('Quelle suite ?'), instruction: '', reads: [], fallback: 'ask', on_error: { retries: 1, then: 'stop' },
                routes: [newRoute(T('Voie 1'), []), newRoute(T('Voie 2'), [])],
            };
        }
        if (type === 'condition' && where && where.lane === 'body') {
            // Dans une boucle : une condition qui trie les éléments — si oui le
            // tour continue, si non l'élément est écarté (élément suivant).
            return {
                key: key, type: 'condition', title: T('Garder cet élément ?'), mode: 'rule', question: '', reads: [],
                rule: { join: 'all', items: [] }, doubt: 'no', yes: { nodes: [], then: 'continue' }, no: { nodes: [], then: 'skip' },
                on_error: { retries: 1, then: 'stop' },
            };
        }
        if (type === 'condition') {
            return {
                key: key, type: 'condition', title: T('On continue ?'), mode: 'question', question: '', reads: [],
                rule: { join: 'all', items: [] }, doubt: 'ask', yes: { nodes: [], then: 'continue' }, no: { nodes: [], then: 'stop' },
                on_error: { retries: 1, then: 'stop' },
            };
        }
        if (type === 'loop') {
            // Parcourt la dernière pièce parcourable ici : un fichier ou un
            // tableau (ses lignes), sinon une liste.
            var av = availableAt(where);
            var keys = Object.keys(av);
            var rowsAt = keys.filter(function (k) { return R.loopAccepts('rows', av[k], ''); });
            var listAt = keys.filter(function (k) { return R.loopAccepts('list', av[k], '') && av[k].form === 'list'; });
            var lkind = rowsAt.length && (!listAt.length || keys.indexOf(rowsAt[rowsAt.length - 1]) > keys.indexOf(listAt[listAt.length - 1])) ? 'rows' : 'list';
            var lover = lkind === 'rows' ? rowsAt[rowsAt.length - 1] : (listAt.length ? listAt[listAt.length - 1] : '');
            return {
                key: key, type: 'loop', title: T('Pour chaque élément'), mode: 'each', over: lover || '', over_field: '',
                source: newSource(lkind, {}), filter: { rule: { join: 'all', items: [] }, dedupe: '', require_label: true },
                on_item_error: 'continue', confirm: false, review_actions: false,
                max: Math.min(50, (cat.limits || {}).each_max || 200),
                item: { key: newKey('e'), name: uniquePieceName(lkind === 'rows' ? T('Ligne en cours') : T('Élément en cours')) }, body: [], collect: '',
                output: { key: newKey('r'), name: uniquePieceName(T('Résultats')) },
                until: { mode: 'question', question: '', reads: [], rule: { join: 'all', items: [] } }, exhausted: 'continue',
            };
        }
        if (type === 'wait') {
            return { key: key, type: 'wait', title: T('Attendre'), mode: 'delay', amount: 1, unit: 'days', piece: '', field: '', time: '09:00', offset: 0 };
        }
        if (type === 'notify') {
            return { key: key, type: 'notify', title: T('Me prévenir'), message: '' };
        }
        if (type === 'stop') {
            return { key: key, type: 'stop', title: T('Arrêter le workflow'), message: '', outcome: 'done' };
        }
        // Validation : relit la dernière pièce disponible à cet endroit.
        var avail = Object.keys(availableAt(where));
        return {
            key: key, type: 'gate', title: T('Relecture'), reviews: avail.length ? [avail[avail.length - 1]] : [], returns_to: '',
            can_correct: true, can_return: true, days: 7, note: '',
        };
    }

    /** Filtres vides (miroir de WorkflowFilters::blank). */
    function blankFilters() {
        return {
            mail: { from: '', subject: '', words: '', attachment: false, unread: false, days: 0 },
            drive: { folder: '', name: '', kind: '', days: 0 },
            recipients: '',
        };
    }

    /** Une voie neuve d'une décision : clé unique dans la brique. */
    function newRoute(label, existing) {
        var used = {};
        (existing || []).forEach(function (r) { used[r.key] = 1; });
        var k;
        do { k = 'v' + Math.random().toString(36).slice(2, 7); } while (used[k]);
        return { key: k, label: label, when: '', nodes: [], then: 'continue' };
    }

    /** Pièces disponibles à un point d'insertion (avant la brique qui y sera). */
    function availableAt(where) {
        if (!where) { return {}; }
        var probe = { key: '__probe__', type: 'gate', reviews: [] };
        var list = listAt(where);
        list.splice(where.index, 0, probe);
        var res = availableFor('__probe__');
        list.splice(where.index, 1);
        return res;
    }

    // ------------------------------------------------------------------
    // Rendu
    // ------------------------------------------------------------------

    function issuesByNode() {
        var m = {};
        state.issues.forEach(function (i) { (m[i.node] = m[i.node] || []).push(i); });
        return m;
    }

    function renderRail() {
        railHost.innerHTML = R.html(state.def, {
            catalog: cat, editable: true, selected: state.selected,
            issuesByNode: issuesByNode(), scheduleText: state.scheduleText,
        });
        // Pièce surlignée : reposée sur le rail neuf (briques qui la touchent comprises).
        R.highlight(railHost, state.highlight);
        // Les liaisons des embranchements : d'après la position réelle des briques.
        R.drawForks(railHost);
    }

    /** Lève la surbrillance d'une pièce (rail et liste du volet). */
    function clearHighlight() {
        if (!state.highlight) { return; }
        state.highlight = null;
        R.highlight(railHost, null);
        if (state.open) { renderInspector(); }
    }

    /** Sous le nom : l'objectif, qui ouvre les réglages du workflow. */
    function renderHead() {
        if (!objectiveLine) { return; }
        var o = String(state.def.objective || '').trim();
        objectiveLine.textContent = o || T('Ajouter un objectif');
        objectiveLine.classList.toggle('is-empty', !o);
    }

    function renderStatus() {
        var w = state.workflow;
        var blocks = state.issues.filter(function (i) { return i.level === 'block'; }).length;
        var html;
        if (!w.published) {
            html = '<span class="wf-pill">' + esc(T('Brouillon')) + '</span>';
        } else if (w.draft_changed) {
            html = '<span class="wf-pill wait">' + esc(T('Modifications non publiées')) + '</span>';
        } else {
            html = '<span class="wf-pill ok">' + esc(T('Publié · version {v}', { v: w.published_version })) + '</span>';
        }
        statusEl.innerHTML = html;
        var n = state.issues.length;
        issueCount.hidden = n === 0;
        issueCount.textContent = String(n);
        issueCount.classList.toggle('block', blocks > 0);
        overviewBtn.title = n ? T('À régler avant de publier') : '';
        publishBtn.disabled = blocks > 0 || (w.published && !w.draft_changed);
        publishBtn.title = blocks > 0 ? T('Réglez d’abord les points signalés « À régler ».') : '';
        if (launchBtn) { launchBtn.hidden = !w.published; }
        renderOrigin(blocks);
    }

    /**
     * Ouvert depuis la carte d'une conversation : une ligne dit où en est le
     * workflow (publié et prêt, à publier, à régler) et mène à la suite.
     */
    function renderOrigin(blocks) {
        if (!originText) { return; }
        var w = state.workflow;
        var start = state.def.start || {};
        var text;
        if (!w.published) {
            if (blocks > 0) {
                text = T('Créé depuis votre conversation. Réglez les points signalés dans « Réglages », puis publiez-le.');
            } else if (start.schedule || start.event) {
                text = T('Créé depuis votre conversation. Relisez-le : il démarrera seul une fois publié.');
            } else {
                text = T('Créé depuis votre conversation. Relisez-le, puis publiez-le pour le lancer.');
            }
        } else if (w.draft_changed) {
            text = T('Vos changements ne sont pas encore publiés : « Publier » les met en service.');
        } else {
            text = T('Créé depuis votre conversation et publié : prêt à lancer. Ajustez-le si besoin.');
        }
        originText.textContent = text;
    }

    function renderAll() {
        renderRail();
        renderHead();
        renderInspector();
        renderStatus();
    }

    // ------------------------------------------------------------------
    // Enregistrement du brouillon
    // ------------------------------------------------------------------

    function setSave(stateName, text) {
        saveEl.className = 'wf-save-state' + (stateName ? ' is-' + stateName : '');
        saveEl.textContent = text;
    }

    function scheduleSave(delay) {
        clearTimeout(state.saveTimer);
        setSave('saving', T('Modifications en cours…'));
        state.saveTimer = setTimeout(save, delay === undefined ? 700 : delay);
    }

    function save() {
        clearTimeout(state.saveTimer);
        if (state.saving) { state.pendingSave = true; return Promise.resolve(); }
        state.saving = true;
        setSave('saving', T('Enregistrement…'));
        return fetch('/workflows/' + state.workflow.id + '/draft', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ name: state.name, color: state.color, definition: state.def }),
        }).then(function (r) { return r.json().then(function (j) { return { r: r, j: j }; }); }).then(function (res) {
            state.saving = false;
            if (!res.r.ok || !res.j.ok) { throw new Error(res.j.error || 'save'); }
            state.issues = res.j.issues || [];
            state.estimate = res.j.estimate || null;
            state.workflow = res.j.workflow || state.workflow;
            state.scheduleText = state.workflow.schedule || state.scheduleText;
            setSave('', T('Brouillon enregistré'));
            renderRail();
            renderStatus();
            if (state.selected === null) { renderInspector(); } else { refreshNodeIssues(); }
            if (state.pendingSave) { state.pendingSave = false; return save(); }
            return null;
        }).catch(function () {
            state.saving = false;
            setSave('error', T('Enregistrement impossible — nouvel essai…'));
            state.saveTimer = setTimeout(save, 4000);
        });
    }

    /** Une modification : rail et résumé redessinés, brouillon enregistré. */
    function changed(opts) {
        opts = opts || {};
        renderRail();
        renderHead();
        if (opts.inspector) { renderInspector(); }
        scheduleSave();
    }

    // ------------------------------------------------------------------
    // Gestes sur le rail
    // ------------------------------------------------------------------

    /** Le volet : celui d'une brique, ou (sans brique) celui du workflow. */
    function setDrawer(open) {
        state.open = open;
        insHost.classList.toggle('is-open', open);
        insHost.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.classList.toggle('wf-drawer-on', open);
    }

    function spotlight(spot) {
        var sec = spot ? insHost.querySelector('[data-sec="' + spot + '"]') : null;
        if (!sec) { insHost.scrollTop = 0; return; }
        // Un choix replié (ou une livraison) s'ouvre : on vient y régler quelque chose.
        if (sec.classList.contains('wf-choice') && !sec.classList.contains('is-open')) { toggleChoice(spot, true); }
        var parentChoice = sec.parentNode && sec.parentNode.closest ? sec.parentNode.closest('.wf-choice') : null;
        if (parentChoice && !parentChoice.classList.contains('is-open')) { toggleChoice(parentChoice.getAttribute('data-sec'), true); }
        if (sec.classList.contains('wf-dl') && !sec.classList.contains('is-open')) {
            state.expanded[expandKey(spot)] = true;
            sec.classList.add('is-open');
            sec.querySelector('.wf-dl-body').hidden = false;
        }
        sec.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        sec.classList.remove('is-spot');
        void sec.offsetWidth;
        sec.classList.add('is-spot');
    }

    /**
     * Une brique (null = fermer le volet). Une pièce surlignée depuis le
     * volet part avec lui : fermé ou passé à une autre brique, le rail
     * revient en entier.
     */
    function select(key, spot) {
        if (key === null || key !== state.selected) { state.highlight = null; }
        state.selected = key;
        renderRail();
        renderInspector();
        setDrawer(key !== null);
        if (key !== null) { spotlight(spot); }
    }

    /** Les réglages du workflow. */
    function openOverview(spot) {
        if (!(state.open && state.selected === null)) { state.highlight = null; }
        state.selected = null;
        renderRail();
        renderInspector();
        setDrawer(true);
        spotlight(spot);
    }

    function insertNode(type, where, preset) {
        var node = defaults(type, where, preset);
        if (preset && preset.target) {
            node.target = preset.target;
            node.title = preset.title || node.title;
            var tg = (cat.targets || []).filter(function (t) { return t.key === preset.target; })[0];
            node.tool_id = tg && tg.kind === 'tool' && tg.tools[0] ? tg.tools[0].id : null;
        }
        var list = listAt(where);
        list.splice(where.index, 0, node);
        state.selected = node.key;
        changed({ inspector: true });
        setDrawer(true);
        spotlight('');
        var card = railHost.querySelector('[data-node="' + node.key + '"]');
        if (card) { card.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }

    function moveNode(key, dir) {
        var at = find(key);
        if (!at) { return; }
        var j = at.index + dir;
        if (j < 0 || j >= at.list.length) { return; }
        var n = at.list.splice(at.index, 1)[0];
        at.list.splice(j, 0, n);
        changed();
    }

    function duplicateNode(key) {
        var at = find(key);
        if (!at || at.node.type === 'stop') { return; }
        var copy = clone(at.node);
        var refresh = function (n) {
            n.key = newKey(n.type.charAt(0));
            if (n.output) { n.output.key = newKey('p'); n.output.name = uniquePieceName((n.output.name || n.title) + ' ' + T('(copie)')); }
            if (n.item) { n.item.key = newKey('e'); n.item.name = uniquePieceName(n.item.name + ' ' + T('(copie)')); }
            R.branchesOf(n).forEach(function (b) { (b.nodes || []).forEach(refresh); });
            if (n.type === 'loop') { (n.body || []).forEach(refresh); n.collect = ''; }
        };
        refresh(copy);
        copy.title = copy.title + ' ' + T('(copie)');
        at.list.splice(at.index + 1, 0, copy);
        state.selected = copy.key;
        changed({ inspector: true });
        setDrawer(true);
    }

    function deleteNode(key) {
        var at = find(key);
        if (!at) { return; }
        var label = at.node.title || T('cette brique');
        if (!window.confirm(T('Supprimer « {titre} » ? Les pièces qu’elle remet disparaissent du dossier.', { titre: label }))) { return; }
        at.list.splice(at.index, 1);
        if (state.selected === key) { state.selected = null; setDrawer(false); }
        changed({ inspector: true });
    }

    var addPop = null;
    var addAnchor = null;
    function closeAddMenu() {
        if (addPop) { addPop.remove(); addPop = null; }
        addAnchor = null;
        railHost.querySelectorAll('.wf-add.is-on').forEach(function (b) { b.classList.remove('is-on'); });
    }

    /**
     * Pose le menu d'ajout sous son bouton, au-dessus s'il manque la place
     * dessous ; sa liste se raccourcit quand aucun des deux côtés ne suffit.
     * Lu à l'écran (getBoundingClientRect, innerWidth : pixels d'écran),
     * écrit divisé par le zoom de l'interface (règle du dépôt).
     */
    function placeAddMenu() {
        if (!addPop || !addAnchor) { return; }
        if (!addAnchor.isConnected) { closeAddMenu(); return; }
        var z = window.miaUiZoom ? window.miaUiZoom() : 1;
        var list = addPop.querySelector('.wf-addpop-list');
        list.style.maxHeight = '';
        var bb = addAnchor.getBoundingClientRect();
        var gap = 6, edge = 8;
        var w = addPop.offsetWidth * z, h = addPop.offsetHeight * z;
        var below = window.innerHeight - bb.bottom - gap - edge;
        var above = bb.top - gap - edge;
        var down = h <= below || below >= above;
        var room = down ? below : above;
        if (h > room) {
            list.style.maxHeight = Math.max(140, list.offsetHeight * z - (h - room)) / z + 'px';
            h = addPop.offsetHeight * z;
        }
        var left = Math.max(edge, Math.min(bb.left, window.innerWidth - w - edge));
        var top = down ? bb.bottom + gap : Math.max(edge, bb.top - gap - h);
        addPop.style.left = left / z + 'px';
        addPop.style.top = top / z + 'px';
    }
    // Le menu suit son bouton quand la page ou le canevas défile (pas quand
    // c'est sa propre liste qui défile).
    window.addEventListener('scroll', function (e) {
        if (addPop && !addPop.contains(e.target)) { placeAddMenu(); }
    }, true);
    window.addEventListener('resize', placeAddMenu);

    /**
     * Les briques du menu d'ajout, rangées par ce qu'elles font : l'IA,
     * la logique, les outils (une entrée par outil relié : l'action est créée
     * dans cet outil), vous et le temps. Une voie ou un corps de boucle n'en
     * prend ni condition, ni décision, ni boucle.
     */
    function addMenuItems(where) {
        var inner = !!where.lane;
        // « Arrêter le workflow » termine une voie : une seule par voie,
        // jamais sur le rail ni dans une boucle.
        var condAt = where.lane && where.lane !== 'body' ? find(where.cond) : null;
        var canStop = !!condAt && !laneNodes(condAt.node, where.lane).some(function (x) { return x.type === 'stop'; });
        var K = L.kinds || {};
        var H = L.kindHelp || {};
        var h = cat.hues || {};
        var item = function (kind, extra) {
            return Object.assign({ kind: kind, label: K[kind] || kind, help: H[kind] || '', ic: icon(kind), hue: h[kind] }, extra || {});
        };
        // Créer : une entrée par nature dont un outil est ouvert à la personne.
        var CK = L.createKinds || {};
        var CH = L.createHelp || {};
        var creates = ['image', 'page', 'map'].map(function (k) {
            var tools = (cat.creations || []).filter(function (t) { return t.kind === k; });
            if (!tools.length) { return null; }
            var usable = tools.some(function (t) { return !t.reason; });
            return item('create', { label: CK[k] || k, help: usable ? (CH[k] || '') : (tools[0].reason || ''), ic: icon(k), ckind: k, off: !usable });
        }).filter(Boolean);
        if (!creates.length) {
            creates = [item('create', { label: T('Créer une image ou une page'), help: T('Aucun outil de création ne vous est ouvert : un administrateur les ouvre à vos groupes dans Outils.'), off: true, disabled: true })];
        }
        var groups = [
            { key: 'ai', items: [item('step'), inner ? null : item('route')] },
            { key: 'create', items: creates },
            { key: 'logic', items: inner
                ? [where.lane === 'body' ? item('condition', { help: T('Trier les éléments : si non, passer à l’élément suivant.') }) : null, item('wait'), canStop ? item('stop') : null]
                : [item('condition'), item('loop'), item('wait')] },
            { key: 'tools', items: (cat.targets || []).map(function (t) {
                return item('action', {
                    label: t.label, help: t.available ? (t.verb || (K.action || '')) : (t.reason || ''), ic: t.icon,
                    target: t.key, title: t.verb || t.label, off: !t.available,
                });
            }).concat([item('action', { label: T('Autre action'), help: H.action || '' })]) },
            { key: 'people', items: [item('gate'), item('notify')] },
        ];
        groups.forEach(function (g) { g.items = g.items.filter(Boolean); });
        return groups.filter(function (g) { return g.items.length; });
    }

    function openAddMenu(btn, where) {
        closeAddMenu();
        btn.classList.add('is-on');
        var head = where.lane === 'body' ? T('Ajouter dans la boucle')
            : where.lane ? T('Ajouter dans cette voie') : T('Ajouter une brique ici');
        var groups = addMenuItems(where);
        var flat = [];
        addPop = document.createElement('div');
        addPop.className = 'wf-addpop';
        addPop.setAttribute('role', 'menu');
        addPop.innerHTML = '<div class="h">' + esc(head) + '</div>'
            + '<label class="wf-addpop-search">' + icon('search') + '<input type="search" placeholder="' + esc(T('Chercher une brique ou un outil…')) + '" aria-label="' + esc(T('Chercher une brique ou un outil…')) + '"></label>'
            + '<div class="wf-addpop-list">' + groups.map(function (g) {
                return '<section data-group="' + g.key + '"><h5>' + icon(({ ai: 'spark', create: 'create', logic: 'logic', tools: 'tool' })[g.key] || 'people')
                    + esc((L.groups || {})[g.key] || g.key) + '</h5>' + g.items.map(function (it) {
                        var i = flat.push(it) - 1;
                        return '<button type="button" role="menuitem" data-i="' + i + '" style="--hue:' + esc(it.hue || 'var(--accent)') + '"' + (it.off ? ' class="is-off"' : '') + '>'
                            + '<span class="wf-node-ic">' + it.ic + '</span><b>' + esc(it.label) + '</b><small>' + esc(it.help) + '</small></button>';
                    }).join('') + '</section>';
            }).join('') + '<p class="wf-addpop-none" hidden>' + esc(T('Aucune brique ne correspond.')) + '</p></div>';
        // Posé sur la page : dans le canevas, qui défile en largeur (donc en
        // hauteur aussi), le menu était rogné au bas du rail.
        document.body.appendChild(addPop);
        addAnchor = btn;
        placeAddMenu();
        addPop.addEventListener('click', function (e) {
            var b = e.target.closest('[data-i]');
            if (!b) { return; }
            var it = flat[+b.getAttribute('data-i')];
            if (it.disabled) { return; }
            closeAddMenu();
            insertNode(it.kind, where, it.target ? { target: it.target, title: it.title } : (it.ckind ? { kind: it.ckind } : null));
        });
        var search = addPop.querySelector('input');
        search.addEventListener('input', function () {
            var q = fold(search.value);
            var any = false;
            addPop.querySelectorAll('[data-i]').forEach(function (b) {
                var it = flat[+b.getAttribute('data-i')];
                var hit = !q || fold(it.label + ' ' + it.help).indexOf(q) >= 0;
                b.hidden = !hit;
                any = any || hit;
            });
            addPop.querySelectorAll('section').forEach(function (sec) { sec.hidden = !sec.querySelector('[data-i]:not([hidden])'); });
            addPop.querySelector('.wf-addpop-none').hidden = any;
        });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var first = addPop.querySelector('[data-i]:not([hidden])');
                if (first) { e.preventDefault(); first.click(); }
            }
        });
        // Au doigt, le clavier ne surgit pas : le champ ne prend le focus qu'à la souris.
        if (window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches) { search.focus(); }
    }

    /** Minuscules sans accents (recherche du menu, des pièces). */
    function fold(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    railHost.addEventListener('click', function (e) {
        var tool = e.target.closest('[data-tool]');
        if (tool) {
            e.stopPropagation();
            var k = tool.getAttribute('data-node');
            var act = tool.getAttribute('data-tool');
            if (act === 'up') { moveNode(k, -1); }
            if (act === 'down') { moveNode(k, 1); }
            if (act === 'dup') { duplicateNode(k); }
            if (act === 'del') { deleteNode(k); }
            return;
        }
        var add = e.target.closest('[data-add]');
        if (add) {
            e.stopPropagation();
            if (add.getAttribute('data-add') === 'lane') {
                var cond = find(add.getAttribute('data-cond'));
                var lane = add.getAttribute('data-lane');
                var arr = laneNodes(cond.node, lane);
                // Une voie qui finit par « Arrêter le workflow » : on ajoute avant elle.
                var stopAt = arr.map(function (x) { return x.type; }).indexOf('stop');
                openAddMenu(add, { lane: lane, cond: cond.node.key, index: stopAt >= 0 ? stopAt : arr.length });
            } else if (add.getAttribute('data-add') === 'body') {
                var loop = find(add.getAttribute('data-loop'));
                openAddMenu(add, { lane: 'body', cond: loop.node.key, index: (loop.node.body || []).length });
            } else {
                openAddMenu(add, { lane: null, index: parseInt(add.getAttribute('data-index'), 10) });
            }
            return;
        }
        var piece = e.target.closest('.wf-dot, .wf-out');
        if (piece && piece.getAttribute('data-piece')) {
            e.stopPropagation();
            var pk = piece.getAttribute('data-piece');
            state.highlight = state.highlight === pk ? null : pk;
            R.highlight(railHost, state.highlight);
            if (state.selected === null && state.open) { renderInspector(); }
            return;
        }
        var card = e.target.closest('[data-node]');
        if (card) { select(card.getAttribute('data-node')); return; }
        closeAddMenu();
        // Un clic dans le vide du canevas lève la surbrillance d'une pièce.
        clearHighlight();
    });
    railHost.addEventListener('keydown', function (e) {
        var card = e.target.closest('.wf-brick[data-node]');
        if (card && (e.key === 'Enter' || e.key === ' ') && e.target === card) {
            e.preventDefault();
            select(card.getAttribute('data-node'));
        }
    });
    document.addEventListener('click', function (e) {
        if (addPop && !addPop.contains(e.target) && !e.target.closest('[data-add]')) { closeAddMenu(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (addPop) { closeAddMenu(); return; }
            if (e.target && e.target.closest && e.target.closest('input, textarea, select')) { return; }
            if (state.open) { select(null); } else { clearHighlight(); }
        }
    });
    railHost.addEventListener('mouseover', function (e) {
        var p = e.target.closest('[data-piece]');
        if (state.highlight || !p) { return; }
        R.highlight(railHost, p.getAttribute('data-piece'));
    });
    railHost.addEventListener('mouseout', function (e) {
        if (state.highlight) { return; }
        if (e.target.closest('[data-piece]')) { R.highlight(railHost, null); }
    });

    // ------------------------------------------------------------------
    // Citations du dossier : @{clé} ⇄ @[Nom], colorées par nature
    // ------------------------------------------------------------------

    var FIELD_SEP = ' › ';
    function fieldColor() { return (cat.colors || {}).field || '#ec4899'; }

    function toDisplay(text) {
        var pm = pieceMap();
        return String(text || '').replace(/@\{([a-z0-9_]{1,24})(?:\.([a-z0-9_]{1,24}))?\}/g, function (m, k, f) {
            var p = pm[k];
            if (!p) { return m; }
            if (f) {
                var fd = (p.fields || []).filter(function (x) { return x.key === f; })[0];
                return fd ? '@[' + p.name + FIELD_SEP + fd.label + ']' : m;
            }
            return '@[' + p.name + ']';
        });
    }
    function fromDisplay(text) {
        var byName = {};
        allPieces().forEach(function (p) {
            byName[p.name.toLowerCase()] = p.key;
            (p.fields || []).forEach(function (f) { byName[(p.name + FIELD_SEP + f.label).toLowerCase()] = p.key + '.' + f.key; });
        });
        return String(text || '').replace(/@\[([^\]\n]{1,160})\]/g, function (m, name) {
            var k = byName[name.trim().toLowerCase()];
            return k ? '@{' + k + '}' : m;
        });
    }
    function getPath(path) {
        var parts = path.split('.');
        var obj = parts[0] === 'node' ? current() : parts[0] === 'start' ? state.def.start : parts[0] === 'end' ? state.def.end : state.def;
        for (var i = 1; i < parts.length && obj; i++) { obj = obj[parts[i]]; }
        return obj;
    }

    /**
     * Ce qu'un champ peut citer à un point du workflow, filtré par ce qu'il
     * accepte : `any` (pièces et champs de Fiche — une consigne, un message),
     * `value` (une valeur courte : champs de Fiche, pièces de texte comme
     * l'élément en cours d'une boucle — un filtre, un dossier), `email`
     * (un champ email seulement — un destinataire).
     */
    function mentionables(at, accept) {
        var avail = at === 'start' ? {} : availableFor(at);
        var out = [];
        Object.keys(avail).forEach(function (k) {
            var p = avail[k];
            if (accept === 'any' || (accept === 'value' && p.form === 'text')) {
                out.push({ key: k, name: p.name, color: p.color, form: p.form === 'file' ? 'file' : p.form, item: !!p.item });
            }
            (p.fields || []).forEach(function (f) {
                if (accept === 'any' || accept === 'value' || (accept === 'email' && f.type === 'email')) {
                    out.push({ key: k + '.' + f.key, name: p.name + FIELD_SEP + f.label, color: fieldColor(), form: 'field', parent: p.name, label: f.label, ftype: f.type });
                }
            });
        });
        return out;
    }

    /** Le texte d'un champ, ses citations surlignées (calque sous le champ). */
    function hlHtml(text, at, accept) {
        var all = {};
        mentionables(at, 'any').forEach(function (m) { all[m.name.toLowerCase()] = m; });
        var ok = {};
        mentionables(at, accept).forEach(function (m) { ok[m.name.toLowerCase()] = 1; });
        var out = '';
        var last = 0;
        text = String(text || '');
        text.replace(/@\[([^\]\n]{1,160})\]/g, function (m, name, off) {
            out += esc(text.slice(last, off));
            var k = name.trim().toLowerCase();
            var it = all[k];
            out += '<mark class="' + (it && ok[k] ? '' : 'is-bad') + '" style="--c:' + esc(it ? it.color : '#ef4444') + '">' + esc(m) + '</mark>';
            last = off + m.length;
            return m;
        });
        // Une espace finale : la dernière ligne vide d'un champ garde sa hauteur.
        return out + esc(text.slice(last)) + ' ';
    }

    /**
     * Champ qui cite le dossier — le même partout (consignes, critères,
     * filtres, objets et messages de livraison, destinataires). « @ » ou le
     * bouton @ ouvre la liste des pièces ; chaque citation est surlignée de
     * la couleur de sa nature. o : id, path (chemin de la valeur), at (point
     * du workflow où le champ lit le dossier), accept (any | value | email),
     * input (une ligne), rows, placeholder.
     */
    function mentionField(o) {
        var accept = o.accept || 'any';
        var val = toDisplay(getPath(o.path) || '');
        var attrs = ' id="' + esc(o.id) + '" data-mention-path="' + esc(o.path) + '" data-mention-at="' + esc(o.at) + '" data-accept="' + accept + '"'
            + ' placeholder="' + esc(o.placeholder || '') + '"' + (o.input ? ' autocomplete="off" spellcheck="false"' : '');
        var field = o.input
            ? '<input class="wf-input"' + attrs + ' value="' + esc(val) + '">'
            : '<textarea class="wf-textarea"' + attrs + (o.rows ? ' style="min-height:' + o.rows + 'px"' : '') + '>' + esc(val) + '</textarea>';
        var title = accept === 'email' ? T('Citer une adresse du dossier') : T('Citer une pièce du dossier');
        return '<div class="wf-mention-wrap' + (o.input ? ' is-line' : '') + '"><div class="wf-hl" aria-hidden="true">' + hlHtml(val, o.at, accept) + '</div>'
            + field + '<button type="button" class="wf-at" data-act="at" data-val="' + esc(o.id) + '" title="' + esc(title) + '" aria-label="' + esc(title) + '">' + icon('at') + '</button></div>';
    }

    function paintHl(el) {
        var hl = el.parentNode && el.parentNode.querySelector('.wf-hl');
        if (!hl) { return; }
        hl.innerHTML = hlHtml(el.value, el.getAttribute('data-mention-at'), el.getAttribute('data-accept') || 'any');
        autoGrow(el);
        syncHl(el);
    }
    /** Un champ long grandit avec son texte : pas d'ascenseur, le calque reste aligné. */
    function autoGrow(el) {
        if (el.tagName !== 'TEXTAREA') { return; }
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    function syncHl(el) {
        var hl = el.parentNode && el.parentNode.querySelector('.wf-hl');
        if (hl) { hl.scrollTop = el.scrollTop; hl.scrollLeft = el.scrollLeft; }
    }

    var mentionPop = null;
    function closeMention() { if (mentionPop) { mentionPop.remove(); mentionPop = null; } }

    /** Le jeton « @… » en cours de frappe, avant le curseur. */
    function mentionToken(el) {
        var before = el.value.slice(0, el.selectionStart);
        return before.match(/@\[?([^\]\n@]{0,40})$/);
    }

    function mentionInput(el) {
        var m = mentionToken(el);
        if (!m) { closeMention(); return; }
        openMention(el, m[1], m[0].length);
    }

    /** La liste des pièces citables, groupées par pièce (ses champs dessous), filtrée par la frappe. */
    function openMention(el, query, replaceLen) {
        var accept = el.getAttribute('data-accept') || 'any';
        var all = mentionables(el.getAttribute('data-mention-at'), accept);
        var q = fold(query);
        var list = all.filter(function (p) { return !q || fold(p.name).indexOf(q) >= 0; });
        closeMention();
        mentionPop = document.createElement('div');
        mentionPop.className = 'wf-mention-pop';
        mentionPop.setAttribute('role', 'listbox');
        var F = L.forms || {};
        var head = accept === 'email' ? T('Adresses du dossier') : T('Pièces du dossier disponibles ici');
        mentionPop.innerHTML = '<div class="h">' + icon('at') + esc(head) + '</div>' + (list.length ? list.slice(0, 40).map(function (p, i) {
            var nature = p.form === 'field' ? T('Champ · {type}', { type: (L.ficheTypes || {})[p.ftype] || p.ftype }) : (p.item ? T('Élément en cours') : (F[p.form] || p.form));
            var name = p.form === 'field' ? '<span class="wf-mp-parent">' + esc(p.parent) + FIELD_SEP + '</span>' + esc(p.label) : esc(p.name);
            return '<button type="button" role="option" data-k="' + esc(p.key) + '" class="' + (p.form === 'field' ? 'is-field' : '') + (i === 0 ? ' is-on' : '') + '" style="--c:' + esc(p.color) + '">'
                + '<span class="wf-mp-ic">' + icon(p.form === 'field' ? 'field' : (p.form === 'file' ? 'file' : p.form)) + '</span><span class="wf-mp-name">' + name + '</span><small>' + esc(nature) + '</small></button>';
        }).join('') : '<p class="wf-mp-none">' + esc(accept === 'email'
            ? T('Aucun champ email disponible ici : ajoutez un champ Email au départ, ou une Fiche avec un champ Email.')
            : T('Aucune pièce disponible à cet endroit : elles arrivent du départ et des étapes d’avant.')) + '</p>');
        placeMention(el);
        var byKey = {};
        all.forEach(function (p) { byKey[p.key] = p; });
        mentionPop.addEventListener('mousedown', function (e) {
            var b = e.target.closest('[data-k]');
            if (!b) { return; }
            e.preventDefault();
            insertMention(el, byKey[b.getAttribute('data-k')], replaceLen);
        });
    }

    /**
     * La liste se pose dans le VOLET, sous le champ, et reste dans sa largeur :
     * attachée au champ, elle serait aussi étroite que lui (un filtre sur une
     * demi-colonne) ou déborderait du volet. Pixels d'écran divisés par le
     * zoom de l'interface.
     */
    function placeMention(el) {
        insHost.appendChild(mentionPop);
        var z = window.miaUiZoom ? window.miaUiZoom() : 1;
        var hb = insHost.getBoundingClientRect();
        var fb = el.getBoundingClientRect();
        var width = Math.min(340, insHost.clientWidth - 24);
        mentionPop.style.width = width + 'px';
        var left = (fb.left - hb.left) / z;
        mentionPop.style.left = Math.max(12, Math.min(left, insHost.clientWidth - width - 12)) + 'px';
        mentionPop.style.top = ((fb.bottom - hb.top) / z + insHost.scrollTop + 4) + 'px';
    }

    function insertMention(el, piece, replaceLen) {
        if (!piece) { return; }
        var pos = el.selectionStart;
        var token = '@[' + piece.name + '] ';
        el.value = el.value.slice(0, pos - replaceLen) + token + el.value.slice(pos);
        var caret = pos - replaceLen + token.length;
        el.setSelectionRange(caret, caret);
        closeMention();
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.focus();
    }

    // ------------------------------------------------------------------
    // Volet : sections, choix repliés
    // ------------------------------------------------------------------

    function sec(id, title, body, small, ic) {
        return '<section class="wf-sec" data-sec="' + id + '"><h4>' + (ic ? '<span class="wf-sec-ic">' + icon(ic) + '</span>' : '') + esc(title)
            + (small ? ' <small>' + esc(small) + '</small>' : '') + '</h4>' + body + '</section>';
    }

    function expandKey(id) { return (state.selected === null ? 'wf' : state.selected) + ':' + id; }

    /**
     * Un CHOIX du volet, replié par défaut : son titre et ce qui est retenu,
     * sur une ligne ; déplié, de quoi le changer. o : id, title, ic,
     * summary (HTML), body (HTML), empty (rien de retenu), warn (à régler).
     */
    function choice(o) {
        var open = !!state.expanded[expandKey(o.id)];
        return '<section class="wf-sec wf-choice' + (open ? ' is-open' : '') + (o.warn ? ' is-warn' : '') + (o.sub ? ' is-sub' : '') + '" data-sec="' + esc(o.id) + '">'
            + '<button type="button" class="wf-choice-head" data-act="expand" data-val="' + esc(o.id) + '" aria-expanded="' + (open ? 'true' : 'false') + '">'
            + (o.ic ? '<span class="wf-choice-ic">' + icon(o.ic) + '</span>' : '')
            + '<span class="wf-choice-txt"><span class="wf-choice-title">' + esc(o.title) + '</span>'
            + '<span class="wf-choice-sum' + (o.empty ? ' is-empty' : '') + '">' + o.summary + '</span></span>'
            + '<span class="wf-choice-chev">' + icon('chev') + '</span></button>'
            + '<div class="wf-choice-body"' + (open ? '' : ' hidden') + '>' + o.body + '</div></section>';
    }

    /** Ouvre ou ferme un choix sans redessiner le volet (le focus et le défilement restent). */
    function toggleChoice(id, force) {
        var k = expandKey(id);
        var open = force === undefined ? !state.expanded[k] : force;
        state.expanded[k] = open;
        var secEl = insHost.querySelector('.wf-choice[data-sec="' + id + '"]');
        if (!secEl) { return; }
        secEl.classList.toggle('is-open', open);
        secEl.querySelector('.wf-choice-head').setAttribute('aria-expanded', open ? 'true' : 'false');
        secEl.querySelector('.wf-choice-body').hidden = !open;
    }

    /** La section où se règle un point « À régler » (cible de WorkflowSpec::issues). */
    function secFor(target) {
        var t = String(target || '');
        if (t.indexOf('field:') === 0) { return 'fields'; }
        if (t.indexOf('delivery:') === 0) { return t; }
        var map = {
            llm: current() && current().type === 'action' ? 'llm' : 'profile', schedule: 'trigger', returns_to: 'options', collect: 'output',
            filters: current() && current().type === 'step' ? 'sources' : 'filters', nodes: 'issues',
        };
        return map[t] || t;
    }

    function chip(p, extra) {
        return '<span class="wf-piece" style="--c:' + esc(p.color) + '">' + icon(p.form === 'file' ? 'file' : p.form) + '<span>' + esc(p.name) + '</span>' + (extra || '') + '</span>';
    }
    /** Résumé : les pièces retenues en puces, sinon une phrase. */
    function chipsSummary(keys, none) {
        var pm = pieceMap();
        var list = (keys || []).filter(function (k) { return pm[k]; });
        return list.length ? '<span class="wf-sum-chips">' + list.map(function (k) { return chip(pm[k]); }).join('') + '</span>' : esc(none);
    }
    function tile(opts) {
        return '<button type="button" class="wf-tile' + (opts.col ? ' col' : '') + (opts.on ? ' is-on' : '') + (opts.off ? ' is-off' : '') + '"'
            + (opts.off ? ' aria-disabled="true"' : '') + ' data-act="' + esc(opts.act) + '" data-val="' + esc(opts.val) + '"'
            + (opts.title ? ' title="' + esc(opts.title) + '"' : '') + '>'
            + (opts.ic !== undefined ? '<span class="t-ic">' + opts.ic + '</span>' : '')
            + '<div><b>' + esc(opts.label) + '</b>' + (opts.small ? '<small>' + esc(opts.small) + '</small>' : '') + '</div>'
            + '<span class="chk">' + (opts.on ? icon('check') : '') + '</span></button>';
    }
    function radio(act, val, on, title, small, off) {
        return '<button type="button" class="wf-radio' + (on ? ' is-on' : '') + (off ? ' is-off' : '') + '" data-act="' + esc(act) + '" data-val="' + esc(val) + '"'
            + (off ? ' aria-disabled="true"' : '') + '><i></i><b>' + esc(title) + '</b>' + (small ? '<small>' + esc(small) + '</small>' : '') + '</button>';
    }
    function check(bind, on, label, small) {
        return '<label class="wf-check"><input type="checkbox" data-bind="' + esc(bind) + '"' + (on ? ' checked' : '') + '><span>' + esc(label)
            + (small ? '<small>' + esc(small) + '</small>' : '') + '</span></label>';
    }
    function chipToggles(act, keys, onKeys) {
        var pm = pieceMap();
        if (!keys.length) { return '<p class="wf-hint">' + esc(T('Aucune pièce disponible à cet endroit.')) + '</p>'; }
        return '<div class="wf-chips">' + keys.map(function (k) {
            var p = pm[k];
            var on = onKeys.indexOf(k) >= 0;
            return '<button type="button" class="wf-piece wf-chip-toggle' + (on ? '' : ' is-off') + '" data-act="' + act + '" data-val="' + esc(k) + '" style="--c:' + esc(p.color) + '">'
                + icon(p.form === 'file' ? 'file' : p.form) + '<span>' + esc(p.name) + '</span></button>';
        }).join('') + '</div>';
    }
    function issuesFor(key) { return state.issues.filter(function (i) { return i.node === key; }); }
    function issuesHtml(key) {
        var list = issuesFor(key);
        if (!list.length) { return ''; }
        return '<section class="wf-sec" data-sec="issues"><h4>' + esc(T('À régler')) + '</h4>' + list.map(function (i) {
            return '<button type="button" class="wf-todo' + (i.level === 'block' ? ' block' : '') + '" data-act="spot" data-val="' + esc(secFor(i.target)) + '">' + icon('alert') + '<div>' + esc(i.text) + '</div></button>';
        }).join('') + '</section>';
    }
    function refreshNodeIssues() {
        var box = insHost.querySelector('[data-sec="issues"]');
        var html = issuesHtml(state.selected);
        if (box) {
            if (html) { box.outerHTML = html; } else { box.remove(); }
        } else if (html) {
            var head = insHost.querySelector('.wf-ins-head');
            if (head) { head.insertAdjacentHTML('afterend', html); }
        }
    }

    function headFor(kind, hue, titleBind, titleVal) {
        return '<div class="wf-ins-head" style="--hue:' + esc(hue) + '"><div class="wf-ins-kicker"><span class="wf-node-ic">' + icon(kind) + '</span>'
            + esc((L.kinds || {})[kind] || kind) + closeBtn() + '</div>'
            + (titleBind ? '<input class="wf-ins-title" data-bind="' + titleBind + '" value="' + esc(titleVal) + '" aria-label="' + esc(T('Nom de la brique')) + '">' : '')
            + ((L.kindHelp || {})[kind] ? '<p>' + esc(L.kindHelp[kind]) + '</p>' : '')
            + '</div>';
    }

    function closeBtn() {
        return '<button type="button" class="wf-drawer-x" data-act="deselect" title="' + esc(T('Fermer')) + '" aria-label="' + esc(T('Fermer')) + '">' + icon('x') + '</button>';
    }

    function renderInspector() {
        closeMention();
        var key = state.selected;
        if (key === null) { insHost.innerHTML = overview(); return; }
        if (key === 'start') { insHost.innerHTML = startInspector(); afterStart(); afterInspector(); return; }
        if (key === 'end') { insHost.innerHTML = endInspector(); afterInspector(); return; }
        var at = find(key);
        if (!at) { state.selected = null; insHost.innerHTML = overview(); return; }
        var n = at.node;
        var inspect = { step: stepInspector, action: actionInspector, create: createInspector, condition: conditionInspector, route: routeInspector, loop: loopInspector, gate: gateInspector, wait: waitInspector, notify: notifyInspector, stop: stopInspector }[n.type] || gateInspector;
        insHost.innerHTML = inspect(n) + brickTools(n, at);
        afterInspector();
    }

    /** Après un rendu du volet : les champs longs prennent la hauteur de leur texte. */
    function afterInspector() {
        insHost.querySelectorAll('textarea[data-mention-path]').forEach(autoGrow);
    }

    /** Pied du volet : déplacer, dupliquer, supprimer la brique (au doigt, la carte n'a pas d'outils au survol). */
    function brickTools(n, at) {
        var b = function (act, ic, label, extra) {
            return '<button type="button" class="wf-btn sm' + (extra || '') + '" data-tool="' + act + '" data-node="' + esc(n.key) + '">' + icon(ic) + esc(label) + '</button>';
        };
        return sec('brick', T('Cette brique'), '<div class="wf-chips">'
            + (at.index > 0 ? b('up', 'up', T('Monter')) : '')
            + (at.index < at.list.length - 1 ? b('down', 'down', T('Descendre')) : '')
            + (n.type === 'stop' ? '' : b('dup', 'copy', T('Dupliquer'))) + b('del', 'trash', T('Supprimer'), ' danger') + '</div>');
    }

    function overview() {
        var pm = allPieces();
        var readers = {};
        var flat = [];
        (state.def.nodes || []).forEach(function (n) {
            flat.push(n);
            R.branchesOf(n).forEach(function (b) { flat = flat.concat(b.nodes); });
            if (n.type === 'loop') { flat = flat.concat(n.body || []); }
        });
        flat.forEach(function (n) { R.readsOf(state.def, n).forEach(function (k) { readers[k] = (readers[k] || 0) + 1; }); });
        var producer = function (p) {
            if (p.producer === 'start') { return T('Départ'); }
            var at = find(p.producer);
            return at ? at.node.title : '';
        };
        var dossier = pm.length ? '<ul class="wf-dossier">' + pm.map(function (p) {
            var n = readers[p.key] || 0;
            return '<li data-hl="' + esc(p.key) + '" style="--c:' + esc(p.color) + '"' + (state.highlight === p.key ? ' class="is-hl"' : '') + '><span class="dl"></span><span class="di">' + icon(p.form === 'file' ? 'file' : p.form) + '</span><div><b>'
                + esc(p.name) + '</b><small>' + esc((L.forms || {})[p.form] || p.form) + ' · ' + esc(T('remise par {qui}', { qui: producer(p) }))
                + ' · ' + esc(window.tn ? window.tn(n, 'lue par {n} brique', 'lue par {n} briques') : n) + '</small></div></li>';
        }).join('') + '</ul>' : '<p class="wf-hint">' + esc(T('Aucune pièce pour l’instant : le départ et les étapes en remettront.')) + '</p>';

        var issues = state.issues.length
            ? state.issues.map(function (i) {
                return '<button type="button" class="wf-todo' + (i.level === 'block' ? ' block' : '') + '" data-act="goto" data-val="' + esc(i.node) + '" data-spot="' + esc(secFor(i.target)) + '">' + icon('alert') + '<div>'
                    + esc(labelOf(i.node)) + '<small>' + esc(i.text) + '</small></div></button>';
            }).join('')
            : '<div class="wf-allgood">' + icon('check') + esc(T('Rien à régler : le workflow peut être publié.')) + '</div>';

        var est = state.estimate
            ? '<div class="wf-est"><b>' + esc(T('≈ {min} à {max} crédits par exécution', { min: state.estimate.min, max: state.estimate.max })) + '</b><span>' + esc(T('Estimé sur les réponses récentes des mêmes modèles.')) + '</span></div>'
            : '<div class="wf-est"><span>' + esc(T('Coût estimé disponible après quelques conversations sur ces modèles.')) + '</span></div>';
        var writes = 0;
        var gates = 0;
        flat.forEach(function (n) { if (n.type === 'action' || (n.type === 'step' && n.write !== 'none')) { writes++; } if (n.type === 'gate' || n.review) { gates++; } });
        var safety = '<div class="wf-est"><b>' + esc(writes ? T('Ce qui agit se voit sur le rail') : T('Rien ne part sans vous')) + '</b><span>'
            + esc(T('{v} · {a}', {
                v: window.tn ? window.tn(gates, '{n} validation', '{n} validations') : gates,
                a: window.tn ? window.tn(writes, '{n} brique qui agit dans un outil', '{n} briques qui agissent dans un outil') : writes,
            })) + '</span></div>';

        return '<div class="wf-ins-head"><div class="wf-ins-kicker">' + icon('settings') + esc(T('Réglages')) + closeBtn() + '</div>'
            + '<h3>' + esc(state.name || '') + '</h3></div>'
            + sec('objective', T('Objectif'), '<input class="wf-input" data-bind="def.objective" maxlength="300" value="' + esc(state.def.objective || '') + '" placeholder="' + esc(T('En une phrase : ce que ce workflow produit, et pour qui.')) + '">')
            + sec('issues', T('À régler avant de publier'), issues)
            + sec('dossier', T('Le dossier'), '<p class="wf-hint">' + esc(T('Les pièces que les briques se passent. Survolez-en une pour voir son trajet ; sur le rail, des paquets de sa couleur descendent les liens qu’elle emprunte.')) + '</p>'
                + dossier + legend(), '', 'inbox')
            + sec('cost', T('Une exécution'), est + safety)
            + sec('notify', T('Prévenir'), check('def.notify', state.def.notify, T('Me prévenir sur mes appareils'), T('À chaque validation attendue, à la fin, en cas d’arrêt.')))
            // Dupliquer = droit « Workflows — Créer », Supprimer = « Supprimer »
            // (Personnes → Rôles) : D.can, posé par le serveur.
            + ((can.create || can.delete) ? sec('danger', T('Ce workflow'), '<div class="wf-chips">'
                + (can.create ? '<button type="button" class="wf-btn sm" data-act="duplicate">' + icon('copy') + esc(T('Dupliquer')) + '</button>' : '')
                + (can.delete ? '<button type="button" class="wf-btn sm danger" data-act="delete">' + icon('trash') + esc(T('Supprimer')) + '</button>' : '')
                + '</div>') : '');
    }

    /** Une couleur = une nature : la légende du dossier. */
    function legend() {
        var F = L.forms || {};
        var C = cat.colors || {};
        var natures = ['text', 'list', 'table', 'fiche', 'document', 'file'].concat((cat.creations || []).length ? ['image', 'page', 'map'] : []);
        return '<div class="wf-legend" aria-label="' + esc(T('Couleurs des pièces')) + '">' + natures.map(function (f) {
            return '<span style="--c:' + esc(C[f] || '#999') + '">' + icon(f) + esc(F[f] || f) + '</span>';
        }).join('') + '<span style="--c:' + esc(fieldColor()) + '">' + icon('field') + esc(T('Champ d’une Fiche')) + '</span></div>';
    }

    function labelOf(key) {
        if (key === 'start') { return T('Départ'); }
        if (key === 'end') { return T('Livrable'); }
        var at = find(key);
        return at ? (at.node.title || T('Sans titre')) : key;
    }

    // --- Départ
    function startInspector() {
        var s = state.def.start;
        var types = L.fieldTypes || {};
        var rows = (s.fields || []).map(function (f, i) {
            var opts = Object.keys(types).map(function (t) { return '<option value="' + t + '"' + (t === f.type ? ' selected' : '') + '>' + esc(types[t]) + '</option>'; }).join('');
            var extra = '';
            if (f.type === 'choice') {
                extra += '<input class="wf-input" data-field="' + i + '" data-prop="options" placeholder="' + esc(T('Options, séparées par des virgules')) + '" value="' + esc((f.options || []).join(', ')) + '">';
            }
            if (f.type !== 'file') {
                extra += '<input class="wf-input" data-field="' + i + '" data-prop="default" placeholder="' + esc(T('Valeur par défaut')) + '" value="' + esc(f.default || '') + '">';
            }
            extra += '<input class="wf-input" data-field="' + i + '" data-prop="help" placeholder="' + esc(T('Aide sous le champ')) + '" value="' + esc(f.help || '') + '">';
            return '<div class="wf-field-row wide"><input class="wf-input" data-field="' + i + '" data-prop="label" value="' + esc(f.label) + '" placeholder="' + esc(T('Nom du champ')) + '">'
                + '<select class="wf-select" data-field="' + i + '" data-prop="type">' + opts + '</select>'
                + '<label class="wf-check" title="' + esc(T('Obligatoire')) + '"><input type="checkbox" data-field="' + i + '" data-prop="required"' + (f.required ? ' checked' : '') + '><span>' + esc(T('Requis')) + '</span></label>'
                + '<button type="button" class="wf-tool danger" data-act="field-del" data-val="' + i + '" aria-label="' + esc(T('Retirer')) + '">' + icon('trash') + '</button>'
                + '<div class="wf-field-extra">' + extra + '</div></div>';
        }).join('');
        var hasFiche = (s.fields || []).some(function (f) { return f.type !== 'file'; });
        var sched = s.schedule;
        var freq = sched ? sched.rule.freq : 'weekly';
        var schedHtml = '';
        if (sched) {
            var F = [['daily', T('Chaque jour')], ['weekdays', T('Jours ouvrés')], ['weekly', T('Chaque semaine')], ['monthly', T('Chaque mois')]];
            var dayNames = [T('L'), T('Ma'), T('Me'), T('J'), T('V'), T('S'), T('D')];
            schedHtml = '<div class="wf-sched"><div class="wf-seg">' + F.map(function (f) {
                return '<button type="button" data-act="freq" data-val="' + f[0] + '"' + (f[0] === freq ? ' class="is-on"' : '') + '>' + esc(f[1]) + '</button>';
            }).join('') + '</div>'
                + (freq === 'weekly' ? '<div class="wf-days">' + dayNames.map(function (d, i) {
                    var on = (sched.rule.days || []).indexOf(i + 1) >= 0;
                    return '<button type="button" data-act="day" data-val="' + (i + 1) + '"' + (on ? ' class="is-on"' : '') + '>' + esc(d) + '</button>';
                }).join('') + '</div>' : '')
                + (freq === 'monthly' ? '<select class="wf-select" data-act-change="mday">' + Array.from({ length: 28 }, function (_, i) {
                    return '<option value="' + (i + 1) + '"' + (String(sched.rule.day) === String(i + 1) ? ' selected' : '') + '>' + esc(T('le {n}', { n: i + 1 })) + '</option>';
                }).join('') + '<option value="last"' + (sched.rule.day === 'last' ? ' selected' : '') + '>' + esc(T('le dernier jour')) + '</option></select>' : '')
                + '<div class="wf-row2"><input type="time" class="wf-input" data-act-change="time" value="' + esc(sched.rule.time || '08:00') + '"><span class="wf-sched-say" id="wfSchedSay">' + esc(state.scheduleText || '') + '</span></div></div>';
        }
        var trig = [T('À la main')];
        if (s.chat) { trig.push(T('Depuis le chat')); }
        if (sched) { trig.push(state.scheduleText || T('Programmé')); }
        return headFor('start', (cat.hues || {}).start, 'start.title', s.title)
            + issuesHtml('start')
            + sec('fields', T('Ce que vous fournissez au lancement'), (rows || '<p class="wf-hint">' + esc(T('Aucun champ : le workflow démarre sans rien demander.')) + '</p>')
                + '<button type="button" class="wf-btn sm" data-act="field-add">' + icon('plus') + esc(T('Ajouter un champ')) + '</button>'
                + '<p class="wf-hint">' + esc(T('Un fichier devient une pièce à lui seul ; les autres champs forment ensemble une Fiche.')) + '</p>', '', 'fiche')
            + (hasFiche ? sec('fiche', T('Nom de la Fiche de départ'), '<input class="wf-input" data-bind="start.fiche_name" value="' + esc(s.fiche_name) + '">') : '')
            + choice({
                id: 'trigger', title: T('Comment il démarre'), ic: 'start', summary: esc(trig.join(' · ')),
                body: '<div class="wf-mini">' + icon('hand') + esc(T('À la main, depuis la page du workflow : toujours possible.')) + '</div>'
                    + check('start.chat', s.chat, T('Depuis le chat'), T('« @ » dans la zone de saisie propose ce workflow ; un fichier joint devient la première pièce.'))
                    + check('start.schedule', !!sched, T('Programmé'), T('Il démarre seul, au rythme choisi, avec les valeurs par défaut.'))
                    + schedHtml,
            })
            + eventSection(s);
    }

    /** Démarrer sur un évènement : un email, un fichier, un appel, la fin d'un autre workflow. */
    function eventSection(s) {
        var ev = s.event || null;
        var kind = ev ? ev.kind : '';
        var Le = L.events || {};
        var Lh = L.eventHelp || {};
        var icons = { '': 'hand', email: 'mail', file: 'folder', webhook: 'webhook', workflow: 'chain' };
        var tiles = ['', 'email', 'file', 'webhook', 'workflow'].map(function (k) {
            return tile({ act: 'event', val: k, on: kind === k, ic: icon(icons[k]), label: Le[k] || k, small: k ? (Lh[k] || '') : T('Seulement à la main, depuis le chat ou programmé.') });
        }).join('');
        var detail = '';
        var pm = pieceMap();
        var piece = pm.evenement ? R.pieceChip(pm.evenement, '') : '';
        if (kind === 'email') {
            ev.filters = ev.filters || blankFilters();
            var srcs = ['gmail', 'outlook'].map(function (src) {
                var st = sourceState(src);
                return tile({ act: 'evsource', val: src, on: ev.source === src, ic: ((cat.sources || []).filter(function (x) { return x.key === src; })[0] || {}).icon || icon('mail'),
                    label: src === 'gmail' ? 'Gmail' : 'Outlook', small: st === 'ok' ? '' : (st === 'off' ? T('Non relié') : T('Non proposé')), off: st === 'none' && ev.source !== src });
            }).join('');
            detail = '<div class="wf-tiles">' + srcs + '</div>'
                + '<div class="wf-filter">' + '<div class="wf-filter-h">' + icon('filter') + esc(T('Seulement les emails…')) + '</div>'
                + '<div class="wf-row2"><label class="wf-lab-in"><span>' + esc(T('De')) + '</span><input class="wf-input" data-bind="start.event.filters.mail.from" value="' + esc(ev.filters.mail.from || '') + '" placeholder="client@exemple.fr"></label>'
                + '<label class="wf-lab-in"><span>' + esc(T('Objet contient')) + '</span><input class="wf-input" data-bind="start.event.filters.mail.subject" value="' + esc(ev.filters.mail.subject || '') + '" placeholder="' + esc(T('devis')) + '"></label></div>'
                + check('start.event.filters.mail.attachment', !!ev.filters.mail.attachment, T('Avec une pièce jointe'))
                + '<label class="wf-lab">' + esc(T('Recherche avancée (facultative)')) + '</label><input class="wf-input" data-bind="start.event.query" value="' + esc(ev.query || '') + '" placeholder="' + esc(ev.source === 'outlook' ? 'hasAttachments:true' : 'label:clients -from:noreply') + '">'
                + '<p class="wf-hint">' + esc(ev.source === 'outlook'
                    ? T('Mots ou recherche Outlook. Vide : tout email de la boîte de réception.')
                    : T('Recherche Gmail (label:, -from:…). Vide : tout email de la boîte de réception.')) + '</p></div>';
        } else if (kind === 'file') {
            ev.filters = ev.filters || blankFilters();
            detail = '<label class="wf-lab">' + esc(T('Dossier Google Drive')) + '</label><input class="wf-input" data-bind="start.event.folder" value="' + esc(ev.folder || '') + '" placeholder="' + esc(T('Lien du dossier, ou son chemin : Clients/À traiter')) + '">'
                + (sourceState('drive') === 'off' ? '<div class="wf-todo">' + icon('alert') + '<div>' + esc(T('Reliez d’abord ce service dans Réglages → Comptes connectés.')) + '</div></div>' : '')
                + '<div class="wf-filter"><div class="wf-filter-h">' + icon('filter') + esc(T('Seulement les fichiers…')) + '</div>'
                + kindSeg('start.event.filters.drive.kind', ev.filters.drive.kind || '')
                + '<label class="wf-lab-in"><span>' + esc(T('Dont le nom contient')) + '</span><input class="wf-input" data-bind="start.event.filters.drive.name" value="' + esc(ev.filters.drive.name || '') + '" placeholder="' + esc(T('facture')) + '"></label></div>'
                + '<p class="wf-hint">' + esc(T('Chaque fichier qui y apparaît lance une exécution ; son texte devient la première pièce. Les fichiers déjà là ne lancent rien.')) + '</p>';
        } else if (kind === 'webhook') {
            detail = '<div class="wf-hook" id="wfHook"><div class="wf-hook-url"><input class="wf-input" id="wfHookUrl" readonly value="' + esc(state.hookUrl || '') + '" placeholder="' + esc(T('Préparation de l’adresse…')) + '">'
                + '<button type="button" class="wf-btn sm" data-act="hook-copy">' + icon('copy') + esc(T('Copier')) + '</button></div>'
                + '<p class="wf-hint">' + esc(T('Une adresse secrète : qui la connaît peut lancer le workflow. Postez du JSON ; ses champs remplissent ceux du départ (par leur nom), le tout devient la pièce « {piece} ».', { piece: (pm.evenement || {}).name || '' })) + '</p>'
                + '<pre class="wf-code" id="wfHookExample">' + esc(hookExample()) + '</pre>'
                + '<button type="button" class="wf-btn ghost sm" data-act="hook-renew">' + icon('retry') + esc(T('Changer d’adresse')) + '</button></div>';
        } else if (kind === 'workflow') {
            var others = (cat.workflows || []).filter(function (w) { return w.id !== state.workflow.id; });
            detail = others.length
                ? '<select class="wf-select" data-bind="start.event.workflow_id"><option value="">' + esc(T('Choisir le workflow…')) + '</option>' + others.map(function (w) {
                    return '<option value="' + w.id + '"' + (ev.workflow_id === w.id ? ' selected' : '') + '>' + esc(w.name + (w.published ? '' : ' — ' + T('pas encore publié'))) + '</option>';
                }).join('') + '</select><p class="wf-hint">' + esc(T('Quand il se termine, son livrable devient la première pièce de celui-ci.')) + '</p>'
                : '<p class="wf-hint">' + esc(T('Créez d’abord un autre workflow.')) + '</p>';
        }
        var watch = state.workflow.watch;
        var status = '';
        if (kind && (kind === 'email' || kind === 'file') && watch && state.workflow.published && !state.workflow.draft_changed) {
            status = watch.error
                ? '<div class="wf-todo block">' + icon('alert') + '<div>' + esc(T('Surveillance interrompue : {cause}', { cause: watch.error })) + '</div></div>'
                : '<div class="wf-mini">' + icon('check') + esc(watch.checked ? T('Surveillé · dernière vérification {d}', { d: watch.checked }) : T('Surveillé dès la publication')) + '</div>';
        }
        var summary = kind ? esc(Le[kind] || kind) + (kind === 'email' || kind === 'file' ? filterSummaryHtml(ev.filters) : '') : esc(Le[''] || '');
        return choice({
            id: 'event', title: T('Démarrer sur un évènement'), ic: 'webhook', summary: summary, empty: !kind,
            body: '<div class="wf-tiles">' + tiles + '</div>'
                + (kind ? '<div class="wf-event-detail">' + detail
                    + '<div class="wf-row2" style="grid-template-columns:minmax(0,1fr) auto;margin-top:8px"><input class="wf-input" data-bind="start.event.piece_name" value="' + esc(ev.piece_name || '') + '" aria-label="' + esc(T('Nom de la pièce')) + '">' + piece + '</div>'
                    + status + '</div>' : ''),
        });
    }

    function hookExample() {
        var fields = (state.def.start.fields || []).filter(function (f) { return f.type !== 'file'; });
        var body = {};
        fields.slice(0, 3).forEach(function (f) { body[f.key] = f.type === 'number' ? 1 : (f.label || f.key); });
        if (!fields.length) { body.message = T('Bonjour'); }
        return 'curl -X POST ' + (state.hookUrl || '<adresse>') + " \\\n  -H 'Content-Type: application/json' \\\n  -d '" + JSON.stringify(body) + "'";
    }

    function loadHook(renew) {
        if (!renew && state.hookUrl) { return; }
        if (renew && !window.confirm(T('Changer d’adresse ? L’ancienne cessera aussitôt de fonctionner.'))) { return; }
        var fd = new FormData();
        if (renew) { fd.append('renew', '1'); }
        fetch('/workflows/' + state.workflow.id + '/hook', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } })
            .then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { return; }
                state.hookUrl = j.url;
                var input = $('#wfHookUrl');
                if (input) { input.value = j.url; }
                var ex = $('#wfHookExample');
                if (ex) { ex.textContent = hookExample(); }
            }).catch(function () {});
    }

    function afterStart() {
        if (state.def.start.schedule) { previewSchedule(); }
        if (state.def.start.event && state.def.start.event.kind === 'webhook') { loadHook(false); }
    }

    var previewTimer = null;
    function previewSchedule() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(function () {
            var sc = state.def.start.schedule;
            if (!sc) { return; }
            var fd = new FormData();
            fd.append('freq', sc.rule.freq);
            fd.append('time', sc.rule.time || '08:00');
            (sc.rule.days || []).forEach(function (d) { fd.append('days[]', d); });
            fd.append('day', sc.rule.day || 1);
            fd.append('timezone', sc.timezone || '');
            fetch('/settings/schedules/preview', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } })
                .then(function (r) { return r.json(); }).then(function (j) {
                    var say = $('#wfSchedSay');
                    if (j.ok) {
                        state.scheduleText = j.sentence;
                        if (say) { say.textContent = j.sentence + (j.next && j.next[0] ? ' · ' + T('prochaine : {d}', { d: j.next[0] }) : ''); }
                        renderRail();
                    } else if (say) { say.textContent = j.error || ''; }
                }).catch(function () {});
        }, 300);
    }

    // --- Filtres (emails, fichiers Drive, destinataires)

    var DAY_PRESETS = [0, 1, 3, 7, 14, 30, 90, 365];

    function daysSelect(bind, value, label) {
        return '<label class="wf-lab-in"><span>' + esc(label) + '</span><select class="wf-select" data-bind="' + esc(bind) + '">' + DAY_PRESETS.map(function (d) {
            return '<option value="' + d + '"' + (+value === d ? ' selected' : '') + '>' + esc(d === 0 ? T('Toute période') : (window.tn ? window.tn(d, 'Le dernier jour', 'Les {n} derniers jours') : d)) + '</option>';
        }).join('') + (DAY_PRESETS.indexOf(+value) < 0 ? '<option value="' + (+value) + '" selected>' + esc(T('{n} jours', { n: +value })) + '</option>' : '') + '</select></label>';
    }

    function kindSeg(bind, value) {
        var K = L.driveKinds || {};
        return '<div class="wf-seg wf-seg-wrap">' + Object.keys(K).map(function (k) {
            return '<button type="button" data-act="kind" data-val="' + esc(bind) + '|' + esc(k) + '"' + (value === k ? ' class="is-on"' : '') + '>' + esc(K[k]) + '</button>';
        }).join('') + '</div>';
    }

    /**
     * Les filtres d'une étape (ses sources) ou d'une action (son outil) :
     * ce que ses recherches d'emails et de fichiers voient, et — quand elle
     * agit — à qui ses emails peuvent partir. Les champs citent le dossier
     * (un expéditeur reçu, le client en cours d'une boucle).
     */
    function filtersEditor(n, connectors, acts) {
        var f = n.filters || (n.filters = blankFilters());
        f.mail = f.mail || blankFilters().mail;
        f.drive = f.drive || blankFilters().drive;
        var mail = connectors.indexOf('gmail') >= 0 || connectors.indexOf('outlook') >= 0;
        var drive = connectors.indexOf('drive') >= 0;
        if (!mail && !drive) { return ''; }
        var out = '';
        if (mail) {
            out += '<div class="wf-filter" data-sec="filter-mail"><div class="wf-filter-h">' + icon('mail') + esc(acts && n.type === 'action' ? T('Emails qu’elle recherche') : T('Emails qu’elle voit')) + '</div>'
                + '<div class="wf-row2"><label class="wf-lab-in"><span>' + esc(T('De')) + '</span>' + mentionField({ id: 'wfFMFrom', path: 'node.filters.mail.from', at: n.key, accept: 'value', input: true, placeholder: T('client@exemple.fr ou @[…]') }) + '</label>'
                + '<label class="wf-lab-in"><span>' + esc(T('Objet contient')) + '</span>' + mentionField({ id: 'wfFMSubj', path: 'node.filters.mail.subject', at: n.key, accept: 'value', input: true, placeholder: T('devis') }) + '</label></div>'
                + '<label class="wf-lab-in"><span>' + esc(T('Mots')) + '</span>' + mentionField({ id: 'wfFMWords', path: 'node.filters.mail.words', at: n.key, accept: 'value', input: true, placeholder: T('commande urgent') }) + '</label>'
                + '<div class="wf-row2">' + daysSelect('node.filters.mail.days', f.mail.days || 0, T('Reçus')) + '<div class="wf-checks">'
                + check('node.filters.mail.attachment', !!f.mail.attachment, T('Avec pièce jointe'))
                + check('node.filters.mail.unread', !!f.mail.unread, T('Non lus seulement')) + '</div></div>'
                + (acts ? '<label class="wf-lab-in"><span>' + esc(T('Ses emails partent seulement vers')) + '</span>' + mentionField({ id: 'wfFRecip', path: 'node.filters.recipients', at: n.key, accept: 'email', input: true, placeholder: T('@client.fr, paul@exemple.fr ou @[…]') }) + '</label>'
                    + '<p class="wf-hint">' + esc(T('Adresses ou domaines, séparés par des virgules. Un autre destinataire est refusé avant même la carte.')) + '</p>' : '')
                + '</div>';
        }
        if (drive) {
            out += '<div class="wf-filter" data-sec="filter-drive"><div class="wf-filter-h">' + icon('folder') + esc(T('Fichiers Google Drive')) + '</div>'
                + '<label class="wf-lab-in"><span>' + esc(acts ? T('Dans le dossier (recherches et créations)') : T('Dans le dossier')) + '</span>' + mentionField({ id: 'wfFDFolder', path: 'node.filters.drive.folder', at: n.key, accept: 'value', input: true, placeholder: T('Clients/@[…] ou le lien du dossier') }) + '</label>'
                + '<label class="wf-lab-in"><span>' + esc(T('Dont le nom contient')) + '</span>' + mentionField({ id: 'wfFDName', path: 'node.filters.drive.name', at: n.key, accept: 'value', input: true, placeholder: T('facture') }) + '</label>'
                + kindSeg('node.filters.drive.kind', f.drive.kind || '')
                + daysSelect('node.filters.drive.days', f.drive.days || 0, T('Modifiés')) + '</div>';
        }
        return out + '<p class="wf-hint">' + esc(T('Les filtres s’appliquent aux recherches elles-mêmes : MIA ne voit rien en dehors.')) + '</p>';
    }

    /** Les filtres en une ligne (résumé d'un choix). */
    function filterSummaryHtml(f) {
        if (!f) { return ''; }
        var m = f.mail || {};
        var d = f.drive || {};
        var parts = [];
        if (m.from) { parts.push(T('de {qui}', { qui: toDisplay(m.from) })); }
        if (m.subject) { parts.push(T('objet « {objet} »', { objet: toDisplay(m.subject) })); }
        if (m.words) { parts.push('« ' + toDisplay(m.words) + ' »'); }
        if (m.attachment) { parts.push(T('avec pièce jointe')); }
        if (m.unread) { parts.push(T('non lus')); }
        if (+m.days) { parts.push(window.tn ? window.tn(+m.days, 'du dernier jour', 'des {n} derniers jours') : m.days); }
        if (d.folder) { parts.push(T('dossier « {dossier} »', { dossier: toDisplay(d.folder) })); }
        if (d.name) { parts.push(T('nom contenant « {nom} »', { nom: toDisplay(d.name) })); }
        if (d.kind) { parts.push((L.driveKinds || {})[d.kind] || d.kind); }
        if (+d.days) { parts.push(window.tn ? window.tn(+d.days, 'modifiés depuis un jour', 'modifiés depuis {n} jours') : d.days); }
        if (f.recipients) { parts.push(T('vers {qui} seulement', { qui: toDisplay(f.recipients) })); }
        return parts.length ? ' <span class="wf-sum-filter">' + icon('filter') + esc(parts.join(' · ')) + '</span>' : '';
    }

    // --- Étape
    function assistantTiles(n) {
        var tiles = [tile({ act: 'profile', val: '', on: n.profile_id === null, ic: icon('mia'), label: 'MIA', small: T('Sans assistant, modèle au choix') })];
        (cat.profiles || []).forEach(function (p) {
            var av = p.avatar ? '<img src="' + esc(p.avatar) + '" alt="">' : '<span style="font-weight:700;color:' + esc(p.color || 'inherit') + '">' + esc((p.name || '?').charAt(0)) + '</span>';
            tiles.push(tile({ act: 'profile', val: String(p.id), on: n.profile_id === p.id, ic: av, label: p.name, small: p.model, title: p.description }));
        });
        return '<div class="wf-tiles">' + tiles.join('') + '</div>';
    }

    function llmLabel(slug) {
        if (!slug) { return T('Modèle par défaut'); }
        var l = (cat.llms || []).filter(function (x) { return x.slug === slug; })[0];
        return l ? l.label : slug;
    }

    /** Résumé « qui fait le travail » : l'avatar, le nom, le modèle. */
    function whoSummary(n) {
        var p = n.profile_id ? (cat.profiles || []).filter(function (x) { return x.id === n.profile_id; })[0] : null;
        return '<span class="wf-sum-who">' + R.avatar(p || null, cat) + '<b>' + esc(p ? p.name : 'MIA') + '</b><small>' + esc(p ? (p.model || '') : llmLabel(n.llm)) + '</small></span>';
    }

    function llmSelect(n, onlyTools) {
        var opts = '<option value="">' + esc(T('Modèle par défaut')) + '</option>' + (cat.llms || []).map(function (l) {
            var off = onlyTools && !l.tools;
            return '<option value="' + esc(l.slug) + '"' + (l.slug === n.llm ? ' selected' : '') + (off ? ' disabled' : '') + '>' + esc(l.label + (l.tools ? '' : ' — ' + T('sans connecteurs'))) + '</option>';
        }).join('');
        return '<select class="wf-select" data-bind="node.llm">' + opts + '</select>';
    }

    function sourceTiles(n) {
        var t = (cat.sources || []).map(function (s) {
            return tile({ act: 'source', val: s.key, on: (n.sources || []).indexOf(s.key) >= 0, ic: s.icon, label: s.label, small: s.connected ? '' : T('Non relié'), off: false });
        });
        (cat.kbs || []).forEach(function (kb) {
            var on = (n.kb_ids || []).indexOf(kb.id) >= 0;
            t.push(tile({ act: 'kb', val: String(kb.id), on: on, ic: icon('kb'), label: kb.name, small: kb.reason || (kb.live ? T('En direct') : T('Base de connaissances')), off: !!kb.reason && !on }));
        });
        (cat.tools || []).forEach(function (tool) {
            var on = (n.tool_ids || []).indexOf(tool.id) >= 0;
            t.push(tile({ act: 'tool', val: String(tool.id), on: on, ic: icon('tool'), label: tool.name, small: tool.reason || tool.type_label, off: !!tool.reason && !on }));
        });
        return t.length ? '<div class="wf-tiles">' + t.join('') + '</div>' : '<p class="wf-hint">' + esc(T('Aucune source disponible.')) + '</p>';
    }

    /** Résumé « peut consulter » : les pictos et noms des sources, bases et outils retenus. */
    function sourcesSummary(n) {
        var parts = [];
        (cat.sources || []).forEach(function (s) { if ((n.sources || []).indexOf(s.key) >= 0) { parts.push('<span class="wf-sum-src">' + s.icon + esc(s.label) + '</span>'); } });
        (cat.kbs || []).forEach(function (kb) { if ((n.kb_ids || []).indexOf(kb.id) >= 0) { parts.push('<span class="wf-sum-src">' + icon('kb') + esc(kb.name) + '</span>'); } });
        (cat.tools || []).forEach(function (tool) { if ((n.tool_ids || []).indexOf(tool.id) >= 0) { parts.push('<span class="wf-sum-src">' + icon('tool') + esc(tool.name) + '</span>'); } });
        return parts.length ? parts.join('') + filterSummaryHtml(n.filters) : esc(T('Rien : elle lit seulement les pièces du dossier'));
    }

    function writeRadios(n, act) {
        var W = L.write || {};
        var modes = act === 'action' ? ['approve', 'auto'] : ['none', 'approve', 'auto'];
        var small = {
            none: T('Elle lit ses sources et remet sa pièce, rien d’autre.'),
            approve: T('Elle prépare des actions (emails, évènements…) ; une carte attend votre accord, le workflow aussi.'),
            auto: cat.auto ? T('Elle agit seule dans les outils reliés. À réserver aux gestes sans risque.') : T('Retiré par l’administrateur.'),
        };
        return modes.map(function (m) { return radio('write', m, n.write === m, W[m] || m, small[m], m === 'auto' && !cat.auto); }).join('')
            + (n.write === 'auto' ? check('node.auto_send_mail', n.auto_send_mail, T('Envoyer réellement les emails'), T('Sinon, un email est préparé en brouillon.')) : '');
    }
    function writeSummary(n) {
        var ic = { none: 'eye', approve: 'gate', auto: 'send' }[n.write] || 'eye';
        return '<span class="wf-sum-mode wf-w-' + esc(n.write) + '">' + icon(ic) + esc((L.write || {})[n.write] || n.write) + '</span>'
            + (n.write === 'auto' && n.auto_send_mail ? ' <small>' + esc(T('emails envoyés')) + '</small>' : '');
    }

    function outputEditor(n) {
        var o = n.output;
        var forms = ['text', 'list', 'table', 'fiche', 'document'];
        var ft = forms.map(function (f) {
            return tile({ act: 'form', val: f, on: o.form === f, ic: icon(f), label: (L.forms || {})[f] || f, col: true, title: (L.formHelp || {})[f] });
        }).join('');
        var extra = '';
        if (o.form === 'fiche') {
            var types = L.ficheTypes || {};
            extra = '<div class="wf-sec-inner">' + (o.fields || []).map(function (f, i) {
                return '<div class="wf-field-row"><input class="wf-input" data-ofield="' + i + '" data-prop="label" value="' + esc(f.label) + '" placeholder="' + esc(T('Nom du champ')) + '">'
                    + '<select class="wf-select" data-ofield="' + i + '" data-prop="type">' + Object.keys(types).map(function (t) {
                        return '<option value="' + t + '"' + (t === f.type ? ' selected' : '') + '>' + esc(types[t]) + '</option>';
                    }).join('') + '</select><button type="button" class="wf-tool danger" data-act="ofield-del" data-val="' + i + '">' + icon('trash') + '</button></div>';
            }).join('') + '<button type="button" class="wf-btn sm" data-act="ofield-add">' + icon('plus') + esc(T('Ajouter un champ')) + '</button>'
                + '<p class="wf-hint">' + esc(T('Des champs nommés et typés : une condition par règle peut les lire, une action y prendre un destinataire.')) + '</p></div>';
        }
        if (o.form === 'document') {
            var fmts = L.docFormats || {};
            extra = '<div class="wf-row2"><select class="wf-select" data-bind="node.output.format">' + Object.keys(fmts).map(function (f) {
                return '<option value="' + f + '"' + (f === o.format ? ' selected' : '') + '>' + esc(fmts[f]) + '</option>';
            }).join('') + '</select>' + check('node.output.template', o.template !== false, T('Au modèle de l’entreprise')) + '</div>';
        }
        var fieldsTxt = o.form === 'fiche' && (o.fields || []).length ? ' · ' + (o.fields || []).map(function (f) { return f.label; }).filter(Boolean).join(', ') : '';
        var fmtTxt = o.form === 'document' ? ' · ' + ((L.docFormats || {})[o.format] || o.format) : '';
        return '<div class="wf-row2" style="grid-template-columns:minmax(0,1fr) auto"><input class="wf-input" data-bind="node.output.name" value="' + esc(o.name) + '" placeholder="' + esc(T('Nom de la pièce')) + '">'
            + R.pieceChip(pieceMap()[o.key] || { key: o.key, name: T('nouvelle pièce'), color: '#999' }, '') + '</div>'
            + choice({
                id: 'form', sub: true, title: T('Forme'), ic: o.form, summary: esc(((L.forms || {})[o.form] || o.form) + fmtTxt + fieldsTxt),
                body: '<div class="wf-tiles cols-5">' + ft + '</div>' + extra,
            });
    }

    function readsChoice(n, avail, title) {
        var pm = pieceMap();
        var cited = mentionsOf(n);
        var keys = avail.filter(function (k) { return pm[k] && cited.indexOf(k) < 0; });
        return choice({
            id: 'reads', title: title || T('Lit aussi, sans la citer'), ic: 'eye',
            summary: chipsSummary(n.reads, T('Rien de plus que les pièces citées')), empty: !(n.reads || []).length,
            body: chipToggles('read', keys, n.reads || []),
        });
    }

    /** Pièces citées par les textes d'une brique (consigne, question, voies). */
    function mentionsOf(n) {
        var t = [n.instruction, n.question, n.message, n.note].concat((n.routes || []).map(function (r) { return r.when; }));
        var out = [];
        t.forEach(function (x) { R.mentions(x || '').forEach(function (k) { if (out.indexOf(k) < 0) { out.push(k); } }); });
        return out;
    }

    function stepInspector(n) {
        var avail = Object.keys(availableFor(n.key));
        return headFor('step', (cat.hues || {}).step, 'node.title', n.title)
            + issuesHtml(n.key)
            + sec('instruction', T('Consigne'), mentionField({ id: 'wfIns', path: 'node.instruction', at: n.key, rows: 120, placeholder: T('Ce que l’étape doit faire, en une ou deux phrases. Tapez @ pour citer une pièce du dossier.') }), T('envoyée telle quelle à chaque exécution'), 'pencil')
            + choice({ id: 'profile', title: T('Qui fait le travail'), ic: 'user', summary: whoSummary(n), body: assistantTiles(n) + (n.profile_id === null ? llmSelect(n, false) : '') })
            + readsChoice(n, avail)
            + choice({
                id: 'sources', title: T('Peut consulter'), ic: 'search', summary: sourcesSummary(n), empty: !(n.sources || []).length && !(n.kb_ids || []).length && !(n.tool_ids || []).length,
                body: sourceTiles(n) + filtersEditor(n, n.sources || [], n.write !== 'none'),
            })
            + choice({ id: 'write', title: T('Ce qu’elle peut faire dans les outils'), ic: 'send', summary: writeSummary(n), body: writeRadios(n, 'step') })
            + sec('output', T('Remet au dossier'), outputEditor(n)
                + check('node.review', n.review, T('Je relis la pièce avant la suite'), T('Une validation s’ouvre après l’étape : approuver, corriger, renvoyer avec une remarque.')), '', 'inbox')
            + onErrorSection(n);
    }

    // --- Création
    function createInspector(n) {
        var avail = Object.keys(availableFor(n.key));
        var CK = L.createKinds || {};
        var all = cat.creations || [];
        var tools = all.filter(function (t) { return t.kind === n.kind; });
        var tool = tools.filter(function (t) { return t.id === n.tool_id; })[0] || null;
        var kindTiles = ['image', 'page', 'map'].filter(function (k) {
            return k === n.kind || all.some(function (t) { return t.kind === k; });
        }).map(function (k) {
            return tile({ act: 'ckind', val: k, on: n.kind === k, ic: icon(k), label: CK[k] || k, small: (L.createHelp || {})[k] || '', col: true });
        }).join('');
        var toolTiles = tools.map(function (t) {
            return tile({ act: 'ctool', val: t.id, on: n.tool_id === t.id, ic: icon(n.kind), label: t.name, small: t.reason || t.type_label, off: !!t.reason && n.tool_id !== t.id });
        }).join('');
        var opts = '';
        if (n.kind === 'image') {
            var F = L.imageFormats || {};
            var fmt = (n.options || {}).format || '';
            opts = choice({
                id: 'options', title: T('Format'), ic: 'image', summary: esc(F[fmt] || ''), empty: !fmt,
                body: '<div class="wf-seg wf-seg-wrap">' + Object.keys(F).map(function (f) {
                    return '<button type="button" data-act="cformat" data-val="' + esc(f) + '"' + (fmt === f ? ' class="is-on"' : '') + '>' + esc(F[f]) + '</button>';
                }).join('') + '</div><p class="wf-hint">' + esc(tool && !tool.can_edit
                    ? T('Cet outil ne part pas d’une image : il crée toujours une image neuve.')
                    : T('Une image citée ou lue (créée plus tôt, ou reçue au départ) sert d’image de départ : l’outil la reprend ou s’en inspire.')) + '</p>',
            });
        } else if (n.kind === 'page' && tool && tool.type === 'html_illustrated') {
            var style = (n.options || {}).style || '';
            opts = choice({
                id: 'options', title: T('Style des illustrations'), ic: 'image', summary: esc(style || T('Celui de l’outil')), empty: !style,
                body: '<input class="wf-input" data-bind="node.options.style" value="' + esc(style) + '" maxlength="200" placeholder="' + esc(T('Par exemple : aquarelle douce, tons pastel')) + '">',
            });
        }
        var placeholder = {
            image: T('Par exemple : une illustration pour @[Article], style flat, aux couleurs de la marque.'),
            page:  T('Par exemple : une page qui présente @[Offre], avec un appel à l’action en bas.'),
            map:   T('Par exemple : l’itinéraire de la tournée de @[Rendez-vous], en voiture.'),
        }[n.kind] || '';
        return headFor('create', (cat.hues || {}).create, 'node.title', n.title)
            + issuesHtml(n.key)
            + choice({ id: 'kind', title: T('Ce que la brique crée'), ic: n.kind, summary: esc(CK[n.kind] || n.kind), body: '<div class="wf-tiles">' + kindTiles + '</div>' })
            + choice({
                id: 'tool', title: T('Avec quel outil'), ic: 'tool', warn: !tool,
                summary: tool ? '<span class="wf-sum-src">' + icon(n.kind) + esc(tool.name + ' · ' + tool.type_label) + '</span>' : esc(T('À choisir')), empty: !tool,
                body: toolTiles ? '<div class="wf-tiles">' + toolTiles + '</div>' : '<p class="wf-hint">' + esc(T('Aucun outil de ce type ne vous est ouvert : un administrateur les ouvre à vos groupes dans Outils.')) + '</p>',
            })
            + sec('instruction', T('Ce qu’il faut créer'), mentionField({ id: 'wfIns', path: 'node.instruction', at: n.key, rows: 110, placeholder: placeholder }), T('un champ de Fiche cité est remplacé par sa valeur'), 'pencil')
            + opts
            + readsChoice(n, avail)
            + sec('output', T('Remet au dossier'), creationOutput(n)
                + check('node.review', n.review, T('Je relis le résultat avant la suite'), T('Une validation s’ouvre après la création : approuver, ou renvoyer avec une remarque pour qu’elle soit refaite.')), '', 'inbox')
            + onErrorSection(n);
    }

    /** Le nom de la pièce créée (sa forme est celle de la nature). */
    function creationOutput(n) {
        var o = n.output;
        return '<div class="wf-row2" style="grid-template-columns:minmax(0,1fr) auto"><input class="wf-input" data-bind="node.output.name" value="' + esc(o.name) + '" placeholder="' + esc(T('Nom de la pièce')) + '">'
            + R.pieceChip(pieceMap()[o.key] || { key: o.key, name: T('nouvelle pièce'), color: '#999' }, '') + '</div>';
    }

    // --- Reprise sur erreur
    function onErrorSection(n) {
        var oe = n.on_error || (n.on_error = { retries: 1, then: 'stop' });
        var max = (cat.limits || {}).retries || 3;
        var counts = [0, 1, 2, 3].filter(function (r) { return r <= max; });
        var seg = '<div class="wf-seg">' + counts.map(function (r) {
            return '<button type="button" data-act="retries" data-val="' + r + '"' + (oe.retries === r ? ' class="is-on"' : '') + '>'
                + esc(r === 0 ? T('Jamais') : (window.tn ? window.tn(r, '{n} fois', '{n} fois') : r)) + '</button>';
        }).join('') + '</div>';
        var branching = R.isBranching(n);
        var then = branching ? '' : '<p class="wf-hint">' + esc(T('Si l’erreur persiste :')) + '</p>'
            + radio('onerror', 'stop', oe.then !== 'skip', (L.onError || {}).stop || '', T('Vous la reprenez d’un clic depuis l’exécution : à la brique, en l’ignorant, ou depuis une brique précédente.'))
            + radio('onerror', 'skip', oe.then === 'skip', (L.onError || {}).skip || '', T('La suite se passe de sa pièce. À réserver aux briques facultatives.'));
        var summary = (oe.retries ? (window.tn ? window.tn(oe.retries, '{n} nouvel essai', '{n} nouveaux essais') : oe.retries) : T('Aucun nouvel essai'))
            + ' · ' + (branching || oe.then !== 'skip' ? T('puis arrêter') : T('puis ignorer'));
        return choice({
            id: 'onerror', title: T('Si une erreur survient'), ic: 'shield', summary: esc(summary),
            body: '<p class="wf-hint">' + esc(T('Réessayer seul quand le service est saturé ou le réseau coupé (après 1, 5 puis 15 minutes) :')) + '</p>' + seg + then,
        });
    }

    // --- Action
    function actionInspector(n) {
        var avail = Object.keys(availableFor(n.key));
        var targets = (cat.targets || []).map(function (t) {
            return tile({ act: 'target', val: t.key, on: n.target === t.key, ic: t.icon, label: t.label, small: t.available ? (t.verb || '') : (t.reason || ''), off: !t.available && n.target !== t.key });
        }).join('');
        var tg = (cat.targets || []).filter(function (t) { return t.key === n.target; })[0];
        var toolSel = tg && tg.kind === 'tool'
            ? '<select class="wf-select" data-bind="node.tool_id">' + tg.tools.map(function (x) {
                return '<option value="' + x.id + '"' + (x.id === n.tool_id ? ' selected' : '') + (x.reason ? ' disabled' : '') + '>' + esc(x.name + (x.reason ? ' — ' + x.reason : '')) + '</option>';
            }).join('') + '</select>'
            : '';
        var tool = tg && tg.kind === 'tool' ? tg.tools.filter(function (x) { return x.id === n.tool_id; })[0] : null;
        var conn = tg && tg.kind === 'connector' ? [{ gmail: 'gmail', outlook: 'outlook', drive: 'drive' }[tg.key]].filter(Boolean) : [];
        var filters = conn.length ? choice({
            id: 'filters', title: T('Filtres'), ic: 'filter', summary: filterSummaryHtml(n.filters) || esc(T('Aucun : tout ce que l’outil voit')), empty: !R.hasFilter(n),
            body: filtersEditor(n, conn, true),
        }) : '';
        return headFor('action', (cat.hues || {}).action, 'node.title', n.title)
            + issuesHtml(n.key)
            + choice({
                id: 'target', title: T('Dans quel outil'), ic: 'tool', warn: !tg,
                summary: tg ? '<span class="wf-sum-src">' + tg.icon + esc(tg.label + (tool ? ' · ' + tool.name : '')) + '</span>' : esc(T('À choisir')), empty: !tg,
                body: (targets ? '<div class="wf-tiles">' + targets + '</div>' : '<p class="wf-hint">' + esc(T('Aucun outil relié : reliez un service dans Réglages → Comptes connectés.')) + '</p>') + toolSel,
            })
            + sec('instruction', T('Ce que l’action fait'), mentionField({ id: 'wfIns', path: 'node.instruction', at: n.key, rows: 110, placeholder: T('Par exemple : prépare un email à l’adresse de @[Contexte] avec @[Synthèse].') }), '', 'pencil')
            + filters
            + readsChoice(n, avail)
            + choice({ id: 'write', title: T('Ce que fait l’action'), ic: 'send', summary: writeSummary(n), body: writeRadios(n, 'action') })
            + choice({ id: 'llm', title: T('Modèle'), ic: 'spark', summary: esc(llmLabel(n.llm)), body: llmSelect(n, true) + '<p class="wf-hint">' + esc(T('L’action est menée par MIA avec ce modèle ; il doit savoir piloter les connecteurs.')) + '</p>' })
            + onErrorSection(n);
    }

    // --- Condition
    function conditionInspector(n) {
        var avail = availableFor(n.key);
        var modeSeg = '<div class="wf-seg"><button type="button" data-act="cmode" data-val="question"' + (n.mode === 'question' ? ' class="is-on"' : '') + '>' + icon('spark') + esc(T('Une question à MIA')) + '</button>'
            + '<button type="button" data-act="cmode" data-val="rule"' + (n.mode === 'rule' ? ' class="is-on"' : '') + '>' + icon('table') + esc(T('Une règle')) + '</button></div>';
        var body;
        if (n.mode === 'question') {
            body = sec('question', T('La question'), mentionField({ id: 'wfQ', path: 'node.question', at: n.key, rows: 80, placeholder: T('Une question fermée : oui ou non. Citez les pièces avec @.') })
                + '<p class="wf-hint">' + esc(T('MIA répond oui ou non, avec sa raison, visible dans l’exécution.')) + '</p>', '', 'spark')
                + readsChoice(n, Object.keys(avail), T('En s’appuyant aussi sur'))
                + choice({
                    id: 'doubt', title: T('Si MIA hésite'), ic: 'hand', summary: esc(n.doubt === 'no' ? T('Prendre « non »') : T('Me demander')),
                    body: radio('doubt', 'ask', n.doubt === 'ask', T('Me demander'), T('Une validation s’ouvre avec ce que MIA a vu.'))
                        + radio('doubt', 'no', n.doubt === 'no', T('Prendre « non »'), T('La voie prudente, sans attendre.')),
                });
        } else {
            body = sec('rule', T('La règle'), ruleEditor(n.rule, avail, 'rule'), '', 'table');
        }
        var inBody = (find(n.key) || {}).lane === 'body';
        if (inBody) {
            var BT = L.bodyThen || {};
            var bodyLanes = ['yes', 'no'].map(function (l) {
                var cur = (n[l] || {}).then || (l === 'yes' ? 'continue' : 'skip');
                return '<div class="wf-row2" style="align-items:center"><span class="wf-pill cond">' + esc(l === 'yes' ? T('Si oui') : T('Si non')) + '</span>'
                    + '<select class="wf-select" data-bind="node.' + l + '.then">' + ['continue', 'skip', 'stop'].map(function (v) {
                        return '<option value="' + v + '"' + (cur === v ? ' selected' : '') + '>' + esc(BT[v] || v) + '</option>';
                    }).join('') + '</select></div>';
            }).join('');
            return headFor('condition', (cat.hues || {}).condition, 'node.title', n.title)
                + issuesHtml(n.key)
                + '<p class="wf-hint">' + esc(T('Dans une boucle, une condition trie les éléments sur ce que le tour vient de produire : l’élément écarté est compté à part, le tour suivant commence.')) + '</p>'
                + choice({ id: 'mode', title: T('Comment décider'), ic: 'condition', summary: esc(n.mode === 'rule' ? T('Une règle') : T('Une question à MIA')), body: modeSeg })
                + body
                + choice({
                    id: 'lanes', title: T('La suite'), ic: 'route',
                    summary: esc(T('Si oui : {a} · Si non : {b}', { a: BT[(n.yes || {}).then || 'continue'] || '', b: BT[(n.no || {}).then || 'skip'] || '' })),
                    body: bodyLanes,
                })
                + (n.mode === 'question' ? onErrorSection(n) : '');
        }
        var lanes = ['yes', 'no'].map(function (l) {
            return '<div class="wf-row2" style="align-items:center"><span class="wf-pill cond">' + esc(l === 'yes' ? T('Si oui') : T('Si non')) + '</span>'
                + laneThen('node.' + l + '.then', n[l]) + '</div>';
        }).join('');
        var thenTxt = function (l) { return R.laneStops(n[l]) ? T('arrête') : T('reprend'); };
        return headFor('condition', (cat.hues || {}).condition, 'node.title', n.title)
            + issuesHtml(n.key)
            + choice({ id: 'mode', title: T('Comment décider'), ic: 'condition', summary: esc(n.mode === 'rule' ? T('Une règle') : T('Une question à MIA')), body: modeSeg })
            + body
            + choice({
                id: 'lanes', title: T('Les deux voies'), ic: 'route', summary: esc(T('Si oui : {a} · Si non : {b}', { a: thenTxt('yes'), b: thenTxt('no') })),
                body: lanes + '<p class="wf-hint">' + esc(T('Ajoutez des briques dans chaque voie avec « + Ajouter », sur le rail.')) + '</p>',
            })
            + (n.mode === 'question' ? onErrorSection(n) : '');
    }

    /**
     * La suite d'une voie : reprendre le rail ou arrêter. Une voie qui se
     * termine par « Arrêter le workflow » n'a pas de suite à choisir.
     */
    function laneThen(bind, lane) {
        if ((lane.nodes || []).some(function (x) { return x.type === 'stop'; })) {
            return '<span class="wf-lane-then-stop">' + icon('stop') + esc(T('se termine par « Arrêter le workflow »')) + '</span>';
        }
        return '<select class="wf-select" data-bind="' + esc(bind) + '"><option value="continue"' + (lane.then !== 'stop' ? ' selected' : '') + '>' + esc(T('puis reprendre le rail')) + '</option>'
            + '<option value="stop"' + (lane.then === 'stop' ? ' selected' : '') + '>' + esc(T('puis arrêter le workflow')) + '</option></select>';
    }

    // --- Décision de MIA (aiguillage)
    function routeInspector(n) {
        var avail = Object.keys(availableFor(n.key));
        var routes = n.routes || (n.routes = []);
        var lim = cat.limits || {};
        var cards = routes.map(function (r, i) {
            var tool = function (act, ic, label, extra) {
                return '<button type="button" class="wf-tool' + (extra || '') + '" data-act="' + act + '" data-val="' + i + '" title="' + esc(label) + '" aria-label="' + esc(label) + '">' + icon(ic) + '</button>';
            };
            return '<div class="wf-route" data-sec="route:' + i + '">'
                + '<div class="wf-route-head"><span class="wf-lane-num" style="--lane:var(--wf-l' + ((i % 6) + 1) + ')">' + (i + 1) + '</span>'
                + '<input class="wf-input" data-bind="node.routes.' + i + '.label" value="' + esc(r.label || '') + '" placeholder="' + esc(T('Nom de la voie, par exemple Devis')) + '" aria-label="' + esc(T('Nom de la voie')) + '">'
                + (i > 0 ? tool('route-up', 'up', T('Monter')) : '') + (i < routes.length - 1 ? tool('route-down', 'down', T('Descendre')) : '')
                + (routes.length > (lim.routes_min || 2) ? tool('route-del', 'trash', T('Retirer la voie'), ' danger') : '') + '</div>'
                + mentionField({ id: 'wfRouteWhen' + i, path: 'node.routes.' + i + '.when', at: n.key, rows: 52, placeholder: T('Quand MIA la prend : par exemple, la demande porte sur un prix.') })
                + laneThen('node.routes.' + i + '.then', r) + '</div>';
        }).join('');
        var add = routes.length < (lim.routes_max || 6) ? '<button type="button" class="wf-btn sm" data-act="route-add">' + icon('plus') + esc(T('Ajouter une voie')) + '</button>' : '';
        var fb = routes.filter(function (r) { return r.key === n.fallback; })[0];
        return headFor('route', (cat.hues || {}).route, 'node.title', n.title)
            + issuesHtml(n.key)
            + sec('instruction', T('Sur quoi MIA choisit'), mentionField({ id: 'wfIns', path: 'node.instruction', at: n.key, rows: 96, placeholder: T('Par exemple : lis @[Email reçu] et choisis selon ce que demande le client.') })
                + '<p class="wf-hint">' + esc(T('MIA lit les pièces citées, suit vos critères et prend UNE voie ; sa raison s’affiche dans l’exécution. Sans IA ? Une condition par règle.')) + '</p>', '', 'spark')
            + sec('routes', T('Les voies'), '<div class="wf-routes">' + cards + '</div>' + add
                + '<p class="wf-hint">' + esc(T('Décrivez chaque scénario : MIA choisit par le nom et la description. Les briques de chaque voie s’ajoutent sur le rail.')) + '</p>', T('{n} au plus', { n: lim.routes_max || 6 }), 'route')
            + readsChoice(n, avail, T('En s’appuyant aussi sur'))
            + choice({
                id: 'fallback', title: T('Si MIA ne sait pas choisir'), ic: 'hand', summary: esc(fb ? T('Prendre « {voie} »', { voie: fb.label || '…' }) : T('Me demander')),
                body: radio('fallback', 'ask', !fb, T('Me demander'), T('Une validation s’ouvre : vous choisissez la voie, avec ce que MIA a vu.'))
                    + routes.map(function (r) { return radio('fallback', r.key, fb === r, T('Prendre « {voie} »', { voie: r.label || '…' }), T('Sans attendre : la voie par défaut.')); }).join(''),
            })
            + onErrorSection(n);
    }

    /** Éditeur d'une règle sur des champs de Fiche (condition, sortie d'une boucle). `prefix` : rule | urule. */
    function ruleEditor(rule, avail, prefix) {
        var fiches = Object.keys(avail).map(function (k) { return avail[k]; }).filter(function (p) { return p.form === 'fiche'; });
        var rows = (rule.items || []).map(function (it, i) {
            var fieldOpts = fiches.map(function (p) {
                return '<optgroup label="' + esc(p.name) + '">' + (p.fields || []).map(function (f) {
                    var v = p.key + '|' + f.key;
                    return '<option value="' + esc(v) + '"' + (it.piece === p.key && it.field === f.key ? ' selected' : '') + '>' + esc(f.label) + '</option>';
                }).join('') + '</optgroup>';
            }).join('');
            var p = avail[it.piece];
            var f = p ? (p.fields || []).filter(function (x) { return x.key === it.field; })[0] : null;
            var type = f ? f.type : 'text';
            var ops = ((L.opsFor || {})[type] || []).map(function (o) { return '<option value="' + o + '"' + (o === it.op ? ' selected' : '') + '>' + esc((L.ops || {})[o] || o) + '</option>'; }).join('');
            var noValue = ['empty', 'notempty', 'yes', 'no'].indexOf(it.op) >= 0;
            var inputType = type === 'number' ? 'number' : type === 'date' ? 'date' : 'text';
            return '<div class="wf-rule-row"><select class="wf-select" data-' + prefix + '="' + i + '" data-prop="field"><option value="">' + esc(T('Champ…')) + '</option>' + fieldOpts + '</select>'
                + '<select class="wf-select" data-' + prefix + '="' + i + '" data-prop="op">' + ops + '</select>'
                + (noValue ? '<span></span>' : '<input class="wf-input" type="' + inputType + '" data-' + prefix + '="' + i + '" data-prop="value" value="' + esc(it.value) + '">')
                + '<button type="button" class="wf-tool danger" data-act="' + prefix + '-del" data-val="' + i + '">' + icon('trash') + '</button></div>';
        }).join('');
        var bindJoin = prefix === 'rule' ? 'node.rule.join' : (prefix === 'frule' ? 'node.filter.rule.join' : 'node.until.rule.join');
        return (fiches.length || prefix === 'frule' ? '' : '<p class="wf-hint">' + esc(T('Une règle lit les champs d’une Fiche remise avant elle. Ajoutez une étape qui remet une Fiche, ou un champ au départ.')) + '</p>')
            + rows
            + ((rule.items || []).length < (cat.limits || {}).rule && fiches.length ? '<button type="button" class="wf-btn sm" data-act="' + prefix + '-add">' + icon('plus') + esc(T('Ajouter une condition')) + '</button>' : '')
            + ((rule.items || []).length > 1 ? '<select class="wf-select" data-bind="' + bindJoin + '"><option value="all"' + (rule.join === 'all' ? ' selected' : '') + '>' + esc(T('Toutes les conditions')) + '</option><option value="any"' + (rule.join === 'any' ? ' selected' : '') + '>' + esc(T('Au moins une')) + '</option></select>' : '')
            + '<p class="wf-hint">' + esc(T('Une règle se calcule sans IA : même réponse à chaque fois, aucun crédit.')) + '</p>';
    }

    // --- Validation
    function gateInspector(n) {
        var pm = pieceMap();
        var avail = availableFor(n.key);
        var producers = [];
        Object.keys(avail).forEach(function (k) {
            var p = avail[k];
            var at = p.producer !== 'start' ? find(p.producer) : null;
            if (at && at.node.type === 'step' && producers.indexOf(p.producer) < 0) { producers.push(p.producer); }
        });
        var ret = '<select class="wf-select" data-bind="node.returns_to"><option value="">' + esc(T('L’étape qui a remis la pièce relue')) + '</option>'
            + producers.map(function (k) { var at = find(k); return at ? '<option value="' + esc(k) + '"' + (n.returns_to === k ? ' selected' : '') + '>' + esc(at.node.title) + '</option>' : ''; }).join('') + '</select>';
        var days = Array.from({ length: (cat.limits || {}).gate_days || 14 }, function (_, i) {
            return '<option value="' + (i + 1) + '"' + (n.days === i + 1 ? ' selected' : '') + '>' + esc(window.tn ? window.tn(i + 1, '{n} jour', '{n} jours') : (i + 1)) + '</option>';
        }).join('');
        var opts = [T('approuver'), n.can_correct ? T('corriger') : '', n.can_return ? T('renvoyer') : '', T('arrêter')].filter(Boolean);
        return headFor('gate', (cat.hues || {}).gate, 'node.title', n.title)
            + issuesHtml(n.key)
            + choice({
                id: 'reviews', title: T('Ce qu’on relit'), ic: 'eye', summary: chipsSummary(n.reviews, T('À choisir')), empty: !(n.reviews || []).length, warn: !(n.reviews || []).length,
                body: chipToggles('review', Object.keys(avail).filter(function (k) { return pm[k]; }), n.reviews || []),
            })
            + sec('note', T('Ce qu’il faut vérifier'), mentionField({ id: 'wfGateNote', path: 'node.note', at: n.key, rows: 70, placeholder: T('Facultatif : ce que la personne doit regarder. Citez une pièce avec @.') }), '', 'pencil')
            + choice({
                id: 'options', title: T('Ce que vous pourrez faire'), ic: 'hand', summary: esc(opts.join(' · ')),
                body: '<div class="wf-mini">' + icon('check') + esc(T('Approuver et continuer, ou arrêter : toujours.')) + '</div>'
                    + check('node.can_correct', n.can_correct, T('Corriger la pièce directement'))
                    + check('node.can_return', n.can_return, T('Renvoyer à l’étape avec une remarque'), T('L’étape recommence avec votre remarque. Deux fois au plus.'))
                    + (n.can_return ? ret : ''),
            })
            + choice({
                id: 'days', title: T('Sans réponse'), ic: 'clock', summary: esc(T('{d}, puis l’exécution s’arrête', { d: window.tn ? window.tn(n.days, '{n} jour', '{n} jours') : n.days })),
                body: '<div class="wf-row2"><select class="wf-select" data-bind="node.days">' + days + '</select><span class="wf-hint">' + esc(T('puis l’exécution s’arrête')) + '</span></div>',
            });
    }

    // --- Boucle
    /** Le découpeur d'une boucle d'avant (sans `source.kind`), tel que l'écran le montre. */
    function legacyKind(n, pm) {
        var p = pm[n.over];
        return p && p.form === 'table' ? 'rows' : 'list';
    }
    function loopSourceDef(kind) {
        return (cat.loop_sources || []).filter(function (s) { return s.key === kind; })[0] || null;
    }
    /** Réglages neufs d'une source (ceux d'avant gardés quand ils valent encore). */
    /** Réglages d'une source quittée, par boucle et par façon de parcourir (le temps de la page). */
    var sourceMemo = {};

    function newSource(kind, old) {
        old = old || {};
        var s = { kind: kind };
        var def = loopSourceDef(kind) || {};
        s.from = def.folder ? 'drive' : (kind === 'emails' ? 'search' : 'piece');
        if (def.drive || def.folder) { s.drive = old.drive || ''; }
        if (kind === 'emails' || kind === 'attachments') {
            s.service = old.service || (sourceState('outlook') === 'ok' && sourceState('gmail') !== 'ok' ? 'outlook' : 'gmail');
            s.query = old.query || ''; s.days = old.days !== undefined ? old.days : 7; s.unread = !!old.unread;
        }
        if (kind === 'attachments') { s.types = old.types || 'all'; s.inline = !!old.inline; }
        if (kind === 'files') { s.name = old.name || ''; s.file_kind = old.file_kind || ''; }
        if (kind === 'rows') { s.sheet = old.sheet || ''; s.header = old.header || 0; s.columns = old.columns || []; s.label = old.label || ''; }
        if (kind === 'ai') { s.instruction = old.instruction || ''; s.fields = old.fields || []; }
        return s;
    }
    /** Pièces qu'un découpeur peut parcourir à cet endroit : [{value, label, piece, field}]. */
    function loopOverOptions(n, kind) {
        var avail = availableFor(n.key);
        var out = [];
        Object.keys(avail).forEach(function (k) {
            var p = avail[k];
            if (kind === 'list' && p.form === 'fiche') {
                (p.fields || []).filter(function (f) { return f.type === 'list'; }).forEach(function (f) {
                    out.push({ value: k + '|' + f.key, label: p.name + FIELD_SEP + f.label, piece: k, field: f.key });
                });
                return;
            }
            if (R.loopAccepts(kind, p, '')) {
                out.push({ value: k, label: p.name + ' — ' + ((L.forms || {})[p.form] || p.form), piece: k, field: '' });
            }
        });
        return out;
    }
    /** Colonnes d'un tableau parcouru, ou champs des éléments repérés par MIA. */
    function columnsEditor(n, list, attr, addAct, addLabel) {
        var types = L.ficheTypes || {};
        var rows = list.map(function (c, i) {
            return '<div class="wf-col-row"><input class="wf-input" data-' + attr + '="' + i + '" data-prop="label" value="' + esc(c.label || '') + '" placeholder="' + esc(T('Nom de la colonne, tel qu’écrit dans le fichier')) + '" aria-label="' + esc(T('Nom')) + '">'
                + '<select class="wf-select" data-' + attr + '="' + i + '" data-prop="type" aria-label="' + esc(T('Type')) + '">' + Object.keys(types).map(function (t) {
                    return '<option value="' + t + '"' + (c.type === t ? ' selected' : '') + '>' + esc(types[t]) + '</option>';
                }).join('') + '</select>'
                + '<button type="button" class="wf-tool danger" data-act="' + addAct + '-del" data-val="' + i + '" title="' + esc(T('Retirer')) + '" aria-label="' + esc(T('Retirer')) + '">' + icon('trash') + '</button></div>';
        }).join('');
        return rows + '<button type="button" class="wf-btn sm" data-act="' + addAct + '-add">' + icon('plus') + esc(addLabel) + '</button>';
    }
    /** Lire un fichier exemple (colonnes, nombre de lignes, de slides, de pages). */
    function sampleBlock(n, kind) {
        var sm = (state.samples || {})[n.key];
        var info = '';
        if (sm && sm.error) {
            info = '<p class="wf-hint wf-hint-warn">' + icon('alert') + esc(sm.error) + '</p>';
        } else if (sm && sm.kind === kind) {
            info = '<p class="wf-hint wf-hint-ok">' + icon('check') + esc(kind === 'rows'
                ? T('« {nom} » : {n} lignes, {c} colonnes{onglet}.', { nom: sm.name, n: sm.count, c: (sm.columns || []).length, onglet: (sm.sheets || []).length > 1 ? T(' (onglet « {o} »)', { o: sm.sheet }) : '' })
                : T('« {nom} » : {n} éléments.', { nom: sm.name, n: sm.count })) + '</p>';
            if (sm.labels && sm.labels.length) { info += '<p class="wf-hint">' + esc(sm.labels.join(' · ')) + '</p>'; }
        }
        return '<div class="wf-sample"><input type="file" class="wf-sample-input" data-loop-sample="' + esc(n.key) + '" data-kind="' + esc(kind) + '" hidden'
            + ' accept=".xlsx,.xlsm,.xls,.ods,.csv,.tsv,.txt,.pptx,.pdf,.docx,.md">'
            + '<button type="button" class="wf-btn sm" data-act="lsample" data-val="' + esc(n.key) + '">' + icon('download') + esc(kind === 'rows' ? T('Lire les colonnes d’un fichier exemple') : T('Essayer sur un fichier exemple')) + '</button>'
            + '<span class="wf-hint">' + esc(T('Le fichier est lu puis oublié : rien n’est gardé.')) + '</span></div>' + info;
    }
    function fromSeg(n, choices) {
        return '<div class="wf-seg">' + choices.map(function (c) {
            return '<button type="button" data-act="lfrom" data-val="' + c[0] + '"' + ((n.source.from || 'piece') === c[0] ? ' class="is-on"' : '') + '>' + icon(c[2]) + esc(c[1]) + '</button>';
        }).join('') + '</div>';
    }
    function serviceSelect(n) {
        var opts = ['gmail', 'outlook'].filter(function (k) { return sourceState(k) !== 'none' || n.source.service === k; });
        return '<select class="wf-select" data-bind="node.source.service" aria-label="' + esc(T('Boîte mail')) + '">' + opts.map(function (k) {
            var src = (cat.sources || []).filter(function (x) { return x.key === k; })[0];
            return '<option value="' + k + '"' + (n.source.service === k ? ' selected' : '') + '>' + esc(src ? src.label : k) + '</option>';
        }).join('') + '</select>';
    }
    function daysSelectLoop(n) {
        return '<select class="wf-select" data-bind="node.source.days" aria-label="' + esc(T('Période')) + '">' + [0, 1, 2, 7, 14, 30, 60, 90].map(function (d) {
            return '<option value="' + d + '"' + ((n.source.days || 0) === d ? ' selected' : '') + '>' + esc(d === 0 ? T('Toute période') : (d === 1 ? T('Depuis hier') : T('Les {n} derniers jours', { n: d }))) + '</option>';
        }).join('') + '</select>';
    }

    /** Les détails d'une source : la pièce ou le fichier Drive, la recherche, les colonnes… */
    function loopSourceBody(n) {
        var src = n.source;
        var kind = src.kind;
        var def = loopSourceDef(kind) || {};
        var out = '';
        var readsPiece = R.readsPiece(src);
        if (def.drive) {
            out += fromSeg(n, [['piece', T('Une pièce du dossier'), 'inbox'], ['drive', T('Un fichier Google Drive'), 'cloud']]);
        }
        if (kind === 'attachments') {
            out += fromSeg(n, [['piece', T('L’email reçu'), 'mail'], ['search', T('Les emails d’une recherche'), 'search']]);
        }
        if (readsPiece) {
            var opts = loopOverOptions(n, kind);
            var cur = n.over + (n.over_field ? '|' + n.over_field : '');
            out += '<label class="wf-lbl">' + esc(kind === 'attachments' ? T('L’email') : T('La pièce parcourue')) + '</label>' + (opts.length
                ? '<select class="wf-select" data-act-change="loop-over"><option value="">' + esc(T('Choisir…')) + '</option>' + opts.map(function (o) {
                    return '<option value="' + esc(o.value) + '"' + (o.value === cur ? ' selected' : '') + '>' + esc(o.label) + '</option>';
                }).join('') + '</select>'
                : '<p class="wf-hint wf-hint-warn">' + esc(kind === 'attachments'
                    ? T('Les pièces jointes se lisent dans l’email qui lance le workflow : choisissez le déclencheur « Un email arrive » au départ, ou cherchez des emails.')
                    : (kind === 'rows' || kind === 'slides' || kind === 'pages' || kind === 'sections'
                        ? T('Aucun fichier avant la boucle : ajoutez un champ Fichier au départ, ou lisez un fichier Google Drive.')
                        : T('Aucune pièce à parcourir avant la boucle : ajoutez une étape qui en remet une.'))) + '</p>');
        } else if (def.drive || def.folder) {
            out += '<label class="wf-lbl">' + esc(def.folder ? T('Le dossier Google Drive') : T('Le fichier Google Drive')) + '</label>'
                + mentionField({ id: 'wfLoopDrive', path: 'node.source.drive', at: n.key, input: true, accept: 'value', placeholder: def.folder ? T('Lien, nom ou chemin : Clients/Factures 2026') : T('Lien, nom ou chemin : Suivi/Clients.xlsx') });
        }
        if (kind === 'attachments' && src.from === 'search' || kind === 'emails') {
            out += '<label class="wf-lbl">' + esc(T('La recherche')) + '</label><div class="wf-row2">' + serviceSelect(n) + daysSelectLoop(n) + '</div>'
                + mentionField({ id: 'wfLoopQuery', path: 'node.source.query', at: n.key, input: true, accept: 'value', placeholder: T('Par exemple : facture from:compta@fournisseur.fr') })
                + check('node.source.unread', !!src.unread, T('Non lus seulement'));
        }
        if (kind === 'attachments') {
            var at = L.attachmentTypes || {};
            out += '<label class="wf-lbl">' + esc(T('Quelles pièces jointes')) + '</label><select class="wf-select" data-bind="node.source.types">' + Object.keys(at).map(function (k) {
                return '<option value="' + k + '"' + ((src.types || 'all') === k ? ' selected' : '') + '>' + esc(at[k]) + '</option>';
            }).join('') + '</select>' + check('node.source.inline', !!src.inline, T('Garder les images intégrées au corps'), T('Logos et signatures : écartés sinon.'));
        }
        if (kind === 'files') {
            var dk = L.driveKinds || {};
            out += '<div class="wf-row2"><input class="wf-input" data-bind="node.source.name" value="' + esc(src.name || '') + '" placeholder="' + esc(T('Nom contenant… (facultatif)')) + '" aria-label="' + esc(T('Nom contenant')) + '">'
                + '<select class="wf-select" data-bind="node.source.file_kind" aria-label="' + esc(T('Nature')) + '">' + Object.keys(dk).map(function (k) {
                    return '<option value="' + esc(k) + '"' + ((src.file_kind || '') === k ? ' selected' : '') + '>' + esc(dk[k] || T('Tous les fichiers')) + '</option>';
                }).join('') + '</select></div>';
        }
        if (kind === 'rows') {
            var headOpts = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(function (h) {
                return '<option value="' + h + '"' + ((src.header || 0) === h ? ' selected' : '') + '>' + esc(h === 0 ? T('En-tête détecté') : T('En-tête ligne {n}', { n: h })) + '</option>';
            }).join('');
            out += '<div class="wf-row2"><input class="wf-input" data-bind="node.source.sheet" value="' + esc(src.sheet || '') + '" placeholder="' + esc(T('Onglet (le premier si vide)')) + '" aria-label="' + esc(T('Onglet')) + '">'
                + '<select class="wf-select" data-bind="node.source.header" aria-label="' + esc(T('Ligne d’en-tête')) + '">' + headOpts + '</select></div>';
            out += '<label class="wf-lbl">' + esc(T('Les colonnes que les briques citent')) + '</label>'
                + columnsEditor(n, src.columns || (src.columns = []), 'lcol', 'lcol', T('Ajouter une colonne'));
            if ((src.columns || []).length) {
                out += '<label class="wf-lbl">' + esc(T('La colonne qui nomme chaque ligne')) + '</label><select class="wf-select" data-bind="node.source.label"><option value="">' + esc(T('La première colonne')) + '</option>'
                    + src.columns.map(function (c) { return '<option value="' + esc(c.key) + '"' + (src.label === c.key ? ' selected' : '') + '>' + esc(c.label) + '</option>'; }).join('') + '</select>';
            }
            out += '<p class="wf-hint">' + esc(T('Une colonne se retrouve dans le fichier par son nom (sans tenir compte des majuscules ni des accents). Une colonne absente arrête la boucle avant le premier tour, sans rien dépenser.')) + '</p>';
            out += sampleBlock(n, 'rows');
        }
        if (kind === 'slides' || kind === 'pages' || kind === 'sections') {
            out += sampleBlock(n, kind);
        }
        if (kind === 'ai') {
            out += '<label class="wf-lbl">' + esc(T('Ce que MIA repère')) + '</label>'
                + mentionField({ id: 'wfLoopAi', path: 'node.source.instruction', at: n.key, rows: 64, placeholder: T('Par exemple : chaque sujet abordé dans l’email, avec ce qui est demandé.') })
                + '<label class="wf-lbl">' + esc(T('Les champs de chaque élément (facultatif)')) + '</label>'
                + columnsEditor(n, src.fields || (src.fields = []), 'lfield', 'lfield', T('Ajouter un champ'))
                + '<p class="wf-hint">' + esc(T('Un appel à MIA lit la pièce et rend la liste ; la suite ne fait plus appel à l’IA pour découper. Cochez « Relire la liste » pour l’approuver avant les tours.')) + '</p>';
        }
        return out;
    }

    function loopInspector(n) {
        var pm = pieceMap();
        var lim = cat.limits || {};
        var modeSeg = '<div class="wf-seg"><button type="button" data-act="loopmode" data-val="each"' + (n.mode !== 'until' ? ' class="is-on"' : '') + '>' + icon('list') + esc(T('Pour chaque élément')) + '</button>'
            + '<button type="button" data-act="loopmode" data-val="until"' + (n.mode === 'until' ? ' class="is-on"' : '') + '>' + icon('retry') + esc(T('Jusqu’à un résultat')) + '</button></div>';
        var body = '';
        var summary = '';
        if (n.mode !== 'until') {
            n.source = n.source || { kind: '' };
            n.filter = n.filter || { rule: { join: 'all', items: [] }, dedupe: '', require_label: true };
            n.filter.rule = n.filter.rule || { join: 'all', items: [] };
            var kind = n.source.kind || '';
            var shownKind = kind || legacyKind(n, pm);
            var tiles = (cat.loop_sources || []).map(function (s) {
                return '<button type="button" class="wf-ltile' + (s.key === shownKind ? ' is-on' : '') + '" data-act="lsource" data-val="' + esc(s.key) + '" title="' + esc(s.help) + '">'
                    + '<span class="wf-ltile-ic">' + icon(s.icon) + '</span><b>' + esc(s.label) + '</b></button>';
            }).join('');
            var def = loopSourceDef(shownKind) || {};
            var itemP = pm[(n.item || {}).key];
            summary = '<p class="wf-loop-summary">' + icon('loop') + '<span>' + esc(R.loopSentence(n, R.readsPiece(n.source) ? pm[n.over] : null))
                + (n.filter.rule.items.length || n.filter.dedupe ? ' · ' + esc(T('filtrée')) : '') + ' · ' + esc(T('{n} au plus', { n: Math.min(n.max || 0, lim.each_max || 200) })) + '</span></p>';
            var max = [5, 10, 20, 50, 100, 200, 500].filter(function (v) { return v <= (lim.each_max || 200); });
            if (max.indexOf(Math.min(n.max, lim.each_max || 200)) < 0) { max.push(Math.min(n.max, lim.each_max || 200)); max.sort(function (a, b) { return a - b; }); }
            var maxSel = '<select class="wf-select" data-bind="node.max" aria-label="' + esc(T('Éléments au plus')) + '">' + max.map(function (v) {
                return '<option value="' + v + '"' + (Math.min(n.max, lim.each_max || 200) === v ? ' selected' : '') + '>' + esc(window.tn ? window.tn(v, '{n} élément au plus', '{n} éléments au plus') : v) + '</option>';
            }).join('') + '</select>';
            var fields = itemP && itemP.form === 'fiche' ? (itemP.fields || []) : [];
            var filterAvail = {};
            if (itemP) { filterAvail[itemP.key] = itemP; }
            var dedupe = fields.length ? '<label class="wf-lbl">' + esc(T('Sans doublon sur')) + '</label><select class="wf-select" data-bind="node.filter.dedupe"><option value="">' + esc(T('Garder les doublons')) + '</option>'
                + fields.map(function (f) { return '<option value="' + esc(f.key) + '"' + (n.filter.dedupe === f.key ? ' selected' : '') + '>' + esc(f.label) + '</option>'; }).join('') + '</select>' : '';
            var cites = fields.length
                ? T('Citez-le avec @ dans les briques de la boucle : @[{nom}] entier, ou un champ, comme @[{champ}].', { nom: (n.item || {}).name || '', champ: ((n.item || {}).name || '') + FIELD_SEP + fields[0].label })
                : T('Une pièce que les briques de la boucle citent avec @ : un élément à chaque tour.');
            var hasWrites = (n.body || []).some(function (c) { return (c.type === 'action' && c.write !== 'auto') || (c.type === 'step' && c.write === 'approve'); });
            body = summary
                + sec('source', T('1. Que parcourir ?'), (kind ? '' : '<p class="wf-hint">' + esc(T('Boucle d’avant les sources : choisissez une façon de parcourir pour profiter des colonnes, du filtre et des fichiers.')) + '</p>')
                    + '<div class="wf-ltiles">' + tiles + '</div>' + (def.help ? '<p class="wf-hint">' + esc(def.help) + '</p>' : '') + (kind ? loopSourceBody(n) : ''), '', 'list')
                + sec('filter', T('2. Quels éléments garder ?'), (fields.length
                        ? ruleEditor(n.filter.rule, filterAvail, 'frule')
                        : '<p class="wf-hint">' + esc(T('Le filtre lit les champs de l’élément : avec des colonnes ou des champs déclarés, une règle trie les éléments sans IA.')) + '</p>')
                    + dedupe + check('node.filter.require_label', n.filter.require_label !== false, T('Écarter les lignes de total et les éléments sans nom'), T('« Total », « Sous-total », une ligne sans nom en bas d’un tableau…'))
                    + '<div class="wf-row2">' + maxSel + '<span class="wf-hint">' + esc(T('au-delà, les éléments suivants sont signalés, pas traités')) + '</span></div>'
                    + check('node.confirm', !!n.confirm, T('Relire la liste avant de commencer'), T('Vous décochez ce qu’il ne faut pas traiter ; rien ne part avant votre accord.')), '', 'filter')
                + sec('item', T('L’élément en cours'), '<div class="wf-row2" style="grid-template-columns:minmax(0,1fr) auto"><input class="wf-input" data-bind="node.item.name" value="' + esc((n.item || {}).name || '') + '">'
                    + (itemP ? R.pieceChip(itemP, '') : '') + '</div>'
                    + (fields.length ? '<p class="wf-hint">' + esc(T('Ses champs : {liste}.', { liste: fields.map(function (f) { return f.label; }).join(', ') })) + (itemP && itemP.file ? ' ' + esc(T('Son fichier est joint au message des étapes qui le lisent.')) : '') + '</p>' : '')
                    + '<p class="wf-hint">' + esc(cites) + '</p>', '', 'at')
                + choice({
                    id: 'onitem', title: T('3. Si un élément échoue'), ic: 'alert',
                    summary: esc((L.itemError || {})[n.on_item_error === 'stop' ? 'stop' : 'continue'] || ''),
                    body: radio('litemerr', 'continue', n.on_item_error !== 'stop', (L.itemError || {}).continue || '', T('Les autres éléments continuent. Trois échecs d’affilée mettent la boucle en pause ; à la fin, « Refaire les éléments en échec » ne rejoue qu’eux.'))
                        + radio('litemerr', 'stop', n.on_item_error === 'stop', (L.itemError || {}).stop || '', T('Le premier échec arrête l’exécution, reprenable à cet élément.')),
                })
                + choice({
                    id: 'review_actions', title: T('Les actions préparées'), ic: 'hand',
                    summary: esc(n.review_actions ? T('Relues ensemble à la fin de la boucle') : T('Une carte à approuver par élément')),
                    body: check('node.review_actions', !!n.review_actions, T('Relire toutes les actions ensemble, à la fin de la boucle'), hasWrites
                        ? T('Chaque tour prépare son action sans attendre ; à la fin, une seule relecture : vous cochez celles qui partent.')
                        : T('Aucune brique de la boucle ne prépare encore d’action à approuver.')),
                });
        } else {
            var u = n.until || (n.until = { mode: 'question', question: '', reads: [], rule: { join: 'all', items: [] } });
            var uSeg = '<div class="wf-seg"><button type="button" data-act="umode" data-val="question"' + (u.mode !== 'rule' ? ' class="is-on"' : '') + '>' + icon('spark') + esc(T('Une question à MIA')) + '</button>'
                + '<button type="button" data-act="umode" data-val="rule"' + (u.mode === 'rule' ? ' class="is-on"' : '') + '>' + icon('table') + esc(T('Une règle')) + '</button></div>';
            var untilAvail = availableFor(n.key + '~until');
            var cond = u.mode === 'rule'
                ? ruleEditor(u.rule || { join: 'all', items: [] }, untilAvail, 'urule')
                : mentionField({ id: 'wfUntilQ', path: 'node.until.question', at: n.key + '~until', placeholder: T('Par exemple : la note de @[Relecture] est-elle d’au moins 8 ?'), rows: 70 })
                    + '<p class="wf-hint">' + esc(T('Après chaque tour, MIA répond oui (on s’arrête) ou non (un tour de plus).')) + '</p>';
            var umax = Array.from({ length: Math.max(1, (lim.until_max || 5) - 1) }, function (_, i) {
                return '<option value="' + (i + 2) + '"' + (n.max === i + 2 ? ' selected' : '') + '>' + esc(window.tn ? window.tn(i + 2, '{n} tour au plus', '{n} tours au plus') : (i + 2)) + '</option>';
            }).join('');
            body = sec('until', T('On s’arrête quand'), uSeg + cond, '', 'stop')
                + choice({
                    id: 'max', title: T('Combien de tours'), ic: 'retry', summary: esc((window.tn ? window.tn(n.max, '{n} tour au plus', '{n} tours au plus') : n.max) + ' · ' + (n.exhausted === 'stop' ? T('puis arrêter') : T('puis garder la dernière version'))),
                    body: '<select class="wf-select" data-bind="node.max">' + umax + '</select>'
                        + radio('exhausted', 'continue', n.exhausted !== 'stop', T('Puis garder la dernière version'), T('Le workflow continue avec le dernier tour.'))
                        + radio('exhausted', 'stop', n.exhausted === 'stop', T('Puis arrêter le workflow'), T('Sans résultat satisfaisant, rien ne part.'))
                        + '<p class="wf-hint">' + esc(T('À chaque nouveau tour, l’étape reprend sa version précédente et sait pourquoi elle ne suffisait pas.')) + '</p>',
                });
        }
        // Ce que la boucle rassemble : une étape ; « jusqu'à » suit aussi une création (R.loopCollect).
        var outs = (n.body || []).filter(function (c) { return c.output && (c.type === 'step' || (c.type === 'create' && n.mode === 'until')); });
        var collect = R.loopCollect(n);
        var collectSel = outs.length > 1
            ? '<select class="wf-select" data-bind="node.collect">' + outs.map(function (c) {
                return '<option value="' + esc(c.output.key) + '"' + (collect === c.output.key ? ' selected' : '') + '>' + esc((pm[c.output.key] || {}).name || c.output.name) + '</option>';
            }).join('') + '</select>'
            : '';
        var outPiece = pm[(n.output || {}).key];
        var outHelp = n.mode === 'until'
            ? T('La dernière version de la pièce suivie.')
            : (((n.source || {}).kind === 'rows' && (outPiece || {}).form === 'table')
                ? T('Un tableau : les colonnes du fichier, puis les champs remis à chaque tour, et un Statut si un élément n’a pas été traité. Téléchargeable en Excel.')
                : ({ table: T('Un tableau : une ligne par élément.'), list: T('Une liste : tous les éléments remis.'), document: T('Un document : une section par élément.') })[(outPiece || {}).form] || T('Un texte : une section par élément.'));
        return headFor('loop', (cat.hues || {}).loop, 'node.title', n.title)
            + issuesHtml(n.key)
            + choice({ id: 'mode', title: T('Comment répéter'), ic: 'loop', summary: esc(n.mode === 'until' ? T('Jusqu’à un résultat') : T('Pour chaque élément')), body: modeSeg })
            + body
            + sec('output', n.mode === 'until' ? T('Remet au dossier') : T('4. Ce que la boucle remet'), (collectSel ? '<p class="wf-hint">' + esc(T('La pièce suivie, à chaque tour :')) + '</p>' + collectSel : '')
                + '<div class="wf-row2" style="grid-template-columns:minmax(0,1fr) auto"><input class="wf-input" data-bind="node.output.name" value="' + esc((n.output || {}).name || '') + '" placeholder="' + esc(T('Nom de la pièce')) + '">'
                + (outPiece ? R.pieceChip(outPiece, '') : '') + '</div><p class="wf-hint">' + esc(outs.length ? outHelp : T('Ajoutez une étape dans la boucle : sa pièce sera rassemblée ici.')) + '</p>', '', 'inbox')
            + sec('body', T('Les briques répétées'), '<p class="wf-hint">' + esc(T('Ajoutez-les sur le rail, dans le cadre de la boucle, avec « + Ajouter ». Une condition y trie les éléments ; ni décision ni boucle à l’intérieur.')) + '</p>');
    }

    /** Un fichier exemple lu par le serveur : colonnes (avec leur type deviné), lignes, onglets. */
    function readSample(input) {
        var key = input.getAttribute('data-loop-sample');
        var kind = input.getAttribute('data-kind') || 'rows';
        var file = input.files && input.files[0];
        var at = find(key);
        if (!file || !at) { return; }
        var n = at.node;
        state.samples = state.samples || {};
        var fd = new FormData();
        fd.append('file', file);
        fd.append('kind', kind);
        fd.append('sheet', (n.source || {}).sheet || '');
        fd.append('header', String((n.source || {}).header || 0));
        setSave('saving', T('Lecture du fichier…'));
        fetch('/workflows/loop/sample', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() }, body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { state.samples[key] = { error: j.error || T('Fichier illisible.') }; setSave('error', j.error || T('Fichier illisible.')); renderInspector(); return; }
                var sm = j.sample;
                state.samples[key] = sm;
                if (kind === 'rows' && sm.columns) {
                    // Les colonnes du fichier remplacent celles déclarées ; une colonne
                    // déjà déclarée garde sa clé (les briques la citent peut-être).
                    var prev = {};
                    (n.source.columns || []).forEach(function (c) { prev[fold(c.label).replace(/[^a-z0-9]/g, '')] = c; });
                    n.source.columns = sm.columns.slice(0, (cat.limits || {}).columns || 20).map(function (c) {
                        var old = prev[fold(c.label).replace(/[^a-z0-9]/g, '')];
                        return old ? { key: old.key, label: c.label, type: old.type || c.type } : { key: c.key, label: c.label, type: c.type };
                    });
                    if (sm.sheets && sm.sheets.length > 1 && !n.source.sheet) { n.source.sheet = sm.sheet; }
                }
                setSave('', T('Fichier lu'));
                changed({ inspector: true });
            })
            .catch(function () { state.samples[key] = { error: T('Fichier illisible.') }; setSave('error', T('Fichier illisible.')); renderInspector(); });
    }

    // --- Attendre
    function waitInspector(n) {
        var avail = availableFor(n.key);
        var modeSeg = '<div class="wf-seg"><button type="button" data-act="waitmode" data-val="delay"' + (n.mode !== 'date' ? ' class="is-on"' : '') + '>' + icon('wait') + esc(T('Une durée')) + '</button>'
            + '<button type="button" data-act="waitmode" data-val="date"' + (n.mode === 'date' ? ' class="is-on"' : '') + '>' + icon('clock') + esc(T('Une date du dossier')) + '</button></div>';
        var body;
        if (n.mode !== 'date') {
            var units = L.units || {};
            body = sec('delay', T('Combien de temps'), '<div class="wf-row2"><input class="wf-input" type="number" min="1" max="999" data-bind="node.amount" value="' + esc(n.amount) + '">'
                + '<select class="wf-select" data-bind="node.unit">' + Object.keys(units).map(function (u) { return '<option value="' + u + '"' + (n.unit === u ? ' selected' : '') + '>' + esc(units[u]) + '</option>'; }).join('') + '</select></div>'
                + '<p class="wf-hint">' + esc(T('{n} jours au plus. Le workflow reprend seul, même si personne n’ouvre MIA.', { n: (cat.limits || {}).wait_days || 30 })) + '</p>', '', 'wait');
        } else {
            var opts = [];
            Object.keys(avail).forEach(function (k) {
                (avail[k].form === 'fiche' ? avail[k].fields || [] : []).filter(function (f) { return f.type === 'date'; }).forEach(function (f) {
                    var v = k + '|' + f.key;
                    opts.push('<option value="' + esc(v) + '"' + (n.piece === k && n.field === f.key ? ' selected' : '') + '>' + esc(avail[k].name + FIELD_SEP + f.label) + '</option>');
                });
            });
            body = sec('date', T('Jusqu’à quelle date'), (opts.length
                ? '<select class="wf-select" data-act-change="wait-field"><option value="">' + esc(T('Choisir un champ date…')) + '</option>' + opts.join('') + '</select>'
                : '<p class="wf-hint">' + esc(T('Aucune date avant l’attente : ajoutez un champ Date au départ, ou une étape qui remet une Fiche avec une date.')) + '</p>')
                + '<div class="wf-row2"><label class="wf-lab-in"><span>' + esc(T('À')) + '</span><input type="time" class="wf-input" data-bind="node.time" value="' + esc(n.time || '09:00') + '"></label>'
                + '<label class="wf-lab-in"><span>' + esc(T('Décalage (jours)')) + '</span><input type="number" min="-60" max="60" class="wf-input" data-bind="node.offset" value="' + esc(n.offset || 0) + '"></label></div>'
                + '<p class="wf-hint">' + esc(T('« -2 » : deux jours avant la date. Une date déjà passée ne fait pas attendre ; une date à plus de 90 jours arrête l’exécution.')) + '</p>', '', 'clock');
        }
        return headFor('wait', (cat.hues || {}).wait, 'node.title', n.title)
            + issuesHtml(n.key)
            + choice({ id: 'mode', title: T('Attendre'), ic: 'wait', summary: esc(n.mode === 'date' ? T('Une date du dossier') : T('Une durée')), body: modeSeg })
            + body;
    }

    // --- Me prévenir
    function notifyInspector(n) {
        return headFor('notify', (cat.hues || {}).notify, 'node.title', n.title)
            + issuesHtml(n.key)
            + sec('message', T('Le message'), mentionField({ id: 'wfNotifyMsg', path: 'node.message', at: n.key, placeholder: T('Par exemple : nouvelle demande de @[Email reçu › Expéditeur].'), rows: 80 })
                + '<p class="wf-hint">' + esc(T('Sur vos appareils inscrits (Espaces → Tâches programmées → Notifications) et dans la conversation. Sans IA, sans crédit. Un champ cité devient sa valeur ; une pièce, son nom.')) + '</p>', '', 'notify');
    }

    // --- Arrêter le workflow
    function stopInspector(n) {
        var O = L.stopOutcomes || {};
        var outcome = n.outcome === 'stopped' ? 'stopped' : 'done';
        return headFor('stop', (cat.hues || {}).stop, 'node.title', n.title)
            + issuesHtml(n.key)
            + choice({
                id: 'outcome', title: T('Statut de l’exécution'), ic: 'stop', summary: esc(O[outcome] || outcome),
                body: radio('soutcome', 'done', outcome === 'done', O.done || 'done', T('Une fin prévue : l’exécution apparaît comme terminée.'))
                    + radio('soutcome', 'stopped', outcome === 'stopped', O.stopped || 'stopped', T('Une sortie à remarquer : l’exécution apparaît comme arrêtée.'))
                    + '<p class="wf-hint">' + esc(T('Le workflow se termine ici, proprement : rien ne s’exécute après, ni le livrable ni ses livraisons. Ce qui a été produit reste dans le dossier.')) + '</p>',
            })
            + sec('message', T('Le mot de la fin'), mentionField({ id: 'wfStopMsg', path: 'node.message', at: n.key, placeholder: T('Facultatif. Par exemple : facture de @[Fiche facture › Montant] classée, pas d’accord nécessaire.'), rows: 80 })
                + '<p class="wf-hint">' + esc(T('Laissé dans la conversation de l’exécution. Un champ cité devient sa valeur ; une pièce, son nom.')) + '</p>', '', 'chat');
    }

    // --- Livrable
    function endInspector() {
        var e = state.def.end;
        var avail = Object.keys(availableFor('end'));
        return headFor('end', (cat.hues || {}).end, 'end.title', e.title)
            + issuesHtml('end')
            + choice({
                id: 'pieces', title: T('Ce que vous obtenez'), ic: 'inbox', summary: chipsSummary(e.pieces, T('Aucune pièce : choisissez ce que vous obtenez')), empty: !(e.pieces || []).length,
                body: chipToggles('deliver', avail, e.pieces || []) + '<p class="wf-hint">' + esc(T('Ces pièces arrivent dans la conversation du workflow, à ouvrir ou télécharger.')) + '</p>',
            })
            + deliveriesSection(e, avail)
            + sec('note', T('Un mot à la fin'), mentionField({ id: 'wfEndNote', path: 'end.note', at: 'end', rows: 70, placeholder: T('Facultatif. Un champ cité devient sa valeur : @[…].') }), '', 'pencil')
            + chainedNote();
    }

    /** « Lance ensuite : … » — les workflows qui démarrent quand celui-ci se termine. */
    function chainedNote() {
        var followers = (cat.followers || []);
        if (!followers.length) { return ''; }
        return sec('chain', T('Ensuite'), '<div class="wf-mini">' + icon('chain') + esc(T('Une fois terminé, lance : {liste}', { liste: followers.join(', ') })) + '</div>');
    }

    function deliveriesSection(e, avail) {
        var list = e.deliveries || (e.deliveries = []);
        var Ld = L.deliveries || {};
        var cards = list.map(function (d, i) { return deliveryCard(d, i, avail); }).join('');
        var room = list.length < ((cat.limits || {}).deliveries || 5);
        var add = room ? '<div class="wf-dl-add">' + ['email', 'drive', 'slack', 'kb'].map(function (t) {
            return '<button type="button" class="wf-btn sm" data-act="dl-add" data-val="' + t + '">' + icon(deliveryIcon(t)) + esc(Ld[t] || t) + '</button>';
        }).join('') + '</div>' : '';
        return sec('deliveries', T('Puis le livrable part'), (cards || '<p class="wf-hint">' + esc(T('Facultatif : envoyer, ranger ou publier le livrable à la fin, sans le faire à la main.')) + '</p>') + add, '', 'send');
    }

    function deliveryIcon(t) {
        return { email: 'mail', drive: 'folder', slack: 'chat', kb: 'kb' }[t] || 'send';
    }

    function sourceState(key) {
        var s = (cat.sources || []).filter(function (x) { return x.key === key; })[0];
        return s ? (s.connected ? 'ok' : 'off') : 'none';
    }

    /** Une livraison en une ligne (repliée) : à qui, où, comment. */
    function deliverySummary(d) {
        var M = d.type === 'email' ? (L.mailModes || {}) : (L.deliveryModes || {});
        var pm = pieceMap();
        var names = (d.type === 'email' ? d.attach : d.pieces) || [];
        var bits = [];
        if (d.type === 'email') {
            var to = toDisplay(d.to || '');
            if (d.to_field) { var tf = d.to_field.split('.'); var p = pm[tf[0]]; var f = p ? (p.fields || []).filter(function (x) { return x.key === tf[1]; })[0] : null; if (f) { to = (to ? to + ', ' : '') + '@[' + p.name + FIELD_SEP + f.label + ']'; } }
            bits.push(to ? T('à {qui}', { qui: to }) : T('destinataire à choisir'));
        }
        if (d.type === 'drive' && d.folder) { bits.push(T('dans « {dossier} »', { dossier: toDisplay(d.folder) })); }
        if (d.type === 'slack' && d.channel) { bits.push(d.channel); }
        if (d.type !== 'kb') { bits.push(M[d.mode] || d.mode); }
        return esc(bits.join(' · ')) + (names.length ? ' <span class="wf-sum-chips">' + names.filter(function (k) { return pm[k]; }).map(function (k) { return chip(pm[k]); }).join('') + '</span>' : '');
    }

    function deliveryCard(d, i, avail) {
        var pm = pieceMap();
        var Ld = L.deliveries || {};
        var base = 'end.deliveries.' + i;
        var toggles = function (act, keys, on) {
            if (!keys.length) { return '<p class="wf-hint">' + esc(T('Aucune pièce disponible à la fin.')) + '</p>'; }
            return '<div class="wf-chips">' + keys.map(function (k) {
                var sel = (on || []).indexOf(k) >= 0;
                return '<button type="button" class="wf-piece wf-chip-toggle' + (sel ? '' : ' is-off') + '" data-act="' + act + '" data-val="' + i + '|' + esc(k) + '" style="--c:' + esc(pm[k].color) + '">'
                    + icon(pm[k].form === 'file' ? 'file' : pm[k].form) + '<span>' + esc(pm[k].name) + '</span></button>';
            }).join('') + '</div>';
        };
        var modes = function (allowed, labels) {
            return '<div class="wf-seg wf-seg-wrap">' + allowed.map(function (m) {
                var off = m !== 'approve' && !cat.auto;
                return '<button type="button" data-act="dl-mode" data-val="' + i + '|' + m + '"' + (d.mode === m ? ' class="is-on"' : '') + (off ? ' aria-disabled="true" title="' + esc(T('Retiré par l’administrateur.')) + '"' : '') + '>' + esc(labels[m] || m) + '</button>';
            }).join('') + '</div>';
        };
        var warn = function (src) {
            var st = sourceState(src);
            return st === 'off' ? '<div class="wf-todo">' + icon('alert') + '<div>' + esc(T('Reliez d’abord ce service dans Réglages → Comptes connectés.')) + '</div></div>'
                : st === 'none' ? '<div class="wf-todo block">' + icon('alert') + '<div>' + esc(T('Ce connecteur n’est pas proposé sur cette instance.')) + '</div></div>' : '';
        };
        var body = '';
        if (d.type === 'email') {
            body = '<div class="wf-seg">' + ['gmail', 'outlook'].map(function (src) {
                return '<button type="button" data-act="dl-source" data-val="' + i + '|' + src + '"' + (d.source === src ? ' class="is-on"' : '') + '>' + esc(src === 'gmail' ? 'Gmail' : 'Outlook') + '</button>';
            }).join('') + '</div>' + warn(d.source)
                + '<label class="wf-lab">' + esc(T('À')) + '</label>' + mentionField({ id: 'wfDlTo' + i, path: base + '.to', at: 'end', accept: 'email', input: true, placeholder: T('adresse@exemple.fr, ou @ pour une adresse du dossier') })
                + (d.to_field ? '<select class="wf-select" data-bind="' + base + '.to_field"><option value="">' + esc(T('… ou l’adresse d’un champ du dossier')) + '</option>' + emailFieldOptions(avail, d.to_field) + '</select>' : '')
                + '<label class="wf-lab">' + esc(T('Objet')) + '</label>' + mentionField({ id: 'wfDlSubj' + i, path: base + '.subject', at: 'end', accept: 'value', input: true, placeholder: T('Par exemple : Compte rendu — @[Informations de départ › Client]') })
                + '<label class="wf-lab">' + esc(T('Message')) + '</label>' + mentionField({ id: 'wfDlMsg' + i, path: base + '.message', at: 'end', rows: 90, placeholder: T('Bonjour, voici… — citez une pièce avec @ : son contenu sera inséré.') })
                + '<label class="wf-lab">' + esc(T('Pièces jointes')) + '</label>' + toggles('dl-attach', avail, d.attach)
                + '<label class="wf-lab">' + esc(T('Comment il part')) + '</label>' + modes(['approve', 'draft', 'send'], L.mailModes || {});
        } else if (d.type === 'drive') {
            body = warn('drive') + '<label class="wf-lab">' + esc(T('Pièces à enregistrer')) + '</label>' + toggles('dl-piece', avail, d.pieces)
                + '<label class="wf-lab">' + esc(T('Dossier')) + '</label>' + mentionField({ id: 'wfDlFolder' + i, path: base + '.folder', at: 'end', accept: 'value', input: true, placeholder: T('Lien du dossier, ou son chemin : Clients/@[…]') })
                + '<p class="wf-hint">' + esc(T('Vide : à la racine de Mon Drive. Un tableau devient un Google Sheet, le reste un Google Doc.')) + '</p>'
                + modes(['approve', 'auto'], L.deliveryModes || {});
        } else if (d.type === 'slack') {
            body = warn('slack') + '<label class="wf-lab">' + esc(T('Canal')) + '</label><input class="wf-input" data-bind="' + base + '.channel" value="' + esc(d.channel || '') + '" placeholder="#projet">'
                + '<label class="wf-lab">' + esc(T('Message')) + '</label>' + mentionField({ id: 'wfDlSlack' + i, path: base + '.message', at: 'end', rows: 80, placeholder: T('Citez une pièce avec @ : son contenu sera inséré.') })
                + '<label class="wf-lab">' + esc(T('Pièces publiées à la suite')) + '</label>' + toggles('dl-piece', avail, d.pieces)
                + modes(['approve', 'auto'], L.deliveryModes || {});
        } else {
            var kbs = cat.delivery_kbs || [];
            body = (kbs.length
                ? '<select class="wf-select" data-bind="' + base + '.kb_id"><option value="">' + esc(T('Choisir une base…')) + '</option>' + kbs.map(function (kb) {
                    return '<option value="' + kb.id + '"' + (d.kb_id === kb.id ? ' selected' : '') + '>' + esc(kb.name) + '</option>';
                }).join('') + '</select>'
                : '<p class="wf-hint">' + esc(T('Aucune base de connaissances que vous gérez.')) + '</p>')
                + '<label class="wf-lab">' + esc(T('Pièces à ajouter')) + '</label>' + toggles('dl-piece', avail, d.pieces)
                + check(base + '.replace', d.replace !== false, T('Remplacer la version précédente'), T('Sinon, chaque exécution ajoute un document daté.'));
        }
        var id = 'delivery:' + i;
        var open = !!state.expanded[expandKey(id)];
        return '<div class="wf-dl' + (open ? ' is-open' : '') + '" data-sec="' + id + '"><div class="wf-dl-head"><span class="wf-node-ic">' + icon(deliveryIcon(d.type)) + '</span>'
            + '<button type="button" class="wf-dl-toggle" data-act="dl-expand" data-val="' + i + '" aria-expanded="' + (open ? 'true' : 'false') + '"><b>' + esc(Ld[d.type] || d.type) + '</b><small>' + deliverySummary(d) + '</small></button>'
            + '<span class="wf-choice-chev">' + icon('chev') + '</span>'
            + '<button type="button" class="wf-tool danger" data-act="dl-del" data-val="' + i + '" title="' + esc(T('Retirer')) + '" aria-label="' + esc(T('Retirer')) + '">' + icon('trash') + '</button></div>'
            + '<div class="wf-dl-body"' + (open ? '' : ' hidden') + '>' + body + '</div></div>';
    }

    function emailFieldOptions(avail, selected) {
        var pm = pieceMap();
        var out = '';
        avail.forEach(function (k) {
            (pm[k].form === 'fiche' ? pm[k].fields || [] : []).filter(function (f) { return f.type === 'email'; }).forEach(function (f) {
                out += '<option value="' + esc(k + '.' + f.key) + '"' + (selected === k + '.' + f.key ? ' selected' : '') + '>' + esc(pm[k].name + FIELD_SEP + f.label) + '</option>';
            });
        });
        return out;
    }

    function newDelivery(type) {
        var avail = Object.keys(availableFor('end'));
        var last = (state.def.end.pieces || []).slice();
        if (!last.length && avail.length) { last = [avail[avail.length - 1]]; }
        var pm = pieceMap();
        var docs = last.filter(function (k) { return pm[k] && (pm[k].form === 'document' || pm[k].form === 'table' || pm[k].form === 'file'); });
        switch (type) {
            case 'email': return { type: 'email', source: sourceState('outlook') === 'ok' && sourceState('gmail') !== 'ok' ? 'outlook' : 'gmail', to: '', to_field: '', subject: state.def.end.title || '', message: '', attach: docs, mode: 'approve' };
            case 'drive': return { type: 'drive', pieces: last, folder: '', mode: 'approve' };
            case 'slack': return { type: 'slack', channel: '', message: '', pieces: last, mode: 'approve' };
            default: return { type: 'kb', kb_id: null, pieces: last, replace: true };
        }
    }

    // ------------------------------------------------------------------
    // Saisie dans l'inspecteur
    // ------------------------------------------------------------------

    function current() { return state.selected && state.selected !== 'start' && state.selected !== 'end' ? (find(state.selected) || {}).node : null; }

    function setPath(path, value) {
        var parts = path.split('.');
        var obj = parts[0] === 'node' ? current() : parts[0] === 'start' ? state.def.start : parts[0] === 'end' ? state.def.end : state.def;
        if (!obj) { return; }
        for (var i = 1; i < parts.length - 1; i++) {
            if (obj[parts[i]] === undefined || obj[parts[i]] === null) { obj[parts[i]] = {}; }
            obj = obj[parts[i]];
        }
        obj[parts[parts.length - 1]] = value;
    }

    /** Ce qui change la forme du volet (une case qui en montre d'autres, un mode…) : il est redessiné. */
    var STRUCTURAL = /review|write|output\.format|then|returns_to|can_return|rule\.join|collect|unit|kb_id|to_field|workflow_id|filters\.(?:mail|drive)\.(?:attachment|unread|days)|source\.(?:service|label|header|days|types)|filter\.dedupe|confirm|node\.max/;

    insHost.addEventListener('input', function (e) {
        var el = e.target;
        var n = current();
        if (el.matches('[data-mention-path]')) {
            setPath(el.getAttribute('data-mention-path'), fromDisplay(el.value));
            paintHl(el);
            mentionInput(el);
            changed();
            return;
        }
        if (el.matches('[data-bind]') && el.type !== 'checkbox' && el.tagName !== 'SELECT') {
            setPath(el.getAttribute('data-bind'), el.value);
            changed();
            return;
        }
        if (el.matches('[data-field]') && el.type !== 'checkbox' && el.tagName !== 'SELECT') {
            var f = state.def.start.fields[+el.getAttribute('data-field')];
            var prop = el.getAttribute('data-prop');
            f[prop] = prop === 'options' ? el.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : el.value;
            changed();
            return;
        }
        if (el.matches('[data-ofield]') && el.tagName !== 'SELECT') {
            n.output.fields[+el.getAttribute('data-ofield')].label = el.value;
            changed();
            return;
        }
        if (el.matches('[data-rule]') && el.tagName !== 'SELECT') {
            n.rule.items[+el.getAttribute('data-rule')].value = el.value;
            changed();
            return;
        }
        if (el.matches('[data-urule]') && el.tagName !== 'SELECT') {
            n.until.rule.items[+el.getAttribute('data-urule')].value = el.value;
            changed();
            return;
        }
        if (el.matches('[data-frule]') && el.tagName !== 'SELECT') {
            n.filter.rule.items[+el.getAttribute('data-frule')].value = el.value;
            changed();
            return;
        }
        if ((el.matches('[data-lcol]') || el.matches('[data-lfield]')) && el.tagName !== 'SELECT') {
            var cl = el.matches('[data-lcol]') ? n.source.columns : n.source.fields;
            var ci = +el.getAttribute(el.matches('[data-lcol]') ? 'data-lcol' : 'data-lfield');
            // La clé ne bouge pas : les briques citent la colonne par elle, l'écran par son nom.
            cl[ci].label = el.value;
            changed();
        }
    });

    // Le calque des citations suit le défilement de son champ (scroll ne
    // remonte pas : capture).
    insHost.addEventListener('scroll', function (e) {
        if (e.target.matches && e.target.matches('[data-mention-path]')) { syncHl(e.target); }
    }, true);

    insHost.addEventListener('change', function (e) {
        var el = e.target;
        var n = current();
        if (el.matches('[data-bind]')) {
            var path = el.getAttribute('data-bind');
            var val = el.type === 'checkbox' ? el.checked : el.value;
            if (path === 'start.schedule') {
                state.def.start.schedule = el.checked
                    ? { rule: { freq: 'weekly', days: [1], time: '08:00' }, timezone: (Intl.DateTimeFormat().resolvedOptions().timeZone || '') }
                    : null;
                if (!el.checked) { state.scheduleText = ''; }
                changed({ inspector: true });
                afterStart();
                return;
            }
            if (path === 'node.tool_id' || path === 'node.days' || path === 'start.event.workflow_id' || /^end\.deliveries\.\d+\.kb_id$/.test(path)) { val = parseInt(val, 10) || null; }
            if (path === 'node.max' || path === 'node.amount') { val = Math.max(1, parseInt(val, 10) || 1); }
            if (path === 'node.source.header' || path === 'node.source.days') { val = Math.max(0, parseInt(val, 10) || 0); }
            if (path === 'node.offset') { val = Math.max(-60, Math.min(60, parseInt(val, 10) || 0)); }
            if (/\.days$/.test(path) && path !== 'node.days') { val = Math.max(0, parseInt(val, 10) || 0); }
            if (path === 'def.notify') { state.def.notify = el.checked; scheduleSave(); return; }
            setPath(path, val);
            changed({ inspector: STRUCTURAL.test(path) && el.type !== 'text' });
            return;
        }
        if (el.matches('[data-field]')) {
            var f = state.def.start.fields[+el.getAttribute('data-field')];
            var prop = el.getAttribute('data-prop');
            f[prop] = el.type === 'checkbox' ? el.checked : el.value;
            changed({ inspector: prop === 'type' });
            return;
        }
        if (el.matches('[data-ofield]') && el.tagName === 'SELECT') {
            n.output.fields[+el.getAttribute('data-ofield')].type = el.value;
            changed();
            return;
        }
        if ((el.matches('[data-lcol]') || el.matches('[data-lfield]')) && el.tagName === 'SELECT') {
            var tl = el.matches('[data-lcol]') ? n.source.columns : n.source.fields;
            tl[+el.getAttribute(el.matches('[data-lcol]') ? 'data-lcol' : 'data-lfield')].type = el.value;
            changed({ inspector: true });
            return;
        }
        if ((el.matches('[data-rule]') || el.matches('[data-urule]') || el.matches('[data-frule]')) && el.tagName === 'SELECT') {
            var u = el.matches('[data-urule]');
            var fr = el.matches('[data-frule]');
            var it = (fr ? n.filter.rule : (u ? n.until.rule : n.rule)).items[+el.getAttribute(fr ? 'data-frule' : (u ? 'data-urule' : 'data-rule'))];
            if (el.getAttribute('data-prop') === 'field') {
                var pf = el.value.split('|');
                it.piece = pf[0] || '';
                it.field = pf[1] || '';
            } else {
                it.op = el.value;
            }
            changed({ inspector: true });
            return;
        }
        if (el.matches('[data-act-change="loop-over"]')) {
            var lo = el.value.split('|');
            n.over = lo[0] || '';
            n.over_field = lo[1] || '';
            changed({ inspector: true });
            return;
        }
        if (el.matches('[data-loop-sample]')) {
            readSample(el);
            return;
        }
        if (el.matches('[data-act-change="wait-field"]')) {
            var wf = el.value.split('|');
            n.piece = wf[0] || '';
            n.field = wf[1] || '';
            changed({ inspector: true });
            return;
        }
        if (el.matches('[data-act-change]')) {
            var sc = state.def.start.schedule;
            var a = el.getAttribute('data-act-change');
            if (a === 'time') { sc.rule.time = el.value; }
            if (a === 'mday') { sc.rule.day = el.value === 'last' ? 'last' : parseInt(el.value, 10); }
            changed();
            previewSchedule();
        }
    });

    insHost.addEventListener('keydown', function (e) {
        if (!mentionPop || !e.target.matches('[data-mention-path]')) { return; }
        var items = Array.prototype.slice.call(mentionPop.querySelectorAll('[data-k]'));
        if (e.key === 'Escape') { e.preventDefault(); closeMention(); return; }
        if (!items.length) { return; }
        var i = items.findIndex(function (b) { return b.classList.contains('is-on'); });
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            items[i].classList.remove('is-on');
            i = (i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
            items[i].classList.add('is-on');
            items[i].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            var m = mentionToken(e.target);
            var byKey = {};
            mentionables(e.target.getAttribute('data-mention-at'), e.target.getAttribute('data-accept') || 'any').forEach(function (p) { byKey[p.key] = p; });
            insertMention(e.target, byKey[items[i].getAttribute('data-k')], m ? m[0].length : 0);
        }
    });
    insHost.addEventListener('focusout', function (e) {
        if (e.target.matches('[data-mention-path]')) { setTimeout(closeMention, 150); }
    });
    // Le bouton @ ne prend pas le focus : un champ en cours de saisie garde
    // son curseur, et c'est là que la pièce s'insère.
    insHost.addEventListener('mousedown', function (e) {
        if (e.target.closest('.wf-at')) { e.preventDefault(); }
    });

    insHost.addEventListener('click', function (e) {
        var tool = e.target.closest('[data-tool]');
        if (tool) {
            var tk = tool.getAttribute('data-node');
            var ta = tool.getAttribute('data-tool');
            if (ta === 'up') { moveNode(tk, -1); renderInspector(); }
            if (ta === 'down') { moveNode(tk, 1); renderInspector(); }
            if (ta === 'dup') { duplicateNode(tk); }
            if (ta === 'del') { deleteNode(tk); }
            return;
        }
        var b = e.target.closest('[data-act]');
        if (!b || b.getAttribute('aria-disabled') === 'true') {
            var li = e.target.closest('[data-hl]');
            if (li) {
                var k = li.getAttribute('data-hl');
                state.highlight = state.highlight === k ? null : k;
                R.highlight(railHost, state.highlight);
                renderInspector();
            }
            return;
        }
        var act = b.getAttribute('data-act');
        var val = b.getAttribute('data-val');
        var n = current();
        var toggle = function (arr, v) { var i = arr.indexOf(v); if (i >= 0) { arr.splice(i, 1); } else { arr.push(v); } };
        switch (act) {
            case 'deselect': select(null); return;
            case 'goto': select(val, b.getAttribute('data-spot') || 'issues'); return;
            case 'spot': spotlight(val); return;
            case 'expand': toggleChoice(val); return;
            case 'dl-expand':
                var dk = expandKey('delivery:' + val);
                state.expanded[dk] = !state.expanded[dk];
                var dl = insHost.querySelector('[data-sec="delivery:' + val + '"]');
                if (dl) {
                    dl.classList.toggle('is-open', state.expanded[dk]);
                    dl.querySelector('.wf-dl-body').hidden = !state.expanded[dk];
                    b.setAttribute('aria-expanded', state.expanded[dk] ? 'true' : 'false');
                }
                return;
            case 'at':
                // Le bouton @ : comme taper « @ » au curseur.
                var fld = document.getElementById(val);
                if (fld) {
                    // Champ en cours de saisie : au curseur ; sinon, à la fin du texte.
                    var typing = document.activeElement === fld;
                    fld.focus();
                    var pos = typing && typeof fld.selectionStart === 'number' ? fld.selectionStart : fld.value.length;
                    var before = fld.value.slice(0, pos);
                    var pad = before && !/\s$/.test(before) ? ' ' : '';
                    fld.value = before + pad + '@' + fld.value.slice(pos);
                    fld.setSelectionRange(pos + pad.length + 1, pos + pad.length + 1);
                    paintHl(fld);
                    openMention(fld, '', 1);
                }
                return;
            case 'duplicate': return duplicateWorkflow();
            case 'delete': return deleteWorkflow();
            case 'profile':
                n.profile_id = val === '' ? null : parseInt(val, 10);
                if (n.profile_id !== null) { n.llm = ''; }
                break;
            case 'source': toggle(n.sources, val); break;
            case 'kb':
                var id = parseInt(val, 10);
                if (n.kb_ids.indexOf(id) < 0 && n.kb_ids.length >= ((cat.limits || {}).kbs || 3)) { return; }
                toggle(n.kb_ids, id);
                break;
            case 'tool':
                var tid = parseInt(val, 10);
                var tool2 = (cat.tools || []).filter(function (x) { return x.id === tid; })[0];
                // Un outil par type : choisir un outil retire celui du même type.
                if (n.tool_ids.indexOf(tid) < 0 && tool2) {
                    n.tool_ids = n.tool_ids.filter(function (o) { var t = (cat.tools || []).filter(function (x) { return x.id === o; })[0]; return !t || t.type !== tool2.type; });
                }
                toggle(n.tool_ids, tid);
                break;
            case 'write': n.write = val; break;
            case 'ckind':
                // Une autre nature : sa forme de pièce, ses réglages, son premier outil.
                if (n.kind !== val && CREATE_DEFAULTS[val]) {
                    n.kind = val;
                    n.output.form = val;
                    n.options = val === 'image' ? { format: '' } : (val === 'page' ? { style: '' } : {});
                    n.tool_id = firstCreationTool(val);
                }
                break;
            case 'ctool': n.tool_id = parseInt(val, 10) || null; break;
            case 'cformat':
                if (!n.options || Array.isArray(n.options)) { n.options = {}; }
                n.options.format = val;
                break;
            case 'form':
                n.output.form = val;
                if (val === 'fiche' && !(n.output.fields || []).length) { n.output.fields = [{ key: newKey('f'), label: T('Champ'), type: 'text' }]; }
                if (val === 'document' && !n.output.format) { n.output.format = 'docx'; n.output.template = true; }
                break;
            case 'ofield-add': n.output.fields = (n.output.fields || []).concat([{ key: newKey('f'), label: '', type: 'text' }]); break;
            case 'ofield-del': n.output.fields.splice(+val, 1); break;
            case 'read': toggle(n.reads, val); break;
            case 'review': toggle(n.reviews, val); break;
            case 'deliver': toggle(state.def.end.pieces, val); break;
            case 'kind':
                var kv = String(val).split('|');
                setPath(kv[0], kv[1] || '');
                break;
            case 'target':
                n.target = val;
                var tg = (cat.targets || []).filter(function (t) { return t.key === val; })[0];
                n.tool_id = tg && tg.kind === 'tool' && tg.tools[0] ? tg.tools[0].id : null;
                n.filters = n.filters || blankFilters();
                break;
            case 'cmode': n.mode = val; break;
            case 'soutcome': n.outcome = val === 'stopped' ? 'stopped' : 'done'; break;
            case 'doubt': n.doubt = val; break;
            case 'fallback': n.fallback = val; break;
            case 'route-add':
                n.routes.push(newRoute(T('Voie {n}', { n: n.routes.length + 1 }), n.routes));
                changed({ inspector: true });
                spotlight('route:' + (n.routes.length - 1));
                return;
            case 'route-del':
                var rd = n.routes[+val];
                if (!rd) { return; }
                if ((rd.nodes || []).length && !window.confirm(T('Retirer la voie « {voie} » et ses briques ?', { voie: rd.label || '…' }))) { return; }
                n.routes.splice(+val, 1);
                if (n.fallback === rd.key) { n.fallback = 'ask'; }
                break;
            case 'route-up': case 'route-down':
                var ri = +val;
                var rj = ri + (act === 'route-up' ? -1 : 1);
                if (rj < 0 || rj >= n.routes.length) { return; }
                n.routes.splice(rj, 0, n.routes.splice(ri, 1)[0]);
                break;
            case 'rule-add': n.rule.items.push({ piece: '', field: '', op: 'eq', value: '' }); break;
            case 'rule-del': n.rule.items.splice(+val, 1); break;
            case 'field-add':
                state.def.start.fields.push({ key: newKey('c'), label: '', type: 'text', required: true, options: [], default: '', help: '' });
                break;
            case 'field-del': state.def.start.fields.splice(+val, 1); break;
            case 'freq':
                state.def.start.schedule.rule.freq = val;
                if (val === 'weekly' && !(state.def.start.schedule.rule.days || []).length) { state.def.start.schedule.rule.days = [1]; }
                if (val === 'monthly' && !state.def.start.schedule.rule.day) { state.def.start.schedule.rule.day = 1; }
                changed({ inspector: true });
                previewSchedule();
                return;
            case 'day':
                var days = state.def.start.schedule.rule.days = state.def.start.schedule.rule.days || [];
                toggle(days, parseInt(val, 10));
                days.sort();
                changed({ inspector: true });
                previewSchedule();
                return;
            case 'retries': (n.on_error = n.on_error || { then: 'stop' }).retries = parseInt(val, 10) || 0; break;
            case 'onerror': (n.on_error = n.on_error || { retries: 1 }).then = val === 'skip' ? 'skip' : 'stop'; break;
            case 'lsource':
                var oldKind = (n.source || {}).kind || legacyKind(n, pieceMap());
                // Revenir à une façon de parcourir déjà réglée la retrouve telle quelle
                // (colonnes, champs, recherche) : une tuile touchée par erreur ne coûte rien.
                if (oldKind && oldKind !== val) {
                    sourceMemo[n.key + ':' + oldKind] = { source: JSON.parse(JSON.stringify(n.source || {})), over: n.over, over_field: n.over_field };
                }
                var memo = sourceMemo[n.key + ':' + val];
                n.source = memo && val !== oldKind ? JSON.parse(JSON.stringify(memo.source)) : newSource(val, val === oldKind ? n.source : (n.source || {}));
                if (memo && val !== oldKind) { n.over = memo.over; n.over_field = memo.over_field; }
                // La pièce parcourue : la même si le découpeur l'accepte, sinon la dernière qui convient.
                var ok = loopOverOptions(n, val);
                if (!ok.some(function (o) { return o.piece === n.over && (o.field === (n.over_field || '') || val !== 'list'); })) {
                    var lastOpt = ok[ok.length - 1];
                    n.over = lastOpt ? lastOpt.piece : '';
                    n.over_field = lastOpt ? lastOpt.field : '';
                }
                if (!R.readsPiece(n.source)) { n.over = ''; n.over_field = ''; }
                break;
            case 'lfrom':
                n.source.from = val;
                if (!R.readsPiece(n.source)) { n.over = ''; n.over_field = ''; }
                break;
            case 'litemerr': n.on_item_error = val === 'stop' ? 'stop' : 'continue'; break;
            case 'lcol-add': (n.source.columns = n.source.columns || []).push({ key: newKey('c'), label: '', type: 'text' }); break;
            case 'lcol-del':
                var rm = n.source.columns.splice(+val, 1)[0];
                if (rm && n.source.label === rm.key) { n.source.label = ''; }
                if (rm && n.filter && n.filter.dedupe === rm.key) { n.filter.dedupe = ''; }
                break;
            case 'lfield-add': (n.source.fields = n.source.fields || []).push({ key: newKey('f'), label: '', type: 'text' }); break;
            case 'lfield-del': n.source.fields.splice(+val, 1); break;
            case 'frule-add': n.filter.rule.items.push({ piece: (n.item || {}).key || '', field: '', op: 'eq', value: '' }); break;
            case 'frule-del': n.filter.rule.items.splice(+val, 1); break;
            case 'lsample':
                var fi = insHost.querySelector('[data-loop-sample="' + val + '"]');
                if (fi) { fi.value = ''; fi.click(); }
                return;
            case 'loopmode':
                n.mode = val === 'until' ? 'until' : 'each';
                n.max = n.mode === 'until' ? Math.min(Math.max(n.max || 3, 2), (cat.limits || {}).until_max || 5) : Math.min(n.max || 10, (cat.limits || {}).each_max || 20);
                if (n.mode === 'until' && n.max > 3) { n.max = 3; }
                break;
            case 'umode': n.until.mode = val === 'rule' ? 'rule' : 'question'; break;
            case 'urule-add': n.until.rule.items.push({ piece: '', field: '', op: 'eq', value: '' }); break;
            case 'urule-del': n.until.rule.items.splice(+val, 1); break;
            case 'exhausted': n.exhausted = val === 'stop' ? 'stop' : 'continue'; break;
            case 'waitmode': n.mode = val === 'date' ? 'date' : 'delay'; break;
            case 'event':
                state.def.start.event = val ? {
                    kind: val, source: sourceState('outlook') === 'ok' && sourceState('gmail') !== 'ok' ? 'outlook' : 'gmail', query: '', folder: '', folder_name: '',
                    workflow_id: null, piece_name: '', timezone: (Intl.DateTimeFormat().resolvedOptions().timeZone || ''), filters: blankFilters(),
                } : null;
                changed({ inspector: true });
                afterStart();
                return;
            case 'evsource': state.def.start.event.source = val; break;
            case 'hook-copy':
                var hu = $('#wfHookUrl');
                if (hu && hu.value) {
                    (navigator.clipboard ? navigator.clipboard.writeText(hu.value) : Promise.reject()).then(function () { setSave('', T('Adresse copiée')); }, function () { hu.select(); });
                }
                return;
            case 'hook-renew': state.hookUrl = ''; loadHook(true); return;
            case 'dl-add':
                (state.def.end.deliveries = state.def.end.deliveries || []).push(newDelivery(val));
                state.expanded[expandKey('delivery:' + (state.def.end.deliveries.length - 1))] = true;
                changed({ inspector: true });
                spotlight('delivery:' + (state.def.end.deliveries.length - 1));
                return;
            case 'dl-del':
                if (!window.confirm(T('Retirer cette livraison ?'))) { return; }
                state.def.end.deliveries.splice(+val, 1);
                break;
            case 'dl-source': case 'dl-mode': case 'dl-attach': case 'dl-piece':
                var pv = String(val).split('|');
                var dlv = state.def.end.deliveries[+pv[0]];
                if (!dlv) { return; }
                if (act === 'dl-source') { dlv.source = pv[1]; }
                if (act === 'dl-mode') { dlv.mode = pv[1]; }
                if (act === 'dl-attach') { toggle(dlv.attach = dlv.attach || [], pv[1]); }
                if (act === 'dl-piece') { toggle(dlv.pieces = dlv.pieces || [], pv[1]); }
                break;
            default: return;
        }
        changed({ inspector: true });
    });

    // ------------------------------------------------------------------
    // En-tête : nom, objectif, couleur, publier, lancer
    // ------------------------------------------------------------------

    nameInput.addEventListener('input', function () { state.name = nameInput.value; scheduleSave(); });
    overviewBtn.addEventListener('click', function () {
        if (state.open && state.selected === null) { select(null); } else { openOverview(state.issues.length ? 'issues' : ''); }
    });
    objectiveLine.addEventListener('click', function () { openOverview('objective'); });
    scrim.addEventListener('click', function () { select(null); });
    colorInput.addEventListener('input', function () {
        state.color = colorInput.value;
        colorInput.parentNode.style.background = state.color;
        scheduleSave();
    });

    publishBtn.addEventListener('click', function () {
        publishBtn.disabled = true;
        save().then(function () {
            return fetch('/workflows/' + state.workflow.id + '/publish', {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() },
            });
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) {
                setSave('error', j.error || T('Publication impossible.'));
                select(null, 'issues');
                return;
            }
            state.workflow = j.workflow;
            setSave('', T('Publié : la version {v} sera utilisée par les prochaines exécutions.', { v: j.version }));
            renderStatus();
        }).catch(function () {
            setSave('error', T('Publication impossible.'));
            renderStatus();
        });
    });

    if (launchBtn) {
        launchBtn.addEventListener('click', function () {
            if (state.workflow.draft_changed && !window.confirm(T('Des modifications ne sont pas publiées : l’exécution utilisera la version publiée. Lancer quand même ?'))) { return; }
            if (window.MiaWorkflowLaunch) { window.MiaWorkflowLaunch.open(state.workflow.id); }
        });
    }

    function duplicateWorkflow() {
        save().then(function () {
            return fetch('/workflows/' + state.workflow.id + '/duplicate', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } });
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j.ok) { window.location.href = j.url; } else { setSave('error', j.error || ''); }
        });
    }

    function deleteWorkflow() {
        if (!window.confirm(T('Supprimer ce workflow ? Ses exécutions passées restent dans vos conversations.'))) { return; }
        fetch('/workflows/' + state.workflow.id + '/delete', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } })
            .then(function (r) { return r.json(); }).then(function (j) { if (j.ok) { window.location.href = j.url; } });
    }

    window.addEventListener('beforeunload', function (e) {
        if (state.saving || state.saveTimer && saveEl.classList.contains('is-saving')) {
            save();
            e.preventDefault();
            e.returnValue = '';
        }
    });

    renderAll();
    setSave('', state.workflow.published ? T('Brouillon à jour') : T('Brouillon'));
    // Arrivé depuis une conversation avec des points bloquants : les
    // réglages s'ouvrent dessus, c'est ce qu'il reste à faire.
    if (D.origin && state.issues.some(function (i) { return i.level === 'block'; })) {
        openOverview('issues');
    }
})();
