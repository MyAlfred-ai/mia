/**
 * Espaces → Tâches programmées : le formulaire (fiche de l'assistant,
 * bases, outils, préréglages, jours, aperçu en direct, ESSAI sur place) et la
 * liste (interrupteur Active / En pause, « Exécuter maintenant », suppression).
 *
 * Aucun calcul de date ici : la phrase « Chaque lundi à 8 h 00 » et les
 * prochaines exécutions viennent du serveur (POST /settings/schedules/preview,
 * Recurrence côté PHP). Une seconde implémentation en JavaScript finirait par
 * annoncer d'autres dates que celles qui serviront.
 *
 * Tout reste utilisable sans JavaScript : chaque geste est un vrai formulaire,
 * ce script ne fait que l'accélérer.
 *
 * Le SCHÉMA d'une tâche (à droite de la liste au clic, à droite du formulaire
 * pendant la saisie) est dessiné par schedule-diagram.js, chargé avant ce
 * fichier ; ce script ne fait que lui passer la configuration de la tâche —
 * et, dans le formulaire, mène du schéma au réglage : un clic sur un nœud
 * fait venir à l'écran la section qui le règle (cf. « Du schéma au réglage »).
 */
(function () {
    'use strict';

    // `t()` : traducteur global posé par head-meta.php (window.I18N).
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function browserTimeZone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (_e) {
            return '';
        }
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
            body,
        });
        const data = await res.json().catch(() => ({ ok: false }));
        if (!data || data.ok !== true) {
            throw new Error(data && data.error ? data.error : t('Échec — réessayez dans un instant.'));
        }
        return data;
    }

    // =====================================================================
    // Formulaire
    // =====================================================================
    const form = document.getElementById('schedForm');
    if (form) {
        const tzInput      = document.getElementById('schedTz');
        const profileSel   = document.getElementById('schedProfile');
        const llmRow       = document.getElementById('schedLlmRow');
        const daysBox      = document.getElementById('schedDays');
        const monthDayBox  = document.getElementById('schedMonthDay');
        const timeInput    = document.getElementById('schedTime');
        const sentenceEl   = document.getElementById('schedSentence');
        const nextEl       = document.getElementById('schedNext');
        const metaEl       = document.getElementById('schedMeta');
        const whenError    = document.getElementById('schedWhenError');
        const instruction  = document.getElementById('schedInstruction');
        const instrError   = document.getElementById('schedInstructionError');
        const dayInputs    = Array.from(form.querySelectorAll('input[name="days[]"]'));
        const presetInputs = Array.from(form.querySelectorAll('input[name="freq"]'));
        const PRESET_DAYS  = { daily: [1, 2, 3, 4, 5, 6, 7], weekdays: [1, 2, 3, 4, 5] };

        // Schéma de la tâche : son déclencheur suit chaque aperçu du serveur
        // (phrase, prochaine exécution, fuseau) ; le reste est lu dans les champs.
        const diagramConf = (window.SCHED_FORM && window.SCHED_FORM.diagram) || null;
        let diagramTrigger = Object.assign({}, diagramConf ? diagramConf.trigger : {});
        let renderDiagram = () => {};

        // Fuseau : celui du navigateur pour une nouvelle tâche ; une tâche
        // existante garde le sien (créée à Paris, modifiée en voyage, elle
        // reste à l'heure de Paris).
        if (tzInput && tzInput.value === '') tzInput.value = browserTimeZone();

        // Venue de l'horloge de l'assistant (zone de saisie du chat) : le texte
        // qu'on était en train d'écrire devient la consigne. Il a attendu dans
        // le stockage de session de l'onglet (jamais dans l'adresse), il est
        // repris UNE fois, et seulement dans un champ vide.
        if (form.dataset.compose === '1' && instruction) {
            try {
                const raw = sessionStorage.getItem('mia.scheduleDraft');
                sessionStorage.removeItem('mia.scheduleDraft');
                const draft = raw ? JSON.parse(raw) : null;
                const fresh = draft && typeof draft.text === 'string' && Date.now() - Number(draft.at || 0) < 10 * 60 * 1000;
                if (fresh && instruction.value.trim() === '') instruction.value = draft.text;
            } catch (_e) {
                // Stockage indisponible : le formulaire reste vierge.
            }
        }

        const currentPreset = () => presetInputs.find((i) => i.checked)?.value || 'weekly';

        function showError(el, message) {
            if (!el) return;
            el.textContent = message || '';
            el.hidden = !message;
        }

        // Un assistant impose son modèle : le choix du modèle disparaît.
        function syncLlmRow() {
            if (llmRow && profileSel) llmRow.hidden = profileSel.value !== '';
        }

        // Les jours cochés montrent ce que couvre le préréglage.
        function syncPreset() {
            const preset = currentPreset();
            const monthly = preset === 'monthly';
            if (daysBox) daysBox.hidden = monthly;
            if (monthDayBox) monthDayBox.hidden = !monthly;
            if (PRESET_DAYS[preset]) {
                dayInputs.forEach((i) => { i.checked = PRESET_DAYS[preset].includes(Number(i.value)); });
            } else if (preset === 'weekly' && !dayInputs.some((i) => i.checked)) {
                dayInputs[0].checked = true; // lundi : une semaine vide n'a pas de sens
            }
        }

        // Toucher un jour, c'est choisir ses jours : on passe en « Chaque semaine ».
        function onDayChange() {
            const preset = currentPreset();
            if (preset === 'daily' || preset === 'weekdays') {
                const weekly = presetInputs.find((i) => i.value === 'weekly');
                if (weekly) weekly.checked = true;
            }
            schedulePreview();
        }

        // ---- Aperçu : phrase + prochaines dates, calculées par le serveur ----
        let previewTimer = null;
        let previewSeq = 0;
        function schedulePreview() {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(runPreview, 220);
        }
        async function runPreview() {
            const seq = ++previewSeq;
            const body = new FormData();
            body.append('freq', currentPreset());
            dayInputs.filter((i) => i.checked).forEach((i) => body.append('days[]', i.value));
            body.append('day', document.getElementById('schedDay')?.value || '1');
            body.append('time', timeInput?.value || '');
            body.append('timezone', tzInput?.value || '');
            try {
                const data = await postJson('/settings/schedules/preview', body);
                if (seq !== previewSeq) return; // une saisie plus récente a pris le relais
                showError(whenError, '');
                if (sentenceEl) sentenceEl.textContent = data.sentence || '';
                if (nextEl) nextEl.textContent = (data.next || []).length ? t('Prochaines : {dates}', { dates: data.next.join(', ') }) : '';
                if (metaEl) metaEl.textContent = [data.per_month, data.zone].filter(Boolean).join(' · ');
                diagramTrigger = Object.assign({}, diagramTrigger, {
                    sentence: data.sentence || '',
                    next: (data.next || [])[0] || '',
                    zone: data.zone || '',
                    per_month: data.per_month || '',
                });
            } catch (err) {
                if (seq !== previewSeq) return;
                showError(whenError, err.message);
                if (sentenceEl) sentenceEl.textContent = '';
                if (nextEl) nextEl.textContent = '';
                if (metaEl) metaEl.textContent = '';
                // Règle invalide : le schéma dit « Rythme à préciser ».
                diagramTrigger = Object.assign({}, diagramTrigger, { sentence: '', next: '', per_month: '' });
            }
            renderDiagram();
        }

        profileSel?.addEventListener('change', syncLlmRow);
        presetInputs.forEach((i) => i.addEventListener('change', () => { syncPreset(); schedulePreview(); }));
        dayInputs.forEach((i) => i.addEventListener('change', onDayChange));
        document.getElementById('schedDay')?.addEventListener('change', schedulePreview);
        timeInput?.addEventListener('input', schedulePreview);
        instruction?.addEventListener('input', () => showError(instrError, ''));

        // ---- Exemples de consigne : un clic pose l'exemple dans le champ ----
        // Jamais par-dessus un texte ÉCRIT sans le demander : seul le texte
        // qu'un exemple vient de poser se remplace en silence. Le nom suit si
        // le champ est vide. L'évènement `input` est rejoué pour que le schéma
        // (et l'effacement de l'erreur) suivent, comme pour une frappe.
        const examplesBox = document.getElementById('schedExamples');
        const nameField   = document.getElementById('schedName');
        let placedExample = '';
        examplesBox?.addEventListener('click', (e) => {
            const btn = e.target.closest('.sched-example');
            if (!btn || !instruction) return;
            const text = btn.dataset.instruction || '';
            const current = instruction.value.trim();
            if (current !== '' && current !== placedExample
                && !window.confirm(t('Remplacer la consigne en cours par cet exemple ?'))) {
                return;
            }
            instruction.value = text;
            placedExample = text;
            if (nameField && nameField.value.trim() === '') nameField.value = btn.dataset.name || '';
            examplesBox.querySelectorAll('.sched-example').forEach((b) => b.classList.toggle('is-active', b === btn));
            instruction.dispatchEvent(new Event('input', { bubbles: true }));
            instruction.focus();
        });
        // Retouché, le texte n'est plus l'exemple : la puce s'éteint.
        instruction?.addEventListener('input', () => {
            if (instruction.value.trim() !== placedExample) {
                examplesBox?.querySelectorAll('.sched-example.is-active').forEach((b) => b.classList.remove('is-active'));
            }
        });

        // ---- Fiche de l'assistant : avatar, description, modèle, bases ----
        // Les fiches viennent du serveur (window.SCHED_FORM) : rien n'est
        // recalculé ici, seulement affiché.
        const formData   = window.SCHED_FORM || { assistants: {}, mia: {} };
        const avatarBox  = document.getElementById('schedProfileAvatar');
        const card       = document.getElementById('schedAssistantCard');

        function avatarNode(info) {
            if (info && info.avatar) {
                const img = document.createElement('img');
                img.src = info.avatar;
                img.alt = '';
                return img;
            }
            const dot = document.createElement('span');
            dot.className = 'sched-assistant-dot';
            if (info && info.missing) {
                dot.classList.add('is-missing');
            } else {
                dot.style.background = (info && info.color) || 'var(--accent)';
            }
            return dot;
        }

        // Assistant enregistré devenu inaccessible : l'option « à remplacer »
        // (data-missing) reste choisie tant qu'on n'en prend pas une autre —
        // l'enregistrement et l'essai sont refusés d'ici là (validateForm).
        const missingError = document.getElementById('schedProfileMissing');
        const missingSelected = () => !!(profileSel && profileSel.selectedOptions[0]
            && profileSel.selectedOptions[0].dataset.missing === '1');

        function syncAssistant() {
            const id = profileSel ? profileSel.value : '';
            const missing = missingSelected();
            const info = !missing && id && formData.assistants ? formData.assistants[id] : null;
            const shown = info || (missing && formData.missing
                ? { name: formData.missing.name || '', description: formData.missing.description || '', meta: '', missing: true }
                : {
                    name: formData.mia.name || '',
                    avatar: formData.mia.avatar || '',
                    description: formData.mia.description || '',
                    meta: '',
                });
            if (missingError) missingError.hidden = !missing;
            if (profileSel) {
                if (missing) profileSel.setAttribute('aria-invalid', 'true');
                else profileSel.removeAttribute('aria-invalid');
            }
            if (avatarBox) avatarBox.replaceChildren(avatarNode(shown));
            if (card) {
                card.classList.toggle('is-missing', missing);
                card.querySelector('[data-role="avatar"]')?.replaceChildren(avatarNode(shown));
                const nameEl = card.querySelector('[data-role="name"]');
                const descEl = card.querySelector('[data-role="description"]');
                const metaEl2 = card.querySelector('[data-role="meta"]');
                if (nameEl) nameEl.textContent = shown.name || '';
                if (descEl) descEl.textContent = shown.description || '';
                if (metaEl2) {
                    metaEl2.textContent = shown.meta || '';
                    metaEl2.hidden = !shown.meta;
                }
            }
            syncLockedKbs(info ? (info.kb_ids || []) : []);
            syncAssistantTools(info ? (info.tools || []) : []);
        }

        // ---- Bases de connaissances : celles de l'assistant verrouillées, trois au plus en plus ----
        const kbBox   = document.getElementById('schedKbs');
        const kbMax   = Number(kbBox ? kbBox.dataset.max : 3) || 3;
        const kbChips = kbBox ? Array.from(kbBox.querySelectorAll('.sched-kb')) : [];
        const kbCount = kbBox ? kbBox.querySelector('[data-role="count"]') : null;

        function syncLockedKbs(lockedIds) {
            const locked = new Set(lockedIds.map(Number));
            kbChips.forEach((chip) => {
                const input = chip.querySelector('input');
                const note = chip.querySelector('[data-role="note"]');
                const isLocked = locked.has(Number(chip.dataset.kbId));
                const wasLocked = chip.classList.contains('is-locked');
                chip.classList.toggle('is-locked', isLocked);
                if (isLocked) {
                    // Une case désactivée n'est pas envoyée : la base de
                    // l'assistant ne consomme aucun cran, c'est voulu.
                    input.checked = true;
                    input.disabled = true;
                    if (note) note.textContent = t('incluse par l’assistant');
                } else if (wasLocked) {
                    input.checked = false;
                    input.disabled = chip.classList.contains('is-unavailable');
                    if (note) note.textContent = note.dataset.default || '';
                }
            });
            syncKbLimit();
        }

        function syncKbLimit() {
            const free = (chip) => !chip.classList.contains('is-locked') && !chip.classList.contains('is-unavailable');
            const checked = kbChips.filter((chip) => free(chip) && chip.querySelector('input').checked).length;
            kbChips.forEach((chip) => {
                if (!free(chip)) return;
                const input = chip.querySelector('input');
                const full = checked >= kbMax && !input.checked;
                input.disabled = full;
                chip.classList.toggle('is-full', full);
            });
            // « n/3 » dans l'en-tête de la rangée : les bases de l'assistant
            // n'y comptent pas, elles ne consomment aucun cran.
            if (kbCount) kbCount.textContent = checked + '/' + kbMax;
        }
        kbChips.forEach((chip) => chip.querySelector('input')?.addEventListener('change', syncKbLimit));

        // ---- Outils : un par type ; un outil qui AGIT exclut la lecture seule ----
        const toolChips   = Array.from(form.querySelectorAll('.sched-tool'));
        const heldNote    = document.getElementById('schedAssistantToolsHeld');
        // Outils qu'apporte l'assistant choisi (sa fiche, window.SCHED_FORM).
        let assistantTools = [];

        // Leur tuile le dit ; la note d'origine revient quand l'assistant change.
        function syncAssistantTools(list) {
            assistantTools = Array.isArray(list) ? list : [];
            const brought = new Set(assistantTools.map((tool) => Number(tool.id)));
            toolChips.forEach((chip) => {
                const note = chip.querySelector('[data-role="note"]');
                const input = chip.querySelector('input');
                if (!note || !input || chip.classList.contains('is-unavailable')) return;
                note.textContent = brought.has(Number(input.value))
                    ? t('apporté par l’assistant')
                    : (note.dataset.default || '');
            });
            syncHeldTools();
        }

        // « Agir sans validation » : l'outil qui agit apporté par l'assistant,
        // sans outil du même type coché pour la tâche, n'agit pas seul — ses
        // actions attendent une validation (Scheduler::autoExecutable). Dit
        // sous le choix, nommé.
        function syncHeldTools() {
            if (!heldNote) return;
            const checkedTypes = new Set(toolChips
                .filter((chip) => chip.querySelector('input')?.checked)
                .map((chip) => chip.dataset.type));
            const held = assistantTools.filter((tool) => tool.acting && !checkedTypes.has(tool.type));
            const show = !!(autoOpt && autoOpt.checked) && held.length > 0;
            heldNote.hidden = !show;
            heldNote.textContent = show
                ? t('Outil apporté par l’assistant ({liste}) : ses actions attendront votre validation. Cochez-le dans « Outils » pour qu’il agisse seul.', {
                    liste: held.map((tool) => tool.name).join(', '),
                })
                : '';
        }
        const modeInputs  = Array.from(form.querySelectorAll('input[name="allow_actions"]'));
        const readOnlyOpt = form.querySelector('input[name="allow_actions"][value=""]');
        const autoOpt     = form.querySelector('input[name="allow_actions"][value="auto"]');
        const autoOptions = document.getElementById('schedAutoOptions');
        const autoSendInput = form.querySelector('input[name="auto_send_mail"]');
        const forcedNote  = document.getElementById('schedActionsForced');

        // Le choix de la PERSONNE ('' lecture seule, '1' à approuver, 'auto'
        // sans validation), rétabli quand plus aucun outil n'exclut la lecture
        // seule : décocher l'outil ne doit pas laisser derrière lui plus de
        // droits qu'elle n'en avait choisis.
        const checkedMode = () => (modeInputs.find((opt) => opt.checked)?.value ?? '');
        let chosenMode = checkedMode();
        modeInputs.forEach((opt) => opt.addEventListener('change', () => {
            chosenMode = checkedMode();
            syncAutoOptions();
        }));

        // Envoi des emails et avertissement : seulement en « Agir sans validation ».
        function syncAutoOptions() {
            if (autoOptions) autoOptions.hidden = !(autoOpt && autoOpt.checked);
            syncHeldTools();
        }

        function syncActionsMode() {
            const acting = toolChips.some((chip) => chip.dataset.acting === '1' && chip.querySelector('input')?.checked);
            let wanted = chosenMode;
            if (wanted === 'auto' && (!autoOpt || autoOpt.disabled)) wanted = '1';
            if (wanted === '' && acting) wanted = '1';
            modeInputs.forEach((opt) => { opt.checked = opt.value === wanted; });
            if (readOnlyOpt) readOnlyOpt.disabled = acting;
            if (forcedNote) forcedNote.hidden = !acting;
            syncAutoOptions();
        }
        toolChips.forEach((chip) => {
            chip.querySelector('input')?.addEventListener('change', (e) => {
                if (e.target.checked) {
                    toolChips
                        .filter((other) => other !== chip && other.dataset.type === chip.dataset.type)
                        .forEach((other) => { const input = other.querySelector('input'); if (input) input.checked = false; });
                }
                syncActionsMode();
            });
        });

        // ---- Schéma de la tâche telle que saisie, à droite ----
        // Composé depuis les champs, sous la forme de TaskDiagram::spec, et
        // redessiné à chaque geste (balisage identique : rien n'est reposé).
        const diagramPanel = document.getElementById('schedDiagramPanel');
        const diagramHost  = document.getElementById('schedDiagram');
        const diagramTitle = document.getElementById('schedDiagramTitle');
        if (diagramConf && diagramPanel && diagramHost && window.MiaScheduleDiagram) {
            const nameInput = document.getElementById('schedName');
            const llmSelect = document.getElementById('schedLlm');

            // ---- Du schéma au réglage ----
            // Chaque nœud du schéma désigne ce qu'il montre (data-target, posé
            // par schedule-diagram.js) ; le formulaire, seul à connaître ses
            // sections, dit où cela se règle. Un clic sur le nœud (ou son
            // bouton « crayon », au clavier) : la section vient à l'écran,
            // s'allume un instant, et son champ prend le focus — sans second
            // défilement. Les intitulés passés au schéma (infobulle et nom du
            // bouton) sont les titres des sections, lus dans la page.
            const byId = (id) => document.getElementById(id);
            const field = (selector) => form.querySelector(selector);
            const sectionTitle = (id) => (byId(id)?.textContent || '').trim();
            const titles = {
                what:  sectionTitle('schedWhatTitle'),
                with:  sectionTitle('schedWithTitle'),
                when:  sectionTitle('schedWhenTitle'),
                where: sectionTitle('schedWhereTitle'),
            };
            // Cible → section (pour l'intitulé), bloc à montrer, champ à focaliser.
            // Un bloc absent (pas de base, pas d'outil) retombe sur sa section.
            const llmShown = () => !!(llmRow && !llmRow.hidden);
            const JUMP_TARGETS = {
                trigger:     { section: 'when',  el: () => byId('schedSectionWhen'),  field: () => field('input[name="freq"]:checked') },
                llm:         { section: 'what',  el: () => (llmShown() ? llmRow : byId('schedAssistantField')), field: () => (llmShown() ? llmSelect : profileSel) },
                assistant:   { section: 'what',  el: () => byId('schedAssistantField'), field: () => profileSel },
                instruction: { section: 'what',  el: () => byId('schedInstructionBlock'), field: () => instruction },
                connectors:  { section: 'with',  el: () => byId('schedSources') || byId('schedSectionWith') },
                kbs:         { section: 'with',  el: () => byId('schedKbs') || byId('schedSectionWith') },
                tools:       { section: 'with',  el: () => byId('schedTools') || byId('schedSectionWith') },
                sources:     { section: 'with',  el: () => byId('schedSectionWith') },
                mode:        { section: 'with',  el: () => byId('schedActionsMode'), field: () => field('input[name="allow_actions"]:checked') },
                destination: { section: 'where', el: () => byId('schedSectionWhere'), field: () => field('input[name="destination"]:checked') },
                notify:      { section: 'where', el: () => byId('schedSectionWhere'), field: () => field('input[name="notify"]') },
            };
            const jumpTitles = {};
            Object.keys(JUMP_TARGETS).forEach((key) => {
                const label = titles[JUMP_TARGETS[key].section];
                if (label) jumpTitles[key] = t('Modifier dans « {section} »', { section: label });
            });

            let spotTimer = null;
            function spotlight(el) {
                form.querySelectorAll('.is-spotlit').forEach((x) => x.classList.remove('is-spotlit'));
                clearTimeout(spotTimer);
                el.classList.add('is-spotlit');
                spotTimer = setTimeout(() => el.classList.remove('is-spotlit'), 1800);
            }
            function jumpTo(key) {
                const jump = JUMP_TARGETS[key];
                const el = jump ? jump.el() : null;
                if (!el) return;
                const target = jump.field ? jump.field() : null;
                const reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
                el.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });
                spotlight(el);
                if (target && typeof target.focus === 'function') {
                    try { target.focus({ preventScroll: true }); } catch (_e) { target.focus(); }
                }
            }
            // Le bouton « crayon » et le nœud entier portent la même cible ;
            // la consigne et les traits de l'assistant, une cible plus précise.
            diagramHost.addEventListener('click', (e) => {
                const node = e.target.closest('[data-target]');
                if (node && diagramHost.contains(node)) jumpTo(node.dataset.target);
            });
            const checkedValues = (name) => Array.from(form.querySelectorAll('input[name="' + name + '"]'))
                .filter((i) => i.checked)
                .map((i) => i.value);
            const formSpec = () => ({
                name: nameInput ? nameInput.value : '',
                instruction: instruction ? instruction.value : '',
                profile_id: profileSel && profileSel.value ? Number(profileSel.value) : null,
                llm: llmSelect ? llmSelect.value : '',
                sources: checkedValues('sources[]'),
                // Les bases de l'assistant viennent de sa fiche, pas des cases
                // verrouillées qui les montrent.
                kb_ids: kbChips
                    .filter((chip) => !chip.classList.contains('is-locked'))
                    .map((chip) => chip.querySelector('input'))
                    .filter((input) => input && input.checked)
                    .map((input) => Number(input.value)),
                tool_ids: checkedValues('tool_ids[]').map(Number),
                allow_actions: checkedMode() !== '',
                auto_actions: checkedMode() === 'auto',
                auto_send_mail: checkedMode() === 'auto' && !!autoSendInput?.checked,
                destination: checkedValues('destination')[0] || 'thread',
                notify: checkedValues('notify').length > 0,
                trigger: diagramTrigger,
            });
            let diagramTimer = null;
            let diagramFirst = true;
            const paintDiagram = () => {
                diagramTimer = null;
                const spec = formSpec();
                const untitled = (diagramConf.catalog && diagramConf.catalog.labels && diagramConf.catalog.labels.untitled) || '';
                if (diagramTitle) diagramTitle.textContent = spec.name.trim() || untitled;
                diagramPanel.hidden = false;
                window.MiaScheduleDiagram.mount(diagramHost, spec, diagramConf.catalog, { animate: diagramFirst, jump: jumpTitles });
                diagramFirst = false;
            };
            renderDiagram = () => {
                if (diagramTimer === null) diagramTimer = setTimeout(paintDiagram, 90);
            };
            // Les écouteurs des champs passent avant (assistant, bases
            // verrouillées, validation imposée) : le schéma lit l'état final.
            form.addEventListener('input', renderDiagram);
            form.addEventListener('change', renderDiagram);
        }

        // ---- Contrôles de l'envoi ET de l'essai : ceux du serveur, dits à côté du champ ----
        function validateForm() {
            let firstInvalid = null;
            if (missingSelected()) {
                if (missingError) missingError.hidden = false;
                firstInvalid = profileSel;
            }
            if (instruction && instruction.value.trim() === '') {
                showError(instrError, t('Écrivez la consigne que l’assistant suivra.'));
                firstInvalid = firstInvalid || instruction;
            }
            if (currentPreset() === 'weekly' && !dayInputs.some((i) => i.checked)) {
                showError(whenError, t('Choisissez au moins un jour.'));
                firstInvalid = firstInvalid || dayInputs[0];
            } else if (timeInput && !/^\d{1,2}:\d{2}$/.test(timeInput.value)) {
                showError(whenError, t('Indiquez une heure valide, par exemple 08:00.'));
                firstInvalid = firstInvalid || timeInput;
            }
            if (firstInvalid) {
                firstInvalid.focus();
                return false;
            }
            return true;
        }

        // ---- Envoi ----
        form.addEventListener('submit', (e) => {
            if (!validateForm()) {
                e.preventDefault();
                return;
            }
            // Un seul envoi : un double clic programmerait deux tâches.
            const submit = form.querySelector('button[type="submit"]');
            if (submit) setTimeout(() => { submit.disabled = true; }, 0);
        });

        // ---- Essai : la tâche telle que saisie, exécutée une fois, suivie sur place ----
        const trial = document.getElementById('schedTrial');
        if (trial) setupTrial(trial, validateForm);

        profileSel?.addEventListener('change', syncAssistant);
        syncAssistant();
        syncActionsMode();
        syncLlmRow();
        syncPreset();
        // Le rendu serveur supposait l'heure UTC tant que le fuseau était
        // inconnu : l'aperçu est recalculé dès qu'il l'est — et le schéma
        // se dessine à son retour, avec le bon fuseau d'emblée.
        runPreview();
    }

    // =====================================================================
    // Essai depuis le formulaire
    // =====================================================================
    /**
     * « Lancer un essai » : POST du formulaire tel quel (rien n'est
     * enregistré), puis suivi du flux de la génération (EventSource sur
     * /jobs/{id}/stream — le même que le chat). Pendant l'écriture : le texte
     * brut, en clair (textContent) ; à la fin : la réponse rendue par le
     * serveur (HTML sûr par construction), les actions préparées, le lien
     * vers la conversation de l'essai.
     */
    /**
     * Pendant l'écriture : le texte brut, allégé de sa syntaxe la plus
     * visible (gras, titres, puces, liens, code) — toujours du TEXTE posé par
     * textContent ; le rendu complet vient du serveur à la fin. Pure.
     */
    function streamPreview(md) {
        return String(md)
            .replace(/^```[^\n]*$/gm, '')
            .replace(/^#{1,6}[ \t]+/gm, '')
            .replace(/^([ \t]*)[-*+][ \t]+/gm, '$1• ')
            .replace(/\*\*([^*\n]+)\*\*/g, '$1')
            .replace(/__([^_\n]+)__/g, '$1')
            .replace(/\*\*/g, '') // gras ouvert, pas encore refermé
            .replace(/\[([^\]\n]+)\]\([^)\s]+\)/g, '$1')
            .replace(/`([^`\n]+)`/g, '$1');
    }

    function setupTrial(trial, validateForm) {
        const form      = document.getElementById('schedForm');
        const btn       = document.getElementById('schedTrialBtn');
        const stopBtn   = document.getElementById('schedTrialStop');
        const statusEl  = document.getElementById('schedTrialStatus');
        const errorEl   = document.getElementById('schedTrialError');
        const resultEl  = document.getElementById('schedTrialResult');
        const actionsEl = document.getElementById('schedTrialActions');
        const textEl    = document.getElementById('schedTrialText');
        const failedEl  = document.getElementById('schedTrialFailed');
        const footEl    = document.getElementById('schedTrialFoot');
        const pendingEl = document.getElementById('schedTrialPending');
        const linkEl    = document.getElementById('schedTrialLink');
        const heldEl    = document.getElementById('schedTrialHeld');
        if (!form || !btn) return;

        let source = null;
        let jobId = 0;
        let convId = 0;
        // « Agir sans validation » : l'essai prépare ses actions sans les
        // effectuer (Scheduler::startTrial) — dit sous le résultat.
        let autoHeld = false;
        let ticker = null;
        let startedAt = 0;
        let streamErrors = 0;
        let preparedCards = 0;
        let rawText = '';

        function setStatus(text) {
            if (statusEl) statusEl.textContent = text || '';
        }
        function setError(text) {
            if (!errorEl) return;
            errorEl.textContent = text || '';
            errorEl.hidden = !text;
        }
        function setRunning(running) {
            btn.disabled = running;
            if (stopBtn) stopBtn.hidden = !running;
            trial.classList.toggle('is-running', running);
            clearInterval(ticker);
            if (running) {
                startedAt = Date.now();
                const tick = () => setStatus(t('{app} travaille… {s} s', {
                    app: (window.SCHED_FORM && window.SCHED_FORM.mia && window.SCHED_FORM.mia.name) || 'MIA',
                    s: Math.round((Date.now() - startedAt) / 1000),
                }));
                tick();
                ticker = setInterval(tick, 1000);
            }
        }
        function resetResult() {
            if (resultEl) resultEl.hidden = false;
            if (actionsEl) { actionsEl.replaceChildren(); actionsEl.hidden = true; }
            rawText = '';
            if (textEl) { textEl.textContent = ''; textEl.classList.remove('is-rendered'); }
            if (failedEl) failedEl.hidden = true;
            if (footEl) footEl.hidden = true;
            if (heldEl) heldEl.hidden = true;
            preparedCards = 0;
        }

        // Pile des actions, comme au-dessus d'une réponse du chat : libellés
        // et détails écrits par le serveur, affichés tels quels.
        function actionLine(entry, running) {
            if (!actionsEl || !entry) return;
            actionsEl.hidden = false;
            const type = String(entry.t || '');
            if (!running) {
                const waiting = Array.from(actionsEl.querySelectorAll('li.is-running'))
                    .find((li) => li.dataset.type === type);
                if (waiting) waiting.remove();
            }
            const li = document.createElement('li');
            li.dataset.type = type;
            if (running) li.className = 'is-running';
            else if (entry.ok === false) li.className = 'is-failed';
            const label = document.createElement('span');
            label.textContent = String(entry.l || '');
            li.appendChild(label);
            if (entry.d) {
                const detail = document.createElement('span');
                detail.className = 'sched-trial-detail';
                detail.textContent = String(entry.d);
                li.appendChild(detail);
            }
            actionsEl.appendChild(li);
        }

        function showFailed(title, message) {
            if (!failedEl) return;
            failedEl.querySelector('strong').textContent = title || t('L’essai n’a pas abouti');
            failedEl.querySelector('span').textContent = message || '';
            failedEl.hidden = false;
        }

        function onEvent(data) {
            if (typeof data.chunk === 'string' && textEl && !textEl.classList.contains('is-rendered')) {
                rawText += data.chunk;
                textEl.textContent = streamPreview(rawText);
            }
            if (data.action_start) actionLine(data.action_start, true);
            if (data.action) actionLine(data.action, false);
            if (data.drive_confirm) preparedCards++;
            if (data.llm_error) showFailed(data.llm_error.title, data.llm_error.message);
            if (data.job_end) finish(String(data.status || 'done'));
        }

        async function finish(status) {
            if (source) { source.close(); source = null; }
            setRunning(false);
            if (actionsEl) actionsEl.querySelectorAll('li.is-running').forEach((li) => li.remove());
            if (status === 'cancelled') setStatus(t('Essai arrêté.'));
            else if (status === 'failed') setStatus(t('L’essai n’a pas abouti.'));
            else setStatus(t('Essai terminé.'));
            try {
                const res = await fetch('/settings/schedules/trial/' + convId, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (!data || data.ok !== true) throw new Error();
                if (data.error) {
                    showFailed(data.error.title, data.error.message);
                    if (textEl) textEl.textContent = '';
                    if (status !== 'cancelled') setStatus(t('L’essai n’a pas abouti.'));
                } else if (typeof data.html === 'string' && textEl) {
                    textEl.innerHTML = data.html; // rendu serveur, sûr par construction (MarkdownHtml)
                    textEl.classList.add('is-rendered');
                    // Un lien suivi ici quitterait le formulaire, saisie non
                    // enregistrée comprise : il s'ouvre à côté.
                    textEl.querySelectorAll('a[href]').forEach((a) => {
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                    });
                }
                const pending = Number(data.pending || preparedCards || 0);
                if (pendingEl) {
                    pendingEl.textContent = pending > 0
                        ? tn(pending, '{n} action préparée attend votre validation', '{n} actions préparées attendent votre validation')
                        : '';
                    pendingEl.hidden = pending === 0;
                }
                if (heldEl) heldEl.hidden = !(autoHeld && pending > 0);
                if (linkEl) linkEl.href = data.url || ('/chat/' + convId);
                if (footEl) footEl.hidden = false;
            } catch (_e) {
                if (linkEl) linkEl.href = '/chat/' + convId;
                if (footEl) footEl.hidden = false;
            }
        }

        function follow() {
            streamErrors = 0;
            source = new EventSource('/jobs/' + jobId + '/stream');
            source.onmessage = (ev) => {
                streamErrors = 0;
                let data;
                try { data = JSON.parse(ev.data); } catch (_e) { return; }
                onEvent(data);
            };
            // EventSource se reconnecte seul (Last-Event-ID) ; six échecs
            // d'affilée = session expirée ou serveur perdu : on le dit.
            source.onerror = () => {
                streamErrors++;
                if (streamErrors >= 6 && source) {
                    source.close();
                    source = null;
                    setRunning(false);
                    setStatus(t('Suivi interrompu : ouvrez la conversation de l’essai pour voir le résultat.'));
                    if (linkEl) linkEl.href = '/chat/' + convId;
                    if (footEl) footEl.hidden = false;
                }
            };
        }

        btn.addEventListener('click', async () => {
            if (!validateForm()) return;
            setError('');
            const body = new FormData(form);
            body.append('task_id', trial.dataset.taskId || '0');
            // Le nouvel essai remplace le précédent de la page (s'il n'a servi à rien d'autre).
            if (convId) body.append('replace', String(convId));
            resetResult();
            btn.disabled = true;
            setStatus(t('Lancement…'));
            try {
                const data = await postJson('/settings/schedules/trial', body);
                jobId = Number(data.job_id);
                convId = Number(data.conversation_id);
                autoHeld = data.auto_held === true;
                setRunning(true);
                follow();
            } catch (err) {
                if (resultEl) resultEl.hidden = true;
                btn.disabled = false;
                setStatus('');
                setError(err.message);
            }
        });

        stopBtn?.addEventListener('click', async () => {
            if (!jobId) return;
            stopBtn.disabled = true;
            try {
                await fetch('/jobs/' + jobId + '/cancel', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' } });
            } catch (_e) {
                // Le flux dira l'issue ; rien d'autre à faire ici.
            } finally {
                stopBtn.disabled = false;
            }
        });
    }

    // =====================================================================
    // Liste
    // =====================================================================

    /**
     * Schéma de la tâche cliquée. À droite de la liste sur un écran large
     * (la tâche affichée d'office : celle de l'adresse #task-ID, sinon la
     * première) ; dépliée sous la tâche sur un écran étroit, où un second
     * clic la replie. La configuration de chaque tâche est déjà dans la page
     * (window.SCHED_DIAGRAMS) : aucun aller-retour au clic.
     */
    function setupListDiagram() {
        const conf  = window.SCHED_DIAGRAMS;
        const list  = document.getElementById('schedList');
        const panel = document.getElementById('schedDiagramPanel');
        const host  = document.getElementById('schedDiagram');
        if (!conf || !list || !panel || !host || !window.MiaScheduleDiagram) return null;

        const split    = panel.parentElement;
        const title    = document.getElementById('schedDiagramTitle');
        const edit     = document.getElementById('schedDiagramEdit');
        const closeBtn = document.getElementById('schedDiagramClose');
        // Même seuil que la mise en page côte à côte de main.css (.sched-split).
        const wide = window.matchMedia('(min-width: 1200px)');
        let current = 0;

        const specOf = (id) => (conf.tasks && conf.tasks[String(id)]) || null;
        const itemOf = (id) => document.getElementById('task-' + id);

        // Côte à côte : le panneau suit la liste ; en colonne : sous la tâche.
        function place(item) {
            if (wide.matches) {
                if (panel.parentElement !== split) split.appendChild(panel);
            } else if (item && item.nextElementSibling !== panel) {
                item.after(panel);
            }
        }

        function paint(animate) {
            const spec = specOf(current);
            if (!spec) return;
            if (title) title.textContent = spec.name || '';
            if (edit) edit.href = '/settings/schedules/' + current + '/edit';
            window.MiaScheduleDiagram.mount(host, spec, conf.catalog, { animate });
        }

        function select(id, opts) {
            const item = itemOf(id);
            if (!specOf(id) || !item) return;
            current = Number(id);
            list.querySelectorAll('.sched-item').forEach((el) => {
                const on = el === item;
                el.classList.toggle('is-selected', on);
                if (on) el.setAttribute('aria-current', 'true');
                else el.removeAttribute('aria-current');
            });
            place(item);
            panel.hidden = false;
            split.classList.add('is-open');
            paint(opts.animate !== false);
            if (opts.remember) {
                try { history.replaceState(null, '', '#task-' + current); } catch (_e) { /* adresse inchangée */ }
            }
            if (!wide.matches && opts.scroll) panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        function close() {
            const item = itemOf(current);
            current = 0;
            panel.hidden = true;
            split.classList.remove('is-open');
            list.querySelectorAll('.sched-item.is-selected').forEach((el) => {
                el.classList.remove('is-selected');
                el.removeAttribute('aria-current');
            });
            try { history.replaceState(null, '', location.pathname + location.search); } catch (_e) { /* adresse inchangée */ }
            item?.querySelector('.sched-item-name')?.focus();
        }

        // Un clic sur la tâche — hors de ses commandes (interrupteur, boutons,
        // liens) — montre son schéma. Le nom, lien de modification sans
        // JavaScript, le montre aussi : « Modifier » reste là pour modifier.
        list.addEventListener('click', (e) => {
            if (panel.contains(e.target)) return;
            const item = e.target.closest('.sched-item');
            if (!item) return;
            const name = e.target.closest('.sched-item-name');
            if (!name && e.target.closest('a, button, input, select, textarea, label, form')) return;
            if (name) e.preventDefault();
            const id = Number(item.dataset.taskId);
            if (!wide.matches && id === current && !panel.hidden) {
                close();
                return;
            }
            select(id, { remember: true, scroll: true });
        });
        closeBtn?.addEventListener('click', close);

        wide.addEventListener('change', () => {
            if (current) {
                place(itemOf(current));
                paint(false);
            } else if (wide.matches) {
                const first = list.querySelector('.sched-item');
                if (first) select(first.dataset.taskId, { animate: true });
            }
        });

        const fromHash = /^#task-(\d+)$/.exec(location.hash || '');
        if (fromHash && specOf(fromHash[1])) {
            select(fromHash[1], { animate: true, scroll: true });
        } else if (wide.matches) {
            const first = list.querySelector('.sched-item');
            if (first) select(first.dataset.taskId, { animate: true });
        }

        return {
            /** Interrupteur basculé : le déclencheur du schéma suit. */
            toggled(id, active, next) {
                const spec = specOf(id);
                if (!spec) return;
                spec.trigger = Object.assign({}, spec.trigger, { active: !!active, next: next || '', pause: '' });
                if (Number(id) === current) paint(false);
            },
        };
    }
    const listDiagram = setupListDiagram();

    function itemError(scope, message) {
        const el = scope?.querySelector('[data-role="error"]');
        if (!el) return;
        el.textContent = message || '';
        el.hidden = !message;
    }

    // Interrupteur Active / En pause : enregistré au clic. L'état affiché ne
    // bascule qu'après le OK du serveur — un échec doit se voir, pas mentir.
    document.querySelectorAll('.sched-toggle').forEach((input) => {
        input.addEventListener('change', async () => {
            const item = input.closest('.sched-item');
            const toggleForm = input.closest('form');
            const wanted = input.checked;
            input.disabled = true;
            itemError(item, '');
            const body = new FormData();
            body.append('active', wanted ? '1' : '0');
            try {
                const data = await postJson(toggleForm.action, body);
                item?.classList.toggle('is-paused', !data.active);
                listDiagram?.toggled(item?.dataset.taskId, data.active, data.next);
                const state = item?.querySelector('[data-role="state"]');
                if (state) {
                    state.textContent = data.active
                        ? t('Prochaine : {date}', { date: data.next || '' })
                        : t('En pause');
                }
            } catch (err) {
                input.checked = !wanted;
                itemError(item, err.message);
            } finally {
                input.disabled = false;
            }
        });
    });

    // « Exécuter maintenant » : la consigne part, puis on ouvre la
    // conversation de la tâche — la réponse s'y affiche en direct.
    document.querySelectorAll('.sched-run-form').forEach((runForm) => {
        runForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const scope = runForm.closest('.sched-item, .sched-history');
            const btn = runForm.querySelector('button');
            const label = btn ? btn.textContent : '';
            if (btn) { btn.disabled = true; btn.textContent = t('Lancement…'); }
            itemError(scope, '');
            try {
                const data = await postJson(runForm.action, new FormData());
                window.location.href = data.url;
            } catch (err) {
                itemError(scope, err.message);
                if (btn) { btn.disabled = false; btn.textContent = label; }
            }
        });
    });

    // Suppression : confirmation, rien de plus — le serveur redirige.
    document.querySelectorAll('.sched-delete-form').forEach((deleteForm) => {
        deleteForm.addEventListener('submit', (e) => {
            if (!window.confirm(deleteForm.dataset.confirm || '')) e.preventDefault();
        });
    });

    const pushCard = document.getElementById('schedPush');
    if (pushCard) setupPush(pushCard);

    // =====================================================================
    // Notifications sur cet appareil
    // =====================================================================
    /**
     * Inscrit CE navigateur aux notifications des tâches programmées (Web
     * Push) : permission, service worker (/sw.js, portée « / »), inscription
     * auprès du service de notification du navigateur avec la clé publique de
     * l'instance, puis enregistrement côté serveur. L'état affiché est celui
     * du NAVIGATEUR (une inscription existe-t-elle ?), la liste des appareils
     * celle du serveur.
     *
     * `mia.push.owner` (localStorage) retient le compte qui a inscrit ce
     * navigateur : user-menu.js le désinscrit à la déconnexion, ou quand un
     * autre compte s'y connecte.
     */
    function setupPush(card) {
        const OWNER_KEY = 'mia.push.owner';
        const key      = card.dataset.vapidKey || '';
        const userId   = card.dataset.userId || '';
        const stateEl  = document.getElementById('schedPushState');
        const helpEl   = document.getElementById('schedPushHelp');
        const errorEl  = document.getElementById('schedPushError');
        const onBtn    = document.getElementById('schedPushOn');
        const offBtn   = document.getElementById('schedPushOff');
        const testBtn  = document.getElementById('schedPushTest');
        const list     = document.getElementById('schedPushDevices');
        let thisHash = '';

        const supported = 'serviceWorker' in navigator && 'PushManager' in window
            && 'Notification' in window && window.isSecureContext;

        function show(el, visible) { if (el) el.hidden = !visible; }
        function setState(text) { if (stateEl) stateEl.textContent = text; }
        function setHelp(text) { if (helpEl) { helpEl.textContent = text || ''; helpEl.hidden = !text; } }
        function setError(text) { if (errorEl) { errorEl.textContent = text || ''; errorEl.hidden = !text; } }
        function remember(on) {
            try {
                if (on) localStorage.setItem(OWNER_KEY, userId);
                else localStorage.removeItem(OWNER_KEY);
            } catch (_e) { /* navigation privée : rien à retenir */ }
        }

        /** Clé publique base64url → octets, la forme qu'attend pushManager.subscribe. */
        function keyBytes(b64) {
            const pad = '='.repeat((4 - (b64.length % 4)) % 4);
            const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
            return Uint8Array.from(raw, (c) => c.charCodeAt(0));
        }
        function bytesToB64url(buf) {
            if (!buf) return '';
            const bytes = new Uint8Array(buf);
            let bin = '';
            bytes.forEach((b) => { bin += String.fromCharCode(b); });
            return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        }
        async function sha256Hex(text) {
            const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
            return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
        }

        // ---- Liste des appareils (rendue par le serveur, puis tenue à jour) ----
        function renderDevices(devices) {
            if (!list) return;
            list.replaceChildren();
            (devices || []).forEach((d) => {
                const li = document.createElement('li');
                li.className = 'sched-push-device';
                const text = document.createElement('span');
                text.className = 'sched-push-device-text';
                const name = document.createElement('strong');
                name.textContent = d.label + (d.hash === thisHash ? ' — ' + t('cet appareil') : '');
                const meta = document.createElement('span');
                meta.className = 'sched-hint';
                meta.textContent = d.last
                    ? t('Ajouté le {date} · dernière notification le {last}', { date: d.added, last: d.last })
                    : t('Ajouté le {date}', { date: d.added });
                text.append(name, meta);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn-secondary sched-push-remove';
                btn.dataset.id = String(d.id);
                btn.dataset.self = d.hash === thisHash ? '1' : '';
                btn.textContent = t('Retirer');
                li.append(text, btn);
                list.appendChild(li);
            });
            list.hidden = !(devices && devices.length);
        }

        async function currentSubscription() {
            const reg = await navigator.serviceWorker.getRegistration('/');
            return reg && reg.pushManager ? reg.pushManager.getSubscription() : null;
        }

        function payloadOf(sub) {
            const body = new FormData();
            body.append('endpoint', sub.endpoint);
            body.append('p256dh', bytesToB64url(sub.getKey('p256dh')));
            body.append('auth', bytesToB64url(sub.getKey('auth')));
            body.append('key', key);
            return body;
        }

        function paint(on) {
            show(onBtn, !on);
            show(offBtn, on);
            show(testBtn, on);
            setState(on ? t('Activées sur cet appareil.') : t('Désactivées sur cet appareil.'));
        }

        async function refresh() {
            setError('');
            // Navigateur inscrit par un AUTRE compte : user-menu.js le
            // désinscrit d'abord ; l'état et la liste suivent.
            if (window.miaPushGuard) {
                await window.miaPushGuard.catch(() => {});
                if (Array.isArray(window.miaPushGuardDevices)) renderDevices(window.miaPushGuardDevices);
            }
            if (!supported || !key) {
                show(onBtn, false); show(offBtn, false); show(testBtn, false);
                setState(t('Ce navigateur ne reçoit pas les notifications.'));
                const ios = /iPhone|iPad|iPod/.test(navigator.userAgent)
                    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                if (!window.isSecureContext) {
                    setHelp(t('Les notifications demandent une connexion sécurisée (https).'));
                } else if (card.dataset.native === '1') {
                    setHelp(t('L’application mobile ne les reçoit pas encore : activez-les depuis le navigateur de votre ordinateur ou de votre téléphone.'));
                } else if (ios) {
                    setHelp(t('Sur iPhone et iPad : ajoutez {app} à l’écran d’accueil (Partager → Sur l’écran d’accueil), ouvrez-la depuis son icône, puis activez les notifications ici.', { app: card.dataset.appName || 'MIA' }));
                }
                return;
            }
            if (Notification.permission === 'denied') {
                show(onBtn, false); show(offBtn, false); show(testBtn, false);
                setState(t('Notifications bloquées pour ce site.'));
                setHelp(t('Autorisez-les dans les réglages du navigateur pour ce site, puis rechargez la page.'));
                return;
            }
            setHelp('');
            let sub = null;
            try { sub = await currentSubscription(); } catch (_e) { sub = null; }
            // Inscription faite avec une autre clé que celle de l'instance :
            // elle ne recevrait jamais rien, on la défait.
            if (sub && bytesToB64url(sub.options && sub.options.applicationServerKey) !== key) {
                await sub.unsubscribe().catch(() => {});
                sub = null;
            }
            if (sub) {
                thisHash = await sha256Hex(sub.endpoint).catch(() => '');
                // Remise à niveau silencieuse : le navigateur renouvelle parfois
                // son adresse, et l'appareil suit le compte connecté.
                try {
                    const data = await postJson('/settings/push/subscribe', payloadOf(sub));
                    renderDevices(data.devices);
                    remember(true);
                } catch (_e) { /* la liste rendue par le serveur reste affichée */ }
            } else {
                thisHash = '';
                remember(false);
            }
            paint(!!sub);
        }

        onBtn?.addEventListener('click', async () => {
            setError('');
            onBtn.disabled = true;
            try {
                // Demandée DANS le geste : Safari refuse sinon.
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    await refresh();
                    return;
                }
                await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                const reg = await navigator.serviceWorker.ready;
                const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
                const data = await postJson('/settings/push/subscribe', payloadOf(sub));
                thisHash = await sha256Hex(sub.endpoint).catch(() => '');
                renderDevices(data.devices);
                remember(true);
                paint(true);
            } catch (err) {
                setError(err && err.message ? err.message : t('Échec — réessayez dans un instant.'));
            } finally {
                onBtn.disabled = false;
            }
        });

        offBtn?.addEventListener('click', async () => {
            setError('');
            offBtn.disabled = true;
            try {
                const sub = await currentSubscription();
                if (sub) {
                    const body = new FormData();
                    body.append('endpoint', sub.endpoint);
                    await sub.unsubscribe().catch(() => {});
                    const data = await postJson('/settings/push/unsubscribe', body);
                    renderDevices(data.devices);
                }
                thisHash = '';
                remember(false);
                paint(false);
            } catch (err) {
                setError(err.message);
            } finally {
                offBtn.disabled = false;
            }
        });

        testBtn?.addEventListener('click', async () => {
            setError('');
            testBtn.disabled = true;
            try {
                const data = await postJson('/settings/push/test', new FormData());
                setHelp(data.message || '');
                renderDevices(data.devices);
            } catch (err) {
                setError(err.message);
            } finally {
                testBtn.disabled = false;
            }
        });

        list?.addEventListener('click', async (e) => {
            const btn = e.target.closest('.sched-push-remove');
            if (!btn) return;
            setError('');
            btn.disabled = true;
            try {
                const data = await postJson('/settings/push/' + encodeURIComponent(btn.dataset.id) + '/delete', new FormData());
                if (btn.dataset.self === '1') {
                    const sub = await currentSubscription().catch(() => null);
                    if (sub) await sub.unsubscribe().catch(() => {});
                    thisHash = '';
                    remember(false);
                    paint(false);
                }
                renderDevices(data.devices);
            } catch (err) {
                setError(err.message);
                btn.disabled = false;
            }
        });

        refresh();
    }
})();
