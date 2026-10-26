<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Brique « Création » sur la vraie base : elle enfile le job du menu « + »
 * du chat (image, page, carte) avec son bloc `workflow`, range ce qu'il a
 * produit au dossier, se refait d'après une remarque, ne part pas avec un
 * outil retiré ; l'étape suivante voit l'image, un email la joint, le
 * balayage de l'élagage la garde. Même méthode que les autres tests du
 * moteur : la fin d'un job est SIMULÉE (aucun modèle appelé).
 */
final class WorkflowCreationPipelineTest extends DatabaseTestCase
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
        require_once ROOT . '/src/Maintenance/PruningService.php';
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, 'default_llm', 'job_worker_heartbeat_at'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        // Un modèle pour l'étape qui suit une création (aucun appel : les jobs sont simulés).
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save([
            'provider' => 'openai', 'model' => LlmCatalog::defaultModel('openai'),
            'display_name' => 'Défaut', 'is_active' => 1, 'sort_order' => 0,
        ]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
        JobQueue::markWorkerAlive();
        $this->userId = $this->insertUser(31, 'creator');
        $this->groupId = $this->insertGroup('Communication');
        $this->db()->prepare('INSERT INTO user_group_members (user_id, group_id) VALUES (?, ?)')->execute([$this->userId, $this->groupId]);
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

    /** Un outil de création ouvert au groupe de la personne. */
    private function outil(string $type, string $name, string $provider = 'openai', string $model = 'gpt-image-2'): int
    {
        $id = ChatTool::save(['name' => $name, 'type' => $type, 'provider' => $provider, 'model' => $model, 'config_json' => '{}', 'is_active' => 1]);
        $this->db()->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?)')->execute([$this->groupId, $id]);
        return $id;
    }

    private function publie(array $def): array
    {
        $id = Workflow::create($this->userId, 'Communication', WorkflowSpec::normalize($def));
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), ['sujet' => 'Le salon du vélo'], [], 'manual', null, 'Europe/Paris');
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

    /** Une image « générée » par le job simulé : un vrai fichier PNG du dossier des images. */
    private function imageGeneree(): string
    {
        $img = imagecreatetruecolor(64, 32);
        ob_start();
        imagepng($img);
        $name = 'wftest_' . bin2hex(random_bytes(6)) . '.png';
        $path = ROOT . '/public/assets/img/generated/' . $name;
        file_put_contents($path, (string)ob_get_clean());
        $this->files[] = $path;
        return '/assets/img/generated/' . $name;
    }

    /** Fin d'un job d'image : son message dans la conversation, puis le moteur. */
    private function finImage(array $run, array $job, string $url): void
    {
        $msg = Message::create((int)$run['conversation_id'], 'assistant', "![Image générée]($url)");
        WorkflowEngine::onJobFinished($job['id'], 'done', ['done' => true, 'msg_id' => (int)$msg['id'], 'image_url' => $url, 'visual_prompt' => 'Un vélo sur une affiche'], null);
    }

    private static function illustre(bool $review = false, array $then = []): array
    {
        return [
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true]]],
            'nodes' => array_merge([
                ['key' => 'vis', 'type' => 'create', 'title' => 'Illustrer', 'kind' => 'image', 'tool_id' => 0,
                    'instruction' => 'Une affiche pour @{depart.sujet}', 'options' => ['format' => 'portrait'], 'review' => $review,
                    'on_error' => ['retries' => 1, 'then' => 'stop'],
                    'output' => ['key' => 'visuel', 'name' => 'Visuel']],
            ], $then),
            'end' => ['pieces' => ['visuel']],
        ];
    }

    private function avecOutil(array $def, int $toolId): array
    {
        $def['nodes'][0]['tool_id'] = $toolId;
        return $def;
    }

    public function testUneImageEstCreeeParLeJobDuChatEtRangeeAuDossier(): void
    {
        $tool = $this->outil('image', 'Images de marque');
        $run = $this->lance($this->publie($this->avecOutil(self::illustre(), $tool)));
        $job = $this->dernierJob($run['id']);
        $this->assertSame('image', $job['type'], 'Le job du menu « + » du chat.');
        $this->assertSame($tool, $job['payload']['tool_id']);
        $this->assertStringContainsString('Une affiche pour Le salon du vélo', $job['payload']['prompt'], 'Un champ cité part avec sa valeur.');
        $this->assertStringContainsString('Format attendu : portrait', $job['payload']['prompt']);
        $this->assertSame(['run_id', 'node_key', 'context', 'sources', 'base', 'format'], array_keys($job['payload']['workflow']));
        $this->assertSame('portrait', $job['payload']['workflow']['format']);
        $this->assertStringContainsString('« Informations de départ » : - Sujet : Le salon du vélo', $job['payload']['workflow']['context']);
        $step = WorkflowRun::lastStep($run['id'], 'vis');
        $this->assertSame(['running', 'Images de marque'], [$step['status'], $step['detail']['tool']]);
        $first = $this->ligne('SELECT content FROM messages WHERE id = ?', [(int)$step['detail']['user_msg_id']]);
        $this->assertStringStartsWith("Création — Illustrer\n\n", $first['content']);
        $this->assertStringNotContainsString('**', $first['content'], 'Texte simple : le chat n’interprète pas le Markdown d’un message de la personne.');
        $this->assertNull(Conversation::findById((int)$run['conversation_id'])['profile_id'], 'Une création se fait sans assistant.');

        $url = $this->imageGeneree();
        $this->finImage($run, $job, $url);
        $art = WorkflowRun::artifacts($run['id'])['visuel'];
        $this->assertSame('image', $art['form']);
        $this->assertSame([$url, 64, 32, 'Un vélo sur une affiche', 'Images de marque'], [$art['content']['url'], $art['content']['width'], $art['content']['height'], $art['content']['prompt'], $art['content']['tool']]);
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($run['id'])['status'], 'Plus rien après : le livrable, et c’est fini.');

        // Le fichier de la pièce, et sa pièce jointe d'email.
        $file = WorkflowFiles::build(WorkflowRun::find($run['id']), 'visuel', '');
        $this->assertSame(['Visuel.png', 'image/png'], [$file['name'], $file['mime']]);
        $att = WorkflowDeliveries::attachmentsFor([WorkflowDeliveries::ATTACH_KEY => [['run_id' => $run['id'], 'piece' => 'visuel']]], User::findById($this->userId), (int)$run['conversation_id']);
        $this->assertSame('Visuel.png', $att[0]['name']);
    }

    public function testUneImageRelueEtRenvoyeeRepartDeSaVersion(): void
    {
        $tool = $this->outil('image', 'Images');
        $run = $this->lance($this->publie($this->avecOutil(self::illustre(true), $tool)));
        $job = $this->dernierJob($run['id']);
        $this->finImage($run, $job, $this->imageGeneree());
        $gate = WorkflowRun::lastStep($run['id'], 'vis~review');
        $this->assertSame('waiting', $gate['status'], 'La relecture s’ouvre après la création.');

        $jobs = WorkflowEngine::gateResolved(WorkflowRun::find($run['id']), $gate, 'returned', 'Fond bleu nuit');
        $this->assertCount(1, $jobs);
        $again = $this->dernierJob($run['id']);
        $this->assertSame('image', $again['type']);
        $this->assertSame(['visuel'], $again['payload']['workflow']['sources'], 'La version précédente sert d’image de départ.');
        $this->assertStringStartsWith('Correction demandée à la relecture (à appliquer en priorité) : Fond bleu nuit', $again['payload']['prompt']);
        $step = WorkflowRun::lastStep($run['id'], 'vis');
        $this->assertSame('Fond bleu nuit', $step['detail']['remark']);
    }

    public function testLEtapeSuivanteVoitLImage(): void
    {
        $tool = $this->outil('image', 'Images');
        $then = [['key' => 'decrire', 'type' => 'step', 'title' => 'Décrire', 'instruction' => 'Décris @{visuel}',
            'output' => ['key' => 'legende', 'name' => 'Légende', 'form' => 'text']]];
        $run = $this->lance($this->publie($this->avecOutil(self::illustre(false, $then), $tool)));
        $url = $this->imageGeneree();
        $this->finImage($run, $this->dernierJob($run['id']), $url);

        $next = $this->dernierJob($run['id']);
        $this->assertSame('chat', $next['type']);
        $this->assertStringContainsString("[image jointe au message de l'étape] Image : Un vélo sur une affiche", $next['payload']['workflow']['prompt']);
        $msg = $this->ligne('SELECT files_json FROM messages WHERE id = ?', [(int)$next['payload']['user_msg_id']]);
        $files = json_decode((string)$msg['files_json'], true);
        $this->assertSame('image/png', $files[0]['type']);
        $this->assertSame(realpath(ROOT . '/public' . $url), $files[0]['path'], 'L’image elle-même, jointe au message de l’étape.');
        $this->assertTrue(WorkflowPrompt::readableFile($files[0]['path']));
        $this->assertFalse(WorkflowPrompt::readableFile('/etc/hosts'));
    }

    public function testUnePageEtUneCarteSeRangentAussi(): void
    {
        $page = $this->outil('html', 'Pages', 'openai', 'gpt-5.6');
        $map = $this->outil('map', 'Cartes', 'openai', 'gpt-5.6');
        $def = [
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'pg', 'type' => 'create', 'title' => 'Page', 'kind' => 'page', 'tool_id' => $page, 'instruction' => 'Une page sur @{depart}', 'output' => ['key' => 'site', 'name' => 'Site']],
                ['key' => 'tr', 'type' => 'create', 'title' => 'Trajet', 'kind' => 'map', 'tool_id' => $map, 'instruction' => 'La tournée', 'output' => ['key' => 'trajet', 'name' => 'Trajet']],
            ],
            'end' => ['pieces' => ['site', 'trajet']],
        ];
        $run = $this->lance($this->publie($def));
        $job = $this->dernierJob($run['id']);
        $this->assertSame('html', $job['type']);
        $this->assertStringContainsString("DONNÉES DU DOSSIER", $job['payload']['prompt']);
        $this->assertStringContainsString('Sujet : Le salon du vélo', $job['payload']['prompt']);
        $html = '<html><head><title>Salon du vélo</title></head><body><h1>Salon</h1><p>Samedi à Lyon.</p></body></html>';
        $msg = Message::create((int)$run['conversation_id'], 'assistant', $html, [['type' => 'html_page', 'name' => 'page.html']]);
        WorkflowEngine::onJobFinished($job['id'], 'done', ['done' => true, 'msg_id' => (int)$msg['id'], 'illustrated' => false], null);
        $site = WorkflowRun::artifacts($run['id'])['site'];
        $this->assertSame(['page', 'Salon du vélo'], [$site['form'], $site['content']['title']]);

        $job2 = $this->dernierJob($run['id']);
        $this->assertSame('map', $job2['type'], 'La carte part dans son job `map`.');
        $spec = ['title' => 'Tournée', 'waypoints' => [['name' => 'Lyon', 'query' => 'Lyon', 'lat' => 45.76, 'lon' => 4.83], ['name' => 'Vienne', 'query' => 'Vienne', 'lat' => 45.52, 'lon' => 4.87]]];
        $msg2 = Message::create((int)$run['conversation_id'], 'assistant', (string)json_encode($spec), [['type' => 'map_route', 'name' => 'itineraire.json']]);
        WorkflowEngine::onJobFinished($job2['id'], 'done', ['done' => true, 'msg_id' => (int)$msg2['id'], 'map' => $spec], null);
        $this->assertSame('Tournée', WorkflowRun::artifacts($run['id'])['trajet']['content']['spec']['title']);
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($run['id'])['status']);

        $doc = WorkflowFiles::build(WorkflowRun::find($run['id']), 'trajet', 'md');
        $this->assertSame('Trajet.md', $doc['name']);
        $this->assertStringContainsString('1. **Lyon**', $doc['bytes']);
        $pageFile = WorkflowFiles::build(WorkflowRun::find($run['id']), 'site', '');
        $this->assertSame(['Site.html', 'text/html'], [$pageFile['name'], $pageFile['mime']]);
    }

    public function testUnePanneSeReessaieEtUnOutilRetireArrete(): void
    {
        $tool = $this->outil('image', 'Images');
        $run = $this->lance($this->publie($this->avecOutil(self::illustre(), $tool)));
        $job = $this->dernierJob($run['id']);
        WorkflowEngine::onJobFinished($job['id'], 'failed', null, ['kind' => 'server', 'title' => 'Service indisponible', 'message' => 'Le fournisseur ne répond pas.']);
        $wait = WorkflowRun::lastStep($run['id'], 'vis');
        $this->assertSame(['waiting', 'retry'], [$wait['status'], $wait['detail']['kind']], 'Une panne passagère se réessaie seule.');

        // L'outil n'est plus ouvert au groupe : la création ne part pas.
        $this->db()->prepare('DELETE FROM user_group_tools WHERE tool_id = ?')->execute([$tool]);
        $run2 = $this->lance($this->publie($this->avecOutil(self::illustre(), $tool)));
        $this->assertSame(WorkflowRun::FAILED, WorkflowRun::find($run2['id'])['status']);
        $this->assertStringContainsString('n’est plus ouvert à votre compte', (string)(WorkflowRun::find($run2['id'])['error']['message'] ?? ''));
    }

    public function testLElagageGardeLesFichiersDuDossier(): void
    {
        $tmp = sys_get_temp_dir() . '/wfprune_' . bin2hex(random_bytes(4));
        mkdir($tmp);
        $keep = 'wfkeep_' . bin2hex(random_bytes(4)) . '.png';
        $drop = 'wfdrop_' . bin2hex(random_bytes(4)) . '.png';
        foreach ([$keep, $drop] as $f) {
            file_put_contents($tmp . '/' . $f, 'x');
            touch($tmp . '/' . $f, time() - 3 * 86400);
        }
        $wf = Workflow::create($this->userId, 'W', WorkflowSpec::normalize(self::illustre()));
        $this->db()->prepare("INSERT INTO workflow_runs (workflow_id, user_id, version, status, current_key, definition_json, trigger_kind, timezone) VALUES (?, ?, 1, 'done', 'end', '{}', 'manual', 'UTC')")
            ->execute([$wf, $this->userId]);
        $runId = (int)$this->db()->lastInsertId();
        WorkflowRun::saveArtifact($runId, 'visuel', 'vis', 'Visuel', 'image', ['url' => '/assets/img/generated/' . $keep]);
        $report = PruningService::balayerOrphelins([$tmp], true);
        $this->assertSame(1, $report['candidats'], 'Seul le fichier que rien ne cite est un orphelin.');
        @unlink($tmp . '/' . $keep);
        @unlink($tmp . '/' . $drop);
        @rmdir($tmp);
    }
}
