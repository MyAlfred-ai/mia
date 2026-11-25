<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Socle commun des pages d'administration et de réglages (piste A) : barre du
 * haut (fil d'Ariane, état d'enregistrement, recherche), en-tête de page,
 * clair épuré à cadres visibles, et téléphone (action principale en bas,
 * tableaux en cartes).
 */
final class AdminUiSocleTest extends TestCase
{
    /** Pages qui portent le socle : l'administration (hors espaces) et Mon compte. */
    private const PAGES = [
        'templates/admin/index.php', 'templates/admin/users.php', 'templates/admin/user-groups.php',
        'templates/admin/roles.php', 'templates/admin/llms/_head.php', 'templates/admin/tools.php',
        'templates/admin/connectors.php', 'templates/admin/connectors-slack.php',
        'templates/admin/knowledge-bases-sync.php', 'templates/admin/vector-database.php',
        'templates/admin/budget.php', 'templates/admin/usage.php', 'templates/admin/credits.php',
        'templates/admin/company.php', 'templates/admin/settings.php', 'templates/admin/sso.php',
        'templates/admin/diagnostics.php', 'templates/admin/logs.php', 'templates/admin/agent.php',
        'templates/admin/backup.php', 'templates/admin/pruning.php',
        'templates/settings/profile.php', 'templates/settings/ai.php', 'templates/settings/memory.php',
        'templates/settings/display.php', 'templates/settings/connections.php', 'templates/settings/usage.php',
    ];

    private function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    /** @return list<string> */
    private function libelles(array $trail): array
    {
        return array_map(static fn(array $c): string => $c['label'], $trail);
    }

    public function testLeFilDArianeSuitLesRubriquesDuMenu(): void
    {
        I18n::use('fr');
        $this->assertSame(['Administration', 'Personnes', 'Comptes'], $this->libelles(AdminBreadcrumb::trail('users')));
        $this->assertSame(['Administration', 'Tableau de bord'], $this->libelles(AdminBreadcrumb::trail('dashboard')));
        $this->assertSame(['Administration', 'Maintenance', 'Diagnostic', 'Journaux'], $this->libelles(AdminBreadcrumb::trail('logs')));
        $this->assertSame(['Administration', 'Maintenance', 'Données', 'Élagage'], $this->libelles(AdminBreadcrumb::trail('pruning')));
        $this->assertSame(['Mon compte', 'Affichage'], $this->libelles(AdminBreadcrumb::trail('display', 'settings')));
        $this->assertSame(['Mon compte', 'Mémoire'], $this->libelles(AdminBreadcrumb::trail('memory', 'settings')));

        // Les ancêtres sont des liens, la page affichée non.
        $trail = AdminBreadcrumb::trail('users');
        $this->assertSame('/admin', $trail[0]['href']);
        $this->assertSame('/admin/users', $trail[1]['href']);
        $this->assertNull($trail[2]['href']);
        $this->assertSame('/admin/diagnostics', AdminBreadcrumb::trail('logs')[2]['href']);
    }

    public function testLeFilDArianeEstTraduit(): void
    {
        I18n::use('en');
        try {
            $this->assertSame('Administration', AdminBreadcrumb::trail('users')[0]['label']);
            $this->assertSame('My account', AdminBreadcrumb::trail('display', 'settings')[0]['label']);
            $this->assertNotSame('Personnes', AdminBreadcrumb::trail('users')[1]['label']);
        } finally {
            I18n::use('fr');
        }
    }

    public function testChaquePageDuSocleALaBarreDuHautEtUnEnTeteCommun(): void
    {
        foreach (self::PAGES as $page) {
            $html = $this->lire($page);
            $this->assertMatchesRegularExpression(
                '#class="admin-content[^"]*">\s*<\?php require ROOT \. \'/templates/partials/admin-topbar\.php\'; \?>#',
                $html,
                "$page : la barre du haut doit être le premier enfant de .admin-content."
            );
            $this->assertStringContainsString('page-head', $html, "$page : en-tête de page commun absent.");
            $this->assertStringContainsString('page-head-text', $html, "$page : bloc titre de l'en-tête absent.");
        }
    }

