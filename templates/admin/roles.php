<?php
/**
 * Personnes → Rôles (/admin/roles) — administrateurs seuls.
 * Variables fournies par RoleController::index :
 *   @var array<string, array> $roles   tous les rôles (Role::all : livrés puis personnalisés)
 *   @var array<string, int>   $counts  comptes par rôle (Role::memberCounts)
 *   @var array                $role    rôle affiché dans l'éditeur
 *   @var string               $slug    son identifiant
 *   @var int                  $orphanCount comptes dont le rôle n'existe plus
 *   @var array                $catalog Permissions::clientCatalog()
 *   @var ?string              $success, $error
 *   @var bool                 $openNew ouvrir la fenêtre « Nouveau rôle » (retour d'une création refusée)
 *
 * ENREGISTREMENT MANUEL (bouton « Enregistrer » de la barre du bas) : aucun
 * attribut d'enregistrement automatique sur cette page — des droits ne
 * changent pas à moitié cochés. Le script de la page (roles.js) rejoue les
 * implications du catalogue (cocher « Modifier » coche « Voir »), compte les
 * changements, tient le résumé à jour et prévient avant de quitter une page
 * modifiée. Le serveur rejoue la même fermeture (Permissions::fromPost) quoi
 * qu'on lui envoie.
 *
 * AUCUNE LÉGENDE DANS LA PAGE : ce que veut dire une colonne, ce que couvre une
 * ligne, ce qu'ouvre une case (et pourquoi une case est sans objet) vivent dans
 * des sources cachées (`.role-tip-src`), reliées à leur élément par
 * `aria-describedby` (lecteurs d'écran) et `data-tip` (roles.js en fait une
 * bulle AU-DESSUS de l'élément : survol, focus clavier, toucher).
 */
$mainCssVersion  = @filemtime(ROOT . '/public/assets/css/main.css') ?: 1;
$rolesCssVersion = @filemtime(ROOT . '/public/assets/css/roles.css') ?: 1;
$rolesJsVersion  = @filemtime(ROOT . '/public/assets/js/roles.js') ?: 1;

$svg = static fn(string $paths, int $size = 16): string =>
    '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';

// Icône de chaque fonctionnalité : celle de sa page dans le menu quand elle en
// a une (AdminMenu, ScheduledTask, Workflow) — un seul vocabulaire visuel.
$featureIcon = static function (string $feature) use ($svg): string {
    return match ($feature) {
        'assistants'      => AdminMenu::icon('profiles', 18),
        'knowledge_bases' => AdminMenu::icon('kb', 18),
        'schedules'       => ScheduledTask::icon(18),
        'workflows'       => Workflow::icon(18),
        'tools'           => AdminMenu::icon('tools', 18),
        'connectors'      => AdminMenu::icon('connectors', 18),
        'dashboard'       => AdminMenu::icon('dashboard', 18),
        'users'           => $svg('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>', 18),
        'groups'          => AdminMenu::icon('users', 18),
        'llms'            => AdminMenu::icon('llms', 18),
        'sso'             => $svg('<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>', 18),
        'costs'           => AdminMenu::icon('costs', 18),
        'company'         => $svg('<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>', 18),
        'diagnostics'     => $svg('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>', 18),
        'roles'           => $svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>', 18),
        'data'            => $svg('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>', 18),
        default           => '',
    };
};

// Icônes des colonnes (Voir, Utiliser, Créer, Modifier, Supprimer, Gérer).
$actionIcons = [
    'view'   => $svg('<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>', 15),
    'use'    => $svg('<polygon points="6 4 20 12 6 20 6 4"/>', 15),
    'create' => $svg('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>', 15),
    'edit'   => $svg('<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>', 15),
    'delete' => $svg('<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>', 15),
    'manage' => $svg('<path d="M2 21a8 8 0 0 1 10.434-7.62"/><circle cx="10" cy="8" r="5"/><circle cx="18" cy="18" r="3"/><path d="m19.5 14.3-.4.9"/><path d="m16.9 20.8-.4.9"/><path d="m21.7 19.5-.9-.4"/><path d="m15.2 16.9-.9-.4"/><path d="m21.7 16.5-.9.4"/><path d="m15.2 19.1-.9.4"/><path d="m19.5 21.7-.4-.9"/><path d="m16.9 15.2-.4-.9"/>', 15),
];
$lockIcon = $svg('<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>', 14);
$infoIcon = $svg('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>', 15);
$tickIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

