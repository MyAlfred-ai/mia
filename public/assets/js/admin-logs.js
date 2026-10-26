/* Journaux (Maintenance → Diagnostic → Journaux).
 *
 * Tout est rendu par le serveur ; ce script ajoute : le fuseau horaire du
 * navigateur (cookie), le suivi en direct, « Afficher plus », le surlignage
 * de la recherche, la copie, les bulles de la frise et la navigation au
 * clavier. Sans lui, la page reste entièrement utilisable (liens et formulaires).
 *
 * Textes affichés : t() / tn() (dictionnaire CLIENT, lang/en.client.php) ;
 * nombres selon la langue de la page (window.I18N.lang).
 */
(function () {
    'use strict';

    var root = document.getElementById('lgx');
    if (!root) return;

    // ── Fuseau horaire : le serveur tourne en UTC, la personne non. ──
    var browserTz = '';
    try { browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { browserTz = ''; }
    if (browserTz && browserTz !== root.dataset.tz) {
        document.cookie = root.dataset.cookie + '=' + encodeURIComponent(browserTz)
            + '; path=/admin/logs; max-age=31536000; SameSite=Lax';
        var guardKey = 'miaLogsTz';
        var already = null;
        try { already = sessionStorage.getItem(guardKey); } catch (e) { already = browserTz; }
        if (already !== browserTz) {
            try { sessionStorage.setItem(guardKey, browserTz); } catch (e) { /* navigation privée */ }
            location.reload();
            return;
        }
    }

    var flux = document.getElementById('lgxFlux');
    var view = root.dataset.view;

    // Langue de la page (window.I18N, posé par head-meta) : nombres.
    var EN = !!(window.I18N && window.I18N.lang === 'en');
    var LOCALE = EN ? 'en-US' : 'fr-FR';
    function fmt(n) { return Number(n).toLocaleString(LOCALE); }

    // ── Temps relatif (mêmes tournures que le serveur) ──
    function relative(ts) {
        var sec = Date.now() / 1000 - ts;
        if (sec < 45) return t('à l’instant');
        if (sec < 3600) return t('il y a {n} min', { n: Math.max(1, Math.round(sec / 60)) });
        if (sec < 86400) return t('il y a {n} h', { n: Math.round(sec / 3600) });
        return t('il y a {n} j', { n: Math.round(sec / 86400) });
    }
    function refreshRelative() {
        root.querySelectorAll('[data-rel-ts]').forEach(function (el) {
            el.textContent = relative(Number(el.getAttribute('data-rel-ts')));
        });
        var mtime = document.getElementById('lgxMtime');
        if (mtime && mtime.dataset.ts) mtime.textContent = relative(Number(mtime.dataset.ts));
    }
    setInterval(refreshRelative, 30000);

    // ── Surlignage des termes recherchés (nœuds texte seulement : aucun HTML injecté) ──
    var terms = (root.dataset.search || '').split('\n').filter(Boolean);
    var termRx = terms.length
        ? new RegExp('(' + terms.map(function (term) { return term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|') + ')', 'gi')
        : null;
    function highlight(scope) {
        if (!termRx) return;
        scope.querySelectorAll('.lgx-msg, .lgx-pre, .lgx-issue-text, .lgx-frame-call').forEach(function (el) {
            if (el.dataset.marked) return;
            el.dataset.marked = '1';
            var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
            var nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            nodes.forEach(function (node) {
                var text = node.nodeValue;
                termRx.lastIndex = 0;
                if (!termRx.test(text)) return;
                termRx.lastIndex = 0;
                var frag = document.createDocumentFragment();
                var last = 0;
                text.replace(termRx, function (m, _g, idx) {
                    if (idx > last) frag.appendChild(document.createTextNode(text.slice(last, idx)));
                    var mark = document.createElement('mark');
                    mark.textContent = m;
                    frag.appendChild(mark);
                    last = idx + m.length;
                    return m;
                });
                if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
                node.parentNode.replaceChild(frag, node);
            });
        });
    }
    highlight(root);

    // ── Entrée visée par l'adresse (#t<horodatage>) : ouverte et montrée ──
    function focusHash() {
        var m = /^#t(\d+)$/.exec(location.hash || '');
        if (!m) return;
        var entry = root.querySelector('.lgx-entry[data-ts="' + m[1] + '"]');
        if (!entry) return;
        entry.open = true;
        entry.classList.add('is-focus');
        entry.scrollIntoView({ block: 'center' });
    }
    focusHash();

    // ── Copier ──
    function copyText(text, btn) {
        var done = function () {
            var prev = btn.textContent;
            btn.classList.add('is-done');
            btn.textContent = t('Copié');
            setTimeout(function () { btn.classList.remove('is-done'); btn.textContent = prev; }, 1400);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text); done(); });
        } else {
            fallbackCopy(text);
            done();
        }
    }
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* rien à faire */ }
        document.body.removeChild(ta);
    }
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-copy], [data-copy-link]');
        if (!btn) return;
        e.preventDefault();
        if (btn.hasAttribute('data-copy-link')) {
            copyText(location.origin + btn.getAttribute('data-copy-link'), btn);
            return;
        }
        var raw = btn.closest('.lgx-entry').querySelector('[data-raw]');
        copyText(raw ? raw.textContent : '', btn);
    });

    // ── Bulle de la frise (positions d'écran ramenées au zoom d'affichage) ──
    var tip = document.getElementById('lgxTip');
    function zoom() { return typeof window.miaUiZoom === 'function' ? (window.miaUiZoom() || 1) : 1; }
    function showTip(bar) {
        if (!tip) return;
        tip.textContent = bar.getAttribute('data-tip') || '';
        tip.hidden = false;
        var z = zoom();
        var r = bar.getBoundingClientRect();
        var w = tip.offsetWidth * z;
        var hgt = tip.offsetHeight * z;
        var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
        var top = r.top - hgt - 8;
        if (top < 8) top = r.bottom + 8;
        tip.style.left = (left / z) + 'px';
        tip.style.top = (top / z) + 'px';
    }
    function hideTip() { if (tip) tip.hidden = true; }
    root.addEventListener('pointerover', function (e) {
        var bar = e.target.closest('.lgx-bar');
        if (bar && e.pointerType !== 'touch') showTip(bar);
    });
    root.addEventListener('pointerout', function (e) {
        if (e.target.closest('.lgx-bar') && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('.lgx-bar') === e.target.closest('.lgx-bar'))) hideTip();
    });
    root.addEventListener('focusin', function (e) {
        var bar = e.target.closest('.lgx-bar');
        if (bar) showTip(bar); else hideTip();
    });
    window.addEventListener('scroll', hideTip, true);

    // ── Période personnalisée : se referme d'un clic ailleurs ──
    var custom = root.querySelector('.lgx-custom');
    document.addEventListener('click', function (e) {
        if (custom && custom.open && !custom.contains(e.target) && !custom.querySelector('.lgx-seg.is-active')) custom.open = false;
    });

    // ── Clavier : « / » cherche, j / k parcourent, Échap sort du champ ──
    var search = document.getElementById('lgxQ');
    function focusables() {
        return Array.prototype.slice.call(root.querySelectorAll(view === 'flux' ? '.lgx-entry-row' : '.lgx-issue-title'));
    }
    document.addEventListener('keydown', function (e) {
        var target = e.target;
        var typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
        if (e.key === 'Escape' && target === search) { search.blur(); return; }
        if (typing || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key === '/' && search) {
            e.preventDefault();
            search.focus();
            search.select();
            return;
        }
        if (e.key === 'j' || e.key === 'k') {
            var list = focusables();
            if (!list.length) return;
            var idx = list.indexOf(document.activeElement);
            idx = e.key === 'j' ? Math.min(list.length - 1, idx + 1) : Math.max(0, idx - 1);
            e.preventDefault();
            list[idx].focus();
            list[idx].scrollIntoView({ block: 'nearest' });
        }
    });

    // ── Requêtes vers /admin/logs/feed : filtres de l'adresse courante ──
    function feedUrl(extra) {
        var params = new URLSearchParams(location.search);
        params.delete('export');
        Object.keys(extra).forEach(function (k) { params.set(k, extra[k]); });
        return '/admin/logs/feed?' + params.toString();
    }
    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
    }
    function fragment(html) {
        var tpl = document.createElement('template');
        tpl.innerHTML = html;
        return tpl.content;
    }
    function lastDay() {
        var days = flux ? flux.querySelectorAll('.lgx-day') : [];
        return days.length ? days[days.length - 1].getAttribute('data-day') : '';
    }
    function topDay() {
        var day = flux ? flux.querySelector('.lgx-day') : null;
        return day ? day.getAttribute('data-day') : '';
    }

    // ── Afficher plus ──
    var more = document.getElementById('lgxMore');
    if (more && flux) {
        more.addEventListener('click', function () {
            more.disabled = true;
            getJson(feedUrl({ offset: more.dataset.offset, jour: lastDay() })).then(function (data) {
                var frag = fragment(data.html || '');
                var before = flux.lastElementChild;
                flux.appendChild(frag);
                highlight(flux);
                if (data.next === null || data.next === undefined) {
                    more.parentNode.remove();
                } else {
                    more.dataset.offset = data.next;
                    var rest = Math.max(0, (data.total || 0) - data.next);
                    var span = more.querySelector('.lgx-muted');
                    if (span) span.textContent = '· ' + tn(rest, '{nb} restante', '{nb} restantes', { nb: fmt(rest) });
                    more.disabled = false;
                }
                var next = before ? before.nextElementSibling : flux.firstElementChild;
                var row = next && (next.matches('.lgx-entry') ? next : next.nextElementSibling);
                if (row && row.querySelector) {
                    var summary = row.querySelector('.lgx-entry-row');
                    if (summary) summary.focus({ preventScroll: true });
                }
            }).catch(function () {
                more.disabled = false;
            });
        });
    }

    // ── Suivi en direct ──
    var liveBtn = document.getElementById('lgxLive');
    var liveBar = document.getElementById('lgxLiveBar');
    var liveText = document.getElementById('lgxLiveText');
    var size = Number(root.dataset.size || 0);
    var liveTimer = null;
    var liveOn = false;
    var seen = { total: 0, problems: 0 };
    var LIVE_KEY = 'miaLogsLive';
    var POLL_MS = 5000;

    function setLive(on) {
        liveOn = on && liveBtn && !liveBtn.disabled;
        if (liveBtn) {
            liveBtn.setAttribute('aria-pressed', liveOn ? 'true' : 'false');
            liveBtn.querySelector('.lgx-live-label').textContent = liveOn ? t('En direct · actif') : t('En direct');
        }
        try { localStorage.setItem(LIVE_KEY, liveOn ? '1' : '0'); } catch (e) { /* stockage indisponible */ }
        clearTimeout(liveTimer);
        if (liveOn) schedule(800);
    }
    function schedule(ms) {
        clearTimeout(liveTimer);
        liveTimer = setTimeout(poll, ms);
    }
    function announce(text, showReload) {
        if (!liveBar) return;
        liveText.textContent = text;
        liveBar.querySelector('.lgx-livebar-reload').hidden = !showReload;
        liveBar.hidden = false;
    }
    // « 3 nouvelles entrées », « 1 erreur » : les morceaux des annonces.
    function liveCounts() {
        return {
            entrees: tn(seen.total, '{nb} nouvelle entrée', '{nb} nouvelles entrées', { nb: fmt(seen.total) }),
            erreurs: tn(seen.problems, '{nb} erreur', '{nb} erreurs', { nb: String(seen.problems) })
        };
    }
    function poll() {
        if (!liveOn) return;
        if (document.hidden) { schedule(POLL_MS); return; }
        getJson(feedUrl({ after: size, jour: topDay() })).then(function (data) {
            if (data.reload) {
                announce(t('Le journal a été remplacé ou a beaucoup grossi depuis l’ouverture de la page.'), true);
                size = Number(data.size || size);
                schedule(POLL_MS);
                return;
            }
            size = Number(data.size || size);
            if (data.mtime) {
                var mt = document.getElementById('lgxMtime');
                if (mt) { mt.dataset.ts = data.mtime; mt.textContent = relative(Number(data.mtime)); }
            }
            if (data.count > 0) {
                seen.total += data.count;
                seen.problems += (data.levels.critical || 0) + (data.levels.error || 0);
                if (view === 'flux' && flux) {
                    var empty = root.querySelector('.lgx-empty');
                    if (empty) empty.remove();
                    var firstDay = flux.querySelector('.lgx-day');
                    if (data.html_day && firstDay) {
                        firstDay.after(fragment(data.html_day));
                    }
                    if (data.html_new) {
                        flux.insertBefore(fragment(data.html_new), flux.firstChild);
                    }
                    flux.querySelectorAll('.lgx-entry:not([data-live-seen])').forEach(function (el, i) {
                        el.setAttribute('data-live-seen', '1');
                        if (i < data.count) el.classList.add('is-new');
                    });
                    highlight(flux);
                    announce(seen.problems
                        ? t('{entrees} dont {erreurs} depuis l’activation du suivi. Compteurs et frise : actualisez.', liveCounts())
                        : t('{entrees} depuis l’activation du suivi. Compteurs et frise : actualisez.', liveCounts()), true);
                } else {
                    announce(seen.problems
                        ? t('{entrees} dont {erreurs} — actualisez pour mettre les problèmes à jour.', liveCounts())
                        : t('{entrees} — actualisez pour mettre les problèmes à jour.', liveCounts()), true);
                }
            }
            schedule(POLL_MS);
        }).catch(function () {
            schedule(POLL_MS * 3);
        });
    }
    if (liveBtn) {
        // Les entrées déjà là ne sont pas « nouvelles ».
        if (flux) flux.querySelectorAll('.lgx-entry').forEach(function (el) { el.setAttribute('data-live-seen', '1'); });
        liveBtn.addEventListener('click', function () { setLive(!liveOn); });
        var stored = null;
        try { stored = localStorage.getItem(LIVE_KEY); } catch (e) { stored = null; }
        if (stored === '1') setLive(true);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && liveOn) schedule(300);
        });
    }
})();
