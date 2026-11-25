<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tables PERMANENTES dans les outils de tables (WorkTableTools), sur un
 * magasin en mémoire : MIA les LIT comme une table de la conversation
 * (compter, filtrer, croiser, exporter — le résultat reste dans la
 * conversation), n'y écrit JAMAIS directement, et ne peut que PROPOSER une
 * écriture, sous une carte qui montre le changement.
 */
final class KbTableToolsTest extends TestCase
{
    private const KB = 990001;

    private MemoryWorkTableStore $store;

    /** @var list<array{0:string,1:array,2:?array}> écritures déposées dans la file d'approbation */
    private array $queued = [];

    protected function setUp(): void
    {
        $this->store  = new MemoryWorkTableStore();
        $this->queued = [];
    }

    private function turn(): AgentTurn
    {
        return new AgentTurn(
            7,
            12,
            ['id' => 7, 'role' => 'creator'],
            4000,
            static function (array $event): void {
            },
            function (string $tool, array $args, ?array $preview = null): array {
                $this->queued[] = [$tool, $args, $preview];
                return ['statut' => 'en_attente_approbation'];
            },
            'Europe/Paris'
        );
    }

    /** Lignes de la table permanente « Clients 2026 ». */
    private static function clientRows(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['nom' => 'Client ' . $i, 'email' => 'c' . $i . '@ex.fr', 'ville' => $i % 4 === 0 ? 'Paris' : 'Lyon', 'statut' => ''];
        }
        return $rows;
    }

    private static function permanent(int $rows, bool $writable = true): array
    {
        return [
            'id'        => self::KB,
            'kb_id'     => self::KB,
            'name'      => 'clients_2026',
            'title'     => 'Clients 2026',
            'columns'   => WorkTableOps::columns(['Nom', 'Email', 'Ville', 'Statut']),
            'rows'      => $rows,
            'origin'    => ['kind' => 'fichier', 'file' => 'clients.xlsx'],
            'version'   => 1,
            'mia_write' => $writable,
            'writable'  => $writable,
            'shared'    => 2,
            'permanent' => true,
        ];
    }

    private static function rowsReader(array $rows): WorkTableRows
    {
        return new class($rows) implements WorkTableRows {
            public int $reads = 0;
            public function __construct(private array $rows)
            {
            }
            public function rows(int $tableId, int $offset = 0, ?int $limit = null): array
            {
                $this->reads++;
                return array_slice($this->rows, $offset, $limit);
            }
        };
    }

    private function tools(array $permanent, ?WorkTableRows $reader): WorkTableTools
    {
        return new WorkTableTools($this->turn(), $this->store, static fn(): array => [], [], '', [], null, $permanent, $reader);
    }

    private static function call(WorkTableTools $tools, string $name, array $args): array
    {
        return ($tools->handlers()[$name])($args);
    }

    // ---------------------------------------------------------------------
    // 1. Lecture
    // ---------------------------------------------------------------------

    public function testUneTablePermanenteSeLitCommeUneTableDeLaConversation(): void
    {
        $tools = $this->tools([self::permanent(120)], self::rowsReader(self::clientRows(120)));

        // Compter : le serveur lit toutes les lignes, le résultat va dans la conversation.
        $out = self::call($tools, WorkTableTools::TRANSFORM, [
            'table' => 'Clients 2026', 'vers' => 'par_ville', 'regrouper_par' => ['Ville'], 'calculs' => [['operation' => 'compte']],
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('par_ville', $out['table']);
        $this->assertSame(120, $out['lignes_avant']);
        $counts = [];
        foreach ($this->store->rows((int)$this->store->find(12, 'par_ville')['id']) as $r) {
            $counts[$r['ville']] = (int)$r['nombre'];
        }
        ksort($counts);
        $this->assertSame(['Lyon' => 90, 'Paris' => 30], $counts);

        // Aperçu : par pages, avec la suite annoncée.
        $view = self::call($tools, WorkTableTools::VIEW, ['table' => 'clients_2026', 'lignes' => 5]);
        $this->assertSame(5, $view['a']);
        $this->assertStringContainsString('Il reste 115 ligne(s)', $view['suite']);

        // Sans nom : les tables permanentes sont listées à part, avec ce que MIA peut y faire.
        $list = self::call($tools, WorkTableTools::VIEW, []);
        $this->assertSame('clients_2026', $list['tables_permanentes'][0]['table']);
        $this->assertSame('après accord', $list['tables_permanentes'][0]['ecriture']);
        $this->assertNotContains('clients_2026', array_column($list['tables'], 'table'), 'Elle n’est pas une table de la conversation.');
    }

    public function testUneVueNEstJamaisCoupeeCarRienNEstCopie(): void
    {
        // Plus de lignes qu'une table de la conversation n'en garde : une VUE
        // ne copie rien, elle n'a donc pas de plafond.
        $n     = WorkTableOps::MAX_ROWS + 40;
        $tools = $this->tools([self::permanent($n)], self::rowsReader(self::clientRows($n)));
        $out   = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'copie', 'trier_par' => [['colonne' => 'Nom', 'sens' => 'decroissant']]]);
        $this->assertArrayNotHasKey('incomplet', $out);
        $this->assertSame($n, $out['lignes']);
        $this->assertSame('Clients 2026', $out['vue_de']);
        $view = $this->store->find(12, 'copie');
        $this->assertSame(0, (int)$view['rows'], 'Aucune ligne n’est stockée.');
        $this->assertSame('vue', $view['origin']['kind']);

        // Un regroupement, lui, crée un résultat (temporaire), dit comme tel.
        $grouped = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'par_ville', 'regrouper_par' => ['Ville']]);
        $this->assertArrayNotHasKey('vue_de', $grouped);
        $this->assertStringContainsString('résultat temporaire', $grouped['note']);
        $this->assertSame('Clients 2026', WorkTableView::basesOf($this->store->find(12, 'par_ville'))[0]['titre']);
    }

    public function testLExportRendUnLienVersLaTablePermanente(): void
    {
        $tools = $this->tools([self::permanent(120)], self::rowsReader(self::clientRows(120)));
        $out   = self::call($tools, WorkTableTools::EXPORT, ['table' => 'clients_2026']);
        $this->assertSame('/chat/12/tables/p' . self::KB . '/' . $out['fichier'], $out['lien']);
        $this->assertTrue(WorkTableExport::isFileName($out['fichier']));
    }

    // ---------------------------------------------------------------------
    // 2. Jamais d'écriture directe
    // ---------------------------------------------------------------------

    public function testAucunOutilNEcritDirectementDansUneTablePermanente(): void
    {
        $reader = self::rowsReader(self::clientRows(10));
        $tools  = $this->tools([self::permanent(10)], $reader);

        // Son nom n'est pas disponible pour une table de travail.
        foreach ([
            [WorkTableTools::WRITE, ['table' => 'clients_2026', 'colonnes' => ['Nom'], 'lignes' => [['x']]]],
            [WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'clients_2026']],
        ] as [$tool, $args]) {
            $out = self::call($tools, $tool, $args);
            $this->assertStringContainsString('table permanente', (string)($out['error'] ?? ''), $tool);
        }
        $this->assertSame([], $this->store->all(12), 'Rien n’a été créé sous ce nom.');
        $this->assertSame([], $this->queued);
    }

    public function testUneTableNonJointeAuMessageEstInconnue(): void
    {
        $tools = $this->tools([], null);
        $out   = self::call($tools, WorkTableTools::VIEW, ['table' => 'clients_2026']);
        $this->assertStringContainsString('inconnue', (string)$out['error']);
        $names = array_column($tools->definitions(), 'name');
        $this->assertNotContains(WorkTableTools::PERM_WRITE, $names);
    }

    // ---------------------------------------------------------------------
    // 3. Proposer une écriture
    // ---------------------------------------------------------------------

    public function testLOutilDEcritureNExisteQuePourUneTableOuverteALEcriture(): void
    {
        $readOnly = $this->tools([self::permanent(10, false)], self::rowsReader(self::clientRows(10)));
        $this->assertNotContains(WorkTableTools::PERM_WRITE, array_column($readOnly->definitions(), 'name'));

        $writable = $this->tools([self::permanent(10)], self::rowsReader(self::clientRows(10)));
        $defs     = array_column($writable->definitions(), null, 'name');
        $this->assertArrayHasKey(WorkTableTools::PERM_WRITE, $defs);
        $props = $defs[WorkTableTools::PERM_WRITE]['parameters']['properties'];
        $this->assertSame(['clients_2026'], $props['table']['enum']);
        $this->assertSame(KbTableWrite::MODES, $props['mode']['enum']);
        $this->assertSame('string', $props['definir']['type'], 'Un objet libre serait refusé par certains modèles : JSON en texte.');

        // La consigne nomme la table et dit la règle.
        $guidance = (string)$writable->guidance();
        $this->assertStringContainsString('clients_2026', $guidance);
        $this->assertStringContainsString(WorkTableTools::PERM_WRITE, $guidance);
    }

    public function testUneEcritureEstCalculeePuisDeposeeSousUneCarte(): void
    {
        $tools = $this->tools([self::permanent(10)], self::rowsReader(self::clientRows(10)));
        // Ce que MIA a établi dans la conversation.
        self::call($tools, WorkTableTools::WRITE, [
            'table' => 'suivi', 'colonnes' => ['Email', 'Statut'],
            'lignes' => [['c1@ex.fr', 'à relancer'], ['c2@ex.fr', 'à relancer'], ['inconnu@ex.fr', 'à relancer']],
        ]);

        $out = self::call($tools, WorkTableTools::PERM_WRITE, [
            'table' => 'clients_2026', 'depuis' => 'suivi', 'mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut'],
        ]);
        $this->assertSame('en_attente_approbation', $out['statut']);
        $this->assertSame(2, $out['compteurs']['touched']);
        $this->assertSame(1, $out['compteurs']['unmatched']);
        $this->assertStringContainsString('Rien n\'est écrit', $out['note']);

        $this->assertCount(1, $this->queued);
        [$tool, $args, $extra] = $this->queued[0];
        $this->assertSame(WorkTableTools::PERM_WRITE, $tool);
        $this->assertSame(self::KB, $args['kb_id']);
        $this->assertSame('suivi', $args['source']);
        $this->assertNotSame('', $args['source_stamp'], 'L’accord vérifie que la table de travail n’a pas changé.');
        $this->assertSame('mettre_a_jour', $args['spec']['mode']);
        $this->assertArrayNotHasKey('rows', $args, 'La carte ne porte pas les lignes : le calcul est rejoué à l’accord.');
        $card = $extra['preview'];
        $this->assertSame('Modifier la table « Clients 2026 »', $card['action']);
        $this->assertSame(['Client 1', 'Statut', 'vide', 'à relancer'], $card['grille']['lignes'][0]);
        $this->assertStringContainsString('Table partagée', implode(' ', $card['impacts']));
    }

    public function testUneEcritureSansEffetOuMalDesigneeNeDeposeAucuneCarte(): void
    {
        $tools = $this->tools([self::permanent(10)], self::rowsReader(self::clientRows(10)));
        self::call($tools, WorkTableTools::WRITE, ['table' => 'suivi', 'colonnes' => ['Email', 'Statut'], 'lignes' => [['absent@ex.fr', 'x']]]);

        // Aucune clé ne se retrouve.
        $none = self::call($tools, WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'depuis' => 'suivi', 'mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut']]);
        $this->assertSame('rien_a_ecrire', $none['statut']);

        // La cible doit être une table permanente, la source une table de la conversation.
        $wrongTarget = self::call($tools, WorkTableTools::PERM_WRITE, ['table' => 'suivi', 'depuis' => 'suivi', 'mode' => 'ajouter']);
        $this->assertStringContainsString('table de travail de la conversation', (string)$wrongTarget['error']);
        $wrongSource = self::call($tools, WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'depuis' => 'clients_2026', 'mode' => 'ajouter']);
        $this->assertStringContainsString('`depuis`', (string)$wrongSource['error']);

        // Table en lecture seule pour MIA : refus qui dit où la modifier.
        $locked = $this->tools([self::permanent(10, false)], self::rowsReader(self::clientRows(10)));
        self::call($locked, WorkTableTools::WRITE, ['table' => 'suivi2', 'colonnes' => ['Email'], 'lignes' => [['c1@ex.fr']]]);
        // Un appel forcé (outil non exposé) est refusé, et dit où la modifier.
        $refused = self::call($locked, WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'depuis' => 'suivi2', 'mode' => 'ajouter']);
        $this->assertStringContainsString('lecture seule', (string)$refused['error']);
        $this->assertStringContainsString('Bases de connaissances', (string)$refused['error']);

        $this->assertSame([], $this->queued);
    }

    public function testLaTraceNommeLaTableEtLeGeste(): void
    {
        $this->assertSame('table_perm_write', WorkTableTools::traceType(WorkTableTools::PERM_WRITE));
        $detail = WorkTableTools::traceDetail(WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'depuis' => 'suivi'], ['statut' => 'en_attente_approbation', 'table' => 'clients_2026']);
        $this->assertStringContainsString('clients_2026', $detail);
    }

    // ---------------------------------------------------------------------
    // 4. Compter sans créer de table, modifier sans table de travail
    // ---------------------------------------------------------------------

    public function testUnNombreOuUnTotalRevientSansCreerDeTable(): void
    {
        $tools = $this->tools([self::permanent(120)], self::rowsReader(self::clientRows(120)));

        // « Combien de lignes ? » : le total revient dans le résultat — même si le modèle a donné `vers`.
        $total = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'total', 'calculs' => [['operation' => 'compte']]]);
        $this->assertArrayNotHasKey('error', $total);
        $this->assertSame(120, $total['lignes_avant']);
        $this->assertSame([['nombre' => '120']], $total['resultat']);
        $this->assertStringContainsString('aucune table n\'a été créée', $total['note']);
        $this->assertSame([], $this->store->all(12), 'Un total n’est pas une liste : pas de table d’une ligne.');
        $this->assertSame(['p' . self::KB], $tools->touched(), 'Sous la réponse, « Voir » ouvre la table qui a été comptée.');

        // Sans `vers` : le nombre, et la liste entière si elle est courte.
        $paris = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'paris']]]);
        $this->assertSame(30, $paris['lignes_resultat']);
        $this->assertCount(30, $paris['resultat']);
        // Liste longue : des exemples, et la marche à suivre pour la garder.
        $lyon = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Lyon']]]);
        $this->assertSame(90, $lyon['lignes_resultat']);
        $this->assertArrayNotHasKey('resultat', $lyon);
        $this->assertCount(WorkTableOps::SAMPLE_ROWS, $lyon['exemples']);
        $this->assertStringContainsString('`vers`', $lyon['note']);
        // Un compte par catégorie, sans `vers` : les groupes reviennent, rien n'est gardé.
        $byCity = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'regrouper_par' => ['Ville']]);
        $this->assertSame(2, $byCity['lignes_resultat']);
        $this->assertSame([], $this->store->all(12));

        // La trace dit un calcul, pas une transformation : aucune table n'est née.
        $this->assertSame('table_compute', WorkTableTools::traceType(WorkTableTools::TRANSFORM, $total));
        $this->assertSame('table_transform', WorkTableTools::traceType(WorkTableTools::TRANSFORM, ['lignes' => 3]));
        $this->assertSame('« clients_2026 »', WorkTableTools::traceDetail(WorkTableTools::TRANSFORM, ['table' => 'clients_2026'], $total));
    }

    public function testUneListeGardeeEstUneVueQuiNeCopieRien(): void
    {
        $tools = $this->tools([self::permanent(120)], self::rowsReader(self::clientRows(120)));
        $out   = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'parisiens', 'titre' => 'Clients de Paris',
            'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']]]);
        $this->assertSame('parisiens', $out['table']);
        $this->assertSame('Clients de Paris', $out['titre']);
        $this->assertSame(30, $out['lignes']);
        $this->assertStringContainsString('est une VUE de « Clients 2026 » : rien n\'a été copié', $out['note']);
        $this->assertSame([(int)$this->store->find(12, 'parisiens')['id']], $tools->touched(), 'On vient voir la vue.');

        // La vue se lit, se re-filtre (une étape de plus) et s'exporte par sa recette.
        $seen = self::call($tools, WorkTableTools::VIEW, ['table' => 'parisiens', 'lignes' => 50]);
        $this->assertSame(30, (int)$seen['lignes']);
        $more = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'parisiens', 'max' => 5]);
        $this->assertSame(5, $more['lignes_resultat']);
        $this->assertMatchesRegularExpression('/^p' . self::KB . '\.5\.[A-Za-z0-9_-]+$/', (string)$tools->touched()[0], 'Une vue sans nom vit dans la référence de la réponse.');
        $link = self::call($tools, WorkTableTools::EXPORT, ['table' => 'parisiens']);
        $this->assertStringStartsWith('/chat/12/tables/p' . self::KB . '/clients-de-paris.xlsx?vue=', $link['lien']);

        // Lire la table permanente la nomme sous la réponse.
        self::call($tools, WorkTableTools::VIEW, ['table' => 'clients_2026', 'lignes' => 3]);
        $this->assertSame('p' . self::KB, $tools->touched()[0]);
    }

    public function testUneModificationDirecteNeCreeAucuneTableDeTravail(): void
    {
        $tools = $this->tools([self::permanent(10)], self::rowsReader(self::clientRows(10)));

        // « Passe les clients de Paris en statut à relancer ».
        $out = self::call($tools, WorkTableTools::PERM_WRITE, [
            'table' => 'clients_2026', 'mode' => 'definir', 'definir' => '{"Statut": "à relancer"}',
            'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']],
        ]);
        $this->assertSame('en_attente_approbation', $out['statut']);
        $this->assertSame(2, $out['compteurs']['touched'], 'Clients 4 et 8.');
        $this->assertStringContainsString('sans créer de table de travail', $out['note']);
        $this->assertSame([], $this->store->all(12), 'Aucune table de travail intermédiaire.');
        $this->assertSame(['p' . self::KB], $tools->touched(), '« Voir » ouvre la table qui va changer.');

        [$tool, $args, $extra] = $this->queued[0];
        $this->assertSame(WorkTableTools::PERM_WRITE, $tool);
        $this->assertSame(0, $args['source_id'], 'Aucune table de la conversation à relire à l’accord.');
        $this->assertSame('', $args['source_stamp']);
        $this->assertSame('definir', $args['spec']['mode']);
        $this->assertSame(['Client 4', 'Statut', 'vide', 'à relancer'], $extra['preview']['grille']['lignes'][0]);
        $this->assertStringContainsString('Lignes visées : Ville = « Paris » (2 lignes).', implode("\n", $extra['preview']['impacts']));

        // Ajouter des lignes données dans la demande ; en supprimer.
        $add = self::call($tools, WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'mode' => 'ajouter', 'colonnes' => ['Nom', 'Ville'], 'lignes' => [['Zed', 'Nice']]]);
        $this->assertSame('en_attente_approbation', $add['statut']);
        $this->assertSame([['Zed', 'Nice']], $this->queued[1][1]['spec']['inline']['rows'], 'Les lignes voyagent avec la carte : le calcul est rejoué à l’accord.');
        $this->assertSame('Ajouter des lignes à la table « Clients 2026 »', $this->queued[1][2]['preview']['action']);
        $del = self::call($tools, WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'mode' => 'supprimer_lignes', 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']]]);
        $this->assertSame(2, $del['compteurs']['deleted']);
        $this->assertSame('Supprimer des lignes de la table « Clients 2026 »', $this->queued[2][2]['preview']['action']);
        $this->assertCount(3, $this->queued);

        // Aucune ligne visée : pas de carte, et la raison.
        $none = self::call($tools, WorkTableTools::PERM_WRITE, [
            'table' => 'clients_2026', 'mode' => 'definir', 'definir' => ['Statut' => 'x'],
            'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Brest']],
        ]);
        $this->assertSame('rien_a_ecrire', $none['statut']);
        $this->assertStringContainsString('Aucune ligne ne passe ces filtres', $none['note']);

        // Un mode qui lit une table de travail la réclame — et renvoie au mode direct.
        $needs = self::call($tools, WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'mode' => 'mettre_a_jour', 'cle' => 'Email']);
        $this->assertStringContainsString('`depuis`', (string)$needs['error']);
        $this->assertStringContainsString('definir', (string)$needs['error']);
        $empty = self::call($tools, WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'mode' => 'ajouter']);
        $this->assertStringContainsString('`lignes`', (string)$empty['error']);

        $this->assertCount(3, $this->queued);
        $this->assertSame([], $this->store->all(12));
    }

    public function testLaConsigneDemandeLeCompteSansTableEtLaModificationDirecte(): void
    {
        $tools = $this->tools([self::permanent(10)], self::rowsReader(self::clientRows(10)));
        $defs  = array_column($tools->definitions(), null, 'name');
        $this->assertSame(['table'], $defs[WorkTableTools::TRANSFORM]['parameters']['required'], '`vers` ne se donne que pour garder une liste.');
        $this->assertSame(['table', 'mode'], $defs[WorkTableTools::PERM_WRITE]['parameters']['required'], '`depuis` n’est plus exigé.');
        $this->assertSame('string', $defs[WorkTableTools::PERM_WRITE]['parameters']['properties']['lignes']['items']['items']['type'], 'Liste de listes : un objet libre serait refusé par certains modèles.');
        $this->assertStringContainsString('sans table créée', $defs[WorkTableTools::TRANSFORM]['description']);
        $this->assertStringContainsString('DIRECTEMENT, sans table de travail', $defs[WorkTableTools::PERM_WRITE]['description']);

        $guidance = $tools->guidance();
        foreach ([
            'sans `vers`',
            'nul besoin d\'un outil pour les dire',
            'DIRECTEMENT sur elle',
            'n\'en présente jamais une comme « la table modifiée »',
        ] as $needle) {
            $this->assertStringContainsString($needle, $guidance, $needle);
        }
    }
}
