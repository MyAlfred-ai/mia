<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tables plus claires dans une conversation (2026-11-20) — le banc réel avait
 * montré MIA créer des COPIES de la table cochée pour trier, enrichir ou
 * exporter une sélection, nommées comme elle, ouvertes à sa place, périmées
 * dès qu'elle changeait.
 *
 *   A. trier, filtrer, réduire une table permanente donne une VUE (recette,
 *      rien de copié, toujours à jour) ;
 *   B. enrichir et agir s'appliquent directement à une table permanente
 *      (brouillon interne invisible, valeurs proposées dans une carte) ;
 *   C. le volet sépare « Vos tables » et « Résultats de MIA » ;
 *   D. un résultat tiré d'une table qui a changé est dit PÉRIMÉ ;
 *   E. « Garder » un tel résultat dit qu'une nouvelle table naît, et propose
 *      de compléter la table d'origine à la place.
 */
final class WorkTableClarityTest extends TestCase
{
    private const KB   = 990002;
    private const CONV = 12;

    private MemoryWorkTableStore $store;
    /** @var list<array{0:string,1:array,2:?array}> */
    private array $queued = [];
    private array $held = [];

    protected function setUp(): void
    {
        $this->store  = new MemoryWorkTableStore();
        $this->queued = [];
        $this->held   = [];
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        WorkTableEnricher::overrideCompletion(null);
        I18n::use('fr');
    }

    private function turn(): AgentTurn
    {
        return new AgentTurn(7, self::CONV, ['id' => 7, 'role' => 'creator'], 4000, static function (array $e): void {
        }, function (string $tool, array $args, ?array $preview = null): array {
            $this->queued[] = [$tool, $args, $preview];
            return ['statut' => 'en_attente_approbation'];
        }, 'Europe/Paris');
    }

