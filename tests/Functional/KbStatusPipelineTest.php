<?php

declare(strict_types=1);

/**
 * KbStatus::forBases face à une vraie base MySQL : comptes groupés par
 * statut, file de jobs (« en attente »), verrou du passage (« en cours »),
 * âge du dernier passage calculé par l'horloge de la base, activité écrite
 * puis effacée (migration 072).
 *
 * La vérité « en cours » ne vient PAS de `sync_status` : un processus tué y
 * laisse « syncing » pour toujours. D'où les deux témoins vérifiés ici — le
 * verrou MySQL que tient le passage, et la file de jobs.
 */
final class KbStatusPipelineTest extends DatabaseTestCase
{
    private function insertKb(string $name, array $cols = []): int
    {
        $cols += [
            'sync_provider' => null, 'sync_folder_ref' => null, 'sync_enabled' => 0,
            'sync_status' => null, 'sync_error' => null,
        ];
        $stmt = $this->db()->prepare(
            'INSERT INTO knowledge_bases
                (name, embedding_provider, embedding_model, embedding_dims, is_active, is_system,
                 sync_provider, sync_folder_ref, sync_enabled, sync_status, sync_error)
             VALUES (?, ?, ?, ?, 1, 0, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name, 'openai', 'text-embedding-3-large', 1536,
            $cols['sync_provider'], $cols['sync_folder_ref'], $cols['sync_enabled'],
            $cols['sync_status'], $cols['sync_error'],
        ]);
        return (int)$this->db()->lastInsertId();
    }

    private function insertDoc(int $kbId, string $name, string $ext, string $vec, int $chunks): void
    {
        $this->db()->prepare(
            'INSERT INTO knowledge_base_documents
                (kb_id, file_name, stored_name, mime_type, file_size, extraction_status, vector_status, chunk_count, source)
             VALUES (?, ?, ?, ?, 100, ?, ?, ?, ?)'
        )->execute([$kbId, $name, bin2hex(random_bytes(6)) . '.pdf', 'application/pdf', $ext, $vec, $chunks, 'upload']);
    }

    private function insertJob(string $dedupKey, string $status): void
    {
        [$op, $id] = explode(':', $dedupKey);
        $this->db()->prepare(
            "INSERT INTO async_jobs (user_id, conversation_id, type, dedup_key, label, payload_json, status)
             VALUES (NULL, NULL, 'maintenance', ?, ?, ?, ?)"
        )->execute([$dedupKey, $dedupKey, json_encode(['op' => $op, 'id' => (int)$id]), $status]);
    }

    private function etat(int $kbId): array
    {
        return KbStatus::forBases([KnowledgeBase::findById($kbId)])[$kbId];
    }

    private function synchronisee(string $name, array $cols = []): int
    {
        return $this->insertKb($name, $cols + [
            'sync_provider' => 'drive', 'sync_folder_ref' => '1AbCdEfGhIjKlMn', 'sync_enabled' => 1, 'sync_status' => 'ok',
        ]);
    }

    public function testLesComptesViennentDesStatutsReelsDesDocuments(): void
    {
        $kb = $this->insertKb('Dépôt');
        foreach (range(1, 3) as $i) {
            $this->insertDoc($kb, "ok$i.pdf", 'done', 'done', 5);
        }
        $this->insertDoc($kb, 'scan.pdf', 'done', 'done', 0);
        $this->insertDoc($kb, 'casse.pdf', 'failed', 'failed', 0);
        $this->insertDoc($kb, 'mots.pdf', 'done', 'failed', 4);
        $this->insertDoc($kb, 'lecture.pdf', 'processing', 'pending', 0);
        $this->insertDoc($kb, 'attente.pdf', 'pending', 'pending', 0);
        $autre = $this->insertKb('Autre base');
        $this->insertDoc($autre, 'ailleurs.pdf', 'done', 'done', 2);

        $st = $this->etat($kb);

        $this->assertSame(
            ['total' => 8, 'indexed' => 3, 'no_text' => 1, 'failed' => 1, 'lexical' => 1, 'busy' => 1, 'pending' => 1],
            $st['counts'],
            'Comptes groupés en SQL, classés par la même règle que la liste.'
        );
        $this->assertSame(KbStatus::INDEXING, $st['state']);
        $this->assertSame('6 / 8', $st['meta']);
        $this->assertSame(1, KbStatus::forBases([KnowledgeBase::findById($autre)])[$autre]['counts']['total'], 'Aucune fuite entre bases.');
    }

