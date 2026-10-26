<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Le VRAI worker (scripts/worker.php) tourne en tâche de fond, sans personne
 * devant l'écran : il tient plusieurs tours, bat son cœur et réclame les jobs.
 *
 * Deux pannes vécues le 2026-10-01, toutes deux invisibles à la lecture et
 * toutes deux réparées par… l'ouverture d'une page (le repli de l'app) — d'où
 * le symptôme « les tâches programmées ne tournent que si j'ouvre MIA » :
 *
 *  1. l'heure de démarrage du process était réécrite par le nombre de tâches
 *     lancées (0) : le worker se recyclait à son PREMIER tour, avant de
 *     réclamer quoi que ce soit, et Docker le relançait en boucle ;
 *  2. juste après un déploiement, la version publiée par l'app est encore
 *     l'ANCIENNE tant qu'aucune page n'a été servie : comparée à l'égalité,
 *     elle faisait croire le worker (neuf) périmé, et il cessait de réclamer.
 *
 * Le test lance le script comme Docker le fait, contre la base de test, avec
 * une version publiée PLUS ANCIENNE que la sienne, et un job en attente.
 */
final class WorkerLoopTest extends DatabaseTestCase
{
    private const KEYS = ['job_worker_heartbeat_at', 'app_code_version'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetKeys();
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->forgetKeys();
        }
        parent::tearDown();
    }

    private function forgetKeys(): void
    {
        $in = implode(',', array_fill(0, count(self::KEYS), '?'));
        $this->db()->prepare("DELETE FROM settings WHERE `key` IN ($in)")->execute(self::KEYS);
        Settings::refresh();
    }

    public function testLeWorkerTientPlusieursToursEtReclameLesJobsJusteApresUnDeploiement(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open indisponible.');
        }

        // Juste après un déploiement : l'app n'a encore rien servi, la version
        // publiée est celle d'avant (plus ancienne que celle du worker).
        Settings::set('app_code_version', '0.0.1');
        // Un job en attente, d'un type que personne ne sait traiter : le
        // réclamer suffit à le faire passer en échec — preuve qu'il a été pris.
        $this->db()->prepare(
            "INSERT INTO async_jobs (type, user_id, conversation_id, status, payload_json, label)
             VALUES ('banc_type_inconnu', NULL, NULL, 'pending', '{}', 'banc')"
        )->execute();
        $jobId = (int)$this->db()->lastInsertId();

        $env = [];
        foreach (['APP_ENV', 'APP_SECRET', 'ENCRYPTION_KEY', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'PATH'] as $k) {
            $env[$k] = (string)getenv($k);
        }
        $out = tempnam(sys_get_temp_dir(), 'mia-worker-');
        $proc = proc_open(
            [PHP_BINARY, ROOT . '/scripts/worker.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
            $pipes,
            ROOT,
            $env
        );
        $this->assertIsResource($proc, 'Impossible de lancer le worker.');

        try {
            // Plusieurs tours de boucle (0,7 s à vide) — et le job pris.
            $deadline = microtime(true) + 12.0;
            $status = 'pending';
            while (microtime(true) < $deadline) {
                usleep(300_000);
                $stmt = $this->db()->prepare('SELECT status FROM async_jobs WHERE id = ?');
                $stmt->execute([$jobId]);
                $status = (string)$stmt->fetchColumn();
                if ($status !== 'pending' && $status !== 'running') {
                    break;
                }
            }
            usleep(1_600_000);
            $running = proc_get_status($proc)['running'];
        } finally {
            proc_terminate($proc, 15);
            $wait = microtime(true) + 5.0;
            while (proc_get_status($proc)['running'] && microtime(true) < $wait) {
                usleep(100_000);
            }
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
            }
            proc_close($proc);
        }
        $log = (string)file_get_contents($out);
        @unlink($out);

        $this->assertStringContainsString('[worker] démarré', $log, $log);
        $this->assertStringNotContainsString('recyclage du process', $log, "Le worker s'est recyclé aussitôt démarré :\n" . $log);
        $this->assertTrue($running, "Le worker s'est arrêté seul en quelques secondes :\n" . $log);
        $this->assertSame('failed', $status, "Le job en attente n'a pas été réclamé (version publiée plus ancienne = worker à jour) :\n" . $log);

        Settings::refresh();
        $this->assertNotSame('', (string)Settings::get('job_worker_heartbeat_at', ''), 'Aucun battement de cœur écrit.');
    }
}
