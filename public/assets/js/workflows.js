/**
 * Workflows — ce que partagent la liste, la création, l'éditeur et le chat :
 *
 *   - la FENÊTRE DE LANCEMENT (window.MiaWorkflowLaunch) : les champs du
 *     départ à gauche, ce qui va se passer à droite (la suite des briques,
 *     les validations qui vous attendront, le coût estimé). Le chat l'ouvre
 *     avec un fichier déjà joint (jeton de la zone de saisie) ;
 *   - la page « Nouveau workflow » : exemples à poser d'un clic, et
 *     composition par MIA (quelques secondes, d'où l'indicateur) ;
 *   - les boutons « Lancer » de la liste, et « Relancer » du journal d'un
 *     lancement (data-launch-values : la fenêtre s'ouvre remplie avec ses données).
 */
(function () {
    'use strict';

    var T = function (s, v) { return window.t ? window.t(s, v) : s; };
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function tz() {
        try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; }
    }

    var HUES = { start: '#f59e0b', step: '#10a37f', action: '#06b6d4', condition: '#8b5cf6', loop: '#2563eb', gate: '#f97316', wait: '#64748b', notify: '#db2777', end: '#10a37f', stop: '#ef4444', delivery: '#10a37f' };
    var SVG = function (d) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>'; };
    var ICONS = {
        start: SVG('<polygon points="6 4 20 12 6 20 6 4"/>'),
        step: SVG('<path d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z"/>'),
        action: SVG('<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>'),
        condition: SVG('<path d="M6 3v6a6 6 0 0 0 6 6h6"/><path d="M6 21v-6"/><polyline points="15 12 18 15 15 18"/>'),
        gate: SVG('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>'),
        end: SVG('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'),
        stop: SVG('<rect x="5" y="5" width="14" height="14" rx="2"/>'),
        loop: SVG('<path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>'),
        wait: SVG('<path d="M6 2h12M6 22h12"/><path d="M7 2c0 5 10 5 10 10S7 17 7 22"/><path d="M17 2c0 5-10 5-10 10s10 5 10 10"/>'),
        notify: SVG('<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'),
        delivery: SVG('<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>'),
        file: SVG('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'),
        x: SVG('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'),
    };

    // ------------------------------------------------------------------
    // Fenêtre de lancement
    // ------------------------------------------------------------------

    var modal = null;

    function fieldHtml(f, preset) {
        var id = 'wfl_' + f.key;
        var label = '<label class="f" for="' + id + '">' + esc(f.label || f.key) + (f.required ? '' : ' <small>(' + esc(T('facultatif')) + ')</small>');
        var help = f.help ? '<small>' + esc(f.help) + '</small>' : '';
        var name = 'values[' + f.key + ']';
        var val = preset && preset.values && preset.values[f.key] !== undefined ? preset.values[f.key] : (f.default || '');
        var req = f.required ? ' required' : '';
        switch (f.type) {
            case 'file':
                var token = preset && preset.files && preset.files[f.key];
                // Un document de référence est déjà fourni : déposer un autre fichier le remplace pour CE lancement.
                var ref = f.reference || null;
                if (ref) { help = '<small>' + esc(T('Fourni par le workflow : {nom}. Déposez un autre fichier pour le remplacer cette fois-ci.', { nom: ref.name })) + '</small>'; }
                return label + help + '<div class="wf-drop' + (token || ref ? ' has-file' : '') + '" data-drop="' + esc(f.key) + '"><span class="fi">' + ICONS.file + '</span><span data-drop-label>'
                    + esc(token ? token.name : (ref ? ref.name : T('Déposez un fichier ici ou cliquez pour choisir'))) + '</span>'
                    + '<input type="file" name="file_' + esc(f.key) + '" id="' + id + '" accept="' + esc(modal.form.accept || '') + '"' + (token || ref ? '' : req) + '>'
                    + (token ? '<input type="hidden" name="token_' + esc(f.key) + '" value="' + esc(token.token) + '">' : '') + '</div></label>';
            case 'longtext':
                return label + help + '<textarea class="wf-textarea" id="' + id + '" name="' + name + '"' + req + '>' + esc(val) + '</textarea></label>';
            case 'choice':
                return label + help + '<select class="wf-select" id="' + id + '" name="' + name + '"' + req + '><option value="">' + esc(T('Choisir…')) + '</option>'
                    + (f.options || []).map(function (o) { return '<option' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select></label>';
            case 'yesno':
                return label + help + '<select class="wf-select" id="' + id + '" name="' + name + '"' + req + '><option value=""></option><option value="oui"' + (val === 'oui' ? ' selected' : '') + '>' + esc(T('Oui'))
                    + '</option><option value="non"' + (val === 'non' ? ' selected' : '') + '>' + esc(T('Non')) + '</option></select></label>';
            default:
                var type = { number: 'text', date: 'date', email: 'email' }[f.type] || 'text';
                var mode = f.type === 'number' ? ' inputmode="decimal"' : '';
                return label + help + '<input class="wf-input" type="' + type + '"' + mode + ' id="' + id + '" name="' + name + '" value="' + esc(val) + '"' + req + '></label>';
        }
    }

    function stepsHtml(lines) {
        return '<div class="wf-steps-mini">' + (lines || []).map(function (l) {
            var k = l.kind;
            return '<div class="ms" style="--hue:' + (HUES[k] || HUES.step) + '"><span class="wf-node-ic">' + (ICONS[k] || ICONS.step) + '</span><div>' + esc(l.title)
                + (l.sub ? '<small>' + esc(l.sub) + '</small>' : '') + (l.review ? '<small style="color:' + HUES.gate + '">' + esc(T('puis vous relisez')) + '</small>' : '') + '</div></div>';
        }).join('') + '</div>';
    }

    /**
     * Ce qu'il faut régler avant de lancer (WorkflowSpec::preflight, calculé
     * par le serveur avec vos connexions du moment) : un compte à relier, un
     * outil retiré. Tant qu'il en reste, « Lancer » ne part pas — rien n'est
     * dépensé pour un workflow qui ne pourrait pas aller au bout.
     */
    // Typographie : « Gmail » ne se coupe pas après le guillemet (espace
    // insécable), ni « : » en début de ligne. Textes du serveur ou traduits.
    function glue(s) {
        return String(s == null ? '' : s).replace(/« /g, '«\u00a0').replace(/ ([»:;?!])/g, '\u00a0$1');
    }

    function preflightHtml(items) {
        if (!items || !items.length) { return ''; }
        return '<div class="wf-preflight" role="alert"><b>' + esc(T('À régler avant de lancer')) + '</b>'
            + '<p>' + esc(T('MIA a vérifié ce dont le workflow aura besoin : sans cela, il s’arrêterait en route.')) + '</p><ul>'
            + items.map(function (i) {
                return '<li><span>' + (i.brick ? '<b>' + esc(glue(i.brick)) + '</b> — ' : '') + esc(glue(i.text)) + '</span>'
                    + (i.url ? '<a class="wf-btn sm" href="' + esc(i.url) + '" target="_blank" rel="noopener">' + esc(T('Relier')) + '</a>' : '') + '</li>';
            }).join('') + '</ul>'
            + '<button type="button" class="wf-btn sm ghost" data-recheck>' + esc(T('Vérifier à nouveau')) + '</button></div>';
    }

    function syncPreflight(items) {
        if (!modal) { return; }
        modal.form.preflight = items || [];
        var host = modal.back.querySelector('[data-preflight]');
        if (host) { host.innerHTML = preflightHtml(modal.form.preflight); }
        var go = modal.back.querySelector('[data-go]');
        if (go) { go.disabled = modal.form.preflight.length > 0; }
    }

    /** Relit le pré-contrôle (après avoir relié un compte dans un autre onglet). */
    function recheck(btn) {
        if (!modal || !modal.form.form_url) { return; }
        btn.disabled = true;
        btn.textContent = T('Vérification…');
        fetch(modal.form.form_url, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.ok && modal) { syncPreflight(j.form.preflight); } })
            .catch(function () { if (btn) { btn.disabled = false; btn.textContent = T('Vérifier à nouveau'); } });
    }

    function close() {
        if (!modal) { return; }
        modal.back.remove();
        document.removeEventListener('keydown', modal.onKey);
        if (modal.opener && modal.opener.focus) { modal.opener.focus(); }
        modal = null;
    }

    /**
     * Ouvre la fenêtre pour un formulaire déjà chargé.
     * opts : { files: {clé: {token, name}}, values: {clé: valeur}, trigger: 'manual'|'chat', onLaunched(res) }
     */
    function openWithForm(form, opts) {
        close();
        opts = opts || {};
        var back = document.createElement('div');
        back.className = 'wf-modal-back wf-content';
        modal = { back: back, form: form, opener: document.activeElement, opts: opts };
        var gates = (form.lines || []).filter(function (l) { return l.kind === 'gate' || l.review; }).length;
        var fields = (form.fields || []).map(function (f) { return fieldHtml(f, opts); }).join('');
        if (form.event) {
            // Workflow lancé par un évènement : à la main, c'est un essai — on
            // colle ce que l'évènement aurait apporté.
            var ev = form.event;
            fields += '<div class="wf-event-try"><b>' + esc(ev.sentence) + '</b><small>' + esc(ev.help) + '</small>'
                + (ev.kind === 'file'
                    ? '<div class="wf-drop" data-drop="__event"><span class="fi">' + ICONS.file + '</span><span data-drop-label>' + esc(T('Déposez un fichier ici ou cliquez pour choisir')) + '</span>'
                        + '<input type="file" name="file___event" accept="' + esc(form.accept || '') + '"></div>'
                    : ev.recent_url
                        // Email : un de ses derniers emails, comme s'il venait
                        // d'arriver (la liste est rendue par le serveur) ; coller
                        // un texte reste possible, replié.
                        ? '<div class="wf-recent"><div class="wf-recent-head"><b>' + esc(T('Choisir un de vos derniers emails')) + '</b>'
                            + '<button type="button" class="wf-recent-reload" data-recent-reload>' + esc(T('Actualiser')) + '</button></div>'
                            + '<div data-recent><p class="wf-recent-state">' + esc(T('Lecture de vos derniers emails…')) + '</p></div></div>'
                            + '<details class="wf-event-paste"><summary>' + esc(T('Ou coller le texte d’un email')) + '</summary>'
                            + '<textarea class="wf-textarea" name="event_text" placeholder="' + esc(ev.name) + '"></textarea></details>'
                        : '<textarea class="wf-textarea" name="event_text" placeholder="' + esc(ev.name) + '"></textarea>')
                + '</div>';
        }
        back.innerHTML = '<div class="wf-modal" role="dialog" aria-modal="true" aria-labelledby="wflTitle">'
            + '<header><div><h2 id="wflTitle">' + esc(glue(T('Lancer « {nom} »', { nom: form.name }))) + '</h2>' + (form.objective ? '<p>' + esc(form.objective) + '</p>' : '') + '</div>'
            + '<button type="button" class="wf-tool" data-close aria-label="' + esc(T('Fermer')) + '">' + ICONS.x + '</button></header>'
            + '<div data-preflight>' + preflightHtml(form.preflight) + '</div>'
            + '<form class="body" novalidate>'
            + '<section>' + (fields || '<p class="wf-hint">' + esc(T('Ce workflow démarre sans rien demander.')) + '</p>') + '</section>'
            + '<section><b style="font-size:12.5px">' + esc(T('Ce qui va se passer')) + '</b>' + stepsHtml(form.lines)
            + (gates ? '<p class="wf-hint">' + esc(window.tn ? window.tn(gates, 'Une validation vous attendra en route.', '{n} validations vous attendront en route.') : '') + '</p>' : '')
            + ((form.preview_fields || []).length ? '<div class="wf-lpreview" data-preview hidden></div>' : '')
            // Rendu par le serveur (WorkflowCatalog::estimateHtml), le même que l'éditeur.
            + (form.estimate && form.estimate.html ? '<div data-estimate>' + form.estimate.html + '</div>' : '')
            + (form.notify ? '<p class="wf-hint">' + esc(T('Vous serez prévenu sur vos appareils quand il aura besoin de vous.')) + '</p>' : '')
            + '</section></form>'
            + '<footer><span class="err" role="alert"></span><button type="button" class="wf-btn ghost" data-close>' + esc(T('Annuler')) + '</button>'
            + '<button type="button" class="wf-btn primary" data-go' + ((form.preflight || []).length ? ' disabled' : '') + '>' + esc(T('Lancer')) + '</button></footer></div>';
        document.body.appendChild(back);
        modal.onKey = function (e) { if (e.key === 'Escape') { close(); } };
        document.addEventListener('keydown', modal.onKey);
        back.addEventListener('click', function (e) {
            if (e.target === back || e.target.closest('[data-close]')) { close(); return; }
            if (e.target.closest('[data-go]')) { launch(e.target.closest('[data-go]')); }
            if (e.target.closest('[data-recheck]')) { recheck(e.target.closest('[data-recheck]')); }
            if (e.target.closest('[data-recent-reload]')) { loadRecent(); }
        });
        // Un texte collé remplace l'email choisi (le serveur prend l'email
        // choisi d'abord : on le décoche pour que l'écran dise la vérité).
        var pasted = back.querySelector('.wf-event-paste textarea');
        if (pasted) {
            pasted.addEventListener('input', function () {
                if (pasted.value.trim() === '') { return; }
                back.querySelectorAll('input[name="event_message"]').forEach(function (r) { r.checked = false; });
            });
        }
        back.querySelectorAll('[data-drop]').forEach(function (zone) {
            var input = zone.querySelector('input[type=file]');
            var label = zone.querySelector('[data-drop-label]');
            input.addEventListener('change', function () {
                if (input.files && input.files[0]) {
                    label.textContent = input.files[0].name;
                    zone.classList.add('has-file');
                    var tok = zone.querySelector('input[type=hidden]');
                    if (tok) { tok.remove(); }
                    if ((form.preview_fields || []).indexOf(zone.getAttribute('data-drop')) !== -1) { preview(); }
                }
            });
            ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function () { zone.classList.add('is-over'); }); });
            ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function () { zone.classList.remove('is-over'); }); });
        });
        var first = Array.prototype.slice.call(back.querySelectorAll('input:not([type=hidden]), textarea, select'))
            .filter(function (el) { return !el.closest('details:not([open])'); })[0];
        (first || back.querySelector('[data-go]')).focus();
        if (form.event && form.event.recent_url) { loadRecent(); }
        // Un fichier déjà joint (depuis le chat) se compte tout de suite.
        if ((form.preview_fields || []).some(function (k) { return opts.files && opts.files[k]; })) { preview(); }
    }

    /**
     * Les derniers emails de la boîte surveillée (workflow lancé par un
     * email, essai à la main) : la liste vient du serveur, rendue et
     * traduite ; rien n'est coché d'office.
     */
    function loadRecent() {
        if (!modal || !modal.form.event || !modal.form.event.recent_url) { return; }
        var box = modal.back.querySelector('[data-recent]');
        if (!box) { return; }
        var seq = (modal.recentSeq || 0) + 1;
        modal.recentSeq = seq;
        box.innerHTML = '<p class="wf-recent-state">' + esc(T('Lecture de vos derniers emails…')) + '</p>';
        var fail = function (msg) {
            if (!modal || modal.recentSeq !== seq) { return; }
            box.innerHTML = '<p class="wf-recent-state is-warn">' + esc(msg || T('Vos derniers emails n’ont pas pu être lus.')) + '</p>';
        };
        fetch(modal.form.event.recent_url + '?tz=' + encodeURIComponent(tz()), { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (j) {
                if (!modal || modal.recentSeq !== seq) { return; }
                if (!j.ok) { fail(j.error); return; }
                box.innerHTML = j.html || '';
            })
            .catch(function () { fail(''); });
    }

    /**
     * Combien de tours : le fichier déposé est lu tout de suite (sans rien
     * lancer ni enregistrer), la fenêtre dit combien d'éléments la boucle
     * traitera, les premiers d'entre eux et le coût recalculé.
     */
    function preview() {
        if (!modal) { return; }
        var box = modal.back.querySelector('[data-preview]');
        var est = modal.back.querySelector('[data-estimate]');
        var seq = (modal.previewSeq || 0) + 1;
        modal.previewSeq = seq;
        modal.preview = null;
        var fd = new FormData();
        (modal.form.preview_fields || []).forEach(function (k) {
            var input = modal.back.querySelector('input[name="file_' + k + '"]');
            var tok = modal.back.querySelector('input[name="token_' + k + '"]');
            if (tok) { fd.append('token_' + k, tok.value); } else if (input && input.files && input.files[0]) { fd.append('file_' + k, input.files[0]); }
        });
        box.hidden = false;
        box.className = 'wf-lpreview is-busy';
        box.textContent = T('Lecture du fichier…');
        fetch(modal.form.preview_url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (j) {
                if (!modal || modal.previewSeq !== seq) { return; }
                if (!j.ok) { box.className = 'wf-lpreview is-warn'; box.textContent = j.error || T('Le fichier n’a pas pu être lu avant le lancement.'); return; }
                modal.preview = j;
                var html = (j.loops || []).map(function (lp) {
                    if (lp.error) { return '<div class="wf-lp-row is-warn"><b>' + esc(lp.title) + '</b><span>' + esc(lp.error) + '</span></div>'; }
                    var ign = lp.ignored || {};
                    var why = [];
                    if (ign.filter) { why.push(T('{n} hors filtre', { n: ign.filter })); }
                    if (ign.dup) { why.push(T('{n} doublons', { n: ign.dup })); }
                    if (ign.label) { why.push(T('{n} total ou sans nom', { n: ign.label })); }
                    var line = window.tn ? window.tn(lp.count, '{n} tour', '{n} tours') : String(lp.count);
                    return '<div class="wf-lp-row' + (lp.count > (j.confirm_over || 50) ? ' is-big' : '') + (lp.count === 0 ? ' is-warn' : '') + '"><b>' + esc(T('« {titre} » : {tours}', { titre: lp.title, tours: line })) + '</b>'
                        + '<span>' + esc(T('{n} éléments trouvés dans « {fichier} »', { n: lp.found, fichier: lp.file }) + (why.length ? ' · ' + why.join(' · ') : '')
                            + (lp.kept > lp.count ? ' · ' + T('limite de {n} atteinte', { n: lp.cap }) : '')) + '</span>'
                        + ((lp.labels || []).length ? '<small>' + esc(T('Par exemple : {liste}', { liste: lp.labels.join(' · ') })) + '</small>' : '') + '</div>';
                }).join('');
                box.className = 'wf-lpreview';
                box.innerHTML = html;
                box.hidden = html === '';
                // Tours comptés dans le fichier joint : l'estimation recalculée.
                if (est && j.estimate && j.estimate.html) { est.innerHTML = j.estimate.html; }
            })
            .catch(function () {
                if (!modal || modal.previewSeq !== seq) { return; }
                box.className = 'wf-lpreview is-warn';
                box.textContent = T('Le fichier n’a pas pu être lu avant le lancement.');
            });
    }

    function launch(btn) {
        var f = modal.back.querySelector('form');
        var err = modal.back.querySelector('.err');
        err.textContent = '';
        // Validation native : un champ requis vide est signalé en place.
        var invalid = Array.prototype.slice.call(f.elements).filter(function (el) { return el.willValidate && !el.checkValidity(); })[0];
        if (invalid) { invalid.reportValidity(); return; }
        // Beaucoup de tours : on le dit avant de lancer (le coût suit le nombre d'éléments).
        var big = ((modal.preview || {}).loops || []).filter(function (lp) { return lp.count > (modal.preview.confirm_over || 50); })[0];
        if (big && !window.confirm(T('« {titre} » va faire {n} tours, ≈ {max} crédits au plus. Lancer quand même ?', { titre: big.title, n: big.count, max: (modal.preview.estimate || {}).max || '?' }))) { return; }
        // Workflow lancé par un évènement, essai à la main sans rien fournir :
        // la pièce de l'évènement manquera — on le dit avant de lancer.
        var ev = modal.form.event;
        if (ev) {
            var chosen = f.querySelector('input[name="event_message"]:checked');
            var text = f.querySelector('textarea[name="event_text"]');
            var file = f.querySelector('input[name="file___event"]');
            var given = chosen || (text && text.value.trim() !== '') || (file && file.files && file.files.length);
            if (!given && !window.confirm(T('Rien n’est fourni pour « {piece} » : le workflow démarrera sans. Lancer quand même ?', { piece: ev.name }))) { return; }
        }
        var fd = new FormData(f);
        fd.append('trigger', modal.opts.trigger === 'chat' ? 'chat' : 'manual');
        fd.append('tz', tz());
        btn.disabled = true;
        btn.textContent = T('Lancement…');
        var onLaunched = modal.opts.onLaunched;
        fetch(modal.form.launch_url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (j) {
                if (!j.ok) {
                    btn.disabled = false;
                    btn.textContent = T('Lancer');
                    if (j.preflight && j.preflight.length) { syncPreflight(j.preflight); return; }
                    err.textContent = j.error || T('Lancement impossible.');
                    return;
                }
                close();
                // La discussion d'abord : l'exécution se suit dans sa conversation.
                if (onLaunched) { onLaunched(j); } else { window.location.href = j.conversation_url || j.url; }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = T('Lancer');
                err.textContent = T('Lancement impossible : vérifiez votre connexion.');
            });
    }

    function open(id, opts) {
        return fetch('/workflows/' + id + '/form', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { window.alert(j.error || T('Ce workflow ne peut pas être lancé.')); return; }
                openWithForm(j.form, opts);
            });
    }

    window.MiaWorkflowLaunch = { open: open, openWithForm: openWithForm, close: close };

    // ------------------------------------------------------------------
    // Suppression : UNE fenêtre de confirmation pour toutes les portes
    // (corbeille d'une vignette, en-tête et Réglages de l'éditeur). Elle seule
    // envoie `confirm=1` — sans lui, le serveur ne supprime rien.
    // ------------------------------------------------------------------

    var TRASH = SVG('<path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>');
    var confirmBox = null;

    function closeDelete() {
        if (!confirmBox) { return; }
        confirmBox.back.remove();
        document.removeEventListener('keydown', confirmBox.onKey, true);
        if (confirmBox.opener && confirmBox.opener.focus && document.contains(confirmBox.opener)) { confirmBox.opener.focus(); }
        confirmBox = null;
    }

    /**
     * Ce que la suppression emporte, en phrases : le workflow et ses versions,
     * l'historique de ses lancements (leurs conversations restent), les
     * exécutions en cours qu'elle arrête. Forme pure, testée sous node.
     * info : { name, runs, live, pausable }
     */
    function deleteLines(info) {
        var runs = Math.max(0, parseInt(info.runs, 10) || 0);
        var live = Math.max(0, parseInt(info.live, 10) || 0);
        var tn = window.tn || function (n, un, plusieurs, v) { return T(n > 1 ? plusieurs : un, Object.assign({ n: n }, v || {})); };
        var lines = [{ text: T('Le workflow, son brouillon et ses versions publiées sont supprimés.') }];
        lines.push({
            text: runs > 0
                ? tn(runs, 'L’historique de son lancement disparaît aussi ; sa conversation reste dans votre historique.', 'L’historique de ses {n} lancements disparaît aussi ; leurs conversations restent dans votre historique.')
                : T('Il n’a encore jamais été lancé.'),
        });
        if (live > 0) {
            lines.push({ warn: true, text: tn(live, 'Son exécution en cours sera arrêtée, et ses validations en attente fermées.', 'Ses {n} exécutions en cours seront arrêtées, et leurs validations en attente fermées.') });
        }
        return lines;
    }

    /**
     * Ouvre la fenêtre. info : { id, name, runs, live, pausable } ;
     * opts.before() : appelé juste avant l'envoi (l'éditeur y retient son
     * enregistrement automatique, qui reposterait sinon le brouillon d'un
     * workflow supprimé).
     */
    function openDelete(info, opts) {
        closeDelete();
        opts = opts || {};
        var back = document.createElement('div');
        back.className = 'wf-modal-back wf-content';
        var lines = deleteLines(info);
        back.innerHTML = '<div class="wf-modal wf-confirm" role="alertdialog" aria-modal="true" aria-labelledby="wfdTitle" aria-describedby="wfdBody">'
            + '<header><div class="wf-confirm-head"><span class="wf-confirm-ic">' + TRASH + '</span>'
            + '<h2 id="wfdTitle">' + esc(glue(T('Supprimer « {nom} » ?', { nom: info.name || '' }))) + '</h2></div>'
            + '<button type="button" class="wf-tool" data-close aria-label="' + esc(T('Fermer')) + '">' + ICONS.x + '</button></header>'
            + '<div class="wf-confirm-body" id="wfdBody"><ul>'
            + lines.map(function (l) { return '<li' + (l.warn ? ' class="is-warn"' : '') + '>' + esc(glue(l.text)) + '</li>'; }).join('')
            + '</ul><p class="wf-confirm-final">' + esc(glue(T('Cette action est définitive : un workflow supprimé ne se récupère pas.'))) + '</p>'
            + (info.pausable ? '<p class="wf-hint">' + esc(T('Pour l’arrêter seulement un temps, mettez-le plutôt en pause.')) + '</p>' : '')
            + '</div>'
            + '<footer><span class="err" role="alert"></span>'
            + '<button type="button" class="wf-btn ghost" data-close>' + esc(T('Annuler')) + '</button>'
            + '<button type="button" class="wf-btn danger-fill" data-confirm>' + TRASH + esc(T('Supprimer définitivement')) + '</button></footer></div>';
        document.body.appendChild(back);
        confirmBox = { back: back, opener: document.activeElement };
        var cancelBtn = back.querySelector('footer [data-close]');
        var goBtn = back.querySelector('[data-confirm]');
        var err = back.querySelector('.err');

        // Échap referme ; Tab reste dans la fenêtre. Entrée sur « Annuler »
        // (focus d'arrivée) ne supprime rien : le geste destructeur se vise.
        confirmBox.onKey = function (e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); if (!goBtn.disabled) { closeDelete(); } return; }
            if (e.key !== 'Tab') { return; }
            var f = Array.prototype.slice.call(back.querySelectorAll('button:not([disabled])'));
            if (!f.length) { return; }
            var i = f.indexOf(document.activeElement);
            if (e.shiftKey && (i <= 0)) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && (i === -1 || i === f.length - 1)) { e.preventDefault(); f[0].focus(); }
        };
        document.addEventListener('keydown', confirmBox.onKey, true);

        back.addEventListener('click', function (e) {
            if (goBtn.disabled) { return; }
            if (e.target === back || e.target.closest('[data-close]')) { closeDelete(); return; }
            if (!e.target.closest('[data-confirm]')) { return; }
            goBtn.disabled = true;
            cancelBtn.disabled = true;
            err.textContent = '';
            goBtn.lastChild.textContent = T('Suppression…');
            if (typeof opts.before === 'function') { opts.before(); }
            var body = new FormData();
            body.append('confirm', '1');
            body.append('csrf_token', csrf());
            fetch('/workflows/' + encodeURIComponent(info.id) + '/delete', {
                method: 'POST', body: body,
                headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() },
            })
                .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
                .then(function (j) {
                    if (j.ok) { window.location.href = j.url || '/workflows'; return; }
                    throw new Error(j.error || '');
                })
                .catch(function (ex) {
                    goBtn.disabled = false;
                    cancelBtn.disabled = false;
                    goBtn.lastChild.textContent = T('Supprimer définitivement');
                    err.textContent = (ex && ex.message) || T('Suppression impossible : vérifiez votre connexion.');
                });
        });
        cancelBtn.focus();
    }

    window.MiaWorkflowDelete = { open: openDelete, close: closeDelete, lines: deleteLines };

    // Corbeille d'une vignette (liste) : un vrai formulaire, intercepté.
    document.addEventListener('submit', function (e) {
        var f = e.target.closest && e.target.closest('form[data-wf-delete]');
        if (!f) { return; }
        e.preventDefault();
        var m = /\/workflows\/(\d+)\/delete/.exec(f.getAttribute('action') || '');
        if (!m) { return; }
        openDelete({
            id: m[1],
            name: f.getAttribute('data-name') || '',
            runs: f.getAttribute('data-runs'),
            live: f.getAttribute('data-live'),
            pausable: f.getAttribute('data-pausable') === '1',
        });
    });

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-launch]');
        if (b) {
            e.preventDefault();
            var values = null;
            try { values = JSON.parse(b.getAttribute('data-launch-values') || 'null'); } catch (err) { values = null; }
            open(b.getAttribute('data-launch'), values && typeof values === 'object' ? { values: values } : undefined);
            return;
        }
        var ex = e.target.closest('[data-example]');
        if (ex) {
            var ta = document.querySelector('#wfCompose textarea');
            if (ta && (!ta.value.trim() || window.confirm(T('Remplacer votre texte par cet exemple ?')))) {
                ta.value = ex.getAttribute('data-example');
                ta.focus();
            }
        }
    });

    var compose = document.getElementById('wfCompose');
    if (compose) {
        compose.querySelector('[name=tz]').value = tz();
        compose.addEventListener('submit', function (e) {
            e.preventDefault();
            var ta = compose.querySelector('textarea');
            if (ta.value.trim().length < 15) { ta.reportValidity(); return; }
            var btn = compose.querySelector('button[type=submit]');
            var wait = document.getElementById('wfComposing');
            btn.disabled = true;
            wait.classList.add('is-on');
            fetch(compose.action, { method: 'POST', body: new FormData(compose), headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } })
                .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
                .then(function (j) {
                    if (j.ok) { window.location.href = j.url; return; }
                    btn.disabled = false;
                    wait.classList.remove('is-on');
                    window.alert(j.error || T('MIA n’a pas pu composer ce workflow.'));
                })
                .catch(function () {
                    btn.disabled = false;
                    wait.classList.remove('is-on');
                    window.alert(T('Composition impossible : vérifiez votre connexion.'));
                });
        });
    }
})();
