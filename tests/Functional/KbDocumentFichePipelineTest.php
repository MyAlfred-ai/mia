<?php

declare(strict_types=1);

/**
 * Fiche d'un document d'une base, sur MySQL réel (migration 115) : exclure
 * retire ses extraits et l'ôte des problèmes de la base, rétablir et réessayer
 * remettent la bonne phase à faire, un document dont plus rien ne tourne est
 * reconnu interrompu (la base ne « travaille » plus pour toujours), et la
 * synchronisation lève l'exclusion d'un fichier modifié à la source.
 *
 * Aucun service externe (ni modèle, ni Qdrant). Auto-skippé sans base.
 */
final class KbDocumentFichePipelineTest extends DatabaseTestCase
{
    private function base(int $createdBy): int
    {
        return KnowledgeBase::create([
            'name' => 'Contrats', 'is_active' => 1, 'created_by' => $createdBy,
            'embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large', 'embedding_dims' => 1536,
        ]);
    }

    private function document(int $kbId, array $cols = []): int
    {
        $id = KnowledgeBaseDocument::create([
            'kb_id' => $kbId, 'file_name' => $cols['file_name'] ?? 'Contrat.pdf',
            'stored_name' => 'test_' . bin2hex(random_bytes(4)) . '.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 100, 'source' => $cols['source'] ?? 'upload',
            'sync_file_id' => $cols['sync_file_id'] ?? null, 'sync_hash' => $cols['sync_hash'] ?? null,
        ]);
        $set = array_intersect_key($cols, array_flip(['extraction_status', 'extraction_error', 'vector_status', 'vector_error', 'chunk_count']));
        $set += ['extraction_status' => 'done', 'vector_status' => 'done', 'chunk_count' => 0];
        $sql = 'UPDATE knowledge_base_documents SET ' . implode(', ', array_map(static fn(string $c): string => "$c = ?", array_keys($set))) . ' WHERE id = ?';
        $this->db()->prepare($sql)->execute([...array_values($set), $id]);
        return $id;
    }

    private function extraits(int $kbId, int $docId, int $n): void
    {
        $stmt = $this->db()->prepare('INSERT INTO knowledge_base_chunks (document_id, kb_id, chunk_index, content, token_count) VALUES (?, ?, ?, ?, 10)');
        for ($i = 0; $i < $n; $i++) {
            $stmt->execute([$docId, $kbId, $i, "Clause $i du contrat."]);
        }
        $this->db()->prepare('UPDATE knowledge_base_documents SET chunk_count = ? WHERE id = ?')->execute([$n, $docId]);
    }

    private function vieillir(int $docId, int $minutes): void
    {
        $this->db()->prepare('UPDATE knowledge_base_documents SET created_at = NOW() - INTERVAL ? MINUTE WHERE id = ?')->execute([$minutes, $docId]);
    }

    private function job(string $dedupKey, string $status = 'pending'): void
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

    public function testExclureRetireSesExtraitsEtLOteDesProblemes(): void
    {
        $owner = $this->insertUser(1, 'creator');
        $kb    = $this->base($owner);
        $ok    = $this->document($kb, ['file_name' => 'Bon.pdf']);
        $this->extraits($kb, $ok, 2);
        // Un « non vectorisé » : ses extraits MySQL servent la recherche par mots.
        $mal   = $this->document($kb, ['file_name' => 'Scan.pdf', 'vector_status' => 'failed', 'vector_error' => 'Erreur API embeddings openai (HTTP 500).']);
        $this->extraits($kb, $mal, 3);
        $this->assertSame(KbStatus::PARTIAL, $this->etat($kb)['state']);

        KnowledgeBaseDocument::exclude($mal, $owner, 'Vectorisation refusée par le fournisseur');

        $doc = KnowledgeBaseDocument::findForKb($kb, $mal);
        $this->assertNotNull($doc['excluded_at']);
        $this->assertSame('User 1', $doc['excluded_by_name']);
        $this->assertSame('Vectorisation refusée par le fournisseur', $doc['excluded_reason']);
        $this->assertSame(0, (int)$doc['chunk_count']);
        $this->assertSame(0, KnowledgeBaseDocument::chunksPage($kb, $mal, '', 1)['total'], 'Ses extraits ne sont plus cherchés.');
        $this->assertSame(2, KnowledgeBaseDocument::chunksPage($kb, null, '', 1)['total'], 'Les autres restent.');
        $this->assertSame(KbStatus::DOC_EXCLUDED, KbStatus::documentState($doc)['key']);

        $st = $this->etat($kb);
        $this->assertSame(KbStatus::OK, $st['state'], 'Le seul souci a été écarté : la base est prête.');
        $this->assertStringContainsString('1 exclu', $st['detail']);
        $this->assertNull(KnowledgeBaseDocument::findForKb($kb + 1000, $mal), 'Un document se lit dans SA base.');
    }

