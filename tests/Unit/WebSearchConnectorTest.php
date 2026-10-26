<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Connecteur « Recherche Internet » : gardes de non-régression.
 *
 * Deux pannes MUETTES l'avaient rendu inopérant — l'interrupteur restait au
 * vert, aucune erreur, aucun journal, et l'assistant répondait de mémoire :
 *
 *  1. la boucle de function-calling (`streamWithTools`) ne portait PAS la
 *     recherche web, donc le moindre outil exposé (image de l'assistant,
 *     connecteur Drive / Gmail / Agenda) l'éteignait pour tout le message ;
 *  2. côté Anthropic, un type de tool web search refusé par l'API l'est en
 *     HTTP 400 — or cette branche renvoyait `retry_with_fallback => false` en
 *     dur, donc le repli documenté vers `web_search_20250305` ne pouvait
 *     jamais se déclencher.
 *
 * Tout est vérifié sans réseau : signatures (reflection) et fonctions pures.
 */
final class WebSearchConnectorTest extends TestCase
{
    /** Tous les adapters du catalogue, y compris ceux sans tool use. */
    private const ADAPTERS = [
        OpenAIAdapter::class,
        AnthropicAdapter::class,
        MistralAdapter::class,
        PerplexityAdapter::class,
        GeminiAdapter::class,
        DeepSeekAdapter::class,
        MoonshotAdapter::class,
        ZaiAdapter::class,
        LocalAdapter::class,
    ];

    // ---------------------------------------------------------------
    // 1. La boucle d'outils doit pouvoir porter la recherche web
    // ---------------------------------------------------------------

    public function testLInterfaceExposeWebSearchSurStreamWithTools(): void
    {
        $param = $this->parametre(new ReflectionMethod(LLMInterface::class, 'streamWithTools'), 'webSearch');

        $this->assertSame('webSearch', $param->getName());
        $this->assertSame('bool', (string)$param->getType());
        $this->assertTrue($param->isOptional(), 'Paramètre optionnel : aucun appelant existant à casser.');
        $this->assertFalse($param->getDefaultValue(), 'Défaut sûr : pas de recherche web non demandée.');
    }

    public function testChaqueAdapterAccepteWebSearchDansLaBoucleDOutils(): void
    {
        foreach (self::ADAPTERS as $classe) {
            $param = $this->parametre(new ReflectionMethod($classe, 'streamWithTools'), 'webSearch');
            $this->assertSame('bool', (string)$param->getType(), $classe . ' doit accepter la recherche web dans la boucle d\'outils.');
            // La conduite de la boucle (cache…) suit, optionnelle : un adapter
            // qui ne s'en sert pas l'accepte quand même.
            $options = $this->parametre(new ReflectionMethod($classe, 'streamWithTools'), 'options');
            $this->assertSame('array', (string)$options->getType(), $classe);
            $this->assertSame([], $options->getDefaultValue(), $classe);
        }
    }

    public function testLeFallbackSansToolUseTransmetLaRechercheWeb(): void
    {
        // Un adapter sans function-calling retombe sur stream() : éteindre la
        // recherche web au passage ferait perdre l'option Internet à tous les
        // modèles non tool-use dès qu'un outil est configuré.
        $source = file_get_contents(ROOT . '/src/LLM/ToolUseUnsupportedTrait.php');

        $this->assertStringContainsString(
            '$this->stream($messages, $systemPrompt, $onChunk, $temperature, $webSearch)',
            (string)$source
        );
    }

    public function testLeControleurTransmetLaRechercheWebALaBoucleDOutils(): void
    {
        // Garde de non-régression sur l'appel lui-même : la signature peut être
        // correcte et l'argument oublié — c'était exactement le cas.
        $source = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $appel  = strstr($source, '$adapter->streamWithTools(');
        $this->assertIsString($appel, 'ChatController doit appeler streamWithTools.');

        $args = substr((string)$appel, 0, (int)strpos((string)$appel, ');'));
        $this->assertStringContainsString('$nativeWebSearch', $args);
    }

    // ---------------------------------------------------------------
    // 2. Échelle de dégradation Anthropic
    // ---------------------------------------------------------------

