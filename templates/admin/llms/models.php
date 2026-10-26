<?php
$page     = 'llms-models';
$llmTitle = t('Modèles', [], 'llm');
// Phrase entière dans le dictionnaire : `{a1}…{/a1}` et `{a2}…{/a2}` y
// marquent les liens, posés APRÈS l'échappement.
$llmIntro = str_replace(
    ['{a1}', '{/a1}', '{a2}', '{/a2}'],
    ['<a href="/admin/llms">', '</a>', '<a href="/admin/company#vocabulaire">', '</a>'],
    te('Choisissez le comportement par défaut, puis activez les modèles que vos utilisateurs pourront sélectionner dans le chat. Un modèle s\'active si la clé API de son fournisseur est enregistrée sur {a1}Clés API{/a1}. Le vocabulaire de l\'entreprise (sigles, lexique maison) se règle dans {a2}Organisation → Entreprise{/a2}.')
);

// Lookup actifs : [provider][model] => row
$activeLlms = [];
foreach ($llmInstances as $lm) {
    $activeLlms[$lm['provider']][$lm['model']] = $lm;
}
$cloudKeys       = array_filter($keys, fn($k) => $k['provider'] !== 'local');
$localKey        = array_values(array_filter($keys, fn($k) => $k['provider'] === 'local'))[0] ?? null;
$apiKeyProviders = array_column(array_values($cloudKeys), null, 'provider');

// Arrêt annoncé par l'éditeur (bloc `retirement` du catalogue) d'un slug
// « provider::model » — null sinon. Source unique : LlmCatalog::retirement().
$llmRetirement = static function (string $slug): ?array {
    if (!str_contains($slug, '::')) {
        return null;
    }
    [$p, $m] = explode('::', $slug, 2);
    return LlmCatalog::retirement($p, $m);
};

require ROOT . '/templates/admin/llms/_head.php';
?>

    <!-- « Comportement par défaut » (LLM par défaut, secours) vit en tête de
         la page des modèles : le défaut se choisit parmi les modèles activés
         juste en dessous. Ancienne adresse /admin/llms/defaults → 301 vers
         #defaut. Le vocabulaire commun, qui vivait ici, est désormais celui de
         l'entreprise (Organisation → Entreprise). -->
    <section id="defaut">
    <form method="POST" action="/admin/llms/defaults" data-autosave>
        <?= Csrf::field() ?>

        <div class="card" style="margin-bottom:20px">
            <h2 style="font-size:15px;margin:0 0 4px"><?= te('Comportement par défaut') ?></h2>
            <p style="font-size:12px;color:var(--text-muted);margin:0 0 14px;max-width:640px">
                <?= te('Le LLM par défaut répond aux conversations sans assistant. Le LLM de secours prend le relais en cas de panne.') ?>
            </p>
            <?php if (empty($llms)): ?>
                <p style="font-size:13px;color:var(--text-muted);margin:0">
                    <?= te("Aucune instance LLM active pour le moment : activez d'abord un modèle dans la liste ci-dessous, le premier devient le modèle par défaut.") ?>
                </p>
            <?php else: ?>
            <?php if ($defaultLlm !== '' && $defaultLlmStored !== $defaultLlm): ?>
                <?php /* Le réglage enregistré ne désigne aucun modèle disponible
                         (instance neuve : « openai », ou modèle retiré). MIA
                         utilise le défaut effectif — la liste le montre — mais
                         rien ne l'a enregistré : resélectionner le même modèle
                         n'émettant aucun évènement, la confirmation passe par
                         un bouton. */ ?>
                <div id="defaultLlmUnsaved" role="status"
                     style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;max-width:640px;padding:10px 12px;border-radius:8px;font-size:13px;line-height:1.45;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);color:var(--text)">
                    <span style="flex:1;min-width:220px">
                        <?php $arretDefaut = $llmRetirement($defaultLlmStored); ?>
                        <?php /* Phrase entière : le modèle en gras est posé APRÈS
                                 l'échappement, à la place de `{modele}`. */ ?>
                        <?php $modeleEffectif = '<strong>' . htmlspecialchars($llms[$defaultLlm] ?? $defaultLlm) . '</strong>'; ?>
                        <?php if ($arretDefaut !== null && $arretDefaut['retired']): ?>
                            <?= str_replace('{modele}', $modeleEffectif, te('Le modèle par défaut enregistré a été arrêté par son éditeur le {date} : MIA utilise {modele}, affiché ci-dessous. Confirmez-le, ou choisissez-en un autre.', ['date' => Format::dateFr($arretDefaut['date'])])) ?>
                        <?php else: ?>
                            <?= str_replace('{modele}', $modeleEffectif, te("Aucun modèle par défaut n'est enregistré : MIA utilise {modele}, affiché ci-dessous. Confirmez-le, ou choisissez-en un autre.")) ?>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="btn-secondary" id="defaultLlmConfirm"><?= te('Confirmer ce modèle') ?></button>
                </div>
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;max-width:640px">
                <div class="form-group" style="margin:0">
                    <label><?= te('LLM par défaut (sans assistant)') ?></label>
                    <select name="default_llm" id="defaultLlmSelect" class="form-select">
                        <?php foreach ($llms as $k => $v): $arretOpt = $llmRetirement((string)$k); ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= $defaultLlm === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?><?= $arretOpt !== null ? ' — ' . te('arrêt le {date}', ['date' => Format::dateFr($arretOpt['date'])]) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <label><?= te('LLM de secours') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(facultatif)') ?></span></label>
                    <select name="default_llm_fallback" id="fallbackLlmSelect" class="form-select">
                        <option value="">— <?= te('Aucun (échec sans bascule)') ?> —</option>
                        <?php foreach ($llms as $k => $v): $arretOpt = $llmRetirement((string)$k); ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= $fallbackLlm === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?><?= $arretOpt !== null ? ' — ' . te('arrêt le {date}', ['date' => Format::dateFr($arretOpt['date'])]) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-top:10px;line-height:1.4;max-width:640px">
                <?= te('Le secours est utilisé automatiquement quand le LLM choisi (par défaut ou par un assistant) tombe en panne avant le premier mot — coupure réseau, erreur 5xx du provider, timeout ou rate-limit (429). La bascule ne se déclenche jamais en cours de génération.') ?>
            </p>
            <?php endif; ?>
        </div>

        <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
    </form>

