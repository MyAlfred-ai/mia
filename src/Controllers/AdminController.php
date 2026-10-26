<?php

declare(strict_types=1);

class AdminController
{
    // Avatars d'assistants : voir le modèle AssistantAvatar (constantes,
    // stockage, bibliothèque nommée).

    // Documents de référence attachés aux assistants (RAG local).
    // Taille et MIME : source unique dans KnowledgeBaseDocument (partagée avec
    // la synchronisation Google Drive, qui tourne aussi en worker CLI).
    private const PROFILE_DOC_MAX_SIZE  = KnowledgeBaseDocument::MAX_FILE_SIZE;
    private const PROFILE_DOC_MAX_COUNT = 20;               // nb max par profil

    // Refus opposé à toute écriture sur la base de connaissances système.
    private const SYSTEM_KB_MESSAGE = 'Base système : son contenu est synchronisé depuis les guides '
        . 'de MIA à chaque déploiement et ne se modifie pas ici. Seule la réindexation est possible.';

    // Refus opposé aux gestes réservés aux documents sur une base en direct (MyBrain).
    private const REMOTE_KB_NO_DOCUMENTS = 'Base en direct : son contenu vit dans MyBrain, MIA l\'interroge '
        . 'à chaque message. Il n\'y a ni document à déposer ni rien à réindexer ici — '
        . 'c\'est dans MyBrain que le cerveau se nourrit.';

    // Refus opposé à la suppression manuelle d'un document piloté par une sync distante.
    private const SYNCED_DOC_MESSAGE = 'Document synchronisé depuis un dossier en ligne : supprimez-le '
        . 'du dossier source (sinon il serait re-téléchargé au prochain cycle), ou désactivez la '
        . 'synchronisation de la base.';

    private const PROFILE_DOC_ALLOWED_MIMES = KnowledgeBaseDocument::ALLOWED_MIMES;

    public function index(): void
    {
        AuthMiddleware::requireAuth();
        // Sans le droit « Tableau de bord — Voir », /admin mène à la première
        // page que le rôle ouvre (un créateur : ses assistants) — c'est
        // l'adresse du bouton « Admin » sans JavaScript. Aucune : 403.
        if (!Session::can('dashboard.view')) {
            $home = AdminAccess::home(Session::currentUser());
            if ($home === null || $home === '/admin') {
                AuthMiddleware::requirePermission('dashboard.view');
            }
            header('Location: ' . $home);
            exit;
        }

        $today          = date('Y-m-d');
        $monthStart     = date('Y-m-01');
        $prevMonthStart = date('Y-m-01', strtotime($monthStart . ' -1 month'));
        $prevMonthEnd   = date('Y-m-t', strtotime($prevMonthStart));

        // KPIs mois en cours vs mois précédent complet. `cost_eur` (coût réel
        // fournisseur) vient du grand livre ; `billed_eur` = crédits × valeur
        // actuelle du crédit (valorisation), dérivée.
        $monthKpis = UsageLog::periodTotals($monthStart, $today);
        $monthKpis['billed_eur'] = CreditService::creditsToEur($monthKpis['total_credits']);
        $prevKpis = UsageLog::periodTotals($prevMonthStart, $prevMonthEnd);
        $prevKpis['billed_eur'] = CreditService::creditsToEur($prevKpis['total_credits']);

        // Projection fin de mois : COÛT RÉEL au rythme du mois (celui que le
        // budget IA plafonne), comparée au coût réel du mois précédent complet.
        // Source unique : AiBudget (même mesure, même fenêtre que le budget).
        $budget     = AiBudget::status(true);
        $projection = [
            'cost_eur'      => $budget['projection']['projected_eur'] ?? null,
            'reach_date'    => $budget['projection']['reach_date'] ?? null,
            'evolution_pct' => isset($budget['projection']['projected_eur'])
                ? AiBudget::evolutionPct((float)$budget['projection']['projected_eur'], (float)$prevKpis['cost_eur'])
                : null,
        ];

        // Tendance : série mensuelle sur les 12 derniers mois (mois vides comblés).
        $from12  = date('Y-m-01', strtotime($monthStart . ' -11 months'));
        $monthly = $this->fillMonthlySeries(UsageLog::monthlySummary($from12, $today), $from12, $today);

        // Top consommateurs du mois (coût € + part du total).
        $monthByUser    = UsageLog::periodTotalsByUser($monthStart, $today);
        $creditsDivisor = max(1, $monthKpis['total_credits']);
        foreach ($monthByUser as &$row) {
            $row['billed_eur'] = CreditService::creditsToEur($row['total_credits']);
            $row['share_pct']  = round($row['total_credits'] / $creditsDivisor * 100, 1);
        }
        unset($row);
        $topUsers = array_slice($monthByUser, 0, 5);

        // Parc utilisateurs.
        $allUsers  = User::all();
        $userStats = ['total' => count($allUsers), 'active' => 0, 'inactive' => 0, 'new_this_month' => 0];
        $usersById = [];
        foreach ($allUsers as $u) {
            $usersById[(int)$u['id']] = $u;
            $userStats[(int)$u['is_active'] === 1 ? 'active' : 'inactive']++;
            if (substr((string)$u['created_at'], 0, 7) === substr($monthStart, 0, 7)) {
                $userStats['new_this_month']++;
            }
        }
        // Les comptes inactifs EN ATTENTE (jamais utilisés) sont un point à
        // régler (AdminAttention) : le tableau de bord les liste avec les autres.

        // Alertes quota : conso du mois vs quota effectif (user → groupe), seuil 80 %.
        // Même cascade que User::effectiveCreditQuotaK(), quotas groupe
        // pré-chargés en une requête (évite le fallback SQL par utilisateur).
        // On compare `quota_credits` — ce que CreditQuota applique — et non
        // `total_credits` : la maintenance (alimentation des bases, tests)
        // n'entame aucun quota, l'alerte faisait paraître bloqué qui ne l'était pas.
        $groupQuotas = UserGroup::effectiveQuotasByUserIds(array_keys($usersById));
        $quotaAlerts = [];
        foreach ($monthByUser as $row) {
            $uid = $row['user_id'];
            if ($uid === null || !isset($usersById[$uid])) {
                continue;
            }
            $user   = $usersById[$uid];
            $quotaK = User::effectiveCreditQuotaKFrom($user, $groupQuotas[$uid] ?? null);
            $quotaCredits = $quotaK !== null ? (int)$quotaK * 1000 : 0;
            if ($quotaCredits <= 0) {
                continue;
            }
            $pct = (int)round($row['quota_credits'] / $quotaCredits * 100);
            if ($pct >= 80) {
                $quotaAlerts[] = [
                    'user_name'     => $row['user_name'],
                    'used_credits'  => $row['quota_credits'],
                    'quota_credits' => $quotaCredits,
                    'pct'           => $pct,
                ];
            }
        }
        usort($quotaAlerts, static fn(array $a, array $b): int => $b['pct'] <=> $a['pct']);

        // État plateforme.
        $activeProfilesCount = count(Profile::allActive());
        $totalProfilesCount  = count(Profile::all());
        $activeProviders     = array_values(array_filter(
            ApiKey::all(),
            static fn(array $k): bool => (int)$k['is_active'] === 1
        ));
        $creditValueEur = CreditService::creditValueEur();

        // Empreinte disque (MySQL, Qdrant, médias, documents, application) —
        // calcul lourd mis en cache 10 min par StorageInfo.
        $storage = StorageInfo::snapshot();

        require ROOT . '/templates/admin/index.php';
    }

    public function users(): void
    {
        AuthMiddleware::requirePermission('users.view');
        $users        = User::all();
        $userIds      = array_map('intval', array_column($users, 'id'));
        $groupsByUser = UserGroup::groupsByUserIds($userIds);
        $effectiveGroupQuotas = UserGroup::effectiveQuotasByUserIds($userIds);
        $allGroups    = UserGroup::all(); // cases à cocher des modales (création/édition)
        $success      = Session::getFlash('success');
        $error        = Session::getFlash('error');
        // Agrégats en un passage (pas de requête par ligne) : conso totale,
        // conso du mois (barres de quota) et dernière activité (comptes dormants).
        $creditsByUser      = UsageLog::creditsByUser();
        $monthCreditsByUser = UsageLog::creditsThisMonthByUser();
        $lastActivityByUser = UsageLog::lastActivityByUser();
        $globalCredit  = CreditService::globalConfig();
        $creditValueEur = (float)($globalCredit['credit_value_eur'] ?? 0.001);
        // Règles d'accès : ce qu'un compte obtient à sa création, et quels
        // comptes comptent pour « l'équipe ». Réglages de population, donc à
        // côté de la population — pas dans les réglages d'apparence.
        $newUsersActive   = Settings::get('new_users_active', '0') === '1';
        $staffEmailDomain = (string) Settings::get('staff_email_domain', '');
        // Aide de la fenêtre « Nouvel utilisateur » : nomme les fournisseurs
        // RÉELLEMENT proposés à la connexion (cf. SsoRegistry::newAccountHint).
        $newUserHint = SsoRegistry::newAccountHint(
            array_map([SsoRegistry::class, 'label'], SsoRegistry::usableProviders()),
            SsoRegistry::passwordEnabled()
        );
        // Repère budget à côté du champ de quota (null sans budget IA).
        $budgetRepere     = AiBudget::repereForUsers($users);
        // Rôles : tous pour les libellés et le filtre ; ceux que ce compte peut
        // DONNER pour les listes de choix (jamais plus large que les siens,
        // jamais Administrateur s'il ne l'est pas — Role::assignableBy).
        $actor          = Session::currentUser();
        $allRoles       = Role::all();
        $assignableRoles = Role::assignableBy($actor);
        // Sans aucun rôle à donner, la fenêtre de création n'aurait rien à proposer.
        $canCreateUser  = Session::can('users.create') && $assignableRoles !== [];
        $canEditUser    = Session::can('users.edit');
        $canDeleteUser  = Session::can('users.delete');
        $canOpenRoles   = Session::isAdmin();
        require ROOT . '/templates/admin/users.php';
    }

    /**
     * Le rôle demandé pour un compte, contrôlé : il existe, et ce compte
     * (l'acteur) a le droit de le donner. Null = refus (message dans $why).
     */
    private function checkedRoleChange(?array $actor, string $role, ?string &$why): ?string
    {
        $role = trim($role);
        if (!Role::exists($role)) {
            $why = t('Ce rôle n\'existe pas (ou plus) : choisissez-en un dans la liste.');
            return null;
        }
        if (!isset(Role::assignableBy($actor)[$role])) {
            $why = t('Vous ne pouvez pas donner ce rôle : il ouvre plus de droits que le vôtre.');
            return null;
        }
        return $role;
    }

    /**
     * Règles d'accès de la page Utilisateurs. Route dédiée (déclarée AVANT
     * `/admin/users/{id}`, sinon « access-rules » y serait avalé) : le module
     * d'auto-enregistrement reposte le formulaire ENTIER sur sa propre action.
     */
    public function saveUserAccessRules(): void
    {
        AuthMiddleware::requirePermission('users.edit');

        // Case absente du POST = décochée : la convention maison. C'est aussi
        // pourquoi ce champ ne peut PAS rester écrit par `saveSettings` — le
        // formulaire des réglages généraux ne le porte plus, et l'y écrire
        // quand même désactiverait l'activation automatique à chaque
        // enregistrement de ce formulaire-là.
        Settings::set('new_users_active', !empty($_POST['new_users_active']) ? '1' : '0');

        if (isset($_POST['staff_email_domain'])) {
            Settings::set('staff_email_domain', trim((string) $_POST['staff_email_domain']));
        }

        AjaxResponse::finish(
            AjaxResponse::wanted(),
            'success',
            t('Règles d\'accès enregistrées.'),
            '/admin/users'
        );
    }

    public function userGroups(): void
    {
        AuthMiddleware::requirePermission('groups.view');

        // Auto-réparation : le groupe par défaut contient toujours tous les
        // utilisateurs (INSERT IGNORE, no-op si déjà synchronisé).
        UserGroup::syncDefaultGroupMembers();

        $groups   = UserGroup::allWithAssignments();
        $profiles = Profile::all();
        $tools    = ChatTool::all();
        $llms     = LLMFactory::available();
        $users    = User::all();
        usort(
            $users,
            static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name'])
        );

        $userIds = array_map('intval', array_column($users, 'id'));
        $groupsByUser = UserGroup::groupsByUserIds($userIds);
        $usersByGroup = [];
        foreach ($users as $user) {
            $uid = (int)$user['id'];
            foreach ($groupsByUser[$uid] ?? [] as $group) {
                $gid = (int)$group['id'];
                $usersByGroup[$gid] ??= [];
                $usersByGroup[$gid][] = $user;
            }
        }

        // Repère budget à côté du champ de quota (null sans budget IA).
        $budgetRepere = AiBudget::repereForUsers($users);
        $success  = Session::getFlash('success');
        $error    = Session::getFlash('error');
        require ROOT . '/templates/admin/user-groups.php';
    }

    public function updateUser(string $id): void
    {
        AuthMiddleware::requirePermission('users.edit');
        $asJson = AjaxResponse::wanted();
        $userId = (int)$id;
        $actor  = Session::currentUser();
        $target = User::findById($userId);
        if ($target === null) {
            AjaxResponse::abort($asJson, 'error', t('Utilisateur introuvable.'), '/admin/users', 404);
        }
        // Jamais sur un compte plus large que le sien (un administrateur, ou un
        // rôle aux droits qui dépassent ceux de l'acteur) : le droit « Comptes —
        // Modifier » ne doit pas suffire à désactiver ou rétrograder un admin.
        if (!Role::canManageAccount($actor, $target)) {
            AjaxResponse::abort($asJson, 'error', t('Ce compte a des droits plus larges que les vôtres : seul un administrateur peut le modifier.'), '/admin/users', 403);
        }
        $currentRole = (string)($target['role'] ?? Role::USER);
        $role        = (string)($_POST['role'] ?? $currentRole);
        $isActive    = (int)($_POST['is_active'] ?? 0);
        if ($userId === (int)($actor['id'] ?? 0)) {
            // Son propre compte : ni changer de rôle (on se retirerait l'accès en
            // pleine page), ni se désactiver. Il reste ainsi toujours au moins
            // l'administrateur qui agit.
            if ($role !== $currentRole) {
                AjaxResponse::abort($asJson, 'error', t('Votre propre rôle se change par un autre administrateur.'), '/admin/users', 403);
            }
            if ($isActive !== 1) {
                AjaxResponse::abort($asJson, 'error', t('Vous ne pouvez pas désactiver votre propre compte.'), '/admin/users', 403);
            }
        }
        if ($role !== $currentRole) {
            // L'accès de secours (LOCAL_PASSWORD_HASH) doit rester un accès
            // administrateur : c'est le filet quand une connexion externe casse.
            if (($target['google_id'] ?? '') === 'local_admin') {
                AjaxResponse::abort($asJson, 'error', t('Le compte d’administration locale (accès de secours) reste administrateur.'), '/admin/users', 403);
            }
            $why = null;
            if ($this->checkedRoleChange($actor, $role, $why) === null) {
                AjaxResponse::abort($asJson, 'error', (string)$why, '/admin/users', 403);
            }
        }

        $groupIds = $this->parseGroupIdsFromPost();
        if ($groupIds !== null) {
            $validGroupIds = UserGroup::validIds($groupIds);
            if (count($validGroupIds) !== count($groupIds)) {
                AjaxResponse::abort(
                    $asJson,
                    'error',
                    t('Un ou plusieurs groupes sélectionnés sont introuvables.'),
                    '/admin/users'
                );
            }
            $groupIds = $validGroupIds;
        }

        User::update($userId, [
            'role'        => $role,
            'is_active'   => $isActive,
            'credit_quota_k' => $_POST['credit_quota_k'] ?? ($_POST['token_quota'] ?? null),
        ]);
        if ($groupIds !== null) {
            UserGroup::setGroupsForUser($userId, $groupIds);
        }
        AjaxResponse::finish($asJson, 'success', t('Utilisateur mis à jour.'), '/admin/users');
    }

    public function createUser(): void
    {
        AuthMiddleware::requirePermission('users.create');
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $role     = (string)($_POST['role'] ?? Role::USER);
        $active   = (int)($_POST['is_active'] ?? 1);
        $groupIds = $this->parseGroupIdsFromPost() ?? [];

        $why = null;
        if ($this->checkedRoleChange(Session::currentUser(), $role, $why) === null) {
            Session::flash('error', (string)$why);
            header('Location: /admin/users');
            exit;
        }

        if (!$name || !$email || strlen($password) < 8) {
            Session::flash('error', t('Nom, email et mot de passe (8 car. min.) sont requis.'));
            header('Location: /admin/users');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', t('Adresse email invalide.'));
            header('Location: /admin/users');
            exit;
        }

        if (User::emailExists($email)) {
            Session::flash('error', t('Un utilisateur avec cet email existe déjà.'));
            header('Location: /admin/users');
            exit;
        }

        $validGroupIds = UserGroup::validIds($groupIds);
        if (count($validGroupIds) !== count($groupIds)) {
            Session::flash('error', t('Un ou plusieurs groupes sélectionnés sont introuvables.'));
            header('Location: /admin/users');
            exit;
        }
        $groupIds = $validGroupIds;

        $createdUser = User::createWithPassword($name, $email, $password, $role, $active);
        UserGroup::setGroupsForUser((int)$createdUser['id'], $groupIds);
        Session::flash('success', t('Utilisateur « {nom} » créé avec succès.', ['nom' => $name]));
        header('Location: /admin/users');
        exit;
    }

    /**
     * Impact d'une suppression de compte, AVANT le geste (JSON pour la
     * confirmation détaillée). Un créateur ou un admin laisse derrière lui
     * plus que ses conversations : assistants et bases orphelins, partages
     * qui tombent, et syncs Drive portées par SA connexion qui casseront.
     */
    public function userDeletionImpact(string $id): void
    {
        AuthMiddleware::requirePermission('users.delete');
        header('Content-Type: application/json; charset=utf-8');

        $userId = (int)$id;
        $user   = User::findById($userId);
        if ($user === null) {
            echo json_encode(['ok' => false, 'error' => t('Utilisateur introuvable.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!Role::canManageAccount(Session::currentUser(), $user)) {
            echo json_encode(['ok' => false, 'error' => t('Ce compte a des droits plus larges que les vôtres.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $db    = Database::getInstance();
        $count = static function (string $sql) use ($db, $userId): int {
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        };
        $names = static function (string $sql) use ($db, $userId): array {
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        };

        echo json_encode([
            'ok'   => true,
            'role' => (string)($user['role'] ?? 'user'),
            'role_label' => Role::label((string)($user['role'] ?? 'user')),
            // Supprimé avec le compte (CASCADE).
            'conversations' => $count('SELECT COUNT(*) FROM conversations WHERE user_id = ?'),
            // Survivent mais deviennent orphelins (created_by → NULL) : plus
            // aucun creator pour les gérer, seuls les admins y ont accès.
            'profiles'      => $names('SELECT name FROM profiles WHERE created_by = ? ORDER BY name LIMIT 6'),
            'profile_count' => $count('SELECT COUNT(*) FROM profiles WHERE created_by = ?'),
            'kbs'           => $names('SELECT name FROM knowledge_bases WHERE created_by = ? ORDER BY name LIMIT 6'),
            'kb_count'      => $count('SELECT COUNT(*) FROM knowledge_bases WHERE created_by = ?'),
            // Casseront à la prochaine synchro : portées par SA connexion
            // personnelle (Google Drive ou OneDrive).
            'sync_owner_kbs' => $names(
                'SELECT name FROM knowledge_bases
                 WHERE sync_owner_user_id = ? AND sync_enabled = 1 ORDER BY name LIMIT 6'
            ),
            // Partages qu'il a accordés sur ses bases (tombent avec created_by).
            'kb_shares_given' => $count(
                'SELECT COUNT(*) FROM knowledge_base_shares s
                 JOIN knowledge_bases kb ON kb.id = s.kb_id WHERE kb.created_by = ?'
            ),
            // Connecteurs personnels : leurs jetons partent avec le compte.
            'connections' => $names('SELECT provider FROM user_drive_tokens WHERE user_id = ? ORDER BY provider'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function deleteUser(string $id): void
    {
        AuthMiddleware::requirePermission('users.delete');
        $current = Session::currentUser();
        if ((int)$id === (int)$current['id']) {
            Session::flash('error', t('Vous ne pouvez pas supprimer votre propre compte.'));
            header('Location: /admin/users');
            exit;
        }
        $target = User::findById((int)$id);
        if ($target !== null && !Role::canManageAccount($current, $target)) {
            Session::flash('error', t('Ce compte a des droits plus larges que les vôtres : seul un administrateur peut le supprimer.'));
            header('Location: /admin/users');
            exit;
        }
        User::delete((int)$id);
        Session::flash('success', t('Utilisateur supprimé.'));
        header('Location: /admin/users');
        exit;
    }

    public function resetPassword(string $id): void
    {
        AuthMiddleware::requirePermission('users.edit');
        $password = $_POST['new_password'] ?? '';
        if (strlen($password) < 8) {
            Session::flash('error', t('Le mot de passe doit contenir au moins 8 caractères.'));
            header('Location: /admin/users');
            exit;
        }
        // Changer le mot de passe d'un compte plus large que le sien, c'est
        // pouvoir s'y connecter : refusé.
        $target = User::findById((int)$id);
        if ($target === null || !Role::canManageAccount(Session::currentUser(), $target)) {
            Session::flash('error', t('Ce compte a des droits plus larges que les vôtres : seul un administrateur peut changer son mot de passe.'));
            header('Location: /admin/users');
            exit;
        }
        User::updatePassword((int)$id, $password);
        Session::flash('success', t('Mot de passe réinitialisé.'));
        header('Location: /admin/users');
        exit;
    }

    public function createUserGroup(): void
    {
        AuthMiddleware::requirePermission('groups.create');
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            Session::flash('error', t('Le nom du groupe est requis.'));
            header('Location: /admin/user-groups');
            exit;
        }

        try {
            $groupId = UserGroup::create([
                'name'        => $name,
                'credit_quota_k' => $_POST['credit_quota_k'] ?? ($_POST['token_quota'] ?? null),
                'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
            ]);
            UserGroup::setProfileAccess($groupId, (array)($_POST['profile_ids'] ?? []));
            UserGroup::setToolAccess($groupId, (array)($_POST['tool_ids'] ?? []));
            UserGroup::setLlmAccess($groupId, (array)($_POST['llm_keys'] ?? []));
            if (!empty($_POST['members_present'])) {
                UserGroup::setMembersForGroup($groupId, (array)($_POST['member_ids'] ?? []));
            }
            Session::flash('success', t('Groupe « {nom} » créé.', ['nom' => $name]));
        } catch (PDOException $e) {
            Session::flash('error', t('Impossible de créer le groupe (nom déjà utilisé ?).'));
        }

        header('Location: /admin/user-groups');
        exit;
    }

    public function updateUserGroup(string $id): void
    {
        AuthMiddleware::requirePermission('groups.edit');
        $asJson  = AjaxResponse::wanted();
        $groupId = (int)$id;
        if ($groupId <= 0 || !UserGroup::exists($groupId)) {
            AjaxResponse::abort($asJson, 'error', t('Groupe introuvable.'), '/admin/user-groups', 404);
        }

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            AjaxResponse::abort($asJson, 'error', t('Le nom du groupe est requis.'), '/admin/user-groups');
        }

        try {
            UserGroup::update($groupId, [
                'name'        => $name,
                'credit_quota_k' => $_POST['credit_quota_k'] ?? ($_POST['token_quota'] ?? null),
                'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
            ]);
            UserGroup::setProfileAccess($groupId, (array)($_POST['profile_ids'] ?? []));
            UserGroup::setToolAccess($groupId, (array)($_POST['tool_ids'] ?? []));
            UserGroup::setLlmAccess($groupId, (array)($_POST['llm_keys'] ?? []));
            if (!empty($_POST['members_present'])) {
                UserGroup::setMembersForGroup($groupId, (array)($_POST['member_ids'] ?? []));
            }
        } catch (PDOException $e) {
            AjaxResponse::abort(
                $asJson,
                'error',
                t('Impossible de mettre à jour le groupe (nom déjà utilisé ?).'),
                '/admin/user-groups'
            );
        }

        AjaxResponse::finish($asJson, 'success', t('Groupe « {nom} » mis à jour.', ['nom' => $name]), '/admin/user-groups');
    }

    public function deleteUserGroup(string $id): void
    {
        AuthMiddleware::requirePermission('groups.delete');
        $groupId = (int)$id;
        if ($groupId <= 0 || !UserGroup::exists($groupId)) {
            Session::flash('error', t('Groupe introuvable.'));
            header('Location: /admin/user-groups');
            exit;
        }

        if (UserGroup::isDefaultGroup($groupId)) {
            Session::flash('error', t('Le groupe par défaut ne peut pas être supprimé.'));
            header('Location: /admin/user-groups');
            exit;
        }

        UserGroup::delete($groupId);
        Session::flash('success', t('Groupe supprimé.'));
        header('Location: /admin/user-groups');
        exit;
    }

    public function profiles(): void
    {
        AuthMiddleware::requirePermission('assistants.view');

        // Compat : « Modifier l'assistant » du chat pointait /admin/profiles?edit=ID
        // (ouverture de l'ex-modale). L'édition est désormais une vraie page.
        $editWanted = (int)($_GET['edit'] ?? 0);
        if ($editWanted > 0) {
            header('Location: /admin/profiles/' . $editWanted . '/edit');
            exit;
        }

        $currentUser      = Session::currentUser();
        // « Assistants — Gérer » (administrateur, ou rôle qui le porte) :
        // partage aux groupes, auteur, pas de quota. Sinon : les siens.
        $isAdminManager   = Session::can('assistants.manage');
        $canCreateProfile = Session::can('assistants.create');
        $canEditProfile   = Session::can('assistants.edit');
        $canDeleteProfile = Session::can('assistants.delete');
        $profiles         = Profile::forManager($currentUser);
        $assistantAvatars  = AssistantAvatar::all();
        $avatarUsageCounts = $this->buildAssistantAvatarUsageCounts($profiles);
        $documentsByProfile = [];
        $toolIdsByProfile   = [];
        $kbIdsByProfile     = [];
        foreach ($profiles as $p) {
            $documentsByProfile[(int)$p['id']] = ProfileDocument::allForProfile((int)$p['id']);
            $toolIdsByProfile[(int)$p['id']]   = ChatTool::idsForProfile((int)$p['id']);
            $kbIdsByProfile[(int)$p['id']]     = KnowledgeBase::kbIdsForProfile((int)$p['id']);
        }

        // Nom du propriétaire (pour différencier les assistants personnels côté
        // admin). Map id → nom, uniquement pour les créateurs référencés.
        $ownerNames = [];
        $ownerIds   = array_values(array_unique(array_filter(array_map(
            static fn($p) => isset($p['created_by']) ? (int)$p['created_by'] : 0,
            $profiles
        ))));
        foreach ($ownerIds as $oid) {
            $owner = User::findById($oid);
            if ($owner) {
                $ownerNames[$oid] = $owner['name'] ?: $owner['email'];
            }
        }

        // Quota d'assistants personnels (pour l'affichage côté creator).
        $personalCount     = $isAdminManager ? 0 : Profile::countPersonalByCreator((int)$currentUser['id']);
        $personalMax       = Profile::MAX_PERSONAL_PER_CREATOR;
        $personalRemaining = max(0, $personalMax - $personalCount);

        // Libellés d'affichage de TOUS les LLMs actifs (liste) : un assistant
        // peut référencer un LLM hors whitelist du gestionnaire courant — son
        // libellé doit rester lisible dans la liste.
        $llmLabels        = LLMFactory::available();

        // Schéma affiché à droite au clic sur un assistant : le catalogue
        // (libellés, icônes, modèles, bases, outils) et la configuration de
        // chaque assistant, dessinés par diagram.js sans aller-retour. Les
        // bases nommées sont celles que le gestionnaire voit ; une base liée
        // hors de son périmètre reste comptée, sans son nom.
        $diagram = null;
        if ($profiles !== []) {
            $specs = [];
            foreach ($profiles as $p) {
                $pid = (int)$p['id'];
                $specs[$pid] = AssistantDiagram::spec(
                    $p,
                    $kbIdsByProfile[$pid] ?? [],
                    $toolIdsByProfile[$pid] ?? [],
                    $documentsByProfile[$pid] ?? []
                );
            }
            $diagram = [
                'catalog'    => AssistantDiagram::catalog(
                    KnowledgeBase::forManager($currentUser),
                    ChatTool::findByIds(array_merge([], ...array_values($toolIdsByProfile)))
                ),
                'assistants' => (object)$specs,
            ];
        }

        $success          = Session::getFlash('success');
        $error            = Session::getFlash('error');
        require ROOT . '/templates/admin/profiles/list.php';
    }

    /** Page « Créer un assistant » (ex-modale de création). */
    public function newProfile(): void
    {
        AuthMiddleware::requirePermission('assistants.create');
        $currentUser = Session::currentUser();
        // Même garde de quota que createProfile : inutile de présenter un
        // formulaire dont la soumission sera refusée.
        if (!Session::can('assistants.manage')) {
            $count = Profile::countPersonalByCreator((int)$currentUser['id']);
            if ($count >= Profile::MAX_PERSONAL_PER_CREATOR) {
                Session::flash('error', t('Limite atteinte : vous ne pouvez pas dépasser {n} assistants personnels.', [
                    'n' => Profile::MAX_PERSONAL_PER_CREATOR,
                ]));
                header('Location: /admin/profiles');
                exit;
            }
        }
        // « Ajuster » sur la carte d'un assistant préparé par MIA dans le
        // chat : la fiche s'ouvre remplie avec la proposition ; « Créer »
        // l'enregistre avec les règles du formulaire et ferme la carte.
        $prefill  = null;
        $actionId = (int)($_GET['action'] ?? 0);
        if ($actionId > 0) {
            $pending = DrivePendingAction::findPendingForUser($actionId, (int)$currentUser['id']);
            if ($pending !== null && (string)$pending['tool'] === AssistantDraft::TOOL) {
                $prefill = AssistantDraft::formPrefill((array)$pending['args']) + ['from_action' => $actionId];
            } else {
                Session::flash('error', t('Cette proposition de MIA n\'est plus en attente (déjà approuvée, annulée ou expirée) : la fiche s\'ouvre vide.'));
            }
        }
        $this->renderProfileForm(null, $prefill);
    }

    /** Page d'édition d'un assistant (ex-modale d'édition). */
    public function editProfile(string $id): void
    {
        AuthMiddleware::requirePermission('assistants.view');
        if (!Profile::manageableBy((int)$id, Session::currentUser())) {
            Session::flash('error', t('Assistant introuvable ou accès refusé.'));
            header('Location: /admin/profiles');
            exit;
        }
        $profile = Profile::findById((int)$id);
        if ($profile === null) {
            Session::flash('error', t('Assistant introuvable.'));
            header('Location: /admin/profiles');
            exit;
        }
        $this->renderProfileForm($profile);
    }

    /**
     * Données communes du formulaire assistant (création + édition).
     *
     * @param array|null $prefill création pré-remplie depuis la carte d'un
     *        assistant préparé par MIA (AssistantDraft::formPrefill + from_action)
     */
    private function renderProfileForm(?array $profile, ?array $prefill = null): void
    {
        $currentUser    = Session::currentUser();
        $isAdminManager = Session::can('assistants.manage');
        // Fiche en lecture seule sans « Assistants — Modifier » (création : le
        // droit de créer suffit) ; suppression selon « Assistants — Supprimer ».
        $canEditProfile   = $profile === null ? Session::can('assistants.create') : Session::can('assistants.edit');
        $canDeleteProfile = Session::can('assistants.delete');
        $assistantAvatars = AssistantAvatar::all();
        // Bases de connaissances rattachables (périmètre du gestionnaire).
        $knowledgeBases = KnowledgeBase::forManager($currentUser);
        // Outils actifs proposés en « Capacités » de l'assistant
        // (function-calling) : génération d'image, automatisations n8n et
        // présentations Gamma. Un assistant lié à l'outil peut le déclencher
        // lui-même en répondant.
        $imageTools     = ChatTool::active('image');
        $n8nTools       = ChatTool::active(N8nService::TOOL_TYPE);
        $gammaTools     = ChatTool::active(GammaService::TOOL_TYPE);

        // LLMs sélectionnables : restreints aux LLMs autorisés pour le
        // gestionnaire courant (admin = tous ; creator = whitelist de ses groupes).
        $llms = $this->allowedLlmsForCurrentManager();

        // Modèle par défaut ACTUEL de l'application, nommé à côté de
        // « Défaut application » : c'est lui que l'assistant suivra, et il
        // suivra aussi ses changements. Défaut EFFECTIF, jamais le réglage
        // brut (qui peut désigner un modèle retiré) : même règle que le chat
        // et la liste blanche des groupes. Rien d'affiché sans modèle activé.
        $allLlms            = LLMFactory::available();
        $appDefaultLlm      = LLMFactory::appDefault($allLlms);
        $appDefaultLlmLabel = $appDefaultLlm !== null ? ($allLlms[$appDefaultLlm] ?? null) : null;

        // Budget RAG du plus petit modèle autorisé : sert à avertir (UI) quand les
        // documents d'un assistant dépassent ce qu'un petit contexte peut recevoir.
        // La dégradation reste automatique (retrieval budgété) — c'est informatif.
        $ragMinBudget = 0;
        foreach (array_keys($llms) as $llmKey) {
            [$prov, $model] = str_contains($llmKey, '::')
                ? explode('::', $llmKey, 2)
                : [$llmKey, $llmKey];
            $budget = ProfileDocument::ragTokenBudget(
                LlmCatalog::contextWindow(strtolower(trim($prov)), trim($model))
            );
            if ($ragMinBudget === 0 || $budget < $ragMinBudget) {
                $ragMinBudget = $budget;
            }
        }

        $boundToolIds = [];
        $boundKbIds   = [];
        $profileDocs  = [];
        if ($profile !== null) {
            $pid          = (int)$profile['id'];
            $boundToolIds = array_map('intval', ChatTool::idsForProfile($pid));
            $boundKbIds   = array_map('intval', KnowledgeBase::kbIdsForProfile($pid));
            // Même forme que la liste et le schéma (AssistantDiagram::docEntry).
            $profileDocs  = array_map([AssistantDiagram::class, 'docEntry'], ProfileDocument::allForProfile($pid));
        } elseif ($prefill !== null) {
            $boundToolIds = $prefill['tool_ids'];
            $boundKbIds   = $prefill['kb_ids'];
        }

        // Schéma de l'assistant, à droite de la fiche : la page le recompose à
        // chaque geste depuis ses champs. Les liaisons que le formulaire ne
        // porte pas — la base système, une base hors du périmètre du
        // gestionnaire — survivent à l'enregistrement (applyProfileKbLinks) :
        // le schéma les montre, fixes.
        $formKbIds = [];
        foreach ($knowledgeBases as $kb) {
            if ((int)($kb['is_system'] ?? 0) !== 1) {
                $formKbIds[(int)$kb['id']] = true;
            }
        }
        $diagram = [
            'catalog'      => AssistantDiagram::catalog($knowledgeBases, array_merge($imageTools, $n8nTools, $gammaTools)),
            // Un assistant naît personnel (createProfile) ; le partage se règle dans la liste.
            'sharing'      => $profile !== null ? AssistantDiagram::sharingOf($profile) : 'personal',
            'fixed_kb_ids' => array_values(array_filter($boundKbIds, static fn(int $id): bool => !isset($formKbIds[$id]))),
        ];

        // Points de départ du prompt système : la bibliothèque livrée, et
        // l'aide à la rédaction par LLM — proposée seulement si un modèle
        // par défaut est réellement appelable (pas de bouton mort).
        $promptStarters    = PromptStarters::all();
        $promptSkeleton    = PromptStarters::skeleton();
        $promptAiAvailable = SemanticChunker::resolveChunkingLlm() !== null;

        $success = Session::getFlash('success');
        $error   = Session::getFlash('error');
        require ROOT . '/templates/admin/profiles/edit.php';
    }

    /**
     * POST /admin/profiles/prompt/generate — rédige un prompt système depuis
     * un brief, ou améliore celui qui est saisi (JSON, AJAX seulement).
     *
     * Toujours le LLM PAR DÉFAUT de l'app (`resolveChunkingLlm`, source déjà
     * partagée par le chunking et la mémoire) : un seul modèle, un résultat
     * homogène, indépendant du choix de la fiche. Facturé au créateur comme
     * un message de chat (quota vérifié avant l'appel, usage journalisé en
     * `prompt_assist`) : la rédaction est un service rendu à SON compte.
     *
     * Rien n'est persisté ici : le texte revient dans le champ, le créateur
     * le relit, l'adapte, et c'est la fiche qui l'enregistre.
     */
    public function generateProfilePrompt(): void
    {
        AuthMiddleware::requireAnyPermission('assistants.create', 'assistants.edit');
        header('Content-Type: application/json; charset=utf-8');
        $user = Session::currentUser();

        try {
            $request = PromptStarters::normalizeRequest($_POST);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Fiche en cours d'édition : l'usage lui est rattaché (statistiques
        // par assistant), à condition qu'elle soit bien gérée par l'appelant.
        $profileId = (int)($_POST['profile_id'] ?? 0);
        if ($profileId > 0 && !Profile::manageableBy($profileId, $user)) {
            $profileId = 0;
        }

        $quota = CreditQuota::status($user);
        if (CreditQuota::isExceeded($quota)) {
            http_response_code(429);
            echo json_encode([
                'ok'             => false,
                'quota_exceeded' => true,
                // Le budget de l'organisation et le quota personnel ne se
                // débloquent pas par le même geste : le message dit lequel.
                'error'          => ($quota['reason'] ?? '') === CreditQuota::REASON_BUDGET
                    ? (Session::can('costs.edit')
                        ? t('Budget IA du mois atteint : la rédaction assistée est suspendue jusqu\'au {date}. Les modèles de la bibliothèque restent utilisables. Accordez une rallonge dans Coûts → Budget.', [
                            'date' => CreditQuota::resetLabel((string)$quota['resets_on']),
                        ])
                        : t('Budget IA du mois atteint : la rédaction assistée est suspendue jusqu\'au {date}. Les modèles de la bibliothèque restent utilisables. Contactez un administrateur.', [
                            'date' => CreditQuota::resetLabel((string)$quota['resets_on']),
                        ]))
                    : t('Quota mensuel de crédits atteint : la rédaction assistée est suspendue jusqu\'au mois prochain. Les modèles de la bibliothèque restent utilisables.'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $llm = SemanticChunker::resolveChunkingLlm();
        if ($llm === null) {
            http_response_code(503);
            echo json_encode([
                'ok'    => false,
                'error' => t('Aucun modèle de langage n\'est configuré : la rédaction assistée est indisponible. Partez d\'un modèle de la bibliothèque.'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        [$provider, $model] = explode('::', $llm, 2);
        $temperature = LlmCatalog::supportsTemperature($provider, $model) ? PromptStarters::TEMPERATURE : null;

        // Même garde que les autres appels courts : une erreur d'adaptateur
        // arrive en chunk texte AVANT le premier token utile, et ne doit
        // jamais atteindre le client telle quelle (fuite possible de clé).
        $raw         = '';
        $streamError = null;
        $first       = false;
        $tokens      = ['input' => 0, 'output' => 0];
        try {
            $tokens = LLMFactory::make($llm)->stream(
                [['role' => 'user', 'content' => PromptStarters::generationUserPrompt($request)]],
                PromptStarters::generationSystemPrompt($request['mode']),
                function (string $chunk) use (&$raw, &$streamError, &$first): void {
                    if (!$first) {
                        $desc = LlmErrorPresenter::parse($chunk);
                        if ($desc !== null) {
                            $streamError = LlmErrorPresenter::present($desc);
                            return;
                        }
                    }
                    $first = true;
                    if (strlen($raw) < PromptStarters::OUTPUT_MAX * 4) {
                        $raw .= $chunk;
                    }
                },
                $temperature,
                false
            );
        } catch (\Throwable $e) {
            error_log('[Prompt assistant] rédaction échouée (' . $e->getMessage() . ')');
            $streamError = LlmErrorPresenter::present(['kind' => 'network', 'provider' => $provider, 'status' => null]);
        }

        $prompt = $streamError === null ? PromptStarters::cleanOutput($raw) : '';
        if ($streamError === null && $prompt === '') {
            $streamError = LlmErrorPresenter::emptyError($provider);
        }

        // Facturation au créateur, y compris sur une sortie inexploitable :
        // les tokens ont été consommés (le compteur ne journalise rien à zéro).
        UsageMeter::llm($llm, is_array($tokens) ? $tokens : [], UsageSource::PROMPT_ASSIST, [
            'user_id'    => (int)$user['id'],
            'profile_id' => $profileId > 0 ? $profileId : null,
        ]);

        if ($streamError !== null) {
            http_response_code(502);
            echo json_encode([
                'ok'    => false,
                'error' => (string)($streamError['message'] ?? t('Le modèle n\'a rien renvoyé.')),
                'title' => (string)($streamError['title'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'ok'     => true,
            'mode'   => $request['mode'],
            'prompt' => $prompt,
            'llm'    => LlmErrorPresenter::providerLabel($provider) ?? $provider,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function createProfile(): void
    {
        AuthMiddleware::requirePermission('assistants.create');
        $currentUser = Session::currentUser();
        // Tout assistant est créateur-seul par défaut (is_personal=1) : visible et
        // utilisable uniquement par son créateur. Un admin peut ensuite le
        // promouvoir en assistant d'organisation via /toggle-personal.

        // Quota d'assistants personnels pour les créateurs (« Assistants —
        // Gérer » : illimité).
        if (!Session::can('assistants.manage')) {
            $count = Profile::countPersonalByCreator((int)$currentUser['id']);
            if ($count >= Profile::MAX_PERSONAL_PER_CREATOR) {
                Session::flash('error', t('Limite atteinte : vous ne pouvez pas dépasser {n} assistants personnels.', [
                    'n' => Profile::MAX_PERSONAL_PER_CREATOR,
                ]));
                header('Location: /admin/profiles');
                exit;
            }
        }

        // Fiche ouverte depuis la carte d'un assistant préparé par MIA
        // (« Ajuster ») : la carte est relue ici, jamais l'adresse d'image
        // postée — seul ce que le serveur a mis dans la carte peut entrer
        // dans la bibliothèque des avatars.
        $fromAction = (int)($_POST['from_action'] ?? 0);
        $draftCard  = $fromAction > 0 ? DrivePendingAction::findPendingForUser($fromAction, (int)$currentUser['id']) : null;
        if ($draftCard !== null && (string)$draftCard['tool'] !== AssistantDraft::TOOL) {
            $draftCard = null;
        }

        try {
            $avatar = $this->resolveAssistantAvatarFromRequest();
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            header('Location: /admin/profiles/new' . ($draftCard !== null ? '?action=' . $fromAction : ''));
            exit;
        }
        // Avatar créé par MIA pour cet assistant : importé dans la bibliothèque
        // maintenant seulement (carré, réduit, nommé comme l'assistant).
        if ($avatar === null && $draftCard !== null
            && (string)($_POST['avatar'] ?? '') === AssistantDraft::GENERATED_AVATAR_CHOICE) {
            $generatedPath = AssistantDraft::generatedImagePath((string)($draftCard['args']['avatar_generated'] ?? ''));
            $avatar = $generatedPath !== null
                ? AssistantAvatar::importImage($generatedPath, trim((string)($_POST['name'] ?? '')), (int)$currentUser['id'])
                : null;
        }

        // « Défaut application » s'enregistre SANS modèle : l'assistant suit
        // le défaut courant, jamais celui du jour de l'enregistrement.
        $defaultLlm = Profile::normalizeDefaultLlm(
            (string)($_POST['default_llm'] ?? ''),
            $this->allowedLlmsForCurrentManager()
        );
        $id = Profile::create([
            'name'          => $_POST['name']          ?? '',
            'description'   => $_POST['description']   ?? '',
            'system_prompt' => $_POST['system_prompt'] ?? '',
            'default_llm'   => $defaultLlm,
            // Case décochée = clé absente du POST = inactif. Le défaut valait
            // 1 : désactiver un assistant depuis sa fiche n'avait donc aucun
            // effet. Même convention que les utilisateurs et les outils.
            'is_active'     => (int)($_POST['is_active'] ?? 0),
            'color'         => !empty($_POST['color']) ? $_POST['color'] : null,
            'temperature'   => $_POST['temperature'] ?? null,
            'avatar'        => $avatar,
            'created_by'    => (int)$currentUser['id'],
            'is_personal'   => 1,
        ]);
        // Assistant créateur-seul : visibilité gérée par created_by + is_personal.
        // Pas de rôles d'accès. L'admin peut le promouvoir en assistant
        // d'organisation ensuite via /admin/profiles/{id}/toggle-personal.
        Profile::setTools($id, $this->sanitizeProfileToolIds($_POST['tool_ids'] ?? []));
        $this->applyProfileKbLinks($id, $_POST['kb_ids'] ?? []);

        $docsResult = $this->storeMultipleProfileDocumentsFromRequest($id, 'documents');
        if ($draftCard !== null) {
            AssistantDraft::closeAdjustedCard($fromAction, (int)$currentUser['id'], $id, trim((string)($_POST['name'] ?? '')));
        }
        $msg = t('Assistant créé.');
        if ($docsResult['stored'] > 0) {
            $msg .= ' ' . I18n::plural($docsResult['stored'], '{n} document ajouté.', '{n} documents ajoutés.');
        }
        if (!empty($docsResult['warnings'])) {
            $msg .= ' ⚠ ' . implode(' ', $docsResult['warnings']);
        }
        if (!empty($docsResult['errors'])) {
            Session::flash('error', implode(' ', $docsResult['errors']));
        }
        Session::flash('success', $msg);
        // La liste s'ouvre sur l'assistant créé et son schéma — sauf si un
        // document a posé problème : le message, en tête, doit rester en vue.
        $problems = !empty($docsResult['errors']) || !empty($docsResult['warnings']);
        header('Location: /admin/profiles' . ($problems ? '' : '#assistant-' . $id));
        exit;
    }

    public function updateProfile(string $id): void
    {
        AuthMiddleware::requirePermission('assistants.edit');
        $asJson = AjaxResponse::wanted();
        if (!Profile::manageableBy((int)$id, Session::currentUser())) {
            AjaxResponse::abort($asJson, 'error', t('Assistant introuvable ou accès refusé.'), '/admin/profiles', 403);
        }
        try {
            $avatar = $this->resolveAssistantAvatarFromRequest();
        } catch (\Throwable $e) {
            AjaxResponse::abort($asJson, 'error', $e->getMessage(), '/admin/profiles/' . (int)$id . '/edit');
        }

        // Le nom et le prompt système portent le `required` du formulaire ;
        // la fiche s'enregistrant à la frappe, un champ vidé doit être refusé
        // explicitement plutôt qu'écrit tel quel.
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            AjaxResponse::abort(
                $asJson,
                'error',
                t("Le nom de l'assistant est obligatoire."),
                '/admin/profiles/' . (int)$id . '/edit'
            );
        }
        $systemPrompt = trim((string)($_POST['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            AjaxResponse::abort(
                $asJson,
                'error',
                t('Le prompt système est obligatoire.'),
                '/admin/profiles/' . (int)$id . '/edit'
            );
        }

        // « Défaut application » s'enregistre SANS modèle : l'assistant suit
        // le défaut courant, jamais celui du jour de l'enregistrement.
        $defaultLlm = Profile::normalizeDefaultLlm(
            (string)($_POST['default_llm'] ?? ''),
            $this->allowedLlmsForCurrentManager()
        );
        Profile::update((int)$id, [
            'name'          => $name,
            'description'   => $_POST['description']   ?? '',
            'system_prompt' => $systemPrompt,
            'default_llm'   => $defaultLlm,
            // Cf. createProfile : case absente = inactif (le défaut à 1
            // rendait le bouton « Actif » de la fiche inopérant).
            'is_active'     => (int)($_POST['is_active'] ?? 0),
            'color'         => !empty($_POST['color']) ? $_POST['color'] : null,
            'temperature'   => $_POST['temperature'] ?? null,
            'avatar'        => $avatar,
        ]);
        // Plus de rôles d'accès configurables : la visibilité créateur-seul /
        // organisation est pilotée par is_personal (cf. /toggle-personal).
        Profile::setTools((int)$id, $this->sanitizeProfileToolIds($_POST['tool_ids'] ?? []));
        $this->applyProfileKbLinks((int)$id, $_POST['kb_ids'] ?? []);
        // `avatar` : la fiche affiche l'aperçu depuis le fichier choisi, mais
        // un avatar tout juste téléversé porte un nom généré par le serveur —
        // le renvoyer permet à la fiche de cocher la bonne vignette sans
        // recharger la page.
        AjaxResponse::finish(
            $asJson,
            'success',
            t('Assistant mis à jour.'),
            '/admin/profiles',
            ['avatar' => $avatar]
        );
    }

    /**
     * Filtre une liste d'IDs d'outils issue du POST : ne conserve que des
     * outils liables actifs (empêche de lier un assistant à un outil
     * arbitraire/inactif).
     *
     * @param mixed $raw
     * @return list<int>
     */
    private function sanitizeProfileToolIds($raw): array
    {
        // Seuls les types réellement exposés en function-calling sont liables
        // (image, n8n, Gamma) : cocher autre chose n'aurait aucun effet. Règle
        // partagée avec l'assistant préparé par MIA dans le chat.
        return AssistantDraft::sanitizeToolIds($raw);
    }

    public function deleteProfile(string $id): void
    {
        AuthMiddleware::requirePermission('assistants.delete');
        if (!Profile::manageableBy((int)$id, Session::currentUser())) {
            Session::flash('error', t('Assistant introuvable ou accès refusé.'));
            header('Location: /admin/profiles');
            exit;
        }
        Profile::delete((int)$id);
        Session::flash('success', t('Assistant supprimé.'));
        header('Location: /admin/profiles');
        exit;
    }

    /**
     * Bascule un assistant personnel ↔ organisation. Réservé aux admins.
     *  - personnel → organisation : devient autorisable via les groupes ;
     *  - organisation → personnel : se re-cloisonne à son créateur (nécessite
     *    un created_by, sinon l'assistant deviendrait orphelin et invisible).
     * created_by reste inchangé.
     */
    public function togglePersonal(string $id): void
    {
        AuthMiddleware::requirePermission('assistants.manage');
        $profileId = (int)$id;
        $profile   = Profile::findById($profileId);
        if (!$profile) {
            Session::flash('error', t('Assistant introuvable.'));
            header('Location: /admin/profiles');
            exit;
        }

        $name = (string)($profile['name'] ?? 'Assistant');
        if (!empty($profile['is_personal'])) {
            // Promotion personnel → organisation.
            Profile::setPersonal($profileId, false);
            Session::flash('success', t('« {nom} » est désormais partagé. Pensez à l\'autoriser dans Groupes › Assistants autorisés pour que les membres du groupe concerné puissent l\'utiliser.', [
                'nom' => $name,
            ]));
        } else {
            // Rétrogradation organisation → personnel : besoin d'un propriétaire.
            if (empty($profile['created_by'])) {
                Session::flash('error', t('« {nom} » n\'a pas de créateur : impossible de le rendre personnel (il deviendrait invisible). Définissez d\'abord un créateur.', [
                    'nom' => $name,
                ]));
                header('Location: /admin/profiles');
                exit;
            }
            Profile::setPersonal($profileId, true);
            Session::flash('success', t('« {nom} » est redevenu un assistant personnel, visible de son seul créateur.', [
                'nom' => $name,
            ]));
        }
        // Retour sur l'assistant basculé : sa ligne et son schéma.
        header('Location: /admin/profiles#assistant-' . $profileId);
        exit;
    }

    public function uploadProfileAvatar(): void
    {
        AuthMiddleware::requireAnyPermission('assistants.create', 'assistants.edit');

        try {
            $filename = AssistantAvatar::storeUpload($_FILES['avatar_upload'] ?? []);
            if ($filename === null) {
                throw new \RuntimeException(t('Veuillez sélectionner un avatar à uploader.'));
            }
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            header('Location: /admin/profiles');
            exit;
        }

        $name   = (string)($_POST['avatar_name'] ?? '');
        $userId = (int)(Session::currentUser()['id'] ?? 0) ?: null;
        AssistantAvatar::register($filename, $name, $userId);

        Session::flash('success', t('Avatar « {nom} » ajouté à la bibliothèque.', ['nom' => AssistantAvatar::nameFor($filename)]));
        header('Location: /admin/profiles');
        exit;
    }

    public function renameProfileAvatar(): void
    {
        AuthMiddleware::requireAnyPermission('assistants.create', 'assistants.edit');
        $filename = trim((string)($_POST['avatar'] ?? ''));
        $avatar   = AssistantAvatar::findByFilename($filename);

        if (!$avatar) {
            Session::flash('error', t('Avatar introuvable.'));
            header('Location: /admin/profiles');
            exit;
        }
        if (!AssistantAvatar::manageableBy($avatar, Session::currentUser())) {
            Session::flash('error', t('Vous ne pouvez renommer que les avatars que vous avez ajoutés.'));
            header('Location: /admin/profiles');
            exit;
        }
        if (!AssistantAvatar::rename($filename, (string)($_POST['name'] ?? ''))) {
            Session::flash('error', t('Le nom de l\'avatar ne peut pas être vide.'));
            header('Location: /admin/profiles');
            exit;
        }

        Session::flash('success', t('Avatar renommé.'));
        header('Location: /admin/profiles');
        exit;
    }

    public function deleteProfileAvatar(): void
    {
        AuthMiddleware::requireAnyPermission('assistants.create', 'assistants.edit');
        $filename = trim((string)($_POST['avatar'] ?? ''));
        $avatar   = AssistantAvatar::findByFilename($filename);

        if (!$avatar || !AssistantAvatar::fileExists($filename)) {
            Session::flash('error', t('Avatar introuvable ou déjà supprimé.'));
            header('Location: /admin/profiles');
            exit;
        }
        if (AssistantAvatar::isDefault($filename)) {
            Session::flash('error', t('Cet avatar système ne peut pas être supprimé.'));
            header('Location: /admin/profiles');
            exit;
        }
        if (!AssistantAvatar::manageableBy($avatar, Session::currentUser())) {
            Session::flash('error', t('Vous ne pouvez supprimer que les avatars que vous avez ajoutés.'));
            header('Location: /admin/profiles');
            exit;
        }

        $db = Database::getInstance();
        $countStmt = $db->prepare('SELECT COUNT(*) FROM profiles WHERE avatar = ?');
        $countStmt->execute([$filename]);
        $affectedProfiles = (int)$countStmt->fetchColumn();

        $db->prepare('UPDATE profiles SET avatar = NULL, updated_at = NOW() WHERE avatar = ?')->execute([$filename]);

        if (!AssistantAvatar::deleteFile($filename)) {
            Session::flash('error', t('Impossible de supprimer le fichier avatar sur le serveur.'));
            header('Location: /admin/profiles');
            exit;
        }
        AssistantAvatar::deleteByFilename($filename);

        Session::flash('success', $affectedProfiles > 0
            ? I18n::plural($affectedProfiles, 'Avatar supprimé ({n} assistant mis à jour).', 'Avatar supprimé ({n} assistants mis à jour).')
            : t('Avatar supprimé.'));
        header('Location: /admin/profiles');
        exit;
    }

    // ---- Documents de référence (RAG local par profil) ----

    public function uploadProfileDocument(string $id): void
    {
        AuthMiddleware::requirePermission('assistants.edit');
        $profileId = (int)$id;
        $profile   = Profile::findById($profileId);
        $isAjax    = $this->wantsJsonResponse();

        if (!$profile || !Profile::manageableBy($profileId, Session::currentUser())) {
            $this->respondProfileDocumentResult(
                ['ok' => false, 'error' => t('Assistant introuvable ou accès refusé.')],
                $isAjax
            );
        }

        if (empty($_FILES['document']) || !is_array($_FILES['document'])) {
            $this->respondProfileDocumentResult(
                ['ok' => false, 'error' => t('Aucun fichier reçu.')],
                $isAjax
            );
        }

        $r = $this->storeProfileDocumentFromUpload($profileId, $_FILES['document']);
        if (!$r['ok']) {
            $this->respondProfileDocumentResult(
                ['ok' => false, 'error' => $r['error'] ?? t('Aucun fichier reçu.')],
                $isAjax
            );
        }

        $payload = ['ok' => true];
        if (!empty($r['warning'])) {
            $payload['warning'] = $r['warning'];
        }
        if ($isAjax && !empty($r['document_id'])) {
            $doc = ProfileDocument::findById((int)$r['document_id']);
            if ($doc) {
                $payload['document'] = [
                    'id'                => (int)$doc['id'],
                    'file_name'         => (string)$doc['file_name'],
                    'mime_type'         => (string)$doc['mime_type'],
                    'file_size'         => (int)$doc['file_size'],
                    'token_count'       => (int)$doc['token_count'],
                    'has_text'          => !empty($doc['extracted_text']) ? 1 : 0,
                    'extraction_status' => (string)($doc['extraction_status'] ?? 'done'),
                ];
            }
        }
        $payload['profile_name'] = $profile['name'];
        $this->respondProfileDocumentResult($payload, $isAjax);
    }

    public function deleteProfileDocument(string $id, string $docId): void
    {
        AuthMiddleware::requirePermission('assistants.edit');
        $profileId  = (int)$id;
        $documentId = (int)$docId;
        $isAjax     = $this->wantsJsonResponse();

        if (!Profile::manageableBy($profileId, Session::currentUser())) {
            $this->respondProfileDocumentResult(
                ['ok' => false, 'error' => t('Assistant introuvable ou accès refusé.')],
                $isAjax
            );
        }

        $doc = ProfileDocument::findById($documentId);
        if (!$doc || (int)$doc['profile_id'] !== $profileId) {
            $this->respondProfileDocumentResult(
                ['ok' => false, 'error' => t('Document introuvable.')],
                $isAjax
            );
        }

        ProfileDocument::delete($documentId);
        $this->respondProfileDocumentResult(['ok' => true, 'document_id' => $documentId], $isAjax);
    }

    /**
     * Statut d'extraction d'un document (polling front pendant l'extraction async).
     */
    public function profileDocumentStatus(string $id, string $docId): void
    {
        AuthMiddleware::requirePermission('assistants.view');
        $profileId  = (int)$id;
        $documentId = (int)$docId;

        header('Content-Type: application/json; charset=utf-8');

        if (!Profile::manageableBy($profileId, Session::currentUser())) {
            echo json_encode(['ok' => false, 'error' => t('Accès refusé.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $doc = ProfileDocument::findById($documentId);
        if (!$doc || (int)$doc['profile_id'] !== $profileId) {
            echo json_encode(['ok' => false, 'error' => t('Document introuvable.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'ok'          => true,
            'status'      => (string)($doc['extraction_status'] ?? 'done'),
            'token_count' => (int)$doc['token_count'],
            'has_text'    => !empty($doc['extracted_text']) ? 1 : 0,
            'error'       => $doc['extraction_error'] !== null ? (string)$doc['extraction_error'] : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Détecte les requêtes AJAX attendant une réponse JSON. */
    private function wantsJsonResponse(): bool
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /**
     * Renvoie soit un JSON (AJAX), soit un flash + redirect.
     * @param array<string, mixed> $payload
     */
    private function respondProfileDocumentResult(array $payload, bool $asJson): void
    {
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!empty($payload['ok'])) {
            $msg = isset($payload['profile_name'])
                ? t('Document ajouté à « {nom} ».', ['nom' => (string)$payload['profile_name']])
                : t('Document supprimé.');
            if (!empty($payload['warning'])) {
                $msg .= ' ⚠ ' . (string)$payload['warning'];
            }
            Session::flash('success', $msg);
        } else {
            Session::flash('error', (string)($payload['error'] ?? t('Erreur')));
        }
        header('Location: /admin/profiles');
        exit;
    }

    // ---- Bases de connaissances (RAG vectoriel partagé) ----

    public function knowledgeBases(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.view');
        $currentUser    = Session::currentUser();
        // « Bases de connaissances — Gérer » (administrateur, ou rôle qui le
        // porte) : pas de quota, cerveaux MyBrain, sources synchronisées.
        $isAdminManager = Session::can('knowledge_bases.manage');
        $kbCanCreate    = Session::can('knowledge_bases.create');
        $canSeeVector   = Session::can('diagnostics.view');
        $canSeeBudget   = Session::can('costs.view');

        // Toutes les bases (« Gérer ») ; sinon les siennes et celles qu'on lui partage.
        $kbs = KnowledgeBase::forManager($currentUser);

        $docsByKb             = [];
        $linkedProfileIdsByKb = [];
        $sharesByKb           = [];
        $ownedByKb            = [];
        $defaultOnByKb        = [];
        $canFeedByKb          = [];
        foreach ($kbs as $kb) {
            $docsByKb[(int)$kb['id']]             = KnowledgeBaseDocument::allForKb((int)$kb['id']);
            $linkedProfileIdsByKb[(int)$kb['id']] = KnowledgeBase::profileIdsFor((int)$kb['id']);
            $sharesByKb[(int)$kb['id']]           = KnowledgeBase::sharesFor((int)$kb['id']);
            $ownedByKb[(int)$kb['id']]            = KnowledgeBase::ownedBy((int)$kb['id'], $currentUser);
            // « Cochée par défaut » et « peut nourrir » n'existent que pour une base en direct.
            if (KnowledgeBase::isRemote($kb)) {
                $defaultOnByKb[(int)$kb['id']] = KnowledgeBase::defaultOnFor((int)$kb['id']);
                $canFeedByKb[(int)$kb['id']]   = KnowledgeBase::feedersFor((int)$kb['id']);
            }
        }

        // Noms et visages du schéma : assistants du périmètre, personnes et
        // groupes à qui une base peut être partagée.
        $manageableProfiles = Profile::forManager($currentUser);
        $shareableUsers     = $this->shareableManagers((int)$currentUser['id']);
        $shareableGroups    = UserGroup::all();

        // Créer une base locale demande une clé d'embedding (sauf aux
        // administrateurs, qui peuvent toujours brancher un cerveau MyBrain).
        $embeddingProviders = EmbeddingService::availableProviders();

        // Disponibilité Qdrant : bandeau « mode dégradé » si indisponible
        // (le diagnostic complet vit sur /admin/knowledge-bases/vector).
        $qdrantUp = (new QdrantStore())->healthCheck();

        // Budget IA atteint : synchronisation AUTOMATIQUE en pause (KbSync).
        // Le bandeau n'apparaît que si une base se synchronise réellement.
        $autoSyncPaused = AiBudget::blocksAutomaticSync() && array_filter(
            $kbs,
            static fn(array $kb): bool => (int)($kb['sync_enabled'] ?? 0) === 1 && (string)($kb['sync_provider'] ?? '') !== ''
        ) !== [];
        $budgetResetLabel = CreditQuota::resetLabel(AiBudget::monthWindow(new DateTimeImmutable('now'))['resets_on']);

        $syncOwnerNameByKb = $this->kbSyncOwnerNames($kbs);

        // Quota de bases pour les créateurs (admins illimités).
        $kbCount     = $isAdminManager ? 0 : KnowledgeBase::countByCreator((int)$currentUser['id']);
        $kbMax       = KnowledgeBase::MAX_PER_CREATOR;
        $kbRemaining = max(0, $kbMax - $kbCount);

        // État de chaque base (synchronisation, indexation, échecs) : même
        // calcul que le rafraîchissement en direct (knowledgeBasesStatus).
        $kbStatuses = KbStatus::forBases($kbs);

        // Schéma affiché à droite au clic sur une base : le catalogue et la
        // configuration de chaque base, dessinés par diagram.js +
        // kb-diagram.js sans aller-retour.
        $diagram = null;
        if ($kbs !== []) {
            $specs = [];
            foreach ($kbs as $kb) {
                $kbId  = (int)$kb['id'];
                $owned = (bool)($ownedByKb[$kbId] ?? false);
                $specs[$kbId] = KbDiagram::spec(
                    $kb,
                    $docsByKb[$kbId] ?? [],
                    $linkedProfileIdsByKb[$kbId] ?? [],
                    KbDiagram::access(
                        $kb,
                        $owned,
                        (int)($kb['created_by'] ?? 0) === (int)$currentUser['id'],
                        $sharesByKb[$kbId] ?? null,
                        $defaultOnByKb[$kbId] ?? null,
                        $canFeedByKb[$kbId] ?? null
                    ),
                    $kbStatuses[$kbId] ?? null,
                    $syncOwnerNameByKb[$kbId] ?? ''
                );
            }
            $diagram = [
                'catalog' => KbDiagram::catalog($manageableProfiles, $shareableUsers, $shareableGroups, KbSync::intervalMinutes(), $qdrantUp),
                'bases'   => (object)$specs,
            ];
        }

        $success = Session::getFlash('success');
        $error   = Session::getFlash('error');
        require ROOT . '/templates/admin/knowledge-bases.php';
    }

    /** Page « Nouvelle base de connaissances » (ex-modale de création). */
    public function newKnowledgeBase(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.create');
        $currentUser = Session::currentUser();
        // Mêmes gardes que createKnowledgeBase : inutile de présenter une
        // fiche dont l'envoi serait refusé.
        if (!Session::can('knowledge_bases.manage')) {
            if (KnowledgeBase::countByCreator((int)$currentUser['id']) >= KnowledgeBase::MAX_PER_CREATOR) {
                Session::flash('error', t('Limite atteinte : vous ne pouvez pas dépasser {n} bases de connaissances.', [
                    'n' => KnowledgeBase::MAX_PER_CREATOR,
                ]));
                header('Location: /admin/knowledge-bases');
                exit;
            }
            if (EmbeddingService::availableProviders() === []) {
                Session::flash('error', t('Aucun modèle d\'embedding disponible : une clé API OpenAI, Mistral ou Gemini doit être configurée par un administrateur.'));
                header('Location: /admin/knowledge-bases');
                exit;
            }
        }
        $this->renderKnowledgeBaseForm(null);
    }

    /** Fiche d'une base (ex-modale de modification). */
    public function editKnowledgeBase(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.view');
        $kbId = (int)$id;
        $kb   = KnowledgeBase::manageableBy($kbId, Session::currentUser())
            ? $this->kbRowForManager($kbId)
            : null;
        if ($kb === null) {
            Session::flash('error', t('Base introuvable ou accès refusé.'));
            header('Location: /admin/knowledge-bases');
            exit;
        }
        $this->renderKnowledgeBaseForm($kb);
    }

    /**
     * Ligne d'une base telle que la liste la lit — agrégats (documents,
     * extraits, taille), créateur, droits du partage reçu — pour le
     * gestionnaire courant ; null hors de son périmètre.
     */
    private function kbRowForManager(int $kbId): ?array
    {
        foreach (KnowledgeBase::forManager(Session::currentUser()) as $kb) {
            if ((int)$kb['id'] === $kbId) {
                return $kb;
            }
        }
        return null;
    }

    /**
     * Nom du porteur de la connexion des bases lues « avec la connexion d'un
     * utilisateur » (Drive en mode owner, OneDrive), par base.
     *
     * @param list<array<string, mixed>> $kbs
     * @return array<int, string>
     */
    private function kbSyncOwnerNames(array $kbs): array
    {
        $names = [];
        foreach ($kbs as $kb) {
            $ownerId = (int)($kb['sync_owner_user_id'] ?? 0);
            if ($ownerId > 0) {
                $owner = User::findById($ownerId);
                $names[(int)$kb['id']] = $owner !== null ? (string)$owner['name'] : t('utilisateur supprimé');
            }
        }
        return $names;
    }

    /**
     * Données communes de la fiche d'une base (création : $kb = null ;
     * modification : la ligne de kbRowForManager).
     */
    private function renderKnowledgeBaseForm(?array $kb): void
    {
        $currentUser    = Session::currentUser();
        $isAdminManager = Session::can('knowledge_bases.manage');
        $canSeeVector   = Session::can('diagnostics.view');
        $isCreate       = $kb === null;
        // Fiche en lecture seule sans « Bases — Modifier » (création : le droit
        // de créer suffit) ; « Supprimer cette base » selon « Bases — Supprimer ».
        $kbCanEdit      = $isCreate ? Session::can('knowledge_bases.create') : Session::can('knowledge_bases.edit');
        $kbCanDelete    = Session::can('knowledge_bases.delete');
        $kbId           = $isCreate ? 0 : (int)$kb['id'];
        $isLive         = !$isCreate && KnowledgeBase::isRemote($kb);
        $isSystem       = !$isCreate && (int)($kb['is_system'] ?? 0) === 1;
        // Propriétaire (créateur ou administrateur) : partage et suppression.
        // Un partage en écriture donne le reste de la fiche, pas cela.
        $isOwned        = $isCreate || KnowledgeBase::ownedBy($kbId, $currentUser);

        // Assistants rattachables et cibles de partage : périmètre du gestionnaire.
        $manageableProfiles = Profile::forManager($currentUser);
        $shareableUsers     = $this->shareableManagers((int)$currentUser['id']);
        $shareableGroups    = UserGroup::all();

        // Modèles d'embedding utilisables (clé configurée) + choix par défaut.
        $embeddingProviders = EmbeddingService::availableProviders();
        $embeddingDefault   = EmbeddingService::defaultChoice();
        $qdrantUp           = (new QdrantStore())->healthCheck();

        // Sources en ligne : cartes grisées tant que leurs identifiants
        // globaux ne sont pas configurés (Bases de connaissances → Sources
        // synchronisées) ; Drive « via ma connexion » et OneDrive demandent
        // le client OAuth déclaré ET la connexion de l'enregistreur.
        $syncProviders        = KbSync::configuredProviders();
        $syncInterval         = KbSync::intervalMinutes();
        $driveOauthConfigured = GoogleDriveOAuth::isConfigured();
        $driveOwnerReady      = $driveOauthConfigured
            && UserDriveService::forUser((int)$currentUser['id']) !== null;
        $oneDriveConfigured   = MicrosoftOAuth::isConfigured();
        $oneDriveOwnerReady   = $oneDriveConfigured
            && UserOneDriveService::forUser((int)$currentUser['id']) !== null;

        $docs          = $isCreate ? [] : KnowledgeBaseDocument::allForKb($kbId);
        $linkedIds     = $isCreate ? [] : array_map('intval', KnowledgeBase::profileIdsFor($kbId));
        $shares        = !$isCreate && $isOwned ? KnowledgeBase::sharesFor($kbId) : ['users' => [], 'groups' => []];
        $defaultOn     = $isLive && $isOwned ? KnowledgeBase::defaultOnFor($kbId) : ['users' => [], 'groups' => []];
        $canFeed       = $isLive && $isOwned ? KnowledgeBase::feedersFor($kbId) : ['users' => [], 'groups' => []];
        $syncOwnerName = $isCreate ? '' : ($this->kbSyncOwnerNames([$kb])[$kbId] ?? '');

        // État de la base, en tête de la fiche : même partiel que la carte de
        // la liste, rafraîchi par …/sync-status tant que quelque chose tourne.
        $kbStatus        = $isCreate ? null : KbStatus::forBases([$kb])[$kbId];
        $statusPanelHtml = $isCreate ? '' : $this->renderKbStatus($kb, $kbStatus, 'panel', true, $syncOwnerName);

        // Section « Avancé » : volume des extraits (le détail se charge à
        // l'ouverture, par …/chunks).
        $chunkTotals = !$isCreate && !$isLive ? KnowledgeBaseDocument::chunkTotals($kbId) : null;

        $docPayloads = array_map([$this, 'kbDocumentPayload'], $docs);

        // Schéma de la base, à droite de la fiche : la page le recompose à
        // chaque geste depuis ses champs ; ce que la fiche ne règle pas (état,
        // extraits, utilisations, créateur) vient de la base enregistrée.
        $diagram = [
            'catalog' => KbDiagram::catalog($manageableProfiles, $shareableUsers, $shareableGroups, $syncInterval, $qdrantUp),
            'base'    => $isCreate ? null : KbDiagram::spec(
                $kb,
                $docs,
                $linkedIds,
                KbDiagram::access($kb, $isOwned, (int)($kb['created_by'] ?? 0) === (int)$currentUser['id'], $shares, $defaultOn, $canFeed),
                $kbStatus,
                $syncOwnerName
            ),
        ];

        $success = Session::getFlash('success');
        $error   = Session::getFlash('error');
        require ROOT . '/templates/admin/knowledge-bases/edit.php';
    }

    /**
     * Détail des extraits d'une base, page par page (section « Avancé » de
     * la fiche) : le contenu tel qu'il est en base de données, et pour chaque
     * extrait affiché, s'il a son vecteur dans Qdrant — sans moteur
     * joignable, la vérification est dite impossible, jamais inventée.
     */
    public function knowledgeBaseChunks(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.view');
        $kbId      = (int)$id;
        $canManage = KnowledgeBase::manageableBy($kbId, Session::currentUser());
        // Une lecture d'affichage ne retient jamais la session.
        session_write_close();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $kb = $canManage ? KnowledgeBase::findById($kbId) : null;
        if ($kb === null) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => t('Base introuvable ou accès refusé.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (KnowledgeBase::isRemote($kb)) {
            echo json_encode(['ok' => false, 'error' => t(self::REMOTE_KB_NO_DOCUMENTS)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $docId  = (int)($_GET['doc'] ?? 0);
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $query  = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 200);
        $result = KnowledgeBaseDocument::chunksPage($kbId, $docId > 0 ? $docId : null, $query, $page);
        $pages  = max(1, (int)ceil($result['total'] / KnowledgeBaseDocument::CHUNKS_PER_PAGE));

        $present = null;
        try {
            $present = array_flip((new QdrantStore())->existingPointIds(
                KnowledgeBase::collectionName($kbId),
                array_column($result['rows'], 'id')
            ));
        } catch (\Throwable $e) {
            error_log('[KB] vecteurs de kb_' . $kbId . ' : ' . $e->getMessage());
        }

        $chunks = [];
        foreach ($result['rows'] as $row) {
            $chunks[] = $row + [
                'cut'    => $row['chars'] > KnowledgeBaseDocument::CHUNK_PREVIEW_CHARS,
                'vector' => $present === null ? null : isset($present[$row['id']]),
            ];
        }

        echo json_encode([
            'ok'              => true,
            'total'           => $result['total'],
            'page'            => min($page, $pages),
            'pages'           => $pages,
            'per_page'        => KnowledgeBaseDocument::CHUNKS_PER_PAGE,
            'vectors_checked' => $present !== null,
            'chunks'          => $chunks,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Rafraîchissement en direct de la liste des bases : l'état de chacune,
     * TOUT RENDU (zone vivante de la carte + bandeau de synthèse). La page
     * l'appelle toutes les 3 s tant qu'une base travaille, sinon toutes les
     * 45 s — c'est ce qui fait passer une carte de « en attente » à « en cours »
     * puis à « à jour » sans recharger.
     */
    public function knowledgeBasesStatus(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.view');
        $user = Session::currentUser();
        // Un rafraîchissement d'affichage ne doit jamais retenir la session :
        // un « Tester » ou un envoi lancé dans le même onglet l'attendrait.
        session_write_close();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $kbs      = KnowledgeBase::forManager($user);
        $statuses = KbStatus::forBases($kbs);
        $bases    = [];
        $busy     = false;
        foreach ($kbs as $kb) {
            $id = (int)$kb['id'];
            if (!isset($statuses[$id])) {
                continue;
            }
            $st   = $statuses[$id];
            $busy = $busy || $st['busy'];
            $bases[] = [
                'id'    => $id,
                'state' => $st['state'],
                'tone'  => $st['tone'],
                'title' => $st['title'],
                'busy'  => $st['busy'],
                'html'  => $this->renderKbStatus($kb, $st, 'card', (int)($kb['share_can_manage'] ?? 0) === 1),
            ];
        }

        echo json_encode([
            'ok'            => true,
            'busy'          => $busy,
            'poll_ms'       => $busy ? KbStatus::POLL_BUSY_MS : KbStatus::POLL_IDLE_MS,
            'bases'         => $bases,
            'overview_html' => $this->renderKbOverview($statuses),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Rendu d'un état de base (partiel partagé : carte de la liste ou panneau de la fiche). */
    private function renderKbStatus(array $kb, array $kbStatus, string $kbStatusVariant, bool $kbCanManage, string $kbOwnerName = ''): string
    {
        ob_start();
        require ROOT . '/templates/partials/kb-status.php';
        return (string)ob_get_clean();
    }

    /** Bandeau de synthèse de la liste (« 2 en cours · 1 en échec »). */
    private function renderKbOverview(array $kbStatuses): string
    {
        ob_start();
        require ROOT . '/templates/partials/kb-status-overview.php';
        return (string)ob_get_clean();
    }

    public function createKnowledgeBase(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.create');
        $currentUser = Session::currentUser();

        // Base en direct (carte « MyBrain » de l'alimentation) : un autre
        // chemin de création — pas d'embedding, pas de documents, et une
        // connexion vérifiée avant que la base n'existe.
        if ((string)($_POST['sync_provider'] ?? '') === KnowledgeBase::BACKEND_MYBRAIN) {
            $this->createMyBrainKnowledgeBase($currentUser);
        }

        // Quota de bases pour les créateurs (« Bases de connaissances — Gérer » : illimité).
        if (!Session::can('knowledge_bases.manage')) {
            if (KnowledgeBase::countByCreator((int)$currentUser['id']) >= KnowledgeBase::MAX_PER_CREATOR) {
                Session::flash('error', t('Limite atteinte : vous ne pouvez pas dépasser {n} bases de connaissances.', [
                    'n' => KnowledgeBase::MAX_PER_CREATOR,
                ]));
                header('Location: /admin/knowledge-bases');
                exit;
            }
        }

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            Session::flash('error', t('Le nom de la base est obligatoire.'));
            header('Location: /admin/knowledge-bases/new');
            exit;
        }

        $choice = $this->resolveEmbeddingChoiceFromRequest();
        if ($choice === null) {
            Session::flash('error', t('Aucun modèle d\'embedding disponible : configurez une clé API OpenAI, Mistral ou Gemini dans Gestion des LLMs.'));
            header('Location: /admin/knowledge-bases/new');
            exit;
        }

        $id = KnowledgeBase::create([
            'name'               => $name,
            'description'        => trim((string)($_POST['description'] ?? '')) ?: null,
            'embedding_provider' => $choice['provider'],
            'embedding_model'    => $choice['model'],
            'embedding_dims'     => $choice['dims'],
            // Case décochée = clé absente = inactive. Le défaut valait 1 :
            // décocher « Active » dans la modale ne changeait rien.
            'is_active'          => (int)($_POST['is_active'] ?? 0),
            'created_by'         => (int)$currentUser['id'],
        ]);

        // Collection Qdrant : best effort — si indisponible, elle sera créée
        // au premier upload/réindex (l'app fonctionne en fallback lexical).
        try {
            (new QdrantStore())->ensureCollection(KnowledgeBase::collectionName($id), $choice['dims']);
        } catch (\Throwable $e) {
            error_log('[KB] création collection kb_' . $id . ' : ' . $e->getMessage());
        }

        $this->applyKbProfileLinks($id, $_POST['profile_ids'] ?? []);
        $this->applyKbSharesFromPost($id);

        $syncError = $this->applyKbSyncConfigFromPost($id);
        if ($syncError !== null) {
            Session::flash('error', $syncError);
        }

        $docsResult = $this->storeMultipleKbDocumentsFromRequest($id, 'documents');
        $msg = t('Base de connaissances créée.');
        if ($docsResult['stored'] > 0) {
            $msg .= ' ' . I18n::plural($docsResult['stored'], '{n} document ajouté.', '{n} documents ajoutés.');
        }
        if (!empty($docsResult['errors'])) {
            Session::flash('error', implode(' ', $docsResult['errors']));
        }
        Session::flash('success', $msg);
        // La base nouvelle, dans la liste : son état (indexation, première
        // synchronisation) et son schéma.
        header('Location: /admin/knowledge-bases#base-' . $id);
        exit;
    }

    public function updateKnowledgeBase(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.edit');
        $asJson = AjaxResponse::wanted();
        $kbId   = (int)$id;
        if (!KnowledgeBase::manageableBy($kbId, Session::currentUser())) {
            AjaxResponse::abort($asJson, 'error', t('Base introuvable ou accès refusé.'), '/admin/knowledge-bases', 403);
        }

        if (KnowledgeBase::isSystem($kbId)) {
            $this->denySystemKb();
        }

        $back = '/admin/knowledge-bases/' . $kbId . '/edit';
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            AjaxResponse::abort($asJson, 'error', t('Le nom de la base est obligatoire.'), $back);
        }

        // Le modèle d'embedding est FIGÉ ici (dims = collection Qdrant) :
        // il ne change que via la réindexation complète.
        KnowledgeBase::update($kbId, [
            'name'        => $name,
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            // Cf. createKnowledgeBase : case absente = inactive.
            'is_active'   => (int)($_POST['is_active'] ?? 0),
        ]);
        $this->applyKbProfileLinks($kbId, $_POST['profile_ids'] ?? []);

        // Le partage ne suit que si l'appelant est propriétaire : un bénéficiaire
        // en écriture édite la base sans pouvoir toucher à sa liste de partage
        // (le formulaire ne la lui affiche pas, on ignore un POST forgé).
        if (KnowledgeBase::ownedBy($kbId, Session::currentUser())) {
            $this->applyKbSharesFromPost($kbId);
        }

        // Base en direct : ni synchronisation ni documents. Sa connexion ne
        // s'écrit PAS ici — l'enregistrement automatique reposte la fiche à
        // chaque frappe, une clé à moitié collée n'a rien à faire en base.
        // Elle passe par « Tester et enregistrer » (testMyBrainConnection).
        $kbRow = KnowledgeBase::findById($kbId);
        if ($kbRow !== null && KnowledgeBase::isRemote($kbRow)) {
            AjaxResponse::finish($asJson, 'success', t('Base de connaissances mise à jour.'), $back);
        }

        // La configuration de synchronisation peut échouer alors que le reste
        // de la base a bien été enregistré : on le dit sans faire passer
        // l'enregistrement pour un échec (`warning`, pastille ambre côté front).
        $syncError = $this->applyKbSyncConfigFromPost($kbId);
        if ($syncError !== null && !$asJson) {
            Session::flash('error', $syncError);
        }

        AjaxResponse::finish(
            $asJson,
            'success',
            t('Base de connaissances mise à jour.'),
            $back,
            $syncError !== null ? ['warning' => $syncError] : []
        );
    }

    public function deleteKnowledgeBase(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.delete');
        $kbId = (int)$id;
        // ownedBy et non manageableBy : un partage en écriture ne donne pas le
        // droit de supprimer la base de quelqu'un d'autre.
        if (!KnowledgeBase::ownedBy($kbId, Session::currentUser())) {
            Session::flash('error', t('Base introuvable ou accès refusé.'));
            header('Location: /admin/knowledge-bases');
            exit;
        }

        if (KnowledgeBase::isSystem($kbId)) {
            $this->denySystemKb();
        }

        // Drop de la collection Qdrant AVANT la suppression MySQL (best effort).
        // Une base en direct n'en a pas : sa connexion part avec la ligne
        // (knowledge_base_remotes, ON DELETE CASCADE) — la clé reste valide
        // côté MyBrain, c'est là-bas qu'on la supprime pour de bon.
        $kbRow = KnowledgeBase::findById($kbId);
        if ($kbRow !== null && !KnowledgeBase::isRemote($kbRow)) {
            try {
                (new QdrantStore())->dropCollection(KnowledgeBase::collectionName($kbId));
            } catch (\Throwable $e) {
                error_log('[KB] drop collection kb_' . $kbId . ' : ' . $e->getMessage());
            }
        }
        KnowledgeBase::delete($kbId);

        Session::flash('success', t('Base de connaissances supprimée.'));
        header('Location: /admin/knowledge-bases');
        exit;
    }

    /**
     * Réindexation complète : re-embedding de tous les documents depuis leurs
     * chunks MySQL (pas de re-extraction). Peut changer le modèle d'embedding
     * au passage — c'est le SEUL chemin pour en changer (fenêtre
     * « Réindexer » de la carte, champ embedding_choice).
     *
     * Un choix invalide est REFUSÉ, jamais remplacé : l'ancien code retombait
     * sur le choix par défaut (resolveEmbeddingChoiceFromRequest), donc un
     * modèle devenu indisponible entre l'affichage et le clic re-vectorisait
     * toute la base sur un modèle que personne n'avait choisi.
     */
    public function reindexKnowledgeBase(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.edit');
        $kbId = (int)$id;
        $kb   = KnowledgeBase::findById($kbId);
        if (!$kb || !KnowledgeBase::manageableBy($kbId, Session::currentUser())) {
            Session::flash('error', t('Base introuvable ou accès refusé.'));
            header('Location: /admin/knowledge-bases');
            exit;
        }
        $back = '/admin/knowledge-bases/' . $kbId . '/edit#avance';
        if (KnowledgeBase::isRemote($kb)) {
            Session::flash('error', t(self::REMOTE_KB_NO_DOCUMENTS));
            header('Location: ' . $back);
            exit;
        }

        // Changement de modèle optionnel, validé contre le catalogue ET les
        // fournisseurs dont la clé est configurée.
        $raw     = trim((string)($_POST['embedding_choice'] ?? ''));
        $current = $kb['embedding_provider'] . '::' . $kb['embedding_model'];
        $changed = null;
        if ($raw !== '' && $raw !== $current) {
            $changed = EmbeddingService::resolveChoice($raw, EmbeddingService::availableProviders());
            if ($changed === null) {
                Session::flash('error', t('Modèle d\'embedding indisponible : la base n\'a pas été réindexée. Choisissez un modèle dont le fournisseur a une clé API configurée (Modèles IA → Clés API).'));
                header('Location: ' . $back);
                exit;
            }
            KnowledgeBase::setEmbedding($kbId, $changed['provider'], $changed['model'], $changed['dims']);
        }

        $this->dispatchKbReindex($kbId);
        Session::flash('success', $changed !== null
            ? t('Réindexation lancée avec « {modele} » — suivez l\'avancement sur les documents de la base.', [
                'modele' => EmbeddingService::label($changed['provider'], $changed['model']),
            ])
            : t('Réindexation lancée — suivez l\'avancement sur les documents de la base.'));
        header('Location: ' . $back);
        exit;
    }

    public function uploadKnowledgeBaseDocument(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.edit');
        $kbId   = (int)$id;
        $kb     = KnowledgeBase::findById($kbId);
        $isAjax = $this->wantsJsonResponse();

        if (!$kb || !KnowledgeBase::manageableBy($kbId, Session::currentUser())) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => t('Base introuvable ou accès refusé.')], $isAjax);
        }

        if ((int)($kb['is_system'] ?? 0) === 1) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => t(self::SYSTEM_KB_MESSAGE)], $isAjax);
        }
        if (KnowledgeBase::isRemote($kb)) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => t(self::REMOTE_KB_NO_DOCUMENTS)], $isAjax);
        }

        if (empty($_FILES['document']) || !is_array($_FILES['document'])) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => t('Aucun fichier reçu.')], $isAjax);
        }

        $r = $this->storeKbDocumentFromUpload($kbId, $_FILES['document']);
        if (!$r['ok']) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => $r['error'] ?? t('Aucun fichier reçu.')], $isAjax);
        }

        $payload = ['ok' => true];
        if ($isAjax && !empty($r['document_id'])) {
            $doc = KnowledgeBaseDocument::findById((int)$r['document_id']);
            if ($doc) {
                $payload['document'] = $this->kbDocumentPayload($doc);
            }
        }
        $payload['kb_name'] = $kb['name'];
        $this->respondKbDocumentResult($payload, $isAjax);
    }

    public function deleteKnowledgeBaseDocument(string $id, string $docId): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.edit');
        $kbId       = (int)$id;
        $documentId = (int)$docId;
        $isAjax     = $this->wantsJsonResponse();

        if (!KnowledgeBase::manageableBy($kbId, Session::currentUser())) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => t('Base introuvable ou accès refusé.')], $isAjax);
        }

        if (KnowledgeBase::isSystem($kbId)) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => t(self::SYSTEM_KB_MESSAGE)], $isAjax);
        }

        $doc = KnowledgeBaseDocument::findById($documentId);
        if (!$doc || (int)$doc['kb_id'] !== $kbId) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => t('Document introuvable.')], $isAjax);
        }

        // Un document piloté par la synchronisation distante ACTIVE ne se
        // supprime pas à la main : il reviendrait au prochain cycle. Un doc
        // d'un ANCIEN fournisseur (après bascule de source) reste supprimable.
        $kb        = KnowledgeBase::findById($kbId);
        $docSource = (string)($doc['source'] ?? 'upload');
        if ($docSource !== 'upload'
            && (int)($kb['sync_enabled'] ?? 0) === 1
            && $docSource === (string)($kb['sync_provider'] ?? '')) {
            $this->respondKbDocumentResult(['ok' => false, 'error' => t(self::SYNCED_DOC_MESSAGE)], $isAjax);
        }

        // Purge des points Qdrant AVANT la suppression MySQL (les ids de
        // chunks disparaissent avec la ligne). Best effort.
        try {
            (new QdrantStore())->deletePointsByDocument(KnowledgeBase::collectionName($kbId), $documentId);
        } catch (\Throwable $e) {
            error_log('[KB] purge points doc ' . $documentId . ' : ' . $e->getMessage());
        }
        KnowledgeBaseDocument::delete($documentId);

        $this->respondKbDocumentResult(['ok' => true, 'document_id' => $documentId], $isAjax);
    }

    /**
     * Statut d'ingestion d'un document (polling front : extraction PUIS
     * vectorisation).
     */
    public function knowledgeBaseDocumentStatus(string $id, string $docId): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.view');
        $kbId       = (int)$id;
        $documentId = (int)$docId;

        header('Content-Type: application/json; charset=utf-8');

        if (!KnowledgeBase::manageableBy($kbId, Session::currentUser())) {
            echo json_encode(['ok' => false, 'error' => t('Accès refusé.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $doc = KnowledgeBaseDocument::findById($documentId);
        if (!$doc || (int)$doc['kb_id'] !== $kbId) {
            echo json_encode(['ok' => false, 'error' => t('Document introuvable.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'ok'                => true,
            'extraction_status' => (string)($doc['extraction_status'] ?? 'pending'),
            'extraction_error'  => $doc['extraction_error'] !== null ? (string)$doc['extraction_error'] : null,
            'vector_status'     => (string)($doc['vector_status'] ?? 'pending'),
            'vector_error'      => $doc['vector_error'] !== null ? (string)$doc['vector_error'] : null,
            'token_count'       => (int)$doc['token_count'],
            'chunk_count'       => (int)($doc['chunk_count'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Synchronisation d'un dossier distant (Drive / SharePoint) ----

    // ---- Base vectorielle (santé Qdrant) ----

    /**
     * Page « Administrer la base vectorielle ». Admin seul : elle expose
     * l'URL du service et l'état de TOUTES les bases, y compris hors du
     * périmètre d'un creator. Le rapport est chargé en AJAX (cf. template).
     */
    public function vectorDatabase(): void
    {
        AuthMiddleware::requirePermission('diagnostics.view');
        require ROOT . '/templates/admin/vector-database.php';
    }

    /**
     * Rapport de santé du moteur vectoriel (panneau admin de la page Bases de
     * connaissances). Admin seul : il expose l'URL du service et l'état de
     * TOUTES les bases, y compris hors périmètre d'un creator.
     */
    public function qdrantStatus(): void
    {
        AuthMiddleware::requirePermission('diagnostics.view');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'report' => QdrantHealth::report()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** « Réparer » : recrée les collections manquantes (aucun vecteur détruit). */
    public function repairQdrant(): void
    {
        AuthMiddleware::requirePermission('diagnostics.use');
        header('Content-Type: application/json; charset=utf-8');

        $res = QdrantHealth::repair();
        echo json_encode([
            'ok'      => $res['ok'],
            'created' => $res['created'],
            'failed'  => $res['failed'],
            'error'   => $res['error'],
            'report'  => QdrantHealth::report(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** « Purger » : supprime les collections kb_* qui n'ont plus de base. */
    public function pruneQdrant(): void
    {
        AuthMiddleware::requirePermission('diagnostics.use');
        header('Content-Type: application/json; charset=utf-8');

        $res = QdrantHealth::prune();
        echo json_encode([
            'ok'      => $res['ok'],
            'dropped' => $res['dropped'],
            'error'   => $res['error'],
            'report'  => QdrantHealth::report(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * « Réindexer » depuis le panneau de santé : même travail que le bouton de
     * la carte, mais en AJAX (le panneau se re-rend sans recharger la page).
     */
    public function reindexKnowledgeBaseAjax(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.edit');
        header('Content-Type: application/json; charset=utf-8');

        $kbId = (int)$id;
        $kb   = KnowledgeBase::findById($kbId);
        if (!$kb || !KnowledgeBase::manageableBy($kbId, Session::currentUser())) {
            echo json_encode(['ok' => false, 'error' => t('Base introuvable ou accès refusé.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (KnowledgeBase::isRemote($kb)) {
            echo json_encode(['ok' => false, 'error' => t(self::REMOTE_KB_NO_DOCUMENTS)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $this->dispatchKbReindex($kbId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Bases en direct (MyBrain) ----

    /**
     * Crée une base EN DIRECT : un cerveau MyBrain interrogé à chaque message,
     * rien de copié. Admins seuls — la clé est émise par l'administrateur de
     * MyBrain, et la brancher ouvre le wiki de l'équipe à qui recevra la base.
     * La connexion est VÉRIFIÉE avant que la base n'existe : une base qu'on ne
     * peut pas interroger ne doit pas apparaître dans les sélecteurs.
     */
    private function createMyBrainKnowledgeBase(array $currentUser): never
    {
        // Un cerveau MyBrain est une connexion d'organisation : « Bases de
        // connaissances — Gérer » (administrateur, ou rôle qui le porte).
        if (!Session::can('knowledge_bases.manage')) {
            Session::flash('error', t('Brancher un cerveau MyBrain demande le droit « Bases de connaissances — Gérer ».'));
            header('Location: /admin/knowledge-bases');
            exit;
        }
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            Session::flash('error', t('Le nom de la base est obligatoire.'));
            header('Location: /admin/knowledge-bases/new');
            exit;
        }

        $url   = MyBrainClient::normalizeBaseUrl((string)($_POST['mybrain_url'] ?? ''));
        $key   = trim((string)($_POST['mybrain_key'] ?? ''));
        $check = $this->checkMyBrainConnection($url, $key);
        if (!$check['ok']) {
            Session::flash('error', t('Base non créée — {erreur}', ['erreur' => $check['error']]));
            header('Location: /admin/knowledge-bases/new');
            exit;
        }

        $id = KnowledgeBase::create([
            'name'        => $name,
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            'backend'     => KnowledgeBase::BACKEND_MYBRAIN,
            'is_active'   => (int)($_POST['is_active'] ?? 0),
            'created_by'  => (int)$currentUser['id'],
        ]);
        KnowledgeBaseRemote::save($id, (string)$url, $key, $check['whoami']);
        $this->applyKbProfileLinks($id, $_POST['profile_ids'] ?? []);
        $this->applyKbSharesFromPost($id);

        Session::flash('success', t('Cerveau MyBrain branché : application « {application} », droits : {droits}. Choisissez les groupes qui y ont accès dans « Partage » : sans eux, seuls les administrateurs le voient.', [
            'application' => $check['whoami']['application'],
            'droits'      => implode(', ', $check['whoami']['droits']),
        ]));
        header('Location: /admin/knowledge-bases#base-' . $id);
        exit;
    }

    /**
     * Vérifie une adresse et une clé MyBrain par GET /api/v1 — le point
     * « qui suis-je » de MyBrain, en LECTURE SEULE : il ne touche au wiki
     * que pour compter l'usage de la clé. Refuse une clé sans le droit
     * « lire » : MIA ne pourrait rien interroger.
     *
     * @return array{ok: true, whoami: array{application:string, identite:string, droits:list<string>}}
     *       | array{ok: false, error: string, exception: ?MyBrainException}
     */
    private function checkMyBrainConnection(?string $url, string $key): array
    {
        if ($url === null) {
            return ['ok' => false, 'exception' => null, 'error' => t('adresse invalide : indiquez l\'adresse du MyBrain (https://…). Le http en clair n\'est accepté que sur la machine ou le réseau interne.')];
        }
        if (!MyBrainClient::isKeyShaped($key)) {
            return ['ok' => false, 'exception' => null, 'error' => t('clé invalide : une clé MyBrain commence par mbk_ (MyBrain → Administration → Clés d\'API ; elle n\'est montrée qu\'une fois).')];
        }
        try {
            $whoami = (new MyBrainClient($url, $key))->whoami();
        } catch (MyBrainException $e) {
            return ['ok' => false, 'exception' => $e, 'error' => $e->getMessage()];
        }
        if (!in_array(MyBrainClient::RIGHT_READ, $whoami['droits'], true)) {
            return ['ok' => false, 'exception' => null, 'error' => t('cette clé n\'a pas le droit « lire » : MIA ne pourrait rien interroger. Créez-en une qui l\'a dans MyBrain (Administration → Clés d\'API).')];
        }
        return ['ok' => true, 'whoami' => $whoami];
    }

    /**
     * POST /admin/knowledge-bases/mybrain/test — admins seuls, JSON.
     *
     * Sans `kb_id` (création) : teste l'adresse et la clé saisies, rien de plus.
     * Avec `kb_id` (base existante) : clé laissée vide = la clé enregistrée ; et
     * si le test passe, la connexion est ENREGISTRÉE. C'est le seul chemin qui
     * l'écrit : l'enregistrement automatique de la fiche ne la touche jamais.
     * Un échec avec une NOUVELLE clé ne change rien à la connexion en place
     * (elle marche peut-être toujours) ; un échec de la clé enregistrée, lui,
     * est consigné — l'état affiché doit dire la vérité.
     */
    public function testMyBrainConnection(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.manage');
        // Un test réseau ne doit pas retenir la session (autosave du même onglet).
        session_write_close();

        $kbId   = (int)($_POST['kb_id'] ?? 0);
        $rawUrl = trim((string)($_POST['mybrain_url'] ?? ''));
        $key    = trim((string)($_POST['mybrain_key'] ?? ''));
        $row    = null;
        if ($kbId > 0) {
            $kb = KnowledgeBase::findById($kbId);
            if ($kb === null || !KnowledgeBase::isRemote($kb)) {
                AjaxResponse::fail(t('Base en direct introuvable.'), 404);
            }
            $row = KnowledgeBaseRemote::find($kbId);
        }
        $storedKey = $key === '' && $row !== null;
        if ($storedKey) {
            $key = (string)KnowledgeBaseRemote::apiKey($row);
        }
        $url = MyBrainClient::normalizeBaseUrl($rawUrl !== '' ? $rawUrl : (string)($row['base_url'] ?? ''));

        $check = $this->checkMyBrainConnection($url, $key);
        if (!$check['ok']) {
            if ($storedKey && $check['exception'] instanceof MyBrainException) {
                KnowledgeBaseRemote::markFailure($row, $check['exception']);
            }
            AjaxResponse::fail(ucfirst($check['error']));
        }

        $saved = false;
        if ($kbId > 0) {
            KnowledgeBaseRemote::save($kbId, (string)$url, $key, $check['whoami']);
            $saved = true;
        }
        $vars = [
            'application' => $check['whoami']['application'],
            'droits'      => implode(', ', $check['whoami']['droits']),
        ];
        AjaxResponse::ok([
            'application' => $check['whoami']['application'],
            'droits'      => $check['whoami']['droits'],
            'url'         => (string)$url,
            'key_hint'    => MyBrainClient::keyHint($key),
            'saved'       => $saved,
            'message'     => $saved
                ? t('Connexion vérifiée et enregistrée : application « {application} », droits : {droits}.', $vars)
                : t('Connexion vérifiée : application « {application} », droits : {droits}.', $vars),
        ]);
    }

    /**
     * Bouton « Tester » de la fiche : vérifie qu'un lien/dossier distant est
     * accessible avec les identifiants globaux du fournisseur demandé.
     * Sans {id} : utilisable en création.
     */
    public function testKnowledgeBaseSyncFolder(): void
    {
        AuthMiddleware::requireAnyPermission('knowledge_bases.create', 'knowledge_bases.edit');
        header('Content-Type: application/json; charset=utf-8');

        $provider = (string)($_POST['provider'] ?? '');
        if (!in_array($provider, KbSync::PROVIDERS, true)) {
            echo json_encode(['ok' => false, 'error' => t('Source de synchronisation inconnue.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Mode « ma connexion » : le test utilise la connexion personnelle de
        // l'appelant (Google Drive ou OneDrive), les identifiants globaux
        // n'entrent pas en jeu. OneDrive n'a QUE ce mode.
        $ownerUserId = null;
        if (self::wantsOwnerAuth($provider)) {
            $ownerUserId = (int)(Session::currentUser()['id'] ?? 0);
        } elseif (!KbSync::providerConfigured($provider)) {
            $what = KbSync::missingCredentialsLabel($provider);
            $hint = Session::can('knowledge_bases.manage')
                ? t('{manque} — renseignez-les dans Capacités → Sources synchronisées.', ['manque' => $what])
                : t('{manque} — contactez un administrateur.', ['manque' => $what]);
            echo json_encode(['ok' => false, 'error' => $hint], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(
            KbSync::testFolder($provider, (string)($_POST['folder_url'] ?? ''), $ownerUserId),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * Le POST demande-t-il la lecture par la connexion personnelle de
     * l'appelant ? Source unique du critère (test de dossier, explorateur,
     * enregistrement) : un fournisseur qui n'a AUCUN accès global (OneDrive)
     * est toujours en mode « ma connexion », quel que soit le champ envoyé.
     */
    private static function wantsOwnerAuth(string $provider): bool
    {
        if (KbSync::requiresOwnerAuth($provider)) {
            return true;
        }
        return KbSync::supportsOwnerAuth($provider)
            && (string)($_POST['sync_auth'] ?? 'key') === 'owner';
    }

    /**
     * Explorateur de dossiers de la fiche : rend UN niveau de l'arborescence
     * du compte connecté (Google Drive ou OneDrive de l'appelant). Sert à
     * choisir le dossier source d'une base sans avoir à coller un lien.
     *
     * Toujours la connexion de l'APPELANT, jamais celle d'un autre : c'est
     * elle qui sera enregistrée comme porteuse de la synchronisation, et rien
     * ne doit laisser voir l'arborescence d'un tiers.
     */
    public function browseSyncFolder(): void
    {
        AuthMiddleware::requireAnyPermission('knowledge_bases.create', 'knowledge_bases.edit');
        header('Content-Type: application/json; charset=utf-8');

        $provider = (string)($_POST['provider'] ?? '');
        if (!KbSync::supportsOwnerAuth($provider)) {
            echo json_encode([
                'ok'    => false,
                'error' => t('Cette source ne se parcourt pas : collez le lien du dossier.'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $userId   = (int)(Session::currentUser()['id'] ?? 0);
        $resolved = KbSync::ownerClient($provider, $userId);
        $client   = $resolved['client'];
        if ($client === null) {
            echo json_encode([
                'ok'      => false,
                'error'   => t('{source} n\'est pas connecté sur votre compte.', ['source' => KbSync::providerLabel($provider)]),
                'connect' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!$client instanceof BrowsableFolderProvider) {
            echo json_encode([
                'ok'    => false,
                'error' => t('Cette source ne se parcourt pas : collez le lien du dossier.'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $level = $client->browse((string)($_POST['folder_ref'] ?? ''));
        } catch (\RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            error_log('[KB Sync] explorateur ' . $provider . ' : ' . $e->getMessage());
            echo json_encode([
                'ok'    => false,
                'error' => t('Erreur interne pendant la navigation — détail dans les logs serveur.'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Les fichiers sont rendus en clair « repris / ignoré » : l'utilisateur
        // voit ce que la base contiendra AVANT de valider le dossier, au lieu
        // de le découvrir dans le rapport du premier cycle.
        $files    = [];
        $syncable = 0;
        foreach ($level['files'] as $f) {
            $ok = KbSync::isSyncable($f);
            $syncable += $ok ? 1 : 0;
            $files[] = [
                'name'      => (string)$f['name'],
                'size'      => (int)($f['size'] ?? 0),
                'supported' => $ok,
            ];
        }

        echo json_encode([
            'ok'         => true,
            'ref'        => (string)$level['ref'],
            'name'       => (string)$level['name'],
            'parent_ref' => $level['parent_ref'],
            'selectable' => (bool)$level['selectable'],
            'folders'    => $level['folders'],
            'files'      => $files,
            'syncable'   => $syncable,
            'truncated'  => (bool)$level['truncated'],
            'empty'      => isset($level['empty']) ? (string)$level['empty'] : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Bouton « Synchroniser maintenant » : lance le cycle en tâche de fond
     * (secours synchrone sans fork — action explicite, contrairement au
     * déclencheur paresseux qui reste no-op). Le front polle sync-status.
     */
    public function runKnowledgeBaseSync(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.edit');
        $kbId = (int)$id;
        $kb   = KnowledgeBase::findById($kbId);

        header('Content-Type: application/json; charset=utf-8');
        if (!$kb || !KnowledgeBase::manageableBy($kbId, Session::currentUser())) {
            echo json_encode(['ok' => false, 'error' => t('Base introuvable ou accès refusé.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ((int)($kb['is_system'] ?? 0) === 1) {
            echo json_encode(['ok' => false, 'error' => t(self::SYSTEM_KB_MESSAGE)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ((int)($kb['sync_enabled'] ?? 0) !== 1 || (string)($kb['sync_folder_ref'] ?? '') === '') {
            echo json_encode(['ok' => false, 'error' => t('Aucun dossier distant configuré sur cette base.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $this->dispatchKbSync($kbId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * État de la synchronisation d'une base (polling front après un clic
     * « Synchroniser maintenant »). Inclut les documents : la liste se
     * re-rend en direct pendant le cycle.
     */
    public function knowledgeBaseSyncStatus(string $id): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.view');
        $kbId = (int)$id;
        $canManage = KnowledgeBase::manageableBy($kbId, Session::currentUser());
        // Rafraîchi toutes les 2,5 s par la fiche : ne pas retenir la session.
        session_write_close();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$canManage) {
            echo json_encode(['ok' => false, 'error' => t('Accès refusé.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $kb = KnowledgeBase::findById($kbId);
        if (!$kb) {
            echo json_encode(['ok' => false, 'error' => t('Base introuvable.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $report = null;
        if (!empty($kb['sync_report'])) {
            $decoded = json_decode((string)$kb['sync_report'], true);
            $report  = is_array($decoded) ? $decoded : null;
        }

        $ownerName = null;
        $ownerId   = (int)($kb['sync_owner_user_id'] ?? 0);
        if ($ownerId > 0) {
            $owner     = User::findById($ownerId);
            $ownerName = $owner !== null ? (string)$owner['name'] : t('utilisateur supprimé');
        }

        // Verdict + panneau d'état TOUT RENDU (même partiel que la carte) :
        // la fiche remplace son bloc, sans connaître un seul libellé.
        $st = KbStatus::forBases([$kb])[$kbId];

        echo json_encode([
            'ok'           => true,
            'provider'     => $kb['sync_provider'] !== null ? (string)$kb['sync_provider'] : null,
            'status'       => $kb['sync_status'] !== null ? (string)$kb['sync_status'] : null,
            'error'        => $kb['sync_error'] !== null ? (string)$kb['sync_error'] : null,
            'last_sync_at' => $kb['sync_last_at'] !== null ? (string)$kb['sync_last_at'] : null,
            'folder_name'  => $kb['sync_folder_name'] !== null ? (string)$kb['sync_folder_name'] : null,
            'owner_name'   => $ownerName,
            'report'       => $report,
            'state'        => $st['state'],
            'tone'         => $st['tone'],
            'title'        => $st['title'],
            'busy'         => $st['busy'],
            'panel_html'   => $this->renderKbStatus($kb, $st, 'panel', true, (string)($ownerName ?? '')),
            'documents'    => array_map([$this, 'kbDocumentPayload'], KnowledgeBaseDocument::allForKb($kbId)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Applique la configuration de source distante de la fiche
     * (fournisseur + URL de dossier + case d'activation). Fournisseur « fichiers
     * uploadés » ou URL vide = déconfiguration. Retourne un message d'erreur si
     * le lien est illisible (config non modifiée). Dispatch un premier cycle
     * sur activation, changement de fournisseur ou changement de dossier.
     */
    private function applyKbSyncConfigFromPost(int $kbId): ?string
    {
        $provider = trim((string)($_POST['sync_provider'] ?? ''));
        $raw      = trim((string)($_POST['sync_folder_url'] ?? ''));
        $enabled  = !empty($_POST['sync_enabled']);

        if ($provider === '' || $raw === '') {
            KnowledgeBase::setSyncConfig($kbId, null, null, false);
            return null;
        }
        if (!in_array($provider, KbSync::PROVIDERS, true)) {
            return t('Source de synchronisation inconnue — la synchronisation n\'a pas été modifiée.');
        }

        $folderRef = KbSync::parseFolderInput($provider, $raw);
        if ($folderRef === null) {
            return KbSync::invalidFolderInputMessage($provider)
                . ' ' . t('La synchronisation n\'a pas été modifiée.');
        }

        // Drive : accès par la clé API globale (dossier « par lien ») OU par
        // la connexion Google Drive de l'enregistreur (dossier partagé avec
        // lui) ; OneDrive : toujours la connexion personnelle (l'application
        // Microsoft n'a aucune permission propre). Le mode « owner » se
        // (re)lie à CHAQUE enregistrement à la
        // personne qui clique — c'est sa connexion que « Tester » vient de
        // valider, et l'état de la base affiche nommément la connexion
        // utilisée. Jamais de repli silencieux d'un mode vers l'autre.
        $ownerUserId = null;
        if (self::wantsOwnerAuth($provider)) {
            $me = Session::currentUser();
            if (KbSync::ownerClient($provider, (int)$me['id'])['client'] === null) {
                return t('{source} n\'est pas connecté sur votre compte (Réglages → Comptes connectés) — la synchronisation n\'a pas été modifiée.', [
                    'source' => KbSync::providerLabel($provider),
                ]);
            }
            $ownerUserId = (int)$me['id'];
        }

        $before       = KnowledgeBase::findById($kbId);
        $wasEnabled   = (int)($before['sync_enabled'] ?? 0) === 1;
        $sameProvider = (string)($before['sync_provider'] ?? '') === $provider;
        $sameFolder   = (string)($before['sync_folder_ref'] ?? '') === $folderRef;
        $sameOwner    = (int)($before['sync_owner_user_id'] ?? 0) === (int)$ownerUserId;

        KnowledgeBase::setSyncConfig($kbId, $provider, $folderRef, $enabled, $ownerUserId);

        // Premier cycle immédiat quand la sync (re)démarre : activation,
        // nouveau fournisseur, nouveau dossier ou nouvel accès. Le rythme de
        // croisière reste au déclencheur lazy.
        if ($enabled && (!$wasEnabled || !$sameProvider || !$sameFolder || !$sameOwner)) {
            $this->dispatchKbSync($kbId);
        }
        return null;
    }

    /**
     * Lance un cycle de sync distant. Déclenché depuis le bouton
     * « Synchroniser maintenant » : le repli synchrone reste autorisé (l'admin
     * a demandé le cycle et attend son résultat).
     */
    private function dispatchKbSync(int $kbId): void
    {
        JobQueue::enqueueMaintenance('kb_sync', $kbId, 'Synchronisation base #' . $kbId);
    }

    /** Charge utile JSON d'un document de base (upload AJAX + polling sync). */
    private function kbDocumentPayload(array $doc): array
    {
        return [
            'id'                => (int)$doc['id'],
            'file_name'         => (string)$doc['file_name'],
            'mime_type'         => (string)$doc['mime_type'],
            'file_size'         => (int)$doc['file_size'],
            'token_count'       => (int)$doc['token_count'],
            'extraction_status' => (string)($doc['extraction_status'] ?? 'pending'),
            'vector_status'     => (string)($doc['vector_status'] ?? 'pending'),
            'chunk_count'       => (int)($doc['chunk_count'] ?? 0),
            'source'            => (string)($doc['source'] ?? 'upload'),
            // Verdict calculé côté serveur (libellé, ton, motif) : la liste de
            // la fiche l'affiche tel quel, comme les cartes.
            'state'             => KbStatus::documentState($doc),
        ];
    }

    /**
     * Modèle d'embedding d'une base en CRÉATION (embedding_choice =
     * "provider::model", validé contre le catalogue + clés configurées) ;
     * vide/invalide → choix par défaut. Ne jamais s'en servir pour une
     * réindexation, qui doit refuser un choix invalide au lieu d'en
     * substituer un (cf. EmbeddingService::resolveChoice).
     * @return array{provider:string,model:string,dims:int}|null
     */
    private function resolveEmbeddingChoiceFromRequest(): ?array
    {
        return EmbeddingService::resolveChoice(
            (string)($_POST['embedding_choice'] ?? ''),
            EmbeddingService::availableProviders()
        ) ?? EmbeddingService::defaultChoice();
    }

    /**
     * Applique les liaisons base↔assistants depuis le POST, limitées au
     * périmètre du gestionnaire courant (un creator ne peut pas toucher les
     * liaisons posées par un admin sur des assistants qu'il ne voit pas).
     * @param mixed $rawProfileIds
     */
    /** Coupe court à une écriture sur la base système (réponse HTML). */
    private function denySystemKb(): never
    {
        AjaxResponse::abort(
            AjaxResponse::wanted(),
            'error',
            t(self::SYSTEM_KB_MESSAGE),
            '/admin/knowledge-bases',
            403
        );
        exit; // Inatteignable : abort() termine toujours la requête.
    }

    private function applyKbProfileLinks(int $kbId, $rawProfileIds): void
    {
        $scope = array_map(
            static fn($p) => (int)$p['id'],
            Profile::forManager(Session::currentUser())
        );
        KnowledgeBase::setProfiles(
            $kbId,
            is_array($rawProfileIds) ? $rawProfileIds : [],
            $scope
        );
    }

    /**
     * Applique les partages cochés dans la fiche (utilisateurs et groupes).
     * Deux listes par cible : les ids cochés, et parmi eux ceux à qui le droit
     * d'écriture est accordé. Réservé au propriétaire — l'appelant a la charge
     * de vérifier ownedBy().
     *
     * @param mixed $rawUserIds
     * @param mixed $rawUserManage
     * @param mixed $rawGroupIds
     * @param mixed $rawGroupManage
     */
    private function applyKbShares(
        int $kbId,
        $rawUserIds,
        $rawUserManage,
        $rawGroupIds,
        $rawGroupManage,
        $rawUserDefault = [],
        $rawGroupDefault = [],
        $rawUserFeed = [],
        $rawGroupFeed = []
    ): void {
        $ids    = static fn($raw) => is_array($raw) ? array_map('intval', $raw) : [];
        $manage = $ids($rawUserManage);
        $users  = [];
        foreach ($ids($rawUserIds) as $uid) {
            $users[$uid] = in_array($uid, $manage, true) ? 1 : 0;
        }

        $gManage = $ids($rawGroupManage);
        $groups  = [];
        foreach ($ids($rawGroupIds) as $gid) {
            $groups[$gid] = in_array($gid, $gManage, true) ? 1 : 0;
        }

        // « Cochée par défaut » et « peut nourrir » ne valent que pour une cible
        // réellement partagée : une case restée cochée sur une ligne décochée ne
        // crée pas d'accès.
        KnowledgeBase::setShares($kbId, $users, $groups, [
            'users'  => array_values(array_intersect($ids($rawUserDefault), array_keys($users))),
            'groups' => array_values(array_intersect($ids($rawGroupDefault), array_keys($groups))),
        ], [
            'users'  => array_values(array_intersect($ids($rawUserFeed), array_keys($users))),
            'groups' => array_values(array_intersect($ids($rawGroupFeed), array_keys($groups))),
        ]);
    }

    /** Les partages postés par la fiche d'une base (création et édition). */
    private function applyKbSharesFromPost(int $kbId): void
    {
        $this->applyKbShares(
            $kbId,
            $_POST['share_user_ids'] ?? [],
            $_POST['share_user_manage'] ?? [],
            $_POST['share_group_ids'] ?? [],
            $_POST['share_group_manage'] ?? [],
            $_POST['share_user_default'] ?? [],
            $_POST['share_group_default'] ?? [],
            $_POST['share_user_feed'] ?? [],
            $_POST['share_group_feed'] ?? []
        );
    }

    /**
     * Gestionnaires à qui une base peut être partagée : ceux qui disposent de
     * l'administration des assistants (un utilisateur simple n'a pas d'écran où
     * s'en servir). Le créateur courant est exclu — il a déjà tous les droits.
     */
    private function shareableManagers(int $excludeUserId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, name, email, role FROM users
             WHERE role IN ('admin', 'creator') AND id <> ?
             ORDER BY name ASC"
        );
        $stmt->execute([$excludeUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Miroir côté assistant : applique les bases cochées dans la modale
     * assistant, limitées aux bases manageables par le gestionnaire courant.
     * @param mixed $rawKbIds
     */
    private function applyProfileKbLinks(int $profileId, $rawKbIds): void
    {
        // La base système est tenue HORS périmètre : sa liaison à l'assistant
        // d'aide est posée par DefaultAssistant, elle ne suit pas les cases du
        // formulaire (une case décochée priverait l'aide de tout son savoir).
        KnowledgeBase::setForProfile(
            $profileId,
            is_array($rawKbIds) ? $rawKbIds : [],
            array_keys(AssistantDraft::kbScope(Session::currentUser()))
        );
    }

    /**
     * Stocke un fichier uploadé comme document d'une base de connaissances.
     * Mêmes validations que les documents de profil (taille, MIME, quota).
     * @param array<string, mixed> $file entrée unique de $_FILES
     * @return array{ok: bool, error?: string, document_id?: int}
     */
    private function storeKbDocumentFromUpload(int $kbId, array $file): array
    {
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false]; // input vide : silencieux
        }
        if ($errorCode !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            $msg = match($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => t('Fichier trop lourd (limite serveur : {limite}).', ['limite' => (string)ini_get('upload_max_filesize')]),
                default => t('Erreur upload ({code}).', ['code' => $errorCode]),
            };
            return ['ok' => false, 'error' => $msg];
        }

        $name = (string)($file['name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::PROFILE_DOC_MAX_SIZE) {
            return ['ok' => false, 'error' => t('Fichier « {nom} » invalide (taille max 20 Mo).', ['nom' => $name])];
        }

        // Détection MIME robuste : les DOCX sont parfois vus comme application/zip
        $mime = FileExtractor::detectMime((string)$file['tmp_name'], $name);
        $ext  = self::PROFILE_DOC_ALLOWED_MIMES[$mime] ?? null;
        if ($ext === null) {
            return ['ok' => false, 'error' => t('Format non supporté pour « {nom} ». Formats acceptés : PDF, DOCX, XLSX, TXT, MD, images.', ['nom' => $name])];
        }

        // Quota des uploads MANUELS uniquement : les documents synchronisés
        // depuis un dossier distant ont leur propre plafond (KbSync) et ne
        // doivent pas consommer celui-ci.
        if (KnowledgeBaseDocument::countBySource($kbId, 'upload') >= KnowledgeBase::MAX_DOCS_PER_KB) {
            return ['ok' => false, 'error' => t('Limite atteinte : {n} documents maximum par base.', ['n' => KnowledgeBase::MAX_DOCS_PER_KB])];
        }

        try {
            $dir = KnowledgeBase::ensureStorageDir($kbId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $storedName)) {
            return ['ok' => false, 'error' => t('Impossible d\'enregistrer le fichier « {nom} ».', ['nom' => $name])];
        }

        // Ingestion différée (extraction → chunks → embeddings → Qdrant) :
        // l'upload répond immédiatement, le front polle les deux statuts.
        $newId = KnowledgeBaseDocument::create([
            'kb_id'       => $kbId,
            'file_name'   => $name !== '' ? $name : $storedName,
            'stored_name' => $storedName,
            'mime_type'   => $mime,
            'file_size'   => $size,
        ]);

        $this->dispatchKbDocumentIngestion($newId);

        return ['ok' => true, 'document_id' => $newId];
    }

    /**
     * Traite une liste de fichiers uploadés (input name="documents[]") à la
     * création d'une base.
     * @return array{stored: int, errors: list<string>}
     */
    private function storeMultipleKbDocumentsFromRequest(int $kbId, string $field): array
    {
        $result = ['stored' => 0, 'errors' => []];
        if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
            return $result;
        }

        $all = $_FILES[$field];
        $n   = count($all['name']);
        for ($i = 0; $i < $n; $i++) {
            $file = [
                'name'     => $all['name'][$i]     ?? '',
                'type'     => $all['type'][$i]     ?? '',
                'tmp_name' => $all['tmp_name'][$i] ?? '',
                'error'    => $all['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $all['size'][$i]     ?? 0,
            ];
            $r = $this->storeKbDocumentFromUpload($kbId, $file);
            if (!empty($r['ok'])) {
                $result['stored']++;
            } elseif (!empty($r['error'])) {
                $result['errors'][] = $r['error'];
            }
        }
        return $result;
    }

    /**
     * Lance l'ingestion d'un document KB. Worker asynchrone si possible
     * (exec POSIX), sinon traitement synchrone de secours — un document
     * n'est jamais laissé bloqué en 'pending'.
     */
    private function dispatchKbDocumentIngestion(int $documentId): void
    {
        JobQueue::enqueueMaintenance('kb_document', $documentId, 'Ingestion document #' . $documentId);
    }

    /** Lance la réindexation d'une base (worker si possible, sinon synchrone). */
    private function dispatchKbReindex(int $kbId): void
    {
        JobQueue::enqueueMaintenance('kb_reindex', $kbId, 'Réindexation base #' . $kbId);
    }

    /**
     * Renvoie soit un JSON (AJAX), soit un flash + redirect vers la page KB.
     * @param array<string, mixed> $payload
     */
    private function respondKbDocumentResult(array $payload, bool $asJson): void
    {
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!empty($payload['ok'])) {
            $msg = isset($payload['kb_name'])
                ? t('Document ajouté à « {nom} ».', ['nom' => (string)$payload['kb_name']])
                : t('Document supprimé.');
            Session::flash('success', $msg);
        } else {
            Session::flash('error', (string)($payload['error'] ?? t('Erreur')));
        }
        header('Location: /admin/knowledge-bases');
        exit;
    }

    // ---- Outils de génération ----

    public function tools(): void
    {
        AuthMiddleware::requirePermission('tools.view');
        $tools     = ChatTool::all();
        $allModels = LLMFactory::models();
        // Fournisseurs disposant d'une clé API globale active (sert à griser les outils inutilisables).
        $providersWithKey = [];
        foreach (ApiKey::all() as $apiKeyRow) {
            if ((int)($apiKeyRow['is_active'] ?? 1) === 1 && !empty($apiKeyRow['provider'])) {
                $providersWithKey[(string)$apiKeyRow['provider']] = true;
            }
        }
        // Portée réelle de chaque outil (groupes actifs qui l'autorisent) :
        // « Actif » seul ne le propose qu'aux administrateurs.
        $toolGroupCounts = ChatTool::activeGroupCounts();
        $success   = Session::getFlash('success');
        $error     = Session::getFlash('error');
        require ROOT . '/templates/admin/tools.php';
    }

    /**
     * Clés Settings des outils intégrés de la page Outils : une carte = une
     * clé, plus les options d'une carte (« Proposer “Agir sans validation” »
     * des tâches programmées), chacune la sienne.
     */
    public const BUILTIN_TOOL_KEYS = [
        'mic_enabled',
        SpeechSynthesizer::SETTING_ENABLED,
        FollowUpSuggestion::SETTING_ENABLED,
        ScheduledTask::SETTING_ENABLED,
        ScheduledTask::SETTING_AUTO,
        Workflow::SETTING_ENABLED,
        Workflow::SETTING_AUTO,
    ];

    public function saveBuiltinTools(): void
    {
        AuthMiddleware::requirePermission('tools.edit');
        // Chaque carte intégrée poste SA clé seule (AJAX) : une clé absente du
        // POST n'est pas touchée — sinon basculer la conversation vocale
        // éteindrait la dictée, le piège maison « case absente = décochée ».
        // '0' est vide au sens PHP : `!empty` lit bien un `=0` comme OFF.
        $state = [];
        foreach (self::BUILTIN_TOOL_KEYS as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $enabled = !empty($_POST[$key]);
            Settings::set($key, $enabled ? '1' : '0');
            $state[$key] = $enabled ? 1 : 0;
        }
        // Plafond des boucles de workflow : une valeur de la liste, sinon rien.
        if (array_key_exists(Workflow::SETTING_LOOP_MAX, $_POST)) {
            $max = (int)$_POST[Workflow::SETTING_LOOP_MAX];
            if (!in_array($max, Workflow::LOOP_MAX_CHOICES, true)) {
                if ($this->wantsJsonResponse()) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => t('Valeur refusée.')], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } else {
                Settings::set(Workflow::SETTING_LOOP_MAX, (string)$max);
                $state[Workflow::SETTING_LOOP_MAX] = $max;
            }
        }
        if ($this->wantsJsonResponse()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true] + $state, JSON_UNESCAPED_UNICODE);
            exit;
        }
        Session::flash('success', t('Outils intégrés mis à jour.'));
        header('Location: /admin/tools');
        exit;
    }

    public function createTool(): void
    {
        AuthMiddleware::requirePermission('tools.create');
        $name     = trim($_POST['name']     ?? '');
        $type     = $_POST['type']          ?? 'image';
        $provider = $_POST['provider']      ?? '';
        $model    = trim($_POST['model']    ?? '');

        if ($type === 'video') {
            $provider = 'openai';
        }
        // n8n ne passe par aucun LLM : les colonnes provider/model (NOT NULL)
        // portent une valeur fixe, le formulaire n'a donc pas à les demander.
        if ($type === N8nService::TOOL_TYPE) {
            $provider = N8nService::TOOL_PROVIDER;
            $model    = N8nService::TOOL_MODEL;
        }
        // Même règle pour Gamma : la génération est faite par gamma.app.
        if ($type === GammaService::TOOL_TYPE) {
            $provider = GammaService::TOOL_PROVIDER;
            $model    = GammaService::TOOL_MODEL;
        }

        if (!$name || !$provider || !$model) {
            Session::flash('error', t('Nom, fournisseur et modèle sont requis.'));
            header('Location: /admin/tools');
            exit;
        }

        $configArr = [];
        if ($type === 'image') {
            $configArr = self::imageToolConfigFromPost((string)$provider, (string)$model, $_POST);
        } elseif ($type === 'html' || $type === 'html_illustrated' || $type === 'map' || $type === 'presentation') {
            // Champ vidé OU laissé au texte livré : rien n'est stocké, le défaut
            // de ToolPrompts reprend la main (et suivra ses évolutions).
            $prompt = ToolPrompts::fromPost($type, $_POST['config_system_prompt'] ?? '');
            if ($prompt !== null) $configArr['system_prompt'] = $prompt;
            if (isset($_POST['config_temperature']) && $_POST['config_temperature'] !== '')
                $configArr['temperature'] = (float)$_POST['config_temperature'];
            if ($type === 'html_illustrated') {
                if (!empty($_POST['config_image_tool_id'])) $configArr['image_tool_id'] = (int)$_POST['config_image_tool_id'];
                $configArr['max_images'] = max(1, min(6, (int)($_POST['config_max_images'] ?? 3)));
                $style = trim((string)($_POST['config_image_style'] ?? ''));
                if ($style === '__custom__') { $style = trim((string)($_POST['config_image_style_custom'] ?? '')); }
                if ($style !== '') { $configArr['image_style'] = mb_substr($style, 0, 200); }
            }
            if ($type === 'presentation') {
                // Outil image proposé par défaut dans le volet, et nombre de
                // slides pré-sélectionné. L'utilisateur reste maître des deux.
                if (!empty($_POST['config_image_tool_id'])) $configArr['image_tool_id'] = (int)$_POST['config_image_tool_id'];
                $configArr['default_slides'] = DeckSpec::slideCount($_POST['config_default_slides'] ?? DeckSpec::DEFAULT_SLIDES);
            }
        } elseif ($type === N8nService::TOOL_TYPE) {
            $configArr = N8nService::configFromPost($_POST);
        } elseif ($type === GammaService::TOOL_TYPE) {
            $configArr = GammaService::configFromPost($_POST);
        }

        ChatTool::save([
            'name'          => $name,
            'type'          => $type,
            'provider'      => $provider,
            'model'         => $model,
            'config_json'   => !empty($configArr) ? json_encode($configArr) : null,
            'api_key_plain' => $_POST['api_key'] ?? '',
            'is_active'     => (int)($_POST['is_active'] ?? 0),
            'sort_order'    => (int)($_POST['sort_order'] ?? 0),
        ]);

        // Un outil neuf n'est autorisé dans aucun groupe : sans ce rappel,
        // l'administrateur le croyait proposé à tout le monde.
        Session::flash('success', t("Outil « {nom} » créé. Pour le proposer aux utilisateurs, cochez-le dans Personnes → Groupes → Droits d'accès → Outils.", [
            'nom' => $name,
        ]));
        header('Location: /admin/tools');
        exit;
    }

    public function updateTool(string $id): void
    {
        AuthMiddleware::requirePermission('tools.edit');
        $asJson = AjaxResponse::wanted();
        $type = $_POST['type'] ?? 'image';
        $provider = $_POST['provider'] ?? '';
        if ($type === 'video') {
            $provider = 'openai';
        }
        if ($type === N8nService::TOOL_TYPE) {
            $provider = N8nService::TOOL_PROVIDER;
        }
        if ($type === GammaService::TOOL_TYPE) {
            $provider = GammaService::TOOL_PROVIDER;
        }

        $model = $type === GammaService::TOOL_TYPE ? GammaService::TOOL_MODEL : trim((string)($_POST['model'] ?? ''));

        $configArr = [];
        if ($type === 'image') {
            $configArr = self::imageToolConfigFromPost((string)$provider, $model, $_POST);
        } elseif ($type === 'html' || $type === 'html_illustrated' || $type === 'map' || $type === 'presentation') {
            // Champ vidé OU laissé au texte livré : rien n'est stocké, le défaut
            // de ToolPrompts reprend la main (et suivra ses évolutions).
            $prompt = ToolPrompts::fromPost($type, $_POST['config_system_prompt'] ?? '');
            if ($prompt !== null) $configArr['system_prompt'] = $prompt;
            if (isset($_POST['config_temperature']) && $_POST['config_temperature'] !== '')
                $configArr['temperature'] = (float)$_POST['config_temperature'];
            if ($type === 'html_illustrated') {
                if (!empty($_POST['config_image_tool_id'])) $configArr['image_tool_id'] = (int)$_POST['config_image_tool_id'];
                $configArr['max_images'] = max(1, min(6, (int)($_POST['config_max_images'] ?? 3)));
                $style = trim((string)($_POST['config_image_style'] ?? ''));
                if ($style === '__custom__') { $style = trim((string)($_POST['config_image_style_custom'] ?? '')); }
                if ($style !== '') { $configArr['image_style'] = mb_substr($style, 0, 200); }
            }
            if ($type === 'presentation') {
                // Outil image proposé par défaut dans le volet, et nombre de
                // slides pré-sélectionné. L'utilisateur reste maître des deux.
                if (!empty($_POST['config_image_tool_id'])) $configArr['image_tool_id'] = (int)$_POST['config_image_tool_id'];
                $configArr['default_slides'] = DeckSpec::slideCount($_POST['config_default_slides'] ?? DeckSpec::DEFAULT_SLIDES);
            }
        } elseif ($type === N8nService::TOOL_TYPE) {
            // L'ancienne config est relue : la valeur de l'en-tête webhook est
            // conservée quand le champ est laissé vide (elle n'est jamais
            // réaffichée), comme partout ailleurs dans l'application.
            $configArr = N8nService::configFromPost(
                $_POST,
                N8nService::decodeToolConfig(ChatTool::findById((int)$id))
            );
        } elseif ($type === GammaService::TOOL_TYPE) {
            // Aucun secret dans le config_json gamma (la clé vit dans
            // api_key_enc) : pas d'ancienne config à relire.
            $configArr = GammaService::configFromPost($_POST);
        }

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            AjaxResponse::abort($asJson, 'error', t("Le nom de l'outil est obligatoire."), '/admin/tools');
        }

        $data = [
            'id'          => (int)$id,
            'name'        => $name,
            'type'        => $type,
            'provider'    => $provider,
            'model'       => $model,
            'config_json' => !empty($configArr) ? json_encode($configArr) : null,
            'is_active'   => (int)($_POST['is_active'] ?? 0),
            'sort_order'  => (int)($_POST['sort_order'] ?? 0),
        ];
        if (!empty($_POST['api_key'])) {
            $data['api_key_plain'] = $_POST['api_key'];
        }

        ChatTool::save($data);
        AjaxResponse::finish($asJson, 'success', t('Outil mis à jour.'), '/admin/tools');
    }

    /**
     * Expressions déclencheuses d'une règle d'outil (cartes de /admin/tools,
     * même mécanique AJAX que /admin/connectors) : `rule` + `triggers` (texte
     * libre) OU `triggers_reset=1`. Renvoie la liste canonique retenue.
     */
    public function saveToolTriggers(): void
    {
        AuthMiddleware::requirePermission('tools.edit');
        header('Content-Type: application/json; charset=utf-8');
        $rule = (string)($_POST['rule'] ?? '');
        if (!isset(ToolTriggers::RULES[$rule])) {
            echo json_encode(['ok' => false, 'error' => t('Règle inconnue.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $list = !empty($_POST['triggers_reset'])
            ? ToolTriggers::resetTriggers($rule)
            : ToolTriggers::setTriggers($rule, (string)($_POST['triggers'] ?? ''));
        echo json_encode([
            'ok'       => true,
            'triggers' => $list,
            'custom'   => ToolTriggers::triggersAreCustom($rule),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Interrupteur Actif/Inactif des cartes de la liste (AJAX, même mécanique
     * que /admin/connectors : l'état affiché ne bascule qu'après le OK serveur).
     */
    public function toggleTool(string $id): void
    {
        AuthMiddleware::requirePermission('tools.edit');
        header('Content-Type: application/json; charset=utf-8');
        $tool = ChatTool::findById((int)$id);
        if (!$tool) {
            echo json_encode(['ok' => false, 'error' => t('Outil introuvable.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $active = (string)($_POST['enabled'] ?? '') === '1';
        ChatTool::setActive((int)$id, $active);
        echo json_encode(['ok' => true, 'is_active' => $active ? 1 : 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function deleteTool(string $id): void
    {
        AuthMiddleware::requirePermission('tools.delete');
        ChatTool::delete((int)$id);
        Session::flash('success', t('Outil supprimé.'));
        header('Location: /admin/tools');
        exit;
    }

    public function latestToolsTestRun(): void
    {
        AuthMiddleware::requirePermission('tools.view');
        header('Content-Type: application/json');

        $run = ToolTestRun::findLatest();
        if (!$run) {
            echo json_encode(['ok' => true, 'run' => null, 'items' => []]);
            exit;
        }

        echo json_encode([
            'ok'    => true,
            'run'   => $run,
            'items' => ToolTestRun::items((int)$run['id']),
        ]);
        exit;
    }

    public function toolsTestRunStatus(string $id): void
    {
        AuthMiddleware::requirePermission('tools.view');
        header('Content-Type: application/json');

        $run = ToolTestRun::findById((int)$id);
        if (!$run) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => t('Run de test introuvable.')]);
            exit;
        }

        echo json_encode([
            'ok'    => true,
            'run'   => $run,
            'items' => ToolTestRun::items((int)$run['id']),
        ]);
        exit;
    }

    public function startToolsTestRun(): void
    {
        $ids = [];
        if (isset($_POST['tool_ids'])) {
            $raw = is_array($_POST['tool_ids']) ? $_POST['tool_ids'] : [$_POST['tool_ids']];
            $ids = array_map('intval', $raw);
        }
        $this->startToolTestRunInternal($ids);
    }

    public function testTool(string $id): void
    {
        $this->startToolTestRunInternal([(int)$id]);
    }

    private function startToolTestRunInternal(array $toolIds): void
    {
        AuthMiddleware::requirePermission('tools.edit');
        header('Content-Type: application/json');

        $activeRun = ToolTestRun::findActive();
        if ($activeRun) {
            $updatedTs = strtotime((string)($activeRun['updated_at'] ?? ''));
            $isStale = $updatedTs !== false && (time() - $updatedTs) > 900;
            if ($isStale) {
                ToolTestRun::markFailed((int)$activeRun['id']);
                $activeRun = null;
            }
        }

        if ($activeRun) {
            echo json_encode([
                'ok'              => true,
                'run_id'          => (int)$activeRun['id'],
                'status'          => (string)$activeRun['status'],
                'already_running' => true,
            ]);
            exit;
        }

        $tools = empty($toolIds)
            ? ChatTool::all()
            : ChatTool::findByIds($toolIds);

        if (empty($tools)) {
            echo json_encode(['ok' => false, 'message' => t('Aucun outil à tester.')]);
            exit;
        }

        $currentUser = Session::currentUser();
        $runId = ToolTestRun::create($tools, isset($currentUser['id']) ? (int)$currentUser['id'] : null);

        echo json_encode([
            'ok'              => true,
            'run_id'          => $runId,
            'status'          => 'queued',
            'already_running' => false,
        ]);

        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_flush();
            @flush();
        }

        set_time_limit(0);
        $this->processToolTestRun($runId);
        exit;
    }

    /**
     * Imputation des tests d'outils en cours : l'administrateur qui a lancé
     * la campagne (`tool_test_runs.created_by`). Posé par processToolTestRun,
     * lu par les runXToolTest. Source `tool_test` = hors quota, mais
     * journalisée : une image ou une vidéo de test coûte autant qu'une vraie.
     *
     * @var array{user_id:?int}
     */
    private array $toolTestBillingCtx = ['user_id' => null];

    private function processToolTestRun(int $runId): void
    {
        try {
            ToolTestRun::markRunning($runId);
            $run = ToolTestRun::findById($runId);
            $this->toolTestBillingCtx = [
                'user_id' => isset($run['created_by']) && (int)$run['created_by'] > 0 ? (int)$run['created_by'] : null,
            ];

            while ($item = ToolTestRun::nextPendingItem($runId)) {
                $start = microtime(true);
                $toolId = (int)($item['tool_id'] ?? 0);
                $tool = $toolId > 0 ? ChatTool::findById($toolId) : null;

                if (!$tool) {
                    $ok = false;
                    $message = t('Outil introuvable (supprimé pendant le test).');
                } else {
                    try {
                        $result  = $this->executeToolTest($tool);
                        $ok      = !empty($result['ok']);
                        $message = (string)($result['message'] ?? ($ok ? t('Test réussi.') : t('Test échoué.')));
                    } catch (\Throwable $e) {
                        $ok = false;
                        $message = $e->getMessage();
                    }
                }

                $durationMs = (int)((microtime(true) - $start) * 1000);
                ToolTestRun::finishItem((int)$item['id'], $ok ? 'ok' : 'failed', $message, $durationMs);
                if ($tool) {
                    ChatTool::setTestResult((int)$tool['id'], $ok, $message);
                }
                ToolTestRun::refreshCounters($runId);
            }

            ToolTestRun::refreshCounters($runId);
        } catch (\Throwable $e) {
            ToolTestRun::markFailed($runId);
            error_log('[ToolTestRun] Erreur run #' . $runId . ': ' . $e->getMessage());
        }
    }

    private function executeToolTest(array $tool): array
    {
        // Modèle arrêté par son fournisseur (catalogue, `retirement`) : rien à
        // tester ni à facturer, et un remède pour l'administrateur — le refus
        // du générateur, lui, s'adresse à l'utilisateur du chat.
        $arret = ChatTool::retirement($tool);
        if ($arret !== null && $arret['retired']) {
            return [
                'ok'      => false,
                'message' => t('Modèle arrêté par son fournisseur le {date} : choisissez un autre modèle dans « Modifier », ou désactivez l\'outil.', [
                    'date' => Format::dateFr($arret['date']),
                ]),
            ];
        }

        return match ($tool['type']) {
            'image' => $this->runImageToolTest($tool),
            'html', 'html_illustrated' => $this->runHtmlToolTest($tool),
            'map' => $this->runMapToolTest($tool),
            'presentation' => $this->runPresentationToolTest($tool),
            'n8n' => $this->runN8nToolTest($tool),
            'gamma' => $this->runGammaToolTest($tool),
            'video' => $this->runVideoToolTest($tool),
            default => ['ok' => false, 'message' => t('Type d\'outil non supporté.')],
        };
    }

    private function runImageToolTest(array $tool): array
    {
        $result = ImageGenerator::generate($tool, 'Image de test technique: un simple carré bleu sur fond blanc.');

        if (isset($result['error'])) {
            return ['ok' => false, 'message' => (string)$result['error']];
        }
        $imageConfig = json_decode($tool['config_json'] ?? '{}', true) ?: [];
        UsageMeter::tool($tool, [
            'model'   => (string)($tool['model'] ?? ''),
            'size'    => (string)($result['size']    ?? $imageConfig['size']    ?? ''),
            'quality' => (string)($result['quality'] ?? $imageConfig['quality'] ?? ''),
            'count'   => 1,
        ], $this->toolTestBillingCtx, UsageSource::TOOL_TEST);

        $this->cleanupGeneratedAsset((string)($result['image_url'] ?? ''), ROOT . '/public/assets/img/generated');

        $provider = trim((string)($tool['provider'] ?? ''));
        $model = trim((string)($tool['model'] ?? ''));
        $suffix = ($provider !== '' && $model !== '') ? " ({$provider}::{$model})" : '';
        return ['ok' => true, 'message' => t('Image générée avec succès{suffixe}.', ['suffixe' => $suffix])];
    }

    private function runHtmlToolTest(array $tool): array
    {
        $config = json_decode($tool['config_json'] ?? '{}', true) ?: [];
        // Même prompt qu'en production : une 3e copie du défaut ferait tester
        // autre chose que ce que l'utilisateur obtiendra.
        $systemPrompt = ToolPrompts::resolve((string)$tool['type'], $config);
        $temperature = isset($config['temperature']) ? (float)$config['temperature'] : null;

        try {
            $adapter = LLMFactory::make($tool['provider'] . '::' . $tool['model']);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => t('LLM non disponible : {fournisseur}', ['fournisseur' => (string)$tool['provider']])];
        }

        $response = '';
        $tokens = $adapter->stream(
            [['role' => 'user', 'content' => 'Génère une page HTML minimale affichant "Test outil OK".']],
            $systemPrompt,
            function (string $chunk) use (&$response): void {
                if (strlen($response) < 3000) {
                    $response .= $chunk;
                }
            },
            $temperature
        );
        $this->billToolTestLlm($tool, $tokens);

        $trimmed = trim($response);
        if ($trimmed === '') {
            return ['ok' => false, 'message' => t('Aucune réponse reçue du modèle.')];
        }
        if (str_starts_with($trimmed, '[Erreur')) {
            // Cause nommée, puis message d'origine : cf. LlmErrorPresenter::adminLine.
            return ['ok' => false, 'message' => LlmErrorPresenter::adminLine($trimmed)];
        }

        return ['ok' => true, 'message' => t('Réponse HTML reçue.')];
    }

    /**
     * Test de l'outil présentation : on demande un plan minimal de 2 slides et
     * on vérifie qu'il revient en JSON exploitable. C'est exactement ce dont
     * dépend le volet — un modèle incapable de tenir le contrat JSON échoue ici
     * plutôt que devant l'utilisateur.
     */
    /**
     * Test d'un outil n8n : connexion à l'API publique, comptage des workflows
     * réellement exposés, puis cohérence de leur protection. Aucun workflow
     * n'est DÉCLENCHÉ — les exécuter pour tester produirait leurs effets réels.
     */
    private function runN8nToolTest(array $tool): array
    {
        $config  = N8nService::decodeToolConfig($tool);
        $baseUrl = (string)($config['base_url'] ?? '');
        $tag     = (string)($config['tag'] ?? '');
        $apiKey  = (string)(ChatTool::getDecryptedKey((int)$tool['id']) ?? '');

        $connexion = N8nService::testConnection($baseUrl, $apiKey, $tag);
        if (empty($connexion['ok'])) {
            return ['ok' => false, 'message' => (string)($connexion['error'] ?? t('Connexion impossible.'))];
        }

        $protection = N8nService::testWebhookProtection(
            $baseUrl,
            $apiKey,
            $tag,
            (string)($config['webhook_header_name'] ?? ''),
            !empty($config['webhook_secret_enc'])
        );
        $message = (string)($connexion['detail'] ?? '');
        if (empty($protection['ok'])) {
            // La connexion marche, mais des exécutions échoueront à coup sûr :
            // c'est un échec de test, pas une remarque de bas de page.
            return ['ok' => false, 'message' => $message . ' ⚠ ' . (string)($protection['error'] ?? '')];
        }
        $detail = trim((string)($protection['detail'] ?? ''));
        return ['ok' => true, 'message' => trim($message . ($detail !== '' ? ' ' . $detail : ''))];
    }

    /**
     * Test d'un outil Gamma : `GET /themes` valide la clé et l'accès API sans
     * consommer AUCUN crédit, et signale un thème configuré introuvable.
     * Jamais de génération dans un test — chaque génération est facturée.
     */
    private function runGammaToolTest(array $tool): array
    {
        $config = GammaService::decodeToolConfig($tool);
        $apiKey = (string)(ChatTool::getDecryptedKey((int)$tool['id']) ?? '');

        $result = GammaService::testConnection($apiKey, (string)($config['theme_id'] ?? ''));
        return [
            'ok'      => !empty($result['ok']),
            'message' => (string)($result['detail'] ?? $result['error'] ?? t('Connexion impossible.')),
        ];
    }

    private function runPresentationToolTest(array $tool): array
    {
        $config      = json_decode($tool['config_json'] ?? '{}', true) ?: [];
        $temperature = isset($config['temperature']) ? (float)$config['temperature'] : 0.3;
        $options     = DeckSpec::normalizeOptions(['slide_count' => 2, 'image_style' => '']);

        try {
            $adapter = LLMFactory::make($tool['provider'] . '::' . $tool['model']);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => t('LLM non disponible : {fournisseur}', ['fournisseur' => (string)$tool['provider']])];
        }

        $response = '';
        $tokens = $adapter->stream(
            [['role' => 'user', 'content' => DeckGenerator::outlineUserPrompt(
                'Test technique : les bienfaits de la marche à pied.',
                '',
                $options
            )]],
            DeckGenerator::outlineSystemPrompt($options, ToolPrompts::resolve('presentation', $config)),
            function (string $chunk) use (&$response): void {
                if (strlen($response) < 8000) {
                    $response .= $chunk;
                }
            },
            $temperature
        );
        $this->billToolTestLlm($tool, $tokens);

        $trimmed = trim($response);
        if ($trimmed === '') {
            return ['ok' => false, 'message' => t('Aucune réponse reçue du modèle.')];
        }
        if (str_starts_with($trimmed, '[Erreur') || str_starts_with($trimmed, '[Clé')) {
            return ['ok' => false, 'message' => LlmErrorPresenter::adminLine($trimmed)];
        }

        $decoded = DeckGenerator::decodeJson($trimmed);
        if ($decoded === null || !is_array($decoded['slides'] ?? null)) {
            return ['ok' => false, 'message' => t("Le modèle n'a pas renvoyé un plan JSON exploitable.")];
        }

        $count = count(DeckSpec::normalizeOutline($decoded['slides']));
        if ($count === 0) {
            return ['ok' => false, 'message' => t('Plan reçu mais vide.')];
        }

        return ['ok' => true, 'message' => I18n::plural($count, 'Plan JSON reçu ({n} slide).', 'Plan JSON reçu ({n} slides).')];
    }

    private function runMapToolTest(array $tool): array
    {
        $systemPrompt = "Tu génères des itinéraires. Réponds UNIQUEMENT avec un objet JSON valide (aucun texte autour, pas de ```) au format {\"title\":\"…\",\"summary\":\"…\",\"profile\":\"driving\",\"waypoints\":[{\"name\":\"…\",\"query\":\"ville, pays\",\"lat\":null,\"lon\":null,\"note\":\"…\"}],\"notes\":[]}. Minimum 2 waypoints.";

        try {
            $adapter = LLMFactory::make($tool['provider'] . '::' . $tool['model']);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => t('LLM non disponible : {fournisseur}', ['fournisseur' => (string)$tool['provider']])];
        }

        $response = '';
        $tokens = $adapter->stream(
            [['role' => 'user', 'content' => 'Trajet simple de Paris à Lyon en voiture, sans étape intermédiaire.']],
            $systemPrompt,
            function (string $chunk) use (&$response): void {
                if (strlen($response) < 6000) {
                    $response .= $chunk;
                }
            },
            0.0
        );
        $this->billToolTestLlm($tool, $tokens);

        $trimmed = trim($response);
        if ($trimmed === '') {
            return ['ok' => false, 'message' => t('Aucune réponse reçue du modèle.')];
        }
        if (str_starts_with($trimmed, '[Erreur') || str_starts_with($trimmed, '[Clé')) {
            return ['ok' => false, 'message' => LlmErrorPresenter::adminLine($trimmed)];
        }

        // Validation : JSON parsable avec au moins 2 waypoints
        $txt   = preg_replace('/^```[a-z]*\s*|\s*```\s*$/i', '', $trimmed) ?? $trimmed;
        $start = strpos($txt, '{');
        $end   = strrpos($txt, '}');
        $data  = ($start !== false && $end !== false && $end > $start)
            ? json_decode(substr($txt, $start, $end - $start + 1), true)
            : null;
        if (!is_array($data) || !is_array($data['waypoints'] ?? null) || count($data['waypoints']) < 2) {
            return ['ok' => false, 'message' => t('Le modèle n\'a pas renvoyé un itinéraire JSON exploitable.')];
        }

        return ['ok' => true, 'message' => t('Itinéraire JSON valide reçu ({n} étapes).', ['n' => count($data['waypoints'])])];
    }

    private function runVideoToolTest(array $tool): array
    {
        // Test 1 : génération sans image
        $result = VideoGenerator::generate($tool, 'Vidéo de test technique très simple: fond noir fixe.');

        if (isset($result['error'])) {
            return ['ok' => false, 'message' => (string)$result['error']];
        }
        $this->billToolTestVideo($tool, $result);

        $this->cleanupGeneratedAsset((string)($result['video_url'] ?? ''), ROOT . '/public/assets/video/generated');

        // Test 2 : génération avec image de début (sora-2 uniquement)
        $model = trim((string)($tool['model'] ?? ''));
        if ($model === 'sora-2') {
            $imageResult = $this->runVideoWithImageToolTest($tool);
            if (!$imageResult['ok']) {
                return ['ok' => false, 'message' => t('Vidéo OK. Échec du test avec image : {erreur}', ['erreur' => (string)$imageResult['message']])];
            }
            return ['ok' => true, 'message' => t('Vidéo générée avec succès (sans image et avec image de début).')];
        }

        return ['ok' => true, 'message' => t('Vidéo générée avec succès.')];
    }

    private function runVideoWithImageToolTest(array $tool): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            return ['ok' => false, 'message' => t('Extension GD non disponible pour créer l\'image de test.')];
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'sora_img_test_') . '.jpg';

        $img = imagecreatetruecolor(64, 64);
        $color = imagecolorallocate($img, 220, 50, 50);
        imagefill($img, 0, 0, $color);
        imagejpeg($img, $tmpPath, 85);

        if (!is_readable($tmpPath)) {
            return ['ok' => false, 'message' => t('Impossible de créer l\'image de test temporaire.')];
        }

        $imageData = [
            'path' => $tmpPath,
            'type' => 'image/jpeg',
            'role' => 'first_frame',
        ];

        $result = VideoGenerator::generate($tool, 'Vidéo de test avec image de début.', [], $imageData);

        @unlink($tmpPath);

        if (isset($result['error'])) {
            return ['ok' => false, 'message' => (string)$result['error']];
        }
        $this->billToolTestVideo($tool, $result);

        $this->cleanupGeneratedAsset((string)($result['video_url'] ?? ''), ROOT . '/public/assets/video/generated');

        return ['ok' => true, 'message' => t('Vidéo avec image de début générée avec succès.')];
    }

    /** Tokens d'un test d'outil à LLM (HTML, carte, présentation) → grand livre, hors quota. */
    private function billToolTestLlm(array $tool, mixed $tokens): void
    {
        $llmKey = (string)($tool['provider'] ?? '') . '::' . (string)($tool['model'] ?? '');
        UsageMeter::llm($llmKey, is_array($tokens) ? $tokens : [], UsageSource::TOOL_TEST, $this->toolTestBillingCtx);
    }

    /** Vidéo produite par un test d'outil → grand livre, sur les paramètres réels du rendu. */
    private function billToolTestVideo(array $tool, array $result): void
    {
        UsageMeter::tool($tool, [
            'model'   => (string)($result['model'] ?? $tool['model'] ?? ''),
            'seconds' => isset($result['seconds']) ? (float)$result['seconds'] : null,
            'size'    => (string)($result['size'] ?? ''),
            'format'  => (string)($result['format'] ?? ''),
            'count'   => 1,
        ], $this->toolTestBillingCtx, UsageSource::TOOL_TEST);
    }

    /**
     * Nettoie l'asset produit par un test d'outil (image/vidéo jetable).
     * La garde de confinement vit dans MediaFile — SOURCE UNIQUE partagée avec
     * l'élagage : deux exemplaires finiraient par diverger, et une garde
     * anti-traversée qui diverge, c'est un `unlink` hors périmètre.
     */
    private function cleanupGeneratedAsset(string $assetUrl, string $baseDirectory): void
    {
        MediaFile::supprimerAsset($assetUrl, $baseDirectory);
    }

    /** Libellés d'affichage des fournisseurs cloud (pages « Modèles IA »). */
    private function llmProviderNames(): array
    {
        return [
            'openai'     => 'OpenAI',
            'anthropic'  => 'Anthropic',
            'mistral'    => 'Mistral',
            'perplexity' => 'Perplexity',
            'gemini'     => 'Gemini (Google)',
            'deepseek'   => 'DeepSeek',
            'moonshot'   => 'Kimi (Moonshot)',
            'zai'        => 'GLM (Z.ai)',
        ];
    }

    /** Modèles IA — Clés API (fournisseurs cloud + endpoint local). */
    public function llmsKeys(): void
    {
        AuthMiddleware::requirePermission('llms.view');
        $keys          = ApiKey::all();
        $catalog       = LLMFactory::models();
        $logos         = LLMFactory::logos();
        $providerNames = $this->llmProviderNames();
        $success       = Session::getFlash('success');
        require ROOT . '/templates/admin/llms/keys.php';
    }

    /**
     * Modèles IA — Modèles : comportement par défaut (LLM par défaut, secours)
     * en tête, puis le catalogue (catalogue → instances). Le vocabulaire de
     * l'entreprise vit sur Organisation → Entreprise.
     * L'ancienne page « Comportement par défaut » y est fusionnée : le défaut se
     * choisit parmi les modèles activés, juste en dessous.
     */
    public function llmsModels(): void
    {
        AuthMiddleware::requirePermission('llms.view');
        $keys          = ApiKey::all();
        $llmInstances  = LlmModel::all();
        $catalog       = LLMFactory::models();
        $logos         = LLMFactory::logos();
        $providerNames = $this->llmProviderNames();
        $llms          = LLMFactory::available();
        // Sélecteur ET badge « Défaut » : le défaut EFFECTIF (celui que MIA
        // utilise), pas le réglage brut, qui peut désigner un modèle retiré.
        // Quand le réglage enregistré ne désigne aucun modèle disponible
        // (instance neuve : « openai »), la carte le dit et propose de confirmer.
        $defaultLlm       = LLMFactory::appDefault($llms) ?? '';
        $defaultLlmStored = trim((string) Settings::get('default_llm', ''));
        $fallbackLlm      = trim((string) Settings::get('default_llm_fallback', ''));
        $success          = Session::getFlash('success');
        $error            = Session::getFlash('error');
        require ROOT . '/templates/admin/llms/models.php';
    }

    /** Modèles IA — Tests de bon fonctionnement. */
    public function llmsTests(): void
    {
        AuthMiddleware::requirePermission('llms.view');
        $activeInstances = LlmModel::active();
        $catalog         = LLMFactory::models();
        $logos           = LLMFactory::logos();
        $defaultLlm      = LLMFactory::appDefault() ?? '';
        $fallbackLlm     = trim((string) Settings::get('default_llm_fallback', ''));
        $success         = Session::getFlash('success');
        require ROOT . '/templates/admin/llms/tests.php';
    }

    public function saveLlmDefaults(): void
    {
        AuthMiddleware::requirePermission('llms.edit');
        $available = LLMFactory::available();
        $default   = trim((string) ($_POST['default_llm'] ?? ''));
        $fallback  = trim((string) ($_POST['default_llm_fallback'] ?? ''));

        if ($default !== '' && isset($available[$default])) {
            Settings::set('default_llm', $default);
        }
        // '' = pas de secours ; un secours identique au défaut est inerte → normalisé à ''.
        // Écriture conditionnée à la PRÉSENCE du champ : sans instance LLM
        // active, la page n'affiche pas les sélecteurs, et un POST qui ne les
        // porte pas effacerait sinon le secours configuré.
        if (array_key_exists('default_llm_fallback', $_POST)) {
            Settings::set(
                'default_llm_fallback',
                ($fallback !== '' && $fallback !== $default && isset($available[$fallback])) ? $fallback : ''
            );
        }

        // Le vocabulaire de l'entreprise a quitté cet écran pour
        // Organisation → Entreprise (saveCompany) : rien ici ne l'écrit.

        AjaxResponse::finish(
            AjaxResponse::wanted(),
            'success',
            t('Comportement par défaut enregistré.'),
            '/admin/llms/models#defaut'
        );
    }

    public function saveLlmModel(): void
    {
        // Activer un modèle = l'ajouter à ceux que l'instance propose.
        AuthMiddleware::requirePermission('llms.create');
        $provider    = $_POST['provider']     ?? '';
        $model       = trim($_POST['model']   ?? '');
        $displayName = trim($_POST['display_name'] ?? '');

        // Un modèle arrêté par son éditeur ne s'active plus : il quitterait
        // aussitôt le chat (LLMFactory::available) — l'écran ne propose plus
        // le bouton, ce refus couvre une page restée ouverte.
        $arret = ($provider && $model) ? LlmCatalog::retirement((string)$provider, $model) : null;
        if ($arret !== null && $arret['retired']) {
            Session::flash('error', t('{modele} a été arrêté par son éditeur le {date} : il ne peut plus être activé.', [
                'modele' => $displayName,
                'date'   => Format::dateFr($arret['date']),
            ]) . ($arret['replacement_label'] !== ''
                ? ' ' . t('Remplaçant désigné : {modele}.', ['modele' => $arret['replacement_label']])
                : ''));
            header('Location: /admin/llms/models');
            exit;
        }

        if ($provider && $model && $displayName) {
            LlmModel::save([
                'provider'     => $provider,
                'model'        => $model,
                'display_name' => $displayName,
                'is_active'    => 1,
                'sort_order'   => (int)($_POST['sort_order'] ?? 0),
            ]);
            // Premier modèle activé d'une instance neuve : le réglage semé
            // (« openai ») ne désigne plus rien — il est remplacé par le
            // défaut effectif, visible et modifiable dans Modèles IA → Modèles (carte « Comportement par défaut »).
            $nouveauDefaut = LLMFactory::repairStoredDefault();
            Session::flash('success', t('Instance LLM ajoutée.')
                . ($nouveauDefaut !== null
                    ? ' ' . t('Modèle par défaut : {modele}.', ['modele' => LLMFactory::available()[$nouveauDefaut] ?? $nouveauDefaut])
                    : ''));
        }
        header('Location: /admin/llms/models');
        exit;
    }

    public function deleteLlmModel(string $id): void
    {
        AuthMiddleware::requirePermission('llms.delete');
        LlmModel::delete((int)$id);
        // Le modèle retiré était peut-être le défaut : un réglage qui ne
        // désigne plus rien laisserait le groupe « ★ Par défaut » sans modèle.
        $nouveauDefaut = LLMFactory::repairStoredDefault();
        Session::flash('success', t('Instance LLM supprimée.')
            . ($nouveauDefaut !== null
                ? ' ' . t('Nouveau modèle par défaut : {modele}.', ['modele' => LLMFactory::available()[$nouveauDefaut] ?? $nouveauDefaut])
                : ''));
        header('Location: /admin/llms/models');
        exit;
    }

    public function saveApiKey(): void
    {
        AuthMiddleware::requireAnyPermission('llms.create', 'llms.edit');
        $asJson   = AjaxResponse::wanted();
        $provider = trim((string)($_POST['provider'] ?? ''));
        $key      = trim((string)($_POST['key'] ?? ''));
        $model    = trim((string)($_POST['model'] ?? '')) ?: null;

        if ($provider === '') {
            AjaxResponse::abort($asJson, 'error', t('Fournisseur manquant.'), '/admin/llms');
        }

        // Ajouter la clé d'un fournisseur = « Gestion des LLM — Créer » ; changer
        // celle qui existe (clé, modèle, URL locale) = « Modifier ».
        $needed = ApiKey::findByProvider($provider) === null ? 'llms.create' : 'llms.edit';
        if (!Session::can($needed)) {
            AjaxResponse::abort($asJson, 'error', AuthMiddleware::refusal(), '/admin/llms', 403);
        }

        // Champ clé laissé vide = clé INCHANGÉE. Le formulaire ne repropose
        // jamais le secret enregistré : sans cette reprise, changer le modèle
        // d'un fournisseur (ou l'URL du serveur local) écrasait la clé par le
        // chiffré d'une chaîne vide. C'était déjà faux avec le bouton
        // « Enregistrer » ; à la frappe, ce serait systématique.
        if ($key === '') {
            $existing = ApiKey::findByProvider($provider);
            $key      = $existing ? Encryption::decrypt((string)$existing['key_enc']) : '';
        }

        if ($provider === 'local') {
            $baseUrl     = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
            $displayName = trim((string)($_POST['display_name'] ?? '')) ?: 'LLM Local';
            if ($baseUrl === '') {
                AjaxResponse::abort($asJson, 'error', t("L'URL du serveur local est obligatoire."), '/admin/llms');
            }
            // Un endpoint local peut légitimement n'exiger aucune clé.
            ApiKey::save('local', $key, $model, $baseUrl, $displayName);
            // Case « sait appeler des outils » : présente dans CE formulaire
            // seulement, et l'auto-save le reposte en entier — son absence vaut
            // donc bien « décochée ».
            Settings::set(LocalAdapter::SETTING_TOOL_USE, !empty($_POST['tool_use']) ? '1' : '0');
        } else {
            if ($key === '') {
                AjaxResponse::abort($asJson, 'error', t('Renseignez une clé API pour ce fournisseur.'), '/admin/llms');
            }
            ApiKey::save($provider, $key, $model);
        }

        // Une clé change la liste des LLM disponibles (repli sur les
        // fournisseurs à clé tant qu'aucun modèle n'est activé).
        LLMFactory::repairStoredDefault();
        // Clé remplacée ou compte rechargé puis réenregistré : le constat
        // « crédit épuisé » / « clé refusée » s'éteint. S'il est toujours
        // vrai, la prochaine demande refusée le reposera.
        ProviderIncident::resolve($provider);

        AjaxResponse::finish($asJson, 'success', t('Configuration enregistrée.'), '/admin/llms');
    }

    public function deleteApiKey(string $id): void
    {
        AuthMiddleware::requirePermission('llms.delete');
        ApiKey::delete((int)$id);
        LLMFactory::repairStoredDefault();
        Session::flash('success', t('Clé API supprimée.'));
        header('Location: /admin/llms');
        exit;
    }

    public function testApiKey(string $provider): void
    {
        AuthMiddleware::requirePermission('llms.use');
        header('Content-Type: application/json');

        if ($provider === 'local') {
            try {
                $result = $this->testLocal();
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage()];
            }
            echo json_encode($result);
            exit;
        }

        $key = ApiKey::getDecrypted($provider);
        if (!$key) {
            echo json_encode(['ok' => false, 'message' => t('Aucune clé enregistrée pour ce fournisseur.')]);
            exit;
        }

        try {
            $result = match($provider) {
                'openai'     => $this->testOpenAI($key),
                'mistral'    => $this->testMistral($key),
                'anthropic'  => $this->testAnthropic($key),
                'perplexity' => $this->testOpenAICompatMultiModel($key, 'https://api.perplexity.ai/chat/completions', 'perplexity', 'sonar', 'Perplexity'),
                // Repli quand aucun modèle n'est activé : un modèle GA, bon
                // marché, et OUVERT — Google réserve la génération 2.5 aux
                // comptes qui l'utilisaient déjà (18/09/2026), DeepSeek a retiré
                // ses anciens modèles le 24/07/2026 (seul reste celui du catalogue).
                'gemini'     => $this->testOpenAICompatMultiModel($key, 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'gemini', 'gemini-3.5-flash-lite', 'Gemini'),
                'deepseek'   => $this->testOpenAICompatMultiModel($key, 'https://api.deepseek.com/chat/completions', 'deepseek', LlmCatalog::defaultModel('deepseek'), 'DeepSeek'),
                'moonshot'   => $this->testOpenAICompatMultiModel($key, 'https://api.moonshot.ai/v1/chat/completions', 'moonshot', 'kimi-k3', 'Kimi'),
                'zai'        => $this->testOpenAICompatMultiModel($key, 'https://api.z.ai/api/paas/v4/chat/completions', 'zai', 'glm-5.2', 'GLM'),
                default      => ['ok' => false, 'message' => t('Fournisseur inconnu.')],
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        // Le compte répond de nouveau (crédit rechargé, clé renouvelée).
        if (!empty($result['ok'])) {
            ProviderIncident::resolve($provider);
        }

        echo json_encode($result);
        exit;
    }

    /**
     * Liste les noms de modèles à tester pour un provider donné.
     * Préférence : toutes les instances actives dans `llm_models` (= ce que les
     * utilisateurs voient et envoient via le sélecteur). Fallback : modèle
     * configuré dans `api_keys`. En dernier recours : `$defaultFallback`.
     *
     * Important : `api_keys.model` n'est plus envoyé par le chat depuis le
     * passage au catalogue `provider::model`. Tester les instances réelles
     * évite les surprises type "test OK mais chat 400" quand l'admin a saisi
     * un nom de modèle inexistant.
     */
    /**
     * Taille, qualité et format d'un outil image, retenus du POST — un champ
     * hors des valeurs déclarées pour CE modèle est IGNORÉ, jamais enregistré.
     *
     * Ce filtre est nécessaire : mesuré contre l'API le 2026-09-16,
     * /v1/images/generations valide `quality` sur un schéma commun à tous les
     * modèles (`xhigh` y passe même avec gpt-image-2, qui ne le rend pas), donc
     * une valeur incohérente n'échouerait nulle part — elle s'appliquerait en
     * silence, ou pas du tout. Les tailles libres LARGEURxHAUTEUR restent
     * acceptées : les modèles GPT Image en prennent bien au-delà des tailles
     * recommandées que l'écran propose.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private static function imageToolConfigFromPost(string $provider, string $model, array $post): array
    {
        $config = [];
        $retenir = function (string $champ, string $clef, array $valides, bool $tailleLibre = false)
            use ($post, &$config): void {
            $valeur = trim((string)($post[$champ] ?? ''));
            if ($valeur === '') {
                return;
            }
            if (in_array($valeur, $valides, true)
                || ($tailleLibre && preg_match('/^\d{2,4}x\d{2,4}$/', $valeur) === 1)) {
                $config[$clef] = $valeur;
            }
        };

        $retenir('config_size', 'size', ToolCatalog::modelOptions('image', $provider, $model, 'sizes'), true);
        $retenir('config_quality', 'quality', ToolCatalog::modelOptions('image', $provider, $model, 'qualities'));
        $retenir('config_aspect_ratio', 'aspect_ratio', ToolCatalog::options('image', $provider, 'aspect_ratios'));

        return $config;
    }

    private function modelsToTest(string $provider, string $defaultFallback): array
    {
        $models = [];
        foreach (LlmModel::activeByProvider($provider) as $row) {
            $m = trim((string)($row['model'] ?? ''));
            // Un modèle arrêté par son éditeur échouerait et ferait passer la
            // CLÉ pour invalide ; il est signalé ailleurs (points à régler).
            if ($m !== '' && !in_array($m, $models, true) && !LlmCatalog::isRetired($provider, $m)) {
                $models[] = $m;
            }
        }
        if (empty($models)) {
            $fromApiKeys = trim((string)(ApiKey::getModel($provider) ?? ''));
            if ($fromApiKeys !== '') {
                $models[] = $fromApiKeys;
            }
        }
        if (empty($models)) {
            $models[] = $defaultFallback;
        }
        return $models;
    }

    /**
     * Combine plusieurs résultats de test (`[model => result]`) en un seul.
     * `ok=true` ssi tous les modèles passent. Le message liste chaque modèle
     * avec son verdict pour aider l'admin à identifier les entrées invalides.
     */
    private function aggregateModelResults(array $results): array
    {
        $allOk  = true;
        $lines  = [];
        foreach ($results as $model => $r) {
            $ok = (bool)($r['ok'] ?? false);
            if (!$ok) $allOk = false;
            $icon = $ok ? '✓' : '✗';
            $msg  = (string)($r['message'] ?? ($ok ? 'OK' : t('échec')));
            // Strip éventuel "[model] " déjà préfixé par le testeur.
            $msg  = preg_replace('/^\[[^\]]+\]\s*/', '', $msg) ?? $msg;
            $lines[] = $icon . ' [' . $model . '] ' . $msg;
        }
        return [
            'ok'      => $allOk,
            'message' => implode("\n", $lines),
        ];
    }

    private function testOpenAI(string $key): array
    {
        $models  = $this->modelsToTest('openai', LlmCatalog::defaultModel('openai') ?: 'gpt-5.6');
        $results = [];
        foreach ($models as $model) {
            $r = self::testsViaResponsesApi($model)
                ? $this->testOpenAIBasicViaResponses($key, $model)
                : $this->testOpenAICompat($key, 'https://api.openai.com/v1/chat/completions', $model, 'OpenAI');
            $results[$model] = $r;
        }
        return $this->aggregateModelResults($results);
    }

    private function testMistral(string $key): array
    {
        $models  = $this->modelsToTest('mistral', LLMFactory::defaultModel('mistral') ?: 'mistral-large-latest');
        $results = [];
        foreach ($models as $model) {
            $results[$model] = $this->testOpenAICompat($key, 'https://api.mistral.ai/v1/chat/completions', $model, 'Mistral');
        }
        return $this->aggregateModelResults($results);
    }

    /** Variante générique : OpenAI-compat avec test par modèle enregistré. */
    private function testOpenAICompatMultiModel(string $key, string $endpoint, string $provider, string $defaultModel, string $providerLabel): array
    {
        $models  = $this->modelsToTest($provider, $defaultModel);
        $results = [];
        foreach ($models as $model) {
            $results[$model] = $this->testOpenAICompat($key, $endpoint, $model, $providerLabel);
        }
        return $this->aggregateModelResults($results);
    }

    /**
     * Un test OpenAI passe-t-il par la Responses API ? Même règle que
     * l'adapter : le routage du catalogue, source unique. Un préfixe `gpt-5`
     * codé en dur envoyait GPT-6 Astra sur Chat Completions — un chemin que
     * le chat, lui, n'emprunte jamais pour ce modèle.
     */
    private static function testsViaResponsesApi(string $model): bool
    {
        return LlmCatalog::routing('openai', $model) === 'responses_api';
    }

    /**
     * Texte d'une réponse Messages API, pour les écrans de test. Le premier
     * bloc n'est plus forcément du texte : les modèles qui raisonnent par
     * défaut (Opus 5, Sonnet 5, Fable 5 et 5.1) ouvrent sur un bloc
     * `thinking` — lire `content[0].text` affichait « (vide) » sur une
     * réponse valide. Sans texte, la raison de l'arrêt est nommée (refus,
     * limite atteinte) plutôt que tue.
     */
    private static function anthropicReplyText(array $json): string
    {
        foreach ($json['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && trim((string)($block['text'] ?? '')) !== '') {
                return trim((string)$block['text']);
            }
        }
        $stop = (string)($json['stop_reason'] ?? '');
        return $stop !== '' ? t('(vide — arrêt : {raison})', ['raison' => $stop]) : t('(vide)');
    }

    /**
     * Message d'erreur d'un test Anthropic. Un seul cas enrichi : l'organisation
     * en « zéro rétention » à qui Claude Fable est refusé. Le message brut
     * d'Anthropic dit le symptôme (« must have data retention enabled »), pas
     * les remèdes — ni que le chat, lui, fait déjà répondre un autre modèle.
     */
    private static function anthropicTestError(string $message, string $model): string
    {
        if (stripos($message, 'data retention') === false) {
            return $message;
        }
        $relais = LlmCatalog::retentionFallback('anthropic', $model);
        $label  = $relais !== null ? (string)(LlmCatalog::model('anthropic', $relais)['label'] ?? $relais) : null;

        return t('Conservation des données requise : ce modèle n\'est ouvert qu\'aux organisations Anthropic qui conservent les données 30 jours. Activez cette conservation pour l\'organisation ou pour un workspace dédié (console Anthropic), ou demandez une autorisation à Anthropic.')
            . ($label !== null ? ' ' . t('En attendant, le chat fait répondre {modele} à sa place.', ['modele' => $label]) : '')
            . ' (' . $message . ')';
    }

    private function testAnthropic(string $key): array
    {
        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 5,
            'messages'   => [['role' => 'user', 'content' => 'Réponds juste "ok"']],
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                "x-api-key: $key",
                'anthropic-version: 2023-06-01',
            ]),
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $res  = file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
        $json = json_decode($res, true);
        if (isset($json['error'])) {
            return ['ok' => false, 'message' => $json['error']['message']];
        }
        return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => self::anthropicReplyText(is_array($json) ? $json : [])])];
    }

    private function testOpenAICompat(string $key, string $url, string $model, string $providerName): array
    {
        $payloadData = [
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => 'Réponds juste "ok"']],
        ];
        if (str_contains($url, 'api.openai.com')) {
            $payloadData['max_completion_tokens'] = 5;
        } elseif (str_contains($url, 'generativelanguage.googleapis.com') || str_contains($url, 'api.mistral.ai')) {
            // Gemini 3 réfléchit par défaut, et la réflexion compte dans ce
            // plafond : à 5 tokens elle l'épuise et la réponse revient vide sur
            // un modèle qui marche. Même marge pour Mistral, dont Small 4,
            // Medium 3.5 et Large 4 savent raisonner. Seuls les tokens produits
            // sont facturés.
            $payloadData['max_tokens'] = 1024;
        } else {
            $payloadData['max_tokens'] = 5;
        }
        $payload = json_encode($payloadData);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer $key",
            'content'       => $payload,
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $res  = file_get_contents($url, false, $ctx);
        $json = json_decode($res, true);
        if (isset($json['error'])) {
            $msg = is_array($json['error']['message'] ?? null) ? json_encode($json['error']['message']) : ($json['error']['message'] ?? t('Erreur inconnue'));
            return ['ok' => false, 'message' => $msg];
        }
        $reply = $this->extractOpenAICompatMessageText($json);
        return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => $reply !== '' ? $reply : t('(vide)')])];
    }

    private function testLocal(): array
    {
        $row = ApiKey::findByProvider('local');
        $baseUrl = rtrim($row['base_url'] ?? '', '/');
        if (!$baseUrl) {
            return ['ok' => false, 'message' => t('URL de base non configurée.')];
        }
        $model    = $row['model'] ?? 'llama3';
        $endpoint = $baseUrl . '/chat/completions';
        $decrypted = $row ? Encryption::decrypt($row['key_enc']) : '';
        $apiKey    = ($decrypted !== '' && $decrypted !== 'none') ? $decrypted : '';

        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => 5,
            'messages'   => [['role' => 'user', 'content' => 'Réponds juste "ok"']],
        ]);
        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') $headers[] = "Authorization: Bearer $apiKey";

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($endpoint, false, $ctx);
        if ($res === false) {
            return ['ok' => false, 'message' => t('Impossible de contacter {adresse}', ['adresse' => $endpoint])];
        }
        $json = json_decode($res, true);
        if (isset($json['error'])) {
            return ['ok' => false, 'message' => $json['error']['message'] ?? t('Erreur inconnue')];
        }
        $reply = $json['choices'][0]['message']['content'] ?? null;
        if ($reply === null) {
            return ['ok' => false, 'message' => t('Réponse inattendue : {extrait}', ['extrait' => substr($res, 0, 120)])];
        }
        return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => trim($reply)])];
    }

    public function runTest(): void
    {
        AuthMiddleware::requirePermission('llms.use');
        header('Content-Type: application/json');

        $provider = $_POST['provider'] ?? '';
        $model    = $_POST['model']    ?? '';
        $type     = $_POST['type']     ?? 'basic';
        $catalog  = LLMFactory::models();
        $info     = $catalog[$provider][$model] ?? [];
        $start    = microtime(true);

        try {
            $result = match($type) {
                'basic' => $this->runBasicTest($provider, $model),
                'web'   => $this->runWebTest($provider, $model, $info),
                'file'  => $this->runFileTest($provider, $model, $info),
                default => ['ok' => false, 'message' => t('Type de test inconnu'), 'skipped' => false],
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage(), 'skipped' => false];
        }

        $result['duration_ms'] = (int)((microtime(true) - $start) * 1000);
        echo json_encode($result);
        exit;
    }

    private function runBasicTest(string $provider, string $model): array
    {
        if ($provider === 'local') {
            return array_merge($this->testLocal(), ['skipped' => false]);
        }

        if ($provider === 'anthropic') {
            $key = ApiKey::getDecrypted('anthropic');
            if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];
            // Marge pour le raisonnement : ses tokens comptent dans max_tokens,
            // et un modèle qui pense par défaut n'avait, à 10, plus rien pour
            // répondre (bloc `thinking` seul, arrêt `max_tokens`). Seuls les
            // tokens produits sont facturés : la marge ne coûte rien de plus.
            $payload = json_encode(['model' => $model, 'max_tokens' => 1024,
                'messages' => [['role' => 'user', 'content' => 'Réponds uniquement "ok".']]]);
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 60, 'ignore_errors' => true,
                'header'  => "Content-Type: application/json\r\nx-api-key: $key\r\nanthropic-version: 2023-06-01",
                'content' => $payload]]);
            $res  = file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
            $json = json_decode($res, true);
            if (isset($json['error'])) return ['ok' => false, 'message' => self::anthropicTestError((string)$json['error']['message'], $model), 'skipped' => false];
            return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => self::anthropicReplyText(is_array($json) ? $json : [])]), 'skipped' => false];
        }

        $endpoints = [
            'openai'     => 'https://api.openai.com/v1/chat/completions',
            'mistral'    => 'https://api.mistral.ai/v1/chat/completions',
            'perplexity' => 'https://api.perplexity.ai/chat/completions',
            'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
            'deepseek'   => 'https://api.deepseek.com/chat/completions',
            'moonshot'   => 'https://api.moonshot.ai/v1/chat/completions',
            'zai'        => 'https://api.z.ai/api/paas/v4/chat/completions',
        ];
        $url = $endpoints[$provider] ?? null;
        if (!$url) return ['ok' => false, 'message' => t('Fournisseur inconnu'), 'skipped' => false];
        $key = ApiKey::getDecrypted($provider);
        if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];

        if ($provider === 'openai' && self::testsViaResponsesApi($model)) {
            return array_merge($this->testOpenAIBasicViaResponses($key, $model), ['skipped' => false]);
        }

        return array_merge($this->testOpenAICompat($key, $url, $model, $provider), ['skipped' => false]);
    }

    private function runWebTest(string $provider, string $model, array $info): array
    {
        if (empty($info['web_native'])) {
            return ['ok' => false, 'message' => t('Recherche web non supportée nativement par ce modèle'), 'skipped' => true];
        }

        if ($provider === 'anthropic') {
            $key = ApiKey::getDecrypted('anthropic');
            if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];
            $request = function (string $toolType) use ($key, $model): array {
                $tool = ['type' => $toolType, 'name' => 'web_search'];
                if ($toolType === 'web_search_20260209') {
                    $tool['allowed_callers'] = ['direct'];
                }
                $payload = json_encode([
                    'model' => $model,
                    // Même marge que le test de base : le raisonnement compte.
                    'max_tokens' => 1024,
                    'messages' => [['role' => 'user', 'content' => "Quelle est la date d'aujourd'hui ?"]],
                    'tools' => [$tool],
                ]);
                $ctx = stream_context_create(['http' => [
                    'method' => 'POST',
                    'timeout' => 60,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", [
                        'Content-Type: application/json',
                        "x-api-key: $key",
                        'anthropic-version: 2023-06-01',
                    ]),
                    'content' => $payload,
                ]]);
                $res = file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
                return is_array(json_decode((string)$res, true)) ? json_decode((string)$res, true) : [];
            };

            $json = $request('web_search_20260209');
            if (isset($json['error'])) {
                $errMsg = strtolower((string)($json['error']['message'] ?? ''));
                if (str_contains($errMsg, 'web_search_20260209') || str_contains($errMsg, 'unknown tool') || str_contains($errMsg, 'invalid tool') || str_contains($errMsg, 'unsupported tool')) {
                    $json = $request('web_search_20250305');
                }
            }

            if (isset($json['error'])) return ['ok' => false, 'message' => self::anthropicTestError((string)$json['error']['message'], $model), 'skipped' => false];
            foreach ($json['content'] ?? [] as $block) {
                // La recherche est un outil SERVEUR : Anthropic l'exécute et la
                // signale par un bloc `server_tool_use`, jamais `tool_use`.
                if (in_array($block['type'] ?? '', ['server_tool_use', 'tool_use'], true))
                    return ['ok' => true, 'message' => t('Recherche web déclenchée ✓'), 'skipped' => false];
                if ($block['type'] === 'text' && !empty($block['text']))
                    return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => mb_substr(trim($block['text']), 0, 100)]), 'skipped' => false];
            }
            return ['ok' => true, 'message' => t('Réponse reçue'), 'skipped' => false];
        }

        if ($provider === 'gemini') {
            $key = ApiKey::getDecrypted('gemini');
            if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];
            return array_merge($this->testGeminiWebNative($key, $model), ['skipped' => false]);
        }

        if ($provider === 'mistral') {
            $key = ApiKey::getDecrypted('mistral');
            if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];
            return array_merge($this->testMistralWebViaConversations($key, $model), ['skipped' => false]);
        }

        if ($provider === 'openai') {
            $key = ApiKey::getDecrypted('openai');
            if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];
            return array_merge($this->testOpenAIWebViaResponses($key, $model), ['skipped' => false]);
        }

        $endpoints = ['perplexity' => 'https://api.perplexity.ai/chat/completions'];
        $url = $endpoints[$provider] ?? null;
        if (!$url) return ['ok' => false, 'message' => t('Recherche web non testable pour ce fournisseur'), 'skipped' => true];
        $key = ApiKey::getDecrypted($provider);
        if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];

        return array_merge($this->testOpenAICompat($key, $url, $model, $provider), ['skipped' => false]);
    }

    private function testGeminiWebNative(string $key, string $model): array
    {
        $payload = json_encode([
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => "Quelle est la date d'aujourd'hui ? Réponds en une ligne."]],
            ]],
            'tools' => [['google_search' => (object)[]]],
        ]);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 30,
            'ignore_errors' => true,
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $key,
            ]),
            'content'       => $payload,
        ]]);

        $res  = file_get_contents($url, false, $ctx);
        $json = json_decode($res ?: '', true);
        if (!is_array($json)) {
            return ['ok' => false, 'message' => t('Réponse invalide')];
        }
        if (isset($json['error'])) {
            return ['ok' => false, 'message' => $json['error']['message'] ?? t('Erreur inconnue')];
        }

        $reply = trim($this->extractGeminiText($json));
        $grounded = !empty($json['candidates'][0]['groundingMetadata']) || !empty($json['groundingMetadata'] ?? null);
        if ($grounded) {
            return ['ok' => true, 'message' => t('Recherche web déclenchée ✓')];
        }
        if ($reply !== '') {
            return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => mb_substr($reply, 0, 100)])];
        }
        return ['ok' => true, 'message' => t('Réponse reçue')];
    }

    private function testMistralWebViaConversations(string $key, string $model): array
    {
        $primary = $this->testMistralWebViaConversationsTool($key, $model, 'web_search_premium');
        if (($primary['ok'] ?? false) === true || empty($primary['retry_with_fallback'])) {
            unset($primary['retry_with_fallback']);
            return $primary;
        }

        $fallback = $this->testMistralWebViaConversationsTool($key, $model, 'web_search');
        if (($fallback['ok'] ?? false) === true) {
            $fallback['message'] = t('Recherche web déclenchée ✓ (fallback web_search)');
        }
        unset($fallback['retry_with_fallback']);
        return $fallback;
    }

    /**
     * @return array{ok: bool, message: string, retry_with_fallback?: bool}
     */
    private function testMistralWebViaConversationsTool(string $key, string $model, string $toolType): array
    {
        $payload = json_encode([
            'model' => $model,
            'inputs' => [[
                'role'    => 'user',
                'content' => "Quelle est la date d'aujourd'hui ? Réponds en une ligne.",
            ]],
            'tools' => [['type' => $toolType]],
            'store' => false,
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 30,
            'ignore_errors' => true,
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ]),
            'content'       => $payload,
        ]]);

        $res  = file_get_contents('https://api.mistral.ai/v1/conversations', false, $ctx);
        $json = json_decode($res ?: '', true);
        if (!is_array($json)) {
            return ['ok' => false, 'message' => t('Réponse invalide')];
        }
        if (isset($json['error'])) {
            $err = $json['error']['message'] ?? t('Erreur inconnue');
            if (is_array($err)) {
                $err = json_encode($err, JSON_UNESCAPED_UNICODE);
            }
            $retry = $toolType === 'web_search_premium' && $this->shouldFallbackMistralWebTool((string)$err);
            return ['ok' => false, 'message' => (string)$err, 'retry_with_fallback' => $retry];
        }

        $hasToolUse = false;
        foreach (($json['outputs'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            $type = (string)($entry['type'] ?? '');
            $name = (string)($entry['name'] ?? '');
            if (
                str_contains($type, 'tool')
                || $name === 'web_search'
                || $name === 'web_search_premium'
            ) {
                $hasToolUse = true;
                break;
            }
        }

        $reply = trim($this->extractMistralConversationText($json));
        if ($hasToolUse) {
            return ['ok' => true, 'message' => t('Recherche web déclenchée ✓')];
        }
        if ($reply !== '') {
            return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => mb_substr($reply, 0, 100)])];
        }
        return ['ok' => true, 'message' => t('Réponse reçue')];
    }

    private function shouldFallbackMistralWebTool(string $errorMessage): bool
    {
        $msg = strtolower(trim($errorMessage));
        if ($msg === '') {
            return false;
        }

        return str_contains($msg, 'web_search_premium')
            || str_contains($msg, 'unknown tool')
            || str_contains($msg, 'invalid tool')
            || str_contains($msg, 'unsupported tool')
            || str_contains($msg, 'not support');
    }

    private function extractGeminiText(array $response): string
    {
        $parts = [];
        foreach (($response['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
        }
        return implode('', $parts);
    }

    private function extractMistralConversationText(array $response): string
    {
        $parts = [];
        foreach (($response['outputs'] ?? []) as $entry) {
            $content = $entry['content'] ?? null;
            if (is_string($content)) {
                $parts[] = $content;
                continue;
            }
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }
                if (!is_array($part)) {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                    continue;
                }
                if (($part['type'] ?? '') === 'text' && isset($part['content']) && is_string($part['content'])) {
                    $parts[] = $part['content'];
                }
            }
        }

        return implode('', $parts);
    }

    private function runFileTest(string $provider, string $model, array $info): array
    {
        $tags = (array)($info['tags'] ?? []);
        $supportsVision = in_array('vision', $tags, true);
        $supportsFiles  = in_array('fichiers', $tags, true);
        if (!$supportsVision && !$supportsFiles) {
            return ['ok' => false, 'message' => t('Vision/fichiers non supportés par ce modèle'), 'skipped' => true];
        }

        // PNG 1×1 pixel blanc minimal
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVQI12NgAAAAAgAB4iG8MwAAAABJRU5ErkJggg==';

        if ($provider === 'anthropic') {
            $key = ApiKey::getDecrypted('anthropic');
            if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];
            $payload = json_encode(['model' => $model, 'max_tokens' => 1024,
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'image',  'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $png]],
                    ['type' => 'text',   'text'   => 'Que vois-tu ? (5 mots max)'],
                ]]]]);
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 60, 'ignore_errors' => true,
                'header'  => "Content-Type: application/json\r\nx-api-key: $key\r\nanthropic-version: 2023-06-01",
                'content' => $payload]]);
            $res  = file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
            $json = json_decode($res, true);
            if (isset($json['error'])) return ['ok' => false, 'message' => self::anthropicTestError((string)$json['error']['message'], $model), 'skipped' => false];
            return ['ok' => true, 'message' => 'Vision OK — ' . self::anthropicReplyText(is_array($json) ? $json : []), 'skipped' => false];
        }

        if ($provider === 'mistral') {
            $key = ApiKey::getDecrypted('mistral');
            if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];
            return array_merge($this->testMistralVisionAndDocument($key, $model, $png), ['skipped' => false]);
        }

        $endpoints = [
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        ];
        $url = $endpoints[$provider] ?? null;
        if (!$url) return ['ok' => false, 'message' => t('Vision non testable pour ce fournisseur'), 'skipped' => true];
        $key = ApiKey::getDecrypted($provider);
        if (!$key) return ['ok' => false, 'message' => t('Clé API manquante'), 'skipped' => false];

        if ($provider === 'openai') {
            return array_merge($this->testOpenAIVisionViaResponses($key, $model, $png), ['skipped' => false]);
        }

        $payload = json_encode(['model' => $model, 'max_tokens' => 20,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text',      'text'      => 'Que vois-tu sur cette image ? (5 mots max)'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $png]],
            ]]]]);
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 20, 'ignore_errors' => true,
            'header'  => "Content-Type: application/json\r\nAuthorization: Bearer $key",
            'content' => $payload]]);
        $res  = file_get_contents($url, false, $ctx);
        $json = json_decode($res, true);
        if (isset($json['error'])) return ['ok' => false, 'message' => $json['error']['message'], 'skipped' => false];
        $reply = $this->extractOpenAICompatMessageText($json);
        return ['ok' => true, 'message' => 'Vision OK — ' . ($reply !== '' ? $reply : t('(vide)')), 'skipped' => false];
    }

    private function testMistralVisionAndDocument(string $key, string $model, string $pngBase64): array
    {
        // PDF minimal valide (base64) pour vérifier le support document_url.
        $pdfBase64 = 'JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2JqCjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9Db3VudCAxIC9LaWRzIFszIDAgUl0gPj4KZW5kb2JqCjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAyMDAgMjAwXSAvQ29udGVudHMgNCAwIFIgL1Jlc291cmNlcyA8PCAvRm9udCA8PCAvRjEgNSAwIFIgPj4gPj4gPj4KZW5kb2JqCjQgMCBvYmoKPDwgL0xlbmd0aCA0NCA+PgpzdHJlYW0KQlQgL0YxIDEyIFRmIDUwIDEwMCBUZCAoVGVzdCBQREYpIFRqIEVUCmVuZHN0cmVhbQplbmRvYmoKNSAwIG9iago8PCAvVHlwZSAvRm9udCAvU3VidHlwZSAvVHlwZTEgL0Jhc2VGb250IC9IZWx2ZXRpY2EgPj4KZW5kb2JqCnhyZWYKMCA2CjAwMDAwMDAwMDAgNjU1MzUgZiAKMDAwMDAwMDAxMCAwMDAwMCBuIAowMDAwMDAwMDYzIDAwMDAwIG4gCjAwMDAwMDAxMjIgMDAwMDAgbiAKMDAwMDAwMDI1MSAwMDAwMCBuIAowMDAwMDAwMzQ1IDAwMDAwIG4gCnRyYWlsZXIKPDwgL1Jvb3QgMSAwIFIgL1NpemUgNiA+PgpzdGFydHhyZWYKNDE5CiUlRU9GCg==';

        $payload = json_encode([
            'model'      => $model,
            // Marge pour un modèle qui raisonne (la réflexion compte dans ce
            // plafond) ; seuls les tokens produits sont facturés.
            'max_tokens' => 1024,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Décris brièvement l’image et le document (max 10 mots).'],
                    ['type' => 'image_url', 'image_url' => 'data:image/png;base64,' . $pngBase64],
                    ['type' => 'document_url', 'document_url' => 'data:application/pdf;base64,' . $pdfBase64],
                ],
            ]],
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 25,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer $key",
            'content'       => $payload,
        ]]);
        $res  = file_get_contents('https://api.mistral.ai/v1/chat/completions', false, $ctx);
        $json = json_decode($res ?: '', true);
        if (isset($json['error']) || isset($json['message'])) {
            $msg = $json['error']['message'] ?? $json['message'] ?? t('Erreur inconnue');
            return ['ok' => false, 'message' => is_string($msg) ? $msg : json_encode($msg)];
        }

        $reply = $this->extractOpenAICompatMessageText(is_array($json) ? $json : []);
        return ['ok' => true, 'message' => t('Vision/fichier OK — {reponse}', ['reponse' => $reply !== '' ? mb_substr($reply, 0, 120) : t('(vide)')])];
    }

    private function testOpenAIBasicViaResponses(string $key, string $model): array
    {
        $payload = json_encode([
            'model'             => $model,
            'input'             => 'Réponds uniquement "ok".',
            // Le raisonnement compte dans max_output_tokens (GPT-5.x, GPT-6
            // Astra) : à 16, il pouvait tout consommer et laisser « (vide) ».
            'max_output_tokens' => 1024,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 20,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer $key",
            'content'       => $payload,
        ]]);
        $res  = file_get_contents('https://api.openai.com/v1/responses', false, $ctx);
        $json = json_decode($res, true);
        if (isset($json['error'])) {
            return ['ok' => false, 'message' => $json['error']['message'] ?? t('Erreur inconnue')];
        }
        $reply = trim($this->extractOpenAIResponsesText($json));
        return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => $reply !== '' ? $reply : t('(vide)')])];
    }

    private function testOpenAIWebViaResponses(string $key, string $model): array
    {
        $payload = json_encode([
            'model'             => $model,
            'input'             => "Quelle est la date d'aujourd'hui ?",
            'tools'             => [['type' => 'web_search']],
            'max_output_tokens' => 100,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 30,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer $key",
            'content'       => $payload,
        ]]);
        $res  = file_get_contents('https://api.openai.com/v1/responses', false, $ctx);
        $json = json_decode($res, true);
        if (isset($json['error'])) {
            return ['ok' => false, 'message' => $json['error']['message'] ?? t('Erreur inconnue')];
        }

        $reply = trim($this->extractOpenAIResponsesText($json));
        if ($reply !== '') {
            return ['ok' => true, 'message' => t('Réponse : {reponse}', ['reponse' => mb_substr($reply, 0, 120)])];
        }

        return ['ok' => true, 'message' => t('Réponse reçue')];
    }

    private function testOpenAIVisionViaResponses(string $key, string $model, string $pngBase64): array
    {
        $payload = json_encode([
            'model' => $model,
            'input' => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => 'Que vois-tu sur cette image ? (5 mots max)'],
                    ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . $pngBase64],
                ],
            ]],
            'max_output_tokens' => 20,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 20,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer $key",
            'content'       => $payload,
        ]]);
        $res  = file_get_contents('https://api.openai.com/v1/responses', false, $ctx);
        $json = json_decode($res, true);
        if (isset($json['error'])) {
            return ['ok' => false, 'message' => $json['error']['message'] ?? t('Erreur inconnue')];
        }

        $reply = trim($this->extractOpenAIResponsesText($json));
        return ['ok' => true, 'message' => 'Vision OK — ' . ($reply !== '' ? $reply : t('(vide)'))];
    }

    private function extractOpenAIResponsesText(array $json): string
    {
        // Concatène TOUS les `output_text` du tableau output[] : avec
        // l'API Responses (web_search, GPT-5), le modèle peut émettre
        // un préambule + le JSON dans deux parts distinctes.
        $parts = [];

        $top = trim((string)($json['output_text'] ?? ''));
        if ($top !== '') {
            $parts[] = $top;
        }

        foreach ($json['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text' && !empty($content['text'])) {
                    $parts[] = (string)$content['text'];
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    private function extractOpenAICompatMessageText(array $json): string
    {
        $content = $json['choices'][0]['message']['content'] ?? '';
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
                continue;
            }
            if (($part['type'] ?? '') === 'text' && isset($part['content']) && is_string($part['content'])) {
                $parts[] = $part['content'];
            }
        }

        return trim(implode('', $parts));
    }

    public function usage(): void
    {
        AuthMiddleware::requirePermission('costs.view');
        $filters = $this->resolveUsageFilters();
        $from    = $filters['from'];
        $to      = $filters['to'];
        $userId  = $filters['userId'];

        // Export brut conservé (lien « Export brut (logs) » — 500 dernières lignes).
        if (isset($_GET['export'])) {
            $this->exportCsv(UsageLog::query($from, $to, $userId));
        }

        $data           = $this->loadUsageCreditData($filters);
        $kpis           = $data['kpis'];
        $monthly        = $data['monthly'];
        $usersTotals    = $data['usersTotals'];
        $monthlyPerUser = $data['monthlyPerUser'];
        $creditValueEur = $data['creditValueEur'];
        $byCategory     = $data['byCategory'];
        $unpriced       = $data['unpriced'];

        // Onglet Répartition (donuts + détail consolidé), filtrable par utilisateur.
        $byProvider      = UsageLog::byProvider($from, $to, $userId);
        $byProfile       = UsageLog::byProfile($from, $to, $userId);
        $byTool          = UsageLog::byTool($from, $to, $userId);
        $byModel         = UsageLog::byModel($from, $to, $userId);
        $byKnowledgeBase = UsageLog::byKnowledgeBase($from, $to, $userId);
        $detailByUser    = UsageLog::detailByUser($from, $to, $userId);

        // Vue d'ensemble : fonctionnalités, adoption, qualité — sans montant en euros.
        // Comparaison : la même période de référence que la page Budget.
        $period   = $filters['period'];
        $compare  = $from !== null && $to !== null ? UsagePeriod::previous($period, $from, $to) : null;
        $insights = UsageInsights::overview($from, $to, $userId, $byCategory, $compare);

        $usersList = User::all();
        $success   = Session::getFlash('success');
        $error     = Session::getFlash('error');

        $preset    = $filters['preset'];
        $fromInput = $filters['fromInput'];
        $toInput   = $filters['toInput'];

        require ROOT . '/templates/admin/usage.php';
    }

    /** GET /admin/usage/export-csv?view=monthly|users(&preset&from&to&user_id) */
    public function usageExportCsv(): void
    {
        AuthMiddleware::requirePermission('costs.view');
        $view    = $this->resolveUsageExportView();
        $filters = $this->resolveUsageFilters();
        $data    = $this->loadUsageCreditData($filters);
        $filename = $this->usageExportFilename($view, $filters, 'csv');

        if ($view === 'users') {
            // Format long (utilisateur × mois) pivotable, avec cumul progressif.
            $rows = [];
            foreach ($data['usersTotals'] as $userRow) {
                $key = $userRow['user_id'] !== null ? (string)$userRow['user_id'] : 'deleted';
                foreach ($data['monthlyPerUser'][$key] ?? [] as $monthRow) {
                    $rows[] = [
                        $userRow['user_name'],
                        $userRow['email'],
                        $monthRow['month'],
                        $monthRow['total_credits'],
                        $monthRow['exempt_credits'],
                        number_format($monthRow['cost_eur'], 4, ',', ''),
                        number_format($monthRow['billed_eur'], 4, ',', ''),
                        $monthRow['requests'],
                        $monthRow['cumul_credits'],
                        number_format($monthRow['cumul_billed_eur'], 4, ',', ''),
                    ];
                }
            }
            CsvExport::stream($filename, [
                t('Utilisateur'), 'Email', t('Mois'), t('Crédits'), t('dont hors quota'),
                t('Coût réel IA (EUR)'), t('Valorisation (EUR)'), t('Opérations'), t('Cumul crédits'), t('Cumul valorisation (EUR)'),
            ], $rows);
        }

        $familyKeys = UsageSource::families();
        $rows = [];
        foreach ($data['monthly'] as $monthRow) {
            $row = [$monthRow['month']];
            foreach ($familyKeys as $family) {
                $row[] = (int)($monthRow['families'][$family] ?? 0);
            }
            $rows[] = array_merge($row, [
                $monthRow['total_credits'],
                number_format($monthRow['cost_eur'], 4, ',', ''),
                number_format($monthRow['billed_eur'], 4, ',', ''),
                $monthRow['requests'],
                $monthRow['active_users'],
                $monthRow['tokens'],
            ]);
        }
        $kpis = $data['kpis'];
        $totalRow = ['TOTAL'];
        foreach ($familyKeys as $family) {
            $totalRow[] = (int)($kpis['families'][$family] ?? 0);
        }
        $rows[] = array_merge($totalRow, [
            $kpis['total_credits'],
            number_format($kpis['cost_eur'], 4, ',', ''),
            number_format($kpis['billed_eur'], 4, ',', ''),
            $kpis['requests'], $kpis['active_users'], $kpis['tokens'],
        ]);
        CsvExport::stream($filename, array_merge(
            [t('Mois')],
            array_map(static fn(string $f): string => t('Crédits {famille}', ['famille' => UsageSource::familyLabel($f)]), $familyKeys),
            [t('Total crédits'), t('Coût réel IA (EUR)'), t('Valorisation (EUR)'), t('Opérations'), t('Utilisateurs actifs'), 'Tokens']
        ), $rows);
    }

    /** GET /admin/usage/export-pdf?view=monthly|users(&preset&from&to&user_id) */
    public function usageExportPdf(): void
    {
        AuthMiddleware::requirePermission('costs.view');
        $view    = $this->resolveUsageExportView();
        $filters = $this->resolveUsageFilters();
        $data    = $this->loadUsageCreditData($filters);

        // Libellé du filtre utilisateur pour l'en-tête du rapport.
        $filterUserLabel = t('Tous les utilisateurs');
        if ($filters['userId'] !== null) {
            $filterUserLabel = t('Utilisateur #{id}', ['id' => $filters['userId']]);
            foreach ($data['usersTotals'] as $userRow) {
                if ($userRow['user_id'] === $filters['userId']) {
                    $filterUserLabel = $userRow['user_name'];
                    break;
                }
            }
        }

        ob_start();
        require ROOT . '/templates/admin/usage-pdf.php';
        $html = (string)ob_get_clean();

        try {
            $pdfBinary = (new PdfExportService())->buildFromHtml($html, t('Rapport d\'utilisation des crédits'));
        } catch (Throwable $e) {
            error_log('[Usage PDF] ' . $e->getMessage());
            Session::flash('error', t('Export PDF indisponible pour le moment.'));
            header('Location: /admin/usage');
            exit;
        }

        $filename = $this->usageExportFilename($view, $filters, 'pdf');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Length: ' . strlen($pdfBinary));
        echo $pdfBinary;
        exit;
    }

    /**
     * Résout la période (UsagePeriod : tranches et plage personnalisée, les
     * mêmes que la page Budget) et le filtre utilisateur depuis $_GET.
     * Source unique partagée entre la page usage et ses exports CSV/PDF.
     *
     * @return array{preset:string, from:?string, to:?string, fromInput:string, toInput:string, userId:?int, period:array}
     */
    private function resolveUsageFilters(): array
    {
        $period = UsagePeriod::resolve($_GET, 'last_12_months', new DateTimeImmutable('now'));
        $userId = (int)($_GET['user_id'] ?? 0);

        return [
            'preset'    => $period['preset'],
            'from'      => $period['from'],
            'to'        => $period['to'],
            'fromInput' => $period['fromInput'],
            'toInput'   => $period['toInput'],
            'userId'    => $userId > 0 ? $userId : null,
            'period'    => $period,
        ];
    }

    /**
     * Charge et met en forme les données crédits : KPIs, série mensuelle comblée,
     * cumuls par utilisateur, pivot mensuel avec cumul progressif, répartition
     * par catégorie, opérations non tarifées.
     *
     * Source unique page + exports. Deux grandeurs € partout, jamais confondues :
     *   - `cost_eur`   : coût RÉEL fournisseur, sommé depuis le grand livre ;
     *   - `billed_eur` : valorisation = crédits × valeur ACTUELLE du crédit
     *                    (CreditService::creditsToEur, jamais stockée).
     *
     * @return array{kpis:array, monthly:array, usersTotals:array,
     *               monthlyPerUser:array<string, array>, byCategory:array, unpriced:array, creditValueEur:float}
     */
    private function loadUsageCreditData(array $filters): array
    {
        $from   = $filters['from'];
        $to     = $filters['to'];
        $userId = $filters['userId'];

        $kpis = UsageLog::periodTotals($from, $to, $userId);
        $kpis['billed_eur'] = CreditService::creditsToEur($kpis['total_credits']);

        $monthly = $this->fillMonthlySeries(UsageLog::monthlySummary($from, $to, $userId), $from, $to);
        $previousCredits = null;
        $families = [];
        foreach (UsageSource::families() as $family) {
            $families[$family] = 0;
        }
        foreach ($monthly as &$month) {
            $month['billed_eur'] = CreditService::creditsToEur($month['total_credits']);
            $month['evolution_pct'] = ($previousCredits !== null && $previousCredits > 0)
                ? round(($month['total_credits'] - $previousCredits) / $previousCredits * 100, 1)
                : null;
            $previousCredits = $month['total_credits'];
            foreach ($month['families'] as $family => $credits) {
                $families[$family] = ($families[$family] ?? 0) + (int)$credits;
            }
        }
        unset($month);
        $kpis['families'] = $families;

        $usersTotals = UsageLog::periodTotalsByUser($from, $to, $userId);
        $grandTotal = max(1, $kpis['total_credits']);
        foreach ($usersTotals as &$userRow) {
            $userRow['billed_eur'] = CreditService::creditsToEur($userRow['total_credits']);
            $userRow['share_pct']  = round($userRow['total_credits'] / $grandTotal * 100, 1);
        }
        unset($userRow);

        $monthlyPerUser = [];
        foreach (UsageLog::monthlyByUser($from, $to, $userId) as $row) {
            $key = $row['user_id'] !== null ? (string)$row['user_id'] : 'deleted';
            $previous = $monthlyPerUser[$key] ?? [];
            $cumul = $previous !== [] ? $previous[count($previous) - 1]['cumul_credits'] : 0;
            $row['billed_eur']       = CreditService::creditsToEur($row['total_credits']);
            $row['cumul_credits']    = $cumul + $row['total_credits'];
            $row['cumul_billed_eur'] = CreditService::creditsToEur($row['cumul_credits']);
            $monthlyPerUser[$key][] = $row;
        }

        $byCategory = UsageLog::byCategory($from, $to, $userId);
        foreach ($byCategory as &$cat) {
            $cat['billed_eur'] = CreditService::creditsToEur($cat['credits']);
            foreach ($cat['sources'] as &$src) {
                $src['billed_eur'] = CreditService::creditsToEur($src['credits']);
            }
            unset($src);
        }
        unset($cat);

        return [
            'kpis'           => $kpis,
            'monthly'        => $monthly,
            'usersTotals'    => $usersTotals,
            'monthlyPerUser' => $monthlyPerUser,
            'byCategory'     => $byCategory,
            'unpriced'       => UsageLog::unpricedOperations($from, $to, $userId),
            'creditValueEur' => CreditService::creditValueEur(),
        ];
    }

    /** Comble les mois sans données (valeurs 0) ; sans bornes = bornes MIN/MAX des données. */
    private function fillMonthlySeries(array $rows, ?string $from, ?string $to): array
    {
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['month']] = $row;
        }

        if ($from && $to) {
            $startYm = substr($from, 0, 7);
            $endYm   = substr($to, 0, 7);
        } elseif ($byMonth !== []) {
            $months  = array_keys($byMonth);
            $startYm = min($months);
            $endYm   = max($months);
        } else {
            return [];
        }

        try {
            $cursor = new DateTimeImmutable($startYm . '-01');
            $end    = new DateTimeImmutable($endYm . '-01');
        } catch (Exception) {
            return array_values($byMonth);
        }

        $filled = [];
        while ($cursor <= $end) {
            $ym = $cursor->format('Y-m');
            $filled[] = $byMonth[$ym] ?? UsageLog::emptyMonth($ym);
            $cursor = $cursor->modify('+1 month');
        }
        return $filled;
    }

    /** Vue d'export whitelistée (monthly par défaut). */
    private function resolveUsageExportView(): string
    {
        $view = (string)($_GET['view'] ?? '');
        return in_array($view, ['monthly', 'users'], true) ? $view : 'monthly';
    }

    /** Nom de fichier d'export : usage_<vue>_<période>[_user<ID>].<ext> */
    private function usageExportFilename(string $view, array $filters, string $ext): string
    {
        $viewSlug = $view === 'users' ? 'par-utilisateur' : 'mensuel';
        $periodSlug = ($filters['from'] !== null && $filters['to'] !== null)
            ? str_replace('-', '', $filters['from']) . '-' . str_replace('-', '', $filters['to'])
            : 'tout';
        $userSlug = $filters['userId'] !== null ? '_user' . $filters['userId'] : '';
        return 'usage_' . $viewSlug . '_' . $periodSlug . $userSlug . '.' . $ext;
    }

    public function usageBackfillTools(): void
    {
        AuthMiddleware::requirePermission('costs.edit');
        $result = UsageLog::backfillEstimatedToolUsage();

        if (!empty($result['ok'])) {
            $parts = [
                (string)($result['message'] ?? t('Backfill terminé.')),
                t('Scannés: {n}', ['n' => number_format((int)($result['scanned'] ?? 0))]),
                t('Détectés: {n}', ['n' => number_format((int)($result['matched'] ?? 0))]),
                t('Insérés: {n}', ['n' => number_format((int)($result['inserted'] ?? 0))]),
                t('Sans outil estimable: {n}', ['n' => number_format((int)($result['skipped_no_tool'] ?? 0))]),
                t('Sans utilisateur: {n}', ['n' => number_format((int)($result['skipped_no_user'] ?? 0))]),
            ];
            Session::flash('success', implode(' | ', $parts));
        } else {
            Session::flash('error', (string)($result['message'] ?? t('Le backfill a échoué.')));
        }

        header('Location: /admin/usage?preset=all');
        exit;
    }

    public function credits(): void
    {
        AuthMiddleware::requirePermission('costs.view');

        $success = Session::getFlash('success');
        $error   = Session::getFlash('error');
        $logos = LLMFactory::logos();

        $llmModels = LlmModel::active();
        $tools = array_values(array_filter(
            ChatTool::all(),
            static fn(array $tool): bool => !empty($tool['is_active'])
        ));
        $catalogServiceRows = $this->buildCatalogServiceRows($llmModels, $tools);

        $storedConfig = $this->decodeJsonSetting('credits_config_json');
        if (empty($storedConfig)) {
            $storedConfig = $this->defaultCreditsConfig($catalogServiceRows);
        }
        $config = $this->normalizeCreditsConfig($storedConfig);

        // N'afficher que les services liés aux modèles LLM actifs et outils actifs.
        $allowedCatalogKeys = [];
        foreach ($catalogServiceRows as $catalogService) {
            $key = trim((string)($catalogService['catalog_key'] ?? ''));
            if ($key !== '') {
                $allowedCatalogKeys[$key] = true;
            }
        }
        $config['services'] = array_values(array_filter(
            $config['services'],
            static function (array $service) use ($allowedCatalogKeys): bool {
                $key = trim((string)($service['catalog_key'] ?? ''));
                return $key !== '' && isset($allowedCatalogKeys[$key]);
            }
        ));
        $config['services'] = array_map(
            static function (array $service): array {
                $service['active'] = true;
                return $service;
            },
            $config['services']
        );

        // Migration douce: ancien défaut 0,01€ -> nouveau défaut 0,001€.
        $creditValue = (float)($config['global']['credit_value_eur'] ?? 0.0);
        if (abs($creditValue - 0.01) < 0.0000001) {
            $config['global']['credit_value_eur'] = 0.001;
        }

        $existingCatalogKeys = [];
        foreach ($config['services'] as $service) {
            $key = trim((string)($service['catalog_key'] ?? ''));
            if ($key !== '') {
                $existingCatalogKeys[$key] = true;
            }
        }
        foreach ($catalogServiceRows as $catalogService) {
            $key = trim((string)($catalogService['catalog_key'] ?? ''));
            if ($key === '' || isset($existingCatalogKeys[$key])) {
                continue;
            }
            $catalogService['active'] = true;
            $config['services'][] = $catalogService;
            $existingCatalogKeys[$key] = true; // évite les doublons si le catalogue génère la même clé 2×
        }

        // Tarif public qu'applique la facturation aux lignes sans prix : l'écran
        // calcule avec lui (sinon « 0 € → 1 crédit » pour un modèle facturé au
        // tarif public à chaque message). Lecture seule, jamais enregistré.
        $catalogCosts = [];
        foreach ($config['services'] as $service) {
            $key = trim((string)($service['catalog_key'] ?? ''));
            $cost = $key !== '' ? CreditService::catalogUnitCost($key) : null;
            if ($cost !== null) {
                $catalogCosts[$key] = $cost;
            }
        }

        $bootstrap = [
            'config' => $config,
            'catalog' => [
                'llm_models' => $llmModels,
                'tools' => $tools,
            ],
            'catalog_costs' => (object)$catalogCosts,
        ];

        require ROOT . '/templates/admin/credits.php';
    }

    public function saveCredits(): void
    {
        AuthMiddleware::requirePermission('costs.edit');
        $asJson  = AjaxResponse::wanted();
        $rawJson = $_POST['credits_config_json'] ?? null;
        if (!is_string($rawJson) || trim($rawJson) === '') {
            AjaxResponse::abort($asJson, 'error', t('Configuration invalide : JSON manquant.'), '/admin/credits');
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            AjaxResponse::abort($asJson, 'error', t('Configuration invalide : format JSON incorrect.'), '/admin/credits');
        }

        $normalizedConfig = $this->normalizeCreditsConfig($decoded);
        Settings::set('credits_config_json', $this->encodeJsonSetting($normalizedConfig));

        AjaxResponse::finish($asJson, 'success', t('Configuration crédits enregistrée.'), '/admin/credits');
    }

    public function refreshCreditsPrices(): void
    {
        AuthMiddleware::requirePermission('costs.edit');
        header('Content-Type: application/json');

        $rawPayload = file_get_contents('php://input');
        $payload = json_decode((string)$rawPayload, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => t('Payload JSON invalide.')]);
            exit;
        }

        $servicesRaw = $payload['services'] ?? null;
        if (!is_array($servicesRaw) || empty($servicesRaw)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => t('Aucun service transmis.')]);
            exit;
        }

        $services = [];
        foreach ($servicesRaw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $services[] = [
                'id'       => $id,
                'name'     => trim((string)($row['name'] ?? '')),
                'provider' => trim((string)($row['provider'] ?? '')),
                'type'     => trim((string)($row['type'] ?? '')),
                'unit'     => trim((string)($row['unit'] ?? '')),
                'model'    => trim((string)($row['model'] ?? '')),
                'direction'=> trim((string)($row['direction'] ?? '')),
                'catalog_key'  => trim((string)($row['catalog_key'] ?? '')),
                'billing_hint' => trim((string)($row['billing_hint'] ?? '')),
                'tool_config_json' => is_array($row['tool_config_json'] ?? null)
                    ? (json_encode($row['tool_config_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                    : trim((string)($row['tool_config_json'] ?? '')),
            ];
        }

        if (empty($services)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => t('Aucun service valide à traiter.')]);
            exit;
        }

        // Recherche Internet sur des dizaines de services : minutes possibles.
        // Elle part en tâche de fond, la page suit le job par son flux — et la
        // quitter n'annule rien : le job écrit lui-même la configuration.
        // Clé de déduplication : deux clics (ou deux onglets) rejoignent la
        // recherche en cours au lieu d'en lancer une seconde, facturée pour rien.
        $user  = Session::currentUser();
        $jobId = JobQueue::enqueue(
            'credits_refresh',
            (int)$user['id'],
            null,
            ['services' => $services],
            t('Mise à jour des prix ({n} services)', ['n' => count($services)]),
            'credits_refresh'
        );
        JobQueue::respondAndDispatch(['ok' => true, 'job_id' => $jobId], $jobId);
    }

    /**
     * Corps de la recherche de prix (hors requête HTTP) — appelé par
     * `CreditsRefreshJob`.
     *
     * Le résultat est **écrit dans la configuration** avant d'être renvoyé :
     * sans ça, quitter l'écran pendant la recherche perdrait le travail (les
     * prix n'étaient appliqués que dans l'état JavaScript de la page).
     *
     * @param array $services services normalisés par `refreshCreditsPrices()`
     * @param callable|null $keepAlive appelé pendant les transferts HTTP :
     *        renvoie false pour interrompre (annulation demandée). Sert aussi
     *        de battement de cœur — sans lui, une recherche de plus de deux
     *        minutes serait considérée morte et rejouée par le reaper.
     */
    public function runCreditsPriceRefresh(array $services, ?callable $keepAlive = null, ?int $billingUserId = null): array
    {
        // Imputation de la recherche (appel LLM + recherches web) à
        // l'administrateur qui l'a lancée — hors quota (source price_refresh).
        $this->priceRefreshBillingCtx = ['user_id' => $billingUserId];
        $result  = $this->fetchLatestUsdPricingFromInternet($services, $keepAlive);
        $applied = $this->applyPriceUpdatesToStoredConfig((array)($result['updated'] ?? []), $services);

        return array_merge(['ok' => true, 'done' => true, 'applied' => $applied], $result);
    }

    /**
     * Écrit les prix trouvés dans `credits_config_json`.
     *
     * La configuration est relue AU MOMENT de l'écriture (et non celle qu'avait
     * la page au clic) : une modification enregistrée entre-temps par
     * l'administrateur survit, les prix se posent par-dessus.
     *
     * Un service affiché mais jamais enregistré (ligne issue du catalogue,
     * ajoutée à l'affichage par `credits()`) est absent de la configuration
     * stockée : il est alors créé depuis la charge utile, sinon son prix serait
     * silencieusement perdu.
     *
     * @param array $updates          entrées `{id, real_cost_usd, source_url, source_note}`
     * @param array $payloadServices  services transmis au job (repli pour les lignes non enregistrées)
     * @return int nombre de services réellement mis à jour
     */
    private function applyPriceUpdatesToStoredConfig(array $updates, array $payloadServices): int
    {
        $stored = $this->decodeJsonSetting('credits_config_json');
        if (empty($stored)) {
            // Rien n'a jamais été enregistré : on sème la MÊME assiette que
            // l'écran (`credits()`), soit les services du catalogue, actifs.
            // Écrire en plus les lignes d'exemple de `defaultCreditsConfig()`
            // les rendrait éligibles au repli de tarif par provider/direction
            // de `CreditService` — une facturation au prix d'un GPT-4 fictif.
            $tools = array_values(array_filter(
                ChatTool::all(),
                static fn(array $tool): bool => !empty($tool['is_active'])
            ));
            $stored = $this->defaultCreditsConfig();
            $stored['services'] = array_map(
                static function (array $row): array {
                    $row['active'] = true;
                    return $row;
                },
                $this->buildCatalogServiceRows(LlmModel::active(), $tools)
            );
        }

        $config = $this->normalizeCreditsConfig($stored);
        $fxRate = max(0.0001, (float)($config['global']['fx_rate_eur_usd'] ?? 1.08));

        $indexById = [];
        $indexByCatalogKey = [];
        foreach ($config['services'] as $position => $service) {
            $indexById[(string)$service['id']] = $position;
            $catalogKey = trim((string)($service['catalog_key'] ?? ''));
            if ($catalogKey !== '') {
                $indexByCatalogKey[$catalogKey] = $position;
            }
        }

        $payloadById = [];
        foreach ($payloadServices as $row) {
            if (is_array($row) && trim((string)($row['id'] ?? '')) !== '') {
                $payloadById[trim((string)$row['id'])] = $row;
            }
        }

        $stamp   = date('Y-m-d');
        $changed = 0;
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            $id = trim((string)($update['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $usd = $update['real_cost_usd'] ?? null;
            if (!is_numeric($usd) || (float)$usd < 0) {
                continue;
            }

            $position = $indexById[$id] ?? null;
            $fallback = $payloadById[$id] ?? null;
            if ($position === null && $fallback !== null) {
                $catalogKey = trim((string)($fallback['catalog_key'] ?? ''));
                if ($catalogKey !== '' && isset($indexByCatalogKey[$catalogKey])) {
                    $position = $indexByCatalogKey[$catalogKey];
                }
            }
            if ($position === null) {
                if ($fallback === null) {
                    continue;
                }
                $config['services'][] = [
                    'id'               => $id,
                    'name'             => trim((string)($fallback['name'] ?? t('Service IA'))) ?: t('Service IA'),
                    'provider'         => trim((string)($fallback['provider'] ?? 'N/A')) ?: 'N/A',
                    'type'             => trim((string)($fallback['type'] ?? 'other')),
                    'unit'             => trim((string)($fallback['unit'] ?? 'request')),
                    'real_cost_eur'    => 0.0,
                    'multiplier'       => 1.0,
                    'active'           => true,
                    'catalog_key'      => trim((string)($fallback['catalog_key'] ?? '')),
                    'internal_comment' => '',
                ];
                $position = array_key_last($config['services']);
                $indexById[$id] = $position;
            }

            $config['services'][$position]['real_cost_eur'] = round((float)$usd / $fxRate, 10);
            $config['services'][$position]['internal_comment'] = self::stampPriceSourceComment(
                (string)($config['services'][$position]['internal_comment'] ?? ''),
                (string)($update['source_note'] ?? ''),
                (string)($update['source_url'] ?? ''),
                $stamp
            );
            $changed++;
        }

        if ($changed === 0) {
            return 0;
        }

        // Les prix trouvés sont en USD : la page bascule sur cette devise, comme
        // le faisait l'application côté navigateur.
        $config['global']['currency'] = 'USD';
        Settings::set('credits_config_json', $this->encodeJsonSetting($this->normalizeCreditsConfig($config)));

        return $changed;
    }

    /**
     * Remplace la ligne « Maj Internet … » du commentaire interne par celle du
     * jour. Pure (testée) : une ligne par mise à jour s'accumulerait sinon à
     * chaque recherche.
     */
    public static function stampPriceSourceComment(
        string $current,
        string $sourceNote,
        string $sourceUrl,
        string $stamp
    ): string {
        $sourceNote = trim($sourceNote);
        $sourceUrl  = trim($sourceUrl);
        if ($sourceNote === '' && $sourceUrl === '') {
            return trim($current);
        }

        $parts = ['Maj Internet ' . $stamp . ' (USD)'];
        if ($sourceNote !== '') { $parts[] = $sourceNote; }
        if ($sourceUrl !== '')  { $parts[] = $sourceUrl; }
        $freshLine = implode(' — ', $parts);

        $kept = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', trim($current)) ?: [],
            static fn(string $line): bool => trim($line) !== '' && !str_starts_with(trim($line), 'Maj Internet ')
        ));
        $kept[] = $freshLine;

        return implode("\n", $kept);
    }

    public function settings(): void
    {
        AuthMiddleware::requirePermission('company.view');
        $settings = Settings::all();
        $success  = Session::getFlash('settings_success');
        require ROOT . '/templates/admin/settings.php';
    }

    /**
     * Page « Entreprise » : l'organisation qui utilise MIA (nom, descriptif,
     * logo, modèle PowerPoint). Distincte de « Marque & apparence », qui
     * habille l'APPLICATION : deux écrans, deux formulaires, et chacun
     * n'écrit que ses propres réglages (cf. CompanyProfile).
     */
    public function company(): void
    {
        AuthMiddleware::requirePermission('company.view');
        $settings = Settings::all();
        $success  = Session::getFlash('company_success');
        require ROOT . '/templates/admin/company.php';
    }

    /**
     * Page « Connecteurs » : la liste des sources branchables sur le menu « + »
     * du chat, chacune visible ou non pour TOUS les utilisateurs.
     */
    public function connectors(): void
    {
        AuthMiddleware::requirePermission('connectors.view');
        $connectors = ConnectorRegistry::withState();
        $success    = Session::getFlash('connectors_success');
        require ROOT . '/templates/admin/connectors.php';
    }

    /**
     * Bascule d'un connecteur OU édition de ses expressions déclencheuses.
     * Appelée en AJAX par la page (enregistrement immédiat, pas de bouton
     * « Enregistrer ») ; le formulaire complet reste posté tel quel sans
     * JavaScript — d'où les formes de charge utile :
     *   - `connector` + `enabled`                    → un interrupteur ;
     *   - `connector` + `triggers` | `triggers_reset` → une liste d'expressions ;
     *   - `connectors[]` (+ `triggers[clé]`)          → l'état de toute la page.
     */
    public function saveConnectors(): void
    {
        AuthMiddleware::requirePermission('connectors.edit');
        $asJson = $this->wantsJsonResponse();

        $single = trim((string)($_POST['connector'] ?? ''));
        // « Laisser MIA choisir » : pseudo-connecteur, réglage global.
        if ($single === ConnectorRegistry::AUTO) {
            ConnectorRegistry::setAutoModeEnabled(($_POST['enabled'] ?? '0') === '1');
            if ($asJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            Session::flash('connectors_success', t('Connecteurs mis à jour.'));
            header('Location: /admin/connectors');
            exit;
        }
        if ($single !== '') {
            if (ConnectorRegistry::settingKey($single) === null) {
                if ($asJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'error' => t('Connecteur inconnu.')], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                Session::flash('connectors_success', t('Connecteur inconnu.'));
                header('Location: /admin/connectors');
                exit;
            }
            if (!empty($_POST['triggers_reset'])) {
                $list = ConnectorRegistry::resetTriggers($single);
            } elseif (array_key_exists('triggers', $_POST)) {
                $list = ConnectorRegistry::setTriggers($single, (string)$_POST['triggers']);
            } else {
                $enabled = ($_POST['enabled'] ?? '0') === '1';
                ConnectorRegistry::setEnabled($single, $enabled);
                $list = null;
            }
            if ($asJson) {
                header('Content-Type: application/json; charset=utf-8');
                $payload = ['ok' => true];
                if ($list !== null) {
                    // Liste canonique renvoyée : la carte se re-rend depuis la
                    // source de vérité (normalisation, doublons retirés).
                    $payload['triggers'] = $list;
                    $payload['custom']   = ConnectorRegistry::triggersAreCustom($single);
                }
                echo json_encode($payload, JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            // Formulaire complet (sans JavaScript) : les cases absentes du POST
            // valent « désactivé », et chaque zone d'expressions est appliquée.
            $checked  = array_map('strval', (array)($_POST['connectors'] ?? []));
            $triggers = (array)($_POST['triggers'] ?? []);
            // allKeys() et non keys() : un connecteur hors chat (OneDrive) est
            // rendu par cette page, donc posté par elle — l'oublier ici
            // l'éteindrait à chaque enregistrement complet (case absente = 0).
            foreach (ConnectorRegistry::allKeys() as $key) {
                ConnectorRegistry::setEnabled($key, in_array($key, $checked, true));
                if (isset($triggers[$key])) {
                    ConnectorRegistry::setTriggers($key, (string)$triggers[$key]);
                }
            }
            // La case « Laisser MIA choisir » est dans le MÊME formulaire :
            // absente du POST = décochée, comme les autres.
            ConnectorRegistry::setAutoModeEnabled(in_array(ConnectorRegistry::AUTO, $checked, true));
            if ($asJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        Session::flash('connectors_success', t('Connecteurs mis à jour.'));
        header('Location: /admin/connectors');
        exit;
    }

    /**
     * Identifiants de l'application Slack (écran dédié, atteint depuis la carte
     * Slack de la page Connecteurs).
     *
     * Pourquoi un écran à part plutôt qu'une carte de plus sur /admin/sso :
     * Slack n'est PAS un moyen de connexion à MIA. L'y mettre ferait apparaître
     * un bouton « Se connecter avec Slack » qui n'existe pas, et mêlerait des
     * identifiants qui ne servent qu'à un connecteur aux moyens d'accès à
     * l'application. Même raisonnement que l'application Azure de OneDrive, qui
     * vit dans « Sources synchronisées ».
     */
    public function slackApp(): void
    {
        AuthMiddleware::requirePermission('connectors.view');
        $clientId    = SlackApp::clientId();
        $hasSecret   = SlackApp::clientSecret() !== '';
        $redirectUri = SlackApp::redirectUri();
        $scopes      = SlackMessagesOAuth::SCOPES;
        $scopeGuide  = UserSlackService::scopeGuide();
        $manifestJson = (string)json_encode(
            SlackApp::manifest((string)Settings::get('app_name', 'MIA'), $redirectUri, $scopes),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // État du parcours, en tête de page : l'administrateur voit d'un coup
        // d'œil ce qui reste à faire. Lectures locales seulement.
        $configured    = SlackApp::isConfigured();
        $enabled       = ConnectorRegistry::isEnabled(ConnectorRegistry::SLACK);
        // Libellé TRADUIT de la ligne du menu « + » (ConnectorRegistry::chatLabel) :
        // la page cite exactement ce que le chat affiche.
        $chatLabel     = ConnectorRegistry::chatLabel(ConnectorRegistry::SLACK) ?: t('Messages Slack');
        $triggers      = ConnectorRegistry::triggers(ConnectorRegistry::SLACK);
        try {
            $linkedCount = UserDriveToken::countConnected(UserDriveToken::PROVIDER_SLACK);
        } catch (\Throwable $e) {
            error_log('[Slack admin] comptes reliés illisibles : ' . $e->getMessage());
            $linkedCount = null;
        }

        // Ce que le modèle reçoit réellement : mêmes textes que le chat
        // (SlackTools, ExternalContent), jamais une paraphrase.
        $promptGuidance     = SlackTools::guidanceFor(true);
        $promptReadOnlyTail = SlackTools::readOnlyGuidanceTail();
        $promptToolDefs     = SlackTools::definitionsFor(true, true, true, true);
        $promptSafetyRule   = ExternalContent::rule();

        $success     = Session::getFlash('slack_app_success');
        $error       = Session::getFlash('slack_app_error');
        require ROOT . '/templates/admin/connectors-slack.php';
    }

    /**
     * Enregistrement MANUEL, comme la page Authentification : des identifiants
     * ne partent jamais à moitié saisis. Le secret n'est jamais réaffiché, donc
     * un champ vide vaut « conserver » — l'écraser par le chiffré d'une chaîne
     * vide est le piège déjà payé sur saveApiKey.
     */
    public function saveSlackApp(): void
    {
        AuthMiddleware::requirePermission('connectors.edit');

        SlackApp::setClientId((string)($_POST['slack_client_id'] ?? ''));
        if (!empty($_POST['remove_slack_secret'])) {
            SlackApp::clearClientSecret();
        } else {
            SlackApp::setClientSecret((string)($_POST['slack_client_secret'] ?? ''));
        }

        Session::flash('slack_app_success', t('Application Slack enregistrée.'));
        header('Location: /admin/connectors/slack');
        exit;
    }

    /**
     * Bouton « Tester » : éprouve les identifiants SAISIS (pas seulement ceux
     * enregistrés) sans aucun aller-retour navigateur — cf.
     * SlackApp::testCredentials. Un secret laissé vide reprend celui en base,
     * exactement comme à l'enregistrement.
     */
    public function testSlackApp(): void
    {
        AuthMiddleware::requirePermission('connectors.edit');

        $clientId = trim((string)($_POST['slack_client_id'] ?? ''));
        $secret   = trim((string)($_POST['slack_client_secret'] ?? ''));
        if ($clientId === '') {
            $clientId = SlackApp::clientId();
        }
        if ($secret === '') {
            $secret = SlackApp::clientSecret();
        }

        $resultat = SlackApp::testCredentials($clientId, $secret);

        if ($this->wantsJsonResponse()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($resultat, JSON_UNESCAPED_UNICODE);
            exit;
        }
        Session::flash($resultat['ok'] ? 'slack_app_success' : 'slack_app_error', $resultat['message']);
        header('Location: /admin/connectors/slack');
        exit;
    }

    /**
     * Bases de connaissances → « Sources synchronisées » : les identifiants
     * globaux qui autorisent une base à refléter un dossier Google Drive /
     * SharePoint ou un agenda Google. Ils ne servent qu'aux bases — d'où leur
     * place ici et non dans les réglages d'apparence de l'instance.
     */
    public function knowledgeBaseSyncSettings(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.manage');
        $settings = Settings::all();
        $success  = Session::getFlash('kb_sync_settings_success');
        require ROOT . '/templates/admin/knowledge-bases-sync.php';
    }

    // ===================== Authentification (SSO) =====================

    /**
     * GET /admin/sso — moyens de connexion à l'application.
     *
     * Écran distinct de « Marque & apparence » : ce sont des identifiants, pas
     * de l'habillage, et les laisser dans le formulaire des paramètres les
     * aurait éteints à chaque enregistrement de cette page-là (case absente du
     * POST = décochée).
     */
    public function sso(): void
    {
        AuthMiddleware::requirePermission('sso.view');

        $settings = Settings::all();
        $success  = Session::getFlash('sso_success');
        $error    = Session::getFlash('sso_error');

        $redirectUri     = SsoRegistry::redirectUri();
        $googleEnabled   = SsoRegistry::isEnabled(SsoRegistry::GOOGLE);
        $googleClientId  = SsoRegistry::googleClientId();
        // Signale une configuration héritée du .env : l'admin doit comprendre
        // pourquoi un ID s'affiche alors qu'il n'a jamais rien saisi ici.
        $googleFromEnv   = $googleClientId !== ''
            && trim((string)($settings[SsoRegistry::SETTING_GOOGLE_CLIENT] ?? '')) === '';

        $microsoftEnabled      = SsoRegistry::isEnabled(SsoRegistry::MICROSOFT);
        $microsoftClientId     = SsoRegistry::microsoftClientId();
        $microsoftTenant       = (string)($settings[SsoRegistry::SETTING_MS_TENANT] ?? '');
        $microsoftTenantIsNamed = SsoRegistry::microsoftTenantIsNamed();
        // Permissions déléguées que les connecteurs Microsoft demanderont à
        // CETTE application (ils réutilisent l'inscription du login). Lues au
        // registre : ajouter un connecteur met l'écran à jour tout seul.
        $microsoftDelegatedScopes = ConnectorRegistry::delegatedScopes('microsoft');

        $passwordEnabled = SsoRegistry::passwordEnabled();
        $allowedDomains  = implode("\n", SsoRegistry::allowedDomains());
        $secoursActif    = LOCAL_PASSWORD_HASH !== '';
        $comptesMotDePasse = $this->comptesAvecMotDePasse();
        $loginMessage    = SsoRegistry::loginMessage();

        // Section qui vient d'être enregistrée : la confirmation s'affiche DANS
        // sa carte (et la redirection y ancre la page), pas en tête d'un écran
        // que l'administrateur a quitté des yeux en cliquant en bas.
        $savedSection = (string)(Session::getFlash('sso_saved_section') ?? '');

        require ROOT . '/templates/admin/sso.php';
    }

    /**
     * POST /admin/sso/settings — enregistre UNE section de la page.
     *
     * Pas d'enregistrement automatique ici, et pas de formulaire unique : chaque
     * carte (Google, Microsoft, mot de passe, domaines, page de connexion) est
     * son propre formulaire, posté au clic sur SON bouton « Enregistrer ». Le
     * champ `section` dit laquelle, et le handler ne touche qu'à elle — un
     * POST partiel lu comme le formulaire entier éteindrait les interrupteurs
     * absents (case absente = décochée). Section inconnue = rien d'écrit.
     */
    public function saveSso(): void
    {
        AuthMiddleware::requirePermission('sso.edit');
        $asJson  = AjaxResponse::wanted();
        $section = (string)($_POST['section'] ?? '');
        if (!in_array($section, SsoRegistry::SECTIONS, true)) {
            AjaxResponse::abort($asJson, 'sso_error', t('Section inconnue : rien n\'a été enregistré.'), '/admin/sso', 400);
        }
        $retour = '/admin/sso#sso-' . $section;

        switch ($section) {
            case SsoRegistry::SECTION_GOOGLE:
                // Identifiants d'abord : la garde anti-verrouillage se prononce
                // sur ce qui sera utilisable APRÈS enregistrement.
                if (!empty($_POST['remove_google_credentials'])) {
                    Settings::set(SsoRegistry::SETTING_GOOGLE_CLIENT, null);
                    Settings::set(SsoRegistry::SETTING_GOOGLE_SECRET, null);
                } else {
                    if (isset($_POST['google_client_id'])) {
                        Settings::set(SsoRegistry::SETTING_GOOGLE_CLIENT, trim((string)$_POST['google_client_id']));
                    }
                    // Champ vide = secret conservé : il n'est jamais renvoyé au
                    // client, l'écraser par une chaîne vide effacerait une
                    // configuration valide.
                    if (trim((string)($_POST['google_client_secret'] ?? '')) !== '') {
                        Settings::set(
                            SsoRegistry::SETTING_GOOGLE_SECRET,
                            Encryption::encrypt(trim((string)$_POST['google_client_secret']))
                        );
                    }
                }
                $message = t('Google enregistré.');
                break;

            case SsoRegistry::SECTION_MICROSOFT:
                if (!empty($_POST['remove_microsoft_credentials'])) {
                    Settings::set(SsoRegistry::SETTING_MS_CLIENT, null);
                    Settings::set(SsoRegistry::SETTING_MS_SECRET, null);
                } else {
                    if (isset($_POST['microsoft_client_id'])) {
                        Settings::set(SsoRegistry::SETTING_MS_CLIENT, trim((string)$_POST['microsoft_client_id']));
                    }
                    if (trim((string)($_POST['microsoft_client_secret'] ?? '')) !== '') {
                        Settings::set(
                            SsoRegistry::SETTING_MS_SECRET,
                            Encryption::encrypt(trim((string)$_POST['microsoft_client_secret']))
                        );
                    }
                }
                if (isset($_POST['microsoft_tenant'])) {
                    Settings::set(SsoRegistry::SETTING_MS_TENANT, SsoRegistry::normalizeTenant((string)$_POST['microsoft_tenant']));
                }
                $message = t('Microsoft enregistré.');
                break;

            case SsoRegistry::SECTION_PASSWORD:
                $message = t('Connexion par e-mail et mot de passe enregistrée.');
                break;

            case SsoRegistry::SECTION_DOMAINS:
                Settings::set(
                    SsoRegistry::SETTING_ALLOWED_DOMAINS,
                    implode("\n", SsoRegistry::normalizeDomains((string)($_POST['allowed_domains'] ?? '')))
                );
                $message = t('Domaines autorisés enregistrés.');
                break;

            default: // SECTION_LOGIN
                Settings::set(
                    SsoRegistry::SETTING_LOGIN_MESSAGE,
                    SsoRegistry::normalizeLoginMessage((string)($_POST['login_message'] ?? ''))
                );
                $message = t('Message de la page de connexion enregistré.');
                break;
        }

        // Interrupteur de la section, s'il y en a un. Convention maison : case
        // absente du POST = décochée — mais SEULEMENT pour la section postée.
        // Les deux autres méthodes gardent leur état enregistré : c'est
        // mergeDesiredState qui les fournit à la garde anti-verrouillage.
        // Un fournisseur activé mais sans identifiants ne connecte personne :
        // la garde raisonne sur « utilisable », pas sur « coché ».
        $corrige = false;
        if (in_array($section, [SsoRegistry::GOOGLE, SsoRegistry::MICROSOFT, SsoRegistry::PASSWORD], true)) {
            $coche    = !empty($_POST[$section . '_enabled']);
            $souhaite = $section === SsoRegistry::PASSWORD ? $coche : ($coche && SsoRegistry::isConfigured($section));
            $etat     = SsoRegistry::guardLockout(SsoRegistry::mergeDesiredState(SsoRegistry::usableState(), $section, $souhaite));
            $corrige  = $etat['corrige'];

            SsoRegistry::setEnabled($section, $section === SsoRegistry::PASSWORD ? $etat[SsoRegistry::PASSWORD] : $coche);
            if ($corrige) {
                SsoRegistry::setEnabled(SsoRegistry::PASSWORD, true);
            }
        }

        // La confirmation s'affiche dans la carte de la section (vue) : sans
        // JavaScript seulement, un appelant JSON n'a pas de page à ancrer.
        if (!$asJson) {
            Session::flash('sso_saved_section', $section);
        }
        if ($corrige) {
            $avertissement = t('Aucune méthode de connexion utilisable ne serait restée : la connexion par e-mail et mot de passe a été réactivée.');
            if ($asJson) {
                AjaxResponse::send(['ok' => true, 'warning' => $avertissement]);
            }
            Session::flash('sso_error', $avertissement);
            header('Location: ' . $retour);
            exit;
        }

        AjaxResponse::finish($asJson, 'sso_success', $message, $retour);
    }

    /**
     * POST /admin/sso/test-credentials — vérifie des identifiants SANS ouvrir
     * d'écran de consentement. Les valeurs saisies mais pas encore enregistrées
     * sont acceptées et priment, comme pour les sources synchronisées : on veut
     * pouvoir tester avant d'enregistrer.
     */
    public function testSsoCredentials(): void
    {
        AuthMiddleware::requirePermission('sso.edit');
        header('Content-Type: application/json; charset=utf-8');

        $cle = (string)($_POST['provider'] ?? '');
        if (!in_array($cle, SsoRegistry::PROVIDERS, true)) {
            echo json_encode(['ok' => false, 'error' => t('Fournisseur inconnu.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Surcharges de session : SsoRegistry lit `Settings`, dont le cache
        // mémoire est justement fait pour ça — on y injecte les valeurs saisies
        // le temps de la requête, sans rien écrire en base.
        $surcharges = [
            SsoRegistry::SETTING_GOOGLE_CLIENT => 'google_client_id',
            SsoRegistry::SETTING_MS_CLIENT     => 'microsoft_client_id',
            SsoRegistry::SETTING_MS_TENANT     => 'microsoft_tenant',
        ];
        foreach ($surcharges as $reglage => $champ) {
            $valeur = trim((string)($_POST[$champ] ?? ''));
            if ($valeur !== '') {
                Settings::override($reglage, $valeur);
            }
        }
        foreach ([SsoRegistry::SETTING_GOOGLE_SECRET => 'google_client_secret',
                  SsoRegistry::SETTING_MS_SECRET     => 'microsoft_client_secret'] as $reglage => $champ) {
            $valeur = trim((string)($_POST[$champ] ?? ''));
            if ($valeur !== '') {
                Settings::override($reglage, Encryption::encrypt($valeur));
            }
        }

        $provider = SsoRegistry::provider($cle);
        $verdict  = $provider === null
            ? ['ok' => false, 'error' => t('Fournisseur inconnu.')]
            : $provider->testCredentials();

        echo json_encode($verdict, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Comptes actifs disposant d'un mot de passe (carte de la page SSO). */
    private function comptesAvecMotDePasse(): int
    {
        return (int)Database::getInstance()
            ->query("SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL AND password_hash <> '' AND is_active = 1")
            ->fetchColumn();
    }

    /**
     * Enregistrement des identifiants de synchronisation. Route dédiée : le
     * module d'auto-enregistrement reposte le formulaire ENTIER sur sa propre
     * action, donc un formulaire partiel qui viserait `/admin/settings`
     * rejouerait les écritures inconditionnelles de cette page-là.
     */
    public function saveKnowledgeBaseSyncSettings(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.manage');

        // Google Drive : clé API chiffrée (champ vide = clé inchangée — la
        // valeur n'est jamais renvoyée au client).
        if (!empty($_POST['remove_google_drive_api_key'])) {
            Settings::set(KbSync::SETTING_DRIVE_API_KEY, null);
        } elseif (trim((string)($_POST['google_drive_api_key'] ?? '')) !== '') {
            Settings::set(
                KbSync::SETTING_DRIVE_API_KEY,
                Encryption::encrypt(trim((string)$_POST['google_drive_api_key']))
            );
        }

        // SharePoint : identifiants d'app Azure. Tenant et client id sont de
        // simples identifiants (réaffichés) ; seul le secret est chiffré et
        // jamais renvoyé au client (champ vide = inchangé).
        if (!empty($_POST['remove_sharepoint_credentials'])) {
            Settings::set(KbSync::SETTING_SP_TENANT, null);
            Settings::set(KbSync::SETTING_SP_CLIENT, null);
            Settings::set(KbSync::SETTING_SP_SECRET, null);
        } else {
            if (isset($_POST['sharepoint_tenant_id'])) {
                Settings::set(KbSync::SETTING_SP_TENANT, trim((string)$_POST['sharepoint_tenant_id']) ?: null);
            }
            if (isset($_POST['sharepoint_client_id'])) {
                Settings::set(KbSync::SETTING_SP_CLIENT, trim((string)$_POST['sharepoint_client_id']) ?: null);
            }
            if (trim((string)($_POST['sharepoint_client_secret'] ?? '')) !== '') {
                Settings::set(
                    KbSync::SETTING_SP_SECRET,
                    Encryption::encrypt(trim((string)$_POST['sharepoint_client_secret']))
                );
            }
        }

        // Microsoft OneDrive : application Azure en permissions DÉLÉGUÉES
        // (chaque utilisateur connecte SON compte). Tenant et client id sont
        // réaffichés ; seul le secret est chiffré et jamais renvoyé au client
        // (champ vide = inchangé).
        if (!empty($_POST['remove_microsoft_credentials'])) {
            Settings::set(MicrosoftOAuth::SETTING_TENANT, null);
            Settings::set(MicrosoftOAuth::SETTING_CLIENT, null);
            Settings::set(MicrosoftOAuth::SETTING_SECRET, null);
        } else {
            if (isset($_POST['microsoft_tenant_id'])) {
                Settings::set(MicrosoftOAuth::SETTING_TENANT, trim((string)$_POST['microsoft_tenant_id']) ?: null);
            }
            if (isset($_POST['microsoft_client_id'])) {
                Settings::set(MicrosoftOAuth::SETTING_CLIENT, trim((string)$_POST['microsoft_client_id']) ?: null);
            }
            if (trim((string)($_POST['microsoft_client_secret'] ?? '')) !== '') {
                Settings::set(
                    MicrosoftOAuth::SETTING_SECRET,
                    Encryption::encrypt(trim((string)$_POST['microsoft_client_secret']))
                );
            }
        }

        // Google Agenda : clé API dédiée, chiffrée (champ vide = clé
        // inchangée — la valeur n'est jamais renvoyée au client).
        if (!empty($_POST['remove_google_calendar_api_key'])) {
            Settings::set(KbSync::SETTING_GCAL_API_KEY, null);
        } elseif (trim((string)($_POST['google_calendar_api_key'] ?? '')) !== '') {
            Settings::set(
                KbSync::SETTING_GCAL_API_KEY,
                Encryption::encrypt(trim((string)$_POST['google_calendar_api_key']))
            );
        }

        // Intervalle entre deux cycles, commun à toutes les sources distantes.
        if (isset($_POST['drive_sync_interval_min'])) {
            $interval = (int)$_POST['drive_sync_interval_min'];
            Settings::set(
                KbSync::SETTING_INTERVAL,
                (string)max(1, min(1440, $interval ?: KbSync::DEFAULT_INTERVAL_MIN))
            );
        }

        AjaxResponse::finish(
            AjaxResponse::wanted(),
            'kb_sync_settings_success',
            t('Sources synchronisées enregistrées.'),
            '/admin/knowledge-bases/sync'
        );
    }

    /**
     * Bouton « Tester la connexion » des sources synchronisées : vérifie les
     * identifiants globaux d'un fournisseur (Google Drive / SharePoint /
     * Google Agenda) sans dossier. Les valeurs saisies dans le formulaire (non
     * enregistrées) priment sur celles stockées.
     */
    public function testSyncConnection(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.manage');
        header('Content-Type: application/json; charset=utf-8');

        $provider = (string)($_POST['provider'] ?? '');
        if (!in_array($provider, KbSync::PROVIDERS, true)) {
            echo json_encode(['ok' => false, 'error' => t('Source de synchronisation inconnue.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(KbSync::testConnection($provider, [
            'api_key'          => (string)($_POST['google_drive_api_key'] ?? ''),
            'tenant_id'        => (string)($_POST['sharepoint_tenant_id'] ?? ''),
            'client_id'        => (string)($_POST['sharepoint_client_id'] ?? ''),
            'client_secret'    => (string)($_POST['sharepoint_client_secret'] ?? ''),
            'calendar_api_key' => (string)($_POST['google_calendar_api_key'] ?? ''),
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function saveSettings(): void
    {
        AuthMiddleware::requirePermission('company.edit');

        // Champs texte simples
        // docs_url, feedback_url, contact_email, vendor_url et vendor_name étaient codés
        // en dur dans les gabarits : sur une installation ailleurs, les liens
        // pointaient vers l'éditeur. Une valeur vide désactive l'élément.
        foreach ([
            'app_name', 'app_tagline', 'theme', 'accent_color', 'welcome_message',
            // Langue servie à qui n'a rien choisi (cf. I18n::resolve). Un
            // <select> est TOUJOURS présent dans le POST : contrairement à une
            // case à cocher, l'écrire ici ne peut pas l'effacer par surprise.
            I18n::SETTING_DEFAULT,
            'mobile_testflight_url',
            'docs_url', 'feedback_url', 'contact_email', 'vendor_url', 'vendor_name',
        ] as $key) {
            if (isset($_POST[$key])) {
                $value = trim((string) $_POST[$key]);
                if ($key === 'app_tagline') {
                    $value = preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $value)) ?? $value;
                    $value = trim($value);
                }
                if ($key === 'mobile_testflight_url' && $value !== ''
                    && (!filter_var($value, FILTER_VALIDATE_URL) || !str_starts_with($value, 'https://'))) {
                    continue; // lien TestFlight invalide : on ignore (vide reste autorisé)
                }
                if ($key === I18n::SETTING_DEFAULT && $value !== '' && !I18n::isSupported($value)) {
                    continue; // langue inconnue : on garde la valeur en place
                }
                Settings::set($key, $value);
            }
        }
        // Rien d'inconditionnel ici. `new_users_active` (page Utilisateurs) et
        // les interrupteurs des connecteurs (/admin/connectors) vivent ailleurs :
        // les écrire ici aussi les éteindrait à CHAQUE enregistrement de cette
        // page, puisqu'une case absente du POST vaut « décochée ».

        // Upload logo principal
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];
            $mime = mime_content_type($file['tmp_name']);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'])) {
                $ext  = match($mime) {
                    'image/png'     => 'png',
                    'image/jpeg'    => 'jpg',
                    'image/gif'     => 'gif',
                    'image/webp'    => 'webp',
                    'image/svg+xml' => 'svg',
                    default         => 'png',
                };
                $brandingDir = ROOT . '/public/assets/img/branding';
                if (!is_dir($brandingDir)) {
                    @mkdir($brandingDir, 0775, true);
                }
                // Nettoie d'éventuelles versions précédentes (extensions différentes)
                foreach (glob($brandingDir . '/logo-custom.*') ?: [] as $old) {
                    @unlink($old);
                }
                $dest = $brandingDir . '/logo-custom.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    Settings::set('logo_url', '/assets/img/branding/logo-custom.' . $ext . '?v=' . time());
                }
            }
        }

        // Upload logo hover (optionnel)
        if (!empty($_FILES['logo_hover']['tmp_name']) && $_FILES['logo_hover']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo_hover'];
            $mime = mime_content_type($file['tmp_name']);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'])) {
                $ext  = match($mime) {
                    'image/png'  => 'png',
                    'image/jpeg' => 'jpg',
                    'image/gif'  => 'gif',
                    default      => 'png',
                };
                $brandingDir = ROOT . '/public/assets/img/branding';
                if (!is_dir($brandingDir)) {
                    @mkdir($brandingDir, 0775, true);
                }
                foreach (glob($brandingDir . '/logo-hover.*') ?: [] as $old) {
                    @unlink($old);
                }
                $dest = $brandingDir . '/logo-hover.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    Settings::set('logo_hover_url', '/assets/img/branding/logo-hover.' . $ext . '?v=' . time());
                }
            }
        }

        // Suppression logo hover
        if (!empty($_POST['remove_logo_hover'])) {
            Settings::set('logo_hover_url', '');
        }

        // L'entreprise (nom, descriptif, logo, modèle PowerPoint) a sa page,
        // /admin/company : rien ici ne l'écrit, même si un champ homonyme
        // arrivait dans ce POST.

        // Les deux URL de logo portent un cache-buster recalculé à chaque
        // téléversement : sans rechargement de page, l'écran ne peut rafraîchir
        // ses aperçus que si on les lui renvoie.
        AjaxResponse::finish(
            AjaxResponse::wanted(),
            'settings_success',
            t('Paramètres enregistrés.'),
            '/admin/settings',
            [
                'logo_url'       => Settings::get('logo_url', '/assets/img/logo.png'),
                'logo_hover_url' => Settings::get('logo_hover_url', ''),
            ]
        );
    }

    /**
     * Enregistrement de la page « Entreprise ». Le logo et l'état des modèles
     * sont renvoyés tout faits : la page ne se recharge pas, et l'analyse d'un
     * modèle (ou son refus) se lit dans le bloc repeint.
     */
    public function saveCompany(): void
    {
        AuthMiddleware::requirePermission('company.edit');

        $errors = $this->saveCompanyProfile();

        $templateError     = $errors['pptx'];
        $templateInfo      = CompanyProfile::templateInfo();
        $wordTemplateError = $errors['docx'];
        $wordTemplateInfo  = CompanyProfile::wordTemplateInfo();
        ob_start();
        require ROOT . '/templates/partials/company-template-status.php';
        $templateHtml = (string)ob_get_clean();
        ob_start();
        require ROOT . '/templates/partials/company-word-template-status.php';
        $wordTemplateHtml = (string)ob_get_clean();

        $refus = array_filter([
            $templateError !== '' ? t('Modèle PowerPoint refusé : {motif}', ['motif' => $templateError]) : '',
            $wordTemplateError !== '' ? t('Modèle Word refusé : {motif}', ['motif' => $wordTemplateError]) : '',
        ]);

        AjaxResponse::finish(
            AjaxResponse::wanted(),
            'company_success',
            $refus !== [] ? t('Entreprise enregistrée.') . ' ' . implode(' ', $refus) : t('Entreprise enregistrée.'),
            '/admin/company',
            [
                'company_logo_url'            => CompanyProfile::logoUrl(),
                'company_template_set'        => $templateInfo !== null,
                'company_template_html'       => $templateHtml,
                'company_template_error'      => $templateError,
                'company_word_template_set'   => $wordTemplateInfo !== null,
                'company_word_template_html'  => $wordTemplateHtml,
                'company_word_template_error' => $wordTemplateError,
            ]
        );
    }

    /**
     * Page « Entreprise ». Champs lus SEULEMENT s'ils sont postés (piège
     * maison : un champ absent n'est pas un champ vidé). Rend le motif du
     * refus de chaque modèle ('' sinon) — un modèle refusé n'empêche pas
     * d'enregistrer le reste de la page.
     *
     * @return array{pptx: string, docx: string}
     */
    private function saveCompanyProfile(): array
    {
        if (isset($_POST[CompanyProfile::SETTING_NAME])) {
            Settings::set(CompanyProfile::SETTING_NAME, CompanyProfile::sanitizeName((string)$_POST[CompanyProfile::SETTING_NAME]));
        }
        if (isset($_POST[CompanyProfile::SETTING_DESCRIPTION])) {
            Settings::set(CompanyProfile::SETTING_DESCRIPTION, CompanyProfile::sanitizeDescription((string)$_POST[CompanyProfile::SETTING_DESCRIPTION]));
        }
        if (isset($_POST[CompanyProfile::SETTING_LEGAL])) {
            Settings::set(CompanyProfile::SETTING_LEGAL, CompanyProfile::sanitizeLegal((string)$_POST[CompanyProfile::SETTING_LEGAL]));
        }
        // Vocabulaire de l'entreprise : venu de Modèles IA → Modèles (même clé).
        if (isset($_POST[CompanyVocabulary::SETTING])) {
            Settings::set(CompanyVocabulary::SETTING, CompanyVocabulary::sanitize((string)$_POST[CompanyVocabulary::SETTING]));
        }

        // Logo : PNG ou JPG seulement — ce que PowerPoint, Word et le PDF dessinent.
        $logoDir = ROOT . CompanyProfile::LOGO_DIR;
        if (!empty($_FILES['company_logo']['tmp_name']) && ($_FILES['company_logo']['error'] ?? 1) === UPLOAD_ERR_OK) {
            $mime = (string)mime_content_type($_FILES['company_logo']['tmp_name']);
            $ext  = CompanyProfile::LOGO_TYPES[$mime] ?? null;
            if ($ext !== null) {
                if (!is_dir($logoDir)) {
                    @mkdir($logoDir, 0775, true);
                }
                foreach (glob($logoDir . '/' . CompanyProfile::LOGO_STEM . '.*') ?: [] as $old) {
                    @unlink($old);
                }
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $logoDir . '/' . CompanyProfile::LOGO_STEM . '.' . $ext)) {
                    Settings::set(CompanyProfile::SETTING_LOGO, '/assets/img/branding/' . CompanyProfile::LOGO_STEM . '.' . $ext . '?v=' . time());
                }
            }
        } elseif (!empty($_POST['remove_company_logo'])) {
            foreach (glob($logoDir . '/' . CompanyProfile::LOGO_STEM . '.*') ?: [] as $old) {
                @unlink($old);
            }
            Settings::set(CompanyProfile::SETTING_LOGO, '');
        }

        return [
            'pptx' => $this->storeCompanyTemplate('pptx'),
            'docx' => $this->storeCompanyTemplate('docx'),
        ];
    }

    /**
     * Dépôt ou retrait d'un modèle de l'entreprise — PowerPoint ('pptx') ou
     * Word ('docx'). Analysé AVANT d'être gardé ; refusé, l'ancien reste.
     * Rend le motif du refus, '' sinon.
     */
    private function storeCompanyTemplate(string $kind): string
    {
        $spec = $kind === 'docx'
            ? ['field' => 'company_word_template', 'remove' => 'remove_company_word_template', 'exts' => ['docx', 'dotx'],
               'file' => CompanyProfile::WORD_TEMPLATE_FILE, 'setting' => CompanyProfile::SETTING_WORD_TEMPLATE,
               'wrong' => t('déposez un fichier Word .docx ou .dotx (un .doc ancien s’enregistre en .docx depuis Word).'),
               'label' => 'Word']
            : ['field' => 'company_template', 'remove' => 'remove_company_template', 'exts' => ['pptx', 'potx'],
               'file' => CompanyProfile::TEMPLATE_FILE, 'setting' => CompanyProfile::SETTING_TEMPLATE,
               'wrong' => t('déposez un fichier PowerPoint .pptx ou .potx (un .ppt ancien s’enregistre en .pptx depuis PowerPoint).'),
               'label' => 'PowerPoint'];

        $dir  = CompanyProfile::templateDir();
        $dest = $dir . '/' . $spec['file'];
        $file = $_FILES[$spec['field']] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $name = basename((string)($file['name'] ?? ''));
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                return in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? t('fichier trop volumineux (20 Mo au plus).')
                    : t('le fichier n’a pas pu être reçu.');
            }
            if (!in_array($ext, $spec['exts'], true)) {
                return $spec['wrong'];
            }
            if ((int)($file['size'] ?? 0) > CompanyProfile::TEMPLATE_MAX_BYTES) {
                return t('fichier trop volumineux (20 Mo au plus).');
            }
            $info = $kind === 'docx'
                ? DocxTemplate::analyze((string)$file['tmp_name'])
                : PptxTemplate::analyze((string)$file['tmp_name']);
            if (empty($info['ok'])) {
                return (string)($info['error'] ?? t('ce fichier n’est pas un modèle {type} lisible.', ['type' => $spec['label']]));
            }
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                error_log('[Marque] Dossier des modèles impossible à créer : ' . $dir);

                return t('le modèle n’a pas pu être enregistré sur le serveur.');
            }
            // Écriture atomique : un remplissage en cours ne lit jamais un fichier à moitié copié.
            $tmp = $dir . '/.upload-' . bin2hex(random_bytes(6));
            if (!move_uploaded_file((string)$file['tmp_name'], $tmp) || !@rename($tmp, $dest)) {
                @unlink($tmp);
                error_log('[Marque] Modèle ' . $spec['label'] . ' impossible à écrire dans ' . $dir);

                return t('le modèle n’a pas pu être enregistré sur le serveur.');
            }
            CompanyProfile::forgetThumbnail($kind, $dir);
            // `size` de l'analyse PowerPoint = dimensions des slides ; celui de la fiche = poids du fichier.
            if (isset($info['size'])) {
                $info['slide_size'] = $info['size'];
            }
            unset($info['ok'], $info['size']);
            Settings::set($spec['setting'], (string)json_encode(
                ['name' => mb_substr($name, 0, 120), 'size' => (int)$file['size'], 'uploaded_at' => date('Y-m-d H:i:s')] + $info,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } elseif (!empty($_POST[$spec['remove']])) {
            @unlink($dest);
            CompanyProfile::forgetThumbnail($kind, $dir);
            Settings::set($spec['setting'], '');
        }

        return '';
    }

    /**
     * Ce que l'assistant lit (page Entreprise) : les fragments EXACTS de
     * prompt que produiraient les champs en cours de saisie — même s'ils ne
     * sont pas encore enregistrés —, avec leur poids, et pour une question
     * d'essai, les entrées du vocabulaire qui seraient retenues. N'écrit rien.
     */
    public function previewCompany(): void
    {
        AuthMiddleware::requirePermission('company.view');

        $company  = CompanyProfile::fragmentFrom(
            (string)($_POST[CompanyProfile::SETTING_NAME] ?? CompanyProfile::name()),
            (string)($_POST[CompanyProfile::SETTING_DESCRIPTION] ?? CompanyProfile::description())
        );
        $text     = (string)($_POST[CompanyVocabulary::SETTING] ?? CompanyVocabulary::raw());
        $question = mb_substr(trim((string)($_POST['question'] ?? '')), 0, 2000);
        $context  = CompanyVocabulary::contextFrom($question);
        $parsed   = CompanyVocabulary::parse($text);
        $select   = CompanyVocabulary::select($text, $context);
        $vocab    = CompanyVocabulary::fragmentFrom($text, $context);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'         => true,
            'company'    => ltrim($company),
            'vocabulary' => ltrim($vocab),
            'tokens'     => [
                'company'    => TextChunker::estimateTokens($company),
                'vocabulary' => TextChunker::estimateTokens($vocab),
                'full'       => TextChunker::estimateTokens(CompanyVocabulary::sanitize($text)),
            ],
            'vocab'      => [
                'mode'    => $select['mode'],
                'entries' => count($parsed['entries']),
                'free'    => count($parsed['free']),
                'matched' => $select['matched'],
                'terms'   => $select['terms'],
                'chars'   => mb_strlen(CompanyVocabulary::sanitize($text)),
                'full'    => CompanyVocabulary::FULL_CHARS,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Document d'essai (page Entreprise) : une présentation ou un document Word
     * fabriqué EXACTEMENT comme dans la conversation (FileFactory), sur un
     * contenu fixe qui passe par chaque mise en page — modèle de l'entreprise
     * compris. Rien n'est stocké.
     */
    public function companySample(string $kind): void
    {
        AuthMiddleware::requirePermission('company.view');
        if (!in_array($kind, ['pptx', 'docx'], true)) {
            http_response_code(404);
            exit;
        }

        try {
            $file = FileFactory::build(
                'essai-' . ($kind === 'pptx' ? 'presentation' : 'document') . '.' . $kind,
                CompanyProfile::sampleMarkdown($kind),
                'markdown',
                '',
                ['author' => (string)(Session::currentUser()['name'] ?? 'MIA')]
            );
        } catch (FileBuildException $e) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }

        header('Content-Type: ' . $file->mime);
        header('Content-Disposition: ' . FileNaming::contentDisposition($file->name));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . $file->size());
        echo $file->bytes;
        exit;
    }

    /**
     * Miniature d'un modèle (celle qu'Office a enregistrée dans le fichier).
     * Servie à l'administrateur seulement : le modèle vit hors de la racine
     * web, sa miniature aussi.
     */
    public function companyThumbnail(string $kind): void
    {
        AuthMiddleware::requirePermission('company.view');
        $thumb = CompanyProfile::templateThumbnail($kind);
        if ($thumb === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $thumb['mime']);
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (int)filesize($thumb['path']));
        readfile($thumb['path']);
        exit;
    }

    private function exportCsv(array $logs): void
    {
        $rows = [];
        foreach ($logs as $l) {
            $rows[] = [
                $l['user_name'], $l['email'], $l['profile_name'], $l['llm_provider'],
                $l['tokens_input'], $l['tokens_output'], $l['total_tokens'], $l['created_at'],
            ];
        }
        CsvExport::stream(
            'usage_logs_bruts_' . date('Ymd') . '.csv',
            [t('Utilisateur'), 'Email', 'Assistant', 'LLM', t('Tokens entrée'), t('Tokens sortie'), 'Total', 'Date'],
            $rows
        );
    }

    private function decodeJsonSetting(string $key): array
    {
        $raw = Settings::get($key, '');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJsonSetting(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    private function parsePositiveDecimal(mixed $value, float $default = 0.0): float
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return $default;
        }

        $normalized = str_replace(',', '.', $raw);
        if (!is_numeric($normalized)) {
            return $default;
        }

        $number = (float)$normalized;
        if (!is_finite($number) || $number < 0) {
            return $default;
        }

        return round($number, 6);
    }

    /**
     * Orchestre la récupération des prix : tarifs curés du catalogue d'abord
     * (déterministe, source unique de vérité — garantit les prix exacts des
     * modèles trop récents pour le lookup web, ex. GPT-5.6 / Opus 4.8), puis
     * délègue le reste au lookup LLM + recherche web.
     */
    private function fetchLatestUsdPricingFromInternet(array $services, ?callable $keepAlive = null): array
    {
        // 1) Prix curés depuis config/llm_catalog.php (aucun appel réseau).
        $curated    = [];
        $curatedIds = [];
        foreach ($services as $svc) {
            $hit = $this->resolveCuratedUsdPrice($svc);
            if ($hit !== null) {
                $curated[] = $hit;
                $curatedIds[(string)($svc['id'] ?? '')] = true;
            }
        }

        // 2) Services restants → lookup LLM (clé requise seulement s'il en reste).
        $remaining = array_values(array_filter(
            $services,
            static fn($s) => !isset($curatedIds[(string)($s['id'] ?? '')])
        ));

        if (empty($remaining)) {
            return [
                'engine'     => 'catalogue',
                'updated'    => $curated,
                'not_found'  => [],
                'checked_at' => gmdate('c'),
            ];
        }

        $llm    = $this->fetchUsdPricingViaLlm($remaining, $keepAlive);
        $engine = $curated !== []
            ? 'catalogue + ' . (string)($llm['engine'] ?? 'web')
            : (string)($llm['engine'] ?? 'web');

        return [
            'engine'     => $engine,
            'updated'    => array_merge($curated, (array)($llm['updated'] ?? [])),
            'not_found'  => (array)($llm['not_found'] ?? []),
            'checked_at' => (string)($llm['checked_at'] ?? gmdate('c')),
        ];
    }

    /**
     * Résout le prix USD/1M tokens d'un service LLM depuis le catalogue (tarif
     * curé). Retourne un item `updated` prêt à l'emploi ou null si non couvert
     * (le service repart alors vers le lookup web).
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>|null
     */
    private function resolveCuratedUsdPrice(array $service): ?array
    {
        $id = trim((string)($service['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        // Embeddings, recherche web, dictée : tarifs curés des catalogues,
        // jamais cherchés sur le web (ils n'ont pas de page de prix par modèle
        // qu'un LLM saurait lire de façon fiable).
        $curated = $this->resolveCuratedNonLlmUsdPrice((string)($service['catalog_key'] ?? ''));
        if ($curated !== null) {
            return ['id' => $id] + $curated;
        }

        $parsed = $this->parseLlmCatalogKeyServer((string)($service['catalog_key'] ?? ''));
        if ($parsed === null) {
            return null;
        }

        // Tarif NORMAL, jamais un tarif de lancement : enregistré, il primerait
        // sur le catalogue bien après la fin de la remise (sous-facturation
        // muette). Le lancement en cours est seulement signalé dans la note.
        $pricing = LlmCatalog::standardPricing($parsed['provider'], $parsed['model']);
        if ($pricing === null) {
            return null;
        }
        $launchUntil = (string)(LlmCatalog::pricing($parsed['provider'], $parsed['model'])['launch_until'] ?? '');

        $input  = isset($pricing['input'])  ? (float)$pricing['input']  : null;
        $output = isset($pricing['output']) ? (float)$pricing['output'] : null;

        $usd = match ($parsed['variant']) {
            'input'             => $input,
            'output'            => $output,
            'input_cached'      => isset($pricing['cached_input'])
                ? (float)$pricing['cached_input']
                : ($input !== null ? $input * CreditService::DEFAULT_CACHED_RATIO : null),
            'input_cache_write' => isset($pricing['cache_write'])
                ? (float)$pricing['cache_write']
                : ($input !== null ? $input * CreditService::DEFAULT_CACHE_WRITE_RATIO : null),
            'output_reasoning'  => isset($pricing['output_reasoning'])
                ? (float)$pricing['output_reasoning']
                : $output,
            default             => null,
        };

        if ($usd === null || $usd < 0) {
            return null;
        }

        $variantLabels = [
            'input'             => 'input',
            'output'            => 'output',
            'input_cached'      => t('input (cache lu)'),
            'input_cache_write' => 'input (cache write)',
            'output_reasoning'  => 'output (reasoning)',
        ];
        $vlabel = $variantLabels[$parsed['variant']] ?? $parsed['variant'];
        $note   = trim((string)($pricing['note'] ?? t('Tarif catalogue (source unique de vérité).')));
        if ($launchUntil !== '') {
            $note .= ' ' . t('Tarif normal enregistré : la remise de lancement (jusqu\'au {date}) n\'est appliquée automatiquement que sans tarif saisi ici.', [
                'date' => $launchUntil,
            ]);
        }

        return [
            'id'            => $id,
            'real_cost_usd' => round((float)$usd, 6),
            'source_url'    => trim((string)($pricing['source_url'] ?? '')),
            'source_note'   => $note . ' — ' . $parsed['model'] . ' / ' . $vlabel . ' (USD / 1M tokens).',
        ];
    }

    /**
     * Parse une clé catalogue de service LLM `llm:<provider>::<model>:<variant>`.
     * variant ∈ input | output | input_cached | output_reasoning | input_cache_write.
     *
     * @return array{provider:string, model:string, variant:string}|null
     */
    /**
     * Tarif curé d'un service hors LLM de chat, d'après sa `catalog_key` :
     *   - `embedding:{provider}::{model}` → `pricing_usd_per_1m` du catalogue d'embeddings ;
     *   - `websearch:{provider}`          → `web_search_pricing_usd_per_1k` / 1000 ;
     *   - `transcribe:openai::{model}`    → `transcription_pricing_usd_per_minute`.
     * Null si la clé n'est pas de ces formes ou si le catalogue ne tarife pas.
     *
     * @return array{real_cost_usd:float, source_url:string, source_note:string}|null
     */
    private function resolveCuratedNonLlmUsdPrice(string $catalogKey): ?array
    {
        $key = trim($catalogKey);
        if (str_starts_with($key, 'embedding:')) {
            $body = substr($key, strlen('embedding:'));
            if (!str_contains($body, '::')) {
                return null;
            }
            [$provider, $model] = explode('::', $body, 2);
            $usd = EmbeddingService::catalog()[$provider]['models'][$model]['pricing_usd_per_1m'] ?? null;
            return is_numeric($usd)
                ? ['real_cost_usd' => (float)$usd, 'source_url' => '', 'source_note' => t('Tarif catalogue embeddings (USD / 1M tokens).')]
                : null;
        }
        if (str_starts_with($key, 'websearch:')) {
            $provider = substr($key, strlen('websearch:'));
            $usdPer1k = LlmCatalog::provider($provider)['web_search_pricing_usd_per_1k'] ?? null;
            return is_numeric($usdPer1k)
                ? ['real_cost_usd' => (float)$usdPer1k / 1000, 'source_url' => '', 'source_note' => t('Tarif catalogue : {prix} $ les 1 000 recherches.', ['prix' => (float)$usdPer1k])]
                : null;
        }
        if (str_starts_with($key, 'transcribe:openai::')) {
            $model = substr($key, strlen('transcribe:openai::'));
            $table = LlmCatalog::provider('openai')['transcription_pricing_usd_per_minute'] ?? [];
            $usd   = $table[$model] ?? ($table['default'] ?? null);
            return is_numeric($usd)
                ? ['real_cost_usd' => (float)$usd, 'source_url' => '', 'source_note' => t('Tarif catalogue dictée (USD / minute).')]
                : null;
        }
        if (str_starts_with($key, 'speech:openai::')) {
            $model = substr($key, strlen('speech:openai::'));
            $table = LlmCatalog::provider('openai')['speech_pricing_usd_per_minute'] ?? [];
            $usd   = $table[$model] ?? ($table['default'] ?? null);
            return is_numeric($usd)
                ? ['real_cost_usd' => (float)$usd, 'source_url' => '', 'source_note' => t('Tarif catalogue lecture à voix haute (USD / minute d\'audio produit).')]
                : null;
        }
        // Ligne d'un outil image ou vidéo : tarif public du modèle au
        // catalogue des outils — à l'image, ou à la seconde quand toutes les
        // tailles publiées ont le même prix. Un modèle tarifé aux tokens est
        // laissé de côté : son coût dépend de la taille et de la qualité de
        // chaque image, CreditService le calcule image par image depuis la
        // consommation renvoyée.
        if (preg_match('/^tool:(\d+)$/', $key, $m) === 1) {
            $tool    = ChatTool::findById((int)$m[1]);
            $pricing = $tool ? CreditService::catalogToolPricing((string)$tool['type'], (string)$tool['model']) : null;
            if ($pricing === null) {
                return null;
            }
            $source = (string)($pricing['source_url'] ?? '');
            $note   = trim((string)($pricing['note'] ?? ''));
            if (is_numeric($pricing['per_image_usd'] ?? null)) {
                return ['real_cost_usd' => (float)$pricing['per_image_usd'], 'source_url' => $source,
                        'source_note' => t('Tarif catalogue outils (USD / image).') . ' ' . $note];
            }
            $parSeconde = array_values(array_unique(array_map('floatval', (array)($pricing['per_second_usd'] ?? []))));
            if (count($parSeconde) === 1) {
                return ['real_cost_usd' => $parSeconde[0], 'source_url' => $source,
                        'source_note' => t('Tarif catalogue outils (USD / seconde, tailles {tailles}).', [
                            'tailles' => implode(', ', array_keys((array)$pricing['per_second_usd'])),
                        ]) . ' ' . $note];
            }
        }
        return null;
    }

    private function parseLlmCatalogKeyServer(string $key): ?array
    {
        $key = trim($key);
        if (!str_starts_with(strtolower($key), 'llm:')) {
            return null;
        }
        $body = substr($key, 4);                 // <provider>::<model>:<variant>
        $sep  = strpos($body, '::');
        if ($sep === false) {
            return null;
        }
        $provider = substr($body, 0, $sep);
        $rest     = substr($body, $sep + 2);      // <model>:<variant>
        $lastColon = strrpos($rest, ':');
        if ($lastColon === false) {
            return null;
        }
        $model   = substr($rest, 0, $lastColon);
        $variant = substr($rest, $lastColon + 1);
        if ($provider === '' || $model === '' || $variant === '') {
            return null;
        }

        return [
            'provider' => strtolower(trim($provider)),
            'model'    => trim($model),
            'variant'  => strtolower(trim($variant)),
        ];
    }

    /** @var array{user_id:?int} contexte d'imputation de la recherche de prix en cours */
    private array $priceRefreshBillingCtx = ['user_id' => null];

    private function fetchUsdPricingViaLlm(array $services, ?callable $keepAlive = null): array
    {
        $openaiKey = ApiKey::getDecrypted('openai');
        if ($openaiKey) {
            return $this->fetchUsdPricingWithOpenAI($services, $openaiKey, $keepAlive);
        }

        $perplexityKey = ApiKey::getDecrypted('perplexity');
        if ($perplexityKey) {
            return $this->fetchUsdPricingWithPerplexity($services, $perplexityKey, $keepAlive);
        }

        throw new \RuntimeException(
            t('Aucune clé OpenAI ou Perplexity active: configurez un fournisseur avec accès web pour synchroniser les coûts.')
        );
    }

    private function fetchUsdPricingWithOpenAI(array $services, string $key, ?callable $keepAlive = null): array
    {
        // Sans modèle enregistré avec la clé : GPT-6 Luna, le moins cher
        // d'OpenAI avec la recherche web, remplaçant désigné de gpt-4.1-mini
        // (utilisé ici jusqu'à son arrêt annoncé du 14/01/2027).
        $model = ApiKey::getModel('openai') ?: 'gpt-6-luna';
        $servicesJson = json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($servicesJson)) {
            throw new \RuntimeException(t('Impossible de sérialiser la liste des services.'));
        }

        $prompt = <<<PROMPT
Tu dois récupérer sur Internet les prix publics officiels (en USD) des services IA listés ci-dessous.
Priorité absolue aux pages de tarification officielles des fournisseurs (openai.com/pricing, mistral.ai/pricing, anthropic.com/pricing, ai.google.dev/pricing, deepseek.com, perplexity.ai/pricing).
Si un prix exact est introuvable, retourne une estimation prudente avec note explicite.

Retourne UNIQUEMENT un JSON valide avec cette structure:
{
  "updates":[
    {"id":"...","real_cost_usd":0.0,"source_url":"https://...","source_note":"..."}
  ],
  "not_found":[
    {"id":"...","reason":"..."}
  ]
}

Règles:
- "real_cost_usd" = coût pour EXACTEMENT l'unité demandée.
- Pour image/vidéo: tenir compte du paramétrage donné dans "tool_config_json" (taille, qualité, durée, ratio...) pour un coût standard cohérent.
- Pour LLM tokens: respecter le sens demandé dans "direction" (input/output).
- **Aliases `*-latest`** : Mistral et d'autres providers utilisent des aliases comme `mistral-large-latest`, `mistral-medium-latest`, `mistral-small-latest`, `claude-sonnet-latest`, `gemini-flash-latest`. Ces aliases doivent ÊTRE résolus vers le modèle phare en production actuellement (ex. `mistral-large-latest` = Mistral Large 3 ; `mistral-medium-latest` = Mistral Medium 3.5) et le prix officiel de cette version doit être renvoyé. Ne JAMAIS retourner not_found pour cause d'« alias marketing ».
- Ne te base pas uniquement sur le nom marketing du service. Priorise le triplet provider + model + tool_config_json pour trouver le prix réellement facturé par API.
- Si le service est une génération d'image Gemini/Nano Banana/Imagen, retourne le prix par image standard cohérent avec les paramètres fournis.
- Utilise uniquement les tarifs payants/production (ignore free tier, quotas gratuits, promos temporaires).
- Si l'unité demandée est "video_second" ou "audio_minute" et que la source officielle facture "par vidéo", "par minute" ou "par requête", convertis explicitement vers l'unité demandée.
- Ne mets jamais de texte hors JSON.
- Inclure tous les services transmis soit dans "updates" soit dans "not_found".

Services:
$servicesJson
PROMPT;

        $payload = json_encode([
            'model'             => $model,
            'input'             => $prompt,
            'tools'             => [['type' => 'web_search']],
            'max_output_tokens' => 16000,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        [$status, $body, $netError] = $this->postJsonPricingRequest(
            'https://api.openai.com/v1/responses',
            $payload,
            $key,
            $keepAlive
        );

        if ($netError !== null) {
            throw new \RuntimeException(t('Appel OpenAI impossible ({erreur}).', ['erreur' => $netError]));
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $snippet = $this->snippetForError($body);
            error_log(
                '[AdminController] OpenAI réponse non-JSON. status=' . $status
                . ' length=' . strlen($body)
                . ' head=' . substr($body, 0, 240)
                . ' tail=' . substr($body, max(0, strlen($body) - 240))
            );
            throw new \RuntimeException(
                t('Réponse OpenAI illisible (HTTP {statut}).', ['statut' => $status])
                . ($snippet !== '' ? ' ' . t('Extrait : {extrait}', ['extrait' => $snippet]) : '')
            );
        }
        if (isset($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? t('Erreur OpenAI inconnue.')) : (string)$json['error'];
            throw new \RuntimeException(t('OpenAI a refusé la requête (HTTP {statut}) : {message}', ['statut' => $status, 'message' => (string)$message]));
        }

        // Consommation de la recherche (tokens + appels `web_search_call`),
        // journalisée avant toute interprétation : une sortie inexploitable
        // a quand même été payée.
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $webSearches = 0;
        foreach ((array)($json['output'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'web_search_call') {
                $webSearches++;
            }
        }
        UsageMeter::llm('openai::' . $model, [
            'input'            => (int)($usage['input_tokens'] ?? 0),
            'output'           => (int)($usage['output_tokens'] ?? 0),
            'input_cached'     => (int)($usage['input_tokens_details']['cached_tokens'] ?? 0),
            'output_reasoning' => (int)($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
            'web_searches'     => $webSearches,
        ], UsageSource::PRICE_REFRESH, $this->priceRefreshBillingCtx);

        $text = trim($this->extractOpenAIResponsesText($json));
        if ($text === '') {
            $statusField = isset($json['status']) ? (string)$json['status'] : '';
            $incomplete  = $json['incomplete_details']['reason'] ?? null;
            $hint = '';
            if ($statusField === 'incomplete' || $incomplete !== null) {
                $hint = ' ' . ($incomplete !== null
                    ? t('Sortie tronquée par OpenAI (raison : {raison}). Augmente max_output_tokens ou réduis la liste de services.', ['raison' => (string)$incomplete])
                    : t('Sortie tronquée par OpenAI. Augmente max_output_tokens ou réduis la liste de services.'));
            }
            error_log(
                '[AdminController] OpenAI output_text vide. status=' . $status
                . ' status_field=' . $statusField
                . ' incomplete=' . (is_string($incomplete) ? $incomplete : 'n/a')
                . ' length=' . strlen($body)
            );
            throw new \RuntimeException(t("OpenAI n'a pas renvoyé de contenu exploitable.") . $hint);
        }

        $parsed = $this->decodePricingModelOutput($text);
        return $this->sanitizePricingUpdatesResult($parsed, $services, 'openai:' . $model);
    }

    private function fetchUsdPricingWithPerplexity(array $services, string $key, ?callable $keepAlive = null): array
    {
        $servicesJson = json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($servicesJson)) {
            throw new \RuntimeException(t('Impossible de sérialiser la liste des services.'));
        }

        $prompt = <<<PROMPT
Récupère les prix publics officiels en USD des services IA fournis.
Sources officielles à privilégier : openai.com/pricing, mistral.ai/pricing, anthropic.com/pricing, ai.google.dev/pricing, deepseek.com, perplexity.ai/pricing.
Retourne UNIQUEMENT un JSON valide:
{
  "updates":[{"id":"...","real_cost_usd":0.0,"source_url":"https://...","source_note":"..."}],
  "not_found":[{"id":"...","reason":"..."}]
}
Chaque service doit apparaître dans updates ou not_found.
- Pour image/vidéo: utiliser les paramètres de "tool_config_json" si présents.
- Pour LLM tokens: respecter "direction" (input/output).
- Pour les alias marketing (ex: Nano Banana), mappe vers le vrai prix API en utilisant provider + model.
- **Aliases `*-latest`** : `mistral-large-latest` = Mistral Large 3, `mistral-medium-latest` = Mistral Medium 3.5, `mistral-small-latest` = Mistral Small dernière génération, `claude-sonnet-latest` = Claude Sonnet courant, `gemini-flash-latest` = Gemini Flash courant, etc. Résous l'alias et renvoie le prix officiel de la version actuelle. Ne JAMAIS retourner not_found pour cause d'« alias ».
- N'utiliser que des tarifs payants/production.
- Convertir explicitement vers l'unité demandée (ex: video_second).

Services:
$servicesJson
PROMPT;

        $payload = json_encode([
            'model'       => 'sonar-pro',
            'temperature' => 0.0,
            'messages'    => [
                ['role' => 'system', 'content' => 'Tu es un analyste pricing B2B rigoureux.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        [$status, $body, $netError] = $this->postJsonPricingRequest(
            'https://api.perplexity.ai/chat/completions',
            $payload,
            $key,
            $keepAlive
        );

        if ($netError !== null) {
            throw new \RuntimeException(t('Appel Perplexity impossible ({erreur}).', ['erreur' => $netError]));
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $snippet = $this->snippetForError($body);
            error_log(
                '[AdminController] Perplexity réponse non-JSON. status=' . $status
                . ' length=' . strlen($body)
                . ' head=' . substr($body, 0, 240)
                . ' tail=' . substr($body, max(0, strlen($body) - 240))
            );
            throw new \RuntimeException(
                t('Réponse Perplexity illisible (HTTP {statut}).', ['statut' => $status])
                . ($snippet !== '' ? ' ' . t('Extrait : {extrait}', ['extrait' => $snippet]) : '')
            );
        }
        if (isset($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? t('Erreur Perplexity inconnue.')) : (string)$json['error'];
            throw new \RuntimeException(t('Perplexity a refusé la requête (HTTP {statut}) : {message}', ['statut' => $status, 'message' => (string)$message]));
        }

        // Consommation (tokens ; la recherche est comprise dans le tarif du
        // modèle Sonar, comptée au tarif `perplexity::sonar-pro`).
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        UsageMeter::llm('perplexity::sonar-pro', [
            'input'  => (int)($usage['prompt_tokens'] ?? 0),
            'output' => (int)($usage['completion_tokens'] ?? 0),
        ], UsageSource::PRICE_REFRESH, $this->priceRefreshBillingCtx);

        $text = trim((string)($json['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException(t("Perplexity n'a pas renvoyé de contenu exploitable."));
        }

        $parsed = $this->decodePricingModelOutput($text);
        return $this->sanitizePricingUpdatesResult($parsed, $services, 'perplexity:sonar-pro');
    }

    /**
     * POST JSON helper pour les appels de pricing. Préfère cURL si dispo
     * (meilleurs codes/erreurs, timeout fiable) ; fallback file_get_contents.
     * Renvoie [statusHttp, body, netError|null].
     *
     * @return array{0:int, 1:string, 2:?string}
     */
    private function postJsonPricingRequest(
        string $url,
        string $payload,
        string $bearer,
        ?callable $keepAlive = null
    ): array {
        $timeoutSec = 180;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Bearer ' . $bearer,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $timeoutSec,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_FAILONERROR    => false,
                ]);
                // Le transfert peut durer plus longtemps que le délai au-delà
                // duquel un job sans battement est considéré mort : la sonde
                // bat le cœur pendant l'attente, et interrompt le transfert si
                // l'arrêt a été demandé.
                if ($keepAlive !== null) {
                    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                    curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function () use ($keepAlive): int {
                        return $keepAlive() === false ? 1 : 0;
                    });
                }
                $body   = curl_exec($ch);
                $errno  = curl_errno($ch);
                $errmsg = $errno !== 0 ? curl_error($ch) : null;
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($errno !== 0 || !is_string($body)) {
                    return [0, '', $errmsg !== null && $errmsg !== '' ? $errmsg : t('erreur cURL')];
                }
                return [$status, $body, null];
            }
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => $timeoutSec,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer $bearer",
            'content'       => $payload,
        ]]);

        $body = @file_get_contents($url, false, $ctx);
        // Lu par `??` : une lecture nue de $http_response_header est dépréciée
        // en PHP 8.5 (cf. CLAUDE.md).
        $responseHeaders = $http_response_header ?? [];

        if ($body === false) {
            $lastError = error_get_last();
            $msg = is_array($lastError) && isset($lastError['message']) ? (string)$lastError['message'] : t('requête HTTP échouée');
            return [0, '', $msg];
        }

        $status = 0;
        foreach ($responseHeaders as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
            }
        }

        return [$status, $body, null];
    }

    private function snippetForError(string $body): string
    {
        $clean = trim($body);
        if ($clean === '') {
            return '';
        }
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        if (mb_strlen($clean) > 180) {
            $clean = mb_substr($clean, 0, 180) . '…';
        }
        return $clean;
    }

    /**
     * Décode la sortie d'un LLM censée être du JSON, en absorbant les habitudes
     * fréquentes des modèles : code fences markdown ```json…```, préambule
     * explicatif, virgules traînantes, JSON imbriqué dans du texte.
     */
    private function decodePricingModelOutput(string $text): array
    {
        $candidates = $this->buildPricingJsonCandidates($text);

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && (isset($decoded['updates']) || isset($decoded['not_found']))) {
                return $decoded;
            }
        }

        // Dernier recours : accepter tout objet JSON décodable (compat anciens formats).
        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Diagnostic : log un extrait pour qu'on puisse identifier ce qui sort
        // exactement (sans flooder errors.log avec la réponse complète).
        $trimmed = trim($text);
        error_log(
            '[AdminController] decodePricingModelOutput a échoué. '
            . 'json_last_error=' . json_last_error_msg()
            . ' | length=' . strlen($trimmed)
            . ' | head=' . substr($trimmed, 0, 240)
            . ' | tail=' . substr($trimmed, max(0, strlen($trimmed) - 240))
        );

        throw new \RuntimeException(t('Le format de réponse ne contient pas de JSON valide.'));
    }

    /**
     * Génère une liste de candidats JSON à tester, dans l'ordre.
     * Couvre : texte brut, démarquage Markdown, objets équilibrés, et nettoyage
     * (virgules traînantes, guillemets typographiques, marqueurs de citation [1]).
     *
     * @return list<string>
     */
    private function buildPricingJsonCandidates(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }

        $candidates = [];
        $push = static function (string $value) use (&$candidates): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            if (!in_array($value, $candidates, true)) {
                $candidates[] = $value;
            }
        };

        $push($trimmed);

        // Strip fences ```json ... ``` ou ``` ... ```
        $fenceStripped = preg_replace(
            '/```(?:json|JSON)?\s*\n?|\n?\s*```/u',
            '',
            $trimmed
        );
        if (is_string($fenceStripped)) {
            $push($fenceStripped);
        }

        // Objets équilibrés extraits via comptage de braces (ignore les braces dans les strings).
        foreach ($this->extractBalancedJsonObjects($fenceStripped ?: $trimmed) as $object) {
            $push($object);
        }

        // Variantes nettoyées de chaque candidat existant.
        foreach ($candidates as $candidate) {
            $push($this->sanitizePricingJsonCandidate($candidate));
        }

        return $candidates;
    }

    /**
     * Extrait tous les objets JSON équilibrés (premier `{` -> `}` correspondant),
     * en ignorant les braces présentes dans les chaînes JSON (avec gestion des échappements).
     *
     * @return list<string>
     */
    private function extractBalancedJsonObjects(string $text): array
    {
        $objects = [];
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $start = strpos($text, '{', $i);
            if ($start === false) {
                break;
            }

            $depth = 0;
            $inString = false;
            $escape = false;
            $end = -1;

            for ($j = $start; $j < $len; $j++) {
                $c = $text[$j];

                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($inString) {
                    if ($c === '\\') {
                        $escape = true;
                    } elseif ($c === '"') {
                        $inString = false;
                    }
                    continue;
                }

                if ($c === '"') {
                    $inString = true;
                } elseif ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $j;
                        break;
                    }
                }
            }

            if ($end === -1) {
                break;
            }

            $objects[] = substr($text, $start, $end - $start + 1);
            $i = $end + 1;
        }

        return $objects;
    }

    /**
     * Nettoie un candidat JSON : remplace les guillemets typographiques,
     * retire les virgules traînantes et les marqueurs de citation `[1]` / `[1,2]`
     * que renvoient certains LLM avec recherche web (Perplexity, GPT search).
     */
    private function sanitizePricingJsonCandidate(string $text): string
    {
        // Guillemets typographiques -> droits.
        $text = strtr($text, [
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            "\xE2\x80\x9E" => '"',
            "\xE2\x80\x9F" => '"',
            "\xE2\x80\x98" => "'",
            "\xE2\x80\x99" => "'",
            "\xC2\xA0"     => ' ',
        ]);

        // Retire les marqueurs de citation `[1]`, `[12]`, `[1,2,3]`, `[citation:1]`,
        // uniquement hors chaînes JSON (sinon on casserait des URL ou notes).
        $text = $this->stripCitationMarkersOutsideStrings($text);

        // Virgules traînantes avant `}` ou `]`.
        $text = preg_replace('/,(\s*[}\]])/u', '$1', $text) ?? $text;

        return $text;
    }

    private function stripCitationMarkersOutsideStrings(string $text): string
    {
        $len = strlen($text);
        $out = '';
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];

            if ($escape) {
                $out .= $c;
                $escape = false;
                continue;
            }

            if ($inString) {
                if ($c === '\\') {
                    $out .= $c;
                    $escape = true;
                    continue;
                }
                if ($c === '"') {
                    $inString = false;
                }
                $out .= $c;
                continue;
            }

            if ($c === '"') {
                $inString = true;
                $out .= $c;
                continue;
            }

            // Hors chaîne : tente de matcher un marqueur de citation à cette position.
            // Format toléré : `[<digits>(,<digits>)*]` ou `[citation:<digits>]`,
            // possiblement répétés (`[1][2]`). On vérifie aussi le contexte droit
            // pour éviter d'avaler une vraie sous-liste JSON (toujours suivie d'une
            // accolade fermante ou virgule via une valeur, JAMAIS d'une string seule).
            if ($c === '[' && preg_match(
                '/\G(?:\[(?:citation:\s*)?\d+(?:\s*,\s*\d+)*\])+/',
                $text,
                $m,
                0,
                $i
            )) {
                // Pour distinguer une vraie liste `[1]` d'un marqueur de citation,
                // on regarde ce qui précède (hors espace).
                $prev = $this->lastNonSpaceChar($out);
                // Si le caractère précédent est une fin de valeur (`"`, chiffre, `}`, `]`)
                // ET ce qui suit n'est pas un séparateur de valeur ouvert,
                // alors c'est un marqueur à supprimer.
                if ($prev !== null && (ctype_digit($prev) || $prev === '"' || $prev === '}' || $prev === ']')) {
                    $afterPos = $i + strlen($m[0]);
                    $afterChar = $this->nextNonSpaceChar($text, $afterPos);
                    // Si après le marqueur on tombe sur `,`, `}`, `]` ou fin -> citation.
                    if ($afterChar === null || $afterChar === ',' || $afterChar === '}' || $afterChar === ']') {
                        $i += strlen($m[0]) - 1;
                        continue;
                    }
                }
            }

            $out .= $c;
        }

        return $out;
    }

    private function lastNonSpaceChar(string $text): ?string
    {
        for ($k = strlen($text) - 1; $k >= 0; $k--) {
            if (!ctype_space($text[$k])) {
                return $text[$k];
            }
        }
        return null;
    }

    private function nextNonSpaceChar(string $text, int $from): ?string
    {
        $len = strlen($text);
        for ($k = $from; $k < $len; $k++) {
            if (!ctype_space($text[$k])) {
                return $text[$k];
            }
        }
        return null;
    }

    private function sanitizePricingUpdatesResult(array $parsed, array $services, string $engine): array
    {
        $servicesById = [];
        foreach ($services as $service) {
            $id = (string)($service['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $servicesById[$id] = $service;
        }

        $updates = [];
        $seen = [];
        foreach ((array)($parsed['updates'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || !isset($servicesById[$id])) {
                continue;
            }

            $price = $this->parsePositiveDecimal($row['real_cost_usd'] ?? null, -1.0);
            if ($price < 0) {
                continue;
            }

            $suspiciousReason = $this->detectSuspiciousPricing($servicesById[$id], $price);
            if ($suspiciousReason !== null) {
                $updates[] = [
                    'id'            => $id,
                    'real_cost_usd' => -1.0,
                    'source_url'    => '',
                    'source_note'   => $suspiciousReason,
                ];
                $seen[$id] = true;
                continue;
            }

            $updates[] = [
                'id'            => $id,
                'real_cost_usd' => $price,
                'source_url'    => trim((string)($row['source_url'] ?? '')),
                'source_note'   => trim((string)($row['source_note'] ?? '')),
            ];
            $seen[$id] = true;
        }

        $notFound = [];
        foreach ((array)($parsed['not_found'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || !isset($servicesById[$id])) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }

            $notFound[] = [
                'id'     => $id,
                'reason' => trim((string)($row['reason'] ?? t('Prix introuvable.'))),
            ];
            $seen[$id] = true;
        }

        foreach ($services as $service) {
            $id = (string)$service['id'];
            if (!isset($seen[$id])) {
                $notFound[] = ['id' => $id, 'reason' => t('Aucun prix fiable trouvé.')];
            }
        }

        $validUpdates = [];
        foreach ($updates as $item) {
            if (($item['real_cost_usd'] ?? -1) < 0) {
                $notFound[] = [
                    'id' => (string)$item['id'],
                    'reason' => trim((string)($item['source_note'] ?? t('Prix écarté après contrôle de cohérence.'))),
                ];
                continue;
            }
            $validUpdates[] = $item;
        }

        return [
            'engine'    => $engine,
            'updated'   => $validUpdates,
            'not_found' => $notFound,
            'checked_at'=> gmdate('c'),
        ];
    }

    private function detectSuspiciousPricing(array $service, float $priceUsd): ?string
    {
        $type = strtolower(trim((string)($service['type'] ?? '')));
        $unit = strtolower(trim((string)($service['unit'] ?? '')));
        if ($priceUsd < 0) {
            return t('Prix négatif ou invalide.');
        }

        if ($type === 'video') {
            if ($unit === 'video_second' && $priceUsd < 0.05) {
                return t('Prix vidéo/s suspect (< 0.05 USD/s). Vérification manuelle recommandée.');
            }
            if ($unit === 'request' && $priceUsd < 0.50) {
                return t('Prix vidéo/requête suspect (< 0.50 USD). Vérification manuelle recommandée.');
            }
        }

        if ($type === 'image' && $unit === 'image_generated' && $priceUsd < 0.001) {
            return t('Prix image suspect (< 0.001 USD/image).');
        }

        return null;
    }

    private function normalizeCreditsConfig(array $raw): array
    {
        $allowedRoundingModes = ['ceil', 'nearest', 'floor'];
        $allowedCurrencies = ['EUR', 'USD'];
        $allowedTypes = ['llm_text', 'embedding', 'web_search', 'image', 'video', 'audio', 'other'];
        $allowedUnits = [
            '1m_input_tokens',
            '1m_output_tokens',
            '1m_tokens',
            '1k_tokens',
            'image_generated',
            'video_second',
            'audio_minute',
            'api_call',
            'request',
            'minute',
            'second',
        ];

        $config = [
            'version' => 1,
            'global' => [
                'credit_value_eur' => $this->parsePositiveDecimal($raw['global']['credit_value_eur'] ?? null, 0.001),
                'rounding_mode' => (string)($raw['global']['rounding_mode'] ?? 'ceil'),
                'margin_multiplier' => $this->parsePositiveDecimal($raw['global']['margin_multiplier'] ?? null, 1.30),
                'fixed_fee_eur' => $this->parsePositiveDecimal($raw['global']['fixed_fee_eur'] ?? null, 0.0),
                'min_credits_per_operation' => 0,
                'currency' => strtoupper(trim((string)($raw['global']['currency'] ?? 'EUR'))),
                'fx_rate_eur_usd' => $this->parsePositiveDecimal($raw['global']['fx_rate_eur_usd'] ?? null, 1.08),
            ],
            'services' => [],
        ];

        $legacyMinEur = $this->parsePositiveDecimal($raw['global']['min_operation_eur'] ?? null, 0.0);
        $rawMinCredits = $raw['global']['min_credits_per_operation'] ?? null;
        if ($rawMinCredits !== null && $rawMinCredits !== '') {
            $config['global']['min_credits_per_operation'] = max(0, (int)$rawMinCredits);
        } elseif ($legacyMinEur > 0 && $config['global']['credit_value_eur'] > 0) {
            $config['global']['min_credits_per_operation'] = (int)ceil($legacyMinEur / $config['global']['credit_value_eur']);
        }

        if ($config['global']['credit_value_eur'] <= 0) {
            $config['global']['credit_value_eur'] = 0.001;
        }
        if ($config['global']['margin_multiplier'] <= 0) {
            $config['global']['margin_multiplier'] = 1.0;
        }
        if ($config['global']['fx_rate_eur_usd'] <= 0) {
            $config['global']['fx_rate_eur_usd'] = 1.08;
        }
        if (!in_array($config['global']['rounding_mode'], $allowedRoundingModes, true)) {
            $config['global']['rounding_mode'] = 'ceil';
        }
        if (!in_array($config['global']['currency'], $allowedCurrencies, true)) {
            $config['global']['currency'] = 'EUR';
        }

        $seenIds = [];
        $seenCatalogKeys = [];
        $rows = is_array($raw['services'] ?? null) ? $raw['services'] : [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || isset($seenIds[$id])) {
                $id = $this->makeServiceId((string)($row['name'] ?? 'service'), $index);
            }

            // Dédoublonner par catalog_key (garder la première occurrence)
            $rowCatalogKey = trim((string)($row['catalog_key'] ?? ''));
            if ($rowCatalogKey !== '' && isset($seenCatalogKeys[$rowCatalogKey])) {
                continue;
            }

            $type = (string)($row['type'] ?? 'other');
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'other';
            }

            $unit = (string)($row['unit'] ?? 'request');
            if (!in_array($unit, $allowedUnits, true)) {
                $unit = 'request';
            }

            $multiplier = $this->parsePositiveDecimal($row['multiplier'] ?? null, 1.0);
            if ($multiplier <= 0) {
                $multiplier = 1.0;
            }

            $config['services'][] = [
                'id' => $id,
                'name' => trim((string)($row['name'] ?? t('Service IA'))) ?: t('Service IA'),
                'provider' => trim((string)($row['provider'] ?? 'N/A')) ?: 'N/A',
                'type' => $type,
                'unit' => $unit,
                'real_cost_eur' => $this->parsePositiveDecimal($row['real_cost_eur'] ?? null, 0.0),
                'multiplier' => $multiplier,
                'active' => !empty($row['active']),
                'catalog_key' => $rowCatalogKey,
                'internal_comment' => trim((string)($row['internal_comment'] ?? '')),
            ];
            $seenIds[$id] = true;
            if ($rowCatalogKey !== '') {
                $seenCatalogKeys[$rowCatalogKey] = true;
            }
        }

        return $config;
    }

    private function defaultCreditsConfig(array $catalogRows = []): array
    {
        $config = [
            'version' => 1,
            'global' => [
                'credit_value_eur' => 0.001,
                'rounding_mode' => 'ceil',
                'margin_multiplier' => 1.30,
                'fixed_fee_eur' => 0.0,
                'min_credits_per_operation' => 1,
                'currency' => 'EUR',
                'fx_rate_eur_usd' => 1.08,
            ],
            'services' => [
                [
                    'id' => 'svc-gpt4-input',
                    'name' => 'GPT-4 input',
                    'provider' => 'OpenAI',
                    'type' => 'llm_text',
                    'unit' => '1m_input_tokens',
                    'real_cost_eur' => 4.50,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'sample:gpt4:input',
                    'internal_comment' => t('Tarif entrée par million de tokens.'),
                ],
                [
                    'id' => 'svc-gpt4-output',
                    'name' => 'GPT-4 output',
                    'provider' => 'OpenAI',
                    'type' => 'llm_text',
                    'unit' => '1m_output_tokens',
                    'real_cost_eur' => 13.50,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'sample:gpt4:output',
                    'internal_comment' => t('Tarif sortie par million de tokens.'),
                ],
                [
                    'id' => 'svc-gpt5-input',
                    'name' => 'GPT-5 input',
                    'provider' => 'OpenAI',
                    'type' => 'llm_text',
                    'unit' => '1m_input_tokens',
                    'real_cost_eur' => 10.00,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'sample:gpt5:input',
                    'internal_comment' => '',
                ],
                [
                    'id' => 'svc-gpt5-output',
                    'name' => 'GPT-5 output',
                    'provider' => 'OpenAI',
                    'type' => 'llm_text',
                    'unit' => '1m_output_tokens',
                    'real_cost_eur' => 30.00,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'sample:gpt5:output',
                    'internal_comment' => '',
                ],
                [
                    'id' => 'svc-claude-sonnet',
                    'name' => 'Claude Sonnet',
                    'provider' => 'Anthropic',
                    'type' => 'llm_text',
                    'unit' => '1m_input_tokens',
                    'real_cost_eur' => 2.80,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'sample:claude:input',
                    'internal_comment' => '',
                ],
                [
                    'id' => 'svc-mistral-large',
                    'name' => 'Mistral Large',
                    'provider' => 'Mistral',
                    'type' => 'llm_text',
                    'unit' => '1m_input_tokens',
                    'real_cost_eur' => 1.20,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'sample:mistral:input',
                    'internal_comment' => '',
                ],
                [
                    'id' => 'svc-dalle-image',
                    'name' => t('Génération image'),
                    'provider' => 'OpenAI',
                    'type' => 'image',
                    'unit' => 'image_generated',
                    'real_cost_eur' => 0.04,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'sample:dalle:image',
                    'internal_comment' => t('Hypothèse image standard.'),
                ],
                [
                    'id' => 'svc-sora-video',
                    'name' => t('Génération vidéo'),
                    'provider' => 'OpenAI',
                    'type' => 'video',
                    'unit' => 'video_second',
                    'real_cost_eur' => 0.35,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'sample:sora:video',
                    'internal_comment' => t('Coût estimé par seconde.'),
                ],
            ],
        ];

        $existingKeys = [];
        foreach ($config['services'] as $service) {
            $existingKeys[(string)$service['catalog_key']] = true;
        }

        foreach ($catalogRows as $catalogRow) {
            $catalogKey = trim((string)($catalogRow['catalog_key'] ?? ''));
            if ($catalogKey === '' || isset($existingKeys[$catalogKey])) {
                continue;
            }

            $catalogRow['active'] = false;
            $config['services'][] = $catalogRow;
            $existingKeys[$catalogKey] = true;
        }

        return $this->normalizeCreditsConfig($config);
    }

    private function buildCatalogServiceRows(array $llmModels, array $tools): array
    {
        $rows = [];
        $seenCatalogKeys = [];

        foreach ($llmModels as $model) {
            $provider = trim((string)($model['provider'] ?? ''));
            $name = trim((string)($model['display_name'] ?? '')) ?: trim((string)($model['model'] ?? ''));
            $modelName = trim((string)($model['model'] ?? ''));
            if ($provider === '' || $modelName === '') {
                continue;
            }

            // Éviter les doublons si le même provider::model est listé plusieurs fois
            $dedupKey = $provider . '::' . $modelName;
            if (isset($seenCatalogKeys[$dedupKey])) {
                continue;
            }
            $seenCatalogKeys[$dedupKey] = true;

            $baseId = $this->slugify($provider . '-' . $modelName);
            $baseLabel = $name !== '' ? $name : ($provider . ' ' . $modelName);

            $rows[] = [
                'id' => 'svc-' . $baseId . '-input',
                'name' => $baseLabel . ' input',
                'provider' => ucfirst($provider),
                'type' => 'llm_text',
                'unit' => '1m_input_tokens',
                'real_cost_eur' => 0.0,
                'multiplier' => 1.0,
                'active' => true,
                'catalog_key' => 'llm:' . $provider . '::' . $modelName . ':input',
                'internal_comment' => '',
            ];
            $rows[] = [
                'id' => 'svc-' . $baseId . '-output',
                'name' => $baseLabel . ' output',
                'provider' => ucfirst($provider),
                'type' => 'llm_text',
                'unit' => '1m_output_tokens',
                'real_cost_eur' => 0.0,
                'multiplier' => 1.0,
                'active' => true,
                'catalog_key' => 'llm:' . $provider . '::' . $modelName . ':output',
                'internal_comment' => '',
            ];
            // Tokens en cache lus (cached) — facturation réduite (~10% du tarif input plein)
            $rows[] = [
                'id' => 'svc-' . $baseId . '-input-cached',
                'name' => $baseLabel . ' input (cached)',
                'provider' => ucfirst($provider),
                'type' => 'llm_text',
                'unit' => '1m_input_tokens',
                'real_cost_eur' => 0.0,
                'multiplier' => 1.0,
                'active' => false,
                'catalog_key' => 'llm:' . $provider . '::' . $modelName . ':input_cached',
                'internal_comment' => t('Tokens lus depuis le cache. Tarif typique ≈ 10 % du prix input plein.'),
            ];
            // Reasoning tokens (output) — distinguer pour les modèles raisonneurs (GPT-5/o-series, Gemini thinking).
            $rows[] = [
                'id' => 'svc-' . $baseId . '-output-reasoning',
                'name' => $baseLabel . ' output (reasoning)',
                'provider' => ucfirst($provider),
                'type' => 'llm_text',
                'unit' => '1m_output_tokens',
                'real_cost_eur' => 0.0,
                'multiplier' => 1.0,
                'active' => false,
                'catalog_key' => 'llm:' . $provider . '::' . $modelName . ':output_reasoning',
                'internal_comment' => t('Tokens de raisonnement (modèles thinking). Souvent même tarif que output.'),
            ];
            // Cache write Anthropic uniquement — facturation majorée (~1,25× input plein).
            if (strtolower($provider) === 'anthropic') {
                $rows[] = [
                    'id' => 'svc-' . $baseId . '-input-cache-write',
                    'name' => $baseLabel . ' input (cache write)',
                    'provider' => ucfirst($provider),
                    'type' => 'llm_text',
                    'unit' => '1m_input_tokens',
                    'real_cost_eur' => 0.0,
                    'multiplier' => 1.0,
                    'active' => false,
                    'catalog_key' => 'llm:' . $provider . '::' . $modelName . ':input_cache_write',
                    'internal_comment' => t('Création de cache Anthropic. Tarif typique ≈ 1,25× du prix input plein.'),
                ];
            }
        }

        foreach ($tools as $tool) {
            $toolId = (int)($tool['id'] ?? 0);
            if ($toolId <= 0) {
                continue;
            }

            $type = (string)($tool['type'] ?? 'other');
            $normalizedType = match ($type) {
                'image' => 'image',
                'video' => 'video',
                'html' => 'other',
                default => 'other',
            };
            $unit = match ($type) {
                'image' => 'image_generated',
                'video' => 'video_second',
                default => 'request',
            };

            $rows[] = [
                'id' => 'svc-tool-' . $toolId,
                'name' => trim((string)($tool['name'] ?? ('Tool #' . $toolId))),
                'provider' => trim((string)($tool['provider'] ?? 'N/A')) ?: 'N/A',
                'type' => $normalizedType,
                'unit' => $unit,
                'real_cost_eur' => 0.0,
                'multiplier' => 1.0,
                'active' => true,
                'catalog_key' => 'tool:' . $toolId,
                'internal_comment' => '',
            ];
        }

        // Services hors LLM de chat, eux aussi consommés et journalisés
        // (UsageMeter) : sans ligne ici, ils ne seraient ni visibles ni
        // réglables — CreditService retombe sur les tarifs curés du catalogue
        // tant que la ligne reste à 0 (« Mettre à jour les prix » la remplit).
        $providersWithKey = [];
        foreach ($llmModels as $model) {
            $provider = strtolower(trim((string)($model['provider'] ?? '')));
            if ($provider !== '') {
                $providersWithKey[$provider] = true;
            }
        }

        // Embeddings : un service par modèle des fournisseurs à clé configurée.
        foreach (EmbeddingService::availableProviders() as $provider => $entry) {
            foreach ((array)($entry['models'] ?? []) as $modelName => $modelInfo) {
                $rows[] = [
                    'id' => 'svc-embedding-' . $this->slugify($provider . '-' . $modelName),
                    'name' => 'Embeddings ' . (string)($modelInfo['label'] ?? $modelName),
                    'provider' => ucfirst((string)$provider),
                    'type' => 'embedding',
                    'unit' => '1m_tokens',
                    'real_cost_eur' => 0.0,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'embedding:' . $provider . '::' . $modelName,
                    'internal_comment' => t('Vectorisation des bases de connaissances et embedding des questions (RAG).'),
                ];
            }
        }

        // Recherche web : un service par fournisseur dont l'outil serveur est
        // facturé à la recherche (tarif curé au catalogue ou outil déclaré).
        foreach (array_keys($providersWithKey) as $provider) {
            $catalog = LlmCatalog::provider($provider);
            if (!isset($catalog['web_search_pricing_usd_per_1k']) && !isset($catalog['web_search_tools'])) {
                continue;
            }
            $rows[] = [
                'id' => 'svc-websearch-' . $this->slugify($provider),
                'name' => t('Recherche web {fournisseur}', ['fournisseur' => ucfirst($provider)]),
                'provider' => ucfirst($provider),
                'type' => 'web_search',
                'unit' => 'request',
                'real_cost_eur' => 0.0,
                'multiplier' => 1.0,
                'active' => true,
                'catalog_key' => 'websearch:' . $provider,
                'internal_comment' => t('Prix d\'UNE recherche exécutée par le fournisseur pendant une réponse (en plus des tokens).'),
            ];
        }

        // Dictée vocale (OpenAI Realtime) : un service par modèle de transcription.
        if (isset($providersWithKey['openai'])) {
            foreach (array_keys(ChatController::TRANSCRIPTION_MODELS) as $modelName) {
                $rows[] = [
                    'id' => 'svc-transcribe-' . $this->slugify($modelName),
                    'name' => t('Dictée {modele}', ['modele' => $modelName]),
                    'provider' => 'Openai',
                    'type' => 'audio',
                    'unit' => 'audio_minute',
                    'real_cost_eur' => 0.0,
                    'multiplier' => 1.0,
                    'active' => true,
                    'catalog_key' => 'transcribe:openai::' . $modelName,
                    'internal_comment' => t('Minute d\'audio transcrite (durée rapportée par le navigateur en fin de dictée).'),
                ];
            }
            // Lecture à voix haute (conversation vocale) : un service pour le
            // modèle de synthèse, à la minute d'audio produit (mesurée côté
            // serveur sur l'audio reçu d'OpenAI).
            $rows[] = [
                'id' => 'svc-speech-' . $this->slugify(SpeechSynthesizer::MODEL),
                'name' => t('Lecture à voix haute {modele}', ['modele' => SpeechSynthesizer::MODEL]),
                'provider' => 'Openai',
                'type' => 'audio',
                'unit' => 'audio_minute',
                'real_cost_eur' => 0.0,
                'multiplier' => 1.0,
                'active' => true,
                'catalog_key' => 'speech:openai::' . SpeechSynthesizer::MODEL,
                'internal_comment' => t('Minute d\'audio produite par la synthèse vocale (conversation vocale), mesurée sur l\'audio reçu.'),
            ];
        }

        return $rows;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string)$slug, '-');
        return $slug !== '' ? $slug : 'service';
    }

    private function makeServiceId(string $seed, int $index): string
    {
        $slug = $this->slugify($seed);
        return 'svc-' . $slug . '-' . max(1, $index + 1);
    }

    private function splitLlmKey(string $key): array
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return ['openai', 'openai'];
        }

        if (!str_contains($trimmed, '::')) {
            return [$trimmed, $trimmed];
        }

        [$provider, $model] = explode('::', $trimmed, 2);
        $provider = trim($provider);
        $model = trim($model);
        if ($provider === '') {
            $provider = 'openai';
        }
        if ($model === '') {
            $model = $provider;
        }
        return [$provider, $model];
    }

    private function parseGroupIdsFromPost(): ?array
    {
        if (!array_key_exists('group_ids', $_POST)) {
            return null;
        }

        $raw = $_POST['group_ids'];
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn(int $id): bool => $id > 0
        )));
    }

    /**
     * Résout l'avatar d'un assistant depuis la requête de création/édition :
     *  1. fichier uploadé inline (avatar_upload + avatar_upload_name) → ajouté à
     *     la bibliothèque nommée puis sélectionné ;
     *  2. sinon, sélection d'un avatar existant de la bibliothèque.
     */
    private function resolveAssistantAvatarFromRequest(): ?string
    {
        $uploaded = AssistantAvatar::storeUpload($_FILES['avatar_upload'] ?? []);
        if ($uploaded !== null) {
            $userId = (int)(Session::currentUser()['id'] ?? 0) ?: null;
            AssistantAvatar::register($uploaded, (string)($_POST['avatar_upload_name'] ?? ''), $userId);
            return $uploaded;
        }

        $selected = trim((string)($_POST['avatar'] ?? ''));
        if ($selected === '') {
            return null;
        }
        return AssistantAvatar::fileExists($selected) ? $selected : null;
    }

    /**
     * @param array<int, array<string, mixed>> $profiles
     * @return array<string, int>
     */
    private function buildAssistantAvatarUsageCounts(array $profiles): array
    {
        $counts = [];
        foreach ($profiles as $profile) {
            $avatar = trim((string)($profile['avatar'] ?? ''));
            if ($avatar === '') {
                continue;
            }
            $avatar = basename($avatar);
            $counts[$avatar] = ($counts[$avatar] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * LLMs sélectionnables par le gestionnaire courant lors de la création /
     * édition d'un assistant.
     * - Admin : tous les LLMs activés (il gère l'application).
     * - Creator : uniquement ceux autorisés pour ses groupes — même règle que le
     *   chat (cf. UserGroup::allowedLlmKeysForUser). `null` = aucune restriction.
     *
     * @return array<string,string> map slug "provider::model" => libellé
     */
    private function allowedLlmsForCurrentManager(): array
    {
        // Source unique, partagée avec l'assistant préparé par MIA dans le chat.
        return AssistantDraft::allowedLlmsFor(Session::currentUser());
    }

    /**
     * Stocke un fichier uploadé comme document de référence d'un profil.
     * @param array<string, mixed> $file entrée unique de $_FILES
     * @return array{ok: bool, error?: string, warning?: string, document_id?: int}
     */
    private function storeProfileDocumentFromUpload(int $profileId, array $file): array
    {
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false]; // input vide : silencieux
        }
        if ($errorCode !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            $msg = match($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => t('Fichier trop lourd (limite serveur : {limite}).', ['limite' => (string)ini_get('upload_max_filesize')]),
                default => t('Erreur upload ({code}).', ['code' => $errorCode]),
            };
            return ['ok' => false, 'error' => $msg];
        }

        $name = (string)($file['name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::PROFILE_DOC_MAX_SIZE) {
            return ['ok' => false, 'error' => t('Fichier « {nom} » invalide (taille max 20 Mo).', ['nom' => $name])];
        }

        // Détection MIME robuste : les DOCX sont parfois vus comme application/zip
        $mime = FileExtractor::detectMime((string)$file['tmp_name'], $name);
        $ext  = self::PROFILE_DOC_ALLOWED_MIMES[$mime] ?? null;
        if ($ext === null) {
            return ['ok' => false, 'error' => t('Format non supporté pour « {nom} ». Formats acceptés : PDF, DOCX, XLSX, TXT, MD, images.', ['nom' => $name])];
        }

        $existing = ProfileDocument::allForProfile($profileId);
        if (count($existing) >= self::PROFILE_DOC_MAX_COUNT) {
            return ['ok' => false, 'error' => t('Limite atteinte : {n} documents maximum par assistant.', ['n' => self::PROFILE_DOC_MAX_COUNT])];
        }

        try {
            $dir = ProfileDocument::ensureStorageDir($profileId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath   = $dir . '/' . $storedName;
        if (!move_uploaded_file((string)$file['tmp_name'], $destPath)) {
            return ['ok' => false, 'error' => t('Impossible d\'enregistrer le fichier « {nom} ».', ['nom' => $name])];
        }

        // Extraction de texte différée : on crée le document en 'pending' et on
        // délègue l'extraction + le chunking à un worker en tâche de fond. L'upload
        // répond immédiatement (plus de blocage HTTP sur les gros PDF).
        $newId = ProfileDocument::create([
            'profile_id'        => $profileId,
            'file_name'         => $name !== '' ? $name : $storedName,
            'stored_name'       => $storedName,
            'mime_type'         => $mime,
            'file_size'         => $size,
            'extracted_text'    => null,
            'token_count'       => 0,
            'extraction_status' => 'pending',
        ]);

        $this->dispatchDocumentExtraction($newId);

        return ['ok' => true, 'document_id' => $newId];
    }

    /**
     * Lance l'extraction d'un document. Worker asynchrone si possible
     * (exec POSIX), sinon extraction synchrone de secours — un document
     * n'est jamais laissé bloqué en 'pending'.
     */
    private function dispatchDocumentExtraction(int $documentId): void
    {
        JobQueue::enqueueMaintenance('profile_document', $documentId, 'Extraction document #' . $documentId);
    }

    /**
     * Traite une liste de fichiers uploadés (input name="documents[]").
     * @return array{stored: int, errors: list<string>, warnings: list<string>}
     */
    private function storeMultipleProfileDocumentsFromRequest(int $profileId, string $field): array
    {
        $result = ['stored' => 0, 'errors' => [], 'warnings' => []];
        if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
            return $result;
        }

        $all = $_FILES[$field];
        $n   = count($all['name']);
        for ($i = 0; $i < $n; $i++) {
            $file = [
                'name'     => $all['name'][$i]     ?? '',
                'type'     => $all['type'][$i]     ?? '',
                'tmp_name' => $all['tmp_name'][$i] ?? '',
                'error'    => $all['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $all['size'][$i]     ?? 0,
            ];
            $r = $this->storeProfileDocumentFromUpload($profileId, $file);
            if (!empty($r['ok'])) {
                $result['stored']++;
                if (!empty($r['warning'])) {
                    $result['warnings'][] = $r['warning'];
                }
            } elseif (!empty($r['error'])) {
                $result['errors'][] = $r['error'];
            }
        }
        return $result;
    }
}
