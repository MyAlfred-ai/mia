<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Arrêts anormaux d'un modèle et pannes en cours de réponse — le VRAI code de
 * lecture des neuf adaptateurs, rejoué sans réseau ni base : le flux SSE est
 * servi depuis un fichier local vers lequel le catalogue redirige l'adresse
 * de l'API (même méthode que GeminiModelsTest).
 *
 * Ce qui passait inaperçu :
 *   - une réponse coupée par la longueur maximale (`length`, `max_tokens`,
 *     `MAX_TOKENS`) s'arrêtait au milieu d'une phrase, sans un mot ;
 *   - quand RIEN n'avait été écrit — raisonnement qui épuise le plafond,
 *     demande bloquée d'emblée par un filtre —, l'utilisateur lisait
 *     « réponse vide, réessayez » ;
 *   - la Responses API d'OpenAI termine aussi par `response.incomplete` et
 *     `response.failed` : non lus, leur usage n'était pas facturé ;
 *   - un fournisseur qui ne rapporte pas son usage (modèle local) terminait
 *     une réponse COMPLÈTE par « le serveur n'a renvoyé aucune donnée » ;
 *   - Anthropic, recherche Internet cochée : une panne arrivée après le début
 *     du texte n'était pas émise du tout ;
 *   - boucles d'outils OpenAI : un Stop demandé pendant qu'arrivaient les
 *     appels laissait partir lectures et cartes d'approbation — le contrôle
 *     ne venait qu'après l'exécution du tour.
 */