<script>
// Un secours identique au défaut est inerte : on l'interdit à la saisie.
const defSel = document.getElementById('defaultLlmSelect');
const fbSel  = document.getElementById('fallbackLlmSelect');
function syncFallbackOptions() {
    [...fbSel.options].forEach(o => { o.disabled = o.value !== '' && o.value === defSel.value; });
    if (fbSel.value !== '' && fbSel.value === defSel.value) fbSel.value = '';
}
if (defSel && fbSel) {
    defSel.addEventListener('change', syncFallbackOptions);
    syncFallbackOptions();
}

// Défaut non enregistré : « Confirmer » émet le `change` que l'auto-save
// attend (resélectionner le même modèle n'en émet aucun), et l'avertissement
// disparaît dès que le serveur a enregistré un modèle — celui-ci ou un autre.
const unsaved = document.getElementById('defaultLlmUnsaved');
if (unsaved && defSel) {
    document.getElementById('defaultLlmConfirm')?.addEventListener('click', () => {
        defSel.dispatchEvent(new Event('change', { bubbles: true }));
    });
    defSel.form?.addEventListener('mia:autosaved', () => unsaved.remove());
}
</script>
    </section>

    <div class="card" style="margin-bottom:24px">
        <h2 style="font-size:15px;margin:0 0 12px"><?= te('Modèles activés') ?></h2>
        <div class="table-wrap">
        <table class="llm-catalog-table">
            <thead>
                <tr>
                    <th><?= te('Modèle') ?></th>
                    <th><?= te('Description') ?></th>
                    <th><?= te('Capacités') ?></th>
                    <th><?= te('Limites') ?></th>
                    <th><?= te('État') ?></th>
                    <th><?= te('Action') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $providerI = 0;
            foreach ($catalog as $provider => $models):
                $providerLabel = $providerNames[$provider] ?? $provider;
            ?>
                <tr class="llm-catalog-group">
                    <td colspan="6" data-label="" style="padding:6px 12px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <?php if (!empty($logos[$provider])): ?>
                                <img src="<?= htmlspecialchars($logos[$provider]) ?>" alt="" style="width:16px;height:16px;object-fit:contain;opacity:.8">
                            <?php endif; ?>
                            <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)"><?= htmlspecialchars($providerLabel) ?></span>
                        </div>
                    </td>
                </tr>
            <?php
                $modelI = 0;
                foreach ($models as $modelId => $info):
                    $activeRow = $activeLlms[$provider][$modelId] ?? null;
                    $hasKey    = isset($apiKeyProviders[$provider]);
                    $sortOrder = $providerI * 100 + $modelI;
                    $slug      = $provider . '::' . $modelId;
                    $arret     = $llmRetirement($slug);
            ?>
                <tr>
                    <td data-label="<?= te('Modèle') ?>">
                        <div style="display:flex;align-items:center;gap:8px">
                            <?php if (!empty($logos[$provider])): ?>
                                <img src="<?= htmlspecialchars($logos[$provider]) ?>" alt="" style="width:16px;height:16px;object-fit:contain;opacity:.6">
                            <?php endif; ?>
                            <div>
                                <code style="font-size:11px;color:var(--accent)"><?= htmlspecialchars($modelId) ?></code>
                                <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($info['label']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td data-label="<?= te('Description') ?>" style="font-size:12px;color:var(--text-muted);max-width:200px">
                        <?= htmlspecialchars($info['note']) ?>
                        <?php if ($arret !== null && $arret['replacement_label'] !== ''): ?>
                            <div class="llm-retirement-replacement"><?= te('Remplaçant désigné : {modele}', ['modele' => $arret['replacement_label']]) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= te('Capacités') ?>">
                        <?php if (!empty($info['tags'])): ?>
                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                <?php foreach ($info['tags'] as $tag): ?>
                                    <span class="badge <?= $tag === 'web' ? 'badge-green' : 'badge-gray' ?>"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= te('Limites') ?>" style="max-width:180px">
                        <?php if (!empty($info['limits'])): ?>
                            <ul style="margin:0;padding-left:14px;font-size:11px;color:#f87171">
                                <?php foreach ($info['limits'] as $limit): ?>
                                    <li><?= htmlspecialchars($limit) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span style="color:#6ee7b7;font-size:12px"><?= te('Aucune') ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= te('État') ?>">
                        <?php if ($activeRow || $arret !== null): ?>
                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                <?php if ($arret !== null && $arret['retired']): ?>
                                    <span class="badge badge-red" title="<?= te('Retiré du chat : ses conversations et assistants répondent avec le modèle par défaut.') ?>"><?= te('Arrêté le {date}', ['date' => Format::dateFr($arret['date'])]) ?></span>
                                <?php elseif ($arret !== null): ?>
                                    <span class="badge badge-yellow" title="<?= te("Annoncé par l'éditeur. À cette date, MIA le retire du chat et passe au modèle par défaut.") ?>"><?= te('Arrêt le {date}', ['date' => Format::dateFr($arret['date'])]) ?></span>
                                <?php endif; ?>
                                <?php if ($activeRow): ?>
                                    <span class="badge badge-green"><?= te('Actif') ?></span>
                                    <?php if ($slug === $defaultLlm): ?><span class="badge badge-llm-default"><?= te('Défaut') ?></span><?php endif; ?>
                                    <?php if ($slug === $fallbackLlm): ?><span class="badge badge-llm-fallback"><?= te('Secours') ?></span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= te('Action') ?>">
                        <?php if ($activeRow): ?>
                            <form method="POST" action="/admin/llm-models/<?= $activeRow['id'] ?>/delete"
                                  onsubmit='return confirm(<?= tj('Retirer {modele} ?', ['modele' => $info['label']]) ?>)'
                                  style="margin:0">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-deactivate"><?= te('Retirer') ?></button>
                            </form>
                        <?php elseif ($arret !== null && $arret['retired']): ?>
                            <span style="font-size:12px;color:var(--text-muted)"><?= te("Arrêté par l'éditeur") ?></span>
                        <?php elseif ($hasKey): ?>
                            <form method="POST" action="/admin/llm-models" style="margin:0">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="provider"     value="<?= htmlspecialchars($provider) ?>">
                                <input type="hidden" name="model"        value="<?= htmlspecialchars($modelId) ?>">
                                <input type="hidden" name="display_name" value="<?= htmlspecialchars($info['label']) ?>">
                                <input type="hidden" name="sort_order"   value="<?= $sortOrder ?>">
                                <button type="submit" class="btn-activate"><?= te('Activer') ?></button>
                            </form>
                        <?php else: ?>
                            <a href="/admin/llms" style="font-size:12px;color:var(--text-muted);text-decoration:none" title="<?= te("Enregistrez d'abord la clé API du fournisseur") ?>"><?= te('Clé API manquante') ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php
                    $modelI++;
                endforeach;
                $providerI++;
            endforeach;
            ?>

            <?php if ($localKey): ?>
                <tr class="llm-catalog-group">
                    <td colspan="6" data-label="" style="padding:6px 12px">
                        <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)"><?= te('LLM Local') ?></span>
                    </td>
                </tr>
                <tr>
                    <td data-label="<?= te('Modèle') ?>">
                        <div style="display:flex;align-items:center;gap:8px">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);flex-shrink:0"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                            <div>
                                <code style="font-size:11px;color:var(--accent)"><?= htmlspecialchars($localKey['model'] ?? 'local') ?></code>
                                <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($localKey['display_name'] ?? t('LLM Local')) ?></div>
                            </div>
                        </div>
                    </td>
                    <td data-label="<?= te('Description') ?>" style="font-size:12px;color:var(--text-muted)">
                        <?= te('Serveur local OpenAI-compatible') ?><br>
                        <code style="font-size:11px"><?= htmlspecialchars($localKey['base_url'] ?? '') ?></code>
                    </td>
                    <td data-label="<?= te('Capacités') ?>"><span class="badge badge-gray">OpenAI-compat</span></td>
                    <td data-label="<?= te('Limites') ?>"><span style="color:#6ee7b7;font-size:12px"><?= te('Aucune') ?></span></td>
                    <td data-label="<?= te('État') ?>">
                        <div style="display:flex;gap:4px;flex-wrap:wrap">
                            <span class="badge badge-green"><?= te('Actif') ?></span>
                            <?php if ($defaultLlm === 'local'): ?><span class="badge badge-llm-default"><?= te('Défaut') ?></span><?php endif; ?>
                            <?php if ($fallbackLlm === 'local'): ?><span class="badge badge-llm-fallback"><?= te('Secours') ?></span><?php endif; ?>
                        </div>
                    </td>
                    <td data-label="<?= te('Action') ?>">
                        <a href="/admin/llms#local-config" style="font-size:12px;color:var(--accent);text-decoration:none"><?= te('Configurer') ?></a>
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>
        </table>
        </div>
    </div>

<?php require ROOT . '/templates/admin/llms/_foot.php'; ?>
