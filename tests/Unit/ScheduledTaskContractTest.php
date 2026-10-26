<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tâches programmées : formes pures (saisie, issue d'un job, décision de
 * temps, charge, garde de retard) et CÂBLAGE — chaque pièce branchée là où
 * elle doit l'être. Une tâche qui ne part jamais ne lève aucune erreur : ce
 * fichier est le seul endroit qui le verrait.
 */
final class ScheduledTaskContractTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
        require_once ROOT . '/src/Jobs/JobErrorException.php';
    }

    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    /** Corps d'une méthode (jusqu'à la méthode suivante). */
    private static function methode(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertIsInt($debut, "Méthode absente : $nom");
        $fin = preg_match('/\n    (public|private|protected)( static)? function /', $source, $m, PREG_OFFSET_CAPTURE, $debut + 10)
            ? $m[0][1] : strlen($source);
        return substr($source, $debut, $fin - $debut);
    }

    private static function choix(): array
    {
        return [
            'profiles' => [7 => true],
            'llms'     => ['openai::gpt-5.6' => 'GPT-5.6'],
            'sources'  => ['web', 'gmail'],
        ];
    }

    private static function saisie(array $surcharge = []): array
    {
        return $surcharge + [
            'name'        => '  Point   du lundi ',
            'instruction' => "Fais le point\r\nsur mes mails.",
            'profile_id'  => '',
            'llm'         => 'openai::gpt-5.6',
            'freq'        => 'weekly',
            'days'        => ['1'],
            'day'         => '1',
            'time'        => '08:00',
            'timezone'    => 'Europe/Paris',
            'sources'     => ['gmail', 'slack'],
            'destination' => 'thread',
        ];
    }

    // ------------------------------------------------------------------
    // Saisie
    // ------------------------------------------------------------------

    public function testUneSaisieValideEstMiseEnForme(): void
    {
        $data = ScheduledTask::normalizeInput(self::saisie(), self::choix());
        $this->assertSame('Point du lundi', $data['name']);
        $this->assertSame("Fais le point\nsur mes mails.", $data['instruction']);
        $this->assertNull($data['profile_id']);
        $this->assertSame('openai::gpt-5.6', $data['llm']);
        $this->assertSame(['freq' => 'weekly', 'days' => [1], 'time' => '08:00'], $data['rule']);
        $this->assertSame('Europe/Paris', $data['timezone']);
        // Une source que la personne ne peut pas engager disparaît, sans reproche.
        $this->assertSame(['gmail'], $data['sources']);
        $this->assertSame('thread', $data['destination']);
    }

    public function testUnAssistantImposeSonModele(): void
    {
        $data = ScheduledTask::normalizeInput(self::saisie(['profile_id' => '7']), self::choix());
        $this->assertSame(7, $data['profile_id']);
        $this->assertSame('', $data['llm'], 'Le modèle choisi sans assistant ne survit pas au choix d’un assistant.');
    }

    public function testLeNomParDefautEstLeDebutDeLaConsigne(): void
    {
        $data = ScheduledTask::normalizeInput(self::saisie(['name' => '']), self::choix());
        $this->assertSame('Fais le point sur mes mails.', $data['name']);
        $long = ScheduledTask::defaultName(str_repeat('mot ', 40));
        $this->assertLessThanOrEqual(60, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
    }

    /** @return iterable<string, array{0: array}> */
    public static function saisiesRefusees(): iterable
    {
        yield 'consigne vide'           => [['instruction' => "  \n "]];
        yield 'consigne trop longue'    => [['instruction' => str_repeat('a', ScheduledTask::INSTRUCTION_MAX + 1)]];
        yield 'assistant hors périmètre' => [['profile_id' => '99']];
        yield 'modèle hors périmètre'   => [['llm' => 'anthropic::claude-fable-5-1']];
        yield 'règle invalide'          => [['freq' => 'weekly', 'days' => []]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('saisiesRefusees')]
    public function testUneSaisieInvalideEstRefuseeAvecUnMessage(array $surcharge): void
    {
        try {
            ScheduledTask::normalizeInput(self::saisie($surcharge), self::choix());
            $this->fail('Saisie acceptée à tort.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testUnFuseauInconnuEstOublie(): void
    {
        $this->assertSame('', ScheduledTask::normalizeTimezone('Mars/Olympus'));
        $this->assertSame('Europe/Paris', ScheduledTask::normalizeTimezone('Europe/Paris'));
        $this->assertSame('New York', ScheduledTask::zoneCity('America/New_York'));
        $this->assertSame('UTC', ScheduledTask::zoneCity(''));
    }

    public function testChaqueRaisonEstNommee(): void
    {
        foreach ([ScheduledTask::PAUSE_USER, ScheduledTask::PAUSE_FAILURES, ScheduledTask::PAUSE_ACCOUNT,
                  ScheduledTask::PAUSE_ASSISTANT, ScheduledTask::PAUSE_RULE, 'quota', 'budget',
                  'still_running', 'late', 'model_unavailable', 'model_error', 'lost', 'inconnue'] as $raison) {
            $this->assertNotSame('', ScheduledTask::reasonLabel($raison), "Raison sans libellé : $raison");
        }
        foreach (['queued', 'done', 'failed', 'cancelled', 'skipped', 'missed'] as $statut) {
            $this->assertNotSame($statut, ScheduledTask::runStatusLabel($statut), "Statut sans libellé : $statut");
        }
    }

    // ------------------------------------------------------------------
    // Issue d'un job → exécution
    // ------------------------------------------------------------------

    public function testLIssueDUnJobDevientLeStatutDeLExecution(): void
    {
        $this->assertSame(['done', ''], ScheduledTaskRun::outcome('done', ['msg_id' => 4], null));
        $this->assertSame(['failed', 'model_error'], ScheduledTaskRun::outcome('done', ['error' => true], null));
        $this->assertSame(['cancelled', ''], ScheduledTaskRun::outcome('cancelled', null, null));
        // Quota et budget : la tâche n'y est pour rien — sautée, pas en échec.
        $this->assertSame(['skipped', 'quota'], ScheduledTaskRun::outcome('failed', null, ['kind' => 'quota']));
        $this->assertSame(['skipped', 'budget'], ScheduledTaskRun::outcome('failed', null, ['kind' => 'budget']));
        $this->assertSame(['missed', 'late'], ScheduledTaskRun::outcome('failed', null, ['kind' => 'generic', 'scheduled_reason' => 'late']));
        $this->assertSame(['failed', 'overloaded'], ScheduledTaskRun::outcome('failed', null, ['kind' => 'overloaded']));
        $this->assertSame(['failed', 'generic'], ScheduledTaskRun::outcome('failed', null, null));
    }

    // ------------------------------------------------------------------
    // Planificateur
    // ------------------------------------------------------------------

    public function testUnCreneauTropAncienEstManqueJamaisRattrape(): void
    {
        $slot = new \DateTimeImmutable('2026-10-05 06:00', new \DateTimeZone('UTC'));
        $this->assertNull(Scheduler::timingDecision($slot, $slot->modify('+30 seconds'), false, false));
        $this->assertNull(Scheduler::timingDecision($slot, $slot->modify('+11 hours'), false, false));
        $this->assertSame(['missed', 'late'], Scheduler::timingDecision($slot, $slot->modify('+3 days'), false, false));
        // « Exécuter maintenant » n'a pas de retard.
        $this->assertNull(Scheduler::timingDecision($slot, $slot->modify('+3 days'), true, false));
        // Une exécution encore en cours fait sauter la suivante au lieu de les empiler.
        $this->assertSame(['skipped', 'still_running'], Scheduler::timingDecision($slot, $slot, false, true));
    }

    public function testChaqueSourceDuChatAUnInterrupteur(): void
    {
        $this->assertSame(
            ConnectorRegistry::keys(),
            array_keys(Scheduler::ACCESS_FLAGS),
            'Une source du menu « + » sans interrupteur de tâche serait ignorée en silence.'
        );
        $runSend = self::methode(self::src('src/Controllers/ChatController.php'), 'runSend');
        foreach (Scheduler::ACCESS_FLAGS as $flag) {
            $this->assertStringContainsString("\$payload['$flag']", $runSend, "runSend ne lit pas $flag");
        }
    }

    public function testUneExecutionPartieTropTardEstArreteeAvantLaGeneration(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-05 20:00', new \DateTimeZone('UTC'));
        // Sans bloc « scheduled », ou à l'heure : rien ne bloque.
        Scheduler::guardLateStart(['content' => 'x'], $maintenant);
        Scheduler::guardLateStart(['scheduled' => ['not_after' => '2026-10-05 20:00:00']], $maintenant);
        try {
            Scheduler::guardLateStart(['scheduled' => ['not_after' => '2026-10-05 18:00:00']], $maintenant);
            $this->fail('Exécution tardive non arrêtée.');
        } catch (JobErrorException $e) {
            $this->assertSame('late', $e->payload()['scheduled_reason']);
            $this->assertSame(['missed', 'late'], ScheduledTaskRun::outcome('failed', null, $e->payload()));
        }
    }

    public function testLaConsigneDeMethodeExigeLaLectureSeule(): void
    {
        $note = Scheduler::promptNote(['name' => 'Point du lundi']);
        $this->assertStringContainsString('TÂCHE PROGRAMMÉE', $note);
        $this->assertStringContainsString('« Point du lundi »', $note);
        $this->assertStringContainsString('Lecture seule', $note);
        $this->assertStringContainsString('Ne pose pas de question', $note);
        $this->assertStringContainsString('lecture seule', Scheduler::READ_ONLY_REFUSAL);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLaMigrationGarantitQuUnCreneauNePartQuUneFois(): void
    {
        $sql = self::src('database/migrations/081_scheduled_tasks.sql');
        $this->assertStringContainsString('UNIQUE KEY uq_str_slot (task_id, slot_at)', $sql);
        $this->assertStringContainsString('KEY idx_st_due (status, next_run_at)', $sql);
        $this->assertStringContainsString('ADD COLUMN scheduled_task_id', $sql);
        $this->assertStringContainsString('ADD COLUMN unread_at', $sql);
        // Assistant SANS clé étrangère : ni effacé par CASCADE, ni vidé en silence par SET NULL.
        $taches = substr($sql, (int)strpos($sql, 'CREATE TABLE IF NOT EXISTS scheduled_tasks'), 2000);
        $this->assertStringNotContainsString('REFERENCES profiles', $taches);
    }

    public function testLeWorkerEtLeReplisDeclenchentLePlanificateur(): void
    {
        $worker = self::src('scripts/worker.php');
        $this->assertStringContainsString('Scheduler::tick()', $worker);
        $this->assertStringContainsString('Scheduler::TICK_SEC', $worker);
        $this->assertStringContainsString('ScheduledTaskRun::maintain(', $worker);

        $queue = self::src('src/Jobs/JobQueue.php');
        $this->assertStringContainsString('Scheduler::tick();', self::methode($queue, 'maybeMaintain'));
        // Toute clôture de job — normale ou par le reaper — renseigne l'exécution.
        $this->assertStringContainsString('ScheduledTaskRun::onJobFinished($jobId, $status, $result, $error);', self::methode($queue, 'finish'));
        $this->assertStringContainsString('ScheduledTaskRun::onJobFinished(', self::methode($queue, 'reapStale'));
    }

    public function testLeDeclencheurNExecuteJamaisDansLaRequeteQuiLeVoit(): void
    {
        $tick = self::methode(self::src('src/Schedule/Scheduler.php'), 'tick');
        $this->assertStringContainsString('JobQueue::dispatch($jobId, false)', $tick);
    }

    public function testUneExecutionEstUnMessageOrdinaireEnLectureSeule(): void
    {
        $scheduler = self::src('src/Schedule/Scheduler.php');
        $this->assertStringContainsString("JobQueue::enqueue(\n            'chat',", $scheduler);
        $this->assertStringContainsString("Message::create(\$convId, 'user', (string)\$task['instruction'])", $scheduler);
        // Le créneau est réservé AVANT toute autre écriture.
        $start = self::methode($scheduler, 'start');
        $this->assertLessThan(
            strpos($start, 'Message::create'),
            strpos($start, 'ScheduledTaskRun::insert($taskId, $slot, $manual, ScheduledTaskRun::QUEUED)')
        );

        $job = self::src('src/Jobs/ChatSendJob.php');
        $this->assertStringContainsString('Scheduler::guardLateStart($payload);', $job);

        $runSend = self::methode(self::src('src/Controllers/ChatController.php'), 'runSend');
        $this->assertStringContainsString("\$scheduled         = is_array(\$payload['scheduled'] ?? null)", $runSend);
        // Pas de capture de mémoire ni de règle : la consigne est rejouée à chaque échéance.
        $this->assertStringContainsString('$memoryCapture = !$unattended', $runSend);
        $this->assertStringContainsString('$taskRuleCapture = $unattended ? null : $this->captureTaskRule(', $runSend);
        $this->assertStringContainsString('$loopRunsAnyway && !$unattended', $runSend);
        // La file d'approbation, passage OBLIGÉ de toute écriture, est remplacée par un refus.
        // Même porte pour une étape de workflow (WorkflowPrompt, son propre texte).
        $this->assertStringContainsString('$refusal = $workflowStep !== null ? WorkflowPrompt::READ_ONLY_REFUSAL : Scheduler::READ_ONLY_REFUSAL;', $runSend);
        $this->assertStringContainsString("=> ['error' => \$refusal];", $runSend);
        $this->assertLessThan(
            strpos($runSend, '=> [\'error\' => $refusal]'),
            strpos($runSend, '$queueConnectorWrite = function ('),
            'Le refus doit REMPLACER la file, donc venir après elle.'
        );
        $this->assertLessThan(
            strpos($runSend, 'new AgentTurn('),
            strpos($runSend, '=> [\'error\' => $refusal]'),
            'La file est remplacée avant d\'être confiée aux fournisseurs d\'outils.'
        );
        $this->assertStringContainsString('$systemPrompt .= Scheduler::promptNote($scheduled, $scheduledMode);', $runSend);
    }

    public function testLesRoutesLitteralesPrecedentLaRouteParametree(): void
    {
        $routes = self::src('public/index.php');
        $preview = strpos($routes, "post('/settings/schedules/preview'");
        $id      = strpos($routes, "post('/settings/schedules/{id}'");
        $this->assertIsInt($preview);
        $this->assertIsInt($id);
        $this->assertLessThan($id, $preview, 'preview serait avalée par {id}.');
        foreach (["get('/settings/schedules',", "get('/settings/schedules/new',", "post('/settings/schedules',",
                  "get('/settings/schedules/{id}/edit',", "post('/settings/schedules/{id}/toggle',",
                  "post('/settings/schedules/{id}/run',", "post('/settings/schedules/{id}/delete',"] as $route) {
            $this->assertStringContainsString($route, $routes);
        }
    }

    public function testLesClassesSontChargeesPartout(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $src = self::src($liste);
            foreach (['/src/Schedule/Recurrence.php', '/src/Models/ScheduledTask.php',
                      '/src/Models/ScheduledTaskRun.php', '/src/Schedule/Scheduler.php'] as $classe) {
                $this->assertStringContainsString($classe, $src, "$classe absent de $liste");
            }
        }
        $this->assertStringContainsString('/src/Controllers/ScheduledTaskController.php', self::src('config/classes.php'));
        // Récurrence avant les modèles qui l'appellent, Scheduler après eux.
        $classes = self::src('config/classes.php');
        $this->assertLessThan(strpos($classes, 'ScheduledTask.php'), strpos($classes, 'Recurrence.php'));
    }

    /**
     * Le formulaire s'enregistre À LA MAIN : une tâche agit seule, elle ne
     * doit jamais partir à moitié saisie (« 0 » en route vers « 08:00 »).
     */
    public function testLeFormulaireSEnregistreALaMain(): void
    {
        $form = self::src('templates/settings/schedule-form.php');
        $this->assertStringNotContainsString('data-autosave', substr($form, (int)strpos($form, '<form method="POST" action="<?= htmlspecialchars($action) ?>"')));
        $this->assertStringContainsString('<?= Csrf::field() ?>', $form);
        $this->assertStringContainsString("te('Programmer')", $form);
        $this->assertStringContainsString('name="timezone"', $form);
        // L'aperçu vient du serveur, jamais d'un calcul de dates en JavaScript.
        $js = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString("postJson('/settings/schedules/preview', body)", $js);
        $this->assertStringNotContainsString('getDay()', $js);
    }

    public function testLaPageEstUneTuileDeLEspaceDeTravail(): void
    {
        $this->assertSame('/settings/schedules', NavRegistry::WORKSPACE['schedules']['href']);
        // Fonctionnalité proposée ET droit du rôle « Tâches — Voir ».
        $this->assertStringContainsString("'schedules' => ScheduledTask::allows(\$user, 'view'),", self::src('src/Helpers/NavRegistry.php'));
        $this->assertContains('schedules', NavShell::PAGES, 'La page garde le volet du chat.');
    }

    public function testLeChatProposeEtSignaleLesTaches(): void
    {
        $message = self::src('templates/partials/message.php');
        $this->assertStringContainsString('class="msg-action-btn btn-schedule" href="/settings/schedules/new?from=', $message);

        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString("e.target.closest('.btn-schedule')", $js);
        $this->assertStringContainsString('function scheduleUrlForMessage(messageId)', $js);
        $this->assertStringContainsString('function buildScheduledBannerHtml(banner)', $js);
        $this->assertStringContainsString('buildScheduledBannerHtml(data.scheduled_task)', $js);
        $this->assertStringContainsString("a.classList.remove('is-unread')", $js);
        // Les sources reprises sont celles du planificateur, clé pour clé.
        foreach (array_keys(Scheduler::ACCESS_FLAGS) as $source) {
            $this->assertMatchesRegularExpression('/\b' . $source . ": '[a-zA-Z]+AccessToggle'/", $js, "Source absente du lien : $source");
        }

        $index = self::src('templates/chat/index.php');
        $this->assertStringContainsString('class="sched-banner" id="scheduledBanner"', $index);
        $this->assertStringContainsString("' is-unread'", $index);
        $this->assertStringContainsString('class="conv-unread-dot"', $index);
        $this->assertStringContainsString('window.SCHEDULES_ENABLED', $index);

        // Ouvrir la conversation éteint la pastille, par les deux chemins.
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString('Conversation::markRead($convId, (int)$user[\'id\']);', self::methode($chat, 'show'));
        $this->assertStringContainsString('Conversation::markRead($convId, (int)$user[\'id\']);', self::methode($chat, 'loadJson'));
        $this->assertStringContainsString('$scheduledBanner = ScheduledTask::bannerFor($conversation', self::methode($chat, 'loadJson'));
        $this->assertStringContainsString("'scheduled_task' => \$scheduledBanner,", self::methode($chat, 'loadJson'));
    }

    public function testLAdministrationActiveEtCoupeLaFonctionnalite(): void
    {
        $tools = self::src('templates/admin/tools.php');
        $this->assertStringContainsString('id="builtinSchedulesCard"', $tools);
        $this->assertStringContainsString('data-builtin-key="<?= htmlspecialchars(ScheduledTask::SETTING_ENABLED) ?>"', $tools);
        // Clé parmi celles que l'interrupteur des cartes intégrées a le droit d'écrire.
        $this->assertStringContainsString("        ScheduledTask::SETTING_ENABLED,\n", self::src('src/Controllers/AdminController.php'));
        require_once ROOT . '/src/Controllers/AdminController.php';
        $this->assertContains(ScheduledTask::SETTING_ENABLED, AdminController::BUILTIN_TOOL_KEYS);
        $this->assertStringContainsString('if (!ScheduledTask::isEnabled()) {', self::methode(self::src('src/Schedule/Scheduler.php'), 'tick'));
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        $this->assertStringContainsString('Tâches programmées', self::src('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('Tâches programmées', self::src('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('Tâches programmées', self::src('docs/user/fonctionnalites/conversations/programmer-une-tache.md'));
        $this->assertStringContainsString('programmer-une-tache.md', self::src('docs/user/fonctionnalites/conversations/index.md'));
        $this->assertStringContainsString('taches-programmees', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('081_scheduled_tasks.sql', self::src('CLAUDE.md'));
    }
}
