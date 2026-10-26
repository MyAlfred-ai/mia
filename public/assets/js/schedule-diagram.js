/**
 * Schéma d'une tâche programmée — Espaces → Tâches programmées.
 *
 *                 ┌──────────── Déclencheur ────────────┐
 *                 └──────────────────┬──────────────────┘
 *     ┌── Modèle ──┐        ┌────────▼─── Assistant ──────┐
 *     │            ├───────▶│ nom, description, consigne, │
 *     └────────────┘        │ ce que la tâche peut faire  │
 *                           └──────▲─────────────▲────────┘
 *              ┌── Connecteurs ────┴─┐   ┌───────┴── Bases ──┐   (Outils)
 *
 * Ce fichier est le MODÈLE de la tâche : ce qu'on montre d'elle. Le rendu
 * (balisage, flèches, pose dans la page) est celui de diagram.js, partagé
 * avec le schéma d'un assistant (assistant-diagram.js) — chargé AVANT ce
 * fichier. La liste (clic sur une tâche) et le formulaire (mis à jour à
 * chaque geste) l'appellent avec une configuration de la même forme
 * (TaskDiagram::spec côté PHP) et le catalogue du serveur (TaskDiagram::catalog) :
 * libellés déjà traduits, icônes de l'application. Ce fichier n'a AUCUN texte
 * en dur — tout ce qui s'affiche vient du catalogue ou de la tâche.
 *
 *  - `model(spec, catalog)` — PUR : ce qu'on montre (modèle résolu comme au
 *    tour réel, bases de l'assistant et de la tâche dans UN nœud, connecteurs
 *    dans UN nœud) ;
 *  - `html(view)`, `mount(host, spec, catalog, opts)`, `draw(host)` : ceux du
 *    moteur, la vue de la tâche en entrée.
 *
 * Chaque nœud DÉSIGNE ce qu'il montre (`data-target` : trigger, llm,
 * assistant, instruction, mode, destination, notify, connectors, kbs, tools,
 * sources) ; c'est à la page de dire où cela se règle. Le formulaire passe
 * `opts.jump` — un intitulé par cible, déjà traduit — et le schéma devient
 * INTERACTIF : classe `is-interactive`, et sur chaque nœud un vrai bouton
 * (`.sd-jump`, le crayon, nommé par cet intitulé) qui porte la même cible —
 * le clavier y accède, le contenu du nœud reste lisible tel quel. Un clic
 * n'importe où sur le nœud fait de même, par délégation côté page
 * (schedules.js). Sans `jump` (la liste), rien de tout cela n'est rendu.
 *
 * Sous node (tests), seules les parties pures servent ; rien ne touche au DOM
 * avant un appel à mount().
 */
