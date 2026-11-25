<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';
require_once __DIR__ . '/../Support/FakeLlmStream.php';

/**
 * Scénarios de RECETTE des workflows — à relancer à chaque évolution, pour
 * que les workflows restent fonctionnels de bout en bout.
 *
 * Ici rien n'est simulé côté moteur : chaque étape part en VRAI job de chat
 * (JobQueue::runById → ChatSendJob → ChatController::runSend), sur un faux
 * modèle OpenAI-compatible (FakeLlmStream) qui rend la réponse attendue ; la
 * fin du job relance le moteur (JobQueue::finish → WorkflowEngine::onJobFinished),
 * la pièce est lue dans la réponse, la brique suivante démarre. `drive()`
 * déroule ainsi toute l'exécution comme le ferait le worker.
 *
 * Couvre les retours de recette du 2026-11-04 :
 *  - une condition par règle prend la bonne voie (« Oui » → Si oui) ;
 *  - des règles contradictoires sont refusées avant de publier ;
 *  - le pré-contrôle bloque un lancement qui ne pourrait pas aller au bout ;
 *  - l'historique des versions (publication, relecture, repartir d'une version) ;
 *  - le document de référence d'un champ Fichier, lu par les étapes ;
 *  - ce que MIA sait de l'entreprise quand elle tranche une question ;
 *  - les décisions dans la discussion (carte de validation) et le volet
 *    d'avancement de la conversation.
 */
final class WorkflowRecetteScenariosTest extends DatabaseTestCase
{
    private const LLM = 'openai::o3';
    private array $reglagesAvant = [];
    private int $userId = 0;
    /** @var list<string> fichiers de référence créés par le test */
    private array $fichiers = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Controllers/WorkflowController.php';
        // Les jobs s'exécutent pour de vrai (comme le worker).
        foreach (['JobErrorException', 'JobContext', 'JobHandler', 'ChatJob', 'ChatSendJob', 'ImageJob', 'MapJob', 'HtmlPageJob', 'WorkflowJob'] as $job) {
            require_once ROOT . '/src/Jobs/' . $job . '.php';
        }
        $db = $this->db();
        $db->exec('DELETE FROM llm_models');
        $db->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach ([Workflow::SETTING_ENABLED, Workflow::SETTING_AUTO, 'default_llm', 'connector_web_enabled', 'job_worker_heartbeat_at',
                     CompanyProfile::SETTING_DESCRIPTION, 'company_name', SsoRegistry::SETTING_GOOGLE_CLIENT, SsoRegistry::SETTING_GOOGLE_SECRET,
                     'user_gmail_enabled', 'memory_enabled', 'memory_questions_enabled', 'memory_learn_enabled', 'composer_suggestions_enabled'] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(Workflow::SETTING_ENABLED, '1');
        Settings::set('connector_web_enabled', '1');
        // Aucun tour annexe (mémoire, question, suggestion) : chaque appel au
        // faux modèle est une étape du workflow.
        Settings::set('memory_enabled', '0');
        Settings::set('memory_questions_enabled', '0');
        Settings::set('memory_learn_enabled', '0');
        Settings::set(CompanyProfile::SETTING_DESCRIPTION, '');
        LlmCatalog::setToday('2026-11-05');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        Settings::set('default_llm', self::LLM);
        FakeLlmStream::install();
        // Worker « vivant » : JobQueue::dispatch() ne lance rien — c'est drive() qui exécute.
        JobQueue::markWorkerAlive();
        $this->userId = $this->insertUser(7, 'creator');
        I18n::use('fr');
        WorkflowAi::overrideCompletion(null);
    }

