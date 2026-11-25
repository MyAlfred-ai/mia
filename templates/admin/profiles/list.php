<?php
/**
 * Espaces → Assistants (/admin/profiles) : la liste — piste C, « recherche
 * d'abord », même grammaire que la liste des bases de connaissances.
 *
 * Une grande recherche (nom, description, modèle, créateur), un filtre
 * (Tous · Organisation · Personnels, et Inactifs), deux portes de création
 * (« Nouvel assistant », le formulaire ; « Le décrire à MIA », une
 * conversation déjà commencée), puis tout en deux colonnes : Organisation |
 * Personnels. Une ligne dit ce qu'est l'assistant (modèle, bases, documents,
 * outils), qui le voit (une icône, la phrase en infobulle) et son état ; elle
 * mène à sa page (consultation ; « Modifier » y ouvre la fiche). Sa description vient en bulle au survol de la ligne, ou de
 * son « ? » (toucher le « ? » au doigt). « Discuter » et le menu ⋯ (partage, groupes, suppression)
 * restent sur la ligne. La bibliothèque d'avatars vit dans une fenêtre.
 *
 * Filtrer et chercher se jouent sur les lignes déjà rendues
 * (assistant-list.js) : un second rendu ferait dériver le périmètre d'accès.
 *
 * Attend (AdminController::profiles) : $profiles, $currentUser,
 * $isAdminManager, $canCreateProfile, $canEditProfile, $canDeleteProfile,
 * $assistantAvatars, $avatarUsageCounts, $documentsByProfile,
 * $toolIdsByProfile, $kbIdsByProfile, $ownerNames, $personalCount,
 * $personalMax, $personalRemaining, $llmLabels, $miaDraftText, $miaLogo,
 * $avatarsOpen, $success, $error.
 */
$version = static fn(string $file): int => (int)(@filemtime(ROOT . $file) ?: 1);

$currentUserId   = (int)($currentUser['id'] ?? 0);
$canManageAvatar = static function (array $a) use ($isAdminManager, $currentUserId): bool {
    return (!empty($isAdminManager)) || (int)($a['created_by'] ?? 0) === $currentUserId;
};
$canAvatars = !empty($canCreateProfile) || !empty($canEditProfile);
$llmLabels  = $llmLabels ?? [];

