<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/FakeLlmStream.php';

/**
 * Tables PERMANENTES dans le chat, sur MySQL réel et un faux modèle :
 *  - « Garder » : une table de travail devient une table permanente ;
 *  - une table cochée pour la conversation est LUE par MIA sans mot-clé,
 *    le résultat restant dans la conversation ;
 *  - MIA ne fait que PROPOSER une écriture : une carte montre le changement,
 *    et l'accord le recalcule sur la table du moment, en gardant la version
 *    d'avant ; tout ce qui a changé depuis la carte fait refuser l'écriture ;
 *  - sans outils de tables, le tour reçoit un aperçu, dit comme tel.
 *
 * Aucun appel réseau. Auto-skippé sans base.
 */
final class KbTableChatPipelineTest extends DatabaseTestCase
{
    private const USER = 61;

    /** @var list<array> évènements émis par un tour de chat */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';

        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        LlmCatalog::setToday('2026-10-14');
        $this->insertUser(self::USER, 'creator');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        $this->events = [];
    }

    protected function tearDown(): void
    {
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
        }
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            Settings::refresh();
        }
        parent::tearDown();
    }

    private function user(): array
    {
        return User::findById(self::USER);
    }

    private function conversation(int $user = self::USER, string $title = 'Relances de novembre'): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([$user, $title, 'openai::o3']);
        return (int)$this->db()->lastInsertId();
    }

    /** Table permanente « Clients 2026 » de `$n` lignes. */
    private function clients(int $n, string $name = 'Clients 2026'): int
    {
        $columns = WorkTableOps::columns(['Nom', 'Email', 'Ville', 'Statut']);
        $rows    = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['nom' => 'Client ' . $i, 'email' => 'c' . $i . '@ex.fr', 'ville' => $i % 4 === 0 ? 'Paris' : 'Lyon', 'statut' => ''];
        }
        return (new KbTableStore())->createBase($this->user(), $name, null, [
            'columns' => $columns, 'rows' => $rows, 'origin' => ['kind' => KbTableImport::ORIGIN_FILE, 'file' => 'clients.xlsx'], 'notes' => [],
        ]);
    }

    /** Table de travail « suivi » : ce que MIA a établi dans la conversation. */
    private function followUp(int $conv, array $lines): array
    {
        $columns = WorkTableOps::columns(['Email', 'Statut']);
        return (new SqlWorkTableStore())->save($conv, self::USER, 'suivi', 'Suivi des mails', $columns, WorkTableOps::rows($columns, $lines));
    }

    private function deposit(int $conv, int $kbId, array $source, array $args): int
    {
        $store = new KbTableStore();
        $table = $store->find($kbId);
        $spec  = KbTableWrite::spec((array)$table['columns'], (array)$source['columns'], $args);
        return DrivePendingAction::create(self::USER, $conv, WorkTableTools::PERM_WRITE, [
            'kb_id'        => $kbId,
            'table'        => (string)$table['title'],
            'source_id'    => (int)$source['id'],
            'source'       => (string)$source['name'],
            'source_stamp' => WorkTableOps::stateStamp($source),
            'spec'         => $spec,
        ], ['action' => 'Modifier la table « ' . $table['title'] . ' »']);
    }

    private function decide(int $conv, int $actionId, string $decision = 'approve'): array
    {
        $m = new ReflectionMethod(ChatController::class, 'decideKept');
        return $m->invoke(new ChatController(), $conv, $this->user(), $actionId, $decision, false);
    }

    private function action(int $id): array
    {
        return $this->db()->query('SELECT * FROM drive_pending_actions WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> */
    private function replies(int $conv): array
    {
        $stmt = $this->db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id");
        $stmt->execute([$conv]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ---------------------------------------------------------------------
    // 1. Garder
    // ---------------------------------------------------------------------

    public function testUneTableDeTravailGardeeDevientUneTablePermanente(): void
    {
        $conv    = $this->conversation();
        $columns = WorkTableOps::columns(['Expéditeur', 'Objet']);
        $columns[] = ['key' => 'id', 'label' => 'id', 'type' => 'text', 'tech' => true];
        $work = new SqlWorkTableStore();
        $t    = $work->save($conv, self::USER, 'mails', 'Mails de relance', $columns, [
            ['expediteur' => 'a@ex.fr', 'objet' => 'Devis', 'id' => 'm1'],
            ['expediteur' => 'b@ex.fr', 'objet' => 'Relance', 'id' => 'm2'],
        ]);

        $import = KbTableImport::fromWorkTable($t, $work->rows((int)$t['id']), 'Relances de novembre');
        $kbId   = (new KbTableStore())->createBase($this->user(), 'Mails de relance', null, $import);

        $kb = KnowledgeBase::findById($kbId);
        $this->assertTrue(KnowledgeBase::isTable($kb));
        $this->assertSame(self::USER, (int)$kb['created_by']);
        $table = (new KbTableStore())->find($kbId);
        $this->assertSame(2, $table['rows']);
        $this->assertSame(['expediteur', 'objet'], WorkTableOps::keys($table['columns']), 'Les identifiants internes restent dans la conversation.');
        $this->assertSame('Gardée de la conversation « Relances de novembre »', KbTableImport::originLabel($table['origin']));

        // C'est une COPIE : la table de la conversation n'a pas bougé, et vit sa vie.
        $this->assertSame(2, (int)$work->findById($conv, (int)$t['id'])['rows']);
        $work->delete($conv, 'mails');
        $this->assertCount(2, (new KbTableStore())->rows($kbId));
    }

    // ---------------------------------------------------------------------
    // 2. Ce que le tour engage
    // ---------------------------------------------------------------------

    public function testLesTablesEngageesPortentUnNomUniqueEtLeurDroitDEcriture(): void
    {
        $a = $this->clients(3);
        $b = $this->clients(2, 'Clients-2026');   // même nom court
        $kbs = [KnowledgeBase::findById($a), KnowledgeBase::findById($b)];

        $tables = ChatController::permanentTablesFor($kbs, $this->user(), ['clients_2026']);
        $this->assertSame(['clients_2026_2', 'clients_2026_3'], array_column($tables, 'name'), 'Jamais le nom d’une table de la conversation, jamais deux fois le même.');
        $this->assertSame([true, true], array_column($tables, 'writable'));
        $this->assertSame([3, 2], array_column($tables, 'rows'));

        // Le propriétaire ferme l'écriture à MIA : lecture seule, pour tout le monde.
        (new KbTableStore())->setMiaWrite($a, false);
        $this->assertSame([false, true], array_column(ChatController::permanentTablesFor($kbs, $this->user()), 'writable'));

        // Sans le droit de modifier une base (simple utilisateur), jamais d'écriture.
        $this->insertUser(62, 'user');
        $this->assertSame([false, false], array_column(ChatController::permanentTablesFor($kbs, User::findById(62)), 'writable'));
        // Sans personne connue (aperçu d'un prompt) : lecture seule aussi.
        $this->assertSame([false, false], array_column(ChatController::permanentTablesFor($kbs, null), 'writable'));
    }

    // ---------------------------------------------------------------------
    // 3. L'accord
    // ---------------------------------------------------------------------

    public function testLAccordRecalculeSurLaTableDuMomentEtSInscritDansLHistorique(): void
    {
        $conv   = $this->conversation();
        $kbId   = $this->clients(6);
        $source = $this->followUp($conv, [['c1@ex.fr', 'à relancer'], ['c3@ex.fr', 'à relancer'], ['inconnu@ex.fr', 'à relancer']]);
        $id     = $this->deposit($conv, $kbId, $source, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut']]);

        // Entre la carte et l'accord, quelqu'un corrige une ligne à la main : elle doit survivre.
        $store = new KbTableStore();
        $store->setCell($kbId, 1, 'ville', 'Marseille', self::USER);

        $issue = $this->decide($conv, $id);
        $this->assertTrue($issue['ok'] ?? false, json_encode($issue, JSON_UNESCAPED_UNICODE));
        $this->assertSame('executed', $issue['statut']);
        $this->assertSame('/admin/knowledge-bases/' . $kbId . '?historique=1', $issue['resultat']['url']);

        $rows = $store->rows($kbId);
        $this->assertSame(['à relancer', '', 'à relancer', '', '', ''], array_column($rows, 'statut'));
        $this->assertSame('Marseille', $rows[1]['ville'], 'La retouche faite depuis la carte est gardée : le calcul est rejoué à l’accord.');
        $table = $store->find($kbId);
        $this->assertSame(6, $table['rows']);
        $history = $store->history($kbId);
        $this->assertSame([KbTableHistory::KIND_MIA, KbTableHistory::KIND_EDIT, KbTableHistory::KIND_CREATE], array_column($history, 'kind'),
            'La retouche à la main puis l’écriture de MIA : deux états, la création intacte.');
        $this->assertSame(self::USER, $history[0]['user_id'], 'MIA, après accord de la personne.');
        $this->assertSame('mettre_a_jour', $history[0]['detail']['mode']);
        $this->assertSame(2, $history[0]['detail']['touched']);
        $this->assertSame($conv, $history[0]['detail']['conversation']);

        $action = $this->action($id);
        $this->assertSame('executed', $action['status']);
        $replies = $this->replies($conv);
        $this->assertStringStartsWith('✅ ', end($replies));
        $this->assertStringContainsString('Clients 2026', end($replies));
        $this->assertStringContainsString('2 lignes modifiées par mia', mb_strtolower(end($replies)));

        // Revenir à l'état d'avant défait l'écriture de MIA, et garde la retouche.
        $store->restore($kbId, $history[1]['version'], self::USER);
        $this->assertSame(['', '', '', '', '', ''], array_column($store->rows($kbId), 'statut'));
        $this->assertSame('Marseille', $store->rows($kbId)[1]['ville']);
    }

    public function testCeQuiAChangeDepuisLaCarteFaitRefuserLEcriture(): void
    {
        $store = new KbTableStore();

        // (a) La table de travail a été modifiée depuis la carte.
        $conv   = $this->conversation();
        $kbId   = $this->clients(4);
        $source = $this->followUp($conv, [['c1@ex.fr', 'à relancer']]);
        $id     = $this->deposit($conv, $kbId, $source, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut']]);
        sleep(1); // l'empreinte d'une table de travail porte sa date d'écriture, à la seconde
        $this->followUp($conv, [['c2@ex.fr', 'perdu']]);
        $issue = $this->decide($conv, $id);
        $this->assertFalse($issue['ok'] ?? true);
        $this->assertStringContainsString('a changé depuis la préparation', (string)$issue['error']);
        $this->assertSame('failed', $this->action($id)['status']);
        $this->assertSame(['', '', '', ''], array_column($store->rows($kbId), 'statut'), 'Rien n’est écrit.');
        $replies = $this->replies($conv);
        $this->assertStringStartsWith('⚠️ ', end($replies));

        // (b) Le propriétaire a fermé l'écriture à MIA.
        $conv2   = $this->conversation();
        $source2 = $this->followUp($conv2, [['c1@ex.fr', 'à relancer']]);
        $id2     = $this->deposit($conv2, $kbId, $source2, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut']]);
        $store->setMiaWrite($kbId, false);
        $issue2 = $this->decide($conv2, $id2);
        $this->assertFalse($issue2['ok'] ?? true);
        $this->assertStringContainsString('lecture seule', (string)$issue2['error']);
        $this->assertSame(['', '', '', ''], array_column($store->rows($kbId), 'statut'));
        $store->setMiaWrite($kbId, true);

        // (c) La table a été supprimée.
        $conv3   = $this->conversation();
        $source3 = $this->followUp($conv3, [['c1@ex.fr', 'à relancer']]);
        $id3     = $this->deposit($conv3, $kbId, $source3, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut']]);
        $this->db()->exec('DELETE FROM knowledge_bases WHERE id = ' . $kbId);
        $issue3 = $this->decide($conv3, $id3);
        $this->assertFalse($issue3['ok'] ?? true);
        $this->assertStringContainsString('n’existe plus', (string)$issue3['error']);

        // (d) Annuler une carte ne touche à rien.
        $kb4     = $this->clients(2, 'Autres');
        $conv4   = $this->conversation();
        $source4 = $this->followUp($conv4, [['c1@ex.fr', 'à relancer']]);
        $id4     = $this->deposit($conv4, $kb4, $source4, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut']]);
        $this->decide($conv4, $id4, 'cancel');
        $this->assertSame('cancelled', $this->action($id4)['status']);
        $this->assertSame(['', ''], array_column($store->rows($kb4), 'statut'));
    }

    public function testUneEcritureNEstJamaisExecuteeSansValidationParUneTache(): void
    {
        $this->assertFalse(
            Scheduler::autoExecutable(WorkTableTools::PERM_WRITE, ['kb_id' => 1], [1, 2, 3]),
            'Une tâche programmée « sans validation » dépose une carte : une table partagée ne change pas sans accord.'
        );
    }

    // ---------------------------------------------------------------------
    // 4. Un tour de chat
    // ---------------------------------------------------------------------

    public function testUnTourLitUneTableCocheeSansMotCleEtProposeUneEcritureSousCarte(): void
    {
        FakeLlmStream::install();
        ChatController::setEmitSink(function (array $e): void {
            $this->events[] = $e;
        });
        $kbId = $this->clients(120);
        $conv = $this->conversation();
        ConversationKnowledgeBase::setForConversation($conv, [$kbId]);

        $call = static function (string $tool, array $args): string {
            return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                    'index' => 0, 'id' => 'call_1', 'type' => 'function',
                    'function' => ['name' => $tool, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
                ]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
                . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
                . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
                . "data: [DONE]\n\n";
        };
        $send = function (string $text) use ($conv): array {
            $q = Message::create($conv, 'user', $text);
            return (new ChatController())->runSend(['content' => $text, 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false], self::USER, $conv);
        };

        // (1) Une question sans aucun mot de table : les outils sont là, la table est nommée.
        FakeLlmStream::$queue = [
            $call(WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'a_paris', 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']]]),
            FakeLlmStream::sse('Trente clients sont à Paris.'),
        ];
        $r = $send('Qui avons-nous à Paris ?');
        $this->assertTrue($r['done'] ?? false);

        $request = FakeLlmStream::$requests[0];
        $names   = array_map(static fn(array $t): string => (string)($t['function']['name'] ?? ''), (array)($request['tools'] ?? []));
        $this->assertContains(WorkTableTools::TRANSFORM, $names);
        $this->assertContains(WorkTableTools::PERM_WRITE, $names, 'Le créateur de la table peut se voir proposer une écriture.');
        $system = (string)($request['messages'][0]['content'] ?? '');
        $this->assertStringContainsString('clients_2026', $system);
        $this->assertStringNotContainsString('Client 7 |', $system, 'Avec les outils, aucune ligne de la table ne part dans le prompt.');
        // La trace dit ce qui a eu lieu : une lecture de table, pas une recherche dans des documents.
        $types = array_column(array_values(array_filter(array_map(static fn(array $e): ?array => $e['action'] ?? null, $this->events))), 't');
        $this->assertNotContains('searching_knowledge_bases', $types, 'Une table lue par les outils n’est pas « cherchée ».');
        $this->assertContains(WorkTableTools::traceType(WorkTableTools::TRANSFORM, ['vue_de' => 'Clients 2026']), $types);

        // Le serveur a compté sur les 120 lignes ; le résultat est une VUE de la table, pas une copie.
        $work   = new SqlWorkTableStore();
        $result = $work->find($conv, 'a_paris');
        $this->assertTrue(WorkTableView::isView($result), 'Une liste tirée d’une table permanente est une vue.');
        $this->assertSame(30, (int)$result['origin']['lignes']);
        $this->assertSame([], $work->rows((int)$result['id']), 'Une vue ne porte aucune ligne : rien n’est copié.');
        $this->assertSame(120, (new KbTableStore())->find($kbId)['rows'], 'La table permanente n’a pas bougé.');
        $this->assertSame(1, (int)KnowledgeBase::findById($kbId)['query_count'], 'Une lecture par tour compte une utilisation.');

        // (2) Reporter un statut : une carte, rien d'écrit.
        $this->events = [];
        FakeLlmStream::$requests = [];
        FakeLlmStream::$queue = [
            $call(WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'mode' => 'definir', 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']], 'definir' => '{"Statut": "à voir à Paris"}']),
            FakeLlmStream::sse('La modification est prête : à vous de l’approuver.'),
        ];
        $send('Note « à voir à Paris » dans le statut de ces clients.');
        $cards = array_values(array_filter(array_map(static fn(array $e): ?array => $e['drive_confirm'] ?? null, $this->events)));
        $this->assertCount(1, $cards);
        $this->assertSame('Modifier la table « Clients 2026 »', $cards[0]['action']);
        $this->assertStringContainsString('30 lignes modifiées', $cards[0]['fichier']);
        $this->assertSame(['Ligne', 'Colonne', 'Avant', 'Après'], $cards[0]['grille']['colonnes']);
        $this->assertSame(['Client 4', 'Statut', 'vide', 'à voir à Paris'], $cards[0]['grille']['lignes'][0]);
        $this->assertSame('+ 22 autres valeurs modifiées', $cards[0]['grille']['suite']);
        $this->assertSame(array_fill(0, 120, ''), array_column((new KbTableStore())->rows($kbId), 'statut'), 'Rien n’est écrit sans accord.');
        $trace = array_values(array_filter(array_map(static fn(array $e): ?array => $e['action'] ?? null, $this->events)));
        $this->assertContains('table_perm_write', array_column($trace, 't'));

        // (3) L'accord écrit, sur les trente lignes.
        $pending = DrivePendingAction::pendingForConversation($conv, self::USER);
        $this->assertSame([WorkTableTools::PERM_WRITE], array_column($pending, 'tool'));
        $issue = $this->decide($conv, (int)$pending[0]['id']);
        $this->assertTrue($issue['ok'] ?? false, json_encode($issue, JSON_UNESCAPED_UNICODE));
        $statuses = array_count_values(array_column((new KbTableStore())->rows($kbId), 'statut'));
        $this->assertSame(30, $statuses['à voir à Paris']);
        $this->assertSame(90, $statuses['']);
    }

    public function testSansOutilsDeTablesLeTourRecoitUnApercuDitCommeTel(): void
    {
        $kbId = $this->clients(200);
        $m    = new ReflectionMethod(ChatController::class, 'buildKnowledgeBaseContext');
        $kbs  = [KnowledgeBase::findById($kbId)];

        $preview = (string)$m->invoke(new ChatController(), $kbs, 'Combien de clients à Paris ?', 8000, [], ['tables' => 'preview']);
        $this->assertStringContainsString('Table « Clients 2026 » — 200 ligne(s)', $preview);
        $this->assertStringContainsString('N\'en déduis ni total', $preview);
        // Les lignes qui citent la question passent devant le début de la table.
        $this->assertStringContainsString('Client 4 | c4@ex.fr | Paris', $preview);
        $this->assertStringContainsString('qui citent le plus les mots de la question', $preview);
        $this->assertStringNotContainsString('Client 1 | c1@ex.fr', $preview);

        // Avec les outils : rien dans le prompt (la consigne des outils nomme la table).
        $none = $m->invoke(new ChatController(), $kbs, 'Combien de clients à Paris ?', 8000, [], ['tables' => 'tools']);
        $this->assertNull($none);
    }

    // ---------------------------------------------------------------------
    // 5. Le parcours signalé en recette : compter, voir, modifier
    // ---------------------------------------------------------------------

    public function testCompterPuisModifierNeCreeAucuneTableEtVoirOuvreLaTablePrincipale(): void
    {
        FakeLlmStream::install();
        ChatController::setEmitSink(function (array $e): void {
            $this->events[] = $e;
        });
        $kbId = $this->clients(120);
        $conv = $this->conversation();
        ConversationKnowledgeBase::setForConversation($conv, [$kbId]);
        $work = new SqlWorkTableStore();
        $ref  = 'p' . $kbId;

        $call = static function (string $tool, array $args): string {
            return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                    'index' => 0, 'id' => 'call_1', 'type' => 'function',
                    'function' => ['name' => $tool, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
                ]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
                . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
                . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
                . "data: [DONE]\n\n";
        };
        $send = function (string $text) use ($conv): array {
            $q = Message::create($conv, 'user', $text);
            return (new ChatController())->runSend(['content' => $text, 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false], self::USER, $conv);
        };

        // La table cochée est engagée dans la conversation : elle compte dans la puce avant tout message.
        $engaged = KbTableStore::engaged($conv, $this->user());
        $this->assertSame([$kbId], array_column($engaged, 'kb_id'));

        // (0) Le modèle peut répondre sans outil : la consigne lui donne le nombre de lignes. La
        // réponse qui NOMME la table la propose quand même par « Voir ».
        FakeLlmStream::$queue = [FakeLlmStream::sse('La table **Clients 2026** compte 120 lignes.')];
        $r0 = $send('Combien de lignes compte la table ?');
        $this->assertSame([$ref], WorkTableView::refs(Message::agentOf(Message::findById((int)$r0['msg_id']))['tables'] ?? null));
        $this->assertStringContainsString('data-table-open="' . $ref . '"', $r0['tables']['html']);
        $this->assertStringContainsString('nomme-les par leur titre', FakeLlmStream::promptOf(0));
        $this->events = [];
        FakeLlmStream::$requests = [];

        // (1) « Combien de lignes ? » — le modèle demande un total, et donne même un nom de table.
        FakeLlmStream::$queue = [
            $call(WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'nombre_de_lignes', 'calculs' => [['operation' => 'compte']]]),
            FakeLlmStream::sse('La table compte 120 lignes.'),
        ];
        $r = $send('Combien de lignes compte la table ?');
        $this->assertTrue($r['done'] ?? false);
        $this->assertSame([], $work->all($conv), 'Aucune table d’une ligne n’est créée pour un compte.');

        // Sous la réponse, « Voir » ouvre la table PRINCIPALE — pas une table de résultat.
        $reply = Message::findById((int)$r['msg_id']);
        $this->assertSame([$ref], WorkTableView::refs(Message::agentOf($reply)['tables'] ?? null));
        $this->assertSame(1, $r['tables']['chip']['count']);
        $this->assertStringContainsString('data-table-open="' . $ref . '"', $r['tables']['html']);
        $this->assertStringContainsString('Clients 2026', $r['tables']['html']);
        $this->assertStringContainsString('120 lignes', $r['tables']['html']);
        $types = array_column(array_values(array_filter(array_map(static fn(array $e): ?array => $e['action'] ?? null, $this->events))), 't');
        $this->assertContains('table_compute', $types, 'La trace dit un calcul : aucune table n’est née.');
        $this->assertNotContains('table_transform', $types);

        // Le volet l'ouvre en entier : ses 120 lignes, par pages, en lecture seule.
        $panel = WorkTableView::panel($work, $conv, $ref, '', 1, false, true, $engaged, new KbTableStore(), true);
        $this->assertSame($ref, $panel['selected']['id']);
        $this->assertSame(120, $panel['selected']['page']['total']);
        $this->assertCount(WorkTableView::PAGE_ROWS, $panel['selected']['page']['rows']);
        $this->assertSame('Client 1', $panel['selected']['page']['rows'][0]['cells'][0]['text']);
        $this->assertSame('/admin/knowledge-bases/' . $kbId . '/edit', $panel['selected']['kb_url']);

        // (2) « Modifie-la » — directement, sous carte, sans table de travail.
        $this->events = [];
        FakeLlmStream::$queue = [
            $call(WorkTableTools::PERM_WRITE, [
                'table' => 'clients_2026', 'mode' => 'definir', 'definir' => '{"Statut": "à relancer"}',
                'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']],
            ]),
            FakeLlmStream::sse('La modification est prête : à vous de l’approuver.'),
        ];
        $r2 = $send('Passe les clients de Paris en statut « à relancer ».');
        $this->assertSame([], $work->all($conv), 'Modifier la table ne crée pas une nouvelle table.');
        $cards = array_values(array_filter(array_map(static fn(array $e): ?array => $e['drive_confirm'] ?? null, $this->events)));
        $this->assertCount(1, $cards);
        $this->assertSame('Modifier la table « Clients 2026 »', $cards[0]['action']);
        $this->assertStringContainsString('30 lignes modifiées', $cards[0]['fichier']);
        $this->assertStringContainsString('Lignes visées : Ville = « Paris » (30 lignes).', implode("\n", $cards[0]['impacts']));
        $this->assertStringContainsString('data-table-open="' . $ref . '"', $r2['tables']['html'], 'La réponse nomme la table qui va changer.');
        $this->assertSame(array_fill(0, 120, ''), array_column((new KbTableStore())->rows($kbId), 'statut'), 'Rien n’est écrit sans accord.');

        // L'accord écrit DANS la table — la même, pas une copie.
        $pending = DrivePendingAction::pendingForConversation($conv, self::USER);
        $this->assertSame(0, (int)(json_decode((string)$this->action((int)$pending[0]['id'])['args_json'], true)['source_id'] ?? -1));
        $issue = $this->decide($conv, (int)$pending[0]['id']);
        $this->assertTrue($issue['ok'] ?? false, json_encode($issue, JSON_UNESCAPED_UNICODE));
        $statuses = array_count_values(array_column((new KbTableStore())->rows($kbId), 'statut'));
        $this->assertSame(30, $statuses['à relancer']);
        $last = (new KbTableStore())->history($kbId)[0];
        $this->assertSame(KbTableHistory::KIND_MIA, $last['kind']);
        $this->assertSame(30, $last['detail']['touched']);
        $this->assertSame([], $work->all($conv));

        // (3) Au rechargement : les trois réponses nomment la table, la puce la compte.
        $lines = WorkTableView::forMessages(Message::forConversation($conv), [], KbTableStore::engaged($conv, $this->user()));
        $this->assertCount(3, $lines);
        $payload = ChatController::turnTablesPayload($conv, [$ref]);
        $this->assertSame(1, $payload['chip']['count'], 'Relue pour la conversation : la table permanente compte dans la puce.');
        $this->assertStringContainsString('data-table-open="' . $ref . '"', $payload['html']);

        // (4) Décochée : elle n'est plus jointe — ni puce, ni ligne, ni volet.
        ConversationKnowledgeBase::setForConversation($conv, []);
        $this->assertSame([], KbTableStore::engaged($conv, $this->user()));
        $this->assertSame([], WorkTableView::forMessages(Message::forConversation($conv), [], []));
        $this->assertSame(0, ChatController::turnTablesPayload($conv, [$ref])['chip']['count']);
        // … et celle d'un autre compte ne s'engage jamais par l'identifiant d'une conversation qui n'est pas à lui.
        $this->insertUser(63, 'creator');
        $this->assertSame([], KbTableStore::engaged($conv, User::findById(63)));
    }

    public function testLAccordDUneAjoutDeLignesDonneesEtDUneSuppression(): void
    {
        $conv  = $this->conversation();
        $kbId  = $this->clients(4);
        $store = new KbTableStore();
        $table = $store->find($kbId);
        $deposit = function (array $args) use ($conv, $kbId, $table): int {
            $spec = KbTableWrite::spec((array)$table['columns'], [], $args);
            return DrivePendingAction::create(self::USER, $conv, WorkTableTools::PERM_WRITE, [
                'kb_id' => $kbId, 'table' => (string)$table['title'], 'source_id' => 0, 'source' => '', 'source_stamp' => '', 'spec' => $spec,
            ], ['action' => 'Modifier la table']);
        };

        // Lignes données dans la demande : elles voyagent avec la carte.
        $add   = $deposit(['mode' => 'ajouter', 'colonnes' => ['Nom', 'Ville'], 'lignes' => [['Zed', 'Nice'], ['Yak', 'Pau']]]);
        $issue = $this->decide($conv, $add);
        $this->assertTrue($issue['ok'] ?? false, json_encode($issue, JSON_UNESCAPED_UNICODE));
        $rows = $store->rows($kbId);
        $this->assertCount(6, $rows);
        $this->assertSame(['nom' => 'Yak', 'email' => '', 'ville' => 'Pau', 'statut' => ''], $rows[5]);

        // Supprimer des lignes filtrées — on peut revenir à l'état d'avant.
        $del   = $deposit(['mode' => 'supprimer_lignes', 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Lyon']]]);
        $issue = $this->decide($conv, $del);
        $this->assertTrue($issue['ok'] ?? false, json_encode($issue, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['Client 4', 'Zed', 'Yak'], array_column($store->rows($kbId), 'nom'));
        $history = $store->history($kbId);
        $this->assertSame(3, $history[0]['detail']['deleted']);
        $this->assertSame('3 lignes supprimées', KbTableHistory::what($history[0], new \DateTimeZone('UTC')));
        $store->restore($kbId, $history[1]['version'], self::USER);
        $this->assertCount(6, $store->rows($kbId));

        // Une colonne visée disparue entre la carte et l'accord : rien n'est écrit, et c'est dit.
        $set = $deposit(['mode' => 'definir', 'definir' => ['Statut' => 'x'], 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Nice']]]);
        $now = $store->find($kbId);
        $store->replace($kbId, array_values(array_filter($now['columns'], static fn(array $c): bool => $c['key'] !== 'ville')),
            array_map(static function (array $r): array { unset($r['ville']); return $r; }, $store->rows($kbId)), null, self::USER,
            KbTableHistory::change(KbTableHistory::KIND_COLUMN, ['dropped' => ['Ville']]));
        $issue = $this->decide($conv, $set);
        $this->assertFalse($issue['ok'] ?? true);
        $this->assertStringContainsString('n\'existe plus', (string)$issue['error']);
        $this->assertSame('failed', $this->action($set)['status']);

        // Une carte « mettre à jour » sans table source (forgée, ou d'un autre temps) est refusée.
        $forged = DrivePendingAction::create(self::USER, $conv, WorkTableTools::PERM_WRITE, [
            'kb_id' => $kbId, 'table' => 'x', 'source_id' => 0, 'source' => '', 'source_stamp' => '',
            'spec' => ['mode' => 'remplacer', 'key' => '', 'source_key' => '', 'columns' => ['nom'], 'set' => [], 'add_missing' => false],
        ], ['action' => 'Modifier la table']);
        $issue = $this->decide($conv, $forged);
        $this->assertFalse($issue['ok'] ?? true);
        $this->assertCount(6, $store->rows($kbId), 'Un remplacement sans source ne vide jamais la table.');
    }
}