    protected function tearDown(): void
    {
        WorkflowAi::overrideCompletion(null);
        ChatController::setEmitSink(null);
        LlmCatalog::setToday(null);
        LlmCatalog::flushCache();
        foreach ($this->fichiers as $f) {
            @unlink($f);
        }
        if (self::$pdo !== null) {
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

    private function publie(array $def, string $nom = 'Recette'): array
    {
        $id = Workflow::create($this->userId, $nom, WorkflowSpec::normalize($def));
        $wf = Workflow::findById($id);
        $issues = WorkflowSpec::issues($wf['draft'], WorkflowCatalog::choices(User::findById($this->userId)));
        $this->assertTrue(WorkflowSpec::publishable($issues), 'Publiable : ' . json_encode(array_column($issues, 'text'), JSON_UNESCAPED_UNICODE));
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Workflow::findById($id);
    }

    private function lance(array $wf, array $values, array $files = []): array
    {
        $user = User::findById($this->userId);
        WorkflowEngine::assertReady($wf, $user);
        $res = WorkflowEngine::launch($wf, $user, $values, $files, 'manual', null, 'Europe/Paris');
        $this->assertIsArray($res);
        return $res;
    }

    /**
     * Déroule l'exécution comme le worker : chaque job en attente de la
     * conversation est exécuté pour de vrai, jusqu'à ce qu'il n'y en ait plus.
     * Les réponses du faux modèle sont prises dans l'ordre de `$reponses`.
     */
    private function drive(int $runId, array $reponses = []): array
    {
        FakeLlmStream::$queue = array_map(static fn(string $r): string => FakeLlmStream::sse($r), $reponses);
        for ($i = 0; $i < 40; $i++) {
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

    private function etape(int $runId, string $cle): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM workflow_run_steps WHERE run_id = ? AND node_key = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$runId, $cle]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function messages(int $convId): string
    {
        $stmt = $this->db()->prepare('SELECT content FROM messages WHERE conversation_id = ? ORDER BY id');
        $stmt->execute([$convId]);
        return implode("\n---\n", $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** État de l'exécution tel que le calcule la page (et le volet d'avancement). */
    private function etat(int $runId): array
    {
        $run = WorkflowRun::find($runId);
        $ctl = new WorkflowController();
        return (fn(array $r): array => $this->runState($r))->call($ctl, $run);
    }

    /** Départ : un client et une réponse à choix ; une analyse, puis la condition « On continue ? ». */
    private static function definitionOnContinue(array $rule, string $noThen = 'stop'): array
    {
        return [
            'objective' => 'Répondre au client si on continue.',
            'start' => ['fiche_name' => 'Informations de départ', 'fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
                ['key' => 'reponse', 'label' => 'Départ', 'type' => 'choice', 'required' => true, 'options' => ['Oui', 'Non', 'Peut-être']],
            ]],
            'nodes' => [
                ['key' => 'analyse', 'type' => 'step', 'title' => 'Analyser', 'instruction' => 'Analyse la demande de @{depart}.',
                    'output' => ['key' => 'analyse_txt', 'name' => 'Analyse', 'form' => 'text']],
                ['key' => 'go', 'type' => 'condition', 'title' => 'On continue ?', 'mode' => 'rule', 'rule' => $rule,
                    'yes' => ['then' => 'continue', 'nodes' => [
                        ['key' => 'page', 'type' => 'step', 'title' => 'Rédiger la réponse', 'instruction' => 'Rédige la réponse à partir de @{analyse_txt}.',
                            'output' => ['key' => 'reponse_txt', 'name' => 'Réponse', 'form' => 'text']],
                    ]],
                    'no' => ['then' => $noThen, 'nodes' => [
                        ['key' => 'note', 'type' => 'step', 'title' => 'Noter le refus', 'instruction' => 'Note pourquoi on ne continue pas.',
                            'output' => ['key' => 'refus_txt', 'name' => 'Refus', 'form' => 'text']],
                    ]]],
            ],
            'end' => ['pieces' => ['analyse_txt']],
        ];
    }

    // ==================================================================
    // 1. Condition par règle : la bonne voie
    // ==================================================================

    public function testUneReglePrendLaVoieSiOuiQuandOnRepondOui(): void
    {
        $wf = $this->publie(self::definitionOnContinue(['join' => 'all', 'items' => [
            ['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'],
        ]]));
        $res = $this->lance($wf, ['client' => 'Ville de Lyon', 'reponse' => 'Oui']);
        $run = $this->drive($res['run_id'], [
            'Analyse : la Ville de Lyon demande une réponse rapide sur le marché de voirie.',
            'Réponse rédigée pour la Ville de Lyon, prête à partir.',
        ]);

        $this->assertSame(WorkflowRun::DONE, $run['status'], 'L’exécution va au livrable.');
        $go = $this->etape($run['id'], 'go');
        $this->assertSame('yes', $go['outcome'], '« Oui » saisi → voie « Si oui ».');
        $this->assertNotNull($this->etape($run['id'], 'page'), 'La brique de la voie « Si oui » a tourné.');
        $this->assertNull($this->etape($run['id'], 'note'), 'Celle de « Si non », jamais.');
        $this->assertCount(2, FakeLlmStream::$requests, 'Deux étapes, deux appels au modèle.');
        $this->assertStringContainsString('Ville de Lyon', FakeLlmStream::promptOf(0), 'L’étape lit la Fiche de départ.');

        // Le volet d'avancement de la conversation montre la voie prise, dans sa couleur.
        $p = WorkflowProgress::view($run['definition'], $this->etat($run['id']), ['run_id' => $run['id'], 'name' => 'Recette', 'color' => '#6366f1', 'version' => 1]);
        $rows = array_column($p['rows'], null, 'key');
        $this->assertSame('Oui', $rows['go']['note']);
        $this->assertSame(0, $rows['go']['lane'], 'Couleur de la première voie (« Si oui »).');
        $this->assertArrayHasKey('go:yes', $rows, 'La voie prise est nommée.');
        $this->assertArrayHasKey('page', $rows);
        $this->assertArrayNotHasKey('note', $rows, 'La voie écartée n’encombre pas le volet.');
        $this->assertSame(['kind' => 'done'], $p['attention']);
        $this->assertTrue($p['terminal']);
        $this->assertSame(0, $p['poll'], 'Exécution finie : le volet cesse de sonder.');
    }

    public function testUneReglePrendLaVoieSiNonEtSArreteQuandOnRepondNon(): void
    {
        $wf = $this->publie(self::definitionOnContinue(['join' => 'all', 'items' => [
            ['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'],
        ]]));
        $res = $this->lance($wf, ['client' => 'Ville de Lyon', 'reponse' => 'Non']);
        $run = $this->drive($res['run_id'], [
            'Analyse : la demande ne correspond pas à ce que nous faisons.',
            'Refus noté : hors de notre périmètre cette fois.',
        ]);

        $this->assertSame('no', $this->etape($run['id'], 'go')['outcome']);
        $this->assertNotNull($this->etape($run['id'], 'note'), 'La voie « Si non » a tourné.');
        $this->assertNull($this->etape($run['id'], 'page'));
        $this->assertSame(WorkflowRun::STOPPED, $run['status'], 'La voie « Si non » arrête le workflow comme prévu.');
        $rows = array_column(WorkflowProgress::rows($run['definition'], $this->etat($run['id'])), null, 'key');
        $this->assertSame('Non', $rows['go']['note']);
        $this->assertSame(1, $rows['go']['lane'], 'Couleur de la deuxième voie (« Si non »).');
    }

    public function testLeVerdictDUneRegleSuitLaCasseEtLesAccents(): void
    {
        $wf = $this->publie(self::definitionOnContinue(['join' => 'all', 'items' => [
            ['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'peut-etre'],
        ]]));
        $avis = implode(' ', array_column(WorkflowSpec::issues($wf['draft'], WorkflowCatalog::choices(User::findById($this->userId))), 'text'));
        $this->assertStringNotContainsString('n’est pas un des choix', $avis, '« peut-etre » vaut « Peut-être » : casse et accents ignorés.');
        $res = $this->lance($wf, ['client' => 'Ville de Lyon', 'reponse' => 'Peut-être']);
        $run = $this->drive($res['run_id'], ['Analyse prudente : à confirmer avec le client.', 'Réponse rédigée.']);
        $this->assertSame('yes', $this->etape($run['id'], 'go')['outcome']);
    }

    // ==================================================================
    // 2. Règles contradictoires : refusées avant de publier
    // ==================================================================

    public function testDesReglesContradictoiresSontRefuseesAvantDePublier(): void
    {
        // Le cas du retour de recette : Oui, Non, Peut-être, « Toutes les conditions ».
        $def = WorkflowSpec::normalize(self::definitionOnContinue(['join' => 'all', 'items' => [
            ['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Oui'],
            ['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Non'],
            ['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Peut-être'],
        ]]));
        $issues = WorkflowSpec::issues($def, WorkflowCatalog::choices(User::findById($this->userId)));
        $this->assertFalse(WorkflowSpec::publishable($issues), 'Une règle jamais vraie ne se publie pas.');
        $texte = implode(' ', array_column($issues, 'text'));
        $this->assertStringContainsString('ne peut pas valoir à la fois', $texte);
        $this->assertStringContainsString('« Au moins une »', $texte, 'Le message dit quoi faire.');

        // « Au moins une » : la même règle devient juste, et chaque réponse prend la bonne voie.
        $def['nodes'][1]['rule']['join'] = 'any';
        $this->assertTrue(WorkflowSpec::publishable(WorkflowSpec::issues($def, WorkflowCatalog::choices(User::findById($this->userId)))));

        // Une valeur qui n'est pas une option du Choix : signalée.
        $bad = WorkflowSpec::normalize(self::definitionOnContinue(['join' => 'all', 'items' => [
            ['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Oui merci'],
        ]]));
        $warn = implode(' ', array_column(WorkflowSpec::issues($bad, WorkflowCatalog::choices(User::findById($this->userId))), 'text'));
        $this->assertStringContainsString('n’est pas un des choix', $warn);

        // Une condition accepte désormais jusqu'à huit lignes.
        $this->assertSame(8, WorkflowSpec::MAX_RULE_ITEMS);
        $pieces = WorkflowSpec::pieces($def);
        $this->assertSame(['Oui', 'Non', 'Peut-être'], $pieces['depart']['fields'][1]['options'], 'Les options du Choix sont connues de la règle.');
    }

    // ==================================================================
    // 3. Pré-contrôle : rien ne part qui ne pourrait aller au bout
    // ==================================================================

    public function testLePreControleBloqueUnLancementSansLeCompteDeLivraison(): void
    {
        // Gmail proposé sur l'instance, mais pas relié par la personne.
        Settings::set(SsoRegistry::SETTING_GOOGLE_CLIENT, 'client-de-test.apps.googleusercontent.com');
        Settings::set(SsoRegistry::SETTING_GOOGLE_SECRET, Encryption::encrypt('secret-de-test'));
        Settings::set('user_gmail_enabled', '1');
        $def = [
            'objective' => 'Envoyer la synthèse.',
            'start' => ['fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true]]],
            'nodes' => [['key' => 'synth', 'type' => 'step', 'title' => 'Synthétiser', 'instruction' => 'Résume @{depart}.',
                'output' => ['key' => 'synthese', 'name' => 'Synthèse', 'form' => 'text']]],
            'end' => ['pieces' => ['synthese'], 'deliveries' => [
                ['type' => 'email', 'source' => 'gmail', 'to' => 'acheteur@example.com', 'subject' => 'Synthèse', 'message' => 'Ci-joint.', 'attach' => ['synthese'], 'mode' => 'approve'],
            ]],
        ];
        // Publié quand tout allait bien : on le pose directement, comme une version d'avant.
        $id = Workflow::create($this->userId, 'Synthèse envoyée', WorkflowSpec::normalize($def));
        Workflow::publish($id, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $wf = Workflow::findById($id);
        $user = User::findById($this->userId);

        $items = WorkflowEngine::preflight($wf, $user);
        $this->assertNotSame([], $items, 'Le compte Gmail manquant est vu AVANT le lancement.');
        $gmail = array_values(array_filter($items, static fn(array $i): bool => ($i['connect'] ?? '') === 'gmail'));
        $this->assertNotSame([], $gmail, 'Le point dit quel compte relier.');
        $this->assertSame('/settings/connections#connector-gmail', $gmail[0]['url']);
        $this->assertSame('Livrable', $gmail[0]['brick'], 'Et sur quelle brique.');

        try {
            WorkflowEngine::assertReady($wf, $user);
            $this->fail('Le lancement aurait dû être refusé.');
        } catch (WorkflowPreflightException $e) {
            $this->assertStringContainsString('ne peut pas démarrer', $e->getMessage());
            $this->assertNotSame([], $e->items());
        }
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM workflow_runs')->fetchColumn(), 'Rien n’a démarré, rien n’est facturé.');
        $this->assertSame(0, count(FakeLlmStream::$requests));

        // Programmé : le créneau passe sans rien lancer (la personne est prévenue).
        $sched = $def;
        $sched['start']['fields'][0]['default'] = 'Client du lundi';
        $sched['start']['fields'][0]['required'] = false;
        $sched['start']['schedule'] = ['rule' => ['freq' => 'daily', 'time' => '08:00'], 'timezone' => 'Europe/Paris'];
        $sid = Workflow::create($this->userId, 'Synthèse du matin', WorkflowSpec::normalize($sched));
        Workflow::publish($sid, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->db()->prepare('UPDATE workflows SET schedule_next_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 60), $sid]);
        WorkflowEngine::tick(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), false);
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM workflow_runs')->fetchColumn(), 'Le créneau programmé ne lance rien de bancal.');
        $this->assertNotNull(Workflow::findById($sid)['schedule_next_at'], 'Le créneau suivant reste prévu.');

        // Une fois la livraison retirée, le pré-contrôle laisse partir.
        $ok = $def;
        $ok['end']['deliveries'] = [];
        $okId = Workflow::create($this->userId, 'Synthèse seule', WorkflowSpec::normalize($ok));
        Workflow::publish($okId, $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->assertSame([], WorkflowEngine::preflight(Workflow::findById($okId), $user));
    }

    // ==================================================================
    // 4. Historique des versions
    // ==================================================================

    public function testChaqueVersionPublieeSeRevoitEtLeBrouillonPeutEnRepartir(): void
    {
        $v1 = self::definitionOnContinue(['join' => 'all', 'items' => [['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Oui']]]);
        $wf = $this->publie($v1, 'Versionné');
        $v2 = $v1;
        $v2['nodes'][0]['title'] = 'Analyser en détail';
        Workflow::saveDraft($wf['id'], $this->userId, 'Versionné', '#6366f1', WorkflowSpec::normalize($v2));
        $this->assertSame(2, Workflow::publish($wf['id'], $this->userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));

        $list = Workflow::versions($wf['id'], $this->userId);
        $this->assertSame([2, 1], array_column($list, 'version'), 'De la plus récente à la plus ancienne.');
        $this->assertSame(4, $list[0]['bricks']);
        $this->assertFalse($list[0]['recovered']);
        $this->assertSame([], Workflow::versions($wf['id'], 999), 'Cloisonné à la personne.');

        $old = Workflow::version($wf['id'], $this->userId, 1);
        $this->assertSame('Analyser', $old['definition']['nodes'][0]['title'], 'La version 1 est restée telle quelle.');

        // Repartir de la version 1 : le brouillon change, la version en service non.
        Workflow::saveDraft($wf['id'], $this->userId, 'Versionné', '#6366f1', $old['definition']);
        $fresh = Workflow::findById($wf['id']);
        $this->assertSame('Analyser', $fresh['draft']['nodes'][0]['title']);
        $this->assertSame('Analyser en détail', $fresh['published']['nodes'][0]['title'], 'En service : toujours la version 2.');
        $this->assertSame(2, (int)$fresh['published_version']);
        $this->assertTrue((bool)$fresh['draft_changed']);
    }

    public function testLaMigrationRetrouveLesVersionsPasseesDansLesExecutions(): void
    {
        $wf = $this->publie(self::definitionOnContinue(['join' => 'all', 'items' => [['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Oui']]]), 'Ancien');
        $this->lance($wf, ['client' => 'A', 'reponse' => 'Oui']);
        // Avant la migration : aucune ligne d'historique.
        $this->db()->exec('DELETE FROM workflow_versions');
        $sql = (string)file_get_contents(ROOT . '/database/migrations/102_workflow_versions.sql');
        $inserts = array_filter(array_map('trim', explode(';', (string)preg_replace('/^--.*$/m', '', $sql))), static fn(string $q): bool => str_starts_with($q, 'INSERT'));
        $this->assertCount(2, $inserts);
        foreach ($inserts as $q) {
            $this->db()->exec($q);
        }
        $list = Workflow::versions($wf['id'], $this->userId);
        $this->assertSame([1], array_column($list, 'version'), 'La version publiée est reprise (et pas en double depuis son exécution).');
        $this->assertSame(4, $list[0]['bricks']);
    }

    // ==================================================================
    // 5. Document de référence d'un champ Fichier
    // ==================================================================

    public function testUnDocumentDeReferenceEstJointDOfficeEtLuParLEtape(): void
    {
        $ref = 'wfref_' . bin2hex(random_bytes(16)) . '.txt';
        if (!is_dir(UPLOAD_DIR)) {
            @mkdir(UPLOAD_DIR, 0775, true);
        }
        file_put_contents(UPLOAD_DIR . $ref, "Nos références : 12 marchés de voirie depuis 2019, dont Lyon et Grenoble.\nNotre offre : entretien et signalisation.");
        $this->fichiers[] = UPLOAD_DIR . $ref;
        $wf = $this->publie([
            'objective' => 'Une réponse qui s’appuie sur nos références.',
            'start' => ['fields' => [
                ['key' => 'demande', 'label' => 'Demande', 'type' => 'text', 'required' => true, 'default' => 'Entretien courant'],
                ['key' => 'references', 'label' => 'Nos références', 'type' => 'file', 'required' => true,
                    'reference' => ['ref' => $ref, 'name' => 'references.txt', 'type' => 'text/plain', 'size' => 120]],
            ], 'schedule' => ['rule' => ['freq' => 'weekly', 'days' => [1], 'time' => '08:00'], 'timezone' => 'Europe/Paris']],
            'nodes' => [['key' => 'rep', 'type' => 'step', 'title' => 'Répondre', 'instruction' => 'Réponds à @{depart} en t’appuyant sur @{references}.',
                'output' => ['key' => 'reponse', 'name' => 'Réponse', 'form' => 'text']]],
            'end' => ['pieces' => ['reponse']],
        ], 'Avec références');
        $this->assertSame($ref, $wf['published']['start']['fields'][1]['reference']['ref'], 'Le document est gardé dans la définition (par son nom, jamais un chemin).');

        $res = $this->lance($wf, ['demande' => 'Entretien de la voirie de Lyon']);
        $run = $this->drive($res['run_id'], ['Réponse : forts de 12 marchés de voirie depuis 2019, nous proposons…']);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $art = WorkflowRun::artifacts($run['id'])['references'] ?? null;
        $this->assertNotNull($art, 'La pièce du document de référence existe sans rien déposer.');
        $this->assertSame('references.txt', $art['content']['name']);
        $this->assertStringContainsString('12 marchés de voirie', FakeLlmStream::promptOf(0), 'L’étape lit le document de référence cité.');

        // Une définition forgée ne peut pas viser un autre fichier que les siens.
        $this->assertNull(WorkflowSpec::referenceFile(['ref' => '../../.env', 'name' => 'x']));
        $this->assertNull(WorkflowSpec::referenceFile(['ref' => 'abc.pdf']));
    }

    // ==================================================================
    // 6. Ce que MIA sait de l'entreprise quand elle tranche
    // ==================================================================

    public function testUneQuestionAMiaSAppuieSurLaPresentationDeLEntreprise(): void
    {
        Settings::set(CompanyProfile::SETTING_DESCRIPTION, 'Nous entretenons la voirie des collectivités : marquage, signalisation, nids-de-poule.');
        $vus = [];
        WorkflowAi::overrideCompletion(static function (string $system, string $user) use (&$vus): string {
            $vus[] = $user;
            return '{"reponse": "oui", "raison": "La demande porte sur la signalisation, que nous faisons."}';
        });
        $wf = $this->publie([
            'objective' => 'Trier les appels d’offres.',
            'start' => ['fields' => [['key' => 'ao', 'label' => 'Appel d’offres', 'type' => 'longtext', 'required' => true]]],
            'nodes' => [
                ['key' => 'go', 'type' => 'condition', 'title' => 'On y va ?', 'mode' => 'question', 'reads' => ['depart'], 'doubt' => 'ask',
                    'question' => 'Cet appel d’offres correspond-il à notre offre ?',
                    'yes' => ['then' => 'continue', 'nodes' => []], 'no' => ['then' => 'stop', 'nodes' => []]],
                ['key' => 'rep', 'type' => 'step', 'title' => 'Préparer la réponse', 'instruction' => 'Prépare la réponse à @{depart}.',
                    'output' => ['key' => 'reponse', 'name' => 'Réponse', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['reponse']],
        ], 'Tri des AO');
        $res = $this->lance($wf, ['ao' => 'Marché de signalisation horizontale de la métropole.']);
        $run = $this->drive($res['run_id'], ['Réponse préparée : notre méthode de marquage…']);
        $this->assertSame('yes', $this->etape($run['id'], 'go')['outcome']);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertCount(1, $vus);
        $this->assertStringContainsString('Nous entretenons la voirie des collectivités', $vus[0], 'MIA sait ce qu’est « notre offre ».');
        $this->assertStringContainsString('Marché de signalisation horizontale', $vus[0], 'Et lit la pièce citée.');
    }

    // ==================================================================
    // 7. Décider dans la discussion
    // ==================================================================

    public function testUneValidationSeDecideDansLaDiscussionEtPeutRenvoyerLEtape(): void
    {
        $wf = $this->publie([
            'objective' => 'Un mémoire relu.',
            'start' => ['fields' => [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'memoire', 'type' => 'step', 'title' => 'Rédiger le mémoire', 'instruction' => 'Rédige le mémoire sur @{depart}.',
                    'output' => ['key' => 'memoire_txt', 'name' => 'Mémoire', 'form' => 'text']],
                ['key' => 'relecture', 'type' => 'gate', 'title' => 'Relecture', 'reviews' => ['memoire_txt'], 'can_return' => true, 'can_correct' => true],
            ],
            'end' => ['pieces' => ['memoire_txt']],
        ], 'Mémoire relu');
        $res = $this->lance($wf, ['sujet' => 'Entretien de la voirie']);
        $run = $this->drive($res['run_id'], ['Mémoire : première version, trop courte sur les délais.']);
        $this->assertSame(WorkflowRun::WAITING, $run['status'], 'La validation attend la personne.');

        // La carte de la conversation porte de quoi renvoyer l'étape, sans quitter la discussion.
        $card = DrivePendingAction::pendingForConversation((int)$run['conversation_id'], $this->userId)[0] ?? null;
        $this->assertNotNull($card);
        $this->assertSame(['run' => $run['id'], 'kind' => 'gate', 'can_return' => true], $card['preview']['wf']);

        // Le volet le dit, et pointe la carte.
        $p = WorkflowProgress::view($run['definition'], $this->etat($run['id']), ['run_id' => $run['id'], 'name' => 'Mémoire relu', 'color' => '#6366f1', 'version' => 1]);
        $this->assertSame('gate', $p['attention']['kind']);
        $this->assertSame((int)$card['id'], $p['attention']['action_id']);
        $this->assertTrue($p['attention']['can_return']);
        $this->assertSame(WorkflowProgress::POLL_WAITING_MS, $p['poll']);

        // Renvoyer avec une remarque : l'étape est refaite, la remarque dans sa consigne.
        $gateStep = array_values(array_filter(WorkflowRun::steps($run['id']),
            static fn(array $s): bool => $s['node_key'] === 'relecture' && $s['status'] === WorkflowRun::STEP_WAITING))[0];
        DrivePendingAction::resolve((int)$card['id'], 'cancelled', 'Renvoyée à l’étape.');
        $jobs = WorkflowEngine::gateResolved($run, $gateStep, 'returned', 'Détaille les délais de chaque phase.');
        $this->assertNotSame([], $jobs, 'L’étape renvoyée repart.');
        $run = $this->drive($run['id'], ['Mémoire : version détaillée, délais phase par phase.']);
        $this->assertCount(2, FakeLlmStream::$requests, 'L’étape est refaite une fois.');
        $this->assertStringContainsString('Détaille les délais de chaque phase.', FakeLlmStream::promptOf(1), 'La remarque guide la nouvelle version.');
        $this->assertSame(WorkflowRun::WAITING, $run['status'], 'La relecture revient.');

        // Approuver dans la carte : le workflow va au bout.
        $card2 = DrivePendingAction::pendingForConversation((int)$run['conversation_id'], $this->userId)[0];
        DrivePendingAction::resolve((int)$card2['id'], 'executed');
        WorkflowEngine::onCardDecided((int)$run['conversation_id'], (int)$card2['id'], false);
        $run = $this->drive($run['id']);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertStringContainsString('Validation', $this->messages((int)$run['conversation_id']));
    }

    public function testQuandMiaHesiteSurUneDecisionLaCarteProposeChaqueVoie(): void
    {
        WorkflowAi::overrideCompletion(static fn(): string => '{"voie": "aucune", "plus_proche": 1, "raison": "La demande mélange un devis et une réclamation."}');
        $wf = $this->publie([
            'objective' => 'Aiguiller les demandes.',
            'start' => ['fields' => [['key' => 'demande', 'label' => 'Demande', 'type' => 'longtext', 'required' => true]]],
            'nodes' => [['key' => 'aig', 'type' => 'route', 'title' => 'Aiguiller', 'instruction' => 'Lis @{depart} et choisis.', 'reads' => ['depart'], 'fallback' => 'ask',
                'routes' => [
                    ['key' => 'devis', 'label' => 'Devis', 'when' => 'une demande de prix', 'nodes' => [], 'then' => 'continue'],
                    ['key' => 'reclamation', 'label' => 'Réclamation', 'when' => 'un mécontentement', 'nodes' => [], 'then' => 'continue'],
                ]]],
            'end' => ['pieces' => []],
        ], 'Aiguillage');
        $res = $this->lance($wf, ['demande' => 'Votre devis était faux et je veux un nouveau prix.']);
        $run = $this->drive($res['run_id']);
        $this->assertSame(WorkflowRun::WAITING, $run['status']);
        $card = DrivePendingAction::pendingForConversation((int)$run['conversation_id'], $this->userId)[0];
        $this->assertSame('route', $card['preview']['wf']['kind']);
        $this->assertSame('devis', $card['preview']['wf']['suggested']);
        $this->assertSame(['devis', 'reclamation'], array_column($card['preview']['wf']['routes'], 'key'), 'Toutes les voies, dans la discussion.');
    }

    public function testUnEchecSeReprendDepuisLeVoletDeLaConversation(): void
    {
        $wf = $this->publie([
            'objective' => 'Une fiche.',
            'start' => ['fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true]]],
            'nodes' => [['key' => 'fiche', 'type' => 'step', 'title' => 'Remplir la fiche', 'instruction' => 'Remplis la fiche de @{depart}.',
                'on_error' => ['retries' => 0, 'then' => 'stop'],
                'output' => ['key' => 'fiche_client', 'name' => 'Fiche client', 'form' => 'fiche', 'fields' => [
                    ['key' => 'secteur', 'label' => 'Secteur', 'type' => 'text'],
                ]]]],
            'end' => ['pieces' => ['fiche_client']],
        ], 'Fiche');
        $res = $this->lance($wf, ['client' => 'Acme']);
        // Deux réponses sans bloc JSON : la seconde tentative échoue aussi.
        $run = $this->drive($res['run_id'], ['Je ne sais pas.', 'Toujours rien.']);
        $this->assertSame(WorkflowRun::FAILED, $run['status']);
        $p = WorkflowProgress::view($run['definition'], $this->etat($run['id']), ['run_id' => $run['id'], 'name' => 'Fiche', 'color' => '#6366f1', 'version' => 1]);
        $this->assertSame('failed', $p['attention']['kind']);
        $this->assertTrue($p['attention']['resumable'], 'Le volet propose de reprendre.');
        $rows = array_column($p['rows'], null, 'key');
        $this->assertSame('err', $rows['fiche']['state']);

        // Reprendre : l'étape repart, cette fois la fiche arrive.
        WorkflowEngine::retry($run, false);
        $run = $this->drive($run['id'], ["Fiche remplie.\n\n```json\n{\"secteur\": \"Industrie\"}\n```"]);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
    }

    // ==================================================================
    // 8. Boucle, décision de MIA, attente
    // ==================================================================

    public function testUneBouclePourChaqueTraiteChaqueElementPuisRassemble(): void
    {
        $wf = $this->publie([
            'objective' => 'Une réponse par exigence.',
            'start' => ['fields' => [['key' => 'dossier', 'label' => 'Dossier', 'type' => 'longtext', 'required' => true]]],
            'nodes' => [
                ['key' => 'lister', 'type' => 'step', 'title' => 'Lister les exigences', 'instruction' => 'Liste les exigences de @{depart}.',
                    'output' => ['key' => 'exigences', 'name' => 'Exigences', 'form' => 'list']],
                ['key' => 'chaque', 'type' => 'loop', 'title' => 'Répondre à chaque exigence', 'mode' => 'each', 'over' => 'exigences', 'max' => 10,
                    'item' => ['key' => 'exigence', 'name' => 'Exigence en cours'], 'collect' => 'rep',
                    'output' => ['key' => 'reponses', 'name' => 'Réponses aux exigences'],
                    'body' => [['key' => 'repondre', 'type' => 'step', 'title' => 'Répondre', 'instruction' => 'Réponds à @{exigence}.',
                        'output' => ['key' => 'rep', 'name' => 'Réponse à l’exigence', 'form' => 'text']]]],
            ],
            'end' => ['pieces' => ['reponses']],
        ], 'Exigences');
        $res = $this->lance($wf, ['dossier' => 'Le marché exige un délai, une garantie et un rapport mensuel.']);
        $run = $this->drive($res['run_id'], [
            "- Délai d’intervention de 48 h\n- Garantie de deux ans\n- Rapport mensuel",
            'Nous intervenons sous 48 h ouvrées, astreinte comprise.',
            'Nos ouvrages sont garantis deux ans, pièces et main-d’œuvre.',
            'Un rapport mensuel détaillé est remis le 5 de chaque mois.',
        ]);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertCount(4, FakeLlmStream::$requests, 'Une liste, puis un tour par exigence.');
        $this->assertStringContainsString('Garantie de deux ans', FakeLlmStream::promptOf(2), 'Chaque tour lit SON élément.');
        $rendu = ArtifactContract::render(WorkflowSpec::pieces($run['definition'])['reponses'], WorkflowRun::artifacts($run['id'])['reponses']['content'], 20000);
        foreach (['48 h ouvrées', 'garantis deux ans', 'rapport mensuel détaillé'] as $bout) {
            $this->assertStringContainsString($bout, $rendu, 'La pièce rassemblée reprend chaque réponse.');
        }
        $rows = array_column(WorkflowProgress::rows($run['definition'], $this->etat($run['id'])), null, 'key');
        $this->assertSame('3 / 3', $rows['chaque']['note'], 'Le volet compte les éléments traités.');
    }

    public function testUneDecisionDeMiaPrendLaVoieChoisie(): void
    {
        WorkflowAi::overrideCompletion(static fn(): string => '{"voie": 2, "plus_proche": 2, "raison": "Le client se plaint d’un retard."}');
        $wf = $this->publie([
            'objective' => 'Traiter chaque demande.',
            'start' => ['fields' => [['key' => 'demande', 'label' => 'Demande', 'type' => 'longtext', 'required' => true]]],
            'nodes' => [['key' => 'aig', 'type' => 'route', 'title' => 'Aiguiller', 'instruction' => 'Lis @{depart}.', 'reads' => ['depart'], 'fallback' => 'ask',
                'routes' => [
                    ['key' => 'devis', 'label' => 'Devis', 'when' => 'une demande de prix', 'nodes' => [
                        ['key' => 'prix', 'type' => 'step', 'title' => 'Chiffrer', 'instruction' => 'Chiffre @{depart}.', 'output' => ['key' => 'chiffrage', 'name' => 'Chiffrage', 'form' => 'text']],
                    ], 'then' => 'continue'],
                    ['key' => 'reclamation', 'label' => 'Réclamation', 'when' => 'un mécontentement', 'nodes' => [
                        ['key' => 'excuse', 'type' => 'step', 'title' => 'Répondre à la réclamation', 'instruction' => 'Réponds à @{depart}.', 'output' => ['key' => 'excuses', 'name' => 'Réponse', 'form' => 'text']],
                    ], 'then' => 'continue'],
                ]]],
            'end' => ['pieces' => []],
        ], 'Aiguillage choisi');
        $res = $this->lance($wf, ['demande' => 'Votre équipe est arrivée avec trois jours de retard.']);
        $run = $this->drive($res['run_id'], ['Nous vous présentons nos excuses pour ce retard et vous proposons un geste commercial.']);
        $this->assertSame(WorkflowRun::DONE, $run['status']);
        $this->assertSame('reclamation', $this->etape($run['id'], 'aig')['outcome']);
        $this->assertNotNull($this->etape($run['id'], 'excuse'));
        $this->assertNull($this->etape($run['id'], 'prix'));
        $rows = array_column(WorkflowProgress::rows($run['definition'], $this->etat($run['id'])), null, 'key');
        $this->assertSame('Réclamation', $rows['aig']['note']);
        $this->assertSame(1, $rows['aig']['lane'], 'La couleur de la deuxième voie.');
    }

    public function testUneAttenteReprendSeuleALEcheance(): void
    {
        $wf = $this->publie([
            'objective' => 'Relancer plus tard.',
            'start' => ['fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true]]],
            'nodes' => [
                ['key' => 'attente', 'type' => 'wait', 'title' => 'Attendre deux jours', 'mode' => 'delay', 'amount' => 2, 'unit' => 'days'],
                ['key' => 'relance', 'type' => 'step', 'title' => 'Relancer', 'instruction' => 'Écris la relance pour @{depart}.',
                    'output' => ['key' => 'relance_txt', 'name' => 'Relance', 'form' => 'text']],
            ],
            'end' => ['pieces' => ['relance_txt']],
        ], 'Relance');
        $res = $this->lance($wf, ['client' => 'Acme']);
        $run = $this->drive($res['run_id']);
        $this->assertSame(WorkflowRun::WAITING, $run['status'], 'L’attente commence.');
        $p = WorkflowProgress::view($run['definition'], $this->etat($run['id']), ['run_id' => $run['id'], 'name' => 'Relance', 'color' => '#6366f1', 'version' => 1]);
        $this->assertSame('timer', $p['attention']['kind'], 'Le volet dit quand il reprendra.');
        $this->assertNotSame('', $p['attention']['at']);

        WorkflowEngine::tickTimers(new \DateTimeImmutable('+3 days', new \DateTimeZone('UTC')), false);
        $run = $this->drive($run['id'], ['Relance : bonjour, avez-vous pu consulter notre proposition ?']);
        $this->assertSame(WorkflowRun::DONE, $run['status'], 'À l’échéance, la suite part seule.');
    }

    // ==================================================================
    // 9. Le chat sait suivre l'exécution
    // ==================================================================

    public function testLaConversationDUneExecutionPorteSonVoletDAvancement(): void
    {
        $wf = $this->publie(self::definitionOnContinue(['join' => 'all', 'items' => [['piece' => 'depart', 'field' => 'reponse', 'op' => 'eq', 'value' => 'Oui']]]));
        $res = $this->lance($wf, ['client' => 'Acme', 'reponse' => 'Oui']);
        $conv = Conversation::findById($res['conversation_id']);
        $this->assertSame($res['run_id'], ChatController::workflowRunIdFor($conv, $this->userId));
        $this->assertNull(ChatController::workflowRunIdFor($conv, 999), 'Jamais l’exécution d’une autre personne.');
        $this->assertNull(ChatController::workflowRunIdFor(['workflow_run_id' => null], $this->userId));

        // En cours : le volet sonde vite, l'étape en cours est marquée.
        $p = WorkflowProgress::view($wf['published'], $this->etat($res['run_id']), ['run_id' => $res['run_id'], 'name' => 'Recette', 'color' => '#6366f1', 'version' => 1]);
        $this->assertFalse($p['terminal']);
        $this->assertSame(WorkflowProgress::POLL_RUNNING_MS, $p['poll']);
        $rows = array_column($p['rows'], null, 'key');
        $this->assertSame('run', $rows['analyse']['state']);
        $this->assertSame('done', $rows['start']['state']);
        $this->assertSame('todo', $rows['go']['state']);
        $avant = $p['signature'];
        $this->drive($res['run_id'], ['Analyse faite pour Acme, réponse à préparer.', 'Réponse prête pour Acme.']);
        $apres = WorkflowProgress::view($wf['published'], $this->etat($res['run_id']), ['run_id' => $res['run_id'], 'name' => 'Recette', 'color' => '#6366f1', 'version' => 1]);
        $this->assertNotSame($avant, $apres['signature'], 'L’exécution a bougé : le chat relit la conversation.');
        $this->assertSame($apres['total'], $apres['done'], 'Toutes les étapes faites.');
    }
}
