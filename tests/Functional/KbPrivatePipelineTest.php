<?php

declare(strict_types=1);

/**
 * Bases « Uniquement pour moi » (migration 095), sur MySQL réel : la base ne
 * sert qu'à son créateur — aucun rôle, pas même administrateur, ne la voit,
 * ne la coche, ne la partage, ne l'interroge à travers un assistant ni ne la
 * supprime ; la poser retire les partages ; elle part avec le compte.
 *
 * Aucun service externe. Auto-skippé sans base.
 */
final class KbPrivatePipelineTest extends DatabaseTestCase
{
    private function user(int $id, string $role = 'user'): array
    {
        $this->insertUser($id, $role);
        return ['id' => $id, 'role' => $role];
    }

    private function readyKb(string $name, ?int $createdBy, bool $private = false): int
    {
        $kb = KnowledgeBase::create([
            'name'               => $name,
            'description'        => null,
            'embedding_provider' => 'openai',
            'embedding_model'    => 'text-embedding-3-large',
            'embedding_dims'     => 1536,
            'is_active'          => 1,
            'is_private'         => $private,
            'created_by'         => $createdBy,
        ]);
        $doc = KnowledgeBaseDocument::create([
            'kb_id'       => $kb,
            'file_name'   => 'doc.txt',
            'stored_name' => 'test_' . bin2hex(random_bytes(4)) . '.txt',
            'mime_type'   => 'text/plain',
            'file_size'   => 10,
        ]);
        $this->db()->prepare(
            "UPDATE knowledge_base_documents SET vector_status = 'done', chunk_count = 3 WHERE id = ?"
        )->execute([$doc]);
        return $kb;
    }

    private function share(int $kbId, ?int $userId, ?int $groupId, int $canManage = 0): void
    {
        $this->db()->prepare(
            'INSERT INTO knowledge_base_shares (kb_id, user_id, group_id, can_manage) VALUES (?, ?, ?, ?)'
        )->execute([$kbId, $userId, $groupId, $canManage]);
    }

    private function shareCount(int $kbId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM knowledge_base_shares WHERE kb_id = ?');
        $stmt->execute([$kbId]);
        return (int)$stmt->fetchColumn();
    }

    private function idsOf(array $rows): array
    {
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        sort($ids);
        return $ids;
    }

    // ===================== Création et colonne =====================

    public function testLaCreationPoseLOptionSeulementAvecUnCreateur(): void
    {
        $this->user(1, 'creator');
        $mine   = $this->readyKb('À moi', 1, true);
        $orphan = $this->readyKb('Sans créateur', null, true);
        $this->assertTrue(KnowledgeBase::isPrivate(KnowledgeBase::findById($mine)));
        $this->assertFalse(KnowledgeBase::isPrivate(KnowledgeBase::findById($orphan)), 'Sans créateur, personne ne pourrait la voir.');
        $this->assertFalse(KnowledgeBase::isPrivate(KnowledgeBase::findById($this->readyKb('Normale', 1))));
    }

    // ===================== Périmètre : même un administrateur =====================

    public function testUnAdministrateurNeLaVoitNiNeLaCocheNiNeLaGere(): void
    {
        $this->user(1, 'creator');
        $admin   = $this->user(2, 'admin');
        $private = $this->readyKb('Journal', 1, true);
        $public  = $this->readyKb('Doc', 1);

        $this->assertSame([$public], $this->idsOf(KnowledgeBase::forManager($admin)));
        $this->assertSame([$public], $this->idsOf(KnowledgeBase::selectableForUser($admin)));
        $this->assertFalse(KnowledgeBase::ownedBy($private, $admin));
        $this->assertFalse(KnowledgeBase::manageableBy($private, $admin));
        $this->assertFalse(KnowledgeBase::visibleBy($private, $admin));
        $this->assertTrue(KnowledgeBase::ownedBy($public, $admin), 'Le reste du droit d\'administrateur ne bouge pas.');
    }

