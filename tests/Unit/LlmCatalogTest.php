<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Le catalogue LLM est la source unique de vérité (adapters, factory, UI).
 * On vérifie ses invariants structurels plutôt que des valeurs précises (qui
 * évoluent au fil des modèles) : chaque provider a un modèle par défaut existant,
 * et supportsTemperature reste cohérent — dont l'existence d'au moins un modèle
 * qui refuse la temperature (raisonnement) et un qui l'accepte.
 */
final class LlmCatalogTest extends TestCase
{
    public function testCatalogIsNotEmpty(): void
    {
        $all = LlmCatalog::all();
        $this->assertNotEmpty($all, 'le catalogue LLM ne doit pas être vide');
    }

    public function testEachProviderHasAnExistingDefaultModel(): void
    {
        foreach (LlmCatalog::all() as $provider => $data) {
            $this->assertArrayHasKey('models', $data, "provider $provider sans clé models");
            $default = LlmCatalog::defaultModel((string) $provider);
            $this->assertNotSame('', $default, "provider $provider sans modèle par défaut");
            $this->assertArrayHasKey(
                $default,
                $data['models'],
                "le modèle par défaut de $provider ($default) doit exister dans ses models"
            );
            $this->assertIsBool(LlmCatalog::supportsTemperature((string) $provider, $default));
        }
    }

    public function testTemperatureSupportIsMixedAcrossCatalog(): void
    {
        $sawSupported = false;
        $sawUnsupported = false;
        foreach (LlmCatalog::all() as $provider => $data) {
            foreach (array_keys($data['models'] ?? []) as $model) {
                if (LlmCatalog::supportsTemperature((string) $provider, (string) $model)) {
                    $sawSupported = true;
                } else {
                    $sawUnsupported = true;
                }
            }
        }
        $this->assertTrue($sawSupported, 'au moins un modèle doit gérer la temperature');
        $this->assertTrue($sawUnsupported, 'au moins un modèle (raisonnement) doit refuser la temperature');
    }

    /**
     * Capacité de recherche web : lue au catalogue, jamais d'une liste de
     * providers codée en dur — sinon l'option « Internet » reste sans effet
     * pour certains modèles, sans aucun message pour l'utilisateur.
     */
    public function testWebSearchSuitLeCatalogue(): void
    {
        foreach (LlmCatalog::all() as $provider => $data) {
            $provider = (string) $provider;
            $fallback = (string) ($data['fallback_search_model'] ?? '');
            foreach ($data['models'] ?? [] as $model => $info) {
                $attendu = !empty($info['web_native']) || $fallback !== '';
                $this->assertSame(
                    $attendu,
                    LlmCatalog::supportsWebSearch($provider, (string) $model),
                    "{$provider}::{$model}"
                );
            }
        }
    }

    /**
     * Claude Fable 5.1 : l'API rejette `temperature` (400) comme sur tout
     * Claude 5, et sa lecture de cache (0,25 $) sort du ratio conventionnel
     * de CreditService (0,1× l'entrée = 1 $) — elle DOIT donc être déclarée.
     * Il ne devient pas le modèle par défaut : il coûte deux fois Opus 5.
     */
    public function testClaudeFable51(): void
    {
        $info = LlmCatalog::model('anthropic', 'claude-fable-5-1');
        $this->assertNotNull($info, 'claude-fable-5-1 absent du catalogue');
        $this->assertSame('Claude Fable 5.1', $info['label']);
        $this->assertFalse(LlmCatalog::supportsTemperature('anthropic', 'claude-fable-5-1'));
        $this->assertTrue(LlmCatalog::supportsToolUse('anthropic', 'claude-fable-5-1'));
        $this->assertTrue(LlmCatalog::supportsWebSearch('anthropic', 'claude-fable-5-1'));
        $this->assertSame(1000000, LlmCatalog::contextWindow('anthropic', 'claude-fable-5-1'));

        $prix = LlmCatalog::pricing('anthropic', 'claude-fable-5-1');
        $this->assertSame(10.0, $prix['input']);
        $this->assertSame(50.0, $prix['output']);
        $this->assertArrayHasKey('cached_input', $prix, 'lecture de cache à déclarer : le ratio par défaut donnerait 1 $');
        $this->assertSame(0.25, $prix['cached_input']);
        $this->assertSame(12.5, $prix['cache_write']);

        $this->assertSame('claude-opus-5', LlmCatalog::defaultModel('anthropic'));
        // Fable 5 reste servi par Anthropic : il ne disparaît pas du catalogue.
        $this->assertNotNull(LlmCatalog::model('anthropic', 'claude-fable-5'));
    }

