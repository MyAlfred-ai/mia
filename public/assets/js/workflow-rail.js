/**
 * Rail d'un workflow — SEUL rendu, partagé par l'éditeur et la page d'une
 * exécution (MiaWorkflowRail).
 *
 *   [▶] Départ            À la main          [▣ Réunion]
 *    │
 *   [☺] Synthétiser        Analyste           ● → [▣ Compte rendu]
 *    │
 *   [◇] On y va ?          Question à MIA     ●
 *          ┌─────┴─────┐
 *     [Si oui]       [Si non]       une colonne par voie, de sa couleur
 *          └─────┬─────┘
 *                ○                  point de jonction
 *   [⚑] Livrable                              [▣ Compte rendu]
 *
 * Une ligne par brique : qui la mène, ce qu'elle lit (points de couleur) et ce
 * qu'elle remet (puce). Le détail et les réglages s'ouvrent dans un volet ;
 * survoler une pièce allume toutes les briques qui la touchent. Entre deux
 * briques, des PAQUETS de la couleur des pièces descendent le lien : ce qui
 * passe d'une étape à l'autre se voit bouger. Une couleur = une nature de
 * pièce (texte, liste, tableau, Fiche, document, fichier).
 *
 * Une condition ouvre deux voies (« si oui », « si non ») ; une décision de
 * MIA en ouvre de deux à six (branchesOf : la même mécanique). Elles se
 * dessinent en ARBRE (fork, lane) ; les liaisons, qui dépendent de la place
 * réelle des briques, sont tracées une fois le rail posé (drawForks).
 *
 * Partie PURE (pieces, available, readsOf, mentions, html) : aucune lecture du
 * DOM ; la page branche ses gestes par délégation sur les attributs
 * `data-node`, `data-add`, `data-tool`, `data-piece`.
 *
 * Les pièces se comptent comme côté serveur (WorkflowSpec::pieces /
 * available) : l'éditeur montre en direct ce que le serveur validera.
 */
