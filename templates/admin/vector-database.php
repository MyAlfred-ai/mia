<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Moteur vectoriel') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'knowledge-bases-vector'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <?php $ongletsGroupe = AdminTabs::DIAGNOSTIC; require ROOT . '/templates/partials/admin-tabs.php'; ?>
    <h1><?= te('Moteur vectoriel') ?></h1>
    <p style="color:var(--text-muted);font-size:13px;margin:-6px 0 18px">
        <?= te('État du moteur qui porte les vecteurs des bases de connaissances, et gestes de remise en état. MySQL reste la source de vérité : sans vecteurs, les assistants répondent quand même, en recherche texte — moins pertinente.') ?>
    </p>

    <!--
        Rapport chargé en AJAX et non rendu côté serveur : sonder Qdrant
        collection par collection dans la requête de page ajouterait plusieurs
        secondes de latence — et précisément quand le service est en panne
        (chaque appel part au timeout), c'est-à-dire quand la page doit rester
        consultable.
    -->
    <section class="card kb-vec" id="kbVecPanel">
        <div class="kb-vec-head">
            <div class="kb-vec-title">
                <span class="kb-vec-dot" id="kbVecDot" data-up="wait"></span>
                <h2><?= te('État du service') ?></h2>
                <span class="kb-vec-sub" id="kbVecSub"><?= te('Vérification…') ?></span>
            </div>
            <div class="kb-vec-actions">
                <button type="button" class="btn-secondary" id="kbVecRefresh"><?= te('Revérifier') ?></button>
                <button type="button" class="btn-secondary" id="kbVecRepair" hidden
                        title="<?= te('Recrée les collections manquantes. Aucun vecteur existant n\'est touché.') ?>"><?= te('Réparer') ?></button>
                <button type="button" class="btn-secondary kb-btn-del" id="kbVecPrune" hidden
                        title="<?= te('Supprime les collections de bases qui n\'existent plus.') ?>"><?= te('Purger les orphelines') ?></button>
            </div>
        </div>
        <p class="kb-vec-hint">
            <?= strtr(teb('Une collection {collection} par base. Les {b}extraits{/b} sont le texte découpé stocké en base de données ; les {b}vecteurs{/b} sont leur contrepartie dans le moteur. Les deux nombres doivent être égaux.'), ['{collection}' => '<code>kb_&lt;id&gt;</code>']) ?>
        </p>
        <div class="kb-vec-msg" id="kbVecMsg" hidden></div>
        <div class="kb-vec-rows" id="kbVecRows"></div>
        <p class="kb-vec-hint" style="margin-top:14px">
            <?= strtr(te('Les documents d\'une base se gèrent depuis {lien}.'), ['{lien}' => '<a href="/admin/knowledge-bases">' . te('Bases de connaissances') . '</a>']) ?>
        </p>
    </section>
</div>

