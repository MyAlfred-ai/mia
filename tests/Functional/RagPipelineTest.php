<?php

declare(strict_types=1);

/**
 * Pipeline RAG complet sur MySQL : chunking en base, retrieval FULLTEXT +
 * pertinence, application du budget, fallbacks robustes (requête vide,
 * caractères spéciaux), document image, extraction asynchrone, grand nombre de
 * fichiers et purge en cascade. Auto-skippé si aucune base de test (cf.
 * DatabaseTestCase).
 */
final class RagPipelineTest extends DatabaseTestCase
{
    private ?int $profileId = null;

    protected function tearDown(): void
    {
        // Nettoyage des fichiers physiques éventuels (les lignes sont vidées par
        // le truncate du setUp suivant).
        if ($this->profileId !== null) {
            $dir = ProfileDocument::storageDirFor($this->profileId);
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
    }

    private function newProfile(): int
    {
        $this->profileId = $this->insertProfile('__TEST_RAG__', isPersonal: false);
        return $this->profileId;
    }

    private function mkDoc(int $pid, string $name, string $text): int
    {
        $id = ProfileDocument::create([
            'profile_id'        => $pid,
            'file_name'         => $name,
            'stored_name'       => 'test_' . bin2hex(random_bytes(4)) . '.txt',
            'mime_type'         => 'text/plain',
            'file_size'         => strlen($text),
            'extracted_text'    => $text,
            'token_count'       => ProfileDocument::estimateTokens($text),
            'extraction_status' => 'done',
        ]);
        ProfileDocument::rechunkDocument($id);
        return $id;
    }

    private function seedThreeDocs(int $pid): array
    {
        return [
            $this->mkDoc($pid, 'incendie.txt', 'La réglementation incendie impose des extincteurs accessibles dans chaque couloir du bâtiment.'),
            $this->mkDoc($pid, 'conges.txt', 'Le calendrier des congés payés annuels est validé chaque trimestre par le service ressources humaines.'),
            $this->mkDoc($pid, 'sauvegarde.txt', 'La procédure de sauvegarde des serveurs informatiques est exécutée automatiquement chaque nuit.'),
        ];
    }

    public function testRechunkCreatesChunks(): void
    {
        $pid = $this->newProfile();
        [$docA] = $this->seedThreeDocs($pid);
        $cnt = (int) $this->db()->query('SELECT COUNT(*) FROM profile_document_chunks WHERE document_id = ' . $docA)->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $cnt);
    }

    public function testRetrievalRanksRelevantDocumentFirst(): void
    {
        $pid = $this->newProfile();
        $this->seedThreeDocs($pid);
        $res = ProfileDocument::retrieveRelevantContext($pid, 'extincteurs incendie couloir', 100000);
        $this->assertIsString($res);
        $this->assertStringContainsString('incendie', $res);
        $posIncendie = strpos($res, 'incendie');
        $posConges   = strpos($res, 'congés');
        $this->assertTrue($posIncendie !== false && ($posConges === false || $posIncendie < $posConges),
            'le document pertinent doit arriver en tête');
    }

    public function testTightBudgetKeepsOnlyBestChunk(): void
    {
        $pid = $this->newProfile();
        $this->seedThreeDocs($pid);
        $tight = ProfileDocument::retrieveRelevantContext($pid, 'sauvegarde serveurs informatiques', 25);
        $this->assertIsString($tight);
        $this->assertStringContainsString('sauvegarde', $tight);
        $this->assertStringNotContainsString('congés', $tight, 'budget serré exclut les docs non pertinents');
    }

    public function testBudgetBoundsInjectedSize(): void
    {
        $pid = $this->newProfile();
        $this->mkDoc($pid, 'gros.txt', RagFixtures::inflate('La donnée pertinente sur le projet alpha figure ici. ', 120000));
        $budget = 500;
        $r = ProfileDocument::retrieveRelevantContext($pid, 'projet alpha donnée pertinente', $budget);
        $this->assertIsString($r);
        $this->assertLessThanOrEqual($budget * 4 + 400, mb_strlen($r), 'taille injectée bornée par le budget');
    }

    public function testZeroBudgetReturnsNull(): void
    {
        $pid = $this->newProfile();
        $this->seedThreeDocs($pid);
        $this->assertNull(ProfileDocument::retrieveRelevantContext($pid, 'incendie', 0));
    }

