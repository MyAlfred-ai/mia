<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';
require_once __DIR__ . '/../Support/FakeLlmStream.php';

/**
 * Tables de travail dans un VRAI tour de chat (runSend, vrai adaptateur
 * OpenAI, faux modèle rejoué par FakeLlmStream) : le câblage que les tests
 * d'outils ne voient pas.
 *
 * Ce que ces tests protègent : les outils sont proposés au modèle quand le
 * message demande un travail de table et qu'il y a de quoi (un classeur joint,
 * des tables déjà là) — et seulement alors ; l'appel du modèle range TOUTES
 * les lignes en base et ne lui en rend que la poignée ; la trace le dit ; le
 * message suivant retrouve les tables dans sa consigne.
 */
final class WorkTableChatTurnTest extends DatabaseTestCase
{
    private const USER = 41;

    /** @var list<array> évènements émis par le tour */
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
        // o3 (seul modèle routé Chat Completions) est arrêté le 11/12/2026 : jour figé.
        LlmCatalog::setToday('2026-10-14');

        $this->insertUser(self::USER);
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        FakeLlmStream::install();

        $this->events = [];
        ChatController::setEmitSink(function (array $e): void {
            $this->events[] = $e;
        });
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

    private function conversation(): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([self::USER, 'Recoupement', 'openai::o3']);
        return (int)$this->db()->lastInsertId();
    }

    /** Réponse du faux modèle : un appel d'outil. */
    private static function toolCall(string $name, array $args): string
    {
        return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => 0, 'id' => 'call_' . substr(md5($name . json_encode($args)), 0, 8), 'type' => 'function',
                'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
            ]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30]]) . "\n\n"
            . "data: [DONE]\n\n";
    }

    private static function csv(int $rows): string
    {
        $out = "Nom;Email;Ville\n";
        for ($i = 1; $i <= $rows; $i++) {
            $out .= 'Client ' . $i . ';c' . $i . '@ex.fr;' . ($i % 4 === 0 ? 'Paris' : 'Lyon') . "\n";
        }
        return $out;
    }

    private function send(int $conv, string $text, array $files = []): array
    {
        $q = Message::create($conv, 'user', $text, $files);
        return (new ChatController())->runSend(
            ['content' => $text, 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false],
            self::USER,
            $conv
        );
    }

    private static function toolNames(array $request): array
    {
        return array_values(array_map(static fn(array $t): string => (string)($t['function']['name'] ?? ''), (array)($request['tools'] ?? [])));
    }

    public function testUnClasseurJointEtUneDemandeDeTableMenentAuxOutils(): void
    {
        $conv = $this->conversation();
        FakeLlmStream::$queue = [
            self::toolCall(WorkTableTools::COLLECT, ['source' => 'lignes_piece_jointe', 'table' => 'clients']),
            self::toolCall(WorkTableTools::TRANSFORM, [
                'table' => 'clients', 'vers' => 'par_ville', 'regrouper_par' => ['ville'],
                'calculs' => [['operation' => 'compte', 'nom' => 'Clients']],
            ]),
            FakeLlmStream::sse('Vous avez 240 clients à Lyon et 80 à Paris.'),
        ];
        $r = $this->send($conv, 'Combien de clients par ville dans ce fichier ?', [
            ['name' => 'clients.csv', 'type' => 'text/csv', 'extracted' => self::csv(320)],
        ]);
        $this->assertTrue($r['done'] ?? false);

        // Les outils de table sont partis au modèle, collecte comprise (le classeur joint est une source).
        $this->assertCount(3, FakeLlmStream::$requests);
        $names = self::toolNames(FakeLlmStream::$requests[0]);
        foreach ([WorkTableTools::COLLECT, WorkTableTools::JOIN, WorkTableTools::TRANSFORM, WorkTableTools::VIEW, WorkTableTools::EXPORT] as $tool) {
            $this->assertContains($tool, $names);
        }
        $this->assertStringContainsString('TABLES DE TRAVAIL', FakeLlmStream::promptOf(0));

        // Toutes les lignes sont en base ; le modèle n'en a reçu que la poignée.
        $store = new SqlWorkTableStore();
        $this->assertSame(320, $store->find($conv, 'clients')['rows']);
        $back = '';
        foreach ((array)FakeLlmStream::$requests[1]['messages'] as $m) {
            if (($m['role'] ?? '') === 'tool') {
                $back .= (string)$m['content'];
            }
        }
        $this->assertStringContainsString('"lignes":320', $back);
        $this->assertStringContainsString('Client 1', $back);
        $this->assertStringNotContainsString('Client 50;', $back);
        $this->assertStringNotContainsString('c50@ex.fr', $back, 'La 50e ligne n’a pas voyagé jusqu’au modèle.');
        $this->assertStringContainsString('avertissement', $back, 'Contenu d’un fichier : donnée, jamais consigne.');

        // Le comptage est celui du serveur.
        $byCity = $store->rows((int)$store->find($conv, 'par_ville')['id']);
        $this->assertSame([['ville' => 'Lyon', 'clients' => '240'], ['ville' => 'Paris', 'clients' => '80']], $byCity);

        // La trace dit ce qui a été fait.
        $actions = array_values(array_filter(array_map(static fn(array $e): ?array => $e['action'] ?? null, $this->events)));
        $types = array_column($actions, 't');
        $this->assertContains('table_collect', $types);
        $this->assertContains('table_transform', $types);
        $collect = $actions[array_search('table_collect', $types, true)];
        $this->assertSame('Lignes d\'un classeur joint → « clients » (320 lignes)', $collect['d']);
        $this->assertSame('sheet', $collect['i']);
        $this->assertContains(['phase' => 'table_collect'], $this->events);

        // Ce que la personne voit : la table ne s'affiche PAS dans le fil. La
        // réponse garde les tables du tour (le résultat d'abord) ; l'écran
        // reçoit la puce de la zone de saisie et UNE ligne « Voir ».
        $parVille = $store->find($conv, 'par_ville');
        $clients  = $store->find($conv, 'clients');
        $reply    = Message::findById((int)$r['msg_id']);
        $this->assertSame([(int)$parVille['id'], (int)$clients['id']], WorkTableView::refs(Message::agentOf($reply)['tables'] ?? null));
        $this->assertSame(2, $r['tables']['chip']['count']);
        $this->assertStringContainsString('data-table-open="' . (int)$parVille['id'] . '"', $r['tables']['html']);
        $this->assertStringContainsString('>Voir<', $r['tables']['html']);
        $this->assertStringContainsString('+ 1 autre table', $r['tables']['html']);
        $this->assertStringNotContainsString('<table', $r['tables']['html']);
        $this->assertStringNotContainsString('Lyon', $r['tables']['html'], 'Aucune ligne de données dans le fil.');
        $this->assertStringNotContainsString('<table', (string)$reply['content']);
        $this->assertStringContainsString('ne recopie pas une table dans ta réponse', FakeLlmStream::promptOf(0));

        // Message suivant : les tables sont rappelées, les outils encore là
        // (la demande nomme une table), sans que le fichier soit relu.
        FakeLlmStream::$queue = [FakeLlmStream::sse('Voici le fichier.')];
        FakeLlmStream::$requests = [];
        $this->send($conv, 'Exporte par ville en Excel');
        $prompt = FakeLlmStream::promptOf(0);
        $this->assertStringContainsString('`par_ville`', $prompt);
        $this->assertStringContainsString('`clients` — Lignes de « clients.csv » : 320 ligne(s)', $prompt);
        $this->assertContains(WorkTableTools::EXPORT, self::toolNames(FakeLlmStream::$requests[0]));

        // Une suite qui ne nomme rien (« et ceux de Lyon ? ») : la conversation a
        // des tables, les outils restent là — jamais une réponse de mémoire.
        FakeLlmStream::$queue = [FakeLlmStream::sse('240.')];
        FakeLlmStream::$requests = [];
        $this->send($conv, 'Et ceux de Lyon ?');
        $names = self::toolNames(FakeLlmStream::$requests[0]);
        $this->assertContains(WorkTableTools::TRANSFORM, $names);
        $this->assertContains(WorkTableTools::VIEW, $names);
    }

    public function testUnGrosClasseurJointExposeLesOutilsSansMotCle(): void
    {
        $conv = $this->conversation();
        FakeLlmStream::$queue = [FakeLlmStream::sse('Vos meilleurs clients sont…'), FakeLlmStream::sse('Avec plaisir.')];

        // 320 lignes, et une demande qui ne parle ni de liste ni de compte :
        // sans les outils, la réponse viendrait d'un texte lu de tête.
        $this->send($conv, 'Qui sont mes meilleurs clients ?', [
            ['name' => 'clients.csv', 'type' => 'text/csv', 'extracted' => self::csv(320)],
        ]);
        $names = self::toolNames(FakeLlmStream::$requests[0]);
        $this->assertContains(WorkTableTools::COLLECT, $names, 'Un gros classeur joint expose les outils de tables d’office.');
        $prompt = FakeLlmStream::promptOf(0);
        $this->assertStringContainsString('Classeur joint trop long pour être lu d\'un coup d\'œil : « clients.csv »', $prompt);
        $this->assertStringContainsString('lignes_piece_jointe', $prompt);

        // Au message suivant, sans mot-clé non plus : le classeur est toujours là, les outils aussi.
        $this->send($conv, 'Merci. Et les moins bons ?');
        $this->assertContains(WorkTableTools::COLLECT, self::toolNames(FakeLlmStream::$requests[1]));
    }

    public function testUnMessageOrdinaireNEngagePasLesOutils(): void
    {
        $conv = $this->conversation();
        FakeLlmStream::$queue = [FakeLlmStream::sse('Voici le résumé.'), FakeLlmStream::sse('Avec plaisir.')];

        // Un classeur joint, mais rien qui demande un travail de table.
        $this->send($conv, 'Résume ce document en trois phrases', [
            ['name' => 'clients.csv', 'type' => 'text/csv', 'extracted' => self::csv(5)],
        ]);
        $this->assertSame([], self::toolNames(FakeLlmStream::$requests[0]), 'Pas d’outil : le message ne bascule pas dans la boucle.');
        $this->assertStringNotContainsString('TABLES DE TRAVAIL', FakeLlmStream::promptOf(0));

        // Une demande de table sans classeur ni table : rien à travailler, rien d'exposé.
        $bare = $this->conversation();
        $this->send($bare, 'Combien de lignes dans la liste ?');
        $this->assertSame([], self::toolNames(FakeLlmStream::$requests[1]));
        $this->assertSame([], (new SqlWorkTableStore())->all($conv));
    }
}
