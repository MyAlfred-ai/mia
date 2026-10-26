/**
 * Schéma d'une base de connaissances — Espaces → Bases de connaissances.
 *
 *          ┌── Fichiers déposés ──┐   ┌──── Google Drive ────┐   ce qui l'alimente
 *          └───────────┬──────────┘   └───────────┬──────────┘
 *  ┌ Vectorisation ┐  ┌▼────────────── Base ──────▼──────────┐
 *  │ modèle, dims, ├─▶│ nom, description, état, documents,   │
 *  │ extraits      │  │ utilisations                         │
 *  └───────────────┘  └───────┬──────────────────────┬───────┘
 *          ┌── Assistants liés ▼──┐   ┌──── Partage ─▼───────┐   ce qu'elle sert
 *
 * Ce fichier est le MODÈLE d'une base : ce qu'on montre d'elle. Le rendu
 * (balisage, flèches, pose dans la page) est celui de diagram.js — le même
 * que le schéma d'un assistant et d'une tâche programmée : mêmes teintes,
 * mêmes pictogrammes, mêmes flèches. Les sources ARRIVENT dans la base ; la
 * base PART vers les assistants qui l'interrogent et les personnes qui la
 * voient (`flow: 'out'`). Chargé APRÈS diagram.js.
 *
 * Trois écrans l'appellent : la liste (clic sur une base), la création et la
 * modification (mis à jour à chaque geste). Une configuration de la même
 * forme (KbDiagram::spec côté PHP, composée depuis les champs côté fiche) et
 * le catalogue du serveur (KbDiagram::catalog) : libellés, icônes, sources,
 * modèles d'embedding, assistants, personnes, groupes. Ce fichier n'a AUCUN
 * texte en dur — tout ce qui s'affiche vient du catalogue ou de la base.
 *
 *  - `model(spec, catalog, opts)` — PUR : ce qu'on montre ;
 *  - `html(view)`, `mount(host, spec, catalog, opts)`, `draw(host)` : ceux du
 *    moteur, la vue de la base en entrée.
 *
 * Cibles (`data-target`) — le genre de chaque nœud, plus ses pastilles :
 * docs (fichiers déposés, ou aucune source encore), connectors (dossier
 * relié), live (cerveau), llm (vectorisation), embedding et chunks (ses
 * pastilles), assistant (la base), active, state, assistants, sharing. La
 * fiche passe `opts.jump` — un intitulé par cible — et chaque élément mène à
 * son réglage (cf. diagram.js) ; la liste n'en passe pas.
 *
 * Sous node (tests), seules les parties pures servent ; rien ne touche au DOM
 * avant un appel à mount().
 */
