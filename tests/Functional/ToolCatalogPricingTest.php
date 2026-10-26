<?php

declare(strict_types=1);

/**
 * Outils image et vidéo : une ligne de tarif laissée à 0 reprend le tarif
 * public du catalogue des outils, comme les modèles de langage, les
 * embeddings, la recherche web et la dictée.
 *
 * Avant : l'écran Crédits sème une ligne à 0 par outil, et CreditService la
 * prenait telle quelle — toute image, toute vidéo devenait « non tarifée » et
 * coûtait le minimum de crédits, alors que les autres services retombaient
 * sur leur tarif curé.
 */
final class ToolCatalogPricingTest extends DatabaseTestCase
{
    private const FX = 1.08;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('credits_config_json', json_encode([
            'version' => 1,
            'global'  => [
                'credit_value_eur'          => 0.001,
                'rounding_mode'             => 'ceil',
                'margin_multiplier'         => 1.0,
                'fixed_fee_eur'             => 0.0,
                'min_credits_per_operation' => 1,
                'fx_rate_eur_usd'           => self::FX,
            ],
            'services' => [
                // Lignes semées par l'écran Crédits, jamais renseignées.
                ['id' => 't1', 'name' => 'Nano Banana', 'provider' => 'Gemini', 'type' => 'image', 'unit' => 'image_generated', 'real_cost_eur' => 0.0, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'tool:1'],
                ['id' => 't2', 'name' => 'Clips',       'provider' => 'Openai', 'type' => 'video', 'unit' => 'video_second',    'real_cost_eur' => 0.0, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'tool:2'],
                ['id' => 't4', 'name' => 'GPT Image',   'provider' => 'Openai', 'type' => 'image', 'unit' => 'image_generated', 'real_cost_eur' => 0.0, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'tool:4'],
                // Ligne renseignée par l'administrateur : elle prime toujours.
                ['id' => 't3', 'name' => 'Maison',      'provider' => 'Gemini', 'type' => 'image', 'unit' => 'image_generated', 'real_cost_eur' => 0.05, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'tool:3'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            Settings::set('credits_config_json', '');
        }
        parent::tearDown();
    }

    public function testUneImageParImageReprendLeTarifDuCatalogue(): void
    {
        $r = CreditService::computeToolCredits(1, 'image', ['model' => 'gemini-3.1-flash-image', 'count' => 1]);
        $this->assertTrue($r['priced'], 'Une ligne à 0 ne doit plus rendre l\'image non tarifée.');

        $usd = $this->tarif('image', 'gemini-3.1-flash-image')['per_image_usd'];
        $this->assertEqualsWithDelta($usd / self::FX, $r['cost_eur'], 0.000001);
        $this->assertSame((int)ceil(round($usd / self::FX / 0.001, 6)), $r['total_credits']);
        $this->assertStringStartsWith('catalog:tool:', $r['catalog_key']);
    }

    public function testUneImageAuxTokensSeChiffreDepuisLUsageRenvoye(): void
    {
        $usage = ['text_input' => 60, 'image_input' => 0, 'image_output' => 1500];
        $r = CreditService::computeToolCredits(4, 'image', ['model' => 'gpt-image-2', 'count' => 1, 'usage' => $usage]);
        $this->assertTrue($r['priced'], 'Une ligne à 0 ne doit plus rendre l\'image non tarifée.');

        $prix = $this->tarif('image', 'gpt-image-2')['tokens_usd_per_1m'];
        $usd  = (60 * $prix['text_input'] + 1500 * $prix['image_output']) / 1_000_000;
        // cost_eur est arrondi à 6 décimales (CreditService::settle).
        $this->assertEqualsWithDelta($usd / self::FX, $r['cost_eur'], 0.000001);
    }

    public function testSansUsageUneImageAuxTokensResteNonTarifee(): void
    {
        $r = CreditService::computeToolCredits(4, 'image', ['model' => 'gpt-image-2', 'count' => 1]);
        $this->assertFalse($r['priced'], 'Inventer un coût serait pire que le signaler.');
    }

    public function testUneVideoSeChiffreALaSecondeSelonSaTaille(): void
    {
        $r = CreditService::computeToolCredits(2, 'video', ['model' => 'sora-2', 'seconds' => 8, 'size' => '720x1280']);
        $this->assertTrue($r['priced'], 'Une ligne à 0 ne doit plus rendre la vidéo non tarifée.');

        $usd = $this->tarif('video', 'sora-2')['per_second_usd']['720x1280'];
        $this->assertEqualsWithDelta(8 * $usd / self::FX, $r['cost_eur'], 0.000001);
        $this->assertSame(8.0, $r['quantity_units']);

        // Taille sans tarif public connu pour ce modèle : signalée, pas devinée.
        $hd = CreditService::computeToolCredits(2, 'video', ['model' => 'sora-2', 'seconds' => 8, 'size' => '1792x1024']);
        $this->assertFalse($hd['priced']);
    }

    public function testUneLigneRenseigneePrimeSurLeCatalogue(): void
    {
        $r = CreditService::computeToolCredits(3, 'image', ['model' => 'gemini-3.1-flash-image', 'count' => 1]);
        $this->assertTrue($r['priced']);
        $this->assertEqualsWithDelta(0.05, $r['cost_eur'], 0.000001);
    }

    public function testUnModeleSansTarifPublicResteNonTarife(): void
    {
        // DALL-E 3 : coupé par OpenAI, plus aucun tarif public.
        $r = CreditService::computeToolCredits(1, 'image', ['model' => 'dall-e-3', 'count' => 1]);
        $this->assertFalse($r['priced']);
        $this->assertSame(1, $r['total_credits']);
    }

    /** @return array<string, mixed> */
    private function tarif(string $type, string $model): array
    {
        foreach (ToolCatalog::providers($type) as $provider => $entry) {
            if (isset($entry['models'][$model]['pricing'])) {
                return $entry['models'][$model]['pricing'];
            }
        }
        $this->fail("Aucun tarif au catalogue pour $type/$model.");
    }
}
