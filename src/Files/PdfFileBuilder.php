<?php

declare(strict_types=1);

/**
 * Document PDF, depuis du Markdown : structure → HTML sûr (MarkdownHtml) →
 * mise en page par Dompdf, via le service d'export déjà utilisé par le bouton
 * « Exporter en PDF » d'une réponse.
 *
 * Le HTML remis à Dompdf est produit ICI, jamais reçu : il ne contient ni image
 * ni ressource distante, donc le moteur ne sort aucune requête du serveur.
 *
 * Une PRÉSENTATION (« plan.pptx » téléchargé en PDF) donne un PDF de SLIDES,
 * pas un document : mêmes slides que le PowerPoint, par l'exporteur PDF de
 * l'outil Présentation. Un menu qui annonce « PDF » à côté de « PowerPoint »
 * promet la présentation, pas son plan.
 */
final class PdfFileBuilder implements FileBuilder
{
    public function build(FileSource $source): string
    {
        if ($source->isPresentation()) {
            return self::slides($source);
        }

        if (!FileLibrary::has(\Dompdf\Dompdf::class) || !class_exists('PdfExportService')) {
            throw FileBuildException::unavailable('PDF', ['docx', 'rtf']);
        }

        $blocks = $source->documentBlocks();
        if ($blocks === []) {
            throw FileBuildException::emptySource();
        }

        $title = self::printable($source->documentTitle($blocks));

        return (new PdfExportService())->buildFromHtml(
            self::printable(MarkdownHtml::document($blocks, $title)),
            $title,
            CompanyProfile::pdfChrome()
        );
    }

    /** PDF 16:9, une page par slide. */
    private static function slides(FileSource $source): string
    {
        if (!FileLibrary::has(\Dompdf\Dompdf::class) || !class_exists('SlidesPdfExporter')) {
            throw FileBuildException::unavailable('PDF', ['pptx', 'docx']);
        }

        $printable = $source->withContent(self::printable($source->content));

        return SlidesPdfExporter::export(MarkdownDeck::build(
            $printable->documentBlocks(),
            $source->title,
            CompanyProfile::brand(),
            CompanyProfile::deckTheme()
        ));
    }

    /**
     * Retire ce que la police du PDF ne sait pas dessiner : les émojis (plan
     * supplémentaire), leurs sélecteurs de variante et leurs liants. Dompdf les
     * remplacerait par un rectangle vide — un carré au milieu d'un titre se
     * voit plus qu'un émoji absent. Les symboles du plan de base (☑, ✓, ★, ⚠)
     * restent : la police intégrée les dessine. Word et RTF gardent tout,
     * ils s'appuient sur les polices du poste.
     */
    public static function printable(string $text): string
    {
        $text = (string)preg_replace('/[\x{FE0F}\x{200D}]/u', '', $text);

        return (string)preg_replace('/[\x{1F000}-\x{1FFFF}] ?/u', '', $text);
    }
}
