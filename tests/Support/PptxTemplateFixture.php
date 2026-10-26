<?php

declare(strict_types=1);

/**
 * Modèle PowerPoint de TEST, écrit à la main — pour éprouver PptxTemplate
 * sans dépendre d'un fichier livré par Microsoft (thèmes Office non
 * redistribuables) ni d'une bibliothèque.
 *
 * Il reprend la structure d'un modèle enregistré par PowerPoint : un masque,
 * ses mises en page typées (`title`, `obj`, `secHead`, `twoObj`,
 * `twoTxTwoObj`, `titleOnly`, `blank`), un thème (couleurs, polices), et à la
 * demande des slides d'exemple avec notes, commentaires et sections — ce que
 * PptxTemplate doit retirer sans laisser de référence morte.
 *
 * Les PptxTemplate::fill réels sur des modèles PowerPoint (Berlin, Madison)
 * et LibreOffice ont été rouverts dans PowerPoint et LibreOffice pendant le
 * développement ; ce jeu de test garde la STRUCTURE.
 */
final class PptxTemplateFixture
{
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    private const CT  = 'application/vnd.openxmlformats-officedocument.presentationml.';

    /**
     * @param array{typed?: bool, examples?: int, potx?: bool, macro?: bool, notesMaster?: bool,
     *              traversal?: bool, mainType?: string, solidBackground?: bool} $o
     */
    public static function build(string $path, array $o = []): string
    {
        $typed    = $o['typed'] ?? true;
        $examples = (int)($o['examples'] ?? 0);
        $mainType = $o['mainType'] ?? self::CT . (($o['potx'] ?? false) ? 'template.main+xml' : 'presentation.main+xml');

        $layouts = [
            ['title',       'Diapositive de titre', [['ctrTitle', '', [838200, 1122363, 10515600, 2387600]], ['subTitle', '1', [1524000, 3602038, 9144000, 1655762]], ['dt', '10', null], ['ftr', '11', null], ['sldNum', '12', null]]],
            ['obj',         'Titre et contenu',     [['title', '', null], ['', '1', null], ['dt', '10', null], ['ftr', '11', null], ['sldNum', '12', null]]],
            ['secHead',     'Titre de section',     [['title', '', [831850, 1709738, 10515600, 2852737]], ['body', '1', [831850, 4589463, 10515600, 1500187]], ['ftr', '11', null], ['sldNum', '12', null]]],
            ['twoObj',      'Deux contenus',        [['title', '', null], ['', '1', [838200, 1825625, 5181600, 4351338]], ['', '2', [6172200, 1825625, 5181600, 4351338]], ['ftr', '11', null], ['sldNum', '12', null]]],
            ['twoTxTwoObj', 'Comparaison',          [['title', '', null], ['body', '1', [839788, 1681163, 5157787, 823912]], ['', '2', [839788, 2505075, 5157787, 3684588]], ['body', '3', [6172200, 1681163, 5183188, 823912]], ['', '4', [6172200, 2505075, 5183188, 3684588]], ['ftr', '11', null], ['sldNum', '12', null]]],
            ['titleOnly',   'Titre seul',           [['title', '', null], ['ftr', '11', null], ['sldNum', '12', null]]],
            ['blank',       'Vide',                 [['ftr', '11', null], ['sldNum', '12', null]]],
        ];

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $overrides = [
            '/ppt/presentation.xml'            => $mainType,
            '/ppt/slideMasters/slideMaster1.xml' => self::CT . 'slideMaster+xml',
            '/ppt/theme/theme1.xml'            => 'application/vnd.openxmlformats-officedocument.theme+xml',
            '/docProps/core.xml'               => 'application/vnd.openxmlformats-package.core-properties+xml',
            '/docProps/app.xml'                => 'application/vnd.openxmlformats-officedocument.extended-properties+xml',
        ];

        // Masque.
        $layoutIds = '';
        $masterRels = [];
        foreach ($layouts as $k => [$type, $name, $phs]) {
            $n = $k + 1;
            $layoutIds   .= '<p:sldLayoutId id="' . (2147483649 + $k) . '" r:id="rId' . $n . '"/>';
            $masterRels[] = ['slideLayout', '../slideLayouts/slideLayout' . $n . '.xml'];
            $zip->addFromString('ppt/slideLayouts/slideLayout' . $n . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<p:sldLayout ' . self::NS . ($typed ? ' type="' . $type . '"' : '') . ' preserve="1"><p:cSld name="' . $name . '"><p:spTree>'
                . self::group() . self::shapes($phs) . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
            $zip->addFromString('ppt/slideLayouts/_rels/slideLayout' . $n . '.xml.rels', self::rels([['slideMaster', '../slideMasters/slideMaster1.xml']]));
            $overrides['/ppt/slideLayouts/slideLayout' . $n . '.xml'] = self::CT . 'slideLayout+xml';
        }
        $masterRels[] = ['theme', '../theme/theme1.xml'];
        $bg = ($o['solidBackground'] ?? true)
            ? '<p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>'
            : '<p:bg><p:bgRef idx="1003"><a:schemeClr val="bg2"/></p:bgRef></p:bg>';
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldMaster ' . self::NS . '><p:cSld>' . $bg . '<p:spTree>' . self::group()
            . self::shapes([['title', '', [838200, 365125, 10515600, 1325563]], ['body', '1', [838200, 1825625, 10515600, 4351338]],
                ['dt', '2', [838200, 6356350, 2743200, 365125]], ['ftr', '3', [4038600, 6356350, 4114800, 365125]], ['sldNum', '4', [8610600, 6356350, 2743200, 365125]]])
            . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst>' . $layoutIds . '</p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles></p:sldMaster>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', self::rels($masterRels));
        $zip->addFromString('ppt/theme/theme1.xml', self::theme());

        // Présentation, slides d'exemple.
        $presRels = [['slideMaster', 'slideMasters/slideMaster1.xml'], ['theme', 'theme/theme1.xml']];
        $sldIds = '';
        $sectionIds = '';
        if ($o['notesMaster'] ?? false) {
            $presRels[] = ['notesMaster', 'notesMasters/notesMaster1.xml'];
            $zip->addFromString('ppt/notesMasters/notesMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<p:notesMaster ' . self::NS . '><p:cSld><p:spTree>' . self::group() . '</p:spTree></p:cSld>'
                . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/></p:notesMaster>');
            $zip->addFromString('ppt/notesMasters/_rels/notesMaster1.xml.rels', self::rels([['theme', '../theme/theme1.xml']]));
            $overrides['/ppt/notesMasters/notesMaster1.xml'] = self::CT . 'notesMaster+xml';
        }
        for ($i = 1; $i <= $examples; $i++) {
            $rid = 'rId' . (count($presRels) + 1);
            $presRels[] = ['slide', 'slides/slide' . $i . '.xml'];
            $sldIds     .= '<p:sldId id="' . (255 + $i) . '" r:id="' . $rid . '"/>';
            $sectionIds .= '<p14:sldId id="' . (255 + $i) . '"/>';
            $zip->addFromString('ppt/slides/slide' . $i . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<p:sld ' . self::NS . '><p:cSld><p:spTree>' . self::group()
                . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="T"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:spPr/>'
                . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>Exemple confidentiel ' . $i . '</a:t></a:r></a:p></p:txBody></p:sp>'
                . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');
            $slideRels = [['slideLayout', '../slideLayouts/slideLayout2.xml']];
            if ($o['notesMaster'] ?? false) {
                $slideRels[] = ['notesSlide', '../notesSlides/notesSlide' . $i . '.xml'];
                $zip->addFromString('ppt/notesSlides/notesSlide' . $i . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<p:notes ' . self::NS . '><p:cSld><p:spTree>' . self::group() . '</p:spTree></p:cSld></p:notes>');
                $zip->addFromString('ppt/notesSlides/_rels/notesSlide' . $i . '.xml.rels', self::rels([
                    ['notesMaster', '../notesMasters/notesMaster1.xml'], ['slide', '../slides/slide' . $i . '.xml'],
                ]));
                $overrides['/ppt/notesSlides/notesSlide' . $i . '.xml'] = self::CT . 'notesSlide+xml';
            }
            $slideRels[] = ['comments', '../comments/comment' . $i . '.xml'];
            $zip->addFromString('ppt/comments/comment' . $i . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:cmLst ' . self::NS . '/>');
            $overrides['/ppt/comments/comment' . $i . '.xml'] = self::CT . 'comments+xml';
            $zip->addFromString('ppt/slides/_rels/slide' . $i . '.xml.rels', self::rels($slideRels));
            $overrides['/ppt/slides/slide' . $i . '.xml'] = self::CT . 'slide+xml';
        }
        if ($o['traversal'] ?? false) {
            $presRels[] = ['presProps', '../../../etc/passwd'];
        }

        $sections = $examples > 0
            ? '<p:extLst><p:ext uri="{521415D9-36F7-43E2-AB2F-B90AF26B5E84}"><p14:sectionLst xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main">'
                . '<p14:section name="Exemples" id="{11111111-2222-3333-4444-555555555555}"><p14:sldIdLst>' . $sectionIds . '</p14:sldIdLst></p14:section>'
                . '</p14:sectionLst></p:ext></p:extLst>'
            : '';
        $notesMasterIds = ($o['notesMaster'] ?? false) ? '<p:notesMasterIdLst><p:notesMasterId r:id="rId3"/></p:notesMasterIdLst>' : '';
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentation ' . self::NS . ' saveSubsetFonts="1"><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            . $notesMasterIds
            . ($sldIds !== '' ? '<p:sldIdLst>' . $sldIds . '</p:sldIdLst>' : '')
            . '<p:custShowLst><p:custShow name="Court" id="0"><p:sldLst/></p:custShow></p:custShowLst>'
            . '<p:sldSz cx="12192000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/><p:defaultTextStyle/>' . $sections . '</p:presentation>');
        $zip->addFromString('ppt/_rels/presentation.xml.rels', self::rels($presRels));

        if ($o['macro'] ?? false) {
            $zip->addFromString('ppt/vbaProject.bin', 'macro');
        }
        $zip->addFromString('_rels/.rels', self::rels([['officeDocument', 'ppt/presentation.xml']], true));
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Modèle maison</dc:title></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Slides>' . $examples . '</Slides></Properties>');

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>';
        foreach ($overrides as $part => $type) {
            $types .= '<Override PartName="' . $part . '" ContentType="' . $type . '"/>';
        }
        $zip->addFromString('[Content_Types].xml', $types . '</Types>');
        $zip->close();

        return $path;
    }

    private static function group(): string
    {
        return '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
    }

    /** @param list<array{0: string, 1: string, 2: list<int>|null}> $phs */
    private static function shapes(array $phs): string
    {
        $out = '';
        foreach ($phs as $k => [$type, $idx, $box]) {
            $attrs = ($type !== '' ? ' type="' . $type . '"' : '') . ($idx !== '' ? ' idx="' . $idx . '"' : '');
            $xfrm  = $box !== null
                ? '<a:xfrm><a:off x="' . $box[0] . '" y="' . $box[1] . '"/><a:ext cx="' . $box[2] . '" cy="' . $box[3] . '"/></a:xfrm>'
                : '';
            $out .= '<p:sp><p:nvSpPr><p:cNvPr id="' . ($k + 2) . '" name="ph' . ($k + 2) . '"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
                . '<p:nvPr><p:ph' . $attrs . '/></p:nvPr></p:nvSpPr><p:spPr>' . $xfrm . '</p:spPr>'
                . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="fr-FR"/></a:p></p:txBody></p:sp>';
        }

        return $out;
    }

    /** @param list<array{0: string, 1: string}> $rels */
    private static function rels(array $rels, bool $package = false): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($rels as $k => [$type, $target]) {
            $out .= '<Relationship Id="rId' . ($k + 1) . '" Type="' . self::REL . $type . '" Target="' . $target . '"/>';
        }

        return $out . '</Relationships>';
    }

    private static function theme(): string
    {
        $clr = static fn (string $n, string $hex): string => '<a:' . $n . '><a:srgbClr val="' . $hex . '"/></a:' . $n . '>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Maison"><a:themeElements>'
            . '<a:clrScheme name="Maison"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
            . $clr('dk2', '1F3864') . $clr('lt2', 'E7E6E6') . $clr('accent1', '0055A4') . $clr('accent2', 'ED7D31') . $clr('accent3', 'A5A5A5')
            . $clr('accent4', 'FFC000') . $clr('accent5', '5B9BD5') . $clr('accent6', '70AD47') . $clr('hlink', '0563C1') . $clr('folHlink', '954F72')
            . '</a:clrScheme><a:fontScheme name="Maison"><a:majorFont><a:latin typeface="Georgia"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="Maison"><a:fillStyleLst/><a:lnStyleLst/><a:effectStyleLst/><a:bgFillStyleLst/></a:fmtScheme>'
            . '</a:themeElements></a:theme>';
    }
}
