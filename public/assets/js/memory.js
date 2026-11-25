/**
 * Réglages → Mémoire (« Ce que MIA sait de vous ») — rendu et gestes.
 *
 * La page arrive avec sa charge utile (#memoryBootstrap, MemoryController::payload).
 * Chaque geste (ajouter, corriger, ranger, retirer, confirmer, refuser, tout
 * effacer) renvoie la liste à jour, et la page se re-rend depuis elle — jamais
 * depuis un état deviné. Le texte d'un souvenir n'est jamais posé en HTML :
 * textContent ou value seulement.
 *
 * Textes : window.t (dictionnaire lang/en.client.php). Les libellés des thèmes
 * et des portées (« Partout », « Emails »…) viennent du serveur, déjà traduits ;
 * la raison d'une proposition née d'un geste répété aussi (memory.reason).
 */
(function () {
    'use strict';

    const boot = document.getElementById('memoryBootstrap');
    if (!boot) return;

    let state = {};
    try { state = JSON.parse(boot.textContent) || {}; } catch (_) { state = {}; }
    const themes = state.themes || {};
    const scopes = state.scopes || {};
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    const els = {
        confirmSection: document.getElementById('confirmer'),
        confirmNav: document.getElementById('memNavConfirm'),
        confirmList: document.getElementById('memConfirmList'),
        themes: document.getElementById('memThemes'),
        empty: document.getElementById('memEmpty'),
        count: document.getElementById('memCount'),
        feedback: document.getElementById('memFeedback'),
        addTheme: document.getElementById('memAddTheme'),
        addInput: document.getElementById('memAddInput'),
        addBtn: document.getElementById('memAddBtn'),
        gaugeFill: document.getElementById('memGaugeFill'),
        gaugeText: document.getElementById('memGaugeText'),
        clearBtn: document.getElementById('memClearBtn'),
        ctx: document.getElementById('memUserContext'),
        ctxCount: document.getElementById('memCtxCount'),
        ctxCounter: document.getElementById('memCtxCounter'),
    };

    const ORIGINS = {
        vous: t('Écrit par vous'),
        demande: t('Demandé en conversation'),
        question: t('Votre réponse à MIA'),
        confirme: t('Confirmé par vous'),
        appris: t('Appris par MIA'),
    };

    const TRASH = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>';

    function feedback(message, isError) {
        if (!els.feedback) return;
        if (!message) { els.feedback.hidden = true; els.feedback.textContent = ''; return; }
        els.feedback.hidden = false;
        els.feedback.textContent = message;
        els.feedback.classList.toggle('is-error', !!isError);
    }

    async function call(url, fields) {
        const body = new FormData();
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
        let res = null;
        let data = null;
        try {
            res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: body,
            });
            data = await res.json();
        } catch (_) {
            data = null;
        }
        if (data && Array.isArray(data.memories)) {
            state.memories = data.memories;
            if (data.counts) state.counts = data.counts;
            if (data.usage) state.usage = data.usage;
            if (typeof data.max === 'number') state.max = data.max;
            render();
        }
        if (!res || !res.ok || !data || data.ok === false) {
            feedback((data && data.error) || t('Opération impossible. Réessayez.'), true);
            return false;
        }
        feedback('', false);
        return true;
    }

    // Hauteur au contenu. Jamais mesurée sur une largeur nulle (onglet masqué) :
    // recalculée dès que la largeur réelle arrive (ResizeObserver plus bas).
    function autoGrow(textarea) {
        if (!textarea.clientWidth) return;
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }

    function fitAll() {
        document.querySelectorAll('.mem-item-text').forEach(autoGrow);
    }

    function metaLine(memory, proposal) {
        const meta = document.createElement('div');
        meta.className = 'mem-item-meta';
        if (memory.is_new) {
            const badge = document.createElement('span');
            badge.className = 'mem-new';
            badge.textContent = t('Nouveau');
            meta.appendChild(badge);
        }
        const origin = document.createElement('span');
        origin.className = 'mem-origin mem-origin--' + (proposal ? 'proposed' : memory.origin);
        // Proposition née d'un geste répété : ce que MIA a remarqué.
        origin.textContent = proposal
            ? (memory.reason || t('Déduit de vos échanges'))
            : (ORIGINS[memory.origin] || '');
        meta.appendChild(origin);
        // Portée d'une proposition (la liste des souvenirs a son menu).
        if (proposal && memory.scope && memory.scope !== 'all' && scopes[memory.scope]) {
            const scope = document.createElement('span');
            scope.className = 'mem-scope-chip';
            scope.textContent = scopes[memory.scope];
            meta.appendChild(scope);
        }
        if (memory.date) {
            const date = document.createElement('span');
            date.className = 'mem-date';
            date.textContent = memory.date;
            meta.appendChild(date);
        }
        if (memory.conversation && memory.conversation.id) {
            const link = document.createElement('a');
            link.className = 'mem-item-conv';
            link.href = '/chat/' + encodeURIComponent(String(memory.conversation.id));
            link.textContent = t('dans « {titre} »', { titre: memory.conversation.title || '' });
            meta.appendChild(link);
        }
        return meta;
    }

    function editableText(memory, onCommit) {
        const textarea = document.createElement('textarea');
        textarea.className = 'mem-item-text';
        textarea.value = memory.content;
        textarea.rows = 1;
        textarea.maxLength = state.maxLen || 300;
        textarea.setAttribute('aria-label', t('Souvenir'));
        textarea.addEventListener('input', function () { autoGrow(textarea); });
        // Entrée = valider (une phrase courte, pas un paragraphe) ; Échap = annuler.
        textarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); textarea.blur(); }
            if (e.key === 'Escape') { textarea.value = memory.content; textarea.blur(); }
        });
        textarea.addEventListener('blur', function () {
            const value = textarea.value.trim();
            if (value === '' || value === memory.content) {
                textarea.value = memory.content;
                autoGrow(textarea);
                return;
            }
            onCommit(value);
        });
        return textarea;
    }

    function themeSelect(memory) {
        const select = document.createElement('select');
        select.className = 'mem-item-theme';
        select.setAttribute('aria-label', t('Ranger dans un autre thème'));
        Object.keys(themes).forEach(function (key) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = themes[key];
            if (key === memory.theme) option.selected = true;
            select.appendChild(option);
        });
        select.addEventListener('change', function () {
            call('/settings/memory/' + encodeURIComponent(String(memory.id)), { content: memory.content, theme: select.value });
        });
        return select;
    }

    // Portée : à quoi s'applique le souvenir (partout, emails, documents, code).
    function scopeSelect(memory) {
        const select = document.createElement('select');
        select.className = 'mem-item-theme mem-item-scope';
        select.setAttribute('aria-label', t('À quoi ce souvenir s\'applique'));
        select.title = t('À quoi ce souvenir s\'applique');
        Object.keys(scopes).forEach(function (key) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = scopes[key];
            if (key === (memory.scope || 'all')) option.selected = true;
            select.appendChild(option);
        });
        select.addEventListener('change', function () {
            call('/settings/memory/' + encodeURIComponent(String(memory.id)), { content: memory.content, theme: memory.theme, scope: select.value });
        });
        return select;
    }

    function deleteButton(memory) {
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'mem-del';
        del.title = t('Retirer ce souvenir');
        del.setAttribute('aria-label', t('Retirer ce souvenir'));
        del.innerHTML = TRASH;
        del.addEventListener('click', async function () {
            del.disabled = true;
            if (!(await call('/settings/memory/' + encodeURIComponent(String(memory.id)) + '/delete', {}))) {
                del.disabled = false;
            }
        });
        return del;
    }

    function buildItem(memory) {
        const item = document.createElement('div');
        item.className = 'mem-item';
        item.dataset.id = String(memory.id);

        const body = document.createElement('div');
        body.className = 'mem-item-body';
        body.appendChild(editableText(memory, function (value) {
            call('/settings/memory/' + encodeURIComponent(String(memory.id)), { content: value });
        }));
        body.appendChild(metaLine(memory, false));

        const actions = document.createElement('div');
        actions.className = 'mem-item-actions';
        actions.append(scopeSelect(memory), themeSelect(memory), deleteButton(memory));

        item.append(body, actions);
        return item;
    }

    function buildProposal(memory) {
        const item = document.createElement('div');
        item.className = 'mem-item mem-item--proposed';
        item.dataset.id = String(memory.id);

        const body = document.createElement('div');
        body.className = 'mem-item-body';
        const text = document.createElement('p');
        text.className = 'mem-item-static';
        text.textContent = memory.content;
        body.append(text, metaLine(memory, true));

        const actions = document.createElement('div');
        actions.className = 'mem-item-actions mem-item-actions--confirm';

        const yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'btn-primary mem-btn';
        yes.textContent = t('Oui, c\'est ça');
        yes.addEventListener('click', function () {
            yes.disabled = true;
            call('/settings/memory/' + encodeURIComponent(String(memory.id)) + '/confirm', {});
        });

        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'btn-secondary mem-btn';
        no.textContent = t('Non');
        no.addEventListener('click', function () {
            no.disabled = true;
            call('/settings/memory/' + encodeURIComponent(String(memory.id)) + '/reject', {});
        });

        const fix = document.createElement('button');
        fix.type = 'button';
        fix.className = 'btn-secondary mem-btn';
        fix.textContent = t('Corriger');
        fix.addEventListener('click', function () {
            // Corriger = confirmer avec ses propres mots.
            const textarea = editableText(memory, function (value) {
                call('/settings/memory/' + encodeURIComponent(String(memory.id)), { content: value });
            });
            text.replaceWith(textarea);
            autoGrow(textarea);
            textarea.focus();
            textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        });

        actions.append(yes, no, fix);
        item.append(body, actions);
        return item;
    }

    function render() {
        const memories = Array.isArray(state.memories) ? state.memories : [];
        const proposed = memories.filter(function (m) { return m.status === 'proposed'; });
        const active = memories.filter(function (m) { return m.status !== 'proposed'; });
        const max = state.max || 60;

        if (els.confirmList) {
            els.confirmList.replaceChildren.apply(els.confirmList, proposed.map(buildProposal));
            els.confirmSection.hidden = proposed.length === 0;
            if (els.confirmNav) els.confirmNav.hidden = proposed.length === 0;
        }

        let shown = 0;
        els.themes.querySelectorAll('.mem-theme').forEach(function (card) {
            const key = card.dataset.theme;
            const inTheme = active.filter(function (m) { return m.theme === key; });
            const list = card.querySelector('[data-role="list"]');
            list.replaceChildren.apply(list, inTheme.map(buildItem));
            card.querySelector('[data-role="count"]').textContent = inTheme.length ? String(inTheme.length) : '';
            card.hidden = inTheme.length === 0;
            shown += inTheme.length;
        });
        if (els.empty) els.empty.hidden = shown > 0;

        if (els.count) {
            els.count.textContent = t('{n} / {max} souvenirs', { n: active.length, max: max });
            els.count.classList.toggle('is-full', active.length >= max);
        }

        const usage = state.usage || { used: 0, budget: 1, dropped: 0 };
        const budget = Math.max(1, usage.budget || 1);
        if (els.gaugeFill) {
            els.gaugeFill.style.width = Math.min(100, Math.round(100 * (usage.used || 0) / budget)) + '%';
        }
        if (els.gaugeText) {
            let text = t('MIA relit environ {used} caractères de souvenirs à chaque message, sur {budget} au plus.', { used: usage.used || 0, budget: budget });
            if ((usage.dropped || 0) > 0) {
                text += ' ' + tn(usage.dropped,
                    '{n} souvenir ne tient plus dans cette place : ceux que vous avez écrits, demandés ou confirmés passent en premier.',
                    '{n} souvenirs ne tiennent plus dans cette place : ceux que vous avez écrits, demandés ou confirmés passent en premier.');
            }
            els.gaugeText.textContent = text;
        }

        fitAll();
    }

    async function add() {
        const value = els.addInput.value.trim();
        if (value === '') { els.addInput.focus(); return; }
        els.addBtn.disabled = true;
        if (await call('/settings/memory', { content: value, theme: els.addTheme ? els.addTheme.value : 'autre' })) {
            els.addInput.value = '';
        }
        els.addBtn.disabled = false;
        els.addInput.focus();
    }

    if (els.addBtn) els.addBtn.addEventListener('click', add);
    if (els.addInput) {
        els.addInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); add(); }
        });
    }

    if (els.clearBtn) {
        els.clearBtn.addEventListener('click', async function () {
            if (!window.confirm(t('Effacer toute votre mémoire ? Tous vos souvenirs seront retirés, sans retour possible.'))) return;
            els.clearBtn.disabled = true;
            await call('/settings/memory/clear', { confirm: '1' });
            els.clearBtn.disabled = false;
        });
    }

    // Compteur de « Votre contexte ».
    if (els.ctx && els.ctxCount && els.ctxCounter) {
        const max = parseInt(els.ctx.getAttribute('maxlength') || '500', 10);
        const update = function () {
            const len = Array.from(els.ctx.value).length;
            els.ctxCount.textContent = String(len);
            els.ctxCounter.classList.toggle('is-warn', len >= max * 0.8 && len < max);
            els.ctxCounter.classList.toggle('is-error', len >= max);
            // Pastilles d'info « contexte vide » (carte, sommaire, menu,
            // bouton Réglages) : elles s'effacent dès la première lettre.
            const empty = els.ctx.value.trim() === '';
            document.querySelectorAll('[data-context-dot]').forEach(function (dot) { dot.hidden = !empty; });
        };
        els.ctx.addEventListener('input', update);
        update();
    }

    // Re-calibrage au seul changement de LARGEUR.
    if (typeof ResizeObserver !== 'undefined' && els.themes) {
        let lastWidth = 0;
        new ResizeObserver(function (entries) {
            const width = Math.round(entries[0].contentRect.width);
            if (width && width !== lastWidth) { lastWidth = width; fitAll(); }
        }).observe(els.themes);
    }

    render();
})();
