/* Personnes → Rôles (templates/admin/roles.php) — administrateurs seuls.
 *
 * ENREGISTREMENT MANUEL : rien ne part avant « Enregistrer ». Ce script :
 *   - rejoue les implications du catalogue (window.ROLE_PAGE.catalog, tiré de
 *     Permissions::clientCatalog) : cocher « Modifier » coche « Voir », décocher
 *     « Voir » décoche ce qui en dépend — la case touchée par implication pulse,
 *     pour qu'on la voie. Le serveur rejoue la même fermeture
 *     (Permissions::fromPost) quoi qu'on lui envoie : ceci n'est qu'un confort ;
 *   - compte les changements (anneau ambre sur chaque case changée), active
 *     Annuler / Enregistrer, prévient avant de quitter une page modifiée ;
 *   - tient « En résumé » à jour ;
 *   - pose les INFOBULLES (aucune légende dans la page) : ce que veut dire une
 *     colonne, ce que couvre une ligne, ce qu'ouvre une case, pourquoi une case
 *     est sans objet — une bulle au-dessus de l'élément, au survol, au focus
 *     clavier ou au toucher ;
 *   - demande confirmation avant d'enregistrer un retrait de droits quand des
 *     comptes portent le rôle ;
 *   - ouvre les fenêtres « Nouveau rôle » et « Supprimer ».
 * Textes affichés : t() / tn() (dictionnaire CLIENT, lang/en.client.php) ;
 * libellés du catalogue déjà traduits par le serveur (Permissions::clientCatalog).
 */
