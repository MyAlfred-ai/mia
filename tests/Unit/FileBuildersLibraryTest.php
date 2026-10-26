<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PptxTemplateFixture.php';
require_once __DIR__ . '/../Support/DocxTemplateFixture.php';

/**
 * Fabricants qui dépendent de vendor/ : classeur Excel (PhpSpreadsheet),
 * document Word (PhpWord), PDF (Dompdf), présentation PowerPoint
 * (PHPPresentation). Chaque fichier fabriqué est RELU —
 * par la même bibliothèque, ou en ouvrant l'archive — plutôt que comparé à
 * une empreinte : ce qui compte est ce qu'un tableur ou un traitement de
 * texte y trouvera.
 *
 * Le bootstrap des tests ne charge pas vendor/. Cette classe le charge
 * elle-même : `MIA_TEST_VENDOR` (dossier vendor d'une image, par exemple),
 * sinon `vendor/` du dépôt. Une bibliothèque absente SKIPPE ses tests — le
 * dépôt ne versionne qu'une partie de vendor/ ; ils tournent dans le
 * conteneur applicatif et sur un poste où `composer install` a été lancé.
 */
final class FileBuildersLibraryTest extends TestCase
{
    private const BUDGET = <<<'CSV'
        Poste;Catégorie;Montant HT;TVA;Échéance;Payé;Référence;Total
        Loyer;Charges fixes;1 200,00 €;20 %;2026-10-05;VRAI;01000;=C2*(1+D2)
        Licences;Informatique;4200,5;20%;15/10/2026;FAUX;REF-0042;=C3*(1+D3)
        Lien;Piège;0;0 %;;FAUX;=HYPERLINK("http://evil");
        Total;;=SOMME(C2:C4);;;;;=SOMME(H2:H3)
        CSV;

    private const COURRIER = <<<'MD'
        # Compte rendu — Projet Élan

        **Date :** 30 septembre 2026
        Lieu : Nantes

        ## Participants

        - Alex Martin (*direction*)
        - Zoé Lefèvre
          - suppléante : Inès

        ## Décisions

        1. Lancer la phase 2
        2. Voir [le budget](https://exemple.test/budget)

        | Poste | Montant |
        |:------|--------:|
        | Loyer | 1 200 € |

        > Une citation.

        ```
        ligne 1
        ligne 2
        ```

        3. Une seconde liste numérotée
        4. qui continue
        MD;

    private const PRESENTATION = <<<'MD'
        # Plan stratégique 2027

        Comité de direction

        ## Contexte

        - Marché en croissance de **8 %**
        - Deux concurrents ont levé des fonds 🚀

        Notes : insister sur le concurrent B.

        ## Nos priorités

        ## Prochaines étapes

        - Valider le budget & les recrutements
        - Lancer < la campagne >
        MD;

    public static function setUpBeforeClass(): void
    {
        $candidats = [];
        $explicite = getenv('MIA_TEST_VENDOR');
        if (is_string($explicite) && $explicite !== '') {
            $candidats[] = rtrim($explicite, '/') . '/autoload.php';
        }
        $candidats[] = ROOT . '/vendor/autoload.php';

        foreach ($candidats as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                break;
            }
        }
        // Services d'export existants, hors du bootstrap des tests (ils
        // n'appellent leur bibliothèque qu'à l'exécution).
        foreach (['PdfExportService', 'DocxExportService'] as $classe) {
            if (!class_exists($classe, false)) {
                require_once ROOT . '/src/Helpers/' . $classe . '.php';
            }
        }
        // Exporteurs de l'outil Présentation, réutilisés par les présentations livrées.
        foreach (['PptxExporter', 'SlidesPdfExporter'] as $classe) {
            if (!class_exists($classe, false)) {
                require_once ROOT . '/src/Slides/' . $classe . '.php';
            }
        }
        I18n::use('fr');
    }

    /**
     * Réglages en mémoire (identité de l'entreprise comprise) : les fabricants
     * de présentations les lisent, et ce test tourne aussi sans base.
     */
    protected function setUp(): void
    {
        self::reglages([]);
    }

    protected function tearDown(): void
    {
        CompanyProfile::overrideTemplate(null);
        CompanyProfile::overrideWordTemplate(null);
        Settings::refresh();
    }

