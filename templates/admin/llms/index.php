<?php
/**
 * Modèles IA — Fournisseurs et modèles : UNE page pour tout régler.
 *
 *  1. « Qui répond par défaut » : le modèle par défaut et son secours, posés en
 *     schéma (conversation sans assistant → défaut → panne → secours).
 *  2. Une carte par fournisseur (logo, état, clé masquée, modèles proposés) ;
 *     un fournisseur à connecter reçoit sa clé dans sa carte.
 *  3. Sous la grille, le panneau du fournisseur choisi : sa clé (tester,
 *     remplacer, déconnecter) et ses modèles, un interrupteur par modèle.
 *
 * Elle remplace les anciennes pages « Clés API » et « Modèles » (les adresses
 * /admin/llms/models et /admin/llms/defaults y redirigent).
 *
 * Attend (AdminController::llms) : $keys, $llmInstances, $catalog, $logos,
 * $providerNames, $llms, $defaultLlm, $defaultLlmStored, $fallbackLlm,
 * $incidents, $costs, $selected, $success, $error.
 */
$page     = 'llms-keys';
$llmTitle = t('Fournisseurs et modèles');
// Phrase entière dans le dictionnaire : `{a}…{/a}` y marque le lien, posé
// APRÈS l'échappement (te() n'échappe pas les accolades).
$llmIntro = str_replace(['{a}', '{/a}'], ['<a href="/admin/company#vocabulaire">', '</a>'],
    te('Connectez un fournisseur, activez ses modèles, puis choisissez celui qui répond par défaut. Le vocabulaire de l\'entreprise (sigles, lexique maison) se règle dans {a}Organisation → Entreprise{/a}.'));

// ---- Données de la page ------------------------------------------------
$keysByProvider = [];
foreach ($keys as $k) {
    $keysByProvider[(string)$k['provider']] = $k;
}
$localKey = $keysByProvider['local'] ?? null;

// Instances : [provider][model] => ligne
$activeLlms = [];
foreach ($llmInstances as $lm) {
    $activeLlms[(string)$lm['provider']][(string)$lm['model']] = $lm;
}
$activeCount = count(array_filter($llmInstances, static fn($lm) => !empty($lm['is_active'])));

// Arrêt annoncé par l'éditeur (bloc `retirement` du catalogue, sinon date
// relevée chaque jour chez l'éditeur par LlmShutdownWatch) d'un slug
// « provider::model » — null sinon. Source unique : LlmCatalog::retirement().
$llmRetirement = static function (string $slug): ?array {
    if (!str_contains($slug, '::')) {
        return null;
    }
    [$p, $m] = explode('::', $slug, 2);
    return LlmCatalog::retirement($p, $m);
};

// Clé masquée : les 10 premiers caractères, jamais le secret entier.
$maskKey = static function (?array $row): string {
    $plain = $row ? (string)Encryption::decrypt((string)($row['key_enc'] ?? '')) : '';
    return $plain === '' ? '' : mb_substr($plain, 0, 10) . '••••••••';
};

// Libellés des capacités du catalogue (tags).
$tagLabels = [
    'web'          => t('Web'),
    'vision'       => t('Vision'),
    'fichiers'     => t('Fichiers'),
    'raisonnement' => t('Raisonnement'),
    'code'         => t('Code'),
];

// État de chaque fournisseur : ok | err | warn | off.
$providers = [];
foreach ($catalog as $p => $models) {
    $key      = $keysByProvider[$p] ?? null;
    $actifs   = count($activeLlms[$p] ?? []);
    $incident = $incidents[$p] ?? null;
    if ($key && $incident !== null) {
        $state  = 'err';
        $status = ($incident['kind'] ?? '') === 'quota' ? t('Crédit épuisé') : t('Clé refusée');
    } elseif ($key && empty($key['is_active'])) {
        $state  = 'err';
        $status = t('Clé désactivée');
    } elseif ($key) {
        $state  = 'ok';
        $status = t('Connecté');
    } elseif ($actifs > 0) {
        $state  = 'warn';
        $status = t('Clé manquante');
    } else {
        $state  = 'off';
        $status = t('À connecter');
    }
    $providers[$p] = [
        'name'    => $providerNames[$p] ?? $p,
        'logo'    => $logos[$p] ?? '',
        'key'     => $key,
        'state'   => $state,
        'status'  => $status,
        'count'   => count($models),
        'active'  => $actifs,
        'panel'   => $key !== null || $actifs > 0,
    ];
}
$providers['local'] = [
    'name'   => t('LLM local'),
    'logo'   => $logos['local'] ?? '',
    'key'    => $localKey,
    'state'  => $localKey ? 'ok' : 'off',
    'status' => $localKey ? t('Configuré') : t('Non configuré'),
    'count'  => 0,
    'active' => $localKey ? 1 : 0,
    'panel'  => true,
];
$stateCount = ['ok' => 0, 'check' => 0, 'off' => 0];
foreach ($providers as $prov) {
    $stateCount[$prov['state'] === 'ok' ? 'ok' : ($prov['state'] === 'off' ? 'off' : 'check')]++;
}
if (!isset($providers[$selected]) || !$providers[$selected]['panel']) {
    $selected = '';
}

