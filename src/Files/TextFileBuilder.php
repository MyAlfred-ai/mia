<?php

declare(strict_types=1);

/**
 * Fichiers TEXTE : `.txt`, `.md`, `.json`, `.sql`, code… Le contenu écrit par
 * le modèle EST le fichier — on ne touche qu'à ce qui empêche de l'ouvrir
 * proprement :
 *
 *  - une seule fin de ligne finale (un fichier texte se termine par un saut) ;
 *  - les fins de ligne du format quand il en impose (`.bat`, `.ics`, `.vcf`
 *    exigent CRLF ; partout ailleurs LF, qu'un script shell ne pardonnerait
 *    pas de voir remplacé) ;
 *  - un BOM sur les `.txt` SEULEMENT : c'est lui qui fait lire les accents
 *    correctement aux vieux outils Windows, et c'est aussi ce qu'il ne faut
 *    surtout pas mettre dans un JSON ou un script (la RFC 8259 l'interdit, un
 *    interpréteur le prendrait pour du code).
 *
 * Un cas convertit : « Texte (.txt) » demandé sur un document Markdown. Le
 * fichier est alors rendu LISIBLE (MarkdownPlain) — la source à dièses existe
 * déjà, c'est l'entrée « Markdown (.md) ».
 */
final class TextFileBuilder implements FileBuilder
{
    private const BOM = "\xEF\xBB\xBF";

    public function build(FileSource $source): string
    {
        $format = FileFormats::get($source->ext);
        $text   = $source->content;

        if ($source->syntax === FileFormats::SYNTAX_MARKDOWN && $source->ext === 'txt') {
            $text = MarkdownPlain::render(MarkdownDocument::parse($text));
        } elseif ($source->syntax === FileFormats::SYNTAX_HTML && in_array($source->ext, ['txt', 'md'], true)) {
            $text = MailHtml::htmlVersTexte($text);
        }

        $text = rtrim($text, "\n") . "\n";
        if ($format['eol'] !== "\n") {
            $text = str_replace("\n", $format['eol'], $text);
        }

        return ($source->ext === 'txt' ? self::BOM : '') . $text;
    }
}
