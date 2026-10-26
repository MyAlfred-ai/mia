<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Workflows, second lot, sur la vraie base : boucles, attentes, « Me
 * prévenir », reprise sur erreur (essais automatiques, brique ignorée,
 * reprise à la main, refaire depuis), livraisons, surveillances et
 * enchaînement — cf. CLAUDE.md « Workflows ».
 *
 * Même méthode que WorkflowEnginePipelineTest : la fin d'un job d'étape est
 * SIMULÉE (réponse écrite, puis onJobFinished), le worker est déclaré
 * vivant pour que rien ne parte tout seul, et les minuteurs sont avancés
 * en appelant tickTimers avec une heure choisie.
 */
final class WorkflowAutomationPipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];
    private int $userId = 0;
    /** Fichiers écrits par un test (livraison dans une base), retirés à la fin. */
    private array $fichiers = [];

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
        Settings::set(Workflow::SETTING_AUTO, '1');
        Settings::set('connector_web_enabled', '1');
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
        foreach ($this->fichiers as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
            $dir = dirname($f);
            if (is_dir($dir) && count((array)scandir($dir)) === 2) {
                @rmdir($dir);
            }
        }
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

    private function publie(array $def, string $nom = 'Workflow'): array
    {
        $id = Workflow::create($this->userId, $nom, WorkflowSpec::normalize($def));
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf, array $values = []): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), $values, [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        return $res;
    }

    private function repond(int $jobId, string $reponse): int
    {
        $job = $this->ligne('SELECT conversation_id FROM async_jobs WHERE id = ?', [$jobId]);
        $msg = Message::create((int)$job['conversation_id'], 'assistant', $reponse);
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
        return (int)$msg['id'];
    }

    /** Le job échoue (le worker l'a clos en erreur, cause présentée). */
    private function echoue(int $jobId, string $kind, string $title = ''): void
    {
        $this->db()->prepare("UPDATE async_jobs SET status = 'failed' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'failed', null, ['kind' => $kind, 'title' => $title, 'message' => 'Panne simulée.']);
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

    private function payload(int $jobId): array
    {
        return json_decode((string)$this->ligne('SELECT payload_json FROM async_jobs WHERE id = ?', [$jobId])['payload_json'], true);
    }

    private function jobEnCours(int $runId): int
    {
        $step = $this->ligne("SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND status = 'running' AND job_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$runId]);
        $this->assertNotSame([], $step, 'Une brique tourne.');
        return (int)$step['job_id'];
    }

    private function messages(int $convId): array
    {
        $stmt = $this->db()->prepare("SELECT content FROM messages WHERE conversation_id = ? ORDER BY id");
        $stmt->execute([$convId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function plus(int $sec): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $sec . ' seconds');
    }

    private static function deuxEtapes(array $onErrorA = [], array $onErrorB = []): array
    {
        return [
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'a', 'type' => 'step', 'title' => 'Étape A', 'instruction' => 'A sur @{depart}.',
                    'output' => ['key' => 'pa', 'name' => 'Pièce A', 'form' => 'text'], 'on_error' => $onErrorA],
                ['key' => 'b', 'type' => 'step', 'title' => 'Étape B', 'instruction' => 'B sur @{depart}, et @{pa}.',
                    'output' => ['key' => 'pb', 'name' => 'Pièce B', 'form' => 'text'], 'on_error' => $onErrorB],
            ],
            'end' => ['pieces' => ['pb']],
        ];
    }

    // ------------------------------------------------------------------
    // Boucles
    // ------------------------------------------------------------------

    public function testUneBouclePourChaqueTraiteChaqueElementPuisRassembleUnTableau(): void
    {
        $def = [
            'start' => ['fields' => [['key' => 'secteur', 'label' => 'Secteur', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'lister', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'Liste les clients de @{depart}.',
                    'output' => ['key' => 'clients', 'name' => 'Clients', 'form' => 'list']],
                ['key' => 'chaque', 'type' => 'loop', 'title' => 'Pour chaque client', 'mode' => 'each', 'over' => 'clients',
                    'item' => ['key' => 'client', 'name' => 'Client en cours'],
                    'body' => [['key' => 'decrire', 'type' => 'step', 'title' => 'Décrire', 'instruction' => 'Décris @{client}.',
                        'output' => ['key' => 'fiche', 'name' => 'Fiche client', 'form' => 'fiche', 'fields' => [
                            ['label' => 'Nom', 'type' => 'text'], ['label' => 'Note', 'type' => 'number'],
                        ]]]],
                    'output' => ['key' => 'tableau', 'name' => 'Tableau des clients']],
            ],
            'end' => ['pieces' => ['tableau']],
        ];
        $res = $this->lance($this->publie($def), ['secteur' => 'BTP']);
        $runId = $res['run_id'];
        $this->repond($res['job_ids'][0], "- Alpha\n- Beta");

        $this->assertSame(['Alpha', 'Beta'], WorkflowRun::artifacts($runId)['chaque~items']['content']['items']);
        $j1 = $this->jobEnCours($runId);
        $p1 = $this->payload($j1)['workflow'];
        $this->assertSame('decrire#1', $p1['node_key'], 'Le passage porte son tour.');
        $this->assertStringContainsString('Alpha', $p1['prompt']);
        $this->assertStringContainsString('élément 1 sur 2', $p1['prompt']);
        $this->assertSame('decrire#1', WorkflowRun::find($runId)['current_key']);

        $this->repond($j1, "Alpha va bien.\n```json\n{\"nom\": \"Alpha SA\", \"note\": 4}\n```");
        $j2 = $this->jobEnCours($runId);
        $p2 = $this->payload($j2)['workflow'];
        $this->assertSame('decrire#2', $p2['node_key']);
        $this->assertStringContainsString('Beta', $p2['prompt']);
        $this->assertStringNotContainsString('Alpha SA', $p2['prompt'], 'Un tour ne voit pas la pièce du tour d’avant.');

        $this->repond($j2, "```json\n{\"nom\": \"Beta SAS\", \"note\": 5}\n```");
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $arts = WorkflowRun::artifacts($runId);
        $this->assertSame(['nom' => 'Alpha SA', 'note' => 4], $arts['fiche#1']['content']['fields']);
        $this->assertSame('Beta', $arts['client#2']['content']['text']);
        $rows = $arts['tableau']['content']['rows'];
        $this->assertSame(['Élément', 'Nom', 'Note'], $rows[0]);
        $this->assertSame(['Alpha', 'Alpha SA', '4'], $rows[1]);
        $this->assertSame(['Beta', 'Beta SAS', '5'], $rows[2]);
        $this->assertSame('done', WorkflowRun::lastStep($runId, 'chaque')['outcome']);
    }

    public function testUneBoucleSurUneListeVideNeFaitAucunTour(): void
    {
        $def = [
            'start' => ['fields' => [['key' => 's', 'label' => 'S', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'lister', 'type' => 'step', 'title' => 'Lister', 'instruction' => '@{depart}', 'output' => ['key' => 'l', 'name' => 'Liste', 'form' => 'table']],
                ['key' => 'chaque', 'type' => 'loop', 'title' => 'Chaque', 'over' => 'l',
                    'body' => [['key' => 'x', 'type' => 'step', 'title' => 'X', 'instruction' => '@{element}', 'output' => ['key' => 'px', 'name' => 'PX', 'form' => 'text']]],
                    'output' => ['key' => 'tout', 'name' => 'Tout']],
            ],
            'end' => ['pieces' => ['tout']],
        ];
        $res = $this->lance($this->publie($def), ['s' => 'x']);
        $this->repond($res['job_ids'][0], "| Nom |\n|---|");
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::DONE, $run['status'], 'Un tableau sans ligne : la boucle passe, le rail continue.');
        $this->assertSame(0, $this->nombre("SELECT COUNT(*) FROM workflow_run_steps WHERE run_id = ? AND node_key LIKE 'x#%'", [$res['run_id']]));
        $this->assertNotEmpty(array_filter($this->messages((int)$res['conversation_id']), static fn($m) => str_contains($m, 'rien à traiter')));
    }

    public function testUneValidationDansUneBoucleRenvoieAuMemeTour(): void
    {
        $def = [
            'start' => ['fields' => [['key' => 'liste', 'label' => 'Liste', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'lister', 'type' => 'step', 'title' => 'Lister', 'instruction' => '@{depart}', 'output' => ['key' => 'items', 'name' => 'Items', 'form' => 'list']],
                ['key' => 'chaque', 'type' => 'loop', 'title' => 'Chaque', 'over' => 'items',
                    'body' => [
                        ['key' => 'ecrire', 'type' => 'step', 'title' => 'Écrire', 'instruction' => 'Écris sur @{element}.', 'output' => ['key' => 'mot', 'name' => 'Mot', 'form' => 'text']],
                        ['key' => 'relire', 'type' => 'gate', 'title' => 'Relire', 'reviews' => ['mot']],
                    ],
                    'output' => ['key' => 'mots', 'name' => 'Mots']],
            ],
            'end' => ['pieces' => ['mots']],
        ];
        $res = $this->lance($this->publie($def), ['liste' => 'x']);
        $runId = $res['run_id'];
        $this->repond($res['job_ids'][0], "- Un\n- Deux");
        $this->repond($this->jobEnCours($runId), 'Premier jet.');

        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::WAITING, $run['status']);
        $this->assertSame('relire#1', $run['current_key']);
        $gate = WorkflowRun::lastStep($runId, 'relire#1');
        DrivePendingAction::claim((int)$gate['pending_action_id'], $this->userId, (int)$res['conversation_id']);
        DrivePendingAction::resolve((int)$gate['pending_action_id'], 'cancelled');
        $jobs = WorkflowEngine::gateResolved($run, $gate, 'returned', 'Plus court.');
        $this->assertCount(1, $jobs);
        $p = $this->payload($jobs[0])['workflow'];
        $this->assertSame('ecrire#1', $p['node_key'], 'Le renvoi refait l’étape du même tour.');
        $this->assertStringContainsString('Plus court.', $p['prompt']);

        $this->repond($jobs[0], 'Jet court.');
        $run = WorkflowRun::find($runId);
        $gate = WorkflowRun::lastStep($runId, 'relire#1');
        $this->assertSame(WorkflowRun::STEP_WAITING, $gate['status'], 'La validation se rouvre pour le même tour.');
        DrivePendingAction::claim((int)$gate['pending_action_id'], $this->userId, (int)$res['conversation_id']);
        DrivePendingAction::resolve((int)$gate['pending_action_id'], 'executed');
        $jobs = WorkflowEngine::gateResolved($run, $gate, 'approved');
        $this->assertSame('ecrire#2', $this->payload($jobs[0])['workflow']['node_key'], 'Approuvé : tour suivant.');
        $this->assertSame(2, WorkflowRun::artifacts($runId)['mot#1']['version']);
    }

    private static function jusqua(string $exhausted = 'continue'): array
    {
        return [
            'start' => ['fields' => [['key' => 'theme', 'label' => 'Thème', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'ameliorer', 'type' => 'loop', 'title' => 'Améliorer', 'mode' => 'until', 'max' => 3, 'exhausted' => $exhausted,
                    'body' => [['key' => 'noter', 'type' => 'step', 'title' => 'Rédiger et noter', 'instruction' => 'Rédige sur @{depart}.',
                        'output' => ['key' => 'avis', 'name' => 'Version notée', 'form' => 'fiche', 'fields' => [
                            ['label' => 'Texte', 'type' => 'text'], ['label' => 'Note', 'type' => 'number'],
                        ]]]],
                    'until' => ['mode' => 'rule', 'rule' => ['join' => 'all', 'items' => [['piece' => 'avis', 'field' => 'note', 'op' => 'gte', 'value' => '8']]]],
                    'output' => ['key' => 'final', 'name' => 'Version finale']],
            ],
            'end' => ['pieces' => ['final']],
        ];
    }

    public function testUneBoucleJusquaRefaitLeTravailEtGardeLaDerniereVersion(): void
    {
        $res = $this->lance($this->publie(self::jusqua()), ['theme' => 'Voile']);
        $runId = $res['run_id'];
        $this->repond($res['job_ids'][0], "```json\n{\"texte\": \"v1\", \"note\": 5}\n```");

        $j2 = $this->jobEnCours($runId);
        $p2 = $this->payload($j2)['workflow'];
        $this->assertSame('noter#2', $p2['node_key']);
        $this->assertStringContainsString('tour 2 sur 3', $p2['prompt']);
        $this->assertStringContainsString('Au tour précédent', $p2['prompt']);
        $this->assertStringContainsString('v1', $p2['prompt'], 'L’étape reprend sa version du tour d’avant.');

        $this->repond($j2, "```json\n{\"texte\": \"v2\", \"note\": 9}\n```");
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertSame(['texte' => 'v2', 'note' => 9], WorkflowRun::artifacts($runId)['final']['content']['fields']);
        $loop = WorkflowRun::lastStep($runId, 'ameliorer');
        $this->assertSame([false, true], array_column($loop['detail']['rounds'], 'met'));
    }

    public function testUneBoucleJusquaQuiNAboutitPasSArreteOuGardeLaDerniereVersion(): void
    {
        $res = $this->lance($this->publie(self::jusqua('stop')), ['theme' => 'Voile']);
        foreach ([1, 2, 3] as $n) {
            $this->repond($n === 1 ? $res['job_ids'][0] : $this->jobEnCours($res['run_id']), "```json\n{\"texte\": \"v{$n}\", \"note\": 3}\n```");
        }
        $this->assertSame(WorkflowRun::STOPPED, WorkflowRun::find($res['run_id'])['status'], 'Trois tours sans atteindre la note : arrêt.');

        $res = $this->lance($this->publie(self::jusqua('continue'), 'Autre'), ['theme' => 'Voile']);
        foreach ([1, 2, 3] as $n) {
            $this->repond($n === 1 ? $res['job_ids'][0] : $this->jobEnCours($res['run_id']), "```json\n{\"texte\": \"v{$n}\", \"note\": 3}\n```");
        }
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($res['run_id'])['status']);
        $this->assertSame('v3', WorkflowRun::artifacts($res['run_id'])['final']['content']['fields']['texte'], 'La dernière version est gardée.');
        $this->assertSame('exhausted', WorkflowRun::lastStep($res['run_id'], 'ameliorer')['outcome']);
    }

    public function testLaQuestionDeSortieDUneBoucleEnPanneSeReprendAuMemeTour(): void
    {
        $def = self::jusqua();
        $def['nodes'][0]['until'] = ['mode' => 'question', 'question' => 'La version est-elle assez bonne ?', 'reads' => ['avis']];
        $res = $this->lance($this->publie($def), ['theme' => 'Voile']);
        $runId = $res['run_id'];
        $this->repond($res['job_ids'][0], "```json\n{\"texte\": \"v1\", \"note\": 5}\n```");

        $loop = WorkflowRun::lastStep($runId, 'ameliorer');
        $jobId = (int)$loop['job_id'];
        $this->assertGreaterThan(0, $jobId, 'Le job de la question est rattaché à la boucle.');
        $payload = $this->payload($jobId);
        $this->assertSame(['loop_until', 1], [$payload['op'], $payload['i']]);

        // Le job échoue (clé refusée) : arrêt reprenable, la boucle reste ouverte.
        $this->db()->prepare("UPDATE async_jobs SET status = 'failed' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'failed', null, ['kind' => 'auth', 'message' => 'Refus.']);
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);
        $loop = WorkflowRun::lastStep($runId, 'ameliorer');
        $this->assertSame(WorkflowRun::STEP_RUNNING, $loop['status']);
        $this->assertNull($loop['job_id']);

        $jobs = WorkflowEngine::retry($run);
        $this->assertCount(1, $jobs);
        $p = $this->payload($jobs[0]);
        $this->assertSame(['loop_until', 1], [$p['op'], $p['i']], 'La reprise repose la question du même tour.');
        $this->assertSame($jobs[0], (int)WorkflowRun::lastStep($runId, 'ameliorer')['job_id']);
    }

    public function testUneLivraisonBloqueeParLeQuotaMetEnPause(): void
    {
        $def = self::deuxEtapes();
        $def['end']['deliveries'] = [['type' => 'email', 'source' => 'gmail', 'to' => 'a@exemple.fr']];
        $res = $this->lance($this->publie($def), ['sujet' => 'x']);
        $this->repond($res['job_ids'][0], 'A.');
        $this->repond($this->jobEnCours($res['run_id']), 'B.');
        $end = WorkflowRun::lastStep($res['run_id'], WorkflowSpec::END_KEY);
        $this->db()->prepare("UPDATE async_jobs SET status = 'failed' WHERE id = ?")->execute([$end['job_id']]);
        WorkflowEngine::onJobFinished((int)$end['job_id'], 'failed', null, ['kind' => CreditQuota::REASON_QUOTA, 'message' => 'Quota atteint.']);
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::PAUSED, $run['status']);
        $jobs = WorkflowEngine::retry($run);
        $this->assertSame('deliver', $this->payload($jobs[0])['op'], 'Reprendre relance les livraisons.');
    }

    // ------------------------------------------------------------------
    // Attendre, prévenir
    // ------------------------------------------------------------------

    public function testUneAttenteReprendASonHeurePuisPrevient(): void
    {
        $def = [
            'start' => ['fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'pause', 'type' => 'wait', 'title' => 'Laisser passer deux heures', 'mode' => 'delay', 'amount' => 2, 'unit' => 'hours'],
                ['key' => 'ping', 'type' => 'notify', 'title' => 'Prévenir', 'message' => 'Relancer @{depart.client}.'],
            ],
        ];
        $res = $this->lance($this->publie($def), ['client' => 'Ville de Lyon']);
        $this->assertSame([], $res['job_ids'], 'Une attente n’enfile aucun job.');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::WAITING, $run['status']);
        $wait = WorkflowRun::lastStep($res['run_id'], 'pause');
        $this->assertSame(WorkflowRun::STEP_WAITING, $wait['status']);
        $this->assertNotNull($wait['resume_at']);

        $this->assertSame(0, WorkflowEngine::tickTimers(self::plus(3600), false), 'Pas avant son heure.');
        $this->assertSame(WorkflowRun::WAITING, WorkflowRun::find($res['run_id'])['status']);
        $this->assertSame(1, WorkflowEngine::tickTimers(self::plus(7300), false));
        $this->assertSame(0, WorkflowEngine::tickTimers(self::plus(7400), false), 'Un minuteur ne repart qu’une fois.');

        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($res['run_id'])['status']);
        $msgs = $this->messages((int)$res['conversation_id']);
        $this->assertContains('🔔 Relancer Ville de Lyon.', $msgs, 'Le message cite la valeur du champ.');
    }

    public function testNePlusAttendreReprendTouteDeSuite(): void
    {
        $def = ['nodes' => [['key' => 'pause', 'type' => 'wait', 'title' => 'Une semaine', 'amount' => 7, 'unit' => 'days']]];
        $res = $this->lance($this->publie($def));
        WorkflowEngine::resumeNow(WorkflowRun::find($res['run_id']));
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($res['run_id'])['status']);
        $this->expectException(\InvalidArgumentException::class);
        WorkflowEngine::resumeNow(WorkflowRun::find($res['run_id']));
    }

    public function testAttendreUneDateDUneFiche(): void
    {
        $def = [
            'start' => ['fields' => [['key' => 'echeance', 'label' => 'Échéance', 'type' => 'date', 'required' => true]]],
            'nodes' => [['key' => 'veille', 'type' => 'wait', 'title' => 'La veille', 'mode' => 'date', 'piece' => 'depart', 'field' => 'echeance', 'time' => '08:30', 'offset' => -1]],
        ];
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->modify('+10 days')->format('Y-m-d');
        $res = $this->lance($this->publie($def), ['echeance' => $date]);
        $wait = WorkflowRun::lastStep($res['run_id'], 'veille');
        $attendu = (new \DateTimeImmutable($date . ' 08:30', new \DateTimeZone('Europe/Paris')))->modify('-1 day')->setTimezone(new \DateTimeZone('UTC'));
        $this->assertSame($attendu->format('Y-m-d H:i:s'), substr((string)$wait['resume_at'], 0, 19), 'La veille à 8 h 30, heure de la personne.');

        $passee = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->modify('-3 days')->format('Y-m-d');
        $res = $this->lance($this->publie($def, 'Passé'), ['echeance' => $passee]);
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($res['run_id'])['status'], 'Une date passée n’arrête rien.');
    }

    // ------------------------------------------------------------------
    // Reprise sur erreur
    // ------------------------------------------------------------------

    public function testUnePannePassagereEstReessayeeAutomatiquementPuisArrete(): void
    {
        $res = $this->lance($this->publie(self::deuxEtapes(['retries' => 2, 'then' => 'stop'])), ['sujet' => 'x']);
        $runId = $res['run_id'];

        $this->echoue($res['job_ids'][0], 'overloaded', 'Service saturé');
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::RUNNING, $run['status'], 'Un nouvel essai est prévu : l’exécution n’est pas en échec.');
        $this->assertSame(1, $this->nombre("SELECT COUNT(*) FROM workflow_run_steps WHERE run_id = ? AND status = 'waiting' AND resume_at IS NOT NULL", [$runId]));
        $this->assertNotEmpty(array_filter($this->messages((int)$res['conversation_id']), static fn($m) => str_contains($m, 'nouvel essai automatique dans 1 minute (1/2)')));

        $this->assertSame(0, WorkflowEngine::tickTimers(self::plus(30), false));
        $this->assertSame(1, WorkflowEngine::tickTimers(self::plus(90), false));
        $j2 = $this->jobEnCours($runId);
        $this->assertSame('a', $this->payload($j2)['workflow']['node_key']);

        $this->echoue($j2, 'model', 'Service momentanément saturé');
        $this->assertSame(0, WorkflowEngine::tickTimers(self::plus(200), false), 'Second délai : 5 minutes.');
        $this->assertSame(1, WorkflowEngine::tickTimers(self::plus(400), false));

        $this->echoue($this->jobEnCours($runId), 'timeout');
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::FAILED, $run['status'], 'Essais épuisés : arrêt, reprenable.');
        $this->assertSame('a', $run['current_key']);

        $jobs = WorkflowEngine::retry($run);
        $this->repond($jobs[0], 'Pièce A.');
        $this->repond($this->jobEnCours($runId), 'Pièce B.');
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($runId)['status']);
    }

    public function testUnePanneDurableNEstPasReessayee(): void
    {
        $res = $this->lance($this->publie(self::deuxEtapes(['retries' => 3, 'then' => 'stop'])), ['sujet' => 'x']);
        $this->echoue($res['job_ids'][0], 'auth', 'Accès au service refusé');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::FAILED, $run['status'], 'Une clé refusée ne se règle pas en attendant.');
        $this->assertSame(0, $this->nombre("SELECT COUNT(*) FROM workflow_run_steps WHERE run_id = ? AND resume_at IS NOT NULL", [$res['run_id']]));
    }

    public function testUneEtapeFacultativeEstIgnoreeEtLeRailContinue(): void
    {
        $res = $this->lance($this->publie(self::deuxEtapes(['retries' => 0, 'then' => 'skip'])), ['sujet' => 'x']);
        $this->echoue($res['job_ids'][0], 'auth', 'Accès au service refusé');
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::RUNNING, $run['status']);
        $this->assertSame('skipped', WorkflowRun::lastStep($res['run_id'], 'a')['outcome']);
        $j = $this->jobEnCours($res['run_id']);
        $p = $this->payload($j)['workflow'];
        $this->assertSame('b', $p['node_key']);
        $this->assertArrayNotHasKey('pa', WorkflowRun::artifacts($res['run_id']));
        $this->repond($j, 'Pièce B, sans A.');
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($res['run_id'])['status']);
    }

    public function testIgnorerAlaMainPuisRefaireDepuisUneBrique(): void
    {
        $res = $this->lance($this->publie(self::deuxEtapes()), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($res['job_ids'][0], 'Pièce A.');
        $this->echoue($this->jobEnCours($runId), 'auth');
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);
        $this->assertSame('b', $run['current_key']);

        $this->assertSame([], WorkflowEngine::skip($run));
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status'], 'B ignorée : le livrable sans sa pièce.');

        $jobs = WorkflowEngine::restartFrom($run, 'a');
        $this->assertCount(1, $jobs);
        $this->assertSame([], array_values(array_diff(array_keys(WorkflowRun::artifacts($runId)), ['depart'])), 'Les pièces remises depuis A sont retirées.');
        $this->repond($jobs[0], 'Pièce A, deuxième.');
        $this->repond($this->jobEnCours($runId), 'Pièce B, enfin.');
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($runId)['status']);
        $this->assertSame('Pièce B, enfin.', WorkflowRun::artifacts($runId)['pb']['content']['text']);

        $this->expectException(\InvalidArgumentException::class);
        WorkflowEngine::restartFrom(WorkflowRun::find($runId), 'inconnue');
    }

    public function testUneConditionNeSIgnorePas(): void
    {
        $def = [
            'start' => ['fields' => [['key' => 'm', 'label' => 'M', 'type' => 'number', 'required' => true]]],
            'nodes' => [['key' => 'c', 'type' => 'condition', 'title' => 'C', 'mode' => 'question', 'question' => 'Gros ?',
                'yes' => ['nodes' => [], 'then' => 'continue'], 'no' => ['nodes' => [], 'then' => 'stop']]],
        ];
        $res = $this->lance($this->publie($def), ['m' => '3']);
        $this->db()->prepare("UPDATE async_jobs SET status = 'failed' WHERE id = ?")->execute([$res['job_ids'][0]]);
        WorkflowEngine::onJobFinished($res['job_ids'][0], 'failed', null, ['kind' => 'auth', 'message' => 'Refus.']);
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);
        $this->expectException(\InvalidArgumentException::class);
        WorkflowEngine::skip($run);
    }

    public function testReprendreAvecLaDerniereVersionPublieeDuWorkflow(): void
    {
        $wf = $this->publie(self::deuxEtapes());
        $res = $this->lance($wf, ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($res['job_ids'][0], 'Pièce A.');
        $this->echoue($this->jobEnCours($runId), 'auth');

        $def = self::deuxEtapes();
        $def['nodes'][1]['instruction'] = 'B corrigée sur @{pa}.';
        Workflow::saveDraft($wf['id'], $this->userId, 'Workflow', '', $def);
        Workflow::publish($wf['id'], $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $jobs = WorkflowEngine::retry(WorkflowRun::find($runId), true);
        $this->assertStringContainsString('B corrigée', $this->payload($jobs[0])['content'], 'La consigne est celle de la nouvelle version.');
        $this->assertSame(2, WorkflowRun::find($runId)['version']);
        $this->assertSame('Pièce A.', WorkflowRun::artifacts($runId)['pa']['content']['text'], 'Les pièces déjà remises restent.');

        // Une brique retirée de la nouvelle version : la reprise est refusée, l'exécution intacte.
        $this->echoue($jobs[0], 'auth');
        $def['nodes'] = [$def['nodes'][0]];
        $def['end']['pieces'] = ['pa'];
        Workflow::saveDraft($wf['id'], $this->userId, 'Workflow', '', $def);
        Workflow::publish($wf['id'], $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        try {
            WorkflowEngine::retry(WorkflowRun::find($runId), true);
            $this->fail('La brique n’existe plus.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('n’existe plus', $e->getMessage());
        }
        $this->assertSame(2, WorkflowRun::find($runId)['version']);
        $this->assertSame(WorkflowRun::FAILED, WorkflowRun::find($runId)['status']);
    }

    // ------------------------------------------------------------------
    // Livraisons
    // ------------------------------------------------------------------

    public function testUneLivraisonParEmailSansConnexionArretePuisSIgnore(): void
    {
        $def = self::deuxEtapes();
        $def['end']['deliveries'] = [['type' => 'email', 'source' => 'gmail', 'to' => 'equipe@exemple.fr', 'subject' => 'Point @{depart.sujet}', 'mode' => 'approve']];
        $res = $this->lance($this->publie($def), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($res['job_ids'][0], 'Pièce A.');
        $this->repond($this->jobEnCours($runId), 'Pièce B.');

        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::RUNNING, $run['status'], 'Le livrable attend ses livraisons.');
        $end = WorkflowRun::lastStep($runId, WorkflowSpec::END_KEY);
        $job = $this->ligne('SELECT * FROM async_jobs WHERE id = ?', [$end['job_id']]);
        $this->assertSame('workflow', $job['type']);
        $this->assertSame('deliver', $this->payload((int)$end['job_id'])['op']);

        $out = WorkflowEngine::runDeliverJob($this->payload((int)$end['job_id']));
        $this->assertSame(['failed' => 1], $out);
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);
        $this->assertSame(WorkflowSpec::END_KEY, $run['current_key']);
        $this->assertSame('Une livraison n’a pas abouti', $run['error']['title']);
        $this->assertStringContainsString('Gmail non connecté', $run['error']['message']);

        // Reprendre refait la livraison en échec (toujours sans connexion).
        $jobs = WorkflowEngine::retry($run);
        $this->assertSame(['failed' => 1], WorkflowEngine::runDeliverJob($this->payload($jobs[0])));
        // L'ignorer termine le workflow.
        WorkflowEngine::skip(WorkflowRun::find($runId));
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($runId)['status']);
    }

    public function testUneLivraisonDansUneBaseDeConnaissances(): void
    {
        $kbId = KnowledgeBase::create(['name' => 'Comptes rendus', 'created_by' => $this->userId, 'embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large', 'embedding_dims' => 1536]);
        $def = self::deuxEtapes();
        $def['end']['deliveries'] = [['type' => 'kb', 'kb_id' => $kbId, 'pieces' => ['pb'], 'replace' => true]];
        $wf = $this->publie($def);

        foreach (['Version 1.', 'Version 2.'] as $version) {
            $res = $this->lance($wf, ['sujet' => 'x']);
            $this->repond($res['job_ids'][0], 'Pièce A.');
            $this->repond($this->jobEnCours($res['run_id']), $version);
            $end = WorkflowRun::lastStep($res['run_id'], WorkflowSpec::END_KEY);
            $this->assertSame(['done' => true], WorkflowEngine::runDeliverJob($this->payload((int)$end['job_id'])));
            $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($res['run_id'])['status']);
        }
        $docs = KnowledgeBaseDocument::allForKb($kbId);
        $this->assertCount(1, $docs, 'Remplacer : la pièce du même nom remplace la précédente.');
        $this->assertSame('Pièce B.md', $docs[0]['file_name']);
        $path = KnowledgeBase::ensureStorageDir($kbId) . '/' . $docs[0]['stored_name'];
        $this->fichiers[] = $path;
        $this->assertStringContainsString('Version 2.', (string)file_get_contents($path));
        $this->assertSame(2, $this->nombre("SELECT COUNT(*) FROM async_jobs WHERE type = 'maintenance'"), 'Une ingestion par dépôt.');
    }

    public function testUneLivraisonRefuseeQuandLaBaseNEstPlusGeree(): void
    {
        $other = $this->insertUser(9, 'creator');
        $kbId = KnowledgeBase::create(['name' => 'À quelqu’un d’autre', 'created_by' => $other, 'embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large', 'embedding_dims' => 1536]);
        $def = self::deuxEtapes();
        $def['end']['deliveries'] = [['type' => 'kb', 'kb_id' => $kbId, 'pieces' => ['pb']]];
        $res = $this->lance($this->publie($def), ['sujet' => 'x']);
        $this->repond($res['job_ids'][0], 'A.');
        $this->repond($this->jobEnCours($res['run_id']), 'B.');
        $end = WorkflowRun::lastStep($res['run_id'], WorkflowSpec::END_KEY);
        $this->assertSame(['failed' => 1], WorkflowEngine::runDeliverJob($this->payload((int)$end['job_id'])));
        $this->assertStringContainsString('vous ne gérez plus', WorkflowRun::find($res['run_id'])['error']['message']);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM knowledge_base_documents'));
    }

    public function testLesArgumentsInternesNeViennentJamaisDuModele(): void
    {
        $this->assertSame(['objet' => 'x'], WorkflowDeliveries::stripInternal(['objet' => 'x', '_wf_attach' => [['run_id' => 1, 'piece' => 'p']], '_wf_folder' => 'Racine']));
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertMatchesRegularExpression('/\$depositConnectorWrite\s*=.*?WorkflowDeliveries::stripInternal/s', $src, 'Une carte née d’un tour de chat perd ses arguments internes.');

        // Pièces jointes : relues depuis l'exécution de la conversation, qui doit être à la personne.
        $def = self::deuxEtapes();
        $def['nodes'][0]['output'] = ['key' => 'pa', 'name' => 'Note', 'form' => 'document', 'format' => 'md'];
        $res = $this->lance($this->publie($def), ['sujet' => 'x']);
        $this->repond($res['job_ids'][0], "Voici.\n````markdown fichier=\"Note.md\"\n# Note\n\nDu texte.\n````");
        $args = [WorkflowDeliveries::ATTACH_KEY => [['run_id' => $res['run_id'], 'piece' => 'pa'], ['run_id' => 999, 'piece' => 'pa']]];
        $files = WorkflowDeliveries::attachmentsFor($args, User::findById($this->userId), (int)$res['conversation_id']);
        $this->assertCount(1, $files, 'Une référence à une autre exécution est ignorée.');
        $this->assertSame('Note.md', $files[0]['name']);
        $this->assertStringContainsString('Du texte.', $files[0]['bytes']);
        $this->insertUser(10, 'creator');
        $this->expectException(\RuntimeException::class);
        WorkflowDeliveries::attachmentsFor($args, User::findById(10), (int)$res['conversation_id']);
    }

    // ------------------------------------------------------------------
    // Déclencheurs
    // ------------------------------------------------------------------

    private static function surveillance(): array
    {
        return [
            'start' => ['event' => ['kind' => 'email', 'source' => 'gmail', 'query' => 'from:client@exemple.fr', 'timezone' => 'Europe/Paris']],
            'nodes' => [['key' => 'trier', 'type' => 'step', 'title' => 'Trier', 'instruction' => 'Trie @{evenement}.', 'output' => ['key' => 'tri', 'name' => 'Tri', 'form' => 'text']]],
            'end' => ['pieces' => ['tri']],
        ];
    }

    private static function boite(array &$mails, bool $panne = false): object
    {
        return new class($mails, $panne) {
            public array $requetes = [];
            public function __construct(private array &$mails, private bool $panne) {}
            public function search(string $q, int $limit): array
            {
                $this->requetes[] = $q;
                if ($this->panne) {
                    throw new \RuntimeException('Gmail injoignable.');
                }
                return array_map(static fn($m) => ['id' => $m['id'], 'de' => $m['de'], 'objet' => $m['objet'], 'date' => 'Tue, 13 Oct 2026 22:30:00 +0000'], $this->mails);
            }
            public function readMessage(string $id, int $budget): array
            {
                foreach ($this->mails as $m) {
                    if ($m['id'] === $id) {
                        return ['de' => $m['de'], 'objet' => $m['objet'], 'date' => 'Tue, 13 Oct 2026 22:30:00 +0000', 'contenu' => 'Corps de ' . $id, 'pieces_jointes' => [['nom' => 'devis.pdf']]];
                    }
                }
                throw new \RuntimeException('Message introuvable.');
            }
        };
    }

    public function testUneSurveillanceDEmailsLanceUneExecutionParNouveauMessage(): void
    {
        $wf = $this->publie(self::surveillance());
        $this->assertNotNull($this->ligne('SELECT watch_next_at FROM workflows WHERE id = ?', [$wf['id']])['watch_next_at'], 'Vérifiée dès la publication.');
        $this->assertSame(1, WorkflowTriggers::tickWatchers(self::plus(1), false));
        $this->assertSame(0, WorkflowTriggers::tickWatchers(self::plus(2), false), 'Prochaine vérification dans 5 minutes.');
        $job = $this->ligne("SELECT * FROM async_jobs WHERE type = 'workflow'");
        $this->assertSame('wf_watch:' . $wf['id'], $job['dedup_key']);
        $payload = json_decode((string)$job['payload_json'], true);

        $mails = [['id' => 'm2', 'de' => 'Client <client@exemple.fr>', 'objet' => 'Ancien'], ['id' => 'm1', 'de' => 'Client <client@exemple.fr>', 'objet' => 'Très ancien']];
        $svc = self::boite($mails);
        $this->assertSame(['baseline' => 2], WorkflowTriggers::runWatchJob($payload, $svc), 'Ce qui est déjà là ne lance rien.');
        $this->assertSame('in:inbox from:client@exemple.fr newer_than:3d', $svc->requetes[0]);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM workflow_runs'));

        array_unshift($mails, ['id' => 'm4', 'de' => 'Client <client@exemple.fr>', 'objet' => 'Nouveau devis'], ['id' => 'm3', 'de' => 'Autre <autre@exemple.fr>', 'objet' => 'Avant']);
        $svc = self::boite($mails);
        $this->assertSame(['launched' => 2, 'new' => 2], WorkflowTriggers::runWatchJob($payload, $svc));
        $runs = $this->db()->query('SELECT * FROM workflow_runs ORDER BY id')->fetchAll();
        $this->assertSame(['gmail:m3', 'gmail:m4'], array_column($runs, 'trigger_ref'), 'Les plus anciens d’abord.');
        $this->assertSame('email', $runs[0]['trigger_kind']);
        $event = WorkflowRun::artifacts((int)$runs[1]['id'])['evenement']['content']['fields'];
        $this->assertSame('client@exemple.fr', $event['expediteur']);
        $this->assertSame('Nouveau devis', $event['objet']);
        $this->assertSame('2026-10-14', $event['recu_le'], 'La date suit le fuseau de la personne.');
        $this->assertSame(['devis.pdf'], $event['pieces_jointes']);
        $p = json_decode((string)$this->ligne('SELECT payload_json FROM async_jobs WHERE type = ? ORDER BY id DESC LIMIT 1', ['chat'])['payload_json'], true);
        $this->assertTrue($p['workflow']['scheduled'], 'Une étape lancée par un évènement est un tour sans personne devant l’écran.');
        $this->assertStringContainsString('Nouveau devis', $p['workflow']['prompt']);

        $this->assertSame(['launched' => 0, 'new' => 0], WorkflowTriggers::runWatchJob($payload, $svc), 'Un message ne lance qu’une fois.');
        $this->assertNull(WorkflowEngine::launch(Workflow::findById($wf['id']), User::findById($this->userId), [], [], 'email', null, 'Europe/Paris', ['fields' => []], 'gmail:m4'),
            'Même référence : jamais deux exécutions (deux vérifications concurrentes).');
    }

    public function testUneSurveillanceEnPanneLeDitEtNeLancePasDeRafale(): void
    {
        $wf = $this->publie(self::surveillance());
        $mails = [];
        $payload = ['workflow_id' => $wf['id']];
        $this->assertSame(['error' => 'Gmail injoignable.'], WorkflowTriggers::runWatchJob($payload, self::boite($mails, true)));
        $this->assertSame('Gmail injoignable.', Workflow::watchState(Workflow::findById($wf['id']))['error']);

        // Revenue : la première vérification réussie pose la ligne de base.
        $mails = array_map(static fn($i) => ['id' => 'x' . $i, 'de' => 'a@b.fr', 'objet' => 'o' . $i], range(1, 12));
        $this->assertSame(['baseline' => 12], WorkflowTriggers::runWatchJob($payload, self::boite($mails)));
        $this->assertSame('', Workflow::watchState(Workflow::findById($wf['id']))['error']);

        // Douze nouveaux d'un coup : cinq au plus par vérification, les autres à la suivante.
        $nouveaux = array_map(static fn($i) => ['id' => 'n' . $i, 'de' => 'a@b.fr', 'objet' => 'n' . $i], range(1, 7));
        $mails = array_merge($nouveaux, $mails);
        $this->assertSame(['launched' => WorkflowTriggers::WATCH_LAUNCH_MAX, 'new' => 7], WorkflowTriggers::runWatchJob($payload, self::boite($mails)));
        $this->assertSame(['launched' => 2, 'new' => 2], WorkflowTriggers::runWatchJob($payload, self::boite($mails)));

        // En pause : rien n'est vérifié.
        Workflow::setActive($wf['id'], $this->userId, false, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->assertSame(['skipped' => true], WorkflowTriggers::runWatchJob($payload, self::boite($mails)));
    }

    public function testUnWorkflowEnLanceUnAutreALaFinAvecSonLivrable(): void
    {
        $a = $this->publie(self::deuxEtapes(), 'Préparer');
        $suite = [
            'start' => ['event' => ['kind' => 'workflow', 'workflow_id' => $a['id']]],
            'nodes' => [['key' => 'envoyer', 'type' => 'step', 'title' => 'Envoyer', 'instruction' => 'Pars de @{evenement}.', 'output' => ['key' => 'mail', 'name' => 'Mail', 'form' => 'text']]],
            'end' => ['pieces' => ['mail']],
        ];
        $b = $this->publie($suite, 'Envoyer');
        $res = $this->lance($a, ['sujet' => 'x']);
        $this->repond($res['job_ids'][0], 'Pièce A.');
        $this->repond($this->jobEnCours($res['run_id']), 'Le livrable de A.');

        $runs = $this->db()->query('SELECT * FROM workflow_runs ORDER BY id')->fetchAll();
        $this->assertCount(2, $runs);
        $this->assertSame((int)$b['id'], (int)$runs[1]['workflow_id']);
        $this->assertSame('workflow', $runs[1]['trigger_kind']);
        $this->assertSame((int)$res['run_id'], (int)$runs[1]['parent_run_id']);
        $this->assertSame('run:' . $res['run_id'], $runs[1]['trigger_ref']);
        $text = WorkflowRun::artifacts((int)$runs[1]['id'])['evenement']['content']['text'];
        $this->assertStringContainsString('Le livrable de A.', $text);
        $this->assertNotEmpty(array_filter($this->messages((int)$res['conversation_id']), static fn($m) => str_contains($m, 'Workflow suivant lancé')));
    }

    public function testUneChaineDeWorkflowsEstBornee(): void
    {
        // A → B → A → … : la chaîne s'arrête après CHAIN_MAX enchaînements.
        $a = $this->publie(self::deuxEtapes(), 'A');
        $b = $this->publie(['start' => ['event' => ['kind' => 'workflow', 'workflow_id' => $a['id']]],
            'nodes' => [['key' => 'x', 'type' => 'notify', 'title' => 'N', 'message' => 'B fini.']]], 'B');
        $defA = self::deuxEtapes();
        $defA['start']['fields'][0]['default'] = 'auto';
        $defA['start']['event'] = ['kind' => 'workflow', 'workflow_id' => $b['id']];
        Workflow::saveDraft($a['id'], $this->userId, 'A', '', $defA);
        Workflow::publish($a['id'], $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $res = $this->lance(Workflow::findById($a['id']), ['sujet' => 'x']);
        for ($guard = 0; $guard < 10; $guard++) {
            $job = $this->ligne("SELECT s.job_id FROM workflow_run_steps s WHERE s.status = 'running' AND s.job_id IS NOT NULL ORDER BY s.id DESC LIMIT 1");
            if ($job === []) {
                break;
            }
            $this->repond((int)$job['job_id'], 'Pièce.');
        }
        $this->assertSame(WorkflowEngine::CHAIN_MAX, $this->nombre('SELECT COUNT(*) FROM workflow_runs'), 'Trois exécutions, pas une de plus.');
        $last = $this->ligne('SELECT * FROM workflow_runs ORDER BY id DESC LIMIT 1');
        $this->assertSame(WorkflowEngine::CHAIN_MAX - 1, WorkflowRun::chainDepth((int)$last['parent_run_id']));
        $this->assertNotEmpty(array_filter($this->messages((int)$last['conversation_id']), static fn($m) => str_contains($m, 'une chaîne compte 3 workflows au plus')));
        $this->assertSame((int)$res['run_id'], (int)$this->ligne('SELECT MIN(id) AS m FROM workflow_runs')['m']);
    }

    public function testLAdresseDAppelEstUnSecretRetrouveParSonEmpreinte(): void
    {
        $def = [
            'start' => ['fields' => [['key' => 'client', 'label' => 'Nom du client', 'type' => 'text', 'required' => true]], 'event' => ['kind' => 'webhook']],
            'nodes' => [['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => '@{evenement}', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'text']]],
        ];
        $wf = $this->publie($def);
        $token = WorkflowTriggers::hookToken($wf);
        $this->assertTrue(WorkflowTriggers::validHookToken($token));
        $row = $this->ligne('SELECT hook_token_hash, hook_token_enc FROM workflows WHERE id = ?', [$wf['id']]);
        $this->assertSame(hash('sha256', $token), $row['hook_token_hash']);
        $this->assertStringNotContainsString($token, (string)$row['hook_token_enc'], 'Le jeton n’est jamais stocké en clair.');
        $this->assertSame($wf['id'], Workflow::findByHookHash(WorkflowTriggers::hookHash($token))['id']);
        $this->assertSame($token, WorkflowTriggers::hookToken(Workflow::findById($wf['id'])), 'Le même jeton tant qu’on ne le renouvelle pas.');
        $nouveau = WorkflowTriggers::hookToken(Workflow::findById($wf['id']), true);
        $this->assertNotSame($token, $nouveau);
        $this->assertNull(Workflow::findByHookHash(WorkflowTriggers::hookHash($token)), 'Renouvelé : l’ancienne adresse ne répond plus.');

        $vals = WorkflowTriggers::hookValues(Workflow::findById($wf['id'])['published'], ['Nom du client' => 'Ville de Lyon', 'montant' => 12]);
        $this->assertSame(['client' => 'Ville de Lyon'], $vals['values'], 'Un champ se remplit par sa clé ou par son nom.');
        $res = WorkflowEngine::launch(Workflow::findById($wf['id']), User::findById($this->userId), $vals['values'], [], 'webhook', null, '',
            ['text' => $vals['text'], 'headline' => 'Appel'], 'hook:abc');
        $this->assertStringContainsString('"montant": 12', WorkflowRun::artifacts($res['run_id'])['evenement']['content']['text']);
        $this->assertNull(WorkflowEngine::launch(Workflow::findById($wf['id']), User::findById($this->userId), $vals['values'], [], 'webhook', null, '',
            ['text' => $vals['text']], 'hook:abc'), 'Même Idempotency-Key : pas de seconde exécution.');
        $this->assertSame(1, WorkflowRun::countRecent($wf['id'], 'webhook', 3600));
    }

    // ------------------------------------------------------------------
    // Entretien
    // ------------------------------------------------------------------

    public function testUneExecutionArreteeDansUneAttenteFermeSonMinuteur(): void
    {
        $res = $this->lance($this->publie(['nodes' => [['key' => 'p', 'type' => 'wait', 'title' => 'P', 'amount' => 1, 'unit' => 'minutes']]]));
        WorkflowEngine::cancel(WorkflowRun::find($res['run_id']));
        $this->assertSame(0, WorkflowEngine::tickTimers(self::plus(3600), false));
        $this->assertSame(WorkflowRun::CANCELLED, WorkflowRun::find($res['run_id'])['status']);
    }
}