$chevron = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
$logoTag = static function (string $src, string $class = 'llm-logo'): string {
    return '<span class="' . $class . '" aria-hidden="true">'
        . ($src !== '' ? '<img src="' . htmlspecialchars($src) . '" alt="">' : '')
        . '</span>';
};

$llmHeadAction = $activeCount > 0
    ? '<a class="btn-secondary llm-head-action" href="/admin/llms/tests">'
        . '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="6 4 20 12 6 20 6 4"/></svg>'
        . htmlspecialchars(I18n::plural($activeCount, 'Tester le modèle proposé', 'Tester les {n} modèles proposés')) . '</a>'
    : '';

require ROOT . '/templates/admin/llms/_head.php';
?>

    <?php if (!empty($llmSetupNeeded)): // calculé par admin-nav.php (via _head.php) : aucune clé API LLM active ?>
    <div class="llm-setup-banner" id="llmSetupCallout">
        <div class="llm-setup-banner-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
        </div>
        <div class="llm-setup-banner-text">
            <div class="llm-setup-banner-title"><?= te('Première configuration : c\'est ici que tout commence.') ?></div>
            <div class="llm-setup-banner-sub"><?= te('Collez la clé API d\'au moins un fournisseur dans sa carte ci-dessous (ou configurez un LLM local) : sans elle, MIA ne peut répondre à personne. Activez ensuite les modèles à proposer.') ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 1. Qui répond par défaut (ancienne carte « Comportement par défaut »). -->
    <section id="defaut" class="card llm-def" aria-labelledby="llmDefTitle">
    <form method="POST" action="/admin/llms/defaults" data-autosave>
        <?= Csrf::field() ?>
        <div class="llm-def-head">
            <h2 id="llmDefTitle"><?= te('Qui répond par défaut') ?></h2>
            <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
        </div>
        <?php if (empty($llms)): ?>
            <p class="llm-muted"><?= te('Aucun modèle n\'est encore proposé : activez-en un dans un fournisseur ci-dessous, le premier devient le modèle par défaut.') ?></p>
        <?php else: ?>
            <?php if ($defaultLlm !== '' && $defaultLlmStored !== $defaultLlm): ?>
                <?php /* Le réglage enregistré ne désigne aucun modèle disponible
                         (instance neuve : « openai », ou modèle retiré). MIA
                         utilise le défaut effectif — la liste le montre — mais
                         rien ne l'a enregistré : resélectionner le même modèle
                         n'émettant aucun évènement, la confirmation passe par
                         un bouton. */ ?>
                <div id="defaultLlmUnsaved" class="llm-def-unsaved" role="status">
                    <span>
                        <?php $arretDefaut = $llmRetirement($defaultLlmStored); ?>
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
            <?php
            // Logo d'un slug « provider::model » (ou « local »).
            $slugLogo = static function (string $slug) use ($logos): string {
                $p = str_contains($slug, '::') ? explode('::', $slug, 2)[0] : $slug;
                return (string)($logos[$p] ?? '');
            };
            $optionLabel = static function (string $k, string $v) use ($llmRetirement): string {
                $arret = $llmRetirement($k);
                return htmlspecialchars($v) . ($arret !== null ? ' — ' . te('arrêt le {date}', ['date' => Format::dateFr($arret['date'])]) : '');
            };
            ?>
            <div class="llm-def-flow">
                <div class="llm-def-source">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span><?= te('Conversation sans assistant') ?></span>
                </div>
                <span class="llm-def-arrow" aria-hidden="true"></span>
                <label class="llm-def-slot is-default">
                    <?= $logoTag($slugLogo($defaultLlm), 'llm-logo llm-logo-sm') ?>
                    <span class="llm-def-slot-body">
                        <span class="llm-def-kicker"><?= te('Par défaut') ?><?= AdminAttention::itemDot('llm-default', false) ?></span>
                        <select name="default_llm" id="defaultLlmSelect" class="llm-def-select">
                            <?php foreach ($llms as $k => $v): ?>
                                <option value="<?= htmlspecialchars((string)$k) ?>" data-logo="<?= htmlspecialchars($slugLogo((string)$k)) ?>" <?= $defaultLlm === $k ? 'selected' : '' ?>><?= $optionLabel((string)$k, (string)$v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                    <?= $chevron ?>
                </label>
                <span class="llm-def-fail">
                    <span><?= te('en cas de panne, avant le premier mot') ?></span>
                    <span class="llm-def-arrow is-dashed" aria-hidden="true"></span>
                </span>
                <label class="llm-def-slot">
                    <?= $logoTag($slugLogo($fallbackLlm), 'llm-logo llm-logo-sm') ?>
                    <span class="llm-def-slot-body">
                        <span class="llm-def-kicker"><?= te('Secours') ?><?= AdminAttention::itemDot('llm-fallback', false) ?></span>
                        <select name="default_llm_fallback" id="fallbackLlmSelect" class="llm-def-select">
                            <option value="" data-logo=""><?= te('Aucun (échec sans bascule)') ?></option>
                            <?php foreach ($llms as $k => $v): ?>
                                <option value="<?= htmlspecialchars((string)$k) ?>" data-logo="<?= htmlspecialchars($slugLogo((string)$k)) ?>" <?= $fallbackLlm === $k ? 'selected' : '' ?>><?= $optionLabel((string)$k, (string)$v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                    <?= $chevron ?>
                </label>
            </div>
            <p class="llm-muted llm-def-note"><?= te('Le modèle par défaut répond aux conversations sans assistant. Le secours prend le relais automatiquement quand le modèle choisi (par défaut ou par un assistant) tombe en panne avant le premier mot : coupure réseau, erreur du fournisseur, délai dépassé ou limite de débit. Jamais en cours de réponse.') ?></p>
        <?php endif; ?>
    </form>
    </section>

    <!-- 2. Les fournisseurs, une carte chacun. -->
    <section id="fournisseurs" class="llm-providers" aria-labelledby="llmProvTitle">
        <div class="llm-providers-head">
            <h2 id="llmProvTitle"><?= te('Fournisseurs') ?></h2>
            <div class="llm-providers-counts">
                <?php if ($stateCount['ok'] > 0): ?><span class="llm-pill is-ok"><?= htmlspecialchars(I18n::plural($stateCount['ok'], '{n} connecté', '{n} connectés')) ?></span><?php endif; ?>
                <?php if ($stateCount['check'] > 0): ?><span class="llm-pill is-err"><?= te('{n} à vérifier', ['n' => $stateCount['check']]) ?></span><?php endif; ?>
                <?php if ($stateCount['off'] > 0): ?><span class="llm-pill is-off"><?= te('{n} à connecter', ['n' => $stateCount['off']]) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="llm-prov-grid">
        <?php foreach ($providers as $p => $prov):
            $isSel = $p === $selected;
            $sub   = $p === 'local'
                ? t('Ollama, LM Studio, Jan…')
                : I18n::plural($prov['count'], '{n} modèle au catalogue', '{n} modèles au catalogue');
        ?>
            <article class="llm-prov-card is-<?= $prov['state'] ?><?= $isSel ? ' is-selected' : '' ?>" id="fournisseur-<?= htmlspecialchars($p) ?>" data-provider="<?= htmlspecialchars($p) ?>">
                <div class="llm-prov-top">
                    <?= $logoTag($prov['logo']) ?>
                    <div class="llm-prov-id">
                        <h3><?= htmlspecialchars($prov['name']) ?><?= AdminAttention::itemDot('provider:' . $p) ?></h3>
                        <span class="llm-muted"><?= htmlspecialchars($sub) ?></span>
                    </div>
                    <span class="llm-pill is-<?= $prov['state'] ?>"><?= htmlspecialchars($prov['status']) ?></span>
                </div>

                <?php if ($p !== 'local' && $prov['key'] === null): ?>
                    <?php if ($prov['active'] > 0): ?>
                        <p class="llm-prov-warn"><?= htmlspecialchars(I18n::plural($prov['active'], '{n} modèle proposé sans clé : il échoue à chaque message.', '{n} modèles proposés sans clé : ils échouent à chaque message.')) ?></p>
                    <?php endif; ?>
                    <!-- Connecter = créer : un acte volontaire, pas d'enregistrement automatique. -->
                    <form method="POST" action="/admin/api-keys" class="llm-prov-connect">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="provider" value="<?= htmlspecialchars($p) ?>">
                        <input type="password" name="key" class="form-input" required autocomplete="off"
                               placeholder="<?= te('Coller la clé API') ?>"
                               aria-label="<?= te('Clé API {fournisseur}', ['fournisseur' => $prov['name']]) ?>">
                        <button type="submit" class="btn-primary"><?= te('Connecter') ?></button>
                    </form>
                <?php endif; ?>

                <?php if ($prov['panel']): ?>
                    <div class="llm-prov-foot">
                        <?php if ($p === 'local'): ?>
                            <span class="llm-muted"><?= $localKey ? htmlspecialchars((string)($localKey['display_name'] ?? '')) : te('Serveur compatible OpenAI') ?></span>
                        <?php elseif ($prov['key'] !== null): ?>
                            <code class="llm-key-mask"><?= htmlspecialchars($maskKey($prov['key'])) ?></code>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <a class="llm-prov-open" href="/admin/llms?fournisseur=<?= urlencode($p) ?>#modeles"
                           data-llm-open="<?= htmlspecialchars($p) ?>" aria-controls="panel-<?= htmlspecialchars($p) ?>"
                           aria-expanded="<?= $isSel ? 'true' : 'false' ?>">
                            <?php if ($p === 'local'): ?>
                                <?= $localKey ? te('Réglages') : te('Configurer') ?>
                            <?php else: ?>
                                <?= htmlspecialchars(I18n::plural($prov['active'], '{n} modèle proposé', '{n} modèles proposés')) ?>
                            <?php endif; ?>
                            <?= $chevron ?>
                        </a>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>

        <!-- 3. Le panneau du fournisseur choisi : sa clé et ses modèles. -->
        <div id="modeles" class="llm-panels">
        <?php foreach ($providers as $p => $prov):
            if (!$prov['panel']) {
                continue;
            }
            $hidden = $p !== $selected;
        ?>
            <section class="card llm-panel" id="panel-<?= htmlspecialchars($p) ?>" data-panel="<?= htmlspecialchars($p) ?>" aria-labelledby="panelTitle-<?= htmlspecialchars($p) ?>"<?= $hidden ? ' hidden' : '' ?>>

            <?php if ($p === 'local'): ?>
                <div class="llm-panel-head">
                    <?= $logoTag($prov['logo'], 'llm-logo llm-logo-lg') ?>
                    <div class="llm-panel-id">
                        <h2 id="panelTitle-local"><?= te('LLM local (OpenAI-compatible)') ?></h2>
                        <p class="llm-muted"><?= str_replace('{url}', '<code>https://ollama.com/v1</code>', teb('Connectez un serveur local tel qu\'{b}Ollama{/b}, {b}LM Studio{/b} ou {b}Jan{/b}, ou le {b}cloud Ollama{/b} ({url} + clé API). L\'URL doit pointer vers une API compatible OpenAI Chat Completions.')) ?></p>
                    </div>
                </div>
                <form method="POST" action="/admin/api-keys" data-autosave id="localLlmForm" class="llm-local-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="provider" value="local">
                    <div class="llm-local-grid">
                        <div class="form-group">
                            <label><?= te('Nom affiché aux utilisateurs') ?> <span class="llm-required">*</span></label>
                            <input type="text" name="display_name" class="form-input"
                                   value="<?= htmlspecialchars($localKey['display_name'] ?? '') ?>"
                                   placeholder="<?= te('ex : {exemple}', ['exemple' => 'Llama 3.2 Local']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label><?= te('Modèle') ?> <span class="llm-required">*</span></label>
                            <input type="text" name="model" class="form-input"
                                   value="<?= htmlspecialchars($localKey['model'] ?? '') ?>"
                                   placeholder="<?= te('ex : {exemple}', ['exemple' => 'llama3.2, mistral, phi3']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label><?= te('URL de base') ?> <span class="llm-required">*</span></label>
                            <input type="url" name="base_url" class="form-input"
                                   value="<?= htmlspecialchars($localKey['base_url'] ?? '') ?>"
                                   placeholder="http://localhost:11434/v1" required>
                            <div class="llm-field-hint">
                                <?= strtr(te('Ollama : {ollama} · LM Studio : {lmstudio} · cloud Ollama : {cloud}'), [
                                    '{ollama}'   => '<code>http://localhost:11434/v1</code>',
                                    '{lmstudio}' => '<code>http://localhost:1234/v1</code>',
                                    '{cloud}'    => '<code>https://ollama.com/v1</code>',
                                ]) ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><?= te('Clé API') ?> <span class="llm-muted"><?= te('(optionnel)') ?></span></label>
                            <!-- Vidé après enregistrement : le secret n'a pas à rester
                                 dans la page, et un champ vide conserve la clé. -->
                            <input type="password" name="key" data-autosave-reset class="form-input"
                                   placeholder="<?= te('Laissez vide si non requis') ?>">
                            <div class="llm-field-hint"><?= te('Ollama et LM Studio en local n\'en ont pas besoin ; le cloud Ollama l\'exige.') ?></div>
                        </div>
                    </div>
                    <!-- Function-calling : impossible à deviner pour un modèle servi en
                         local (un Llama 3.2 le sait, un Phi-3 non). Décoché par défaut :
                         cocher un modèle incapable ferait échouer chaque message qui
                         porte un outil (connecteurs, outils d'assistant). -->
                    <label class="llm-check">
                        <input type="checkbox" name="tool_use" value="1"
                               <?= LocalAdapter::toolUseEnabled() ? 'checked' : '' ?>>
                        <span>
                            <?= te('Ce modèle sait appeler des outils (function calling)') ?>
                            <span class="llm-field-hint"><?= te('Ouvre les connecteurs (Drive, Gmail, agendas…) et les outils des assistants à ce modèle. Ne cochez que si votre modèle le prend en charge (Llama 3.1+, Qwen 2.5+, Mistral…) : sinon les messages qui utilisent un outil échoueront.') ?></span>
                        </span>
                    </label>
                    <div class="llm-panel-actions">
                        <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
                        <?php if ($localKey): ?>
                            <button type="button" class="btn-test" data-id="local" data-provider="local"><?= te('Tester la connexion') ?></button>
                            <span class="test-result" id="test-result-local" hidden></span>
                        <?php endif; ?>
                    </div>
                </form>

            <?php else:
                $key        = $prov['key'];
                $models     = $catalog[$p] ?? [];
                $savedModel = (string)($key['model'] ?? '');
                $i          = 0;
                $rows       = ['shown' => [], 'more' => []];
                foreach ($models as $modelId => $info) {
                    $slug   = $p . '::' . $modelId;
                    $active = $activeLlms[$p][$modelId] ?? null;
                    $bucket = ($i < 6 || $active || $slug === $defaultLlm || $slug === $fallbackLlm) ? 'shown' : 'more';
                    $rows[$bucket][] = [$modelId, $info, $active, $slug, $i];
                    $i++;
                }
                // Modèle activé que MIA ne propose pas (retiré du catalogue, ou
                // jamais proposé) : listé quand même, sinon impossible de le
                // retirer ; son arrêt se lit dans `retired_models`
                // (LlmCatalog::retirement), d'où sa date et son remplaçant.
                foreach ($activeLlms[$p] ?? [] as $modelId => $activeRow) {
                    $modelId = (string)$modelId;
                    if (isset($models[$modelId]) || empty($activeRow['is_active'])) {
                        continue;
                    }
                    $nom = trim((string)($activeRow['display_name'] ?? ''));
                    $rows['shown'][] = [$modelId, [
                        'label' => $nom !== '' ? $nom : LlmCatalog::label($p, $modelId),
                        'note'  => t('Hors du catalogue de MIA.'),
                        'tags'  => [],
                    ], $activeRow, $p . '::' . $modelId, $i++];
                }
            ?>
                <div class="llm-panel-head">
                    <?= $logoTag($prov['logo'], 'llm-logo llm-logo-lg') ?>
                    <div class="llm-panel-id">
                        <h2 id="panelTitle-<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($prov['name']) ?><?= AdminAttention::itemDot('provider:' . $p) ?></h2>
                        <p class="llm-muted">
                            <?php if ($key !== null): ?>
                                <code class="llm-key-mask"><?= htmlspecialchars($maskKey($key)) ?></code>
                                · <?= te('modifiée le {date}', ['date' => Format::dateFr(substr((string)$key['updated_at'], 0, 10))]) ?>
                            <?php else: ?>
                                <?= te('Aucune clé enregistrée.') ?>
                            <?php endif; ?>
                            <span class="test-result" id="test-result-<?= htmlspecialchars($p) ?>" hidden></span>
                        </p>
                        <?php
                        // Dates d'arrêt relevées chaque jour chez l'éditeur (OpenAI) :
                        // dire quand, ou pourquoi pas, sans quoi un badge absent ne
                        // prouve rien.
                        $releve = ($key !== null && isset(LlmShutdownWatch::PROVIDERS[$p])) ? LlmShutdownWatch::status($p) : null;
                        if ($releve !== null):
                            $releveVars = ['fournisseur' => $prov['name'], 'date' => Format::dateFr(date('Y-m-d', $releve['ok'] ?: $releve['at']))];
                        ?>
                            <p class="llm-muted llm-shutdown-check<?= $releve['error'] !== '' ? ' is-warn' : '' ?>">
                                <?php if ($releve['error'] === ''): ?>
                                    <?= te('Dates d\'arrêt relevées chez {fournisseur} le {date}.', $releveVars) ?>
                                <?php else: ?>
                                    <?= te('Dates d\'arrêt non relevées chez {fournisseur} : {cause}.', $releveVars + ['cause' => LlmShutdownWatch::errorLabel($releve['error'])]) ?>
                                    <?php if ($releve['ok'] > 0): ?><?= te('Dernier relevé le {date}.', $releveVars) ?><?php endif; ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="llm-panel-tools">
                        <?php if ($key !== null): ?>
                            <button type="button" class="btn-test" data-id="<?= htmlspecialchars($p) ?>" data-provider="<?= htmlspecialchars($p) ?>"><?= te('Tester la clé') ?></button>
                            <form method="POST" action="/admin/api-keys/<?= (int)$key['id'] ?>/delete"
                                  onsubmit='return confirm(<?= tj('Supprimer la clé {fournisseur} ?', ['fournisseur' => $prov['name']]) ?>)'>
                                <?= Csrf::field() ?>
                                <button type="submit" class="llm-link-danger"><?= te('Déconnecter') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <details class="llm-key-edit"<?= $key === null ? ' open' : '' ?>>
                    <summary><?= $key !== null ? te('Remplacer la clé ou le modèle de référence') : te('Enregistrer la clé') ?></summary>
                    <form method="POST" action="/admin/api-keys" data-autosave class="llm-key-form">
                        <?= Csrf::field() ?>
                        <!-- Le fournisseur de ce formulaire est FIXÉ : un champ caché,
                             jamais un sélecteur (en changer aurait envoyé la clé
                             affichée chez le fournisseur suivant). -->
                        <input type="hidden" name="provider" value="<?= htmlspecialchars($p) ?>">
                        <div class="form-group">
                            <label for="key-<?= htmlspecialchars($p) ?>"><?= te('Clé API') ?></label>
                            <!-- Vidé après enregistrement ; laissé vide, il conserve la clé. -->
                            <input type="password" name="key" id="key-<?= htmlspecialchars($p) ?>" data-autosave-reset class="form-input" autocomplete="off"
                                   placeholder="<?= $key !== null ? te('Clé enregistrée — laissez vide pour la conserver') : 'sk-…' ?>"<?= $key === null ? ' required' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label for="model-<?= htmlspecialchars($p) ?>"><?= te('Modèle de référence') ?></label>
                            <select name="model" id="model-<?= htmlspecialchars($p) ?>" class="form-select">
                                <?php if ($savedModel !== '' && !isset($models[$savedModel])): ?>
                                    <!-- Modèle enregistré hors du catalogue (saisi avant le 05/03/2026,
                                         ou retiré depuis) : gardé et sélectionné, sinon l'auto-save
                                         du formulaire le remplacerait en silence. -->
                                    <option value="<?= htmlspecialchars($savedModel) ?>" selected><?= htmlspecialchars(LlmCatalog::label($p, $savedModel)) ?><?= te(' (hors catalogue)') ?></option>
                                <?php endif; ?>
                                <?php foreach ($models as $modelId => $info): ?>
                                    <option value="<?= htmlspecialchars((string)$modelId) ?>" <?= ($savedModel !== '' ? $savedModel === $modelId : !empty($info['default'])) ? 'selected' : '' ?>><?= htmlspecialchars((string)$info['label']) ?><?= !empty($info['default']) ? te(' (défaut)') : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="llm-field-hint"><?= te('Sert aux tâches internes de ce fournisseur (découpage des documents, description des images) quand aucun modèle n\'est imposé.') ?></div>
                        </div>
                        <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
                    </form>
                </details>

                <div class="llm-models" role="table" aria-label="<?= te('Modèles {fournisseur}', ['fournisseur' => $prov['name']]) ?>">
                    <div class="llm-model-row is-head" role="row">
                        <span role="columnheader"><?= te('Proposé') ?></span>
                        <span role="columnheader"><?= te('Modèle') ?></span>
                        <span role="columnheader"><?= te('Sait faire') ?></span>
                        <span role="columnheader"><?= te('Coût par réponse') ?></span>
                        <span role="columnheader"><?= te('État') ?></span>
                    </div>
                    <?php
                    $renderRow = static function (array $r) use ($p, $key, $defaultLlm, $fallbackLlm, $llmRetirement, $tagLabels, $costs): void {
                        [$modelId, $info, $activeRow, $slug, $order] = $r;
                        $arret = $llmRetirement($slug);
                        $cost  = $costs[$slug] ?? null;
                        ?>
                        <div class="llm-model-row<?= $activeRow ? ' is-on' : '' ?>" role="row">
                            <span role="cell">
                                <?php if ($activeRow): ?>
                                    <form method="POST" action="/admin/llm-models/<?= (int)$activeRow['id'] ?>/delete"
                                          onsubmit='return confirm(<?= tj('Retirer {modele} ?', ['modele' => $info['label']]) ?>)'>
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="llm-switch is-on" role="switch" aria-checked="true"
                                                aria-label="<?= te('Retirer {modele}', ['modele' => $info['label']]) ?>"></button>
                                    </form>
                                <?php elseif ($arret !== null && $arret['retired']): ?>
                                    <span class="llm-switch is-locked" title="<?= te("Arrêté par l'éditeur") ?>"></span>
                                <?php elseif ($key !== null): ?>
                                    <form method="POST" action="/admin/llm-models">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="provider"     value="<?= htmlspecialchars($p) ?>">
                                        <input type="hidden" name="model"        value="<?= htmlspecialchars((string)$modelId) ?>">
                                        <input type="hidden" name="display_name" value="<?= htmlspecialchars((string)$info['label']) ?>">
                                        <input type="hidden" name="sort_order"   value="<?= (int)$order ?>">
                                        <button type="submit" class="llm-switch" role="switch" aria-checked="false"
                                                aria-label="<?= te('Proposer {modele}', ['modele' => $info['label']]) ?>"></button>
                                    </form>
                                <?php else: ?>
                                    <span class="llm-switch is-locked" title="<?= te("Enregistrez d'abord la clé API du fournisseur") ?>"></span>
                                <?php endif; ?>
                            </span>
                            <span role="cell" class="llm-model-name">
                                <strong><?= htmlspecialchars((string)$info['label']) ?><?= AdminAttention::itemDot('model:' . $slug) ?></strong>
                                <code><?= htmlspecialchars((string)$modelId) ?></code>
                                <?php if (($info['note'] ?? '') !== ''): ?><span class="llm-model-note" title="<?= htmlspecialchars((string)$info['note']) ?>"><?= htmlspecialchars((string)$info['note']) ?></span><?php endif; ?>
                                <?php if (!empty($info['limits'])): ?><span class="llm-model-limits"><?= htmlspecialchars(implode(' · ', (array)$info['limits'])) ?></span><?php endif; ?>
                                <?php if ($arret !== null && $arret['replacement_label'] !== ''): ?>
                                    <span class="llm-retirement-replacement"><?= te('Remplaçant désigné : {modele}', ['modele' => $arret['replacement_label']]) ?></span>
                                <?php endif; ?>
                                <?php if ($arret !== null && ($arret['source'] ?? '') === 'api'): ?>
                                    <span class="llm-retirement-replacement"><?= te('Date relevée chez {fournisseur}, pas encore dans le catalogue de MIA : aucun remplaçant désigné.', ['fournisseur' => LlmShutdownWatch::providerLabel($p)]) ?></span>
                                <?php elseif ($arret !== null && is_string($arret['reported'] ?? null) && $arret['reported'] !== $arret['date']): ?>
                                    <span class="llm-retirement-replacement"><?= te('{fournisseur} annonce le {date} ; MIA suit son catalogue.', ['fournisseur' => LlmShutdownWatch::providerLabel($p), 'date' => Format::dateFr((string)$arret['reported'])]) ?></span>
                                <?php endif; ?>
                            </span>
                            <span role="cell" class="llm-model-tags">
                                <?php foreach ((array)($info['tags'] ?? []) as $tag): ?>
                                    <span class="llm-tag"><?= htmlspecialchars($tagLabels[$tag] ?? (string)$tag) ?></span>
                                <?php endforeach; ?>
                            </span>
                            <span role="cell" class="llm-model-cost"><?= $cost !== null ? te('≈ {n} cr.', ['n' => Format::credits((int)$cost)]) : '—' ?></span>
                            <span role="cell" class="llm-model-state">
                                <?php if ($arret !== null && $arret['retired']): ?>
                                    <span class="llm-pill is-err" title="<?= te('Retiré du chat : ses conversations et assistants répondent avec le modèle par défaut.') ?>"><?= te('Arrêté le {date}', ['date' => Format::dateFr($arret['date'])]) ?></span>
                                <?php elseif ($arret !== null): ?>
                                    <span class="llm-pill is-warn" title="<?= te("Annoncé par l'éditeur. À cette date, MIA le retire du chat et passe au modèle par défaut.") ?>"><?= te('Arrêt le {date}', ['date' => Format::dateFr($arret['date'])]) ?></span>
                                <?php endif; ?>
                                <?php if ($slug === $defaultLlm): ?><span class="llm-pill is-default"><?= te('Par défaut') ?></span><?php endif; ?>
                                <?php if ($slug === $fallbackLlm): ?><span class="llm-pill is-fallback"><?= te('Secours') ?></span><?php endif; ?>
                            </span>
                        </div>
                        <?php
                    };
                    foreach ($rows['shown'] as $r) {
                        $renderRow($r);
                    }
                    ?>
                    <?php if ($rows['more'] !== []): ?>
                        <details class="llm-more">
                            <summary><?= htmlspecialchars(I18n::plural(count($rows['more']), 'Afficher {n} modèle plus ancien', 'Afficher {n} modèles plus anciens')) ?> <?= $chevron ?></summary>
                            <?php foreach ($rows['more'] as $r) { $renderRow($r); } ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            </section>
        <?php endforeach; ?>
        </div>
    </section>

<script>
(() => {
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
// Textes de l'écran, traduits côté serveur (ce script n'en écrit aucun).
const LLM_TXT = {
    testing:  <?= tj('Test en cours…') ?>,
    netError: <?= tj('✗ Erreur réseau') ?>,
};

// ---- Qui répond par défaut -------------------------------------------
// Un secours identique au défaut est inerte : on l'interdit à la saisie.
const defSel = document.getElementById('defaultLlmSelect');
const fbSel  = document.getElementById('fallbackLlmSelect');
function syncFallbackOptions() {
    [...fbSel.options].forEach(o => { o.disabled = o.value !== '' && o.value === defSel.value; });
    if (fbSel.value !== '' && fbSel.value === defSel.value) fbSel.value = '';
}
// Le logo du fournisseur suit le modèle choisi.
function syncLogo(select) {
    const slot = select.closest('.llm-def-slot');
    const box  = slot && slot.querySelector('.llm-logo');
    if (!box) return;
    const src = select.selectedOptions[0]?.dataset.logo || '';
    box.innerHTML = '';
    if (src) {
        const img = document.createElement('img');
        img.src = src; img.alt = '';
        box.appendChild(img);
    }
}
if (defSel && fbSel) {
    defSel.addEventListener('change', () => { syncFallbackOptions(); syncLogo(defSel); syncLogo(fbSel); });
    fbSel.addEventListener('change', () => syncLogo(fbSel));
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

// ---- Un fournisseur à la fois sous la grille ------------------------
// Sans script, le lien recharge la page sur le bon fournisseur.
function openPanel(provider, scroll) {
    document.querySelectorAll('.llm-panel').forEach(panel => {
        panel.hidden = panel.dataset.panel !== provider;
    });
    document.querySelectorAll('.llm-prov-card').forEach(card => {
        card.classList.toggle('is-selected', card.dataset.provider === provider);
    });
    document.querySelectorAll('[data-llm-open]').forEach(link => {
        link.setAttribute('aria-expanded', link.dataset.llmOpen === provider ? 'true' : 'false');
    });
    const url = new URL(window.location.href);
    url.searchParams.set('fournisseur', provider);
    url.hash = 'modeles';
    history.replaceState(null, '', url);
    if (scroll) {
        document.getElementById('panel-' + provider)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
document.querySelectorAll('[data-llm-open]').forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        const provider = link.dataset.llmOpen;
        const panel    = document.getElementById('panel-' + provider);
        if (panel && !panel.hidden) {
            panel.hidden = true;
            link.setAttribute('aria-expanded', 'false');
            link.closest('.llm-prov-card')?.classList.remove('is-selected');
            return;
        }
        openPanel(provider, true);
    });
});

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
// Le formulaire local ne recharge jamais la page : sans ce relais, la
// pastille resterait allumée après la toute première configuration d'un
// LLM local (Ollama, LM Studio…) jusqu'à la navigation suivante.
document.getElementById('localLlmForm')?.addEventListener('mia:autosaved', clearLlmSetupAttention);
document.querySelectorAll('.llm-key-form').forEach(f => f.addEventListener('mia:autosaved', clearLlmSetupAttention));

// ---- Tester une clé ---------------------------------------------------
document.querySelectorAll('.btn-test').forEach(btn => {
    btn.addEventListener('click', async () => {
        const provider = btn.dataset.provider;
        const result   = document.getElementById('test-result-' + btn.dataset.id);
        const label    = btn.textContent;
        btn.disabled    = true;
        result.hidden   = false;
        result.className = 'test-result is-pending';
        result.textContent = LLM_TXT.testing;
        try {
            const res  = await fetch('/admin/api-keys/' + encodeURIComponent(provider) + '/test', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF },
            });
            const data = await res.json();
            result.className   = 'test-result ' + (data.ok ? 'is-ok' : 'is-err');
            result.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
        } catch (e) {
            result.className   = 'test-result is-err';
            result.textContent = LLM_TXT.netError;
        }
        btn.disabled    = false;
        btn.textContent = label;
    });
});
})();
</script>

<?php require ROOT . '/templates/admin/llms/_foot.php'; ?>
