/*
 * Volet de gauche — sections REPLIABLES (piste A, 2026-11-09) et rangement de
 * l'historique. SOURCE UNIQUE pour la page de discussion (chat.js →
 * regroupConvList y délègue) et le volet du chat des pages d'espace
 * (chat-shell-sidebar.php) : avant, l'historique était rangé par deux copies
 * du même code, à garder synchronisées.
 *
 *  - Sections : Espaces, Épinglées, Historique, et la carte du compte au pied
 *    du volet (`account`, 2026-11-10 : repliée, une rangée — avatar cerclé de
 *    ses crédits, Réglages et Admin en icônes). Un chevron chacune
 *    (`data-sb-toggle`, libellé suivant l'état si `data-label-fold|unfold`),
 *    et un bouton « Tout replier / Tout déplier » dans l'en-tête du volet
 *    (`data-sb-all`). État = `data-sb-<section>="closed"` sur <html>, posé
 *    aussi AVANT le premier rendu par sidebar-sections-boot.php (requis par
 *    head-meta.php). Par défaut, tout est DÉPLIÉ
 *    (SidebarPreferences::DEFAULTS, passés dans window.MIA_SIDEBAR).
 *  - Périodes de l'historique (Aujourd'hui, Hier, 7 derniers jours…) :
 *    chacune se replie (`data-sb-group`). Par défaut, seules Aujourd'hui et
 *    Hier sont dépliées — à défaut de l'une et l'autre, la période la plus
 *    récente — et celle de la conversation ouverte ; un choix de la personne
 *    l'emporte ensuite.
 *  - Mémoire PAR COMPTE, côté serveur (`users.ui_sidebar`, migration 109) :
 *    chaque geste envoie l'état à POST /settings/sidebar, et la page suivante
 *    le relit — sur cet appareil comme sur un autre. Seuls les choix sont
 *    gardés : une section jamais touchée suit le défaut. L'ancien état du
 *    navigateur (`miaSidebar:<id>` en localStorage) est repris une fois puis
 *    effacé.
 *
 * Chargé SANS defer juste après #convList : les groupes sont posés avant le
 * premier rendu (la liste arrive à plat du serveur). Sur les pages de
 * réglages et d'administration (sans historique), chargé par admin-nav.php :
 * il n'y sert qu'à la carte du compte. Aucun texte en dur hors window.t
 * (dictionnaire client, posé par head-meta.php).
 */
