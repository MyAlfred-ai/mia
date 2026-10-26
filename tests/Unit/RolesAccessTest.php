<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Rôles (src/Models/Role.php) et accès aux pages de l'administration
 * (src/Helpers/AdminAccess.php), sans base : les lignes de la table `roles`
 * sont fixées en mémoire (Role::overrideRows), comme Settings::override.
 *
 * Ce qui est gardé ici, et qu'un test d'affichage ne verrait pas :
 *   - les rôles livrés ouvrent EXACTEMENT les pages d'avant l'écran des rôles ;
 *   - aucune élévation : un gestionnaire de comptes ne donne jamais un rôle
 *     plus large que le sien, ni l'administrateur, et ne touche pas un compte
 *     plus large que le sien ;
 *   - un compte désactivé n'a aucun droit ;
 *   - le câblage (routes, gardes, gabarit, menus, chargement, migration).
 */
final class RolesAccessTest extends TestCase
{
    private const ADMIN   = ['id' => 1, 'role' => 'admin', 'is_active' => 1];
    private const CREATOR = ['id' => 2, 'role' => 'creator', 'is_active' => 1];
    private const USER    = ['id' => 3, 'role' => 'user', 'is_active' => 1];
    private const GESTION = ['id' => 4, 'role' => 'gestion', 'is_active' => 1];
    private const SUPPORT = ['id' => 5, 'role' => 'support', 'is_active' => 1];

    protected function setUp(): void
    {
        // Deux rôles personnalisés : un gestionnaire de comptes (part des droits
        // d'un utilisateur, plus comptes et groupes) et un support restreint.
        Role::overrideRows([
            ['id' => 10, 'slug' => 'gestion', 'name' => 'Gestion des comptes', 'description' => '', 'color' => '#0ea5e9',
             'permissions' => Permissions::encode(array_merge(Permissions::defaultsFor('user'), ['users.view', 'users.create', 'users.edit', 'groups.view']))],
            ['id' => 11, 'slug' => 'support', 'name' => 'Support', 'description' => 'Consulte', 'color' => '#ec4899',
             'permissions' => Permissions::encode(['assistants.use', 'diagnostics.view', 'llms.view'])],
        ]);
    }

    protected function tearDown(): void
    {
        Role::overrideRows(null);
        Role::forget();
    }

    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    /** Corps d'une méthode (jusqu'à l'accolade équilibrée). */
    private static function methode(string $src, string $nom): string
    {
        $pos = strpos($src, 'function ' . $nom . '(');
        self::assertNotFalse($pos, "Méthode absente : $nom");
        $i = (int)strpos($src, '{', (int)$pos) + 1;
        $depth = 1;
        $len = strlen($src);
        while ($i < $len && $depth > 0) {
            $depth += $src[$i] === '{' ? 1 : ($src[$i] === '}' ? -1 : 0);
            $i++;
        }
        return substr($src, (int)$pos, $i - (int)$pos);
    }

    // ------------------------------------------------------------------
    // Rôles
    // ------------------------------------------------------------------

    public function testLesRolesLivresPuisLesPersonnalises(): void
    {
        $all = Role::all();
        $this->assertSame(['admin', 'creator', 'user', 'gestion', 'support'], array_keys($all));
        $this->assertTrue($all['admin']['locked']);
        $this->assertFalse($all['creator']['locked']);
        $this->assertFalse($all['creator']['customized'], 'Sans surcharge, un rôle livré n’est pas « modifié ».');
        $this->assertTrue($all['gestion']['system'] === false && $all['gestion']['locked'] === false);
        $this->assertSame(Permissions::allKeys(), Role::permissionsFor('admin'));
        $this->assertSame(Permissions::defaultsFor('creator'), Role::permissionsFor('creator'));
        // Rôle inconnu (supprimé à la main en base) : les droits d'un utilisateur, jamais plus.
        $this->assertSame(Role::permissionsFor('user'), Role::permissionsFor('disparu'));
    }

