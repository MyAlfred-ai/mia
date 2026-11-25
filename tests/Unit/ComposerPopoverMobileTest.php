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

    /**
     * Liste des modèles (sélecteur à droite de la pilule) : même règle que les
     * trois autres. PIÈGE payé le 2026-11-13 : la largeur « au contenu » du
     * bureau (`width: max-content`, ajoutée pour ne plus couper les noms) restait
     * active au téléphone et l'emportait sur `right` — la liste faisait 497 px
     * sur un écran de 390, étoiles « modèle par défaut » et coûts hors de vue.
     */
    public function testLaListeDesModelesTientDansLEcranAuTelephone(): void
    {
        $css = self::css();
        // Règle de bureau : largeur du contenu, bornée.
        $this->assertSame(1, preg_match('/\n\.llm-inline-picker \.llm-picker-list \{([^}]*)\}/', $css, $bureau));
        $this->assertStringContainsString('width: max-content', $bureau[1]);

        // Règle du téléphone (bloc ≤ 768 px, indentée) : elle DOIT rendre la
        // largeur à left/right, sinon max-content déborde.
        $this->assertSame(1, preg_match('/\n    \.llm-inline-picker \.llm-picker-list \{([^}]*)\}/', $css, $tel),
            'Règle mobile de la liste des modèles introuvable.');
        $rule = $tel[1];
        $this->assertMatchesRegularExpression('/\bwidth:\s*auto/', $rule,
            'Sans `width: auto`, la largeur du contenu du bureau sort la liste de l\'écran.');
        $this->assertMatchesRegularExpression('/position:\s*absolute/', $rule);
        $this->assertMatchesRegularExpression('/left:\s*0;/', $rule);
        $this->assertMatchesRegularExpression('/right:\s*0;/', $rule);
        $this->assertMatchesRegularExpression('/bottom:\s*calc\(100%\s*\+\s*8px\)/', $rule);
        $this->assertStringNotContainsString('118px', $rule);
        $this->assertStringContainsString('var(--composer-popover-room', $rule);
        $this->assertMatchesRegularExpression('/will-change:\s*transform/', $rule);
        // Le bloc conteneur est la pilule, pas l'enveloppe du bouton.
        $this->assertMatchesRegularExpression('/\.llm-inline-picker\s*\{\s*position:\s*static;\s*\}/', $css);
        // Les noms se raccourcissent, l'étoile et le coût ne rétrécissent pas.
        $this->assertMatchesRegularExpression('/\.llm-picker-item-label\s*\{[^}]*min-width:\s*0;[^}]*text-overflow:\s*ellipsis/', $css);
        $this->assertMatchesRegularExpression('/\.llm-cost-stars\s*\{[^}]*flex-shrink:\s*0/', $css);
        $this->assertMatchesRegularExpression('/\.llm-fav-btn\s*\{[^}]*flex-shrink:\s*0/', $css);

        // Hauteur ajustée à l'ouverture et quand la zone visible change.
        $js = self::js();
        $this->assertStringContainsString("'kbPickerPanel', 'llmPickerList']", $js);
        $debut = strpos($js, "const btn  = document.getElementById('llmPickerBtn');");
        $this->assertIsInt($debut);
        $this->assertStringContainsString('window.fitComposerPopover(list)', substr($js, $debut, 900));
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
