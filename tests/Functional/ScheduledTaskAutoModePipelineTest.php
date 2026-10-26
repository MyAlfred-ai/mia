<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Tâches programmées, « Agir sans validation », sur la vraie base : une
 * action préparée pendant l'exécution est EXÉCUTÉE aussitôt, par le chemin
 * d'un clic « Approuver » (ChatController::decideAction), sans carte.
 *
 * L'action exercée est la seule qui s'exécute sans service tiers : la carte
 * « Programmer une tâche » (elle crée une tâche en base). L'échec l'est par un
 * connecteur coupé par l'administrateur — aucune requête réseau ici.
 */
final class ScheduledTaskAutoModePipelineTest extends DatabaseTestCase
{
    /** @var array<string, ?string> réglages touchés, rétablis en fin de test */
    private array $reglagesAvant = [];

    /** @var list<array> évènements SSE émis pendant le test */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        $this->insertUser(1);
        $this->insertUser(2);

        Settings::refresh();
        foreach ([ScheduledTask::SETTING_ENABLED, ScheduledTask::SETTING_AUTO, UserCalendarService::SETTING_ENABLED] as $cle) {
            $this->reglagesAvant[$cle] = $this->reglage($cle);
        }
        Settings::set(ScheduledTask::SETTING_ENABLED, '1');
        Settings::set(ScheduledTask::SETTING_AUTO, '1');

