<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Opérations des tables de travail (WorkTableOps) — pures : ce sont elles qui
 * rendent un recoupement EXHAUSTIF, donc elles ne doivent ni sauter une
 * ligne, ni rapprocher ce qui ne se ressemble pas, ni compter de travers.
 */
final class WorkTableOpsTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Noms, colonnes, lignes
    // ---------------------------------------------------------------------

    public function testNomsEtClesSontDesIdentifiantsSurs(): void
    {
        $this->assertSame('mails_relance', WorkTableOps::slug('Mails  Relance !'));
        $this->assertSame('clients_ete_2026', WorkTableOps::slug('Clients été 2026'));
        $this->assertSame('', WorkTableOps::slug('  —  '));
        $this->assertLessThanOrEqual(WorkTableOps::NAME_MAX, strlen(WorkTableOps::slug(str_repeat('très long nom ', 12))));
    }

    public function testLesColonnesOntDesClesUniques(): void
    {
        $cols = WorkTableOps::columns(['Nom', 'E-mail', 'Nom', '', ['label' => 'Montant dû', 'type' => 'number']]);
        $this->assertSame(['nom', 'e_mail', 'nom_2', 'colonne_4', 'montant_du'], WorkTableOps::keys($cols));
        $this->assertSame('number', $cols[4]['type']);
        $this->assertSame('text', $cols[0]['type']);
        $this->assertCount(WorkTableOps::MAX_COLUMNS, WorkTableOps::columns(array_map(static fn(int $i): string => 'c' . $i, range(1, 60))));
    }

    public function testLesLignesSeLisentEnListeParCleOuParLibelle(): void
    {
        $cols = WorkTableOps::columns(['Nom', 'Ville']);
        $rows = WorkTableOps::rows($cols, [
            ['Dupont', 'Lyon'],
            ['nom' => 'Martin', 'ville' => 'Paris'],
            ['Nom' => 'Durand', 'VILLE' => 'Nantes', 'inconnue' => 'x'],
            ['', ''],
            'pas une ligne',
        ]);
        $this->assertSame([
            ['nom' => 'Dupont', 'ville' => 'Lyon'],
            ['nom' => 'Martin', 'ville' => 'Paris'],
            ['nom' => 'Durand', 'ville' => 'Nantes'],
        ], $rows);
    }

    public function testUneCelluleEstBorneeEtPropre(): void
    {
        $this->assertSame('oui', WorkTableOps::cell(true));
        $this->assertSame('a, b', WorkTableOps::cell(['a', 'b']));
        $this->assertSame("ligne 1\nligne 2", WorkTableOps::cell("ligne 1\r\nligne 2"));
        $this->assertSame(WorkTableOps::MAX_CELL, mb_strlen(WorkTableOps::cell(str_repeat('é', 5000))));
    }

    public function testUneColonneSeDesigneParSaCleOuSonLibelle(): void
    {
        $cols = WorkTableOps::columns(['Nom du client', 'Montant dû']);
        $this->assertSame('nom_du_client', WorkTableOps::column($cols, 'nom_du_client'));
        $this->assertSame('nom_du_client', WorkTableOps::column($cols, 'Nom du client'));
        $this->assertSame('montant_du', WorkTableOps::column($cols, 'MONTANT DU'));
        try {
            WorkTableOps::column($cols, 'ville');
            $this->fail('Une colonne inconnue doit être refusée.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nom_du_client, montant_du', $e->getMessage(), 'Le refus nomme les colonnes qui existent.');
        }
    }

    // ---------------------------------------------------------------------
    // Valeurs : nombres, dates, emails
    // ---------------------------------------------------------------------

    public function testUnNombreNEstReconnuQueSIlEnEstUn(): void
    {
        $this->assertSame(1250.5, WorkTableOps::number('1 250,50 €'));
        $this->assertSame(-3.5, WorkTableOps::number('-3.5'));
        $this->assertSame(12.0, WorkTableOps::number('12%'));
        $this->assertNull(WorkTableOps::number('AB12'), 'Un code n’est pas un nombre.');
        $this->assertNull(WorkTableOps::number('01000'), 'Un code postal n’est pas un nombre.');
        $this->assertNull(WorkTableOps::number('2026-11-16'));
        $this->assertNull(WorkTableOps::number(''));
    }

    public function testLesDatesDeviennentTriables(): void
    {
        $this->assertSame('2026-11-16 09:30', WorkTableOps::dateKey('2026-11-16 09:30'));
        $this->assertSame('2026-11-16', WorkTableOps::dateKey('16/11/2026'));
        $this->assertSame('2026-11-16 14:05', WorkTableOps::dateKey('16/11/2026 14h05'));
        $this->assertSame('2026-10-30', WorkTableOps::dateKey('30 octobre 2026'));
        $this->assertNull(WorkTableOps::dateKey('31/02/2026'));
        $this->assertNull(WorkTableOps::dateKey('Dupont'));
        $this->assertSame('2026-01-01 01:00', WorkTableOps::stamp(1767225600, new \DateTimeZone('Europe/Paris')));
        $this->assertSame('', WorkTableOps::stamp(0));
    }

    public function testEmailsEtDomainesDUneCellule(): void
    {
        $this->assertSame(['marie.durand@acme.fr'], WorkTableOps::emails('Marie Durand <Marie.Durand@ACME.fr>'));
        $this->assertSame(['a@x.fr', 'b@y.com'], WorkTableOps::emails('a@x.fr, B@y.com; a@x.fr'));
        $this->assertSame([], WorkTableOps::emails('pas une adresse'));
        $this->assertSame(['acme.fr'], WorkTableOps::domains('Marie <marie@acme.fr>'));
        $this->assertSame(['acme.fr'], WorkTableOps::domains('https://www.acme.fr/contact'));
        $this->assertSame(['acme.fr'], WorkTableOps::domains('acme.fr'));
        $this->assertSame([], WorkTableOps::domains('Acme'));
    }

    // ---------------------------------------------------------------------
    // Filtrer
    // ---------------------------------------------------------------------

    public function testLesTestsComparentTexteNombresEtDates(): void
    {
        $this->assertTrue(WorkTableOps::test('Société Générale', 'egal', 'societe generale'));
        $this->assertTrue(WorkTableOps::test('1 200,00 €', 'egal', '1200'));
        $this->assertTrue(WorkTableOps::test('1 200', 'superieur', '900'), '1 200 > 900 : comparés comme des nombres, pas comme du texte.');
        $this->assertFalse(WorkTableOps::test('90', 'superieur', '100'));
        $this->assertTrue(WorkTableOps::test('2026-11-16 09:30', 'superieur', '2026-10-01'));
        $this->assertTrue(WorkTableOps::test('2026-11-16 09:30', 'egal', '16/11/2026'), 'Une date sans heure se compare au jour.');
        $this->assertTrue(WorkTableOps::test('16/11/2026', 'au_plus', '2026-11-16'));
        $this->assertTrue(WorkTableOps::test('Relance facture Dupont', 'contient', 'FACTURE'));
        $this->assertTrue(WorkTableOps::test('Relance facture', 'ne_contient_pas', 'devis'));
        $this->assertTrue(WorkTableOps::test('Facture 12', 'commence_par', 'fact'));
        $this->assertTrue(WorkTableOps::test('', 'vide', ''));
        $this->assertTrue(WorkTableOps::test('x', 'non_vide', ''));
        $this->assertTrue(WorkTableOps::test('Lyon', 'parmi', 'Paris | lyon | Nantes'));
        $this->assertFalse(WorkTableOps::test('Lille', 'parmi', 'Paris | Lyon'));
        $this->assertTrue(WorkTableOps::test('Lille', 'different', 'Lyon'));
        $this->assertFalse(WorkTableOps::test('', 'egal', ''), 'Une cellule vide ne vaut rien d’autre que « vide ».');
    }

    public function testFiltrerGardeToutesLesLignesQuiPassent(): void
    {
        $cols = WorkTableOps::columns(['Nom', 'Ville', 'Montant']);
        $rows = WorkTableOps::rows($cols, [
            ['Dupont', 'Lyon', '1 200'], ['Martin', 'Paris', '90'], ['Durand', 'Lyon', '300'], ['Petit', '', '5000'],
        ]);
        $conds = WorkTableOps::conditions($cols, [
            ['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'lyon'],
            ['colonne' => 'montant', 'test' => 'superieur', 'valeur' => '500'],
        ]);
        $this->assertSame(['Dupont'], array_column(WorkTableOps::filter($rows, $conds), 'nom'));
        $this->assertSame(['Dupont', 'Durand', 'Petit'], array_column(WorkTableOps::filter($rows, $conds, true), 'nom'));
        $this->assertCount(4, WorkTableOps::filter($rows, []));

        $this->expectException(\InvalidArgumentException::class);
        WorkTableOps::conditions($cols, [['colonne' => 'ville', 'test' => 'ressemble', 'valeur' => 'x']]);
    }

    // ---------------------------------------------------------------------
    // Dédoublonner, trier, regrouper
    // ---------------------------------------------------------------------

    public function testDedoublonnerGardeLaPremiereLigneDeChaqueValeur(): void
    {
        $rows = [
            ['email' => 'A@x.fr', 'n' => '1'], ['email' => 'a@x.fr', 'n' => '2'], ['email' => 'b@x.fr', 'n' => '3'], ['email' => 'É@x.fr', 'n' => '4'],
        ];
        $this->assertSame(['1', '3', '4'], array_column(WorkTableOps::dedupe($rows, ['email']), 'n'));
    }

    public function testLeTriEstTypeEtStable(): void
    {
        $cols = WorkTableOps::columns(['Nom', 'Montant', 'Date']);
        $rows = WorkTableOps::rows($cols, [
            ['b', '1 200', '2026-03-01'], ['a', '90', '16/11/2026'], ['c', '', '2026-01-15'], ['d', '300', ''],
        ]);
        $by = WorkTableOps::sortSpec($cols, [['colonne' => 'montant', 'sens' => 'decroissant']]);
        $this->assertSame(['b', 'd', 'a', 'c'], array_column(WorkTableOps::sort($rows, $by), 'nom'), 'Nombres triés comme tels, cellule vide à la fin.');
        $by = WorkTableOps::sortSpec($cols, [['colonne' => 'Date']]);
        $this->assertSame(['c', 'b', 'a', 'd'], array_column(WorkTableOps::sort($rows, $by), 'nom'), 'Dates de formes différentes triées dans le temps.');
        $this->assertSame($rows, WorkTableOps::sort($rows, []));
    }

    public function testRegrouperCompteEtCalculeParValeur(): void
    {
        $cols = WorkTableOps::columns(['Client', 'Montant', 'Date', 'Objet']);
        $rows = WorkTableOps::rows($cols, [
            ['Acme', '100', '2026-01-10', 'Devis'], ['acme', '250,50', '2026-03-02', 'Facture'],
            ['Bolt', '40', '2026-02-01', 'Devis'], ['Acme', '', '2026-02-20', 'Devis'],
        ]);
        $calcs = WorkTableOps::calcs($cols, [
            ['operation' => 'compte', 'nom' => 'Nb mails'],
            ['operation' => 'somme', 'colonne' => 'Montant'],
            ['operation' => 'max', 'colonne' => 'date', 'nom' => 'Dernier'],
            ['operation' => 'liste', 'colonne' => 'objet'],
            ['operation' => 'compte_distinct', 'colonne' => 'objet'],
        ]);
        [$outCols, $out] = WorkTableOps::group($cols, $rows, ['client'], $calcs);

        $this->assertSame(['client', 'nb_mails', 'somme_montant', 'dernier', 'liste_objet', 'compte_distinct_objet'], WorkTableOps::keys($outCols));
        $this->assertSame([
            ['client' => 'Acme', 'nb_mails' => '3', 'somme_montant' => '350.5', 'dernier' => '2026-03-02', 'liste_objet' => 'Devis ; Facture', 'compte_distinct_objet' => '2'],
            ['client' => 'Bolt', 'nb_mails' => '1', 'somme_montant' => '40', 'dernier' => '2026-02-01', 'liste_objet' => 'Devis', 'compte_distinct_objet' => '1'],
        ], $out, '« Acme » et « acme » sont le même client ; une cellule vide ne compte pas dans une somme.');

        // Sans colonne de regroupement : les totaux de la table, en une ligne.
        [, $totals] = WorkTableOps::group($cols, $rows, [], []);
        $this->assertSame([['nombre' => '4']], $totals);
    }

    // ---------------------------------------------------------------------
    // Croiser
    // ---------------------------------------------------------------------

    private static function clients(): array
    {
        $cols = WorkTableOps::columns(['Nom', 'Email']);
        return [$cols, WorkTableOps::rows($cols, [
            ['Acme', 'contact@acme.fr'], ['Bolt', 'achats@bolt.io'], ['Cirrus', 'info@cirrus.com'], ['Sans adresse', ''],
        ])];
    }

    private static function mails(): array
    {
        $cols = WorkTableOps::columns(['Date', 'Email', 'Destinataires', 'Objet']);
        return [$cols, WorkTableOps::rows($cols, [
            ['2026-11-10 09:00', 'contact@acme.fr', 'moi@ex.fr', 'Devis Acme'],
            ['2026-11-02 18:00', 'jean@acme.fr', 'moi@ex.fr', 'Relance'],
            ['2026-10-20 08:00', 'moi@ex.fr', 'Achats <ACHATS@bolt.io>, autre@z.fr', 'Votre commande'],
            ['2026-10-01 08:00', 'pub@spam.biz', 'moi@ex.fr', 'Promo'],
        ])];
    }

    public function testCroiserParEmailRendCorrespondancesEtCompteurs(): void
    {
        [$lc, $l] = self::clients();
        [$rc, $r] = self::mails();
        $res = WorkTableOps::join($lc, $l, $rc, $r, 'email', 'email', 'email', 'correspondances', false, 'mails', 'Mails');

        $this->assertSame(['Acme'], array_column($res['rows'], 'nom'));
        $this->assertSame('Devis Acme', $res['rows'][0]['objet']);
        $this->assertSame(['nom', 'email', 'date', 'mails_email', 'destinataires', 'objet'], WorkTableOps::keys($res['columns']), 'Une colonne de droite homonyme est renommée, jamais écrasée.');
        $this->assertSame([
            'lignes_gauche' => 4, 'lignes_droite' => 4, 'gauche_avec_correspondance' => 1,
            'gauche_sans_correspondance' => 3, 'droite_sans_correspondance' => 3, 'paires' => 1,
        ], $res['stats']);
    }

    public function testUneCelluleAPlusieursAdressesRapprocheChacune(): void
    {
        [$lc, $l] = self::clients();
        [$rc, $r] = self::mails();
        $res = WorkTableOps::join($lc, $l, $rc, $r, 'email', 'destinataires', 'email', 'correspondances', false, 'mails', 'Mails');
        $this->assertSame(['Bolt'], array_column($res['rows'], 'nom'), 'L’adresse du client figure parmi les destinataires, casse ignorée.');
    }

    public function testCroiserParDomaineEtQuiManque(): void
    {
        [$lc, $l] = self::clients();
        [$rc, $r] = self::mails();
        $res = WorkTableOps::join($lc, $l, $rc, $r, 'email', 'email', 'domaine', 'tout_gauche', true, 'mails', 'Mails');
        $this->assertSame(['Acme', 'Bolt', 'Cirrus', 'Sans adresse'], array_column($res['rows'], 'nom'), 'Toutes les lignes de gauche, une fois chacune.');
        $this->assertSame(['2', '0', '0', '0'], array_column($res['rows'], 'nb_correspondances'), 'Deux expéditeurs du domaine acme.fr.');
        $this->assertSame('Devis Acme', $res['rows'][0]['objet'], 'La première correspondance (la plus récente, dans l’ordre de la table de droite).');
        $this->assertSame('', $res['rows'][2]['objet']);

        $missing = WorkTableOps::join($lc, $l, $rc, $r, 'email', 'email', 'domaine', 'sans_correspondance', false, 'mails', 'Mails');
        $this->assertSame(['Bolt', 'Cirrus', 'Sans adresse'], array_column($missing['rows'], 'nom'));
        $this->assertSame(['nom', 'email'], WorkTableOps::keys($missing['columns']), '« Qui manque » : les colonnes de gauche seulement.');
    }

    public function testCroiserParNomContenuEnMotsEntiers(): void
    {
        [$lc, $l] = self::clients();
        [$rc, $r] = self::mails();
        $res = WorkTableOps::join($lc, $l, $rc, $r, 'nom', 'objet', 'contient', 'correspondances', false, 'mails', 'Mails');
        $this->assertSame(['Acme'], array_column($res['rows'], 'nom'));

        $this->assertTrue(WorkTableOps::containsWords('dupont fils', 'relance dupont fils facture'));
        $this->assertFalse(WorkTableOps::containsWords('durand', 'durandal sa'), 'Un mot entier, pas un morceau de mot.');
        $this->assertFalse(WorkTableOps::containsWords('ag', 'ag du 12'), 'Trop court pour désigner quoi que ce soit.');
    }

    public function testUnCroisementDemesureEstCoupeMaisCompteTout(): void
    {
        $cols = WorkTableOps::columns(['K']);
        $left = WorkTableOps::rows($cols, array_fill(0, 30, ['x']));
        $right = WorkTableOps::rows($cols, array_fill(0, 30, ['x']));
        $res = WorkTableOps::join($cols, $left, $cols, $right, 'k', 'k', 'exact', 'correspondances', false, 'd', 'D', 100);
        $this->assertTrue($res['truncated']);
        $this->assertCount(100, $res['rows']);
        $this->assertSame(900, $res['stats']['paires'], 'Les compteurs portent sur tout, même si la table est coupée.');
    }

    // ---------------------------------------------------------------------
    // Rendu au modèle, export, détection
    // ---------------------------------------------------------------------

    public function testLeModeleNeRecoitQueDesCellulesCourtesEtNonVides(): void
    {
        $out = WorkTableOps::forModel([['a' => str_repeat('x', 500), 'b' => '', 'c' => 'ok']]);
        $this->assertSame(['a', 'c'], array_keys($out[0]));
        $this->assertSame(WorkTableOps::PREVIEW_CELL, mb_strlen($out[0]['a']));
    }

    public function testLeCsvEstCiteEtSansColonnesTechniques(): void
    {
        $cols = WorkTableOps::columns([['label' => 'Nom'], ['label' => 'Note'], ['key' => 'id', 'label' => 'Identifiant', 'tech' => true]]);
        $rows = [['nom' => 'Dupont; "Fils"', 'note' => "ligne 1\nligne 2", 'id' => 'abc']];
        $csv = WorkTableOps::csv($cols, $rows);
        $this->assertSame("\"Nom\";\"Note\"\n\"Dupont; \"\"Fils\"\"\";\"ligne 1\nligne 2\"\n", $csv);
        $this->assertStringContainsString('"abc"', WorkTableOps::csv($cols, $rows, ';', "\n", true));

        // Relu par le lecteur de tableaux de l'application : mêmes cellules.
        $parsed = TabularData::parse($csv)->rows();
        $this->assertSame([['Nom', 'Note'], ['Dupont; "Fils"', "ligne 1\nligne 2"]], $parsed);
    }

    public function testLaDemandeDeTravailDeTableEstReconnue(): void
    {
        foreach ([
            'Croise ce fichier avec mes mails', 'Quels clients manquent ?', 'Dédoublonne la liste', 'Combien de lignes à Lyon ?',
            'Exporte-moi ça en Excel', 'cross-reference these two files', 'How many rows match?', 'Trie par date',
        ] as $msg) {
            $this->assertTrue(WorkTableOps::looksLikeTableRequest($msg), $msg);
        }
        foreach (['Merci beaucoup !', 'Résume ce document', 'Écris un email à Paul', ''] as $msg) {
            $this->assertFalse(WorkTableOps::looksLikeTableRequest($msg), $msg);
        }
        $this->assertTrue(WorkTableOps::looksLikeTableRequest('Et dans mails relance, qui a répondu ?', ['mails_relance']), 'Le nom d’une table de la conversation suffit.');
    }

    public function testLesColonnesSontTypeesDApresLeursValeurs(): void
    {
        $cols = WorkTableOps::columns(['Nom', 'Montant', 'Date', 'Code']);
        $rows = WorkTableOps::rows($cols, [['a', '12', '2026-01-01', '01000'], ['b', '1 300,5', '16/11/2026', 'AB12'], ['c', '', '', '']]);
        $this->assertSame(['text', 'number', 'date', 'text'], array_column(WorkTableOps::typed($cols, $rows), 'type'));
    }
}
