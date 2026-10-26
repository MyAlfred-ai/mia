<?php
$page     = 'llms-keys';
$llmTitle = t('Clés API');
// Phrase entière dans le dictionnaire : `{a}…{/a}` y marque le lien, posé
// APRÈS l'échappement (te() n'échappe pas les accolades).
$llmIntro = str_replace(['{a}', '{/a}'], ['<a href="/admin/llms/models">', '</a>'],
    te('Clés d\'accès des fournisseurs cloud et endpoint local. Activez ensuite les modèles sur {a}Modèles{/a}.'));

// Séparation LLMs cloud / local
$cloudKeys = array_filter($keys, fn($k) => $k['provider'] !== 'local');
$localKey  = array_values(array_filter($keys, fn($k) => $k['provider'] === 'local'))[0] ?? null;

require ROOT . '/templates/admin/llms/_head.php';
?>

    <?php if (!empty($llmSetupNeeded)): // calculé par admin-nav.php (via _head.php) : aucune clé API LLM active ?>
    <div class="llm-setup-banner" id="llmSetupCallout">
        <div class="llm-setup-banner-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
        </div>
        <div class="llm-setup-banner-text">
            <div class="llm-setup-banner-title"><?= te('Première configuration : c\'est ici que tout commence.') ?></div>
            <div class="llm-setup-banner-sub">
                <?= str_replace(['{a}', '{/a}'], ['<a href="/admin/llms/models">', '</a>'],
                    te('Enregistrez au moins une clé API de fournisseur cloud ci-dessous (ou connectez un LLM local) : sans elle, MIA ne peut répondre à personne. Ce signal s\'éteint dès la première clé enregistrée — pensez ensuite à activer les modèles souhaités sur {a}Modèles{/a}.')) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Fournisseurs cloud (OpenAI, Anthropic, Mistral…) -->
    <div class="card" style="margin-bottom:24px">
        <h3 style="margin-bottom:4px;font-size:15px"><?= te('Fournisseurs cloud') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px"><?= te('Renseignez la clé API de chaque fournisseur et choisissez le modèle à utiliser.') ?></p>
        <form method="POST" action="/admin/api-keys" data-autosave>
            <?= Csrf::field() ?>
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                <!-- « Fournisseur » désigne la CIBLE de la configuration, ce
                     n'est pas une valeur à enregistrer : en changer revient à
                     ouvrir une autre fiche, pas à modifier celle en cours.
                     D'où `data-autosave-silent` (champ envoyé, mais qui ne
                     déclenche aucun enregistrement). -->
                <div class="form-group" style="margin:0;flex:0 0 180px" data-autosave-silent>
                    <label><?= te('Fournisseur', [], 'llm') ?></label>
                    <select name="provider" id="providerSelect" class="form-select">
                        <option value="openai">ChatGPT (OpenAI)</option>
                        <option value="anthropic">Claude (Anthropic)</option>
                        <option value="mistral">Mistral</option>
                        <option value="perplexity">Perplexity</option>
                        <option value="gemini">Gemini (Google)</option>
                        <option value="deepseek">DeepSeek</option>
                        <option value="moonshot">Kimi (Moonshot)</option>
                        <option value="zai">GLM (Z.ai)</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;flex:0 0 240px">
                    <label><?= te('Modèle') ?></label>
                    <select name="model" id="modelSelect" class="form-select"></select>
                </div>
                <div class="form-group" style="margin:0;flex:1;min-width:220px">
                    <label><?= te('Clé API') ?></label>
                    <!-- `required` posé par le JS selon le fournisseur choisi :
                         une clé déjà enregistrée n'est jamais réaffichée, et
                         laisser le champ vide la conserve désormais. -->
                    <input type="password" name="key" id="cloudKeyInput" class="form-input" placeholder="sk-…">
                </div>
            </div>
            <p id="modelNote" style="margin-top:8px;font-size:12px;color:var(--text-muted)"></p>
            <div style="margin-top:10px">
                <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
            </div>
        </form>

        <?php if (!empty($cloudKeys)): ?>
        <div class="table-wrap" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
        <table class="api-keys-table">
            <thead>
                <tr>
                    <th><?= te('Fournisseur', [], 'llm') ?></th>
                    <th><?= te('Clé API') ?></th>
                    <th><?= te('Statut') ?></th>
                    <th><?= te('Modifié') ?></th>
                    <th><?= te('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cloudKeys as $ck):
                $p     = $ck['provider'];
                $plain = Encryption::decrypt($ck['key_enc'] ?? '');
            ?>
            <tr id="row-<?= $ck['id'] ?>">
                <td data-label="<?= te('Fournisseur', [], 'llm') ?>">
                    <div style="display:flex;align-items:center;gap:8px">
                        <?php if (!empty($logos[$p])): ?>
                            <img src="<?= htmlspecialchars($logos[$p]) ?>" alt="" style="width:18px;height:18px;object-fit:contain">
                        <?php endif; ?>
                        <span style="font-size:13px"><?= htmlspecialchars($providerNames[$p] ?? $p) ?></span>
                    </div>
                </td>
                <td data-label="<?= te('Clé API') ?>">
                    <code style="font-size:12px;color:var(--text-muted);letter-spacing:.04em">
                        <?= htmlspecialchars(substr($plain, 0, 10)) ?>••••••••
                    </code>
                </td>
                <td data-label="<?= te('Statut') ?>">
                    <span class="badge <?= $ck['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $ck['is_active'] ? te('Active') : te('Inactive') ?></span>
                    <span class="test-result" id="test-result-<?= $ck['id'] ?>" style="display:none;margin-left:8px;font-size:12px"></span>
                </td>
                <td data-label="<?= te('Modifié') ?>" style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars(substr($ck['updated_at'], 0, 10)) ?></td>
                <td data-label="<?= te('Actions') ?>" class="api-keys-actions">
                    <div class="api-keys-actions-row">
                        <button class="btn-test" data-id="<?= $ck['id'] ?>" data-provider="<?= htmlspecialchars($p) ?>"><?= te('Tester') ?></button>
                        <button type="button" class="btn-edit-key"
                                data-provider="<?= htmlspecialchars($p) ?>"
                                style="background:none;border:none;color:var(--accent);font-size:12px;cursor:pointer"><?= te('Modifier') ?></button>
                        <form method="POST" action="/admin/api-keys/<?= $ck['id'] ?>/delete"
                              onsubmit='return confirm(<?= tj('Supprimer la clé {fournisseur} ?', ['fournisseur' => $providerNames[$p] ?? $p]) ?>)'
                              style="margin:0">
                            <?= Csrf::field() ?>
                            <button type="submit" style="background:none;border:none;color:#ef4444;font-size:12px;cursor:pointer"><?= te('Supprimer') ?></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- LLM local (OpenAI-compatible) -->
    <div class="card" id="local-config" style="margin-bottom:24px">
        <h3 style="margin-bottom:4px;font-size:15px"><?= te('LLM local (OpenAI-compatible)') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
            <?= str_replace('{url}', '<code>https://ollama.com/v1</code>', teb('Connectez un serveur local tel qu\'{b}Ollama{/b}, {b}LM Studio{/b} ou {b}Jan{/b}, ou le {b}cloud Ollama{/b} ({url} + clé API). L\'URL doit pointer vers une API compatible OpenAI Chat Completions.')) ?>
        </p>
        <form method="POST" action="/admin/api-keys" data-autosave id="localLlmForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="provider" value="local">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin:0">
                    <label><?= te('Nom affiché aux utilisateurs') ?> <span style="color:#ef4444">*</span></label>
                    <input type="text" name="display_name" class="form-input"
                           value="<?= htmlspecialchars($localKey['display_name'] ?? '') ?>"
                           placeholder="<?= te('ex : {exemple}', ['exemple' => 'Llama 3.2 Local']) ?>" required>
                </div>
                <div class="form-group" style="margin:0">
                    <label><?= te('Modèle') ?> <span style="color:#ef4444">*</span></label>
                    <input type="text" name="model" class="form-input"
                           value="<?= htmlspecialchars($localKey['model'] ?? '') ?>"
                           placeholder="<?= te('ex : {exemple}', ['exemple' => 'llama3.2, mistral, phi3']) ?>" required>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group" style="margin:0">
                    <label><?= te('URL de base') ?> <span style="color:#ef4444">*</span></label>
                    <input type="url" name="base_url" class="form-input"
                           value="<?= htmlspecialchars($localKey['base_url'] ?? '') ?>"
                           placeholder="http://localhost:11434/v1" required>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                        <?= strtr(te('Ollama : {ollama} · LM Studio : {lmstudio} · cloud Ollama : {cloud}'), [
                            '{ollama}'   => '<code>http://localhost:11434/v1</code>',
                            '{lmstudio}' => '<code>http://localhost:1234/v1</code>',
                            '{cloud}'    => '<code>https://ollama.com/v1</code>',
                        ]) ?>
                    </div>
                </div>
                <div class="form-group" style="margin:0">
                    <label><?= te('Clé API') ?> <span style="color:var(--text-muted)"><?= te('(optionnel)') ?></span></label>
                    <!-- Vidé après enregistrement : le secret n'a pas à rester
                         dans la page, et un champ vide conserve la clé. -->
                    <input type="password" name="key" data-autosave-reset class="form-input"
                           placeholder="<?= te('Laissez vide si non requis') ?>">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= te('Ollama et LM Studio en local n\'en ont pas besoin ; le cloud Ollama l\'exige.') ?></div>
                </div>
            </div>
            <!-- Function-calling : impossible à deviner pour un modèle servi en
                 local (un Llama 3.2 le sait, un Phi-3 non). Décoché par défaut :
                 cocher un modèle incapable ferait échouer chaque message qui
                 porte un outil (connecteurs, outils d'assistant). -->
            <label style="display:flex;align-items:flex-start;gap:8px;margin-top:14px;font-size:13px;cursor:pointer">
                <input type="checkbox" name="tool_use" value="1" style="margin-top:3px"
                       <?= LocalAdapter::toolUseEnabled() ? 'checked' : '' ?>>
                <span>
                    <?= te('Ce modèle sait appeler des outils (function calling)') ?>
                    <span style="display:block;font-size:11px;color:var(--text-muted);margin-top:2px">
                        <?= te('Ouvre les connecteurs (Drive, Gmail, agendas…) et les outils des assistants à ce modèle. Ne cochez que si votre modèle le prend en charge (Llama 3.1+, Qwen 2.5+, Mistral…) : sinon les messages qui utilisent un outil échoueront.') ?>
                    </span>
                </span>
            </label>
            <div style="display:flex;align-items:center;gap:12px;margin-top:16px">
                <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
                <?php if ($localKey): ?>
                    <button type="button" class="btn-test" data-id="local" data-provider="local">
                        <?= te('Tester la connexion') ?>
                    </button>
                    <span class="test-result" id="test-result-local" style="display:none;font-size:12px"></span>
                <?php endif; ?>
            </div>
        </form>
    </div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
// Textes de l'écran, traduits côté serveur (ce script n'en écrit aucun).
const KEYS_TXT = {
    defaut:      <?= tj(' (défaut)') ?>,
    keySaved:    <?= tj('Clé enregistrée — laissez vide pour la conserver') ?>,
    webNative:   <?= tj('✓ Recherche web native.') ?>,
    testing:     <?= tj('Test en cours…') ?>,
    netError:    <?= tj('✗ Erreur réseau') ?>,
    testLocal:   <?= tj('Tester la connexion') ?>,
    test:        <?= tj('Tester') ?>,
};
const MODEL_CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;
<?php
// Ce qui est DÉJÀ enregistré, par fournisseur. Sans cette carte, changer de
// fournisseur affichait le modèle par défaut du catalogue et non celui en
// service : l'enregistrement automatique l'aurait alors écrit tel quel.
$savedByProvider = [];
foreach ($cloudKeys ?? [] as $ck) {
    $savedByProvider[(string)$ck['provider']] = [
        'model'   => (string)($ck['model'] ?? ''),
        'has_key' => !empty($ck['key_enc']),
    ];
}
?>
const SAVED_KEYS = <?= json_encode($savedByProvider, JSON_UNESCAPED_UNICODE) ?>;

const providerSel = document.getElementById('providerSelect');
const modelSel    = document.getElementById('modelSelect');
const modelNote   = document.getElementById('modelNote');
const keyInput    = document.getElementById('cloudKeyInput');

function populateModels(provider) {
    const models = MODEL_CATALOG[provider] || {};
    const saved  = SAVED_KEYS[provider] || null;
    modelSel.innerHTML = '';
    Object.entries(models).forEach(([id, info]) => {
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = info.label + (info.default ? KEYS_TXT.defaut : '');
        // Le modèle en service prime sur le défaut du catalogue.
        if (saved && saved.model ? id === saved.model : info.default) { opt.selected = true; }
        modelSel.appendChild(opt);
    });

    if (keyInput) {
        // Une clé déjà en place se conserve en laissant le champ vide ; sans
        // clé, elle reste obligatoire (sinon l'enregistrement est refusé).
        const hasKey = !!(saved && saved.has_key);
        keyInput.value = '';
        keyInput.required = !hasKey;
        keyInput.placeholder = hasKey ? KEYS_TXT.keySaved : 'sk-…';
    }
    updateNote();
}

function updateNote() {
    const info = (MODEL_CATALOG[providerSel.value] || {})[modelSel.value];
    if (info) {
        let parts = [info.note];
        if (info.web_native) parts.push(KEYS_TXT.webNative);
        if (info.limits?.length) parts.push('⚠ ' + info.limits.join(' · '));
        modelNote.textContent = parts.join(' ');
    } else {
        modelNote.textContent = '';
    }
}

providerSel.addEventListener('change', () => populateModels(providerSel.value));
modelSel.addEventListener('change', updateNote);
populateModels(providerSel.value);

// Extinction du signal de configuration initiale SANS rechargement (pastilles
// de la nav + bandeau de cette page) : un enregistrement réussi vient de poser
// une ligne active dans api_keys, exactement la condition qui éteint
// ApiKey::hasActiveLlm() côté serveur au prochain rendu.
function clearLlmSetupAttention() {
    document.querySelectorAll('.nav-attention-dot').forEach(d => d.remove());
    document.querySelectorAll('.nav-attention').forEach(g => g.classList.remove('nav-attention'));
    const callout = document.getElementById('llmSetupCallout');
    if (callout) callout.remove();
}

// Après un enregistrement automatique : un fournisseur qui n'avait pas encore
// de clé vient d'entrer dans la liste rendue côté serveur — il faut recharger
// pour l'y voir. Sinon on se contente de rafraîchir l'état local (le champ clé
// redevient facultatif, le modèle en service est mémorisé).
providerSel.form.addEventListener('mia:autosaved', () => {
    clearLlmSetupAttention();
    const provider = providerSel.value;
    const wasNew   = !SAVED_KEYS[provider];
    SAVED_KEYS[provider] = { model: modelSel.value, has_key: true };
    if (wasNew) { window.location.reload(); return; }
    populateModels(provider);
});

// Le formulaire local, lui, ne recharge jamais la page : sans ce relais, la
// pastille resterait allumée après la toute première configuration d'un
// LLM local (Ollama, LM Studio…) jusqu'à la navigation suivante.
document.getElementById('localLlmForm')?.addEventListener('mia:autosaved', clearLlmSetupAttention);

// ---- Bouton Modifier : préremplit le formulaire du haut ----
document.querySelectorAll('.btn-edit-key').forEach(btn => {
    btn.addEventListener('click', () => {
        const provider = btn.dataset.provider;
        providerSel.value = provider;
        populateModels(provider);
        providerSel.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        providerSel.closest('.card').querySelector('input[name="key"]').focus();
    });
});

// ---- Bouton Tester (validité de la clé) ----
document.querySelectorAll('.btn-test').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id       = btn.dataset.id;
        const provider = btn.dataset.provider;
        const result   = document.getElementById('test-result-' + id);

        btn.disabled    = true;
        btn.textContent = '…';
        result.style.display = '';
        result.style.color   = 'var(--text-muted)';
        result.textContent   = KEYS_TXT.testing;

        try {
            const res  = await fetch('/admin/api-keys/' + provider + '/test', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF },
            });
            const data = await res.json();
            result.style.color = data.ok ? '#6ee7b7' : '#fca5a5';
            result.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
        } catch (e) {
            result.style.color = '#fca5a5';
            result.textContent = KEYS_TXT.netError;
        }

        btn.disabled    = false;
        btn.textContent = btn.dataset.provider === 'local' ? KEYS_TXT.testLocal : KEYS_TXT.test;
    });
});
</script>

<?php require ROOT . '/templates/admin/llms/_foot.php'; ?>
