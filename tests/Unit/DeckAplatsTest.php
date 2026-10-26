<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Style « aplats » des présentations livrées dans la conversation.
 *
 * Le moteur d'origine posait du texte noir sur fond blanc, slide après slide :
 * rien n'était cassé, tout était fade. Le style « aplats » donne à chaque
 * forme de contenu sa mise en page (couverture et conclusion sur fond sombre,
 * points en cartes, chiffres sur un bandeau, étapes en pastilles), à partir de
 * la SEULE couleur d'accent : les teintes en sont dérivées (DeckSpec), donc la
 * présentation prend la couleur de l'entreprise sans rien recalculer ailleurs.
 *
 * Ce que ce test garde : les teintes restent lisibles quelle que soit la
 * couleur choisie, chaque mise en page tient dans la slide et dans les bornes
 * de la spec, un mot ou un chiffre n'est jamais coupé en deux, et les trois
 * rendus (HTML, PowerPoint, PDF) savent dessiner les formes nouvelles.
 */
final class DeckAplatsTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    /** @return array<string, mixed> */
    private static function aplats(array $item, int $index = 1, int $total = 8, array $brand = []): array
    {
        return LayoutEngine::build($item + ['id' => 's' . ($index + 1), 'points' => []], $index, $total, false, $brand, LayoutEngine::STYLE_APLATS);
    }

    /** @return list<array<string, mixed>> */
    private static function byId(array $slide, string $prefix): array
    {
        return array_values(array_filter($slide['elements'], static fn (array $e): bool => str_starts_with((string)$e['id'], $prefix)));
    }

    // ------------------------------------------------------------ teintes

    /** Les teintes viennent de l'accent, jamais de la spec reçue : un accent changé les entraîne. */
    public function testLesTeintesSontRecalculeesDepuisLAccent(): void
    {
        $theme = DeckSpec::normalizeTheme(['accent' => '#dc2626', 'deep' => '#000000', 'tint' => '#00ff00']);

        $this->assertSame(DeckSpec::derivedColors('#dc2626', $theme['bg']), array_intersect_key($theme, DeckSpec::derivedColors('#dc2626', $theme['bg'])));
        $this->assertNotSame('#000000', $theme['deep'], 'Une teinte posée à la main est ignorée.');
        $this->assertSame($theme, DeckSpec::normalizeTheme($theme), 'Normaliser deux fois ne change rien.');
    }

    /**
     * Quelle que soit la couleur de l'entreprise, ce qui est écrit sur une
     * teinte se lit : valeurs en accent sur fond et sur teinte claire (4,5:1),
     * texte sur l'accent et sur le fond sombre.
     */
    public function testLesTeintesRestentLisiblesQuelQueSoitLAccent(): void
    {
        foreach (['#2563eb', '#facc15', '#22c55e', '#f97316', '#0f172a', '#e5e7eb', '#7c3aed', '#14b8a6'] as $accent) {
            foreach (['#ffffff', '#0f172a'] as $bg) {
                $c = DeckSpec::derivedColors($accent, $bg);
                $this->assertGreaterThanOrEqual(4.5, DeckSpec::contrast($c['accent_ink'], $bg), "$accent sur $bg : valeur sur le fond");
                $this->assertGreaterThanOrEqual(4.5, DeckSpec::contrast($c['accent_ink'], $c['tint']), "$accent sur $bg : valeur sur la teinte");
                // Blanc ou noir, le meilleur des deux : au pire 4,29:1, sur du texte grand et gras.
                $this->assertGreaterThanOrEqual(4.2, DeckSpec::contrast($c['on_accent'], $accent), "$accent : texte sur l'accent");
                $this->assertGreaterThanOrEqual(4.5, DeckSpec::contrast($c['on_deep'], $c['deep']), "$accent : texte sur le fond sombre");
            }
        }
        $this->assertSame('#141414', DeckSpec::derivedColors('#facc15', '#ffffff')['on_accent'], 'Un jaune reçoit du noir.');
        $this->assertSame('#ffffff', DeckSpec::derivedColors('#2563eb', '#ffffff')['on_accent']);
    }

    /** Les couleurs nommées passent la normalisation ; un nom inconnu, non. */
    public function testCouleursNommeesEtFormesNouvelles(): void
    {
        $slide = DeckSpec::normalizeSlide(['id' => 's1', 'layout' => 'titre-contenu', 'elements' => [
            ['id' => 'a', 'type' => 'shape', 'shape' => 'round', 'fill' => 'deep', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10],
            ['id' => 'b', 'type' => 'shape', 'shape' => 'circle', 'fill' => 'tint', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10],
            ['id' => 'c', 'type' => 'shape', 'shape' => 'etoile', 'fill' => 'arc-en-ciel', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10],
            ['id' => 'd', 'type' => 'text', 'text' => 'Bonjour', 'color' => 'on-deep', 'size' => 'xxxl', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10],
            ['id' => 'e', 'type' => 'text', 'text' => 'Bonjour', 'color' => 'fuchsia', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10],
            ['id' => 'f', 'type' => 'bullets', 'items' => ['un'], 'marker' => 'on-deep-accent', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10],
        ]], 0);
        $els = array_column($slide['elements'], null, 'id');

        $this->assertSame(['round', 'deep'], [$els['a']['shape'], $els['a']['fill']]);
        $this->assertSame(['circle', 'tint'], [$els['b']['shape'], $els['b']['fill']]);
        $this->assertSame('rect', $els['c']['shape'], 'Forme inconnue : un rectangle.');
        $this->assertNotSame('arc-en-ciel', $els['c']['fill']);
        $this->assertSame(['on-deep', 'xxxl'], [$els['d']['color'], $els['d']['size']]);
        $this->assertSame('', $els['e']['color'] ?? '', 'Nom de couleur inconnu : ignoré.');
        $this->assertSame('on-deep-accent', $els['f']['marker']);
        $this->assertContains('cartes', DeckSpec::EXTRA_LAYOUTS);
        $this->assertNotContains('cartes', DeckSpec::LAYOUTS, 'Le générateur de l’outil Présentation ne sait pas fournir ses données.');
    }

    // --------------------------------------------------------- mises en page

    /** @return array<string, array<string, mixed>> une entrée de plan par mise en page */
    private static function items(): array
    {
        $long = 'Une phrase assez longue pour remplir une ligne entière de la colonne de droite';

        return [
            'titre'         => ['title' => 'Offre MIA 2026', 'points' => ['L’assistant IA souverain des PME et ETI'], 'layout' => 'titre'],
            'section'       => ['title' => 'Le constat', 'layout' => 'section', 'part' => 3],
            'titre-contenu' => ['title' => 'Notre réponse : MIA', 'points' => ['un', 'deux', 'trois', $long], 'layout' => 'titre-contenu'],
            'trop-long'     => ['title' => 'Beaucoup', 'points' => array_fill(0, 6, $long . ' ' . $long), 'layout' => 'titre-contenu'],
            'deux-colonnes' => ['title' => 'Ce que MIA relie', 'points' => ['Gmail', 'Drive', 'Agenda', 'Slack', 'n8n', 'MyBrain', 'SharePoint', 'Tâches'], 'layout' => 'deux-colonnes'],
            'conclusion'    => ['title' => 'Prochaines étapes', 'points' => ['un', 'deux', 'trois'], 'layout' => 'conclusion'],
            'citation'      => ['title' => 'MIA a changé notre façon de travailler.', 'points' => ['Directrice commerciale'], 'layout' => 'citation'],
            'chiffres'      => ['title' => 'Chiffres', 'layout' => 'chiffres', 'kpis' => [
                ['value' => '2 400', 'label' => 'utilisateurs'], ['value' => '35 %', 'label' => 'de temps gagné'],
                ['value' => '9', 'label' => 'fournisseurs'], ['value' => '4 sem.', 'label' => 'pour déployer'],
            ]],
            'chiffres-6'    => ['title' => 'Chiffres', 'layout' => 'chiffres', 'kpis' => array_fill(0, 6, ['value' => '12 %', 'label' => 'légende'])],
            'tableau'       => ['title' => 'Tarifs', 'layout' => 'tableau', 'intro' => 'Hors taxes.', 'table' => ['header' => true, 'rows' => [['Formule', 'Prix'], ['Essentiel', '19 €'], ['Entreprise', '29 €']]]],
            'comparaison'   => ['title' => 'Deux formules', 'layout' => 'comparaison', 'columns' => [['title' => 'Essentiel', 'points' => ['a', 'b']], ['title' => 'Entreprise', 'points' => ['c']]]],
            'etapes'        => ['title' => 'Déploiement', 'layout' => 'etapes', 'steps' => ['Atelier de cadrage', 'Configuration et connecteurs', 'Formation', 'Suivi']],
            'chronologie'   => ['title' => 'Feuille de route', 'layout' => 'chronologie', 'events' => [['date' => '2025', 'label' => 'Pilote'], ['date' => 'Mars 2026', 'label' => 'Équipe équipée']]],
            'cartes-2'      => ['title' => 'Ce qui freine', 'layout' => 'cartes', 'cards' => array_fill(0, 2, ['term' => 'Temps perdu', 'text' => 'Six heures par semaine'])],
            'cartes-3'      => ['title' => 'Ce qui freine', 'layout' => 'cartes', 'cards' => array_fill(0, 3, ['term' => 'Temps perdu', 'text' => 'Six heures par semaine'])],
            'cartes-6'      => ['title' => 'Ce qui freine', 'layout' => 'cartes', 'cards' => array_fill(0, 6, ['term' => 'Temps perdu', 'text' => ''])],
        ];
    }

    /** Chaque mise en page tient dans la slide, sous le plafond d'éléments, et passe la normalisation entière. */
    public function testChaqueMiseEnPageTientDansLaSlide(): void
    {
        $brand = ['logo' => '/assets/img/logo.png', 'name' => 'Acme', 'legal' => 'Acme SAS — Document interne'];
        foreach (self::items() as $cas => $item) {
            foreach ([[], $brand] as $marque) {
                $slide = self::aplats($item, $cas === 'titre' ? 0 : 3, 12, $marque);
                $this->assertNotEmpty($slide['elements'], $cas);
                $this->assertLessThanOrEqual(DeckSpec::MAX_ELEMENTS_PER_SLIDE, count($slide['elements']), $cas);
                foreach ($slide['elements'] as $el) {
                    $this->assertGreaterThanOrEqual(0.0, $el['x'], "$cas — {$el['id']}");
                    $this->assertGreaterThanOrEqual(0.0, $el['y'], "$cas — {$el['id']}");
                    $this->assertLessThanOrEqual(100.01, $el['x'] + $el['w'], "$cas — {$el['id']}");
                    $this->assertLessThanOrEqual(100.01, $el['y'] + $el['h'], "$cas — {$el['id']}");
                }
                $this->assertSame($slide, DeckSpec::normalizeSlide($slide, 3), "$cas : rien de perdu ni de changé à la normalisation.");
            }
        }
    }

    /** Le style d'origine reste celui de l'outil Présentation : rien de nommé, rien d'arrondi. */
    public function testLeStyleClassiqueNeChangePas(): void
    {
        $item  = self::items()['titre-contenu'] + ['id' => 's2'];
        $slide = LayoutEngine::build($item, 1, 8, false);
        foreach ($slide['elements'] as $el) {
            $this->assertNotContains($el['shape'] ?? 'rect', ['round', 'circle'], $el['id']);
            $this->assertNotContains($el['fill'] ?? '', ['deep', 'tint', 'soft'], $el['id']);
        }
    }

    /** Couverture et conclusion sur le fond sombre ; l'intercalaire porte le numéro de SA partie. */
    public function testFondsSombresEtNumeroDePartie(): void
    {
        $couverture = self::aplats(self::items()['titre'], 0);
        $this->assertSame('deep', self::byId($couverture, 'fond')[0]['fill']);
        $this->assertSame('on-deep', self::byId($couverture, 'titre')[0]['color']);

        $section = self::aplats(self::items()['section']);
        $this->assertSame('03', self::byId($section, 'num')[0]['text']);
        $this->assertSame('accent', self::byId($section, 'fond')[0]['fill']);

        $md = "# Offre\n\nSous-titre\n\n## Constat\n\n## Problème\n\n- a\n- b\n- c\n\n## Réponse\n\n## Solution\n\n- d\n- e\n- f";
        $deck = MarkdownDeck::build(MarkdownDocument::parse($md), 'offre');
        $parts = [];
        foreach ($deck['slides'] as $slide) {
            if ($slide['layout'] === 'section') {
                $parts[] = self::byId($slide, 'num')[0]['text'];
            }
        }
        $this->assertSame(['01', '02'], $parts, 'Numérotées par partie, pas par position dans le deck.');
    }

    /** Sur le fond sombre, le logo se pose sur une pastille blanche : un logo sombre y disparaîtrait. */
    public function testLeLogoSurFondSombreAUnePastille(): void
    {
        $brand = ['logo' => '/assets/img/logo.png', 'name' => 'Acme'];
        $couverture = self::aplats(self::items()['titre'], 0, 8, $brand);
        $this->assertSame('#ffffff', self::byId($couverture, 'pastille-logo')[0]['fill']);
        $this->assertSame('round', self::byId($couverture, 'pastille-logo')[0]['shape']);

        $contenu = self::aplats(self::items()['titre-contenu'], 2, 8, $brand);
        $this->assertSame([], self::byId($contenu, 'pastille-logo'), 'Sur fond clair, le logo tel quel.');
        $this->assertCount(1, self::byId($contenu, 'logo'));
    }

    /** « Configuration » ne se coupe pas : la taille commune des étapes est réduite pour que le mot tienne. */
    public function testUnMotOuUnChiffreNEstJamaisCoupe(): void
    {
        $courtes = self::aplats(['title' => 'Étapes', 'layout' => 'etapes', 'steps' => ['Écouter', 'Tester', 'Lancer', 'Suivre']]);
        $longues = self::aplats(self::items()['etapes']);
        $this->assertSame('m', self::byId($courtes, 'etape1')[0]['size']);
        $this->assertNotSame('m', self::byId($longues, 'etape2')[0]['size']);
        $this->assertSame(
            array_fill(0, 4, self::byId($longues, 'etape1')[0]['size']),
            array_column(self::byId($longues, 'etape'), 'size'),
            'Une taille commune : des étapes à des tailles différentes se liraient comme une hiérarchie.'
        );

        $chiffres = self::byId(self::aplats(self::items()['chiffres']), 'val');
        $this->assertCount(1, array_unique(array_column($chiffres, 'size')));
        $this->assertSame("2\u{00A0}400", $chiffres[0]['text'], 'Une valeur qui tient garde ses espaces insécables.');
        $this->assertSame("4\u{00A0}sem.", $chiffres[3]['text']);
    }

    /** Espace insécable devant « : ; ? ! » : jamais de deux-points seul en début de ligne. */
    public function testTypographieFrancaise(): void
    {
        $slide = self::aplats(self::items()['titre-contenu']);
        $this->assertSame("Notre réponse\u{00A0}: MIA", self::byId($slide, 'titre')[0]['text']);
        $guillemets = self::aplats(['title' => '« Bien », dit-il ?', 'points' => ['a', 'b', 'c'], 'layout' => 'titre-contenu']);
        $this->assertSame("«\u{00A0}Bien\u{00A0}», dit-il\u{00A0}?", self::byId($guillemets, 'titre')[0]['text']);
        $anglais = self::aplats(['title' => 'Our answer: MIA', 'points' => ['a', 'b', 'c'], 'layout' => 'titre-contenu']);
        $this->assertSame('Our answer: MIA', self::byId($anglais, 'titre')[0]['text'], 'Sans espace avant le deux-points : rien ne change.');
    }

    // ------------------------------------------------------------ Markdown

    /** « - **Terme** — explication » : des cartes ; des valeurs chiffrées restent des chiffres clés. */
    public function testDesTermesEnGrasFontDesCartes(): void
    {
        $md = "## Ce qui freine\n\n- **Temps perdu** — 6 h par semaine à chercher\n- **Confidentialité** — Les outils grand public posent la question du RGPD\n- **Coûts invisibles** — Aucune vue claire sur la dépense";
        $plan = MarkdownDeck::outline(MarkdownDocument::parse($md), 'x');
        $slide = $plan['slides'][0];

        $this->assertSame('cartes', $slide['layout']);
        $this->assertSame(['Temps perdu', 'Confidentialité', 'Coûts invisibles'], array_column($slide['cards'], 'term'));
        $this->assertSame('6 h par semaine à chercher', $slide['cards'][0]['text']);
        $this->assertSame('Temps perdu — 6 h par semaine à chercher', $slide['points'][0], 'Les points restent lisibles par un modèle d’entreprise.');

        $chiffres = MarkdownDeck::outline(MarkdownDocument::parse("## C\n\n- **42 %** — a\n- **12** — b"), 'x');
        $this->assertSame('chiffres', $chiffres['slides'][0]['layout']);

        $seule = MarkdownDeck::outline(MarkdownDocument::parse("## C\n\n- **Seul** — un point"), 'x');
        $this->assertNotSame('cartes', $seule['slides'][0]['layout'], 'Une carte seule : des puces.');
    }

    /** Ce que le prompt enseigne pour les cartes est ce que MarkdownDeck lit. */
    public function testLaConsigneEnseigneLesCartes(): void
    {
        $prompt = FileDelivery::promptFragment();
        $this->assertStringContainsString('« - **Terme** — explication d\'une phrase »', $prompt);
        $this->assertStringContainsString('3 à 5 puces d\'une ligne', $prompt);

        $plan = MarkdownDeck::outline(MarkdownDocument::parse("## Atouts\n\n- **Terme** — explication d'une phrase\n- **Autre terme** — explication d'une phrase"), 'x');
        $this->assertSame('cartes', $plan['slides'][0]['layout']);
    }

    /** Les présentations livrées dans la conversation sont au style « aplats ». */
    public function testLaConversationLivreLeStyleAplats(): void
    {
        $deck = MarkdownDeck::build(MarkdownDocument::parse("# Offre\n\nSous-titre\n\n## Points\n\n- a\n- b\n- c"), 'offre');
        $fills = [];
        foreach ($deck['slides'] as $slide) {
            foreach ($slide['elements'] as $el) {
                $fills[] = $el['fill'] ?? '';
            }
        }
        $this->assertContains('deep', $fills, 'Couverture sur fond sombre.');
        $this->assertContains('tint', $fills, 'Panneau teinté du contenu.');
    }

    // -------------------------------------------------------------- rendus

    /** Le rendu HTML connaît les couleurs nommées et les formes arrondies. */
    public function testLeRenduHtmlDessineLesFormesNouvelles(): void
    {
        $deck = MarkdownDeck::build(MarkdownDocument::parse(
            "# Offre\n\nSous-titre\n\n## Ce qui freine\n\n- **Temps** — a\n- **Coûts** — b\n\n## Étapes\n\n1. Écouter\n2. Tester\n3. Lancer"
        ), 'offre');
        $html = DeckRenderer::render($deck);

        $this->assertStringContainsString('border-radius:50%', $html, 'Disques.');
        $this->assertMatchesRegularExpression('/border-radius:[0-9.]+cqw/', $html, 'Cartes arrondies.');
        $this->assertStringContainsString($deck['theme']['deep'], $html);
        $this->assertSame($deck['theme']['deep'], DeckRenderer::tokenColor('deep', $deck['theme']));
        $this->assertSame($deck['theme']['on_deep_muted'], DeckRenderer::tokenColor('on-deep-muted', $deck['theme']));
        $this->assertNull(DeckRenderer::tokenColor('fuchsia', $deck['theme']));
    }

    /** Les trois rendus arrondissent au même rayon (PowerPoint : 1/6 du petit côté, sa valeur par défaut). */
    public function testLeRayonEstCommunAuxTroisRendus(): void
    {
        $this->assertEqualsWithDelta(1 / 6, DeckRenderer::ROUND_RATIO, 0.0001);
        foreach (['src/Slides/PptxExporter.php', 'src/Slides/SlidesPdfExporter.php'] as $fichier) {
            $code = (string)file_get_contents(ROOT . '/' . $fichier);
            $this->assertStringContainsString("'round'", $code, $fichier);
            $this->assertStringContainsString("'circle'", $code, $fichier);
        }
        $this->assertStringContainsString('DeckRenderer::ROUND_RATIO', (string)file_get_contents(ROOT . '/src/Slides/SlidesPdfExporter.php'));
    }
}
