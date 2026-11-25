<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Mémoire — apprendre des GESTES, sur base réelle :
 *   - un geste répété dans plusieurs conversations devient UNE proposition
 *     (portée, clé du geste, compte), jamais deux ;
 *   - une même référence ne compte qu'une fois ; une conversation seule ne
 *     suffit pas ; le geste contraire freine ;
 *   - refusée, jamais reproposée ; acceptée, elle chasse la préférence
 *     contraire ;
 *   - mêmes portes que l'apprentissage ;
 *   - la demande de retouche après une vraie réponse, la carte d'un brouillon ;
 *   - la question est posée plus tôt que le rythme ordinaire ;
 *   - portée des souvenirs : même texte, portées différentes ; changement de
 *     portée ; page ; entretien.
 */
final class MemorySignalsPipelineTest extends DatabaseTestCase
{
    private const UTILISATEUR = 52;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Controllers/MemoryController.php';
        $this->reglages();
        $this->insertUser(self::UTILISATEUR);
        MemoryLearner::markInformed(self::UTILISATEUR);
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->reglages();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Du geste à la proposition
    // ------------------------------------------------------------------

    public function testUnGesteRepeteDevientUneProposition(): void
    {
        [$a] = $this->conversation();
        [$b] = $this->conversation();

        $this->assertNull(MemorySignals::record($this->user(), 'shorter', 'email', 'mail:1', $a));
        $this->assertNull(MemorySignals::record($this->user(), 'shorter', 'email', 'mail:1', $a), 'Même référence : une fois.');
        $this->assertNull(MemorySignals::record($this->user(), 'shorter', 'email', 'mail:2', $a));
        $this->assertSame(2, $this->evenements());
        $this->assertSame(0, $this->propositions(), 'Deux gestes : pas encore.');

        $id = MemorySignals::record($this->user(), 'shorter', 'email', 'mail:3', $b);
        $this->assertNotNull($id);
        $row = $this->souvenir((int)$id);
        $this->assertSame('proposed', $row['status']);
        $this->assertSame('auto', $row['source']);
        $this->assertSame('email', $row['scope']);
        $this->assertSame('reponses', $row['theme']);
        $this->assertSame('shorter:email', $row['signal_key']);
        $this->assertSame('Préfère des réponses plus courtes', $row['content']);
        $this->assertSame(3, (int)$row['hits']);
        $this->assertSame(0, (int)$row['is_locked']);

        // D'autres gestes ne la reproposent pas.
        [$c] = $this->conversation();
        $this->assertNull(MemorySignals::record($this->user(), 'shorter', 'email', 'mail:4', $c));
        $this->assertSame(1, $this->propositions());

        // Jamais injectée tant qu'elle n'est pas confirmée.
        $this->assertSame('', UserMemory::promptFragment(self::UTILISATEUR));
    }

    public function testUneSeuleConversationNeSuffitPas(): void
    {
        [$a] = $this->conversation();
        for ($i = 1; $i <= 5; $i++) {
            MemorySignals::record($this->user(), 'table', 'all', 'msg:' . $i, $a);
        }
        $this->assertSame(0, $this->propositions());
    }

    public function testLeGesteContraireFreine(): void
    {
        [$a] = $this->conversation();
        [$b] = $this->conversation();
        MemorySignals::record($this->user(), 'longer', 'all', 'msg:1', $a);
        MemorySignals::record($this->user(), 'longer', 'all', 'msg:2', $b);
        MemorySignals::record($this->user(), 'shorter', 'all', 'msg:3', $a);
        MemorySignals::record($this->user(), 'shorter', 'all', 'msg:4', $b);
        $this->assertNull(MemorySignals::record($this->user(), 'shorter', 'all', 'msg:5', $b), 'Tantôt plus court, tantôt plus long : pas de préférence.');
        $this->assertSame(0, $this->propositions());
    }

