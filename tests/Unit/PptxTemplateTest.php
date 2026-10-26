<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PptxTemplateFixture.php';

/**
 * PptxTemplate — le modèle PowerPoint de l'entreprise, analysé puis rempli.
 *
 * Le contrat qui compte : le fichier produit est un paquet OOXML COHÉRENT —
 * chaque relation pointe vers une partie qui existe, chaque partie a son type,
 * chaque slide pointe vers la bonne mise en page du modèle, et rien ne cite
 * plus les slides d'exemple retirées. Un paquet incohérent, PowerPoint
 * l'ouvre après un message « réparer » : pour un client, c'est un fichier
 * cassé. `assertPackageConsistent` vérifie ce contrat sur chaque sortie.
 *
 * Les rendus réels (PowerPoint, LibreOffice) ont été vérifiés à la main sur
 * des modèles enregistrés par PowerPoint ; ce test garde la structure, sans
 * réseau ni bibliothèque.
 */
final class PptxTemplateTest extends TestCase
{
    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive indisponible.');
        }
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            FileScratch::discard($file);
        }
        I18n::use('fr');
    }

    private function fixture(array $options = []): string
    {
        $path = FileScratch::path('modele-test', ($options['potx'] ?? false) ? 'potx' : 'pptx');
        $this->files[] = $path;

        return PptxTemplateFixture::build($path, $options);
    }

    private static function outline(): array
    {
        $md = <<<'MD'
            # Bilan 2026 & perspectives

            Comité de direction

            ## Chiffres clés

            - **4,2 M€** — de chiffre d'affaires
            - **+18 %** — de croissance
            - **320** — clients actifs

            ## Notre histoire

            - **2019** — Création à Nantes
            - **2024** — Ouverture de Lyon

            ## Ventes

            | Région | 2026 |
            |---|---|
            | Ouest | 1,5 M€ |
            | Sud | <1 M€ |

            ## Avant / après

            ### Avant
            - Saisie manuelle

            ### Après
            - Saisie automatique

            ## Méthode

            1. Écouter
            2. Prototyper
            3. Déployer

            ## Une partie

            ## Contexte

            - Marché en croissance
            - Deux concurrents

            Notes : insister sur le concurrent B.

            ## Témoignage

            > Un partenaire fiable.

            — Directrice achats
            MD;

        return MarkdownDeck::plan(MarkdownDocument::parse($md), 'bilan');
    }

    /** @return array<string, string> parties du paquet (chemin → contenu) */
    private static function unzip(string $bytes): array
    {
        $path = FileScratch::path('lecture', 'pptx');
        file_put_contents($path, $bytes);
        try {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path) === true, 'Le fichier produit doit être une archive ZIP.');
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                $parts[$name] = (string)$zip->getFromIndex($i);
            }
            $zip->close();

            return $parts;
        } finally {
            FileScratch::discard($path);
        }
    }

    /**
     * Le contrat d'un paquet OOXML : XML bien formé, relations résolues,
     * types déclarés, identifiants de slides uniques et reliés.
     *
     * @param array<string, string> $parts
     */
    private function assertPackageConsistent(array $parts): void
    {
        $ct = new DOMDocument();
        $this->assertTrue(@$ct->loadXML($parts['[Content_Types].xml'] ?? ''), '[Content_Types].xml illisible.');
        $overrides = [];
        foreach ($ct->getElementsByTagName('Override') as $o) {
            $overrides[ltrim((string)$o->getAttribute('PartName'), '/')] = (string)$o->getAttribute('ContentType');
        }
        $defaults = [];
        foreach ($ct->getElementsByTagName('Default') as $d) {
            $defaults[strtolower((string)$d->getAttribute('Extension'))] = true;
        }

        foreach ($parts as $name => $content) {
            if (str_ends_with($name, '/')) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name !== '[Content_Types].xml') {
                $this->assertTrue(isset($overrides[$name]) || isset($defaults[$ext]), "Partie sans type : $name");
            }
            if (in_array($ext, ['xml', 'rels'], true)) {
                $doc = new DOMDocument();
                $this->assertTrue(@$doc->loadXML($content), "XML mal formé : $name");
            }
        }
        foreach (array_keys($overrides) as $part) {
            $this->assertArrayHasKey($part, $parts, "Type déclaré pour une partie absente : $part");
        }

        foreach ($parts as $name => $content) {
            if (!str_ends_with($name, '.rels')) {
                continue;
            }
            // « x/_rels/y.xml.rels » décrit « x/y.xml » : les cibles sont relatives à « x ».
            $dir    = dirname(dirname($name));
            $doc    = new DOMDocument();
            $doc->loadXML($content);
            foreach ($doc->getElementsByTagName('Relationship') as $rel) {
                if (strtolower((string)$rel->getAttribute('TargetMode')) === 'external') {
                    continue;
                }
                $target = (string)$rel->getAttribute('Target');
                $path   = str_starts_with($target, '/') ? ltrim($target, '/') : ($dir === '.' || $dir === '' ? '' : $dir . '/') . $target;
                $segs   = [];
                foreach (explode('/', $path) as $seg) {
                    if ($seg === '..') {
                        array_pop($segs);
                    } elseif ($seg !== '' && $seg !== '.') {
                        $segs[] = $seg;
                    }
                }
                $this->assertArrayHasKey(implode('/', $segs), $parts, "Relation morte dans $name → $target");
            }
        }

        $pres = new DOMDocument();
        $pres->loadXML($parts['ppt/presentation.xml']);
        $rels = new DOMDocument();
        $rels->loadXML($parts['ppt/_rels/presentation.xml.rels']);
        $rids = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            $rids[(string)$rel->getAttribute('Id')] = true;
        }
        $ids = [];
        foreach ($pres->getElementsByTagNameNS(self::NS_P, 'sldId') as $sld) {
            $id = (int)$sld->getAttribute('id');
            $this->assertGreaterThanOrEqual(256, $id);
            $this->assertArrayNotHasKey($id, $ids, 'Identifiant de slide en double.');
            $ids[$id] = true;
            $this->assertArrayHasKey((string)$sld->getAttributeNS(self::NS_R, 'id'), $rids);
        }
        $this->assertStringNotContainsString('sectionLst', $parts['ppt/presentation.xml'], 'Section qui cite des slides retirées.');
    }

    /** @return list<string> chemin de la mise en page de chaque slide, dans l'ordre */
    private static function layoutsOf(array $parts): array
    {
        $pres = new DOMDocument();
        $pres->loadXML($parts['ppt/presentation.xml']);
        $rels = new DOMDocument();
        $rels->loadXML($parts['ppt/_rels/presentation.xml.rels']);
        $targets = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            $targets[(string)$rel->getAttribute('Id')] = 'ppt/' . $rel->getAttribute('Target');
        }
        $out = [];
        foreach ($pres->getElementsByTagNameNS(self::NS_P, 'sldId') as $sld) {
            $slide = $targets[(string)$sld->getAttributeNS(self::NS_R, 'id')];
            $srels = $parts[dirname($slide) . '/_rels/' . basename($slide) . '.rels'];
            preg_match('#slideLayouts/(slideLayout\d+)\.xml#', $srels, $m);
            $out[] = $m[1];
        }

        return $out;
    }

    // ------------------------------------------------------------- analyse

    public function testAnalyseDUnModeleType(): void
    {
        $info = PptxTemplate::analyze($this->fixture(['examples' => 3]));

        $this->assertTrue($info['ok']);
        $this->assertSame('16:9', $info['ratio']);
        $this->assertSame(3, $info['slides']);
        $this->assertSame(7, $info['layouts']);
        $this->assertSame('Diapositive de titre', $info['roles']['cover']['name']);
        $this->assertSame('Titre et contenu', $info['roles']['content']['name']);
        $this->assertSame('Titre de section', $info['roles']['section']['name']);
        $this->assertSame('Deux contenus', $info['roles']['two']['name']);
        $this->assertSame('Comparaison', $info['roles']['comparison']['name']);
        $this->assertSame('Titre seul', $info['roles']['titleOnly']['name']);
        foreach ($info['roles'] as $role) {
            $this->assertFalse($role['fallback']);
        }
        $this->assertSame('#0055a4', $info['colors']['accent1']);
        $this->assertSame('#000000', $info['colors']['dk1'], 'Couleur système : sa dernière valeur.');
        $this->assertSame(['major' => 'Georgia', 'minor' => 'Calibri'], $info['fonts']);
        $this->assertSame(['bg' => '#ffffff', 'text' => '#000000', 'accent' => '#0055a4', 'muted' => '#1f3864'], $info['palette']);
        $this->assertFalse($info['notes']);
    }

    /** Un modèle sans types (LibreOffice, modèles anciens) : rôles trouvés par composition. */
    public function testModeleNonTypeParComposition(): void
    {
        $info = PptxTemplate::analyze($this->fixture(['typed' => false]));

        $this->assertTrue($info['ok']);
        $this->assertSame('Titre et contenu', $info['roles']['content']['name']);
        $this->assertSame('Diapositive de titre', $info['roles']['cover']['name']);
        $this->assertSame('Titre seul', $info['roles']['titleOnly']['name']);
        $this->assertSame('Deux contenus', $info['roles']['two']['name'], 'Titre et deux corps.');
        $this->assertSame('Comparaison', $info['roles']['comparison']['name'], 'Titre et quatre corps.');
        $this->assertSame('Diapositive de titre', $info['roles']['section']['name'], 'Intercalaire : la couverture vaut mieux que le contenu.');
        foreach ($info['roles'] as $role) {
            $this->assertTrue($role['fallback'], 'Trouvé par composition : signalé « à défaut ».');
        }

        $parts = self::unzip(PptxTemplate::fill($this->fixture(['typed' => false]), self::outline()['slides']));
        $this->assertPackageConsistent($parts);
        $this->assertSame('slideLayout5', self::layoutsOf($parts)[4], 'La comparaison sur sa mise en page à quatre corps.');
    }

    public function testFichiersRefuses(): void
    {
        $pasZip = FileScratch::path('faux', 'pptx');
        $this->files[] = $pasZip;
        file_put_contents($pasZip, 'ceci n’est pas une archive');
        $this->assertFalse(PptxTemplate::analyze($pasZip)['ok']);

        $macro = PptxTemplate::analyze($this->fixture(['macro' => true]));
        $this->assertFalse($macro['ok']);
        $this->assertStringContainsString('macros', $macro['error']);

        $word = PptxTemplate::analyze($this->fixture(['mainType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml']));
        $this->assertFalse($word['ok']);
        $this->assertStringContainsString('pas une présentation PowerPoint', $word['error']);

        $traversee = PptxTemplate::analyze($this->fixture(['traversal' => true]));
        $this->assertFalse($traversee['ok'], 'Une relation qui sort du paquet est refusée.');
    }

    /** Bombe ZIP : trop d'entrées, refus avant toute lecture. */
    public function testTropDEntreesRefuse(): void
    {
        $path = $this->fixture();
        $zip = new ZipArchive();
        $zip->open($path);
        for ($i = 0; $i <= PptxTemplate::MAX_ENTRIES; $i++) {
            $zip->addFromString('ppt/media/vide' . $i . '.txt', '');
        }
        $zip->close();

        $info = PptxTemplate::analyze($path);
        $this->assertFalse($info['ok']);
        $this->assertStringContainsString('trop volumineux', $info['error']);
    }

    /** Les refus parlent la langue de l'administrateur. */
    public function testLesRefusSuiventLaLangue(): void
    {
        I18n::use('en');
        $info = PptxTemplate::analyze($this->fixture(['macro' => true]));

        $this->assertSame('This template contains macros: save it without macros (.pptx or .potx).', $info['error']);
    }

    // ----------------------------------------------------------- remplissage

    public function testRemplissageCoherentEtFideleAuxMisesEnPage(): void
    {
        $plan  = self::outline();
        $parts = self::unzip(PptxTemplate::fill($this->fixture(), $plan['slides'], [
            'title' => $plan['title'], 'footer' => 'Acme & Fils', 'lang' => 'fr-FR',
        ]));
        $this->assertPackageConsistent($parts);

        // couverture, chiffres, chronologie, tableau, comparaison, étapes,
        // intercalaire, contenu, citation — mises en page du modèle :
        // 1 titre, 2 contenu, 3 section, 5 comparaison, 6 titre seul.
        $this->assertSame(
            ['slideLayout1', 'slideLayout6', 'slideLayout6', 'slideLayout6', 'slideLayout5', 'slideLayout6', 'slideLayout3', 'slideLayout2', 'slideLayout2'],
            self::layoutsOf($parts)
        );
        $this->assertSame(
            ['titre', 'chiffres', 'chronologie', 'tableau', 'comparaison', 'etapes', '', 'titre-contenu', 'citation'],
            array_column($plan['slides'], 'layout')
        );
    }

    public function testTextesDansLesEspacesReservesDuModele(): void
    {
        $plan  = self::outline();
        $parts = self::unzip(PptxTemplate::fill($this->fixture(), $plan['slides'], ['title' => $plan['title'], 'footer' => 'Acme & Fils']));

        $cover = $parts['ppt/slides/slide1.xml'];
        $this->assertStringContainsString('<p:ph type="ctrTitle"/>', $cover);
        $this->assertStringContainsString('<p:ph type="subTitle" idx="1"/>', $cover);
        $this->assertStringContainsString('Bilan 2026 &amp; perspectives', $cover, 'Texte échappé.');
        $this->assertStringContainsString('Comité de direction', $cover);
        $this->assertStringNotContainsString('sldNum', $cover, 'Pas de numéro sur la couverture.');

        $content = $parts['ppt/slides/slide8.xml'];
        $this->assertStringContainsString('<p:ph idx="1"/>', $content, 'Le contenu va dans l’espace réservé du modèle.');
        $this->assertStringContainsString('<p:spPr/>', $content, 'Aucune géométrie : tout est hérité.');
        $this->assertStringContainsString('Marché en croissance', $content);
        $this->assertStringContainsString('type="slidenum"', $content);
        $this->assertStringContainsString('Acme &amp; Fils', $content, 'Le nom de l’entreprise en pied de page.');
        $this->assertStringContainsString('lang="fr-FR"', $content);

        $table = $parts['ppt/slides/slide4.xml'];
        $this->assertStringContainsString('drawingml/2006/table', $table);
        $this->assertStringContainsString('&lt;1 M€', $table);
        $this->assertStringContainsString('<a:schemeClr val="accent1"/>', $table, 'En-tête aux couleurs du thème.');
        preg_match('#<p:graphicFrame>.*?<a:ext cx="(\d+)"#s', $table, $ext);
        preg_match_all('#<a:gridCol w="(\d+)"/>#', $table, $cols);
        $this->assertSame((int)$ext[1], array_sum(array_map('intval', $cols[1])), 'Colonnes = largeur du tableau.');

        $kpis = $parts['ppt/slides/slide2.xml'];
        $this->assertStringContainsString('4,2 M€', $kpis);
        $this->assertStringContainsString('<a:latin typeface="+mj-lt"/>', $kpis, 'Police des titres du thème.');

        $comparison = $parts['ppt/slides/slide5.xml'];
        foreach (['idx="1"', 'idx="2"', 'idx="3"', 'idx="4"'] as $idx) {
            $this->assertStringContainsString($idx, $comparison);
        }
        $this->assertStringContainsString('Saisie automatique', $comparison);

        $quote = $parts['ppt/slides/slide9.xml'];
        $this->assertStringContainsString('« Un partenaire fiable. »', $quote);
        $this->assertStringContainsString('— Directrice achats', $quote);
        $this->assertStringContainsString('i="1"', $quote, 'Citation en italique.');
    }

    /** Fond uni clair : l'accent ressort, les chiffres le portent. Fond en image : la couleur du texte. */
    public function testCouleurDesChiffresSelonLeFond(): void
    {
        $plan = self::outline();
        $uni  = self::unzip(PptxTemplate::fill($this->fixture(), $plan['slides']))['ppt/slides/slide2.xml'];
        $this->assertMatchesRegularExpression('#b="1"[^>]*><a:solidFill><a:schemeClr val="accent1"/>#', $uni);

        $image = self::unzip(PptxTemplate::fill($this->fixture(['solidBackground' => false]), $plan['slides']))['ppt/slides/slide2.xml'];
        $this->assertMatchesRegularExpression('#b="1"[^>]*><a:solidFill><a:schemeClr val="tx1"/>#', $image);
    }

    /**
     * Les slides d'exemple du modèle disparaissent — avec leurs notes, leurs
     * commentaires, leurs sections et le diaporama personnalisé — et leur
     * texte avec elles.
     */
    public function testLesSlidesDExempleSontRetirees(): void
    {
        $plan  = self::outline();
        $parts = self::unzip(PptxTemplate::fill($this->fixture(['examples' => 3, 'notesMaster' => true]), $plan['slides']));
        $this->assertPackageConsistent($parts);

        foreach ($parts as $name => $content) {
            $this->assertStringNotContainsString('Exemple confidentiel', $content, "Reste d'exemple dans $name");
        }
        $this->assertArrayNotHasKey('ppt/comments/comment1.xml', $parts);
        $this->assertStringNotContainsString('custShowLst', $parts['ppt/presentation.xml']);
        $this->assertCount(count($plan['slides']), self::layoutsOf($parts));
        $this->assertStringContainsString('<Slides>' . count($plan['slides']) . '</Slides>', $parts['docProps/app.xml']);
    }

    /** Notes du présentateur : le masque des notes du modèle, sinon un masque créé. */
    public function testNotesDuPresentateur(): void
    {
        $plan = self::outline();

        $sans = self::unzip(PptxTemplate::fill($this->fixture(), $plan['slides']));
        $this->assertPackageConsistent($sans);
        $this->assertArrayHasKey('ppt/notesMasters/notesMaster1.xml', $sans);
        $this->assertStringContainsString('notesMasterIdLst', $sans['ppt/presentation.xml']);
        $this->assertLessThan(
            strpos($sans['ppt/presentation.xml'], '<p:sldIdLst'),
            strpos($sans['ppt/presentation.xml'], '<p:notesMasterIdLst'),
            'Ordre du schéma : masques des notes avant la liste des slides.'
        );
        $notes = array_values(array_filter(array_keys($sans), static fn (string $n): bool => str_starts_with($n, 'ppt/notesSlides/notesSlide')));
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('insister sur le concurrent B.', $sans[$notes[0]]);

        $avec = self::unzip(PptxTemplate::fill($this->fixture(['notesMaster' => true]), $plan['slides']));
        $this->assertPackageConsistent($avec);
        $this->assertSame(1, substr_count($avec['ppt/presentation.xml'], '<p:notesMasterId '), 'Celui du modèle, pas un second.');
    }

    /** Un modèle .potx devient une présentation : PowerPoint ouvrirait sinon un « nouveau document ». */
    public function testUnModelePotxDevientUnePresentation(): void
    {
        $parts = self::unzip(PptxTemplate::fill($this->fixture(['potx' => true]), self::outline()['slides']));
        $this->assertPackageConsistent($parts);

        $this->assertStringContainsString('presentationml.presentation.main+xml', $parts['[Content_Types].xml']);
        $this->assertStringNotContainsString('template.main+xml', $parts['[Content_Types].xml']);
    }

    /** Le titre du document suit la présentation, le modèle d'origine n'est pas touché. */
    public function testProprietesEtModeleIntact(): void
    {
        $template = $this->fixture();
        $before   = hash_file('sha256', $template);
        $parts    = self::unzip(PptxTemplate::fill($template, self::outline()['slides'], ['title' => 'Bilan 2026']));

        $this->assertStringContainsString('<dc:title>Bilan 2026</dc:title>', $parts['docProps/core.xml']);
        $this->assertSame($before, hash_file('sha256', $template), 'Le modèle déposé ne doit jamais être modifié.');
    }

    public function testModeleIntrouvable(): void
    {
        $this->expectException(PptxTemplateException::class);
        PptxTemplate::fill('/inexistant/modele.pptx', self::outline()['slides']);
    }
}
