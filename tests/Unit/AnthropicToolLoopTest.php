<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Boucle d'outils Anthropic rejouée sans réseau : le VRAI code de
 * streamWithTools / streamToolRound, un faux serveur qui sert une réponse SSE
 * par tour et garde chaque requête envoyée.
 *
 *  - le raisonnement (`thinking` signé, même vide) est RENVOYÉ au tour suivant,
 *    à sa place parmi le texte et les appels d'outils — la doc d'Anthropic le
 *    dit requis dans un tour d'outils, et MIA le jetait ;
 *  - un résultat d'outil en erreur part avec `is_error` ;
 *  - un outil sans argument est rejoué avec `{}`, jamais `[]` ;
 *  - un fil rejoué refusé par l'API retombe sur la forme d'avant, sans rien
 *    afficher ;
 *  - un tour mis en pause par le serveur (`pause_turn`) reprend, sans message
 *    « continue » ajouté, hors budget d'allers-retours ;
 *  - le cache de prompt pose ses points au bon endroit : dernier outil, fin du
 *    fil (dès le premier aller en `eager`, au second en `lazy`), jamais au
 *    dernier aller forcé.
 */
final class AnthropicToolLoopTest extends TestCase
{
    private string $dossier = '';
    private string|false $journalAvant = false;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/mia-loop-' . bin2hex(random_bytes(6));
        mkdir($this->dossier);
        $this->journalAvant = ini_get('error_log');
        ini_set('error_log', $this->dossier . '/journal.log');
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(AnthropicAdapter::class, 'agentFeaturesRejected'))->setValue(null, []);
        (new ReflectionProperty(AnthropicAdapter::class, 'fallbacksRejected'))->setValue(null, false);
        LlmAdapterHelpers::setStopProbe(null);
        LlmCatalog::flushCache();
        ini_set('error_log', $this->journalAvant === false ? '' : $this->journalAvant);
        foreach (glob($this->dossier . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dossier);
    }

    public function testLeRaisonnementEstRenvoyeASaPlaceAuTourSuivant(): void
    {
        $adapter = $this->adapter([
            [200, self::sse([
                ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 100]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig-1']],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Je cherche. ']],
                ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'chercher', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"q":"devis"}']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 20]],
            ])],
            [200, self::sse([
                ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 150]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Voilà.']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
            ])],
        ]);

        $appels = [];
        [$texte] = $this->jouer($adapter, function (string $nom, array $args) use (&$appels): array {
            $appels[] = [$nom, $args];
            return ['resultats' => [['id' => 'd1']]];
        });

        $this->assertSame("Je cherche. \n\nVoilà.", $texte,
            'Le raisonnement n’est jamais affiché comme réponse ; le texte d’après l’outil ouvre un paragraphe.');
        $this->assertSame([['chercher', ['q' => 'devis']]], $appels);
        $this->assertCount(2, $adapter->bodies);

        $messages = $adapter->bodies[1]['messages'];
        $assistant = $messages[count($messages) - 2];
        $this->assertSame('assistant', $assistant['role']);
        $this->assertSame(
            ['thinking', 'text', 'tool_use'],
            array_column($assistant['content'], 'type'),
            'Les blocs reviennent dans l’ordre reçu, raisonnement en tête.'
        );
        $this->assertSame('sig-1', $assistant['content'][0]['signature']);
        $this->assertSame('', $assistant['content'][0]['thinking'], 'Un raisonnement au texte omis est rejoué tel quel, vide.');
        $this->assertSame(['q' => 'devis'], $assistant['content'][2]['input']);

        $user = $messages[count($messages) - 1];
        $this->assertSame('tool_result', $user['content'][0]['type']);
        $this->assertArrayNotHasKey('is_error', $user['content'][0]);
    }

    public function testUnResultatEnErreurPortIsErrorEtUnOutilSansArgumentRejoueUnObjet(): void
    {
        $adapter = $this->adapter([
            [200, self::sse([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'lister', 'input' => []]],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]],
            ])],
            [200, self::sse([
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Échec.']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
            ])],
        ]);

        $this->jouer($adapter, static fn(string $nom, array $args): array => ['error' => 'Connexion expirée.']);

        $this->assertStringContainsString('"input":{}', $adapter->raw[1], 'Un outil sans argument : `{}`, jamais `[]` (refusé).');
        $messages = $adapter->bodies[1]['messages'];
        $resultat = $messages[count($messages) - 1]['content'][0];
        $this->assertTrue($resultat['is_error'] ?? false, 'Le modèle doit savoir que l’outil a échoué.');
    }

    public function testUnFilRejoueRefuseRetombeSurLaFormeDAvantSansRienAfficher(): void
    {
        $tourOutil = self::sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1']],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'chercher', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"q":"x"}']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]],
        ]);
        $adapter = $this->adapter([
            [200, $tourOutil],
            [400, json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
                'message' => 'messages.1.content.0: Invalid `signature` in `thinking` block.']])],
            [200, self::sse([
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Réponse.']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
            ])],
        ]);

        [$texte] = $this->jouer($adapter, static fn(string $nom, array $args): array => ['ok' => true]);

        $this->assertSame('Réponse.', $texte, 'Aucun message d’erreur : le repli est silencieux.');
        $this->assertCount(3, $adapter->bodies);
        $assistant = $adapter->bodies[2]['messages'][1];
        $this->assertSame(['tool_use'], array_column($assistant['content'], 'type'), 'Forme d’avant : ni raisonnement ni bloc serveur.');
    }

    public function testUnTourEnPauseReprendSansMessageAjoute(): void
    {
        $adapter = $this->adapter([
            [200, self::sse([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_search', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":"météo"}']],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'web_search_tool_result', 'tool_use_id' => 'srv_1',
                    'content' => [['type' => 'web_search_result', 'url' => 'https://exemple.fr', 'title' => 'Météo', 'encrypted_content' => 'abc']]]],
                ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'text_delta', 'text' => 'Début. ']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'pause_turn'], 'usage' => ['output_tokens' => 4]],
            ])],
            [200, self::sse([
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Suite.']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
            ])],
        ]);

        [$texte] = $this->jouer($adapter, static fn(string $nom, array $args): array => ['ok' => true]);

        $this->assertStringStartsWith('Début. Suite.', $texte, 'La suite enchaîne sans rien répéter.');
        $this->assertSame(1, substr_count($texte, '[Météo](https://exemple.fr)'),
            'La source du tour en pause est citée une fois, en fin de réponse.');
        $this->assertCount(2, $adapter->bodies);
        $messages = $adapter->bodies[1]['messages'];
        $dernier = $messages[count($messages) - 1];
        $this->assertSame('assistant', $dernier['role'], 'Le tour en pause termine le fil : l’API reprend là où elle en était.');
        $this->assertSame(['server_tool_use', 'web_search_tool_result', 'text'], array_column($dernier['content'], 'type'));
        $this->assertSame('abc', $dernier['content'][1]['content'][0]['encrypted_content'], 'Le résultat serveur revient intact.');
    }

    public function testUneBasculeDeRelaisEnCoursDeReponseGardeLaFormeDAvant(): void
    {
        $blocs = [
            3 => ['type' => 'thinking', 'thinking' => '', 'signature' => 's'],
            5 => ['type' => 'tool_use', 'id' => 't', 'name' => 'n', 'json' => '{}'],
        ];
        $this->assertNotNull(AnthropicAdapter::replayBlocks($blocs));
        $this->assertNull(AnthropicAdapter::replayBlocks([['type' => 'thinking', 'thinking' => 'x', 'signature' => '']]),
            'Un raisonnement sans signature serait refusé : forme d’avant.');

        $src = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertMatchesRegularExpression("/if \\(\\\$btype === 'fallback'\\) \\{.*?\\\$replayable = false;/s", $src);
    }

    public function testLesFormesPuresDuFil(): void
    {
        $rounds = [
            ['blocks' => [['type' => 'server_tool_use', 'id' => 's', 'name' => 'web_search', 'input' => new stdClass()]], 'text' => '', 'tool_calls' => [], 'results' => null],
            ['blocks' => [['type' => 'tool_use', 'id' => 't', 'name' => 'n', 'input' => new stdClass()]], 'text' => '',
             'tool_calls' => [['id' => 't', 'name' => 'n', 'input' => []]], 'results' => [['id' => 't', 'result' => ['ok' => 1]]]],
        ];
        $fil = AnthropicAdapter::roundsToMessages($rounds, true);
        $this->assertCount(2, $fil, 'Le tour en pause et sa suite forment UN message assistant.');
        $this->assertSame(['server_tool_use', 'tool_use'], array_column($fil[0]['content'], 'type'));
        $this->assertTrue(AnthropicAdapter::roundsCarrySpecialBlocks($rounds));
        $this->assertFalse(AnthropicAdapter::endsPaused($rounds));
        $this->assertTrue(AnthropicAdapter::endsPaused([$rounds[0]]));

        $this->assertTrue(AnthropicAdapter::isReplayRejection(400, 'Invalid `signature` in `thinking` block'));
        $this->assertFalse(AnthropicAdapter::isReplayRejection(400, 'max_tokens: 200000 > 128000'));
        $this->assertFalse(AnthropicAdapter::isReplayRejection(529, 'thinking overloaded'));
    }

    // ---------- Notes d'avancement, effort ----------

    public function testLesNotesDAvancementSontTransmisesSansEntrerDansLaReponse(): void
    {
        $adapter = $this->adapter([
            [200, self::sse([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Je cherche d’abord ']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'dans Gmail.']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => 's2']],
                ['type' => 'content_block_stop', 'index' => 1],
                ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'chercher', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
                ['type' => 'content_block_stop', 'index' => 2],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 9]],
            ])],
            [200, self::sse([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => AnthropicAdapter::INTERRUPTED_NOTE]],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Voilà.']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
            ])],
        ]);
        $notes = [];
        [$texte] = $this->jouer($adapter, static fn(string $n, array $a): array => ['ok' => true], [
            'on_progress' => function (string $note) use (&$notes): void { $notes[] = $note; },
        ]);

        $this->assertSame(['Je cherche d’abord dans Gmail.'], $notes, 'Bloc vide et note d’interruption écartés.');
        $this->assertSame('Voilà.', $texte, 'Une note d’avancement n’est jamais la réponse.');
        $this->assertSame(['type' => 'adaptive', 'display' => 'updates'], $adapter->bodies[0]['thinking']);
        $this->assertContains(AnthropicAdapter::THINKING_UPDATES_BETA, AnthropicAdapter::betasFor($adapter->bodies[0]));
        $this->assertSame('Je cherche d’abord dans Gmail.', $adapter->bodies[1]['messages'][1]['content'][0]['thinking'],
            'La note revient au tour suivant, à sa place, comme tout bloc de raisonnement.');
    }

    public function testUnReglageAgentRefuseEstRejoueSansLuiEtOublie(): void
    {
        $adapter = $this->adapter([
            [400, '{"type":"error","error":{"type":"invalid_request_error","message":"thinking.display: Input should be \'omitted\' or \'summarized\'"}}'],
            [200, self::sse(self::tourFinal('Oui.'))],
            [200, self::sse(self::tourFinal('Encore.'))],
        ]);
        $opts = ['on_progress' => static function (string $n): void {}];
        [$texte] = $this->jouer($adapter, static fn(string $n, array $a): array => [], $opts);
        $this->assertSame('Oui.', $texte, 'Rejoué sans rien afficher.');
        $this->assertArrayHasKey('thinking', $adapter->bodies[0]);
        $this->assertArrayNotHasKey('thinking', $adapter->bodies[1]);

        $this->jouer($adapter, static fn(string $n, array $a): array => [], $opts);
        $this->assertArrayNotHasKey('thinking', $adapter->bodies[2], 'Appris pour le process : pas un 400 par message.');
    }

    public function testLEffortNePartQueSIlChangeQuelqueChose(): void
    {
        $adapter = $this->adapter([[200, self::sse(self::tourFinal('A.'))]]);
        $this->jouer($adapter, static fn(string $n, array $a): array => [], ['effort' => 'high']);
        $this->assertSame(['effort' => 'high'], $adapter->bodies[0]['output_config'], 'Opus 5.5 raisonne à `medium` par défaut.');
        $this->assertArrayNotHasKey('thinking', $adapter->bodies[0], 'Sans affichage des notes, rien n’est demandé.');

        $autre = $this->adapter([[200, self::sse(self::tourFinal('B.'))]]);
        (new ReflectionProperty(AnthropicAdapter::class, 'model'))->setValue($autre, 'claude-opus-5');
        $this->jouer($autre, static fn(string $n, array $a): array => [], ['effort' => 'high', 'on_progress' => static function (string $n): void {}]);
        $this->assertArrayNotHasKey('output_config', $autre->bodies[0], 'Défaut déjà `high` : l’envoyer ne changerait rien.');
        $this->assertArrayNotHasKey('thinking', $autre->bodies[0], 'Opus 5 écrit ses notes en texte : rien à demander.');
    }

    public function testLesBetasPartentEnUnSeulEnTete(): void
    {
        $payload = ['fallbacks' => 'default', 'thinking' => ['type' => 'adaptive', 'display' => 'updates']];
        $this->assertSame([AnthropicAdapter::REFUSAL_FALLBACK_BETA, AnthropicAdapter::THINKING_UPDATES_BETA], AnthropicAdapter::betasFor($payload));
        $this->assertSame([], AnthropicAdapter::betasFor(['thinking' => ['type' => 'adaptive']]));
        $src = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertStringContainsString("'anthropic-beta: ' . implode(',', \$betas)", $src);
        $this->assertSame('progress', AnthropicAdapter::rejectedAgentFeature(400, 'thinking.display: unknown', $payload));
        $this->assertNull(AnthropicAdapter::rejectedAgentFeature(400, 'thinking.display: unknown', []), 'Rien d’envoyé, rien de refusé.');
        $this->assertNull(AnthropicAdapter::rejectedAgentFeature(529, 'display', $payload));
    }

    // ---------- Repli sur refus, Stop, paragraphes, nombres ----------

    public function testUnEnTeteDesNotesRefuseNeCoupePasLeRepliSurRefus(): void
    {
        // Le refus cite `anthropic-beta` : le repli le revendiquait, coupait
        // le repli sur refus pour tout le process, puis rejouait la demande
        // avec le même `display`.
        $message = 'Unexpected value(s) `thinking-display-updates-2026-08-18` for the `anthropic-beta` header.';
        $adapter = $this->adapter([
            [400, json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => $message]])],
            [200, self::sse(self::tourFinal('Oui.'))],
        ]);
        [$texte] = $this->jouer($adapter, static fn(string $n, array $a): array => [],
            ['on_progress' => static function (string $n): void {}]);

        $this->assertSame('Oui.', $texte);
        $this->assertCount(2, $adapter->bodies, 'Un seul rejeu : sans les notes.');
        $this->assertSame('default', $adapter->bodies[1]['fallbacks'] ?? null, 'Le repli sur refus reste en place.');
        $this->assertArrayNotHasKey('thinking', $adapter->bodies[1]);
        $this->assertFalse((new ReflectionProperty(AnthropicAdapter::class, 'fallbacksRejected'))->getValue());

        $this->assertFalse(AnthropicAdapter::isFallbacksRejection(400, $message));
        $this->assertTrue(AnthropicAdapter::isFallbacksRejection(400, 'Unexpected value(s) `server-side-fallback-2026-07-01` for the `anthropic-beta` header.'));
        $this->assertTrue(AnthropicAdapter::isFallbacksRejection(400,
            'Unexpected value(s) `server-side-fallback-2026-07-01`, `thinking-display-updates-2026-08-18` for the `anthropic-beta` header.'));
        $this->assertTrue(AnthropicAdapter::isFallbacksRejection(400, 'fallbacks: Extra inputs are not permitted'));
    }

    public function testUnArretDemandeNExecutePlusAucunOutil(): void
    {
        // Stop pendant que l'entrée de l'outil arrive : aucun texte dans le
        // tour, donc aucun contrôle dans le flux — et celui de la boucle
        // venait après l'exécution.
        $adapter = $this->adapter([
            [200, self::sse(self::tourOutil('t1'))],
            [200, self::sse(self::tourFinal('Jamais.'))],
        ]);
        LlmAdapterHelpers::setStopProbe(static fn(): bool => true);

        $appels = 0;
        $this->jouer($adapter, static function (string $n, array $a) use (&$appels): array {
            $appels++;
            return ['ok' => true];
        });
        $this->assertSame(0, $appels, 'Ni lecture ni carte d’approbation sur une réponse coupée.');
        $this->assertCount(1, $adapter->bodies, 'Rien ne repart.');
    }

    public function testUnArretPendantLEcritureEcarteLesAppelsDejaRecus(): void
    {
        $adapter = $this->adapter([[200, self::sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'chercher', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"q":"x"}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Je regarde.']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 4]],
        ])]]);
        $stop = false;
        LlmAdapterHelpers::setStopProbe(static function () use (&$stop): bool { return $stop; });

        $appels = 0;
        $chunks = [];
        $adapter->streamWithTools(
            [['role' => 'user', 'content' => 'Bonjour']],
            'système',
            [['name' => 'chercher', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]],
            static function (string $c) use (&$chunks, &$stop): void { $chunks[] = $c; $stop = true; },
            static function (string $n, array $a) use (&$appels): array { $appels++; return []; }
        );
        $this->assertSame(['Je regarde.'], $chunks);
        $this->assertSame(0, $appels);
    }

    public function testLeTexteQuiSuitUnOutilOuvreUnParagraphe(): void
    {
        $adapter = $this->adapter([
            [200, self::sse([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'D’après vos fichiers, ']],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'je dois vérifier.']],
                ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'chercher', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 9]],
            ])],
            [200, self::sse(self::tourFinal('Voici le résumé.'))],
        ]);
        [$texte] = $this->jouer($adapter, static fn(string $n, array $a): array => ['ok' => true]);

        // Deux blocs de texte consécutifs (découpe des citations) : un seul
        // tenant. Après l'outil : un paragraphe.
        $this->assertSame("D’après vos fichiers, je dois vérifier.\n\nVoici le résumé.", $texte);
        $this->assertSame('D’après vos fichiers, je dois vérifier.', $adapter->bodies[1]['messages'][1]['content'][0]['text']
            . $adapter->bodies[1]['messages'][1]['content'][1]['text'], 'La séparation ne part qu’au client, jamais dans le fil rejoué.');
    }

    public function testLaRechercheWebSepareAussiLeTexteSansOutilClient(): void
    {
        $fichier = $this->dossier . '/web.sse';
        file_put_contents($fichier, self::sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Je vérifie le prix.']],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => []]],
            ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'web_search_tool_result', 'tool_use_id' => 's1', 'content' => []]],
            ['type' => 'content_block_start', 'index' => 3, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'text_delta', 'text' => 'Il est de 12 €.']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 9, 'server_tool_use' => ['web_search_requests' => 1]]],
        ]));
        $catalogue = require ROOT . '/config/llm_catalog.php';
        $catalogue['anthropic']['endpoints']['messages'] = $fichier;
        (new ReflectionProperty(LlmCatalog::class, 'cache'))->setValue(null, $catalogue);

        $chunks = [];
        $jetons = $this->adapter([])->stream(
            [['role' => 'user', 'content' => 'Quel prix ?']],
            'système',
            static function (string $c) use (&$chunks): void { $chunks[] = $c; },
            null,
            true
        );
        $this->assertSame(['Je vérifie le prix.', "\n\nIl est de 12 €."], $chunks);
        $this->assertSame(1, $jetons['web_searches']);
    }

    public function testUnNombreRepartTelQueLeModeleLAEcrit(): void
    {
        $adapter = $this->adapter([
            [200, self::sse([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'chercher', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"taille": 12.0}']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 4]],
            ])],
            [200, self::sse(self::tourFinal('Fait.'))],
        ]);
        $recu = null;
        $this->jouer($adapter, static function (string $n, array $a) use (&$recu): array { $recu = $a; return []; });

        $this->assertSame(['taille' => 12.0], $recu, 'L’outil reçoit toujours le tableau décodé.');
        $this->assertStringContainsString('"input":{"taille":12.0}', $adapter->raw[1], '`12.0`, pas `12` : le fil rejoué reste celui produit.');
    }

    /** @return array<string, array{string, string, string}> */
    public static function separations(): array
    {
        return [
            'rien avant'             => ['', 'Suite', ''],
            'phrase close'           => ['e.', 'Suite', "\n\n"],
            'un saut déjà là'        => ["e\n", 'Suite', "\n"],
            'deux sauts déjà là'     => ["\n\n", 'Suite', ''],
            'la suite en ouvre un'   => ['e.', "\nSuite", "\n"],
            'la suite en ouvre deux' => ['e.', "\n\nSuite", ''],
            'un de chaque côté'      => ["e\n", "\nSuite", ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('separations')]
    public function testLaSeparationNEmpileJamaisDeParagraphes(string $avant, string $suite, string $attendu): void
    {
        $this->assertSame($attendu, LlmAdapterHelpers::paragraphBreak($avant, $suite));
    }

    public function testSeulUnBlocDOutilOuvreUnParagraphe(): void
    {
        foreach (['tool_use', 'server_tool_use', 'mcp_tool_use', 'web_search_tool_result', 'web_fetch_tool_result'] as $type) {
            $this->assertTrue(AnthropicAdapter::isToolBlockType($type), $type);
        }
        foreach (['text', 'thinking', 'redacted_thinking', 'fallback', ''] as $type) {
            $this->assertFalse(AnthropicAdapter::isToolBlockType($type), $type === '' ? '(vide)' : $type);
        }
    }

    // ---------- Cache de prompt ----------

    public function testEnEagerLeFilEstMisEnCacheDesLePremierAller(): void
    {
        $adapter = $this->adapter([
            [200, self::sse(self::tourOutil('t1'))],
            [200, self::sse(self::tourFinal('Fini.'))],
        ]);
        $this->jouer($adapter, static fn(string $n, array $a): array => ['ok' => true], ['cache' => 'eager']);

        [$aller1, $aller2] = $adapter->bodies;
        $this->assertSame(['type' => 'ephemeral'], end($aller1['tools'])['cache_control'], 'Les définitions d’outils se relisent au message suivant.');
        $this->assertSame(['type' => 'ephemeral'], $aller1['messages'][0]['content'][0]['cache_control'],
            'Connecteurs engagés : la question est mise en cache dès le premier aller.');

        $dernier = $aller2['messages'][count($aller2['messages']) - 1];
        $this->assertSame('user', $dernier['role']);
        $this->assertSame('tool_result', end($dernier['content'])['type']);
        $this->assertSame(['type' => 'ephemeral'], end($dernier['content'])['cache_control'],
            'L’aller suivant relit tout ce qui précède et n’écrit que le nouveau tour.');
        $this->assertArrayNotHasKey('cache_control', $aller2['messages'][0]['content'][0], 'Un seul point de fin de fil par aller.');
        $this->assertSame([['type' => 'text', 'text' => 'Bonjour']], $aller2['messages'][0]['content'],
            'Même forme qu’au premier aller (bloc texte) : le préfixe se rend à l’identique.');
    }

    public function testEnLazyLaFinDuFilAttendLeSecondAller(): void
    {
        $adapter = $this->adapter([
            [200, self::sse(self::tourOutil('t1'))],
            [200, self::sse(self::tourFinal('Fini.'))],
        ]);
        [, $jetons] = $this->jouer($adapter, static fn(string $n, array $a): array => ['ok' => true]);
        $this->assertArrayNotHasKey('rounds_exhausted', $jetons, 'Réponse terminée d’elle-même.');

        [$aller1, $aller2] = $adapter->bodies;
        $this->assertArrayHasKey('cache_control', end($aller1['tools']));
        $this->assertStringNotContainsString('cache_control', json_encode($aller1['messages']),
            'Une réponse directe ne relirait rien : pas d’écriture payée pour rien.');
        $this->assertStringContainsString('cache_control', json_encode($aller2['messages']));
    }

    public function testAucunPointDeFinDeFilAuDernierAllerForce(): void
    {
        $reponses = [];
        for ($i = 1; $i <= LLMInterface::TOOL_MAX_ROUNDS; $i++) {
            $reponses[] = [200, self::sse(self::tourOutil('t' . $i))];
        }
        $adapter = $this->adapter($reponses);
        $n = 0;
        [, $jetons] = $this->jouer($adapter, static function (string $nom, array $a) use (&$n): array { return ['n' => ++$n]; }, ['cache' => 'eager']);

        $this->assertCount(LLMInterface::TOOL_MAX_ROUNDS, $adapter->bodies);
        $this->assertTrue($jetons['rounds_exhausted'] ?? false, 'Synthèse forcée : la demande peut rester inachevée, « Continuer » est proposé.');
        $final = $adapter->bodies[LLMInterface::TOOL_MAX_ROUNDS - 1];
        $this->assertSame(['type' => 'none'], $final['tool_choice']);
        $this->assertStringNotContainsString('cache_control', json_encode($final['messages']),
            'Son tool_choice diffère : le cache des messages y est invalidé, l’écriture ne serait jamais relue.');
        $this->assertArrayHasKey('cache_control', end($final['tools']), 'Celui des outils, lui, survit.');
    }

    public function testSansCacheRienNEstPose(): void
    {
        $adapter = $this->adapter([[200, self::sse(self::tourFinal('Oui.'))]]);
        $this->jouer($adapter, static fn(string $n, array $a): array => [], ['cache' => 'off']);
        $this->assertStringNotContainsString('cache_control', $adapter->raw[0]);
    }

    public function testLesPointsDeCacheEvitentLOutilServeurEtLeRaisonnement(): void
    {
        $payload = [
            'tools'    => [['type' => 'web_search_20260209', 'name' => 'web_search'], ['name' => 'a', 'input_schema' => []]],
            'messages' => [
                ['role' => 'user', 'content' => 'Question'],
                ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 's'], ['type' => 'tool_use', 'id' => 'x', 'name' => 'a', 'input' => new stdClass()]]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'x', 'content' => '{}'], ['type' => 'text', 'text' => '  ']]],
            ],
        ];
        $marque = AnthropicAdapter::withCacheBreakpoints($payload, 'lazy', 1);
        $this->assertArrayNotHasKey('cache_control', $marque['tools'][0], 'L’outil serveur est en tête, jamais marqué.');
        $this->assertArrayHasKey('cache_control', $marque['tools'][1]);
        $this->assertArrayNotHasKey('cache_control', $marque['messages'][1]['content'][0], 'Un raisonnement ne porte jamais de point.');
        $this->assertArrayHasKey('cache_control', $marque['messages'][2]['content'][0], 'Un bloc texte vide n’est pas éligible : le résultat d’outil l’est.');
        $this->assertArrayNotHasKey('cache_control', $marque['messages'][2]['content'][1]);

        $this->assertSame($payload, AnthropicAdapter::withCacheBreakpoints($payload, 'off', 3));
        $this->assertSame('lazy', AnthropicAdapter::cacheMode([]));
        $this->assertSame('lazy', AnthropicAdapter::cacheMode(['cache' => 'toujours']));
        $this->assertSame('eager', AnthropicAdapter::cacheMode(['cache' => 'eager']));
    }

    public function testUnePauseNEntamePasLeBudgetDAllersRetours(): void
    {
        $pause = self::sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '…']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'pause_turn'], 'usage' => ['output_tokens' => 1]],
        ]);
        $reponses = [[200, $pause]];
        for ($i = 1; $i <= LLMInterface::TOOL_MAX_ROUNDS; $i++) {
            $reponses[] = [200, self::sse(self::tourOutil('t' . $i))];
        }
        $adapter = $this->adapter($reponses);
        $this->jouer($adapter, static fn(string $n, array $a): array => ['ok' => true]);

        $this->assertCount(LLMInterface::TOOL_MAX_ROUNDS + 1, $adapter->bodies,
            'La pause est une limite du serveur, pas un appel d’outil : les huit allers-retours restent.');
    }

    public function testUnOutilActiveEnCoursDeReponsePartAuTourSuivantSansRaisonnementRejoue(): void
    {
        $tourAvecRaisonnement = self::sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1']],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'chercher', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"q":"x"}']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]],
        ]);
        $definition = static fn(string $nom): array => ['name' => $nom, 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]];

        // L'appel d'outil agrandit la liste (activer_connecteur) : le tour
        // suivant la porte, et le raisonnement du tour d'avant — lié à
        // l'ancienne liste — n'est plus rejoué.
        $outils = [$definition('chercher')];
        $adapter = $this->adapter([[200, $tourAvecRaisonnement], [200, self::sse(self::tourFinal('Fini.'))]]);
        $this->jouer($adapter, function (string $n, array $a) use (&$outils, $definition): array {
            $outils[] = $definition('lire');
            return ['ok' => true];
        }, ['tools_source' => function () use (&$outils): array { return $outils; }]);

        $this->assertSame(['chercher'], array_column($adapter->bodies[0]['tools'], 'name'));
        $this->assertSame(['chercher', 'lire'], array_column($adapter->bodies[1]['tools'], 'name'),
            'L’outil activé part dès l’aller suivant.');
        $messages = $adapter->bodies[1]['messages'];
        $this->assertSame(['tool_use'], array_column($messages[count($messages) - 2]['content'], 'type'),
            'Liste changée : forme d’avant, sans le raisonnement signé (l’API le refuserait).');

        // Liste inchangée : le raisonnement reste rejoué.
        $outils = [$definition('chercher')];
        $adapter = $this->adapter([[200, $tourAvecRaisonnement], [200, self::sse(self::tourFinal('Fini.'))]]);
        $this->jouer($adapter, static fn(string $n, array $a): array => ['ok' => true],
            ['tools_source' => function () use (&$outils): array { return $outils; }]);
        $messages = $adapter->bodies[1]['messages'];
        $this->assertSame(['thinking', 'tool_use'], array_column($messages[count($messages) - 2]['content'], 'type'));
    }

    // ------------------------------------------------------------------

    /** Un tour qui appelle l'outil `chercher`. */
    private static function tourOutil(string $id): array
    {
        return [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => 'chercher', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"q":"' . $id . '"}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]],
        ];
    }

    /** Un tour final, texte seul. */
    private static function tourFinal(string $texte): array
    {
        return [
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $texte]],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
        ];
    }

    /** @param list<array{0:int,1:string}> $reponses */
    private function adapter(array $reponses): AnthropicToolLoopDouble
    {
        $adapter = (new ReflectionClass(AnthropicToolLoopDouble::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AnthropicAdapter::class, 'apiKey'))->setValue($adapter, 'cle-factice');
        (new ReflectionProperty(AnthropicAdapter::class, 'model'))->setValue($adapter, 'claude-opus-5-5');
        $adapter->responses = $reponses;
        $adapter->dir = $this->dossier;
        return $adapter;
    }

    /** @return array{0:string,1:array} */
    private function jouer(AnthropicToolLoopDouble $adapter, callable $outil, array $options = []): array
    {
        $chunks = [];
        $jetons = $adapter->streamWithTools(
            [['role' => 'user', 'content' => 'Bonjour']],
            'système',
            [['name' => 'chercher', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]],
            static function (string $c) use (&$chunks): void { $chunks[] = $c; },
            $outil,
            null,
            false,
            $options
        );
        return [implode('', $chunks), $jetons];
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

/** Faux serveur : une réponse par tour, chaque requête gardée (décodée et brute). */
final class AnthropicToolLoopDouble extends AnthropicAdapter
{
    /** @var list<array{0:int,1:string}> */
    public array $responses = [];
    /** @var list<array> */
    public array $bodies = [];
    /** @var list<string> */
    public array $raw = [];
    public string $dir = '';

    protected function openRoundStream(array $payload, string $body): array
    {
        $this->bodies[] = json_decode($body, true);
        $this->raw[] = $body;
        [$status, $contenu] = array_shift($this->responses) ?? [500, '{"error":{"message":"plus de réponse"}}'];
        $fichier = $this->dir . '/tour-' . count($this->bodies) . '.sse';
        file_put_contents($fichier, $contenu);
        return [fopen($fichier, 'r'), $status];
    }
}
