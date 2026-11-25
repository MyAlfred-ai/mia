<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sessions qui survivent aux redéploiements et à l'usage quotidien.
 *
 * Deux défauts corrigés, constatés chez un client (« MIA redemande de se
 * connecter à chaque mise à jour ») :
 *  1. les sessions PHP vivaient dans /tmp du conteneur (session.save_path vide
 *     dans l'image) — recréer le conteneur au déploiement les effaçait toutes ;
 *  2. PHP ne renvoie le cookie de session qu'à sa création : il expirait à
 *     date fixe après la connexion, même pour qui s'en servait tous les jours.
 */
final class SessionPersistenceTest extends TestCase
{
    private string $iniAvant = '';
    private ?string $dossierTemp = null;

    protected function setUp(): void
    {
        $this->iniAvant = (string) ini_get('session.save_path');
    }

    protected function tearDown(): void
    {
        ini_set('session.save_path', $this->iniAvant);
        if ($this->dossierTemp !== null && is_dir($this->dossierTemp)) {
            @chmod($this->dossierTemp, 0700);
            foreach (glob($this->dossierTemp . '/*') ?: [] as $f) {
                @chmod($f, 0700);
                @rmdir($f);
                @unlink($f);
            }
            @rmdir($this->dossierTemp);
        }
    }

    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string) file_get_contents($abs);
    }

    private function dossierTemporaire(): string
    {
        $base = sys_get_temp_dir() . '/mia-sessions-' . bin2hex(random_bytes(6));
        $this->dossierTemp = $base;
        return $base;
    }

    // ------------------------------------------------------------------
    // Où vivent les sessions
    // ------------------------------------------------------------------

    public function testLesSessionsVontDansStorageSessions(): void
    {
        $this->assertSame(
            realpath(ROOT) . '/storage/sessions',
            Session::savePath(),
            'Les sessions doivent vivre dans storage/sessions, monté sur un volume.'
        );
    }

    public function testLeDossierEstCreePriveEtRetenu(): void
    {
        $base = $this->dossierTemporaire();
        $dossier = $base . '/sessions';
        mkdir($base, 0700);

        $this->assertTrue(Session::useSaveDir($dossier));
        $this->assertDirectoryExists($dossier);
        $this->assertSame($dossier, ini_get('session.save_path'));
        // Pas de lecture par les autres comptes de la machine : une session
        // porte l'identité de la personne connectée.
        $this->assertSame('0', substr(sprintf('%o', fileperms($dossier)), -1),
            'Le dossier des sessions ne doit pas être lisible par tous.');
    }

    public function testUnDossierInutilisableLaisseLeReglageEnPlace(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root écrit partout : le refus ne se constate pas.');
        }
        $base = $this->dossierTemporaire();
        mkdir($base, 0500);
        ini_set('session.save_path', '/chemin/avant');

        $this->assertFalse(Session::useSaveDir($base . '/sessions'));
        $this->assertSame('/chemin/avant', ini_get('session.save_path'),
            'Sans dossier inscriptible, PHP garde son réglage : une session vaut mieux qu\'aucune.');
    }

    // ------------------------------------------------------------------
    // Durée : glissante, 30 jours d'inactivité
    // ------------------------------------------------------------------

    public function testLaDureeEstDeTrenteJoursDInactivite(): void
    {
        $this->assertSame(30, Session::LIFETIME_DAYS);
        $this->assertSame(30 * 86400, Session::lifetimeSeconds());
    }

    public function testLeCookieEstRenvoyeAuPlusUneFoisParJour(): void
    {
        $maintenant = 1_800_000_000;
        $this->assertTrue(Session::cookieNeedsRefresh(null, $maintenant), 'Jamais renvoyé : on le renvoie.');
        $this->assertFalse(Session::cookieNeedsRefresh($maintenant - 3600, $maintenant), 'Renvoyé il y a une heure : rien.');
        $this->assertFalse(Session::cookieNeedsRefresh($maintenant - 86399, $maintenant));
        $this->assertTrue(Session::cookieNeedsRefresh($maintenant - 86400, $maintenant), 'Un jour plus tard : renvoyé.');
        $this->assertTrue(Session::cookieNeedsRefresh($maintenant + 600, $maintenant), 'Horloge revenue en arrière : renvoyé.');
    }

    public function testLeCookieNestProlongeQuePourUnePersonneConnectee(): void
    {
        $src = self::lire('src/Auth/Session.php');
        $this->assertStringNotContainsString('86400 * 7', $src, 'L\'ancienne durée fixe de 7 jours doit avoir disparu.');
        $this->assertMatchesRegularExpression('/function refreshCookie\(\)/', $src);
        $corps = substr($src, (int) strpos($src, 'function refreshCookie()'));
        $corps = substr($corps, 0, (int) strpos($corps, "\n    }\n") ?: strlen($corps));
        $this->assertStringContainsString("isset(\$_SESSION['user'])", $corps,
            'Une visite anonyme (robot, page de connexion) ne doit rien écrire de plus.');
        $this->assertStringContainsString('headers_sent()', $corps);
        $this->assertStringContainsString('setcookie(', $corps);
        // Le renvoi est appelé par start(), après l'ouverture de la session.
        $start = substr($src, (int) strpos($src, 'public static function start()'));
        $this->assertStringContainsString('self::refreshCookie()', $start);
        $this->assertStringContainsString('self::useSaveDir(', $start);
    }

    public function testLeRamasseMiettesPhpSuitLaMemeDuree(): void
    {
        foreach (['php-env.ini', 'kit/php-env.ini'] as $fichier) {
            $this->assertMatchesRegularExpression(
                '/^session\.gc_maxlifetime\s*=\s*2592000\s*$/m',
                self::lire($fichier),
                "$fichier : session.gc_maxlifetime doit valoir 30 jours (2592000 s)."
            );
        }
    }

    // ------------------------------------------------------------------
    // Volume Docker et image
    // ------------------------------------------------------------------

    public function testLeVolumeDesSessionsEstMonteSurLApp(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.prod.yml', 'kit/docker-compose.yml'] as $fichier) {
            $compose = self::lire($fichier);
            $this->assertStringContainsString(
                'storage_sessions:/var/www/html/storage/sessions',
                $compose,
                "$fichier : sessions non montées sur un volume — tout le monde est déconnecté à chaque déploiement."
            );
            $this->assertMatchesRegularExpression(
                '/^\s*storage_sessions:\s*$/m',
                $compose,
                "$fichier : storage_sessions non déclaré dans les volumes."
            );
        }
    }

    public function testLImageEtLeDemarragePreparentLeDossier(): void
    {
        $this->assertMatchesRegularExpression('/^\s+storage\/sessions \\\\$/m', self::lire('Dockerfile'),
            'Le Dockerfile doit créer storage/sessions (sinon le volume neuf naît en root).');

        $entrypoint = self::lire('docker/entrypoint.sh');
        $this->assertStringContainsString('/var/www/html/storage/sessions', $entrypoint,
            'L\'entrypoint doit rendre storage/sessions inscriptible par www-data.');
        $this->assertMatchesRegularExpression('/chmod 770 "?\/var\/www\/html\/storage\/sessions"?/', $entrypoint);
    }

    public function testLesSessionsLocalesNeSontNiVersionneesNiCopieesDansLImage(): void
    {
        $this->assertMatchesRegularExpression('/^storage\/sessions\/$/m', self::lire('.gitignore'));
        $this->assertMatchesRegularExpression('/^storage\/sessions\/$/m', self::lire('.dockerignore'),
            'Des sessions de développement copiées dans l\'image seraient des sessions valides en production.');
    }
}
