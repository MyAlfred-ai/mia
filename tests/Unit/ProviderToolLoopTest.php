<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Function-calling pour les sept fournisseurs qui n'en avaient pas.
 *
 * Avant : seuls Anthropic et OpenAI pilotaient des outils ; avec DeepSeek,
 * Kimi, GLM, Mistral, Gemini ou un modèle local, aucun connecteur, aucun outil
 * d'assistant — l'interface les déclarait « incompatibles ». Ce fichier rejoue
 * le VRAI code des boucles (ChatCompletionsToolLoopTrait, boucle native de
 * GeminiAdapter) contre un faux serveur qui sert une réponse par aller et
 * garde chaque requête : on vérifie ce qui part sur le fil, pas l'intention.
 * Un arrêt demandé (Stop) n'exécute plus aucun outil : le contrôle a lieu
 * avant chaque appel, et non plus après le tour.
 */
final class ProviderToolLoopTest extends TestCase
{
    private ?string $dossier = null;
    private string|false $journalAvant = false;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/mia-outils-' . bin2hex(random_bytes(6));
        mkdir($this->dossier);
        $this->journalAvant = ini_get('error_log');
        ini_set('error_log', $this->dossier . '/journal.log');
        foreach ([DeepSeekAdapter::class, MoonshotAdapter::class, ZaiAdapter::class, MistralAdapter::class, LocalAdapter::class] as $classe) {
            (new ReflectionProperty($classe, 'toolChoiceNoneRejected'))->setValue(null, []);
        }
        (new ReflectionProperty(GeminiAdapter::class, 'searchWithToolsRejected'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        LlmAdapterHelpers::setStopProbe(null);
        ini_set('error_log', $this->journalAvant === false ? '' : $this->journalAvant);
        LlmCatalog::flushCache();
        $cache  = new ReflectionProperty(Settings::class, 'cache');
        $loaded = new ReflectionProperty(Settings::class, 'loaded');
        $cache->setValue(null, []);
        $loaded->setValue(null, false);
        if ($this->dossier !== null && is_dir($this->dossier)) {
            foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }
    }

    // ------------------------------------------------------------------
    // 1. Catalogue : qui pilote des outils, et avec quel dialecte
    // ------------------------------------------------------------------

    public function testLeCatalogueDitQuiPiloteDesOutils(): void
    {
        foreach ([
            ['deepseek', 'deepseek-flash', true],
            ['moonshot', 'kimi-k3', true],
            ['zai', 'glm-5.2', true],
            ['mistral', 'mistral-large-latest', true],
            ['mistral', 'codestral-latest', true],
            ['gemini', 'gemini-3-flash-preview', true],
            ['anthropic', 'claude-opus-5', true],
            ['openai', 'gpt-5.6', true],
            // Recherche Google et outils ne se cumulent pas en 2.5 : la
            // recherche, cochée par défaut, aurait été éteinte.
            ['gemini', 'gemini-2.5-flash', false],
            ['gemini', 'gemini-2.5-pro', false],
            // L'API Sonar n'a pas de function-calling.
            ['perplexity', 'sonar-pro', false],
        ] as [$fournisseur, $modele, $attendu]) {
            $this->assertSame($attendu, LlmCatalog::supportsToolUse($fournisseur, $modele), "$fournisseur/$modele");
        }
        // Modèle inconnu : jugé sur le modèle par défaut du fournisseur.
        $this->assertTrue(LlmCatalog::supportsToolUse('deepseek', 'deepseek-inconnu'));
    }

    public function testRechercheInternetEtOutilsNeSeCumulentPasPartout(): void
    {
        $this->assertTrue(LlmCatalog::supportsWebSearchWithTools('anthropic', 'claude-opus-5'));
        $this->assertTrue(LlmCatalog::supportsWebSearchWithTools('openai', 'gpt-5.6'));
        $this->assertTrue(LlmCatalog::supportsWebSearchWithTools('gemini', 'gemini-3.8-flash'));
        // Mistral : recherche par la Conversations API, outils par Chat Completions.
        $this->assertFalse(LlmCatalog::supportsWebSearchWithTools('mistral', 'mistral-large-latest'));
        // Sans recherche native, la question ne se pose pas.
        $this->assertFalse(LlmCatalog::supportsWebSearchWithTools('deepseek', 'deepseek-flash'));
    }

    public function testLeDialecteDeChaqueFournisseurEstDeclare(): void
    {
        $this->assertFalse(LlmCatalog::toolCallingDialect('zai')['tool_choice_none'], 'GLM : tool_choice « only supports auto »');
        $this->assertTrue(LlmCatalog::toolCallingDialect('mistral')['tool_message_name'], 'Mistral exige le nom');
        $this->assertTrue(LlmCatalog::toolCallingDialect('moonshot')['tool_message_name']);
        $this->assertTrue(LlmCatalog::toolCallingDialect('moonshot')['reasoning_echo'], 'Kimi exige son raisonnement');
        // Défauts du format OpenAI.
        $this->assertSame(
            ['tool_choice_none' => true, 'tool_message_name' => false, 'reasoning_echo' => true],
            LlmCatalog::toolCallingDialect('local')
        );
    }

    public function testLeLlmLocalDependDeLaCaseDeLAdministrateur(): void
    {
        $this->assertFalse(LlmCatalog::supportsToolUse('local', 'llama3'), 'décochée par défaut');
        $this->reglages([LocalAdapter::SETTING_TOOL_USE => '1']);
        $this->assertTrue(LlmCatalog::supportsToolUse('local', 'llama3'));
    }

    // ------------------------------------------------------------------
    // 2. Boucle Chat Completions (DeepSeek, Kimi, GLM, Mistral, local)
    // ------------------------------------------------------------------

    public function testUnAppelDOutilEstExecuteEtSonResultatRenduAuModele(): void
    {
        $adapter = $this->chat(DeepSeekToolDouble::class, 'deepseek-flash', [
            [200, self::sse([
                ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'chercher', 'arguments' => '{"q":']]]]]]],
                ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"budget"}']]]], 'finish_reason' => 'tool_calls']]],
                ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'prompt_cache_hit_tokens' => 40]],
            ])],
            [200, self::sse([
                ['choices' => [['delta' => ['content' => 'Voici le budget.'], 'finish_reason' => 'stop']]],
                ['usage' => ['prompt_tokens' => 150, 'completion_tokens' => 5]],
            ])],
        ]);
        $appels = [];
        [$texte, $jetons] = $this->jouer($adapter, function (string $nom, array $args) use (&$appels): array {
            $appels[] = [$nom, $args];
            return ['fichiers' => [['nom' => 'Budget 2026']]];
        });

        $this->assertSame('Voici le budget.', $texte);
        $this->assertSame([['chercher', ['q' => 'budget']]], $appels, 'arguments réassemblés depuis leurs fragments');
        $this->assertSame(250, $jetons['input'], 'usage sommé sur les allers');
        $this->assertSame(15, $jetons['output']);
        $this->assertSame(40, $jetons['input_cached'], 'cache DeepSeek (prompt_cache_hit_tokens)');

        $second = $adapter->bodies[1]['messages'];
        $tour = $second[count($second) - 2];
        $this->assertSame('assistant', $tour['role']);
        $this->assertSame('call_a', $tour['tool_calls'][0]['id']);
        $this->assertSame('{"q":"budget"}', $tour['tool_calls'][0]['function']['arguments']);
        $resultat = $second[count($second) - 1];
        $this->assertSame('tool', $resultat['role']);
        $this->assertSame('call_a', $resultat['tool_call_id']);
        $this->assertArrayNotHasKey('name', $resultat, 'DeepSeek : pas de nom sur le résultat');
        $this->assertSame(['fichiers' => [['nom' => 'Budget 2026']]], json_decode($resultat['content'], true));
        $this->assertSame('chercher', $adapter->bodies[0]['tools'][0]['function']['name']);
        $this->assertArrayNotHasKey('tool_choice', $adapter->bodies[0], 'auto par défaut, rien à envoyer');
    }

    public function testKimiRetrouveSonRaisonnementEtLeNomDeLaFonction(): void
    {
        $adapter = $this->chat(MoonshotToolDouble::class, 'kimi-k3', [
            [200, self::sse([
                ['choices' => [['delta' => ['reasoning_content' => 'Je dois chercher. ']]]],
                ['choices' => [['delta' => ['reasoning_content' => 'Puis lire.', 'tool_calls' => [['index' => 0, 'id' => 'k1', 'function' => ['name' => 'chercher', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]],
            ])],
            [200, self::sse([['choices' => [['delta' => ['content' => 'Fini.'], 'finish_reason' => 'stop']]]])],
        ]);
        $this->jouer($adapter, static fn(): array => ['ok' => true]);

        $second = $adapter->bodies[1]['messages'];
        $tour = $second[count($second) - 2];
        $this->assertSame('Je dois chercher. Puis lire.', $tour['reasoning_content'],
            '« thinking is enabled but reasoning_content is missing in assistant tool call message » sinon');
        $this->assertSame('chercher', $second[count($second) - 1]['name']);
        $this->assertArrayNotHasKey('temperature', $adapter->bodies[0], 'Kimi K3 : jamais de temperature');
    }

    public function testMistralNommeChaqueResultatEtAccepteDesAppelsSansIndex(): void
    {
        $adapter = $this->chat(MistralToolDouble::class, 'mistral-large-latest', [
            // Deux appels ENTIERS dans un même fragment, sans `index`.
            [200, self::sse([
                ['choices' => [['delta' => ['content' => '', 'tool_calls' => [
                    ['id' => 'AbCdEfGh1', 'function' => ['name' => 'chercher', 'arguments' => '{"q":"a"}']],
                    ['id' => 'AbCdEfGh2', 'function' => ['name' => 'lire', 'arguments' => '{"id":"b"}']],
                ]], 'finish_reason' => 'tool_calls']]],
            ])],
            [200, self::sse([['choices' => [['delta' => ['content' => 'Résumé.'], 'finish_reason' => 'stop']]]])],
        ]);
        $appels = [];
        $this->jouer($adapter, function (string $nom, array $args) use (&$appels): array {
            $appels[] = $nom;
            return ['ok' => $nom];
        });

        $this->assertSame(['chercher', 'lire'], $appels);
        $second = $adapter->bodies[1]['messages'];
        $this->assertSame('lire', $second[count($second) - 1]['name'], 'Mistral : name requis sur chaque message tool');
        $this->assertSame('AbCdEfGh2', $second[count($second) - 1]['tool_call_id']);
        $this->assertArrayNotHasKey('reasoning_content', $second[count($second) - 3], 'rien à rendre, rien d\'ajouté');
    }

    public function testLeDernierAllerInterditLesOutils(): void
    {
        $adapter = $this->chat(DeepSeekToolDouble::class, 'deepseek-flash', [
            [200, self::sse([['choices' => [['delta' => ['content' => 'Synthèse.'], 'finish_reason' => 'stop']]]])],
        ]);
        [, $jetons] = $this->jouer($adapter, static fn(): array => [], ['max_rounds' => fn(): int => 1]);

        $this->assertSame('none', $adapter->bodies[0]['tool_choice']);
        $this->assertTrue($jetons['rounds_exhausted'] ?? false);
    }

    /**
     * DeepSeek V4 réfléchit PAR DÉFAUT : sans `thinking: disabled`, la boucle
     * paierait un raisonnement qu'elle ne rejoue pas (400 dès le second tour)
     * et `temperature` serait ignorée. Et sans `max_tokens`, l'API borne la
     * sortie à 8K. Les deux partent à CHAQUE aller, dernier compris.
     */
    public function testDeepSeekCoupeLaReflexionEtPoseSonPlafondAChaqueAller(): void
    {
        $adapter = $this->chat(DeepSeekToolDouble::class, 'deepseek-flash', [
            [200, self::sse([['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c', 'function' => ['name' => 'chercher', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]]])],
            [200, self::sse([['choices' => [['delta' => ['content' => 'Ok.'], 'finish_reason' => 'stop']]]])],
        ]);
        $this->jouer($adapter, static fn(): array => ['ok' => true], ['max_rounds' => fn(): int => 2]);

        $this->assertCount(2, $adapter->bodies);
        foreach ($adapter->bodies as $i => $corps) {
            $this->assertSame(['type' => 'disabled'], $corps['thinking'], "aller $i");
            $this->assertSame(65536, $corps['max_tokens'], "aller $i");
            $this->assertSame(0.7, $corps['temperature'], "aller $i : temperature honorée hors réflexion");
        }
        $this->assertSame('none', $adapter->bodies[1]['tool_choice']);
        // Les autres fournisseurs ne reçoivent rien de plus.
        $kimi = $this->chat(MoonshotToolDouble::class, 'kimi-k3', [
            [200, self::sse([['choices' => [['delta' => ['content' => 'Ok.'], 'finish_reason' => 'stop']]]])],
        ]);
        $this->jouer($kimi, static fn(): array => [], ['max_rounds' => fn(): int => 1]);
        $this->assertArrayNotHasKey('thinking', $kimi->bodies[0]);
        $this->assertArrayNotHasKey('max_tokens', $kimi->bodies[0]);
    }

    public function testGlmSynthetiseParUneConsigneFauteDeToolChoiceNone(): void
    {
        $adapter = $this->chat(ZaiToolDouble::class, 'glm-5.2', [
            [200, self::sse([['choices' => [['delta' => ['content' => 'Synthèse.'], 'finish_reason' => 'stop']]]])],
        ]);
        $this->jouer($adapter, static fn(): array => [], ['max_rounds' => fn(): int => 1]);

        $corps = $adapter->bodies[0];
        $this->assertArrayNotHasKey('tool_choice', $corps, 'GLM : « only supports auto »');
        $dernier = $corps['messages'][count($corps['messages']) - 1];
        $this->assertSame('system', $dernier['role']);
        $this->assertSame(LlmAdapterHelpers::finalRoundNote(), $dernier['content']);
    }

    public function testUnToolChoiceRefuseEstAppris(): void
    {
        $adapter = $this->chat(LocalToolDouble::class, 'llama3', [
            [400, '{"error":{"message":"tool_choice none is not supported"}}'],
            [200, self::sse([['choices' => [['delta' => ['content' => 'Ok.'], 'finish_reason' => 'stop']]]])],
            [200, self::sse([['choices' => [['delta' => ['content' => 'Ok.'], 'finish_reason' => 'stop']]]])],
        ]);
        $this->reglages([LocalAdapter::SETTING_TOOL_USE => '1']);
        (new ReflectionProperty(LocalAdapter::class, 'apiUrl'))->setValue($adapter, 'http://serveur-local/v1/chat/completions');

        [$texte] = $this->jouer($adapter, static fn(): array => [], ['max_rounds' => fn(): int => 1]);
        $this->assertSame('Ok.', $texte, 'la même demande repart, sans tool_choice, sans erreur affichée');
        $this->assertSame('none', $adapter->bodies[0]['tool_choice']);
        $this->assertArrayNotHasKey('tool_choice', $adapter->bodies[1]);

        // Appris pour le process : plus de 400 au message suivant.
        $this->jouer($adapter, static fn(): array => [], ['max_rounds' => fn(): int => 1]);
        $this->assertArrayNotHasKey('tool_choice', $adapter->bodies[2]);
        $this->assertCount(3, $adapter->bodies);
    }

    public function testLesOutilsSontRelusAChaqueAller(): void
    {
        $adapter = $this->chat(DeepSeekToolDouble::class, 'deepseek-flash', [
            [200, self::sse([['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c', 'function' => ['name' => 'activer', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]]])],
            [200, self::sse([['choices' => [['delta' => ['content' => 'Ok.'], 'finish_reason' => 'stop']]]])],
        ]);
        $outils = [['name' => 'activer', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]];
        $this->jouer($adapter, function () use (&$outils): array {
            $outils[] = ['name' => 'lire', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]];
            return ['actif' => true];
        }, ['tools_source' => function () use (&$outils): array { return $outils; }]);

        $this->assertSame(['activer'], array_column(array_column($adapter->bodies[0]['tools'], 'function'), 'name'));
        $this->assertSame(['activer', 'lire'], array_column(array_column($adapter->bodies[1]['tools'], 'function'), 'name'));
    }

    public function testMistralDitQuIlNePeutPasChercherEnLigneAvecSesOutils(): void
    {
        $adapter = $this->chat(MistralToolDouble::class, 'mistral-large-latest', [
            [200, self::sse([['choices' => [['delta' => ['content' => 'Ok.'], 'finish_reason' => 'stop']]]])],
        ]);
        $this->jouer($adapter, static fn(): array => [], [], true);

        $systeme = $adapter->bodies[0]['messages'][0];
        $this->assertSame('system', $systeme['role']);
        $this->assertStringContainsString(LlmAdapterHelpers::webSearchWithToolsUnavailableNote(), $systeme['content']);
    }

    public function testUnePanneDuFournisseurArreteLaBoucleAvecUneErreurNommee(): void
    {
        $adapter = $this->chat(ZaiToolDouble::class, 'glm-5.2', [
            [503, '{"error":{"code":"1305","message":"Service overloaded"}}'],
        ]);
        [$texte] = $this->jouer($adapter, static fn(): array => []);

        $this->assertStringStartsWith('[Erreur GLM', $texte);
        $this->assertSame('overloaded', LlmErrorPresenter::parse($texte)['kind']);
        $this->assertCount(1, $adapter->bodies);
    }

    /**
     * Arrêt demandé (Stop) dans un aller qui appelle deux outils, sans texte —
     * donc sans contrôle dans le flux : soit pendant que les appels arrivent,
     * soit pendant l'exécution du premier. Le contrôle de la boucle venait
     * APRÈS l'exécution du tour ; il a lieu désormais avant CHAQUE appel.
     *
     * @return array<string, array{bool, list<string>}> arrêt dès l'arrivée des appels, outils exécutés
     */
    public static function arretsDemandes(): array
    {
        return [
            'Stop pendant l’arrivée des appels' => [true, []],
            'Stop pendant le premier outil'     => [false, ['chercher']],
        ];
    }

    #[DataProvider('arretsDemandes')]
    public function testUnArretDemandeNExecutePlusAucunOutil(bool $arretDesLeDebut, array $attendus): void
    {
        $adapter = $this->chat(DeepSeekToolDouble::class, 'deepseek-chat', [
            [200, self::sse([
                ['choices' => [['delta' => ['tool_calls' => [
                    ['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'chercher', 'arguments' => '{"q":"budget"}']],
                    ['index' => 1, 'id' => 'call_b', 'function' => ['name' => 'lire', 'arguments' => '{"id":"d1"}']],
                ]], 'finish_reason' => 'tool_calls']]],
            ])],
            [200, self::sse([['choices' => [['delta' => ['content' => 'Jamais.'], 'finish_reason' => 'stop']]]])],
        ]);
        [$appels, $texte] = $this->jouerAvecArret($adapter, $arretDesLeDebut);

        $this->assertSame($attendus, $appels, 'Ni lecture ni carte d’approbation sur une réponse coupée.');
        $this->assertCount(1, $adapter->bodies, 'Rien ne repart.');
        $this->assertSame('', $texte, 'Un arrêt n’est pas une erreur.');
    }

    public function testLesFormesPuresDeLaBoucle(): void
    {
        // Emplacement d'un fragment : index, sinon identifiant, sinon dernier.
        $this->assertSame(2, DeepSeekAdapter::toolCallSlot(['index' => 2], [], -1));
        $this->assertSame(0, DeepSeekAdapter::toolCallSlot(['id' => 'x'], [], -1));
        $appels = [0 => ['id' => 'x', 'name' => 'a', 'arguments' => '']];
        $this->assertSame(1, DeepSeekAdapter::toolCallSlot(['id' => 'y'], $appels, 0));
        $this->assertSame(0, DeepSeekAdapter::toolCallSlot(['id' => 'x'], $appels, 0));
        $this->assertSame(0, DeepSeekAdapter::toolCallSlot(['function' => ['arguments' => '}']], $appels, 0));

        // Appels sans nom écartés, identifiant de secours.
        $final = DeepSeekAdapter::finalizeToolCalls([1 => ['id' => '', 'name' => 'b', 'arguments' => '{}'], 0 => ['id' => 'a', 'name' => ' ', 'arguments' => '']]);
        $this->assertCount(1, $final);
        $this->assertSame('b', $final[0]['name']);
        $this->assertNotSame('', $final[0]['id']);

        // Texte d'un delta : chaîne, ou morceaux typés (raisonnement exclu).
        $this->assertSame('ab', DeepSeekAdapter::deltaText([['type' => 'thinking', 'thinking' => 'x'], ['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']]));
        $this->assertSame('', DeepSeekAdapter::deltaText(null));

        // Usage : toutes les formes de cache et de raisonnement.
        $this->assertSame(7, DeepSeekAdapter::chatUsageTokens(['cached_tokens' => 7])['input_cached']);
        $this->assertSame(9, DeepSeekAdapter::chatUsageTokens(['prompt_tokens_details' => ['cached_tokens' => 9]])['input_cached']);
        $this->assertSame(3, DeepSeekAdapter::chatUsageTokens(['completion_tokens_details' => ['reasoning_tokens' => 3]])['output_reasoning']);

        $this->assertTrue(DeepSeekAdapter::isToolChoiceRejection(400, 'Invalid tool_choice'));
        $this->assertFalse(DeepSeekAdapter::isToolChoiceRejection(500, 'tool_choice'));
        $this->assertFalse(DeepSeekAdapter::isToolChoiceRejection(400, 'context length exceeded'));
    }

    // ------------------------------------------------------------------
    // 3. Gemini
    // ------------------------------------------------------------------

    public function testLeSchemaEstRameneAuSousEnsembleAcceptePerGemini(): void
    {
        $schema = GeminiAdapter::geminiSchema([
            'type'                 => 'object',
            '$schema'              => 'http://json-schema.org/draft-07/schema#',
            'additionalProperties' => false,
            'properties'           => [
                'requete' => ['type' => 'string', 'description' => 'q', 'default' => 'x'],
                'format'  => ['type' => 'string', 'enum' => ['euro', 'pourcent']],
                'nombre'  => ['type' => ['integer', 'null'], 'minimum' => 1],
                'donnees' => ['type' => 'object', 'properties' => new stdClass()],
                'valeurs' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
                'drapeau' => ['type' => 'boolean', 'enum' => [true]],
            ],
            'required' => ['requete', 'absent'],
        ], true);

        $this->assertSame('object', $schema['type']);
        $this->assertArrayNotHasKey('additionalProperties', $schema);
        $this->assertArrayNotHasKey('$schema', $schema);
        $this->assertSame(['requete'], $schema['required'], 'une propriété inconnue ne peut pas être requise');
        $this->assertArrayNotHasKey('default', $schema['properties']['requete']);
        $this->assertSame(['euro', 'pourcent'], $schema['properties']['format']['enum'],
            'une PROPRIÉTÉ nommée « format » reste une propriété');
        $this->assertSame(['type' => 'integer', 'nullable' => true, 'minimum' => 1], $schema['properties']['nombre']);
        $this->assertSame('string', $schema['properties']['donnees']['type'], 'objet libre imbriqué → texte JSON');
        $this->assertSame('string', $schema['properties']['valeurs']['items']['items']['type']);
        $this->assertArrayNotHasKey('enum', $schema['properties']['drapeau'], 'énumération non textuelle refusée par Gemini');

        // Outil sans paramètre : aucune déclaration de paramètres.
        $decl = GeminiAdapter::functionDeclarations([['name' => 'lister', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]]);
        $this->assertSame([['name' => 'lister', 'description' => 'd']], $decl);
    }

    public function testLeTourDuModeleEstRenduTelQuelAvecSaSignature(): void
    {
        $adapter = $this->gemini('gemini-3-flash-preview', [
            [200, self::sse([
                ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Je cherche']]]]]],
                ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => '…']]]]]],
                ['candidates' => [['content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'chercher', 'args' => ['q' => 'budget'], 'id' => 'fc1'], 'thoughtSignature' => 'SIG-A'],
                    ['functionCall' => ['name' => 'lister', 'args' => [], 'id' => 'fc2']],
                ]], 'finishReason' => 'STOP']],
                 'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 8, 'thoughtsTokenCount' => 4]],
            ])],
            [200, self::sse([
                ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Voici.']]], 'finishReason' => 'STOP']],
                 'usageMetadata' => ['promptTokenCount' => 90, 'candidatesTokenCount' => 3]],
            ])],
        ]);
        $appels = [];
        [$texte, $jetons] = $this->jouer($adapter, function (string $nom, array $args) use (&$appels): array {
            $appels[] = [$nom, $args];
            return $nom === 'lister' ? ['a', 'b'] : ['trouve' => 1];
        });

        $this->assertSame('Je cherche…Voici.', $texte);
        $this->assertSame([['chercher', ['q' => 'budget']], ['lister', []]], $appels);
        $this->assertSame(140, $jetons['input']);
        $this->assertSame(15, $jetons['output'], 'la réflexion est une partie de la sortie');

        $contenus = $adapter->bodies[1]['contents'];
        $modele = $contenus[count($contenus) - 2];
        $this->assertSame('model', $modele['role']);
        $this->assertSame(['text' => 'Je cherche…'], $modele['parts'][0], 'fragments de texte fusionnés');
        $this->assertSame('SIG-A', $modele['parts'][1]['thoughtSignature'], 'signature rendue « in the exact part where it was received »');
        $this->assertSame('fc2', $modele['parts'][2]['functionCall']['id']);

        $reponses = $contenus[count($contenus) - 1];
        $this->assertSame('user', $reponses['role']);
        $this->assertSame(['name' => 'chercher', 'response' => ['trouve' => 1], 'id' => 'fc1'], $reponses['parts'][0]['functionResponse']);
        $this->assertSame(['result' => ['a', 'b']], $reponses['parts'][1]['functionResponse']['response'],
            'une liste n\'est pas un objet JSON : enveloppée');
    }

    public function testGemini3CumuleRechercheGoogleEtOutils(): void
    {
        $adapter = $this->gemini('gemini-3.8-flash', [
            [200, self::sse([
                ['candidates' => [['content' => ['parts' => [['text' => 'Ok.']]], 'finishReason' => 'STOP',
                    'groundingMetadata' => ['webSearchQueries' => ['météo Paris', 'météo Paris']]]]],
            ])],
        ]);
        [, $jetons] = $this->jouer($adapter, static fn(): array => [], ['max_rounds' => fn(): int => 1], true);

        $corps = $adapter->bodies[0];
        $this->assertCount(1, $corps['tools'], 'un seul objet outils, comme dans l\'exemple de Google');
        $this->assertArrayHasKey('googleSearch', $corps['tools'][0]);
        $this->assertArrayHasKey('functionDeclarations', $corps['tools'][0]);
        $this->assertTrue($corps['toolConfig']['includeServerSideToolInvocations']);
        $this->assertSame('NONE', $corps['toolConfig']['functionCallingConfig']['mode'], 'dernier aller : synthèse forcée');
        $this->assertSame(1, $jetons['web_searches'], 'requêtes dédoublonnées');
    }

    /**
     * Gemini 3 facture CHAQUE requête Google de CHAQUE requête au modèle : la
     * même recherche relancée à l'aller suivant compte deux fois (elle était
     * dédoublonnée sur toute la boucle). Chaque aller est aussi rapporté
     * (`rounds`) pour son palier de contexte.
     */
    public function testLesRecherchesGeminiSontCompteesAllerParAller(): void
    {
        $recherche = ['webSearchQueries' => ['météo Paris']];
        $adapter = $this->gemini('gemini-3.8-flash', [
            [200, self::sse([
                ['candidates' => [['content' => ['parts' => [['functionCall' => ['id' => 'fc1', 'name' => 'chercher', 'args' => ['q' => 'x']]]]],
                    'finishReason' => 'STOP', 'groundingMetadata' => $recherche]],
                 'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 30]],
            ])],
            [200, self::sse([
                ['candidates' => [['content' => ['parts' => [['text' => 'Il fera beau.']]], 'finishReason' => 'STOP', 'groundingMetadata' => $recherche]],
                 'usageMetadata' => ['promptTokenCount' => 1500, 'candidatesTokenCount' => 40]],
            ])],
        ]);
        [, $jetons] = $this->jouer($adapter, static fn(): array => ['ok' => true], [], true);

        $this->assertSame(2, $jetons['web_searches'], 'une requête Google par aller');
        $this->assertSame(2700, $jetons['input']);
        $this->assertSame([1200, 1500], array_column($jetons['rounds'], 'input'), 'usage de chaque aller');
    }

    /** Kimi ne rapporte l'usage d'un flux que sur demande : la boucle le demande. */
    public function testLaBoucleDeKimiDemandeLUsageDuFlux(): void
    {
        $adapter = (new ReflectionClass(MoonshotToolDouble::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(MoonshotAdapter::class, 'apiKey'))->setValue($adapter, 'cle-factice');
        (new ReflectionProperty(MoonshotAdapter::class, 'model'))->setValue($adapter, 'kimi-k3');
        $adapter->responses = [[200, self::sse([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Bonjour.'], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 4, 'cached_tokens' => 20]],
        ]) . "data: [DONE]\n\n"]];
        $adapter->dir = (string)$this->dossier;
        [, $jetons] = $this->jouer($adapter, static fn(): array => []);

        $this->assertTrue($adapter->bodies[0]['stream_options']['include_usage'] ?? false);
        $this->assertSame(80, $jetons['input']);
        $this->assertSame(20, $jetons['input_cached']);
    }

    public function testUneRechercheRefuseeAvecLesOutilsEstApprise(): void
    {
        $adapter = $this->gemini('gemini-3.8-flash', [
            [400, '{"error":{"code":400,"message":"Tool use with googleSearch is unsupported here","status":"INVALID_ARGUMENT"}}'],
            [200, self::sse([['candidates' => [['content' => ['parts' => [['text' => 'Ok.']]], 'finishReason' => 'STOP']]]])],
        ]);
        [$texte] = $this->jouer($adapter, static fn(): array => [], [], true);

        $this->assertSame('Ok.', $texte, 'rien d\'affiché : la même demande repart sans recherche');
        $this->assertArrayNotHasKey('googleSearch', $adapter->bodies[1]['tools'][0]);
        $this->assertStringContainsString(
            LlmAdapterHelpers::webSearchWithToolsUnavailableNote(),
            $adapter->bodies[1]['systemInstruction']['parts'][0]['text']
        );
        $this->assertTrue((new ReflectionProperty(GeminiAdapter::class, 'searchWithToolsRejected'))->getValue());
    }

    public function testUnAppelMalFormeAUneSecondeChance(): void
    {
        $adapter = $this->gemini('gemini-3-flash-preview', [
            [200, self::sse([['candidates' => [['content' => ['parts' => []], 'finishReason' => 'MALFORMED_FUNCTION_CALL']]]])],
            [200, self::sse([['candidates' => [['content' => ['parts' => [['text' => 'Ok.']]], 'finishReason' => 'STOP']]]])],
        ]);
        [$texte] = $this->jouer($adapter, static fn(): array => []);

        $this->assertSame('Ok.', $texte);
        $this->assertCount(2, $adapter->bodies);
    }

    #[DataProvider('arretsDemandes')]
    public function testGeminiNExecutePlusAucunOutilApresUnArret(bool $arretDesLeDebut, array $attendus): void
    {
        // Le flux ne contrôle l'arrêt qu'une fois du texte écrit : un aller
        // fait d'appels seuls n'était jamais interrompu avant ses outils.
        $adapter = $this->gemini('gemini-3-flash-preview', [
            [200, self::sse([
                ['candidates' => [['content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'chercher', 'args' => ['q' => 'budget'], 'id' => 'fc1'], 'thoughtSignature' => 'SIG-A'],
                    ['functionCall' => ['name' => 'lire', 'args' => ['id' => 'd1'], 'id' => 'fc2']],
                ]], 'finishReason' => 'STOP']]],
            ])],
            [200, self::sse([['candidates' => [['content' => ['parts' => [['text' => 'Jamais.']]], 'finishReason' => 'STOP']]]])],
        ]);
        [$appels, $texte] = $this->jouerAvecArret($adapter, $arretDesLeDebut);

        $this->assertSame($attendus, $appels, 'Ni lecture ni carte d’approbation sur une réponse coupée.');
        $this->assertCount(1, $adapter->bodies, 'Rien ne repart.');
        $this->assertSame('', $texte, 'Un arrêt n’est pas une erreur.');
    }

    // ------------------------------------------------------------------
    // 4. Câblage
    // ------------------------------------------------------------------

    public function testLeControleurArbitreRechercheInternetEtOutils(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        // Pure : la recherche ne s'efface que devant des outils EXPLICITES, sur
        // un modèle qui ne sait pas cumuler les deux.
        $this->assertTrue(ChatController::webSearchYieldsToTools(true, true, false, true));
        $this->assertFalse(ChatController::webSearchYieldsToTools(true, true, true, true), 'Gemini 3, Claude, GPT cumulent');
        $this->assertFalse(ChatController::webSearchYieldsToTools(true, true, false, false), 'MIA choisit seul : la recherche gagne');
        $this->assertFalse(ChatController::webSearchYieldsToTools(false, true, false, true));

        $source = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('self::webSearchYieldsToTools(', $source);
        $this->assertMatchesRegularExpression('/\$webYieldedToTools = true;/', $source);
        $this->assertStringContainsString('if ($webAccess && !$nativeWebSearch && !$webYieldedToTools) {', $source,
            'pas deux notes contradictoires sur la recherche');
        $this->assertMatchesRegularExpression('/!LlmCatalog::supportsWebSearchWithTools\([^\n]*\)\)\s*\{\s*\$autoConnectors = \[\];/', $source,
            '« Laisser MIA choisir » cède la place à la recherche');
    }

    public function testLesAdaptateursEmploientLaBoucleCommune(): void
    {
        foreach ([DeepSeekAdapter::class, MoonshotAdapter::class, ZaiAdapter::class, MistralAdapter::class, LocalAdapter::class] as $classe) {
            $this->assertContains(ChatCompletionsToolLoopTrait::class, class_uses($classe), $classe);
        }
        $this->assertContains(ToolUseUnsupportedTrait::class, class_uses(PerplexityAdapter::class), 'Sonar : aucun outil');
        $this->assertNotContains(ToolUseUnsupportedTrait::class, class_uses(GeminiAdapter::class));

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $src = (string)file_get_contents(ROOT . '/' . $liste);
            $trait = strpos($src, 'ChatCompletionsToolLoopTrait.php');
            $this->assertNotFalse($trait, $liste);
            foreach (['DeepSeekAdapter.php', 'MoonshotAdapter.php', 'ZaiAdapter.php', 'MistralAdapter.php', 'LocalAdapter.php'] as $adapter) {
                $this->assertLessThan(strpos($src, $adapter), $trait, "$liste : le trait avant $adapter");
            }
        }
    }

    public function testLaCaseDuLlmLocalEstCableeDeBoutEnBout(): void
    {
        $gabarit = (string)file_get_contents(ROOT . '/templates/admin/llms/keys.php');
        $this->assertMatchesRegularExpression('/id="localLlmForm".*name="tool_use"/s', $gabarit);
        $this->assertStringContainsString('LocalAdapter::toolUseEnabled()', $gabarit);

        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $local = substr($admin, (int)strpos($admin, "if (\$provider === 'local') {"), 900);
        $this->assertStringContainsString('Settings::set(LocalAdapter::SETTING_TOOL_USE', $local, 'écrit dans la branche du LLM local seulement');

        $chat = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString("LlmCatalog::supportsToolUse('local'", $chat);
    }

    // ------------------------------------------------------------------
    // Outillage
    // ------------------------------------------------------------------

    /** @param list<array{0:int,1:string}> $reponses */
    private function chat(string $classe, string $modele, array $reponses): object
    {
        $adapter = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
        $parent = get_parent_class($classe);
        (new ReflectionProperty($parent, 'apiKey'))->setValue($adapter, $parent === LocalAdapter::class ? '' : 'cle-factice');
        (new ReflectionProperty($parent, 'model'))->setValue($adapter, $modele);
        if ($parent === LocalAdapter::class) {
            (new ReflectionProperty($parent, 'apiUrl'))->setValue($adapter, 'http://serveur-local/v1/chat/completions');
        }
        $adapter->responses = $reponses;
        $adapter->dir = (string)$this->dossier;
        return $adapter;
    }

    /** @param list<array{0:int,1:string}> $reponses */
    private function gemini(string $modele, array $reponses): GeminiToolDouble
    {
        $adapter = (new ReflectionClass(GeminiToolDouble::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(GeminiAdapter::class, 'apiKey'))->setValue($adapter, 'cle-factice');
        (new ReflectionProperty(GeminiAdapter::class, 'model'))->setValue($adapter, $modele);
        $adapter->responses = $reponses;
        $adapter->dir = (string)$this->dossier;
        return $adapter;
    }

    /** @return array{0:string,1:array} */
    private function jouer(object $adapter, callable $outil, array $options = [], bool $web = false): array
    {
        $chunks = [];
        $jetons = $adapter->streamWithTools(
            [['role' => 'user', 'content' => 'Bonjour']],
            'système',
            [['name' => 'chercher', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]]],
            static function (string $c) use (&$chunks): void { $chunks[] = $c; },
            $outil,
            0.7,
            $web,
            $options
        );
        return [implode('', $chunks), $jetons];
    }

    /**
     * Joue un aller qui appelle des outils, Stop demandé dès l'arrivée des
     * appels ou pendant le premier outil exécuté (sonde d'arrêt du worker).
     *
     * @return array{0: list<string>, 1: string} outils exécutés, texte émis
     */
    private function jouerAvecArret(object $adapter, bool $arretDesLeDebut): array
    {
        $stop = $arretDesLeDebut;
        LlmAdapterHelpers::setStopProbe(static function () use (&$stop): bool {
            return $stop;
        });
        $appels = [];
        [$texte] = $this->jouer($adapter, static function (string $nom) use (&$appels, &$stop): array {
            $appels[] = $nom;
            $stop = true;
            return ['ok' => true];
        });
        return [$appels, $texte];
    }

    /** @param array<string, string> $valeurs */
    private function reglages(array $valeurs): void
    {
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $valeurs);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, true);
    }

    /** @param list<array> $evenements */
    private static function sse(array $evenements): string
    {
        $sse = '';
        foreach ($evenements as $e) {
            $sse .= 'data: ' . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        return $sse;
    }
}

