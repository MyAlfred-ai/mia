<?php

declare(strict_types=1);

/**
 * Planificateur des tâches programmées, sur la vraie base : un créneau échu
 * devient UN message et UN job de chat, jamais deux ; un créneau trop ancien
 * est manqué ; quota, compte et assistant sont revérifiés au moment T ; l'issue
 * du job revient sur l'exécution, le compteur d'échecs et la pastille.
 *
 * Les jobs sont enfilés SANS être déclenchés (`tick($now, false)`) : aucun
 * appel à un modèle ne part d'un test.
 */
final class SchedulerPipelineTest extends DatabaseTestCase
{
    private const NOW = '2026-10-05 06:00:00';

    private array $reglagesAvant = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Scheduler résout le modèle par ChatController, hors du bootstrap.
        require_once ROOT . '/src/Controllers/ChatController.php';

        $db = $this->db();
        // api_keys et llm_models ne sont pas vidées par le socle.
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
    }

    protected function tearDown(): void
    {
        // Sans base, le socle a sauté le test mais PHPUnit appelle tearDown().
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, $valeur);
            }
        }
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

    /** Tâche « chaque lundi à 8 h, Paris », échue depuis `$retard`. */
    private function tache(int $userId, array $surcharge = [], string $retard = '-1 minute'): int
    {
        $data = $surcharge + [
            'name'        => 'Point du lundi',
            'instruction' => 'Fais le point sur mes mails.',
            'profile_id'  => null,
            'llm'         => '',
            'rule'        => ['freq' => 'weekly', 'days' => [1], 'time' => '08:00'],
            'timezone'    => 'Europe/Paris',
            'sources'     => ['web', 'gmail'],
            'destination' => ScheduledTask::DEST_THREAD,
        ];
        $id = ScheduledTask::create($userId, $data, self::now('-7 days'));
        $this->echeance($id, self::now($retard));
        return $id;
    }

    private function echeance(int $taskId, \DateTimeImmutable $quand): void
    {
        ScheduledTask::setNextRun($taskId, $quand);
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

    // ------------------------------------------------------------------
    // Un créneau
    // ------------------------------------------------------------------

    public function testUnCreneauEchuDevientUnMessageEtUnJobDeChat(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user);

        $this->assertSame(1, Scheduler::tick(self::now(), false));

        $run = $this->ligne('SELECT * FROM scheduled_task_runs WHERE task_id = ?', [$task]);
        $this->assertSame('queued', $run['status']);
        $this->assertNotNull($run['job_id']);

        $job = $this->ligne('SELECT * FROM async_jobs WHERE id = ?', [(int)$run['job_id']]);
        $this->assertSame('chat', $job['type']);
        $this->assertSame('pending', $job['status']);
        $payload = json_decode((string)$job['payload_json'], true);
        $this->assertSame('Fais le point sur mes mails.', $payload['content']);
        $this->assertTrue($payload['gmail_access']);
        $this->assertTrue($payload['web_access']);
        $this->assertFalse($payload['drive_access']);
        $this->assertFalse($payload['connectors_auto']);
        $this->assertSame('Europe/Paris', $payload['tz']);
        $this->assertSame($task, $payload['scheduled']['task_id']);

        $conv = Conversation::findById((int)$run['conversation_id']);
        $this->assertSame($task, (int)$conv['scheduled_task_id']);
        $this->assertSame('Point du lundi', $conv['title']);
        $this->assertSame((int)$conv['id'], (int)$job['conversation_id']);
        $messages = Message::forConversation((int)$conv['id']);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame((int)$messages[0]['id'], $payload['user_msg_id']);

        // L'échéance a avancé à la semaine suivante, 8 h à Paris.
        $task = ScheduledTask::findForUser($task, $user);
        $this->assertSame('2026-10-12 06:00:00', $task['next_run_at']);
    }

    public function testLOptionActionsVoyageDansLaChargeDuJob(): void
    {
        $user = $this->insertUser(1);
        $lecture = $this->tache($user);
        $actions = $this->tache($user, ['name' => 'Relances', 'allow_actions' => true]);

        $this->assertFalse(ScheduledTask::findForUser($lecture, $user)['allow_actions'], 'Lecture seule par défaut.');
        $this->assertTrue(ScheduledTask::findForUser($actions, $user)['allow_actions']);

        $this->assertSame(2, Scheduler::tick(self::now(), false));
        $charges = [];
        foreach ($this->db()->query('SELECT payload_json FROM async_jobs')->fetchAll() as $job) {
            $p = json_decode((string)$job['payload_json'], true);
            $charges[$p['scheduled']['task_id']] = $p['scheduled']['allow_actions'];
        }
        $this->assertSame([$lecture => false, $actions => true], $charges);
    }

    public function testUnCreneauNePartJamaisDeuxFois(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user);

        $this->assertSame(1, Scheduler::tick(self::now(), false));
        $this->assertSame(0, Scheduler::tick(self::now(), false), 'Le même passage ne relance rien.');

        // Même si l'échéance revenait sur le créneau déjà traité (deux
        // processus concurrents), l'unicité en base l'arrête.
        $this->echeance($task, self::now('-1 minute'));
        Scheduler::tick(self::now(), false);

        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM async_jobs'));
        $this->assertSame(1, $this->nombre("SELECT COUNT(*) FROM messages WHERE role = 'user'"));
    }

    public function testUnCreneauTropAncienEstManqueSansRafale(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user, [], '-3 days');

        $this->assertSame(0, Scheduler::tick(self::now(), false));
        $run = $this->ligne('SELECT * FROM scheduled_task_runs WHERE task_id = ?', [$task]);
        $this->assertSame('missed', $run['status']);
        $this->assertSame('late', $run['reason']);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM async_jobs'));
        // Reprise au prochain créneau à venir, pas aux trois jours perdus.
        $this->assertGreaterThan(self::now()->format('Y-m-d H:i:s'), ScheduledTask::findForUser($task, $user)['next_run_at']);
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM scheduled_task_runs'));
    }

    public function testUnQuotaAtteintSauteLeCreneauSansMettreEnPause(): void
    {
        $user = $this->insertUser(1);
        $this->db()->exec('UPDATE users SET credit_quota_k = 0 WHERE id = 1');
        $task = $this->tache($user);

        $this->assertSame(0, Scheduler::tick(self::now(), false));
        $run = $this->ligne('SELECT * FROM scheduled_task_runs WHERE task_id = ?', [$task]);
        $this->assertSame('skipped', $run['status']);
        $this->assertSame('quota', $run['reason']);
        $task = ScheduledTask::findForUser($task, $user);
        $this->assertSame('active', $task['status']);
        $this->assertSame(0, $task['failures']);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM messages'));
    }

    public function testUnAssistantDevenuInaccessibleMetLaTacheEnPause(): void
    {
        $user   = $this->insertUser(1);
        $autre  = $this->insertUser(2, 'creator');
        $prive  = $this->insertProfile('Assistant d’un autre', true, $autre);
        $task   = $this->tache($user, ['profile_id' => $prive]);

        $this->assertSame(0, Scheduler::tick(self::now(), false));
        $task = ScheduledTask::findForUser($task, $user);
        $this->assertSame('paused', $task['status']);
        $this->assertSame(ScheduledTask::PAUSE_ASSISTANT, $task['pause_reason']);
        $this->assertSame('skipped', $this->ligne('SELECT status FROM scheduled_task_runs')['status']);
    }

    public function testUnAssistantAccessibleEstCeluiDeLaConversation(): void
    {
        $user    = $this->insertUser(1);
        $profile = $this->insertProfile('Veille', true, $user);
        $task    = $this->tache($user, ['profile_id' => $profile]);

        $this->assertSame(1, Scheduler::tick(self::now(), false));
        $conv = Conversation::findById((int)$this->ligne('SELECT conversation_id FROM scheduled_task_runs')['conversation_id']);
        $this->assertSame($profile, (int)$conv['profile_id']);
        $this->assertSame($task, (int)$conv['scheduled_task_id']);
    }

    public function testUnCompteDesactiveMetLaTacheEnPause(): void
    {
        $user = $this->insertUser(1);
        $this->db()->exec('UPDATE users SET is_active = 0 WHERE id = 1');
        $task = $this->tache($user);

        Scheduler::tick(self::now(), false);
        $task = ScheduledTask::findForUser($task, $user);
        $this->assertSame('paused', $task['status']);
        $this->assertSame(ScheduledTask::PAUSE_ACCOUNT, $task['pause_reason']);
    }

    public function testUneRegleIllisibleMetLaTacheEnPause(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user);
        $this->db()->prepare('UPDATE scheduled_tasks SET rule_json = ? WHERE id = ?')->execute(['{"freq":"jamais"}', $task]);

        Scheduler::tick(self::now(), false);
        $task = ScheduledTask::findForUser($task, $user);
        $this->assertSame('paused', $task['status']);
        $this->assertSame(ScheduledTask::PAUSE_RULE, $task['pause_reason']);
        $this->assertNull($task['next_run_at']);
    }

    public function testUneFonctionnaliteCoupeeNeLanceRien(): void
    {
        $user = $this->insertUser(1);
        $this->tache($user);
        Settings::set(ScheduledTask::SETTING_ENABLED, '0');

        $this->assertSame(0, Scheduler::tick(self::now(), false));
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM scheduled_task_runs'));
    }

    public function testLaRechercheInternetResteSoumiseALAdministrateur(): void
    {
        $user = $this->insertUser(1);
        $this->tache($user);
        Settings::set('connector_web_enabled', '0');

        Scheduler::tick(self::now(), false);
        $payload = json_decode((string)$this->ligne('SELECT payload_json FROM async_jobs')['payload_json'], true);
        $this->assertFalse($payload['web_access']);
        $this->assertTrue($payload['gmail_access']);
    }

    // ------------------------------------------------------------------
    // Destination
    // ------------------------------------------------------------------

    public function testLaMemeConversationSertDUneExecutionALAutre(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user);

        Scheduler::tick(self::now(), false);
        $premier = $this->ligne('SELECT * FROM scheduled_task_runs ORDER BY id DESC LIMIT 1');
        ScheduledTaskRun::onJobFinished((int)$premier['job_id'], 'done', ['msg_id' => 0], null);

        $this->echeance($task, self::now('+7 days'));
        Scheduler::tick(self::now('+7 days +1 minute'), false);
        $second = $this->ligne('SELECT * FROM scheduled_task_runs ORDER BY id DESC LIMIT 1');

        $this->assertSame($premier['conversation_id'], $second['conversation_id']);
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM conversations'));
        $this->assertSame(2, $this->nombre("SELECT COUNT(*) FROM messages WHERE role = 'user'"));
    }

    public function testUneNouvelleConversationAChaqueFoisPorteLaDate(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user, ['destination' => ScheduledTask::DEST_NEW]);

        Scheduler::tick(self::now(), false);
        $premier = $this->ligne('SELECT * FROM scheduled_task_runs ORDER BY id DESC LIMIT 1');
        ScheduledTaskRun::onJobFinished((int)$premier['job_id'], 'done', [], null);
        $this->echeance($task, self::now('+7 days'));
        Scheduler::tick(self::now('+7 days +1 minute'), false);

        $this->assertSame(2, $this->nombre('SELECT COUNT(*) FROM conversations WHERE scheduled_task_id = ?', [$task]));
        $titre = (string)$this->ligne('SELECT title FROM conversations ORDER BY id ASC LIMIT 1')['title'];
        $this->assertStringStartsWith('Point du lundi — ', $titre);
        $this->assertStringContainsString('oct.', $titre);
    }

    // ------------------------------------------------------------------
    // Issue
    // ------------------------------------------------------------------

    public function testUneReussiteEstConsigneeEtSignaleeNonLue(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user);
        Scheduler::tick(self::now(), false);
        $run = $this->ligne('SELECT * FROM scheduled_task_runs');
        $reponse = Message::create((int)$run['conversation_id'], 'assistant', 'Voici le point.');

        ScheduledTaskRun::onJobFinished((int)$run['job_id'], 'done', ['msg_id' => (int)$reponse['id']], null);

        $run = $this->ligne('SELECT * FROM scheduled_task_runs');
        $this->assertSame('done', $run['status']);
        $this->assertSame((int)$reponse['id'], (int)$run['message_id']);
        $this->assertNotNull($run['finished_at']);
        $this->assertNotNull(Conversation::findById((int)$run['conversation_id'])['unread_at']);

        // Ouvrir la conversation éteint la pastille.
        Conversation::markRead((int)$run['conversation_id'], $user);
        $this->assertNull(Conversation::findById((int)$run['conversation_id'])['unread_at']);
        $this->assertSame(0, ScheduledTask::findForUser($task, $user)['failures']);
    }

    public function testTroisEchecsDAffileeMettentLaTacheEnPause(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user);

        for ($i = 0; $i < 3; $i++) {
            $slot = self::now('+' . (7 * $i) . ' days');
            $this->echeance($task, $slot);
            Scheduler::tick($slot->modify('+1 minute'), false);
            $run = $this->ligne('SELECT * FROM scheduled_task_runs ORDER BY id DESC LIMIT 1');
            $this->assertSame('queued', $run['status'], "Créneau $i non lancé.");
            ScheduledTaskRun::onJobFinished((int)$run['job_id'], 'failed', null, ['kind' => 'auth']);
        }

        $task = ScheduledTask::findForUser($task, $user);
        $this->assertSame('paused', $task['status']);
        $this->assertSame(ScheduledTask::PAUSE_FAILURES, $task['pause_reason']);

        // Reprendre remet le compteur à zéro et recalcule l'échéance.
        ScheduledTask::resume($task['id'], $user, self::now('+30 days'));
        $task = ScheduledTask::findForUser($task['id'], $user);
        $this->assertSame('active', $task['status']);
        $this->assertSame(0, $task['failures']);
        $this->assertGreaterThan(self::now('+30 days')->format('Y-m-d H:i:s'), $task['next_run_at']);
    }

    public function testUnJobOrdinaireNeTouchePasAuxTaches(): void
    {
        $user = $this->insertUser(1);
        $conv = Conversation::create($user, null, null);
        $jobId = JobQueue::enqueue('chat', $user, (int)$conv['id'], ['content' => 'x']);

        ScheduledTaskRun::onJobFinished($jobId, 'done', ['msg_id' => 1], null);

        $this->assertNull(Conversation::findById((int)$conv['id'])['unread_at']);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM scheduled_task_runs'));
    }

    public function testUneExecutionPerdueNeBloquePasLaTache(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user);
        Scheduler::tick(self::now(), false);
        $run = $this->ligne('SELECT * FROM scheduled_task_runs');
        // Le job s'est terminé sans que l'issue soit recopiée (processus tué).
        $this->db()->prepare("UPDATE async_jobs SET status = 'failed' WHERE id = ?")->execute([(int)$run['job_id']]);

        ScheduledTaskRun::maintain(self::now('+2 hours'));

        $run = $this->ligne('SELECT * FROM scheduled_task_runs');
        $this->assertSame('failed', $run['status']);
        $this->assertSame('lost', $run['reason']);
        $this->assertFalse(ScheduledTaskRun::hasRunning($task, self::now('+2 hours'), Scheduler::RUNNING_WINDOW_SEC));
    }

    // ------------------------------------------------------------------
    // « Exécuter maintenant »
    // ------------------------------------------------------------------

    public function testExecuterMaintenantNeDeplacePasLEcheance(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user, [], '+2 days');
        $avant = ScheduledTask::findForUser($task, $user)['next_run_at'];

        $result = Scheduler::runNow(ScheduledTask::findForUser($task, $user), self::now());
        $this->assertTrue($result['ok']);
        $this->assertSame($avant, ScheduledTask::findForUser($task, $user)['next_run_at']);
        $this->assertSame(1, (int)$this->ligne('SELECT is_manual FROM scheduled_task_runs')['is_manual']);

        // Une seconde demande pendant que la première tourne est refusée.
        $encore = Scheduler::runNow(ScheduledTask::findForUser($task, $user), self::now('+10 seconds'));
        $this->assertFalse($encore['ok']);
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM async_jobs'));
    }

    public function testLesTachesRestentCelleDeLeurProprietaire(): void
    {
        $alice = $this->insertUser(1);
        $bob   = $this->insertUser(2);
        $task  = $this->tache($alice);

        $this->assertNull(ScheduledTask::findForUser($task, $bob));
        $this->assertFalse(ScheduledTask::delete($task, $bob));
        $this->assertFalse(ScheduledTask::pauseForUser($task, $bob));
        $this->assertSame([], ScheduledTask::forUser($bob));
        $this->assertCount(1, ScheduledTask::forUser($alice));
    }

    public function testSupprimerUneTacheGardeSesConversations(): void
    {
        $user = $this->insertUser(1);
        $task = $this->tache($user);
        Scheduler::tick(self::now(), false);

        $this->assertTrue(ScheduledTask::delete($task, $user));
        $this->assertSame(1, $this->nombre('SELECT COUNT(*) FROM conversations'));
        $this->assertNull($this->ligne('SELECT scheduled_task_id FROM conversations')['scheduled_task_id']);
        $this->assertSame(0, $this->nombre('SELECT COUNT(*) FROM scheduled_task_runs'));
    }
}
