<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Typographie : câblage des deux polices de la marque (aucune base).
 *
 * `--font` (Lexend) habille TOUTE l'interface, `--font-title` (Congenial,
 * repli Lexend) le seul nom du produit. Les deux vivent dans `:root` de
 * `main.css` — les redéclarer ailleurs ferait dériver l'un des deux.
 *
 * Deux régressions sont gardées ici en particulier :
 *  - un `@font-face` qui pointerait un fichier absent du dépôt : toute
 *    l'interface retomberait sur la police système, sans une ligne de log ;
 *  - un `@import`/`<link>` vers fonts.googleapis.com : en prod le réseau
 *    `myllm` est `internal: true` (aucune sortie) et la police resterait
 *    muette, alors qu'elle s'affiche parfaitement en développement.
 */
final class TypographyTest extends TestCase
{
    private const FONT_DIR = ROOT . '/public/assets/fonts';

    private static function css(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/css/main.css');
    }

    /** Les commentaires portent des virgules : les laisser fausserait toute lecture de sélecteurs. */
    private static function cssSansCommentaires(): string
    {
        return (string)preg_replace('#/\*.*?\*/#s', '', self::css());
    }

    public function testLesDeuxJetonsSontDeclaresUneSeuleFoisDansRoot(): void
    {
        $css = self::css();

        foreach (['--font', '--font-title'] as $jeton) {
            $this->assertSame(
                1,
                preg_match_all('/^\s*' . preg_quote($jeton, '/') . '\s*:/m', $css),
                "« $jeton » doit être déclaré exactement une fois : c'est la source unique."
            );
        }

        $this->assertMatchesRegularExpression(
            "/--font\s*:\s*'Lexend'/",
            $css,
            "L'interface entière est composée en Lexend."
        );
        $this->assertMatchesRegularExpression(
            "/--font-title\s*:\s*'Congenial',\s*'Lexend'/",
            $css,
            'Congenial en tête, Lexend juste derrière : sans ce repli, le nom du '
            . "produit retomberait sur la police système tant que la police sous licence n'est pas déposée."
        );
    }

    public function testSeulLeNomDuProduitPorteLaPoliceDeMarque(): void
    {
        $css = self::cssSansCommentaires();

        // Le bloc qui applique --font-title, quel qu'il soit.
        $this->assertSame(
            1,
            preg_match('/([^{}]*)\{[^{}]*font-family:\s*var\(--font-title\)/', $css, $m),
            'Un seul bloc doit appliquer --font-title.'
        );
        $selecteurs = array_map('trim', explode(',', trim($m[1])));
        sort($selecteurs);

        $this->assertSame(
            ['.login-box h1', '.logo-text', '.mobile-header-title'],
            $selecteurs,
            'Les trois surfaces qui affichent `Settings::get(\'app_name\')` — et elles seules. '
            . 'La police de marque signale le produit, elle ne hiérarchise pas le contenu.'
        );
    }

