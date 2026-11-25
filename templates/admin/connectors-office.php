<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Claude dans Office') ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">
<?php $page = 'connectors'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
/*
 * Écran « Claude dans Office » : l'add-in officiel d'Anthropic branché sur MIA
 * comme passerelle (OfficeGateway). Les classes `slack-*` sont celles de
 * l'écran de l'application Slack, même mise en page (état, valeurs à copier,
 * étapes) : un second jeu de règles dériverait du premier.
 */
$copyRow = static function (string $id, string $value): void { ?>
    <span class="slack-url">
        <code id="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($value) ?></code>
        <button type="button" class="btn-secondary slack-copy-btn" data-copy-target="<?= htmlspecialchars($id) ?>"><?= te('Copier') ?></button>
    </span>
<?php };
$appLabel = (string)Settings::get('app_name', 'MIA');
?>
<div class="admin-content">
    <?php require ROOT . '/templates/partials/admin-topbar.php'; ?>
    <div class="connector-page slack-app-page">
        <p style="margin-bottom:10px"><a href="/admin/connectors" style="font-size:13px">← <?= te('Connecteurs') ?></a></p>
        <header class="page-head">
        <div class="page-head-text">
            <div class="office-head-apps"><?= OfficeAddin::appIcons(null, 30) ?></div>
            <h1 style="margin-bottom:6px"><?= te('Claude dans Office') ?></h1>
            <p class="connector-intro page-head-sub">
                <?= teb('L\'add-in officiel {b}Claude{/b} d\'Anthropic pour {b}Excel, Word et PowerPoint{/b} peut passer par MIA au lieu d\'un compte Claude par personne. Vos utilisateurs n\'ont {b}aucun compte à créer{/b} : MIA reconnaît chacun, relaie ses demandes à Anthropic avec la clé de l\'organisation et les compte dans {b}ses crédits MIA{/b}, comme une conversation.') ?>
                <?= teb('Avec la {b}connexion Microsoft{/b}, la DSI règle les choses {b}une seule fois{/b} pour toute l\'organisation : plus aucune demande au cas par cas.') ?>
            </p>
        </div>
        </header>

        <div class="slack-status" aria-label="<?= te('État de Claude dans Office') ?>">
            <a class="slack-status-item <?= $enabled ? 'is-ok' : 'is-todo' ?>" href="#reglages">
                <span class="slack-status-dot" aria-hidden="true"></span>
                <?= $enabled ? teb('Passerelle : {b}activée{/b}') : teb('Passerelle : {b}désactivée{/b}') ?>
            </a>
            <a class="slack-status-item <?= $hasKey ? 'is-ok' : 'is-todo' ?>" href="/admin/llms">
                <span class="slack-status-dot" aria-hidden="true"></span>
                <?= $hasKey ? teb('Clé Anthropic : {b}présente{/b}') : teb('Clé Anthropic : {b}à ajouter{/b}') ?>
            </a>
            <a class="slack-status-item is-ok" href="#applications">
                <span class="slack-status-dot" aria-hidden="true"></span>
                <?= OfficeAddin::appIcons($apps, 16) ?>
                <?= teb('Applications : {b}{apps}{/b}', ['apps' => OfficeAddin::appsSentence($apps)]) ?>
            </a>
            <?php if ($authMode !== OfficeAddin::AUTH_TOKEN): ?>
                <a class="slack-status-item <?= $entraReady ? 'is-ok' : 'is-todo' ?>" href="#microsoft">
                    <span class="slack-status-dot" aria-hidden="true"></span>
                    <?= $entraReady ? teb('Connexion Microsoft : {b}prête{/b}') : teb('Connexion Microsoft : {b}à configurer{/b}') ?>
                </a>
            <?php endif; ?>
            <?php if ($tokenCount !== null && $authMode !== OfficeAddin::AUTH_ENTRA): ?>
                <span class="slack-status-item <?= $tokenCount > 0 ? 'is-ok' : 'is-idle' ?>">
                    <span class="slack-status-dot" aria-hidden="true"></span>
                    <?= str_replace(['{b}', '{/b}'], ['<strong>', '</strong>'], htmlspecialchars(I18n::plural((int)$tokenCount, '{b}{n}{/b} jeton personnel', '{b}{n}{/b} jetons personnels'))) ?>
                </span>
            <?php endif; ?>
            <?php if ($officeMonth !== null): ?>
                <a class="slack-status-item is-idle" href="/admin/usage#categories"
                   title="<?= htmlspecialchars(I18n::plural((int)$officeMonth['requests'], '{n} demande', '{n} demandes') . ' · ' . I18n::plural((int)$officeMonth['people'], '{n} personne', '{n} personnes')) ?>">
                    <span class="slack-status-dot" aria-hidden="true"></span>
                    <?= str_replace(['{b}', '{/b}'], ['<strong>', '</strong>'], htmlspecialchars(t('{b}{n}{/b} crédits ce mois-ci', ['n' => Format::credits((int)$officeMonth['credits'])]))) ?>
                    · <?= htmlspecialchars(I18n::plural((int)$officeMonth['people'], '{n} personne', '{n} personnes')) ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="margin-bottom:20px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!$gatewayHttps): ?>
            <div class="alert alert-error" style="margin-bottom:20px"><?= te('L\'adresse de cette instance n\'est pas en HTTPS : l\'add-in refusera de s\'y connecter. Vérifiez la variable APP_URL.') ?></div>
        <?php endif; ?>

        <?php /* ===================== 1. Réglages ===================== */ ?>
        <div class="card slack-card" id="reglages">
            <div class="card-head">
                <div class="card-ic" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg>
                </div>
                <div class="card-head-text">
                    <h3><?= te('Réglages') ?></h3>
                    <p><?= teb('Enregistrement {b}manuel{/b} : activer la passerelle ouvre une dépense, et des identifiants ne partent jamais à moitié saisis.') ?></p>
                </div>
            </div>
            <form method="POST" action="/admin/connectors/office/settings" id="officeAddinForm" data-autosave="off" class="slack-form">
                <?= Csrf::field() ?>
                <label style="display:flex;align-items:center;gap:10px;font-size:14px;margin-bottom:14px">
                    <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                    <span><?= teb('{b}Activer la passerelle{/b} — l\'add-in peut joindre MIA à l\'adresse ci-dessous.') ?></span>
                </label>
                <div class="form-group">
                    <label><?= te('Adresse de la passerelle (à donner à l\'add-in)') ?></label>
                    <?php $copyRow('officeGatewayUrl', $gatewayUrl); ?>
                </div>

                <fieldset class="form-group" id="applications" style="border:0;padding:0;margin:0 0 14px">
                    <legend style="font-size:13px;font-weight:600;margin-bottom:8px"><?= te('Applications qui reçoivent l\'add-in') ?></legend>
                    <div class="office-apps">
                        <?php foreach ([
                            'excel'      => t('Analyser un classeur, écrire des formules, construire tableaux et graphiques.'),
                            'word'       => t('Rédiger, relire et réécrire un document, en gardant sa mise en forme.'),
                            'powerpoint' => t('Créer et retoucher des diapositives à partir d\'un plan ou d\'un document.'),
                        ] as $app => $hint): ?>
                            <label class="office-app-tile">
                                <input type="checkbox" name="apps[]" value="<?= $app ?>" <?= in_array($app, $apps, true) ? 'checked' : '' ?>>
                                <?= OfficeAddin::appIcon($app, 30) ?>
                                <span><span class="office-app-name"><?= htmlspecialchars(OfficeAddin::APPS[$app]['label']) ?></span>
                                <span class="office-app-hint"><?= htmlspecialchars($hint) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="slack-muted" style="font-size:12.5px;margin:8px 0 0"><?= te('Un seul add-in, une seule passerelle, un seul manifeste : seules les applications cochées y figurent. Après un changement, téléchargez le manifeste et redéposez-le dans Microsoft 365.') ?></p>
                </fieldset>

                <fieldset class="form-group" style="border:0;padding:0;margin:0 0 14px">
                    <legend style="font-size:13px;font-weight:600;margin-bottom:8px"><?= te('Comment reconnaître chaque personne') ?></legend>
                    <?php foreach ([
                        OfficeAddin::AUTH_BOTH  => [t('Les deux'), t('Connexion Microsoft, et jetons personnels pour qui n\'en dispose pas.')],
                        OfficeAddin::AUTH_ENTRA => [t('Connexion Microsoft seulement'), t('Rien à saisir pour les utilisateurs ; la DSI déploie l\'add-in une fois.')],
                        OfficeAddin::AUTH_TOKEN => [t('Jetons personnels seulement'), t('Aucune action dans Entra ; chacun colle son jeton une fois dans l\'add-in.')],
                    ] as $value => [$label, $hint]): ?>
                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13.5px;margin-bottom:8px">
                            <input type="radio" name="auth" value="<?= htmlspecialchars($value) ?>" <?= $authMode === $value ? 'checked' : '' ?> style="margin-top:3px">
                            <span><strong><?= htmlspecialchars($label) ?></strong><br><span class="slack-muted"><?= htmlspecialchars($hint) ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <div class="form-group">
                    <label for="officeClientId"><?= te('ID d\'application Entra (client ID)') ?></label>
                    <input type="text" id="officeClientId" name="client_id" class="form-input" value="<?= htmlspecialchars($ownClientId) ?>"
                           placeholder="<?= $inheritsSso && $clientId !== '' ? htmlspecialchars(t('{id} (celui de la connexion Microsoft)', ['id' => $clientId])) : '1b2c3d4e-0000-4a5b-8c9d-0123456789ab' ?>"
                           autocomplete="off" spellcheck="false">
                </div>
                <div class="form-group">
                    <label for="officeTenant"><?= te('ID de l\'annuaire (tenant ID)') ?></label>
                    <input type="text" id="officeTenant" name="tenant" class="form-input" value="<?= htmlspecialchars($ownTenant) ?>"
                           placeholder="<?= $inheritsSso && $tenant !== '' ? htmlspecialchars(t('{id} (celui de la connexion Microsoft)', ['id' => $tenant])) : '00000000-0000-0000-0000-000000000000' ?>"
                           autocomplete="off" spellcheck="false">
                    <p class="slack-muted" style="font-size:12.5px;margin:6px 0 0"><?= te('Laissés vides, ces deux champs reprennent ceux de la connexion Microsoft (Organisation → Authentification), si l\'annuaire y est donné par son GUID. Seuls les comptes de cet annuaire sont acceptés.') ?></p>
                </div>

                <label style="display:flex;align-items:center;gap:10px;font-size:13.5px;margin-bottom:14px">
                    <input type="checkbox" name="web_search" value="1" <?= $webSearch ? 'checked' : '' ?>>
                    <span><?= te('Laisser la recherche Internet de l\'add-in (servie par le fournisseur de recherche d\'Anthropic, comptée en crédits)') ?></span>
                </label>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px">
                    <button type="submit" class="btn-primary"><?= te('Enregistrer') ?></button>
                    <button type="button" class="btn-secondary" id="officeTestBtn"><?= te('Tester') ?></button>
                </div>
                <ul id="officeTestResult" class="slack-list" style="list-style:none;padding-left:0" role="status" aria-live="polite"></ul>
            </form>
        </div>

        <?php /* ===================== 2. Connexion Microsoft ===================== */ ?>
        <div class="card slack-card" id="microsoft">
            <div class="card-head">
                <div class="card-ic" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="8"/><rect x="13" y="3" width="8" height="8"/><rect x="3" y="13" width="8" height="8"/><rect x="13" y="13" width="8" height="8"/></svg>
                </div>
                <div class="card-head-text">
                    <h3><?= te('Avec la connexion Microsoft — une fois pour toute l\'organisation') ?></h3>
                    <p><?= te('Recommandé. L\'add-in envoie à MIA le jeton Microsoft 365 de la personne ; MIA vérifie sa signature et la rattache à son compte MIA (même adresse). Rien à saisir, aucun jeton à distribuer.') ?></p>
                </div>
            </div>
            <div class="tool-step" data-step="1">
                <p class="step-title"><?= te('Ouvrir l\'application dans Entra') ?></p>
                <p class="step-sub"><?= teb('Centre d\'administration Microsoft Entra → {b}Inscriptions d\'applications{/b} : celle qui sert déjà à la connexion à MIA convient, sinon une nouvelle, pour {b}cet annuaire seulement{/b}. Reportez son ID d\'application et l\'ID de l\'annuaire dans les réglages ci-dessus s\'ils diffèrent de ceux de la connexion.') ?></p>
            </div>
            <div class="tool-step" data-step="2">
                <p class="step-title"><?= te('Déclarer les deux adresses de retour de l\'add-in') ?></p>
                <p class="step-sub"><?= teb('{b}Authentification → Ajouter une plateforme → Application monopage (SPA){/b}, puis ces deux adresses — sous la plateforme SPA, ni Web ni mobile :') ?></p>
                <?php foreach ($redirectUris as $i => $uri) { $copyRow('officeRedirect' . $i, $uri); } ?>
            </div>
            <div class="tool-step" data-step="3">
                <p class="step-title"><?= te('Exposer la permission que l\'add-in demandera') ?></p>
                <p class="step-sub">
                    <?= teb('{b}Exposer une API{/b} → URI d\'ID d\'application {b}api://<ID d\'application>{/b} → {b}Ajouter une étendue{/b} nommée :') ?>
                    <?php $copyRow('officeScope', $scope); ?>
                    <?= teb('Puis {b}Autorisations d\'API → Ajouter → Mes API{/b} → cette application → {b}{scope}{/b} (déléguée), et {b}Accorder le consentement administrateur{/b}. Sans ce consentement, chaque personne verrait une demande d\'autorisation.', ['scope' => $scope]) ?>
                </p>
            </div>
            <div class="tool-step" data-step="4">
                <p class="step-title"><?= te('Jetons au format v2') ?></p>
                <p class="step-sub"><?= teb('{b}Manifeste{/b} de l\'application : {b}accessTokenAcceptedVersion{/b} (ou {b}requestedAccessTokenVersion{/b} selon l\'éditeur) à {b}2{/b}. MIA accepte aussi l\'ancien format, mais c\'est celui que recommande Anthropic.') ?></p>
            </div>
            <div class="tool-step" data-step="5">
                <p class="step-title"><?= te('Déployer l\'add-in dans Microsoft 365') ?></p>
                <p class="step-sub">
                    <?= teb('Téléchargez le manifeste ci-dessous, puis {b}Centre d\'administration Microsoft 365 → Paramètres → Applications intégrées → Charger des applications personnalisées{/b}, type {b}Complément Office{/b}. Attribuez-le aux groupes voulus (un groupe pilote d\'abord). La diffusion prend jusqu\'à 24 heures ; l\'add-in apparaît ensuite dans {b}{apps}{/b}, menu {b}Accueil → Compléments{/b} (Windows) ou {b}Outils → Compléments{/b} (Mac).', ['apps' => OfficeAddin::appsSentence($apps)]) ?>
                </p>
                <p style="margin:10px 0 0">
                    <a class="btn-primary" href="/admin/connectors/office/manifest" style="display:inline-block;text-decoration:none">
                        <?= $manifestEntra ? te('Télécharger le manifeste (connexion Microsoft)') : te('Télécharger le manifeste (jetons personnels)') ?>
                    </a>
                </p>
                <p class="slack-note">
                    <?= te('Le manifeste reprend celui d\'Anthropic, avec l\'adresse de MIA et la connexion choisie ; il ne contient aucun secret. Il change de version quand un réglage change : redéposez-le alors dans Microsoft 365 (même application, nouvelle version).') ?>
                    <?php if (!$manifestEntra && $authMode !== OfficeAddin::AUTH_TOKEN): ?>
                        <br><strong><?= te('La connexion Microsoft n\'est pas encore prête : le manifeste téléchargé demande un jeton personnel.') ?></strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php /* ===================== 3. Jetons personnels ===================== */ ?>
        <div class="card slack-card" id="jetons">
            <div class="card-head">
                <div class="card-ic" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/></svg>
                </div>
                <div class="card-head-text">
                    <h3><?= te('Sans action dans Entra : les jetons personnels') ?></h3>
                    <p><?= te('Pour démarrer vite, ou pour une organisation qui ne touche pas à Entra. Chacun agit une fois.') ?></p>
                </div>
            </div>
            <div class="tool-step" data-step="1">
                <p class="step-title"><?= te('Chaque personne génère son jeton dans MIA') ?></p>
                <p class="step-sub"><?= teb('{b}Réglages → Comptes connectés{/b} → carte {b}Claude dans Office{/b} → {b}Générer mon jeton{/b}. Il n\'est montré qu\'une fois ; en générer un autre révoque le premier.') ?></p>
            </div>
            <div class="tool-step" data-step="2">
                <p class="step-title"><?= te('Elle le colle dans l\'add-in') ?></p>
                <p class="step-sub"><?= teb('Dans {apps}, l\'add-in Claude → {b}Cloud provider or gateway{/b} → {b}Gateway{/b}, puis l\'adresse de la passerelle et le jeton — une fois par application. L\'add-in vient du manifeste téléchargé ici (adresse déjà remplie) ou de Microsoft AppSource si votre organisation l\'autorise.', ['apps' => OfficeAddin::appsSentence($apps, true)]) ?></p>
                <?php $copyRow('officeGatewayUrl2', $gatewayUrl); ?>
            </div>
        </div>

        <?php /* ===================== 4. Ce qu'il faut savoir ===================== */ ?>
        <div class="card slack-card" id="savoir">
            <div class="card-head">
                <div class="card-ic" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                </div>
                <div class="card-head-text">
                    <h3><?= te('Ce qu\'il faut savoir') ?></h3>
                </div>
            </div>
            <ul class="slack-list">
                <li><?= teb('{b}Crédits{/b} : chaque demande de l\'add-in compte dans les crédits de la personne, à son coût réel chez Anthropic — jetons (cache lu et écrit compris) et recherches Internet ; mode rapide et inférence aux États-Unis au tarif majoré qu\'Anthropic applique. Quota, budget de l\'organisation et comptes de démonstration s\'appliquent comme dans le chat, vérifiés avant chaque demande.') ?></li>
                <li><?= teb('{b}Où le lire{/b} : catégorie « Claude dans Office » de Coûts → Consommation (par personne, par modèle, par catégorie), du Budget et de Réglages → Usage & crédits de chaque personne.') ?></li>
                <li><?= teb('{b}Modèles{/b} : les modèles Claude ouverts aux groupes de la personne (Modèles IA, Groupes). Les autres fournisseurs ne servent pas l\'add-in.') ?></li>
                <li><?= teb('{b}Applications{/b} : Excel, Word et PowerPoint reçoivent le même add-in et passent par la même passerelle. Leur consommation forme une seule source, « Claude dans Office » : l\'add-in ne dit pas de quelle application vient une demande. Outlook n\'est pas couvert (Anthropic le livre par un manifeste à part).') ?></li>
                <li><?= teb('{b}Données{/b} : Office → {app} → Anthropic. MIA ne garde aucun contenu, seulement les jetons consommés. L\'add-in échange avec pivot.claude.ai son interface et des mesures d\'usage, sans le contenu.', ['app' => $appLabel]) ?></li>
                <li><?= teb('{b}Historique{/b} : les échanges restent dans l\'add-in, sur l\'ordinateur de la personne ; ils n\'apparaissent pas dans ses conversations MIA.') ?></li>
                <li><?= teb('{b}Pas disponibles par une passerelle{/b} (limite d\'Anthropic) : la dictée et le travail entre plusieurs applications Office. La recherche Internet et l\'exécution de code fonctionnent, MIA relayant Anthropic tel quel.') ?></li>
                <li><?= teb('{b}Réseau{/b} : les postes doivent joindre {b}pivot.claude.ai{/b}, {b}login.microsoftonline.com{/b} et l\'adresse de MIA.') ?></li>
            </ul>
        </div>
    </div>
