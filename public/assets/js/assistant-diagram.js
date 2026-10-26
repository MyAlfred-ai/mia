/**
 * Schéma d'un assistant — Espaces → Assistants.
 *
 *     ┌── Modèle ──┐        ┌──────────── Assistant ─────────┐
 *     │ température├───────▶│ nom, description, prompt,      │
 *     └────────────┘        │ actif, partage                 │
 *                           └──────▲──────────▲─────────▲────┘
 *              ┌── Bases ──────────┴─┐ ┌── Documents ─┴─┐ ┌┴─ Outils ──┐
 *
 * Ce fichier est le MODÈLE de l'assistant : ce qu'on montre de lui. Le rendu
 * (balisage, flèches, pose dans la page) est celui de diagram.js — le même
 * que le schéma d'une tâche programmée, sans déclencheur : mêmes teintes,
 * mêmes pictogrammes, mêmes flèches. Chargé APRÈS diagram.js.
 *
 * Trois écrans l'appellent : la liste (clic sur un assistant), la création
 * et la modification (mis à jour à chaque geste). Une configuration de la
 * même forme (AssistantDiagram::spec côté PHP, composée depuis les champs
 * côté formulaire) et le catalogue du serveur (AssistantDiagram::catalog) :
 * libellés, icônes de l'application, modèles, bases, outils. Ce fichier n'a
 * AUCUN texte en dur — tout ce qui s'affiche vient du catalogue ou de
 * l'assistant.
 *
 *  - `model(spec, catalog, opts)` — PUR : ce qu'on montre (modèle résolu
 *    comme au chat, bases, documents et outils chacun dans UN nœud) ;
 *  - `html(view)`, `mount(host, spec, catalog, opts)`, `draw(host)` : ceux du
 *    moteur, la vue de l'assistant en entrée.
 *
 * Cibles (`data-target`) : llm, temperature, assistant, prompt, active, kbs,
 * docs, tools, knowledge. Le formulaire passe `opts.jump` — un intitulé par
 * cible — et chaque élément mène à son réglage (cf. diagram.js) ; la liste
 * n'en passe pas.
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
     * Le modèle qui répondra — la règle du chat (resolveProfileDefaultLlm) :
     * celui de l'assistant s'il est actif, sinon le modèle par défaut de
     * l'application, qu'un assistant sans modèle suit aussi dans ses
     * changements.
     */
    function resolveLlm(spec, cat) {
        const L = cat.labels || {};
        const entry = (slug) => {
            const llm = pick(cat.llms || {}, slug);
            return llm ? { label: llm.label, provider: llm.provider || '', logo: localPath(llm.logo), temp: llm.temp !== false } : null;
        };
        const fallback = entry(String(cat.default_llm || ''));
        const none = { label: L.llm_none, provider: '', logo: '', note: '', muted: true, temp: true };
        const slug = String(spec.llm || '');
        if (slug !== '') {
            const chosen = entry(slug);
            if (chosen) return Object.assign(chosen, { note: L.llm_imposed });
            return fallback ? Object.assign(fallback, { note: L.llm_retired, warn: true }) : none;
        }
        return fallback ? Object.assign(fallback, { note: L.llm_default }) : none;
    }

    /**
     * La température envoyée — ou pourquoi aucune ne l'est : un modèle qui
     * n'en accepte pas (la fiche grise le curseur) ignore celle qu'un
     * assistant garderait d'un modèle précédent.
     */
    function temperature(spec, llm, cat) {
        const L = cat.labels || {};
        const raw = spec.temperature;
        const set = raw !== null && raw !== undefined && String(raw).trim() !== '' && Number.isFinite(Number(raw));
        const value = set ? Number(raw).toLocaleString(cat.locale || undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '';
        if (!llm.temp) return set ? fill(L.temp_ignored, { t: value }) : L.temp_unsupported;
        return set ? fill(L.temp_value, { t: value }) : L.temp_default;
    }

    /**
     * Extrait du prompt système, sur une ligne. Les titres de section
     * (« ## Rôle »…) structurent le prompt, ils ne le disent pas : l'extrait
     * montre le texte. Le nœud le coupe à quatre lignes (CSS).
     */
    function promptExcerpt(raw, max) {
        const text = String(raw || '');
        const flat = (s) => s.replace(/\*\*|__|`/g, '').replace(/\s+/g, ' ').trim();
        const body = flat(text.split(/\r?\n/).filter((line) => !/^\s{0,3}#{1,6}(\s|$)/.test(line)).join(' ')) || flat(text);
        return body.length > max ? body.slice(0, max) : body;
    }

    /**
     * État d'un document de référence — la règle de la liste de la fiche. Le
     * document a la forme que la fiche reçoit du serveur
     * (AssistantDiagram::docEntry : file_name, mime_type, token_count,
     * has_text, extraction_status) ; `pending_create` = choisi pour la
     * création, pas encore envoyé.
     */
    function docItem(doc, cat) {
        const L = cat.labels || {};
        const I = cat.icons || {};
        const st = String(doc.extraction_status || 'done');
        const image = String(doc.mime_type || '').indexOf('image/') === 0;
        let note;
        let warn = false;
        if (doc.pending_create) {
            note = L.doc_new;
        } else if (st === 'pending' || st === 'processing') {
            note = L.doc_pending;
        } else if (st === 'failed') {
            note = L.doc_failed;
            warn = true;
        } else if (doc.has_text) {
            note = fill(L.doc_tokens, { n: Number(doc.token_count || 0).toLocaleString(cat.locale || undefined) });
        } else if (image) {
            note = L.doc_image;
        } else {
            note = L.doc_empty;
            warn = true;
        }
        return { label: String(doc.file_name || ''), icon: image ? I.doc_image : I.doc, note, warn };
    }

    /**
     * Ce que le schéma montre d'un assistant. Pure. `opts.jump` (formulaire) :
     * intitulé du réglage par cible — sa présence rend le schéma interactif.
     */
    function model(spec, cat, opts) {
        spec = spec || {};
        cat = cat || {};
        const L = cat.labels || {};
        const I = cat.icons || {};
        const jump = opts && opts.jump && typeof opts.jump === 'object' ? opts.jump : null;
        const active = spec.active !== false;
        const name = String(spec.name || '').trim();
        const max = Number(cat.max_items) > 0 ? Number(cat.max_items) : 5;
        const prompt = promptExcerpt(spec.prompt, Number(cat.prompt_chars) > 0 ? Number(cat.prompt_chars) : 600);

        const llm = resolveLlm(spec, cat);
        // La température, à côté du modèle qui la reçoit — comme sur la fiche.
        llm.params = [{ icon: I.temperature, text: temperature(spec, llm, cat), tone: '', target: 'temperature' }];

        // Qui le voit : la liste des assistants dit la même chose, avec les mêmes mots.
        const SHARING = {
            personal: { icon: I.personal, text: L.personal },
            org:      { icon: I.org, text: L.org },
            global:   { icon: I.global, text: L.global },
        };
        const sharing = pick(SHARING, String(spec.sharing || '')) || SHARING.personal;

        const assistant = {
            kind: 'assistant',
            kicker: L.assistant,
            icon: I.assistant,
            name: name || L.untitled || '',
            missingName: name === '',
            avatar: localPath(spec.avatar),
            color: color(spec.color),
            initial: E.initial(name),
            description: String(spec.description || '').trim(),
            status: { text: active ? L.active : L.inactive, on: active, target: 'active' },
            quote: prompt !== ''
                ? { text: prompt, target: 'prompt' }
                : { text: L.prompt_missing, target: 'prompt', missing: true },
            traits: [{ icon: sharing.icon, text: sharing.text, tone: '', target: '' }],
        };

        // Bases : UN nœud — celles cochées et celles que la fiche ne porte pas
        // (base système, liaison posée hors de votre périmètre), sans doublon,
        // par ordre alphabétique (le même dans la liste et dans la fiche) ;
        // une base hors de votre périmètre, sans son nom, ferme la marche.
        const byName = (a, b) => a.label.localeCompare(b.label, cat.locale || undefined, { sensitivity: 'base' });
        const kbs = [];
        const hidden = [];
        const seen = {};
        (spec.kb_ids || []).concat(spec.fixed_kb_ids || []).forEach((id) => {
            const key = String(id);
            if (seen[key]) return;
            seen[key] = true;
            const kb = pick(cat.kbs, key);
            if (kb) kbs.push({ label: kb.name, icon: kb.live ? I.kb_live : I.kb, note: kb.note || '', warn: !!kb.warn });
            else hidden.push({ label: L.kb_hidden, icon: I.kb, note: L.kb_hidden_note, warn: false });
        });
        kbs.sort(byName);
        kbs.push(...hidden);

        const docs = (spec.docs || []).filter((doc) => doc && doc.file_name).map((doc) => docItem(doc, cat));

        const tools = [];
        (spec.tool_ids || []).forEach((id) => {
            const tool = pick(cat.tools, String(id));
            if (tool) tools.push({ label: tool.name, icon: pick(cat.tool_icons, tool.type) || I.tools, note: tool.note || '', warn: !!tool.warn });
        });
        tools.sort(byName);

        // Au-delà de `max` éléments, le nœud dit combien il en reste : un
        // assistant porte jusqu'à vingt documents.
        const groups = [];
        const group = (kind, title, icon, items) => {
            if (!items.length) return;
            const rest = items.length - max;
            groups.push({
                kind, title, icon,
                items: items.slice(0, max),
                count: items.length,
                more: rest > 0 ? fill(rest === 1 ? L.more_one : L.more_many, { n: rest }) : '',
            });
        };
        group('kbs', L.kbs, I.kbs, kbs);
        group('docs', L.docs, I.docs, docs);
        group('tools', L.tools, I.tools, tools);

        return {
            aria: L.aria || '',
            name: name || L.untitled || '',
            active,
            trigger: null,
            llm: Object.assign({ kicker: L.model, icon: I.model }, llm),
            assistant,
            groups,
            empty: groups.length === 0 ? L.no_sources || '' : '',
            emptyTarget: 'knowledge',
            jump,
            editIcon: I.edit || '',
        };
    }

    const api = {
        model,
        html: E.html,
        /** Pose le schéma d'un assistant — cf. MiaDiagram.mount (animate, jump). */
        mount(host, spec, cat, opts) { E.mount(host, model(spec, cat, opts), opts); },
        draw: E.draw,
        escape: E.escape,
    };
    root.MiaAssistantDiagram = api;
    if (typeof module === 'object' && module && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
