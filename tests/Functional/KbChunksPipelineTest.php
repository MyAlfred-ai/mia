<?php

declare(strict_types=1);

/**
 * Section « Avancé » de la fiche d'une base : le détail des extraits
 * vectorisés tels qu'ils sont en base de données (KnowledgeBaseDocument::
 * chunksPage, chunkTotals), et la configuration qu'en tire le schéma
 * (KbDiagram::spec) avec la vraie base.
 *
 * Les formes pures et le rendu sont gardés par tests/Unit/KbDiagramTest.php ;
 * ici, ce que les requêtes rapportent vraiment : ordre du découpage, pages,
 * filtre par document, recherche littérale, extrait borné, cloisonnement.
 */
final class KbChunksPipelineTest extends DatabaseTestCase
{
    private function base(string $nom, int $createdBy): int
    {
        return KnowledgeBase::create([
            'name' => $nom, 'is_active' => 1, 'created_by' => $createdBy,
            'embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large', 'embedding_dims' => 1536,
        ]);
    }

    private function document(int $kbId, string $nom, string $source = 'upload'): int
    {
        $this->db()->prepare(
            'INSERT INTO knowledge_base_documents
                (kb_id, file_name, stored_name, mime_type, file_size, extraction_status, vector_status, chunk_count, source)
             VALUES (?, ?, ?, ?, 100, ?, ?, 0, ?)'
        )->execute([$kbId, $nom, bin2hex(random_bytes(6)), 'application/pdf', 'done', 'done', $source]);
        return (int)$this->db()->lastInsertId();
    }

    /** @param list<string> $contenus */
    private function extraits(int $kbId, int $docId, array $contenus): void
    {
        $stmt = $this->db()->prepare('INSERT INTO knowledge_base_chunks (document_id, kb_id, chunk_index, content, token_count) VALUES (?, ?, ?, ?, ?)');
        foreach ($contenus as $i => $contenu) {
            $stmt->execute([$docId, $kbId, $i, $contenu, 10 + $i]);
        }
        $this->db()->prepare('UPDATE knowledge_base_documents SET chunk_count = ? WHERE id = ?')->execute([count($contenus), $docId]);
    }

    public function testLesExtraitsSuiventLOrdreDuDecoupageEtSePaginent(): void
    {
        $admin = $this->insertUser(1, 'admin');
        $kb    = $this->base('Documentation', $admin);
        $guide = $this->document($kb, 'Guide.pdf');
        $faq   = $this->document($kb, 'FAQ.pdf');
        $this->extraits($kb, $guide, array_map(static fn(int $i): string => "Guide, passage $i.", range(0, 14)));
        $this->extraits($kb, $faq, array_map(static fn(int $i): string => "FAQ, réponse $i.", range(0, 9)));

        $p1 = KnowledgeBaseDocument::chunksPage($kb, null, '', 1);
        $this->assertSame(25, $p1['total']);
        $this->assertCount(KnowledgeBaseDocument::CHUNKS_PER_PAGE, $p1['rows']);
        // Document par document, extrait par extrait : l'ordre du découpage.
        $this->assertSame(['Guide.pdf', 0], [$p1['rows'][0]['file_name'], $p1['rows'][0]['chunk_index']]);
        $this->assertSame(['Guide.pdf', 14], [$p1['rows'][14]['file_name'], $p1['rows'][14]['chunk_index']]);
        $this->assertSame(['FAQ.pdf', 0], [$p1['rows'][15]['file_name'], $p1['rows'][15]['chunk_index']]);
        $this->assertSame($guide, $p1['rows'][0]['document_id']);
        $this->assertSame(10, $p1['rows'][0]['token_count']);
        $this->assertSame(mb_strlen('Guide, passage 0.'), $p1['rows'][0]['chars']);

        $p2 = KnowledgeBaseDocument::chunksPage($kb, null, '', 2);
        $this->assertCount(5, $p2['rows']);
        $this->assertSame(['FAQ.pdf', 9], [$p2['rows'][4]['file_name'], $p2['rows'][4]['chunk_index']]);
        $this->assertSame([], KnowledgeBaseDocument::chunksPage($kb, null, '', 9)['rows'], 'Au-delà de la dernière page : rien, sans erreur.');

        // Un document seulement.
        $seul = KnowledgeBaseDocument::chunksPage($kb, $faq, '', 1);
        $this->assertSame(10, $seul['total']);
        $this->assertSame(['FAQ.pdf'], array_values(array_unique(array_column($seul['rows'], 'file_name'))));

        // Le volume de la base.
        $this->assertSame(['chunks' => 25, 'tokens' => array_sum(range(10, 24)) + array_sum(range(10, 19))], KnowledgeBaseDocument::chunkTotals($kb));
    }

