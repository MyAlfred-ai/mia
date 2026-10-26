<?php

declare(strict_types=1);

/**
 * Bases de connaissances « en direct » (MyBrain), sur MySQL réel : la
 * connexion chiffrée, la porte par personne (même à travers un assistant),
 * « cochée par défaut », l'état affiché, le disjoncteur, et le contexte d'un
 * tour avec un transport HTTP factice — aucun appel réseau.
 *
 * Auto-skippé sans base.
 */
final class MyBrainKbPipelineTest extends DatabaseTestCase
{
    private const KEY = 'mbk_aaaaaaaaaaaaaaaaaaaaaaaaaaaa012345';

    /** @var list<array{method:string, url:string, headers:list<string>, body:?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Les jobs ne sont pas dans tests/bootstrap.php. Chargés ici plutôt que
        // dans jobContext() : un test qui construit le job AVANT d'appeler
        // cette aide ne passait qu'après un autre test qui l'avait appelée.
        require_once ROOT . '/src/Jobs/JobContext.php';
        require_once ROOT . '/src/Jobs/JobErrorException.php';
        require_once ROOT . '/src/Jobs/MyBrainFeedJob.php';
    }

    private function liveKb(string $name, int $createdBy, bool $active = true, array $droits = ['lire']): int
    {
        $id = KnowledgeBase::create([
            'name'               => $name,
            'description'        => null,
            'backend'            => KnowledgeBase::BACKEND_MYBRAIN,
            // Passés exprès : une base en direct doit les ignorer.
            'embedding_provider' => 'openai',
            'embedding_model'    => 'text-embedding-3-large',
            'embedding_dims'     => 1536,
            'is_active'          => $active ? 1 : 0,
            'created_by'         => $createdBy,
        ]);
        KnowledgeBaseRemote::save($id, 'https://cerveau.exemple.fr', self::KEY, ['application' => 'MIA', 'droits' => $droits]);
        return $id;
    }

    /** Base locale prête (un document vectorisé avec des extraits). */
    private function localKb(string $name, int $createdBy): int
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
        $doc = KnowledgeBaseDocument::create([
            'kb_id' => $id, 'file_name' => 'doc.txt', 'stored_name' => 'test_' . bin2hex(random_bytes(4)) . '.txt',
            'mime_type' => 'text/plain', 'file_size' => 10,
        ]);
        $this->db()->prepare("UPDATE knowledge_base_documents SET vector_status = 'done', chunk_count = 3 WHERE id = ?")
            ->execute([$doc]);
        return $id;
    }

    private function user(int $id, string $role = 'user'): array
    {
        $this->insertUser($id, $role);
        return ['id' => $id, 'role' => $role];
    }

    private function linkProfile(int $profileId, int $kbId): void
    {
        $this->db()->prepare('INSERT INTO profile_knowledge_bases (profile_id, kb_id) VALUES (?, ?)')->execute([$profileId, $kbId]);
    }

    private function idsOf(array $kbs): array
    {
        $ids = array_map(static fn(array $kb): int => (int)$kb['id'], $kbs);
        sort($ids);
        return $ids;
    }

    private function row(int $kbId): array
    {
        $row = KnowledgeBaseRemote::find($kbId);
        $this->assertNotNull($row);
        return $row;
    }

    private function transport(int $status, $body, string $netError = ''): callable
    {
        return function (string $method, string $url, array $headers, ?string $b, int $timeout) use ($status, $body, $netError): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $b];
            return [$status, is_string($body) ? $body : (string)json_encode($body), $netError];
        };
    }

    // =================== Nature et connexion ===================

    public function testUneBaseEnDirectNAniEmbeddingNiDocuments(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = KnowledgeBase::findById($this->liveKb('Cerveau', $admin['id']));

        $this->assertTrue(KnowledgeBase::isRemote($kb));
        $this->assertSame('', $kb['embedding_provider']);
        $this->assertSame('', $kb['embedding_model']);
        $this->assertSame(0, (int)$kb['embedding_dims']);
    }

    public function testLaCleEstChiffreeEtNeSortJamaisDesListes(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        $row   = $this->row($id);

        $this->assertNotSame(self::KEY, $row['api_key_enc']);
        $this->assertStringNotContainsString(self::KEY, (string)$row['api_key_enc']);
        $this->assertSame(self::KEY, KnowledgeBaseRemote::apiKey($row));
        $this->assertSame(MyBrainClient::keyHint(self::KEY), $row['key_hint']);

        // Les lectures de liste portent la connexion (adresse, droits, état)… jamais la clé.
        foreach ([KnowledgeBase::forManager($admin), KnowledgeBase::selectableForUser($admin), [KnowledgeBase::findById($id)]] as $rows) {
            $json = (string)json_encode($rows);
            $this->assertStringNotContainsString('api_key_enc', $json);
            $this->assertStringNotContainsString(self::KEY, $json);
            $this->assertStringContainsString('cerveau.exemple.fr', $json);
        }
    }

    public function testDebrancherEffaceLaConnexion(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        KnowledgeBase::delete($id);
        $this->assertNull(KnowledgeBaseRemote::find($id));
    }

    public function testLaReindexationIgnoreUneBaseEnDirect(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        KnowledgeBase::reindex($id); // ne doit ni lever, ni écrire d'activité
        $this->assertNull(KnowledgeBase::findById($id)['activity_json']);
    }

    // =================== Porte par personne ===================

    public function testSeulsLesGroupesPartagesVoientLeCerveau(): void
    {
        $admin  = $this->user(1, 'admin');
        $membre = $this->user(2);
        $autre  = $this->user(3);
        $kb     = $this->liveKb('Cerveau', $admin['id']);
        $g      = $this->insertGroup('Consultants');
        $this->addMember($membre['id'], $g);
        KnowledgeBase::setShares($kb, [], [$g => 0]);

        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::selectableForUser($membre)));
        $this->assertSame([], $this->idsOf(KnowledgeBase::selectableForUser($autre)));
        $this->assertSame([$kb], $this->idsOf(KnowledgeBase::selectableForUser($admin)));
    }

    public function testUnGroupeInactifNOuvrePasLeCerveau(): void
    {
        $admin  = $this->user(1, 'admin');
        $membre = $this->user(2);
        $kb     = $this->liveKb('Cerveau', $admin['id']);
        $g      = $this->insertGroup('Anciens', null, false);
        $this->addMember($membre['id'], $g);
        KnowledgeBase::setShares($kb, [], [$g => 0]);

        $this->assertSame([], $this->idsOf(KnowledgeBase::selectableForUser($membre)));
    }

    /**
     * La décision : une base MyBrain suit la PERSONNE, jamais l'assistant.
     * Une base locale liée à l'assistant, elle, sert quiconque l'ouvre.
     */
    public function testLaPorteSuitLaPersonneMemeATraversUnAssistant(): void
    {
        $admin  = $this->user(1, 'admin');
        $membre = $this->user(2);
        $autre  = $this->user(3);
        $live   = $this->liveKb('Cerveau', $admin['id']);
        $local  = $this->localKb('Procédures', $admin['id']);
        $g      = $this->insertGroup('Consultants');
        $this->addMember($membre['id'], $g);
        KnowledgeBase::setShares($live, [], [$g => 0]);
        $profile = $this->insertProfile('Expert client', false, $admin['id']);
        $this->linkProfile($profile, $live);
        $this->linkProfile($profile, $local);

        $this->assertSame($this->idsOf([['id' => $live], ['id' => $local]]), $this->idsOf(KnowledgeBase::forProfileAndUser($profile, $membre)));
        $this->assertSame([$local], $this->idsOf(KnowledgeBase::forProfileAndUser($profile, $autre)));
        // Sans utilisateur connu (présentation sans compte de facturation) : aucune base en direct.
        $this->assertSame([$local], $this->idsOf(KnowledgeBase::forProfileAndUser($profile, null)));
        $this->assertSame($this->idsOf([['id' => $live], ['id' => $local]]), $this->idsOf(KnowledgeBase::forProfileAndUser($profile, $admin)));
    }

    public function testUnPartageRetireCoupeAuTourSuivant(): void
    {
        $admin  = $this->user(1, 'admin');
        $membre = $this->user(2);
        $kb     = $this->liveKb('Cerveau', $admin['id']);
        $g      = $this->insertGroup('Consultants');
        $this->addMember($membre['id'], $g);
        KnowledgeBase::setShares($kb, [], [$g => 0]);
        $conv = (int)Conversation::create($membre['id'], null, 'openai::gpt-5.6')['id'];
        ConversationKnowledgeBase::setForConversation($conv, [$kb]);
        $this->assertSame([$kb], $this->idsOf(ConversationKnowledgeBase::selectedKbsForTurn($conv, $membre)));

        KnowledgeBase::setShares($kb, [], []);
        $this->assertSame([], $this->idsOf(ConversationKnowledgeBase::selectedKbsForTurn($conv, $membre)));
    }

    /** La connexion vient de l'administrateur de MyBrain : elle reste aux administrateurs de MIA. */
    public function testUnPartageNeDonneJamaisLaGestionDUnCerveau(): void
    {
        $admin   = $this->user(1, 'admin');
        $creator = $this->user(2, 'creator');
        $kb      = $this->liveKb('Cerveau', $admin['id']);
        KnowledgeBase::setShares($kb, [$creator['id'] => 1], []);

        $this->assertSame(0, KnowledgeBase::sharesFor($kb)['users'][$creator['id']]);
        $this->assertFalse(KnowledgeBase::manageableBy($kb, $creator));
        $this->assertTrue(KnowledgeBase::visibleBy($kb, $creator));
    }

    // =================== Cochée par défaut ===================

    public function testCocheeParDefautPourLesMembresDesGroupesChoisis(): void
    {
        $admin = $this->user(1, 'admin');
        $u1    = $this->user(2);
        $u2    = $this->user(3);
        $kb    = $this->liveKb('Cerveau', $admin['id']);
        $g1    = $this->insertGroup('Consultants');
        $g2    = $this->insertGroup('Stagiaires');
        $this->addMember($u1['id'], $g1);
        $this->addMember($u2['id'], $g2);
        KnowledgeBase::setShares($kb, [], [$g1 => 0, $g2 => 0], ['groups' => [$g1]]);

        $this->assertSame(['users' => [], 'groups' => [$g1]], KnowledgeBase::defaultOnFor($kb));
        $this->assertSame([$kb], KnowledgeBase::defaultOnIdsForUser($u1));
        $this->assertSame([], KnowledgeBase::defaultOnIdsForUser($u2), 'Partagée, mais pas cochée par défaut.');
        // Un admin voit toutes les bases : ça ne fait pas de toutes ses bases par défaut.
        $this->assertSame([], KnowledgeBase::defaultOnIdsForUser($admin));
    }

    public function testCocheeParDefautEstIgnoreeSurUneBaseLocale(): void
    {
        $admin = $this->user(1, 'admin');
        $local = $this->localKb('Procédures', $admin['id']);
        $g     = $this->insertGroup('Consultants');
        KnowledgeBase::setShares($local, [], [$g => 1], ['groups' => [$g]]);

        $this->assertSame(['users' => [], 'groups' => []], KnowledgeBase::defaultOnFor($local));
        $this->assertSame(1, KnowledgeBase::sharesFor($local)['groups'][$g], '« peut modifier » reste possible sur une base locale.');
    }

    public function testUnDefautNeCochePasUneBaseRefuseeNiInactive(): void
    {
        $admin = $this->user(1, 'admin');
        $u1    = $this->user(2);
        $kb    = $this->liveKb('Cerveau', $admin['id']);
        $off   = $this->liveKb('Ancien cerveau', $admin['id'], false);
        $g     = $this->insertGroup('Consultants');
        $this->addMember($u1['id'], $g);
        KnowledgeBase::setShares($kb, [], [$g => 0], ['groups' => [$g]]);
        KnowledgeBase::setShares($off, [], [$g => 0], ['groups' => [$g]]);
        $this->assertSame([$kb], KnowledgeBase::defaultOnIdsForUser($u1));

        KnowledgeBaseRemote::markFailure($this->row($kb), new MyBrainException('Clé refusée', MyBrainException::REFUSED, 401));
        $this->assertSame([], KnowledgeBase::defaultOnIdsForUser($u1));
    }

    // =================== État et disjoncteur ===================

    public function testUnRefusDurableGriseLaBaseUnePannePassagereNon(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = $this->liveKb('Cerveau', $admin['id']);
        $rows  = static fn(): array => array_values(array_filter(
            KnowledgeBase::selectableForUser(['id' => 1, 'role' => 'admin']),
            static fn(array $r): bool => (int)$r['id'] === $kb
        ));

        $this->assertNull(KnowledgeBase::unavailableReason($rows()[0]));

        KnowledgeBaseRemote::markFailure($this->row($kb), new MyBrainException('Serveur absent', MyBrainException::UNREACHABLE));
        $this->assertNull(KnowledgeBase::unavailableReason($rows()[0]), 'Une panne passagère laisse la base cochable.');

        KnowledgeBaseRemote::markFailure($this->row($kb), new MyBrainException('Clé refusée', MyBrainException::REFUSED, 401));
        $this->assertStringContainsString('refusée', (string)KnowledgeBase::unavailableReason($rows()[0]));
    }

    public function testSansConnexionLaBaseNEstPasCochable(): void
    {
        $admin = $this->user(1, 'admin');
        KnowledgeBase::create([
            'name' => 'Orpheline', 'description' => null, 'backend' => KnowledgeBase::BACKEND_MYBRAIN,
            'embedding_provider' => '', 'embedding_model' => '', 'embedding_dims' => 0, 'is_active' => 1, 'created_by' => 1,
        ]);
        $row = KnowledgeBase::selectableForUser($admin)[0];
        $this->assertSame('Connexion MyBrain non configurée', KnowledgeBase::unavailableReason($row));
    }

    public function testLeDisjoncteurSOuvreApresTroisEchecsEtSeRefermeAuSucces(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = $this->liveKb('Cerveau', $admin['id']);
        $panne = new MyBrainException('Serveur absent', MyBrainException::UNREACHABLE);

        for ($i = 1; $i < KnowledgeBaseRemote::BREAKER_THRESHOLD; $i++) {
            KnowledgeBaseRemote::markFailure($this->row($kb), $panne);
            $this->assertFalse(KnowledgeBaseRemote::isPaused($this->row($kb)), "échec $i : pas encore de pause");
        }
        KnowledgeBaseRemote::markFailure($this->row($kb), $panne);
        $this->assertTrue(KnowledgeBaseRemote::isPaused($this->row($kb)));
        $this->assertSame(KnowledgeBaseRemote::STATUS_UNREACHABLE, $this->row($kb)['status']);

        KnowledgeBaseRemote::markOk($this->row($kb));
        $row = $this->row($kb);
        $this->assertFalse(KnowledgeBaseRemote::isPaused($row));
        $this->assertSame(KnowledgeBaseRemote::STATUS_OK, $row['status']);
        $this->assertSame(0, (int)$row['failures']);
    }

    public function testUnRefusDeLaDemandeNeChangeRienALaConnexion(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = $this->liveKb('Cerveau', $admin['id']);
        KnowledgeBaseRemote::markFailure($this->row($kb), new MyBrainException('question trop longue', MyBrainException::REQUEST, 413));
        $row = $this->row($kb);
        $this->assertSame(KnowledgeBaseRemote::STATUS_OK, $row['status']);
        $this->assertSame(0, (int)$row['failures']);
    }

    public function testLEtatAfficheSuitLaConnexion(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = $this->liveKb('Cerveau', $admin['id']);
        $st    = KbStatus::forBases(KnowledgeBase::forManager($admin))[$kb];
        $this->assertSame(KbStatus::OK, $st['state']);
        $this->assertSame('En direct', $st['title']);
        $this->assertStringContainsString('« MIA »', $st['detail']);
        $this->assertFalse($st['busy']);

        KnowledgeBaseRemote::markFailure($this->row($kb), new MyBrainException('Clé refusée par MyBrain', MyBrainException::REFUSED, 401));
        $st = KbStatus::forBases(KnowledgeBase::forManager($admin))[$kb];
        $this->assertSame(KbStatus::ERROR, $st['state']);
        $this->assertStringContainsString('Clé refusée par MyBrain', $st['detail']);
    }

    // =================== Le contexte d'un tour ===================

    public function testLeContexteDuCerveauEntreDansLePromptAvecSesLiens(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = KnowledgeBase::findById($this->liveKb('Cerveau', $admin['id']));
        $res   = MyBrainContext::query(
            $kb,
            'et les chantiers ?',
            [['role' => 'user', 'content' => 'Où en est Globex ?'], ['role' => 'assistant', 'content' => 'ATLAS avance.']],
            200_000,
            $this->transport(200, [
                'fiches'   => [['chemin' => 'wiki/clients/globex.md', 'titre' => 'Globex', 'url' => 'https://cerveau.exemple.fr/cerveau?f=w', 'derniere_maj' => '2026-09-06']],
                'tronque'  => false,
                'contexte' => "### Globex — wiki/clients/globex.md\nClient actif, chantiers IA en cours.",
            ])
        );

        $this->assertTrue($res['ok']);
        $this->assertCount(1, $res['fiches']);
        $this->assertStringContainsString('Client actif, chantiers IA en cours.', (string)$res['block']);
        $this->assertStringContainsString('[Globex](https://cerveau.exemple.fr/cerveau?f=w)', (string)$res['block']);

        $sent = json_decode((string)$this->calls[0]['body'], true);
        $this->assertSame('et les chantiers ?', $sent['question']);
        $this->assertCount(2, $sent['historique'], 'Les tours précédents voyagent pour les questions de suite.');
        $this->assertArrayNotHasKey('budget', $sent, 'Grande fenêtre : le défaut de MyBrain.');
    }

    public function testUnePetiteFenetreDemandeUnBudgetEtCoupe(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = KnowledgeBase::findById($this->liveKb('Cerveau', $admin['id']));
        $long  = "### A\n" . str_repeat('a', 3000) . "\n---\n### B\n" . str_repeat('b', 30000);
        $res   = MyBrainContext::query($kb, 'Globex', [], 8000, $this->transport(200, [
            'fiches' => [['chemin' => 'a.md', 'titre' => 'A', 'url' => '']], 'tronque' => false, 'contexte' => $long,
        ]));

        $sent = json_decode((string)$this->calls[0]['body'], true);
        $this->assertSame(MyBrainContext::MYBRAIN_MIN_BUDGET, $sent['budget']);
        $this->assertLessThanOrEqual(8000, mb_strlen((string)$res['block']));
        $this->assertStringContainsString('Extraits partiels', (string)$res['block']);
        $this->assertStringNotContainsString('### B', (string)$res['block']);
    }

    public function testUneBaseRefuseeOuEnPauseNEstPasAppelee(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        KnowledgeBaseRemote::markFailure($this->row($id), new MyBrainException('Clé refusée', MyBrainException::REFUSED, 401));
        $res = MyBrainContext::query(KnowledgeBase::findById($id), 'Globex', [], 200_000, $this->transport(200, []));
        $this->assertFalse($res['ok']);
        $this->assertSame(MyBrainContext::unavailableNote('Cerveau'), $res['block']);
        $this->assertSame([], $this->calls, 'Un refus durable ne se redemande pas à chaque message.');

        $id2 = $this->liveKb('Autre cerveau', $admin['id']);
        for ($i = 0; $i < KnowledgeBaseRemote::BREAKER_THRESHOLD; $i++) {
            KnowledgeBaseRemote::markFailure($this->row($id2), new MyBrainException('absent', MyBrainException::UNREACHABLE));
        }
        $res = MyBrainContext::query(KnowledgeBase::findById($id2), 'Globex', [], 200_000, $this->transport(200, []));
        $this->assertFalse($res['ok']);
        $this->assertSame([], $this->calls, 'Disjoncteur ouvert : pas d\'appel.');
    }

    public function testUnePanneAuMomentDuMessageEstConsigneeEtAnnoncee(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        $res   = MyBrainContext::query(KnowledgeBase::findById($id), 'Globex', [], 200_000,
            $this->transport(0, '', 'Connection timed out after 8000 milliseconds'));

        $this->assertFalse($res['ok']);
        $this->assertSame(MyBrainContext::unavailableNote('Cerveau'), $res['block'], 'Le modèle est prévenu : il ne répond pas de mémoire.');
        $row = $this->row($id);
        $this->assertSame(KnowledgeBaseRemote::STATUS_UNREACHABLE, $row['status']);
        $this->assertSame(1, (int)$row['failures']);
    }

    public function testUnCerveauQuiNeSaitRienNInjecteRien(): void
    {
        $admin = $this->user(1, 'admin');
        $res   = MyBrainContext::query(KnowledgeBase::findById($this->liveKb('Cerveau', $admin['id'])), 'Recette de la tarte', [], 200_000,
            $this->transport(200, ['fiches' => [], 'tronque' => false, 'contexte' => '']));
        $this->assertTrue($res['ok']);
        $this->assertNull($res['block']);
        $this->assertSame([], $res['fiches']);
    }

    /**
     * Mode compact (modèle à outils) : la carte, qui passe DEVANT les fiches
     * dans le rendu de MyBrain, ne doit pas les évincer — et le bloc dit où
     * trouver la suite.
     */
    public function testLeContexteCompactGardeLesPremieresFichesEtRenvoieAuxOutils(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = KnowledgeBase::findById($this->liveKb('Cerveau', $admin['id']));
        $carte = 'CARTE DES FICHES CITÉES — ' . str_repeat('fiche citée, ', 1500);
        $res   = MyBrainContext::query($kb, 'Où en est Globex ?', [], MyBrainContext::COMPACT_CHARS, $this->transport(200, [
            'fiches'   => [
                ['chemin' => 'wiki/clients/globex.md', 'titre' => 'Globex', 'url' => 'https://cerveau.exemple.fr/cerveau?f=w'],
                ['chemin' => 'wiki/projets/atlas.md', 'titre' => 'ATLAS', 'url' => 'https://cerveau.exemple.fr/cerveau?f=s'],
            ],
            'tronque'  => false,
            'contexte' => $carte . "\n\n---\n\n### Globex — wiki/clients/globex.md\nClient actif. (vault_read_file si besoin)"
                . "\n\n---\n\n### ATLAS — wiki/projets/atlas.md\nProjet en conception.",
        ]), withTools: true);

        $this->assertTrue($res['ok']);
        $this->assertLessThanOrEqual(MyBrainContext::COMPACT_CHARS, mb_strlen((string)$res['block']));
        $this->assertStringContainsString('Client actif.', (string)$res['block']);
        $this->assertStringContainsString('Projet en conception.', (string)$res['block']);
        $this->assertStringNotContainsString('CARTE DES FICHES CITÉES', (string)$res['block']);
        $this->assertStringContainsString('lire_fiche_mybrain', (string)$res['block']);
        $this->assertStringNotContainsString('vault_read_file', (string)$res['block']);
        $sent = json_decode((string)$this->calls[0]['body'], true);
        $this->assertSame(MyBrainContext::MYBRAIN_MIN_BUDGET, $sent['budget'], 'Compact : les extraits les plus denses.');
    }

    // =================== Les outils du cerveau (lot 2) ===================

    public function testChercherDansLeCerveauRendLesFichesEtLeurContexte(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = KnowledgeBase::findById($this->liveKb('Cerveau', $admin['id']));
        $res   = MyBrainContext::searchForTool($kb, 'chantiers en cours chez Globex', 36000, $this->transport(200, [
            'fiches'   => [['chemin' => 'wiki/chantiers/devis.md', 'titre' => 'Devis', 'url' => 'https://cerveau.exemple.fr/cerveau?f=d', 'derniere_maj' => '2026-09-06']],
            'tronque'  => false,
            'contexte' => "### Devis — wiki/chantiers/devis.md\nAutomatisation des devis.",
        ]));

        $this->assertSame('Cerveau', $res['base']);
        $this->assertSame([['titre' => 'Devis', 'chemin' => 'wiki/chantiers/devis.md', 'lien' => 'https://cerveau.exemple.fr/cerveau?f=d', 'derniere_maj' => '2026-09-06']], $res['fiches']);
        $this->assertStringContainsString('Automatisation des devis.', $res['contexte']);
        $this->assertSame(MyBrainContext::DATA_NOTE, $res['avertissement']);
        $sent = json_decode((string)$this->calls[0]['body'], true);
        $this->assertSame('chantiers en cours chez Globex', $sent['question']);
        $this->assertArrayNotHasKey('historique', $sent, 'La question du modèle est autoportante : pas de fil.');
    }

    public function testUneRechercheVideNestPasUnePanne(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        $res   = MyBrainContext::searchForTool(KnowledgeBase::findById($id), 'la recette de la tarte', 36000,
            $this->transport(200, ['fiches' => [], 'tronque' => false, 'contexte' => '']));
        // Une liste VIDE, sans erreur : c'est la forme sur laquelle le dispatch
        // pose son conseil de relance (ToolRegistry::hintIfEmpty, clé `fiches`).
        $this->assertSame([], $res['fiches']);
        $this->assertArrayNotHasKey('error', $res);
        $this->assertSame(KnowledgeBaseRemote::STATUS_OK, $this->row($id)['status']);
    }

    public function testLireUneFicheParSonLienRendSonContenuBorne(): void
    {
        $admin = $this->user(1, 'admin');
        $kb    = KnowledgeBase::findById($this->liveKb('Cerveau', $admin['id']));
        $res   = MyBrainContext::readForTool($kb, 'https://cerveau.exemple.fr/cerveau?f=wiki%2Fclients%2Fglobex.md', 4000, $this->transport(200, [
            'chemin'  => 'wiki/clients/globex.md',
            'titre'   => 'Globex',
            'contenu' => "# Globex\n\n" . str_repeat("Un paragraphe sur le client.\n\n", 400),
            'url'     => 'https://cerveau.exemple.fr/cerveau?f=wiki%2Fclients%2Fglobex.md',
        ]));

        $this->assertSame('Globex', $res['titre']);
        $this->assertTrue($res['coupe']);
        $this->assertLessThanOrEqual(4000, mb_strlen($res['contenu']));
        $this->assertStringContainsString('api/v1/fiche?chemin=wiki%2Fclients%2Fglobex.md', $this->calls[0]['url']);
    }

    public function testUneFicheIntrouvableRevientAuModeleAvecLesCandidats(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        $res   = MyBrainContext::readForTool(KnowledgeBase::findById($id), 'Glubex', 4000, $this->transport(404, [
            'erreur'    => 'aucune fiche ne porte ce nom : « Glubex »',
            'candidats' => [['chemin' => 'wiki/clients/globex.md', 'titre' => 'Globex']],
        ]));

        $this->assertStringContainsString('Fiche introuvable', $res['error']);
        $this->assertSame([['chemin' => 'wiki/clients/globex.md', 'titre' => 'Globex']], $res['candidats']);
        // Un nom mal orthographié ne compte pas comme une panne de la connexion.
        $row = $this->row($id);
        $this->assertSame(KnowledgeBaseRemote::STATUS_OK, $row['status']);
        $this->assertSame(0, (int)$row['failures']);
    }

    public function testUneBaseRefuseeOuEnPauseNExposePasSesOutils(): void
    {
        $admin = $this->user(1, 'admin');
        $ok    = $this->liveKb('Cerveau', $admin['id']);
        $this->assertTrue(MyBrainContext::isCallable(KnowledgeBase::findById($ok)));

        $refusee = $this->liveKb('Cerveau refusé', $admin['id']);
        KnowledgeBaseRemote::markFailure($this->row($refusee), new MyBrainException('Clé refusée', MyBrainException::REFUSED, 401));
        $this->assertFalse(MyBrainContext::isCallable(KnowledgeBase::findById($refusee)));

        $enPause = $this->liveKb('Cerveau en pause', $admin['id']);
        for ($i = 0; $i < KnowledgeBaseRemote::BREAKER_THRESHOLD; $i++) {
            KnowledgeBaseRemote::markFailure($this->row($enPause), new MyBrainException('absent', MyBrainException::UNREACHABLE));
        }
        $this->assertFalse(MyBrainContext::isCallable(KnowledgeBase::findById($enPause)));
        $this->assertSame([], $this->calls, 'la vérification ne fait aucun appel réseau');
    }

    public function testUnOutilDUnCerveauRefuseNAppellePasEtLeDit(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        KnowledgeBaseRemote::markFailure($this->row($id), new MyBrainException('Clé refusée', MyBrainException::REFUSED, 401));
        $kb = KnowledgeBase::findById($id);

        $this->assertStringContainsString('n\'a pas pu être interrogé', MyBrainContext::searchForTool($kb, 'Globex', 36000, $this->transport(200, []))['error']);
        $this->assertStringContainsString('n\'invente rien', MyBrainContext::readForTool($kb, 'Globex', 4000, $this->transport(200, []))['error']);
        $this->assertSame([], $this->calls);
    }

    public function testUnePanneDUnOutilEstConsigneeCommeCelleDuContexte(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id']);
        $res   = MyBrainContext::readForTool(KnowledgeBase::findById($id), 'wiki/clients/globex.md', 4000,
            $this->transport(503, ['erreur' => 'maintenance']));
        $this->assertArrayHasKey('error', $res);
        $row = $this->row($id);
        $this->assertSame(KnowledgeBaseRemote::STATUS_UNREACHABLE, $row['status']);
        $this->assertSame(1, (int)$row['failures']);
    }

    // =================== Nourrir le cerveau (lot 3) ===================

    private const JOB = '0f8e2c4a-1b3d-4e5f-9a7b-6c5d4e3f2a1b';

    /** « Peut nourrir » : une case par groupe — lire le cerveau n'y suffit pas. */
    public function testPeutNourrirSuitLePartageEtLaPersonne(): void
    {
        $admin    = $this->user(1, 'admin');
        $membre   = $this->user(2);
        $lecteur  = $this->user(3);
        $nominatif = $this->user(4, 'creator');
        $ancien   = $this->user(5);
        $kbId     = $this->liveKb('Cerveau', $admin['id']);
        $nourrit  = $this->insertGroup('Consultants');
        $lit      = $this->insertGroup('Commerciaux');
        $inactif  = $this->insertGroup('Anciens', null, false);
        $this->addMember($membre['id'], $nourrit);
        $this->addMember($lecteur['id'], $lit);
        $this->addMember($ancien['id'], $inactif);
        KnowledgeBase::setShares($kbId, [$nominatif['id'] => 0], [$nourrit => 0, $lit => 0, $inactif => 0], [], [
            'users'  => [$nominatif['id']],
            'groups' => [$nourrit, $inactif],
        ]);
        $kb = KnowledgeBase::findById($kbId);

        $this->assertTrue(KnowledgeBase::canFeed($kb, $membre));
        $this->assertTrue(KnowledgeBase::canFeed($kb, $nominatif));
        $this->assertTrue(KnowledgeBase::canFeed($kb, $admin), 'les administrateurs, comme pour la lecture');
        $this->assertFalse(KnowledgeBase::canFeed($kb, $lecteur), 'lire le cerveau ne donne pas le droit de le nourrir');
        $this->assertFalse(KnowledgeBase::canFeed($kb, $ancien), 'un groupe désactivé n\'ouvre rien');
        $this->assertSame(['users' => [$nominatif['id']], 'groups' => [$nourrit, $inactif]], KnowledgeBase::feedersFor($kbId));
    }

    public function testUneBaseLocaleNeSeNourritJamais(): void
    {
        $admin = $this->user(1, 'admin');
        $user  = $this->user(2);
        $kbId  = $this->localKb('Documents', $admin['id']);
        KnowledgeBase::setShares($kbId, [$user['id'] => 0], [], [], ['users' => [$user['id']]]);
        $this->assertSame(['users' => [], 'groups' => []], KnowledgeBase::feedersFor($kbId), 'ignoré sur une base locale');
        $this->assertFalse(KnowledgeBase::canFeed(KnowledgeBase::findById($kbId), $admin));
    }

    /** La clé fixe le maximum : sans le droit « nourrir » côté MyBrain, l'outil n'existe pour personne. */
    public function testSansLeDroitNourrirSurLaCleRienNEstNourrissable(): void
    {
        $admin  = $this->user(1, 'admin');
        $lecture = KnowledgeBase::findById($this->liveKb('Cerveau lecture', $admin['id']));
        $pleine  = KnowledgeBase::findById($this->liveKb('Cerveau complet', $admin['id'], true, ['lire', 'nourrir']));

        $this->assertSame([(int)$pleine['id']], $this->idsOf(MyBrainFeed::feedableBases([$lecture, $pleine], $admin)));

        try {
            MyBrainFeed::deposit($lecture, MyBrainFeed::normalizeArgs(['titre' => 'T', 'texte' => str_repeat('fait ', 10)]), 'a@b.fr',
                $this->transport(202, []));
            $this->fail('dépôt accepté sans le droit « nourrir »');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('« nourrir »', $e->getMessage());
        }
        $this->assertSame([], $this->calls, 'aucun appel sans le droit');
    }

    public function testLeDepotPartAuNomDeLaPersonne(): void
    {
        $admin = $this->user(1, 'admin');
        $id    = $this->liveKb('Cerveau', $admin['id'], true, ['lire', 'nourrir']);
        $depot = MyBrainFeed::deposit(KnowledgeBase::findById($id),
            MyBrainFeed::normalizeArgs(['titre' => 'Réunion', 'texte' => 'Le projet Alpha démarre en octobre.']),
            'alex@exemple.fr',
            $this->transport(202, ['job' => self::JOB, 'statut' => 'en-attente', 'pour' => null,
                'avertissements' => ['alex@exemple.fr n\'a pas de compte myBrain']]));

        $this->assertSame(self::JOB, $depot['job']);
        $this->assertSame(["alex@exemple.fr n'a pas de compte myBrain"], $depot['avertissements']);
        $sent = json_decode((string)$this->calls[0]['body'], true);
        $this->assertSame('alex@exemple.fr', $sent['pour']);
        $this->assertSame('Réunion', $sent['titre']);
        $this->assertSame(KnowledgeBaseRemote::STATUS_OK, $this->row($id)['status']);
    }

    /** Un contexte de job qui n'écrit rien : on lit ce que le job émet. */
    private function jobContext(int $userId, int $convId): JobContext
    {
        return new class (0, $userId, $convId) extends JobContext {
            public array $events = [];
            public function emit(array $event): void { $this->events[] = $event; }
            public function setPhase(string $phase): void {}
            public function shouldStop(): bool { return false; }
        };
    }

    public function testLeSuiviPublieLeLienDeLaRevue(): void
    {
        $admin  = $this->user(1, 'admin');
        $id     = $this->liveKb('Cerveau', $admin['id'], true, ['lire', 'nourrir']);
        $convId = (int)Conversation::create($admin['id'], null, null)['id'];
        $ctx    = $this->jobContext($admin['id'], $convId);

        $job = new MyBrainFeedJob($this->transport(200, [
            'job' => self::JOB, 'statut' => 'fait',
            'proposition' => ['id' => 'p1', 'url' => 'https://cerveau.exemple.fr/revue?id=p1'],
            'pages' => ['wiki/clients/exemple.md'],
        ]));
        $done = $job->run(['kb_id' => $id, 'depot' => self::JOB, 'titre' => 'Réunion', 'base' => 'Cerveau'], $ctx);

        $this->assertStringContainsString('[trancher la proposition](https://cerveau.exemple.fr/revue?id=p1)', $done['content']);
        $this->assertSame('https://cerveau.exemple.fr/api/v1/nourrir/' . self::JOB, $this->calls[0]['url']);
        $last = $this->db()->query("SELECT content FROM messages WHERE conversation_id = $convId ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame($done['content'], $last, 'persisté : visible au rechargement');
    }

    public function testUneAnalyseEnEchecRemonteEnErreurDeJob(): void
    {
        $admin  = $this->user(1, 'admin');
        $id     = $this->liveKb('Cerveau', $admin['id'], true, ['lire', 'nourrir']);
        $convId = (int)Conversation::create($admin['id'], null, null)['id'];
        $job    = new MyBrainFeedJob($this->transport(200, ['statut' => 'erreur', 'erreur' => 'OPENAI_API_KEY absente']));
        try {
            $job->run(['kb_id' => $id, 'depot' => self::JOB, 'titre' => 'Réunion', 'base' => 'Cerveau'], $this->jobContext($admin['id'], $convId));
            $this->fail('une analyse en échec doit lever');
        } catch (JobErrorException $e) {
            $this->assertStringContainsString('OPENAI_API_KEY absente', (string)$e->payload()['message']);
        }
    }
}