</div>
<script>
// « Tester » : ce que MIA vérifie d'elle-même (activation, clé, HTTPS,
// adresse publique, annuaire Microsoft). Textes rendus par le serveur.
(function () {
    var btn = document.getElementById('officeTestBtn');
    var out = document.getElementById('officeTestResult');
    if (!btn || !out) return;
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.content : '';
    var marks = { ok: '✓', warn: '!', fail: '✗' };
    var colors = { ok: 'var(--success, #2e7d32)', warn: 'var(--kb-warn, #b26a00)', fail: 'var(--danger, #c62828)' };
    btn.addEventListener('click', function () {
        btn.disabled = true;
        out.textContent = '';
        var wait = document.createElement('li');
        wait.textContent = <?= tj('Test en cours…') ?>;
        out.appendChild(wait);
        fetch('/admin/connectors/office/test', {
            method: 'POST',
            headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            out.textContent = '';
            (data && data.checks || []).forEach(function (c) {
                var li = document.createElement('li');
                var mark = document.createElement('strong');
                mark.textContent = (marks[c.status] || '?') + ' ' + c.label;
                mark.style.color = colors[c.status] || 'inherit';
                li.appendChild(mark);
                if (c.detail) {
                    var d = document.createElement('span');
                    d.className = 'slack-muted';
                    d.textContent = c.detail;
                    li.appendChild(d);
                }
                out.appendChild(li);
            });
        })
        .catch(function () {
            out.textContent = '';
            var li = document.createElement('li');
            li.textContent = <?= tj('Test impossible — vérifiez votre connexion.') ?>;
            out.appendChild(li);
        })
        .finally(function () { btn.disabled = false; });
    });
})();
// Boutons « Copier » : une adresse ou une permission recopiée à la main est
// l'erreur la plus fréquente. Repli par sélection hors HTTPS.
(function () {
    document.querySelectorAll('.slack-copy-btn').forEach(function (btn) {
        var label = btn.textContent;
        btn.addEventListener('click', function () {
            var cible = document.getElementById(btn.getAttribute('data-copy-target') || '');
            if (!cible) return;
            var texte = cible.textContent;
            var fait = function () {
                btn.textContent = <?= tj('Copié ✓') ?>;
                setTimeout(function () { btn.textContent = label; }, 1800);
            };
            var repli = function () {
                var plage = document.createRange();
                plage.selectNodeContents(cible);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(plage);
                btn.textContent = <?= tj('Sélectionné — Ctrl+C') ?>;
                setTimeout(function () { btn.textContent = label; }, 2400);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(texte).then(fait, repli);
            } else {
                repli();
            }
        });
    });
})();
</script>
</body>
</html>