    /**
     * Claude Opus 5.5 et Sonnet 5.5 (22 et 28 septembre 2026), relus sur la
     * documentation d'Anthropic le 30/09/2026. L'API rejette `temperature` sur
     * tout Claude 4.7+, les deux classifieurs relaient leurs refus côté
     * serveur, et la lecture de cache d'Opus 5.5 (0,05× l'entrée) sort du
     * ratio conventionnel de CreditService — elle DOIT être déclarée. Aucun
     * des deux ne devient le défaut de l'instance.
     */
    public function testClaudeOpus55EtSonnet55(): void
    {
        foreach (['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5'] as $id => $label) {
            $info = LlmCatalog::model('anthropic', $id);
            $this->assertNotNull($info, "$id absent du catalogue");
            $this->assertSame($label, $info['label']);
            $this->assertFalse(LlmCatalog::supportsTemperature('anthropic', $id), $id);
            $this->assertTrue(LlmCatalog::supportsToolUse('anthropic', $id), $id);
            $this->assertTrue(LlmCatalog::supportsWebSearch('anthropic', $id), $id);
            $this->assertSame(1000000, LlmCatalog::contextWindow('anthropic', $id), $id);
            $this->assertSame('default', LlmCatalog::refusalFallback('anthropic', $id), $id);
            $this->assertNull(LlmCatalog::retentionFallback('anthropic', $id), "$id n'exige aucune conservation des données.");
        }

        $opus = LlmCatalog::pricing('anthropic', 'claude-opus-5-5');
        $this->assertSame(4.0, $opus['input']);
        $this->assertSame(20.0, $opus['output']);
        $this->assertSame(0.2, $opus['cached_input'], 'lecture de cache à déclarer : le ratio par défaut donnerait 0,40 $');
        $this->assertSame(5.0, $opus['cache_write']);

        $sonnet = LlmCatalog::pricing('anthropic', 'claude-sonnet-5-5');
        $this->assertSame(2.0, $sonnet['input']);
        $this->assertSame(10.0, $sonnet['output']);
        // Lecture de cache à 0,05× l'entrée (« $0.10 USD on Claude Sonnet
        // 5.5 », page Pricing relue le 17/10/2026) — le catalogue la croyait
        // identique à celle de Sonnet 5 (0,20 $).
        $this->assertSame(0.1, $sonnet['cached_input']);
        $this->assertSame(2.5, $sonnet['cache_write']);

        // Sonnet 5 : le tarif de lancement (2 $ / 10 $) est devenu définitif,
        // la hausse à 3 $ / 15 $ du 01/09/2026 n'a pas eu lieu. Sonnet 5.5
        // coûte la même chose, sauf la lecture de cache (0,10 $ contre 0,20 $).
        $sonnet5 = LlmCatalog::pricing('anthropic', 'claude-sonnet-5');
        foreach (['input', 'output', 'cache_write'] as $cle) {
            $this->assertSame($sonnet[$cle], $sonnet5[$cle], "Sonnet 5 : $cle");
        }
        $this->assertSame(0.2, $sonnet5['cached_input']);

        $this->assertSame('claude-opus-5', LlmCatalog::defaultModel('anthropic'), 'Un nouveau modèle ne devient pas le défaut en silence.');
    }

    public function testClaudeFable52EtHaiku55(): void
    {
        // Fiches « models/fable-5-2/overview » et « models/haiku-5-5/overview »,
        // relues le 2026-11-10.
        // Fable 5.2 : mêmes contraintes que Fable 5.1, moins cher.
        $this->assertFalse(LlmCatalog::supportsTemperature('anthropic', 'claude-fable-5-2'));
        $this->assertTrue(LlmCatalog::supportsToolUse('anthropic', 'claude-fable-5-2'));
        $this->assertTrue(LlmCatalog::supportsWebSearch('anthropic', 'claude-fable-5-2'));
        $this->assertTrue(LlmCatalog::supportsProgressUpdates('anthropic', 'claude-fable-5-2'));
        $this->assertSame(1000000, LlmCatalog::contextWindow('anthropic', 'claude-fable-5-2'));
        $this->assertSame('default', LlmCatalog::refusalFallback('anthropic', 'claude-fable-5-2'));
        $this->assertSame('claude-opus-5', LlmCatalog::retentionFallback('anthropic', 'claude-fable-5-2'));
        $fable = LlmCatalog::pricing('anthropic', 'claude-fable-5-2');
        $this->assertSame(8.0, $fable['input']);
        $this->assertSame(40.0, $fable['output']);
        $this->assertSame(0.2, $fable['cached_input'], 'Lecture de cache à 0,025× l\'entrée : le ratio par défaut donnerait 0,80 $.');
        $this->assertSame(10.0, $fable['cache_write']);
        $this->assertNull(LlmCatalog::longContext('anthropic', 'claude-fable-5-2'), 'Tarif unique sur tout le million de jetons.');

        // Haiku 5.5 : aucun repli serveur, pas de notes d'avancement, effort
        // `medium` par défaut, palier ×5 au-delà de 100 000 jetons d'entrée.
        $this->assertFalse(LlmCatalog::supportsTemperature('anthropic', 'claude-haiku-5-5'));
        $this->assertTrue(LlmCatalog::supportsToolUse('anthropic', 'claude-haiku-5-5'));
        $this->assertNull(LlmCatalog::refusalFallback('anthropic', 'claude-haiku-5-5'), 'Haiku 5.5 n\'a pas de repli serveur : le paramètre serait refusé.');
        $this->assertNull(LlmCatalog::retentionFallback('anthropic', 'claude-haiku-5-5'));
        $this->assertFalse(LlmCatalog::supportsProgressUpdates('anthropic', 'claude-haiku-5-5'));
        $this->assertSame('medium', LlmCatalog::defaultEffort('anthropic', 'claude-haiku-5-5'));
        $this->assertSame(['above' => 100000, 'input_ratio' => 5.0, 'output_ratio' => 5.0], LlmCatalog::longContext('anthropic', 'claude-haiku-5-5'));
        $haiku = LlmCatalog::pricing('anthropic', 'claude-haiku-5-5');
        $this->assertSame(0.1, $haiku['input']);
        $this->assertSame(0.5, $haiku['output']);
        $this->assertSame(0.01, $haiku['cached_input']);
        $this->assertSame(0.125, $haiku['cache_write']);

        $this->assertSame('claude-opus-5', LlmCatalog::defaultModel('anthropic'), 'Aucun des deux ne devient le défaut en silence.');
    }

