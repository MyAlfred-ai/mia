<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Connecteurs') ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">
<?php $page = 'connectors'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <div class="connector-page">
        <h1 style="margin-bottom:6px"><?= te('Connecteurs') ?></h1>
        <p class="connector-intro">
            <?= teb('Les services que vos utilisateurs peuvent relier à MIA : les sources consultables depuis le menu {b}+{/b} du chat, et OneDrive qui alimente les bases de connaissances.') ?>
            <?= teb('L\'interrupteur décide si le connecteur {b}apparaît ou non dans l\'interface{/b} : masqué, il disparaît du menu {b}+{/b} ET de la page {b}Réglages → Comptes connectés{/b} de tout le monde — plus aucun compte ne peut y être relié.') ?>
            <?= te('Les connexions déjà établies sont conservées (leur propriétaire garde de quoi se déconnecter) et reviennent telles quelles dès la réactivation.') ?>
            <?= teb('Chaque carte du chat porte aussi les {b}expressions{/b} qui font suggérer le connecteur pendant la frappe d\'un message ; elles sont modifiables.') ?>
        </p>
        <p style="margin-bottom:18px">
            <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
        </p>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/connectors" id="connectorsForm">
            <?= Csrf::field() ?>
            <?php $autoOn = ConnectorRegistry::isAutoModeEnabled(); ?>
            <div class="connector-card connector-auto-card<?= $autoOn ? '' : ' is-off' ?>" data-connector="<?= ConnectorRegistry::AUTO ?>"
                 data-on-label="<?= te('Proposé') ?>" data-off-label="<?= te('Désactivé') ?>">
                <div class="connector-top">
                    <div class="connector-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg></div>
                    <div class="connector-title">
                        <h3><?= te('Laisser MIA choisir') ?></h3>
                        <div class="connector-caps"><span class="badge badge-gray"><?= te('Activation à la demande') ?></span></div>
                    </div>
                    <div class="connector-switch">
                        <span class="connector-state"><?= $autoOn ? te('Proposé') : te('Désactivé') ?></span>
                        <label class="ios-switch">
                            <input type="checkbox" name="connectors[]" value="<?= ConnectorRegistry::AUTO ?>"
                                   <?= $autoOn ? 'checked' : '' ?>
                                   aria-label="<?= te('Proposer « Laisser MIA choisir » dans le menu + du chat') ?>">
                            <span class="ios-switch-slider"></span>
                        </label>
                    </div>
                </div>
                <p class="connector-summary">
                    <?= teb('Proposé, le menu {b}+{/b} gagne une ligne « Laisser MIA choisir » que chaque utilisateur allume s\'il le souhaite : ses connecteurs {b}reliés{/b} mais éteints deviennent activables par MIA elle-même, quand la demande en a besoin.') ?>
                    <?= te('Seul un outil léger part au modèle ; les outils d\'un connecteur ne sont chargés qu\'une fois activés, et les écritures restent soumises à l\'approbation de l\'utilisateur.') ?>
                </p>
            </div>
            <div class="connector-grid">
                <?php foreach ($connectors as $c):
                    $on         = !empty($c['enabled']);
                    $configured = !empty($c['configured']);
                    $connected  = $c['connected'];
                    $key        = (string)$c['key'];
                    $triggers   = (array)($c['triggers'] ?? []);
                    $custom     = !empty($c['triggers_custom']);
                ?>
                <div class="connector-card<?= $on ? '' : ' is-off' ?>" data-connector="<?= htmlspecialchars($key) ?>">
                    <div class="connector-top">
                        <div class="connector-icon"><?= $c['icon'] ?></div>
                        <div class="connector-title">
                            <h3><?= htmlspecialchars((string)$c['label']) ?></h3>
                            <div class="connector-caps">
                                <?php foreach ($c['capabilities'] as $cap): ?>
                                    <span class="badge badge-gray"><?= htmlspecialchars($cap) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="connector-switch">
                            <span class="connector-state"><?= $on ? te('Visible') : te('Masqué') ?></span>
                            <label class="ios-switch">
                                <input type="checkbox" name="connectors[]"
                                       value="<?= htmlspecialchars($key) ?>"
                                       <?= $on ? 'checked' : '' ?>
                                       aria-label="<?= te('Rendre « {label} » visible pour tous les utilisateurs', ['label' => (string)$c['label']]) ?>">
                                <span class="ios-switch-slider"></span>
                            </label>
                        </div>
                    </div>

                    <p class="connector-summary"><?= htmlspecialchars((string)$c['summary']) ?></p>

                    <div class="connector-meta">
                        <span class="connector-chat">
                            <?php if (!empty($c['has_triggers'])): ?>
                                <?= teb('Menu {b}+{/b} : « {label} »', ['label' => (string)$c['chat_label']]) ?>
                            <?php else: ?>
                                <?= teb('Réglages → {b}Comptes connectés{/b}') ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($connected !== null): ?>
                            <span class="connector-dot">•</span>
                            <span><?= htmlspecialchars(I18n::plural((int)$connected, '{n} compte relié', '{n} comptes reliés')) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$configured && !empty($c['warn'])): ?>
                        <p class="connector-warn">⚠ <?= htmlspecialchars((string)$c['warn']) ?></p>
                    <?php elseif (!empty($c['note'])): ?>
                        <p class="connector-note"><?= htmlspecialchars((string)$c['note']) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($c['settings_url'])): ?>
                        <p class="connector-config">
                            <a href="<?= htmlspecialchars((string)$c['settings_url']) ?>">
                                <?= (string)$c['settings_label'] !== '' ? htmlspecialchars((string)$c['settings_label']) : te('Configurer') ?> →
                            </a>
                        </p>
                    <?php endif; ?>

                    <?php if (empty($c['has_triggers'])): ?>
                        <?php // Hors chat : rien à suggérer pendant la frappe. Une zone
                             // d'expressions inopérante laisserait croire au contraire. ?>
                        <p class="connector-note connector-no-trigger">
                            <?= teb('Ce connecteur ne s\'utilise pas dans une conversation : il n\'a pas d\'expression déclencheuse. L\'interrupteur décide seulement s\'il apparaît dans {b}Réglages → Comptes connectés{/b}.') ?>
                        </p>
                    <?php else: ?>
                    <details class="connector-triggers">
                        <summary>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/></svg>
                            <span><?= te('Expressions déclencheuses') ?></span>
                            <span class="connector-triggers-count"><?= count($triggers) ?></span>
                            <span class="connector-triggers-badge" <?= $custom ? '' : 'hidden' ?>><?= te('Personnalisées') ?></span>
                            <svg class="connector-triggers-chev" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <p class="connector-triggers-hint">
                            <?= te('Quand un message en cours de frappe contient l\'une de ces expressions, MIA propose d\'activer ce connecteur (bannière au-dessus de la saisie).') ?>
                            <?= teb('{b}Une expression par ligne{/b} — mots entiers, accents et majuscules ignorés. Liste vide = aucune suggestion.') ?>
                            <?php if ($key === 'web'): ?>
                                <?= teb('La Recherche Internet n\'est suggérée que si son interrupteur est {b}éteint{/b} (il est actif par défaut).') ?>
                            <?php endif; ?>
                        </p>
                        <textarea class="connector-triggers-input" name="triggers[<?= htmlspecialchars($key) ?>]"
                                  rows="6" spellcheck="false"
                                  aria-label="<?= te('Expressions déclencheuses de « {label} »', ['label' => (string)$c['label']]) ?>"><?= htmlspecialchars(implode("\n", $triggers)) ?></textarea>
                        <div class="connector-triggers-actions">
                            <button type="button" class="btn-secondary connector-triggers-reset"
                                    title="<?= te('Revenir à la liste par défaut de l\'application') ?>"><?= te('Réinitialiser') ?></button>
                            <span class="connector-triggers-status" role="status" aria-live="polite"></span>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <noscript>
                <button type="submit" class="btn-primary" style="margin-top:18px"><?= te('Enregistrer') ?></button>
            </noscript>
        </form>

        <p style="color:var(--text-muted);font-size:12.5px;margin-top:20px">
            <?= teb('Les connecteurs Google et Microsoft sont {b}personnels{/b} : chaque utilisateur relie son propre compte depuis {b}Réglages → Comptes connectés{/b}. La Recherche Internet est au contraire un réglage {b}d\'organisation{/b}, commun à tous.') ?>
            <?php /* Phrase entière dans le dictionnaire : `{a}…{/a}` marque le
                     lien, posé APRÈS l'échappement. */ ?>
            <?= str_replace(['{a}', '{/a}'], ['<a href="/admin/tools">', '</a>'], te('Les workflows n8n ne sont pas des connecteurs : ce sont des outils confiés aux assistants ({a}Outils{/a}).')) ?>
            <?= te('Chaque action à effet de bord (document, brouillon, évènement) reste soumise à l\'approbation de l\'utilisateur dans la conversation. Les expressions modifiées s\'appliquent au prochain chargement du chat de chacun.') ?>
        </p>
    </div>
