<?php

declare(strict_types=1);

/**
 * Présentation PowerPoint (.pptx), depuis du Markdown lu en slides.
 *
 * Deux chemins, un seul plan (MarkdownDeck::plan) :
 *
 *  - l'entreprise a déposé SON modèle PowerPoint (Organisation → Entreprise) : les
 *    slides sont construites DANS ce modèle — masques, polices, couleurs,
 *    pied de page (PptxTemplate) ;
 *  - sinon, notre mise en page (LayoutEngine, celle de l'outil Présentation)
 *    écrite par PptxExporter, avec le logo et le nom de l'entreprise.
 *
 * Un modèle qui ne se remplit pas (fichier abîmé depuis son dépôt) ne prive
 * jamais la personne de sa présentation : elle repart sur notre mise en page,
 * et l'échec part au journal.
 */
final class PptxFileBuilder implements FileBuilder
{
    public function build(FileSource $source): string
    {
        $blocks   = $source->documentBlocks();
        $template = CompanyProfile::templatePath();

        if ($template !== null) {
            $plan = MarkdownDeck::plan($blocks, $source->title);
            try {
                return PptxTemplate::fill($template, $plan['slides'], [
                    'title'  => $plan['title'],
                    'footer' => CompanyProfile::documentFooter(),
                    'lang'   => CompanyProfile::officeLanguage(),
                ]);
            } catch (Throwable $e) {
                error_log('[Fichiers] Modèle PowerPoint de l’entreprise inutilisable, mise en page MIA à la place : '
                    . get_class($e) . ' — ' . $e->getMessage());
            }
        }

        if (!FileLibrary::has(\PhpOffice\PhpPresentation\PhpPresentation::class) || !class_exists('PptxExporter')) {
            throw FileBuildException::unavailable('PowerPoint', ['pdf', 'docx']);
        }

        return PptxExporter::export(MarkdownDeck::build(
            $blocks,
            $source->title,
            CompanyProfile::brand(),
            CompanyProfile::deckTheme()
        ));
    }
}