    /**
     * GPT-6 Astra : hors du préfixe `gpt-5`, le routage par défaut du provider
     * ne le rattrape pas — sans `routing` déclaré il partirait sur Chat
     * Completions. L'API rejette `temperature` (400, vérifié le 10/09/2026) et
     * facture l'écriture de cache (12,50 $).
     */
    public function testGpt6Astra(): void
    {
        $info = LlmCatalog::model('openai', 'gpt-6-astra');
        $this->assertNotNull($info, 'gpt-6-astra absent du catalogue');
        $this->assertSame('GPT-6 Astra', $info['label']);
        $this->assertSame('responses_api', LlmCatalog::routing('openai', 'gpt-6-astra'));
        $this->assertFalse(LlmCatalog::supportsTemperature('openai', 'gpt-6-astra'));
        $this->assertTrue(LlmCatalog::supportsToolUse('openai', 'gpt-6-astra'));
        $this->assertTrue(LlmCatalog::supportsWebSearch('openai', 'gpt-6-astra'));
        $this->assertSame(1050000, LlmCatalog::contextWindow('openai', 'gpt-6-astra'));

        $prix = LlmCatalog::pricing('openai', 'gpt-6-astra');
        $this->assertSame(10.0, $prix['input']);
        $this->assertSame(50.0, $prix['output']);
        $this->assertSame(1.0, $prix['cached_input']);
        $this->assertSame(12.5, $prix['cache_write']);

        // Le modèle phare par défaut et le modèle de recherche de repli ne
        // basculent pas en silence sur un modèle deux fois plus cher.
        $this->assertSame('gpt-5.6', LlmCatalog::defaultModel('openai'));
        $this->assertSame('gpt-5.6', LlmCatalog::fallbackSearchModel('openai'));
    }

