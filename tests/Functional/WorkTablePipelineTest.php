<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Tables de travail sur base réelle (migration 111, SqlWorkTableStore).
 *
 * Ce que ces tests protègent : toutes les lignes sont gardées, dans l'ordre ;
 * un même nom REMPLACE la table (jamais deux, jamais un mélange) ; une table
 * n'existe que pour SA conversation et disparaît avec elle ; le plafond de
 * tables tient ; et les outils mènent un recoupement complet sur ce magasin.
 */
final class WorkTablePipelineTest extends DatabaseTestCase
{
    private SqlWorkTableStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(1);
        $this->insertUser(2);
        $this->store = new SqlWorkTableStore();
    }

    private function conversation(int $userId): int
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)')->execute([$userId, 'Recoupement']);
        return (int)$this->db()->lastInsertId();
    }

    private static function rows(int $count, string $prefix = 'Client'): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = ['nom' => $prefix . ' ' . $i, 'email' => 'c' . $i . '@ex.fr', 'note' => 'Été à « Besançon » — ligne ' . $i];
        }
        return $out;
    }

    public function testUneTableGardeToutesSesLignesDansLOrdre(): void
    {
        $conv = $this->conversation(1);
        $cols = WorkTableOps::columns(['Nom', 'Email', 'Note']);
        $table = $this->store->save($conv, 1, 'clients', 'Fichier clients', $cols, self::rows(450), ['kind' => 'collecte', 'source' => 'lignes_piece_jointe']);

        $this->assertSame('clients', $table['name']);
        $this->assertSame(450, $table['rows']);
        $this->assertSame(['nom', 'email', 'note'], WorkTableOps::keys($table['columns']));
        $this->assertSame('collecte', $table['origin']['kind']);

        $all = $this->store->rows((int)$table['id']);
        $this->assertCount(450, $all);
        $this->assertSame('Client 1', $all[0]['nom']);
        $this->assertSame('Client 450', $all[449]['nom']);
        $this->assertSame('Été à « Besançon » — ligne 201', $all[200]['note'], 'Accents et guillemets intacts.');

        // Lecture par pages : c'est ainsi que le modèle parcourt une table.
        $page = $this->store->rows((int)$table['id'], 440, 20);
        $this->assertSame(['Client 441', 'Client 450'], [$page[0]['nom'], $page[9]['nom']]);
        $this->assertCount(10, $page);
        $this->assertSame(['clients'], SqlWorkTableStore::names($conv));
    }

    public function testLeMemeNomRemplaceLaTable(): void
    {
        $conv = $this->conversation(1);
        $cols = WorkTableOps::columns(['Nom', 'Email', 'Note']);
        $first = $this->store->save($conv, 1, 'clients', 'Avant', $cols, self::rows(30));
        $second = $this->store->save($conv, 1, 'clients', 'Après', WorkTableOps::columns(['Nom']), [['nom' => 'Seul']]);

        $this->assertSame($first['id'], $second['id'], 'Remplacée, pas doublée.');
        $this->assertSame('Après', $second['title']);
        $this->assertSame([['nom' => 'Seul']], $this->store->rows((int)$second['id']));
        $this->assertSame(1, (int)$this->db()->query('SELECT COUNT(*) FROM work_tables')->fetchColumn());
        $this->assertSame(1, (int)$this->db()->query('SELECT COUNT(*) FROM work_table_rows')->fetchColumn(), 'Les anciennes lignes ne survivent pas.');
    }

    public function testUneTableNExisteQuePourSaConversation(): void
    {
        $mine   = $this->conversation(1);
        $theirs = $this->conversation(2);
        $cols   = WorkTableOps::columns(['Nom']);
        $table  = $this->store->save($mine, 1, 'clients', 'À moi', $cols, [['nom' => 'Acme']]);
        $this->store->save($theirs, 2, 'clients', 'À eux', $cols, [['nom' => 'Bolt'], ['nom' => 'Cirrus']]);

        $this->assertSame('À moi', $this->store->find($mine, 'clients')['title']);
        $this->assertSame(2, $this->store->find($theirs, 'clients')['rows']);
        $this->assertNull($this->store->findById($theirs, (int)$table['id']), 'L’identifiant d’une table ne suffit pas : il faut sa conversation.');
        $this->assertCount(1, $this->store->all($mine));
        $this->assertNull($this->store->find($mine, 'inconnue'));
    }

    public function testLesTablesDisparaissentAvecLaConversation(): void
    {
        $conv = $this->conversation(1);
        $this->store->save($conv, 1, 'clients', 'T', WorkTableOps::columns(['Nom']), self::rows(12));
        $this->store->save($conv, 1, 'mails', 'T', WorkTableOps::columns(['Nom']), self::rows(5));
        $this->assertTrue($this->store->delete($conv, 'mails'));
        $this->assertFalse($this->store->delete($conv, 'mails'));
        $this->assertSame(12, (int)$this->db()->query('SELECT COUNT(*) FROM work_table_rows')->fetchColumn());

        $this->db()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$conv]);
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM work_tables')->fetchColumn());
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM work_table_rows')->fetchColumn());
        $this->assertSame([], SqlWorkTableStore::names($conv));
    }

    public function testLePlafondDeTablesTientEtUnRemplacementPasseToujours(): void
    {
        $conv = $this->conversation(1);
        $cols = WorkTableOps::columns(['Nom']);
        for ($i = 1; $i <= WorkTableOps::MAX_TABLES; $i++) {
            $this->store->save($conv, 1, 't' . $i, 'T', $cols, [['nom' => 'x']]);
        }
        // Remplacer une table existante reste possible au plafond.
        $this->assertSame(2, $this->store->save($conv, 1, 't3', 'T', $cols, [['nom' => 'a'], ['nom' => 'b']])['rows']);
        try {
            $this->store->save($conv, 1, 'une_de_trop', 'T', $cols, [['nom' => 'x']]);
            $this->fail('Le plafond de tables doit refuser une table de plus.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string)WorkTableOps::MAX_TABLES, $e->getMessage());
        }
        $this->assertCount(WorkTableOps::MAX_TABLES, $this->store->all($conv));
    }

    public function testUneTableEstBorneeEnLignes(): void
    {
        $conv = $this->conversation(1);
        $rows = [];
        for ($i = 0; $i < WorkTableOps::MAX_ROWS + 25; $i++) {
            $rows[] = ['n' => (string)$i];
        }
        $table = $this->store->save($conv, 1, 'grande', 'G', WorkTableOps::columns(['N']), $rows);
        $this->assertSame(WorkTableOps::MAX_ROWS, $table['rows']);
        $this->assertSame((string)(WorkTableOps::MAX_ROWS - 1), $this->store->rows((int)$table['id'], WorkTableOps::MAX_ROWS - 1, 5)[0]['n']);
    }

    /** La route de téléchargement : la conversation de la personne, la table de cette conversation. */
    public function testLeTelechargementEstReserveALaConversationDeLaPersonne(): void
    {
        require_once ROOT . '/src/Auth/AuthMiddleware.php';
        require_once ROOT . '/src/Controllers/WorkTableController.php';
        $mine   = $this->conversation(1);
        $theirs = $this->conversation(2);
        $cols   = WorkTableOps::columns(['Nom', 'Email', ['key' => 'id', 'label' => 'Identifiant', 'tech' => true]]);
        $table  = $this->store->save($mine, 1, 'clients', 'Clients', $cols, [['nom' => 'Acme', 'email' => 'a@acme.fr', 'id' => 'secret-1']]);
        $other  = $this->store->save($theirs, 2, 'clients', 'Clients', $cols, [['nom' => 'Bolt', 'email' => 'b@bolt.io', 'id' => 'secret-2']]);

        $download = static function (int $conv, int $tableId, string $file): string {
            ob_start();
            try {
                (new WorkTableController())->download((string)$conv, (string)$tableId, $file);
            } finally {
                $body = (string)ob_get_clean();
            }
            return $body;
        };

        Session::set('user', User::findById(1));
        try {
            $link = WorkTableExport::link($mine, (int)$table['id'], 'clients.csv');
            $this->assertSame('/chat/' . $mine . '/tables/' . $table['id'] . '/clients.csv', $link);
            $body = $download($mine, (int)$table['id'], 'clients.csv');
            $this->assertStringContainsString('"Acme";"a@acme.fr"', $body);
            $this->assertStringNotContainsString('secret-1', $body, 'Les identifiants techniques ne partent pas dans le fichier.');

            // La table d'autrui, sa conversation, une table d'une autre conversation, un nom forgé : comme si rien n'existait.
            foreach ([
                [$theirs, (int)$other['id'], 'clients.csv'],
                [$mine, (int)$other['id'], 'clients.csv'],
                [$mine, (int)$table['id'], '../clients.csv'],
                [$mine, 999999, 'clients.csv'],
            ] as [$conv, $id, $file]) {
                $refused = $download($conv, $id, $file);
                $this->assertStringNotContainsString('Bolt', $refused);
                $this->assertStringNotContainsString('Acme', $refused);
                $this->assertStringContainsString('n’existe plus', $refused);
            }
        } finally {
            Session::remove('user');
        }
    }

    /** Les outils, sur le vrai magasin : collecte, croisement, comptage, fichier. */
    public function testUnRecoupementCompletSurLaBase(): void
    {
        $conv = $this->conversation(1);
        $gmail = new class {
            public function collectMessages(string $query, int $limit, ?\Closure $progress = null): array
            {
                $out = [];
                for ($i = 1; $i <= 120; $i++) {
                    // Deux emails sur trois viennent d'un client du fichier.
                    $out[] = ['id' => 'm' . $i, 'de' => 'X <c' . ($i % 3 === 0 ? 900 + $i : $i) . '@ex.fr>', 'a' => 'moi@ex.fr',
                        'objet' => 'Objet ' . $i, 'recu' => 1767225600 - $i * 60, 'extrait' => 'Texte'];
                }
                return ['messages' => $out, 'estimation' => 120, 'tronque' => false, 'echecs' => 0, 'interrompu' => false];
            }
        };
        $turn  = new AgentTurn(1, $conv, ['id' => 1], 4000, static function (array $e): void {}, static fn(string $t, array $a, ?array $p = null): array => []);
        $tools = new WorkTableTools($turn, $this->store, static fn(): array => ['gmail' => $gmail]);
        $call  = static fn(string $name, array $args): array => ($tools->handlers()[$name])($args);

        $this->store->save($conv, 1, 'clients', 'Fichier clients', WorkTableOps::columns(['Nom', 'Email', 'Note']), self::rows(200));
        $this->assertSame(120, $call(WorkTableTools::COLLECT, ['source' => 'emails_gmail', 'table' => 'mails'])['lignes']);

        $join = $call(WorkTableTools::JOIN, [
            'gauche' => 'clients', 'droite' => 'mails', 'cle_gauche' => 'email', 'cle_droite' => 'email',
            'comparaison' => 'email', 'garder' => 'sans_correspondance', 'vers' => 'silencieux',
        ]);
        $this->assertSame(80, $join['compteurs']['gauche_avec_correspondance']);
        $this->assertSame(120, $join['lignes'], '200 clients, 80 ont écrit : 120 manquent — compté par le serveur.');
        $this->assertSame(120, $this->store->find($conv, 'silencieux')['rows']);

        $count = $call(WorkTableTools::TRANSFORM, ['table' => 'mails', 'vers' => 'total', 'calculs' => [['operation' => 'compte_distinct', 'colonne' => 'email', 'nom' => 'Expéditeurs']]]);
        $this->assertSame('120', $count['resultat'][0]['expediteurs']);
        $this->assertNull($this->store->find($conv, 'total'), 'Un total revient dans le résultat : aucune table d’une ligne n’est créée.');

        // La consigne du tour suivant rappelle les tables, sans leurs lignes.
        $guidance = (new WorkTableTools($turn, $this->store, static fn(): array => []))->guidance();
        $this->assertStringContainsString('`silencieux`', $guidance);
        $this->assertStringContainsString('120 ligne(s)', $guidance);
        $this->assertStringNotContainsString('c1@ex.fr', $guidance);

        // Le fichier remis : toutes les lignes, colonnes visibles seulement.
        $table = $this->store->find($conv, 'mails');
        $file  = WorkTableExport::build($table, $this->store->rows((int)$table['id']), 'mails.csv', 'fr');
        $this->assertSame(121, substr_count($file->bytes, "\r\n"));
        $this->assertStringNotContainsString('"m1"', $file->bytes);
    }
}
