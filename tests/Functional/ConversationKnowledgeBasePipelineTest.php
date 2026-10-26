<?php

declare(strict_types=1);

/**
 * Sélection de bases de connaissances par l'utilisateur, sur MySQL réel :
 * périmètre d'accès, critère de vectorisation, écriture de la sélection,
 * cascades, et comportement quand l'accès change APRÈS le clic.
 *
 * Aucun service externe (ni Qdrant ni API d'embeddings). Auto-skippé sans base.
 */
final class ConversationKnowledgeBasePipelineTest extends DatabaseTestCase
{
    private function newKb(string $name, ?int $createdBy = null, bool $active = true): int
    {
        return KnowledgeBase::create([
            'name'               => $name,
            'description'        => null,
            'embedding_provider' => 'openai',
            'embedding_model'    => 'text-embedding-3-large',
            'embedding_dims'     => 1536,
            'is_active'          => $active ? 1 : 0,
            'created_by'         => $createdBy,
        ]);
    }

    /** Document au statut voulu, sans passer par l'ingestion (pas de fichier). */
    private function mkDoc(int $kbId, string $vectorStatus, int $chunkCount): int
    {
        $id = KnowledgeBaseDocument::create([
            'kb_id'       => $kbId,
            'file_name'   => 'doc.txt',
            'stored_name' => 'test_' . bin2hex(random_bytes(4)) . '.txt',
            'mime_type'   => 'text/plain',
            'file_size'   => 10,
        ]);
        $this->db()->prepare(
            'UPDATE knowledge_base_documents SET vector_status = ?, chunk_count = ? WHERE id = ?'
        )->execute([$vectorStatus, $chunkCount, $id]);
        return $id;
    }

    /** Base prête à être interrogée (1 document vectorisé avec des extraits). */
    private function readyKb(string $name, ?int $createdBy = null, bool $active = true): int
    {
        $kb = $this->newKb($name, $createdBy, $active);
        $this->mkDoc($kb, 'done', 4);
        return $kb;
    }

    private function user(int $id, string $role = 'user'): array
    {
        $this->insertUser($id, $role);
        return ['id' => $id, 'role' => $role];
    }

    private function newConversation(int $userId, ?int $profileId = null): int
    {
        return (int)Conversation::create($userId, $profileId, 'openai::gpt-5.5')['id'];
    }

    private function share(int $kbId, ?int $userId, ?int $groupId, int $canManage = 0): void
    {
        $this->db()->prepare(
            'INSERT INTO knowledge_base_shares (kb_id, user_id, group_id, can_manage) VALUES (?, ?, ?, ?)'
        )->execute([$kbId, $userId, $groupId, $canManage]);
    }

    private function idsOf(array $rows): array
    {
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        sort($ids);
        return $ids;
    }

    // ===================== Périmètre =====================