    public function testRefuseeElleNeRevientPas(): void
    {
        $id = $this->proposition('formal', 'all');
        $this->assertTrue(UserMemory::reject(self::UTILISATEUR, $id));

        [$d] = $this->conversation();
        [$e] = $this->conversation();
        foreach ([[$d, 10], [$d, 11], [$e, 12]] as [$conv, $n]) {
            $this->assertNull(MemorySignals::record($this->user(), 'formal', 'all', 'msg:' . $n, $conv));
        }
        $this->assertSame(0, $this->propositions());
        $this->assertSame('rejected', $this->souvenir($id)['status']);
        $this->assertSame('formal:all', $this->souvenir($id)['signal_key'], 'L\'empreinte garde la clé du geste.');
    }

    public function testAccepteeElleChasseLaPreferenceContraire(): void
    {
        $longer = $this->proposition('longer', 'document');
        $this->assertSame('ok', UserMemory::confirm(self::UTILISATEUR, $longer)['status']);
        $this->assertSame('active', $this->souvenir($longer)['status']);

        // Une autre portée n'est pas touchée.
        $autre = $this->proposition('longer', 'all');
        $this->assertSame('ok', UserMemory::confirm(self::UTILISATEUR, $autre)['status']);

        // Le geste contraire récent freine : il en faut nettement plus.
        $shorter = $this->proposition('shorter', 'document', 7);
        $this->assertSame('ok', UserMemory::confirm(self::UTILISATEUR, $shorter)['status']);
        $this->assertNull($this->souvenirOuNull($longer), 'La préférence contraire de même portée s\'en va.');
        $this->assertSame('active', $this->souvenir($autre)['status']);
        $this->assertStringContainsString(
            '- Préfère des réponses plus courtes (seulement pour les documents et fichiers)',
            UserMemory::promptFragment(self::UTILISATEUR)
        );
    }

    public function testMemesPortesQueLApprentissage(): void
    {
        [$a] = $this->conversation();
        $this->db()->exec('UPDATE users SET memory_learn = 0 WHERE id = ' . self::UTILISATEUR);
        $this->assertNull(MemorySignals::record($this->user(), 'shorter', 'all', 'msg:1', $a));
        $this->db()->exec('UPDATE users SET memory_learn = 1, memory_informed_at = NULL WHERE id = ' . self::UTILISATEUR);
        $this->assertNull(MemorySignals::record($this->user(), 'shorter', 'all', 'msg:2', $a), 'Pas encore informée.');
        $this->db()->exec('UPDATE users SET memory_informed_at = NOW() WHERE id = ' . self::UTILISATEUR);
        Settings::set(MemoryLearner::SETTING_ENABLED, '0');
        $this->assertNull(MemorySignals::record($this->user(), 'shorter', 'all', 'msg:3', $a), 'Coupé par l\'administrateur.');
        $this->assertSame(0, $this->evenements());

        Settings::set(MemoryLearner::SETTING_ENABLED, '1');
        $this->assertNull(MemorySignals::record($this->user(), 'vouvoiement', 'all', 'msg:4', $a), 'Hors de sa portée.');
        $this->assertSame(0, $this->evenements());
    }

    // ------------------------------------------------------------------
    // Sources des gestes
    // ------------------------------------------------------------------

