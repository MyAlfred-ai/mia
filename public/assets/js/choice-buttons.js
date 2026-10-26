/**
 * Choix proposés en boutons dans une conversation.
 *
 * LE CONTRAT (côté serveur : src/Helpers/ChoiceButtons.php). Quand la suite
 * dépend d'une décision de l'utilisateur, le modèle termine sa réponse par un
 * bloc dont le langage est `choix` :
 *
 *     ```choix
 *     Quel ton pour l'email ?
 *     - Formel — pour un client
 *     - Cordial
 *     ```
 *
 * Ce module en fait une CARTE de boutons. Un clic envoie le libellé comme un
 * message ordinaire (chat.js, sendChoiceAnswer) : rien n'est stocké, la
 * réponse n'est qu'un message de plus dans le fil.
 *
 * Trois parties :
 *   1. lecture du bloc — PURE, exécutée sous node par les tests ;
 *   2. rendu — une CHAÎNE sans état, recalculée à chaque fragment pendant que
 *      la réponse s'écrit (comme les cartes de fichier) : les boutons y sont
 *      donc toujours inertes ;
 *   3. état — posé après coup par chat.js (syncChoicePrompts), qui seul sait
 *      si la réponse est finie, si quelqu'un a déjà répondu, si une réponse
 *      s'écrit encore. Une carte n'est active que sous la DERNIÈRE réponse,
 *      tant que personne n'a répondu.
 *
 * Langages de bloc, bornes : window.CHOICE_BUTTONS (ChoiceButtons::clientConfig()),
 * jamais recopiés ici — les valeurs ci-dessous ne sont qu'un repli.
 *
 * Chargé APRÈS generated-files.js (lecture d'un bloc refermé, note de réponse
 * interrompue) et AVANT chat.js (qui rend les messages dès son chargement).
 */
