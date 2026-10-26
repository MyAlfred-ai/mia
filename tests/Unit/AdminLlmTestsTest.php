<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Écrans de test des modèles (Modèles IA → Tests, Clés API → Tester).
 *
 * Deux angles morts révélés par l'arrivée de Claude Fable 5.1 et GPT-6 Astra :
 *  - le chemin d'un test OpenAI se décidait sur un préfixe `gpt-5` codé en
 *    dur, si bien que GPT-6 Astra était testé sur Chat Completions — un
 *    chemin que le chat n'emprunte jamais pour lui ;
 *  - les tests Anthropic lisaient `content[0].text`, alors qu'un modèle qui
 *    raisonne par défaut (Opus 5, Sonnet 5, Fable 5 et 5.1) ouvre sa réponse
 *    sur un bloc `thinking` : « Réponse : (vide) » sur une réponse valide.
 */
final class AdminLlmTestsTest extends TestCase
{
    protected function setUp(): void
    {
        // Le contrôleur n'est pas dans le socle des tests (il tire vendor/ à
        // l'exécution) ; son chargement seul suffit pour les méthodes pures.
        require_once ROOT . '/src/Controllers/AdminController.php';
    }

    /** @return array<string, array{string, bool}> */
    public static function routages(): array
    {
        return [
            'gpt-6-astra (hors préfixe gpt-5)' => ['gpt-6-astra', true],
            'gpt-5.6'                          => ['gpt-5.6', true],
            'gpt-5.x non listé (catch-all)'    => ['gpt-5.9-inconnu', true],
            'gpt-4o'                           => ['gpt-4o', false],
            'o3'                               => ['o3', false],
        ];
    }

    #[DataProvider('routages')]
    public function testLesTestsOpenAiSuiventLeRoutageDuCatalogue(string $modele, bool $viaResponses): void
    {
        $m = new ReflectionMethod(AdminController::class, 'testsViaResponsesApi');
        $this->assertSame($viaResponses, $m->invoke(null, $modele));
    }

    public function testPlusAucunPrefixeGpt5CodeEnDur(): void
    {
        $src = file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertDoesNotMatchRegularExpression(
            "/str_starts_with\([^)]*'gpt-5'\)/",
            $src,
            'Le routage d\'un test OpenAI se lit au catalogue, comme dans l\'adapter.'
        );
    }

    /** @return array<string, array{array, string}> */
    public static function reponsesAnthropic(): array
    {
        return [
            'thinking puis texte' => [
                ['content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => ' ok ']],
                 'stop_reason' => 'end_turn'],
                'ok',
            ],
            'texte seul' => [['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn'], 'ok'],
            // La raison de l'arrêt est nommée plutôt que tue.
            'raisonnement épuisé' => [
                ['content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']], 'stop_reason' => 'max_tokens'],
                '(vide — arrêt : max_tokens)',
            ],
            'refus avant sortie' => [['content' => [], 'stop_reason' => 'refusal'], '(vide — arrêt : refusal)'],
            'corps illisible'    => [[], '(vide)'],
        ];
    }

    #[DataProvider('reponsesAnthropic')]
    public function testLeTexteDUneReponseAnthropicSauteLeRaisonnement(array $json, string $attendu): void
    {
        $m = new ReflectionMethod(AdminController::class, 'anthropicReplyText');
        $this->assertSame($attendu, $m->invoke(null, $json));
    }

    public function testLesTestsAnthropicNeLisentPlusLePremierBloc(): void
    {
        $src = file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringNotContainsString("\$json['content'][0]['text']", $src);
    }

    /**
     * Le raisonnement compte dans la limite de sortie : une marge de quelques
     * tokens (5, 10, 16, 20, 100) ne laissait rien pour répondre. Seuls les
     * tokens produits sont facturés — la marge ne coûte rien de plus.
     */
    public function testLesTestsLaissentDeLaPlacePourLeRaisonnement(): void
    {
        $src = file_get_contents(ROOT . '/src/Controllers/AdminController.php');

        // Test de base via la Responses API (GPT-5.x, GPT-6 Astra).
        $this->assertStringNotContainsString("'max_output_tokens' => 16,", $src);
        $this->assertSame(1, substr_count($src, "'max_output_tokens' => 1024,"));
        $this->assertSame(
            4,
            preg_match_all("/'max_tokens' => 1024,/", $src),
            'Tests Anthropic de base, recherche web et vision, et vision/fichier Mistral (Small 4, Medium 3.5 et Large 4 savent raisonner).'
        );
        // Test de base Mistral : même marge que Gemini (au lieu de 5 tokens).
        $this->assertMatchesRegularExpression("/str_contains\(\\\$url, 'api\.mistral\.ai'\)\) \{[^}]*\\\$payloadData\['max_tokens'\] = 1024;/s", $src);
    }

    /**
     * Organisation en « zéro rétention » : Anthropic dit le symptôme
     * (« must have data retention enabled »), l'écran de test ajoute les
     * remèdes et le relais que le chat applique déjà.
     */
    public function testLeTestDitCommentOuvrirFableAUneOrganisationSansConservation(): void
    {
        $m = new ReflectionMethod(AdminController::class, 'anthropicTestError');
        $brut = 'In order to access this model, your organization or workspace must have data retention enabled.';

        $msg = $m->invoke(null, $brut, 'claude-fable-5-1');
        $this->assertStringStartsWith('Conservation des données requise', $msg);
        $this->assertStringContainsString('workspace dédié', $msg);
        $this->assertStringContainsString('le chat fait répondre Claude Opus 5 à sa place', $msg);
        $this->assertStringContainsString($brut, $msg, 'Le message d\'origine reste lisible pour le support.');

        // Toute autre erreur passe telle quelle.
        $this->assertSame('invalid x-api-key', $m->invoke(null, 'invalid x-api-key', 'claude-fable-5-1'));

        $src = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertSame(3, substr_count($src, "self::anthropicTestError((string)\$json['error']['message'], \$model)"),
            'Tests de base, recherche web et vision.');
    }

    /** La recherche Anthropic est un outil SERVEUR : son bloc est `server_tool_use`. */
    public function testLaRechercheWebAnthropicEstReconnue(): void
    {
        $src = file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString("in_array(\$block['type'] ?? '', ['server_tool_use', 'tool_use'], true)", $src);
    }
}