(function (root) {
    'use strict';

    var T = function (s, v) {
        if (root.t) { return root.t(s, v); }
        return String(s).replace(/\{(\w+)\}/g, function (m, k) { return v && k in v ? v[k] : m; });
    };
    var esc = function (s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var LANES = ['yes', 'no'];
    /** Palette d'avant (une couleur par pièce), si le catalogue n'en donne pas par nature. */
    var PALETTE = ['#3b82f6', '#d946ef', '#14b8a6', '#eab308', '#6366f1', '#f43f5e'];
    /** Paquets au plus par lien. */
    var PACKETS_MAX = 3;
    /** Champs de la Fiche « Email reçu » (miroir de WorkflowSpec::EMAIL_FIELDS). */
    var EMAIL_FIELDS = [
        { key: 'expediteur', label: 'Expéditeur', type: 'email' },
        { key: 'objet', label: 'Objet', type: 'text' },
        { key: 'recu_le', label: 'Reçu le', type: 'date' },
        { key: 'contenu', label: 'Contenu', type: 'text' },
        { key: 'pieces_jointes', label: 'Pièces jointes', type: 'list' },
    ];
    var EVENT_NAMES = { email: 'Email reçu', file: 'Fichier reçu', webhook: 'Données reçues', workflow: 'Résultat du workflow précédent' };
    /** Champs des éléments des découpeurs à champs fixes (miroir de LoopSources::FIXED_FIELDS). */
    var SOURCE_FIELDS = {
        attachments: [
            { key: 'nom', label: 'Nom', type: 'text' }, { key: 'type', label: 'Type', type: 'text' }, { key: 'taille', label: 'Taille', type: 'text' },
            { key: 'email_objet', label: 'Objet de l’email', type: 'text' }, { key: 'email_de', label: 'Expéditeur', type: 'email' },
        ],
        slides: [
            { key: 'numero', label: 'Numéro', type: 'number' }, { key: 'titre', label: 'Titre', type: 'text' },
            { key: 'texte', label: 'Texte', type: 'text' }, { key: 'notes', label: 'Notes', type: 'text' },
        ],
        pages: [{ key: 'numero', label: 'Numéro', type: 'number' }, { key: 'texte', label: 'Texte', type: 'text' }],
        sections: [{ key: 'titre', label: 'Titre', type: 'text' }, { key: 'contenu', label: 'Contenu', type: 'text' }],
        files: [
            { key: 'nom', label: 'Nom', type: 'text' }, { key: 'type', label: 'Type', type: 'text' },
            { key: 'modifie_le', label: 'Modifié le', type: 'date' }, { key: 'lien', label: 'Lien', type: 'text' },
        ],
        emails: [
            { key: 'expediteur', label: 'Expéditeur', type: 'email' }, { key: 'objet', label: 'Objet', type: 'text' },
            { key: 'recu_le', label: 'Reçu le', type: 'date' }, { key: 'contenu', label: 'Contenu', type: 'text' },
            { key: 'pieces_jointes', label: 'Pièces jointes', type: 'list' },
        ],
    };
    var FILE_SOURCES = ['attachments', 'files'];
    var DRIVE_FILE_SOURCES = ['rows', 'slides', 'pages', 'sections'];
    var UNTIL = '~until';

    // ------------------------------------------------------------------
    // Pièces (miroir de WorkflowSpec)
    // ------------------------------------------------------------------

    /** Voies d'une brique (miroir de WorkflowSpec::branchesOf) : [{key, nodes, then, label?, when?}]. */
    function branchesOf(node) {
        if (!node) { return []; }
        if (node.type === 'condition') {
            return LANES.map(function (l) {
                var lane = node[l] || { nodes: [], then: l === 'yes' ? 'continue' : 'stop' };
                return { key: l, nodes: lane.nodes || [], then: lane.then, lane: lane };
            });
        }
        if (node.type === 'route') {
            return (node.routes || []).map(function (r) { return { key: r.key, nodes: r.nodes || [], then: r.then, label: r.label, when: r.when, lane: r }; });
        }
        return [];
    }

    /** Une voie arrête-t-elle le workflow (miroir de WorkflowSpec::laneStops) ? */
    function laneStops(lane) {
        if (!lane) { return false; }
        if (lane.then === 'stop') { return true; }
        return (lane.nodes || []).some(function (n) { return n.type === 'stop'; });
    }

    function isBranching(node) {
        return !!node && (node.type === 'condition' || node.type === 'route');
    }

    /** Couleur d'une pièce : celle de sa nature (catalogue), sinon la palette d'avant. */
    function colorOf(p, colors, i) {
        if (colors && !Array.isArray(colors)) {
            return colors[p.form] || colors.text || PALETTE[0];
        }
        var palette = colors && colors.length ? colors : PALETTE;
        return palette[i % palette.length];
    }

    function ficheTypeOfField(type) {
        return ({ number: 'number', date: 'date', email: 'email', yesno: 'yesno' })[type] || 'text';
    }

    /** Brique qui remet une pièce : une étape, une création (WorkflowSpec::PRODUCING_TYPES). */
    function produces(n) {
        return !!n && (n.type === 'step' || n.type === 'create') && !!n.output;
    }

    /**
     * Pièce que rassemble une boucle : `collect` si c'est une pièce du corps,
     * sinon celle de la dernière étape. « Jusqu'à » suit aussi une création
     * (miroir de WorkflowSpec::loopCollect).
     */
    function loopCollect(loop) {
        var outs = [];
        (loop.body || []).forEach(function (c) {
            if (c.output && (c.type === 'step' || (c.type === 'create' && loop.mode === 'until'))) { outs.push(c.output.key); }
        });
        if (!outs.length) { return null; }
        return outs.indexOf(loop.collect) >= 0 ? loop.collect : outs[outs.length - 1];
    }

    function loopOutputPiece(loop, list) {
        var collect = loopCollect(loop);
        var src = null;
        list.forEach(function (p) { if (p.key === collect) { src = p; } });
        var form = 'text';
        var fields = [];
        var format = null;
        if (src) {
            if (loop.mode === 'until') {
                form = src.form; fields = src.fields || []; format = src.format || null;
            } else {
                form = ({ fiche: 'table', table: 'table', list: 'list', document: src.format === 'xlsx' ? 'table' : 'document' })[src.form] || 'text';
                format = form === 'document' ? (src.format || 'docx') : null;
            }
        }
        var o = loop.output || {};
        return {
            key: o.key, name: o.name || loop.title || o.key, form: form, fields: fields, format: format,
            producer: loop.key, branch: null, aggregate: true, collect: collect,
        };
    }

    /**
     * Forme de l'élément d'une boucle « pour chaque » (miroir de
     * LoopSources::itemShape) : un texte, ou une Fiche dont les champs
     * viennent du découpeur.
     */
    function itemShape(loop) {
        var src = loop.source || {};
        var kind = src.kind || '';
        if (kind === 'rows') { return { form: 'fiche', fields: (src.columns || []).slice(), file: false }; }
        if (kind === 'ai') { var f = (src.fields || []).slice(); return { form: f.length ? 'fiche' : 'text', fields: f, file: false }; }
        if (SOURCE_FIELDS[kind]) { return { form: 'fiche', fields: SOURCE_FIELDS[kind].slice(), file: FILE_SOURCES.indexOf(kind) >= 0 }; }
        return { form: 'text', fields: [], file: false };
    }

    /** La source lit-elle une pièce du dossier (miroir de LoopSources::readsPiece) ? */
    function readsPiece(src) {
        src = src || {};
        var kind = src.kind || '';
        if (kind === '' || kind === 'list' || kind === 'ai') { return true; }
        if (kind === 'files' || kind === 'emails') { return false; }
        if (kind === 'attachments') { return src.from !== 'search'; }
        return src.from !== 'drive';
    }

    /** Le découpeur accepte-t-il cette pièce comme source (miroir de LoopSources::accepts) ? */
    function loopAccepts(kind, p, overField) {
        if (!p) { return false; }
        var form = p.form || '';
        var format = p.format || '';
        switch (kind) {
            case 'list': return form === 'list' || form === 'text' || (form === 'fiche' && (p.fields || []).some(function (f) { return f.key === overField && f.type === 'list'; }));
            case 'rows': return form === 'table' || form === 'file' || (form === 'document' && format === 'xlsx');
            case 'slides': return form === 'file' || (form === 'document' && format === 'pptx');
            case 'pages': return form === 'file';
            case 'sections': return form === 'file' || form === 'text' || (form === 'document' && format !== 'xlsx');
            case 'attachments': return p.event === 'email';
            case 'ai': return form !== '' && ['image', 'page', 'map'].indexOf(form) < 0;
            default: return false;
        }
    }

    /** Phrase de ce que parcourt une boucle (miroir de LoopSources::describe). */
    function loopSentence(loop, over) {
        var src = loop.source || {};
        var kind = src.kind || '';
        var name = over ? over.name : '';
        var drive = String(src.drive || '').trim();
        var of = src.from === 'drive' ? (drive || T('un fichier Drive')) : name;
        switch (kind) {
            case 'rows': return of ? T('Pour chaque ligne de « {source} »', { source: of }) : T('Pour chaque ligne d’un tableau');
            case 'slides': return of ? T('Pour chaque slide de « {source} »', { source: of }) : T('Pour chaque slide d’une présentation');
            case 'pages': return of ? T('Pour chaque page de « {source} »', { source: of }) : T('Pour chaque page d’un PDF');
            case 'sections': return of ? T('Pour chaque section de « {source} »', { source: of }) : T('Pour chaque section d’un document');
            case 'attachments': return src.from === 'search' ? T('Pour chaque pièce jointe des emails trouvés')
                : (name ? T('Pour chaque pièce jointe de « {source} »', { source: name }) : T('Pour chaque pièce jointe'));
            case 'files': return drive ? T('Pour chaque fichier du dossier « {source} »', { source: drive }) : T('Pour chaque fichier d’un dossier Drive');
            case 'emails': return T('Pour chaque email trouvé');
            case 'ai': return name ? T('Pour chaque élément repéré par MIA dans « {source} »', { source: name }) : T('Pour chaque élément repéré par MIA');
            default: return name ? T('Pour chaque élément de « {piece} »', { piece: name }) : T('Pour chaque élément');
        }
    }

    /** Pièces dans l'ordre du rail, avec leur couleur. */
    function pieces(def, colors) {
        var out = [];
        var start = def.start || {};
        var fiche = [];
        (start.fields || []).forEach(function (f) {
            if (f.type === 'file') {
                out.push({ key: f.key, name: f.label || f.key, form: 'file', fields: [], producer: 'start', branch: null });
            } else {
                fiche.push({ key: f.key, label: f.label, type: ficheTypeOfField(f.type) });
            }
        });
        if (fiche.length) {
            out.unshift({ key: 'depart', name: start.fiche_name || T('Informations de départ'), form: 'fiche', fields: fiche, producer: 'start', branch: null });
        }
        if (start.event && start.event.kind) {
            var kind = start.event.kind;
            out.push({
                key: 'evenement', name: start.event.piece_name || EVENT_NAMES[kind] || kind,
                form: kind === 'email' ? 'fiche' : (kind === 'file' ? 'file' : 'text'),
                fields: kind === 'email' ? EMAIL_FIELDS.slice() : [], producer: 'start', branch: null, event: kind,
            });
        }
        var collect = function (n, branch) {
            if (!produces(n)) { return; }
            out.push({
                key: n.output.key, name: n.output.name || n.title || n.output.key, form: n.output.form,
                fields: n.output.fields || [], format: n.output.format || null, producer: n.key, branch: branch,
                review: !!n.review,
            });
        };
        (def.nodes || []).forEach(function (n) {
            collect(n, null);
            branchesOf(n).forEach(function (b) { b.nodes.forEach(function (c) { collect(c, n.key + ':' + b.key); }); });
            if (n.type === 'loop') {
                var branch = 'loop:' + n.key;
                if (n.mode !== 'until' && n.item) {
                    var shape = itemShape(n);
                    out.push({ key: n.item.key, name: n.item.name, form: shape.form, fields: shape.fields, producer: n.key, branch: branch, item: true, file: shape.file });
                }
                (n.body || []).forEach(function (c) { collect(c, branch); });
                if (n.output && n.output.key) { out.push(loopOutputPiece(n, out)); }
            }
        });
        out.forEach(function (p, i) { p.color = colorOf(p, colors, i); });
        return out;
    }

    function byKey(list) {
        var m = {};
        list.forEach(function (p) { m[p.key] = p; });
        return m;
    }

    function locate(def, key) {
        var nodes = def.nodes || [];
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            if (n.key === key) { return { in: 'main', lane: 'main', index: i, cond: null, condIndex: null, node: n }; }
            var lanes = branchesOf(n);
            for (var li = 0; li < lanes.length; li++) {
                var arr = lanes[li].nodes;
                for (var j = 0; j < arr.length; j++) {
                    if (arr[j].key === key) { return { in: 'lane', lane: lanes[li].key, index: j, cond: n.key, condIndex: i, node: arr[j] }; }
                }
            }
            if (n.type === 'loop') {
                var body = n.body || [];
                for (var k = 0; k < body.length; k++) {
                    if (body[k].key === key) { return { in: 'body', lane: 'body', index: k, cond: n.key, condIndex: i, node: body[k] }; }
                }
            }
        }
        return null;
    }

    /** Pièces disponibles avant une brique (même règle que le serveur). */
    function available(def, key, all) {
        all = all || pieces(def);
        var keep = {};
        all.forEach(function (p) { if (p.producer === 'start') { keep[p.key] = p; } });
        var add = function (nodeKey, railOnly) {
            all.forEach(function (p) {
                if (p.producer === nodeKey && (!railOnly || p.branch === null) && !p.item) { keep[p.key] = p; }
            });
        };
        var at;
        if (key === 'end') {
            at = { lane: 'main', index: (def.nodes || []).length };
        } else if (String(key).slice(-UNTIL.length) === UNTIL) {
            var loopAt = locate(def, String(key).slice(0, -UNTIL.length));
            if (!loopAt || loopAt.lane !== 'main' || loopAt.node.type !== 'loop') { return keep; }
            at = { lane: 'body', index: (loopAt.node.body || []).length, condIndex: loopAt.index };
        } else {
            at = locate(def, key);
        }
        if (!at) { return keep; }
        var limit = at.lane === 'main' ? at.index : at.condIndex;
        (def.nodes || []).slice(0, limit).forEach(function (n) {
            add(n.key, true);
            var lanes = branchesOf(n);
            lanes.forEach(function (b) {
                if (laneStops(b)) { return; }
                var othersStop = lanes.every(function (o) { return o.key === b.key || laneStops(o); });
                if (othersStop) { b.nodes.forEach(function (c) { add(c.key, false); }); }
            });
        });
        if (at.in === 'lane') {
            var laneOf = branchesOf(def.nodes[at.condIndex]).filter(function (b) { return b.key === at.lane; })[0];
            (laneOf ? laneOf.nodes : []).slice(0, at.index).forEach(function (c) { add(c.key, false); });
        }
        if (at.lane === 'body') {
            var loop = def.nodes[at.condIndex];
            if (loop.mode !== 'until' && loop.item) {
                all.forEach(function (p) { if (p.key === loop.item.key) { keep[p.key] = p; } });
            }
            (loop.body || []).slice(0, at.index).forEach(function (c) { add(c.key, false); });
        }
        return keep;
    }

    /** Clés citées par `@{clé}` ou `@{clé.champ}`. */
    function mentions(text) {
        var out = [];
        String(text || '').replace(/@\{([a-z0-9_]{1,24})(?:\.[a-z0-9_]{1,24})?\}/g, function (m, k) { if (out.indexOf(k) < 0) { out.push(k); } return m; });
        return out;
    }

    function readsOf(def, node, all) {
        var avail = available(def, node.key, all);
        var keys = (node.reads || []).slice();
        ['instruction', 'question', 'message'].forEach(function (f) { if (typeof node[f] === 'string' && node[f]) { keys = keys.concat(mentions(node[f])); } });
        if (node.type === 'gate') { keys = keys.concat(node.reviews || [], mentions(node.note || '')); }
        if (node.type === 'route') { (node.routes || []).forEach(function (r) { keys = keys.concat(mentions(r.when || '')); }); }
        if (node.type === 'step' || node.type === 'action') { filterTexts(node.filters).forEach(function (t) { keys = keys.concat(mentions(t)); }); }
        if (node.type === 'condition' && node.mode === 'rule') {
            ((node.rule || {}).items || []).forEach(function (it) { keys.push(it.piece); });
        }
        if (node.type === 'loop' && node.mode !== 'until') {
            if (readsPiece(node.source)) { keys.push(node.over); }
            ['instruction', 'query', 'drive'].forEach(function (f) { keys = keys.concat(mentions(((node.source || {})[f]) || '')); });
        }
        if (node.type === 'wait' && node.mode === 'date') { keys.push(node.piece); }
        var out = [];
        keys.forEach(function (k) { if (avail[k] && out.indexOf(k) < 0) { out.push(k); } });
        return out;
    }

    /** Textes des filtres qui peuvent citer le dossier (miroir de WorkflowFilters::texts). */
    function filterTexts(f) {
        f = f || {};
        var m = f.mail || {};
        var d = f.drive || {};
        return [m.from, m.subject, m.words, d.folder, d.name, f.recipients].map(function (x) { return String(x || ''); });
    }

    /** Pièces lues par la condition de sortie d'une boucle « jusqu'à ». */
    function untilReadsOf(def, loop, all) {
        var avail = available(def, loop.key + UNTIL, all);
        var u = loop.until || {};
        var keys = (u.reads || []).concat(mentions(u.question || ''));
        if (u.mode === 'rule') { ((u.rule || {}).items || []).forEach(function (it) { keys.push(it.piece); }); }
        var out = [];
        keys.forEach(function (k) { if (avail[k] && out.indexOf(k) < 0) { out.push(k); } });
        return out;
    }

    // ------------------------------------------------------------------
    // Balisage
    // ------------------------------------------------------------------

    function icon(cat, name) {
        return (cat.icons && cat.icons[name]) || '';
    }

    function pieceChip(p, mode, extra) {
        if (!p) { return ''; }
        return '<span class="wf-piece ' + (mode || '') + '" data-piece="' + esc(p.key) + '" style="--c:' + esc(p.color) + '" title="' + esc(p.name) + '">'
            + '<i class="dot"></i><span>' + esc(p.name) + '</span>' + (extra || '') + '</span>';
    }

    /** Texte d'une consigne : les @{clé} (et @{clé.champ}) deviennent des puces colorées. */
    function richText(text, pmap) {
        var out = '';
        var last = 0;
        text = String(text || '');
        text.replace(/@\{([a-z0-9_]{1,24})(?:\.([a-z0-9_]{1,24}))?\}/g, function (m, k, f, off) {
            out += esc(text.slice(last, off));
            var p = pmap[k];
            if (p && f) {
                var field = (p.fields || []).filter(function (x) { return x.key === f; })[0];
                out += field ? pieceChip(p, 'read', '<small> › ' + esc(field.label) + '</small>') : esc(m);
            } else {
                out += p ? pieceChip(p, 'read') : esc(m);
            }
            last = off + m.length;
            return m;
        });
        out += esc(text.slice(last));
        return out;
    }

    function avatar(profile, cat) {
        if (!profile) {
            return '<span class="wf-ava mia">' + icon(cat, 'mia') + '</span>';
        }
        if (profile.avatar) {
            return '<span class="wf-ava" style="--av:' + esc(profile.color || '#10a37f') + '"><img src="' + esc(profile.avatar) + '" alt=""></span>';
        }
        return '<span class="wf-ava" style="--av:' + esc(profile.color || '#10a37f') + '">' + esc((profile.name || '?').charAt(0).toUpperCase()) + '</span>';
    }

    function findProfile(cat, id) {
        var list = cat.profiles || [];
        for (var i = 0; i < list.length; i++) { if (list[i].id === id) { return list[i]; } }
        return null;
    }

    function findLlm(cat, slug) {
        var list = cat.llms || [];
        for (var i = 0; i < list.length; i++) { if (list[i].slug === slug) { return list[i]; } }
        return null;
    }

    function findTarget(cat, key) {
        var list = cat.targets || [];
        for (var i = 0; i < list.length; i++) { if (list[i].key === key) { return list[i]; } }
        return null;
    }

    /** Outil de création (catalogue `creations`) par identifiant. */
    function findCreation(cat, id) {
        var list = cat.creations || [];
        for (var i = 0; i < list.length; i++) { if (list[i].id === id) { return list[i]; } }
        return null;
    }

    function statusPill(state, node, cat) {
        if (!state) { return ''; }
        var st = (state.nodes || {})[node.key];
        var reviewSt = produces(node) && node.review ? (state.nodes || {})[node.key + '~review'] : null;
        if (!st) {
            if (state.current === node.key && !state.terminal) { return '<span class="wf-pill run">' + esc(T('En cours')) + '</span>'; }
            return '';
        }
        if (node.type === 'condition' && st.status === 'done') {
            return '<span class="wf-pill cond">' + esc(st.outcome === 'yes' ? T('Oui') : T('Non')) + '</span>';
        }
        if (node.type === 'route' && st.status === 'done' && st.outcome !== 'stopped') {
            var chosen = branchesOf(node).filter(function (b) { return b.key === st.outcome; })[0];
            return chosen ? '<span class="wf-pill route">' + icon(cat, 'route') + esc(chosen.label || chosen.key) + '</span>' : '';
        }
        if (st.outcome === 'skipped' && st.status === 'done') {
            return '<span class="wf-pill">' + icon(cat, 'skip') + esc(T('Ignorée')) + '</span>';
        }
        if (st.status === 'waiting' && st.resume) {
            return '<span class="wf-pill wait">' + icon(cat, (st.detail || {}).kind === 'retry' ? 'retry' : 'wait') + esc(st.resume) + '</span>';
        }
        if (node.type === 'loop' && state.loops && state.loops[node.key]) {
            var lp = state.loops[node.key];
            if (st.status === 'running') {
                return '<span class="wf-pill run">' + esc(lp.mode === 'until' ? T('Tour {n}', { n: lp.i }) : T('{n} / {total}', { n: lp.i, total: lp.count }))
                    + (lp.failed ? ' · ' + esc(T('{n} en échec', { n: lp.failed })) : '') + '</span>';
            }
            if (st.status === 'done' && lp.failed) {
                return '<span class="wf-pill err">' + esc(T('{n} en échec', { n: lp.failed })) + '</span>';
            }
        }
        if (reviewSt && reviewSt.status === 'waiting') { return '<span class="wf-pill wait">' + esc(T('Vous attend')) + '</span>'; }
        switch (st.status) {
            case 'done': return '<span class="wf-pill ok">' + icon(cat, 'check') + (st.seconds ? esc(duration(st.seconds)) : esc(T('Fait'))) + '</span>';
            case 'running': return '<span class="wf-pill run">' + esc(T('En cours')) + '</span>';
            case 'waiting': return '<span class="wf-pill wait">' + esc(T('Vous attend')) + '</span>';
            case 'failed': return state.current === node.key ? '<span class="wf-pill err">' + esc(T('Arrêtée')) + '</span>' : '';
            case 'cancelled': return '<span class="wf-pill">' + esc(T('Arrêtée')) + '</span>';
            default: return '';
        }
    }

    function duration(s) {
        if (s < 60) { return T('{n} s', { n: s }); }
        var m = Math.floor(s / 60);
        var r = s % 60;
        return r ? T('{m} min {s} s', { m: m, s: r }) : T('{m} min', { m: m });
    }

    function stateClass(state, node) {
        if (!state) { return ''; }
        var st = (state.nodes || {})[node.key];
        var rev = produces(node) && node.review ? (state.nodes || {})[node.key + '~review'] : null;
        if (rev && rev.status === 'waiting') { return ' st-waiting'; }
        if (!st) {
            return state.current === node.key && !state.terminal ? ' st-active' : ' st-pending';
        }
        if (st.status === 'running') { return ' st-active'; }
        if (st.status === 'waiting') { return st.resume ? ' st-timer' : ' st-waiting'; }
        if (st.outcome === 'skipped') { return ' st-skipped'; }
        if (st.status === 'failed' && state.current === node.key) { return ' st-failed'; }
        if (st.status === 'done') { return ' st-done'; }
        return '';
    }

    function tools(opts, cat, node, ctx) {
        if (!opts.editable) { return ''; }
        var b = function (act, ic, label, extra) {
            return '<button type="button" class="wf-tool' + (extra || '') + '" data-tool="' + act + '" data-node="' + esc(node.key) + '" title="' + esc(label) + '" aria-label="' + esc(label) + '">' + icon(cat, ic) + '</button>';
        };
        return '<span class="wf-node-tools">'
            + (ctx.first ? '' : b('up', 'up', T('Monter')))
            + (ctx.last ? '' : b('down', 'down', T('Descendre')))
            + (node.type === 'stop' ? '' : b('dup', 'copy', T('Dupliquer')))
            + b('del', 'trash', T('Supprimer'), ' danger')
            + '</span>';
    }

    function issueDot(opts, key) {
        var list = (opts.issuesByNode || {})[key];
        if (!list || !list.length) { return ''; }
        var block = list.some(function (i) { return i.level === 'block'; });
        return '<span class="wf-issue-dot' + (block ? ' block' : '') + '" title="' + esc(list.map(function (i) { return i.text; }).join('\n')) + '">!</span>';
    }

    // ------------------------------------------------------------------
    // Cartes : une ligne par brique. Le détail vit dans le volet qu'ouvre
    // un clic ; la carte ne garde que QUI fait QUOI, et ce qui circule :
    // les points de couleur = pièces lues, la puce = pièce remise. Survoler
    // une pièce allume toutes les briques qui la touchent.
    // ------------------------------------------------------------------

    function dot(p) {
        return '<i class="wf-dot" data-piece="' + esc(p.key) + '" style="--c:' + esc(p.color) + '" title="' + esc(T('Lit « {piece} »', { piece: p.name })) + '"></i>';
    }

    function outChip(p, cat, opts) {
        var produced = !opts.state || !!((opts.state.pieces || {})[p.key]);
        return '<span class="wf-out' + (produced ? '' : ' is-ghost') + '" data-piece="' + esc(p.key) + '" style="--c:' + esc(p.color) + '" title="' + esc(p.name) + '">'
            + icon(cat, p.form === 'file' ? 'file' : p.form) + '<span>' + esc(p.name) + '</span></span>';
    }

    function io(reads, outs, cat, opts, pmap) {
        reads = reads.filter(function (k) { return pmap[k]; });
        outs = outs.filter(function (k) { return pmap[k]; });
        if (!reads.length && !outs.length) { return ''; }
        var d = reads.length
            ? '<span class="wf-dots">' + reads.slice(0, 4).map(function (k) { return dot(pmap[k]); }).join('') + (reads.length > 4 ? '<em>+' + (reads.length - 4) + '</em>' : '') + '</span>'
            : '';
        var arrow = reads.length && outs.length ? '<span class="wf-arr">' + icon(cat, 'arrow') + '</span>' : '';
        return '<div class="wf-io">' + d + arrow + outs.map(function (k) { return outChip(pmap[k], cat, opts); }).join('') + '</div>';
    }

    function brick(o) {
        return '<article class="wf-brick wf-k-' + o.type + (o.sel ? ' is-sel' : '') + (o.stateCls || '') + '" data-node="' + esc(o.key) + '" style="--hue:' + esc(o.hue) + '" tabindex="0">'
            + (o.issue || '')
            + '<span class="wf-brick-ic">' + o.ic + '</span>'
            + '<div class="wf-brick-txt"><b' + (o.empty ? ' class="is-empty"' : '') + '>' + esc(o.title) + '</b>' + (o.sub ? '<small>' + o.sub + '</small>' : '') + '</div>'
            + (o.io || '') + (o.pill || '') + (o.tools || '')
            + '</article>';
    }

    function subLine(parts) {
        return parts.filter(Boolean).map(esc).join(' · ');
    }

    function nodeBrick(def, node, cat, opts, pmap, all, ctx) {
        var labels = cat.labels || {};
        var hues = cat.hues || {};
        var o = {
            key: node.key, type: node.type, title: node.title || T('Sans titre'), empty: !node.title,
            sel: opts.selected === node.key, stateCls: stateClass(opts.state, node),
            issue: issueDot(opts, node.key), pill: statusPill(opts.state, node, cat), tools: tools(opts, cat, node, ctx),
            hue: hues[node.type] || '#10a37f',
        };
        var reads = readsOf(def, node, all);
        if (node.type === 'step') {
            var prof = node.profile_id ? findProfile(cat, node.profile_id) : null;
            if (prof && prof.color) { o.hue = prof.color; }
            o.ic = avatar(prof, cat);
            o.sub = subLine([
                prof ? prof.name : 'MIA',
                hasFilter(node) ? T('Filtrée') : '',
                node.review ? T('Relue par vous') : '',
                node.write === 'auto' ? T('Sans validation') : (node.write && node.write !== 'none' ? T('À approuver') : ''),
            ]);
            o.io = io(reads, node.output ? [node.output.key] : [], cat, opts, pmap);
        } else if (node.type === 'create') {
            // L'outil et ce qu'il crée : le pictogramme de la nature (image, page, carte).
            var ct = findCreation(cat, node.tool_id);
            o.ic = icon(cat, node.kind || 'create');
            o.sub = subLine([
                ct ? ct.name : (((labels.createKinds || {})[node.kind]) || ''),
                node.review ? T('Relue par vous') : '',
            ]);
            o.io = io(reads, node.output ? [node.output.key] : [], cat, opts, pmap);
        } else if (node.type === 'action') {
            var tg = findTarget(cat, node.target);
            o.ic = tg ? tg.icon : icon(cat, 'action');
            o.sub = subLine([tg ? tg.label : ((labels.kinds || {}).action || ''), hasFilter(node) ? T('Filtrée') : '', node.write === 'auto' ? T('Sans validation') : T('À approuver')]);
            o.io = io(reads, [], cat, opts, pmap);
        } else if (node.type === 'route') {
            o.ic = icon(cat, 'route');
            var n = (node.routes || []).length;
            o.sub = subLine([n === 1 ? T('MIA choisit parmi {n} voie', { n: n }) : T('MIA choisit parmi {n} voies', { n: n })]);
            o.io = io(reads, [], cat, opts, pmap);
        } else if (node.type === 'condition') {
            o.ic = icon(cat, 'condition');
            var inLoop = (locate(def, node.key) || {}).lane === 'body';
            var thenNo = ((node.no || {}).then) || 'skip';
            o.sub = subLine([
                node.mode === 'rule' ? T('Règle') : T('Question à MIA'),
                inLoop ? (thenNo === 'skip' ? T('sinon : élément suivant') : (thenNo === 'stop' ? T('sinon : arrêter le workflow') : '')) : '',
            ]);
            o.io = io(reads, [], cat, opts, pmap);
        } else if (node.type === 'loop') {
            o.ic = icon(cat, 'loop');
            var over = pmap[node.over];
            var filtered = node.mode !== 'until' && node.filter && (((node.filter.rule || {}).items || []).length || node.filter.dedupe);
            o.sub = subLine([
                node.mode === 'until'
                    ? T('Jusqu’à une condition · {n} tours au plus', { n: node.max })
                    : loopSentence(node, readsPiece(node.source) ? over : null) + ' · ' + T('{n} au plus', { n: node.max }),
                filtered ? T('Filtrée') : '',
                node.mode !== 'until' && node.confirm ? T('Liste relue') : '',
                node.mode !== 'until' && node.review_actions ? T('Actions relues ensemble') : '',
            ]);
            o.io = io(reads, node.output && pmap[node.output.key] ? [node.output.key] : [], cat, opts, pmap);
        } else if (node.type === 'wait') {
            o.ic = icon(cat, 'wait');
            o.sub = subLine([waitText(node, pmap, labels)]);
            o.io = io(reads, [], cat, opts, pmap);
        } else if (node.type === 'notify') {
            o.ic = icon(cat, 'notify');
            o.sub = subLine([T('Sur vos appareils')]);
            o.io = io(reads, [], cat, opts, pmap);
        } else if (node.type === 'stop') {
            o.ic = icon(cat, 'stop');
            o.sub = subLine([T('Fin du workflow'), ((labels.stopOutcomes || {})[node.outcome === 'stopped' ? 'stopped' : 'done']) || '']);
            o.io = io(reads, [], cat, opts, pmap);
        } else {
            o.ic = icon(cat, 'gate');
            o.sub = subLine([(labels.kinds || {}).gate || '']);
            o.io = io(node.reviews || [], [], cat, opts, pmap);
        }
        if ((node.type === 'step' || node.type === 'action' || node.type === 'create') && node.on_error && node.on_error.then === 'skip') {
            o.sub = (o.sub ? o.sub + ' · ' : '') + esc(T('Facultative'));
        }
        return brick(o);
    }

    /** La brique a-t-elle des filtres (emails, fichiers, destinataires) ? */
    function hasFilter(node) {
        return filterTexts(node.filters).some(function (t) { return t.trim() !== ''; })
            || !!(node.filters && node.filters.mail && (node.filters.mail.attachment || node.filters.mail.unread || node.filters.mail.days))
            || !!(node.filters && node.filters.drive && (node.filters.drive.kind || node.filters.drive.days));
    }

    function waitText(node, pmap, labels) {
        if (node.mode === 'date') {
            var p = pmap[node.piece];
            var f = p ? (p.fields || []).filter(function (x) { return x.key === node.field; })[0] : null;
            var base = f ? T('Jusqu’à « {champ} »', { champ: f.label }) : T('Jusqu’à une date');
            var off = parseInt(node.offset, 10) || 0;
            if (off) { base += ' ' + (off > 0 ? T('+ {n} j', { n: off }) : T('− {n} j', { n: -off })); }
            return base + ' · ' + String(node.time || '09:00');
        }
        return T('{n} {unite}', { n: node.amount, unite: ((labels.units || {})[node.unit] || node.unit) });
    }

    function startBrick(def, cat, opts, all, pmap) {
        var s = def.start || {};
        var trig = [T('À la main')];
        if (s.chat) { trig.push(T('Depuis le chat')); }
        if (s.schedule) { trig.push(opts.scheduleText || T('Programmé')); }
        if (s.event && s.event.kind) { trig.push(((cat.labels || {}).events || {})[s.event.kind] || s.event.kind); }
        var outs = all.filter(function (p) { return p.producer === 'start'; }).map(function (p) { return p.key; });
        return brick({
            key: 'start', type: 'start', hue: (cat.hues || {}).start, ic: icon(cat, 'start'),
            title: s.title || T('Départ'), sub: subLine(trig), io: io([], outs, cat, opts, pmap),
            sel: opts.selected === 'start', stateCls: opts.state ? ' st-done' : '', issue: issueDot(opts, 'start'),
            pill: opts.state ? '<span class="wf-pill ok">' + icon(cat, 'check') + esc(T('Lancé')) + '</span>' : '',
        });
    }

    function endBrick(def, cat, opts, pmap) {
        var end = def.end || {};
        var st = opts.state;
        var done = st && st.status === 'done';
        var keys = (end.pieces || []).filter(function (k) { return pmap[k]; });
        var dl = (end.deliveries || []).map(function (d) { return ((cat.labels || {}).deliveriesShort || {})[d.type] || ((cat.labels || {}).deliveries || {})[d.type] || d.type; });
        var endSt = st && (st.nodes || {}).end;
        var pill = done ? '<span class="wf-pill ok">' + icon(cat, 'check') + esc(T('Livré')) + '</span>' : '';
        if (!done && endSt && endSt.status === 'waiting') { pill = '<span class="wf-pill wait">' + esc(T('Vous attend')) + '</span>'; }
        if (!done && endSt && endSt.status === 'running') { pill = '<span class="wf-pill run">' + esc(T('Livraison')) + '</span>'; }
        return brick({
            key: 'end', type: 'end', hue: (cat.hues || {}).end, ic: icon(cat, 'end'),
            title: end.title || T('Livrable'),
            sub: dl.length ? esc(T('Puis : {liste}', { liste: dl.join(', ') })) : (keys.length ? '' : esc(T('Choisissez les pièces que vous obtenez à la fin.'))),
            io: io([], keys, cat, opts, pmap), sel: opts.selected === 'end',
            stateCls: st ? (done ? ' st-done' : (endSt && endSt.status === 'waiting' ? ' st-waiting' : (endSt && endSt.status === 'running' ? ' st-active' : ' st-pending'))) : '', issue: issueDot(opts, 'end'),
            pill: pill,
        });
    }

    function link(index, nodes, cat, opts, transit) {
        var target = index < nodes.length ? nodes[index].key : 'end';
        var flowing = opts.state && opts.state.current && !opts.state.terminal && String(opts.state.current).split('#')[0].split('~')[0] === target;
        var add = opts.editable
            ? '<button type="button" class="wf-add" data-add="main" data-index="' + index + '" title="' + esc(T('Ajouter une brique ici')) + '" aria-label="' + esc(T('Ajouter une brique ici')) + '">' + icon(cat, 'plus') + '</button>'
            : '';
        return '<div class="wf-link' + (flowing ? ' is-flow' : '') + '">' + packets(transit || []) + add + '</div>';
    }

    /**
     * Les paquets d'un lien : les pièces qui y PASSENT — remises avant lui,
     * lues après lui. Décalés dans le temps, ils descendent l'un après
     * l'autre (CSS, coupé si l'appareil demande moins de mouvement).
     */
    function packets(list) {
        if (!list.length) { return ''; }
        var shown = list.slice(0, PACKETS_MAX);
        return '<span class="wf-pks" aria-hidden="true">' + shown.map(function (p, i) {
            return '<i class="wf-pk" data-piece="' + esc(p.key) + '" style="--c:' + esc(p.color) + ';--i:' + i + ';--n:' + shown.length + '"></i>';
        }).join('') + '</span>';
    }

    /**
     * Ce qui passe sur chaque lien du rail principal : lien i (avant la
     * brique i, `nodes.length` = avant le livrable). Une pièce passe de sa
     * brique (le départ = -1 ; une brique de voie ou de boucle compte pour la
     * brique du rail qui la porte) jusqu'à sa dernière lectrice.
     */
    function transitMap(def, all, pmap) {
        var nodes = def.nodes || [];
        var top = {};
        nodes.forEach(function (n, i) {
            top[n.key] = i;
            branchesOf(n).forEach(function (b) { b.nodes.forEach(function (c) { top[c.key] = i; }); });
            (n.body || []).forEach(function (c) { top[c.key] = i; });
        });
        var last = {};
        var read = function (key, at) { if (pmap[key] && (last[key] === undefined || last[key] < at)) { last[key] = at; } };
        nodes.forEach(function (n, i) {
            readsOf(def, n, all).forEach(function (k) { read(k, i); });
            branchesOf(n).forEach(function (b) { b.nodes.forEach(function (c) { readsOf(def, c, all).forEach(function (k) { read(k, i); }); }); });
            (n.body || []).forEach(function (c) { readsOf(def, c, all).forEach(function (k) { read(k, i); }); });
            if (n.type === 'loop' && n.mode === 'until') { untilReadsOf(def, n, all).forEach(function (k) { read(k, i); }); }
        });
        var end = def.end || {};
        var endKeys = (end.pieces || []).slice();
        (end.deliveries || []).forEach(function (d) {
            endKeys = endKeys.concat(d.attach || [], d.pieces || [], mentions(d.subject || ''), mentions(d.message || ''), mentions(d.to || ''), mentions(d.folder || ''));
            if (d.to_field) { endKeys.push(String(d.to_field).split('.')[0]); }
        });
        endKeys.concat(mentions(end.note || '')).forEach(function (k) { read(k, nodes.length); });
        var out = [];
        for (var i = 0; i <= nodes.length; i++) { out.push([]); }
        all.forEach(function (p) {
            if (last[p.key] === undefined || p.item) { return; }
            var from = p.producer === 'start' ? -1 : (top[p.producer] !== undefined ? top[p.producer] : -1);
            for (var j = from + 1; j <= last[p.key] && j <= nodes.length; j++) { out[j].push(p); }
        });
        return out;
    }

    /**
     * Le rail complet.
     * opts : catalog, editable, selected, state, issuesByNode, scheduleText.
     * La surbrillance d'une pièce n'est pas rendue ici : l'appelant la pose
     * par highlight() une fois le rail dans la page (elle grise les briques
     * qui ne touchent pas la pièce ET rallume celles qui la touchent — un
     * rail rendu avec la seule moitié grise restait tout gris).
     */
    function html(def, opts) {
        opts = opts || {};
        var cat = opts.catalog || {};
        var all = pieces(def, cat.colors);
        var pmap = byKey(all);
        var nodes = def.nodes || [];
        var transit = transitMap(def, all, pmap);
        // Les voies les plus nombreuses élargissent le rail : chaque voie garde
        // sa colonne, de même largeur que les autres (cf. l'arbre, workflows.css).
        var forkMax = 0;
        nodes.forEach(function (n) { if (isBranching(n)) { forkMax = Math.max(forkMax, branchesOf(n).length); } });
        var out = '<div class="wf-rail' + (opts.editable ? ' is-editable' : '') + (opts.state ? ' is-run' : '') + '"'
            + (forkMax ? ' style="--fork-max:' + forkMax + '"' : '') + '>';
        out += startBrick(def, cat, opts, all, pmap);
        nodes.forEach(function (n, i) {
            out += link(i, nodes, cat, opts, transit[i]);
            out += nodeBrick(def, n, cat, opts, pmap, all, { first: i === 0, last: i === nodes.length - 1 });
            if (isBranching(n)) {
                out += fork(def, n, cat, opts, pmap, all);
            }
            if (n.type === 'loop') {
                out += loopBody(def, n, cat, opts, pmap, all);
            }
        });
        out += link(nodes.length, nodes, cat, opts, transit[nodes.length]);
        out += endBrick(def, cat, opts, pmap);
        return out + '</div>';
    }

    /** Nombre de couleurs de voie (--wf-l1 … --wf-l6, workflows.css). */
    var LANE_COLORS = 6;

    /**
     * Un embranchement, dessiné en ARBRE : les voies côte à côte, en colonnes
     * de même largeur et de même hauteur, chacune de sa couleur ; un tronc
     * qui se sépare sous la question et des liaisons qui se rejoignent sur
     * un point de jonction. Les liaisons sont tracées une fois le rail dans
     * la page (drawForks : elles dépendent de la position réelle des
     * briques) ; ce rendu-ci reste pur.
     */
    function fork(def, n, cat, opts, pmap, all) {
        var lanes = branchesOf(n);
        var allStop = lanes.length > 0 && lanes.every(function (b) { return laneStops(b); });
        return '<div class="wf-fork wf-fork-' + n.type + '" style="--lanes:' + lanes.length + '" data-fork-of="' + esc(n.key) + '">'
            + '<svg class="wf-wires" aria-hidden="true" focusable="false"></svg>'
            + '<div class="wf-lanes">' + lanes.map(function (b, li) { return lane(def, n, b, li, cat, opts, pmap, all); }).join('') + '</div>'
            + '<div class="wf-join' + (allStop ? ' is-none' : '') + '" aria-hidden="true"><i></i></div>'
            + '</div>';
    }

    /** Une voie d'une condition (si oui, si non) ou d'une décision (scénario nommé). */
    function lane(def, cond, b, index, cat, opts, pmap, all) {
        var which = b.key;
        var l = { nodes: b.nodes || [], then: b.then };
        var st = opts.state && (opts.state.nodes || {})[cond.key];
        var decided = !!(st && st.status === 'done');
        var isTaken = decided && st.outcome === which;
        var taken = decided ? (isTaken ? ' is-taken' : ' is-skipped') : '';
        var stops = laneStops(b);
        // La voie continue vers la jonction, sauf si elle finit le workflow.
        var exit = !stops;
        var state = decided ? '<span class="wf-lane-state' + (isTaken ? ' is-taken' : '') + '">' + esc(isTaken ? T('voie prise') : T('écartée')) + '</span>' : '';
        var head = cond.type === 'route'
            ? '<div class="wf-lane-pill"><span class="wf-lane-num">' + (index + 1) + '</span><span class="wf-lane-name" title="' + esc(b.label || T('Voie sans nom')) + '">' + esc(b.label || T('Voie sans nom')) + '</span>'
                + (cond.fallback === which ? '<span class="wf-lane-tag" title="' + esc(T('Prise quand MIA ne sait pas choisir')) + '">' + esc(T('par défaut')) + '</span>' : '') + state + '</div>'
                + (b.when ? '<div class="wf-lane-when">' + richText(b.when, pmap) + '</div>' : '')
            : '<div class="wf-lane-pill"><span class="wf-lane-key">' + icon(cat, which === 'yes' ? 'check' : 'x') + esc(which === 'yes' ? T('Si oui') : T('Si non')) + '</span>' + state + '</div>';
        var body = (l.nodes || []).map(function (n, j) {
            return '<div class="wf-link"></div>' + nodeBrick(def, n, cat, opts, pmap, all, { first: j === 0, last: j === l.nodes.length - 1 });
        }).join('');
        if (!body) { body = '<div class="wf-link"></div><div class="wf-lane-empty">' + esc(l.then === 'stop' ? T('Rien de plus.') : T('Rien de plus : on continue.')) + '</div>'; }
        var add = opts.editable ? '<button type="button" class="wf-lane-add" data-add="lane" data-cond="' + esc(cond.key) + '" data-lane="' + esc(which) + '">' + icon(cat, 'plus') + esc(T('Ajouter')) + '</button>' : '';
        // La fin d'une voie : la liaison vers la jonction (dessinée) ou l'arrêt.
        // Une voie qui finit par « Arrêter le workflow » : la brique dit déjà la fin.
        var stopBrick = (l.nodes || []).some(function (n) { return n.type === 'stop'; });
        var end = stopBrick ? ''
            : l.then === 'stop'
            ? '<div class="wf-lane-end is-stop">' + icon(cat, 'stop') + esc(T('Fin du workflow')) + '</div>'
            : '<span class="wf-lane-end wf-sr">' + esc(T('Reprend le rail')) + '</span>';
        return '<div class="wf-lane wf-lane-' + (cond.type === 'route' ? 'route' : which) + taken + (exit ? ' is-exit' : '') + (l.nodes.length ? '' : ' is-empty') + '"'
            + ' data-lane-of="' + esc(cond.key) + '" data-lane="' + esc(which) + '" style="--lane:var(--wf-l' + ((index % LANE_COLORS) + 1) + ')">' + head
            + '<div class="wf-lane-body">' + body + '</div>' + add + end + '</div>';
    }

    // ------------------------------------------------------------------
    // Liaisons de l'arbre (DOM) : tracées une fois le rail dans la page
    // ------------------------------------------------------------------

    /** Position d'un élément dans un ancêtre positionné (pixels de page, zoom compris). */
    function offsetIn(el, anc) {
        var x = 0;
        var y = 0;
        while (el && el !== anc) { x += el.offsetLeft; y += el.offsetTop; el = el.offsetParent; }
        return el === anc ? { x: x, y: y } : null;
    }

    function round(v) { return Math.round(v * 2) / 2; }

    /**
     * Une branche qui part du tronc : depuis (x0, ym) à l'horizontale, un
     * coude arrondi, puis à la verticale jusqu'à (x2, y2). x0 = le tronc,
     * ou la voie voisine plus proche du centre (les branches d'un même côté
     * se suivent au lieu de se superposer).
     */
    function splitPath(x0, ym, x2, y2) {
        if (Math.abs(x2 - x0) < 1) { return 'M' + round(x2) + ' ' + round(ym) + 'V' + round(y2); }
        var s = x2 > x0 ? 1 : -1;
        var r = Math.max(0, Math.min(12, Math.abs(x2 - x0) - 1, y2 - ym));
        return 'M' + round(x0) + ' ' + round(ym) + 'H' + round(x2 - s * r) + 'Q' + round(x2) + ' ' + round(ym) + ' ' + round(x2) + ' ' + round(ym + r) + 'V' + round(y2);
    }

    /** Le retour d'une voie : verticale, coude arrondi, horizontale jusqu'à x0. */
    function mergePath(x1, y1, ym, x0) {
        if (Math.abs(x1 - x0) < 1) { return 'M' + round(x1) + ' ' + round(y1) + 'V' + round(ym); }
        var s = x0 > x1 ? 1 : -1;
        var r = Math.max(0, Math.min(12, Math.abs(x1 - x0) - 1, ym - y1));
        return 'M' + round(x1) + ' ' + round(y1) + 'V' + round(ym - r) + 'Q' + round(x1) + ' ' + round(ym) + ' ' + round(x1 + s * r) + ' ' + round(ym) + 'H' + round(x0);
    }

    /**
     * Pour chaque voie, d'où part sa branche horizontale : du tronc pour la
     * plus proche du centre de chaque côté, sinon de la voie voisine.
     */
    function branchOrigins(xs, cx) {
        var out = xs.map(function () { return cx; });
        [-1, 1].forEach(function (side) {
            var idx = xs.map(function (x, i) { return i; }).filter(function (i) { return (xs[i] - cx) * side > 0.5; });
            idx.sort(function (a, b) { return Math.abs(xs[a] - cx) - Math.abs(xs[b] - cx); });
            idx.forEach(function (i, k) { if (k > 0) { out[i] = xs[idx[k - 1]]; } });
        });
        return out;
    }

    /** Une liaison ; `lane` = rang de la voie (sa couleur), absent pour le tronc. */
    function wire(d, cls, lane) {
        return '<path class="wf-wire' + (cls ? ' ' + cls : '') + '" d="' + d + '"'
            + (lane === undefined ? '' : ' style="stroke:var(--wf-l' + ((lane % LANE_COLORS) + 1) + ')"') + '/>';
    }

    /** Trace les liaisons d'un embranchement posé dans la page. */
    function drawFork(forkEl) {
        var svg = forkEl.querySelector('.wf-wires');
        var lanesEl = forkEl.querySelector('.wf-lanes');
        var joinEl = forkEl.querySelector('.wf-join > i');
        if (!svg || !lanesEl) { return; }
        var W = forkEl.offsetWidth;
        var H = forkEl.offsetHeight;
        if (!W || !H) { return; }
        svg.setAttribute('width', W);
        svg.setAttribute('height', H);
        var lanes = Array.prototype.filter.call(lanesEl.children, function (c) { return c.classList.contains('wf-lane'); });
        var stacked = lanes.length > 1 && lanes[1].offsetTop > lanes[0].offsetTop + 2;
        forkEl.classList.toggle('is-stacked', stacked);
        var cx = W / 2;
        var lt = offsetIn(lanesEl, forkEl);
        if (!lt) { return; }
        var paths = '';
        var trunk = '';
        // Les éléments enchaînés d'une voie, dans l'ordre, et leur centre.
        var info = lanes.map(function (ln) {
            var o = offsetIn(ln, forkEl);
            var exit = ln.classList.contains('is-exit');
            var sel = '.wf-lane-pill, .wf-lane-when, .wf-lane-body > .wf-brick, .wf-lane-body > .wf-lane-empty, .wf-lane-end.is-stop' + (exit ? ', .wf-lane-add' : '');
            var items = Array.prototype.map.call(ln.querySelectorAll(sel), function (el) {
                var p = offsetIn(el, forkEl);
                return p ? { top: p.y, bottom: p.y + el.offsetHeight } : null;
            }).filter(Boolean);
            var cls = (ln.classList.contains('is-skipped') ? ' is-skipped' : '') + (ln.classList.contains('is-taken') ? ' is-taken' : '') + (ln.classList.contains('is-empty') ? ' is-empty' : '');
            return { el: ln, x: o.x + ln.offsetWidth / 2, top: o.y, bottom: o.y + ln.offsetHeight, items: items, exit: exit, cls: cls };
        });
        // Dans chaque voie : d'un élément au suivant, à la verticale.
        info.forEach(function (ln, i) {
            for (var k = 1; k < ln.items.length; k++) {
                if (ln.items[k].top - ln.items[k - 1].bottom > 1) {
                    paths += wire('M' + round(ln.x) + ' ' + round(ln.items[k - 1].bottom) + 'V' + round(ln.items[k].top), 'is-lane' + ln.cls, i);
                }
            }
        });
        if (!stacked && info.length) {
            // Séparation : le tronc descend de la question, puis une branche par voie.
            var ym = Math.max(6, lt.y / 2);
            trunk += wire('M' + round(cx) + ' 0V' + round(ym), 'is-trunk');
            var xs = info.map(function (ln) { return ln.x; });
            var from = branchOrigins(xs, cx);
            // Les branches extérieures d'abord : les plus proches du centre passent dessus.
            var order = info.map(function (ln, i) { return i; }).sort(function (a, b) { return Math.abs(xs[b] - cx) - Math.abs(xs[a] - cx); });
            order.forEach(function (i) {
                var ln = info[i];
                var y2 = ln.items.length ? ln.items[0].top : ln.top;
                paths += wire(splitPath(from[i], ym, ln.x, y2), 'is-lane' + ln.cls, i);
            });
            // Jonction : chaque voie qui continue descend, puis revient au centre.
            var jp = joinEl ? offsetIn(joinEl, forkEl) : null;
            var lanesBottom = lt.y + lanesEl.offsetHeight;
            if (jp) {
                var ym2 = lanesBottom + Math.max(6, (jp.y - lanesBottom) / 2);
                var exits = info.map(function (ln, i) { return ln.exit ? i : -1; }).filter(function (i) { return i >= 0; });
                var exs = exits.map(function (i) { return info[i].x; });
                var to = branchOrigins(exs, cx);
                exits.slice().sort(function (a, b) { return Math.abs(info[b].x - cx) - Math.abs(info[a].x - cx); }).forEach(function (i) {
                    var ln = info[i];
                    var y1 = ln.items.length ? ln.items[ln.items.length - 1].bottom : ln.bottom;
                    paths += wire(mergePath(ln.x, y1, ym2, to[exits.indexOf(i)]), 'is-lane' + ln.cls, i);
                });
                if (exits.length) { trunk += wire('M' + round(cx) + ' ' + round(ym2) + 'V' + round(jp.y), 'is-trunk'); }
            }
        }
        svg.innerHTML = paths + trunk;
    }

    /**
     * Un rail plus large que son canevas (beaucoup de voies, volet ouvert) :
     * le canevas défile de côté. La première fois qu'il déborde, on le centre
     * sur le tronc — sinon la page s'ouvre sur le bord gauche des voies et
     * coupe les briques du tronc. Ensuite, le défilement reste à la personne.
     */
    function centerOnTrunk(host) {
        var sc = host;
        while (sc && sc !== root.document.body) {
            var ov = root.getComputedStyle ? root.getComputedStyle(sc).overflowX : '';
            if (ov === 'auto' || ov === 'scroll') { break; }
            sc = sc.parentElement;
        }
        if (!sc || sc === root.document.body) { return; }
        var over = sc.scrollWidth - sc.clientWidth;
        if (over <= 1) { sc.__wfCentered = false; return; }
        if (sc.__wfCentered) { return; }
        sc.__wfCentered = true;
        sc.scrollLeft = Math.round(over / 2);
    }

    /**
     * Trace les liaisons de tous les embranchements d'un rail posé, et les
     * retrace quand la page change de taille (volet ouvert, fenêtre, police
     * chargée). À appeler après chaque rendu du rail.
     */
    function drawForks(host) {
        if (!host || !host.querySelectorAll) { return; }
        host.querySelectorAll('.wf-fork').forEach(drawFork);
        centerOnTrunk(host);
        if (host.__wfForksWatched) { return; }
        host.__wfForksWatched = true;
        var pending = false;
        var redraw = function () {
            if (pending) { return; }
            pending = true;
            var run = function () { pending = false; host.querySelectorAll('.wf-fork').forEach(drawFork); centerOnTrunk(host); };
            if (root.requestAnimationFrame) { root.requestAnimationFrame(run); } else { setTimeout(run, 16); }
        };
        if (root.ResizeObserver) {
            var lastW = 0;
            new root.ResizeObserver(function (entries) {
                var w = entries[0] && entries[0].contentRect ? Math.round(entries[0].contentRect.width) : 0;
                if (w !== lastW) { lastW = w; redraw(); }
            }).observe(host);
        }
        if (root.addEventListener) { root.addEventListener('resize', redraw); }
        if (root.document && root.document.fonts && root.document.fonts.ready) { root.document.fonts.ready.then(redraw); }
    }

    /**
     * Le corps d'une boucle : ses briques, encadrées, avec le retour au
     * début du tour ; en exécution, l'avancement (tour en cours, éléments).
     */
    function loopBody(def, loop, cat, opts, pmap, all) {
        var body = loop.body || [];
        var lp = opts.state && opts.state.loops ? opts.state.loops[loop.key] : null;
        var st = opts.state && (opts.state.nodes || {})[loop.key];
        var head = '<div class="wf-loop-head">' + icon(cat, 'loop')
            + '<span>' + esc(loop.mode === 'until' ? T('À chaque tour') : T('Pour chaque élément')) + '</span>';
        if (loop.mode !== 'until' && loop.item && pmap[loop.item.key]) {
            head += pieceChip(pmap[loop.item.key], 'item');
        }
        head += '</div>';
        var progress = '';
        if (lp && st) {
            var total = lp.mode === 'until' ? lp.max : lp.count;
            var doneN = st.status === 'done' ? (lp.mode === 'until' ? lp.i : lp.count) : Math.max(0, lp.i - 1);
            var pct = total > 0 ? Math.round(doneN * 100 / total) : 0;
            progress = '<div class="wf-loop-progress" title="' + esc(lp.mode === 'until' ? T('Tour {n} sur {total}', { n: lp.i, total: total }) : T('{n} éléments traités sur {total}', { n: doneN, total: total })) + '">'
                + '<i style="width:' + pct + '%"></i></div>';
        }
        var inner = body.map(function (n, j) {
            return (j > 0 ? '<div class="wf-link"></div>' : '') + nodeBrick(def, n, cat, opts, pmap, all, { first: j === 0, last: j === body.length - 1 });
        }).join('');
        if (!inner) { inner = '<div class="wf-lane-empty">' + esc(T('Ajoutez les briques à répéter.')) + '</div>'; }
        var add = opts.editable ? '<button type="button" class="wf-lane-add" data-add="body" data-loop="' + esc(loop.key) + '">' + icon(cat, 'plus') + esc(T('Ajouter')) + '</button>' : '';
        var foot = '<div class="wf-loop-foot">' + icon(cat, 'retry') + esc(loop.mode === 'until'
            ? T('Recommence tant que la condition n’est pas remplie')
            : T('Élément suivant')) + '</div>';
        return '<div class="wf-loopbox"><div class="wf-loop' + (st && st.status === 'running' ? ' is-live' : '') + '" data-loop-of="' + esc(loop.key) + '">' + head + progress
            + '<div class="wf-loop-body">' + inner + '</div>' + add + foot + '</div></div>';
    }

    /** Silhouette d'un workflow : un point par brique (liste, chat). */
    function silhouette(seq, hues) {
        var K = { d: hues.start, s: hues.step, x: hues.action, k: hues.create, c: hues.condition, a: hues.route, l: hues.loop, g: hues.gate, w: hues.wait, n: hues.notify, q: hues.stop, e: hues.end };
        return '<div class="wf-sil" aria-hidden="true">' + String(seq || '').split('').map(function (k, i) {
            var shape = k === 'c' || k === 'a' ? 'd' : (k === 'g' ? 'r' : (k === 'l' ? 'o' : ''));
            return (i ? '<b></b>' : '') + '<i class="' + shape + '" style="--k:' + (K[k] || hues.step) + '"></i>';
        }).join('') + '</div>';
    }

    /** Surligne une pièce partout dans un rail posé (null = aucune). */
    function highlight(host, key) {
        if (!host) { return; }
        var rails = host.querySelectorAll('.wf-rail');
        rails.forEach(function (r) { r.classList.toggle('has-hl', !!key); });
        host.querySelectorAll('[data-piece]').forEach(function (el) {
            el.classList.toggle('is-hl', !!key && el.getAttribute('data-piece') === key);
        });
        // La brique qui remet ou lit la pièce s'allume avec elle : le trajet
        // d'une pièce se lit d'un coup d'œil, sans ligne à suivre.
        host.querySelectorAll('.wf-brick').forEach(function (b) {
            b.classList.toggle('is-hl', !!key && !!b.querySelector('[data-piece="' + key + '"]'));
        });
    }

    root.MiaWorkflowRail = {
        pieces: pieces, available: available, readsOf: readsOf, untilReadsOf: untilReadsOf, mentions: mentions, locate: locate,
        itemShape: itemShape, readsPiece: readsPiece, loopSentence: loopSentence, loopAccepts: loopAccepts, SOURCE_FIELDS: SOURCE_FIELDS, DRIVE_FILE_SOURCES: DRIVE_FILE_SOURCES,
        branchesOf: branchesOf, isBranching: isBranching, filterTexts: filterTexts, hasFilter: hasFilter, transitMap: transitMap,
        loopCollect: loopCollect, html: html, silhouette: silhouette, laneStops: laneStops, highlight: highlight, richText: richText, esc: esc, pieceChip: pieceChip,
        drawForks: drawForks, splitPath: splitPath, mergePath: mergePath, branchOrigins: branchOrigins,
        statusPill: statusPill, avatar: avatar, waitText: waitText, produces: produces, findCreation: findCreation,
    };
})(typeof window !== 'undefined' ? window : globalThis);
