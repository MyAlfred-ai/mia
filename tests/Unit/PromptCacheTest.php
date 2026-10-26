<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cache de prompt de la boucle d'outils, côté OpenAI et côté contrôleur (le
 * côté Anthropic est éprouvé sur le vrai code dans AnthropicToolLoopTest).
 *
 * Sans cache, chaque aller-retour de la boucle refacturait au plein tarif
 * tout le fil — prompt système, historique, résultats d'outils —, alors que
 * seule la fin change.
 */
final class PromptCacheTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    public function testLaCleDeCacheOpenAiEstBorneeEtPropre(): void
    {
        $this->assertNull(OpenAIAdapter::promptCacheKey([]), 'Pas de clé demandée : rien d’envoyé.');
        $this->assertNull(OpenAIAdapter::promptCacheKey(['cache_key' => 'mia-abc', 'cache' => 'off']), '`off` coupe aussi la clé.');
        $this->assertSame('mia-abc', OpenAIAdapter::promptCacheKey(['cache_key' => 'mia-abc']));
        $this->assertSame('miaabc', OpenAIAdapter::promptCacheKey(['cache_key' => "mia a\nbc"]), 'Aucun caractère de contrôle ni espace.');
        $this->assertSame(64, strlen((string)OpenAIAdapter::promptCacheKey(['cache_key' => str_repeat('a', 200)])));
        $this->assertNull(OpenAIAdapter::promptCacheKey(['cache_key' => ' !!! ']));
    }

    public function testUnRefusDeLaCleEstReconnuEtRejoueSansElle(): void
    {
        $this->assertTrue(OpenAIAdapter::isPromptCacheKeyRejection(400, "Unrecognized request argument supplied: prompt_cache_key"));
        $this->assertFalse(OpenAIAdapter::isPromptCacheKeyRejection(400, 'max_tokens is too large'));
        $this->assertFalse(OpenAIAdapter::isPromptCacheKeyRejection(500, 'prompt_cache_key'));

        $src = (string)file_get_contents(ROOT . '/src/LLM/OpenAIAdapter.php');
        $this->assertSame(2, substr_count($src, "\$payload['prompt_cache_key'] = \$cacheKey;"),
            'Les deux boucles d’outils (Chat Completions et Responses) envoient la clé.');
        $this->assertSame(2, substr_count($src, "if (!empty(\$res['retry_cache_key'])) {"),
            'Un refus de la clé est rejoué sans elle, sans rien afficher.');
        $this->assertSame(2, substr_count($src, "return ['retry_cache_key' => true] + \$fail();"),
            'Le refus est détecté AVANT d’émettre l’erreur au client.');
        $this->assertStringContainsString('private static bool $promptCacheKeyRejected', $src,
            'Appris une fois pour tout le process : pas un 400 par message.');
    }

    public function testLeControleurEcritDesLePremierAllerQuandDesConnecteursSontEngages(): void
    {
        $this->assertSame('eager', ChatController::toolLoopOptions(12, ['drive'])['cache']);
        $this->assertSame('lazy', ChatController::toolLoopOptions(12, [])['cache'],
            'Un outil image seul : une réponse directe est probable, l’écriture attend le second aller.');
    }

    public function testLaCleDesigneLaConversationSansRienDIdentifiant(): void
    {
        $cle = ChatController::toolLoopOptions(12, [])['cache_key'];
        $this->assertMatchesRegularExpression('/^mia-[0-9a-f]{32}$/', $cle);
        $this->assertSame($cle, ChatController::toolLoopOptions(12, ['gmail'])['cache_key'], 'Stable d’un message à l’autre.');
        $this->assertNotSame($cle, ChatController::toolLoopOptions(13, [])['cache_key']);
        $this->assertStringNotContainsString('12', substr($cle, 0, 4));
    }

    public function testLaBoucleEtLeSecoursRecoiventLesOptions(): void
    {
        $src = AgentSource::chat();
        $this->assertStringContainsString('$loopOptions = self::toolLoopOptions($convId, $engagedConnectors) + [', $src);
        $this->assertSame(3, substr_count($src, "\$loopOptions\n                );"),
            'L’appel principal, le passage au secours ET l’appel du secours portent les options.');
    }
}
