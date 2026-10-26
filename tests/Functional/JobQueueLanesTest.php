<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Plusieurs workers en parallèle (MIA_WORKERS) : ce que le worker unique
 * garantissait sans le dire doit tenir quand ils se disputent la file.
 *
 *  - priorité : une personne qui attend passe avant une tâche programmée,
 *    elle-même avant la maintenance ;
 *  - couloirs : les tours d'une même conversation s'exécutent un par un et
 *    dans l'ordre ; une seule maintenance à la fois ;
 *  - aucun job pris deux fois, aucun job oublié ;
 *  - le reaper ne clôt jamais deux fois le même job.
 *
 * Le dernier test lance de VRAIS processus concurrents (tests/Support/
 * claim_loop.php) : une course entre transactions MySQL ne se tranche pas
 * à la lecture du SQL.
 */
final class JobQueueLanesTest extends DatabaseTestCase
{
    private int $userId = 0;

    public static function setUpBeforeClass(): void
    {
        // Hors de tests/bootstrap.php (le reste de la file n'y est pas requis).
        require_once ROOT . '/src/Helpers/BackgroundJob.php';
        require_once ROOT . '/src/Jobs/JobContext.php';
        require_once ROOT . '/src/Jobs/JobErrorException.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->insertUser(1);
    }

    private function conversation(): int
    {
        return (int)Conversation::create($this->userId, null, 'openai')['id'];
    }

