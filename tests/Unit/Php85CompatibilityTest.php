<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Passage à PHP 8.5 (image Docker et CI) sans « Obsolète » au journal.
 *
 * L'application journalise E_DEPRECATED (ErrorHandler pose E_ALL et écrit
 * « [Obsolète] … @ fichier:ligne ») : sous 8.5, chaque connexion à la base
 * écrivait une ligne pour `PDO::MYSQL_ATTR_INIT_COMMAND`, chaque appel HTTP
 * une pour `curl_close()`, chaque image une pour `imagedestroy()`. Ces appels
 * sont sans effet depuis PHP 8.0/8.1 : ils ont été retirés, et ce test empêche
 * qu'ils reviennent — sur le CODE seul (les commentaires peuvent les citer).
 *
 * Le plancher reste PHP 8.3 (composer.json, Requirements::PHP_MINIMUM) : rien
 * ici n'exige une fonction 8.4+ sans repli.
 */
final class Php85CompatibilityTest extends TestCase
{
    /** Fonctions dépréciées en 8.5, sans effet depuis 8.0/8.1. */
    private const FONCTIONS_DEPRECIEES = ['curl_close', 'imagedestroy', 'finfo_close', 'xml_parser_free', 'curl_share_close'];

    /** Méthodes dépréciées en 8.5, sans effet depuis 8.1. */
    private const METHODES_DEPRECIEES = ['setAccessible'];

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    /** @return iterable<string, string> chemin relatif => source */
    private static function sourcesPhp(): iterable
    {
        foreach (['src', 'scripts', 'public', 'config', 'templates', 'tests'] as $dossier) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/' . $dossier, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $chemin = substr($f->getPathname(), strlen(ROOT) + 1);
                    yield $chemin => (string)file_get_contents($f->getPathname());
                }
            }
        }
    }

    /** Jetons significatifs (sans blancs ni commentaires). @return list<array{0:int,1:string}|string> */
    private static function jetons(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    public function testAucunAppelDeprecieEnPhp85(): void
    {
        $fautes = [];
        foreach (self::sourcesPhp() as $chemin => $source) {
            $j = self::jetons($source);
            foreach ($j as $i => $t) {
                if (!is_array($t) || $t[0] !== T_STRING) {
                    continue;
                }
                $avant  = $j[$i - 1] ?? null;
                $apres  = $j[$i + 1] ?? null;
                $appel  = $apres === '(';
                $objet  = is_array($avant) && in_array($avant[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
                $statiq = is_array($avant) && $avant[0] === T_DOUBLE_COLON;
                $defini = is_array($avant) && $avant[0] === T_FUNCTION;
                $nom    = strtolower($t[1]);

                if ($appel && !$objet && !$statiq && !$defini && in_array($nom, self::FONCTIONS_DEPRECIEES, true)) {
                    $fautes[] = "$chemin:{$t[2]} {$t[1]}()";
                }
                if ($appel && $objet && in_array($t[1], self::METHODES_DEPRECIEES, true)) {
                    $fautes[] = "$chemin:{$t[2]} ->{$t[1]}()";
                }
                // PDO::MYSQL_ATTR_* : dépréciées au profit de Pdo\Mysql::ATTR_*.
                if ($t[1] === 'PDO' && is_array($apres) && $apres[0] === T_DOUBLE_COLON) {
                    $cst = $j[$i + 2] ?? null;
                    if (is_array($cst) && str_starts_with($cst[1], 'MYSQL_ATTR_')) {
                        $fautes[] = "$chemin:{$t[2]} PDO::{$cst[1]}";
                    }
                }
            }
        }
        $this->assertSame([], $fautes, "Dépréciés en PHP 8.5 :\n" . implode("\n", $fautes));
    }

    /**
     * `$http_response_header` : seule une lecture NUE est dépréciée en 8.5
     * (signalée à la compilation du fichier) ; `$http_response_header ?? []`
     * et `isset(...)` ne le sont pas — vérifié sur PHP 8.5.12. Les 49 lectures
     * du dépôt passent par `??` ; `http_get_last_response_headers()` (8.4+)
     * attendra que le plancher dépasse 8.3.
     */
    public function testAucuneLectureNueDeHttpResponseHeader(): void
    {
        $fautes = [];
        foreach (self::sourcesPhp() as $chemin => $source) {
            if (!str_contains($source, '$http_response_header')) {
                continue;
            }
            $j = self::jetons($source);
            foreach ($j as $i => $t) {
                if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$http_response_header') {
                    continue;
                }
                $k = $i + 1;
                if (($j[$k] ?? null) === '[') {           // $http_response_header[0] ?? …
                    for ($d = 0; $k < count($j); $k++) {
                        if ($j[$k] === '[') {
                            $d++;
                        } elseif ($j[$k] === ']' && --$d === 0) {
                            $k++;
                            break;
                        }
                    }
                }
                $suivant = $j[$k] ?? null;
                $coalesce = is_array($suivant) && $suivant[0] === T_COALESCE;
                $isset    = is_array($j[$i - 2] ?? null) && $j[$i - 2][0] === T_ISSET && ($j[$i - 1] ?? null) === '(';
                if (!$coalesce && !$isset) {
                    $fautes[] = "$chemin:{$t[2]}";
                }
            }
        }
        $this->assertSame([], $fautes, "Lecture nue de \$http_response_header (dépréciée en 8.5) :\n" . implode("\n", $fautes));
    }

    public function testLEchangeDeClesWebPushNeFixePasDeLongueur(): void
    {
        // Le 3e argument d'openssl_pkey_derive() est déprécié en 8.5 : P-256
        // donne 32 octets d'office, et WebPush le vérifie (strlen).
        foreach (['src/Push/WebPush.php', 'tests/Functional/PushNotificationsPipelineTest.php'] as $f) {
            $this->assertDoesNotMatchRegularExpression('/openssl_pkey_derive\([^;]*,[^;]*,[^;]*\);/', self::lire($f), $f);
        }
        $this->assertStringContainsString('strlen($ecdh) !== 32', self::lire('src/Push/WebPush.php'));
    }

    public function testLaConnexionLitLAttributMysqlSansDeprecation(): void
    {
        $source = self::lire('src/Models/Database.php');
        $this->assertStringContainsString('self::initCommandAttribute() =>', $source);
        $this->assertStringContainsString("defined('Pdo\\Mysql::ATTR_INIT_COMMAND')", $source);

        // Même valeur, quelle que soit la version : le repli 8.3 ne change rien.
        $m = new ReflectionMethod(Database::class, 'initCommandAttribute');
        $this->assertSame(1002, $m->invoke(null));
    }

    public function testLImageEtLaCiSontEnPhp85(): void
    {
        $docker = self::lire('Dockerfile');
        $this->assertMatchesRegularExpression('/^FROM php:8\.5(?:\.\d+)?-apache@sha256:[0-9a-f]{64}$/m', $docker,
            'Base épinglée par empreinte (cache de couches stable).');
        // OPcache est compilé dans PHP depuis 8.5 : l'installer fait échouer
        // la construction (« cannot stat modules/* »).
        $install = substr($docker, (int)strpos($docker, 'docker-php-ext-install'), 400);
        $this->assertStringNotContainsString('opcache', strtok($install, '&') ?: $install);

        $ci = self::lire('.github/workflows/tests.yml');
        $this->assertStringContainsString("php-version: '8.5'", $ci);
    }

    public function testLePlancherResteEnPhp83(): void
    {
        require_once ROOT . '/src/Helpers/Requirements.php';
        $composer = json_decode(self::lire('composer.json'), true);
        $this->assertSame('>=8.3', $composer['require']['php'] ?? null);
        $this->assertSame('8.3.0', Requirements::PHP_MINIMUM);
    }
}
