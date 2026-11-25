<?php

declare(strict_types=1);

/**
 * Historique d'une table PERMANENTE (migration 116), sur MySQL réel :
 *   - les retouches d'une même personne font un état par quart d'heure ;
 *     une autre personne, ou le quart d'heure passé, en ouvrent un autre ;
 *   - au-delà de la conservation, un état part avec ses lignes, la création
 *     reste inscrite sans les siennes, et on ne peut plus y revenir ;
 *   - un retour en arrière s'annule par qui l'a fait, dans le quart d'heure,
 *     tant que rien n'a été écrit depuis ;
 *   - une table d'avant l'historique (version d'avant `prev_*`) est reprise
 *     par la migration, et on peut revenir à sa version d'avant ;
 *   - le volet se rend depuis l'historique réel.
 *
 * Aucun service externe. Auto-skippé sans base.
 */
final class KbTableHistoryPipelineTest extends DatabaseTestCase
{
    private function base(int $rows = 4): array
    {
        $this->insertUser(1, 'creator');
        $this->insertUser(2, 'creator');
        $columns = WorkTableOps::columns(['Nom', 'Ville']);
        $lines   = [];
        for ($i = 1; $i <= $rows; $i++) {
            $lines[] = ['Client ' . $i, 'Lyon'];
        }
        $store = new KbTableStore();
        $kbId  = $store->createBase(['id' => 1, 'role' => 'creator', 'is_active' => 1], 'Clients', null, [
            'columns' => $columns, 'rows' => WorkTableOps::rows($columns, $lines),
            'origin'  => ['kind' => KbTableImport::ORIGIN_MANUAL], 'notes' => [],
        ]);
        return [$store, $kbId];
    }

    /** Recule l'horloge d'un état (création et dernière retouche). */
    private function age(int $kbId, int $version, int $seconds): void
    {
        $this->db()->prepare(
            'UPDATE knowledge_base_table_versions
                SET created_at = created_at - INTERVAL ? SECOND, updated_at = updated_at - INTERVAL ? SECOND
              WHERE kb_id = ? AND version = ?'
        )->execute([$seconds, $seconds, $kbId, $version]);
    }

    public function testLesRetouchesSeRegroupentParPersonneEtParQuartDHeure(): void
    {
        [$store, $kbId] = $this->base();

        $store->setCell($kbId, 0, 'ville', 'Paris', 1);
        $store->setCell($kbId, 1, 'ville', 'Paris', 1);
        $store->setCell($kbId, 1, 'ville', 'Paris', 1); // même valeur : rien à compter
        $history = $store->history($kbId);
        $this->assertSame([2, 1], array_column($history, 'version'), 'Deux cellules d’affilée : un état.');
        $this->assertSame(2, $history[0]['detail']['cells']);
        $this->assertSame(['Lyon', 'Lyon'], array_column(array_slice($store->versionRows($kbId, 1), 0, 2), 'ville'), 'La création est intacte.');

        // Une autre personne : un nouvel état.
        $store->setCell($kbId, 2, 'ville', 'Nice', 2);
        $this->assertSame([3, 2, 1], array_column($store->history($kbId), 'version'));

        // Le quart d'heure passé : un nouvel état, même pour la même personne.
        $store->setCell($kbId, 3, 'ville', 'Pau', 2);
        $this->assertSame([3, 2, 1], array_column($store->history($kbId), 'version'), 'Toujours dans le quart d’heure.');
        $this->age($kbId, 3, KbTableHistory::GROUP_SECONDS + 5);
        $store->setCell($kbId, 0, 'nom', 'Alpha', 2);
        $history = $store->history($kbId);
        $this->assertSame([4, 3, 2, 1], array_column($history, 'version'));
        $this->assertSame(1, $history[0]['detail']['cells']);
        $this->assertSame(['Alpha', 'Paris'], [$store->rows($kbId)[0]['nom'], $store->rows($kbId)[0]['ville']]);
    }

