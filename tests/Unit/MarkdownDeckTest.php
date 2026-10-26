<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MarkdownDeck — lecture d'une présentation écrite en Markdown.
 *
 * La convention vit à deux endroits qui doivent rester d'accord : ce que le
 * prompt ENSEIGNE au modèle (FileDelivery) et ce que cette classe LIT. Les
 * cas ci-dessous suivent la convention enseignée, puis ce que les modèles
 * écrivent aussi par habitude (`#` partout, `---` entre les slides), puis les
 * débordements : trop de points, trop de slides.
 *
 * Tout est pur : le plan, puis la spec que LayoutEngine en tire. Le fichier
 * PowerPoint lui-même est éprouvé par FileBuildersLibraryTest.
 */
final class MarkdownDeckTest extends TestCase
{
    private const PRESENTATION = <<<'MD'
        # Plan stratégique 2027

        Comité de direction — 12 octobre

        ## Contexte

        - Marché en croissance de **8 %**
        - Deux concurrents ont *levé des fonds*
        - Notre part : [18 %](https://exemple.test/part)

        Notes : insister sur la levée de fonds du concurrent B.

        ## Nos priorités

        ## Recruter

        1. Deux commerciaux
        2. Un responsable produit

        ## Ce qu'en disent nos clients

        > Un partenaire fiable, qui tient ses délais.

        — Directrice achats, Groupe Alpha

        ## Prochaines étapes

        - Valider le budget
        - Lancer les recrutements
        MD;

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    /** @return array{title: string, slides: list<array<string, mixed>>} */
    private static function plan(string $markdown, string $fallback = 'fichier'): array
    {
        return MarkdownDeck::outline(MarkdownDocument::parse($markdown), $fallback);
    }

    /** @return list<string> */
    private static function titles(array $plan): array
    {
        return array_column($plan['slides'], 'title');
    }

    // ------------------------------------------------- convention enseignée

    public function testLaConventionEnseigneeDonneUneSlideParTitre(): void
    {
        $plan = self::plan(self::PRESENTATION);

        $this->assertSame('Plan stratégique 2027', $plan['title']);
        $this->assertSame(
            ['Plan stratégique 2027', 'Contexte', 'Nos priorités', 'Recruter', 'Un partenaire fiable, qui tient ses délais.', 'Prochaines étapes'],
            self::titles($plan)
        );
        $this->assertSame(
            ['titre', 'titre-contenu', '', 'titre-contenu', 'citation', 'conclusion'],
            array_column($plan['slides'], 'layout')
        );
    }

    public function testLaCouvertureGardeSonSousTitre(): void
    {
        $couverture = self::plan(self::PRESENTATION)['slides'][0];

        $this->assertSame(['Comité de direction — 12 octobre'], $couverture['points']);
        $this->assertSame('titre', $couverture['layout']);
    }

    /** L'emphase et les liens ne survivent pas : une slide affiche du texte. */
    public function testLesPointsSontDuTexteSansSyntaxe(): void
    {
        $contexte = self::plan(self::PRESENTATION)['slides'][1];

        $this->assertSame(
            ['Marché en croissance de 8 %', 'Deux concurrents ont levé des fonds', 'Notre part : 18 %'],
            $contexte['points']
        );
    }

    public function testLesNotesDuPresentateurQuittentLaSlide(): void
    {
        $contexte = self::plan(self::PRESENTATION)['slides'][1];

        $this->assertSame('insister sur la levée de fonds du concurrent B.', $contexte['notes']);
        foreach ($contexte['points'] as $point) {
            $this->assertStringNotContainsString('Notes', $point);
        }
    }

    /** « Note : » seul est une remarque à AFFICHER, pas une note cachée. */
    public function testUneRemarqueNEstPasUneNote(): void
    {
        $slide = self::plan("## Budget\n\n- 1,2 M€\n\nNote : hors taxes.")['slides'][0];

        $this->assertSame(['1,2 M€', 'Note : hors taxes.'], $slide['points']);
        $this->assertSame('', $slide['notes']);

        foreach (['Notes du présentateur : a', 'Speaker notes: a', 'notes: a', 'Note de la présentatrice : a'] as $note) {
            $this->assertSame('a', self::plan("## T\n\n- x\n\n$note")['slides'][0]['notes'], $note);
        }
    }

    /** Un titre seul : LayoutEngine en fait un intercalaire de partie. */
    public function testUnTitreSeulFaitUnIntercalaire(): void
    {
        $deck = MarkdownDeck::build(MarkdownDocument::parse(self::PRESENTATION), 'plan');

        $this->assertSame('section', $deck['slides'][2]['layout']);
        $this->assertSame('titre', $deck['slides'][0]['layout']);
    }

    public function testUneCitationSeuleFaitUneSlideCitation(): void
    {
        $citation = self::plan(self::PRESENTATION)['slides'][4];

        $this->assertSame('citation', $citation['layout']);
        $this->assertSame(['Directrice achats, Groupe Alpha'], $citation['points']);

        // Légende écrite DANS la citation.
        $dedans = self::plan("## Avis\n\n> Excellent.\n>\n> — Paul")['slides'][0];
        $this->assertSame('Excellent.', $dedans['title']);
        $this->assertSame(['Paul'], $dedans['points']);

        // Sans légende : le titre de la slide en tient lieu.
        $sans = self::plan("## Ce que dit le client\n\n> Excellent.")['slides'][0];
        $this->assertSame(['Ce que dit le client'], $sans['points']);

        // Une citation parmi d'autres contenus n'est qu'un point de plus.
        $melange = self::plan("## Avis\n\n- Un\n\n> Excellent.")['slides'][0];
        $this->assertSame('titre-contenu', $melange['layout']);
        $this->assertSame(['Un', 'Excellent.'], $melange['points']);
    }

    /** La mise en page de clôture ne vaut que pour la DERNIÈRE slide. */
    public function testSeuleLaDerniereSlideConclut(): void
    {
        $plan = self::plan("## Synthèse\n\n- a\n\n## Suite\n\n- b");
        $this->assertSame(['titre-contenu', 'titre-contenu'], array_column($plan['slides'], 'layout'));

        $plan = self::plan("## Suite\n\n- b\n\n## En résumé\n\n- a");
        $this->assertSame(['titre-contenu', 'conclusion'], array_column($plan['slides'], 'layout'));
    }

    // --------------------------------------- ce que les modèles écrivent aussi

    /** Des `#` partout : chacun est une slide, la première n'est pas une couverture. */
    public function testDesTitresDeNiveauUnPartout(): void
    {
        $plan = self::plan("# Introduction\n\n- a\n- b\n\n# Objectifs\n\n- c");

        $this->assertSame(['Introduction', 'Objectifs'], self::titles($plan));
        $this->assertSame(['a', 'b'], $plan['slides'][0]['points'], 'Les puces restent sur leur slide.');
        $this->assertSame('titre-contenu', $plan['slides'][0]['layout']);
    }

    public function testDesTitresDeNiveauTroisSeulement(): void
    {
        $plan = self::plan("### Un\n\n- a\n\n### Deux\n\n- b", 'secours');

        $this->assertSame(['Un', 'Deux'], self::titles($plan));
        $this->assertSame('Un', $plan['title']);
    }

    /** `---` entre les slides (habitude des outils de slides en Markdown) : aucune slide vide. */
    public function testLesFiletsSeparentSansCreerDeSlideVide(): void
    {
        $plan = self::plan("# Titre\n\n---\n\n## Un\n\n- a\n\n---\n\n## Deux\n\n- b\n\n---\n");

        $this->assertSame(['Titre', 'Un', 'Deux'], self::titles($plan));
    }

    /** Des notes isolées après un filet rejoignent la slide qui précède. */
    public function testDesNotesOrphelinesRejoignentLaSlidePrecedente(): void
    {
        $plan = self::plan("## Un\n\n- a\n\n---\n\nNotes : dire bonjour.\n\n## Deux\n\n- b");

        $this->assertSame(['Un', 'Deux'], self::titles($plan));
        $this->assertSame('dire bonjour.', $plan['slides'][0]['notes']);
    }

    public function testSousTitresTableauxEtListesImbriqueesDeviennentDesPoints(): void
    {
        $slide = self::plan(
            "## Bilan\n\n### Ventes\n\n- France\n  - Nord\n\n| Région | CA | Évolution |\n|---|---|---|\n| Sud | 2 M€ | +4 % |\n| Est | | -1 % |"
        )['slides'][0];

        $this->assertSame(['Ventes', 'France', 'Nord', 'Sud : 2 M€ · +4 %', 'Est : -1 %'], $slide['points']);
    }

    /** Un document sans titre : le nom du fichier titre la présentation. */
    public function testSansTitreLeNomDuFichier(): void
    {
        $plan = self::plan("- a\n- b", 'budget-2027');

        $this->assertSame('budget-2027', $plan['title']);
        $this->assertSame([''], self::titles($plan));
        $this->assertSame(['a', 'b'], $plan['slides'][0]['points']);
    }

    // ------------------------------------------- mises en page reconnues

    public function testDesChiffresClesEnGrasFontUneSlideChiffres(): void
    {
        $slide = self::plan("## Résultats\n\n- **4,2 M€** — de chiffre d'affaires\n- **+18 %** — de croissance\n- **320** : clients actifs")['slides'][0];

        $this->assertSame('chiffres', $slide['layout']);
        $this->assertSame([
            ['value' => '4,2 M€', 'label' => "de chiffre d'affaires"],
            ['value' => '+18 %', 'label' => 'de croissance'],
            ['value' => '320', 'label' => 'clients actifs'],
        ], $slide['kpis']);
    }

    /** Toutes les valeurs sont des dates : une chronologie, pas des chiffres. */
    public function testDesDatesFontUneChronologie(): void
    {
        $slide = self::plan("## Historique\n\n- **2019** — Création\n- **T3 2024** — Lyon\n- **Mars 2026** — Levée")['slides'][0];

        $this->assertSame('chronologie', $slide['layout']);
        $this->assertSame(['2019', 'T3 2024', 'Mars 2026'], array_column($slide['events'], 'date'));
    }

    /** La moindre ligne d'une autre forme, une valeur sans chiffre, trop ou trop peu : des points. */
    public function testDesChiffresMelesRestentDesPoints(): void
    {
        $cas = [
            "- **42 %** — de croissance\n- Un point ordinaire",
            "- **Fiable** — selon nos clients\n- **42 %** — de croissance",
            "- **42 %** — de croissance",
            "- **1** — a\n- **2** — b\n- **3** — c\n- **4** — d\n- **5** — e\n- **6** — f\n- **7** — g",
            "- **42 %** — a\n  - détail\n- **12** — b",
        ];
        foreach ($cas as $markdown) {
            $layout = self::plan("## T\n\n$markdown")['slides'][0]['layout'];
            $this->assertNotSame('chiffres', $layout, $markdown);
            $this->assertNotSame('chronologie', $layout, $markdown);
        }
    }

    public function testUnTableauSeulDevientUnTableau(): void
    {
        $slide = self::plan("## Ventes\n\nPar région, en 2026.\n\n| Région | CA |\n|---|---|\n| Ouest | **1,5 M€** |\n| Sud | 0,9 M€ |\n\nNotes : citer la météo.")['slides'][0];

        $this->assertSame('tableau', $slide['layout']);
        $this->assertSame('Par région, en 2026.', $slide['intro']);
        $this->assertSame(['header' => true, 'rows' => [['Région', 'CA'], ['Ouest', '1,5 M€'], ['Sud', '0,9 M€']]], $slide['table']);
        $this->assertSame('citer la météo.', $slide['notes']);
    }

    /** Trop de lignes : découpé, l'en-tête répété, l'introduction et les notes sur la première. */
    public function testUnLongTableauEstDecoupeAvecSonEnTete(): void
    {
        $rows = '';
        for ($i = 1; $i <= MarkdownDeck::TABLE_ROWS_PER_SLIDE + 3; $i++) {
            $rows .= "| Ligne $i | $i |\n";
        }
        $plan = self::plan("## Détail\n\nIntro.\n\n| Nom | Valeur |\n|---|---|\n$rows\nNotes : vite.");

        $this->assertSame(['Détail', 'Détail (suite)'], self::titles($plan));
        $this->assertCount(MarkdownDeck::TABLE_ROWS_PER_SLIDE + 1, $plan['slides'][0]['table']['rows']);
        $this->assertSame(['Nom', 'Valeur'], $plan['slides'][1]['table']['rows'][0]);
        $this->assertCount(4, $plan['slides'][1]['table']['rows']);
        $this->assertSame('', $plan['slides'][1]['intro']);
        $this->assertSame('', $plan['slides'][1]['notes']);
    }

    /** Trop de colonnes pour une slide : le tableau reste en points. */
    public function testUnTableauTropLargeResteEnPoints(): void
    {
        $slide = self::plan("## Large\n\n| a | b | c | d | e | f | g |\n|---|---|---|---|---|---|---|\n| 1 | 2 | 3 | 4 | 5 | 6 | 7 |")['slides'][0];

        $this->assertNotSame('tableau', $slide['layout']);
    }

    public function testDeuxSousTitresFontUneComparaison(): void
    {
        $slide = self::plan("## Avant / après\n\n### Avant\n\n- Saisie manuelle\n- Erreurs\n\n### Après\n\nTout est automatique.")['slides'][0];

        $this->assertSame('comparaison', $slide['layout']);
        $this->assertSame([
            ['title' => 'Avant', 'points' => ['Saisie manuelle', 'Erreurs']],
            ['title' => 'Après', 'points' => ['Tout est automatique.']],
        ], $slide['columns']);

        $trois = self::plan("## Options\n\n### A\n\n- a\n\n### B\n\n- b\n\n### C\n\n- c")['slides'][0];
        $this->assertNotSame('comparaison', $trois['layout']);
    }

    public function testUneListeNumeroteeSeuleFaitDesEtapes(): void
    {
        $slide = self::plan("## Méthode\n\n1. Écouter\n2. Prototyper\n3. Déployer")['slides'][0];
        $this->assertSame('etapes', $slide['layout']);
        $this->assertSame(['Écouter', 'Prototyper', 'Déployer'], $slide['steps']);

        foreach ([
            "1. Écouter\n2. Déployer",
            "1. Écouter\n2. Prototyper\n   - vite\n3. Déployer",
            "1. Écouter\n2. " . str_repeat('long ', 40) . "\n3. Déployer",
            "Intro.\n\n1. Écouter\n2. Prototyper\n3. Déployer",
            "- Écouter\n- Prototyper\n- Déployer",
        ] as $markdown) {
            $this->assertNotSame('etapes', self::plan("## T\n\n$markdown")['slides'][0]['layout'], $markdown);
        }
    }

    /** Chaque mise en page arrive jusqu'à la spec : chiffres, tableau, marque de l'entreprise. */
    public function testLaSpecPorteLesDonneesEtLaMarque(): void
    {
        $markdown = "# Bilan\n\n## Chiffres\n\n- **42 %** — a\n- **12** — b\n\n## Ventes\n\n| Région | CA |\n|---|---|\n| Ouest | 1 M€ |\n\n## Suite\n\n- point";
        $deck = MarkdownDeck::build(
            MarkdownDocument::parse($markdown),
            'bilan',
            ['logo' => '/assets/img/logo.png', 'name' => 'Acme'],
            ['accent' => '#0055a4']
        );
        $this->assertSame($deck, DeckSpec::normalize($deck));
        $this->assertSame('#0055a4', $deck['theme']['accent']);

        $find = static fn (array $slide, callable $test): array => array_values(array_filter($slide['elements'], $test));

        $kpis = $find($deck['slides'][1], static fn (array $e): bool => ($e['role'] ?? '') === 'chiffre');
        // Une valeur qui tient sur une ligne garde ses espaces INSÉCABLES :
        // « 42 / % » ne se coupe dans aucun des trois rendus.
        $this->assertSame(["42\u{00A0}%", '12'], array_column($kpis, 'text'));

        $table = $find($deck['slides'][2], static fn (array $e): bool => $e['type'] === 'table');
        $this->assertCount(1, $table);
        $this->assertSame([['Région', 'CA'], ['Ouest', '1 M€']], $table[0]['rows']);

        $logo = $find($deck['slides'][0], static fn (array $e): bool => $e['type'] === 'image');
        $this->assertSame('/assets/img/logo.png', $logo[0]['url'] ?? null, 'Logo de l\'entreprise sur la couverture.');
        $this->assertSame('contain', $logo[0]['fit'] ?? null, 'Un logo n\'est jamais rogné.');

        $footer = $find($deck['slides'][3], static fn (array $e): bool => $e['type'] === 'image');
        $this->assertSame('left', $footer[0]['anchor'] ?? null, 'Logo de pied de page calé à gauche.');

        // Sans logo, le nom de l'entreprise en tient lieu.
        $sansLogo = MarkdownDeck::build(MarkdownDocument::parse($markdown), 'bilan', ['logo' => '', 'name' => 'Acme']);
        $this->assertContains('Acme', array_column($sansLogo['slides'][0]['elements'], 'text'));
        $this->assertContains('Acme', array_column($sansLogo['slides'][3]['elements'], 'text'));
    }

    // ------------------------------------------------------- débordements

    /** Trop de points : la slide est découpée, jamais amputée. */
    public function testUneSlideTropChargeeEstDecoupee(): void
    {
        $points = [];
        for ($i = 1; $i <= 9; $i++) {
            $points[] = "- Point numéro $i, avec assez de texte pour ne plus tenir sur deux colonnes";
        }
        $plan = self::plan("## Actions\n\n" . implode("\n", $points) . "\n\nNotes : aller vite.");

        $this->assertSame(['Actions', 'Actions (suite)'], self::titles($plan));
        $this->assertCount(MarkdownDeck::MAX_POINTS, $plan['slides'][0]['points']);
        $this->assertCount(3, $plan['slides'][1]['points']);
        $this->assertSame('aller vite.', $plan['slides'][0]['notes']);
        $this->assertSame('', $plan['slides'][1]['notes'], 'Les notes restent sur la première slide.');

        I18n::use('en');
        $this->assertSame('Actions (continued)', self::plan("## Actions\n\n" . implode("\n", $points))['slides'][1]['title']);
    }

    /** Des points COURTS au-delà de six tiennent sur deux colonnes. */
    public function testDesPointsCourtsTiennentSurDeuxColonnes(): void
    {
        $plan = self::plan("## Équipe\n\n- Ana\n- Bob\n- Cléo\n- Dan\n- Eva\n- Fred\n- Gina\n- Hugo");

        $this->assertCount(1, $plan['slides']);
        $this->assertSame('deux-colonnes', $plan['slides'][0]['layout']);
    }

    /** Un texte long est coupé à un mot, avec une ellipse — jamais au milieu d'un mot. */
    public function testUnPointTropLongEstCoupeAUnMot(): void
    {
        $long  = str_repeat('développement ', 60);
        $point = self::plan("## T\n\n- $long")['slides'][0]['points'][0];

        $this->assertLessThanOrEqual(DeckSpec::MAX_BULLET_LEN, mb_strlen($point));
        $this->assertStringEndsWith('développement…', $point);
    }

    /** Au-delà du plafond, la fabrication est refusée — les formats qui gardent tout sont proposés. */
    public function testTropDeSlidesRefuseAvecDesIssues(): void
    {
        $markdown = '';
        for ($i = 1; $i <= DeckSpec::MAX_SLIDES + 1; $i++) {
            $markdown .= "## Slide $i\n\n- point\n\n";
        }

        try {
            MarkdownDeck::build(MarkdownDocument::parse($markdown), 'long');
            $this->fail('Une présentation trop longue doit être refusée.');
        } catch (FileBuildException $e) {
            $this->assertSame(FileBuildException::TOO_LARGE, $e->kind());
            $this->assertSame(['docx', 'md'], $e->alternatives());
            $this->assertStringContainsString((string)(DeckSpec::MAX_SLIDES + 1) . ' slides', $e->getMessage());
        }
    }

    public function testRienAMettreEnSlides(): void
    {
        $this->expectException(FileBuildException::class);
        MarkdownDeck::build(MarkdownDocument::parse("---\n\n---"), 'vide');
    }

    // ------------------------------------------------------ spec produite

    /** La spec suit le chemin de l'outil Présentation : normalisée, thème par défaut, aucune image. */
    public function testLaSpecEstCelleDeLOutilPresentation(): void
    {
        $deck = MarkdownDeck::build(MarkdownDocument::parse(self::PRESENTATION), 'plan');

        $this->assertSame($deck, DeckSpec::normalize($deck), 'Spec déjà normalisée.');
        $this->assertSame('Plan stratégique 2027', $deck['title']);
        $this->assertSame(DeckSpec::DEFAULT_THEME, $deck['theme']['preset']);
        $this->assertCount(6, $deck['slides']);

        $types = [];
        foreach ($deck['slides'] as $slide) {
            foreach ($slide['elements'] as $element) {
                $types[$element['type']] = true;
            }
        }
        $this->assertArrayNotHasKey('image', $types, 'Rien ne générerait une image ici.');

        // Numéro « 2 / 6 » sur une slide de contenu, notes conservées.
        $textes = array_column(array_filter($deck['slides'][1]['elements'], static fn ($e) => $e['type'] === 'text'), 'text');
        $this->assertContains('2 / 6', $textes);
        $this->assertSame('insister sur la levée de fonds du concurrent B.', $deck['slides'][1]['notes']);
    }
}