(function (root) {
    'use strict';

    const E = root.MiaDiagram;
    if (!E) return;
    const { fill, color, localPath, pick } = E;

    const TONES = { ok: true, busy: true, warn: true, error: true };
    const WARN_TONES = { warn: true, error: true };

    function maxItems(cat) {
        return Number(cat.max_items) > 0 ? Number(cat.max_items) : 5;
    }

    function number(cat, n) {
        return Number(n || 0).toLocaleString(cat.locale || undefined);
    }

    /** « Aucun document », « 1 document », « 12 documents » — clés `<base>_none|_one|_many`. */
    function plural(cat, n, key) {
        const L = cat.labels || {};
        if (n === 0 && L[key + '_none'] !== undefined) return L[key + '_none'];
        return fill(n === 1 ? L[key + '_one'] : L[key + '_many'], { n: number(cat, n) });
    }

    function byName(cat) {
        return (a, b) => a.label.localeCompare(b.label, cat.locale || undefined, { sensitivity: 'base' });
    }

    /** Un nœud de groupe : `max` éléments montrés, le reste dit ; le nombre compte TOUT. */
    function groupOf(cat, base, items, total) {
        const L = cat.labels || {};
        const max = maxItems(cat);
        const rest = items.length - max;
        return Object.assign({
            items: items.slice(0, max),
            count: total !== undefined ? total : items.length,
            more: rest > 0 ? fill(rest === 1 ? L.more_one : L.more_many, { n: rest }) : '',
        }, base);
    }

    /**
     * Ce qui ALIMENTE la base, au-dessus : son dossier synchronisé et ses
     * fichiers déposés — ou, pour une base en direct, le cerveau MyBrain.
     */
    function inputs(spec, cat) {
        const L = cat.labels || {};
        const I = cat.icons || {};
        if (spec.live) {
            const remote = spec.remote || {};
            const src = pick(cat.sources, 'mybrain') || {};
            const host = String(remote.host || '');
            return [{
                kind: 'live', title: L.live_title, icon: src.icon || I.search,
                status: { text: L.live_status, on: true },
                headline: host || L.live_missing, missing: host === '',
                sub: remote.app ? fill(L.live_app, { app: remote.app }) : L.live_hint,
            }];
        }

        const out = [];
        const source = spec.source || {};
        if (source.provider) {
            const src = pick(cat.sources, String(source.provider)) || { label: String(source.provider), icon: I.upload };
            const calendar = source.provider === 'gcalendar';
            const folder = String(source.folder || '').trim();
            let headline;
            if (folder) headline = fill(calendar ? L.calendar : L.folder, { name: folder });
            else if (source.linked) headline = calendar ? L.calendar_linked : L.folder_linked;
            else headline = calendar ? L.calendar_missing : L.folder_missing;
            out.push({
                kind: 'connectors', title: src.label, icon: src.icon,
                status: { text: source.auto ? L.sync_auto : L.sync_manual, on: !!source.auto, muted: !source.auto },
                headline, missing: !source.linked,
                sub: source.auto ? fill(L.sync_every, { n: cat.interval || 10 }) : L.sync_off,
                count: Number(spec.synced_total) || 0,
                note: source.owner ? fill(L.via_owner, { name: source.owner }) : '',
                items: [],
            });
        }

        const files = (spec.files || []).filter((f) => f && f.name);
        if (files.length) {
            out.push(groupOf(cat, { kind: 'docs', title: L.files, icon: I.upload },
                files.map((f) => ({
                    label: String(f.name),
                    icon: f.image ? I.doc_image : I.doc,
                    note: f.label || '',
                    warn: !!WARN_TONES[f.tone],
                }))));
        }

        if (!out.length) {
            out.push({ kind: 'docs', title: L.sources, icon: I.upload, missing: true, empty: L.sources_empty, items: [] });
        }
        return out;
    }

    /**
     * Comment la base est cherchée, à gauche — la place du modèle dans les
     * autres schémas : le modèle d'embedding (et ses dimensions, ses
     * extraits), ou « en direct » pour un cerveau MyBrain.
     */
    function search(spec, cat) {
        const L = cat.labels || {};
        const I = cat.icons || {};
        if (spec.live) {
            const src = pick(cat.sources, 'mybrain') || {};
            return {
                kicker: L.search, icon: I.search,
                label: L.live, provider: L.live_provider, logo: localPath(src.logo), note: L.live_note,
            };
        }
        const emb = pick(cat.embeddings, String(spec.embedding || ''));
        if (!emb) {
            return { kicker: L.vector, icon: I.vector, label: L.vector_none, provider: '', logo: '', note: '', muted: true };
        }
        const params = [{ icon: I.trait_dims, text: fill(L.dims, { n: number(cat, emb.dims) }), tone: '', target: 'embedding' }];
        if (!spec.creating) params.push({ icon: I.trait_chunks, text: plural(cat, Number(spec.chunks) || 0, 'chunks'), tone: '', target: 'chunks' });
        const down = cat.qdrant_up === false;
        return {
            kicker: L.vector, icon: I.vector,
            label: emb.label, provider: emb.model || '', logo: localPath(emb.logo),
            note: down ? L.qdrant_down : (spec.creating ? L.vector_create : L.vector_note),
            warn: down,
            params,
        };
    }

    /** La base, au centre : nom, description, active ou non, état et chiffres. */
    function base(spec, cat) {
        const L = cat.labels || {};
        const I = cat.icons || {};
        const name = String(spec.name || '').trim();
        const active = spec.active !== false;
        const traits = [];

        const state = spec.state || null;
        if (state && state.title) {
            const tone = TONES[state.tone] ? state.tone : '';
            traits.push({ icon: I['state_' + (tone || 'idle')], text: state.title, tone, target: 'state' });
        }
        if (!spec.creating) {
            // Une base vide le dit déjà par son état : pas de « Aucun document » en double.
            const docs = (spec.files || []).length + (Number(spec.synced_total) || 0);
            if (!spec.live && docs > 0) {
                traits.push({ icon: I.trait_docs, text: plural(cat, docs, 'docs'), tone: '', target: '' });
            }
            traits.push({ icon: I.trait_usage, text: plural(cat, Number(spec.usage) || 0, 'usage'), tone: '', target: '' });
        }
        if (spec.system) traits.push({ icon: I.trait_system, text: L.system, tone: '', target: '' });
        const access = spec.access || {};
        if (access.shared && !access.owned) {
            traits.push({ icon: I.trait_shared, text: access.can_manage ? L.shared_manage : L.shared_read, tone: '', target: '' });
        }

        return {
            kind: 'kb', kicker: L.base, icon: I.base,
            name: name || L.untitled || '', missingName: name === '',
            avatar: '', glyph: I.glyph, color: cat.hue || '',
            description: String(spec.description || '').trim(),
            status: { text: active ? L.active : L.inactive, on: active, target: 'active' },
            traits,
        };
    }

    /** Les assistants qui interrogent la base — avatar ou pastille, qui les voit. */
    function assistants(spec, cat) {
        const L = cat.labels || {};
        const I = cat.icons || {};
        const SHARING = { personal: L.personal, org: L.org, global: L.global };
        const items = [];
        const seen = {};
        let hidden = 0;
        (spec.assistant_ids || []).forEach((id) => {
            const key = String(id);
            if (seen[key]) return;
            seen[key] = true;
            const a = pick(cat.assistants, key);
            if (!a) {
                hidden++;
                return;
            }
            const avatar = localPath(a.avatar);
            items.push({
                label: String(a.name || ''),
                avatar,
                dot: avatar ? '' : (color(a.color) || '#64748b'),
                initial: E.initial(a.name),
                note: a.active === false ? L.assistant_off : (SHARING[a.sharing] || ''),
                warn: a.active === false,
            });
        });
        items.sort(byName(cat));
        const total = items.length + hidden;
        if (hidden) {
            items.push({ label: fill(hidden === 1 ? L.hidden_one : L.hidden_many, { n: hidden }), icon: I.assistant, note: L.hidden_note, warn: false });
        }
        return groupOf(cat, {
            kind: 'assistants', title: L.assistants, icon: I.assistants, flow: 'out',
            empty: total === 0 ? L.assistants_none : '',
        }, items, total);
    }

    /**
     * Qui voit la base. Le propriétaire (et un administrateur) voit la liste
     * du partage ; un bénéficiaire ne voit que son propre accès et le
     * propriétaire. Une base en direct dit « interroge le cerveau », et ce
     * que chaque partage permet de plus.
     */
    function sharing(spec, cat) {
        const L = cat.labels || {};
        const I = cat.icons || {};
        const access = spec.access || {};
        const items = [];
        let status;

        if (spec.system) {
            items.push({ label: L.everyone, icon: I.everyone, note: L.everyone_note });
            status = { text: L.shared, on: true };
        } else if (access.owned) {
            const live = !!spec.live;
            const noteOf = (share) => (live
                ? [L.live_query].concat(share.default ? [L.live_default] : [], share.feed ? [L.live_feed] : []).join(' · ')
                : (share.manage ? L.can_manage : L.can_read));
            const groups = (access.groups || []).map((g) => {
                const meta = pick(cat.groups, String(g.id));
                const off = !!meta && meta.active === false;
                return {
                    label: meta && meta.name ? meta.name : fill(L.group_unknown, { id: g.id }),
                    icon: I.group, note: off ? L.group_off : noteOf(g), warn: off,
                };
            }).sort(byName(cat));
            const people = (access.users || []).map((u) => ({
                label: pick(cat.users, String(u.id)) || fill(L.user_unknown, { id: u.id }),
                icon: I.person, note: noteOf(u), warn: false,
            })).sort(byName(cat));

            items.push({ label: access.you ? L.you : (access.creator || L.creator_unknown), icon: I.person, note: L.owner });
            items.push(...groups, ...people);
            items.push({ label: L.admins, icon: I.admins, note: L.admins_note });
            status = groups.length + people.length > 0 ? { text: L.shared, on: true } : { text: L.private, on: false, muted: true };
        } else {
            items.push({ label: L.you, icon: I.person, note: access.can_manage ? L.shared_you_manage : L.shared_you_read });
            items.push({ label: access.creator || L.creator_unknown, icon: I.person, note: L.owner });
            status = { text: L.shared, on: true };
        }

        return groupOf(cat, { kind: 'sharing', title: L.sharing, icon: I.sharing, flow: 'out', status }, items);
    }

    /**
     * Ce que le schéma montre d'une base. Pure. `opts.jump` (fiche) :
     * intitulé du réglage par cible — sa présence rend le schéma interactif.
     */
    function model(spec, cat, opts) {
        spec = spec || {};
        cat = cat || {};
        const L = cat.labels || {};
        const I = cat.icons || {};
        const jump = opts && opts.jump && typeof opts.jump === 'object' ? opts.jump : null;
        const name = String(spec.name || '').trim();
        return {
            aria: L.aria || '',
            name: name || L.untitled || '',
            active: spec.active !== false,
            trigger: null,
            inputs: inputs(spec, cat),
            llm: search(spec, cat),
            assistant: base(spec, cat),
            groups: [assistants(spec, cat), sharing(spec, cat)],
            empty: '',
            jump,
            editIcon: I.edit || '',
        };
    }

    const api = {
        model,
        html: E.html,
        /** Pose le schéma d'une base — cf. MiaDiagram.mount (animate, jump). */
        mount(host, spec, cat, opts) { E.mount(host, model(spec, cat, opts), opts); },
        draw: E.draw,
        escape: E.escape,
    };
    root.MiaKbDiagram = api;
    if (typeof module === 'object' && module && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
