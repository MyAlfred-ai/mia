<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Normalisation de la spécification d'une présentation (sans base ni LLM).
 *
 * DeckSpec est la seule porte d'entrée des decks : toute écriture — sortie de
 * LLM comme POST de l'éditeur — y passe. Ces tests couvrent surtout ce que la
 * normalisation doit REFUSER.
 */
final class DeckSpecTest extends TestCase
{
    public function testStructureGarantieSurEntreeVide(): void
    {
        $deck = DeckSpec::normalize([]);

        $this->assertSame(DeckSpec::VERSION, $deck['version']);
        $this->assertSame('16:9', $deck['ratio']);
        $this->assertSame('Présentation', $deck['title']);
        $this->assertSame([], $deck['slides']);
        $this->assertSame(DeckSpec::DEFAULT_THEME, $deck['theme']['preset']);
    }

    public function testThemeInconnuRetombeSurLeDefaut(): void
    {
        $theme = DeckSpec::normalizeTheme(['preset' => 'n-existe-pas']);

        $this->assertSame(DeckSpec::DEFAULT_THEME, $theme['preset']);
        $this->assertSame(DeckSpec::THEMES[DeckSpec::DEFAULT_THEME]['accent'], $theme['accent']);
    }

    public function testCouleurInvalideIgnoree(): void
    {
        $theme = DeckSpec::normalizeTheme(['preset' => 'sobre', 'accent' => 'javascript:alert(1)']);

        $this->assertSame(DeckSpec::THEMES['sobre']['accent'], $theme['accent']);
    }

    public function testCouleurHexadecimaleAcceptee(): void
    {
        $theme = DeckSpec::normalizeTheme(['preset' => 'sobre', 'accent' => '#AABBCC']);

        $this->assertSame('#aabbcc', $theme['accent']);
    }

