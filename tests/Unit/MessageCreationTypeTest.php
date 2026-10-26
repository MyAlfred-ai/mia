<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Détection du type de création partageable d'un message (sans base).
 */
final class MessageCreationTypeTest extends TestCase
{
    public function testHtmlPageViaFilesJson(): void
    {
        $msg = [
            'content'    => '<!DOCTYPE html><html></html>',
            'files_json' => json_encode([['type' => 'html_page', 'name' => 'page.html']]),
        ];
        $this->assertSame('html_page', Message::creationType($msg));
    }

    public function testMapRouteViaFilesJson(): void
    {
        $msg = [
            'content'    => '{"title":"Trajet","waypoints":[]}',
            'files_json' => json_encode([['type' => 'map_route', 'name' => 'itineraire.json']]),
        ];
        $this->assertSame('map_route', Message::creationType($msg));
    }

    public function testVideoFileViaFilesJson(): void
    {
        $msg = [
            'content'    => '[Vidéo générée](/assets/video/generated/video_x.mp4)',
            'files_json' => json_encode([['type' => 'video_file', 'name' => 'v.mp4', 'path' => '/assets/video/generated/v.mp4']]),
        ];
        $this->assertSame('video_file', Message::creationType($msg));
    }

    public function testHtmlPageViaFileTypeLegacy(): void
    {
        $msg = ['content' => '<html></html>', 'file_type' => 'html_page', 'files_json' => null];
        $this->assertSame('html_page', Message::creationType($msg));
    }

    public function testImageGenereeSeule(): void
    {
        $msg = ['content' => '![Image générée](/assets/img/generated/abc123.png)', 'files_json' => null];
        $this->assertSame('generated_image', Message::creationType($msg));
        $this->assertSame('/assets/img/generated/abc123.png', Message::generatedImageUrl($msg));
    }

    public function testImageExterneNonPartageable(): void
    {
        // Image markdown hors /assets/img/generated/ → pas une création MIA.
        $msg = ['content' => '![photo](https://exemple.com/photo.png)', 'files_json' => null];
        $this->assertNull(Message::creationType($msg));
        $this->assertNull(Message::generatedImageUrl($msg));
    }

    public function testImageEntoureeDeTexteNonPartageable(): void
    {
        // Même détection "image seule" que le partial message.php.
        $msg = ['content' => "Voici :\n![img](/assets/img/generated/a.png)", 'files_json' => null];
        $this->assertNull(Message::creationType($msg));
    }

    public function testMessageTexteSimple(): void
    {
        $msg = ['content' => 'Bonjour, voici ma réponse.', 'files_json' => null];
        $this->assertNull(Message::creationType($msg));
    }

    public function testPieceJointeUtilisateurNonPartageable(): void
    {
        // Fichier joint par l'utilisateur (type MIME) ≠ création générée.
        $msg = [
            'content'    => 'Analyse ce document.',
            'files_json' => json_encode([['type' => 'application/pdf', 'name' => 'doc.pdf', 'extracted' => 'texte']]),
        ];
        $this->assertNull(Message::creationType($msg));
    }
}