<script>
// Rapport de santé Qdrant + gestes de remise en état. Le verdict par base est
// calculé côté serveur (QdrantHealth) : ici on ne fait que rendre.
(function () {
    const panel = document.getElementById('kbVecPanel');
    if (!panel) return;

    const dot     = document.getElementById('kbVecDot');
    const sub     = document.getElementById('kbVecSub');
    const rowsEl  = document.getElementById('kbVecRows');
    const msgEl   = document.getElementById('kbVecMsg');
    const btnRefr = document.getElementById('kbVecRefresh');
    const btnRep  = document.getElementById('kbVecRepair');
    const btnPrune= document.getElementById('kbVecPrune');
    let busy = false;

    // Langue de la page (window.I18N, posé par head-meta) : nombres, pluriels.
    const EN     = !!(window.I18N && window.I18N.lang === 'en');
    const LOCALE = EN ? 'en-US' : 'fr-FR';
    const plural = (n, one, many) => ((EN ? n !== 1 : n > 1) ? many : one);
    const fill   = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => ((vars && k in vars) ? String(vars[k]) : m));
    const esc    = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const NUMS   = <?= tj('{extraits} extraits · {vecteurs} vecteurs') ?>;

    function csrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function setMessage(text, kind) {
        if (!text) { msgEl.hidden = true; msgEl.textContent = ''; return; }
        msgEl.textContent = text;
        msgEl.dataset.kind = kind || 'info';
        msgEl.hidden = false;
    }

    function setBusy(on) {
        busy = on;
        [btnRefr, btnRep, btnPrune].forEach(b => { if (b) b.disabled = on; });
        panel.classList.toggle('is-busy', on);
    }

    // Bases dont l'état se corrige par une réindexation (les seules à porter
    // le bouton : proposer « Réindexer » sur une base saine invite à détruire
    // des vecteurs corrects pour rien).
    const REINDEXABLE = ['dims', 'empty', 'partial', 'failed', 'missing'];

    function render(rep) {
        const up = !!rep.up;
        dot.dataset.up = up ? (rep.issues > 0 ? 'warn' : 'ok') : 'ko';

        if (!up) {
            sub.textContent = fill(<?= tj('Injoignable — {url}') ?>, { url: rep.url });
            setMessage(rep.error
                ? fill(<?= tj('Qdrant ne répond pas ({erreur}). Les assistants continuent de répondre en recherche texte. Vérifiez que le conteneur « qdrant » tourne, puis revérifiez.') ?>, { erreur: rep.error })
                : <?= tj('Qdrant ne répond pas. Les assistants continuent de répondre en recherche texte. Vérifiez que le conteneur « qdrant » tourne, puis revérifiez.') ?>, 'error');
        } else {
            sub.textContent = [
                <?= tj('En ligne') ?> + (rep.version ? ' · v' + rep.version : ''),
                fill(plural(rep.collections, <?= tj('{n} collection') ?>, <?= tj('{n} collections') ?>), { n: rep.collections }),
                fill(plural(rep.points, <?= tj('{n} vecteur') ?>, <?= tj('{n} vecteurs') ?>), { n: rep.points.toLocaleString(LOCALE) }),
            ].join(' · ');
            setMessage(rep.issues > 0
                ? fill(plural(rep.issues, <?= tj('{n} point d\'attention ci-dessous.') ?>, <?= tj('{n} points d\'attention ci-dessous.') ?>), { n: rep.issues })
                : '', rep.issues > 0 ? 'warn' : 'info');
        }

        const missing = (rep.bases || []).some(b => b.state === 'missing');
        btnRep.hidden   = !(up && missing);
        btnPrune.hidden = !(up && (rep.orphans || []).length > 0);

        rowsEl.innerHTML = '';
        (rep.bases || []).forEach(b => {
            const row = document.createElement('div');
            row.className = 'kb-vec-row';
            row.dataset.state = b.state;

            const st = document.createElement('span');
            st.className = 'kb-vec-badge';
            st.textContent = b.label;
            st.title = b.hint || '';
            row.appendChild(st);

            const name = document.createElement('div');
            name.className = 'kb-vec-name';
            name.textContent = b.name;
            const code = document.createElement('code');
            code.textContent = b.collection;
            name.appendChild(code);
            row.appendChild(name);

            const nums = document.createElement('div');
            nums.className = 'kb-vec-nums';
            nums.title = <?= tj('Extraits en base MySQL (source de vérité) / vecteurs dans Qdrant') ?>;
            nums.innerHTML = fill(esc(NUMS), {
                extraits: '<strong>' + esc(b.chunks) + '</strong>',
                vecteurs: '<strong>' + esc(b.points === null ? '—' : b.points) + '</strong>',
            });
            row.appendChild(nums);

            const act = document.createElement('div');
            act.className = 'kb-vec-act';
            if (up && REINDEXABLE.indexOf(b.state) !== -1) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn-secondary';
                btn.textContent = <?= tj('Réindexer') ?>;
                btn.title = <?= tj('Re-vectorise tous les documents depuis les textes déjà extraits (ni re-téléchargement, ni ré-extraction).') ?>;
                btn.addEventListener('click', () => reindex(b.id, btn));
                act.appendChild(btn);
            }
            row.appendChild(act);
            rowsEl.appendChild(row);
        });

        (rep.orphans || []).forEach(o => {
            const row = document.createElement('div');
            row.className = 'kb-vec-row';
            row.dataset.state = 'orphan';
            row.innerHTML = '<span class="kb-vec-badge" title="'
                + esc(<?= tj('Collection vectorielle dont la base n\'existe plus : plus personne ne l\'interroge. « Purger les orphelines » la supprime.') ?>) + '">'
                + esc(<?= tj('Collection orpheline') ?>) + '</span>'
                + '<div class="kb-vec-name">' + esc(<?= tj('Base supprimée') ?>) + ' <code></code></div>'
                + '<div class="kb-vec-nums">' + fill(esc(NUMS), { extraits: '<strong>—</strong>', vecteurs: '<strong>' + esc(o.points) + '</strong>' })
                + '</div><div class="kb-vec-act"></div>';
            row.querySelector('code').textContent = o.name;
            rowsEl.appendChild(row);
        });

        if (!rowsEl.children.length) {
            rowsEl.innerHTML = '<div class="kb-vec-empty">' + esc(<?= tj('Aucune base de connaissances.') ?>) + '</div>';
        }
    }

    async function call(url, method) {
        const opts = {
            method: method || 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        };
        if (method === 'POST') {
            const fd = new FormData();
            fd.append('csrf_token', csrf());
            opts.body = fd;
            opts.headers['X-CSRF-Token'] = csrf();
        }
        const r = await fetch(url, opts);
        return await r.json().catch(() => ({}));
    }

    async function load() {
        if (busy) return;
        setBusy(true);
        try {
            const data = await call('/admin/knowledge-bases/qdrant/status');
            if (data && data.ok && data.report) render(data.report);
            else setMessage(<?= tj('Diagnostic impossible (session expirée ?). Rechargez la page.') ?>, 'error');
        } catch (e) {
            setMessage(<?= tj('Erreur réseau pendant le diagnostic.') ?>, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function act(url, done) {
        if (busy) return;
        setBusy(true);
        try {
            const data = await call(url, 'POST');
            if (data && data.report) render(data.report);
            setMessage(done(data || {}), data && data.ok === false ? 'error' : 'info');
        } catch (e) {
            setMessage(<?= tj('Erreur réseau. Réessayez.') ?>, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function reindex(kbId, btn) {
        btn.disabled = true;
        btn.textContent = <?= tj('Lancée…') ?>;
        try {
            const data = await call('/admin/knowledge-bases/' + kbId + '/reindex-async', 'POST');
            if (!data || !data.ok) {
                setMessage(data && data.error ? data.error : <?= tj('Réindexation impossible.') ?>, 'error');
                btn.disabled = false;
                btn.textContent = <?= tj('Réindexer') ?>;
                return;
            }
            setMessage(<?= tj('Réindexation lancée. Elle tourne en tâche de fond — « Revérifier » dans quelques instants pour suivre la remontée des vecteurs.') ?>, 'info');
        } catch (e) {
            setMessage(<?= tj('Erreur réseau. Réessayez.') ?>, 'error');
            btn.disabled = false;
            btn.textContent = <?= tj('Réindexer') ?>;
        }
    }

    btnRefr.addEventListener('click', load);
    btnRep.addEventListener('click', () => act('/admin/knowledge-bases/qdrant/repair', (d) => {
        if (d.error) return fill(<?= tj('Réparation impossible : {erreur}') ?>, { erreur: d.error });
        const n = (d.created || []).length;
        if (!n) return <?= tj('Aucune collection à recréer.') ?>;
        return fill(plural(n,
            <?= tj('{n} collection recréée. Réindexez les bases concernées pour y remettre les vecteurs.') ?>,
            <?= tj('{n} collections recréées. Réindexez les bases concernées pour y remettre les vecteurs.') ?>), { n: n });
    }));
    btnPrune.addEventListener('click', () => {
        if (!confirm(<?= tj('Supprimer les collections vectorielles qui n\'ont plus de base ? Elles ne sont plus interrogées par personne. Action irréversible.') ?>)) return;
        act('/admin/knowledge-bases/qdrant/prune', (d) => {
            if (d.error) return fill(<?= tj('Purge impossible : {erreur}') ?>, { erreur: d.error });
            const n = (d.dropped || []).length;
            return n
                ? fill(plural(n, <?= tj('{n} collection supprimée.') ?>, <?= tj('{n} collections supprimées.') ?>), { n: n })
                : <?= tj('Rien à purger.') ?>;
        });
    });

    load();
})();
</script>
</body>
</html>