    public function testRetablirEtReessayerRemettentLaBonnePhase(): void
    {
        $owner = $this->insertUser(1, 'creator');
        $kb    = $this->base($owner);

        $vec = $this->document($kb, ['vector_status' => 'failed', 'vector_error' => 'Qdrant injoignable (http://qdrant:6333).']);
        $this->extraits($kb, $vec, 4);
        KnowledgeBaseDocument::prepareRetry($vec, KbDocIssue::RETRY_VECTOR);
        $d = KnowledgeBaseDocument::findById($vec);
        $this->assertSame(['done', 'pending', null], [$d['extraction_status'], $d['vector_status'], $d['vector_error']]);
        $this->assertSame(4, KnowledgeBaseDocument::chunksPage($kb, $vec, '', 1)['total'], 'Vectorisation seule : les extraits restent.');

        $lu = $this->document($kb, ['extraction_status' => 'failed', 'extraction_error' => 'Unable to parse', 'vector_status' => 'failed']);
        KnowledgeBaseDocument::exclude($lu, $owner, 'Lecture impossible');
        KnowledgeBaseDocument::prepareRetry($lu, KbDocIssue::RETRY_FULL);
        $d = KnowledgeBaseDocument::findById($lu);
        $this->assertSame(['pending', null, 'pending', null], [$d['extraction_status'], $d['extraction_error'], $d['vector_status'], $d['excluded_at']], 'Rétabli : tout est à refaire, l’exclusion est levée.');

        $sync = $this->document($kb, ['source' => 'drive', 'sync_file_id' => 'f1', 'sync_hash' => 'abc', 'extraction_status' => 'failed', 'extraction_error' => 'Fichier introuvable sur le stockage.']);
        KnowledgeBaseDocument::prepareRetry($sync, KbDocIssue::RETRY_SYNC);
        $d = KnowledgeBaseDocument::findById($sync);
        $this->assertNull($d['sync_hash'], 'Empreinte oubliée : le prochain passage le retélécharge.');
        $this->assertTrue(KbSync::isModified(['hash' => 'abc', 'modified' => 'x'], KnowledgeBaseDocument::syncedDocsForKb($kb)['f1']));
    }

    public function testUnFichierModifieALaSourceEstReessaye(): void
    {
        $owner = $this->insertUser(1, 'creator');
        $kb    = $this->base($owner);
        $doc   = $this->document($kb, ['source' => 'drive', 'sync_file_id' => 'f9', 'sync_hash' => 'v1']);
        KnowledgeBaseDocument::exclude($doc, $owner, 'Document numérisé');
        // Inchangé : la synchronisation le voit tel quel, il reste exclu.
        $this->assertFalse(KbSync::isModified(['hash' => 'v1', 'modified' => ''], KnowledgeBaseDocument::syncedDocsForKb($kb)['f9']));
        KnowledgeBaseDocument::updateSyncedVersion($doc, [
            'file_name' => 'Contrat (OCR).pdf', 'stored_name' => 'test_new.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 120, 'sync_hash' => 'v2', 'sync_modified_at' => '2026-11-19T10:00:00Z',
        ]);
        $this->assertNull(KnowledgeBaseDocument::findById($doc)['excluded_at']);
    }