/** Faux serveur : une réponse par aller, chaque requête gardée décodée. */
trait ProviderToolLoopServerDouble
{
    /** @var list<array{0:int,1:string}> */
    public array $responses = [];
    /** @var list<array> */
    public array $bodies = [];
    public string $dir = '';

    protected function openToolRoundStream(string $body): array
    {
        $this->bodies[] = json_decode($body, true);
        [$status, $contenu] = array_shift($this->responses) ?? [500, '{"error":{"message":"plus de réponse"}}'];
        $fichier = $this->dir . '/aller-' . count($this->bodies) . '-' . bin2hex(random_bytes(3)) . '.sse';
        file_put_contents($fichier, $contenu);
        return [fopen($fichier, 'r'), $status];
    }
}

final class DeepSeekToolDouble extends DeepSeekAdapter { use ProviderToolLoopServerDouble; }
final class MoonshotToolDouble extends MoonshotAdapter { use ProviderToolLoopServerDouble; }
final class ZaiToolDouble extends ZaiAdapter { use ProviderToolLoopServerDouble; }
final class MistralToolDouble extends MistralAdapter { use ProviderToolLoopServerDouble; }
final class LocalToolDouble extends LocalAdapter { use ProviderToolLoopServerDouble; }
final class GeminiToolDouble extends GeminiAdapter { use ProviderToolLoopServerDouble; }
