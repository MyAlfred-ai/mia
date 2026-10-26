<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Application Slack') ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">
<?php $page = 'connectors'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
/*
 * Écran « Application Slack » : la seule pièce COMMUNE du connecteur Slack.
 *
 * Il ne se contente pas de recueillir deux identifiants : il dit à
 * l'administrateur ce que chaque réglage ouvrira ensuite dans les
 * conversations. Le tableau des autorisations (`UserSlackService::scopeGuide`),
 * la consigne et les outils montrés en bas (`SlackTools::guidanceFor` /
 * `definitionsFor`, `ExternalContent::rule`) sont les MÊMES textes que ceux
 * que reçoit le modèle — jamais une paraphrase qui dériverait.
 */
$appLabel  = (string)Settings::get('app_name', 'MIA');
$scopeTip  = [
    'channels' => t('canaux publics'),
    'groups'   => t('canaux privés'),
    'im'       => t('messages directs'),
    'mpim'     => t('messages de groupe'),
];
$scopeChip = static function (string $scope) use ($scopeTip): string {
    $famille = explode(':', $scope)[0];
    $titre   = $scopeTip[$famille] ?? '';
    return '<code class="slack-scope"' . ($titre !== '' ? ' title="' . htmlspecialchars($titre) . '"' : '') . '>'
        . htmlspecialchars($scope) . '</code>';
};
// Phrases entières au dictionnaire : `{b}` (gras, teb()), `{i}` (italique),
// `{c}` (code) et `{a}…{/a}` (lien — balises ouvrantes passées en argument,
// dans l'ordre) sont posés APRÈS l'échappement : seules NOS balises passent.
$md = static function (string $echappe, string ...$liens): string {
    $html = str_replace(['{i}', '{/i}', '{c}', '{/c}', '{/a}'], ['<em>', '</em>', '<code>', '</code>', '</a>'], $echappe);
    foreach ($liens as $lien) {
        $pos = strpos($html, '{a}');
        if ($pos === false) {
            break;
        }
        $html = substr_replace($html, $lien, $pos, 3);
    }
    return $html;
};
?>
<div class="admin-content">
    <div class="connector-page slack-app-page">
        <p style="margin-bottom:10px"><a href="/admin/connectors" style="font-size:13px">← <?= te('Connecteurs') ?></a></p>
        <h1 style="margin-bottom:6px"><?= te('Application Slack') ?></h1>
        <p class="connector-intro">
            <?= teb('Le connecteur Slack laisse les assistants {b}chercher et lire{/b} les conversations Slack d\'un utilisateur, et {b}publier en son nom{/b} après son approbation — toujours avec {b}ses{/b} droits : MIA ne voit que ce qu\'il voit déjà.') ?>
            <?= teb('Cette page règle la seule pièce commune : l\'{b}application Slack{/b} de votre organisation, créée une fois. Elle ne donne accès à rien par elle-même ; chaque utilisateur relie ensuite son propre compte dans {b}Réglages → Comptes connectés{/b}.') ?>
        </p>

        <?php /* Où en est le parcours : trois constats, chacun mène à l'endroit où il se règle. */ ?>
        <div class="slack-status" aria-label="<?= te('État du connecteur Slack') ?>">
            <a class="slack-status-item <?= $configured ? 'is-ok' : 'is-todo' ?>" href="#identifiants">
                <span class="slack-status-dot" aria-hidden="true"></span>
                <?= $configured ? teb('Application : {b}identifiants enregistrés{/b}') : teb('Application : {b}à configurer{/b}') ?>
            </a>
            <a class="slack-status-item <?= $enabled ? 'is-ok' : 'is-todo' ?>" href="/admin/connectors">
                <span class="slack-status-dot" aria-hidden="true"></span>
                <?= $enabled ? teb('Connecteur : {b}visible des utilisateurs{/b}') : teb('Connecteur : {b}masqué{/b}') ?>
            </a>
            <?php if ($linkedCount !== null): ?>
                <span class="slack-status-item <?= $linkedCount > 0 ? 'is-ok' : 'is-idle' ?>">
                    <span class="slack-status-dot" aria-hidden="true"></span>
                    <?= str_replace(['{b}', '{/b}'], ['<strong>', '</strong>'], htmlspecialchars(I18n::plural((int)$linkedCount, '{b}{n}{/b} compte Slack relié', '{b}{n}{/b} comptes Slack reliés'))) ?>
                </span>
            <?php endif; ?>
        </div>

        <nav class="slack-toc" aria-label="<?= te('Sommaire') ?>">
            <a href="#mise-en-place"><?= te('Mise en place') ?></a>
            <a href="#autorisations"><?= te('Autorisations') ?></a>
            <a href="#conversation"><?= te('Dans une conversation') ?></a>
            <a href="#prompt"><?= te('Ce que reçoit le modèle') ?></a>
        </nav>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="margin-bottom:20px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php /* ===================== 1. Mise en place ===================== */ ?>
        <div class="card slack-card" id="mise-en-place">
            <div class="card-head">
                <div class="card-ic" aria-hidden="true"><img src="/assets/img/slack.svg" width="22" height="22" alt=""></div>
                <div class="card-head-text">
                    <h3><?= te('Mise en place — une fois pour toute l\'organisation') ?></h3>
                    <p><?= te('Environ cinq minutes, avec un compte autorisé à créer une application dans votre espace de travail Slack.') ?></p>
                </div>
            </div>

            <div class="tool-step" data-step="1">
                <p class="step-title"><?= te('Créer l\'application dans Slack') ?></p>
                <p class="step-sub">
                    <?= $md(teb('Le plus sûr : depuis le {b}manifeste{/b} ci-dessous, déjà rempli avec l\'URL de retour de cette instance et la liste exacte des autorisations — les deux saisies où l\'on se trompe. Sur {a}api.slack.com/apps{/a} : {b}Create New App → From a manifest{/b}, choisissez l\'espace de travail de vos utilisateurs, collez le manifeste (format {b}JSON{/b}), puis {b}Next{/b} et {b}Create{/b}.'), '<a href="https://api.slack.com/apps" target="_blank" rel="noopener">') ?>
                </p>
                <div class="slack-copyable">
                    <button type="button" class="btn-secondary slack-copy-btn" data-copy-target="slackManifest"><?= te('Copier le manifeste') ?></button>
                    <pre id="slackManifest" class="slack-pre"><?= htmlspecialchars($manifestJson) ?></pre>
                </div>
                <p class="slack-note">
                    <?= te('L\'espace de travail compte : tant qu\'elle n\'est pas distribuée publiquement, une application Slack ne s\'installe que dans l\'espace où elle a été créée.') ?>
                    <?php /* Espaces insécables (U+00A0, invisibles à l'éditeur) autour du nom. */ ?>
                    <?= te('Le nom (« {nom} ») est celui que vos utilisateurs liront sur l\'écran d\'autorisation de Slack ; vous pouvez le changer avant de valider.', ['nom' => mb_substr(trim($appLabel) !== '' ? trim($appLabel) : 'MIA', 0, SlackApp::MANIFEST_NAME_MAX)]) ?>
                </p>
                <details class="slack-details">
                    <summary><?= te('Préférer la saisie manuelle, ou corriger une application existante') ?></summary>
                    <ol class="slack-list">
                        <li><?= teb('{b}Create New App → From scratch{/b} : nommez l\'application, choisissez l\'espace de travail.') ?></li>
                        <li><?= teb('{b}OAuth & Permissions → Redirect URLs → Add New Redirect URL{/b} : collez exactement cette URL, puis {b}Add{/b} et surtout {b}Save URLs{/b}.') ?>
                            <span class="slack-url">
                                <code id="slackRedirectUri"><?= htmlspecialchars($redirectUri) ?></code>
                                <button type="button" class="btn-secondary slack-copy-btn" data-copy-target="slackRedirectUri"><?= te('Copier') ?></button>
                            </span>
                            <span class="slack-muted"><?= te('C\'est l\'URL de retour du reste de l\'application : il n\'y en a pas d\'autre à déclarer.') ?></span></li>
                        <li><?= $md(teb('Même page, section {b}Scopes → User Token Scopes{/b} — et non {i}Bot Token Scopes{/i} : MIA agit au nom de chaque utilisateur, jamais en son nom propre.')) ?>
                            <?= $md(teb('{b}Add an OAuth Scope{/b} pour chacune des {n} autorisations :', ['n' => count($scopes)])) ?>
                            <span class="slack-scope-list">
                                <?php foreach ($scopes as $scope): ?><?= $scopeChip($scope) ?><?php endforeach; ?>
                            </span>
                            <?= $md(te('Laissez {i}Bot Token Scopes{/i} vide.')) ?></li>
                        <li><?= $md(teb('Application déjà créée ? Le menu {b}App Manifest{/b} de ses réglages permet de remplacer le bloc {c}oauth_config{/c} par celui du manifeste ci-dessus.')) ?></li>
                    </ol>
                </details>
            </div>

            <div class="tool-step" data-step="2" id="identifiants">
                <p class="step-title"><?= te('Copier les identifiants dans MIA') ?></p>
                <p class="step-sub">
                    <?= $md(teb('Dans Slack, {b}Basic Information → App Credentials{/b} : le {b}Client ID{/b} et le {b}Client Secret{/b} (bouton {i}Show{/i}). Pas le {i}Signing Secret{/i} ni le {i}Verification Token{/i}, inutiles ici.')) ?>
                    <?= teb('Enregistrement {b}manuel{/b}, comme sur la page Authentification : des identifiants ne partent jamais à moitié saisis. {b}Tester{/b} vérifie l\'application et l\'URL de retour sans ouvrir d\'écran de consentement.') ?>
                </p>

                <form method="POST" action="/admin/connectors/slack/settings" id="slackAppForm" data-autosave="off" class="slack-form">
                    <?= Csrf::field() ?>
                    <div class="form-group">
                        <label for="slackClientId">Client ID</label>
                        <input type="text" id="slackClientId" name="slack_client_id" class="form-input"
                               value="<?= htmlspecialchars($clientId) ?>" placeholder="1234567890.9876543210"
                               autocomplete="off" spellcheck="false">
                    </div>
                    <div class="form-group">
                        <label for="slackClientSecret">Client Secret</label>
                        <input type="password" id="slackClientSecret" name="slack_client_secret" class="form-input"
                               placeholder="<?= $hasSecret ? te('•••••••• (enregistré — laisser vide pour le conserver)') : te('Secret de l\'application') ?>"
                               autocomplete="new-password" spellcheck="false">
                        <?php if ($hasSecret): ?>
                            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:13px;color:var(--text-muted)">
                                <input type="checkbox" name="remove_slack_secret" value="1">
                                <?= te('Supprimer le secret enregistré') ?>
                            </label>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px">
                        <button type="submit" class="btn-primary"><?= te('Enregistrer les identifiants') ?></button>
                        <button type="button" class="btn-secondary" id="slackTestBtn"><?= te('Tester') ?></button>
                        <span id="slackTestResult" style="font-size:13px" role="status" aria-live="polite"></span>
                    </div>
                </form>
            </div>

            <div class="tool-step" data-step="3">
                <p class="step-title"><?= te('Faire approuver l\'application, si votre espace l\'exige') ?></p>
                <p class="step-sub">
                    <?= te('Sur un espace de travail où les applications doivent être approuvées, un administrateur Slack valide celle-ci. Tant qu\'il ne l\'a pas fait, la connexion des utilisateurs échoue, ou revient avec des autorisations manquantes (badges à tiret dans leurs Connexions).') ?>
                    <?= teb('La {b}rotation des jetons{/b} (Basic Information → Token Rotation) est facultative : MIA la gère si elle est activée, et s\'en passe sinon.') ?>
                </p>
            </div>

            <div class="tool-step" data-step="4">
                <p class="step-title"><?= te('Rendre le connecteur visible') ?></p>
                <p class="step-sub">
                    <?= $md($enabled
                        ? teb('{a}Connecteurs{/a} → carte {b}Slack{/b} → {b}Visible{/b} (actuellement : {b}visible{/b}).')
                        : teb('{a}Connecteurs{/a} → carte {b}Slack{/b} → {b}Visible{/b} (actuellement : {b}masqué{/b}).'), '<a href="/admin/connectors">') ?>
                    <?= teb('Masqué, le connecteur disparaît pour tout le monde — menu {b}+{/b} du chat et page Connexions — sans effacer les comptes déjà reliés. La même carte règle les expressions qui font suggérer Slack pendant la frappe.') ?>
                </p>
            </div>
        </div>

        <?php /* ===================== 2. Autorisations ===================== */ ?>
        <div class="card slack-card" id="autorisations">
            <div class="card-head">
                <div class="card-ic" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div class="card-head-text">
                    <h3><?= te('Les autorisations, et ce qu\'elles ouvrent dans MIA') ?></h3>
                    <p><?= teb('Toutes sont des autorisations {b}utilisateur{/b}. Chaque personne les accorde à la connexion ; MIA ne donne au modèle que les outils qu\'elle a réellement accordés — un consentement partiel donne un connecteur réduit, jamais cassé.') ?></p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="slack-scope-table">
                    <thead>
                        <tr>
                            <th><?= te('Autorisation') ?></th>
                            <th><?= te('Ce que MIA peut faire') ?></th>
                            <th><?= te('Outil donné au modèle') ?></th>
                            <th><?= te('Si elle manque') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php /* Les textes du guide (UserSlackService::scopeGuide) sont
                                 les phrases françaises du code : traduites ICI, à
                                 l'affichage, clé = le texte lui-même. */ ?>
                        <?php foreach ($scopeGuide as $ligne): ?>
                            <tr>
                                <td data-label="<?= te('Autorisation') ?>"><span class="slack-scope-list"><?php foreach ($ligne['scopes'] as $scope): ?><?= $scopeChip($scope) ?><?php endforeach; ?></span></td>
                                <td data-label="<?= te('Ce que MIA peut faire') ?>"><?= htmlspecialchars(t((string)$ligne['usage'])) ?></td>
                                <td data-label="<?= te('Outil donné au modèle') ?>">
                                    <?php if ($ligne['outils'] === []): ?>
                                        <span class="slack-muted"><?= te('Aucun : sert à tous les outils de lecture') ?></span>
                                    <?php else: ?>
                                        <?php foreach ($ligne['outils'] as $outil): ?><code class="slack-tool"><?= htmlspecialchars($outil) ?></code> <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="slack-muted" data-label="<?= te('Si elle manque') ?>"><?= htmlspecialchars(t((string)$ligne['sans'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="slack-note">
                <?= $md(te('Préfixes : {c}channels{/c} = canaux publics, {c}groups{/c} = canaux privés, {c}im{/c} = messages directs, {c}mpim{/c} = messages de groupe.')) ?>
            </p>
            <p class="slack-note">
                <?= $md(teb('{b}Jamais demandées{/b} : {c}chat:write.public{/c} (publier dans un canal dont on n\'est pas membre), {c}im:write{/c} (ouvrir un nouveau message direct), {c}users:read.email{/c} (les adresses des collègues), aucune autorisation de bot ni d\'administration.')) ?>
                <br><?= $md(teb('{b}À savoir sur {c}chat:write{/c}{/b} : c\'est le seul droit qui permette de publier, et il couvre aussi — côté API — la modification et la suppression des messages de la personne. MIA n\'expose aucun outil de ce genre : chercher, lire, publier après approbation, rien d\'autre.')) ?>
            </p>
        </div>

        <?php /* ===================== 3. Dans une conversation ===================== */ ?>
        <div class="card slack-card" id="conversation">
            <div class="card-head">
                <div class="card-ic" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <div class="card-head-text">
                    <h3><?= te('Ensuite : comment MIA s\'en sert dans une conversation') ?></h3>
                    <p><?= te('Ce que vivent vos utilisateurs une fois l\'application en place.') ?></p>
                </div>
            </div>

            <div class="tool-step" data-step="1">
                <p class="step-title"><?= te('Chaque utilisateur relie son Slack') ?></p>
                <p class="step-sub">
                    <?= teb('{b}Réglages → Comptes connectés{/b} → carte Slack → {b}Connecter mon Slack{/b}.') ?>
                    <?= teb('Slack affiche son écran d\'autorisation ; au retour, trois badges — Recherche, Lecture, Publication — disent ce qui a été accordé. Le jeton est personnel et chiffré ; {b}Déconnecter{/b} retire aussi l\'autorisation côté Slack.') ?>
                </p>
            </div>

            <div class="tool-step" data-step="2">
                <p class="step-title"><?= te('Il l\'allume pour sa question') ?></p>
                <p class="step-sub">
                    <?php /* Le libellé du menu « + » est traduit comme dans le chat
                             (même clé que ConnectorRegistry::chatLabel()). Les
                             expressions sont du contenu : citées telles quelles,
                             entre guillemets insécables (U+00A0, invisibles). */ ?>
                    <?= teb('Menu {b}+{/b} du chat → {b}{label}{/b}.', ['label' => t($chatLabel)]) ?>
                    <?php if ($triggers !== []): ?>
                        <?php
                        $exprList = implode(', ', array_map(
                            static fn (string $expr): string => t('« {expr} »', ['expr' => $expr]),
                            array_slice($triggers, 0, 6)
                        )) . (count($triggers) > 6 ? '…' : '');
                        ?>
                        <?= teb('Jamais allumé d\'office ; le choix est ensuite retenu sur son navigateur. Deux raccourcis : {b}Laisser MIA choisir{/b} (MIA active Slack d\'elle-même quand la question le demande), et une suggestion qui propose l\'interrupteur dès que le message parle de Slack (expressions actuelles : {liste}).', ['liste' => $exprList]) ?>
                    <?php else: ?>
                        <?= teb('Jamais allumé d\'office ; le choix est ensuite retenu sur son navigateur. Deux raccourcis : {b}Laisser MIA choisir{/b} (MIA active Slack d\'elle-même quand la question le demande), et une suggestion qui propose l\'interrupteur dès que le message parle de Slack (aucune expression déclencheuse : suggestion coupée).') ?>
                    <?php endif; ?>
                    <?= te('Il faut un modèle qui sait appeler des outils : sinon, MIA propose d\'en changer.') ?>
                </p>
            </div>

            <div class="tool-step" data-step="3">
                <p class="step-title"><?= te('Le modèle reçoit des outils et une consigne') ?></p>
                <p class="step-sub">
                    <?= teb('Pour ce message seulement, le modèle reçoit les outils Slack que CET utilisateur a autorisés (tableau ci-dessus), une consigne dans son prompt système, et la règle de sécurité commune à tous les connecteurs : un message Slack lu est une {b}donnée{/b}, jamais une instruction — si un message lui demande d\'agir, il le signale au lieu d\'obéir. Le texte exact est en bas de page.') ?>
                </p>
            </div>

            <div class="tool-step" data-step="4">
                <p class="step-title"><?= te('Il cherche et lit avec les droits de l\'utilisateur') ?></p>
                <p class="step-sub">
                    <?= te('Question ouverte (« qu\'a-t-on dit du budget ? ») : recherche d\'abord ; canal nommé (« quoi de neuf dans #projet-x ? ») : lecture directe. La réponse cite l\'auteur, la date et le lien du message.') ?>
                    <?= te('Chaque action apparaît dans la trace au-dessus de la réponse, et le canal ou le fil consulté reste en mémoire de la conversation : « réponds dans ce fil » marche au message suivant. Un canal privé dont l\'utilisateur n\'est pas membre n\'existe pas pour MIA.') ?>
                </p>
            </div>

            <div class="tool-step" data-step="5">
                <p class="step-title"><?= te('Publier : toujours une carte d\'approbation') ?></p>
                <p class="step-sub">
                    <?= teb('Le modèle ne publie jamais lui-même : il prépare une {b}carte{/b} qui montre le canal et le texte exact, déjà converti au format Slack. Rien ne part sans le clic {b}Approuver{/b} de l\'utilisateur.') ?>
                    <?= teb('Ensuite le message est publié {b}en son nom{/b}, aussitôt visible de tout le canal — contrairement à un brouillon d\'email, il n\'y a pas de retour possible, et MIA ne peut ni le modifier ni le supprimer.') ?>
                </p>
            </div>

            <p class="slack-note">
                <?= teb('{b}Coûts{/b} : les appels à Slack ne consomment aucun crédit. Seul le modèle compte ses jetons — un canal lu entre dans le contexte de la réponse, et la lecture est bornée à la moitié de ce que le modèle peut lire.') ?>
            </p>
        </div>

        <?php /* ===================== 4. Ce que reçoit le modèle ===================== */ ?>
        <div class="card slack-card" id="prompt">
            <div class="card-head">
                <div class="card-ic" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                </div>
                <div class="card-head-text">
                    <h3><?= te('Ce que reçoit le modèle') ?></h3>
                    <p><?= te('Les textes exacts ajoutés au prompt quand Slack est allumé — ceux du code, pas un résumé. Ils ne se modifient pas ici : ils évoluent avec MIA.') ?></p>
                </div>
            </div>

            <details class="slack-details">
                <?php /* Les textes ci-dessous sont les prompts EXACTS envoyés au
                         modèle (en français, par décision de périmètre) : montrés
                         tels quels, jamais traduits. */ ?>
                <summary><?= te('Consigne ajoutée au prompt système') ?></summary>
                <p class="slack-muted"><?= te('Version d\'un utilisateur qui a autorisé la publication :') ?></p>
                <pre class="slack-pre slack-pre-wrap"><?= htmlspecialchars(trim($promptGuidance)) ?></pre>
                <p class="slack-muted"><?= $md(te('Ce qui change pour une connexion en lecture seule (sans {c}chat:write{/c}) — la fin de la consigne devient :')) ?></p>
                <pre class="slack-pre slack-pre-wrap"><?= htmlspecialchars($promptReadOnlyTail) ?></pre>
            </details>

            <details class="slack-details">
                <summary><?= te('Outils décrits au modèle ({n})', ['n' => count($promptToolDefs)]) ?></summary>
                <dl class="slack-tool-defs">
                    <?php foreach ($promptToolDefs as $def): ?>
                        <dt><code class="slack-tool"><?= htmlspecialchars((string)$def['name']) ?></code></dt>
                        <dd><?= htmlspecialchars((string)$def['description']) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </details>

            <details class="slack-details">
                <summary><?= te('Règle de sécurité commune aux connecteurs') ?></summary>
                <pre class="slack-pre slack-pre-wrap"><?= htmlspecialchars(trim($promptSafetyRule)) ?></pre>
            </details>
        </div>
    </div>
</div>

<script>
// Bouton « Tester » : éprouve les identifiants SAISIS, sans passer par un
// aller-retour de consentement — si Slack se plaint du code de test, c'est
// qu'il a accepté l'application.
(function () {
    var btn  = document.getElementById('slackTestBtn');
    var form = document.getElementById('slackAppForm');
    var out  = document.getElementById('slackTestResult');
    if (!btn || !form || !out) return;
    var meta  = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.content : '';

    btn.addEventListener('click', function () {
        var body = new FormData();
        body.append('slack_client_id', form.querySelector('#slackClientId').value);
        body.append('slack_client_secret', form.querySelector('#slackClientSecret').value);
        btn.disabled = true;
        out.textContent = <?= tj('Test en cours…') ?>;
        out.style.color = 'var(--text-muted)';
        fetch('/admin/connectors/slack/test', {
            method: 'POST',
            headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' },
            body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            out.textContent = data && data.message ? data.message : <?= tj('Réponse inattendue.') ?>;
            out.style.color = data && data.ok ? 'var(--success, #2e7d32)' : 'var(--danger, #c62828)';
        })
        .catch(function () {
            out.textContent = <?= tj('Test impossible — vérifiez votre connexion.') ?>;
            out.style.color = 'var(--danger, #c62828)';
        })
        .finally(function () { btn.disabled = false; });
    });
})();

// Boutons « Copier » : le manifeste et l'URL de retour doivent arriver dans
// Slack au caractère près — une recopie à la main est l'erreur la plus
// fréquente (`bad_redirect_uri`). Repli par sélection quand le presse-papiers
// n'est pas accessible (page servie hors HTTPS).
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
