<?php
/**
 * Espaces → Assistants (/admin/profiles) : la liste.
 *
 * Cliquer un assistant — hors de ses commandes — affiche sa COMPOSITION à
 * droite (modèle, prompt, bases, documents, outils) : le schéma des tâches
 * programmées, sans déclencheur, dessiné par diagram.js + assistant-diagram.js
 * depuis $diagram (AssistantDiagram : catalogue + configuration de chaque
 * assistant). Sur un écran étroit, le schéma se déplie sous l'assistant
 * cliqué. Sans JavaScript, le nom reste un lien vers sa fiche.
 */
$diagramJsVersion          = @filemtime(ROOT . '/public/assets/js/diagram.js') ?: 1;
$assistantDiagramJsVersion = @filemtime(ROOT . '/public/assets/js/assistant-diagram.js') ?: 1;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Assistants') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'profiles'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content">
    <?php
        // Avatars : la bibliothèque ($assistantAvatars) est une liste de lignes
        // {id, filename, name, created_by, is_default} fournie par AssistantAvatar.
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $canManageAvatar = static function (array $a) use ($isAdminManager, $currentUserId): bool {
            return (!empty($isAdminManager)) || (int)($a['created_by'] ?? 0) === $currentUserId;
        };
    ?>
    <h1><?= te('Assistants') ?></h1>
    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Gestion des avatars d'assistants -->
    <div class="card" style="margin-bottom:24px">
        <details class="admin-collapse">
            <summary class="admin-collapse-summary">
                <span class="admin-collapse-caret">▸</span>
                <span><?= te("Gestion des avatars d'assistants") ?></span>
            </summary>
            <div class="admin-collapse-content">
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
        </details>
    </div>

    <!-- Assistants créés — et, à droite, la composition de l'assistant cliqué -->
    <div class="pf-split" id="pfSplit">
    <div class="card pf-list-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
            <h2 style="margin:0;font-size:16px"><?= te('Assistants créés') ?></h2>
            <div style="display:flex;align-items:center;gap:10px">
                <?php if (isset($isAdminManager) && !$isAdminManager): ?>
                    <span class="badge <?= ($personalRemaining ?? 0) > 0 ? 'badge-gray' : 'badge-red' ?>"
                          title="<?= te("Quota d'assistants personnels") ?>">
                        <?= (int)($personalCount ?? 0) ?> / <?= (int)($personalMax ?? 0) ?>
                    </span>
                <?php endif; ?>
                <?php if (empty($canCreateProfile)): ?>
                    <?php /* Droit « Assistants — Créer » absent (Personnes → Rôles) : pas de bouton. */ ?>
                <?php elseif (isset($isAdminManager) && !$isAdminManager && ($personalRemaining ?? 0) <= 0): ?>
                    <button type="button" class="btn-primary" disabled title="<?= te("Quota d'assistants personnels atteint") ?>"><?= te('+ Nouvel assistant') ?></button>
                <?php else: ?>
                    <a href="/admin/profiles/new" class="btn-primary"><?= te('+ Nouvel assistant') ?></a>
                <?php endif; ?>
            </div>
        </div>

        <?php
            // ---- Préparation de la liste ----
            $llmLogos  = LLMFactory::logos();
            $llmLabels = $llmLabels ?? [];

            // Icônes des chips méta (stroke currentColor → suivent le thème).
            $icoSvg  = static fn(string $paths): string =>
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
            $icoTemp = $icoSvg('<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>');
            $icoDoc  = $icoSvg('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>');
            $icoDb   = $icoSvg('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>');
            $icoTool = $icoSvg('<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3L12 3Z"/>');

            // Deux familles : assistants d'organisation (partagés) / personnels.
            $alGroups = [
                [
                    'key'   => 'org',
                    'title' => t('Partagés — organisation'),
                    'hint'  => t('Distribués aux utilisateurs via Groupes › Assistants autorisés'),
                    'rows'  => array_values(array_filter($profiles, static fn(array $p): bool => empty($p['is_personal']))),
                ],
                [
                    'key'   => 'personal',
                    'title' => t('Personnels'),
                    'hint'  => t('Visibles et utilisables par leur seul créateur'),
                    'rows'  => array_values(array_filter($profiles, static fn(array $p): bool => !empty($p['is_personal']))),
                ],
            ];

            // Options du filtre LLM (slugs réellement utilisés, libellé lisible).
            $llmFilterOptions = [];
            $hasDefaultLlmRows = false;
            foreach ($profiles as $pp) {
                $k = (string)($pp['default_llm'] ?? '');
                if ($k === '') { $hasDefaultLlmRows = true; continue; }
                $llmFilterOptions[$k] = $llmLabels[$k] ?? $k;
            }
            asort($llmFilterOptions, SORT_NATURAL | SORT_FLAG_CASE);
        ?>

        <!-- Toolbar recherche / filtres / tri -->
        <div class="al-toolbar">
            <input type="search" id="alSearch" class="form-input al-search"
                   placeholder="<?= te('Rechercher (nom, description, auteur, LLM)…') ?>" aria-label="<?= te('Rechercher un assistant') ?>">
            <select id="alStatus" class="form-select" aria-label="<?= te('Filtrer par statut') ?>">
                <option value=""><?= te('Statut : tous') ?></option>
                <option value="1"><?= te('Actifs') ?></option>
                <option value="0"><?= te('Inactifs') ?></option>
            </select>
            <?php if (!empty($llmFilterOptions) || $hasDefaultLlmRows): ?>
            <select id="alLlm" class="form-select" aria-label="<?= te('Filtrer par LLM') ?>">
                <option value=""><?= te('LLM : tous') ?></option>
                <?php foreach ($llmFilterOptions as $slug => $label): ?>
                    <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
                <?php if ($hasDefaultLlmRows): ?>
                    <option value="_default"><?= te('Défaut application') ?></option>
                <?php endif; ?>
            </select>
            <?php endif; ?>
            <span class="al-sort-wrap">
                <select id="alSort" class="form-select" aria-label="<?= te('Trier la liste') ?>">
                    <option value="name"><?= te('Tri : nom') ?></option>
                    <?php if ($isAdminManager): ?><option value="owner"><?= te('Tri : auteur') ?></option><?php endif; ?>
                    <option value="llmLabel"><?= te('Tri : LLM') ?></option>
                    <option value="temp"><?= te('Tri : température') ?></option>
                    <option value="docs"><?= te('Tri : documents') ?></option>
                </select>
                <button type="button" id="alSortDir" class="al-sort-dir" title="<?= te("Inverser l'ordre de tri") ?>" aria-label="<?= te("Inverser l'ordre de tri") ?>">↑</button>
            </span>
            <span class="al-count" id="alCount"></span>
        </div>

        <?php if (empty($profiles)): ?>
            <div class="al-empty"><?= te("Aucun assistant pour l'instant. Créez le premier avec « + Nouvel assistant ».") ?></div>
        <?php endif; ?>

        <?php foreach ($alGroups as $alGroup): if (empty($alGroup['rows'])) continue; ?>
        <section class="al-group" data-group="<?= htmlspecialchars($alGroup['key']) ?>">
            <div class="al-group-head">
                <span class="al-group-title"><?= htmlspecialchars($alGroup['title']) ?></span>
                <span class="al-group-count"><?= count($alGroup['rows']) ?></span>
                <span class="al-group-hint"><?= htmlspecialchars($alGroup['hint']) ?></span>
            </div>
            <div class="al-rows">
                <?php foreach ($alGroup['rows'] as $p): ?>
                <?php
                    $pid           = (int)$p['id'];
                    $rowOwnerId    = (int)($p['created_by'] ?? 0);
                    $ownerName     = $rowOwnerId > 0 ? ($ownerNames[$rowOwnerId] ?? null) : null;
                    $isPersonalRow = !empty($p['is_personal']);
                    $isGlobalRow   = !empty($p['is_global']);
                    $rowColor      = trim((string)($p['color'] ?? '')) ?: '#64748b';

                    // LLM : libellé lisible depuis le catalogue des LLMs actifs,
                    // fallback slug brut (LLM désactivé entre-temps).
                    $llmSlug     = (string)($p['default_llm'] ?? '');
                    $llmLabel    = $llmSlug === '' ? t('Défaut application') : ($llmLabels[$llmSlug] ?? $llmSlug);
                    $llmProvider = $llmSlug !== '' ? LLMFactory::provider($llmSlug) : '';
                    $llmLogo     = $llmProvider !== '' ? ($llmLogos[$llmProvider] ?? null) : null;

                    $docsCount  = count($documentsByProfile[$pid] ?? []);
                    $toolsCount = count($toolIdsByProfile[$pid] ?? []);
                    $kbCount    = count($kbIdsByProfile[$pid] ?? []);
                    $tempVal    = $p['temperature'];
                    $nameTrim   = trim((string)$p['name']);
                    $initial    = $nameTrim !== '' ? mb_strtoupper(mb_substr($nameTrim, 0, 1)) : '?';

                    // « Utiliser » : démarre une conversation. Visible si actif ET
                    // utilisable par le gestionnaire courant (un admin ne peut pas
                    // ouvrir le chat d'un assistant personnel d'un autre).
                    $canUse = !empty($p['is_active'])
                        && (!$isPersonalRow || $rowOwnerId === (int)$currentUser['id']);

                    // Bascule partage (admin seulement) ; masquée sur un assistant
                    // org sans créateur (deviendrait orphelin s'il redevenait perso).
                    $rowShared   = !$isPersonalRow;
                    $rowHasOwner = !empty($p['created_by']);
                    $canToggle   = (isset($isAdminManager) && $isAdminManager) && !empty($canEditProfile)
                                   && ($isPersonalRow || $rowHasOwner);
                    $switchTitle = $rowShared
                        ? t("Partagé (assistant d'organisation). Désactiver pour le rendre personnel — il redeviendra visible de son seul créateur.")
                        : t("Personnel (visible de son seul créateur). Activer pour le partager à l'organisation — pensez ensuite à l'autoriser dans les groupes.");
                    $delConfirm = t('Supprimer « {nom} » définitivement ? Cette action est irréversible.', ['nom' => (string)$p['name']]);
                    $editUrl    = '/admin/profiles/' . $pid . '/edit';
                ?>
                <article class="al-row" id="assistant-<?= $pid ?>"
                    data-profile-id="<?= $pid ?>"
                    data-edit-url="<?= htmlspecialchars($editUrl) ?>"
                    data-name="<?= htmlspecialchars(mb_strtolower((string)$p['name'])) ?>"
                    data-desc="<?= htmlspecialchars(mb_strtolower((string)($p['description'] ?? ''))) ?>"
                    data-owner="<?= htmlspecialchars(mb_strtolower((string)($ownerName ?? ''))) ?>"
                    data-llm="<?= htmlspecialchars($llmSlug) ?>"
                    data-llm-label="<?= htmlspecialchars(mb_strtolower($llmLabel)) ?>"
                    data-status="<?= !empty($p['is_active']) ? '1' : '0' ?>"
                    data-type="<?= $isPersonalRow ? 'personal' : 'org' ?>"
                    data-temp="<?= $tempVal !== null ? htmlspecialchars((string)$tempVal) : '' ?>"
                    data-docs="<?= $docsCount ?>">

                    <div class="al-id">
                        <?php if (!empty($p['avatar'])): ?>
                            <img src="/assets/img/profils/<?= htmlspecialchars(basename($p['avatar'])) ?>" alt=""
                                 class="al-avatar" style="border-color:<?= htmlspecialchars($rowColor) ?>">
                        <?php else: ?>
                            <span class="al-color-dot" style="background:<?= htmlspecialchars($rowColor) ?>"><?= htmlspecialchars($initial) ?></span>
                        <?php endif; ?>
                        <div class="al-id-text">
                            <div class="al-name">
                                <?php /* Lien vers la fiche sans JavaScript ; avec, il montre la
                                         composition (comme le reste de la ligne) — le clavier y accède. */ ?>
                                <a class="al-name-link" href="<?= htmlspecialchars($editUrl) ?>"><?= htmlspecialchars($p['name']) ?></a>
                                <?php if ($isGlobalRow): ?>
                                    <span class="badge badge-global" title="<?= te('Assistant global : visible par tous les utilisateurs, sans filtrage de groupe') ?>"><?= te('Global') ?></span>
                                <?php endif; ?>
                                <?php if (empty($p['is_active'])): ?>
                                    <span class="badge badge-red" title="<?= te('Assistant désactivé : invisible dans le chat') ?>"><?= te('Inactif') ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($p['description'])): ?>
                                <div class="al-desc" title="<?= htmlspecialchars($p['description']) ?>"><?= htmlspecialchars($p['description']) ?></div>
                            <?php endif; ?>
                            <?php if (isset($isAdminManager) && $isAdminManager): ?>
                                <div class="al-owner"><?= teb('créé par {b}{nom}{/b}', ['nom' => $ownerName ?? t('Système')]) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="al-share">
                        <?php if ($isPersonalRow): ?>
                            <span class="badge badge-personal" title="<?= te('Visible et utilisable par son seul créateur') ?>"><?= te('Personnel') ?></span>
                        <?php else: ?>
                            <span class="badge badge-org" title="<?= te("Assistant d'organisation : distribué aux utilisateurs via Groupes › Assistants autorisés") ?>"><?= te('Partagé') ?></span>
                        <?php endif; ?>
                        <?php if ($canToggle): ?>
                        <form method="POST" action="/admin/profiles/<?= $pid ?>/toggle-personal" class="al-share-toggle">
                            <?= Csrf::field() ?>
                            <label class="ios-switch" title="<?= htmlspecialchars($switchTitle) ?>">
                                <input type="checkbox" onchange="this.form.submit()" <?= $rowShared ? 'checked' : '' ?>>
                                <span class="ios-switch-slider"></span>
                            </label>
                            <span><?= te('Partage organisation') ?></span>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="al-meta">
                        <span class="al-chip al-chip-llm <?= $llmSlug === '' ? 'al-chip-muted' : '' ?>" title="<?= te("LLM par défaut de l'assistant : {llm}", ['llm' => $llmLabel]) ?>">
                            <?php if ($llmLogo): ?><img src="<?= htmlspecialchars($llmLogo) ?>" alt=""><?php endif; ?>
                            <span><?= htmlspecialchars($llmLabel) ?></span>
                        </span>
                        <?php if ($tempVal !== null): ?>
                            <span class="al-chip" title="<?= te('Température : {t} (0 = factuel, 2 = créatif)', ['t' => (string)$tempVal]) ?>"><?= $icoTemp ?><span><?= htmlspecialchars((string)$tempVal) ?></span></span>
                        <?php endif; ?>
                        <?php if ($docsCount > 0): ?>
                            <span class="al-chip" title="<?= htmlspecialchars(I18n::plural($docsCount, '{n} document de référence (RAG local de l\'assistant)', '{n} documents de référence (RAG local de l\'assistant)')) ?>"><?= $icoDoc ?><span><?= htmlspecialchars(I18n::plural($docsCount, '{n} doc', '{n} docs')) ?></span></span>
                        <?php endif; ?>
                        <?php if ($kbCount > 0): ?>
                            <span class="al-chip" title="<?= htmlspecialchars(I18n::plural($kbCount, '{n} base de connaissances liée (RAG vectoriel partagé)', '{n} bases de connaissances liées (RAG vectoriel partagé)')) ?>"><?= $icoDb ?><span><?= htmlspecialchars(I18n::plural($kbCount, '{n} base', '{n} bases')) ?></span></span>
                        <?php endif; ?>
                        <?php if ($toolsCount > 0): ?>
                            <span class="al-chip" title="<?= htmlspecialchars(I18n::plural($toolsCount, '{n} outil de génération d\'image activé (function-calling)', '{n} outils de génération d\'image activés (function-calling)')) ?>"><?= $icoTool ?><span><?= htmlspecialchars(I18n::plural($toolsCount, '{n} outil', '{n} outils')) ?></span></span>
                        <?php endif; ?>
                    </div>

                    <div class="al-actions">
                        <?php if ($canUse): ?>
                        <form method="POST" action="/chat/new">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="profile_id" value="<?= $pid ?>">
                            <button type="submit" class="btn-primary" title="<?= te('Démarrer une conversation avec cet assistant') ?>"><?= te('Utiliser') ?></button>
                        </form>
                        <?php endif; ?>
                        <a class="btn-secondary" href="<?= htmlspecialchars($editUrl) ?>" title="<?= te("Ouvrir la fiche de l'assistant") ?>"><?= !empty($canEditProfile) ? te('Modifier') : te('Voir') ?></a>

                        <?php if (!empty($canDeleteProfile) || ($rowShared && isset($isAdminManager) && $isAdminManager)): ?>
                        <div class="kebab">
                            <button type="button" class="kebab-btn" aria-haspopup="true" aria-expanded="false" aria-label="<?= te("Plus d'actions") ?>" title="<?= te("Plus d'actions") ?>">⋯</button>
                            <div class="kebab-menu" hidden role="menu">
                                <?php if ($rowShared && isset($isAdminManager) && $isAdminManager): ?>
                                    <a class="kebab-row" href="/admin/user-groups" title="<?= te("Un assistant partagé doit être coché dans « Assistants autorisés » d'un groupe pour être visible de ses membres") ?>"><?= te('Autoriser dans les groupes →') ?></a>
                                    <?php if (!empty($canDeleteProfile)): ?><div class="kebab-sep"></div><?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($canDeleteProfile)): ?>
                                <form method="POST" action="/admin/profiles/<?= $pid ?>/delete"
                                      onsubmit="return confirm(<?= htmlspecialchars(json_encode($delConfirm), ENT_QUOTES) ?>);">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="kebab-danger" title="<?= te('Supprimer cet assistant') ?>">🗑 <?= te('Supprimer') ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>

        <div class="al-empty-filtered" id="alNoResults" style="display:none"><?= te('Aucun assistant ne correspond aux filtres.') ?></div>
    </div>

    <?php if ($diagram !== null): ?>
    <?php /* Composition de l'assistant cliqué : remplie par le script de la
             page (rien sans JavaScript — la fiche s'ouvre alors par le nom). */ ?>
    <aside class="sched-diagram-panel pf-diagram-panel" id="pfDiagramPanel" aria-labelledby="pfDiagramTitle" hidden>
        <div class="sched-diagram-head">
            <div class="sched-diagram-head-text">
                <span class="sched-diagram-kicker"><?= te("Configuration de l'assistant") ?></span>
                <h2 id="pfDiagramTitle"></h2>
            </div>
            <a class="btn-secondary sched-diagram-edit" id="pfDiagramEdit" href="/admin/profiles"><?= te('Modifier') ?></a>
            <button type="button" class="sched-diagram-close" id="pfDiagramClose" aria-label="<?= te('Fermer le schéma') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="sched-diagram" id="pfDiagram"></div>
    </aside>
    <?php endif; ?>
    </div>
