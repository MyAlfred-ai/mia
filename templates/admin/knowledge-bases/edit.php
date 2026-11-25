<?php
/**
 * Fiche d'une base de connaissances, en page pleine — création comme
 * modification (ex-modale de /admin/knowledge-bases).
 *
 * Attend (AdminController::renderKnowledgeBaseForm) : $kb (null = création),
 * $isCreate, $isLive (cerveau MyBrain), $isSystem (base de l'aide, verrouillée),
 * $isOwned (propriétaire : partage, suppression), $isCreator (seul à poser
 * « Uniquement pour moi »), $isPrivate (base réservée à son créateur),
 * $isAdminManager,
 * $currentUser, $manageableProfiles, $shareableUsers, $shareableGroups,
 * $embeddingProviders, $embeddingDefault, $qdrantUp, $syncProviders,
 * $syncInterval, $driveOauthConfigured, $driveOwnerReady, $oneDriveConfigured,
 * $oneDriveOwnerReady, $docs, $linkedIds, $shares, $defaultOn, $canFeed,
 * $syncOwnerName, $kbStatus, $statusPanelHtml, $chunkTotals, $docPayloads,
 * $success, $error ; pour une TABLE permanente : $isTable, $tableView
 * (KbTableView::section), $tableMiaWrite.
 *
 * Piste D : UNE vue pour créer et pour modifier. Une barre du haut collante
 * (retour, titre, enregistrement ou « Créer »), un menu des sections à
 * gauche, puis une carte par section :
 *   Identité (nom, description, active) · Documents (état, source, dépôt,
 *   documents) — ou Lignes pour une table · Qui la voit (Privée, Commune,
 *   Publique, partage) · Assistants (et ce que MIA peut faire d'une table) ·
 *   Avancé, replié (modèle d'embedding, réindexation, extraits vectorisés) ·
 *   Vue d'ensemble : le schéma de la base (KbDiagram), recomposé à chaque
 *   réglage, dont chaque nœud mène à sa section.
 * Un clic sur un document ouvre sa FICHE dans un volet, à droite
 * (#kbDocDrawer, partials/doc-drawer.php + kb-doc.js, le même que la page de
 * la base) : étapes, pourquoi il pose problème, nouvel essai ou exclusion,
 * extraits.
 *
 * Une ligne d'aide au plus par réglage ; le détail est dans une infobulle
 * (`$tip`, survol, focus clavier ou toucher). Seules les sources CONFIGURÉES
 * sont proposées (plus celle de la base) : les autres sont comptées en une
 * ligne. Partage et assistants liés s'affichent en pastilles ; les listes à
 * cocher, inchangées (ce sont elles qui partent dans le POST), se déplient
 * sous « Ajouter » / « Lier un assistant » (kb-form.js).
 *
 * Enregistrement automatique en MODIFICATION seulement : créer une base
 * reste un acte volontaire (« Créer la base ») — elle fixe le modèle
 * d'embedding. La connexion d'un cerveau ne part jamais par l'enregistrement
 * automatique (« Tester et enregistrer »), ni le choix d'un nouveau modèle
 * (« Réindexer la base », formulaire à part).
 *
 * Une TABLE permanente (nature « table ») garde les questions 1 et 3 ; sa
 * question 2 montre ses LIGNES (partiel table-section.php, kb-table.js) à la
 * place des sources et des documents, et la 3 règle en plus ce que MIA peut y
 * faire. Ni « Avancé », ni volet d'extraits : rien n'y est vectorisé.
 */
$isTable = !empty($isTable);
// Ni documents, ni vectorisation : un cerveau en direct, ou une table.
$noDocs  = $isLive || $isTable;
$kbId       = $isCreate ? 0 : (int)$kb['id'];
$kbName     = $isCreate ? '' : (string)$kb['name'];
$kbActive   = $isCreate ? true : !empty($kb['is_active']);
$kbTitle    = $isCreate ? t('Nouvelle base de connaissances') : $kbName;
$formAction = $isCreate ? '/admin/knowledge-bases' : '/admin/knowledge-bases/' . $kbId;
// Retour : la liste en création, la page de consultation de la base sinon.
$backUrl    = $isCreate ? '/admin/knowledge-bases' : '/admin/knowledge-bases/' . $kbId;
$kbKicker   = $isCreate
    ? ($isTable ? t('Nouvelle table') : t('Nouvelle base'))
    : ($isTable ? t('Modifier la table') : ($isLive ? t('Modifier le cerveau') : t('Modifier la base')));
// Lecture seule : la base système (contenu tenu par MIA), ou une base que le
// rôle peut voir sans le droit « Bases de connaissances — Modifier »
// (Personnes → Rôles). La création, elle, demande « Créer » (contrôleur).
$readOnly   = $isSystem || empty($kbCanEdit);
// Réindexer : droit « Modifier » (ouvert aussi sur la base système).
$kbCanReindex = !$isCreate && !empty($kbCanEdit);
$kbCanDrop    = !$isCreate && $isOwned && !$isSystem && !empty($kbCanDelete);

// Source enregistrée (modification) : la source cochée, l'adresse affichée.
$savedProvider = $isLive ? 'mybrain' : (string)($kb['sync_provider'] ?? '');
$savedFolder   = (string)($kb['sync_folder_ref'] ?? '');
$savedUrl      = $savedFolder === '' ? '' : ($savedProvider === 'drive' ? 'https://drive.google.com/drive/folders/' . $savedFolder : $savedFolder);
$savedAuto     = $isCreate ? true : (int)($kb['sync_enabled'] ?? 0) === 1;
$savedAuth     = (int)($kb['sync_owner_user_id'] ?? 0) > 0 ? 'owner' : 'key';
$driveKeyOk    = !empty($syncProviders['drive']);
// Source Drive utilisable dès qu'UN des deux accès est possible : clé API
// globale OU connexion personnelle (client OAuth déclaré).
$driveCardOn   = $driveKeyOk || !empty($driveOauthConfigured);

$uploadIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
// Une phrase traduite qui porte un lien : la phrase reste ENTIÈRE dans le
// dictionnaire, `{a}…{/a}` y marque le lien, posé APRÈS l'échappement.
$withLink = static fn(string $escaped, string $href): string =>
    str_replace(['{a}', '{/a}'], ['<a href="' . htmlspecialchars($href) . '">', '</a>'], $escaped);
