<?php

declare(strict_types=1);

/**
 * Tables PERMANENTES (migration 112), sur MySQL réel : une base de
 * connaissances de nature « table » porte des lignes — créée d'un coup avec
 * sa base, lue par pages, retouchée à la main sur place, remplacée d'un bloc
 * en gardant la version d'avant, plafonnée sans jamais rien couper. Elle suit
 * les règles d'une base (partage, « Uniquement pour moi », quota à part) et
 * n'entre dans aucun chemin réservé aux documents.
 *
 * Aucun service externe. Auto-skippé sans base.
 */
final class KbTablePipelineTest extends DatabaseTestCase
{
    private function user(int $id, string $role = 'creator'): array
    {
        $this->insertUser($id, $role);
        return ['id' => $id, 'role' => $role, 'is_active' => 1];
    }

    private static function import(int $rows, array $labels = ['Nom', 'Email', 'Ville']): array
    {
        $columns = WorkTableOps::columns($labels);
        $lines   = [];
        for ($i = 1; $i <= $rows; $i++) {
            $lines[] = ['Client ' . $i, 'c' . $i . '@ex.fr', $i % 4 === 0 ? 'Paris' : 'Lyon'];
        }
        return ['columns' => $columns, 'rows' => WorkTableOps::rows($columns, $lines), 'origin' => ['kind' => KbTableImport::ORIGIN_FILE, 'file' => 'clients.xlsx'], 'notes' => []];
    }

    public function testUneTableNaitAvecSaBaseEtSeLitParPages(): void
    {
        $user  = $this->user(1);
        $store = new KbTableStore();
        $kbId  = $store->createBase($user, '  Clients   2026 ', 'Clients actifs', self::import(120));

        $kb = KnowledgeBase::findById($kbId);
        $this->assertTrue(KnowledgeBase::isTable($kb));
        $this->assertFalse(KnowledgeBase::holdsDocuments($kb));
        $this->assertSame('Clients 2026', $kb['name']);
        $this->assertSame('', (string)$kb['embedding_provider'], 'Rien à vectoriser.');
        $this->assertSame(120, (int)$kb['table_rows'], 'Les lectures de liste portent la fiche de la table.');
        $this->assertSame(3, (int)$kb['table_columns']);

        $table = $store->find($kbId);
        $this->assertSame($kbId, $table['id']);
        $this->assertSame('clients_2026', $table['name']);
        $this->assertSame('Clients 2026', $table['title']);
        $this->assertSame(120, $table['rows']);
        $this->assertSame(['nom', 'email', 'ville'], WorkTableOps::keys($table['columns']));
        $this->assertTrue($table['mia_write'], 'Par défaut : MIA peut proposer une écriture (toujours sous carte).');
        $this->assertArrayNotHasKey('previous', $table, 'Plus de « version d’avant » : l’historique.');
        $this->assertTrue($table['permanent']);
        $history = $store->history($kbId);
        $this->assertCount(1, $history);
        $this->assertSame(KbTableHistory::KIND_CREATE, $history[0]['kind']);
        $this->assertSame(['kind' => KbTableImport::ORIGIN_FILE, 'file' => 'clients.xlsx'], $history[0]['detail']['origin']);

        $this->assertCount(120, $store->rows($kbId));
        $page = $store->rows($kbId, 50, 50);
        $this->assertCount(50, $page);
        $this->assertSame('Client 51', $page[0]['nom']);

        // La grille de l'écran lit la table comme une table de travail.
        $view = WorkTableView::page($store, $table, 'paris client 12', 1);
        $this->assertSame(['Client 12', 'Client 112', 'Client 120'], array_map(static fn(array $r): string => $r['cells'][0]['text'], $view['rows']));
        $this->assertSame(120, $view['total']);
    }

