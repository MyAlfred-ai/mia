<?php
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
$consoleGoogle = '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Sources synchronisées') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'knowledge-bases-sync'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <h1><?= te('Sources synchronisées') ?></h1>
    <p style="color:var(--text-muted);font-size:13px;margin:-6px 0 18px;max-width:760px">
        <?= $md(teb('Les identifiants qui permettent à une base de connaissances de refléter automatiquement un dossier {b}Google Drive{/b}, {b}OneDrive{/b} ou {b}SharePoint{/b}, ou un agenda {b}Google Agenda{/b}. Ils sont globaux : une fois renseignés ici, tous les gestionnaires de bases peuvent activer une synchronisation depuis {a}Bases de connaissances{/a}.'), '<a href="/admin/knowledge-bases">') ?>
        <?= $md(teb('Deux logiques cohabitent : Drive (clé API), SharePoint et Agenda lisent des dossiers {i}partagés{/i} ; {b}OneDrive{/b} et le mode « ma connexion » de Drive lisent les dossiers {i}personnels{/i} de l\'utilisateur qui enregistre la base, avec ses propres droits.')) ?>
    </p>

    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="/admin/knowledge-bases/sync/settings" data-autosave>
        <?= Csrf::field() ?>

        <!-- Google Drive -->
        <div class="card" style="margin-bottom:20px">
            <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:8px">
                <img src="/assets/img/google-drive.svg" alt="" width="16" height="14" style="flex-shrink:0">
                Google Drive
            </h3>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
                <?= $md(teb('Lit les dossiers partagés « Tous les utilisateurs disposant du lien ». Créez la clé dans la {a}console Google Cloud{/a} : activez l\'{b}API Google Drive{/b} sur le projet, générez une {b}clé API{/b} et restreignez-la à l\'API Drive — sans restriction « référents HTTP » (les appels viennent du serveur). La clé est stockée chiffrée et n\'est jamais réaffichée.'), $consoleGoogle) ?>
            </p>
            <?php $driveKeySet = !empty($settings['google_drive_api_key_enc']); ?>
            <div class="form-group" style="margin:0;max-width:420px">
                <label><?= te('Clé API Google') ?> <?= $driveKeySet ? '<span style="font-weight:400;color:var(--text-muted)">' . te('(configurée)') . '</span>' : '' ?></label>
                <input type="password" name="google_drive_api_key" data-autosave-reset class="form-input"
                       autocomplete="new-password"
                       placeholder="<?= $driveKeySet ? te('••••••••••••  (laisser vide pour conserver)') : 'AIza…' ?>">
            </div>
            <?php if ($driveKeySet): ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);margin-top:10px;cursor:pointer;width:fit-content">
                    <input type="checkbox" name="remove_google_drive_api_key" data-autosave-reset value="1" style="accent-color:var(--accent)">
                    <?= te('Supprimer la clé (désactive toutes les synchronisations Google Drive)') ?>
                </label>
            <?php endif; ?>
            <div class="sync-test-row">
                <button type="button" class="btn-secondary" style="font-size:13px" id="driveTestBtn"
                        onclick="testSyncConnection('drive')">
                    <?= te('Tester la connexion') ?>
                </button>
                <div class="sync-test-result" id="driveTestResult" role="status" aria-live="polite"></div>
            </div>
        </div>

        <!-- SharePoint -->
        <div class="card" style="margin-bottom:20px">
            <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:8px">
                <img src="/assets/img/sharepoint.svg" alt="" width="16" height="16" style="flex-shrink:0">
                Microsoft SharePoint
            </h3>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
                <?= $md(teb('Lit les dossiers SharePoint via une application {a}Azure{/a} (Microsoft Entra ID → Inscriptions d\'applications) : notez l\'{b}ID de l\'annuaire (tenant){/b} et l\'{b}ID d\'application (client){/b}, créez un {b}secret client{/b} (notez sa valeur immédiatement, surveillez son expiration), puis accordez la permission d\'application {b}Files.Read.All{/b} (Microsoft Graph) avec {b}consentement administrateur{/b}. Le secret est stocké chiffré et n\'est jamais réaffiché.'), '<a href="https://portal.azure.com" target="_blank" rel="noopener">') ?>
            </p>
            <?php $spSet = !empty($settings['sharepoint_tenant_id'])
                && !empty($settings['sharepoint_client_id'])
                && !empty($settings['sharepoint_client_secret_enc']); ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group" style="margin:0">
                    <label><?= te('ID de l\'annuaire (tenant)') ?></label>
                    <input type="text" name="sharepoint_tenant_id" class="form-input"
                           value="<?= htmlspecialchars($settings['sharepoint_tenant_id'] ?? '') ?>"
                           placeholder="00000000-0000-0000-0000-000000000000">
                </div>
                <div class="form-group" style="margin:0">
                    <label><?= te('ID d\'application (client)') ?></label>
                    <input type="text" name="sharepoint_client_id" class="form-input"
                           value="<?= htmlspecialchars($settings['sharepoint_client_id'] ?? '') ?>"
                           placeholder="00000000-0000-0000-0000-000000000000">
                </div>
            </div>
            <div class="form-group" style="margin:10px 0 0;max-width:420px">
                <label><?= te('Secret client') ?> <?= !empty($settings['sharepoint_client_secret_enc']) ? '<span style="font-weight:400;color:var(--text-muted)">' . te('(configuré)') . '</span>' : '' ?></label>
                <input type="password" name="sharepoint_client_secret" data-autosave-reset class="form-input"
                       autocomplete="new-password"
                       placeholder="<?= !empty($settings['sharepoint_client_secret_enc']) ? te('••••••••••••  (laisser vide pour conserver)') : te('Valeur du secret créé dans Azure') ?>">
            </div>
            <?php if ($spSet): ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);margin-top:10px;cursor:pointer;width:fit-content">
                    <input type="checkbox" name="remove_sharepoint_credentials" data-autosave-reset value="1" style="accent-color:var(--accent)">
                    <?= te('Supprimer les identifiants (désactive toutes les synchronisations SharePoint)') ?>
                </label>
            <?php endif; ?>
            <div class="sync-test-row">
                <button type="button" class="btn-secondary" style="font-size:13px" id="sharepointTestBtn"
                        onclick="testSyncConnection('sharepoint')">
                    <?= te('Tester la connexion') ?>
                </button>
                <div class="sync-test-result" id="sharepointTestResult" role="status" aria-live="polite"></div>
            </div>
        </div>

        <!-- Microsoft OneDrive (OAuth délégué : chaque utilisateur connecte SON compte) -->
        <div class="card" style="margin-bottom:20px">
            <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:8px">
                <img src="/assets/img/onedrive.svg" alt="" width="16" height="16" style="flex-shrink:0">
                Microsoft OneDrive
            </h3>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
                <?= teb('Ici, l\'application Azure n\'accède à {b}rien par elle-même{/b} : elle autorise chaque utilisateur à connecter {b}son propre OneDrive{/b}, puis à {b}parcourir ses dossiers{/b} pour en faire la source d\'une base. C\'est donc une application en permissions {b}déléguées{/b} — distinctes de celles de SharePoint ci-dessus, même si la même inscription Azure peut porter les deux.') ?>
                <?= $md(teb('Sans inscription dédiée, OneDrive utilise l\'application de la {b}connexion Microsoft{/b} ({a}Organisation → Authentification{/a}) : il suffit d\'y déclarer la permission déléguée {c}Files.Read.All{/c}. L\'inscription dédiée ci-dessous est {b}facultative{/b} — pour cantonner cette permission à une application à part, ou viser un autre annuaire — et l\'emporte dès que son ID d\'application est saisi.'), '<a href="/admin/sso">') ?>
            </p>
            <?php
            // Application RÉELLEMENT retenue (MicrosoftOAuth) : la dédiée dès
            // que son ID est saisi, sinon celle de la connexion Microsoft.
            $msDedicated = MicrosoftOAuth::usesDedicatedApp();
            $msReady     = MicrosoftOAuth::isConfigured();
            if ($msDedicated && $msReady) {
                [$msTone, $msStatus] = ['ok', $md(teb('OneDrive utilise l\'{b}inscription dédiée{/b} ci-dessous.'))];
            } elseif ($msDedicated) {
                [$msTone, $msStatus] = ['err', $md(teb('{b}Inscription dédiée incomplète{/b} : il manque son secret client. OneDrive reste indisponible tant qu\'il manque — la connexion Microsoft n\'est pas utilisée à sa place.'))];
            } elseif ($msReady) {
                [$msTone, $msStatus] = ['ok', $md(teb('OneDrive utilise l\'application de la {a}connexion Microsoft{/a} et son URI de redirection, déjà déclarée. Vérifiez que {c}Files.Read.All{/c} figure dans ses autorisations d\'API.'), '<a href="/admin/sso">')];
            } else {
                [$msTone, $msStatus] = ['pending', $md(teb('{b}Aucune application Microsoft{/b} : renseignez celle de la {a}connexion Microsoft{/a}, ou une inscription dédiée ci-dessous.'), '<a href="/admin/sso">')];
            }
            ?>
            <div class="sync-test-result visible <?= $msTone ?>" id="onedriveAppStatus" style="margin:0 0 10px">
                <span><?= $msStatus ?></span>
            </div>

            <!-- Marche à suivre : elle vit ICI, sur l'écran où l'on colle les
                 valeurs. Renvoyer au guide obligerait à faire l'aller-retour
                 entre deux fenêtres pendant une manipulation en huit étapes.
                 Ouverte seulement quand AUCUNE application n'est utilisable :
                 avec la connexion Microsoft, l'inscription dédiée est facultative. -->
            <details class="sync-howto"<?= $msReady ? '' : ' open' ?>>
                <summary><?= te('Inscription dédiée (facultative) — marche à suivre, ~5 minutes') ?></summary>
                <ol class="sync-steps">
                    <li><?= $md(teb('Ouvrez le {a}portail Azure → Inscriptions d\'applications{/a}, puis {b}Nouvelle inscription{/b}.'), '<a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">') ?></li>
                    <li><?= teb('Nommez-la (ex. « MIA — OneDrive »). Comptes pris en charge : {b}votre organisation{/b} si vous voulez restreindre l\'accès à vos collaborateurs, sinon laissez le choix par défaut. Puis {b}S\'inscrire{/b}.') ?></li>
                    <li><?= teb('Sur la page {b}Vue d\'ensemble{/b} de l\'application, copiez l\'{b}ID d\'application (client){/b} et — si vous restreignez à votre organisation — l\'{b}ID de l\'annuaire (tenant){/b}, à coller dans les champs ci-dessous.') ?></li>
                    <li>
                        <?= teb('{b}Authentification → Ajouter une plateforme → Web{/b}, et collez cette URI de redirection :') ?>
                        <span class="sync-copy">
                            <code id="msRedirectUri"><?= htmlspecialchars(MicrosoftOAuth::dedicatedRedirectUri()) ?></code>
                            <button type="button" class="btn-secondary" id="msRedirectCopyBtn"><?= te('Copier') ?></button>
                        </span>
                        <em><?= $md(te('C\'est l\'oubli le plus fréquent : sans elle, le consentement échoue avec le code {c}AADSTS50011{/c}.')) ?></em>
                    </li>
                    <li><?= teb('{b}Certificats & secrets → Nouveau secret client{/b} : copiez la {b}valeur{/b} immédiatement (elle n\'est plus réaffichée par Azure) et notez sa {b}date d\'expiration{/b} — il faudra la renouveler ici le moment venu.') ?></li>
                    <li><?= $md(teb('{b}API autorisées → Ajouter une autorisation → Microsoft Graph → Autorisations déléguées{/b}, et cochez {c}Files.Read.All{/c}, {c}User.Read{/c} et {c}offline_access{/c}. Aucune permission d\'écriture : la connexion est en lecture seule.')) ?></li>
                    <li><?= teb('Collez les valeurs dans les champs ci-dessous — l\'enregistrement est automatique — puis cliquez {b}Vérifier la configuration{/b}.') ?></li>
                    <li><?= $md(teb('Chaque personne concernée connecte ensuite {b}son{/b} compte dans {a}Réglages → Comptes connectés → OneDrive{/a}, et choisit son dossier avec {b}Parcourir…{/b} dans la fenêtre d\'une base.'), '<a href="/settings/connections">') ?></li>
                </ol>
            </details>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group" style="margin:0">
                    <label><?= te('ID de l\'annuaire (tenant)') ?> <span style="font-weight:400;color:var(--text-muted)"><?= te('— « common » par défaut') ?></span></label>
                    <input type="text" name="microsoft_tenant_id" class="form-input"
                           value="<?= htmlspecialchars($settings['microsoft_tenant_id'] ?? '') ?>"
                           placeholder="common">
                </div>
                <div class="form-group" style="margin:0">
                    <label><?= te('ID d\'application (client)') ?></label>
                    <input type="text" name="microsoft_client_id" class="form-input"
                           value="<?= htmlspecialchars($settings['microsoft_client_id'] ?? '') ?>"
                           placeholder="00000000-0000-0000-0000-000000000000">
                </div>
            </div>
            <div class="form-group" style="margin:10px 0 0;max-width:420px">
                <label><?= te('Secret client') ?> <?= !empty($settings['microsoft_client_secret_enc']) ? '<span style="font-weight:400;color:var(--text-muted)">' . te('(configuré)') . '</span>' : '' ?></label>
                <input type="password" name="microsoft_client_secret" data-autosave-reset class="form-input"
                       autocomplete="new-password"
                       placeholder="<?= !empty($settings['microsoft_client_secret_enc']) ? te('••••••••••••  (laisser vide pour conserver)') : te('Valeur du secret créé dans Azure') ?>">
            </div>
            <?php if (!empty($settings['microsoft_client_id']) || !empty($settings['microsoft_client_secret_enc'])): ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);margin-top:10px;cursor:pointer;width:fit-content">
                    <input type="checkbox" name="remove_microsoft_credentials" data-autosave-reset value="1" style="accent-color:var(--accent)">
                    <?= te('Supprimer l\'inscription dédiée (OneDrive repassera par la connexion Microsoft si elle est configurée ; chacun devra reconnecter son OneDrive)') ?>
                </label>
            <?php endif; ?>
            <div class="sync-test-row">
                <button type="button" class="btn-secondary" style="font-size:13px" id="onedriveTestBtn"
                        onclick="testSyncConnection('onedrive')">
                    <?= te('Vérifier la configuration') ?>
                </button>
                <div class="sync-test-result" id="onedriveTestResult" role="status" aria-live="polite"></div>
            </div>
        </div>

        <!-- Google Agenda -->
        <div class="card" style="margin-bottom:20px">
            <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:8px">
                <img src="/assets/img/google-calendar.svg" alt="" width="16" height="16" style="flex-shrink:0">
                <?= te('Google Agenda') ?>
            </h3>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
                <?= $md(teb('Lit les agendas rendus {b}publics{/b} (Paramètres de l\'agenda → « Rendre disponible publiquement »), reflétés en documents mensuels dans la base. Créez la clé dans la {a}console Google Cloud{/a} : activez l\'{b}API Google Calendar{/b} sur le projet, générez une {b}clé API{/b} (distincte de la clé Drive ou commune, au choix — l\'important est que l\'API Calendar soit autorisée) — sans restriction « référents HTTP ». La clé est stockée chiffrée et n\'est jamais réaffichée.'), $consoleGoogle) ?>
            </p>
            <?php $gcalKeySet = !empty($settings['google_calendar_api_key_enc']); ?>
            <div class="form-group" style="margin:0;max-width:420px">
                <label><?= te('Clé API Google (Agenda)') ?> <?= $gcalKeySet ? '<span style="font-weight:400;color:var(--text-muted)">' . te('(configurée)') . '</span>' : '' ?></label>
                <input type="password" name="google_calendar_api_key" data-autosave-reset class="form-input"
                       autocomplete="new-password"
                       placeholder="<?= $gcalKeySet ? te('••••••••••••  (laisser vide pour conserver)') : 'AIza…' ?>">
            </div>
            <?php if ($gcalKeySet): ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);margin-top:10px;cursor:pointer;width:fit-content">
                    <input type="checkbox" name="remove_google_calendar_api_key" data-autosave-reset value="1" style="accent-color:var(--accent)">
                    <?= te('Supprimer la clé (désactive toutes les synchronisations Google Agenda)') ?>
                </label>
            <?php endif; ?>
            <div class="sync-test-row">
                <button type="button" class="btn-secondary" style="font-size:13px" id="gcalendarTestBtn"
                        onclick="testSyncConnection('gcalendar')">
                    <?= te('Tester la connexion') ?>
                </button>
                <div class="sync-test-result" id="gcalendarTestResult" role="status" aria-live="polite"></div>
            </div>
        </div>

        <!-- Intervalle commun -->
        <div class="card" style="margin-bottom:24px">
            <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em"><?= te('Rythme') ?></h3>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">
                <?= te('Une base synchronisée est rafraîchie au plus une fois par intervalle. Le cycle démarre à la visite d\'une page, pas à la minute près : c\'est un plancher, pas une horloge.') ?>
            </p>
            <div class="form-group" style="margin:0;max-width:420px">
                <label><?= te('Intervalle entre deux cycles (minutes)') ?> <span style="font-weight:400;color:var(--text-muted)"><?= te('— commun à toutes les sources') ?></span></label>
                <input type="number" name="drive_sync_interval_min" class="form-input" min="1" max="1440"
                       value="<?= (int)($settings['drive_sync_interval_min'] ?? KbSync::DEFAULT_INTERVAL_MIN) ?>">
            </div>
        </div>

        <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
    </form>
