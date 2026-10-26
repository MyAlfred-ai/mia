<?php

declare(strict_types=1);

/**
 * Un tour de chat RÉEL quand le modèle échoue — `ChatController::runSend` de
 * bout en bout : base de test, vrais adaptateurs, flux du fournisseur rejoué
 * depuis un fichier local (le catalogue redirige l'adresse de l'API).
 *
 * Ce que la conversation doit montrer, en direct (évènements du flux) ET au
 * retour (message enregistré) :
 *   - la cause de la panne, nommée, quand rien n'a été écrit ;
 *   - une note sous le texte quand la réponse avait commencé ;
 *   - le modèle de secours, et pourquoi il a répondu.
 * Jamais le message brut du fournisseur.
 */
final class LlmErrorChatFlowTest extends DatabaseTestCase
{
    private const UTILISATEUR = 7;

    private ?string $dossier = null;
    private mixed $secoursAvant = null;
    private string|false $journalAvant = false;
    /** @var list<array> évènements émis vers le navigateur */
    private array $evenements = [];

    protected function setUp(): void
    {
        parent::setUp();

        // ChatController dépend de vendor/, il n'est pas dans tests/bootstrap.php.
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';

        // api_keys et llm_models ne font pas partie des tables vidées par le socle.
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        $this->secoursAvant = Settings::get('default_llm_fallback');
        Settings::set('default_llm_fallback', '');

        // o3, seul modèle OpenAI routé en Chat Completions (le faux serveur
        // rejoue ce format), est arrêté par OpenAI le 11/12/2026 : passé cette
        // date, il quitterait LLMFactory::available() et le test avec lui.
        LlmCatalog::setToday('2026-10-14');

        $this->insertUser(self::UTILISATEUR);
        ApiKey::save('openai', 'sk-test-factice');
        ApiKey::save('anthropic', 'sk-ant-test-factice');
        $this->activer('openai', 'o3', 'o3');
        $this->activer('anthropic', 'claude-opus-5', 'Claude Opus 5');

        $this->dossier = sys_get_temp_dir() . '/mia-chat-' . bin2hex(random_bytes(6));
        mkdir($this->dossier);
        $this->journalAvant = ini_get('error_log');
        ini_set('error_log', $this->dossier . '/journal.log');

        $this->evenements = [];
        ChatController::setEmitSink(function (array $e): void { $this->evenements[] = $e; });

        // Incidents de compte fournisseur : la VRAIE table `settings` ici (les
        // tests unitaires les gardent en mémoire), partie de zéro.
        Settings::set(ProviderIncident::SETTING, '');
        ProviderIncident::useSettingsStore();
    }

