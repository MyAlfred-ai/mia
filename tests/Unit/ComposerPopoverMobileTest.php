<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Popovers du composer sur téléphone (menu « + », assistants, bases) — cf.
 * CLAUDE.md « Popovers du composer sur téléphone ».
 *
 * Défauts constatés au simulateur iPhone (Safari et application web installée),
 * tous invisibles à la lecture du code :
 *  - le panneau était `fixed` à 118 px du bas de l'écran : sur l'accueil (pilule
 *    centrée) il recouvrait toute la pilule, « + » compris, et le sélecteur de
 *    modèle et le bouton Envoyer passaient par-dessus et en volaient les taps ;
 *    avec un long texte, il en masquait le haut ;
 *  - posé en `absolute` sous la pilule, la liste des assistants s'ouvrait sans
 *    rien afficher : iOS la rognait dans le conteneur défilant
 *    `.input-actions-left` faute de couche propre ;
 *  - un `resize` fermait le menu, or le clavier qui se ferme en émet un
 *    (Android, Firefox) au moment précis où le tap sur « + » l'ouvre ;
 *  - au doigt, `:hover` restait collé sur le dernier bouton touché.
 */
final class ComposerPopoverMobileTest extends TestCase
{
    private static function css(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/css/main.css');
    }

    private static function js(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
    }

    /** Règle mobile des trois popovers (bloc ≤ 768 px). */
    private static function mobileRule(): string
    {
        $css = self::css();
        $ok  = preg_match('/#plusMenuPanel,\s*#assistantPickerList,\s*#kbPickerPanel\s*\{([^}]*)\}/', $css, $m);
        self::assertSame(1, $ok, 'Règle mobile commune des popovers du composer introuvable.');
        return $m[1];
    }

    public function testLesPopoversSontPosesAuDessusDeLaPilule(): void
    {
        $rule = self::mobileRule();
        $this->assertMatchesRegularExpression('/position:\s*absolute\s*!important/', $rule);
        $this->assertMatchesRegularExpression('/bottom:\s*calc\(100%\s*\+\s*8px\)\s*!important/', $rule);
        $this->assertStringNotContainsString('118px', $rule,
            'Un décalage fixe depuis le bas de l\'écran suppose une pilule d\'une ligne collée en bas : '
            . 'faux sur l\'accueil et dès que la pilule grandit.');
        $this->assertStringNotContainsString('position: fixed', $rule);
        // Le bloc conteneur est la pilule (ancêtre du conteneur défilant), donc
        // les enveloppes des boutons ne doivent plus en servir.
        $this->assertMatchesRegularExpression('/\.input-box\s*\{\s*position:\s*relative;\s*\}/', self::css());
        $this->assertMatchesRegularExpression('/\.plus-menu,\s*\.assistant-picker,\s*\.kb-picker\s*\{\s*position:\s*static;\s*\}/', self::css());
    }

    public function testLesPopoversOntLeurPropreCoucheSousIos(): void
    {
        $this->assertMatchesRegularExpression('/will-change:\s*transform/', self::mobileRule(),
            'Sans couche propre, iOS rogne le popover dans le conteneur défilant .input-actions-left : '
            . 'la liste des assistants s\'ouvrait sans rien afficher.');
    }

    public function testLaHauteurSuitLaPlaceAuDessusDeLaPilule(): void
    {
        $rule = self::mobileRule();
        $this->assertStringContainsString('var(--composer-popover-room', $rule);
        $js = self::js();
        $this->assertStringContainsString('window.fitComposerPopover = function', $js);
        $this->assertStringContainsString("'--composer-popover-room'", $js);
        $this->assertStringContainsString('miaUiZoom', substr($js, strpos($js, 'window.fitComposerPopover = function'), 1600),
            'La place est mesurée en pixels d\'écran : la taille d\'affichage doit être retirée.');
        // Appelée à l'ouverture des trois popovers.
        $this->assertGreaterThanOrEqual(2, substr_count($js, 'window.fitComposerPopover(panel)'), 'Menu « + » et bases.');
        $this->assertGreaterThanOrEqual(2, substr_count($js, 'window.fitComposerPopover(list)'), 'Assistants : bouton et mention « @ ».');
    }

    public function testLeClavierQuiSeFermeNeRefermePasLeMenu(): void
    {
        $js = self::js();
        $this->assertStringNotContainsString("window.addEventListener('resize', close);", $js,
            'Fermer sur tout `resize` referme le menu quand le clavier se ferme, au moment du tap sur « + ».');
        $this->assertGreaterThanOrEqual(2, substr_count($js, 'if (window.innerWidth === lastWidth) return;'),
            'Menu « + » et bases : fermeture au changement de LARGEUR seulement.');
    }

    public function testLesSurvolsDuComposerNeCollentPasAuDoigt(): void
    {
        $css = self::css();
        foreach (['.composer-btn:hover', '.plus-menu-row:hover', '.plus-menu-item:hover', '.tool-picker-item:hover'] as $sel) {
            $pos = strpos($css, $sel);
            $this->assertNotFalse($pos, "$sel introuvable");
            $before = substr($css, max(0, $pos - 400), 400);
            $this->assertMatchesRegularExpression('/@media \(hover: hover\)\s*\{[^}]*$/', $before,
                "$sel doit vivre sous @media (hover: hover) : iOS garde :hover sur le dernier élément touché.");
        }
    }
}