    public function testUneEcritureDEnsembleEstUnEtatDeLHistoriqueEtOnPeutYRevenir(): void
    {
        $user  = $this->user(1);
        $store = new KbTableStore();
        $kbId  = $store->createBase($user, 'Tarifs', null, self::import(10));
        $import = static fn(string $file): array => KbTableHistory::change(KbTableHistory::KIND_IMPORT, ['mode' => 'replace', 'file' => $file]);

        $next = self::import(4, ['Nom', 'Email', 'Ville']);
        $table = $store->replace($kbId, $next['columns'], $next['rows'], ['kind' => 'fichier', 'file' => 'v2.xlsx'], 1, $import('v2.xlsx'));
        $this->assertSame(4, $table['rows']);
        $this->assertSame(2, $table['version']);
        $this->assertSame('v2.xlsx', $table['origin']['file']);
        $this->assertCount(4, $store->rows($kbId));
        $this->assertSame([2, 1], array_column($store->history($kbId), 'version'));
        $this->assertSame('v2.xlsx', $store->history($kbId)[0]['detail']['file']);

        // Revenir à la création : ses dix lignes sont COPIÉES dans un nouvel état.
        $back = $store->restore($kbId, 1, 1);
        $this->assertSame(10, $back['rows']);
        $this->assertSame(3, $back['version']);
        $this->assertSame('Client 10', $store->rows($kbId)[9]['nom']);
        $history = $store->history($kbId);
        $this->assertSame([3, 2, 1], array_column($history, 'version'), 'Rien n’est effacé : l’état quitté reste.');
        $this->assertSame(KbTableHistory::KIND_RESTORE, $history[0]['kind']);
        $this->assertSame(['from' => 1, 'prev' => 2], array_intersect_key($history[0]['detail'], ['from' => 1, 'prev' => 1]));

        // … et le retour se défait : la table reprend l'état 2, le retour sort de l'historique.
        $undone = $store->undoRestore($kbId, 1);
        $this->assertSame(2, $undone['version']);
        $this->assertSame(4, $undone['rows']);
        $this->assertSame([2, 1], array_column($store->history($kbId), 'version'));

        $store->replace($kbId, $next['columns'], array_slice($next['rows'], 0, 2), null, 1, $import('v3.xlsx'));
        $table = $store->find($kbId);
        $this->assertSame(2, $table['rows']);
        $this->assertSame('v2.xlsx', $table['origin']['file'], 'Une origine non précisée ne change pas.');
        $versions = $this->db()->query('SELECT COUNT(DISTINCT version) FROM knowledge_base_table_rows WHERE kb_id = ' . $kbId)->fetchColumn();
        $this->assertSame(3, (int)$versions, 'Chaque état gardé garde ses lignes ; celles du retour défait sont parties.');

        // Écrire sur un état qui a changé depuis : refusé, rien n'est écrit.
        $stale = $store->replace($kbId, $next['columns'], $next['rows'], null, 1, $import('tard.xlsx'), 'empreinte-perimee');
        $this->assertTrue($stale['stale']);
        $this->assertSame(2, $store->find($kbId)['rows']);
        $ok = $store->replace($kbId, $next['columns'], $next['rows'], null, 1, $import('temps.xlsx'), KbTableStore::stamp($store->find($kbId)));
        $this->assertArrayNotHasKey('stale', $ok);
        $this->assertSame(4, $ok['rows']);
    }

    public function testLesRetouchesALaMainFontUnSeulEtatRegroupe(): void
    {
        $user  = $this->user(1);
        $store = new KbTableStore();
        $kbId  = $store->createBase($user, 'Suivi', null, self::import(5));

        $this->assertTrue($store->setCell($kbId, 2, 'ville', '  Besançon ', 1));
        $this->assertSame('Besançon', $store->rows($kbId)[2]['ville']);
        $this->assertFalse($store->setCell($kbId, 2, 'inconnue', 'x', 1), 'Colonne inconnue : rien.');
        $this->assertFalse($store->setCell($kbId, 99, 'ville', 'x', 1), 'Ligne inconnue : rien.');

        $this->assertSame(5, $store->addRow($kbId, ['nom' => 'Nouveau', 'pirate' => 'x'], 1));
        $this->assertSame(6, $store->find($kbId)['rows']);
        $this->assertSame(['nom' => 'Nouveau', 'email' => '', 'ville' => ''], $store->rows($kbId)[5]);

        // Supprimer la deuxième ligne : les suivantes remontent, sans trou.
        $this->assertTrue($store->deleteRow($kbId, 1, 1));
        $rows = $store->rows($kbId);
        $this->assertSame(['Client 1', 'Client 3', 'Client 4', 'Client 5', 'Nouveau'], array_column($rows, 'nom'));
        $positions = $this->db()->query('SELECT position FROM knowledge_base_table_rows WHERE kb_id = ' . $kbId
            . ' AND version = ' . (int)$store->find($kbId)['version'] . ' ORDER BY position')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([0, 1, 2, 3, 4], array_map('intval', $positions));
        $this->assertFalse($store->deleteRow($kbId, 42, 1));

        // Colonnes : en ajouter une, la renommer — les lignes ne bougent pas.
        $columns   = $store->find($kbId)['columns'];
        $columns[] = ['label' => 'CA 2026', 'type' => 'number'];
        $columns[0]['label'] = 'Client';
        $table = $store->setColumns($kbId, $columns, 1);
        $this->assertSame(['nom', 'email', 'ville', 'ca_2026'], WorkTableOps::keys($table['columns']));
        $this->assertSame('Client', $table['columns'][0]['label']);
        $this->assertSame('number', $table['columns'][3]['type']);
        $this->assertSame('Client 1', $store->rows($kbId)[0]['nom']);
        // Toutes ces retouches d'une même personne : UN état, à côté de la création intacte.
        $history = $store->history($kbId);
        $this->assertSame([2, 1], array_column($history, 'version'));
        $this->assertSame(KbTableHistory::KIND_EDIT, $history[0]['kind']);
        $detail = $history[0]['detail'];
        $this->assertSame(1, $detail['cells']);
        $this->assertSame(1, $detail['rows_added']);
        $this->assertSame(1, $detail['rows_deleted']);
        $this->assertSame(['CA 2026'], $detail['columns_added']);
        $this->assertSame([['Nom', 'Client']], $detail['columns_renamed']);
        $this->assertSame(5, $history[0]['row_count'], 'Le compte de l’état suit la table.');
        $this->assertSame(4, $history[0]['column_count']);
        $this->assertCount(5, $store->versionRows($kbId, 1), 'La création garde ses cinq lignes d’origine.');
        $this->assertSame('Client 2', $store->versionRows($kbId, 1)[1]['nom']);
    }