// ---- Lignes : une par assistant, rangées Organisation | Personnels ----
$rows   = ['org' => [], 'personal' => []];
$offCnt = 0;
foreach ($profiles as $p) {
    $pid        = (int)$p['id'];
    $vis        = AssistantPages::visibility($p);
    $group      = $vis === AssistantPages::VIS_PERSONAL ? 'personal' : 'org';
    $ownerId    = (int)($p['created_by'] ?? 0);
    $ownerName  = $ownerId > 0 ? ($ownerNames[$ownerId] ?? '') : '';
    $active     = !empty($p['is_active']);
    $llmSlug    = (string)($p['default_llm'] ?? '');
    $llmLabel   = $llmSlug === '' ? t('Modèle de l’organisation') : ($llmLabels[$llmSlug] ?? $llmSlug);
    $docsCount  = count($documentsByProfile[$pid] ?? []);
    $toolsCount = count($toolIdsByProfile[$pid] ?? []);
    $kbCount    = count($kbIdsByProfile[$pid] ?? []);
    $meta       = AssistantPages::meta($llmLabel, $kbCount, $docsCount, $toolsCount);
    // Un administrateur voit les assistants personnels des autres : leur auteur se dit.
    if ($vis === AssistantPages::VIS_PERSONAL && $ownerId !== $currentUserId && $ownerName !== '') {
        $meta .= ' · ' . t('de {nom}', ['nom' => $ownerName]);
    } elseif ($vis === AssistantPages::VIS_PERSONAL && $ownerName === '') {
        // Créateur supprimé : plus personne ne le voit dans le chat.
        $meta .= ' · ' . t('sans créateur');
    }
    // Gestes de la ligne : les mêmes règles que la page de l'assistant.
    $acts      = AssistantPages::actions($p, ['manage' => !empty($isAdminManager), 'edit' => !empty($canEditProfile), 'delete' => !empty($canDeleteProfile)], $currentUserId);
    $canUse    = $acts['use'];
    $canToggle = $acts['toggle'];
    $canGroups = $acts['groups'];
    $canDelete = $acts['delete'];
    if (!$active) {
        $offCnt++;
    }
    $rows[$group][] = [
        'id'        => $pid,
        'p'         => $p,
        'vis'       => $vis,
        'visTxt'    => AssistantPages::visibilityText($vis),
        'color'     => AssistantPages::color($p),
        'initial'   => AssistantPages::initial((string)$p['name']),
        'meta'      => $meta,
        'active'    => $active,
        'canUse'    => $canUse,
        'canToggle' => $canToggle,
        'canGroups' => $canGroups,
        'canDelete' => $canDelete,
        'pageUrl'   => '/admin/profiles/' . $pid,
        'desc'      => trim((string)($p['description'] ?? '')),
        'search'    => implode(' ', [
            (string)$p['name'],
            (string)($p['description'] ?? ''),
            $llmLabel,
            $ownerName,
        ]),
    ];
}
$orgCount      = count($rows['org']);
$personalTotal = count($rows['personal']);
$total         = $orgCount + $personalTotal;
// Une colonne vide n'apprend rien (un créateur sans assistant partagé) : elle
// ne s'affiche, avec son filtre, que pour un administrateur, qui gère les deux.
$showGroup = [
    'org'      => $orgCount > 0 || !empty($isAdminManager),
    'personal' => $personalTotal > 0 || !empty($isAdminManager),
];
$bothGroups = $showGroup['org'] && $showGroup['personal'];
// La légende ne nomme que les visibilités présentes.
$visPresent = [];
foreach ($rows as $groupRows) {
    foreach ($groupRows as $r) {
        $visPresent[$r['vis']] = true;
    }
}

// Créer : un créateur a son plafond d'assistants personnels.
$createOffered = !empty($canCreateProfile);
$canCreateNow  = !empty($isAdminManager) || ($personalRemaining ?? 0) > 0;
$createWhy     = t('Limite de {n} assistants personnels atteinte.', ['n' => (int)($personalMax ?? 0)]);