    public function testUnTypeDeToolWebSearchRefuseDeclencheLeRepli(): void
    {
        $adapter = $this->adapterAnthropicSansBase();
        $detecte = new ReflectionMethod(AnthropicAdapter::class, 'shouldFallbackWebSearchTool');

        // Formulation type d'un 400 Anthropic sur un tool inconnu.
        $primaire = LlmCatalog::webSearchTool('anthropic', true) ?? 'web_search_20260209';
        $messages = [
            "tools.0.type: Input tag '" . $primaire . "' found using 'type' does not match any expected tag",
            'Unknown tool type provided',
            'Invalid tool: allowed_callers is not supported',
        ];
        foreach ($messages as $msg) {
            $this->assertTrue(
                $detecte->invoke($adapter, $msg),
                'Refus du tool web search à reconnaître : ' . $msg
            );
        }

        // Une panne sans rapport ne doit PAS être prise pour un refus d'outil,
        // sinon on rejouerait la requête au lieu de présenter l'erreur.
        $this->assertFalse($detecte->invoke($adapter, 'Overloaded'));
        $this->assertFalse($detecte->invoke($adapter, ''));
    }

    public function testLaBranche400SignaleLeRepliAuLieuDeLeFigerAFalse(): void
    {
        $source = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertStringNotContainsString(
            "'fatal_error' => (string)\$errMsg, 'retry_with_fallback' => false,",
            $source,
            'Le refus HTTP 400 arrive AVANT le flux : figer retry_with_fallback à false '
            . 'rend le repli vers web_search_20250305 inatteignable.'
        );
    }

    public function testLEchelleDeDegradationSeTermineSansRechercheWeb(): void
    {
        $adapter = $this->adapterAnthropicSansBase();
        $pourVariante = new ReflectionMethod(AnthropicAdapter::class, 'webSearchToolForVariant');
        $suivante     = new ReflectionMethod(AnthropicAdapter::class, 'nextWebSearchVariant');

        $recent = $pourVariante->invoke($adapter, AnthropicAdapter::WEB_SEARCH_VARIANT_LATEST);
        $ancien = $pourVariante->invoke($adapter, AnthropicAdapter::WEB_SEARCH_VARIANT_LEGACY);
        $aucun  = $pourVariante->invoke($adapter, AnthropicAdapter::WEB_SEARCH_VARIANT_NONE);

        $this->assertSame(LlmCatalog::webSearchTool('anthropic', true), $recent['type']);
        $this->assertSame(LlmCatalog::webSearchTool('anthropic', false), $ancien['type']);
        $this->assertNull($aucun, 'Dernier barreau : répondre sans recherche web plutôt qu\'échouer.');

        // L'échelle converge : deux refus au plus, jamais de boucle infinie.
        $this->assertSame(
            AnthropicAdapter::WEB_SEARCH_VARIANT_LEGACY,
            $suivante->invoke(null, AnthropicAdapter::WEB_SEARCH_VARIANT_LATEST)
        );
        $this->assertSame(
            AnthropicAdapter::WEB_SEARCH_VARIANT_NONE,
            $suivante->invoke(null, AnthropicAdapter::WEB_SEARCH_VARIANT_LEGACY)
        );
        $this->assertSame(
            AnthropicAdapter::WEB_SEARCH_VARIANT_NONE,
            $suivante->invoke(null, AnthropicAdapter::WEB_SEARCH_VARIANT_NONE)
        );
    }

    // ---------------------------------------------------------------
    // 3. Le catalogue reste la source unique de la capacité
    // ---------------------------------------------------------------

    public function testLesModelesParDefautDesProvidersToolUseSaventChercherSurLeWeb(): void
    {
        // Anthropic et OpenAI sont les deux providers qui pilotent la boucle
        // d'outils : ce sont eux qui exposaient le no-op silencieux.
        foreach (['anthropic', 'openai'] as $provider) {
            $modele = LlmCatalog::defaultModel($provider);
            $this->assertTrue(
                LlmCatalog::supportsWebSearch($provider, (string)$modele),
                $provider . ' : le modèle par défaut doit rester capable de recherche web.'
            );
        }
    }

    /**
     * Le constructeur lit la clé API en base : ces méthodes n'en ont pas
     * besoin (elles ne consultent que le catalogue), on l'évite donc pour
     * garder un test purement unitaire.
     */
    private function adapterAnthropicSansBase(): AnthropicAdapter
    {
        return (new ReflectionClass(AnthropicAdapter::class))->newInstanceWithoutConstructor();
    }

    private function parametre(ReflectionMethod $methode, string $nom): ReflectionParameter
    {
        foreach ($methode->getParameters() as $param) {
            if ($param->getName() === $nom) {
                return $param;
            }
        }
        $this->fail($methode->getDeclaringClass()->getName() . '::' . $methode->getName() . ' sans paramètre $' . $nom);
    }
}
