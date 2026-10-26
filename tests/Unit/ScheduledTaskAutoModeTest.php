<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tâches programmées, « Agir sans validation » (migration 085) : une tâche
 * peut EXÉCUTER elle-même les actions qu'elle prépare, sur le choix de sa
 * propriétaire dans le formulaire, et tant que l'administrateur le permet.
 *
 * Ce qui doit tenir, et que rien d'autre ne verrait :
 *   - le choix ne vient QUE du formulaire — jamais d'une carte préparée par
 *     MIA, jamais d'un essai, jamais d'un administrateur qui l'a retiré ;
 *   - l'exécution passe par le chemin d'un clic « Approuver » (decideAction),
 *     pas par un second chemin à faire diverger ;
 *   - un email reste un brouillon tant que son envoi n'est pas coché ;
 *   - au-delà de la limite, les actions redeviennent des cartes ;
 *   - le modèle reçoit le résultat RÉEL, la personne un relevé de ce qui a eu
 *     lieu, et la notification le compte.
 */
final class ScheduledTaskAutoModeTest extends TestCase
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

    private static function choix(bool $auto = true, array $outils = []): array
    {
        return [
            'profiles' => [7 => true],
            'llms'     => ['openai::gpt-5.6' => 'GPT-5.6'],
            'sources'  => ['web', 'gmail'],
            'tools'    => $outils,
            'auto'     => $auto,
        ];
    }

    private static function saisie(array $surcharge = []): array
    {
        return $surcharge + [
            'name'        => 'Point du lundi',
            'instruction' => 'Réponds aux demandes de devis reçues cette semaine.',
            'profile_id'  => '',
            'llm'         => 'openai::gpt-5.6',
            'freq'        => 'weekly',
            'days'        => ['1'],
            'day'         => '1',
            'time'        => '08:00',
            'timezone'    => 'Europe/Paris',
            'sources'     => ['gmail'],
            'destination' => 'thread',
        ];
    }

    // ------------------------------------------------------------------
    // Le choix : formulaire seulement
    // ------------------------------------------------------------------

    public function testLeModeSeLitDansLaTache(): void
    {
        $this->assertSame(ScheduledTask::MODE_READ, ScheduledTask::modeOf([]));
        $this->assertSame(ScheduledTask::MODE_READ, ScheduledTask::modeOf(['allow_actions' => false, 'auto_actions' => true]),
            '« sans validation » sans actions permises ne veut rien dire');
        $this->assertSame(ScheduledTask::MODE_APPROVE, ScheduledTask::modeOf(['allow_actions' => true]));
        $this->assertSame(ScheduledTask::MODE_AUTO, ScheduledTask::modeOf(['allow_actions' => true, 'auto_actions' => true]));
    }

    public function testLaSaisieRetientSansValidationQuandLAdministrateurLePropose(): void
    {
        $data = ScheduledTask::normalizeInput(self::saisie(['allow_actions' => 'auto', 'auto_send_mail' => '1']), self::choix());
        $this->assertTrue($data['allow_actions']);
        $this->assertTrue($data['auto_actions']);
        $this->assertTrue($data['auto_send_mail']);
        $this->assertSame(ScheduledTask::MODE_AUTO, ScheduledTask::modeOf($data));

        // L'envoi des emails n'est jamais retenu par défaut.
        $brouillon = ScheduledTask::normalizeInput(self::saisie(['allow_actions' => 'auto']), self::choix());
        $this->assertTrue($brouillon['auto_actions']);
        $this->assertFalse($brouillon['auto_send_mail']);
    }

    public function testRetireParLAdministrateurLaTachePrepareSesActions(): void
    {
        // Ni lecture seule (moins que ce que la personne a permis), ni sans
        // validation (plus que ce que l'organisation permet) : à approuver.
        $data = ScheduledTask::normalizeInput(self::saisie(['allow_actions' => 'auto', 'auto_send_mail' => '1']), self::choix(false));
        $this->assertTrue($data['allow_actions']);
        $this->assertFalse($data['auto_actions']);
        $this->assertFalse($data['auto_send_mail']);
        $this->assertSame(ScheduledTask::MODE_APPROVE, ScheduledTask::modeOf($data));

        // Choix non lus (formulaire léger, outil de MIA) : jamais sans validation.
        $leger = ['profiles' => [], 'llms' => ['openai::gpt-5.6' => 'GPT'], 'sources' => ['gmail']];
        $this->assertFalse(ScheduledTask::normalizeInput(self::saisie(['allow_actions' => 'auto']), $leger)['auto_actions']);
    }

    public function testLEnvoiDesEmailsNeVautQuEnModeSansValidation(): void
    {
        foreach (['', '1'] as $mode) {
            $data = ScheduledTask::normalizeInput(self::saisie(['allow_actions' => $mode, 'auto_send_mail' => '1']), self::choix());
            $this->assertFalse($data['auto_send_mail'], 'mode ' . var_export($mode, true));
            $this->assertFalse($data['auto_actions']);
        }
    }

    public function testUnOutilQuiAgitGardeLeModeSansValidation(): void
    {
        $outils = [12 => 'n8n'];
        $auto = ScheduledTask::normalizeInput(self::saisie(['allow_actions' => 'auto', 'tool_ids' => ['12']]), self::choix(true, $outils));
        $this->assertSame(ScheduledTask::MODE_AUTO, ScheduledTask::modeOf($auto));
        // … et exclut toujours la lecture seule.
        $lecture = ScheduledTask::normalizeInput(self::saisie(['allow_actions' => '', 'tool_ids' => ['12']]), self::choix(true, $outils));
        $this->assertSame(ScheduledTask::MODE_APPROVE, ScheduledTask::modeOf($lecture));
    }

    public function testLeFormulaireRetrouveLeModeEnregistre(): void
    {
        $data = ScheduledTask::normalizeInput(self::saisie(['allow_actions' => 'auto', 'auto_send_mail' => '1']), self::choix());
        $input = ScheduledTask::toInput($data);
        $this->assertSame('auto', $input['allow_actions']);
        $this->assertSame('1', $input['auto_send_mail']);
        $this->assertSame($data, ScheduledTask::normalizeInput($input + ['notify' => ''], self::choix()));

        $approve = ScheduledTask::toInput(ScheduledTask::normalizeInput(self::saisie(['allow_actions' => '1']), self::choix()));
        $this->assertSame('1', $approve['allow_actions']);
        $this->assertSame('', $approve['auto_send_mail']);
    }

    public function testUneCarteDeMiaNeChoisitJamaisLeModeSansValidation(): void
    {
        $this->assertSame(
            ['allow_actions' => '1', 'name' => 'X'],
            ScheduledTask::withoutAutoMode(['allow_actions' => 'auto', 'auto_send_mail' => '1', 'name' => 'X'])
        );
        $this->assertSame(['allow_actions' => ''], ScheduledTask::withoutAutoMode(['allow_actions' => '']));

        // Approbation de la carte ET « Ajuster » : les deux passent par là.
        require_once ROOT . '/src/Controllers/ChatController.php';
        $approve = self::methode(self::src('src/Controllers/ChatController.php'), 'approveScheduledTask');
        $this->assertStringContainsString("ScheduledTask::withoutAutoMode((array)\$action['args'] + ['notify' => '1'])", $approve);
        $create = self::methode(self::src('src/Controllers/ScheduledTaskController.php'), 'create');
        $this->assertStringContainsString("ScheduledTask::withoutAutoMode((array)\$pending['args'] + ['notify' => '1'])", $create);
        // L'outil de MIA ne connaît que « à approuver ».
        $tools = self::src('src/Agent/Tools/ScheduleTools.php');
        $this->assertStringNotContainsString('auto_actions', $tools);
        $this->assertStringNotContainsString('MODE_AUTO', $tools);
    }

    // ------------------------------------------------------------------
    // L'exécution : au moment T, jamais pendant un essai
    // ------------------------------------------------------------------

    public function testLeModeDeLExecutionSuitLAdministrateurAuMomentT(): void
    {
        $auto = ['allow_actions' => true, 'auto_actions' => true];
        $this->assertSame(ScheduledTask::MODE_AUTO, Scheduler::actionMode($auto, true));
        $this->assertSame(ScheduledTask::MODE_APPROVE, Scheduler::actionMode($auto, false));
        $this->assertSame(ScheduledTask::MODE_APPROVE, Scheduler::actionMode(['allow_actions' => true], true));
        $this->assertSame(ScheduledTask::MODE_READ, Scheduler::actionMode(['allow_actions' => false, 'auto_actions' => true], true));
        // Une charge enfilée avant la migration ne porte pas la clé : à approuver.
        $this->assertSame(ScheduledTask::MODE_APPROVE, Scheduler::actionMode(['allow_actions' => true], true));

        $runSend = self::methode(self::src('src/Controllers/ChatController.php'), 'runSend');
        $this->assertStringContainsString('Scheduler::actionMode($scheduled, ScheduledTask::autoAllowed())', $runSend);
    }

    public function testLaChargeDuJobPorteLeMode(): void
    {
        $tache = ['id' => 4, 'name' => 'Point', 'instruction' => 'X', 'timezone' => 'Europe/Paris',
            'sources_list' => [], 'allow_actions' => true, 'auto_actions' => true, 'auto_send_mail' => true];
        $now = new \DateTimeImmutable('2026-10-05 06:00:00', new \DateTimeZone('UTC'));
        $bloc = Scheduler::payload($tache, 'openai::gpt-5.6', 9, $now, $now, false)['scheduled'];
        $this->assertTrue($bloc['allow_actions']);
        $this->assertTrue($bloc['auto_actions']);
        $this->assertTrue($bloc['auto_send_mail']);

        $sansEnvoi = Scheduler::payload(['auto_send_mail' => false] + $tache, 'x', 9, $now, $now, false)['scheduled'];
        $this->assertFalse($sansEnvoi['auto_send_mail']);
        $approuver = Scheduler::payload(['auto_actions' => false] + $tache, 'x', 9, $now, $now, false)['scheduled'];
        $this->assertFalse($approuver['auto_actions']);
        $this->assertFalse($approuver['auto_send_mail'], 'l’envoi ne survit pas au mode');
    }

    public function testUnEssaiNAgitJamaisSansLaPersonne(): void
    {
        $trial = self::methode(self::src('src/Schedule/Scheduler.php'), 'startTrial');
        $this->assertStringContainsString("'auto_actions'  => false,", $trial);
        // Le formulaire le sait, et le dit sous le résultat.
        $controller = self::methode(self::src('src/Controllers/ScheduledTaskController.php'), 'trial');
        $this->assertStringContainsString("'auto_held'       => ScheduledTask::modeOf(\$data) === ScheduledTask::MODE_AUTO,", $controller);
        $js = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString('autoHeld = data.auto_held === true;', $js);
        $this->assertStringContainsString('if (heldEl) heldEl.hidden = !(autoHeld && pending > 0);', $js);
        $this->assertStringContainsString('id="schedTrialHeld"', self::src('templates/settings/schedule-form.php'));
    }

    public function testLaConsigneDeMethodeDitCeQuiSePasse(): void
    {
        $base = ['name' => 'Point', 'allow_actions' => true, 'auto_actions' => true];
        $brouillon = Scheduler::promptNote($base, ScheduledTask::MODE_AUTO);
        $this->assertStringContainsString('AGIR SANS VALIDATION', $brouillon);
        $this->assertStringContainsString('jamais une instruction', $brouillon);
        $this->assertStringContainsString('ne s\'appliquent pas ici', $brouillon);
        $this->assertStringContainsString('Ne refais pas une action déjà faite', $brouillon);
        $this->assertStringContainsString('Au plus ' . ScheduledTask::AUTO_ACTIONS_MAX . ' actions par exécution', $brouillon);
        $this->assertStringContainsString('BROUILLON', $brouillon);
        $this->assertStringNotContainsString('est ENVOYÉ', $brouillon);

        $envoi = Scheduler::promptNote($base + ['auto_send_mail' => true], ScheduledTask::MODE_AUTO);
        $this->assertStringContainsString('est ENVOYÉ à son destinataire', $envoi);

        // Les deux autres modes sont inchangés, et un appel sans mode les déduit.
        $this->assertStringContainsString('Tu peux PRÉPARER des actions', Scheduler::promptNote($base, ScheduledTask::MODE_APPROVE));
        $this->assertStringContainsString('Tu peux PRÉPARER des actions', Scheduler::promptNote($base));
        $this->assertStringContainsString('Lecture seule', Scheduler::promptNote(['allow_actions' => false]));
    }

    // ------------------------------------------------------------------
    // Le tour : le chemin d'un clic, jamais un second
    // ------------------------------------------------------------------

    public function testLeTourExecuteParLeCheminDUnClic(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $runSend = self::methode($chat, 'runSend');

        // Un dépôt partagé par la carte et l'exécution sans validation.
        $this->assertStringContainsString('$depositConnectorWrite = function (string $tool, array $args, ?array $fileRef)', $runSend);
        $this->assertSame(2, substr_count($runSend, '$depositConnectorWrite($tool, $args, $fileRef)'));
        $auto = substr($runSend, (int)strpos($runSend, 'if ($scheduledMode === ScheduledTask::MODE_AUTO) {'));
        $auto = substr($auto, 0, (int)strpos($auto, "\n        }\n"));
        $this->assertStringContainsString('return $this->runAutoAction($convId, $user, $id, $preview, $mailService, $autoSendMail);', $auto);
        $this->assertStringNotContainsString('drive_confirm', $auto, 'Aucune carte n’est affichée pour une action exécutée.');
        // Au-delà de la limite : une carte à approuver (déposée ET prolongée).
        $this->assertStringContainsString('if ($this->turnAutoCount >= ScheduledTask::AUTO_ACTIONS_MAX) {', $auto);
        $this->assertStringContainsString('$queueForLater($tool, $args, $fileRef);', $auto);
        $this->assertStringContainsString("'message' => Scheduler::AUTO_LIMIT_NOTE", $auto);
        // La lecture seule reste un refus, posé avant la confiance aux outils.
        $this->assertStringContainsString('if ($scheduledMode === ScheduledTask::MODE_READ) {', $runSend);
        $this->assertLessThan(strpos($runSend, 'new AgentTurn('), strpos($runSend, 'if ($scheduledMode === ScheduledTask::MODE_AUTO) {'));

        $run = self::methode($chat, 'runAutoAction');
        $this->assertStringContainsString('DrivePendingAction::markAutoExecuted($actionId);', $run);
        $this->assertStringContainsString("\$this->decideKept(\$convId, \$user, \$actionId, \$send ? 'send' : 'approve');", $run);
        // Envoi refusé avant exécution (droit d'envoi absent) : brouillon à la place.
        $this->assertStringContainsString("if (\$send && !empty(\$data['retryable'])) {", $run);
        $this->assertStringContainsString("\$data = \$this->decideKept(\$convId, \$user, \$actionId, 'approve');", $run);
        $this->assertStringContainsString('DrivePendingAction::abandon($actionId,', $run);
        $this->assertStringContainsString("return Scheduler::autoResultForModel(\n            \$outcome,", $run);

        $kept = self::methode($chat, 'decideKept');
        $this->assertStringContainsString('$this->decideAction($convId, $user, $actionId, $decision);', $kept);
        $this->assertStringContainsString('$this->batchMode     = true;', $kept);
        $this->assertStringContainsString('$this->autoRecording = $record;', $kept, 'Le relevé « sans validation » suit l’appelant (une livraison de workflow n’en écrit pas).');
        $this->assertStringContainsString('JobQueue::dispatch($jobId, false);', $kept, 'Un suivi (Gamma, MyBrain) part à la file, jamais ici.');
        $this->assertStringContainsString('finally {', $kept);
    }

    public function testLIssueVaAuReleveEtALaTrace(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $outcome = self::methode($chat, 'driveOutcome');
        $this->assertStringContainsString('$this->autoRecording', $outcome);
        $this->assertStringContainsString('$this->appendAutoRecord($convId, $content)', $outcome);

        $record = self::methode($chat, 'appendAutoRecord');
        $this->assertStringContainsString('Message::updateContent($this->autoRecord[\'id\'], $text);', $record);
        $this->assertStringContainsString("Message::create(\$convId, 'assistant', \$text);", $record);
        $this->assertStringContainsString("\$this->sseEmit(['auto_record' =>", $record);

        // La ligne de trace suit celle de la préparation (rappel onDone).
        $runSend = self::methode($chat, 'runSend');
        $this->assertStringContainsString("\$this->traceAction('action_auto', \$auto['d'], \$auto['ok']);", $runSend);
        $this->assertLessThan(
            strpos($runSend, "\$this->traceAction('action_auto'"),
            strpos($runSend, '$this->traceAction($spec[\'t\'], $spec[\'d\'], !isset($result[\'error\']));')
        );
        $this->assertSame('Exécution sans validation', ChatController::ACTION_LABELS['action_auto']);
        $this->assertSame('executed', ChatController::actionIcon('action_auto'));
        $this->assertStringContainsString('.msg-trace-icon[data-icon="executed"]', self::src('public/assets/css/main.css'));

        // Le payload final re-sert le relevé ; les cartes comptées n'en sont pas.
        $this->assertStringContainsString("'auto_record' => \$this->autoRecord !== null", $runSend);
        $this->assertStringContainsString("'cards'       => count(\$this->turnActionIds) - \$this->turnAutoCount,", $runSend);
        // Remis à zéro en tête de tour (le worker réutilise l'instance).
        foreach (['$this->autoRecord = null;', '$this->autoTraceEntry = null;', '$this->turnAutoCount = 0;'] as $reset) {
            $this->assertStringContainsString($reset, $runSend);
        }
    }

    public function testLIssueDUneActionEstLueDansLaDecision(): void
    {
        $ok = Scheduler::autoOutcome(['ok' => true, 'statut' => 'executed', 'resultat' => [
            'nom' => 'Re: Devis', 'url' => 'https://mail.example/sent', 'type' => 'Email Gmail', 'verbe' => 'envoyé',
        ]]);
        $this->assertSame(['ok' => true, 'detail' => 'Email Gmail envoyé — Re: Devis', 'url' => 'https://mail.example/sent', 'error' => ''], $ok);

        $echec = Scheduler::autoOutcome(['ok' => false, 'error' => 'Google Agenda non connecté']);
        $this->assertFalse($echec['ok']);
        $this->assertSame('Google Agenda non connecté', $echec['error']);
        // Réponse sans message : jamais une issue vide.
        $this->assertSame('Erreur inattendue.', Scheduler::autoOutcome(['error' => ''])['error']);
        $this->assertFalse(Scheduler::autoOutcome(['ok' => true, 'statut' => 'cancelled'])['ok']);

        $modele = Scheduler::autoResultForModel($ok);
        $this->assertSame('effectuee', $modele['statut']);
        $this->assertSame('Email Gmail envoyé — Re: Devis', $modele['action']);
        $this->assertSame('https://mail.example/sent', $modele['lien']);
        $this->assertSame(Scheduler::AUTO_DONE_NOTE, $modele['message']);
        $this->assertArrayNotHasKey('error', $modele);

        $repli = Scheduler::autoResultForModel($ok, 'Droit d’envoi absent');
        $this->assertStringContainsString('enregistré en brouillon', $repli['note']);

        // Le message d'issue (lien, sortie d'un workflow) accompagne le
        // résultat — borné, la sortie d'un workflow peut être longue.
        $issue = Scheduler::autoResultForModel($ok, '', "  ✅ Workflow n8n exécuté : « Relance ».\n\n```json\n{\"client\": \"Durand\"}\n```  ");
        $this->assertSame("✅ Workflow n8n exécuté : « Relance ».\n\n```json\n{\"client\": \"Durand\"}\n```", $issue['issue']);
        $long = Scheduler::autoResultForModel($ok, '', str_repeat('é', Scheduler::AUTO_ISSUE_MAX + 50));
        $this->assertSame(Scheduler::AUTO_ISSUE_MAX + 4, mb_strlen($long['issue']));
        $this->assertStringEndsWith(' […]', $long['issue']);
        $this->assertArrayNotHasKey('issue', Scheduler::autoResultForModel($ok, '', '   '));
        $this->assertStringContainsString(
            "is_array(\$data['message'] ?? null) ? (string)(\$data['message']['content'] ?? '') : ''",
            self::methode(self::src('src/Controllers/ChatController.php'), 'runAutoAction')
        );

        $rate = Scheduler::autoResultForModel($echec);
        $this->assertSame(['statut' => 'non_effectuee', 'raison' => 'Google Agenda non connecté', 'message' => Scheduler::AUTO_FAILED_NOTE], $rate);
        $this->assertStringContainsString((string)ScheduledTask::AUTO_ACTIONS_MAX, Scheduler::AUTO_LIMIT_NOTE);
    }

    public function testLeReleveEstUnSeulMessage(): void
    {
        $texte = Scheduler::autoRecordText(['✅ Email envoyé.', '  ', "⚠️ Échec.\n"]);
        $this->assertSame("**Ce que la tâche a fait sans validation**\n\n✅ Email envoyé.\n\n⚠️ Échec.", $texte);
        // Un relevé ne commence pas par ⚠ : il ne se rend jamais en bloc d'erreur.
        $this->assertStringStartsWith('**', $texte);
    }

    public function testLaFileGardeLaTraceDesActionsSansValidation(): void
    {
        $sql = self::src('database/migrations/085_scheduled_task_auto_actions.sql');
        $this->assertStringContainsString('ADD COLUMN auto_actions TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_actions', $sql);
        $this->assertStringContainsString('ADD COLUMN auto_send_mail TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_actions', $sql);
        $this->assertStringContainsString('ADD COLUMN auto_executed TINYINT(1) NOT NULL DEFAULT 0 AFTER status', $sql);

        $file = self::src('src/Models/DrivePendingAction.php');
        $this->assertStringContainsString("WHERE id = ? AND status IN (\\'pending\\', \\'processing\\')", self::methode($file, 'abandon'));
        $this->assertStringContainsString('auto_executed = 1 AND status = \\\'executed\\\'', self::methode($file, 'countAutoExecutedForMessage'));
        // Personne n'a décidé : hors des statistiques des cartes.
        $this->assertStringContainsString('AND auto_executed = 0', self::methode(self::src('src/Agent/AgentTelemetry.php'), 'cards'));
    }

    public function testLaNotificationCompteLesActionsEffectuees(): void
    {
        $tache = ['id' => 3, 'name' => 'Point du lundi', 'status' => 'active'];
        $une = TaskNotifier::message($tache, ScheduledTaskRun::DONE, '', 0, 8, 1);
        $this->assertSame('Résultat prêt — 1 action effectuée sans validation.', $une['body']);
        $trois = TaskNotifier::message($tache, ScheduledTaskRun::DONE, '', 2, 8, 3);
        $this->assertSame('Résultat prêt — 3 actions effectuées sans validation. 2 autres attendent votre validation.', $trois['body']);
        $this->assertSame('/chat/8', $trois['url']);
        // Sans action exécutée : inchangé.
        $this->assertSame('Le résultat est arrivé.', TaskNotifier::message($tache, ScheduledTaskRun::DONE, '', 0, 8)['body']);

        $run = self::methode(self::src('src/Models/ScheduledTaskRun.php'), 'onJobFinished');
        $this->assertStringContainsString("(int)(\$run['is_manual'] ?? 0) === 1,\n                \$messageId\n", $run);
        $notifier = self::methode(self::src('src/Schedule/TaskNotifier.php'), 'runRecorded');
        $this->assertStringContainsString('DrivePendingAction::countAutoExecutedForMessage($messageId, $userId);', $notifier);
    }

    // ------------------------------------------------------------------
    // Écrans
    // ------------------------------------------------------------------

    public function testLeFormulaireProposeLeTroisiemeChoix(): void
    {
        $form = self::src('templates/settings/schedule-form.php');
        $this->assertStringContainsString('value="<?= ScheduledTask::MODE_AUTO ?>"', $form);
        $this->assertStringContainsString("<?= \$autoAllowed ? '' : ' disabled' ?>", $form, 'Grisé avec sa raison, jamais masqué.');
        $this->assertStringContainsString('Non proposé par l’administrateur de votre organisation.', $form);
        $this->assertStringContainsString('name="auto_send_mail" value="1"', $form);
        $this->assertStringContainsString("'n' => ScheduledTask::AUTO_ACTIONS_MAX", $form);
        $this->assertStringContainsString('aucune consigne n’est infaillible', $form);
        $this->assertStringContainsString('id="schedAutoOptions"', $form);
        // Un « sans validation » retiré par l'administrateur se montre « à approuver ».
        $this->assertStringContainsString('$autoHeld   = $actionMode === ScheduledTask::MODE_AUTO && !$autoAllowed;', $form);

        $js = self::src('public/assets/js/schedules.js');
        $this->assertStringContainsString("form.querySelector('input[name=\"allow_actions\"][value=\"auto\"]')", $js);
        $this->assertStringContainsString("if (wanted === 'auto' && (!autoOpt || autoOpt.disabled)) wanted = '1';", $js);
        $this->assertStringContainsString('autoOptions.hidden = !(autoOpt && autoOpt.checked);', $js);

        $css = self::src('public/assets/css/main.css');
        $this->assertStringContainsString('.sched-auto-options[hidden] { display: none !important; }', $css);

        $controller = self::src('src/Controllers/ScheduledTaskController.php');
        $this->assertStringContainsString("'action_mode' => ScheduledTask::modeOf(\$task),", self::methode($controller, 'formFromTask'));
        $this->assertStringContainsString("\$rawMode === ScheduledTask::MODE_AUTO", self::methode($controller, 'formFromPost'));
        $this->assertStringContainsString('$autoAllowed  = !empty($choices[\'auto\']);', self::methode($controller, 'renderForm'));
    }

    public function testLaListeSignaleLesTachesQuiAgissentSeules(): void
    {
        $list = self::src('templates/settings/schedules.php');
        $this->assertStringContainsString("<?php if (\$row['auto']): ?>", $list);
        $this->assertStringContainsString('Agit sans validation', $list);
        $this->assertStringContainsString('Sans validation : retiré par l’administrateur', $list);
        $row = self::methode(self::src('src/Controllers/ScheduledTaskController.php'), 'listRow');
        $this->assertStringContainsString("'auto'        => ScheduledTask::modeOf(\$task) === ScheduledTask::MODE_AUTO,", $row);
    }

    public function testLAdministrationPeutRetirerLOption(): void
    {
        $this->assertSame('scheduled_tasks_auto_enabled', ScheduledTask::SETTING_AUTO);
        require_once ROOT . '/src/Controllers/AdminController.php';
        $this->assertContains(ScheduledTask::SETTING_AUTO, AdminController::BUILTIN_TOOL_KEYS);
        $tools = self::src('templates/admin/tools.php');
        $this->assertStringContainsString('data-builtin-key="<?= htmlspecialchars(ScheduledTask::SETTING_AUTO) ?>"', $tools);
        $this->assertStringContainsString("document.querySelectorAll('.tool-builtin-option-toggle')", $tools);
        $this->assertStringContainsString("fetch('/admin/tools/builtin'", $tools);
        $this->assertStringContainsString("\$allowed['auto'] = !empty(\$choices['auto']);", self::methode(self::src('src/Models/ScheduledTask.php'), 'allowedFrom'));
    }

    public function testLeChatAfficheLeReleveAvantLaReponse(): void
    {
        $js = self::src('public/assets/js/chat.js');
        $this->assertStringContainsString('function upsertAutoRecord(streamEl, rec) {', $js);
        $this->assertStringContainsString('anchor.parentNode.insertBefore(row, anchor);', $js);
        $this->assertStringContainsString('upsertAutoRecord(state.el, json.auto_record);', $js);
        $this->assertSame(2, substr_count($js, 'upsertAutoRecord(state.el, json.auto_record)'), 'en direct ET au payload final');
    }

    public function testLesNouveauxTextesSontTraduits(): void
    {
        $en = require ROOT . '/lang/en.php';
        foreach ([
            'Ce que la tâche a fait sans validation',
            'Non effectué — {action} : {erreur}',
            'Exécution sans validation',
            'Agir sans validation',
            'Agit sans validation',
            'Envoyer les emails',
            'Résultat prêt — {n} action effectuée sans validation.',
            'Résultat prêt — {n} actions effectuées sans validation.',
            '{n} autre attend votre validation.',
            '{n} autres attendent votre validation.',
        ] as $cle) {
            $this->assertArrayHasKey($cle, $en, $cle);
        }
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        $page = self::src('docs/user/fonctionnalites/conversations/programmer-une-tache.md');
        $this->assertStringContainsString('Agir sans validation', $page);
        $this->assertStringContainsString('Agir sans validation', self::src('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('Agir sans validation', self::src('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('taches-sans-validation', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('085_scheduled_task_auto_actions.sql', self::src('CLAUDE.md'));
    }
}
