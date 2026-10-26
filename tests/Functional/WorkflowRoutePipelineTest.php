<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Décision de MIA et filtres, sur la vraie base : la décision choisit une
 * voie (le modèle est remplacé par WorkflowAi::overrideCompletion), se
 * replie, demande à la personne, arrête ; les filtres d'une étape partent
 * remplis dans la charge de son tour. Même méthode que
 * WorkflowAutomationPipelineTest : la fin d'un job d'étape est SIMULÉE.
 */
final class WorkflowRoutePipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, 'default_llm', 'job_worker_heartbeat_at'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save([
            'provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'),
            'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0,
        ]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
        JobQueue::markWorkerAlive();
        $this->userId = $this->insertUser(7, 'creator');
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        WorkflowAi::overrideCompletion(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, (string)($valeur ?? ''));
            }
        }
        parent::tearDown();
    }

    private function publie(array $def): array
    {
        $id = Workflow::create($this->userId, 'Tri', WorkflowSpec::normalize($def));
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), ['demande' => 'Combien pour 3 licences ?', 'mail' => 'paul@client.fr'], [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        return $res;
    }

    private function ligne(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    private function payload(int $jobId): array
    {
        return json_decode((string)$this->ligne('SELECT payload_json FROM async_jobs WHERE id = ?', [$jobId])['payload_json'], true);
    }

    private function dernierJob(int $runId): int
    {
        $step = $this->ligne("SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND job_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$runId]);
        $this->assertNotSame([], $step);
        return (int)$step['job_id'];
    }

    private function messages(int $convId): array
    {
        $stmt = $this->db()->prepare('SELECT content FROM messages WHERE conversation_id = ? ORDER BY id');
        $stmt->execute([$convId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Une décision à trois voies : « Devis » et « Question » continuent, « Spam » arrête. */
    private static function triage(string $fallback = 'ask'): array
    {
        return [
            'start' => ['fields' => [
                ['key' => 'demande', 'label' => 'Demande', 'type' => 'longtext', 'required' => true],
                ['key' => 'mail', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ]],
            'nodes' => [
                ['key' => 'tri', 'type' => 'route', 'title' => 'Quel type de demande ?', 'instruction' => 'Classe @{depart}.', 'fallback' => $fallback,
                    'routes' => [
                        ['key' => 'devis', 'label' => 'Devis', 'when' => 'Une demande de prix.', 'then' => 'continue', 'nodes' => [
                            ['key' => 'chiffrer', 'type' => 'step', 'title' => 'Chiffrer', 'instruction' => 'Chiffre @{depart}.',
                                'sources' => ['gmail'], 'filters' => ['mail' => ['from' => '@{depart.mail}', 'days' => 30, 'attachment' => true]],
                                'output' => ['key' => 'offre', 'name' => 'Offre', 'form' => 'text']],
                        ]],
                        ['key' => 'question', 'label' => 'Question', 'when' => 'Une question simple.', 'then' => 'continue', 'nodes' => []],
                        ['key' => 'spam', 'label' => 'Spam', 'when' => 'Rien à traiter.', 'then' => 'stop', 'nodes' => []],
                    ]],
                ['key' => 'noter', 'type' => 'notify', 'title' => 'Noter', 'message' => 'Traité : @{depart.demande}'],
            ],
            'end' => ['pieces' => []],
        ];
    }

    public function testMiaChoisitUneVoieEtLeRailLaSuit(): void
    {
        $vu = [];
        WorkflowAi::overrideCompletion(static function (string $system, string $user) use (&$vu): string {
            $vu[] = $user;
            return '{"voie": 1, "raison": "Le client demande un prix."}';
        });
        $res = $this->lance($this->publie(self::triage()));
        $run = WorkflowRun::find($res['run_id']);
        $job = $this->dernierJob($run['id']);
        $this->assertSame('route', $this->payload($job)['op'], 'La décision part dans un job `workflow`.');

        $out = WorkflowEngine::runRouteJob($this->payload($job));
        $this->assertSame(['outcome' => 'devis'], $out);
        $this->assertStringContainsString('1. Devis — Une demande de prix.', $vu[0], 'MIA reçoit les voies, numérotées, avec leur description.');
        $this->assertStringContainsString('Combien pour 3 licences', $vu[0], 'Et les pièces citées.');
        $step = WorkflowRun::lastStep($run['id'], 'tri');
        $this->assertSame(['done', 'devis'], [$step['status'], $step['outcome']]);
        $this->assertSame('Le client demande un prix.', $step['detail']['reason']);

        // La voie choisie démarre : son étape porte ses filtres, REMPLIS.
        $etape = $this->dernierJob($run['id']);
        $p = $this->payload($etape);
        $this->assertSame('chat', $this->ligne('SELECT type FROM async_jobs WHERE id = ?', [$etape])['type']);
        $this->assertSame('paul@client.fr', $p['workflow']['filters']['mail']['from'], 'Le champ cité devient sa valeur.');
        $this->assertSame([30, true], [$p['workflow']['filters']['mail']['days'], $p['workflow']['filters']['mail']['attachment']]);
        $this->assertStringContainsString('PÉRIMÈTRE IMPOSÉ', $p['workflow']['prompt'], 'Le modèle sait que ses recherches sont filtrées.');
        $this->assertTrue($p['gmail_access'] ?? false);

        $msgs = implode("\n", $this->messages((int)$run['conversation_id']));
        $this->assertStringContainsString('Décision — Quel type de demande ?', $msgs);
        $this->assertStringContainsString('« Devis »', $msgs);
    }

    public function testUneVoieVideQuiArreteTermineLeWorkflow(): void
    {
        WorkflowAi::overrideCompletion(static fn(): string => '{"voie": 3, "raison": "Publicité."}');
        $res = $this->lance($this->publie(self::triage()));
        $run = WorkflowRun::find($res['run_id']);
        WorkflowEngine::runRouteJob($this->payload($this->dernierJob($run['id'])));
        $run = WorkflowRun::find($run['id']);
        $this->assertSame(WorkflowRun::STOPPED, $run['status']);
        $this->assertStringContainsString('voie « Spam » de « Quel type de demande ? »', implode("\n", $this->messages((int)$run['conversation_id'])));
    }

    public function testSansChoixLaVoieDeRepliEstPrise(): void
    {
        WorkflowAi::overrideCompletion(static fn(): string => '{"voie": "aucune", "plus_proche": 2, "raison": "Ambigu."}');
        $res = $this->lance($this->publie(self::triage('question')));
        $run = WorkflowRun::find($res['run_id']);
        WorkflowEngine::runRouteJob($this->payload($this->dernierJob($run['id'])));
        $step = WorkflowRun::lastStep($run['id'], 'tri');
        $this->assertSame(['done', 'question', true], [$step['status'], $step['outcome'], $step['detail']['fallback'] ?? false]);
        $this->assertStringContainsString('voie de repli', implode("\n", $this->messages((int)$run['conversation_id'])));
        // Voie vide qui continue : la brique suivante du rail est passée (prévenir).
        $this->assertNotSame([], WorkflowRun::lastStep($run['id'], 'noter') ?? []);
    }

    public function testMiaHesiteLaPersonneChoisitLaVoie(): void
    {
        WorkflowAi::overrideCompletion(static fn(): string => '{"voie": "aucune", "plus_proche": 2, "raison": "Ni prix ni question claire."}');
        $res = $this->lance($this->publie(self::triage()));
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(['waiting' => true], WorkflowEngine::runRouteJob($this->payload($this->dernierJob($run['id']))));
        $run = WorkflowRun::find($run['id']);
        $this->assertSame(WorkflowRun::WAITING, $run['status']);
        $step = WorkflowRun::lastStep($run['id'], 'tri');
        $this->assertSame(['waiting', 'question'], [$step['status'], $step['detail']['suggested']]);
        $this->assertNotNull($step['pending_action_id'], 'Une carte attend la personne dans la conversation.');

        // La page de l'exécution : elle choisit « Devis », pas la suggestion.
        $jobs = WorkflowEngine::gateResolved($run, $step, 'route:devis');
        $this->assertCount(1, $jobs, 'L’étape de la voie choisie démarre.');
        $this->assertSame('devis', WorkflowRun::lastStep($run['id'], 'tri')['outcome']);
        $this->assertSame('person', WorkflowRun::lastStep($run['id'], 'tri')['detail']['decided_by']);
        $this->assertSame([], WorkflowEngine::gateResolved($run, $step, 'route:devis'), 'Une décision ne se prend qu’une fois.');
    }

    public function testLaCarteDuChatPrendLaVoieSuggereeOuArrete(): void
    {
        WorkflowAi::overrideCompletion(static fn(): string => '{"voie": "aucune", "plus_proche": 2, "raison": "?"}');
        $res = $this->lance($this->publie(self::triage()));
        $run = WorkflowRun::find($res['run_id']);
        WorkflowEngine::runRouteJob($this->payload($this->dernierJob($run['id'])));
        $step = WorkflowRun::lastStep($run['id'], 'tri');
        WorkflowEngine::gateResolved(WorkflowRun::find($run['id']), $step, 'approved');
        $this->assertSame('question', WorkflowRun::lastStep($run['id'], 'tri')['outcome'], 'Approuver la carte : la voie la plus proche.');

        $res2 = $this->lance($this->publie(self::triage()));
        $run2 = WorkflowRun::find($res2['run_id']);
        WorkflowEngine::runRouteJob($this->payload($this->dernierJob($run2['id'])));
        WorkflowEngine::gateResolved(WorkflowRun::find($run2['id']), WorkflowRun::lastStep($run2['id'], 'tri'), 'stopped');
        $this->assertSame(WorkflowRun::CANCELLED, WorkflowRun::find($run2['id'])['status'], 'Annuler la carte : le workflow s’arrête.');
    }

    public function testUneReponseIllisibleEstUnEchecReprenable(): void
    {
        WorkflowAi::overrideCompletion(static fn(): string => 'Je dirais plutôt un devis.');
        $wf = self::triage();
        $wf['nodes'][0]['on_error'] = ['retries' => 0];
        $res = $this->lance($this->publie($wf));
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(['failed' => true], WorkflowEngine::runRouteJob($this->payload($this->dernierJob($run['id']))));
        $run = WorkflowRun::find($run['id']);
        $this->assertSame(WorkflowRun::FAILED, $run['status'], 'Aucune voie devinée : l’exécution s’arrête, reprenable.');

        WorkflowAi::overrideCompletion(static fn(): string => '{"voie": 2, "raison": "Question."}');
        $jobs = WorkflowEngine::retry($run);
        $this->assertCount(1, $jobs, 'La reprise repose la question.');
        $this->assertSame(['outcome' => 'question'], WorkflowEngine::runRouteJob($this->payload($jobs[0])));
    }
}
