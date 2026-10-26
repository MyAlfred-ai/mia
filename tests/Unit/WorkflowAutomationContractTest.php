<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Workflows, second lot : formes pures (pièces jointes, adresse d'appel,
 * requêtes de surveillance, textes de livraison) et câblage (routes,
 * chargement, ordre de l'adresse d'appel avant la session et le CSRF).
 */
final class WorkflowAutomationContractTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    // ------------------------------------------------------------------
    // Pièces jointes d'un email
    // ------------------------------------------------------------------

    public function testLesPiecesJointesSontNettoyeesEtBornees(): void
    {
        $out = OutgoingAttachments::normalize([
            ['name' => '../../etc/pass"wd', 'mime' => 'TEXT/PLAIN', 'bytes' => 'x'],
            ['name' => "a\x01b.pdf", 'mime' => 'pas un type', 'bytes' => 'y'],
            ['name' => 'vide.txt', 'mime' => 'text/plain', 'bytes' => ''],
        ]);
        $this->assertSame([
            ['name' => 'passwd', 'mime' => 'text/plain', 'bytes' => 'x'],
            ['name' => 'ab.pdf', 'mime' => 'application/octet-stream', 'bytes' => 'y'],
        ], $out, 'Ni chemin, ni guillemet, ni caractère de contrôle ; un fichier vide ne part pas.');

        try {
            OutgoingAttachments::normalize(array_fill(0, OutgoingAttachments::MAX_COUNT + 1, ['name' => 'a', 'mime' => 'text/plain', 'bytes' => 'z']));
            $this->fail('Trop de pièces jointes.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string)OutgoingAttachments::MAX_COUNT, $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        OutgoingAttachments::normalize([['name' => 'gros', 'mime' => 'application/pdf', 'bytes' => str_repeat('a', OutgoingAttachments::MAX_BYTES + 1)]]);
    }

    public function testUnBrouillonGmailPorteSesPiecesJointes(): void
    {
        $mime = UserGmailService::buildDraftMime('a@exemple.fr', 'Objet', "Bonjour,\n\nVoici.", '', [], '', [
            ['name' => 'Synthèse finale.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'bytes' => 'DOCX-OCTETS'],
        ]);
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/mixed; boundary="([^"]+)"/m', $mime);
        preg_match('/multipart\/mixed; boundary="([^"]+)"/', $mime, $mixed);
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/alternative; boundary="[^"]+"/m', $mime, 'Le texte et le HTML restent une alternative.');
        $this->assertStringContainsString("filename*=UTF-8''Synth%C3%A8se%20finale.docx", $mime, 'Le nom accentué voyage en RFC 2231.');
        $this->assertStringContainsString('filename="Synth_se finale.docx"', $mime, 'Repli ASCII pour les vieux clients.');
        $this->assertStringContainsString(base64_encode('DOCX-OCTETS'), $mime);
        $this->assertStringEndsWith('--' . $mixed[1] . '--', rtrim($mime));

        $simple = UserGmailService::buildDraftMime('a@exemple.fr', 'Objet', 'Bonjour.');
        $this->assertStringNotContainsString('multipart/mixed', $simple, 'Sans pièce jointe : le message d’avant, inchangé.');
    }

    public function testUnBrouillonOutlookAjouteSesPiecesJointesAuBrouillonCree(): void
    {
        $src = self::src('src/Outlook/UserOutlookService.php');
        $this->assertMatchesRegularExpression("#/messages/'\s*\.\s*rawurlencode\(\\\$draftId\)\s*\.\s*'/attachments'#", $src);
        $this->assertSame([
            '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'a.pdf', 'contentType' => 'application/pdf', 'contentBytes' => base64_encode('x'),
        ], OutgoingAttachments::graphPayload(['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => 'x']));
    }

    // ------------------------------------------------------------------
    // Surveillances et adresse d'appel
    // ------------------------------------------------------------------

    public function testLaRequeteDUneSurveillanceGmail(): void
    {
        $this->assertSame('in:inbox newer_than:3d', WorkflowTriggers::gmailQuery(''));
        $this->assertSame('in:inbox from:x@y.fr newer_than:3d', WorkflowTriggers::gmailQuery('from:x@y.fr'));
        $this->assertSame('label:clients newer_than:3d', WorkflowTriggers::gmailQuery('label:clients'));
        $this->assertSame('in:inbox objet after:2026/10/01', WorkflowTriggers::gmailQuery('objet after:2026/10/01'));
    }

    public function testLaDateDUnEmailSuitLeFuseauDeLaPersonne(): void
    {
        $this->assertSame('2026-10-14', WorkflowTriggers::dateOf('Tue, 13 Oct 2026 22:30:00 +0000', 'Europe/Paris'));
        $this->assertSame('2026-10-13', WorkflowTriggers::dateOf('Tue, 13 Oct 2026 22:30:00 +0000', 'America/New_York'));
        $this->assertSame('2026-10-05', WorkflowTriggers::dateOf('05/10/2026 08:12', 'Europe/Paris'));
        $this->assertNull(WorkflowTriggers::dateOf('', 'Europe/Paris'));
        $this->assertNull(WorkflowTriggers::dateOf('demain', 'Europe/Paris'));
    }

    public function testLeJetonDeLAdresseDAppel(): void
    {
        $a = WorkflowTriggers::newHookToken();
        $b = WorkflowTriggers::newHookToken();
        $this->assertNotSame($a, $b);
        $this->assertTrue(WorkflowTriggers::validHookToken($a));
        $this->assertSame(46, strlen($a), 'Préfixe + 32 octets en base64url.');
        foreach (['', 'wh_court', 'xx_' . substr($a, 3), $a . 'x', 'wh_' . str_repeat('+', 43)] as $faux) {
            $this->assertFalse(WorkflowTriggers::validHookToken($faux), $faux);
        }
        $this->assertSame(hash('sha256', $a), WorkflowTriggers::hookHash($a));
    }

    public function testLeCorpsDUnAppel(): void
    {
        require_once ROOT . '/src/Controllers/WorkflowHookController.php';
        $this->assertSame(['client' => 'Lyon', 'n' => 2], WorkflowHookController::parseBody('{"client":"Lyon","n":2}', 'application/json; charset=utf-8'));
        $this->assertSame(['donnees' => [1, 2]], WorkflowHookController::parseBody('[1,2]', 'application/json'), 'Une liste devient un champ.');
        $this->assertSame(['donnees' => 'texte'], WorkflowHookController::parseBody('"texte"', ''));
        $this->assertNull(WorkflowHookController::parseBody('{pas du json', 'application/json'));
        $this->assertSame(['texte' => 'Bonjour'], WorkflowHookController::parseBody('Bonjour', 'text/plain'));
        $this->assertSame([], WorkflowHookController::parseBody('', 'application/json'), 'Un appel sans corps lance le workflow sans données.');
        $this->assertSame(['client' => 'Lyon', 'ville' => 'Paris'], WorkflowHookController::parseBody('client=Lyon&ville=Paris', 'application/x-www-form-urlencoded'));
    }

    public function testLesValeursDUnAppelRemplissentLeDepart(): void
    {
        $def = WorkflowSpec::normalize(['start' => ['fields' => [
            ['key' => 'client', 'label' => 'Nom du client', 'type' => 'text'],
            ['key' => 'urgent', 'label' => 'Urgent', 'type' => 'yesno'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'longtext'],
            ['key' => 'piece', 'label' => 'Pièce', 'type' => 'file'],
        ], 'event' => ['kind' => 'webhook']]]);
        $vals = WorkflowTriggers::hookValues($def, ['nom_du_client' => 'Lyon', 'Urgent' => true, 'notes' => ['a', 'b'], 'piece' => 'x', 'autre' => ['k' => 'v']]);
        $this->assertSame(['client' => 'Lyon', 'urgent' => 'oui', 'notes' => "a\nb"], $vals['values'], 'Un fichier ne se fournit pas par un appel.');
        $this->assertStringContainsString('"autre"', $vals['text'], 'Le corps entier devient la pièce « Données reçues ».');
    }

    public function testUnEssaiALaMainDUnWorkflowAEvenement(): void
    {
        $email = WorkflowSpec::normalize(['start' => ['event' => ['kind' => 'email']]]);
        $this->assertNull(WorkflowTriggers::manualEvent($email, '  ', null));
        $ev = WorkflowTriggers::manualEvent($email, 'Bonjour, voici ma demande.', null);
        $this->assertSame('Bonjour, voici ma demande.', $ev['fields']['contenu']);
        $this->assertNull(WorkflowTriggers::manualEvent(WorkflowSpec::normalize([]), 'x', null), 'Sans évènement, rien à fournir.');
        $file = WorkflowSpec::normalize(['start' => ['event' => ['kind' => 'file', 'folder' => 'Entrants']]]);
        $this->assertNull(WorkflowTriggers::manualEvent($file, 'du texte', null), 'Un dossier surveillé attend un fichier.');
    }

    // ------------------------------------------------------------------
    // Livraisons
    // ------------------------------------------------------------------

    public function testLeTexteDUneLivraisonCiteLesPiecesEtLeursChamps(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text'], ['key' => 'montant', 'label' => 'Montant', 'type' => 'number']]],
            'nodes' => [['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => 'x', 'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'text']]],
        ]);
        $pieces = WorkflowSpec::pieces($def);
        $arts = ['depart' => ['content' => ['fields' => ['client' => 'Lyon', 'montant' => 6200]]], 'synthese' => ['content' => ['text' => 'Trois écoles.']]];
        $this->assertSame('Pour Lyon : Trois écoles.', WorkflowDeliveries::fill('Pour @{depart.client} : @{synthese}', $pieces, $arts, true));
        $this->assertSame('Objet — Lyon, « Synthèse »', WorkflowDeliveries::fill('Objet — @{depart.client}, @{synthese}', $pieces, $arts, false));
        $this->assertSame('— @{inconnue}', WorkflowDeliveries::fill('@{depart.zzz} @{inconnue}', $pieces, $arts, true));
        $this->assertStringContainsString('6', WorkflowDeliveries::fill('@{depart.montant}', $pieces, $arts, true));
    }

    public function testUneCarteNeeDUnTourDeChatPerdSesArgumentsInternes(): void
    {
        $this->assertSame(['corps' => 'x'], WorkflowDeliveries::stripInternal(['corps' => 'x', WorkflowDeliveries::ATTACH_KEY => [1], WorkflowDeliveries::FOLDER_KEY => 'D', '_wf_autre' => 1]));
        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertMatchesRegularExpression('/\$depositConnectorWrite\s*=.*?WorkflowDeliveries::stripInternal\(/s', $chat);
        $this->assertStringContainsString('WorkflowDeliveries::attachmentsFor(', $chat, 'Les pièces jointes sont relues depuis l’exécution au moment d’envoyer.');
        $this->assertStringContainsString('public function decideForWorkflow(', $chat);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLAdresseDAppelPasseAvantLaSessionEtLeCsrf(): void
    {
        $index = self::src('public/index.php');
        $hook = strpos($index, 'WorkflowHookController::handle(');
        $this->assertNotFalse($hook);
        $this->assertLessThan(strpos($index, 'Session::start();'), $hook);
        $this->assertLessThan(strpos($index, 'Vérification CSRF'), $hook);
        $this->assertGreaterThan(strpos($index, 'Bootstrap::configProblems()'), $hook, 'Une instance mal configurée ne répond pas aux appels.');
        $this->assertStringContainsString("\$requestMethod === 'POST' && preg_match('#^/hooks/workflows/", $index, 'POST seulement, ce chemin exact.');
    }

    public function testLesRoutesDeReprise(): void
    {
        $index = self::src('public/index.php');
        foreach (['skip', 'restart', 'resume', 'retry', 'cancel', 'gate'] as $geste) {
            $this->assertStringContainsString("\$router->post('/workflows/runs/{id}/{$geste}'", $index, $geste);
        }
        $this->assertStringContainsString("\$router->post('/workflows/{id}/hook'", $index);
        $ctrl = self::src('src/Controllers/WorkflowController.php');
        foreach (['public function skip(', 'public function restart(', 'public function resume(', 'public function hook('] as $m) {
            $this->assertStringContainsString($m, $ctrl);
        }
    }

    public function testLesClassesSontChargeesDesDeuxCotes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = self::src($f);
            foreach (['src/Workflow/WorkflowLoop.php', 'src/Workflow/WorkflowFiles.php', 'src/Workflow/WorkflowDeliveries.php', 'src/Workflow/WorkflowTriggers.php', 'src/Connectors/OutgoingAttachments.php'] as $c) {
                $this->assertStringContainsString($c, $src, "$c dans $f");
            }
        }
        $this->assertStringContainsString('src/Controllers/WorkflowHookController.php', self::src('config/classes.php'));
    }

    public function testLeWorkerMeneMinuteursEtSurveillances(): void
    {
        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertMatchesRegularExpression('/public static function tick\(.*?self::tickTimers\(.*?WorkflowTriggers::tickWatchers\(/s', $engine);
        $job = self::src('src/Jobs/WorkflowJob.php');
        foreach (["'condition'", "'loop_until'", "'deliver'", "'watch'"] as $op) {
            $this->assertStringContainsString($op, $job);
        }
        $this->assertStringContainsString('WorkflowTriggers::afterRunDone(', $engine, 'Fin d’une exécution : les workflows qui suivent.');
    }

    public function testLaMigrationDuSecondLot(): void
    {
        $sql = self::src('database/migrations/089_workflow_triggers_loops.sql');
        foreach (['watch_next_at', 'watch_state_json', 'hook_token_hash', 'hook_token_enc', 'trigger_ref', 'parent_run_id', 'resume_at', 'uq_wfr_ref', 'uq_wf_hook', 'idx_wfs_timer'] as $c) {
            $this->assertStringContainsString($c, $sql, $c);
        }
        $this->assertStringNotContainsString(' manual', strtolower($sql), 'Mot réservé de MySQL 8.4.');
    }

    public function testLeRailEtLEditeurConnaissentLesNouvellesBriques(): void
    {
        $rail = self::src('public/assets/js/workflow-rail.js');
        $editor = self::src('public/assets/js/workflow-editor.js');
        $run = self::src('public/assets/js/workflow-run.js');
        foreach (['loop', 'wait', 'notify'] as $type) {
            $this->assertStringContainsString("'" . $type . "'", $rail, $type);
            $this->assertStringContainsString("'" . $type . "'", $editor, $type);
        }
        foreach (["'retry'", "'skip'", "'resume'", "'restart'"] as $geste) {
            $this->assertStringContainsString($geste, $run, $geste);
        }
        $css = self::src('public/assets/css/workflows.css');
        foreach (['.wf-loopbox', '.wf-timer', '.wf-dl', '.wf-hook', '.wf-restart'] as $sel) {
            $this->assertStringContainsString($sel, $css, $sel);
        }
        $this->assertDoesNotMatchRegularExpression('/(?<![\w-])(\d+)vh\b/', $css, 'Une unité d’écran se divise par le zoom (cf. Affichage).');
    }
}
