<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Garde de NavRegistry, source unique des destinations (cf. CLAUDE.md « Menus
 * en trois espaces, Centre de réglages ») : chaque page dans un seul espace,
 * des adresses qui existent, des droits respectés, des libellés traduits.
 */
final class NavRegistryTest extends TestCase
{
    private const ADMIN   = ['id' => 1, 'role' => 'admin', 'is_active' => 1];
    private const CREATOR = ['id' => 2, 'role' => 'creator', 'is_active' => 1];
    private const USER    = ['id' => 3, 'role' => 'user', 'is_active' => 1];

    /** @var array{0:mixed,1:mixed} */
    private array $settingsAvant;

    protected function setUp(): void
    {
        // Rôles livrés seulement, sans base ; réglages vides (tâches et
        // workflows proposés par défaut) sans lire la table.
        Role::overrideRows([]);
        $cache  = new ReflectionProperty(Settings::class, 'cache');
        $loaded = new ReflectionProperty(Settings::class, 'loaded');
        $this->settingsAvant = [$cache->getValue(), $loaded->getValue()];
        $cache->setValue(null, []);
        $loaded->setValue(null, true);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $this->settingsAvant[0]);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, $this->settingsAvant[1]);
        Role::overrideRows(null);
        Role::forget();
    }

    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    public function testChaquePageVitDansUnSeulEspace(): void
    {
        $pages = [];
        foreach (NavRegistry::WORKSPACE as $w) {
            $pages[] = $w['page'];
        }
        foreach (NavRegistry::ACCOUNT as $a) {
            $pages[] = 'settings:' . $a['page']; // les $page des réglages sont d'un autre espace de noms
        }
        foreach (AdminAccess::ENTRY_PAGES as $liste) {
            foreach ($liste as $p) {
                $pages[] = $p;
            }
        }
        $this->assertSame($pages, array_values(array_unique($pages)), 'Une page apparaît dans deux espaces : une page, une entrée.');
        // Assistants et Bases ne sont plus des rubriques de l'administration.
        $this->assertArrayNotHasKey('profiles', AdminMenu::ENTRIES);
        $this->assertArrayNotHasKey('kb', AdminMenu::ENTRIES);
    }

    public function testLesPagesDEspaceGardentLeVoletDuChat(): void
    {
        foreach (NavRegistry::WORKSPACE as $key => $w) {
            $this->assertTrue(NavShell::keepsChatShell($w['page']), "$key : sa page doit garder le volet du chat.");
            $this->assertSame($key, NavShell::SHORTCUT_OF[$w['page']]);
        }
        $this->assertSame(array_keys(NavRegistry::WORKSPACE), array_values(array_unique(array_values(NavShell::SHORTCUT_OF))));
    }

    public function testChaqueAdresseEstUneRouteDeLApplication(): void
    {
        $index = self::lire('public/index.php');
        preg_match_all("~\\\$router->get\\(\\s*'([^']+)'~", $index, $m);
        $routes = $m[1];
        $this->assertNotEmpty($routes);
        $adresses = array_merge(
            array_column(NavRegistry::WORKSPACE, 'href'),
            array_column(NavRegistry::ACCOUNT, 'href'),
            array_values(AdminAccess::PAGE_HREFS),
            ['/a-propos', '/admin/logs', '/admin/knowledge-bases/vector', '/admin/agent', '/admin/pruning']
        );
        foreach ($adresses as $href) {
            $chemin = (string)strtok($href, '#');
            $this->assertContains($chemin, $routes, "Adresse sans route GET : $href");
        }
    }

    public function testLesDroitsDecidentDeCeQuiEstPropose(): void
    {
        // Simple utilisateur : ses tâches et ses créations, aucune administration.
        $this->assertSame(['schedules', 'creations'], array_keys(NavRegistry::workspaceFor(self::USER)));
        $this->assertSame([], NavRegistry::adminFor(self::USER));
        $this->assertSame(array_keys(NavRegistry::ACCOUNT), array_keys(NavRegistry::accountFor(self::USER)));

        // Créateur : tout l'espace de travail, aucune rubrique d'administration
        // par défaut ; il arrive quand même sur ses assistants.
        $this->assertSame(array_keys(NavRegistry::WORKSPACE), array_keys(NavRegistry::workspaceFor(self::CREATOR)));
        $this->assertSame([], NavRegistry::adminFor(self::CREATOR));
        $this->assertSame('/admin/profiles', AdminAccess::home(self::CREATOR));

        // Administrateur : les sept rubriques, dans l'ordre du menu, chacune avec ses pages.
        $admin = NavRegistry::adminFor(self::ADMIN);
        $this->assertSame(array_keys(AdminMenu::ENTRIES), array_keys($admin));
        foreach ($admin as $key => $r) {
            $this->assertSame(AdminAccess::ENTRY_PAGES[$key], array_keys($r['pages']), "$key : pages de la rubrique");
            foreach ($r['pages'] as $page => $p) {
                $this->assertSame(AdminAccess::PAGE_HREFS[$page], $p['href']);
                $this->assertNotSame('', $p['text']);
            }
        }
        $this->assertSame([], NavRegistry::workspaceFor(null));
        $this->assertSame([], NavRegistry::accountFor(null));
    }

    public function testLaPaletteRespecteLesEspacesEtLesDroits(): void
    {
        $user = NavRegistry::paletteEntries(self::USER, ['gmail']);
        $this->assertNotEmpty($user);
        $this->assertNotContains('admin', array_column($user, 'space'), 'Un simple utilisateur ne voit aucune page d’administration.');
        $hrefs = array_column($user, 'href');
        $this->assertContains('/settings/connections#connector-gmail', $hrefs, 'La connexion à reconnecter est proposée.');
        $this->assertNotContains('/admin/profiles/new', $hrefs);
        $this->assertContains('/', $hrefs, 'Nouvelle conversation est proposée à tous.');

        $admin = NavRegistry::paletteEntries(self::ADMIN);
        $this->assertSame(['work', 'me', 'admin'], array_values(array_unique(array_column($admin, 'space'))));
        foreach ($admin as $e) {
            $this->assertSame(['kind', 'space', 'label', 'path', 'href', 'keys', 'icon'], array_keys($e));
            $this->assertStringContainsString('<svg', NavRegistry::icon($e['icon']), "Icône inconnue : {$e['icon']}");
            $this->assertContains($e['kind'], ['page', 'action']);
            $this->assertNotSame('', $e['label']);
            $this->assertStringStartsWith('/', $e['href']);
        }
        // Les onglets internes (journaux, élagage…) se trouvent aussi.
        $this->assertContains('/admin/pruning', array_column($admin, 'href'));
        $this->assertSame([], NavRegistry::paletteEntries(null));
    }

    public function testLesLibellesSontTraduits(): void
    {
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        $cles = [];
        foreach (NavRegistry::WORKSPACE as $w) {
            array_push($cles, $w['label'], $w['short']);
        }
        foreach (NavRegistry::ACCOUNT as $a) {
            $cles[] = $a['label'];
        }
        foreach (AdminMenu::ENTRIES as $e) {
            $cles[] = $e['label'];
        }
        foreach (array_merge(...array_values(AdminAccess::ENTRY_PAGES)) as $page) {
            $ctx = AdminMenu::PAGE_CONTEXTS[$page] ?? null;
            $cles[] = ($ctx !== null ? $ctx . '|' : '') . AdminMenu::pageLabel($page);
        }
        array_push($cles, 'Espace de travail', 'Mon compte', 'Administration', 'Réglages', 'Espaces');
        foreach (array_unique($cles) as $cle) {
            $this->assertArrayHasKey($cle, $en, "Traduction absente : $cle");
        }
    }

    /** La palette dit la même chose que le menu latéral : mêmes accesseurs (AdminMenu, AdminTabs). */
    public function testLaPaletteParleCommeLeMenu(): void
    {
        $avant = I18n::locale();
        I18n::use('en');
        try {
            $parAdresse = [];
            foreach (NavRegistry::paletteEntries(self::ADMIN) as $e) {
                $parAdresse[$e['href']] = $e;
            }
            $this->assertSame('Pruning', $parAdresse['/admin/pruning']['label'] ?? null, 'Onglet : libellé d’AdminTabs.');
            $this->assertSame('Administration › Maintenance', $parAdresse['/admin/pruning']['path'] ?? null);
            $this->assertSame(AdminMenu::pageText('llms-models'), $parAdresse['/admin/llms/models']['label'] ?? null);
            $this->assertSame('Models', $parAdresse['/admin/llms/models']['label'] ?? null);
            $this->assertSame('Administration › ' . AdminMenu::label('people'), $parAdresse['/admin/users']['path'] ?? null);
        } finally {
            I18n::use($avant);
        }
    }

    public function testEstChargeeApresSesDependances(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = self::lire($f);
            $nav = strpos($src, 'Helpers/NavRegistry.php');
            $this->assertNotFalse($nav, "$f : NavRegistry n'est pas chargée.");
            $this->assertGreaterThan((int)strpos($src, 'Helpers/AdminAccess.php'), $nav, "$f : NavRegistry avant AdminAccess.");
            $this->assertGreaterThan((int)strpos($src, 'Helpers/AdminMenu.php'), $nav, "$f : NavRegistry avant AdminMenu.");
        }
    }
}
