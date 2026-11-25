<?php

declare(strict_types=1);

/**
 * Visibilités d'une base (piste D, migration 114), sur MySQL réel : une base
 * « Publique » se voit et se coche par toute l'organisation, ne se modifie que
 * par son créateur et les administrateurs ; passer en publique ou en privée
 * retire les partages ; un administrateur ne rend jamais privée la base d'un
 * autre. Et « Que sait-elle ? » : la recherche dans les extraits d'une base.
 *
 * Aucun service externe. Auto-skippé sans base.
 */
final class KbVisibilityPipelineTest extends DatabaseTestCase
{
    private function user(int $id, string $role = 'user'): array
    {
        $this->insertUser($id, $role);
        return ['id' => $id, 'role' => $role];
    }

    private function readyKb(string $name, ?int $createdBy, array $chunks = ['Un extrait.']): int
    {
        $kb = KnowledgeBase::create([
            'name'               => $name,
            'description'        => null,
            'embedding_provider' => 'openai',
            'embedding_model'    => 'text-embedding-3-large',
            'embedding_dims'     => 1536,
            'is_active'          => 1,
            'created_by'         => $createdBy,
        ]);
        $doc = KnowledgeBaseDocument::create([
            'kb_id'       => $kb,
            'file_name'   => 'Conditions générales.docx',
            'stored_name' => 'test_' . bin2hex(random_bytes(4)) . '.docx',
            'mime_type'   => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size'   => 10,
        ]);
        $this->db()->prepare(
            "UPDATE knowledge_base_documents SET vector_status = 'done', chunk_count = ? WHERE id = ?"
        )->execute([count($chunks), $doc]);
        $insert = $this->db()->prepare(
            'INSERT INTO knowledge_base_chunks (document_id, kb_id, chunk_index, content, token_count) VALUES (?, ?, ?, ?, 10)'
        );
        foreach (array_values($chunks) as $i => $content) {
            $insert->execute([$doc, $kb, $i, $content]);
        }
        return $kb;
    }

    private function share(int $kbId, int $userId): void
    {
        $this->db()->prepare('INSERT INTO knowledge_base_shares (kb_id, user_id, can_manage) VALUES (?, ?, 0)')
            ->execute([$kbId, $userId]);
    }

    private function shareCount(int $kbId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM knowledge_base_shares WHERE kb_id = ?');
        $stmt->execute([$kbId]);
        return (int)$stmt->fetchColumn();
    }

    private function kbIds(array $rows): array
    {
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        sort($ids);
        return $ids;
    }

    public function testUneBasePubliqueSeVoitEtSeCocheParTouteLOrganisation(): void
    {
        $this->user(1, 'creator');
        $reader = $this->user(2, 'user');
        $other  = $this->user(3, 'creator');
        $kb = $this->readyKb('Accueil', 1);

        $this->assertNotContains($kb, $this->kbIds(KnowledgeBase::selectableForUser($reader)), 'Commune sans partage : cachée aux autres.');
        KnowledgeBase::setVisibility($kb, KnowledgeBase::VIS_PUBLIC, true);

        $row = KnowledgeBase::findById($kb);
        $this->assertSame(KnowledgeBase::VIS_PUBLIC, KnowledgeBase::visibility($row));
        $this->assertContains($kb, $this->kbIds(KnowledgeBase::selectableForUser($reader)));
        $this->assertTrue(KnowledgeBase::visibleBy($kb, $reader));
        $this->assertContains($kb, $this->kbIds(KnowledgeBase::forManager($other)), 'Un créateur peut la lier à ses assistants.');
        $this->assertFalse(KnowledgeBase::manageableBy($kb, $other), 'Seuls son créateur et les administrateurs la modifient.');
        $this->assertFalse(KnowledgeBase::manageableBy($kb, $reader));
    }

    public function testPublicOuPriveeRetireLesPartages(): void
    {
        $this->user(1, 'creator');
        $this->user(2, 'creator');
        $kb = $this->readyKb('Procédures', 1);
        $this->share($kb, 2);

        $this->assertSame(1, KnowledgeBase::setVisibility($kb, KnowledgeBase::VIS_PUBLIC, true));
        $this->assertSame(0, $this->shareCount($kb));

        KnowledgeBase::setVisibility($kb, KnowledgeBase::VIS_COMMON, true);
        $row = KnowledgeBase::findById($kb);
        $this->assertSame(KnowledgeBase::VIS_COMMON, KnowledgeBase::visibility($row));
        $this->share($kb, 2);

        KnowledgeBase::setVisibility($kb, KnowledgeBase::VIS_PRIVATE, true);
        $row = KnowledgeBase::findById($kb);
        $this->assertSame(KnowledgeBase::VIS_PRIVATE, KnowledgeBase::visibility($row));
        $this->assertFalse(KnowledgeBase::isPublic($row), 'Privée et publique ne vont jamais ensemble.');
        $this->assertSame(0, $this->shareCount($kb));
    }

    public function testUnAdministrateurNeRendJamaisPriveeLaBaseDUnAutre(): void
    {
        $this->user(1, 'creator');
        $this->user(9, 'admin');
        $kb = $this->readyKb('Catalogue', 1);

        $this->assertSame(0, KnowledgeBase::setVisibility($kb, KnowledgeBase::VIS_PRIVATE, false));
        $this->assertSame(KnowledgeBase::VIS_COMMON, KnowledgeBase::visibility(KnowledgeBase::findById($kb)));

        // Une base privée ne se rouvre que par son créateur.
        KnowledgeBase::setVisibility($kb, KnowledgeBase::VIS_PRIVATE, true);
        $this->assertSame(0, KnowledgeBase::setVisibility($kb, KnowledgeBase::VIS_PUBLIC, false));
        $this->assertSame(KnowledgeBase::VIS_PRIVATE, KnowledgeBase::visibility(KnowledgeBase::findById($kb)));
    }

    public function testQueSaitElleTrouveLesExtraitsDeCetteBaseSeulement(): void
    {
        $this->user(1, 'creator');
        $kb    = $this->readyKb('CGV', 1, [
            'Article 9 — Pénalités de retard : trois fois le taux d\'intérêt légal.',
            'Article 2 — Commandes : toute commande est ferme après signature.',
        ]);
        $autre = $this->readyKb('Autre', 1, ['Pénalités de retard dans une autre base.']);

        $hits = KnowledgeBaseDocument::searchExtracts($kb, 'pénalités');
        $this->assertNotEmpty($hits);
        foreach ($hits as $hit) {
            $this->assertStringContainsString('Pénalités', $hit['excerpt']);
            $this->assertSame('Conditions générales.docx', $hit['file_name']);
        }
        $this->assertSame([], KnowledgeBaseDocument::searchExtracts($kb, 'xylophone'));
        $this->assertSame([], KnowledgeBaseDocument::searchExtracts($kb, '  '));
        $this->assertNotSame($autre, $kb);

        $names = KnowledgeBaseDocument::namesByKb([$kb, $autre, 0]);
        $this->assertSame('Conditions générales.docx', $names[$kb]);
        $this->assertArrayHasKey($autre, $names);
    }
}