final class LlmStopReasonTest extends TestCase
{
    private ?string $dossier = null;
    private string|false $journalAvant = false;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Nom de fichier avec « : » et « ? » impossible sous Windows.');
        }
        $this->dossier = sys_get_temp_dir() . '/mia-stop-' . bin2hex(random_bytes(6));
        mkdir($this->dossier);
        // Les adaptateurs journalisent chaque panne rejouée : hors de la sortie
        // des tests, où une centaine de lignes noierait un vrai échec.
        $this->journalAvant = ini_get('error_log');
        ini_set('error_log', $this->dossier . '/journal.log');
    }

    protected function tearDown(): void
    {
        LlmAdapterHelpers::setStopProbe(null);
        ini_set('error_log', $this->journalAvant === false ? '' : $this->journalAvant);
        LlmCatalog::flushCache();
        if ($this->dossier !== null && is_dir($this->dossier)) {
            foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }
    }

    // ------------------------------------------------------------------
    // 1. Lecture des raisons d'arrêt
    // ------------------------------------------------------------------

    /** @return array<string, array{?string, ?string}> */
    public static function raisons(): array
    {
        return [
            // Fins normales : jamais un problème.
            'OpenAI stop'            => ['stop', null],
            'OpenAI tool_calls'      => ['tool_calls', null],
            'Anthropic end_turn'     => ['end_turn', null],
            'Anthropic tool_use'     => ['tool_use', null],
            'Anthropic pause_turn'   => ['pause_turn', null],
            'Gemini STOP'            => ['STOP', null],
            'Gemini non précisée'    => ['FINISH_REASON_UNSPECIFIED', null],
            'absente'                => [null, null],
            'vide'                   => ['', null],
            // Une raison inconnue n'invente pas un problème.
            'inconnue'               => ['quelque_chose_de_neuf', null],

            'OpenAI length'          => ['length', 'truncated'],
            'Anthropic max_tokens'   => ['max_tokens', 'truncated'],
            'Responses API'          => ['max_output_tokens', 'truncated'],
            'Gemini MAX_TOKENS'      => ['MAX_TOKENS', 'truncated'],
            'Mistral model_length'   => ['model_length', 'context'],
            'Anthropic fenêtre'      => ['model_context_window_exceeded', 'context'],
            'OpenAI content_filter'  => ['content_filter', 'filtered'],
            'Gemini SAFETY'          => ['SAFETY', 'filtered'],
            'Gemini RECITATION'      => ['RECITATION', 'filtered'],
            'Gemini interdit'        => ['PROHIBITED_CONTENT', 'filtered'],
            'GLM sensitive'          => ['sensitive', 'filtered'],
            'DeepSeek ressources'    => ['insufficient_system_resource', 'overloaded'],
            'DeepSeek interrompue'   => ['aborted', 'generic'],
            'GLM réseau'             => ['network_error', 'overloaded'],
            'Mistral error'          => ['error', 'generic'],
            'Gemini OTHER'           => ['OTHER', 'generic'],
        ];
    }

    #[DataProvider('raisons')]
    public function testLaRaisonDArretEstTraduite(?string $raison, ?string $attendu): void
    {
        $genre = LlmAdapterHelpers::stopKind($raison);
        $this->assertSame($attendu, $genre);
        if ($genre !== null) {
            $this->assertContains($genre, LlmErrorPresenter::STOP_KINDS, 'Le présentateur doit savoir dire ce genre.');
        }
    }

    public function testLArretSansReponseDevientUneErreurReconnue(): void
    {
        foreach (LlmErrorPresenter::STOP_KINDS as $genre) {
            $chunk = LlmAdapterHelpers::stopChunk('Gemini', $genre, 'BRUT');
            // Préfixe commun à toutes les erreurs d'adaptateur : les détections
            // ad hoc du dépôt (« [Erreur ») le reconnaissent aussi.
            $this->assertStringStartsWith('[Erreur Gemini : ', $chunk);

            $desc = LlmErrorPresenter::parse($chunk);
            $this->assertSame($genre, $desc['kind'], $chunk);
            $this->assertSame('Gemini', $desc['provider']);
        }
    }

    public function testRienNEstEmisQuandDuTexteEstDejaParti(): void
    {
        $emis = [];
        $stop = LlmAdapterHelpers::concludeStop('OpenAI', 'length', true, false, static function (string $c) use (&$emis): void { $emis[] = $c; });

        $this->assertSame(['kind' => 'truncated', 'raw' => 'length'], $stop);
        // Un marqueur entre crochets finirait dans une page web ou un JSON
        // que l'appelant attend intact : l'information voyage dans les jetons.
        $this->assertSame([], $emis);

        $stop = LlmAdapterHelpers::concludeStop('OpenAI', 'length', false, false, static function (string $c) use (&$emis): void { $emis[] = $c; });
        $this->assertSame(['kind' => 'truncated', 'raw' => 'length'], $stop);
        $this->assertCount(1, $emis);

        // Une erreur est déjà partie : pas de second message.
        $emis = [];
        LlmAdapterHelpers::concludeStop('OpenAI', 'length', false, true, static function (string $c) use (&$emis): void { $emis[] = $c; });
        $this->assertSame([], $emis);

        $this->assertNull(LlmAdapterHelpers::concludeStop('OpenAI', 'stop', false, false, static fn(string $c) => null));
    }

    public function testLesLignesSseSansChargeUtileNeSontPasUneErreur(): void
    {
        foreach (["\n", "\r\n", ": keepalive\n", "event: message\n", "id: 42\n", "retry: 3000\n", "  \n"] as $ligne) {
            $this->assertTrue(LlmAdapterHelpers::isSseNoise($ligne), json_encode($ligne));
        }
        foreach (["data: {}\n", '{"error":{"message":"x"}}', "<html>\n"] as $ligne) {
            $this->assertFalse(LlmAdapterHelpers::isSseNoise($ligne), json_encode($ligne));
        }
    }

    // ------------------------------------------------------------------
    // 2. Adaptateurs « Chat Completions » (OpenAI et compatibles)
    // ------------------------------------------------------------------

    /** @return array<string, array{string, string}> fournisseur (clé du catalogue), libellé des chunks */
    public static function adaptateursChat(): array
    {
        return [
            'OpenAI'     => ['openai', 'OpenAI'],
            'Mistral'    => ['mistral', 'Mistral'],
            'DeepSeek'   => ['deepseek', 'DeepSeek'],
            'Kimi'       => ['moonshot', 'Kimi'],
            'GLM'        => ['zai', 'GLM'],
            'Perplexity' => ['perplexity', 'Perplexity'],
            'local'      => ['local', 'LLM local'],
        ];
    }

    #[DataProvider('adaptateursChat')]
    public function testUneReponseCoupeeParLaLongueurLeDitDansSesJetons(string $fournisseur, string $libelle): void
    {
        [$texte, $jetons, $chunks] = $this->jouerChat($fournisseur, [
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Bonjour, voici le début'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'length']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
        ]);

        $this->assertSame('Bonjour, voici le début', $texte, 'Le texte reste intact : aucun marqueur ajouté par l\'adaptateur.');
        $this->assertSame(['Bonjour, voici le début'], $chunks);
        $this->assertSame(['kind' => 'truncated', 'raw' => 'length'], $jetons['stop'] ?? null);
        $this->assertSame(5, (int)$jetons['output']);
        $this->assertSame('truncated', LlmErrorPresenter::stopDescriptor($jetons, $fournisseur)['kind']);
    }

    #[DataProvider('adaptateursChat')]
    public function testUnArretSansAucunTexteDevientUneErreurNommee(string $fournisseur, string $libelle): void
    {
        // Modèle qui raisonne : tout le plafond est parti en réflexion.
        [$texte, $jetons, $chunks] = $this->jouerChat($fournisseur, [
            ['choices' => [['index' => 0, 'delta' => ['reasoning_content' => 'Je réfléchis…'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'length']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4096]],
        ]);

        $this->assertCount(1, $chunks, 'Une seule erreur, pas de diagnostic « flux vide » en plus.');
        $desc = LlmErrorPresenter::parse($chunks[0]);
        $this->assertSame('truncated', $desc['kind'] ?? null, $chunks[0]);
        $this->assertSame($libelle, $desc['provider']);
        $this->assertSame('Limite de longueur atteinte', LlmErrorPresenter::present($desc)['title']);
        $this->assertSame('truncated', $jetons['stop']['kind'] ?? null);
        $this->assertSame(4096, (int)$jetons['output'], 'La réflexion a été consommée : elle reste comptée.');
    }

    #[DataProvider('adaptateursChat')]
    public function testUneReponseCompleteSansUsageNestPasUnFluxVide(string $fournisseur, string $libelle): void
    {
        // Aucun évènement d'usage (modèle local, fournisseur qui ne le rapporte pas).
        [$texte, $jetons, $chunks] = $this->jouerChat($fournisseur, [
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Réponse complète.'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ]);

        $this->assertSame(['Réponse complète.'], $chunks, '« Le serveur n\'a renvoyé aucune donnée » suivait une réponse entière.');
        $this->assertArrayNotHasKey('stop', $jetons);
        $this->assertSame(0, (int)$jetons['output']);
    }

    /**
     * Mistral, modèle qui raisonne (Small 4, Medium 3.5, Large 4 avec
     * `reasoning_effort`) : `delta.content` est une LISTE pendant la réflexion
     * (morceau `thinking`), puis une liste [thinking fermé, text] à la
     * transition, puis une chaîne (guide « Reasoning » de Mistral, relu le
     * 2026-10-07). La liste partait telle quelle vers emitAssistantChunk(string)
     * : TypeError, réponse coupée net. Seuls les morceaux `text` sont la réponse.
     */
    public function testMistralNeLaissePasserQueLeTexteDUnModeleQuiRaisonne(): void
    {
        $pensee = static fn(string $t): array => ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $t]]];
        [$texte, $jetons, $chunks] = $this->jouerChat('mistral', [
            ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => [$pensee('17 × 23 : 340 + 51…')]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => [$pensee(' = 391.'), ['type' => 'text', 'text' => '17 × 23 ']]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => '= 391.'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => null], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 40]],
        ]);

        $this->assertSame('17 × 23 = 391.', $texte, 'La réflexion ne fuit pas dans la réponse.');
        $this->assertSame(['17 × 23 ', '= 391.'], $chunks);
        $this->assertArrayNotHasKey('stop', $jetons);
        $this->assertSame(40, (int)$jetons['output'], 'La réflexion est comptée dans la sortie facturée.');
    }

    #[DataProvider('adaptateursChat')]
    public function testUnFluxVideResteSignale(string $fournisseur, string $libelle): void
    {
        [, , $chunks] = $this->jouerChat($fournisseur, []);

        $this->assertCount(1, $chunks);
        $this->assertSame('empty', LlmErrorPresenter::parse($chunks[0])['kind'] ?? null, $chunks[0]);
    }

    #[DataProvider('adaptateursChat')]
    public function testUneErreurAuFilDuFluxPorteSonCode(string $fournisseur, string $libelle): void
    {
        [, , $chunks] = $this->jouerChat($fournisseur, [
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Début'], 'finish_reason' => null]]],
            ['error' => ['message' => 'The engine is currently overloaded, please try again later', 'type' => 'engine_overloaded_error']],
        ]);

        $this->assertSame('Début', $chunks[0]);
        $this->assertCount(2, $chunks, 'L\'erreur part une fois, sans diagnostic de flux vide derrière.');
        $desc = LlmErrorPresenter::parseMidStream($chunks[1]);
        $this->assertSame('overloaded', $desc['kind'] ?? null, $chunks[1]);
        $this->assertStringContainsString('engine_overloaded_error', $chunks[1], 'Le code machine précède le message.');
    }

    #[DataProvider('adaptateursChat')]
    public function testUnCorpsDErreurSansLigneDataEstPresente(string $fournisseur, string $libelle): void
    {
        // Réponse JSON servie sans statut d'erreur exposé : aucune ligne `data:`.
        [, , $chunks] = $this->jouerChat($fournisseur, null, json_encode([
            'error' => ['message' => 'You exceeded your current quota, please check your plan and billing details.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota'],
        ]) . "\n");

        $this->assertCount(1, $chunks);
        $this->assertSame('quota', LlmErrorPresenter::parse($chunks[0])['kind'] ?? null, $chunks[0]);
    }

    #[DataProvider('adaptateursChat')]
    public function testLesChampsIdEtRetryDUnFluxSainNeSontPasUneErreur(string $fournisseur, string $libelle): void
    {
        $sse = "id: 1\nretry: 3000\nevent: message\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'Tout va bien.'], 'finish_reason' => 'stop']]]) . "\n\n"
            . "id: 2\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4]]) . "\n\n"
            . "data: [DONE]\n\n";
        [, , $chunks] = $this->jouerChat($fournisseur, null, $sse);

        $this->assertSame(['Tout va bien.'], $chunks);
    }

    // ------------------------------------------------------------------
    // 3. OpenAI — Responses API
    // ------------------------------------------------------------------

    public function testUneReponseIncompleteEstFactureeEtSignalee(): void
    {
        [$texte, $jetons, $chunks] = $this->jouerResponses([
            ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
            ['type' => 'response.output_text.delta', 'delta' => 'Voici le début'],
            ['type' => 'response.incomplete', 'response' => [
                'id' => 'resp_1', 'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 4096,
                    'output_tokens_details' => ['reasoning_tokens' => 3000]],
            ]],
        ]);

        $this->assertSame('Voici le début', $texte);
        $this->assertSame(['kind' => 'truncated', 'raw' => 'max_output_tokens'], $jetons['stop'] ?? null);
        // L'évènement n'était pas lu : la boucle courait jusqu'à la fin du flux
        // et l'usage n'était jamais relevé — donc jamais facturé.
        $this->assertSame(120, $jetons['input']);
        $this->assertSame(4096, $jetons['output']);
        $this->assertSame(3000, $jetons['output_reasoning']);
        $this->assertCount(1, $chunks);
    }

    public function testUneReponseIncompleteSansTexteEstUneErreurNommee(): void
    {
        [, $jetons, $chunks] = $this->jouerResponses([
            ['type' => 'response.incomplete', 'response' => [
                'incomplete_details' => ['reason' => 'content_filter'], 'output' => [],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 0],
            ]],
        ]);

        $this->assertCount(1, $chunks);
        $this->assertSame('filtered', LlmErrorPresenter::parse($chunks[0])['kind'] ?? null, $chunks[0]);
        $this->assertSame(50, $jetons['input']);
    }

    public function testUneReponseEnEchecPorteSaCauseEtSonUsage(): void
    {
        [, $jetons, $chunks] = $this->jouerResponses([
            ['type' => 'response.output_text.delta', 'delta' => 'Début'],
            ['type' => 'response.failed', 'response' => [
                'status' => 'failed',
                'error' => ['code' => 'server_error', 'message' => 'The model failed to generate a response.'],
                'usage' => ['input_tokens' => 80, 'output_tokens' => 12],
            ]],
        ]);

        $this->assertSame('Début', $chunks[0]);
        $this->assertCount(2, $chunks);
        $this->assertSame('server', LlmErrorPresenter::parseMidStream($chunks[1])['kind'] ?? null, $chunks[1]);
        $this->assertSame(80, $jetons['input'], 'Une réponse en échec a consommé des tokens comme une autre.');
        $this->assertArrayNotHasKey('stop', $jetons);
    }

    public function testLaBoucleDOutilsSignaleAussiUneReponseIncomplete(): void
    {
        $this->rediriger('openai', ['responses' => $this->ecrire('responses', [
            ['type' => 'response.created', 'response' => ['id' => 'resp_9']],
            ['type' => 'response.incomplete', 'response' => [
                'id' => 'resp_9', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [],
                'usage' => ['input_tokens' => 200, 'output_tokens' => 8000],
            ]],
        ])]);

        $chunks = [];
        $jetons = $this->adapter(OpenAIAdapter::class, 'gpt-5.6')->streamWithTools(
            [['role' => 'user', 'content' => 'Bonjour']],
            'système',
            [['name' => 'outil', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]],
            static function (string $c) use (&$chunks): void { $chunks[] = $c; },
            static fn(string $nom, array $args): array => ['ok' => true]
        );

        $this->assertCount(1, $chunks);
        $this->assertSame('truncated', LlmErrorPresenter::parse($chunks[0])['kind'] ?? null);
        $this->assertSame('truncated', $jetons['stop']['kind'] ?? null);
        $this->assertSame(8000, $jetons['output']);
    }

    public function testLaBoucleDOutilsChatCompletionsSignaleLaLongueur(): void
    {
        $this->rediriger('openai', ['chat_completions' => $this->ecrire('chat', [
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Texte coupé'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'length']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3]],
        ])]);

        $chunks = [];
        $jetons = $this->adapter(OpenAIAdapter::class, 'o3')->streamWithTools(
            [['role' => 'user', 'content' => 'Bonjour']],
            'système',
            [['name' => 'outil', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]],
            static function (string $c) use (&$chunks): void { $chunks[] = $c; },
            static fn(string $nom, array $args): array => ['ok' => true]
        );

        $this->assertSame(['Texte coupé'], $chunks);
        $this->assertSame('truncated', $jetons['stop']['kind'] ?? null);
    }

    /**
     * Stop dans un aller qui appelle deux outils, sans texte — donc sans
     * contrôle dans le flux : soit pendant que les appels arrivent, soit
     * pendant l'exécution du premier. Le contrôle venait APRÈS l'exécution du
     * tour ; il a lieu désormais avant CHAQUE appel, dans les deux boucles.
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
    public function testLaBoucleDOutilsChatCompletionsNExecutePlusRienApresUnArret(bool $arretDesLeDebut, array $attendus): void
    {
        $this->rediriger('openai', ['chat_completions' => $this->ecrire('chat', [
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'chercher', 'arguments' => '{"q":']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '"budget"}']],
                ['index' => 1, 'id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'lire', 'arguments' => '{"id":"d1"}']],
            ]], 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 12]],
        ], true)]);

        [$appels, $jetons, $chunks] = $this->outilsApresArret('o3', $arretDesLeDebut);

        $this->assertSame($attendus, $appels, 'Ni lecture ni carte d’approbation sur une réponse coupée.');
        $this->assertSame(40, $jetons['input'], 'Un seul aller : rien ne repart.');
        $this->assertArrayNotHasKey('rounds_exhausted', $jetons);
        $this->assertSame([], $chunks, 'Un arrêt n’est pas une erreur.');
    }

    #[DataProvider('arretsDemandes')]
    public function testLaBoucleDOutilsResponsesNExecutePlusRienApresUnArret(bool $arretDesLeDebut, array $attendus): void
    {
        $appel = static fn(string $id, string $nom, string $args): array =>
            ['type' => 'function_call', 'id' => 'fc_' . $id, 'call_id' => 'call_' . $id, 'name' => $nom, 'arguments' => $args];
        $this->rediriger('openai', ['responses' => $this->ecrire('responses', [
            ['type' => 'response.created', 'response' => ['id' => 'resp_7']],
            ['type' => 'response.output_item.added', 'item' => $appel('1', 'chercher', '')],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{"q":"budget"}'],
            ['type' => 'response.output_item.done', 'item' => $appel('1', 'chercher', '{"q":"budget"}')],
            ['type' => 'response.output_item.done', 'item' => $appel('2', 'lire', '{"id":"d1"}')],
            ['type' => 'response.completed', 'response' => [
                'id' => 'resp_7', 'output' => [], 'usage' => ['input_tokens' => 40, 'output_tokens' => 12],
            ]],
        ])]);

        [$appels, $jetons, $chunks] = $this->outilsApresArret('gpt-5.6', $arretDesLeDebut);

        $this->assertSame($attendus, $appels, 'Ni lecture ni carte d’approbation sur une réponse coupée.');
        $this->assertSame(40, $jetons['input'], 'Un seul aller : rien ne repart.');
        $this->assertArrayNotHasKey('rounds_exhausted', $jetons);
        $this->assertSame([], $chunks, 'Un arrêt n’est pas une erreur.');
    }

    // ------------------------------------------------------------------
    // 4. Gemini
    // ------------------------------------------------------------------

    public function testUneDemandeBloqueeParGoogleNestPlusUneReponseVide(): void
    {
        // Aucun candidat : seulement le motif du blocage et l'usage de l'entrée.
        [$texte, $jetons, $chunks] = $this->jouerGemini([
            ['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 42, 'totalTokenCount' => 42]],
        ]);

        $this->assertSame('', $texte === $chunks[0] ? '' : $texte);
        $this->assertCount(1, $chunks);
        $desc = LlmErrorPresenter::parse($chunks[0]);
        $this->assertSame('filtered', $desc['kind'] ?? null, $chunks[0]);
        $this->assertSame('Demande bloquée par le filtre de sécurité', LlmErrorPresenter::present($desc)['title']);
        $this->assertSame(42, $jetons['input']);
    }

    public function testLaReflexionQuiEpuiseLePlafondEstNommee(): void
    {
        [, $jetons, $chunks] = $this->jouerGemini([
            ['candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'MAX_TOKENS']],
             'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 0, 'thoughtsTokenCount' => 1024, 'totalTokenCount' => 1054]],
        ]);

        $this->assertCount(1, $chunks);
        $this->assertSame('truncated', LlmErrorPresenter::parse($chunks[0])['kind'] ?? null, $chunks[0]);
        $this->assertSame(1024, $jetons['output']);
    }

    public function testUneReponseGeminiCoupeeLeDitDansSesJetons(): void
    {
        [$texte, $jetons, $chunks] = $this->jouerGemini([
            ['candidates' => [['content' => ['parts' => [['text' => 'Début de réponse']]]]]],
            ['candidates' => [['content' => ['parts' => []], 'finishReason' => 'MAX_TOKENS']],
             'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 2048, 'totalTokenCount' => 2078]],
        ]);

        $this->assertSame(['Début de réponse'], $chunks);
        $this->assertSame(['kind' => 'truncated', 'raw' => 'MAX_TOKENS'], $jetons['stop'] ?? null);
    }

    public function testUneFinNormaleGeminiNeSignaleRien(): void
    {
        [, $jetons, $chunks] = $this->jouerGemini([
            ['candidates' => [['content' => ['parts' => [['text' => 'Réponse.']]], 'finishReason' => 'STOP']],
             'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3, 'totalTokenCount' => 8]],
        ]);

        $this->assertSame(['Réponse.'], $chunks);
        $this->assertArrayNotHasKey('stop', $jetons);
    }

    // ------------------------------------------------------------------
    // 5. Anthropic
    // ------------------------------------------------------------------

    public function testUneReponseClaudeCoupeeLeDitDansSesJetons(): void
    {
        [$texte, $jetons, $chunks] = $this->jouerAnthropic(self::evenementsAnthropic('Voici le début', 'max_tokens', 4096), false);

        $this->assertSame(['Voici le début'], $chunks);
        $this->assertSame(['kind' => 'truncated', 'raw' => 'max_tokens'], $jetons['stop'] ?? null);
        $this->assertSame(4096, $jetons['output']);
    }

    public function testLePlafondEpuiseParLeRaisonnementEstNomme(): void
    {
        [, $jetons, $chunks] = $this->jouerAnthropic(self::evenementsAnthropic('', 'max_tokens', 4096), false);

        $this->assertCount(1, $chunks);
        $desc = LlmErrorPresenter::parse($chunks[0]);
        $this->assertSame('truncated', $desc['kind'] ?? null, $chunks[0]);
        $this->assertSame('Anthropic', $desc['provider']);
    }

    public function testUneFinNormaleClaudeNeSignaleRien(): void
    {
        [, $jetons, $chunks] = $this->jouerAnthropic(self::evenementsAnthropic('Réponse.', 'end_turn', 12), false);

        $this->assertSame(['Réponse.'], $chunks);
        $this->assertArrayNotHasKey('stop', $jetons);
    }

    /**
     * Recherche Internet cochée (le réglage par défaut) : le premier essai de
     * l'échelle n'avait pas le droit d'émettre d'erreur. Une surcharge arrivée
     * APRÈS le début du texte n'était donc pas émise du tout.
     */
    public function testUnePanneApresLeDebutDuTexteEstEmiseMemeAvecLaRechercheWeb(): void
    {
        $evenements = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 25]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Début de réponse']],
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
        ];

        foreach ([true, false] as $rechercheWeb) {
            [, , $chunks] = $this->jouerAnthropic($evenements, $rechercheWeb);

            $this->assertSame('Début de réponse', $chunks[0]);
            $this->assertCount(2, $chunks, $rechercheWeb ? 'avec recherche web' : 'sans recherche web');
            $desc = LlmErrorPresenter::parseMidStream($chunks[1]);
            $this->assertSame('overloaded', $desc['kind'] ?? null, $chunks[1]);
        }
    }

    public function testUnePanneAvantLePremierMotResteUneErreurUnique(): void
    {
        $evenements = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 25]]],
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
        ];

        foreach ([true, false] as $rechercheWeb) {
            [, , $chunks] = $this->jouerAnthropic($evenements, $rechercheWeb);

            $this->assertCount(1, $chunks, $rechercheWeb ? 'avec recherche web' : 'sans recherche web');
            $desc = LlmErrorPresenter::parse($chunks[0]);
            $this->assertSame('overloaded', $desc['kind'] ?? null, $chunks[0]);
            $this->assertTrue(LlmErrorPresenter::warrantsFailover($desc), 'Avant le premier mot, le LLM de secours prend le relais.');
        }
    }

    public function testLaBoucleDOutilsClaudeSignaleLaLongueur(): void
    {
        $this->rediriger('anthropic', ['messages' => $this->ecrire('messages', self::evenementsAnthropic('Texte coupé', 'max_tokens', 4096))]);

        $chunks = [];
        $jetons = $this->adapter(AnthropicAdapter::class, 'claude-opus-5')->streamWithTools(
            [['role' => 'user', 'content' => 'Bonjour']],
            'système',
            [['name' => 'outil', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]],
            static function (string $c) use (&$chunks): void { $chunks[] = $c; },
            static fn(string $nom, array $args): array => ['ok' => true]
        );

        $this->assertSame(['Texte coupé'], $chunks);
        $this->assertSame('truncated', $jetons['stop']['kind'] ?? null);
    }

    /**
     * Refus avant toute sortie : Anthropic ne facture que certaines
     * catégories. Le VRAI code de lecture des deux boucles, rejoué sans
     * réseau : une catégorie facturée garde ses jetons (le grand livre dit ce
     * que l'organisation paie), une autre les remet à zéro.
     */
    public function testUnRefusFactureGardeSesJetonsDansLesDeuxBoucles(): void
    {
        $refus = static fn(string $categorie): array => [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 25]]],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => $categorie]],
             'usage' => ['output_tokens' => 0]],
            ['type' => 'message_stop'],
        ];

        foreach (['bio' => 25, 'reasoning_extraction' => 25, 'cyber' => 0] as $categorie => $attendu) {
            [, $jetons, $chunks] = $this->jouerAnthropic($refus($categorie), false);
            $this->assertSame($attendu, $jetons['input'], "$categorie (chemin simple)");
            $this->assertSame('refusal', LlmErrorPresenter::parse($chunks[0] ?? '')['kind'] ?? null, $categorie);

            $this->rediriger('anthropic', ['messages' => $this->ecrire('messages', $refus($categorie))]);
            $jetons = $this->adapter(AnthropicAdapter::class, 'claude-sonnet-5-5')->streamWithTools(
                [['role' => 'user', 'content' => 'Bonjour']],
                'système',
                [['name' => 'outil', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]],
                static function (string $c): void {},
                static fn(string $nom, array $args): array => ['ok' => true]
            );
            $this->assertSame($attendu, $jetons['input'], "$categorie (boucle d'outils)");
        }
    }

    // ------------------------------------------------------------------
    // 6. Câblage : aucun adaptateur ne garde l'ancienne lecture
    // ------------------------------------------------------------------

    public function testTousLesAdaptateursLisentLeDetailCompletDesErreurs(): void
    {
        $adaptateurs = ['OpenAI', 'Anthropic', 'Mistral', 'Gemini', 'Perplexity', 'DeepSeek', 'Moonshot', 'Zai', 'Local'];
        foreach ($adaptateurs as $nom) {
            $src = (string)file_get_contents(ROOT . '/src/LLM/' . $nom . 'Adapter.php');

            // Le message seul ne dit pas la cause : le code machine doit suivre.
            $this->assertStringNotContainsString("['error']['message'] ??", $src, "$nom garde une lecture du seul message.");
            $this->assertStringContainsString('LlmAdapterHelpers::errorDetail(', $src, $nom);
            // Raison d'arrêt rapportée dans les jetons.
            $this->assertStringContainsString("['stop' => \$stop]", str_replace("\$tokens['stop'] = \$stop", "['stop' => \$stop]", $src), $nom);
        }
    }

    /**
     * Recherche Internet cochée (le défaut), c'est stream() qui émet l'erreur
     * HTTP à la place du premier barreau de l'échelle. Le statut s'y perdait :
     * un 5xx d'Anthropic n'était plus reconnu comme une panne, donc ni présenté
     * comme tel ni relayé par le LLM de secours.
     */
    public function testLEchelleWebDAnthropicRedonneLeStatutHttp(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');

        $this->assertStringContainsString('return $fail($errMsg, $retryWithFallback, null, null, $httpStatus);', $src);
        $this->assertStringContainsString(
            "LlmAdapterHelpers::buildHttpErrorMessage('Anthropic', (int)\$status, \$result['fatal_error'])",
            $src
        );
        // Après du texte, l'erreur part toujours : aucun barreau ne rejouera la réponse.
        $this->assertSame(3, substr_count($src, 'if ($emitErrors || $hasDelta) {'));

        // Le chunk ainsi émis est une panne reconnue, avec ou sans statut.
        foreach ([
            LlmAdapterHelpers::buildHttpErrorMessage('Anthropic', 500, 'api_error — Internal server error'),
            '[Erreur Anthropic : overloaded_error — Overloaded]',
        ] as $chunk) {
            $this->assertTrue(LlmErrorPresenter::warrantsFailover(LlmErrorPresenter::parse($chunk)), $chunk);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @param list<array>|null $evenements évènements SSE (null = contenu brut fourni)
     * @return array{0: string, 1: array, 2: list<string>} texte, jetons, fragments émis
     */
    private function jouerChat(string $fournisseur, ?array $evenements, ?string $brut = null): array
    {
        $fichier = $evenements !== null ? $this->ecrire('chat', $evenements, true) : $this->ecrireBrut('chat', (string)$brut);

        if ($fournisseur === 'local') {
            $adapter = (new ReflectionClass(LocalAdapter::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(LocalAdapter::class, 'apiUrl'))->setValue($adapter, $fichier);
            (new ReflectionProperty(LocalAdapter::class, 'model'))->setValue($adapter, 'llama3');
            (new ReflectionProperty(LocalAdapter::class, 'apiKey'))->setValue($adapter, '');
        } else {
            $this->rediriger($fournisseur, [$fournisseur === 'mistral' ? 'chat' : 'chat_completions' => $fichier]);
            $classe = [
                'openai' => OpenAIAdapter::class, 'mistral' => MistralAdapter::class, 'deepseek' => DeepSeekAdapter::class,
                'moonshot' => MoonshotAdapter::class, 'zai' => ZaiAdapter::class, 'perplexity' => PerplexityAdapter::class,
            ][$fournisseur];
            // OpenAI : un modèle routé sur Chat Completions.
            $modele = ['openai' => 'o3', 'mistral' => 'mistral-large-latest', 'deepseek' => 'deepseek-flash',
                'moonshot' => 'kimi-k3', 'zai' => 'glm-5.2', 'perplexity' => 'sonar'][$fournisseur];
            $adapter = $this->adapter($classe, $modele);
        }

        return $this->lancer($adapter);
    }

    /** @return array{0: string, 1: array, 2: list<string>} */
    private function jouerResponses(array $evenements): array
    {
        $this->rediriger('openai', ['responses' => $this->ecrire('responses', $evenements)]);
        return $this->lancer($this->adapter(OpenAIAdapter::class, 'gpt-5.6'));
    }

    /** @return array{0: string, 1: array, 2: list<string>} */
    private function jouerGemini(array $evenements): array
    {
        $modele = 'gemini-3-flash-preview';
        $this->rediriger('gemini', ['base' => $this->dossier . '/']);
        $sse = '';
        foreach ($evenements as $e) {
            $sse .= 'data: ' . json_encode($e) . "\r\n\r\n";
        }
        file_put_contents($this->dossier . '/' . $modele . ':streamGenerateContent?alt=sse', $sse);

        return $this->lancer($this->adapter(GeminiAdapter::class, $modele));
    }

    /** @return array{0: string, 1: array, 2: list<string>} */
    private function jouerAnthropic(array $evenements, bool $rechercheWeb): array
    {
        $this->rediriger('anthropic', ['messages' => $this->ecrire('messages', $evenements)]);
        return $this->lancer($this->adapter(AnthropicAdapter::class, 'claude-opus-5'), $rechercheWeb);
    }

    /** @return list<array> flux Anthropic : texte éventuel, puis la raison d'arrêt */
    private static function evenementsAnthropic(string $texte, string $stopReason, int $sortie): array
    {
        $evenements = [['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 25]]]];
        if ($texte !== '') {
            $evenements[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $texte]];
        }
        $evenements[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => $sortie]];
        $evenements[] = ['type' => 'message_stop'];
        return $evenements;
    }

    /** @return array{0: string, 1: array, 2: list<string>} */
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
        return [implode('', $chunks), $jetons, $chunks];
    }

    /**
     * Boucle d'outils OpenAI, Stop demandé dès l'arrivée des appels ou pendant
     * le premier outil exécuté (sonde d'arrêt du worker).
     *
     * @return array{0: list<string>, 1: array, 2: list<string>} outils exécutés, jetons, fragments émis
     */
    private function outilsApresArret(string $modele, bool $arretDesLeDebut): array
    {
        $stop = $arretDesLeDebut;
        LlmAdapterHelpers::setStopProbe(static function () use (&$stop): bool {
            return $stop;
        });
        $outil = static fn(string $nom): array => ['name' => $nom, 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]];
        $appels = [];
        $chunks = [];
        $jetons = $this->adapter(OpenAIAdapter::class, $modele)->streamWithTools(
            [['role' => 'user', 'content' => 'Bonjour']],
            'système',
            [$outil('chercher'), $outil('lire')],
            static function (string $c) use (&$chunks): void { $chunks[] = $c; },
            static function (string $nom, array $args) use (&$appels, &$stop): array {
                $appels[] = $nom;
                $stop = true;
                return ['ok' => true];
            }
        );
        return [$appels, $jetons, $chunks];
    }

    /** Fichier SSE : un évènement par bloc `data:`, `[DONE]` final pour Chat Completions. */
    private function ecrire(string $nom, array $evenements, bool $done = false): string
    {
        $sse = '';
        foreach ($evenements as $e) {
            $sse .= 'data: ' . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        if ($done && $evenements !== []) {
            $sse .= "data: [DONE]\n\n";
        }
        return $this->ecrireBrut($nom, $sse);
    }

    private function ecrireBrut(string $nom, string $contenu): string
    {
        $fichier = $this->dossier . '/' . $nom . '-' . bin2hex(random_bytes(4)) . '.sse';
        file_put_contents($fichier, $contenu);
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
