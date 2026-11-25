<?php

declare(strict_types=1);

/**
 * Dates d'arrêt relevées chez l'éditeur, de bout en bout sur la base de test :
 * le relevé (transport factice, rien ne part sur le réseau) écrit dans
 * `settings` les dates des modèles qui comptent pour l'instance ; le modèle
 * activé quitte le chat à SA date sans que le catalogue ait été touché, le
 * modèle de référence de la clé n'est plus servi, le point à régler le dit ;
 * une panne ne change rien ; l'entretien n'enfile qu'un job à la fois.
 */
final class LlmShutdownWatchPipelineTest extends DatabaseTestCase
{
    private const NOW = 1_794_470_400; // 2026-11-12 08:00 UTC
    private const HORS_CATALOGUE = 'gpt-5.2-nano-2026-04-01';

    /** @var array<string,mixed> */
    private array $reglagesAvant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        foreach (['default_llm', 'job_worker_heartbeat_at', LlmShutdownWatch::SETTING, LlmShutdownWatch::SETTING_CHECK] as $cle) {
            $this->reglagesAvant[$cle] = Settings::get($cle);
        }
        Settings::set(LlmShutdownWatch::SETTING, '');
        Settings::set(LlmShutdownWatch::SETTING_CHECK, '');
        LlmShutdownWatch::useSettingsStore();
        // Un worker « vivant » : un job enfilé reste en file, jamais lancé par fork.
        JobQueue::markWorkerAlive();
        AdminAttention::forget();
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        LlmShutdownWatch::useMemoryStore();
        LlmCatalog::setToday(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            foreach ($this->reglagesAvant as $cle => $valeur) {
                Settings::set($cle, (string)($valeur ?? ''));
            }
        }
        AdminAttention::forget();
        parent::tearDown();
    }

    private function activer(string $model, string $label): void
    {
        LlmModel::save(['provider' => 'openai', 'model' => $model, 'display_name' => $label, 'is_active' => 1]);
    }

    /** Transport factice : la réponse de GET /v1/models, ou une panne. */
    private static function liste(array $modeles, int $statut = 200, array &$appels = []): callable
    {
        return static function (string $url, array $headers) use ($modeles, $statut, &$appels): array {
            $appels[] = [$url, $headers];
            $data = [];
            foreach ($modeles as $id => $date) {
                $data[] = ['id' => $id, 'object' => 'model', 'created' => 1686935002, 'owned_by' => 'openai', 'shutdown_date' => $date];
            }
            return ['status' => $statut, 'body' => $statut === 200 ? (string)json_encode(['object' => 'list', 'data' => $data]) : '{"error":{}}'];
        };
    }

    public function testUnModeleActiveQuitteLeChatALaDateReleveeSansToucherAuCatalogue(): void
    {
        ApiKey::save('openai', 'sk-test-factice', 'gpt-6-luna');
        $this->activer('gpt-6-luna', 'GPT-6 Luna');
        $this->activer('gpt-6.1-sol', 'GPT-6.1 Sol');
        $this->activer(self::HORS_CATALOGUE, 'Nano daté');

        $appels = [];
        $r = LlmShutdownWatch::refresh(self::liste([
            'gpt-6-luna'          => '2027-03-01',
            'gpt-6.1-sol'         => null,
            self::HORS_CATALOGUE  => '2026-12-11',
            'whisper-1'           => '2027-02-26', // hors des modèles qui comptent
        ], 200, $appels), self::NOW);

        $this->assertTrue($r['openai']['ok']);
        $this->assertCount(1, $appels);
        $this->assertContains('Authorization: Bearer sk-test-factice', $appels[0][1], 'la clé de l\'instance, déchiffrée');
        Settings::refresh();
        $this->assertSame(
            ['openai' => ['gpt-5.2-nano-2026-04-01' => '2026-12-11', 'gpt-6-luna' => '2027-03-01']],
            json_decode((string)Settings::get(LlmShutdownWatch::SETTING), true)
        );
        $etat = LlmShutdownWatch::status('openai');
        $this->assertSame(self::NOW, $etat['ok']);
        $this->assertSame('', $etat['error']);
        $this->assertSame(self::NOW + LlmShutdownWatch::INTERVAL_SEC, LlmShutdownWatch::nextCheck('openai'));

        // La veille : proposé, et annoncé par un point à régler dédié.
        LlmCatalog::setToday('2027-02-28');
        $this->assertArrayHasKey('openai::gpt-6-luna', LLMFactory::available());
        $this->assertSame('gpt-6-luna', ApiKey::getModel('openai'));
        $constats = array_column(AdminAttention::current(), null, 'id');
        $this->assertArrayHasKey('llm_arret_releve', $constats);
        $this->assertStringContainsString('GPT-6 Luna (le 01/03/2027)', $constats['llm_arret_releve']['text']);
        $this->assertStringContainsString('Nano daté (depuis le 11/12/2026)', $constats['llm_arret_releve']['text']);
        $this->assertArrayNotHasKey('openai::' . self::HORS_CATALOGUE, LLMFactory::available(), 'déjà arrêté');

        // Le jour même : retiré du chat, le modèle de la clé n'est plus servi.
        LlmCatalog::setToday('2027-03-01');
        AdminAttention::forget();
        $dispo = LLMFactory::available();
        $this->assertArrayNotHasKey('openai::gpt-6-luna', $dispo);
        $this->assertArrayHasKey('openai::gpt-6.1-sol', $dispo);
        $this->assertNull(ApiKey::getModel('openai'));
        // Rien n'est écrit dans llm_models : remettre la date en arrière le rend.
        $this->assertSame(1, (int)$this->db()->query("SELECT is_active FROM llm_models WHERE model = 'gpt-6-luna'")->fetchColumn());
    }

    public function testUnePanneNeChangeRienEtGardeLeDernierReleveReussi(): void
    {
        ApiKey::save('openai', 'sk-test-factice');
        $this->activer('gpt-6-luna', 'GPT-6 Luna');
        LlmShutdownWatch::refresh(self::liste(['gpt-6-luna' => '2027-03-01']), self::NOW);

        $r = LlmShutdownWatch::refresh(self::liste([], 403), self::NOW + 86400);
        $this->assertFalse($r['openai']['ok']);
        $this->assertSame(LlmShutdownWatch::ERR_PERMISSION, $r['openai']['error']);
        Settings::refresh();
        $this->assertSame('2027-03-01', LlmShutdownWatch::dateFor('openai', 'gpt-6-luna'));
        $etat = LlmShutdownWatch::status('openai');
        $this->assertSame(LlmShutdownWatch::ERR_PERMISSION, $etat['error']);
        $this->assertSame(self::NOW, $etat['ok'], 'dernier relevé réussi gardé');
        $this->assertSame(1, $etat['dated']);
        $this->assertSame(self::NOW + 2 * 86400, LlmShutdownWatch::nextCheck('openai'), 'clé sans le droit : nouvel essai demain');

        $r = LlmShutdownWatch::refresh(self::liste([], 503), self::NOW + 2 * 86400);
        $this->assertSame(self::NOW + 2 * 86400 + LlmShutdownWatch::RETRY_SEC, LlmShutdownWatch::nextCheck('openai'), 'panne passagère : dans une heure');
    }

    public function testUnModeleCoupeQuiDisparaitDeLaListeGardeSaDate(): void
    {
        ApiKey::save('openai', 'sk-test-factice');
        $this->activer('gpt-6-luna', 'GPT-6 Luna');
        LlmShutdownWatch::refresh(self::liste(['gpt-6-luna' => '2026-12-01']), self::NOW);
        // Coupé : OpenAI ne le liste plus. L'oublier le ferait revenir dans le chat.
        LlmShutdownWatch::refresh(self::liste(['gpt-6.1-sol' => null]), self::NOW + 30 * 86400);
        Settings::refresh();
        $this->assertSame('2026-12-01', LlmShutdownWatch::dateFor('openai', 'gpt-6-luna'));
        LlmCatalog::setToday('2026-12-12');
        $this->assertArrayNotHasKey('openai::gpt-6-luna', LLMFactory::available());

        // Date annulée par l'éditeur (listé, sans date) : retirée.
        LlmShutdownWatch::refresh(self::liste(['gpt-6-luna' => null]), self::NOW + 31 * 86400);
        Settings::refresh();
        $this->assertNull(LlmShutdownWatch::dateFor('openai', 'gpt-6-luna'));
        $this->assertArrayHasKey('openai::gpt-6-luna', LLMFactory::available());
    }

    public function testSansCleRienNEstEnfileNiAppele(): void
    {
        $this->assertFalse(LlmShutdownWatch::tick(self::NOW));
        $appels = [];
        $r = LlmShutdownWatch::refresh(self::liste([], 200, $appels), self::NOW);
        $this->assertSame([], $appels);
        $this->assertSame('cle', $r['openai']['skipped']);
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM async_jobs')->fetchColumn());
    }

    public function testLEntretienNEnfileQuUnJobALaFois(): void
    {
        ApiKey::save('openai', 'sk-test-factice');
        $this->assertTrue(LlmShutdownWatch::tick(self::NOW));
        $jobs = $this->db()->query("SELECT type, payload_json, user_id, conversation_id FROM async_jobs")->fetchAll();
        $this->assertCount(1, $jobs);
        $this->assertSame('maintenance', $jobs[0]['type']);
        $this->assertSame(['op' => 'llm_shutdowns', 'id' => 1], json_decode((string)$jobs[0]['payload_json'], true));
        $this->assertNull($jobs[0]['user_id'], 'tâche système : ni personne ni quota');

        // La minute suivante : pas encore dû (nouvel essai dans une heure au plus tôt).
        $this->assertFalse(LlmShutdownWatch::tick(self::NOW + 60));
        // Clé changée : dû tout de suite, mais le job en file est rejoint.
        LlmShutdownWatch::soon('openai');
        $this->assertTrue(LlmShutdownWatch::tick(self::NOW + 120));
        $this->assertSame(1, (int)$this->db()->query('SELECT COUNT(*) FROM async_jobs')->fetchColumn());
    }

    public function testLeJobDeMaintenanceFaitLeReleve(): void
    {
        ApiKey::save('openai', 'sk-test-factice');
        $this->activer('gpt-6-luna', 'GPT-6 Luna');
        // Le job appelle refresh() sans transport : on le rejoue ici avec le
        // transport factice pour vérifier la forme de son résultat.
        $resultat = ['op' => 'llm_shutdowns', 'id' => 1] + LlmShutdownWatch::refresh(self::liste(['gpt-6-luna' => '2027-03-01']), self::NOW);
        $this->assertTrue($resultat['openai']['ok']);
        $this->assertSame(['gpt-6-luna : 2027-03-01'], $resultat['openai']['changes']);
        $this->assertNotFalse(json_encode($resultat), 'résultat de job encodable');
    }

    public function testUnSecondReleveEnCoursRepartAussitot(): void
    {
        ApiKey::save('openai', 'sk-test-factice');
        // Une autre connexion tient le verrou : le relevé ne fait rien.
        $autre = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $this->assertSame(1, (int)$autre->query("SELECT GET_LOCK('mia_llm_shutdowns', 0)")->fetchColumn());
        try {
            $appels = [];
            $r = LlmShutdownWatch::refresh(self::liste([], 200, $appels), self::NOW);
            $this->assertSame(['skipped' => 'verrou'], $r);
            $this->assertSame([], $appels);
        } finally {
            $autre->query("SELECT RELEASE_LOCK('mia_llm_shutdowns')");
        }
    }

    public function testLaPageDitQuandLesDatesOntEteRelevees(): void
    {
        ApiKey::save('openai', 'sk-test-factice');
        LlmShutdownWatch::refresh(self::liste([]), self::NOW);
        $etat = LlmShutdownWatch::status('openai');
        $this->assertNotNull($etat);
        $this->assertSame('', $etat['error']);
        $this->assertNull(LlmShutdownWatch::status('anthropic'));
    }
}