</div>

<style>
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

/* Marche à suivre d'un fournisseur : repliable, ouverte par défaut tant que
   la configuration n'est pas faite (l'admin est là pour ça), refermable une
   fois la manipulation derrière soi. */
.sync-howto {
    margin: 0 0 14px; padding: 10px 12px;
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    background: var(--surface);
}
.sync-howto > summary {
    cursor: pointer; font-size: 12px; font-weight: 600;
    color: var(--text); list-style-position: outside;
}
.sync-steps { margin: 10px 0 0; padding-left: 20px; font-size: 12px; color: var(--text-muted) }
.sync-steps > li { margin-bottom: 8px; line-height: 1.5 }
.sync-steps code {
    font-size: 11px; padding: 1px 5px; border-radius: 4px;
    background: var(--bg-surface); border: 1px solid var(--border);
}
.sync-steps em { display: block; margin-top: 4px; font-size: 11px }
.sync-copy { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 6px 0 2px }
.sync-copy code { user-select: all; word-break: break-all }
.sync-copy button { font-size: 11px; padding: 3px 10px }
</style>
<script>
// ---- Test de connexion des fournisseurs de synchronisation ----
// Envoie aussi les valeurs saisies (non enregistrées) : elles priment sur
// celles stockées côté serveur — on teste AVANT d'enregistrer.
const SYNC_TEST_ICONS = {
    ok:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    err: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
};
// Copie de l'URI de redirection : elle doit être recopiée à l'identique dans
// Azure (une faute de frappe = AADSTS50011 au consentement, plusieurs minutes
// plus tard et à l'autre bout du parcours). Repli sur une sélection manuelle
// si le presse-papiers est refusé (page non sécurisée, permission navigateur).
document.getElementById('msRedirectCopyBtn')?.addEventListener('click', async (e) => {
    const code = document.getElementById('msRedirectUri');
    const btn  = e.currentTarget;
    try {
        await navigator.clipboard.writeText(code.textContent.trim());
        btn.textContent = <?= tj('Copié ✓') ?>;
    } catch (err) {
        const range = document.createRange();
        range.selectNodeContents(code);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        btn.textContent = <?= tj('Sélectionnée — Ctrl+C') ?>;
    }
    setTimeout(() => { btn.textContent = <?= tj('Copier') ?>; }, 2500);
});

