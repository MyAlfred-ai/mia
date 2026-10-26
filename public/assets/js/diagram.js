/**
 * Moteur des schémas de composition — une tâche programmée (Paramètres →
 * Tâches programmées), un assistant (Espaces → Assistants) et une base
 * de connaissances (Espaces → Bases de connaissances).
 *
 *                 ┌──────────── Déclencheur ────────────┐   (tâche seulement)
 *                 └──────────────────┬──────────────────┘   ou des ENTRÉES
 *     ┌── Modèle ──┐        ┌────────▼─── Assistant ──────┐    (les sources
 *     │            ├───────▶│ nom, description, consigne  │     d'une base)
 *     └────────────┘        │ ou prompt, caractéristiques │
 *                           └──────▲─────────────▲────────┘
 *        ┌── Connecteurs / Bases / Documents ────┴─┐   (Outils)
 *
 * SEUL rendu des schémas. Chaque sujet a son MODÈLE, pur, qui décide de ce
 * qu'on montre et produit une VUE de même forme — schedule-diagram.js pour
 * une tâche, assistant-diagram.js pour un assistant, kb-diagram.js pour une
 * base — ; ce fichier la dessine. Des rendus séparés divergeraient au premier
 * ajustement : une teinte, un pictogramme ou une flèche corrigés ici le sont
 * pour tous.
 *
 * Aucun texte en dur : tout ce qui s'affiche vient de la vue, donc du
 * catalogue du serveur (TaskDiagram, AssistantDiagram, KbDiagram).
 *
 * La vue :
 *   aria, active (false : couleurs éteintes, traits pointillés),
 *   trigger   — null ou { kicker, icon, title, missing, status, pause, lines, volume },
 *   inputs    — sans déclencheur, une rangée de nœuds au-dessus du centre, de
 *               la forme d'un groupe : ce qui l'ALIMENTE (flèches vers lui) ;
 *   llm       — { kicker, icon, label, provider, logo, note, warn, muted, params? },
 *   assistant — le nœud central : { kind, kicker, icon, name, missingName?, avatar,
 *               glyph? (pictogramme du catalogue, sans avatar), color, initial?,
 *               description, status?, quote? ({ text, target, missing? }), traits },
 *   groups    — en dessous : [{ kind, title, icon, items, count?, more?, flow?,
 *               missing?, status? ({ text, on, muted? }), headline?, sub?, empty?,
 *               note?, warn? }] — `flow: 'out'` : la flèche part du centre VERS le
 *               nœud (ce que le centre sert) ; sans lui, elle va vers le centre ;
 *               un élément : { label, icon, note, warn, avatar? (chemin local), dot?
 *               (couleur #RRGGBB, avec `initial`) } ;
 *   empty, emptyTarget?, jump (null ou { cible: intitulé }), editIcon.
 *
 * Chaque nœud DÉSIGNE ce qu'il montre (`data-target`) ; c'est à la page de
 * dire où cela se règle. Une vue qui porte `jump` — un intitulé par cible,
 * déjà traduit — rend le schéma INTERACTIF : classe `is-interactive`, et sur
 * chaque nœud nommé un vrai bouton (`.sd-jump`, le crayon) qui porte la même
 * cible. Sans `jump`, rien de tout cela n'est rendu.
 *
 * Deux étages :
 *  - `html(view)` — PUR : le balisage, tout texte échappé, seules les icônes
 *    du catalogue (fournies par le serveur) passent telles quelles ;
 *  - `mount(host, view, opts)` / `draw(host)` — DOM : pose le balisage puis
 *    trace les flèches (SVG) d'après la position réelle des nœuds, retracées à
 *    chaque changement de taille. Positions lues par offsetLeft/offsetTop :
 *    l'animation d'entrée (transform) ne les fausse pas.
 *
 * Sous node (tests), seule la partie pure sert ; rien ne touche au DOM avant
 * un appel à mount().
 */