    public function testAuDelaDeLaConservationOnNeRevientPlusALEtat(): void
    {
        [$store, $kbId] = $this->base(2);
        $cols   = WorkTableOps::columns(['Nom', 'Ville']);
        $change = KbTableHistory::change(KbTableHistory::KIND_IMPORT, ['mode' => 'replace', 'file' => 'x.xlsx']);
        for ($i = 0; $i < KbTableHistory::KEEP_MIN + 4; $i++) {
            $store->replace($kbId, $cols, WorkTableOps::rows($cols, [['N' . $i, 'V']]), null, 1, $change);
        }
        $this->db()->prepare(
            'UPDATE knowledge_base_table_versions
                SET created_at = NOW() - INTERVAL 200 DAY, updated_at = NOW() - INTERVAL 200 DAY WHERE kb_id = ?'
        )->execute([$kbId]);
        $store->replace($kbId, $cols, WorkTableOps::rows($cols, [['Dernier', 'V']]), null, 1, $change);

        $history = $store->history($kbId);
        $this->assertCount(KbTableHistory::KEEP_MIN + 1, $history, 'Les trente plus récents, plus la création.');
        $creation = $history[count($history) - 1];
        $this->assertSame(KbTableHistory::KIND_CREATE, $creation['kind']);
        $this->assertFalse($creation['rows_kept'], 'La création reste inscrite, sans ses lignes.');
        $this->assertSame([], $store->versionRows($kbId, 1));
        $kept = array_column(array_filter($history, static fn(array $e): bool => $e['rows_kept']), 'version');
        $stored = array_map('intval', $this->db()->query('SELECT DISTINCT version FROM knowledge_base_table_rows WHERE kb_id = ' . $kbId . ' ORDER BY version DESC')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame($kept, $stored, 'Seuls les états gardés gardent leurs lignes.');

        try {
            $store->restore($kbId, 1, 1);
            $this->fail('Un état dont les lignes ne sont plus gardées ne se restaure pas.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cet état n’est plus conservé : on ne peut plus y revenir.', $e->getMessage());
        }
        try {
            $store->restore($kbId, $history[0]['version'], 1);
            $this->fail('On ne revient pas à l’état en service.');
        } catch (\RuntimeException $e) {
            $this->assertSame('La table est déjà dans cet état.', $e->getMessage());
        }
        $this->assertSame('Dernier', $store->rows($kbId)[0]['nom'], 'Rien n’a bougé.');
    }

    public function testUnRetourNeSAnnuleQueParQuiLAFaitEtTantQueRienNABouge(): void
    {
        [$store, $kbId] = $this->base();
        $store->setCell($kbId, 0, 'ville', 'Paris', 1);
        $store->restore($kbId, 1, 1);
        $this->assertSame('Lyon', $store->rows($kbId)[0]['ville']);

        $this->assertNull($store->undoRestore($kbId, 2), 'Une autre personne ne l’annule pas.');
        $undone = $store->undoRestore($kbId, 1);
        $this->assertSame(2, $undone['version']);
        $this->assertSame('Paris', $store->rows($kbId)[0]['ville']);
        $this->assertNull($store->undoRestore($kbId, 1), 'Déjà annulé.');

        // Un retour suivi d'une retouche : l'annuler effacerait la retouche.
        $store->restore($kbId, 1, 1);
        $store->setCell($kbId, 1, 'ville', 'Nice', 1);
        $this->assertNull($store->undoRestore($kbId, 1));

        // Un retour d'il y a plus d'un quart d'heure ne s'annule plus.
        $restored = $store->restore($kbId, 2, 1);
        $this->age($kbId, (int)$restored['version'], KbTableHistory::UNDO_SECONDS + 5);
        $this->assertNull($store->undoRestore($kbId, 1));
        $this->assertSame(KbTableHistory::KIND_RESTORE, $store->history($kbId)[0]['kind']);
    }

    public function testUneTableDAvantLHistoriqueEstRepriseParLaMigration(): void
    {
        [$store, $kbId] = $this->base(3);
        $cols = WorkTableOps::columns(['Nom', 'Ville']);
        $store->replace($kbId, $cols, WorkTableOps::rows($cols, [['Seul', 'Lyon']]), null, 2,
            KbTableHistory::change(KbTableHistory::KIND_IMPORT, ['mode' => 'replace']));
        // L'état d'avant la migration 116 : une version d'avant dans `prev_*`, aucun historique.
        $this->db()->exec('DELETE FROM knowledge_base_table_versions WHERE kb_id = ' . $kbId);
        $this->db()->prepare(
            'UPDATE knowledge_base_tables
                SET prev_version = 1, prev_columns_json = ?, prev_row_count = 3, prev_saved_at = NOW(), prev_reason = ?
              WHERE kb_id = ?'
        )->execute([json_encode($cols), 'Fichier importé', $kbId]);

        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/116_kb_table_history.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->db()->exec($statement);
        }

        $history = $store->history($kbId);
        $this->assertSame([2, 1], array_column($history, 'version'));
        $this->assertSame(KbTableHistory::KIND_EARLIER, $history[0]['kind']);
        $this->assertSame('Fichier importé', $history[0]['detail']['reason']);
        $this->assertSame(2, $history[0]['user_id']);
        $this->assertSame(KbTableHistory::KIND_CREATE, $history[1]['kind']);
        $this->assertSame(1, $history[1]['user_id'], 'La création est rendue au créateur de la base.');
        $this->assertTrue($history[1]['rows_kept']);
        $this->assertSame(3, $history[1]['row_count']);

        $store->restore($kbId, 1, 1);
        $this->assertSame(['Client 1', 'Client 2', 'Client 3'], array_column($store->rows($kbId), 'nom'));

        // Une table sans aucun état inscrit (sauvegarde d'avant) : l'état en service se réinscrit seul.
        $this->db()->exec('DELETE FROM knowledge_base_table_versions WHERE kb_id = ' . $kbId);
        $history = $store->history($kbId);
        $this->assertCount(1, $history);
        $this->assertSame(KbTableHistory::KIND_EARLIER, $history[0]['kind']);
    }

