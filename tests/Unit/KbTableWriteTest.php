<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Ce que MIA écrit dans une table PERMANENTE (KbTableWrite) — calcul pur :
 * la demande vérifiée, le résultat, ce qui change (c'est ce que la carte
 * montre), et les refus. Rien n'est jamais coupé, rien n'est inventé.
 */
final class KbTableWriteTest extends TestCase
{
    /** Table permanente : clients. */
    private static function target(): array
    {
        $columns = WorkTableOps::columns(['Nom', 'Email', 'Ville', 'Statut']);
        $rows    = WorkTableOps::rows($columns, [
            ['Acme', 'contact@acme.fr', 'Lyon', ''],
            ['Bolt', 'hello@bolt.io', 'Paris', 'actif'],
            ['Cube', 'info@cube.fr', 'Nantes', ''],
        ]);
        return [$columns, $rows];
    }

    /** Table de la conversation : ce que MIA a établi. */
    private static function source(): array
    {
        $columns = WorkTableOps::columns(['Email', 'Statut', 'Dernier contact']);
        $columns[] = ['key' => 'id', 'label' => 'id', 'type' => 'text', 'tech' => true];
        $rows = [
            ['email' => 'CONTACT@acme.fr', 'statut' => 'à relancer', 'dernier_contact' => '2026-09-01', 'id' => 'm1'],
            ['email' => 'hello@bolt.io', 'statut' => 'actif', 'dernier_contact' => '2026-10-12', 'id' => 'm2'],
            ['email' => 'nouveau@delta.fr', 'statut' => 'prospect', 'dernier_contact' => '', 'id' => 'm3'],
        ];
        return [$columns, $rows];
    }

    // ---------------------------------------------------------------------
    // 1. La demande
    // ---------------------------------------------------------------------

    public function testLaDemandeEstVerifieeAvantToutCalcul(): void
    {
        [$tc] = self::target();
        [$sc] = self::source();

        $spec = KbTableWrite::spec($tc, $sc, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut', 'Dernier contact', 'Email']]);
        $this->assertSame('mettre_a_jour', $spec['mode']);
        $this->assertSame('email', $spec['key']);
        $this->assertSame('email', $spec['source_key'], 'Sans cle_source, la même colonne est cherchée dans la source.');
        $this->assertSame(['statut', 'dernier_contact'], $spec['columns'], 'La clé ne se reporte pas sur elle-même.');
        $this->assertFalse($spec['add_missing']);

        // Valeurs fixes : un JSON en texte (un objet libre est refusé par certains modèles) ou un tableau.
        $fixed = KbTableWrite::spec($tc, $sc, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'definir' => '{"Statut": "à relancer"}', 'ajouter_absents' => 'true']);
        $this->assertSame(['Statut' => 'à relancer'], $fixed['set']);
        $this->assertTrue($fixed['add_missing']);

        // Ajouter ou remplacer sans colonnes : toutes les colonnes VISIBLES de la source.
        $append = KbTableWrite::spec($tc, $sc, ['mode' => 'ajouter']);
        $this->assertSame(['email', 'statut', 'dernier_contact'], $append['columns'], 'Jamais une colonne technique.');
        $this->assertSame('', $append['key']);
    }

