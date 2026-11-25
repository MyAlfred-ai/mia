<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Les deux outils du lot 2 des tables de travail — `enrichir_table` et
 * `agir_sur_table` — sur un magasin en mémoire, un faux modèle et une fausse
 * file d'approbation.
 *
 * Ce que ces tests protègent : peu de lignes sont enrichies pendant la
 * réponse, beaucoup deviennent une opération de fond PROPOSÉE (rien n'est
 * fait sans accord, le coût est annoncé) ; une action par ligne ne crée
 * rien pendant la réponse — chaque ligne est tenue en réserve, UNE carte les
 * présente toutes — et une préparation abandonnée ne laisse rien derrière elle.
 */
final class WorkTableMissionToolsTest extends TestCase
{
    private const CONV = 12;

    private MemoryWorkTableStore $store;
    /** @var list<array> évènements émis vers l'écran */
    private array $events = [];
    /** @var list<array{0: string, 1: array, 2: ?array}> cartes déposées */
    private array $cards = [];
    /** @var list<array{0: string, 1: array}> actions tenues en réserve */
    private array $held = [];
    /** @var list<int> actions écartées */
    private array $discarded = [];

    protected function setUp(): void
    {
        $this->store = new MemoryWorkTableStore();
        $this->events = $this->cards = $this->held = $this->discarded = [];
    }

    protected function tearDown(): void
    {
        WorkTableEnricher::overrideCompletion(null);
        LlmAdapterHelpers::setStopProbe(null);
    }

    private function turn(): AgentTurn
    {
        return new AgentTurn(
            7,
            self::CONV,
            ['id' => 7, 'role' => 'user'],
            4000,
            function (array $event): void {
                $this->events[] = $event;
            },
            function (string $tool, array $args, ?array $ref = null): array {
                $this->cards[] = [$tool, $args, $ref];
                return ['statut' => 'en_attente_approbation', 'message' => 'carte'];
            },
            'Europe/Paris'
        );
    }

    /** @param array<string, object> $services */
    private function tools(array $services = [], string $llm = 'openai::o3', ?\Closure $prepare = null, bool $withActions = true): WorkTableTools
    {
        $prepare ??= function (string $tool, array $args): array {
            if (!filter_var($args['destinataire'] ?? ($args['titre'] ?? 'x'), FILTER_VALIDATE_EMAIL) && isset($args['destinataire'])) {
                return ['error' => 'Adresse invalide : ' . $args['destinataire']];
            }
            $this->held[] = [$tool, $args];
            return ['id' => 500 + count($this->held), 'label' => ($args['objet'] ?? $args['titre'] ?? '') . ' → ' . ($args['destinataire'] ?? ''), 'example' => (string)($args['corps'] ?? ''), 'icon' => '/assets/img/gmail.svg'];
        };
        return new WorkTableTools(
            $this->turn(),
            $this->store,
            static fn(): array => $services,
            [],
            $llm,
            $withActions ? ['prepare' => $prepare, 'discard' => function (array $ids): void {
                $this->discarded = array_merge($this->discarded, $ids);
            }] : [],
            static fn(int $in, int $out): ?int => 3
        );
    }

    private static function call(WorkTableTools $tools, string $name, array $args): array
    {
        return ($tools->handlers()[$name])($args);
    }