    public function testLeVoletSeRendDepuisLHistoriqueReel(): void
    {
        [$store, $kbId] = $this->base();
        $store->setCell($kbId, 0, 'ville', 'Paris', 1);
        $store->restore($kbId, 1, 1);

        $entries = $store->history($kbId);
        $current = (int)$store->find($kbId)['version'];
        $view    = [
            'kb_id'     => $kbId,
            'groups'    => KbTableHistory::view($entries, $current, 1, new DateTimeZone('Europe/Paris'), time(), 2, true,
                KbTableHistory::undoableFrom($entries, $current, 1)),
            'preview'   => ['version' => 2, 'moment' => 'm', 'summary' => '1 cellule différente par rapport à maintenant.', 'confirm' => 'c'],
            'can_write' => true,
        ];
        require_once ROOT . '/src/Controllers/KbTableController.php';
        $render = new ReflectionMethod(KbTableController::class, 'render');
        $html   = $render->invoke(null, 'table-history.php', ['historyView' => $view]);

        $this->assertSame(3, substr_count($html, 'data-kbh-version="'));
        $this->assertStringContainsString('Aujourd’hui', $html);
        $this->assertStringContainsString('Maintenant', $html);
        $this->assertStringContainsString('Retour à l’état du', $html);
        $this->assertStringContainsString('1 cellule corrigée', $html);
        $this->assertStringContainsString('Table créée', $html);
        $this->assertStringContainsString('/table/history/restore', $html, 'L’état ouvert se restaure.');
        $this->assertStringContainsString('/table/history/undo', $html, 'Le retour qu’on vient de faire s’annule.');
        $this->assertSame(1, substr_count($html, 'class="kbh-restore"'), 'Un seul état ouvert à la fois.');

        $banner = $render->invoke(null, 'table-history-banner.php', ['historyView' => $view]);
        $this->assertStringContainsString('data-kbh-restore="2"', $banner);
        $this->assertStringContainsString('Revenir au présent', $banner);
    }
}
