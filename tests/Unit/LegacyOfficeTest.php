<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/LegacyOfficeFixture.php';

/**
 * Word 97-2003 (.doc) et Excel 97-2003 (.xls) — lus sans bibliothèque
 * (LegacyOffice), acceptés partout où .docx et .xlsx le sont.
 *
 * Fichiers réels : tests/Fixtures/legacy-office/ancien.doc et ancien.xls,
 * enregistrés par LibreOffice (filtres « MS Word 97 » et « MS Excel 97 »)
 * depuis un document plat (.fodt / .fods) : titre, accents, €, caractères
 * hors Windows-1252, lien, tableau, note de bas de page, saut de page ;
 * classeur à trois onglets — date, booléens, pourcentage, heure, formule,
 * erreur, cellule décalée, et 200 chaînes uniques pour que la table des
 * chaînes déborde sur des CONTINUE.
 */
final class LegacyOfficeTest extends TestCase
{
    private const DIR = __DIR__ . '/../Fixtures/legacy-office';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mia_legacy_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function file(string $name, string $bytes): string
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    // ==================================================================
    // Fichiers réels
    // ==================================================================

    public function testUnDocumentWordEnregistreParLibreOfficeSeLitEnEntier(): void
    {
        $text = (string)FileExtractor::extract(self::DIR . '/ancien.doc', LegacyOffice::MIME_DOC);

        $this->assertStringContainsString('Compte rendu — réunion d’équipe', $text);
        $this->assertStringContainsString('Budget validé : 1 250,50 € pour l’année, déjà « engagé ».', $text);
        $this->assertStringContainsString('Ωmega, 日本語, coût œuvre', $text, 'caractères hors Windows-1252 : morceau en UTF-16');
        $this->assertStringContainsString('Voir le rapport complet en ligne.', $text, 'le résultat du lien reste');
        $this->assertStringNotContainsString('HYPERLINK', $text, 'le code du champ disparaît');
        $this->assertStringContainsString("Projet | Responsable | Statut\nAtlas | Marie | En cours\nOrion | Paul | Terminé", $text);
        $this->assertStringContainsString('Deuxième page : conclusion.', $text);
        $this->assertStringContainsString('Note de bas de page : chiffre provisoire.', $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text);
    }

    public function testUnClasseurExcelEnregistreParLibreOfficeSeLitFeuilleParFeuille(): void
    {
        $text = (string)FileExtractor::extract(self::DIR . '/ancien.xls', LegacyOffice::MIME_XLS);

        $this->assertStringContainsString("# Ventes\nClient Montant Date Payé Remise Heure", $text);
        $this->assertStringContainsString('Société Générale d’Électricité 1250.5 2026-03-14 TRUE 15% 09:30', $text);
        $this->assertStringContainsString('日本商事 42 FALSE', $text);
        $this->assertStringContainsString('Total 1292.5 #DIV/0!', $text, 'valeur calculée de la formule, erreur comprise');
        $this->assertMatchesRegularExpression('/# Notes\n\s*Remarque décalée\n-0\.125 3000000/u', $text, 'cellule décalée : colonne vide gardée');
        $this->assertStringContainsString('# Liste', $text);

        preg_match_all('/Élément n° (\d{4})/u', $text, $m);
        $this->assertCount(200, array_unique($m[1]), 'chaque chaîne de la table, coupures par CONTINUE comprises');
        $this->assertSame(28, substr_count($text, 'Ω€'), 'les chaînes en UTF-16 aussi');
    }

    public function testLaDetectionSuitLesFluxPasLExtension(): void
    {
        $doc = $this->file('rapport.xls', (string)file_get_contents(self::DIR . '/ancien.doc'));
        $xls = $this->file('chiffres.doc', (string)file_get_contents(self::DIR . '/ancien.xls'));

        $this->assertSame(LegacyOffice::MIME_DOC, FileExtractor::detectMime(self::DIR . '/ancien.doc', 'ancien.doc'));
        $this->assertSame(LegacyOffice::MIME_XLS, FileExtractor::detectMime(self::DIR . '/ancien.xls', 'ancien.xls'));
        $this->assertSame(LegacyOffice::MIME_DOC, FileExtractor::detectMime($doc, 'rapport.xls'));
        $this->assertSame(LegacyOffice::MIME_XLS, FileExtractor::detectMime($xls, 'chiffres.doc'));
        $this->assertSame('doc', LegacyOffice::kind($doc));
        $this->assertSame('xls', LegacyOffice::kind($xls));
    }