    public function testUneDemandeBancaleEstRefuseeAvecSaRaison(): void
    {
        [$tc] = self::target();
        [$sc] = self::source();
        $cases = [
            'mode inconnu'      => [['mode' => 'vider'], 'mode'],
            'sans clé'          => [['mode' => 'mettre_a_jour', 'colonnes' => ['Statut']], 'cle'],
            'clé absente'       => [['mode' => 'mettre_a_jour', 'cle' => 'Téléphone', 'colonnes' => ['Statut']], 'Téléphone'],
            'rien à écrire'     => [['mode' => 'mettre_a_jour', 'cle' => 'Email'], 'Rien à écrire'],
            'colonne inconnue'  => [['mode' => 'ajouter', 'colonnes' => ['Chiffre']], 'Chiffre'],
        ];
        foreach ($cases as $label => [$args, $needle]) {
            try {
                KbTableWrite::spec($tc, $sc, $args);
                $this->fail("$label : la demande aurait dû être refusée.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), $label);
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. Mettre à jour
    // ---------------------------------------------------------------------

    public function testMettreAJourNeToucheQueLesLignesRetrouvees(): void
    {
        [$tc, $tr] = self::target();
        [$sc, $sr] = self::source();
        $spec = KbTableWrite::spec($tc, $sc, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut', 'Dernier contact']]);
        $plan = KbTableWrite::plan($tc, $tr, $sc, $sr, $spec);

        // La clé se compare sans casse : CONTACT@acme.fr retrouve contact@acme.fr.
        $this->assertSame('à relancer', $plan['rows'][0]['statut']);
        $this->assertSame('2026-09-01', $plan['rows'][0]['dernier_contact']);
        $this->assertSame('actif', $plan['rows'][1]['statut']);
        $this->assertSame('', $plan['rows'][2]['statut'], 'Une ligne absente de la source ne bouge pas.');
        $this->assertSame('Lyon', $plan['rows'][0]['ville'], 'Les autres colonnes ne bougent pas.');
        $this->assertCount(3, $plan['rows'], 'Sans ajouter_absents, aucune ligne ne s’ajoute.');

        $s = $plan['stats'];
        $this->assertSame(3, $s['before']);
        $this->assertSame(3, $s['after']);
        $this->assertSame(2, $s['touched'], 'Acme (statut + date) et Bolt (date seule).');
        $this->assertSame(0, $s['added']);
        $this->assertSame(1, $s['unmatched'], 'nouveau@delta.fr ne se retrouve pas.');
        $this->assertSame(3, $s['cells'], 'Une valeur déjà identique ne compte pas.');
        $this->assertSame(['Dernier contact'], $s['columns_added']);
        $this->assertSame(['Statut', 'Dernier contact'], $s['columns_touched']);
        $this->assertSame(['nom', 'email', 'ville', 'statut', 'dernier_contact'], WorkTableOps::keys($plan['columns']));

        // Les exemples de la carte : la ligne, la colonne, avant, après.
        $this->assertSame(['line' => 'Acme', 'column' => 'Statut', 'before' => '', 'after' => 'à relancer'], $plan['samples'][0]);
        $this->assertTrue(KbTableWrite::changes($plan));
    }

    public function testLesAbsentsSAjoutentSeulementSurDemande(): void
    {
        [$tc, $tr] = self::target();
        [$sc, $sr] = self::source();
        $spec = KbTableWrite::spec($tc, $sc, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut'], 'ajouter_absents' => true]);
        $plan = KbTableWrite::plan($tc, $tr, $sc, $sr, $spec);
        $this->assertCount(4, $plan['rows']);
        $this->assertSame(['nom' => '', 'email' => 'nouveau@delta.fr', 'ville' => '', 'statut' => 'prospect'], $plan['rows'][3]);
        $this->assertSame(1, $plan['stats']['added']);
    }

    public function testUneValeurFixeSePoseSurChaqueLigneRetrouvee(): void
    {
        [$tc, $tr] = self::target();
        [$sc, $sr] = self::source();
        $spec = KbTableWrite::spec($tc, $sc, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'definir' => ['Vu par MIA' => 'oui']]);
        $plan = KbTableWrite::plan($tc, $tr, $sc, $sr, $spec);
        $this->assertSame(['oui', 'oui', ''], array_column($plan['rows'], 'vu_par_mia'));
        $this->assertSame(['Vu par MIA'], $plan['stats']['columns_added']);
        $this->assertSame(2, $plan['stats']['touched']);
    }

    public function testRienAEcrireSeDitSansCarte(): void
    {
        [$tc, $tr] = self::target();
        [$sc] = self::source();
        // La source ne porte que ce que la table dit déjà.
        $same = [['email' => 'hello@bolt.io', 'statut' => 'actif', 'dernier_contact' => '', 'id' => 'x']];
        $spec = KbTableWrite::spec($tc, $sc, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut']]);
        $plan = KbTableWrite::plan($tc, $tr, $sc, $same, $spec);
        $this->assertFalse(KbTableWrite::changes($plan));
        $this->assertSame([], $plan['samples']);

        // Aucune clé ne se retrouve : rien non plus, et le compteur le dit.
        $none = KbTableWrite::plan($tc, $tr, $sc, [['email' => 'x@y.fr', 'statut' => 'z', 'dernier_contact' => '', 'id' => 'y']], $spec);
        $this->assertFalse(KbTableWrite::changes($none));
        $this->assertSame(1, $none['stats']['unmatched']);
    }

    // ---------------------------------------------------------------------
    // 3. Ajouter, remplacer
    // ---------------------------------------------------------------------

    public function testAjouterPoseLesLignesEnFinDeTable(): void
    {
        [$tc, $tr] = self::target();
        [$sc, $sr] = self::source();
        $spec = KbTableWrite::spec($tc, $sc, ['mode' => 'ajouter', 'colonnes' => ['Email', 'Statut']]);
        $plan = KbTableWrite::plan($tc, $tr, $sc, $sr, $spec);
        $this->assertCount(6, $plan['rows']);
        $this->assertSame('Acme', $plan['rows'][0]['nom'], 'Les lignes d’avant restent, dans l’ordre.');
        $this->assertSame(['nom' => '', 'email' => 'nouveau@delta.fr', 'ville' => '', 'statut' => 'prospect'], $plan['rows'][5]);
        $this->assertSame(3, $plan['stats']['added']);
        $this->assertSame([], $plan['stats']['columns_added'], 'Email et Statut existent déjà.');
    }

    public function testRemplacerPrendLeContenuDeLaSourceSansSesColonnesTechniques(): void
    {
        [$tc, $tr] = self::target();
        [$sc, $sr] = self::source();
        $spec = KbTableWrite::spec($tc, $sc, ['mode' => 'remplacer']);
        $plan = KbTableWrite::plan($tc, $tr, $sc, $sr, $spec);
        $this->assertSame(['email', 'statut', 'dernier_contact'], WorkTableOps::keys($plan['columns']));
        $this->assertCount(3, $plan['rows']);
        $this->assertArrayNotHasKey('id', $plan['rows'][0]);
        $this->assertSame(3, $plan['stats']['before']);
        $this->assertSame(3, $plan['stats']['after']);
        $this->assertTrue(KbTableWrite::changes($plan), 'Même nombre de lignes, contenu remplacé : c’est un changement.');
    }

    public function testAuDelaDuPlafondLEcritureEstRefuseeJamaisCoupee(): void
    {
        $columns = WorkTableOps::columns(['Nom']);
        $rows    = [];
        for ($i = 0; $i < KbTableStore::MAX_ROWS; $i++) {
            $rows[] = ['nom' => 'L' . $i];
        }
        $spec = KbTableWrite::spec($columns, $columns, ['mode' => 'ajouter']);
        try {
            KbTableWrite::plan($columns, $rows, $columns, [['nom' => 'une de trop']], $spec);
            $this->fail('Une ligne au-delà du plafond aurait dû être refusée.');
        } catch (KbTableTooLargeException $e) {
            $this->assertSame(KbTableStore::MAX_ROWS + 1, $e->rowsFound);
        }
    }

    // ---------------------------------------------------------------------
    // 4. La carte
    // ---------------------------------------------------------------------

    public function testLaCarteDitCeQuiChangeEtMontreAvantApres(): void
    {
        [$tc, $tr] = self::target();
        [$sc, $sr] = self::source();
        $spec  = KbTableWrite::spec($tc, $sc, ['mode' => 'mettre_a_jour', 'cle' => 'Email', 'colonnes' => ['Statut', 'Dernier contact']]);
        $plan  = KbTableWrite::plan($tc, $tr, $sc, $sr, $spec);
        $table = ['title' => 'Clients 2026', 'name' => 'clients_2026'];
        $card  = KbTableWrite::preview($table, $spec, $plan, 'Suivi des mails', 2);

        $this->assertSame('Modifier la table « Clients 2026 »', $card['action']);
        $this->assertStringContainsString('2 lignes modifiées', $card['fichier']);
        $this->assertSame('/assets/img/table.svg', $card['icone']);
        $impacts = implode("\n", $card['impacts']);
        $this->assertStringContainsString('Aucune ligne ajoutée ni supprimée.', $impacts);
        $this->assertStringContainsString('1 ligne de « Suivi des mails » ne se retrouve pas dans la table : ignorée.', $impacts);
        $this->assertStringContainsString('Colonne créée : « Dernier contact ».', $impacts);
        $this->assertStringContainsString('Table partagée', $impacts);
        $this->assertStringContainsString('historique de la table : on peut revenir en arrière', $impacts);

        $this->assertSame(['Ligne', 'Colonne', 'Avant', 'Après'], $card['grille']['colonnes']);
        $this->assertSame(['Acme', 'Statut', 'vide', 'à relancer'], $card['grille']['lignes'][0]);
        $this->assertSame('', $card['grille']['suite'], 'Trois valeurs changées, trois montrées.');

        // Non partagée : la ligne de partage disparaît. Remplacement : le compte avant → après.
        $solo = KbTableWrite::preview($table, $spec, $plan, 'Suivi', 0);
        $this->assertStringNotContainsString('Table partagée', implode("\n", $solo['impacts']));
        $rSpec = KbTableWrite::spec($tc, $sc, ['mode' => 'remplacer']);
        $rCard = KbTableWrite::preview($table, $rSpec, KbTableWrite::plan($tc, $tr, $sc, $sr, $rSpec), 'Suivi');
        $this->assertSame('Remplacer le contenu de la table « Clients 2026 »', $rCard['action']);
        $this->assertStringContainsString('→', $rCard['fichier']);
        $this->assertArrayNotHasKey('grille', $rCard, 'Un remplacement n’a pas de valeur « avant / après » ligne à ligne.');

        // La raison gardée avec la version d'avant.
        $this->assertSame('2 lignes modifiées par MIA', KbTableWrite::reason($spec, $plan));
    }

    public function testLaGrilleAnnonceCeQuElleNeMontrePas(): void
    {
        $columns = WorkTableOps::columns(['Nom', 'Statut']);
        $rows    = [];
        $src     = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['nom' => 'Client ' . $i, 'statut' => ''];
            $src[]  = ['nom' => 'Client ' . $i, 'statut' => 'vu'];
        }
        $spec = KbTableWrite::spec($columns, $columns, ['mode' => 'mettre_a_jour', 'cle' => 'Nom', 'colonnes' => ['Statut']]);
        $plan = KbTableWrite::plan($columns, $rows, $columns, $src, $spec);
        $card = KbTableWrite::preview(['title' => 'T'], $spec, $plan, 'S');
        $this->assertCount(KbTableWrite::SAMPLES, $card['grille']['lignes']);
        $this->assertSame('+ 12 autres valeurs modifiées', $card['grille']['suite']);
    }

    // ---------------------------------------------------------------------
    // 5. Modes directs : la demande suffit, aucune table de travail
    // ---------------------------------------------------------------------

    public function testDefinirEcritDesValeursSurLesLignesFiltrees(): void
    {
        [$tc, $tr] = self::target();
        $this->assertFalse(KbTableWrite::usesSource('definir', []), 'Aucune table de la conversation à lire.');
        $spec = KbTableWrite::spec($tc, [], [
            'mode'    => 'definir',
            'definir' => '{"Statut": "à relancer", "Relance": "17/11/2026"}',
            'filtres' => [['colonne' => 'Statut', 'test' => 'vide']],
        ]);
        $plan = KbTableWrite::plan($tc, $tr, [], [], $spec);

        $this->assertSame(2, $plan['stats']['matched'], 'Acme et Cube n’ont pas de statut.');
        $this->assertSame(2, $plan['stats']['touched']);
        $this->assertSame(4, $plan['stats']['cells']);
        $this->assertSame(['Relance'], $plan['stats']['columns_added'], 'Une colonne absente est créée.');
        $this->assertSame('à relancer', $plan['rows'][0]['statut']);
        $this->assertSame('actif', $plan['rows'][1]['statut'], 'Une ligne que le filtre écarte ne bouge pas.');
        $this->assertSame('', $plan['rows'][1]['relance']);
        $this->assertSame('17/11/2026', $plan['rows'][2]['relance']);
        $this->assertCount(3, $plan['rows'], 'Ni ligne ajoutée, ni ligne supprimée.');

        $card = KbTableWrite::preview(['title' => 'Clients 2026', 'columns' => $tc], $spec, $plan, '');
        $this->assertSame('Modifier la table « Clients 2026 »', $card['action']);
        $this->assertStringContainsString('2 lignes modifiées', $card['fichier']);
        $this->assertStringContainsString('Lignes visées : Statut est vide (2 lignes).', implode("\n", $card['impacts']));
        $this->assertSame(['Acme', 'Statut', 'vide', 'à relancer'], $card['grille']['lignes'][0]);
        $this->assertSame('2 lignes modifiées par MIA', KbTableWrite::reason($spec, $plan));

        // Sans filtre : toutes les lignes, et la carte le dit.
        $all  = KbTableWrite::spec($tc, [], ['mode' => 'definir', 'definir' => ['Ville' => 'Lyon']]);
        $plan = KbTableWrite::plan($tc, $tr, [], [], $all);
        $this->assertSame(3, $plan['stats']['matched']);
        $this->assertSame(2, $plan['stats']['touched'], 'Acme est déjà à Lyon : pas comptée comme modifiée.');
        $this->assertStringContainsString('Toutes les lignes de la table sont visées.', implode("\n", KbTableWrite::preview(['title' => 'T', 'columns' => $tc], $all, $plan, '')['impacts']));

        // Une colonne seulement créée (aucune valeur) : c'est un changement, et il est nommé.
        $col  = KbTableWrite::spec($tc, [], ['mode' => 'definir', 'definir' => ['Priorité' => '']]);
        $plan = KbTableWrite::plan($tc, $tr, [], [], $col);
        $this->assertTrue(KbTableWrite::changes($plan));
        $this->assertSame('Colonne « Priorité » ajoutée par MIA', KbTableWrite::reason($col, $plan));
        $this->assertSame('Colonne « Priorité » ajoutée', KbTableWrite::preview(['title' => 'T', 'columns' => $tc], $col, $plan, '')['fichier']);

        // Aucune ligne retenue : rien ne change.
        $none = KbTableWrite::spec($tc, [], ['mode' => 'definir', 'definir' => ['Statut' => 'x'], 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Brest']]]);
        $this->assertFalse(KbTableWrite::changes(KbTableWrite::plan($tc, $tr, [], [], $none)));
    }

    public function testSupprimerDesLignesExigeUnFiltreEtLesMontre(): void
    {
        [$tc, $tr] = self::target();
        $spec = KbTableWrite::spec($tc, [], [
            'mode'      => 'supprimer_lignes',
            'filtres'   => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'lyon'], ['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Nantes']],
            'condition' => 'au_moins_une',
        ]);
        $plan = KbTableWrite::plan($tc, $tr, [], [], $spec);
        $this->assertSame(2, $plan['stats']['deleted']);
        $this->assertSame([3, 1], [$plan['stats']['before'], $plan['stats']['after']]);
        $this->assertSame('Bolt', $plan['rows'][0]['nom']);
        $this->assertTrue(KbTableWrite::changes($plan));

        $card = KbTableWrite::preview(['title' => 'Clients 2026', 'columns' => $tc], $spec, $plan, '');
        $this->assertSame('Supprimer des lignes de la table « Clients 2026 »', $card['action']);
        $this->assertSame('2 lignes supprimées', $card['fichier']);
        $impacts = implode("\n", $card['impacts']);
        $this->assertStringContainsString('Lignes visées : Ville = « lyon » ou Ville = « Nantes » (2 lignes).', $impacts);
        $this->assertStringContainsString('Il restera 1 ligne dans la table.', $impacts);
        $this->assertSame(['Nom', 'Email', 'Ville', 'Statut'], $card['grille']['colonnes'], 'Les lignes supprimées sont montrées telles quelles.');
        $this->assertSame(['Acme', 'contact@acme.fr', 'Lyon', ''], $card['grille']['lignes'][0]);
        $this->assertSame('2 lignes supprimées par MIA', KbTableWrite::reason($spec, $plan));

        // Sans filtre : refusé — vider une table n'est pas une demande à deviner.
        try {
            KbTableWrite::spec($tc, [], ['mode' => 'supprimer_lignes']);
            $this->fail('Une suppression sans filtre est refusée.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('filtres', $e->getMessage());
        }
    }

    public function testAjouterDesLignesDonneesDansLaDemande(): void
    {
        [$tc, $tr] = self::target();
        $args = ['mode' => 'ajouter', 'colonnes' => ['Nom', 'Ville'], 'lignes' => [['Dune', 'Lille'], ['Eole', 'Brest'], ['', '']]];
        $this->assertFalse(KbTableWrite::usesSource('ajouter', $args), 'Les lignes sont dans la demande.');
        $this->assertTrue(KbTableWrite::usesSource('ajouter', ['depuis' => 'x']), 'Sans `lignes`, on lit une table de la conversation.');
        $spec = KbTableWrite::spec($tc, [], $args);
        $this->assertSame(['labels' => ['Nom', 'Ville'], 'rows' => [['Dune', 'Lille'], ['Eole', 'Brest']]], $spec['inline'], 'Une ligne vide est écartée.');

        $plan = KbTableWrite::plan($tc, $tr, [], [], $spec);
        $this->assertSame(2, $plan['stats']['added']);
        $this->assertSame(['nom' => 'Eole', 'email' => '', 'ville' => 'Brest', 'statut' => ''], $plan['rows'][4]);
        $card = KbTableWrite::preview(['title' => 'Clients 2026', 'columns' => $tc], $spec, $plan, '');
        $this->assertSame('Ajouter des lignes à la table « Clients 2026 »', $card['action']);
        $this->assertSame(['Nom', 'Ville'], $card['grille']['colonnes'], 'Seules les colonnes que les lignes remplissent : rien de ce qui est donné ne reste hors de la carte.');
        $this->assertSame(['Dune', 'Lille'], $card['grille']['lignes'][0], 'Les lignes ajoutées sont montrées.');

        // Sans `colonnes` : l'ordre des colonnes de la table. En objets, ou en JSON : compris aussi.
        $ordered = KbTableWrite::spec($tc, [], ['mode' => 'ajouter', 'lignes' => [['Fox', 'fox@ex.fr', 'Paris']]]);
        $this->assertSame('fox@ex.fr', KbTableWrite::plan($tc, $tr, [], [], $ordered)['rows'][3]['email']);
        $objects = KbTableWrite::spec($tc, [], ['mode' => 'ajouter', 'lignes' => '[{"nom": "Gex", "VILLE": "Pau"}]']);
        $this->assertSame('Pau', KbTableWrite::plan($tc, $tr, [], [], $objects)['rows'][3]['ville']);

        // Trop de lignes dans la demande : une table de travail s'impose.
        $many = array_fill(0, KbTableWrite::INLINE_ROWS_MAX + 1, ['x', 'y']);
        $this->expectException(\InvalidArgumentException::class);
        KbTableWrite::spec($tc, [], ['mode' => 'ajouter', 'lignes' => $many]);
    }

    public function testRenommerEtSupprimerUneColonne(): void
    {
        [$tc, $tr] = self::target();
        $spec = KbTableWrite::spec($tc, [], ['mode' => 'renommer_colonne', 'colonne' => 'Statut', 'nouveau_nom' => '  État  du compte ']);
        $plan = KbTableWrite::plan($tc, $tr, [], [], $spec);
        $this->assertSame(['Nom', 'Email', 'Ville', 'État du compte'], array_column($plan['columns'], 'label'));
        $this->assertSame(['nom', 'email', 'ville', 'statut'], WorkTableOps::keys($plan['columns']), 'La clé ne bouge pas : les lignes restent lisibles.');
        $this->assertSame($tr, $plan['rows']);
        $this->assertTrue(KbTableWrite::changes($plan));
        $card = KbTableWrite::preview(['title' => 'T', 'columns' => $tc], $spec, $plan, '');
        $this->assertSame('Renommer une colonne de la table « T »', $card['action']);
        $this->assertSame('« Statut » → « État du compte »', $card['fichier']);
        $this->assertSame('Colonne « Statut » renommée « État du compte » par MIA', KbTableWrite::reason($spec, $plan));

        $drop = KbTableWrite::spec($tc, [], ['mode' => 'supprimer_colonne', 'colonne' => 'ville']);
        $plan = KbTableWrite::plan($tc, $tr, [], [], $drop);
        $this->assertSame(['nom', 'email', 'statut'], WorkTableOps::keys($plan['columns']));
        $this->assertArrayNotHasKey('ville', $plan['rows'][0]);
        $this->assertSame('Colonne « Ville » supprimée par MIA', KbTableWrite::reason($drop, $plan));
        $this->assertStringContainsString('retirées de la table', implode("\n", KbTableWrite::preview(['title' => 'T', 'columns' => $tc], $drop, $plan, '')['impacts']));

        // Refus : nom déjà pris, colonne inconnue, dernière colonne.
        foreach ([
            ['mode' => 'renommer_colonne', 'colonne' => 'Statut', 'nouveau_nom' => 'VILLE'],
            ['mode' => 'renommer_colonne', 'colonne' => 'Statut'],
            ['mode' => 'supprimer_colonne', 'colonne' => 'Inconnue'],
            ['mode' => 'supprimer_colonne'],
        ] as $bad) {
            try {
                KbTableWrite::spec($tc, [], $bad);
                $this->fail('Demande refusée attendue : ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $one = WorkTableOps::columns(['Seule']);
        $this->expectException(\InvalidArgumentException::class);
        KbTableWrite::spec($one, [], ['mode' => 'supprimer_colonne', 'colonne' => 'Seule']);
    }

    public function testUneLivraisonDeWorkflowNePeutQueReporterSaPiece(): void
    {
        $this->assertSame(['ajouter', 'mettre_a_jour', 'remplacer'], WorkflowTables::WRITE_MODES, 'Les modes directs du chat n’ont pas de sens pour une livraison.');
        foreach (KbTableWrite::SOURCE_MODES as $mode) {
            $this->assertContains($mode, KbTableWrite::MODES);
            $this->assertTrue(KbTableWrite::usesSource($mode, []), $mode);
        }
        // Un réglage écrit « definir » (modèle, définition ancienne) retombe sur l'ajout.
        $this->assertSame('ajouter', WorkflowTables::specArgs(['write' => 'definir'])['mode']);
    }

    public function testUneColonneDisparueEntreLaCarteEtLAccordArreteLEcriture(): void
    {
        [$tc, $tr] = self::target();
        $spec = KbTableWrite::spec($tc, [], ['mode' => 'definir', 'definir' => ['Statut' => 'x'], 'filtres' => [['colonne' => 'Ville', 'test' => 'egal', 'valeur' => 'Lyon']]]);
        // Entre-temps, quelqu'un a supprimé la colonne « Ville ».
        $later = array_values(array_filter($tc, static fn(array $c): bool => $c['key'] !== 'ville'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('n\'existe plus');
        KbTableWrite::plan($later, $tr, [], [], $spec);
    }
}
