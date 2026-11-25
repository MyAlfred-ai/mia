<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * La brique Slack dans le vrai moteur : une brique qui LIT part sans aucun
 * outil, avec sa source dans la charge, et remet sa pièce ; une brique qui
 * PUBLIE part avec sa destination remplie (le champ cité devient sa valeur)
 * et ses pièces jointes désignées par leur clé au dossier, que l'exécution
 * de la carte refabrique en fichiers.
 *
 * Comme WorkflowEnginePipelineTest : la fin d'un tour est SIMULÉE (réponse
 * écrite, puis onJobFinished) ; aucun appel à Slack.
 */
final class WorkflowSlackPipelineTest extends DatabaseTestCase
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
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, (string)($valeur ?? ''));
            }
        }
        parent::tearDown();
    }

    private function payloadOf(int $jobId): array
    {
        $stmt = $this->db()->prepare('SELECT payload_json FROM async_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        return (array)json_decode((string)$stmt->fetchColumn(), true);
    }

    private function repond(int $jobId, string $reponse): void
    {
        $stmt = $this->db()->prepare('SELECT conversation_id FROM async_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $msg = Message::create((int)$stmt->fetchColumn(), 'assistant', $reponse);
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
    }

    private function jobEnCours(int $runId): int
    {
        $stmt = $this->db()->prepare("SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND status = 'running' AND job_id IS NOT NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$runId]);
        $id = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, 'Une brique tourne.');
        return $id;
    }

    public function testLireRemetSaPieceEtPublierPartAvecSaDestinationEtSesFichiers(): void
    {
        $def = WorkflowSpec::normalize([
            'objective' => 'Le point de la semaine.',
            'start' => ['fiche_name' => 'Contact', 'fields' => [
                ['key' => 'mail', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ]],
            'nodes' => [
                ['key' => 'lire', 'type' => 'action', 'title' => 'Lire Slack', 'target' => 'slack',
                    'slack' => ['mode' => 'read', 'where' => 'channel', 'channel' => '#projets', 'days' => 30],
                    'output' => ['key' => 'echanges', 'name' => 'Échanges', 'form' => 'text']],
                ['key' => 'synth', 'type' => 'step', 'title' => 'Synthèse', 'instruction' => 'Résume @{echanges}.',
                    'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'text']],
                ['key' => 'publier', 'type' => 'action', 'title' => 'Publier dans Slack', 'target' => 'slack',
                    'slack' => ['mode' => 'post', 'where' => 'person', 'person' => '@{depart.mail}', 'attach' => ['synthese']],
                    'instruction' => 'Préviens que la synthèse est prête.'],
            ],
            'end' => ['pieces' => ['synthese']],
        ]);
        $id = Workflow::create($this->userId, 'Point Slack', $def);
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $res = WorkflowEngine::launch(Workflow::findById($id), User::findById($this->userId), ['mail' => 'marie@exemple.fr'], [], 'manual', null, 'Europe/Paris');
        $runId = $res['run_id'];

        // LIRE : aucun outil, la source dans la charge, lecture seule.
        $lire = $this->payloadOf($res['job_ids'][0]);
        $this->assertSame(['run_id' => $runId, 'mode' => 'read', 'where' => 'channel', 'channel' => '#projets', 'days' => 30], $lire['workflow']['slack']);
        $this->assertFalse($lire['slack_access'], 'Les messages sont lus par le serveur, pas par un outil.');
        $this->assertSame(WorkflowSpec::WRITE_NONE, $lire['workflow']['write']);
        $this->assertStringContainsString('BRIQUE SLACK — LIRE', $lire['workflow']['prompt']);
        $this->assertStringNotContainsString('Cette brique est une ACTION', $lire['workflow']['prompt']);

        $this->repond($res['job_ids'][0], 'Décision : livrer vendredi. Paul s’en charge.');
        $art = WorkflowRun::artifacts($runId);
        $this->assertStringContainsString('livrer vendredi', $art['echanges']['content']['text']);
        $this->assertSame('lire', $art['echanges']['producer'] ?? WorkflowSpec::pieces($def)['echanges']['producer']);

        // L'étape suivante lit la pièce remise.
        $synth = $this->jobEnCours($runId);
        $this->assertStringContainsString('livrer vendredi', $this->payloadOf($synth)['workflow']['prompt']);
        $this->repond($synth, 'La synthèse : livraison vendredi, Paul pilote.');

        // PUBLIER : la destination remplie, les fichiers désignés, l'outil Slack seul.
        $pubJob = $this->jobEnCours($runId);
        $pub = $this->payloadOf($pubJob);
        $this->assertTrue($pub['slack_access']);
        $this->assertSame('post', $pub['workflow']['slack']['mode']);
        $this->assertSame('person', $pub['workflow']['slack']['where']);
        $this->assertSame('marie@exemple.fr', $pub['workflow']['slack']['person']);
        $this->assertSame([['run_id' => $runId, 'piece' => 'synthese', 'name' => 'Synthèse']], $pub['workflow']['slack']['attach']);
        $this->assertStringContainsString('BRIQUE SLACK — PUBLIER', $pub['workflow']['prompt']);
        $this->assertStringContainsString('jointe en fichier au message', $pub['workflow']['prompt']);
        $this->assertStringNotContainsString('livraison vendredi, Paul pilote', $pub['workflow']['prompt'], 'Une pièce seulement jointe n’est pas montrée au modèle.');

        // L'exécution de la carte refabrique les fichiers depuis le dossier de CETTE exécution, une fois chacun.
        $user = User::findById($this->userId);
        $ref = ['run_id' => $runId, 'piece' => 'synthese', 'name' => 'Synthèse'];
        if (FileLibrary::has(\PhpOffice\PhpWord\PhpWord::class)) {
            $files = WorkflowDeliveries::attachmentsFor([WorkflowDeliveries::ATTACH_KEY => [$ref, $ref]], $user, (int)$res['conversation_id'], WorkflowDeliveries::FOR_SLACK);
            $this->assertCount(1, $files);
            $this->assertSame('Synthèse.docx', $files[0]['name']);
            $this->assertStringStartsWith('PK', $files[0]['bytes'], 'Un vrai document Word.');
        }
        // Une autre exécution ne prête pas ses fichiers.
        $this->assertSame([], WorkflowDeliveries::attachmentsFor([WorkflowDeliveries::ATTACH_KEY => [['run_id' => $runId + 999, 'piece' => 'synthese']]], $user, (int)$res['conversation_id'], WorkflowDeliveries::FOR_SLACK));

        // Sans carte déposée (rien n'a été préparé), la brique se termine.
        $this->repond($pubJob, 'Message préparé.');
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($runId)['status']);
    }
}
