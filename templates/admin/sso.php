<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Authentification') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'sso'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
// ENREGISTREMENT MANUEL, section par section — aucun attribut d'enregistrement
// automatique sur cette page (AutosaveContractTest / SsoCablageTest). Chaque carte est son
// propre <form>, posté au clic sur SON bouton « Enregistrer », avec un champ
// `section` : des identifiants de connexion ne partent jamais à moitié saisis,
// et couper un moyen de connexion est un geste qu'on assume d'un clic.
//
// La confirmation (ou l'avertissement anti-verrouillage) s'affiche DANS la
// carte qui vient d'être enregistrée, où la page revient (ancre) : en tête
// d'écran, elle serait hors de vue de qui vient de cliquer en bas.
$sectionsConnues = in_array($savedSection, SsoRegistry::SECTIONS, true);
$noteDeSection = static function (string $section) use ($success, $error, $savedSection): void {
    if ($savedSection !== $section) {
        return;
    }
    if ($error) {
        echo '<div class="alert alert-error sso-section-alert" role="alert">' . htmlspecialchars($error) . '</div>';
    }
    if ($success) {
        echo '<div class="alert alert-success sso-section-alert" role="status">' . htmlspecialchars($success) . '</div>';
    }
};
?>
<div class="admin-content">
    <h1><?= te('Authentification') ?></h1>
    <p class="sso-lead">
        <?= teb('Les moyens de se connecter à {app} : {b}Google{/b}, {b}Microsoft{/b} et la connexion par {b}e-mail et mot de passe{/b}.', ['app' => Settings::get('app_name', 'MIA')]) ?>
        <?= strtr(te("Chacun s'active indépendamment ; la page de connexion n'affiche que ceux qui sont activés {i}et{/i} renseignés."), ['{i}' => '<em>', '{/i}' => '</em>']) ?>
        <?= strtr(te('Les identifiants Google servent aussi aux {lien} (Drive, Gmail, Agenda).'), ['{lien}' => '<a href="/admin/connectors">' . te('connecteurs personnels') . '</a>']) ?>
        <?= teb("{b}Chaque section s'enregistre avec son propre bouton{/b} — rien ne part avant le clic.") ?>
    </p>

    <?php if (!$sectionsConnues): ?>
        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:20px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- URI de redirection : hors formulaire, c'est une donnée à recopier, pas un réglage. -->
    <div class="card" style="margin-bottom:20px">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:4px"><?= te('URI de redirection') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            <?= strtr(teb('À déclarer {b}telle quelle{/b} dans la console Google {i}et{/i} dans le portail Microsoft. Une seule URI suffit pour tout : les deux connexions et les trois connecteurs personnels y reviennent, et se distinguent par un marqueur interne.'), ['{i}' => '<em>', '{/i}' => '</em>']) ?>
        </p>
        <div style="display:flex;gap:8px;align-items:center;max-width:620px">
            <input type="text" class="form-input" id="ssoRedirectUri" readonly
                   value="<?= htmlspecialchars($redirectUri) ?>" style="flex:1">
            <button type="button" class="btn-secondary" style="font-size:13px;white-space:nowrap"
                    onclick="copierUriRedirection(this)"><?= te('Copier') ?></button>
        </div>
    </div>

    <!-- Google -->
    <form method="POST" action="/admin/sso/settings" class="card sso-section" id="sso-<?= SsoRegistry::SECTION_GOOGLE ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="section" value="<?= SsoRegistry::SECTION_GOOGLE ?>">
        <div class="sso-section-head">
            <h3>
                <svg width="16" height="16" viewBox="0 0 48 48" style="flex-shrink:0">
                    <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.2l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.6 5.6 2.7 13.7l7.9 6.1C12.5 13.5 17.8 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8C43.8 37.5 46.5 31.4 46.5 24.5z"/>
                    <path fill="#FBBC05" d="M10.6 28.2A14.5 14.5 0 0 1 9.5 24c0-1.5.2-2.9.6-4.3l-7.9-6.1A23.9 23.9 0 0 0 0 24c0 3.9.9 7.5 2.5 10.8l8.1-6.6z"/>
                    <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.2 1.5-5 2.4-8.4 2.4-6.2 0-11.5-4.2-13.4-9.8l-8.1 6.6C6.6 42.4 14.6 48 24 48z"/>
                </svg>
                Google
            </h3>
            <label class="ios-switch" style="flex-shrink:0" title="<?= te('Proposer « Continuer avec Google » sur la page de connexion') ?>">
                <input type="checkbox" name="google_enabled" value="1" <?= $googleEnabled ? 'checked' : '' ?>>
                <span class="ios-switch-slider"></span>
            </label>
        </div>
        <?php $noteDeSection(SsoRegistry::SECTION_GOOGLE); ?>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            <?= strtr(teb("Créez un {b}ID client OAuth 2.0{/b} de type « Application Web » dans la {lien}, et ajoutez-y l'URI de redirection ci-dessus. Le secret est stocké chiffré et n'est jamais réaffiché."), ['{lien}' => '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">' . te('console Google Cloud') . '</a>']) ?>
        </p>
        <?php $googleSecretSet = !empty($settings[SsoRegistry::SETTING_GOOGLE_SECRET]); ?>
        <div class="sso-grid-2">
            <div class="form-group" style="margin:0">
                <label><?= te('ID client') ?></label>
                <input type="text" name="google_client_id" class="form-input"
                       value="<?= htmlspecialchars($googleClientId) ?>"
                       placeholder="123456789-xxxx.apps.googleusercontent.com">
            </div>
            <div class="form-group" style="margin:0">
                <label><?= te('Secret client') ?> <?= $googleSecretSet ? '<span style="font-weight:400;color:var(--text-muted)">' . te('(configuré)') . '</span>' : '' ?></label>
                <input type="password" name="google_client_secret" class="form-input"
                       autocomplete="new-password"
                       placeholder="<?= $googleSecretSet ? '••••••••••••  ' . te('(laisser vide pour conserver)') : 'GOCSPX-…' ?>">
            </div>
        </div>
        <?php if ($googleFromEnv): ?>
            <p style="font-size:12px;color:var(--text-muted);margin-top:10px">
                <?= strtr(te('Ces identifiants proviennent actuellement du fichier {fichier} du serveur. Saisissez-les ici pour pouvoir les modifier sans redéploiement — les valeurs enregistrées ici prennent alors le pas.'), ['{fichier}' => '<code>.env</code>']) ?>
            </p>
        <?php endif; ?>
        <?php if ($googleSecretSet): ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);margin-top:10px;cursor:pointer;width:fit-content">
                <input type="checkbox" name="remove_google_credentials" value="1" style="accent-color:var(--accent)">
                <?= te('Supprimer les identifiants Google enregistrés') ?>
            </label>
        <?php endif; ?>
        <div class="sso-section-actions">
            <div class="sync-test-row">
                <button type="button" class="btn-secondary" style="font-size:13px" id="googleTestBtn"
                        onclick="testSsoCredentials('google')">
                    <?= te('Tester les identifiants') ?>
                </button>
                <div class="sync-test-result" id="googleTestResult" role="status" aria-live="polite"></div>
            </div>
            <div class="sso-save">
                <span class="sso-save-note"><?= te('Interrupteur et identifiants partent ensemble.') ?></span>
                <button type="submit" class="btn-primary"><?= te('Enregistrer') ?></button>
            </div>
        </div>
    </form>

    <!-- Microsoft -->
    <form method="POST" action="/admin/sso/settings" class="card sso-section" id="sso-<?= SsoRegistry::SECTION_MICROSOFT ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="section" value="<?= SsoRegistry::SECTION_MICROSOFT ?>">
        <div class="sso-section-head">
            <h3>
                <svg width="16" height="16" viewBox="0 0 23 23" style="flex-shrink:0">
                    <path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M12 1h10v10H12z"/>
                    <path fill="#00A4EF" d="M1 12h10v10H1z"/><path fill="#FFB900" d="M12 12h10v10H12z"/>
                </svg>
                Microsoft
            </h3>
            <label class="ios-switch" style="flex-shrink:0" title="<?= te('Proposer « Continuer avec Microsoft » sur la page de connexion') ?>">
                <input type="checkbox" name="microsoft_enabled" value="1" <?= $microsoftEnabled ? 'checked' : '' ?>>
                <span class="ios-switch-slider"></span>
            </label>
        </div>
        <?php $noteDeSection(SsoRegistry::SECTION_MICROSOFT); ?>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            <?= strtr(teb("Inscrivez une application dans le {lien} (Applications → Inscriptions d'applications), ajoutez l'URI de redirection ci-dessus en type {b}Web{/b}, puis créez un secret client (Certificats & secrets) et collez ici sa {b}valeur{/b}, pas son identifiant."), ['{lien}' => '<a href="https://entra.microsoft.com/" target="_blank" rel="noopener">' . te('portail Microsoft Entra') . '</a>']) ?>
        </p>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            <?= strtr(teb("{b}À ne pas confondre{/b} avec l'application Azure de {lien}, qui sert à lire SharePoint pour les bases de connaissances (et OneDrive, quand il a sa propre inscription). Ce sont deux inscriptions distinctes : {i}se connecter{/i} ne demande aucune permission sur vos données."), ['{lien}' => '<a href="/admin/knowledge-bases/sync">' . te('Sources synchronisées') . '</a>', '{i}' => '<em>', '{/i}' => '</em>']) ?>
        </p>
        <?php if ($microsoftDelegatedScopes !== []): ?>
            <?php /* Les connecteurs Microsoft du chat réutilisent CETTE inscription : un annuaire
                    qui interdit le consentement utilisateur exige que leurs permissions y soient
                    déclarées pour qu'un administrateur puisse les accorder. Sans cette liste, on
                    configurait « une application qui ne demande rien » et les connecteurs
                    échouaient ensuite en AADSTS65001, sans que rien ne relie les deux. */ ?>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm)">
                <?= teb("{b}Si vous activez les connecteurs Microsoft{/b} (Outlook, calendrier Outlook), ils utilisent cette même inscription. Ajoutez-y les permissions {b}déléguées{/b} Microsoft Graph correspondantes (Autorisations d'API → Microsoft Graph → Autorisations déléguées) :") ?>
                <ul style="margin:6px 0 0;padding-left:18px">
                    <?php foreach ($microsoftDelegatedScopes as $connecteur => $scopes): ?>
                        <li><?= te('{connecteur} :', ['connecteur' => t((string)$connecteur)]) ?>
                            <?= implode(', ', array_map(
                                static fn(string $s): string => '<code>' . htmlspecialchars($s) . '</code>',
                                $scopes
                            )) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin:6px 0 0">
                    <?= te('Si votre annuaire interdit aux utilisateurs de consentir eux-mêmes, cliquez ensuite « Accorder le consentement administrateur » — sinon chaque connexion échouera avec un message de consentement manquant.') ?>
                </p>
            </div>
        <?php endif; ?>
        <?php $msSecretSet = !empty($settings[SsoRegistry::SETTING_MS_SECRET]); ?>
        <div class="sso-grid-2">
            <div class="form-group" style="margin:0">
                <label><?= te("ID d'application (client)") ?></label>
                <input type="text" name="microsoft_client_id" class="form-input"
                       value="<?= htmlspecialchars($microsoftClientId) ?>"
                       placeholder="00000000-0000-0000-0000-000000000000">
            </div>
            <div class="form-group" style="margin:0">
                <label><?= te('Secret client') ?> <?= $msSecretSet ? '<span style="font-weight:400;color:var(--text-muted)">' . te('(configuré)') . '</span>' : '' ?></label>
                <input type="password" name="microsoft_client_secret" class="form-input"
                       autocomplete="new-password"
                       placeholder="<?= $msSecretSet ? '••••••••••••  ' . te('(laisser vide pour conserver)') : te('valeur du secret') ?>">
            </div>
        </div>
        <div class="form-group" style="margin:16px 0 0;max-width:420px">
            <label><?= te('Annuaire (locataire)') ?></label>
            <input type="text" name="microsoft_tenant" class="form-input"
                   value="<?= htmlspecialchars($microsoftTenant) ?>" placeholder="common">
            <p style="font-size:12px;color:var(--text-muted);margin-top:6px">
                <?= strtr(teb("{common} accepte tout compte Microsoft, professionnel ou personnel ; {organizations} les seuls comptes professionnels ; l'{b}ID de l'annuaire{/b} de votre organisation la restreint à elle seule."), ['{common}' => '<code>common</code>', '{organizations}' => '<code>organizations</code>']) ?>
            </p>
        </div>
        <?php if (!$microsoftTenantIsNamed): ?>
            <p style="font-size:12px;color:var(--text-muted);margin-top:10px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm)">
                <?= strtr(teb("{b}Annuaire ouvert.{/b} Microsoft ne garantit pas qu'une adresse e-mail appartient réellement à la personne qui se connecte : n'importe qui peut créer un annuaire et y déclarer l'adresse de son choix. Un compte Microsoft ne sera donc jamais rattaché à un compte existant, sauf si vous activez le champ facultatif {claim} sur l'application (Entra → Configuration du jeton → ajouter le champ facultatif {champ}, puis cocher l'option), ou si vous renseignez les domaines de votre organisation ci-dessous."), ['{claim}' => '<code>xms_edov</code>', '{champ}' => '<code>email</code>']) ?>
            </p>
        <?php endif; ?>
        <?php if ($msSecretSet): ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);margin-top:10px;cursor:pointer;width:fit-content">
                <input type="checkbox" name="remove_microsoft_credentials" value="1" style="accent-color:var(--accent)">
                <?= te('Supprimer les identifiants Microsoft enregistrés') ?>
            </label>
        <?php endif; ?>
        <div class="sso-section-actions">
            <div class="sync-test-row">
                <button type="button" class="btn-secondary" style="font-size:13px" id="microsoftTestBtn"
                        onclick="testSsoCredentials('microsoft')">
                    <?= te('Tester les identifiants') ?>
                </button>
                <div class="sync-test-result" id="microsoftTestResult" role="status" aria-live="polite"></div>
            </div>
            <div class="sso-save">
                <span class="sso-save-note"><?= te('Interrupteur, identifiants et annuaire partent ensemble.') ?></span>
                <button type="submit" class="btn-primary"><?= te('Enregistrer') ?></button>
            </div>
        </div>
    </form>

    <!-- Mot de passe -->
    <form method="POST" action="/admin/sso/settings" class="card sso-section" id="sso-<?= SsoRegistry::SECTION_PASSWORD ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="section" value="<?= SsoRegistry::SECTION_PASSWORD ?>">
        <div class="sso-section-head">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0">
                    <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                <?= te('E-mail et mot de passe') ?>
            </h3>
            <label class="ios-switch" style="flex-shrink:0" title="<?= te('Afficher le formulaire e-mail / mot de passe sur la page de connexion') ?>">
                <input type="checkbox" name="password_enabled" value="1" <?= $passwordEnabled ? 'checked' : '' ?>>
                <span class="ios-switch-slider"></span>
            </label>
        </div>
        <?php $noteDeSection(SsoRegistry::SECTION_PASSWORD); ?>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            <?= htmlspecialchars(I18n::plural((int)$comptesMotDePasse, '{n} compte actif dispose d\'un mot de passe.', '{n} comptes actifs disposent d\'un mot de passe.')) ?>
            <?= strtr(te('Les mots de passe se définissent compte par compte dans {lien}.'), ['{lien}' => '<a href="/admin/users">' . te('Utilisateurs') . '</a>']) ?>
        </p>
        <?php if ($secoursActif): ?>
            <p style="font-size:12px;color:var(--text-muted)">
                <?= strtr(teb("Un {b}accès de secours{/b} est configuré sur le serveur ({variable}). Il reste utilisable même si tout le reste est désactivé — c'est le recours si une connexion externe cesse de fonctionner."), ['{variable}' => '<code>LOCAL_PASSWORD_HASH</code>']) ?>
            </p>
        <?php endif; ?>
        <div class="sso-section-actions">
            <div class="sso-save">
                <span class="sso-save-note"><?= te("Éteindre ce moyen sans autre méthode utilisable le réactive d'office.") ?></span>
                <button type="submit" class="btn-primary"><?= te('Enregistrer') ?></button>
            </div>
        </div>
    </form>

    <!-- Arrivée de nouveaux comptes -->
    <form method="POST" action="/admin/sso/settings" class="card sso-section" id="sso-<?= SsoRegistry::SECTION_DOMAINS ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="section" value="<?= SsoRegistry::SECTION_DOMAINS ?>">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:4px"><?= te('Domaines autorisés') ?></h3>
        <?php $noteDeSection(SsoRegistry::SECTION_DOMAINS); ?>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            <?= te('Un domaine par ligne. Seules les adresses de ces domaines peuvent créer un compte par Google ou Microsoft.') ?>
            <?= strtr(teb("{b}Laissez vide pour n'imposer aucune restriction.{/b} La règle ne s'applique qu'à la {i}création{/i} : un compte déjà existant continue de se connecter même si son domaine sort de la liste."), ['{i}' => '<em>', '{/i}' => '</em>']) ?>
            <?= strtr(te("L'activation automatique des nouveaux comptes se règle dans {lien}."), ['{lien}' => '<a href="/admin/users">' . te('Utilisateurs') . '</a>']) ?>
        </p>
        <div class="form-group" style="margin:0;max-width:420px">
            <label><?= te('Domaines e-mail') ?></label>
            <textarea name="allowed_domains" class="form-input" rows="3"
                      placeholder="<?= te('exemple.fr') ?>&#10;<?= te('exemple.com') ?>"><?= htmlspecialchars($allowedDomains) ?></textarea>
        </div>
        <div class="sso-section-actions">
            <div class="sso-save">
                <button type="submit" class="btn-primary"><?= te('Enregistrer') ?></button>
            </div>
        </div>
    </form>

    <!-- Page de connexion : message libre -->
    <form method="POST" action="/admin/sso/settings" class="card sso-section" id="sso-<?= SsoRegistry::SECTION_LOGIN ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="section" value="<?= SsoRegistry::SECTION_LOGIN ?>">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:4px"><?= te('Message sur la page de connexion') ?></h3>
        <?php $noteDeSection(SsoRegistry::SECTION_LOGIN); ?>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            <?= te("Un texte court affiché sous le nom de l'application, avant les boutons de connexion : mot de bienvenue, consigne d'accès, adresse à contacter pour obtenir un compte.") ?>
            <?= teb("{b}Vide par défaut{/b} — rien n'est affiché. Les retours à la ligne sont conservés ; le texte s'affiche tel que vous l'écrivez, dans toutes les langues.") ?>
        </p>
        <div class="sso-login-grid">
            <div class="form-group" style="margin:0">
                <label for="loginMessage"><?= te('Texte ({n} caractères au plus)', ['n' => SsoRegistry::LOGIN_MESSAGE_MAX]) ?></label>
                <textarea id="loginMessage" name="login_message" class="form-input" rows="5"
                          maxlength="<?= SsoRegistry::LOGIN_MESSAGE_MAX ?>"
                          placeholder="<?= te('Ex. : Accès réservé aux collaborateurs.') ?>&#10;<?= te('Pour obtenir un compte, écrivez à support@exemple.fr.') ?>"><?= htmlspecialchars($loginMessage) ?></textarea>
                <div class="sso-counter" aria-live="polite"><span id="loginMessageCount">0</span> / <?= SsoRegistry::LOGIN_MESSAGE_MAX ?></div>
            </div>
            <div class="sso-login-preview-wrap">
                <span class="sso-login-preview-label"><?= te('Aperçu sur la page de connexion') ?></span>
                <div class="sso-login-preview">
                    <div class="sso-login-preview-title"><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></div>
                    <p class="login-notice" id="loginMessagePreview" hidden></p>
                    <p class="sso-login-preview-empty" id="loginMessageEmpty"><?= te('Aucun message : la page de connexion reste telle quelle.') ?></p>
                </div>
            </div>
        </div>
        <div class="sso-section-actions">
            <div class="sso-save">
                <button type="submit" class="btn-primary"><?= te('Enregistrer') ?></button>
            </div>
        </div>
    </form>