(function () {
    'use strict';

    var PAGE = window.ROLE_PAGE || {};
    var CAT = PAGE.catalog || { features: [], actions: {}, sections: {} };
    // Colonnes, dans l'ordre du catalogue (Permissions::ACTION_LABELS).
    var ACTIONS = Object.keys(CAT.actions || {});
    var form = document.getElementById('roleForm');
    var dirty = false;
    var leaving = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ------------------------------------------------------------------
    // Fenêtres « Nouveau rôle » et « Supprimer »
    // ------------------------------------------------------------------
    var lastOpener = null;

    function focusables(modal) {
        return Array.prototype.filter.call(
            modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])'),
            function (el) { return el.offsetParent !== null; }
        );
    }

    function openModal(modal, opener) {
        if (!modal) return;
        hideTip();
        lastOpener = opener || document.activeElement;
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        var first = modal.querySelector('input[type="text"], select, textarea') || focusables(modal)[0];
        if (first) window.setTimeout(function () { first.focus(); }, 30);
    }

    function closeModal(modal) {
        if (!modal || modal.style.display === 'none') return;
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        if (lastOpener && typeof lastOpener.focus === 'function') lastOpener.focus();
    }

    function openModals() {
        return Array.prototype.filter.call(document.querySelectorAll('.roles-modal-wrap'), function (m) {
            return m.style.display !== 'none';
        });
    }

    document.querySelectorAll('.roles-modal-wrap').forEach(function (modal) {
        // Un clic sur le voile ferme — seulement s'il y a commencé (sélectionner
        // du texte dans un champ et relâcher dehors ne ferme rien).
        var downOnBackdrop = false;
        modal.addEventListener('mousedown', function (e) { downOnBackdrop = e.target === modal; });
        modal.addEventListener('click', function (e) {
            if (e.target.closest('[data-close]') || (e.target === modal && downOnBackdrop)) {
                closeModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (e) {
        var open = openModals();
        if (open.length === 0) return;
        var modal = open[open.length - 1];
        if (e.key === 'Escape') {
            e.preventDefault();
            closeModal(modal);
            return;
        }
        if (e.key === 'Tab') {
            // Le focus reste dans la fenêtre.
            var items = focusables(modal);
            if (items.length === 0) return;
            var first = items[0];
            var last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    var newModal = document.getElementById('roleNewModal');
    var newBtn = document.getElementById('roleNewBtn');
    if (newBtn && newModal) {
        newBtn.addEventListener('click', function () { openModal(newModal, newBtn); });
    }
    var newForm = document.getElementById('roleNewForm');
    if (newForm) {
        newForm.addEventListener('submit', function (e) {
            if (dirty && !window.confirm(t('Les changements du rôle affiché ne sont pas enregistrés : ils seront perdus. Créer le nouveau rôle quand même ?'))) {
                e.preventDefault();
                return;
            }
            leaving = true;
        });
    }
    var deleteModal = document.getElementById('roleDeleteModal');
    var deleteBtn = document.getElementById('roleDeleteBtn');
    if (deleteBtn && deleteModal) {
        deleteBtn.addEventListener('click', function () { openModal(deleteModal, deleteBtn); });
    }

    // Boutons qui quittent la page (dupliquer, rétablir, supprimer) : confirmation
    // éventuelle, puis plus d'avertissement « quitter la page ? ».
    document.querySelectorAll('[data-leave]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            var question = btn.getAttribute('data-confirm');
            if (btn.hasAttribute('data-duplicate') && dirty) {
                question = t('Dupliquer reprend les droits ENREGISTRÉS de ce rôle : vos changements non enregistrés ne seront pas copiés. Continuer ?');
            }
            if (question && !window.confirm(question)) {
                e.preventDefault();
                return;
            }
            leaving = true;
        });
    });

    // Passer à un autre rôle avec des changements en cours : on le dit.
    document.querySelectorAll('[data-role-card]').forEach(function (card) {
        card.addEventListener('click', function (e) {
            if (!dirty) return;
            if (!window.confirm(t('Les changements de ce rôle ne sont pas enregistrés. Les abandonner ?'))) {
                e.preventDefault();
                return;
            }
            leaving = true;
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (!dirty || leaving) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ------------------------------------------------------------------
    // Infobulles. Source = l'élément caché que nomme `data-tip` (le même que
    // lit le lecteur d'écran par aria-describedby) ; la bulle se pose
    // AU-DESSUS de l'élément, dessous faute de place, et n'est qu'un écho
    // visuel (aria-hidden). Souris : survol, après un court délai (aussitôt
    // d'un élément à l'autre une fois une bulle ouverte). Clavier : au focus.
    // Doigt (aucun survol) : toucher l'en-tête, la ligne ou la case — elle
    // reste jusqu'au toucher suivant ; un second toucher sur un en-tête la
    // referme. Échap la referme partout.
    // ------------------------------------------------------------------
    var tipRoot = document.getElementById('roleEditor');
    var tip = null;
    var tipHost = null;
    var tipTimer = null;
    var tipPending = null;
    var TIP_DELAY = 240;
    var TIP_GAP = 9;
    var TIP_MARGIN = 8;

    function tipBubble() {
        if (tip) return tip;
        tip = document.createElement('div');
        tip.className = 'role-tip';
        tip.setAttribute('aria-hidden', 'true');
        tip.hidden = true;
        document.body.appendChild(tip);
        return tip;
    }

    function tipHostOf(node) {
        var host = node && node.closest ? node.closest('[data-tip]') : null;
        return host && tipRoot && tipRoot.contains(host) ? host : null;
    }

    // Le point visé, pour la flèche : la case cochable, le nom de la ligne,
    // l'icône de la colonne, sinon l'élément. La hauteur, elle, se lit sur
    // l'élément entier : la bulle ne le recouvre jamais.
    function tipAnchor(host) {
        return host.querySelector('.role-tick, .role-row-label, .role-col-ic') || host;
    }

    function placeTip() {
        if (!tip || !tipHost || tip.hidden) return;
        // Pixels d'ÉCRAN jusqu'à l'écriture, divisés alors par la taille
        // d'affichage (zoom de <html>, cf. head-meta.php).
        var z = window.miaUiZoom ? window.miaUiZoom() : 1;
        var r = tipHost.getBoundingClientRect();
        var a = tipAnchor(tipHost).getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;
        if (r.bottom < 0 || r.top > vh) { hideTip(); return; }
        var w = tip.offsetWidth * z;
        var h = tip.offsetHeight * z;
        var center = a.left + a.width / 2;
        var left = Math.max(TIP_MARGIN, Math.min(Math.round(center - w / 2), vw - w - TIP_MARGIN));
        var top = Math.round(r.top - h - TIP_GAP);
        var below = false;
        if (top < TIP_MARGIN) {
            top = Math.round(r.bottom + TIP_GAP);
            below = true;
        }
        top = Math.max(TIP_MARGIN, Math.min(top, vh - h - TIP_MARGIN));
        tip.style.left = left / z + 'px';
        tip.style.top = top / z + 'px';
        tip.style.setProperty('--tip-arrow', Math.round((center - left) / z) + 'px');
        tip.classList.toggle('is-below', below);
    }

    function showTip(host) {
        window.clearTimeout(tipTimer);
        tipPending = null;
        var src = document.getElementById(host.getAttribute('data-tip') || '');
        if (!src) return;
        var b = tipBubble();
        if (tipHost && tipHost !== host) tipHost.classList.remove('has-tip');
        b.innerHTML = src.innerHTML;
        b.hidden = false;
        tipHost = host;
        host.classList.add('has-tip');
        placeTip();
        b.classList.add('is-visible');
    }

    function hideTip() {
        window.clearTimeout(tipTimer);
        tipPending = null;
        if (tipHost) tipHost.classList.remove('has-tip');
        tipHost = null;
        if (!tip) return;
        tip.classList.remove('is-visible');
        tip.hidden = true;
    }

    function tipShown() {
        return !!(tip && !tip.hidden && tipHost);
    }

    if (tipRoot) {
        tipRoot.addEventListener('pointerover', function (e) {
            if (e.pointerType === 'touch') return;
            var host = tipHostOf(e.target);
            if (host && host === tipPending) return; // d'un enfant à l'autre du même élément
            window.clearTimeout(tipTimer);
            tipPending = null;
            if (host === tipHost) return;
            if (!host) {
                tipTimer = window.setTimeout(hideTip, 80);
                return;
            }
            tipPending = host;
            tipTimer = window.setTimeout(function () { showTip(host); }, tipShown() ? 40 : TIP_DELAY);
        });
        tipRoot.addEventListener('pointerleave', function (e) {
            if (e.pointerType === 'touch') return;
            window.clearTimeout(tipTimer);
            tipPending = null;
            tipTimer = window.setTimeout(hideTip, 80);
        });
        // Doigt : toucher affiche ; un second toucher sur le même en-tête
        // referme (une case, elle, se coche et se décoche : sa bulle reste).
        tipRoot.addEventListener('pointerup', function (e) {
            if (e.pointerType !== 'touch') return;
            var host = tipHostOf(e.target);
            if (!host) return;
            if (host === tipHost && tipShown() && host.matches('button')) {
                hideTip();
                return;
            }
            showTip(host);
        });
        // Souris : cliquer un en-tête l'ouvre sans attendre le délai.
        tipRoot.addEventListener('click', function (e) {
            var host = tipHostOf(e.target);
            if (host && host.matches('button') && e.detail > 0 && !tipShown()) showTip(host);
        });
        // Clavier : la bulle suit le focus visible.
        tipRoot.addEventListener('focusin', function (e) {
            var host = tipHostOf(e.target);
            if (host && e.target.matches(':focus-visible')) showTip(host);
        });
        tipRoot.addEventListener('focusout', function (e) {
            if (!tipHost) return;
            if (e.relatedTarget && tipHost.contains(e.relatedTarget)) return;
            if (tipHost.contains(e.target)) hideTip();
        });
    }
    // Un toucher ou un clic ailleurs referme.
    document.addEventListener('pointerdown', function (e) {
        if (!tipShown()) return;
        if (tipHostOf(e.target) === tipHost) return;
        hideTip();
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && tipShown()) hideTip();
    });
    // Elle suit l'élément quand la page défile (ou se referme s'il sort de l'écran).
    var tipFrame = 0;
    function followTip() {
        if (!tipShown() || tipFrame) return;
        tipFrame = window.requestAnimationFrame(function () {
            tipFrame = 0;
            placeTip();
        });
    }
    window.addEventListener('scroll', followTip, true);
    window.addEventListener('resize', followTip);

    if (PAGE.openNew && newModal) {
        openModal(newModal, newBtn);
    } else if (/[?&]role=/.test(window.location.search) && window.matchMedia('(max-width: 1239px)').matches) {
        // Tablette, téléphone : la liste est au-dessus — on amène l'éditeur à
        // l'écran. Sur mobile c'est `.admin-content` qui défile, pas la fenêtre.
        var editor = document.getElementById('roleEditor');
        var scroller = document.querySelector('.admin-content');
        if (editor && scroller && scroller.scrollHeight > scroller.clientHeight + 1
            && /(auto|scroll)/.test(window.getComputedStyle(scroller).overflowY)) {
            scroller.scrollTop += editor.getBoundingClientRect().top - scroller.getBoundingClientRect().top - 8;
        } else if (editor) {
            window.scrollTo(0, editor.getBoundingClientRect().top + window.pageYOffset - 16);
        }
    }

    if (!form) return;
    var locked = form.getAttribute('data-locked') === '1';

    // ------------------------------------------------------------------
    // Catalogue : implications (requires) et leur réciproque (dependents)
    // ------------------------------------------------------------------
    var features = {};
    var requires = {};
    var dependents = {};
    (CAT.features || []).forEach(function (f) {
        features[f.key] = f;
        var req = f.requires || {};
        Object.keys(req).forEach(function (action) {
            var key = f.key + '.' + action;
            (req[action] || []).forEach(function (needed) {
                var neededKey = f.key + '.' + needed;
                (requires[key] = requires[key] || []).push(neededKey);
                (dependents[neededKey] = dependents[neededKey] || []).push(key);
            });
        });
    });

    var boxes = {};
    form.querySelectorAll('.role-cb[data-key]').forEach(function (cb) {
        boxes[cb.getAttribute('data-key')] = cb;
    });

    function splitKey(key) {
        var i = key.indexOf('.');
        return [key.slice(0, i), key.slice(i + 1)];
    }

    function isOn(key) {
        return !!(boxes[key] && boxes[key].checked);
    }

    function setOn(key, on) {
        var cb = boxes[key];
        if (!cb || cb.checked === on) return false;
        cb.checked = on;
        return true;
    }

    function holderOf(key) {
        return boxes[key] ? boxes[key].closest('.role-cell-td') : null;
    }

    function pulse(key) {
        var el = holderOf(key);
        if (!el) return;
        el.classList.remove('is-implied');
        void el.offsetWidth; // relance l'animation
        el.classList.add('is-implied');
        window.setTimeout(function () { el.classList.remove('is-implied'); }, 950);
    }

    function propagate(key, on) {
        (on ? (requires[key] || []) : (dependents[key] || [])).forEach(function (other) {
            if (setOn(other, on)) {
                pulse(other);
                propagate(other, on);
            }
        });
    }

    function grantedActions(featureKey) {
        var def = features[featureKey];
        if (!def) return [];
        return ACTIONS.filter(function (a) {
            return def.actions && def.actions[a] !== undefined && isOn(featureKey + '.' + a);
        });
    }

    function labelOf(key) {
        var p = splitKey(key);
        var def = features[p[0]];
        if (!def) return key;
        return def.label + ' — ' + ((CAT.actions || {})[p[1]] || p[1]);
    }

    // ------------------------------------------------------------------
    // État : instantané initial, changements, résumé
    // ------------------------------------------------------------------
    function snapshot() {
        var s = { keys: {}, fields: {} };
        Object.keys(boxes).forEach(function (k) { s.keys[k] = boxes[k].checked; });
        form.querySelectorAll('[data-track]').forEach(function (el) {
            if (el.type === 'radio') {
                if (el.checked) s.fields[el.name] = el.value;
            } else {
                s.fields[el.name] = el.value;
            }
        });
        return s;
    }

    var initial = snapshot();

    function changes() {
        var now = snapshot();
        var out = { keys: [], fields: [] };
        Object.keys(initial.keys).forEach(function (k) {
            if (initial.keys[k] !== now.keys[k]) out.keys.push(k);
        });
        Object.keys(initial.fields).forEach(function (n) {
            if (initial.fields[n] !== now.fields[n]) out.fields.push(n);
        });
        return out;
    }

    var summaryEl = document.getElementById('roleSummary');
    var dirtyEl = document.getElementById('roleDirty');
    var saveBtn = document.getElementById('roleSaveBtn');
    var cancelBtn = document.getElementById('roleCancelBtn');
    var bar = document.getElementById('roleActionsBar');

    function renderSummary() {
        if (!summaryEl || locked) return; // l'administrateur garde sa phrase : il a tout
        var html = '';
        Object.keys(CAT.sections || {}).forEach(function (section) {
            var chips = [];
            (CAT.features || []).forEach(function (f) {
                if (f.section !== section) return;
                var acts = grantedActions(f.key);
                if (acts.length === 0) return;
                var declared = ACTIONS.filter(function (a) { return f.actions[a] !== undefined; });
                var what = acts.length === declared.length && declared.length > 1
                    ? t('tous les droits')
                    : acts.map(function (a) { return String((CAT.actions || {})[a] || a).toLowerCase(); }).join(', ');
                chips.push('<span class="role-sum-chip">' + esc(f.label) + ' <em>' + esc(what) + '</em></span>');
            });
            var none = section === 'admin'
                ? t('Aucun écran d’administration.')
                : t('Aucune : discuter avec MIA, sans assistant, base, outil ni connecteur.');
            html += '<div class="role-sum-group"><span class="role-sum-title">' + esc(CAT.sections[section].label) + '</span>'
                + (chips.length > 0
                    ? '<span class="role-sum-chips">' + chips.join('') + '</span>'
                    : '<span class="role-sum-none">' + esc(none) + '</span>')
                + '</div>';
        });
        summaryEl.innerHTML = html;
    }

    function refresh() {
        var ch = changes();
        var changed = {};
        ch.keys.forEach(function (k) { changed[k] = true; });
        Object.keys(boxes).forEach(function (k) {
            var td = boxes[k].closest('.role-cell-td');
            if (td) td.classList.toggle('is-changed', !!changed[k]);
        });
        form.querySelectorAll('.role-row[data-feature]').forEach(function (row) {
            row.classList.toggle('is-on', grantedActions(row.getAttribute('data-feature')).length > 0);
        });

        var n = ch.keys.length + ch.fields.length;
        dirty = n > 0;
        if (dirtyEl) {
            dirtyEl.textContent = n === 0 ? '' : tn(n, '{n} changement non enregistré', '{n} changements non enregistrés');
        }
        if (saveBtn) saveBtn.disabled = !dirty;
        if (cancelBtn) cancelBtn.disabled = !dirty;
        if (bar) bar.classList.toggle('is-dirty', dirty);
        renderSummary();
    }

    // Couleur et nom d'un rôle personnalisé : aperçu immédiat (carte de la
    // liste, en-tête de l'éditeur).
    function applyIdentity() {
        var color = form.querySelector('input[name="color"]:checked');
        var current = document.querySelector('.role-card.is-current');
        if (color) {
            var editor = form.closest('.roles-editor');
            if (editor) editor.style.setProperty('--role-color', color.value);
            if (current) current.style.setProperty('--role-color', color.value);
        }
        var name = document.getElementById('roleName');
        var cardName = current ? current.querySelector('.role-card-name-text') : null;
        if (name && cardName) cardName.textContent = name.value.trim() || PAGE.name || '';
    }

    if (locked) return; // l'administrateur : rien ne se coche

    form.addEventListener('change', function (e) {
        var el = e.target;
        if (el.matches('.role-cb')) {
            propagate(el.getAttribute('data-key'), el.checked);
        }
        if (el.hasAttribute('data-track')) applyIdentity();
        refresh();
    });
    form.addEventListener('input', function (e) {
        if (e.target.hasAttribute('data-track')) {
            applyIdentity();
            refresh();
        }
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            Object.keys(initial.keys).forEach(function (k) { setOn(k, initial.keys[k]); });
            form.querySelectorAll('[data-track]').forEach(function (el) {
                if (el.type === 'radio') {
                    el.checked = initial.fields[el.name] === el.value;
                } else if (Object.prototype.hasOwnProperty.call(initial.fields, el.name)) {
                    el.value = initial.fields[el.name];
                }
            });
            applyIdentity();
            refresh();
        });
    }

    form.addEventListener('submit', function (e) {
        var ch = changes();
        if (ch.keys.length + ch.fields.length === 0) {
            e.preventDefault();
            return;
        }
        // Des comptes portent ce rôle et des droits partent : on le dit, droit
        // par droit, avant d'enregistrer.
        var members = parseInt(form.getAttribute('data-members') || '0', 10) || 0;
        var removed = ch.keys.filter(function (k) { return initial.keys[k] && !isOn(k); });
        if (members > 0 && removed.length > 0) {
            var shown = removed.slice(0, 6).map(labelOf);
            var more = removed.length - shown.length;
            var question = tn(members, '{n} compte porte ce rôle. Il perdra :', '{n} comptes portent ce rôle. Ils perdront :')
                + '\n\n• ' + shown.join('\n• ')
                + (more > 0 ? '\n• ' + tn(more, '… et {n} autre', '… et {n} autres') : '')
                + '\n\n' + t('Enregistrer ces droits ?');
            if (!window.confirm(question)) {
                e.preventDefault();
                return;
            }
        }
        leaving = true;
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = t('Enregistrement…');
        }
    });

    // Retour arrière depuis la page suivante (cache du navigateur) : la page
    // revient telle quelle, bouton « Enregistrement… » compris.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        leaving = false;
        if (saveBtn) saveBtn.textContent = t('Enregistrer');
        refresh();
    });

    refresh();
})();
