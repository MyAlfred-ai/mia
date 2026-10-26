<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Gemini 3.8 Flash, 3.7 Flash et 3.5 Flash-Lite (septembre 2026), et les trois
 * défauts de l'adapter Gemini que leur arrivée rendait coûteux :
 *
 *  1. la réflexion n'était pas facturée en entier — `candidatesTokenCount`
 *     l'exclut, alors que CreditService la tient pour une PARTIE de la sortie ;
 *  2. la température partait vers des modèles pour lesquels Google l'a
 *     dépréciée et déconseille toute autre valeur que 1,0 ;
 *  3. la recherche Google était comptée une fois par réponse, à 35 $ les
 *     1 000, quand la génération 3.x facture chaque requête, 14 $ les 1 000.
 *
 * Sans réseau ni base : le flux SSE est rejoué depuis un fichier local nommé
 * comme l'URL que l'adapter construit.
 */
final class GeminiModelsTest extends TestCase
{
    private const NOUVEAUX = [
        'gemini-3.8-flash'      => 'Gemini 3.8 Flash',
        'gemini-3.7-flash'      => 'Gemini 3.7 Flash',
        'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite',
    ];

    private ?string $dossier = null;

    protected function tearDown(): void
    {
        LlmCatalog::setToday(null);
        LlmCatalog::flushCache();
        if ($this->dossier !== null) {
            foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    public function testLesTroisModelesSontAuCatalogue(): void
    {
        foreach (self::NOUVEAUX as $id => $label) {
            $info = LlmCatalog::model('gemini', $id);
            $this->assertNotNull($info, "$id absent du catalogue");
            $this->assertSame($label, $info['label']);
            $this->assertSame(1048576, LlmCatalog::contextWindow('gemini', $id), $id);
            $this->assertTrue(LlmCatalog::supportsWebSearch('gemini', $id), $id);
            // Boucle d'outils native (functionDeclarations), recherche Google
            // cumulée avec les outils sur toute la génération 3.
            $this->assertTrue(LlmCatalog::supportsToolUse('gemini', $id), $id);
            $this->assertTrue(LlmCatalog::supportsWebSearchWithTools('gemini', $id), $id);
            $this->assertFalse(LlmCatalog::supportsTemperature('gemini', $id), $id);
            $this->assertNotNull(LlmCatalog::pricing('gemini', $id), "$id sans tarif curé");
        }
    }

    /**
     * Ordre du picker : du plus récent au plus ancien. Et le défaut ne bascule
     * pas en silence sur un modèle qui coûtera plus du double en 2027.
     */
    public function testIlsOuvrentLeFournisseurSansDevenirLeDefaut(): void
    {
        // Du plus récent au plus ancien : 3.8 (2 sept.), 3.7 (13 août), 3.6 et
        // 3.5 Flash-Lite (21 juillet), 3.5 Flash (19 mai), 3.1 Flash-Lite (7 mai).
        $this->assertSame(
            ['gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-3.1-flash-lite'],
            array_slice(array_keys(LlmCatalog::models('gemini')), 0, 6)
        );
        $this->assertSame('gemini-3-flash-preview', LlmCatalog::defaultModel('gemini'));
    }

    /**
     * Gemini 3.6 Flash (GA 21/07/2026, même tarif et même lancement que 3.7 et
     * 3.8 Flash), 3.5 Flash (GA 19/05/2026, plus cher, « legacy ») et 3.1
     * Flash-Lite (GA 07/05/2026, arrêt annoncé au plus tôt le 07/05/2027)
     * manquaient au catalogue — pages Models, Pricing, Deprecations et
     * Release notes de Google relues le 2026-10-01. La génération 2.5 n'est
     * plus ouverte aux nouveaux comptes depuis le 18/09/2026 : la limite le dit.
     */
    public function testLesModelesManquantsDeLaGeneration3SontAuCatalogue(): void
    {
        foreach (['gemini-3.6-flash' => 'Gemini 3.6 Flash', 'gemini-3.5-flash' => 'Gemini 3.5 Flash', 'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite'] as $id => $label) {
            $info = LlmCatalog::model('gemini', $id);
            $this->assertNotNull($info, "$id absent du catalogue");
            $this->assertSame($label, $info['label']);
            $this->assertSame(1048576, LlmCatalog::contextWindow('gemini', $id), $id);
            $this->assertTrue(LlmCatalog::supportsToolUse('gemini', $id), $id);
            $this->assertTrue(LlmCatalog::supportsWebSearchWithTools('gemini', $id), $id);
            $this->assertFalse(LlmCatalog::supportsTemperature('gemini', $id), $id);
            $this->assertSame(14.0, LlmCatalog::webSearchPricingUsdPer1k('gemini', $id), $id);
            $this->assertSame('request', LlmCatalog::webSearchBilling('gemini', $id), $id);
        }

        LlmCatalog::setToday('2026-10-01');
        $flash36 = LlmCatalog::pricing('gemini', 'gemini-3.6-flash');
        $this->assertSame([0.75, 3.75, 0.075, '2026-12-31'], [$flash36['input'], $flash36['output'], $flash36['cached_input'], $flash36['launch_until']]);
        LlmCatalog::setToday('2027-01-01');
        $flash36 = LlmCatalog::pricing('gemini', 'gemini-3.6-flash');
        $this->assertSame([1.50, 7.50, 0.15], [$flash36['input'], $flash36['output'], $flash36['cached_input']]);

        $flash35 = LlmCatalog::pricing('gemini', 'gemini-3.5-flash');
        $this->assertSame([1.50, 9.00, 0.15], [$flash35['input'], $flash35['output'], $flash35['cached_input']]);
        $this->assertArrayNotHasKey('launch_until', $flash35, 'aucun tarif de lancement sur 3.5 Flash');

        $lite31 = LlmCatalog::pricing('gemini', 'gemini-3.1-flash-lite');
        $this->assertSame([0.25, 1.50, 0.025], [$lite31['input'], $lite31['output'], $lite31['cached_input']]);
        $this->assertStringContainsString('07/05/2027', implode(' ', LlmCatalog::model('gemini', 'gemini-3.1-flash-lite')['limits']));

        foreach (['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'] as $id) {
            $this->assertStringContainsString('18/09/2026', implode(' ', LlmCatalog::model('gemini', $id)['limits']), $id);
        }
    }

    /** Coupé par Google le 25/05/2026 : il répondait 404 depuis le picker. */
    public function testLeModeleCoupeParGoogleAQuitteLeCatalogue(): void
    {
        $this->assertNull(LlmCatalog::model('gemini', 'gemini-3.1-flash-lite-preview'));
        $this->assertStringContainsString(
            'gemini-3.1-flash-lite-preview — coupé le 25/05/2026',
            (string)file_get_contents(ROOT . '/config/llm_catalog.php'),
            'La disparition doit se lire au catalogue, avec sa date.'
        );
    }

    public function testAucunModeleGemini3NeRecoitDeTemperature(): void
    {
        foreach (array_keys(LlmCatalog::models('gemini')) as $id) {
            $id = (string)$id;
            if (str_starts_with($id, 'gemini-3')) {
                $this->assertFalse(LlmCatalog::supportsTemperature('gemini', $id), $id);
            } elseif (str_starts_with($id, 'gemini-2.5')) {
                $this->assertTrue(LlmCatalog::supportsTemperature('gemini', $id), $id);
            }
        }
    }

    // ------------------------------------------------------------------
    // Tarif de lancement
    // ------------------------------------------------------------------

    public function testLeTarifDeLancementSEffaceSeulASaDate(): void
    {
        LlmCatalog::setToday('2026-09-16');
        $lancement = LlmCatalog::pricing('gemini', 'gemini-3.8-flash');
        $this->assertSame(0.75, $lancement['input']);
        $this->assertSame(3.75, $lancement['output']);
        $this->assertSame(0.075, $lancement['cached_input']);
        $this->assertSame('2026-12-31', $lancement['launch_until']);
        $this->assertArrayNotHasKey('launch', $lancement);

        // Dernier jour inclus.
        LlmCatalog::setToday('2026-12-31');
        $this->assertSame(0.75, LlmCatalog::pricing('gemini', 'gemini-3.7-flash')['input']);

        LlmCatalog::setToday('2027-01-01');
        foreach (['gemini-3.8-flash', 'gemini-3.7-flash'] as $id) {
            $normal = LlmCatalog::pricing('gemini', $id);
            $this->assertSame(1.50, $normal['input'], $id);
            $this->assertSame(7.50, $normal['output'], $id);
            $this->assertSame(0.15, $normal['cached_input'], $id);
            $this->assertArrayNotHasKey('launch_until', $normal, $id);
        }

        // Sans tarif de lancement : le même tarif toute l'année.
        $lite = LlmCatalog::pricing('gemini', 'gemini-3.5-flash-lite');
        $this->assertSame(0.30, $lite['input']);
        $this->assertSame(2.50, $lite['output']);
    }

    public function testUnTarifDeLancementSansDateValideNestJamaisApplique(): void
    {
        $normal = ['input' => 2.0, 'output' => 8.0];

        $this->assertSame($normal, LlmCatalog::effectivePricing($normal, '2026-09-16'));

        foreach (['', '31/12/2026', '2026-12'] as $until) {
            $prix = LlmCatalog::effectivePricing($normal + ['launch' => ['until' => $until, 'input' => 1.0]], '2026-09-16');
            $this->assertSame($normal, $prix, "until « $until » ne borne rien : tarif normal");
        }

        // Un bloc partiel ne remplace que ce qu'il déclare.
        $partiel = LlmCatalog::effectivePricing($normal + ['launch' => ['until' => '2026-12-31', 'input' => 1.0]], '2026-09-16');
        $this->assertSame(1.0, $partiel['input']);
        $this->assertSame(8.0, $partiel['output']);
    }

    // ------------------------------------------------------------------
    // Recherche Google
    // ------------------------------------------------------------------

    public function testLaRechercheGoogleEstFactureeSelonLaGeneration(): void
    {
        $this->assertSame(14.0, LlmCatalog::webSearchPricingUsdPer1k('gemini', 'gemini-3.8-flash'));
        $this->assertSame(14.0, LlmCatalog::webSearchPricingUsdPer1k('gemini', 'gemini-3-flash-preview'));
        $this->assertSame(14.0, LlmCatalog::webSearchPricingUsdPer1k('gemini', 'modele-absent-du-catalogue'));
        $this->assertSame(35.0, LlmCatalog::webSearchPricingUsdPer1k('gemini', 'gemini-2.5-pro'));
        $this->assertSame(10.0, LlmCatalog::webSearchPricingUsdPer1k('openai', 'gpt-5.6'));
        $this->assertNull(LlmCatalog::webSearchPricingUsdPer1k('deepseek', 'deepseek-flash'));

        $this->assertSame('request', LlmCatalog::webSearchBilling('gemini', 'gemini-3.8-flash'));
        $this->assertSame('request', LlmCatalog::webSearchBilling('anthropic', 'claude-opus-5'));
        foreach (['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'] as $id) {
            $this->assertSame('prompt', LlmCatalog::webSearchBilling('gemini', $id), $id);
        }

        $this->assertSame(3, GeminiAdapter::billedWebSearches('gemini-3.8-flash', 3));
        $this->assertSame(1, GeminiAdapter::billedWebSearches('gemini-2.5-flash', 3));
        $this->assertSame(0, GeminiAdapter::billedWebSearches('gemini-3.8-flash', 0));
    }

    // ------------------------------------------------------------------
    // Adapter
    // ------------------------------------------------------------------

    public function testLaReflexionEstCompteeDansLaSortie(): void
    {
        $jetons = GeminiAdapter::tokensFromUsage([
            'promptTokenCount'        => 100,
            'candidatesTokenCount'    => 12,
            'thoughtsTokenCount'      => 40,
            'cachedContentTokenCount' => 20,
            'totalTokenCount'         => 152,
        ]);

        $this->assertSame(100, $jetons['input']);
        $this->assertSame(52, $jetons['output'], 'sortie = réponse + réflexion, comme la facture Google');
        $this->assertSame(40, $jetons['output_reasoning']);
        $this->assertSame(20, $jetons['input_cached']);
        $this->assertSame(0, $jetons['input_cache_write']);
        // Contrat de CreditService : il tarife `output − output_reasoning` puis
        // la réflexion — la réponse et la réflexion le sont donc chacune en entier.
        $this->assertSame(12, $jetons['output'] - $jetons['output_reasoning']);

        $this->assertSame(
            ['input' => 0, 'output' => 0, 'input_cached' => 0, 'input_cache_write' => 0, 'output_reasoning' => 0],
            GeminiAdapter::tokensFromUsage([])
        );
    }

    public function testLaTemperatureNePartQueVersLesModelesQuiLaGerent(): void
    {
        $messages = [['role' => 'user', 'content' => 'Bonjour']];

        $gemini3 = $this->adapter('gemini-3.8-flash')->buildPayload($messages, 'Tu es utile.', 0.2, true);
        $this->assertArrayNotHasKey('generationConfig', $gemini3);
        $this->assertEquals([['google_search' => new stdClass()]], $gemini3['tools']);
        $this->assertSame('Tu es utile.', $gemini3['systemInstruction']['parts'][0]['text']);

        $gemini25 = $this->adapter('gemini-2.5-flash')->buildPayload($messages, '', 0.2, false);
        $this->assertSame(['temperature' => 0.2], $gemini25['generationConfig']);
        $this->assertArrayNotHasKey('tools', $gemini25);
        $this->assertArrayNotHasKey('systemInstruction', $gemini25);
    }

    /**
     * Le vrai code de lecture du flux : texte reconstitué, réflexion facturée,
     * requêtes Google dédoublonnées quand leurs métadonnées reviennent dans
     * plusieurs évènements — puis comptées selon la génération du modèle.
     */
    public function testLeFluxEstLuDeBoutEnBout(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Nom de fichier avec « : » et « ? » impossible sous Windows.');
        }
        $this->dossier = sys_get_temp_dir() . '/mia-gemini-' . bin2hex(random_bytes(6));
        mkdir($this->dossier);

        $evenements = [
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Bonjour']]]]],
             'usageMetadata' => ['promptTokenCount' => 100, 'totalTokenCount' => 100]],
            ['candidates' => [['content' => ['parts' => [['text' => ' le monde']]],
                'groundingMetadata' => ['webSearchQueries' => ['meteo paris', 'meteo paris demain']]]]],
            ['candidates' => [['content' => ['parts' => [['text' => '.']]], 'finishReason' => 'STOP',
                'groundingMetadata' => ['webSearchQueries' => ['meteo paris', 'meteo paris demain']]]],
             'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 12,
                'thoughtsTokenCount' => 40, 'totalTokenCount' => 152]],
        ];
        $sse = '';
        foreach ($evenements as $evenement) {
            $sse .= 'data: ' . json_encode($evenement) . "\r\n\r\n";
        }

