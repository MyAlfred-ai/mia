<?php

declare(strict_types=1);

/**
 * Document RTF, depuis du Markdown (ou du texte brut). Aucune bibliothèque :
 * c'est MarkdownRtf qui écrit le format.
 *
 * Le RTF a une place à part : il est le format de REPLI des documents. Il
 * s'ouvre dans Word, LibreOffice, Pages et TextEdit, et ne dépend de rien sur
 * le serveur — quand la fabrication d'un `.docx` échoue, c'est lui que la
 * carte propose.
 */
final class RtfFileBuilder implements FileBuilder
{
    public function build(FileSource $source): string
    {
        // Le modèle a parfois écrit du RTF lui-même (bloc ```rtf) : c'est déjà
        // le fichier, le repasser par le Markdown l'afficherait en toutes lettres.
        $raw = ltrim($source->content);
        if (str_starts_with($raw, '{\rtf')) {
            return $raw;
        }

        $blocks = $source->documentBlocks();
        if ($blocks === []) {
            throw FileBuildException::emptySource();
        }

        return MarkdownRtf::render($blocks, $source->documentTitle($blocks), $source->locale);
    }
}
