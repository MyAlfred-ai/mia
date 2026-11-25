<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';
require_once __DIR__ . '/../Support/FakeLlmStream.php';

/**
 * Le cas constaté chez un client (2026-11-24), de bout en bout, sur la vraie base et un
 * faux modèle : un workflow lancé par un email Outlook dont la pièce jointe
 * est un classeur Excel, une étape qui cite « Email reçu » sans rien cocher
 * dans « Peut consulter ».
 *
 * Avant : l'étape ne recevait que le nom du fichier, n'avait aucun outil pour
 * l'ouvrir, et répondait « pièce jointe non accessible ». Désormais :
 *  - le classeur est téléchargé au début du tour et joint au message de
 *    l'étape (une fois : un job rejoué ne le retélécharge pas) ;
 *  - le modèle le reçoit, et les outils de tables le rangent en entier dans
 *    une table de travail (source « lignes_piece_jointe ») ;
 *  - la trace le dit ; une panne du service se dit aussi, sans rien inventer.
 */
final class WorkflowEventFilesPipelineTest extends DatabaseTestCase
{
    private const LLM = 'openai::o3';
    private const MESSAGE_ID = 'AAMkADFi-test-client';
    private array $reglagesAvant = [];
    private int $userId = 0;
    /** @var list<string> */
    private array $tmp = [];
    private object $outlook;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Controllers/ScheduledTaskController.php';
        foreach (['JobErrorException', 'JobContext', 'JobHandler', 'ChatJob', 'ChatSendJob', 'ImageJob', 'MapJob', 'HtmlPageJob', 'WorkflowJob'] as $job) {
            require_once ROOT . '/src/Jobs/' . $job . '.php';
        }
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, Workflow::SETTING_AUTO, 'default_llm', 'job_worker_heartbeat_at',
                     'memory_enabled', 'memory_questions_enabled', 'memory_learn_enabled', 'composer_suggestions_enabled', CompanyProfile::SETTING_DESCRIPTION] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        Settings::set(Workflow::SETTING_AUTO, '1');
        Settings::set('memory_enabled', '0');
        Settings::set('memory_questions_enabled', '0');
        Settings::set('memory_learn_enabled', '0');
        Settings::set(CompanyProfile::SETTING_DESCRIPTION, '');
        LlmCatalog::setToday('2026-11-05');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        Settings::set('default_llm', self::LLM);
        FakeLlmStream::install();
        JobQueue::markWorkerAlive();
        $this->userId = $this->insertUser(7, 'creator');
        I18n::use('fr');

        // Faux Outlook : la boîte qui a reçu l'email du reporting Amazon.
        $this->outlook = new class {
            public int $downloads = 0;
            public ?string $fail = null;
            /** @var array<int, array{nom: string, type: string, bytes: string}> */
            public array $files = [];
            public array $listing = [];

            public function listAttachments(string $id): array
            {
                if ($this->fail !== null) {
                    throw new \RuntimeException($this->fail);
                }
                return $this->listing;
            }

            public function downloadAttachment(string $id, string $wanted): array
            {
                $this->downloads++;
                return $this->files[(int)$wanted];
            }
        };
        $outlook = $this->outlook;
        LoopRunner::overrideServices(static fn(string $service, int $userId): object => $outlook);
    }

    protected function tearDown(): void
    {
        LoopRunner::overrideServices(null);
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
        }
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        LlmCatalog::setToday(null);
        LlmCatalog::flushCache();
        if (self::$pdo !== null) {
            // Fichiers joints aux étapes : rangés parmi les téléversements par le test.
            foreach ($this->db()->query("SELECT files_json FROM messages WHERE files_json LIKE '%\"event\":true%'")->fetchAll(PDO::FETCH_COLUMN) as $json) {
                foreach ((array)json_decode((string)$json, true) as $f) {
                    if (str_starts_with((string)($f['path'] ?? ''), UPLOAD_DIR . 'wf_')) {
                        @unlink((string)$f['path']);
                    }
                }
            }
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, (string)($valeur ?? ''));
            }
        }
        parent::tearDown();
    }

    // ==================================================================
    // Outils
    // ==================================================================

    /** Un classeur Excel minimal : une ligne d'en-tête, puis $n lignes de ventes. */
    private function classeur(int $n): string
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $shared = ['ASIN', 'Pays', 'Ventes'];
        $data = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>';
        for ($i = 1; $i <= $n; $i++) {
            $r = $i + 1;
            $shared[] = 'B0' . str_pad((string)$i, 8, '0', STR_PAD_LEFT);
            $asin = count($shared) - 1;
            $pays = $i % 2 === 0 ? 'FR' : 'DE';
            if (!in_array($pays, $shared, true)) {
                $shared[] = $pays;
            }
            $p = array_search($pays, $shared, true);
            $data .= '<row r="' . $r . '"><c r="A' . $r . '" t="s"><v>' . $asin . '</v></c><c r="B' . $r . '" t="s"><v>' . $p . '</v></c><c r="C' . $r . '"><v>' . (100 + $i) . '</v></c></row>';
        }
        $base = tempnam(sys_get_temp_dir(), 'wfev');
        $path = $base . '.xlsx';
        rename($base, $path);
        $this->tmp[] = $path;
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $z->addFromString('xl/workbook.xml', '<workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheets><sheet name="Ventes Manuf" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="' . $pkg . '"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="' . $ns . '"><sheetData>' . $data . '</sheetData></worksheet>');
        $z->addFromString('xl/sharedStrings.xml', '<sst xmlns="' . $ns . '">' . implode('', array_map(static fn($s) => '<si><t>' . htmlspecialchars($s) . '</t></si>', $shared)) . '</sst>');
        $z->addFromString('xl/styles.xml', '<styleSheet xmlns="' . $ns . '"><cellXfs count="1"><xf numFmtId="0"/></cellXfs></styleSheet>');
        $z->close();
        return (string)file_get_contents($path);
    }

    /** L'email reçu, tel que le pose le déclencheur (WorkflowTriggers::emailContent). */
    private static function email(): array
    {
        return [
            'message_id' => self::MESSAGE_ID,
            'service'    => 'outlook',
            'fields'     => [
                'expediteur'     => 'martin@exemple.fr',
                'objet'          => '[Reporting Amazon EU] 2026-11-08 au 2026-11-14',
                'recu_le'        => '2026-11-23',
                'contenu'        => 'Reporting Amazon Europe. Fichier en pièce jointe.',
                'pieces_jointes' => ['Amazon_EU_reporting.xlsx', 'logo.png'],
            ],
            'summary'    => 'Email reçu dans Outlook de martin@exemple.fr.',
            'headline'   => '« [Reporting Amazon EU] » — martin@exemple.fr',
            'provenance' => ['Email de martin@exemple.fr'],
        ];
    }

    /** « Contrôler les données » : cite l'email reçu, ne consulte RIEN (comme chez le client). */
    private function workflow(): array
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fields' => [], 'event' => ['kind' => 'email', 'source' => 'outlook', 'query' => '',
                'filters' => ['mail' => ['subject' => '[Reporting Amazon EU]', 'attachment' => true]]]],
            'nodes' => [[
                'key' => 'controler', 'type' => 'step', 'title' => 'Contrôler les données',
                'instruction' => 'Examiner toutes les pièces jointes de @{evenement} et contrôler les ventes par pays.',
                'output' => ['key' => 'controle', 'name' => 'Contrôle', 'form' => 'text'],
            ]],
            'end' => ['pieces' => ['controle']],
        ]);
        $this->assertSame([], WorkflowSpec::node($def, 'controler')['sources'], 'Aucune source cochée : le cas constaté chez un client.');
        $id = Workflow::create($this->userId, 'Piloter Amazon', $def);
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf): array
    {
        $res = WorkflowEngine::launch($wf, User::findById($this->userId), [], [], 'email', null, 'Europe/Paris', self::email(), 'outlook:' . self::MESSAGE_ID);
        $this->assertIsArray($res);
        return $res;
    }

    private function drive(int $runId, array $reponses): array
    {
        FakeLlmStream::$queue = array_map(static fn(string $r): string => str_starts_with($r, 'data: ') ? $r : FakeLlmStream::sse($r), $reponses);
        for ($i = 0; $i < 30; $i++) {
            $run = WorkflowRun::find($runId);
            $stmt = $this->db()->prepare("SELECT id FROM async_jobs WHERE conversation_id = ? AND status = 'pending' ORDER BY id LIMIT 1");
            $stmt->execute([(int)$run['conversation_id']]);
            $jobId = (int)$stmt->fetchColumn();
            if ($jobId === 0) {
                break;
            }
            JobQueue::runById($jobId);
        }
        return WorkflowRun::find($runId);
    }

    private static function call(string $tool, array $args): string
    {
        return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => 0, 'id' => 'call_' . substr(md5($tool), 0, 6), 'type' => 'function',
                'function' => ['name' => $tool, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
            ]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
            . "data: [DONE]\n\n";
    }

    private function stepMessage(int $runId): array
    {
        $step = WorkflowRun::lastStep($runId, 'controler');
        return Message::findById((int)$step['detail']['user_msg_id']);
    }

    private function sourcesOutlook(): void
    {
        $this->outlook->listing = [
            ['numero' => 1, 'nom' => 'Amazon_EU_reporting.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'taille' => 40_000, 'ref' => 'a1', 'data' => '', 'en_ligne' => false, 'piece' => 'fichier'],
            ['numero' => 2, 'nom' => 'logo.png', 'type' => 'image/png', 'taille' => 3_000, 'ref' => 'a2', 'data' => '', 'en_ligne' => true, 'piece' => 'fichier'],
        ];
        $this->outlook->files = [1 => ['nom' => 'Amazon_EU_reporting.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bytes' => $this->classeur(240)]];
    }

    // ==================================================================
    // 1. Le classeur arrive à l'étape, et se compte en entier
    // ==================================================================

    public function testLEtapeRecoitLeClasseurEtLeRangeEnEntierDansUneTable(): void
    {
        $this->sourcesOutlook();
        $res = $this->lance($this->workflow());
        $run = $this->drive($res['run_id'], [
            self::call(WorkTableTools::COLLECT, ['source' => 'lignes_piece_jointe', 'table' => 'ventes', 'fichier' => 'Amazon_EU_reporting.xlsx']),
            '240 lignes contrôlées : 120 en France, 120 en Allemagne.',
        ]);
        $this->assertSame(WorkflowRun::DONE, $run['status']);

        // Le classeur est joint au message de l'étape, téléchargé une fois ; le logo de signature non.
        $msg = $this->stepMessage($run['id']);
        $files = json_decode((string)$msg['files_json'], true);
        $this->assertCount(1, $files);
        $this->assertSame('Amazon_EU_reporting.xlsx', $files[0]['name']);
        $this->assertTrue($files[0]['event']);
        $this->assertStringStartsWith(UPLOAD_DIR . 'wf_', $files[0]['path']);
        $this->assertFileExists($files[0]['path']);
        $this->assertStringContainsString('B000000240', $files[0]['extracted'], 'Texte extrait EN ENTIER, comme un fichier joint au chat.');
        $this->assertSame(1, $this->outlook->downloads);

        // Le modèle le sait, et a les outils pour compter : la table de travail porte les 240 lignes.
        $prompt = FakeLlmStream::promptOf(0);
        $this->assertStringContainsString('PIÈCES JOINTES DE « Email reçu »', $prompt);
        $this->assertStringContainsString('« Amazon_EU_reporting.xlsx » est jointe à ce message', $prompt);
        $tools = array_map(static fn(array $t): string => (string)($t['function']['name'] ?? ''), (array)(FakeLlmStream::$requests[0]['tools'] ?? []));
        $this->assertContains(WorkTableTools::COLLECT, $tools, 'Gros classeur joint : les outils de tables sont exposés sans rien cocher.');
        $this->assertStringContainsString('Amazon_EU_reporting.xlsx', json_encode(FakeLlmStream::$requests[0]['messages'] ?? [], JSON_UNESCAPED_UNICODE));
        $table = (new SqlWorkTableStore())->find((int)$run['conversation_id'], 'ventes');
        $this->assertNotNull($table);
        $this->assertSame(240, (int)$table['rows'], 'Toutes les lignes, pas un extrait.');

        // La trace dit d'où vient le fichier.
        $reply = Message::findById((int)WorkflowRun::lastStep($run['id'], 'controler')['detail']['reply_id']);
        $actions = json_decode((string)$reply['actions_json'], true);
        $this->assertSame('email_files', $actions[0]['t']);
        $this->assertSame('Amazon_EU_reporting.xlsx', $actions[0]['d']);
        $this->assertTrue($actions[0]['ok']);

        // Un job rejoué ne retélécharge rien : les fichiers déjà joints sont repris.
        $again = WorkflowEventFiles::attach(['service' => 'outlook', 'message_id' => self::MESSAGE_ID], $this->userId, (int)$msg['id']);
        $this->assertSame(['Amazon_EU_reporting.xlsx'], $again['files']);
        $this->assertSame(1, $this->outlook->downloads);
    }

    // ==================================================================
    // 2. Une panne se dit
    // ==================================================================

    public function testUnePanneDuServiceSeDitSansBloquerLEtape(): void
    {
        $this->outlook->fail = 'Outlook non connecté — reconnectez-le dans Réglages → Comptes connectés.';
        $res = $this->lance($this->workflow());
        $run = $this->drive($res['run_id'], ['Le classeur n’a pas pu être récupéré : Outlook n’est pas connecté.']);
        $this->assertSame(WorkflowRun::DONE, $run['status']);

        $this->assertNull($this->stepMessage($run['id'])['files_json']);
        $prompt = FakeLlmStream::promptOf(0);
        $this->assertStringContainsString('n\'ont pas pu être récupérées (Outlook non connecté', $prompt);
        $reply = Message::findById((int)WorkflowRun::lastStep($run['id'], 'controler')['detail']['reply_id']);
        $actions = json_decode((string)$reply['actions_json'], true);
        $this->assertSame('email_files', $actions[0]['t']);
        $this->assertFalse($actions[0]['ok'], 'Ligne ambre : la personne voit que le fichier manque.');
    }

    // ==================================================================
    // 3. Une étape qui ne lit pas l'email ne télécharge rien
    // ==================================================================

    public function testUneEtapeQuiNeCitePasLEmailNeTelechargeRien(): void
    {
        $this->sourcesOutlook();
        $def = WorkflowSpec::normalize([
            'start' => ['fields' => [], 'event' => ['kind' => 'email', 'source' => 'outlook', 'query' => '']],
            'nodes' => [['key' => 'controler', 'type' => 'step', 'title' => 'Saluer', 'instruction' => 'Écris bonjour.',
                'output' => ['key' => 'controle', 'name' => 'Salut', 'form' => 'text']]],
            'end' => ['pieces' => ['controle']],
        ]);
        $id = Workflow::create($this->userId, 'Saluer', $def);
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $res = $this->lance(Workflow::findById($id));
        $run = $this->drive($res['run_id'], ['Bonjour.']);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertSame(0, $this->outlook->downloads);
        $this->assertStringNotContainsString('PIÈCES JOINTES DE', FakeLlmStream::promptOf(0));
    }
}
