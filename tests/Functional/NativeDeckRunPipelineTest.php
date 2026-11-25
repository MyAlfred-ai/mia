<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Carte de réalisation d'une « Version soignée », sur la vraie base : état
 * écrit dans le message d'attente, relu contre la file des jobs, et le VRAI
 * job (NativeDeckJob par JobQueue::execute, fournisseur factice) qui réécrit
 * ce même message — prêt, arrêté — ou le laisse « interrompu ».
 */
final class NativeDeckRunPipelineTest extends DatabaseTestCase
{
    private const USER = 4101;
    private const PPTX = "PK\x03\x04....ppt/presentation.xml....";

    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        // Hors de tests/bootstrap.php (le reste de la file n'y est pas requis).
        require_once ROOT . '/src/Helpers/BackgroundJob.php';
        require_once ROOT . '/src/Jobs/JobContext.php';
        require_once ROOT . '/src/Jobs/JobErrorException.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        I18n::use('fr');
        $this->insertUser(self::USER);
        ApiKey::save('openai', 'sk-test-factice');
        CompanyProfile::overrideTemplate(sys_get_temp_dir() . '/aucun-modele-' . bin2hex(random_bytes(4)) . '.pptx');
        $this->dir = sys_get_temp_dir() . '/mia-native-run-' . bin2hex(random_bytes(6));
        NativeDeckStore::overrideDir($this->dir);
        NativeDeck::overrideUploadRoot($this->dir . '/uploads');
    }

    protected function tearDown(): void
    {
        NativeDeck::overrideTransport(null);
        NativeDeck::overrideUploadRoot(null);
        NativeDeckStore::overrideDir(null);
        CompanyProfile::overrideTemplate(null);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM api_keys');
        }
        parent::tearDown();
    }

    /** Lancement tel que nativeDeckFromFile le fait : message d'attente, état, job. */
    private function launch(int $convId, string $title = 'Cap sur le drapeau !'): array
    {
        $msg   = Message::create($convId, 'assistant', '⏳ GPT-6.1 Sol réalise la présentation « ' . $title . ' »…');
        $msgId = (int)$msg['id'];
        Message::setRun($msgId, NativeDeckRun::start('pptx', 'openai::gpt-6.1-sol', 'GPT-6.1 Sol', $title, 0, time() - 30));
        $jobId = JobQueue::enqueue('native_deck', self::USER, $convId, [
            'kind' => 'pptx', 'llm' => 'openai::gpt-6.1-sol', 'label' => 'GPT-6.1 Sol', 'source' => "# $title\n\n## Un\n\n- point",
            'name' => 'cap.pptx', 'title' => $title, 'before' => 0, 'exclude' => [], 'company' => false, 'note' => '',
            'message_id' => $msgId,
        ], 'Présentation par GPT-6.1 Sol : ' . $title);
        Message::setRunJob($msgId, $jobId);

        return [$msgId, $jobId];
    }

    private function conversation(): int
    {
        return (int)Conversation::create(self::USER, null, 'openai::gpt-6.1-sol')['id'];
    }

    private function assistantMessages(int $convId): array
    {
        return array_values(array_filter(Message::forConversation($convId), static fn (array $m): bool => $m['role'] === 'assistant'));
    }

    private function doneEvent(int $jobId): array
    {
        foreach (JobQueue::eventPayloads($jobId) as $payload) {
            $event = is_array($payload) ? $payload : json_decode((string)$payload, true);
            if (!empty($event['done'])) {
                return $event;
            }
        }

        return [];
    }

    // ------------------------------------------------------------------

    public function testLEtatSeFusionneDansLEtatAgentDuMessage(): void
    {
        $convId = $this->conversation();
        $msg    = Message::create($convId, 'assistant', '⏳ …');
        Message::setSuggestion((int)$msg['id'], '');
        Message::setRun((int)$msg['id'], NativeDeckRun::start('xlsx', 'openai::gpt-6-luna', 'GPT-6 Luna', 'Budget', 0, 100));
        Message::setRunJob((int)$msg['id'], 77);

        $agent = Message::agentOf((array)Message::findById((int)$msg['id']));
        $this->assertSame('', $agent['suggestion'], 'Le reste de l’état est gardé.');
        $this->assertSame(77, $agent['progress']['job_id']);
        $this->assertSame('xlsx', $agent['progress']['kind']);

        $user = Message::create($convId, 'user', 'bonjour');
        Message::setRunJob((int)$user['id'], 5);
        $this->assertSame([], Message::agentOf((array)Message::findById((int)$user['id'])), 'Jamais sur un message de la personne.');
    }

    public function testLaCarteSuitLaFileDesJobs(): void
    {
        $convId = $this->conversation();
        [$msgId, $jobId] = $this->launch($convId);

        $views = NativeDeckRun::forMessages(Message::forConversation($convId), time());
        $this->assertSame('running', $views[$msgId]['status'], 'Job en file : la carte tourne.');
        $this->assertGreaterThanOrEqual(30, $views[$msgId]['elapsed'], 'Le temps écoulé vient du serveur.');

        $this->db()->prepare("UPDATE async_jobs SET status = 'failed' WHERE id = ?")->execute([$jobId]);
        $views = NativeDeckRun::forMessages(Message::forConversation($convId), time());
        $this->assertSame('failed', $views[$msgId]['status'], 'Job mort sans prévenir : la carte ne tourne plus.');
    }

    public function testLeFichierArriveDansLeMessageDAttente(): void
    {
        NativeDeck::overrideTransport(static function (string $method, string $url): array {
            if ($method === 'POST' && str_ends_with($url, '/v1/responses')) {
                return ['status' => 200, 'body' => (string)json_encode(['status' => 'completed', 'output' => [
                    ['type' => 'code_interpreter_call', 'container_id' => 'cntr_z'],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Fait.', 'annotations' => [
                        ['type' => 'container_file_citation', 'container_id' => 'cntr_z', 'file_id' => 'cfile_1', 'filename' => 'cap.pptx'],
                    ]]]],
                ], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 400]])];
            }
            if ($method === 'GET' && str_contains($url, '/containers/cntr_z/files/cfile_1/content')) {
                return ['status' => 200, 'body' => self::PPTX];
            }

            return ['status' => 200, 'body' => '{}'];
        });

        $convId = $this->conversation();
        [$msgId, $jobId] = $this->launch($convId);
        JobQueue::execute(JobQueue::find($jobId));

        $assistant = $this->assistantMessages($convId);
        $this->assertCount(1, $assistant, 'Plus de second message : le fichier arrive dans la carte.');
        $this->assertSame($msgId, (int)$assistant[0]['id']);
        $this->assertStringStartsWith('✅ ', (string)$assistant[0]['content'], 'Le texte reste la source de l’historique.');
        $this->assertMatchesRegularExpression('#\[cap\.pptx\]\(/chat/\d+/presentation/[0-9a-f]{32}\)#', (string)$assistant[0]['content']);

        $run = NativeDeckRun::of($assistant[0]);
        $this->assertSame('done', $run['status']);
        $this->assertSame('cap.pptx', $run['file']);
        $this->assertSame($jobId, (int)$run['job_id']);

        $done = $this->doneEvent($jobId);
        $this->assertSame($msgId, (int)$done['msg_id']);
        $this->assertStringContainsString('native-run is-done', (string)$done['run_html']);
        $this->assertStringContainsString('class="native-run-download" href="' . $run['url'] . '"', (string)$done['run_html']);

        $view = NativeDeckRun::forMessages(Message::forConversation($convId), time())[$msgId];
        $this->assertSame('done', $view['status']);
        $this->assertGreaterThan(0, (int)UsageLog::creditsByMessage($convId, self::USER)[$msgId], 'Les jetons sont comptés sur le message de la carte.');
    }

    public function testUnEchecLaisseLaCarteInterrompueEtLaCauseDessous(): void
    {
        NativeDeck::overrideTransport(static fn (): array => ['status' => 429, 'body' => (string)json_encode(['error' => [
            'type' => 'insufficient_quota', 'code' => 'insufficient_quota', 'message' => 'You exceeded your current quota.',
        ]])]);

        $convId = $this->conversation();
        [$msgId, $jobId] = $this->launch($convId);
        JobQueue::execute(JobQueue::find($jobId));

        $assistant = $this->assistantMessages($convId);
        $this->assertCount(2, $assistant, 'La carte, puis la cause posée par JobQueue.');
        $this->assertSame('failed', NativeDeckRun::of($assistant[0])['status']);
        $this->assertStringStartsWith('⚠', (string)$assistant[1]['content']);
        $this->assertSame('failed', NativeDeckRun::forMessages(Message::forConversation($convId), time())[$msgId]['status']);
    }
}