    public function testLHeureDeLaDerniereIndexationSuitChaqueVectorisationMeneeAuBout(): void
    {
        $owner = $this->insertUser(1, 'creator');
        $kb    = $this->base($owner);
        $doc   = $this->document($kb, ['vector_status' => 'pending']);
        $this->assertNull(KnowledgeBaseDocument::findForKb($kb, $doc)['indexed_ts'], 'Pas encore indexé.');

        $avant = time();
        KnowledgeBaseDocument::setVectorStatus($doc, 'done', null, 3);
        $ts = (int)KnowledgeBaseDocument::findForKb($kb, $doc)['indexed_ts'];
        $this->assertGreaterThanOrEqual($avant - 5, $ts);
        $this->assertLessThanOrEqual(time() + 5, $ts);
        $this->assertSame($ts, (int)KnowledgeBaseDocument::findById($doc)['indexed_ts']);

        // Un nouvel essai qui échoue garde l'heure de la précédente.
        $this->db()->prepare('UPDATE knowledge_base_documents SET indexed_at = NOW() - INTERVAL 2 DAY WHERE id = ?')->execute([$doc]);
        $ancienne = (int)KnowledgeBaseDocument::findForKb($kb, $doc)['indexed_ts'];
        KnowledgeBaseDocument::setVectorStatus($doc, 'failed', 'Erreur API embeddings openai (HTTP 500).');
        $this->assertSame($ancienne, (int)KnowledgeBaseDocument::findForKb($kb, $doc)['indexed_ts']);
        KnowledgeBaseDocument::setVectorStatus($doc, 'done');
        $this->assertGreaterThan($ancienne, (int)KnowledgeBaseDocument::findForKb($kb, $doc)['indexed_ts']);
    }

    public function testUnDocumentDontPlusRienNeTourneEstInterrompu(): void
    {
        $owner = $this->insertUser(1, 'creator');
        $kb    = $this->base($owner);
        $doc   = $this->document($kb, ['extraction_status' => 'processing', 'vector_status' => 'pending']);
        $this->vieillir($doc, 10);

        // Rien ne tourne : interrompu, la base n'attend plus pour toujours.
        $st = $this->etat($kb);
        $this->assertSame(KbStatus::PARTIAL, $st['state']);
        $this->assertFalse($st['busy']);
        $work = KnowledgeBaseDocument::activeWork($kb, [$doc]);
        $this->assertSame(['base' => false, 'docs' => []], $work);
        $marked = KbDocIssue::markStalled(KnowledgeBaseDocument::allForKb($kb), $work);
        $this->assertSame(KbStatus::DOC_STALLED, KbStatus::documentState($marked[0])['key']);

        // Son job vit : il travaille.
        $this->job('kb_document:' . $doc, 'running');
        $this->assertSame(KbStatus::INDEXING, $this->etat($kb)['state']);
        $this->assertSame([$doc => true], KnowledgeBaseDocument::activeWork($kb, [$doc])['docs']);
        $this->db()->exec('DELETE FROM async_jobs');

        // Une synchronisation de la base en file : elle s'en charge.
        $this->job('kb_sync:' . $kb);
        $this->assertTrue(KnowledgeBaseDocument::activeWork($kb, [$doc])['base']);
        $this->assertNotSame(KbStatus::PARTIAL, $this->etat($kb)['state']);
        $this->db()->exec('DELETE FROM async_jobs');

        // Tout juste déposé : son job part, pas d'alerte.
        $neuf = $this->document($kb, ['extraction_status' => 'pending', 'vector_status' => 'pending']);
        $rows = KnowledgeBaseDocument::allForKb($kb);
        $neufRow = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['id'] === $neuf))[0];
        $this->assertFalse(KbDocIssue::isStalled($neufRow, ['base' => false, 'docs' => []]));
    }
}