        $this->events = [];
        ChatController::setEmitSink(function (array $event): void {
            $this->events[] = $event;
        });
    }

    protected function tearDown(): void
    {
        ChatController::setEmitSink(null);
        // Sans base, le socle a sauté le test mais PHPUnit appelle tearDown().
        if (self::$pdo !== null) {
            foreach ($this->reglagesAvant as $cle => $valeur) {
                if ($valeur === null) {
                    $this->db()->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$cle]);
                } else {
                    Settings::set($cle, $valeur);
                }
            }
            Settings::refresh();
        }
        parent::tearDown();
    }

    /** Valeur EN BASE d'un réglage (null = absent : le défaut du code s'applique). */
    private function reglage(string $cle): ?string
    {
        $stmt = $this->db()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$cle]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    private function conversation(int $userId = 1): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([$userId, 'Point du lundi']);
        return (int)$this->db()->lastInsertId();
    }

    private static function saisie(array $surcharge = []): array
    {
        return $surcharge + [
            'name'        => 'Revue du vendredi',
            'instruction' => 'Fais la revue de la semaine.',
            'profile_id'  => '',
            'llm'         => '',
            'freq'        => 'weekly',
            'days'        => ['5'],
            'day'         => '1',
            'time'        => '17:00',
            'timezone'    => 'Europe/Paris',
            'sources'     => [],
            'destination' => 'thread',
            'notify'      => '1',
        ];
    }

    /** Une carte « Programmer une tâche » déposée pendant l'exécution, comme par l'outil de MIA. */
    private function carteTache(int $convId, string $nom = 'Revue du vendredi'): array
    {
        $user = User::findById(1);
        $data = ScheduledTask::normalizeInput(self::saisie(['name' => $nom]), ScheduledTask::allowedFrom(ScheduledTask::choicesFor($user)));
        $preview = ['action' => 'Programmer une tâche', 'fichier' => $nom];
        $id = DrivePendingAction::create(1, $convId, 'programmer_tache', ScheduledTask::toInput($data), $preview);
        return [$id, $preview];
    }

    private function executer(ChatController $chat, int $convId, int $actionId, array $preview, ?string $mail = null, bool $envoi = false): array
    {
        $m = new ReflectionMethod(ChatController::class, 'runAutoAction');
        return $m->invoke($chat, $convId, User::findById(1), $actionId, $preview, $mail, $envoi);
    }

    private function propriete(ChatController $chat, string $nom): mixed
    {
        return (new ReflectionProperty(ChatController::class, $nom))->getValue($chat);
    }

    private function action(int $id): array
    {
        return $this->db()->query('SELECT * FROM drive_pending_actions WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array> messages assistant de la conversation */
    private function reponses(int $convId): array
    {
        $stmt = $this->db()->prepare("SELECT id, content FROM messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id");
        $stmt->execute([$convId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Persistance du mode
    // ------------------------------------------------------------------

    public function testLaTacheGardeSonModeEnBase(): void
    {
        $user = User::findById(1);
        $allowed = ScheduledTask::allowedFrom(ScheduledTask::choicesFor($user));
        $now = new \DateTimeImmutable('2026-10-05 06:00:00', new \DateTimeZone('UTC'));

        $id = ScheduledTask::create(1, ScheduledTask::normalizeInput(self::saisie(['allow_actions' => 'auto', 'auto_send_mail' => '1']), $allowed), $now);
        $task = ScheduledTask::findById($id);
        $this->assertSame(ScheduledTask::MODE_AUTO, ScheduledTask::modeOf($task));
        $this->assertTrue($task['auto_send_mail']);

        ScheduledTask::update($id, 1, ScheduledTask::normalizeInput(self::saisie(['allow_actions' => '1', 'auto_send_mail' => '1']), $allowed), $now);
        $task = ScheduledTask::findById($id);
        $this->assertSame(ScheduledTask::MODE_APPROVE, ScheduledTask::modeOf($task));
        $this->assertFalse($task['auto_send_mail'], 'l’envoi ne survit pas au mode');

        ScheduledTask::update($id, 1, ScheduledTask::normalizeInput(self::saisie(), $allowed), $now);
        $this->assertSame(ScheduledTask::MODE_READ, ScheduledTask::modeOf(ScheduledTask::findById($id)));
        $row = $this->db()->query('SELECT allow_actions, auto_actions, auto_send_mail FROM scheduled_tasks WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['allow_actions' => 0, 'auto_actions' => 0, 'auto_send_mail' => 0], array_map('intval', $row));
    }

    public function testRetireParLAdministrateurLeFormulaireNeLeRetientPlus(): void
    {
        Settings::set(ScheduledTask::SETTING_AUTO, '0');
        $this->assertFalse(ScheduledTask::autoAllowed());
        $choices = ScheduledTask::choicesFor(User::findById(1));
        $this->assertFalse($choices['auto']);
        $data = ScheduledTask::normalizeInput(self::saisie(['allow_actions' => 'auto']), ScheduledTask::allowedFrom($choices));
        $this->assertSame(ScheduledTask::MODE_APPROVE, ScheduledTask::modeOf($data));
    }

    // ------------------------------------------------------------------
    // Exécution sans validation
    // ------------------------------------------------------------------

    public function testUneActionEstExecuteeParLeCheminDUnClic(): void
    {
        $conv = $this->conversation();
        [$id, $preview] = $this->carteTache($conv);
        $chat = new ChatController();

        $res = $this->executer($chat, $conv, $id, $preview);

        $this->assertSame('effectuee', $res['statut']);
        $this->assertStringContainsString('Revue du vendredi', $res['action']);
        $this->assertSame(Scheduler::AUTO_DONE_NOTE, $res['message']);
        // L'action a eu lieu : la tâche existe, la file le garde, marqué.
        $this->assertCount(1, ScheduledTask::forUser(1));
        $row = $this->action($id);
        $this->assertSame('executed', $row['status']);
        $this->assertSame(1, (int)$row['auto_executed']);
        // Aucune carte en attente au rechargement.
        $this->assertSame([], DrivePendingAction::pendingForConversation($conv, 1));

        // Le relevé : UN message, l'issue du clic dedans.
        $reponses = $this->reponses($conv);
        $this->assertCount(1, $reponses);
        $this->assertStringStartsWith('**Ce que la tâche a fait sans validation**', $reponses[0]['content']);
        $this->assertStringContainsString('✅ Tâche programmée', $reponses[0]['content']);
        $emis = array_values(array_filter($this->events, static fn(array $e): bool => isset($e['auto_record'])));
        $this->assertCount(1, $emis);
        $this->assertSame((int)$reponses[0]['id'], $emis[0]['auto_record']['id']);
        $this->assertArrayNotHasKey('drive_confirm', array_merge(...array_map(static fn(array $e): array => $e, $this->events)));

        // La ligne de trace attend sa place, juste après celle de la préparation.
        $this->assertSame(['d' => $res['action'], 'ok' => true], $this->propriete($chat, 'autoTraceEntry'));
        // Rien ne reste en mode lot ou relevé : un clic ordinaire suivant serait servi normalement.
        $this->assertFalse($this->propriete($chat, 'batchMode'));
        $this->assertFalse($this->propriete($chat, 'autoRecording'));
    }

    public function testUnEchecSansMessageFigureQuandMemeAuReleveTenuAJour(): void
    {
        Settings::set(UserCalendarService::SETTING_ENABLED, '0');
        $conv = $this->conversation();
        $chat = new ChatController();

        // 1. Une action qui réussit : le relevé naît.
        [$tache, $previewTache] = $this->carteTache($conv);
        $this->executer($chat, $conv, $tache, $previewTache);
        // 2. Un connecteur coupé par l'administrateur : échec SANS message d'issue.
        $preview = ['action' => 'Créer un évènement', 'fichier' => 'Réunion Atlas'];
        $agenda = DrivePendingAction::create(1, $conv, 'creer_evenement_agenda', ['titre' => 'Réunion Atlas', 'debut' => '2026-10-06 14:00'], $preview);
        $res = $this->executer($chat, $conv, $agenda, $preview);

        $this->assertSame('non_effectuee', $res['statut']);
        $this->assertStringContainsString('désactivée', $res['raison']);
        $this->assertSame(Scheduler::AUTO_FAILED_NOTE, $res['message']);
        $row = $this->action($agenda);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(1, (int)$row['auto_executed']);
        $this->assertFalse($this->propriete($chat, 'autoTraceEntry')['ok']);

        // Toujours UN message, réécrit : la réussite puis l'échec, nommé.
        $reponses = $this->reponses($conv);
        $this->assertCount(1, $reponses);
        $this->assertStringContainsString('✅ Tâche programmée', $reponses[0]['content']);
        $this->assertStringContainsString('⚠️ Non effectué — Créer un évènement « Réunion Atlas » :', $reponses[0]['content']);
        $this->assertLessThan(strpos($reponses[0]['content'], '⚠️'), strpos($reponses[0]['content'], '✅'));
        $this->assertSame((int)$reponses[0]['id'], $this->propriete($chat, 'autoRecord')['id']);
    }

    public function testAbandonNeReecritJamaisUneActionTranchee(): void
    {
        $conv = $this->conversation();
        $attente = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'A'], []);
        $reservee = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'B'], []);
        DrivePendingAction::claim($reservee, 1, $conv);
        $faite = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'C'], []);
        DrivePendingAction::resolve($faite, 'executed');

        foreach ([$attente, $reservee, $faite] as $id) {
            DrivePendingAction::abandon($id, 'Interrompue');
        }
        $this->assertSame('failed', $this->action($attente)['status']);
        $this->assertSame('failed', $this->action($reservee)['status']);
        $this->assertSame('Interrompue', $this->action($reservee)['error']);
        $this->assertSame('executed', $this->action($faite)['status']);
    }

    public function testLaNotificationCompteLesActionsDeCetteReponse(): void
    {
        $conv = $this->conversation();
        $reponse = Message::create($conv, 'assistant', 'Compte rendu');
        $autre = Message::create($conv, 'assistant', 'Autre');

        $faites = [];
        foreach (['A', 'B'] as $titre) {
            $id = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => $titre], []);
            DrivePendingAction::markAutoExecuted($id);
            DrivePendingAction::resolve($id, 'executed');
            $faites[] = $id;
        }
        $ratee = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'C'], []);
        DrivePendingAction::markAutoExecuted($ratee);
        DrivePendingAction::resolve($ratee, 'failed', 'Non connecté');
        $carte = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'D'], []);
        DrivePendingAction::resolve($carte, 'executed');
        DrivePendingAction::attachToMessage([...$faites, $ratee, $carte], (int)$reponse['id'], 1);
        $ailleurs = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'E'], []);
        DrivePendingAction::markAutoExecuted($ailleurs);
        DrivePendingAction::resolve($ailleurs, 'executed');
        DrivePendingAction::attachToMessage([$ailleurs], (int)$autre['id'], 1);

        $this->assertSame(2, DrivePendingAction::countAutoExecutedForMessage((int)$reponse['id'], 1));
        $this->assertSame(0, DrivePendingAction::countAutoExecutedForMessage((int)$reponse['id'], 2), 'jamais les actions d’autrui');
        $this->assertSame(0, DrivePendingAction::countAutoExecutedForMessage(0, 1));
    }

    public function testLesStatistiquesDesCartesNeComptentPasLesActionsSansValidation(): void
    {
        $conv = $this->conversation();
        $approuvee = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'A'], []);
        DrivePendingAction::resolve($approuvee, 'executed');
        $refusee = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'B'], []);
        DrivePendingAction::resolve($refusee, 'cancelled');
        for ($i = 0; $i < 3; $i++) {
            $id = DrivePendingAction::create(1, $conv, 'creer_tache', ['titre' => 'auto ' . $i], []);
            DrivePendingAction::markAutoExecuted($id);
            DrivePendingAction::resolve($id, 'executed');
        }

        $cartes = AgentTelemetry::cards(30);
        $total = end($cartes);
        $this->assertSame('', $total['tool']);
        $this->assertSame(2, $total['proposed']);
        $this->assertSame(1, $total['executed']);
        $this->assertSame(1, $total['cancelled']);
    }
}