    public function testUnFauxDocNEstPasPrisPourUnDocumentWord(): void
    {
        $txt = $this->file('notes.doc', "Simple texte enregistré avec la mauvaise extension.\n");
        $this->assertNull(LegacyOffice::kind($txt));
        $this->assertNotSame(LegacyOffice::MIME_DOC, FileExtractor::detectMime($txt, 'notes.doc'));

        $exe = $this->file('budget.xls', "MZ\x90\x00" . random_bytes(2000));
        $this->assertNull(LegacyOffice::kind($exe));
        $this->assertNotSame(LegacyOffice::MIME_XLS, FileExtractor::detectMime($exe, 'budget.xls'));
    }

    // ==================================================================
    // Word : table des morceaux
    // ==================================================================

    private function docFrom(array $pieces, array $ccp = [], bool $encrypted = false): string
    {
        return $this->file('t.doc', LegacyOfficeFixture::cfb(LegacyOfficeFixture::word($pieces, $ccp, $encrypted)));
    }

    public function testDesMorceauxSurUnEtDeuxOctetsRangesDansLeDesordreSeRemettentEnOrdre(): void
    {
        $path = $this->docFrom([
            ['text' => "Bonjour é€ ", 'compressed' => true],
            ['text' => "Ωmega 日本\r", 'compressed' => false],
            ['text' => "Fin œuvre.\r", 'compressed' => true],
        ]);
        $this->assertSame('doc', LegacyOffice::kind($path));
        $this->assertSame("Bonjour é€ Ωmega 日本\nFin œuvre.", LegacyOffice::docText($path));
    }

    public function testSeulsLeTexteLesNotesEtLesZonesDeTexteSontLus(): void
    {
        $main = "Corps du texte.\r";
        $ftn  = "Note en bas.\r";
        $hdd  = "EN-TÊTE CONFIDENTIEL\r";
        $atn  = "Commentaire de relecture\r";
        $edn  = "Note de fin.\r";
        $txbx = "Encadré.\r";
        $path = $this->docFrom(
            [['text' => $main . $ftn . $hdd . $atn . $edn . $txbx, 'compressed' => true]],
            ['text' => mb_strlen($main), 'ftn' => mb_strlen($ftn), 'hdd' => mb_strlen($hdd),
             'atn' => mb_strlen($atn), 'edn' => mb_strlen($edn), 'txbx' => mb_strlen($txbx)],
        );
        $text = (string)LegacyOffice::docText($path);
        $this->assertSame("Corps du texte.\n\nNote en bas.\n\nNote de fin.\n\nEncadré.", $text);
        $this->assertStringNotContainsString('EN-TÊTE', $text);
        $this->assertStringNotContainsString('Commentaire', $text);
    }

    public function testLesChampsNeGardentQueLeurResultatMemeImbriques(): void
    {
        $this->assertSame('Voir le site.', LegacyOffice::wordToText("Voir \x13HYPERLINK \"https://exemple.org\"\x14le site\x15."));
        $this->assertSame('Page résultat', LegacyOffice::wordToText("Page \x13 IF \x13 PAGE \x14 3\x15 = 3 \x14résultat\x15"));
        $this->assertSame('a b', LegacyOffice::wordToText("a \x13 TOC \\o \x15b"), 'champ sans résultat : rien');
        $this->assertSame('reste', LegacyOffice::wordToText("\x15\x14reste"), 'marques orphelines sans effet');
    }

    public function testLesMarquesDeWordDeviennentDuTexteLisible(): void
    {
        $this->assertSame("a | b\nc | d\nsuite", LegacyOffice::wordToText("a\x07b\x07\x07c\x07d\x07\x07suite"));
        $this->assertSame("ligne\nsuivante\n\npage", LegacyOffice::wordToText("ligne\x0Bsuivante\x0Cpage"));
        $this->assertSame('porte-clé', LegacyOffice::wordToText("porte\x1Ecl\x1Fé"));
        $this->assertSame('image  texte', LegacyOffice::wordToText("image \x01\x08 texte\x02"));
    }