    public function testLaRechercheEstLitteraleEtCloisonnee(): void
    {
        $admin = $this->insertUser(1, 'admin');
        $kb    = $this->base('Tarifs', $admin);
        $doc   = $this->document($kb, 'Tarifs.pdf');
        $this->extraits($kb, $doc, [
            'Remise de 10% sur le forfait.',
            'Remise de 100 € sur la licence.',
            'Le code PROMO_2026 expire en mars.',
            'Le code PROMOX2026 est un autre code.',
            'Rien à voir ici.',
        ]);
        $autre = $this->base('Ailleurs', $admin);
        $this->extraits($autre, $this->document($autre, 'Autre.pdf'), ['Remise de 10% ailleurs.']);

        // « % » et « _ » tapés à l'écran sont du texte, pas des jokers.
        $pourcent = KnowledgeBaseDocument::chunksPage($kb, null, '10%', 1);
        $this->assertSame(1, $pourcent['total']);
        $this->assertSame('Remise de 10% sur le forfait.', $pourcent['rows'][0]['content']);
        $souligne = KnowledgeBaseDocument::chunksPage($kb, null, 'PROMO_2026', 1);
        $this->assertSame(['Le code PROMO_2026 expire en mars.'], array_column($souligne['rows'], 'content'));
        $this->assertSame(0, KnowledgeBaseDocument::chunksPage($kb, null, 'aucune trace', 1)['total']);

        // Une base ne montre jamais les extraits d'une autre — même par un document d'ailleurs.
        $this->assertSame(2, KnowledgeBaseDocument::chunksPage($kb, null, 'Remise', 1)['total']);
        $docAilleurs = (int)$this->db()->query("SELECT id FROM knowledge_base_documents WHERE kb_id = $autre")->fetchColumn();
        $this->assertSame(0, KnowledgeBaseDocument::chunksPage($kb, $docAilleurs, '', 1)['total']);
    }

    public function testUnExtraitLongEstBorneMaisSaTailleReelleEstDite(): void
    {
        $admin = $this->insertUser(1, 'admin');
        $kb    = $this->base('Long', $admin);
        $long  = str_repeat('é', KnowledgeBaseDocument::CHUNK_PREVIEW_CHARS + 500);
        $this->extraits($kb, $this->document($kb, 'Long.txt'), [$long]);

        $row = KnowledgeBaseDocument::chunksPage($kb, null, '', 1)['rows'][0];
        $this->assertSame(KnowledgeBaseDocument::CHUNK_PREVIEW_CHARS, mb_strlen($row['content']), 'Jamais un extrait entier à l’écran.');
        $this->assertSame(KnowledgeBaseDocument::CHUNK_PREVIEW_CHARS + 500, $row['chars'], 'Caractères, pas octets.');
    }

    public function testLaConfigurationDuSchemaAvecLaVraieBase(): void
    {
        $admin    = $this->insertUser(1, 'admin');
        $createur = $this->insertUser(2, 'creator');
        $kb  = $this->base('Dossiers clients', $admin);
        $doc = $this->document($kb, 'Notes.md');
        $this->extraits($kb, $doc, ['Un', 'Deux', 'Trois']);
        $this->document($kb, 'Synthèse.pdf', 'drive');
        KnowledgeBase::setSyncConfig($kb, 'drive', '1AbCdEfGhIjKlMnOp', true);
        $groupe = $this->insertGroup('Ventes');
        KnowledgeBase::setShares($kb, [$createur => 1], [$groupe => 0]);
        $pid = $this->insertProfile('Veille', false, $admin);
        KnowledgeBase::setForProfile($pid, [$kb], [$kb]);

        $user = User::findById($admin);
        $row  = null;
        foreach (KnowledgeBase::forManager($user) as $candidate) {
            if ((int)$candidate['id'] === $kb) {
                $row = $candidate;
            }
        }
        $this->assertNotNull($row);
        $access = KbDiagram::access($row, KnowledgeBase::ownedBy($kb, $user), true, KnowledgeBase::sharesFor($kb), null, null);
        $spec   = KbDiagram::spec($row, KnowledgeBaseDocument::allForKb($kb), KnowledgeBase::profileIdsFor($kb), $access, null);

        $this->assertSame('Dossiers clients', $spec['name']);
        $this->assertSame('drive', $spec['source']['provider']);
        $this->assertTrue($spec['source']['auto']);
        $this->assertSame(['Notes.md'], array_column($spec['files'], 'name'), 'Seuls les fichiers déposés sont nommés.');
        $this->assertSame(1, $spec['synced_total']);
        $this->assertSame(3, $spec['chunks'], 'Les extraits réellement en base.');
        $this->assertSame('openai::text-embedding-3-large', $spec['embedding']);
        $this->assertSame([$pid], $spec['assistant_ids']);
        $this->assertTrue($access['owned']);
        $this->assertSame([['id' => $createur, 'manage' => true, 'default' => false, 'feed' => false]], $access['users']);
        $this->assertSame([['id' => $groupe, 'manage' => false, 'default' => false, 'feed' => false]], $access['groups']);

        // Le bénéficiaire, lui, ne voit pas la liste du partage.
        $pourLui = null;
        foreach (KnowledgeBase::forManager(User::findById($createur)) as $candidate) {
            if ((int)$candidate['id'] === $kb) {
                $pourLui = $candidate;
            }
        }
        $this->assertNotNull($pourLui, 'La base partagée apparaît chez le bénéficiaire.');
        $sonAcces = KbDiagram::access($pourLui, KnowledgeBase::ownedBy($kb, User::findById($createur)), false, KnowledgeBase::sharesFor($kb), null, null);
        $this->assertFalse($sonAcces['owned']);
        $this->assertTrue($sonAcces['shared']);
        $this->assertTrue($sonAcces['can_manage'], 'Partagée avec « peut modifier ».');
        $this->assertSame([], $sonAcces['users']);
    }
}
