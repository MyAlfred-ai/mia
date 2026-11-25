<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Comptes') ?></title>
    <?php $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php $page = 'users'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <?php require ROOT . '/templates/partials/admin-topbar.php'; ?>

    <?php
        $currentUser = Session::currentUser();

        // Rôles (Personnes → Rôles, source unique : Role). Les rôles livrés
        // gardent leurs pastilles ; un rôle personnalisé porte sa couleur.
        $roleLabels  = ['admin' => t('Admin'), 'creator' => t('Créateur'), 'user' => t('Utilisateur')];
        foreach ($allRoles as $roleSlug => $roleDef) {
            if (!isset($roleLabels[$roleSlug])) {
                $roleLabels[$roleSlug] = (string)$roleDef['name'];
            }
        }
        // Nom affiché d'un groupe : le groupe par défaut porte le nom LIVRÉ
        // « Par défaut », qui suit la langue de l'écran tant que personne ne
        // l'a renommé (I18n::editorial) ; un nom écrit par un administrateur
        // reste tel quel. Affichage seulement : jamais dans un champ.
        $groupLabel = static fn(array $g): string => !empty($g['is_default'])
            ? I18n::editorial((string)$g['name'], 'Par défaut')
            : (string)$g['name'];
        $roleBadges  = ['admin' => 'badge-green', 'creator' => 'badge-yellow', 'user' => 'badge-gray'];
        // Badge d'un rôle : classe des rôles livrés, couleur d'un rôle personnalisé,
        // mention « inconnu » pour un rôle disparu (droits d'un utilisateur).
        $roleBadge = static function (string $slug) use ($roleLabels, $roleBadges, $allRoles): string {
            if (isset($roleBadges[$slug])) {
                return '<span class="badge ' . $roleBadges[$slug] . '">' . htmlspecialchars($roleLabels[$slug]) . '</span>';
            }
            if (isset($allRoles[$slug])) {
                return '<span class="badge role-badge-custom" style="--role-color:' . htmlspecialchars((string)$allRoles[$slug]['color']) . '">'
                    . htmlspecialchars((string)$allRoles[$slug]['name']) . '</span>';
            }
            return '<span class="badge badge-gray" title="' . te('Rôle disparu : ce compte a les droits d’un utilisateur') . '">' . te('Rôle inconnu') . '</span>';
        };
        // Phrase courte de chaque rôle dans les listes de choix.
        $roleOptionLabel = static function (array $role): string {
            return match ($role['slug']) {
                'admin'   => t('Administrateur — accès complet'),
                'creator' => t('Créateur — crée ses assistants'),
                'user'    => t('Utilisateur'),
                default   => (string)$role['name'],
            };
        };

        // Date relative compacte pour la colonne Activité.
        $relativeDay = static function (?string $dt): string {
            if (!$dt) {
                return '';
            }
            $ts = strtotime($dt);
            if ($ts === false) {
                return '';
            }
            $days = (int)floor((time() - $ts) / 86400);
            if ($days <= 0) return t("aujourd'hui");
            if ($days === 1) return t('hier');
            if ($days <= 30) return t('il y a {n} j', ['n' => $days]);
            return Format::dateFr(date('Y-m-d', $ts));
        };

        // Une passe de pré-calcul par utilisateur : quota effectif, %, auth,
        // payload JSON de la modale — et les compteurs des tuiles de synthèse.
        //
        // Fournisseurs de connexion : UNE requête pour toute la page. Le mode
        // d'authentification se devinait auparavant en reniflant `google_id`,
        // colonne qui portait trois encodages différents et ne savait pas
        // décrire un compte Microsoft.
        $providersByUser = UserIdentity::providersForUsers(array_map(
            static fn(array $u): int => (int)$u['id'],
            $users
        ));
        $rows = [];
        $countActive = 0; $countInactive = 0; $countAdmins = 0; $countOverQuota = 0;
        // Comptes de démo (DemoAccount::accounts) : enveloppe et consommation
        // depuis le début de LEUR démo, à la place du quota mensuel.
        $countDemo = 0; $countDemoEnded = 0;
        $demoAccounts = $demoAccounts ?? [];
        foreach ($users as $u) {
            $uid          = (int)$u['id'];
            $monthCredits = (int)($monthCreditsByUser[$uid] ?? 0);
            $userGroups   = $groupsByUser[$uid] ?? [];
            $groupQuota   = $effectiveGroupQuotas[$uid] ?? null;
            $userQuota    = User::individualQuotaK($u);
            // Le plus généreux du compte et de ses groupes (User::effectiveCreditQuotaKFrom) :
            // la même règle que celle appliquée au moment de consommer.
            $effectiveQuota = User::effectiveCreditQuotaKFrom($u, $groupQuota);
            $quotaMax       = $effectiveQuota !== null ? $effectiveQuota * 1_000 : null;
            if ($quotaMax === null) {
                $pct = 0;
            } elseif ($quotaMax <= 0) {
                $pct = 100;
            } else {
                $pct = min(100, (int)round($monthCredits / $quotaMax * 100));
            }

            $isLocalPw    = !empty($u['password_hash']);
            $userProviders = $providersByUser[$uid] ?? [];
            $isGoogle     = in_array(UserIdentity::GOOGLE, $userProviders, true);
            $isLocalAdmin = ($u['google_id'] ?? '') === 'local_admin';
            $authLabel    = UserIdentity::authLabel($userProviders, $isLocalPw, $isLocalAdmin);
            $authMethods  = UserIdentity::authMethods($userProviders, $isLocalPw, $isLocalAdmin);
            $quotaSource  = match (User::quotaOrigin($userQuota, $groupQuota)) {
                'user'   => t('Individuel'),
                'groups' => t('Groupes (max)'),
                default  => t('Illimité'),
            };
            $lastActivity = $lastActivityByUser[$uid] ?? null;
            $isActive     = (int)$u['is_active'] === 1;

            if ($isActive) { $countActive++; } else { $countInactive++; }
            if (($u['role'] ?? '') === 'admin') { $countAdmins++; }
            // Ce compte peut-il être modifié par celui qui regarde ? Jamais un
            // compte plus large que le sien (Role::canManageAccount).
            $canManageRow = Role::canManageAccount($currentUser, $u);
            $demo = $demoAccounts[$uid] ?? null;
            $demoPct = $demo !== null
                ? ($demo['credits'] > 0 ? min(100, (int)round($demo['used'] / $demo['credits'] * 100)) : 100)
                : null;
            if ($demo !== null) {
                $countDemo++;
                if ($demo['exhausted']) { $countDemoEnded++; }
            } elseif ($effectiveQuota !== null && $pct >= 90) {
                $countOverQuota++;
            }

            $rows[] = [
                'u'              => $u,
                'uid'            => $uid,
                'monthCredits'   => $monthCredits,
                'userGroups'     => $userGroups,
                'userQuota'      => $userQuota,
                'groupQuota'     => $groupQuota,
                'effectiveQuota' => $effectiveQuota,
                'pct'            => $pct,
                'isActive'       => $isActive,
                'isLocalPw'      => $isLocalPw,
                'authLabel'      => $authLabel,
                'authMethods'    => $authMethods,
                'quotaSource'    => $quotaSource,
                'lastActivity'   => $lastActivity,
                'demo'           => $demo,
                'demoPct'        => $demoPct,
                'payload'        => [
                    'id'            => $uid,
                    'name'          => (string)$u['name'],
                    'email'         => (string)$u['email'],
                    'avatar_url'    => (string)($u['avatar_url'] ?? ''),
                    'role'          => (string)$u['role'],
                    'is_active'     => $isActive,
                    'auth_label'    => $authLabel,
                    'is_local_pw'   => $isLocalPw,
                    'created_at'    => substr((string)$u['created_at'], 0, 10),
                    'quota_user'    => $userQuota,
                    'quota_group'   => $groupQuota,
                    'month_credits' => $monthCredits,
                    'total_credits' => (int)($creditsByUser[$uid] ?? 0),
                    'last_activity' => $lastActivity ? substr($lastActivity, 0, 10) : '',
                    'group_ids'     => array_map(static fn(array $g): int => (int)$g['id'], $userGroups),
                    'is_self'       => $uid === (int)$currentUser['id'],
                    // Droits de celui qui regarde SUR ce compte (la modale se met
                    // en lecture seule, masque suppression et mot de passe).
                    'can_edit'      => $canEditUser && $canManageRow,
                    // Pourquoi la fiche est en lecture : le droit manque, ou le
                    // compte a des droits que le rôle de celui qui regarde n'a pas.
                    'locked_reason' => !$canEditUser ? 'right' : (!$canManageRow ? 'wider' : ''),
                    'can_delete'    => $canDeleteUser && $canManageRow && $uid !== (int)$currentUser['id'],
                    // Compte de démo : enveloppe et consommation depuis son début.
                    'demo'          => $demo !== null ? [
                        'credits'   => $demo['credits'],
                        'used'      => $demo['used'],
                        'remaining' => $demo['remaining'],
                        'exhausted' => $demo['exhausted'],
                        'muted'     => $demo['muted'],
                        'started'   => Format::dateFr(substr($demo['started_at'], 0, 10)),
                    ] : null,
                    // Peut-il devenir un compte de démo ? Jamais un administrateur.
                    'demo_allowed'  => DemoAccount::allowedForRole((string)$u['role']),
                ],
            ];
        }
    ?>

    <div class="um-header page-head">
        <div class="page-head-text">
            <h1><?= te('Comptes') ?></h1>
            <p class="page-head-sub">
                <?= te('Comptes, rôles, groupes et quotas mensuels.') ?>
                <?php
                    // Phrase ENTIÈRE dans une clé : les liens y entrent par
                    // substitution APRÈS l'échappement (te() ne voit que du texte).
                    $lienGroupes = '<a href="/admin/user-groups" style="color:var(--accent)">' . te('Personnes → Groupes') . '</a>';
                    $lienRoles   = '<a href="/admin/roles" style="color:var(--accent)">' . te('Personnes → Rôles') . '</a>';
                ?>
                <?php if (!empty($canOpenRoles)): ?>
                <?= strtr(te('Ce que chaque groupe ouvre (assistants, outils, modèles) se règle dans {groupes}, ce que chaque rôle peut faire dans {roles}.'), ['{groupes}' => $lienGroupes, '{roles}' => $lienRoles]) ?>
                <?php else: ?>
                <?= strtr(te('Ce que chaque groupe ouvre (assistants, outils, modèles) se règle dans {groupes}.'), ['{groupes}' => $lienGroupes]) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if (!empty($canCreateUser)): ?>
        <button type="button" class="btn-primary page-head-primary" id="btnNewUser">+ <?= te('Nouvel utilisateur') ?></button>
        <?php endif; ?>
    </div>

    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"   style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Tuiles de synthèse : cliquer filtre la liste (re-cliquer = tout afficher). -->
    <div class="um-stats" id="umStats">
        <button type="button" class="um-stat" data-stat="all">
            <span class="um-stat-value"><?= count($rows) ?></span>
            <span class="um-stat-label"><?= te('Comptes') ?></span>
        </button>
        <button type="button" class="um-stat" data-stat="active">
            <span class="um-stat-value" style="color:#22c55e"><?= $countActive ?></span>
            <span class="um-stat-label"><?= te('Actifs') ?></span>
        </button>
        <button type="button" class="um-stat" data-stat="inactive" <?= $countInactive > 0 ? 'title="' . te('Comptes à approuver ou à nettoyer') . '"' : '' ?>>
            <span class="um-stat-value" style="color:<?= $countInactive > 0 ? '#ef4444' : 'var(--text)' ?>"><?= $countInactive ?></span>
            <span class="um-stat-label"><?= te('Inactifs') ?></span>
        </button>
        <button type="button" class="um-stat" data-stat="admins">
            <span class="um-stat-value"><?= $countAdmins ?></span>
            <span class="um-stat-label"><?= te('Admins') ?></span>
        </button>
        <button type="button" class="um-stat" data-stat="overquota" title="<?= te('Utilisateurs à 90 % ou plus de leur quota mensuel') ?>">
            <span class="um-stat-value" style="color:<?= $countOverQuota > 0 ? '#f59e0b' : 'var(--text)' ?>"><?= $countOverQuota ?></span>
            <span class="um-stat-label"><?= te('Quota ≥ 90 %') ?></span>
        </button>
        <?php if ($countDemo > 0): ?>
        <button type="button" class="um-stat" data-stat="demo" title="<?= te('Comptes de démonstration : enveloppe de crédits sans renouvellement mensuel') ?>">
            <span class="um-stat-value"><?= $countDemo ?></span>
            <span class="um-stat-label"><?= te('Démos') ?></span>
        </button>
        <button type="button" class="um-stat" data-stat="demoended" title="<?= te('Démos dont les crédits sont épuisés : MIA ne leur répond plus') ?>">
            <span class="um-stat-value" style="color:<?= $countDemoEnded > 0 ? '#f59e0b' : 'var(--text)' ?>"><?= $countDemoEnded ?></span>
            <span class="um-stat-label"><?= te('Démos épuisées') ?></span>
        </button>
        <?php endif; ?>
    </div>

    <!--
        Règles d'accès : ce qu'un compte obtient à sa création, et quels comptes
        sont reconnus comme « équipe ». Deux réglages qui vivaient dans les
        réglages généraux, loin de l'écran où l'on constate leur effet (la tuile
        « Inactifs » juste au-dessus). Route dédiée : l'auto-save reposte le
        formulaire ENTIER sur sa propre action.
    -->
    <?php
        // Résumé de la ligne repliée : l'état des deux réglages, lisible sans ouvrir.
        $accessSummary = ($newUsersActive ? t('Nouveaux comptes actifs d’office') : t('Nouveaux comptes à approuver'))
            . ' · ' . ($staffEmailDomain !== '' ? t('Équipe : {domaine}', ['domaine' => $staffEmailDomain]) : t('Aucun domaine d’équipe'));
    ?>
    <details class="card admin-fold" style="margin-bottom:16px">
        <summary class="admin-fold-head">
            <span class="admin-fold-icon" aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
            <span class="admin-fold-text">
                <span class="admin-fold-title"><?= te("Règles d'accès") ?></span>
                <span class="admin-fold-summary"><?= htmlspecialchars($accessSummary) ?></span>
            </span>
            <span class="admin-fold-action"><?= te('Modifier') ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span>
        </summary>
        <div class="admin-fold-body">
        <form method="POST" action="/admin/users/access-rules"<?= !empty($canEditUser) ? ' data-autosave' : '' ?>>
            <?= Csrf::field() ?>
            <?php // Règles d'accès : droit « Comptes — Modifier ». Sans lui, affichées en lecture seule. ?>
            <fieldset class="um-access-fieldset"<?= !empty($canEditUser) ? '' : ' disabled' ?>>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;width:fit-content">
                <input type="checkbox" name="new_users_active" value="1"
                       <?= $newUsersActive ? 'checked' : '' ?>
                       style="width:16px;height:16px;accent-color:var(--accent)">
                <span style="font-size:14px"><?= te('Activer automatiquement les nouveaux utilisateurs') ?></span>
            </label>
            <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0 26px">
                <?= teb('Décochée, chaque nouveau compte arrive {b}inactif{/b} et doit être approuvé à la main dans la liste ci-dessous.') ?>
            </p>

            <div class="form-group" style="margin:18px 0 0;max-width:420px">
                <label class="form-label"><?= te("Domaine e-mail de l'équipe") ?></label>
                <input type="text" name="staff_email_domain" class="form-input"
                       value="<?= htmlspecialchars($staffEmailDomain) ?>"
                       placeholder="<?= te('exemple.fr') ?>">
                <p style="font-size:12px;color:var(--text-muted);margin-top:6px">
                    <?= te("Les comptes dont l'adresse se termine par ce domaine voient les éléments réservés à l'équipe (détail des correctifs dans l'historique des versions). Vide = personne.") ?>
                </p>
            </div>

            </fieldset>
            <?php if (!empty($canEditUser)): ?>
            <div style="margin-top:14px">
                <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
            </div>
            <?php endif; ?>
        </form>
        </div>
    </details>

    <!-- Recherche + filtres -->
    <div class="um-toolbar">
        <input type="search" id="umSearch" class="form-input um-search" placeholder="<?= te('Rechercher un nom ou un email…') ?>" autocomplete="off">
        <select id="umFilterRole" class="form-select um-filter">
            <option value=""><?= te('Tous les rôles') ?></option>
            <?php foreach ($allRoles as $roleSlug => $roleDef): ?>
                <option value="<?= htmlspecialchars($roleSlug) ?>"><?= htmlspecialchars($roleLabels[$roleSlug] ?? (string)$roleDef['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php
            // Filtre par groupe : chaque groupe de la page (actifs et inactifs),
            // puis les comptes qui n'ont que le groupe par défaut — tout le
            // monde en fait partie, le choisir ne filtrerait rien d'utile.
            $defaultGroup = null;
            foreach ($allGroups as $g) {
                if (!empty($g['is_default'])) { $defaultGroup = $g; break; }
            }
        ?>
        <select id="umFilterGroup" class="form-select um-filter um-filter-group">
            <option value=""><?= te('Tous les groupes') ?></option>
            <?php foreach ($allGroups as $g): ?>
                <option value="<?= (int)$g['id'] ?>"><?= !empty($g['is_default']) ? '★ ' : '' ?><?= htmlspecialchars($groupLabel($g)) ?><?= empty($g['is_active']) ? ' (' . te('inactif') . ')' : '' ?></option>
            <?php endforeach; ?>
            <?php /* Libellé court : il suit « ★ Par défaut » dans la liste, et la liste
                     prend la largeur de sa plus longue option. */ ?>
            <option value="none" title="<?= $defaultGroup !== null ? te('Comptes qui ne sont dans aucun groupe en dehors de « {nom} »', ['nom' => $groupLabel($defaultGroup)]) : te('Comptes qui ne sont dans aucun groupe') ?>"><?= $defaultGroup !== null ? te('Sans autre groupe') : te('Aucun groupe') ?></option>
        </select>
        <select id="umFilterStatus" class="form-select um-filter">
            <option value=""><?= te('Tous les statuts') ?></option>
            <option value="1"><?= te('Actifs') ?></option>
            <option value="0"><?= te('Inactifs') ?></option>
        </select>
        <select id="umFilterAuth" class="form-select um-filter">
            <option value=""><?= te('Toutes connexions') ?></option>
            <?php /* Options = méthodes de UserIdentity, source unique des libellés affichés sous le rôle. */ ?>
            <?php foreach (UserIdentity::methodLabels() as $methode => $libelle): ?>
                <option value="<?= htmlspecialchars($methode) ?>"><?= htmlspecialchars($libelle) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="um-count" id="umCount"></span>
        <button type="button" class="btn-secondary um-copy-btn" id="umCopyEmails"
                title="<?= te('Copier les emails des utilisateurs affichés (filtres appliqués) au format Prénom NOM <email>, séparés par des virgules') ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            <span><?= te('Copier les emails') ?></span>
        </button>
    </div>

    <div class="card table-wrap" id="usersListCard">
        <table class="users-table um-table">
            <thead>
                <tr>
                    <th class="um-sortable" data-sort="name"><?= te('Utilisateur') ?> <span class="um-sort-arrow"></span></th>
                    <th class="um-sortable" data-sort="role"><?= te('Rôle') ?> <span class="um-sort-arrow"></span></th>
                    <th><?= te('Groupes') ?></th>
                    <th class="um-sortable" data-sort="pct"><?= te('Quota mensuel') ?> <span class="um-sort-arrow"></span></th>
                    <th class="um-sortable" data-sort="activity"><?= te('Activité') ?> <span class="um-sort-arrow"></span></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="umTbody">
                <?php foreach ($rows as $r): $u = $r['u']; $uid = $r['uid']; ?>
                <tr class="<?= $r['isActive'] ? '' : 'um-row-inactive' ?>"
                    data-name="<?= htmlspecialchars(mb_strtolower($u['name'] . ' ' . $u['email'])) ?>"
                    data-sortname="<?= htmlspecialchars(mb_strtolower((string)$u['name'])) ?>"
                    data-role="<?= htmlspecialchars($u['role']) ?>"
                    data-active="<?= $r['isActive'] ? 1 : 0 ?>"
                    data-auth="<?= htmlspecialchars(implode(' ', $r['authMethods'])) ?>"
                    data-groups="<?= htmlspecialchars(implode(' ', array_map(static fn(array $g): int => (int)$g['id'], $r['userGroups']))) ?>"
                    data-own-groups="<?= count(array_filter($r['userGroups'], static fn(array $g): bool => empty($g['is_default']))) ?>"
                    data-pct="<?= $r['demo'] !== null ? $r['demoPct'] : ($r['effectiveQuota'] !== null ? $r['pct'] : -1) ?>"
                    data-overquota="<?= ($r['demo'] === null && $r['effectiveQuota'] !== null && $r['pct'] >= 90) ? 1 : 0 ?>"
                    data-demo="<?= $r['demo'] !== null ? 1 : 0 ?>"
                    data-demoended="<?= ($r['demo'] !== null && $r['demo']['exhausted']) ? 1 : 0 ?>"
                    data-activity="<?= htmlspecialchars($r['lastActivity'] ?? '') ?>">
                    <td data-label="<?= te('Utilisateur') ?>">
                        <div class="um-user-cell">
                            <?php if (!empty($u['avatar_url'])): ?>
                                <img class="um-avatar" src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="">
                            <?php else: ?>
                                <div class="um-avatar um-avatar-fallback"><?= mb_strtoupper(mb_substr((string)$u['name'], 0, 1)) ?></div>
                            <?php endif; ?>
                            <div class="um-user-id">
                                <span class="um-user-name">
                                    <?= htmlspecialchars($u['name']) ?>
                                    <?= AdminAttention::itemDot('user:' . $uid) ?>
                                    <?php if ($uid === (int)$currentUser['id']): ?><span class="badge badge-gray" style="font-size:10px"><?= te('vous') ?></span><?php endif; ?>
                                </span>
                                <span class="um-user-email"><?= htmlspecialchars($u['email']) ?></span>
                            </div>
                        </div>
                    </td>
                    <td data-label="<?= te('Rôle') ?>">
                        <div class="um-role-cell">
                            <?= $roleBadge((string)$u['role']) ?>
                            <?php if ($r['demo'] !== null): ?>
                                <span class="badge um-demo-badge<?= $r['demo']['exhausted'] ? ' is-ended' : '' ?>"
                                      title="<?= $r['demo']['exhausted'] ? te('Démo épuisée : MIA ne répond plus à ce compte') : te('Compte de démonstration : enveloppe de crédits sans renouvellement mensuel') ?>">
                                    <?= $r['demo']['exhausted'] ? te('Démo épuisée') : te('Démo') ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($r['authLabel'] !== ''): ?>
                                <span class="um-auth" title="<?= te('Méthode de connexion') ?>"><?= htmlspecialchars($r['authLabel']) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="<?= te('Groupes') ?>">
                        <?php if (empty($r['userGroups'])): ?>
                            <span class="um-muted"><?= te('Aucun groupe') ?></span>
                        <?php else: ?>
                            <div class="um-groups">
                                <?php
                                    $shown = 0;
                                    $extra = [];
                                    foreach ($r['userGroups'] as $g) {
                                        $isDefaultG = !empty($g['is_default']);
                                        if ($shown < 2) {
                                            $badgeClass = $isDefaultG ? 'badge-default-group' : ((int)$g['is_active'] === 1 ? 'badge-gray' : 'badge-red');
                                            echo '<span class="badge ' . $badgeClass . '" style="font-size:11px"'
                                                . ($isDefaultG ? ' title="' . te('Groupe par défaut — tous les utilisateurs') . '"' : '') . '>'
                                                . ($isDefaultG ? '★ ' : '') . htmlspecialchars($groupLabel($g)) . '</span>';
                                            $shown++;
                                        } else {
                                            $extra[] = (string)$g['name'];
                                        }
                                    }
                                    if ($extra !== []) {
                                        echo '<span class="badge badge-gray" style="font-size:11px" title="'
                                            . htmlspecialchars(implode(', ', $extra)) . '">+' . count($extra) . '</span>';
                                    }
                                ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= te('Quota mensuel') ?>">
                        <?php if ($r['demo'] !== null): $d = $r['demo']; $dp = (int)$r['demoPct']; ?>
                        <div class="um-quota">
                            <div class="um-quota-line">
                                <?= Format::credits($d['used']) ?> / <?= Format::credits($d['credits']) ?>
                                <span style="color:<?= $dp >= 90 ? '#ef4444' : 'var(--text-muted)' ?>">(<?= te('{n} %', ['n' => $dp]) ?>)</span>
                            </div>
                            <div class="um-quota-bar">
                                <div style="width:<?= $dp ?>%;background:<?= $dp >= 90 ? '#ef4444' : ($dp >= 70 ? '#f59e0b' : 'var(--accent)') ?>"></div>
                            </div>
                            <div class="um-quota-meta">
                                <?php if ($d['exhausted']): ?>
                                    <em style="color:#ef4444"><?= te('Démo épuisée') ?></em><?= $d['muted'] ? ' · ' . te('alerte masquée') : '' ?>
                                <?php else: ?>
                                    <em><?= te('Crédits de démo · {n} restants', ['n' => Format::credits($d['remaining'])]) ?></em>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="um-quota">
                            <div class="um-quota-line">
                                <?= Format::credits((int)$r['monthCredits']) ?>
                                / <?= $r['effectiveQuota'] !== null ? Format::credits((int)$r['effectiveQuota'] * 1000) : '∞' ?>
                                <?php if ($r['effectiveQuota'] !== null): ?>
                                    <span style="color:<?= $r['pct'] >= 90 ? '#ef4444' : 'var(--text-muted)' ?>">(<?= te('{n} %', ['n' => (int)$r['pct']]) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($r['effectiveQuota'] !== null): ?>
                                <div class="um-quota-bar">
                                    <div style="width:<?= $r['pct'] ?>%;background:<?= $r['pct'] >= 90 ? '#ef4444' : ($r['pct'] >= 70 ? '#f59e0b' : 'var(--accent)') ?>"></div>
                                </div>
                            <?php endif; ?>
                            <div class="um-quota-meta">
                                <em><?= htmlspecialchars($r['quotaSource']) ?></em>
                                <?php if ($creditValueEur > 0 && $r['monthCredits'] > 0): ?>
                                    <?php $valeurEur = $r['monthCredits'] * $creditValueEur; ?>
                                    · ≈ <?= I18n::locale() === 'en' ? '€' . number_format($valeurEur, 2, '.', ',') : number_format($valeurEur, 2, ',', ' ') . ' €' ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= te('Activité') ?>">
                        <div class="um-activity">
                            <span title="<?= te('Dernier message ou outil utilisé') ?>"><?= $r['lastActivity'] ? htmlspecialchars($relativeDay($r['lastActivity'])) : '—' ?></span>
                            <span class="um-muted" title="<?= te("Date d'inscription") ?>"><?= te('inscrit le {date}', ['date' => Format::dateFr(date('Y-m-d', strtotime((string)$u['created_at']) ?: time()))]) ?></span>
                        </div>
                    </td>
                    <td data-label="<?= te('Actions') ?>">
                        <button type="button" class="btn-secondary um-edit-btn"
                                data-user="<?= htmlspecialchars(json_encode($r['payload'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                                <?= $r['payload']['can_edit'] ? '' : ($r['payload']['locked_reason'] === 'wider' ? 'title="' . te('Ce compte a des droits que votre rôle n’a pas : vous pouvez le consulter, pas le modifier') . '"' : 'title="' . te('Votre rôle vous permet de voir ce compte, pas de le modifier') . '"') ?>>
                            <?= $r['payload']['can_edit'] ? te('Modifier') : te('Voir') ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="um-empty" id="umEmpty" style="display:none"><?= te('Aucun utilisateur ne correspond aux filtres.') ?></p>
    </div>
</div>

<!-- Modale création -->
<div class="modal" id="newUserModal" style="display:none">
    <div class="modal-box modal-box-profile-edit" style="max-width:560px">
        <header class="profile-modal-header">
            <h3><?= te('Nouvel utilisateur') ?></h3>
            <button type="button" class="profile-modal-close" data-close="newUserModal" aria-label="<?= te('Fermer') ?>" title="<?= te('Fermer') ?>">×</button>
        </header>
        <div class="profile-modal-body">
            <form method="POST" action="/admin/users/new" id="newUserForm" style="display:flex;flex-direction:column;gap:18px">
                <?= Csrf::field() ?>
                <section class="profile-section">
                    <h4><?= te('Compte') ?></h4>
                    <p class="profile-section-hint"><?= htmlspecialchars($newUserHint) ?></p>
                    <div class="form-group" style="margin:0">
                        <label><?= te('Nom') ?> <span style="color:#ef4444">*</span></label>
                        <input type="text" name="name" class="form-input" placeholder="<?= te('Prénom Nom') ?>" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label><?= te('Email') ?> <span style="color:#ef4444">*</span></label>
                        <input type="email" name="email" class="form-input" placeholder="<?= te('user@exemple.fr') ?>" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label><?= te('Mot de passe') ?> <span style="color:#ef4444">*</span> <span style="color:var(--text-muted);font-weight:400"><?= te('(8 caractères min.)') ?></span></label>
                        <div class="password-field">
                            <input type="password" name="password" class="form-input" minlength="8" required autocomplete="new-password">
                            <button type="button" class="password-toggle" aria-label="<?= te('Afficher le mot de passe') ?>" title="<?= te('Afficher / masquer') ?>">
                                <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 4.06-5.06"/><path d="M9.9 5.08A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 2.94"/><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/><path d="M3 3l18 18"/></svg>
                            </button>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <div class="form-group" style="margin:0;flex:1;min-width:140px">
                            <label><?= te('Rôle') ?></label>
                            <?php /* Rôles que ce compte peut DONNER (Role::assignableBy) — Utilisateur par défaut. */ ?>
                            <select name="role" class="form-select">
                                <?php foreach ($assignableRoles as $roleSlug => $roleDef): ?>
                                    <option value="<?= htmlspecialchars($roleSlug) ?>"<?= $roleSlug === 'user' ? ' selected' : '' ?>><?= htmlspecialchars($roleOptionLabel($roleDef)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($canOpenRoles) && count($assignableRoles) < count($allRoles)): ?>
                            <p class="profile-hint" style="margin-top:4px"><?= te('Seuls les rôles qui ne dépassent pas le vôtre sont proposés.') ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group" style="margin:0;flex:1;min-width:120px">
                            <label><?= te('Statut') ?></label>
                            <select name="is_active" class="form-select">
                                <option value="1"><?= te('Actif') ?></option>
                                <option value="0"><?= te('Inactif') ?></option>
                            </select>
                        </div>
                    </div>
                </section>
                <section class="profile-section">
                    <h4><?= te('Démo') ?></h4>
                    <label class="um-check">
                        <input type="checkbox" name="is_demo" value="1" id="nuDemo">
                        <?= te('Compte de démonstration') ?>
                    </label>
                    <div id="nuDemoFields" hidden>
                        <div class="form-group" style="margin:0;max-width:220px">
                            <label for="nuDemoCredits"><?= te('Crédits de démo') ?></label>
                            <input type="number" name="demo_credits" id="nuDemoCredits" class="form-input"
                                   value="<?= (int)($demoDefaultCredits ?? DemoAccount::DEFAULT_CREDITS) ?>" min="1" max="<?= DemoAccount::MAX_CREDITS ?>" step="1" disabled>
                        </div>
                        <p class="profile-hint" style="margin-top:6px"><?= te('Utilisables une seule fois : ils ne se renouvellent pas chaque mois et remplacent le quota mensuel. Une fois épuisés, MIA ne répond plus ; la personne garde ses conversations et un message l’invite à prendre contact.') ?>
                            <?php if (!empty($demoContactEmail)): ?><?= te('Adresse indiquée : {email}.', ['email' => $demoContactEmail]) ?><?php else: ?><?= te('Aucune adresse de contact n’est renseignée (Marque & apparence → Liens & contact).') ?><?php endif; ?>
                        </p>
                    </div>
                </section>
                <section class="profile-section">
                    <h4><?= te('Groupes') ?></h4>
                    <p class="profile-section-hint"><?= te('Les groupes donnent accès aux assistants, outils et modèles, et portent un quota.') ?></p>
                    <input type="hidden" name="group_ids[]" value="">
                    <div class="ug-check-list">
                        <?php foreach ($allGroups as $g): ?>
                            <label class="ug-check-item">
                                <input type="checkbox" name="group_ids[]" value="<?= (int)$g['id'] ?>"
                                    <?= !empty($g['is_default']) ? 'checked disabled title="' . te('Groupe par défaut — tous les utilisateurs en font partie') . '"' : '' ?>>
                                <span class="ug-member-id"><span class="ug-member-name"><?= !empty($g['is_default']) ? '★ ' : '' ?><?= htmlspecialchars($groupLabel($g)) ?></span></span>
                                <?php if (empty($g['is_active'])): ?><span class="badge badge-red"><?= te('inactif') ?></span><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
            </form>
        </div>
        <footer class="profile-modal-footer">
            <span></span>
            <div class="profile-modal-footer-actions">
                <button type="button" class="btn-secondary" data-close="newUserModal"><?= te('Annuler') ?></button>
                <button type="submit" form="newUserForm" class="btn-primary"><?= te('Créer') ?></button>
            </div>
        </footer>
    </div>
</div>

<!-- Modale édition -->
<div class="modal" id="editUserModal" style="display:none">
    <div class="modal-box modal-box-profile-edit" style="max-width:560px">
        <header class="profile-modal-header">
            <h3 id="euTitle"><?= te('Modifier') ?></h3>
            <button type="button" class="profile-modal-close" data-close="editUserModal" aria-label="<?= te('Fermer') ?>" title="<?= te('Fermer') ?>">×</button>
        </header>
        <div class="profile-modal-body">
            <!-- Identité + consommation (lecture seule) -->
            <div class="um-edit-identity">
                <div class="um-avatar um-avatar-fallback" id="euAvatarFallback" style="display:none"></div>
                <img class="um-avatar" id="euAvatarImg" src="" alt="" style="display:none">
                <div class="um-user-id">
                    <span class="um-user-name" id="euName"></span>
                    <span class="um-user-email" id="euEmail"></span>
                    <span class="um-muted" id="euMeta"></span>
                </div>
            </div>
            <div class="um-edit-usage" id="euUsage"></div>

            <!-- Armé seulement une fois la modale remplie (cf. `.um-edit-btn`) :
                 le remplissage par script ne doit pas passer pour une saisie.
                 `reload="close"` parce que la ligne du tableau derrière porte
                 le rôle, l'état et le quota. -->
            <form method="POST" id="editUserForm" data-autosave="off" data-autosave-reload="close"
                  style="display:flex;flex-direction:column;gap:18px">
                <?= Csrf::field() ?>
                <section class="profile-section">
                    <h4><?= te('Compte') ?></h4>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <div class="form-group" style="margin:0;flex:1;min-width:150px">
                            <label><?= te('Rôle') ?></label>
                            <?php /* Rôles que ce compte peut DONNER (Role::assignableBy) ; le rôle
                                     actuel d'un compte, s'il n'y figure pas, est ajouté par le
                                     script (jamais remplacé en silence par un autre). */ ?>
                            <select name="role" id="euRole" class="form-select">
                                <?php foreach ($assignableRoles as $roleSlug => $roleDef): ?>
                                    <option value="<?= htmlspecialchars($roleSlug) ?>"><?= htmlspecialchars($roleOptionLabel($roleDef)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="profile-hint" id="euRoleHint" style="margin-top:4px;display:none"></p>
                            <?php if (empty($canOpenRoles) && count($assignableRoles) < count($allRoles)): ?>
                            <p class="profile-hint" style="margin-top:4px"><?= te('Seuls les rôles qui ne dépassent pas le vôtre sont proposés.') ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group" style="margin:0;flex:1;min-width:150px">
                            <label><?= te('Quota mensuel') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(k crédits)') ?></span></label>
                            <input type="number" name="credit_quota_k" id="euQuota" class="form-input" min="0" step="1" placeholder="<?= te('hérité des groupes') ?>">
                            <p class="profile-hint" id="euQuotaHint" style="margin-top:4px"></p>
                            <?php if (!empty($budgetRepere)): ?>
                            <p class="profile-hint budget-repere-hint"><?= te('Repère budget : ≈ {k} k crédits par compte actif et par mois ({budget} ÷ {n} comptes).', ['k' => (int)$budgetRepere['per_account_k'], 'budget' => Format::eur((float)$budgetRepere['budget_eur']), 'n' => (int)$budgetRepere['accounts']]) ?> <a href="/admin/budget"><?= te('Budget') ?></a></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <label style="display:flex;flex-direction:row;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);margin-top:4px">
                        <input type="checkbox" name="is_active" id="euActive" value="1" style="width:auto"> <?= te('Compte actif (peut se connecter et consommer)') ?>
                        <?php /* Son propre compte : la case est grisée (on ne se désactive pas) ;
                                 ce champ, activé par le script, garde le compte actif à l'envoi —
                                 une case désactivée n'est jamais postée. */ ?>
                        <input type="hidden" name="is_active" value="1" id="euActiveSelf" disabled>
                    </label>
                </section>

                <section class="profile-section" id="euDemoSection" hidden>
                    <h4><?= te('Démo') ?></h4>
                    <div id="euDemoOn" hidden>
                        <p class="um-demo-usage" id="euDemoUsage"></p>
                        <?php /* Marqueur : la section démo est dans le POST (la case absente vaut décochée). */ ?>
                        <input type="hidden" name="demo_form" value="1" id="euDemoFormFlag" disabled>
                        <div class="form-group" style="margin:0;max-width:220px">
                            <label for="euDemoCredits"><?= te('Crédits de démo') ?></label>
                            <input type="number" name="demo_credits" id="euDemoCredits" class="form-input" min="1" max="<?= DemoAccount::MAX_CREDITS ?>" step="1" disabled>
                        </div>
                        <p class="profile-hint" style="margin-top:4px"><?= te('Augmenter l’enveloppe rouvre la démo : le décompte ne repart pas de zéro.') ?></p>
                        <label class="um-check" style="margin-top:8px">
                            <input type="checkbox" name="demo_alert_muted" value="1" id="euDemoMuted" disabled>
                            <?= te('Ne plus signaler l’épuisement de cette démo') ?>
                        </label>
                        <p class="profile-hint" style="margin-top:4px"><?= te('La pastille de « Personnes › Comptes » disparaît pour ce compte. Elle revient si son enveloppe change.') ?></p>
                        <div class="um-demo-actions">
                            <button type="button" class="btn-secondary" id="euDemoConvert"><?= te('Convertir en compte normal') ?></button>
                        </div>
                    </div>
                    <div id="euDemoOff" hidden>
                        <p class="profile-section-hint"><?= te('Un compte de démo dispose d’une enveloppe de crédits qui ne se renouvelle pas chaque mois. Le décompte part du moment où vous le passez en démo.') ?></p>
                        <div class="um-demo-actions">
                            <div class="form-group" style="margin:0;max-width:160px">
                                <label for="euDemoStartCredits"><?= te('Crédits de démo') ?></label>
                                <input type="number" id="euDemoStartCredits" class="form-input" value="<?= (int)($demoDefaultCredits ?? DemoAccount::DEFAULT_CREDITS) ?>" min="1" max="<?= DemoAccount::MAX_CREDITS ?>" step="1" data-autosave-skip>
                            </div>
                            <button type="button" class="btn-secondary" id="euDemoStart"><?= te('Passer en compte de démo') ?></button>
                        </div>
                    </div>
                </section>

                <section class="profile-section">
                    <h4><?= te('Groupes') ?></h4>
                    <p class="profile-section-hint"><?= te("Droits d'accès (assistants, outils, modèles) et quota hérités des groupes cochés.") ?></p>
                    <input type="hidden" name="group_ids[]" value="">
                    <div class="ug-check-list" id="euGroupList">
                        <?php foreach ($allGroups as $g): ?>
                            <label class="ug-check-item">
                                <input type="checkbox" name="group_ids[]" value="<?= (int)$g['id'] ?>"
                                       data-default="<?= !empty($g['is_default']) ? 1 : 0 ?>"
                                    <?= !empty($g['is_default']) ? 'checked disabled title="' . te('Groupe par défaut — tous les utilisateurs en font partie') . '"' : '' ?>>
                                <span class="ug-member-id"><span class="ug-member-name"><?= !empty($g['is_default']) ? '★ ' : '' ?><?= htmlspecialchars($groupLabel($g)) ?></span></span>
                                <?php if (empty($g['is_active'])): ?><span class="badge badge-red"><?= te('inactif') ?></span><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="profile-section">
                    <h4><?= te('Sécurité') ?></h4>
                    <div class="um-edit-danger">
                        <button type="button" class="btn-secondary" id="euResetPw" style="display:none">🔑 <?= te('Réinitialiser le mot de passe') ?></button>
                        <button type="button" id="euDelete" class="um-delete-btn" style="display:none"><?= te('Supprimer ce compte') ?></button>
                    </div>
                    <p class="profile-hint" id="euSelfHint" style="display:none"><?= te('Votre propre compte : la suppression est désactivée.') ?></p>
                </section>
            </form>
            <form method="POST" id="euDeleteForm" style="display:none"><?= Csrf::field() ?></form>
            <?php /* Gestes de démo (passer en démo, convertir) : actions explicites, hors auto-save. */ ?>
            <form method="POST" id="euDemoActionForm" style="display:none"><?= Csrf::field() ?><input type="hidden" name="demo_credits" id="euDemoActionCredits"></form>
        </div>
        <footer class="profile-modal-footer">
            <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
            <div class="profile-modal-footer-actions">
                <button type="button" class="btn-primary" data-close="editUserModal"><?= te('Fermer') ?></button>
            </div>
        </footer>
    </div>
</div>

<!-- Modale reset mot de passe -->
<div id="pwModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:210;align-items:center;justify-content:center">
    <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;width:340px;max-width:calc(90vw / var(--ui-zoom, 1))">
        <h3 style="margin-bottom:16px;font-size:15px"><?= te('Nouveau mot de passe') ?></h3>
        <p id="pwModalName" style="font-size:13px;color:var(--text-muted);margin-bottom:12px"></p>
        <form id="pwForm" method="POST" style="display:flex;flex-direction:column;gap:12px">
            <?= Csrf::field() ?>
            <div class="password-field">
                <input type="password" name="new_password" class="form-input" placeholder="<?= te('Nouveau mot de passe (8 car. min.)') ?>" minlength="8" required>
                <button type="button" class="password-toggle" aria-label="<?= te('Afficher le mot de passe') ?>" title="<?= te('Afficher / masquer') ?>">
                    <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 4.06-5.06"/><path d="M9.9 5.08A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 2.94"/><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/><path d="M3 3l18 18"/></svg>
                </button>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button type="button" id="pwModalCancel" class="btn-secondary"><?= te('Annuler') ?></button>
                <?php /* Volontairement HORS auto-save : un mot de passe ne se
                         définit pas à la frappe, à moitié saisi. */ ?>
                <button type="submit" class="btn-primary"><?= te('Définir le mot de passe') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // Libellés des rôles (rôles livrés et personnalisés) — pour le rôle actuel
    // d'un compte que la liste de choix ne propose pas.
    const ROLE_LABELS = <?= json_encode($roleLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // Langue de l'écran : nombres, pluriels (FR « > 1 », EN « != 1 ») et
    // substituants {nom} des textes traduits par tj().
    const EN = !!(window.I18N && window.I18N.lang === 'en');
    const NUM_LOCALE = EN ? 'en-US' : 'fr-FR';
    const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => ((vars && k in vars) ? String(vars[k]) : m));
    const plural = (n, one, many) => ((EN ? n !== 1 : n > 1) ? many : one);
    // ── Recherche, filtres, tuiles ──
    const search   = document.getElementById('umSearch');
    const fRole    = document.getElementById('umFilterRole');
    const fGroup   = document.getElementById('umFilterGroup');
    const fStatus  = document.getElementById('umFilterStatus');
    const fAuth    = document.getElementById('umFilterAuth');
    const tbody    = document.getElementById('umTbody');
    const countEl  = document.getElementById('umCount');
    const emptyEl  = document.getElementById('umEmpty');
    const statsEl  = document.getElementById('umStats');
    let statFilter = 'all'; // all | active | inactive | admins | overquota | demo | demoended

    function applyFilters() {
        const q  = (search.value || '').trim().toLowerCase();
        const r  = fRole.value;
        const g  = fGroup.value;
        const s  = fStatus.value;
        const a  = fAuth.value;
        let shown = 0;
        tbody.querySelectorAll('tr').forEach(tr => {
            let ok = true;
            if (q && !(tr.dataset.name || '').includes(q)) ok = false;
            if (r && tr.dataset.role !== r) ok = false;
            // Un compte appartient à plusieurs groupes : il apparaît sous
            // CHACUN. « none » = seulement le groupe par défaut (ou aucun).
            if (g === 'none') {
                if (tr.dataset.ownGroups !== '0') ok = false;
            } else if (g && !(tr.dataset.groups || '').split(' ').includes(g)) {
                ok = false;
            }
            if (s !== '' && tr.dataset.active !== s) ok = false;
            // Un compte peut se connecter de plusieurs façons (« google password ») :
            // il apparaît sous CHACUNE de ses méthodes.
            if (a && !(tr.dataset.auth || '').split(' ').includes(a)) ok = false;
            if (statFilter === 'active'    && tr.dataset.active !== '1') ok = false;
            if (statFilter === 'inactive'  && tr.dataset.active !== '0') ok = false;
            if (statFilter === 'admins'    && tr.dataset.role !== 'admin') ok = false;
            if (statFilter === 'overquota' && tr.dataset.overquota !== '1') ok = false;
            if (statFilter === 'demo'      && tr.dataset.demo !== '1') ok = false;
            if (statFilter === 'demoended' && tr.dataset.demoended !== '1') ok = false;
            tr.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        const total = tbody.querySelectorAll('tr').length;
        countEl.textContent = shown === total
            ? fill(plural(total, <?= tj('{n} compte') ?>, <?= tj('{n} comptes') ?>), { n: total })
            : shown + ' / ' + total;
        emptyEl.style.display = shown === 0 ? '' : 'none';
        statsEl.querySelectorAll('.um-stat').forEach(b => {
            b.classList.toggle('is-active', b.dataset.stat === statFilter && statFilter !== 'all');
        });
    }
    [search, fRole, fGroup, fStatus, fAuth].forEach(el => el.addEventListener('input', applyFilters));
    // Lien « N comptes » d'Personnes → Rôles : la liste s'ouvre filtrée sur ce rôle.
    const roleParam = new URLSearchParams(window.location.search).get('role');
    if (roleParam && Array.from(fRole.options).some(o => o.value === roleParam)) {
        fRole.value = roleParam;
    }
    // ?demo=1 : les comptes de démo ; ?demo=epuisees : celles qui n'ont plus
    // de crédits (lien de la pastille « démos épuisées »).
    const demoParam = new URLSearchParams(window.location.search).get('demo');
    if (demoParam && statsEl.querySelector('[data-stat="demo"]')) {
        statFilter = demoParam === 'epuisees' ? 'demoended' : 'demo';
    }
    statsEl.addEventListener('click', e => {
        const btn = e.target.closest('.um-stat');
        if (!btn) return;
        statFilter = (statFilter === btn.dataset.stat) ? 'all' : btn.dataset.stat;
        applyFilters();
    });

    // ── Tri par colonne ──
    let sortKey = null, sortDir = 1;
    function sortValue(tr, key) {
        if (key === 'name')     return tr.dataset.sortname || '';
        if (key === 'role')     return tr.dataset.role || '';
        if (key === 'pct')      return Number(tr.dataset.pct);
        if (key === 'activity') return tr.dataset.activity || '';
        return '';
    }
    document.querySelectorAll('.um-sortable').forEach(th => {
        th.addEventListener('click', () => {
            const key = th.dataset.sort;
            sortDir = (sortKey === key) ? -sortDir : 1;
            sortKey = key;
            const rows = [...tbody.querySelectorAll('tr')];
            rows.sort((a, b) => {
                const va = sortValue(a, key), vb = sortValue(b, key);
                if (va < vb) return -sortDir;
                if (va > vb) return sortDir;
                return 0;
            });
            rows.forEach(r => tbody.appendChild(r));
            document.querySelectorAll('.um-sortable').forEach(col => {
                col.querySelector('.um-sort-arrow').textContent = col === th ? (sortDir === 1 ? '▲' : '▼') : '';
            });
        });
    });

    // ── Copie des emails (lignes visibles uniquement : filtres et recherche respectés) ──
    const copyBtn      = document.getElementById('umCopyEmails');
    const copyLabel    = copyBtn.querySelector('span');
    const copyDefault  = copyLabel.textContent;
    let   copyResetTimer = null;

    // « Prénom NOM » : premier mot conservé tel quel, le reste en capitales.
    function formatRecipient(name, email) {
        const parts = (name || '').trim().split(/\s+/).filter(Boolean);
        const first = parts.shift() || '';
        const last  = parts.join(' ').toUpperCase();
        const display = (first + ' ' + last).trim();
        return display ? display + ' <' + email + '>' : email;
    }

    copyBtn.addEventListener('click', async () => {
        const list = [];
        tbody.querySelectorAll('tr').forEach(tr => {
            if (tr.style.display === 'none') return;
            const btn = tr.querySelector('.um-edit-btn');
            if (!btn) return;
            try {
                const u = JSON.parse(btn.dataset.user);
                if (u.email) list.push(formatRecipient(u.name, u.email));
            } catch (e) { /* payload illisible : on saute la ligne */ }
        });
        if (!list.length) return;

        const text = list.join(', ');
        let ok = true;
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            // Fallback hors contexte sécurisé (HTTP) ou permission refusée.
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity  = '0';
            document.body.appendChild(ta);
            ta.select();
            ok = document.execCommand('copy');
            ta.remove();
        }

        clearTimeout(copyResetTimer);
        copyLabel.textContent = ok
            ? '✓ ' + fill(plural(list.length, <?= tj('{n} email copié') ?>, <?= tj('{n} emails copiés') ?>), { n: list.length })
            : <?= tj('Échec de la copie') ?>;
        copyBtn.classList.toggle('is-copied', ok);
        copyResetTimer = setTimeout(() => {
            copyLabel.textContent = copyDefault;
            copyBtn.classList.remove('is-copied');
        }, 2500);
    });

    // ── Modales génériques (ouverture/fermeture) ──
    function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        const hide = () => { modal.style.display = 'none'; };
        // La ligne du tableau derrière est rendue côté serveur : après une
        // modification, elle afficherait encore l'ancien rôle et l'ancien
        // quota. `closeAndReload` ferme tout de suite et ne recharge que si
        // quelque chose a réellement été enregistré.
        const form = modal.querySelector('form[data-autosave]');
        if (form && window.MiaAutosave) {
            window.MiaAutosave.closeAndReload(form, hide);
        } else {
            hide();
        }
    }
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.dataset.close));
    });
    ['newUserModal', 'editUserModal'].forEach(id => {
        const m = document.getElementById(id);
        m.addEventListener('click', e => { if (e.target === m) closeModal(id); });
    });
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        ['pwModal', 'editUserModal', 'newUserModal'].forEach(id => {
            const m = document.getElementById(id);
            // Passer par closeModal et non par un `display = none` direct :
            // sinon Échap sauterait l'enregistrement en attente.
            if (m && m.style.display !== 'none') closeModal(id);
        });
    });

    // ── Création : compte de démonstration ──
    // Coché, la case ouvre l'enveloppe (champ envoyé seulement alors) et retire
    // le rôle Administrateur de la liste : une démo ne l'est jamais.
    const nuDemo       = document.getElementById('nuDemo');
    const nuDemoFields = document.getElementById('nuDemoFields');
    const nuDemoCredits = document.getElementById('nuDemoCredits');
    function syncNewUserDemo() {
        const on = !!nuDemo.checked;
        nuDemoFields.hidden = !on;
        nuDemoCredits.disabled = !on;
        const roleSel = document.querySelector('#newUserForm select[name="role"]');
        const adminOpt = roleSel ? roleSel.querySelector('option[value="admin"]') : null;
        if (adminOpt) {
            adminOpt.disabled = on;
            if (on && roleSel.value === 'admin') roleSel.value = 'user';
        }
    }
    nuDemo.addEventListener('change', syncNewUserDemo);

    document.getElementById('btnNewUser')?.addEventListener('click', () => {
        const form = document.getElementById('newUserForm');
        form.reset();
        syncNewUserDemo();
        openModal('newUserModal');
        form.querySelector('input[name="name"]').focus();
    });

    // ── Modale édition ──
    const fmt = n => Number(n || 0).toLocaleString(NUM_LOCALE);
    let currentEditUser = null;
    // D'où viendra le quota appliqué : le PLUS GÉNÉREUX entre le quota
    // individuel et celui des groupes (User::effectiveCreditQuotaKFrom).
    // Recalculé à la frappe : la règle se lit avant d'enregistrer.
    function paintQuotaHint(u, raw) {
        const hint = document.getElementById('euQuotaHint');
        if (!hint || !u) return;
        if (u.demo) {
            hint.textContent = <?= tj('Compte de démo : ce quota mensuel ne s’appliquera qu’après conversion en compte normal.') ?>;
            return;
        }
        const val = String(raw ?? '').trim();
        const own = val === '' ? null : Math.max(0, parseInt(val, 10) || 0);
        const grp = u.quota_group;
        if (own === null) {
            hint.textContent = grp !== null
                ? fill(<?= tj('Vide : hérite du plus généreux des groupes ({n} crédits/mois).') ?>, { n: fmt(grp * 1000) })
                : <?= tj('Vide et aucun quota de groupe : illimité.') ?>;
        } else if (grp === null) {
            hint.textContent = <?= tj('Quota individuel : aucun groupe n’en définit.') ?>;
        } else if (own > grp) {
            hint.textContent = fill(<?= tj('Plus généreux que ses groupes ({n} crédits/mois) : c’est lui qui s’applique.') ?>, { n: fmt(grp * 1000) });
        } else {
            hint.textContent = fill(<?= tj('Ses groupes sont plus généreux : {n} crédits/mois s’appliquent. Le plus généreux l’emporte toujours.') ?>, { n: fmt(grp * 1000) });
        }
    }
    document.getElementById('euQuota')?.addEventListener('input', e => paintQuotaHint(currentEditUser, e.target.value));

    document.querySelectorAll('.um-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const u = JSON.parse(btn.dataset.user);
            currentEditUser = u;
            document.getElementById('euTitle').textContent = fill(<?= tj('Modifier — {nom}') ?>, { nom: u.name });
            document.getElementById('editUserForm').action = '/admin/users/' + u.id;

            // Identité
            const img = document.getElementById('euAvatarImg');
            const fb  = document.getElementById('euAvatarFallback');
            if (u.avatar_url) {
                img.src = u.avatar_url; img.style.display = ''; fb.style.display = 'none';
            } else {
                fb.textContent = (u.name || '?').charAt(0).toUpperCase();
                fb.style.display = 'flex'; img.style.display = 'none';
            }
            document.getElementById('euName').textContent  = u.name;
            document.getElementById('euEmail').textContent = u.email;
            document.getElementById('euMeta').textContent  =
                (u.auth_label ? fill(<?= tj('Connexion {methode}') ?>, { methode: u.auth_label }) + ' · ' : '')
                + fill(<?= tj('inscrit le {date}') ?>, { date: u.created_at });

            // Consommation
            document.getElementById('euUsage').innerHTML =
                (u.demo ? '<span>' + fill(<?= tj('{n} crédits de démo utilisés sur {max}') ?>, { n: '<strong>' + fmt(u.demo.used) + '</strong>', max: fmt(u.demo.credits) }) + '</span>' : '')
                + '<span>' + fill(<?= tj('{n} crédits ce mois') ?>, { n: '<strong>' + fmt(u.month_credits) + '</strong>' }) + '</span>'
                + '<span>' + fill(<?= tj('{n} au total') ?>, { n: '<strong>' + fmt(u.total_credits) + '</strong>' }) + '</span>'
                + '<span>' + fill(<?= tj('Dernière activité : {date}') ?>, { date: '<strong>' + (u.last_activity || <?= tj('jamais') ?>) + '</strong>' }) + '</span>';

            // Champs
            // Rôle : la valeur du compte, toujours — ajoutée à la liste si ce
            // compte ne peut pas la donner (rôle plus large, rôle disparu) :
            // un enregistrement ne doit jamais changer de rôle en silence.
            const roleSel = document.getElementById('euRole');
            roleSel.querySelectorAll('option[data-current-only]').forEach(o => o.remove());
            if (![...roleSel.options].some(o => o.value === u.role)) {
                const opt = document.createElement('option');
                opt.value = u.role;
                opt.textContent = ROLE_LABELS[u.role] || fill(<?= tj('Rôle inconnu ({slug})') ?>, { slug: u.role });
                opt.dataset.currentOnly = '1';
                roleSel.appendChild(opt);
            }
            roleSel.value = u.role;
            // Son propre rôle ne se change pas (on se retirerait l'accès en
            // pleine page), ni son propre compte ne se désactive.
            roleSel.disabled = !!u.is_self || !u.can_edit;
            const roleHint = document.getElementById('euRoleHint');
            const lockedWhy = u.is_self
                ? <?= tj('Votre propre rôle se change par un autre administrateur.') ?>
                : (u.locked_reason === 'wider'
                    ? <?= tj('Ce compte a des droits que votre rôle n’a pas : vous pouvez le consulter, pas le modifier.') ?>
                    : (u.locked_reason === 'right' ? <?= tj('Votre rôle vous permet de consulter ce compte, pas de le modifier.') ?> : ''));
            roleHint.textContent = lockedWhy;
            roleHint.style.display = lockedWhy ? '' : 'none';
            document.getElementById('euActive').checked = !!u.is_active;
            document.getElementById('euActive').disabled = !!u.is_self || !u.can_edit;
            document.getElementById('euActiveSelf').disabled = !u.is_self;
            document.getElementById('euQuota').value   = u.quota_user !== null ? u.quota_user : '';
            paintQuotaHint(u, document.getElementById('euQuota').value);

            // Démo : enveloppe, alerte et conversion (compte de démo) ; passage
            // en démo (autre compte, jamais un administrateur). Les champs
            // d'une partie masquée sont désactivés : jamais postés.
            const demoOn = !!u.demo;
            const demoSection = document.getElementById('euDemoSection');
            demoSection.hidden = !demoOn && !(u.demo_allowed && u.can_edit);
            document.getElementById('euDemoOn').hidden = !demoOn;
            document.getElementById('euDemoOff').hidden = demoOn;
            document.getElementById('euDemoFormFlag').disabled = !demoOn || !u.can_edit;
            document.getElementById('euDemoCredits').disabled = !demoOn || !u.can_edit;
            document.getElementById('euDemoMuted').disabled = !demoOn || !u.can_edit;
            document.getElementById('euDemoConvert').hidden = !u.can_edit;
            if (demoOn) {
                document.getElementById('euDemoCredits').value = u.demo.credits;
                document.getElementById('euDemoMuted').checked = !!u.demo.muted;
                document.getElementById('euDemoUsage').textContent = u.demo.exhausted
                    ? fill(<?= tj('Démo épuisée : {n} crédits utilisés sur {max} depuis le {date}. MIA ne répond plus à ce compte.') ?>, { n: fmt(u.demo.used), max: fmt(u.demo.credits), date: u.demo.started })
                    : fill(<?= tj('{n} crédits utilisés sur {max} depuis le {date} — {reste} restants.') ?>, { n: fmt(u.demo.used), max: fmt(u.demo.credits), date: u.demo.started, reste: fmt(u.demo.remaining) });
                document.getElementById('euDemoUsage').classList.toggle('is-ended', !!u.demo.exhausted);
            }

            // Groupes
            document.querySelectorAll('#euGroupList input[type="checkbox"]').forEach(cb => {
                if (cb.dataset.default === '1') { cb.checked = true; return; }
                cb.checked = (u.group_ids || []).includes(Number(cb.value));
            });

            // Sécurité
            document.getElementById('euResetPw').style.display = (u.is_local_pw && u.can_edit) ? '' : 'none';
            document.getElementById('euDelete').style.display  = u.can_delete ? '' : 'none';
            document.getElementById('euSelfHint').style.display = u.is_self ? '' : 'none';

            // Compte que ce rôle ne peut pas modifier (droit « Comptes —
            // Modifier » absent, ou compte plus large que le sien) : la modale
            // montre sans rien laisser changer.
            const editForm = document.getElementById('editUserForm');
            editForm.querySelectorAll('#euQuota, #euGroupList input[type="checkbox"]').forEach(el => {
                if (el.dataset.default === '1') return;
                el.disabled = !u.can_edit;
            });

            openModal('editUserModal');
            const body = document.querySelector('#editUserModal .profile-modal-body');
            if (body) body.scrollTop = 0;
            // Tous les champs sont posés : l'enregistrement automatique peut
            // prendre la main (s'il y a quelque chose à enregistrer).
            editForm.dataset.autosave = u.can_edit ? 'on' : 'off';
        });
    });

    // Une enveloppe qui change rallume l'alerte côté serveur : la case suit,
    // sinon le prochain envoi de la fiche la remasquerait.
    document.getElementById('euDemoCredits').addEventListener('input', () => {
        document.getElementById('euDemoMuted').checked = false;
    });

    // Gestes de démo : explicites (confirmation), hors auto-save. Ce qui reste
    // à enregistrer dans la fiche part d'abord, puis la page se recharge.
    async function submitDemoAction(path, credits) {
        const editForm = document.getElementById('editUserForm');
        if (window.MiaAutosave) {
            try { await window.MiaAutosave.settle(editForm); } catch (e) { /* l'envoi en échec se voit dans la fiche */ }
            window.MiaAutosave.detach(editForm);
        }
        const f = document.getElementById('euDemoActionForm');
        document.getElementById('euDemoActionCredits').value = credits !== undefined ? String(credits) : '';
        f.action = '/admin/users/' + currentEditUser.id + path;
        f.submit();
    }
    document.getElementById('euDemoConvert').addEventListener('click', () => {
        if (!currentEditUser) return;
        if (!confirm(fill(<?= tj('Convertir « {nom} » en compte normal ? Son historique est conservé ; son quota mensuel (individuel ou des groupes) s’applique dès maintenant.') ?>, { nom: currentEditUser.name }))) return;
        submitDemoAction('/demo/convert');
    });
    document.getElementById('euDemoStart').addEventListener('click', () => {
        if (!currentEditUser) return;
        const input = document.getElementById('euDemoStartCredits');
        const n = parseInt(input.value, 10);
        if (!(n >= 1 && n <= <?= DemoAccount::MAX_CREDITS ?>)) {
            input.reportValidity();
            input.focus();
            return;
        }
        if (!confirm(fill(<?= tj('Passer « {nom} » en compte de démo avec {n} crédits ? Le décompte part de maintenant, sans renouvellement mensuel.') ?>, { nom: currentEditUser.name, n: fmt(n) }))) return;
        submitDemoAction('/demo', n);
    });

    // La checkbox « actif » décochée n'envoie rien : is_active lu côté serveur
    // via (int)($_POST['is_active'] ?? 0) — un submit sans la case = inactif. OK.

    document.getElementById('euResetPw').addEventListener('click', () => {
        if (!currentEditUser) return;
        document.getElementById('pwModalName').textContent = fill(<?= tj('Utilisateur : {nom}') ?>, { nom: currentEditUser.name });
        document.getElementById('pwForm').action = '/admin/users/' + currentEditUser.id + '/password';
        const modal = document.getElementById('pwModal');
        modal.style.display = 'flex';
        modal.querySelector('input[name="new_password"]').value = '';
        modal.querySelector('input[name="new_password"]').focus();
    });

    // Confirmation INFORMÉE : l'impact réel est chargé avant le geste. Un
    // créateur/admin laisse derrière lui des assistants et bases orphelins,
    // des partages qui tombent, et des syncs Drive portées par SA connexion
    // qui casseront — un « êtes-vous sûr ? » générique ne dit rien de tout ça.
    document.getElementById('euDelete').addEventListener('click', async () => {
        if (!currentEditUser) return;
        const btn = document.getElementById('euDelete');
        btn.disabled = true;

        const titre = fill(<?= tj('Supprimer « {nom} » ?') ?>, { nom: currentEditUser.name });
        let msg = titre;
        try {
            const r  = await fetch('/admin/users/' + currentEditUser.id + '/delete-impact', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const it = await r.json();
            if (it && it.ok) {
                const lignes = [];
                if (it.conversations > 0) {
                    lignes.push('• ' + fill(plural(it.conversations,
                        <?= tj('{n} conversation supprimée définitivement') ?>,
                        <?= tj('{n} conversations supprimées définitivement') ?>), { n: it.conversations }));
                }
                const liste = (arr, total) => arr.join(', ')
                    + (total > arr.length ? '… (' + fill(<?= tj('{n} au total') ?>, { n: total }) + ')' : '');
                if (it.profile_count > 0) {
                    lignes.push('• ' + fill(plural(it.profile_count,
                        <?= tj('{n} assistant sans créateur (gérable uniquement par les admins) : {liste}') ?>,
                        <?= tj('{n} assistants sans créateur (gérables uniquement par les admins) : {liste}') ?>),
                        { n: it.profile_count, liste: liste(it.profiles, it.profile_count) }));
                }
                if (it.kb_count > 0) {
                    lignes.push('• ' + fill(plural(it.kb_count,
                        <?= tj('{n} base de connaissances sans propriétaire : {liste}') ?>,
                        <?= tj('{n} bases de connaissances sans propriétaire : {liste}') ?>),
                        { n: it.kb_count, liste: liste(it.kbs, it.kb_count) })
                        + (it.kb_shares_given > 0 ? ' (' + fill(plural(it.kb_shares_given,
                            <?= tj('et {n} partage accordé') ?>,
                            <?= tj('et {n} partages accordés') ?>), { n: it.kb_shares_given }) + ')' : ''));
                }
                if (it.private_kb_count > 0) {
                    lignes.push('• ' + fill(plural(it.private_kb_count,
                        <?= tj('{n} base « Uniquement pour moi » supprimée avec le compte') ?>,
                        <?= tj('{n} bases « Uniquement pour moi » supprimées avec le compte') ?>), { n: it.private_kb_count }));
                }
                if (it.sync_owner_kbs.length > 0) {
                    lignes.push('⚠ ' + fill(plural(it.sync_owner_kbs.length,
                        <?= tj('{n} base synchronisée via SA connexion personnelle (Google Drive / OneDrive) — la synchronisation CASSERA : {liste}') ?>,
                        <?= tj('{n} bases synchronisées via SA connexion personnelle (Google Drive / OneDrive) — la synchronisation CASSERA : {liste}') ?>),
                        { n: it.sync_owner_kbs.length, liste: it.sync_owner_kbs.join(', ') }));
                }
                if (it.connections.length > 0) {
                    lignes.push('• ' + fill(<?= tj('Connexions personnelles révoquées : {liste}') ?>, { liste: it.connections.join(', ') }));
                }
                if (it.role === 'admin')   lignes.push('• ' + <?= tj('Ce compte est ADMINISTRATEUR.') ?>);
                else if (it.role !== 'user' && it.role_label) lignes.push('• ' + fill(<?= tj('Rôle : {role}.') ?>, { role: String(it.role_label).toUpperCase() }));

                msg = titre
                    + (lignes.length ? '\n\n' + lignes.join('\n') : '\n\n' + <?= tj('Aucune donnée partagée : impact limité à ses conversations.') ?>)
                    + '\n\n' + <?= tj('Action irréversible.') ?>;
            } else {
                msg += '\n\n' + <?= tj('Ses conversations et ses données seront perdues. Action irréversible.') ?>;
            }
        } catch (e) {
            // Impact injoignable (réseau) : on retombe sur l'avertissement générique.
            msg += '\n\n' + <?= tj('Ses conversations et ses données seront perdues. Action irréversible.') ?>;
        } finally {
            btn.disabled = false;
        }

        if (!confirm(msg)) return;
        const f = document.getElementById('euDeleteForm');
        f.action = '/admin/users/' + currentEditUser.id + '/delete';
        f.submit();
    });

    document.getElementById('pwModalCancel').addEventListener('click', () => {
        document.getElementById('pwModal').style.display = 'none';
    });
    document.getElementById('pwModal').addEventListener('click', e => {
        if (e.target === e.currentTarget) e.currentTarget.style.display = 'none';
    });

    // Toggle œil sur tous les champs password (.password-field)
    document.addEventListener('click', e => {
        const btn = e.target.closest('.password-toggle');
        if (!btn) return;
        const wrap  = btn.closest('.password-field');
        const input = wrap?.querySelector('input');
        if (!input) return;
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.querySelector('.eye-open').style.display   = isHidden ? 'none' : '';
        btn.querySelector('.eye-closed').style.display = isHidden ? '' : 'none';
        btn.setAttribute('aria-label', isHidden ? <?= tj('Masquer le mot de passe') ?> : <?= tj('Afficher le mot de passe') ?>);
    });

    applyFilters();
})();
</script>
</body>
</html>
