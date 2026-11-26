<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * La suite de tests publiée doit passer sur le dépôt public.
 *
 * Le dépôt public (MyAlfred-ai/mia) ne reçoit ni le guide interne (CLAUDE.md),
 * ni l'outillage de publication (le dossier `publication/`,
 * `.github/publication/`). Un test qui les lit échoue là-bas : la release
 * publique s'arrête alors à la CI, sans image ni kit — constaté à la
 * publication de la 1.5.4, quinze tests récents avaient oublié la règle.
 *
 * La règle : un test qui lit un fichier privé porte
 * `#[\PHPUnit\Framework\Attributes\Group('doc-interne')]` (méthode ou classe),
 * groupe exclu par le phpunit.xml du public ; ou son fichier est retiré de
 * l'export par la liste blanche (`!tests/...`). Ce test le vérifie ici, dans
 * le dépôt privé, avant qu'une publication ne le découvre.
 */
final class PublicTestSuiteTest extends TestCase
{
    /** Chaînes qui désignent un fichier absent du dépôt public. */
    private const PRIVATE_PATHS = ['CLAUDE.md', 'publication/'];

    private const GROUP = "Group('doc-interne')";

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Fichiers de tests retirés de l'export par la liste blanche. */
    private static function excludedTests(): array
    {
        $liste = self::root() . '/publication/liste-blanche.txt';
        if (!is_file($liste)) {
            return []; // dépôt public : les fichiers exclus n'y sont pas.
        }
        $out = [];
        foreach (file($liste, FILE_IGNORE_NEW_LINES) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if (str_starts_with($ligne, '!tests/')) {
                $out[] = substr($ligne, 1);
            }
        }
        return $out;
    }

    private static function isExcluded(string $relative, array $excluded): bool
    {
        foreach ($excluded as $motif) {
            if (fnmatch($motif, $relative)) {
                return true;
            }
        }
        return false;
    }

    private static function namesPrivatePath(string $literal): bool
    {
        foreach (self::PRIVATE_PATHS as $path) {
            if (str_contains($literal, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Méthodes d'un fichier qui citent un fichier privé sans porter le groupe.
     * Lecture par jetons : un commentaire qui cite CLAUDE.md ne compte pas.
     *
     * @return list<string>
     */
    public static function offenders(string $code): array
    {
        $tokens   = token_get_all($code);
        $depth    = 0;
        $preamble = '';      // texte depuis la fin de la déclaration précédente
        $classOk  = false;   // groupe posé sur la classe
        $inClass  = false;
        $method   = null;    // ['name' => …, 'ok' => bool, 'depth' => int, 'hit' => bool]
        $out      = [];

        foreach ($tokens as $i => $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            $id   = is_array($tok) ? $tok[0] : null;

            if ($id === T_CLASS && !$inClass) {
                $classOk = str_contains($preamble, self::GROUP);
                $inClass = true;
            }
            if ($id === T_FUNCTION && $inClass && $method === null && $depth === 1) {
                $name = '';
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $name = $tokens[$j][1];
                        break;
                    }
                }
                $method = ['name' => $name, 'ok' => $classOk || str_contains($preamble, self::GROUP), 'depth' => null, 'hit' => false];
            }
            if ($method !== null && $id === T_CONSTANT_ENCAPSED_STRING && self::namesPrivatePath($text)) {
                $method['hit'] = true;
            }

            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                if ($method !== null && $method['depth'] === null && $text === '{') {
                    $method['depth'] = $depth;
                }
            } elseif ($text === '}') {
                if ($method !== null && $method['depth'] === $depth) {
                    if ($method['hit'] && !$method['ok']) {
                        $out[] = $method['name'];
                    }
                    $method = null;
                    $preamble = '';
                    $depth--;
                    continue;
                }
                $depth--;
            } elseif ($text === ';' && $method !== null && $method['depth'] === null) {
                $method = null; // méthode abstraite ou d'interface
            }

            if ($method === null) {
                $preamble = ($text === ';' || ($text === '{' && $depth === 1)) ? '' : $preamble . $text;
            }
        }
        return $out;
    }

    public function testLaDetectionReconnaitUneMethodeSansLeGroupe(): void
    {
        $code = <<<'PHP'
        <?php
        final class X extends TestCase
        {
            // CLAUDE.md cité en commentaire : sans effet.
            public function testDoc(): void { $a = self::lire('CLAUDE.md'); if ($a) { $b = "{$a}"; } }

            #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
            public function testDocInterne(): void { self::lire('CLAUDE.md'); }

            public function testPublic(): void { self::lire('docs/GUIDE-ADMIN.md'); }

            public function testListe(): void { file('publication/liste-blanche.txt'); }
        }
        PHP;
        $this->assertSame(['testDoc', 'testListe'], self::offenders($code));

        $classe = "<?php\n#[\\PHPUnit\\Framework\\Attributes\\Group('doc-interne')]\nfinal class Y { public function testA(): void { self::lire('CLAUDE.md'); } }";
        $this->assertSame([], self::offenders($classe), 'Un groupe posé sur la classe vaut pour toutes ses méthodes.');
    }

    public function testAucunTestPublieNeLitUnFichierPrive(): void
    {
        $excluded = self::excludedTests();
        $fautes   = [];
        foreach (['tests/Unit', 'tests/Functional'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = $dir . substr($file->getPathname(), strlen(self::root() . '/' . $dir));
                if (self::isExcluded($relative, $excluded)) {
                    continue;
                }
                foreach (self::offenders((string)file_get_contents($file->getPathname())) as $method) {
                    $fautes[] = $relative . ' :: ' . $method;
                }
            }
        }
        sort($fautes);
        $this->assertSame([], $fautes,
            "Ces tests lisent un fichier absent du dépôt public : la release publique échouerait.\n"
            . "Mettre l'assertion dans une méthode #[\\PHPUnit\\Framework\\Attributes\\Group('doc-interne')].");
    }
}
