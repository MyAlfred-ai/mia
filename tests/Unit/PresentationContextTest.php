<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sources qui nourrissent une présentation.
 *
 * Le plan et la rédaction doivent s'appuyer sur la conversation en cours, pas
 * seulement sur le sujet tapé. L'historique y est injecté sous forme de digest
 * — ce test verrouille la brique pure qui le prépare : sans elle, une création
 * déjà présente dans le fil (page HTML, deck, itinéraire) y déverserait des
 * dizaines de Ko et écraserait tout le contexte utile.
 */
final class PresentationContextTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    private function deckMessage(): array
    {
        // Spec volumineuse, telle qu'elle est réellement stockée en base.
        $deck = ['title' => 'Un deck', 'slides' => array_fill(0, 12, [
            'id' => 's', 'layout' => 'titre-contenu',
            'elements' => array_fill(0, 6, ['type' => 'text', 'text' => str_repeat('du texte ', 40)]),
        ])];

        return [
            'role'       => 'assistant',
            'content'    => json_encode($deck),
            'files_json' => json_encode([['type' => 'slide_deck', 'name' => 'presentation.json']]),
        ];
    }

    public function testSpecDeDeckRemplaceeParUnLibelle(): void
    {
        $out = ChatController::summarizeCreationsForDigest([$this->deckMessage()]);

        $this->assertSame('[présentation générée]', $out[0]['content']);
    }

    public function testLeDigestNeGonflePlusAvecUneCreation(): void
    {
        $brut = $this->deckMessage();
        $this->assertGreaterThan(5000, strlen($brut['content']), 'le cas de test doit être volumineux');

        $digest = ChatController::buildImageContextDigest(
            ChatController::summarizeCreationsForDigest([$brut])
        );

        $this->assertLessThan(100, strlen($digest));
        $this->assertStringContainsString('présentation générée', $digest);
    }

    public function testAutresCreationsResumees(): void
    {
        $history = [
            ['role' => 'assistant', 'content' => '<!DOCTYPE html><html></html>',
             'files_json' => json_encode([['type' => 'html_page', 'name' => 'page.html']])],
            ['role' => 'assistant', 'content' => '{"title":"T","waypoints":[]}',
             'files_json' => json_encode([['type' => 'map_route', 'name' => 'itineraire.json']])],
            ['role' => 'assistant', 'content' => '![Image générée](/assets/img/generated/a.png)'],
        ];

        $out = ChatController::summarizeCreationsForDigest($history);

        $this->assertSame('[page HTML générée]', $out[0]['content']);
        $this->assertSame('[itinéraire généré]', $out[1]['content']);
        $this->assertSame('[image générée]', $out[2]['content']);
    }

    /** Les messages ordinaires — la matière utile — passent intacts. */
    public function testMessagesOrdinairesIntacts(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'On vise plutôt les DSI de PME.'],
            ['role' => 'assistant', 'content' => 'Compris, je recentre sur ce public.'],
        ];

        $this->assertSame($history, ChatController::summarizeCreationsForDigest($history));
    }

    /** Le digest doit conserver les décisions prises dans la conversation. */
    public function testDigestConserveLesEchangesUtiles(): void
    {
        $digest = ChatController::buildImageContextDigest(
            ChatController::summarizeCreationsForDigest([
                ['role' => 'user', 'content' => 'On vise les DSI de PME.'],
                ['role' => 'assistant', 'content' => 'Noté, je recentre.'],
                $this->deckMessage(),
            ])
        );

        $this->assertStringContainsString('DSI de PME', $digest);
        $this->assertStringContainsString('Noté, je recentre.', $digest);
        $this->assertStringNotContainsString('slides', $digest);
    }
}