</div>

<script>
// Enregistrement immédiat, sans aucun bouton « Enregistrer » ni rechargement :
// - interrupteur → bascule du connecteur, dès le clic ;
// - zone d'expressions → départ après une pause de frappe (le bouton
//   « Réinitialiser » reste, il rétablit la liste par défaut).
// Dans les deux cas, l'état affiché ne change qu'après le OK serveur — un
// échec réseau doit se voir, pas mentir. Sans JavaScript, le formulaire
// complet poste interrupteurs ET zones d'expressions.
(function () {
    var form  = document.getElementById('connectorsForm');
    if (!form) return;
    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.content : '';
    // Pastille commune à tous les écrans de configuration : même retour visuel
    // ici que sur les formulaires pilotés par `autosave.js`.
    var pill = window.MiaAutosave;

    form.addEventListener('submit', function (e) { e.preventDefault(); });

    function post(fields) {
        var body = new FormData();
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        return fetch('/admin/connectors', {
            method: 'POST',
            headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' },
            body: body
        }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
    }

    form.querySelectorAll('.connector-card').forEach(function (card) {
        var key   = card.dataset.connector;
        var input = card.querySelector('.connector-switch input[type="checkbox"]');
        var state = card.querySelector('.connector-state');

        // --- Interrupteur Visible / Masqué ---
        if (input) {
            input.addEventListener('change', function () {
                var wanted = input.checked;
                input.disabled = true;
                card.classList.add('is-saving');
                if (pill) pill.paint('saving', <?= tj('Enregistrement…') ?>);
                post({ connector: key, enabled: wanted ? '1' : '0' })
                .then(function (data) {
                    if (!data || !data.ok) throw new Error();
                    card.classList.toggle('is-off', !wanted);
                    if (state) {
                        state.textContent = wanted ? (card.dataset.onLabel || <?= tj('Visible') ?>) : (card.dataset.offLabel || <?= tj('Masqué') ?>);
                        state.classList.add('is-flash');
                        setTimeout(function () { state.classList.remove('is-flash'); }, 900);
                    }
                    if (pill) pill.paint('saved', <?= tj('Enregistré') ?>);
                })
                .catch(function () {
                    input.checked = !wanted;        // rien enregistré : retour à l'état réel
                    if (state) state.textContent = <?= tj('Échec') ?>;
                    if (pill) pill.paint('error', <?= tj('Échec — le connecteur n\'a pas été modifié.') ?>);
                    setTimeout(function () {
                        if (state) state.textContent = input.checked ? (card.dataset.onLabel || <?= tj('Visible') ?>) : (card.dataset.offLabel || <?= tj('Masqué') ?>);
                    }, 2000);
                })
                .finally(function () {
                    input.disabled = false;
                    card.classList.remove('is-saving');
                });
            });
        }

        // --- Expressions déclencheuses ---
        // Plus de bouton « Enregistrer » : la zone part toute seule après une
        // pause de frappe, comme les formulaires d'`autosave.js`. Le re-rendu
        // depuis la liste canonique du serveur reste indispensable (la saisie
        // est normalisée et dédupliquée), il ne peut donc pas passer par le
        // module générique.
        var ta     = card.querySelector('.connector-triggers-input');
        var reset  = card.querySelector('.connector-triggers-reset');
        var status = card.querySelector('.connector-triggers-status');
        var count  = card.querySelector('.connector-triggers-count');
        var badge  = card.querySelector('.connector-triggers-badge');
        if (!ta || !reset) return;

        var timer = null;
        var pill  = window.MiaAutosave;

        function sendTriggers(fields, okLabel) {
            clearTimeout(timer);
            reset.disabled = true;
            status.textContent = <?= tj('Enregistrement…') ?>;
            status.className = 'connector-triggers-status';
            if (pill) pill.paint('saving', <?= tj('Enregistrement…') ?>);
            post(fields)
            .then(function (data) {
                if (!data || !data.ok || !Array.isArray(data.triggers)) throw new Error();
                // Re-rendu depuis la liste canonique du serveur (normalisée,
                // dédupliquée) — la zone reflète ce qui est vraiment actif.
                // Ne pas réécrire pendant la frappe : le curseur sauterait.
                if (document.activeElement !== ta) { ta.value = data.triggers.join('\n'); }
                if (count) count.textContent = String(data.triggers.length);
                if (badge) badge.hidden = !data.custom;
                status.textContent = okLabel;
                status.classList.add('is-ok');
                if (pill) pill.paint('saved', okLabel);
                setTimeout(function () { status.textContent = ''; }, 2500);
            })
            .catch(function () {
                status.textContent = <?= tj('Échec — rien n\'a été enregistré.') ?>;
                status.classList.add('is-err');
                if (pill) pill.paint('error', <?= tj('Expressions : échec, rien n\'a été enregistré.') ?>);
            })
            .finally(function () { reset.disabled = false; });
        }

        ta.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                sendTriggers({ connector: key, triggers: ta.value }, <?= tj('Enregistré ✓') ?>);
            }, 900);
        });
        // Quitter la zone ne doit pas laisser la dernière frappe en suspens.
        ta.addEventListener('blur', function () {
            if (timer) { sendTriggers({ connector: key, triggers: ta.value }, <?= tj('Enregistré ✓') ?>); }
        });
        reset.addEventListener('click', function () {
            sendTriggers({ connector: key, triggers_reset: '1' }, <?= tj('Liste par défaut rétablie ✓') ?>);
        });
    });
})();
</script>
</body>
</html>
