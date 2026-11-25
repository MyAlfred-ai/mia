/**
 * Écriture fluide d'une réponse (window.MiaStream).
 *
 * Le texte d'un modèle arrive par paquets irréguliers : quelques lettres ici,
 * trois phrases là. Les afficher tels quels donne une lecture par à-coups. Ce
 * module sépare ce qui est REÇU de ce qui est MONTRÉ :
 *
 *  1. un réglage de vitesse (noteArrival, advance) révèle le texte lettre à
 *     lettre, à chaque image, au rythme réel du modèle, avec un retard minime
 *     pour absorber l'irrégularité des paquets ;
 *  2. seul le DERNIER bloc est recalculé à chaque image : ce qui est terminé
 *     est figé (scanBlocks), et le reste est mis à jour par différence
 *     (morphChildren) — une réponse de 30 000 caractères ne coûte pas plus
 *     qu'une de 300, une image ne se recharge pas, une sélection tient ;
 *  3. une syntaxe en cours d'écriture ne se voit jamais (holdBack,
 *     closeInline) : pas de « ** » ni de « [lien](htt » à l'écran.
 *
 * Partie PURE (premières sections) exécutée sous node par les tests. Le rendu
 * Markdown et le suivi du défilement restent dans chat.js : `configure()`.
 */
