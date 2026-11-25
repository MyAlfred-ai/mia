<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Historique d'une table PERMANENTE (migration 116) : les règles pures de
 * KbTableHistory et le câblage de l'écran.
 *
 *   - les retouches à la main d'une même personne font UN état par quart d'heure ;
 *   - ce qui est gardé : l'état en service, les 30 plus récents, 90 jours, 60 au plus,
 *     la création toujours inscrite ;
 *   - un retour en arrière s'annule un quart d'heure, par qui l'a fait ;
 *   - ce que dit l'écran, en français et en anglais, sans phrase figée en base ;
 *   - ce qui diffère d'un état à maintenant, sans tout marquer pour une ligne insérée ;
 *   - le volet n'a aucun libellé côté script, « Revenir à cet état » marche sans script.
 */
final class KbTableHistoryTest extends TestCase
{
    private const NOW = 1_795_000_000; // mercredi 18 novembre 2026, 12 h 06 à Paris

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function read(string $path): string
    {
        $abs = dirname(__DIR__, 2) . '/' . $path;
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    private static function plain(string $s): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $s);
    }

    private static function entry(int $version, string $kind, array $detail = [], array $extra = []): array
    {
        return $extra + [
            'version' => $version, 'kind' => $kind, 'user_id' => 7, 'user_name' => 'Claire Martin',
            'detail' => $detail, 'row_count' => 10, 'column_count' => 3, 'rows_kept' => true,
            'created' => self::NOW - 3600, 'updated' => self::NOW - 3600, 'age' => 3600, 'since_update' => 3600,
        ];
    }

    // ---------------------------------------------------------------------
    // 1. Regrouper les retouches
    // ---------------------------------------------------------------------

    public function testUneRetoucheRejointLEtatDeLaMemePersonneUnQuartDHeure(): void
    {
        $edit = ['kind' => KbTableHistory::KIND_EDIT, 'user_id' => 7];
        $this->assertTrue(KbTableHistory::joinsEdit($edit, 7, 0));
        $this->assertTrue(KbTableHistory::joinsEdit($edit, 7, KbTableHistory::GROUP_SECONDS - 1));
        $this->assertFalse(KbTableHistory::joinsEdit($edit, 7, KbTableHistory::GROUP_SECONDS), 'Passé le quart d’heure : un nouvel état.');
        $this->assertFalse(KbTableHistory::joinsEdit($edit, 8, 10), 'Une autre personne : un nouvel état.');
        $this->assertFalse(KbTableHistory::joinsEdit(['kind' => KbTableHistory::KIND_MIA, 'user_id' => 7], 7, 10), 'Jamais par-dessus une écriture de MIA.');
        $this->assertFalse(KbTableHistory::joinsEdit(['kind' => KbTableHistory::KIND_RESTORE, 'user_id' => 7], 7, 10), 'Jamais par-dessus un retour en arrière.');
        $this->assertFalse(KbTableHistory::joinsEdit(null, 7, 10));
        $this->assertFalse(KbTableHistory::joinsEdit($edit, 0, 10), 'Sans personne connue, pas de regroupement.');
    }

    public function testLesRetouchesSontCompteesSansDoublon(): void
    {
        $d = KbTableHistory::noteEdit([], 'cell', ['cell' => '2:ville']);
        $d = KbTableHistory::noteEdit($d, 'cell', ['cell' => '2:ville']);
        $d = KbTableHistory::noteEdit($d, 'cell', ['cell' => '3:ville']);
        $d = KbTableHistory::noteEdit($d, 'row_added');
        $d = KbTableHistory::noteEdit($d, 'row_deleted');
        $d = KbTableHistory::noteEdit($d, 'columns', [
            'before' => [['key' => 'nom', 'label' => 'Nom', 'type' => 'text'], ['key' => 'ca', 'label' => 'CA', 'type' => 'text']],
            'after'  => [['key' => 'nom', 'label' => 'Client', 'type' => 'text'], ['key' => 'ca', 'label' => 'CA', 'type' => 'number'],
                ['key' => 'pays', 'label' => 'Pays', 'type' => 'text']],
        ]);
        $this->assertSame(2, $d['cells'], 'Une même cellule corrigée deux fois compte une fois.');
        $this->assertSame(1, $d['rows_added']);
        $this->assertSame(1, $d['rows_deleted']);
        $this->assertSame(['Pays'], $d['columns_added']);
        $this->assertSame([['Nom', 'Client']], $d['columns_renamed']);
        $this->assertSame(['CA'], $d['columns_retyped']);
        $this->assertSame(
            '1 ligne ajoutée, 1 ligne supprimée, 2 cellules corrigées, colonne « Pays » ajoutée, colonne « Nom » renommée « Client », type de la colonne « CA » changé',
            self::plain(lcfirst(KbTableHistory::what(['kind' => KbTableHistory::KIND_EDIT, 'detail' => $d], new DateTimeZone('UTC'))))
        );
    }

    // ---------------------------------------------------------------------
    // 2. Conserver
    // ---------------------------------------------------------------------

    public function testLesTrenteDerniersEtatsSontGardesQuelQueSoitLeurAge(): void
    {
        $old = self::NOW - 400 * 86400;
        $entries = [['version' => 1, 'kind' => KbTableHistory::KIND_CREATE, 'created' => $old, 'rows_kept' => true]];
        for ($v = 2; $v <= 40; $v++) {
            $entries[] = ['version' => $v, 'kind' => KbTableHistory::KIND_EDIT, 'created' => $old + $v, 'rows_kept' => true];
        }
        $plan = KbTableHistory::retention($entries, 40, self::NOW);
        $this->assertSame(range(10, 2), $plan['drop'], 'Au-delà des 30 plus récents, l’ancien sort.');
        $this->assertSame([1], $plan['empty'], 'La création reste inscrite, sans ses lignes.');

        // Déjà vidée : rien à refaire.
        $entries[0]['rows_kept'] = false;
        $this->assertSame([], KbTableHistory::retention($entries, 40, self::NOW)['empty']);
    }

    public function testNonanteJoursGardesDansLaLimiteDeSoixante(): void
    {
        $entries = [];
        for ($v = 1; $v <= 70; $v++) {
            $entries[] = ['version' => $v, 'kind' => KbTableHistory::KIND_MIA, 'created' => self::NOW - 86400, 'rows_kept' => true];
        }
        $plan = KbTableHistory::retention($entries, 70, self::NOW);
        $this->assertSame(range(10, 1), $plan['drop'], 'Récents, mais au-delà de 60 : l’ancien sort.');

        // L'état en service est toujours gardé, même s'il est le plus ancien (retour à un vieil état).
        $plan = KbTableHistory::retention($entries, 1, self::NOW);
        $this->assertNotContains(1, $plan['drop']);
    }

    public function testUnRetourSAnnuleUnQuartDHeureParQuiLAFait(): void
    {
        $restore = ['kind' => KbTableHistory::KIND_RESTORE, 'user_id' => 7];
        $prev    = ['rows_kept' => true];
        $this->assertTrue(KbTableHistory::canUndo($restore, $prev, 7, 60));
        $this->assertFalse(KbTableHistory::canUndo($restore, $prev, 8, 60), 'Une autre personne : non.');
        $this->assertFalse(KbTableHistory::canUndo($restore, $prev, 7, KbTableHistory::UNDO_SECONDS));
        $this->assertFalse(KbTableHistory::canUndo($restore, ['rows_kept' => false], 7, 60), 'L’état quitté n’est plus gardé.');
        $this->assertFalse(KbTableHistory::canUndo(['kind' => KbTableHistory::KIND_EDIT, 'user_id' => 7], $prev, 7, 60));

        $entries = [
            self::entry(3, KbTableHistory::KIND_RESTORE, ['from' => 1, 'prev' => 2], ['age' => 30]),
            self::entry(2, KbTableHistory::KIND_IMPORT),
            self::entry(1, KbTableHistory::KIND_CREATE),
        ];
        $this->assertTrue(KbTableHistory::undoableFrom($entries, 3, 7));
        $this->assertFalse(KbTableHistory::undoableFrom($entries, 2, 7), 'Quelque chose a été écrit depuis le retour.');
    }

    // ---------------------------------------------------------------------
    // 3. Dire
    // ---------------------------------------------------------------------

    public function testLEnTeteDitLaCreationEtLaDerniereModification(): void
    {
        $created = self::entry(1, KbTableHistory::KIND_CREATE, [], ['created' => 1_793_793_600, 'updated' => 1_793_793_600]); // 4 nov. 2026
        $head = KbTableHistory::head([$created], 7, 0, '', self::NOW);
        $this->assertSame('Créée le 4 nov. 2026 par vous', $head['created']);
        $this->assertSame('', $head['modified'], 'Rien n’a changé depuis la création.');

        $edit = self::entry(2, KbTableHistory::KIND_EDIT, ['cells' => 1], ['updated' => self::NOW - 7200]);
        $head = KbTableHistory::head([$edit, $created], 9, 0, '', self::NOW);
        $this->assertSame('Créée le 4 nov. 2026 par Claire Martin', $head['created']);
        $this->assertSame('Modifiée il y a 2 h par Claire Martin', $head['modified']);
        $this->assertSame(2, $head['count']);

        // Création non inscrite (table d'avant l'historique) : la base fait foi.
        $earlier = self::entry(4, KbTableHistory::KIND_EARLIER, [], ['user_id' => 0, 'user_name' => '']);
        $head = KbTableHistory::head([$earlier], 9, 1_793_793_600, 'Paul', self::NOW);
        $this->assertSame('Créée le 4 nov. 2026 par Paul', $head['created']);

        I18n::use('en');
        $head = KbTableHistory::head([$edit, $created], 7, 0, '', self::NOW);
        $this->assertSame('Created on Nov 4, 2026 by you', $head['created']);
        $this->assertSame('Changed 2 h ago by you', $head['modified']);
    }

    public function testChaqueEtatSeDitDansLaLangueDeLEcran(): void
    {
        $tz = new DateTimeZone('Europe/Paris');
        $cases = [
            [KbTableHistory::KIND_CREATE, [], 'Table créée', 'Table created'],
            [KbTableHistory::KIND_IMPORT, ['mode' => 'replace', 'file' => 'v2.xlsx'], 'Fichier « v2.xlsx » importé', 'File “v2.xlsx” imported'],
            [KbTableHistory::KIND_IMPORT, ['mode' => 'append', 'file' => 'v2.xlsx', 'added' => 3], '3 lignes ajoutées depuis « v2.xlsx »', '3 rows added from “v2.xlsx”'],
            [KbTableHistory::KIND_COLUMN, ['dropped' => ['Ville']], 'Colonne « Ville » supprimée', 'Column “Ville” deleted'],
            [KbTableHistory::KIND_MIA, ['mode' => KbTableWrite::MODE_UPDATE, 'touched' => 2], '2 lignes mises à jour', '2 rows updated'],
            [KbTableHistory::KIND_WORKFLOW, ['mode' => KbTableWrite::MODE_DELETE, 'deleted' => 1, 'workflow' => 'Relances'], '1 ligne supprimée', '1 row deleted'],
            [KbTableHistory::KIND_EARLIER, [], 'État antérieur à l’historique', 'State from before the history'],
        ];
        foreach ($cases as [$kind, $detail, $fr, $en]) {
            I18n::use('fr');
            $this->assertSame($fr, self::plain(KbTableHistory::what(['kind' => $kind, 'detail' => $detail], $tz)), $kind);
            I18n::use('en');
            $this->assertSame($en, self::plain(KbTableHistory::what(['kind' => $kind, 'detail' => $detail], $tz)), $kind);
        }

        I18n::use('fr');
        $restore = ['kind' => KbTableHistory::KIND_RESTORE, 'detail' => ['from_at' => self::NOW - 86400 * 2]];
        $this->assertSame('Retour à l’état du 16 nov. à 12 h 06', self::plain(KbTableHistory::what($restore, $tz)));
        $this->assertSame('Workflow « Relances »', KbTableHistory::who(['kind' => KbTableHistory::KIND_WORKFLOW, 'detail' => ['workflow' => 'Relances']], 7));
        $this->assertSame('Vous', KbTableHistory::who(self::entry(2, KbTableHistory::KIND_EDIT), 7));
        $this->assertSame('Compte supprimé', KbTableHistory::who(self::entry(2, KbTableHistory::KIND_EDIT, [], ['user_id' => 0, 'user_name' => '']), 7));
        $this->assertSame('Après votre accord', KbTableHistory::note(self::entry(2, KbTableHistory::KIND_MIA), 7, $tz));
        $this->assertSame('Après accord de Claire Martin', KbTableHistory::note(self::entry(2, KbTableHistory::KIND_MIA), 9, $tz));
        $this->assertSame('Le fichier a remplacé tout le contenu.', KbTableHistory::note(self::entry(2, KbTableHistory::KIND_IMPORT, ['mode' => 'replace']), 7, $tz));
        $long = self::entry(2, KbTableHistory::KIND_EDIT, [], ['created' => self::NOW - 600, 'updated' => self::NOW - 60]);
        $this->assertSame('Retouches à la main, de 11 h 56 à 12 h 05.', self::plain(KbTableHistory::note($long, 7, $tz)));
    }

    public function testLeVoletRangeLesEtatsParJourEtNOffreQueLesGestesPossibles(): void
    {
        $tz = new DateTimeZone('Europe/Paris');
        $entries = [
            self::entry(4, KbTableHistory::KIND_RESTORE, ['from' => 1, 'prev' => 3], ['created' => self::NOW - 60, 'age' => 60]),
            self::entry(3, KbTableHistory::KIND_IMPORT, ['mode' => 'replace', 'file' => 'v2.xlsx'], ['created' => self::NOW - 600]),
            self::entry(2, KbTableHistory::KIND_EDIT, ['cells' => 1], ['created' => self::NOW - 86400]),
            self::entry(1, KbTableHistory::KIND_CREATE, [], ['created' => self::NOW - 86400 * 5, 'rows_kept' => false]),
        ];
        $groups = KbTableHistory::view($entries, 4, 7, $tz, self::NOW, 3, true, true);
        $this->assertSame(['Aujourd’hui', 'Hier', 'Vendredi 13 novembre'], array_column($groups, 'day'));
        [$restore, $import] = $groups[0]['items'];
        $this->assertTrue($restore['is_now']);
        $this->assertTrue($restore['can_undo'], 'Le retour qu’on vient de faire s’annule.');
        $this->assertFalse($restore['can_restore'], 'On ne revient pas à l’état en service.');
        $this->assertTrue($import['open']);
        $this->assertTrue($import['can_restore']);
        $creation = $groups[2]['items'][0];
        $this->assertFalse($creation['can_restore'], 'Contenu plus conservé : on n’y revient plus.');
        $this->assertSame('Son contenu n’est plus conservé.', $creation['note']);

        // Sans droit de modifier : tout se lit, rien ne se défait.
        $read = KbTableHistory::view($entries, 4, 7, $tz, self::NOW, 3, false, true);
        $this->assertFalse($read[0]['items'][0]['can_undo']);
        $this->assertFalse($read[0]['items'][1]['can_restore']);
    }

    public function testUnMomentPorteLAnneeSeulementSiElleDiffere(): void
    {
        $tz = new DateTimeZone('Europe/Paris');
        $this->assertSame('17 nov. à 12 h 06', self::plain(KbTableHistory::moment(self::NOW - 86400, $tz, self::NOW)));
        $this->assertStringContainsString('2025', KbTableHistory::moment(self::NOW - 400 * 86400, $tz, self::NOW));
        $this->assertStringNotContainsString('2026', KbTableHistory::moment(self::NOW - 86400, $tz, self::NOW));
        I18n::use('en');
        $this->assertSame('Nov 17 at 12:06', KbTableHistory::moment(self::NOW - 86400, $tz, self::NOW));
    }

    // ---------------------------------------------------------------------
    // 4. Comparer
    // ---------------------------------------------------------------------

    public function testUneLigneInsereeNeFaitPasParaitreLesAutresChangees(): void
    {
        $cols = WorkTableOps::columns(['Nom', 'Ville']);
        $then = [['nom' => 'Alpha', 'ville' => 'Lyon'], ['nom' => 'Beta', 'ville' => 'Paris'], ['nom' => 'Gamma', 'ville' => 'Nice']];
        $now  = [['nom' => 'Zéro', 'ville' => 'Lille'], ['nom' => 'alpha', 'ville' => 'Lyon'], ['nom' => 'Beta', 'ville' => 'Marseille']];
        $diff = KbTableHistory::diff($cols, $then, $cols, $now);
        $this->assertSame([], array_keys(array_filter($diff['marks'], static fn(array $m): bool => $m['changed'] === [] && !$m['gone'])));
        $this->assertSame(['changed' => ['nom' => 'alpha'], 'gone' => false], $diff['marks'][0], 'Alpha retrouvée sans casse : seule sa casse a changé.');
        $this->assertSame(['ville' => 'Marseille'], $diff['marks'][1]['changed']);
        $this->assertTrue($diff['marks'][2]['gone'], 'Gamma n’est plus là.');
        $this->assertSame(2, $diff['cells']);
        $this->assertSame('2 cellules différentes par rapport à maintenant.', self::plain(KbTableHistory::summary($diff)));

        $more = KbTableHistory::diff($cols, array_merge($then, [['nom' => 'Delta', 'ville' => 'Pau']]), WorkTableOps::columns(['Nom']), [['nom' => 'Alpha']]);
        $this->assertSame('3 lignes de plus, 1 colonne de plus par rapport à maintenant.', self::plain(KbTableHistory::summary($more)));
        $this->assertSame('La table repasse à 4 lignes et 2 colonnes.', self::plain(KbTableHistory::confirmText($more)));
        $this->assertSame('Identique à maintenant.', KbTableHistory::summary(KbTableHistory::diff($cols, $then, $cols, $then)));

        // Aucune colonne commune : on ne compare pas les cellules.
        $none = KbTableHistory::diff(WorkTableOps::columns(['A']), [['a' => '1']], WorkTableOps::columns(['B']), [['b' => '1']]);
        $this->assertSame([], $none['marks']);
    }

    // ---------------------------------------------------------------------
    // 5. Câblage
    // ---------------------------------------------------------------------

    public function testLaMigrationInscritLesEtatsDejaLa(): void
    {
        $sql = self::read('database/migrations/116_kb_table_history.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS knowledge_base_table_versions', $sql);
        $this->assertStringContainsString('PRIMARY KEY (kb_id, version)', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql, 'L’historique part avec sa table.');
        $this->assertStringContainsString('ON DELETE SET NULL', $sql, 'Un compte supprimé ne supprime pas l’histoire.');
        $this->assertSame(2, substr_count($sql, 'INSERT IGNORE INTO knowledge_base_table_versions'), 'Version en service et version d’avant reprises.');
        $this->assertDoesNotMatchRegularExpression('/\bmanual\b/i', $sql);
        $this->assertContains('knowledge_base_table_versions', (new ReflectionClassConstant(DatabaseTestCase::class, 'TABLES'))->getValue());
    }

    public function testRoutesEtEcransSontCables(): void
    {
        $routes = self::read('public/index.php');
        foreach (['get(\'/admin/knowledge-bases/{id}/table/history\'', 'post(\'/admin/knowledge-bases/{id}/table/history/restore\'', 'post(\'/admin/knowledge-bases/{id}/table/history/undo\''] as $r) {
            $this->assertStringContainsString('$router->' . $r, $routes);
        }
        $this->assertStringNotContainsString('/table/restore\'', $routes, 'L’ancien « Rétablir la version d’avant » a disparu.');

        $show = self::read('templates/admin/knowledge-bases/show.php');
        foreach (['data-kbh-open', 'id="kbHistory"', 'id="kbHistBanner"', 'id="kbHistConfirm"', 'kb-history.js'] as $needle) {
            $this->assertStringContainsString($needle, $show);
        }
        $this->assertStringContainsString('KbTableHistory::head(', self::read('src/Controllers/AdminController.php'));

        // « Revenir à cet état » est un vrai formulaire : il marche sans script.
        $panel = self::read('templates/admin/knowledge-bases/partials/table-history.php');
        $this->assertStringContainsString('<form method="post" action="<?= htmlspecialchars($hvUrl . \'restore\') ?>"', $panel);
        $this->assertStringContainsString('name="csrf_token"', $panel);

        // Le script n'a aucun libellé.
        $js = self::read('public/assets/js/kb-history.js');
        $this->assertDoesNotMatchRegularExpression('/\bt\(\s*[\'"]/', $js);
        $this->assertDoesNotMatchRegularExpression('/[éèêàùçÉÈÀ]/u', preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $js));
        $this->assertStringContainsString("'mia:kb-table-version'", $js);
        $this->assertStringContainsString("'mia:kb-table-version'", self::read('public/assets/js/kb-table.js'), 'La grille montre l’état ouvert.');
        // Vus au banc : la remise à zéro des marges décentrait la confirmation ;
        // au téléphone, « Voir la table d'alors » laissait le bandeau hors de l'écran.
        $this->assertMatchesRegularExpression('/\.kbh-dialog \{\s*margin: auto;/', self::read('public/assets/css/kb-table.css'));
        $this->assertStringContainsString('const target = banner && !banner.hidden ? banner : card;', $js);

        // Les écritures d'ensemble disent ce qu'elles sont.
        $ctl = self::read('src/Controllers/KbTableController.php');
        $this->assertStringContainsString('KbTableHistory::change(KbTableHistory::KIND_IMPORT', $ctl);
        $this->assertStringContainsString('KbTableHistory::change(KbTableHistory::KIND_COLUMN', $ctl);
        $chat = self::read('src/Controllers/ChatController.php');
        $this->assertStringContainsString('self::permanentWriteChange(', $chat);
        $this->assertStringContainsString('KbTableHistory::KIND_WORKFLOW', $chat);
    }

    public function testLesTextesSontTraduits(): void
    {
        $dict = require dirname(__DIR__, 2) . '/lang/en.php';
        $src  = self::read('src/Tables/KbTableHistory.php') . self::read('src/Controllers/KbTableController.php')
            . self::read('templates/admin/knowledge-bases/partials/table-history.php')
            . self::read('templates/admin/knowledge-bases/partials/table-history-banner.php');
        preg_match_all('/\b(?:t|te)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/u', $src, $m);
        foreach (array_unique($m[1]) as $key) {
            $key = stripslashes($key);
            $this->assertArrayHasKey($key, $dict, "Clé non traduite : $key");
        }
        preg_match_all('/I18n::plural\([^,]+,\s*\'((?:[^\'\\\\]|\\\\.)*)\',\s*\'((?:[^\'\\\\]|\\\\.)*)\'/u', self::read('src/Tables/KbTableHistory.php'), $p);
        foreach (array_merge($p[1], $p[2]) as $key) {
            $this->assertArrayHasKey(stripslashes($key), $dict, "Pluriel non traduit : $key");
        }
    }
}
