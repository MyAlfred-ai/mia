<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verdict de santé d'une base (logique pure) : c'est lui qui décide du badge
 * affiché et de la présence du bouton « Réindexer ». Un faux « Opérationnelle »
 * laisserait une base muette en production sans le moindre signal.
 */
final class QdrantHealthTest extends TestCase
{
    /** @param array<string, int|null> $over */
    private function base(array $over = []): array
    {
        return array_merge([
            'docs' => 3, 'chunks' => 40, 'points' => 40, 'dims' => 1536,
            'actual_dims' => 1536, 'pending_docs' => 0, 'failed_docs' => 0,
        ], $over);
    }

    public function testServiceInjoignablePrimeSurToutLeReste(): void
    {
        $this->assertSame(
            QdrantHealth::STATE_DOWN,
            QdrantHealth::stateFor(false, false, $this->base(['chunks' => 0, 'docs' => 0]))
        );
    }

    public function testCollectionAbsenteSurBasePeuplee(): void
    {
        $this->assertSame(
            QdrantHealth::STATE_MISSING,
            QdrantHealth::stateFor(true, false, $this->base(['points' => 0]))
        );
    }

    public function testCollectionAbsenteSurBaseVideNestPasUnDefaut(): void
    {
        // Base créée mais sans document : la collection sera posée au 1er upload.
        $this->assertSame(
            QdrantHealth::STATE_VOID,
            QdrantHealth::stateFor(true, false, $this->base(['docs' => 0, 'chunks' => 0, 'points' => 0]))
        );
    }

    public function testDimensionIncoherentePrimeSurLesCompteurs(): void
    {
        // Modèle d'embedding changé sans réindexation : les compteurs peuvent
        // concorder alors que les vecteurs sont inexploitables.
        $this->assertSame(
            QdrantHealth::STATE_DIMS,
            QdrantHealth::stateFor(true, true, $this->base(['actual_dims' => 1024]))
        );
    }

    public function testAucunVecteurMalgreDesExtraits(): void
    {
        $this->assertSame(
            QdrantHealth::STATE_EMPTY,
            QdrantHealth::stateFor(true, true, $this->base(['points' => 0]))
        );
    }

    public function testVectorisationIncomplete(): void
    {
        $this->assertSame(
            QdrantHealth::STATE_PARTIAL,
            QdrantHealth::stateFor(true, true, $this->base(['points' => 12]))
        );
    }

    public function testEcartExpliqueParUneIngestionEnCours(): void
    {
        // Un document encore à vectoriser explique l'écart : ne pas crier au feu.
        $this->assertSame(
            QdrantHealth::STATE_RUNNING,
            QdrantHealth::stateFor(true, true, $this->base(['points' => 12, 'pending_docs' => 1]))
        );
    }

    public function testDocumentsEnEchecMalgreDesVecteursComplets(): void
    {
        $this->assertSame(
            QdrantHealth::STATE_FAILED,
            QdrantHealth::stateFor(true, true, $this->base(['failed_docs' => 2]))
        );
    }

    public function testBaseSaine(): void
    {
        $this->assertSame(QdrantHealth::STATE_OK, QdrantHealth::stateFor(true, true, $this->base()));
    }

    public function testDimensionInconnueNeDeclenchePasDeFauxPositif(): void
    {
        // Qdrant qui ne renvoie pas la taille du vecteur (0) ne doit pas faire
        // conclure à une incohérence.
        $this->assertSame(
            QdrantHealth::STATE_OK,
            QdrantHealth::stateFor(true, true, $this->base(['actual_dims' => 0]))
        );
    }

    public function testLinfobulleDeDimensionPorteLesDeuxTailles(): void
    {
        // Le badge reste court (largeur de colonne fixe) : les chiffres qui
        // permettent de comprendre l'écart vivent dans l'infobulle.
        $hint = QdrantHealth::stateHint(QdrantHealth::STATE_DIMS, $this->base(['actual_dims' => 1024]));
        $this->assertStringContainsString('1024', $hint);
        $this->assertStringContainsString('1536', $hint);
    }

    public function testLibellesPortentLesChiffresUtiles(): void
    {
        $this->assertStringContainsString(
            '2 documents',
            QdrantHealth::stateLabel(QdrantHealth::STATE_FAILED, $this->base(['failed_docs' => 2]))
        );
        $this->assertStringContainsString(
            '1 document en échec',
            QdrantHealth::stateLabel(QdrantHealth::STATE_FAILED, $this->base(['failed_docs' => 1]))
        );
    }
}
