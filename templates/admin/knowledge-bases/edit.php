<?php
/**
 * Fiche d'une base de connaissances, en page pleine — création comme
 * modification (ex-modale de /admin/knowledge-bases).
 *
 * Attend (AdminController::renderKnowledgeBaseForm) : $kb (null = création),
 * $isCreate, $isLive (cerveau MyBrain), $isSystem (base de l'aide, verrouillée),
 * $isOwned (propriétaire : partage, suppression), $isAdminManager,
 * $currentUser, $manageableProfiles, $shareableUsers, $shareableGroups,
 * $embeddingProviders, $embeddingDefault, $qdrantUp, $syncProviders,
 * $syncInterval, $driveOauthConfigured, $driveOwnerReady, $oneDriveConfigured,
 * $oneDriveOwnerReady, $docs, $linkedIds, $shares, $defaultOn, $canFeed,
 * $syncOwnerName, $kbStatus, $statusPanelHtml, $chunkTotals, $docPayloads,
 * $diagram, $success, $error.
 *
 * Quatre sections, dans l'ordre où l'on pense une base — Identité, Sources
 * (ce qui l'alimente), Assistants liés, Partage —, puis « Avancé », replié :
 * le modèle d'embedding, la réindexation (seul chemin pour en changer) et le
 * détail des extraits vectorisés. À droite, le SCHÉMA de la base, redessiné à
 * chaque geste par public/assets/js/kb-form.js avec diagram.js + kb-diagram.js ;
 * chaque section et chaque réglage porte un id (kbSection*, kbSourcesField,
 * kbDocsBlock, kbEmbeddingField, kbChunksBlock…) : c'est par eux qu'un clic sur
 * un élément du schéma mène au réglage correspondant.
 *
 * Enregistrement automatique en MODIFICATION seulement : créer une base
 * reste un acte volontaire (« Créer la base ») — elle fixe le modèle
 * d'embedding. La connexion d'un cerveau ne part jamais par l'enregistrement
 * automatique (« Tester et enregistrer »), ni le choix d'un nouveau modèle
 * (« Réindexer la base », formulaire à part).
 */
$kbId       = $isCreate ? 0 : (int)$kb['id'];
$kbName     = $isCreate ? '' : (string)$kb['name'];
$kbActive   = $isCreate ? true : !empty($kb['is_active']);
$kbTitle    = $isCreate ? t('Nouvelle base de connaissances') : $kbName;
$formAction = $isCreate ? '/admin/knowledge-bases' : '/admin/knowledge-bases/' . $kbId;
$backUrl    = $isCreate ? '/admin/knowledge-bases' : '/admin/knowledge-bases#base-' . $kbId;
// Lecture seule : la base système (contenu tenu par MIA), ou une base que le
// rôle peut voir sans le droit « Bases de connaissances — Modifier »
// (Personnes → Rôles). La création, elle, demande « Créer » (contrôleur).
$readOnly   = $isSystem || empty($kbCanEdit);
// Réindexer : droit « Modifier » (ouvert aussi sur la base système).
$kbCanReindex = !$isCreate && !empty($kbCanEdit);

// Source enregistrée (modification) : la tuile cochée, l'adresse affichée.
$savedProvider = $isLive ? 'mybrain' : (string)($kb['sync_provider'] ?? '');
$savedFolder   = (string)($kb['sync_folder_ref'] ?? '');
$savedUrl      = $savedFolder === '' ? '' : ($savedProvider === 'drive' ? 'https://drive.google.com/drive/folders/' . $savedFolder : $savedFolder);
$savedAuto     = $isCreate ? true : (int)($kb['sync_enabled'] ?? 0) === 1;
$savedAuth     = (int)($kb['sync_owner_user_id'] ?? 0) > 0 ? 'owner' : 'key';
$driveKeyOk    = !empty($syncProviders['drive']);
// Tuile Drive utilisable dès qu'UN des deux accès est possible : clé API
// globale OU connexion personnelle (client OAuth déclaré).
$driveCardOn   = $driveKeyOk || !empty($driveOauthConfigured);

$uploadIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
$icon = static fn(string $paths): string =>
    '<svg class="profile-section-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
// Une phrase traduite qui porte un lien : la phrase reste ENTIÈRE dans le
// dictionnaire, `{a}…{/a}` y marque le lien, posé APRÈS l'échappement.
$withLink = static fn(string $escaped, string $href): string =>
    str_replace(['{a}', '{/a}'], ['<a href="' . htmlspecialchars($href) . '">', '</a>'], $escaped);
// Une source sans identifiants : une ligne courte sur la tuile — le détail
// au survol (`title`), le remède à un clic pour un administrateur.
$credentialsHint = static fn(string $what): string => '<span title="' . htmlspecialchars($what) . '">'
    . ($isAdminManager
        ? $withLink(te('À configurer dans {a}Sources synchronisées{/a}'), '/admin/knowledge-bases/sync')
        : te('Non configurée — voyez un administrateur'))
    . '</span>';

/*
 * Tuiles de source. `''` = fichiers déposés seulement ; les fournisseurs en
 * ligne ajoutent un dossier tenu en miroir (le dépôt de fichiers reste
 * possible) ; `mybrain` fait de la base un cerveau EN DIRECT. Une base
 * existante ne change pas de nature : un cerveau ne montre que sa tuile, une
 * base locale toutes les autres. La tuile de la source ENREGISTRÉE reste
 * sélectionnable même si ses identifiants globaux ont été retirés (une radio
 * désactivée disparaîtrait du POST : la base serait déconfigurée en silence).
 */
