<?php
/**
 * Page création / édition d'un assistant (ex-modale de /admin/profiles).
 *
 * Attend : $profile (null = création), $prefill (création pré-remplie depuis
 * la carte d'un assistant préparé par MIA, « Ajuster » — sinon null),
 * $assistantAvatars, $knowledgeBases,
 * $imageTools, $n8nTools, $gammaTools, $llms (whitelist du gestionnaire), $ragMinBudget,
 * $isAdminManager, $currentUser, $profileDocs (édition seulement, format
 * payload), $boundToolIds, $boundKbIds, $diagram, $success, $error.
 *
 * Même grammaire que la fiche d'une base (kb-pages.css, en violet) : une
 * barre du haut qui reste en vue (retour, nom qui suit le champ, état,
 * « Créer l'assistant » ou enregistrement automatique et « Terminé »), un
 * menu des sections à gauche, puis cinq cartes — Identité, Consigne, Modèle,
 * Capacités, Connaissances — et la « Vue d'ensemble » : le SCHÉMA de
 * l'assistant, celui des tâches programmées sans déclencheur, redessiné à
 * chaque geste (diagram.js + assistant-diagram.js ; données $diagram :
 * AssistantDiagram::catalog, partage, liaisons fixes). Mise en page de la
 * fiche (titre, menu, « … ») : assistant-fiche.js.
 * Chaque section et chaque champ réglable porte un id (mpSection*,
 * mpPromptField, mpLlmField, mpTempField, mpKbsField…) : c'est par eux qu'un
 * clic sur un élément du schéma mène au réglage correspondant.
 */
$isCreate  = $profile === null;
// « Ajuster » : la proposition de MIA remplit la fiche de création.
$prefill   = $isCreate && isset($prefill) && is_array($prefill) ? $prefill : null;
$page      = $isCreate ? 'profiles-new' : 'profiles';
$pfName    = $isCreate ? (string)($prefill['name'] ?? '') : (string)$profile['name'];
$pfTitle   = $isCreate ? t('Créer un assistant') : t('Modifier — {nom}', ['nom' => $pfName]);
$pfActive  = $isCreate ? true : !empty($profile['is_active']);
$pfColor   = $isCreate ? (($prefill['color'] ?? '') ?: '#1e293b') : (trim((string)($profile['color'] ?? '')) ?: '#1e293b');
// Avatar créé par MIA pour cet assistant : proposé (et coché) en tête de la
// bibliothèque ; il n'y entre qu'à la création (AssistantDraft).
$pfAvatarGenerated = $prefill !== null ? (string)($prefill['avatar_generated'] ?? '') : '';
$pfAvatar  = $isCreate
    ? ($pfAvatarGenerated !== '' ? AssistantDraft::GENERATED_AVATAR_CHOICE : (string)($prefill['avatar'] ?? ''))
    : (string)($profile['avatar'] ?? '');
$pfTemp    = $isCreate ? ($prefill['temperature'] ?? null) : ($profile['temperature'] ?? null);
$pfLlm     = $isCreate ? (string)($prefill['default_llm'] ?? '') : (string)($profile['default_llm'] ?? '');
$pfDescription = $isCreate ? (string)($prefill['description'] ?? '') : (string)($profile['description'] ?? '');
$pfPrompt  = $isCreate ? (string)($prefill['system_prompt'] ?? '') : (string)$profile['system_prompt'];
$formAction = $isCreate ? '/admin/profiles' : ('/admin/profiles/' . (int)$profile['id']);
// Fiche en lecture seule : assistant existant, droit « Assistants — Modifier »
// absent (Personnes → Rôles). Champs grisés, rien ne s'enregistre.
$pfReadOnly = !$isCreate && empty($canEditProfile);
$boundToolIds = $boundToolIds ?? [];
$boundKbIds   = $boundKbIds ?? [];
$profileDocs  = $profileDocs ?? [];
// Retour : la liste à la création, la page de l'assistant ensuite (« Terminé »).
$backUrl    = $isCreate ? '/admin/profiles' : '/admin/profiles/' . (int)$profile['id'];
// Barre du haut : avatar (ou initiale) et qui le voit.
$pfHeadAvatar = $pfAvatarGenerated !== '' ? $pfAvatarGenerated : AssistantDiagram::avatarUrl($isCreate ? (string)($prefill['avatar'] ?? '') : (string)($profile['avatar'] ?? ''));
$pfVisLabel   = AssistantPages::visibilityText($isCreate ? AssistantPages::VIS_PERSONAL : AssistantPages::visibility($profile))['label'];
$diagramJsVersion          = @filemtime(ROOT . '/public/assets/js/diagram.js') ?: 1;
$assistantDiagramJsVersion = @filemtime(ROOT . '/public/assets/js/assistant-diagram.js') ?: 1;
// Documents de référence : les formats des bases, extensions de texte
// comprises — la même source que la détection côté serveur.
$acceptDocs = FileExtractor::acceptAttribute(array_keys(KnowledgeBaseDocument::ALLOWED_MIMES));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= htmlspecialchars($pfTitle) ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
    <link rel="stylesheet" href="/assets/css/kb-pages.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/kb-pages.css') ?: 1) ?>">
    <link rel="stylesheet" href="/assets/css/assistants.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/assistants.css') ?: 1) ?>">