    public function testLesPagesDEspaceGardentLeurMiseEnPage(): void
    {
        // Volet du chat, schémas, éditeurs : ni barre du haut ni largeur de lecture.
        foreach (['templates/admin/profiles/list.php', 'templates/admin/knowledge-bases.php',
                  'templates/workflows/index.php', 'templates/settings/schedules.php'] as $page) {
            $this->assertStringNotContainsString('admin-topbar.php', $this->lire($page), $page);
        }
    }

    public function testLaBarreDuHautCiteSesTextesTraduitsEtLaRecherche(): void
    {
        $tpl = $this->lire('templates/partials/admin-topbar.php');
        $this->assertStringContainsString('AdminBreadcrumb::trail', $tpl);
        $this->assertStringContainsString('data-palette-open', $tpl, 'La recherche ouvre la palette ⌘K.');
        $this->assertStringContainsString('data-palette-kbd', $tpl);
        foreach (['data-idle', 'data-saving', 'data-saved', 'data-error'] as $attr) {
            $this->assertMatchesRegularExpression('#' . $attr . '="<\?= te\(#', $tpl, "$attr doit être traduit.");
        }
        $dict = require ROOT . '/lang/en.php';
        foreach (['Fil d’Ariane', 'Enregistrement automatique', 'Enregistrement…', 'Enregistré', 'Non enregistré', 'Rechercher'] as $cle) {
            $this->assertArrayHasKey($cle, $dict, $cle);
        }
    }

