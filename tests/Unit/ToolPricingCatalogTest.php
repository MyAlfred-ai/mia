<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tarifs publics des outils image et vidéo (config/tools_catalog.php) et
 * lecture de la consommation renvoyée par l'API images d'OpenAI.
 *
 * Un tarif sans source se périme en silence : chaque bloc `pricing` porte
 * son URL officielle et la date de sa vérification, comme ceux du catalogue
 * LLM.
 */
final class ToolPricingCatalogTest extends TestCase
{
    public function testChaqueTarifEstBienFormeEtSource(): void
    {
        $vus = 0;
        foreach (['image', 'video'] as $type) {
            foreach (ToolCatalog::providers($type) as $provider => $entry) {
                foreach ((array)($entry['models'] ?? []) as $model => $info) {
                    if (!isset($info['pricing'])) {
                        continue;
                    }
                    $vus++;
                    $p = $info['pricing'];
                    $id = "$type/$provider/$model";
                    $this->assertMatchesRegularExpression('#^https://#', (string)($p['source_url'] ?? ''), "$id : source officielle attendue");
                    $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', (string)($p['note'] ?? ''), "$id : date de vérification attendue dans la note");

                    $modes = array_intersect(['per_image_usd', 'per_second_usd', 'tokens_usd_per_1m'], array_keys($p));
                    $this->assertCount(1, $modes, "$id : un seul mode de tarif");

                    if (isset($p['per_image_usd'])) {
                        $this->assertGreaterThan(0, (float)$p['per_image_usd'], $id);
                    }
                    if (isset($p['per_second_usd'])) {
                        $this->assertIsArray($p['per_second_usd'], "$id : tarif à la seconde par taille");
                        $tailles = array_keys((array)($entry['size_options'] ?? []));
                        foreach ($p['per_second_usd'] as $taille => $usd) {
                            $this->assertContains((string)$taille, $tailles, "$id : taille « $taille » inconnue du catalogue");
                            $this->assertGreaterThan(0, (float)$usd, $id);
                        }
                    }
                    if (isset($p['tokens_usd_per_1m'])) {
                        $this->assertSame([], array_diff(array_keys($p['tokens_usd_per_1m']), ['text_input', 'image_input', 'image_output']), $id);
                        $this->assertGreaterThan(0, (float)($p['tokens_usd_per_1m']['image_output'] ?? 0), $id);
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $vus, 'Aucun tarif d\'outil au catalogue.');
    }

    public function testLUsageDeLApiImagesEstLuAvecSesDetails(): void
    {
        require_once ROOT . '/src/Helpers/ImageGenerator.php';

        $this->assertSame(
            ['text_input' => 12, 'image_input' => 40, 'image_output' => 1056],
            ImageGenerator::usageFromResponse(['usage' => [
                'input_tokens' => 52, 'output_tokens' => 1056,
                'input_tokens_details'  => ['text_tokens' => 12, 'image_tokens' => 40],
                'output_tokens_details' => ['image_tokens' => 1056, 'text_tokens' => 0],
            ]])
        );
        // Sans détail : l'entrée est du texte, la sortie de l'image.
        $this->assertSame(
            ['text_input' => 30, 'image_input' => 0, 'image_output' => 272],
            ImageGenerator::usageFromResponse(['usage' => ['input_tokens' => 30, 'output_tokens' => 272]])
        );
        $this->assertNull(ImageGenerator::usageFromResponse(['data' => []]));
    }

    public function testLesDeuxCheminsDeFacturationTransmettentLUsage(): void
    {
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertMatchesRegularExpression("/'usage'\s*=>\s*\\\$result\['usage'\] \?\? null/", $chat);

        $illustrateur = (string)file_get_contents(ROOT . '/src/Helpers/HtmlIllustrator.php');
        $this->assertMatchesRegularExpression("/'usage'\s*=>\s*\\\$result\['usage'\] \?\? null/", $illustrateur);
    }
}
