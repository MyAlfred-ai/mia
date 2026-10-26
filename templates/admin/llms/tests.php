<?php
$page     = 'llms-tests';
$llmTitle = t('Tests');
$llmIntro = te('Vérification du bon fonctionnement de chaque LLM activé : conversation, recherche web, fichiers.');

require ROOT . '/templates/admin/llms/_head.php';
?>

    <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;margin:-8px 0 16px">
        <span id="globalScore" style="font-size:13px;color:var(--text-muted)"></span>
        <button class="btn-primary" id="runAllBtn" <?= empty($activeInstances) ? 'disabled' : '' ?>>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            <?= te('Lancer tous les tests') ?>
        </button>
    </div>

    <?php if (empty($activeInstances)): ?>
    <div class="card" style="text-align:center;padding:40px">
        <p style="color:var(--text-muted);margin-bottom:12px"><?= te('Aucun LLM activé.') ?></p>
        <a href="/admin/llms/models" class="btn-primary"><?= te('Activer des modèles') ?></a>
    </div>
    <?php endif; ?>

    <?php foreach ($activeInstances as $lm):
        $provider  = $lm['provider'];
        $model     = $lm['model'];
        $info      = $catalog[$provider][$model] ?? [];
        $tags      = $info['tags']       ?? [];
        $webNative = !empty($info['web_native']);
        $hasMedia  = in_array('vision', $tags, true) || in_array('fichiers', $tags, true);
        $logoSrc   = $logos[$provider] ?? '';
        $slug      = $provider . '::' . $model;
    ?>
    <div class="card test-card" style="margin-bottom:16px" data-provider="<?= htmlspecialchars($provider) ?>" data-model="<?= htmlspecialchars($model) ?>">

        <!-- En-tête LLM -->
        <div class="test-card-header">
            <div class="test-card-identity">
                <?php if ($logoSrc): ?>
                    <img src="<?= htmlspecialchars($logoSrc) ?>" alt="" class="test-card-logo">
                <?php endif; ?>
                <div class="test-card-name">
                    <span class="test-card-label"><?= htmlspecialchars($lm['display_name']) ?></span>
                    <code class="test-card-slug"><?= htmlspecialchars($slug) ?></code>
                    <?php if ($slug === $defaultLlm): ?><span class="badge badge-llm-default"><?= te('Défaut') ?></span><?php endif; ?>
                    <?php if ($slug === $fallbackLlm): ?><span class="badge badge-llm-fallback"><?= te('Secours') ?></span><?php endif; ?>
                </div>
            </div>
            <div class="test-card-actions">
                <span class="card-score"></span>
                <button class="btn-run-llm"><?= te('Tester ce LLM') ?></button>
            </div>
        </div>

        <!-- Lignes de tests -->
        <table class="test-table">
            <tbody>
                <!-- Basique -->
                <tr class="test-row" data-type="basic">
                    <td class="test-icon">○</td>
                    <td class="test-label"><?= te('Conversation de base') ?></td>
                    <td class="test-duration"></td>
                    <td class="test-message"><?= te('En attente') ?></td>
                </tr>

                <!-- Recherche web -->
                <tr class="test-row <?= !$webNative ? 'test-pre-skip' : '' ?>" data-type="web">
                    <td class="test-icon"><?= $webNative ? '○' : '—' ?></td>
                    <td class="test-label"><?= te('Recherche web') ?></td>
                    <td class="test-duration"></td>
                    <td class="test-message"><?= $webNative ? te('En attente') : te('Non supporté nativement par ce modèle') ?></td>
                </tr>

                <!-- Vision / Fichier -->
                <tr class="test-row <?= !$hasMedia ? 'test-pre-skip' : '' ?>" data-type="file">
                    <td class="test-icon"><?= $hasMedia ? '○' : '—' ?></td>
                    <td class="test-label"><?= te('Upload image / fichier') ?></td>
                    <td class="test-duration"></td>
                    <td class="test-message"><?= $hasMedia ? te('En attente') : te('Vision/fichiers non supportés par ce modèle') ?></td>
                </tr>
            </tbody>
        </table>

    </div>
    <?php endforeach; ?>

<script>
const CSRF  = document.querySelector('meta[name="csrf-token"]')?.content || '';
const TYPES = ['basic', 'web', 'file'];
// Textes de l'écran, traduits côté serveur (ce script n'en écrit aucun).
// Le score d'une carte garde la forme « n/m … » : updateGlobalScore() la relit.
const TESTS_TXT = {
    running:   <?= tj('En cours…') ?>,
    skipped:   <?= tj('Non supporté') ?>,
    netError:  <?= tj('Erreur réseau') ?>,
    cardScore: <?= tj('{ok}/{total} OK') ?>,
    retest:    <?= tj('Retester') ?>,
    global:    <?= tj('{ok}/{total} tests OK ({pct}%)') ?>,
    rerunAll:  <?= tj('Relancer tous les tests') ?>,
};
function testsFmt(s, vars) {
    return s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
}