    public function testUneRetoucheApresUneVraieReponse(): void
    {
        // Trois conversations : « Plus court » après une réponse de MIA.
        foreach (range(1, 3) as $i) {
            [$conv, $history, $userMsg] = $this->echange('Plus court');
            MemorySignals::fromFollowUp($this->user(), $conv, $userMsg, 'Plus court', $history);
        }
        $this->assertSame(3, $this->evenements());
        $row = $this->db()->query('SELECT gesture, scope FROM memory_signal_events LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['gesture' => 'shorter', 'scope' => 'all'], $row);
        $this->assertSame(1, $this->propositions());
    }

    public function testRienSansVraieReponseNiSurUneNouvelleDemande(): void
    {
        // La réponse précédente est un message d'erreur : pas une réponse.
        [$conv] = $this->conversation();
        Message::create($conv, 'user', 'Bonjour');
        $err = Message::create($conv, 'assistant', '⚠️ Le service ne répond pas', [], 'openai', 'gpt-5.6');
        $user = Message::create($conv, 'user', 'Plus court');
        MemorySignals::fromFollowUp($this->user(), $conv, (int)$user['id'], 'Plus court', [$this->row((int)$err['id'])]);

        // Une longue demande n'est pas une retouche.
        [$conv2, $history, $msg] = $this->echange('x');
        $long = str_repeat('Rédige-moi un compte rendu détaillé de la réunion. ', 6) . 'Plus court que le précédent.';
        MemorySignals::fromFollowUp($this->user(), $conv2, $msg, $long, $history);

        $this->assertSame(0, $this->evenements());
    }

    public function testLaPorteeVientDeLaReponseRetouchee(): void
    {
        [$conv] = $this->conversation();
        Message::create($conv, 'user', 'Prépare un email pour Paul');
        $reply = Message::create($conv, 'assistant', 'Voici le brouillon proposé.', [], 'openai', 'gpt-5.6');
        Message::setActions((int)$reply['id'], [['t' => 'creer_brouillon_gmail', 'l' => 'Préparation d\'un brouillon Gmail', 'ok' => true]]);
        $user = Message::create($conv, 'user', 'Vouvoie-le');
        MemorySignals::fromFollowUp($this->user(), $conv, (int)$user['id'], 'Vouvoie-le', [$this->row((int)$reply['id'])]);

        $row = $this->db()->query('SELECT gesture, scope, ref FROM memory_signal_events')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['gesture' => 'vouvoiement', 'scope' => 'email', 'ref' => 'msg:' . $user['id']], $row);
    }

    public function testLaCarteDUnBrouillon(): void
    {
        [$conv] = $this->conversation();
        $long = str_repeat('Je reviens vers vous au sujet de la proposition commerciale envoyée la semaine dernière. ', 4);
        $before = ['destinataire' => 'paul@example.com', 'objet' => 'Proposition', 'corps' => $long];
        $edited = ['corps' => mb_substr($long, 0, 120)] + $before;

        // Retouche à la main + préréglage.
        MemorySignals::fromMailRevision($this->user(), $conv, 7, $before, $edited, 'improve', ['corps' => $edited['corps'], 'preset' => 'plus_formel']);
        // Consigne libre.
        MemorySignals::fromMailRevision($this->user(), $conv, 8, $before, $before, 'improve', ['instruction' => 'Plus chaleureux et plus direct']);
        // « Version précédente » : rien.
        MemorySignals::fromMailRevision($this->user(), $conv, 9, $before, $edited, 'edit', ['corps' => $edited['corps'], 'restore' => '1']);
        // « Corriger la langue » : rien.
        MemorySignals::fromMailRevision($this->user(), $conv, 10, $before, $before, 'improve', ['preset' => 'corriger']);

        $rows = $this->db()->query('SELECT gesture, scope, ref FROM memory_signal_events ORDER BY gesture')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([
            ['gesture' => 'direct', 'scope' => 'email', 'ref' => 'mail:8'],
            ['gesture' => 'formal', 'scope' => 'email', 'ref' => 'mail:7'],
            ['gesture' => 'shorter', 'scope' => 'email', 'ref' => 'mail:7'],
            ['gesture' => 'warm', 'scope' => 'email', 'ref' => 'mail:8'],
        ], $rows);
    }

    // ------------------------------------------------------------------
    // Question posée plus tôt
    // ------------------------------------------------------------------

