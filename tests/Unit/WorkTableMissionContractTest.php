<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage du lot 2 des tables de travail (enrichir, agir, opération de
 * fond) : ce qu'aucun test d'outil ne voit — le chargement des classes, le
 * job et sa place dans la file, la réserve des actions dans le tour de chat,
 * l'accord, l'écran, la facturation, et les limites annoncées.
 */
final class WorkTableMissionContractTest extends TestCase
{
    private static function read(string $path): string
    {
        $content = @file_get_contents(ROOT . '/' . ltrim($path, '/'));
        self::assertIsString($content, $path . ' illisible');
        return $content;
    }

    public function testLesClassesSontChargeesEtLeJobConnu(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $list) {
            $src = self::read($list);
            foreach (['src/Tables/WorkTableEnricher.php', 'src/Tables/WorkTableActions.php'] as $file) {
                $this->assertStringContainsString("'/" . $file . "'", $src, $file . ' absent de ' . $list . ' (pas d’autoloader).');
            }
        }
        $classes = self::read('config/classes.php');
        $job = strpos($classes, "'/src/Jobs/TableMissionJob.php'");
        $this->assertNotFalse($job, 'Le worker ne trouverait pas le job.');
        $this->assertLessThan($job, strpos($classes, "'/src/Jobs/ChatJob.php'"), 'Le socle avant le job qui l’étend.');
        $this->assertLessThan(strpos($classes, "'/src/Jobs/JobQueue.php'"), $job);

        $queue = self::read('src/Jobs/JobQueue.php');
        $this->assertStringContainsString("'table_mission' => new TableMissionJob(),", $queue);
        $consts = (new ReflectionClass(JobQueue::class))->getConstants();
        $this->assertNotContains('table_mission', $consts['NON_IDEMPOTENT'], 'Reprenable : chaque lot, chaque action est un point de contrôle.');
        $this->assertNotContains('table_mission', $consts['QUOTA_EXEMPT'], 'Un enrichissement consomme des crédits : le quota s’applique.');
        $this->assertNull(JobQueue::laneFor('table_mission', 12), 'Hors du couloir de la conversation : elle reste utilisable.');
        $this->assertSame(JobQueue::PRIORITY_INTERACTIVE, JobQueue::priorityFor('table_mission'));