    private function jobStatus(int $jobId): string
    {
        $stmt = $this->db()->prepare('SELECT status FROM async_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        return (string)$stmt->fetchColumn();
    }

    private function finish(int $jobId): void
    {
        $this->db()->prepare("UPDATE async_jobs SET status = 'done', finished_at = NOW() WHERE id = ?")
            ->execute([$jobId]);
    }

    /**
     * Comme JobQueue::enqueueMaintenance, sans le déclenchement : sur un poste
     * qui sait forker, celui-ci lancerait un run_job.php en tâche de fond —
     * un exécutant de plus, hors du banc, qui fausserait chaque mesure.
     */
    private function maintenance(string $op, int $id): int
    {
        return JobQueue::enqueue('maintenance', null, null, ['op' => $op, 'id' => $id], $op . ' #' . $id, $op . ':' . $id);
    }

    private function claimedId(): ?int
    {
        $job = JobQueue::claimNext();
        return $job === null ? null : (int)$job['id'];
    }

    // ------------------------------------------------------------- Enfilage

    public function testLeJobPorteSaPrioriteEtSonCouloirDesLEnfilage(): void
    {
        $conv = $this->conversation();
        $cases = [
            'chat'        => [JobQueue::enqueue('chat', $this->userId, $conv, []), 10, 'conv:' . $conv],
            'image'       => [JobQueue::enqueue('image', $this->userId, $conv, []), 10, 'conv:' . $conv],
            'html'        => [JobQueue::enqueue('html', $this->userId, $conv, []), 10, 'conv:' . $conv],
            'video'       => [JobQueue::enqueue('video', $this->userId, $conv, []), 10, null],
            'programmée'  => [JobQueue::enqueue('chat', $this->userId, $conv, ['scheduled' => ['task_id' => 3, 'manual' => false]]), 50, 'conv:' . $conv],
            'maintenant'  => [JobQueue::enqueue('chat', $this->userId, $conv, ['scheduled' => ['task_id' => 3, 'manual' => true]]), 10, 'conv:' . $conv],
            'maintenance' => [$this->maintenance('kb_sync', 7), 90, 'maintenance'],
        ];
        foreach ($cases as $nom => [$id, $priorite, $couloir]) {
            $stmt = $this->db()->prepare('SELECT priority, lane FROM async_jobs WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            $this->assertSame($priorite, (int)$row['priority'], $nom);
            $this->assertSame($couloir, $row['lane'], $nom);
        }
    }

    // ------------------------------------------------------------- Priorité

    public function testUneReponseAttendueDoubleLaMaintenanceEtLesTachesProgrammees(): void
    {
        $maintenance = $this->maintenance('kb_sync', 7);
        $programmee  = JobQueue::enqueue('chat', $this->userId, $this->conversation(), ['scheduled' => ['task_id' => 1]]);
        $reponse     = JobQueue::enqueue('chat', $this->userId, $this->conversation(), []);

        $this->assertSame($reponse, $this->claimedId(), 'La personne qui attend passe la première.');
        $this->assertSame($programmee, $this->claimedId());
        $this->assertSame($maintenance, $this->claimedId());
        $this->assertNull($this->claimedId());
    }

    // -------------------------------------------------------------- Couloirs

    public function testLesToursDUneConversationPartentUnParUnEtDansLOrdre(): void
    {
        $conv  = $this->conversation();
        $autre = $this->conversation();
        $premier = JobQueue::enqueue('chat', $this->userId, $conv, []);
        $second  = JobQueue::enqueue('image', $this->userId, $conv, []);
        $ailleurs = JobQueue::enqueue('chat', $this->userId, $autre, []);
        $libre    = JobQueue::enqueue('video', $this->userId, $conv, []);

        $this->assertSame($premier, $this->claimedId());
        // Le second tour de la même conversation attend ; les autres passent.
        $this->assertSame($ailleurs, $this->claimedId());
        $this->assertSame($libre, $this->claimedId(), 'Une vidéo ne retient pas la conversation.');
        $this->assertNull($this->claimedId(), 'Le second tour ne part pas tant que le premier tourne.');
        $this->assertSame('pending', $this->jobStatus($second));

        $this->finish($premier);
        $this->assertSame($second, $this->claimedId());
    }

    public function testUnTourEnAttentePlusAncienBloqueLeSuivantDeSaConversation(): void
    {
        // Le second tour passe devant le premier dans l'ordre de réclamation
        // (priorité plus forte) : il ne doit pas le doubler pour autant. C'est
        // la même règle qui empêche deux workers réclamant en même temps de
        // lancer les deux tours d'une conversation (le premier, encore « en
        // attente » chez l'autre, bloque le second).
        $conv   = $this->conversation();
        $bloque = JobQueue::enqueue('chat', $this->userId, $conv, []);
        $suivant = JobQueue::enqueue('chat', $this->userId, $conv, []);
        $this->db()->prepare("UPDATE async_jobs SET priority = 95 WHERE id = ?")->execute([$bloque]);

        // Même relégué, le premier passe avant le second de son couloir.
        $this->assertSame($bloque, $this->claimedId());
        $this->assertNull($this->claimedId());
        $this->finish($bloque);
        $this->assertSame($suivant, $this->claimedId());
    }

    public function testUneSeuleMaintenanceALaFoisSansRetenirLesConversations(): void
    {
        $m1 = $this->maintenance('kb_sync', 1);
        $m2 = $this->maintenance('kb_reindex', 2);
        $this->assertSame($m1, $this->claimedId());

        $chat = JobQueue::enqueue('chat', $this->userId, $this->conversation(), []);
        $this->assertSame($chat, $this->claimedId(), 'Une maintenance en cours ne retient pas le chat.');
        $this->assertNull($this->claimedId(), 'Deux maintenances ne tournent jamais ensemble.');

        $this->finish($m1);
        $this->assertSame($m2, $this->claimedId());
    }

    public function testLeCheminForkRespecteAussiLeCouloir(): void
    {
        $conv    = $this->conversation();
        $premier = JobQueue::enqueue('chat', $this->userId, $conv, []);
        $second  = JobQueue::enqueue('chat', $this->userId, $conv, []);

        $this->assertNull(JobQueue::claimById($second, $bloque));
        $this->assertTrue($bloque, 'Couloir occupé : le job reste en attente.');
        $this->assertSame('pending', $this->jobStatus($second));

        $job = JobQueue::claimById($premier, $bloque);
        $this->assertSame($premier, (int)$job['id']);
        $this->assertFalse($bloque);
        $this->assertNull(JobQueue::claimById($premier, $bloque), 'Déjà pris.');
        $this->assertFalse($bloque, 'Déjà pris ≠ couloir occupé.');

        $this->finish($premier);
        $this->assertSame($second, (int)JobQueue::claimById($second)['id']);
    }

    public function testLeCheminForkAttendQueLeCouloirSeLibere(): void
    {
        $conv    = $this->conversation();
        $premier = JobQueue::enqueue('chat', $this->userId, $conv, []);
        // Un type inconnu : l'exécuter le fait échouer aussitôt — la preuve
        // qu'il a été pris, sans lancer de génération.
        $this->db()->prepare(
            "INSERT INTO async_jobs (type, user_id, conversation_id, status, priority, lane, payload_json, label)
             VALUES ('banc_type_inconnu', NULL, ?, 'pending', 10, ?, '{}', 'banc')"
        )->execute([$conv, 'conv:' . $conv]);
        $second = (int)$this->db()->lastInsertId();
        $this->assertSame($premier, (int)JobQueue::claimById($premier)['id']);

        // Le premier se termine pendant que le second attend son couloir.
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl / posix indisponibles.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('fork indisponible.');
        }
        if ($pid === 0) {
            usleep(1_500_000);
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME,
                DB_USER,
                DB_PASS
            );
            $pdo->prepare("UPDATE async_jobs SET status = 'done' WHERE id = ?")->execute([$premier]);
            posix_kill(getmypid(), SIGKILL); // sortie sans les destructeurs du parent
        }

        $t = microtime(true);
        JobQueue::runById($second);
        pcntl_waitpid($pid, $st);
        $this->assertGreaterThan(1.0, microtime(true) - $t, 'Il a attendu le premier.');
        $this->assertSame('failed', $this->jobStatus($second), 'Puis il a été pris et exécuté.');
    }