(function (root) {
    'use strict';

    const E = root.MiaDiagram;
    if (!E) return;
    const { fill, color, localPath, pick } = E;

    /**
     * Le modèle qui répondra — la règle du tour réel (scheduledTurnLlm) :
     * l'assistant impose le sien (le défaut de l'application s'il n'en a
     * pas) ; sans assistant, celui de la tâche s'il est encore permis, sinon
     * le défaut.
     */
    function resolveLlm(spec, cat, card, missing) {
        const L = cat.labels || {};
        const llms = cat.llms || {};
        const entry = (slug, note) => {
            const llm = pick(llms, slug);
            return llm ? { label: llm.label, provider: llm.provider || '', logo: localPath(llm.logo), note } : null;
        };
        const byDefault = () => entry(cat.default_llm || '', L.llm_default)
            || { label: L.llm_default, provider: '', logo: '', note: '' };

        if (missing) return { label: L.llm_unknown, provider: '', logo: '', note: '', muted: true };
        if (card) return (card.llm && entry(card.llm, L.llm_imposed)) || byDefault();
        const slug = String(spec.llm || '');
        if (slug !== '' && (cat.allowed_llms || []).indexOf(slug) !== -1) {
            return entry(slug, L.llm_chosen) || byDefault();
        }
        return byDefault();
    }

    /**
     * Ce que le schéma montre d'une tâche. Pure. `opts.jump` (formulaire) :
     * intitulé du réglage par cible — sa présence rend le schéma interactif.
     */
    function model(spec, cat, opts) {
        spec = spec || {};
        cat = cat || {};
        const L = cat.labels || {};
        const I = cat.icons || {};
        const trig = spec.trigger || {};
        const active = trig.active !== false;
        const jump = opts && opts.jump && typeof opts.jump === 'object' ? opts.jump : null;

        const profileId = spec.profile_id ? String(spec.profile_id) : '';
        const card = profileId ? pick(cat.assistants, profileId) : null;
        const missing = profileId !== '' && !card;

        let assistant;
        if (card) {
            // Sans avatar : la pastille de sa couleur, à son initiale — comme
            // la liste des assistants et leur propre schéma.
            assistant = {
                kind: 'assistant', kicker: L.assistant, name: card.name,
                avatar: localPath(card.avatar), color: color(card.color), initial: E.initial(card.name),
                description: card.description || '',
            };
        } else if (missing) {
            assistant = { kind: 'missing', kicker: L.assistant, name: L.assistant_missing, avatar: '', color: '', description: '' };
        } else {
            const mia = cat.mia || {};
            assistant = {
                kind: 'mia', kicker: L.no_assistant, name: mia.name || '',
                avatar: localPath(mia.avatar), color: '', description: L.mia_description || '',
            };
        }
        // La consigne, citée dans le nœud de l'assistant : elle mène à son champ.
        assistant.instruction = String(spec.instruction || '').trim();
        assistant.quote = assistant.instruction ? { text: assistant.instruction, target: 'instruction' } : null;
        // Ce que la tâche peut faire : lecture seule, actions à approuver, ou
        // agir sans validation — et, dans ce dernier cas, des emails qui partent.
        const auto = !!(spec.allow_actions && spec.auto_actions);
        // Chaque trait désigne le réglage qui le produit : « Ce que la tâche
        // peut faire » (mode), « Où voir le résultat » (destination, notify).
        assistant.traits = [
            auto
                ? { icon: I.auto, text: L.auto, tone: 'auto', target: 'mode' }
                : spec.allow_actions
                    ? { icon: I.actions, text: L.actions, tone: 'actions', target: 'mode' }
                    : { icon: I.read_only, text: L.read_only, tone: 'read', target: 'mode' },
        ];
        if (auto && spec.auto_send_mail) assistant.traits.push({ icon: I.auto_send, text: L.auto_send, tone: 'auto', target: 'mode' });
        assistant.traits.push(spec.destination === 'new'
            ? { icon: I.new_conv, text: L.dest_new, tone: '', target: 'destination' }
            : { icon: I.thread, text: L.dest_thread, tone: '', target: 'destination' });
        if (spec.notify) assistant.traits.push({ icon: I.notify, text: L.notify, tone: '', target: 'notify' });

        // Connecteurs : UN nœud, une ligne par source, son problème dit.
        const connectors = [];
        (spec.sources || []).forEach((key) => {
            const src = pick(cat.sources, String(key));
            if (src) connectors.push({ label: src.label, icon: src.icon, note: src.issue || '', warn: !!src.issue });
        });

        // Bases : UN nœud — celles de l'assistant (incluses d'office) puis
        // celles de la tâche, sans doublon.
        const kbs = [];
        const seen = {};
        const pushKb = (id, name, live, note) => {
            if (!name || seen[id]) return;
            seen[id] = true;
            kbs.push({ label: name, icon: live ? I.kb_live : I.kb, note: [note, live ? L.live : ''].filter(Boolean).join(' · '), warn: false });
        };
        if (card) (card.kbs || []).forEach((kb) => pushKb(String(kb.id), kb.name, !!kb.live, L.via_assistant));
        (spec.kb_ids || []).forEach((id) => {
            const kb = pick(cat.kbs, String(id));
            if (kb) pushKb(String(id), kb.name, !!kb.live, '');
        });

        // Outils : ceux de la tâche, puis ceux qu'apporte l'assistant — un par
        // type, celui de la tâche passant avant (règle du tour réel). Un outil
        // qui agit apporté par l'assistant n'agit jamais seul : en « Agir sans
        // validation », ses actions attendent quand même (autoExecutable).
        const tools = [];
        const toolTypes = {};
        const toolSeen = {};
        (spec.tool_ids || []).forEach((id) => {
            const tool = pick(cat.tools, String(id));
            if (!tool || toolSeen[id]) return;
            toolSeen[id] = true;
            toolTypes[tool.type] = true;
            tools.push({ label: tool.name, icon: pick(cat.tool_icons, tool.type) || I.tools, note: tool.kind || '', warn: false });
        });
        if (card) {
            (card.tools || []).forEach((tool) => {
                if (toolTypes[tool.type]) return;
                toolTypes[tool.type] = true;
                const held = auto && tool.acting ? L.tool_held : '';
                tools.push({
                    label: tool.name,
                    icon: pick(cat.tool_icons, tool.type) || I.tools,
                    note: [L.via_assistant, tool.kind || '', held].filter(Boolean).join(' · '),
                    warn: false,
                });
            });
        }

        const groups = [];
        if (connectors.length) groups.push({ kind: 'connectors', title: L.connectors, icon: I.connectors, items: connectors });
        if (kbs.length) groups.push({ kind: 'kbs', title: L.kbs, icon: I.kbs, items: kbs });
        if (tools.length) groups.push({ kind: 'tools', title: L.tools, icon: I.tools, items: tools });

        const lines = [];
        if (active && trig.next) lines.push(fill(L.next, { date: trig.next }));
        if (trig.zone) lines.push(trig.zone);

        return {
            aria: L.aria || '',
            name: String(spec.name || '').trim() || L.untitled || '',
            active,
            trigger: {
                kicker: L.trigger,
                icon: I.trigger,
                title: trig.sentence || L.rule_missing || '',
                missing: !trig.sentence,
                status: active ? L.active : L.paused,
                pause: active ? '' : (trig.pause || ''),
                lines,
                volume: trig.per_month || '',
            },
            llm: Object.assign({ kicker: L.model, icon: I.model }, resolveLlm(spec, cat, card, missing)),
            assistant: Object.assign({ icon: I.assistant }, assistant),
            groups,
            empty: groups.length === 0 ? L.no_sources || '' : '',
            emptyTarget: 'sources',
            jump,
            editIcon: I.edit || '',
        };
    }

    const api = {
        model,
        html: E.html,
        /** Pose le schéma d'une tâche — cf. MiaDiagram.mount (animate, jump). */
        mount(host, spec, cat, opts) { E.mount(host, model(spec, cat, opts), opts); },
        draw: E.draw,
        escape: E.escape,
    };
    root.MiaScheduleDiagram = api;
    if (typeof module === 'object' && module && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
