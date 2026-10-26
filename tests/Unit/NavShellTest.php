<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Les pages de l'espace de travail — Assistants, Bases de connaissances,
 * Tâches programmées, Workflows, Mes créations — gardent TOUJOURS le volet du
 * chat (NavShell) : elles ne sont plus dans aucun autre menu.
 */
final class NavShellTest extends TestCase
{
    private static function lire(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function testLesPagesDeLEspaceDeTravailGardentLeVolet(): void
    {
        foreach (['profiles', 'profiles-new', 'schedules', 'workflows', 'knowledge-bases', 'creations'] as $page) {
            $this->assertTrue(NavShell::keepsChatShell($page), $page);
            $this->assertTrue(NavShell::resolve($page), $page);
            $this->assertArrayHasKey($page, NavShell::SHORTCUT_OF, "$page : tuile allumée");
            $this->assertArrayHasKey(NavShell::SHORTCUT_OF[$page], NavRegistry::WORKSPACE, "$page : tuile de l'espace de travail");
        }
        foreach (['dashboard', 'users', 'llms-keys', 'profile', 'connections', 'knowledge-bases-sync', ''] as $page) {
            $this->assertFalse(NavShell::keepsChatShell($page), "$page garde son menu latéral");
        }
        $this->assertSame(array_keys(NavRegistry::WORKSPACE), array_values(array_unique(NavShell::SHORTCUT_OF)), 'Une tuile par page d’espace, dans l’ordre.');
        // Chaque page de l'espace se déclare sous cette valeur de $page.
        foreach (NavRegistry::WORKSPACE as $w) {
            $this->assertContains($w['page'], NavShell::PAGES);
        }
    }

    public function testPlusDeDrapeauDeSession(): void
    {
        $this->assertStringNotContainsString('NavShell::fromChat', self::lire('src/Controllers/ChatController.php'));
        $this->assertStringNotContainsString('nav_from_chat', self::lire('src/Helpers/NavShell.php'));
        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringContainsString('$navChatShell = NavShell::resolve($page);', $nav);
        $this->assertLessThan((int)strpos($nav, '<aside class="admin-sidebar" id="adminSidebar">'),
            (int)strpos($nav, '<aside class="admin-sidebar admin-sidebar--chat" id="adminSidebar">'));
        $this->assertStringContainsString("require ROOT . '/templates/partials/chat-shell-sidebar.php'", $nav);
    }

    public function testLeVoletRepliqueCeluiDuChat(): void
    {
        $shell = self::lire('templates/partials/chat-shell-sidebar.php');
        $chat  = self::lire('templates/chat/index.php');
        foreach (['sidebar-shortcuts.php'] as $partial) {
            $this->assertStringContainsString($partial, $shell);
            $this->assertStringContainsString($partial, $chat);
        }
        foreach (['class="sidebar-header"', '<div class="sidebar-start">', 'class="conv-search conv-search-trigger" data-palette-open', 'data-palette-kbd',
                  'id="convList" data-max-pinned="<?= Conversation::MAX_PINNED ?>"', 'class="sidebar-footer"'] as $morceau) {
            $this->assertStringContainsString($morceau, $shell, $morceau);
            $this->assertStringContainsString($morceau, $chat, $morceau);
        }
        $this->assertStringContainsString('<a href="/" class="sidebar-new-btn"', $shell, 'Nouvelle conversation = la page de discussion.');
        $this->assertStringContainsString('$sbNavActive        = NavShell::SHORTCUT_OF[$page] ?? \'\';', $shell);
        $this->assertStringContainsString('chat-shell.js', $shell);

        $raccourcis = self::lire('templates/partials/sidebar-shortcuts.php');
        $this->assertStringContainsString('$sbNavActive === $sbKey', $raccourcis, 'Chaque tuile peut être allumée.');

        $js = self::lire('public/assets/js/chat-shell.js');
        $this->assertStringNotContainsString("/chat/search?q=", $js, 'La recherche vit dans la palette (command-palette.js).');
        $this->assertStringContainsString("list.closest('.admin-sidebar--chat')", $js);

        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.admin-sidebar.admin-sidebar--chat {', $css);
        $this->assertStringContainsString('.space-tile.active {', $css);
    }

    /**
     * Le volet du chat élargi (260 px) vit dans main.css : une page qui la
     * chargeait sans version (Assistants, Bases de connaissances) recevait la
     * feuille du cache, sans la règle — le volet rétrécissait à 220 px. Toute
     * feuille de style d'un gabarit porte donc sa version (filemtime).
     */
    public function testAucuneFeuilleDeStyleSansVersion(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $src = (string)file_get_contents($f->getPathname());
            $this->assertDoesNotMatchRegularExpression('#href="/assets/css/[a-z-]+\.css"#', $src, $f->getPathname() . ' : feuille de style sans version');
        }
    }

    public function testChargement(): void
    {
        $this->assertStringContainsString("'/src/Helpers/NavShell.php'", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Helpers/NavShell.php'", self::lire('tests/bootstrap.php'));
    }
}
