/*
 * Historique des exécutions — l'onglet de /workflows (?vue=historique),
 * posé par templates/workflows/partials/history.php.
 *
 * À gauche les lancements (GET /workflows/history), à droite le journal de
 * celui qu'on choisit (GET /workflows/runs/{id}/journal). Les deux arrivent
 * RENDUS par le serveur (WorkflowJournal) : ce script ne connaît aucun libellé.
 * Il filtre (workflow, état) sans recharger la page, choisit un lancement,
 * ajoute la suite de la liste, suit ce qui est encore en cours, et tient
 * l'adresse à jour (workflow, etat, run) : un lien, un rechargement rouvrent
 * le même journal.
 *
 * Au-delà de 1200 px la liste et le journal sont côte à côte ; en dessous,
 * la liste puis le journal (« Tous les lancements » ou le retour du
 * navigateur pour revenir). Même seuil que la feuille de style.
 */
(function () {
    'use strict';

    var root = document.getElementById('wfHistory');
    if (!root) {
        return;
    }
    var form = root.querySelector('#wfHxFilters');
    var select = form ? form.querySelector('select[name="workflow"]') : null;
    var wfLabel = root.querySelector('.wf-hx-wf');
    var list = root.querySelector('#wfHistList');
    var journal = root.querySelector('#wfHistJournal');
    var fail = root.querySelector('.wf-hist-fail');
    var pickTpl = root.querySelector('#wfHistPick');
    var narrow = window.matchMedia('(max-width: 1199px)');
    var LIVE_MS = 6000;

    var state = {
        workflow: parseInt(root.getAttribute('data-workflow') || '0', 10) || 0,
        group: root.getAttribute('data-etat') || 'all',
        current: parseInt(root.getAttribute('data-run') || '0', 10) || 0,
        live: root.getAttribute('data-live') === '1',
        journalHtml: journal.innerHTML,
        listSeq: 0,
        journalSeq: 0,
        timer: null,
    };

    function get(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (d) {
                if (!r.ok || !d || d.ok === false) {
                    throw new Error((d && d.error) || ('HTTP ' + r.status));
                }
                return d;
            });
        });
    }

    function each(sel, fn, scope) {
        Array.prototype.forEach.call((scope || root).querySelectorAll(sel), fn);
    }

    // ------------------------------------------------------------------
    // L'adresse
    // ------------------------------------------------------------------

    function pageUrl(withRun) {
        var p = new URLSearchParams();
        p.set('vue', 'historique');
        if (state.workflow) {
            p.set('workflow', String(state.workflow));
        }
        if (state.group !== 'all') {
            p.set('etat', state.group);
        }
        if (withRun && state.current) {
            p.set('run', String(state.current));
        }
        return location.pathname + '?' + p.toString();
    }

    function syncUrl(push) {
        var showingJournal = narrow.matches && root.classList.contains('is-journal');
        // Au téléphone, la liste seule ne garde pas le lancement : recharger la rouvre.
        var url = pageUrl(!narrow.matches || showingJournal);
        var st = { hx: showingJournal ? 'journal' : 'list', run: state.current };
        if (push) {
            history.pushState(st, '', url);
        } else {
            history.replaceState(st, '', url);
        }
    }

    function listUrl(extra) {
        var p = new URLSearchParams();
        if (state.workflow) {
            p.set('workflow', String(state.workflow));
        }
        if (state.group !== 'all') {
            p.set('etat', state.group);
        }
        Object.keys(extra || {}).forEach(function (k) { p.set(k, String(extra[k])); });
        return '/workflows/history?' + p.toString();
    }

    // ------------------------------------------------------------------
    // La liste des lancements
    // ------------------------------------------------------------------

    function runIds() {
        return Array.prototype.map.call(list.querySelectorAll('.wf-hist-run'), function (a) {
            return parseInt(a.getAttribute('data-run'), 10);
        });
    }

    function lastLoadedId() {
        var ids = runIds();
        return ids.length ? ids[ids.length - 1] : 0;
    }

    function setCounts(counts) {
        each('.wf-hist-filter', function (b) {
            var g = b.getAttribute('data-filter');
            var n = counts && counts[g] !== undefined ? counts[g] : 0;
            var span = b.querySelector('span');
            if (span) {
                span.textContent = String(n);
            }
            b.hidden = g !== 'all' && n === 0 && g !== state.group;
            b.setAttribute('aria-pressed', g === state.group ? 'true' : 'false');
        });
    }

    /**
     * Recharge la liste. `keep` : on garde l'étendue déjà chargée (suite
     * comprise) et la position — c'est la mise à jour d'une liste en cours.
     */
    function loadList(keep) {
        var seq = ++state.listSeq;
        var extra = {};
        var upto = keep ? lastLoadedId() : 0;
        if (upto) {
            extra.upto = upto;
        }
        fail.hidden = true;
        if (!keep) {
            list.classList.add('is-loading');
        }
        return get(listUrl(extra)).then(function (d) {
            if (seq !== state.listSeq) {
                return null;
            }
            var scroll = list.scrollTop;
            // Le lancement qui avait le focus clavier le garde après la mise à jour.
            var focused = document.activeElement && list.contains(document.activeElement)
                ? document.activeElement.closest('.wf-hist-run') : null;
            var focusedId = focused ? focused.getAttribute('data-run') : null;
            list.innerHTML = d.html;
            if (focusedId) {
                var again = list.querySelector('.wf-hist-run[data-run="' + focusedId + '"]');
                if (again) {
                    again.focus({ preventScroll: true });
                }
            }
            list.classList.remove('is-loading');
            setCounts(d.counts);
            markCurrent();
            if (keep) {
                list.scrollTop = scroll;
            } else {
                list.scrollTop = 0;
            }
            return d;
        }).catch(function () {
            if (seq === state.listSeq) {
                list.classList.remove('is-loading');
                if (!keep) {
                    fail.hidden = false;
                }
            }
            return null;
        });
    }

    function loadMore(btn) {
        btn.disabled = true;
        get(listUrl({ before: btn.getAttribute('data-before') })).then(function (d) {
            var tpl = document.createElement('template');
            tpl.innerHTML = d.html;
            btn.remove();
            // La même journée coupée entre deux pages : on la recoud.
            var days = list.querySelectorAll('.wf-hist-day');
            var last = days.length ? days[days.length - 1] : null;
            var first = tpl.content.querySelector('.wf-hist-day');
            if (last && first && last.getAttribute('data-day') === first.getAttribute('data-day')) {
                var ul = last.querySelector('.wf-hist-runs');
                Array.prototype.slice.call(first.querySelectorAll('.wf-hist-item')).forEach(function (li) { ul.appendChild(li); });
                first.remove();
            }
            list.appendChild(tpl.content);
            markCurrent();
        }).catch(function () {
            btn.disabled = false;
        });
    }

    function markCurrent() {
        each('.wf-hist-run', function (a) {
            if (parseInt(a.getAttribute('data-run'), 10) === state.current) {
                a.setAttribute('aria-current', 'true');
            } else {
                a.removeAttribute('aria-current');
            }
        }, list);
    }

    // Un lancement qui avance seul (en cours, attente qui reprendra) : la
    // liste se met à jour. Celui qui attend votre décision ne bouge pas seul.
    function listHasActive() {
        return !!list.querySelector('.wf-hist-run.tone-run');
    }

    /** Un filtre a changé : la liste repart, le journal suit si son lancement n'y est plus. */
    function applyFilters() {
        if (wfLabel && select) {
            var opt = select.options[select.selectedIndex];
            var color = opt ? opt.getAttribute('data-color') : '';
            if (color) {
                wfLabel.style.setProperty('--hue', color);
            } else {
                wfLabel.style.removeProperty('--hue');
            }
        }
        loadList(false).then(function (d) {
            if (!d) {
                return;
            }
            var ids = runIds();
            if (state.current && ids.indexOf(state.current) !== -1) {
                syncUrl(false);
                return;
            }
            if (!narrow.matches && ids.length) {
                select_(ids[0]);
                return;
            }
            // Rien à montrer à droite, ou au téléphone : la liste.
            state.current = 0;
            state.live = false;
            showPick();
            showList(false);
            syncUrl(false);
            scheduleLive();
        });
    }

    // ------------------------------------------------------------------
    // Le journal d'un lancement
    // ------------------------------------------------------------------

    function showPick() {
        if (pickTpl) {
            journal.innerHTML = '';
            journal.appendChild(pickTpl.content.cloneNode(true));
            state.journalHtml = journal.innerHTML;
        }
    }

    function select_(runId, opts) {
        opts = opts || {};
        var changed = runId !== state.current;
        state.current = runId;
        markCurrent();
        if (changed) {
            journal.classList.add('is-loading');
        }
        var seq = ++state.journalSeq;
        return get('/workflows/runs/' + encodeURIComponent(runId) + '/journal').then(function (d) {
            if (seq !== state.journalSeq) {
                return;
            }
            journal.classList.remove('is-loading');
            if (d.html !== state.journalHtml) {
                // Les détails dépliés le restent quand le journal se met à jour —
                // repérés par ce qu'ils montrent (`data-view` : une pièce, une
                // action), sinon par leur rang : une entrée ajoutée en tête (le
                // « Résultat » d'un lancement qui finit) ne décale rien.
                var keyOf = function (x, i) { return x.getAttribute('data-view') || ('#' + i); };
                var opened = {};
                Array.prototype.forEach.call(journal.querySelectorAll('details'), function (x, i) { opened[keyOf(x, i)] = x.open; });
                journal.innerHTML = d.html;
                if (!changed) {
                    Array.prototype.forEach.call(journal.querySelectorAll('details'), function (x, i) {
                        if (opened[keyOf(x, i)] !== undefined) {
                            x.open = opened[keyOf(x, i)];
                        }
                    });
                }
                state.journalHtml = d.html;
                // Une page web déjà dépliée se recharge dans son cadre (html-preview.js).
                if (window.MiaHtmlPreview) { window.MiaHtmlPreview.scan(journal); }
            }
            state.live = !!d.live;
            if (changed && !opts.quiet) {
                if (narrow.matches) {
                    var wasJournal = root.classList.contains('is-journal');
                    root.classList.add('is-journal');
                    syncUrl(!wasJournal);
                    root.scrollIntoView({ block: 'start' });
                    var back = journal.querySelector('[data-hist-back]');
                    if (back) {
                        back.focus({ preventScroll: true });
                    }
                } else {
                    syncUrl(false);
                    // Le haut du journal en vue (la page défile, la liste reste collée).
                    var top = journal.getBoundingClientRect().top;
                    if (top < 0) {
                        journal.scrollIntoView({ block: 'start' });
                    }
                }
            }
            scheduleLive();
        }).catch(function () {
            if (seq === state.journalSeq) {
                journal.classList.remove('is-loading');
            }
        });
    }

    function showList(focus) {
        root.classList.remove('is-journal');
        if (focus === false || !narrow.matches) {
            return;
        }
        var cur = list.querySelector('.wf-hist-run[aria-current="true"]') || list.querySelector('.wf-hist-run');
        if (cur) {
            cur.focus({ preventScroll: false });
        }
    }

    // ------------------------------------------------------------------
    // En direct : un lancement en cours, ou une liste qui en montre
    // ------------------------------------------------------------------

    function scheduleLive() {
        if (state.timer) {
            clearTimeout(state.timer);
            state.timer = null;
        }
        if (!state.live && !listHasActive()) {
            return;
        }
        state.timer = setTimeout(function () {
            state.timer = null;
            if (document.hidden) {
                scheduleLive();
                return;
            }
            var jobs = [];
            if (listHasActive() || state.live) {
                jobs.push(loadList(true));
            }
            if (state.live && state.current) {
                jobs.push(select_(state.current, { quiet: true }));
            }
            Promise.all(jobs).then(scheduleLive, scheduleLive);
        }, LIVE_MS);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && (state.live || listHasActive())) {
            scheduleLive();
        }
    });

    // ------------------------------------------------------------------
    // Gestes
    // ------------------------------------------------------------------

    if (form) {
        // Les filtres sont les boutons d'un formulaire (marche sans script) :
        // ici, ils rechargent la liste sans quitter la page.
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var btn = ev.submitter && ev.submitter.closest ? ev.submitter.closest('[data-filter]') : null;
            if (btn) {
                state.group = btn.getAttribute('data-filter') || 'all';
            }
            applyFilters();
        });
    }
    if (select) {
        select.addEventListener('change', function () {
            state.workflow = parseInt(select.value || '0', 10) || 0;
            applyFilters();
        });
    }

    root.addEventListener('click', function (ev) {
        var t = ev.target;
        if (t.closest('[data-hist-back]')) {
            ev.preventDefault();
            if (history.state && history.state.hx === 'journal') {
                history.back();
            } else {
                showList();
                syncUrl(false);
            }
            return;
        }
        if (t.closest('[data-hist-retry]')) {
            loadList(false);
            return;
        }
        var reset = t.closest('[data-hist-reset]');
        if (reset) {
            ev.preventDefault();
            state.group = 'all';
            state.workflow = 0;
            if (select) {
                select.value = '';
            }
            applyFilters();
            return;
        }
        var more = t.closest('.wf-hist-more');
        if (more) {
            loadMore(more);
            return;
        }
        var run = t.closest('.wf-hist-run');
        // Ctrl, ⌘, Maj, clic du milieu : le lien s'ouvre ailleurs, comme partout.
        if (run && !ev.metaKey && !ev.ctrlKey && !ev.shiftKey && ev.button === 0) {
            ev.preventDefault();
            select_(parseInt(run.getAttribute('data-run'), 10));
        }
    });

    // ↑ / ↓ dans la liste : d'un lancement à l'autre (Entrée l'ouvre).
    list.addEventListener('keydown', function (ev) {
        if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') {
            return;
        }
        var links = Array.prototype.slice.call(list.querySelectorAll('.wf-hist-run'));
        var i = links.indexOf(document.activeElement);
        if (i === -1) {
            return;
        }
        ev.preventDefault();
        var next = links[i + (ev.key === 'ArrowDown' ? 1 : -1)];
        if (next) {
            next.focus();
        }
    });

    // Retour du navigateur au téléphone : du journal à la liste.
    window.addEventListener('popstate', function (ev) {
        var st = ev.state || {};
        if (st.hx === 'journal' && st.run) {
            root.classList.add('is-journal');
            if (st.run !== state.current) {
                select_(st.run, { quiet: true });
            }
        } else {
            showList();
        }
    });

    narrow.addEventListener('change', function () {
        if (!narrow.matches) {
            root.classList.remove('is-journal');
            if (!state.current) {
                var ids = runIds();
                if (ids.length) {
                    select_(ids[0], { quiet: true });
                }
            }
        }
        syncUrl(false);
    });

    // Premier affichage : tout est déjà rendu par le serveur.
    markCurrent();
    if (narrow.matches && root.classList.contains('is-journal')) {
        // Ouvert sur un lancement (lien, rechargement) : le retour mène à la liste.
        history.replaceState({ hx: 'list', run: state.current }, '', pageUrl(false));
        history.pushState({ hx: 'journal', run: state.current }, '', pageUrl(true));
    } else {
        syncUrl(false);
    }
    var cur = list.querySelector('.wf-hist-run[aria-current="true"]');
    if (cur && !narrow.matches) {
        // Le lancement choisi en vue dans la liste (un lancement ancien ouvert par un lien).
        var lr = list.getBoundingClientRect();
        var cr = cur.getBoundingClientRect();
        if (cr.bottom > lr.bottom || cr.top < lr.top) {
            list.scrollTop += cr.top - lr.top - 60;
        }
    }
    scheduleLive();

    window.MiaWorkflowHistory = { select: select_, reload: loadList };
})();