(function (root) {
    'use strict';

    // =====================================================================
    // Partie pure
    // =====================================================================

    const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, (c) => ESCAPES[c]);
    }

    /** « Prochaine : {date} » → valeur posée, substituants inconnus laissés. */
    function fill(template, vars) {
        return String(template || '').replace(/\{(\w+)\}/g, (m, k) => (Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : m));
    }

    const COLOR = /^#[0-9a-fA-F]{6}$/;
    // Images du schéma : chemins de CETTE origine seulement (avatars, logos).
    const LOCAL_PATH = /^\/(?!\/)[^\s"'<>\\]*$/;

    function color(value) { return COLOR.test(String(value || '')) ? String(value) : ''; }
    function localPath(value) { return LOCAL_PATH.test(String(value || '')) ? String(value) : ''; }
    function pick(map, key) {
        return map && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : null;
    }
    /**
     * Couleur assez franche pour teinter un nœud : une couleur presque noire
     * (le #1e293b proposé à la création) ou presque blanche ne teinterait
     * rien — le nœud prend alors l'accent de l'instance, la pastille de
     * l'avatar garde la vraie couleur. Luminance relative (WCAG).
     */
    function tint(value) {
        const hex = color(value);
        if (!hex) return '';
        const lin = [1, 3, 5].map((i) => {
            const v = parseInt(hex.substr(i, 2), 16) / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        const l = 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
        return l < 0.03 || l > 0.88 ? '' : hex;
    }

    /** Initiale d'un nom (pastille d'un assistant sans avatar), comme la liste des assistants. */
    function initial(name) {
        const first = Array.from(String(name || '').trim())[0];
        return first ? first.toLocaleUpperCase() : '';
    }

    /**
     * Tête d'un nœud : pictogramme (et, pour un groupe, le nombre d'éléments
     * en pastille sur son coin), intitulé, et ce qu'on y ajoute à droite.
     */
    function head(icon, kicker, extra, count) {
        return '<div class="sd-head"><span class="sd-ic" aria-hidden="true">' + (icon || '')
            + (count ? '<span class="sd-count">' + Number(count) + '</span>' : '') + '</span>'
            + '<span class="sd-kicker">' + esc(kicker) + '</span>' + (extra || '') + '</div>';
    }

    /**
     * Avatar de l'assistant : son image ; sinon une pastille de sa couleur,
     * pleine et portant son initiale quand la vue la donne (comme la liste
     * des assistants), un simple point sinon.
     */
    function avatarHtml(a) {
        if (a.avatar) return '<span class="sd-ava"><img src="' + esc(a.avatar) + '" alt=""></span>';
        if (a.kind === 'missing') return '<span class="sd-ava is-missing" aria-hidden="true">?</span>';
        // Un sujet sans visage (une base) : son pictogramme, fourni par le catalogue.
        if (a.glyph) return '<span class="sd-ava is-glyph" aria-hidden="true">' + a.glyph + '</span>';
        if (a.initial) {
            return '<span class="sd-ava is-dot has-initial" aria-hidden="true"><span style="background:' + esc(a.color || 'var(--accent)') + '">'
                + esc(a.initial) + '</span></span>';
        }
        return '<span class="sd-ava is-dot"><span style="background:' + esc(a.color || 'var(--accent)') + '"></span></span>';
    }

    /** Pastilles (caractéristiques de l'assistant, réglages du modèle). */
    function pills(list, target, extraClass) {
        return '<ul class="sd-traits' + (extraClass ? ' ' + extraClass : '') + '">'
            + list.map((tr) => '<li class="sd-trait' + (tr.tone ? ' is-' + esc(tr.tone) : '') + '"' + (tr.target ? target(tr.target) : '') + '>'
                + '<span class="sd-trait-ic" aria-hidden="true">' + (tr.icon || '') + '</span>' + esc(tr.text) + '</li>').join('')
            + '</ul>';
    }

    /**
     * Pictogramme d'un élément : l'avatar d'une personne ou d'un assistant
     * (chemin de CETTE origine), sinon une pastille de sa couleur à son
     * initiale, sinon l'icône du catalogue.
     */
    function itemIcon(it) {
        const ava = localPath(it.avatar);
        if (ava) return '<span class="sd-item-ic is-ava" aria-hidden="true"><img src="' + esc(ava) + '" alt=""></span>';
        const dot = color(it.dot);
        if (dot) return '<span class="sd-item-ic is-dot" aria-hidden="true"><span style="background:' + esc(dot) + '">' + esc(it.initial || '') + '</span></span>';
        return '<span class="sd-item-ic" aria-hidden="true">' + (it.icon || '') + '</span>';
    }

    /** Balisage du schéma. Pure — tout texte est échappé. */
    function html(view) {
        const v = view;
        let i = 0;
        const order = () => ' style="--sd-i:' + (i++) + '"';
        const trig = v.trigger || null;
        const inputs = !trig && Array.isArray(v.inputs) ? v.inputs : [];
        const jump = v.jump || null;
        // Ce que le nœud désigne ; et, en mode interactif, le bouton qui y
        // mène — un vrai bouton, nommé par l'intitulé du réglage : le clavier
        // l'atteint sans que le nœud entier ne devienne un bouton (son
        // contenu resterait sinon muet pour un lecteur d'écran).
        const target = (key) => ' data-target="' + esc(key) + '"';
        const jumpBtn = (key) => (jump && jump[key]
            ? '<button type="button" class="sd-jump" data-target="' + esc(key) + '" title="' + esc(jump[key]) + '" aria-label="' + esc(jump[key]) + '">' + (v.editIcon || '') + '</button>'
            : '');

        // Un nœud de groupe : en dessous du centre (ce qu'il consulte, ou ce
        // qu'il sert quand `flow` vaut 'out'), ou au-dessus (ce qui l'alimente).
        const group = (g, node, edge) => {
            const items = g.items || [];
            let markup = '<div class="sd-node sd-group sd-' + esc(g.kind) + (g.missing ? ' is-missing' : '') + '" data-node="' + node + '" data-edge="' + edge + '"'
                + (g.flow === 'out' ? ' data-flow="out"' : '') + target(g.kind) + order() + '>'
                + jumpBtn(g.kind)
                + head(g.icon, g.title, g.status ? '<span class="sd-status ' + (g.status.muted ? 'is-muted' : (g.status.on ? 'is-on' : 'is-off')) + '">' + esc(g.status.text) + '</span>' : '', g.count || items.length);
            if (g.headline) markup += '<div class="sd-title">' + esc(g.headline) + '</div>';
            if (g.sub) markup += '<div class="sd-sub">' + esc(g.sub) + '</div>';
            if (items.length || g.more) {
                markup += '<ul class="sd-items">'
                    + items.map((it) => '<li class="sd-item' + (it.warn ? ' is-warn' : '') + '" title="' + esc(it.note ? it.label + ' — ' + it.note : it.label) + '">'
                        + itemIcon(it)
                        + '<span class="sd-item-text"><span class="sd-item-label">' + esc(it.label) + '</span>'
                        + (it.note ? '<span class="sd-item-note">' + esc(it.note) + '</span>' : '')
                        + '</span></li>').join('')
                    + (g.more ? '<li class="sd-more">' + esc(g.more) + '</li>' : '')
                    + '</ul>';
            }
            if (g.empty) markup += '<p class="sd-group-empty">' + esc(g.empty) + '</p>';
            if (g.note) markup += '<div class="sd-note' + (g.warn ? ' is-warn' : '') + '">' + esc(g.note) + '</div>';
            return markup + '</div>';
        };

        let out = '<div class="sd' + (v.active ? '' : ' is-paused') + (jump ? ' is-interactive' : '') + '" role="group" aria-label="' + esc(v.aria) + '">'
            + '<div class="sd-canvas' + (trig || inputs.length ? '' : ' no-trigger') + '">'
            + '<svg class="sd-edges" aria-hidden="true" focusable="false"></svg>';

        // Ce qui alimente le centre — au-dessus, à la place d'un déclencheur.
        if (inputs.length) {
            out += '<div class="sd-inputs">' + inputs.map((g) => group(g, 'input', 'down')).join('') + '</div>';
        }

        // Déclencheur — en haut (une tâche ; un assistant n'en a pas).
        if (trig) {
            out += '<div class="sd-node sd-trigger' + (trig.missing ? ' is-missing' : '') + '" data-node="trigger" data-edge="down"' + target('trigger') + order() + '>'
                + jumpBtn('trigger')
                + head(trig.icon, trig.kicker, '<span class="sd-status ' + (v.active ? 'is-on' : 'is-off') + '">' + esc(trig.status) + '</span>')
                + '<div class="sd-title">' + esc(trig.title) + '</div>';
            if (trig.lines.length) out += '<div class="sd-sub">' + trig.lines.map(esc).join(' · ') + '</div>';
            if (trig.volume) out += '<div class="sd-sub sd-volume">' + esc(trig.volume) + '</div>';
            if (trig.pause) out += '<div class="sd-note is-warn">' + esc(trig.pause) + '</div>';
            out += '</div>';
        }

        // Modèle — à gauche.
        const m = v.llm;
        out += '<div class="sd-node sd-llm' + (m.muted ? ' is-missing' : '') + '" data-node="llm" data-edge="side"' + target('llm') + order() + '>'
            + jumpBtn('llm')
            + head(m.icon, m.kicker)
            + '<div class="sd-llm-id">'
            + (m.logo ? '<img class="sd-logo" src="' + esc(m.logo) + '" alt="">' : '')
            + '<span class="sd-llm-text"><span class="sd-title">' + esc(m.label) + '</span>'
            + (m.provider ? '<span class="sd-sub">' + esc(m.provider) + '</span>' : '')
            + '</span></div>'
            + (m.note ? '<div class="sd-note' + (m.warn ? ' is-warn' : '') + '">' + esc(m.note) + '</div>' : '')
            + (m.params && m.params.length ? pills(m.params, target, 'sd-params') : '')
            + '</div>';

        // Assistant — au centre.
        const a = v.assistant;
        // La citation (consigne d'une tâche, prompt d'un assistant), l'état et
        // les pastilles désignent un réglage plus précis que le nœud : un clic
        // dessus y mène ; le bouton du nœud, lui, mène à l'assistant.
        const quote = a.quote || null;
        const status = a.status
            ? '<span class="sd-status ' + (a.status.on ? 'is-on' : 'is-off') + '"' + (a.status.target ? target(a.status.target) : '') + '>' + esc(a.status.text) + '</span>'
            : '';
        const hue = tint(a.color);
        out += '<div class="sd-node sd-assistant is-' + esc(a.kind) + '" data-node="assistant"' + target('assistant')
            + (hue ? ' style="--sd-assistant:' + esc(hue) + ';--sd-i:' + (i++) + '"' : order()) + '>'
            + jumpBtn('assistant')
            + head(a.icon, a.kicker, status)
            + '<div class="sd-assistant-id">' + avatarHtml(a)
            + '<span class="sd-assistant-text"><span class="sd-title sd-name' + (a.missingName ? ' is-missing' : '') + '">' + esc(a.name) + '</span>'
            + (a.description ? '<span class="sd-desc">' + esc(a.description) + '</span>' : '')
            + '</span></div>'
            + (quote ? '<p class="sd-quote' + (quote.missing ? ' is-missing' : '') + '"' + (quote.target ? target(quote.target) : '') + '><span>' + esc(quote.text) + '</span></p>' : '')
            + (a.traits && a.traits.length ? pills(a.traits, target, '') : '')
            + '</div>';

        // Ce que le sujet consulte et engage — en dessous.
        out += '<div class="sd-sources">';
        if (v.groups.length === 0) {
            // Rien à relier : pas de flèche depuis le vide.
            const emptyTarget = v.emptyTarget || '';
            out += '<div class="sd-node sd-empty" data-node="source"' + (emptyTarget ? target(emptyTarget) : '') + order() + '>'
                + (emptyTarget ? jumpBtn(emptyTarget) : '') + esc(v.empty) + '</div>';
        }
        v.groups.forEach((g) => { out += group(g, 'source', 'up'); });
        out += '</div></div></div>';
        return out;
    }

    // =====================================================================
    // Tracé des flèches (DOM)
    // =====================================================================

    let markerSeq = 0;
    const R = (n) => Math.round(n * 10) / 10;
    const clamp = (n, lo, hi) => (hi < lo ? (lo + hi) / 2 : Math.min(Math.max(n, lo), hi));

    /** Rectangle d'un nœud dans le repère du canevas (positions de mise en page). */
    function rect(el, canvas) {
        let l = 0;
        let t = 0;
        let node = el;
        while (node && node !== canvas) {
            l += node.offsetLeft;
            t += node.offsetTop;
            node = node.offsetParent;
        }
        const w = el.offsetWidth;
        const h = el.offsetHeight;
        return { l, t, r: l + w, b: t + h, cx: l + w / 2, cy: t + h / 2, w, h };
    }

    /** Courbe verticale (Mermaid) ; droite quand les deux x coïncident. */
    function vCurve(x1, y1, x2, y2) {
        if (Math.abs(x1 - x2) < 1) return 'M' + R(x1) + ' ' + R(y1) + 'V' + R(y2);
        const ym = (y1 + y2) / 2;
        return 'M' + R(x1) + ' ' + R(y1) + 'C' + R(x1) + ' ' + R(ym) + ' ' + R(x2) + ' ' + R(ym) + ' ' + R(x2) + ' ' + R(y2);
    }
    function hCurve(x1, y1, x2, y2) {
        if (Math.abs(y1 - y2) < 1) return 'M' + R(x1) + ' ' + R(y1) + 'H' + R(x2);
        const xm = (x1 + x2) / 2;
        return 'M' + R(x1) + ' ' + R(y1) + 'C' + R(xm) + ' ' + R(y1) + ' ' + R(xm) + ' ' + R(y2) + ' ' + R(x2) + ' ' + R(y2);
    }

    /** Positions d'arrivée sur un bord : sous chaque nœud si possible, écartées d'au moins 22 px. */
    function spread(xs) {
        const ends = xs.slice();
        for (let k = 1; k < ends.length; k++) ends[k] = Math.max(ends[k], ends[k - 1] + 22);
        for (let k = ends.length - 2; k >= 0; k--) ends[k] = Math.min(ends[k], ends[k + 1] - 22);
        return ends;
    }

    /**
     * Par la gouttière de gauche (colonne unique) : du bord gauche d'un nœud
     * au bord gauche de l'autre, raccords en quart de cercle. La pointe est
     * à l'arrivée.
     */
    function gutter(x1, y1, x2, y2) {
        const bus = 8;
        const dir = y2 > y1 ? 1 : -1;
        return 'M' + R(x1) + ' ' + R(y1) + 'H' + R(bus + 8) + 'Q' + bus + ' ' + R(y1) + ' ' + bus + ' ' + R(y1 + 8 * dir)
            + 'V' + R(y2 - 8 * dir) + 'Q' + bus + ' ' + R(y2) + ' ' + R(bus + 8) + ' ' + R(y2) + 'H' + R(x2 - 3);
    }

    /**
     * Les flèches vont VERS le nœud central — sauf celles d'un nœud marqué
     * `data-flow="out"`, qui en PARTENT (ce que le centre sert). Côté de
     * départ et d'arrivée choisis d'après la position réelle : au-dessus, à
     * gauche, en dessous. En colonne unique (petits écrans), ce qui est en
     * dessous — et une rangée de plusieurs entrées au-dessus — rejoint le
     * centre par la gouttière de gauche, sans traverser les autres nœuds.
     */
    function routes(canvas, T, stacked) {
        const GAP = 3; // la pointe touche le bord sans le recouvrir
        const above = [];
        const below = [];
        const out = [];
        canvas.querySelectorAll('[data-edge]').forEach((el) => {
            const S = rect(el, canvas);
            S.out = el.getAttribute('data-flow') === 'out';
            // Au-dessus et en dessous d'abord : un nœud du bas, même décalé à
            // gauche, rejoint le centre par le bas, jamais par son flanc.
            if (S.b <= T.t + 1) {
                above.push(S);
            } else if (S.t >= T.b - 1) {
                below.push(S);
            } else if (S.r <= T.l + 1) {
                const y = clamp(T.cy, S.t + 16, S.b - 16);
                out.push({ d: hCurve(S.r, y, T.l - GAP, clamp(y, T.t + 18, T.b - 18)), x1: S.r, y1: y });
            }
        });

        // Un seul nœud au-dessus (le déclencheur d'une tâche) : droit vers le
        // milieu du centre.
        if (above.length === 1) {
            const S = above[0];
            const x = clamp(T.cx, S.l + 16, S.r - 16);
            out.push({ d: vCurve(x, S.b, clamp(x, T.l + 18, T.r - 18), T.t - GAP), x1: x, y1: S.b });
        }

        const ty = clamp(T.cy, T.t + 18, T.b - 18);
        if (stacked) {
            const viaGutter = (above.length > 1 ? above : []).concat(below);
            viaGutter.forEach((S) => {
                const y = S.t + Math.min(20, S.h / 2);
                out.push(S.out
                    ? { d: gutter(T.l, ty, S.l, y), x1: T.l, y1: ty }
                    : { d: gutter(S.l, y, T.l, ty), x1: S.l, y1: y });
            });
            return out;
        }

        // Plusieurs entrées au-dessus : arrivées réparties sur le haut du centre.
        if (above.length > 1) {
            above.sort((p, q) => p.cx - q.cx);
            const tops = spread(above.map((S) => clamp(S.cx, T.l + 18, T.r - 18)));
            above.forEach((S, k) => {
                const x2 = clamp(tops[k], T.l + 12, T.r - 12);
                const x1 = clamp(x2, S.l + 16, S.r - 16) === x2 ? x2 : S.cx;
                out.push({ d: vCurve(x1, S.b, x2, T.t - GAP), x1, y1: S.b });
            });
        }

        // Sous le centre : sous le nœud quand c'est possible (trait droit).
        below.sort((p, q) => p.cx - q.cx);
        const ends = spread(below.map((S) => clamp(S.cx, T.l + 18, T.r - 18)));
        below.forEach((S, k) => {
            const x2 = clamp(ends[k], T.l + 12, T.r - 12);
            const x1 = clamp(x2, S.l + 16, S.r - 16) === x2 ? x2 : S.cx;
            out.push(S.out
                ? { d: vCurve(x2, T.b, x1, S.t - GAP), x1: x2, y1: T.b }
                : { d: vCurve(x1, S.t, x2, T.b + GAP), x1, y1: S.t });
        });
        return out;
    }

    function draw(host) {
        const canvas = host && host.querySelector('.sd-canvas');
        const svg = canvas && canvas.querySelector('.sd-edges');
        const target = canvas && canvas.querySelector('[data-node="assistant"]');
        if (!svg || !target || !canvas.offsetWidth) return;

        const T = rect(target, canvas);
        const stacked = getComputedStyle(canvas).getPropertyValue('--sd-stack').trim() === '1';
        if (!svg.dataset.marker) svg.dataset.marker = 'sd-arrow-' + (++markerSeq);
        const id = svg.dataset.marker;
        const w = canvas.offsetWidth;
        const h = canvas.offsetHeight;

        svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
        svg.setAttribute('width', String(w));
        svg.setAttribute('height', String(h));
        svg.innerHTML = '<defs><marker id="' + id + '" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto" markerUnits="userSpaceOnUse">'
            + '<path class="sd-arrow" d="M0 0L10 5L0 10z"/></marker></defs>'
            + routes(canvas, T, stacked).map((e) => '<path class="sd-edge" d="' + e.d + '" marker-end="url(#' + id + ')"/>'
                + '<circle class="sd-edge-dot" cx="' + R(e.x1) + '" cy="' + R(e.y1) + '" r="2.6"/>').join('');
    }

    // =====================================================================
    // Pose dans la page (DOM)
    // =====================================================================

    /**
     * Pose une vue dans `host`. Un balisage inchangé n'est pas reposé (les
     * formulaires appellent à chaque frappe) ; `opts.animate` joue l'entrée
     * des nœuds — pour un changement de sujet, jamais pendant la saisie.
     */
    function mount(host, view, opts) {
        if (!host) return;
        const markup = html(view);
        if (host.__sdMarkup !== markup) {
            host.__sdMarkup = markup;
            host.innerHTML = markup;
            const sd = host.querySelector('.sd');
            if (sd && opts && opts.animate && !reducedMotion()) sd.classList.add('is-entering');
            host.querySelectorAll('img').forEach((img) => {
                if (!img.complete) img.addEventListener('load', () => schedule(host), { once: true });
            });
            observe(host);
        }
        schedule(host);
    }

    function reducedMotion() {
        try {
            return !!(root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (_e) {
            return false;
        }
    }

    function schedule(host) {
        if (host.__sdFrame) return;
        const raf = root.requestAnimationFrame || ((fn) => setTimeout(fn, 16));
        host.__sdFrame = raf(() => {
            host.__sdFrame = 0;
            draw(host);
        });
    }

    /** Retracé quand le schéma ou l'un de ses nœuds change de taille. */
    function observe(host) {
        if (typeof root.ResizeObserver !== 'function') return;
        if (!host.__sdObserver) host.__sdObserver = new root.ResizeObserver(() => schedule(host));
        const ro = host.__sdObserver;
        ro.disconnect();
        ro.observe(host);
        host.querySelectorAll('.sd-canvas, .sd-node').forEach((el) => ro.observe(el));
    }

    const api = { html, mount, draw, escape: esc, fill, color, tint, localPath, pick, initial };
    root.MiaDiagram = api;
    if (typeof module === 'object' && module && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
