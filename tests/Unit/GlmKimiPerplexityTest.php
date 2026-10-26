<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * GLM (Z.ai), Kimi (Moonshot) et Perplexity, relus sur leurs documentations
 * officielles le 2026-10-01.
 *
 *  - Z.ai : GLM-5.3 (18/08/2026) et GLM-5.3-Flash (26/08/2026, vision)
 *    manquaient ; la réflexion y est forcée (MIA n'envoie pas `thinking`) et
 *    l'adaptateur réduisait toute image au texte.
 *  - Moonshot : fenêtre de K3 = 1 048 576 jetons, écriture de cache au prix de
 *    l'entrée ; K2.6 (généraliste économique) manquait ; K2.7 Code est écarté
 *    (raisonnement de tout l'historique exigé, que MIA ne conserve pas).
 *  - Perplexity : `sonar-reasoning` retiré le 15/12/2025 était encore proposé ;
 *    fin de support de Sonar Chat Completions le 27/09/2026 (requêtes
 *    synchrones toujours servies) ; les frais par requête de Sonar n'étaient
 *    jamais comptés.
 */
final class GlmKimiPerplexityTest extends TestCase
{
    protected function tearDown(): void
    {
        LlmCatalog::flushCache();
    }

    // ------------------------------------------------------------------
    // Z.ai (GLM)
    // ------------------------------------------------------------------

    public function testGlm53EtSaVersionFlashSontAuCatalogueSansDevenirLeDefaut(): void
    {
        $this->assertSame(['glm-5.3-flash', 'glm-5.3', 'glm-5.2'], array_keys(LlmCatalog::models('zai')), 'du plus récent au plus ancien');
        $this->assertSame('glm-5.2', LlmCatalog::defaultModel('zai'), 'un nouveau modèle ne devient pas le défaut en silence');

        foreach (['glm-5.3' => 'GLM 5.3', 'glm-5.3-flash' => 'GLM 5.3 Flash'] as $id => $label) {
            $this->assertSame($label, LlmCatalog::model('zai', $id)['label']);
            $this->assertSame(1000000, LlmCatalog::contextWindow('zai', $id), $id);
            $this->assertTrue(LlmCatalog::supportsToolUse('zai', $id), $id);
            $this->assertTrue(LlmCatalog::supportsTemperature('zai', $id), "$id : temperature ∈ [0, 1] acceptée");
            $this->assertFalse(LlmCatalog::supportsWebSearch('zai', $id), $id);
            $this->assertNull(LlmCatalog::thinkingMode('zai', $id), "$id : réflexion forcée, rien à envoyer");
        }
        $this->assertTrue(LlmCatalog::acceptsImages('zai', 'glm-5.3-flash'));
        $this->assertFalse(LlmCatalog::acceptsImages('zai', 'glm-5.3'), 'GLM-5.3 : texte seul');
        $this->assertFalse(LlmCatalog::acceptsImages('zai', 'glm-5.2'));

        $flash = LlmCatalog::pricing('zai', 'glm-5.3-flash');
        $this->assertSame([0.15, 0.50, 0.03, 0.15], [$flash['input'], $flash['output'], $flash['cached_input'], $flash['cache_write']]);
        $glm53 = LlmCatalog::pricing('zai', 'glm-5.3');
        $glm52 = LlmCatalog::pricing('zai', 'glm-5.2');
        foreach (['input', 'output', 'cached_input', 'cache_write'] as $cle) {
            $this->assertSame($glm52[$cle], $glm53[$cle], "GLM-5.3 au tarif de GLM-5.2 : $cle");
        }
        $this->assertSame(1.40, $glm53['cache_write'], 'aucune surfacturation à l\'écriture de cache');
        $this->assertSame(['tool_choice_none' => false, 'tool_message_name' => false, 'reasoning_echo' => true],
            LlmCatalog::toolCallingDialect('zai'), 'tool_choice « only supports auto », raisonnement rendu dans la boucle');
    }

    public function testLaVersionFlashRecoitLesImagesLesAutresLeTexte(): void
    {
        $messages = [['role' => 'user', 'content' => [
            ['type' => 'image', 'mime' => 'image/jpeg', 'data' => 'QUJD'],
            ['type' => 'text', 'text' => 'Décris.'],
        ]]];

        $flash = $this->zai('glm-5.3-flash');
        $this->assertSame([
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,QUJD']],
            ['type' => 'text', 'text' => 'Décris.'],
        ], $this->formater($flash, $messages)[0]['content']);

        $this->assertSame('Décris.', $this->formater($this->zai('glm-5.3'), $messages)[0]['content']);

        $source = (string)file_get_contents(ROOT . '/src/LLM/ZaiAdapter.php');
        $this->assertStringContainsString("LlmAdapterHelpers::chatContent(\$messages, LlmCatalog::acceptsImages('zai', \$this->model))", $source);
    }

    // ------------------------------------------------------------------
    // Moonshot (Kimi)
    // ------------------------------------------------------------------

    public function testKimiK3AjusteEtK26Ajoute(): void
    {
        $this->assertSame(['kimi-k3', 'kimi-k2.6'], array_keys(LlmCatalog::models('moonshot')));
        $this->assertSame('kimi-k3', LlmCatalog::defaultModel('moonshot'));

        $this->assertSame(1048576, LlmCatalog::contextWindow('moonshot', 'kimi-k3'));
        $k3 = LlmCatalog::pricing('moonshot', 'kimi-k3');
        $this->assertSame([3.00, 15.00, 0.30, 3.00], [$k3['input'], $k3['output'], $k3['cached_input'], $k3['cache_write']],
            'écriture de cache (TTL 5 min) au prix de l\'entrée, pas 1,25×');

        $info = LlmCatalog::model('moonshot', 'kimi-k2.6');
        $this->assertSame('Kimi K2.6', $info['label']);
        $this->assertContains('vision', $info['tags']);
        $this->assertSame(262144, LlmCatalog::contextWindow('moonshot', 'kimi-k2.6'));
        $this->assertFalse(LlmCatalog::supportsTemperature('moonshot', 'kimi-k2.6'), '« cannot be modified » : jamais envoyée');
        $this->assertTrue(LlmCatalog::supportsToolUse('moonshot', 'kimi-k2.6'));
        $this->assertNull(LlmCatalog::maxOutputTokens('moonshot', 'kimi-k2.6'), 'entrée + plafond > contexte serait refusé : aucun plafond imposé');
        $k26 = LlmCatalog::pricing('moonshot', 'kimi-k2.6');
        $this->assertSame([0.95, 4.00, 0.16, 0.95], [$k26['input'], $k26['output'], $k26['cached_input'], $k26['cache_write']]);
    }

    public function testLesModelesKimiRetiresOuEcartesNeSontPasProposes(): void
    {
        foreach (['kimi-k2.5', 'kimi-k2.7-code', 'kimi-k2.7-code-highspeed', 'moonshot-v1-128k', 'kimi-k2-thinking', 'kimi-latest'] as $id) {
            $this->assertNull(LlmCatalog::model('moonshot', $id), $id);
        }
        $source = (string)file_get_contents(ROOT . '/config/llm_catalog.php');
        $this->assertStringContainsString('kimi-k2.7-code (et -highspeed)', $source, 'l\'écart de K2.7 Code se lit au catalogue, avec sa raison');
    }

    // ------------------------------------------------------------------
    // Perplexity
    // ------------------------------------------------------------------

    public function testSonarReasoningRetireAQuitteLeCatalogue(): void
    {
        $this->assertSame(['sonar-pro', 'sonar', 'sonar-reasoning-pro'], array_keys(LlmCatalog::models('perplexity')));
        $this->assertNull(LlmCatalog::model('perplexity', 'sonar-reasoning'));
        $source = (string)file_get_contents(ROOT . '/config/llm_catalog.php');
        $this->assertStringContainsString('15/12/2025', $source, 'la date du retrait se lit au catalogue');
        $this->assertStringContainsString('27/09/2026', $source, 'la fin de support de Sonar Chat Completions aussi');
        foreach (array_keys(LlmCatalog::models('perplexity')) as $id) {
            $this->assertTrue(LlmCatalog::supportsWebSearch('perplexity', (string)$id), (string)$id);
            $this->assertFalse(LlmCatalog::supportsToolUse('perplexity', (string)$id), (string)$id);
        }
    }

    public function testSonarEstTarifeAvecSesFraisParRequete(): void
    {
        $attendus = [
            'sonar'               => [1.00, 1.00, 5.0],
            'sonar-pro'           => [3.00, 15.00, 6.0],
            'sonar-reasoning-pro' => [2.00, 8.00, 6.0],
        ];
        foreach ($attendus as $id => [$entree, $sortie, $requete]) {
            $prix = LlmCatalog::pricing('perplexity', $id);
            $this->assertSame([$entree, $sortie], [$prix['input'], $prix['output']], $id);
            $this->assertSame($requete, LlmCatalog::webSearchPricingUsdPer1k('perplexity', $id), "$id : frais par requête (contexte low)");
            $this->assertSame('prompt', LlmCatalog::webSearchBilling('perplexity', $id), "$id : une requête par réponse");
        }
    }

    public function testUneReponseSonarCompteUneRequete(): void
    {
        $this->assertSame(1, PerplexityAdapter::billedRequests(true, false));
        $this->assertSame(0, PerplexityAdapter::billedRequests(false, false), 'réponse vide : rien servi');
        $this->assertSame(1, PerplexityAdapter::billedRequests(true, true), 'coupée après du texte : la requête a été servie, donc facturée');
        $this->assertSame(0, PerplexityAdapter::billedRequests(false, true), 'refusée d\'emblée : rien servi');

        // Le vrai code de lecture du flux, rejoué depuis un fichier local.
        $fichier = tempnam(sys_get_temp_dir(), 'mia-pplx-');
        file_put_contents($fichier,
            'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Bonjour.']]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3]]) . "\n\n"
            . "data: [DONE]\n\n");
        try {
            $catalogue = require ROOT . '/config/llm_catalog.php';
            $catalogue['perplexity']['endpoints']['chat_completions'] = $fichier;
            (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);

            $adapter = (new ReflectionClass(PerplexityAdapter::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(PerplexityAdapter::class, 'apiKey'))->setValue($adapter, 'cle-factice');
            (new ReflectionProperty(PerplexityAdapter::class, 'model'))->setValue($adapter, 'sonar');
            $texte = '';
            $jetons = $adapter->stream([['role' => 'user', 'content' => 'Salut']], '', function (string $c) use (&$texte): void { $texte .= $c; });

            $this->assertSame('Bonjour.', $texte);
            $this->assertSame(1, $jetons['web_searches']);
            $this->assertSame(10, $jetons['input']);
        } finally {
            @unlink($fichier);
        }
    }

    // ------------------------------------------------------------------

    private function zai(string $modele): ZaiAdapter
    {
        $adapter = (new ReflectionClass(ZaiAdapter::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ZaiAdapter::class, 'model'))->setValue($adapter, $modele);
        return $adapter;
    }

    private function formater(ZaiAdapter $adapter, array $messages): array
    {
        return (new ReflectionMethod(ZaiAdapter::class, 'formatMessages'))->invoke($adapter, $messages);
    }
}
