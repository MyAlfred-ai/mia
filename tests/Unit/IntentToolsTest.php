<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/AgentSource.php';
require_once ROOT . '/src/Controllers/ChatController.php';

/**
 * Outils par intention (rechercher_emails, consulter_agendas,
 * rechercher_partout) et leur transport parallèle.
 *
 * Ce que ces tests protègent :
 *   - l'exposition : un outil multi-sources n'apparaît que lorsqu'il recoupe
 *     réellement plusieurs sources engagées ;
 *   - la fusion : chaque élément garde sa source, une source en panne est
 *     nommée sans faire taire les autres ;
 *   - le PARALLÉLISME, mesuré contre un vrai serveur HTTP local : trois
 *     requêtes d'une seconde chacune prennent une seconde, pas trois — en
 *     passant par le vrai point HTTP des connecteurs (ConnectorApiService).
 */
final class IntentToolsTest extends TestCase
{
    /** @var list<resource> serveurs HTTP locaux démarrés par le test */
    private array $servers = [];
    private ?string $router = null;

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
        }
        $this->servers = [];
        if ($this->router !== null) {
            @unlink($this->router);
            $this->router = null;
        }
    }

    private function turn(array &$events = []): AgentTurn
    {
        return new AgentTurn(1, 2, [], 1000, function (array $e) use (&$events): void { $events[] = $e; },
            static fn(string $t, array $a, ?array $r): array => [], 'Europe/Paris');
    }

    private function mail(array $rows, ?string $error = null): object
    {
        return new class($rows, $error) {
            public array $calls = [];
            public function __construct(private array $rows, private ?string $error) {}
            public function search(string $q, int $n): array
            {
                $this->calls[] = [$q, $n];
                if ($this->error !== null) {
                    throw new \RuntimeException($this->error);
                }
                return $this->rows;
            }
        };
    }

    private function agenda(array $rows): object
    {
        return new class($rows) {
            public array $calls = [];
            public function __construct(private array $rows) {}
            public function searchEvents(string $cal, string $q, string $from, string $to, int $n): array
            {
                $this->calls[] = [$cal, $q, $from, $to, $n];
                return $this->rows;
            }
        };
    }

    // ===================== Exposition =====================

    public function testUnOutilMultiSourcesNApparaitQuAvecPlusieursSources(): void
    {
        $services = [];
        $tools = new IntentTools($this->turn(), function () use (&$services): array { return $services; });
        $this->assertSame([], $tools->definitions(), 'Aucune source : aucun outil.');
        $this->assertSame('', $tools->guidance());

        $services = ['gmail' => $this->mail([])];
        $this->assertSame([], $tools->definitions(), 'Une seule source : son propre outil suffit.');

        $services['outlook'] = $this->mail([]);
        $this->assertSame(['rechercher_emails', 'rechercher_partout'], array_column($tools->definitions(), 'name'));

        $services['calendar']   = $this->agenda([]);
        $services['mscalendar'] = $this->agenda([]);
        $this->assertSame(['rechercher_emails', 'consulter_agendas', 'rechercher_partout'], array_column($tools->definitions(), 'name'));
        $this->assertStringContainsString('rechercher_partout', $tools->guidance());

        // Une source + des bases de connaissances = deux sources.
        $withBases = new IntentTools($this->turn(), static fn(): array => ['drive' => new \stdClass()], static fn(string $q): ?string => 'extrait');
        $this->assertSame(['rechercher_partout'], array_column($withBases->definitions(), 'name'));
        $this->assertNull($withBases->connector(), 'Pas un connecteur : il ne fait pas tourner la méthode agent à lui seul.');
    }

    public function testSlackSansDroitDeRechercheNEstPasUneSource(): void
    {
        $slack = new class { public function canSearch(): bool { return false; } };
        $this->assertSame(['gmail' => 'Gmail'], IntentTools::searchableSources(['gmail' => new \stdClass(), 'slack' => $slack], false));
    }

    public function testLeRegistreRecalculeUnFournisseurReajouteASaPlace(): void
    {
        $services = ['gmail' => $this->mail([])];
        $registry = new ToolRegistry();
        $intent   = new IntentTools($this->turn(), function () use (&$services): array { return $services; });
        $registry->add($intent);
        $registry->add(new PlanTools($this->turn(), static function (array $p): void {}));
        $this->assertSame(['plan_de_travail'], array_column($registry->definitions(), 'name'));

        // Un connecteur activé en cours de réponse : le fournisseur est ré-ajouté.
        $services['outlook'] = $this->mail([]);
        $registry->add($intent);
        $this->assertSame(['rechercher_emails', 'rechercher_partout', 'plan_de_travail'],
            array_column($registry->definitions(), 'name'), 'Recalculé, à sa place d\'origine, sans doublon.');
    }

    // ===================== Fusion =====================

    public function testRechercherEmailsFusionneEtNommeLaSource(): void
    {
        $gmail   = $this->mail([['id' => 'g1', 'de' => 'a@x.fr', 'objet' => 'Ancien', 'date' => 'Mon, 05 Oct 2026 08:00:00 +0200']]);
        $outlook = $this->mail([['id' => 'o1', 'de' => 'b@x.fr', 'objet' => 'Récent', 'date' => '06/10/2026 09:30']]);
        $events  = [];
        $tools   = new IntentTools($this->turn($events), static fn(): array => ['gmail' => $gmail, 'outlook' => $outlook]);
        $r = $tools->handlers()['rechercher_emails'](['requete' => '', 'max_resultats' => 50]);

        $this->assertSame(['Outlook', 'Gmail'], array_column($r['resultats'], 'source'), 'Du plus récent au plus ancien.');
        $this->assertSame([['in:inbox', IntentTools::PER_SOURCE_MAX]], $gmail->calls, 'Requête vide = derniers messages, plafond respecté.');
        $this->assertSame('', $outlook->calls[0][0]);
        $this->assertSame(['phase' => 'emails_search'], $events[0]);
    }

    public function testUneSourceEnPanneEstNommeeSansFaireTaireLesAutres(): void
    {
        $gmail   = $this->mail([['id' => 'g1', 'objet' => 'Devis', 'date' => '']]);
        $outlook = $this->mail([], 'Connexion Outlook expirée ou révoquée.');
        $r = (new IntentTools($this->turn(), static fn(): array => ['gmail' => $gmail, 'outlook' => $outlook]))
            ->handlers()['rechercher_emails'](['requete' => 'devis']);
        $this->assertCount(1, $r['resultats']);
        $this->assertSame(['Outlook' => 'Connexion Outlook expirée ou révoquée.'], $r['erreurs']);
    }

    public function testConsulterAgendasFusionneChronologiquement(): void
    {
        $google  = $this->agenda([['id' => 'e1', 'titre' => 'Comité', 'debut' => 'mardi 06/10/2026 14:00']]);
        $outlook = $this->agenda([['id' => 'e2', 'titre' => 'Point', 'debut' => 'mardi 06/10/2026 09:00'],
                                  ['id' => 'e3', 'titre' => 'Salon', 'debut' => 'lundi 05/10/2026 (journée entière)']]);
        $r = (new IntentTools($this->turn(), static fn(): array => ['calendar' => $google, 'mscalendar' => $outlook]))
            ->handlers()['consulter_agendas'](['debut' => '2026-10-05', 'fin' => '2026-10-09']);
        $this->assertSame(['Salon', 'Point', 'Comité'], array_column($r['evenements'], 'titre'));
        $this->assertSame(['Calendrier Outlook', 'Calendrier Outlook', 'Google Agenda'], array_column($r['evenements'], 'source'));
        $this->assertSame('2026-10-05', $google->calls[0][2]);
    }

    public function testRechercherPartoutRangeParSourceAvecLOutilDeLecture(): void
    {
        $drive = new class { public function search(string $q, int $n): array { return [['id' => 'd1', 'nom' => 'Devis Durand.pdf']]; } };
        $tasks = new class {
            public function listTasks(string $l, string $s, string $f, string $t, int $n): array
            {
                return [['id' => 't1', 'titre' => 'Relancer Durand', 'apercu' => ''], ['id' => 't2', 'titre' => 'Courses', 'apercu' => '']];
            }
        };
        $cal   = $this->agenda([]);
        $gmail = $this->mail([['id' => 'g1', 'objet' => 'Durand']]);
        $events = [];
        $tools = new IntentTools($this->turn($events),
            static fn(): array => ['gmail' => $gmail, 'drive' => $drive, 'calendar' => $cal, 'tasks' => $tasks],
            static fn(string $q): ?string => 'Extrait de la base : contrat Durand signé.');
        $r = $tools->handlers()['rechercher_partout'](['requete' => 'Durand']);

        $this->assertSame(['Gmail', 'Google Drive', 'Google Tasks', 'Bases de connaissances'], array_keys($r['sources']));
        $this->assertSame('lire_gmail (message = id)', $r['sources']['Gmail']['lire_avec']);
        $this->assertSame(['Relancer Durand'], array_column($r['sources']['Google Tasks']['elements'], 'titre'), 'Tâches filtrées sur les mots cherchés.');
        $this->assertStringContainsString('contrat Durand', $r['sources']['Bases de connaissances']['extraits']);
        $this->assertSame(['Google Agenda'], $r['sans_resultat']);
        // Agendas : fenêtre de 90 jours avant et après, dans le fuseau de l'utilisateur.
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $this->assertSame($today->modify('-90 days')->format('Y-m-d'), $cal->calls[0][2]);
        $this->assertSame(['phase' => 'everywhere_search'], $events[0]);
        $this->assertArrayHasKey('error', $tools->handlers()['rechercher_partout'](['requete' => '  ']));
    }

    public function testLesFormesDeTriSontRobustes(): void
    {
        $this->assertGreaterThan(IntentTools::emailTime(['date' => '05/10/2026 09:00']), IntentTools::emailTime(['date' => '06/10/2026 08:00']));
        $this->assertSame(0, IntentTools::emailTime(['date' => '']));
        $this->assertSame(PHP_INT_MAX, IntentTools::displayTime('date illisible'));
        [$rows, $errors] = IntentTools::collect([
            'gmail' => ['ok' => [1]],
            'drive' => ['error' => new \TypeError('interne')],
        ]);
        $this->assertSame(['gmail' => [1]], $rows);
        $this->assertSame('Source indisponible pour le moment.', $errors['Google Drive'], 'Une panne interne ne fuit pas vers le modèle.');
    }

    // ===================== ParallelHttp =====================

    public function testLePlanificateurRendChaqueIssueSousSaCle(): void
    {
        $inside = null;
        $r = ParallelHttp::run([
            'a' => static function () use (&$inside) { $inside = ParallelHttp::active(); return 1; },
            'b' => static function () { throw new \RuntimeException('panne'); },
            'c' => static fn() => ParallelHttp::run(['x' => static fn() => 'imbriqué']),
        ]);
        $this->assertTrue($inside, 'Une tâche tourne sous le planificateur.');
        $this->assertSame(1, $r['a']['ok']);
        $this->assertSame('panne', $r['b']['error']->getMessage());
        $this->assertSame(['x' => ['ok' => 'imbriqué']], $r['c']['ok'], 'Imbriqué : exécution à la suite, sans seconde boucle.');
        $this->assertFalse(ParallelHttp::active(), 'Hors planificateur, le transport historique reprend.');
    }

    public function testLeTransportDesConnecteursPasseParLePlanificateur(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Connectors/ConnectorApiService.php');
        $this->assertStringContainsString('if (ParallelHttp::active()) {', $src);
        $this->assertStringContainsString('ParallelHttp::request($method, $url, explode("\r\n", $headers), $payload, $timeout)', $src);
        $this->assertStringContainsString('@file_get_contents($url, false, $ctx)', $src, 'Le transport historique reste le chemin ordinaire.');
        $par = (string)file_get_contents(ROOT . '/src/Connectors/ParallelHttp.php');
        $this->assertStringContainsString("CURLOPT_PROXY          => ''", $par);
    }

    /**
     * Démarre un serveur HTTP local (une seconde par requête) et rend son
     * adresse. UN serveur par requête simultanée, et non un serveur à
     * plusieurs workers : `php -S` répartit mal ses workers — mesuré, l'un
     * d'eux sert parfois les trois requêtes à la suite, ce qui rendait le test
     * de parallélisme aléatoire pour une raison étrangère au code testé.
     */
    private function startServer(): string
    {
        if ($this->router === null) {
            $this->router = sys_get_temp_dir() . '/mia_par_router_' . getmypid() . '.php';
            file_put_contents($this->router, '<?php usleep(1000000); header("Content-Type: application/json"); echo json_encode(["path" => $_SERVER["REQUEST_URI"], "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? ""]);');
        }
        for ($tentative = 0; $tentative < 5; $tentative++) {
            $port   = random_int(20000, 40000);
            $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->router],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($server)) {
                continue;
            }
            $this->servers[] = $server;
            for ($i = 0; $i < 50; $i++) {
                if (!(proc_get_status($server)['running'] ?? false)) {
                    break; // port pris : un autre essai
                }
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($sock !== false) {
                    fclose($sock);
                    return 'http://127.0.0.1:' . $port;
                }
                usleep(100000);
            }
        }
        $this->markTestSkipped('Serveur HTTP local indisponible.');
    }

    public function testTroisRequetesDUneSecondePrennentUneSeconde(): void
    {
        if (!function_exists('curl_multi_init')) {
            $this->markTestSkipped('curl absent.');
        }
        [$s1, $s2, $s3] = [$this->startServer(), $this->startServer(), $this->startServer()];

        // Le VRAI point HTTP des connecteurs : une sous-classe minimale de
        // ConnectorApiService, jeton valide (aucun rafraîchissement).
        $make = static fn(): object => new class extends ConnectorApiService {
            public function __construct()
            {
                parent::__construct(1, 'refresh', 'jeton-de-test', date('Y-m-d H:i:s', time() + 3600), '');
            }
            public function get(string $url): array
            {
                return $this->apiJson('GET', $url, null, 10);
            }
        };

        $t0 = microtime(true);
        $r  = ParallelHttp::run([
            'a' => static fn() => $make()->get($s1 . '/a'),
            'b' => static fn() => $make()->get($s2 . '/b'),
            'c' => static fn() => $make()->get($s3 . '/c'),
        ]);
        $elapsed = microtime(true) - $t0;

        $this->assertSame('/a', $r['a']['ok']['path'] ?? null, json_encode($r));
        $this->assertSame('/c', $r['c']['ok']['path'] ?? null);
        $this->assertSame('Bearer jeton-de-test', $r['b']['ok']['auth'] ?? null, 'L\'en-tête d\'autorisation part avec la requête.');
        $this->assertLessThan(2.2, $elapsed, 'Les trois requêtes partent ensemble (sinon : trois secondes).');

        // Hors planificateur : transport historique, à la suite.
        $this->assertSame('/d', $make()->get($s1 . '/d')['path']);
    }

    public function testLeDelaiGlobalAbandonneUneSourceLente(): void
    {
        if (!function_exists('curl_multi_init')) {
            $this->markTestSkipped('curl absent.');
        }
        $base = $this->startServer();
        $t0 = microtime(true);
        $r  = ParallelHttp::run([
            'lente'  => static fn() => ParallelHttp::request('GET', $base . '/lent', [], '', 10),
            'rapide' => static fn() => 'immédiat',
        ], 0.3);
        $this->assertLessThan(1.5, microtime(true) - $t0);
        $this->assertSame('immédiat', $r['rapide']['ok']);
        $this->assertStringContainsString('trop lente', $r['lente']['error']->getMessage());
    }

    // ===================== Câblage =====================

    public function testLeChatAjouteLesOutilsParIntentionEtLesTientAJour(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('$intentTools = new IntentTools(', $src);
        $this->assertStringContainsString('$registry->add($intentTools);', $src);
        $this->assertMatchesRegularExpression('/\$intentServices\[\$key\] = \$service;\s*\$registry->add\(\$intentTools\);/', $src,
            'Un connecteur activé par MIA entre dans les recherches multi-sources.');
        foreach (['rechercher_emails' => 'emails_search', 'consulter_agendas' => 'agendas_search', 'rechercher_partout' => 'everywhere_search'] as $tool => $type) {
            $this->assertSame($type, ChatController::actionEntryForTool($tool, ['requete' => 'x'], [])['t']);
            $this->assertArrayHasKey($type, ChatController::ACTION_LABELS);
        }
        $this->assertSame('search', ChatController::actionIcon('everywhere_search'));
        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        $this->assertStringContainsString('.msg-trace-icon[data-icon="search"]', $css);
        $tools = AgentSource::tools('IntentTools');
        $this->assertStringContainsString("return ['rechercher_emails', 'consulter_agendas', 'rechercher_partout'];", $tools,
            'Les trois rapportent des contenus écrits par des tiers.');
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $code = (string)file_get_contents(ROOT . '/' . $f);
            $this->assertLessThan(strpos($code, '/src/Connectors/ConnectorApiService.php'), strpos($code, '/src/Connectors/ParallelHttp.php'));
            $this->assertStringContainsString('/src/Agent/Tools/IntentTools.php', $code);
        }
    }
}
