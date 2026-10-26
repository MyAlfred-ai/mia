<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mise en forme des Google Sheets (SheetsFormat) : traduction d'une plage A1 en
 * GridRange, requêtes `repeatCell`, et présentation de l'en-tête à la création.
 *
 * DEUX pièges verrouillés ici :
 *
 *  1. **GridRange est 0-based, borne de fin EXCLUSIVE**, alors que la notation
 *     A1 est 1-based et inclusive. Un décalage d'un cran stylerait la mauvaise
 *     ligne sans que rien ne le signale — d'où des valeurs exactes partout.
 *  2. **`repeatCell` remplace ce que ses `fields` désignent** : un
 *     `fields: 'userEnteredFormat'` global effacerait bordures, alignement et
 *     format de nombre existants. Les `fields` sont donc comparés au
 *     sous-champ près.
 *
 * Classe pure : tout se teste sans réseau ; le câblage chat par lecture de source.
 */
final class SheetsFormatTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // Notation A1 → bornes
    // ---------------------------------------------------------------------

    public function testUnBlocDevient0BasedAvecFinExclusive(): void
    {
        $this->assertSame(
            ['onglet' => null, 'startRowIndex' => 0, 'endRowIndex' => 10, 'startColumnIndex' => 0, 'endColumnIndex' => 3],
            SheetsFormat::parseA1('A1:C10'),
            'A1:C10 = 10 lignes × 3 colonnes → rows [0,10), cols [0,3).'
        );
        $this->assertSame(30, SheetsFormat::compterCellules(SheetsFormat::parseA1('A1:C10')));

        // Cellule unique : plage 1×1, pas une plage vide.
        $b = SheetsFormat::parseA1('B2');
        $this->assertSame([1, 2, 1, 2], [$b['startRowIndex'], $b['endRowIndex'], $b['startColumnIndex'], $b['endColumnIndex']]);
        $this->assertSame(1, SheetsFormat::compterCellules($b));
    }

    public function testLOngletEstExtraitYComprisQuote(): void
    {
        $this->assertSame('Feuille1', SheetsFormat::parseA1('Feuille1!B2:D5')['onglet']);
        // Nom contenant une espace : quoté par Google, apostrophes internes doublées.
        $this->assertSame('Mon onglet', SheetsFormat::parseA1("'Mon onglet'!A1")['onglet']);
        $this->assertSame("L'année", SheetsFormat::parseA1("'L''année'!A1")['onglet']);
        $this->assertNull(SheetsFormat::parseA1('A1')['onglet'], 'Sans onglet nommé : le premier de la feuille.');
    }

    public function testUneDimensionOuverteResteNulleEtNonZero(): void
    {
        // Poser 0 au lieu de null RESTREINDRAIT la plage au lieu de la laisser
        // ouverte : « A:C » doit couvrir toutes les lignes.
        $cols = SheetsFormat::parseA1('A:C');
        $this->assertNull($cols['startRowIndex']);
        $this->assertNull($cols['endRowIndex']);
        $this->assertSame([0, 3], [$cols['startColumnIndex'], $cols['endColumnIndex']]);
        $this->assertNull(SheetsFormat::compterCellules($cols), 'Dimension ouverte : le nombre de cellules est inconnu.');

        $lignes = SheetsFormat::parseA1('2:5');
        $this->assertSame([1, 5], [$lignes['startRowIndex'], $lignes['endRowIndex']]);
        $this->assertNull($lignes['startColumnIndex']);

        // Les clés nulles ne partent PAS dans le GridRange.
        $this->assertSame(['sheetId' => 7, 'startColumnIndex' => 0, 'endColumnIndex' => 3],
            SheetsFormat::gridRange($cols, 7));
    }

    public function testLesBornesInverseesSontRemisesDansLOrdre(): void
    {
        $this->assertSame(SheetsFormat::parseA1('A1:C3'), SheetsFormat::parseA1('C3:A1'));
    }

    public function testColonnesAuDelaDeZ(): void
    {
        // Base 26 SANS zéro : après Z (26) vient AA (27), pas A0.
        $this->assertSame(27, SheetsFormat::colToIndex('AA'));
        $this->assertSame(702, SheetsFormat::colToIndex('ZZ'));
        $this->assertSame('AA', SheetsFormat::indexToCol(27));
        $this->assertSame('ZZ', SheetsFormat::indexToCol(702));
        $this->assertSame('AAA', SheetsFormat::indexToCol(703));
        foreach ([1, 26, 27, 52, 53, 702, 703] as $i) {
            $this->assertSame($i, SheetsFormat::colToIndex(SheetsFormat::indexToCol($i)), 'Aller-retour colonne ' . $i);
        }
        $b = SheetsFormat::parseA1('AA1:AB2');
        $this->assertSame([26, 28], [$b['startColumnIndex'], $b['endColumnIndex']]);
    }

    public function testLesPlagesInvalidesSontRefusees(): void
    {
        foreach (['', 'Feuille1!', 'B2:D5:F7', '1A', 'A1:C'] as $mauvaise) {
            try {
                SheetsFormat::parseA1($mauvaise);
                $this->fail('Plage acceptée à tort : ' . var_export($mauvaise, true));
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
        // Une borne mixte est refusée AVEC explication : deviner stylerait des
        // cellules non visées.
        try {
            SheetsFormat::parseA1('A1:C');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('A1:C10', $e->getMessage(), 'Le message montre les formes correctes.');
        }
        // Formes volontairement acceptées : une lettre seule = colonne entière,
        // un nombre seul = ligne entière.
        $this->assertSame([0, 1], [SheetsFormat::parseA1('A')['startColumnIndex'], SheetsFormat::parseA1('A')['endColumnIndex']]);
        $this->assertSame([1, 2], [SheetsFormat::parseA1('2')['startRowIndex'], SheetsFormat::parseA1('2')['endRowIndex']]);
    }

    public function testLaPlageEstDecriteLisiblementPourLaCarte(): void
    {
        $this->assertSame('plage A1:C10', SheetsFormat::decrirePlage(SheetsFormat::parseA1('A1:C10')));
        $this->assertSame('cellule B2', SheetsFormat::decrirePlage(SheetsFormat::parseA1('B2')));
        $this->assertSame('colonnes A à C', SheetsFormat::decrirePlage(SheetsFormat::parseA1('A:C')));
        $this->assertSame('lignes 2 à 5', SheetsFormat::decrirePlage(SheetsFormat::parseA1('2:5')));
    }

    // ---------------------------------------------------------------------
    // Style → repeatCell
    // ---------------------------------------------------------------------

    public function testLesFieldsSontAuSousChampPres(): void
    {
        $style = SheetsFormat::styleDepuisArgs([
            'gras' => 'true', 'couleur_fond' => 'bleu', 'format' => 'monnaie', 'alignement' => 'droite',
        ]);
        $req = SheetsFormat::cellFormatRequest($style, SheetsFormat::gridRange(SheetsFormat::parseA1('A1:D1'), 42));

        $this->assertSame(
            'userEnteredFormat.textFormat.bold,userEnteredFormat.backgroundColor,'
            . 'userEnteredFormat.horizontalAlignment,userEnteredFormat.numberFormat',
            $req['repeatCell']['fields'],
            'Un `userEnteredFormat` global effacerait bordures et formats existants.'
        );
        $this->assertSame(42, $req['repeatCell']['range']['sheetId']);
        $format = $req['repeatCell']['cell']['userEnteredFormat'];
        $this->assertTrue($format['textFormat']['bold']);
        $this->assertSame('RIGHT', $format['horizontalAlignment']);
        $this->assertSame(['type' => 'CURRENCY', 'pattern' => '#,##0.00 €'], $format['numberFormat']);
        // Couleur : même objet rgb que l'API Docs, palette FR partagée.
        $this->assertEqualsWithDelta(0.4627, $format['backgroundColor']['blue'], 0.001);
    }

    public function testRetirerUnStyleEstDistinctDeNePasYToucher(): void
    {
        // false = retirer le gras (envoyé) ; absent = ne pas toucher (pas de field).
        $retrait = SheetsFormat::cellFormatRequest(SheetsFormat::styleDepuisArgs(['gras' => 'false']), ['sheetId' => 1]);
        $this->assertFalse($retrait['repeatCell']['cell']['userEnteredFormat']['textFormat']['bold']);
        $this->assertSame('userEnteredFormat.textFormat.bold', $retrait['repeatCell']['fields']);

        $this->assertNull(SheetsFormat::cellFormatRequest([], ['sheetId' => 1]),
            'Style vide (gel seul) : aucune requête de format.');
    }

    public function testValeursInvalidesRefuseesEtStyleVideRefuse(): void
    {
        foreach ([['alignement' => 'justifie'], ['format' => 'euros'], ['taille' => 200]] as $args) {
            try {
                SheetsFormat::styleDepuisArgs($args);
                $this->fail('Valeur acceptée à tort : ' . json_encode($args));
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(RuntimeException::class); // aucun style = appel inutile
        SheetsFormat::styleDepuisArgs(['fichier' => 'x', 'plage' => 'A1']);
    }

    public function testGelerSeulEstUnAppelLegitime(): void
    {
        // `styleDepuisArgs` refuse un style vide, mais geler une ligne sans
        // autre mise en forme est valide : demandeDepuisArgs tranche en UN point.
        $demande = SheetsFormat::demandeDepuisArgs(['geler' => true]);
        $this->assertSame([], $demande['style']);
        $this->assertTrue($demande['geler']);

        $avec = SheetsFormat::demandeDepuisArgs(['gras' => true, 'geler' => 'false']);
        $this->assertTrue($avec['style']['gras']);
        $this->assertFalse($avec['geler']);

        $this->expectException(RuntimeException::class); // ni style ni gel
        SheetsFormat::demandeDepuisArgs(['plage' => 'A1']);
    }

    // ---------------------------------------------------------------------
    // En-tête posé à la création
    // ---------------------------------------------------------------------

    public function testLEnTeteEstGrasFondGeleEtColonnesAjustees(): void
    {
        $reqs = SheetsFormat::headerRequests(0, 4);
        $this->assertCount(3, $reqs);

        $this->assertSame(['sheetId' => 0, 'startRowIndex' => 0, 'endRowIndex' => 1,
                           'startColumnIndex' => 0, 'endColumnIndex' => 4], $reqs[0]['repeatCell']['range'],
            'Première ligne seulement.');
        $this->assertSame('userEnteredFormat.textFormat.bold,userEnteredFormat.backgroundColor',
            $reqs[0]['repeatCell']['fields'],
            'Au sous-champ près : un format global effacerait ce que l\'utilisateur a déjà posé.');
        $this->assertSame(1, $reqs[1]['updateSheetProperties']['properties']['gridProperties']['frozenRowCount']);
        $this->assertSame('COLUMNS', $reqs[2]['autoResizeDimensions']['dimensions']['dimension']);

        // Largeur d'un tableau irrégulier = la ligne la plus large.
        $this->assertSame(3, SheetsFormat::largeur([[1, 2, 3], [1]]));
        $this->assertSame(1, SheetsFormat::largeur([]) === 0 ? 1 : SheetsFormat::largeur([['a']]));
    }

    // ---------------------------------------------------------------------
    // Câblage
    // ---------------------------------------------------------------------

    public function testLaCreationPoseLEnTeteSansFaireEchouerLaFeuille(): void
    {
        $srv = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $bloc = $this->extraire($srv, 'public function createSheet(', "\n    /** batchUpdate Sheets");
        $this->assertStringContainsString('SheetsFormat::headerRequests(', $bloc);
        $this->assertStringContainsString('count($rows) > 1', $bloc,
            'Une seule ligne : pas d\'en-tête à distinguer.');
        $this->assertStringContainsString('catch (\RuntimeException', $bloc,
            'Best-effort : les valeurs sont déjà écrites, une feuille non décorée vaut mieux qu\'un échec annoncé.');
    }

    public function testLeSheetIdNumeriqueEstResolu(): void
    {
        $srv = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        // Les requêtes batchUpdate emploient le gid, jamais le nom d'onglet.
        $this->assertStringContainsString('private function resolveSheetTab(', $srv);
        $bloc = $this->extraire($srv, 'private function resolveSheetTab(', "\n    /**");
        $this->assertStringContainsString('sheetId', $bloc);
        $this->assertStringContainsString('Onglets disponibles', $bloc,
            'Onglet introuvable : le message liste ceux qui existent.');
    }

    public function testLOutilEstExposeGardeEtExecutable(): void
    {
        $src = AgentSource::chat();

        $this->assertStringContainsString("'name'        => 'mettre_en_forme_google_sheet'", $src);
        $this->assertStringContainsString("case 'mettre_en_forme_google_sheet':", $src, 'Pas exécutable après approbation.');
        $this->assertStringContainsString("\$queueConnectorWrite('mettre_en_forme_google_sheet'", $src,
            'La mise en forme passe par la file d\'approbation, jamais exécutée pendant le stream.');
        $this->assertArrayHasKey('mettre_en_forme_google_sheet', ChatController::ACTION_LABELS);
        $this->assertStringContainsString("'phase' => 'sheets_format'", $src);

        // Handler ET exécution passent par la MÊME résolution du gel.
        $this->assertSame(2, substr_count($src, 'SheetsFormat::demandeDepuisArgs('),
            'Deux appelants, une seule règle : sinon « geler seul » marcherait d\'un côté et pas de l\'autre.');

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('sheets_format:', $js, 'Une phase sans libellé front n\'affiche rien.');
    }

    public function testLeMessageFinalNommeLOngletEtLaPlage(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString("onglet « {onglet} », {plage} : {styles}", $src);
        // Le type est déduit du nom d'outil : « sheet » → Google Sheet.
        $this->assertStringContainsString("str_contains(\$tool, 'sheet') => 'Google Sheet'", $src);
    }

    public function testSheetsFormatEstChargePartoutApresDocsFormat(): void
    {
        // Pas d'autoloader ; et la palette de couleurs vient de DocsFormat —
        // une classe absente d'une des DEUX listes ne planterait que dans le
        // worker, en production.
        foreach (['/config/classes.php', '/tests/bootstrap.php'] as $liste) {
            $contenu = (string)file_get_contents(ROOT . $liste);
            $posDocs   = strpos($contenu, '/src/Drive/DocsFormat.php');
            $posSheets = strpos($contenu, '/src/Drive/SheetsFormat.php');
            $this->assertNotFalse($posSheets, $liste . ' : SheetsFormat absent.');
            $this->assertNotFalse($posDocs, $liste . ' : DocsFormat absent.');
            $this->assertLessThan($posSheets, $posDocs, $liste . ' : DocsFormat doit précéder SheetsFormat.');
        }
    }

    public function testLaPaletteDeCouleursNEstPasDupliquee(): void
    {
        $sheets = (string)file_get_contents(ROOT . '/src/Drive/SheetsFormat.php');
        $this->assertStringContainsString('DocsFormat::parseCouleur(', $sheets);
        $this->assertStringNotContainsString('const COULEURS', $sheets,
            'Deux palettes divergentes n\'accepteraient pas les mêmes noms dans un Doc et dans un Sheet.');
    }

    /** Extrait la portion de source entre deux marqueurs (bornes exclues). */
    private function extraire(string $src, string $de, string $a): string
    {
        $debut = strpos($src, $de);
        $this->assertNotFalse($debut, 'Marqueur introuvable : ' . $de);
        $fin = strpos($src, $a, $debut + strlen($de));
        $this->assertNotFalse($fin, 'Marqueur introuvable : ' . $a);
        return substr($src, $debut, $fin - $debut);
    }
}
