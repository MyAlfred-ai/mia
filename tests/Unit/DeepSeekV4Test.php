<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DeepSeek V4.1 Flash (10 septembre 2026), seul modèle servi par l'API.
 *
 * Le catalogue ne proposait que `deepseek-chat` (V3) et `deepseek-reasoner`
 * (R1), retirés et inaccessibles depuis le 24/07/2026 : chaque message sur
 * DeepSeek échouait. Documentation DeepSeek (Models & Pricing, référence de
 * l'API, guide Thinking Mode, annonces du 24/04 et du 10/09/2026) relue le
 * 2026-10-01. Trois pièges payés d'avance :
 *   1. la réflexion est ACTIVE PAR DÉFAUT — `temperature` ignorée, et avec des
 *      outils le raisonnement de tous les tours passés est exigé (400) ;
 *   2. sans `max_tokens`, l'API borne la sortie à 8K ;
 *   3. le modèle lit les images : les laisser tomber en silence serait un
 *      recul sur ce qu'annonce sa fiche.
 */
final class DeepSeekV4Test extends TestCase
{
    protected function tearDown(): void
    {
        LlmCatalog::flushCache();
    }

    public function testV41FlashEstLeSeulModeleEtLeDefaut(): void
    {
        $this->assertSame(['deepseek-flash'], array_keys(LlmCatalog::models('deepseek')));
        $this->assertSame('deepseek-flash', LlmCatalog::defaultModel('deepseek'));

        $info = LlmCatalog::model('deepseek', 'deepseek-flash');
        $this->assertSame('DeepSeek V4.1 Flash', $info['label']);
        $this->assertContains('vision', $info['tags']);
        $this->assertTrue(LlmCatalog::supportsToolUse('deepseek', 'deepseek-flash'));
        $this->assertTrue(LlmCatalog::supportsTemperature('deepseek', 'deepseek-flash'), 'hors réflexion, la température est honorée');
        $this->assertFalse(LlmCatalog::supportsWebSearch('deepseek', 'deepseek-flash'));
        $this->assertSame(1000000, LlmCatalog::contextWindow('deepseek', 'deepseek-flash'));
        $this->assertFalse(LlmCatalog::thinkingMode('deepseek', 'deepseek-flash'));
        $this->assertSame(65536, LlmCatalog::maxOutputTokens('deepseek', 'deepseek-flash'));

        $prix = LlmCatalog::pricing('deepseek', 'deepseek-flash');
        $this->assertSame(0.30, $prix['input'], 'tarif de pointe : jamais de sous-facturation muette');
        $this->assertSame(1.20, $prix['output']);
        $this->assertSame(0.006, $prix['cached_input']);
        $this->assertSame(0.30, $prix['cache_write'], 'cache automatique : aucune surfacturation à l\'écriture');
    }

    /** Les identifiants retirés ont quitté le catalogue, avec leur date. */
    public function testLesModelesRetiresOntQuitteLeCatalogueAvecLeurDate(): void
    {
        foreach (['deepseek-chat', 'deepseek-reasoner', 'deepseek-v4-flash', 'deepseek-v4-flash-vision-exp', 'deepseek-v4-pro'] as $id) {
            $this->assertNull(LlmCatalog::model('deepseek', $id), "$id ne doit plus être proposé");
        }
        $source = (string)file_get_contents(ROOT . '/config/llm_catalog.php');
        $this->assertStringContainsString('24/07/2026', $source, 'la date du retrait de deepseek-chat / deepseek-reasoner se lit au catalogue');
        $this->assertStringContainsString('14/09/2026', $source, 'le sort de deepseek-v4-pro (relais annoncé au 14/09, puis maintenu) se lit au catalogue');
        $this->assertStringContainsString('continue providing API services for DeepSeek V4 Pro', $source, 'le revirement de DeepSeek est cité au catalogue');
    }

    public function testUnModeleInconnuEstJugeSurLeDefaut(): void
    {
        $this->assertFalse(LlmCatalog::thinkingMode('deepseek', 'deepseek-inconnu'));
        $this->assertTrue(LlmCatalog::acceptsImages('deepseek', 'deepseek-inconnu'));
        // Les autres fournisseurs n'ont pas de mode de réflexion piloté par MIA.
        $this->assertNull(LlmCatalog::thinkingMode('openai', 'gpt-5.6'));
        $this->assertNull(LlmCatalog::thinkingMode('moonshot', 'kimi-k3'));
        $this->assertNull(LlmCatalog::thinkingMode('inconnu', 'x'));
    }

    public function testLesChampsDeRequeteSontLusAuCatalogue(): void
    {
        $this->assertSame(
            ['thinking' => ['type' => 'disabled'], 'max_tokens' => 65536],
            DeepSeekAdapter::requestExtras('deepseek-flash')
        );

        // Un catalogue qui rallume la réflexion et retire le plafond.
        $catalogue = require ROOT . '/config/llm_catalog.php';
        $catalogue['deepseek']['models']['deepseek-flash']['thinking'] = true;
        unset($catalogue['deepseek']['models']['deepseek-flash']['max_output_tokens']);
        (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);
        $this->assertSame(['thinking' => ['type' => 'enabled']], DeepSeekAdapter::requestExtras('deepseek-flash'));

        // Rien de déclaré : rien d'envoyé, le défaut de l'API s'applique.
        unset($catalogue['deepseek']['models']['deepseek-flash']['thinking']);
        (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);
        $this->assertSame([], DeepSeekAdapter::requestExtras('deepseek-flash'));
    }

    public function testLesImagesPartentEnDataUrlLesDocumentsEnTexte(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'user', 'content' => [
                ['type' => 'image', 'mime' => 'image/png', 'data' => "QUJD\nREVG"],
                ['type' => 'document', 'mime' => 'application/pdf', 'data' => 'JVBERi0='],
                ['type' => 'text', 'text' => 'Que vois-tu ?'],
            ]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Un chat.']]],
        ];

        $avec = LlmAdapterHelpers::chatContent($messages, true);
        $this->assertSame(['role' => 'user', 'content' => 'Bonjour'], $avec[0]);
        $this->assertSame([
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QUJDREVG']],
            ['type' => 'text', 'text' => 'Que vois-tu ?'],
        ], $avec[1]['content'], 'image en data URL (blancs retirés), PDF écarté, texte en dernier');
        $this->assertSame('Un chat.', $avec[2]['content'], 'un message réduit à son texte redevient une chaîne');

        $sans = LlmAdapterHelpers::chatContent($messages, false);
        $this->assertSame('Que vois-tu ?', $sans[1]['content'], 'modèle sans vision : texte seul, comme avant');
    }

    /**
     * Câblage : le flux simple et la boucle d'outils passent par les mêmes
     * champs, le trait les prend à chaque aller, et le test de clé de
     * l'administration suit le catalogue (Gemini 2.5 n'est plus ouvert aux
     * nouveaux comptes depuis le 18/09/2026).
     */
    public function testCablage(): void
    {
        $adapter = (string)file_get_contents(ROOT . '/src/LLM/DeepSeekAdapter.php');
        $this->assertSame(2, substr_count($adapter, 'self::requestExtras($this->model)'), 'flux simple ET boucle d\'outils');
        $this->assertStringContainsString("LlmAdapterHelpers::chatContent(\$messages, LlmCatalog::acceptsImages('deepseek', \$this->model))", $adapter);
        $this->assertStringContainsString('LlmCatalog::defaultModel(\'deepseek\')', $adapter, 'plus aucun modèle par défaut en dur');

        $trait = (string)file_get_contents(ROOT . '/src/LLM/ChatCompletionsToolLoopTrait.php');
        $this->assertStringContainsString('] + $this->toolLoopPayloadExtras();', $trait);

        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString("'deepseek', LlmCatalog::defaultModel('deepseek'), 'DeepSeek'", $admin);
        $this->assertStringContainsString("'gemini', 'gemini-3.5-flash-lite', 'Gemini'", $admin);
    }

    /**
     * Plus aucune référence aux modèles retirés dans le code ni dans la
     * documentation publiée (docs/user ne nomme pas de modèle). Le guide
     * administrateur, lui, DOIT les nommer : c'est ainsi qu'un administrateur
     * reconnaît, sur la page Clés API ou dans un assistant, le choix à refaire.
     */
    public function testPlusAucuneReferenceAuxModelesRetires(): void
    {
        $fichiers = [];
        foreach (['src', 'templates', 'public/assets/js', 'docs/user', 'config'] as $dossier) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/' . $dossier, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (preg_match('/\.(php|js|md)$/', $f->getFilename()) === 1) {
                    $fichiers[] = $f->getPathname();
                }
            }
        }
        foreach ($fichiers as $f) {
            $contenu = (string)file_get_contents($f);
            if ($f === ROOT . '/config/llm_catalog.php') {
                continue; // le catalogue nomme les retirés pour dire qu'ils le sont
            }
            foreach (['deepseek-chat', 'deepseek-reasoner', 'DeepSeek R1', 'DeepSeek (V3)'] as $motif) {
                $this->assertStringNotContainsString($motif, $contenu, $f . ' cite encore ' . $motif);
            }
        }
    }
}