    public function testEmptyQueryFallsBackToFirstChunks(): void
    {
        $pid = $this->newProfile();
        $this->seedThreeDocs($pid);
        $empty = ProfileDocument::retrieveRelevantContext($pid, '', 5000);
        $this->assertIsString($empty);
        $this->assertNotSame('', trim($empty));
    }

    public function testSpecialCharsQueryDoesNotCrash(): void
    {
        $pid = $this->newProfile();
        $this->seedThreeDocs($pid);
        $r = ProfileDocument::retrieveRelevantContext($pid, '+incendie -"couloir" *(test)~', 5000);
        $this->assertTrue($r === null || is_string($r));
    }

    public function testImageDocumentProducesNoChunk(): void
    {
        $pid = $this->newProfile();
        $imgId = ProfileDocument::create([
            'profile_id'        => $pid,
            'file_name'         => 'photo.png',
            'stored_name'       => 'photo_' . bin2hex(random_bytes(4)) . '.png',
            'mime_type'         => 'image/png',
            'file_size'         => 100,
            'extracted_text'    => null,
            'token_count'       => 0,
            'extraction_status' => 'done',
        ]);
        $this->assertSame(0, ProfileDocument::rechunkDocument($imgId));
    }

    public function testProcessExtractionSetsDoneAndCreatesChunks(): void
    {
        $pid = $this->newProfile();
        $dir = ProfileDocument::ensureStorageDir($pid);
        $stored = 'async_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents("$dir/$stored", 'Document asynchrone contenant ASYNCMARK42 et du texte.');
        $id = ProfileDocument::create([
            'profile_id'        => $pid,
            'file_name'         => 'async.txt',
            'stored_name'       => $stored,
            'mime_type'         => 'text/plain',
            'file_size'         => 60,
            'extracted_text'    => null,
            'token_count'       => 0,
            'extraction_status' => 'pending',
        ]);
        ProfileDocument::processExtraction($id);
        $after = ProfileDocument::findById($id);
        $this->assertSame('done', (string) $after['extraction_status']);
        $this->assertStringContainsString('ASYNCMARK42', (string) $after['extracted_text']);
        $cnt = (int) $this->db()->query('SELECT COUNT(*) FROM profile_document_chunks WHERE document_id = ' . $id)->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $cnt);
    }

    public function testProcessExtractionMissingFileEndsInTerminalStatus(): void
    {
        $pid = $this->newProfile();
        $id = ProfileDocument::create([
            'profile_id'        => $pid,
            'file_name'         => 'absent.txt',
            'stored_name'       => 'nexistepas_' . bin2hex(random_bytes(4)) . '.txt',
            'mime_type'         => 'text/plain',
            'file_size'         => 0,
            'extracted_text'    => null,
            'token_count'       => 0,
            'extraction_status' => 'pending',
        ]);
        ProfileDocument::processExtraction($id);
        $after = ProfileDocument::findById($id);
        $this->assertContains((string) $after['extraction_status'], ['done', 'failed'],
            'jamais bloqué en pending');
    }

    public function testManyDocumentsStayWithinBudget(): void
    {
        $pid = $this->newProfile();
        for ($i = 0; $i < 20; $i++) {
            $this->mkDoc($pid, "bulk_$i.txt", "Document de masse numéro $i sur le thème delta$i avec des détails techniques répétés.");
        }
        $bulk = ProfileDocument::retrieveRelevantContext($pid, 'thème delta7 détails techniques', 2000);
        $this->assertIsString($bulk);
        $this->assertLessThanOrEqual(2000 * 4 + 800, mb_strlen($bulk));
    }

    public function testDeleteCascadesChunks(): void
    {
        $pid = $this->newProfile();
        [$docA] = $this->seedThreeDocs($pid);
        $before = (int) $this->db()->query('SELECT COUNT(*) FROM profile_document_chunks WHERE document_id = ' . $docA)->fetchColumn();
        ProfileDocument::delete($docA);
        $after = (int) $this->db()->query('SELECT COUNT(*) FROM profile_document_chunks WHERE document_id = ' . $docA)->fetchColumn();
        $this->assertGreaterThan(0, $before);
        $this->assertSame(0, $after);
    }
}