    private static function reglages(array $valeurs): void
    {
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $valeurs);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, true);
    }

    private static function fabriquer(string $nom, string $source, string $langage = '', string $origine = ''): GeneratedFile
    {
        return FileFactory::build($nom, $source, $langage, $origine, ['locale' => 'fr', 'author' => 'MIA']);
    }

    private static function exiger(string $classe, string $bibliotheque): void
    {
        if (!FileLibrary::has($classe)) {
            self::markTestSkipped("$bibliotheque indisponible (vendor/ incomplet) : test lancé dans le conteneur applicatif.");
        }
    }

    /** Écrit les octets dans un fichier temporaire, le passe au lecteur, le supprime. */
    private static function relire(string $octets, string $extension, callable $lecteur): mixed
    {
        $chemin = FileScratch::path('relecture', $extension);
        file_put_contents($chemin, $octets);
        try {
            return $lecteur($chemin);
        } finally {
            FileScratch::discard($chemin);
        }
    }

    // --------------------------------------------------------------- Excel

    public function testClasseurTypeEtMisEnForme(): void
    {
        self::exiger(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, 'PhpSpreadsheet');

        $f = self::fabriquer('Budget prévisionnel 2026.xlsx', self::BUDGET, 'csv');
        $this->assertSame('Budget prévisionnel 2026.xlsx', $f->name);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f->mime);
        $this->assertStringStartsWith("PK\x03\x04", $f->bytes, 'Un .xlsx est une archive ZIP.');

        $classeur = self::relire($f->bytes, 'xlsx', static fn (string $chemin) => \PhpOffice\PhpSpreadsheet\IOFactory::load($chemin));
        $feuille  = $classeur->getActiveSheet();

        $this->assertSame('Budget prévisionnel 2026', $feuille->getTitle());
        $this->assertSame('MIA', $classeur->getProperties()->getCreator());
        $this->assertSame(5, $feuille->getHighestDataRow());
        $this->assertSame('H', $feuille->getHighestDataColumn());

        // En-tête : texte, gras, ligne figée, filtre automatique.
        $this->assertSame('Montant HT', $feuille->getCell('C1')->getValue());
        $this->assertTrue($feuille->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A2', $feuille->getFreezePane());
        $this->assertSame('A1:H5', $feuille->getAutoFilter()->getRange());

        // Nombres, pourcentages, montants, dates, booléens : de VRAIES valeurs.
        $this->assertSame(1200, (int)$feuille->getCell('C2')->getValue());
        $this->assertSame('n', $feuille->getCell('C2')->getDataType());
        $this->assertSame('#,##0.00 "€"', $feuille->getStyle('C2')->getNumberFormat()->getFormatCode());
        $this->assertEqualsWithDelta(4200.5, $feuille->getCell('C3')->getValue(), 1e-9);
        $this->assertEqualsWithDelta(0.2, $feuille->getCell('D2')->getValue(), 1e-9);
        $this->assertSame('0%', $feuille->getStyle('D2')->getNumberFormat()->getFormatCode());
        $this->assertSame(46300, (int)$feuille->getCell('E2')->getValue(), '5 octobre 2026');
        $this->assertSame('yyyy-mm-dd', $feuille->getStyle('E2')->getNumberFormat()->getFormatCode());
        $this->assertSame('dd/mm/yyyy', $feuille->getStyle('E3')->getNumberFormat()->getFormatCode());
        $this->assertTrue($feuille->getCell('F2')->getValue());
        $this->assertSame('b', $feuille->getCell('F2')->getDataType());

        // Un code n'est pas une quantité : « 01000 » garde son zéro.
        $this->assertSame('01000', $feuille->getCell('G2')->getValue());
        $this->assertSame('s', $feuille->getCell('G2')->getDataType());

        // Formules : conservées, traduites, et PRÉ-CALCULÉES (un aperçu qui ne
        // recalcule rien affiche le total au lieu d'une cellule vide).
        $this->assertSame('=C2*(1+D2)', $feuille->getCell('H2')->getValue());
        $this->assertSame('=SUM(C2:C4)', $feuille->getCell('C5')->getValue());
        $this->assertEqualsWithDelta(5400.5, (float)$feuille->getCell('C5')->getOldCalculatedValue(), 1e-6);
        $this->assertEqualsWithDelta(1440 + 5040.6, (float)$feuille->getCell('H5')->getOldCalculatedValue(), 1e-6);

        // Formule dangereuse : du TEXTE, jamais une formule.
        $this->assertSame('=HYPERLINK("http://evil")', $feuille->getCell('G4')->getValue());
        $this->assertSame('s', $feuille->getCell('G4')->getDataType());

        $classeur->disconnectWorksheets();
    }

    public function testClasseurDepuisUneTableMarkdown(): void
    {
        self::exiger(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, 'PhpSpreadsheet');

        $f = self::fabriquer('clients.xlsx', "| Nom | CA |\n|---|--:|\n| **Zoé** | 1 200 |\n| Alex | 800 |", 'markdown');
        $classeur = self::relire($f->bytes, 'xlsx', static fn (string $chemin) => \PhpOffice\PhpSpreadsheet\IOFactory::load($chemin));
        $feuille  = $classeur->getActiveSheet();

        $this->assertSame('Zoé', $feuille->getCell('A2')->getValue());
        $this->assertSame(1200, (int)$feuille->getCell('B2')->getValue());
        $this->assertSame('n', $feuille->getCell('B2')->getDataType());
        $this->assertTrue($feuille->getStyle('A1')->getFont()->getBold());
        $classeur->disconnectWorksheets();
    }

    /** Des chiffres en première ligne : ce sont des données, pas un en-tête. */
    public function testClasseurSansEnTete(): void
    {
        self::exiger(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, 'PhpSpreadsheet');

        $f = self::fabriquer('mesures.xlsx', "2024;12\n2025;15", 'csv');
        $classeur = self::relire($f->bytes, 'xlsx', static fn (string $chemin) => \PhpOffice\PhpSpreadsheet\IOFactory::load($chemin));
        $feuille  = $classeur->getActiveSheet();

        $this->assertSame(2024, (int)$feuille->getCell('A1')->getValue());
        $this->assertSame('n', $feuille->getCell('A1')->getDataType());
        $this->assertNull($feuille->getFreezePane());
        $this->assertFalse($feuille->getStyle('A1')->getFont()->getBold());
        $classeur->disconnectWorksheets();
    }

    public function testClasseurImpossibleProposeLeCsv(): void
    {
        self::exiger(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, 'PhpSpreadsheet');

        try {
            self::fabriquer('grand.xlsx', str_repeat("a;b;c;d;e;f\n", 20000), 'csv');
            $this->fail('Un tableau au-delà du plafond du classeur doit être refusé.');
        } catch (FileBuildException $e) {
            $this->assertSame(FileBuildException::TOO_LARGE, $e->kind());
            $this->assertSame(['csv'], $e->alternatives(), 'Le CSV n’a pas de plafond : c’est l’issue.');
        }
    }

    // ---------------------------------------------------------------- Word

    public function testDocumentWordStructure(): void
    {
        self::exiger(\PhpOffice\PhpWord\PhpWord::class, 'PhpWord');

        $f = self::fabriquer('Compte rendu.docx', self::COURRIER, 'markdown');
        $this->assertSame('Compte rendu.docx', $f->name);
        $this->assertStringStartsWith("PK\x03\x04", $f->bytes);

        [$document, $numerotation, $proprietes] = self::relire($f->bytes, 'docx', static function (string $chemin): array {
            $zip = new ZipArchive();
            if ($zip->open($chemin) !== true) {
                self::fail('Archive Word illisible.');
            }
            $parts = [
                (string)$zip->getFromName('word/document.xml'),
                (string)$zip->getFromName('word/numbering.xml'),
                (string)$zip->getFromName('docProps/core.xml'),
            ];
            $zip->close();

            return $parts;
        });

        $this->assertNotSame('', $document);
        foreach (['Compte rendu — Projet Élan', 'Participants', 'Lancer la phase 2', 'Une citation.', 'ligne 1', 'ligne 2'] as $texte) {
            $this->assertStringContainsString(htmlspecialchars($texte, ENT_XML1), $document);
        }
        // De vrais styles de titre, un tableau, un lien.
        $this->assertMatchesRegularExpression('/<w:pStyle w:val="Heading1"\/>/', $document);
        $this->assertMatchesRegularExpression('/<w:pStyle w:val="Heading2"\/>/', $document);
        $this->assertStringContainsString('<w:tbl>', $document);
        $this->assertStringContainsString('<w:hyperlink', $document);
        $this->assertStringContainsString('<dc:creator>MIA</dc:creator>', $proprietes);

        // Retouche pour l'aperçu d'Apple : aucun saut de ligne hors d'un run.
        $this->assertSame(0, preg_match('/(?<!<w:r>)<w:br\/>/', $document));

        // Numérotations compactes : définitions 0…n-1, instances 1…n.
        preg_match_all('/<w:abstractNum w:abstractNumId="(\d+)"/', $numerotation, $abstraites);
        $this->assertSame(range(0, count($abstraites[1]) - 1), array_map('intval', $abstraites[1]));
        preg_match_all('/<w:num w:numId="(\d+)"><w:abstractNumId w:val="(\d+)"\/>/', $numerotation, $instances);
        $this->assertSame(range(1, count($instances[1])), array_map('intval', $instances[1]));
        preg_match_all('/<w:numId w:val="(\d+)"\/>/', $document, $renvois);
        $this->assertNotEmpty($renvois[1]);
        foreach ($renvois[1] as $renvoi) {
            $this->assertContains((int)$renvoi, array_map('intval', $instances[1]), 'Chaque élément de liste renvoie à une numérotation déclarée.');
        }
        // Puces + deux listes numérotées indépendantes (1, 2 puis 3, 4 qui repart de 3).
        $this->assertGreaterThanOrEqual(3, count($abstraites[1]));
        $this->assertStringContainsString('<w:start w:val="3"/>', $numerotation);
    }

    /** Sans modèle Word : la mention de l'entreprise et le numéro de page en pied. */
    public function testDocumentWordPiedDeLEntreprise(): void
    {
        self::exiger(\PhpOffice\PhpWord\PhpWord::class, 'PhpWord');
        self::reglages([CompanyProfile::SETTING_LEGAL => 'Acme SA · Document interne']);

        $f = self::fabriquer('note.docx', self::COURRIER, 'markdown');
        $pieds = self::relire($f->bytes, 'docx', static function (string $chemin): string {
            $zip = new ZipArchive();
            $zip->open($chemin);
            $out = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (preg_match('#^word/footer\d*\.xml$#', (string)$zip->getNameIndex($i)) === 1) {
                    $out .= (string)$zip->getFromIndex($i);
                }
            }
            $zip->close();

            return $out;
        });
        $this->assertStringContainsString('Acme SA · Document interne', $pieds);
        $this->assertStringContainsString('PAGE', $pieds);
    }

    /** Avec modèle Word : le document fabriqué par PhpWord greffé dans le modèle. */
    public function testDocumentWordDansLeModeleDeLEntreprise(): void
    {
        self::exiger(\PhpOffice\PhpWord\PhpWord::class, 'PhpWord');
        $modele = FileScratch::path('modele-word', 'dotx');
        DocxTemplateFixture::template($modele);
        CompanyProfile::overrideWordTemplate($modele);

        try {
            $f = self::fabriquer('Compte rendu.docx', self::COURRIER, 'markdown');
            [$document, $types] = self::relire($f->bytes, 'docx', static function (string $chemin): array {
                $zip = new ZipArchive();
                $zip->open($chemin);
                $parts = [(string)$zip->getFromName('word/document.xml'), (string)$zip->getFromName('[Content_Types].xml')];
                $zip->close();

                return $parts;
            });
        } finally {
            FileScratch::discard($modele);
        }

        $this->assertStringContainsString('Participants', $document);
        $this->assertStringNotContainsString('Texte d’exemple', $document);
        $this->assertStringContainsString('<w:pStyle w:val="Titre1"/>', $document, 'Les titres prennent le style du modèle.');
        $this->assertStringContainsString('<w:headerReference', $document, 'L\'en-tête du modèle est gardé.');
        $this->assertStringContainsString('document.main+xml', $types);
    }

    /** PDF : mention et numéro de page en pied, quand l'entreprise en a une. */
    public function testDocumentPdfPiedDeLEntreprise(): void
    {
        self::exiger(\Dompdf\Dompdf::class, 'Dompdf');
        if (!extension_loaded('gd') || !FileLibrary::has(\Smalot\PdfParser\Parser::class)) {
            self::markTestSkipped('Dompdf ou le lecteur de PDF indisponible.');
        }
        self::reglages([CompanyProfile::SETTING_LEGAL => 'Acme SA · Document interne']);

        $f = self::fabriquer('note.pdf', self::COURRIER, 'markdown', 'note.docx');
        $texte = (new \Smalot\PdfParser\Parser())->parseContent($f->bytes)->getText();
        $this->assertStringContainsString('Acme SA · Document interne', $texte);
        $this->assertMatchesRegularExpression('#1\s*/\s*1#', $texte);
    }

    /** Un document écrit en HTML par le modèle passe par l'import HTML existant. */
    public function testDocumentWordDepuisDuHtml(): void
    {
        self::exiger(\PhpOffice\PhpWord\PhpWord::class, 'PhpWord');

        $f = self::fabriquer('page.docx', '<h1>Titre</h1><p>Un <b>paragraphe</b>.</p>', 'html');
        $document = self::relire($f->bytes, 'docx', static function (string $chemin): string {
            $zip = new ZipArchive();
            $zip->open($chemin);
            $xml = (string)$zip->getFromName('word/document.xml');
            $zip->close();

            return $xml;
        });

        $this->assertStringContainsString('Titre', $document);
        $this->assertStringContainsString('paragraphe', $document);
    }

    /** Deux documents de suite dans le même processus (worker) : aucune numérotation ne fuit de l'un à l'autre. */
    public function testDeuxDocumentsDeSuiteSontIndependants(): void
    {
        self::exiger(\PhpOffice\PhpWord\PhpWord::class, 'PhpWord');

        $numerotation = static function (string $octets): string {
            return self::relire($octets, 'docx', static function (string $chemin): string {
                $zip = new ZipArchive();
                $zip->open($chemin);
                $xml = (string)$zip->getFromName('word/numbering.xml');
                $zip->close();

                return $xml;
            });
        };

        $premier = $numerotation(self::fabriquer('a.docx', "1. un\n2. deux", 'markdown')->bytes);
        $second  = $numerotation(self::fabriquer('b.docx', "1. un\n2. deux", 'markdown')->bytes);

        $this->assertSame(
            substr_count($premier, '<w:abstractNum '),
            substr_count($second, '<w:abstractNum '),
            'Les styles de PhpWord sont un registre statique : il doit être remis à zéro.'
        );
    }

    // ----------------------------------------------------------------- PDF

    public function testDocumentPdf(): void
    {
        self::exiger(\Dompdf\Dompdf::class, 'Dompdf');
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension gd absente : Dompdf ne peut pas traiter les images.');
        }

        $f = self::fabriquer('compte-rendu.pdf', self::COURRIER . "\n\nFin 😀", 'markdown', 'compte-rendu.docx');
        $this->assertSame('application/pdf', $f->mime);
        $this->assertStringStartsWith('%PDF-', $f->bytes);

        if (!FileLibrary::has(\Smalot\PdfParser\Parser::class)) {
            return;
        }
        $texte = (new \Smalot\PdfParser\Parser())->parseContent($f->bytes)->getText();
        foreach (['Compte rendu', 'Participants', 'Lancer la phase 2', 'Loyer', 'ligne 2'] as $attendu) {
            $this->assertStringContainsString($attendu, $texte);
        }
    }

    public function testDocumentPdfSurPlusieursPages(): void
    {
        self::exiger(\Dompdf\Dompdf::class, 'Dompdf');
        self::exiger(\Smalot\PdfParser\Parser::class, 'smalot/pdfparser');

        $contenu = "# Rapport\n\n" . str_repeat("Un paragraphe de contrôle pour vérifier la pagination du document PDF.\n\n", 180);
        $f = self::fabriquer('rapport-long.pdf', $contenu, 'markdown', 'rapport-long.docx');
        $pdf = (new \Smalot\PdfParser\Parser())->parseContent($f->bytes);

        $this->assertStringStartsWith('%PDF-', $f->bytes);
        $this->assertGreaterThan(1, count($pdf->getPages()), 'Un contenu long doit produire plusieurs pages.');
        $this->assertStringContainsString('Rapport', $pdf->getText());
    }

    // ---------------------------------------------------------- PowerPoint

    /** Textes d'un fragment DrawingML (PHPPresentation les écrit en CDATA). */
    private static function textesXml(string $xml): string
    {
        preg_match_all('#<a:t>(?:<!\[CDATA\[(.*?)\]\]>|([^<]*))</a:t>#s', $xml, $m, PREG_SET_ORDER);

        return implode(' | ', array_map(
            static fn (array $t): string => ($t[1] ?? '') !== '' ? $t[1] : html_entity_decode($t[2] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $m
        ));
    }

    /** Lit le texte de chaque slide d'une archive .pptx, dans l'ordre, et le XML de la deuxième. */
    private static function slidesTexte(string $octets): array
    {
        return self::relire($octets, 'pptx', static function (string $chemin): array {
            $zip = new ZipArchive();
            $zip->open($chemin);
            $slides = [];
            for ($n = 1; ($xml = $zip->getFromName("ppt/slides/slide$n.xml")) !== false; $n++) {
                $slides[$n] = self::textesXml($xml);
            }
            $notes = [];
            for ($n = 1; $n <= 20; $n++) {
                $xml = $zip->getFromName("ppt/notesSlides/notesSlide$n.xml");
                if ($xml !== false) {
                    $notes[] = self::textesXml($xml);
                }
            }
            $xml = (string)$zip->getFromName('ppt/slides/slide2.xml');
            $zip->close();

            return ['slides' => $slides, 'notes' => implode("\n", $notes), 'xml2' => $xml];
        });
    }

    public function testPresentationPowerPoint(): void
    {
        self::exiger(\PhpOffice\PhpPresentation\PhpPresentation::class, 'PHPPresentation');

        $f = self::fabriquer('Plan 2027.pptx', self::PRESENTATION, 'markdown');
        $this->assertSame('Plan 2027.pptx', $f->name);
        $this->assertSame('application/vnd.openxmlformats-officedocument.presentationml.presentation', $f->mime);
        $this->assertStringStartsWith("PK\x03\x04", $f->bytes, 'Un .pptx est une archive ZIP.');

        $lu = self::slidesTexte($f->bytes);
        $this->assertCount(4, $lu['slides'], 'Couverture, Contexte, intercalaire, Prochaines étapes.');
        $this->assertStringContainsString('Plan stratégique 2027', $lu['slides'][1]);
        $this->assertStringContainsString('Comité de direction', $lu['slides'][1]);
        $this->assertStringContainsString('Marché en croissance de 8 %', $lu['slides'][2], 'Le gras ne laisse pas ses astérisques.');
        $this->assertStringNotContainsString('**', $lu['slides'][2]);
        $this->assertStringContainsString('2 / 4', $lu['slides'][2]);
        $this->assertStringContainsString('Nos priorités', $lu['slides'][3]);
        // Échappement XML : « & », « < » et « > » ne rendent pas le fichier illisible.
        $this->assertStringContainsString('Valider le budget & les recrutements', $lu['slides'][4]);
        $this->assertStringContainsString('Lancer < la campagne >', $lu['slides'][4]);
        $this->assertStringNotContainsString('Notes', implode(' ', $lu['slides']), 'La note ne s’affiche pas sur la slide.');
        $this->assertStringContainsString('insister sur le concurrent B.', $lu['notes']);

        // Langue de vérification du lecteur, et retrait des puces de l'aperçu
        // (2,6 % de 960 px, en EMU) — il valait 180 px.
        $this->assertStringContainsString('lang="fr-FR"', $lu['xml2']);
        $this->assertStringNotContainsString('lang="en-US"', $lu['xml2']);
        $this->assertMatchesRegularExpression('/marL="(\d+)"/', $lu['xml2']);
        preg_match_all('/marL="(\d+)"/', $lu['xml2'], $marges);
        $this->assertLessThan(300_000, max(array_map('intval', $marges[1])), 'Retrait de puce démesuré.');

        // Relu par la bibliothèque elle-même : un fichier qu'un lecteur PowerPoint accepte.
        $relu = self::relire($f->bytes, 'pptx', static fn (string $chemin) => \PhpOffice\PhpPresentation\IOFactory::load($chemin));
        $this->assertSame(4, $relu->getSlideCount());
        $this->assertSame('Plan stratégique 2027', $relu->getDocumentProperties()->getTitle());
    }

    public function testPresentationEnPdfDeSlides(): void
    {
        self::exiger(\Dompdf\Dompdf::class, 'Dompdf');
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension gd absente : Dompdf ne peut pas traiter les images.');
        }

        $f = self::fabriquer('plan.pdf', self::PRESENTATION, 'markdown', 'plan.pptx');
        $this->assertSame('application/pdf', $f->mime);
        $this->assertStringStartsWith('%PDF-', $f->bytes);

        if (!FileLibrary::has(\Smalot\PdfParser\Parser::class)) {
            return;
        }
        $pdf = (new \Smalot\PdfParser\Parser())->parseContent($f->bytes);
        $this->assertCount(4, $pdf->getPages(), 'Une page par slide : un PDF de slides, pas un document.');
        // Un titre de couverture passe à la ligne : on compare le texte, pas la mise en page.
        $texte = (string)preg_replace('/\s+/u', ' ', $pdf->getText());
        $this->assertStringNotContainsString('🚀', $texte, 'Émoji retiré : la police du PDF le dessinerait en carré.');
        foreach (['Plan stratégique 2027', 'Contexte', 'Prochaines étapes', 'Valider le budget & les recrutements'] as $attendu) {
            $this->assertStringContainsString($attendu, $texte);
        }
    }

    /** Mise en page de MIA : un vrai tableau, des chiffres clés, le logo de l'entreprise. */
    public function testPresentationAvecTableauChiffresEtLogo(): void
    {
        self::exiger(\PhpOffice\PhpPresentation\PhpPresentation::class, 'PHPPresentation');

        $markdown = "# Bilan\n\n## Chiffres\n\n- **4,2 M€** — de chiffre d'affaires\n- **+18 %** — de croissance\n\n"
            . "## Ventes\n\n| Région | CA |\n|---|---|\n| Ouest | 1,5 M€ |\n| Sud | <1 M€ |";
        $deck = MarkdownDeck::build(MarkdownDocument::parse($markdown), 'bilan', ['logo' => '/assets/img/logo.png', 'name' => 'Acme']);

        $lu = self::relire(PptxExporter::export($deck), 'pptx', static function (string $chemin): array {
            $zip = new ZipArchive();
            $zip->open($chemin);
            $media = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $media += str_starts_with((string)$zip->getNameIndex($i), 'ppt/media/') ? 1 : 0;
            }
            $out = ['media' => $media, 'kpis' => (string)$zip->getFromName('ppt/slides/slide2.xml'), 'table' => (string)$zip->getFromName('ppt/slides/slide3.xml')];
            $zip->close();

            return $out;
        });

        $this->assertGreaterThan(0, $lu['media'], 'Le logo est embarqué.');
        $this->assertStringContainsString("4,2\u{00A0}M€", self::textesXml($lu['kpis']), 'Valeur insécable : jamais coupée.');
        $this->assertStringContainsString('<a:tbl>', $lu['table'], 'Un vrai tableau PowerPoint, pas des puces.');
        $this->assertStringContainsString('<1 M€', self::textesXml($lu['table']));
    }

    /**
     * Style « aplats » : PowerPoint reçoit de VRAIES formes arrondies et des
     * disques (AutoShape), pas des rectangles ; le PDF de slides se fabrique
     * avec les mêmes formes.
     */
    public function testPresentationAuStyleAplatsFormesArrondies(): void
    {
        self::exiger(\PhpOffice\PhpPresentation\PhpPresentation::class, 'PHPPresentation');

        $markdown = "# Offre\n\nSous-titre\n\n## Ce qui freine\n\n- **Temps perdu** — six heures par semaine\n- **Coûts** — aucune vue claire\n\n"
            . "## Étapes\n\n1. Écouter\n2. Tester\n3. Lancer";
        $xml = self::relire(self::fabriquer('offre.pptx', $markdown, 'markdown', 'offre.pptx')->bytes, 'pptx', static function (string $chemin): string {
            $zip = new ZipArchive();
            $zip->open($chemin);
            $out = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nom = (string)$zip->getNameIndex($i);
                if (preg_match('#^ppt/slides/slide\d+\.xml$#', $nom) === 1) {
                    $out .= (string)$zip->getFromName($nom);
                }
            }
            $zip->close();

            return $out;
        });

        $this->assertStringContainsString('prst="roundRect"', $xml, 'Cartes arrondies.');
        $this->assertStringContainsString('prst="ellipse"', $xml, 'Disques et pastilles.');
        $this->assertStringContainsString('Temps perdu', self::textesXml($xml));

        if (!extension_loaded('gd') || !FileLibrary::has(\Dompdf\Dompdf::class)) {
            return;
        }
        $pdf = self::fabriquer('offre.pdf', $markdown, 'markdown', 'offre.pptx');
        $this->assertStringStartsWith('%PDF', $pdf->bytes, 'PDF de slides au même style.');
    }

    /**
     * L'entreprise a déposé son modèle : la présentation est construite DANS
     * ce modèle (sans PHPPresentation), son nom en pied de page.
     */
    public function testPresentationDansLeModeleDeLEntreprise(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive indisponible.');
        }
        $modele = FileScratch::path('modele-entreprise', 'pptx');
        try {
            PptxTemplateFixture::build($modele, ['examples' => 2]);
            CompanyProfile::overrideTemplate($modele);
            self::reglages([CompanyProfile::SETTING_NAME => 'Acme & Fils']);

            $f = self::fabriquer('Plan 2027.pptx', self::PRESENTATION, 'markdown');
            $this->assertSame('application/vnd.openxmlformats-officedocument.presentationml.presentation', $f->mime);

            $lu = self::slidesTexte($f->bytes);
            $this->assertCount(4, $lu['slides'], 'Les slides d\'exemple du modèle ne sont pas reprises.');
            $this->assertStringContainsString('Plan stratégique 2027', $lu['slides'][1]);
            $this->assertStringContainsString('Marché en croissance de 8 %', $lu['slides'][2]);
            $this->assertStringContainsString('Acme & Fils', $lu['slides'][2], 'Le nom de l\'entreprise en pied de page.');
            $this->assertStringContainsString('Lancer < la campagne >', $lu['slides'][4]);
            $this->assertStringContainsString('insister sur le concurrent B.', $lu['notes']);
            $this->assertStringContainsString('<p:ph idx="1"/>', $lu['xml2'], 'Dans l\'espace réservé du modèle.');
        } finally {
            FileScratch::discard($modele);
        }
    }

    /** Un modèle abîmé depuis son dépôt ne prive personne de sa présentation : repli sur la mise en page de MIA. */
    public function testModeleAbimeRepliSurLaMiseEnPageMia(): void
    {
        self::exiger(\PhpOffice\PhpPresentation\PhpPresentation::class, 'PHPPresentation');

        $modele = FileScratch::path('modele-abime', 'pptx');
        file_put_contents($modele, 'plus une archive');
        $journal = ini_set('error_log', FileScratch::path('journal', 'log'));
        try {
            CompanyProfile::overrideTemplate($modele);
            $f = self::fabriquer('plan.pptx', self::PRESENTATION, 'markdown');

            $lu = self::slidesTexte($f->bytes);
            $this->assertCount(4, $lu['slides']);
            $this->assertStringContainsString('2 / 4', $lu['slides'][2], 'Numérotation de la mise en page de MIA.');
        } finally {
            $log = (string)ini_get('error_log');
            ini_set('error_log', (string)$journal);
            FileScratch::discard($log);
            FileScratch::discard($modele);
        }
    }

    /** Le Word d'une présentation est un document : ses titres, ses listes, sans slide. */
    public function testPresentationEnWord(): void
    {
        self::exiger(\PhpOffice\PhpWord\PhpWord::class, 'PhpWord');

        $f = self::fabriquer('plan.docx', self::PRESENTATION, 'markdown', 'plan.pptx');
        $this->assertStringStartsWith("PK\x03\x04", $f->bytes);

        $document = self::relire($f->bytes, 'docx', static function (string $chemin): string {
            $zip = new ZipArchive();
            $zip->open($chemin);
            $xml = (string)$zip->getFromName('word/document.xml');
            $zip->close();

            return $xml;
        });
        $this->assertStringContainsString('Plan stratégique 2027', $document);
        $this->assertStringContainsString('Prochaines étapes', $document);
    }

    // ------------------------------------------- retouche des fichiers Word

    /**
     * La retouche pour les lecteurs d'Apple, sur une archive écrite à la main :
     * elle ne dépend pas de PhpWord, seulement de ZipArchive.
     */
    public function testRenumerotationEtSautsDeLigne(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive indisponible.');
        }

        $chemin = FileScratch::path('retouche', 'docx');
        $zip = new ZipArchive();
        $zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/numbering.xml',
            '<w:numbering><w:abstractNum w:abstractNumId="8"/><w:abstractNum w:abstractNumId="10"/>'
            . '<w:num w:numId="8"><w:abstractNumId w:val="8"/></w:num><w:num w:numId="10"><w:abstractNumId w:val="10"/></w:num></w:numbering>'
        );
        $zip->addFromString(
            'word/document.xml',
            '<w:p><w:numPr><w:numId w:val="10"/></w:numPr><w:r><w:t>a</w:t></w:r><w:br/><w:r><w:br w:type="page"/></w:r></w:p>'
            . '<w:p><w:numPr><w:numId w:val="8"/></w:numPr></w:p>'
        );
        $zip->close();

        try {
            DocxFileBuilder::normalizeForReaders($chemin);
            $zip = new ZipArchive();
            $zip->open($chemin);
            $numerotation = (string)$zip->getFromName('word/numbering.xml');
            $document     = (string)$zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            FileScratch::discard($chemin);
        }

        $this->assertSame(
            '<w:numbering><w:abstractNum w:abstractNumId="0"/><w:abstractNum w:abstractNumId="1"/>'
            . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num></w:numbering>',
            $numerotation
        );
        $this->assertSame(
            '<w:p><w:numPr><w:numId w:val="2"/></w:numPr><w:r><w:t>a</w:t></w:r><w:r><w:br/></w:r><w:r><w:br w:type="page"/></w:r></w:p>'
            . '<w:p><w:numPr><w:numId w:val="1"/></w:numPr></w:p>',
            $document
        );
    }
}