$renderRow = static function (array $r): string {
    $p   = $r['p'];
    $pid = $r['id'];
    $visTip = $r['visTxt']['label'] . ' — ' . $r['visTxt']['hint'];
    $hasMenu = $r['canToggle'] || $r['canGroups'] || $r['canDelete'];
    // Description : en bulle au survol de la ligne ou de son « ? » (assistant-list.js),
    // lue avec le lien par un lecteur d'écran (aria-describedby).
    $desc   = $r['desc'];
    $descId = 'asx-desc-' . $pid;
    ob_start(); ?>
    <div class="asx-ln<?= $r['active'] ? '' : ' is-off' ?>" id="assistant-<?= $pid ?>" data-asx-row
         data-vis="<?= htmlspecialchars($r['vis']) ?>" data-active="<?= $r['active'] ? '1' : '0' ?>"
         data-search="<?= htmlspecialchars(mb_strtolower($r['search'])) ?>">
        <a class="asx-ln-link" href="<?= htmlspecialchars($r['pageUrl']) ?>"<?php if ($desc !== ''): ?> aria-describedby="<?= $descId ?>"<?php endif; ?>>
            <span class="asx-ava<?= empty($p['avatar']) ? ' is-initial' : '' ?>" style="--asx-c: <?= htmlspecialchars($r['color']) ?>">
                <?php if (!empty($p['avatar'])): ?>
                    <img src="/assets/img/profils/<?= htmlspecialchars(rawurlencode(basename((string)$p['avatar']))) ?>" alt="">
                <?php else: ?>
                    <span aria-hidden="true"><?= htmlspecialchars($r['initial']) ?></span>
                <?php endif; ?>
            </span>
            <span class="asx-ln-text">
                <b><span class="asx-name"><?= htmlspecialchars((string)$p['name']) ?></span><?php if ($desc !== ''): ?><span class="asx-help" data-asx-help aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M9.3 9.2a2.8 2.8 0 0 1 5.4 1c0 1.9-2.7 2.5-2.7 2.5"/><path d="M12 16.6h.01"/></svg></span><?php endif; ?><?= AdminAttention::itemDot('profile:' . $pid, false) ?></b>
                <small><?= htmlspecialchars($r['meta']) ?></small>
            </span>
            <?php if ($desc !== ''): ?><span class="asx-sr" id="<?= $descId ?>" data-asx-desc><?= htmlspecialchars($desc) ?></span><?php endif; ?>
        </a>
        <span class="asx-vis" title="<?= htmlspecialchars($visTip) ?>" role="img" aria-label="<?= htmlspecialchars($visTip) ?>"><?= AssistantPages::visibilityIcon($r['vis']) ?></span>
        <span class="asx-st"><span class="asx-dot<?= $r['active'] ? '' : ' is-off' ?>"></span><?= $r['active'] ? te('Actif') : te('Inactif') ?></span>
        <div class="asx-acts">
            <?php if ($r['canUse']): ?>
                <form method="POST" action="/chat/new">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="profile_id" value="<?= $pid ?>">
                    <button type="submit" class="asx-ib is-chat" title="<?= te('Discuter') ?>" aria-label="<?= te('Discuter avec « {nom} »', ['nom' => (string)$p['name']]) ?>"><?= KbPages::icon('chat', '') ?></button>
                </form>
            <?php endif; ?>
            <?php if ($hasMenu): ?>
                <div class="asx-menu">
                    <button type="button" class="asx-ib" data-asx-menu aria-haspopup="true" aria-expanded="false" aria-label="<?= te("Plus d'actions pour « {nom} »", ['nom' => (string)$p['name']]) ?>" title="<?= te("Plus d'actions") ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
                    </button>
                    <div class="kebab-menu" hidden role="menu">
                        <?php if ($r['canToggle']): ?>
                            <form method="POST" action="/admin/profiles/<?= $pid ?>/toggle-personal"<?php if ($r['vis'] !== AssistantPages::VIS_PERSONAL): ?> data-asx-confirm="<?= te('Rendre « {nom} » personnel ? Ses autorisations de groupe seront effacées : il ne sera plus visible que de son créateur.', ['nom' => (string)$p['name']]) ?>"<?php endif; ?>>
                                <?= Csrf::field() ?>
                                <?php if ($r['vis'] === AssistantPages::VIS_PERSONAL): ?>
                                    <button type="submit" class="kebab-row" role="menuitem" title="<?= te('Il pourra ensuite être autorisé dans des groupes.') ?>"><?= te('Partager à l’organisation') ?></button>
                                <?php else: ?>
                                    <button type="submit" class="kebab-row" role="menuitem" title="<?= te('Il ne sera plus visible que de son créateur.') ?>"><?= te('Rendre personnel') ?></button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                        <?php if ($r['canGroups']): ?>
                            <a class="kebab-row" role="menuitem" href="/admin/user-groups"><?= te('Autoriser dans les groupes →') ?></a>
                        <?php endif; ?>
                        <?php if ($r['canDelete']): ?>
                            <?php if ($r['canToggle'] || $r['canGroups']): ?><div class="kebab-sep"></div><?php endif; ?>
                            <form method="POST" action="/admin/profiles/<?= $pid ?>/delete" data-asx-confirm="<?= te('Supprimer « {nom} » définitivement ? Cette action est irréversible.', ['nom' => (string)$p['name']]) ?>">
                                <?= Csrf::field() ?>
                                <button type="submit" class="kebab-danger" role="menuitem"><?= te('Supprimer…') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php return (string)ob_get_clean();
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Assistants') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= $version('/public/assets/css/main.css') ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
    <link rel="stylesheet" href="/assets/css/assistants.css?v=<?= $version('/public/assets/css/assistants.css') ?>">
</head>
<body class="admin-page">
<?php $page = 'profiles'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content asx-page">
<div class="asx" id="asxList">

    <?php if (empty($avatarsOpen)): ?>
        <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php endif; ?>

    <div class="asx-hero">
        <div class="asx-kicker"><?= te('Assistants') ?></div>
        <h1><?= te('Qui vous aide aujourd’hui ?') ?></h1>
        <label class="asx-search">
            <?= KbPages::icon('search', '') ?>
            <input type="search" id="asxSearch" autocomplete="off"
                   placeholder="<?= htmlspecialchars(I18n::plural($total, 'Rechercher parmi {n} assistant…', 'Rechercher parmi {n} assistants…')) ?>"
                   aria-label="<?= te('Rechercher un assistant') ?>">
            <kbd aria-hidden="true">/</kbd>
        </label>
        <div class="asx-scope" role="group" aria-label="<?= te('Filtrer') ?>">
            <button type="button" class="asx-sc is-on" data-scope="all" aria-pressed="true"><?= te('Tous') ?> <em><?= $total ?></em></button>
            <?php if ($bothGroups): ?>
                <button type="button" class="asx-sc" data-scope="org" aria-pressed="false"><?= te('Organisation') ?> <em><?= $orgCount ?></em></button>
                <button type="button" class="asx-sc" data-scope="personal" aria-pressed="false"><?= te('Personnels') ?> <em><?= $personalTotal ?></em></button>
            <?php endif; ?>
            <?php if ($offCnt > 0): ?>
                <span class="asx-sep" aria-hidden="true"></span>
                <button type="button" class="asx-sc" data-off-filter aria-pressed="false"><?= te('Inactifs') ?> <em><?= $offCnt ?></em></button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($createOffered): ?>
    <div class="asx-create">
        <?php if ($canCreateNow): ?>
            <a class="asx-mk" href="/admin/profiles/new">
        <?php else: ?>
            <span class="asx-mk is-off" aria-disabled="true" title="<?= htmlspecialchars($createWhy) ?>">
        <?php endif; ?>
                <span class="asx-mk-ico"><?= KbPages::icon('robot', '') ?></span>
                <span>
                    <b><?= te('Nouvel assistant') ?></b>
                    <small><?= $canCreateNow ? te('Un formulaire guidé ; la consigne peut être rédigée avec l’IA.') : htmlspecialchars($createWhy) ?></small>
                </span>
                <span class="asx-mk-go"><?= KbPages::icon('plus', '') ?></span>
        <?= $canCreateNow ? '</a>' : '</span>' ?>

        <?php if ($canCreateNow): ?>
            <a class="asx-mk is-mia" href="/" data-mia-draft="<?= htmlspecialchars($miaDraftText) ?>">
        <?php else: ?>
            <span class="asx-mk is-mia is-off" aria-disabled="true" title="<?= htmlspecialchars($createWhy) ?>">
        <?php endif; ?>
                <span class="asx-mk-ico"><img src="<?= htmlspecialchars($miaLogo) ?>" alt=""></span>
                <span>
                    <b><?= te('Le décrire à MIA') ?></b>
                    <small><?= $canCreateNow ? te('Dites ce qu’il doit faire : MIA le prépare dans une conversation, vous approuvez.') : htmlspecialchars($createWhy) ?></small>
                </span>
                <span class="asx-mk-go"><?= KbPages::icon('arrow', '') ?></span>
        <?= $canCreateNow ? '</a>' : '</span>' ?>
    </div>
    <?php if (empty($isAdminManager)): ?>
        <p class="asx-note"><?= htmlspecialchars(I18n::plural((int)($personalRemaining ?? 0), 'Vous pouvez encore créer {n} assistant personnel.', 'Vous pouvez encore créer {n} assistants personnels.')) ?></p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($profiles === []): ?>
        <p class="asx-nothing"><?= te('Aucun assistant pour l’instant. Créez le premier, ou décrivez-le à MIA.') ?></p>
    <?php else: ?>
        <div class="asx-groups<?= $bothGroups ? '' : ' is-one' ?>" id="asxGroups">
            <?php if ($showGroup['org']): ?>
            <section data-group="org" aria-labelledby="asxGroupOrg">
                <div class="asx-gtitle" id="asxGroupOrg"><i></i><?= te('Organisation') ?> · <span data-group-count><?= $orgCount ?></span></div>
                <?php foreach ($rows['org'] as $r) echo $renderRow($r); ?>
                <p class="asx-empty" data-group-empty<?= $orgCount > 0 ? ' hidden' : '' ?>><?= te('Aucun assistant ici.') ?></p>
            </section>
            <?php endif; ?>
            <?php if ($showGroup['personal']): ?>
            <section data-group="personal" aria-labelledby="asxGroupPersonal">
                <div class="asx-gtitle is-personal" id="asxGroupPersonal"><i></i><?= te('Personnels') ?> · <span data-group-count><?= $personalTotal ?></span></div>
                <?php foreach ($rows['personal'] as $r) echo $renderRow($r); ?>
                <p class="asx-empty" data-group-empty<?= $personalTotal > 0 ? ' hidden' : '' ?>><?= te('Aucun assistant ici.') ?></p>
            </section>
            <?php endif; ?>
        </div>
        <p class="asx-results" id="asxNone" hidden><?= te('Rien ne correspond. Essayez un autre mot, ou retirez un filtre.') ?></p>
        <div class="asx-legend" aria-label="<?= te('Qui voit un assistant') ?>">
            <?php if (!empty($visPresent[AssistantPages::VIS_PERSONAL])): ?><span><?= AssistantPages::visibilityIcon(AssistantPages::VIS_PERSONAL) ?><?= te('Personnel : son créateur seul') ?></span><?php endif; ?>
            <?php if (!empty($visPresent[AssistantPages::VIS_SHARED])): ?><span><?= AssistantPages::visibilityIcon(AssistantPages::VIS_SHARED) ?><?= te('Partagé : les groupes qui l’autorisent') ?></span><?php endif; ?>
            <?php if (!empty($visPresent[AssistantPages::VIS_GLOBAL])): ?><span><?= AssistantPages::visibilityIcon(AssistantPages::VIS_GLOBAL) ?><?= te('Global : tout le monde') ?></span><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canAvatars || !empty($isAdminManager)): ?>
        <div class="asx-after">
            <?php if ($canAvatars): ?><button type="button" data-asx-avatars aria-haspopup="dialog"><?= te('Bibliothèque d’avatars') ?></button><?php endif; ?>
            <?php if (!empty($isAdminManager)): ?><a href="/admin/user-groups"><?= te('Assistants autorisés par groupe') ?></a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</div>

