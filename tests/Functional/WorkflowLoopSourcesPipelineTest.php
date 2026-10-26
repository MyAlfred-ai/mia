<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Boucles « pour chaque » qui parcourent une SOURCE, sur la vraie base —
 * cf. CLAUDE.md « Workflows », boucles intelligentes. Même méthode que
 * WorkflowAutomationPipelineTest : la fin d'un job d'étape est SIMULÉE
 * (réponse écrite, puis onJobFinished), les jobs `workflow` (lecture des
 * éléments, fichier d'un élément, relecture groupée) sont joués à la main,
 * le worker est déclaré vivant pour que rien ne parte tout seul.
 */
final class WorkflowLoopSourcesPipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];
    private int $userId = 0;
    /** @var list<string> */
    private array $fichiers = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, Workflow::SETTING_AUTO, Workflow::SETTING_LOOP_MAX, 'default_llm', 'job_worker_heartbeat_at'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        Settings::set(Workflow::SETTING_AUTO, '1');
        Settings::set(Workflow::SETTING_LOOP_MAX, '200');
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
        LoopRunner::overrideServices(null);
        WorkflowAi::overrideCompletion(null);
        foreach ($this->fichiers as $f) {
            @unlink($f);
        }
        if (self::$pdo !== null) {
            foreach (glob(UPLOAD_DIR . 'wf_*') ?: [] as $f) {
                if (filemtime($f) >= time() - 600 && str_contains((string)@file_get_contents($f), 'Contrat de test')) {
                    @unlink($f);
                }
            }
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

    private function lance(array $wf, array $values = [], array $files = []): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), $values, $files, 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        return $res;
    }

    private function repond(int $jobId, string $reponse, array $cards = []): int
    {
        $job = $this->ligne('SELECT conversation_id FROM async_jobs WHERE id = ?', [$jobId]);
        $msg = Message::create((int)$job['conversation_id'], 'assistant', $reponse);
        if ($cards !== []) {
            DrivePendingAction::attachToMessage($cards, (int)$msg['id'], $this->userId);
        }
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
        return (int)$msg['id'];
    }

    private function echoue(int $jobId): void
    {
        $this->db()->prepare("UPDATE async_jobs SET status = 'failed' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'failed', null, ['kind' => 'generic', 'title' => 'Panne', 'message' => 'Panne simulée.']);
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

    /** Le dernier job rattaché à une brique de l'exécution. */
    private function dernierJob(int $runId): int
    {
        $step = $this->ligne('SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND job_id IS NOT NULL ORDER BY id DESC LIMIT 1', [$runId]);
        $this->assertNotSame([], $step, 'Un job attend.');
        return (int)$step['job_id'];
    }

    /** Le job `workflow` d'une opération, le plus récent de l'exécution. */
    private function jobWorkflow(int $runId, string $op): array
    {
        $stmt = $this->db()->prepare("SELECT id, payload_json FROM async_jobs WHERE type = 'workflow' ORDER BY id DESC");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $j) {
            $p = json_decode((string)$j['payload_json'], true);
            if ((int)($p['run_id'] ?? 0) === $runId && ($p['op'] ?? '') === $op) {
                return $p;
            }
        }
        $this->fail("Aucun job « $op ».");
    }

    private function messages(int $convId): array
    {
        $stmt = $this->db()->prepare('SELECT content FROM messages WHERE conversation_id = ? ORDER BY id');
        $stmt->execute([$convId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function contient(array $messages, string $texte): bool
    {
        return array_filter($messages, static fn($m) => str_contains((string)$m, $texte)) !== [];
    }

    /** Un classeur minimal (une feuille, chaînes partagées). */
    private function classeur(array $rows): string
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $shared = [];
        $data = '';
        foreach ($rows as $r => $row) {
            $data .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $v) {
                $ref = chr(65 + $c) . ($r + 1);
                if (is_int($v)) {
                    $data .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                    continue;
                }
                $shared[] = $v;
                $data .= '<c r="' . $ref . '" t="s"><v>' . (count($shared) - 1) . '</v></c>';
            }
            $data .= '</row>';
        }
        $path = UPLOAD_DIR . 'test_loop_' . bin2hex(random_bytes(6)) . '.xlsx';
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('xl/workbook.xml', '<workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Clients" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="' . $ns . '"><sheetData>' . $data . '</sheetData></worksheet>');
        $z->addFromString('xl/sharedStrings.xml', '<sst xmlns="' . $ns . '">' . implode('', array_map(static fn($s) => '<si><t>' . htmlspecialchars($s) . '</t></si>', $shared)) . '</sst>');
        $z->close();
        $this->fichiers[] = $path;
        return $path;
    }

    /** Un workflow : une étape remet une liste, une boucle la parcourt. */
    private static function surUneListe(array $loopExtra = [], array $body = []): array
    {
        return [
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'lister', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'Liste pour @{depart}.',
                    'output' => ['key' => 'liste', 'name' => 'Liste', 'form' => 'list']],
                $loopExtra + ['key' => 'chaque', 'type' => 'loop', 'title' => 'Chaque', 'mode' => 'each', 'over' => 'liste',
                    'source' => ['kind' => 'list', 'from' => 'piece'],
                    'item' => ['key' => 'el', 'name' => 'Élément en cours'],
                    'body' => $body !== [] ? $body : [['key' => 'faire', 'type' => 'step', 'title' => 'Faire', 'instruction' => 'Traite @{el}.',
                        'output' => ['key' => 'res', 'name' => 'Résultat', 'form' => 'text']]],
                    'output' => ['key' => 'tout', 'name' => 'Tout']],
            ],
            'end' => ['pieces' => ['tout']],
        ];
    }

    // ------------------------------------------------------------------
    // Lignes d'un classeur
    // ------------------------------------------------------------------

    public function testUneBoucleParcourtLesLignesDuClasseurDeDepartGardeesParLeFiltre(): void
    {
        $path = $this->classeur([['Suivi'], ['Client', 'Montant'], ['Alpha', 10], ['Beta', 0], ['Gamma', 5]]);
        $def = [
            'start' => ['fields' => [['key' => 'fichier', 'label' => 'Fichier', 'type' => 'file', 'required' => true]]],
            'nodes' => [['key' => 'chaque', 'type' => 'loop', 'title' => 'Chaque client', 'mode' => 'each', 'over' => 'fichier',
                'source' => ['kind' => 'rows', 'from' => 'piece', 'columns' => [
                    ['key' => 'client', 'label' => 'Client', 'type' => 'text'], ['key' => 'montant', 'label' => 'Montant', 'type' => 'number'],
                ]],
                'filter' => ['rule' => ['join' => 'all', 'items' => [['piece' => 'client_en_cours', 'field' => 'montant', 'op' => 'gt', 'value' => '0']]]],
                'item' => ['key' => 'client_en_cours', 'name' => 'Client en cours'],
                'body' => [['key' => 'relancer', 'type' => 'step', 'title' => 'Relancer', 'instruction' => 'Relance @{client_en_cours.client}.',
                    'output' => ['key' => 'relance', 'name' => 'Relance', 'form' => 'fiche', 'fields' => [['label' => 'Objet', 'type' => 'text']]]]],
                'output' => ['key' => 'relances', 'name' => 'Relances']]],
            'end' => ['pieces' => ['relances']],
        ];
        $res = $this->lance($this->publie($def), [], ['fichier' => ['name' => 'suivi.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'path' => $path, 'text' => '']]);
        $runId = $res['run_id'];

        $split = $this->jobWorkflow($runId, 'loop_split');
        $this->assertSame(['elements' => 3], WorkflowEngine::runLoopSplitJob($split), 'Trois lignes lues sous l’en-tête.');
        $items = WorkflowRun::artifact($runId, 'chaque~items')['content'];
        $this->assertSame(['Alpha', 'Gamma'], $items['items'], 'Beta doit 0 : laissé par le filtre.');
        $msgs = $this->messages((int)$res['conversation_id']);
        $this->assertTrue($this->contient($msgs, '1 hors filtre'), implode("\n", $msgs));

        $p1 = $this->payload($this->dernierJob($runId))['workflow'];
        $this->assertSame('relancer#1', $p1['node_key']);
        $this->assertStringContainsString("- Client : Alpha\n- Montant : 10", $p1['prompt'], 'Les colonnes déclarées sont les champs de l’élément.');
        $etape = $this->ligne("SELECT content FROM messages WHERE conversation_id = ? AND role = 'user' ORDER BY id DESC LIMIT 1", [(int)$res['conversation_id']]);
        $this->assertStringContainsString('Client en cours', (string)$etape['content'], 'Le message de l’étape cite l’élément.');
        $this->repond($this->dernierJob($runId), "```json\n{\"objet\": \"Rappel Alpha\"}\n```");
        $this->repond($this->dernierJob($runId), "```json\n{\"objet\": \"Rappel Gamma\"}\n```");

        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $rows = WorkflowRun::artifact($runId, 'relances')['content']['rows'];
        $this->assertSame([['Client', 'Montant', 'Objet'], ['Alpha', '10', 'Rappel Alpha'], ['Gamma', '5', 'Rappel Gamma']], $rows,
            'Le fichier complété : ses colonnes d’origine, puis ce que chaque tour a remis.');
        $this->assertSame('dd', WorkflowRun::lastStep($runId, 'chaque')['detail']['st']);
    }

    public function testUneColonneAbsenteArreteLaBoucleAvantLePremierTour(): void
    {
        $path = $this->classeur([['Nom', 'Ville'], ['Alpha', 'Lyon']]);
        $def = [
            'start' => ['fields' => [['key' => 'fichier', 'label' => 'Fichier', 'type' => 'file', 'required' => true]]],
            'nodes' => [['key' => 'chaque', 'type' => 'loop', 'title' => 'Chaque', 'mode' => 'each', 'over' => 'fichier',
                'source' => ['kind' => 'rows', 'from' => 'piece', 'columns' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]],
                'item' => ['key' => 'el', 'name' => 'Ligne'],
                'body' => [['key' => 'x', 'type' => 'step', 'title' => 'X', 'instruction' => '@{el}', 'output' => ['key' => 'px', 'name' => 'PX', 'form' => 'text']]],
                'output' => ['key' => 'tout', 'name' => 'Tout']]],
            'end' => ['pieces' => ['tout']],
        ];
        $res = $this->lance($this->publie($def), [], ['fichier' => ['name' => 'c.xlsx', 'type' => '', 'path' => $path, 'text' => '']]);
        $this->assertSame(['failed' => true], WorkflowEngine::runLoopSplitJob($this->jobWorkflow($res['run_id'], 'loop_split')));
        $run = WorkflowRun::find($res['run_id']);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);
        $this->assertStringContainsString('Colonnes trouvées : Nom, Ville', (string)($run['error']['message'] ?? ''));
        $this->assertSame(0, (int)$this->ligne("SELECT COUNT(*) AS n FROM workflow_run_steps WHERE run_id = ? AND node_key LIKE 'x#%'", [$res['run_id']])['n'], 'Aucun tour, rien de dépensé.');
    }

    // ------------------------------------------------------------------
    // Échecs : continuer, pause, refaire les échecs
    // ------------------------------------------------------------------

    public function testUnElementEnEchecNArretePasLesAutresPuisSeRefaitSeul(): void
    {
        $res = $this->lance($this->publie(self::surUneListe()), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($this->dernierJob($runId), "- Alpha\n- Beta\n- Gamma");

        $this->echoue($this->dernierJob($runId));
        $msgs = $this->messages((int)$res['conversation_id']);
        $this->assertTrue($this->contient($msgs, 'la boucle passe au suivant'), 'L’échec est dit, la boucle continue.');
        $p2 = $this->payload($this->dernierJob($runId))['workflow'];
        $this->assertSame('faire#2', $p2['node_key']);
        $this->repond($this->dernierJob($runId), 'Beta traité.');
        $this->repond($this->dernierJob($runId), 'Gamma traité.');

        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status'], 'Le livrable est remis malgré l’élément en échec.');
        $this->assertSame('fdd', WorkflowRun::lastStep($runId, 'chaque')['detail']['st']);
        $rows = WorkflowRun::artifact($runId, 'tout')['content'];
        $this->assertStringContainsString('en échec', json_encode($rows, JSON_UNESCAPED_UNICODE));

        // Refaire les éléments en échec : seul Alpha repart, la suite est refaite.
        $jobs = WorkflowEngine::retryFailedItems($run, 'chaque');
        $this->assertNotSame([], $jobs);
        $p = $this->payload($this->dernierJob($runId))['workflow'];
        $this->assertSame('faire#1', $p['node_key']);
        $this->assertStringContainsString('Alpha', $p['prompt']);
        $this->repond($this->dernierJob($runId), 'Alpha traité enfin.');
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertSame('ddd', WorkflowRun::lastStep($runId, 'chaque')['detail']['st']);
        $this->assertStringNotContainsString('en échec', json_encode(WorkflowRun::artifact($runId, 'tout')['content'], JSON_UNESCAPED_UNICODE));
        $this->expectException(InvalidArgumentException::class);
        WorkflowEngine::retryFailedItems(WorkflowRun::find($runId), 'chaque');
    }

    public function testTroisEchecsDAffileeMettentEnPauseEtLaRepriseRefaitLElement(): void
    {
        $res = $this->lance($this->publie(self::surUneListe()), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($this->dernierJob($runId), "- A\n- B\n- C\n- D");
        $this->echoue($this->dernierJob($runId));
        $this->echoue($this->dernierJob($runId));
        $this->echoue($this->dernierJob($runId));
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::PAUSED, $run['status'], 'Trois échecs d’affilée : la cause est sans doute commune.');
        $this->assertTrue($this->contient($this->messages((int)$res['conversation_id']), 'ont échoué d’affilée'));

        WorkflowEngine::retry($run);
        $p = $this->payload($this->dernierJob($runId))['workflow'];
        $this->assertSame('faire#3', $p['node_key'], 'La reprise refait l’élément qui a mis en pause.');
        $this->repond($this->dernierJob($runId), 'C traité.');
        $this->repond($this->dernierJob($runId), 'D traité.');
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertSame('ffdd', WorkflowRun::lastStep($runId, 'chaque')['detail']['st']);
    }

    public function testUnEchecArreteToutQuandLaBoucleLeDemande(): void
    {
        $res = $this->lance($this->publie(self::surUneListe(['on_item_error' => 'stop'])), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($this->dernierJob($runId), "- A\n- B");
        $this->echoue($this->dernierJob($runId));
        $this->assertSame(WorkflowRun::FAILED, WorkflowRun::find($runId)['status']);
    }

    // ------------------------------------------------------------------
    // Condition dans le corps
    // ------------------------------------------------------------------

    public function testUneConditionDansLaBoucleEcarteUnElementEtPasseAuSuivant(): void
    {
        $body = [
            ['key' => 'lire', 'type' => 'step', 'title' => 'Lire', 'instruction' => '@{el}',
                'output' => ['key' => 'avis', 'name' => 'Avis', 'form' => 'fiche', 'fields' => [['label' => 'Utile', 'type' => 'yesno']]]],
            ['key' => 'garde', 'type' => 'condition', 'title' => 'Utile ?', 'mode' => 'rule',
                'rule' => ['join' => 'all', 'items' => [['piece' => 'avis', 'field' => 'utile', 'op' => 'yes', 'value' => '']]],
                'yes' => ['then' => 'continue', 'nodes' => []], 'no' => ['then' => 'skip', 'nodes' => []]],
            ['key' => 'agir', 'type' => 'step', 'title' => 'Agir', 'instruction' => '@{avis}', 'output' => ['key' => 'fait', 'name' => 'Fait', 'form' => 'text']],
        ];
        $res = $this->lance($this->publie(self::surUneListe(['collect' => 'fait'], $body)), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($this->dernierJob($runId), "- Alpha\n- Beta");
        $this->repond($this->dernierJob($runId), "```json\n{\"utile\": \"oui\"}\n```");
        $this->assertSame('agir#1', $this->payload($this->dernierJob($runId))['workflow']['node_key'], 'Oui : le tour continue.');
        $this->repond($this->dernierJob($runId), 'Fait pour Alpha.');
        $this->repond($this->dernierJob($runId), "```json\n{\"utile\": \"non\"}\n```");

        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status'], 'Non : Beta est écarté, la boucle finit.');
        $this->assertSame([], $this->ligne("SELECT id FROM workflow_run_steps WHERE run_id = ? AND node_key = 'agir#2'", [$runId]));
        $this->assertSame('ds', WorkflowRun::lastStep($runId, 'chaque')['detail']['st']);
        $journal = WorkflowJournal::entries(WorkflowRun::find($runId), WorkflowRun::steps($runId), ['artifacts' => WorkflowRun::artifacts($runId)], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->assertTrue($this->contient(array_map(static fn($e) => implode(' ', $e['chips']), $journal), 'Élément écarté'));
    }

    // ------------------------------------------------------------------
    // Relectures : la liste avant, les actions après
    // ------------------------------------------------------------------

    public function testRelireLaListeAvantDeCommencerNeGardeQueLesElementsCoches(): void
    {
        $res = $this->lance($this->publie(self::surUneListe(['confirm' => true])), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($this->dernierJob($runId), "- Alpha\n- Beta\n- Gamma");
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::WAITING, $run['status']);
        $this->assertSame('chaque' . WorkflowEngine::LIST_SUFFIX, $run['current_key']);
        $gate = WorkflowRun::lastStep($runId, 'chaque' . WorkflowEngine::LIST_SUFFIX);
        $this->assertSame(WorkflowRun::STEP_WAITING, $gate['status']);
        $this->assertNotNull($gate['pending_action_id'], 'Une carte dans la conversation.');

        WorkflowEngine::loopGateResolved($run, $gate, 'approved', ['3', '1']);
        $this->assertSame(['Alpha', 'Gamma'], WorkflowRun::artifact($runId, 'chaque~items')['content']['items']);
        $this->assertStringContainsString('Alpha', $this->payload($this->dernierJob($runId))['workflow']['prompt']);
        $this->repond($this->dernierJob($runId), 'A.');
        $this->assertStringContainsString('Gamma', $this->payload($this->dernierJob($runId))['workflow']['prompt']);
        $this->repond($this->dernierJob($runId), 'G.');
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($runId)['status']);
    }

    public function testLesActionsPrepareesSeRelisentEnsembleALaFin(): void
    {
        $res = $this->lance($this->publie(self::surUneListe(['review_actions' => true])), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $convId = (int)$res['conversation_id'];
        $this->repond($this->dernierJob($runId), "- Alpha\n- Beta");
        $carte = fn(string $titre): int => DrivePendingAction::create($this->userId, $convId, 'creer_tache', ['titre' => $titre], ['action' => 'Créer une tâche', 'fichier' => $titre]);

        $c1 = $carte('Relancer Alpha');
        $this->repond($this->dernierJob($runId), 'Tâche préparée pour Alpha.', [$c1]);
        $this->assertSame('prepared', WorkflowRun::lastStep($runId, 'faire#1')['outcome'], 'Le tour continue sans attendre la carte.');
        $c2 = $carte('Relancer Beta');
        $this->repond($this->dernierJob($runId), 'Tâche préparée pour Beta.', [$c2]);

        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::WAITING, $run['status']);
        $this->assertSame('chaque' . WorkflowEngine::ACTIONS_SUFFIX, $run['current_key']);
        $this->assertSame([$c1 => 1, $c2 => 2], WorkflowEngine::preparedActions($run, WorkflowSpec::node($run['definition'], 'chaque')));
        $gate = WorkflowRun::lastStep($runId, 'chaque' . WorkflowEngine::ACTIONS_SUFFIX);

        // Seule la première part ; la seconde est écartée.
        WorkflowEngine::loopGateResolved($run, $gate, 'approved', [(string)$c1]);
        $this->assertSame('cancelled', DrivePendingAction::statusOf($c2)['status']);
        $out = WorkflowEngine::runLoopActionsJob($this->jobWorkflow($runId, 'loop_actions'));
        $this->assertIsArray($out);
        $this->assertNotSame('pending', DrivePendingAction::statusOf($c1)['status'], 'L’action retenue a été tranchée (sans connecteur, elle échoue).');
        $run = WorkflowRun::find($runId);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $done = WorkflowRun::lastStep($runId, 'chaque' . WorkflowEngine::ACTIONS_SUFFIX);
        $this->assertSame([$c1], $done['detail']['approved']);
        $this->assertSame([$c2], $done['detail']['refused']);
    }

    public function testNEnExecuterAucuneEcarteToutEtContinue(): void
    {
        $res = $this->lance($this->publie(self::surUneListe(['review_actions' => true])), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $convId = (int)$res['conversation_id'];
        $this->repond($this->dernierJob($runId), '- Alpha');
        $c1 = DrivePendingAction::create($this->userId, $convId, 'creer_tache', ['titre' => 'A'], ['action' => 'Créer une tâche']);
        $this->repond($this->dernierJob($runId), 'Préparé.', [$c1]);
        $run = WorkflowRun::find($runId);
        WorkflowEngine::loopGateResolved($run, WorkflowRun::lastStep($runId, 'chaque' . WorkflowEngine::ACTIONS_SUFFIX), 'none');
        $this->assertSame('cancelled', DrivePendingAction::statusOf($c1)['status']);
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($runId)['status']);
    }

    // ------------------------------------------------------------------
    // MIA repère, fichiers d'un dossier
    // ------------------------------------------------------------------

    public function testMiaRepereLesElementsDUnTexteUneSeuleFois(): void
    {
        $appels = 0;
        WorkflowAi::overrideCompletion(static function () use (&$appels): string {
            $appels++;
            return "```json\n[{\"element\": \"Devis\", \"urgence\": \"haute\"}, {\"element\": \"Planning\", \"urgence\": \"basse\"}]\n```";
        });
        $def = [
            'start' => ['fields' => [['key' => 'email', 'label' => 'Email', 'type' => 'longtext', 'required' => true]]],
            'nodes' => [['key' => 'sujets', 'type' => 'loop', 'title' => 'Chaque sujet', 'mode' => 'each', 'over' => 'depart',
                'source' => ['kind' => 'ai', 'instruction' => 'Chaque sujet abordé dans @{depart}', 'fields' => [['key' => 'urgence', 'label' => 'Urgence', 'type' => 'text']]],
                'item' => ['key' => 'sujet', 'name' => 'Sujet en cours'],
                'body' => [['key' => 'traiter', 'type' => 'step', 'title' => 'Traiter', 'instruction' => 'Traite @{sujet}.', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'text']]],
                'output' => ['key' => 'tout', 'name' => 'Tout']]],
            'end' => ['pieces' => ['tout']],
        ];
        $res = $this->lance($this->publie($def), ['email' => 'Bonjour, il nous faut un devis et un planning.']);
        $runId = $res['run_id'];
        $this->assertSame(['elements' => 2], WorkflowEngine::runLoopSplitJob($this->jobWorkflow($runId, 'loop_split')));
        $this->assertSame(1, $appels, 'Un appel découpe ; les tours n’y reviennent pas.');
        $this->assertSame(['Devis', 'Planning'], WorkflowRun::artifact($runId, 'sujets~items')['content']['items']);
        $p = $this->payload($this->dernierJob($runId))['workflow']['prompt'];
        $this->assertStringContainsString('Devis', $p);
        $this->assertStringContainsString('haute', $p);
    }

    public function testLesFichiersDUnDossierDriveSontJointsAuTourQuiLesTraite(): void
    {
        $svc = new class {
            public function folderFiles(string $input, int $limit): array
            {
                return ['dossier' => ['id' => 'd', 'nom' => 'Contrats'], 'total' => 1, 'fichiers' => [
                    ['id' => 'f1', 'nom' => 'Contrat.txt', 'mime' => 'text/plain', 'type' => 'Texte', 'modifie' => '2026-10-01T10:00:00Z', 'taille' => 20, 'lien' => 'https://drive.google.com/file/d/f1/view'],
                ]];
            }
            public function downloadFile(string $id): array
            {
                return ['id' => $id, 'nom' => 'Contrat.txt', 'mime' => 'text/plain', 'bytes' => "Contrat de test\nClause 1", 'lien' => ''];
            }
        };
        LoopRunner::overrideServices(static fn(string $c) => $svc);
        $def = [
            'start' => ['fields' => [['key' => 'quoi', 'label' => 'Quoi', 'type' => 'text', 'required' => true]]],
            'nodes' => [['key' => 'docs', 'type' => 'loop', 'title' => 'Chaque contrat', 'mode' => 'each',
                'source' => ['kind' => 'files', 'from' => 'drive', 'drive' => 'Contrats'],
                'item' => ['key' => 'contrat', 'name' => 'Contrat en cours'],
                'body' => [['key' => 'lire', 'type' => 'step', 'title' => 'Lire', 'instruction' => 'Résume @{contrat}.', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'text']]],
                'output' => ['key' => 'tout', 'name' => 'Tout']]],
            'end' => ['pieces' => ['tout']],
        ];
        $res = $this->lance($this->publie($def), ['quoi' => 'x']);
        $runId = $res['run_id'];
        $this->assertSame(['elements' => 1], WorkflowEngine::runLoopSplitJob($this->jobWorkflow($runId, 'loop_split')));
        WorkflowEngine::runLoopItemJob($this->jobWorkflow($runId, 'loop_item'));

        $item = WorkflowRun::artifact($runId, 'contrat#1')['content'];
        $this->assertSame('Contrat.txt', $item['file']['name']);
        $this->assertFileExists($item['file']['path']);
        $this->fichiers[] = $item['file']['path'];
        $p = $this->payload($this->dernierJob($runId))['workflow']['prompt'];
        $this->assertStringContainsString("joint au message de l'étape", $p, 'Le fichier part avec le message de l’étape, pas en double dans la consigne.');
        $msg = $this->ligne("SELECT files_json FROM messages WHERE conversation_id = ? AND role = 'user' ORDER BY id DESC LIMIT 1", [(int)$res['conversation_id']]);
        $this->assertStringContainsString('Contrat.txt', (string)$msg['files_json']);
        $this->repond($this->dernierJob($runId), 'Résumé du contrat.');
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($runId)['status']);
    }

    public function testLePlafondDeLAdministrateurBorneLaBoucle(): void
    {
        Settings::set(Workflow::SETTING_LOOP_MAX, '50');
        $this->assertSame(50, Workflow::loopMax());
        $res = $this->lance($this->publie(self::surUneListe(['max' => 500])), ['sujet' => 'x']);
        $runId = $res['run_id'];
        $this->repond($this->dernierJob($runId), implode("\n", array_map(static fn($i) => '- Élément ' . $i, range(1, 60))));
        $items = WorkflowRun::artifact($runId, 'chaque~items')['content']['items'];
        $this->assertCount(50, $items);
        $this->assertSame(50, WorkflowRun::lastStep($runId, 'chaque')['detail']['count']);
        Settings::set(Workflow::SETTING_LOOP_MAX, '9999');
        $this->assertSame(Workflow::LOOP_MAX_DEFAULT, Workflow::loopMax(), 'Une valeur hors des choix reprend le défaut.');
    }
}
