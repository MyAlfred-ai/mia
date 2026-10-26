<?php

declare(strict_types=1);

/**
 * Pipeline des bases de connaissances sur MySQL : chunking, fallback lexical
 * multi-bases (chemin utilisé quand Qdrant / l'API d'embeddings est
 * indisponible), budget, liaisons N-N scopées, quotas, cascade de suppression.
 * Le chemin vectoriel (Qdrant réel) est couvert par les tests end-to-end
 * manuels — ici tout tourne sans service externe. Auto-skippé sans base.
 */
final class KnowledgeBasePipelineTest extends DatabaseTestCase
{
    private array $kbIds = [];

    protected function tearDown(): void
    {
        foreach ($this->kbIds as $kbId) {
            $dir = KnowledgeBase::storageDirFor($kbId);
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
        $this->kbIds = [];
    }

    private function newKb(string $name = '__TEST_KB__', ?int $createdBy = null): int
    {
        $id = KnowledgeBase::create([
            'name'               => $name,
            'description'        => null,
            'embedding_provider' => 'openai',
            'embedding_model'    => 'text-embedding-3-large',
            'embedding_dims'     => 1536,
            'is_active'          => 1,
            'created_by'         => $createdBy,
        ]);
        $this->kbIds[] = $id;
        return $id;
    }

    private function mkDoc(int $kbId, string $name, string $text): int
    {
        $id = KnowledgeBaseDocument::create([
            'kb_id'       => $kbId,
            'file_name'   => $name,
            'stored_name' => 'test_' . bin2hex(random_bytes(4)) . '.txt',
            'mime_type'   => 'text/plain',
            'file_size'   => strlen($text),
        ]);
        KnowledgeBaseDocument::setExtractedText($id, $text);
        KnowledgeBaseDocument::rechunkDocument($id);
        KnowledgeBaseDocument::setExtractionStatus($id, 'done');
        return $id;
    }

    // ===================== Chunking =====================

    public function testRechunkCreatesChunksWithKbId(): void
    {
        $kb  = $this->newKb();
        $doc = $this->mkDoc($kb, 'procedure.txt', 'La procédure de sauvegarde des serveurs est exécutée chaque nuit.');
        $row = $this->db()->query(
            "SELECT COUNT(*) AS n, MIN(kb_id) AS kb FROM knowledge_base_chunks WHERE document_id = $doc"
        )->fetch();
        $this->assertGreaterThanOrEqual(1, (int)$row['n']);
        $this->assertSame($kb, (int)$row['kb']);
    }

    public function testRechunkIsIdempotent(): void
    {
        $kb  = $this->newKb();
        $doc = $this->mkDoc($kb, 'a.txt', 'Contenu de test suffisant pour produire un chunk.');
        $n1  = KnowledgeBaseDocument::rechunkDocument($doc);
        $n2  = KnowledgeBaseDocument::rechunkDocument($doc);
        $this->assertSame($n1, $n2);
        $cnt = (int)$this->db()->query("SELECT COUNT(*) FROM knowledge_base_chunks WHERE document_id = $doc")->fetchColumn();
        $this->assertSame($n2, $cnt);
    }

    // ===================== Fallback lexical =====================

    public function testLexicalFallbackRanksRelevantContentFirst(): void
    {
        $kb = $this->newKb();
        $this->mkDoc($kb, 'incendie.txt', 'La réglementation incendie impose des extincteurs accessibles dans chaque couloir du bâtiment.');
        $this->mkDoc($kb, 'conges.txt', 'Le calendrier des congés payés annuels est validé par le service ressources humaines.');

        $ctx = KnowledgeBaseDocument::retrieveLexicalFallback([$kb], 'extincteurs réglementation incendie', 2000);
        $this->assertNotNull($ctx);
        $this->assertStringContainsString('incendie.txt', $ctx);
        $this->assertStringContainsString('extincteurs', $ctx);
    }

    public function testLexicalFallbackSearchesAcrossMultipleKbs(): void
    {
        $kbA = $this->newKb('__TEST_KB_A__');
        $kbB = $this->newKb('__TEST_KB_B__');
        $this->mkDoc($kbA, 'reseau.txt', 'Le pare-feu du réseau interne bloque les connexions entrantes non autorisées.');
        $this->mkDoc($kbB, 'paie.txt', 'Les bulletins de paie sont distribués le cinquième jour ouvré du mois.');

        $ctx = KnowledgeBaseDocument::retrieveLexicalFallback([$kbA, $kbB], 'bulletins de paie distribués', 2000);
        $this->assertNotNull($ctx);
        $this->assertStringContainsString('paie.txt', $ctx);

        // Une seule base interrogée → l'autre n'apparaît pas.
        $ctxA = KnowledgeBaseDocument::retrieveLexicalFallback([$kbA], 'bulletins de paie distribués', 2000);
        if ($ctxA !== null) {
            $this->assertStringNotContainsString('paie.txt', $ctxA);
        } else {
            $this->assertNull($ctxA);
        }
    }

    public function testLexicalFallbackEmptyQueryReturnsFirstChunks(): void
    {
        $kb = $this->newKb();
        $this->mkDoc($kb, 'a.txt', 'Premier document de la base de connaissances.');
        $ctx = KnowledgeBaseDocument::retrieveLexicalFallback([$kb], '', 2000);
        $this->assertNotNull($ctx);
        $this->assertStringContainsString('a.txt', $ctx);
    }

    public function testLexicalFallbackRespectsBudgetAndEmptyCases(): void
    {
        $kb = $this->newKb();
        $this->assertNull(KnowledgeBaseDocument::retrieveLexicalFallback([$kb], 'question', 2000), 'base vide → null');
        $this->mkDoc($kb, 'a.txt', str_repeat('Texte de remplissage pour la base documentaire. ', 200));
        $this->assertNull(KnowledgeBaseDocument::retrieveLexicalFallback([$kb], 'texte', 0), 'budget nul → null');
        $this->assertNull(KnowledgeBaseDocument::retrieveLexicalFallback([], 'texte', 2000), 'aucune base → null');

        $ctx = KnowledgeBaseDocument::retrieveLexicalFallback([$kb], 'remplissage documentaire', 120);
        $this->assertNotNull($ctx);
        $this->assertLessThanOrEqual(120 * 4 + 200, mb_strlen($ctx), 'le contexte reste ~dans le budget (marge en-tête)');
    }

    public function testChunksByIdsPreservesGivenOrderAndSkipsOrphans(): void
    {
        $kb  = $this->newKb();
        $this->mkDoc($kb, 'x.txt', 'Alpha contenu premier.');
        $this->mkDoc($kb, 'y.txt', 'Bravo contenu second.');
        $ids = array_map('intval', $this->db()
            ->query("SELECT id FROM knowledge_base_chunks WHERE kb_id = $kb ORDER BY id ASC")
            ->fetchAll(PDO::FETCH_COLUMN));
        $this->assertCount(2, $ids);

        $rows = KnowledgeBaseDocument::chunksByIds([$ids[1], 999999, $ids[0]]);
        $this->assertCount(2, $rows, 'id orphelin ignoré');
        $this->assertSame($ids[1], (int)$rows[0]['id'], 'ordre fourni préservé (score vectoriel)');
        $this->assertSame($ids[0], (int)$rows[1]['id']);
    }

    // ===================== Liaisons N-N scopées =====================

    public function testSetProfilesIsScopedToManagerPerimeter(): void
    {
        $admin   = $this->insertUser(1, 'admin');
        $creator = $this->insertUser(2, 'creator');
        $orgProfile     = $this->insertProfile('Org', isPersonal: false, createdBy: $admin);
        $creatorProfile = $this->insertProfile('Perso', isPersonal: true, createdBy: $creator);
        $kb = $this->newKb('__TEST_KB_SCOPE__', $creator);

        // L'admin lie la base à l'assistant d'organisation.
        KnowledgeBase::setProfiles($kb, [$orgProfile], [$orgProfile, $creatorProfile]);
        $this->assertSame([$orgProfile], KnowledgeBase::profileIdsFor($kb));

        // Le creator ré-enregistre avec SON périmètre : il coche son assistant.
        // La liaison admin (hors périmètre) doit survivre.
        KnowledgeBase::setProfiles($kb, [$creatorProfile], [$creatorProfile]);
        $linked = KnowledgeBase::profileIdsFor($kb);
        sort($linked);
        $this->assertSame([$orgProfile, $creatorProfile], $linked, 'liaison admin préservée');

        // Le creator décoche tout : seule SA liaison disparaît.
        KnowledgeBase::setProfiles($kb, [], [$creatorProfile]);
        $this->assertSame([$orgProfile], KnowledgeBase::profileIdsFor($kb));
    }

    public function testForProfileReturnsOnlyActiveKbs(): void
    {
        $user    = $this->insertUser(1, 'admin');
        $profile = $this->insertProfile('P', isPersonal: false, createdBy: $user);
        $kbOn    = $this->newKb('__TEST_KB_ON__');
        $kbOff   = $this->newKb('__TEST_KB_OFF__');
        KnowledgeBase::update($kbOff, ['name' => '__TEST_KB_OFF__', 'description' => null, 'is_active' => 0]);
        KnowledgeBase::setForProfile($profile, [$kbOn, $kbOff], [$kbOn, $kbOff]);

        $active = array_map(static fn($kb) => (int)$kb['id'], KnowledgeBase::forProfile($profile));
        $this->assertSame([$kbOn], $active);
    }

    public function testKbCountSubqueryFeedsChatBadge(): void
    {
        $user    = $this->insertUser(1, 'admin');
        $profile = $this->insertProfile('P', isPersonal: false, createdBy: $user);
        $kb      = $this->newKb();
        KnowledgeBase::setForProfile($profile, [$kb], [$kb]);

        $row = Profile::findById($profile);
        $this->assertSame(1, (int)($row['kb_count'] ?? 0));
    }

    // ===================== Quotas & droits =====================

    public function testManageableByAndCountByCreator(): void
    {
        $admin   = $this->insertUser(1, 'admin');
        $creator = $this->insertUser(2, 'creator');
        $other   = $this->insertUser(3, 'creator');
        $kb = $this->newKb('__TEST_KB_OWN__', $creator);

        $this->assertTrue(KnowledgeBase::manageableBy($kb, ['id' => $admin, 'role' => 'admin']));
        $this->assertTrue(KnowledgeBase::manageableBy($kb, ['id' => $creator, 'role' => 'creator']));
        $this->assertFalse(KnowledgeBase::manageableBy($kb, ['id' => $other, 'role' => 'creator']));
        $this->assertSame(1, KnowledgeBase::countByCreator($creator));
        $this->assertSame(0, KnowledgeBase::countByCreator($other));
    }

    // ===================== Partage =====================

    /** Sans partage, une base ne sort du périmètre de personne d'autre. */
    public function testKbIsPrivateToItsCreatorByDefault(): void
    {
        $creator = $this->insertUser(1, 'creator');
        $other   = $this->insertUser(2, 'creator');
        $admin   = $this->insertUser(3, 'admin');
        $kb      = $this->newKb('__TEST_KB_PRIVATE__', $creator);

        $this->assertSame([$kb], $this->kbIdsSeenBy($creator, 'creator'));
        $this->assertSame([], $this->kbIdsSeenBy($other, 'creator'));
        $this->assertContains($kb, $this->kbIdsSeenBy($admin, 'admin'));
    }

    /** Partage nominatif en lecture : visible et rattachable, mais pas éditable. */
    public function testUserShareGrantsVisibilityWithoutWriteByDefault(): void
    {
        $creator = $this->insertUser(1, 'creator');
        $guest   = $this->insertUser(2, 'creator');
        $kb      = $this->newKb('__TEST_KB_SHARED__', $creator);

        KnowledgeBase::setShares($kb, [$guest => 0], []);

        $this->assertSame([$kb], $this->kbIdsSeenBy($guest, 'creator'));
        $this->assertTrue(KnowledgeBase::visibleBy($kb, ['id' => $guest, 'role' => 'creator']));
        $this->assertFalse(KnowledgeBase::manageableBy($kb, ['id' => $guest, 'role' => 'creator']));
        $this->assertFalse(KnowledgeBase::ownedBy($kb, ['id' => $guest, 'role' => 'creator']));

        $rows = KnowledgeBase::forManager(['id' => $guest, 'role' => 'creator']);
        $this->assertSame(1, (int)$rows[0]['is_shared']);
        $this->assertSame(0, (int)$rows[0]['share_can_manage']);
    }

    /** can_manage = 1 : édition oui, suppression et re-partage non. */
    public function testUserShareWithManageGrantsWriteButNotOwnership(): void
    {
        $creator = $this->insertUser(1, 'creator');
        $guest   = $this->insertUser(2, 'creator');
        $kb      = $this->newKb('__TEST_KB_SHARED_RW__', $creator);

        KnowledgeBase::setShares($kb, [$guest => 1], []);

        $this->assertTrue(KnowledgeBase::manageableBy($kb, ['id' => $guest, 'role' => 'creator']));
        $this->assertFalse(KnowledgeBase::ownedBy($kb, ['id' => $guest, 'role' => 'creator']));

        $rows = KnowledgeBase::forManager(['id' => $guest, 'role' => 'creator']);
        $this->assertSame(1, (int)$rows[0]['share_can_manage']);
    }

    /** Partage par groupe : seuls les membres d'un groupe ACTIF en héritent. */
    public function testGroupShareFollowsActiveMembership(): void
    {
        $creator = $this->insertUser(1, 'creator');
        $member  = $this->insertUser(2, 'creator');
        $outside = $this->insertUser(3, 'creator');
        $kb      = $this->newKb('__TEST_KB_GROUP__', $creator);

        $groupId = UserGroup::create(['name' => '__TEST_GROUP_SHARE__', 'is_active' => 1]);
        UserGroup::addUserToGroup($member, $groupId);
        KnowledgeBase::setShares($kb, [], [$groupId => 1]);

        $this->assertSame([$kb], $this->kbIdsSeenBy($member, 'creator'));
        $this->assertTrue(KnowledgeBase::manageableBy($kb, ['id' => $member, 'role' => 'creator']));
        $this->assertSame([], $this->kbIdsSeenBy($outside, 'creator'));

        // Groupe désactivé → le partage cesse de s'appliquer.
        UserGroup::update($groupId, ['name' => '__TEST_GROUP_SHARE__', 'is_active' => 0]);
        $this->assertSame([], $this->kbIdsSeenBy($member, 'creator'));
        $this->assertFalse(KnowledgeBase::manageableBy($kb, ['id' => $member, 'role' => 'creator']));
    }

    /** Une base partagée devient rattachable aux assistants du bénéficiaire. */
    public function testSharedKbIsLinkableByGuestWhileUnsharedIsNot(): void
    {
        $creator = $this->insertUser(1, 'creator');
        $guest   = $this->insertUser(2, 'creator');
        $shared  = $this->newKb('__TEST_KB_LINKABLE__', $creator);
        $private = $this->newKb('__TEST_KB_NOT_LINKABLE__', $creator);
        $profile = $this->insertProfile('Assistant du guest', isPersonal: false, createdBy: $guest);

        KnowledgeBase::setShares($shared, [$guest => 0], []);

        $scope = array_map(
            static fn(array $k): int => (int)$k['id'],
            KnowledgeBase::forManager(['id' => $guest, 'role' => 'creator'])
        );
        // Le guest coche les deux : seule la base partagée doit s'accrocher.
        KnowledgeBase::setForProfile($profile, [$shared, $private], $scope);

        $this->assertSame([$shared], KnowledgeBase::kbIdsForProfile($profile));
    }

    /** setShares remplace l'existant et ignore un partage au créateur lui-même. */
    public function testSetSharesReplacesAndIgnoresCreator(): void
    {
        $creator = $this->insertUser(1, 'creator');
        $first   = $this->insertUser(2, 'creator');
        $second  = $this->insertUser(3, 'creator');
        $kb      = $this->newKb('__TEST_KB_RESHARE__', $creator);

        KnowledgeBase::setShares($kb, [$first => 1, $creator => 1], []);
        $this->assertSame([$first => 1], KnowledgeBase::sharesFor($kb)['users']);

        KnowledgeBase::setShares($kb, [$second => 0], []);
        $shares = KnowledgeBase::sharesFor($kb);
        $this->assertSame([$second => 0], $shares['users']);
        $this->assertSame([], $this->kbIdsSeenBy($first, 'creator'));
    }

    /** Supprimer la base emporte ses partages (FK ON DELETE CASCADE). */
    public function testDeleteKbCascadesShares(): void
    {
        $creator = $this->insertUser(1, 'creator');
        $guest   = $this->insertUser(2, 'creator');
        $kb      = $this->newKb('__TEST_KB_SHARE_CASCADE__', $creator);
        KnowledgeBase::setShares($kb, [$guest => 1], []);

        KnowledgeBase::delete($kb);

        $left = self::$pdo->query('SELECT COUNT(*) FROM knowledge_base_shares')->fetchColumn();
        $this->assertSame(0, (int)$left);
    }

    /** @return list<int> ids des bases vues par cet utilisateur, via forManager. */
    private function kbIdsSeenBy(int $userId, string $role): array
    {
        return array_map(
            static fn(array $k): int => (int)$k['id'],
            KnowledgeBase::forManager(['id' => $userId, 'role' => $role])
        );
    }

    // ===================== Cascade =====================

    public function testDeleteKbCascadesDocumentsChunksAndLinks(): void
    {
        $user    = $this->insertUser(1, 'admin');
        $profile = $this->insertProfile('P', isPersonal: false, createdBy: $user);
        $kb      = $this->newKb();
        $this->mkDoc($kb, 'a.txt', 'Contenu qui produira au moins un chunk en base.');
        KnowledgeBase::setForProfile($profile, [$kb], [$kb]);

        KnowledgeBase::delete($kb);

        foreach (['knowledge_base_documents' => 'kb_id', 'knowledge_base_chunks' => 'kb_id', 'profile_knowledge_bases' => 'kb_id'] as $table => $col) {
            $cnt = (int)$this->db()->query("SELECT COUNT(*) FROM `$table` WHERE `$col` = $kb")->fetchColumn();
            $this->assertSame(0, $cnt, "$table purgée par cascade");
        }
    }

    public function testDeleteDocumentCascadesItsChunks(): void
    {
        $kb  = $this->newKb();
        $doc = $this->mkDoc($kb, 'a.txt', 'Contenu qui produira au moins un chunk en base.');
        KnowledgeBaseDocument::delete($doc);
        $cnt = (int)$this->db()->query("SELECT COUNT(*) FROM knowledge_base_chunks WHERE document_id = $doc")->fetchColumn();
        $this->assertSame(0, $cnt);
    }
}
