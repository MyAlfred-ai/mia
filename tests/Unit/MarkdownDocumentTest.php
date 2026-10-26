<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Document livré en Markdown → structure, puis trois rendus sans bibliothèque :
 * RTF (écrit à la main), HTML (pour le moteur PDF) et texte brut.
 *
 * Une seule analyse, plusieurs rendus : c'est ce qui garantit qu'un tableau
 * présent dans le PDF l'est aussi dans le RTF et le Word. Les tests portent
 * donc d'abord sur la STRUCTURE, puis sur ce que chaque format a de propre —
 * et, pour le RTF, sur les trois règles dont l'oubli rend le fichier illisible.
 */
final class MarkdownDocumentTest extends TestCase
{
    private const DOCUMENT = <<<'MD'
        # Compte rendu — Projet Élan

        **Date :** 30 septembre 2026
        Lieu : Nantes

        ## 1. Participants

        - Alex Martin (*direction*)
        - Zoé Lefèvre
          - suppléante : Inès
          - ~~ancien contact~~
        - Équipe « données » 😀

        ## 2. Décisions

        1. Lancer la phase 2
        2. Budget : voir [le tableau](https://exemple.test/budget)

        | Poste | Montant | État |
        |:------|--------:|:----:|
        | Loyer | 1 200 € | **OK** |
        | Licences | 4 200,50 € | à voir |

        > Citation sur
        > deux lignes.

        ```python
        print("a { b } \\ c")
        ```

        ---

        - [x] Fait
        - [ ] À faire
        MD;

    /** Résumé lisible d'une suite de runs : « B:gras | I:ital | texte ». */
    private static function resume(array $runs): string
    {
        $morceaux = [];
        foreach ($runs as $run) {
            if ($run['break']) {
                $morceaux[] = '⏎';
                continue;
            }
            $style = ($run['bold'] ? 'B' : '') . ($run['italic'] ? 'I' : '') . ($run['strike'] ? 'S' : '')
                . ($run['code'] ? 'C' : '') . ($run['link'] !== null ? '<' . $run['link'] . '>' : '');
            $morceaux[] = ($style !== '' ? $style . ':' : '') . $run['text'];
        }

        return implode(' | ', $morceaux);
    }

    /** @return list<array<string, mixed>> */
    private static function blocs(): array
    {
        return MarkdownDocument::parse(self::DOCUMENT);
    }

    // ---------------------------------------------------------- structure

    public function testStructureDUnDocument(): void
    {
        $blocs = self::blocs();

        $this->assertSame(
            ['heading', 'paragraph', 'heading', 'list', 'heading', 'list', 'table', 'quote', 'code', 'rule', 'list'],
            array_column($blocs, 'type')
        );
        $this->assertSame([1, 2, 2], array_values(array_map(
            static fn (array $b): int => $b['level'],
            array_filter($blocs, static fn (array $b): bool => $b['type'] === 'heading')
        )));
        $this->assertSame('Compte rendu — Projet Élan', MarkdownDocument::title($blocs));
    }

    public function testParagrapheEtSautDeLigne(): void
    {
        // Un retour simple est un saut de ligne, comme dans l'aperçu du chat.
        $this->assertSame(
            'B:Date : |  30 septembre 2026 | ⏎ | Lieu : Nantes',
            self::resume(self::blocs()[1]['inlines'])
        );
    }

    public function testListeImbriquee(): void
    {
        $liste = self::blocs()[3];

        $this->assertFalse($liste['ordered']);
        $this->assertCount(3, $liste['items']);
        $this->assertSame('Alex Martin ( | I:direction | )', self::resume($liste['items'][0]['inlines']));
        $this->assertSame([], $liste['items'][0]['children']);

        $sousListe = $liste['items'][1]['children'][0];
        $this->assertSame('list', $sousListe['type']);
        $this->assertFalse($sousListe['ordered']);
        $this->assertSame(
            ['suppléante : Inès', 'S:ancien contact'],
            array_map(static fn (array $i): string => self::resume($i['inlines']), $sousListe['items'])
        );
        $this->assertSame('Équipe « données » 😀', self::resume($liste['items'][2]['inlines']));
    }

    public function testListeNumeroteeEtLien(): void
    {
        $liste = self::blocs()[5];

        $this->assertTrue($liste['ordered']);
        $this->assertSame(1, $liste['start']);
        $this->assertSame(
            'Budget : voir  | <https://exemple.test/budget>:le tableau',
            self::resume($liste['items'][1]['inlines'])
        );
    }

    public function testUneListeNumeroteeGardeSonPremierNumero(): void
    {
        $liste = MarkdownDocument::parse("3. trois\n4. quatre")[0];

        $this->assertTrue($liste['ordered']);
        $this->assertSame(3, $liste['start']);
        $this->assertCount(2, $liste['items']);
    }

    public function testDeuxListesDeTypesDifferentsNeSeMelangentPas(): void
    {
        $blocs = MarkdownDocument::parse("- puce\n- puce\n\n1. un\n2. deux");

        $this->assertSame(['list', 'list'], array_column($blocs, 'type'));
        $this->assertFalse($blocs[0]['ordered']);
        $this->assertTrue($blocs[1]['ordered']);
    }

    public function testTableau(): void
    {
        $table = self::blocs()[6];

        $this->assertSame(['left', 'right', 'center'], $table['align']);
        $this->assertSame(
            ['Poste', 'Montant', 'État'],
            array_map(static fn (array $c): string => self::resume($c), $table['header'])
        );
        $this->assertCount(2, $table['rows']);
        $this->assertSame(
            ['Loyer', '1 200 €', 'B:OK'],
            array_map(static fn (array $c): string => self::resume($c), $table['rows'][0])
        );
    }

    /** Une ligne plus courte ou plus longue que l'en-tête ne décale pas le tableau. */
    public function testLesLignesDUnTableauSontRameneesALaLargeurDeLEnTete(): void
    {
        $table = MarkdownDocument::parse("| a | b | c |\n|---|---|---|\n| 1 |\n| 1 | 2 | 3 | 4 |")[0];

        $this->assertCount(3, $table['rows'][0]);
        $this->assertCount(3, $table['rows'][1]);
        $this->assertSame('', self::resume($table['rows'][0][2]));
    }

    public function testCitationBlocDeCodeFiletEtCases(): void
    {
        $blocs = self::blocs();

        $this->assertSame('Citation sur | ⏎ | deux lignes.', self::resume($blocs[7]['blocks'][0]['inlines']));

        // Le contenu d'un bloc de code n'est JAMAIS interprété.
        $this->assertSame('python', $blocs[8]['lang']);
        $this->assertSame('print("a { b } \\\\ c")', $blocs[8]['text']);

        $this->assertSame(['type' => 'rule'], $blocs[9]);
        $this->assertSame([true, false], array_column($blocs[10]['items'], 'checked'));
        $this->assertSame('Fait', self::resume($blocs[10]['items'][0]['inlines']));
        // Une puce ordinaire n'est ni cochée ni décochée.
        $this->assertNull($blocs[3]['items'][0]['checked']);
    }

    public function testBlocDeCodeJamaisRefermeEtDelimiteurLong(): void
    {
        // Délimiteur de quatre accents : un ``` à l'intérieur est du contenu.
        $bloc = MarkdownDocument::parse("````md\navant\n```\ndedans\n```\naprès\n````\nsuite")[0];
        $this->assertSame("avant\n```\ndedans\n```\naprès", $bloc['text']);

        // Jamais refermé : tout le reste est du code, rien n'est perdu.
        $ouvert = MarkdownDocument::parse("```\nligne 1\nligne 2");
        $this->assertCount(1, $ouvert);
        $this->assertSame("ligne 1\nligne 2", $ouvert[0]['text']);
    }

    public function testTitreSouligne(): void
    {
        $blocs = MarkdownDocument::parse("Grand titre\n===========\n\nSous-titre\n----------\n\nTexte.");

        $this->assertSame(['heading', 'heading', 'paragraph'], array_column($blocs, 'type'));
        $this->assertSame([1, 2], [$blocs[0]['level'], $blocs[1]['level']]);
    }

    public function testMiseEnFormeDeLigne(): void
    {
        $cas = [
            'Un **gras**, un *italique*, un ~~barré~~ et du `code`.'
                => 'Un  | B:gras | , un  | I:italique | , un  | S:barré |  et du  | C:code | .',
            '***gras ital*** puis __gras__ et _ital_'
                => 'BI:gras ital |  puis  | B:gras |  et  | I:ital',
            '[lien **gras** dedans](https://a.b)'
                => '<https://a.b>:lien  | B<https://a.b>:gras | <https://a.b>: dedans',
            'Wikipédia : [Page](https://fr.wikipedia.org/wiki/Page_(homonymie)) fin'
                => 'Wikipédia :  | <https://fr.wikipedia.org/wiki/Page_(homonymie)>:Page |  fin',
            'ligne 1<br>ligne 2'
                => 'ligne 1 | ⏎ | ligne 2',
            // Ce qui ressemble à de la mise en forme sans en être.
            'snake_case_name et 2*3*4 et a * b * c'
                => 'snake_case_name et 2*3*4 et a * b * c',
            'Échappé \*pas ital\* et \# pas titre'
                => 'Échappé *pas ital* et # pas titre',
            '**gras non fermé'
                => '**gras non fermé',
            '`code avec ** dedans`'
                => 'C:code avec ** dedans',
            // Entités décodées dans le texte, jamais dans le code.
            'Entité &amp; et &eacute; · `a &amp; b`'
                => 'Entité & et é ·  | C:a &amp; b',
            // Balises HTML de ligne : ignorées, leur contenu reste.
            '<span style="color:red">texte</span> <b>x</b>'
                => 'texte x',
        ];
        foreach ($cas as $markdown => $attendu) {
            $this->assertSame($attendu, self::resume(MarkdownDocument::inline((string)$markdown)), (string)$markdown);
        }
    }

    /** Un document fabriqué ne va chercher aucune ressource et n'exécute rien. */
    public function testLiensEtImagesSurs(): void
    {
        // Schéma refusé : le libellé reste, le lien disparaît.
        $this->assertSame(
            'piège',
            self::resume(MarkdownDocument::inline('[piège](javascript:alert(1))'))
        );
        $this->assertSame('fichier', self::resume(MarkdownDocument::inline('[fichier](file:///etc/passwd)')));
        $this->assertSame('données', self::resume(MarkdownDocument::inline('[données](data:text/html,x)')));

        $this->assertSame(
            '<mailto:a@b.fr>:écrire',
            self::resume(MarkdownDocument::inline('[écrire](mailto:a@b.fr)'))
        );
        $this->assertSame(
            '<https://a.b/c>:https://a.b/c',
            self::resume(MarkdownDocument::inline('<https://a.b/c>'))
        );
        // URL nue : la ponctuation finale n'en fait pas partie.
        $this->assertSame(
            'Voir  | <https://x.y/z>:https://x.y/z | .',
            self::resume(MarkdownDocument::inline('Voir https://x.y/z.'))
        );
        // Image : son texte de remplacement, aucune adresse.
        $this->assertSame(
            'I:Logo de MIA |  suivi',
            self::resume(MarkdownDocument::inline('![Logo de MIA](https://x/y.png) suivi'))
        );
    }

    public function testTexteBrutSansAucuneSyntaxe(): void
    {
        $blocs = MarkdownDocument::fromPlainText("# Pas un titre\n* pas une puce\r\n\r\n\r\nSecond **paragraphe**\n");

        $this->assertSame(['paragraph', 'paragraph'], array_column($blocs, 'type'));
        $this->assertSame('# Pas un titre | ⏎ | * pas une puce', self::resume($blocs[0]['inlines']));
        $this->assertSame('Second **paragraphe**', self::resume($blocs[1]['inlines']));
        $this->assertSame([], MarkdownDocument::fromPlainText("  \n\n "));
    }

    public function testDocumentVideOuSansTitre(): void
    {
        $this->assertSame([], MarkdownDocument::parse(''));
        $this->assertSame([], MarkdownDocument::parse("\n\n   \n"));
        $this->assertSame('', MarkdownDocument::title(MarkdownDocument::parse('Un paragraphe.')));
    }

    /** Garde-fou : une imbrication démesurée ne fait ni boucler ni déborder la pile. */
    public function testImbricationDemesuree(): void
    {
        $citations = str_repeat('> ', 60) . 'fond';
        $listes    = '';
        for ($i = 0; $i < 60; $i++) {
            $listes .= str_repeat('  ', $i) . "- niveau $i\n";
        }
        $emphase = str_repeat('**', 40) . 'x' . str_repeat('**', 40);

        $this->assertNotEmpty(MarkdownDocument::parse($citations));
        $this->assertNotEmpty(MarkdownDocument::parse($listes));
        $this->assertNotEmpty(MarkdownDocument::parse($emphase));
    }

    // -------------------------------------------------------------- texte

    public function testRenduTexteLisible(): void
    {
        $texte = MarkdownPlain::render(self::blocs());

        // Ni dièse, ni astérisque, ni accent grave : c'est un fichier texte.
        $this->assertStringNotContainsString('#', $texte);
        $this->assertStringNotContainsString('**', $texte);
        $this->assertStringNotContainsString('~~', $texte);
        $this->assertStringNotContainsString('```', $texte);

        $this->assertStringStartsWith("Compte rendu — Projet Élan\n==========================\n\n", $texte);
        $this->assertStringContainsString("Date : 30 septembre 2026\nLieu : Nantes\n", $texte);
        $this->assertStringContainsString("1. Participants\n---------------\n", $texte);
        $this->assertStringContainsString("- Zoé Lefèvre\n  - suppléante : Inès\n  - ancien contact\n", $texte);
        $this->assertStringContainsString("1. Lancer la phase 2\n2. Budget : voir le tableau (https://exemple.test/budget)\n", $texte);
        $this->assertStringContainsString("> Citation sur\n> deux lignes.\n", $texte);
        $this->assertStringContainsString("    print(\"a { b } \\\\ c\")\n", $texte);
        $this->assertStringContainsString("- [x] Fait\n- [ ] À faire\n", $texte);
        $this->assertStringEndsWith("À faire\n", $texte, 'Un fichier texte finit par un seul saut de ligne.');
    }

    public function testTableauEnColonnesAlignees(): void
    {
        $texte = MarkdownPlain::render(MarkdownDocument::parse(
            "| Poste | Montant |\n|---|---|\n| Loyer | 1 200 € |\n| Licences logicielles | 4 200,50 € |"
        ));

        $this->assertSame(
            "Poste                | Montant\n"
            . "---------------------+-----------\n"
            . "Loyer                | 1 200 €\n"
            . "Licences logicielles | 4 200,50 €\n",
            $texte
        );
    }

    /** L'adresse d'un lien est écrite UNE fois, même si son libellé est découpé. */
    public function testUnLienGardeSonAdresseUneSeuleFois(): void
    {
        $this->assertSame(
            "lien gras dedans (https://a.b) et la suite\n",
            MarkdownPlain::render(MarkdownDocument::parse('[lien **gras** dedans](https://a.b) et la suite'))
        );
        // Libellé identique à l'adresse : pas de doublon.
        $this->assertSame(
            "Voir https://x.y/z.\n",
            MarkdownPlain::render(MarkdownDocument::parse('Voir https://x.y/z.'))
        );
        $this->assertSame(
            "a@b.fr\n",
            MarkdownPlain::render(MarkdownDocument::parse('[a@b.fr](mailto:a@b.fr)'))
        );
    }

    // --------------------------------------------------------------- HTML

    public function testRenduHtmlSurParConstruction(): void
    {
        $html = MarkdownHtml::render(MarkdownDocument::parse(
            "# Titre <script>alert(1)</script>\n\nTexte avec <img src=x onerror=alert(1)> et \"guillemets\" & esperluette.\n\n"
            . "[lien](https://a.b/?x=1&y=\"2\")\n\n```\n</pre><script>x</script>\n```"
        ));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringContainsString('&lt;/pre&gt;&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;guillemets&quot; &amp; esperluette', $html);
        $this->assertStringContainsString('href="https://a.b/?x=1&amp;y=&quot;2&quot;"', $html);
    }

    public function testRenduHtmlDesBlocs(): void
    {
        $html = MarkdownHtml::render(self::blocs());

        $this->assertStringContainsString('<h1>Compte rendu — Projet Élan</h1>', $html);
        $this->assertStringContainsString('<h2>1. Participants</h2>', $html);
        $this->assertStringContainsString('<strong>Date :</strong> 30 septembre 2026<br>Lieu : Nantes', $html);
        $this->assertStringContainsString('<li>Zoé Lefèvre<ul><li>suppléante : Inès</li><li><s>ancien contact</s></li></ul>', $html);
        $this->assertStringContainsString('<ol><li>Lancer la phase 2</li>', $html);
        $this->assertStringContainsString('<th style="text-align:right">Montant</th>', $html);
        $this->assertStringContainsString('<td style="text-align:center"><strong>OK</strong></td>', $html);
        $this->assertStringContainsString('<div class="quote"><p>Citation sur<br>deux lignes.</p>', $html);
        $this->assertStringContainsString('<pre class="code">print(&quot;a { b } \\\\ c&quot;)</pre>', $html);
        $this->assertStringContainsString('<hr>', $html);
        $this->assertStringContainsString("<li>\u{2611} Fait</li><li>\u{2610} À faire</li>", $html);

        $document = MarkdownHtml::document(self::blocs(), 'Titre & <b>');
        $this->assertStringStartsWith('<!DOCTYPE html>', $document);
        $this->assertStringContainsString('<title>Titre &amp; &lt;b&gt;</title>', $document);
        // Aucune ressource distante : le moteur PDF ne sort aucune requête.
        $this->assertDoesNotMatchRegularExpression('/(?:src|url)\s*[=(]/i', $document);
    }

    public function testUneListeNumeroteeRepartDeSonNumero(): void
    {
        $this->assertStringContainsString(
            '<ol start="3">',
            MarkdownHtml::render(MarkdownDocument::parse("3. trois\n4. quatre"))
        );
    }

    // ---------------------------------------------------------------- RTF

    /** Règle 1 : `\`, `{` et `}` structurent le fichier — dans le texte, ils s'échappent. */
    public function testRtfEchappeLesCaracteresDeStructure(): void
    {
        $this->assertSame('a \\{ b \\} \\\\ c', MarkdownRtf::text('a { b } \\ c'));
        $this->assertSame('ligne 1\\line ligne 2\\tab fin', MarkdownRtf::text("ligne 1\nligne 2\tfin"));
        // Caractères de contrôle : écartés, jamais écrits.
        $this->assertSame('ab', MarkdownRtf::text("a\x00\x07\x1Bb"));
        $this->assertSame('', MarkdownRtf::text(''));
    }

    /**
     * Règle 2 : hors ASCII, `\uN?` — N SIGNÉ sur 16 bits, et deux unités pour
     * un caractère hors du plan de base.
     */
    public function testRtfEcritLUnicode(): void
    {
        // Latin-1 « haut » : l'octet de la page de codes déclarée.
        $this->assertSame("\\'e9t\\'e9", MarkdownRtf::text('été'));
        $this->assertSame('\\~', MarkdownRtf::text("\u{00A0}"));
        // Plan de base : « œ » (U+0153), « € » (U+20AC), « — » (U+2014).
        $this->assertSame('\\u339?', MarkdownRtf::text('œ'));
        $this->assertSame('\\u8364?', MarkdownRtf::text('€'));
        $this->assertSame('\\u8212?', MarkdownRtf::text('—'));
        // Au-delà de 32767 : on retranche 65536 (U+FF01 = 65281 → -255).
        $this->assertSame('\\u-255?', MarkdownRtf::text("\u{FF01}"));
        // Hors plan de base : paire de substitution (😀 U+1F600 = D83D DE00).
        $this->assertSame('\\u-10179?\\u-8704?', MarkdownRtf::text('😀'));
        // UTF-8 invalide : jamais d'octet brut dans le fichier.
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', MarkdownRtf::text("a\xC3b\xFF"));
    }

    public function testRtfEstUnFichierSeptBitsEquilibre(): void
    {
        $rtf = MarkdownRtf::render(self::blocs(), 'Compte rendu — Projet Élan', 'fr');

        $this->assertStringStartsWith('{\rtf1\ansi\ansicpg1252', $rtf);
        $this->assertStringEndsWith('}', $rtf);
        $this->assertMatchesRegularExpression('/^[\x0A\x20-\x7E]+$/', $rtf, 'Un fichier RTF ne contient que de l’ASCII.');

        // Accolades équilibrées, hors accolades échappées.
        $nu = str_replace(['\\\\', '\\{', '\\}'], '', $rtf);
        $this->assertSame(substr_count($nu, '{'), substr_count($nu, '}'));

        // Le texte du bloc de code est échappé, pas interprété.
        $this->assertStringContainsString('print("a \\{ b \\} \\\\\\\\ c")', $rtf);
        $this->assertStringContainsString('{\info{\title Compte rendu \\u8212? Projet \\\'c9lan}}', $rtf);
    }

    public function testRtfTitresListesEtTableaux(): void
    {
        $rtf = MarkdownRtf::render(self::blocs(), 'Titre', 'fr');

        // De VRAIS titres : style de paragraphe + niveau de plan.
        $this->assertStringContainsString('\s1\outlinelevel0', $rtf);
        $this->assertStringContainsString('\s2\outlinelevel1', $rtf);
        $this->assertStringContainsString('heading 1;}', $rtf);

        // Listes : table des listes en tête, et renvoi sur chaque élément.
        $this->assertStringContainsString('{\*\listtable', $rtf);
        $this->assertStringContainsString('{\*\listoverridetable', $rtf);
        $this->assertSame(3, substr_count($rtf, '{\list\listtemplateid'), 'Trois listes de premier niveau.');
        $this->assertStringContainsString('\ls1\ilvl0', $rtf);
        $this->assertStringContainsString('\ls1\ilvl1', $rtf, 'La sous-liste est au niveau 1 de SA liste.');
        $this->assertStringContainsString('{\listtext\tab \\u8226?\tab }', $rtf);
        $this->assertStringContainsString('{\listtext\tab 1.\tab }', $rtf);
        $this->assertStringContainsString('{\listtext\tab 2.\tab }', $rtf);

        // Tableau : `\itap1` exigé par le moteur de texte de macOS.
        $this->assertSame(3, substr_count($rtf, '\itap1\trowd'), 'Trois rangées.');
        $this->assertSame(9, substr_count($rtf, '\cell' . "\n"), 'Neuf cellules.');
        $this->assertSame(1, substr_count($rtf, '\lastrow\row'));
        $this->assertStringContainsString('\trhdr', $rtf, 'L’en-tête se répète d’une page à l’autre.');
        $this->assertStringContainsString('\qr', $rtf);
        $this->assertStringContainsString('\qc', $rtf);

        // Lien : un champ HYPERLINK.
        $this->assertStringContainsString('{\field{\*\fldinst{HYPERLINK "https://exemple.test/budget"}}', $rtf);
    }

    /** Deux listes numérotées partageant une définition se suivraient : 1, 2, 3 puis 4, 5. */
    public function testChaqueListeRtfASaPropreDefinition(): void
    {
        $rtf = MarkdownRtf::render(MarkdownDocument::parse("1. a\n2. b\n\nTexte.\n\n5. c\n6. d"));

        $this->assertSame(2, substr_count($rtf, '{\list\listtemplateid'));
        $this->assertStringContainsString('\levelstartat1\levelspace360', $rtf);
        $this->assertStringContainsString('\levelstartat5\levelspace360', $rtf);
        $this->assertStringContainsString('\ls1\ilvl0', $rtf);
        $this->assertStringContainsString('\ls2\ilvl0', $rtf);
        $this->assertStringContainsString('{\listtext\tab 5.\tab }', $rtf);
    }

    public function testRtfSansListeNiTableau(): void
    {
        $rtf = MarkdownRtf::render(MarkdownDocument::parse('Un simple paragraphe.'), 'Note', 'en');

        $this->assertStringNotContainsString('\listtable', $rtf);
        $this->assertStringNotContainsString('\trowd', $rtf);
        $this->assertStringContainsString('\deflang1033', $rtf);
        $this->assertStringContainsString('Un simple paragraphe.\par', $rtf);
    }

    /** Une adresse ne peut pas refermer l'instruction de champ qui la porte. */
    public function testUneAdresseNeCassePasLeChampRtf(): void
    {
        $rtf = MarkdownRtf::render(MarkdownDocument::parse('[x](https://a.b/c?q={1}&r=2)'));

        $this->assertStringContainsString('HYPERLINK "https://a.b/c?q=%7B1%7D&r=2"', $rtf);
        $nu = str_replace(['\\\\', '\\{', '\\}'], '', $rtf);
        $this->assertSame(substr_count($nu, '{'), substr_count($nu, '}'));
    }
}
