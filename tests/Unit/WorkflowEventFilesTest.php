<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Les pièces jointes de l'email qui lance un workflow arrivent à l'étape qui
 * le lit (WorkflowEventFiles) — et une étape ou une tâche ne renvoie plus
 * vers le menu « + » du chat (ConnectorRegistry::dormantSourcesNote).
 *
 * Le défaut d'origine (constaté chez un client, 2026-11-24, workflow « Piloter Amazon 1P EU ») :
 * l'étape « Contrôler les données » citait « Email reçu » et devait analyser
 * son classeur Excel ; elle ne recevait que le NOM du fichier, n'avait aucun
 * outil pour l'ouvrir (Outlook non coché dans « Peut consulter »), et le
 * modèle conseillait d'activer Outlook dans un menu « + » qui n'existe pas
 * dans un workflow.
 */
final class WorkflowEventFilesTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        I18n::use('fr');
    }

    private static function src(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    }

    /** Workflow lancé par un email Outlook : une étape le cite, une autre non. */
    private static function def(): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fields' => [], 'event' => ['kind' => 'email', 'source' => 'outlook', 'query' => '']],
            'nodes' => [
                ['key' => 'controler', 'type' => 'step', 'title' => 'Contrôler', 'instruction' => 'Analyse le classeur joint à @{evenement}.',
                    'output' => ['key' => 'controle', 'name' => 'Contrôle', 'form' => 'text']],
                ['key' => 'rediger', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'Résume @{controle}.',
                    'output' => ['key' => 'resume', 'name' => 'Résumé', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['resume']],
        ]);
    }

    private static function artifacts(array $content): array
    {
        return [WorkflowSpec::EVENT_PIECE => ['key' => 'evenement', 'name' => 'Email reçu', 'form' => 'fiche', 'content' => $content]];
    }

    private static function email(array $over = []): array
    {
        return $over + [
            'message_id' => 'AAMkAD-xyz',
            'service'    => 'outlook',
            'fields'     => ['objet' => '[Reporting Amazon EU]', 'pieces_jointes' => ['Amazon_EU.xlsx']],
        ];
    }

    // ------------------------------------------------------------------
    // Quelle étape reçoit les pièces jointes
    // ------------------------------------------------------------------

    public function testUneEtapeQuiCiteLEmailRecuRecoitSesPiecesJointes(): void
    {
        $def = self::def();
        $node = WorkflowSpec::node($def, 'controler');
        $this->assertSame(
            ['service' => 'outlook', 'message_id' => 'AAMkAD-xyz', 'piece' => 'Email reçu'],
            WorkflowEventFiles::forPass($def, $node, self::artifacts(self::email()))
        );
    }

    public function testRienQuandLEtapeNeLitPasLEmailOuQuIlNyARienAJoindre(): void
    {
        $def = self::def();
        // L'étape suivante ne cite que la pièce de la première : pas l'email.
        $this->assertNull(WorkflowEventFiles::forPass($def, WorkflowSpec::node($def, 'rediger'), self::artifacts(self::email())));
        $node = WorkflowSpec::node($def, 'controler');
        // Email sans pièce jointe.
        $this->assertNull(WorkflowEventFiles::forPass($def, $node, self::artifacts(self::email(['fields' => ['pieces_jointes' => []]]))));
        // Email collé à la main dans la fenêtre de lancement : aucun identifiant.
        $this->assertNull(WorkflowEventFiles::forPass($def, $node, self::artifacts(self::email(['message_id' => '']))));
        // Fichier Drive reçu : pas un email.
        $this->assertNull(WorkflowEventFiles::forPass($def, $node, self::artifacts(self::email(['service' => '']))));
        // Pas d'évènement du tout (lancé à la main sans email).
        $this->assertNull(WorkflowEventFiles::forPass($def, $node, []));
    }

    public function testLitAussiSansCiterSuffit(): void
    {
        $def = self::def();
        $node = WorkflowSpec::node($def, 'rediger');
        $node['reads'] = [WorkflowSpec::EVENT_PIECE];
        $this->assertNotNull(WorkflowEventFiles::forPass($def, $node, self::artifacts(self::email())));
    }

    // ------------------------------------------------------------------
    // Quelles pièces jointes
    // ------------------------------------------------------------------

    private static function att(string $nom, string $type, int $taille, array $over = []): array
    {
        static $n = 0;
        return $over + ['numero' => ++$n, 'nom' => $nom, 'type' => $type, 'taille' => $taille, 'piece' => 'fichier', 'en_ligne' => false, 'ref' => 'r' . $n, 'data' => ''];
    }

    public function testLePlanGardeCeQueLeChatLiraitEtNommeLeReste(): void
    {
        $plan = WorkflowEventFiles::plan([
            self::att('Amazon_EU.xlsx', 'application/octet-stream', 400_000),
            self::att('logo.png', 'image/png', 4_000, ['en_ligne' => true]),
            self::att('photo.jpg', 'image/jpeg', 900_000),
            self::att('scan.heic', 'image/heic', 900_000),
            self::att('Dossier partagé', '', 0, ['piece' => 'lien']),
            self::att('archive.exe', 'application/x-msdownload', 1_000),
            self::att('enorme.csv', 'text/csv', 15 * 1024 * 1024),
            self::att('Re: devis', 'message/rfc822', 20_000, ['piece' => 'email']),
        ]);
        $this->assertSame(['Amazon_EU.xlsx', 'photo.jpg', 'Re: devis'], array_column($plan['take'], 'nom'), 'Le logo de signature est ignoré sans un mot.');
        $reasons = array_column($plan['skipped'], 'reason', 'name');
        $this->assertSame(['scan.heic', 'Dossier partagé', 'archive.exe', 'enorme.csv'], array_keys($reasons));
        $this->assertSame('format d’image non lu', $reasons['scan.heic']);
        $this->assertSame('lien de partage, pas un fichier joint', $reasons['Dossier partagé']);
        $this->assertSame('format non lu', $reasons['archive.exe']);
        $this->assertStringStartsWith('trop lourd (', $reasons['enorme.csv'], 'La limite d’un fichier joint au chat vaut ici aussi.');
    }

    public function testAuDelaDeCinqPiecesJointesLesSuivantesSontNommees(): void
    {
        $list = [];
        for ($i = 1; $i <= 7; $i++) {
            $list[] = self::att('f' . $i . '.csv', 'text/csv', 1_000);
        }
        $plan = WorkflowEventFiles::plan($list);
        $this->assertCount(WorkflowEventFiles::MAX_FILES, $plan['take']);
        $this->assertSame(['f6.csv', 'f7.csv'], array_column($plan['skipped'], 'name'));
        $this->assertSame('au-delà de 5 pièces jointes', $plan['skipped'][0]['reason']);
    }

    // ------------------------------------------------------------------
    // Ce que le modèle apprend, ce que dit la trace
    // ------------------------------------------------------------------

    public function testLaNoteDitQueLeFichierEstLaEtCommentLeCompter(): void
    {
        $note = WorkflowEventFiles::promptNote(['files' => ['Amazon_EU.xlsx'], 'skipped' => [['name' => 'scan.heic', 'reason' => 'format d’image non lu']], 'error' => ''], 'Email reçu');
        $this->assertStringContainsString('« Amazon_EU.xlsx » est jointe à ce message, en entier', $note);
        $this->assertStringContainsString('aucun connecteur n\'est nécessaire', $note, 'Sinon le modèle cherche l’email dans Outlook.');
        $this->assertStringContainsString('lignes_piece_jointe', $note, 'Un classeur se compte par une table, jamais sur un extrait.');
        $this->assertStringContainsString('« scan.heic » (format d’image non lu)', $note);
        $this->assertSame('Amazon_EU.xlsx', WorkflowEventFiles::traceDetail(['files' => ['Amazon_EU.xlsx'], 'skipped' => [], 'error' => '']));
    }

    public function testUnePanneSeDitSansRienInventer(): void
    {
        $res = ['files' => [], 'skipped' => [], 'error' => 'Outlook non connecté — reconnectez-le dans Réglages → Comptes connectés.'];
        $note = WorkflowEventFiles::promptNote($res, 'Email reçu');
        $this->assertStringContainsString('n\'ont pas pu être récupérées (Outlook non connecté', $note);
        $this->assertStringContainsString('n\'invente rien', $note);
        $this->assertSame($res['error'], WorkflowEventFiles::traceDetail($res));
        $this->assertTrue(WorkflowEventFiles::worthTracing($res));
        $this->assertSame('', WorkflowEventFiles::promptNote(['files' => [], 'skipped' => [], 'error' => ''], 'Email reçu'));
        $this->assertFalse(WorkflowEventFiles::worthTracing(['files' => [], 'skipped' => [], 'error' => '']));
    }

    // ------------------------------------------------------------------
    // Connecteur éteint : le geste d'une étape, d'une tâche
    // ------------------------------------------------------------------

    public function testUneEtapeDeWorkflowRenvoieVersPeutConsulterJamaisVersLeMenuPlus(): void
    {
        $note = ConnectorRegistry::dormantSourcesNote(['Outlook'], ConnectorRegistry::WHERE_WORKFLOW);
        $this->assertStringContainsString('« Outlook »', $note);
        $this->assertStringContainsString('section « Peut consulter »', $note);
        $this->assertStringContainsString('publier, puis relancer', $note);
        $this->assertStringContainsString('Les pièces jointes de l\'email qui lance le workflow n\'en ont pas besoin', $note);
        $this->assertStringNotContainsString('déplier « Connecteurs »', $note, 'Le geste du chat n’existe pas dans un workflow.');
        $this->assertStringContainsString('SEULEMENT si', $note, 'Sans borne, l’étape le dirait à chaque exécution.');

        $task = ConnectorRegistry::dormantSourcesNote(['Outlook'], ConnectorRegistry::WHERE_TASK);
        $this->assertStringContainsString('section « Avec quoi »', $task);
        $this->assertStringNotContainsString('déplier « Connecteurs »', $task);

        $this->assertSame('', ConnectorRegistry::dormantSourcesNote([], ConnectorRegistry::WHERE_WORKFLOW));
        $this->assertSame('', ConnectorRegistry::dormantSourcesNote(['', '  '], ConnectorRegistry::WHERE_TASK));
    }

    public function testLaNoteSuitLaLangueDeLEcran(): void
    {
        I18n::use('en');
        try {
            $note = ConnectorRegistry::dormantSourcesNote(['Outlook'], ConnectorRegistry::WHERE_WORKFLOW);
            $this->assertStringContainsString('“Can consult”', $note, 'Le libellé cité est celui de l’éditeur anglais.');
            $task = ConnectorRegistry::dormantSourcesNote(['Outlook'], ConnectorRegistry::WHERE_TASK);
            $this->assertStringContainsString('“With what”', $task);
        } finally {
            I18n::use('fr');
        }
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeMoteurEtLeTourSontBranches(): void
    {
        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString("'event_mail'     => WorkflowEventFiles::forPass(\$def, \$node, \$artifacts),", $engine);

        $chat = self::src('src/Controllers/ChatController.php');
        $attach = strpos($chat, 'WorkflowEventFiles::attach(');
        $read = strpos($chat, '$userMsgRow = $userMsgId > 0 ? Message::findById($userMsgId) : null;');
        $this->assertNotFalse($attach);
        $this->assertNotFalse($read);
        $this->assertLessThan($read, $attach, 'Les fichiers sont joints AVANT que le tour relise son message.');
        $this->assertStringContainsString('WorkflowEventFiles::promptNote($eventFiles', $chat);
        $this->assertStringContainsString("\$this->traceAction('email_files'", $chat);
        $this->assertArrayHasKey('email_files', ChatController::ACTION_LABELS);
        $this->assertSame('doc', ChatController::ACTION_ICONS['email_files']);

        // Note du connecteur éteint : une étape, une tâche → la variante sans menu « + ».
        $this->assertMatchesRegularExpression('/if \(\$workflowStep !== null \|\| \$scheduled !== null\) \{\s*\/\/[^\n]*\n[^\n]*\n\s*\$systemPrompt \.= ConnectorRegistry::dormantSourcesNote\(/', $chat);

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = self::src($f);
            $this->assertStringContainsString('/src/Workflow/WorkflowEventFiles.php', $src);
            $this->assertLessThan(strpos($src, '/src/Workflow/WorkflowEventFiles.php'), strpos($src, '/src/Workflow/LoopRunner.php'));
        }
    }

    public function testLEditeurLeDitLaOuLOnRegleCeQueLEtapeLit(): void
    {
        $js = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString('function eventFilesHint(n)', $js);
        $this->assertStringContainsString("+ readsChoice(n, avail)\n            + eventFilesHint(n)", $js);
        $this->assertStringContainsString("ev.kind !== 'email'", $js);
        $client = (array)(require dirname(__DIR__, 2) . '/lang/en.client.php');
        $this->assertArrayHasKey('Les pièces jointes de « {piece} » sont jointes à cette étape : elle les lit en entier, sans cocher {service} dans « Peut consulter ».', $client);
        $this->assertStringContainsString('.wf-hint.wf-event-files', self::src('public/assets/css/workflows.css'));
    }

    public function testLeResumeDeLEmailRecuNePromettPlusUnOutil(): void
    {
        $triggers = self::src('src/Workflow/WorkflowTriggers.php');
        $this->assertStringContainsString('Ses pièces jointes sont jointes à chaque étape qui le lit', $triggers);
        $this->assertStringNotContainsString('peut le relire, lire ses pièces jointes ou y répondre', $triggers);
    }
}