    public function testUneBaseCreeeParMoiEstSelectionnable(): void
    {
        $u  = $this->user(1, 'creator');
        $kb = $this->readyKb('Mienne', 1);
        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::selectableForUser($u)));
    }

    /**
     * Le cœur de la décision produit : le partage ne regarde AUCUN rôle, un
     * simple `user` doit voir la base qu'on lui a nommément partagée.
     */
    public function testUnSimpleUtilisateurVoitUneBasePartageeNominativement(): void
    {
        $this->insertUser(1, 'creator');
        $u  = $this->user(2, 'user');
        $kb = $this->readyKb('Partagée', 1);
        $this->share($kb, 2, null);
        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::selectableForUser($u)));
    }

    public function testUnPartageDeGroupeSuitLesMembresEtLeStatutDuGroupe(): void
    {
        $this->insertUser(1, 'creator');
        $u  = $this->user(2, 'user');
        $kb = $this->readyKb('Groupe', 1);
        $g  = $this->insertGroup('Équipe');
        $this->addMember(2, $g);
        $this->share($kb, null, $g);
        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::selectableForUser($u)));

        // Groupe désactivé → le partage devient inopérant, sans rien supprimer.
        $this->db()->prepare('UPDATE user_groups SET is_active = 0 WHERE id = ?')->execute([$g]);
        $this->assertSame([], KnowledgeBase::selectableForUser($u));
    }

    public function testUneBaseDAutruiSansPartageResteInvisible(): void
    {
        $this->insertUser(1, 'creator');
        $u = $this->user(2, 'user');
        $this->readyKb('Privée', 1);
        $this->assertSame([], KnowledgeBase::selectableForUser($u));
    }

    public function testUnAdminVoitToutesLesBasesActives(): void
    {
        $this->insertUser(1, 'creator');
        $admin = $this->user(2, 'admin');
        $a = $this->readyKb('A', 1);
        $b = $this->readyKb('B', null);
        $this->readyKb('Éteinte', 1, false);
        $this->assertSame([$a, $b], $this->idsOf(KnowledgeBase::selectableForUser($admin)));
    }

    public function testUneBaseInactiveNEstJamaisProposee(): void
    {
        $u = $this->user(1, 'creator');
        $this->readyKb('Éteinte', 1, false);
        $this->assertSame([], KnowledgeBase::selectableForUser($u));
    }

    /**
     * LA garde contre la divergence des deux SQL de partage : `forManager()` et
     * `selectableForUser()` recopient le même prédicat d'accès. Ils doivent
     * couvrir exactement le même ensemble, à `is_active` près.
     */
    public function testLesDeuxPerimetresCoincidentAIsActivePres(): void
    {
        $this->insertUser(1, 'creator');
        $u = $this->user(2, 'creator');
        $mienne  = $this->readyKb('Mienne', 2);
        $partage = $this->readyKb('Partagée', 1);
        $this->share($partage, 2, null);
        $this->readyKb('Autrui', 1);
        $eteinte = $this->readyKb('Éteinte à moi', 2, false);

        $manager = array_values(array_filter(
            KnowledgeBase::forManager($u),
            static fn(array $kb): bool => (int)$kb['is_active'] === 1
        ));
        $this->assertSame(
            $this->idsOf($manager),
            $this->idsOf(KnowledgeBase::selectableForUser($u))
        );
        $this->assertNotContains($eteinte, $this->idsOf(KnowledgeBase::selectableForUser($u)));
        $this->assertContains($mienne, $this->idsOf(KnowledgeBase::selectableForUser($u)));
    }

    // ===================== Critère de vectorisation =====================

    public function testUneBaseAvecDesExtraitsVectorisesEstUtilisable(): void
    {
        $u  = $this->user(1, 'creator');
        $this->readyKb('Prête', 1);
        $kb = KnowledgeBase::selectableForUser($u)[0];
        $this->assertNull(KnowledgeBase::unavailableReason($kb));
        $this->assertSame(1, (int)$kb['ready_docs']);
    }

    /** Le piège : `done` avec 0 extrait = vectorisée au statut, vide au contenu. */
    public function testUnDocumentDoneSansExtraitNeSuffitPas(): void
    {
        $u  = $this->user(1, 'creator');
        $kb = $this->newKb('Vide', 1);
        $this->mkDoc($kb, 'done', 0);
        $row = KnowledgeBase::selectableForUser($u)[0];
        $this->assertSame(0, (int)$row['ready_docs']);
        $this->assertSame('Aucun texte exploitable dans les documents', KnowledgeBase::unavailableReason($row));
    }

    public function testUnDocumentPretSuffitMemeSiLesAutresEchouent(): void
    {
        $u  = $this->user(1, 'creator');
        $kb = $this->newKb('Mixte', 1);
        $this->mkDoc($kb, 'done', 3);
        $this->mkDoc($kb, 'failed', 0);
        $this->mkDoc($kb, 'pending', 0);
        $row = KnowledgeBase::selectableForUser($u)[0];
        $this->assertNull(KnowledgeBase::unavailableReason($row));
        $this->assertSame(3, (int)$row['doc_count']);
    }

    // ===================== Écriture de la sélection =====================

    public function testLEcritureRemplaceLaSelectionEntiere(): void
    {
        $this->user(1, 'creator');
        $conv = $this->newConversation(1);
        $a = $this->readyKb('A', 1);
        $b = $this->readyKb('B', 1);

        ConversationKnowledgeBase::setForConversation($conv, [$a, $b]);
        $ids = ConversationKnowledgeBase::idsForConversation($conv);
        sort($ids);
        $this->assertSame([$a, $b], $ids);

        ConversationKnowledgeBase::setForConversation($conv, [$b]);
        $this->assertSame([$b], ConversationKnowledgeBase::idsForConversation($conv));

        ConversationKnowledgeBase::setForConversation($conv, []);
        $this->assertSame([], ConversationKnowledgeBase::idsForConversation($conv));
    }

    public function testLeTourEstBorneAuPlafond(): void
    {
        $u    = $this->user(1, 'creator');
        $conv = $this->newConversation(1);
        $ids  = [];
        for ($i = 0; $i < ConversationKnowledgeBase::MAX_SELECTED + 2; $i++) {
            $ids[] = $this->readyKb('Base ' . $i, 1);
        }
        // Écriture forcée au-delà du plafond (POST forgé, ligne héritée) : la
        // lecture du tour doit rester bornée quoi qu'il arrive.
        ConversationKnowledgeBase::setForConversation($conv, $ids);
        $this->assertCount(
            ConversationKnowledgeBase::MAX_SELECTED,
            ConversationKnowledgeBase::selectedKbsForTurn($conv, $u)
        );
    }

    public function testUneBaseHorsPerimetreNAlimenteJamaisLeTour(): void
    {
        $this->insertUser(1, 'creator');
        $u    = $this->user(2, 'user');
        $conv = $this->newConversation(2);
        $autrui = $this->readyKb('Autrui', 1);

        // POST forgé : la ligne existe, mais la résolution du tour re-filtre.
        ConversationKnowledgeBase::setForConversation($conv, [$autrui]);
        $this->assertSame([], ConversationKnowledgeBase::selectedKbsForTurn($conv, $u));
    }

    // ===================== Dérive de l'accès après le clic =====================

    public function testUneBaseDesactiveeDisparaitDuTourMaisPasDeLaTable(): void
    {
        $u    = $this->user(1, 'creator');
        $conv = $this->newConversation(1);
        $kb   = $this->readyKb('Temporaire', 1);
        ConversationKnowledgeBase::setForConversation($conv, [$kb]);

        $this->db()->prepare('UPDATE knowledge_bases SET is_active = 0 WHERE id = ?')->execute([$kb]);
        $this->assertSame([], ConversationKnowledgeBase::selectedKbsForTurn($conv, $u));
        $this->assertSame([$kb], ConversationKnowledgeBase::idsForConversation($conv));

        // Réactivation : le choix se rétablit tout seul.
        $this->db()->prepare('UPDATE knowledge_bases SET is_active = 1 WHERE id = ?')->execute([$kb]);
        $this->assertSame([$kb], $this->idsOf(ConversationKnowledgeBase::selectedKbsForTurn($conv, $u)));
    }

    public function testUnPartageRevoquePuisRetabliSuitLeMemeChemin(): void
    {
        $this->insertUser(1, 'creator');
        $u    = $this->user(2, 'user');
        $conv = $this->newConversation(2);
        $kb   = $this->readyKb('Partagée', 1);
        $this->share($kb, 2, null);
        ConversationKnowledgeBase::setForConversation($conv, [$kb]);
        $this->assertSame([$kb], $this->idsOf(ConversationKnowledgeBase::selectedKbsForTurn($conv, $u)));

        $this->db()->prepare('DELETE FROM knowledge_base_shares WHERE kb_id = ?')->execute([$kb]);
        $this->assertSame([], ConversationKnowledgeBase::selectedKbsForTurn($conv, $u));
        $this->assertSame([$kb], ConversationKnowledgeBase::idsForConversation($conv));

        $this->share($kb, 2, null);
        $this->assertSame([$kb], $this->idsOf(ConversationKnowledgeBase::selectedKbsForTurn($conv, $u)));
    }

    // ===================== Cascades =====================

    public function testLaSelectionMeurtAvecLaConversation(): void
    {
        $this->user(1, 'creator');
        $conv = $this->newConversation(1);
        $kb   = $this->readyKb('A', 1);
        ConversationKnowledgeBase::setForConversation($conv, [$kb]);

        Conversation::delete($conv);
        $n = (int)$this->db()->query(
            'SELECT COUNT(*) FROM conversation_knowledge_bases WHERE conversation_id = ' . $conv
        )->fetchColumn();
        $this->assertSame(0, $n);
    }

    /** Un RESTRICT accidentel ferait échouer la suppression d'une base cochée. */
    public function testLaSuppressionDUneBaseCocheeReussitEtNettoie(): void
    {
        $this->user(1, 'creator');
        $conv = $this->newConversation(1);
        $kb   = $this->readyKb('À supprimer', 1);
        ConversationKnowledgeBase::setForConversation($conv, [$kb]);

        KnowledgeBase::delete($kb);
        $this->assertSame([], ConversationKnowledgeBase::idsForConversation($conv));
    }

    // ===================== Divers =====================

    public function testDeuxConversationsSontCloisonnees(): void
    {
        $u  = $this->user(1, 'creator');
        $c1 = $this->newConversation(1);
        $c2 = $this->newConversation(1);
        $a  = $this->readyKb('A', 1);
        $b  = $this->readyKb('B', 1);

        ConversationKnowledgeBase::setForConversation($c1, [$a]);
        ConversationKnowledgeBase::setForConversation($c2, [$b]);
        $this->assertSame([$a], $this->idsOf(ConversationKnowledgeBase::selectedKbsForTurn($c1, $u)));
        $this->assertSame([$b], $this->idsOf(ConversationKnowledgeBase::selectedKbsForTurn($c2, $u)));
    }

    public function testUneNouvelleConversationNaAucuneBaseCochee(): void
    {
        $u    = $this->user(1, 'creator');
        $this->readyKb('A', 1);
        $conv = $this->newConversation(1);
        $this->assertSame([], ConversationKnowledgeBase::idsForConversation($conv));
        $this->assertSame([], ConversationKnowledgeBase::selectedKbsForTurn($conv, $u));
    }

    /** Lecture best-effort : jamais d'exception qui casserait un tour de chat. */
    public function testLaLectureNeLevePasSurUneConversationInexistante(): void
    {
        $u = $this->user(1, 'creator');
        $this->assertSame([], ConversationKnowledgeBase::idsForConversation(0));
        $this->assertSame([], ConversationKnowledgeBase::selectedKbsForTurn(0, $u));
        $this->assertSame([], ConversationKnowledgeBase::selectedKbsForTurn(999999, $u));
    }
}
