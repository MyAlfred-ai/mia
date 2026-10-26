<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Menu d'administration en sept rubriques (AdminMenu), pages sœurs reliées par
 * des onglets (AdminTabs), titres de page = libellés du menu. Assistants et
 * bases n'y sont plus : pages de l'espace de travail (NavRegistry).
 */
final class AdminNavTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    public function testOrdreDuMenu(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');
        $reperes = [
            '<a href="/admin" class="admin-nav-link',
            "\$navGroup('people',",
            "\$navGroup('models',",
            "\$navGroup('capabilities',",
            "\$navGroup('costs',",
            "\$navGroup('organisation',",
            "\$navGroup('maintenance',",
        ];
        $precedent = -1;
        foreach ($reperes as $r) {
            $pos = strpos($nav, $r);
            $this->assertNotFalse($pos, "Repère absent : $r");
            $this->assertGreaterThan($precedent, $pos, "Ordre du menu rompu à : $r");
            $precedent = $pos;
        }
        // Chaque page de chaque rubrique est rendue, et seulement elles.
        foreach (AdminAccess::ENTRY_PAGES as $rubrique => $pages) {
            if ($rubrique === 'dashboard') {
                continue;
            }
            foreach ($pages as $page) {
                $this->assertStringContainsString("\$navSub('$page'", $nav, "Page absente du menu : $page");
            }
        }
        // Le libellé et l'icône d'une rubrique, l'adresse et le libellé d'une
        // page : lus à la source unique, jamais recopiés.
        $this->assertStringContainsString('htmlspecialchars(AdminMenu::label($key))', $nav);
        $this->assertStringContainsString("AdminMenu::icon(AdminMenu::ENTRIES[\$key]['icon'], 15)", $nav);
        $this->assertStringContainsString('htmlspecialchars(AdminAccess::PAGE_HREFS[$p])', $nav);
        $this->assertStringContainsString('htmlspecialchars(AdminMenu::pageText($p))', $nav);
        $this->assertStringNotContainsString("href=\"/admin/profiles\"", $nav, 'Les assistants sont dans l’espace de travail.');
        $this->assertStringNotContainsString("href=\"/admin/knowledge-bases\"", $nav, 'Les bases aussi.');
    }

    public function testAnciensLibellesDisparus(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');
        foreach (['Groupes utilisateurs', 'Modèles activés', 'Comportement par défaut', 'Créer un assistant',
                  'Gérer les assistants', 'Gérer mes bases', 'Administrer la base vectorielle', 'Gestion crédits',
                  'data-nav-group="profiles"', 'href="/admin/llms/defaults"', 'Gestion LLMs', 'Coûts IA',
                  '>Configuration<', '>Utilisateurs<'] as $ancien) {
            $this->assertStringNotContainsString($ancien, $nav, "Ancien libellé resté dans le menu : $ancien");
        }
        $this->assertSame(
            ['Tableau de bord', 'Personnes', 'Modèles IA', 'Capacités', 'Coûts', 'Organisation', 'Maintenance'],
            array_column(AdminMenu::ENTRIES, 'label')
        );
        $this->assertSame('Consommation', AdminMenu::pageLabel('usage'));
        $this->assertSame('Sources synchronisées', AdminMenu::pageLabel('knowledge-bases-sync'));
        $this->assertContains('knowledge-bases-sync', AdminAccess::ENTRY_PAGES['capabilities']);
    }

    public function testChaqueOngletPointeUneRouteDeclareeEtChaquePageLAffiche(): void
    {
        $routes = self::lire('public/index.php');
        $pages = [
            AdminTabs::DIAGNOSTIC => ['diagnostics' => 'templates/admin/diagnostics.php', 'logs' => 'templates/admin/logs.php', 'knowledge-bases-vector' => 'templates/admin/vector-database.php', 'agent' => 'templates/admin/agent.php'],
            AdminTabs::DONNEES    => ['backup' => 'templates/admin/backup.php', 'pruning' => 'templates/admin/pruning.php'],
        ];
        foreach ($pages as $groupe => $gabarits) {
            $this->assertSame(array_keys($gabarits), AdminTabs::pages($groupe));
            foreach (AdminTabs::onglets($groupe) as $page => [$href]) {
                $this->assertStringContainsString("get('{$href}',", $routes, "Route GET absente : $href");
                $this->assertSame($groupe, AdminTabs::groupeDe($page));
                $const = $groupe === AdminTabs::DIAGNOSTIC ? 'DIAGNOSTIC' : 'DONNEES';
                $this->assertStringContainsString("\$ongletsGroupe = AdminTabs::{$const}; require ROOT . '/templates/partials/admin-tabs.php';", self::lire($gabarits[$page]));
            }
        }
        $this->assertNull(AdminTabs::groupeDe('users'));
        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringContainsString('AdminTabs::pages(AdminTabs::DIAGNOSTIC)', $nav);
        $this->assertStringContainsString('overflow-x: auto;', self::lire('public/assets/css/main.css'));
    }

    public function testTitresDePageAlignesSurLeMenu(): void
    {
        $titres = [
            'templates/admin/usage.php' => "<h1><?= te('Consommation') ?></h1>",
            'templates/admin/credits.php' => "<h1><?= te('Tarifs & crédits') ?></h1>",
            'templates/admin/profiles/list.php' => "<h1><?= te('Assistants') ?></h1>",
            'templates/admin/logs.php' => "<h1><?= te('Journaux') ?></h1>",
            'templates/admin/vector-database.php' => "<h1><?= te('Moteur vectoriel') ?></h1>",
            'templates/admin/users.php' => "><?= te('Comptes') ?></h1>",
            'templates/admin/user-groups.php' => "<h1><?= te('Groupes') ?></h1>",
        ];
        foreach ($titres as $f => $h1) {
            $this->assertStringContainsString($h1, self::lire($f), "Titre non aligné : $f");
        }
    }

    public function testComportementParDefautFusionneDansModeles(): void
    {
        $routes = self::lire('public/index.php');
        $this->assertStringContainsString("get('/admin/llms/defaults', function (): void { header('Location: /admin/llms/models#defaut', true, 301); exit; });", $routes);
        $this->assertStringContainsString("post('/admin/llms/defaults',", $routes, 'Le formulaire poste toujours au même endroit');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/templates/admin/llms/defaults.php');
        $models = self::lire('templates/admin/llms/models.php');
        $this->assertStringContainsString('id="defaut"', $models);
        $this->assertStringContainsString('action="/admin/llms/defaults" data-autosave', $models);
        $this->assertStringContainsString("'/admin/llms/models#defaut'", self::lire('src/Controllers/AdminController.php'));
    }

    /**
     * Le menu se lit dans la langue de l'écran : libellés traduits à
     * l'accesseur (AdminMenu, AdminTabs), les mêmes que la palette ⌘K, et le
     * chemin d'un point à régler au tableau de bord reprend le menu mot pour
     * mot en français.
     */
    public function testLeMenuEtLesOngletsSontTraduitsALaSource(): void
    {
        foreach (AdminAttention::ENTRIES as $entry => [, $chemin]) {
            $this->assertSame($chemin, AdminMenu::pathText($entry, $chemin), "Chemin du tableau de bord ≠ menu : $entry");
        }
        $this->assertSame('Personnes › Comptes', AdminMenu::pathText('users'));
        $this->assertSame('Tableau de bord', AdminMenu::label('dashboard'));
        $this->assertSame('Élagage', AdminTabs::ongletText('pruning'));
        $this->assertSame('/admin/pruning', AdminTabs::href('pruning'));
        $this->assertSame('', AdminTabs::ongletText('users'));

        $avant = I18n::locale();
        I18n::use('en');
        try {
            $this->assertSame('People', AdminMenu::label('people'));
            $this->assertSame('Models', AdminMenu::pageText('llms-models'), '« Modèles » des LLM, pas des workflows.');
            $this->assertSame('People › Accounts', AdminMenu::pathText('users'));
            $this->assertSame('Knowledge bases', AdminMenu::pathText('knowledge-bases', 'Bases de connaissances'));
            $this->assertSame('Logs', AdminTabs::ongletText('logs'));
            $this->assertSame('Data', AdminTabs::libelleText(AdminTabs::DONNEES));
            $this->assertSame('AI budget reached: requests are suspended for everyone.', AdminMenu::budgetSignal(['state' => AiBudget::STATE_REACHED, 'blocks' => true, 'pct' => 100])['text']);
        } finally {
            I18n::use($avant);
        }

        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringNotContainsString('À CHEVAL', $nav, 'Le menu d’administration est traduit : plus de note de lot.');
        $this->assertStringContainsString("htmlspecialchars(AdminMenu::label('dashboard'))", $nav);
        $onglets = self::lire('templates/partials/admin-tabs.php');
        $this->assertStringContainsString('AdminTabs::ongletText(', $onglets);
        $this->assertStringContainsString('AdminTabs::libelleText(', $onglets);
    }

    /** Les libellés calculés (onglets) ont leur traduction : le test de couverture ne les voit pas. */
    public function testLesLibellesDesOngletsOntUneTraduction(): void
    {
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        foreach ([AdminTabs::DIAGNOSTIC, AdminTabs::DONNEES] as $groupe) {
            $this->assertArrayHasKey(AdminTabs::libelle($groupe), $en, "Traduction absente : groupe $groupe");
            foreach (AdminTabs::onglets($groupe) as $page => [, $libelle]) {
                $this->assertArrayHasKey($libelle, $en, "Traduction absente : onglet $page");
            }
        }
    }

    public function testLeTexteDesConnecteursNeRangePlusN8nParmiEux(): void
    {
        $this->assertStringNotContainsString('les workflows n8n sont au', self::lire('templates/admin/connectors.php'));
    }
}
