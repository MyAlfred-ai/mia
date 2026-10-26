<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/DocxTemplateFixture.php';

/**
 * DocxTemplate — le modèle Word de l'entreprise, analysé puis greffé.
 *
 * Le contrat qui compte : le document produit est un paquet OOXML COHÉRENT
 * (chaque relation pointe vers une partie qui existe, chaque partie XML se
 * relit) qui porte le corps fabriqué dans les styles, la mise en page et les
 * en-têtes du modèle — et plus rien de son contenu d'exemple. Un paquet
 * incohérent, Word l'ouvre après un message « contenu illisible » : pour un
 * client, c'est un fichier cassé.
 */
final class DocxTemplateTest extends TestCase
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive indisponible.');
        }
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            FileScratch::discard($file);
        }
        CompanyProfile::overrideWordTemplate(null);
    }

    private function template(array $o = []): string
    {
        $path = FileScratch::path('modele-word', ($o['dotx'] ?? true) ? 'dotx' : 'docx');
        $this->files[] = $path;

        return DocxTemplateFixture::template($path, $o);
    }

    /** @return array{zip: ZipArchive, path: string} */
    private function fill(array $o = [], array $options = []): array
    {
        $bytes = DocxTemplate::fill($this->template($o), DocxTemplateFixture::generated(), $options + ['title' => 'Rapport généré', 'author' => 'Alex']);
        $path  = FileScratch::path('greffe', 'docx');
        $this->files[] = $path;
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertPackageConsistent($zip);

        return ['zip' => $zip, 'path' => $path];
    }

    /** Chaque partie XML se relit ; chaque relation interne pointe vers une partie présente. */
    private function assertPackageConsistent(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $this->assertStringNotContainsString('\\', $name);
            if (preg_match('/\.(xml|rels)$/', $name) === 1) {
                $doc = new DOMDocument();
                $this->assertTrue(@$doc->loadXML((string)$zip->getFromName($name)), "XML illisible : $name");
            }
            if (str_ends_with($name, '.rels')) {
                $source = $name === '_rels/.rels' ? '' : (string)preg_replace('#_rels/(.*)\.rels$#', '$1', $name);
                $doc = new DOMDocument();
                $doc->loadXML((string)$zip->getFromName($name));
                foreach ($doc->getElementsByTagName('Relationship') as $rel) {
                    if (strtolower($rel->getAttribute('TargetMode')) === 'external') {
                        continue;
                    }
                    $target = OfficePackage::resolve($source, $rel->getAttribute('Target'));
                    $this->assertNotFalse($zip->locateName($target), "Relation morte : $name → $target");
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Analyse
    // ------------------------------------------------------------------

    public function testAnalyseUnModele(): void
    {
        $info = DocxTemplate::analyze($this->template());

        $this->assertTrue($info['ok']);
        $this->assertSame('A4 portrait', $info['page']);
        $this->assertSame(1, $info['headers']);
        $this->assertSame(0, $info['footers']);
        $this->assertTrue($info['header_image'], 'L\'en-tête porte une image (le logo).');
        $this->assertSame(['title' => true, 'heading 1' => true, 'heading 2' => true, 'heading 3' => false], $info['styles'],
            'Styles retrouvés par leur NOM interne, quel que soit leur identifiant (Titre1).');
        $this->assertSame(['major' => 'Georgia', 'minor' => 'Verdana'], $info['fonts']);
        $this->assertSame('#c00000', $info['colors']['accent1']);
        $this->assertSame('#000000', $info['colors']['dk1'], 'Couleur système : sa dernière valeur.');
        $this->assertSame(3, $info['content']);
    }

    public function testRefusDesFichiersInexploitables(): void
    {
        $macro = DocxTemplate::analyze($this->template(['macro' => true]));
        $this->assertFalse($macro['ok']);
        $this->assertStringContainsString('macros', $macro['error']);

        $pptx = DocxTemplate::analyze($this->template(['mainType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml']));
        $this->assertFalse($pptx['ok']);
        $this->assertStringContainsString('pas un document Word', $pptx['error']);

        $enabled = DocxTemplate::analyze($this->template(['mainType' => 'application/vnd.ms-word.template.macroEnabledTemplate.main+xml']));
        $this->assertFalse($enabled['ok']);

        $path = FileScratch::path('pas-un-zip', 'docx');
        $this->files[] = $path;
        file_put_contents($path, 'texte');
        $this->assertFalse(DocxTemplate::analyze($path)['ok']);
    }

    /** Certains paquets nomment leurs parties avec des barres inverses : ils sont lus quand même. */
    public function testBarresInversesRenommees(): void
    {
        $info = DocxTemplate::analyze($this->template(['backslashes' => true]));
        $this->assertTrue($info['ok']);
    }

    public function testLibelleDePage(): void
    {
        $this->assertSame('A4 portrait', DocxTemplate::pageLabel(['w' => 11906, 'h' => 16838, 'orient' => 'portrait']));
        $this->assertSame('A4 paysage', DocxTemplate::pageLabel(['w' => 16838, 'h' => 11906, 'orient' => 'landscape']));
        $this->assertSame('Lettre US portrait', DocxTemplate::pageLabel(['w' => 12240, 'h' => 15840]));
        $this->assertSame('10,0 × 20,0 cm portrait', DocxTemplate::pageLabel(['w' => 5670, 'h' => 11340]));
    }

    // ------------------------------------------------------------------
    // Greffe
    // ------------------------------------------------------------------

    public function testLeCorpsPrendLaPlaceDuContenuDExemple(): void
    {
        ['zip' => $zip] = $this->fill();
        $doc = (string)$zip->getFromName('word/document.xml');

        $this->assertStringContainsString('Rapport généré', $doc);
        $this->assertStringNotContainsString('Texte d’exemple', $doc);
        $this->assertStringNotContainsString('Section d’exemple', $doc);
        $this->assertStringNotContainsString('commentReference', $doc);
        $this->assertStringNotContainsString('footnoteReference', $doc, 'Un renvoi vers les notes du modèle serait faux.');
        // Mise en page et en-tête du modèle gardés.
        $this->assertStringContainsString('<w:headerReference w:type="default" r:id="rId4"/>', $doc);
        $this->assertStringContainsString('w:left="1134"', $doc);
        $this->assertStringNotContainsString('<w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>', $doc,
            'La section du document fabriqué n\'est pas reprise quand le modèle a la sienne.');
        $zip->close();
    }

    public function testUnDotxDevientUnDocument(): void
    {
        ['zip' => $zip] = $this->fill(['dotx' => true]);
        $ct = (string)$zip->getFromName('[Content_Types].xml');
        $this->assertStringContainsString('PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"', $ct);
        $this->assertStringNotContainsString('template.main+xml', $ct);
        $zip->close();
    }

    /** Titres → styles du modèle par NOM (Titre1), style absent recopié sans héritage mort. */
    public function testLesTitresPrennentLesStylesDuModele(): void
    {
        ['zip' => $zip] = $this->fill();
        $doc = (string)$zip->getFromName('word/document.xml');
        $this->assertStringContainsString('<w:pStyle w:val="Titre1"/>', $doc);
        $this->assertStringContainsString('<w:pStyle w:val="Titre2"/>', $doc);
        $this->assertStringNotContainsString('w:val="Heading1"', $doc);

        $styles = (string)$zip->getFromName('word/styles.xml');
        $this->assertStringContainsString('w:styleId="Heading4"', $styles, 'Style absent du modèle : recopié.');
        $this->assertStringNotContainsString('Heading9', $styles, 'Un héritage vers un style inconnu du modèle est retiré.');
        $this->assertSame(1, substr_count($styles, 'w:styleId="Titre1"'));
        $zip->close();
    }

    /** Listes fusionnées, identifiants décalés, définitions abstraites compactes. */
    public function testLesListesSontFusionneesDansCellesDuModele(): void
    {
        ['zip' => $zip] = $this->fill();
        $num = new DOMDocument();
        $num->loadXML((string)$zip->getFromName('word/numbering.xml'));
        $abstract = [];
        foreach ($num->getElementsByTagNameNS(self::W, 'abstractNum') as $a) {
            $abstract[] = (int)$a->getAttributeNS(self::W, 'abstractNumId');
        }
        $this->assertSame([0, 1], $abstract, 'Définitions renumérotées par position (aperçu d\'Apple).');

        $nums = [];
        foreach ($num->getElementsByTagNameNS(self::W, 'num') as $n) {
            $nums[(int)$n->getAttributeNS(self::W, 'numId')] = (int)$n->getElementsByTagNameNS(self::W, 'abstractNumId')->item(0)->getAttributeNS(self::W, 'val');
        }
        $this->assertSame([3 => 0, 4 => 1], $nums, 'L\'instance du modèle garde son numéro ; celle du document vient après.');

        $doc = (string)$zip->getFromName('word/document.xml');
        $this->assertSame(2, substr_count($doc, '<w:numId w:val="4"/>'));
        $this->assertStringNotContainsString('22222222', (string)$zip->getFromName('word/numbering.xml'), 'Identifiant de liste (nsid) renouvelé.');
        $zip->close();
    }

    public function testModeleSansListesRecoitCellesDuDocument(): void
    {
        ['zip' => $zip] = $this->fill(['numbering' => false]);
        $this->assertNotFalse($zip->locateName('word/numbering.xml'));
        $this->assertStringContainsString('numbering+xml', (string)$zip->getFromName('[Content_Types].xml'));
        $this->assertStringContainsString('/numbering"', (string)$zip->getFromName('word/_rels/document.xml.rels'));
        $zip->close();
    }

    public function testLiensEtImagesRecopies(): void
    {
        ['zip' => $zip] = $this->fill();
        $rels = (string)$zip->getFromName('word/_rels/document.xml.rels');
        $doc  = (string)$zip->getFromName('word/document.xml');

        $this->assertMatchesRegularExpression('#Id="(rIdMia\d+)"[^>]*Target="https://exemple\.fr/\?a=1&amp;b=2"[^>]*TargetMode="External"#', $rels);
        preg_match('#<w:hyperlink r:id="([^"]+)"#', $doc, $m);
        $this->assertStringStartsWith('rIdMia', $m[1] ?? '');

        preg_match('#<a:blip r:embed="([^"]+)"#', $doc, $img);
        $this->assertStringStartsWith('rIdMia', $img[1] ?? '');
        $this->assertNotFalse($zip->locateName('word/media/mia-image.png'));
        $this->assertSame(DocxTemplateFixture::PNG, $zip->getFromName('word/media/mia-image.png'));
        $zip->close();
    }

    public function testCommentairesEtProtectionRetires(): void
    {
        ['zip' => $zip] = $this->fill();
        $this->assertFalse($zip->locateName('word/comments.xml'));
        $this->assertStringNotContainsString('/comments"', (string)$zip->getFromName('word/_rels/document.xml.rels'));
        $this->assertStringNotContainsString('comments.xml', (string)$zip->getFromName('[Content_Types].xml'));
        $this->assertStringNotContainsString('documentProtection', (string)$zip->getFromName('word/settings.xml'));
        $zip->close();
    }

    public function testProprietesDuDocument(): void
    {
        ['zip' => $zip] = $this->fill(['thumbnail' => true]);
        $core = (string)$zip->getFromName('docProps/core.xml');
        $this->assertStringContainsString('<dc:title>Rapport généré</dc:title>', $core);
        $this->assertStringContainsString('<dc:creator>Alex</dc:creator>', $core);
        $this->assertStringContainsString('dcterms:modified', $core);
        $this->assertFalse($zip->locateName('docProps/thumbnail.jpeg'), 'La miniature du modèle ne montrerait pas ce document.');
        $zip->close();
    }

    /** Modèle sans pied de page : celui de MIA (mention, numéro de page). Avec : le sien. */
    public function testPiedDePage(): void
    {
        ['zip' => $zip] = $this->fill([], ['footer' => 'Acme SA · SIRET 123 · Document interne']);
        $doc = (string)$zip->getFromName('word/document.xml');
        $this->assertMatchesRegularExpression('#<w:headerReference[^>]*/><w:footerReference w:type="default" r:id="rIdMia\d+"/>#', $doc,
            'Renvois d\'en-tête puis de pied, en tête de section.');
        $footer = (string)$zip->getFromName('word/footer-mia.xml');
        $this->assertStringContainsString('Acme SA · SIRET 123 · Document interne', $footer);
        $this->assertStringContainsString(' PAGE ', $footer);
        $this->assertStringContainsString('footer+xml', (string)$zip->getFromName('[Content_Types].xml'));
        $zip->close();

        ['zip' => $zip] = $this->fill(['footer' => true], ['footer' => 'Acme SA']);
        $this->assertFalse($zip->locateName('word/footer-mia.xml'), 'Un modèle qui a son pied de page le garde.');
        $this->assertStringContainsString('Pied du modèle', (string)$zip->getFromName('word/footer1.xml'));
        $zip->close();
    }

    public function testMiniatureExtraiteSeulementSiLisible(): void
    {
        $with = $this->template(['thumbnail' => true]);
        $thumb = OfficePackage::thumbnail($with);
        $this->assertSame('jpg', $thumb['ext'] ?? null);
        $this->assertNull(OfficePackage::thumbnail($this->template()));
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    /** Les deux chemins Word passent par l'habillage de l'entreprise. */
    public function testCablageDesDocumentsWord(): void
    {
        $root = dirname(__DIR__, 2);
        $builder = (string)file_get_contents($root . '/src/Files/DocxFileBuilder.php');
        $this->assertStringContainsString('CompanyDocx::addChrome($section);', $builder);
        $this->assertSame(2, substr_count($builder, 'CompanyDocx::dress('), 'Markdown ET HTML.');

        $export = (string)file_get_contents($root . '/src/Helpers/DocxExportService.php');
        $this->assertStringContainsString('if ($companyChrome && class_exists(\'CompanyDocx\'))', $export);

        $chat = (string)file_get_contents($root . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString("CompanyDocx::dress(\n                (new DocxExportService())->buildFromHtml(\$html, true),", $chat);
        $this->assertStringContainsString("buildFromHtml(\$html, 'Export message', CompanyProfile::pdfChrome())", $chat);

        // Le rapport d'usage de l'administration n'est pas un document de l'entreprise.
        $admin = (string)file_get_contents($root . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString("buildFromHtml(\$html, t('Rapport d\\'utilisation des crédits'));", $admin);

        $docx = (string)file_get_contents($root . '/src/Files/CompanyDocx.php');
        $this->assertStringContainsString("'footer' => CompanyProfile::documentFooter(),", $docx);
        $this->assertStringContainsString("return \$bytes;", $docx, 'Un modèle abîmé ne prive jamais du document.');

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $file) {
            $src = (string)file_get_contents($root . '/' . $file);
            $package = strpos($src, '/src/Files/OfficePackage.php');
            $template = strpos($src, '/src/Files/DocxTemplate.php');
            $this->assertNotFalse($package, $file);
            $this->assertNotFalse(strpos($src, '/src/Files/CompanyDocx.php'), $file);
            $this->assertLessThan($template, $package, $file);
        }
    }
}
