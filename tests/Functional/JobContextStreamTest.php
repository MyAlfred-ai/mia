<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Le flux d'une réponse, écrit par le worker : fréquent, sans retenir le texte,
 * sans multiplier les écritures disque.
 */
final class JobContextStreamTest extends DatabaseTestCase
{
    private int $jobId = 0;

    public static function setUpBeforeClass(): void
    {
        require_once ROOT . '/src/Helpers/BackgroundJob.php';
        require_once ROOT . '/src/Jobs/JobContext.php';
        require_once ROOT . '/src/Jobs/JobErrorException.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->insertUser(1);
        $conv = (int)Conversation::create($user, null, 'openai')['id'];
        $this->jobId = JobQueue::enqueue('chat', $user, $conv, ['content' => 'x'], 'x');
    }

    private function context(): JobContext
    {
        return new JobContext($this->jobId, 1, null);
    }

    /** @return list<string> */
    private function chunks(): array
    {
        return array_values(array_filter(array_map(
            static fn(array $event): ?string => json_decode((string)$event['payload'], true)['chunk'] ?? null,
            JobQueue::eventsSince($this->jobId, 0)
        ), 'is_string'));
    }

    public function testLePremierMotPartTouteDeSuite(): void
    {
        $ctx = $this->context();
        $ctx->emit(['phase' => 'generating']); // pose l'horloge du tampon : aucun délai écoulé
        $ctx->emit(['chunk' => 'Bon']);
        $this->assertSame(['Bon'], $this->chunks(), 'C\'est lui qui met fin à l\'attente.');
    }

    public function testLaSuiteEstGroupeeEnPetitsPaquets(): void
    {
        $ctx = $this->context();
        $ctx->emit(['chunk' => 'A']);
        $ctx->emit(['chunk' => 'B']);
        $ctx->emit(['chunk' => 'C']);
        $this->assertSame(['A'], $this->chunks(), 'Pas une écriture par fragment.');
        usleep(70_000);
        $ctx->emit(['chunk' => 'D']);
        $this->assertSame(['A', 'BCD'], $this->chunks(), 'Moins de 80 ms entre deux paquets.');
    }

    public function testLaFinDuTamponNAttendPasLeFragmentSuivant(): void
    {
        $ctx = $this->context();
        $ctx->emit(['chunk' => 'A']);
        $ctx->emit(['chunk' => 'B']);
        usleep(70_000);
        $ctx->shouldStop();
        $this->assertSame(['A', 'B'], $this->chunks(), 'Le modèle écrit un outil : plus de texte, la phrase doit sortir quand même.');
    }

    public function testUnEvenementEcritEtLeCurseurAvancent(): void
    {
        $ctx = $this->context();
        $ctx->emit(['phase' => 'analyzing']);
        $ctx->emit(['phase' => 'generating']);
        $this->assertSame(2, (int)(JobQueue::liveStatus($this->jobId)['last_seq'] ?? 0));
        $this->assertFalse(Database::getInstance()->inTransaction(), 'Transaction refermée.');
    }

    public function testUneTransactionEnCoursEstRespectee(): void
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        $this->context()->emit(['phase' => 'analyzing']);
        $this->assertTrue($db->inTransaction(), 'Celle de l\'appelant reste ouverte (PDO n\'en imbrique pas).');
        $db->commit();
        $this->assertCount(1, JobQueue::eventsSince($this->jobId, 0));
    }

    public function testUnJobEnAttenteSeVoitSansVerrou(): void
    {
        $this->assertTrue(JobQueue::hasPending());
        Database::getInstance()->exec("UPDATE async_jobs SET status = 'done'");
        $this->assertFalse(JobQueue::hasPending());
    }
}
