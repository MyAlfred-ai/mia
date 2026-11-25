<?php

declare(strict_types=1);

/**
 * « Mettre à jour coûts Internet » sur une instance qui a beaucoup de modèles.
 *
 * Panne vécue en recette : la configuration de Tarifs & crédits range une
 * ligne par service — cinq par modèle activé — avec la source de chaque prix.
 * Passé une vingtaine de modèles, elle dépassait les 64 Ko d'un TEXT : MySQL
 * refusait l'écriture (1406 Data too long, mode strict) et l'écran affichait
 * « La génération n'a pas abouti. Réessayez. », sans rien enregistrer.
 */
final class CreditsPriceSavePipelineTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Controllers/AdminController.php';
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        $this->db()->exec("DELETE FROM settings WHERE `key` IN ('credits_config_json', 'test_grand_reglage')");
        Settings::refresh();
    }

    protected function tearDown(): void
    {
        AdminController::overridePricingTransport(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            $this->db()->exec("DELETE FROM settings WHERE `key` IN ('credits_config_json', 'test_grand_reglage')");
            Settings::refresh();
        }
        parent::tearDown();
    }

    public function testUnReglageDePlusDe64KoEstEnregistreEntier(): void
    {
        $valeur = str_repeat('é', 120000); // 240 000 octets
        Settings::set('test_grand_reglage', $valeur);
        Settings::refresh();

        $this->assertSame($valeur, Settings::get('test_grand_reglage', ''));
    }

    public function testLesPrixDeTrenteCinqModelesSontEnregistres(): void
    {
        ApiKey::save('openai', 'sk-test', 'gpt-5.6');
        $n = 0;
        foreach (['openai', 'anthropic', 'gemini', 'mistral'] as $provider) {
            ApiKey::save($provider, 'cle-test-' . $provider, (string)LlmCatalog::defaultModel($provider));
            foreach (array_keys((array)(LlmCatalog::all()[$provider]['models'] ?? [])) as $model) {
                if ($n >= 35 || LlmCatalog::isRetired($provider, $model)) {
                    continue;
                }
                LlmModel::save(['provider' => $provider, 'model' => $model, 'display_name' => $model, 'is_active' => 1, 'sort_order' => $n++]);
            }
        }

        $ctrl = new AdminController();
        $rows = (new ReflectionMethod($ctrl, 'buildCatalogServiceRows'))->invoke($ctrl, LlmModel::active(), []);
        $services = array_map(static fn(array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'provider' => $r['provider'],
            'type' => $r['type'], 'unit' => $r['unit'], 'catalog_key' => $r['catalog_key'],
        ], $rows);

        // Ce que le catalogue ne tarife pas part en ligne : faux OpenAI.
        AdminController::overridePricingTransport(static function (string $url, string $payload): array {
            preg_match_all('/"id":"([^"]+)"/', (string)(json_decode($payload, true)['input'] ?? ''), $m);
            $updates = array_map(static fn(string $id): array => [
                'id' => $id, 'real_cost_usd' => 0.02, 'source_url' => 'https://exemple.test/tarifs', 'source_note' => 'Page officielle',
            ], $m[1]);
            $texte = json_encode(['updates' => $updates, 'not_found' => []]);
            return [200, (string)json_encode(['status' => 'completed', 'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $texte]]],
            ]]), null];
        });

        $resultat = $ctrl->runCreditsPriceRefresh($services, static fn(): bool => true, null);

        $this->assertTrue($resultat['ok']);
        $this->assertGreaterThan(150, $resultat['applied']);
        Settings::refresh();
        $brut = (string)Settings::get('credits_config_json', '');
        $this->assertGreaterThan(65535, strlen($brut), 'Le cas qui dépassait la colonne TEXT.');
        $config = json_decode($brut, true);
        $this->assertIsArray($config, 'Configuration enregistrée entière, pas tronquée.');
        $this->assertCount(count($services), $config['services']);
    }
}
