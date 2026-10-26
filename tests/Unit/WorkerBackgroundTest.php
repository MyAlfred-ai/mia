<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Le worker travaille SEUL, sans page ouverte : c'est lui qui lance les tâches
 * programmées et exécute les jobs. Le comportement est prouvé par
 * tests/Functional/WorkerLoopTest.php (vrai script, vraie base) ; ici, la
 * règle de version (pure) et le câblage qui l'entoure.
 */
final class WorkerBackgroundTest extends TestCase
{
    private static function src(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    public function testUnWorkerNEstPerimeQueFaceAUneVersionPlusRecente(): void
    {
        // Image ancienne restée en place : périmé.
        $this->assertTrue(JobQueue::isOutdated('1.3.1.12', '1.3.1.11'));
        $this->assertTrue(JobQueue::isOutdated('1.3.2.0', '1.3.1.40'));
        // Juste après un déploiement, l'app n'a encore rien servi : la version
        // publiée est l'ancienne, le worker (neuf) est À JOUR.
        $this->assertFalse(JobQueue::isOutdated('1.3.1.10', '1.3.1.11'));
        $this->assertFalse(JobQueue::isOutdated('1.3.1.11', '1.3.1.11'));
        // Pas d'avis sans version.
        $this->assertFalse(JobQueue::isOutdated('', '1.3.1.11'));
        $this->assertFalse(JobQueue::isOutdated('1.3.1.11', ''));
        // Versions non numériques (développement) : la différence suffit.
        $this->assertTrue(JobQueue::isOutdated('autre-version', '1.3.1.11'));
    }

    public function testLHeureDeDemarrageDuWorkerNEstEcriteQuUneFois(): void
    {
        $code = self::src('scripts/worker.php');
        $this->assertSame(1, preg_match_all('/\$processStartedAt\s*=(?!=)/', $code), "L'heure de démarrage du process ne doit être écrite qu'une fois.");
        $this->assertMatchesRegularExpression('/\(\$now - \$processStartedAt\) >= MAX_LIFETIME/', $code);
        $this->assertStringContainsString('$launched = Scheduler::tick();', $code);
        $this->assertDoesNotMatchRegularExpression('/\$started\s*=/', $code, 'Nom ambigu, réécrit une fois déjà : le worker sortait dès son premier tour.');
    }

    public function testLAppPublieSaVersionDesLeDemarrageDeSonConteneur(): void
    {
        $sh = self::src('docker/entrypoint.sh');
        $publie = strpos($sh, 'scripts/publish_version.php');
        $worker = strpos($sh, 'exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"');
        $apache = strpos($sh, 'exec apache2-foreground');
        $this->assertNotFalse($publie, "L'entrypoint ne publie pas la version.");
        $this->assertGreaterThan($worker, $publie, 'Branche Apache seulement : le worker ne publie pas la version de l’app.');
        $this->assertLessThan($apache, $publie);
        $this->assertMatchesRegularExpression('#php /var/www/html/scripts/publish_version\.php \|\| true#', $sh, 'Jamais bloquant pour Apache.');

        $script = self::src('scripts/publish_version.php');
        $this->assertStringContainsString('JobQueue::publishCodeVersion();', $script);
        $this->assertStringContainsString('exit(0);', $script);
    }

    public function testLaSondeDeSanteDitUnWorkerPerime(): void
    {
        $this->assertStringContainsString('JobQueue::workerAlive() && !JobQueue::workerOutdated()', self::src('scripts/worker-health.php'));
    }
}
