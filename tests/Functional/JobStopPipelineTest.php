<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Bouton Stop d'une réponse, côté serveur, sur une vraie base :
 *   - une réponse encore EN FILE est close tout de suite (rien ne sera
 *     généré ni facturé, le message suivant n'attend pas) ;
 *   - une réponse EN COURS garde le repère de ce que l'écran montrait, quitte
 *     le tiroir des générations et n'est plus rattachée au rechargement ;
 *   - un VRAI tour de chat (worker, faux modèle) arrêté en plein flux
 *     enregistre la réponse telle qu'elle était affichée au clic — pas le
 *     texte produit entre le clic et le moment où le worker l'a vu.
 */
final class JobStopPipelineTest extends DatabaseTestCase
{
    private const UTILISATEUR = 51;
    private const AUTRE = 52;
    private const MARQUE = "*[Réponse interrompue par l'utilisateur]*";

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Jobs/JobContext.php';
        require_once ROOT . '/src/Jobs/ChatJob.php';
        require_once ROOT . '/src/Jobs/ChatSendJob.php';
        $this->insertUser(self::UTILISATEUR);
        $this->insertUser(self::AUTRE);
    }

    protected function tearDown(): void
    {
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
            ChatController::setStopTextSource(null);
        }
        LlmAdapterHelpers::setStopProbe(null);
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
        }
        parent::tearDown();
    }

    public function testUneReponseEnFileEstCloseToutDeSuite(): void
    {
        $conv = $this->conversation();
        $job = JobQueue::enqueue('chat', self::UTILISATEUR, $conv, ['content' => 'Bonjour']);

        $this->assertFalse(JobQueue::requestCancel($job, self::AUTRE, 4), 'seul son auteur l\'arrête');
        $this->assertTrue(JobQueue::requestCancel($job, self::UTILISATEUR, 0));

        $row = $this->job($job);
        $this->assertSame('cancelled', $row['status']);
        $this->assertNotNull($row['finished_at']);
        $this->assertNull(JobQueue::claimById($job, $blocked), 'un worker ne le prend plus');
        $this->assertSame([], JobQueue::activeForUser(self::UTILISATEUR));
        $this->assertFalse(JobQueue::requestCancel($job, self::UTILISATEUR), 'déjà clos');
    }

    public function testUneReponseEnCoursGardeLeRepereEtQuitteLeTiroir(): void
    {
        $conv = $this->conversation();
        $job = JobQueue::enqueue('chat', self::UTILISATEUR, $conv, ['content' => 'Bonjour']);
        $this->assertNotNull(JobQueue::claimById($job, $blocked));
        $this->assertCount(1, JobQueue::activeForUser(self::UTILISATEUR));

        $this->assertTrue(JobQueue::requestCancel($job, self::UTILISATEUR, 7));
        $row = $this->job($job);
        $this->assertSame('running', $row['status'], 'le worker la clôt à son prochain tick');
        $this->assertSame(1, (int)$row['cancel_requested']);
        $this->assertSame(7, (int)$row['cancel_seq']);
        $this->assertSame([], JobQueue::activeForUser(self::UTILISATEUR), 'l\'écran l\'a déjà finie : ni tiroir ni rattachement');

        // Un second arrêt (tiroir, double clic) ne déplace pas le repère.
        JobQueue::requestCancel($job, self::UTILISATEUR, 12);
        $this->assertSame(7, (int)$this->job($job)['cancel_seq']);
    }

    public function testLeTexteVuSeRelitDansLeFlux(): void
    {
        $conv = $this->conversation();
        $job = JobQueue::enqueue('chat', self::UTILISATEUR, $conv, ['content' => 'Bonjour']);
        $ctx = new JobContext($job, self::UTILISATEUR, $conv);
        $ctx->emit(['phase' => 'analyzing']);
        $ctx->emit(['chunk' => 'Un ']);
        $ctx->flush();
        $ctx->emit(['chunk' => 'deux ']);
        $ctx->flush();
        $seen = (int)$this->job($job)['last_seq'];
        $ctx->emit(['chunk' => 'trois ']);
        $ctx->flush();

        $this->assertSame('Un deux ', JobQueue::textSeenUpTo($job, $seen));
        $this->assertSame('Un deux trois ', JobQueue::textSeenUpTo($job, $seen + 1));
    }

    public function testUnVraiTourArreteEnregistreLaReponseAffichee(): void
    {
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        LlmCatalog::setToday('2026-10-14'); // o3 : routé Chat Completions, servi à cette date
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        StopMidStream::install(['Un ', 'deux ', 'trois ', 'quatre ', 'cinq.'], 2, self::UTILISATEUR);

        $conv = $this->conversation();
        $q = Message::create($conv, 'user', 'Compte jusqu\'à cinq');
        $job = JobQueue::enqueue('chat', self::UTILISATEUR, $conv, [
            'content' => 'Compte jusqu\'à cinq', 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false,
        ]);
        StopMidStream::$jobId = $job;

        JobQueue::runById($job);

        $row = $this->job($job);
        $this->assertSame('cancelled', $row['status']);
        $this->assertGreaterThan(0, StopMidStream::$seenSeq, 'l\'arrêt a été demandé en plein flux');
        $this->assertStringContainsString('trois', implode('', array_map(
            static fn(string $p): string => (string)(json_decode($p, true)['chunk'] ?? ''),
            JobQueue::eventPayloads($job)
        )), 'le modèle a écrit au-delà du clic avant que le worker ne le voie');

        $reply = $this->db()->query("SELECT content FROM messages WHERE conversation_id = $conv AND role = 'assistant' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame("Un deux\n\n" . self::MARQUE, $reply, 'la réponse enregistrée est celle qui était affichée au clic');
        $aborted = array_values(array_filter(
            array_map(static fn(string $p): array => json_decode($p, true) ?: [], JobQueue::eventPayloads($job)),
            static fn(array $e): bool => !empty($e['aborted'])
        ));
        $this->assertCount(1, $aborted, 'l\'écran reçoit l\'identifiant de la réponse interrompue');
        $this->assertGreaterThan(0, (int)$aborted[0]['msg_id']);
    }

    private function conversation(): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([self::UTILISATEUR, 'Conversation', 'openai::o3']);
        return (int)$this->db()->lastInsertId();
    }

    private function job(int $id): array
    {
        return $this->db()->query('SELECT * FROM async_jobs WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    }
}

/**
 * Faux modèle qui écrit lentement (une ligne toutes les 70 ms : chaque
 * fragment part dans son propre évènement) et dont la personne clique Stop
 * pendant qu'il écrit : juste avant le fragment n° `$stopAt`, l'arrêt est
 * demandé avec le dernier évènement que l'écran avait reçu, puis le modèle
 * continue d'écrire — le temps que le worker voie la demande (≥ 500 ms).
 */
final class StopMidStream
{
    public const SCHEME = 'miastopmodele';

    public static int $jobId = 0;
    public static int $seenSeq = 0;
    private static array $chunks = [];
    private static int $stopAt = 0;
    private static int $userId = 0;

    /** @var resource|null posé par PHP */
    public $context;

    /** @var list<string> */
    private array $lines = [];
    private int $line = 0;

    public static function install(array $chunks, int $stopAt, int $userId): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$chunks  = $chunks;
        self::$stopAt  = $stopAt;
        self::$userId  = $userId;
        self::$seenSeq = 0;
        $cache     = new ReflectionProperty(LlmCatalog::class, 'cache');
        $catalogue = $cache->getValue() ?? require ROOT . '/config/llm_catalog.php';
        $catalogue['openai']['endpoints']['chat_completions'] = self::SCHEME . '://openai';
        $cache->setValue(null, $catalogue);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        foreach (self::$chunks as $text) {
            $this->lines[] = 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n";
        }
        $this->lines[] = 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n";
        $this->lines[] = 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n";
        $this->lines[] = "data: [DONE]\n";
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->line >= count($this->lines)) {
            return '';
        }
        usleep(70_000);
        if ($this->line === self::$stopAt && self::$jobId > 0) {
            self::$seenSeq = (int)Database::getInstance()
                ->query('SELECT last_seq FROM async_jobs WHERE id = ' . self::$jobId)->fetchColumn();
            JobQueue::requestCancel(self::$jobId, self::$userId, self::$seenSeq);
            usleep(600_000);
        }
        return $this->lines[$this->line++];
    }

    public function stream_eof(): bool
    {
        return $this->line >= count($this->lines);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_stat(): array|false
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