$tiles = [
    '' => [
        'label'  => t('Fichiers déposés'),
        'desc'   => t('Vous ajoutez vos fichiers depuis votre ordinateur.'),
        'icon'   => $uploadIcon,
        'on'     => true,
        'hint'   => '',
    ],
    'drive' => [
        'label'  => KbDiagram::sourceLabel('drive'),
        'desc'   => t('Le miroir d\'un dossier : par lien, avec votre compte ou dans un Drive partagé.'),
        'on'     => $driveCardOn,
        'hint'   => $driveCardOn ? '' : $credentialsHint(t('Ni clé API Google ni connexion Google configurées')),
    ],
    'onedrive' => [
        'label'  => KbDiagram::sourceLabel('onedrive'),
        'desc'   => t('Le miroir d\'un dossier de votre OneDrive, choisi en le parcourant.'),
        'on'     => !empty($oneDriveConfigured),
        'hint'   => empty($oneDriveConfigured)
            ? $credentialsHint(t('Application Microsoft non configurée'))
            : (empty($oneDriveOwnerReady) ? $withLink(te('{a}Connectez votre OneDrive{/a} pour l\'utiliser'), '/settings/connections') : ''),
        'attrs'  => ' data-owner-ready="' . (empty($oneDriveOwnerReady) ? 0 : 1) . '"',
    ],
    'sharepoint' => [
        'label'  => KbDiagram::sourceLabel('sharepoint'),
        'desc'   => t('Le miroir d\'un dossier SharePoint de votre organisation.'),
        'on'     => !empty($syncProviders['sharepoint']),
        'hint'   => empty($syncProviders['sharepoint']) ? $credentialsHint(t('Application Azure non configurée')) : '',
    ],
    'gcalendar' => [
        'label'  => KbDiagram::sourceLabel('gcalendar'),
        'desc'   => t('Un agenda public, en un document par mois.'),
        'on'     => !empty($syncProviders['gcalendar']),
        'hint'   => empty($syncProviders['gcalendar']) ? $credentialsHint(t('Clé API Google (Agenda) non configurée')) : '',
    ],
];

$kbFormJsVersion    = @filemtime(ROOT . '/public/assets/js/kb-form.js') ?: 1;
$diagramJsVersion   = @filemtime(ROOT . '/public/assets/js/diagram.js') ?: 1;
$kbDiagramJsVersion = @filemtime(ROOT . '/public/assets/js/kb-diagram.js') ?: 1;
$acceptDocs = '.pdf,.docx,.xlsx,.pptx,.odt,.ods,.odp,.epub,.html,.htm,.rtf,.txt,.md,.markdown,.csv,.tsv,.json,.xml,.yaml,.yml,.log,.ini,.conf,.php,.js,.ts,.py,.rb,.go,.rs,.java,.c,.cpp,.h,.cs,.sh,.sql,.css,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.oasis.opendocument.text,application/vnd.oasis.opendocument.spreadsheet,application/vnd.oasis.opendocument.presentation,application/epub+zip,text/html,application/rtf,text/plain,text/markdown,image/jpeg,image/png,image/gif,image/webp';

