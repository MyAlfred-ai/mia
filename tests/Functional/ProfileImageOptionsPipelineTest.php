<?php

declare(strict_types=1);

/**
 * Options des images d'un assistant (ImageOptions), avec la vraie base : la
 * colonne de la migration 097, l'aller-retour par Profile, et l'outil de
 * l'assistant réglé par-dessus le sien — l'outil, partagé, ne bouge pas.
 */
final class ProfileImageOptionsPipelineTest extends DatabaseTestCase
{
    public function testLesOptionsDeLAssistantPrecisentCellesDeSonOutil(): void
    {
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        $id = Profile::create(['name' => 'Logos', 'system_prompt' => 'Tu dessines des logos.']);
        $this->assertNull(Profile::findById($id)['image_options'], 'Rien de réglé : NULL, l’outil décide.');

        Profile::setImageOptions($id, ['background' => 'transparent', 'format' => 'square']);
        $profile = Profile::findById($id);
        $this->assertSame(['background' => 'transparent', 'output_format' => 'png', 'format' => 'square'], ImageOptions::ofProfile($profile));

        $tool = ['type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2.5-flare', 'config_json' => json_encode(['quality' => 'high', 'format' => 'landscape'])];
        $config = json_decode(ImageOptions::applyTo($tool, ImageOptions::ofProfile($profile))['config_json'], true);
        $this->assertSame('square', $config['format'], 'L’assistant l’emporte sur l’outil.');
        $this->assertSame(['quality' => 'high', 'background' => 'transparent', 'output_format' => 'png', 'size' => '1024x1024'],
            ImageOptions::resolve('openai', 'gpt-image-2.5-flare', $config));

        Profile::setImageOptions($id, []);
        $this->assertNull(Profile::findById($id)['image_options'], 'Tout vidé : retour à l’outil.');
    }
}