</div>

<div class="modal" id="deleteAvatarModal" style="display:none">
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

<?php if ($diagram !== null): ?>
<script>
// Composition des assistants (clic sur une ligne) : catalogue et
// configurations calculés par le serveur (AssistantDiagram), dessinés par
// diagram.js + assistant-diagram.js — chargés avant le script de la page.
window.ASSISTANT_DIAGRAMS = <?= json_encode($diagram, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= (int)$diagramJsVersion ?>"></script>
<script src="/assets/js/assistant-diagram.js?v=<?= (int)$assistantDiagramJsVersion ?>"></script>
<?php endif; ?>
<script>
(function () {
    const deleteAvatarModal   = document.getElementById('deleteAvatarModal');
    const deleteAvatarForm    = document.getElementById('deleteAvatarForm');
    const deleteAvatarInput   = document.getElementById('deleteAvatarInput');
    const deleteAvatarPreview = document.getElementById('deleteAvatarPreview');
    const deleteAvatarFile    = document.getElementById('deleteAvatarFilename');
    const deleteAvatarCancel  = document.getElementById('deleteAvatarCancelBtn');
    const deleteAvatarConfirm = document.getElementById('deleteAvatarConfirmBtn');
    let pendingAvatar = '';

    function closeDeleteAvatarModal() {
        pendingAvatar = '';
        if (deleteAvatarModal) deleteAvatarModal.style.display = 'none';
    }

    function openDeleteAvatarModal(avatarFilename, avatarName) {
        if (!deleteAvatarModal || !deleteAvatarInput || !deleteAvatarPreview || !deleteAvatarFile) return;
        pendingAvatar = String(avatarFilename || '').trim();
        if (!pendingAvatar) return;
        deleteAvatarInput.value = pendingAvatar;
        deleteAvatarFile.textContent = String(avatarName || pendingAvatar);
        deleteAvatarPreview.src = '/assets/img/profils/' + encodeURIComponent(pendingAvatar);
        deleteAvatarModal.style.display = 'flex';
    }

    document.querySelectorAll('.avatar-delete-btn').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            openDeleteAvatarModal(btn.dataset.avatar || '', btn.dataset.name || '');
        });
    });
    deleteAvatarCancel?.addEventListener('click', closeDeleteAvatarModal);
    deleteAvatarModal?.addEventListener('click', (e) => { if (e.target === deleteAvatarModal) closeDeleteAvatarModal(); });
    deleteAvatarConfirm?.addEventListener('click', () => {
        if (!pendingAvatar || !deleteAvatarForm) return;
        deleteAvatarForm.submit();
    });

    // ── Composition de l'assistant cliqué ──
    // Même geste que la liste des tâches programmées : un clic sur la ligne —
    // hors de ses commandes (bascule de partage, Utiliser, Modifier, ⋯) —
    // montre le schéma de l'assistant : à droite sur un écran large
    // (l'assistant de l'adresse #assistant-ID, sinon le premier, affiché
    // d'office), déplié sous la ligne sur un écran étroit, où un second clic
    // le replie. « Modifier » reste là pour modifier. La configuration de
    // chaque assistant est déjà dans la page : aucun aller-retour au clic.
    const listDiagram = (function setupListDiagram() {
        const conf  = window.ASSISTANT_DIAGRAMS;
        const split = document.getElementById('pfSplit');
        const panel = document.getElementById('pfDiagramPanel');
        const host  = document.getElementById('pfDiagram');
        if (!conf || !split || !panel || !host || !window.MiaAssistantDiagram) return null;

        const title    = document.getElementById('pfDiagramTitle');
        const edit     = document.getElementById('pfDiagramEdit');
        const closeBtn = document.getElementById('pfDiagramClose');
        // Même seuil que la mise en page côte à côte de main.css (.pf-split).
        const wide = window.matchMedia('(min-width: 1200px)');
        let current = 0;

        const specOf = (id) => (conf.assistants && conf.assistants[String(id)]) || null;
        const rowOf  = (id) => document.getElementById('assistant-' + id);
        // Une ligne masquée par les filtres (ou dont le groupe l'est) ne se montre pas.
        const shown  = (row) => !!row && row.style.display !== 'none'
            && (row.closest('.al-group') || {}).style?.display !== 'none';
        const firstShown = () => Array.from(document.querySelectorAll('.al-row')).find(shown) || null;

        // Côte à côte : le panneau suit la liste ; en colonne : sous la ligne.
        function place(row) {
            if (wide.matches) {
                if (panel.parentElement !== split) split.appendChild(panel);
            } else if (row && row.nextElementSibling !== panel) {
                row.after(panel);
            }
        }

        function paint(animate) {
            const spec = specOf(current);
            const row  = rowOf(current);
            if (!spec || !row) return;
            if (title) title.textContent = spec.name || '';
            if (edit) edit.href = row.dataset.editUrl || '/admin/profiles';
            window.MiaAssistantDiagram.mount(host, spec, conf.catalog, { animate });
        }

        function select(id, opts) {
            const row = rowOf(id);
            if (!specOf(id) || !row) return;
            current = Number(id);
            document.querySelectorAll('.al-row').forEach((el) => {
                const on = el === row;
                el.classList.toggle('is-selected', on);
                if (on) el.setAttribute('aria-current', 'true');
                else el.removeAttribute('aria-current');
            });
            place(row);
            panel.hidden = false;
            split.classList.add('is-open');
            paint(opts.animate !== false);
            if (opts.remember) {
                try { history.replaceState(null, '', '#assistant-' + current); } catch (_e) { /* adresse inchangée */ }
            }
            if (!wide.matches && opts.scroll) panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        function close(opts) {
            const row = rowOf(current);
            current = 0;
            panel.hidden = true;
            split.classList.remove('is-open');
            document.querySelectorAll('.al-row.is-selected').forEach((el) => {
                el.classList.remove('is-selected');
                el.removeAttribute('aria-current');
            });
            try { history.replaceState(null, '', location.pathname + location.search); } catch (_e) { /* adresse inchangée */ }
            if (!opts || opts.focus !== false) row?.querySelector('.al-name-link')?.focus();
        }

        // Un clic sur la ligne — hors de ses commandes — montre son schéma. Le
        // nom, lien vers la fiche sans JavaScript, le montre aussi.
        document.querySelectorAll('.al-row').forEach((row) => {
            row.addEventListener('click', (e) => {
                const name = e.target.closest('.al-name-link');
                if (!name && e.target.closest('button, a, form, label, input, select, textarea')) return;
                if (name) e.preventDefault();
                const id = Number(row.dataset.profileId);
                if (!wide.matches && id === current && !panel.hidden) {
                    close();
                    return;
                }
                select(id, { remember: true, scroll: true });
            });
        });
        closeBtn?.addEventListener('click', () => close());

        wide.addEventListener('change', () => {
            if (current) {
                place(rowOf(current));
                paint(false);
            } else if (wide.matches) {
                const first = firstShown();
                if (first) select(first.dataset.profileId, { animate: true });
            }
        });

        const fromHash = /^#assistant-(\d+)$/.exec(location.hash || '');
        if (fromHash && specOf(fromHash[1])) {
            select(fromHash[1], { animate: true, scroll: true });
        } else if (wide.matches) {
            const first = firstShown();
            if (first) select(first.dataset.profileId, { animate: true });
        }

        return {
            /**
             * Après un filtre ou un tri : le panneau suit sa ligne ; une ligne
             * masquée cède la place à la première visible (écran large) ou
             * replie le schéma (écran étroit, il vivait sous elle).
             */
            refresh() {
                const row = current ? rowOf(current) : null;
                if (row && shown(row)) {
                    place(row);
                    return;
                }
                const first = wide.matches ? firstShown() : null;
                if (first) {
                    select(first.dataset.profileId, { animate: true });
                } else if (current) {
                    close({ focus: false });
                }
            },
        };
    })();

    // Sans schéma (script absent) : toute la ligne ouvre la fiche, comme avant.
    if (!listDiagram) {
        document.querySelectorAll('.al-row').forEach(row => {
            row.addEventListener('click', e => {
                if (e.target.closest('button, a, form, label, input')) return;
                const url = row.dataset.editUrl;
                if (url) location.href = url;
            });
        });
    }

    // ── Menu kebab (actions secondaires) ──
    function closeAllKebabs() {
        document.querySelectorAll('.kebab-menu:not([hidden])').forEach(m => {
            m.hidden = true;
            const b = m.parentElement && m.parentElement.querySelector('.kebab-btn');
            if (b) b.setAttribute('aria-expanded', 'false');
        });
    }
    document.querySelectorAll('.kebab-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const menu = btn.parentElement.querySelector('.kebab-menu');
            if (!menu) return;
            const wasOpen = !menu.hidden;
            closeAllKebabs();
            if (wasOpen) return;
            // position:fixed → échappe au clipping de .table-wrap.
            menu.hidden = false;
            // Pixels d'écran → pixels de page (taille d'affichage, cf. head-meta.php).
            const z = window.miaUiZoom ? window.miaUiZoom() : 1;
            const r = btn.getBoundingClientRect();
            const mw = (menu.offsetWidth || 230) * z;
            let left = Math.max(8, r.right - mw);
            menu.style.top = (r.bottom + 4) / z + 'px';
            menu.style.left = left / z + 'px';
            btn.setAttribute('aria-expanded', 'true');
        });
    });
    document.addEventListener('click', closeAllKebabs);
    window.addEventListener('scroll', closeAllKebabs, true);

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        closeDeleteAvatarModal();
        closeAllKebabs();
    });

    // ── Recherche / filtres / tri de la liste ──
    const alRows    = Array.from(document.querySelectorAll('.al-row'));
    const alGroups  = Array.from(document.querySelectorAll('.al-group'));
    const noResults = document.getElementById('alNoResults');
    const alSearch  = document.getElementById('alSearch');
    const alStatus  = document.getElementById('alStatus');
    const alLlm     = document.getElementById('alLlm');
    const alCount   = document.getElementById('alCount');
    const alSort    = document.getElementById('alSort');
    const alSortDir = document.getElementById('alSortDir');
    const COUNT_ONE  = <?= tj('{shown} / {n} assistant') ?>;
    const COUNT_MANY = <?= tj('{shown} / {n} assistants') ?>;
    // Tri alphabétique dans la langue de la page.
    const sortLang  = document.documentElement.lang || 'fr';

    function applyFilters() {
        const q  = (alSearch && alSearch.value || '').trim().toLowerCase();
        const st = alStatus ? alStatus.value : '';
        const lm = alLlm ? alLlm.value : '';
        let shownTotal = 0;
        alRows.forEach(row => {
            const d = row.dataset;
            const okQ = !q || d.name.includes(q) || (d.desc || '').includes(q)
                     || (d.owner || '').includes(q) || (d.llmLabel || '').includes(q);
            const okS = !st || d.status === st;
            const okL = !lm || (lm === '_default' ? d.llm === '' : d.llm === lm);
            const show = okQ && okS && okL;
            row.style.display = show ? '' : 'none';
            if (show) shownTotal++;
        });
        // Compteur par groupe + masquage des groupes vides.
        alGroups.forEach(g => {
            const rows  = Array.from(g.querySelectorAll('.al-row'));
            const shown = rows.filter(r => r.style.display !== 'none').length;
            g.style.display = shown > 0 ? '' : 'none';
            const c = g.querySelector('.al-group-count');
            if (c) c.textContent = shown === rows.length ? String(rows.length) : shown + ' / ' + rows.length;
        });
        if (noResults) noResults.style.display = (alRows.length > 0 && shownTotal === 0) ? '' : 'none';
        // Total ≥ 1 ici : singulier pour 1, pluriel au-delà — la même règle en
        // français et en anglais (elles ne diffèrent qu'à 0).
        if (alCount) alCount.textContent = alRows.length
            ? (alRows.length === 1 ? COUNT_ONE : COUNT_MANY)
                .replace('{shown}', String(shownTotal)).replace('{n}', String(alRows.length))
            : '';
        listDiagram?.refresh();
    }
    [alSearch, alStatus, alLlm].forEach(el => {
        if (!el) return;
        el.addEventListener('input', applyFilters);
        el.addEventListener('change', applyFilters);
    });

    // Tri au sein de chaque groupe (les sections partagés / personnels restent).
    let sortAsc = true;
    function applySort() {
        if (!alSort) return;
        const key = alSort.value;
        const dir = sortAsc ? 1 : -1;
        const numeric = (key === 'temp' || key === 'docs');
        document.querySelectorAll('.al-rows').forEach(container => {
            Array.from(container.querySelectorAll('.al-row')).sort((a, b) => {
                const va = a.dataset[key] || '', vb = b.dataset[key] || '';
                if (numeric) {
                    let na = parseFloat(va), nb = parseFloat(vb);
                    if (isNaN(na)) na = -Infinity;
                    if (isNaN(nb)) nb = -Infinity;
                    return (na - nb) * dir;
                }
                return va.localeCompare(vb, sortLang, { sensitivity: 'base' }) * dir;
            }).forEach(row => container.appendChild(row));
        });
        if (alSortDir) alSortDir.textContent = sortAsc ? '↑' : '↓';
        listDiagram?.refresh();
    }
    if (alSort) alSort.addEventListener('change', () => { sortAsc = true; applySort(); });
    if (alSortDir) alSortDir.addEventListener('click', () => { sortAsc = !sortAsc; applySort(); });

    applyFilters();
})();
</script>
</body>
</html>