(function () {
    'use strict';

    var html = document.documentElement;
    var SECTIONS = ['spaces', 'pinned', 'history', 'account'];
    // Périodes ouvertes par défaut ; les autres se replient d'elles-mêmes.
    var OPEN_BY_DEFAULT = { today: true, yesterday: true };
    // Clés de période qui reviennent toujours ; les mois disparaissent avec le temps.
    var STABLE_GROUPS = { today: true, yesterday: true, '7d': true, '30d': true };

    var PIN_SVG = '<svg class="conv-group-pin" width="11" height="13" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M32 32C32 14.3 46.3 0 64 0L320 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-29.5 0 10.3 134.1c37.1 21.2 65.8 56.4 78.2 99.7l3.8 13.4c2.8 9.7 .8 20-5.2 28.1S362 352 352 352L32 352c-10 0-19.5-4.7-25.5-12.7s-8-18.4-5.2-28.1L5 297.8c12.4-43.3 41-78.5 78.2-99.7L93.5 64 64 64C46.3 64 32 49.7 32 32zM160 400l64 0 0 112c0 17.7-14.3 32-32 32s-32-14.3-32-32l0-112z"/></svg>';
    var CHEVRON = '<svg class="sb-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';

    // ---- Mémoire (compte) -----------------------------------------------
    // Posé dans <head> par sidebar-sections-boot.php : état gardé sur le
    // compte, défauts, jeton CSRF.
    var CFG = window.MIA_SIDEBAR || { state: {}, defaults: {}, saved: true };
    var DEFAULTS = CFG.defaults || {};
    var isObject = function (v) { return !!v && typeof v === 'object' && !Array.isArray(v); };
    var state = isObject(CFG.state) ? CFG.state : {};
    if (!isObject(state.groups)) state.groups = {};

    // Ancienne mémoire du navigateur, avant que l'état ne suive le compte.
    var userMeta = document.querySelector('meta[name="mia-user"]');
    var LEGACY_KEY = 'miaSidebar:' + ((userMeta && userMeta.content) || '0');
    function forgetLegacy() {
        try { localStorage.removeItem(LEGACY_KEY); } catch (e) { /* stockage indisponible */ }
    }

    // Envoie l'état ENTIER au compte. Envoyé tout de suite, jamais différé :
    // la requête de la page suivante attend la fin de celle-ci (session PHP),
    // un clic suivi d'une navigation retrouve donc le bon état. `keepalive` :
    // l'envoi survit à un changement de page immédiat.
    function save(onSaved) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = (meta && meta.content) || CFG.csrf || '';
        try {
            fetch('/settings/sidebar', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type':     'application/x-www-form-urlencoded',
                    'X-CSRF-Token':     token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept':           'application/json'
                },
                body: 'state=' + encodeURIComponent(JSON.stringify(state))
            }).then(function (r) {
                if (r.ok && onSaved) onSaved();
            }).catch(function () { /* hors ligne : le geste vaut pour cette page */ });
        } catch (e) { /* navigateur sans fetch : le geste vaut pour cette page */ }
    }

    // Une section non touchée suit le défaut (SidebarPreferences::DEFAULTS).
    function isOpen(section) {
        return typeof state[section] === 'boolean' ? state[section] : DEFAULTS[section] !== false;
    }

    // ---- Sections -------------------------------------------------------
    function applySections() {
        SECTIONS.forEach(function (k) {
            if (isOpen(k)) html.removeAttribute('data-sb-' + k);
            else html.setAttribute('data-sb-' + k, 'closed');
            document.querySelectorAll('[data-sb-toggle="' + k + '"]').forEach(function (b) {
                b.setAttribute('aria-expanded', isOpen(k) ? 'true' : 'false');
                var lbl = b.getAttribute(isOpen(k) ? 'data-label-fold' : 'data-label-unfold');
                if (lbl) { b.setAttribute('aria-label', lbl); b.setAttribute('title', lbl); }
            });
        });
        var any = SECTIONS.some(isOpen);
        document.querySelectorAll('[data-sb-all]').forEach(function (b) {
            var label = any ? b.getAttribute('data-label-fold') : b.getAttribute('data-label-unfold');
            if (label) { b.setAttribute('aria-label', label); b.setAttribute('title', label); }
            b.classList.toggle('is-folded', !any);
        });
    }

    // ---- Historique -----------------------------------------------------
    // Période d'une conversation (updated_at) → clé + libellé traduit.
    function bucket(ts, now) {
        var startOfDay = function (d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); };
        var day = 86400000;
        var today = startOfDay(now);
        var jour = startOfDay(ts);
        if (jour === today)           return { key: 'today',     label: window.t('Aujourd’hui') };
        if (jour === today - day)     return { key: 'yesterday', label: window.t('Hier') };
        if (jour >  today - 7 * day)  return { key: '7d',  label: window.t('7 derniers jours') };
        if (jour >  today - 30 * day) return { key: '30d', label: window.t('30 derniers jours') };
        // Mois dans la langue de la page (« Août 2026 », « August 2026 »).
        var lbl = ts.toLocaleDateString(html.lang || 'fr', { month: 'long', year: 'numeric' });
        return { key: 'm' + ts.getFullYear() + '-' + ts.getMonth(), label: lbl.charAt(0).toUpperCase() + lbl.slice(1) };
    }

    // Une période est-elle dépliée ? Le choix de la personne, sinon la règle.
    function groupOpen(key, ctx) {
        if (Object.prototype.hasOwnProperty.call(state.groups, key)) return !!state.groups[key];
        if (OPEN_BY_DEFAULT[key]) return true;
        if (ctx.active === key) return true;
        return !ctx.hasRecent && ctx.first === key;
    }

    function chevron() {
        var span = document.createElement('span');
        span.innerHTML = CHEVRON;
        return span.firstChild;
    }

    // Range la liste : Épinglées en tête, puis l'en-tête « Historique », puis
    // une période par groupe. Rejouable à volonté (ajout, suppression,
    // épinglage) : les items sont repris où qu'ils soient, les anciens groupes
    // retirés.
    function regroup(list) {
        if (!list) return;
        var items = Array.prototype.slice.call(list.querySelectorAll('.conv-item'));
        var old = list.querySelectorAll('.conv-group, .conv-group-label, .conv-history-head');
        if (!items.length) {
            Array.prototype.forEach.call(old, function (g) { g.remove(); });
            return;
        }
        var pinned = items.filter(function (i) { return i.classList.contains('is-pinned'); });
        var others = items.filter(function (i) { return !i.classList.contains('is-pinned'); })
            .sort(function (a, b) { return (Number(b.dataset.ts) || 0) - (Number(a.dataset.ts) || 0); });

        var now = new Date();
        var periods = [];
        others.forEach(function (item) {
            var b = bucket(new Date((Number(item.dataset.ts) || 0) * 1000), now);
            var last = periods[periods.length - 1];
            if (!last || last.key !== b.key) { last = { key: b.key, label: b.label, items: [] }; periods.push(last); }
            last.items.push(item);
        });
        var ctx = { active: null, first: periods.length ? periods[0].key : null, hasRecent: false };
        periods.forEach(function (p) {
            if (OPEN_BY_DEFAULT[p.key]) ctx.hasRecent = true;
            if (p.items.some(function (i) { return i.classList.contains('active'); })) ctx.active = p.key;
        });

        var frag = document.createDocumentFragment();
        var openGroup = function (key, label) {
            var group = document.createElement('div');
            group.className = 'conv-group' + (key === 'pinned' ? ' conv-group--pinned' : '');
            group.dataset.group = key;
            group.setAttribute('role', 'group');
            var head = document.createElement('button');
            head.type = 'button';
            head.className = 'conv-group-label';
            head.id = 'convGroup-' + key;
            group.setAttribute('aria-labelledby', head.id);
            if (key === 'pinned') {
                head.setAttribute('data-sb-toggle', 'pinned');
                head.setAttribute('aria-expanded', isOpen('pinned') ? 'true' : 'false');
                head.insertAdjacentHTML('beforeend', PIN_SVG);
            } else {
                head.setAttribute('data-sb-group', key);
            }
            var text = document.createElement('span');
            text.className = 'conv-group-name';
            text.textContent = label;
            head.appendChild(text);
            group.appendChild(head);
            frag.appendChild(group);
            return group;
        };
        var count = function (head, txt) {
            var c = document.createElement('span');
            c.className = 'conv-group-count';
            c.textContent = txt;
            head.appendChild(c);
            head.appendChild(chevron());
        };

        if (pinned.length) {
            var g = openGroup('pinned', window.t('Épinglées'));
            // Nombre sur le plafond (Conversation::MAX_PINNED, posé par le gabarit).
            var max = Number(list.dataset.maxPinned) || 0;
            count(g.firstChild, max ? pinned.length + '/' + max : String(pinned.length));
            pinned.forEach(function (i) { g.appendChild(i); });
        }
        if (periods.length) {
            var hist = document.createElement('button');
            hist.type = 'button';
            hist.className = 'conv-history-head';
            hist.setAttribute('data-sb-toggle', 'history');
            hist.setAttribute('aria-expanded', isOpen('history') ? 'true' : 'false');
            var title = document.createElement('span');
            title.className = 'conv-history-title';
            title.textContent = window.t('Historique');
            hist.appendChild(title);
            var n = document.createElement('span');
            n.className = 'conv-history-count';
            n.textContent = String(others.length);
            hist.appendChild(n);
            hist.appendChild(chevron());
            frag.appendChild(hist);
        }
        periods.forEach(function (p) {
            var grp = openGroup(p.key, p.label);
            var open = groupOpen(p.key, ctx);
            grp.classList.toggle('is-folded', !open);
            grp.firstChild.setAttribute('aria-expanded', open ? 'true' : 'false');
            count(grp.firstChild, String(p.items.length));
            p.items.forEach(function (i) { grp.appendChild(i); });
        });
        // Les items ont quitté les anciens groupes en rejoignant le fragment.
        Array.prototype.forEach.call(old, function (o) { o.remove(); });
        list.appendChild(frag);
    }

    function toggleGroup(head) {
        var group = head.closest('.conv-group');
        var key = head.getAttribute('data-sb-group');
        if (!group || !key) return;
        var open = group.classList.contains('is-folded');
        group.classList.toggle('is-folded', !open);
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
        state.groups[key] = open;
        // Les mois passés ne reviennent pas : on oublie ceux qui ne sont plus affichés.
        var shown = {};
        document.querySelectorAll('#convList [data-sb-group]').forEach(function (h) { shown[h.getAttribute('data-sb-group')] = true; });
        Object.keys(state.groups).forEach(function (k) {
            if (!STABLE_GROUPS[k] && !shown[k]) delete state.groups[k];
        });
        save();
    }

    // ---- Gestes ---------------------------------------------------------
    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('[data-sb-toggle], [data-sb-group], [data-sb-all]');
        // Carte du compte repliée : toucher l'avatar la redéplie (le chevron
        // reste la commande au clavier). Dépliée, l'avatar ne fait rien.
        if (!el && !isOpen('account') && e.target.closest && e.target.closest('.user-dock .user-info')) {
            state.account = true;
            save();
            applySections();
            return;
        }
        if (!el) return;
        e.preventDefault();
        if (el.hasAttribute('data-sb-all')) {
            var any = SECTIONS.some(isOpen);
            SECTIONS.forEach(function (k) { state[k] = !any; });
            save();
            applySections();
            return;
        }
        if (el.hasAttribute('data-sb-toggle')) {
            var k = el.getAttribute('data-sb-toggle');
            if (SECTIONS.indexOf(k) === -1) return;
            state[k] = !isOpen(k);
            save();
            applySections();
            return;
        }
        toggleGroup(el);
    });

    window.MiaSidebarSections = { regroup: regroup, apply: applySections };

    // L'état repris du navigateur (compte encore sans état) part au compte ;
    // l'ancienne clé n'est effacée qu'une fois l'envoi réussi. Un état déjà
    // gardé sur le compte rend l'ancienne clé inutile.
    if (CFG.adopt) {
        CFG.adopt = false;
        save(forgetLegacy);
    } else if (CFG.saved) {
        forgetLegacy();
    }

    regroup(document.getElementById('convList'));
    applySections();
    // La carte du compte suit la liste dans la page : ses commandes ne sont
    // pas encore là quand ce script s'exécute (chargé juste après #convList).
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applySections);
})();