        $catalogue = require ROOT . '/config/llm_catalog.php';
        $catalogue['gemini']['endpoints']['base'] = $this->dossier . '/';
        (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);

        $attendus = ['gemini-3.8-flash' => 2, 'gemini-2.5-flash' => 1];
        foreach ($attendus as $modele => $recherches) {
            file_put_contents($this->dossier . '/' . $modele . ':streamGenerateContent?alt=sse', $sse);

            $texte  = '';
            $jetons = $this->adapter($modele)->stream(
                [['role' => 'user', 'content' => 'Quel temps fait-il ?']],
                '',
                function (string $morceau) use (&$texte): void { $texte .= $morceau; },
                null,
                true
            );

            $this->assertSame('Bonjour le monde.', $texte, $modele);
            $this->assertSame(100, $jetons['input'], $modele);
            $this->assertSame(52, $jetons['output'], $modele);
            $this->assertSame(40, $jetons['output_reasoning'], $modele);
            $this->assertSame($recherches, $jetons['web_searches'], $modele);
        }
    }

    /** L'adresse de l'API et le modèle de repli viennent du catalogue. */
    public function testLAdapterLitLeCatalogue(): void
    {
        $source = (string)file_get_contents(ROOT . '/src/LLM/GeminiAdapter.php');
        $this->assertStringContainsString("LlmCatalog::endpoint('gemini', 'base')", $source);
        $this->assertStringContainsString("LlmCatalog::defaultModel('gemini')", $source);
        $this->assertStringNotContainsString('DEFAULT_MODEL', $source);
    }

    /**
     * Écran Tests : à 5 tokens, la réflexion de Gemini 3 épuisait le plafond
     * et la réponse revenait vide sur un modèle qui marche.
     */
    public function testLeTestDeBaseLaisseReflechirGemini(): void
    {
        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertMatchesRegularExpression(
            "/str_contains\(\\\$url, 'generativelanguage\.googleapis\.com'\)[^{]*\) \{[^}]*\\\$payloadData\['max_tokens'\] = 1024;/s",
            $admin
        );
    }

    private function adapter(string $modele): GeminiAdapter
    {
        $adapter = (new ReflectionClass(GeminiAdapter::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(GeminiAdapter::class, 'apiKey'))->setValue($adapter, 'cle-factice');
        (new ReflectionProperty(GeminiAdapter::class, 'model'))->setValue($adapter, $modele);
        return $adapter;
    }
}