    /**
     * Mistral Large 4 : aperçu public le 06/10/2026, disponibilité générale le
     * 05/11/2026 (carte du modèle et page « Models » relues le 2026-11-10).
     * MIA retient l'identifiant figé `mistral-large-4-0`, au tarif de GA
     * (moitié de celui de l'aperçu). Depuis la revue du 2026-11-17, l'alias
     * `mistral-large-latest` (défaut de Mistral) sert Large 4 : même tarif,
     * plus d'arrêt. Large 3 et Medium 3.5 sont arrêtés le 30/04/2027.
     */
    public function testMistralLarge4(): void
    {
        $info = LlmCatalog::model('mistral', 'mistral-large-4-0');
        $this->assertNotNull($info, 'mistral-large-4-0 absent du catalogue');
        $this->assertSame('Mistral Large 4', $info['label']);
        $this->assertNull(LlmCatalog::model('mistral', 'mistral-large-4'), 'un seul identifiant pour le même modèle');
        $this->assertSame('mistral-large-4-0', array_key_first(LlmCatalog::models('mistral')), 'le plus récent ouvre Mistral');
        $this->assertContains('vision', $info['tags']);
        $this->assertTrue(LlmCatalog::supportsToolUse('mistral', 'mistral-large-4-0'));
        $this->assertTrue(LlmCatalog::supportsWebSearch('mistral', 'mistral-large-4-0'));
        $this->assertFalse(LlmCatalog::supportsWebSearchWithTools('mistral', 'mistral-large-4-0'));
        $this->assertSame(1000000, LlmCatalog::contextWindow('mistral', 'mistral-large-4-0'));

        $prix = LlmCatalog::pricing('mistral', 'mistral-large-4-0');
        $this->assertSame([0.7, 2.1, 0.07], [$prix['input'], $prix['output'], $prix['cached_input']]);
        $this->assertArrayNotHasKey('launch_until', $prix, 'Plus de remise : le tarif de GA est le tarif.');

        // L'alias du défaut sert Large 4 (cartes relues le 2026-11-17) : compté
        // au tarif de Large 4, sans date d'arrêt.
        $alias = LlmCatalog::pricing('mistral', 'mistral-large-latest');
        $this->assertSame([0.7, 2.1, 0.07], [$alias['input'], $alias['output'], $alias['cached_input']], 'Plus au tarif de Large 3.');
        $this->assertSame(1000000, LlmCatalog::contextWindow('mistral', 'mistral-large-latest'));
        $this->assertContains('raisonnement', LlmCatalog::model('mistral', 'mistral-large-latest')['tags']);

        try {
            LlmCatalog::setToday('2027-04-29');
            foreach (['mistral-large-2512', 'mistral-medium-latest', 'mistral-medium-3-5', 'mistral-medium-2604'] as $id) {
                $r = LlmCatalog::retirement('mistral', $id);
                $this->assertSame(['2027-04-30', 'mistral-large-4-0', false], [$r['date'], $r['replacement'], $r['retired']], $id);
            }
            LlmCatalog::setToday('2027-04-30');
            $this->assertTrue(LlmCatalog::isRetired('mistral', 'mistral-large-2512'));
            $this->assertNull(LlmCatalog::retirement('mistral', 'mistral-large-latest'), 'L\'alias ne vise plus Large 3.');
            $this->assertSame('mistral-large-latest', LlmCatalog::defaultModel('mistral'), 'Le défaut reste l\'alias, toujours servi.');
            $this->assertNull(LlmCatalog::retirement('mistral', 'mistral-small-2603'), 'Small 4 reste servi.');
        } finally {
            LlmCatalog::setToday(null);
        }
    }

    /**
     * Un défaut ARRÊTÉ cède la place à son remplaçant (LlmCatalog::defaultModel),
     * sinon les appels sans modèle choisi partiraient vers un identifiant coupé.
     * Plus aucun défaut réel ne porte de date d'arrêt : catalogue de substitution.
     */
    public function testUnDefautArreteCedeLaPlaceASonRemplacant(): void
    {
        $catalogue = require ROOT . '/config/llm_catalog.php';
        $catalogue['mistral']['models']['mistral-large-latest']['retirement'] = [
            'date' => '2027-04-30', 'replacement' => 'mistral-large-4-0', 'source_url' => 'https://docs.mistral.ai/models',
        ];
        $cache = new ReflectionProperty(LlmCatalog::class, 'cache');
        try {
            $cache->setValue(null, $catalogue);
            LlmCatalog::setToday('2027-04-29');
            $this->assertSame('mistral-large-latest', LlmCatalog::defaultModel('mistral'));
            LlmCatalog::setToday('2027-04-30');
            $this->assertSame('mistral-large-4-0', LlmCatalog::defaultModel('mistral'));
        } finally {
            LlmCatalog::setToday(null);
            LlmCatalog::flushCache();
        }
    }

    /**
     * Medium 3.5 : la documentation de Mistral ne connaît que
     * `mistral-medium-3-5` (avec `mistral-medium-3` et `-latest`). L'identifiant
     * daté retenu à l'ajout du modèle reste, pour les instances qui l'ont
     * activé, avec le même tarif — sinon leurs opérations deviendraient « non
     * tarifées ».
     */
    public function testMistralMedium35PorteSonIdentifiantOfficiel(): void
    {
        $officiel = LlmCatalog::model('mistral', 'mistral-medium-3-5');
        $this->assertNotNull($officiel, 'mistral-medium-3-5 absent du catalogue');
        $this->assertSame('Mistral Medium 3.5', $officiel['label']);
        $this->assertSame(256000, LlmCatalog::contextWindow('mistral', 'mistral-medium-3-5'));
        $this->assertSame(LlmCatalog::pricing('mistral', 'mistral-medium-3-5'), LlmCatalog::pricing('mistral', 'mistral-medium-2604'),
            'Même modèle, même tarif, même fiche (relue le 2026-11-17).');
        $this->assertStringContainsString('mistral-medium-3-5', LlmCatalog::model('mistral', 'mistral-medium-2604')['note']);
    }

