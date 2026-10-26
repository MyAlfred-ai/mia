<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Moteur des workflows sur la vraie base — cf. CLAUDE.md « Workflows ».
 *
 * Un tour de chat n'est jamais exécuté ici : la fin d'un job d'étape est
 * SIMULÉE (réponse écrite dans la conversation, puis onJobFinished), ce que
 * fait JobQueue::finish() en production. Le worker est déclaré vivant pour
 * que le moteur n'essaie pas de lancer lui-même les jobs suivants.
 */
final class WorkflowEnginePipelineTest extends DatabaseTestCase
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
        foreach ([Workflow::SETTING_ENABLED, Workflow::SETTING_AUTO, 'default_llm', 'connector_web_enabled', 'job_worker_heartbeat_at'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        Settings::set('connector_web_enabled', '1');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save([
            'provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'),
            'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0,
        ]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
        // Worker « vivant » : JobQueue::dispatch() ne lance rien (aucun fork).
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

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    /** Montant (départ) → synthèse → si montant > 5 000, validation → livrable. */
    private static function definition(string $thenNo = 'continue'): array
    {
        return WorkflowSpec::normalize([
            'objective' => 'Une synthèse validée.',
            'start' => ['fiche_name' => 'Contexte', 'fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true],
            ]],
            'nodes' => [
                ['key' => 'synth', 'type' => 'step', 'title' => 'Synthétiser', 'instruction' => 'Résume @{depart}.',
                    'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'text']],
                ['key' => 'seuil', 'type' => 'condition', 'title' => 'Gros montant ?', 'mode' => 'rule',
                    'rule' => ['join' => 'all', 'items' => [['piece' => 'depart', 'field' => 'montant', 'op' => 'gt', 'value' => '5000']]],
                    'yes' => ['nodes' => [['key' => 'accord', 'type' => 'gate', 'title' => 'Accord', 'reviews' => ['synthese']]], 'then' => 'continue'],
                    'no' => ['nodes' => [], 'then' => $thenNo]],
            ],
            'end' => ['pieces' => ['synthese']],
        ]);
    }

    private function publie(array $def, array $extra = []): array
    {
        $id = Workflow::create($this->userId, 'Synthèse validée', $def);
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf, array $values): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), $values, [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        return $res;
    }

    /** Simule la fin du job de l'étape en cours : la réponse du modèle, puis la clôture. */
    private function repond(int $jobId, string $reponse): int
    {
        $job = $this->ligne('SELECT conversation_id FROM async_jobs WHERE id = ?', [$jobId]);
        $msg = Message::create((int)$job['conversation_id'], 'assistant', $reponse);
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
        return (int)$msg['id'];
    }

    private function ligne(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    private function nombre(string $sql, array $params = []): int
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function jobEnCours(int $runId): int
    {
        $step = $this->ligne("SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND status = 'running' AND job_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$runId]);
        $this->assertNotSame([], $step, 'Une étape tourne.');
        return (int)$step['job_id'];
    }

    // ------------------------------------------------------------------
    // Scénarios
    // ------------------------------------------------------------------

    public function testDuLancementAuLivrableEnPassantParLaValidation(): void
    {
        $res = $this->lance($this->publie(self::definition()), ['client' => 'Ville de Lyon', 'montant' => '6 200']);
        $runId = $res['run_id'];
        $this->assertCount(1, $res['job_ids'], 'La première étape part en job de chat.');

        // Le job est un tour de chat ordinaire, porteur du bloc `workflow`.
        $job = $this->ligne('SELECT * FROM async_jobs WHERE id = ?', [$res['job_ids'][0]]);
        $this->assertSame('chat', $job['type']);
        $payload = json_decode((string)$job['payload_json'], true);
        $this->assertSame($runId, $payload['workflow']['run_id']);
        $this->assertSame('synth', $payload['workflow']['node_key']);
        $this->assertStringContainsString('Ville de Lyon', $payload['workflow']['prompt'], 'La Fiche de départ est donnée à l’étape.');
        $this->assertSame((int)$res['conversation_id'], (int)$job['conversation_id']);

        // La Fiche de départ est au dossier, typée.
        $depart = WorkflowRun::artifacts($runId)['depart'];
        $this->assertSame(6200, $depart['content']['fields']['montant']);

        // Fin de l'étape → pièce remise → règle (6 200 > 5 000) → validation.
        $this->repond($res['job_ids'][0], 'Le marché porte sur la rénovation de trois écoles.');
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::WAITING, $run['status']);
        $this->assertSame('accord', $run['current_key']);
        $this->assertStringContainsString('trois écoles', WorkflowRun::artifacts($runId)['synthese']['content']['text']);
        $cond = WorkflowRun::lastStep($runId, 'seuil');
        $this->assertSame('yes', $cond['outcome']);
        $gate = WorkflowRun::lastStep($runId, 'accord');
        $this->assertSame(WorkflowRun::STEP_WAITING, $gate['status']);
        $carte = $this->ligne('SELECT * FROM drive_pending_actions WHERE id = ?', [$gate['pending_action_id']]);
        $this->assertSame(WorkflowEngine::GATE_TOOL, $carte['tool']);
        $this->assertSame('pending', $carte['status']);

        // Approuver : la carte est réservée puis résolue, le rail repart jusqu'au livrable.
        $this->assertNotNull(DrivePendingAction::claim((int)$carte['id'], $this->userId, (int)$res['conversation_id']));
        DrivePendingAction::resolve((int)$carte['id'], 'executed');
        $this->assertSame([], WorkflowEngine::gateResolved($run, $gate, 'approved'));
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertGreaterThan(0, $this->nombre(
            "SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND role = 'assistant'", [$res['conversation_id']]
        ));
    }

    public function testLesMessagesEcritsAuNomDeLaPersonneSontEnTexteSimple(): void
    {
        $res = $this->lance($this->publie(self::definition()), ['client' => 'Ville de Lyon', 'montant' => '6 200']);
        $stmt = $this->db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND role = 'user' ORDER BY id");
        $stmt->execute([$res['conversation_id']]);
        $messages = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(2, $messages, 'Le lancement, puis la première étape.');
        [$lancement, $etape] = $messages;
        $this->assertSame("▶ Synthèse validée — Lancement\n\nUne synthèse validée.\n\n- Client : Ville de Lyon\n- Montant : 6\u{202F}200", $lancement);
        $this->assertSame("Étape — Synthétiser\n\nRésume « Contexte ».", $etape);
        // Le message de l'étape est aussi la consigne envoyée au modèle.
        $job = $this->ligne('SELECT payload_json FROM async_jobs WHERE id = ?', [$res['job_ids'][0]]);
        $this->assertSame($etape, json_decode((string)$job['payload_json'], true)['content']);
    }

    public function testUneFinDeJobRejoueeNAvancePasDeuxFois(): void
    {
        $res = $this->lance($this->publie(self::definition()), ['client' => 'A', 'montant' => '9000']);
        $jobId = $res['job_ids'][0];
        $msgId = $this->repond($jobId, 'Synthèse.');
        // Le reaper ou un double appel rejoue la même fin : rien ne bouge.
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => $msgId], null);
        $this->assertSame(1, $this->nombre("SELECT COUNT(*) FROM workflow_run_steps WHERE run_id = ? AND node_key = 'accord'", [$res['run_id']]));
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM drive_pending_actions'));
    }

    public function testLaVoieNonPeutArreterLeWorkflow(): void
    {
        $res = $this->lance($this->publie(self::definition('stop')), ['client' => 'B', 'montant' => '1200']);
        $this->repond($res['job_ids'][0], 'Petite synthèse.');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::STOPPED, $run['status']);
        $this->assertSame('no', WorkflowRun::lastStep($res['run_id'], 'seuil')['outcome']);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM drive_pending_actions'), 'Aucune validation sur la voie « non ».');
    }

    /** La voie « non » finit par la brique « Arrêter le workflow ». */
    private static function definitionAvecArret(string $outcome, string $title, string $message): array
    {
        $def = self::definition();
        // Sa propre suite (« reprendre le rail ») ne compte plus : la brique arrête.
        $def['nodes'][1]['no'] = ['nodes' => [['key' => 'fin', 'type' => 'stop', 'title' => $title, 'message' => $message, 'outcome' => $outcome]], 'then' => 'continue'];
        return WorkflowSpec::normalize($def);
    }

    public function testLaBriqueArreterTermineProprementLExecution(): void
    {
        $res = $this->lance($this->publie(self::definitionAvecArret('done', 'Classée sans accord', 'Rien à valider pour @{depart.client}.')), ['client' => 'Durand', 'montant' => '1200']);
        $this->repond($res['job_ids'][0], 'Petite synthèse.');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::DONE, $run['status'], 'Statut « Terminée » : une fin prévue.');
        $this->assertSame('fin', $run['current_key']);
        $step = WorkflowRun::lastStep($res['run_id'], 'fin');
        $this->assertSame([WorkflowRun::STEP_DONE, WorkflowSpec::STOP_DONE], [$step['status'], $step['outcome']]);
        $this->assertSame('Rien à valider pour Durand.', $step['detail']['reason'] ?? null, 'Le champ cité devient sa valeur.');
        $this->assertNull(WorkflowRun::lastStep($res['run_id'], WorkflowSpec::END_KEY), 'Ni livrable ni livraison.');
        $this->assertNull(WorkflowRun::lastStep($res['run_id'], 'accord'));
        $dernier = $this->ligne("SELECT content FROM messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 1", [$res['conversation_id']]);
        $this->assertSame("⏹ Le workflow se termine ici : « Classée sans accord ».\n\nRien à valider pour Durand.", $dernier['content']);
    }

    public function testLaBriqueArreterPeutMarquerLExecutionArretee(): void
    {
        $res = $this->lance($this->publie(self::definitionAvecArret('stopped', 'Arrêter le workflow', '')), ['client' => 'Martin', 'montant' => '800']);
        $this->repond($res['job_ids'][0], 'Petite synthèse.');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::STOPPED, $run['status']);
        $this->assertSame(WorkflowSpec::STOP_STOPPED, WorkflowRun::lastStep($res['run_id'], 'fin')['outcome']);
        $dernier = $this->ligne("SELECT content FROM messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 1", [$res['conversation_id']]);
        $this->assertSame('⏹ Le workflow s’arrête ici.', $dernier['content'], 'Titre par défaut, sans message : une phrase courte.');
    }

    public function testUnePieceMalFormeeEstRedemandeeUneFoisPuisEchoue(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true]]],
            'nodes' => [['key' => 'liste', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'Liste les idées sur @{depart}.',
                'output' => ['key' => 'idees', 'name' => 'Idées', 'form' => 'list']]],
            'end' => ['pieces' => ['idees']],
        ]);
        $res = $this->lance($this->publie($def), ['sujet' => 'Veille']);
        $this->repond($res['job_ids'][0], 'Aucune puce ici, juste du texte.');

        // Seconde tentative : un nouveau job, avec la raison du refus dans la consigne.
        $second = $this->jobEnCours($res['run_id']);
        $this->assertNotSame($res['job_ids'][0], $second);
        $payload = json_decode((string)$this->ligne('SELECT payload_json FROM async_jobs WHERE id = ?', [$second])['payload_json'], true);
        $this->assertStringContainsString('puce', $payload['workflow']['prompt']);

        $this->repond($second, 'Toujours pas de liste.');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);
        $this->assertContains($run['status'], WorkflowRun::RESUMABLE, 'Une exécution en échec se reprend.');

        // Reprendre à l'étape : un nouveau passage, et cette fois la liste est là.
        $jobs = WorkflowEngine::retry($run);
        $this->assertCount(1, $jobs);
        $this->repond($jobs[0], "- Une idée\n- Une autre");
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($res['run_id'])['status']);
        $this->assertSame(['Une idée', 'Une autre'], WorkflowRun::artifacts($res['run_id'])['idees']['content']['items']);
    }

    public function testRenvoyerUneEtapeAvecUneRemarque(): void
    {
        $res = $this->lance($this->publie(self::definition()), ['client' => 'C', 'montant' => '8000']);
        $this->repond($res['job_ids'][0], 'Version 1.');
        $run = WorkflowRun::find($res['run_id']);
        $gate = WorkflowRun::lastStep($res['run_id'], 'accord');
        DrivePendingAction::claim((int)$gate['pending_action_id'], $this->userId, (int)$res['conversation_id']);
        DrivePendingAction::resolve((int)$gate['pending_action_id'], 'cancelled');

        $jobs = WorkflowEngine::gateResolved($run, $gate, 'returned', 'Ajoute le budget.');
        $this->assertCount(1, $jobs, 'L’étape relue repart.');
        $payload = json_decode((string)$this->ligne('SELECT payload_json FROM async_jobs WHERE id = ?', [$jobs[0]])['payload_json'], true);
        $this->assertSame('synth', $payload['workflow']['node_key']);
        $this->assertStringContainsString('Ajoute le budget.', $payload['workflow']['prompt']);

        $this->repond($jobs[0], 'Version 2, avec le budget.');
        $this->assertSame(2, WorkflowRun::artifacts($res['run_id'])['synthese']['version'], 'Nouvelle version de la pièce.');
        $this->assertSame(WorkflowRun::WAITING, WorkflowRun::find($res['run_id'])['status'], 'La validation se rouvre.');
    }

    /**
     * Constaté en essai réel (clé refusée pendant une étape renvoyée) : la
     * cause affichée était « le modèle n'a pas pu répondre », et la reprise
     * repartait SANS la remarque — la pièce refaite ignorait la demande.
     */
    public function testUneEtapeRenvoyeeQuiEchoueRepartAvecSaRemarqueEtSaCause(): void
    {
        $res = $this->lance($this->publie(self::definition()), ['client' => 'C', 'montant' => '8000']);
        $this->repond($res['job_ids'][0], 'Version 1.');
        $run = WorkflowRun::find($res['run_id']);
        $gate = WorkflowRun::lastStep($res['run_id'], 'accord');
        DrivePendingAction::claim((int)$gate['pending_action_id'], $this->userId, (int)$res['conversation_id']);
        DrivePendingAction::resolve((int)$gate['pending_action_id'], 'cancelled');
        $jobs = WorkflowEngine::gateResolved($run, $gate, 'returned', 'Ajoute le budget.');

        // Le tour échoue côté modèle : job « done » portant `error`, message ⚠️ persisté.
        $job = $this->ligne('SELECT conversation_id FROM async_jobs WHERE id = ?', [$jobs[0]]);
        $msg = Message::create((int)$job['conversation_id'], 'assistant', LlmErrorPresenter::toPlainText(['title' => 'Accès au service refusé', 'message' => 'La clé est refusée.']));
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobs[0]]);
        WorkflowEngine::onJobFinished($jobs[0], 'done', ['done' => true, 'msg_id' => (int)$msg['id'], 'error' => true], null);

        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);
        $this->assertSame('Accès au service refusé', $run['error']['title'] ?? null, 'La cause présentée est reprise du message ⚠️.');
        $this->assertSame('La clé est refusée.', $run['error']['message']);

        // Deux reprises (la première échoue encore) : la remarque suit jusqu'au bout.
        foreach ([1, 2] as $essai) {
            $reprise = WorkflowEngine::retry(WorkflowRun::find($res['run_id']));
            $this->assertCount(1, $reprise);
            $payload = json_decode((string)$this->ligne('SELECT payload_json FROM async_jobs WHERE id = ?', [$reprise[0]])['payload_json'], true);
            $this->assertStringContainsString('Ajoute le budget.', $payload['workflow']['prompt'], "Reprise $essai : la remarque est rendue au modèle.");
            if ($essai === 1) {
                $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$reprise[0]]);
                WorkflowEngine::onJobFinished($reprise[0], 'done', ['done' => true, 'msg_id' => (int)$msg['id'], 'error' => true], null);
            }
        }
        $this->repond($reprise[0], 'Version 2, avec le budget.');
        $this->assertSame(WorkflowRun::WAITING, WorkflowRun::find($res['run_id'])['status']);
        $steps = WorkflowRun::steps($res['run_id']);
        $this->assertSame('', WorkflowEngine::pendingRemark($run['definition'], $steps, 'synth'), 'Un passage abouti consomme la remarque.');
    }

    public function testLaCauseDUnTourEnErreurSeLitDansSonMessage(): void
    {
        $this->assertSame(
            ['kind' => 'model', 'title' => 'Crédit épuisé chez OpenAI', 'message' => "Le crédit est épuisé.\n\nPrévenez un administrateur."],
            WorkflowEngine::errorFromReply("⚠️ Crédit épuisé chez OpenAI\n\nLe crédit est épuisé.\n\nPrévenez un administrateur.")
        );
        $this->assertSame(['kind' => 'model', 'title' => '', 'message' => 'Une erreur est survenue.'], WorkflowEngine::errorFromReply('⚠ Une erreur est survenue.'));
        $this->assertNull(WorkflowEngine::errorFromReply('Une réponse ordinaire.'));
        $this->assertNull(WorkflowEngine::errorFromReply('⚠️'));
    }

    public function testLesChampsDuDepartSontControles(): void
    {
        $wf = $this->publie(self::definition());
        try {
            WorkflowEngine::launch($wf, User::findById($this->userId), ['client' => 'D'], [], 'manual', null, '');
            $this->fail('Montant obligatoire.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Montant', $e->getMessage());
        }
        try {
            WorkflowEngine::launch($wf, User::findById($this->userId), ['client' => 'D', 'montant' => 'beaucoup'], [], 'manual', null, '');
            $this->fail('Un nombre est attendu.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nombre', $e->getMessage());
        }
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM workflow_runs'), 'Rien n’est créé.');
    }

    public function testUnSimpleUtilisateurNeLancePas(): void
    {
        $wf = $this->publie(self::definition());
        $this->insertUser(8, 'user');
        $this->expectException(\InvalidArgumentException::class);
        WorkflowEngine::launch($wf, User::findById(8), ['client' => 'E', 'montant' => '1'], [], 'manual', null, '');
    }

    public function testArreterUneExecution(): void
    {
        $res = $this->lance($this->publie(self::definition()), ['client' => 'F', 'montant' => '9000']);
        WorkflowEngine::cancel(WorkflowRun::find($res['run_id']));
        $this->assertSame(WorkflowRun::CANCELLED, WorkflowRun::find($res['run_id'])['status']);
        $statuts = array_column(WorkflowRun::steps($res['run_id']), 'status');
        $this->assertNotContains(WorkflowRun::STEP_RUNNING, $statuts, 'Aucune étape ne reste « en cours » sur une exécution arrêtée.');
        // La fin du job arrivée après l'arrêt ne relance rien.
        $this->repond($res['job_ids'][0], 'Trop tard.');
        $this->assertSame(WorkflowRun::CANCELLED, WorkflowRun::find($res['run_id'])['status']);
    }

    public function testUnWorkflowProgrammePartUneSeuleFoisParCreneau(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true, 'default' => 'IA']],
                'schedule' => ['rule' => ['freq' => 'daily', 'time' => '08:00'], 'timezone' => 'Europe/Paris']],
            'nodes' => [['key' => 'veille', 'type' => 'step', 'title' => 'Veiller', 'instruction' => 'Veille sur @{depart}.',
                'output' => ['key' => 'faits', 'name' => 'Faits', 'form' => 'text']]],
            'end' => ['pieces' => ['faits']],
        ]);
        $wf = $this->publie($def);
        $slot = new \DateTimeImmutable('2026-10-15 06:00:00', new \DateTimeZone('UTC'));
        Workflow::setScheduleNext($wf['id'], $slot);

        $this->assertSame(1, WorkflowEngine::tick($slot->modify('+1 minute'), false));
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM workflow_runs'));
        $run = $this->ligne('SELECT * FROM workflow_runs LIMIT 1');
        $this->assertSame('schedule', $run['trigger_kind']);
        $next = $this->ligne('SELECT schedule_next_at FROM workflows WHERE id = ?', [$wf['id']]);
        $this->assertGreaterThan($slot->format('Y-m-d H:i:s'), (string)$next['schedule_next_at'], 'L’échéance avance.');

        // Même créneau rejoué (deux workers, horloge revenue en arrière) : jamais deux exécutions.
        Workflow::setScheduleNext($wf['id'], $slot);
        WorkflowEngine::tick($slot->modify('+2 minutes'), false);
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM workflow_runs'));
    }

    public function testCoupeParLAdministrateurLEtapeSuivanteSeMetEnPause(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'a', 'type' => 'step', 'title' => 'A', 'instruction' => 'A sur @{depart}.', 'output' => ['key' => 'pa', 'name' => 'Pièce A', 'form' => 'text']],
                ['key' => 'b', 'type' => 'step', 'title' => 'B', 'instruction' => 'B sur @{pa}.', 'output' => ['key' => 'pb', 'name' => 'Pièce B', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['pb']],
        ]);
        $res = $this->lance($this->publie($def), ['sujet' => 'x']);
        Settings::set(Workflow::SETTING_ENABLED, '0');
        $this->repond($res['job_ids'][0], 'Pièce A.');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::PAUSED, $run['status'], 'L’étape suivante ne démarre pas.');
        $this->assertSame('b', $run['current_key']);

        // Rouverts : la reprise repart de l'étape B.
        Settings::set(Workflow::SETTING_ENABLED, '1');
        $jobs = WorkflowEngine::retry($run);
        $this->assertCount(1, $jobs);
        $this->repond($jobs[0], 'Pièce B.');
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($res['run_id'])['status']);
    }
}
