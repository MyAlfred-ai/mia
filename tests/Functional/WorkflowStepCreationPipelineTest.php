<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Une ÉTAPE qui remet une création (image, page, carte), sur la vraie base :
 * son tour de conversation rédige la demande, puis le moteur enfile le job
 * de création de l'outil choisi — celui d'une brique Création — sur le MÊME
 * passage. La pièce rangée est celle de l'outil ; la trace et les cartes
 * restent celles du tour. Même méthode que les autres tests du moteur : la
 * fin d'un job est SIMULÉE (aucun modèle, aucun générateur appelé) — le
 * « générateur » est un vrai fichier PNG posé dans le dossier des images.
 */
final class WorkflowStepCreationPipelineTest extends DatabaseTestCase
{
    private array $reglagesAvant = [];
    private int $userId = 0;
    private int $groupId = 0;
    /** @var list<string> */
    private array $files = [];

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
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save([
            'provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'),
            'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0,
        ]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
        JobQueue::markWorkerAlive();
        $this->userId = $this->insertUser(41, 'creator');
        $this->groupId = $this->insertGroup('Marketing');
        $db->prepare('INSERT INTO user_group_members (user_id, group_id) VALUES (?, ?)')->execute([$this->userId, $this->groupId]);
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
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

    private function outil(string $type, string $name, string $model = 'gpt-image-2'): int
    {
        $id = ChatTool::save(['name' => $name, 'type' => $type, 'provider' => 'openai', 'model' => $model, 'config_json' => '{}', 'is_active' => 1]);
        $this->db()->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?)')->execute([$this->groupId, $id]);
        return $id;
    }

    private function publie(array $def): array
    {
        $id = Workflow::create($this->userId, 'Campagne', WorkflowSpec::normalize($def));
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), ['produit' => 'Le vélo pliant Atlas'], [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        return WorkflowRun::find($res['run_id']);
    }

    private function ligne(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    private function dernierJob(int $runId): array
    {
        $step = $this->ligne('SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND job_id IS NOT NULL ORDER BY id DESC LIMIT 1', [$runId]);
        $this->assertNotSame([], $step);
        $job = $this->ligne('SELECT id, type, payload_json FROM async_jobs WHERE id = ?', [(int)$step['job_id']]);
        return ['id' => (int)$job['id'], 'type' => (string)$job['type'], 'payload' => json_decode((string)$job['payload_json'], true)];
    }

    private function nbJobs(int $runId): int
    {
        $row = $this->ligne('SELECT COUNT(*) AS n FROM async_jobs WHERE conversation_id = ?', [(int)WorkflowRun::find($runId)['conversation_id']]);
        return (int)$row['n'];
    }

    private function imageGeneree(): string
    {
        $img = imagecreatetruecolor(48, 64);
        ob_start();
        imagepng($img);
        $name = 'wfstep_' . bin2hex(random_bytes(6)) . '.png';
        $path = ROOT . '/public/assets/img/generated/' . $name;
        file_put_contents($path, (string)ob_get_clean());
        $this->files[] = $path;
        return '/assets/img/generated/' . $name;
    }

    /** Fin du tour de l'étape : la demande qu'il a rédigée, avec une ligne de trace. */
    private function finTour(array $run, array $job, string $brief): int
    {
        $msg = Message::create((int)$run['conversation_id'], 'assistant', $brief);
        Message::setActions((int)$msg['id'], [['t' => 'kb_search', 'l' => 'Recherche dans la base Marque', 'ok' => true]]);
        WorkflowEngine::onJobFinished($job['id'], 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
        return (int)$msg['id'];
    }

    /** Fin du job d'image : son message dans la conversation, puis le moteur. */
    private function finImage(array $run, array $job, string $url): int
    {
        $msg = Message::create((int)$run['conversation_id'], 'assistant', "![Image générée]($url)\n\n*Prompt enrichi : Un vélo pliant sur fond bleu*");
        WorkflowEngine::onJobFinished($job['id'], 'done', ['done' => true, 'msg_id' => (int)$msg['id'], 'image_url' => $url, 'visual_prompt' => 'Un vélo pliant, affiche'], null);
        return (int)$msg['id'];
    }

    private function visuel(int $tool, array $extra = [], bool $review = false): array
    {
        return [
            'start' => ['fields' => [['key' => 'produit', 'label' => 'Produit', 'type' => 'text', 'required' => true]]],
            'nodes' => [[
                'key' => 'graphiste', 'type' => 'step', 'title' => 'Préparer le visuel',
                'instruction' => 'Le visuel de lancement de @{depart.produit}, aux couleurs de la marque.',
                'review' => $review, 'on_error' => ['retries' => 1, 'then' => 'stop'],
                'output' => ['key' => 'visuel', 'name' => 'Visuel', 'form' => 'image', 'tool_id' => $tool, 'options' => ['format' => 'portrait']],
            ] + $extra],
            'end' => ['pieces' => ['visuel']],
        ];
    }

    public function testLEtapeRedigeLaDemandePuisLOutilFabriqueLImage(): void
    {
        $tool = $this->outil('image', 'Images de marque');
        $other = $this->outil('image', 'Autre outil image');
        $run = $this->lance($this->publie($this->visuel($tool, ['tool_ids' => [$other]])));

        // 1. Le tour de l'étape : un message de chat ordinaire, sans outil image.
        $chat = $this->dernierJob($run['id']);
        $this->assertSame('chat', $chat['type']);
        $this->assertSame('image', $chat['payload']['workflow']['creates']);
        $this->assertSame([], $chat['payload']['workflow']['tool_ids'], 'L’outil image de l’étape n’est pas exposé au tour : l’image est faite après.');
        $this->assertStringContainsString('DESCRIPTION VISUELLE', $chat['payload']['workflow']['prompt']);
        $this->assertStringContainsString('Ne fabrique pas la pièce toi-même', $chat['payload']['workflow']['prompt']);
        $first = WorkflowRun::lastStep($run['id'], 'graphiste');
        $this->assertSame('running', $first['status']);

        // 2. La demande rédigée → le job du menu « + », sur le même passage.
        $briefId = $this->finTour($run, $chat, "Un vélo pliant Atlas plié, posé sur un fond bleu nuit, lumière de studio.\n\nTexte : « Atlas ».");
        $img = $this->dernierJob($run['id']);
        $this->assertSame('image', $img['type'], 'La fabrication est le job d’une brique Création.');
        $this->assertSame($tool, $img['payload']['tool_id']);
        $this->assertStringStartsWith('Un vélo pliant Atlas plié, posé sur un fond bleu nuit', $img['payload']['prompt']);
        $this->assertStringContainsString('Format attendu : portrait', $img['payload']['prompt']);
        $this->assertSame(['run_id', 'node_key', 'context', 'sources', 'base', 'format', 'background', 'output_format'], array_keys($img['payload']['workflow']));
        $this->assertSame('portrait', $img['payload']['workflow']['format']);
        $mid = WorkflowRun::lastStep($run['id'], 'graphiste');
        $this->assertSame($first['id'], $mid['id'], 'Un seul passage : le job est remplacé.');
        $this->assertSame(['running', 'create', $briefId, 'Images de marque'], [$mid['status'], $mid['detail']['phase'], $mid['detail']['brief_id'], $mid['detail']['tool']]);
        $this->assertSame(WorkflowRun::RUNNING, WorkflowRun::find($run['id'])['status']);

        // Une fin du tour rejouée (reaper, entretien) ne relance rien.
        $before = $this->nbJobs($run['id']);
        WorkflowEngine::onJobFinished($chat['id'], 'done', ['done' => true, 'msg_id' => $briefId], null);
        $this->assertSame($before, $this->nbJobs($run['id']));

        // 3. L'image fabriquée devient la pièce ; la trace reste celle du tour.
        $url = $this->imageGeneree();
        $imgMsg = $this->finImage($run, $img, $url);
        $art = WorkflowRun::artifacts($run['id'])['visuel'];
        $this->assertSame('image', $art['form']);
        $this->assertSame([$url, 48, 64, 'Un vélo pliant, affiche', 'Images de marque'], [$art['content']['url'], $art['content']['width'], $art['content']['height'], $art['content']['prompt'], $art['content']['tool']]);
        $this->assertSame(['Recherche dans la base Marque'], $art['provenance']);
        $done = WorkflowRun::lastStep($run['id'], 'graphiste');
        $this->assertSame(['done', $briefId, $briefId, $imgMsg], [$done['status'], (int)$done['message_id'], (int)$done['detail']['reply_id'], (int)$done['detail']['creation_id']]);
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($run['id'])['status']);

        // Le coût estimé compte le tour ET la fabrication de la pièce.
        $estimate = WorkflowCatalog::estimate(WorkflowRun::find($run['id'])['definition'], WorkflowCatalog::choices(User::findById($this->userId)));
        $this->assertNotNull($estimate);
        $this->assertStringContainsString('puis la création : « Images de marque »', $estimate['lines'][0]['note']);

        // Le fichier de la pièce, et le journal : l'outil, l'aperçu de l'image.
        $file = WorkflowFiles::build(WorkflowRun::find($run['id']), 'visuel', '');
        $this->assertSame(['Visuel.png', 'image/png'], [$file['name'], $file['mime']]);
        $ctx = [
            'artifacts' => WorkflowRun::artifacts($run['id']), 'actions' => [], 'cards' => [], 'profiles' => [], 'llms' => [],
            'image' => static fn(array $c): ?string => (string)$c['url'],
        ];
        $entries = WorkflowJournal::entries(WorkflowRun::find($run['id']), WorkflowRun::steps($run['id']), $ctx, new \DateTimeImmutable());
        $step = array_values(array_filter($entries, static fn(array $e): bool => ($e['key'] ?? '') === 'graphiste'))[0] ?? null;
        $this->assertNotNull($step);
        $this->assertStringContainsString('Images de marque', $step['who']);
        $this->assertSame(['image', $url], [$step['piece']['icon'] ?? null, $step['piece']['image'] ?? null]);
    }

    public function testRenvoyeeAvecUneRemarqueLaFabricationRepartDeSaVersion(): void
    {
        $tool = $this->outil('image', 'Images');
        $run = $this->lance($this->publie($this->visuel($tool, [], true)));
        $this->finTour($run, $this->dernierJob($run['id']), 'Un vélo pliant sur fond blanc.');
        $this->finImage($run, $this->dernierJob($run['id']), $this->imageGeneree());

        $gate = WorkflowRun::lastStep($run['id'], 'graphiste~review');
        $this->assertSame('waiting', $gate['status'], 'La relecture s’ouvre après la fabrication.');
        $this->assertFalse(WorkflowEngine::nodeFor(WorkflowRun::find($run['id'])['definition'], 'graphiste~review')['can_correct'], 'Une image ne se corrige pas à la main.');

        WorkflowEngine::gateResolved(WorkflowRun::find($run['id']), $gate, 'returned', 'Fond bleu nuit');
        $again = $this->dernierJob($run['id']);
        $this->assertSame('chat', $again['type'], 'Le tour repart : il rédige une nouvelle demande.');
        $this->assertStringContainsString('Fond bleu nuit', $again['payload']['workflow']['prompt']);
        $this->finTour($run, $again, 'Le même vélo, sur fond bleu nuit.');
        $img = $this->dernierJob($run['id']);
        $this->assertSame(['visuel'], $img['payload']['workflow']['sources'], 'La version précédente sert d’image de départ.');
        $this->assertStringStartsWith('Correction demandée à la relecture (à appliquer en priorité) : Fond bleu nuit', $img['payload']['prompt']);
        $this->assertStringContainsString('Le même vélo, sur fond bleu nuit.', $img['payload']['prompt']);
    }

    public function testUnePanneDeFabricationSeReessaieSansRefaireLeTour(): void
    {
        $tool = $this->outil('image', 'Images');
        $run = $this->lance($this->publie($this->visuel($tool)));
        $briefId = $this->finTour($run, $this->dernierJob($run['id']), 'Un vélo pliant, affiche verticale.');
        $img = $this->dernierJob($run['id']);
        WorkflowEngine::onJobFinished($img['id'], 'failed', null, ['kind' => 'overloaded', 'title' => 'Service saturé', 'message' => 'Réessayez.']);
        $wait = WorkflowRun::lastStep($run['id'], 'graphiste');
        $this->assertSame(['waiting', 'retry', 'Un vélo pliant, affiche verticale.', $briefId], [$wait['status'], $wait['detail']['kind'], $wait['detail']['brief'], $wait['detail']['brief_id']]);

        WorkflowEngine::resumeNow(WorkflowRun::find($run['id']));
        $retry = $this->dernierJob($run['id']);
        $this->assertSame('image', $retry['type'], 'Le nouvel essai repart de la demande déjà rédigée : pas de second tour.');
        $this->assertStringStartsWith('Un vélo pliant, affiche verticale.', $retry['payload']['prompt']);
        $step = WorkflowRun::lastStep($run['id'], 'graphiste');
        $this->assertSame(['running', 'create', 1], [$step['status'], $step['detail']['phase'], (int)$step['detail']['auto']]);

        $url = $this->imageGeneree();
        $this->finImage($run, $retry, $url);
        $this->assertSame($url, WorkflowRun::artifacts($run['id'])['visuel']['content']['url']);
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($run['id'])['status']);
    }

    public function testUnOutilRetireArreteAvantLeTour(): void
    {
        $tool = $this->outil('image', 'Images');
        $wf = $this->publie($this->visuel($tool));
        $this->db()->prepare('DELETE FROM user_group_tools WHERE tool_id = ?')->execute([$tool]);
        $run = $this->lance($wf);
        $this->assertSame(WorkflowRun::FAILED, WorkflowRun::find($run['id'])['status'], 'L’assistant ne travaille pas pour un outil qui ne servira pas.');
        $this->assertSame(0, $this->nbJobs($run['id']));
        $this->assertStringContainsString('n’est plus ouvert à votre compte', (string)(WorkflowRun::find($run['id'])['error']['message'] ?? ''));
    }

    public function testUneEtapeQuiRemetUnePageWebPasseSaDemandeALOutilPage(): void
    {
        $page = $this->outil('html', 'Pages', 'gpt-5.6');
        $def = [
            'start' => ['fields' => [['key' => 'produit', 'label' => 'Produit', 'type' => 'text', 'required' => true]]],
            'nodes' => [[
                'key' => 'redac', 'type' => 'step', 'title' => 'Rédiger la page', 'instruction' => 'La page produit de @{depart.produit}.',
                'output' => ['key' => 'site', 'name' => 'Site', 'form' => 'page', 'tool_id' => $page],
            ]],
            'end' => ['pieces' => ['site']],
        ];
        $run = $this->lance($this->publie($def));
        $chat = $this->dernierJob($run['id']);
        $this->assertSame(['chat', 'page'], [$chat['type'], $chat['payload']['workflow']['creates']]);
        $this->assertStringContainsString('TOUT LE CONTENU de la page web', $chat['payload']['workflow']['prompt']);
        $this->finTour($run, $chat, "# Atlas\n\nLe vélo pliant qui tient dans un placard.");
        $html = $this->dernierJob($run['id']);
        $this->assertSame('html', $html['type']);
        $this->assertSame($page, $html['payload']['tool_id']);
        $this->assertStringStartsWith("# Atlas\n\nLe vélo pliant", $html['payload']['prompt']);

        $msg = Message::create((int)$run['conversation_id'], 'assistant', '<html><head><title>Atlas</title></head><body><h1>Atlas</h1></body></html>', [['type' => 'html_page', 'name' => 'page.html']]);
        WorkflowEngine::onJobFinished($html['id'], 'done', ['done' => true, 'msg_id' => (int)$msg['id'], 'illustrated' => false], null);
        $site = WorkflowRun::artifacts($run['id'])['site'];
        $this->assertSame(['page', 'Atlas', 'Pages'], [$site['form'], $site['content']['title'], $site['content']['tool']]);
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($run['id'])['status']);
    }

    public function testUneDemandeVideSeRedemandeUneFois(): void
    {
        $tool = $this->outil('image', 'Images');
        $run = $this->lance($this->publie($this->visuel($tool)));
        $this->finTour($run, $this->dernierJob($run['id']), '![x](/assets/img/generated/rien.png)');
        $again = $this->dernierJob($run['id']);
        $this->assertSame('chat', $again['type'], 'Pas de demande : le tour est redemandé, aucune image n’est fabriquée.');
        $this->assertStringContainsString("ne contient pas la demande à transmettre à l'outil de création", $again['payload']['workflow']['prompt']);
    }
}