    public function testUneMaintenanceBloqueeNAttendPasDansLeCheminFork(): void
    {
        $m1 = $this->maintenance('kb_sync', 1);
        $m2 = $this->maintenance('kb_sync', 2);
        $this->assertSame($m1, (int)JobQueue::claimById($m1)['id']);

        $t = microtime(true);
        JobQueue::runById($m2);
        $this->assertLessThan(0.5, microtime(true) - $t, 'Elle rend la main aussitôt.');
        $this->assertSame('pending', $this->jobStatus($m2), 'Et reste en file pour un worker.');
    }

    // ---------------------------------------------------------------- Reaper

    public function testLeReaperNeClotJamaisDeuxFoisLeMemeJob(): void
    {
        $conv = $this->conversation();
        $rejouable = $this->maintenance('kb_sync', 4);
        $definitif = JobQueue::enqueue('chat', $this->userId, $conv, []);
        $this->db()->exec(
            "UPDATE async_jobs SET status = 'running', attempts = 1, heartbeat_at = NOW() - INTERVAL 10 MINUTE"
        );

        $this->assertSame(2, JobQueue::reapStale());
        $this->assertSame('pending', $this->jobStatus($rejouable));
        $this->assertSame('failed', $this->jobStatus($definitif));
        $this->assertSame(0, JobQueue::reapStale(), 'Plus rien d\'orphelin.');
        $this->assertCount(1, $this->assistantMessages($conv), 'Un seul message d\'échec, même relancé.');
    }

