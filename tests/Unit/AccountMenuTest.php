<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Carte du compte, liens « Réglages » et « Admin », menu des réglages et
 * rubriques de l'administration — cf. CLAUDE.md « Menus en trois espaces ».
 *
 * Garde : AdminMenu (rubriques) reste en phase avec AdminAccess (pages,
 * adresses), ses libellés ont une traduction (clés calculées, que le test de
 * couverture d'I18nContractTest ne voit pas), « Réglages » et « Admin » mènent
 * DIRECTEMENT à leurs pages (plus de fenêtre intermédiaire), et le volet garde
 * la même largeur partout.
 */
final class AccountMenuTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    // ------------------------------------------------------------ AdminMenu

    /** Sept rubriques, chacune avec ses pages, ses libellés et son icône. */
    public function testLesRubriquesSontEnPhaseAvecLesPages(): void
    {
        $this->assertSame(
            ['dashboard', 'people', 'models', 'capabilities', 'costs', 'organisation', 'maintenance'],
            array_keys(AdminMenu::ENTRIES)
        );
        $this->assertSame(array_keys(AdminMenu::ENTRIES), array_keys(AdminAccess::ENTRY_PAGES));
        $this->assertSame(array_keys(AdminMenu::ENTRIES), array_keys(AdminMenu::forAdmin()));
        foreach (AdminMenu::ENTRIES as $key => $entry) {
            $this->assertArrayHasKey($entry['icon'], AdminMenu::ICONS, "Icône inconnue pour $key");
            $pages = AdminAccess::ENTRY_PAGES[$key];
            $this->assertSame(AdminAccess::PAGE_HREFS[$pages[0]], $entry['href'], "$key mène à sa première page");
            foreach ($pages as $page) {
                $this->assertArrayHasKey($page, AdminMenu::PAGE_LABELS, "Libellé manquant : $page");
            }
        }
        $this->assertStringStartsWith('<svg width="16" height="16"', AdminMenu::icon('kb'));
        $this->assertStringContainsString('M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z', AdminMenu::icon('kb'));
        // Les schémas des tâches, des assistants et des bases lisent ces icônes.
        foreach (['profiles', 'kb', 'llms', 'tools', 'connectors'] as $cle) {
            $this->assertArrayHasKey($cle, AdminMenu::ICONS);
        }
    }

    /** Les libellés calculés ont leur traduction — le test de couverture ne les voit pas. */
    public function testLesLibellesOntUneTraduction(): void
    {
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        foreach (AdminMenu::ENTRIES as $key => $entry) {
            $this->assertArrayHasKey($entry['label'], $en, "Traduction absente : rubrique $key");
        }
        $ctx = (new ReflectionClassConstant(AdminMenu::class, 'PAGE_CONTEXTS'))->getValue();
        foreach (AdminMenu::PAGE_LABELS as $page => $label) {
            $cle = isset($ctx[$page]) ? $ctx[$page] . '|' . $label : $label;
            $this->assertArrayHasKey($cle, $en, "Traduction absente : page $page");
        }
        $this->assertSame('Models', $en['llm|Modèles'], '« Modèles » des LLM ≠ modèles de workflows.');
        foreach (['Réglages', 'Mon compte', 'Administration', 'Admin', 'Espaces', 'À propos de MIA', 'Se déconnecter', 'Se déconnecter ?'] as $cle) {
            $this->assertArrayHasKey($cle, $en, "Traduction absente : $cle");
        }
    }

    /** Bouton « Admin » : tous les constats, dédiés compris, au pire niveau. */
    public function testLaPastilleDuBoutonAdminCompteTousLesConstats(): void
    {
        $constats = [
            ['id' => 'a', 'entry' => 'users',     'level' => AdminAttention::WARN,  'short' => 'x', 'text' => 'Comptes en attente', 'dedicated' => false],
            ['id' => 'b', 'entry' => 'tools',     'level' => AdminAttention::ALERT, 'short' => 'y', 'text' => 'Outil inutilisable', 'dedicated' => false],
            ['id' => AdminAttention::AUCUNE_CLE, 'entry' => 'llms-keys', 'level' => AdminAttention::ALERT, 'short' => 'aucune clé API', 'text' => AdminAttention::TEXTE_AUCUNE_CLE, 'dedicated' => true],
        ];
        $this->assertSame(['level' => AdminAttention::ALERT, 'count' => 3], AdminMenu::badge($constats));
        $this->assertSame(['level' => AdminAttention::WARN, 'count' => 1], AdminMenu::badge([$constats[0]]));
        $this->assertNull(AdminMenu::badge([]));
    }

    public function testLaPastilleDesCoutsSuitLeBudget(): void
    {
        $this->assertNull(AdminMenu::budgetSignal(['state' => AiBudget::STATE_OK, 'blocks' => false, 'pct' => 12]));
        $alerte = AdminMenu::budgetSignal(['state' => AiBudget::STATE_ALERT, 'blocks' => false, 'pct' => 85]);
        $this->assertSame(AdminAttention::WARN, $alerte['level']);
        $this->assertSame('Budget IA : 85 % consommé ce mois-ci.', $alerte['text']);
        $plafond = AdminMenu::budgetSignal(['state' => AiBudget::STATE_REACHED, 'blocks' => true, 'pct' => 100]);
        $this->assertSame(AdminAttention::ALERT, $plafond['level']);
        $this->assertSame('Budget IA atteint : les demandes sont suspendues pour tous.', $plafond['text']);
    }

    // -------------------------------------------------------------- Câblage

    /** La carte du compte : identité non cliquable, puis deux LIENS directs, libellés. */
    public function testLaCarteDuComptePorteDeuxLiensDirects(): void
    {
        $trigger = self::lire('templates/partials/user-account-trigger.php');
        $this->assertStringContainsString('<div class="user-dock">', $trigger);
        $this->assertStringContainsString('<div class="user-info" id="userAccountCard"', $trigger, 'L’identité n’est pas un second chemin vers les réglages.');
        $this->assertStringNotContainsString('aria-haspopup', $trigger);
        $this->assertLessThan(
            (int)strpos($trigger, "partials/user-account-actions.php'"),
            (int)strpos($trigger, 'id="userAccountCard"'),
            'Les boutons viennent APRÈS l’identité.'
        );
        $this->assertStringNotContainsString('AdminAttention', $trigger, 'L’avatar reste réservé aux connexions à reconnecter.');

        $actions = self::lire('templates/partials/user-account-actions.php');
        $this->assertStringContainsString('<a href="/settings" class="user-action-btn" id="userSettingsBtn"' . "\n", $actions, '« Réglages » mène à Mon profil, sans fenêtre.');
        $this->assertStringContainsString('<a href="/admin" class="user-action-btn is-admin" id="adminMenuBtn"' . "\n", $actions, '« Admin » mène à l’administration, sans fenêtre.');
        $this->assertStringNotContainsString('aria-haspopup', $actions);
        $this->assertStringContainsString("te('Réglages')", $actions);
        $this->assertStringContainsString("te('Admin')", $actions);
        $this->assertStringContainsString('AdminMenu::badge($uaAttention)', $actions);
        $this->assertStringContainsString('class="user-action-count', $actions);
        $this->assertStringContainsString("I18n::plural(\$uaBadge['count'], '{n} point à régler', '{n} points à régler')", $actions);
        $this->assertLessThan(
            (int)strpos($actions, 'id="adminMenuBtn"'),
            (int)strpos($actions, '<?php if ($uaHasAdmin): ?>'),
            '« Admin » est réservé à qui ouvre l’administration.'
        );
        $avatar = self::lire('templates/partials/header-avatar-btn.php');
        $this->assertStringContainsString('<a href="/settings" class="header-avatar-btn"' . "\n", $avatar);
        $this->assertStringNotContainsString('aria-haspopup', $avatar);
    }

    /** Plus de fenêtre intermédiaire : le « Centre de réglages » a disparu partout. */
    public function testPlusAucuneFenetreIntermediaire(): void
    {
        $racine = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($racine . '/templates/partials/settings-hub.php', 'Un clic de trop : la fenêtre est retirée.');
        $this->assertFileDoesNotExist($racine . '/templates/partials/user-account-menu.php', 'Les trois menus d’avant ne reviennent pas.');
        foreach ([
            'templates/chat/index.php', 'templates/partials/admin-nav.php', 'templates/partials/user-account-actions.php',
            'templates/partials/header-avatar-btn.php', 'templates/partials/user-account-trigger.php',
            'public/assets/js/user-menu.js', 'public/assets/js/command-palette.js', 'public/assets/css/main.css',
        ] as $f) {
            $source = self::lire($f);
            foreach (['settings-hub', 'data-hub-', 'MiaHub', 'hub-open', 'settingsHub'] as $trace) {
                $this->assertStringNotContainsString($trace, $source, "$f : reste de la fenêtre ($trace)");
            }
        }
        $this->assertDoesNotMatchRegularExpression('/^\.hub[ -{]/m', self::lire('public/assets/css/main.css'), 'Plus aucune règle de la fenêtre.');
        $js = self::lire('public/assets/js/user-menu.js');
        $this->assertStringNotContainsString('userSettingsMenu', $js);
        $this->assertStringNotContainsString('data-user-menu', $js);
        $this->assertStringContainsString("closest('a[href=\"/auth/logout\"]')", $js, 'Le garde des notifications vaut pour tout lien de déconnexion.');
    }

    /** À propos et déconnexion n'ont plus d'autre place que le bas du menu des réglages. */
    public function testLeMenuDesReglagesPorteAProposEtDeconnexion(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');
        $debut = (int)strpos($nav, "<?php if (\$navVariant === 'settings'): ?>");
        $fin   = (int)strpos($nav, "<?php elseif (\$navVariant === 'admin'): ?>");
        $this->assertGreaterThan($debut, $fin);
        $reglages = substr($nav, $debut, $fin - $debut);
        $this->assertStringContainsString('NavRegistry::accountFor($navUser)', $reglages);
        $this->assertStringContainsString('<div class="admin-nav-sep" role="separator"></div>', $reglages);
        $this->assertStringContainsString('<a href="/a-propos" class="admin-nav-link">', $reglages);
        // tj() rend un littéral entre guillemets doubles : l'attribut se délimite par des apostrophes.
        $this->assertStringContainsString("<a href=\"/auth/logout\" class=\"admin-nav-link is-danger\" onclick='return confirm(<?= tj('Se déconnecter ?') ?>);'>", $reglages);
        $this->assertLessThan(
            (int)strpos($reglages, 'href="/a-propos"'),
            (int)strpos($reglages, 'NavRegistry::accountFor($navUser)'),
            'Les pages du compte d’abord, À propos et déconnexion en bas.'
        );
    }

    /** Un volet de la même largeur partout : chat, réglages, administration. */
    public function testLeVoletGardeLaMemeLargeurPartout(): void
    {
        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString(".sidebar {\n    width: 260px;\n    min-width: 260px;", $css);
        $this->assertMatchesRegularExpression('/\n\.admin-sidebar \{\n    width: 260px;\n    min-width: 260px;/', $css, 'Réglages et administration : la largeur du chat.');
        $this->assertStringNotContainsString('width: 220px;', substr($css, (int)strpos($css, "\n.admin-sidebar {"), 300));
        // Tiroir mobile : celui du chat, 280 px.
        $tiroir = substr($css, (int)strpos($css, "    /* Admin — sidebar drawer */"), 400);
        $this->assertStringContainsString('width: 280px;', $tiroir);
        $this->assertStringContainsString("        width: 280px;\n        min-width: 0;\n        z-index: 100;", $css, 'Le tiroir du chat.');
    }

    public function testLaFeuilleDeStyleHabilleLaCarteEtLeMenu(): void
    {
        $css = self::lire('public/assets/css/main.css');
        foreach ([
            '.user-dock {',
            '.user-actions.has-admin { grid-template-columns: repeat(2, minmax(0, 1fr)); }',
            '.user-action-btn {',
            '.user-action-count {',
            '.admin-nav-sep {',
            '.admin-nav-link.is-danger {',
            '.space-tile {',
        ] as $regle) {
            $this->assertStringContainsString($regle, $css, "main.css : $regle");
        }
        $this->assertStringNotContainsString('.user-menu', $css, 'Plus aucune règle des menus d’avant.');
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaClasseEstChargeeEtLaDocumentationSuit(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('/src/Helpers/AdminMenu.php', self::lire($f));
            $this->assertStringContainsString('/src/Helpers/NavRegistry.php', self::lire($f));
        }
        $this->assertStringContainsString('id: menus-centre-de-reglages', self::lire('docs/NOUVEAUTES.md'));
        foreach (['docs/GUIDE-UTILISATEUR.md', 'docs/user/premiers-pas.md', 'docs/GUIDE-ADMIN.md'] as $doc) {
            $this->assertStringNotContainsString('Centre de réglages', self::lire($doc), "$doc parle encore de la fenêtre retirée.");
        }
        $this->assertStringContainsString('## Menus en trois espaces', self::lire('CLAUDE.md'));
        $this->assertStringNotContainsString('Centre de réglages', self::lire('CLAUDE.md'));
    }
}