$delConfirm = $isLive
    ? t('Débrancher le cerveau « {nom} » ? Les assistants liés et les conversations qui l\'avaient coché perdront cette source. La clé reste valide dans MyBrain : supprimez-la là-bas si elle ne sert plus.', ['nom' => $kbName])
    : t('Supprimer la base « {nom} » et tous ses documents ? Les assistants liés perdront cette source. Action irréversible.', ['nom' => $kbName]);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= htmlspecialchars($kbTitle) ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'knowledge-bases'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content kb-content">

    <div class="pf-page-head">
        <div class="pf-page-head-text">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="pf-back-link"><?= te('← Toutes les bases') ?></a>
            <h1 style="margin:0"><?= htmlspecialchars($kbTitle) ?></h1>
        </div>
        <div class="mp-active-toggle" id="kbActiveToggle" title="<?= te('Active = interrogée par les assistants liés et proposée dans le chat. Inactive = ignorée partout, sans rien perdre.') ?>">
            <label class="ios-switch">
                <input type="checkbox" name="is_active" id="kbIsActive" value="1" form="editKbForm" <?= $kbActive ? 'checked' : '' ?><?= $readOnly ? ' disabled' : '' ?>>
                <span class="ios-switch-slider"></span>
            </label>
            <span class="mp-active-text<?= $kbActive ? '' : ' is-off' ?>" id="kbActiveText"><?= $kbActive ? te('Active') : te('Inactive') ?></span>
        </div>
    </div>

    <?php if (!empty($success)): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($isSystem): ?>
        <div class="kb-notice" role="note">
            <?= teb('{b}Base système.{/b} Son contenu est tenu à jour par MIA depuis sa documentation, à chaque déploiement : il ne se modifie pas ici. Seule la réindexation reste possible (section « Avancé »).') ?>
        </div>
    <?php elseif ($readOnly && !$isCreate): ?>
        <div class="admin-readonly-banner" role="status"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><span><?= teb('{b}Lecture seule.{/b} Votre rôle vous permet de consulter cette base, pas de la modifier.') ?></span></div>
    <?php endif; ?>
    <?php if (empty($qdrantUp) && !$isLive): ?>
        <div class="alert alert-error" style="margin-bottom:16px">
            <?= te('⚠ Le moteur vectoriel (Qdrant) est indisponible : les recherches passent par les mots, et les nouveaux documents ne seront vectorisés qu\'après son retour.') ?>
            <?php if (!empty($canSeeVector)): ?><a href="/admin/knowledge-bases/vector"><?= te('Voir le moteur vectoriel') ?></a>.<?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="pf-form-layout">
    <?php /* Schéma de la base telle que saisie : rempli par kb-form.js (rien
             sans JavaScript). Avant la fiche dans le document : sur un écran
             étroit, il ouvre la page comme un résumé. */ ?>
    <aside class="sched-diagram-panel is-form pf-diagram-panel" id="kbDiagramPanel" aria-labelledby="kbDiagramTitle" hidden>
        <div class="sched-diagram-head">
            <div class="sched-diagram-head-text">
                <span class="sched-diagram-kicker"><?= te('Aperçu de la base') ?></span>
                <h2 id="kbDiagramTitle"></h2>
            </div>
        </div>
        <p class="sched-diagram-hint"><?= te('Cliquez un élément du schéma pour aller à son réglage.') ?></p>
        <div class="sched-diagram" id="kbDiagram"></div>
    </aside>

    <div class="pf-form-main">
    <?php if (!$isCreate): ?>
        <?php /* État de la base : synchronisation, indexation, échecs, prochain
                 passage — rendu par le serveur (partiel kb-status.php, variante
                 « panel »), rafraîchi par kb-form.js tant que quelque chose
                 tourne. Hors du formulaire : il ne porte aucun champ. */ ?>
        <div id="kbStatusPanel" class="kb-panel-wrap" aria-live="polite"><?= $statusPanelHtml ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($formAction) ?>" id="editKbForm" enctype="multipart/form-data"
          class="kb-form" <?= $isCreate || $readOnly ? '' : 'data-autosave' ?>>
        <?= Csrf::field() ?>
        <fieldset class="kb-fieldset"<?= $readOnly ? ' disabled' : '' ?>>

        <!-- Identité -->
        <section class="profile-section" id="kbSectionIdentity">
            <h4><?= $icon('<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>') ?><?= te('Identité') ?></h4>
            <p class="profile-section-hint"><?= te('Comment la base apparaît dans l\'administration et dans le sélecteur du chat.') ?></p>
            <div class="form-group" style="margin:0">
                <label for="kbName"><?= te('Nom *') ?></label>
                <input type="text" name="name" id="kbName" class="form-input" required maxlength="255"
                       value="<?= htmlspecialchars($kbName) ?>" placeholder="<?= te('Ex. : Documentation produit') ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label for="kbDescription"><?= te('Description') ?></label>
                <input type="text" name="description" id="kbDescription" class="form-input"
                       value="<?= htmlspecialchars($isCreate ? '' : (string)($kb['description'] ?? '')) ?>"
                       placeholder="<?= te('Ex. : Manuels, FAQ et procédures internes') ?>">
                <p class="profile-hint"><?= te('Aide à reconnaître la base. Elle n\'est pas envoyée au modèle.') ?></p>
            </div>
        </section>

        <!-- Sources : ce qui alimente la base -->
        <section class="profile-section" id="kbSectionSources">
            <h4><?= $icon('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>') ?><?= te('Sources') ?></h4>
            <p class="profile-section-hint">
                <?php if ($isLive): ?>
                    <?= te('Un cerveau MyBrain, interrogé en direct à chaque message : rien n\'est copié dans MIA.') ?>
                <?php elseif ($isSystem): ?>
                    <?= te('La documentation de MIA, déposée et tenue à jour par MIA elle-même.') ?>
                <?php else: ?>
                    <?= te('D\'où viennent les documents. Un dossier en ligne est tenu en miroir, toutes les {n} minutes ; vous pouvez toujours déposer des fichiers en plus.', ['n' => (int)$syncInterval]) ?>
                <?php endif; ?>
            </p>

            <?php if (!$isSystem): ?>
            <div class="kb-source-tiles" id="kbSourcesField" role="radiogroup" aria-label="<?= te('Source des documents') ?>">
                <?php if (!$isLive): ?>
                    <?php foreach ($tiles as $value => $tile): ?>
                        <?php
                            $isSaved   = !$isCreate && $value === $savedProvider;
                            $available = $tile['on'] || $isSaved;
                            $logo      = $value === '' ? $tile['icon']
                                : '<img src="' . htmlspecialchars(KbDiagram::SOURCES[$value]['logo']) . '" alt="" width="22" height="22">';
                        ?>
                        <label class="kb-source-tile<?= $available ? '' : ' is-off' ?>" data-provider="<?= htmlspecialchars($value) ?>"
                               data-configured="<?= $tile['on'] ? 1 : 0 ?>"<?= $tile['attrs'] ?? '' ?>>
                            <input type="radio" name="sync_provider" value="<?= htmlspecialchars($value) ?>"
                                   <?= $value === ($isCreate ? '' : $savedProvider) ? 'checked' : '' ?><?= $available ? '' : ' disabled' ?>>
                            <span class="kb-source-tile-logo"><?= $logo ?></span>
                            <span class="kb-source-tile-text">
                                <span class="kb-source-tile-name"><?= htmlspecialchars($tile['label']) ?></span>
                                <span class="kb-source-tile-desc"><?= htmlspecialchars($tile['desc']) ?></span>
                                <?php if ($tile['hint'] !== '' && !$isSaved): ?>
                                    <span class="kb-source-tile-hint"><?= $tile['hint'] ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="kb-source-tile-check" aria-hidden="true"></span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($isAdminManager): ?>
                    <?php /* Pas un miroir : une base EN DIRECT (backend = mybrain). Réservée
                             aux administrateurs — la clé vient de l'administrateur de MyBrain,
                             et la brancher ouvre le wiki de l'équipe à qui recevra la base. */ ?>
                    <?php if ($isCreate || $isLive): ?>
                        <label class="kb-source-tile is-live" data-provider="mybrain" data-configured="1">
                            <input type="radio" name="sync_provider" value="mybrain"<?= $isLive ? ' checked' : '' ?>>
                            <span class="kb-source-tile-logo"><img src="<?= htmlspecialchars(KbDiagram::SOURCES['mybrain']['logo']) ?>" alt="" width="22" height="22"></span>
                            <span class="kb-source-tile-text">
                                <span class="kb-source-tile-name"><?= te('Cerveau MyBrain') ?></span>
                                <span class="kb-source-tile-desc"><?= te('Le wiki de l\'équipe, interrogé en direct : rien n\'est copié dans MIA.') ?></span>
                            </span>
                            <span class="kb-source-tile-check" aria-hidden="true"></span>
                        </label>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($isAdminManager && ($isCreate || $isLive)): ?>
            <!-- Connexion d'un cerveau (tuile « Cerveau MyBrain »). Les deux champs
                 sont HORS de l'enregistrement automatique (data-autosave-skip) : une
                 clé à moitié collée n'a rien à faire en base. En création, « Créer la
                 base » teste puis enregistre ; en modification, « Tester et
                 enregistrer » est le seul chemin. -->
            <div id="kbMyBrainConfig" class="kb-source-config kb-mybrain-config" hidden>
                <p class="profile-section-hint" style="margin:0">
                    <?= teb('MIA interroge le cerveau à chaque message où la base est engagée — cochée dans la conversation ou liée à l\'assistant — et cite ses fiches. Il faut une {b}clé d\'application{/b} créée dans MyBrain (Administration → Clés d\'API) avec le droit {b}lire{/b}. Rien n\'est enregistré tant que le test ne passe pas.') ?>
                </p>
                <div class="form-group" style="margin:0">
                    <label for="kbMyBrainUrl"><?= te('Adresse du MyBrain') ?></label>
                    <input type="url" name="mybrain_url" id="kbMyBrainUrl" class="form-input" data-autosave-skip
                           placeholder="<?= te('https://mybrain.votre-domaine.fr') ?>" autocomplete="off"
                           value="<?= htmlspecialchars($isLive ? (string)($kb['remote_url'] ?? '') : '') ?>">
                    <p class="profile-hint"><?= te('L\'adresse du site MyBrain, pas celle d\'une fiche. https obligatoire, sauf sur le réseau interne du serveur.') ?></p>
                </div>
                <div class="form-group" style="margin:0">
                    <label for="kbMyBrainKey"><?= te('Clé d\'application') ?></label>
                    <input type="password" name="mybrain_key" id="kbMyBrainKey" class="form-input"
                           placeholder="mbk_…" autocomplete="new-password" spellcheck="false" data-autosave-skip>
                    <p class="profile-hint" id="kbMyBrainKeyHint"><?= $isLive && (string)($kb['remote_key_hint'] ?? '') !== ''
                        ? te('Clé enregistrée : {indice} — laissez vide pour la garder.', ['indice' => (string)$kb['remote_key_hint']])
                        : te('Montrée une seule fois par MyBrain, à sa création.') ?></p>
                </div>
                <div class="kb-mybrain-test">
                    <button type="button" class="btn-secondary" id="kbMyBrainTestBtn"><?= $isCreate ? te('Tester la connexion') : te('Tester et enregistrer') ?></button>
                    <span class="kb-mybrain-result" id="kbMyBrainTestResult" role="status" hidden></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$isLive && !$isSystem): ?>
            <!-- Dossier tenu en miroir (masqué pour « Fichiers déposés »). -->
            <div id="kbSyncConfig" class="kb-source-config" hidden>
                <p class="profile-section-hint" id="kbSyncHint" style="margin:0"></p>

                <!-- Drive seulement : par quel accès lire le dossier. « Ma connexion »
                     = la connexion de l'enregistreur, dossier partagé avec lui — aucun
                     partage public. -->
                <div class="form-group" id="kbDriveAuthRow" style="margin:0" hidden
                     data-key-configured="<?= $driveKeyOk ? 1 : 0 ?>"
                     data-owner-ready="<?= empty($driveOwnerReady) ? 0 : 1 ?>">
                    <label><?= te('Accès au dossier') ?></label>
                    <div class="kb-auth-options">
                        <label class="kb-auth-opt<?= $driveKeyOk ? '' : ' is-off' ?>">
                            <input type="radio" name="sync_auth" value="key"
                                   <?= $savedProvider === 'drive' && $savedAuth === 'key' ? 'checked' : '' ?><?= $driveKeyOk ? '' : ' disabled' ?>>
                            <span><?= teb('{b}Clé API globale{/b} — dossier partagé « Tous les utilisateurs disposant du lien ».') ?>
                                <?php if (!$driveKeyOk): ?>
                                    <em class="kb-auth-hint"><?= $isAdminManager
                                        ? $withLink(te('Clé non configurée — {a}Sources synchronisées{/a}.'), '/admin/knowledge-bases/sync')
                                        : te('Clé non configurée.') ?></em>
                                <?php endif; ?>
                            </span>
                        </label>
                        <label class="kb-auth-opt<?= empty($driveOwnerReady) ? ' is-off' : '' ?>">
                            <input type="radio" name="sync_auth" value="owner"
                                   <?= $savedProvider === 'drive' && $savedAuth === 'owner' ? 'checked' : '' ?><?= empty($driveOwnerReady) ? ' disabled' : '' ?>>
                            <span><?= teb('{b}Ma connexion Google Drive{/b} — dossier à vous, partagé avec votre compte ou dans un Drive partagé dont vous êtes membre, aucun partage public.') ?>
                                <?php if (empty($driveOwnerReady)): ?>
                                    <em class="kb-auth-hint"><?= empty($driveOauthConfigured)
                                        ? te('Connexion Google non configurée sur cette instance.')
                                        : $withLink(te('{a}Connectez votre Google Drive{/a} d\'abord.'), '/settings/connections') ?></em>
                                <?php endif; ?>
                            </span>
                        </label>
                    </div>
                    <p class="profile-hint" id="kbAuthOwnerNote" hidden></p>
                </div>

                <!-- OneDrive : aucun accès global n'existe, la connexion personnelle
                     est la seule voie — d'où une note plutôt qu'un choix d'accès. -->
                <p class="profile-hint kb-hint-warn" id="kbOneDriveConnectNote" hidden>
                    <?= $withLink(te('⚠ Votre OneDrive n\'est pas connecté — {a}connectez-le{/a} pour parcourir vos dossiers et enregistrer cette source.'), '/settings/connections') ?>
                </p>

                <div class="form-group" style="margin:0">
                    <label for="kbSyncUrl" id="kbSyncUrlLabel"><?= te('Dossier') ?></label>
                    <div class="kb-folder-row">
                        <input type="text" name="sync_folder_url" id="kbSyncUrl" class="form-input" value="<?= htmlspecialchars($savedUrl) ?>">
                        <button type="button" class="btn-secondary" id="kbSyncBrowseBtn" hidden><?= te('Parcourir…') ?></button>
                        <button type="button" class="btn-secondary" id="kbSyncTestBtn"><?= te('Tester') ?></button>
                    </div>
                    <p class="profile-hint" id="kbSyncPickedName" hidden></p>
                    <!-- Marche à suivre du mode « ma connexion » : elle remplace l'aide
                         « collez le lien », qui ne veut plus rien dire sans lien. -->
                    <p class="profile-hint" id="kbSyncBrowseHelp" hidden></p>
                    <p class="profile-hint" id="kbSyncTestResult" hidden></p>
                    <p class="profile-hint" id="kbSyncUrlHelp">
                        <?= teb('Collez le lien (ou l\'adresse) de la source puis vérifiez l\'accès avec {b}Tester{/b}. Revenir sur « Fichiers déposés » retire le miroir : les documents déjà repris restent, et redeviennent supprimables.') ?>
                    </p>
                </div>

                <!-- Explorateur de dossiers (connexion personnelle Google Drive / OneDrive),
                     déplié sous le champ. C'est un sélecteur : il ne poste RIEN de lui-même,
                     il repose une valeur dans le champ « Dossier » et se replie. -->
                <div class="kb-browse" id="kbBrowse" hidden>
                    <div class="kb-browse-bar">
                        <button type="button" class="btn-secondary kb-browse-up" id="kbBrowseUpBtn" title="<?= te('Dossier parent') ?>" aria-label="<?= te('Dossier parent') ?>">↑</button>
                        <nav class="kb-browse-crumbs" id="kbBrowseCrumbs" aria-label="<?= te('Chemin') ?>"></nav>
                        <button type="button" class="kb-browse-close" id="kbBrowseCloseBtn" aria-label="<?= te('Fermer l\'explorateur') ?>" title="<?= te('Fermer') ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="kb-browse-body" id="kbBrowseBody"><p class="kb-browse-empty"><?= te('Chargement…') ?></p></div>
                    <div class="kb-browse-foot">
                        <span class="kb-browse-count" id="kbBrowseCount"></span>
                        <button type="button" class="btn-secondary" id="kbBrowseCancelBtn"><?= te('Annuler') ?></button>
                        <button type="button" class="btn-primary" id="kbBrowsePickBtn" disabled><?= te('Choisir ce dossier') ?></button>
                    </div>
                </div>

                <label class="kb-check-line">
                    <input type="checkbox" name="sync_enabled" id="kbSyncEnabled" value="1" <?= $savedAuto ? 'checked' : '' ?>>
                    <?= te('Synchronisation automatique, toutes les {n} minutes', ['n' => (int)$syncInterval]) ?>
                </label>
                <p class="profile-hint kb-hint-warn" id="kbSyncSwitchWarn" hidden>
                    <?= te('⚠ Changement de source : les documents repris de l\'ancienne source seront retirés de la base au premier passage de la nouvelle.') ?>
                </p>
            </div>
            <?php endif; ?>

            <?php if (!$isLive): ?>
            <!-- Documents de la base : dépôt, en complément d'un miroir ou seul. -->
            <div id="kbDocsBlock" class="kb-docs-block">
                <div class="kb-docs-head">
                    <span class="kb-docs-title"><?= te('Documents') ?></span>
                    <span class="kb-docs-count" id="kbDocsCount"></span>
                </div>
                <?php if (!$readOnly): ?>
                <label class="kb-drop" id="kbDrop">
                    <?php if ($isCreate): ?>
                        <input type="file" name="documents[]" id="kbDocsCreateInput" multiple accept="<?= htmlspecialchars($acceptDocs) ?>">
                    <?php else: ?>
                        <input type="file" id="kbDocFileInput" multiple accept="<?= htmlspecialchars($acceptDocs) ?>">
                    <?php endif; ?>
                    <span class="kb-drop-ico" aria-hidden="true"><?= $uploadIcon ?></span>
                    <span class="kb-drop-text">
                        <?= teb('{b}Déposez vos fichiers ici{/b} ou cliquez pour les choisir') ?>
                        <span class="kb-drop-sub"><?= $isCreate
                            ? te('PDF, Word, Excel, PowerPoint, OpenDocument, EPUB, HTML, texte, Markdown, CSV, JSON, code, images — {n} fichiers déposés au plus, 20 Mo chacun. Les images servent aussi de modèles à l\'outil image des assistants.', ['n' => (int)KnowledgeBase::MAX_DOCS_PER_KB])
                            : te('PDF, Word, Excel, PowerPoint, OpenDocument, EPUB, HTML, texte, Markdown, CSV, JSON, code, images — {n} fichiers déposés au plus, 20 Mo chacun ; {m} repris d\'un dossier, 50 Mo chacun. Les images servent aussi de modèles à l\'outil image des assistants.', ['n' => (int)KnowledgeBase::MAX_DOCS_PER_KB, 'm' => (int)KbSync::MAX_DOCS_PER_SYNCED_KB]) ?></span>
                    </span>
                </label>
                <?php endif; ?>
                <p class="profile-doc-error" id="kbDocError" hidden></p>
                <?php if ($isCreate): ?>
                    <ul class="kb-pending-files" id="kbPendingFiles" hidden></ul>
                    <p class="profile-hint"><?= te('Les fichiers sont lus puis vectorisés dès la création de la base.') ?></p>
                <?php else: ?>
                    <div class="kb-docs-toolbar" id="kbDocsToolbar" hidden></div>
                    <div id="kbDocumentsList" class="kb-docs-list"></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- Assistants liés -->
        <section class="profile-section" id="kbSectionAssistants">
            <h4><?= $icon('<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/>') ?><?= te('Assistants liés') ?><span class="kb-linked-count" id="kbLinkedCount"></span></h4>
            <p class="profile-section-hint"><?= te('Ils interrogent la base à chaque message. Une base peut servir plusieurs assistants, et se coche aussi dans le chat.') ?></p>
            <?php if (empty($manageableProfiles)): ?>
                <p class="ug-empty-list"><?= te('Aucun assistant dans votre périmètre. Créez d\'abord un assistant.') ?></p>
            <?php else: ?>
                <div class="kb-linked-toolbar">
                    <input type="text" class="form-input ug-search" id="kbAssistantSearch" placeholder="<?= te('Rechercher un assistant…') ?>" autocomplete="off" aria-label="<?= te('Rechercher un assistant') ?>">
                    <span class="ug-access-tools">
                        <button type="button" class="ug-mini-link" data-check-all="kbAssistantList"><?= te('Tout') ?></button>
                        <button type="button" class="ug-mini-link" data-check-none="kbAssistantList"><?= te('Aucun') ?></button>
                    </span>
                </div>
                <div class="ug-check-list kb-pick-list" id="kbAssistantList">
                    <?php foreach ($manageableProfiles as $mp): ?>
                        <?php
                            $mpColor  = (string)($mp['color'] ?? '');
                            $mpColor  = preg_match('/^#[0-9a-fA-F]{6}$/', $mpColor) === 1 ? $mpColor : '#64748b';
                            $mpAvatar = AssistantDiagram::avatarUrl((string)($mp['avatar'] ?? ''));
                            $mpName   = trim((string)$mp['name']);
                        ?>
                        <label class="ug-check-item" data-search="<?= htmlspecialchars(mb_strtolower($mpName)) ?>">
                            <input type="checkbox" name="profile_ids[]" value="<?= (int)$mp['id'] ?>"
                                   <?= in_array((int)$mp['id'], $linkedIds, true) ? 'checked' : '' ?>>
                            <?php if ($mpAvatar !== ''): ?>
                                <span class="kb-pick-ava"><img src="<?= htmlspecialchars($mpAvatar) ?>" alt="" loading="lazy"></span>
                            <?php else: ?>
                                <span class="kb-pick-ava is-dot" style="background:<?= htmlspecialchars($mpColor) ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($mpName, 0, 1))) ?></span>
                            <?php endif; ?>
                            <span class="ug-member-id"><span class="ug-member-name"><?= htmlspecialchars($mpName) ?></span></span>
                            <?php if (empty($mp['is_active'])): ?><span class="badge badge-red"><?= te('inactif') ?></span><?php endif; ?>
                            <?php if (!empty($mp['is_personal'])): ?><span class="badge badge-personal"><?= te('personnel') ?></span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="ug-empty-search" id="kbAssistantEmpty" hidden><?= te('Aucun assistant ne correspond.') ?></p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Partage — propriétaire seulement (créateur ou administrateur) -->
        <section class="profile-section" id="kbSectionSharing">
            <h4><?= $icon('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>') ?><?= te('Partage') ?></h4>
            <?php if ($isSystem): ?>
                <p class="profile-section-hint">
                    <?= te('La base de l\'assistant d\'aide sert tout le monde : son partage ne se règle pas.') ?>
                </p>
            <?php elseif (!$isOwned): ?>
                <p class="profile-section-hint">
                    <?= te('Cette base vous est partagée en modification par {nom} : son partage et sa suppression restent à lui.', ['nom' => (string)($kb['creator_name'] ?? t('son propriétaire'))]) ?>
                </p>
            <?php else: ?>
                <p class="profile-section-hint" id="kbShareHintLocal"<?= $isLive ? ' hidden' : '' ?>>
                    <?= teb('Par défaut, seuls vous et les administrateurs voyez cette base. Les personnes et groupes cochés la voient aussi : ils la cochent dans le chat et la rattachent à leurs assistants. Cochez {b}peut modifier{/b} pour leur confier aussi les documents ; la suppression et ce partage restent à vous.') ?>
                </p>
                <p class="profile-section-hint" id="kbShareHintLive"<?= $isLive ? '' : ' hidden' ?>>
                    <?= teb('{b}Qui peut interroger le cerveau.{/b} Sans partage, seuls les administrateurs le voient. Les membres des groupes cochés le trouvent dans le sélecteur de bases du chat — et c\'est vérifié à chaque message, même à travers un assistant lié : un assistant ouvert à d\'autres groupes ne leur ouvre pas le cerveau. {b}Cochée par défaut{/b} : la base est déjà cochée dans chaque nouvelle conversation de ces personnes, qui peuvent la décocher. {b}Peut nourrir{/b} : ces personnes peuvent proposer un texte au cerveau depuis une conversation — après leur approbation, il part dans la revue de MyBrain, où il est validé. Il faut aussi que la clé ait le droit « nourrir ».') ?>
                </p>

                <?php if (!empty($shareableGroups)): ?>
                    <div class="kb-share-block">
                        <span class="kb-share-label"><?= te('Groupes') ?></span>
                        <div class="ug-check-list" id="kbShareGroupList">
                            <?php foreach ($shareableGroups as $sg): ?>
                                <?php
                                    $gid   = (int)$sg['id'];
                                    $gOn   = array_key_exists($gid, $shares['groups']);
                                    $gMng  = $gOn && (int)$shares['groups'][$gid] === 1;
                                    $gDef  = $gOn && in_array($gid, array_map('intval', $defaultOn['groups'] ?? []), true);
                                    $gFeed = $gOn && in_array($gid, array_map('intval', $canFeed['groups'] ?? []), true);
                                ?>
                                <label class="ug-check-item kb-share-item<?= $gOn ? ' is-shared-on' : '' ?>">
                                    <input type="checkbox" name="share_group_ids[]" value="<?= $gid ?>" data-share-target="group"<?= $gOn ? ' checked' : '' ?>>
                                    <span class="ug-member-id"><span class="ug-member-name"><?= htmlspecialchars((string)$sg['name']) ?></span></span>
                                    <?php if (empty($sg['is_active'])): ?><span class="badge badge-red"><?= te('inactif') ?></span><?php endif; ?>
                                    <span class="kb-share-manage"<?= $isLive ? ' hidden' : '' ?>>
                                        <input type="checkbox" name="share_group_manage[]" value="<?= $gid ?>"<?= $gMng ? ' checked' : '' ?><?= $gOn && !$isLive ? '' : ' disabled' ?>>
                                        <?= te('peut modifier') ?>
                                    </span>
                                    <span class="kb-share-default"<?= $isLive ? '' : ' hidden' ?>>
                                        <input type="checkbox" name="share_group_default[]" value="<?= $gid ?>"<?= $gDef ? ' checked' : '' ?><?= $gOn && $isLive ? '' : ' disabled' ?>>
                                        <?= te('cochée par défaut') ?>
                                    </span>
                                    <span class="kb-share-feed"<?= $isLive ? '' : ' hidden' ?>>
                                        <input type="checkbox" name="share_group_feed[]" value="<?= $gid ?>"<?= $gFeed ? ' checked' : '' ?><?= $gOn && $isLive ? '' : ' disabled' ?>>
                                        <?= te('peut nourrir') ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="profile-hint"><?= te('Partager à un groupe évite de repartager à chaque nouvel arrivant.') ?></p>
                    </div>
                <?php endif; ?>

                <div class="kb-share-block">
                    <span class="kb-share-label"><?= te('Personnes') ?></span>
                    <?php if (empty($shareableUsers)): ?>
                        <p class="ug-empty-list"><?= te('Aucun autre gestionnaire d\'assistants à qui partager.') ?></p>
                    <?php else: ?>
                        <div class="kb-linked-toolbar">
                            <input type="text" class="form-input ug-search" id="kbShareUserSearch" placeholder="<?= te('Rechercher une personne…') ?>" autocomplete="off" aria-label="<?= te('Rechercher une personne') ?>">
                        </div>
                        <div class="ug-check-list" id="kbShareUserList">
                            <?php foreach ($shareableUsers as $su): ?>
                                <?php
                                    $uid   = (int)$su['id'];
                                    $uOn   = array_key_exists($uid, $shares['users']);
                                    $uMng  = $uOn && (int)$shares['users'][$uid] === 1;
                                    $uDef  = $uOn && in_array($uid, array_map('intval', $defaultOn['users'] ?? []), true);
                                    $uFeed = $uOn && in_array($uid, array_map('intval', $canFeed['users'] ?? []), true);
                                ?>
                                <label class="ug-check-item kb-share-item<?= $uOn ? ' is-shared-on' : '' ?>" data-search="<?= htmlspecialchars(mb_strtolower((string)$su['name'] . ' ' . (string)$su['email'])) ?>">
                                    <input type="checkbox" name="share_user_ids[]" value="<?= $uid ?>" data-share-target="user"<?= $uOn ? ' checked' : '' ?>>
                                    <span class="ug-member-id">
                                        <span class="ug-member-name"><?= htmlspecialchars((string)$su['name']) ?></span>
                                        <span class="ug-member-email"><?= htmlspecialchars((string)$su['email']) ?></span>
                                    </span>
                                    <?php if (($su['role'] ?? '') === 'admin'): ?><span class="badge badge-gray" title="<?= te('Les administrateurs voient déjà toutes les bases') ?>"><?= te('admin') ?></span><?php endif; ?>
                                    <span class="kb-share-manage"<?= $isLive ? ' hidden' : '' ?>>
                                        <input type="checkbox" name="share_user_manage[]" value="<?= $uid ?>"<?= $uMng ? ' checked' : '' ?><?= $uOn && !$isLive ? '' : ' disabled' ?>>
                                        <?= te('peut modifier') ?>
                                    </span>
                                    <span class="kb-share-default"<?= $isLive ? '' : ' hidden' ?>>
                                        <input type="checkbox" name="share_user_default[]" value="<?= $uid ?>"<?= $uDef ? ' checked' : '' ?><?= $uOn && $isLive ? '' : ' disabled' ?>>
                                        <?= te('cochée par défaut') ?>
                                    </span>
                                    <span class="kb-share-feed"<?= $isLive ? '' : ' hidden' ?>>
                                        <input type="checkbox" name="share_user_feed[]" value="<?= $uid ?>"<?= $uFeed ? ' checked' : '' ?><?= $uOn && $isLive ? '' : ' disabled' ?>>
                                        <?= te('peut nourrir') ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <p class="ug-empty-search" id="kbShareUserEmpty" hidden><?= te('Personne ne correspond.') ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        </fieldset>
    </form>

    <?php if (!$isLive): ?>
    <!-- Avancé : le modèle d'embedding, la réindexation (seul chemin pour en
         changer) et le détail des extraits vectorisés. Replié par défaut ;
         ouvert par l'adresse #avance (retour d'une réindexation) ou depuis le
         schéma. -->
    <details class="profile-section kb-advanced" id="avance">
        <summary class="kb-advanced-summary">
            <span class="kb-advanced-title"><?= $icon('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>') ?><?= te('Avancé') ?></span>
            <span class="kb-advanced-sub"><?= $isCreate ? te('Modèle d\'embedding') : te('Modèle d\'embedding, réindexation et extraits vectorisés') ?></span>
            <span class="kb-advanced-caret" aria-hidden="true"></span>
        </summary>

        <div class="kb-adv-block" id="kbEmbeddingField">
            <h5 class="kb-adv-title"><?= te('Modèle d\'embedding') ?></h5>
            <?php if ($isCreate): ?>
                <?php if (empty($embeddingProviders)): ?>
                    <p class="kb-adv-warn"><?= te('Aucune clé d\'embedding n\'est configurée (OpenAI, Mistral ou Gemini) : une base de documents ne peut pas être créée.') ?></p>
                <?php else: ?>
                    <?php /* Rattaché au formulaire de la fiche par form= : il vit ici, en bas, mais part avec « Créer la base ». */ ?>
                    <select name="embedding_choice" id="kbEmbeddingChoice" class="form-select" form="editKbForm">
                        <?php foreach ($embeddingProviders as $prov => $entry): ?>
                            <?php foreach (($entry['models'] ?? []) as $modelId => $m): ?>
                                <option value="<?= htmlspecialchars($prov . '::' . $modelId) ?>"
                                    <?= ($embeddingDefault && $embeddingDefault['provider'] === $prov && $embeddingDefault['model'] === $modelId) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['label'] ?? ($prov . ' / ' . $modelId)) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                    <p class="profile-hint"><?= te('Il convertit les textes en vecteurs pour la recherche par le sens. Le modèle proposé convient presque toujours ; il se change plus tard, en réindexant la base.') ?></p>
                <?php endif; ?>
            <?php else: ?>
                <?php
                    $embProvider = (string)$kb['embedding_provider'];
                    $embModel    = (string)$kb['embedding_model'];
                    $embCurrent  = $embProvider . '::' . $embModel;
                    $embLabel    = EmbeddingService::label($embProvider, $embModel);
                    $embLogo     = LLMFactory::logos()[$embProvider] ?? '';
                    // Les dimensions sont dites sur la ligne d'en dessous.
                    $embName     = KbDiagram::embeddingLabel($embProvider, $embModel);
                ?>
                <div class="kb-emb-current">
                    <?php if ($embLogo !== ''): ?><img src="<?= htmlspecialchars($embLogo) ?>" alt="" width="28" height="28"><?php endif; ?>
                    <span class="kb-emb-current-text">
                        <strong><?= htmlspecialchars($embName) ?></strong>
                        <span><?= htmlspecialchars(LLMFactory::providerLabel($embProvider)) ?> · <?= str_replace('{collection}', '<code>' . htmlspecialchars(KnowledgeBase::collectionName($kbId)) . '</code>', te('{n} dimensions · collection {collection}', ['n' => Format::credits((int)$kb['embedding_dims'])])) ?></span>
                    </span>
                </div>
                <?php if ($kbCanReindex): ?>
                <form method="POST" action="/admin/knowledge-bases/<?= $kbId ?>/reindex" id="kbReindexForm" class="kb-reindex">
                    <?= Csrf::field() ?>
                    <label for="kbReindexChoice"><?= te('Réindexer avec') ?></label>
                    <div class="kb-reindex-row">
                        <?php if (!empty($embeddingProviders)): ?>
                            <select name="embedding_choice" id="kbReindexChoice" class="form-select"
                                    data-current="<?= htmlspecialchars($embCurrent) ?>" data-current-label="<?= htmlspecialchars($embLabel) ?>">
                                <?php foreach ($embeddingProviders as $prov => $entry): ?>
                                    <?php foreach (($entry['models'] ?? []) as $modelId => $m): ?>
                                        <?php $optValue = $prov . '::' . $modelId; ?>
                                        <option value="<?= htmlspecialchars($optValue) ?>"<?= $optValue === $embCurrent ? ' selected' : '' ?>
                                            <?= ($embeddingDefault && $embeddingDefault['provider'] === $prov && $embeddingDefault['model'] === $modelId) ? ' data-default="1"' : '' ?>>
                                            <?= htmlspecialchars($m['label'] ?? ($prov . ' / ' . $modelId)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="btn-secondary" id="kbReindexBtn"><?= te('Réindexer la base') ?></button>
                    </div>
                    <p class="profile-hint" id="kbReindexHint">
                        <?php if (empty($embeddingProviders)): ?>
                            <?= te('Aucune clé d\'embedding n\'est configurée : la réindexation échouera tant qu\'une clé OpenAI, Mistral ou Gemini n\'est pas enregistrée dans Modèles IA → Clés API.') ?>
                        <?php else: ?>
                            <?= te('Tous les documents sont re-vectorisés à partir de leurs textes déjà extraits : rien n\'est re-téléchargé. Pendant ce temps, la base reste interrogeable par les mots. Le coût est compté, hors quota.') ?>
                        <?php endif; ?>
                    </p>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (!$isCreate && $chunkTotals !== null): ?>
            <?php
                $docTotal   = count($docs);
                $docIndexed = count(array_filter($docs, static fn(array $d): bool => KbStatus::documentState($d)['key'] === KbStatus::DOC_INDEXED));
                $chunkAvg   = $chunkTotals['chunks'] > 0 ? (int)round($chunkTotals['tokens'] / $chunkTotals['chunks']) : 0;
                $nf         = static fn(int $n): string => Format::credits($n);
            ?>
            <div class="kb-adv-block" id="kbChunksBlock" data-kb-id="<?= $kbId ?>">
                <h5 class="kb-adv-title"><?= te('Extraits vectorisés') ?></h5>
                <p class="profile-hint" style="margin:-4px 0 0">
                    <?= te('Chaque document est découpé en extraits ; ce sont eux que la recherche retrouve et confie au modèle. Voici ce qui est en base de données, et si chaque extrait a son vecteur.') ?>
                </p>
                <div class="kb-chunk-stats">
                    <div class="kb-chunk-stat"><strong><?= $nf($chunkTotals['chunks']) ?></strong><span><?= htmlspecialchars(I18n::plural((int)$chunkTotals['chunks'], 'extrait', 'extraits')) ?></span></div>
                    <div class="kb-chunk-stat"><strong><?= $nf($chunkTotals['tokens']) ?></strong><span><?= te('tokens') ?></span></div>
                    <div class="kb-chunk-stat"><strong><?= $nf($chunkAvg) ?></strong><span><?= te('tokens par extrait') ?></span></div>
                    <div class="kb-chunk-stat"><strong><?= $docIndexed ?> / <?= $docTotal ?></strong><span><?= te('documents indexés') ?></span></div>
                </div>
                <?php if ($chunkTotals['chunks'] > 0): ?>
                    <div class="kb-chunk-tools">
                        <select id="kbChunkDoc" class="form-select" aria-label="<?= te('Document') ?>">
                            <option value=""><?= te('Tous les documents') ?></option>
                            <?php foreach ($docs as $d): ?>
                                <?php if ((int)($d['chunk_count'] ?? 0) > 0): ?>
                                    <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars((string)$d['file_name']) ?> (<?= (int)$d['chunk_count'] ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <input type="search" id="kbChunkSearch" class="form-input" placeholder="<?= te('Chercher dans les extraits…') ?>" autocomplete="off" aria-label="<?= te('Chercher dans les extraits') ?>">
                    </div>
                    <div class="kb-chunk-list" id="kbChunkList" aria-live="polite"></div>
                    <div class="kb-chunk-pager" id="kbChunkPager" hidden>
                        <button type="button" class="btn-secondary" id="kbChunkPrev"><?= te('← Précédents') ?></button>
                        <span class="kb-chunk-page" id="kbChunkPage"></span>
                        <button type="button" class="btn-secondary" id="kbChunkNext"><?= te('Suivants →') ?></button>
                    </div>
                <?php else: ?>
                    <p class="kb-chunk-empty"><?= te('Aucun extrait pour l\'instant : déposez des documents, ou attendez la fin de leur lecture.') ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <!-- Barre d'actions -->
    <div class="card pf-actions-bar">
        <?php if (!$isCreate && $isOwned && !$isSystem && !empty($kbCanDelete)): ?>
            <button type="button" class="btn-danger-link" id="kbDeleteBtn" data-confirm="<?= htmlspecialchars($delConfirm) ?>">
                <?= $isLive ? te('Débrancher ce cerveau') : te('Supprimer cette base') ?>
            </button>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <div class="pf-actions-right">
            <?php if ($isCreate): ?>
                <a href="/admin/knowledge-bases" class="btn-secondary"><?= te('Annuler') ?></a>
                <button type="submit" class="btn-primary" id="kbSaveBtn" form="editKbForm"><?= te('Créer la base') ?></button>
            <?php else: ?>
                <?php if (!$readOnly): ?>
                    <span class="pf-actions-note" id="kbAutosaveHint"><?php require ROOT . '/templates/partials/autosave-hint.php'; ?></span>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($backUrl) ?>" class="btn-secondary"><?= te('Retour à la liste') ?></a>
            <?php endif; ?>
        </div>
    </div>
    </div>
    </div>

    <?php if (!$isCreate && $isOwned && !$isSystem && !empty($kbCanDelete)): ?>
        <form method="POST" action="/admin/knowledge-bases/<?= $kbId ?>/delete" id="kbDeleteForm" hidden><?= Csrf::field() ?></form>
    <?php endif; ?>
</div>

<script>
// Fiche d'une base (kb-form.js) : état de départ et schéma — catalogue
// calculé par le serveur (KbDiagram), dessiné par diagram.js + kb-diagram.js.
window.KB_FORM = <?= json_encode([
    'id'          => $kbId,
    'create'      => $isCreate,
    'live'        => $isLive,
    'readOnly'    => $readOnly,
    'owned'       => $isOwned,
    'userName'    => (string)($currentUser['name'] ?? ''),
    'source'      => [
        'provider' => $savedProvider,
        'url'      => $savedUrl,
        'auto'     => $savedAuto,
        'auth'     => $savedAuth,
        'owner'    => $syncOwnerName,
        'folder'   => (string)($kb['sync_folder_name'] ?? ''),
    ],
    'docs'        => array_values($docPayloads),
    'pollBusyMs'  => 2500,
    'diagram'     => $diagram,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= (int)$diagramJsVersion ?>"></script>
<script src="/assets/js/kb-diagram.js?v=<?= (int)$kbDiagramJsVersion ?>"></script>
<script src="/assets/js/kb-form.js?v=<?= (int)$kbFormJsVersion ?>"></script>
</body>
</html>