    public function testLesPlafondsRefusentSansRienCouper(): void
    {
        $user  = $this->user(1);
        $store = new KbTableStore();

        try {
            $store->createBase($user, 'Trop grande', null, self::import(KbTableStore::MAX_ROWS + 1));
            $this->fail('Une table au-delà du plafond doit être refusée.');
        } catch (KbTableTooLargeException $e) {
            $this->assertSame(KbTableStore::MAX_ROWS + 1, $e->rowsFound);
            $this->assertStringContainsString('20 001', str_replace("\u{202f}", ' ', str_replace("\u{a0}", ' ', $e->getMessage())));
        }
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM knowledge_bases')->fetchColumn(), 'Aucune base orpheline.');

        try {
            $store->createBase($user, 'Trop large', null, ['columns' => array_map(static fn(int $i): string => 'C' . $i, range(1, 41)), 'rows' => [], 'origin' => []]);
            $this->fail('Plus de 40 colonnes : refusé.');
        } catch (KbTableTooLargeException $e) {
            $this->assertSame(41, $e->columnsFound);
        }

        $kbId = $store->createBase($user, 'Pleine', null, self::import(KbTableStore::MAX_ROWS));
        $this->assertSame(KbTableStore::MAX_ROWS, $store->find($kbId)['rows']);
        $this->expectException(KbTableTooLargeException::class);
        $store->addRow($kbId, ['nom' => 'Une de trop'], 1);
    }

    public function testLeQuotaDesTablesEstAPartDeCeluiDesBases(): void
    {
        $creator = $this->user(1);
        $store   = new KbTableStore();
        for ($i = 1; $i <= KbTableStore::MAX_PER_CREATOR; $i++) {
            $store->createBase($creator, 'Table ' . $i, null, self::import(1));
        }
        $this->assertSame(0, KnowledgeBase::countByCreator(1), 'Les tables n’entament pas le quota des bases.');
        $this->assertSame(KbTableStore::MAX_PER_CREATOR, KbTableStore::countByCreator(1));
        try {
            $store->createBase($creator, 'Une de trop', null, self::import(1));
            $this->fail('Quota de tables atteint : refusé.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string)KbTableStore::MAX_PER_CREATOR, $e->getMessage());
        }
        // « Bases de connaissances — Gérer » : sans limite.
        $admin = $this->user(2, 'admin');
        $this->assertGreaterThan(0, $store->createBase($admin, 'Table admin', null, self::import(1)));
    }

    public function testUneTableSuitLesReglesDUneBaseEtRienDesDocuments(): void
    {
        $owner = $this->user(1);
        $other = $this->user(2, 'user');
        $store = new KbTableStore();
        $kbId  = $store->createBase($owner, 'Clients', null, self::import(3));
        $empty = $store->createBase($owner, 'Vide', null, ['columns' => ['Nom'], 'rows' => [], 'origin' => ['kind' => 'saisie']]);

        // Sélecteur du chat : la table du créateur, cochable ; vide, grisée.
        $byId = [];
        foreach (KnowledgeBase::selectableForUser($owner) as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $this->assertNull(KnowledgeBase::unavailableReason($byId[$kbId]));
        $this->assertSame('Table vide', KnowledgeBase::unavailableReason($byId[$empty]));
        $this->assertSame([], KnowledgeBase::selectableForUser($other), 'Non partagée : invisible pour un autre compte.');

        // Partagée : l'autre la coche, sans pouvoir la modifier.
        KnowledgeBase::setShares($kbId, [2 => 0], []);
        $this->assertSame([$kbId], array_map(static fn(array $r): int => (int)$r['id'], KnowledgeBase::selectableForUser($other)));
        $this->assertFalse(KnowledgeBase::manageableBy($kbId, $other));

        // Trois natures, trois listes : une table n'est jamais cherchée comme une base à documents.
        [$live, $local, $tables] = KnowledgeBase::partitionByBackend(KnowledgeBase::selectableForUser($owner));
        $this->assertSame([], $live);
        $this->assertSame([], $local);
        $this->assertCount(2, $tables);

        // État affiché : ses lignes, jamais « occupé ».
        $status = KbStatus::forBases([KnowledgeBase::findById($kbId), KnowledgeBase::findById($empty)]);
        $this->assertSame(KbStatus::OK, $status[$kbId]['state']);
        $this->assertFalse($status[$kbId]['busy']);
        $this->assertSame(KbStatus::EMPTY, $status[$empty]['state']);
        $this->assertSame('Table vide', $status[$empty]['title']);

        // Réindexer ne fait rien, supprimer emporte les lignes.
        KnowledgeBase::reindex($kbId);
        KnowledgeBase::destroy($kbId);
        $this->assertNull($store->find($kbId));
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM knowledge_base_table_rows WHERE kb_id = ' . $kbId)->fetchColumn());
    }
}