    public function testUnDocumentChiffreOuAbimeRendNullSansException(): void
    {
        $this->assertNull(LegacyOffice::docText($this->docFrom([['text' => "Secret\r", 'compressed' => true]], [], true)));

        $streams = LegacyOfficeFixture::word([['text' => "Texte\r", 'compressed' => true]]);
        $streams['WordDocument'][0] = "\x00"; // signature de FIB cassée
        $this->assertNull(LegacyOffice::docText($this->file('a.doc', LegacyOfficeFixture::cfb($streams))));

        $streams = LegacyOfficeFixture::word([['text' => "Texte\r", 'compressed' => true]]);
        unset($streams['1Table']); // table des morceaux absente
        $this->assertNull(LegacyOffice::docText($this->file('b.doc', LegacyOfficeFixture::cfb($streams))));

        $this->assertNull(LegacyOffice::docText($this->file('c.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . random_bytes(3000))));
        $this->assertNull(LegacyOffice::docText($this->file('d.doc', '')));
        $this->assertNull(LegacyOffice::pieces("\x02\xFF\xFF\xFF\x7F"), 'longueur annoncée hors du flux');
    }

    public function testUnDocumentIncorporeNEstPasPrisPourLeDocument(): void
    {
        // Seul un flux « WordDocument » posé dans un dossier : un objet
        // incorporé, pas le document lui-même.
        $inner = LegacyOfficeFixture::word([['text' => "Intérieur\r", 'compressed' => true]]);
        $path = $this->file('objet.doc', LegacyOfficeFixture::cfb(['Autre' => 'x'], ['ObjectPool' => $inner]));
        $this->assertNull(LegacyOffice::kind($path));
        $this->assertNull(LegacyOffice::docText($path));
    }

    public function testUneChaineDeSecteursEnBoucleNeBloquePas(): void
    {
        $bytes = LegacyOfficeFixture::cfb(LegacyOfficeFixture::word([['text' => "Texte\r", 'compressed' => true]]));
        $fatSector = unpack('V', $bytes, 0x4C)[1];
        // Premier secteur du premier flux : renvoie sur lui-même.
        $bytes = substr_replace($bytes, pack('V', 0), 512 + $fatSector * 512, 4);
        $start = microtime(true);
        $this->assertNull(LegacyOffice::docText($this->file('boucle.doc', $bytes)));
        $this->assertLessThan(2.0, microtime(true) - $start);
    }

    // ==================================================================
    // Excel : enregistrements BIFF
    // ==================================================================

    private static function sheetStream(string $globals, string $cells, bool $biff8 = true, int $dt = 0, string $name = 'Feuil1', array $extraSheets = []): string
    {
        $F = LegacyOfficeFixture::class;
        // Position des feuilles connue après coup : BOUNDSHEET de taille fixe.
        $sheets = array_merge([[$name, $dt, $cells]], $extraSheets);
        $bound = static function (array $at) use ($sheets, $biff8, $F): string {
            $out = '';
            foreach ($sheets as $i => [$n, $type]) {
                $label = $biff8
                    ? chr(mb_strlen($n)) . "\x00" . mb_convert_encoding($n, 'ISO-8859-1', 'UTF-8')
                    : chr(strlen(mb_convert_encoding($n, 'Windows-1252', 'UTF-8'))) . mb_convert_encoding($n, 'Windows-1252', 'UTF-8');
                $out .= $F::rec(0x0085, pack('V', $at[$i] ?? 0) . chr(0) . chr($type) . $label);
            }
            return $out;
        };
        $head = $F::bof(0x0005, $biff8) . $globals;
        $prefixLen = strlen($head . $bound([]) . $F::eof());
        $at = [];
        $body = '';
        foreach ($sheets as [, $type, $c]) {
            $at[] = $prefixLen + strlen($body);
            $body .= $F::bof($type === 0 ? 0x0010 : 0x0020, $biff8) . $c . $F::eof();
        }
        return $head . $bound($at) . $F::eof() . $body;
    }

    private static function cell(int $type, int $r, int $c, int $xf, string $rest): string
    {
        return LegacyOfficeFixture::rec($type, pack('vvv', $r, $c, $xf) . $rest);
    }

    public function testUneChaineCoupeeParUnContinueChangeDeLargeurSansRienPerdre(): void
    {
        $F = LegacyOfficeFixture::class;
        // Trois chaînes ; la deuxième (riche, avec données phonétiques) est
        // coupée au milieu de ses caractères : le CONTINUE redit la largeur,
        // ici en UTF-16 pour la fin (« Ω »).
        $first  = $F::xlString('Début');
        $second = pack('v', 9) . "\x0C" . pack('v', 1) . pack('V', 6) . mb_convert_encoding('Réun', 'ISO-8859-1', 'UTF-8'); // 4 car. sur 9, compressés
        $sst = pack('VV', 3, 3) . $first . $second;
        $cont = "\x01" . mb_convert_encoding('ion Ω', 'UTF-16LE', 'UTF-8') . pack('vv', 0, 0) . str_repeat("\x11", 6)
            . $F::xlString('Fin');
        $globals = $F::rec(0x00FC, $sst) . $F::rec(0x003C, $cont);
        $cells = self::cell(0x00FD, 0, 0, 0, pack('V', 0)) . self::cell(0x00FD, 0, 1, 0, pack('V', 1))
            . self::cell(0x00FD, 1, 0, 0, pack('V', 2));

        $this->assertSame(['Début', 'Réunion Ω', 'Fin'], LegacyOffice::readSst($sst, [$cont]));
        $book = LegacyOffice::parseWorkbook(self::sheetStream($globals, $cells));
        $this->assertSame([['name' => 'Feuil1', 'rows' => [['Début', 'Réunion Ω'], ['Fin']]]], $book);
    }

    public function testNombresBooleensErreursEtFormulesGardentLeurValeur(): void
    {
        $F = LegacyOfficeFixture::class;
        $num = static fn(float $v): string => pack('e', $v);
        $cells =
            self::cell(0x0203, 0, 0, 0, $num(1250.5))
            . self::cell(0x027E, 0, 1, 0, pack('V', $F::rkInt(-42)))
            . self::cell(0x027E, 0, 2, 0, pack('V', $F::rkInt(12345, true)))
            . self::cell(0x027E, 0, 3, 0, pack('V', $F::rkDouble(2.5)))
            . $F::rec(0x00BD, pack('vv', 1, 0) . pack('vV', 0, $F::rkInt(1)) . pack('vV', 0, $F::rkInt(2)) . pack('vV', 0, $F::rkInt(3)) . pack('v', 2))
            . self::cell(0x0205, 2, 0, 0, "\x01\x00") . self::cell(0x0205, 2, 1, 0, "\x00\x00") . self::cell(0x0205, 2, 2, 0, "\x07\x01")
            // Formules : nombre, texte (après une formule partagée), booléen, erreur.
            . self::cell(0x0006, 3, 0, 0, $num(0.1 + 0.2) . str_repeat("\0", 6))
            . self::cell(0x0006, 3, 1, 0, "\x00\0\0\0\0\0\xFF\xFF" . str_repeat("\0", 6))
            . $F::rec(0x04BC, str_repeat("\0", 10))
            . $F::rec(0x0207, $F::xlString('Clos le mois'))
            . self::cell(0x0006, 3, 2, 0, "\x01\0\x01\0\0\0\xFF\xFF" . str_repeat("\0", 6))
            . self::cell(0x0006, 3, 3, 0, "\x02\0\x2A\0\0\0\xFF\xFF" . str_repeat("\0", 6))
            . self::cell(0x0204, 4, 1, 0, $F::xlString('Ωmega', true));

        $rows = LegacyOffice::parseWorkbook(self::sheetStream('', $cells))[0]['rows'];
        $this->assertSame([
            ['1250.5', '-42', '123.45', '2.5'],
            ['1', '2', '3'],
            ['TRUE', 'FALSE', '#DIV/0!'],
            ['0.3', 'Clos le mois', 'TRUE', '#N/A'],
            ['', 'Ωmega'],
        ], $rows);
    }

    public function testLesDatesSuiventLeFormatDeLaCellule(): void
    {
        $F = LegacyOfficeFixture::class;
        $globals = $F::rec(0x041E, pack('v', 164) . $F::xlString('dd/mm/yyyy hh:mm'))
            . $F::rec(0x041E, pack('v', 165) . $F::xlString('"Total "#,##0.00\ "m"'))
            . $F::rec(0x041E, pack('v', 166) . $F::xlString('[$-40C]mmmm yyyy'))
            . $F::rec(0x041E, pack('v', 167) . $F::xlString('[h]:mm'))
            // XF 0 : général ; 1 : date intégrée ; 2..5 : formats ci-dessus ; 6 : pourcentage ; 7 : heure intégrée.
            . implode('', array_map(static fn(int $f): string => $F::rec(0x00E0, pack('vv', 0, $f) . str_repeat("\0", 16)), [0, 14, 164, 165, 166, 167, 10, 20]));
        $num = static fn(float $v): string => pack('e', $v);
        $cells = self::cell(0x0203, 0, 0, 1, $num(46095))
            . self::cell(0x0203, 0, 1, 2, $num(46095.5))
            . self::cell(0x0203, 0, 2, 3, $num(46095))
            . self::cell(0x0203, 0, 3, 4, $num(46095))
            . self::cell(0x0203, 0, 4, 5, $num(1.25))
            . self::cell(0x0203, 0, 5, 6, $num(0.155))
            . self::cell(0x0203, 0, 6, 7, $num(0.5))
            . self::cell(0x0203, 0, 7, 0, $num(46095));
        $rows = LegacyOffice::parseWorkbook(self::sheetStream($globals, $cells))[0]['rows'];
        $this->assertSame([['2026-03-14', '2026-03-14 12:00', '46095', '2026-03-14', '1900-01-01 06:00', '15.5%', '12:00', '46095']], $rows);

        $this->assertSame('1900-01-01', LegacyOffice::excelDate(1, false, 'date'));
        $this->assertSame('1900-02-28', LegacyOffice::excelDate(59, false, 'date'));
        $this->assertSame('1900-03-01', LegacyOffice::excelDate(61, false, 'date'), 'le 29/02/1900 fictif d’Excel');
        $this->assertSame('1904-01-02', LegacyOffice::excelDate(1, true, 'date'));
        $this->assertSame('2026-03-14 09:30:15', LegacyOffice::excelDate(46095 + (9 * 3600 + 30 * 60 + 15) / 86400, false, 'datetime'));

        $this->assertSame('number', LegacyOffice::formatKind(164, 'General'));
        $this->assertSame('number', LegacyOffice::formatKind(164, '[Red]#,##0.00'));
        $this->assertSame('time', LegacyOffice::formatKind(164, 'mm:ss'));
        $this->assertSame('date', LegacyOffice::formatKind(164, 'mmm'));
        $this->assertSame('percent', LegacyOffice::formatKind(164, '0.0%'));
    }

    public function testLeMode1904EstRespecte(): void
    {
        $F = LegacyOfficeFixture::class;
        $globals = $F::rec(0x0022, pack('v', 1)) . $F::rec(0x00E0, pack('vv', 0, 14) . str_repeat("\0", 16));
        $rows = LegacyOffice::parseWorkbook(self::sheetStream($globals, self::cell(0x0203, 0, 0, 0, pack('e', 44633.0))))[0]['rows'];
        $this->assertSame([['2026-03-14']], $rows);
    }

    public function testUnClasseurExcel95SeLitDansSaPageDeCode(): void
    {
        $F = LegacyOfficeFixture::class;
        $cp1252 = static fn(string $s): string => mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        $globals = $F::rec(0x0042, pack('v', 1252));
        $cells = self::cell(0x0204, 0, 0, 0, pack('v', strlen($cp1252('Coût €'))) . $cp1252('Coût €'))
            . self::cell(0x0203, 0, 1, 0, pack('e', 3.75));
        $stream = self::sheetStream($globals, $cells, false, 0, 'Résumé');

        $path = $this->file('vieux.xls', LegacyOfficeFixture::cfb(['Book' => $stream]));
        $this->assertSame('xls', LegacyOffice::kind($path));
        $this->assertSame("# Résumé\nCoût €\t3.75", LegacyOffice::xlsText($path));
    }

    public function testGraphiquesFeuillesVidesEtClasseurChiffreSontEcartes(): void
    {
        $F = LegacyOfficeFixture::class;
        $stream = self::sheetStream('', self::cell(0x0203, 0, 0, 0, pack('e', 1.0)), true, 0, 'Données', [
            ['Graphique', 2, self::cell(0x0203, 0, 0, 0, pack('e', 9.0))],
            ['Vide', 0, ''],
        ]);
        $this->assertSame([['name' => 'Données', 'rows' => [['1']]], ['name' => 'Vide', 'rows' => []]], LegacyOffice::parseWorkbook($stream));
        $this->assertSame("# Données\n1", LegacyOffice::xlsText($this->file('g.xls', LegacyOfficeFixture::cfb(['Workbook' => $stream]))));

        $empty = self::sheetStream('', '');
        $this->assertNull(LegacyOffice::xlsText($this->file('vide.xls', LegacyOfficeFixture::cfb(['Workbook' => $empty]))));

        $locked = self::sheetStream($F::rec(0x002F, str_repeat("\0", 54)), self::cell(0x0203, 0, 0, 0, pack('e', 1.0)));
        $this->assertNull(LegacyOffice::parseWorkbook($locked), 'FILEPASS : classeur protégé par mot de passe');
        $this->assertNull(LegacyOffice::parseWorkbook('garbage'));
    }

    public function testLesNombresSansFormatRestentLisibles(): void
    {
        $this->assertSame('3000000', LegacyOffice::number(3000000.0));
        $this->assertSame('0', LegacyOffice::number(-0.0));
        $this->assertSame('-0.125', LegacyOffice::number(-0.125));
        $this->assertSame('0.3', LegacyOffice::number(0.1 + 0.2));
        $this->assertSame('1.0E+20', LegacyOffice::number(1e20));
        $this->assertSame(-42.0, LegacyOffice::rk(LegacyOfficeFixture::rkInt(-42)));
        $this->assertSame(1.23, LegacyOffice::rk(LegacyOfficeFixture::rkInt(123, true)));
        $this->assertSame(2.5, LegacyOffice::rk(LegacyOfficeFixture::rkDouble(2.5)));
    }

    // ==================================================================
    // Câblage : accepté partout où .docx et .xlsx le sont
    // ==================================================================

    public function testLesDeuxFormatsSontAcceptesPartout(): void
    {
        foreach ([LegacyOffice::MIME_DOC, LegacyOffice::MIME_XLS] as $mime) {
            $this->assertContains($mime, UPLOAD_ALLOWED_TYPES, 'chat et départ des workflows');
            $this->assertArrayHasKey($mime, KnowledgeBaseDocument::ALLOWED_MIMES, 'bases, documents d’assistant, synchronisation');
            $this->assertTrue(KbSync::isSyncable(['mime' => $mime, 'size' => 1000]));
        }
        $this->assertSame('doc', KnowledgeBaseDocument::ALLOWED_MIMES[LegacyOffice::MIME_DOC]);
        $this->assertSame('xls', KnowledgeBaseDocument::ALLOWED_MIMES[LegacyOffice::MIME_XLS]);

        $accept = explode(',', FileExtractor::acceptAttribute(UPLOAD_ALLOWED_TYPES));
        foreach ([LegacyOffice::MIME_DOC, LegacyOffice::MIME_XLS, '.doc', '.xls'] as $token) {
            $this->assertContains($token, $accept, 'sélecteur de fichiers du chat');
        }

        $root = dirname(__DIR__, 2);
        foreach (['templates/admin/knowledge-bases/edit.php', 'templates/admin/profiles/edit.php'] as $tpl) {
            $src = (string)file_get_contents($root . '/' . $tpl);
            $this->assertStringContainsString('FileExtractor::acceptAttribute(array_keys(KnowledgeBaseDocument::ALLOWED_MIMES))', $src, $tpl);
        }
        $kbAccept = explode(',', FileExtractor::acceptAttribute(array_keys(KnowledgeBaseDocument::ALLOWED_MIMES)));
        foreach (['.doc', '.xls', 'application/msword', 'application/vnd.ms-excel'] as $token) {
            $this->assertContains($token, $kbAccept, "sélecteur des bases : $token");
        }
        foreach (['src/Drive/UserDriveService.php', 'src/Helpers/WebPageReader.php', 'src/Connectors/MailAttachments.php', 'src/Files/NativeDeck.php'] as $f) {
            $src = (string)file_get_contents($root . '/' . $f);
            $this->assertStringContainsString("'application/msword'", $src, $f);
            $this->assertStringContainsString("'application/vnd.ms-excel'", $src, $f);
        }
    }

    public function testUnePieceJointeDEmailEnAncienFormatSeLit(): void
    {
        $doc = (string)file_get_contents(self::DIR . '/ancien.doc');
        $this->assertStringContainsString('Compte rendu', MailAttachments::extractText($doc, 'application/octet-stream', 'cr.doc'));
        $xls = (string)file_get_contents(self::DIR . '/ancien.xls');
        $this->assertStringContainsString('Société Générale', MailAttachments::extractText($xls, 'application/vnd.ms-excel', 'ventes.xls'));
    }

    public function testLeLecteurEstChargePartout(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = (string)file_get_contents($root . '/' . $f);
            $legacy = strpos($src, '/src/Helpers/LegacyOffice.php');
            $this->assertNotFalse($legacy, $f);
            $this->assertLessThan(strpos($src, '/src/Helpers/FileExtractor.php'), $legacy, "$f : avant FileExtractor");
        }
    }
}
