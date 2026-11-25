<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Options d'une image — fond transparent, format du fichier, orientation
 * (ImageOptions) : ce que chaque modèle accepte (catalogue), comment l'outil,
 * l'assistant et la brique de workflow se combinent, et le câblage des trois
 * écrans. Les requêtes réellement émises sont vérifiées par
 * ImageGeneratorSourcesTest (faux fournisseur).
 */
final class ImageOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        ToolCatalog::flushCache();
    }

    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    public function testChaqueModeleNeProposeQueCeQueSonApiAccepte(): void
    {
        $flare = ImageOptions::capabilities('openai', 'gpt-image-2.5-flare');
        $this->assertTrue($flare['transparent']);
        $this->assertFalse($flare['transparent_preview']);
        $this->assertSame(['png', 'jpeg', 'webp'], $flare['output_formats']);
        $this->assertSame(['square', 'landscape', 'portrait'], $flare['formats']);

        $this->assertTrue(ImageOptions::capabilities('openai', 'gpt-image-2')['transparent_preview'], 'gpt-image-2 : transparence en aperçu chez OpenAI.');
        $this->assertTrue(ImageOptions::capabilities('openai', 'gpt-5.4')['transparent'], 'Outil image_generation de la Responses API.');

        $dalle = ImageOptions::capabilities('openai', 'dall-e-3');
        $this->assertFalse($dalle['transparent']);
        $this->assertSame([], $dalle['output_formats']);

        $nano = ImageOptions::capabilities('gemini', 'gemini-3.1-flash-image');
        $this->assertFalse($nano['transparent'], 'Gemini ne rend pas de fond transparent.');
        $this->assertSame([], $nano['output_formats']);
        $this->assertSame(['square', 'landscape', 'portrait'], $nano['formats'], 'Orientation par imageConfig.aspectRatio.');
        $this->assertTrue(ImageOptions::hasAny($nano));
        $this->assertFalse(ImageOptions::hasAny(ImageOptions::capabilitiesOf(['type' => 'html', 'provider' => 'openai', 'model' => 'gpt-image-2'])));

        foreach (ImageOptions::catalogCapabilities() as $provider => $models) {
            foreach ($models as $model => $caps) {
                $this->assertSame([], array_diff($caps['output_formats'], ImageOptions::OUTPUT_FORMATS), "$provider/$model : format inconnu.");
                if ($caps['transparent']) {
                    $this->assertNotSame([], array_intersect($caps['output_formats'], ImageOptions::TRANSPARENT_FORMATS), "$provider/$model : transparence sans format qui la garde.");
                }
            }
        }
    }

    public function testFondTransparentEnclenchePngEtJpegNeLeGardePas(): void
    {
        $this->assertSame(['background' => 'transparent', 'output_format' => 'png', 'format' => ''], ImageOptions::normalize(['background' => 'transparent']));
        $this->assertSame('png', ImageOptions::normalize(['background' => 'transparent', 'output_format' => 'jpeg'])['output_format']);
        $this->assertSame('webp', ImageOptions::normalize(['background' => 'transparent', 'output_format' => 'webp'])['output_format']);
        $this->assertSame('jpeg', ImageOptions::normalize(['output_format' => 'JPG'])['output_format']);
        $this->assertSame(['background' => '', 'output_format' => '', 'format' => ''], ImageOptions::normalize(['background' => 'vert', 'output_format' => 'gif', 'format' => 'panorama']));

        $nano = ImageOptions::capabilities('gemini', 'gemini-3.1-flash-image');
        $this->assertSame(['background' => '', 'output_format' => '', 'format' => 'portrait'],
            ImageOptions::normalize(['background' => 'transparent', 'output_format' => 'webp', 'format' => 'portrait'], $nano),
            'Ce que le modèle refuse est écarté, jamais envoyé.');
    }

    public function testLeNiveauLePlusPrecisGagneEtLeVideLaisseDecider(): void
    {
        $outil = ['background' => 'transparent', 'output_format' => 'webp', 'format' => 'square'];
        $assistant = ['background' => '', 'output_format' => '', 'format' => 'landscape'];
        $this->assertSame(['background' => 'transparent', 'output_format' => 'webp', 'format' => 'landscape'], ImageOptions::merge($outil, $assistant));
        $this->assertSame('png', ImageOptions::merge($outil, ['output_format' => 'jpeg'])['output_format'], 'Fond transparent hérité : JPEG impossible, PNG.');

        $tool = ['provider' => 'openai', 'model' => 'gpt-image-2', 'config_json' => json_encode(['quality' => 'high', 'format' => 'square'])];
        $this->assertSame($tool, ImageOptions::applyTo($tool, ['format' => '']), 'Rien à poser : l’outil tel quel.');
        $applied = json_decode(ImageOptions::applyTo($tool, ['format' => 'portrait', 'background' => 'transparent'])['config_json'], true);
        $this->assertSame(['quality' => 'high', 'format' => 'portrait', 'background' => 'transparent', 'output_format' => 'png'], $applied);
    }

    public function testResolveTraduitLOrientationEtFiltreSelonLeModele(): void
    {
        $this->assertSame(['size' => '1536x1024'], ImageOptions::resolve('openai', 'gpt-image-2.5-flare', ['format' => 'landscape']));
        $this->assertSame(['size' => '3840x2160'], ImageOptions::resolve('openai', 'gpt-image-2', ['size' => '3840x2160', 'format' => 'landscape']), 'Taille déjà en paysage : gardée.');
        $this->assertSame(['size' => '1024x1024'], ImageOptions::resolve('openai', 'gpt-image-2', ['size' => '3840x2160', 'format' => 'square']));
        $this->assertSame(['aspect_ratio' => '9:16'], ImageOptions::resolve('gemini', 'gemini-3-pro-image', ['format' => 'portrait', 'background' => 'transparent']));
        $this->assertSame(['size' => '1024x1024'], ImageOptions::resolve('openai', 'dall-e-3', ['size' => '1024x1024', 'output_format' => 'png']), 'DALL-E : ni fond ni format.');
        $this->assertSame(['background' => 'transparent'], ImageGenerator::outputFields(['background' => 'transparent', 'output_format' => 'gif']));
        $this->assertSame('jpg', ImageOptions::extension('jpeg'));
        $this->assertSame('png', ImageOptions::extension(''));
        $this->assertSame(['responseModalities' => ['TEXT', 'IMAGE']], ImageGenerator::geminiGenerationConfig('gemini-3.1-flash-image', '7:3'), 'Un rapport inconnu du modèle : pas de bloc, l’API le refuserait.');

        $carre = json_decode(AssistantDraft::squareTool(['config_json' => json_encode(['format' => 'landscape'])])['config_json'], true);
        $this->assertSame('1024x1024', ImageOptions::resolve('openai', 'gpt-image-2', $carre)['size'], 'L’avatar reste carré même si l’outil est réglé en paysage.');
    }

    public function testLeFormulaireNeGardeQueCeQueLeModeleAccepte(): void
    {
        $caps = ImageOptions::capabilities('openai', 'gpt-image-2.5-flare');
        $this->assertSame(['background' => 'transparent', 'output_format' => 'png', 'format' => 'portrait'],
            ImageOptions::fromPost(['config_transparent' => '1', 'config_output_format' => '', 'config_format' => 'portrait'], 'config_', $caps));
        $this->assertSame(['background' => '', 'output_format' => 'jpeg', 'format' => ''],
            ImageOptions::fromPost(['image_output_format' => 'jpeg'], 'image_', $caps));
        $this->assertNull(ImageOptions::encode([]));
        $this->assertSame('{"format":"square"}', ImageOptions::encode(['format' => 'square']));
        $this->assertSame(['background' => '', 'output_format' => '', 'format' => 'square'], ImageOptions::ofProfile(['image_options' => '{"format":"square"}']));
        $this->assertSame(['background' => '', 'output_format' => '', 'format' => ''], ImageOptions::ofProfile(null));
    }

    public function testLesTroisNiveauxSontCables(): void
    {
        $gen = self::src('src/Helpers/ImageGenerator.php');
        $this->assertStringContainsString('ImageOptions::resolve((string)$provider, (string)$model', $gen, 'Point de passage de toutes les images.');

        $admin = self::src('src/Controllers/AdminController.php');
        $this->assertStringContainsString("ImageOptions::fromPost(\$post, 'config_', ImageOptions::capabilities(\$provider, \$model))", $admin);
        $this->assertStringContainsString("if (empty(\$_POST['image_options_form'])) {", $admin, 'Bloc non rendu : rien n’est effacé.');
        $this->assertSame(2, substr_count($admin, '$this->applyProfileImageOptions('), 'Création et modification de l’assistant.');

        $tools = self::src('templates/admin/tools.php');
        foreach (['name="config_transparent"', 'name="config_output_format"', 'name="config_format"', 'IMAGE_MODEL_CAPS', 'ImageOptions::catalogCapabilities()'] as $needle) {
            $this->assertStringContainsString($needle, $tools);
        }
        $profile = self::src('templates/admin/profiles/edit.php');
        foreach (['name="image_options_form"', 'name="image_transparent"', 'name="image_output_format"', 'name="image_format"', 'data-image-caps'] as $needle) {
            $this->assertStringContainsString($needle, $profile);
        }

        $chat = self::src('src/Controllers/ChatController.php');
        $this->assertStringContainsString('return ImageOptions::applyTo($t, ImageOptions::ofProfile($profile));', $chat, 'L’assistant précise son outil dans la conversation.');
        $this->assertStringContainsString(": ImageOptions::ofProfile(\$profile));", $chat, 'Outil Image du menu « + » dans la conversation d’un assistant.');

        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString("'background'    => (string)(\$create['options']['background'] ?? ''),", $engine);
        $this->assertStringContainsString("'output_format' => (string)(\$create['options']['output_format'] ?? ''),", $engine);
        $editor = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString("case 'coutput':", $editor);
        $this->assertStringContainsString("case 'ooutput':", $editor);
        $this->assertStringContainsString('function imageCapsOf(tool)', $editor);

        $migration = self::src('database/migrations/097_profile_image_options.sql');
        $this->assertStringContainsString('ADD COLUMN image_options TEXT NULL', $migration);
        $this->assertStringContainsString("/src/Tools/ImageOptions.php", self::src('config/classes.php'));
        $this->assertStringContainsString("/src/Tools/ImageOptions.php", self::src('tests/bootstrap.php'));
    }

    public function testUneBriqueDeWorkflowGardeSesOptions(): void
    {
        $def = WorkflowSpec::normalize([
            'name' => 'Logo',
            'start' => ['mode' => 'manual', 'fields' => []],
            'nodes' => [[
                'key' => 'logo', 'type' => 'create', 'kind' => 'image', 'tool_id' => 3, 'instruction' => 'Un logo.',
                'options' => ['format' => 'square', 'background' => 'transparent', 'output_format' => 'jpeg'],
                'output' => ['key' => 'logo', 'name' => 'Logo', 'form' => 'image'],
            ]],
        ]);
        $this->assertSame(['format' => 'square', 'background' => 'transparent', 'output_format' => 'png'], $def['nodes'][0]['options']);
    }
}
