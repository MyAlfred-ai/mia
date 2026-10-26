<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Réserve de « safe area » en haut d'écran.
 *
 * Toutes les pages portent `viewport-fit=cover` : hors navigateur (PWA installée
 * ou wrapper natif), la page s'étend SOUS la barre d'état et l'encoche. Sans
 * réserve explicite, le header du haut (burger, titre, avatar) de
 * l'administration et des paramètres est amputé — et le défaut est invisible
 * dans l'onglet de navigateur où l'on teste. On verrouille donc la réserve au
 * niveau du CSS, ET l'absence de raccourci `padding` capable de l'écraser.
 *
 * Les headers ne sont que la moitié du problème : dès qu'un volet plein écran,
 * une modale ou un calque s'ouvre, c'est LUI qui occupe le haut de l'écran et
 * sa commande de fermeture — le retour vers les menus — repasse sous l'horloge.
 * D'où deux jetons (`--safe-top` / `--safe-bottom`) plutôt que des `env(...)`
 * recopiés surface par surface : une seule chose à vérifier, énumérée ici.
 */
final class PwaSafeAreaTest extends TestCase
{
    private static function css(): string
    {
        return file_get_contents(ROOT . '/public/assets/css/main.css') ?: '';
    }

    public function testMetasCarryViewportFitCover(): void
    {
        // Le fix n'a de sens que si les pages débordent réellement sous l'encoche.
        foreach (['admin/index.php', 'admin/tools.php', 'settings/ai.php'] as $tpl) {
            $this->assertStringContainsString(
                'viewport-fit=cover',
                file_get_contents(ROOT . '/templates/' . $tpl) ?: '',
                $tpl . ' : viewport-fit=cover attendu'
            );
        }
    }

    public function testMobileHeadersReserveTheStatusBarWhateverTheDisplayMode(): void
    {
        // La réserve n'est PAS conditionnée à `display-mode` : `max()` la rend
        // inoffensive là où l'inset vaut 0 (onglet de navigateur, Android), et
        // une détection de contexte qui rate un cas (WebView tierce, plein écran
        // après défilement) redonne un header amputé.
        $css = self::css();
        $this->assertMatchesRegularExpression(
            '/body\.app \.mobile-header,\s*body\.admin-page \.admin-mobile-header\s*\{\s*padding-top:\s*max\(\s*8px,\s*var\(--safe-top\)\s*\)/',
            $css,
            'Les deux headers mobiles (chat + admin/paramètres) doivent réserver la barre d\'état, sélecteurs qualifiés par le <body>'
        );

        // Cette réserve doit vivre dans un @media largeur seule, pas display-mode.
        $start = strpos($css, 'body.app .mobile-header,');
        $this->assertIsInt($start);
        $before = substr($css, 0, (int)$start);
        $media  = substr($before, (int)strrpos($before, '@media'));
        $this->assertStringContainsString('max-width: 768px', $media);
        $this->assertStringNotContainsString('display-mode', $media);
    }

