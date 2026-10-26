<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Réglages → Affichage : thème, taille d'affichage, contraste renforcé et
 * touche d'envoi, par utilisateur.
 *
 * Ce que ces tests protègent :
 *  - les formes pures de DisplayPreferences (valeurs autorisées, thème rendu,
 *    attributs de <html>, libellés traduits) ;
 *  - le CÂBLAGE qu'aucune relecture ne garde : TOUS les gabarits passent par
 *    le helper (un écran oublié afficherait le thème de l'organisation à qui en
 *    a choisi un autre), le script du thème « Automatique » et le facteur de
 *    zoom dans head-meta.php ;
 *  - les DEUX règles du zoom : aucune unité d'écran (vh, vw…) non divisée par
 *    --ui-zoom dans les feuilles de style, et aucun menu flottant positionné
 *    en JavaScript sans window.miaUiZoom() — l'une déborderait de l'écran,
 *    l'autre placerait le menu à côté de son bouton, et seulement chez qui a
 *    agrandi l'affichage.
 */
final class DisplayPreferencesTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    // ------------------------------------------------------------------
    // 1. Formes pures
    // ------------------------------------------------------------------

    public function testLesValeursHorsListeRetombentSurLeDefaut(): void
    {
        $this->assertSame('light', DisplayPreferences::sanitizeTheme(' Light '));
        $this->assertSame('system', DisplayPreferences::sanitizeTheme('system'));
        $this->assertSame('', DisplayPreferences::sanitizeTheme('rose'));
        $this->assertSame('', DisplayPreferences::sanitizeTheme(['dark']));
        $this->assertSame('', DisplayPreferences::sanitizeTheme(null));

        $this->assertSame(110, DisplayPreferences::sanitizeScale('110'));
        $this->assertSame(125, DisplayPreferences::sanitizeScale(125));
        $this->assertSame(110, DisplayPreferences::sanitizeScale(112), 'Arrondie au pas du curseur.');
        $this->assertSame(115, DisplayPreferences::sanitizeScale('113'));
        $this->assertSame(150, DisplayPreferences::sanitizeScale(300), 'Bornée au maximum.');
        $this->assertSame(80, DisplayPreferences::sanitizeScale(10), 'Bornée au minimum.');
        $this->assertSame(100, DisplayPreferences::sanitizeScale('grand'));
        $this->assertSame(100, DisplayPreferences::sanitizeScale(null));
        $this->assertSame(range(80, 150, 5), DisplayPreferences::scaleSteps());
        $this->assertSame('1.1', DisplayPreferences::factor(110));
        $this->assertSame('0.85', DisplayPreferences::factor(85));
        $this->assertSame('1.5', DisplayPreferences::factor(150));

        $this->assertSame('', DisplayPreferences::themeOf(null));
        $this->assertSame(100, DisplayPreferences::scaleOf(null));
        $this->assertSame(100, DisplayPreferences::scaleOf(['ui_scale' => null]));
    }

    public function testContrasteEtToucheDEnvoi(): void
    {
        $this->assertTrue(DisplayPreferences::sanitizeContrast('1'));
        $this->assertTrue(DisplayPreferences::sanitizeContrast(true));
        $this->assertFalse(DisplayPreferences::sanitizeContrast('0'));
        $this->assertFalse(DisplayPreferences::sanitizeContrast('<script>'));
        $this->assertFalse(DisplayPreferences::contrastOf(null));
        $this->assertTrue(DisplayPreferences::contrastOf(['ui_contrast' => 1]));

        $this->assertSame('mod_enter', DisplayPreferences::sanitizeSendKey('mod_enter'));
        $this->assertSame('enter', DisplayPreferences::sanitizeSendKey('shift_enter'), 'Une touche inconnue retombe sur Entrée.');
        $this->assertSame('enter', DisplayPreferences::sendKeyOf(['ui_send_key' => '']), '« Jamais choisi » = Entrée.');
        $this->assertSame('enter', DisplayPreferences::sendKeyOf(null));

        $this->assertSame(
            'data-theme="light" data-theme-pref="light" data-contrast="high"',
            DisplayPreferences::htmlAttributesFor(['ui_theme' => 'light', 'ui_contrast' => '1', 'ui_send_key' => 'mod_enter'], 'dark'),
            'Le contraste se pose sur <html> ; la touche d\'envoi, elle, ne regarde que le chat.'
        );

        $this->assertSame([0, 1], array_keys(DisplayPreferences::contrasts()));
        $this->assertSame(DisplayPreferences::SEND_KEYS, array_keys(DisplayPreferences::sendKeys()));
        I18n::use('en');
        $this->assertSame('Ctrl + Enter', DisplayPreferences::sendKeys()['mod_enter']['label']);
        $this->assertSame('High', DisplayPreferences::contrasts()[1]['label']);
    }

    public function testLeThemeRenduSuitLaPersonneSinonLOrganisation(): void
    {
        $this->assertSame('light', DisplayPreferences::resolveTheme('light', 'dark'));
        $this->assertSame('dark', DisplayPreferences::resolveTheme('dark', 'light'));
        $this->assertSame('light', DisplayPreferences::resolveTheme('', 'light'), 'Rien de choisi : le thème de l\'organisation.');
        $this->assertSame('dark', DisplayPreferences::resolveTheme('', 'n_importe'));
        $this->assertSame(
            'light',
            DisplayPreferences::resolveTheme('system', 'light'),
            '« Automatique » : le serveur rend le thème de l\'organisation, le navigateur corrige avant le premier rendu.'
        );
    }

    public function testLesAttributsDeHtml(): void
    {
        $this->assertSame('data-theme="dark"', DisplayPreferences::htmlAttributesFor(null, 'dark'), 'Page de connexion : le thème de l\'organisation, rien d\'autre.');
        $this->assertSame('data-theme="light"', DisplayPreferences::htmlAttributesFor(['ui_theme' => '', 'ui_scale' => 100], 'light'));
        $this->assertSame(
            'data-theme="light" data-theme-pref="light"',
            DisplayPreferences::htmlAttributesFor(['ui_theme' => 'light', 'ui_scale' => 100], 'dark')
        );
        $this->assertSame(
            'data-theme="dark" data-theme-pref="system" data-ui-scale="125" style="--ui-scale: 1.25"',
            DisplayPreferences::htmlAttributesFor(['ui_theme' => 'system', 'ui_scale' => 125], 'dark')
        );
        $this->assertSame(
            'data-theme="dark" data-ui-scale="90" style="--ui-scale: 0.9"',
            DisplayPreferences::htmlAttributesFor(['ui_theme' => '"><script>', 'ui_scale' => '90'], 'dark'),
            'Une valeur forgée n\'atteint jamais l\'attribut : seules des valeurs connues y sont écrites.'
        );
        $this->assertSame('data-theme="dark"', DisplayPreferences::htmlAttributesFor(['ui_scale' => '90"><x'], 'dark'));
        $this->assertStringNotContainsString(
            'data-ui-scale',
            DisplayPreferences::htmlAttributesFor(['ui_scale' => 100], 'dark'),
            'À 100 %, aucun attribut : rendu strictement inchangé.'
        );

        $this->assertSame('#f5f5f5', DisplayPreferences::themeColor('light'));
        $this->assertSame('#212121', DisplayPreferences::themeColor('dark'));
    }

    public function testLesLibellesSontTraduitsEtNommentLeThemeDeLOrganisation(): void
    {
        $fr = DisplayPreferences::themes('dark');
        $this->assertSame(DisplayPreferences::THEMES, array_keys($fr));
        $this->assertSame('Par défaut', $fr['']['label']);
        $this->assertStringContainsString('sombre', $fr['']['hint']);
        $this->assertStringContainsString('clair', DisplayPreferences::themes('light')['']['hint']);

        $scales = DisplayPreferences::scaleLabels();
        $this->assertSame(DisplayPreferences::scaleSteps(), array_keys($scales));
        $this->assertSame("110\u{00A0}%", $scales[110], 'Espace insécable avant % en français.');
        foreach (DisplayPreferences::SCALE_TICKS as $tick) {
            $this->assertContains($tick, DisplayPreferences::scaleSteps(), "Repère $tick hors de la course du curseur.");
        }

        I18n::use('en');
        $en = DisplayPreferences::themes('dark');
        $this->assertSame('Default', $en['']['label']);
        $this->assertStringContainsString('dark', $en['']['hint']);
        $this->assertSame('Automatic', $en['system']['label']);
        $this->assertSame('110%', DisplayPreferences::scaleLabels()[110]);
    }

    public function testLesLibellesDesConstantesOntLeurTraduction(): void
    {
        // Clés CALCULÉES (t($constante)) : le test de couverture du dictionnaire ne
        // voit que les appels littéraux, c'est celui-ci qui les garde.
        $dict = (array)(require ROOT . '/lang/en.php');
        $src  = self::lire('src/Helpers/DisplayPreferences.php');
        preg_match_all("/'(label|hint)'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
        preg_match_all("/\\d+\\s*=>\\s*'([^']+)',/", $src, $s);
        $cles = array_merge(array_map('stripslashes', $m[2]), $s[1]);
        $this->assertGreaterThanOrEqual(12, count($cles));
        foreach ($cles as $cle) {
            $this->assertArrayHasKey($cle, $dict, "Libellé d'affichage sans traduction : « $cle »");
        }
    }

    // ------------------------------------------------------------------
    // 2. Tous les gabarits passent par le helper
    // ------------------------------------------------------------------

    /** @return list<string> gabarits qui ouvrent une page HTML complète */
    private static function gabaritsDePage(): array
    {
        $out = [];
        $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/templates', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen(ROOT) + 1);
            if (!str_ends_with($rel, '.php')) continue;
            // Pages rendues SANS base, par construction (setup, erreurs fatales) :
            // elles ne chargent pas main.css et n'ont pas de compte à lire.
            if (str_starts_with($rel, 'templates/setup/') || str_starts_with($rel, 'templates/errors/')) continue;
            if (preg_match('/<html\s+lang=/i', (string)file_get_contents($f->getPathname()))) {
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    public function testChaquePagePoseLesAttributsDuHelper(): void
    {
        $pages = self::gabaritsDePage();
        $this->assertGreaterThan(30, count($pages));
        // Exceptions motivées : un export PDF (rendu par Dompdf, ni thème ni zoom).
        $exempts = ['templates/admin/usage-pdf.php'];
        foreach ($pages as $page) {
            $src = self::lire($page);
            $this->assertDoesNotMatchRegularExpression('/data-theme="<\?=\s*htmlspecialchars\(Settings::theme\(\)\)/', $src, "$page lit encore le thème de l'organisation au lieu de celui de la personne.");
            if (in_array($page, $exempts, true)) continue;
            $this->assertMatchesRegularExpression('/<html\b(?:\?>|[^>])*?<\?= DisplayPreferences::htmlAttributes\(\) \?>/', $src, "$page ne pose pas les attributs d'affichage sur <html>.");
        }
    }

    public function testHeadMetaResoutLAutomatiqueEtExposeLeZoom(): void
    {
        $head = self::lire('templates/partials/head-meta.php');
        $this->assertStringContainsString('DisplayPreferences::themeColor(DisplayPreferences::currentTheme())', $head);
        $this->assertStringNotContainsString('Settings::theme()', $head);
        $this->assertStringContainsString("window.miaApplyTheme = function", $head);
        $this->assertStringContainsString("'(prefers-color-scheme: dark)'", $head);
        $this->assertStringContainsString("data-theme-pref') !== 'system'", $head, 'Seul « Automatique » suit l\'appareil.');
        $this->assertStringContainsString("'mia:themechange'", $head);
        $this->assertStringContainsString('window.miaUiZoom = function', $head);

        // Le script est rendu AVANT <meta charset> : il doit rester pur ASCII.
        preg_match('/<script>\s*\(function \(\) \{\s*var d = document\.documentElement;.*?<\/script>/s', $head, $m);
        $this->assertNotEmpty($m, 'Script du thème introuvable.');
        $this->assertSame(1, preg_match('/^[\x00-\x7F]*$/', $m[0]), 'Le script du thème contient un caractère non ASCII.');
        // …et APRÈS la balise theme-color, qu'il met à jour.
        $this->assertLessThan(strpos($head, 'window.miaApplyTheme'), strpos($head, 'name="theme-color"'));

        $this->assertStringContainsString("addEventListener('mia:themechange', syncStatusBar)", self::lire('public/assets/js/native-bridge.js'));
    }

    public function testMarqueEtApparenceNeContreditPasLeChoixDeLAdministrateur(): void
    {
        $src = self::lire('templates/admin/settings.php');
        $this->assertStringContainsString("!document.documentElement.hasAttribute('data-theme-pref')", $src);
        $this->assertStringContainsString('Réglages → Affichage', $src);
    }

    // ------------------------------------------------------------------
    // 3. Les deux règles du zoom
    // ------------------------------------------------------------------

    /**
     * Unités d'écran NON divisées par --ui-zoom, hors commentaires, préludes
     * d'@-règles (media queries) et tailles de police (qui doivent grandir).
     *
     * @return list<string>
     */
    private static function unitesNonCompensees(string $css): array
    {
        $css   = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        $fautes = [];
        foreach (explode("\n", $css) as $n => $ligne) {
            if (str_starts_with(ltrim($ligne), '@')) continue;
            // Déclaration par déclaration : une taille de police doit grandir.
            foreach (preg_split('/[;{}]/', $ligne) ?: [] as $decl) {
                if (preg_match('/^\s*font-size\s*:/', $decl)) continue;
                $sans = preg_replace('/calc\(\s*[\d.]+(?:d|s|l)?v[hw] \/ var\(--ui-zoom, 1\)\)/', '', $decl) ?? '';
                if (preg_match('/(?<![\w.#-])[\d.]+(?:d|s|l)?v[hw]\b/', $sans)) {
                    $fautes[] = 'ligne ' . ($n + 1) . ' : ' . trim($ligne);
                    break;
                }
            }
        }
        return $fautes;
    }

    public function testAucuneUniteDEcranNEchappeALaCompensation(): void
    {
        foreach (['public/assets/css/main.css', 'public/assets/css/files.css', 'public/assets/css/deck.css'] as $feuille) {
            $this->assertSame([], self::unitesNonCompensees(self::lire($feuille)), "$feuille : unité d'écran à diviser par var(--ui-zoom, 1).");
        }
        // Le détecteur détecte : sans ce contrôle, un test vert ne prouverait rien.
        $this->assertCount(1, self::unitesNonCompensees(".x { height: 100dvh; }\n.y { height: calc(100vh / var(--ui-zoom, 1)); }\n.z { font-size: 3vw; }"));

        foreach (self::gabaritsDePage() as $page) {
            preg_match_all('/style="([^"]*)"/', self::lire($page), $m);
            foreach ($m[1] as $style) {
                $this->assertSame([], self::unitesNonCompensees(str_replace(';', ";\n", $style)), "$page : style en ligne avec une unité d'écran non divisée.");
            }
        }
    }

    public function testLeZoomEstPlafonneParLaFeuilleDeStyle(): void
    {
        $css = self::lire('public/assets/css/main.css');
        $this->assertMatchesRegularExpression(
            '/@supports \(zoom: 1\) \{\s*html\[data-ui-scale\] \{\s*--ui-zoom: min\(var\(--ui-scale, 1\), var\(--ui-zoom-max, 1\.5\)\);\s*zoom: var\(--ui-zoom, 1\);/',
            $css,
            'Le zoom n\'est posé que là où il est compris, plafonné par --ui-zoom-max.'
        );
        // Plafond par largeur d'écran : le maximum du curseur sur grand écran,
        // puis 130 % jusqu'à 1 024 px, 115 % jusqu'à 400 px.
        $this->assertStringContainsString(':root { --ui-zoom-max: ' . DisplayPreferences::factor(DisplayPreferences::MAX_SCALE) . '; }', $css);
        $this->assertStringContainsString('@media (max-width: 1024px) { :root { --ui-zoom-max: 1.3; } }', $css);
        $this->assertStringContainsString('@media (max-width: 400px)  { :root { --ui-zoom-max: 1.15; } }', $css);
        // Plus aucune règle par cran : la valeur est continue.
        $this->assertDoesNotMatchRegularExpression('/html\[data-ui-scale="\d+"\]/', $css);
        $this->assertStringContainsString('--safe-top:    calc(env(safe-area-inset-top, 0px) / var(--ui-zoom, 1));', $css);
    }

    public function testLeContrasteRenforceRedefinitLesJetonsDesDeuxThemes(): void
    {
        $css = self::lire('public/assets/css/main.css');
        $this->assertMatchesRegularExpression('/html\[data-contrast="high"\] \{\s*--text:\s*#ffffff;\s*--text-muted:[^;]+;\s*--border:[^;]+;/', $css);
        $this->assertMatchesRegularExpression(
            '/html\[data-theme="light"\]\[data-contrast="high"\] \{\s*--text:\s*#000000;\s*--text-muted:[^;]+;\s*--border:[^;]+;\s*--accent:\s*color-mix\(in srgb, var\(--accent-base, #10a37f\) \d+%, black\);/',
            $css,
            'En thème clair, l\'accent est assombri depuis la couleur de l\'organisation.'
        );
        // Les règles de contraste viennent APRÈS celles du thème clair : à
        // spécificité supérieure de toute façon, mais l'ordre se lit.
        $this->assertGreaterThan(strpos($css, '[data-theme="light"] {'), strpos($css, 'html[data-contrast="high"] {'));
        $this->assertStringContainsString('--accent-base: #10a37f;', $css);
        $this->assertStringContainsString("--accent-base: <?= htmlspecialchars(\$accentColor", self::lire('templates/partials/theme-vars.php'));
        // Marque & apparence : en contraste renforcé, l'accent se dérive.
        $this->assertStringContainsString("if (!document.documentElement.hasAttribute('data-contrast'))", self::lire('templates/admin/settings.php'));
    }

    public function testLaToucheDEnvoiDuChat(): void
    {
        $tpl = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('$sendKey = DisplayPreferences::sendKeyOf(Session::currentUser());', $tpl);
        $this->assertStringContainsString('data-send-key="<?= htmlspecialchars($sendKey) ?>"', $tpl);
        $this->assertStringContainsString("aria-keyshortcuts=\"<?= \$sendKey === 'mod_enter' ? 'Control+Enter Meta+Enter' : 'Enter' ?>\"", $tpl);

        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("isSendKeystroke(e, msgInput.dataset.sendKey || 'enter')", $js);
        $this->assertSame(1, substr_count($js, "e.key === 'Enter' && !e.shiftKey") + substr_count($js, 'function isSendKeystroke('), 'Une seule règle d\'envoi au clavier.');

        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }
        $debut = strpos($js, 'function isSendKeystroke(');
        $fonction = substr($js, $debut, strpos($js, "\n}\n", $debut) - $debut + 2);
        $cas = [
            // [mode, évènement, envoie ?]
            ['enter',     ['key' => 'Enter'], true],
            ['enter',     ['key' => 'Enter', 'shiftKey' => true], false],
            ['enter',     ['key' => 'Enter', 'ctrlKey' => true], true],
            ['enter',     ['key' => 'a'], false],
            ['enter',     ['key' => 'Enter', 'isComposing' => true], false],
            ['enter',     ['key' => 'Enter', 'keyCode' => 229], false],
            ['mod_enter', ['key' => 'Enter'], false],
            ['mod_enter', ['key' => 'Enter', 'shiftKey' => true], false],
            ['mod_enter', ['key' => 'Enter', 'ctrlKey' => true], true],
            ['mod_enter', ['key' => 'Enter', 'metaKey' => true], true],
            ['mod_enter', ['key' => 'Enter', 'metaKey' => true, 'isComposing' => true], false],
        ];
        $script = $fonction . "\nconst cas = " . json_encode($cas) . ";\n"
            . 'process.stdout.write(JSON.stringify(cas.map(([m, e]) => isSendKeystroke(e, m))));';
        $fichier = tempnam(sys_get_temp_dir(), 'mia-send-') . '.js';
        file_put_contents($fichier, $script);
        $sortie = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($fichier));
        @unlink($fichier);
        $this->assertSame(array_column($cas, 2), json_decode((string)$sortie, true));
    }

    private static function node(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }

    public function testLesMenusFlottantsRetirentLeZoom(): void
    {
        // Un script qui lit getBoundingClientRect() ET écrit une position en
        // pixels doit retirer le zoom. deck.js en est exempt : son éditeur tourne
        // DANS l'aperçu (iframe), que le zoom de la page ne touche pas, et ses
        // positions sont en pourcentages.
        $exempts = ['public/assets/js/deck.js'];
        $fichiers = array_merge(glob(ROOT . '/public/assets/js/*.js') ?: [], [ROOT . '/templates/admin/profiles/list.php']);
        $verifies = 0;
        foreach ($fichiers as $abs) {
            $rel = substr($abs, strlen(ROOT) + 1);
            if (str_contains($rel, '.min.') || in_array($rel, $exempts, true)) continue;
            $src = (string)file_get_contents($abs);
            if (!str_contains($src, 'getBoundingClientRect') || !preg_match('/style\.(left|top)\s*=/', $src)) continue;
            $verifies++;
            $this->assertStringContainsString('miaUiZoom', $src, "$rel positionne un élément depuis getBoundingClientRect() sans retirer la taille d'affichage.");
        }
        $this->assertGreaterThanOrEqual(6, $verifies);

        // La sonde de zone sûre mesure offsetHeight (valeur d'env()), pas la
        // hauteur zoomée à l'écran.
        $menu = self::lire('public/assets/js/user-menu.js');
        $this->assertStringContainsString('var h = probe.offsetHeight || 0;', $menu);
        // La largeur des volets droits suit le pointeur en pixels de page.
        $this->assertStringContainsString('applyViewerWidth((window.innerWidth - e.clientX) / (window.miaUiZoom ? window.miaUiZoom() : 1));', self::lire('public/assets/js/chat.js'));
    }

    // ------------------------------------------------------------------
    // 4. Page, routes, menus, migration, documentation
    // ------------------------------------------------------------------

    public function testLaPageEstRouteeEtAtteignableDesDeuxMenus(): void
    {
        $index = self::lire('public/index.php');
        $this->assertStringContainsString("\$router->get('/settings/display',         [UserSettingsController::class, 'display']);", $index);
        $this->assertStringContainsString("\$router->post('/settings/display',        [UserSettingsController::class, 'updateDisplayPrefs']);", $index);
        $ctrl = self::lire('src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString('public function display(): void', $ctrl);
        $this->assertStringContainsString('public function updateDisplayPrefs(): void', $ctrl);
        $this->assertStringContainsString("require ROOT . '/templates/settings/display.php';", $ctrl);

        // « Mon compte » : menu latéral des pages /settings (et la palette ⌘K),
        // lus dans NavRegistry::ACCOUNT.
        $this->assertSame('/settings/display', NavRegistry::ACCOUNT['display']['href']);
        $this->assertSame('display', NavRegistry::ACCOUNT['display']['page']);
        $this->assertStringContainsString('NavRegistry::accountFor($navUser)', self::lire('templates/partials/admin-nav.php'));
        $this->assertSame('/settings/display', array_column(NavRegistry::accountFor(['id' => 1, 'role' => 'user', 'is_active' => 1]), 'href', 'page')['display'] ?? null);
    }

    public function testLeHandlerNEcritQueCeQuiEstPoste(): void
    {
        $src = self::lire('src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString("array_key_exists('ui_theme', \$_POST) ? DisplayPreferences::sanitizeTheme(\$_POST['ui_theme']) : null", $src);
        $this->assertStringContainsString("array_key_exists('ui_scale', \$_POST) ? DisplayPreferences::sanitizeScale(\$_POST['ui_scale']) : null", $src);
        $this->assertStringContainsString("array_key_exists('ui_contrast', \$_POST) ? DisplayPreferences::sanitizeContrast(\$_POST['ui_contrast']) : null", $src);
        $this->assertStringContainsString("array_key_exists('ui_send_key', \$_POST) ? DisplayPreferences::sanitizeSendKey(\$_POST['ui_send_key']) : null", $src);
    }

    public function testLeFormulaireSAppliqueALaPageDesLeClic(): void
    {
        $tpl = self::lire('templates/settings/display.php');
        $this->assertStringContainsString('action="/settings/display" id="displayPrefsForm" data-autosave', $tpl);
        $this->assertStringContainsString('name="ui_theme"', $tpl);
        $this->assertStringContainsString('name="ui_scale"', $tpl);
        $this->assertStringContainsString("form.addEventListener('change'", $tpl);
        $this->assertStringContainsString("root.setAttribute('data-ui-scale', scale)", $tpl);
        $this->assertStringContainsString("root.style.setProperty('--ui-scale', String(parseInt(scale, 10) / 100))", $tpl);
        $this->assertStringContainsString('name="ui_contrast"', $tpl);
        $this->assertStringContainsString('name="ui_send_key"', $tpl);
        $this->assertStringContainsString("root.setAttribute('data-contrast', 'high')", $tpl);
        $this->assertStringContainsString('window.miaApplyTheme()', $tpl, '« Automatique » passe par le script de head-meta.php, une seule implémentation.');
        $this->assertStringContainsString('autosave-hint.php', $tpl);
    }

    public function testLeCurseurEtSonApercu(): void
    {
        $tpl = self::lire('templates/settings/display.php');
        $this->assertStringContainsString('type="range"', $tpl);
        $this->assertStringContainsString('min="<?= $scaleMin ?>"', $tpl);
        $this->assertStringContainsString('step="<?= DisplayPreferences::SCALE_STEP ?>"', $tpl);
        $this->assertStringContainsString('data-labels="<?= htmlspecialchars(json_encode($scaleLabels', $tpl, 'Libellés calculés par le serveur, aucune mise en forme recopiée en JS.');
        $this->assertStringContainsString('id="displayPreview"', $tpl);
        // Pendant le glissement, l'aperçu seul ; la page au relâchement.
        $this->assertStringContainsString("range?.addEventListener('input', syncScale);", $tpl);
        $this->assertStringNotContainsString("range?.addEventListener('input', apply", $tpl);
        // L'aperçu montre la taille APPLIQUÉE, plafond lu dans la feuille de style,
        // rapportée au zoom de la page qui le porte déjà.
        $this->assertStringContainsString("getPropertyValue('--ui-zoom-max')", $tpl);
        $this->assertStringContainsString('preview.style.zoom = String(effective / pageZoom);', $tpl);
        $this->assertStringContainsString("range.dispatchEvent(new Event('change', { bubbles: true }));", $tpl, 'Repères et « Rétablir » passent par le même geste que le curseur.');
    }

    public function testLaMigrationEtLeChargement(): void
    {
        $sql = self::lire('database/migrations/086_user_display_prefs.sql');
        $this->assertStringContainsString("ADD COLUMN ui_theme VARCHAR(10) NOT NULL DEFAULT ''", $sql);
        $this->assertStringContainsString('ADD COLUMN ui_scale SMALLINT UNSIGNED NOT NULL DEFAULT 100', $sql);
        $this->assertStringContainsString('ADD COLUMN ui_contrast TINYINT(1) NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString("ADD COLUMN ui_send_key VARCHAR(10) NOT NULL DEFAULT ''", $sql);
        $this->assertStringContainsString("require ROOT . '/src/Helpers/DisplayPreferences.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Helpers/DisplayPreferences.php',", self::lire('tests/bootstrap.php'));
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        $this->assertStringContainsString('**Onglet Affichage :**', self::lire('docs/GUIDE-UTILISATEUR.md'));
        $this->assertStringContainsString('**Réglages → Affichage**', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('Affichage par utilisateur', self::lire('CLAUDE.md'));
        $this->assertStringContainsString('<!-- id: affichage-personnel |', self::lire('docs/NOUVEAUTES.md'));
        $page = self::lire('docs/user/fonctionnalites/personnalisation/regler-l-affichage.md');
        $this->assertStringContainsString('Automatique', $page);
        $this->assertStringContainsString('regler-l-affichage.md', self::lire('docs/user/fonctionnalites/personnalisation/index.md'));
    }
}