    /**
     * Le wordmark est composé en Medium (500), jamais en Bold : c'est la spec
     * MyAlfred, celle que MyBrief applique déjà (`.app-logo-text` /
     * `.sb-wordmark`). Et la graisse ne doit vivre QU'AU bloc commun — une
     * redéclaration plus bas dans le fichier l'emporterait (même spécificité,
     * règle plus tardive) et ferait diverger l'un des trois écrans.
     */
    public function testLeWordmarkEstEnMediumEtSaGraisseNEstDeclareeQuUneFois(): void
    {
        $css = self::cssSansCommentaires();

        $this->assertSame(
            1,
            preg_match('/[^{}]*\{[^{}]*font-family:\s*var\(--font-title\)[^{}]*\}/', $css, $m),
            'Bloc de la police de marque introuvable.'
        );
        $this->assertMatchesRegularExpression('/font-weight:\s*500\s*;/', $m[0], 'Congenial Medium, pas Bold.');
        $this->assertMatchesRegularExpression('/letter-spacing:\s*-0\.3px\s*;/', $m[0]);

        // Aucun autre bloc visant l'une des trois surfaces ne redéclare la graisse.
        preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $blocs, PREG_SET_ORDER);
        foreach (['.logo-text', '.mobile-header-title', '.login-box h1'] as $sel) {
            $porteurs = array_filter($blocs, static function (array $b) use ($sel): bool {
                $selecteurs = array_map('trim', explode(',', trim($b[1])));
                return in_array($sel, $selecteurs, true)
                    && preg_match('/font-weight\s*:|letter-spacing\s*:/', $b[2]) === 1;
            });
            $this->assertCount(
                1,
                $porteurs,
                "« $sel » : graisse/approche déclarées ailleurs qu'au bloc commun — la dernière règle gagnerait."
            );
        }
    }

    public function testChaqueFontFaceVersionneePointeUnFichierPresent(): void
    {
        preg_match_all("#url\('(/assets/fonts/[^']+)'\)#", self::css(), $m);
        $this->assertNotEmpty($m[1], 'Aucune police auto-hébergée déclarée.');

        foreach ($m[1] as $url) {
            $fichier = ROOT . '/public' . $url;
            $nom     = basename($url);

            // Congenial est sous licence commerciale : absente du dépôt par
            // construction (cf. .gitignore). Son absence est le cas nominal.
            if (str_starts_with($nom, 'congenial-')) {
                continue;
            }

            $this->assertFileExists($fichier, "« $url » est déclaré en CSS mais absent du dépôt.");
            $this->assertSame(
                'wOF2',
                (string)file_get_contents($fichier, false, null, 0, 4),
                "« $nom » n'est pas un woff2 valide."
            );
        }
    }

    public function testCongenialEstAttendueMaisJamaisVersionnee(): void
    {
        $css = self::css();
        foreach (['congenial-regular.woff2', 'congenial-bold.woff2'] as $nom) {
            $this->assertStringContainsString($nom, $css, "Le @font-face de « $nom » doit préexister : déposer le fichier suffit à l'activer.");
            $this->assertFileDoesNotExist(self::FONT_DIR . '/' . $nom, 'Police commerciale : jamais dans le dépôt.');
        }

        $this->assertStringContainsString(
            'public/assets/fonts/congenial-',
            (string)file_get_contents(ROOT . '/.gitignore'),
            'Sans règle .gitignore, un `git add -A` embarquerait la police achetée dans le dépôt.'
        );
    }

    public function testAucunePoliceNEstChargeeDepuisUnCdn(): void
    {
        $sources = [ROOT . '/public/assets/css/main.css', ROOT . '/public/assets/css/deck.css'];
        // Récursif : `glob('templates/**/*.php')` ne descend que d'UN niveau
        // (le `**` de PHP n'est qu'un `*`) et manquerait templates/admin/llms/.
        $arbre = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/templates'));
        foreach ($arbre as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $sources[] = $f->getPathname();
            }
        }
        $this->assertGreaterThan(40, count($sources), 'Le balayage des gabarits n\'a presque rien trouvé.');

        // On cherche une RÉFÉRENCE (donc un schéma ou un `//`), pas la simple
        // mention du domaine : le commentaire de main.css explique justement
        // pourquoi on ne l'utilise pas.
        foreach ($sources as $f) {
            $this->assertDoesNotMatchRegularExpression(
                '#(?:https?:)?//fonts\.(?:googleapis|gstatic)\.com#',
                (string)file_get_contents($f),
                basename($f) . ' : les polices sont servies par l\'app (réseau `myllm` sans sortie en prod).'
            );
        }
    }

    public function testLesPolicesSontServiesAvecLeurTypeEtUnCacheLong(): void
    {
        $htaccess = (string)file_get_contents(ROOT . '/public/.htaccess');

        $this->assertStringContainsString('AddType font/woff2 .woff2', $htaccess);
        $this->assertMatchesRegularExpression(
            '/FilesMatch "\\\\\.woff2\$".*?Cache-Control "public, max-age=31536000, immutable"/s',
            $htaccess,
            'Une police n\'a pas de `?v=` : sans cache long, chaque navigation revalide et le texte clignote.'
        );
    }

    public function testLaLicenceOuverteDeLexendEstLivree(): void
    {
        $ofl = self::FONT_DIR . '/LEXEND-OFL.txt';
        $this->assertFileExists($ofl, 'La SIL OFL impose de distribuer la licence avec la police.');
        $this->assertStringContainsString('SIL Open Font License', (string)file_get_contents($ofl));
    }
}