// Contenu d'une infobulle (roles.js le recopie dans la bulle) : titre, texte,
// note — chaque morceau échappé ici. `$list` : [libellé => phrase].
$tipHtml = static function (string $title, string $body, string $note = '', array $list = [], string $foot = '') use ($infoIcon): string {
    $h = '<span class="rt-title">' . htmlspecialchars($title) . '</span>';
    if ($body !== '') {
        $h .= '<span class="rt-body">' . htmlspecialchars($body) . '</span>';
    }
    if ($list !== []) {
        $h .= '<span class="rt-list">';
        foreach ($list as $label => $phrase) {
            $h .= '<span class="rt-item"><span class="rt-key">' . htmlspecialchars((string)$label) . '</span> ' . htmlspecialchars((string)$phrase) . '</span>';
        }
        $h .= '</span>';
    }
    if ($note !== '') {
        $h .= '<span class="rt-note">' . $infoIcon . '<span>' . htmlspecialchars($note) . '</span></span>';
    }
    if ($foot !== '') {
        $h .= '<span class="rt-foot">' . htmlspecialchars($foot) . '</span>';
    }
    return $h;
};
// « Coche aussi « Voir ». » : ce que la case coche avec elle.
$requiresLine = static function (string $feature, string $action): string {
    $labels = Permissions::requiredLabels($feature, $action);
    if ($labels === []) {
        return '';
    }
    return count($labels) === 1
        ? t('Coche aussi « {action} ».', ['action' => $labels[0]])
        : t('Coche aussi {actions}.', ['actions' => I18n::listeCitee($labels)]);
};

$granted    = array_flip($role['permissions']);
$locked     = (bool)$role['locked'];
$isCustom   = !$role['system'];
$members    = (int)($counts[$slug] ?? 0);
$canAddRole = count(array_filter($roles, static fn(array $r): bool => !$r['system'])) < Role::MAX_CUSTOM;

// Ce que le rôle ouvre, en une phrase (repli du résumé sans JavaScript, ligne
// d'une carte de rôle sans description).
$summaryLine = static function (array $perms, bool $isLocked): string {
    if ($isLocked) {
        return t('Tous les droits, dans toutes les fonctionnalités et tous les écrans d’administration — rôles et données compris.');
    }
    $s = Permissions::summary($perms);
    $parts = [];
    if ($s['features'] !== []) {
        $parts[] = implode(', ', $s['features']);
    }
    if ($s['admin'] !== []) {
        $parts[] = t('Administration : {screens}', ['screens' => implode(', ', $s['admin'])]);
    }
    return $parts === [] ? t('Aucun droit : discuter avec MIA, sans assistant, base, outil ni connecteur.') : implode(' · ', $parts);
};
$cardLine = static fn(array $r): string => trim((string)$r['description']) !== ''
    ? (string)$r['description']
    : $summaryLine($r['permissions'], (bool)$r['locked']);