    public function testUnJobDeSynchronisationEnFileEstEnAttentePuisEnCours(): void
    {
        $kb = $this->synchronisee('Drive', ['sync_status' => 'error', 'sync_error' => 'ancien échec']);
        $this->insertDoc($kb, 'a.pdf', 'done', 'done', 3);

        $this->insertJob('kb_sync:' . $kb, 'pending');
        $st = $this->etat($kb);
        $this->assertSame(KbStatus::QUEUED, $st['state'], 'L\'ancien échec ne masque pas la demande en cours.');
        $this->assertTrue($st['busy']);

        $this->db()->prepare("UPDATE async_jobs SET status = 'running' WHERE dedup_key = ?")->execute(['kb_sync:' . $kb]);
        $this->assertSame(KbStatus::SYNCING, $this->etat($kb)['state']);

        $this->db()->prepare("UPDATE async_jobs SET status = 'done' WHERE dedup_key = ?")->execute(['kb_sync:' . $kb]);
        $this->assertSame(KbStatus::ERROR, $this->etat($kb)['state'], 'Job terminé : l\'état enregistré reprend la main.');
    }

    /** Le verrou tenu par le passage est le témoin fiable : il meurt avec le processus. */
    public function testLeVerrouDuPassageSignaleUnPassageVivant(): void
    {
        $kb = $this->synchronisee('Drive verrouillé', ['sync_status' => 'syncing']);
        $this->db()->prepare('SELECT GET_LOCK(?, 0)')->execute(['kb_sync_' . $kb]);
        try {
            KnowledgeBase::setActivity($kb, ['kind' => 'sync', 'phase' => 'files', 'done' => 2, 'total' => 8, 'current' => 'Contrat.pdf']);
            $st = $this->etat($kb);
            $this->assertSame(KbStatus::SYNCING, $st['state']);
            $this->assertSame('2 / 8', $st['meta']);
            $this->assertSame('« Contrat.pdf »', $st['detail']);
        } finally {
            $this->db()->prepare('SELECT RELEASE_LOCK(?)')->execute(['kb_sync_' . $kb]);
        }

        // Verrou relâché, état resté « syncing », aucun job : processus tué.
        $st = $this->etat($kb);
        $this->assertSame(KbStatus::STALLED, $st['state']);
        $this->assertStringContainsString('(2 / 8)', $st['detail']);
    }

    public function testUneReindexationEnFileEstSignalee(): void
    {
        $kb = $this->insertKb('À réindexer');
        $this->insertDoc($kb, 'a.pdf', 'done', 'done', 3);
        $this->insertJob('kb_reindex:' . $kb, 'pending');

        $this->assertSame(KbStatus::REINDEXING, $this->etat($kb)['state']);
    }

    public function testLActiviteSEcritPuisSEfface(): void
    {
        $kb = $this->insertKb('Activité');
        KnowledgeBase::setActivity($kb, ['kind' => 'reindex', 'done' => 1, 'total' => 3]);
        $json = json_decode((string)KnowledgeBase::findById($kb)['activity_json'], true);
        $this->assertSame('reindex', $json['kind']);
        $this->assertSame(3, $json['total']);
        $this->assertIsInt($json['at'], 'Horodatée à chaque étape.');

        KnowledgeBase::setActivity($kb, null);
        $this->assertNull(KnowledgeBase::findById($kb)['activity_json']);
    }

    /** L'âge vient de NOW() côté base : aucun fuseau PHP/MySQL à accorder. */
    public function testLeProchainPassageSeCalculeSurLHorlogeDeLaBase(): void
    {
        $kb = $this->synchronisee('Drive rythmé');
        $this->insertDoc($kb, 'a.pdf', 'done', 'done', 3);
        $this->db()->prepare(
            "UPDATE knowledge_bases SET sync_last_at = NOW() - INTERVAL 4 MINUTE,
                    sync_report = ? WHERE id = ?"
        )->execute([json_encode(['added' => 1, 'finished' => time() - 200]), $kb]);

        $st = $this->etat($kb);
        $this->assertSame(KbStatus::OK, $st['state']);
        $this->assertSame('dans ' . (KbSync::intervalMinutes() - 4) . ' min', $st['next']);
        $this->assertSame('il y a 3 min', $st['meta']);
    }

    public function testPlusieursBasesEnUneSeulePasse(): void
    {
        $a = $this->synchronisee('A');
        $b = $this->insertKb('B');
        $this->insertJob('kb_sync:' . $a, 'pending');
        $this->insertDoc($b, 'x.pdf', 'done', 'done', 1);

        $all = KbStatus::forBases([KnowledgeBase::findById($a), KnowledgeBase::findById($b)]);
        $this->assertSame([$a, $b], array_keys($all));
        $this->assertSame(KbStatus::QUEUED, $all[$a]['state']);
        $this->assertSame(KbStatus::OK, $all[$b]['state']);
        $this->assertSame([], KbStatus::forBases([]));
    }
}
