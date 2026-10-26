<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Pièces insérées dans l'email d'une étape de workflow, sur base réelle : la
 * charge du job porte ce que l'étape peut insérer, le dépôt remplace les
 * repères par le contenu exact du dossier, et l'image citée part INTÉGRÉE au
 * message (fichier refabriqué depuis le dossier de l'exécution, `cid`).
 */
final class WorkflowInsertsPipelineTest extends DatabaseTestCase
{
    private int $userId = 0;
    private array $files = [];
    private array $reglagesAvant = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Le moteur choisit le modèle de l'étape par le contrôleur du chat.
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

    private static function def(): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]],
            'nodes' => [
                ['key' => 'mail', 'type' => 'action', 'title' => 'Préparer ou envoyer un email', 'target' => 'gmail', 'write' => 'auto', 'auto_send_mail' => true,
                    'instruction' => 'Envoie à alice@example.com un mail qui contient @{depart} et l’image @{visuel}.'],
            ],
            'end' => ['title' => 'Fait', 'pieces' => []],
        ]);
    }

    private function image(): string
    {
        $img = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($img);
        $name = 'wfins_' . bin2hex(random_bytes(6)) . '.png';
        $path = ROOT . '/public/assets/img/generated/' . $name;
        file_put_contents($path, (string)ob_get_clean());
        $this->files[] = $path;
        return '/assets/img/generated/' . $name;
    }

    public function testLaChargeDuJobPorteCeQueLEtapePeutInserer(): void
    {
        $id = Workflow::create($this->userId, 'Nouvelles', self::def());
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $res = WorkflowEngine::launch(Workflow::findById($id), User::findById($this->userId), ['client' => 'Ville de Lyon'], [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        $stmt = $this->db()->prepare('SELECT payload_json FROM async_jobs WHERE id = (SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND node_key = ? ORDER BY id DESC LIMIT 1)');
        $stmt->execute([$res['run_id'], 'mail']);
        $payload = json_decode((string)$stmt->fetchColumn(), true);
        $this->assertSame(['depart' => 'depart'], $payload['workflow']['inserts']['map'], 'Le visuel n’existe pas au dossier : il n’est pas promis.');
        $this->assertStringContainsString('repère @{depart}', (string)$payload['workflow']['prompt']);
    }

    /** Simule la fin du job de l'étape en cours : la réponse du modèle, puis la clôture. */
    private function repond(int $runId, string $reponse): void
    {
        $stmt = $this->db()->prepare("SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND status = 'running' AND job_id IS NOT NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$runId]);
        $jobId = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $jobId, 'Une étape tourne.');
        $stmt = $this->db()->prepare('SELECT conversation_id FROM async_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $msg = Message::create((int)$stmt->fetchColumn(), 'assistant', $reponse);
        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        WorkflowEngine::onJobFinished($jobId, 'done', ['done' => true, 'msg_id' => (int)$msg['id']], null);
    }

    public function testUnePieceQuiCiteDesPiecesEntreAuDossierRemplieEtLEmailAussi(): void
    {
        // Le cas de la recette : la news, rédigée par une étape qui écrit,
        // citait les pièces des étapes d'avant (« - @{veille} »).
        $def = WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]],
            'nodes' => [
                ['key' => 'veille', 'type' => 'step', 'title' => 'Veille', 'write' => 'auto', 'instruction' => 'Cherche.', 'output' => ['key' => 'veille_ia', 'name' => 'Veille IA', 'form' => 'list']],
                ['key' => 'news', 'type' => 'step', 'title' => 'Rédiger la news', 'write' => 'auto', 'instruction' => 'Rédige la news avec @{veille_ia}.',
                    'output' => ['key' => 'news_slack', 'name' => 'Message Slack hebdomadaire', 'form' => 'text']],
                ['key' => 'mail', 'type' => 'action', 'title' => 'Email', 'target' => 'gmail', 'write' => 'auto', 'instruction' => 'Envoie un mail qui contient @{news_slack}.'],
            ],
            'end' => ['title' => 'Fait', 'pieces' => []],
        ]);
        $id = Workflow::create($this->userId, 'Nouvelles', $def);
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $res = WorkflowEngine::launch(Workflow::findById($id), User::findById($this->userId), ['client' => 'MyAlfred'], [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        $runId = (int)$res['run_id'];

        $this->repond($runId, "- Claude Opus 5.5 sort\n- GPT-6.1 Sol en promotion");
        $this->repond($runId, "Bonjour à tous !\n\n**IA de la semaine**\n- @{veille_ia}\n\nBonne semaine.");

        $news = WorkflowRun::artifacts($runId)['news_slack']['content']['text'] ?? '';
        $this->assertStringNotContainsString('@{', $news, 'Le dossier garde le vrai contenu : ' . $news);
        $this->assertStringContainsString("**IA de la semaine**\n- Claude Opus 5.5 sort\n- GPT-6.1 Sol en promotion", $news, 'Une seule puce par élément.');

        // L'email : la news, et ce qu'elle cite, en entier.
        $stmt = $this->db()->prepare("SELECT payload_json FROM async_jobs WHERE id = (SELECT job_id FROM workflow_run_steps WHERE run_id = ? AND node_key = 'mail' ORDER BY id DESC LIMIT 1)");
        $stmt->execute([$runId]);
        $payload = json_decode((string)$stmt->fetchColumn(), true);
        $args = WorkflowInserts::forTurn('creer_brouillon_gmail', ['destinataire' => 'alice@example.com', 'corps' => "Pour l'équipe :\n\n@{news_slack}"], $payload['workflow'], $this->userId);
        $this->assertStringNotContainsString('@{', $args['corps']);
        $this->assertStringContainsString('- GPT-6.1 Sol en promotion', $args['corps']);
    }

    public function testLEmailPartAvecLeContenuEtLImageIntegree(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]],
            'nodes' => [
                ['key' => 'redac', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'Rédige.', 'output' => ['key' => 'message_slack', 'name' => 'Message Slack hebdomadaire', 'form' => 'text']],
                ['key' => 'illus', 'type' => 'create', 'kind' => 'image', 'title' => 'Illustrer', 'instruction' => 'Une image.', 'output' => ['key' => 'visuel', 'name' => 'Image']],
                ['key' => 'mail', 'type' => 'action', 'title' => 'Email', 'target' => 'gmail', 'write' => 'auto',
                    'instruction' => 'Envoie un mail qui contient @{message_slack} et l’image @{visuel}.'],
            ],
            'end' => ['title' => 'Fait', 'pieces' => []],
        ]);
        $wid = Workflow::create($this->userId, 'Nouvelles', $def);
        $runId = (int)WorkflowRun::create($wid, 1, $this->userId, $def, 'manual', null, [], 'Europe/Paris');
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([$this->userId, 'Workflow — Nouvelles']);
        $convId = (int)$this->db()->lastInsertId();
        WorkflowRun::attachConversation($runId, $convId);
        WorkflowRun::saveArtifact($runId, 'message_slack', 'redac', 'Message Slack hebdomadaire', 'text', ['text' => "- **MIA 1.5.1** en recette\n- Bienvenue à Lina"]);
        WorkflowRun::saveArtifact($runId, 'visuel', 'illus', 'Image', 'image', ['url' => $this->image(), 'prompt' => 'Une équipe']);

        $node = WorkflowSpec::node($def, 'mail');
        $step = ['run_id' => $runId, 'inserts' => WorkflowInserts::forPass($def, $node, WorkflowRun::artifacts($runId))];
        // Ce qu'a écrit le modèle en recette : le seul nom de la pièce, aucune image.
        $args = WorkflowInserts::forTurn('creer_brouillon_gmail', [
            'destinataire' => 'alice@example.com', 'objet' => 'Nouvelles',
            'corps' => "Pour l'équipe :\n\n@{message_slack}",
        ], $step, $this->userId);
        $this->assertStringContainsString('**MIA 1.5.1** en recette', $args['corps']);
        $this->assertStringContainsString('[[mia-image:', $args['corps'], 'L’image citée par la consigne est ajoutée même si le modèle l’a oubliée.');

        // À l'envoi : le fichier refabriqué depuis le dossier de l'exécution.
        $files = WorkflowDeliveries::attachmentsFor($args, User::findById($this->userId), $convId);
        $this->assertCount(1, $files);
        $this->assertSame('image/png', $files[0]['mime']);
        $this->assertSame($args['_wf_attach'][0]['cid'], $files[0]['cid']);
        $mime = UserGmailService::buildDraftMime('alice@example.com', 'Nouvelles', $args['corps'], '', [], '', $files);
        $this->assertStringContainsString('Content-ID: <' . $files[0]['cid'] . '>', $mime);
        $this->assertStringContainsString('multipart/related', $mime);

        // Une autre personne : rien ne part.
        $autre = $this->insertUser(42, 'creator');
        $this->assertSame(['corps' => "Pour l'équipe :\n\n@{message_slack}"],
            WorkflowInserts::forTurn('creer_brouillon_gmail', ['corps' => "Pour l'équipe :\n\n@{message_slack}"], $step, $autre));
        $this->expectException(\RuntimeException::class);
        WorkflowDeliveries::attachmentsFor($args, User::findById($autre), $convId);
    }

    public function testLaNoteDUneValidationEtLeMotDeLaFinCitentLeDossier(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]],
            'nodes' => [
                ['key' => 'redac', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'Rédige pour @{depart}.', 'output' => ['key' => 'lettre', 'name' => 'Lettre', 'form' => 'text']],
                ['key' => 'accord', 'type' => 'gate', 'title' => 'Accord', 'reviews' => ['lettre'], 'note' => 'Relisez @{lettre} pour @{depart.client}.'],
            ],
            'end' => ['title' => 'Fait', 'pieces' => ['lettre'], 'note' => 'Bonne relance auprès de @{depart.client}.'],
        ]);
        $id = Workflow::create($this->userId, 'Relance', $def);
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $res = WorkflowEngine::launch(Workflow::findById($id), User::findById($this->userId), ['client' => 'Ville de Lyon'], [], 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        $runId = (int)$res['run_id'];
        $this->repond($runId, 'Madame, Monsieur…');

        $messages = array_column(Message::forConversation((int)$res['conversation_id']), 'content');
        $gateMsg = implode("\n", array_filter($messages, static fn($c) => str_contains((string)$c, 'Relisez « Lettre » pour')));
        $this->assertStringContainsString('Relisez « Lettre » pour Ville de Lyon.', $gateMsg, 'La note de la validation, remplie.');

        $run = WorkflowRun::find($runId);
        $gate = WorkflowRun::lastStep($runId, 'accord');
        DrivePendingAction::claim((int)$gate['pending_action_id'], $this->userId, (int)$res['conversation_id']);
        DrivePendingAction::resolve((int)$gate['pending_action_id'], 'executed');
        WorkflowEngine::gateResolved($run, $gate, 'approved');
        $this->assertSame(WorkflowRun::DONE, WorkflowRun::find($runId)['status']);
        $messages = implode("\n", array_column(Message::forConversation((int)$res['conversation_id']), 'content'));
        $this->assertStringContainsString('Bonne relance auprès de Ville de Lyon.', $messages, 'Le mot de la fin, rempli.');
        $this->assertStringNotContainsString('@{depart', $messages);
    }
}