    public function testLeCreateurGardeTousSesDroits(): void
    {
        $creator = $this->user(1, 'creator');
        $kb      = $this->readyKb('Journal', 1, true);

        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::forManager($creator)));
        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::selectableForUser($creator)));
        $this->assertTrue(KnowledgeBase::ownedBy($kb, $creator));
        $this->assertTrue(KnowledgeBase::manageableBy($kb, $creator));
        $this->assertTrue(KnowledgeBase::createdBy(KnowledgeBase::findById($kb), $creator));
    }

    /** Un administrateur créateur de la base la garde, les AUTRES administrateurs non. */
    public function testUneBasePriveeDUnAdministrateurEchappeAuxAutresAdministrateurs(): void
    {
        $a1 = $this->user(1, 'admin');
        $a2 = $this->user(2, 'admin');
        $kb = $this->readyKb('Notes du directeur', 1, true);

        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::forManager($a1)));
        $this->assertSame([], KnowledgeBase::forManager($a2));
        $this->assertFalse(KnowledgeBase::createdBy(KnowledgeBase::findById($kb), $a2));
    }

    /** Un partage resté en base (posé à la main, restauration) n'ouvre rien. */
    public function testUnPartageResteEnBaseNOuvreRien(): void
    {
        $this->user(1, 'creator');
        $u  = $this->user(2, 'user');
        $kb = $this->readyKb('Journal', 1, true);
        $this->share($kb, 2, null, 1);

        $this->assertSame([], KnowledgeBase::forManager($u));
        $this->assertSame([], KnowledgeBase::selectableForUser($u));
        $this->assertFalse(KnowledgeBase::manageableBy($kb, $u));
        $this->assertFalse(KnowledgeBase::visibleBy($kb, $u));
    }

    // ===================== Partage =====================

    public function testPoserLOptionRetireLesPartagesEtSetSharesNEnEcritPlus(): void
    {
        $this->user(1, 'creator');
        $u  = $this->user(2, 'creator');
        $g  = $this->insertGroup('Équipe');
        $this->addMember(2, $g);
        $kb = $this->readyKb('Projet', 1);
        KnowledgeBase::setShares($kb, [2 => 1], [$g => 0]);
        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::selectableForUser($u)));

        $this->assertSame(2, KnowledgeBase::setPrivate($kb, true));
        $this->assertSame(0, $this->shareCount($kb));
        $this->assertSame([], KnowledgeBase::selectableForUser($u));

        // Un formulaire forgé qui reposte des partages : rien n'est écrit.
        KnowledgeBase::setShares($kb, [2 => 1], [$g => 1]);
        $this->assertSame(0, $this->shareCount($kb));

        // Retirer l'option ne ressuscite rien : les partages sont à refaire.
        $this->assertSame(0, KnowledgeBase::setPrivate($kb, false));
        $this->assertSame([], KnowledgeBase::selectableForUser($u));
        KnowledgeBase::setShares($kb, [2 => 0], []);
        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::selectableForUser($u)));
    }

    public function testLOptionSeRefuseSurUneBaseSystemeOuSansCreateur(): void
    {
        $orphan = $this->readyKb('Sans créateur', null);
        $this->assertSame(0, KnowledgeBase::setPrivate($orphan, true));
        $this->assertFalse(KnowledgeBase::isPrivate(KnowledgeBase::findById($orphan)));

        $this->user(1, 'admin');
        $sys = KnowledgeBase::create([
            'name' => 'Aide', 'embedding_provider' => 'openai', 'embedding_model' => 'text-embedding-3-large',
            'embedding_dims' => 1536, 'is_active' => 1, 'is_system' => 1, 'is_private' => 1, 'created_by' => 1,
        ]);
        $this->assertFalse(KnowledgeBase::isPrivate(KnowledgeBase::findById($sys)), 'Une base système ne se réserve pas.');
        $this->assertSame(0, KnowledgeBase::setPrivate($sys, true));
        $this->assertFalse(KnowledgeBase::isPrivate(KnowledgeBase::findById($sys)));
    }

    // ===================== À travers un assistant =====================

    /**
     * Liée à un assistant que d'autres ouvrent, la base ne répond qu'à son
     * créateur : la porte (gateForUser) passe avant l'assistant.
     */
    public function testLieeAUnAssistantPartageElleNeRepondQuASonCreateur(): void
    {
        $creator = $this->user(1, 'creator');
        $admin   = $this->user(2, 'admin');
        $other   = $this->user(3, 'user');
        $private = $this->readyKb('Journal', 1, true);
        $public  = $this->readyKb('Doc', 1);
        $profile = $this->insertProfile('Assistant commun', false, 1);
        $this->db()->prepare('INSERT INTO profile_knowledge_bases (profile_id, kb_id) VALUES (?, ?), (?, ?)')
            ->execute([$profile, $private, $profile, $public]);

        $this->assertSame([$private, $public], $this->idsOf(KnowledgeBase::forProfileAndUser($profile, $creator)));
        $this->assertSame([$public], $this->idsOf(KnowledgeBase::forProfileAndUser($profile, $admin)));
        $this->assertSame([$public], $this->idsOf(KnowledgeBase::forProfileAndUser($profile, $other)));
        $this->assertSame([$public], $this->idsOf(KnowledgeBase::forProfileAndUser($profile, null)));
    }

    /** Une sélection enregistrée dans une conversation d'autrui ne passe plus. */
    public function testUneSelectionDeConversationEstRefiltreeAChaqueTour(): void
    {
        $this->user(1, 'creator');
        $admin = $this->user(2, 'admin');
        $kb    = $this->readyKb('Projet', 1);
        $conv  = (int)Conversation::create(2, null, 'openai::gpt-5.5')['id'];
        ConversationKnowledgeBase::setForConversation($conv, [$kb]);
        $this->assertSame([$kb], $this->idsOf(ConversationKnowledgeBase::selectedKbsForTurn($conv, $admin)));

        KnowledgeBase::setPrivate($kb, true);
        $this->assertSame([], ConversationKnowledgeBase::selectedKbsForTurn($conv, $admin));
    }

    // ===================== Cerveau MyBrain =====================

    public function testUnCerveauPriveNeSeNourritQueParSonCreateur(): void
    {
        $a1 = $this->user(1, 'admin');
        $a2 = $this->user(2, 'admin');
        $kb = KnowledgeBase::create([
            'name' => 'Cerveau perso', 'backend' => KnowledgeBase::BACKEND_MYBRAIN,
            'is_active' => 1, 'is_private' => 1, 'created_by' => 1,
        ]);
        $row = KnowledgeBase::findById($kb);
        $this->assertTrue(KnowledgeBase::canFeed($row, $a1));
        $this->assertFalse(KnowledgeBase::canFeed($row, $a2));
    }

    // ===================== Suppression du compte =====================

    public function testSesBasesPriveesPartentAvecLeCompte(): void
    {
        $this->user(1, 'creator');
        $this->user(2, 'creator');
        $private = $this->readyKb('Journal', 1, true);
        $public  = $this->readyKb('Doc', 1);
        $others  = $this->readyKb('Journal d\'un autre', 2, true);

        $this->assertSame([$private], KnowledgeBase::privateIdsOf(1));
        $this->assertSame(1, KnowledgeBase::deletePrivateOf(1));
        $this->assertNull(KnowledgeBase::findById($private));
        $this->assertNotNull(KnowledgeBase::findById($public), 'Une base partageable reste, sans propriétaire.');
        $this->assertNotNull(KnowledgeBase::findById($others));
        $docs = $this->db()->prepare('SELECT COUNT(*) FROM knowledge_base_documents WHERE kb_id = ?');
        $docs->execute([$private]);
        $this->assertSame(0, (int)$docs->fetchColumn(), 'Documents partis avec la base (CASCADE).');
    }

    // ===================== Écrans d'ensemble =====================

    public function testLaConsommationCompteLaBaseSansLaNommer(): void
    {
        $creator = $this->user(1, 'creator');
        $admin   = $this->user(2, 'admin');
        $kb      = $this->readyKb('Journal intime', 1, true);
        $this->db()->prepare(
            "INSERT INTO usage_logs (user_id, kb_id, source, llm_provider, tokens_input, tokens_output, credits_used, cost_eur, created_at)
             VALUES (1, ?, 'kb_embedding', 'openai', 100, 0, 3, 0.001, NOW())"
        )->execute([$kb]);

        $forAdmin = UsageLog::byKnowledgeBase(null, null, null, $admin);
        $this->assertCount(1, $forAdmin);
        $this->assertSame(3, $forAdmin[0]['credits']);
        $this->assertStringNotContainsString('Journal', $forAdmin[0]['kb_name']);

        $forCreator = UsageLog::byKnowledgeBase(null, null, null, $creator);
        $this->assertSame('Journal intime', $forCreator[0]['kb_name']);
    }
}