    /** @return list<string> réponses de l'assistant dans la conversation */
    private function assistantMessages(int $conv): array
    {
        $stmt = $this->db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id");
        $stmt->execute([$conv]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testUnJobPerduAvecSonWorkerLeDitDansLaConversationEtDansLeFlux(): void
    {
        $conv = $this->conversation();
        $job  = JobQueue::enqueue('chat', $this->userId, $conv, []);
        // Le worker a écrit deux évènements puis il est mort.
        $this->db()->prepare(
            "INSERT INTO job_events (job_id, seq, payload) VALUES (?, 1, '{\"phase\":\"thinking\"}'), (?, 2, '{\"chunk\":\"Bonjour\"}')"
        )->execute([$job, $job]);
        $this->db()->prepare(
            "UPDATE async_jobs SET status = 'running', attempts = 1, last_seq = 2, heartbeat_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?"
        )->execute([$job]);

        $this->assertSame(1, JobQueue::reapStale());

        // La conversation porte l'échec, titre compris (relu par chat.js).
        $messages = $this->assistantMessages($conv);
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('⚠️ Génération interrompue', $messages[0]);
        $this->assertStringContainsString('Relancez votre demande', $messages[0]);

        // Le flux reprend après le dernier évènement du worker : le navigateur
        // resté attaché lit la cause avant la fin du flux.
        $events = JobQueue::eventsSince($job, 2);
        $this->assertCount(1, $events);
        $this->assertSame(3, (int)$events[0]['seq']);
        $this->assertSame('Génération interrompue', json_decode((string)$events[0]['payload'], true)['llm_error']['title'] ?? null);
        $live = JobQueue::liveStatus($job);
        $this->assertSame('failed', $live['status']);
        $this->assertSame(3, (int)$live['last_seq'], 'Statut et évènement validés ensemble.');
        $this->assertSame('Génération interrompue', JobQueue::find($job)['error']['title'] ?? null);
    }

    public function testLeMessageDEchecSuitLaLangueDeLaPersonne(): void
    {
        $this->db()->prepare("UPDATE users SET lang = 'en' WHERE id = ?")->execute([$this->userId]);
        $conv = $this->conversation();
        $job  = JobQueue::enqueue('image', $this->userId, $conv, []);
        $this->db()->prepare(
            "UPDATE async_jobs SET status = 'running', attempts = 1, heartbeat_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?"
        )->execute([$job]);
        $avant = I18n::locale();

        $this->assertSame(1, JobQueue::reapStale());
        $this->assertStringStartsWith('⚠️ Generation stopped', $this->assistantMessages($conv)[0]);
        $this->assertSame($avant, I18n::locale(), 'La langue du process est rendue.');
    }

    public function testUnWorkerQuiSeReveilleApresCoupNAjoutePasUnSecondMessage(): void
    {
        $conv = $this->conversation();
        // Job clos par le reaper pendant que son worker était bloqué (essais
        // épuisés : un type rejouable serait remis en file)…
        $this->db()->prepare(
            "INSERT INTO async_jobs (type, user_id, conversation_id, status, priority, lane, payload_json, label, attempts, heartbeat_at)
             VALUES ('banc_type_inconnu', NULL, ?, 'running', 10, ?, '{}', 'banc', 2, NOW() - INTERVAL 10 MINUTE)"
        )->execute([$conv, 'conv:' . $conv]);
        $job = (int)$this->db()->lastInsertId();
        $this->assertSame(1, JobQueue::reapStale());
        $this->assertCount(1, $this->assistantMessages($conv));

        // … puis le worker repart et échoue à son tour : rien de plus dans le fil.
        JobQueue::execute(JobQueue::find($job));
        $this->assertCount(1, $this->assistantMessages($conv), 'Le ⚠️ du reaper suffit.');
        $this->assertSame('failed', $this->jobStatus($job));
        $this->assertCount(1, JobQueue::eventsSince($job, 0), 'Une seule erreur dans le flux.');
    }

    public function testLeReaperNeToucheQuUnJobEncoreMuet(): void
    {
        // Lu orphelin puis reparti entre la lecture et l'écriture : la
        // condition est relue au moment d'écrire.
        $job = JobQueue::enqueue('chat', $this->userId, $this->conversation(), []);
        $this->db()->prepare("UPDATE async_jobs SET status = 'running', heartbeat_at = NOW() WHERE id = ?")
            ->execute([$job]);
        $this->assertSame(0, JobQueue::reapStale());
        $this->assertSame('running', $this->jobStatus($job));
    }

    public function testUneCorveeExclusiveNeTourneQuUneFois(): void
    {
        $autre = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $autre->query("SELECT GET_LOCK('mia_banc_corvee', 0)");

        $faite = false;
        $this->assertFalse(JobQueue::exclusively('mia_banc_corvee', function () use (&$faite): void { $faite = true; }));
        $this->assertFalse($faite, 'Un autre process la fait déjà.');

        $autre->query("SELECT RELEASE_LOCK('mia_banc_corvee')");
        $this->assertTrue(JobQueue::exclusively('mia_banc_corvee', function () use (&$faite): void { $faite = true; }));
        $this->assertTrue($faite);
        // Relâché après coup, même sur exception.
        try {
            JobQueue::exclusively('mia_banc_corvee', static function (): void { throw new RuntimeException('x'); });
        } catch (RuntimeException) {
        }
        $libre = $autre->query("SELECT IS_FREE_LOCK('mia_banc_corvee')")->fetchColumn();
        $this->assertSame(1, (int)$libre);
    }

    // ------------------------------------------- Course réelle entre workers

    public function testPlusieursWorkersSeRepartissentLaFileSansJamaisEnfreindreUnCouloir(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open indisponible.');
        }

        // 5 conversations × 4 tours, 6 maintenances, 6 vidéos (sans couloir).
        $attendus = [];
        for ($c = 0; $c < 5; $c++) {
            $conv = $this->conversation();
            for ($t = 0; $t < 4; $t++) {
                $attendus[] = JobQueue::enqueue($t % 2 ? 'image' : 'chat', $this->userId, $conv, []);
            }
        }
        for ($m = 0; $m < 6; $m++) {
            $attendus[] = $this->maintenance('kb_sync', 100 + $m);
            $attendus[] = JobQueue::enqueue('video', $this->userId, $this->conversation(), []);
        }

        $env = [];
        foreach (['APP_ENV', 'APP_SECRET', 'ENCRYPTION_KEY', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'PATH'] as $k) {
            $env[$k] = (string)getenv($k);
        }
        $workers = 6;
        $procs = [];
        $outs  = [];
        for ($w = 0; $w < $workers; $w++) {
            $outs[$w] = tempnam(sys_get_temp_dir(), 'mia-claim-');
            $procs[$w] = proc_open(
                [PHP_BINARY, ROOT . '/tests/Support/claim_loop.php', '150', '40'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outs[$w], 'w'], 2 => ['file', $outs[$w] . '.err', 'w']],
                $pipes,
                ROOT,
                $env
            );
            $this->assertIsResource($procs[$w]);
        }
        foreach ($procs as $p) {
            while (proc_get_status($p)['running']) {
                usleep(50_000);
            }
            proc_close($p);
        }

        $executions = [];
        $erreurs = '';
        foreach ($outs as $f) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
                $executions[] = json_decode($ligne, true);
            }
            $erreurs .= (string)@file_get_contents($f . '.err');
            @unlink($f);
            @unlink($f . '.err');
        }
        $this->assertSame('', trim($erreurs), $erreurs);

        // Chaque job exécuté une fois, aucun oublié.
        $ids = array_column($executions, 'id');
        sort($ids);
        sort($attendus);
        $this->assertSame($attendus, $ids, 'Chaque job pris exactement une fois.');

        // Couloirs : jamais deux exécutions qui se chevauchent, ordre d'arrivée.
        $parCouloir = [];
        foreach ($executions as $e) {
            if ($e['lane'] !== null) {
                $parCouloir[$e['lane']][] = $e;
            }
        }
        $this->assertCount(6, $parCouloir, '5 conversations + la maintenance.');
        foreach ($parCouloir as $couloir => $runs) {
            usort($runs, static fn ($a, $b) => $a['start'] <=> $b['start']);
            for ($i = 1, $n = count($runs); $i < $n; $i++) {
                $this->assertGreaterThanOrEqual(
                    $runs[$i - 1]['end'],
                    $runs[$i]['start'],
                    "Couloir $couloir : deux jobs en même temps (#{$runs[$i - 1]['id']} et #{$runs[$i]['id']})."
                );
                $this->assertGreaterThan($runs[$i - 1]['id'], $runs[$i]['id'], "Couloir $couloir : ordre d'arrivée non respecté.");
            }
        }

        // Le parallélisme a réellement eu lieu : plusieurs jobs en même temps
        // (balayage des débuts et fins), sur bien moins que la durée en série.
        $this->assertGreaterThan(1, count(array_unique(array_column($executions, 'pid'))));
        $bornes = [];
        foreach ($executions as $e) {
            $bornes[] = [$e['start'], 1];
            $bornes[] = [$e['end'], -1];
        }
        usort($bornes, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $enCours = 0;
        $pic = 0;
        foreach ($bornes as [, $delta]) {
            $enCours += $delta;
            $pic = max($pic, $enCours);
        }
        $this->assertGreaterThanOrEqual(4, $pic, 'Pas plus de ' . $pic . ' job(s) à la fois avec 6 workers.');
        $serie = array_sum(array_map(static fn ($e) => $e['end'] - $e['start'], $executions));
        $etendue = max(array_column($executions, 'end')) - min(array_column($executions, 'start'));
        $this->assertLessThan($serie / 2, $etendue, 'Les workers n\'ont pas travaillé en parallèle.');
    }
}