    protected function tearDown(): void
    {
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
        }
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        I18n::use('fr');
        if ($this->dossier !== null) {
            ini_set('error_log', $this->journalAvant === false ? '' : $this->journalAvant);
            foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }
        // Base absente : le socle a sauté le test dans setUp(), mais PHPUnit
        // appelle tearDown() quand même — rien à remettre en état.
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            Settings::set('default_llm_fallback', $this->secoursAvant);
            Settings::set(ProviderIncident::SETTING, '');
        }
        ProviderIncident::useMemoryStore();
        parent::tearDown();
    }

    // ------------------------------------------------------------------

    public function testUnCompteSansCreditEstDitAinsiEtLeMessageGardeSonTitre(): void
    {
        $this->openai(self::corpsErreur('You exceeded your current quota, please check your plan and billing details.', 'insufficient_quota'));

        $resultat = $this->envoyer('openai::o3');

        $erreur = $this->evenement('llm_error');
        $this->assertSame('quota', $erreur['kind']);
        $this->assertSame('Crédit épuisé chez OpenAI', $erreur['title']);
        // L'encadré proposera « Prévenir un administrateur ».
        $this->assertTrue($erreur['contact_admin']);
        $this->assertTrue($resultat['error']);
        $this->assertNull($resultat['llm_badge'], 'Rien n\'a été généré : pas de badge de modèle.');

        // Au retour dans la conversation : le titre nomme toujours la cause.
        $message = $this->derniereReponse();
        $this->assertStringStartsWith("⚠️ Crédit épuisé chez OpenAI\n\nLe crédit de votre organisation chez OpenAI est épuisé", $message['content']);
        $this->assertStringContainsString('Prévenez un administrateur', $message['content']);

        // L'administration le sait : constat persisté, point à régler sur les clés API.
        Settings::refresh();
        $constat = ProviderIncident::active();
        $this->assertSame('quota', $constat['openai']['kind'] ?? null);
        $this->assertSame(1, $constat['openai']['count']);
        $points = array_column(AdminAttention::providerIncidentSignals($constat, time()), null, 'id');
        $this->assertSame('llms-keys', $points['incident_openai']['entry'] ?? null);

        $this->assertRienDeBrut();
    }

    public function testLeSecoursRepondEtDitPourquoi(): void
    {
        Settings::set('default_llm_fallback', 'anthropic::claude-opus-5');
        $this->openai(self::corpsErreur('You exceeded your current quota, please check your plan and billing details.', 'insufficient_quota'));
        $this->anthropic([
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 25]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Réponse du secours.']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 6]],
        ]);

        $resultat = $this->envoyer('openai::o3');

        $bascule = $this->evenement('llm_fallback');
        $this->assertSame('o3', $bascule['from_label']);
        $this->assertSame('Claude Opus 5', $bascule['to_label']);
        // Le titre présenté de la panne — avant, le chunk de l'adaptateur partait tel quel.
        $this->assertSame('Crédit épuisé chez OpenAI', $bascule['reason']);
        $this->assertSame('quota', $bascule['kind']);

        $this->assertFalse($resultat['error']);
        $message = $this->derniereReponse();
        $this->assertSame('Réponse du secours.', $message['content']);
        $this->assertSame('anthropic', $message['llm_provider']);

        // Le toast disparaît ; la trace, elle, reste au-dessus de la réponse.
        $trace = json_decode((string)$message['actions_json'], true);
        $this->assertSame('relais_secours', $trace[0]['t'] ?? null);
        $this->assertSame('o3 → Claude Opus 5 · Crédit épuisé chez OpenAI', $trace[0]['d'] ?? null);

        $this->assertArrayNotHasKey('llm_error', array_merge(...$this->evenements));
        $this->assertRienDeBrut();

        // Le secours a masqué la panne pour l'utilisateur, pas pour l'administration.
        Settings::refresh();
        $this->assertSame('quota', ProviderIncident::active()['openai']['kind'] ?? null);
    }

    public function testUneConversationTropLongueNePartPasSurLeSecours(): void
    {
        Settings::set('default_llm_fallback', 'anthropic::claude-opus-5');
        $this->openai(self::corpsErreur("This model's maximum context length is 128000 tokens. However, your messages resulted in 130812 tokens.", 'context_length_exceeded'));

        $this->envoyer('openai::o3');

        $this->assertSame('context', $this->evenement('llm_error')['kind']);
        $this->assertArrayNotHasKey('llm_fallback', array_merge(...$this->evenements), 'Un autre modèle refuserait pareil.');
        $this->assertStringStartsWith("⚠️ Trop de contenu pour ce modèle\n\n", $this->derniereReponse()['content']);
        $this->assertRienDeBrut();
    }

    public function testUnePanneEnCoursDeReponseLaisseUneNoteSousLeTexte(): void
    {
        $this->openai(self::sse([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Voici le début de la réponse'], 'finish_reason' => null]]],
            ['error' => ['message' => 'The server had an error while processing your request. Sorry about that! (request req_abc123)', 'type' => 'server_error']],
        ]));

        $resultat = $this->envoyer('openai::o3');

        $attendu = "Voici le début de la réponse\n\n*[Réponse interrompue : OpenAI a rencontré un incident technique]*";
        $this->assertSame($attendu, $this->derniereReponse()['content']);
        // En direct : le texte, puis la note — jamais le message du fournisseur.
        $this->assertSame($attendu, implode('', array_column($this->evenements, 'chunk')));
        $this->assertArrayNotHasKey('llm_error', array_merge(...$this->evenements), 'Une réponse commencée ne devient pas un bloc d\'erreur.');
        $this->assertFalse($resultat['error']);
        $this->assertRienDeBrut();
    }

    public function testUneReponseCoupeeParLaLongueurProposeDeContinuer(): void
    {
        $this->openai(self::sse([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Une longue réponse qui s\'arrête'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'length']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 4096]],
        ]));

        $resultat = $this->envoyer('openai::o3');

        $contenu = $this->derniereReponse()['content'];
        $this->assertSame(
            "Une longue réponse qui s'arrête\n\n*[Réponse interrompue : le modèle a atteint sa longueur maximale de réponse — écrivez « continue » pour obtenir la suite]*",
            $contenu
        );
        $this->assertFalse($resultat['error']);
        $this->assertSame(4096, (int)$resultat['tokens']['output'], 'Une réponse coupée reste facturée.');
    }

    public function testLePlafondEpuiseParLaReflexionNestPlusUneReponseVide(): void
    {
        $this->openai(self::sse([
            ['choices' => [['index' => 0, 'delta' => ['reasoning_content' => '…'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'length']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 4096]],
        ]));

        $this->envoyer('openai::o3');

        $erreur = $this->evenement('llm_error');
        $this->assertSame('truncated', $erreur['kind']);
        $this->assertSame('Limite de longueur atteinte', $erreur['title']);
        $this->assertStringStartsWith("⚠️ Limite de longueur atteinte\n\n", $this->derniereReponse()['content']);
    }

    public function testUneReponseNormaleNePorteAucuneNote(): void
    {
        // Un ancien constat « crédit épuisé » : la réponse qui aboutit l'éteint.
        ProviderIncident::note(['kind' => 'quota', 'provider' => 'openai'], time() - 600);
        Settings::refresh();
        $this->assertArrayHasKey('openai', ProviderIncident::active());

        $this->openai(self::sse([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Bonjour !'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3]],
        ]));

        $resultat = $this->envoyer('openai::o3');

        $this->assertSame('Bonjour !', $this->derniereReponse()['content']);
        $this->assertFalse($resultat['error']);
        $this->assertNotNull($resultat['llm_badge']);

        Settings::refresh();
        $this->assertSame([], ProviderIncident::active(), 'Le compte répond : le constat s\'éteint.');
    }

    public function testLAnglophoneLitLaCauseDansSaLangue(): void
    {
        I18n::use('en');
        $this->openai(self::corpsErreur('You exceeded your current quota, please check your plan and billing details.', 'insufficient_quota'));

        $this->envoyer('openai::o3');

        $this->assertSame('Out of credit at OpenAI', $this->evenement('llm_error')['title']);
        $this->assertStringStartsWith("⚠️ Out of credit at OpenAI\n\nYour organisation has run out of credit with OpenAI", $this->derniereReponse()['content']);
    }

    // ------------------------------------------------------------------

    /** Envoie « Bonjour » dans une conversation neuve ; rend le résultat du tour. */
    private function envoyer(string $llm): array
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')
            ->execute([self::UTILISATEUR, 'Harnais — erreurs de modèle']);
        $conv = (int)$this->db()->lastInsertId();
        $question = Message::create($conv, 'user', 'Bonjour');

        return (new ChatController())->runSend(
            ['content' => 'Bonjour', 'llm' => $llm, 'user_msg_id' => (int)$question['id']],
            self::UTILISATEUR,
            $conv
        );
    }

    /** Charge utile du premier évènement de ce nom. */
    private function evenement(string $nom): array
    {
        foreach ($this->evenements as $e) {
            if (isset($e[$nom])) {
                return $e[$nom];
            }
        }
        $this->fail("Évènement « $nom » absent du flux : " . json_encode($this->evenements, JSON_UNESCAPED_UNICODE));
    }

    private function derniereReponse(): array
    {
        $ligne = $this->db()->query("SELECT * FROM messages WHERE role = 'assistant' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($ligne, 'Aucune réponse enregistrée.');
        return $ligne;
    }

    /** Ni le flux ni la base ne portent le message du fournisseur. */
    private function assertRienDeBrut(): void
    {
        $sortie = json_encode($this->evenements, JSON_UNESCAPED_UNICODE) . $this->derniereReponse()['content'];
        foreach (['exceeded your current quota', 'billing details', 'maximum context length', 'Sorry about that', 'req_abc123', 'insufficient_quota', 'server_error'] as $brut) {
            $this->assertStringNotContainsString($brut, $sortie);
        }
    }

    private function openai(string $flux): void
    {
        $this->rediriger('openai', 'chat_completions', $flux);
    }

    private function anthropic(array $evenements): void
    {
        $this->rediriger('anthropic', 'messages', self::sse($evenements, false));
    }

    /** Redirige l'adresse d'un fournisseur vers un fichier local portant ce flux. */
    private function rediriger(string $fournisseur, string $endpoint, string $flux): void
    {
        $fichier = $this->dossier . '/' . $fournisseur . '.sse';
        file_put_contents($fichier, $flux);

        $cache = new ReflectionProperty(LlmCatalog::class, 'cache');
        $catalogue = $cache->getValue() ?? require ROOT . '/config/llm_catalog.php';
        $catalogue[$fournisseur]['endpoints'][$endpoint] = $fichier;
        $cache->setValue(null, $catalogue);
    }

    /** Flux SSE : un évènement par bloc `data:`. */
    private static function sse(array $evenements, bool $done = true): string
    {
        $sse = '';
        foreach ($evenements as $e) {
            $sse .= 'data: ' . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        return $sse . ($done ? "data: [DONE]\n\n" : '');
    }

    /** Corps d'erreur JSON, tel qu'un fournisseur le renvoie à la place du flux. */
    private static function corpsErreur(string $message, string $code): string
    {
        return json_encode(['error' => ['message' => $message, 'type' => $code, 'param' => null, 'code' => $code]]) . "\n";
    }

    private function activer(string $provider, string $model, string $label): void
    {
        LlmModel::save([
            'provider'     => $provider,
            'model'        => $model,
            'display_name' => $label,
            'is_active'    => 1,
            'sort_order'   => 0,
        ]);
    }
}
