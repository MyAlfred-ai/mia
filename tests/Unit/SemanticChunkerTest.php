<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Découpage sémantique — parties PURES (assemblage depuis frontières, parsing
 * des indices LLM). Le chemin LLM lui-même n'est pas testé ici (pas de clé en
 * unité) ; le fallback heuristique de split() est couvert par le pipeline KB
 * fonctionnel. La propriété critique vérifiée : l'assemblage ne perd ni ne
 * réécrit JAMAIS le contenu (il ne fait que regrouper des unités d'origine).
 */
final class SemanticChunkerTest extends TestCase
{
    /** @return list<string> */
    private function units(int $count, int $chars): array
    {
        $u = [];
        for ($i = 0; $i < $count; $i++) {
            // Contenu distinct par unité (lettre + remplissage) pour tracer l'ordre.
            $u[] = chr(65 + $i) . str_repeat('x', max(0, $chars - 1));
        }
        return $u;
    }

    public function testParseIndicesValidFilteredSorted(): void
    {
        $this->assertSame([0, 3, 7], SemanticChunker::parseIndices('[0,3,7]', 10));
        // Bruit autour + doublons + hors bornes filtrés, tri croissant.
        $this->assertSame([0, 2, 5], SemanticChunker::parseIndices("Voici: [5, 2, 0, 2, 99] fin", 6));
        // Fence markdown.
        $this->assertSame([0, 4], SemanticChunker::parseIndices("```json\n[0,4]\n```", 5));
    }

    public function testParseIndicesRejectsGarbage(): void
    {
        $this->assertNull(SemanticChunker::parseIndices('aucun tableau ici', 5));
        $this->assertNull(SemanticChunker::parseIndices('[99, 42]', 5), 'tous hors bornes → null');
        $this->assertNull(SemanticChunker::parseIndices('{"a":1}', 5));
    }

    public function testAssembleRespectsBoundariesWithLargeSections(): void
    {
        // 4 unités de ~1500 chars : deux ne tiennent pas ensemble sous TARGET
        // (2000) → les frontières produisent bien des chunks distincts.
        $units = $this->units(4, 1500);
        $chunks = SemanticChunker::assemble($units, [0, 2]);

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('Axxx', $chunks[0]);
        $this->assertStringContainsString('Bxxx', $chunks[0]);
        $this->assertStringContainsString('Cxxx', $chunks[1]);
        $this->assertStringContainsString('Dxxx', $chunks[1]);
    }

    public function testAssembleForcesLeadingZeroAndDropsOutOfRange(): void
    {
        $units = $this->units(4, 1500);
        // Frontières sans 0 et avec un index hors bornes : sanitizées en [0,2].
        $chunks = SemanticChunker::assemble($units, [2, 99]);
        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('Axxx', $chunks[0]);
        $this->assertStringContainsString('Cxxx', $chunks[1]);
    }

    public function testAssemblePreservesAllContentInOrder(): void
    {
        // Intégrité : toutes les unités présentes, dans l'ordre, quelles que
        // soient les frontières (le LLM ne réécrit jamais le texte).
        $units  = $this->units(6, 900);
        $chunks = SemanticChunker::assemble($units, [0, 1, 3, 5]);
        $joined = implode("\n", $chunks);

        $lastPos = -1;
        foreach (['Axx', 'Bxx', 'Cxx', 'Dxx', 'Exx', 'Fxx'] as $marker) {
            $pos = mb_strpos($joined, $marker);
            $this->assertNotFalse($pos, "unité $marker absente de la sortie");
            $this->assertGreaterThan($lastPos, $pos, "ordre non préservé pour $marker");
            $lastPos = (int)$pos;
        }
    }

    public function testAssembleHardSplitsOversizedGroup(): void
    {
        // Un seul groupe (frontière [0]) de 3×1500 = 4500 chars > CHUNK_MAX (3200)
        // → borné dimensionnellement en plusieurs chunks ≤ max.
        $units  = $this->units(3, 1500);
        $chunks = SemanticChunker::assemble($units, [0]);
        $this->assertGreaterThanOrEqual(2, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(TextChunker::CHUNK_MAX_TOKENS * 4 + 300, mb_strlen($c));
        }
    }

    public function testAssembleEmptyUnits(): void
    {
        $this->assertSame([], SemanticChunker::assemble([], [0]));
    }
}
