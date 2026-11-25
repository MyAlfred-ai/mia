<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mise en page des PDF de documents (MarkdownHtml → Dompdf) : ce qui rendait
 * les PDF « décevants », vérifié sur des documents types le 2026-10-29 —
 * polices inconnues de Dompdf (gras en Helvetica, sans « → » ni accents
 * hors Windows-1252), marges de page à zéro (texte au bord, pied de page de
 * l'entreprise hors de la feuille), tableaux larges coupés, mots longs qui
 * sortaient de la page. Partie PURE ; le rendu réel est vérifié par
 * FileBuildersLibraryTest (Dompdf requis).
 */
final class PdfLayoutTest extends TestCase
{
    private static function src(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    /** Les noms de police sont ceux de Dompdf : `dejavusans` le faisait retomber sur Helvetica. */
    public function testLesPolicesPortentLeurNomDompdf(): void
    {
        $doc = MarkdownHtml::document(MarkdownDocument::parse("# Titre\n\nTexte **gras** et `code`."));

        $this->assertStringContainsString('font-family:"DejaVu Sans",sans-serif', $doc);
        $this->assertStringContainsString('font-family:"DejaVu Sans Mono",monospace', $doc);
        $this->assertStringNotContainsString('dejavusans', $doc);
    }

    /** Marges de PAGE posées par @page, jamais par `html` : sans elles, texte au bord et pied de page hors de la feuille. */
    public function testLesMargesDePageSontPoseesParPage(): void
    {
        $service = self::src('src/Helpers/PdfExportService.php');

        $this->assertMatchesRegularExpression('/@page \{ margin: 20mm 16mm 18mm 16mm; \}/', $service);
        $this->assertStringNotContainsString('html, body {', $service, 'Dompdf prend les marges de `html` pour celles de la page.');
        $this->assertStringContainsString("\$dompdf->setPaper('A4', \$orientation === 'landscape' ? 'landscape' : 'portrait');", $service);
    }

    public function testCoupuresEtSautsDePage(): void
    {
        $doc = MarkdownHtml::document([]);

        $this->assertStringContainsString('p,li,td,th{word-wrap:break-word;}', $doc, 'Un mot ou une adresse trop longs se coupent.');
        $this->assertStringContainsString('table-layout:fixed', $doc);
        $this->assertStringContainsString('tr{page-break-inside:avoid;}', $doc);
        $this->assertStringContainsString('h1,h2,h3,h4,h5,h6{page-break-after:avoid;}', $doc);
    }

    /** Petites colonnes jamais sacrifiées : « 12 500 € » et une date tiennent sur une ligne. */
    public function testLargeurDesColonnesSelonLeurContenu(): void
    {
        $widths = MarkdownHtml::columnWidths(
            ['Fournisseur', 'Offre', 'Prix annuel HT', 'Délai'],
            [
                ['Accenture Technology Solutions', 'Plateforme intégrée de gestion documentaire avec module d’intelligence artificielle', '125 000 €', '6 mois'],
                ['Capgemini', 'Solution SaaS', '98 500 €', '4 mois'],
            ]
        );

        $this->assertCount(4, $widths);
        $this->assertEqualsWithDelta(100.0, array_sum($widths), 0.1);
        $this->assertGreaterThan($widths[2], $widths[1], 'La colonne longue prend plus de place.');
        $this->assertGreaterThan($widths[3], $widths[2], 'Un montant prend plus qu’une durée.');
        // Le minimum de « 125 000 € » : 9 caractères (×1,2) + marge, sur une ligne de 88.
        $this->assertGreaterThanOrEqual(100 * (11 + 3) / 88 - 0.01, $widths[2]);
    }

    /** Toutes égales sans contenu ; aucune colonne vide. */
    public function testLargeursDegenerees(): void
    {
        $this->assertSame([], MarkdownHtml::columnWidths([], []));
        $this->assertSame([50.0, 50.0], MarkdownHtml::columnWidths(['', ''], [['', '']]));
        $this->assertCount(3, MarkdownHtml::columnWidths(['A', 'B'], [['1', '2', '3']]), 'Une ligne plus longue que l’en-tête compte.');
    }

    /** Au-delà de 6 colonnes : page en paysage et tableau en corps réduit. */
    public function testTableauLargeEnPaysage(): void
    {
        $large = MarkdownDocument::parse("| a | b | c | d | e | f | g |\n|---|---|---|---|---|---|---|\n| 1 | 2 | 3 | 4 | 5 | 6 | 7 |");
        $etroit = MarkdownDocument::parse("| a | b |\n|---|---|\n| 1 | 2 |");

        $this->assertTrue(MarkdownHtml::wantsLandscape($large));
        $this->assertFalse(MarkdownHtml::wantsLandscape($etroit));
        $this->assertTrue(MarkdownHtml::wantsLandscape([['type' => 'quote', 'blocks' => $large]]), 'Un tableau cité compte aussi.');
        $this->assertStringContainsString('<table class="wide">', MarkdownHtml::document($large));
        $this->assertStringContainsString('<table>', MarkdownHtml::document($etroit));
        $this->assertStringContainsString("MarkdownHtml::wantsLandscape(\$blocks) ? 'landscape' : 'portrait'", self::src('src/Files/PdfFileBuilder.php'));
    }

    /** Largeurs posées sur l'en-tête au rendu PDF seulement ; à l'écran, le navigateur décide. */
    public function testLargeursAuRenduPdfSeulement(): void
    {
        $blocs = MarkdownDocument::parse("| Nom | Montant |\n|---|---:|\n| Loyer | 1 200 € |");

        $this->assertMatchesRegularExpression('/<th style="width:[0-9.]+%">Nom<\/th><th style="text-align:right;width:[0-9.]+%">Montant<\/th>/', MarkdownHtml::render($blocs, true));
        $this->assertStringNotContainsString('width:', MarkdownHtml::render($blocs));
    }

    /** Mots trop longs pour le portrait : corps réduit, sans passer en paysage. */
    public function testTableauDenseEnCorpsReduit(): void
    {
        $dense = MarkdownDocument::parse(
            "| Fournisseur | Offre | Prix annuel HT | Délai | Points forts | Référence contrat |\n|---|---|---:|---|---|---|\n"
            . "| Accenture Technology Solutions | Plateforme documentaire | 125 000 € | 6 mois | accompagnement international | CTR-2026-ACC-00045871-REV3 |"
        );

        $this->assertFalse(MarkdownHtml::wantsLandscape($dense));
        $this->assertStringContainsString('<table class="wide">', MarkdownHtml::document($dense));
    }
}
