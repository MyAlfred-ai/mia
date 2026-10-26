<?php

declare(strict_types=1);

/**
 * Modèle Word et document fabriqué de TEST, écrits à la main — pour éprouver
 * DocxTemplate sans fichier livré par Microsoft (modèles Office non
 * redistribuables) ni PhpWord (absent de vendor/ sur certains postes).
 *
 * Le MODÈLE reprend ce que Word enregistre et ce que DocxTemplate doit gérer :
 * styles de titre à l'identifiant FRANÇAIS (`Titre1`, nom interne
 * « heading 1 »), listes à identifiants non compacts, en-tête avec image,
 * contenu d'exemple, commentaires, protection en écriture, thème, miniature.
 *
 * Le DOCUMENT FABRIQUÉ reprend la forme de ce que produit PhpWord : titres en
 * `Heading1`/`Heading2`, une liste (`numId` 1), un lien externe, une image.
 *
 * Les greffes réelles sur des modèles Word de Microsoft ont été rendues par
 * LibreOffice pendant le développement ; ce jeu de test garde la STRUCTURE.
 */
final class DocxTemplateFixture
{
    private const W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    private const CT  = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';

    /** Une image PNG de 1 × 1 pixel. */
    public const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    /**
     * @param array{dotx?: bool, macro?: bool, footer?: bool, numbering?: bool, backslashes?: bool,
     *              mainType?: string, thumbnail?: bool} $o
     */
    public static function template(string $path, array $o = []): string
    {
        $mainType = $o['mainType'] ?? self::CT . (($o['dotx'] ?? true) ? 'template.main+xml' : 'document.main+xml');
        $footer   = $o['footer'] ?? false;
        $numbering = $o['numbering'] ?? true;

        $docRels = [
            ['rId1', 'styles', 'styles.xml'],
            ['rId2', 'settings', 'settings.xml'],
            ['rId3', 'theme', 'theme/theme1.xml'],
            ['rId4', 'header', 'header1.xml'],
            ['rId5', 'comments', 'comments.xml'],
        ];
        if ($numbering) {
            $docRels[] = ['rId6', 'numbering', 'numbering.xml'];
        }
        if ($footer) {
            $docRels[] = ['rId7', 'footer', 'footer1.xml'];
        }

        $overrides = [
            '/word/document.xml' => $mainType,
            '/word/styles.xml'   => self::CT . 'styles+xml',
            '/word/settings.xml' => self::CT . 'settings+xml',
            '/word/header1.xml'  => self::CT . 'header+xml',
            '/word/comments.xml' => self::CT . 'comments+xml',
            '/word/theme/theme1.xml' => 'application/vnd.openxmlformats-officedocument.theme+xml',
            '/docProps/core.xml' => 'application/vnd.openxmlformats-package.core-properties+xml',
        ];
        if ($numbering) {
            $overrides['/word/numbering.xml'] = self::CT . 'numbering+xml';
        }
        if ($footer) {
            $overrides['/word/footer1.xml'] = self::CT . 'footer+xml';
        }

        $files = [
            '[Content_Types].xml' => self::contentTypes($overrides, ['png' => 'image/png', 'jpeg' => 'image/jpeg']),
            '_rels/.rels'         => self::rels([
                ['rId1', 'officeDocument', 'word/document.xml'],
                ['rId2', '../package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
            ] + (($o['thumbnail'] ?? false) ? [2 => ['rId3', '../package/2006/relationships/metadata/thumbnail', 'docProps/thumbnail.jpeg']] : [])),
            'word/_rels/document.xml.rels' => self::rels($docRels),
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:body>'
                . '<w:p><w:pPr><w:pStyle w:val="Titre"/></w:pPr><w:r><w:t>Titre d’exemple du modèle</w:t></w:r></w:p>'
                . '<w:p><w:commentRangeStart w:id="0"/><w:r><w:t>Texte d’exemple à remplacer.</w:t></w:r><w:commentRangeEnd w:id="0"/>'
                . '<w:r><w:commentReference w:id="0"/></w:r></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Titre1"/></w:pPr><w:r><w:t>Section d’exemple</w:t></w:r></w:p>'
                . '<w:sectPr><w:headerReference w:type="default" r:id="rId4"/>'
                . ($footer ? '<w:footerReference w:type="default" r:id="rId7"/>' : '')
                . '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1418" w:right="1134" w:bottom="1418" w:left="1134" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr>'
                . '</w:body></w:document>',
            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:styles xmlns:w="' . self::W . '">'
                . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Georgia"/></w:rPr></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Titre"><w:name w:val="Title"/><w:basedOn w:val="Normal"/></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Titre1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:rPr><w:color w:val="C00000"/></w:rPr></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Titre2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/></w:style>'
                . '</w:styles>',
            'word/settings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:settings xmlns:w="' . self::W . '"><w:documentProtection w:edit="readOnly" w:enforcement="1"/><w:defaultTabStop w:val="708"/></w:settings>',
            'word/header1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:hdr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:p><w:r><w:drawing/></w:r><w:r><w:t>ACME</w:t></w:r></w:p></w:hdr>',
            'word/comments.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:comments xmlns:w="' . self::W . '"><w:comment w:id="0" w:author="Modèle"><w:p><w:r><w:t>Remplacez ce texte.</w:t></w:r></w:p></w:comment></w:comments>',
            'word/theme/theme1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Acme"><a:themeElements>'
                . '<a:clrScheme name="Acme"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
                . '<a:dk2><a:srgbClr val="1F2A44"/></a:dk2><a:lt2><a:srgbClr val="EEEEEE"/></a:lt2><a:accent1><a:srgbClr val="C00000"/></a:accent1>'
                . '<a:accent2><a:srgbClr val="0070C0"/></a:accent2></a:clrScheme>'
                . '<a:fontScheme name="Acme"><a:majorFont><a:latin typeface="Georgia"/></a:majorFont><a:minorFont><a:latin typeface="Verdana"/></a:minorFont></a:fontScheme>'
                . '</a:themeElements></a:theme>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:title>Modèle Acme</dc:title><dc:creator>Service communication</dc:creator></cp:coreProperties>',
        ];
        if ($numbering) {
            // Identifiants NON compacts, comme dans bien des modèles.
            $files['word/numbering.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:numbering xmlns:w="' . self::W . '">'
                . '<w:abstractNum w:abstractNumId="5"><w:nsid w:val="11111111"/><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/><w:lvlText w:val="–"/></w:lvl></w:abstractNum>'
                . '<w:num w:numId="3"><w:abstractNumId w:val="5"/></w:num>'
                . '</w:numbering>';
        }
        if ($footer) {
            $files['word/footer1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:ftr xmlns:w="' . self::W . '"><w:p><w:r><w:t>Pied du modèle</w:t></w:r></w:p></w:ftr>';
        }
        if ($o['thumbnail'] ?? false) {
            $files['docProps/thumbnail.jpeg'] = "\xFF\xD8\xFF\xE0" . str_repeat("\0", 32);
        }
        if ($o['macro'] ?? false) {
            $files['word/vbaProject.bin'] = 'macro';
        }

        return self::zip($path, $files, $o['backslashes'] ?? false);
    }

    /** Document « fabriqué », de la forme de ce que produit PhpWord. */
    public static function generated(): string
    {
        $path = FileScratch::path('corps-test', 'docx');
        self::zip($path, [
            '[Content_Types].xml' => self::contentTypes([
                '/word/document.xml'  => self::CT . 'document.main+xml',
                '/word/styles.xml'    => self::CT . 'styles+xml',
                '/word/numbering.xml' => self::CT . 'numbering+xml',
            ], ['png' => 'image/png']),
            '_rels/.rels' => self::rels([['rId1', 'officeDocument', 'word/document.xml']]),
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . self::REL . 'styles" Target="styles.xml"/>'
                . '<Relationship Id="rId2" Type="' . self::REL . 'numbering" Target="numbering.xml"/>'
                . '<Relationship Id="rId7" Type="' . self::REL . 'hyperlink" Target="https://exemple.fr/?a=1&amp;b=2" TargetMode="External"/>'
                . '<Relationship Id="rId8" Type="' . self::REL . 'image" Target="media/image1.png"/>'
                . '</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '" '
                . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
                . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'
                . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Rapport généré</w:t></w:r></w:p>'
                . '<w:p><w:r><w:t xml:space="preserve">Voir </w:t></w:r><w:hyperlink r:id="rId7"><w:r><w:t>le site</w:t></w:r></w:hyperlink></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Détail</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Heading4"/></w:pPr><w:r><w:t>Niveau 4</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Premier point</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Second point</w:t>'
                . '<w:footnoteReference w:id="9"/></w:r></w:p>'
                . '<w:p><w:r><w:drawing><a:graphic><a:graphicData><pic:pic><pic:blipFill><a:blip r:embed="rId8"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></w:drawing></w:r></w:p>'
                . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr>'
                . '</w:body></w:document>',
            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:styles xmlns:w="' . self::W . '">'
                . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Heading4"><w:name w:val="heading 4"/><w:basedOn w:val="Heading9"/><w:rPr><w:b/></w:rPr></w:style>'
                . '</w:styles>',
            'word/numbering.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:numbering xmlns:w="' . self::W . '">'
                . '<w:abstractNum w:abstractNumId="0"><w:nsid w:val="22222222"/><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/></w:lvl></w:abstractNum>'
                . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
                . '</w:numbering>',
            'word/media/image1.png' => self::PNG,
        ]);
        $bytes = (string)file_get_contents($path);
        FileScratch::discard($path);

        return $bytes;
    }

    private static function contentTypes(array $overrides, array $defaults): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>';
        foreach ($defaults as $ext => $type) {
            $xml .= '<Default Extension="' . $ext . '" ContentType="' . $type . '"/>';
        }
        foreach ($overrides as $part => $type) {
            $xml .= '<Override PartName="' . $part . '" ContentType="' . $type . '"/>';
        }

        return $xml . '</Types>';
    }

    /** @param array<int, array{0: string, 1: string, 2: string}> $rels */
    private static function rels(array $rels): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($rels as [$id, $type, $target]) {
            $full = str_starts_with($type, '../') ? 'http://schemas.openxmlformats.org/' . substr($type, 3) : self::REL . $type;
            $xml .= '<Relationship Id="' . $id . '" Type="' . $full . '" Target="' . $target . '"/>';
        }

        return $xml . '</Relationships>';
    }

    /** @param array<string, string> $files */
    private static function zip(string $path, array $files, bool $backslashes = false): string
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($backslashes ? str_replace('/', '\\', $name) : $name, $content);
        }
        $zip->close();

        return $path;
    }
}