    public function testNoPaddingShorthandCanOverrideTheAdminHeaderReserve(): void
    {
        // Le défaut réel : `body.admin-page .admin-mobile-header { padding: 8px 8px }`
        // du bloc ≤ 420 px (spécificité 0,2,1) écrasait le `padding-top` de la
        // réserve — et TOUS les téléphones sont sous 420 px. Convention : aucune
        // règle qualifiée par le <body> ne pose de raccourci `padding` sur ce
        // header ; utiliser `padding-inline` / `padding-block`.
        preg_match_all(
            '/(?:^|[{}])([^{}]*\.admin-mobile-header[^{}]*)\{([^{}]*)\}/m',
            self::css(),
            $rules,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($rules);

        foreach ($rules as [, $selector, $body]) {
            $selector = (string)preg_replace('#/\*.*?\*/#s', '', $selector);
            if (!str_contains($selector, 'body.')) {
                continue; // spécificité < réserve : ne peut pas la battre
            }
            $this->assertDoesNotMatchRegularExpression(
                '/(^|;|\s)padding\s*:/',
                $body,
                'Raccourci `padding` sur « ' . trim(preg_replace('/\s+/', ' ', $selector) ?? '')
                . ' » : il écrase la réserve de barre d\'état'
            );
        }
    }

    public function testNativeHeaderKeepsItsPaddingWhenInsetIsZero(): void
    {
        // Android renvoie 0 : un `padding-top: env(...)` seul ÉCRASERAIT le
        // padding de base et collerait le contenu au bord haut. D'où max().
        $this->assertMatchesRegularExpression(
            '/\.in-native-app \.mobile-header,\s*\.in-native-app \.admin-mobile-header\s*\{\s*padding-top:\s*max\(/',
            self::css(),
            'Le header natif doit utiliser max(base, env(...)), pas env(...) seul'
        );
    }

    public function testTokensAreDefinedWithAZeroFallback(): void
    {
        $css = self::css();

        // Repli à 0 AVANT les valeurs réelles : sur un moteur qui ignore env(),
        // un `calc(12px + var(--safe-bottom))` non résolu retomberait à 0 au
        // lieu de 12px (déclaration invalide à la substitution).
        $this->assertMatchesRegularExpression(
            '/--safe-top:\s*0px;\s*--safe-bottom:\s*0px;/',
            $css,
            'Les jetons de zone sûre doivent d\'abord valoir 0 (repli des vieux moteurs)'
        );
        // Divisées par la taille d'affichage (Réglages → Affichage) : une zone
        // sûre est une mesure de l'écran, elle ne grandit pas avec le zoom.
        $this->assertMatchesRegularExpression(
            '/@supports \(padding-top: env\(safe-area-inset-top\)\)\s*\{\s*:root\s*\{\s*(?:\/\*.*?\*\/\s*)?'
                . '--safe-top:\s*calc\(env\(safe-area-inset-top, 0px\) \/ var\(--ui-zoom, 1\)\);\s*'
                . '--safe-bottom:\s*calc\(env\(safe-area-inset-bottom, 0px\) \/ var\(--ui-zoom, 1\)\);/s',
            $css,
            'Les valeurs réelles doivent être posées sous @supports env(...)'
        );
    }

    public function testEnvIsNeverCalledOutsideTheTokenDefinition(): void
    {
        // Toute la feuille passe par les jetons : un `env(safe-area-inset-*)`
        // ailleurs, c'est une surface qui se protège dans son coin et que la
        // prochaine relecture oubliera.
        $occurrences = preg_match_all('/env\(safe-area-inset-[a-z]+/', self::css());
        $this->assertSame(
            3, // @supports (…top) + les deux valeurs de :root
            $occurrences,
            'env(safe-area-inset-*) ne doit apparaître que dans la définition des jetons ; '
                . 'partout ailleurs, utiliser var(--safe-top) / var(--safe-bottom)'
        );
    }

    public function testFullScreenViewersReserveTheStatusBar(): void
    {
        // Sur mobile ces volets couvrent 100 % de la largeur en `top: 0` : sans
        // réserve, leur bouton « Fermer » — seul retour vers les menus — se
        // retrouve sous l'horloge. Les trois barres sont mutualisées : la carte
        // et la présentation réutilisent la coque `.html-viewer`.
        $this->assertMatchesRegularExpression(
            '/\.html-viewer-bar,\s*\.video-viewer-bar,\s*\.sources-viewer-bar\s*\{\s*'
                . 'height:\s*calc\(46px \+ var\(--safe-top\)\);\s*'
                . 'padding-top:\s*var\(--safe-top\);/',
            self::css(),
            'Les barres de titre des volets plein écran doivent réserver la zone sûre '
                . '(hauteur ET padding : le padding seul ne ferait qu\'écraser le contenu)'
        );
    }

    public function testOverlaysReserveTheStatusBar(): void
    {
        $css = self::css();

        // Modales : en bottom sheet sur mobile, la boîte monte jusqu'au bord haut.
        $this->assertMatchesRegularExpression(
            '/\.modal,\s*\.session-expired-modal\s*\{\s*padding-top:\s*var\(--safe-top\)/',
            $css,
            'Les calques modaux doivent réserver la zone sûre'
        );
        // …et la hauteur max doit suivre, sinon ce qu'on gagne en haut se perd en bas.
        $this->assertStringContainsString(
            'max-height: calc(calc(100dvh / var(--ui-zoom, 1)) - 24px - var(--safe-top));',
            $css,
            'La hauteur max des modales mobiles doit retrancher la zone sûre réservée'
        );
        $this->assertStringContainsString(
            'height: calc(calc(100vh / var(--ui-zoom, 1)) - var(--safe-top));',
            $css,
            'La visionneuse de créations doit retrancher la zone sûre de sa hauteur'
        );
        // Lightbox image : son bouton de fermeture est ancré en haut à droite.
        $this->assertMatchesRegularExpression(
            '/\.image-lightbox-close\s*\{\s*top:\s*calc\(18px \+ var\(--safe-top\)\)/',
            $css,
            'La fermeture de la lightbox doit descendre sous la barre d\'état'
        );
    }

    public function testStagingBadgeLeavesTheTopBarAlone(): void
    {
        // Le badge « STAGING » du wrapper natif était centré en haut : une fois
        // les headers et les volets descendus sous la barre d'état, il tombait
        // pile sur leur titre.
        $css  = self::css();
        $rule = substr($css, (int)strpos($css, '.native-env-badge {'), 700);
        $this->assertStringNotContainsString('top: calc(var(--safe-top)', $rule);
        $this->assertStringContainsString('bottom: calc(var(--safe-bottom) + 4px);', $rule);
    }

    public function testJsPositionedMenusShareASingleSafeAreaProbe(): void
    {
        // Les menus placés en px par JS ne peuvent pas lire `--safe-top` de façon
        // fiable (env() n'est pas garanti résolu par getComputedStyle) : une
        // sonde mesurée, déclarée une seule fois dans le script chargé par TOUTES
        // les pages applicatives.
        $userMenu = file_get_contents(ROOT . '/public/assets/js/user-menu.js') ?: '';
        $this->assertStringContainsString('window.miaSafeAreaTop = function', $userMenu);
        $this->assertStringContainsString('env(safe-area-inset-top,0px)', $userMenu);
        // La palette ⌘K, elle, se place en CSS : plein écran sur téléphone,
        // son en-tête descend sous la barre d'état.
        $this->assertStringContainsString('    .cmdp-head { padding: max(10px, var(--safe-top)) 10px 10px 16px; }', file_get_contents(ROOT . '/public/assets/css/main.css') ?: '');

        // chat.js réutilise la sonde sans la redéfinir, avec repli à 0.
        $chat = file_get_contents(ROOT . '/public/assets/js/chat.js') ?: '';
        $this->assertMatchesRegularExpression(
            '/function safeAreaTop\(\)\s*\{\s*return typeof window\.miaSafeAreaTop === \'function\'/',
            $chat,
            'chat.js doit déléguer à la sonde partagée (repli à 0 si le script manque)'
        );
        $this->assertStringContainsString('const minTop = safeAreaTop() + 8;', $chat);
        $this->assertStringNotContainsString(
            'window.miaSafeAreaTop = function',
            $chat,
            'La sonde ne doit être déclarée qu\'une fois (user-menu.js)'
        );
    }
}
