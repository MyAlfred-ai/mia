<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Génération du titre auto de conversation.
 *
 * Régression : quand le 1er échange (ou la requête de titre) échoue, les
 * adapters LLM n'émettent pas d'exception mais un texte "[Erreur ... : ...]".
 * Ce texte ne doit JAMAIS devenir le titre — on retombe sur les premiers mots
 * de la question. On verrouille ici les deux briques privées qui le garantissent
 * (looksLikeApiError + normalizeAutoTitle), via réflexion.
 */
final class ConversationTitleTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        // Pas de constructeur : instance « nue » suffisante pour les méthodes pures.
        $this->controller = (new ReflectionClass(ChatController::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, string $arg): mixed
    {
        $m = new ReflectionMethod(ChatController::class, $method);
        return $m->invoke($this->controller, $arg);
    }

    /** @return array<string, array{string}> */
    public static function apiErrorStrings(): array
    {
        return [
            'OpenAI 400 unsupported param' => ["[Erreur OpenAI (400) : Unsupported parameter: 'temperature']"],
            'Anthropic'                    => ['[Erreur Anthropic : overloaded_error]'],
            'connexion impossible'         => ['[Erreur : impossible de contacter OpenAI]'],
            'DeepSeek'                     => ['[Erreur DeepSeek : requête invalide (encodage payload)]'],
            'Kimi'                         => ['[Erreur Kimi : requête invalide (encodage payload)]'],
            'GLM 429'                      => ['[Erreur GLM (429) : trop de requêtes]'],
        ];
    }

    #[DataProvider('apiErrorStrings')]
    public function testApiErrorsAreDetected(string $errorText): void
    {
        $this->assertTrue($this->call('looksLikeApiError', $errorText));
    }

    public function testNormalTitleIsNotFlaggedAsError(): void
    {
        $this->assertFalse($this->call('looksLikeApiError', 'Recette de tarte aux pommes'));
        // Un titre légitime qui contient le mot « erreur » sans crochet n'est pas un faux positif.
        $this->assertFalse($this->call('looksLikeApiError', 'Comment corriger une erreur 500'));
    }

    #[DataProvider('apiErrorStrings')]
    public function testNormalizeRejectsApiErrorTitles(string $errorText): void
    {
        // '' → le caller conserve son fallback (premiers mots de la question).
        $this->assertSame('', $this->call('normalizeAutoTitle', $errorText));
    }

    public function testNormalizeKeepsLegitTitle(): void
    {
        $this->assertSame('Tarte aux pommes', $this->call('normalizeAutoTitle', 'Titre : Tarte aux pommes.'));
    }
}
