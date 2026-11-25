<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Les tables de travail vues par la personne, sur base réelle.
 *
 * Ce que ces tests protègent : renommer une table ne change NI son nom court
 * NI sa date d'écriture (une opération de fond en cours sur elle continue) ;
 * supprimer une table efface ses lignes et retire sa ligne « Voir » des
 * réponses qui la nommaient ; les tables gardées avec une réponse survivent
 * aux autres écritures de son état ; et tout reste cloisonné par conversation.
 */
final class WorkTableViewPipelineTest extends DatabaseTestCase
{
    private SqlWorkTableStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        $this->insertUser(1);
        $this->insertUser(2);
        $this->store = new SqlWorkTableStore();
        I18n::use('fr');
    }

    private function conversation(int $userId): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([$userId, 'Relances']);
        return (int)$this->db()->lastInsertId();
    }

    private function table(int $conv, string $name, string $title, int $rows): array
    {
        $cols = WorkTableOps::columns(['Nom', 'Ville']);
        $data = [];
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = ['nom' => 'Client ' . $i, 'ville' => $i % 2 === 0 ? 'Besançon' : 'Lyon'];
        }
        return $this->store->save($conv, 1, $name, $title, $cols, $data, ['kind' => 'saisie']);
    }

    private function reply(int $conv, array $agent): int
    {
        $msg = Message::create($conv, 'assistant', 'Voici le résultat.');
        Message::setAgent((int)$msg['id'], $agent);
        return (int)$msg['id'];
    }

    public function testRenommerGardeLeNomCourtEtLaDateDEcriture(): void
    {
        $conv  = $this->conversation(1);
        $table = $this->table($conv, 'clients', 'Fichier clients', 12);
        // Une table écrite il y a une heure : ON UPDATE CURRENT_TIMESTAMP la
        // daterait de maintenant si le renommage ne la préservait pas.
        $this->db()->prepare('UPDATE work_tables SET updated_at = NOW() - INTERVAL 1 HOUR WHERE id = ?')->execute([(int)$table['id']]);
        $before = $this->store->findById($conv, (int)$table['id']);
        $stamp  = WorkTableOps::stateStamp($before);

        $after = $this->store->retitle($conv, (int)$table['id'], 'Clients à relancer');

        $this->assertSame('Clients à relancer', $after['title']);
        $this->assertSame('clients', $after['name']);
        $this->assertSame($before['updated_at'], $after['updated_at']);
        $this->assertSame($stamp, WorkTableOps::stateStamp($after), 'L’empreinte ne bouge pas : une opération en cours continue.');
        $this->assertCount(12, $this->store->rows((int)$table['id']));

        // Une écriture sous cette empreinte passe encore.
        $written = $this->store->patch($conv, (int)$table['id'], $after['columns'], [0 => ['nom' => 'Acme', 'ville' => 'Lyon']], $stamp);
        $this->assertNotNull($written);
        $this->assertArrayNotHasKey('stale', $written);
    }

    public function testRenommerOuVoirLaTableDUneAutreConversationEstImpossible(): void
    {
        $mine   = $this->conversation(1);
        $theirs = $this->conversation(2);
        $table  = $this->table($theirs, 'clients', 'Leurs clients', 5);

        $this->assertNull($this->store->retitle($mine, (int)$table['id'], 'Vol'));
        $this->assertSame('Leurs clients', $this->store->findById($theirs, (int)$table['id'])['title']);

        $view = WorkTableView::panel($this->store, $mine, (int)$table['id'], '', 1);
        $this->assertSame(0, $view['count']);
        $this->assertNull($view['selected']);
    }

    public function testLeVoletLitUnePageFiltreeSurLaBase(): void
    {
        $conv  = $this->conversation(1);
        $table = $this->table($conv, 'clients', 'Clients', 730);

        $view = WorkTableView::panel($this->store, $conv, (int)$table['id'], 'besancon', 3);
        $page = $view['selected']['page'];
        $this->assertSame(365, $page['matched']);
        $this->assertSame(730, $page['total']);
        $this->assertSame([3, 8], [$page['page'], $page['pages']]);
        $this->assertSame(202, $page['rows'][0]['n'], 'Rang dans la table : la 101ᵉ ligne paire.');
        $this->assertSame('Client 202', $page['rows'][0]['cells'][0]['text']);
    }

    public function testUneTableSupprimeeNEstPlusNommeeSousSaReponse(): void
    {
        $conv = $this->conversation(1);
        $a = $this->table($conv, 'clients', 'Clients', 4);
        $b = $this->table($conv, 'mails', 'Emails', 6);
        $msgId = $this->reply($conv, ['tables' => [(int)$b['id'], (int)$a['id']]]);

        $messages = Message::forConversation($conv);
        $lines = WorkTableView::forMessages($messages, $this->store->all($conv));
        $this->assertSame(['mails', 'clients'], array_column($lines[$msgId], 'name'));

        $this->assertTrue($this->store->delete($conv, 'mails'));
        $left = $this->db()->prepare('SELECT COUNT(*) FROM work_table_rows WHERE table_id = ?');
        $left->execute([(int)$b['id']]);
        $this->assertSame(0, (int)$left->fetchColumn(), 'Ses lignes partent avec elle.');

        $lines = WorkTableView::forMessages(Message::forConversation($conv), $this->store->all($conv));
        $this->assertSame(['clients'], array_column($lines[$msgId], 'name'));

        $this->store->delete($conv, 'clients');
        $this->assertSame([], WorkTableView::forMessages(Message::forConversation($conv), $this->store->all($conv)));
        $this->assertSame([], SqlWorkTableStore::names($conv));
    }

    public function testLesTablesGardeesAvecUneReponseSurviventAuResteDeSonEtat(): void
    {
        $conv  = $this->conversation(1);
        $table = $this->table($conv, 'clients', 'Clients', 4);
        $msgId = $this->reply($conv, ['plan' => null, 'suite' => null, 'offer' => null, 'tables' => [(int)$table['id']]]);

        // La suggestion de suite arrive après coup, dans le même état.
        Message::setSuggestion($msgId, 'Prépare les relances.');

        $row = Message::findById($msgId);
        $agent = Message::agentOf($row);
        $this->assertSame([(int)$table['id']], WorkTableView::refs($agent['tables'] ?? null));
        $this->assertSame('Prépare les relances.', $agent['suggestion']);

        // Une réponse sans table ne garde rien (et ne coûte aucune lecture des tables).
        $plain = $this->reply($conv, ['tables' => []]);
        $this->assertNull(Message::findById($plain)['agent_json']);
    }

    public function testLaFinDUnTourRendLaPuceEtLaLigne(): void
    {
        $conv = $this->conversation(1);
        $a = $this->table($conv, 'clients', 'Fichier clients', 240);
        $b = $this->table($conv, 'sans_reponse', 'Clients sans réponse', 37);

        $payload = ChatController::turnTablesPayload($conv, [(int)$b['id'], 424242]);
        $this->assertSame(2, $payload['chip']['count']);
        // Sans table permanente cochée, la puce compte les résultats de MIA.
        $this->assertSame('2 résultats', $payload['chip']['label']);
        $this->assertStringContainsString('data-table-open="' . (int)$b['id'] . '"', $payload['html']);
        $this->assertStringContainsString('Clients sans réponse', $payload['html']);
        $this->assertStringContainsString('37 lignes', $payload['html']);
        $this->assertStringNotContainsString('data-table-open="' . (int)$a['id'] . '"', $payload['html'], 'Seules les tables du tour.');
        $this->assertStringNotContainsString('<table', $payload['html']);

        // Tour qui n'a touché aucune table : la puce suit quand même, sans ligne.
        $none = ChatController::turnTablesPayload($conv, []);
        $this->assertSame(['count' => 2, 'html' => ''], ['count' => $none['chip']['count'], 'html' => $none['html']]);

        // Conversation d'autrui, ou vide : rien à nommer.
        $other = ChatController::turnTablesPayload($this->conversation(2), [(int)$a['id']]);
        $this->assertSame(0, $other['chip']['count']);
        $this->assertSame('', $other['html']);
    }
}
