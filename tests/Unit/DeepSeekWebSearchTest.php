<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Recherche Internet intégrée de DeepSeek (13/11/2026), guide « Web Tools » et
 * page Pricing relus le 2026-11-17.
 *
 *   - l'outil SERVEUR `deepseek_web_search` se déclare dans `tools` ; DeepSeek
 *     cherche lui-même, MIA n'exécute rien ;
 *   - « other tools cannot be used together with `deepseek_web_search` …
 *     yet » : jamais avec les outils de MIA (le contrôleur arbitre) ;
 *   - les sources citées arrivent dans `annotations` du dernier fragment ;
 *   - les recherches facturées sont `tool_search` + `tool_fetch` +
 *     `tool_image_search` (`web_search_requests` n'est qu'un affichage).
 *
 * Le VRAI flux de l'adaptateur est rejoué depuis un fichier SSE (aucun réseau,
 * aucune clé DeepSeek en local).
 */
final class DeepSeekWebSearchTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/mia-deepseek-web-' . bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        LlmCatalog::flushCache();
        foreach (glob($this->dossier . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dossier);
    }

    public function testLaRechercheDeclareLOutilServeurEtRienDAutre(): void
    {
        $adapter = $this->adapter();
        $avec = $adapter->streamPayload([['role' => 'user', 'content' => 'Météo à Lyon ?']], 'système', 0.7, true);
        $this->assertSame([['type' => 'deepseek_web_search']], $avec['tools']);
        $this->assertArrayNotHasKey('web_search_options', $avec, 'Refusé en 400 avec les outils serveur.');
        $this->assertArrayNotHasKey('safety_check', $avec, 'Exigé d\'un service ouvert au public seulement.');
        $this->assertArrayNotHasKey('tool_choice', $avec, 'Un outil serveur ne se force pas (400).');
        $this->assertSame(['type' => 'disabled'], $avec['thinking'], 'La recherche simple suppose la réflexion coupée.');
        $this->assertSame(65536, $avec['max_tokens']);

        $sans = $adapter->streamPayload([['role' => 'user', 'content' => 'Bonjour']], 'système', 0.7, false);
        $this->assertArrayNotHasKey('tools', $sans);
    }

    public function testUnModeleQuiNeCherchePasNeDeclareRien(): void
    {
        $catalogue = require ROOT . '/config/llm_catalog.php';
        $catalogue['deepseek']['models']['deepseek-flash']['web_native'] = false;
        (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);

        $payload = $this->adapter()->streamPayload([['role' => 'user', 'content' => 'x']], 's', null, true);
        $this->assertArrayNotHasKey('tools', $payload);
    }

    public function testLesSourcesCiteesSontLuesDansLesAnnotations(): void
    {
        $sources = DeepSeekAdapter::sourcesFrom([
            ['type' => 'url_citation', 'url_citation' => ['url' => 'https://exemple.fr/a', 'title' => 'Article A', 'start_index' => 0, 'end_index' => 12]],
            ['type' => 'url_citation', 'url_citation' => ['url' => 'https://exemple.fr/a', 'title' => 'Doublon']],
            ['type' => 'url_citation', 'url_citation' => ['url' => 'https://exemple.fr/b', 'title' => 'https://exemple.fr/b']],
            ['type' => 'url_citation', 'url_citation' => ['url' => 'javascript:alert(1)', 'title' => 'Piège']],
            ['type' => 'file_citation', 'url_citation' => ['url' => 'https://exemple.fr/c']],
            'n\'importe quoi',
        ]);
        $this->assertSame([
            ['url' => 'https://exemple.fr/a', 'title' => 'Article A'],
            ['url' => 'https://exemple.fr/b', 'title' => ''],
        ], $sources, 'Titre inconnu (= l\'adresse) laissé vide ; adresse non http(s) écartée.');
        $this->assertSame([], DeepSeekAdapter::sourcesFrom(null));
    }

    public function testSeulesLesRecherchesFactureesSontComptees(): void
    {
        $this->assertSame(4, DeepSeekAdapter::billedSearches(['server_tool_use' => [
            'web_search_requests' => 9, 'web_fetch_requests' => 9,
            'tool_search' => 2, 'tool_fetch' => 1, 'tool_image_search' => 1,
        ]]));
        $this->assertSame(0, DeepSeekAdapter::billedSearches(['prompt_tokens' => 10]));
        $this->assertSame(0, DeepSeekAdapter::billedSearches(null));
    }

    public function testLeFluxRejoueRendLaReponseSesSourcesEtSesRecherches(): void
    {
        $fichier = $this->ecrire([
            ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'server_tool_event' => ['type' => 'call_started', 'step' => 0, 'call' => ['type' => 'deepseek_web_search', 'kind' => 'search']]]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Il fait beau à Lyon'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => ' aujourd\'hui.'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [
                'annotations' => [
                    ['type' => 'url_citation', 'url_citation' => ['url' => 'https://meteo.exemple.fr/lyon', 'title' => 'Météo Lyon [officiel]', 'start_index' => 0, 'end_index' => 19]],
                ],
                'server_tool_calls' => [['index' => 0, 'calls' => [['type' => 'deepseek_web_search', 'kind' => 'search', 'status' => 'completed']]]],
            ], 'finish_reason' => 'stop']],
             'usage' => ['prompt_tokens' => 1900, 'completion_tokens' => 70, 'prompt_cache_hit_tokens' => 0,
                 'server_tool_use' => ['web_search_requests' => 1, 'web_fetch_requests' => 0, 'tool_search' => 2, 'tool_fetch' => 0, 'tool_image_search' => 0]]],
        ]);
        [$texte, $jetons] = $this->lancer($fichier, true);

        $this->assertStringStartsWith('Il fait beau à Lyon aujourd\'hui.', $texte);
        $this->assertStringContainsString("Sources:\n1. [Météo Lyon (officiel)](https://meteo.exemple.fr/lyon)", $texte);
        $this->assertSame(2, $jetons['web_searches']);
        $this->assertSame([1900, 70], [$jetons['input'], $jetons['output']]);
        $this->assertArrayNotHasKey('stop', $jetons);
    }

    public function testUneReponseQuiEchoueEnRouteGardeSonUsageSansSources(): void
    {
        $fichier = $this->ecrire([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Début de réponse'], 'finish_reason' => null]]],
            ['error' => ['message' => 'The model failed to generate a complete response. Please retry.', 'type' => 'api_error'],
             'choices' => [],
             'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 5, 'server_tool_use' => ['tool_search' => 1]]],
        ]);
        [$texte, $jetons] = $this->lancer($fichier, true);

        $this->assertStringNotContainsString('Sources:', $texte);
        $this->assertSame(1, $jetons['web_searches'], 'Ce qui a tourné avant l\'échec est facturé par DeepSeek.');
        $this->assertSame(800, $jetons['input']);
    }

    public function testLeTarifDuCatalogueFactureLaRecherche(): void
    {
        $this->assertSame(0.75, LlmCatalog::webSearchPricingUsdPer1k('deepseek', 'deepseek-flash'));
        $this->assertSame('request', LlmCatalog::webSearchBilling('deepseek', 'deepseek-flash'));
        // Réglages vides (tarifs du catalogue) sans lire la table.
        $cache  = new ReflectionProperty(Settings::class, 'cache');
        $loaded = new ReflectionProperty(Settings::class, 'loaded');
        $avant  = [$cache->getValue(), $loaded->getValue()];
        try {
            $cache->setValue(null, []);
            $loaded->setValue(null, true);
            $this->assertEqualsWithDelta(CreditService::usdToEur(0.75 / 1000) * 3,
                CreditService::computeWebSearchCredits('deepseek::deepseek-flash', 3)['cost_eur'], 1e-6);
        } finally {
            $cache->setValue(null, $avant[0]);
            $loaded->setValue(null, $avant[1]);
        }
    }

    /** La boucle d'outils ne déclare JAMAIS l'outil : DeepSeek le refuse à côté des outils de MIA. */
    public function testLaBoucleDOutilsNeDeclareJamaisLaRecherche(): void
    {
        $trait = (string)file_get_contents(ROOT . '/src/LLM/ChatCompletionsToolLoopTrait.php');
        $this->assertStringNotContainsString('deepseek_web_search', $trait);
        $adapter = (string)file_get_contents(ROOT . '/src/LLM/DeepSeekAdapter.php');
        $this->assertSame(1, substr_count($adapter, "'type' => 'deepseek_web_search'"), 'Déclaré à un seul endroit.');
        $this->assertStringContainsString('webSearchTools()', $adapter);
        $this->assertStringNotContainsString('webSearchTools', (string)preg_replace('/^.*?function toolLoopPayloadExtras\(\): array\s*\{(.*?)\n    \}.*$/s', '$1', $adapter));
    }

    public function testLeBlocSourcesEstPartageAvecClaude(): void
    {
        $anthropic = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertStringContainsString('LlmAdapterHelpers::sourcesMarkdown(', $anthropic);
        $this->assertStringNotContainsString('"\n\nSources:\n"', $anthropic, 'Une seule mise en forme des sources.');
        $this->assertSame('', LlmAdapterHelpers::sourcesMarkdown([]));
        $this->assertSame("\n\nSources:\n1. [https://a.fr](https://a.fr)\n",
            LlmAdapterHelpers::sourcesMarkdown([['url' => 'https://a.fr', 'title' => '']]));
    }

    // ------------------------------------------------------------------

    private function adapter(): DeepSeekAdapter
    {
        $adapter = (new ReflectionClass(DeepSeekAdapter::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(DeepSeekAdapter::class, 'apiKey'))->setValue($adapter, 'cle-factice');
        (new ReflectionProperty(DeepSeekAdapter::class, 'model'))->setValue($adapter, 'deepseek-flash');
        return $adapter;
    }

    /** @param list<array> $evenements */
    private function ecrire(array $evenements): string
    {
        $sse = '';
        foreach ($evenements as $e) {
            $sse .= 'data: ' . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        $sse .= ": keep-alive\n\n";
        $fichier = $this->dossier . '/chat-' . bin2hex(random_bytes(4)) . '.sse';
        file_put_contents($fichier, $sse);

        $catalogue = require ROOT . '/config/llm_catalog.php';
        $catalogue['deepseek']['endpoints']['chat_completions'] = $fichier;
        (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);
        return $fichier;
    }

    /** @return array{0: string, 1: array} */
    private function lancer(string $fichier, bool $recherche): array
    {
        $texte = '';
        $jetons = $this->adapter()->stream(
            [['role' => 'user', 'content' => 'Quel temps fait-il à Lyon ?']],
            'système',
            static function (string $c) use (&$texte): void { $texte .= $c; },
            null,
            $recherche
        );
        return [$texte, $jetons];
    }
}