    public function testUneSurchargeDUnRoleLivreEstSignalee(): void
    {
        Role::overrideRows([
            ['id' => 2, 'slug' => 'creator', 'name' => 'Créateur', 'permissions' => Permissions::encode(['assistants.use'])],
        ]);
        $creator = Role::find('creator');
        $this->assertTrue($creator['customized']);
        $this->assertSame(['assistants.use'], $creator['permissions']);
        $this->assertSame('Créateur', $creator['name'], 'Le nom d’un rôle livré vient du code, pas de la ligne.');
    }

    public function testLesDroitsDUnCompte(): void
    {
        $this->assertTrue(Role::userCan(self::ADMIN, 'users.delete'));
        $this->assertTrue(Role::userCan(self::ADMIN, 'nimporte.quoi'), 'L’administrateur a tout.');
        $this->assertTrue(Role::userCan(self::CREATOR, 'assistants.edit'));
        $this->assertFalse(Role::userCan(self::CREATOR, 'assistants.manage'));
        $this->assertFalse(Role::userCan(self::USER, 'assistants.view'));
        $this->assertTrue(Role::userCan(self::GESTION, 'users.edit'));
        $this->assertFalse(Role::userCan(self::GESTION, 'users.delete'));
        // Compte désactivé, absent, sans identifiant : aucun droit.
        $this->assertFalse(Role::userCan(['id' => 1, 'role' => 'admin', 'is_active' => 0], 'users.view'));
        $this->assertFalse(Role::userCan(null, 'assistants.use'));
        $this->assertFalse(Role::userCan(['id' => 0, 'role' => 'admin'], 'assistants.use'));
        // Champ `is_active` absent (ligne partielle) : on lit le rôle.
        $this->assertTrue(Role::userCan(['id' => 3, 'role' => 'user'], 'assistants.use'));
    }

    public function testPersonneNeDonneUnRolePlusLargeQueLeSien(): void
    {
        $this->assertSame(['admin', 'creator', 'user', 'gestion', 'support'], array_keys(Role::assignableBy(self::ADMIN)));
        // Le gestionnaire a les droits d'un utilisateur + comptes/groupes : il
        // donne « Utilisateur » et son propre rôle, jamais Créateur (des droits
        // qu'il n'a pas), ni Support (diagnostic, LLM), ni Administrateur.
        $this->assertSame(['user', 'gestion'], array_keys(Role::assignableBy(self::GESTION)));
        $this->assertSame([], Role::assignableBy(null));

        $this->assertTrue(Role::canManageAccount(self::ADMIN, self::ADMIN));
        $this->assertTrue(Role::canManageAccount(self::GESTION, self::USER));
        $this->assertFalse(Role::canManageAccount(self::GESTION, self::ADMIN), 'Jamais un administrateur.');
        $this->assertFalse(Role::canManageAccount(self::GESTION, self::CREATOR), 'Jamais un compte plus large que le sien.');
        $this->assertFalse(Role::canManageAccount(self::GESTION, self::SUPPORT));
        $this->assertFalse(Role::canManageAccount(null, self::USER));
    }

    public function testLibellesEtCouleurs(): void
    {
        $this->assertSame('Admin', Role::label('admin'));
        $this->assertSame('Créateur', Role::label('creator'));
        $this->assertSame('Utilisateur', Role::label('user'));
        $this->assertSame('Gestion des comptes', Role::label('gestion'), 'Un rôle personnalisé garde son nom, jamais traduit.');
        $this->assertSame('En attente', Role::label('pending'));
        $this->assertSame('Disparu', Role::label('disparu'));
        $this->assertSame('#0ea5e9', Role::color('gestion'));
        $this->assertSame('#94a3b8', Role::color('disparu'));
        $this->assertTrue(Role::isSystem('creator'));
        $this->assertFalse(Role::isSystem('gestion'));
    }

