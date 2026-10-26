<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FileFactory — point d'entrée unique de la fabrication d'un fichier.
 *
 * Couvre tout ce qui se fabrique SANS bibliothèque (CSV, TSV, texte, code,
 * Markdown, RTF) et chaque refus présentable. Les formats qui dépendent de
 * vendor/ (Excel, Word, PDF) sont éprouvés par FileBuildersLibraryTest.
 */
final class FileFactoryTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    private const BUDGET = "Poste;Catégorie;Montant\nLoyer;Charges fixes;1 200 €\n\"Frais; divers\";Autre;-150,75\nTotal;;=SOMME(C2:C3)";

    private const COURRIER = "# Compte rendu\n\n**Date :** 30 septembre\n\n- Un\n- Deux\n\n| A | B |\n|---|---|\n| 1 | 2 |";

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function fabriquer(string $nom, string $source, string $langage = '', string $origine = ''): GeneratedFile
    {
        return FileFactory::build($nom, $source, $langage, $origine, ['locale' => 'fr', 'author' => 'MIA']);
    }

    private static function echec(string $nom, string $source, string $langage = '', string $origine = ''): FileBuildException
    {
        try {
            self::fabriquer($nom, $source, $langage, $origine);
        } catch (FileBuildException $e) {
            return $e;
        }
        self::fail("La fabrication de « $nom » aurait dû échouer.");
    }

    // ------------------------------------------------------------- tableur

    public function testCsvReecritConforme(): void
    {
        $f = self::fabriquer('budget.csv', self::BUDGET, 'csv', 'budget.xlsx');

        $this->assertSame('budget.csv', $f->name);
        $this->assertSame('text/csv; charset=utf-8', $f->mime);
        // BOM (accents lus en UTF-8 par Excel), CRLF, séparateur de la source,
        // cellule citée parce qu'elle contient le séparateur.
        $this->assertSame(
            self::BOM . "Poste;Catégorie;Montant\r\nLoyer;Charges fixes;1 200 €\r\n\"Frais; divers\";Autre;-150,75\r\nTotal;;=SOMME(C2:C3)\r\n",
            $f->bytes
        );
        $this->assertSame(strlen($f->bytes), $f->size());
    }

    public function testCsvGardeLeSeparateurDeLaSource(): void
    {
        $f = self::fabriquer('export.csv', "Nom,Ville\nZoé,Nantes", 'csv');

        $this->assertSame(self::BOM . "Nom,Ville\r\nZoé,Nantes\r\n", $f->bytes);
    }

    public function testTsvDepuisUnTableauAPointVirgule(): void
    {
        $f = self::fabriquer('budget.tsv', "a;b\n1;2", 'csv', 'budget.xlsx');

        $this->assertSame('budget.tsv', $f->name);
        $this->assertStringStartsWith('text/tab-separated-values', $f->mime);
        $this->assertSame(self::BOM . "a\tb\r\n1\t2\r\n", $f->bytes);
    }

    public function testCsvDepuisUneTableMarkdown(): void
    {
        $f = self::fabriquer('clients.csv', "| Nom | Ville |\n|---|---|\n| **Zoé** | Nantes |", 'markdown');

        $this->assertSame(self::BOM . "Nom;Ville\r\nZoé;Nantes\r\n", $f->bytes);
    }

    public function testCsvNeutraliseLesCellulesExecutables(): void
    {
        $f = self::fabriquer('liens.csv', "Lien;Total\n=HYPERLINK(\"http://evil\");=SOMME(A1:A2)", 'csv');

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\")\";=SOMME(A1:A2)\r\n", $f->bytes);
    }

    // ------------------------------------------------------ texte et code

    public function testTexteBrutAvecBom(): void
    {
        $f = self::fabriquer('notes.txt', "Ligne 1\n* pas une puce", 'text');

        $this->assertSame('notes.txt', $f->name);
        $this->assertSame('text/plain; charset=utf-8', $f->mime);
        $this->assertSame(self::BOM . "Ligne 1\n* pas une puce\n", $f->bytes);
    }

    /** RFC 8259 : pas de BOM dans un JSON — ni dans un script, qu'il casserait. */
    public function testDonneesEtCodeSansBom(): void
    {
        $this->assertSame("{\"a\": 1}\n", self::fabriquer('data.json', '{"a": 1}', 'json')->bytes);
        $this->assertSame("#!/bin/sh\necho ok\n", self::fabriquer('run.sh', "#!/bin/sh\necho ok\n\n\n", 'bash')->bytes);
        $this->assertSame("print('é')\n", self::fabriquer('script.py', "print('é')", 'python')->bytes);
    }

    public function testFinsDeLigneImposeesParLeFormat(): void
    {
        $this->assertSame("@echo off\r\necho ok\r\n", self::fabriquer('lancer.bat', "@echo off\necho ok", 'bat')->bytes);
        $this->assertStringContainsString(
            "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
            self::fabriquer('agenda.ics', "BEGIN:VCALENDAR\nEND:VCALENDAR", 'ics')->bytes
        );
    }

    public function testMarkdownRecopie(): void
    {
        $f = self::fabriquer('README.md', self::COURRIER, 'markdown');

        $this->assertSame('text/markdown; charset=utf-8', $f->mime);
        $this->assertSame(self::COURRIER . "\n", $f->bytes);
    }

    /** « Texte (.txt) » sur un document : le même courrier, lisible, sans syntaxe. */
    public function testTexteDepuisUnDocument(): void
    {
        $f = self::fabriquer('compte-rendu.txt', self::COURRIER, 'markdown', 'compte-rendu.docx');

        $this->assertSame(
            self::BOM . "Compte rendu\n============\n\nDate : 30 septembre\n\n- Un\n- Deux\n\nA   | B\n----+----\n1   | 2\n",
            $f->bytes
        );
    }

    public function testExtensionHorsCatalogueRecopiee(): void
    {
        $f = self::fabriquer('nginx.conf', "server {\n  listen 80;\n}", '');

        $this->assertSame('nginx.conf', $f->name);
        $this->assertStringStartsWith('text/plain', $f->mime);
        $this->assertSame("server {\n  listen 80;\n}\n", $f->bytes);
    }

    public function testNomSansExtension(): void
    {
        $this->assertSame('sans-extension.csv', self::fabriquer('sans-extension', "a;b\n1;2", 'csv')->name);
        $this->assertSame('notes.txt', self::fabriquer('notes', 'Bonjour', '')->name);
        $this->assertSame('tableau.csv', self::fabriquer('', "a;b\n1;2", 'csv')->name);
        $this->assertSame('script.py', self::fabriquer('', 'print(1)', 'python')->name);
    }

    public function testNomVenuDUnCheminOuDUnePageHostile(): void
    {
        $this->assertSame('passwd.csv', self::fabriquer('../../etc/passwd.csv', "a;b\n1;2", 'csv')->name);
        $this->assertSame('a Set-Cookie- x.csv', self::fabriquer("a\r\nSet-Cookie: x.csv", "a;b\n1;2", 'csv')->name);
    }

    // --------------------------------------------------------------- RTF

    public function testRtfDepuisUnDocument(): void
    {
        $f = self::fabriquer('compte-rendu.rtf', self::COURRIER, 'markdown', 'compte-rendu.docx');

        $this->assertSame('compte-rendu.rtf', $f->name);
        $this->assertSame('application/rtf', $f->mime);
        $this->assertStringStartsWith('{\rtf1\ansi', $f->bytes);
        $this->assertStringContainsString('{\info{\title Compte rendu}}', $f->bytes);
        $this->assertStringContainsString('\s1\outlinelevel0', $f->bytes);
        $this->assertStringContainsString('\ls1\ilvl0', $f->bytes);
        $this->assertStringContainsString('\itap1\trowd', $f->bytes);
    }

    /** Un document écrit en texte brut : ses astérisques restent des astérisques. */
    public function testRtfDepuisDuTexteBrut(): void
    {
        $f = self::fabriquer('lettre.rtf', "Bonjour,\n\n* astérisque littéral *", 'text', 'lettre.docx');

        $this->assertStringContainsString('* ast\\\'e9risque litt\\\'e9ral *', $f->bytes);
        $this->assertStringNotContainsString('\ls1', $f->bytes);
        // Sans titre dans le texte, le document porte le nom du fichier.
        $this->assertStringContainsString('{\info{\title lettre}}', $f->bytes);
    }

    /** Le modèle a écrit le RTF lui-même : c'est déjà le fichier. */
    public function testRtfDejaEcritRecopieTelQuel(): void
    {
        $rtf = '{\rtf1\ansi Bonjour {\b monde}.\par}';

        $this->assertSame($rtf, self::fabriquer('note.rtf', $rtf, 'rtf')->bytes);
    }

    // ---------------------------------------------------- refus présentables

    public function testFormatQuOnNeSaitPasFabriquer(): void
    {
        $e = self::echec('plan.key', self::COURRIER, 'markdown');

        $this->assertSame(FileBuildException::UNSUPPORTED, $e->kind());
        $this->assertSame(415, $e->httpStatus());
        $this->assertSame(['pptx', 'pdf'], $e->alternatives());
        $this->assertStringContainsString('Keynote', $e->getMessage());

        $archive = self::echec('fichiers.zip', 'x', '');
        $this->assertSame([], $archive->alternatives());
    }

    /** L'issue proposée pour un format refusé se fabrique bien : le contenu est un document. */
    public function testLIssueDUnFormatRefuseSeFabrique(): void
    {
        $f = self::fabriquer('lettre.rtf', self::COURRIER, 'markdown', 'lettre.doc');

        $this->assertStringStartsWith('{\rtf1', $f->bytes);
        $this->assertSame('lettre.rtf', $f->name);

        $texte = self::fabriquer('plan.txt', self::COURRIER, 'markdown', 'plan.key');
        $this->assertStringContainsString('# Compte rendu', $texte->bytes, 'Issue « texte » : le contenu tel quel.');
    }

    /**
     * Le PowerPoint ne se fabrique QUE depuis une présentation : demandé sur
     * un compte rendu, il est refusé avec les formats du document — même
     * décision que le menu, qui ne le propose pas.
     */
    public function testUnDocumentNeDevientPasUnePresentation(): void
    {
        $e = self::echec('compte-rendu.pptx', self::COURRIER, 'markdown', 'compte-rendu.docx');

        $this->assertSame(FileBuildException::UNSUPPORTED, $e->kind());
        $this->assertSame(['docx', 'pdf', 'rtf', 'md', 'txt'], $e->alternatives());
        $this->assertSame('Ce contenu ne peut pas être converti en PowerPoint.', $e->getMessage());
    }

    /** Une présentation se télécharge aussi en Word ou en Markdown, sans bibliothèque de slides. */
    public function testUnePresentationSeTelechargeEnSaSource(): void
    {
        $source = "# Plan 2027\n\nComité de direction\n\n## Objectifs\n\n- Croître\n- Recruter";

        $md = self::fabriquer('plan.md', $source, 'markdown', 'plan.pptx');
        $this->assertSame('plan.md', $md->name);
        $this->assertStringContainsString('## Objectifs', $md->bytes);

        // Un classeur n'en sort pas : les issues sont celles du menu d'une présentation.
        $classeur = self::echec('plan.xlsx', $source, 'markdown', 'plan.pptx');
        $this->assertSame(['pptx', 'pdf', 'docx', 'md'], $classeur->alternatives());
    }

    public function testConversionSansObjet(): void
    {
        // Un classeur ne devient pas un courrier…
        $e = self::echec('budget.docx', self::BUDGET, 'csv', 'budget.xlsx');
        $this->assertSame(FileBuildException::UNSUPPORTED, $e->kind());
        $this->assertSame(415, $e->httpStatus());
        $this->assertSame(['xlsx', 'csv'], $e->alternatives());
        $this->assertSame('Ce contenu ne peut pas être converti en Word.', $e->getMessage());

        // … ni un courrier un classeur, ni un JSON un script.
        $this->assertSame(['docx', 'pdf', 'rtf', 'md', 'txt'], self::echec('lettre.xlsx', self::COURRIER, 'markdown', 'lettre.docx')->alternatives());
        $this->assertSame(FileBuildException::UNSUPPORTED, self::echec('data.py', '{"a":1}', 'json', 'data.json')->kind());
    }

    public function testFichierVide(): void
    {
        foreach (['', "   \n\n\t", "\xEF\xBB\xBF", "\0\0"] as $source) {
            $e = self::echec('vide.txt', $source, 'text');
            $this->assertSame(FileBuildException::EMPTY, $e->kind());
            $this->assertSame(422, $e->httpStatus());
        }
        // Un tableau sans aucune cellule : vide aussi.
        $this->assertSame(FileBuildException::EMPTY, self::echec('vide.csv', "\n\n", 'csv')->kind());
    }

    public function testFichierTropGrandAvecSesIssues(): void
    {
        $pdf = self::echec('long.pdf', str_repeat("Une ligne de texte.\n", 25000), 'markdown');
        $this->assertSame(FileBuildException::TOO_LARGE, $pdf->kind());
        $this->assertSame(413, $pdf->httpStatus());
        // Le PDF a le plafond le plus bas : les autres documents restent possibles.
        $this->assertSame(['rtf', 'docx', 'txt', 'md'], $pdf->alternatives());
        $this->assertStringContainsString('400 Ko', $pdf->getMessage());

        $texte = self::echec('long.txt', str_repeat('x', FileFormats::DEFAULT_MAX_BYTES + 1), 'text');
        $this->assertSame(FileBuildException::TOO_LARGE, $texte->kind());
        $this->assertSame([], $texte->alternatives());
    }

    /** Un CSV trop grand pour être analysé est livré tel quel : c'est le format de repli des grands tableaux. */
    public function testCsvTropGrandPourEtreAnalyseLivreTelQuel(): void
    {
        $source = str_repeat("a;b\n", TabularData::MAX_ROWS + 5);
        $f = self::fabriquer('grand.csv', $source, 'csv');

        $this->assertStringStartsWith(self::BOM . "a;b\r\na;b\r\n", $f->bytes);
        $this->assertSame(TabularData::MAX_ROWS + 5, substr_count($f->bytes, "\r\n"));
    }

    // ------------------------------------------------------------- outils

    public function testExtensionVisee(): void
    {
        $this->assertSame('xlsx', FileFactory::resolveExtension('budget.xlsx', 'csv'));
        $this->assertSame('csv', FileFactory::resolveExtension('budget', 'csv'));
        $this->assertSame('xlsx', FileFactory::resolveExtension('budget', 'xlsx'));
        $this->assertSame('txt', FileFactory::resolveExtension('budget', ''));
        $this->assertSame('txt', FileFactory::resolveExtension('', 'inconnu'));
    }

    public function testSourceNormalisee(): void
    {
        $this->assertSame("a\nb\nc", FileFactory::cleanSource("\xEF\xBB\xBFa\r\nb\rc"));
        $this->assertSame('ab', FileFactory::cleanSource("a\0b"));
        $this->assertTrue(mb_check_encoding(FileFactory::cleanSource("caf\xE9 \xC3"), 'UTF-8'));
    }

    /** Nom d'onglet : Excel refuse d'ouvrir un classeur dont un onglet porte `[ ] : * ? / \`. */
    public function testNomDOnglet(): void
    {
        $this->assertSame('Budget 2026', XlsxFileBuilder::sheetTitle('Budget 2026'));
        $this->assertSame('a b c d e f g', XlsxFileBuilder::sheetTitle('a[b]c:d*e?f/g'));
        $this->assertSame('Feuille 1', XlsxFileBuilder::sheetTitle(" ''[]* "));
        $this->assertSame(31, mb_strlen(XlsxFileBuilder::sheetTitle(str_repeat('é', 50)), 'UTF-8'));
        $this->assertSame("L'été", XlsxFileBuilder::sheetTitle("'L'été'"));
    }

    /** Les caractères que la police du PDF ne dessine pas sont retirés (un carré vide se voit). */
    public function testTexteImprimable(): void
    {
        $this->assertSame('Équipe « données » ', PdfFileBuilder::printable('Équipe « données » 😀'));
        $this->assertSame('Bravo ✓ ★', PdfFileBuilder::printable("Bravo ✓\u{FE0F} ★"));
        $this->assertSame('Famille ', PdfFileBuilder::printable("Famille 👨\u{200D}👩\u{200D}👧"));
    }

    public function testFichierDeTravail(): void
    {
        $chemin = FileScratch::path('essai', 'bin');

        $this->assertStringEndsWith('.bin', $chemin);
        $this->assertMatchesRegularExpression('/essai-[0-9a-f]{16}\.bin$/', $chemin);
        $this->assertNotSame($chemin, FileScratch::path('essai', 'bin'));
        $this->assertDirectoryExists(dirname($chemin));
        $this->assertTrue(is_writable(dirname($chemin)));

        file_put_contents($chemin, 'x');
        FileScratch::discard($chemin);
        $this->assertFileDoesNotExist($chemin);
        FileScratch::discard($chemin);   // absent : ne lève pas
        FileScratch::discard('');
    }

    public function testLesEchecsSuiventLaLangue(): void
    {
        I18n::use('en');

        $this->assertSame(
            'This content cannot be converted to Word.',
            self::echec('budget.docx', self::BUDGET, 'csv', 'budget.xlsx')->getMessage()
        );
        $this->assertSame(
            'Keynote format: MIA creates .pptx presentations, which Keynote opens.',
            self::echec('plan.key', self::COURRIER, 'markdown')->getMessage()
        );
    }
}
