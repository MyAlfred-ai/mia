<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';
require_once __DIR__ . '/../Support/FakeLlmStream.php';

/**
 * Discussion dans la conversation d'une exécution de workflow, sur MySQL réel
 * et un faux modèle (WorkflowChat) — le cas constaté chez un client du 2026-11-24 rejoué :
 * une étape remet une fiche « Ad Spend à compléter », la relecture attend,
 * la personne donne le montant DANS LA DISCUSSION.
 *
 * Avant : un tour de chat ordinaire, la fiche restait vide et toute la suite
 * écrivait « en attente de saisie ». Désormais le VRAI tour de chat met la
 * fiche à jour, la carte de validation se redessine, et l'étape suivante lit
 * le montant. Rien n'est approuvé à la place de la personne.
 *
 * La fin des jobs d'ÉTAPE est simulée (réponse écrite, puis onJobFinished),
 * comme dans WorkflowEnginePipelineTest ; le tour de DISCUSSION est réel.
 */
final class WorkflowChatPipelineTest extends DatabaseTestCase
{
    private const USER = 71;

    private array $reglagesAvant = [];

    /** @var list<array> évènements émis par le tour de chat */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, 'default_llm', 'job_worker_heartbeat_at'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        // o3 : routé en Chat Completions, celui que rejoue FakeLlmStream (jour figé : il s'arrête le 11/12/2026).
        LlmCatalog::setToday('2026-10-14');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        Settings::set('default_llm', 'openai::o3');
        JobQueue::markWorkerAlive();
        $this->insertUser(self::USER, 'creator');
        I18n::use('fr');
        $this->events = [];
        ChatController::setEmitSink(function (array $e): void {
            $this->events[] = $e;
        });
    }

    protected function tearDown(): void
    {
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
        }
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, (string)($valeur ?? ''));
            }
        }
        parent::tearDown();
    }

    /** Recueillir l'Ad Spend (fiche relue) → Calculer le TROAS → livrable. */
    private static function definition(): array
    {
        return WorkflowSpec::normalize([
            'objective' => 'Un reporting Amazon avec son TROAS.',
            'start' => ['fiche_name' => 'Contexte', 'fields' => [
                ['key' => 'mois', 'label' => 'Mois', 'type' => 'text', 'required' => true],
            ]],
            'nodes' => [
                ['key' => 'ad_spend', 'type' => 'step', 'title' => 'Recueillir l\'Ad Spend', 'review' => true,
                    'instruction' => 'Présente une fiche Ad Spend à compléter pour @{depart}, à confirmer à la relecture.',
                    'output' => ['key' => 'saisie', 'name' => 'Saisie Ad Spend', 'form' => 'fiche', 'fields' => [
                        ['key' => 'montant', 'label' => 'Ad Spend du mois EUR HT', 'type' => 'number'],
                        ['key' => 'devise', 'label' => 'Devise', 'type' => 'text'],
                    ]]],
                ['key' => 'calcul', 'type' => 'step', 'title' => 'Calculer', 'instruction' => 'Calcule le TROAS avec @{saisie}.',
                    'output' => ['key' => 'resultat', 'name' => 'Résultat', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['resultat']],
        ]);
    }

    /** Lance le workflow et mène l'étape jusqu'à la relecture qui attend. @return array{run_id: int, conv: int} */
    private function jusquALaRelecture(): array
    {
        $id = Workflow::create(self::USER, 'Piloter Amazon', self::definition());
        Workflow::publish($id, self::USER, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $res = WorkflowEngine::launch(Workflow::findById($id), User::findById(self::USER), ['mois' => 'octobre 2026'], [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        $this->repond($res['job_ids'][0], "**Statut : en attente de saisie.** Aucun montant fourni.\n\n```json\n{\"Ad Spend du mois EUR HT\": null, \"Devise\": \"EUR\"}\n```");
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::WAITING, $run['status']);
        $this->assertSame('ad_spend~review', $run['current_key']);
        return ['run_id' => (int)$res['run_id'], 'conv' => (int)$res['conversation_id']];
    }

    private function repond(int $jobId, string $reponse): void
    {
        $job = $this->db()->query('SELECT conversation_id FROM async_jobs WHERE id = ' . $jobId)->fetch(PDO::FETCH_ASSOC);
        $msg = Message::create((int)$job['conversation_id'], 'assistant', $reponse);
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
    }

    private static function appel(string $tool, array $args): string
    {
        return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => 0, 'id' => 'call_1', 'type' => 'function',
                'function' => ['name' => $tool, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
            ]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
            . "data: [DONE]\n\n";
    }

    /** Un tour de chat ORDINAIRE (le bouton Envoyer) dans la conversation de l'exécution. */
    private function envoie(int $conv, string $texte): array
    {
        $q = Message::create($conv, 'user', $texte);
        return (new ChatController())->runSend(['content' => $texte, 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false], self::USER, $conv);
    }

    private static function systeme(int $i): string
    {
        return (string)(FakeLlmStream::$requests[$i]['messages'][0]['content'] ?? '');
    }

    /** @return list<string> */
    private static function outils(int $i): array
    {
        return array_map(static fn(array $t): string => (string)($t['function']['name'] ?? ''), (array)(FakeLlmStream::$requests[$i]['tools'] ?? []));
    }

    public function testLeMontantDonneDansLaDiscussionEntreDansLaFicheEtLaSuiteLeLit(): void
    {
        ['run_id' => $runId, 'conv' => $conv] = $this->jusquALaRelecture();
        $gate = WorkflowRun::lastStep($runId, 'ad_spend~review');
        $actionId = (int)$gate['pending_action_id'];

        FakeLlmStream::install();
        FakeLlmStream::$queue = [
            self::appel(WorkflowChat::TOOL, [
                'piece'  => 'saisie',
                'champs' => '{"Ad Spend du mois EUR HT": 15539}',
                'resume' => 'Ad Spend confirmé pour la France : 15 539 € HT.',
            ]),
            FakeLlmStream::sse('La fiche est à jour : approuvez la carte de validation pour que le workflow continue.'),
        ];
        $r = $this->envoie($conv, 'Voici l’ad spend : 15 539 € HT, je confirme.');
        $this->assertTrue($r['done'] ?? false);

        // Le modèle sait où il est, voit la fiche ACTUELLE et a l'outil.
        $systeme = self::systeme(0);
        $this->assertStringContainsString('CONVERSATION D\'UN WORKFLOW', $systeme);
        $this->assertStringContainsString('« Piloter Amazon »', $systeme);
        $this->assertStringContainsString('ne prépare aucun email', $systeme);
        $this->assertStringContainsString('en attente de saisie', $systeme);
        $this->assertContains(WorkflowChat::TOOL, self::outils(0));

        // Le dossier est à jour, résumé compris.
        $piece = WorkflowRun::artifacts($runId)['saisie'];
        $this->assertSame(2, $piece['version']);
        $this->assertEquals(15539, $piece['content']['fields']['montant']);
        $this->assertSame('EUR', $piece['content']['fields']['devise']);
        $this->assertSame('Ad Spend confirmé pour la France : 15 539 € HT.', $piece['content']['summary']);

        // La carte de validation montre la nouvelle version — en base et à l'écran.
        $carte = DrivePendingAction::findPending($actionId, self::USER, $conv);
        $this->assertNotNull($carte, 'La validation attend toujours : rien n’est approuvé à la place de la personne.');
        $this->assertStringContainsString('Ad Spend confirmé', (string)$carte['preview']['apercu']);
        $maj = array_values(array_filter(array_map(static fn(array $e): ?array => $e['drive_card_update'] ?? null, $this->events)));
        $this->assertCount(1, $maj);
        $this->assertSame($actionId, $maj[0]['id']);
        $trace = array_values(array_filter(array_map(static fn(array $e): ?array => $e['action'] ?? null, $this->events)));
        $this->assertContains(WorkflowChat::TOOL, array_column($trace, 't'));
        $this->assertSame(WorkflowRun::WAITING, WorkflowRun::find($runId)['status']);

        // La personne approuve : l'étape suivante reçoit le montant.
        $this->assertNotNull(DrivePendingAction::claim($actionId, self::USER, $conv));
        DrivePendingAction::resolve($actionId, 'executed');
        $jobs = WorkflowEngine::gateResolved(WorkflowRun::find($runId), $gate, 'approved');
        $this->assertCount(1, $jobs);
        $payload = json_decode((string)$this->db()->query('SELECT payload_json FROM async_jobs WHERE id = ' . (int)$jobs[0])->fetchColumn(), true);
        $this->assertSame('calcul', $payload['workflow']['node_key']);
        $this->assertStringContainsString('Ad Spend confirmé pour la France', $payload['workflow']['prompt']);
        $this->assertStringNotContainsString('en attente de saisie', $payload['workflow']['prompt']);
    }

    public function testUnTourEnErreurNeTouchePasALaFiche(): void
    {
        ['run_id' => $runId, 'conv' => $conv] = $this->jusquALaRelecture();
        FakeLlmStream::install();
        FakeLlmStream::$queue = [
            self::appel(WorkflowChat::TOOL, ['piece' => 'saisie', 'champs' => '{"TROAS": 4}']),
            FakeLlmStream::sse('Je n’ai pas pu mettre la fiche à jour.'),
        ];
        $this->envoie($conv, 'Le TROAS vaut 4.');
        $this->assertSame(1, WorkflowRun::artifacts($runId)['saisie']['version'], 'Un champ inconnu ne réécrit rien.');
        // Le refus est rendu au modèle, qui peut le dire.
        $retour = json_encode(FakeLlmStream::$requests[1]['messages'] ?? [], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('« TROAS » inconnu', (string)$retour);
    }

    public function testHorsValidationNiOutilNiDossierModifiable(): void
    {
        ['run_id' => $runId, 'conv' => $conv] = $this->jusquALaRelecture();
        // Approuvée : le workflow travaille à l'étape suivante.
        $gate = WorkflowRun::lastStep($runId, 'ad_spend~review');
        DrivePendingAction::claim((int)$gate['pending_action_id'], self::USER, $conv);
        DrivePendingAction::resolve((int)$gate['pending_action_id'], 'executed');
        WorkflowEngine::gateResolved(WorkflowRun::find($runId), $gate, 'approved');

        FakeLlmStream::install();
        FakeLlmStream::$queue = [FakeLlmStream::sse('Le workflow calcule.')];
        $this->envoie($conv, 'Où en est-on ?');
        $this->assertStringContainsString('en train de travailler', self::systeme(0));
        $this->assertNotContains(WorkflowChat::TOOL, self::outils(0));

        // Exécution terminée : plus aucune note.
        $this->db()->prepare('UPDATE workflow_runs SET status = ? WHERE id = ?')->execute([WorkflowRun::DONE, $runId]);
        FakeLlmStream::$requests = [];
        FakeLlmStream::$queue = [FakeLlmStream::sse('Bonjour.')];
        $this->envoie($conv, 'Merci !');
        $this->assertStringNotContainsString('CONVERSATION D\'UN WORKFLOW', self::systeme(0));
    }

    public function testAjusterAlaMainRetireLeResumeDevenuFaux(): void
    {
        ['run_id' => $runId] = $this->jusquALaRelecture();
        $piece = WorkflowRun::artifacts($runId)['saisie'];
        $this->assertStringContainsString('en attente de saisie', (string)$piece['content']['summary']);
        $spec = WorkflowSpec::pieces(WorkflowRun::find($runId)['definition'])['saisie'];
        $corrige = ArtifactContract::withCorrectedFields($spec, $piece['content'], ['montant' => 15539.0]);
        $this->assertArrayNotHasKey('summary', $corrige);
        $this->assertStringNotContainsString('en attente', ArtifactContract::render($spec, $corrige));
    }
}
