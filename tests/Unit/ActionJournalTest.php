<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mémoire de travail d'un tour à l'autre :
 *  - le journal des actions des réponses précédentes (ActionJournal) ;
 *  - la date et l'heure de l'utilisateur dans tous les prompts de chat ;
 *  - le budget de lecture des connecteurs, tiré du modèle et non plus de
 *    l'assistant.
 */
final class ActionJournalTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------- Journal ----------

    public function testSansActionLeJournalNeCouteRien(): void
    {
        $this->assertSame('', ActionJournal::promptFragment([]));
        $this->assertSame('', ActionJournal::promptFragment([
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'assistant', 'content' => 'Salut', 'actions_json' => null],
        ]));
    }

    public function testLeJournalRappelleLesGestesEtLaDemandeQuiLesAPortes(): void
    {
        $history = [
            ['role' => 'user', 'content' => "Trouve la facture EDF\nde juillet"],
            ['role' => 'assistant', 'content' => 'Je ne la trouve pas.', 'actions_json' => json_encode([
                ['t' => 'rechercher_gmail', 'l' => 'Recherche dans Gmail', 'd' => 'facture EDF juillet', 'ok' => true],
                ['t' => 'lire_gmail', 'l' => 'Lecture d’un email', 'd' => 'Relevé', 'ok' => false],
                ['t' => 'relais_secours', 'l' => 'Réponse du modèle de secours', 'ok' => true],
            ])],
        ];
        $fragment = ActionJournal::promptFragment($history);
        $this->assertStringContainsString('En réponse à « Trouve la facture EDF de juillet »', $fragment,
            'Les gestes sont rattachés à la demande : le modèle sait à quoi ils ont servi.');
        $this->assertStringContainsString('Recherche dans Gmail (« facture EDF juillet »)', $fragment);
        $this->assertStringContainsString('Lecture d’un email (« Relevé ») — échec', $fragment);
        $this->assertStringNotContainsString('secours', $fragment, 'Un relais dit QUI a répondu, pas ce qui a été fait.');
        $this->assertStringContainsString('ne refais pas à l\'identique', $fragment);
    }

    public function testLeJournalEstBorne(): void
    {
        $history = [];
        for ($i = 1; $i <= 5; $i++) {
            $actions = [];
            for ($k = 1; $k <= 12; $k++) {
                $actions[] = ['t' => 'rechercher_google_drive', 'l' => 'Recherche Drive', 'd' => str_repeat('x', 200), 'ok' => true];
            }
            $history[] = ['role' => 'user', 'content' => 'Demande ' . $i];
            $history[] = ['role' => 'assistant', 'content' => 'R', 'actions_json' => json_encode($actions)];
        }
        $fragment = ActionJournal::promptFragment($history);
        $this->assertSame(ActionJournal::TURNS, substr_count($fragment, "\n- "), 'Les trois réponses les plus récentes seulement.');
        $this->assertStringNotContainsString('Demande 2', $fragment);
        $this->assertStringContainsString('Demande 5', $fragment);
        $this->assertLessThan(strpos($fragment, 'Demande 5'), strpos($fragment, 'Demande 3'), 'Dans l’ordre de la conversation.');
        $this->assertStringContainsString('et 4 autre(s)', $fragment);
        $this->assertLessThan(3000, mb_strlen($fragment), 'Quelques centaines de jetons au plus.');
    }

    public function testLeJournalRejointLaBoucleDOutilsSeulement(): void
    {
        $src = AgentSource::chat();
        $this->assertMatchesRegularExpression(
            '/if \(\$useToolLoop\) \{.*?\$toolGuidance \.= ActionJournal::promptFragment\(self::historyWithout\(\$history, \$userMsgId\)\);/s',
            $src
        );
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString("/src/Agent/ActionJournal.php'", (string)file_get_contents(ROOT . '/' . $f), $f);
        }
    }

    // ---------- Date et heure ----------

    public function testLaDateEstCelleDeLUtilisateur(): void
    {
        $t = new DateTimeImmutable('2026-09-30 22:30:00', new DateTimeZone('UTC'));
        $paris = ChatController::nowLine('Europe/Paris', $t);
        $this->assertStringContainsString('jeudi 1 octobre 2026, 00:30', $paris,
            'À minuit passé à Paris, « demain » n’est plus le même jour qu’en UTC.');
        $this->assertStringContainsString('fuseau de l\'utilisateur : Europe/Paris', $paris);

        $serveur = ChatController::nowLine('', $t);
        $this->assertStringContainsString('fuseau du serveur', $serveur, 'Sans fuseau du navigateur, celui du serveur, nommé comme tel.');
    }

    public function testUnFuseauInconnuEstIgnore(): void
    {
        $this->assertSame('Europe/Paris', ChatController::sanitizeTimeZone(' Europe/Paris '));
        $this->assertSame('America/Argentina/Buenos_Aires', ChatController::sanitizeTimeZone('America/Argentina/Buenos_Aires'));
        foreach (['', 'Mars/Base', '../../etc/passwd', 'Europe/Paris;x', 'Europe /Paris', str_repeat('A', 80)] as $faux) {
            $this->assertSame('', ChatController::sanitizeTimeZone($faux), var_export($faux, true));
        }
    }

    public function testLaDateRejointLEnvoiEtLaRegeneration(): void
    {
        $src = AgentSource::chat();
        $this->assertStringContainsString("'tz'              => \$timeZone,", $src, 'Le fuseau voyage dans la charge du job.');
        $this->assertStringContainsString('$systemPrompt .= self::nowLine($timeZone);', $src);
        $this->assertStringContainsString("\$systemPrompt .= self::nowLine(self::sanitizeTimeZone((string)(\$_POST['tz'] ?? '')));", $src);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertSame(3, substr_count($js, "append('tz', clientTimeZone())"), 'Envoi, régénération et nouvelle tentative automatique.');
        $this->assertStringContainsString('Intl.DateTimeFormat().resolvedOptions().timeZone', $js);
    }

    // ---------- Budget de lecture ----------

    public function testLeBudgetDeLectureNeDependPlusDeLAssistant(): void
    {
        $this->assertSame(2000, ChatController::connectorReadBudget(0), 'Plancher.');
        $this->assertSame(50000, ChatController::connectorReadBudget(100000), 'Moitié du budget RAG du modèle.');
        $this->assertSame(ChatController::CONNECTOR_READ_BUDGET_MAX, ChatController::connectorReadBudget(500000), 'Plafond : le coût d’une lecture reste borné.');

        $src = AgentSource::chat();
        $this->assertStringContainsString('self::connectorReadBudget($ragBudget),', $src);
        $this->assertStringNotContainsString('max(2000, intdiv(max(0, $budget), 2))', $src,
            'Sans assistant, $budget vaut 0 : un document Drive était coupé à 2 000 jetons.');
    }
}
