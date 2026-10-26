<?php

declare(strict_types=1);

/**
 * Générateurs de fichiers fixtures pour les tests RAG : produit des documents
 * TXT / MD / DOCX / XLSX / PDF valides (lus par FileExtractor) à la volée, sans
 * committer de binaires. Le PDF utilise des opérateurs Tj non compressés afin
 * d'être lisible par le fallback natif (sans smalot/pdfparser ni poppler).
 */
final class RagFixtures
{
    public static function txt(string $path, string $text): void
    {
        file_put_contents($path, $text);
    }

    /** @param list<string> $paragraphs */
    public static function docx(string $path, array $paragraphs): void
    {
        $body = '';
        foreach ($paragraphs as $p) {
            $body .= '<w:p><w:r><w:t xml:space="preserve">'
                   . htmlspecialchars($p, ENT_XML1) . '</w:t></w:r></w:p>';
        }
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
             . '<w:body>' . $body . '</w:body></w:document>';
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
              . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
              . '</Relationships>';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $doc);
        $zip->close();
    }

    /** @param list<list<string>> $rows */
    public static function xlsx(string $path, array $rows): void
    {
        $shared = [];
        $index  = [];
        foreach ($rows as $r) {
            foreach ($r as $v) {
                if (!isset($index[$v])) {
                    $index[$v] = count($shared);
                    $shared[]  = $v;
                }
            }
        }
        $si = '';
        foreach ($shared as $s) {
            $si .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
        }
        $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($shared) . '" uniqueCount="' . count($shared) . '">' . $si . '</sst>';

        $sheetRows = '';
        $rowNum    = 1;
        foreach ($rows as $r) {
            $cells  = '';
            $colIdx = 0;
            foreach ($r as $v) {
                $col    = chr(ord('A') + $colIdx);
                $cells .= '<c r="' . $col . $rowNum . '" t="s"><v>' . $index[$v] . '</v></c>';
                $colIdx++;
            }
            $sheetRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
            $rowNum++;
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
               . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('xl/sharedStrings.xml', $ss);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }

    /** @param list<string> $lines */
    public static function pdf(string $path, array $lines): void
    {
        $stream = "BT /F1 12 Tf 72 720 Td\n";
        foreach ($lines as $ln) {
            $esc = strtr($ln, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
            $stream .= "($esc) Tj 0 -14 Td\n";
        }
        $stream .= 'ET';

        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
               . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $bodyObj) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $bodyObj . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf    .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($objs as $n => $bodyObj) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\n"
              . "startxref\n" . $xrefPos . "\n%%EOF";

        file_put_contents($path, $pdf);
    }

    public static function html(string $path, string $body): void
    {
        file_put_contents($path, '<!doctype html><html><head><title>t</title></head><body>' . $body . '</body></html>');
    }

    /** RTF minimal : \par entre lignes, texte ASCII. */
    public static function rtf(string $path, string $text): void
    {
        file_put_contents($path, '{\\rtf1\\ansi\\deff0 ' . str_replace("\n", '\\par ', $text) . '\\par}');
    }

    /** PPTX minimal : une diapositive par entrée, texte dans <a:t>. @param list<string> $slides */
    public static function pptx(string $path, array $slides): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $i = 1;
        foreach ($slides as $text) {
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
                 . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
                 . '<p:cSld><p:spTree><a:p><a:r><a:t>'
                 . htmlspecialchars($text, ENT_XML1)
                 . '</a:t></a:r></a:p></p:spTree></p:cSld></p:sld>';
            $zip->addFromString("ppt/slides/slide$i.xml", $xml);
            $i++;
        }
        $zip->close();
    }

    /** OpenDocument (ODT/ODS/ODP) minimal : mimetype + content.xml. @param list<string> $paragraphs */
    public static function odf(string $path, string $odfMime, array $paragraphs): void
    {
        $body = '';
        foreach ($paragraphs as $p) {
            $body .= '<text:p>' . htmlspecialchars($p, ENT_XML1) . '</text:p>';
        }
        $content = '<?xml version="1.0" encoding="UTF-8"?>'
                 . '<office:document-content '
                 . 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
                 . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
                 . '<office:body><office:text>' . $body . '</office:text></office:body>'
                 . '</office:document-content>';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', $odfMime);
        $zip->addFromString('content.xml', $content);
        $zip->close();
    }

    /** EPUB minimal : mimetype + chapitres XHTML. @param list<string> $chaptersHtml */
    public static function epub(string $path, array $chaptersHtml): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $i = 1;
        foreach ($chaptersHtml as $html) {
            $zip->addFromString("OEBPS/chap$i.xhtml", '<html><body>' . $html . '</body></html>');
            $i++;
        }
        $zip->close();
    }

    /** Répète un motif jusqu'à environ $targetBytes. */
    public static function inflate(string $pattern, int $targetBytes): string
    {
        $reps = max(1, (int) ceil($targetBytes / max(1, strlen($pattern))));
        return str_repeat($pattern, $reps);
    }
}
