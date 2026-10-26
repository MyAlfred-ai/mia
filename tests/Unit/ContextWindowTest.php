<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT . '/src/Controllers/ChatController.php';

/**
 * Historique BORNÉ envoyé au modèle (ContextWindow, ConversationSummary,
 * relecture des fichiers anciens, lecture paginée des connecteurs).
 *
 * Le défaut corrigé : tout l'historique repartait à chaque message, pièces
 * jointes comprises — un PDF joint au début repartait avec chaque question,
 * jusqu'au refus « conversation trop longue ».
 */
final class ContextWindowTest extends TestCase
{
    /** Ligne d'historique (forme de la table messages). */
    private static function row(int $id, string $role, string $content, array $files = []): array
    {
        return [
            'id'         => $id,
            'role'       => $role,
            'content'    => $content,
            'files_json' => $files !== [] ? json_encode($files) : null,
        ];
    }

    /** Conversation alternée de `$n` messages de `$chars` caractères. */
    private static function conversation(int $n, int $chars): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = self::row($i, $i % 2 === 1 ? 'user' : 'assistant', str_repeat('a', $chars));
        }
        return $rows;
    }

    public function testLeBudgetEstLaMoitieDeLaFenetreAvecUnPlancher(): void
    {
        $this->assertSame(100000, ContextWindow::budget(200000));
        $this->assertSame(ContextWindow::MIN_BUDGET, ContextWindow::budget(1000));
        $this->assertSame(ContextWindow::MIN_BUDGET, ContextWindow::budget(0));
    }

    public function testUneConversationQuiTientNeChangePas(): void
    {
        $history = self::conversation(10, 400);
        $plan    = ContextWindow::plan($history, 4000, null);
        $this->assertSame(0, $plan['cutoff']);
        $this->assertSame(0, $plan['strip_files_before']);
        $this->assertFalse($plan['needs_summary']);
        $this->assertSame($history, ContextWindow::apply($history, $plan), 'Rien ne bouge tant que tout tient.');
        $this->assertSame('', ContextWindow::summaryFragment(null, 0));
    }

    public function testLesPiecesJointesAnciennesSontNommeesAvantDeResumer(): void
    {
        $contrat = ['name' => 'contrat.docx', 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'extracted' => str_repeat('clause ', 6000)];
        $photo   = ['name' => 'plan.png', 'type' => 'image/png', 'extracted' => base64_encode('png')];
        $history = [
            self::row(1, 'user', 'Voici le contrat', [$contrat]),
            self::row(2, 'assistant', 'Bien reçu.'),
            self::row(3, 'user', 'Et ce plan ?', [$photo]),
            self::row(4, 'assistant', 'Je le vois.'),
            self::row(5, 'user', 'Résume.'),
            self::row(6, 'assistant', 'Voici.'),
            self::row(7, 'user', 'Merci', [$photo]),
        ];
        $plan = ContextWindow::plan($history, 4000, null);
        $this->assertSame(5, $plan['strip_files_before'], 'Les pièces jointes des deux derniers messages de l\'utilisateur restent.');
        $this->assertSame(0, $plan['cutoff'], 'Sans les pièces jointes anciennes, tout tient : aucun résumé.');

        $fitted = ContextWindow::apply($history, $plan);
        $this->assertCount(7, $fitted);
        $this->assertNull($fitted[0]['files_json'], 'Le contrat n\'est plus renvoyé.');
        $this->assertStringContainsString('[Fichier « contrat.docx » joint à ce message', $fitted[0]['content']);
        $this->assertStringNotContainsString('relire_fichier_conversation', $fitted[0]['content'],
            'Le texte des anciens messages ne dépend pas de l\'outil, disponible ou non selon le message.');
        $this->assertNull($fitted[2]['files_json']);
        $this->assertSame($history[6]['files_json'], $fitted[6]['files_json'], 'Le message en cours garde ses pièces jointes.');
        $this->assertStringContainsString('relire_fichier_conversation', ContextWindow::filesNote());
        $this->assertStringContainsString('demande à l\'utilisateur de le', ContextWindow::filesNote());
        $this->assertSame(['contrat.docx', 'plan.png'], ContextWindow::strippedFileNames($history, $plan));

        // Le message évoque-t-il un fichier retiré ? (sinon : pas de boucle forcée)
        $this->assertTrue(ContextWindow::mentionsCompactedFile('Que dit le contrat sur la résiliation ?', $history, $plan), 'Nom sans extension.');
        $this->assertTrue(ContextWindow::mentionsCompactedFile('Reprends la pièce jointe du début', $history, $plan));
        $this->assertTrue(ContextWindow::mentionsCompactedFile('Look at the attached file again', $history, $plan));
        $this->assertFalse(ContextWindow::mentionsCompactedFile('Merci, et pour demain ?', $history, $plan));
        $this->assertFalse(ContextWindow::mentionsCompactedFile('Rédige la documentation', $history, $plan), 'Mots entiers seulement.');
        $this->assertFalse(ContextWindow::mentionsCompactedFile('Que dit le contrat ?', $history, ['strip_files_before' => 0, 'cutoff' => 0]));
    }

    public function testUneLongueConversationEstCoupeeAvantUnMessageDeLUtilisateur(): void
    {
        $history = self::conversation(21, 2000); // ~504 jetons le message
        $plan    = ContextWindow::plan($history, 4000, null);
        $this->assertGreaterThan(0, $plan['cutoff']);
        $this->assertTrue($plan['needs_summary']);
        $fitted = ContextWindow::apply($history, $plan, true);
        $this->assertSame('user', $fitted[0]['role'], 'La partie gardée commence par un message de l\'utilisateur.');
        $this->assertGreaterThanOrEqual(ContextWindow::KEEP_RECENT_MIN, count($fitted));
        $this->assertSame(21, end($fitted)['id'], 'Le message en cours n\'est jamais retiré.');
        $this->assertSame(21 - count($fitted), $plan['dropped']);
        $kept = array_sum(array_map(static fn(array $r): int => ContextWindow::rowTokens($r), $fitted));
        $this->assertLessThanOrEqual(4000 - ContextWindow::SUMMARY_RESERVE, $kept, 'La partie gardée laisse la place au résumé.');
    }

    public function testLeResumeEstReutiliseTantQueLaSuiteTientPuisCompleteParBlocs(): void
    {
        $history = self::conversation(61, 2000); // ~504 jetons le message, ~30 000 en tout
        $first   = ContextWindow::plan($history, 20000, null);
        $this->assertTrue($first['needs_summary']);
        $summary = ['upto' => $first['cutoff'], 'text' => 'Résumé'];

        // Message suivant : la suite tient encore → même résumé, aucun appel.
        $history[] = self::row(62, 'assistant', str_repeat('b', 2000));
        $history[] = self::row(63, 'user', 'Et ensuite ?');
        $again = ContextWindow::plan($history, 20000, $summary);
        $this->assertTrue($again['reuse_summary']);
        $this->assertFalse($again['needs_summary']);
        $this->assertSame($first['cutoff'], $again['cutoff']);

        // Une vingtaine de messages plus tard : la coupure avance, d'un bloc.
        for ($id = 64; $id <= 81; $id++) {
            $history[] = self::row($id, $id % 2 === 1 ? 'user' : 'assistant', str_repeat('c', 2000));
        }
        $later = ContextWindow::plan($history, 20000, $summary);
        $this->assertTrue($later['needs_summary']);
        $this->assertGreaterThan($first['cutoff'], $later['cutoff'], 'La coupure avance : le résumé est complété, pas refait.');
    }

    public function testUnePresentationNeCompteQueSonResume(): void
    {
        $deck = self::row(2, 'assistant', str_repeat('{"slides":[]}', 5000), [['type' => 'slide_deck', 'name' => 'presentation.json']]);
        $this->assertLessThan(100, ContextWindow::rowTokens($deck), 'Une présentation part en une ligne de résumé (buildLLMMessages).');
        $image = self::row(1, 'user', 'x', [['name' => 'a.png', 'type' => 'image/png', 'extracted' => 'AAAA']]);
        $this->assertSame(ContextWindow::IMAGE_TOKENS + 5, ContextWindow::rowTokens($image));
        $this->assertSame(45, ContextWindow::rowTokens($image, false), 'Sans ses pièces jointes, une ligne les nomme.');
    }

    public function testLeFragmentDeResumeEtLaNoteDeRepli(): void
    {
        $avec = ContextWindow::summaryFragment('- Budget 2026 validé', 12);
        $this->assertStringContainsString('ses 12 premiers messages', $avec);
        $this->assertStringContainsString('- Budget 2026 validé', $avec);
        $this->assertStringContainsString('plutôt que de l\'inventer', $avec);
        $sans = ContextWindow::summaryFragment(null, 12);
        $this->assertStringContainsString('ne te sont plus transmis', $sans, 'Sans résumé, la coupure est dite, jamais muette.');
    }

    public function testLaTranscriptionPourLeResume(): void
    {
        $history = [
            self::row(1, 'user', 'Bonjour ![x](/assets/img/generated/a.png)', [['name' => 'devis.pdf', 'type' => 'application/pdf', 'extracted' => 'JVBER']]),
            self::row(2, 'assistant', '⚠️ Service saturé'),
            self::row(3, 'assistant', str_repeat('long ', 1000)),
            self::row(4, 'user', 'Hors plage'),
        ];
        $t = ContextWindow::transcript($history, 0, 3);
        $this->assertStringContainsString('[Utilisateur] Bonjour [image] [fichier(s) joint(s) : devis.pdf]', $t);
        $this->assertStringNotContainsString('Service saturé', $t, 'Une réponse en erreur n\'apprend rien au résumé.');
        $this->assertStringContainsString(' […]', $t, 'Un message long est borné.');
        $this->assertStringNotContainsString('Hors plage', $t);
        $this->assertStringNotContainsString('JVBER', $t, 'Jamais le contenu d\'une pièce jointe.');

        $court = ContextWindow::transcript(self::conversation(40, 1400), 0, 40, 6000, 1500);
        $this->assertLessThan(6200, mb_strlen($court));
        $this->assertStringContainsString('[… messages intermédiaires omis …]', $court, 'Début et fin gardés.');
    }

    public function testLesFichiersDeLaConversationSeDesignentParNumeroOuParNom(): void
    {
        $txt = static fn(string $n, string $c): array => ['name' => $n, 'type' => 'text/plain', 'extracted' => $c];
        $history = [
            self::row(1, 'user', 'a', [$txt('Contrat Dupont.docx', 'v1'), ['type' => 'slide_deck', 'name' => 'presentation.json']]),
            self::row(2, 'user', 'b', [$txt('notes.txt', 'n')]),
            self::row(3, 'user', 'c', [$txt('Contrat Dupont.docx', 'v2')]),
        ];
        $files = ContextWindow::conversationFiles($history);
        $this->assertSame([1, 2, 3], array_column($files, 'numero'), 'Les marqueurs de création ne comptent pas.');
        $this->assertSame('notes.txt', ContextWindow::pickFile($files, '2')['nom']);
        $this->assertSame('notes.txt', ContextWindow::pickFile($files, 'n°2')['nom']);
        $this->assertSame('v2', ContextWindow::pickFile($files, 'contrat dupont.docx')['extracted'], 'Même nom : la version la plus récente.');
        $this->assertSame('v2', ContextWindow::pickFile($files, 'dupont')['extracted'], 'Partie unique du nom.');

        foreach (['', '9', 'facture'] as $mauvais) {
            try {
                ContextWindow::pickFile($files, $mauvais);
                $this->fail('Désignation refusée attendue : ' . $mauvais);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Fichiers joints dans cette conversation : 1. Contrat Dupont.docx, 2. notes.txt', $e->getMessage());
            }
        }
        $this->expectException(\RuntimeException::class);
        ContextWindow::pickFile([], 'x');
    }

    public function testLaRelectureEstExposeeSeulementQuandLaFenetreARetireDesFichiers(): void
    {
        $turn  = new AgentTurn(1, 2, [], 1000, static function (array $e): void {}, static fn(string $t, array $a, ?array $r): array => []);
        $avec  = new ConversationTools($turn, false, false, true);
        $this->assertSame(['relire_fichier_conversation'], array_column($avec->definitions(), 'name'));
        $this->assertStringContainsString('relire_fichier_conversation', $avec->guidance());
        $this->assertArrayHasKey('relire_fichier_conversation', $avec->handlers());
        $sans = new ConversationTools($turn, true, true);
        $this->assertNotContains('relire_fichier_conversation', array_column($sans->definitions(), 'name'));
        $this->assertArrayHasKey('partie', $avec->definitions()[0]['parameters']['properties']);
    }

    public function testLeChatBorneLHistoriqueAuxDeuxEndroits(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');

        // Envoi : la fenêtre est calculée une fois l'adaptateur connu.
        $send = substr($src, (int)strpos($src, 'public function runSend('), 80000);
        $this->assertStringNotContainsString('$llmMsgs  = $this->buildLLMMessages($history);', $send, 'Plus jamais l\'historique entier.');
        $this->assertStringContainsString('[$fittedHistory, $contextFragment, $contextPlan] = $this->fitHistory($conv, $history, $llm, $userId);', $send);
        $this->assertLessThan(strpos($send, 'fitHistory('), strpos($send, '$supportsTools = $adapter->supportsToolUse();'));
        $this->assertStringContainsString('$fileMention   = $filesStripped && ContextWindow::mentionsCompactedFile($content, $history, $contextPlan);', $send);
        $this->assertStringContainsString('|| $pageUrlInMessage || $pastReference || $fileMention;', $send,
            'Seul un message qui évoque un fichier compte comme demande explicite.');
        $this->assertStringContainsString('$fileReread = $filesStripped && ($fileMention || $loopRunsAnyway);', $send,
            'Jamais de boucle forcée par la seule présence de fichiers anciens.');
        $this->assertStringContainsString('new ConversationTools($turn, true, $loopRunsAnyway && !$unattended, $fileReread)', $send);

        // Régénération : même fenêtre, sans outil de relecture.
        $regen = substr($src, (int)strpos($src, 'public function regenerate('), 20000);
        $this->assertStringContainsString('$this->fitHistory($conv, $history, $llm, (int)$user[\'id\'])', $regen);
        $this->assertLessThan(strpos($regen, 'fitHistory('), strpos($regen, '$this->actionTrace = [];'), 'La trace est remise à zéro AVANT : le résumé y écrit.');

        // Le résumé est compté et sa coupure n'est jamais muette.
        $fit = substr($src, (int)strpos($src, 'private function fitHistory('), 3000);
        $this->assertStringContainsString('ConversationSummary::save(', $fit);
        $this->assertStringContainsString('ContextWindow::summaryFragment($text, $plan[\'dropped\'])', $fit);
        $this->assertStringContainsString('ContextWindow::filesNote()', $fit);
        $this->assertStringContainsString("traceAction('context_summary'", $fit);
    }

    public function testMigrationSauvegardeChargementEtLibelles(): void
    {
        $sql = (string)file_get_contents(ROOT . '/database/migrations/079_conversation_context_summaries.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS conversation_context_summaries', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
        $this->assertContains('conversation_context_summaries', BackupService::EXCLUDED_TABLES, 'Un résumé de conversation est du contenu, pas de la configuration.');

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $code = (string)file_get_contents(ROOT . '/' . $liste);
            foreach (['src/Agent/ContextWindow.php', 'src/Agent/ResultCondenser.php', 'src/Models/ConversationSummary.php'] as $classe) {
                $this->assertStringContainsString($classe, $code, "$classe absent de $liste");
            }
        }

        foreach (['context_summary' => 'memory', 'conversation_file' => 'doc'] as $type => $famille) {
            $this->assertArrayHasKey($type, ChatController::ACTION_LABELS);
            $this->assertSame($famille, ChatController::ACTION_ICONS[$type]);
        }
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        foreach (['summarizing:', 'conversation_file:', 'condensing:'] as $phase) {
            $this->assertStringContainsString($phase, $js);
        }
        $en = require ROOT . '/lang/en.php';
        $this->assertArrayHasKey('Résumé du début de la conversation', $en);
        $this->assertArrayHasKey('Relecture d\'un fichier de la conversation', $en);
    }
}