        $jobSrc = self::read('src/Jobs/TableMissionJob.php');
        $this->assertStringContainsString('class TableMissionJob extends ChatJob', $jobSrc);
        $this->assertStringContainsString('$chat->decideForTable($convId, $user, $actionId)', $jobSrc, 'Une action passe par le chemin d’un clic, jamais par un appel direct au service.');
        $this->assertStringContainsString('$ctx->shouldStop()', $jobSrc);
        $this->assertStringContainsString('CreditQuota::isExceeded(CreditQuota::status($user))', $jobSrc, 'Le quota et le budget sont relus pendant l’opération.');
        $this->assertStringContainsString('DrivePendingAction::cancelHeld(', $jobSrc, 'Un arrêt ne laisse rien en réserve.');
    }

    public function testLeTourDeChatTientLesActionsEnReserveSansCarte(): void
    {
        $src = self::read('src/Controllers/ChatController.php');
        // La réserve est décidée DANS la file d'approbation, avant l'émission de la carte.
        $held = strpos($src, 'if ($this->heldAction !== null) {');
        $card = strpos($src, "\$this->sseEmit(['drive_confirm' => ['id' => \$id] + \$preview]);");
        $this->assertNotFalse($held);
        $this->assertNotFalse($card);
        $this->assertLessThan($card, $held, 'Une action tenue en réserve n’émet pas de carte.');
        $this->assertStringContainsString('DrivePendingAction::hold($id, $userId);', $src);
        $this->assertStringContainsString('array_pop($this->turnActionIds);', $src, 'Elle n’est la carte d’aucune réponse.');
        // Déclinée par le handler de l'outil visé, liste fermée, jamais dans un tour sans personne.
        $this->assertStringContainsString('$tableActions = $unattended ? [] : [', $src);
        $this->assertStringContainsString('WorkTableActions::isAllowed($tool) ? $registry->handler($tool) : null', $src);
        $this->assertMatchesRegularExpression('/\$this->heldAction = \[\];\s*try \{\s*\$result = \$handler\(\$args\);\s*\} finally \{\s*\$held = \$this->heldAction;\s*\$this->heldAction = null;/', $src,
            'La réserve est levée même si le handler lève.');
        // Le fournisseur reçoit le modèle du tour et la file d'actions — sans enrichissement
        // dans une étape de workflow (son opération de fond tournerait après l'étape).
        $this->assertMatchesRegularExpression('/\$sheetAttachments,\s*\/\/[^\n]*\n\s*\$workflowStep !== null \? \'\' : \(string\)\$llm,\s*\$tableActions,/', $src);
        // Aperçus calculés par le fournisseur.
        $this->assertStringContainsString('in_array($tool, [WorkTableTools::ENRICH, WorkTableTools::ACT, WorkTableTools::PERM_WRITE], true)', $src);
        // Trace.
        $this->assertSame('Enrichissement d\'une table', ChatControllerLabels::get('table_enrich'));
        $this->assertSame('Préparation d\'actions sur une table', ChatControllerLabels::get('table_act'));
    }

    public function testLAccordEtLExecutionPassentParLaDecisionDUnClic(): void
    {
        $src = self::read('src/Controllers/ChatController.php');
        $start = strpos($src, 'public function decideForTable(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, (int)strpos($src, 'private function approveTableMission(') - $start);
        $this->assertStringContainsString('DrivePendingAction::releaseHeld($actionId, (int)$user[\'id\'], $convId)', $body);
        $this->assertStringContainsString("\$this->decideKept(\$convId, \$user, \$actionId, 'approve', false)", $body);
        $this->assertStringNotContainsString("'send'", $body, 'Un email d’une action groupée reste un BROUILLON.');
        $this->assertMatchesRegularExpression('/\$this->silentOutcome = true;\s*try \{.*\} finally \{\s*\$this->silentOutcome = false;/s', $body, 'Aucun message par ligne dans le fil.');

        $approve = substr($src, (int)strpos($src, 'private function approveTableMission('), 5200);
        $this->assertStringContainsString("JobQueue::activeByDedupKey(\$dedup) !== null", $approve, 'Une seule opération à la fois par conversation.');
        $this->assertStringContainsString('DrivePendingAction::release($actionId);', $approve, 'La carte attend : elle n’est pas consommée.');
        $this->assertStringContainsString("JobQueue::enqueue('table_mission'", $approve);
        $this->assertStringContainsString("'job_type' => 'table_mission'", $approve);
        $this->assertStringContainsString('findById($convId, (int)($args[\'table_id\'] ?? 0))', $approve, 'La table se cherche DANS la conversation.');

        // Enrichir n'écrit chez personne ; agir écrit par un connecteur : le droit « Connecteurs — Utiliser » s'applique.
        $list = substr($src, (int)strpos($src, 'private const NON_CONNECTOR_ACTION_TOOLS = ['), 500);
        $this->assertStringContainsString('WorkTableTools::ENRICH', $list);
        $this->assertStringNotContainsString('WorkTableTools::ACT', $list);
        // Refuser le groupe écarte ses actions.
        $this->assertMatchesRegularExpression("/if \(\(string\)\\\$action\['tool'\] === WorkTableTools::ACT\) \{\s*DrivePendingAction::cancelHeld\(/", $src);

        // La réserve est invisible : toutes les lectures de la file exigent « pending ».
        $model = self::read('src/Models/DrivePendingAction.php');
        $this->assertStringContainsString("SET status = \\'held\\', auto_executed = 1", $model);
        foreach (['claim', 'findPending', 'pendingForConversation'] as $fn) {
            $at = strpos($model, 'public static function ' . $fn . '(');
            $this->assertStringContainsString("status = \\'pending\\'", substr($model, (int)$at, 900), $fn . ' ne doit rendre que des actions en attente.');
        }
    }

    public function testLEcranSuitLOperationEtLaFacturationLaCompte(): void
    {
        $chat = self::read('public/assets/js/chat.js');
        $this->assertStringContainsString('JOB_TYPES.table_mission = {', $chat);
        $this->assertStringContainsString("table_mission: t('Opération sur une table')", $chat, 'Le tiroir des générations nomme l’opération (et permet de l’arrêter).');
        $this->assertStringContainsString('if (json.mission && jobIsVisible(state)) paintMissionProgress(json.mission);', $chat);
        $this->assertStringContainsString("content.insertAdjacentElement('afterend', line);", $chat, 'L’avancement vit hors du contenu du message : ni copié, ni exporté.');
        foreach (['table_enrich:', 'table_act:'] as $phase) {
            $this->assertStringContainsString($phase, $chat);
        }
        $this->assertStringContainsString('.mission-progress {', self::read('public/assets/css/main.css'));
        $this->assertFileExists(ROOT . '/public/assets/img/table.svg');

        // Source d'usage : dans le quota, rattachée à la réponse quand l'enrichissement se fait pendant elle.
        $this->assertTrue(UsageSource::isKnown(UsageSource::TABLE_ENRICH));
        $this->assertFalse(UsageSource::isQuotaExempt(UsageSource::TABLE_ENRICH));
        $this->assertSame(UsageSource::CAT_CHAT_ASSIST, UsageSource::category(UsageSource::TABLE_ENRICH));
        $this->assertContains(UsageSource::TABLE_ENRICH, UsageSource::TURN_HELPERS);
        $enricher = self::read('src/Tables/WorkTableEnricher.php');
        $this->assertStringContainsString('UsageMeter::llm($llmKey, is_array($tokens) ? $tokens : [], UsageSource::TABLE_ENRICH, $billing)', $enricher, 'Un seul chemin d’écriture au grand livre.');
        $this->assertStringContainsString("['message_id' => \$statusMsg]", self::read('src/Jobs/TableMissionJob.php'), 'Une opération de fond n’est jamais comptée avec la réponse d’un autre tour.');
    }

    public function testLesLimitesAnnonceesSontCellesDuCode(): void
    {
        $this->assertSame(45, WorkTableEnricher::INLINE_ROWS);
        $this->assertSame(200, WorkTableActions::ROWS_MAX);
        $this->assertSame(6, WorkTableEnricher::MAX_NEW_COLUMNS);

        $page  = self::read('docs/user/fonctionnalites/connecteurs/traiter-chaque-ligne.md');
        $guide = self::read('docs/GUIDE-UTILISATEUR.md');
        foreach ([$page, $guide] as $doc) {
            $doc = (string)preg_replace('/\s+/u', ' ', $doc);
            $this->assertStringContainsString('45 lignes', $doc);
            $this->assertStringContainsString('200 lignes', $doc);
            $this->assertMatchesRegularExpression('/[Ss]ix colonnes/u', $doc);
            $this->assertStringContainsString('Enrichissement d\'une table', $doc, 'Le libellé de la trace cité est celui que l’écran affiche.');
            $this->assertMatchesRegularExpression('/[Aa]ucun email n\'est envoyé/u', $doc);
        }
        $index = self::read('docs/user/fonctionnalites/connecteurs/index.md');
        $this->assertStringContainsString('(traiter-chaque-ligne.md)', $index, 'Une page nouvelle est annoncée dans la vue d’ensemble.');
        $this->assertStringContainsString('(traiter-chaque-ligne.md)', self::read('docs/user/fonctionnalites/connecteurs/recouper-des-sources.md'));
        $this->assertStringContainsString('une seule carte', self::read('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('enrichissement de tables de travail', self::read('docs/GUIDE-ADMIN.md'));
        // Aucun lien d'une page utilisateur vers l'administration.
        $this->assertStringNotContainsString('/admin/', $page);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLeGuideInterneEnParle(): void
    {
        $claude = self::read('CLAUDE.md');
        $this->assertStringContainsString('Lot 2 — `enrichir_table`', $claude);
        $this->assertStringContainsString('Lot 2 — `agir_sur_table`', $claude);
    }
}

/** Lecture des libellés de trace sans charger tout le contrôleur dans chaque test. */
final class ChatControllerLabels
{
    public static function get(string $type): string
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        return (string)(ChatController::ACTION_LABELS[$type] ?? '');
    }
}
