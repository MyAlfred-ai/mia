<?php
/**
 * Carte « MIA pour vous » — mémoire long terme de l'utilisateur.
 *
 * Inclus par /settings/ai (Préférences IA) SEUL, sous « Votre contexte », dans
 * la section « Ce que MIA sait de vous » ; Mon profil y renvoie par une carte
 * au lieu de dupliquer l'éditeur (deux portes pour une liste obligeaient la
 * doc à l'expliquer). Sa tête reprend .card-head (icône + aide).
 *
 * Variables attendues : $memories (list<array>).
 *
 * La liste est rendue en JS depuis la charge utile JSON ci-dessous : chaque
 * mutation (ajout / édition / suppression) renvoie la liste à jour et la carte
 * se re-rend depuis la source de vérité, jamais depuis un état deviné. Un
 * <noscript> garde le contenu lisible sans JavaScript.
 */
$memories = $memories ?? [];
?>
<style>
    /* Tête de carte = .card-head de main.css (icône, titre, aide), le compteur
       à droite. La phrase « pour en ajouter… » suit, sous la tête. */
    .memory-count { font-size:12px; color:var(--text-muted); font-variant-numeric:tabular-nums; white-space:nowrap; align-self:flex-start; padding-top:2px; }
    .memory-howto { font-size:13px; color:var(--text-muted); margin:0; line-height:1.5; }
    .memory-count.is-full { color:#c92a2a; font-weight:600; }
    .memory-list { display:flex; flex-direction:column; gap:8px; margin:14px 0 0; }
    .memory-item {
        display:flex; align-items:flex-start; gap:8px;
        border:1.5px solid var(--border); border-radius:10px; padding:8px 10px;
        background:var(--bg-surface, transparent);
        transition:border-color .15s;
    }
    .memory-item:focus-within { border-color:var(--accent); }
    .memory-item textarea {
        flex:1; border:0; background:transparent; resize:none; overflow:hidden;
        font:inherit; font-size:13px; line-height:1.5; color:inherit;
        padding:2px 0; min-height:22px;
    }
    .memory-item textarea:focus { outline:none; }
    .memory-item-meta { font-size:11px; color:var(--text-muted); white-space:nowrap; padding-top:4px; }
    .memory-del {
        background:none; border:0; cursor:pointer; color:var(--text-muted);
        padding:4px; border-radius:6px; display:flex; align-items:center;
    }
    .memory-del:hover { color:#c92a2a; background:color-mix(in srgb, #c92a2a 12%, transparent); }
    .memory-empty {
        margin-top:14px; padding:14px; border:1.5px dashed var(--border); border-radius:10px;
        font-size:13px; color:var(--text-muted); line-height:1.5;
    }
    .memory-add { display:flex; gap:8px; margin-top:12px; }
    .memory-add .form-input { flex:1; font-size:13px; }
    .memory-feedback { margin-top:10px; font-size:12px; }
    .memory-feedback.is-error { color:#c92a2a; }
    .memory-feedback.is-ok    { color:var(--text-muted); }
    @media (max-width: 768px) {
        .memory-add { flex-direction:column; }
        .memory-add .btn-primary { width:100%; }
    }
</style>

<div class="card" style="margin-bottom:20px" id="memoryCard">
    <div class="card-head">
        <span class="card-ic" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
        </span>
        <div class="card-head-text">
            <h3><?= te('MIA pour vous') ?></h3>
            <p><?= te('Ce que MIA retient de vous d\'une conversation à l\'autre. Ces informations sont ajoutées à « votre contexte » au début de chaque discussion.') ?></p>
        </div>
        <span class="memory-count" id="memoryCount"></span>
    </div>
    <p class="memory-howto">
        <?= te('Pour en ajouter pendant une conversation, demandez-le simplement :') ?>
        <em><?= te('« Souviens-toi que je préfère les réunions du matin »') ?></em><?= te('. Vous pouvez modifier ou supprimer chaque souvenir ici.') ?>
    </p>

    <div class="memory-list" id="memoryList"></div>

    <div class="memory-add">
        <input type="text" class="form-input" id="memoryInput"
               maxlength="<?= (int)UserMemory::MAX_LEN ?>"
               placeholder="<?= te('Ex. : Travaille au département marketing') ?>">
        <button type="button" class="btn-primary" id="memoryAddBtn"><?= te('Ajouter') ?></button>
    </div>

    <div class="memory-feedback" id="memoryFeedback" hidden></div>

    <noscript>
        <ul style="margin-top:14px;font-size:13px;line-height:1.6">
            <?php foreach ($memories as $memory): ?>
                <li><?= htmlspecialchars((string)$memory['content']) ?></li>
            <?php endforeach; ?>
            <?php if ($memories === []): ?>
                <li style="color:var(--text-muted)"><?= te('Aucun souvenir enregistré.') ?></li>
            <?php endif; ?>
        </ul>
        <p style="font-size:12px;color:var(--text-muted)">
            <?= te('L\'édition de cette section nécessite JavaScript.') ?>
        </p>
    </noscript>
</div>

<script type="application/json" id="memoryBootstrap"><?= json_encode([
    'memories' => $memories,
    'max'      => UserMemory::MAX_ENTRIES,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<script>
(function () {
    const card = document.getElementById('memoryCard');
    if (!card) return;

    const listEl     = document.getElementById('memoryList');
    const countEl    = document.getElementById('memoryCount');
    const inputEl    = document.getElementById('memoryInput');
    const addBtn     = document.getElementById('memoryAddBtn');
    const feedbackEl = document.getElementById('memoryFeedback');
    const csrf       = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let state = { memories: [], max: 50 };
    try {
        state = JSON.parse(document.getElementById('memoryBootstrap').textContent) || state;
    } catch (_) {}

    function feedback(message, isError) {
        if (!message) { feedbackEl.hidden = true; feedbackEl.textContent = ''; return; }
        feedbackEl.hidden = false;
        feedbackEl.textContent = message;
        feedbackEl.classList.toggle('is-error', !!isError);
        feedbackEl.classList.toggle('is-ok', !isError);
    }

    // Ajuste la hauteur au contenu. Ne mesure JAMAIS une largeur nulle : dans un
    // onglet ouvert en arrière-plan la mise en page est repliée, le texte
    // s'enroule sur une colonne d'un caractère et la hauteur figée reste absurde
    // au retour sur l'onglet. On refait donc le calcul dès que la largeur réelle
    // arrive (ResizeObserver ci-dessous).
    function autoGrow(textarea) {
        if (!textarea.clientWidth) return;
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }

    function fitAll() {
        listEl.querySelectorAll('textarea').forEach(autoGrow);
    }

    async function call(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body,
        });
        let data = null;
        try { data = await res.json(); } catch (_) {}
        if (data && Array.isArray(data.memories)) {
            state.memories = data.memories;
            if (typeof data.max === 'number') state.max = data.max;
            render();
        }
        if (!res.ok || (data && data.ok === false)) {
            feedback((data && data.error) || <?= tj('Opération impossible. Réessayez.') ?>, true);
            return false;
        }
        feedback('', false);
        return true;
    }

    function buildItem(memory) {
        const item = document.createElement('div');
        item.className = 'memory-item';

        const textarea = document.createElement('textarea');
        textarea.value = memory.content;
        textarea.rows = 1;
        textarea.maxLength = <?= (int)UserMemory::MAX_LEN ?>;
        textarea.setAttribute('aria-label', 'Souvenir');
        textarea.addEventListener('input', () => autoGrow(textarea));
        // Entrée = valider (le souvenir est une phrase courte, pas un paragraphe).
        textarea.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); textarea.blur(); }
            if (e.key === 'Escape') { textarea.value = memory.content; textarea.blur(); }
        });
        textarea.addEventListener('blur', async () => {
            const value = textarea.value.trim();
            if (value === memory.content || value === '') { textarea.value = memory.content; autoGrow(textarea); return; }
            const body = new FormData();
            body.append('content', value);
            await call('/settings/memory/' + encodeURIComponent(memory.id), body);
        });

        const meta = document.createElement('span');
        meta.className = 'memory-item-meta';
        meta.textContent = memory.source === 'chat' ? <?= tj('via chat') ?> : <?= tj('ajouté ici') ?>;

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'memory-del';
        del.title = <?= tj('Supprimer ce souvenir') ?>;
        del.setAttribute('aria-label', <?= tj('Supprimer ce souvenir') ?>);
        del.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>';
        del.addEventListener('click', async () => {
            del.disabled = true;
            const body = new FormData();
            const ok = await call('/settings/memory/' + encodeURIComponent(memory.id) + '/delete', body);
            if (!ok) del.disabled = false;
        });

        item.append(textarea, meta, del);
        return item;
    }

    function render() {
        listEl.innerHTML = '';
        countEl.textContent = state.memories.length + ' / ' + state.max;
        countEl.classList.toggle('is-full', state.memories.length >= state.max);

        if (state.memories.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'memory-empty';
            empty.textContent = <?= tj("Aucun souvenir pour l'instant. Demandez à MIA de retenir quelque chose pendant une conversation, ou ajoutez-le ci-dessous.") ?>;
            listEl.appendChild(empty);
            return;
        }
        state.memories.forEach(memory => listEl.appendChild(buildItem(memory)));
        fitAll();
    }

    // Re-calibrage au seul changement de LARGEUR (un changement de hauteur vient
    // de nos propres ajustements — l'observer boucherait).
    if (typeof ResizeObserver !== 'undefined') {
        let lastWidth = 0;
        new ResizeObserver(entries => {
            const width = Math.round(entries[0].contentRect.width);
            if (width && width !== lastWidth) { lastWidth = width; fitAll(); }
        }).observe(listEl);
    }
    window.addEventListener('resize', fitAll);

    async function add() {
        const value = inputEl.value.trim();
        if (value === '') { inputEl.focus(); return; }
        addBtn.disabled = true;
        const body = new FormData();
        body.append('content', value);
        if (await call('/settings/memory', body)) inputEl.value = '';
        addBtn.disabled = false;
        inputEl.focus();
    }

    addBtn.addEventListener('click', add);
    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); add(); }
    });

    render();
})();
</script>
