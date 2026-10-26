/*
 * Volet du chat sur les pages des espaces du créateur (NavShell,
 * templates/partials/chat-shell-sidebar.php) : Assistants, Tâches programmées,
 * Workflows, Bases de connaissances atteints depuis la discussion.
 *
 * chat.js n'est pas chargé sur ces pages : ce script rejoue SEULEMENT ce qui
 * donne au volet son aspect — historique rangé en groupes (Épinglées, puis
 * par période). La recherche et ⌘K / Ctrl+K sont ceux de la palette
 * (command-palette.js, incluse par admin-nav.php).
 * RÉPLIQUE de convDateBucket / regroupConvList (chat.js) : à garder
 * synchronisée.
 */
(function () {
    'use strict';

    var tr = typeof window.t === 'function' ? window.t : function (s) { return s; };
    var PIN_SVG = '<svg class="conv-group-pin" width="11" height="13" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M32 32C32 14.3 46.3 0 64 0L320 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-29.5 0 10.3 134.1c37.1 21.2 65.8 56.4 78.2 99.7l3.8 13.4c2.8 9.7 .8 20-5.2 28.1S362 352 352 352L32 352c-10 0-19.5-4.7-25.5-12.7s-8-18.4-5.2-28.1L5 297.8c12.4-43.3 41-78.5 78.2-99.7L93.5 64 64 64C46.3 64 32 49.7 32 32zM160 400l64 0 0 112c0 17.7-14.3 32-32 32s-32-14.3-32-32l0-112z"/></svg>';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function bucket(ts, now) {
        var startOfDay = function (d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); };
        var day = 86400000;
        var today = startOfDay(now);
        var jour = startOfDay(ts);
        if (jour === today)           return { key: 'today',     label: tr('Aujourd’hui') };
        if (jour === today - day)     return { key: 'yesterday', label: tr('Hier') };
        if (jour >  today - 7 * day)  return { key: '7d',  label: tr('7 derniers jours') };
        if (jour >  today - 30 * day) return { key: '30d', label: tr('30 derniers jours') };
        var lbl = ts.toLocaleDateString(document.documentElement.lang || 'fr', { month: 'long', year: 'numeric' });
        return { key: 'm' + ts.getFullYear() + '-' + ts.getMonth(), label: lbl.charAt(0).toUpperCase() + lbl.slice(1) };
    }

    function regroup(list) {
        var items = Array.prototype.slice.call(list.querySelectorAll('.conv-item'));
        if (!items.length) return;
        var pinned = items.filter(function (i) { return i.classList.contains('is-pinned'); });
        var others = items.filter(function (i) { return !i.classList.contains('is-pinned'); })
            .sort(function (a, b) { return (Number(b.dataset.ts) || 0) - (Number(a.dataset.ts) || 0); });
        var now = new Date();
        var frag = document.createDocumentFragment();
        function openGroup(key, label) {
            var group = document.createElement('div');
            group.className = 'conv-group' + (key === 'pinned' ? ' conv-group--pinned' : '');
            group.dataset.group = key;
            group.setAttribute('role', 'group');
            var head = document.createElement('div');
            head.className = 'conv-group-label';
            head.id = 'convGroup-' + key;
            group.setAttribute('aria-labelledby', head.id);
            if (key === 'pinned') head.insertAdjacentHTML('beforeend', PIN_SVG);
            var text = document.createElement('span');
            text.textContent = label;
            head.appendChild(text);
            group.appendChild(head);
            frag.appendChild(group);
            return group;
        }
        if (pinned.length) {
            var g = openGroup('pinned', tr('Épinglées'));
            var max = Number(list.dataset.maxPinned) || 0;
            var count = document.createElement('span');
            count.className = 'conv-group-count';
            count.textContent = max ? pinned.length + '/' + max : String(pinned.length);
            g.firstChild.appendChild(count);
            pinned.forEach(function (i) { g.appendChild(i); });
        }
        var curKey = null, group = null;
        others.forEach(function (item) {
            var b = bucket(new Date((Number(item.dataset.ts) || 0) * 1000), now);
            if (b.key !== curKey) { group = openGroup(b.key, b.label); curKey = b.key; }
            group.appendChild(item);
        });
        list.appendChild(frag);
    }

    function init() {
        var list = document.getElementById('convList');
        if (!list || !list.closest('.admin-sidebar--chat')) return;
        regroup(list);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
