<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Boucles « pour chaque » qui parcourent une SOURCE (LoopSources, LoopFiles,
 * LoopRunner) — cf. CLAUDE.md « Workflows », boucles intelligentes. Les
 * fichiers (classeur, présentation, document Word) sont fabriqués à la
 * volée : ce qui est éprouvé, c'est la lecture réelle de leur archive.
 */
final class WorkflowLoopSourcesTest extends TestCase
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        LoopRunner::overrideServices(null);
    }

    // ------------------------------------------------------------------
    // Fabriques de fichiers
    // ------------------------------------------------------------------

    private function zip(string $ext, array $files): string
    {
        $base = tempnam(sys_get_temp_dir(), 'wfls');
        $path = $base . '.' . $ext;
        rename($base, $path);
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $z->addFromString($name, $content);
        }
        $z->close();
        $this->tmp[] = $path;
        return $path;
    }

    /**
     * Un classeur : onglets → lignes (les chaînes vont aux chaînes partagées,
     * un tableau ['date' => n] porte le style date, null = cellule absente).
     */
    private function xlsx(array $sheets): string
    {
        $shared = [];
        $files = [];
        $book = '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '"><sheets>';
        $rels = '<Relationships xmlns="' . self::NS_PKG . '">';
        $i = 0;
        foreach ($sheets as $name => $rows) {
            $i++;
            $book .= '<sheet name="' . htmlspecialchars($name) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $rels .= '<Relationship Id="rId' . $i . '" Type="' . self::NS_REL . '/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $data = '';
            foreach ($rows as $r => $row) {
                $data .= '<row r="' . ($r + 1) . '">';
                foreach ($row as $c => $v) {
                    if ($v === null) {
                        continue;
                    }
                    $ref = chr(65 + $c) . ($r + 1);
                    if (is_array($v)) {
                        $data .= '<c r="' . $ref . '" s="1"><v>' . $v['date'] . '</v></c>';
                    } elseif (is_int($v) || is_float($v)) {
                        $data .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                    } else {
                        $idx = array_search($v, $shared, true);
                        if ($idx === false) {
                            $shared[] = $v;
                            $idx = count($shared) - 1;
                        }
                        $data .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
                    }
                }
                $data .= '</row>';
            }
            $files['xl/worksheets/sheet' . $i . '.xml'] = '<worksheet xmlns="' . self::NS_MAIN . '"><sheetData>' . $data . '</sheetData></worksheet>';
        }
        $files['xl/workbook.xml'] = $book . '</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = $rels . '</Relationships>';
        $files['xl/sharedStrings.xml'] = '<sst xmlns="' . self::NS_MAIN . '">' . implode('', array_map(static fn($s) => '<si><t>' . htmlspecialchars($s) . '</t></si>', $shared)) . '</sst>';
        $files['xl/styles.xml'] = '<styleSheet xmlns="' . self::NS_MAIN . '"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>';
        return $this->zip('xlsx', $files);
    }

    /** Une présentation : slides [titre, [lignes], notes], dans l'ordre donné ; fichiers nommés à l'envers. */
    private function pptx(array $slides): string
    {
        $p = 'http://schemas.openxmlformats.org/presentationml/2006/main';
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $files = [];
        $ids = '';
        $rels = '<Relationships xmlns="' . self::NS_PKG . '">';
        $n = count($slides);
        foreach (array_values($slides) as $i => [$title, $lines, $notes]) {
            // Fichier slideN nommé à l'envers : l'ordre doit venir de sldIdLst.
            $file = 'slide' . ($n - $i) . '.xml';
            $ids .= '<p:sldId id="' . (256 + $i) . '" r:id="rId' . ($i + 10) . '"/>';
            $rels .= '<Relationship Id="rId' . ($i + 10) . '" Type="' . self::NS_REL . '/slide" Target="slides/' . $file . '"/>';
            $sp = static fn(string $ph, array $paras): string => '<p:sp><p:nvSpPr><p:cNvPr id="2" name="x"/><p:cNvSpPr/><p:nvPr>'
                . ($ph !== '' ? '<p:ph type="' . $ph . '"/>' : '') . '</p:nvPr></p:nvSpPr><p:txBody>'
                . implode('', array_map(static fn($t) => '<a:p><a:r><a:t>' . htmlspecialchars($t) . '</a:t></a:r></a:p>', $paras)) . '</p:txBody></p:sp>';
            $files['ppt/slides/' . $file] = '<p:sld xmlns:p="' . $p . '" xmlns:a="' . $a . '"><p:cSld><p:spTree>'
                . $sp('title', [$title]) . $sp('', $lines) . $sp('sldNum', ['12']) . '</p:spTree></p:cSld></p:sld>';
            if ($notes !== '') {
                $files['ppt/slides/_rels/' . $file . '.rels'] = '<Relationships xmlns="' . self::NS_PKG . '"><Relationship Id="rId1" Type="' . self::NS_REL . '/notesSlide" Target="../notesSlides/notes' . $i . '.xml"/></Relationships>';
                $files['ppt/notesSlides/notes' . $i . '.xml'] = '<p:notes xmlns:p="' . $p . '" xmlns:a="' . $a . '"><p:cSld><p:spTree>'
                    . $sp('sldImg', []) . $sp('body', [$notes]) . '</p:spTree></p:cSld></p:notes>';
            }
        }
        $files['ppt/presentation.xml'] = '<p:presentation xmlns:p="' . $p . '" xmlns:r="' . self::NS_REL . '"><p:sldIdLst>' . $ids . '</p:sldIdLst></p:presentation>';
        $files['ppt/_rels/presentation.xml.rels'] = $rels . '</Relationships>';
        return $this->zip('pptx', $files);
    }

    /** Un document Word : paragraphes [style, texte]. */
    private function docx(array $paras): string
    {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $body = '';
        foreach ($paras as [$style, $text]) {
            $body .= '<w:p>' . ($style !== '' ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '') . '<w:r><w:t>' . htmlspecialchars($text) . '</w:t></w:r></w:p>';
        }
        return $this->zip('docx', [
            'word/document.xml' => '<w:document xmlns:w="' . $w . '"><w:body>' . $body . '</w:body></w:document>',
            // Style « Titre1 » nommé « heading 1 » (Word en français), « Sous » nommé « heading 2 ».
            'word/styles.xml' => '<w:styles xmlns:w="' . $w . '"><w:style w:type="paragraph" w:styleId="Titre1"><w:name w:val="heading 1"/></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Sous"><w:name w:val="heading 2"/></w:style></w:styles>',
        ]);
    }

    private static function loop(array $source, array $extra = []): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fields' => [['key' => 'fichier', 'label' => 'Fichier', 'type' => 'file', 'required' => true]]],
            'nodes' => [$extra + ['key' => 'chaque', 'type' => 'loop', 'title' => 'Chaque', 'mode' => 'each', 'over' => 'fichier',
                'source' => $source, 'item' => ['key' => 'el', 'name' => 'Élément en cours'],
                'body' => [['key' => 'faire', 'type' => 'step', 'title' => 'Faire', 'instruction' => 'Traite @{el}.',
                    'output' => ['key' => 'res', 'name' => 'Résultat', 'form' => 'text']]],
                'output' => ['key' => 'tout', 'name' => 'Tout']]],
            'end' => ['pieces' => ['tout']],
        ]);
    }

    // ------------------------------------------------------------------
    // Classeurs
    // ------------------------------------------------------------------

    public function testUnClasseurSeLitDansSonArchiveAvecSonEnTete(): void
    {
        $path = $this->xlsx([
            'Clients' => [
                ['Suivi des clients 2026'],
                [],
                ['Client', 'E-mail', 'Montant dû', 'Échéance'],
                ['Alpha', 'alpha@exemple.fr', 1200, ['date' => 46327]],
                ['Beta', null, 0.5, null],
                [],
                ['Gamma', 'gamma@exemple.fr', 300, ['date' => 46328]],
            ],
            'Archives' => [['Nom'], ['Vieux client']],
        ]);
        $g = LoopFiles::sheet($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'suivi.xlsx');
        $this->assertSame(['Clients', 'Archives'], $g['sheets']);
        $this->assertSame('Clients', $g['sheet']);
        $this->assertSame(['Client', 'E-mail', 'Montant dû', 'Échéance'], $g['header'], 'Le titre seul au-dessus n’est pas l’en-tête.');
        $this->assertSame(3, $g['header_row']);
        $this->assertSame([
            ['Alpha', 'alpha@exemple.fr', '1200', '2026-11-01'],
            ['Beta', '', '0.5', ''],
            ['Gamma', 'gamma@exemple.fr', '300', '2026-11-02'],
        ], $g['rows'], 'Cellule absente à sa place, date reconnue par son style, lignes vides retirées.');

        $other = LoopFiles::sheet($path, '', 'suivi.xlsx', 'archives');
        $this->assertSame('Archives', $other['sheet']);
        $this->assertSame([['Vieux client']], $other['rows']);

        try {
            LoopFiles::sheet($path, '', 'suivi.xlsx', 'Budget');
            $this->fail('Onglet inconnu');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Onglets : Clients, Archives', $e->getMessage());
        }
        $forced = LoopFiles::sheet($path, '', 'suivi.xlsx', '', 1);
        $this->assertSame('Suivi des clients 2026', $forced['header'][0], 'Ligne d’en-tête imposée.');
    }

    public function testUnCsvEtUnTableauSeLisentPareil(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'wfls');
        $csv = $base . '.csv';
        rename($base, $csv);
        $this->tmp[] = $csv;
        file_put_contents($csv, "Client;Email\nAlpha;a@x.fr\n;\nBeta;b@x.fr\n");
        $g = LoopFiles::sheet($csv, 'text/csv', 'c.csv');
        $this->assertSame(['Client', 'Email'], $g['header']);
        $this->assertSame([['Alpha', 'a@x.fr'], ['Beta', 'b@x.fr']], $g['rows']);

        $grid = LoopFiles::grid([], '', [['', 'Nom', ''], ['', 'Alpha', ''], ['', 'Alpha', '']], 0);
        $this->assertSame(['Colonne 1', 'Nom'], $grid['header'], 'Colonne sans nom nommée, colonnes vides de fin retirées.');
        $this->assertSame(LoopFiles::columnIndex('AB12'), 27);
        $this->assertSame('2026-11-01 12:00', LoopFiles::excelDate(46327.5));
    }

    public function testLesColonnesSeRetrouventParLeurNomEtUneColonneAbsenteArreteTout(): void
    {
        $def = self::loop(['kind' => 'rows', 'from' => 'piece', 'label' => 'client', 'columns' => [
            ['key' => 'client', 'label' => 'Client', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email'],
        ]]);
        $loop = $def['nodes'][0];
        $grid = ['header' => ['CLIENT ', 'E-mail', 'Ville'], 'rows' => [['Alpha', 'a@x.fr', 'Lyon'], ['Beta', 'b@x.fr', '']], 'header_row' => 1, 'sheet' => '', 'sheets' => []];
        $out = LoopRunner::rowElements($loop, $grid);
        $this->assertCount(2, $out['elements']);
        $e = $out['elements'][0];
        $this->assertSame('Alpha', $e['label']);
        $this->assertSame(['client' => 'Alpha', 'email' => 'a@x.fr'], $e['fields']);
        $this->assertSame("Autres colonnes :\nVille : Lyon", $e['text'], 'Les colonnes non déclarées restent lisibles.');
        $this->assertSame(['ligne' => 2], $e['ref']);

        $grid['header'] = ['Nom', 'Ville'];
        try {
            LoopRunner::rowElements($loop, $grid);
            $this->fail('Colonne absente');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('« Client », « Email »', $e->getMessage());
            $this->assertStringContainsString('Colonnes trouvées : Nom, Ville', $e->getMessage());
        }
    }

    public function testUneBoucleSurUnClasseurDuDepartDecoupeSesLignes(): void
    {
        $path = $this->xlsx(['F' => [['Client', 'Montant'], ['Alpha', 10], ['Beta', 0], ['Alpha', 20]]]);
        $def = self::loop(['kind' => 'rows', 'from' => 'piece', 'columns' => [
            ['key' => 'client', 'label' => 'Client', 'type' => 'text'], ['key' => 'montant', 'label' => 'Montant', 'type' => 'number'],
        ]], ['filter' => ['rule' => ['join' => 'all', 'items' => [['piece' => 'el', 'field' => 'montant', 'op' => 'gt', 'value' => '0']]], 'dedupe' => 'client']]);
        $loop = $def['nodes'][0];
        $arts = ['fichier' => ['form' => 'file', 'content' => ['name' => 'f.xlsx', 'type' => '', 'path' => $path, 'text' => '']]];
        $split = LoopRunner::split($def, $loop, $arts, ['id' => 1], []);
        $this->assertSame(['Alpha', 'Beta', 'Alpha'], array_column($split['elements'], 'label'));
        $this->assertSame(['Client', 'Montant'], $split['header']);

        $pieces = WorkflowSpec::pieces($def);
        $f = WorkflowLoop::filterElements($loop, $pieces['el'], $split['elements']);
        $this->assertSame(['Alpha'], array_column($f['kept'], 'label'));
        $this->assertSame(1, $f['ignored']['filter'], 'Beta doit 0 : hors filtre.');
        $this->assertSame(1, $f['ignored']['dup'], 'Le second Alpha est un doublon.');

        // Une ligne de total en bas du tableau n'est pas un client.
        foreach (['Total', 'SOUS-TOTAL', 'Total TTC', 'Grand total', 'Totaux'] as $t) {
            $this->assertTrue(WorkflowLoop::isTotalRow($t), $t);
        }
        foreach (['TotalEnergies', 'Total Énergie SA', 'Alpha'] as $t) {
            $this->assertFalse(WorkflowLoop::isTotalRow($t), $t);
        }
        $withTotal = array_merge($split['elements'], [['label' => 'Total', 'fields' => ['client' => 'Total', 'montant' => '30'], 'cells' => ['Total', '30']]]);
        $this->assertSame(['Alpha'], array_column(WorkflowLoop::filterElements($loop, $pieces['el'], $withTotal)['kept'], 'label'));
        $this->assertSame(['Total'], array_column(WorkflowLoop::filterElements($loop, $pieces['el'], [['label' => 'Total', 'fields' => ['montant' => '1']]])['kept'], 'label'),
            'Hors tableau (une liste), « Total » est un élément comme un autre.');

        // Un fichier qui n'est pas un classeur : message qui dit quoi joindre.
        $arts['fichier']['content']['name'] = 'notes.docx';
        $this->expectExceptionMessage('n’est pas un classeur');
        LoopRunner::split($def, $loop, $arts, ['id' => 1], []);
    }

    // ------------------------------------------------------------------
    // Présentations, documents, textes
    // ------------------------------------------------------------------

    public function testLesSlidesSuiventLOrdreDeLaPresentationAvecLeursNotes(): void
    {
        $path = $this->pptx([
            ['Ouverture', ['Bienvenue'], 'Dire bonjour'],
            ['Chiffres', ['CA +12 %', 'Marge stable'], ''],
            ['Conclusion', ['Merci'], 'Prendre les questions'],
        ]);
        $slides = LoopFiles::slides($path, 'deck.pptx');
        $this->assertSame(['Ouverture', 'Chiffres', 'Conclusion'], array_column($slides, 'titre'), 'Ordre de sldIdLst, pas des noms de fichiers.');
        $this->assertSame([1, 2, 3], array_column($slides, 'numero'));
        $this->assertSame("CA +12 %\nMarge stable", $slides[1]['texte'], 'Le numéro de slide n’est pas du texte.');
        $this->assertSame('Dire bonjour', $slides[0]['notes']);
        $this->assertSame('', $slides[1]['notes']);
        $this->assertTrue(LoopFiles::isSlides('', 'deck.pptx'));
    }

    public function testUnDocumentWordSeCoupeASesTitres(): void
    {
        $path = $this->docx([
            ['', 'Préambule du rapport.'],
            ['Titre1', 'Contexte'],
            ['', 'Le marché évolue.'],
            ['Sous', 'Détail'],
            ['', 'Un point précis.'],
            ['Heading1', 'Recommandations'],
            ['', 'Agir vite.'],
        ]);
        $md = LoopFiles::documentMarkdown($path, '', 'rapport.docx');
        $this->assertStringContainsString("# Contexte", $md);
        $this->assertStringContainsString("## Détail", $md);
        $sections = LoopFiles::sectionsOf($md);
        $this->assertSame(['Introduction', 'Contexte', 'Recommandations'], array_column($sections, 'titre'));
        $this->assertStringContainsString('## Détail', $sections[1]['contenu'], 'Un titre plus bas reste dans sa section.');
        $this->assertSame('Agir vite.', $sections[2]['contenu']);
    }

    public function testUnTexteSeCoupeAuPlusHautNiveauDeTitreHorsBlocDeCode(): void
    {
        $text = "## Un\nA\n```\n# pas un titre\n```\n## Deux\nB";
        $this->assertSame([['titre' => 'Un', 'contenu' => "A\n```\n# pas un titre\n```"], ['titre' => 'Deux', 'contenu' => 'B']], LoopFiles::sectionsOf($text));
        $this->assertSame([['titre' => 'Sans titre ici', 'contenu' => "Sans titre ici\nsuite"]], LoopFiles::sectionsOf("Sans titre ici\nsuite"));
        $this->assertSame([], LoopFiles::sectionsOf("  \n"));
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], LoopFiles::lines("- Alpha\n2) Beta\n\n# Titre\n• Gamma"));
    }

    // ------------------------------------------------------------------
    // Le registre : formes, acceptation, phrases
    // ------------------------------------------------------------------

    public function testLaFormeDeLElementSuitLaSource(): void
    {
        $rows = self::loop(['kind' => 'rows', 'columns' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]])['nodes'][0];
        $shape = LoopSources::itemShape($rows);
        $this->assertSame('fiche', $shape['form']);
        $this->assertSame(['client'], array_column($shape['fields'], 'key'));
        $this->assertFalse($shape['file']);

        $att = LoopSources::itemShape(['source' => ['kind' => 'attachments']]);
        $this->assertTrue($att['file']);
        $this->assertSame(['nom', 'type', 'taille', 'email_objet', 'email_de'], array_column($att['fields'], 'key'));

        $legacy = LoopSources::itemShape(['source' => ['kind' => '']]);
        $this->assertSame('text', $legacy['form'], 'Une boucle d’avant les sources garde un élément texte.');

        $pieces = WorkflowSpec::pieces(self::loop(['kind' => 'slides']));
        $this->assertSame(['numero', 'titre', 'texte', 'notes'], array_column($pieces['el']['fields'], 'key'));
        $this->assertTrue($pieces['el']['item']);
    }

    public function testChaqueSourceDitCeQuElleAccepte(): void
    {
        $file = ['form' => 'file'];
        $this->assertTrue(LoopSources::accepts('rows', $file));
        $this->assertTrue(LoopSources::accepts('rows', ['form' => 'table']));
        $this->assertTrue(LoopSources::accepts('rows', ['form' => 'document', 'format' => 'xlsx']));
        $this->assertFalse(LoopSources::accepts('rows', ['form' => 'text']));
        $this->assertTrue(LoopSources::accepts('slides', ['form' => 'document', 'format' => 'pptx']));
        $this->assertFalse(LoopSources::accepts('attachments', $file), 'Les pièces jointes se lisent dans un email reçu.');
        $this->assertTrue(LoopSources::accepts('attachments', ['form' => 'fiche', 'event' => 'email']));
        $this->assertTrue(LoopSources::accepts('list', ['form' => 'fiche', 'fields' => [['key' => 'l', 'type' => 'list']]], 'l'));
        $this->assertFalse(LoopSources::accepts('list', ['form' => 'fiche', 'fields' => [['key' => 'l', 'type' => 'text']]], 'l'));
        $this->assertFalse(LoopSources::accepts('ai', ['form' => 'image']));

        $this->assertTrue(LoopSources::readsPiece(['kind' => 'rows', 'from' => 'piece']));
        $this->assertFalse(LoopSources::readsPiece(['kind' => 'rows', 'from' => 'drive']));
        $this->assertFalse(LoopSources::readsPiece(['kind' => 'emails']));
        $this->assertFalse(LoopSources::readsPiece(['kind' => 'attachments', 'from' => 'search']));
        $this->assertSame(array_keys(LoopSources::FIXED_FIELDS), array_values(array_intersect(array_keys(LoopSources::FIXED_FIELDS), LoopSources::KINDS)));
    }

    public function testLaPhraseDUneBoucleDitCeQuElleParcourt(): void
    {
        $over = ['name' => 'Suivi clients'];
        $this->assertSame('Pour chaque ligne de « Suivi clients »', LoopSources::describe(['source' => ['kind' => 'rows', 'from' => 'piece']], $over));
        $this->assertSame('Pour chaque ligne de « Clients.xlsx »', LoopSources::describe(['source' => ['kind' => 'rows', 'from' => 'drive', 'drive' => 'Clients.xlsx']], null));
        $this->assertSame('Pour chaque email trouvé', LoopSources::describe(['source' => ['kind' => 'emails']], null));
        $catalog = LoopSources::catalog();
        $this->assertSame(LoopSources::KINDS, array_column($catalog, 'key'), 'Une tuile par façon de parcourir, dans l’ordre du registre.');
        foreach ($catalog as $c) {
            $this->assertNotSame('', $c['label']);
            $this->assertNotSame('', $c['help']);
        }
        I18n::use('en');
        $this->assertSame('For each row of “Suivi clients”', LoopSources::describe(['source' => ['kind' => 'rows', 'from' => 'piece']], $over));
        I18n::use('fr');
    }

    // ------------------------------------------------------------------
    // Spec : normalisation, condition dans le corps, points à régler
    // ------------------------------------------------------------------

    public function testUneConditionDansUneBoucleTrieLesElementsSansVoie(): void
    {
        $def = WorkflowSpec::normalize(['start' => ['fields' => [['key' => 'l', 'label' => 'Liste', 'type' => 'longtext', 'required' => true]]],
            'nodes' => [['key' => 'b', 'type' => 'loop', 'title' => 'B', 'over' => 'depart', 'source' => ['kind' => 'ai', 'instruction' => 'Chaque sujet', 'fields' => [['label' => 'Sujet', 'type' => 'text']]],
                'item' => ['key' => 'el', 'name' => 'Sujet en cours'],
                'body' => [
                    ['key' => 'lire', 'type' => 'step', 'title' => 'Lire', 'instruction' => '@{el}', 'output' => ['key' => 'avis', 'name' => 'Avis', 'form' => 'fiche', 'fields' => [['label' => 'Utile', 'type' => 'yesno']]]],
                    ['key' => 'garde', 'type' => 'condition', 'title' => 'Garder ?', 'mode' => 'rule',
                        'rule' => ['join' => 'all', 'items' => [['piece' => 'avis', 'field' => 'utile', 'op' => 'yes', 'value' => '']]],
                        'yes' => ['then' => 'continue', 'nodes' => [['key' => 'perdu', 'type' => 'notify', 'title' => 'N', 'message' => 'x']]],
                        'no' => ['then' => 'skip', 'nodes' => []]],
                    ['key' => 'agir', 'type' => 'step', 'title' => 'Agir', 'instruction' => '@{avis}', 'output' => ['key' => 'fait', 'name' => 'Fait', 'form' => 'text']],
                ],
                'output' => ['key' => 'tout', 'name' => 'Tout']]],
            'end' => ['pieces' => ['tout']]]);
        $cond = $def['nodes'][0]['body'][1];
        $this->assertSame('condition', $cond['type']);
        $this->assertSame([], $cond['yes']['nodes'], 'Une condition de boucle n’a pas de voie.');
        $this->assertSame(WorkflowSpec::THEN_SKIP, $cond['no']['then']);
        $this->assertSame(WorkflowSpec::THEN_CONTINUE, $cond['yes']['then']);
        $this->assertSame('agir', WorkflowSpec::next($def, 'garde', 'yes'));
        $this->assertSame(WorkflowSpec::LOOP_SKIP . 'b', WorkflowSpec::next($def, 'garde', 'no'), 'Non : l’élément suivant.');

        // Une décision dans le corps reste écartée.
        $withRoute = WorkflowSpec::normalize(['nodes' => [['key' => 'b', 'type' => 'loop', 'title' => 'B', 'over' => 'x',
            'body' => [['key' => 'r', 'type' => 'route', 'title' => 'R', 'routes' => [['key' => 'a', 'label' => 'A'], ['key' => 'c', 'label' => 'C']]]]]]]);
        $this->assertSame([], $withRoute['nodes'][0]['body']);
    }

    public function testLaNormalisationDUneSourceGardeSesBornes(): void
    {
        $loop = self::loop(['kind' => 'rows', 'from' => 'nimporte', 'header' => 99, 'label' => 'inconnu', 'columns' => array_map(
            static fn($i) => ['label' => 'Col ' . $i, 'type' => 'zz'], range(1, 30)
        )], ['max' => 9999, 'on_item_error' => 'bof'])['nodes'][0];
        $this->assertSame('piece', $loop['source']['from']);
        $this->assertSame(50, $loop['source']['header']);
        $this->assertCount(LoopSources::MAX_COLUMNS, $loop['source']['columns']);
        $this->assertSame('text', $loop['source']['columns'][0]['type']);
        $this->assertSame('', $loop['source']['label'], 'Colonne de nom inconnue : la première.');
        $this->assertSame(WorkflowSpec::LOOP_EACH_MAX, $loop['max']);
        $this->assertSame(WorkflowSpec::ITEM_ERROR_CONTINUE, $loop['on_item_error'], 'Un élément en échec n’arrête pas les autres par défaut.');
        $this->assertTrue($loop['filter']['require_label']);

        $emails = self::loop(['kind' => 'emails', 'from' => 'piece', 'service' => 'yahoo', 'days' => 400])['nodes'][0]['source'];
        $this->assertSame(['kind' => 'emails', 'from' => 'search', 'service' => 'gmail', 'query' => '', 'days' => 90, 'unread' => false], $emails);
        $this->assertSame(['kind' => ''], self::loop(['kind' => 'inconnue'])['nodes'][0]['source']);
    }

    public function testLesPointsARreglerDisentCeQuiManque(): void
    {
        $msgs = static fn(array $def): array => array_map(static fn($i) => $i['level'] . ' ' . $i['text'], WorkflowSpec::issues($def, ['sources' => ['gmail' => ['label' => 'Gmail', 'connected' => true]]]));

        $att = self::loop(['kind' => 'attachments', 'from' => 'piece']);
        $this->assertNotEmpty(array_filter($msgs($att), static fn($m) => str_contains($m, 'Un email arrive')), implode("\n", $msgs($att)));

        $rows = self::loop(['kind' => 'rows', 'from' => 'piece', 'columns' => []]);
        $this->assertNotEmpty(array_filter($msgs($rows), static fn($m) => str_starts_with($m, 'warn') && str_contains($m, 'Aucune colonne déclarée')));

        $drive = self::loop(['kind' => 'rows', 'from' => 'drive', 'drive' => '', 'columns' => [['label' => 'A']]]);
        $this->assertNotEmpty(array_filter($msgs($drive), static fn($m) => str_contains($m, 'fichier Google Drive à lire')));

        $ai = self::loop(['kind' => 'ai', 'instruction' => '']);
        $this->assertNotEmpty(array_filter($msgs($ai), static fn($m) => str_contains($m, 'ce qu’elle doit repérer')));

        $review = self::loop(['kind' => 'rows', 'columns' => [['label' => 'A']]], ['review_actions' => true]);
        $this->assertNotEmpty(array_filter($msgs($review), static fn($m) => str_contains($m, 'rien à relire')));

        $ok = self::loop(['kind' => 'rows', 'columns' => [['label' => 'Client']]]);
        $this->assertSame([], $msgs($ok), 'Une boucle sur les lignes du fichier de départ, colonnes déclarées : rien à régler.');
    }

    // ------------------------------------------------------------------
    // Rassemblement, MIA qui repère
    // ------------------------------------------------------------------

    public function testLeTableauRassembleGardeLesColonnesDOrigineEtUnStatut(): void
    {
        $out = ['form' => 'table'];
        $src = ['form' => 'fiche', 'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'number']]];
        $res = WorkflowLoop::aggregate($out, $src, [
            ['item' => 'Alpha', 'content' => ['fields' => ['note' => 4]], 'status' => 'done', 'cells' => ['Alpha', 'a@x.fr']],
            ['item' => 'Beta', 'content' => null, 'status' => 'failed', 'note' => 'Délai dépassé', 'cells' => ['Beta', 'b@x.fr']],
            ['item' => 'Gamma', 'content' => null, 'status' => 'skipped', 'cells' => ['Gamma', '']],
        ], ['Client', 'Email']);
        $this->assertSame(['Client', 'Email', 'Note', 'Statut'], $res['rows'][0]);
        $this->assertSame(['Alpha', 'a@x.fr', '4', 'traité'], $res['rows'][1]);
        $this->assertSame('Beta', $res['rows'][2][0]);
        $this->assertStringContainsString('en échec', $res['rows'][2][3]);
        $this->assertStringContainsString('Délai dépassé', $res['rows'][2][3]);
        $this->assertSame('écarté', $res['rows'][3][3]);

        // Une boucle d'avant les sources garde exactement sa forme.
        $legacy = WorkflowLoop::aggregate($out, $src, [['item' => 'Alpha', 'content' => ['fields' => ['note' => 4]]]]);
        $this->assertSame([['Élément', 'Note'], ['Alpha', '4']], $legacy['rows']);
    }

    public function testLaListeRendueParMiaSeLitSansEtreDevinee(): void
    {
        $fields = [['key' => 'sujet', 'label' => 'Sujet', 'type' => 'text'], ['key' => 'urgence', 'label' => 'Urgence', 'type' => 'text']];
        $r = WorkflowAi::parseExtraction("Voici :\n```json\n[{\"element\": \"Devis\", \"sujet\": \"Devis\", \"urgence\": \"haute\"}, {\"sujet\": \"Planning\"}, {\"element\": \"devis\"}]\n```", $fields);
        $this->assertNull($r['error'] ?? null);
        $this->assertSame(['Devis', 'Planning'], array_column($r['items'], 'label'), 'Nom repris d’un champ, doublon retiré.');
        $this->assertSame('haute', $r['items'][0]['fields']['urgence']);

        $plain = WorkflowAi::parseExtraction('["Alpha", "Beta"]', []);
        $this->assertSame(['Alpha', 'Beta'], array_column($plain['items'], 'label'));

        $bad = WorkflowAi::parseExtraction('Je ne sais pas.', $fields);
        $this->assertSame('malformed', $bad['error']['kind'], 'Une réponse sans liste est une erreur, jamais une liste devinée.');
    }

    // ------------------------------------------------------------------
    // Pièces jointes, dossier, emails : services remplacés
    // ------------------------------------------------------------------

    public function testLesPiecesJointesDeLEmailRecuEcartentLesImagesDuCorps(): void
    {
        $svc = new class {
            public function listAttachments(string $id): array
            {
                return ['objet' => 'Factures', 'de' => 'compta@x.fr', 'pieces' => [
                    ['numero' => 1, 'nom' => 'facture.pdf', 'type' => 'application/pdf', 'taille' => 2048, 'en_ligne' => false],
                    ['numero' => 2, 'nom' => 'logo.png', 'type' => 'image/png', 'taille' => 900, 'en_ligne' => true],
                    ['numero' => 3, 'nom' => 'releve.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'taille' => 5000, 'en_ligne' => false],
                ]];
            }
        };
        LoopRunner::overrideServices(static fn(string $c) => $svc);
        $def = WorkflowSpec::normalize(['start' => ['event' => ['kind' => 'email', 'source' => 'gmail']],
            'nodes' => [['key' => 'b', 'type' => 'loop', 'title' => 'B', 'over' => 'evenement', 'source' => ['kind' => 'attachments', 'from' => 'piece', 'types' => 'all'],
                'item' => ['key' => 'pj', 'name' => 'Pièce jointe'],
                'body' => [['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => '@{pj}', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'text']]],
                'output' => ['key' => 'tout', 'name' => 'Tout']]],
            'end' => ['pieces' => ['tout']]]);
        $arts = ['evenement' => ['form' => 'fiche', 'content' => ['message_id' => 'm1', 'service' => 'gmail', 'fields' => ['objet' => 'Factures', 'expediteur' => 'compta@x.fr']]]];
        $split = LoopRunner::split($def, $def['nodes'][0], $arts, ['id' => 1], []);
        $this->assertSame(['facture.pdf', 'releve.xlsx'], array_column($split['elements'], 'label'));
        $this->assertSame('compta@x.fr', $split['elements'][0]['fields']['email_de']);
        $this->assertSame(['service' => 'gmail', 'message' => 'm1', 'numero' => 1, 'nom' => 'facture.pdf'], $split['elements'][0]['ref']);
        $this->assertNotEmpty(array_filter($split['notes'], static fn($n) => str_contains($n, 'image intégrée')));

        $pdfOnly = $def;
        $pdfOnly['nodes'][0]['source']['types'] = 'pdf';
        $this->assertSame(['facture.pdf'], array_column(LoopRunner::split($pdfOnly, $pdfOnly['nodes'][0], $arts, ['id' => 1], [])['elements'], 'label'));
    }

    public function testLesFichiersDUnDossierDriveSontListesPuisLusAuTour(): void
    {
        $svc = new class {
            public array $downloads = [];
            public function folderFiles(string $input, int $limit): array
            {
                return ['dossier' => ['id' => 'd1', 'nom' => 'Contrats'], 'total' => 2, 'fichiers' => [
                    ['id' => 'f1', 'nom' => 'Contrat A.pdf', 'mime' => 'application/pdf', 'type' => 'PDF', 'modifie' => '2026-10-01', 'taille' => 10, 'lien' => 'https://drive.google.com/x'],
                    ['id' => 'f2', 'nom' => 'Notes.txt', 'mime' => 'text/plain', 'type' => 'Texte', 'modifie' => '2026-10-02', 'taille' => 5, 'lien' => ''],
                ]];
            }
            public function downloadFile(string $id): array
            {
                $this->downloads[] = $id;
                return ['id' => $id, 'nom' => 'Notes.txt', 'mime' => 'text/plain', 'bytes' => "Bonjour\nle monde", 'lien' => ''];
            }
        };
        LoopRunner::overrideServices(static fn(string $c) => $svc);
        $def = WorkflowSpec::normalize(['nodes' => [['key' => 'b', 'type' => 'loop', 'title' => 'B', 'source' => ['kind' => 'files', 'from' => 'drive', 'drive' => 'Contrats'],
            'item' => ['key' => 'fic', 'name' => 'Fichier'],
            'body' => [['key' => 's', 'type' => 'step', 'title' => 'S', 'instruction' => '@{fic}', 'output' => ['key' => 'r', 'name' => 'R', 'form' => 'text']]],
            'output' => ['key' => 'tout', 'name' => 'Tout']]], 'end' => ['pieces' => ['tout']]]);
        $loop = $def['nodes'][0];
        $split = LoopRunner::split($def, $loop, [], ['id' => 1], []);
        $this->assertSame(['Contrat A.pdf', 'Notes.txt'], array_column($split['elements'], 'label'));
        $this->assertSame([], $svc->downloads, 'Rien n’est téléchargé au découpage.');

        $el = LoopRunner::fetch($loop, $split['elements'][1], ['id' => 1]);
        $this->assertSame(['f2'], $svc->downloads, 'Le fichier est lu au tour qui le traite.');
        $this->assertFileExists($el['file']['path']);
        $this->tmp[] = $el['file']['path'];
        $this->assertStringStartsWith('wf_', basename($el['file']['path']));
        $this->assertStringContainsString('le monde', (string)$el['file']['text']);
    }

    // ------------------------------------------------------------------
    // Rail du navigateur, éditeur, création par MIA
    // ------------------------------------------------------------------

    public function testLeNavigateurDonneLaMemeFormeQueLeServeur(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node absent');
        }
        $sources = [
            'rows' => ['kind' => 'rows', 'columns' => [['key' => 'client', 'label' => 'Client', 'type' => 'text'], ['key' => 'montant', 'label' => 'Montant', 'type' => 'number']]],
            'list' => ['kind' => 'list'],
            'attachments' => ['kind' => 'attachments', 'from' => 'search'],
            'slides' => ['kind' => 'slides'],
            'pages' => ['kind' => 'pages'],
            'sections' => ['kind' => 'sections'],
            'files' => ['kind' => 'files', 'from' => 'drive', 'drive' => 'X'],
            'emails' => ['kind' => 'emails'],
            'ai' => ['kind' => 'ai', 'instruction' => 'x', 'fields' => [['label' => 'Sujet', 'type' => 'text']]],
            'legacy' => ['kind' => ''],
        ];
        $defs = [];
        $expected = [];
        foreach ($sources as $name => $src) {
            $def = self::loop($src);
            $defs[$name] = $def;
            $p = WorkflowSpec::pieces($def)['el'];
            $loop = $def['nodes'][0];
            $expected[$name] = [
                'form' => $p['form'], 'fields' => array_column($p['fields'] ?? [], 'key'), 'file' => (bool)($p['file'] ?? false),
                'reads' => LoopSources::readsPiece($loop['source']),
                'sentence' => ($loop['source']['kind'] ?? '') !== '' ? LoopSources::describe($loop, WorkflowSpec::pieces($def)['fichier']) : null,
            ];
        }
        $forms = [['form' => 'file'], ['form' => 'table'], ['form' => 'text'], ['form' => 'list'], ['form' => 'document', 'format' => 'xlsx'],
            ['form' => 'document', 'format' => 'pptx'], ['form' => 'document', 'format' => 'docx'], ['form' => 'image'], ['form' => 'fiche', 'event' => 'email'],
            ['form' => 'fiche', 'fields' => [['key' => 'l', 'type' => 'list']]]];
        $accepts = [];
        foreach (LoopSources::KINDS as $k) {
            foreach ($forms as $i => $f) {
                $accepts[$k . ':' . $i] = LoopSources::accepts($k, $f, 'l');
            }
        }
        $script = 'require(' . json_encode(ROOT . '/public/assets/js/workflow-rail.js') . ');'
            . 'var R = globalThis.MiaWorkflowRail; var defs = ' . json_encode($defs, JSON_UNESCAPED_UNICODE) . ';'
            . 'var forms = ' . json_encode($forms) . '; var kinds = ' . json_encode(LoopSources::KINDS) . '; var out = {items: {}, accepts: {}};'
            . 'Object.keys(defs).forEach(function (n) { var d = defs[n]; var loop = d.nodes[0];'
            . ' var p = R.pieces(d).filter(function (x) { return x.key === "el"; })[0];'
            . ' var over = R.pieces(d).filter(function (x) { return x.key === "fichier"; })[0];'
            . ' out.items[n] = { form: p.form, fields: (p.fields || []).map(function (f) { return f.key; }), file: !!p.file,'
            . '  reads: R.readsPiece(loop.source), sentence: loop.source.kind ? R.loopSentence(loop, over) : null }; });'
            . 'kinds.forEach(function (k) { forms.forEach(function (f, i) { out.accepts[k + ":" + i] = !!R.loopAccepts(k, f, "l"); }); });'
            . 'process.stdout.write(JSON.stringify(out));';
        $file = tempnam(sys_get_temp_dir(), 'wfls') . '.js';
        file_put_contents($file, $script);
        $got = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        $this->assertSame($expected, $got['items'] ?? null, 'Forme de l’élément, lecture de la pièce, phrase : les mêmes des deux côtés.');
        $this->assertSame($accepts, $got['accepts'] ?? null, 'Ce que chaque source accepte : la même règle des deux côtés.');
    }

    public function testMiaComposeUneBoucleSurUnFichierAvecSonFiltreEtSaCondition(): void
    {
        $choices = ['profiles' => [], 'llms' => [], 'tool_llms' => [], 'default_llm' => '', 'sources' => ['gmail' => ['label' => 'Gmail', 'connected' => true]],
            'kbs' => [], 'tools' => [], 'creations' => [], 'delivery_kbs' => [], 'auto' => true];
        $draft = WorkflowDraft::fromArgs(['nom' => 'Relances', 'objectif' => 'Relancer',
            'depart' => ['champs' => [['nom' => 'Fichier clients', 'type' => 'fichier', 'obligatoire' => true]]],
            'briques' => [[
                'type' => 'boucle', 'titre' => 'Chaque client', 'parcourir' => 'lignes', 'pour_chaque' => 'Fichier clients',
                'colonnes' => ['Client', ['nom' => 'Montant', 'type' => 'nombre']], 'garder_si' => ['champ' => 'Montant', 'operateur' => 'superieur', 'valeur' => '0'],
                'sans_doublon' => 'Client', 'relire_actions' => true, 'element' => 'Client en cours',
                'briques' => [
                    ['type' => 'etape', 'titre' => 'Rédiger', 'consigne' => 'Relance [Client en cours].', 'piece' => ['nom' => 'Relance', 'forme' => 'texte']],
                    ['type' => 'condition', 'titre' => 'Utile ?', 'question' => 'La relance est-elle utile ?', 'si_oui' => 'continuer', 'si_non' => 'element_suivant'],
                ],
                'resultat' => 'Relances',
            ]],
            'livrable' => ['Relances'],
        ], $choices);
        $loop = $draft['definition']['nodes'][0];
        $this->assertSame('rows', $loop['source']['kind']);
        $this->assertSame('piece', $loop['source']['from']);
        $this->assertSame(['Client', 'Montant'], array_column($loop['source']['columns'], 'label'));
        $this->assertSame('number', $loop['source']['columns'][1]['type']);
        $this->assertSame('montant', $loop['filter']['rule']['items'][0]['field']);
        $this->assertSame('client', $loop['filter']['dedupe']);
        $this->assertTrue($loop['review_actions']);
        $this->assertSame('condition', $loop['body'][1]['type']);
        $this->assertSame(WorkflowSpec::THEN_SKIP, $loop['body'][1]['no']['then']);
        $guide = WorkflowDraft::schemaGuide($choices);
        $this->assertStringContainsString('"parcourir"', $guide);
        $this->assertStringContainsString('element_suivant', $guide);
    }

    public function testLesEcransEtLaDocumentationSontCables(): void
    {
        $src = static fn(string $f): string => (string)file_get_contents(ROOT . '/' . $f);
        $routes = $src('public/index.php');
        foreach (["'/workflows/loop/sample'", "'/workflows/runs/{id}/loops/{key}/retry'", "'/workflows/{id}/preview'"] as $r) {
            $this->assertStringContainsString($r, $routes);
        }
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $c = $src($f);
            foreach (['LoopSources.php', 'LoopFiles.php', 'LoopRunner.php'] as $cls) {
                $this->assertStringContainsString($cls, $c, "$f charge $cls");
            }
            $this->assertLessThan(strpos($c, 'LoopSources.php'), strpos($c, 'WorkflowLoop.php'), "$f : WorkflowLoop avant les découpeurs");
        }
        $run = $src('public/assets/js/workflow-run.js');
        $this->assertStringContainsString('data.keep = picked', $run, 'La relecture poste les éléments ou actions cochés.');
        $this->assertStringContainsString("'/loops/' + encodeURIComponent(lk) + '/retry'", $run);
        $this->assertStringContainsString('picks.off', $run, 'Les cases cochées survivent au sondage.');
        $launch = $src('public/assets/js/workflows.js');
        $this->assertStringContainsString('modal.form.preview_url', $launch);
        $this->assertStringContainsString('confirm_over', $launch);
        $this->assertStringContainsString("'preview_url'", $src('src/Controllers/WorkflowController.php'));
        $this->assertStringContainsString('Workflow::SETTING_LOOP_MAX', $src('templates/admin/tools.php'));
        $this->assertStringContainsString('Workflow::SETTING_LOOP_MAX', $src('src/Controllers/AdminController.php'));
        $this->assertStringContainsString("'message_id' => (string)\$item['id']", $src('src/Workflow/WorkflowTriggers.php'), 'L’email reçu garde de quoi relire ses pièces jointes.');
        $this->assertStringContainsString("'drive_id' => (string)\$item['id']", $src('src/Workflow/WorkflowTriggers.php'));

        // Documentation : la page des boucles et le guide pas à pas, annoncés par la vue d'ensemble.
        $index = $src('docs/user/fonctionnalites/workflows/index.md');
        foreach (['repeter-pour-chaque-element.md', 'mettre-en-place-un-workflow.md'] as $page) {
            $this->assertFileExists(ROOT . '/docs/user/fonctionnalites/workflows/' . $page);
            $this->assertStringContainsString('(' . $page . ')', $index, "La vue d'ensemble annonce $page.");
        }
        // La documentation écrit l'apostrophe droite, l'écran la typographique.
        $doc = (string)preg_replace('/\s+/u', ' ', str_replace('’', "'", $src('docs/user/fonctionnalites/workflows/repeter-pour-chaque-element.md')));
        foreach (LoopSources::catalog() as $c) {
            $this->assertStringContainsString('**' . str_replace('’', "'", $c['label']) . '**', $doc, 'La page nomme la tuile « ' . $c['label'] . ' ».');
        }
        foreach (['Refaire les éléments en échec', 'Relire la liste avant de commencer', 'Lire les colonnes d’un fichier exemple', 'Sans doublon sur', 'passer à l’élément suivant'] as $label) {
            $this->assertStringContainsString(str_replace('’', "'", $label), $doc, "La page cite « $label ».");
        }
        $this->assertStringNotContainsString('](../../admin', $doc, 'Jamais de lien d’une page utilisateur vers l’administration.');
        $this->assertStringContainsString('Éléments traités au plus par une boucle', $src('docs/user/admin/outils/regler-les-workflows.md'));
        $this->assertStringContainsString('id: workflows-boucles-intelligentes', $src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('**1. Que parcourir ?**', $src('docs/GUIDE-CREATEUR.md'));
    }
}