// Une phrase à emphase lue en infobulle : le texte, sans ses marques.
$plain = static fn(string $text): string => str_replace(['{b}', '{/b}'], '', $text);
// Infobulle : le détail d'un réglage, au survol, au focus clavier ou au
// toucher (le point est focalisable). `$end` l'ouvre vers la gauche, pour un
// point placé au bout d'une ligne ; `$id` le désigne au script qui le remplit.
$tip = static fn(string $text, bool $end = false, string $id = ''): string =>
    '<span class="kbf-tip' . ($end ? ' is-end' : '') . '"' . ($id !== '' ? ' id="' . htmlspecialchars($id) . '"' : '')
    . ' tabindex="0" role="img" aria-label="' . htmlspecialchars($text)
    . '" data-tip="' . htmlspecialchars($text) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.5h.01"/></svg></span>';

/*
 * Sources. `''` = fichiers déposés seulement ; les fournisseurs en ligne
 * ajoutent un dossier tenu en miroir (le dépôt de fichiers reste possible) ;
 * `mybrain` fait de la base un cerveau EN DIRECT. Une base existante ne change
 * pas de nature : un cerveau ne montre que sa source, une base locale toutes
 * les autres. Seules les sources CONFIGURÉES sont proposées, plus celle de la
 * base : une radio désactivée disparaîtrait du POST, la base serait
 * déconfigurée en silence. Les autres sont comptées en une ligne.
 */
$tiles = [
    '' => [
        'label'  => t('Fichiers déposés'),
        'desc'   => t('Vous ajoutez vos fichiers depuis votre ordinateur.'),
        'icon'   => $uploadIcon,
        'on'     => true,
    ],
    'drive' => [
        'label'  => KbDiagram::sourceLabel('drive'),
        'desc'   => t('Le miroir d\'un dossier : par lien, avec votre compte ou dans un Drive partagé.'),
        'on'     => $driveCardOn,
    ],
    'onedrive' => [
        'label'  => KbDiagram::sourceLabel('onedrive'),
        'desc'   => t('Le miroir d\'un dossier de votre OneDrive, choisi en le parcourant.'),
        'on'     => !empty($oneDriveConfigured),
        'attrs'  => ' data-owner-ready="' . (empty($oneDriveOwnerReady) ? 0 : 1) . '"',
    ],
    'sharepoint' => [
        'label'  => KbDiagram::sourceLabel('sharepoint'),
        'desc'   => t('Le miroir d\'un dossier SharePoint de votre organisation.'),
        'on'     => !empty($syncProviders['sharepoint']),
    ],
    'gcalendar' => [
        'label'  => KbDiagram::sourceLabel('gcalendar'),
        'desc'   => t('Un agenda public, en un document par mois.'),
        'on'     => !empty($syncProviders['gcalendar']),
    ],
];
$shownTiles  = [];
$otherTiles  = [];
foreach ($tiles as $value => $tile) {
    if ($tile['on'] || (!$isCreate && $value === $savedProvider)) {
        $shownTiles[$value] = $tile;
    } else {
        $otherTiles[] = $tile['label'];
    }
}

// En-tête : documents, état, utilisations — ce qu'on veut savoir d'un coup d'œil.
$headDocs  = $isCreate ? 0 : count($docs);
$headUses  = $isCreate ? 0 : (int)($kb['query_count'] ?? 0);
$headState = $isCreate || $kbStatus === null ? null : ['title' => (string)$kbStatus['title'], 'tone' => (string)$kbStatus['tone']];

// Qui la voit : privée (son créateur seul), commune (créateur, administrateurs
// et ceux à qui elle est partagée) ou publique (toute l'organisation).
$sharedCount = count($shares['users'] ?? []) + count($shares['groups'] ?? []);
$visibility  = $isCreate ? KnowledgeBase::VIS_COMMON : KnowledgeBase::visibility($kb);
$visTexts    = [
    KnowledgeBase::VIS_PRIVATE => KbPages::visibility(KnowledgeBase::VIS_PRIVATE),
    KnowledgeBase::VIS_COMMON  => KbPages::visibility(KnowledgeBase::VIS_COMMON, $sharedCount),
    KnowledgeBase::VIS_PUBLIC  => KbPages::visibility(KnowledgeBase::VIS_PUBLIC),
];

// Volets : chaque question se replie sur une ligne qui la résume (kb-form.js
// écrit les résumés et retient, par base et par appareil, ce que la personne
// a ouvert ou fermé). Tout est ouvert à la création. En modification, seule
// « D'où viennent les documents » s'ouvre d'elle-même, quand la base demande
// qu'on s'en occupe (KbStatus::attention : échec, documents à vérifier, base
// vide…) — sinon la fiche tient en un écran.
$foldAttention = $isCreate || $kbStatus === null ? '' : KbStatus::attention($kbStatus);
$foldOpen = [
    'identity' => $isCreate,
    // Une table : ses lignes SONT la fiche — ouvertes d'office.
    'sources'  => $isCreate || $foldAttention !== '' || !empty($isTable),
    'use'      => $isCreate,
];
$foldAttr  = static fn(string $key): string => $foldOpen[$key] ? ' open' : '';
$foldCaret = '<span class="kb-advanced-caret kbf-fold-caret" aria-hidden="true"></span>';

$kbFormJsVersion = @filemtime(ROOT . '/public/assets/js/kb-form.js') ?: 1;
// Formats acceptés : ceux des bases (KnowledgeBaseDocument::ALLOWED_MIMES),
// extensions de texte comprises (scripts, données, code — FileExtractor) :
// la même source que la détection côté serveur.
$acceptDocs = FileExtractor::acceptAttribute(array_keys(KnowledgeBaseDocument::ALLOWED_MIMES));

$delConfirm = $isTable
    ? t('Supprimer la table « {nom} » et toutes ses lignes ? Les assistants liés perdront cette source. Action irréversible.', ['nom' => $kbName])
    : ($isLive
    ? t('Débrancher le cerveau « {nom} » ? Les assistants liés et les conversations qui l\'avaient coché perdront cette source. La clé reste valide dans MyBrain : supprimez-la là-bas si elle ne sert plus.', ['nom' => $kbName])
    : t('Supprimer la base « {nom} » et tous ses documents ? Les assistants liés perdront cette source. Action irréversible.', ['nom' => $kbName]));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= htmlspecialchars($kbTitle) ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
    <link rel="stylesheet" href="/assets/css/kb-pages.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/kb-pages.css') ?: 1) ?>">
    <?php if ($isTable): ?><link rel="stylesheet" href="/assets/css/kb-table.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/kb-table.css') ?: 1) ?>"><?php endif; ?>
