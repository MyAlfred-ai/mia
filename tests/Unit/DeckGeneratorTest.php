<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prompts, décodage et garanties de la génération de présentations.
 *
 * Aucun appel réseau : DeckGenerator ne fait que produire des prompts et
 * interpréter des réponses. L'enjeu principal est la robustesse face à une
 * sortie de LLM approximative.
 */
final class DeckGeneratorTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function outlineOf(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'id' => 'x' . $i, 'title' => 'T' . $i, 'points' => [],
                'layout' => 'titre-contenu', 'image_prompt' => '',
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------- décodage

    public function testJsonNu(): void
    {
        $this->assertSame(['a' => 1], DeckGenerator::decodeJson('{"a":1}'));
    }

    public function testFenceMarkdownRetire(): void
    {
        $this->assertSame(['a' => 2], DeckGenerator::decodeJson("```json\n{\"a\":2}\n```"));
        $this->assertSame(['a' => 3], DeckGenerator::decodeJson("```\n{\"a\":3}\n```"));
    }

    public function testPreambuleEtSuffixeIgnores(): void
    {
        $this->assertSame(['a' => 4], DeckGenerator::decodeJson("Voici le plan :\n{\"a\":4}"));
        $this->assertSame(['a' => 5], DeckGenerator::decodeJson('{"a":5} Voilà !'));
    }

    /** Une accolade dans un titre ne doit pas fausser le comptage. */
    public function testAccoladeDansUneChaine(): void
    {
        $this->assertSame(
            ['t' => 'un { piège', 'b' => 6],
            DeckGenerator::decodeJson('{"t":"un { piège","b":6}')
        );
    }

    public function testGuillemetEchappeDansUneChaine(): void
    {
        $this->assertSame(
            ['t' => 'gui"llemet }', 'b' => 7],
            DeckGenerator::decodeJson('{"t":"gui\\"llemet }","b":7}')
        );
    }

    public function testObjetImbrique(): void
    {
        $this->assertSame(['s' => ['x' => 1]], DeckGenerator::decodeJson('bla {"s":{"x":1}} bla'));
    }

    public function testReponsesIllisibles(): void
    {
        $this->assertNull(DeckGenerator::decodeJson(''));
        $this->assertNull(DeckGenerator::decodeJson('pas de json ici'));
        $this->assertNull(DeckGenerator::decodeJson('{"a":1,'));
    }

    // -------------------------------------------------- nombre de slides

    public function testNombreExactConserve(): void
    {
        $r = DeckGenerator::enforceSlideCount($this->outlineOf(10), 10);

        $this->assertCount(10, $r);
        $this->assertSame('T1', $r[0]['title']);
        $this->assertSame('T10', $r[9]['title']);
    }

    public function testIdentifiantsRenumerotes(): void
    {
        $r = DeckGenerator::enforceSlideCount($this->outlineOf(4), 4);

        $this->assertSame(['s1', 's2', 's3', 's4'], array_column($r, 'id'));
    }

    public function testExcedentTronqueEnGardantLaConclusion(): void
    {
        $r = DeckGenerator::enforceSlideCount($this->outlineOf(14), 8);

        $this->assertCount(8, $r);
        $this->assertSame('T1', $r[0]['title']);
        $this->assertSame('T7', $r[6]['title']);
        $this->assertSame('T14', $r[7]['title'], 'la dernière slide du modèle est la conclusion');
    }

    /** Le complément s'insère avant la conclusion, jamais après. */
    public function testDeficitCompleteAvantLaConclusion(): void
    {
        $r = DeckGenerator::enforceSlideCount($this->outlineOf(5), 9);

        $this->assertCount(9, $r);
        $this->assertSame('T4', $r[3]['title']);
        $this->assertSame('Slide à compléter', $r[4]['title']);
        $this->assertSame('Slide à compléter', $r[7]['title']);
        $this->assertSame('T5', $r[8]['title']);
    }

    public function testUneSeuleSlideNaPasDeConclusionAPreserver(): void
    {
        $r = DeckGenerator::enforceSlideCount($this->outlineOf(1), 3);

        $this->assertCount(3, $r);
        $this->assertSame('T1', $r[0]['title']);
    }

    public function testPlanVideEntierementComplete(): void
    {
        $this->assertCount(6, DeckGenerator::enforceSlideCount([], 6));
    }

    public function testBornesRespectees(): void
    {
        $this->assertCount(DeckSpec::MAX_SLIDES, DeckGenerator::enforceSlideCount($this->outlineOf(3), 999));
        $this->assertCount(DeckSpec::MIN_SLIDES, DeckGenerator::enforceSlideCount($this->outlineOf(3), 0));
    }

    // ----------------------------------------------------------- lots

    public function testDecoupageEnLots(): void
    {
        $lots = DeckGenerator::batches($this->outlineOf(10), 4);

        $this->assertCount(3, $lots);
        $this->assertCount(4, $lots[0]);
        $this->assertCount(2, $lots[2]);
    }

    public function testTailleDeLotNulleProtegee(): void
    {
        $this->assertCount(3, DeckGenerator::batches($this->outlineOf(3), 0));
    }

    // -------------------------------------------------------- fusion

    public function testSlideRedigeeAppliquee(): void
    {
        $outline = DeckGenerator::enforceSlideCount($this->outlineOf(3), 3);
        $merged  = DeckGenerator::mergeWritten($outline, [
            ['id' => 's2', 'title' => 'Titre rédigé', 'points' => ['a'], 'layout' => 'citation', 'notes' => 'à dire'],
        ]);

        $this->assertSame('Titre rédigé', $merged[1]['title']);
        $this->assertSame('citation', $merged[1]['layout']);
        $this->assertSame('à dire', $merged[1]['notes']);
    }

    /** Une rédaction ratée dégrade la slide, elle ne la fait pas disparaître. */
    public function testSlideNonRedigeeConservee(): void
    {
        $outline = DeckGenerator::enforceSlideCount($this->outlineOf(3), 3);
        $merged  = DeckGenerator::mergeWritten($outline, []);

        $this->assertCount(3, $merged);
        $this->assertSame('T1', $merged[0]['title']);
    }

    public function testSlideInconnueIgnoree(): void
    {
        $outline = DeckGenerator::enforceSlideCount($this->outlineOf(2), 2);
        $merged  = DeckGenerator::mergeWritten($outline, [['id' => 'inexistant', 'title' => 'X']]);

        $this->assertCount(2, $merged);
        $this->assertSame('T1', $merged[0]['title']);
    }

    /** Le prompt d'image vient du plan : le modèle de rédaction n'en invente pas. */
    public function testPromptImageDuPlanReste(): void
    {
        $outline = $this->outlineOf(2);
        $outline[0]['image_prompt'] = 'un paysage';
        $outline = DeckGenerator::enforceSlideCount($outline, 2);

        $merged = DeckGenerator::mergeWritten($outline, [
            ['id' => 's1', 'title' => 'T1', 'points' => [], 'image_prompt' => 'INVENTÉ'],
        ]);

        $this->assertSame('un paysage', $merged[0]['image_prompt']);
    }

    // -------------------------------------------------------- prompts

    public function testNombreDeSlidesRappeleDansLesDeuxPrompts(): void
    {
        $system = DeckGenerator::outlineSystemPrompt(['slide_count' => 12]);
        $user   = DeckGenerator::outlineUserPrompt('Sujet', '', ['slide_count' => 12]);

        $this->assertStringContainsString('EXACTEMENT 12', $system);
        $this->assertStringContainsString('EXACTEMENT 12', $user);
    }

    public function testImagesInterditesQuandAucunStyle(): void
    {
        $system = DeckGenerator::outlineSystemPrompt(['slide_count' => 5, 'image_style' => '']);

        $this->assertStringContainsString('TOUJOURS une chaîne vide', $system);
    }

    public function testDocumentsJointsInseres(): void
    {
        $user = DeckGenerator::outlineUserPrompt('Sujet', 'CONTENU_DU_DOCUMENT', []);

        $this->assertStringContainsString('CONTENU_DU_DOCUMENT', $user);
    }

    public function testPromptImagePorteLaGardeAntiTexte(): void
    {
        $prompt = DeckGenerator::imagePrompt('une ville la nuit', 'style aquarelle');

        $this->assertStringContainsString('une ville la nuit', $prompt);
        $this->assertStringContainsString('style aquarelle', $prompt);
        $this->assertStringContainsString('Aucun texte', $prompt);
    }
}
