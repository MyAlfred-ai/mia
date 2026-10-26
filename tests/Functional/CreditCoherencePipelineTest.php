<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Crédits cohérents à tous les niveaux, sur base réelle : un VRAI tour de chat
 * (`runSend`, vrai adaptateur rejoué depuis un fichier local), le vrai grand
 * livre, les vraies estimations.
 *
 *   - le badge d'une réponse affiché en direct = celui du rechargement, et il
 *     compte tout le tour (mémoire, titre…) ;
 *   - le total de la conversation = la somme de ce qui a été débité ;
 *   - une estimation lit la consommation réelle de SON modèle.
 */
final class CreditCoherencePipelineTest extends DatabaseTestCase
{
    private const UTILISATEUR = 21;

    private ?string $dossier = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';

        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        // o3 : le modèle OpenAI routé en Chat Completions (le format rejoué ici).
        LlmCatalog::setToday('2026-10-14');
        CreditEstimate::forget();

        $this->insertUser(self::UTILISATEUR);
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);

        $this->dossier = sys_get_temp_dir() . '/mia-credits-' . bin2hex(random_bytes(6));
        mkdir($this->dossier);
        ChatController::setEmitSink(static function (array $e): void {});
    }

    protected function tearDown(): void
    {
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
        }
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        CreditEstimate::forget();
        if ($this->dossier !== null) {
            foreach (glob($this->dossier . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dossier);
        }
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------

    public function testLeBadgeEnDirectEstCeluiDuRechargementEtCompteToutLeTour(): void
    {
        $this->modele('C\'est noté, je garderai des réponses courtes.');
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([self::UTILISATEUR, 'Nouvelle conversation', 'openai::o3']);
        $conv = (int)$this->db()->lastInsertId();
        $texte = 'Souviens-toi que je préfère les réponses courtes';
        $question = Message::create($conv, 'user', $texte);

        $r = (new ChatController())->runSend(
            ['content' => $texte, 'llm' => 'openai::o3', 'user_msg_id' => (int)$question['id'], 'needs_title' => true],
            self::UTILISATEUR,
            $conv
        );

        $this->assertTrue($r['done'] ?? false);
        $msg = (int)$r['msg_id'];
        // Le tour a payé : la réponse, le juge de la mémoire et le titre.
        $sources = $this->db()->query("SELECT source, message_id FROM usage_logs WHERE conversation_id = $conv ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertContains('chat', array_column($sources, 'source'));
        $this->assertContains('memory', array_column($sources, 'source'));
        $this->assertContains('title', array_column($sources, 'source'));
        foreach ($sources as $row) {
            $this->assertSame($msg, (int)$row['message_id'], 'Ligne « ' . $row['source'] . ' » rattachée à la réponse.');
        }

        // Ce que le navigateur affiche en direct = ce que le rechargement retrouve.
        $this->assertSame(UsageLog::creditsByMessage($conv, self::UTILISATEUR)[$msg] ?? null, $r['credits']);
        $this->assertSame(UsageLog::conversationCreditsTotal($conv, self::UTILISATEUR), $r['conversation_credits_total'], 'Titre compris.');
        $this->assertSame($r['credits'], $r['conversation_credits_total'], 'Un seul tour : badge = total.');
    }

    public function testLeRattachementNePrendQueLeTourDeCetteConversation(): void
    {
        $conv  = $this->conversation();
        $autre = $this->conversation();
        $avant = $this->ligne($conv, 'title', 3);          // tour précédent : pas rattaché
        $debut = UsageLog::lastId();
        $kb    = $this->ligne($conv, 'kb_query', 1);
        $memo  = $this->ligne($conv, 'memory', 2);
        $suite = $this->ligne($conv, 'follow_up', 4);      // demandée après coup
        $voisin = $this->ligne($autre, 'kb_query', 5);     // une autre conversation
        $rep = Message::create($conv, 'assistant', 'Réponse', [], 'openai', 'o3');
        $chat = $this->ligne($conv, 'chat', 30, (int)$rep['id']);

        UsageLog::attachTurn($conv, self::UTILISATEUR, (int)$rep['id'], $debut);

        $rattache = fn(int $id): ?int => ($v = $this->db()->query("SELECT message_id FROM usage_logs WHERE id = $id")->fetchColumn()) === null ? null : (int)$v;
        $this->assertSame((int)$rep['id'], $rattache($kb));
        $this->assertSame((int)$rep['id'], $rattache($memo));
        $this->assertNull($rattache($avant));
        $this->assertNull($rattache($suite));
        $this->assertNull($rattache($voisin));
        $this->assertSame(33, UsageLog::creditsForMessage((int)$rep['id']));
        $this->assertSame(1 + 2 + 3 + 4 + 30, UsageLog::conversationCreditsTotal($conv, self::UTILISATEUR), 'Le total compte tout ce qui a été débité.');
        $this->assertGreaterThan(0, $chat);
    }

    public function testLEstimationLitLaConsommationReelleDeSonModele(): void
    {
        $conv = $this->conversation();
        for ($i = 0; $i < 6; $i++) {
            $this->ligne($conv, 'chat', 40, null, 'openai::gpt-5.6', 18000 + $i * 100, 900);
            $this->ligne($conv, 'chat', 10, null, 'openai::gpt-6-luna', 3000, 300);
        }
        CreditEstimate::forget();
        $sol = CreditEstimate::chatReply('openai::gpt-5.6');
        $this->assertSame('model', $sol['basis']);
        $this->assertSame(6, $sol['samples']);
        $this->assertSame((int)CreditService::computeLlmCredits('openai::gpt-5.6', 18250, 900)['total_credits'], $sol['credits']);
        $this->assertSame((int)CreditService::computeLlmCredits('openai::gpt-6-luna', 3000, 300)['total_credits'], CreditEstimate::chatReply('openai::gpt-6-luna')['credits']);

        // Un workflow : l'étape coûte une réponse de SON modèle (la moyenne de
        // tous les modèles mélangés n'était, avant, jamais celle de personne).
        $choix = ['llms' => ['openai::gpt-5.6' => 'GPT-5.6', 'openai::gpt-6-luna' => 'Luna'], 'default_llm' => 'openai::gpt-6-luna', 'profiles' => []];
        $def = ['nodes' => [['type' => WorkflowSpec::TYPE_STEP, 'key' => 'e1', 'llm' => 'openai::gpt-5.6', 'sources' => []]]];
        $this->assertSame(
            ['min' => (int)floor($sol['credits'] * 0.6), 'max' => (int)ceil($sol['credits'] * 1.6)],
            WorkflowCatalog::estimate($def, $choix)
        );
        $def['nodes'][0]['llm'] = 'openai::gpt-6-luna';
        $luna = CreditEstimate::chatReply('openai::gpt-6-luna')['credits'];
        $this->assertSame(['min' => max(1, (int)floor($luna * 0.6)), 'max' => max(1, (int)ceil($luna * 1.6))], WorkflowCatalog::estimate($def, $choix));
    }

    public function testLeCoutDUnOutilSuitSesGenerationsReelles(): void
    {
        $this->db()->prepare("INSERT INTO chat_tools (name, type, provider, model, is_active) VALUES ('Visuels', 'image', 'openai', 'gpt-image-2.5-flare', 1)")->execute();
        $tool = ChatTool::findById((int)$this->db()->lastInsertId());
        $this->assertSame('tariff', CreditEstimate::toolUse($tool)['basis']);
        foreach ([40, 50, 60] as $c) {
            $this->db()->prepare("INSERT INTO tool_usage_logs (user_id, tool_id, tool_type, source, credits_used, quantity_units, cost_eur, priced) VALUES (?, ?, 'image', 'chat', ?, 1, 0.03, 1)")
                ->execute([self::UTILISATEUR, (int)$tool['id'], $c]);
        }
        // Les tests d'outils ne disent rien du coût en usage.
        $this->db()->prepare("INSERT INTO tool_usage_logs (user_id, tool_id, tool_type, source, credits_used, quantity_units, cost_eur, priced) VALUES (?, ?, 'image', 'tool_test', 900, 1, 0.5, 1)")
            ->execute([self::UTILISATEUR, (int)$tool['id']]);
        CreditEstimate::forget();
        $this->assertSame(['credits' => 50, 'basis' => 'history', 'samples' => 3], CreditEstimate::toolUse($tool));
    }

    /**
     * Une réponse appuyée sur Internet s'écrit sur deux lignes (réponse,
     * recherches) ; leur somme est l'opération réglée UNE fois — jamais deux
     * arrondis ni deux minimums.
     */
    public function testUneReponseEtSesRecherchesFontUneSeuleOperation(): void
    {
        $conv = $this->conversation();
        $rep  = Message::create($conv, 'assistant', 'Réponse', [], 'openai', 'gpt-6-luna');
        $r = UsageMeter::llm('openai::gpt-6-luna', ['input' => 900, 'output' => 50, 'web_searches' => 2], UsageSource::CHAT, [
            'user_id' => self::UTILISATEUR, 'conversation_id' => $conv, 'message_id' => (int)$rep['id'],
        ]);

        $lignes = $this->db()->query('SELECT source, credits_used, quantity FROM usage_logs WHERE message_id = ' . (int)$rep['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['chat', 'web_search'], array_column($lignes, 'source'));
        $this->assertSame($r['total_credits'], array_sum(array_map('intval', array_column($lignes, 'credits_used'))));
        $this->assertSame($r['total_credits'], UsageLog::creditsForMessage((int)$rep['id']), 'Le badge de la réponse.');

        $chat = CreditService::computeLlmCredits('openai::gpt-6-luna', 900, 50);
        $ws   = CreditService::computeWebSearchCredits('openai::gpt-6-luna', 2);
        $this->assertSame(
            max(1, (int)ceil(($chat['billed_eur'] + $ws['billed_eur']) / CreditService::globalConfig()['credit_value_eur'] - 1e-9)),
            $r['total_credits']
        );
    }

    // ------------------------------------------------------------------

    private function conversation(): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([self::UTILISATEUR, 'Crédits', 'openai::o3']);
        return (int)$this->db()->lastInsertId();
    }

    private function ligne(int $conv, string $source, int $credits, ?int $message = null, string $llm = 'openai::o3', int $in = 100, int $out = 10): int
    {
        return UsageLog::insertLlm([
            'user_id' => self::UTILISATEUR, 'conversation_id' => $conv, 'message_id' => $message,
            'llm_provider' => $llm, 'model' => explode('::', $llm)[1], 'tokens_input' => $in, 'tokens_output' => $out,
            'credits_used' => $credits, 'cost_eur' => 0.001, 'source' => $source,
        ]);
    }

    /** Le modèle répond ce texte (flux Chat Completions rejoué depuis un fichier). */
    private function modele(string $texte): void
    {
        $fichier = $this->dossier . '/openai.sse';
        file_put_contents($fichier,
            'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $texte], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 1800, 'completion_tokens' => 40]]) . "\n\n"
            . "data: [DONE]\n\n");
        $cache = new ReflectionProperty(LlmCatalog::class, 'cache');
        $catalogue = $cache->getValue() ?? require ROOT . '/config/llm_catalog.php';
        $catalogue['openai']['endpoints']['chat_completions'] = $fichier;
        $cache->setValue(null, $catalogue);
    }
}
