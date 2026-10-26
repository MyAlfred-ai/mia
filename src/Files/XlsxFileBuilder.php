<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Classeur Excel (.xlsx) depuis un tableau écrit par le modèle.
 *
 * Toute l'intelligence est AILLEURS, dans des classes pures et testées sans
 * bibliothèque : TabularData lit le tableau, SpreadsheetCell reconnaît nombres
 * et dates, SpreadsheetFormula décide des formules conservées. Ici, on ne fait
 * que poser ces décisions dans un classeur PhpSpreadsheet et lui donner
 * l'allure qu'on attend d'un fichier « prêt à l'emploi » : en-tête en gras sur
 * fond gris, ligne d'en-tête figée, filtre automatique, colonnes à la largeur
 * de leur contenu.
 *
 * Une cellule est TOUJOURS écrite avec son type explicite. Laisser
 * PhpSpreadsheet deviner (`setCellValue`) referait, en moins prudent, le
 * travail de SpreadsheetCell : « 01000 » redeviendrait 1000, et toute chaîne
 * commençant par « = » une formule — exactement ce que la liste blanche
 * interdit.
 */
final class XlsxFileBuilder implements FileBuilder
{
    /** Au-delà, la mémoire du serveur ne suit plus (≈ 1 Ko par cellule). */
    private const MAX_CELLS = 100000;

    /** Largeur de colonne, en caractères. */
    private const MIN_WIDTH = 8;
    private const MAX_WIDTH = 60;

    public function build(FileSource $source): string
    {
        if (!FileLibrary::has(Spreadsheet::class)) {
            throw FileBuildException::unavailable('Excel', ['csv']);
        }

        try {
            $table = TabularData::parse($source->content);
        } catch (FileBuildException $e) {
            // Même message, mais avec l'issue : le CSV n'a pas de plafond.
            throw $e->withAlternatives(['csv']);
        }
        if ($table->isEmpty()) {
            throw FileBuildException::noTable(['csv']);
        }

        $rows    = $table->rows();
        $columns = $table->columnCount();
        if (count($rows) * $columns > self::MAX_CELLS) {
            throw FileBuildException::tableTooLarge(count($rows), $columns, ['csv']);
        }

        $book = new Spreadsheet();
        $book->getProperties()
            ->setCreator($source->author)
            ->setLastModifiedBy($source->author)
            ->setTitle($source->title);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::sheetTitle($source->title));

        $hasHeader = $table->hasHeader($source->locale);
        $widths    = array_fill(0, $columns, self::MIN_WIDTH);
        $wrapped   = [];

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $raw) {
                $raw = (string)$raw;
                // Largeur : la plus longue LIGNE de la cellule (une cellule à
                // retours à la ligne ne doit pas élargir sa colonne d'autant).
                $longest = 0;
                foreach (explode("\n", $raw) as $line) {
                    $longest = max($longest, mb_strwidth($line, 'UTF-8'));
                }
                $widths[$c] = max($widths[$c], min(self::MAX_WIDTH, $longest + 2));
                if ($longest + 2 > self::MAX_WIDTH || str_contains($raw, "\n")) {
                    $wrapped[$c] = true;
                }

                $coordinate = Coordinate::stringFromColumnIndex($c + 1) . ($r + 1);
                if ($hasHeader && $r === 0) {
                    $sheet->setCellValueExplicit($coordinate, $raw, DataType::TYPE_STRING);
                    continue;
                }
                self::writeCell($sheet, $coordinate, $raw, $source->locale);
            }
        }

        $lastColumn = Coordinate::stringFromColumnIndex($columns);
        $lastRow    = count($rows);
        $range      = 'A1:' . $lastColumn . $lastRow;

        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        foreach ($widths as $c => $width) {
            $letter = Coordinate::stringFromColumnIndex($c + 1);
            $sheet->getColumnDimension($letter)->setWidth((float)$width);
            if (isset($wrapped[$c])) {
                $sheet->getStyle($letter . '1:' . $letter . $lastRow)->getAlignment()->setWrapText(true);
            }
        }

        if ($hasHeader) {
            $header = $sheet->getStyle('A1:' . $lastColumn . '1');
            $header->getFont()->setBold(true);
            $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDEDED');
            $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF9E9E9E');
            $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter($range);
        }
        $sheet->setSelectedCell('A1');

        return self::save($book);
    }

    /** Pose une cellule avec le type reconnu par SpreadsheetCell. */
    private static function writeCell(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $coordinate,
        string $raw,
        string $locale
    ): void {
        $cell = SpreadsheetCell::classify($raw, $locale);

        switch ($cell['type']) {
            case SpreadsheetCell::BLANK:
                return;

            case SpreadsheetCell::NUMBER:
            case SpreadsheetCell::PERCENT:
            case SpreadsheetCell::CURRENCY:
            case SpreadsheetCell::DATE:
            case SpreadsheetCell::DATETIME:
                $sheet->setCellValueExplicit($coordinate, $cell['value'], DataType::TYPE_NUMERIC);
                if (($cell['format'] ?? '') !== '') {
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($cell['format']);
                }
                return;

            case SpreadsheetCell::BOOL:
                $sheet->setCellValueExplicit($coordinate, $cell['value'], DataType::TYPE_BOOL);
                return;

            case SpreadsheetCell::FORMULA:
                $sheet->setCellValueExplicit($coordinate, $cell['value'], DataType::TYPE_FORMULA);
                return;

            default:
                $sheet->setCellValueExplicit($coordinate, (string)$cell['value'], DataType::TYPE_STRING);
        }
    }

    /**
     * Écrit le classeur et rend ses octets.
     *
     * Les formules sont PRÉ-CALCULÉES quand c'est possible : le résultat est
     * alors dans le fichier, et l'aperçu d'un téléphone ou de Coup d'œil —
     * qui ne recalculent rien — affiche le total au lieu d'une cellule vide.
     * Si le moteur de calcul bute sur une formule, on écrit sans résultat :
     * Excel recalculera à l'ouverture.
     */
    private static function save(Spreadsheet $book): string
    {
        $path = FileScratch::path('classeur', 'xlsx');
        try {
            $writer = new Xlsx($book);
            try {
                $writer->save($path);
            } catch (\Throwable $e) {
                error_log('[Fichiers] Pré-calcul des formules impossible (' . $e->getMessage() . ') — classeur écrit sans résultats');
                $writer = new Xlsx($book);
                $writer->setPreCalculateFormulas(false);
                $writer->save($path);
            }
            $bytes = (string)file_get_contents($path);
        } finally {
            FileScratch::discard($path);
            $book->disconnectWorksheets();
        }

        if ($bytes === '') {
            throw new RuntimeException('Classeur vide après écriture.');
        }

        return $bytes;
    }

    /**
     * Nom d'onglet : 31 caractères au plus, sans `[ ] : * ? / \` ni apostrophe
     * de bord — Excel refuse d'ouvrir un classeur dont un onglet en porte.
     */
    public static function sheetTitle(string $title): string
    {
        $title = (string)preg_replace('/[\[\]:*?\/\\\\]+/u', ' ', $title);
        $title = trim((string)preg_replace('/\s+/u', ' ', $title), " '");
        $title = mb_substr($title, 0, 31, 'UTF-8');
        $title = trim($title, " '");

        return $title !== '' ? $title : 'Feuille 1';
    }
}