</div>

<style>
.sso-lead { color:var(--text-muted); font-size:13px; line-height:1.55; margin:-6px 0 18px; max-width:760px }
.sso-section { margin-bottom:20px; scroll-margin-top:16px }
.sso-section-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px }
.sso-section-head h3 { font-size:14px; font-weight:600; margin-bottom:4px; display:flex; align-items:center; gap:8px }
.sso-section-alert { margin:10px 0 12px; font-size:13px }
.sso-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; max-width:760px }
/* Pied de section : Tester à gauche, Enregistrer à droite, filet au-dessus —
   le bouton appartient visiblement à SA carte, pas à la page. */
.sso-section-actions {
    display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    margin-top:16px; padding-top:14px; border-top:1px solid var(--border);
}
.sso-section-actions .sync-test-row { margin-top:0; flex:1 1 320px; min-width:0 }
.sso-save { display:inline-flex; align-items:center; gap:12px; margin-left:auto }
.sso-save-note { font-size:12px; color:var(--text-muted) }
.sso-counter { font-size:12px; color:var(--text-muted); text-align:right }
.sso-counter.is-near { color:#b45309; font-weight:600 }
.sso-login-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:20px; align-items:start; max-width:980px }
.sso-login-preview-wrap { display:flex; flex-direction:column; gap:6px; min-width:0 }
.sso-login-preview-label { font-size:13px; color:var(--text-muted) }
/* Reproduit la boîte de connexion (fond, bordure, largeur) pour que l'aperçu
   dise vrai ; `.login-notice` est LA règle de la page de connexion, partagée. */
