<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT . '/src/Controllers/AdminController.php';

/**
 * Modèles IA — « Fournisseurs et modèles » : UNE page remplace « Clés API »
 * et « Modèles » (défaut et secours, une carte par fournisseur avec son logo,
 * le panneau du fournisseur choisi avec ses modèles).
 */
final class LlmProvidersPageTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $chemin);
    }

    public function testLePanneauDemandeEstValide(): void
    {
        $catalog = ['openai' => [], 'anthropic' => []];
        $this->assertSame('openai', AdminController::llmPanelProvider('openai', $catalog));
        $this->assertSame('local', AdminController::llmPanelProvider('local', $catalog));
        $this->assertSame('', AdminController::llmPanelProvider('inconnu', $catalog));
        $this->assertSame('', AdminController::llmPanelProvider(['openai'], $catalog));
        $this->assertSame('', AdminController::llmPanelProvider(null, $catalog));
    }

    public function testLesAnciennesPagesRedirigent(): void
    {
        $routes = self::lire('public/index.php');
        $this->assertStringContainsString("get('/admin/llms',               [AdminController::class, 'llms'])", $routes);
        $this->assertStringContainsString("get('/admin/llms/models', function (): void { header('Location: /admin/llms', true, 301); exit; });", $routes);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/templates/admin/llms/keys.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/templates/admin/llms/models.php');
        $this->assertArrayNotHasKey('llms-models', AdminAccess::PAGES);
        $this->assertSame(['llms-keys', 'llms-tests'], AdminAccess::ENTRY_PAGES['models']);
        $this->assertSame('Fournisseurs et modèles', AdminMenu::pageLabel('llms-keys'));
    }

    public function testLesGestesReviennentSurLePanneauDuFournisseur(): void
    {
        $admin = self::lire('src/Controllers/AdminController.php');
        foreach (['saveLlmModel', 'deleteLlmModel', 'saveApiKey'] as $methode) {
            $m      = new ReflectionMethod(AdminController::class, $methode);
            $lignes = file((string)$m->getFileName()) ?: [];
            $corps  = implode('', array_slice($lignes, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
            $this->assertStringContainsString('self::llmPanelUrl(', $corps, $methode);
            $this->assertStringNotContainsString('/admin/llms/models', $corps, $methode);
        }
        $this->assertStringNotContainsString("'/admin/llms/models", $admin);
    }

    public function testLaPageMontreLesLogosEtSesTroisParties(): void
    {
        $page = self::lire('templates/admin/llms/index.php');
        // Les logos viennent de la source unique (LLMFactory::logos), posés sur
        // une tuile claire : sur le fond sombre, un logo noir disparaîtrait.
        $this->assertStringContainsString("'logo'    => \$logos[\$p] ?? ''", $page);
        $this->assertStringContainsString('$logoTag = static function (string $src, string $class = \'llm-logo\')', $page);
        $this->assertStringContainsString('background: #fff', self::lire('public/assets/css/llms.css'));
        $m = new ReflectionMethod(AdminController::class, 'llms');
        $corps = implode('', array_slice(file((string)$m->getFileName()) ?: [], $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
        $this->assertStringContainsString('$logos         = LLMFactory::logos();', $corps);

        $this->assertStringContainsString('id="defaut"', $page);
        $this->assertStringContainsString('class="llm-prov-grid"', $page);
        $this->assertStringContainsString('id="modeles"', $page);
        $this->assertStringContainsString('role="switch"', $page);
        // Connecter un fournisseur = créer : un bouton, jamais l'enregistrement automatique.
        $this->assertMatchesRegularExpression('#<form method="POST" action="/admin/api-keys" class="llm-prov-connect">#', $page);
        // Un panneau à la fois ; sans script, le lien recharge sur le bon fournisseur.
        $this->assertStringContainsString('href="/admin/llms?fournisseur=', $page);
        $this->assertStringContainsString('data-llm-open', $page);
        $this->assertStringContainsString('.llm-panel[hidden] { display: none !important; }', self::lire('public/assets/css/llms.css'));
    }

    public function testLaFeuilleEstChargeeParLaCoquille(): void
    {
        $this->assertStringContainsString('/assets/css/llms.css?v=', self::lire('templates/admin/llms/_head.php'));
        $this->assertStringContainsString('$llmHeadAction', self::lire('templates/admin/llms/_head.php'));
    }

    public function testLesConstatsDesModelesVontSurLaPage(): void
    {
        $this->assertSame(['llms-keys'], AdminAttention::GROUPS['models']);
        $this->assertStringNotContainsString("'llms-models'", self::lire('src/Helpers/AdminAttention.php'));
        $this->assertStringNotContainsString("'llms-models'", self::lire('templates/partials/admin-nav.php'));
    }
}