    /** Une URL externe permettrait de faire charger une ressource tierce par l'aperçu. */
    public function testUrlImageExterneRejetee(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'image', 'url' => 'https://exemple.invalid/pixel.png', 'prompt' => 'x',
        ]);

        $this->assertSame('', $el['url']);
    }

    public function testUrlImageAvecTraverseeRejetee(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'image', 'url' => '/assets/img/../../etc/passwd', 'prompt' => 'x',
        ]);

        $this->assertSame('', $el['url']);
    }

    public function testUrlImageLocaleAcceptee(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'image', 'url' => '/assets/img/generated/abc.png',
        ]);

        $this->assertSame('/assets/img/generated/abc.png', $el['url']);
    }

    /**
     * Image insérée par l'éditeur : elle n'a pas de prompt (ni générée depuis
     * un plan, ni décrite). La normalisation ne doit pas la jeter.
     */
    public function testImageInsereeSansPromptConservee(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'image', 'url' => '/assets/img/generated/insertion.png', 'prompt' => '',
            'x' => 32, 'y' => 22, 'w' => 36, 'h' => 56,
        ]);

        $this->assertNotNull($el);
        $this->assertSame('/assets/img/generated/insertion.png', $el['url']);
        $this->assertSame(36.0, $el['w']);
    }

    public function testGeometrieHorsCanvasRamenee(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'text', 'text' => 'Titre', 'x' => -80, 'y' => 400, 'w' => 900, 'h' => 900,
        ]);

        $this->assertGreaterThanOrEqual(0.0, $el['x']);
        $this->assertLessThanOrEqual(99.0, $el['y']);
        $this->assertLessThanOrEqual(100.0, $el['x'] + $el['w']);
        $this->assertLessThanOrEqual(100.0, $el['y'] + $el['h']);
    }

    /** Les filets d'accent font moins de 1 % de haut : le minimum ne doit pas les épaissir. */
    public function testFiletFinConserveSaFinesse(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'shape', 'x' => 8, 'y' => 26, 'w' => 6, 'h' => 0.7,
        ]);

        $this->assertSame(0.7, $el['h']);
    }

    // ------------------------------------ mise en forme posée par l'éditeur

    public function testMiseEnFormeConservee(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'text', 'text' => 'Titre', 'color' => '#FF8800', 'italic' => true, 'font' => 'serif',
        ]);

        $this->assertSame('#ff8800', $el['color']);
        $this->assertTrue($el['italic']);
        $this->assertSame('serif', $el['font']);
    }

    public function testCouleurDeTexteInvalideIgnoree(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'text', 'text' => 'Titre', 'color' => 'red; background:url(x)',
        ]);

        $this->assertSame('', $el['color'], 'aucune valeur libre ne doit atteindre le style inline');
    }

    /** Une police hors catalogue ne serait pas exportable à l'identique. */
    public function testPoliceHorsCatalogueIgnoree(): void
    {
        $el = DeckSpec::normalizeElement(['type' => 'text', 'text' => 'x', 'font' => 'Comic Sans MS']);

        $this->assertSame('', $el['font']);
    }

    public function testMiseEnFormeAussiSurLesPuces(): void
    {
        $el = DeckSpec::normalizeElement([
            'type' => 'bullets', 'items' => ['a', 'b'], 'color' => '#123456', 'italic' => true,
            'font' => 'mono', 'weight' => 700,
        ]);

        $this->assertSame('#123456', $el['color']);
        $this->assertTrue($el['italic']);
        $this->assertSame('mono', $el['font']);
        $this->assertSame(700, $el['weight']);
    }

    public function testMiseEnFormeAbsenteParDefaut(): void
    {
        $el = DeckSpec::normalizeElement(['type' => 'text', 'text' => 'x']);

        $this->assertSame('', $el['color']);
        $this->assertFalse($el['italic']);
        $this->assertSame('', $el['font']);
    }

    public function testElementDeTypeInconnuRetire(): void
    {
        $this->assertNull(DeckSpec::normalizeElement(['type' => 'iframe', 'x' => 0, 'y' => 0]));
    }

    public function testTexteVideRetire(): void
    {
        $this->assertNull(DeckSpec::normalizeElement(['type' => 'text', 'text' => '   ']));
    }

    public function testBulletsSansItemRetire(): void
    {
        $this->assertNull(DeckSpec::normalizeElement(['type' => 'bullets', 'items' => ['', '  ']]));
    }

    /** Tableau : des cellules TEXTE, toutes les lignes à la même largeur, bornées. */
    public function testTableauNormalise(): void
    {
        $rows = [['Région', 'CA', 'x', 'x', 'x', 'x', 'x', 'trop'], ['Ouest', 12], ['', ''], 'pas une ligne'];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ["l$i"];
        }
        $el = DeckSpec::normalizeElement(['type' => 'table', 'rows' => $rows]);

        $this->assertSame(DeckSpec::MAX_TABLE_COLS, count($el['rows'][0]));
        $this->assertSame(['Ouest', '12', '', '', '', ''], $el['rows'][1], 'Complétée à la largeur, en texte.');
        $this->assertLessThanOrEqual(DeckSpec::MAX_TABLE_ROWS, count($el['rows']));
        $this->assertTrue($el['header'], 'En-tête par défaut.');
        $this->assertSame('s', $el['size']);

        $this->assertFalse(DeckSpec::normalizeElement(['type' => 'table', 'rows' => [['a']], 'header' => false])['header']);
        $this->assertNull(DeckSpec::normalizeElement(['type' => 'table', 'rows' => [['', ''], []]]));
    }

    /**
     * Les mises en page des présentations livrées dans la conversation sont
     * acceptées dans une spec, mais jamais proposées au modèle par l'outil
     * Présentation : il ne sait pas fournir leurs données.
     */
    public function testMisesEnPageSupplementairesHorsDuChoixDuGenerateur(): void
    {
        $this->assertSame([], array_intersect(DeckSpec::LAYOUTS, DeckSpec::EXTRA_LAYOUTS));
        foreach (DeckSpec::EXTRA_LAYOUTS as $layout) {
            $deck = DeckSpec::normalize(['slides' => [['layout' => $layout, 'elements' => []]]]);
            $this->assertSame($layout, $deck['slides'][0]['layout']);
            $this->assertSame('titre-contenu', DeckSpec::normalizeOutline([['title' => 'T', 'layout' => $layout]])[0]['layout']);
        }
        $generator = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Slides/DeckGenerator.php');
        $this->assertStringNotContainsString('EXTRA_LAYOUTS', $generator);
    }

    /** Un logo s'ancre à gauche ; toute autre valeur revient au centre. */
    public function testAncrageDUneImage(): void
    {
        $logo = DeckSpec::normalizeElement(['type' => 'image', 'url' => '/assets/img/logo.png', 'fit' => 'contain', 'anchor' => 'left']);
        $this->assertSame('left', $logo['anchor']);
        $this->assertSame('center', DeckSpec::normalizeElement(['type' => 'image', 'url' => '/assets/img/logo.png', 'anchor' => 'top'])['anchor']);
    }

    public function testIdentifiantsDeSlidesDedupliques(): void
    {
        $deck = DeckSpec::normalize(['slides' => [
            ['id' => 's1', 'elements' => []],
            ['id' => 's1', 'elements' => []],
            ['id' => 's1', 'elements' => []],
        ]]);

        $ids = array_column($deck['slides'], 'id');
        $this->assertCount(3, array_unique($ids));
    }

    public function testNombreDeSlidesPlafonne(): void
    {
        $slides = array_fill(0, DeckSpec::MAX_SLIDES + 15, ['elements' => []]);
        $deck   = DeckSpec::normalize(['slides' => $slides]);

        $this->assertCount(DeckSpec::MAX_SLIDES, $deck['slides']);
    }

    public function testOptionsHorsListeRamenees(): void
    {
        $options = DeckSpec::normalizeOptions([
            'slide_count' => 999, 'text_length' => 'epique', 'tone' => 'inconnu',
        ]);

        $this->assertSame(DeckSpec::MAX_SLIDES, $options['slide_count']);
        $this->assertSame('moyen', $options['text_length']);
        $this->assertSame('professionnel', $options['tone']);
    }

    public function testAllerRetourJsonStable(): void
    {
        $deck = DeckSpec::normalize([
            'title'  => 'Sujet',
            'slides' => [['id' => 's1', 'layout' => 'titre', 'elements' => [
                ['id' => 't', 'type' => 'text', 'text' => 'Bonjour', 'x' => 8, 'y' => 34, 'w' => 84, 'h' => 20],
            ]]],
        ]);

        $this->assertSame($deck, DeckSpec::decode(DeckSpec::encode($deck)));
    }

    public function testDecodeRefuseUnContenuNonDeck(): void
    {
        $this->assertNull(DeckSpec::decode('{"title":"x"}'));
        $this->assertNull(DeckSpec::decode('pas du json'));
    }

    /**
     * Sauvegarde d'une slide éditée : l'éditeur envoie des coordonnées issues
     * de gestes à la souris, jamais supposées propres. La normalisation les
     * borne, arrondit, et accepte la suppression d'éléments.
     */
    public function testSpecIssueDeLEditeurNormalisee(): void
    {
        $deck = DeckSpec::normalize(['slides' => [[
            'id' => 's1', 'layout' => 'titre-contenu',
            'elements' => [
                // Déplacé au-delà du bord droit par un glissement.
                ['id' => 'titre', 'type' => 'text', 'text' => 'Titre déplacé',
                 'x' => 96.4567, 'y' => 12.3456, 'w' => 40, 'h' => 15],
                // Redimensionné à une taille absurde.
                ['id' => 'pts', 'type' => 'bullets', 'items' => ['a', 'b'],
                 'x' => 8, 'y' => 40, 'w' => 0.001, 'h' => 200],
            ],
        ]]]);

        $els = array_column($deck['slides'][0]['elements'], null, 'id');

        $this->assertLessThanOrEqual(100.0, $els['titre']['x'] + $els['titre']['w']);
        $this->assertSame(12.35, $els['titre']['y'], 'coordonnées arrondies au centième');
        $this->assertGreaterThanOrEqual(DeckSpec::MIN_SIZE, $els['pts']['w']);
        $this->assertLessThanOrEqual(100.0, $els['pts']['y'] + $els['pts']['h']);
    }

    public function testSlideVidableParLEditeur(): void
    {
        $deck = DeckSpec::normalize(['slides' => [['id' => 's1', 'elements' => []]]]);

        $this->assertCount(1, $deck['slides']);
        $this->assertSame([], $deck['slides'][0]['elements']);
    }

    public function testResumeLisible(): void
    {
        $deck = DeckSpec::normalize([
            'title'  => 'Mon sujet',
            'slides' => [['elements' => []], ['elements' => []]],
        ]);

        $this->assertSame('Mon sujet (2 slides)', DeckSpec::summary($deck));
    }
}