    public function testLeScriptDuSocleEstChargeEtSansTexte(): void
    {
        $nav = $this->lire('templates/partials/admin-nav.php');
        $this->assertStringContainsString('<script defer src="/assets/js/admin-ui.js', $nav);
        // Le bandeau « lecture seule » se pose SOUS la barre du haut.
        $this->assertStringContainsString("getElementById('adminTopbar')", $nav);

        $js = $this->lire('public/assets/js/admin-ui.js');
        foreach (['mia:autosave-before', 'mia:autosaved', 'mia:autosave-failed'] as $evt) {
            $this->assertStringContainsString($evt, $js);
        }
        $this->assertStringContainsString('form[data-autosave]:not([data-autosave="off"])', $js, 'Une modale au repos ne compte pas.');
        // Aucun libellé en dur : les textes viennent du gabarit, traduits.
        $code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js);
        $this->assertDoesNotMatchRegularExpression("#'[^']*\\b(Enregistr|Rechercher)[^']*'#u", $code);
    }

    public function testLeClairEpureACadresVisiblesEtRayonsReduits(): void
    {
        $css = $this->lire('public/assets/css/main.css');
        $debut = strpos($css, 'SOCLE COMMUN DES PAGES D\'ADMINISTRATION');
        $this->assertNotFalse($debut);
        $bloc = substr($css, (int)$debut);
        $this->assertStringContainsString('--radius:      8px;', $bloc);
        $this->assertStringContainsString('--radius-sm:   6px;', $bloc);
        $this->assertStringContainsString('--bg:          #ffffff;', $bloc, 'Fond blanc en thème clair.');
        $this->assertStringContainsString('--border:      #c4c4ce;', $bloc, 'Cadres visibles en thème clair.');
        // Le contraste renforcé garde ses propres bordures.
        $this->assertStringContainsString('html[data-theme="light"]:not([data-contrast="high"]) body.admin-page .admin-content', $bloc);
        $this->assertStringContainsString('box-shadow: none;', $bloc);
    }

    /**
     * Le chat en thème clair prend le fond de l'administration : fond blanc,
     * volet quasi blanc à filet net — le MÊME volet que celui des pages
     * d'espace (on ne change plus de teinte en passant de l'un à l'autre).
     * Scopé à `body.app` : connexion, À propos et erreurs gardent leurs jetons.
     */
    public function testLeChatEnThemeClairPrendLeFondDeLAdministration(): void
    {
        $css = $this->lire('public/assets/css/main.css');
        $bloc = substr($css, (int)strpos($css, 'SOCLE COMMUN DES PAGES D\'ADMINISTRATION'));

        $this->assertMatchesRegularExpression(
            '#html\[data-theme="light"\] body\.app \{\s*--bg:\s*\#ffffff;\s*--bg-base:\s*\#ffffff;#',
            $bloc,
            'Fond blanc sur la page du chat, en thème clair seulement.'
        );
        $this->assertMatchesRegularExpression(
            '#html\[data-theme="light"\] body\.app \.sidebar,\s*html\[data-theme="light"\] body\.admin-page \.admin-sidebar \{\s*--bg-sidebar:\s*\#fafafa;#',
            $bloc,
            'Le volet du chat, celui des pages d\'espace et le menu de l\'administration ont la même teinte.'
        );
        $this->assertStringNotContainsString('.admin-sidebar:not(.admin-sidebar--chat)', $bloc, 'Le volet du chat des pages d\'espace suit le chat.');
        // Filet net, sauf en contraste renforcé (qui a ses bordures).
        $this->assertStringContainsString('html[data-theme="light"]:not([data-contrast="high"]) body.app .sidebar', $bloc);
        // Sur fond blanc, survols et en-têtes de tableau ne peuvent plus être « blancs ».
        $this->assertStringContainsString('html[data-theme="light"] body.app .msg-content th { background: #f5f5f7; }', $bloc);
        $this->assertMatchesRegularExpression('#html\[data-theme="light"\] body\.app :is\([^)]*\.msg-action-btn[^)]*\):hover#', $bloc);

        // La page du chat porte bien `body.app`, et elle seule.
        $this->assertStringContainsString('<body class="app">', $this->lire('templates/chat/index.php'));
        foreach (['templates/auth/login.php', 'templates/admin/index.php'] as $page) {
            $this->assertStringNotContainsString('<body class="app"', $this->lire($page), $page);
        }
    }

    public function testAuTelephoneLActionPrincipaleEstEnBasEtLesTableauxEnCartes(): void
    {
        $css = $this->lire('public/assets/css/main.css');
        $bloc = substr($css, (int)strpos($css, 'SOCLE COMMUN DES PAGES D\'ADMINISTRATION'));
        $this->assertStringContainsString('.page-head .page-head-primary:not([hidden])', $bloc);
        $this->assertStringContainsString('table.table-stack td::before', $bloc);

        foreach (['templates/admin/users.php' => 'btnNewUser', 'templates/admin/user-groups.php' => 'ugCreateBtn', 'templates/admin/tools.php' => 'addToolOpen'] as $page => $id) {
            $this->assertMatchesRegularExpression('#class="[^"]*page-head-primary[^"]*"[^>]*id="' . $id . '"|id="' . $id . '"[^>]*class="[^"]*page-head-primary#', $this->lire($page), $page);
        }
        $this->assertStringContainsString('table-stack', $this->lire('templates/admin/agent.php'));
        $this->assertStringContainsString('table-stack', $this->lire('templates/admin/usage-overview.php'));
    }

    public function testLesReglesDAccesTiennentEnUneLigneQuiResumeLeurEtat(): void
    {
        $html = $this->lire('templates/admin/users.php');
        $this->assertStringContainsString('<details class="card admin-fold"', $html);
        $this->assertStringContainsString('admin-fold-summary', $html);
        // Le formulaire auto-enregistré reste le même (route dédiée, case postée).
        $this->assertStringContainsString('action="/admin/users/access-rules"', $html);
        $this->assertStringContainsString('name="new_users_active"', $html);
    }

    public function testLeHelperEstChargeDansLesDeuxListes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('/src/Helpers/AdminBreadcrumb.php', $this->lire($f), $f);
        }
    }
}