const icon = {
    pending : '<span style="color:var(--text-muted)">○</span>',
    running : '<span style="color:var(--accent)">◐</span>',
    ok      : '<span style="color:#6ee7b7">✓</span>',
    error   : '<span style="color:#f87171">✗</span>',
    skip    : '<span style="color:var(--text-muted)">—</span>',
};

async function runSingleTest(card, type) {
    const row = card.querySelector(`.test-row[data-type="${type}"]`);
    if (!row) return null;

    row.querySelector('.test-icon').innerHTML    = icon.running;
    row.querySelector('.test-duration').textContent = '';
    row.querySelector('.test-message').textContent = TESTS_TXT.running;
    row.querySelector('.test-message').style.color = 'var(--text-muted)';

    try {
        const res  = await fetch('/admin/tests/run', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body   : new URLSearchParams({ provider: card.dataset.provider, model: card.dataset.model, type }),
        });
        const data = await res.json();

        if (data.skipped) {
            row.querySelector('.test-icon').innerHTML    = icon.skip;
            row.querySelector('.test-message').textContent = data.message || TESTS_TXT.skipped;
            row.querySelector('.test-message').style.color = 'var(--text-muted)';
        } else if (data.ok) {
            row.querySelector('.test-icon').innerHTML    = icon.ok;
            row.querySelector('.test-message').textContent = data.message;
            row.querySelector('.test-message').style.color = '#6ee7b7';
        } else {
            row.querySelector('.test-icon').innerHTML    = icon.error;
            row.querySelector('.test-message').textContent = data.message;
            row.querySelector('.test-message').style.color = '#f87171';
        }

        if (data.duration_ms !== undefined) {
            row.querySelector('.test-duration').textContent = data.duration_ms + ' ms';
        }

        return data;
    } catch (e) {
        row.querySelector('.test-icon').innerHTML    = icon.error;
        row.querySelector('.test-message').textContent = TESTS_TXT.netError;
        row.querySelector('.test-message').style.color = '#f87171';
        return { ok: false };
    }
}

async function runCard(card) {
    const btn   = card.querySelector('.btn-run-llm');
    const score = card.querySelector('.card-score');
    btn.disabled    = true;
    btn.textContent = '…';

    let ok = 0, total = 0;

    for (const type of TYPES) {
        const result = await runSingleTest(card, type);
        if (result && !result.skipped) {
            total++;
            if (result.ok) ok++;
        }
    }

    score.textContent = testsFmt(TESTS_TXT.cardScore, { ok, total });
    score.style.color = ok === total ? '#6ee7b7' : ok > 0 ? '#f59e0b' : '#f87171';
    btn.disabled    = false;
    btn.textContent = TESTS_TXT.retest;

    return { ok, total };
}

function updateGlobalScore() {
    const cards  = [...document.querySelectorAll('.test-card')];
    let gOk = 0, gTotal = 0;
    cards.forEach(card => {
        const s = card.querySelector('.card-score').textContent;
        const m = s.match(/(\d+)\/(\d+)/);
        if (m) { gOk += +m[1]; gTotal += +m[2]; }
    });
    if (gTotal > 0) {
        const pct = Math.round(gOk / gTotal * 100);
        document.getElementById('globalScore').textContent = testsFmt(TESTS_TXT.global, { ok: gOk, total: gTotal, pct });
        document.getElementById('globalScore').style.color = gOk === gTotal ? '#6ee7b7' : gOk > 0 ? '#f59e0b' : '#f87171';
    }
}

// Boutons individuels
document.querySelectorAll('.btn-run-llm').forEach(btn => {
    btn.addEventListener('click', async () => {
        await runCard(btn.closest('.test-card'));
        updateGlobalScore();
    });
});

// Bouton global
const runAllBtn = document.getElementById('runAllBtn');
if (runAllBtn) {
    runAllBtn.addEventListener('click', async () => {
        runAllBtn.disabled    = true;
        runAllBtn.textContent = '…';

        document.getElementById('globalScore').textContent = '';

        for (const card of document.querySelectorAll('.test-card')) {
            await runCard(card);
            updateGlobalScore();
        }

        runAllBtn.disabled  = false;
        runAllBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        runAllBtn.append(TESTS_TXT.rerunAll);
    });
}
</script>

<?php require ROOT . '/templates/admin/llms/_foot.php'; ?>
