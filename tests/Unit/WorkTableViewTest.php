<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Les tables de travail telles que la PERSONNE les voit (WorkTableView) :
 * la ligne « Voir » sous une réponse, la puce de la zone de saisie, le volet.
 *
 * Ce que ces tests protègent :
 *   - une table ne s'affiche JAMAIS dans le fil — la ligne sous une réponse
 *     ne porte ni grille ni ligne de données, seulement un nom, un nombre et
 *     un bouton ;
 *   - le volet montre des lignes exactes : pages, filtre sans casse ni
 *     accents, total des lignes trouvées juste quelle que soit la taille ;
 *   - renommer ne touche qu'au titre (le nom court tenu par le modèle reste) ;
 *   - le câblage : routes, gabarits, script sans libellé, volets exclusifs.
 */
final class WorkTableViewTest extends TestCase
{
    private MemoryWorkTableStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryWorkTableStore();
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $path): string
    {
        return (string)file_get_contents(self::root() . '/' . $path);
    }

    /** @return list<array<string, string>> */
    private static function clients(int $count): array
    {
        $villes = ['Lyon', 'Besançon', 'Orléans', 'Paris'];
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = [
                'nom'   => 'Client ' . $i,
                'ville' => $villes[$i % 4],
                'ca'    => (string)($i * 100),
                'id'    => 'x' . $i,
            ];
        }
        return $out;
    }

    private function table(int $conv, string $name, string $title, int $rows, array $origin = []): array
    {
        $cols = WorkTableOps::columns([
            ['key' => 'nom', 'label' => 'Nom'],
            ['key' => 'ville', 'label' => 'Ville'],
            ['key' => 'ca', 'label' => 'CA 2026', 'type' => 'number'],
            ['key' => 'id', 'label' => 'Identifiant', 'tech' => true],
        ]);
        return $this->store->save($conv, 7, $name, $title, $cols, self::clients($rows), $origin);
    }

    // ------------------------------------------------------------------
    // Sous une réponse
    // ------------------------------------------------------------------

    public function testLesIdentifiantsGardesAvecUneReponseSontPropres(): void
    {
        $this->assertSame([3, 9], WorkTableView::refs([3, '9', 3, 0, -2, 'x', null, [4]]));
        $this->assertSame([], WorkTableView::refs('3'));
        $this->assertSame([], WorkTableView::refs(null));
        $this->assertCount(WorkTableView::REFS_MAX, WorkTableView::refs(range(1, 40)));
    }

    public function testSeulesLesTablesEncoreLaSontNommeesSousUneReponse(): void
    {
        $a = $this->table(12, 'clients', 'Fichier clients', 5);
        $b = $this->table(12, 'mails', 'Emails Gmail', 3);
        $messages = [
            ['id' => 1, 'role' => 'user', 'agent_json' => json_encode(['tables' => [$a['id']]])],
            ['id' => 2, 'role' => 'assistant', 'agent_json' => json_encode(['tables' => [$b['id'], $a['id'], 999]])],
            ['id' => 3, 'role' => 'assistant', 'agent_json' => json_encode(['plan' => ['etapes' => []]])],
            ['id' => 4, 'role' => 'assistant', 'agent_json' => null],
            ['id' => 5, 'role' => 'assistant', 'agent_json' => json_encode(['tables' => [999]])],
        ];

        $this->assertTrue(WorkTableView::anyRefs($messages));
        $lines = WorkTableView::forMessages($messages, $this->store->all(12));

        $this->assertSame([2], array_keys($lines), 'Ni le message de la personne, ni une table disparue.');
        $this->assertSame(['mails', 'clients'], array_column($lines[2], 'name'), 'Dans l’ordre gardé avec la réponse.');

        $this->assertSame([], WorkTableView::forMessages($messages, []), 'Plus aucune table : plus aucune ligne.');
        $this->assertFalse(WorkTableView::anyRefs([['id' => 3, 'role' => 'assistant', 'agent_json' => '{"plan":{}}']]));
    }

    public function testLaLigneSousUneReponseNeMontreJamaisLaTable(): void
    {
        $tables = [];
        foreach (['Clients à relancer', 'Emails de septembre', 'Suivi', 'Doublons', 'Archives'] as $i => $title) {
            $tables[] = $this->table(12, 't' . $i, $title, 37 + $i);
        }
        $line = WorkTableView::line($tables);

        $this->assertCount(WorkTableView::LINE_MAX, $line['items']);
        $this->assertSame('Clients à relancer', $line['items'][0]['label']);
        $this->assertSame('37 lignes · résultat', $line['items'][0]['meta']);
        $this->assertSame(4, $line['more']);
        $this->assertSame('+ 4 autres tables', $line['more_label']);

        $html = WorkTableView::lineHtml($tables);
        $this->assertStringContainsString('class="msg-tables"', $html);
        $this->assertSame(WorkTableView::LINE_MAX, substr_count($html, 'class="msg-table-chip"'), 'Une ligne, pas une rangée de cartes.');
        $this->assertStringContainsString('data-table-open="' . (int)$tables[0]['id'] . '"', $html);
        $this->assertStringContainsString('>Voir<', $html, 'Le geste est un bouton « Voir ».');
        $this->assertStringContainsString('data-table-open="0"', $html, '« + N autres tables » ouvre le volet sur la liste.');
        // Décision d'Axel (2026-11-16) : jamais de table au milieu de la conversation.
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('Client 1', $html, 'Aucune ligne de données dans le fil.');

        $this->assertSame('', WorkTableView::lineHtml([]), 'Rien à nommer : rien à poser.');
    }

    public function testLaLigneEchappeCeQueLeModeleAEcrit(): void
    {
        $t = $this->table(12, 'piege', '<img src=x onerror=alert(1)> & "co"', 2);
        $html = WorkTableView::lineHtml([$t]);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; &amp; &quot;co&quot;', $html);
    }

    public function testLaLigneSeLitEnAnglais(): void
    {
        $t = $this->table(12, 'clients', 'Clients', 1);
        I18n::use('en');
        $html = WorkTableView::lineHtml([$t]);
        $this->assertStringContainsString('>View<', $html);
        $this->assertStringContainsString('1 row', $html);
        $this->assertSame('3 results', WorkTableView::chip(3)['label']);
        $this->assertSame('Clients · 2 results', WorkTableView::chip(2, ['Clients'])['label']);
    }

    // ------------------------------------------------------------------
    // Puce et libellés
    // ------------------------------------------------------------------

    public function testLaPuceDitCombienLaConversationCompteDeTables(): void
    {
        $this->assertSame(['count' => 0, 'label' => '', 'title' => ''], WorkTableView::chip(0));
        $this->assertSame('1 résultat', WorkTableView::chip(1)['label']);
        $this->assertSame('Voir le résultat de MIA dans cette conversation', WorkTableView::chip(1)['title']);
        $this->assertSame('3 résultats', WorkTableView::chip(3)['label']);
        // La table de la personne d'abord, jamais un compte qui la mêle aux listes de MIA.
        $this->assertSame(['count' => 3, 'label' => 'Clients 2026 · 2 résultats', 'title' => 'Voir votre table, et ce que MIA en a tiré'], WorkTableView::chip(2, ['Clients 2026']));
        $this->assertSame('Clients 2026', WorkTableView::chip(0, ['Clients 2026'])['label']);
        $this->assertSame('2 tables · 1 résultat', WorkTableView::chip(1, ['A', 'B'])['label']);
        $this->assertSame('Voir les 3 résultats de MIA dans cette conversation', WorkTableView::chip(3)['title']);
        $this->assertSame(0, WorkTableView::chip(-4)['count']);
    }

    public function testLeNomAfficheEstLeTitreSinonLeNomCourtRenduLisible(): void
    {
        $this->assertSame('Clients à relancer', WorkTableView::label(['title' => 'Clients à relancer', 'name' => 'clients']));
        $this->assertSame('Clients sans reponse', WorkTableView::label(['title' => '', 'name' => 'clients_sans_reponse']));
        $this->assertSame('Mails', WorkTableView::label(['title' => 'Table', 'name' => 'mails']), '« Table » est le titre par défaut des outils : il ne dit rien.');
        $this->assertSame('Table', WorkTableView::label(['title' => '', 'name' => '']));
    }

    public function testLOrigineSeDitEnMots(): void
    {
        $this->assertSame('Emails Gmail — facture', WorkTableView::originLabel(['origin' => ['kind' => 'collecte', 'source' => 'emails_gmail', 'requete' => 'facture']]));
        $this->assertSame('Emails Gmail', WorkTableView::originLabel(['origin' => ['kind' => 'collecte', 'source' => 'emails_gmail', 'requete' => '']]));
        $this->assertSame('Établie par MIA', WorkTableView::originLabel(['origin' => ['kind' => 'saisie']]));
        $this->assertSame('Croisement de « clients » et « mails »', WorkTableView::originLabel(['origin' => ['kind' => 'croisement', 'gauche' => 'clients', 'droite' => 'mails']]));
        $this->assertSame('Tirée de « clients »', WorkTableView::originLabel(['origin' => ['kind' => 'transformation', 'depuis' => 'clients']]));
        $this->assertSame('', WorkTableView::originLabel(['origin' => []]));
        $this->assertSame('', WorkTableView::originLabel([]));
    }

    public function testUnTitreSaisiEstRameneAUneLigneBornee(): void
    {
        $this->assertSame('Clients à relancer', WorkTableView::cleanTitle("  Clients\n à   relancer \t"));
        $this->assertSame(WorkTableOps::TITLE_MAX, mb_strlen(WorkTableView::cleanTitle(str_repeat('é', 400))));
        foreach (['', "  \n\t ", null, ['x']] as $vide) {
            try {
                WorkTableView::cleanTitle($vide);
                $this->fail('Un titre vide est refusé.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Donnez un nom à la table.', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Volet : colonnes, filtre, pages
    // ------------------------------------------------------------------

    public function testLesColonnesTechniquesNeSontPasMontrees(): void
    {
        $t = $this->table(12, 'clients', 'Clients', 3);
        $this->assertSame(['nom', 'ville', 'ca'], array_column(WorkTableView::visibleColumns($t['columns']), 'key'));

        $techOnly = WorkTableOps::columns([['key' => 'id', 'label' => 'Identifiant', 'tech' => true]]);
        $this->assertSame(['id'], array_column(WorkTableView::visibleColumns($techOnly), 'key'), 'Une table qui n’a qu’elles les montre.');
    }

    public function testLeFiltreIgnoreCasseEtAccentsEtExigeTousLesMots(): void
    {
        $this->assertSame(['besancon', 'client'], WorkTableView::filterWords('  BESANÇON   Client besançon '));
        $this->assertSame([], WorkTableView::filterWords('   '));
        $this->assertSame([], WorkTableView::filterWords(['x']));

        $row = ['nom' => 'Client 7', 'ville' => 'Besançon', 'id' => 'secret'];
        $keys = ['nom', 'ville'];
        $this->assertTrue(WorkTableView::rowMatches($row, $keys, WorkTableView::filterWords('besancon')));
        $this->assertTrue(WorkTableView::rowMatches($row, $keys, WorkTableView::filterWords('CLIENT besançon')));
        $this->assertFalse(WorkTableView::rowMatches($row, $keys, WorkTableView::filterWords('besançon lyon')), 'Tous les mots, pas l’un d’eux.');
        $this->assertFalse(WorkTableView::rowMatches($row, $keys, WorkTableView::filterWords('secret')), 'Une colonne masquée ne se cherche pas.');
    }

    public function testUnePageDeLignesSansFiltre(): void
    {
        $t = $this->table(12, 'clients', 'Clients', 120);

        $p1 = WorkTableView::page($this->store, $t, '', 1);
        $this->assertSame([1, 3, 120, 120, 1, 50], [$p1['page'], $p1['pages'], $p1['matched'], $p1['total'], $p1['from'], $p1['to']]);
        $this->assertCount(WorkTableView::PAGE_ROWS, $p1['rows']);
        $this->assertSame(1, $p1['rows'][0]['n']);
        $this->assertSame('Client 1', $p1['rows'][0]['cells'][0]['text']);
        $this->assertCount(3, $p1['rows'][0]['cells'], 'La colonne technique n’est pas rendue.');
        $this->assertSame('number', $p1['rows'][0]['cells'][2]['type']);

        $p3 = WorkTableView::page($this->store, $t, '', 3);
        $this->assertSame([101, 120], [$p3['from'], $p3['to']]);
        $this->assertSame(120, $p3['rows'][19]['n']);

        $beyond = WorkTableView::page($this->store, $t, '', 99);
        $this->assertSame(3, $beyond['page'], 'Une page qui n’existe pas retombe sur la dernière.');
        $this->assertSame(1, WorkTableView::page($this->store, $t, '', -5)['page']);
    }

    public function testLeFiltreCompteJusteAuDelaDUnPassageDeLecture(): void
    {
        // 1 300 lignes : trois passages de lecture. Une ville sur quatre.
        $t = $this->table(12, 'clients', 'Clients', 1300);

        $p = WorkTableView::page($this->store, $t, 'besançon', 1);
        $this->assertSame(325, $p['matched'], 'Le total trouvé est exact, pas celui de la première tranche.');
        $this->assertSame(1300, $p['total']);
        $this->assertSame(7, $p['pages']);
        $this->assertCount(50, $p['rows']);
        $this->assertSame(1, $p['rows'][0]['n'], 'Le rang est celui de la ligne dans la table.');
        $this->assertSame(5, $p['rows'][1]['n']);
        $this->assertSame('besançon', $p['q']);

        $last = WorkTableView::page($this->store, $t, 'besançon', 7);
        $this->assertSame([301, 325], [$last['from'], $last['to']]);
        $this->assertSame(1297, $last['rows'][24]['n']);

        $clamped = WorkTableView::page($this->store, $t, 'besançon', 40);
        $this->assertSame(7, $clamped['page']);
        $this->assertCount(25, $clamped['rows']);

        $none = WorkTableView::page($this->store, $t, 'marseille', 1);
        $this->assertSame([0, [], 0, 0], [$none['matched'], $none['rows'], $none['from'], $none['to']]);
    }

    public function testUneCelluleLongueEstCoupeeALEcranEtEntiereEnInfobulle(): void
    {
        $cols = WorkTableOps::columns(['Note']);
        $long = str_repeat('mot ', 120);
        $t = $this->store->save(12, 7, 'notes', 'Notes', $cols, [['note' => $long], ['note' => "court\ntexte"]]);

        $p = WorkTableView::page($this->store, $t, '', 1);
        $this->assertSame(WorkTableView::CELL_CHARS, mb_strlen($p['rows'][0]['cells'][0]['text']));
        $this->assertStringEndsWith('…', $p['rows'][0]['cells'][0]['text']);
        $this->assertSame($long, $p['rows'][0]['cells'][0]['full']);
        $this->assertSame('court texte', $p['rows'][1]['cells'][0]['text'], 'Une cellule tient sur une ligne.');
        $this->assertSame('', $p['rows'][1]['cells'][0]['full']);
    }

    public function testLeVoletMontreLaListeEtLaTableChoisie(): void
    {
        $a = $this->table(12, 'clients', 'Fichier clients', 240, ['kind' => 'collecte', 'source' => 'lignes_piece_jointe', 'requete' => '']);
        $b = $this->table(12, 'sans_reponse', 'Clients sans réponse', 37, ['kind' => 'croisement', 'gauche' => 'clients', 'droite' => 'mails']);
        $this->table(99, 'ailleurs', 'Autre conversation', 5);

        $view = WorkTableView::panel($this->store, 12, 0, '', 1);
        $this->assertSame(2, $view['count']);
        $this->assertSame(WorkTableOps::MAX_TABLES, $view['max']);
        $this->assertSame((int)$b['id'], $view['selected']['id'], 'Sans choix : la plus récente.');
        $this->assertSame('37 lignes · 3 colonnes', $view['selected']['meta']);
        $this->assertSame([true, false], array_column($view['tables'], 'selected'));
        $this->assertFalse($view['busy']);

        $picked = WorkTableView::panel($this->store, 12, (int)$a['id'], 'lyon', 1, true);
        $this->assertSame((int)$a['id'], $picked['selected']['id']);
        $this->assertSame(60, $picked['selected']['page']['matched']);
        $this->assertTrue($picked['busy']);

        // Les liens d'export sont ceux que sert la route de téléchargement.
        foreach (['xlsx', 'csv'] as $format) {
            $link = $picked['selected'][$format];
            $this->assertStringStartsWith('/chat/12/tables/' . (int)$a['id'] . '/', $link);
            $this->assertTrue(WorkTableExport::isFileName(rawurldecode(basename($link))), $link);
            $this->assertStringEndsWith('fichier-clients.' . $format, $link);
        }

        $elsewhere = WorkTableView::panel($this->store, 12, 999999, '', 1);
        $this->assertSame((int)$b['id'], $elsewhere['selected']['id'], 'Une table d’ailleurs (ou disparue) : la plus récente de CETTE conversation.');
    }

    public function testLeVoletRenduPorteCeQueLeScriptEcoute(): void
    {
        $a = $this->table(12, 'clients', 'Fichier clients', 120);
        $html = WorkTableView::panelHtml(WorkTableView::panel($this->store, 12, (int)$a['id'], '', 2));

        foreach ([
            'data-tables-list', 'data-table-select="' . (int)$a['id'] . '"', 'data-table-id="' . (int)$a['id'] . '"',
            'data-table-rename', 'data-table-rename-form', 'data-table-rename-cancel', 'data-table-status',
            'data-table-delete', 'data-confirm="Supprimer « Fichier clients » (120 lignes) ?',
            'data-table-filter', 'data-tables-grid', 'data-table-page="1"', 'data-table-page="3"',
            '51 – 100 sur 120', '1 résultat ou vue sur ' . WorkTableOps::MAX_TABLES . ' au plus par conversation.',
            'Ils vivent avec la conversation : supprimés avec elle.', 'Résultats de MIA', '>Temporaire<',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('>Client 51<', $html);
        $this->assertStringNotContainsString('x51', $html, 'La colonne technique reste hors de l’écran.');
        $this->assertStringContainsString('class="is-num"', $html, 'Les nombres s’alignent à droite.');
    }

    public function testLeVoletVideDitCommentNaitUneTable(): void
    {
        $html = WorkTableView::panelHtml(WorkTableView::panel($this->store, 12, 0, '', 1));
        $this->assertStringContainsString('Aucune table dans cette conversation', $html);
        $this->assertStringNotContainsString('data-tables-grid', $html);
    }

    public function testUneTableVideEtUnFiltreSansResultatSeDisent(): void
    {
        $cols = WorkTableOps::columns(['Nom']);
        $empty = $this->store->save(12, 7, 'vide', 'Vide', $cols, []);
        $html = WorkTableView::panelHtml(WorkTableView::panel($this->store, 12, (int)$empty['id'], '', 1));
        $this->assertStringContainsString('Cette table est vide.', $html);
        $this->assertStringNotContainsString('data-table-filter', $html, 'Rien à filtrer.');
        $this->assertStringNotContainsString('.xlsx', $html, 'Rien à télécharger.');

        $t = $this->table(12, 'clients', 'Clients', 10);
        $html = WorkTableView::panelHtml(WorkTableView::panel($this->store, 12, (int)$t['id'], '<b>zzz', 1));
        $this->assertStringContainsString('Aucune ligne ne contient « &lt;b&gt;zzz ».', $html);
        $this->assertStringContainsString('value="&lt;b&gt;zzz"', $html, 'Le filtre reste dans son champ, échappé.');
    }

    public function testUneOperationEnCoursRetientLaSuppression(): void
    {
        $t = $this->table(12, 'clients', 'Clients', 10);
        $html = WorkTableView::panelHtml(WorkTableView::panel($this->store, 12, (int)$t['id'], '', 1, true));
        $this->assertMatchesRegularExpression('/data-table-delete disabled/', $html);
        $this->assertStringContainsString('la suppression attend sa fin', $html);
    }

    // ------------------------------------------------------------------
    // Renommer, tables touchées par un tour
    // ------------------------------------------------------------------

    public function testRenommerNeToucheQuAuTitre(): void
    {
        $t = $this->table(12, 'clients', 'Fichier clients', 20);
        $stamp = WorkTableOps::stateStamp($t);

        $renamed = $this->store->retitle(12, (int)$t['id'], 'Clients 2026');
        $this->assertSame('Clients 2026', $renamed['title']);
        $this->assertSame('clients', $renamed['name'], 'Le nom court tenu par le modèle ne bouge pas.');
        $this->assertSame($stamp, WorkTableOps::stateStamp($renamed), 'Une opération de fond en cours sur la table continue.');
        $this->assertCount(20, $this->store->rows((int)$t['id']));

        $this->assertNull($this->store->retitle(99, (int)$t['id'], 'Vol'), 'La table d’une autre conversation n’existe pas.');
        $this->assertSame('Clients 2026', $this->store->find(12, 'clients')['title']);
    }

    public function testLesOutilsRetiennentLesTablesDuTour(): void
    {
        $turn = new AgentTurn(
            7,
            12,
            ['id' => 7, 'role' => 'user'],
            4000,
            static function (array $event): void {
            },
            static fn(string $tool, array $args, ?array $preview = null): array => ['error' => 'aucune écriture attendue'],
            'Europe/Paris'
        );
        $tools = new WorkTableTools($turn, $this->store, static fn(): array => []);
        $handlers = $tools->handlers();
        $this->assertSame([], $tools->touched());

        $handlers[WorkTableTools::WRITE](['table' => 'clients', 'colonnes' => ['Nom', 'Ville'], 'lignes' => [['Acme', 'Lyon'], ['Bolt', 'Paris'], ['Cesar', 'Lyon']]]);
        $handlers[WorkTableTools::TRANSFORM](['table' => 'clients', 'vers' => 'lyonnais', 'filtres' => [['colonne' => 'ville', 'test' => 'egal', 'valeur' => 'Lyon']]]);
        $clients  = $this->store->find(12, 'clients');
        $lyonnais = $this->store->find(12, 'lyonnais');
        $this->assertNotNull($lyonnais);
        $this->assertSame([(int)$lyonnais['id'], (int)$clients['id']], $tools->touched(), 'La plus récente d’abord : c’est le résultat qu’on vient voir.');

        $handlers[WorkTableTools::VIEW](['table' => 'clients']);
        $this->assertSame([(int)$clients['id'], (int)$lyonnais['id']], $tools->touched(), 'Une table consultée remonte, sans doublon.');

        $handlers[WorkTableTools::VIEW](['table' => 'inconnue']);
        $this->assertCount(2, $tools->touched(), 'Un appel en erreur ne nomme rien.');
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLesRoutesDuVoletExistentEtNeSeRecouvrentPas(): void
    {
        $index = self::read('public/index.php');
        foreach ([
            "\$router->get('/chat/{id}/tables', [WorkTableController::class, 'index']);",
            "\$router->post('/chat/{id}/tables/{table}/rename', [WorkTableController::class, 'rename']);",
            "\$router->post('/chat/{id}/tables/{table}/delete', [WorkTableController::class, 'delete']);",
            "\$router->get('/chat/{id}/tables/{table}/{file}', [WorkTableController::class, 'download']);",
        ] as $route) {
            $this->assertStringContainsString($route, $index);
        }

        $controller = self::read('src/Controllers/WorkTableController.php');
        // Chaque route vérifie la conversation PUIS la table de cette conversation.
        // Liste, gestes (renommer, supprimer, garder), téléchargement, et le téléchargement d'une table permanente.
        $this->assertSame(4, substr_count($controller, 'Conversation::belongsToUser('));
        $this->assertStringContainsString('->findById($convId, (int)$table)', $controller);
        $this->assertStringContainsString("JobQueue::activeByDedupKey('table_mission:' . \$convId)", $controller, 'Pas de suppression sous une opération de fond.');
        $this->assertStringContainsString('session_write_close();', $controller);
    }

    public function testLaVueEstChargeeDesDeuxCotes(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $list) {
            $this->assertStringContainsString('/src/Tables/WorkTableView.php', self::read($list), $list);
        }
    }

    public function testLeRenommageGardeLaDateDEcritureEnBase(): void
    {
        $sql = self::read('src/Tables/SqlWorkTableStore.php');
        $this->assertStringContainsString('SET title = ?, updated_at = updated_at WHERE conversation_id = ? AND id = ?', $sql,
            'ON UPDATE CURRENT_TIMESTAMP changerait l’empreinte de la table : une opération en cours s’arrêterait.');
    }

    public function testLeFilNomeLaTableSansLAfficher(): void
    {
        $message = self::read('templates/partials/message.php');
        $content = strpos($message, 'class="msg-content"');
        $line    = strpos($message, "include ROOT . '/templates/partials/work-table-line.php'");
        $badge   = strpos($message, 'class="msg-llm-badge"');
        $this->assertNotFalse($content);
        $this->assertNotFalse($line);
        $this->assertTrue($content < $line && $line < $badge, 'Sous le contenu, hors de lui : ni copiée, ni exportée.');

        $partial = self::read('templates/partials/work-table-line.php');
        $this->assertStringNotContainsString('<table', $partial);
        $this->assertStringContainsString("te('Voir', [], 'bouton')", $partial);

        $chat = self::read('public/assets/js/chat.js');
        $this->assertStringContainsString("window.MiaTables?.place(div, options.tablesHtml)", $chat, 'Même HTML que message.php.');
        $this->assertSame(2, substr_count($chat, "tablesHtml: msg.tables_html || ''"), 'Historique et synchronisation du fil.');
        $this->assertStringContainsString("window.MiaTables?.afterTurn(state.el?.closest('.message') || null, json.tables)", $chat);
        $this->assertStringContainsString("window.MiaTables?.setChip(data.tables?.chip || null)", $chat, 'La puce suit la conversation affichée.');
        $this->assertMatchesRegularExpression("/querySelectorAll\\('[^']*\\.msg-tables[^']*'\\)/", $chat, 'Copier ou exporter une réponse n’emporte pas la ligne.');

        $controller = self::read('src/Controllers/ChatController.php');
        $this->assertStringContainsString("'tables' => \$turnTableIds]", $controller, 'Gardées avec la réponse.');
        $this->assertStringContainsString("'tables_html'  => isset(\$tableLines[(int)\$m['id']])", $controller, 'Historique JSON.');
        $this->assertStringContainsString('[$workTablesChip, $tableLines] = self::workTablesFor($convId, $messages, self::engagedTables($convId, $conversation, $user));', $controller, 'Chargement de la page.');
        $this->assertStringContainsString("Message::setAgent(\$msgId, ['tables' => [\$tableRef]]);", self::read('src/Jobs/TableMissionJob.php'), 'Bilan d’une opération de fond.');
    }

    public function testLeVoletEstFermeParDefautEtExclusif(): void
    {
        $page = self::read('templates/chat/index.php');
        $this->assertStringContainsString('<div id="tablesViewer" class="html-viewer tables-viewer"', $page, 'Même coque que les autres volets, sans la classe « open ».');
        $this->assertStringNotContainsString('tables-viewer open', $page);
        $this->assertStringContainsString('id="tablesChip"', $page);
        $this->assertStringContainsString("(int)\$wtChip['count'] > 0 ? '' : ' hidden'", $page, 'Aucune table : aucune puce.');
        $this->assertStringContainsString('/assets/js/work-tables.js', $page);
        $this->assertStringContainsString('/assets/css/work-tables.css', $page);
        $this->assertTrue(strpos($page, '/assets/js/chat.js') < strpos($page, '/assets/js/work-tables.js'), 'Après chat.js.');

        $chat = self::read('public/assets/js/chat.js');
        $this->assertStringContainsString("document.getElementById('tablesViewer')?.classList.contains('open')", $chat, 'Compté parmi les volets ouverts.');
        $this->assertSame(6, substr_count($chat, "    closeDeckViewer();\n    closeTablesViewer();\n"), 'Fermé partout où un autre volet s’ouvre, et au changement de conversation.');
        $this->assertStringContainsString("bindViewerResizeHandle(document.getElementById('tablesViewerResizer'));", $chat);
        $this->assertStringContainsString("if (typeof closeTablesViewer === 'function') closeTablesViewer();", self::read('public/assets/js/deck.js'));

        $script = self::read('public/assets/js/work-tables.js');
        foreach (['closeHtmlViewer', 'closeVideoViewer', 'closeSourcesViewer', 'closeMapViewer', 'closeDeckViewer'] as $other) {
            $this->assertStringContainsString("'" . $other . "'", $script, 'Un seul volet à la fois.');
        }
        // Rien ne l'ouvre tout seul : seuls un clic sur « Voir » ou sur la puce.
        $this->assertSame(2, preg_match_all('/\bopen\((?:opener\.getAttribute\(|0\))/', $script));
        $this->assertStringNotContainsString('DOMContentLoaded', $script);
    }

    public function testLeScriptDuVoletNAAucunLibelle(): void
    {
        $script = self::read('public/assets/js/work-tables.js');
        // Hors commentaires : ni appel au dictionnaire client, ni lettre accentuée.
        $code = (string)preg_replace('~^\s*//.*$~m', '', (string)preg_replace('~/\*.*?\*/~s', '', $script));
        $this->assertDoesNotMatchRegularExpression('/\bt\(|\btn\(/', $code, 'Tout texte vient du serveur.');
        $this->assertDoesNotMatchRegularExpression('/[àâçéèêëîïôùûüœ]/iu', $code);
        $this->assertStringContainsString("'X-CSRF-Token': csrf()", $code);
        $this->assertStringContainsString("window.confirm(String(button.dataset.confirm || ''))", $code, 'Supprimer demande confirmation.');
    }

    public function testLesStylesTiennentLePiegeHiddenEtLeTelephone(): void
    {
        $css = self::read('public/assets/css/work-tables.css');
        $this->assertStringContainsString('.tables-viewer [hidden] { display: none !important; }', $css);
        $this->assertStringContainsString('.composer-tables-chip[hidden] { display: none; }', $css);
        $this->assertMatchesRegularExpression('/\.input-actions-left > \.composer-tables-chip \{[^}]*order:\s*1;[^}]*flex-shrink:\s*1;/s', $css,
            'Après « + », assistants et bases, seule à rétrécir.');
        $this->assertStringContainsString('@media (pointer: coarse)', $css);
        $this->assertDoesNotMatchRegularExpression('/\d(?:vh|vw|dvh|svh)\b/', $css, 'Aucune unité d’écran (taille d’affichage).');
    }

    public function testLeModeleNeRecopiePasLaTableDansSaReponse(): void
    {
        $tools = self::read('src/Agent/Tools/WorkTableTools.php');
        $this->assertStringContainsString('ne recopie pas une table dans ta réponse', $tools);
        $this->assertStringContainsString("elle s'ouvre d'un clic sous ta réponse", $tools);
    }

    public function testLesGuidesDisentCommentVoirUneTable(): void
    {
        foreach (['docs/GUIDE-UTILISATEUR.md', 'docs/NOUVEAUTES.md', 'docs/user/fonctionnalites/connecteurs/voir-ses-tables.md', 'docs/user/fonctionnalites/connecteurs/recouper-des-sources.md'] as $doc) {
            $text = self::read($doc);
            $this->assertStringContainsString('« Voir »', $text, $doc);
        }
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLeGuideInterneEnParle(): void
    {
        $this->assertStringContainsString('WorkTableView', self::read('CLAUDE.md'));
    }

    // ------------------------------------------------------------------
    // Tables permanentes engagées dans la conversation
    // ------------------------------------------------------------------

    /** Une table permanente, telle que la rend KbTableStore::find(). */
    private static function permanent(int $kbId, string $title, int $rows): array
    {
        return [
            'id' => $kbId, 'kb_id' => $kbId, 'name' => WorkTableOps::slug($title), 'title' => $title,
            'columns' => WorkTableOps::columns([
                ['key' => 'nom', 'label' => 'Nom'],
                ['key' => 'ville', 'label' => 'Ville'],
                ['key' => 'ca', 'label' => 'CA 2026', 'type' => 'number'],
            ]),
            'rows' => $rows, 'origin' => ['kind' => 'fichier', 'file' => 'clients.xlsx'], 'permanent' => true,
        ];
    }

    private static function permanentRows(int $count): WorkTableRows
    {
        return new class(self::clients($count)) implements WorkTableRows {
            public function __construct(private array $rows)
            {
            }
            public function rows(int $tableId, int $offset = 0, ?int $limit = null): array
            {
                return array_slice($this->rows, $offset, $limit);
            }
        };
    }

    public function testUneTablePermanenteSeDesigneParSaBase(): void
    {
        $this->assertSame([3, 'p12', 9], WorkTableView::refs([3, 'p12', '9', 'p12', 'p0', 'px', 'p-4', ' p7', null]));
        $this->assertSame('p12', WorkTableView::ref('p12'));
        $this->assertSame(0, WorkTableView::ref('p12 OR 1=1'), 'Rien d’autre qu’un identifiant.');
        $this->assertSame(0, WorkTableView::ref(['p12']));
        $this->assertSame('p44', WorkTableView::refOf(self::permanent(44, 'Clients 2026', 3)));
        $this->assertSame(5, WorkTableView::refOf(['id' => 5]));
    }

    public function testUneReponseQuiNommeUneTablePermanenteLaProposeAussi(): void
    {
        $clients = self::permanent(44, 'Clients 2026', 120);
        $short   = self::permanent(45, 'Suivi', 9);
        $all     = [$clients, $short];
        // Le modèle répond de mémoire de la consigne, sans appeler d'outil.
        $this->assertSame(['p44'], WorkTableView::namedIn('La table **CLIENTS 2026** compte 120 lignes.', $all));
        $this->assertSame(['p44'], WorkTableView::namedIn('`clients_2026` : 120 lignes.', $all), 'Son nom court la désigne aussi.');
        $this->assertSame([], WorkTableView::namedIn('Votre suivi du mois compte 9 lignes.', $all), 'Un titre trop court se retrouverait partout.');
        $this->assertSame([], WorkTableView::namedIn('Bonjour !', $all));
        $this->assertSame([], WorkTableView::namedIn('', $all));

        $tools = WorkTableViewTest::read('src/Controllers/ChatController.php');
        $this->assertStringContainsString('WorkTableView::namedIn($fullResp, $permanentTables)', $tools);
    }

    public function testLaTablePermanenteLueParUneReponseSeNommeSousElle(): void
    {
        $work = $this->table(12, 'parisiens', 'Parisiens', 30);
        $perm = self::permanent(44, 'Clients 2026', 120);
        $messages = [
            ['id' => 2, 'role' => 'assistant', 'agent_json' => json_encode(['tables' => ['p44']])],
            ['id' => 3, 'role' => 'assistant', 'agent_json' => json_encode(['tables' => [$work['id'], 'p44', 'p99']])],
        ];
        $lines = WorkTableView::forMessages($messages, $this->store->all(12), [$perm]);
        $this->assertSame([2, 3], array_keys($lines));
        $this->assertSame(['Clients 2026'], array_column($lines[2], 'title'));
        $this->assertSame(['Parisiens', 'Clients 2026'], array_column($lines[3], 'title'), 'Une table permanente d’ailleurs (p99) ne se nomme pas.');

        // Sans table de travail du tout, elle se nomme quand même — c'était le défaut.
        $alone = WorkTableView::forMessages($messages, [], [$perm]);
        $this->assertSame(['Clients 2026'], array_column($alone[2], 'title'));
        // Décochée depuis : plus de ligne.
        $this->assertSame([], WorkTableView::forMessages([$messages[0]], $this->store->all(12), []));

        $line = WorkTableView::line($lines[2]);
        $this->assertSame('p44', $line['items'][0]['id']);
        $this->assertSame('120 lignes · table permanente', $line['items'][0]['meta']);
        $this->assertTrue($line['items'][0]['permanent']);
        $html = WorkTableView::lineHtml($lines[2]);
        $this->assertStringContainsString('data-table-open="p44"', $html);
        $this->assertStringContainsString('msg-table-chip is-permanent', $html);
        $this->assertStringNotContainsString('<table', $html, 'Jamais la grille dans le fil.');
    }

    public function testLeVoletListeEtOuvreLaTablePermanenteEnLectureSeule(): void
    {
        $perm   = self::permanent(44, 'Clients 2026', 120);
        $reader = self::permanentRows(120);

        // Aucune table de travail : la table permanente est là, et c'est elle qui s'ouvre.
        $view = WorkTableView::panel($this->store, 12, 0, '', 1, false, true, [$perm], $reader, true);
        $this->assertSame(0, $view['count']);
        $this->assertSame(1, $view['permanent_count']);
        $this->assertSame(1, $view['total']);
        $this->assertSame('p44', $view['selected']['id']);
        $this->assertTrue($view['selected']['permanent']);
        $this->assertSame('120 lignes · 3 colonnes', $view['selected']['meta']);
        $this->assertSame('Importée de clients.xlsx', $view['selected']['origin']);
        $this->assertSame('/admin/knowledge-bases/44/edit', $view['selected']['kb_url']);
        $this->assertStringStartsWith('/chat/12/tables/p44/', $view['selected']['xlsx']);
        $this->assertSame(120, $view['selected']['page']['total']);
        $this->assertCount(WorkTableView::PAGE_ROWS, $view['selected']['page']['rows']);

        $html = WorkTableView::panelHtml($view);
        foreach ([
            'data-table-select="p44"', 'data-table-id="p44"', 'wt-badge', '>Votre table<', '>Vos tables<', '>Client 1<',
            'data-table-filter', 'href="/admin/knowledge-bases/44/edit"', 'Ouvrir sa fiche',
            'Votre table, rangée dans Bases de connaissances',
            'Votre table reste dans Bases de connaissances',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // Elle se tient dans sa fiche : ni renommée, ni supprimée, ni « gardée » d'ici.
        foreach (['data-table-rename', 'data-table-delete', 'data-table-keep', 'Aucune table dans cette conversation', 'au plus par conversation'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }

        // Filtre et pages : les mêmes que pour une table de travail.
        $lyon = WorkTableView::panel($this->store, 12, 'p44', 'lyon', 1, false, false, [$perm], $reader);
        $this->assertSame(30, $lyon['selected']['page']['matched']);
        $this->assertSame('', $lyon['selected']['kb_url'], 'Sans le droit de voir les bases : pas de lien vers la fiche.');

        // Avec des résultats de MIA : « Vos tables » d'abord — c'est elle qui s'ouvre —, les résultats ensuite.
        $work  = $this->table(12, 'parisiens', 'Parisiens', 30);
        $mixed = WorkTableView::panel($this->store, 12, 0, '', 1, false, true, [$perm], $reader);
        $this->assertSame(['p44', (int)$work['id']], array_column($mixed['tables'], 'id'));
        $this->assertSame(['p44'], array_column($mixed['mine'], 'id'));
        $this->assertSame([(int)$work['id']], array_column($mixed['results'], 'id'));
        $this->assertSame('p44', $mixed['selected']['id']);
        $this->assertFalse($mixed['results_open'], 'Les résultats restent repliés quand on regarde sa table.');
        $this->assertSame([true, false], array_column($mixed['tables'], 'permanent'));
        $this->assertSame(2, $mixed['total']);
        $picked = WorkTableView::panel($this->store, 12, 'p44', '', 1, false, true, [$perm], $reader);
        $this->assertSame('p44', $picked['selected']['id']);
        // Une table permanente qui n'est pas engagée ici ne s'ouvre pas : on retombe sur la première.
        $other = WorkTableView::panel($this->store, 12, 'p99', '', 1, false, true, [$perm], $reader);
        $this->assertSame('p44', $other['selected']['id']);
    }

    public function testLEcranSuitLesTablesPermanentes(): void
    {
        $script = self::read('public/assets/js/work-tables.js');
        $this->assertStringContainsString('/^p[1-9]\d{0,9}(\.\d{1,6}\.[A-Za-z0-9_-]{4,1800})?$/', $script, 'Le script garde une référence « p12 » (ou « p12.5.<recette> ») telle quelle.');
        $this->assertStringContainsString("state.table = ref(data.selected);", $script);
        $this->assertStringContainsString("window.addEventListener('mia:card-decided'", $script, 'Une modification approuvée : le volet se recharge.');
        $this->assertStringContainsString("document.addEventListener('mia:kb-selection-changed'", $script, 'Une table cochée ou décochée : la puce suit.');
        $this->assertStringContainsString("new CustomEvent('mia:kb-selection-changed')", self::read('public/assets/js/chat.js'));

        $ctrl = self::read('src/Controllers/WorkTableController.php');
        $this->assertStringContainsString("WorkTableView::ref(\$_GET['table'] ?? 0)", $ctrl);
        $this->assertStringContainsString("'chip'     => ChatController::tablesChip((new SqlWorkTableStore())->all(\$convId), \$engaged)", $ctrl, 'La puce nomme aussi les tables permanentes.');
        $this->assertStringContainsString('self::engaged($convId)', $ctrl);

        $chat = self::read('src/Controllers/ChatController.php');
        $this->assertStringContainsString('self::turnTablesPayload($convId, $turnTableIds, $tablesPossible ? $permanentTables : null)', $chat);
        $this->assertStringContainsString('KbTableStore::engaged($convId, $user ?: null, ', $chat);
        $this->assertStringContainsString('.wt-badge', self::read('public/assets/css/work-tables.css'));
    }
}
