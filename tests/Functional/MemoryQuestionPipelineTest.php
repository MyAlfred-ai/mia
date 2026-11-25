<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';
require_once __DIR__ . '/../Support/FakeLlmStream.php';

/**
 * Questions de MIA (mémoire, lot 2), sur base réelle :
 *   - quand une question est posée (et quand jamais), une par conversation ;
 *   - chaque réponse et son effet, une seule fois ;
 *   - la question reposée au rechargement tant qu'elle suit la dernière réponse ;
 *   - un VRAI tour de chat (runSend, faux modèle) pose la question en fin de tour.
 */
final class MemoryQuestionPipelineTest extends DatabaseTestCase
{
    private const UTILISATEUR = 41;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Controllers/MemoryController.php';
        $this->reglages();
        $this->insertUser(self::UTILISATEUR);
        // Déjà informée de l'apprentissage : la carte d'information (lot 3,
        // MemoryLearningPipelineTest) ne passe pas devant les questions.
        MemoryLearner::markInformed(self::UTILISATEUR);
    }

    protected function tearDown(): void
    {
        if (class_exists('ChatController', false)) {
            ChatController::setEmitSink(null);
        }
        LlmCatalog::flushCache();
        LlmCatalog::setToday(null);
        if (self::$pdo !== null) {
            $this->db()->exec('DELETE FROM llm_models');
            $this->db()->exec('DELETE FROM api_keys');
            $this->reglages();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Quand une question est posée
    // ------------------------------------------------------------------

    public function testRienPendantLaToutePremiereConversation(): void
    {
        [$conv, $msg] = $this->echange();
        $this->assertNull(MemoryQuestions::forTurn($this->user(), $conv, $msg, $this->faits()));
        $this->assertSame(0, $this->questions());
    }

    public function testUneQuestionParConversationEtUnEcartEntreDeux(): void
    {
        $this->echange(); // première conversation
        [$conv, $msg] = $this->echange();

        $q = MemoryQuestions::forTurn($this->user(), $conv, $msg, $this->faits());
        $this->assertNotNull($q);
        $this->assertSame('bank', $q['kind']);
        $this->assertSame($msg, $q['message_id']);
        $this->assertSame('Pour vos questions de tous les jours, vous préférez des réponses…', $q['question'], 'Style encore vide : la longueur d\'abord.');

        // Même conversation : jamais une seconde.
        [, $msg2] = $this->echange($conv);
        $this->assertNull(MemoryQuestions::forTurn($this->user(), $conv, $msg2, $this->faits()));

        // Autre conversation, le même jour : l'écart n'est pas écoulé.
        [$autre, $msgAutre] = $this->echange();
        $this->assertNull(MemoryQuestions::forTurn($this->user(), $autre, $msgAutre, $this->faits()));

        // Un jour plus tard, pendant que MIA fait connaissance : la suivante.
        $this->db()->exec('UPDATE user_memory_questions SET asked_at = NOW() - INTERVAL 25 HOUR');
        $suivante = MemoryQuestions::forTurn($this->user(), $autre, $msgAutre, $this->faits());
        $this->assertNotNull($suivante);
        $this->assertSame(2, $this->questions());
    }

    public function testRienQuandLaPersonneOuLAdministrateurLesRefuse(): void
    {
        $this->echange();
        [$conv, $msg] = $this->echange();

        $this->db()->exec("UPDATE users SET memory_questions = 'off' WHERE id = " . self::UTILISATEUR);
        $this->assertNull(MemoryQuestions::forTurn($this->user(), $conv, $msg, $this->faits()));
        $this->db()->exec("UPDATE users SET memory_questions = '' WHERE id = " . self::UTILISATEUR);

        Settings::set(MemoryQuestions::SETTING_ENABLED, '0');
        $this->assertNull(MemoryQuestions::forTurn($this->user(), $conv, $msg, $this->faits()));
        Settings::set(MemoryQuestions::SETTING_ENABLED, '1');

        Settings::set(UserMemory::SETTING_ENABLED, '0');
        $this->assertNull(MemoryQuestions::forTurn($this->user(), $conv, $msg, $this->faits()));
        Settings::set(UserMemory::SETTING_ENABLED, '1');

        $this->assertNull(MemoryQuestions::forTurn($this->user(), $conv, $msg, $this->faits(['cards' => 1])));
        $this->assertSame(0, $this->questions());
        $this->assertNotNull(MemoryQuestions::forTurn($this->user(), $conv, $msg, $this->faits()));
    }

    public function testUnePropositionRevuePasseAvantLaBanque(): void
    {
        $this->echange();
        [$conv, $msg] = $this->echange();
        UserMemory::add(self::UTILISATEUR, 'Une fois seulement', ['source' => 'auto', 'status' => 'proposed']);
        $revue = UserMemory::add(self::UTILISATEUR, 'Lit ses emails le matin', ['source' => 'auto', 'status' => 'proposed', 'hits' => 2]);

        $q = MemoryQuestions::forTurn($this->user(), $conv, $msg, $this->faits());
        $this->assertSame('proposal', $q['kind']);
        $this->assertSame('Lit ses emails le matin', $q['quote']);
        $this->assertSame($revue['id'], (int)$this->db()->query('SELECT memory_id FROM user_memory_questions')->fetchColumn());
    }

    // ------------------------------------------------------------------
    // Réponses
    // ------------------------------------------------------------------

    public function testUneOptionRetientUnSouvenirUneSeuleFois(): void
    {
        $id = $this->question('reponses.structure');
        $r  = MemoryQuestions::answer(self::UTILISATEUR, $id, 'tableaux');
        $this->assertSame(['status' => 'ok', 'saved' => ['kind' => 'memory', 'text' => 'Apprécie les tableaux pour comparer ou résumer']], $r);

        $row = $this->db()->query('SELECT * FROM user_memories')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('question', $row['source']);
        $this->assertSame('reponses', $row['theme']);
        $this->assertSame(1, (int)$row['is_locked']);
        $this->assertSame('question', MemoryController::item(UserMemory::forPage(self::UTILISATEUR)[0], null)['origin']);

        $this->assertSame('already', MemoryQuestions::answer(self::UTILISATEUR, $id, 'listes')['status']);
        $this->assertSame(1, UserMemory::countForUser(self::UTILISATEUR));
        $this->assertSame('answered', $this->db()->query('SELECT outcome FROM user_memory_questions WHERE id = ' . $id)->fetchColumn());
    }

    public function testLaLongueurRegleLeStyleDeReponse(): void
    {
        $id = $this->question('reponses.longueur');
        $r  = MemoryQuestions::answer(self::UTILISATEUR, $id, 'courtes');
        $this->assertSame('style', $r['saved']['kind']);
        $this->assertSame('concis', $this->db()->query('SELECT ai_style FROM users WHERE id = ' . self::UTILISATEUR)->fetchColumn());
        $this->assertSame(0, UserMemory::countForUser(self::UTILISATEUR), 'Un style, pas un souvenir.');

        $id2 = $this->question('methode.options', 2);
        $this->assertSame('invalid', MemoryQuestions::answer(self::UTILISATEUR, $id2, 'inexistante')['status']);
        $this->assertNull($this->db()->query('SELECT answered_at FROM user_memory_questions WHERE id = ' . $id2)->fetchColumn(), 'Une réponse refusée ne consigne rien.');
    }

    public function testUneReponseLibreSuitLeGabaritEtRefuseLesSecrets(): void
    {
        $id = $this->question('profil.role');
        $this->assertSame('invalid', MemoryQuestions::answer(self::UTILISATEUR, $id, 'autre', '   ')['status']);
        $this->assertSame('sensitive', MemoryQuestions::answer(self::UTILISATEUR, $id, 'autre', 'mon mot de passe est soleil')['status']);
        $r = MemoryQuestions::answer(self::UTILISATEUR, $id, 'autre', 'Responsable qualité');
        $this->assertSame('Métier : Responsable qualité', $r['saved']['text']);
        $this->assertSame('profil', $this->db()->query('SELECT theme FROM user_memories')->fetchColumn());
    }

    public function testUnePropositionSeConfirmeSeRefuseOuSeCorrige(): void
    {
        $a = UserMemory::add(self::UTILISATEUR, 'Lit ses emails le matin', ['source' => 'auto', 'status' => 'proposed', 'hits' => 2]);
        $b = UserMemory::add(self::UTILISATEUR, 'Travaille le soir', ['source' => 'auto', 'status' => 'proposed', 'hits' => 2]);
        $c = UserMemory::add(self::UTILISATEUR, 'Aime le vert', ['source' => 'auto', 'status' => 'proposed', 'hits' => 2]);

        $this->assertSame('confirmed', MemoryQuestions::answer(self::UTILISATEUR, $this->question('proposition', 1, $a['id']), 'oui')['saved']['kind']);
        $this->assertSame('active', $this->statut($a['id']));

        $this->assertSame('rejected', MemoryQuestions::answer(self::UTILISATEUR, $this->question('proposition', 2, $b['id']), 'non')['saved']['kind']);
        $this->assertSame('rejected', $this->statut($b['id']));

        $r = MemoryQuestions::answer(self::UTILISATEUR, $this->question('proposition', 3, $c['id']), 'autre', 'Aime le vert sapin');
        $this->assertSame('memory', $r['saved']['kind']);
        $this->assertSame('active', $this->statut($c['id']));
        $this->assertSame('Aime le vert sapin', $this->db()->query('SELECT content FROM user_memories WHERE id = ' . $c['id'])->fetchColumn());
    }

    public function testPasserEtNePlusDemander(): void
    {
        $id = $this->question('methode.sources');
        $this->assertSame('skipped', MemoryQuestions::answer(self::UTILISATEUR, $id, 'passer')['saved']['kind']);
        $this->assertSame('skipped', $this->db()->query('SELECT outcome FROM user_memory_questions WHERE id = ' . $id)->fetchColumn());

        $id2 = $this->question('methode.options', 2);
        $this->assertSame('stopped', MemoryQuestions::answer(self::UTILISATEUR, $id2, 'stop')['saved']['kind']);
        $this->assertSame('off', $this->db()->query('SELECT memory_questions FROM users WHERE id = ' . self::UTILISATEUR)->fetchColumn());

        // Une question d'une autre personne n'existe pas pour elle.
        $this->insertUser(42);
        $this->assertSame('not_found', MemoryQuestions::answer(42, $id2, 'une')['status']);
    }

    // ------------------------------------------------------------------
    // Rechargement
    // ------------------------------------------------------------------

    public function testLaQuestionRevientTantQuElleSuitLaDerniereReponse(): void
    {
        [$conv, $msg] = $this->echange();
        $this->db()->prepare('INSERT INTO user_memory_questions (user_id, qkey, conversation_id, message_id) VALUES (?, ?, ?, ?)')
            ->execute([self::UTILISATEUR, 'methode.clarifier', $conv, $msg]);

        $pending = MemoryQuestions::pendingFor(self::UTILISATEUR, $conv);
        $this->assertSame($msg, $pending['message_id']);
        $this->assertSame('Quand votre demande est un peu floue, je dois plutôt…', $pending['question']);

        // La réponse a été donnée : plus rien à reposer.
        MemoryQuestions::answer(self::UTILISATEUR, (int)$pending['id'], 'proposer');
        $this->assertNull(MemoryQuestions::pendingFor(self::UTILISATEUR, $conv));
    }

    public function testUnePropositionTrancheeAilleursNeRevientPas(): void
    {
        [$conv, $msg] = $this->echange();
        $p = UserMemory::add(self::UTILISATEUR, 'Lit ses emails le matin', ['source' => 'auto', 'status' => 'proposed', 'hits' => 2]);
        $this->db()->prepare('INSERT INTO user_memory_questions (user_id, qkey, memory_id, conversation_id, message_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([self::UTILISATEUR, 'proposition', $p['id'], $conv, $msg]);
        $this->assertNotNull(MemoryQuestions::pendingFor(self::UTILISATEUR, $conv));

        UserMemory::confirm(self::UTILISATEUR, $p['id']); // depuis la page Mémoire
        $this->assertNull(MemoryQuestions::pendingFor(self::UTILISATEUR, $conv));
    }

    // ------------------------------------------------------------------
    // Un vrai tour de chat
    // ------------------------------------------------------------------

    public function testUnVraiTourPoseLaQuestionEnFinDeTour(): void
    {
        $this->db()->exec('DELETE FROM llm_models');
        $this->db()->exec('DELETE FROM api_keys');
        Settings::refresh();
        LlmCatalog::setToday('2026-10-14');
        ApiKey::save('openai', 'sk-test-factice');
        LlmModel::save(['provider' => 'openai', 'model' => 'o3', 'display_name' => 'o3', 'is_active' => 1, 'sort_order' => 0]);
        FakeLlmStream::install();
        $events = [];
        ChatController::setEmitSink(static function (array $e) use (&$events): void {
            $events[] = $e;
        });

        $this->echange(); // une première conversation
        [$conv] = $this->echange(); // une réponse existe déjà dans celle-ci
        FakeLlmStream::$queue = [FakeLlmStream::sse('Voici le résumé demandé.')];
        $q = Message::create($conv, 'user', 'Résume-moi ce point');
        $r = (new ChatController())->runSend(
            ['content' => 'Résume-moi ce point', 'llm' => 'openai::o3', 'user_msg_id' => (int)$q['id'], 'needs_title' => false],
            self::UTILISATEUR,
            $conv
        );

        $this->assertTrue($r['done'] ?? false);
        $this->assertIsArray($r['memory_question']);
        $this->assertSame((int)$r['msg_id'], $r['memory_question']['message_id']);
        $this->assertCount(1, FakeLlmStream::$requests, 'La question n\'a coûté aucun appel au modèle.');

        // Une réponse qui se termine sur des choix en boutons n'en reçoit pas.
        $this->db()->exec('DELETE FROM user_memory_questions');
        [$conv2] = $this->echange();
        FakeLlmStream::$queue = [FakeLlmStream::sse("Laquelle ?\n\n```choix\n- A\n- B\n```")];
        $q2 = Message::create($conv2, 'user', 'Propose-moi deux pistes');
        $r2 = (new ChatController())->runSend(
            ['content' => 'Propose-moi deux pistes', 'llm' => 'openai::o3', 'user_msg_id' => (int)$q2['id'], 'needs_title' => false],
            self::UTILISATEUR,
            $conv2
        );
        $this->assertNull($r2['memory_question']);
    }

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

    /** @return array{0:int, 1:int} conversation, réponse de l'assistant */
    private function echange(?int $conv = null): array
    {
        if ($conv === null) {
            $this->db()->prepare('INSERT INTO conversations (user_id, title, llm_used) VALUES (?, ?, ?)')
                ->execute([self::UTILISATEUR, 'Conversation', 'openai::o3']);
            $conv = (int)$this->db()->lastInsertId();
        }
        Message::create($conv, 'user', 'Bonjour');
        $msg = Message::create($conv, 'assistant', 'Bonjour, que puis-je faire ?');
        return [$conv, (int)$msg['id']];
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

    private function questions(): int
    {
        return (int)$this->db()->query('SELECT COUNT(*) FROM user_memory_questions')->fetchColumn();
    }

    /** Inscrit une question (dans sa propre conversation) et rend son id. */
    private function question(string $key, int $n = 1, ?int $memoryId = null): int
    {
        [$conv, $msg] = $this->echange();
        $this->db()->prepare('INSERT INTO user_memory_questions (user_id, qkey, memory_id, conversation_id, message_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([self::UTILISATEUR, $key, $memoryId, $conv, $msg]);
        return (int)$this->db()->lastInsertId();
    }

    private function statut(int $id): string
    {
        return (string)$this->db()->query('SELECT status FROM user_memories WHERE id = ' . $id)->fetchColumn();
    }
}