(function (root) {
    'use strict';

    const CONFIG = Object.assign({
        languages: ['choix', 'choices', 'choice'],
        multiple: ['multiple', 'multi', 'plusieurs'],
        min: 2,
        limit: 8,
        labelMax: 120,
        descriptionMax: 200,
        questionMax: 300,
        compactMax: 28,
    }, root.CHOICE_BUTTONS || {});

    // ------------------------------------------------------------------
    // 1. Lecture du bloc — PUR
    // ------------------------------------------------------------------

    /** Ligne d'option : puce (- * + •) ou numéro (1. 1)), puis le texte. */
    const OPTION_LINE = /^(?:[-*+•]|\d{1,2}[.)])\s+(.+)$/;

    /** Libellé — précision : tiret long ou demi-long ENTOURÉ d'espaces (« Plan A-B » reste entier). */
    const DESCRIPTION_SEP = /\s+[—–]\s+/;

    function chars(text) {
        return Array.from(String(text || ''));
    }

    /** Coupe à `max` caractères (points de code, comme mb_substr). */
    function cut(text, max) {
        const all = chars(text);
        return all.length > max ? all.slice(0, max).join('').trim() : String(text || '');
    }

    /**
     * Texte d'un libellé, d'une précision ou de la question : un seul blanc
     * entre les mots, et sans la mise en forme que les modèles y glissent
     * (gras, code, titre, citation) — c'est le libellé lui-même qui part
     * comme message.
     */
    function clean(text) {
        let out = String(text || '').replace(/\s+/g, ' ').trim();
        out = out.replace(/^#{1,6}\s+/, '').replace(/^>\s*/, '');
        out = out.replace(/(\*\*|__)(\S(?:.*?\S)?)\1/g, '$2');
        out = out.replace(/`([^`]*)`/g, '$1');
        out = out.replace(/^[*_]+(?=\S)/, '').replace(/(\S)[*_]+$/, '$1');
        return out.trim();
    }

    /**
     * Ligne d'ouverture → `null` si ce n'est pas un bloc de choix, sinon
     * `{ multiple }`. `{.choix}` (attributs à la Pandoc) passe aussi.
     */
    function parseInfo(info) {
        const words = String(info || '').trim().split(/\s+/).filter(Boolean);
        if (!words.length) return null;
        const lang = words[0].replace(/^\{\.?/, '').replace(/\}$/, '').toLowerCase();
        if (CONFIG.languages.indexOf(lang) === -1) return null;

        let multiple = false;
        words.slice(1).forEach(function (word) {
            const m = word.replace(/[{}]/g, '').toLowerCase().match(/^([a-z]+)(?:=["']?([^"']*)["']?)?$/);
            if (m && CONFIG.multiple.indexOf(m[1]) !== -1) {
                multiple = m[2] === undefined || !/^(?:0|false|non|no)$/.test(m[2]);
            }
        });
        return { multiple: multiple };
    }

    /**
     * Contenu du bloc → `{ question, options: [{label, description}] }`.
     *
     * Les lignes avant la première option forment la question ; une ligne qui
     * n'est pas une option APRÈS elles est ignorée (un modèle y glisse parfois
     * une remarque). Libellés dédoublonnés sans tenir compte de la casse,
     * `limit` options au plus.
     *
     * Tolérance : un bloc SANS aucune puce (une option par ligne) se lit
     * encore — la première ligne est la question si elle finit par « ? » ou
     * « : », sinon toutes les lignes sont des options.
     */
    function parseBody(text) {
        const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n')
            .map(function (line) { return line.trim(); })
            .filter(function (line) { return line !== ''; });

        let question = [];
        let items = [];
        lines.forEach(function (line) {
            const m = line.match(OPTION_LINE);
            if (m) items.push(m[1]);
            else if (!items.length) question.push(line);
        });

        if (!items.length && question.length >= 2) {
            const first = question[0];
            if (/[?:：]$/.test(first)) {
                items = question.slice(1);
                question = [first];
            } else {
                items = question;
                question = [];
            }
        }

        const options = [];
        const seen = {};
        items.forEach(function (item) {
            const body = item.replace(/^\[[ xX]\]\s+/, '');
            const sep = body.match(DESCRIPTION_SEP);
            let label = body;
            let description = '';
            if (sep && sep.index > 0) {
                label = body.slice(0, sep.index);
                description = body.slice(sep.index + sep[0].length);
            }
            label = cut(clean(label), CONFIG.labelMax);
            description = cut(clean(description), CONFIG.descriptionMax);
            if (label === '' || options.length >= CONFIG.limit) return;
            const key = label.toLowerCase();
            if (seen[key]) return;
            seen[key] = true;
            options.push({ label: label, description: description });
        });

        return { question: cut(clean(question.join(' ')), CONFIG.questionMax), options: options };
    }

    /**
     * Bloc complet → `null` si ce n'est pas un bloc de choix, sinon
     * `{ question, multiple, options, valid }` (`valid` : assez d'options pour
     * des boutons ; sinon le bloc s'affiche en simple liste).
     */
    function parse(info, text) {
        const head = parseInfo(info);
        if (!head) return null;
        const body = parseBody(text);
        return {
            question: body.question,
            multiple: head.multiple,
            options: body.options,
            valid: body.options.length >= CONFIG.min,
        };
    }

    /** Pastilles sur une ligne : choix unique, options courtes et sans précision. */
    function isCompact(choice) {
        return !choice.multiple && choice.options.every(function (o) {
            return o.description === '' && chars(o.label).length <= CONFIG.compactMax;
        });
    }

    /** Une option en texte : « Libellé — précision ». */
    function optionText(o) {
        return o.description ? o.label + ' — ' + o.description : o.label;
    }

    /**
     * Markdown d'une réponse → le même texte, chaque bloc de choix remplacé
     * par sa question et la liste de ses options (copie en texte brut).
     * Parcours ligne à ligne : un bloc d'une autre langue n'est jamais touché,
     * et une ligne « ```choix » DANS un tel bloc n'en ouvre pas un.
     */
    function toPlainText(markdown) {
        const lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n');
        const out = [];
        let fence = null;   // bloc en cours : { marker, choice, info, body }
        lines.forEach(function (line) {
            if (fence) {
                const close = line.match(/^[ \t]{0,3}(`{3,}|~{3,})[ \t]*$/);
                if (close && close[1].charAt(0) === fence.marker.charAt(0) && close[1].length >= fence.marker.length) {
                    if (fence.choice) out.push(choiceText(fence.info, fence.body.join('\n')));
                    else out.push(fence.open, ...fence.body, line);
                    fence = null;
                } else {
                    fence.body.push(line);
                }
                return;
            }
            const open = line.match(/^[ \t]{0,3}(`{3,}|~{3,})[ \t]*([^\n]*)$/);
            if (open && !(open[1].charAt(0) === '`' && open[2].indexOf('`') !== -1)) {
                fence = { marker: open[1], open: line, info: open[2], body: [], choice: parseInfo(open[2]) !== null };
                return;
            }
            out.push(line);
        });
        if (fence) {
            // Bloc jamais refermé (réponse interrompue) : rendu tel quel.
            if (fence.choice) out.push(choiceText(fence.info, fence.body.join('\n')));
            else out.push(fence.open, ...fence.body);
        }
        return out.join('\n');
    }

    function choiceText(info, body) {
        const choice = parse(info, body);
        if (!choice) return body;
        const lines = [];
        if (choice.question) lines.push(choice.question);
        choice.options.forEach(function (o) { lines.push('- ' + optionText(o)); });
        return lines.join('\n');
    }

    const api = {
        parseInfo: parseInfo,
        parseBody: parseBody,
        parse: parse,
        isCompact: isCompact,
        toPlainText: toPlainText,
        config: CONFIG,
    };

    // Sous node (tests) : seule la partie pure existe.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (typeof document === 'undefined') {
        root.MiaChoices = api;
        return;
    }

    // ------------------------------------------------------------------
    // 2. Rendu — une chaîne SANS état
    // ------------------------------------------------------------------

    const ICON_ARROW = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
    const ICON_CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    const ICON_BOX = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    const ICON_PEN = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
    const ICON_SEND = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>';

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function optionHtml(choice, option, index, compact) {
        let key = '';
        if (choice.multiple) {
            key = '<span class="choice-option-box" aria-hidden="true">' + ICON_BOX + '</span>';
        } else if (!compact) {
            key = '<span class="choice-option-key" aria-hidden="true">' + (index + 1) + '</span>';
        }
        return '<button type="button" class="choice-option" disabled data-value="' + esc(option.label) + '"'
            + (choice.multiple ? ' aria-pressed="false"' : '') + '>'
            + key
            + '<span class="choice-option-text"><span class="choice-option-label">' + esc(option.label) + '</span>'
            + (option.description ? '<span class="choice-option-desc">' + esc(option.description) + '</span>' : '')
            + '</span>'
            + (choice.multiple ? '' : '<span class="choice-option-mark" aria-hidden="true">'
                + '<span class="choice-mark-go">' + ICON_ARROW + '</span><span class="choice-mark-done">' + ICON_CHECK + '</span></span>')
            + '</button>';
    }

    /**
     * Carte de choix. Toujours INERTE à la sortie du rendu (boutons
     * `disabled`) : c'est syncChoicePrompts (chat.js) qui l'active, une fois
     * la réponse finie et seulement si personne n'y a encore répondu.
     */
    function cardHtml(choice, closed) {
        const compact = isCompact(choice);
        const label = choice.question || t('Choix proposés');

        let html = '<div class="choice-card' + (compact ? ' is-compact' : '') + (choice.multiple ? ' is-multiple' : '') + '"'
            + ' data-closed="' + (closed ? '1' : '0') + '" data-multiple="' + (choice.multiple ? '1' : '0') + '"'
            + ' role="group" aria-label="' + esc(label) + '">';

        if (choice.question || choice.multiple) {
            html += '<div class="choice-head">'
                + (choice.question ? '<p class="choice-question">' + esc(choice.question) + '</p>' : '')
                + (choice.multiple ? '<span class="choice-kicker">' + esc(t('Plusieurs choix possibles')) + '</span>' : '')
                + '</div>';
        }

        html += '<div class="choice-options">'
            + choice.options.map(function (o, i) { return optionHtml(choice, o, i, compact); }).join('')
            + '</div>';

        if (!closed) {
            html += '<div class="choice-pending"><span class="choice-pending-dot" aria-hidden="true"></span>'
                + esc(t('Préparation des choix…')) + '</div>';
        }

        html += '<div class="choice-foot">'
            + (choice.multiple
                ? '<button type="button" class="choice-submit" disabled>' + ICON_SEND
                    + '<span class="choice-submit-label">' + esc(t('Envoyer la sélection')) + '</span></button>'
                : '')
            + '<button type="button" class="choice-other" disabled title="' + esc(t('Écrire votre propre réponse dans la zone de saisie')) + '">'
            + ICON_PEN + '<span>' + esc(t('Autre réponse')) + '</span></button>'
            + '<span class="choice-status" role="status"></span>'
            + '</div>'
            + '</div>\n';

        return html;
    }

    /** Bloc refermé mais sans assez d'options : la question et la liste, en texte. */
    function fallbackHtml(choice) {
        let html = '';
        if (choice.question) html += '<p>' + esc(choice.question) + '</p>\n';
        if (choice.options.length) {
            html += '<ul>' + choice.options.map(function (o) { return '<li>' + esc(optionText(o)) + '</li>'; }).join('') + '</ul>\n';
        }
        return html;
    }

    // Le moteur Markdown appelle ce rendu pour CHAQUE bloc de code ; `false`
    // lui rend la main (generated-files.js, puis le rendu par défaut).
    if (root.marked && typeof root.marked.use === 'function') {
        root.marked.use({
            renderer: {
                code: function (token) {
                    if (!token || typeof token !== 'object') return false;
                    if (parseInfo(token.lang || '') === null) return false;

                    const files = root.MiaFiles;
                    const closed = files ? files.isClosedFence(token.raw) : true;
                    const parts = !closed && files ? files.splitInterruption(token.text) : { source: String(token.text || ''), note: '' };
                    const choice = parse(token.lang || '', parts.source);
                    const note = parts.note ? '<p><em>[' + esc(parts.note) + ']</em></p>\n' : '';

                    // Dans l'aperçu d'un document livré, ce n'est pas une
                    // question posée à l'utilisateur : du texte, sans bouton.
                    if (files && typeof files.isPreviewing === 'function' && files.isPreviewing()) return fallbackHtml(choice) + note;
                    if (closed && !choice.valid) return fallbackHtml(choice) + note;
                    return cardHtml(choice, closed) + note;
                },
            },
        });
    }

    // ------------------------------------------------------------------
    // 3. État d'une carte (décidé par chat.js) et interactions locales
    // ------------------------------------------------------------------

    function norm(text) {
        return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function options(card) {
        return Array.prototype.slice.call(card.querySelectorAll('.choice-option'));
    }

    /** Bouton « Envoyer la sélection » d'un choix multiple : actif dès une option retenue. */
    function syncSubmit(card) {
        const submit = card.querySelector('.choice-submit');
        if (!submit) return;
        const n = options(card).filter(function (o) { return o.getAttribute('aria-pressed') === 'true'; }).length;
        submit.disabled = card.dataset.state !== 'live' || n === 0;
        const label = submit.querySelector('.choice-submit-label');
        if (label) label.textContent = n === 0 ? t('Envoyer la sélection') : t('Envoyer ({n})', { n: n });
    }

    /**
     * Pose l'état d'une carte :
     *   - `pending`    la réponse s'écrit encore ;
     *   - `live`       dernière réponse, personne n'a répondu : boutons actifs ;
     *   - `sending`    un choix vient d'être envoyé ;
     *   - `answered`   une réponse a suivi — l'option envoyée est cochée ;
     *   - `superseded` une réponse plus récente porte d'autres choix ;
     *   - `incomplete` la réponse s'est arrêtée avant la fin du bloc.
     * `answer` = texte du message de l'utilisateur qui a suivi (état answered).
     */
    function applyState(card, state, answer) {
        if (!card || card.dataset.state === state) return;
        card.dataset.state = state;
        ['live', 'sending', 'answered', 'superseded', 'incomplete'].forEach(function (s) {
            card.classList.toggle('is-' + s, s === state);
        });

        const live = state === 'live';
        card.querySelectorAll('button').forEach(function (b) { b.disabled = !live; });
        // Le groupe n'est plus un contrôle : on ne s'y arrête pas au clavier.
        card.setAttribute('aria-disabled', live ? 'false' : 'true');

        if (state === 'answered') {
            const lines = String(answer || '').split('\n').map(norm).filter(Boolean);
            const whole = norm(answer);
            let found = false;
            options(card).forEach(function (o) {
                const value = norm(o.dataset.value);
                const chosen = value !== '' && (whole === value || lines.indexOf(value) !== -1);
                o.classList.toggle('is-chosen', chosen);
                if (card.dataset.multiple === '1') o.setAttribute('aria-pressed', chosen ? 'true' : 'false');
                found = found || chosen;
            });
            card.classList.toggle('is-written', !found);
        } else if (state !== 'sending') {
            options(card).forEach(function (o) { o.classList.remove('is-chosen'); });
            card.classList.remove('is-written');
        }

        const status = card.querySelector('.choice-status');
        if (status) {
            status.textContent = state === 'incomplete'
                ? t('Choix incomplets : la réponse s’est arrêtée avant la fin.')
                : '';
        }
        syncSubmit(card);
    }

    /** Choix multiple : bascule une option. */
    function toggle(card, option) {
        const on = option.getAttribute('aria-pressed') !== 'true';
        option.setAttribute('aria-pressed', on ? 'true' : 'false');
        syncSubmit(card);
    }

    /** Options retenues d'un choix multiple, dans l'ordre de la carte. */
    function selected(card) {
        return options(card).filter(function (o) { return o.getAttribute('aria-pressed') === 'true'; });
    }

    /**
     * Clavier dans une carte active : flèches pour passer d'une option à
     * l'autre (en boucle), chiffres 1 à 9 pour toucher l'option de ce rang.
     * Rend `true` si la touche a été prise.
     */
    function handleKey(card, event) {
        if (!card || card.dataset.state !== 'live') return false;
        const list = options(card);
        const current = list.indexOf(document.activeElement);
        const key = event.key;

        if (key === 'ArrowDown' || key === 'ArrowRight' || key === 'ArrowUp' || key === 'ArrowLeft') {
            if (current === -1) return false;
            const step = key === 'ArrowDown' || key === 'ArrowRight' ? 1 : -1;
            list[(current + step + list.length) % list.length].focus();
            return true;
        }
        if (/^[1-9]$/.test(key) && !event.ctrlKey && !event.metaKey && !event.altKey) {
            const target = list[Number(key) - 1];
            if (!target) return false;
            target.focus();
            target.click();
            return true;
        }
        return false;
    }

    /**
     * Copie et exports PDF / Word (neutralizeClipboardStyles, chat.js) : une
     * carte devient sa question et la liste de ses options — l'option choisie
     * reste marquée d'une coche.
     */
    function flattenForExport(scope) {
        if (!scope || !scope.querySelectorAll) return scope;
        scope.querySelectorAll('.choice-card').forEach(function (card) {
            const out = document.createElement('div');
            const question = card.querySelector('.choice-question');
            if (question) {
                const p = document.createElement('p');
                const strong = document.createElement('strong');
                strong.textContent = question.textContent;
                p.appendChild(strong);
                out.appendChild(p);
            }
            const ul = document.createElement('ul');
            options(card).forEach(function (o) {
                const li = document.createElement('li');
                const label = o.querySelector('.choice-option-label');
                const desc = o.querySelector('.choice-option-desc');
                li.textContent = (o.classList.contains('is-chosen') ? '✓ ' : '')
                    + (label ? label.textContent : '') + (desc ? ' — ' + desc.textContent : '');
                ul.appendChild(li);
            });
            out.appendChild(ul);
            card.parentNode.replaceChild(out, card);
        });
        return scope;
    }

    root.MiaChoices = Object.assign(api, {
        cardHtml: cardHtml,
        applyState: applyState,
        toggle: toggle,
        selected: selected,
        handleKey: handleKey,
        flattenForExport: flattenForExport,
    });
})(typeof window !== 'undefined' ? window : globalThis);