<?php if ($canAvatars): ?>
<!-- Bibliothèque d'avatars : commune à tous les assistants. Ses gestes reviennent sur ?avatars=1. -->
<div class="modal asx-modal" id="asxAvatars" role="dialog" aria-modal="true" aria-labelledby="asxAvatarsTitle"<?= empty($avatarsOpen) ? ' hidden' : '' ?>>
    <div class="modal-box asx-avatars-box">
        <div class="asx-modal-head">
            <h3 id="asxAvatarsTitle"><?= te('Bibliothèque d’avatars') ?></h3>
            <button type="button" class="asx-ib" data-asx-close aria-label="<?= te('Fermer') ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
        </div>
        <p class="modal-subtitle"><?= te('Les images que tous les assistants peuvent prendre. Un avatar créé par MIA dans une conversation y entre quand vous l’approuvez.') ?></p>
        <?php if (!empty($avatarsOpen)): ?>
            <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:14px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:14px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php endif; ?>
        <form method="POST" action="/admin/profiles/avatar/upload" enctype="multipart/form-data" class="avatar-upload-form">
            <?= Csrf::field() ?>
            <div class="form-group" style="margin:0;flex:1 1 220px">
                <label for="avatarUploadLibrary"><?= te('Image') ?></label>
                <input type="file" id="avatarUploadLibrary" name="avatar_upload" class="form-input" accept="image/png,image/jpeg,image/gif,image/webp" required>
                <div class="profile-hint"><?= te('PNG, JPG, GIF, WebP — max 2 Mo.') ?></div>
            </div>
            <div class="form-group" style="margin:0;flex:1 1 180px">
                <label for="avatarUploadName"><?= te('Nom') ?></label>
                <input type="text" id="avatarUploadName" name="avatar_name" class="form-input" maxlength="120" placeholder="<?= te('Ex : Conseiller souriant') ?>" required>
            </div>
            <button type="submit" class="btn-primary"><?= te('Ajouter') ?></button>
        </form>

        <?php if (empty($assistantAvatars)): ?>
            <p class="welcome-empty-note" style="max-width:none;margin:0"><?= te('Aucun avatar disponible.') ?></p>
        <?php else: ?>
            <div class="avatar-library-grid">
                <?php foreach ($assistantAvatars as $a): ?>
                    <?php
                        $usageCount = (int)($avatarUsageCounts[$a['filename']] ?? 0);
                        $manage     = $canManageAvatar($a);
                    ?>
                    <div class="avatar-library-item">
                        <div class="avatar-library-image-wrap">
                            <img src="/assets/img/profils/<?= htmlspecialchars(rawurlencode($a['filename'])) ?>" alt="<?= htmlspecialchars($a['name']) ?>" class="avatar-library-image">
                            <?php if (!$a['is_default'] && $manage): ?>
                                <button type="button" class="avatar-delete-btn" data-avatar="<?= htmlspecialchars($a['filename']) ?>" data-name="<?= htmlspecialchars($a['name']) ?>" title="<?= te('Supprimer cet avatar') ?>" aria-label="<?= te('Supprimer cet avatar') ?>">×</button>
                            <?php endif; ?>
                        </div>
                        <?php if ($manage): ?>
                            <form method="POST" action="/admin/profiles/avatar/rename" class="avatar-rename-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="avatar" value="<?= htmlspecialchars($a['filename']) ?>">
                                <input type="text" name="name" value="<?= htmlspecialchars($a['name']) ?>" maxlength="120" class="avatar-name-input" aria-label="<?= te("Nom de l'avatar") ?>" required>
                                <button type="submit" class="avatar-rename-btn" title="<?= te('Renommer') ?>" aria-label="<?= te('Renommer') ?>">✓</button>
                            </form>
                        <?php else: ?>
                            <div class="avatar-library-name"><?= htmlspecialchars($a['name']) ?></div>
                        <?php endif; ?>
                        <div class="avatar-library-meta">
                            <?= htmlspecialchars(I18n::plural($usageCount, '{n} assistant', '{n} assistants')) ?>
                            <?php if ($a['is_default']): ?><span class="avatar-library-system"><?= te('Système') ?></span><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal asx-modal" id="deleteAvatarModal" hidden>
    <div class="modal-box modal-box-compact">
        <h3 style="margin-bottom:8px"><?= te('Supprimer cet avatar ?') ?></h3>
        <p class="modal-subtitle" id="deleteAvatarText" style="margin-bottom:14px"><?= te("Cette action retirera l'avatar de la bibliothèque.") ?></p>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <img id="deleteAvatarPreview" src="" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:1px solid var(--border)">
            <code id="deleteAvatarFilename" style="font-size:12px"></code>
        </div>
        <div class="modal-actions" style="justify-content:flex-end">
            <button type="button" class="btn-secondary" id="deleteAvatarCancelBtn"><?= te('Annuler') ?></button>
            <button type="button" class="btn-primary" id="deleteAvatarConfirmBtn" style="background:#dc2626;border-color:#dc2626"><?= te('Supprimer') ?></button>
        </div>
    </div>
</div>

<form method="POST" action="/admin/profiles/avatar/delete" id="deleteAvatarForm" style="display:none">
    <?= Csrf::field() ?>
    <input type="hidden" name="avatar" id="deleteAvatarInput" value="">
</form>
<?php endif; ?>

<script src="/assets/js/assistant-list.js?v=<?= $version('/public/assets/js/assistant-list.js') ?>"></script>
</body>
</html>