    /**
     * Les rôles livrés se lisent dans la langue de l'écran (nom, libellé
     * court, phrase), sans relire la base quand la locale change ; un rôle
     * personnalisé garde le nom que l'administrateur lui a donné. Un nom de
     * rôle livré est réservé dans TOUTES les langues.
     */
    public function testLesRolesLivresSuiventLaLangueDeLEcran(): void
    {
        $this->assertSame('Administrateur', Role::find('admin')['name']);
        $avant = I18n::locale();
        I18n::use('en');
        try {
            $this->assertSame('Administrator', Role::find('admin')['name']);
            $this->assertSame('Creator', Role::find('creator')['short']);
            $this->assertNotSame('', Role::find('user')['description']);
            $this->assertSame('Gestion des comptes', Role::find('gestion')['name'], 'Un rôle personnalisé n’est jamais traduit.');
            $noms = Role::builtinNames();
            foreach (['Administrateur', 'Administrator', 'Admin', 'Créateur', 'Creator', 'Utilisateur', 'User'] as $nom) {
                $this->assertContains($nom, $noms);
            }
            $this->assertSame('en', I18n::locale(), 'builtinNames() rend la langue de l’écran.');
        } finally {
            I18n::use($avant);
        }
        $this->assertSame('Administrateur', Role::find('admin')['name'], 'Retour au français sans relire la base.');
    }