    public function testLaQuestionVientPlusTotQueLeRythmeOrdinaire(): void
    {
        $id = $this->proposition('shorter', 'email');
        // Cinq souvenirs actifs (MIA ne « fait plus connaissance ») et une
        // question posée il y a deux jours : le rythme ordinaire (7 jours)
        // n'est pas échu.
        for ($i = 1; $i <= 5; $i++) {
            UserMemory::add(self::UTILISATEUR, 'Souvenir ' . $i, UserMemory::SOURCE_MANUAL);
        }
        [$vieille, , , $vieilleMsg] = $this->echange('Bonjour');
        $this->db()->prepare('INSERT INTO user_memory_questions (user_id, qkey, conversation_id, message_id, asked_at) VALUES (?, ?, ?, ?, NOW() - INTERVAL 2 DAY)')
            ->execute([self::UTILISATEUR, 'reponses.structure', $vieille, $vieilleMsg]);

        [$conv, , , $reply] = $this->echange('Bonjour');
        $q = MemoryQuestions::forTurn($this->user(), $conv, $reply, $this->faits());
        $this->assertNotNull($q);
        $this->assertSame('proposal', $q['kind']);
        $this->assertSame('Vous me demandez souvent de raccourcir mes emails. Je fais plus court d\'office désormais ?', $q['question']);
        $this->assertSame('Préfère des réponses plus courtes (seulement pour les emails)', $q['quote']);

        // Rechargée, la même question revient avec son texte.
        $pending = MemoryQuestions::pendingFor(self::UTILISATEUR, $conv);
        $this->assertSame($q['question'], $pending['question']);

        // « Oui » : confirmée, injectée avec sa portée.
        $res = MemoryQuestions::answer(self::UTILISATEUR, (int)$q['id'], 'oui');
        $this->assertSame('confirmed', $res['saved']['kind']);
        $this->assertSame('active', $this->souvenir($id)['status']);
        $this->assertStringContainsString('(seulement pour les emails)', UserMemory::promptFragment(self::UTILISATEUR));
    }

    public function testJamaisVeutDireJamais(): void
    {
        $this->proposition('shorter', 'all');
        $this->db()->exec("UPDATE users SET memory_questions = 'off' WHERE id = " . self::UTILISATEUR);
        [$conv, , , $reply] = $this->echange('Bonjour');
        $this->assertNull(MemoryQuestions::forTurn($this->user(), $conv, $reply, $this->faits()));
    }

    // ------------------------------------------------------------------
    // Portée des souvenirs
    // ------------------------------------------------------------------

    public function testMemeTexteAutrePortee(): void
    {
        $all = UserMemory::add(self::UTILISATEUR, 'Préfère le vouvoiement', ['source' => UserMemory::SOURCE_MANUAL]);
        $this->assertSame('ok', $all['status']);
        $mail = UserMemory::add(self::UTILISATEUR, 'Préfère le vouvoiement', ['source' => UserMemory::SOURCE_MANUAL, 'scope' => 'email']);
        $this->assertSame('ok', $mail['status'], 'Même texte, autre portée : un autre souvenir.');
        $this->assertSame('duplicate', UserMemory::add(self::UTILISATEUR, 'préfère le vouvoiement !', ['source' => UserMemory::SOURCE_MANUAL, 'scope' => 'emails'])['status']);

        // Changer la portée vers une portée déjà prise : doublon.
        $this->assertSame('duplicate', UserMemory::update(self::UTILISATEUR, (int)$all['id'], 'Préfère le vouvoiement', null, true, 'email')['status']);
        $this->assertSame('ok', UserMemory::update(self::UTILISATEUR, (int)$all['id'], 'Préfère le vouvoiement', null, true, 'document')['status']);
        $this->assertSame('document', $this->souvenir((int)$all['id'])['scope']);

        $payload = MemoryController::payload(self::UTILISATEUR);
        $this->assertEqualsCanonicalizing(['email', 'document'], array_column($payload['memories'], 'scope'));
    }

    public function testUnSouvenirAppriSRetireGardeSaPortee(): void
    {
        $res = UserMemory::add(self::UTILISATEUR, 'Préfère les tableaux', ['source' => UserMemory::SOURCE_AUTO, 'scope' => 'document']);
        $this->assertTrue(UserMemory::delete(self::UTILISATEUR, (int)$res['id']));
        $this->assertSame('refused', UserMemory::add(self::UTILISATEUR, 'Préfère les tableaux', ['source' => UserMemory::SOURCE_AUTO, 'scope' => 'document'])['status']);
        $this->assertSame('ok', UserMemory::add(self::UTILISATEUR, 'Préfère les tableaux', ['source' => UserMemory::SOURCE_AUTO, 'scope' => 'all'])['status'], 'Retiré pour les documents, pas partout.');
    }