(function (root) {
    'use strict';

    // ------------------------------------------------------------------
    // 1. Vitesse d'écriture (pure)
    // ------------------------------------------------------------------

    const TIMING = {
        // Retard visé derrière le texte reçu : de quoi garder un débit régulier
        // entre deux paquets, sans que la réponse paraisse en retard.
        LAG_MS: 170,
        // Temps pour résorber un écart à ce retard.
        CATCHUP_MS: 220,
        // Lettres par milliseconde : plancher (70/s), et rythme de départ, avant
        // d'avoir observé le modèle. En fin de réponse, plancher relevé.
        MIN_RATE: 0.07,
        START_RATE: 0.2,
        FINISH_RATE: 0.6,
        FINISH_MS: 90,
        // Au-delà de cette avance, inutile d'écrire : une page rechargée rejoue
        // d'un coup ce que le modèle a déjà produit, seule la fin s'écrit.
        JUMP_CHARS: 1500,
        // Fenêtre d'observation du rythme du modèle.
        RATE_WINDOW_MS: 700,
        // Au-delà, un silence n'est plus le rythme du modèle : un outil, une pause.
        ARRIVAL_CAP_MS: 500,
        // Plus longue image comptée : un onglet revenu au premier plan rattrape
        // son retard en quelques images, pas d'un coup.
        MAX_FRAME_MS: 100,
        // Attente d'une fin d'écriture, si le navigateur cesse de dessiner.
        DRAIN_LIMIT_MS: 450,
        // Sans nouvelle lettre, le curseur se met à respirer.
        WAITING_MS: 700,
    };

    function newSpeed() {
        return { shown: 0, target: 0, rate: TIMING.START_RATE, gap: 0, lastArrival: 0, finishing: false };
    }

    /** Un paquet de `added` caractères vient d'arriver : le rythme s'ajuste. */
    function noteArrival(speed, now, added) {
        if (added <= 0) return;
        if (speed.lastArrival > 0) {
            const gap = Math.min(TIMING.ARRIVAL_CAP_MS, Math.max(8, now - speed.lastArrival));
            const alpha = 1 - Math.exp(-gap / TIMING.RATE_WINDOW_MS);
            speed.rate += alpha * (added / gap - speed.rate);
            speed.gap += alpha * (gap - speed.gap);
        }
        speed.lastArrival = now;
    }

    /**
     * Fait avancer `shown` de `dt` millisecondes. La vitesse suit le rythme
     * observé, corrigée pour garder le retard visé ; retard et correction
     * s'allongent quand le modèle livre par grosses rafales espacées, sinon la
     * vitesse oscillerait entre l'arrêt et le sprint à chaque rafale.
     */
    function advance(speed, dt) {
        const backlog = speed.target - speed.shown;
        if (backlog <= 0) {
            speed.shown = speed.target;
            return;
        }
        if (backlog > TIMING.JUMP_CHARS) speed.shown = speed.target - TIMING.JUMP_CHARS;
        const lag = Math.max(TIMING.LAG_MS, speed.gap * 1.1);
        const catchup = Math.max(TIMING.CATCHUP_MS, speed.gap * 2);
        const ahead = speed.target - speed.shown;
        let rate = Math.max(TIMING.MIN_RATE, speed.rate + (ahead - speed.rate * lag) / catchup);
        if (speed.finishing) rate = Math.max(rate, TIMING.FINISH_RATE, ahead / TIMING.FINISH_MS);
        speed.shown = Math.min(speed.target, speed.shown + rate * dt);
    }

    /** Ne coupe jamais une paire de surrogates (émoji, caractère rare). */
    function safeLength(text, length) {
        if (length <= 0) return 0;
        if (length >= text.length) return text.length;
        const code = text.charCodeAt(length - 1);
        return code >= 0xd800 && code <= 0xdbff ? length - 1 : length;
    }

    // ------------------------------------------------------------------
    // 2. Blocs terminés (pure)
    // ------------------------------------------------------------------

    // Après des accents graves, l'info d'un bloc n'en contient pas : « ```x``` »
    // en début de ligne est du code en ligne, pas une ouverture.
    const FENCE_LINE = /^ {0,3}(`{3,}(?=[^`]*$)|~{3,})(.*)$/;
    // Après une ligne vide, ce qui peut PROLONGER le bloc d'avant : une liste
    // aérée (item suivant) ou un contenu en retrait. Rien d'autre ne franchit
    // une ligne vide en Markdown. « 1 » et « - » seuls sont indécidables.
    const CONTINUES = /^(?:[ \t]|[-*+](?:[ \t]|$)|\d{1,9}(?:[.)](?:[ \t]|$)|$))/;

    function closesFence(fence, line) {
        const m = line.match(FENCE_LINE);
        return !!m && m[1][0] === fence.mark[0] && m[1].length >= fence.mark.length && m[2].trim() === '';
    }

    /**
     * Fichier déclaré (```md fichier="x.md") : ses blocs de code intérieurs ne
     * le referment pas (cf. balanceFences de generated-files.js).
     */
    function isNamedFence(info) {
        const files = root.MiaFiles;
        return !!(files && info.trim() !== '' && files.parseInfo(info.trim()).name);
    }

    /**
     * Parcourt `text` depuis `from`, position hors de tout bloc de code.
     * `cut` = début du dernier bloc qu'on ne peut pas encore figer ; tout ce qui
     * le précède se rend seul, à l'identique du rendu de la réponse entière.
     *
     * @returns {{cut: number, inFence: boolean}}
     */
    function scanBlocks(text, from) {
        let cut = from;
        let fence = null;
        let blank = false;
        let at = from;

        while (at < text.length) {
            const nl = text.indexOf('\n', at);
            const complete = nl !== -1;
            const line = text.slice(at, complete ? nl : text.length).replace(/\r$/, '');

            if (fence) {
                if (!complete) break;
                const open = line.match(FENCE_LINE);
                if (fence.named && open && open[2].trim() !== '') {
                    fence.depth++;
                } else if (closesFence(fence, line)) {
                    if (fence.depth > 0) fence.depth--; else fence = null;
                }
            } else {
                const empty = line.trim() === '';
                if (!empty && blank && !CONTINUES.test(line)) cut = at;
                const open = complete ? line.match(FENCE_LINE) : null;
                if (open) fence = { mark: open[1], named: isNamedFence(open[2]), depth: 0 };
                blank = empty;
            }
            if (!complete) break;
            at = nl + 1;
        }
        return { cut, inFence: fence !== null };
    }

    // ------------------------------------------------------------------
    // 3. Syntaxe en cours d'écriture (pure)
    // ------------------------------------------------------------------

    // Une ligne qui n'est encore qu'un début de syntaxe : « # », « - », « 1. »,
    // « --- », « > », ou l'ouverture d'un bloc de code, langue comprise.
    const MARKER_ONLY = /^[ \t]*(?:#{1,6}|[-*+]|\d{1,9}[.)]?|[-=_*~:]{1,}|>)[ \t]*$/;
    const FENCE_START = /^ {0,3}(?:`{1,2}$|`{3,}[^`]*$|~{3,})/;
    const TABLE_ROW = /^[ \t]*\|/;
    const TABLE_SEPARATOR = /^[ \t]*\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/;
    // Lien ou image inachevés : « [texte », « [texte] », « [texte](http ».
    const OPEN_LINK = /!?\[[^\]\n]{0,200}(?:\](?:\([^)\n]{0,800})?)?$/;
    const OPEN_TAG = /<\/?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>\n]{0,300})?$/;
    const TRAILING_MARKS = /[*_~`]+$/;

    /**
     * Tableau en cours d'écriture : tant que sa ligne de séparation n'est pas
     * complète, rien ne dit que c'en est un, et il s'afficherait en texte brut.
     * Rend le début de son en-tête à retenir, -1 si rien n'est à retenir — ses
     * lignes suivantes, elles, se remplissent sous les yeux.
     */
    function tableStart(text, from) {
        const lines = text.slice(from).split('\n');
        const last = lines.length - 1;
        let top = lines[last] === '' ? last - 1 : last;
        if (top < 0 || !TABLE_ROW.test(lines[top])) return -1;
        while (top > 0 && TABLE_ROW.test(lines[top - 1])) top--;
        if (top + 1 < last && TABLE_SEPARATOR.test(lines[top + 1])) return -1;
        return from + lines.slice(0, top).reduce((length, line) => length + line.length + 1, 0);
    }

    /**
     * Longueur montrable de `text` (hors bloc de code) : s'arrête avant une
     * syntaxe qu'on ne peut pas encore interpréter. Elle apparaît d'un coup une
     * fois complète, au lieu de s'afficher en toutes lettres puis de se
     * transformer. `from` borne la recherche au bloc en cours.
     */
    function holdBack(text, from) {
        let end = text.length;
        const start = Math.max(from, text.lastIndexOf('\n', end - 1) + 1);

        // Marqueur de citation ChatGPT pas encore refermé (chat.js les retire).
        const mark = text.lastIndexOf('\uE200');
        if (mark >= from && mark > text.lastIndexOf('\uE201')) end = mark;

        const table = tableStart(text, from);
        if (table !== -1) return Math.min(end, table);

        const line = text.slice(start, end);
        if (line === '') return end;
        if (MARKER_ONLY.test(line) || FENCE_START.test(line)) return start;

        const link = line.match(OPEN_LINK);
        if (link) end = start + link.index;
        const tag = text.slice(start, end).match(OPEN_TAG);
        if (tag) end = start + tag.index;
        const marks = text.slice(start, end).match(TRAILING_MARKS);
        if (marks) end = start + marks.index;
        // « - ** » : une fois la marque retirée, reste une puce vide.
        return MARKER_ONLY.test(text.slice(start, end)) ? start : end;
    }

    /**
     * Referme le gras, l'italique, le barré et le code restés ouverts sur la
     * dernière ligne : « un mot en **gra » s'affiche déjà en gras. Sur une
     * ligne seulement — une marque ne franchit presque jamais la fin de ligne
     * dans une réponse, et une liste ne doit pas hériter d'une marque ouverte.
     */
    function closeInline(text) {
        const body = text.replace(/\s+$/, '');
        const trailing = text.slice(body.length);
        const start = body.lastIndexOf('\n') + 1;
        const line = body.slice(start);
        const stack = [];
        let code = 0;
        let i = 0;

        while (i < line.length) {
            const ch = line[i];
            if (ch === '\\') { i += 2; continue; }
            if (ch === '`') {
                let run = 1;
                while (line[i + run] === '`') run++;
                if (code === 0) code = run; else if (code === run) code = 0;
                i += run;
                continue;
            }
            if (code === 0 && (ch === '*' || ch === '_' || ch === '~')) {
                let run = 1;
                while (line[i + run] === ch) run++;
                const before = i > 0 ? line[i - 1] : ' ';
                const after = i + run < line.length ? line[i + run] : ' ';
                const closes = !/\s/.test(before);
                const inWord = /[\p{L}\p{N}]/u.test(before);
                // « a*b », « mon_fichier » : pas une marque.
                const opens = !/\s/.test(after) && !(inWord && (ch === '_' || run === 1));
                if (ch !== '~' || run >= 2) {
                    const mark = ch.repeat(ch === '~' ? 2 : Math.min(run, 3));
                    if (closes && stack[stack.length - 1] === mark) stack.pop();
                    else if (opens) stack.push(mark);
                }
                i += run;
                continue;
            }
            i++;
        }
        const closers = (code > 0 ? '`'.repeat(code) : '') + stack.reverse().join('');
        return body + closers + trailing;
    }

    /**
     * Ce qu'il faut rendre pour `text` déjà figé jusqu'à `from` : nouvelle
     * limite des blocs terminés, et Markdown du bloc en cours, prêt à rendre.
     * `final` : le texte est complet, plus rien à retenir.
     */
    function plan(text, from, final) {
        const scan = scanBlocks(text, from);
        const end = final || scan.inFence
            ? text.length
            : Math.max(scan.cut, holdBack(text, scan.cut));
        const tail = text.slice(scan.cut, end);
        return { cut: scan.cut, tail: final || scan.inFence ? tail : closeInline(tail) };
    }

    const api = {
        TIMING: TIMING,
        newSpeed: newSpeed,
        noteArrival: noteArrival,
        advance: advance,
        safeLength: safeLength,
        scanBlocks: scanBlocks,
        holdBack: holdBack,
        closeInline: closeInline,
        plan: plan,
    };

    // Sous node (tests) : seule la partie pure existe.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    root.MiaStream = api;
    if (typeof document === 'undefined') return;

    // ------------------------------------------------------------------
    // 4. Mise à jour du DOM par différence
    // ------------------------------------------------------------------

    function parseHtml(html) {
        const tpl = document.createElement('template');
        tpl.innerHTML = html;
        return Array.from(tpl.content.childNodes);
    }

    function syncAttributes(from, to) {
        for (const attr of Array.from(from.attributes)) {
            if (!to.hasAttribute(attr.name)) from.removeAttribute(attr.name);
        }
        for (const attr of Array.from(to.attributes)) {
            if (from.getAttribute(attr.name) !== attr.value) from.setAttribute(attr.name, attr.value);
        }
    }

    function morphNode(from, to) {
        if (from.nodeType !== to.nodeType || from.nodeName !== to.nodeName) {
            from.replaceWith(to);
            return;
        }
        if (from.nodeType === Node.ELEMENT_NODE) {
            syncAttributes(from, to);
            morphChildren(from, Array.from(to.childNodes), 0);
        } else if (from.nodeValue !== to.nodeValue) {
            from.nodeValue = to.nodeValue;
        }
    }

    /** Aligne les enfants de `parent`, à partir du rang `start`, sur `targets`. */
    function morphChildren(parent, targets, start) {
        let node = parent.childNodes[start] || null;
        for (const target of targets) {
            if (node) {
                const next = node.nextSibling;
                morphNode(node, target);
                node = next;
            } else {
                parent.appendChild(target);
            }
        }
        while (node) {
            const next = node.nextSibling;
            node.remove();
            node = next;
        }
    }

    /** Remplace le contenu de `el` sans recréer ce qui n'a pas changé. */
    function swap(el, html) {
        morphChildren(el, parseHtml(html), 0);
    }

    // ------------------------------------------------------------------
    // 5. Écriture d'une réponse
    // ------------------------------------------------------------------

    const hooks = { render: (text) => text, follow: null };
    const calm = root.matchMedia ? root.matchMedia('(prefers-reduced-motion: reduce)') : null;
    // Où poser le curseur : le dernier bloc de texte, jamais une carte.
    const TEXT_BLOCKS = /^(?:P|UL|OL|LI|BLOCKQUOTE|TABLE|THEAD|TBODY|TR|TD|TH|H[1-6]|PRE)$/;

    function configure(options) {
        Object.assign(hooks, options);
    }

    function create(el) {
        const cursor = document.createElement('span');
        cursor.className = 'stream-cursor';
        cursor.setAttribute('aria-hidden', 'true');
        cursor.innerHTML = '<span class="stream-caret"></span>';
        el.__stream = {
            el: el, raw: '', speed: newSpeed(), cut: 0, nodes: 0, tail: '', painted: 0,
            frame: 0, lastFrame: 0, lastPaint: 0, paintMs: 0, cursor: cursor,
            waitTimer: 0, drainTimer: 0, waiters: [],
        };
        return el.__stream;
    }

    function restart(p) {
        const md = p.el.querySelector('.md-render');
        if (md) md.textContent = '';
        Object.assign(p, { cut: 0, nodes: 0, tail: '', painted: 0, speed: newSpeed() });
    }

    function textHost(md) {
        let node = md;
        for (;;) {
            let last = node.lastChild;
            while (last && last.nodeType === Node.TEXT_NODE && last.nodeValue.trim() === '') last = last.previousSibling;
            if (!last) return node === md ? null : node;
            if (last.nodeType !== Node.ELEMENT_NODE) return node;
            if (node.nodeName === 'PRE' && last.nodeName === 'CODE') return last;
            if (TEXT_BLOCKS.test(last.nodeName)) { node = last; continue; }
            // Une carte (fichier, choix) ou une image : rien à la suite.
            return node === md ? null : node;
        }
    }

    function paint(p) {
        const md = p.el.querySelector('.md-render');
        if (!md) return;
        const started = performance.now();
        const length = safeLength(p.raw, Math.floor(p.speed.shown));
        const text = p.raw.slice(0, length);
        const step = plan(text, p.cut, p.speed.finishing && length >= p.raw.length);
        p.painted = length;
        if (step.cut === p.cut && step.tail === p.tail) return;

        p.cursor.remove();
        const settled = step.cut > p.cut ? parseHtml(hooks.render(text.slice(p.cut, step.cut))) : [];
        const tail = step.tail !== '' ? parseHtml(hooks.render(step.tail)) : [];
        morphChildren(md, settled.concat(tail), p.nodes);
        p.nodes += settled.length;
        p.cut = step.cut;
        p.tail = step.tail;
        const host = textHost(md);
        if (host) host.appendChild(p.cursor);
        p.paintMs += 0.3 * (performance.now() - started - p.paintMs);
    }

    function settle(p) {
        const waiters = p.waiters;
        p.waiters = [];
        clearTimeout(p.drainTimer);
        waiters.forEach((done) => done());
    }

    function dispose(p) {
        cancelAnimationFrame(p.frame);
        clearTimeout(p.waitTimer);
        p.cursor.remove();
        if (p.el.__stream === p) p.el.__stream = null;
        settle(p);
    }

    function caughtUp(p) {
        return p.speed.shown >= p.speed.target && p.painted >= p.raw.length;
    }

    function waitAgain(p) {
        clearTimeout(p.waitTimer);
        p.cursor.classList.remove('is-waiting');
        p.waitTimer = setTimeout(() => p.cursor.classList.add('is-waiting'), TIMING.WAITING_MS);
    }

    function tick(p, now) {
        p.frame = 0;
        if (p.el.__streamRenderFinalized || !p.el.isConnected) {
            dispose(p);
            return;
        }
        const dt = p.lastFrame ? Math.min(TIMING.MAX_FRAME_MS, now - p.lastFrame) : 16;
        p.lastFrame = now;
        advance(p.speed, dt);

        // Une réponse lourde à rendre garde sa vitesse : elle est simplement
        // dessinée moins souvent, par pas un peu plus grands.
        if (Math.floor(p.speed.shown) !== p.painted && now - p.lastPaint >= p.paintMs * 1.2) {
            paint(p);
            p.lastPaint = now;
        }
        const scrolling = hooks.follow ? hooks.follow(dt) : false;

        if (!caughtUp(p) || scrolling) {
            run(p);
            return;
        }
        p.lastFrame = 0;
        if (p.speed.finishing) settle(p);
    }

    function run(p) {
        if (!p.frame) p.frame = requestAnimationFrame((now) => tick(p, now));
    }

    /** Le texte reçu vaut désormais `raw` ; `instant` l'affiche sans l'écrire. */
    function feed(el, raw, options) {
        if (!el || el.__streamRenderFinalized) return;
        const p = el.__stream || create(el);
        if (raw.length < p.raw.length) restart(p);
        noteArrival(p.speed, performance.now(), raw.length - p.raw.length);
        p.raw = raw;
        p.speed.target = raw.length;
        if ((options && options.instant) || (calm && calm.matches)) p.speed.shown = raw.length;
        waitAgain(p);
        run(p);
    }

    /** Reste-t-il des lettres à montrer ? */
    function isWriting(el) {
        const p = el && el.__stream;
        return !!p && !caughtUp(p);
    }

    /** Appelle `done` une fois le texte entièrement écrit (très vite). */
    function drain(el, done) {
        const p = el && el.__stream;
        if (!p || document.hidden || caughtUp(p)) {
            done();
            return;
        }
        p.speed.finishing = true;
        p.waiters.push(done);
        clearTimeout(p.drainTimer);
        p.drainTimer = setTimeout(() => settle(p), TIMING.DRAIN_LIMIT_MS);
        run(p);
    }

    /** Fin de l'écriture : le contenu final prend la suite. */
    function stop(el) {
        if (el && el.__stream) dispose(el.__stream);
    }

    Object.assign(api, {
        configure: configure,
        feed: feed,
        isWriting: isWriting,
        drain: drain,
        stop: stop,
        swap: swap,
    });
})(typeof window !== 'undefined' ? window : globalThis);