</head>
<body class="admin-page">
<?php $page = 'knowledge-bases'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content kb-content kbx-page">
<div class="kbx kbx-fiche<?= $isTable ? ' is-table' : '' ?>" id="kbFiche">

    <div class="kbx-top">
        <div class="kbx-top-in">
            <div class="kbx-crumb">
                <a href="<?= htmlspecialchars($backUrl) ?>" title="<?= $isCreate ? te('Toutes les bases') : te('Retour à la base') ?>" aria-label="<?= $isCreate ? te('Toutes les bases') : te('Retour à la base') ?>"><?= KbPages::icon('back', '') ?></a>
                <div>
                    <small><?= htmlspecialchars($kbKicker) ?></small>
                    <b id="kbFicheTitle" class="<?= $kbName === '' ? 'is-missing' : '' ?>" data-empty="<?= $isTable ? te('Sans nom') : te('Sans nom') ?>"><?= htmlspecialchars($kbName !== '' ? $kbName : t('Sans nom')) ?></b>
                    <?php if (!$isCreate): ?>
                    <small id="kbHeadMeta">
                        <?php if ($isTable): ?>
                            <span id="kbTableHeadMeta"><?= htmlspecialchars((string)($tableView['meta'] ?? '')) ?></span>
                        <?php else: ?>
                            <?php if (!$isLive): ?><span id="kbHeadDocs"><?= htmlspecialchars(I18n::plural($headDocs, '{n} document', '{n} documents')) ?></span> · <?php endif; ?>
                            <?php if ($headState !== null): ?><span class="kbf-state" id="kbHeadState" data-tone="<?= htmlspecialchars($headState['tone']) ?>"><?= htmlspecialchars($headState['title']) ?></span><?php endif; ?>
                            <a href="#conseils" class="kbf-advice-pill" id="kbAdvicePill"<?= $kbAdvice === [] ? ' hidden' : '' ?>><?= $kbAdvice === [] ? '' : htmlspecialchars(I18n::plural(count($kbAdvice), '{n} point à améliorer', '{n} points à améliorer')) ?></a>
                        <?php endif; ?>
                    </small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="kbx-top-tools">
                <?php if ($isCreate): ?>
                    <span class="kbx-top-hint"><?= $isTable ? te('La table existe dès que vous cliquez sur « Créer la table ».') : te('La base existe dès que vous cliquez sur « Créer la base ».') ?></span>
                    <a href="/admin/knowledge-bases" class="kbx-btn is-sm"><?= te('Annuler') ?></a>
                    <button type="submit" class="kbx-btn is-sm is-primary" id="kbSaveBtn" form="editKbForm"><?= te('Créer la base') ?></button>
                <?php else: ?>
                    <?php if (!$readOnly): ?>
                        <span class="kbx-saved" id="kbAutosaveHint"><?php require ROOT . '/templates/partials/autosave-hint.php'; ?></span>
                    <?php endif; ?>
                    <?php if (!$noDocs || $kbCanDrop): ?>
                        <?php /* « … » : ce qui ne sert pas tous les jours — les réglages
                                 avancés, et la suppression, loin des réglages courants. */ ?>
                        <div class="kbf-more">
                            <button type="button" class="kbf-more-btn" id="kbMoreBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="kbMoreMenu"
                                    title="<?= te('Plus d\'actions') ?>" aria-label="<?= te('Plus d\'actions') ?>">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                            </button>
                            <div class="kbf-more-menu" id="kbMoreMenu" role="menu" hidden>
                                <?php if (!$noDocs): ?>
                                    <a href="#avance" role="menuitem" class="kbf-more-item" data-kb-open-advanced><?= te('Réglages avancés') ?></a>
                                <?php endif; ?>
                                <?php if ($kbCanDrop): ?>
                                    <button type="button" role="menuitem" class="kbf-more-item is-danger" id="kbDeleteBtn" data-confirm="<?= htmlspecialchars($delConfirm) ?>">
                                        <?= $isTable ? te('Supprimer cette table') : ($isLive ? te('Débrancher ce cerveau') : te('Supprimer cette base')) ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($backUrl) ?>" class="kbx-btn is-sm is-primary"><?= te('Terminé') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="kbx-layout">
        <nav class="kbx-nav" aria-label="<?= te('Sections de la fiche') ?>">
            <a href="#identite" class="is-on"><?= KbPages::icon('id', '') ?><span class="kbx-nl"><?= te('Identité') ?></span></a>
            <?php if ($isTable): ?>
                <a href="#lignes"><?= KbPages::icon('table', '') ?><span class="kbx-nl"><?= te('Lignes') ?></span></a>
            <?php else: ?>
                <a href="#documents"><?= KbPages::icon('doc', '') ?><span class="kbx-nl"><?= $isLive ? te('Connexion') : te('Documents') ?></span><?php if (!$isCreate && !$isLive && $kbAdvice !== []): ?><span class="kbx-count"><?= count($kbAdvice) ?></span><?php endif; ?></a>
            <?php endif; ?>
            <a href="#visibilite"><?= KbPages::visibilityIcon($visibility) ?><span class="kbx-nl"><?= te('Qui la voit') ?></span></a>
            <a href="#assistants"><?= KbPages::icon('robot', '') ?><span class="kbx-nl"><?= $isTable ? te('MIA et assistants') : te('Assistants') ?></span></a>
            <?php if (!$noDocs): ?>
                <a href="#avance"><?= KbPages::icon('cog', '') ?><span class="kbx-nl"><?= te('Avancé') ?></span></a>
            <?php endif; ?>
            <span class="kbx-nav-sep" aria-hidden="true"></span>
            <a href="#ensemble"><?= KbPages::icon('map', '') ?><span class="kbx-nl"><?= te('Vue d\'ensemble') ?></span></a>
        </nav>

        <div class="kbx-sections">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($isSystem): ?>
        <div class="kb-notice" role="note">
            <?= teb('{b}Base système.{/b} Son contenu est tenu à jour par MIA depuis sa documentation, à chaque déploiement : il ne se modifie pas ici. Seule la réindexation reste possible (section « Avancé »).') ?>
        </div>
    <?php elseif ($readOnly && !$isCreate): ?>
        <div class="admin-readonly-banner" role="status"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><span><?= teb('{b}Lecture seule.{/b} Votre rôle vous permet de consulter cette base, pas de la modifier.') ?></span></div>
    <?php endif; ?>
    <?php if (empty($qdrantUp) && !$noDocs): ?>
        <div class="alert alert-error">
            <?= te('⚠ Le moteur vectoriel (Qdrant) est indisponible : les recherches passent par les mots, et les nouveaux documents ne seront vectorisés qu\'après son retour.') ?>
            <?php if (!empty($canSeeVector)): ?><a href="/admin/knowledge-bases/vector"><?= te('Voir le moteur vectoriel') ?></a>.<?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($formAction) ?>" id="editKbForm" enctype="multipart/form-data"
          class="kb-form" <?= $isCreate || $readOnly ? '' : 'data-autosave' ?>>
        <?= Csrf::field() ?>
        <fieldset class="kb-fieldset"<?= $readOnly ? ' disabled' : '' ?>>

        <!-- Identité : nom, description, active. -->
        <section class="kbx-sec" id="identite" aria-labelledby="kbSecIdentity">
            <div id="kbSectionIdentity">
            <h2 id="kbSecIdentity"><?= te('Identité') ?></h2>
            <p class="kbx-lead"><?= $isTable ? te('Comment la table apparaît dans la liste et dans le sélecteur du chat.') : te('Comment la base apparaît dans la liste et dans le sélecteur du chat.') ?></p>
            <div class="kbf-row2 kbx-fields">
                <div class="form-group">
                    <div class="kbf-label"><label for="kbName"><?= te('Nom *') ?></label><?= $tip(t('Comment la base apparaît dans l\'administration et dans le sélecteur du chat.')) ?></div>
                    <input type="text" name="name" id="kbName" class="form-input" required maxlength="255"
                           value="<?= htmlspecialchars($kbName) ?>" placeholder="<?= te('Ex. : Documentation produit') ?>">
                </div>
                <div class="form-group">
                    <div class="kbf-label"><label for="kbDescription"><?= te('Description') ?></label><?= $tip(t('Aide à reconnaître la base. Elle n\'est pas envoyée au modèle.')) ?></div>
                    <input type="text" name="description" id="kbDescription" class="form-input"
                           value="<?= htmlspecialchars($isCreate ? '' : (string)($kb['description'] ?? '')) ?>"
                           placeholder="<?= te('Ex. : Manuels, FAQ et procédures internes') ?>">
                </div>
            </div>
            <div class="kbx-divider"></div>
            <div class="kbx-row">
                <div class="kbx-row-text">
                    <?= te('Active') ?>
                    <small><?= te('Active = interrogée par les assistants liés et proposée dans le chat. Inactive = ignorée partout, sans rien perdre.') ?></small>
                </div>
                <div class="mp-active-toggle kbf-active" id="kbActiveToggle">
                    <label class="ios-switch">
                        <input type="checkbox" name="is_active" id="kbIsActive" value="1" <?= $kbActive ? 'checked' : '' ?><?= $readOnly ? ' disabled' : '' ?>>
                        <span class="ios-switch-slider"></span>
                    </label>
                    <span class="mp-active-text<?= $kbActive ? '' : ' is-off' ?>" id="kbActiveText"><?= $kbActive ? te('Active') : te('Inactive') ?></span>
                </div>
            </div>
            </div>
        </section>

        <!-- Documents (ou les lignes d'une table) : état, source, dépôt. -->
        <section class="kbx-sec" id="<?= $isTable ? 'lignes' : 'documents' ?>" aria-labelledby="kbSecSources">
            <div id="kbSectionSources" data-attention="<?= htmlspecialchars($foldAttention) ?>">
            <h2 id="kbSecSources"><?= $isTable ? te('Lignes') : ($isLive ? te('Connexion') : te('Documents')) ?></h2>
            <p class="kbx-lead"><?= $isTable
                ? te('Les colonnes et les lignes que MIA lit. Chaque geste s\'enregistre aussitôt.')
                : ($isLive ? te('Le cerveau est interrogé en direct : rien n\'est copié dans MIA.') : te('D\'où viennent les documents, et où en est leur lecture.')) ?></p>
            <div class="kbx-block">

            <?php if ($isTable): ?>
                <?php /* Table permanente : colonnes, grille modifiable, import,
                         lien vers son historique. Rien de cette zone ne part avec la fiche
                         (data-autosave-skip, aucun champ nommé) : chaque geste a sa
                         route (KbTableController), et le partiel revient tout rendu. */ ?>
                <div id="kbTableSection" class="kbt" data-autosave-skip data-kb-id="<?= $kbId ?>"
                     data-summary="<?= htmlspecialchars((string)($tableView['meta'] ?? '')) ?>"
                     data-error="<?= te('Le geste n\'a pas abouti. Réessayez.') ?>">
                    <?= KbTableView::sectionHtml($tableView) ?>
                </div>
            <?php else: ?>

            <?php if (!$isCreate): ?>
                <?php /* État de la base : synchronisation, indexation, échecs, prochain
                         passage — rendu par le serveur (partiel kb-status.php, variante
                         « panel »), rafraîchi par kb-form.js tant que quelque chose
                         tourne. Il ne porte aucun champ. */ ?>
                <div id="kbStatusPanel" class="kb-panel-wrap" aria-live="polite"><?= $statusPanelHtml ?></div>
            <?php endif; ?>

            <?php if ($isSystem): ?>
                <p class="kbf-line"><?= te('La documentation de MIA, déposée et tenue à jour par MIA elle-même.') ?></p>
            <?php else: ?>
            <div class="kbf-pills" id="kbSourcesField" role="radiogroup" aria-label="<?= te('Source des documents') ?>">
                <?php if (!$isLive): ?>
                    <?php foreach ($shownTiles as $value => $tile): ?>
                        <?php
                            $logo = $value === '' ? $tile['icon']
                                : '<img src="' . htmlspecialchars(KbDiagram::SOURCES[$value]['logo']) . '" alt="" width="16" height="16">';
                        ?>
                        <label class="kbf-pill" data-provider="<?= htmlspecialchars($value) ?>"
                               data-configured="<?= $tile['on'] ? 1 : 0 ?>"<?= $tile['attrs'] ?? '' ?> title="<?= htmlspecialchars($tile['desc']) ?>">
                            <input type="radio" name="sync_provider" value="<?= htmlspecialchars($value) ?>"
                                   <?= $value === ($isCreate ? '' : $savedProvider) ? 'checked' : '' ?>>
                            <span class="kbf-pill-logo"><?= $logo ?></span>
                            <span class="kbf-pill-name"><?= htmlspecialchars($tile['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($isAdminManager): ?>
                    <?php /* Pas un miroir : une base EN DIRECT (backend = mybrain). Réservée
                             aux administrateurs — la clé vient de l'administrateur de MyBrain,
                             et la brancher ouvre le wiki de l'équipe à qui recevra la base. */ ?>
                    <?php if ($isCreate || $isLive): ?>
                        <label class="kbf-pill is-live" data-provider="mybrain" data-configured="1"
                               title="<?= te('Le wiki de l\'équipe, interrogé en direct : rien n\'est copié dans MIA.') ?>">
                            <input type="radio" name="sync_provider" value="mybrain"<?= $isLive ? ' checked' : '' ?>>
                            <span class="kbf-pill-logo"><img src="<?= htmlspecialchars(KbDiagram::SOURCES['mybrain']['logo']) ?>" alt="" width="16" height="16"></span>
                            <span class="kbf-pill-name"><?= te('Cerveau MyBrain') ?></span>
                        </label>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!$isLive && $otherTiles !== []): ?>
                <p class="kbf-line is-muted" id="kbOtherSources">
                    <?php if ($isAdminManager): ?>
                        <?= $withLink(htmlspecialchars(I18n::plural(count($otherTiles),
                            'Une autre source existe ({liste}) : {a}configurez-la dans Sources synchronisées{/a}.',
                            '{n} autres sources existent ({liste}) : {a}configurez-les dans Sources synchronisées{/a}.',
                            ['liste' => implode(', ', $otherTiles)])), '/admin/knowledge-bases/sync') ?>
                    <?php else: ?>
                        <?= htmlspecialchars(I18n::plural(count($otherTiles),
                            'Une autre source existe ({liste}) : un administrateur peut l\'activer.',
                            '{n} autres sources existent ({liste}) : un administrateur peut les activer.',
                            ['liste' => implode(', ', $otherTiles)])) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($isAdminManager && ($isCreate || $isLive)): ?>
            <!-- Connexion d'un cerveau (source « Cerveau MyBrain »). Les deux champs
                 sont HORS de l'enregistrement automatique (data-autosave-skip) : une
                 clé à moitié collée n'a rien à faire en base. En création, « Créer la
                 base » teste puis enregistre ; en modification, « Tester et
                 enregistrer » est le seul chemin. -->
            <div id="kbMyBrainConfig" class="kb-source-config kb-mybrain-config" hidden>
                <p class="kbf-line">
                    <?= te('Il faut une clé d\'application MyBrain avec le droit « lire ».') ?>
                    <?= $tip($plain(t('MIA interroge le cerveau à chaque message où la base est engagée — cochée dans la conversation ou liée à l\'assistant — et cite ses fiches. Il faut une {b}clé d\'application{/b} créée dans MyBrain (Administration → Clés d\'API) avec le droit {b}lire{/b}. Rien n\'est enregistré tant que le test ne passe pas.'))) ?>
                </p>
                <div class="kbf-row2">
                    <div class="form-group">
                        <div class="kbf-label"><label for="kbMyBrainUrl"><?= te('Adresse du MyBrain') ?></label><?= $tip(t('L\'adresse du site MyBrain, pas celle d\'une fiche. https obligatoire, sauf sur le réseau interne du serveur.')) ?></div>
                        <input type="url" name="mybrain_url" id="kbMyBrainUrl" class="form-input" data-autosave-skip
                               placeholder="<?= te('https://mybrain.votre-domaine.fr') ?>" autocomplete="off"
                               value="<?= htmlspecialchars($isLive ? (string)($kb['remote_url'] ?? '') : '') ?>">
                    </div>
                    <div class="form-group">
                        <div class="kbf-label"><label for="kbMyBrainKey"><?= te('Clé d\'application') ?></label></div>
                        <input type="password" name="mybrain_key" id="kbMyBrainKey" class="form-input"
                               placeholder="mbk_…" autocomplete="new-password" spellcheck="false" data-autosave-skip>
                        <p class="profile-hint" id="kbMyBrainKeyHint"><?= $isLive && (string)($kb['remote_key_hint'] ?? '') !== ''
                            ? te('Clé enregistrée : {indice} — laissez vide pour la garder.', ['indice' => (string)$kb['remote_key_hint']])
                            : te('Montrée une seule fois par MyBrain, à sa création.') ?></p>
                    </div>
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
                <?php /* Les réglages du dossier se replient eux aussi, sur une ligne
                         qui les résume (dossier, rythme, accès) : une fois la source
                         en place, on n'y revient que rarement. Ouverts tant qu'aucun
                         dossier n'est enregistré, et dès qu'on change de source. */ ?>
                <details class="kbf-subfold" id="kbSyncFold"<?= $isCreate || $savedProvider === '' || $savedUrl === '' ? ' open' : '' ?>>
                <summary class="kbf-subfold-head">
                    <span class="kbf-subfold-title"><?= te('Réglages du dossier') ?></span>
                    <span class="kbf-subfold-sum" id="kbSyncFoldSum"></span>
                    <span class="kb-advanced-caret kbf-fold-caret" aria-hidden="true"></span>
                </summary>
                <div class="kbf-subfold-body">
                <p class="kbf-line" id="kbSyncHint"><span id="kbSyncHintText"></span> <?= $tip('', false, 'kbSyncHintTip') ?></p>

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
                    <div class="kbf-label">
                        <label for="kbSyncUrl" id="kbSyncUrlLabel"><?= te('Dossier') ?></label>
                        <?= $tip($plain(t('Collez le lien (ou l\'adresse) de la source puis vérifiez l\'accès avec {b}Tester{/b}. Revenir sur « Fichiers déposés » retire le miroir : les documents déjà repris restent, et redeviennent supprimables.'))) ?>
                    </div>
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
                    <p class="profile-hint" id="kbSyncUrlHelp"><?= teb('Collez le lien de la source, puis {b}Tester{/b}.') ?></p>
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
                </details>
            </div>
            <?php endif; ?>

            <?php if (!$isLive): ?>
            <!-- Documents de la base : dépôt, en complément d'un miroir ou seul. -->
            <div id="kbDocsBlock" class="kb-docs-block">
                <div class="kb-docs-head">
                    <span class="kb-docs-title"><?= te('Documents') ?></span>
                    <span class="kb-docs-count" id="kbDocsCount"></span>
                    <?= $tip($isCreate
                        ? t('PDF, Word, Excel, PowerPoint, OpenDocument, EPUB, HTML, texte, Markdown, CSV, JSON, code, images — {n} fichiers déposés au plus, 20 Mo chacun. Les images servent aussi de modèles à l\'outil image des assistants.', ['n' => (int)KnowledgeBase::MAX_DOCS_PER_KB])
                        : t('PDF, Word, Excel, PowerPoint, OpenDocument, EPUB, HTML, texte, Markdown, CSV, JSON, code, images — {n} fichiers déposés au plus, 20 Mo chacun ; {m} repris d\'un dossier, 50 Mo chacun. Les images servent aussi de modèles à l\'outil image des assistants.', ['n' => (int)KnowledgeBase::MAX_DOCS_PER_KB, 'm' => (int)KbSync::MAX_DOCS_PER_SYNCED_KB])) ?>
                </div>
                <?php if (!$readOnly): ?>
                <label class="kb-drop kbf-drop" id="kbDrop">
                    <?php if ($isCreate): ?>
                        <input type="file" name="documents[]" id="kbDocsCreateInput" multiple accept="<?= htmlspecialchars($acceptDocs) ?>">
                    <?php else: ?>
                        <input type="file" id="kbDocFileInput" multiple accept="<?= htmlspecialchars($acceptDocs) ?>">
                    <?php endif; ?>
                    <span class="kb-drop-ico" aria-hidden="true"><?= $uploadIcon ?></span>
                    <span class="kb-drop-text"><?= teb('{b}Déposez vos fichiers ici{/b} ou cliquez pour les choisir') ?></span>
                </label>
                <?php endif; ?>
                <p class="profile-doc-error" id="kbDocError" hidden></p>
                <?php if ($isCreate): ?>
                    <ul class="kb-pending-files" id="kbPendingFiles" hidden></ul>
                    <p class="profile-hint"><?= te('Les fichiers sont lus puis vectorisés dès la création de la base.') ?></p>
                <?php else: ?>
                    <?php /* Liste compacte (kb-form.js) : ce qui demande l'attention
                             d'abord, cinq lignes, puis « Voir les N autres » ; au-delà
                             de cinq documents, des filtres par état et, dès huit, une
                             recherche par nom. */ ?>
                    <div class="kb-docs-toolbar" id="kbDocsToolbar" hidden>
                        <div class="kb-docs-filters" id="kbDocsFilters" role="group" aria-label="<?= te('Filtrer les documents') ?>"></div>
                        <input type="search" class="form-input kb-docs-search" id="kbDocsSearch" data-autosave-skip hidden
                               placeholder="<?= te('Rechercher un document…') ?>" aria-label="<?= te('Rechercher un document par son nom') ?>" autocomplete="off">
                    </div>
                    <div id="kbDocumentsList" class="kb-docs-list"></div>
                    <div class="kb-docs-more" id="kbDocsMore" hidden></div>
                <?php endif; ?>
            </div>

            <?php if (!$isSystem): ?>
            <!-- Pour une base efficace : ce qui rend une base utile à un assistant,
                 en mots simples, et — sur une base existante — ses points à
                 améliorer (KbAdvice, partiel kb-advice.php, rafraîchi avec l'état).
                 Replié : la pastille de l'en-tête l'ouvre (#conseils). -->
            <details class="kbf-advice" id="conseils">
                <summary class="kbf-advice-summary">
                    <span class="kbf-advice-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2Z"/></svg></span>
                    <span class="kbf-advice-title"><?= te('Pour une base efficace') ?></span>
                    <span class="kbf-advice-count" id="kbAdviceCount"<?= $kbAdvice === [] ? ' hidden' : '' ?>><?= $kbAdvice === [] ? '' : htmlspecialchars(I18n::plural(count($kbAdvice), '{n} point à améliorer', '{n} points à améliorer')) ?></span>
                    <span class="kb-advanced-caret" aria-hidden="true"></span>
                </summary>
                <div class="kbf-advice-body">
                    <div id="kbAdviceIssues"><?php require ROOT . '/templates/partials/kb-advice.php'; ?></div>
                    <ul class="kbf-tips">
                        <li><?= teb('{b}Un sujet par base.{/b} Les RH dans une base, les produits dans une autre : la recherche vise plus juste, et chacun coche celle qui lui sert.') ?></li>
                        <li><?= teb('{b}Du vrai texte.{/b} PDF issus d\'un traitement de texte, Word, pages web, Markdown. Un scan ou une photo de page ne contient pas de texte : MIA n\'y trouve rien.') ?></li>
                        <li><?= teb('{b}Une seule version de chaque document.{/b} Deux versions qui se contredisent donnent des réponses qui se contredisent.') ?></li>
                        <li><?= teb('{b}Des titres et des paragraphes.{/b} MIA découpe chaque document en extraits d\'environ {n} mots, de préférence aux titres : un document bien structuré donne des extraits qui ont du sens seuls.', ['n' => KbAdvice::chunkWords()]) ?></li>
                        <li><?= teb('{b}Le volume ne coûte pas plus cher.{/b} À chaque question, seuls les extraits les plus proches partent au modèle : 5 documents ou 50 coûtent autant par question — pourvu qu\'ils parlent du sujet.') ?></li>
                        <li><?= teb('{b}Des chiffres à calculer ?{/b} La recherche retrouve des lignes de tableur, pas des totaux : joignez plutôt le fichier dans la conversation.') ?></li>
                    </ul>
                    <p class="kbf-tips-limits"><?= te('{n} fichiers déposés au plus, 20 Mo chacun ; un dossier synchronisé : {m} fichiers, 50 Mo chacun.', ['n' => (int)KnowledgeBase::MAX_DOCS_PER_KB, 'm' => (int)KbSync::MAX_DOCS_PER_SYNCED_KB]) ?></p>
                </div>
            </details>
            <?php endif; ?>
            <?php endif; ?>
            <?php endif; /* table / base à documents */ ?>
            </div>
            </div>
        </section>

        <!-- Qui la voit : privée, commune ou publique ; le partage d'une base commune. -->
        <section class="kbx-sec" id="visibilite" aria-labelledby="kbSecVisibility">
            <div class="kbf-block" id="kbSectionSharing">
                <h2 id="kbSecVisibility"><?= te('Qui la voit') ?></h2>
                <?php if ($isSystem): ?>
                    <p class="kbx-lead"><?= te('La base de l\'assistant d\'aide sert tout le monde : son partage ne se règle pas.') ?></p>
                <?php elseif (!$isOwned): ?>
                    <p class="kbx-lead">
                        <?= te('Cette base vous est partagée en modification par {nom} : son partage et sa suppression restent à lui.', ['nom' => (string)($kb['creator_name'] ?? t('son propriétaire'))]) ?>
                    </p>
                <?php else: ?>
                    <p class="kbx-lead"><?= te('Ceux qui la voient la cochent dans le chat et la lient à leurs assistants. Vous pouvez changer d\'avis à tout moment.') ?></p>
                    <div id="kbAccess">
                        <?php /* `visibility_form` : le serveur ne lit le choix que si la fiche le portait. */ ?>
                        <input type="hidden" name="visibility_form" value="1">
                        <div class="kbx-access" role="radiogroup" aria-label="<?= te('Qui la voit') ?>">
                            <?php foreach ([KnowledgeBase::VIS_PRIVATE, KnowledgeBase::VIS_COMMON, KnowledgeBase::VIS_PUBLIC] as $vKey):
                                $vOff = $vKey === KnowledgeBase::VIS_PRIVATE && empty($isCreator);
                                $vSub = match ($vKey) {
                                    KnowledgeBase::VIS_PRIVATE => t('Vous seul'),
                                    KnowledgeBase::VIS_PUBLIC  => t('Toute l\'organisation'),
                                    default                    => t('Vous, les administrateurs, et ceux que vous choisissez'),
                                }; ?>
                                <label class="kbx-acc"<?= $vOff ? ' title="' . te('Seul son créateur peut réserver une base à lui seul.') . '"' : '' ?>>
                                    <input type="radio" name="visibility" value="<?= htmlspecialchars($vKey) ?>"<?= $visibility === $vKey ? ' checked' : '' ?><?= $vOff ? ' disabled' : '' ?>>
                                    <span class="kbx-acc-top"><span class="kbx-acc-ic"><?= KbPages::visibilityIcon($vKey) ?></span><span class="kbx-radio"></span></span>
                                    <b><?= htmlspecialchars($visTexts[$vKey]['label']) ?></b>
                                    <span class="kbx-acc-sub"><?= htmlspecialchars($vSub) ?></span>
                                    <small><?= htmlspecialchars(match ($vKey) {
                                        KnowledgeBase::VIS_PRIVATE => t('Pas même un administrateur. Liée à un assistant, elle ne répond qu\'à vous.'),
                                        KnowledgeBase::VIS_PUBLIC  => t('Chacun la voit et la coche dans le chat. Seuls vous et les administrateurs la modifiez.'),
                                        default                    => t('Sans partage, seuls vous et les administrateurs la voyez.'),
                                    }) ?></small>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="kbx-vis-note" data-vis-note="private"<?= $visibility === KnowledgeBase::VIS_PRIVATE ? '' : ' hidden' ?>><?= te('Privée : personne d\'autre — pas même un administrateur — ne la voit, ne la coche dans le chat, ne la partage ni ne l\'interroge à travers un assistant. Vous seul pouvez retirer ce choix.') ?></p>
                        <p class="kbx-vis-note" data-vis-note="public"<?= $visibility === KnowledgeBase::VIS_PUBLIC ? '' : ' hidden' ?>><?= te('Publique : toute l\'organisation la voit et la coche dans le chat. Le partage aux groupes devient inutile.') ?></p>
                    </div>
                    <div class="kb-share-body" id="kbShareBody"<?= $visibility === KnowledgeBase::VIS_COMMON ? '' : ' hidden' ?>>
                        <p class="kbf-line" id="kbShareHintLocal"<?= $isLive ? ' hidden' : '' ?>>
                            <?= te('Les groupes et personnes ajoutés la cochent dans le chat et la rattachent à leurs assistants.') ?>
                            <?= $tip($plain(t('Par défaut, seuls vous et les administrateurs voyez cette base. Les personnes et groupes cochés la voient aussi : ils la cochent dans le chat et la rattachent à leurs assistants. Cochez {b}peut modifier{/b} pour leur confier aussi les documents ; la suppression et ce partage restent à vous.'))) ?>
                        </p>
                        <p class="kbf-line" id="kbShareHintLive"<?= $isLive ? '' : ' hidden' ?>>
                            <?= te('Les membres des groupes ajoutés trouvent le cerveau dans le sélecteur de bases du chat.') ?>
                            <?= $tip($plain(t('{b}Qui peut interroger le cerveau.{/b} Sans partage, seuls les administrateurs le voient. Les membres des groupes cochés le trouvent dans le sélecteur de bases du chat — et c\'est vérifié à chaque message, même à travers un assistant lié : un assistant ouvert à d\'autres groupes ne leur ouvre pas le cerveau. {b}Cochée par défaut{/b} : la base est déjà cochée dans chaque nouvelle conversation de ces personnes, qui peuvent la décocher. {b}Peut nourrir{/b} : ces personnes peuvent proposer un texte au cerveau depuis une conversation — après leur approbation, il part dans la revue de MyBrain, où il est validé. Il faut aussi que la clé ait le droit « nourrir ».'))) ?>
                        </p>

                        <?php /* Pastilles des groupes et personnes cochés, rendues par
                                 kb-form.js depuis les listes ci-dessous. */ ?>
                        <div class="kbf-chips" id="kbShareChips" role="list" aria-label="<?= te('Groupes et personnes') ?>"></div>
                        <?php if (!$readOnly): ?>
                            <button type="button" class="kbf-add" id="kbShareAddBtn" aria-expanded="false" aria-controls="kbSharePanel"><?= te('+ Ajouter') ?></button>
                        <?php endif; ?>

                        <div class="kbf-panel" id="kbSharePanel" hidden>
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
                        </div><!-- /#kbSharePanel -->
                    </div><!-- /#kbShareBody -->
                <?php endif; ?>
            </div>

        </section>

        <!-- Assistants qui l'interrogent ; ce que MIA peut faire d'une table. -->
        <section class="kbx-sec" id="assistants" aria-labelledby="kbSecAssistants">
            <h2 id="kbSecAssistants" class="kbx-sr"><?= $isTable ? te('MIA et assistants') : te('Assistants') ?></h2>
            <!-- Assistants qui l'interrogent -->
            <div class="kbf-block" id="kbSectionAssistants">
                <h5 class="kbf-block-title">
                    <?= te('Assistants liés') ?><span class="kb-linked-count" id="kbLinkedCount"></span>
                    <?= $tip(t('Ils interrogent la base à chaque message. Une base peut servir plusieurs assistants, et se coche aussi dans le chat.')) ?>
                </h5>
                <p class="profile-hint kb-private-note" id="kbPrivateAssistantsHint"<?= $isPrivate ? '' : ' hidden' ?>>
                    <?= teb('{b}Uniquement pour moi :{/b} même liée à un assistant que d\'autres ouvrent, la base ne répond qu\'à vous. Pour eux, l\'assistant répond sans elle.') ?>
                </p>
                <?php if (empty($manageableProfiles)): ?>
                    <p class="ug-empty-list"><?= te('Aucun assistant dans votre périmètre. Créez d\'abord un assistant.') ?></p>
                <?php else: ?>
                    <?php /* Pastilles des assistants cochés, rendues par kb-form.js depuis la liste. */ ?>
                    <div class="kbf-chips" id="kbAssistantChips" role="list" aria-label="<?= te('Assistants liés') ?>"></div>
                    <?php if (!$readOnly): ?>
                        <button type="button" class="kbf-add" id="kbAssistantAddBtn" aria-expanded="false" aria-controls="kbAssistantPicker"><?= te('Lier un assistant') ?></button>
                    <?php endif; ?>
                    <div class="kbf-panel" id="kbAssistantPicker" hidden>
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
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isTable): ?>
            <!-- Ce que MIA peut faire de la table. Lire : toujours. Écrire :
                 jamais sans carte d'approbation — ce réglage dit seulement si
                 elle peut le PROPOSER. `table_form` : le serveur ne lit la case
                 que si la fiche la portait. -->
            <div class="kbf-block" id="kbSectionMia">
                <h5 class="kbf-block-title">
                    <?= te('MIA peut') ?>
                    <?= $tip(t('MIA lit toujours une table cochée dans le chat ou liée à un assistant : elle compte, filtre et croise ses lignes. Écrire dedans se fait toujours sous une carte d\'approbation, qui montre les lignes touchées avant et après ; seules les personnes qui peuvent modifier la table se la voient proposer. Chaque écriture s\'inscrit dans l\'historique de la table : on peut revenir en arrière.')) ?>
                </h5>
                <input type="hidden" name="table_form" value="1">
                <div class="kbf-access kbt-mia" role="radiogroup" aria-label="<?= te('MIA peut') ?>">
                    <label class="kbf-access-opt">
                        <input type="radio" name="mia_write" value=""<?= empty($tableMiaWrite) ? ' checked' : '' ?>>
                        <span class="kbf-access-text">
                            <span class="kbf-access-name"><?= te('Lire seulement') ?></span>
                            <span class="kbf-access-sub"><?= te('Elle compte et croise, sans rien changer') ?></span>
                        </span>
                    </label>
                    <label class="kbf-access-opt">
                        <input type="radio" name="mia_write" value="1"<?= empty($tableMiaWrite) ? '' : ' checked' ?>>
                        <span class="kbf-access-text">
                            <span class="kbf-access-name"><?= te('Lire, et écrire après mon accord') ?></span>
                            <span class="kbf-access-sub"><?= te('Chaque modification attend une carte d\'approbation') ?></span>
                        </span>
                    </label>
                </div>
            </div>
            <?php endif; ?>
        </section>

        </fieldset>
    </form>

    <?php if (!$noDocs): ?>
    <!-- Avancé : le modèle d'embedding, la réindexation (seul chemin pour en
         changer) et le détail des extraits vectorisés. Replié par défaut ;
         ouvert par l'adresse #avance (retour d'une réindexation) ou par le
         menu « … » de l'en-tête. -->
    <details class="kbx-sec kb-advanced" id="avance">
        <summary class="kb-advanced-summary">
            <span class="kb-advanced-title"><svg class="profile-section-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg><?= te('Avancé') ?></span>
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
                            <?= te('Aucune clé d\'embedding n\'est configurée : la réindexation échouera tant qu\'une clé OpenAI, Mistral ou Gemini n\'est pas enregistrée dans Modèles IA → Fournisseurs et modèles.') ?>
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

    <!-- Vue d'ensemble : le schéma de la base, recomposé à chaque réglage. Un
         nœud mène à la section qui le règle. -->
    <section class="kbx-sec" id="ensemble" aria-labelledby="kbSecEnsemble">
        <div class="kbx-sec-h">
            <div>
                <h2 id="kbSecEnsemble"><?= te('Vue d\'ensemble') ?></h2>
                <p class="kbx-lead"><?= $isCreate
                    ? te('Ce que sera la base, mis à jour à chaque réglage. Touchez un élément pour aller le régler.')
                    : te('Ce qui l\'alimente, comment elle est cherchée, qui s\'en sert et qui la voit. Touchez un élément pour aller le régler.') ?></p>
            </div>
        </div>
        <div class="kbx-diagram sched-diagram" id="kbFormDiagram"></div>
    </section>

    <?php if ($isCreate): ?>
        <div class="kbx-endbar">
            <span><?= te('Vous pourrez tout modifier ensuite, depuis sa page.') ?></span>
            <a href="/admin/knowledge-bases" class="kbx-btn"><?= te('Annuler') ?></a>
            <button type="submit" class="kbx-btn is-primary" form="editKbForm" data-kb-create><?= te('Créer la base') ?></button>
        </div>
    <?php endif; ?>
        </div><!-- /.kbx-sections -->
    </div><!-- /.kbx-layout -->

    <?php if ($kbCanDrop): ?>
        <form method="POST" action="/admin/knowledge-bases/<?= $kbId ?>/delete" id="kbDeleteForm" hidden><?= Csrf::field() ?></form>
    <?php endif; ?>
</div>

<?php if (!$isCreate && !$noDocs): ?>
    <?php /* Fiche d'un document (volet) : un clic sur un document l'ouvre à
             droite (plein écran au téléphone). Hors du formulaire : un
             fieldset désactivé (lecture seule) n'y désactive rien. Même volet
             que la page de la base (kb-doc.js). */ ?>
    <?php require ROOT . '/templates/admin/knowledge-bases/partials/doc-drawer.php'; ?>
<?php endif; ?>
</div>

<script>
// Fiche d'une base (kb-form.js) : état de départ, rendu par le serveur.
window.KB_FORM = <?= json_encode([
    'id'          => $kbId,
    'create'      => $isCreate,
    'live'        => $isLive,
    'table'       => $isTable,
    'readOnly'    => $readOnly,
    'owned'       => $isOwned,
    'private'     => $isPrivate,
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
    'userId'      => (int)($currentUser['id'] ?? 0),
    'syncInterval'=> (int)$syncInterval,
    // Volets : ouverts d'office par le serveur, et la signature de ce qui
    // demande l'attention (un problème NOUVEAU rouvre « D'où viennent les
    // documents », même replié la fois d'avant).
    'fold'        => ['open' => $foldOpen, 'attention' => $foldAttention, 'busy' => !empty($kbStatus['busy'])],
    'system'      => $isSystem,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script>
// Vue d'ensemble : le schéma de la base (KbDiagram), recomposé par kb-fiche.js.
window.KB_FICHE = <?= json_encode([
    'catalog' => $diagram['catalog'],
    'spec'    => $diagram['spec'],
    'jump'    => [
        'docs'       => t('Modifier dans « Documents »'),
        'connectors' => t('Modifier dans « Documents »'),
        'live'       => t('Modifier dans « Connexion »'),
        'llm'        => t('Modifier dans « Avancé »'),
        'assistant'  => t('Modifier dans « Identité »'),
        'assistants' => t('Modifier dans « Assistants »'),
        'sharing'    => t('Modifier dans « Qui la voit »'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/diagram.js') ?: 1) ?>"></script>
<script src="/assets/js/kb-diagram.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/kb-diagram.js') ?: 1) ?>"></script>
<script src="/assets/js/kb-doc.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/kb-doc.js') ?: 1) ?>"></script>
<script src="/assets/js/kb-form.js?v=<?= (int)$kbFormJsVersion ?>"></script>
<script src="/assets/js/kb-fiche.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/kb-fiche.js') ?: 1) ?>"></script>
<?php if ($isTable): ?>
<script src="/assets/js/kb-table.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/kb-table.js') ?: 1) ?>"></script>
<?php endif; ?>
</body>
</html>
