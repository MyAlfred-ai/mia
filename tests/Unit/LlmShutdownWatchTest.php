<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dates d'arrêt relevées chez l'éditeur (`GET /v1/models` d'OpenAI, champ
 * `shutdown_date`) : lecture de la réponse, fusion avec les dates gardées,
 * pannes qui ne changent rien, repli dans LlmCatalog::retirement() (le
 * catalogue reste prioritaire), points à régler et câblage. Sans base : le
 * magasin est en mémoire (tests/bootstrap.php), l'API rejouée par un transport
 * factice — et, pour le vrai transport, par un serveur `php -S` local.
 */
final class LlmShutdownWatchTest extends TestCase
{
    private const NOW = 1_794_470_400; // 2026-11-12 08:00 UTC

    /** @var resource|null */
    private static $serveur = null;
    private static ?string $base = null;

    protected function setUp(): void
    {
        LlmShutdownWatch::useMemoryStore();
        LlmCatalog::flushCache();
    }

    protected function tearDown(): void
    {
        LlmShutdownWatch::useMemoryStore();
        LlmCatalog::setToday(null);
        LlmCatalog::flushCache();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serveur)) {
            proc_terminate(self::$serveur);
        }
        self::$serveur = null;
        self::$base = null;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Réponse de la référence (relue le 2026-11-12), forme exacte. */
    private static function listeOpenAi(array $modeles): string
    {
        $data = [];
        foreach ($modeles as $id => $date) {
            $item = ['id' => $id, 'object' => 'model', 'created' => 1686935002, 'owned_by' => 'openai'];
            if ($date !== '__absent__') {
                $item['shutdown_date'] = $date;
            }
            $data[] = $item;
        }
        return (string)json_encode(['object' => 'list', 'data' => $data]);
    }

    /** @return array<string,array<string,mixed>> */
    private static function parId(array $signals): array
    {
        return array_column($signals, null, 'id');
    }

    // ===================================================== Lecture de la réponse

    public function testLaReponseEstLueChampParChamp(): void
    {
        $lu = LlmShutdownWatch::parseList(json_decode(self::listeOpenAi([
            'gpt-5'       => '2026-12-11',
            'gpt-6-luna'  => null,
            'gpt-6.1-sol' => '__absent__',
            'abime'       => '11/12/2026',
            'impossible'  => '2026-02-30',
        ]), true));
        $this->assertSame([
            'gpt-5'       => '2026-12-11',
            'gpt-6-luna'  => null,
            'gpt-6.1-sol' => null,
            'abime'       => null,
            'impossible'  => null,
        ], $lu, 'champ facultatif : absent ou null = aucune date ; une date mal formée n\'est jamais inventée');
    }

    public function testUneReponseQuiNEstPasUneListeNeDitRien(): void
    {
        $this->assertNull(LlmShutdownWatch::parseList(null));
        $this->assertNull(LlmShutdownWatch::parseList(['error' => ['message' => 'x']]));
        $this->assertNull(LlmShutdownWatch::parseList(['data' => 'x']));
        $this->assertSame([], LlmShutdownWatch::parseList(['object' => 'list', 'data' => []]));
        // Élément sans identifiant ignoré.
        $this->assertSame(['a' => null], LlmShutdownWatch::parseList(['data' => [['object' => 'model'], ['id' => 'a'], 'x']]));
    }

    public function testValidDate(): void
    {
        $this->assertSame('2026-12-11', LlmShutdownWatch::validDate('2026-12-11'));
        foreach ([null, '', '2026-12-11T00:00:00Z', '2026-13-01', '2026-02-29', 20261211] as $v) {
            $this->assertNull(LlmShutdownWatch::validDate($v), var_export($v, true));
        }
        $this->assertSame('2028-02-29', LlmShutdownWatch::validDate('2028-02-29'));
    }

    // ===================================================== Fusion

    public function testLaFusionGardeLaDateDUnModeleQuiADisparuDeLaListe(): void
    {
        $avant  = ['gpt-5' => '2026-12-11', 'o3' => '2026-12-11', 'gpt-4o' => '2027-01-14', 'oublie' => '2026-12-01'];
        $listes = [
            'gpt-5'      => '2027-01-15', // date repoussée par l'éditeur
            'o3'         => null,         // date annulée
            'gpt-6-luna' => '2028-01-01', // nouvelle date
            'dall-e-3'   => '2026-05-12', // hors des modèles qui comptent
        ];
        $apres = LlmShutdownWatch::merge($avant, $listes, ['gpt-5', 'o3', 'gpt-4o', 'gpt-6-luna', 'gpt-6.1-sol']);
        $this->assertSame([
            'gpt-4o'     => '2027-01-14', // absent de la liste : un modèle coupé disparaît, sa date reste
            'gpt-5'      => '2027-01-15',
            'gpt-6-luna' => '2028-01-01',
        ], $apres);
        $this->assertSame(['gpt-4o', 'gpt-5', 'gpt-6-luna'], array_keys($apres), 'écriture stable, triée');
        $this->assertSame(
            ['gpt-5 : 2026-12-11 → 2027-01-15', 'gpt-6-luna : 2028-01-01', 'o3 : 2026-12-11 annulée', 'oublie : 2026-12-01 annulée'],
            LlmShutdownWatch::changes($avant, $apres)
        );
    }

    public function testUnReglageAbimeNArreteAucunModele(): void
    {
        $this->assertSame([], LlmShutdownWatch::decodeDates(''));
        $this->assertSame([], LlmShutdownWatch::decodeDates('{pas du json'));
        $this->assertSame(
            ['openai' => ['gpt-5' => '2026-12-11']],
            LlmShutdownWatch::decodeDates('{"openai":{"gpt-5":"2026-12-11","x":"demain","":"2026-12-11"},"0":["a"]}')
        );
    }

    // ===================================================== Relevé (transport factice)

    public function testUnReleveReussiAppelleLaListeEnLectureSeuleAvecLaCleDeLInstance(): void
    {
        $appels = [];
        $transport = static function (string $url, array $headers) use (&$appels): array {
            $appels[] = [$url, $headers];
            return ['status' => 200, 'body' => self::listeOpenAi(['gpt-5' => '2026-12-11', 'gpt-6-luna' => null, 'tts-1' => '2027-01-06'])];
        };
        $r = LlmShutdownWatch::fetchProvider('openai', 'sk-test-123', [], ['gpt-5', 'gpt-6-luna'], $transport, self::NOW);

        $this->assertCount(1, $appels);
        $this->assertSame('https://api.openai.com/v1/models', $appels[0][0], 'adresse lue au catalogue (endpoints.models)');
        $this->assertContains('Authorization: Bearer sk-test-123', $appels[0][1]);
        $this->assertSame(['gpt-5' => '2026-12-11'], $r['dates']);
        $this->assertSame('', $r['check']['error']);
        $this->assertSame(self::NOW, $r['check']['ok']);
        $this->assertSame(self::NOW + LlmShutdownWatch::INTERVAL_SEC, $r['check']['next']);
        $this->assertSame(3, $r['check']['listed']);
        $this->assertSame(1, $r['check']['dated']);
        $this->assertSame(['gpt-5 : 2026-12-11'], $r['changes']);
    }

    /** @return array<string, array{0: callable, 1: string, 2: int}> */
    public static function pannes(): array
    {
        return [
            'clé refusée'         => [static fn(): array => ['status' => 401, 'body' => '{"error":{"code":"invalid_api_key"}}'], LlmShutdownWatch::ERR_AUTH, LlmShutdownWatch::INTERVAL_SEC],
            'clé sans le droit'   => [static fn(): array => ['status' => 403, 'body' => '{"error":{"message":"Missing scopes: api.model.read"}}'], LlmShutdownWatch::ERR_PERMISSION, LlmShutdownWatch::INTERVAL_SEC],
            'limite de débit'     => [static fn(): array => ['status' => 429, 'body' => '{}'], LlmShutdownWatch::ERR_RATE, LlmShutdownWatch::RETRY_SEC],
            'service en panne'    => [static fn(): array => ['status' => 503, 'body' => ''], LlmShutdownWatch::ERR_SERVER, LlmShutdownWatch::RETRY_SEC],
            'réseau'              => [static fn(): array => throw new \RuntimeException('Could not resolve host'), LlmShutdownWatch::ERR_NETWORK, LlmShutdownWatch::RETRY_SEC],
            'réponse illisible'   => [static fn(): array => ['status' => 200, 'body' => '<html>proxy</html>'], LlmShutdownWatch::ERR_INVALID, LlmShutdownWatch::INTERVAL_SEC],
            'statut inattendu'    => [static fn(): array => ['status' => 404, 'body' => ''], LlmShutdownWatch::ERR_OTHER, LlmShutdownWatch::INTERVAL_SEC],
        ];
    }

    #[DataProvider('pannes')]
    public function testUnePanneNeChangeAucuneDate(callable $transport, string $code, int $delai): void
    {
        $avant = ['gpt-5' => '2026-12-11'];
        $r = LlmShutdownWatch::fetchProvider('openai', 'sk-x', $avant, ['gpt-5'], $transport, self::NOW);
        $this->assertSame($avant, $r['dates']);
        $this->assertSame([], $r['changes']);
        $this->assertSame($code, $r['check']['error']);
        $this->assertSame(self::NOW + $delai, $r['check']['next']);
        $this->assertArrayNotHasKey('ok', $r['check'], 'la date du dernier relevé réussi est gardée par l\'appelant');
        $this->assertNotSame('', LlmShutdownWatch::errorLabel($code));
    }

    public function testEcheance(): void
    {
        $this->assertTrue(LlmShutdownWatch::due([], self::NOW), 'jamais relevé');
        $this->assertTrue(LlmShutdownWatch::due(['next' => 0], self::NOW), 'relevé anticipé (soon)');
        $this->assertFalse(LlmShutdownWatch::due(['next' => self::NOW + 1], self::NOW));
        $this->assertTrue(LlmShutdownWatch::due(['next' => self::NOW], self::NOW));
    }

    // ===================================================== Repli du catalogue

    public function testUneDateReleveeArreteUnModeleQueLeCatalogueNeConnaitPas(): void
    {
        // GPT-6 Luna : au catalogue, sans bloc `retirement`.
        $this->assertNull(LlmCatalog::retirement('openai', 'gpt-6-luna'));
        LlmShutdownWatch::useMemoryStore(['openai' => ['gpt-6-luna' => '2027-03-01']]);

        LlmCatalog::setToday('2027-02-28');
        $arret = LlmCatalog::retirement('openai', 'gpt-6-luna');
        $this->assertNotNull($arret);
        $this->assertSame('2027-03-01', $arret['date']);
        $this->assertSame('api', $arret['source']);
        $this->assertSame('2027-03-01', $arret['reported']);
        $this->assertSame('', $arret['replacement'], 'l\'API ne désigne aucun remplaçant');
        $this->assertSame('', $arret['replacement_label']);
        $this->assertSame('https://developers.openai.com/api/docs/deprecations', $arret['source_url']);
        $this->assertFalse($arret['retired']);
        $this->assertFalse(LLMFactory::isRetired('openai::gpt-6-luna'));

        LlmCatalog::setToday('2027-03-01');
        $this->assertTrue(LlmCatalog::isRetired('openai', 'gpt-6-luna'), 'le jour même, il quitte le chat');
        $this->assertTrue(LLMFactory::isRetired('openai::gpt-6-luna'));
    }

    public function testLeCatalogueRestePrioritaire(): void
    {
        // GPT-4o : bloc `retirement` au 14/01/2027, remplaçant GPT-6 Luna.
        LlmShutdownWatch::useMemoryStore(['openai' => ['gpt-4o' => '2027-02-01']]);
        LlmCatalog::setToday('2027-01-14');
        $arret = LlmCatalog::retirement('openai', 'gpt-4o');
        $this->assertSame('2027-01-14', $arret['date']);
        $this->assertSame('catalog', $arret['source']);
        $this->assertSame('2027-02-01', $arret['reported'], 'la date de l\'éditeur reste connue (écart signalé)');
        $this->assertSame('GPT-6 Luna', $arret['replacement_label']);
        $this->assertTrue($arret['retired']);
    }

    public function testUnModeleHorsCatalogueActiveEstCouvertAussi(): void
    {
        $this->assertNull(LlmCatalog::retirement('openai', 'gpt-5.2-nano-2026-04-01'));
        LlmShutdownWatch::useMemoryStore(['openai' => ['gpt-5.2-nano-2026-04-01' => '2026-12-11']]);
        LlmCatalog::setToday('2026-12-11');
        $this->assertTrue(LlmCatalog::isRetired('openai', 'gpt-5.2-nano-2026-04-01'));
    }

    public function testSeulsLesFournisseursQuiPublientLeursDatesSontLus(): void
    {
        LlmShutdownWatch::useMemoryStore(['anthropic' => ['claude-opus-5' => '2026-12-01']]);
        LlmCatalog::setToday('2027-01-01');
        $this->assertNull(LlmCatalog::retirement('anthropic', 'claude-opus-5'));
        $this->assertNull(LlmShutdownWatch::dateFor('anthropic', 'claude-opus-5'));
        $this->assertSame(['openai'], array_keys(LlmShutdownWatch::PROVIDERS));
    }

    public function testLeDefautSansRemplacantNeSuitRien(): void
    {
        // Un défaut arrêté par l'API seulement n'a pas de remplaçant à suivre :
        // defaultModel() garde l'identifiant (aucune boucle, aucun modèle inventé).
        $defaut = LlmCatalog::defaultModel('openai');
        LlmShutdownWatch::useMemoryStore(['openai' => [$defaut => '2026-11-01']]);
        LlmCatalog::setToday('2026-11-12');
        $this->assertSame($defaut, LlmCatalog::defaultModel('openai'));
    }

    // ===================================================== Points à régler

    public function testUnArretReleveHorsCatalogueADonPointARegler(): void
    {
        LlmShutdownWatch::useMemoryStore(['openai' => ['gpt-6-luna' => '2027-03-01', 'gpt-6.1-sol' => '2026-11-01']]);
        LlmCatalog::setToday('2026-11-12');
        $s = self::parId(AdminAttention::llmRetirementSignals([
            ['provider' => 'openai', 'model' => 'gpt-6-luna', 'display_name' => 'GPT-6 Luna'],
            ['provider' => 'openai', 'model' => 'gpt-6.1-sol', 'display_name' => ''],
        ], [['name' => 'Juriste', 'default_llm' => 'openai::gpt-6-luna']]));

        $this->assertArrayHasKey('llm_arret_releve', $s);
        $this->assertSame('llms-keys', $s['llm_arret_releve']['entry']);
        $this->assertSame(AdminAttention::WARN, $s['llm_arret_releve']['level']);
        $this->assertStringContainsString('GPT-6 Luna (le 01/03/2027)', $s['llm_arret_releve']['text']);
        $this->assertStringContainsString('GPT-6.1 Sol (depuis le 01/11/2026)', $s['llm_arret_releve']['text']);
        $this->assertStringContainsString('absentes du catalogue de MIA', $s['llm_arret_releve']['text']);
        $this->assertStringContainsString('2 arrêts relevés hors catalogue', $s['llm_arret_releve']['short']);
        // Pas listé deux fois : les points d'un arrêt du catalogue ne le reprennent pas.
        $this->assertArrayNotHasKey('llm_arret_annonce', $s);
        $this->assertArrayNotHasKey('llm_modeles_arretes', $s);
        // L'assistant qui impose le modèle est prévenu comme pour un arrêt du catalogue.
        $this->assertArrayHasKey('assistant_modele_arret_annonce', $s);
    }

    public function testUneDateDifferenteDeCelleDuCatalogueEstSignalee(): void
    {
        LlmShutdownWatch::useMemoryStore(['openai' => ['gpt-4o' => '2027-02-01', 'gpt-4.1' => '2027-01-14']]);
        LlmCatalog::setToday('2026-11-12');
        $s = self::parId(AdminAttention::llmRetirementSignals([
            ['provider' => 'openai', 'model' => 'gpt-4o', 'display_name' => 'GPT-4o'],
            ['provider' => 'openai', 'model' => 'gpt-4.1', 'display_name' => 'GPT-4.1'],
        ], []));
        $this->assertArrayHasKey('llm_arret_date_differente', $s);
        $this->assertStringContainsString('GPT-4o (catalogue : 14/01/2027, éditeur : 01/02/2027)', $s['llm_arret_date_differente']['text']);
        $this->assertStringNotContainsString('GPT-4.1', $s['llm_arret_date_differente']['text'], 'même date : rien à dire');
        // Le catalogue fait foi : l'arrêt annoncé reste à SA date.
        $this->assertStringContainsString('GPT-4o (le 14/01/2027, remplaçant : GPT-6 Luna)', $s['llm_arret_annonce']['text']);
        $this->assertArrayNotHasKey('llm_arret_releve', $s);
    }

    public function testSansReleveRienNeChange(): void
    {
        LlmCatalog::setToday('2026-11-12');
        $s = self::parId(AdminAttention::llmRetirementSignals(
            [['provider' => 'openai', 'model' => 'gpt-6-luna', 'display_name' => 'GPT-6 Luna']],
            []
        ));
        $this->assertSame([], $s);
    }

    // ===================================================== Vrai transport

    private static function serveur(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $dir = sys_get_temp_dir() . '/mia-shutdowns-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/router.php', '<?php
$chemin = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($chemin === "/redirige") { header("Location: /v1/models", true, 302); exit; }
header("Content-Type: application/json");
echo json_encode(["object" => "list", "data" => [["id" => "gpt-5", "object" => "model", "shutdown_date" => "2026-12-11"]],
    "methode" => $_SERVER["REQUEST_METHOD"], "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? ""]);
');
        $port = 0;
        for ($essai = 0; $essai < 5 && self::$serveur === null; $essai++) {
            $port = random_int(20000, 39000);
            $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubes);
            if (!is_resource($proc)) {
                continue;
            }
            for ($i = 0; $i < 50; $i++) {
                $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($s) {
                    fclose($s);
                    self::$serveur = $proc;
                    break;
                }
                usleep(50_000);
            }
            if (self::$serveur === null) {
                proc_terminate($proc);
            }
        }
        if (self::$serveur === null) {
            self::markTestSkipped('Impossible de démarrer le serveur local (php -S).');
        }
        register_shutdown_function(static function (): void {
            if (is_resource(self::$serveur)) {
                proc_terminate(self::$serveur);
            }
        });
        return self::$base = 'http://127.0.0.1:' . $port;
    }

    public function testLeVraiTransportFaitUnGetSansSuivreDeRedirection(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('curl absent');
        }
        $base = self::serveur();
        $r = LlmShutdownWatch::httpGet($base . '/v1/models', ['Authorization: Bearer sk-local', 'Accept: application/json']);
        $this->assertSame(200, $r['status']);
        $corps = json_decode($r['body'], true);
        $this->assertSame('GET', $corps['methode']);
        $this->assertSame('Bearer sk-local', $corps['auth']);
        $this->assertSame(['gpt-5' => '2026-12-11'], LlmShutdownWatch::parseList($corps));

        // Une redirection n'est JAMAIS suivie : la clé partirait avec.
        $r = LlmShutdownWatch::httpGet($base . '/redirige', ['Authorization: Bearer sk-local']);
        $this->assertSame(302, $r['status']);
        $this->assertSame(LlmShutdownWatch::ERR_OTHER, LlmShutdownWatch::errorCode(302));
    }

    // ===================================================== Câblage

    public function testLeReleveEstEnfileParLEntretienJamaisDansUneRequete(): void
    {
        $worker = (string)file_get_contents(self::root() . '/scripts/worker.php');
        $bloc = substr($worker, (int)strpos($worker, "JobQueue::exclusively('mia_worker_maintenance'"));
        $bloc = substr($bloc, 0, (int)strpos($bloc, '});'));
        $this->assertStringContainsString('LlmShutdownWatch::tick();', $bloc, 'bloc d\'entretien du worker, sous son verrou');

        $file = (string)file_get_contents(self::root() . '/src/Jobs/JobQueue.php');
        $maintien = substr($file, (int)strpos($file, 'public static function maybeMaintain'));
        $maintien = substr($maintien, 0, (int)strpos($maintien, "\n    }\n"));
        $this->assertStringContainsString('LlmShutdownWatch::tick();', $maintien, 'sans worker : enfilé, parti par le fork');

        $job = (string)file_get_contents(self::root() . '/src/Jobs/MaintenanceJob.php');
        $this->assertStringContainsString('case LlmShutdownWatch::JOB_OP:', $job);
        $this->assertStringContainsString('LlmShutdownWatch::refresh()', $job);

        // tick() n'appelle jamais le réseau : il enfile, sans exécution en ligne.
        $src = (string)file_get_contents(self::root() . '/src/LLM/LlmShutdownWatch.php');
        $tick = substr($src, (int)strpos($src, 'public static function tick'));
        $tick = substr($tick, 0, (int)strpos($tick, "\n    }\n"));
        $this->assertStringContainsString("JobQueue::enqueueMaintenance(self::JOB_OP, 1, 'Dates d’arrêt des modèles', false)", $tick);
        $this->assertStringNotContainsString('httpGet', $tick);
        $this->assertStringNotContainsString('self::refresh(', $tick);
        $this->assertStringNotContainsString('fetchProvider(', $tick);
        // Le relevé tient un verrou MySQL.
        $this->assertStringContainsString("'mia_llm_shutdowns'", $src);
        $this->assertStringContainsString('GET_LOCK', $src);
        $this->assertStringContainsString('RELEASE_LOCK', $src);
        // Lecture seule : jamais une écriture chez l'éditeur, jamais facturé.
        $this->assertStringContainsString('CURLOPT_HTTPGET', $src);
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $src);
        $this->assertStringNotContainsString('UsageMeter', $src);
    }

    public function testLAdresseEstAuCatalogueEtLaClasseChargeePartout(): void
    {
        $this->assertSame('https://api.openai.com/v1/models', LlmCatalog::endpoint('openai', 'models'));
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('/src/LLM/LlmShutdownWatch.php', (string)file_get_contents(self::root() . '/' . $f), $f);
        }
        $boot = (string)file_get_contents(self::root() . '/tests/bootstrap.php');
        $this->assertStringContainsString('LlmShutdownWatch::useMemoryStore();', $boot);
    }

    public function testUneNouvelleCleDemandeUnReleveSansAttendre(): void
    {
        $ctrl = (string)file_get_contents(self::root() . '/src/Controllers/AdminController.php');
        $save = substr($ctrl, (int)strpos($ctrl, 'public function saveApiKey'));
        $save = substr($save, 0, (int)strpos($save, "\n    }\n"));
        $this->assertStringContainsString('LlmShutdownWatch::soon($provider);', $save);

        LlmShutdownWatch::useMemoryStore([], ['openai' => ['next' => self::NOW + 9999, 'ok' => self::NOW, 'at' => self::NOW]]);
        $this->assertSame(self::NOW + 9999, LlmShutdownWatch::nextCheck('openai'));
        // Pas encore dû : tick() ne touche pas à la base (l'échéance se lit d'abord).
        $this->assertFalse(LlmShutdownWatch::tick(self::NOW));
        LlmShutdownWatch::soon('openai');
        LlmShutdownWatch::soon('anthropic'); // sans objet : rien n'est écrit
        $this->assertSame(0, LlmShutdownWatch::nextCheck('openai'));
        $this->assertSame(0, LlmShutdownWatch::nextCheck('anthropic'));
        $this->assertSame(self::NOW, LlmShutdownWatch::status('openai')['ok'], 'le dernier relevé réussi reste affiché');
    }

    public function testLaSauvegardeNEmporteNiLesDatesNiLEtat(): void
    {
        $this->assertContains(LlmShutdownWatch::SETTING, BackupService::EXCLUDED_SETTINGS_KEYS);
        $this->assertContains(LlmShutdownWatch::SETTING_CHECK, BackupService::EXCLUDED_SETTINGS_KEYS);
    }

    public function testLaPageDitDOuVientLaDateEtQuandElleAEteRelevee(): void
    {
        $page = (string)file_get_contents(self::root() . '/templates/admin/llms/index.php');
        $this->assertStringContainsString("(\$arret['source'] ?? '') === 'api'", $page);
        $this->assertStringContainsString('LlmShutdownWatch::status($p)', $page);
        $this->assertStringContainsString('LlmShutdownWatch::errorLabel(', $page);
    }

    public function testLaDocumentationLeDit(): void
    {
        foreach (['docs/GUIDE-ADMIN.md', 'docs/user/admin/modeles/activer-les-modeles.md', 'CLAUDE.md'] as $f) {
            $this->assertStringContainsString('relev', (string)file_get_contents(self::root() . '/' . $f), $f);
        }
        $this->assertStringContainsString('LlmShutdownWatch', (string)file_get_contents(self::root() . '/CLAUDE.md'));
        $this->assertStringContainsString('id: openai-dates-arret-relevees', (string)file_get_contents(self::root() . '/docs/NOUVEAUTES.md'));
    }
}