$featureOn = static function (string $feature) use ($granted): bool {
    foreach (array_keys($granted) as $key) {
        if (str_starts_with((string)$key, $feature . '.')) {
            return true;
        }
    }
    return false;
};
// Une création refusée revient avec la fenêtre « Nouveau rôle » ouverte : le
// message s'affiche DANS la fenêtre, pas derrière son voile.
$modalError = ($openNew && !empty($error)) ? (string)$error : null;
$pageError  = ($modalError === null && !empty($error)) ? (string)$error : null;
$roleUrl    = static fn(string $s): string => '/admin/roles?role=' . rawurlencode($s);
$appName    = Settings::get('app_name', 'MIA');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars($appName) ?> — <?= te('Rôles') ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <link rel="stylesheet" href="/assets/css/roles.css?v=<?= (int)$rolesCssVersion ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'roles'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content roles-content">

    <div class="roles-head">
        <div class="roles-head-text">
            <h1><?= te('Rôles') ?></h1>
            <p class="roles-lead">
                <?= strtr(teb('Ce que chaque rôle permet de {b}voir{/b}, d’{b}utiliser{/b}, de {b}créer{/b}, de {b}modifier{/b}, de {b}supprimer{/b} et de {b}gérer{/b} dans {app}. Chaque compte porte un rôle ({comptes}) ; ses {groupes} décident en plus des assistants, outils et modèles qui lui sont ouverts.', ['app' => $appName]), [
                    '{comptes}' => '<a href="/admin/users">' . te('Personnes → Comptes') . '</a>',
                    '{groupes}' => '<a href="/admin/user-groups">' . te('groupes') . '</a>',
                ]) ?>
            </p>
            <p class="roles-tiphint"><?= $infoIcon ?><span><?= te('Survolez, touchez ou atteignez au clavier un en-tête de colonne, une ligne ou une case : une bulle dit ce qu’il ouvre.') ?></span></p>
        </div>
        <button type="button" class="btn-primary roles-new-btn" id="roleNewBtn"<?= $canAddRole ? '' : ' disabled title="' . te('{n} rôles personnalisés au plus : supprimez-en un pour en créer un autre.', ['n' => Role::MAX_CUSTOM]) . '"' ?>>
            <?= $svg('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>', 16) ?>
            <span><?= te('Nouveau rôle') ?></span>
        </button>
    </div>

    <?php if ($orphanCount > 0): ?>
        <div class="alert alert-error roles-alert" role="note">
            <?= str_replace('{lien}', '<a href="/admin/users">' . te('Personnes → Comptes') . '</a>', htmlspecialchars(I18n::plural(
                $orphanCount,
                '{n} compte porte un rôle qui n’existe plus : il a les droits d’un utilisateur. Donnez-lui un rôle dans {lien}.',
                '{n} comptes portent un rôle qui n’existe plus : ils ont les droits d’un utilisateur. Donnez-leur un rôle dans {lien}.'
            ))) ?>
        </div>
    <?php endif; ?>

    <div class="roles-layout">

        <!-- La liste des rôles : livrés, puis personnalisés. -->
        <nav class="roles-list" aria-label="<?= te('Rôles') ?>">
            <?php foreach (['system' => t('Rôles livrés'), 'custom' => t('Rôles personnalisés')] as $group => $groupLabel): ?>
                <?php $inGroup = array_filter($roles, static fn(array $r): bool => $r['system'] === ($group === 'system')); ?>
                <div class="roles-list-label"><?= htmlspecialchars($groupLabel) ?></div>
                <?php if ($inGroup === []): ?>
                    <p class="roles-list-empty"><?= te('Aucun pour l’instant. Créez-en un, ou dupliquez un rôle livré : le nouveau rôle part de ses droits, vous les ajustez.') ?></p>
                <?php endif; ?>
                <?php foreach ($inGroup as $r): $n = (int)($counts[$r['slug']] ?? 0); ?>
                    <a href="<?= htmlspecialchars($roleUrl($r['slug'])) ?>" data-role-card
                       class="role-card<?= $r['slug'] === $slug ? ' is-current' : '' ?>"
                       style="--role-color:<?= htmlspecialchars((string)$r['color']) ?>"
                       <?= $r['slug'] === $slug ? 'aria-current="page"' : '' ?>>
                        <span class="role-card-dot" aria-hidden="true"></span>
                        <span class="role-card-text">
                            <span class="role-card-name">
                                <span class="role-card-name-text"><?= htmlspecialchars((string)$r['name']) ?></span>
                                <?php if ($r['locked']): ?><span class="role-card-lock" title="<?= te('Rôle verrouillé') ?>"><?= $lockIcon ?></span><?php endif; ?>
                                <?php if (!empty($r['customized'])): ?><span class="role-chip is-mod"><?= te('modifié', [], 'role') ?></span><?php endif; ?>
                            </span>
                            <span class="role-card-line"><?= htmlspecialchars($cardLine($r)) ?></span>
                        </span>
                        <span class="role-card-count" title="<?= htmlspecialchars(I18n::plural($n, '{n} compte porte ce rôle', '{n} comptes portent ce rôle')) ?>"><?= $n ?><span class="sr-only"> <?= htmlspecialchars(I18n::plural($n, 'compte', 'comptes')) ?></span></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="roles-main" id="roleEditor">
            <?php if (!empty($success)): ?><div class="alert alert-success roles-alert" role="status"><?= htmlspecialchars((string)$success) ?></div><?php endif; ?>
            <?php if ($pageError !== null): ?><div class="alert alert-error roles-alert" role="alert"><?= htmlspecialchars($pageError) ?></div><?php endif; ?>

            <!-- Les droits du rôle choisi. -->
            <section class="card roles-editor" style="--role-color:<?= htmlspecialchars((string)$role['color']) ?>" aria-labelledby="roleTitle">
                <form method="POST" action="/admin/roles/<?= htmlspecialchars(rawurlencode($slug)) ?>" id="roleForm"
                      data-locked="<?= $locked ? '1' : '0' ?>" data-members="<?= $members ?>">
                    <?= Csrf::field() ?>

                    <header class="role-editor-head">
                        <span class="role-editor-badge" aria-hidden="true"><?= $featureIcon('roles') ?></span>
                        <div class="role-editor-id">
                            <?php if ($isCustom): ?>
                                <h2 id="roleTitle" class="sr-only"><?= htmlspecialchars((string)$role['name']) ?></h2>
                                <label class="sr-only" for="roleName"><?= te('Nom du rôle') ?></label>
                                <input type="text" id="roleName" name="name" class="role-name-input" maxlength="<?= Role::NAME_MAX ?>"
                                       value="<?= htmlspecialchars((string)$role['name']) ?>" required autocomplete="off" data-track>
                            <?php else: ?>
                                <h2 id="roleTitle" class="role-editor-title"><?= htmlspecialchars((string)$role['name']) ?></h2>
                            <?php endif; ?>
                            <div class="role-editor-meta">
                                <?php if ($locked): ?>
                                    <span class="role-chip is-lock"><?= $lockIcon ?><?= te('Verrouillé') ?></span>
                                <?php elseif (!$isCustom): ?>
                                    <span class="role-chip"><?= te('Rôle livré') ?></span>
                                    <?php if (!empty($role['customized'])): ?><span class="role-chip is-mod"><?= te('droits modifiés') ?></span><?php endif; ?>
                                <?php else: ?>
                                    <span class="role-chip"><?= te('Rôle personnalisé') ?></span>
                                <?php endif; ?>
                                <a class="role-members" href="/admin/users?role=<?= htmlspecialchars(rawurlencode($slug)) ?>">
                                    <?= $svg('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>', 14) ?>
                                    <?= $members === 0 ? te('Aucun compte') : htmlspecialchars(I18n::plural($members, '{n} compte', '{n} comptes')) ?>
                                </a>
                            </div>
                        </div>
                        <?php if ($isCustom): ?>
                        <div class="role-colors" role="radiogroup" aria-label="<?= te('Couleur du rôle') ?>">
                            <?php foreach (Role::COLORS as $color): ?>
                                <label class="role-color" style="--swatch:<?= $color ?>" title="<?= te('Couleur {color}', ['color' => $color]) ?>">
                                    <input type="radio" name="color" value="<?= $color ?>"<?= $color === $role['color'] ? ' checked' : '' ?> data-track>
                                    <span class="role-color-dot" aria-hidden="true"></span>
                                    <span class="sr-only"><?= te('Couleur {color}', ['color' => $color]) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </header>

                    <?php if ($isCustom): ?>
                        <label class="sr-only" for="roleDescription"><?= te('Description du rôle') ?></label>
                        <textarea id="roleDescription" name="description" class="role-description-input" rows="2"
                                  maxlength="<?= Role::DESCRIPTION_MAX ?>"
                                  placeholder="<?= te('À quoi sert ce rôle ? Par exemple : « Gère les comptes et les groupes, sans toucher aux réglages. »') ?>"
                                  data-track><?= htmlspecialchars((string)$role['description']) ?></textarea>
                    <?php else: ?>
                        <p class="role-description"><?= htmlspecialchars((string)$role['description']) ?></p>
                    <?php endif; ?>

                    <?php if ($locked): ?>
                        <div class="role-locked-note">
                            <?= $lockIcon ?>
                            <span><?= teb('L’administrateur a {b}tous les droits{/b}, rôles et données compris : il reste toujours quelqu’un capable de tout régler. Ses droits ne se modifient pas — pour déléguer une partie de l’administration, dupliquez-le ou créez un rôle personnalisé.') ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- En résumé : recalculé par roles.js à chaque case. -->
                    <div class="role-summary">
                        <span class="role-summary-label"><?= te('En résumé') ?></span>
                        <div class="role-summary-body" id="roleSummary" aria-live="polite">
                            <span class="role-summary-text"><?= htmlspecialchars($summaryLine($role['permissions'], $locked)) ?></span>
                        </div>
                    </div>

                    <?php foreach (Permissions::sections() as $section => $sectionDef): ?>
                    <section class="role-section" data-section="<?= htmlspecialchars($section) ?>" aria-labelledby="roleSection-<?= $section ?>">
                        <div class="role-section-head">
                            <h3 id="roleSection-<?= $section ?>"><?= htmlspecialchars($sectionDef['label']) ?></h3>
                            <button type="button" class="role-info-btn" data-tip="tips-<?= $section ?>" aria-describedby="tips-<?= $section ?>"
                                    aria-label="<?= te('À propos : {section}', ['section' => $sectionDef['label']]) ?>"><?= $infoIcon ?></button>
                            <span class="role-tip-src" id="tips-<?= $section ?>" hidden><?= $tipHtml($sectionDef['label'], $sectionDef['summary']) ?></span>
                        </div>
                        <table class="role-matrix">
                            <thead>
                                <tr>
                                    <th scope="col" class="role-col-feature"><span class="role-cols-label"><?= te('Colonnes') ?></span><span class="sr-only"><?= te('Fonctionnalité') ?></span></th>
                                    <?php foreach (Permissions::ACTIONS as $action): $colTip = 'tipc-' . $section . '-' . $action; ?>
                                        <th scope="col" class="role-col-action" data-action="<?= $action ?>">
                                            <button type="button" class="role-col-btn" data-tip="<?= $colTip ?>" aria-describedby="<?= $colTip ?>">
                                                <span class="role-col-ic"><?= $actionIcons[$action] ?></span>
                                                <span class="role-col-label"><?= htmlspecialchars(Permissions::actionLabel($action)) ?></span>
                                            </button>
                                            <span class="role-tip-src" id="<?= $colTip ?>" hidden><?= $tipHtml(
                                                Permissions::actionLabel($action),
                                                Permissions::actionDefinition($action),
                                                in_array($action, [Permissions::VIEW, Permissions::USE], true) ? Permissions::viewUseNote() : ''
                                            ) ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (Permissions::featuresIn($section) as $feature => $def):
                                    $rowTip = 'tipr-' . $feature;
                                    $rowList = [];
                                    foreach (Permissions::ACTIONS as $action) {
                                        if (isset($def['actions'][$action])) {
                                            $rowList[Permissions::actionLabel($action)] = $def['actions'][$action];
                                        }
                                    } ?>
                                <tr class="role-row<?= $featureOn($feature) ? ' is-on' : '' ?>" data-feature="<?= htmlspecialchars($feature) ?>">
                                    <th scope="row" class="role-row-name">
                                        <button type="button" class="role-row-btn" data-tip="<?= $rowTip ?>" aria-describedby="<?= $rowTip ?>">
                                            <span class="role-row-ic"><?= $featureIcon($feature) ?></span>
                                            <span class="role-row-label"><?= htmlspecialchars($def['label']) ?></span>
                                            <?php if (!empty($def['personal'])): ?><span class="role-row-tag"><?= te('propre à chacun') ?></span><?php endif; ?>
                                        </button>
                                        <span class="role-tip-src" id="<?= $rowTip ?>" hidden><?= $tipHtml($def['label'], $def['summary'], '', $rowList) ?></span>
                                    </th>
                                    <?php foreach (Permissions::ACTIONS as $action):
                                        $key = Permissions::key($feature, $action);
                                        $cellTip = 'tip-' . $feature . '-' . $action; ?>
                                        <?php if (isset($def['actions'][$action])): ?>
                                        <td class="role-cell-td" data-action="<?= $action ?>">
                                            <label class="role-cell" data-tip="<?= $cellTip ?>">
                                                <input type="checkbox" class="role-cb" name="perms[<?= htmlspecialchars($feature) ?>][]"
                                                       value="<?= $action ?>" data-key="<?= htmlspecialchars($key) ?>" aria-describedby="<?= $cellTip ?>"
                                                       <?= isset($granted[$key]) ? 'checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
                                                <span class="role-tick"><?= $tickIcon ?></span>
                                                <span class="role-cell-label" aria-hidden="true"><?= htmlspecialchars(Permissions::actionLabel($action)) ?></span>
                                                <span class="sr-only"><?= htmlspecialchars($def['label'] . ' — ' . Permissions::actionLabel($action)) ?></span>
                                            </label>
                                            <span class="role-tip-src" id="<?= $cellTip ?>" hidden><?= $tipHtml(
                                                $def['label'] . ' · ' . Permissions::actionLabel($action),
                                                $def['actions'][$action],
                                                '',
                                                [],
                                                $requiresLine($feature, $action)
                                            ) ?></span>
                                        </td>
                                        <?php else: ?>
                                        <td class="role-cell-td is-na" data-action="<?= $action ?>">
                                            <span class="role-na" data-tip="<?= $cellTip ?>" aria-hidden="true">—</span>
                                            <span class="role-tip-src sr-only" id="<?= $cellTip ?>"><?= $tipHtml(
                                                t('{feature} · {action} : sans objet', ['feature' => $def['label'], 'action' => Permissions::actionLabel($action)]),
                                                Permissions::naReason($feature, $action)
                                            ) ?></span>
                                        </td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                    <?php endforeach; ?>

                    <!-- Rôles et données : jamais délégués. -->
                    <div class="role-adminonly" role="group" aria-labelledby="roleAdminOnlyLabel">
                        <span class="role-adminonly-label" id="roleAdminOnlyLabel"><?= $lockIcon ?><?= te('Réservé aux administrateurs') ?></span>
                        <?php foreach (Permissions::adminOnly() as $domain => $domainDef): $aoTip = 'tipa-' . $domain; ?>
                            <button type="button" class="role-adminonly-item" data-tip="<?= $aoTip ?>" aria-describedby="<?= $aoTip ?>">
                                <span class="role-adminonly-ic"><?= $featureIcon($domain) ?></span>
                                <span><?= htmlspecialchars($domainDef['label']) ?></span>
                                <span class="role-chip is-lock"><?= $slug === Role::ADMIN ? te('Ouvert') : te('Administrateur seul') ?></span>
                            </button>
                            <span class="role-tip-src" id="<?= $aoTip ?>" hidden><?= $tipHtml(
                                $domainDef['label'],
                                $domainDef['summary'],
                                '',
                                [],
                                t('Ne se délègue pas, quel que soit le rôle.')
                            ) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <!-- Barre d'actions, collée en bas : ce qui changera, et le geste. -->
                    <div class="role-actions-bar<?= $locked ? ' is-static' : '' ?>" id="roleActionsBar">
                        <div class="role-actions-left">
                            <?php if ($isCustom): ?>
                                <button type="button" class="btn-danger-link" id="roleDeleteBtn"><?= te('Supprimer le rôle') ?></button>
                            <?php elseif (!$locked && !empty($role['customized'])): ?>
                                <button type="submit" class="btn-danger-link" form="roleResetForm" data-leave
                                        data-confirm="<?= te('Rétablir les droits par défaut du rôle « {name} » ? Les droits modifiés ici sont perdus.', ['name' => (string)$role['name']]) ?>"><?= te('Rétablir les droits par défaut') ?></button>
                            <?php endif; ?>
                            <button type="submit" class="roles-link-btn" form="roleDuplicateForm" data-leave data-duplicate
                                    title="<?= te('Créer un rôle personnalisé qui part de ces droits') ?>">
                                <?= $svg('<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>', 14) ?>
                                <?= te('Dupliquer') ?>
                            </button>
                        </div>
                        <?php if (!$locked): ?>
                        <div class="role-actions-right">
                            <span class="role-dirty" id="roleDirty" aria-live="polite"></span>
                            <button type="button" class="btn-secondary" id="roleCancelBtn" disabled><?= te('Annuler') ?></button>
                            <button type="submit" class="btn-primary" id="roleSaveBtn" disabled><?= te('Enregistrer') ?></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>

                <form method="POST" action="/admin/roles/<?= htmlspecialchars(rawurlencode($slug)) ?>/duplicate" id="roleDuplicateForm" hidden><?= Csrf::field() ?></form>
                <?php if (!$isCustom && !$locked): ?>
                    <form method="POST" action="/admin/roles/<?= htmlspecialchars(rawurlencode($slug)) ?>/reset" id="roleResetForm" hidden><?= Csrf::field() ?></form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<!-- Nouveau rôle -->