.sso-login-preview {
    display:flex; flex-direction:column; align-items:center; gap:12px;
    padding:22px 20px; max-width:380px;
    background:var(--bg-sidebar); border:1px solid var(--border); border-radius:var(--radius);
}
.sso-login-preview-title { font-family:var(--font-title); font-weight:500; letter-spacing:-.3px; font-size:20px }
.sso-login-preview-empty { font-size:12px; color:var(--text-muted); text-align:center; margin:0 }
.sync-test-row { display:flex; align-items:center; gap:12px; margin-top:12px; flex-wrap:wrap }
.sync-test-result {
    display:none; align-items:flex-start; gap:8px;
    font-size:13px; line-height:1.45; padding:8px 12px; max-width:640px;
    border-radius:var(--radius-sm); border:1px solid transparent;
}
.sync-test-result.visible { display:flex }
.sync-test-result.ok      { color:#10a37f; background:rgba(16,163,127,.08); border-color:rgba(16,163,127,.35) }
.sync-test-result.err     { color:#ef4444; background:rgba(239,68,68,.07);  border-color:rgba(239,68,68,.3) }
.sync-test-result.pending { color:var(--text-muted); background:var(--surface); border-color:var(--border) }
.sync-test-result svg { flex-shrink:0; margin-top:2px }
.sync-test-spinner {
    width:14px; height:14px; flex-shrink:0; margin-top:2px;
    border:2px solid var(--border); border-top-color:var(--accent);
    border-radius:50%; animation:syncTestSpin .7s linear infinite;
}
@keyframes syncTestSpin { to { transform:rotate(360deg) } }
@media (max-width: 900px) {
    .sso-login-grid { grid-template-columns:1fr }
}
@media (max-width: 768px) {
    .sso-grid-2 { grid-template-columns:1fr }
    .sso-section-actions { flex-direction:column; align-items:stretch }
    .sso-save { margin-left:0; flex-direction:column; align-items:stretch; gap:8px }
    .sso-save .btn-primary { justify-content:center }
}
</style>
<script>
// ---- Test des identifiants (même mécanique que « Sources synchronisées ») ----
// Les valeurs saisies mais pas encore enregistrées sont envoyées et priment
// côté serveur : on teste AVANT d'enregistrer. Les champs sont cherchés par
// nom dans toute la page : chaque section est son propre formulaire.
const SSO_TEST_ICONS = {
    ok:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    err: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
};
// Substituants {nom} des textes traduits par tj().
const ssoFill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => ((vars && k in vars) ? String(vars[k]) : m));
const SSO_TEST_FIELDS = {
    google:    ['google_client_id', 'google_client_secret'],
    microsoft: ['microsoft_client_id', 'microsoft_client_secret', 'microsoft_tenant'],
};

async function testSsoCredentials(provider) {
    const btn  = document.getElementById(provider + 'TestBtn');
    const box  = document.getElementById(provider + 'TestResult');
    const body = new URLSearchParams({ provider });
    (SSO_TEST_FIELDS[provider] || []).forEach(name => {
        const input = document.querySelector('[name="' + name + '"]');
        if (input && input.value.trim() !== '') body.set(name, input.value.trim());
    });

    btn.disabled  = true;
    box.className = 'sync-test-result pending visible';
    box.innerHTML = '<span class="sync-test-spinner"></span><span></span>';
    box.lastElementChild.textContent = <?= tj('Vérification en cours…') ?>;
    try {
        const res  = await fetch('/admin/sso/test-credentials', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: body.toString(),
        });
        const data = await res.json();
        const ok   = !!data.ok;
        box.className = 'sync-test-result visible ' + (ok ? 'ok' : 'err');
        box.innerHTML = SSO_TEST_ICONS[ok ? 'ok' : 'err'] + '<span></span>';
        box.lastElementChild.textContent = ok
            ? ssoFill(<?= tj('Identifiants valides — {detail}') ?>, { detail: data.detail || <?= tj('connexion opérationnelle.') ?> })
            : (data.error || <?= tj('Échec de la vérification — réessayez.') ?>);
    } catch (e) {
        box.className = 'sync-test-result visible err';
        box.innerHTML = SSO_TEST_ICONS.err + '<span></span>';
        box.lastElementChild.textContent = <?= tj('Serveur injoignable pendant la vérification — vérifiez votre connexion puis réessayez.') ?>;
    } finally {
        btn.disabled = false;
    }
}