const SYNC_TEST_FIELDS = {
    drive:      ['google_drive_api_key'],
    sharepoint: ['sharepoint_tenant_id', 'sharepoint_client_id', 'sharepoint_client_secret'],
    gcalendar:  ['google_calendar_api_key'],
};

async function testSyncConnection(provider) {
    const btn  = document.getElementById(provider + 'TestBtn');
    const box  = document.getElementById(provider + 'TestResult');
    const body = new URLSearchParams({ provider });
    (SYNC_TEST_FIELDS[provider] || []).forEach(name => {
        const input = document.querySelector('[name="' + name + '"]');
        if (input && input.value.trim() !== '') body.set(name, input.value.trim());
    });

    btn.disabled  = true;
    box.className = 'sync-test-result pending visible';
    box.innerHTML = '<span class="sync-test-spinner"></span><span></span>';
    box.lastElementChild.textContent = <?= tj('Test de connexion en cours…') ?>;
    try {
        const res  = await fetch('/admin/knowledge-bases/sync/test-credentials', {
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
        box.innerHTML = SYNC_TEST_ICONS[ok ? 'ok' : 'err'] + '<span></span>';
        box.lastElementChild.textContent = ok
            ? <?= tj('Connexion réussie — {detail}') ?>.replace('{detail}', () => data.detail || <?= tj('identifiants opérationnels.') ?>)
            : (data.error || <?= tj('Échec du test — réessayez.') ?>);
    } catch (e) {
        box.className = 'sync-test-result visible err';
        box.innerHTML = SYNC_TEST_ICONS.err + '<span></span>';
        box.lastElementChild.textContent = <?= tj('Serveur injoignable pendant le test — vérifiez votre connexion puis réessayez.') ?>;
    } finally {
        btn.disabled = false;
    }
}
</script>
</body>
</html>