    private static function rows(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['nom' => 'Client ' . $i, 'email' => 'c' . $i . '@ex.fr', 'ville' => $i % 4 === 0 ? 'Paris' : 'Lyon',
                'ca' => (string)(1000 * $i), 'note' => $i % 2 ? 'retard de paiement' : 'à jour'];
        }
        return $out;
    }

    private static function permanent(int $rows, bool $writable = true, int $version = 1): array
    {
        return [
            'id' => self::KB, 'kb_id' => self::KB, 'name' => 'clients_2026', 'title' => 'Clients 2026',
            'columns' => WorkTableOps::typed(WorkTableOps::columns(['Nom', 'Email', 'Ville', 'CA', 'Note']), self::rows($rows)),
            'rows' => $rows, 'origin' => [], 'version' => $version, 'updated_at' => '2026-11-20 10:00:00',
            'mia_write' => $writable, 'writable' => $writable, 'shared' => 0, 'permanent' => true,
        ];
    }

    private static function reader(array $rows): WorkTableRows
    {
        return new ArrayTableRows($rows);
    }

    private function tools(array $perm, string $llm = 'openai::o3', bool $actions = false): WorkTableTools
    {
        $prepare = function (string $tool, array $args): array {
            $this->held[] = $args;
            return ['id' => 700 + count($this->held), 'label' => (string)($args['titre'] ?? ''), 'example' => '', 'icon' => ''];
        };
        $services = $actions ? ['tasks' => new class { public function canWrite(): bool { return true; } }] : [];
        return new WorkTableTools($this->turn(), $this->store, static fn(): array => $services, [], $llm,
            $actions ? ['prepare' => $prepare, 'discard' => static function (): void {
            }] : [],
            static fn(int $in, int $out): ?int => 2, [$perm], self::reader(self::rows((int)$perm['rows'])));
    }

    private static function call(WorkTableTools $tools, string $name, array $args): array
    {
        return ($tools->handlers()[$name])($args);
    }

    /** Le « modèle » qui classe : un retard de paiement = « À relancer ». */
    private static function judge(): \Closure
    {
        return static function (string $system, string $user): string {
            preg_match('/<<<\n(.*)\n>>>/s', $user, $m);
            $out = [];
            foreach (explode("\n", (string)($m[1] ?? '')) as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $out[] = ['n' => $row['n'], 'secteur' => str_contains((string)($row['note'] ?? ''), 'retard') ? 'Risque' : 'Sain'];
                }
            }
            return json_encode($out);
        };
    }

    // ------------------------------------------------------------------
    // La recette d'une vue
    // ------------------------------------------------------------------

    public function testUneRecetteGardeLaPositionDOrigineDeChaqueLigne(): void
    {
        $cols  = WorkTableOps::columns(['Nom', 'Ville', 'CA']);
        $rows  = [['nom' => 'A', 'ville' => 'Lyon', 'ca' => '5'], ['nom' => 'B', 'ville' => 'Paris', 'ca' => '9'], ['nom' => 'C', 'ville' => 'Lyon', 'ca' => '7']];
        $steps = [WorkTableRecipe::step([['col' => 'ville', 'test' => 'egal', 'value' => 'Lyon']], false, [], [['col' => 'ca', 'desc' => true]], ['nom'], 0)];
        $res   = WorkTableRecipe::apply($cols, $rows, $steps);
        $this->assertSame(['nom'], WorkTableOps::keys($res['columns']));
        $this->assertSame([['nom' => 'C', WorkTableRecipe::LINE_KEY => '3'], ['nom' => 'A', WorkTableRecipe::LINE_KEY => '1']], $res['rows']);
        $this->assertSame([['nom' => 'C'], ['nom' => 'A']], WorkTableRecipe::strip($res['rows']));
        $this->assertSame(['Ville = Lyon', 'triée par CA, décroissant', '1 colonne'], WorkTableRecipe::describe($cols, $steps));
        $this->assertSame('Clients — Ville = Lyon', WorkTableRecipe::title('Clients', $cols, $steps));

        // Aller-retour par une adresse, revérifié contre les colonnes.
        $this->assertSame($steps, WorkTableRecipe::normalize($cols, WorkTableRecipe::decode(WorkTableRecipe::encode($steps))));
        $this->assertNull(WorkTableRecipe::decode('pas une recette!'));
        try {
            WorkTableRecipe::normalize(WorkTableOps::columns(['Nom']), $steps);
            $this->fail('Une colonne disparue rend la vue inapplicable.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('« ville » n’existe plus', $e->getMessage());
        }
        $this->assertTrue(WorkTableRecipe::isIdentity(WorkTableRecipe::step([], false, [], [], [], 0)));
    }

    public function testUnReportParPositionCompleteLesLignesDesigneesSansCleADeviner(): void
    {
        $target = WorkTableOps::columns(['Nom', 'Ville']);
        $rows   = [['nom' => 'A', 'ville' => 'Lyon'], ['nom' => 'A', 'ville' => 'Paris'], ['nom' => 'B', 'ville' => 'Lyon']];
        $source = array_merge(WorkTableOps::columns(['Nom', 'Secteur']), [['key' => WorkTableRecipe::LINE_KEY, 'label' => 'Ligne', 'type' => 'number', 'tech' => true]]);
        $spec   = KbTableWrite::lineUpdateSpec(['secteur']);
        $plan   = KbTableWrite::plan($target, $rows, $source, [
            ['nom' => 'A', 'secteur' => 'Bois', WorkTableRecipe::LINE_KEY => '2'],
            ['nom' => 'B', 'secteur' => 'Fer', WorkTableRecipe::LINE_KEY => '3'],
        ], $spec);
        $this->assertTrue(KbTableWrite::byLine($spec));
        $this->assertSame(['', 'Bois', 'Fer'], array_column($plan['rows'], 'secteur'), 'Le doublon de nom « A » ne gêne pas : la position désigne la ligne.');
        $this->assertSame(['Secteur'], $plan['stats']['columns_added']);
        $this->assertSame(2, $plan['stats']['touched']);
        $this->assertFalse(KbTableWrite::byLine(['mode' => 'mettre_a_jour', 'by_line' => false]));
    }

    // ------------------------------------------------------------------
    // A. Des vues, plus des copies
    // ------------------------------------------------------------------

    public function testTrierOuFiltrerUneTablePermanenteNeCreeAucuneTable(): void
    {
        $tools = $this->tools(self::permanent(40));
        $sorted = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'trier_par' => [['colonne' => 'CA', 'sens' => 'decroissant']]]);
        $this->assertSame(40, $sorted['lignes_resultat']);
        $this->assertStringContainsString('« Voir » : « Clients 2026 » filtrée', $sorted['note']);
        $this->assertSame([], $this->store->all(self::CONV), 'Rien n’est copié.');
        $ref = (string)$tools->touched()[0];
        [$kb, $n, $steps] = WorkTableView::anonRef($ref);
        $this->assertSame([self::KB, 40], [$kb, $n]);
        $this->assertTrue($steps[0]['s'][0]['desc']);

        // Une demande qui ne change rien (aucun filtre) montre simplement la table.
        $plain = $this->tools(self::permanent(10));
        self::call($plain, WorkTableTools::TRANSFORM, ['table' => 'clients_2026']);
        $this->assertSame(['p' . self::KB], $plain->touched());
    }

    public function testUnePermanenteEtUneVueSePresententDansLeVoletEtSousLaReponse(): void
    {
        $perm  = self::permanent(40);
        $tools = $this->tools($perm);
        self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'parisiens', 'titre' => 'Clients de Paris',
            'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']]]);
        $view = $this->store->find(self::CONV, 'parisiens');

        // Sous la réponse : la vraie table d'abord.
        $line = WorkTableView::line(WorkTableView::forMessages([['id' => 1, 'role' => 'assistant', 'agent_json' => json_encode(['tables' => $tools->touched()])]], $this->store->all(self::CONV), [$perm])[1]);
        $this->assertSame('Clients 2026', $line['items'][0]['parent']);
        $this->assertSame('Clients de Paris', $line['items'][0]['label']);
        $this->assertSame('10 lignes · vue, toujours à jour', $line['items'][0]['meta']);

        // Le volet : la table, sa vue rangée dessous ; la vue ouverte porte son bandeau.
        $panel = WorkTableView::panel($this->store, self::CONV, (int)$view['id'], '', 1, false, true, [$perm], self::reader(self::rows(40)));
        $this->assertSame(['p' . self::KB, (int)$view['id']], array_column($panel['mine'], 'id'));
        $this->assertSame([false, true], array_column($panel['mine'], 'nested'));
        $this->assertSame([], $panel['results']);
        $sel = $panel['selected'];
        $this->assertSame('view', $sel['kind']);
        $this->assertSame(10, $sel['page']['total']);
        $this->assertSame(['Ville = Paris'], $sel['view']['what']);
        $this->assertStringContainsString('?vue=', $sel['xlsx']);
        $this->assertTrue($sel['own'], 'Une vue nommée se renomme et se supprime d’ici.');
        $html = WorkTableView::panelHtml($panel);
        foreach (['Filtrée par MIA', 'Rien n’est copié : la vue suit la table.', 'Tout afficher', 'data-table-select="p' . self::KB . '"', 'Supprimer la vue « Clients de Paris »'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        // Une vue sans nom s'ouvre filtrée, sur la table.
        $anon  = self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'max' => 3]);
        $this->assertSame(3, $anon['lignes_resultat']);
        $open  = WorkTableView::panel($this->store, self::CONV, $tools->touched()[0], '', 1, false, true, [$perm], self::reader(self::rows(40)));
        $this->assertSame(3, $open['selected']['page']['total']);
        $this->assertFalse($open['selected']['own']);
        $this->assertTrue($open['mine'][0]['selected'], 'La table dont la vue est ouverte s’allume dans la liste.');
    }

    public function testSousLaReponseUneVueDitSonCompteDuMomentPasCeluiDeSaCreation(): void
    {
        // Constaté au banc réel : la vue « triée par CA » disait « 40 lignes · vue, toujours à jour »
        // après la suppression de dix lignes de sa table.
        $perm  = self::permanent(40);
        $tools = $this->tools($perm);
        self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'parisiens', 'titre' => 'Clients de Paris',
            'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']]]);
        self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'max' => 30]);
        $message = [['id' => 1, 'role' => 'assistant', 'agent_json' => json_encode(['tables' => $tools->touched()])]];

        // La table n'a plus que ses 20 premières lignes : 5 Parisiens, et une vue « 30 premières » de 20 lignes.
        $now   = self::permanent(20);
        $found = WorkTableView::forMessages($message, $this->store->all(self::CONV), [$now], self::reader(self::rows(20)))[1];
        $metas = array_column(WorkTableView::line($found)['items'], 'meta');
        $this->assertCount(1, $metas, 'Une seule table nommée sous la réponse.');
        $byRef = [];
        foreach ($found as $t) {
            $byRef[WorkTableView::isView($t) ? 'vue' : 'anon'] = WorkTableView::line([$t])['items'][0]['meta'];
        }
        $this->assertSame('5 lignes · vue, toujours à jour', $byRef['vue']);
        $this->assertSame('20 lignes sur 20 · filtrée', $byRef['anon']);

        // Sans les lignes de la table : le compte noté à la création (rien n'est lu).
        $old = WorkTableView::forMessages($message, $this->store->all(self::CONV), [$now])[1];
        foreach ($old as $t) {
            if (WorkTableView::isView($t)) {
                $this->assertSame('10 lignes · vue, toujours à jour', WorkTableView::line([$t])['items'][0]['meta']);
            }
        }

        // Une colonne filtrée a disparu de la table : la vue le dit, sans compte.
        $broken = $now;
        $broken['columns'] = array_values(array_filter((array)$now['columns'], static fn(array $c): bool => $c['key'] !== 'ville'));
        $found  = WorkTableView::forMessages($message, $this->store->all(self::CONV), [$broken], self::reader(self::rows(20)))[1];
        foreach ($found as $t) {
            if (WorkTableView::isView($t)) {
                $this->assertSame('vue qui ne s’applique plus', WorkTableView::line([$t])['items'][0]['meta']);
            }
        }
    }

    // ------------------------------------------------------------------
    // B. Enrichir et agir sans copie
    // ------------------------------------------------------------------

    public function testEnrichirUneTablePermanenteProposeUneCarteSansCopieVisible(): void
    {
        WorkTableEnricher::overrideCompletion(self::judge());
        $perm  = self::permanent(20);
        $tools = $this->tools($perm);
        $out = self::call($tools, WorkTableTools::ENRICH, [
            'table' => 'Clients 2026', 'consigne' => 'Classe le client', 'colonnes' => [['nom' => 'Secteur', 'valeurs' => ['Risque', 'Sain']]],
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(20, $out['lignes_enrichies']);
        $this->assertSame('en_attente_approbation', $out['statut']);
        $this->assertStringContainsString('une carte propose de les écrire dans « Clients 2026 »', $out['note']);
        $this->assertSame([], $this->store->all(self::CONV), 'Le brouillon interne n’est ni listé ni compté.');
        $this->assertSame(['p' . self::KB], $tools->touched(), '« Voir » ouvre la table de la personne.');

        [$tool, $args, $extra] = $this->queued[0];
        $this->assertSame(WorkTableTools::PERM_WRITE, $tool);
        $this->assertTrue(KbTableWrite::byLine($args['spec']));
        $this->assertSame(KbTableStore::stamp($perm), $args['target_stamp']);
        $draft = $this->store->findById(self::CONV, (int)$args['source_id']);
        $this->assertTrue(WorkTableOps::isHidden((string)$draft['name']));
        $this->assertSame('p' . self::KB, WorkTableView::displayRef($draft));
        $this->assertStringContainsString('Colonne créée : « Secteur ».', implode(' ', $extra['preview']['impacts']));

        // Le même calcul, rejoué sur la table, complète exactement les lignes.
        $plan = KbTableWrite::plan((array)$perm['columns'], self::rows(20), (array)$draft['columns'], $this->store->rows((int)$draft['id']), $args['spec']);
        $this->assertSame('Risque', $plan['rows'][0]['secteur']);
        $this->assertSame('Sain', $plan['rows'][1]['secteur']);
    }

    public function testEnrichirUneVueNeTouchePasLesAutresLignes(): void
    {
        WorkTableEnricher::overrideCompletion(self::judge());
        $perm  = self::permanent(20);
        $tools = $this->tools($perm);
        self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'paris', 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Paris']]]);
        $out = self::call($tools, WorkTableTools::ENRICH, ['table' => 'paris', 'consigne' => 'Classe', 'colonnes' => ['Secteur']]);
        $this->assertSame(5, $out['lignes_enrichies']);
        $args = $this->queued[0][1];
        $draft = $this->store->findById(self::CONV, (int)$args['source_id']);
        $plan = KbTableWrite::plan((array)$perm['columns'], self::rows(20), (array)$draft['columns'], $this->store->rows((int)$draft['id']), $args['spec']);
        $filled = array_keys(array_filter(array_column($plan['rows'], 'secteur'), static fn(string $v): bool => $v !== ''));
        $this->assertSame([3, 7, 11, 15, 19], $filled, 'Seules les lignes de Paris (4, 8, 12, 16, 20).');
        $this->assertSame([(int)$this->store->find(self::CONV, 'paris')['id']], $tools->touched());
    }

    public function testUneTableEnLectureSeuleEstEnrichieDansUnResultatDitCommeTel(): void
    {
        WorkTableEnricher::overrideCompletion(self::judge());
        $tools = $this->tools(self::permanent(6, false));
        $out = self::call($tools, WorkTableTools::ENRICH, ['table' => 'clients_2026', 'consigne' => 'Classe', 'colonnes' => ['Secteur']]);
        $this->assertSame(6, $out['lignes_enrichies']);
        $this->assertStringContainsString('est en lecture seule pour toi', $out['note']);
        $this->assertSame([], $this->queued, 'Rien n’est proposé pour une table que MIA ne peut pas modifier.');
        $copy = $this->store->find(self::CONV, 'clients_2026_enrichie');
        $this->assertSame('Clients 2026 — enrichie', $copy['title']);
        $this->assertSame('Clients 2026', WorkTableView::basesOf($copy)[0]['titre']);
    }

    public function testUnGrosEnrichissementPartEnFondAvecSaCarteDeReport(): void
    {
        WorkTableEnricher::overrideCompletion(static fn(): string => throw new \LogicException('rien ne part sans accord'));
        $tools = $this->tools(self::permanent(120));
        $out = self::call($tools, WorkTableTools::ENRICH, ['table' => 'clients_2026', 'consigne' => 'Classe', 'colonnes' => ['Secteur']]);
        $this->assertSame('en_attente_approbation', $out['statut']);
        $this->assertSame('clients_2026', $out['table']);
        [$tool, $args, $extra] = $this->queued[0];
        $this->assertSame(WorkTableTools::ENRICH, $tool);
        $this->assertSame(self::KB, $args['report']['kb_id']);
        $this->assertSame('p' . self::KB, $args['report']['ref']);
        $this->assertTrue(WorkTableOps::isHidden((string)$this->store->findById(self::CONV, (int)$args['table_id'])['name']));
        $this->assertStringContainsString('une carte proposera d’écrire ces valeurs dans « Clients 2026 »', implode(' ', $extra['preview']['impacts']));
    }

    public function testAgirSurUneTablePermanenteNeCopieRienDeVisible(): void
    {
        $tools = $this->tools(self::permanent(8), 'openai::o3', true);
        $out = self::call($tools, WorkTableTools::ACT, ['table' => 'Clients 2026', 'action' => 'creer_tache', 'arguments' => '{"titre": "Relancer {nom}"}']);
        $this->assertSame('en_attente_approbation', $out['statut']);
        $this->assertSame(8, $out['actions_preparees']);
        $this->assertSame('Relancer Client 1', $this->held[0]['titre']);
        $this->assertSame([], $this->store->all(self::CONV));
        [, $args] = $this->queued[0];
        $this->assertSame('Clients 2026', $args['table']);
        $this->assertSame(['p' . self::KB], $tools->touched());

        // Rejouée, l'action garde le même brouillon (les lignes déjà faites ne seraient pas refaites).
        $again = $this->tools(self::permanent(8), 'openai::o3', true);
        self::call($again, WorkTableTools::ACT, ['table' => 'clients_2026', 'action' => 'creer_tache', 'arguments' => '{"titre": "Relancer {nom}"}']);
        $this->assertSame($args['table_id'], $this->queued[1][1]['table_id']);
    }

    public function testUneVueNeSeReportePasDansSaPropreTable(): void
    {
        $tools = $this->tools(self::permanent(10));
        self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'lyon', 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Lyon']]]);
        $out = self::call($tools, WorkTableTools::PERM_WRITE, ['table' => 'clients_2026', 'mode' => 'mettre_a_jour', 'depuis' => 'lyon', 'cle' => 'Email']);
        $this->assertStringContainsString('ses lignes SONT celles de la table', $out['error']);
    }

    // ------------------------------------------------------------------
    // C, D, E. Le volet, le périmé, « Garder »
    // ------------------------------------------------------------------

    public function testUnResultatTireDUneTableQuiAChangeEstDitPerime(): void
    {
        $perm  = self::permanent(12);
        $tools = $this->tools($perm);
        self::call($tools, WorkTableTools::TRANSFORM, ['table' => 'clients_2026', 'vers' => 'par_ville', 'titre' => 'Clients par ville', 'regrouper_par' => ['Ville']]);
        $result = $this->store->find(self::CONV, 'par_ville');
        $this->assertSame('', WorkTableView::staleBase($result, [self::KB => $perm]));
        $changed = self::permanent(12, true, 2);
        $this->assertSame('Clients 2026', WorkTableView::staleBase($result, [self::KB => $changed]));
        $this->assertSame('', WorkTableView::staleBase($result, []), 'Table plus jointe : on ne sait pas.');

        $later = new WorkTableTools($this->turn(), $this->store, static fn(): array => [], [], '', [], null, [$changed], self::reader(self::rows(12)));
        $this->assertStringContainsString('Résultats PÉRIMÉS', $later->guidance());
        $this->assertStringContainsString('`par_ville` (de « Clients 2026 »)', $later->guidance());

        $panel = WorkTableView::panel($this->store, self::CONV, (int)$result['id'], '', 1, false, true, [$changed], self::reader(self::rows(12)));
        $this->assertSame('Clients 2026', $panel['results'][0]['stale']);
        $this->assertTrue($panel['results_open']);
        $html = WorkTableView::panelHtml($panel);
        $this->assertStringContainsString('Périmé : « Clients 2026 » a changé depuis que MIA a établi ce résultat.', $html);
        $this->assertStringContainsString('Tirée de « Clients 2026 » : la garder crée une NOUVELLE table permanente', $html);
    }

    public function testGarderUnResultatProposeDeCompleterSaTable(): void
    {
        $perm = self::permanent(6);
        $this->store->save(self::CONV, 7, 'secteurs', 'Clients avec secteur', WorkTableOps::columns(['Nom', 'Email', 'Secteur']),
            [['nom' => 'Client 1', 'email' => 'c1@ex.fr', 'secteur' => 'Bois']],
            ['kind' => 'transformation', 'depuis' => 'clients_2026', 'depuis_titre' => 'Clients 2026',
                'bases' => [['kb' => self::KB, 'stamp' => KbTableStore::stamp($perm), 'titre' => 'Clients 2026']]]);
        $result = $this->store->find(self::CONV, 'secteurs');
        $this->assertSame('Tirée de « Clients 2026 »', WorkTableView::originLabel($result));

        $canWrite = static fn(int $kb): bool => $kb === self::KB;
        $panel = WorkTableView::panel($this->store, self::CONV, (int)$result['id'], '', 1, false, true, [$perm], self::reader(self::rows(6)), false, $canWrite);
        $this->assertSame('Ajouter la colonne « Secteur » à « Clients 2026 »', $panel['selected']['update']['label']);
        $this->assertStringContainsString('data-table-update-source', WorkTableView::panelHtml($panel));
        $none = WorkTableView::panel($this->store, self::CONV, (int)$result['id'], '', 1, false, true, [$perm], self::reader(self::rows(6)));
        $this->assertNull($none['selected']['update'], 'Sans le droit de la modifier : seulement l’avertissement.');

        // La clé de rapprochement : l'email, unique et renseigné.
        $this->assertSame(['email', 'email'], WorkTableView::matchKey((array)$perm['columns'], self::rows(6), (array)$result['columns'], $this->store->rows((int)$result['id'])));
        $this->assertNull(WorkTableView::matchKey(WorkTableOps::columns(['Ville']), [['ville' => 'Lyon'], ['ville' => 'Lyon']], WorkTableOps::columns(['Ville']), [['ville' => 'Lyon']]),
            'Une colonne qui se répète ne désigne pas une ligne.');
        $this->assertSame(['Secteur'], WorkTableView::missingColumns((array)$perm['columns'], (array)$result['columns']));
    }

    public function testLaConsigneParleDeVuesEtDeResultatsJamaisDeTableDeTravail(): void
    {
        $tools = $this->tools(self::permanent(5));
        $g = $tools->guidance();
        $this->assertStringContainsString('jamais « table de travail »', $g);
        $this->assertStringContainsString('Travaille DIRECTEMENT sur elle, jamais sur une copie', $g);
        $this->assertStringContainsString('« La table », « le tableau » désignent la table permanente', $g);
        foreach (['collecter_dans_table' => null, WorkTableTools::TRANSFORM => 'titre', WorkTableTools::JOIN => 'titre'] as $name => $param) {
            foreach ($tools->definitions() as $d) {
                if ($d['name'] === $name && $param !== null) {
                    $this->assertArrayHasKey($param, $d['parameters']['properties'], $name . ' déclare un titre lisible.');
                }
            }
        }
    }
}
