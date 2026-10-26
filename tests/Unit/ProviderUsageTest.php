<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Ce que chaque API facture, relevé par le VRAI code de lecture des
 * adaptateurs : le flux est rejoué sans réseau depuis un fichier local vers
 * lequel le catalogue redirige l'adresse de l'API (méthode de LlmStopReasonTest).
 *
 * Le contrat attendu par UsageMeter et CreditService :
 *   - `input` = entrée TOTALE, cache lu et cache écrit compris ;
 *   - `input_cached` / `input_cache_write` = parties de cette entrée ;
 *   - `output` = sortie TOTALE, raisonnement compris ;
 *   - `web_searches` = recherches facturées à l'unité.
 */
final class ProviderUsageTest extends TestCase
{
    private ?string $dossier = null;
    private string|false $journalAvant = false;
    private array $settingsAvant = [];

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Nom de fichier avec « : » impossible sous Windows.');
        }
        $this->dossier = sys_get_temp_dir() . '/mia-usage-' . bin2hex(random_bytes(6));
        mkdir($this->dossier);
        $this->journalAvant = ini_get('error_log');
        ini_set('error_log', $this->dossier . '/journal.log');
        // Réglages vides (tarifs du catalogue) sans lire la table.
        $cache  = new ReflectionProperty(Settings::class, 'cache');
        $loaded = new ReflectionProperty(Settings::class, 'loaded');
        $this->settingsAvant = [$cache->getValue(), $loaded->getValue()];
        $this->reglages([]);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->journalAvant === false ? '' : $this->journalAvant);
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $this->settingsAvant[0]);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, $this->settingsAvant[1]);
        LlmCatalog::flushCache();
        if ($this->dossier !== null && is_dir($this->dossier)) {
            foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }
    }

    // ------------------------------------------------------------------
    // Anthropic
    // ------------------------------------------------------------------

    /**
     * Exemple de la documentation (streaming, recherche Internet) : le
     * `message_start` annonce 2 679 jetons d'entrée, le `message_delta` final
     * 10 682 — les résultats de la recherche sont de l'entrée. Seul le second
     * est facturé.
     */
    public function testLEntreeAjouteeParLaRechercheInternetEstFacturee(): void
    {
        $evenements = self::fluxRechercheAnthropic();

        [, $jetons] = $this->jouerAnthropic($evenements);
        $this->assertSame(10682 + 300 + 50, $jetons['input'], 'Entrée totale : celle du message_delta, cache compris.');
        $this->assertSame(300, $jetons['input_cached']);
        $this->assertSame(50, $jetons['input_cache_write']);
        $this->assertSame(510, $jetons['output']);
        $this->assertSame(1, $jetons['web_searches']);

        $this->rediriger('anthropic', ['messages' => $this->ecrire($evenements)]);
        $jetons = $this->adapter(AnthropicAdapter::class, 'claude-opus-5')->streamWithTools(
            [['role' => 'user', 'content' => 'Quoi de neuf ?']],
            'système',
            [['name' => 'outil', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]],
            static function (string $c): void {},
            static fn(string $nom, array $args): array => ['ok' => true]
        );
        $this->assertSame(10682 + 300 + 50, $jetons['input'], 'Même lecture dans la boucle d\'outils.');
        $this->assertSame(510, $jetons['output']);
        $this->assertSame(1, $jetons['web_searches']);
    }

    /** Un flux d'avant (message_delta sans entrée) garde l'entrée du message_start. */
    public function testUnMessageDeltaSansEntreeNEffaceRien(): void
    {
        [, $jetons] = $this->jouerAnthropic([
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 900, 'cache_read_input_tokens' => 100, 'output_tokens' => 1]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Bonjour']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 42]],
            ['type' => 'message_stop'],
        ]);
        $this->assertSame(1000, $jetons['input']);
        $this->assertSame(100, $jetons['input_cached']);
        $this->assertSame(42, $jetons['output']);

        $acc = AnthropicAdapter::mergeStreamUsage(['in' => 500, 'cached' => 0, 'write' => 0, 'out' => 3, 'searches' => 0], ['input_tokens' => 0, 'output_tokens' => 9]);
        $this->assertSame(500, $acc['in'], 'Un 0 laissé par un intermédiaire n\'efface rien.');
        $this->assertSame(9, $acc['out']);
    }

    /** Lecture de cache de Sonnet 5.5 : 0,10 $ (0,05× l'entrée), pas 0,20 $. */
    public function testLaLectureDeCacheDeSonnet55EstCelleDuTarifOfficiel(): void
    {
        $this->assertEqualsWithDelta(0.10, (float)LlmCatalog::pricing('anthropic', 'claude-sonnet-5-5')['cached_input'], 1e-9);
        $this->assertEqualsWithDelta(0.20, (float)LlmCatalog::pricing('anthropic', 'claude-opus-5-5')['cached_input'], 1e-9);
    }

    // ------------------------------------------------------------------
    // Kimi, Mistral, Perplexity
    // ------------------------------------------------------------------

    /**
     * Kimi range l'usage final dans le choix de la dernière tranche (forme
     * historique) ou à la racine (« include_usage ») : le chat simple ne
     * lisait que la racine, et ne la demandait pas.
     */
    public function testLeChatDeKimiReleveSonUsageOuQuIlSoit(): void
    {
        $dansLeChoix = [
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Bonjour.'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 19, 'completion_tokens' => 13, 'total_tokens' => 32]]]],
        ];
        $this->rediriger('moonshot', ['chat_completions' => $this->ecrire($dansLeChoix, true)]);
        [, $jetons] = $this->lancer($this->adapter(MoonshotAdapter::class, 'kimi-k3'));
        $this->assertSame(19, $jetons['input']);
        $this->assertSame(13, $jetons['output']);

        $aLaRacine = [
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Bonjour.'], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 9, 'cached_tokens' => 10]],
        ];
        $this->rediriger('moonshot', ['chat_completions' => $this->ecrire($aLaRacine, true)]);
        [, $jetons] = $this->lancer($this->adapter(MoonshotAdapter::class, 'kimi-k3'));
        $this->assertSame(40, $jetons['input']);
        $this->assertSame(10, $jetons['input_cached']);

        $source = (string)file_get_contents(ROOT . '/src/LLM/MoonshotAdapter.php');
        $this->assertStringContainsString("] + self::STREAM_USAGE;", $source, 'Le chat simple demande l\'usage du flux.');
    }

    /** Exemple de la page « Websearch » de Mistral : 188 + 7 112 jetons d'entrée, une recherche. */
    public function testLesRecherchesMistralSontCompteesAvecLeursResultats(): void
    {
        $relu = MistralAdapter::conversationUsage([
            'prompt_tokens' => 188, 'completion_tokens' => 55, 'total_tokens' => 7355,
            'connector_tokens' => 7112, 'connectors' => ['web_search' => 1],
        ]);
        $this->assertSame(['input' => 7300, 'output' => 55, 'input_cached' => 0, 'web_searches' => 1], $relu);
        $this->assertNull(MistralAdapter::conversationUsage([]));
        $this->assertSame(0, MistralAdapter::conversationUsage(['input_tokens' => 10, 'output_tokens' => 2])['web_searches']);
    }

    // ------------------------------------------------------------------
    // Calculs
    // ------------------------------------------------------------------

    /**
     * Palier « contexte long » d'OpenAI (> 272 000 jetons d'entrée) : toute
     * la requête au-delà, entrée ×2 (cache compris) et sortie ×1,5. Il vaut
     * PAR REQUÊTE : trois allers de 100 000 jetons restent au tarif normal.
     */
    public function testLePalierDeContexteLongVautParRequete(): void
    {
        $court = CreditService::computeLlmCredits('openai::gpt-6.1-sol', 100000, 1000);
        $this->assertEqualsWithDelta(CreditService::usdToEur(100000 * 2 / 1e6 + 1000 * 10 / 1e6), $court['cost_eur'], 1e-6);

        $jetons = ['input' => 300000, 'output' => 1000, 'input_cached' => 100000];
        $long = CreditService::longContextPart('openai::gpt-6.1-sol', $jetons);
        $this->assertSame(300000, $long['input']);
        $cout = CreditService::computeLlmCredits('openai::gpt-6.1-sol', 300000, 1000, 100000, 0, 0, $long)['cost_eur'];
        $this->assertEqualsWithDelta(CreditService::usdToEur(200000 * 4 / 1e6 + 100000 * 0.20 / 1e6 + 1000 * 15 / 1e6), $cout, 1e-6);

        $troisAllers = $jetons + ['rounds' => [
            ['input' => 100000, 'output' => 300], ['input' => 100000, 'output' => 300], ['input' => 100000, 'output' => 400],
        ]];
        $this->assertSame([], CreditService::longContextPart('openai::gpt-6.1-sol', $troisAllers));
        $unLong = ['rounds' => [['input' => 280000, 'output' => 500, 'input_cached' => 0], ['input' => 20000, 'output' => 500]]];
        $this->assertSame(280000, CreditService::longContextPart('openai::gpt-6.1-sol', $unLong)['input']);

        // Gemini Pro : 200 000 ; un modèle sans palier n'en a pas.
        $this->assertSame(250000, CreditService::longContextPart('gemini::gemini-3.1-pro-preview', ['input' => 250000, 'output' => 10])['input']);
        $this->assertSame([], CreditService::longContextPart('gemini::gemini-3.8-flash', ['input' => 900000, 'output' => 10]));
        $this->assertSame([], CreditService::longContextPart('anthropic::claude-opus-5', ['input' => 900000, 'output' => 10]));
    }

    /**
     * Une réponse avec recherche s'écrit sur deux lignes ; frais fixes,
     * arrondi et minimum ne valent qu'UNE fois pour l'opération.
     */
    public function testUneReponseEtSesRecherchesSontArrondiesUneSeuleFois(): void
    {
        $r = UsageMeter::llm('openai::gpt-6-luna', ['input' => 900, 'output' => 50, 'web_searches' => 1], UsageSource::CHAT, []);
        $chat = CreditService::computeLlmCredits('openai::gpt-6-luna', 900, 50);
        $ws   = CreditService::computeWebSearchCredits('openai::gpt-6-luna', 1);
        $global = CreditService::globalConfig();
        $unSeulReglement = (int)ceil(($chat['billed_eur'] + $ws['billed_eur']) / $global['credit_value_eur'] - 1e-9);

        $this->assertSame(max($unSeulReglement, 1), $r['total_credits']);
        $this->assertLessThan($chat['total_credits'] + $ws['total_credits'], $r['total_credits'], 'Avant : deux arrondis et deux minimums.');
        $this->assertSame($r['total_credits'] - $chat['total_credits'], $r['web_search_credits']);
    }

    /** Gemini 2.5 a SON tarif de recherche : la ligne du fournisseur ne le remplace pas. */
    public function testLeTarifDeRechercheDUnModelePrimeSurLaLigneDuFournisseur(): void
    {
        $this->reglages(['credits_config_json' => json_encode(['services' => [[
            'id' => 'ws', 'name' => 'Recherche Gemini', 'type' => 'web_search', 'unit' => 'request', 'active' => true,
            'real_cost_eur' => CreditService::usdToEur(14 / 1000), 'catalog_key' => 'websearch:gemini',
        ]]])]);
        $this->assertEqualsWithDelta(CreditService::usdToEur(35 / 1000), CreditService::computeWebSearchCredits('gemini::gemini-2.5-pro', 1)['cost_eur'], 1e-6);
        $this->assertEqualsWithDelta(CreditService::usdToEur(14 / 1000), CreditService::computeWebSearchCredits('gemini::gemini-3.8-flash', 1)['cost_eur'], 1e-6);
    }

    /** Code Interpreter d'OpenAI : 0,03 $ par session de conteneur, en plus des jetons. */
    public function testLaSessionDeConteneurDeCodeEstFacturee(): void
    {
        $reponse = ['output' => [
            ['type' => 'code_interpreter_call', 'container_id' => 'cntr_a'],
            ['type' => 'code_interpreter_call', 'container_id' => 'cntr_a'],
            ['type' => 'message'],
        ], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]];
        $jetons = NativeDeck::openaiTokens($reponse);
        $this->assertSame(1, $jetons['containers']);

        $sans = CreditService::computeLlmCredits('openai::gpt-6-luna', 1000, 200);
        $avec = CreditService::computeLlmCredits('openai::gpt-6-luna', 1000, 200, 0, 0, 0, [], 1);
        $this->assertEqualsWithDelta(CreditService::usdToEur(0.03), $avec['cost_eur'] - $sans['cost_eur'], 1e-6);
        $this->assertTrue($avec['priced']);
    }

    /**
     * Réponse arrêtée (Stop) : l'usage arrive en fin de flux, que l'adaptateur
     * ne lit plus. Ce qui manque est estimé ; un relevé n'est jamais baissé.
     */
    public function testUneReponseArreteeEstEstimeeSansBaisserLeReleve(): void
    {
        $texte = str_repeat('Une phrase de la réponse déjà écrite. ', 40);
        $estime = LlmAdapterHelpers::fillAbortedUsage(['input' => 0, 'output' => 0], 3000, $texte);
        $this->assertSame(3000, $estime['input']);
        $this->assertGreaterThan(100, $estime['output']);
        $this->assertTrue($estime['estimated']);

        // Anthropic annonce l'entrée dès le début : elle reste ; la sortie (1) est complétée.
        $anthropic = LlmAdapterHelpers::fillAbortedUsage(['input' => 5200, 'output' => 1], 3000, $texte);
        $this->assertSame(5200, $anthropic['input']);
        $this->assertGreaterThan(1, $anthropic['output']);

        $complet = LlmAdapterHelpers::fillAbortedUsage(['input' => 900, 'output' => 4000], 3000, 'Bref.');
        $this->assertSame(['input' => 900, 'output' => 4000], $complet, 'Rien à compléter.');

        $source = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($source, 'LlmAdapterHelpers::fillAbortedUsage('), 'Envoi ET régénération.');
    }

    /** Vectorisation interrompue : les lots déjà payés restent au grand livre. */
    public function testLesLotsDEmbeddingsDejaPayesRestentFactures(): void
    {
        $e = new EmbeddingPartialException('panne', 1234, false);
        $this->assertSame(1234, $e->tokens);
        $this->assertInstanceOf(RuntimeException::class, $e);
        $source = (string)file_get_contents(ROOT . '/src/Models/KnowledgeBaseDocument.php');
        $this->assertMatchesRegularExpression('/instanceof EmbeddingPartialException.*?UsageMeter::embeddings\(/s', $source);
    }

    // ------------------------------------------------------------------

    /** @param array<string, string> $valeurs */
    private function reglages(array $valeurs): void
    {
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $valeurs);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, true);
    }

    /** @return list<array> flux de la documentation Anthropic (recherche Internet) */
    private static function fluxRechercheAnthropic(): array
    {
        return [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 2679, 'cache_read_input_tokens' => 300, 'cache_creation_input_tokens' => 50, 'output_tokens' => 3]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Voici les nouvelles.']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => [
                'input_tokens' => 10682, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 300,
                'output_tokens' => 510, 'server_tool_use' => ['web_search_requests' => 1],
            ]],
            ['type' => 'message_stop'],
        ];
    }

    /** @return array{0: string, 1: array} texte, jetons */
    private function jouerAnthropic(array $evenements): array
    {
        $this->rediriger('anthropic', ['messages' => $this->ecrire($evenements)]);
        return $this->lancer($this->adapter(AnthropicAdapter::class, 'claude-opus-5'));
    }

    /** @return array{0: string, 1: array} texte, jetons */
    private function lancer(LLMInterface $adapter, bool $rechercheWeb = false): array
    {
        $chunks = [];
        $jetons = $adapter->stream(
            [['role' => 'user', 'content' => 'Bonjour']],
            'système',
            static function (string $c) use (&$chunks): void { $chunks[] = $c; },
            null,
            $rechercheWeb
        );
        return [implode('', $chunks), $jetons];
    }

    /** Fichier SSE : un évènement par bloc `data:`, `[DONE]` final si demandé. */
    private function ecrire(array $evenements, bool $done = false): string
    {
        $sse = '';
        foreach ($evenements as $e) {
            $sse .= 'data: ' . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        if ($done) {
            $sse .= "data: [DONE]\n\n";
        }
        $fichier = $this->dossier . '/flux-' . bin2hex(random_bytes(4)) . '.sse';
        file_put_contents($fichier, $sse);
        return $fichier;
    }

    /** Redirige les adresses d'un fournisseur vers des fichiers locaux. */
    private function rediriger(string $fournisseur, array $endpoints): void
    {
        $catalogue = require ROOT . '/config/llm_catalog.php';
        foreach ($endpoints as $nom => $cible) {
            $catalogue[$fournisseur]['endpoints'][$nom] = $cible;
        }
        (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);
    }

    /** Adaptateur sans base : clé factice, modèle imposé. */
    private function adapter(string $classe, string $modele): LLMInterface
    {
        $adapter = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
        (new ReflectionProperty($classe, 'apiKey'))->setValue($adapter, 'cle-factice');
        (new ReflectionProperty($classe, 'model'))->setValue($adapter, $modele);
        return $adapter;
    }
}