</head>
<body class="admin-page">
<?php require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content kbx-page">
<div class="kbx kbx-fiche is-asst" id="pfFiche">

    <?php /* Barre du haut, qui reste en vue : retour, nom (il suit le champ),
             état, et l'issue de la fiche — « Créer l'assistant » à la
             création ; en modification, l'enregistrement automatique,
             la relecture d'un prompt posé par script, « … » et « Terminé ». */ ?>
    <div class="kbx-top">
        <div class="kbx-top-in">
            <div class="kbx-crumb">
                <a href="<?= htmlspecialchars($backUrl) ?>" title="<?= $isCreate ? te('Tous les assistants') : te('Retour à l’assistant') ?>" aria-label="<?= $isCreate ? te('Tous les assistants') : te('Retour à l’assistant') ?>"><?= KbPages::icon('back', '') ?></a>
                <span class="asx-ava is-head<?= $pfHeadAvatar === '' ? ' is-initial' : '' ?>" id="pfHeadAva" style="--asx-c: <?= htmlspecialchars(AssistantPages::color(['color' => $pfColor])) ?>" aria-hidden="true">
                    <img src="<?= htmlspecialchars($pfHeadAvatar) ?>" alt=""<?= $pfHeadAvatar === '' ? ' hidden' : '' ?>><span<?= $pfHeadAvatar !== '' ? ' hidden' : '' ?>><?= htmlspecialchars(AssistantPages::initial($pfName)) ?></span>
                </span>
                <div>
                    <small><?= $isCreate ? te('Nouvel assistant') : te('Assistant') ?></small>
                    <b id="pfFicheTitle" class="<?= $pfName === '' ? 'is-missing' : '' ?>" data-empty="<?= te('Sans nom') ?>"><?= htmlspecialchars($pfName !== '' ? $pfName : t('Sans nom')) ?></b>
                    <?php if (!$isCreate): ?>
                    <small id="pfHeadMeta"><span id="pfHeadState" data-on="<?= te('Actif') ?>" data-off="<?= te('Inactif') ?>"><?= $pfActive ? te('Actif') : te('Inactif') ?></span> · <?= htmlspecialchars($pfVisLabel) ?></small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="kbx-top-tools">
                <?php if ($isCreate): ?>
                    <span class="kbx-top-hint"><?= te('L’assistant existe dès que vous cliquez sur « Créer l’assistant ».') ?></span>
                    <a href="/admin/profiles" class="kbx-btn is-sm"><?= te('Annuler') ?></a>
                    <button type="submit" class="kbx-btn is-sm is-primary" id="mpSaveBtn" form="editProfileForm"><?= te('Créer l’assistant') ?></button>
                <?php else: ?>
                    <?php /* Mode relecture : un modèle collé ou un prompt généré
                             n'est PAS enregistré tout seul — l'assistant est peut-
                             être en service, le texte se relit avant d'être
                             appliqué. L'auto-save du formulaire est suspendu
                             jusqu'à Enregistrer ou Annuler (cf. JS). */ ?>
                    <span class="pf-review-bar" id="mpReviewBar" hidden>
                        <span class="pf-review-note"><?= te('Prompt modifié, pas encore enregistré') ?></span>
                        <button type="button" class="btn-secondary" id="mpReviewCancel" title="<?= te('Revenir au prompt enregistré') ?>"><?= te('Annuler') ?></button>
                        <button type="button" class="btn-primary" id="mpReviewSave"><?= te('Enregistrer') ?></button>
                    </span>
                    <?php if (!$pfReadOnly): ?>
                    <span class="kbx-saved" id="mpAutosaveHint"><?php require ROOT . '/templates/partials/autosave-hint.php'; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($canDeleteProfile)): ?>
                        <?php /* « … » : la suppression, loin des réglages courants. */ ?>
                        <div class="kbf-more">
                            <button type="button" class="kbf-more-btn" id="pfMoreBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="pfMoreMenu"
                                    title="<?= te('Plus d\'actions') ?>" aria-label="<?= te('Plus d\'actions') ?>">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                            </button>
                            <div class="kbf-more-menu" id="pfMoreMenu" role="menu" hidden>
                                <button type="button" role="menuitem" class="kbf-more-item is-danger" id="mpDeleteBtn"><?= te('Supprimer cet assistant') ?></button>
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
            <a href="#consigne"><?= KbPages::icon('pencil', '') ?><span class="kbx-nl"><?= te('Consigne') ?></span></a>
            <a href="#modele"><?= KbPages::icon('robot', '') ?><span class="kbx-nl"><?= te('Modèle') ?></span></a>
            <a href="#capacites"><?= KbPages::icon('cog', '') ?><span class="kbx-nl"><?= te('Capacités') ?></span></a>
            <a href="#connaissances"><?= KbPages::icon('book', '') ?><span class="kbx-nl"><?= te('Connaissances') ?></span></a>
            <span class="kbx-nav-sep" aria-hidden="true"></span>
            <a href="#ensemble"><?= KbPages::icon('map', '') ?><span class="kbx-nl"><?= te('Vue d\'ensemble') ?></span></a>
        </nav>

        <div class="kbx-sections">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($pfReadOnly): ?>
    <div class="admin-readonly-banner" role="status"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><span><?= teb('{b}Lecture seule.{/b} Votre rôle vous permet de consulter cet assistant, pas de le modifier.') ?></span></div>
    <?php endif; ?>
    <?php if ($prefill !== null): ?>
    <div class="alert pf-prefill-note"><?= teb('Proposition préparée par MIA dans la conversation : relisez, ajustez ce qui doit l\'être, puis {b}Créer l’assistant{/b}. La carte de la conversation se fermera d\'elle-même.') ?></div>
    <?php endif; ?>

    <?php /* Enregistrement automatique en MODIFICATION seulement : créer un
             assistant reste un acte volontaire, avec son bouton. */ ?>
    <form method="POST" action="<?= htmlspecialchars($formAction) ?>" id="editProfileForm" enctype="multipart/form-data"
          class="pf-form" <?= ($isCreate || $pfReadOnly) ? '' : 'data-autosave' ?>>
        <?= Csrf::field() ?>
        <?php if ($pfReadOnly): ?><fieldset class="pf-readonly-fieldset" disabled><?php endif; ?>
        <?php if ($prefill !== null): ?>
        <input type="hidden" name="from_action" value="<?= (int)($prefill['from_action'] ?? 0) ?>">
        <?php endif; ?>

        <!-- Identité : nom, couleur, description, avatar, actif. -->
        <section class="kbx-sec" id="identite" aria-labelledby="pfSecIdentity">
            <div id="mpSectionIdentity">
            <h2 id="pfSecIdentity"><?= te('Identité') ?></h2>
            <p class="kbx-lead"><?= te('Comment il apparaît dans la liste des assistants et dans le chat.') ?></p>

            <div class="asx-name-row">
                <div class="form-group">
                    <label for="mpName"><?= te('Nom') ?> *</label>
                    <input type="text" name="name" id="mpName" class="form-input" value="<?= htmlspecialchars($pfName) ?>" required
                           placeholder="<?= te('Ex. : Juriste, Relances clients…') ?>">
                    <p class="profile-hint"><?= te('C’est lui qu’on tape après @ dans le chat.') ?></p>
                </div>
                <div class="form-group">
                    <label for="mpColor"><?= te('Couleur') ?></label>
                    <input type="color" name="color" id="mpColor" class="form-input asx-color" value="<?= htmlspecialchars($pfColor) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="mpDescription"><?= te('Description') ?></label>
                <input type="text" name="description" id="mpDescription" class="form-input"
                       value="<?= htmlspecialchars($pfDescription) ?>"
                       placeholder="<?= te('Ex. : Assistant juridique pour analyser des contrats') ?>">
                <p class="profile-hint"><?= te('Phrase courte : la liste des assistants l’affiche au survol de l’assistant. Pas envoyée au modèle.') ?></p>
            </div>

            <div class="form-group" style="margin:0">
                <label><?= te('Avatar') ?></label>
                <div class="avatar-picker" id="editAvatarPicker" data-avatar-picker>
                    <label class="avatar-opt avatar-opt-none" title="<?= te('Sans avatar : pastille de couleur avec l\'initiale du nom') ?>">
                        <input type="radio" name="avatar" id="mpAvatarNone" value="" <?= $pfAvatar === '' ? 'checked' : '' ?>>
                        <span class="avatar-img-wrap"><span class="avatar-none-symbol">—</span></span>
                        <span class="avatar-label"><?= te('Aucun') ?></span>
                    </label>
                    <?php if ($pfAvatarGenerated !== ''): ?>
                    <label class="avatar-opt" title="<?= te('Avatar créé par MIA pour cet assistant : il rejoindra la bibliothèque à la création') ?>">
                        <input type="radio" name="avatar" value="<?= htmlspecialchars(AssistantDraft::GENERATED_AVATAR_CHOICE) ?>" data-src="<?= htmlspecialchars($pfAvatarGenerated) ?>" <?= $pfAvatar === AssistantDraft::GENERATED_AVATAR_CHOICE ? 'checked' : '' ?>>
                        <span class="avatar-img-wrap"><img src="<?= htmlspecialchars($pfAvatarGenerated) ?>" alt="<?= te('Avatar créé par MIA') ?>"></span>
                        <span class="avatar-label"><?= te('Créé par MIA') ?></span>
                    </label>
                    <?php endif; ?>
                    <?php foreach ($assistantAvatars as $a): ?>
                    <label class="avatar-opt" title="<?= htmlspecialchars($a['name']) ?>">
                        <input type="radio" name="avatar" value="<?= htmlspecialchars($a['filename']) ?>" <?= $pfAvatar === (string)$a['filename'] ? 'checked' : '' ?>>
                        <span class="avatar-img-wrap"><img src="/assets/img/profils/<?= htmlspecialchars(rawurlencode($a['filename'])) ?>" alt="<?= htmlspecialchars($a['name']) ?>"></span>
                        <span class="avatar-label"><?= htmlspecialchars($a['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                    <button type="button" class="avatar-opt avatar-opt-add" id="mpAvatarAddBtn" aria-expanded="false"
                            title="<?= te('Uploader une nouvelle image (elle rejoint la bibliothèque partagée)') ?>">
                        <span class="avatar-img-wrap">＋</span>
                        <span class="avatar-label"><?= te('Nouveau') ?></span>
                    </button>
                </div>
                <div class="avatar-upload-inline" id="mpAvatarUploadRow" hidden>
                    <div class="form-group" style="margin:0;flex:1 1 180px">
                        <label class="avatar-upload-inline-label"><?= te('Image (PNG, JPG, GIF, WebP — max 2 Mo)') ?></label>
                        <input type="file" name="avatar_upload" id="mpAvatarUpload" class="form-input" accept="image/png,image/jpeg,image/gif,image/webp">
                    </div>
                    <div class="form-group" style="margin:0;flex:1 1 160px">
                        <label class="avatar-upload-inline-label"><?= te('Nom de l\'avatar') ?></label>
                        <input type="text" name="avatar_upload_name" id="mpAvatarUploadName" class="form-input" maxlength="120" placeholder="<?= te('Ex : Conseiller souriant') ?>">
                    </div>
                </div>
            </div>

            <div class="kbx-divider"></div>
            <div class="mp-active-toggle">
                <div class="kbx-row-text">
                    <span class="mp-active-text<?= $pfActive ? '' : ' is-off' ?>" id="mpActiveText"><?= $pfActive ? te('Actif') : te('Inactif') ?></span>
                    <small><?= te('Actif : visible et utilisable dans le chat. Inactif : masqué pour tous, réglages gardés.') ?></small>
                </div>
                <label class="ios-switch" title="<?= te('Actif = visible et utilisable dans le chat. Inactif = masqué pour tous les utilisateurs.') ?>">
                    <input type="checkbox" name="is_active" id="mpIsActive" value="1" form="editProfileForm" <?= $pfActive ? 'checked' : '' ?><?= $pfReadOnly ? ' disabled' : '' ?>>
                    <span class="ios-switch-slider"></span>
                </label>
            </div>
            </div>
        </section>

        <!-- Consigne : le prompt système. -->
        <section class="kbx-sec" id="consigne" aria-labelledby="pfSecPrompt">
            <div id="mpSectionPrompt">
            <h2 id="pfSecPrompt"><?= te('Consigne') ?></h2>
            <p class="kbx-lead"><?= te('Son rôle, son ton et ses règles : le modèle les reçoit au début de chaque conversation.') ?></p>
            <?php
                // Points de départ du prompt système (cf. PromptStarters) :
                // icônes des cartes, par nom — repli sur l'initiale du libellé.
                $starterIcons = [
                    'chat'        => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
                    'briefcase'   => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
                    'file-search' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
                    'life-buoy'   => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"/><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"/>',
                    'pen'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
                    'languages'   => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
                    'graduation'  => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
                    'lightbulb'   => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>',
                ];
                $sparkle = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 16l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/></svg>';
                $promptStarters    = $promptStarters    ?? [];
                $promptSkeleton    = $promptSkeleton    ?? '';
                $promptAiAvailable = !empty($promptAiAvailable);
            ?>
            <div class="form-group" id="mpPromptField" style="margin:0">
                <div class="pf-prompt-head">
                    <label for="mpSystemPrompt"><?= te('System prompt') ?> *</label>
                    <?php if ($promptAiAvailable): ?>
                    <div class="pf-prompt-tools" role="group" aria-label="<?= te('Aide à la rédaction') ?>">
                        <button type="button" class="pf-ai-btn" id="mpAiWriteBtn" data-mode="rediger" aria-pressed="false"
                                title="<?= te('Décrivez l\'assistant en quelques phrases ; le modèle par défaut de l\'application rédige un premier jet structuré.') ?>"><?= $sparkle ?> <?= te('Rédiger avec l\'IA') ?></button>
                        <button type="button" class="pf-ai-btn" id="mpAiImproveBtn" data-mode="ameliorer" aria-pressed="false"
                                title="<?= te('Restructure et précise le prompt déjà saisi, sans rien inventer ni retirer.') ?>"><?= te('Améliorer') ?></button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php /* Bibliothèque : ouverte face à un champ vide (création),
                         repliée sur une fiche existante. Un clic colle le modèle,
                         pose description et température seulement si elles sont
                         vides, et sélectionne le premier [crochet] à compléter. */ ?>
                <details class="pf-starters" id="mpStarters" <?= $isCreate && $pfPrompt === '' ? 'open' : '' ?>>
                    <summary>
                        <span><?= te('Partir d\'un modèle') ?></span>
                        <span class="pf-starters-sub"><?= htmlspecialchars(I18n::plural(count($promptStarters), '{n} point de départ, à adapter', '{n} points de départ, à adapter')) ?></span>
                        <span class="pf-starters-chosen" id="mpStarterChosen" hidden></span>
                    </summary>
                    <div class="pf-starter-grid">
                        <?php foreach ($promptStarters as $st): ?>
                        <button type="button" class="pf-starter-card" data-starter="<?= htmlspecialchars((string)$st['key']) ?>"
                                style="--starter-accent:<?= htmlspecialchars((string)($st['accent'] ?? '')) ?>"
                                title="<?= htmlspecialchars((string)$st['tagline']) ?>">
                            <span class="pf-starter-ic" aria-hidden="true">
                                <?php if (isset($starterIcons[$st['icon'] ?? ''])): ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $starterIcons[$st['icon']] ?></svg>
                                <?php else: ?>
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr((string)$st['label'], 0, 1))) ?>
                                <?php endif; ?>
                            </span>
                            <span class="pf-starter-title"><?= htmlspecialchars((string)$st['label']) ?></span>
                            <span class="pf-starter-desc"><?= htmlspecialchars((string)$st['tagline']) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </details>

                <?php if ($promptAiAvailable): ?>
                <div class="pf-ai-panel" id="mpAiPanel" hidden>
                    <div class="pf-ai-panel-head">
                        <strong id="mpAiPanelTitle"><?= te('Rédiger avec l\'IA') ?></strong>
                        <button type="button" class="pf-ai-close" id="mpAiClose" aria-label="<?= te('Fermer') ?>">×</button>
                    </div>
                    <p class="profile-hint" id="mpAiPanelHint" style="margin:0"></p>
                    <textarea id="mpAiBrief" class="form-textarea" rows="3" maxlength="<?= (int)PromptStarters::BRIEF_MAX ?>"
                              placeholder="<?= te('Ex. : Aide les commerciaux à préparer leurs rendez-vous : fiche client, questions à poser, objections probables. Ton direct, réponses courtes, jamais d\'engagement sur les prix.') ?>"></textarea>
                    <div class="pf-ai-actions">
                        <span class="pf-ai-status" id="mpAiStatus" aria-live="polite"></span>
                        <button type="button" class="btn-primary" id="mpAiGo" style="padding:8px 14px"><?= te('Générer') ?></button>
                    </div>
                </div>
                <?php endif; ?>

                <textarea name="system_prompt" id="mpSystemPrompt" class="form-textarea" rows="14" required
                          placeholder="<?= htmlspecialchars($promptSkeleton) ?>"><?= htmlspecialchars($pfPrompt) ?></textarea>
                <div class="pf-prompt-foot">
                    <p class="profile-hint" style="margin:0"><?= teb('Instructions injectées en tête du prompt. Les modèles suivent six sections — rôle, mission, ton et format, règles et limites, sources, cas limites — gardez-les, complétez les {b}[crochets]{/b}, supprimez ce qui ne s\'applique pas.') ?></p>
                    <span class="pf-prompt-foot-actions">
                        <span class="pf-review-inline" id="mpReviewInline" hidden><?= teb('Non enregistré — relisez, puis {b}Enregistrer{/b} en bas de page') ?></span>
                        <button type="button" class="pf-undo" id="mpPromptUndo" hidden>↶ <?= te('Revenir au texte précédent') ?></button>
                    </span>
                </div>
            </div>

            </div>
        </section>

        <!-- Modèle : qui répond, et avec quelle température. -->
        <section class="kbx-sec" id="modele" aria-labelledby="pfSecModel">
            <div id="mpSectionModel">
            <h2 id="pfSecModel"><?= te('Modèle') ?></h2>
            <p class="kbx-lead"><?= te('Le modèle qui lui donne sa voix, et sa liberté de ton.') ?></p>
            <div class="kbx-fields">
                <div class="form-group" id="mpLlmField">
                    <label for="mpDefaultLlm"><?= te('LLM par défaut') ?></label>
                    <select name="default_llm" id="mpDefaultLlm" class="form-select">
                        <option value=""><?= !empty($appDefaultLlmLabel) ? te('Défaut application (actuellement : {modele})', ['modele' => (string)$appDefaultLlmLabel]) : te('Défaut application') ?></option>
                        <?php foreach ($llms as $k => $v): ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= $pfLlm === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="profile-hint"><?= teb('Modèle {b}imposé{/b} à toutes les conversations de cet assistant : le sélecteur de modèle est masqué, l\'utilisateur ne peut pas en changer. Le modifier ici s\'applique aussi aux conversations déjà ouvertes. « Défaut application » = l\'assistant suit le modèle par défaut de MIA, y compris quand l\'administrateur le change.') ?></p>
                </div>
                <div class="form-group" id="mpTempField">
                    <label for="mpTemperature"><?= te('Température') ?> <span class="asx-label-note">— <?= te('vide = défaut') ?></span></label>
                    <div class="asx-temp-row">
                        <?php /* Le curseur n'a pas de `name` : il pilote le champ
                                 nombre, seul envoyé. Une affectation par script
                                 n'émet aucun évènement — sans le `input`
                                 explicite, glisser le curseur ne déclencherait
                                 aucun enregistrement. */ ?>
                        <input type="range" id="mpTempRange" min="0" max="2" step="0.1"
                               value="<?= $pfTemp !== null ? htmlspecialchars((string)$pfTemp) : '0.7' ?>"
                               oninput="const t=document.getElementById('mpTemperature');t.value=parseFloat(this.value).toFixed(1);t.dispatchEvent(new Event('input',{bubbles:true}))">
                        <input type="number" name="temperature" id="mpTemperature" min="0" max="2" step="0.1"
                               value="<?= $pfTemp !== null ? htmlspecialchars((string)$pfTemp) : '' ?>"
                               placeholder="—" class="form-input"
                               oninput="if(this.value!='') document.getElementById('mpTempRange').value=this.value">
                        <button type="button" id="mpTempReset" class="btn-secondary"
                                title="<?= te('Vide le champ : aucune température n\'est envoyée à l\'API (le modèle utilise sa valeur par défaut). Obligatoire pour les modèles GPT-5.x qui refusent ce paramètre.') ?>"
                                onclick="const t=document.getElementById('mpTemperature');t.value='';document.getElementById('mpTempRange').value=0.7;t.dispatchEvent(new Event('change',{bubbles:true}))">↺ <?= te('Réinitialiser') ?></button>
                    </div>
                    <div class="asx-temp-scale">
                        <span>0 — <?= te('Précis') ?></span><span>1 — <?= te('Équilibré') ?></span><span>2 — <?= te('Créatif') ?></span>
                    </div>
                    <p class="profile-hint"><?= te('Vide / réinitialisé = aucune température envoyée à l\'API (défaut du modèle) — requis pour les modèles de raisonnement type GPT-5.x.') ?></p>
                    <p id="mpTempNote" class="profile-hint asx-temp-note" style="display:none">
                        <?= teb('{b}Ce modèle ne gère pas la température.{/b} Le paramètre est ignoré par l\'API — l\'assistant n\'envoie aucune valeur. Le curseur est désactivé.') ?>
                    </p>
                </div>
            </div>
            </div>
        </section>

        <!-- Capacités : ce qu'il déclenche lui-même en répondant. -->
        <section class="kbx-sec" id="capacites" aria-labelledby="pfSecTools">
            <div id="mpSectionTools">
            <h2 id="pfSecTools"><?= te('Capacités') ?></h2>
            <p class="kbx-lead"><?= te('Ce qu’il déclenche lui-même en répondant — il faut un modèle qui sait appeler des outils. Chaque outil reste soumis aux autorisations de groupe de la personne.') ?></p>
            <?php if (empty($imageTools) && empty($n8nTools) && empty($gammaTools)): ?>
                <p class="kbx-muted asx-empty-tools"><?= te('Aucun outil n’est ouvert pour l’instant : un administrateur en ajoute dans Capacités → Outils. Sans outil, l’assistant rédige et répond.') ?></p>
            <?php endif; ?>
            <?php if (!empty($imageTools)): ?>
            <div class="form-group asx-group" id="mpToolsGroup">
                <h3 class="asx-group-title"><?= te('Génération d’image') ?></h3>
                <p class="profile-hint asx-group-hint"><?= teb('Les {b}images{/b} des documents de référence et des bases de connaissances liées servent de modèles : l\'assistant choisit celles que la demande vise (4 au plus) et le générateur les reprend fidèlement — un personnage, un produit, un logo. Il faut un outil qui sait partir d\'une image (GPT Image, Nano Banana, modèles GPT-5.x natifs).') ?></p>
                <div class="mp-opt-list">
                    <?php foreach ($imageTools as $tool): ?>
                    <label class="mp-opt-row">
                        <input type="checkbox" name="tool_ids[]" value="<?= (int)$tool['id'] ?>" <?= in_array((int)$tool['id'], $boundToolIds, true) ? 'checked' : '' ?>
                               data-image-caps="<?= htmlspecialchars((string)json_encode(ImageOptions::capabilitiesOf($tool)), ENT_QUOTES) ?>">
                        <span class="mp-opt-meta">
                            <span class="mp-opt-name"><?= htmlspecialchars($tool['name']) ?></span>
                            <span class="mp-opt-sub"><?= htmlspecialchars($tool['provider']) ?> / <?= htmlspecialchars($tool['model']) ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php
                // Options des images de CET assistant : elles précisent celles
                // de l'outil (vide = ce que règle l'outil). Chaque réglage
                // n'apparaît que si un outil coché l'accepte (data-image-caps).
                $pfImageOpts = $isCreate ? ImageOptions::normalize([]) : ImageOptions::ofProfile($profile);
                $imgLabels   = ImageOptions::labels();
                ?>
                <input type="hidden" name="image_options_form" value="1">
                <div class="tool-img-options" id="mpImageOptions" style="margin-top:10px" hidden>
                    <div class="tool-img-options-title"><?= te('Options des images de cet assistant') ?></div>
                    <label class="tool-img-transparent" id="mpImgTransparentWrap">
                        <input type="checkbox" name="image_transparent" value="1" id="mpImgTransparent" <?= $pfImageOpts['background'] === 'transparent' ? 'checked' : '' ?>>
                        <span><?= te('Fond transparent') ?></span>
                    </label>
                    <p class="tool-img-hint" id="mpImgTransparentHint"><?= te('Le sujet se détache sur un fond vide : logo, pictogramme, produit détouré. Le fichier passe en PNG (ou WebP), seuls formats qui gardent la transparence.') ?></p>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <div class="form-group" style="margin:0;flex:0 0 200px" id="mpImgOutputWrap">
                            <label for="mpImgOutput"><?= te('Format du fichier') ?></label>
                            <select name="image_output_format" id="mpImgOutput" class="form-select">
                                <option value=""><?= te('Celui de l’outil') ?></option>
                                <?php foreach (ImageOptions::OUTPUT_FORMATS as $of): ?>
                                <option value="<?= $of ?>" <?= $pfImageOpts['output_format'] === $of ? 'selected' : '' ?>><?= htmlspecialchars($imgLabels['output_formats'][$of]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 200px" id="mpImgFormatWrap">
                            <label for="mpImgFormat"><?= te('Format') ?></label>
                            <select name="image_format" id="mpImgFormat" class="form-select">
                                <option value=""><?= te('Celui de l’outil') ?></option>
                                <?php foreach (ImageOptions::FORMATS as $ff): ?>
                                <option value="<?= $ff ?>" <?= $pfImageOpts['format'] === $ff ? 'selected' : '' ?>><?= htmlspecialchars($imgLabels['formats'][$ff]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="tool-img-hint" style="margin:8px 0 0"><?= te('Laissés vides, ces réglages suivent ceux de l’outil. Non cochée, la case garde le fond que règle l’outil.') ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($n8nTools)): ?>
            <div class="form-group asx-group" id="mpN8nGroup">
                <h3 class="asx-group-title"><?= te('Automatisations n8n') ?></h3>
                <p class="profile-hint asx-group-hint">
                    <?= teb('Instances n8n que l\'assistant peut interroger et déclencher pendant la conversation (function-calling, LLM compatible requis). Le périmètre reste borné par le tag de l\'outil, et {b}chaque exécution est soumise à l\'approbation de l\'utilisateur{/b}.') ?>
                </p>
                <div class="mp-opt-list">
                    <?php foreach ($n8nTools as $tool): $cfgN8n = json_decode((string)($tool['config_json'] ?? ''), true) ?: []; ?>
                    <label class="mp-opt-row">
                        <input type="checkbox" name="tool_ids[]" value="<?= (int)$tool['id'] ?>" <?= in_array((int)$tool['id'], $boundToolIds, true) ? 'checked' : '' ?>>
                        <span class="mp-opt-meta">
                            <span class="mp-opt-name"><?= htmlspecialchars((string)$tool['name']) ?></span>
                            <span class="mp-opt-sub">
                                <?= htmlspecialchars((string)(parse_url((string)($cfgN8n['base_url'] ?? ''), PHP_URL_HOST) ?: t('instance non configurée'))) ?>
                                · tag <?= htmlspecialchars((string)($cfgN8n['tag'] ?? '—')) ?>
                            </span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($gammaTools)): ?>
            <div class="form-group asx-group" id="mpGammaGroup">
                <h3 class="asx-group-title"><?= te('Présentations Gamma') ?></h3>
                <p class="profile-hint asx-group-hint">
                    <?= teb('Comptes gamma.app avec lesquels l\'assistant peut générer des présentations pendant la conversation (function-calling, LLM compatible requis). {b}Chaque génération est soumise à l\'approbation de l\'utilisateur{/b} et consomme des crédits Gamma (service payant).') ?>
                </p>
                <div class="mp-opt-list">
                    <?php foreach ($gammaTools as $tool): $cfgGamma = json_decode((string)($tool['config_json'] ?? ''), true) ?: []; ?>
                    <label class="mp-opt-row">
                        <input type="checkbox" name="tool_ids[]" value="<?= (int)$tool['id'] ?>" <?= in_array((int)$tool['id'], $boundToolIds, true) ? 'checked' : '' ?>>
                        <span class="mp-opt-meta">
                            <span class="mp-opt-name"><?= htmlspecialchars((string)$tool['name']) ?></span>
                            <span class="mp-opt-sub">
                                gamma.app<?= !empty($cfgGamma['theme_id']) ? ' · ' . te('thème {theme}', ['theme' => (string)$cfgGamma['theme_id']]) : '' ?><?= !empty($cfgGamma['export_as']) ? ' · export ' . htmlspecialchars(strtoupper((string)$cfgGamma['export_as'])) : '' ?>
                            </span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </section>

        <!-- Connaissances : bases et documents de référence. -->
        <section class="kbx-sec" id="connaissances" aria-labelledby="pfSecKnowledge">
            <div id="mpSectionKnowledge">
            <h2 id="pfSecKnowledge"><?= te('Connaissances') ?></h2>
            <p class="kbx-lead"><?= te('Ce qu’il consulte pour répondre : des bases de connaissances, et ses propres documents.') ?></p>
            <?php
                // Lien vers les bases, posé dans une phrase traduite ENTIÈRE :
                // `{lien}` reste littéral dans te() (aucune variable passée) et
                // est remplacé APRÈS l'échappement.
                $kbAdminLink = '<a href="/admin/knowledge-bases">' . te('Bases de connaissances') . '</a>';
            ?>
            <div class="form-group asx-group is-first" id="mpKbsField">
                <h3 class="asx-group-title"><?= te('Bases de connaissances') ?></h3>
                <?php if (empty($knowledgeBases)): ?>
                    <p class="profile-hint" style="margin:0">
                        <?= str_replace('{lien}', $kbAdminLink, te('Aucune base disponible. Créez-en une dans {lien} — collections de documents vectorisés, partageables entre plusieurs assistants.')) ?>
                    </p>
                <?php else: ?>
                    <div class="mp-opt-list">
                        <?php foreach ($knowledgeBases as $kbOpt): ?>
                        <?php
                            $kbOptSystem = (int)($kbOpt['is_system'] ?? 0) === 1;
                            $kbDocN      = (int)($kbOpt['doc_count'] ?? 0);
                            $kbInactive  = empty($kbOpt['is_active']);
                            $kbChecked   = in_array((int)$kbOpt['id'], $boundKbIds, true);

                            // Nature de la base : la même tuile que les pages
                            // « Bases de connaissances » (logo de la source, livre ou table).
                            $kbSyncActive = !empty($kbOpt['sync_enabled']) && !empty($kbOpt['sync_provider']);
                            $kbOptLive    = KnowledgeBase::isRemote($kbOpt);
                            $kbOptTable   = KnowledgeBase::isTable($kbOpt);
                            if ($kbOptTable) {
                                $kbSrcTitle = t('Table : ses lignes sont lues telles quelles (compter, filtrer, croiser), jamais découpées en extraits');
                            } elseif ($kbOptLive) {
                                // Base en direct : elle suit la PERSONNE, pas l'assistant —
                                // seuls les membres de ses groupes la font interroger.
                                $kbSrcTitle = t('Cerveau MyBrain, interrogé en direct — seulement pour les personnes qui y ont accès (partage de la base), même à travers cet assistant');
                            } elseif ($kbSyncActive && ($kbOpt['sync_provider'] ?? '') === 'sharepoint') {
                                $kbSrcTitle = t('Source : dossier SharePoint');
                            } elseif ($kbSyncActive) {
                                $kbSrcTitle = t('Source : dossier Google Drive');
                            } else {
                                $kbSrcTitle = t('Source : fichiers uploadés manuellement');
                            }
                        ?>
                        <label class="mp-opt-row<?= $kbOptSystem ? ' is-locked' : '' ?>"
                               <?= $kbOptSystem ? 'title="' . te('Base système : sa liaison à l\'assistant d\'aide de MIA est posée automatiquement et ne se modifie pas ici.') . '"' : '' ?>>
                            <input type="checkbox" name="kb_ids[]" value="<?= (int)$kbOpt['id'] ?>"<?= $kbOptSystem ? ' disabled' : '' ?><?= $kbChecked && !$kbOptSystem ? ' checked' : '' ?>>
                            <span class="asx-kb-tile" title="<?= htmlspecialchars($kbSrcTitle) ?>"><?= KbPages::tile($kbOpt) ?></span>
                            <span class="mp-opt-meta">
                                <span class="mp-opt-name"><?= htmlspecialchars($kbOpt['name']) ?></span>
                                <span class="mp-opt-sub"><?= $kbOptTable
                                    ? htmlspecialchars(t('Table') . ' · ' . WorkTableView::rowsLabel((int)($kbOpt['table_rows'] ?? 0)))
                                    : ($kbOptLive ? te('MyBrain · pour ses groupes seulement') : htmlspecialchars(I18n::plural($kbDocN, '{n} doc', '{n} docs'))) ?></span>
                            </span>
                            <span class="mp-opt-tags">
                                <?php if ($kbInactive): ?><span class="mp-opt-badge mp-opt-badge-off"><?= te('Inactive') ?></span><?php endif; ?>
                                <?php if ($kbOptSystem): ?><span class="mp-opt-badge mp-opt-badge-sys"><?= te('Base système') ?></span><?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="profile-hint"><?= str_replace('{lien}', $kbAdminLink, te('Bases interrogées à chaque message : les extraits pertinents sont injectés dans le prompt. Gérées dans {lien}.')) ?></p>
                <?php endif; ?>
            </div>

            <div id="mpDocumentsSection" class="asx-group"
                 data-rag-min-budget="<?= (int)$ragMinBudget ?>">
                <div class="asx-group-head">
                    <h3 class="asx-group-title"><?= te('Documents de référence') ?></h3>
                    <span id="mpDocumentsTokenTotal" class="asx-group-meta"></span>
                </div>
                <p id="mpDocsBudgetWarn" style="display:none;margin:0 0 8px;font-size:11px;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:6px 8px"></p>
                <p class="profile-hint" style="margin:0 0 10px"><?= te('Fichiers attachés à l\'assistant (PDF, DOCX, XLSX, PPTX, ODT/ODS/ODP, EPUB, HTML, RTF, TXT, MD, CSV, JSON, XML, code, images). Le texte extrait alimente le RAG de l\'assistant ; les images sont montrées au modèle et servent de références à l\'outil image. Max 20 fichiers, 20 Mo/fichier.') ?></p>

                <?php if ($isCreate): ?>
                <!-- Création : input multiple soumis avec le formulaire (pas d'AJAX, l'assistant n'existe pas encore). -->
                <div id="mpDocsCreate">
                    <label class="kb-drop kbf-drop asx-drop" id="mpDocDrop">
                        <input type="file" name="documents[]" id="mpDocsCreateInput" multiple
                               accept="<?= htmlspecialchars($acceptDocs) ?>">
                        <span class="kb-drop-ico" aria-hidden="true"><?= KbPages::icon('upload', '') ?></span>
                        <span class="kb-drop-text"><?= teb('{b}Déposez vos fichiers ici{/b} ou cliquez pour les choisir') ?></span>
                    </label>
                    <ul class="kb-pending-files asx-pending" id="mpPendingFiles" hidden
                        data-remove="<?= te('Retirer « {nom} »') ?>"
                        data-units="<?= I18n::locale() === 'en' ? 'KB|MB' : 'Ko|Mo' ?>"></ul>
                    <p class="profile-hint" style="margin-top:6px"><?= te('Les fichiers seront attachés à la création de l\'assistant.') ?></p>
                </div>
                <?php else: ?>
                <!-- Édition : gestion AJAX (l'assistant existe). -->
                <div id="mpDocsEdit">
                    <label class="kb-drop kbf-drop asx-drop" id="mpDocDrop">
                        <input type="file" id="mpDocFileInput" multiple data-autosave-skip
                               accept="<?= htmlspecialchars($acceptDocs) ?>">
                        <span class="kb-drop-ico" aria-hidden="true"><?= KbPages::icon('upload', '') ?></span>
                        <span class="kb-drop-text"><?= teb('{b}Déposez vos fichiers ici{/b} ou cliquez pour les choisir') ?></span>
                    </label>
                    <p class="asx-drop-busy" id="mpDocBusy" hidden data-label="<?= te('Envoi de « {nom} »…') ?>"></p>
                    <p class="profile-doc-error" id="mpDocError" style="display:none"></p>
                    <div id="mpDocumentsList" style="display:flex;flex-direction:column;gap:6px"></div>
                </div>
                <?php endif; ?>
            </div>
            </div>
        </section>

        <?php if ($pfReadOnly): ?></fieldset><?php endif; ?>
    </form>

    <!-- Vue d'ensemble : le schéma de l'assistant, recomposé à chaque geste.
         Un élément du schéma mène au réglage qui le règle. -->
    <section class="kbx-sec" id="ensemble" aria-labelledby="pfSecEnsemble">
        <div class="kbx-sec-h">
            <div>
                <h2 id="pfSecEnsemble"><?= te('Vue d\'ensemble') ?></h2>
                <p class="kbx-lead sched-diagram-hint"><?= $isCreate
                    ? te('Ce que sera l’assistant, mis à jour à chaque réglage. Touchez un élément pour aller le régler.')
                    : te('Son modèle, sa consigne, ce qu’il connaît, ses outils et qui le voit. Touchez un élément pour aller le régler.') ?></p>
            </div>
        </div>
        <div id="pfDiagramPanel" hidden>
            <div class="kbx-diagram sched-diagram" id="pfDiagram"></div>
        </div>
    </section>

    <?php if ($isCreate): ?>
        <div class="kbx-endbar">
            <span><?= te('Vous pourrez tout modifier ensuite, depuis sa page.') ?></span>
            <a href="/admin/profiles" class="kbx-btn"><?= te('Annuler') ?></a>
            <button type="submit" class="kbx-btn is-primary" form="editProfileForm"><?= te('Créer l’assistant') ?></button>
        </div>
    <?php endif; ?>
        </div><!-- /.kbx-sections -->
    </div><!-- /.kbx-layout -->

    <?php if (!$isCreate && !empty($canDeleteProfile)): ?>
    <form method="POST" action="/admin/profiles/<?= (int)$profile['id'] ?>/delete" id="deleteProfileForm" hidden><?= Csrf::field() ?></form>
    <?php endif; ?>
</div>
</div>

<script>
// Schéma de l'assistant (à droite de la fiche) : catalogue calculé par le
// serveur (AssistantDiagram), dessiné par diagram.js + assistant-diagram.js —
// chargés avant le script de la page, qui le recompose à chaque geste.
window.ASSISTANT_FORM_DIAGRAM = <?= json_encode($diagram, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= (int)$diagramJsVersion ?>"></script>
<script src="/assets/js/assistant-diagram.js?v=<?= (int)$assistantDiagramJsVersion ?>"></script>
<script>
(function () {
    // Textes : traduits par le serveur (tj). fill() n'y pose que les
    // variables {nom} — une phrase reste entière dans le dictionnaire.
    const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => ((vars && k in vars) ? String(vars[k]) : m));
    // Nombres au format de la langue de la page (inchangé en français ; même
    // règle que le schéma, AssistantDiagram::numberLocale).
    const NUM_LOCALE = document.documentElement.lang === 'en' ? 'en-US' : 'fr-FR';

    // ── Support température par LLM (source : catalogue côté serveur) ──
    <?php
        $llmTempSupport = [];
        foreach ($llms as $llmKey => $_llmLabel) {
            $llmTempSupport[$llmKey] = \LLMFactory::supportsTemperature($llmKey);
        }
        // « Défaut application » : le modèle par défaut actuel décide — comme
        // au chat, et comme le schéma de l'assistant (une température qu'il
        // n'accepte pas serait ignorée).
        $llmTempSupport[''] = !empty($appDefaultLlm) ? \LLMFactory::supportsTemperature((string)$appDefaultLlm) : true;
    ?>
    const LLM_TEMP_SUPPORT = <?= json_encode($llmTempSupport, JSON_UNESCAPED_UNICODE) ?>;
    const PROFILE_ID   = <?= $isCreate ? 'null' : (int)$profile['id'] ?>;
    const INITIAL_DOCS = <?= json_encode(array_values($profileDocs), JSON_UNESCAPED_UNICODE) ?>;

    const llmSel  = document.getElementById('mpDefaultLlm');
    const tempRange  = document.getElementById('mpTempRange');
    const tempNumber = document.getElementById('mpTemperature');
    const tempReset  = document.getElementById('mpTempReset');
    const tempNote   = document.getElementById('mpTempNote');

    function applyTempSupport() {
        if (!llmSel) return;
        const supported = LLM_TEMP_SUPPORT[llmSel.value] !== false;
        [tempRange, tempNumber, tempReset].forEach(el => { if (el) el.disabled = !supported; });
        if (tempRange)  tempRange.style.opacity  = supported ? '' : '0.45';
        if (tempNumber) {
            tempNumber.style.opacity = supported ? '' : '0.45';
            if (!supported) tempNumber.value = '';
        }
        if (tempNote) tempNote.style.display = supported ? 'none' : '';
    }
    if (llmSel) llmSel.addEventListener('change', applyTempSupport);
    applyTempSupport();

    // ── Interrupteur « Actif » : libellé Actif / Inactif ──
    const mpActive     = document.getElementById('mpIsActive');
    const mpActiveText = document.getElementById('mpActiveText');
    if (mpActive) mpActive.addEventListener('change', () => {
        if (!mpActiveText) return;
        mpActiveText.textContent = mpActive.checked ? <?= tj('Actif') ?> : <?= tj('Inactif') ?>;
        mpActiveText.classList.toggle('is-off', !mpActive.checked);
    });

    // ── Tuile « + » du picker d'avatars : révèle la rangée d'upload ──
    const avatarAddBtn    = document.getElementById('mpAvatarAddBtn');
    const avatarUploadRow = document.getElementById('mpAvatarUploadRow');
    if (avatarAddBtn) avatarAddBtn.addEventListener('click', () => {
        const show = avatarUploadRow ? avatarUploadRow.hidden : false;
        if (avatarUploadRow) avatarUploadRow.hidden = !show;
        avatarAddBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (show) document.getElementById('mpAvatarUpload')?.focus();
    });

    // ── Suppression de l'assistant (édition) ──
    const deleteBtn  = document.getElementById('mpDeleteBtn');
    const deleteForm = document.getElementById('deleteProfileForm');
    if (deleteBtn && deleteForm) deleteBtn.addEventListener('click', () => {
        if (confirm(<?= tj('Supprimer cet assistant définitivement ?') ?>)) deleteForm.submit();
    });

    // ── Après un enregistrement automatique ──
    // La page ne se recharge pas (le titre de la barre du haut suit déjà le
    // nom, assistant-fiche.js) ; mais un avatar tout juste téléversé n'a pas
    // encore de vignette dans la grille (elle est rendue côté serveur) : seul
    // cas qui justifie un rechargement complet.
    const editForm = document.getElementById('editProfileForm');
    if (editForm) editForm.addEventListener('mia:autosaved', (e) => {
        const avatar = e.detail?.data?.avatar;
        if (!avatar) return;
        const known = Array.from(document.querySelectorAll('input[name="avatar"]'))
            .some((radio) => radio.value === avatar);
        if (!known) { window.location.reload(); }
    });

    // ── Points de départ du prompt système : bibliothèque + aide IA ──
    // Un seul point d'entrée, applyPrompt() : pose le texte, mémorise le
    // précédent (↶), émet `input` (l'auto-save de la fiche ne voit pas une
    // affectation par script) et sélectionne le premier [crochet] à remplir.
    (function () {
        const PROMPT_STARTERS = <?= json_encode(array_column($promptStarters, null, 'key'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const PLACEHOLDER_RE  = /\[[^\[\]\n]{2,80}\]/;
        const AI_MAX_BRIEF    = <?= (int)PromptStarters::BRIEF_MAX ?>;

        const promptEl   = document.getElementById('mpSystemPrompt');
        const undoBtn    = document.getElementById('mpPromptUndo');
        const starters   = document.getElementById('mpStarters');
        const chosenEl   = document.getElementById('mpStarterChosen');
        const descEl     = document.getElementById('mpDescription');
        const nameEl     = document.getElementById('mpName');
        if (!promptEl) return;

        let previous       = null;  // texte d'avant la dernière insertion
        let previousIntact = true;  // ce texte était-il posé par script (ou vide) ? sinon : écrit par le créateur
        let lastApplied    = '';    // dernier texte posé par script (modèle ou IA)
        let selectedKey    = null;

        function emitInput(el) {
            el.dispatchEvent(new Event('input',  { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // ── Mode relecture (édition seulement : la création a son bouton Créer) ──
        // Un texte posé par script n'est jamais enregistré tout seul : l'auto-
        // save du formulaire ENTIER est suspendu (`data-autosave="off"`, relu
        // par autosave.js à chaque évènement) jusqu'à Enregistrer ou Annuler.
        // Le formulaire étant toujours posté en entier, laisser un autre champ
        // s'enregistrer emporterait le prompt non relu avec lui.
        const form          = document.getElementById('editProfileForm');
        const reviewable    = !!(form && form.hasAttribute('data-autosave'));
        const reviewBar     = document.getElementById('mpReviewBar');
        const reviewSave    = document.getElementById('mpReviewSave');
        const reviewCancel  = document.getElementById('mpReviewCancel');
        const reviewInline  = document.getElementById('mpReviewInline');
        const autosaveHint  = document.getElementById('mpAutosaveHint');
        let savedPrompt  = promptEl.value;   // dernier état confirmé par le serveur
        let reviewing    = false;
        let otherChanges = false;            // un AUTRE champ a bougé pendant la relecture

        function setReviewUi(on) {
            if (reviewBar)    reviewBar.hidden    = !on;
            if (reviewInline) reviewInline.hidden = !on;
            if (autosaveHint) autosaveHint.hidden = on;
            promptEl.classList.toggle('is-review', on);
        }

        function enterReview() {
            if (!reviewable || reviewing) return;
            reviewing = true;
            otherChanges = false;
            form.dataset.autosave = 'off';
            setReviewUi(true);
        }

        function exitReview(save) {
            if (!reviewing) return;
            reviewing = false;
            form.dataset.autosave = '';
            setReviewUi(false);
            if (!window.MiaAutosave) return;
            // Enregistrer : tout part. Annuler : seules les modifications faites
            // entre-temps sur d'autres champs partent (le prompt est revenu à
            // l'état enregistré, rien à envoyer pour lui).
            if (save || otherChanges) window.MiaAutosave.save(form, 0);
        }

        if (reviewable) {
            form.addEventListener('mia:autosaved', () => { savedPrompt = promptEl.value; });
            ['input', 'change'].forEach((type) => document.addEventListener(type, (e) => {
                if (!reviewing || e.target === promptEl) return;
                const f = e.target && (e.target.form || (e.target.closest && e.target.closest('form')));
                if (f === form) otherChanges = true;
            }, true));
            if (reviewSave) reviewSave.addEventListener('click', () => exitReview(true));
            if (reviewCancel) reviewCancel.addEventListener('click', () => {
                promptEl.value = savedPrompt;
                lastApplied = '';
                previous = null;
                if (undoBtn) undoBtn.hidden = true;
                markSelected(null);
                exitReview(false);
                promptEl.focus();
            });
            // Quitter la page perdrait la proposition sans un mot : l'auto-save,
            // suspendu, ne sait rien de ce texte.
            window.addEventListener('beforeunload', (e) => {
                if (!reviewing) return;
                e.preventDefault();
                e.returnValue = '';
            });
        }

        // Le texte courant vaut-il la peine d'être protégé ? Oui s'il est non
        // vide ET qu'il diffère de ce qu'un clic a posé (le créateur l'a écrit
        // ou retouché). Remplacer un modèle intact par un autre ne demande rien.
        function isUserText() {
            const cur = promptEl.value.trim();
            return cur !== '' && cur !== lastApplied.trim();
        }

        function selectFirstPlaceholder() {
            const m = PLACEHOLDER_RE.exec(promptEl.value);
            promptEl.focus();
            if (m) {
                promptEl.setSelectionRange(m.index, m.index + m[0].length);
                // Amène la sélection dans la zone visible (le champ démarre en haut).
                const before = promptEl.value.slice(0, m.index).split('\n').length;
                const lineH  = parseFloat(getComputedStyle(promptEl).lineHeight) || 20;
                promptEl.scrollTop = Math.max(0, (before - 3) * lineH);
            } else {
                promptEl.setSelectionRange(0, 0);
                promptEl.scrollTop = 0;
            }
        }

        function applyPrompt(text, opts) {
            opts = opts || {};
            if (!opts.force && isUserText()
                && !confirm(<?= tj('Remplacer le prompt actuel ? Vous pourrez revenir au texte précédent.') ?>)) {
                return false;
            }
            previousIntact = !isUserText();
            previous = promptEl.value;
            promptEl.value = text;
            lastApplied = text;
            enterReview();          // avant les évènements : l'auto-save ne doit rien voir partir
            emitInput(promptEl);
            if (undoBtn) undoBtn.hidden = previous.trim() === '' || previous === text;
            selectFirstPlaceholder();
            return true;
        }

        function markSelected(key) {
            selectedKey = key;
            document.querySelectorAll('.pf-starter-card').forEach((card) => {
                card.classList.toggle('is-selected', card.dataset.starter === key);
            });
            if (chosenEl) {
                const st = key ? PROMPT_STARTERS[key] : null;
                chosenEl.textContent = st ? ('✓ ' + st.label) : '';
                chosenEl.hidden = !st;
            }
        }

        if (undoBtn) undoBtn.addEventListener('click', () => {
            if (previous === null) return;
            const cur       = promptEl.value;
            const curIntact = !isUserText();
            promptEl.value = previous;
            // Un texte du créateur restauré redevient protégé (lastApplied vide
            // → isUserText vrai) ; un modèle intact restauré reste remplaçable.
            lastApplied    = previousIntact ? previous : '';
            previous       = cur;
            previousIntact = curIntact;
            emitInput(promptEl);
            markSelected(null);
            // Revenu au texte enregistré : plus rien à relire.
            if (reviewing && promptEl.value === savedPrompt) exitReview(false);
            promptEl.focus();
        });

        // Le créateur retouche le texte : le modèle n'est plus « tel quel ».
        promptEl.addEventListener('input', () => {
            if (selectedKey && promptEl.value !== PROMPT_STARTERS[selectedKey].prompt) markSelected(null);
        });

        document.querySelectorAll('.pf-starter-card').forEach((card) => {
            card.addEventListener('click', () => {
                const st = PROMPT_STARTERS[card.dataset.starter];
                if (!st) return;
                if (!applyPrompt(st.prompt)) return;
                markSelected(card.dataset.starter);

                // Suggestions : seulement dans un champ encore vide.
                if (descEl && descEl.value.trim() === '' && st.description) {
                    descEl.value = st.description;
                    emitInput(descEl);
                }
                const tNum = document.getElementById('mpTemperature');
                const tRng = document.getElementById('mpTempRange');
                if (tNum && !tNum.disabled && tNum.value === '' && typeof st.temperature === 'number') {
                    tNum.value = st.temperature.toFixed(1);
                    if (tRng) tRng.value = String(st.temperature);
                    emitInput(tNum);
                }
                // Le champ prend la place : la bibliothèque se replie, son
                // résumé garde le nom du modèle choisi.
                if (starters) starters.open = false;
            });
        });

        // ── Aide IA (présente seulement si un modèle par défaut est appelable) ──
        const panel     = document.getElementById('mpAiPanel');
        const writeBtn  = document.getElementById('mpAiWriteBtn');
        const improveBtn = document.getElementById('mpAiImproveBtn');
        if (!panel || !writeBtn || !improveBtn) return;

        const titleEl  = document.getElementById('mpAiPanelTitle');
        const hintEl   = document.getElementById('mpAiPanelHint');
        const briefEl  = document.getElementById('mpAiBrief');
        const statusEl = document.getElementById('mpAiStatus');
        const goBtn    = document.getElementById('mpAiGo');
        const closeBtn = document.getElementById('mpAiClose');
        let mode = 'rediger';
        let busy = false;

        const COPY = {
            rediger: {
                title: <?= tj('Rédiger avec l\'IA') ?>,
                hint:  <?= tj('Décrivez l\'assistant en quelques phrases : pour qui, ce qu\'il doit faire, ce qu\'il doit éviter. Le nom et la description déjà saisis sont pris en compte. Le texte obtenu est un premier jet : relisez-le et complétez les [crochets].') ?>,
                go:    <?= tj('Générer') ?>,
                placeholder: <?= tj('Ex. : Aide les commerciaux à préparer leurs rendez-vous : fiche client, questions à poser, objections probables. Ton direct, réponses courtes, jamais d\'engagement sur les prix.') ?>,
            },
            ameliorer: {
                title: <?= tj('Améliorer le prompt') ?>,
                hint:  <?= tj('Le prompt saisi est restructuré en six sections et précisé, sans rien inventer ni retirer. Indiquez si besoin ce qui doit changer (facultatif).') ?>,
                go:    <?= tj('Améliorer') ?>,
                placeholder: <?= tj('Ex. : plus strict sur les sources ; vouvoiement ; réponses plus courtes.') ?>,
            },
        };

        function setStatus(text, kind) {
            if (!statusEl) return;
            statusEl.textContent = text || '';
            statusEl.classList.toggle('is-error', kind === 'error');
            statusEl.classList.toggle('is-busy',  kind === 'busy');
        }

        function openPanel(next) {
            mode = next;
            const c = COPY[mode];
            if (titleEl) titleEl.textContent = c.title;
            if (hintEl)  hintEl.textContent  = c.hint;
            if (goBtn)   goBtn.textContent   = c.go;
            if (briefEl) briefEl.placeholder = c.placeholder;
            writeBtn.setAttribute('aria-pressed',   mode === 'rediger'   ? 'true' : 'false');
            improveBtn.setAttribute('aria-pressed', mode === 'ameliorer' ? 'true' : 'false');
            setStatus('');
            panel.hidden = false;
            if (starters) starters.open = false;
            if (briefEl) briefEl.focus();
        }

        function closePanel() {
            panel.hidden = true;
            writeBtn.setAttribute('aria-pressed', 'false');
            improveBtn.setAttribute('aria-pressed', 'false');
        }

        writeBtn.addEventListener('click',   () => (!panel.hidden && mode === 'rediger')   ? closePanel() : openPanel('rediger'));
        improveBtn.addEventListener('click', () => (!panel.hidden && mode === 'ameliorer') ? closePanel() : openPanel('ameliorer'));
        if (closeBtn) closeBtn.addEventListener('click', closePanel);

        function csrf() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) return meta.getAttribute('content') || '';
            const f = document.querySelector('input[name="csrf_token"]');
            return f ? f.value : '';
        }

        async function generate() {
            if (busy) return;
            const brief = (briefEl ? briefEl.value : '').trim().slice(0, AI_MAX_BRIEF);
            const existing = promptEl.value.trim();
            if (mode === 'ameliorer' && existing.length < 20) {
                setStatus(<?= tj('Il n\'y a pas encore de prompt à améliorer : écrivez un premier jet, ou partez d\'un modèle.') ?>, 'error');
                return;
            }
            if (mode === 'rediger' && brief === '' && (!nameEl || nameEl.value.trim() === '')
                && (!descEl || descEl.value.trim() === '')) {
                setStatus(<?= tj('Décrivez l\'assistant en quelques phrases, ou donnez-lui au moins un nom.') ?>, 'error');
                if (briefEl) briefEl.focus();
                return;
            }

            busy = true;
            [goBtn, writeBtn, improveBtn].forEach((b) => { if (b) b.disabled = true; });
            setStatus(mode === 'ameliorer' ? <?= tj('Amélioration en cours… (10 à 20 s)') ?> : <?= tj('Rédaction en cours… (10 à 20 s)') ?>, 'busy');

            try {
                const fd = new FormData();
                fd.append('csrf_token', csrf());
                fd.append('mode', mode);
                fd.append('brief', brief);
                fd.append('name', nameEl ? nameEl.value : '');
                fd.append('description', descEl ? descEl.value : '');
                fd.append('existing', mode === 'ameliorer' ? existing : '');
                if (PROFILE_ID !== null) fd.append('profile_id', String(PROFILE_ID));

                const r = await fetch('/admin/profiles/prompt/generate', {
                    method: 'POST',
                    body: fd,
                    headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await r.json().catch(() => ({}));
                if (!r.ok || !data.ok || !data.prompt) {
                    setStatus(data.error || <?= tj('La rédaction a échoué. Réessayez dans un instant.') ?>, 'error');
                    return;
                }
                // En mode « améliorer », remplacer le texte EST la demande :
                // pas de confirmation, le ↶ couvre le regret.
                if (!applyPrompt(data.prompt, { force: mode === 'ameliorer' })) {
                    setStatus(<?= tj('Texte conservé.') ?>);
                    return;
                }
                markSelected(null);
                // Quatre phrases entières : l'ordre des mots change d'une langue à l'autre.
                const done = mode === 'ameliorer'
                    ? (data.llm
                        ? <?= tj('Prompt amélioré par {modele}. Relisez-le, complétez les [crochets], ajustez ce qui ne vous ressemble pas.') ?>
                        : <?= tj('Prompt amélioré. Relisez-le, complétez les [crochets], ajustez ce qui ne vous ressemble pas.') ?>)
                    : (data.llm
                        ? <?= tj('Premier jet rédigé par {modele}. Relisez-le, complétez les [crochets], ajustez ce qui ne vous ressemble pas.') ?>
                        : <?= tj('Premier jet rédigé. Relisez-le, complétez les [crochets], ajustez ce qui ne vous ressemble pas.') ?>);
                setStatus(fill(done, { modele: data.llm || '' }));
            } catch (e) {
                setStatus(<?= tj('Erreur réseau. Réessayez.') ?>, 'error');
            } finally {
                busy = false;
                [goBtn, writeBtn, improveBtn].forEach((b) => { if (b) b.disabled = false; });
            }
        }

        if (goBtn) goBtn.addEventListener('click', generate);
        if (briefEl) briefEl.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); generate(); }
        });
    })();

    // ── Schéma de l'assistant, dans la « Vue d'ensemble » au bas de la fiche ──
    // Composé depuis les champs, sous la forme d'AssistantDiagram::spec, et
    // redessiné à chaque geste (balisage identique : rien n'est reposé). Même
    // geste que la fiche d'une tâche programmée : chaque élément du schéma
    // désigne ce qu'il montre (data-target) ; un clic dessus — ou sur son
    // bouton « crayon », au clavier — amène le réglage à l'écran, l'allume un
    // instant et lui donne le focus, sans second défilement.
    (function () {
        const conf  = window.ASSISTANT_FORM_DIAGRAM;
        const panel = document.getElementById('pfDiagramPanel');
        const host  = document.getElementById('pfDiagram');
        const form  = document.getElementById('editProfileForm');
        if (!conf || !panel || !host || !form || !window.MiaAssistantDiagram) return;

        const byId    = (id) => document.getElementById(id);

        // Documents : en édition, la liste de la fiche (téléversés, supprimés,
        // extraction qui avance) ; en création, les fichiers choisis.
        let docs = INITIAL_DOCS.slice();
        document.addEventListener('mia:profile-docs', (e) => {
            docs = Array.isArray(e.detail) ? e.detail.slice() : [];
            render();
        });
        const createDocs = () => {
            const input = byId('mpDocsCreateInput');
            return input && input.files
                ? Array.from(input.files).map((f) => ({ file_name: f.name, mime_type: f.type, pending_create: true }))
                : [];
        };

        // Cases cochées et réellement envoyées (une base système est désactivée).
        const checkedIds = (name) => Array.from(form.querySelectorAll('input[name="' + name + '"]'))
            .filter((i) => i.checked && !i.disabled)
            .map((i) => Number(i.value));
        const avatarUrl = () => {
            const radio = form.querySelector('input[name="avatar"]:checked');
            // Avatar créé par MIA (« Ajuster ») : pas encore dans la bibliothèque.
            if (radio && radio.dataset.src) return radio.dataset.src;
            const file  = radio ? radio.value : '';
            return file ? '/assets/img/profils/' + encodeURIComponent(file).replace(/'/g, '%27') : '';
        };
        const value = (id) => (byId(id) ? byId(id).value : '');
        const formSpec = () => {
            const temp = byId('mpTemperature');
            return {
                name: value('mpName'),
                description: value('mpDescription'),
                color: value('mpColor'),
                avatar: avatarUrl(),
                prompt: value('mpSystemPrompt'),
                llm: value('mpDefaultLlm'),
                // Curseur grisé (modèle sans température) : rien n'est envoyé.
                temperature: temp && !temp.disabled ? temp.value : '',
                active: !!(byId('mpIsActive') && byId('mpIsActive').checked),
                sharing: conf.sharing,
                kb_ids: checkedIds('kb_ids[]'),
                fixed_kb_ids: conf.fixed_kb_ids || [],
                tool_ids: checkedIds('tool_ids[]'),
                docs: PROFILE_ID === null ? createDocs() : docs,
            };
        };

        // ---- Du schéma au réglage ----
        // Cible → section (pour l'intitulé du crayon), bloc à montrer, champ à
        // focaliser. Les intitulés sont les titres des sections, lus dans la page.
        const sectionTitle = (id) => ((byId(id) && byId(id).querySelector('h2')) ? byId(id).querySelector('h2').textContent : '').trim();
        const titles = {
            identity:  sectionTitle('mpSectionIdentity'),
            prompt:    sectionTitle('mpSectionPrompt'),
            model:     sectionTitle('mpSectionModel'),
            tools:     sectionTitle('mpSectionTools'),
            knowledge: sectionTitle('mpSectionKnowledge'),
        };
        // Une liste de cases : la première cochée, sinon la première modifiable.
        const firstBox = (scope) => (scope
            ? scope.querySelector('input[type="checkbox"]:checked:not(:disabled)') || scope.querySelector('input[type="checkbox"]:not(:disabled)')
            : null);
        // Outils : le groupe qui porte un outil coché, sinon le premier proposé.
        const toolGroup = () => {
            const groups = ['mpToolsGroup', 'mpN8nGroup', 'mpGammaGroup'].map(byId).filter(Boolean);
            return groups.find((g) => g.querySelector('input:checked')) || groups[0] || byId('mpSectionTools');
        };
        const JUMP_TARGETS = {
            llm:         { section: 'model',     el: () => byId('mpLlmField'), field: () => byId('mpDefaultLlm') },
            temperature: { section: 'model',     el: () => byId('mpTempField'), field: () => {
                const temp = byId('mpTemperature');
                return temp && !temp.disabled ? temp : byId('mpDefaultLlm');
            } },
            assistant:   { section: 'identity',  el: () => byId('mpSectionIdentity'), field: () => byId('mpName') },
            prompt:      { section: 'prompt',    el: () => byId('mpPromptField'), field: () => byId('mpSystemPrompt') },
            active:      { section: 'identity',  el: () => document.querySelector('.mp-active-toggle'), field: () => byId('mpIsActive') },
            kbs:         { section: 'knowledge', el: () => byId('mpKbsField') || byId('mpSectionKnowledge'), field: () => firstBox(byId('mpKbsField')) },
            docs:        { section: 'knowledge', el: () => byId('mpDocumentsSection'), field: () => byId('mpDocFileInput') || byId('mpDocsCreateInput') },
            tools:       { section: 'tools',     el: toolGroup, field: () => firstBox(toolGroup()) },
            knowledge:   { section: 'knowledge', el: () => byId('mpSectionKnowledge') },
        };
        const jumpTitles = {};
        Object.keys(JUMP_TARGETS).forEach((key) => {
            const label = titles[JUMP_TARGETS[key].section];
            if (label) jumpTitles[key] = fill(<?= tj('Modifier dans « {section} »') ?>, { section: label });
        });

        let spotTimer = null;
        function spotlight(el) {
            document.querySelectorAll('.pf-content .is-spotlit').forEach((x) => x.classList.remove('is-spotlit'));
            clearTimeout(spotTimer);
            el.classList.add('is-spotlit');
            spotTimer = setTimeout(() => el.classList.remove('is-spotlit'), 1800);
        }
        function jumpTo(key) {
            const jump = JUMP_TARGETS[key];
            const el = jump ? jump.el() : null;
            if (!el) return;
            const target = jump.field ? jump.field() : null;
            const reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            el.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });
            spotlight(el);
            if (target && typeof target.focus === 'function') {
                try { target.focus({ preventScroll: true }); } catch (_e) { target.focus(); }
            }
        }
        // Le bouton « crayon » et le nœud entier portent la même cible ; le
        // prompt, la température et l'état, une cible plus précise.
        host.addEventListener('click', (e) => {
            const node = e.target.closest('[data-target]');
            if (node && host.contains(node)) jumpTo(node.dataset.target);
        });

        let timer = null;
        let first = true;
        function paint() {
            timer = null;
            const spec = formSpec();
            panel.hidden = false;
            window.MiaAssistantDiagram.mount(host, spec, conf.catalog, { animate: first, jump: jumpTitles });
            first = false;
        }
        function render() {
            if (timer === null) timer = setTimeout(paint, 90);
        }
        // Tout geste de la page : la fiche, et l'interrupteur « Actif » rattaché
        // par form= hors d'elle. Les écouteurs des champs passent avant (curseur
        // de température grisé, modèle collé) : le schéma lit l'état final.
        document.addEventListener('input', render);
        document.addEventListener('change', render);
        paint();
    })();

    // ── Documents de référence (édition : AJAX + polling d'extraction) ──
    if (PROFILE_ID === null) return;

    const docsList         = document.getElementById('mpDocumentsList');
    const docsTokenTotalEl = document.getElementById('mpDocumentsTokenTotal');
    const docFileInput     = document.getElementById('mpDocFileInput');
    const docDrop          = document.getElementById('mpDocDrop');
    const docBusy          = document.getElementById('mpDocBusy');
    const docErrorEl       = document.getElementById('mpDocError');
    let currentDocs        = INITIAL_DOCS.slice();

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content') || '';
        const fallback = document.querySelector('input[name="csrf_token"]');
        return fallback ? fallback.value : '';
    }

    function showDocError(msg) {
        if (!docErrorEl) return;
        if (msg) { docErrorEl.textContent = msg; docErrorEl.style.display = ''; }
        else { docErrorEl.textContent = ''; docErrorEl.style.display = 'none'; }
    }

    function formatBytes(n) {
        if (!Number.isFinite(n) || n <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n.toString() : n.toFixed(1)) + ' ' + units[i];
    }

    const FILE_KINDS = <?= json_encode(KbPages::fileKinds(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function renderDocuments(docs) {
        if (!docsList) return;
        docsList.innerHTML = '';
        if (!docs || docs.length === 0) {
            const empty = document.createElement('div');
            empty.style.cssText = 'font-size:12px;color:var(--text-muted);padding:8px 0';
            empty.textContent = <?= tj('Aucun document pour l\'instant.') ?>;
            docsList.appendChild(empty);
        } else {
            docs.forEach(d => {
                const row = document.createElement('div');
                row.className = 'mp-doc-row';
                // Pastille du format : les mêmes couleurs que les pages des bases (KbPages::fileKind).
                const ext = String(d.file_name || '').split('.').length > 1
                    ? String(d.file_name).split('.').pop().toLowerCase() : '';
                const badge = document.createElement('span');
                badge.className = 'kbx-ft';
                badge.dataset.ext = FILE_KINDS[ext] || 'txt';
                badge.textContent = (ext || 'txt').toUpperCase().slice(0, 4);
                row.appendChild(badge);
                const info = document.createElement('div');
                info.className = 'mp-doc-info';
                const name = document.createElement('div');
                name.className = 'mp-doc-name';
                name.textContent = d.file_name;
                const meta = document.createElement('div');
                meta.className = 'mp-doc-sub';
                const parts = [formatBytes(d.file_size)];
                const st = d.extraction_status || 'done';
                if (st === 'pending' || st === 'processing') {
                    parts.push('⏳ ' + <?= tj('extraction en cours…') ?>);
                } else if (st === 'failed') {
                    parts.push('⚠ ' + <?= tj('échec de l\'extraction') ?>);
                } else if (d.has_text) {
                    parts.push(fill(<?= tj('~{n} tokens') ?>, { n: d.token_count.toLocaleString(NUM_LOCALE) }));
                } else if (d.mime_type && d.mime_type.indexOf('image/') === 0) {
                    parts.push(<?= tj('image de référence') ?>);
                } else {
                    parts.push(<?= tj('pas de texte extrait') ?>);
                }
                meta.textContent = parts.join(' • ');
                info.appendChild(name);
                info.appendChild(meta);
                row.appendChild(info);

                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'btn-secondary mp-doc-del';
                delBtn.textContent = <?= tj('Supprimer') ?>;
                delBtn.addEventListener('click', async () => {
                    if (!confirm(fill(<?= tj('Supprimer « {nom} » ?') ?>, { nom: d.file_name }))) return;
                    delBtn.disabled = true;
                    try {
                        const fd = new FormData();
                        fd.append('csrf_token', getCsrfToken());
                        const r = await fetch('/admin/profiles/' + PROFILE_ID + '/documents/' + d.id + '/delete', {
                            method: 'POST',
                            body: fd,
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-Token': getCsrfToken(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const data = await r.json().catch(() => ({}));
                        if (!r.ok || !data.ok) {
                            showDocError(data.error || <?= tj('Suppression impossible.') ?>);
                            delBtn.disabled = false;
                            return;
                        }
                        currentDocs = currentDocs.filter(x => x.id !== d.id);
                        renderDocuments(currentDocs);
                        showDocError('');
                    } catch (e) {
                        showDocError(<?= tj('Erreur réseau. Réessayez.') ?>);
                        delBtn.disabled = false;
                    }
                });
                row.appendChild(delBtn);
                docsList.appendChild(row);
            });
        }

        // Le schéma de l'assistant suit la liste (téléversé, supprimé, extrait).
        document.dispatchEvent(new CustomEvent('mia:profile-docs', { detail: docs || [] }));

        const total = (docs || []).reduce((s, d) => s + (d.has_text ? d.token_count : 0), 0);
        if (docsTokenTotalEl) {
            docsTokenTotalEl.textContent = total > 0
                ? fill(<?= tj('Total : ~{n} tokens') ?>, { n: total.toLocaleString(NUM_LOCALE) })
                : '';
        }

        // Alerte si les documents dépassent le budget du plus petit modèle autorisé.
        // La dégradation reste automatique (retrieval budgété) — c'est informatif.
        const warnEl = document.getElementById('mpDocsBudgetWarn');
        const section = document.getElementById('mpDocumentsSection');
        if (warnEl && section) {
            const minBudget = parseInt(section.dataset.ragMinBudget || '0', 10);
            if (minBudget > 0 && total > minBudget) {
                warnEl.textContent = fill(<?= tj('Vos documents (~{total} tokens) dépassent ce que le plus petit modèle autorisé (~{budget} tokens) peut recevoir entièrement — seuls les extraits les plus pertinents à la question seront injectés.') ?>, {
                    total: total.toLocaleString(NUM_LOCALE),
                    budget: minBudget.toLocaleString(NUM_LOCALE),
                });
                warnEl.style.display = '';
            } else {
                warnEl.style.display = 'none';
            }
        }
    }

    // Polling du statut d'extraction (async) : met à jour la ligne quand
    // le worker passe le document à 'done' / 'failed'.
    const pollingDocs = new Set();
    function pollDocStatus(docId, attempt) {
        attempt = attempt || 0;
        if (pollingDocs.has(docId) && attempt === 0) return; // déjà suivi
        pollingDocs.add(docId);
        if (attempt > 120) { pollingDocs.delete(docId); return; } // garde-fou ~3 min
        setTimeout(async () => {
            try {
                const r = await fetch('/admin/profiles/' + PROFILE_ID + '/documents/' + docId + '/status', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await r.json().catch(() => ({}));
                if (!data || !data.ok) { pollingDocs.delete(docId); return; }
                const doc = currentDocs.find(x => x.id === docId);
                if (!doc) { pollingDocs.delete(docId); return; }
                doc.extraction_status = data.status;
                doc.token_count = data.token_count;
                doc.has_text = data.has_text;
                renderDocuments(currentDocs);
                if (data.status === 'pending' || data.status === 'processing') {
                    pollDocStatus(docId, attempt + 1);
                } else {
                    pollingDocs.delete(docId);
                }
            } catch (e) {
                pollDocStatus(docId, attempt + 1); // réseau : on retente
            }
        }, 1500);
    }

    renderDocuments(currentDocs);
    currentDocs.forEach(d => {
        const st = d.extraction_status || 'done';
        if (st === 'pending' || st === 'processing') pollDocStatus(d.id, 0);
    });

    // Dépôt : chaque fichier choisi (ou glissé sur la zone) part aussitôt,
    // l'un après l'autre ; la ligne apparaît dans la liste dès son arrivée.
    async function uploadOne(file) {
        const fd = new FormData();
        fd.append('csrf_token', getCsrfToken());
        fd.append('document', file);
        const r = await fetch('/admin/profiles/' + PROFILE_ID + '/documents', {
            method: 'POST',
            body: fd,
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await r.json().catch(() => ({}));
        if (!r.ok || !data.ok) {
            return file.name + ' : ' + (data.error || <?= tj('Upload impossible.') ?>);
        }
        if (data.document) {
            currentDocs.push(data.document);
            renderDocuments(currentDocs);
            const st = data.document.extraction_status || 'done';
            if (st === 'pending' || st === 'processing') pollDocStatus(data.document.id, 0);
        }
        return data.warning ? ('⚠ ' + data.warning) : '';
    }
    if (docFileInput) {
        docFileInput.addEventListener('change', async () => {
            const files = Array.from(docFileInput.files || []);
            if (files.length === 0) return;
            showDocError('');
            docFileInput.disabled = true;
            if (docDrop) docDrop.classList.add('is-busy');
            const notes = [];
            try {
                for (const file of files) {
                    if (docBusy) {
                        docBusy.textContent = (docBusy.dataset.label || '').replace('{nom}', file.name);
                        docBusy.hidden = false;
                    }
                    try {
                        const note = await uploadOne(file);
                        if (note) notes.push(note);
                    } catch (e) {
                        notes.push(file.name + ' : ' + <?= tj('Erreur réseau. Réessayez.') ?>);
                    }
                }
            } finally {
                showDocError(notes.join('\n'));
                docFileInput.value = '';
                docFileInput.disabled = false;
                if (docDrop) docDrop.classList.remove('is-busy');
                if (docBusy) docBusy.hidden = true;
            }
        });
    }
})();

// ---- Options des images de l'assistant (ImageOptions) ----
// Visibles selon ce qu'acceptent les outils image COCHÉS (data-image-caps) :
// un réglage qu'aucun d'eux ne prend est masqué et désactivé — jamais posté.
// Fond transparent : PNG s'enclenche, JPEG (qui ne le garde pas) se grise.
(function () {
    const box = document.getElementById('mpImageOptions');
    if (!box) return;
    const cb = document.getElementById('mpImgTransparent');
    const out = document.getElementById('mpImgOutput');
    const fmt = document.getElementById('mpImgFormat');
    const inputs = () => [...document.querySelectorAll('#mpToolsGroup input[name="tool_ids[]"][data-image-caps]')];
    function caps() {
        const c = { transparent: false, output_formats: [], formats: [] };
        inputs().filter(i => i.checked).forEach(i => {
            let x = {};
            try { x = JSON.parse(i.dataset.imageCaps || '{}'); } catch (e) { x = {}; }
            c.transparent = c.transparent || !!x.transparent;
            (x.output_formats || []).forEach(v => { if (!c.output_formats.includes(v)) c.output_formats.push(v); });
            (x.formats || []).forEach(v => { if (!c.formats.includes(v)) c.formats.push(v); });
        });
        return c;
    }
    function part(id, on) {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = on ? '' : 'none';
        el.querySelectorAll('input, select').forEach(x => { x.disabled = !on || x.dataset.readonly === '1'; });
    }
    function limit(sel, allowed) {
        [...sel.options].forEach(o => { if (o.value !== '') o.hidden = !allowed.includes(o.value); });
    }
    function syncTransparent() {
        const on = cb.checked && !cb.disabled;
        [...out.options].forEach(o => { if (o.value === 'jpeg') o.disabled = on; });
        if (on && (out.value === 'jpeg' || out.value === '')) {
            const keep = [...out.options].find(o => o.value === 'png' && !o.hidden) || [...out.options].find(o => o.value === 'webp' && !o.hidden);
            if (keep && out.value !== keep.value) {
                out.value = keep.value;
                out.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }
    function sync() {
        const c = caps();
        part('mpImgTransparentWrap', c.transparent);
        document.getElementById('mpImgTransparentHint').style.display = c.transparent ? '' : 'none';
        part('mpImgOutputWrap', c.output_formats.length > 0);
        part('mpImgFormatWrap', c.formats.length > 0);
        limit(out, c.output_formats);
        limit(fmt, c.formats);
        box.hidden = !(c.transparent || c.output_formats.length || c.formats.length);
        syncTransparent();
    }
    // Fiche en lecture seule : les contrôles restent grisés quoi qu'il arrive.
    [cb, out, fmt].forEach(x => { if (x.disabled) x.dataset.readonly = '1'; });
    inputs().forEach(i => i.addEventListener('change', sync));
    cb.addEventListener('change', syncTransparent);
    sync();
})();
</script>
<script src="/assets/js/assistant-fiche.js?v=<?= (int)(@filemtime(ROOT . '/public/assets/js/assistant-fiche.js') ?: 1) ?>"></script>
</body>
</html>