    public function testSaisieDUnRolePersonnalise(): void
    {
        $data = Role::normalizeInput(['name' => "  Responsable \n RH ", 'description' => str_repeat('x', 400), 'color' => '#EC4899'], ['Support']);
        $this->assertSame('Responsable RH', $data['name']);
        $this->assertSame(Role::DESCRIPTION_MAX, mb_strlen($data['description']));
        $this->assertSame('#ec4899', $data['color']);
        $this->assertSame(Role::COLORS[0], Role::normalizeInput(['name' => 'X', 'color' => 'red'], [])['color']);
        foreach ([['name' => ''], ['name' => str_repeat('a', Role::NAME_MAX + 1)], ['name' => 'support']] as $refus) {
            try {
                Role::normalizeInput($refus, ['Support', 'Admin', 'Utilisateur']);
                $this->fail('Saisie acceptée à tort : ' . json_encode($refus));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        // Un nom déjà pris, à la casse et aux accents près.
        $this->expectException(\InvalidArgumentException::class);
        Role::normalizeInput(['name' => 'UTILISATEUR'], ['Utilisateur']);
    }

    public function testIdentifiantDUnRolePersonnalise(): void
    {
        $this->assertSame('responsable-rh', Role::slugFrom('Responsable RH', []));
        $this->assertSame('responsable-rh-2', Role::slugFrom('Responsable  RH !', ['responsable-rh']));
        $this->assertSame('responsable', Role::slugFrom('Responsable assistants', []), 'Coupé à un mot entier.');
        $this->assertLessThanOrEqual(Role::SLUG_MAX, strlen(Role::slugFrom(str_repeat('abcdefghij', 5), [])));
        // Jamais un identifiant réservé, ni vide.
        $this->assertNotContains(Role::slugFrom('Admin', []), Role::RESERVED_SLUGS);
        $this->assertNotContains(Role::slugFrom('Créateur', []), Role::RESERVED_SLUGS);
        $this->assertNotSame('', Role::slugFrom('!!!', []));
        $this->assertSame('ecole-elementaire', Role::slugFrom('École élémentaire', []));
    }

    // ------------------------------------------------------------------
    // Pages de l'administration
    // ------------------------------------------------------------------

    public function testLesRolesLivresOuvrentLesMemesPagesQuAvant(): void
    {
        foreach (array_keys(AdminAccess::PAGES) as $page) {
            $this->assertTrue(AdminAccess::canOpen($page, self::ADMIN), "admin : $page");
            $this->assertFalse(AdminAccess::canOpen($page, self::USER), "utilisateur : $page");
            $this->assertFalse(AdminAccess::readOnly($page, self::ADMIN), "admin en lecture seule : $page");
        }
        // Le créateur : ses assistants et ses bases, rien d'autre.
        $creator = array_values(array_filter(array_keys(AdminAccess::PAGES), static fn(string $p): bool => AdminAccess::canOpen($p, self::CREATOR)));
        $this->assertSame(['profiles', 'profiles-new', 'knowledge-bases'], $creator);
        $this->assertFalse(AdminAccess::hasAdministration(self::CREATOR), 'Pas de bouton « Admin » pour un créateur.');
        $this->assertSame([], AdminAccess::visibleEntries(self::CREATOR), 'Ses pages sont dans l’espace de travail.');
        $this->assertSame('/admin/profiles', AdminAccess::home(self::CREATOR), '/admin reste un favori valable.');
        $this->assertTrue(AdminAccess::hasAdministration(self::ADMIN));
        $this->assertSame('/admin', AdminAccess::home(self::ADMIN));
        $this->assertNull(AdminAccess::home(self::USER));
        $this->assertNull(AdminAccess::home(null));
        // Page inconnue : administrateur seul.
        $this->assertTrue(AdminAccess::canOpen('page-future', self::ADMIN));
        $this->assertFalse(AdminAccess::canOpen('page-future', self::GESTION));
    }

    public function testUnRolePersonnaliseOuvreSesPagesEtSeulementElles(): void
    {
        $this->assertTrue(AdminAccess::canOpen('users', self::GESTION));
        $this->assertTrue(AdminAccess::canOpen('user-groups', self::GESTION));
        $this->assertFalse(AdminAccess::canOpen('roles', self::GESTION), 'Les rôles restent aux administrateurs.');
        $this->assertFalse(AdminAccess::canOpen('backup', self::GESTION));
        $this->assertFalse(AdminAccess::canOpen('dashboard', self::GESTION));
        $this->assertTrue(AdminAccess::hasAdministration(self::GESTION));
        // La rubrique « Personnes » mène à la première page qu'il ouvre.
        $entries = AdminAccess::visibleEntries(self::GESTION);
        $this->assertSame(['people'], array_keys($entries));
        $this->assertSame('/admin/users', $entries['people']['href']);
        $this->assertSame('/admin/users', AdminAccess::home(self::GESTION));
        // Groupes : vus, pas modifiables → lecture seule ; comptes : modifiables.
        $this->assertTrue(AdminAccess::readOnly('user-groups', self::GESTION));
        $this->assertFalse(AdminAccess::readOnly('users', self::GESTION));
        // Support : Gestion des LLM en lecture, diagnostic sans réparation.
        $this->assertTrue(AdminAccess::readOnly('llms-keys', self::SUPPORT));
        $this->assertTrue(AdminAccess::readOnly('diagnostics', self::SUPPORT));
        $this->assertFalse(AdminAccess::readOnly('logs', self::SUPPORT), 'Une page sans écriture n’est jamais « en lecture seule ».');
        $this->assertSame(['models', 'maintenance'], array_keys(AdminAccess::visibleEntries(self::SUPPORT)));
        $this->assertSame('/admin/llms', AdminAccess::visibleEntries(self::SUPPORT)['models']['href']);
        $this->assertSame('/admin/diagnostics', AdminAccess::visibleEntries(self::SUPPORT)['maintenance']['href'], 'Maintenance mène à la première page ouverte.');
    }

    public function testLesPointsARegler(): void
    {
        $constats = [
            ['id' => 'a', 'entry' => 'users'],
            ['id' => 'b', 'entry' => 'llms-models'],
            ['id' => 'c', 'entry' => 'tools'],
        ];
        $this->assertSame($constats, AdminAccess::filterAttention($constats, self::ADMIN));
        $this->assertSame(['a'], array_column(AdminAccess::filterAttention($constats, self::GESTION), 'id'));
        $this->assertSame(['b'], array_column(AdminAccess::filterAttention($constats, self::SUPPORT), 'id'));
        $this->assertSame([], AdminAccess::filterAttention($constats, self::USER));
    }

    public function testLesTablesDAccesSontCoherentes(): void
    {
        $this->assertSame(array_keys(AdminMenu::ENTRIES), array_keys(AdminAccess::ENTRY_PAGES), 'Une entrée de menu par entrée d’accès.');
        foreach (AdminAccess::ENTRY_PAGES as $entry => $pages) {
            foreach ($pages as $page) {
                $this->assertArrayHasKey($page, AdminAccess::PAGES, "$entry → $page");
                $this->assertArrayHasKey($page, AdminAccess::PAGE_HREFS, "$entry → $page : adresse manquante");
            }
        }
        // Assistants et bases : pages de l'espace de travail, dans aucune rubrique.
        $rubriques = array_merge(...array_values(AdminAccess::ENTRY_PAGES));
        foreach (['profiles', 'profiles-new', 'knowledge-bases'] as $page) {
            $this->assertNotContains($page, $rubriques, "$page vit dans l'espace de travail, pas dans l'administration.");
        }
        // Chaque point à régler désigne une page dont le droit est connu :
        // sinon il retomberait sur « administrateur seul » sans le dire.
        foreach (array_keys(AdminAttention::ENTRIES) as $entry) {
            $this->assertArrayHasKey($entry, AdminAccess::PAGES, "Point à régler sans page d'accès : $entry");
        }
        // Chaque page qu'un gabarit d'administration pose ($page) a son droit :
        // sinon elle retomberait sur « administrateur seul » sans que personne le décide.
        $pages = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates/admin')) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')
                && preg_match_all("/\\\$page\\s*=\\s*(?:\\\$isCreate \\? '([a-z-]+)' : )?'([a-z-]+)'/", (string)file_get_contents($f->getPathname()), $m)) {
                $pages = array_merge($pages, array_filter($m[1]), $m[2]);
            }
        }
        $pages[] = 'diagnostics'; // posée par HealthController
        $this->assertGreaterThan(20, count(array_unique($pages)));
        foreach (array_unique($pages) as $page) {
            $this->assertArrayHasKey($page, AdminAccess::PAGES, "Page sans droit : $page");
        }
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLEcranDesRolesEstReserveAuxAdministrateurs(): void
    {
        $routes = self::lire('public/index.php');
        $index  = strpos($routes, "\$router->get('/admin/roles',");
        $slug   = strpos($routes, "\$router->post('/admin/roles/{slug}',");
        $this->assertNotFalse($index);
        $this->assertNotFalse($slug);
        foreach (['duplicate', 'reset', 'delete'] as $action) {
            $pos = strpos($routes, "\$router->post('/admin/roles/{slug}/$action',");
            $this->assertNotFalse($pos, $action);
            $this->assertLessThan($slug, $pos, "/admin/roles/{slug}/$action doit précéder /admin/roles/{slug}");
        }
        $ctrl = self::lire('src/Controllers/RoleController.php');
        foreach (['index', 'create', 'update', 'duplicate', 'reset', 'delete'] as $action) {
            $corps = self::methode($ctrl, $action);
            $this->assertStringContainsString('AuthMiddleware::requireAdmin();', substr($corps, 0, 220), "$action : administrateurs seuls");
        }
        // Les droits enregistrés passent par la fermeture du serveur, quoi qu'on envoie.
        $this->assertStringContainsString("Permissions::fromPost(\$_POST['perms'] ?? [])", self::methode($ctrl, 'update'));
        // Sans ?role= : le premier rôle de la liste, en détail (plus de vue d'ensemble).
        $this->assertStringContainsString('$slug = (string)array_key_first($roles);', self::methode($ctrl, 'index'));
        $this->assertStringContainsString("error_log('[Rôles] '", $ctrl, 'Chaque écriture est journalisée.');
    }