    /**
     * Claude Opus 4.7, Opus 4.6, Sonnet 4.6 et Haiku 4.5 n'avaient aucun tarif
     * au catalogue : leurs opérations restaient « non tarifées ». Grille
     * d'Anthropic relue le 07/10/2026 ; Sonnet et Opus 4.6 ont une fenêtre de
     * 1M (le défaut du fournisseur, 200 000, coupait leur budget RAG).
     */
    public function testLesModelesClaudePrecedentsSontTarifes(): void
    {
        $attendu = [
            'claude-opus-4-7'           => [5.0, 25.0, 0.5, 6.25],
            'claude-opus-4-6'           => [5.0, 25.0, 0.5, 6.25],
            'claude-sonnet-4-6'         => [3.0, 15.0, 0.3, 3.75],
            'claude-haiku-4-5-20251001' => [1.0, 5.0, 0.1, 1.25],
        ];
        foreach ($attendu as $id => [$entree, $sortie, $lu, $ecrit]) {
            $prix = LlmCatalog::pricing('anthropic', $id);
            $this->assertNotNull($prix, "$id sans tarif");
            $this->assertSame([$entree, $sortie, $lu, $ecrit], [$prix['input'], $prix['output'], $prix['cached_input'], $prix['cache_write']], $id);
        }
        foreach (array_keys(LlmCatalog::models('anthropic')) as $id) {
            $this->assertNotNull(LlmCatalog::pricing('anthropic', (string)$id), "$id : chaque modèle Claude du catalogue porte son tarif");
        }
        $this->assertSame(1000000, LlmCatalog::contextWindow('anthropic', 'claude-sonnet-4-6'));
        $this->assertSame(1000000, LlmCatalog::contextWindow('anthropic', 'claude-opus-4-6'));
        $this->assertSame(200000, LlmCatalog::contextWindow('anthropic', 'claude-haiku-4-5-20251001'));
    }

    /**
     * Le curseur de MIA va de 0 à 2 ; Anthropic et GLM refusent au-delà de 1,
     * Mistral au-delà de 1,5 (400 à chaque message). La valeur envoyée est
     * bornée au catalogue, et rien ne part pour un modèle qui refuse le
     * paramètre ou un assistant qui n'en fixe pas.
     */
    /**
     * La Conversations API de Mistral (recherche Internet) borne
     * `completion_args.temperature` à 1,0 — Chat Completions accepte 1,5
     * (schémas CompletionArgs / ChatCompletionRequest, relus le 2026-11-10).
     */
    public function testLaRechercheMistralBorneLaTemperatureAUn(): void
    {
        $this->assertSame(1.5, LlmCatalog::temperatureFor('mistral', 'mistral-large-4-0', 1.8));
        $src = (string)file_get_contents(ROOT . '/src/LLM/MistralAdapter.php');
        $this->assertStringContainsString("private const CONVERSATIONS_TEMPERATURE_MAX = 1.0;", $src);
        $this->assertStringContainsString("['temperature' => min(self::CONVERSATIONS_TEMPERATURE_MAX, \$sendTemperature)]", $src);
    }

    public function testLaTemperatureEnvoyeeEstBorneeAuCatalogue(): void
    {
        $this->assertSame(1.0, LlmCatalog::temperatureFor('anthropic', 'claude-sonnet-4-6', 1.8));
        $this->assertSame(0.4, LlmCatalog::temperatureFor('anthropic', 'claude-sonnet-4-6', 0.4));
        $this->assertNull(LlmCatalog::temperatureFor('anthropic', 'claude-opus-5', 0.4), 'Claude 4.7+ refuse le paramètre');
        $this->assertSame(1.5, LlmCatalog::temperatureFor('mistral', 'mistral-large-4-0', 2.0));
        $this->assertSame(1.2, LlmCatalog::temperatureFor('mistral', 'mistral-large-latest', 1.2));
        $this->assertSame(1.0, LlmCatalog::temperatureFor('zai', 'glm-5.2', 1.6));
        $this->assertSame(1.9, LlmCatalog::temperatureFor('openai', 'gpt-4.1', 1.9), 'aucune borne déclarée : valeur intacte');
        $this->assertNull(LlmCatalog::temperatureFor('openai', 'gpt-5.6', 0.7));
        $this->assertNull(LlmCatalog::temperatureFor('mistral', 'mistral-large-latest', null));
        $this->assertSame(0.0, LlmCatalog::temperatureFor('mistral', 'mistral-large-latest', -0.5));

        // Les adaptateurs concernés passent par la règle commune, pas par une
        // borne recopiée.
        foreach (['MistralAdapter' => 3, 'AnthropicAdapter' => 1, 'ZaiAdapter' => 2] as $classe => $appels) {
            $source = (string)file_get_contents(ROOT . '/src/LLM/' . $classe . '.php');
            $this->assertSame($appels, substr_count($source, 'LlmCatalog::temperatureFor('), $classe);
            $this->assertStringNotContainsString('min($temperature', $source, $classe);
        }
    }

