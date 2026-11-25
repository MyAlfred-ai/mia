/**
 * Questions de MIA (mémoire) — la carte discrète posée sous une réponse
 * terminée (MemoryQuestions côté serveur).
 *
 * La question vient du SERVEUR, déjà traduite : fin d'un tour (`done`), ou
 * rechargement (question restée sans réponse sous la dernière réponse). Le
 * script ne contient AUCUN texte : libellés fixes dans window.MIA_MEMORY_Q.
 *
 * Un clic répond (POST /settings/memory/answer) sans appel au modèle ; la
 * carte devient une ligne « Retenu : … · Voir ma mémoire ». « Passer »
 * retire la carte ; « Ne plus me poser de questions » coupe les questions.
 * Une carte sans réponse disparaît dès que la conversation continue : elle ne
 * revient pas.
 *
 * Posée DANS la bulle, hors de .msg-content : « Copier » et les exports ne
 * la voient pas (neutralizeClipboardStyles la retire d'une sélection).
 *
 * La carte d'information de l'apprentissage (`kind: intro`) prend la même
 * forme : son titre et sa note viennent de la question (`title`, `why`), et
 * elle n'a pas de lien « Ne plus me poser de questions » (`stop: false`).
 */
(function () {
    'use strict';

    const cfg = window.MIA_MEMORY_Q || null;
    const L = (cfg && cfg.labels) || {};
    const ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';

    function csrf() {
        return (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    }

    function el(tag, cls, text) {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function button(cls, text) {
        const b = el('button', cls, text);
        b.type = 'button';
        return b;
    }

    function messagesRoot() {
        return document.getElementById('chatMessages');
    }

    function findMessage(id) {
        const root = messagesRoot();
        if (!root) return null;
        return Array.from(root.querySelectorAll('.message[data-message-id]'))
            .find(m => m.getAttribute('data-message-id') === String(id)) || null;
    }

    /** Message qui suit dans le fil (la conversation a continué), ou null. */
    function nextMessage(msg) {
        let n = msg.nextElementSibling;
        while (n) {
            if (n.classList && n.classList.contains('message')) return n;
            n = n.nextElementSibling;
        }
        return null;
    }

    function showStatus(card, text, isError) {
        const status = card.querySelector('.memq-status');
        if (!status) return;
        status.textContent = text || '';
        status.hidden = !text;
        status.classList.toggle('is-error', !!isError);
    }

    function setBusy(card, busy) {
        card.classList.toggle('is-busy', busy);
        card.querySelectorAll('button, input').forEach(b => { b.disabled = busy; });
    }

    function done(card, data) {
        if (data.kind === 'skipped') {
            card.remove();
            return;
        }
        const chip = el('div', 'memq-done');
        chip.setAttribute('role', 'status');
        const icon = el('span', 'memq-icon');
        icon.innerHTML = ICON;
        chip.appendChild(icon);
        chip.appendChild(el('span', 'memq-done-text', data.message || ''));
        if (data.kind !== 'stopped' && L.link) {
            const link = el('a', 'memq-done-link', L.link);
            link.href = '/settings/memory';
            chip.appendChild(link);
        }
        card.replaceWith(chip);
    }

    async function send(card, q, choice, text) {
        if (card.classList.contains('is-busy')) return;
        setBusy(card, true);
        showStatus(card, '', false);
        const body = new FormData();
        body.append('id', String(q.id));
        body.append('choice', choice);
        body.append('text', text || '');
        let data = null;
        try {
            const res = await fetch('/settings/memory/answer', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body,
            });
            data = await res.json().catch(() => null);
        } catch (e) {
            data = null;
        }
        if (!data || !data.ok) {
            setBusy(card, false);
            showStatus(card, (data && data.error) || L.error || '', true);
            return;
        }
        done(card, data);
    }

    function build(q) {
        const card = el('div', 'memq-card');
        card.setAttribute('role', 'group');
        const title = q.title || L.title || '';
        card.setAttribute('aria-label', title);
        card.dataset.questionId = String(q.id);
        card.dataset.kind = String(q.kind || '');

        const head = el('div', 'memq-head');
        const icon = el('span', 'memq-icon');
        icon.innerHTML = ICON;
        head.appendChild(icon);
        head.appendChild(el('span', 'memq-title', title));
        const close = button('memq-close', '×');
        close.setAttribute('aria-label', L.skip || '');
        close.title = L.skip || '';
        close.addEventListener('click', () => send(card, q, 'passer', ''));
        head.appendChild(close);
        card.appendChild(head);

        card.appendChild(el('p', 'memq-question', q.question || ''));
        if (q.quote) card.appendChild(el('blockquote', 'memq-quote', q.quote));

        // Réponse libre : `other` = libellé du bouton qui l'ouvre, '' = champ
        // seul (question ouverte), null = pas de réponse libre.
        let form = null;
        if (q.other !== null && q.other !== undefined) {
            form = el('form', 'memq-other');
            const input = el('input', 'memq-input');
            input.type = 'text';
            input.maxLength = 200;
            input.placeholder = q.placeholder || '';
            input.setAttribute('aria-label', q.question || '');
            const submit = button('memq-send', L.send || '');
            submit.type = 'submit';
            form.appendChild(input);
            form.appendChild(submit);
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const value = input.value.trim();
                if (value === '') {
                    input.focus();
                    return;
                }
                send(card, q, 'autre', value);
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && q.other !== '') {
                    form.hidden = true;
                }
            });
        }

        const options = el('div', 'memq-options');
        (q.options || []).forEach(o => {
            const b = button('memq-opt', o.label);
            b.addEventListener('click', () => send(card, q, String(o.value), ''));
            options.appendChild(b);
        });
        if (form && q.other !== '') {
            const other = button('memq-opt memq-opt-other', q.other);
            other.setAttribute('aria-expanded', 'false');
            other.addEventListener('click', () => {
                form.hidden = false;
                other.setAttribute('aria-expanded', 'true');
                form.querySelector('input')?.focus();
            });
            options.appendChild(other);
            form.hidden = true;
        }
        if (options.children.length) card.appendChild(options);
        if (form) card.appendChild(form);

        const status = el('p', 'memq-status');
        status.setAttribute('role', 'status');
        status.hidden = true;
        card.appendChild(status);

        const foot = el('div', 'memq-foot');
        foot.appendChild(el('span', 'memq-why', q.why || L.why || ''));
        if (q.stop !== false) {
            const stop = button('memq-link', L.stop || '');
            stop.addEventListener('click', () => send(card, q, 'stop', ''));
            foot.appendChild(stop);
        }
        card.appendChild(foot);
        return card;
    }

    /** Pose la carte sous la réponse qu'elle suit, si elle est encore la dernière. */
    function attach(q) {
        if (!cfg || !q || !q.id || !q.message_id) return;
        const msg = findMessage(q.message_id);
        if (!msg || nextMessage(msg)) return;
        if (msg.querySelector('.memq-card, .memq-done')) return;
        const card = build(q);
        const content = msg.querySelector('.msg-content');
        if (content) {
            content.insertAdjacentElement('afterend', card);
        } else {
            (msg.querySelector('.msg-body') || msg).appendChild(card);
        }
    }

    /** La conversation a continué : une question sans réponse s'efface. */
    function prune() {
        const root = messagesRoot();
        if (!root) return;
        root.querySelectorAll('.memq-card').forEach(card => {
            const msg = card.closest('.message');
            if (!msg || nextMessage(msg)) card.remove();
        });
    }

    window.MiaMemoryQuestion = { attach, prune };

    function init() {
        const root = messagesRoot();
        if (root && 'MutationObserver' in window) {
            new MutationObserver(prune).observe(root, { childList: true });
        }
        if (cfg && cfg.pending) attach(cfg.pending);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
