// =========================================================
// SUGGESTION AUTOMATIQUE D'OUTILS ET DE CONNECTEURS
// =========================================================
// Détecte dans le prompt (côté client, mots-clés/regex) deux familles
// d'intentions et propose l'action correspondante via une bannière de
// validation au-dessus du composer. Approuver produit dans les DEUX cas
// exactement l'état qu'un geste manuel aurait produit — et qui, comme lui,
// dure jusqu'à ce que l'utilisateur l'éteigne :
//   - OUTILS (image, page HTML, vidéo, carte, présentation) — approuver arme
//     l'outil dans le menu « + » (window.armToolFromPicker), pastille
//     affichée, choix mémorisé pour la conversation ;
//   - CONNECTEURS (Google Drive, Gmail, Google Agenda) — approuver allume
//     l'interrupteur du menu « + » (préréglage persistant) ; si le modèle
//     courant ne sait pas piloter les connecteurs, la garde de chat.js ouvre
//     la modale de bascule de modèle.
// Le module ne porte donc AUCUN état d'outil : il suggère, chat.js décide et
// mémorise. Un « armement pour le message courant » en parallèle du picker
// donnerait deux sources de vérité qui divergeraient au premier envoi.
// Refuser (« Non merci ») = plus de suggestion de ce type durant la session
// (sessionStorage).
// Le menu « + » garde toujours priorité : un outil engagé désactive les
// suggestions d'outil, un connecteur déjà allumé n'est plus suggéré.
//
// Couplage volontairement minimal avec chat.js : le module lit l'outil engagé
// via window.__getSelectedTool(), l'arme via window.armToolFromPicker() et
// expose window.ToolSuggest — submitForm() appelle clearAfterSend(). Sans ce
// fichier, l'envoi de chat.js fonctionne à l'identique (tout est en optional
// chaining).
(function () {
    'use strict';

    // ---- Normalisation : minuscules, sans accents, apostrophes droites ----
    // Les regex des règles sont écrites SANS accents et matchent le texte
    // normalisé (« génère », « genere » et « GÉNÈRE » sont équivalents).
    function normalizeText(s) {
        return String(s || '')
            .toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[’ʼ]/g, "'");
    }

    // ---- Règles déclaratives ----
    // Une règle : { id    : clé de mémorisation du refus (sessionStorage),
    //               kind  : 'tool' (arme un outil) | 'connector' (allume un
    //                       interrupteur du menu « + »),
    //               types : types d'outils candidats (kind 'tool' uniquement,
    //                       ordre du picker respecté),
    //               row/toggle : ids DOM de la ligne et du switch (kind
    //                       'connector' uniquement),
    //               label : complément affiché dans la bannière,
    //               patterns : RegExp appliquées au texte normalisé }.
    // L'ordre du tableau = priorité (vidéo > html > image : « génère une vidéo
    // d'un chat » ne doit pas matcher image ; « crée une page web avec des
    // images » doit matcher html). Les outils passent AVANT les connecteurs :
    // « génère une image et range-la dans mon Drive » reste une génération
    // d'image.
    //
    // PLUS AUCUN MOTIF EN DUR, ni pour les outils ni pour les connecteurs :
    // toutes les expressions viennent du serveur (window.TOOL_TRIGGERS /
    // window.CONNECTOR_TRIGGERS — sources uniques ToolTriggers et
    // ConnectorRegistry, ÉDITABLES sur /admin/tools et /admin/connectors).
    // Les défauts perpétuent la règle de curation historique : jamais un nom
    // générique seul, il faut un verbe de création, un déterminant marqué ou
    // la marque du service — sinon « analyse cette image » ou « l'agenda de
    // la réunion » déclencheraient à tort.
    // Pour ajouter un type d'outil ou un connecteur : une entrée ici et ses
    // expressions par défaut dans ToolTriggers / ConnectorRegistry.

    // ---- Expressions déclencheuses (servies par le serveur) ----
    // Injectées par le gabarit du chat. Absentes (page sans l'injection) →
    // la règle n'est simplement jamais suggérée, jamais d'erreur.
    const CONNECTOR_TRIGGERS = (window.CONNECTOR_TRIGGERS && typeof window.CONNECTOR_TRIGGERS === 'object')
        ? window.CONNECTOR_TRIGGERS : {};
    const TOOL_TRIGGERS = (window.TOOL_TRIGGERS && typeof window.TOOL_TRIGGERS === 'object')
        ? window.TOOL_TRIGGERS : {};

    // Une expression → une regex à MOTS ENTIERS sur le texte normalisé.
    // \\b ne convient pas aux bornes accentuées/apostrophes une fois le texte
    // normalisé : on encadre par « début ou non-lettre », ce qui évite qu'une
    // expression courte matche à l'intérieur d'un mot (« n8n » dans « xn8ny »).
    function triggerToRegex(phrase) {
        const norm = normalizeText(phrase).trim();
        if (norm.length < 2) return null;
        const esc = norm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+');
        try { return new RegExp('(?:^|[^a-z0-9])' + esc + '(?![a-z0-9])'); }
        catch (_) { return null; }
    }

    function connectorRule(id, row, toggle, label, action) {
        const patterns = (CONNECTOR_TRIGGERS[id] || []).map(triggerToRegex).filter(Boolean);
        return { id, kind: 'connector', row, toggle, label, action, patterns };
    }

    // Une expression d'OUTIL décrit une demande de génération, formulée
    // librement : ses mots doivent apparaître DANS L'ORDRE mais peuvent être
    // séparés par d'autres mots de la même clause — « génère une image »
    // matche « génère-moi vite une image de chat ». La ponctuation forte
    // ([.!?\n]) borne la portée : deux phrases distinctes ne se combinent pas.
    function toolTriggerToRegex(phrase) {
        const norm = normalizeText(phrase).trim();
        if (norm.length < 2) return null;
        const words = norm.split(/\s+/).map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        const body  = words
            .map(w => '(?:^|[^a-z0-9])' + w + '(?![a-z0-9])')
            .join('[^.!?\\n]{0,50}?');
        try { return new RegExp(body); }
        catch (_) { return null; }
    }

    function toolRule(id, types, label) {
        const patterns = (TOOL_TRIGGERS[id] || []).map(toolTriggerToRegex).filter(Boolean);
        return { id, kind: 'tool', types, label, patterns };
    }

    const SUGGEST_RULES = [
        // Outils — ordre = priorité : vidéo d'abord (« génère une vidéo d'un
        // chat » ne doit pas matcher image), carte avant image (« dessine-moi
        // un itinéraire » vise la carte). Pas de règle « présentation » : une
        // présentation se demande dans la conversation (carte PowerPoint).
        toolRule('video',        ['video'],                    t('une vidéo')),
        toolRule('html',         ['html', 'html_illustrated'], t('une page HTML')),
        toolRule('map',          ['map'],                      t('un itinéraire sur carte')),
        toolRule('image',        ['image'],                    t('une image')),
        // Connecteurs — ordre = priorité. Drive avant Gmail/Agenda (« le doc
        // joint à mon mail » est d'abord une lecture de document), et web EN
        // DERNIER (« cherche dans mon Drive » vise le Drive, pas le web ; la
        // règle ne se déclenche de toute façon que si l'interrupteur Internet
        // est ÉTEINT, cf. pickConnectorFor). Les motifs sont compilés depuis
        // les expressions servies par le serveur.
        connectorRule('drive',    'plusMenuDriveRow',    'driveAccessToggle', t('vos fichiers Google Drive'), t('Activer Google Drive')),
        connectorRule('gmail',    'plusMenuGmailRow',    'gmailAccessToggle', t('vos emails Gmail'),          t('Activer Gmail')),
        connectorRule('calendar', 'plusMenuCalendarRow', 'calendarAccessToggle', t('votre agenda Google'),    'Activer l\u2019agenda'),
        // T\u00e2ches apr\u00e8s l'agenda : \u00ab planifie une r\u00e9union \u00bb vise l'agenda, pas
        // une t\u00e2che.
        connectorRule('tasks',    'plusMenuTasksRow',    'tasksAccessToggle',  t('vos t\u00e2ches Google'),   t('Activer les t\u00e2ches')),
        // Outlook APR\u00c8S Gmail : les deux partagent les possessifs g\u00e9n\u00e9riques
        // (\u00ab mes mails \u00bb), et `evaluate()` retient la premi\u00e8re r\u00e8gle dont la
        // cible est r\u00e9ellement disponible \u2014 une organisation qui n'a qu'Outlook
        // re\u00e7oit donc la bonne suggestion sans que l'ordre ait \u00e0 changer.
        connectorRule('outlook',  'plusMenuOutlookRow',  'outlookAccessToggle', t('vos emails Outlook'),      t('Activer Outlook')),
        // Calendrier Outlook APRÈS l'agenda Google, même raison : « mon agenda »
        // ne dit pas quel fournisseur, et `evaluate()` retient la première
        // règle dont la cible est réellement disponible.
        connectorRule('mscalendar', 'plusMenuMsCalendarRow', 'mscalendarAccessToggle', t('votre agenda Outlook'), t('Activer l’agenda Outlook')),
        // Microsoft To Do APRÈS Google Tasks, même raison : « mes tâches » ne
        // dit pas quel fournisseur.
        connectorRule('mstodo',   'plusMenuMsTodoRow',   'mstodoAccessToggle', t('vos tâches Microsoft To Do'), t('Activer Microsoft To Do')),
        // Slack : ses expressions portent toutes la marque ou un possessif
        // propre (« mes canaux »), il n'entre donc en concurrence avec aucune
        // autre règle — sa place dans l'ordre n'a pas d'enjeu.
        connectorRule('slack',    'plusMenuSlackRow',    'slackAccessToggle', t('vos conversations Slack'), t('Activer Slack')),
        // Teams : expressions toutes à la marque (« sur teams », « microsoft
        // teams »), aucune concurrence avec Slack.
        connectorRule('teams',    'plusMenuTeamsRow',    'teamsAccessToggle', t('vos conversations Teams'), t('Activer Teams')),
        connectorRule('web',      'plusMenuWebRow',      'webAccessToggle',   'Internet',                  t('Activer Internet')),
    ];

    // ---- Détection pure : règles matchantes, par ordre de priorité ----
    // Exposées telles quelles dans l'API publique (testables en console).
    function detectIntents(text) {
        const norm = normalizeText(text).trim();
        if (!norm) return [];
        return SUGGEST_RULES
            .filter(rule => rule.patterns.some(re => re.test(norm)))
            .map(rule => ({ ruleId: rule.id, kind: rule.kind, types: rule.types, label: rule.label, rule }));
    }
    /** Intention la plus prioritaire, ou null. Conservée pour compat. */
    function detectToolIntent(text) {
        return detectIntents(text)[0] || null;
    }

    // ---- Mémoire de session (refus par type d'outil) ----
    // try/catch systématique : sessionStorage peut être indisponible
    // (navigation privée) → dégradation douce, suggestions sans mémoire.
    const STORAGE_KEY = 'toolSuggestDismissed';
    function readDismissed() {
        try { return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}') || {}; }
        catch (_) { return {}; }
    }
    function isDismissed(ruleId) {
        return readDismissed()[ruleId] === true;
    }
    function dismiss(ruleId) {
        try {
            const d = readDismissed();
            d[ruleId] = true;
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(d));
        } catch (_) { /* pas de mémoire, tant pis */ }
    }

    // ---- Éléments du composer ----
    // Les outils vivent dans le panneau du menu « + » (section Outils),
    // items .tool-picker-item avec data-id/type/name/disabled.
    const input      = document.getElementById('msgInput');
    const form       = document.getElementById('chatForm');
    const inputBox   = document.getElementById('inputBox');
    const menuPanel  = document.getElementById('plusMenuPanel');
    const fileChips  = document.getElementById('fileChips');

    // Sans composer ou sans menu « + », le module reste inerte mais expose
    // quand même son API pour que chat.js puisse l'appeler sans garde.
    // L'absence d'outils ne rend PAS le module inerte : les connecteurs
    // vivent dans le même menu et se suggèrent indépendamment.
    const inert = !input || !form || !inputBox || !menuPanel;

    // ---- Choix de l'outil suggéré pour un ensemble de types ----
    // Premier item non désactivé du menu (ordre DOM = ordre du menu). Seul le
    // DOM porte l'état des tests (data-disabled) : window.IMAGE_TOOLS n'expose
    // pas last_test_ok. On ne retient qu'id/type/name — de quoi nommer l'outil
    // dans la bannière et le désigner à window.armToolFromPicker() ; c'est
    // chat.js qui construit l'outil complet (provider/modèle d'un outil vidéo)
    // depuis ce même item, sans second assemblage à tenir à jour ici.
    function pickToolFor(types) {
        if (inert) return null;
        for (const item of menuPanel.querySelectorAll('.tool-picker-item')) {
            if (!item.dataset.id || item.dataset.disabled === '1') continue;
            if (!types.includes(item.dataset.type)) continue;
            return { id: item.dataset.id, type: item.dataset.type, name: item.dataset.name };
        }
        return null;
    }

    // ---- Cible d'une règle connecteur ----
    // La ligne n'existe dans le DOM que si l'administrateur a configuré la
    // source ; elle porte data-disabled="1" tant que le compte n'est pas
    // connecté. Déjà allumé → plus rien à suggérer. La compatibilité du
    // modèle n'est PAS testée ici : c'est la garde de chat.js qui ouvre la
    // modale de bascule au moment de l'activation.
    function pickConnectorFor(rule) {
        if (inert) return null;
        const row    = document.getElementById(rule.row);
        const toggle = document.getElementById(rule.toggle);
        if (!row || !toggle) return null;
        if (row.dataset.disabled === '1' || toggle.disabled) return null;
        if (toggle.checked) return null;
        return { row, toggle, name: row.dataset.label || rule.label };
    }

    // ---- Gardes : la suggestion est-elle pertinente maintenant ? ----
    function canSuggest(rule) {
        if (inert) return false;
        // « Non merci » déjà cliqué pour cette règle : plus rien de la session.
        if (isDismissed(rule.id)) return false;
        // Fichiers joints : flux d'édition/analyse de fichier, pas de suggestion
        // (vaut aussi pour les connecteurs — « résume mon document » avec une
        // pièce jointe parle de la pièce jointe).
        if (fileChips && fileChips.style.display !== 'none' && fileChips.children.length > 0) return false;
        if (rule.kind === 'connector') return true;
        // Outil déjà forcé au menu « + » → le choix explicite prime.
        if (typeof window.__getSelectedTool === 'function' && window.__getSelectedTool()) return false;
        // L'assistant du chat a un outil image lié : le LLM déclenche déjà la
        // génération lui-même (function-calling côté send) → pas de doublon.
        if (rule.id === 'image' && (window.PROFILE_TOOL_TYPES || []).includes('image')) return false;
        return true;
    }

    // ---- Bannière (créée en JS, insérée au-dessus de la zone de saisie) ----
    // Machine à 2 états : hidden | suggest (question). Approuver ne laisse
    // rien derrière dans ce module : l'outil part dans le picker (pastille
    // amovible), le connecteur dans son interrupteur. PAS d'envoi automatique
    // — l'utilisateur peut encore compléter son texte.
    let state = 'hidden';           // 'hidden' | 'suggest'
    let current = null;             // { rule, tool } ou { rule, target } quand state !== 'hidden'

    let banner = null, bannerText = null, btnAccept = null, btnDecline = null;
    function ensureBanner() {
        if (banner || inert) return;
        banner = document.createElement('div');
        banner.className = 'tool-suggest-banner';
        banner.id = 'toolSuggestBanner';
        banner.hidden = true;
        banner.setAttribute('role', 'status');
        banner.innerHTML =
            '<svg class="tool-suggest-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>' +
            '<span class="tool-suggest-text"></span>' +
            '<span class="tool-suggest-actions">' +
                '<button type="button" class="tool-suggest-accept"></button>' +
                '<button type="button" class="tool-suggest-decline"></button>' +
            '</span>';
        bannerText = banner.querySelector('.tool-suggest-text');
        btnAccept  = banner.querySelector('.tool-suggest-accept');
        btnDecline = banner.querySelector('.tool-suggest-decline');

        btnAccept.addEventListener('click', () => {
            if (state !== 'suggest') return;
            if (current.rule.kind === 'connector') {
                // Allumage réel : c'est le `change` du switch qui déclenche la
                // logique de chat.js (persistance du préréglage, point ambre,
                // et modale de bascule si le modèle ne gère pas les outils).
                const bascule = current.target.toggle;
                if (!bascule.checked) {
                    bascule.checked = true;
                    bascule.dispatchEvent(new Event('change'));
                }
            } else {
                // Engagement réel de l'outil dans le menu « + » : pastille
                // affichée, mémorisé pour la conversation, éteint par
                // l'utilisateur seul — comme s'il l'avait cliqué lui-même.
                window.armToolFromPicker?.([current.tool.type], current.tool.id);
            }
            setState('hidden');
            input.focus();
        });
        btnDecline.addEventListener('click', () => {
            // « Non merci » = refus pour toute la session (par type de règle).
            if (state === 'suggest') dismiss(current.rule.id);
            setState('hidden');
            input.focus();
        });

        form.insertBefore(banner, inputBox);
    }

    function setState(next, payload) {
        state = next;
        current = next === 'hidden' ? null : (payload || current);
        if (!banner) return;
        if (state === 'hidden') {
            banner.hidden = true;
            return;
        }
        btnDecline.textContent = t('Non merci');
        if (current.rule.kind === 'connector') {
            bannerText.textContent = t('Autoriser l’assistant à consulter ') + current.rule.label + ' ?';
            btnAccept.textContent = current.rule.action;
        } else {
            const name = current.tool.name;
            bannerText.textContent = t('Générer ') + current.rule.label + ' avec « ' + name + ' » ?';
            btnAccept.textContent = 'Utiliser ' + name;
        }
        banner.hidden = false;
    }

    // ---- Évaluation du texte courant ----
    function evaluate() {
        if (inert) return;
        const text = input.value.trim();
        if (!text) {
            // Texte vidé : on repart de zéro — c'est un nouveau message.
            if (state !== 'hidden') setState('hidden');
            return;
        }

        // Première règle exploitable : une intention détectée dont la cible
        // n'est pas disponible (outil non branché, connecteur non connecté ou
        // déjà allumé) ne doit pas masquer la suivante — « prépare une réponse
        // à mon dernier mail dans un google doc » reste utile même si Gmail
        // n'est pas connecté.
        for (const intent of detectIntents(text)) {
            if (!canSuggest(intent.rule)) continue;
            if (intent.rule.kind === 'connector') {
                const target = pickConnectorFor(intent.rule);
                if (!target) continue;
                ensureBanner();
                setState('suggest', { rule: intent.rule, target });
                return;
            }
            const tool = pickToolFor(intent.types);
            if (!tool) continue;
            ensureBanner();
            setState('suggest', { rule: intent.rule, tool });
            return;
        }
        if (state === 'suggest') setState('hidden');
        return;
    }

    // ---- Écoutes ----
    if (!inert) {
        // Frappe débouncée (~350 ms), indépendante de l'auto-resize de chat.js.
        let debounceTimer = null;
        input.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(evaluate, 350);
        });

        // Un outil forcé (ou libéré) au menu « + » — ou retiré via la croix de
        // la pastille — invalide la suggestion en cours. setTimeout(0) : laisse
        // le handler de chat.js poser/effacer selectedTool avant.
        const onToolSelectionChange = () => setTimeout(() => {
            if (typeof window.__getSelectedTool === 'function' && window.__getSelectedTool()) {
                setState('hidden');
            } else {
                evaluate();
            }
        }, 0);
        // `.plus-menu-row` aussi : allumer soi-même un connecteur doit retirer
        // la suggestion qui le proposait (et en révéler une autre s'il y en a).
        menuPanel.addEventListener('click', e => {
            if (e.target.closest('.tool-picker-item, .plus-menu-row')) onToolSelectionChange();
        });
        // Sélection clavier (Entrée/Espace sur un item, cf. bindActivate de chat.js).
        menuPanel.addEventListener('keydown', e => {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('.tool-picker-item')) onToolSelectionChange();
        });
        document.getElementById('toolChipRemove')?.addEventListener('click', onToolSelectionChange);

        // Brouillon restauré au chargement (composerDraft) : évaluation initiale.
        if (input.value.trim()) evaluate();
    }

    // ---- API publique consommée par chat.js ----
    window.ToolSuggest = {
        // Détection pure, exposée pour test manuel en console.
        detectToolIntent,
        detectIntents,
        // Appelé par submitForm() après dispatch : la bannière se referme.
        // Un envoi sans approbation = refus implicite du message courant
        // uniquement (AUCUNE mémorisation session). Le module ne détient aucun
        // outil : ce que l'utilisateur a approuvé vit dans le menu « + ».
        clearAfterSend() {
            setState('hidden');
        },
    };
})();