    /**
     * GPT-6.1 Sol et GPT-6 Luna (fiches OpenAI relues le 07/10/2026) : hors du
     * préfixe `gpt-5`, routage déclaré ; effort de raisonnement par défaut
     * `medium`, donc pas de `temperature`. La lecture de cache de 6.1 Sol vaut
     * 5 % de l'entrée — à déclarer. Le défaut ne bascule pas.
     */
    public function testGpt61SolEtGpt6Luna(): void
    {
        foreach (['gpt-6.1-sol' => 'GPT-6.1 Sol', 'gpt-6-luna' => 'GPT-6 Luna'] as $id => $label) {
            $info = LlmCatalog::model('openai', $id);
            $this->assertNotNull($info, "$id absent du catalogue");
            $this->assertSame($label, $info['label']);
            $this->assertSame('responses_api', LlmCatalog::routing('openai', $id), $id);
            $this->assertFalse(LlmCatalog::supportsTemperature('openai', $id), $id);
            $this->assertTrue(LlmCatalog::supportsToolUse('openai', $id), $id);
            $this->assertTrue(LlmCatalog::supportsWebSearch('openai', $id), $id);
            $this->assertSame(1050000, LlmCatalog::contextWindow('openai', $id), $id);
        }
        $sol = LlmCatalog::pricing('openai', 'gpt-6.1-sol');
        $this->assertSame([2.0, 10.0, 0.1, 2.5], [$sol['input'], $sol['output'], $sol['cached_input'], $sol['cache_write']]);
        $luna = LlmCatalog::pricing('openai', 'gpt-6-luna');
        $this->assertSame([0.1, 0.5, 0.01, 0.125], [$luna['input'], $luna['output'], $luna['cached_input'], $luna['cache_write']]);
        $this->assertSame('gpt-5.6', LlmCatalog::defaultModel('openai'));
    }