    public function testEntretienEtEffacement(): void
    {
        [$a] = $this->conversation();
        MemorySignals::record($this->user(), 'table', 'all', 'msg:1', $a);
        MemorySignals::record($this->user(), 'table', 'all', 'msg:2', $a);
        $this->db()->exec('UPDATE memory_signal_events SET created_at = NOW() - INTERVAL ' . (MemorySignals::RETENTION_DAYS + 1) . ' DAY WHERE ref = \'msg:1\'');
        MemorySignals::purge();
        $this->assertSame(1, $this->evenements());

        // La conversation supprimée ne fait pas disparaître le geste compté.
        $this->db()->exec('DELETE FROM conversations WHERE id = ' . $a);
        $this->assertSame(1, $this->evenements());

        MemorySignals::forgetUser(self::UTILISATEUR);
        $this->assertSame(0, $this->evenements());
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    private function reglages(): void
    {
        $this->db()->exec("DELETE FROM settings WHERE `key` IN ('memory_enabled', 'memory_questions_enabled', 'memory_learn_enabled', 'memory_llm')");
        Settings::refresh();
    }

    private function user(): array
    {
        return $this->db()->query('SELECT * FROM users WHERE id = ' . self::UTILISATEUR)->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array{0:int} */
    private function conversation(): array
    {
        $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
            ->execute([self::UTILISATEUR, 'Conversation', 'openai::gpt-5.6']);
        return [(int)$this->db()->lastInsertId()];
    }

    /**
     * Une conversation neuve : question, réponse de MIA, puis le message de la
     * personne. Rend [conversation, historique antérieur, message, réponse].
     *
     * @return array{0:int, 1:list<array>, 2:int, 3:int}
     */
    private function echange(string $message, ?int $conv = null): array
    {
        $conv ??= $this->conversation()[0];
        Message::create($conv, 'user', 'Explique-moi le budget');
        $reply = Message::create($conv, 'assistant', 'Voici une explication du budget.', [], 'openai', 'gpt-5.6');
        $history = [$this->row((int)$reply['id'])];
        $user = Message::create($conv, 'user', $message);
        return [$conv, $history, (int)$user['id'], (int)$reply['id']];
    }

    private function row(int $id): array
    {
        return $this->db()->query('SELECT * FROM messages WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    }

    /** Une proposition née d'un geste ($n gestes, deux conversations). */
    private function proposition(string $gesture, string $scope, int $n = 3): int
    {
        [$a] = $this->conversation();
        [$b] = $this->conversation();
        $tag = $gesture . $scope . mt_rand();
        $id  = null;
        for ($i = 1; $i <= $n; $i++) {
            $id = MemorySignals::record($this->user(), $gesture, $scope, $tag . ':' . $i, $i === $n ? $b : $a) ?? $id;
        }
        $this->assertNotNull($id, $gesture . ':' . $scope);
        return (int)$id;
    }

    /** @param array<string, mixed> $faits */
    private function faits(array $faits = []): array
    {
        return $faits + [
            'voice' => false, 'unattended' => false, 'error' => false, 'interrupted' => false,
            'empty' => false, 'cards' => 0, 'choice_block' => false, 'polish_offer' => false,
            'captured' => false, 'prior_replies' => 1,
        ];
    }

    private function evenements(): int
    {
        return (int)$this->db()->query('SELECT COUNT(*) FROM memory_signal_events')->fetchColumn();
    }

    private function propositions(): int
    {
        return (int)$this->db()->query("SELECT COUNT(*) FROM user_memories WHERE status = 'proposed'")->fetchColumn();
    }

    private function souvenir(int $id): array
    {
        $row = $this->souvenirOuNull($id);
        $this->assertNotNull($row, 'Souvenir ' . $id);
        return $row;
    }

    private function souvenirOuNull(int $id): ?array
    {
        $row = $this->db()->query('SELECT * FROM user_memories WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