    public function testLEcranSEnregistreALaMainEtSePresenteBien(): void
    {
        $tpl = self::lire('templates/admin/roles.php');
        $this->assertStringNotContainsString('data-autosave', $tpl, 'Des droits ne changent pas à moitié cochés : enregistrement manuel.');
        $this->assertStringContainsString("\$page = 'roles'; require ROOT . '/templates/partials/admin-nav.php';", $tpl);
        $this->assertStringContainsString("id=\"roleSaveBtn\" disabled><?= te('Enregistrer') ?></button>", $tpl);
        $this->assertStringContainsString('name="perms[<?= htmlspecialchars($feature) ?>][]"', $tpl);
        // Le catalogue passe par ses accesseurs : libellés dans la langue de l'écran.
        foreach (['Permissions::sections()', 'Permissions::adminOnly()', 'Permissions::featuresIn($section)', 'Role::COLORS', 'Permissions::ACTIONS'] as $source) {
            $this->assertStringContainsString($source, $tpl, "Le gabarit lit le catalogue : $source");
        }
        // Le rôle Administrateur ne se coche pas ; la suppression ne promeut personne.
        $this->assertStringContainsString("<?= \$locked ? ' disabled' : '' ?>", $tpl);
        $this->assertStringContainsString("if (\$r['slug'] === \$slug || \$r['slug'] === Role::ADMIN) { continue; }", $tpl);
        // Le catalogue des implications voyage vers le script de la page.
        $this->assertStringContainsString("'catalog' => \$catalog,", $tpl);
        $this->assertStringContainsString('/assets/js/roles.js', $tpl);
        $this->assertStringContainsString('/assets/css/roles.css', $tpl);

        $js = self::lire('public/assets/js/roles.js');
        $this->assertStringContainsString('function propagate(key, on)', $js);
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $js);
        $this->assertStringContainsString('comptes portent ce rôle. Ils perdront :', $js, 'Un retrait de droits porté par des comptes se confirme.');
        $this->assertStringContainsString('var ACTIONS = Object.keys(CAT.actions || {});', $js, 'Les colonnes viennent du catalogue (Gérer compris).');

