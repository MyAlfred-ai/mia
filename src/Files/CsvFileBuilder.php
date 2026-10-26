<?php

declare(strict_types=1);

/**
 * Fichier CSV (ou TSV) — réécrit, pas recopié.
 *
 * Le CSV d'un modèle s'ouvre mal tel quel : pas de BOM (Excel lit alors les
 * accents en Latin-1 — « Ã© »), des fins de ligne LF, des cellules aux
 * guillemets mal doublés, des lignes de longueurs inégales. On le relit donc
 * avec TabularData et on le réécrit conforme (RFC 4180), sans rien changer à
 * ses valeurs ni à son SÉPARATEUR : le modèle a pu suivre une demande explicite
 * (« séparé par des virgules »), et le remplacer casserait l'import visé.
 *
 * Les cellules qu'un tableur exécuterait sans être des formules sûres sont
 * neutralisées (cf. SpreadsheetFormula::neutralize).
 */
final class CsvFileBuilder implements FileBuilder
{
    private const BOM = "\xEF\xBB\xBF";

    public function build(FileSource $source): string
    {
        try {
            $table = TabularData::parse($source->content);
        } catch (FileBuildException) {
            // Trop grand pour être analysé : on livre le texte tel quel, avec
            // BOM et CRLF. Un CSV imparfait vaut mieux qu'un refus — c'est
            // justement le format de repli des tableaux trop grands pour Excel.
            return self::BOM . str_replace("\n", "\r\n", rtrim($source->content, "\n") . "\n");
        }

        if ($table->isEmpty()) {
            throw FileBuildException::emptySource();
        }

        $delimiter = $source->ext === 'tsv' ? "\t" : $table->delimiter();

        return self::BOM . $table->toCsv($delimiter, "\r\n");
    }
}
