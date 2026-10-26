<?php

declare(strict_types=1);

/**
 * Tâches programmées, sur la vraie base : bases de connaissances et outils
 * choisis pour une tâche (migration 083), leur écriture sur la conversation
 * d'une exécution, leur revérification au moment T, et l'essai lancé depuis
 * le formulaire — une conversation, un job, jamais deux.
 *
 * Aucun job n'est déclenché : enfilés seulement, aucun appel de modèle.
 */
final class ScheduledTaskResourcesPipelineTest extends DatabaseTestCase
{
    private const NOW = '2026-10-05 06:00:00';

    private array $reglagesAvant = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Scheduler résout le modèle par ChatController, hors du bootstrap.
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Controllers/ScheduledTaskController.php';

        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');

        Settings::refresh();
        foreach ([ScheduledTask::SETTING_ENABLED, 'default_llm', 'connector_web_enabled'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(ScheduledTask::SETTING_ENABLED, '1');
        Settings::set('connector_web_enabled', '1');

        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save([
            'provider'     => 'openai',
            'model'        => LlmCatalog::defaultModel('openai'),
            'display_name' => 'Défaut',
            'is_active'    => 1,
            'sort_order'   => 0,
        ]);
        Settings::set('default_llm', 'openai::' . LlmCatalog::defaultModel('openai'));
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, $valeur);
            }
        }
        $_SESSION = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    private static function now(string $decalage = ''): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        return $decalage !== '' ? $now->modify($decalage) : $now;
    }

    private function user(int $id, string $role = 'user'): array
    {
        $this->insertUser($id, $role);
        return User::findById($id);
    }

    /** Base prête à être interrogée : un document vectorisé, avec des extraits. */
    private function base(string $nom, ?int $createdBy, bool $prete = true): int
    {
        $kb = KnowledgeBase::create([
            'name'               => $nom,
            'description'        => null,
            'embedding_provider' => 'openai',
            'embedding_model'    => 'text-embedding-3-large',
            'embedding_dims'     => 1536,
            'is_active'          => 1,
            'created_by'         => $createdBy,
        ]);
        if ($prete) {
            $doc = KnowledgeBaseDocument::create([
                'kb_id'       => $kb,
                'file_name'   => 'doc.txt',
                'stored_name' => 'test_' . bin2hex(random_bytes(4)) . '.txt',
                'mime_type'   => 'text/plain',
                'file_size'   => 10,
            ]);
            $this->db()->prepare("UPDATE knowledge_base_documents SET vector_status = 'done', chunk_count = 3 WHERE id = ?")
                ->execute([$doc]);
        }
        return $kb;
    }

    private function outil(string $nom, string $type, array $surcharge = []): int
    {
        $row = $surcharge + [
            'provider'     => $type === 'image' ? 'openai' : $type,
            'model'        => $type === 'image' ? 'gpt-image-2.5-flare' : ($type === 'n8n' ? 'webhook' : 'presentation'),
            'config_json'  => $type === 'n8n' ? json_encode(['base_url' => 'https://n8n.example.test', 'tag' => 'mia']) : '{}',
            'api_key_enc'  => Encryption::encrypt('cle-de-test'),
            'is_active'    => 1,
            'last_test_ok' => null,
        ];
        $this->db()->prepare(
            'INSERT INTO chat_tools (name, type, provider, model, config_json, api_key_enc, is_active, last_test_ok)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$nom, $type, $row['provider'], $row['model'], $row['config_json'], $row['api_key_enc'], $row['is_active'], $row['last_test_ok']]);
        return (int)$this->db()->lastInsertId();
    }

    private function autoriserOutil(int $groupId, int $toolId): void
    {
        $this->db()->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?)')->execute([$groupId, $toolId]);
    }

    private function donnees(array $surcharge = []): array
    {
        return $surcharge + [
            'name'          => 'Point du lundi',
            'instruction'   => 'Fais le point sur mes mails.',
            'profile_id'    => null,
            'llm'           => '',
            'rule'          => ['freq' => 'weekly', 'days' => [1], 'time' => '08:00'],
            'timezone'      => 'Europe/Paris',
            'sources'       => ['web'],
            'kb_ids'        => [],
            'tool_ids'      => [],
            'destination'   => ScheduledTask::DEST_THREAD,
            'allow_actions' => false,
        ];
    }

    private function selection(int $convId): array
    {
        $stmt = $this->db()->prepare('SELECT kb_id FROM conversation_knowledge_bases WHERE conversation_id = ? ORDER BY kb_id');
        $stmt->execute([$convId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function nombre(string $sql, array $params = []): int
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Persistance et périmètre
    // ------------------------------------------------------------------

    public function testUneTacheGardeSesBasesEtSesOutils(): void
    {
        $user = $this->user(1);
        $id = ScheduledTask::create(1, $this->donnees(['kb_ids' => [4, 9], 'tool_ids' => [2]]), self::now());

        $task = ScheduledTask::findForUser($id, 1);
        $this->assertSame([4, 9], $task['kb_list']);
        $this->assertSame([2], $task['tool_list']);

        ScheduledTask::update($id, 1, $this->donnees(['kb_ids' => [], 'tool_ids' => [5, 6]]), self::now());
        $task = ScheduledTask::findForUser($id, 1);
        $this->assertSame([], $task['kb_list']);
        $this->assertSame([5, 6], $task['tool_list']);
        $this->assertSame((int)$user['id'], (int)$task['user_id']);
    }

    public function testLesChoixSuiventLePerimetreDeLaPersonne(): void
    {
        $user  = $this->user(1);
        $this->user(2);
        $group = $this->insertGroup('Commerciaux');
        $this->addMember(1, $group);

        $mienne  = $this->base('Mes notes', 1);
        $vide    = $this->base('Brouillons', 1, false);
        $autrui  = $this->base('Privée d’autrui', 2);
        $partage = $this->base('Partagée au groupe', 2);
        $this->db()->prepare('INSERT INTO knowledge_base_shares (kb_id, group_id, can_manage) VALUES (?, ?, 0)')->execute([$partage, $group]);

        $profile = $this->insertProfile('Veille', true, 1);
        $this->db()->prepare('INSERT INTO profile_knowledge_bases (profile_id, kb_id) VALUES (?, ?)')->execute([$profile, $mienne]);

        $image  = $this->outil('Images', 'image');
        $n8n    = $this->outil('Automatisations', 'n8n');
        $hors   = $this->outil('Gamma réservé', 'gamma');
        $echec  = $this->outil('Images en panne', 'image', ['last_test_ok' => 0]);
        foreach ([$image, $n8n, $echec] as $t) {
            $this->autoriserOutil($group, $t);
        }
        // Outils liés à l'assistant : seul celui que les groupes ouvrent ET qui
        // sert est apporté à la tâche (règle du tour, resolveProfile*Tool).
        foreach ([$echec, $n8n, $hors] as $t) {
            $this->db()->prepare('INSERT INTO profile_tools (profile_id, tool_id) VALUES (?, ?)')->execute([$profile, $t]);
        }

        $choices = ScheduledTask::choicesFor($user);
        $this->assertEqualsCanonicalizing([$mienne, $vide, $partage], array_keys($choices['kbs']));
        $this->assertArrayNotHasKey($autrui, $choices['kbs'], 'Jamais la base privée d’autrui.');
        $this->assertSame('Aucun document', $choices['kbs'][$vide]['reason']);
        $this->assertNull($choices['kbs'][$partage]['reason']);
        $this->assertSame([$profile => [['id' => $mienne, 'name' => 'Mes notes']]], $choices['profile_kbs']);

        $this->assertEqualsCanonicalizing([$image, $n8n, $echec], array_keys($choices['tools']));
        $this->assertArrayNotHasKey($hors, $choices['tools'], 'Un outil que les groupes n’ouvrent pas n’existe pas ici.');
        $this->assertNotNull($choices['tools'][$echec]['reason']);
        $this->assertSame(
            [$profile => [['id' => $n8n, 'name' => 'Automatisations', 'type' => 'n8n', 'acting' => true]]],
            $choices['profile_tools']
        );

        $allowed = ScheduledTask::allowedFrom($choices);
        $this->assertEqualsCanonicalizing([$mienne, $partage], $allowed['kbs']);
        $this->assertSame([$image => 'image', $n8n => 'n8n'], $allowed['tools']);

        // Description de l'outil programmer_tache : rien au-delà de l'essentiel.
        $legers = ScheduledTask::choicesFor($user, false);
        $this->assertSame(['profiles', 'llms', 'sources'], array_keys($legers));
    }

    // ------------------------------------------------------------------
    // Une exécution
    // ------------------------------------------------------------------

    public function testUneExecutionEcritLesBasesDeLaTacheSurSaConversation(): void
    {
        $this->user(1);
        $kb = $this->base('Procédures', 1);
        $id = ScheduledTask::create(1, $this->donnees(['kb_ids' => [$kb], 'tool_ids' => [7]]), self::now('-7 days'));
        ScheduledTask::setNextRun($id, self::now('-1 minute'));

        $this->assertSame(1, Scheduler::tick(self::now(), false));
        $run = $this->db()->query('SELECT * FROM scheduled_task_runs WHERE task_id = ' . $id)->fetch();
        $convId = (int)$run['conversation_id'];
        $this->assertSame([$kb], $this->selection($convId));

        $payload = json_decode((string)$this->db()->query('SELECT payload_json FROM async_jobs WHERE id = ' . (int)$run['job_id'])->fetchColumn(), true);
        $this->assertSame([7], $payload['scheduled']['tool_ids']);

        // La tâche ne porte plus de base : à la suite, la sélection suit (vidée).
        ScheduledTask::update($id, 1, $this->donnees(['kb_ids' => []]), self::now('-7 days'));
        $this->db()->exec("UPDATE async_jobs SET status = 'done'");
        $this->db()->exec("UPDATE scheduled_task_runs SET status = 'done'");
        ScheduledTask::setNextRun($id, self::now('+1 day -1 minute'));
        $this->assertSame(1, Scheduler::tick(self::now('+1 day'), false));
        $this->assertSame([], $this->selection($convId));
    }

    public function testUneExecutionEnNouvelleConversationPorteSesBases(): void
    {
        $this->user(1);
        $kb = $this->base('Procédures', 1);
        $id = ScheduledTask::create(1, $this->donnees(['kb_ids' => [$kb], 'destination' => ScheduledTask::DEST_NEW]), self::now('-7 days'));
        ScheduledTask::setNextRun($id, self::now('-1 minute'));

        Scheduler::tick(self::now(), false);
        $convId = (int)$this->db()->query('SELECT conversation_id FROM scheduled_task_runs WHERE task_id = ' . $id)->fetchColumn();
        $this->assertSame([$kb], $this->selection($convId));
    }

    // ------------------------------------------------------------------
    // Outils revérifiés au moment T
    // ------------------------------------------------------------------

    public function testLesOutilsDeLaTacheSontRevérifiesAuMomentT(): void
    {
        $user  = $this->user(1);
        $group = $this->insertGroup('Équipe');
        $this->addMember(1, $group);

        $image1  = $this->outil('Images A', 'image');
        $image2  = $this->outil('Images B', 'image');
        $n8n     = $this->outil('Automatisations', 'n8n');
        $gamma   = $this->outil('Gamma sans clé', 'gamma', ['api_key_enc' => '']);
        $inactif = $this->outil('Image coupée', 'image', ['is_active' => 0]);
        foreach ([$image1, $image2, $gamma, $inactif] as $t) {
            $this->autoriserOutil($group, $t);
        }
        // n8n n'est ouvert à aucun groupe de la personne.

        $scope  = UserGroup::membershipScopeForUser(1);
        $method = new ReflectionMethod(ChatController::class, 'resolveTaskTools');
        $tools  = $method->invoke(
            (new ReflectionClass(ChatController::class))->newInstanceWithoutConstructor(),
            [$inactif, $image2, $image1, $n8n, $gamma],
            $user,
            $scope['active_group_ids'],
            $scope['has_assignments']
        );

        $this->assertSame(['image'], array_keys($tools), 'Inactif, hors groupe et incomplet sortent ; une image par tour.');
        $this->assertSame($image2, (int)$tools['image']['id'], 'Le premier choisi l’emporte.');
    }

    // ------------------------------------------------------------------
    // Essai depuis le formulaire
    // ------------------------------------------------------------------

    public function testUnEssaiCreeSaConversationEtUnSeulJob(): void
    {
        $user = $this->user(1);
        $kb = $this->base('Procédures', 1);
        $data = $this->donnees(['kb_ids' => [$kb], 'tool_ids' => [3], 'allow_actions' => true]);

        $first = Scheduler::startTrial($user, $data, null, self::now());
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['rejoined']);

        $conv = Conversation::findById($first['conversation_id']);
        $this->assertSame('Essai — Point du lundi', $conv['title']);
        $this->assertNull($conv['scheduled_task_id'], 'Un essai n’est pas la conversation de la tâche.');
        $this->assertSame([$kb], $this->selection((int)$conv['id']));
        $messages = Message::forConversation((int)$conv['id']);
        $this->assertCount(1, $messages);
        $this->assertSame('Fais le point sur mes mails.', $messages[0]['content']);

        $job = JobQueue::find($first['job_id']);
        $this->assertSame('chat', $job['type']);
        $this->assertSame(Scheduler::trialDedupKey(1), $job['dedup_key']);
        $payload = $job['payload'];
        $this->assertSame([3], $payload['scheduled']['tool_ids']);
        $this->assertTrue($payload['scheduled']['allow_actions']);
        $this->assertSame((int)$messages[0]['id'], $payload['user_msg_id']);
        // Un essai n'écrit aucune exécution dans l'historique de la tâche.
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM scheduled_task_runs'));

        // Un second clic pendant l'essai le rejoint.
        $second = Scheduler::startTrial($user, $data, null, self::now());
        $this->assertTrue($second['rejoined']);
        $this->assertSame($first['job_id'], $second['job_id']);
        $this->assertSame($first['conversation_id'], $second['conversation_id']);
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM conversations'));
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM async_jobs'));

        // Fini : le suivant repart.
        $this->db()->exec("UPDATE async_jobs SET status = 'done'");
        $third = Scheduler::startTrial($user, $data, null, self::now());
        $this->assertFalse($third['rejoined']);
        $this->assertNotSame($first['conversation_id'], $third['conversation_id']);
    }

    public function testUnEssaiImpossibleDitPourquoiSansRienCreer(): void
    {
        $user = $this->user(1);
        $this->db()->exec('UPDATE users SET is_active = 0 WHERE id = 1');
        $user['is_active'] = 0;

        $result = Scheduler::startTrial($user, $this->donnees(), null, self::now());
        $this->assertFalse($result['ok']);
        $this->assertStringStartsWith('Essai impossible : ', $result['error']);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM conversations'));
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM async_jobs'));
    }

    public function testLeResultatDUnEssaiEstLaDerniereReponse(): void
    {
        $this->user(1);
        $conv = Conversation::createTitled(1, null, 'openai::gpt-5.6', 'Essai — Point');
        Message::create($conv, 'user', 'Le point.');
        $this->assertNull(Message::lastAnswer($conv), 'Pas encore de réponse : l’essai n’est pas prêt.');

        $m = Message::create($conv, 'assistant', '**Fait.**');
        DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'A'], ['action' => 'Créer une tâche']);
        $payload = ScheduledTaskController::trialResultPayload(
            Message::lastAnswer($conv),
            DrivePendingAction::countPendingByConversation([$conv], 1)[$conv] ?? 0,
            $conv
        );
        $this->assertTrue($payload['ready']);
        $this->assertSame(1, $payload['pending']);
        $this->assertStringContainsString('<strong>Fait.</strong>', $payload['html']);
        $this->assertSame((int)$m['id'], (int)Message::lastAnswer($conv)['id']);
    }

    public function testUnEssaiNEstRemplaceQueSIlNAServiARien(): void
    {
        $this->user(1);
        $this->user(2);
        $drop = new ReflectionMethod(ScheduledTaskController::class, 'dropReplacedTrial');
        $ctrl = (new ReflectionClass(ScheduledTaskController::class))->newInstanceWithoutConstructor();
        $essai = function (int $userId = 1): int {
            $conv = Conversation::createTitled($userId, null, 'openai::gpt-5.6', 'Essai — Point');
            Message::create($conv, 'user', 'Le point.');
            Message::create($conv, 'assistant', 'Fait.');
            return $conv;
        };
        $existe = fn(int $id): bool => Conversation::findById($id) !== null;

        // Pas créé par un essai de cette session : gardé.
        $hors = $essai();
        $drop->invoke($ctrl, $hors, 1);
        $this->assertTrue($existe($hors));

        $libre       = $essai();
        $poursuivi   = $essai();
        Message::create($poursuivi, 'user', 'Et pour demain ?');
        $enCours     = $essai();
        JobQueue::enqueue('chat', 1, $enCours, ['content' => 'x']);
        $enAttente   = $essai();
        DrivePendingAction::create(1, $enAttente, 'creer_tache', ['titre' => 'A'], ['action' => 'Créer une tâche']);
        $dAutrui     = $essai(2);
        $_SESSION['schedule_trials'] = [$libre, $poursuivi, $enCours, $enAttente, $dAutrui];

        foreach ([$poursuivi, $enCours, $enAttente, $dAutrui] as $garde) {
            $drop->invoke($ctrl, $garde, 1);
            $this->assertTrue($existe($garde), "Essai $garde supprimé à tort.");
        }
        $drop->invoke($ctrl, $libre, 1);
        $this->assertFalse($existe($libre), 'Un essai resté tel quel est remplacé par le suivant.');
    }
}
