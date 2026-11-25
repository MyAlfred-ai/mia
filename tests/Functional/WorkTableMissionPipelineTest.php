<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';
require_once __DIR__ . '/../Support/FakeLlmStream.php';

/**
 * Opérations de fond sur une table de travail, sur la VRAIE base : le point
 * de contrôle (patch), les actions tenues en réserve, l'accord qui lance le
 * job, le job lui-même (enrichissement, action par ligne) et un vrai tour de
 * chat qui enrichit ou propose.
 *
 * Ce que ces tests protègent : une action par ligne n'apparaît nulle part
 * avant l'accord du groupe et ne s'exécute qu'une fois ; l'accord ne lance
 * qu'UNE opération à la fois par conversation ; le job écrit ce qu'il fait au
 * fur et à mesure, s'arrête proprement, et laisse un bilan dans le fil — sans
 * un message par ligne.
 */
final class WorkTableMissionPipelineTest extends DatabaseTestCase
{
    private const USER = 51;

    /** @var list<array> évènements émis par un tour de chat */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Jobs/JobContext.php';
        require_once ROOT . '/src/Jobs/JobErrorException.php';
        require_once ROOT . '/src/Jobs/ChatJob.php';
        require_once ROOT . '/src/Jobs/TableMissionJob.php';

        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        LlmCatalog::setToday('2026-10-14');
        $this->insertUser(self::USER);
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        // Un worker « vivant » : l'accord enfile le job sans lancer de processus.
        JobQueue::markWorkerAlive();
        $this->events = [];
    }

    protected function tearDown(): void
    {
        WorkTableEnricher::overrideCompletion(null);
        LlmAdapterHelpers::setStopProbe(null);
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
        }
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            $this->db()->exec("DELETE FROM settings WHERE `key` = 'job_worker_heartbeat_at'");
            Settings::refresh();
        }
        parent::tearDown();
    }

    private function conversation(int $user = self::USER): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([$user, 'Relances', 'openai::o3']);
        return (int)$this->db()->lastInsertId();
    }

    private function clients(int $conv, int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['nom' => 'Client ' . $i, 'email' => 'c' . $i . '@ex.fr', 'note' => $i % 2 ? 'retard de paiement' : 'à jour'];
        }
        return (new SqlWorkTableStore())->save($conv, self::USER, 'clients', 'Clients', WorkTableOps::columns(['Nom', 'Email', 'Note']), $rows);
    }

    private static function judge(): \Closure
    {
        return static function (string $system, string $user, string $llm): string {
            preg_match('/<<<\n(.*)\n>>>/s', $user, $m);
            $out = [];
            foreach (explode("\n", (string)($m[1] ?? '')) as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $out[] = ['n' => $row['n'], 'a_relancer' => str_contains((string)($row['note'] ?? ''), 'retard') ? 'oui' : 'non'];
                }
            }
            return json_encode($out);
        };
    }

    private static function spec(array $table): array
    {
        $spec = WorkTableEnricher::spec((array)$table['columns'], [
            'consigne' => 'Faut-il relancer ce client ?', 'colonnes' => [['nom' => 'À relancer', 'type' => 'oui_non']], 'lire' => ['Note'],
        ]);
        unset($spec['columns']);
        return $spec;
    }

    private function decide(int $conv, int $actionId, string $decision = 'approve'): array
    {
        $m = new ReflectionMethod(ChatController::class, 'decideKept');
        return $m->invoke(new ChatController(), $conv, User::findById(self::USER), $actionId, $decision, false);
    }

    private function action(int $id): array
    {
        return $this->db()->query('SELECT * FROM drive_pending_actions WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function job(int $id): array
    {
        return $this->db()->query('SELECT * FROM async_jobs WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> */
    private function replies(int $conv): array
    {
        $stmt = $this->db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id");
        $stmt->execute([$conv]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function runJob(int $jobId): void
    {
        $this->assertNotNull(JobQueue::claimById($jobId), 'Le job attend en file.');
        JobQueue::execute(JobQueue::find($jobId));
    }

    public function testLePointDeControleNeToucheQueLesLignesDesignees(): void
    {
        $conv  = $this->conversation();
        $table = $this->clients($conv, 5);
        $store = new SqlWorkTableStore();
        $cols  = array_merge((array)$table['columns'], [['key' => 'statut', 'label' => 'Statut', 'type' => 'text', 'tech' => false]]);
        $rows  = $store->rows((int)$table['id']);

        $after = $store->patch($conv, (int)$table['id'], $cols, [3 => $rows[3] + ['statut' => 'fait']]);
        $this->assertSame(['nom', 'email', 'note', 'statut'], WorkTableOps::keys((array)$after['columns']));
        $this->assertSame(5, $after['rows']);
        $now = $store->rows((int)$table['id']);
        $this->assertSame('fait', $now[3]['statut']);
        $this->assertSame('Client 4', $now[3]['nom']);
        $this->assertArrayNotHasKey('statut', $now[0], 'Les autres lignes ne sont pas réécrites.');

        // Écriture conditionnelle : la table doit être telle que l'appelant l'a laissée.
        $stamp = WorkTableOps::stateStamp($after);
        $ok = $store->patch($conv, (int)$table['id'], $cols, [0 => $now[0] + ['statut' => 'fait']], $stamp);
        $this->assertArrayNotHasKey('stale', $ok);
        // Un autre message la transforme sur place (tri) : l'ancienne empreinte ne vaut plus, rien n'est écrit.
        $store->save($conv, self::USER, 'clients', 'Clients', $cols, array_reverse($store->rows((int)$table['id'])), ['kind' => 'transformation', 'depuis' => 'clients']);
        $stale = $store->patch($conv, (int)$table['id'], $cols, [0 => ['nom' => 'écrasé']], WorkTableOps::stateStamp($ok));
        $this->assertTrue($stale['stale']);
        $this->assertSame('Client 5', $store->rows((int)$table['id'])[0]['nom']);

        // La table d'une autre conversation n'existe pas pour celle-ci.
        $this->assertNull($store->patch($this->conversation(), (int)$table['id'], $cols, [0 => ['nom' => 'pirate']]));
        $this->assertSame('Client 5', $store->rows((int)$table['id'])[0]['nom']);
    }

    public function testUneActionTenueEnReserveNApparaitNullePartEtNePartQuUneFois(): void
    {
        $conv = $this->conversation();
        $id   = DrivePendingAction::create(self::USER, $conv, 'creer_tache', ['titre' => 'Relancer'], ['action' => 'Créer une tâche']);
        $card = DrivePendingAction::create(self::USER, $conv, 'creer_tache', ['titre' => 'Autre'], ['action' => 'Créer une tâche']);
        DrivePendingAction::hold($id, self::USER);

        $this->assertSame('held', $this->action($id)['status']);
        $this->assertSame([$card], array_column(DrivePendingAction::pendingForConversation($conv, self::USER), 'id'), 'Aucune carte au rechargement.');
        $this->assertNull(DrivePendingAction::findPending($id, self::USER, $conv));
        $this->assertNull(DrivePendingAction::claim($id, self::USER, $conv), 'Un clic forgé ne l’exécute pas.');
        $this->assertSame(1, (int)$this->action($id)['auto_executed'], 'Hors des statistiques des cartes.');

        // Rendue à l'attente par l'opération de fond — pas par un autre compte, ni deux fois.
        $this->assertFalse(DrivePendingAction::releaseHeld($id, self::USER + 1, $conv));
        $this->assertTrue(DrivePendingAction::releaseHeld($id, self::USER, $conv));
        $this->assertFalse(DrivePendingAction::releaseHeld($id, self::USER, $conv));
        $this->assertNotNull(DrivePendingAction::claim($id, self::USER, $conv));

        // Écarter : seulement ce qui est en réserve.
        $a = DrivePendingAction::create(self::USER, $conv, 'creer_tache', [], []);
        $b = DrivePendingAction::create(self::USER, $conv, 'creer_tache', [], []);
        DrivePendingAction::hold($a, self::USER);
        $this->assertSame(1, DrivePendingAction::cancelHeld([$a, $b, $card], self::USER, 'Refusé.'));
        $this->assertSame('cancelled', $this->action($a)['status']);
        $this->assertSame('pending', $this->action($b)['status']);
        $this->assertSame('pending', $this->action($card)['status']);
    }

    public function testLAccordLanceUneSeuleOperationALaFois(): void
    {
        $conv  = $this->conversation();
        $table = $this->clients($conv, 60);
        $args  = ['table_id' => (int)$table['id'], 'table' => 'clients', 'spec' => self::spec($table), 'llm' => 'openai::o3', 'rows' => 60, 'cap' => 40];
        $first  = DrivePendingAction::create(self::USER, $conv, WorkTableTools::ENRICH, $args, ['action' => 'Enrichir 60 lignes', 'fichier' => 'Table « Clients »']);
        $second = DrivePendingAction::create(self::USER, $conv, WorkTableTools::ENRICH, $args, ['action' => 'Enrichir 60 lignes']);

        $data = $this->decide($conv, $first);
        $this->assertTrue($data['ok']);
        $this->assertSame('table_mission', $data['job_type']);
        $job = $this->job((int)$data['job_id']);
        $this->assertSame(['table_mission', 'pending', 'table_mission:' . $conv], [$job['type'], $job['status'], $job['dedup_key']]);
        $this->assertNull($job['lane'], 'La conversation reste utilisable pendant l’opération.');
        $payload = json_decode((string)$job['payload_json'], true);
        $this->assertSame(['enrich', (int)$table['id'], 'openai::o3', 40], [$payload['op'], $payload['table_id'], $payload['llm'], $payload['cap']]);
        $this->assertSame('executed', $this->action($first)['status']);
        $this->assertCount(1, $this->replies($conv));
        $this->assertStringStartsWith('⏳ Enrichissement de la table « Clients » en cours (60 lignes)', $this->replies($conv)[0]);
        $this->assertGreaterThan(0, $payload['status_msg_id']);

        // Une seconde opération pendant la première : la carte attend, rien n'est enfilé.
        $again = $this->decide($conv, $second);
        $this->assertFalse($again['ok']);
        $this->assertTrue($again['retryable']);
        $this->assertSame('pending', $this->action($second)['status']);
        $this->assertSame(1, (int)$this->db()->query("SELECT COUNT(*) FROM async_jobs WHERE type = 'table_mission'")->fetchColumn());

        // Refus d'une action groupée : ses actions tenues en réserve tombent avec elle.
        $held = DrivePendingAction::create(self::USER, $conv, 'creer_tache', ['titre' => 'x'], []);
        DrivePendingAction::hold($held, self::USER);
        $group = DrivePendingAction::create(self::USER, $conv, WorkTableTools::ACT, ['table_id' => (int)$table['id'], 'tool' => 'creer_tache', 'items' => [[0, $held]]], ['action' => 'Créer 1 tâche Google']);
        $this->assertSame('cancelled', $this->decide($conv, $group, 'cancel')['statut']);
        $this->assertSame('cancelled', $this->action($held)['status']);

        // Table disparue entre la carte et l'accord : rien n'est lancé.
        $other = $this->conversation();
        $lost  = DrivePendingAction::create(self::USER, $other, WorkTableTools::ENRICH, ['table_id' => 999999] + $args, ['action' => 'Enrichir']);
        $this->assertFalse($this->decide($other, $lost)['ok']);
        $this->assertSame('failed', $this->action($lost)['status']);
    }

    public function testLeJobEnrichitLaTableEtLaisseSonBilan(): void
    {
        $conv  = $this->conversation();
        $table = $this->clients($conv, 60);
        $card  = DrivePendingAction::create(self::USER, $conv, WorkTableTools::ENRICH,
            ['table_id' => (int)$table['id'], 'table' => 'clients', 'spec' => self::spec($table), 'llm' => 'openai::o3', 'rows' => 60, 'cap' => null],
            ['action' => 'Enrichir 60 lignes']);
        $jobId = (int)$this->decide($conv, $card)['job_id'];

        WorkTableEnricher::overrideCompletion(self::judge());
        $this->runJob($jobId);

        $this->assertSame('done', $this->job($jobId)['status']);
        $rows = (new SqlWorkTableStore())->rows((int)$table['id']);
        $this->assertCount(60, $rows);
        $this->assertSame(30, count(array_filter($rows, static fn(array $r): bool => ($r['a_relancer'] ?? '') === 'oui')));
        $replies = $this->replies($conv);
        $this->assertCount(2, $replies, 'Le message de lancement, puis le bilan — rien d’autre.');
        // Le bilan nomme la table par son titre, comme l'écran.
        $this->assertStringStartsWith('✅ Table « Clients » enrichie : 60 lignes traitées', $replies[1]);
        // Le flux porte l'avancement et le bilan.
        $events = array_map(static fn(string $p): array => (array)json_decode($p, true), JobQueue::eventPayloads($jobId));
        $missions = array_values(array_filter($events, static fn(array $e): bool => isset($e['mission'])));
        $this->assertNotSame([], $missions);
        $this->assertSame([60, 60], [end($missions)['mission']['done'], end($missions)['mission']['total']]);
        $done = end($events);
        $this->assertTrue($done['done']);
        $this->assertSame($replies[1], $done['content']);

        // Arrêt demandé en cours : ce qui est fait reste, le bilan dit ce qui reste.
        $conv2  = $this->conversation();
        $table2 = $this->clients($conv2, 60);
        $card2  = DrivePendingAction::create(self::USER, $conv2, WorkTableTools::ENRICH,
            ['table_id' => (int)$table2['id'], 'table' => 'clients', 'spec' => self::spec($table2), 'llm' => 'openai::o3', 'rows' => 60, 'cap' => null], ['action' => 'Enrichir']);
        $job2 = (int)$this->decide($conv2, $card2)['job_id'];
        $calls = 0;
        $judge = self::judge();
        WorkTableEnricher::overrideCompletion(function (...$a) use (&$calls, $judge, $job2) {
            if (++$calls === 2) {
                JobQueue::requestCancel($job2, self::USER);
                // L'arrêt est relu au plus deux fois par seconde (JobContext).
                usleep(600000);
            }
            return $judge(...$a);
        });
        $this->runJob($job2);
        $this->assertSame('cancelled', $this->job($job2)['status']);
        $left = WorkTableEnricher::pending((new SqlWorkTableStore())->rows((int)$table2['id']), self::spec($table2));
        $this->assertGreaterThan(0, count($left));
        $this->assertLessThan(60, count($left));
        $bilan = $this->replies($conv2)[1];
        $this->assertStringContainsString('arrêtée à votre demande', $bilan);
        $this->assertStringContainsString(count($left) . ' lignes restent à faire', $bilan);
    }

    public function testLeJobExecuteChaqueActionEtNoteSonIssueDansLaTable(): void
    {
        $conv  = $this->conversation();
        $table = $this->clients($conv, 4);
        $items = [];
        foreach ([0, 1, 3] as $position) {
            $id = DrivePendingAction::create(self::USER, $conv, 'creer_tache', ['titre' => 'Relancer Client ' . ($position + 1)], ['action' => 'Créer une tâche Google', 'fichier' => 'Relancer']);
            DrivePendingAction::hold($id, self::USER);
            $items[] = [$position, $id];
        }
        $group = DrivePendingAction::create(self::USER, $conv, WorkTableTools::ACT,
            ['table_id' => (int)$table['id'], 'table' => 'clients', 'tool' => 'creer_tache', 'items' => $items], ['action' => 'Créer 3 tâches Google']);
        $data = $this->decide($conv, $group);
        $this->assertSame('table_mission', $data['job_type']);
        $this->assertStringStartsWith('⏳ Création de 3 tâches Google en cours', $this->replies($conv)[0]);

        // Chemin RÉEL d'une décision : Google Tasks n'est pas relié — chaque action échoue, proprement.
        $this->runJob((int)$data['job_id']);
        $this->assertSame('done', $this->job((int)$data['job_id'])['status']);
        $rows = (new SqlWorkTableStore())->rows((int)$table['id']);
        $this->assertStringStartsWith('échec : ', $rows[0]['tache_statut']);
        $this->assertStringContainsString('Google Tasks', $rows[0]['tache_statut']);
        $this->assertSame('', $rows[0]['tache_lien']);
        $this->assertArrayNotHasKey('tache_statut', $rows[2], 'La ligne sans action n’est pas touchée.');
        foreach ($items as [, $id]) {
            $this->assertSame('failed', $this->action($id)['status']);
        }
        $replies = $this->replies($conv);
        $this->assertCount(2, $replies, 'Aucun message par ligne : le lancement, puis le bilan.');
        $this->assertStringStartsWith('⚠️ Aucune action n’a abouti', $replies[1]);
        $this->assertStringContainsString('3 lignes en échec', $replies[1]);

        // Réussite (décision simulée) : statut et lien notés ; arrêt : le reste est écarté.
        $conv2  = $this->conversation();
        $table2 = $this->clients($conv2, 30);
        $items2 = [];
        for ($p = 0; $p < 30; $p++) {
            $id = DrivePendingAction::create(self::USER, $conv2, 'creer_brouillon_gmail', ['destinataire' => 'c@ex.fr'], []);
            DrivePendingAction::hold($id, self::USER);
            $items2[] = [$p, $id];
        }
        $jobId = JobQueue::enqueue('table_mission', self::USER, $conv2, ['op' => 'act', 'table_id' => (int)$table2['id'], 'tool' => 'creer_brouillon_gmail', 'items' => $items2]);
        $chat = new class extends ChatController {
            public int $decided = 0;
            public function decideForTable(int $convId, array $user, int $actionId): array
            {
                $this->decided++;
                if ($this->decided === 12) {
                    JobQueue::requestCancel((int)$GLOBALS['__mission_job'], (int)$user['id']);
                    // L'arrêt est relu au plus deux fois par seconde (JobContext).
                    usleep(600000);
                }
                DrivePendingAction::cancelHeld([$actionId], (int)$user['id'], 'simulé');
                return ['ok' => true, 'statut' => 'executed', 'resultat' => ['nom' => 'Relance', 'url' => 'https://mail.google.com/mail/#drafts/' . $actionId, 'type' => 'Brouillon', 'verbe' => 'créé']];
            }
        };
        $GLOBALS['__mission_job'] = $jobId;
        JobQueue::claimById($jobId);
        $job = new class extends TableMissionJob {
            public function go(ChatController $chat, array $payload, JobContext $ctx): array
            {
                return $this->execute($chat, $payload, $ctx);
            }
        };
        $result = $job->go($chat, json_decode((string)$this->job($jobId)['payload_json'], true), new JobContext($jobId, self::USER, $conv2));
        unset($GLOBALS['__mission_job']);
        $this->assertTrue($result['cancelled'] ?? false);
        $rows2 = (new SqlWorkTableStore())->rows((int)$table2['id']);
        $done = array_filter($rows2, static fn(array $r): bool => ($r['brouillon_statut'] ?? '') === WorkTableActions::STATUS_DONE);
        $this->assertGreaterThanOrEqual(12, count($done));
        $this->assertLessThan(30, count($done));
        $this->assertStringStartsWith('https://mail.google.com/mail/#drafts/', $rows2[0]['brouillon_lien']);
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM drive_pending_actions WHERE conversation_id = $conv2 AND status = 'held'")->fetchColumn(), 'Rien ne reste en réserve après un arrêt.');
        $this->assertStringContainsString((30 - $chat->decided) . ' lignes n’ont pas été traitées', $result['content']);

        // Table transformée par un autre message pendant l'opération : elle s'arrête, sans rien écraser.
        $conv3  = $this->conversation();
        $table3 = $this->clients($conv3, 30);
        $items3 = [];
        for ($p = 0; $p < 30; $p++) {
            $id = DrivePendingAction::create(self::USER, $conv3, 'creer_brouillon_gmail', ['destinataire' => 'c@ex.fr'], []);
            DrivePendingAction::hold($id, self::USER);
            $items3[] = [$p, $id];
        }
        $job3 = JobQueue::enqueue('table_mission', self::USER, $conv3, ['op' => 'act', 'table_id' => (int)$table3['id'], 'tool' => 'creer_brouillon_gmail', 'items' => $items3]);
        JobQueue::claimById($job3);
        $tableId = (int)$table3['id'];
        $chat3 = new class extends ChatController {
            public int $decided = 0;
            public ?\Closure $onFifth = null;
            public function decideForTable(int $convId, array $user, int $actionId): array
            {
                if (++$this->decided === 5 && $this->onFifth !== null) {
                    ($this->onFifth)();
                }
                DrivePendingAction::cancelHeld([$actionId], (int)$user['id'], 'simulé');
                return ['ok' => true, 'statut' => 'executed', 'resultat' => ['nom' => 'Relance', 'url' => 'https://mail.google.com/x', 'type' => 'Brouillon', 'verbe' => 'créé']];
            }
        };
        $chat3->onFifth = static function () use ($conv3, $tableId): void {
            $store = new SqlWorkTableStore();
            $store->save($conv3, self::USER, 'clients', 'Clients', (array)$store->findById($conv3, $tableId)['columns'],
                array_reverse($store->rows($tableId)), ['kind' => 'transformation', 'depuis' => 'clients']);
        };
        $result3 = $job->go($chat3, json_decode((string)$this->job($job3)['payload_json'], true), new JobContext($job3, self::USER, $conv3));
        $this->assertSame(10, $chat3->decided, 'Arrêt au premier point de contrôle refusé.');
        $this->assertStringContainsString('modifiée pendant', $result3['content']);
        $this->assertStringContainsString('20 lignes n’ont pas été traitées', $result3['content']);
        $rows3 = (new SqlWorkTableStore())->rows($tableId);
        $this->assertSame('Client 30', $rows3[0]['nom'], 'La table triée est intacte.');
        $this->assertSame('', $rows3[0]['brouillon_statut'] ?? '');
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM drive_pending_actions WHERE conversation_id = $conv3 AND status = 'held'")->fetchColumn());
    }

    public function testUnTourDeChatTientLesActionsParLigneEnReserveSousUneSeuleCarte(): void
    {
        // Gmail relié (aucun appel réseau : la préparation d'un brouillon n'en fait pas).
        // Le réglage du connecteur peut avoir été laissé vide par un autre test : il est remis ensuite.
        $before = (string)Settings::get(UserGmailService::SETTING_ENABLED, '1');
        Settings::set(UserGmailService::SETTING_ENABLED, '1');
        Settings::set(SsoRegistry::SETTING_GOOGLE_CLIENT, 'client-factice');
        Settings::set(SsoRegistry::SETTING_GOOGLE_SECRET, Encryption::encrypt('secret-factice'));
        UserDriveToken::saveConnection(self::USER, 'refresh', 'access', 3600,
            'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.compose', 'moi@example.test', 'gmail');
        try {
            FakeLlmStream::install();
            ChatController::setEmitSink(function (array $e): void {
                $this->events[] = $e;
            });
            $conv  = $this->conversation();
            $table = $this->clients($conv, 6);
            // Une ligne sans adresse, une adresse fausse : écartées, les autres préparées.
            $store = new SqlWorkTableStore();
            $rows  = $store->rows((int)$table['id']);
            $store->patch($conv, (int)$table['id'], (array)$table['columns'], [2 => ['email' => ''] + $rows[2], 4 => ['email' => 'pas-une-adresse'] + $rows[4]]);

            $args = ['table' => 'clients', 'action' => 'creer_brouillon_gmail',
                'arguments' => json_encode(['destinataire' => '{email}', 'objet' => 'Relance — {nom}', 'corps' => 'Bonjour {nom}, votre facture est en retard.'], JSON_UNESCAPED_UNICODE)];
            FakeLlmStream::$queue = [
                'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                    'index' => 0, 'id' => 'call_1', 'type' => 'function',
                    'function' => ['name' => WorkTableTools::ACT, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
                ]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
                . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
                . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
                . "data: [DONE]\n\n",
                FakeLlmStream::sse('Quatre brouillons sont prêts à être créés.'),
            ];
            $q = Message::create($conv, 'user', 'Prépare un brouillon de relance pour chaque client de la table.');
            $r = (new ChatController())->runSend(
                ['content' => 'Prépare un brouillon de relance pour chaque client de la table.', 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false, 'gmail_access' => true],
                self::USER,
                $conv
            );
            $this->assertTrue($r['done'] ?? false);

            // L'outil est proposé, avec la seule action que le connecteur engagé permet.
            $defs = array_column(array_map(static fn(array $t): array => (array)($t['function'] ?? []), (array)FakeLlmStream::$requests[0]['tools']), null, 'name');
            $this->assertSame(['creer_brouillon_gmail'], $defs[WorkTableTools::ACT]['parameters']['properties']['action']['enum']);

            // UNE carte à l'écran — pas une par ligne.
            $cards = array_values(array_filter(array_map(static fn(array $e): ?array => $e['drive_confirm'] ?? null, $this->events)));
            $this->assertCount(1, $cards);
            $this->assertSame('Créer 4 brouillons Gmail', $cards[0]['action']);
            $this->assertSame('/assets/img/gmail.svg', $cards[0]['icone']);
            $this->assertStringContainsString('2 lignes écartées', implode(' ', $cards[0]['impacts']));
            $this->assertStringContainsString('Bonjour Client 1', $cards[0]['apercu']);
            $this->assertStringContainsString('1. Relance — Client 1 → c1@ex.fr', $cards[0]['apercu']);

            // En base : quatre actions tenues en réserve, déclinées avec les valeurs de LEUR ligne…
            $held = $this->db()->query("SELECT * FROM drive_pending_actions WHERE conversation_id = $conv AND status = 'held' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(4, $held);
            $this->assertSame(['creer_brouillon_gmail'], array_values(array_unique(array_column($held, 'tool'))));
            $first = json_decode((string)$held[0]['args_json'], true);
            $this->assertSame(['c1@ex.fr', 'Relance — Client 1', 'Bonjour Client 1, votre facture est en retard.'], [$first['destinataire'], $first['objet'], $first['corps']]);
            $this->assertNull($held[0]['message_id'], 'Une action en réserve n’est la carte d’aucune réponse.');
            // … et la carte du groupe, seule à attendre, qui les désigne par leur rang.
            $pending = DrivePendingAction::pendingForConversation($conv, self::USER);
            $this->assertSame([WorkTableTools::ACT], array_column($pending, 'tool'));
            $group = json_decode((string)$this->action((int)$pending[0]['id'])['args_json'], true);
            $this->assertSame([0, 1, 3, 5], array_column($group['items'], 0));
            $this->assertSame(array_map('intval', array_column($held, 'id')), array_column($group['items'], 1));

            // La trace dit ce qui a été préparé ; le modèle sait que rien n'est fait.
            $actions = array_values(array_filter(array_map(static fn(array $e): ?array => $e['action'] ?? null, $this->events)));
            $act = $actions[array_search('table_act', array_column($actions, 't'), true)];
            $this->assertSame('« clients » (4 actions à approuver)', $act['d']);
            $back = '';
            foreach ((array)FakeLlmStream::$requests[1]['messages'] as $m) {
                if (($m['role'] ?? '') === 'tool') {
                    $back .= (string)$m['content'];
                }
            }
            $this->assertStringContainsString('"actions_preparees":4', $back);
            $this->assertStringContainsString('RIEN n', $back);

            // Refuser le groupe écarte tout ce qui était en réserve.
            $this->decide($conv, (int)$pending[0]['id'], 'cancel');
            $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM drive_pending_actions WHERE conversation_id = $conv AND status IN ('held','pending')")->fetchColumn());
        } finally {
            $this->db()->exec("DELETE FROM settings WHERE `key` IN ('" . SsoRegistry::SETTING_GOOGLE_CLIENT . "', '" . SsoRegistry::SETTING_GOOGLE_SECRET . "')");
            $this->db()->exec('DELETE FROM user_drive_tokens WHERE user_id = ' . self::USER);
            Settings::set(UserGmailService::SETTING_ENABLED, $before);
            Settings::refresh();
        }
    }

    public function testUnTourDeChatEnrichitPeuDeLignesEtProposeAuDela(): void
    {
        FakeLlmStream::install();
        ChatController::setEmitSink(function (array $e): void {
            $this->events[] = $e;
        });
        $send = function (int $conv, string $text): array {
            $q = Message::create($conv, 'user', $text);
            return (new ChatController())->runSend(['content' => $text, 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false], self::USER, $conv);
        };
        $call = static function (array $args): string {
            return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                    'index' => 0, 'id' => 'call_1', 'type' => 'function',
                    'function' => ['name' => WorkTableTools::ENRICH, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
                ]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
                . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
                . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
                . "data: [DONE]\n\n";
        };
        $args = ['table' => 'clients', 'consigne' => 'Faut-il relancer ce client ?', 'colonnes' => [['nom' => 'À relancer', 'type' => 'oui_non']], 'lire' => ['Note']];
        WorkTableEnricher::overrideCompletion(self::judge());

        // Peu de lignes : enrichies pendant la réponse.
        $conv  = $this->conversation();
        $table = $this->clients($conv, 20);
        FakeLlmStream::$queue = [$call($args), FakeLlmStream::sse('Dix clients sont à relancer.')];
        $send($conv, 'Dans la table clients, lesquels faut-il relancer ?');
        $names = array_map(static fn(array $t): string => (string)($t['function']['name'] ?? ''), (array)(FakeLlmStream::$requests[0]['tools'] ?? []));
        $this->assertContains(WorkTableTools::ENRICH, $names);
        $this->assertNotContains(WorkTableTools::ACT, $names, 'Aucun connecteur qui écrit : pas d’action par ligne.');
        $rows = (new SqlWorkTableStore())->rows((int)$table['id']);
        $this->assertSame(10, count(array_filter($rows, static fn(array $r): bool => ($r['a_relancer'] ?? '') === 'oui')));
        $actions = array_values(array_filter(array_map(static fn(array $e): ?array => $e['action'] ?? null, $this->events)));
        $enrich  = $actions[array_search('table_enrich', array_column($actions, 't'), true)];
        $this->assertSame('« clients » (20 lignes enrichies)', $enrich['d']);
        $this->assertSame(0, (int)$this->db()->query("SELECT COUNT(*) FROM drive_pending_actions WHERE conversation_id = $conv")->fetchColumn());

        // Beaucoup de lignes : une carte, rien n'est fait.
        $this->events = [];
        $conv2  = $this->conversation();
        $table2 = $this->clients($conv2, 120);
        FakeLlmStream::$queue = [$call($args), FakeLlmStream::sse('Je vous propose de lancer l’enrichissement.')];
        FakeLlmStream::$requests = [];
        WorkTableEnricher::overrideCompletion(static fn(): string => throw new \LogicException('rien ne part sans accord'));
        $send($conv2, 'Dans la table clients, lesquels faut-il relancer ?');
        $cards = array_values(array_filter(array_map(static fn(array $e): ?array => $e['drive_confirm'] ?? null, $this->events)));
        $this->assertCount(1, $cards);
        $this->assertSame('Enrichir 120 lignes', $cards[0]['action']);
        $pending = DrivePendingAction::pendingForConversation($conv2, self::USER);
        $this->assertSame([WorkTableTools::ENRICH], array_column($pending, 'tool'));
        $this->assertNotNull($pending[0]['message_id'], 'La carte revient sous la réponse au rechargement.');
        $this->assertNotContains('a_relancer', WorkTableOps::keys((array)(new SqlWorkTableStore())->findById($conv2, (int)$table2['id'])['columns']));
    }
}