        $css = self::lire('public/assets/css/roles.css');
        $this->assertStringContainsString('@container (max-width: 640px)', $css, 'Vue en cartes sur un éditeur étroit.');
        $this->assertStringContainsString('body.admin-page .card.roles-editor { overflow: visible; }', $css, 'Sinon la barre collante ne colle pas sur mobile.');
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function testAucuneLegendeDansLaPageToutEnInfobulle(): void
    {
        $tpl = self::lire('templates/admin/roles.php');
        // Plus de légende posée dans la page, ni de ligne d'aide sous les tableaux.
        foreach (['role-legend', 'role-hint', 'role-row-details', 'Ce que veulent dire les colonnes', 'Ce que chaque case ouvre', 'role-scope'] as $parti) {
            $this->assertStringNotContainsString($parti, $tpl, "Légende restée dans la page : $parti");
        }
        // Les définitions vivent dans des sources cachées, reliées à leur
        // élément pour la bulle (data-tip) ET pour les lecteurs d'écran
        // (aria-describedby) — en-têtes de colonne, lignes, cases, sections.
        $this->assertStringContainsString('<button type="button" class="role-col-btn" data-tip="<?= $colTip ?>" aria-describedby="<?= $colTip ?>">', $tpl);
        $this->assertStringContainsString('<button type="button" class="role-row-btn" data-tip="<?= $rowTip ?>" aria-describedby="<?= $rowTip ?>">', $tpl);
        $this->assertStringContainsString('<label class="role-cell" data-tip="<?= $cellTip ?>">', $tpl);
        $this->assertStringContainsString('aria-describedby="<?= $cellTip ?>"', $tpl);
        $this->assertStringContainsString('class="role-info-btn" data-tip="tips-<?= $section ?>"', $tpl);
        $this->assertSame(substr_count($tpl, 'data-tip="'), substr_count($tpl, 'class="role-tip-src'), 'Chaque élément à infobulle a sa source.');
        // Ce que disent les bulles vient du catalogue.
        foreach (['Permissions::actionDefinition($action)', 'Permissions::viewUseNote()', "\$def['actions'][\$action]", "\$def['summary']", 'Permissions::naReason($feature, $action)', '$requiresLine($feature, $action)'] as $source) {
            $this->assertStringContainsString($source, $tpl, "Infobulle tirée du catalogue : $source");
        }

        // La bulle : au-dessus, au survol, au clavier, au doigt ; jamais lue en double.
        $js = self::lire('public/assets/js/roles.js');
        foreach (["tipRoot.addEventListener('pointerover'", "tipRoot.addEventListener('pointerup'", "tipRoot.addEventListener('focusin'", "e.pointerType === 'touch'", "e.key === 'Escape'", "tip.setAttribute('aria-hidden', 'true');", 'window.miaUiZoom', 'var top = Math.round(r.top - h - TIP_GAP);'] as $morceau) {
            $this->assertStringContainsString($morceau, $js, "Infobulle : $morceau");
        }
        $css = self::lire('public/assets/css/roles.css');
        $this->assertStringContainsString('.role-tip[hidden] { display: none !important; }', $css);
        $this->assertStringContainsString('.role-cb:disabled { cursor: default; pointer-events: none; }', $css, 'Au doigt, une case verrouillée montre quand même sa bulle.');
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\) \{[^}]*min-height: 44px;/', $css, 'Cibles de 44 px au doigt.');
        // En cartes, l'en-tête devient une rangée de pastilles « Colonnes » à toucher.
        $cartes = substr($css, (int)strpos($css, '@container (max-width: 640px)'));
        $this->assertStringContainsString('.role-matrix thead tr { display: flex;', $cartes);
    }

    public function testLaVueDEnsembleAEteRetiree(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/templates/admin/roles-matrix.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/public/assets/js/roles-matrix.js');
        $this->assertStringNotContainsString('/admin/roles/matrix', self::lire('public/index.php'));
        $this->assertNotContains('matrix', Role::RESERVED_SLUGS);
        $ctrl = self::lire('src/Controllers/RoleController.php');
        foreach (['saveMatrix', 'matrixMessage', 'levelsByRole', 'matrixCatalog'] as $mort) {
            $this->assertStringNotContainsString($mort, $ctrl);
        }
        $perm = self::lire('src/Auth/Permissions.php');
        foreach (['function ladder', 'function levelOf', 'function applyLevel', 'function levelPhrase', 'function matrixCatalog', 'LEVEL_LABELS', 'LEVEL_DEFINITIONS'] as $mort) {
            $this->assertStringNotContainsString($mort, $perm);
        }
        $tpl = self::lire('templates/admin/roles.php');
        $this->assertStringNotContainsString('roles-matrix', $tpl);
        $this->assertStringNotContainsString('Vue d’ensemble', $tpl);
        $this->assertStringNotContainsString('.rm-', self::lire('public/assets/css/roles.css'));
    }

    public function testLeMenuEtLesListesSuiventLesRoles(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');
        // Chaque page du menu n'est rendue que si le rôle l'ouvre ($navCan),
        // l'adresse venant d'AdminAccess::PAGE_HREFS.
        $this->assertStringContainsString("\$navSub('roles')", $nav);
        $this->assertStringContainsString('if (empty($navCan[$p])) {', $nav);
        $this->assertSame('/admin/roles', AdminAccess::PAGE_HREFS['roles']);
        $this->assertStringContainsString('AdminAccess::readOnly($page, $navUser)', $nav);
        $this->assertStringContainsString("banner.className = 'admin-readonly-banner';", $nav);

        $users = self::lire('templates/admin/users.php');
        $this->assertStringContainsString('foreach ($assignableRoles as $roleSlug => $roleDef)', $users, 'Les listes de choix ne proposent que les rôles qu’on peut donner.');
        $this->assertStringContainsString("new URLSearchParams(window.location.search).get('role')", $users, 'Le lien « N comptes » ouvre la liste filtrée.');
        $admin = self::lire('src/Controllers/AdminController.php');
        foreach (['createUser', 'updateUser'] as $methode) {
            $this->assertStringContainsString('checkedRoleChange(', self::methode($admin, $methode), "$methode contrôle le rôle donné");
        }
        $this->assertStringContainsString('Role::canManageAccount(', self::methode($admin, 'updateUser'));
        $this->assertStringContainsString('Role::canManageAccount(', self::methode($admin, 'deleteUser'));
        $this->assertStringContainsString('Role::canManageAccount(', self::methode($admin, 'resetPassword'));
    }

    public function testLesActionsHorsConnecteursNeDependentPasDuDroitConnecteurs(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $prop = new ReflectionClassConstant(ChatController::class, 'NON_CONNECTOR_ACTION_TOOLS');
        $outils = $prop->getValue();
        $this->assertSame([
            ScheduleTools::TOOL, AssistantDraft::TOOL, WorkflowEngine::GATE_TOOL, WorkflowDraft::TOOL,
            'executer_workflow_n8n', 'generer_presentation_gamma', 'nourrir_mybrain',
        ], $outils);
        $this->assertStringContainsString("'executer_workflow_n8n'", self::lire('src/Agent/Tools/N8nTools.php'));
        $this->assertStringContainsString("'generer_presentation_gamma'", self::lire('src/Agent/Tools/GammaTools.php'));
        $this->assertStringContainsString("'nourrir_mybrain'", self::lire('src/Agent/Tools/MyBrainTools.php'));
        // Les écritures des comptes reliés, elles, exigent le droit au clic.
        foreach (['creer_brouillon_gmail', 'creer_brouillon_outlook', 'creer_google_doc', 'creer_evenement_agenda', 'creer_tache', 'envoyer_message_slack'] as $ecriture) {
            $this->assertNotContains($ecriture, $outils);
        }
        $decide = self::methode(self::lire('src/Controllers/ChatController.php'), 'decideAction');
        $this->assertStringContainsString('!ConnectorRegistry::usableBy($user)', $decide);
    }

    public function testChargementEtMigration(): void
    {
        $classes = self::lire('config/classes.php');
        $boot    = self::lire('tests/bootstrap.php');
        foreach (['src/Auth/Permissions.php', 'src/Models/Role.php', 'src/Helpers/AdminAccess.php'] as $f) {
            $this->assertStringContainsString($f, $classes);
            $this->assertStringContainsString($f, $boot);
        }
        $this->assertStringContainsString('src/Controllers/RoleController.php', $classes);
        // Role lit Permissions : chargé APRÈS (pas d'autoloader).
        $this->assertLessThan(strpos($classes, 'src/Models/Role.php'), strpos($classes, 'src/Auth/Permissions.php'));
        $this->assertLessThan(strpos($boot, 'src/Models/Role.php'), strpos($boot, 'src/Auth/Permissions.php'));

        $sql = self::lire('database/migrations/090_roles.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS roles', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_roles_slug (slug)', $sql);
        $this->assertStringContainsString("('admin',   'Administrateur', 1)", $sql);
        $this->assertStringNotContainsString('ALTER TABLE users', $sql, 'Aucun compte touché par la migration.');
        $this->assertCount(1, glob(dirname(__DIR__, 2) . '/database/migrations/090_*.sql'), 'Un seul fichier 090.');
        // Le worker relit les rôles à chaque tour (un rôle modifié vaut au job suivant).
        $this->assertStringContainsString('Role::forget();', self::methode(self::lire('src/Jobs/JobQueue.php'), 'execute'));
        $this->assertStringContainsString('Role::forget();', self::lire('src/Schedule/Scheduler.php'));
        $this->assertStringContainsString('Role::forget();', self::lire('src/Workflow/WorkflowEngine.php'));
        // La session relit le compte une fois par requête : un rôle changé vaut aussitôt.
        $this->assertStringContainsString('self::syncSessionUser();', self::lire('src/Auth/AuthMiddleware.php'));
    }
}