function copierUriRedirection(btn) {
    const champ = document.getElementById('ssoRedirectUri');
    const fini  = () => { const avant = btn.textContent; btn.textContent = <?= tj('Copié') ?>; setTimeout(() => { btn.textContent = avant; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(champ.value).then(fini).catch(() => { champ.select(); });
    } else {
        // Sans presse-papiers (HTTP, navigateur ancien) : sélectionner reste
        // utile, l'utilisateur fait Ctrl+C.
        champ.select();
        champ.setSelectionRange(0, 99999);
    }
}

// ---- Message de la page de connexion : compteur + aperçu vivant ----
// Le compteur compte des CARACTÈRES (comme le serveur, mb_strlen), pas des
// octets : « é » vaut 1. L'aperçu réutilise la règle `.login-notice` de la
// page de connexion — ce qu'on voit ici est ce qu'on verra là-bas.
(function () {
    const zone    = document.getElementById('loginMessage');
    const compte  = document.getElementById('loginMessageCount');
    const apercu  = document.getElementById('loginMessagePreview');
    const vide    = document.getElementById('loginMessageEmpty');
    if (!zone || !compte || !apercu || !vide) return;
    const max = parseInt(zone.getAttribute('maxlength'), 10) || 500;
    const rendre = () => {
        const texte = zone.value.replace(/\r\n?/g, '\n');
        const n = Array.from(texte).length;
        compte.textContent = String(n);
        compte.parentElement.classList.toggle('is-near', n >= max - 40);
        const propre = texte.trim();
        apercu.textContent = propre;
        apercu.hidden = propre === '';
        vide.hidden = propre !== '';
    };
    zone.addEventListener('input', rendre);
    rendre();
})();
</script>
</body>
</html>