<div class="modal roles-modal-wrap" id="roleNewModal" style="display:none" aria-hidden="true">
    <form method="POST" action="/admin/roles" class="modal-box roles-modal" id="roleNewForm" role="dialog" aria-modal="true" aria-labelledby="roleNewTitle">
        <?= Csrf::field() ?>
        <header class="roles-modal-head">
            <h3 id="roleNewTitle"><?= te('Nouveau rôle') ?></h3>
            <button type="button" class="roles-modal-x" data-close aria-label="<?= te('Fermer') ?>">×</button>
        </header>
        <div class="roles-modal-body">
            <?php if ($modalError !== null): ?><div class="alert alert-error roles-modal-alert" role="alert"><?= htmlspecialchars($modalError) ?></div><?php endif; ?>
            <div class="form-group">
                <label for="roleNewName"><?= te('Nom') ?></label>
                <input type="text" id="roleNewName" name="name" class="form-input" maxlength="<?= Role::NAME_MAX ?>" required autocomplete="off"
                       placeholder="<?= te('Responsable des assistants, Support, Direction…') ?>">
            </div>
            <div class="form-group">
                <label for="roleNewDescription"><?= te('Description') ?> <span class="roles-optional"><?= te('(facultative)') ?></span></label>
                <textarea id="roleNewDescription" name="description" class="form-input" rows="2" maxlength="<?= Role::DESCRIPTION_MAX ?>"
                          placeholder="<?= te('À quoi sert ce rôle ?') ?>"></textarea>
            </div>
            <div class="form-group">
                <span class="roles-field-label" id="roleNewFromLabel"><?= te('Partir des droits de') ?></span>
                <div class="roles-from" role="radiogroup" aria-labelledby="roleNewFromLabel">
                    <?php foreach ($roles as $r): ?>
                        <label class="roles-from-opt" style="--role-color:<?= htmlspecialchars((string)$r['color']) ?>">
                            <input type="radio" name="from" value="<?= htmlspecialchars($r['slug']) ?>"<?= $r['slug'] === Role::USER ? ' checked' : '' ?>>
                            <span class="role-card-dot" aria-hidden="true"></span>
                            <span><?= htmlspecialchars((string)$r['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <label class="roles-from-opt" style="--role-color:#9ca3af">
                        <input type="radio" name="from" value="none">
                        <span class="role-card-dot" aria-hidden="true"></span>
                        <span><?= te('Aucun droit') ?></span>
                    </label>
                </div>
                <p class="roles-hint"><?= te('Les droits se règlent ensuite case par case. Partir d’« Administrateur » donne tout, sauf les rôles et les données, qui restent aux administrateurs.') ?></p>
            </div>
            <div class="form-group">
                <span class="roles-field-label" id="roleNewColorLabel"><?= te('Couleur') ?></span>
                <div class="role-colors" role="radiogroup" aria-labelledby="roleNewColorLabel">
                    <?php foreach (Role::COLORS as $i => $color): ?>
                        <label class="role-color" style="--swatch:<?= $color ?>" title="<?= te('Couleur {color}', ['color' => $color]) ?>">
                            <input type="radio" name="color" value="<?= $color ?>"<?= $i === 0 ? ' checked' : '' ?>>
                            <span class="role-color-dot" aria-hidden="true"></span>
                            <span class="sr-only"><?= te('Couleur {color}', ['color' => $color]) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <footer class="roles-modal-foot">
            <button type="button" class="btn-secondary" data-close><?= te('Annuler') ?></button>
            <button type="submit" class="btn-primary"><?= te('Créer le rôle') ?></button>
        </footer>
    </form>
</div>

<?php if ($isCustom): ?>
<!-- Supprimer un rôle personnalisé : ses comptes changent de rôle. -->
<div class="modal roles-modal-wrap" id="roleDeleteModal" style="display:none" aria-hidden="true">
    <form method="POST" action="/admin/roles/<?= htmlspecialchars(rawurlencode($slug)) ?>/delete" class="modal-box roles-modal" role="dialog" aria-modal="true" aria-labelledby="roleDeleteTitle">
        <?= Csrf::field() ?>
        <header class="roles-modal-head">
            <h3 id="roleDeleteTitle"><?= te('Supprimer « {name} » ?', ['name' => (string)$role['name']]) ?></h3>
            <button type="button" class="roles-modal-x" data-close aria-label="<?= te('Fermer') ?>">×</button>
        </header>
        <div class="roles-modal-body">
            <?php if ($members > 0): ?>
                <p><strong><?= htmlspecialchars(I18n::plural($members, '{n} compte porte ce rôle.', '{n} comptes portent ce rôle.')) ?></strong> <?= htmlspecialchars(I18n::plural($members, 'Il prendra celui-ci :', 'Ils prendront celui-ci :')) ?></p>
            <?php else: ?>
                <p><?= te('Aucun compte ne porte ce rôle. S’il en reçoit un d’ici là, il prendra celui-ci :') ?></p>
            <?php endif; ?>
            <div class="form-group">
                <label for="roleDeleteFallback" class="sr-only"><?= te('Rôle de remplacement') ?></label>
                <select id="roleDeleteFallback" name="fallback" class="form-select">
                    <?php foreach ($roles as $r): if ($r['slug'] === $slug || $r['slug'] === Role::ADMIN) { continue; } ?>
                        <option value="<?= htmlspecialchars($r['slug']) ?>"<?= $r['slug'] === Role::USER ? ' selected' : '' ?>><?= htmlspecialchars((string)$r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="roles-hint"><?= te('Le rôle Administrateur n’est jamais proposé : une suppression ne promeut personne. La suppression est définitive.') ?></p>
        </div>
        <footer class="roles-modal-foot">
            <button type="button" class="btn-secondary" data-close><?= te('Annuler') ?></button>
            <button type="submit" class="btn-danger" data-leave><?= te('Supprimer le rôle') ?></button>
        </footer>
    </form>
</div>
<?php endif; ?>

<script>
// Catalogue des droits (Permissions::clientCatalog) : libellés et implications
// — roles.js rejoue la même fermeture que le serveur.
window.ROLE_PAGE = <?= json_encode([
    'catalog' => $catalog,
    'openNew' => (bool)$openNew,
    'locked'  => $locked,
    'custom'  => $isCustom,
    'members' => $members,
    'name'    => (string)$role['name'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/roles.js?v=<?= (int)$rolesJsVersion ?>"></script>
</body>
</html>