    private function clients(int $n, string $name = 'clients'): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['prenom' => 'Client ' . $i, 'email' => $i % 10 === 0 ? '' : 'c' . $i . '@ex.fr', 'numero' => 'F-' . $i, 'note' => $i % 2 ? 'retard de paiement' : 'à jour'];
        }
        return $this->store->save(self::CONV, 7, $name, 'Clients à relancer', WorkTableOps::columns(['Prénom', 'Email', 'Numéro', 'Note']), $rows);
    }

    private static function judge(): \Closure
    {
        return static function (string $system, string $user, string $llm): string {
            preg_match('/<<<\n(.*)\n>>>/s', $user, $m);
            $out = [];
            foreach (explode("\n", (string)($m[1] ?? '')) as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $out[] = ['n' => $row['n'], 'a_relancer' => str_contains((string)($row['note'] ?? ''), 'retard') ? 'oui' : 'non'];
                }
            }
            return json_encode($out);
        };
    }

    private static function names(WorkTableTools $tools): array
    {
        return array_column($tools->definitions(), 'name');
    }

    public function testLesOutilsNeSontProposesQueLaOuIlsServent(): void
    {
        $mail = new class { public function canDraft(): bool { return true; } };
        $readOnlyMail = new class { public function canDraft(): bool { return false; } };
        $tasks = new class { public function canWrite(): bool { return true; } };

        // Sans modèle : pas d'enrichissement ; sans file d'actions (tour sans personne) : pas d'action par ligne.
        $this->assertNotContains(WorkTableTools::ENRICH, self::names($this->tools(['gmail' => $mail], '')));
        $this->assertNotContains(WorkTableTools::ACT, self::names($this->tools(['gmail' => $mail], 'openai::o3', null, false)));
        // Aucun connecteur qui sache écrire : pas d'action par ligne.
        $this->assertNotContains(WorkTableTools::ACT, self::names($this->tools(['gmail' => $readOnlyMail, 'drive' => new \stdClass()])));

        $tools = $this->tools(['gmail' => $mail, 'tasks' => $tasks, 'outlook' => $readOnlyMail]);
        $this->assertContains(WorkTableTools::ENRICH, self::names($tools));
        $defs = array_column($tools->definitions(), null, 'name');
        $this->assertSame(['creer_brouillon_gmail', 'creer_tache'], $defs[WorkTableTools::ACT]['parameters']['properties']['action']['enum'],
            'Seuls les outils des connecteurs engagés qui savent écrire.');
        $this->assertSame('string', $defs[WorkTableTools::ACT]['parameters']['properties']['arguments']['type'], 'Un objet libre serait refusé par certains fournisseurs.');
        $this->assertStringContainsString(WorkTableTools::ENRICH, $tools->guidance());
        $this->assertStringContainsString(WorkTableTools::ACT, $tools->guidance());
        $this->assertStringNotContainsString(WorkTableTools::ACT, $this->tools([], 'openai::o3')->guidance());

        // Les huit outils restent légers : envoyés à chaque message où ils servent.
        $size = strlen((string)json_encode($tools->definitions(), JSON_UNESCAPED_UNICODE)) + strlen($tools->guidance());
        $this->assertLessThan(11500, $size, 'Définitions et consigne : ' . $size . ' caractères.');
    }

    public function testPeuDeLignesSontEnrichiesPendantLaReponse(): void
    {
        $table = $this->clients(30);
        WorkTableEnricher::overrideCompletion(self::judge());
        $res = self::call($this->tools(), WorkTableTools::ENRICH, [
            'table' => 'clients', 'consigne' => 'Faut-il relancer ce client ?',
            'colonnes' => [['nom' => 'À relancer', 'type' => 'oui_non']], 'lire' => ['Note'],
        ]);
        $this->assertSame(30, $res['lignes_enrichies']);
        $this->assertSame(['a_relancer'], $res['colonnes_remplies']);
        $this->assertContains('a_relancer', $res['colonnes']);
        $this->assertCount(WorkTableOps::SAMPLE_ROWS, $res['exemples'], 'Quelques lignes pour vérifier la consigne, jamais la table.');
        $this->assertSame('oui', $res['exemples'][0]['a_relancer']);
        $this->assertArrayNotHasKey('incomplet', $res);
        $this->assertSame([], $this->cards, 'Aucune carte : c’est fait.');
        $this->assertContains(['phase' => 'table_enrich'], $this->events);

        $rows = $this->store->rows((int)$table['id']);
        $this->assertSame(15, count(array_filter($rows, static fn(array $r): bool => $r['a_relancer'] === 'oui')));

        // Déjà remplies : rien à refaire, sans appel.
        WorkTableEnricher::overrideCompletion(static fn(): string => throw new \LogicException('aucun appel attendu'));
        $again = self::call($this->tools(), WorkTableTools::ENRICH, ['table' => 'clients', 'consigne' => 'x', 'colonnes' => ['À relancer']]);
        $this->assertStringContainsString('rien à faire', $again['note']);

        // Erreurs rendues au modèle.
        $this->assertStringContainsString('consigne', self::call($this->tools(), WorkTableTools::ENRICH, ['table' => 'clients', 'colonnes' => ['X']])['error']);
        $this->assertStringContainsString('inconnue', self::call($this->tools(), WorkTableTools::ENRICH, ['table' => 'absente', 'consigne' => 'x', 'colonnes' => ['X']])['error']);
    }

    public function testBeaucoupDeLignesDeviennentUneOperationDeFondProposee(): void
    {
        $table = $this->clients(400);
        WorkTableEnricher::overrideCompletion(static fn(): string => throw new \LogicException('rien ne part sans accord'));
        $res = self::call($this->tools(), WorkTableTools::ENRICH, [
            'table' => 'clients', 'consigne' => 'Faut-il relancer ce client ?', 'colonnes' => [['nom' => 'À relancer', 'type' => 'oui_non']],
        ]);
        $this->assertSame('en_attente_approbation', $res['statut']);
        $this->assertSame(400, $res['lignes_a_enrichir']);
        $calls = (int)ceil(400 / WorkTableEnricher::BATCH_ROWS);
        $this->assertSame(['credits' => CreditEstimate::round(3 * $calls), 'duree' => WorkTableEnricher::durationLabel($calls * WorkTableEnricher::SECONDS_PER_CALL)], $res['estimation']);
        $this->assertStringContainsString('RIEN n\'est fait', $res['message']);

        $this->assertCount(1, $this->cards);
        [$tool, $args, $ref] = $this->cards[0];
        $this->assertSame(WorkTableTools::ENRICH, $tool);
        $this->assertSame((int)$table['id'], $args['table_id']);
        $this->assertSame('openai::o3', $args['llm']);
        $this->assertSame(400, $args['rows']);
        $this->assertSame(WorkTableEnricher::cap(3 * $calls), $args['cap']);
        $this->assertSame(['instruction', 'new', 'read', 'redo'], array_keys($args['spec']), 'La demande voyage sans les colonnes de la table : relues au moment de l’exécution.');
        $this->assertSame('Enrichir 400 lignes', $ref['preview']['action']);
        // La table n'a pas bougé.
        $this->assertNotContains('a_relancer', WorkTableOps::keys((array)$this->store->findById(self::CONV, (int)$table['id'])['columns']));

        $this->assertSame('« clients » (400 lignes, à approuver)', WorkTableTools::traceDetail(WorkTableTools::ENRICH, ['table' => 'clients'], $res));
        $this->assertSame('« clients » (30 lignes enrichies)', WorkTableTools::traceDetail(WorkTableTools::ENRICH, ['table' => 'clients'], ['lignes_enrichies' => 30]));
        $this->assertSame('table_enrich', WorkTableTools::traceType(WorkTableTools::ENRICH));
    }

    public function testUneActionParLigneNeCreeRienEtTientDansUneCarte(): void
    {
        $table = $this->clients(25);
        $mail  = new class { public function canDraft(): bool { return true; } };
        $res = self::call($this->tools(['gmail' => $mail]), WorkTableTools::ACT, [
            'table' => 'clients', 'action' => 'creer_brouillon_gmail',
            'arguments' => '{"destinataire": "{email}", "objet": "Relance {numero}", "corps": "Bonjour {prenom}, votre facture {numero} est en retard."}',
        ]);
        $this->assertSame('en_attente_approbation', $res['statut']);
        $this->assertSame(23, $res['actions_preparees']);
        $this->assertSame(2, $res['lignes_ecartees'], 'Les lignes 10 et 20 n’ont pas d’adresse.');
        $this->assertSame(['colonne « email » vide'], $res['motifs_d_ecart']);
        $this->assertStringContainsString('RIEN n\'est créé', $res['message']);

        // Chaque ligne a été déclinée par l'outil visé, avec SES valeurs.
        $this->assertCount(23, $this->held);
        $this->assertSame(['creer_brouillon_gmail', ['destinataire' => 'c1@ex.fr', 'objet' => 'Relance F-1', 'corps' => 'Bonjour Client 1, votre facture F-1 est en retard.']], $this->held[0]);

        // UNE carte, qui porte le rang et l'action tenue en réserve de chaque ligne.
        $this->assertCount(1, $this->cards);
        [$tool, $args, $ref] = $this->cards[0];
        $this->assertSame(WorkTableTools::ACT, $tool);
        $this->assertSame('creer_brouillon_gmail', $args['tool']);
        $this->assertSame((int)$table['id'], $args['table_id']);
        $this->assertCount(23, $args['items']);
        $this->assertSame([0, 501], $args['items'][0]);
        $this->assertSame([10, 510], $args['items'][9], 'Le rang 9 (ligne 10) est écarté : le dixième élément est le rang 10.');
        $this->assertSame('Créer 23 brouillons Gmail', $ref['preview']['action']);
        $this->assertStringContainsString('Bonjour Client 1', $ref['preview']['apercu']);
        $this->assertSame([], $this->discarded);
        $this->assertContains(['phase' => 'table_act'], $this->events);
        $this->assertSame('« clients » (23 actions à approuver)', WorkTableTools::traceDetail(WorkTableTools::ACT, ['table' => 'clients'], $res));

        // Une ligne déjà faite n'est pas refaite.
        $rows = $this->store->rows((int)$table['id']);
        $rows[0]['brouillon_statut'] = WorkTableActions::STATUS_DONE;
        $this->store->patch(self::CONV, (int)$table['id'], WorkTableActions::withResultColumns((array)$table['columns'], 'creer_brouillon_gmail'), [0 => $rows[0]]);
        $this->held = $this->cards = [];
        $res = self::call($this->tools(['gmail' => $mail]), WorkTableTools::ACT, [
            'table' => 'clients', 'action' => 'creer_brouillon_gmail', 'arguments' => ['destinataire' => '{email}', 'objet' => 'x', 'corps' => 'y'],
        ]);
        $this->assertSame(22, $res['actions_preparees']);
        $this->assertSame('c2@ex.fr', $this->held[0][1]['destinataire']);
    }

    public function testUnGabaritFauxOuUneActionImpossibleSontRendusAuModele(): void
    {
        $this->clients(250, 'trop');
        $this->clients(12);
        $mail = new class { public function canDraft(): bool { return true; } };
        $tools = $this->tools(['gmail' => $mail]);
        $ok = ['destinataire' => '{email}', 'objet' => 'x', 'corps' => 'y'];

        $this->assertStringContainsString('« nom » inconnue', self::call($tools, WorkTableTools::ACT, ['table' => 'clients', 'action' => 'creer_brouillon_gmail', 'arguments' => ['corps' => '{nom}']])['error']);
        $this->assertStringContainsString('{colonne}', self::call($tools, WorkTableTools::ACT, ['table' => 'clients', 'action' => 'creer_brouillon_gmail', 'arguments' => 'pas du json'])['error']);
        $this->assertStringContainsString(WorkTableActions::ROWS_MAX . ' au plus', self::call($tools, WorkTableTools::ACT, ['table' => 'trop', 'action' => 'creer_brouillon_gmail', 'arguments' => $ok])['error']);
        // Outil permis mais connecteur éteint ; outil jamais permis.
        $this->assertStringContainsString('n\'est pas allumé', self::call($tools, WorkTableTools::ACT, ['table' => 'clients', 'action' => 'creer_tache', 'arguments' => $ok])['error']);
        $refused = self::call($tools, WorkTableTools::ACT, ['table' => 'clients', 'action' => 'envoyer_message_slack', 'arguments' => $ok])['error'];
        $this->assertStringContainsString('impossible sur une table', $refused);
        $this->assertStringContainsString('creer_brouillon_gmail', $refused);
        $this->assertSame([], $this->cards);
        $this->assertSame([], $this->held);

        // Gabarit faux : chaque ligne échoue — arrêt au bout de cinq, erreur rendue, aucune carte.
        $tried = 0;
        $failing = $this->tools(['gmail' => $mail], 'openai::o3', static function () use (&$tried): array {
            $tried++;
            return ['error' => 'Objet obligatoire.'];
        });
        $res = self::call($failing, WorkTableTools::ACT, ['table' => 'clients', 'action' => 'creer_brouillon_gmail', 'arguments' => $ok]);
        $this->assertStringContainsString('Aucune ligne ne donne d\'action : Objet obligatoire.', $res['error']);
        $this->assertSame(5, $tried);
        $this->assertSame([], $this->cards);
    }

    public function testUnePreparationAbandonneeNeLaisseRienEnReserve(): void
    {
        $this->clients(8);
        $mail = new class { public function canDraft(): bool { return true; } };
        $ok   = ['destinataire' => '{email}', 'objet' => 'x', 'corps' => 'y'];

        // Arrêt demandé après la troisième ligne : les actions déjà tenues en réserve sont écartées.
        $n = 0;
        LlmAdapterHelpers::setStopProbe(static function () use (&$n): bool {
            return ++$n > 3;
        });
        $res = self::call($this->tools(['gmail' => $mail]), WorkTableTools::ACT, ['table' => 'clients', 'action' => 'creer_brouillon_gmail', 'arguments' => $ok]);
        LlmAdapterHelpers::setStopProbe(null);
        $this->assertStringContainsString('interrompue', $res['error']);
        $this->assertSame([501, 502, 503], $this->discarded);
        $this->assertSame([], $this->cards);

        // La carte du groupe est refusée par la file (tâche en lecture seule…) : idem.
        $this->held = $this->discarded = [];
        $refusing = new WorkTableTools(
            new AgentTurn(7, self::CONV, ['id' => 7], 4000, static function (array $e): void {
            }, static fn(string $t, array $a, ?array $r = null): array => ['error' => 'Écriture refusée.']),
            $this->store,
            static fn(): array => ['gmail' => $mail],
            [],
            'openai::o3',
            ['prepare' => fn(string $tool, array $args): array => ['id' => 700 + count($this->held = array_merge($this->held, [[$tool, $args]])), 'label' => 'x'],
             'discard' => function (array $ids): void {
                 $this->discarded = $ids;
             }]
        );
        $res = self::call($refusing, WorkTableTools::ACT, ['table' => 'clients', 'action' => 'creer_brouillon_gmail', 'arguments' => $ok]);
        $this->assertSame('Écriture refusée.', $res['error']);
        $this->assertCount(8, $this->discarded);
    }
}
