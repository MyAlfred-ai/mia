<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Workflows, troisième lot : la DÉCISION DE MIA (une brique qui choisit une
 * voie parmi deux à six) et les FILTRES (ce que les recherches d'emails et de
 * fichiers voient, à qui les emails partent) — la forme (WorkflowSpec,
 * WorkflowFilters), leur application aux outils (SearchScope), la lecture
 * du choix de MIA (WorkflowAi::parseChoice), l'outil creer_workflow
 * (WorkflowDraft), et le rail du navigateur, qui doit compter les voies
 * exactement comme le serveur.
 */
final class WorkflowRouteFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    /** Un email arrive → MIA trie (devis, réclamation, autre) → une réponse filtrée. */
    public static function routeDefinition(): array
    {
        return WorkflowSpec::normalize([
            'start' => ['event' => ['kind' => 'email', 'source' => 'gmail', 'filters' => ['mail' => ['from' => '@{depart.x} client@x.fr', 'attachment' => true, 'unread' => true]]]],
            'nodes' => [
                ['key' => 'tri', 'type' => 'route', 'title' => 'Trier', 'instruction' => 'Classe @{evenement}.',
                    'routes' => [
                        ['key' => 'devis', 'label' => 'Devis', 'when' => 'Une demande de prix de @{evenement.expediteur}.', 'then' => 'continue', 'nodes' => [
                            ['key' => 'chiffrer', 'type' => 'step', 'title' => 'Chiffrer', 'instruction' => 'Chiffre.', 'output' => ['key' => 'offre', 'name' => 'Offre', 'form' => 'document']],
                        ]],
                        ['key' => 'yes', 'label' => 'Réclamation', 'then' => 'stop', 'nodes' => [
                            ['type' => 'route', 'title' => 'Interdite dans une voie'],
                            ['key' => 'excuser', 'type' => 'step', 'title' => 'Excuses', 'instruction' => 'x', 'output' => ['key' => 'excuses', 'name' => 'Excuses', 'form' => 'text']],
                        ]],
                        ['label' => 'Autre', 'then' => 'stop'],
                        ['label' => 'Quatre'], ['label' => 'Cinq'], ['label' => 'Six'], ['label' => 'Sept, de trop'],
                    ],
                    'fallback' => 'nimporte'],
                ['key' => 'repondre', 'type' => 'action', 'title' => 'Répondre', 'target' => 'gmail', 'instruction' => 'Réponds avec @{offre}.',
                    'filters' => ['mail' => ['from' => '@{evenement.expediteur}', 'days' => 900, 'subject' => "Devis\n(urgent)"], 'recipients' => '@client.fr; Bob <bob@x.fr>, pas une adresse']],
            ],
            'end' => ['pieces' => ['offre']],
        ]);
    }

    public function testUneDecisionEstBorneeEtSesVoiesBienNommees(): void
    {
        $def = self::routeDefinition();
        $route = $def['nodes'][0];
        $this->assertSame('route', $route['type']);
        $this->assertCount(WorkflowSpec::MAX_ROUTES, $route['routes'], 'Six voies au plus.');
        $keys = array_column($route['routes'], 'key');
        $this->assertSame('devis', $keys[0]);
        $this->assertSame('yes_2', $keys[1], 'Une clé réservée (yes, no, main, body) est renommée.');
        $this->assertSame('autre', $keys[2], 'Sans clé : celle du nom de la voie.');
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertSame(WorkflowSpec::FALLBACK_ASK, $route['fallback'], 'Une voie de repli inconnue redevient « me demander ».');
        $this->assertSame(['excuser'], array_column($route['routes'][1]['nodes'], 'key'), 'Une voie ne porte pas de décision.');
        $this->assertSame(['retries' => 1, 'then' => 'stop'], $route['on_error'], 'Une décision ne s’ignore pas.');
        $this->assertSame('continue', $route['routes'][3]['then']);

        $again = WorkflowSpec::normalize(['nodes' => [['key' => 'd', 'type' => 'route', 'routes' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']], 'fallback' => 'b']]]);
        $this->assertSame('b', $again['nodes'][0]['fallback'], 'Une voie de repli connue est gardée.');
    }

    public function testLesVoiesDUneDecisionSeComptentCommeCellesDUneCondition(): void
    {
        $def = self::routeDefinition();
        $this->assertSame(['devis', 'yes_2', 'autre', 'quatre', 'cinq', 'six'], array_keys(WorkflowSpec::branchesOf($def['nodes'][0])));
        $this->assertSame([], WorkflowSpec::branchesOf($def['nodes'][1]));
        $this->assertTrue(WorkflowSpec::isBranching($def['nodes'][0]));

        $pieces = WorkflowSpec::pieces($def);
        $this->assertSame('tri:devis', $pieces['offre']['branch']);
        $this->assertSame('tri:yes_2', $pieces['excuses']['branch']);

        $at = WorkflowSpec::locate($def, 'chiffrer');
        $this->assertSame(['lane', 'devis', 'tri'], [$at['in'], $at['lane'], $at['cond']]);

        // Une pièce d'une voie n'est garantie après la décision que si TOUTES
        // les autres voies arrêtent le workflow : ici « Quatre » continue.
        $this->assertArrayNotHasKey('offre', WorkflowSpec::available($def, 'repondre'));
        $stopOthers = $def;
        foreach ($stopOthers['nodes'][0]['routes'] as $i => $r) {
            if ($r['key'] !== 'devis') {
                $stopOthers['nodes'][0]['routes'][$i]['then'] = 'stop';
            }
        }
        $this->assertArrayHasKey('offre', WorkflowSpec::available($stopOthers, 'repondre'));
        $this->assertArrayNotHasKey('offre', WorkflowSpec::available($def, 'excuser'), 'Une voie ne voit pas les pièces d’une autre.');

        $this->assertSame('chiffrer', WorkflowSpec::next($def, 'tri', 'devis'));
        $this->assertSame('repondre', WorkflowSpec::next($def, 'chiffrer'), 'Fin d’une voie qui continue : la suite du rail.');
        $this->assertSame(WorkflowSpec::STOP, WorkflowSpec::next($def, 'excuser'), 'Fin d’une voie qui arrête.');
        $this->assertSame(WorkflowSpec::STOP, WorkflowSpec::next($def, 'tri', 'autre'), 'Voie vide qui arrête.');
        $this->assertSame('repondre', WorkflowSpec::next($def, 'tri', 'quatre'), 'Voie vide qui continue.');
        $this->assertSame(WorkflowSpec::STOP, WorkflowSpec::next($def, 'tri', 'inconnue'), 'Une voie inconnue n’emmène nulle part.');

        $this->assertSame('daxe', WorkflowSpec::silhouette($def), 'La silhouette suit le rail principal.');
        $this->assertSame(1, WorkflowSpec::counts($def)['routes']);
        $this->assertSame('Devis', WorkflowSpec::laneLabel($def['nodes'][0], 'devis'));
        $this->assertContains('excuser', array_column(WorkflowSpec::flatNodes($def), 'key'));
    }

    public function testCeQuiResteARegler(): void
    {
        $issue = static function (array $def, string $node, string $text): bool {
            foreach (WorkflowSpec::issues($def, []) as $i) {
                if ($i['node'] === $node && str_contains($i['text'], $text)) {
                    return true;
                }
            }
            return false;
        };
        $vide = WorkflowSpec::normalize(['nodes' => [['key' => 'd', 'type' => 'route', 'title' => 'D', 'routes' => [['label' => 'Seule']]]]]);
        $this->assertTrue($issue($vide, 'd', 'sur quoi choisir'));
        $this->assertTrue($issue($vide, 'd', 'au moins deux voies'));
        $this->assertTrue($issue($vide, 'd', 'sans rien lire'));

        $doublon = WorkflowSpec::normalize(['nodes' => [['key' => 'd', 'type' => 'route', 'title' => 'D', 'instruction' => 'x @{inconnue}',
            'routes' => [['label' => 'A', 'when' => '@{plus_tard}'], ['label' => 'a'], ['label' => ''], ]],
            ['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => 'x', 'output' => ['key' => 'plus_tard', 'name' => 'Plus tard', 'form' => 'text']]]]);
        $this->assertTrue($issue($doublon, 'd', 'même nom'));
        $this->assertTrue($issue($doublon, 'd', 'Nommez chaque voie'));
        $this->assertTrue($issue($doublon, 'd', 'Une voie cite « Plus tard »'));

        $def = self::routeDefinition();
        $this->assertTrue($issue($def, 'repondre', 'ni une adresse ni un domaine'), 'Un destinataire permis invalide est signalé.');
        $this->assertFalse($issue($def, 'tri', 'au moins deux voies'));

        $filtreAvant = WorkflowSpec::normalize(['nodes' => [
            ['key' => 'a', 'type' => 'step', 'title' => 'A', 'instruction' => 'x', 'sources' => ['gmail'], 'filters' => ['mail' => ['from' => '@{plus_tard.x}']], 'output' => ['key' => 'pa', 'name' => 'PA', 'form' => 'text']],
            ['key' => 'b', 'type' => 'step', 'title' => 'B', 'instruction' => 'x', 'output' => ['key' => 'plus_tard', 'name' => 'Plus tard', 'form' => 'fiche', 'fields' => [['key' => 'x', 'label' => 'X', 'type' => 'email']]]],
        ]]);
        $this->assertTrue($issue($filtreAvant, 'a', 'Un filtre cite « Plus tard »'));

        $toutArrete = WorkflowSpec::normalize(['nodes' => [
            ['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => 'x', 'output' => ['key' => 'p', 'name' => 'P', 'form' => 'text']],
            ['key' => 'd', 'type' => 'route', 'title' => 'D', 'instruction' => '@{p}', 'routes' => [['label' => 'A', 'then' => 'stop'], ['label' => 'B', 'then' => 'stop']]],
        ], 'end' => ['pieces' => ['p']]]);
        $this->assertTrue($issue($toutArrete, 'd', 'jamais atteint'));
    }

    public function testLeDestinatairePeutCiterUnChampEmail(): void
    {
        $base = ['start' => ['fields' => [['key' => 'mail', 'label' => 'Email', 'type' => 'email'], ['key' => 'nom', 'label' => 'Nom', 'type' => 'text']]],
            'nodes' => [['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => 'x', 'output' => ['key' => 'p', 'name' => 'P', 'form' => 'text']]],
            'end' => ['pieces' => ['p']]];
        $avec = static function (string $to) use ($base): array {
            $def = $base;
            $def['end']['deliveries'] = [['type' => 'email', 'to' => $to, 'subject' => 'S', 'message' => 'M', 'mode' => 'approve']];
            return array_values(array_filter(WorkflowSpec::issues(WorkflowSpec::normalize($def), []), static fn($i) => $i['node'] === 'end'));
        };
        $this->assertSame([], $avec('@{depart.mail}'), 'Un champ email cité suffit comme destinataire.');
        $this->assertSame([], $avec('@{depart.mail}, chef@x.fr'));
        $this->assertNotSame([], $avec('@{depart.nom}'), 'Un champ texte n’est pas une adresse.');
        $this->assertSame('a@x.fr', WorkflowSpec::withoutMentions('@{depart.mail} a@x.fr'));
    }

    public function testLesFiltresSontBornesEtRemplisDesValeursDuDossier(): void
    {
        $f = WorkflowFilters::normalize(['mail' => ['from' => " a\tb ", 'days' => 999, 'attachment' => true], 'drive' => ['kind' => 'video', 'days' => -3], 'recipients' => str_repeat('x', 400)]);
        $this->assertSame('a b', $f['mail']['from']);
        $this->assertSame(WorkflowFilters::DAYS_MAX, $f['mail']['days']);
        $this->assertSame('', $f['drive']['kind'], 'Une nature inconnue est retirée.');
        $this->assertSame(0, $f['drive']['days']);
        $this->assertSame(300, mb_strlen($f['recipients']));
        $this->assertTrue(WorkflowFilters::isEmpty(WorkflowFilters::blank()));
        $this->assertFalse(WorkflowFilters::mailIsEmpty($f));

        $pour = WorkflowFilters::forConnectors(['mail' => ['from' => 'x'], 'drive' => ['folder' => 'Y'], 'recipients' => '@z.fr'], ['drive'], false);
        $this->assertSame('', $pour['mail']['from'], 'Un filtre d’emails ne vaut que pour une brique qui lit des emails.');
        $this->assertSame('Y', $pour['drive']['folder']);
        $lit = WorkflowFilters::forConnectors(['mail' => ['from' => 'x'], 'recipients' => '@z.fr'], ['gmail'], false);
        $this->assertSame('', $lit['recipients'], 'Les destinataires ne comptent que pour une brique qui agit.');

        $pieces = ['evenement' => ['key' => 'evenement', 'name' => 'Email reçu', 'form' => 'fiche', 'fields' => WorkflowSpec::EMAIL_FIELDS],
            'client' => ['key' => 'client', 'name' => 'Client en cours', 'form' => 'text', 'fields' => []]];
        $arts = ['evenement' => ['content' => ['fields' => ['expediteur' => 'paul@client.fr']]], 'client' => ['content' => ['text' => 'Acme (France) "SA"']]];
        $filled = WorkflowFilters::fill(['mail' => ['from' => '@{evenement.expediteur}'], 'drive' => ['folder' => 'Clients/@{client}']], $pieces, $arts);
        $this->assertSame('paul@client.fr', $filled['mail']['from']);
        $this->assertSame('Clients/Acme France SA', $filled['drive']['folder'], 'Guillemets et parenthèses retirés : ils fermeraient la requête.');

        $rules = WorkflowFilters::recipientRules('Paul <PAUL@client.fr>; @acme.com, exemple.org, pas une adresse, @{depart.mail}');
        $this->assertSame(['paul@client.fr'], $rules['addresses']);
        $this->assertSame(['acme.com', 'exemple.org'], $rules['domains']);
        $this->assertSame(['pas une adresse'], $rules['invalid']);
        $this->assertTrue(WorkflowFilters::recipientAllowed('Jo@Sub.Acme.com', $rules), 'Un sous-domaine d’un domaine permis.');
        $this->assertFalse(WorkflowFilters::recipientAllowed('jo@notacme.com', $rules), 'Pas un faux suffixe.');
        $this->assertStringContainsString('avec pièce jointe', WorkflowFilters::summary($f));
    }

    public function testLePerimetreEstImposeAuxOutils(): void
    {
        $s = SearchScope::fromFilters(['mail' => ['from' => 'paul@client.fr', 'subject' => 'Devis (urgent)', 'attachment' => true, 'unread' => true, 'days' => 7],
            'drive' => ['folder' => 'Clients/Acme', 'name' => 'Facture', 'kind' => 'pdf', 'days' => 30], 'recipients' => '@client.fr']);
        $this->assertSame('mars from:(paul@client.fr) subject:(Devis urgent) has:attachment is:unread newer_than:7d', $s->gmailQuery('mars'));
        $this->assertSame('from:paul@client.fr subject:Devis urgent', $s->outlookQuery(''));
        $now = new \DateTimeImmutable('2026-10-16 12:00');
        $outlook = [
            ['id' => '1', 'lu' => false, 'pieces_jointes' => true, 'date' => '15/10/2026 09:00'],
            ['id' => '2', 'lu' => true, 'pieces_jointes' => true, 'date' => '15/10/2026 09:00'],
            ['id' => '3', 'lu' => false, 'pieces_jointes' => false, 'date' => '15/10/2026 09:00'],
            ['id' => '4', 'lu' => false, 'pieces_jointes' => true, 'date' => '01/09/2026 09:00'],
        ];
        $this->assertSame(['1'], array_column($s->filterOutlook($outlook, $now), 'id'));
        $drive = [
            ['id' => 'a', 'nom' => 'Facture 12', 'type' => 'PDF', 'modifie' => '2026-10-10T08:00:00Z'],
            ['id' => 'b', 'nom' => 'Facture 13', 'type' => 'Google Docs', 'modifie' => '2026-10-10T08:00:00Z'],
            ['id' => 'c', 'nom' => 'Devis', 'type' => 'PDF', 'modifie' => '2026-10-10T08:00:00Z'],
            ['id' => 'd', 'nom' => 'facture ancienne', 'type' => 'PDF', 'modifie' => '2026-01-01T08:00:00Z'],
            ['id' => 'e', 'nom' => 'Sous-dossier', 'type' => 'Dossier', 'dossier' => true],
        ];
        $this->assertSame(['a', 'e'], array_column($s->filterDrive($drive, $now), 'id'), 'Nature, nom, période ; un dossier se parcourt toujours.');
        $this->assertSame('Clients/Acme', $s->driveFolder());
        $this->assertSame('Facture', $s->driveQuery(''), 'Une recherche Drive ne part jamais vide.');
        $this->assertSame('budget', $s->driveQuery('budget'));

        $this->assertNull($s->writeRefusal('creer_brouillon_gmail', ['destinataire' => 'paul@client.fr', 'cc' => 'jo@sub.client.fr']));
        $this->assertStringContainsString('eve@autre.fr', (string)$s->writeRefusal('creer_brouillon_outlook', ['destinataire' => 'paul@client.fr', 'cc' => 'eve@autre.fr']));
        $this->assertStringContainsString('destinataire', (string)$s->writeRefusal('creer_brouillon_gmail', ['repondre_a' => 'abc']), 'Une réponse doit nommer son destinataire.');
        $this->assertNull($s->writeRefusal('creer_evenement_agenda', ['invites' => 'eve@autre.fr']), 'Seuls les emails sont filtrés.');
        $this->assertSame('Clients/Acme', $s->applyToWrite('creer_google_doc', ['titre' => 'x'])[WorkflowDeliveries::FOLDER_KEY]);
        $this->assertArrayNotHasKey(WorkflowDeliveries::FOLDER_KEY, $s->applyToWrite('modifier_google_doc', []));
        $this->assertStringContainsString('PÉRIMÈTRE IMPOSÉ', $s->promptNote());

        $none = SearchScope::none();
        $this->assertTrue($none->isEmpty());
        $this->assertSame('x', $none->gmailQuery('x'), 'Sans filtre, rien ne change.');
        $this->assertSame($outlook, $none->filterOutlook($outlook));
        $this->assertNull($none->writeRefusal('creer_brouillon_gmail', ['destinataire' => 'eve@autre.fr']));
        $this->assertSame('', $none->promptNote());
    }

    /**
     * Le dossier d'un filtre s'écrit comme on le connaît — ici un CHEMIN : la
     * création y est rangée avec la résolution de ses recherches
     * (folderByPath), pas celle d'un simple nom.
     */
    public function testUnFichierCreeEstRangeDansLeDossierDuFiltreMemeParSonChemin(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
        $drive = new class extends UserDriveService {
            public array $moves = [];
            public function __construct()
            {
                parent::__construct(1, 'test-refresh', 'test-access', '2099-01-01 00:00:00', '');
            }
            public function folderByPath(string $input): array
            {
                if ($input === 'Clients/Inconnu') {
                    throw new \RuntimeException('Sous-dossier « Inconnu » introuvable dans « Clients ».');
                }
                return ['id' => 'id-acme', 'nom' => 'Acme', 'chemin' => 'Clients/Acme'];
            }
            public function moveFile(string $idOrUrl, string $folderInput): array
            {
                $this->moves[] = [$idOrUrl, $folderInput];
                return ['id' => $idOrUrl, 'nom' => 'Offre', 'dossier' => 'Acme'];
            }
        };
        $place = new \ReflectionMethod(ChatController::class, 'placeInFolder');
        $args = SearchScope::fromFilters(['drive' => ['folder' => 'Clients/Acme']])->applyToWrite('creer_google_doc', ['titre' => 'Offre']);

        $ok = $place->invoke(null, $drive, ['id' => 'doc-1'], $args);
        $this->assertSame([['doc-1', 'id-acme']], $drive->moves, 'Le déplacement vise l’identifiant RÉSOLU du chemin.');
        $this->assertSame('Clients/Acme', $ok['range_dans']);

        $ko = $place->invoke(null, $drive, ['id' => 'doc-2'], [WorkflowDeliveries::FOLDER_KEY => 'Clients/Inconnu']);
        $this->assertStringContainsString('introuvable', (string)$ko['rangement_echec'], 'Un rangement impossible ne défait pas la création : l’issue le dit.');
        $this->assertCount(1, $drive->moves);
        $this->assertSame(['id' => 'doc-3'], $place->invoke(null, $drive, ['id' => 'doc-3'], []), 'Sans dossier, rien ne bouge.');
    }

    public function testUnDeclencheurGardeSeulementSesFiltres(): void
    {
        $def = self::routeDefinition();
        $ev = $def['start']['event'];
        $this->assertSame('client@x.fr', $ev['filters']['mail']['from'], 'Pas de citation dans un déclencheur : le dossier n’existe pas encore.');
        $this->assertTrue($ev['filters']['mail']['attachment']);
        $this->assertFalse($ev['filters']['mail']['unread'], 'Une surveillance ne voit que les nouveaux emails : « non lus » n’y a pas de sens.');
        $file = WorkflowSpec::normalize(['start' => ['event' => ['kind' => 'file', 'folder' => 'X', 'filters' => ['drive' => ['kind' => 'pdf', 'name' => 'Facture', 'folder' => 'ignoré'], 'mail' => ['from' => 'x']]]]]);
        $this->assertSame(['folder' => '', 'name' => 'Facture', 'kind' => 'pdf', 'days' => 0], $file['start']['event']['filters']['drive']);
        $this->assertSame('', $file['start']['event']['filters']['mail']['from']);
        $this->assertNotSame(WorkflowTriggers::watchSignature(['kind' => 'email', 'source' => 'gmail', 'query' => '']),
            WorkflowTriggers::watchSignature($ev), 'Un filtre changé : nouvelle ligne de base.');
        $this->assertStringContainsString('de client@x.fr', WorkflowCatalog::eventSentence($ev));
    }

    public function testLeChoixDeMiaSeLitSansDeviner(): void
    {
        $routes = [['key' => 'devis', 'label' => 'Devis', 'when' => ''], ['key' => 'reclam', 'label' => 'Réclamation', 'when' => '']];
        $this->assertSame(['devis', 'devis', 'Prix demandé.'], array_values(array_slice(WorkflowAi::parseChoice('{"voie": 1, "raison": "Prix demandé."}', $routes), 0, 3)));
        $this->assertSame('reclam', WorkflowAi::parseChoice("```json\n{\"voie\": \"2\", \"raison\": \"x\"}\n```", $routes)['route']);
        $this->assertSame('reclam', WorkflowAi::parseChoice('{"voie": "réclamation", "raison": "x"}', $routes)['route'], 'Par son nom.');
        $aucune = WorkflowAi::parseChoice('{"voie": "aucune", "plus_proche": 2, "raison": "Ni l’un ni l’autre."}', $routes);
        $this->assertSame(['', 'reclam'], [$aucune['route'], $aucune['closest']], 'Aucune : la personne tranche, la plus proche en tête.');
        $this->assertSame('', WorkflowAi::parseChoice('{"voie": 9}', $routes)['route'], 'Hors liste : aucune voie.');
        $this->assertNotNull(WorkflowAi::parseChoice('Je pense que c’est un devis.', $routes)['error'], 'Sans JSON : une erreur, jamais une voie devinée.');
    }

    public function testCreerWorkflowSaitDecrireUneDecisionEtDesFiltres(): void
    {
        $res = WorkflowDraft::fromArgs([
            'nom' => 'Tri', 'depart' => ['champs' => [['nom' => 'Demande', 'type' => 'texte_long']]],
            'briques' => [
                ['type' => 'decision', 'titre' => 'Quel type ?', 'criteres' => 'Lis [Informations de départ].', 'si_doute' => 'Autre',
                    'voies' => [
                        ['nom' => 'Devis', 'quand' => 'une demande de prix', 'briques' => [['type' => 'etape', 'titre' => 'Chiffrer', 'consigne' => 'x', 'piece' => ['nom' => 'Offre']]]],
                        ['nom' => 'Autre', 'ensuite' => 'arreter'],
                    ]],
                ['type' => 'action', 'titre' => 'Répondre', 'outil' => 'gmail', 'consigne' => 'x', 'filtres' => ['de' => 'a@b.fr', 'jours' => 3, 'destinataires' => '@b.fr']],
            ],
        ], ['sources' => [], 'profiles' => [], 'tools' => []]);
        $def = $res['definition'];
        $route = $def['nodes'][0];
        $this->assertSame('route', $route['type']);
        $this->assertSame(['devis', 'autre'], array_column($route['routes'], 'key'));
        $this->assertSame('autre', $route['fallback']);
        $this->assertSame('stop', $route['routes'][1]['then']);
        $this->assertSame(['a@b.fr', 3, '@b.fr'], [$def['nodes'][1]['filters']['mail']['from'], $def['nodes'][1]['filters']['mail']['days'], $def['nodes'][1]['filters']['recipients']]);
        $this->assertStringContainsString('"decision"', WorkflowDraft::schemaGuide([]));
    }

    public function testLeRailDuNavigateurCompteLesVoiesCommeLeServeur(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $def = self::routeDefinition();
        $keys = array_merge(array_map(static fn($n) => $n['key'], WorkflowSpec::flatNodes($def)), ['end']);
        $expected = ['pieces' => array_map(static fn($p) => [$p['key'], $p['form'], $p['branch'], $p['producer']], array_values(WorkflowSpec::pieces($def))), 'available' => [], 'reads' => []];
        foreach ($keys as $k) {
            $expected['available'][$k] = array_keys(WorkflowSpec::available($def, $k));
        }
        foreach (WorkflowSpec::flatNodes($def) as $n) {
            $expected['reads'][$n['key']] = WorkflowSpec::readsOf($def, $n);
        }
        $cat = ['icons' => WorkflowCatalog::icons(), 'hues' => WorkflowCatalog::HUES, 'labels' => WorkflowCatalog::labels(), 'colors' => WorkflowCatalog::FORM_COLORS];
        $script = 'require(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ');'
            . 'var R = globalThis.MiaWorkflowRail; var d = ' . json_encode($def, JSON_UNESCAPED_UNICODE) . '; var cat = ' . json_encode($cat, JSON_UNESCAPED_UNICODE) . ';'
            . 'var keys = ' . json_encode($keys) . '; var av = {}, rd = {};'
            . 'keys.forEach(function (k) { av[k] = Object.keys(R.available(d, k)); });'
            . 'var flat = []; d.nodes.forEach(function (n) { flat.push(n); R.branchesOf(n).forEach(function (b) { flat = flat.concat(b.nodes); }); });'
            . 'flat.forEach(function (n) { rd[n.key] = R.readsOf(d, n); });'
            . 'var html = R.html(d, { catalog: cat, editable: true });'
            . 'var colors = R.pieces(d, cat.colors).map(function (p) { return [p.form, p.color]; });'
            . 'var transit = R.transitMap(d, R.pieces(d, cat.colors), (function () { var m = {}; R.pieces(d, cat.colors).forEach(function (p) { m[p.key] = p; }); return m; })()).map(function (l) { return l.map(function (p) { return p.key; }); });'
            . 'process.stdout.write(JSON.stringify({ pieces: R.pieces(d).map(function (p) { return [p.key, p.form, p.branch, p.producer]; }), available: av, reads: rd, html: html, colors: colors, transit: transit, sil: R.silhouette("dae", cat.hues) }));';
        $file = tempnam(sys_get_temp_dir(), 'wfroute') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertIsArray($got, 'workflow-rail.js a échoué sous node.');
        $this->assertSame($expected['pieces'], $got['pieces']);
        $this->assertSame($expected['available'], $got['available']);
        $this->assertSame($expected['reads'], $got['reads'], 'Les pièces lues (voies, filtres) se comptent des deux côtés.');

        // Le rendu : une voie par scénario, numérotée, son nom ; des paquets sur les liens.
        $this->assertStringContainsString('class="wf-fork wf-fork-route" style="--lanes:6"', $got['html']);
        $this->assertSame(6, substr_count($got['html'], 'class="wf-lane wf-lane-route'));
        $this->assertStringContainsString('data-lane="yes_2"', $got['html']);
        $this->assertStringContainsString('<span class="wf-lane-name" title="Devis">Devis</span>', $got['html']);
        $this->assertStringContainsString('class="wf-pk"', $got['html']);
        $this->assertStringContainsString('MIA choisit parmi 6 voies', $got['html']);
        $this->assertStringContainsString('Filtrée', $got['html'], 'Une brique filtrée le dit sur le rail.');
        // Une couleur = une nature : deux pièces de même forme ont la même couleur.
        foreach ($got['colors'] as [$form, $color]) {
            $this->assertSame(WorkflowCatalog::FORM_COLORS[$form], $color, "Couleur de la nature « {$form} ».");
        }
        // L'email reçu passe sur les liens jusqu'à sa dernière lectrice (l'action, 2e brique).
        $this->assertContains('evenement', $got['transit'][0]);
        $this->assertContains('evenement', $got['transit'][1]);
        $this->assertNotContains('evenement', $got['transit'][2], 'Plus personne ne la lit après.');
        $this->assertStringContainsString('class="d"', $got['sil'], 'Une décision a la forme d’un losange, comme une condition.');
    }
}