    /**
     * Grille OpenAI relue le 07/10/2026 : Terra (-20 %) et Luna (-80 %) depuis
     * le 30/07, Sol en promotion (4 $ / 20 $) annoncée « at least through
     * November 21, 2026 », prolongée « until November 30, 2026 » (relu le
     * 10/11/2026) — bornée à cette date, puis tarif GA. MIA facturait Luna
     * cinq fois son prix. Terra et Luna ont leur palier au-delà de 272k.
     */
    public function testLesTarifsGpt56SuiventLaGrille(): void
    {
        try {
            LlmCatalog::setToday('2026-11-30');
            foreach (['gpt-5.6', 'gpt-5.6-sol'] as $id) {
                $promo = LlmCatalog::pricing('openai', $id);
                $this->assertSame([4.0, 20.0, 0.4, 5.0], [$promo['input'], $promo['output'], $promo['cached_input'], $promo['cache_write']], $id);
            }
            LlmCatalog::setToday('2026-12-01');
            foreach (['gpt-5.6', 'gpt-5.6-sol'] as $id) {
                $ga = LlmCatalog::pricing('openai', $id);
                $this->assertSame([5.0, 30.0, 0.5], [$ga['input'], $ga['output'], $ga['cached_input']], $id);
                $this->assertArrayNotHasKey('cache_write', $ga, 'hors promotion : ratio conventionnel 1,25×');
            }
        } finally {
            LlmCatalog::setToday(null);
        }
        $terra = LlmCatalog::pricing('openai', 'gpt-5.6-terra');
        $this->assertSame([2.0, 12.0, 0.2], [$terra['input'], $terra['output'], $terra['cached_input']]);
        $luna = LlmCatalog::pricing('openai', 'gpt-5.6-luna');
        $this->assertSame([0.2, 1.2, 0.02], [$luna['input'], $luna['output'], $luna['cached_input']]);
        foreach (['gpt-5.6-terra', 'gpt-5.6-luna'] as $id) {
            $this->assertSame(['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], LlmCatalog::longContext('openai', $id), $id);
        }
    }

    /**
     * GPT-6.2 Astra et GPT-6.2 Sol (17/11/2026), fiches, page Pricing et guide
     * « Using GPT-6.2 » relus le 2026-11-17. Chat Completions accepté mais
     * « tool calling requires the Responses API » : routage déclaré, sinon les
     * connecteurs disparaîtraient en silence. `temperature` à omettre. Cache lu
     * à 5 % (le ratio conventionnel donnerait le double), palier > 272k.
     */
    public function testGpt62AstraEtSol(): void
    {
        foreach (['gpt-6.2-astra' => 'GPT-6.2 Astra', 'gpt-6.2-sol' => 'GPT-6.2 Sol'] as $id => $label) {
            $info = LlmCatalog::model('openai', $id);
            $this->assertNotNull($info, "$id absent du catalogue");
            $this->assertSame($label, $info['label']);
            $this->assertSame('responses_api', LlmCatalog::routing('openai', $id), "$id : hors préfixe gpt-5, routage déclaré.");
            $this->assertFalse(LlmCatalog::supportsTemperature('openai', $id), $id);
            $this->assertTrue(LlmCatalog::supportsToolUse('openai', $id), $id);
            $this->assertTrue(LlmCatalog::supportsWebSearch('openai', $id), $id);
            $this->assertTrue(LlmCatalog::supportsNativePptx('openai', $id), "$id : Code Interpreter pris en charge.");
            $this->assertTrue(LlmCatalog::acceptsImages('openai', $id), $id);
            $this->assertSame(1050000, LlmCatalog::contextWindow('openai', $id), $id);
            $this->assertSame(['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], LlmCatalog::longContext('openai', $id), $id);
            $this->assertNull(LlmCatalog::retirement('openai', $id), $id);
        }
        $astra = LlmCatalog::pricing('openai', 'gpt-6.2-astra');
        $this->assertSame([8.0, 40.0, 0.4, 10.0], [$astra['input'], $astra['output'], $astra['cached_input'], $astra['cache_write']]);
        $sol = LlmCatalog::pricing('openai', 'gpt-6.2-sol');
        $this->assertSame([2.0, 10.0, 0.1, 2.5], [$sol['input'], $sol['output'], $sol['cached_input'], $sol['cache_write']]);
        $this->assertSame('gpt-5.6', LlmCatalog::defaultModel('openai'), 'Un nouveau modèle ne devient pas le défaut en silence.');
        $this->assertSame('gpt-5.6', LlmCatalog::fallbackSearchModel('openai'));
    }

    /** GPT-6.1 Terra (26/10/2026), fiche relue le 2026-11-10. */
    public function testGpt61Terra(): void
    {
        $this->assertTrue(LlmCatalog::supportsToolUse('openai', 'gpt-6.1-terra'));
        $this->assertTrue(LlmCatalog::supportsWebSearch('openai', 'gpt-6.1-terra'));
        $this->assertFalse(LlmCatalog::supportsTemperature('openai', 'gpt-6.1-terra'));
        $this->assertTrue(LlmCatalog::supportsNativePptx('openai', 'gpt-6.1-terra'));
        $this->assertSame('responses_api', LlmCatalog::model('openai', 'gpt-6.1-terra')['routing'], 'Hors préfixe gpt-5 : routage déclaré, sinon Chat Completions sans outils.');
        $this->assertSame(1050000, LlmCatalog::contextWindow('openai', 'gpt-6.1-terra'));
        $terra = LlmCatalog::pricing('openai', 'gpt-6.1-terra');
        $this->assertSame([1.0, 5.0, 0.05, 1.25], [$terra['input'], $terra['output'], $terra['cached_input'], $terra['cache_write']],
            'Lecture de cache à 5 % : le ratio conventionnel donnerait 0,10 $.');
        $this->assertSame(['above' => 272000, 'input_ratio' => 2.0, 'output_ratio' => 1.5], LlmCatalog::longContext('openai', 'gpt-6.1-terra'));
        $this->assertSame('GPT-5.4 (26.03.05)', LlmCatalog::model('openai', 'gpt-5.4-2026-03-05')['label']);
        $this->assertSame('gpt-5.6', LlmCatalog::defaultModel('openai'), 'Un nouveau modèle ne devient pas le défaut en silence.');
    }

    /**
     * Identifiants qui ne répondent plus (ou n'ont jamais existé) chez leur
     * éditeur : ils quittent le catalogue, avec leur date en commentaire.
     */
    public function testLesModelesMortsOntQuitteLeCatalogue(): void
    {
        foreach (['gpt-5.3-chat-latest', 'gpt-4.5-preview', 'gpt-5.6-pro'] as $id) {
            $this->assertNull(LlmCatalog::model('openai', $id), "$id ne doit plus être proposé");
        }
        $source = (string)file_get_contents(ROOT . '/config/llm_catalog.php');
        foreach (['10/08/2026', '14/07/2025', 'reasoning.mode'] as $trace) {
            $this->assertStringContainsString($trace, $source);
        }
    }

    /**
     * Dictée : les deux modèles live, que la dictée essaie EN PREMIER, coûtent
     * 0,017 $ la minute ; absents de la grille du catalogue, ils étaient
     * comptés au défaut de 0,006 $.
     */
    public function testLaDicteeEstTarifeeAuPrixDeChaqueModele(): void
    {
        $grille = LlmCatalog::provider('openai')['transcription_pricing_usd_per_minute'];
        foreach (array_keys(ChatController::TRANSCRIPTION_MODELS) as $modele) {
            $this->assertArrayHasKey($modele, $grille, "$modele : chaque modèle de dictée a son tarif");
        }
        $this->assertSame(0.017, $grille['gpt-live-transcribe']);
        $this->assertSame(0.017, $grille['gpt-realtime-whisper']);
        $this->assertSame(0.0045, $grille['gpt-transcribe']);
        $this->assertSame(max($grille), $grille['default'], 'un modèle inconnu n\'est jamais sous-facturé');
    }

    /**
     * « Mettre à jour les prix » enregistre le tarif NORMAL : un tarif
     * enregistré prime sur le catalogue et ne suit aucune date — y figer une
     * remise de lancement (Gemini 4 Argon, promotion GPT-5.6, Gemini 3.x)
     * ferait sous-facturer en silence dès sa fin. La remise en cours est
     * seulement signalée dans la note.
     */
    public function testMettreAJourLesPrixEnregistreLeTarifNormal(): void
    {
        require_once ROOT . '/src/Controllers/AdminController.php';
        $resoudre = new ReflectionMethod(AdminController::class, 'resolveCuratedUsdPrice');
        $admin = (new ReflectionClass(AdminController::class))->newInstanceWithoutConstructor();
        try {
            LlmCatalog::setToday('2026-11-10');
            $prix = $resoudre->invoke($admin, ['id' => 's1', 'catalog_key' => 'llm:gemini::gemini-4-argon:input']);
            $this->assertSame(4.0, $prix['real_cost_usd'], 'tarif normal, pas la remise de lancement (2 $)');
            $this->assertStringContainsString('2026-11-30', $prix['source_note']);

            $lu = $resoudre->invoke($admin, ['id' => 's2', 'catalog_key' => 'llm:openai::gpt-5.6-sol:input_cached']);
            $this->assertSame(0.5, $lu['real_cost_usd']);

            LlmCatalog::setToday('2026-12-01');
            $apres = $resoudre->invoke($admin, ['id' => 's1', 'catalog_key' => 'llm:gemini::gemini-4-argon:input']);
            $this->assertSame(4.0, $apres['real_cost_usd']);
            $this->assertStringNotContainsString('remise de lancement', $apres['source_note']);
        } finally {
            LlmCatalog::setToday(null);
        }
        $this->assertNull(LlmCatalog::standardPricing('mistral', 'modele-inconnu'));
        $this->assertArrayNotHasKey('launch', LlmCatalog::standardPricing('gemini', 'gemini-3.8-flash'));
    }

    /**
     * Le picker du chat classe les modèles dans l'ordre du catalogue, tenu du
     * plus récent au plus ancien : les nouveaux venus ouvrent leur fournisseur.
     */
    public function testLesNouveauxModelesOuvrentLeurFournisseur(): void
    {
        // Génération courante dans l'ordre du tableau d'Anthropic (Fable 5.2,
        // Opus 5.5, Sonnet 5.5, Haiku 5.5), puis Fable 5.1.
        $this->assertSame(
            ['claude-fable-5-2', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-1'],
            array_slice(array_keys(LlmCatalog::models('anthropic')), 0, 5)
        );
        $this->assertSame(['gpt-6.2-astra', 'gpt-6.2-sol', 'gpt-6.1-sol', 'gpt-6.1-terra', 'gpt-6-luna', 'gpt-6-astra'], array_slice(array_keys(LlmCatalog::models('openai')), 0, 6));
        $this->assertSame('gemini-4-neon', array_key_first(LlmCatalog::models('gemini')));
    }

    /** Perplexity est web_native de bout en bout : il ne doit pas être oublié. */
    public function testPerplexitySaitChercher(): void
    {
        foreach (array_keys(LlmCatalog::models('perplexity')) as $model) {
            $this->assertTrue(LlmCatalog::supportsWebSearch('perplexity', (string) $model));
        }
    }

    /** Les providers sans recherche ni repli restent honnêtement à false. */
    public function testProvidersSansRechercheWeb(): void
    {
        foreach (['moonshot', 'zai'] as $provider) {
            foreach (array_keys(LlmCatalog::models($provider)) as $model) {
                $this->assertFalse(LlmCatalog::supportsWebSearch($provider, (string) $model), $provider);
            }
        }
    }

    /**
     * Slug réduit au provider (le parseur renvoie alors le provider comme
     * modèle) ou modèle absent du catalogue : on se prononce sur le modèle par
     * défaut, plutôt que de désactiver la recherche sans raison visible.
     */
    public function testModeleInconnuRetombeSurLeDefaut(): void
    {
        foreach (['openai', 'anthropic', 'mistral', 'perplexity', 'gemini', 'deepseek'] as $provider) {
            $this->assertTrue(LlmCatalog::supportsWebSearch($provider, $provider), $provider);
            $this->assertTrue(LlmCatalog::supportsWebSearch($provider, 'modele-absent-du-catalogue'), $provider);
        }
        $this->assertFalse(LlmCatalog::supportsWebSearch('zai', 'zai'));
    }
}
