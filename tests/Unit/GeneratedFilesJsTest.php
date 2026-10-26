<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Partie PURE de `generated-files.js`, exécutée sous node avec le VRAI
 * catalogue (`FileFormats::clientCatalog()`), comme dans la page.
 *
 * Deux moitiés du même contrat vivent dans deux langages :
 *
 *  - le navigateur LIT la déclaration d'un fichier (ligne d'ouverture du bloc),
 *    décide de la carte et de son menu, et découpe le tableau pour l'aperçu ;
 *  - le serveur FABRIQUE le fichier.
 *
 * Une lecture du code ne dit pas si elles sont d'accord. Ces tests le
 * vérifient par l'exécution : tout format proposé par une carte est accepté
 * par le serveur, et l'aperçu d'un tableau découpe comme le classeur — sur le
 * jeu de cas partagé ET sur des entrées tirées au hasard (graine fixe).
 *
 * Sans node (conteneur applicatif), ces tests sont SKIPPÉS ; ils tournent sur
 * le poste de développement et en CI.
 */
final class GeneratedFilesJsTest extends TestCase
{
    /** Lignes d'ouverture d'un bloc → [langage, nom du fichier]. */
    private const DECLARATIONS = [
        // Ce que le prompt enseigne, dans les deux langues.
        ['csv fichier="budget-2026.xlsx"', 'csv', 'budget-2026.xlsx'],
        ['csv file="budget-2026.xlsx"', 'csv', 'budget-2026.xlsx'],
        ["markdown fichier='compte rendu.docx'", 'markdown', 'compte rendu.docx'],
        // Ce que les modèles écrivent aussi, par habitude prise ailleurs.
        ['csv filename=export.csv', 'csv', 'export.csv'],
        ['js name=app.min.js', 'js', 'app.min.js'],
        ['text FICHIER="Notes.TXT"', 'text', 'Notes.TXT'],
        ['yaml title="docker-compose.yml"', 'yaml', 'docker-compose.yml'],
        ['csv:clients.csv', 'csv', 'clients.csv'],
        ['sql requete.sql', 'sql', 'requete.sql'],
        ['budget.csv', '', 'budget.csv'],
        ['csv fichier: "a.csv"', 'csv', 'a.csv'],
        ['{.python fichier="x.py"}', 'python', 'x.py'],
        // Jamais de chemin : seul le dernier segment est gardé.
        ['csv fichier="../../etc/passwd.csv"', 'csv', 'passwd.csv'],
        ['python fichier="src\\app\\main.py"', 'python', 'main.py'],
        // Blocs ORDINAIRES : aucun nom, donc aucune carte.
        ['python', 'python', ''],
        ['', '', ''],
        ['{.python}', 'python', ''],
        ['c++', 'c++', ''],
        ['csv fichier=""', 'csv', ''],
        // `title` ne désigne un fichier que s'il en a la forme.
        ['python title="Exemple d\'utilisation"', 'python', ''],
        ['text v1.2', 'text', ''],
        ['diff --git', 'diff', ''],
    ];

    /** [nom déclaré, langage du bloc] → ce que la carte en retient. */
    private const FICHIERS = [
        ['budget-2026.xlsx', 'csv', ['name' => 'budget-2026.xlsx', 'ext' => 'xlsx', 'preview' => 'table', 'raw' => 'csv', 'targets' => ['xlsx', 'csv']]],
        ['export.csv', 'csv', ['name' => 'export.csv', 'ext' => 'csv', 'preview' => 'table', 'raw' => 'csv', 'targets' => ['csv', 'xlsx']]],
        ['export.tsv', 'tsv', ['name' => 'export.tsv', 'ext' => 'tsv', 'preview' => 'table', 'raw' => 'tsv', 'targets' => ['tsv', 'xlsx', 'csv']]],
        ['rapport.docx', 'markdown', ['name' => 'rapport.docx', 'ext' => 'docx', 'preview' => 'markdown', 'raw' => 'md', 'targets' => ['docx', 'pdf', 'rtf', 'md', 'txt']]],
        ['rapport.pdf', '', ['name' => 'rapport.pdf', 'ext' => 'pdf', 'preview' => 'markdown', 'raw' => 'md', 'targets' => ['pdf', 'docx', 'rtf', 'md', 'txt']]],
        ['lettre.docx', 'text', ['name' => 'lettre.docx', 'ext' => 'docx', 'preview' => 'text', 'raw' => 'txt', 'targets' => ['docx', 'pdf', 'rtf', 'md', 'txt']]],
        ['page.docx', 'html', ['name' => 'page.docx', 'ext' => 'docx', 'preview' => 'code', 'raw' => 'txt', 'targets' => ['docx', 'pdf', 'rtf', 'md', 'txt']]],
        ['README.md', 'markdown', ['name' => 'README.md', 'ext' => 'md', 'preview' => 'markdown', 'raw' => 'md', 'targets' => ['md', 'docx', 'pdf', 'rtf', 'txt']]],
        ['notes.txt', 'text', ['name' => 'notes.txt', 'ext' => 'txt', 'preview' => 'text', 'raw' => 'txt', 'targets' => ['txt']]],
        ['config.json', 'json', ['name' => 'config.json', 'ext' => 'json', 'preview' => 'code', 'raw' => 'json', 'targets' => ['json']]],
        ['x.conf', '', ['name' => 'x.conf', 'ext' => 'conf', 'preview' => 'code', 'raw' => 'conf', 'targets' => ['conf']]],
        // Nom sans extension : celle du langage, à défaut du texte.
        ['sans-extension', 'csv', ['name' => 'sans-extension.csv', 'ext' => 'csv', 'preview' => 'table', 'raw' => 'csv', 'targets' => ['csv', 'xlsx']]],
        ['budget.2026', 'csv', ['name' => 'budget.2026.csv', 'ext' => 'csv', 'preview' => 'table', 'raw' => 'csv', 'targets' => ['csv', 'xlsx']]],
        ['notes', '', ['name' => 'notes.txt', 'ext' => 'txt', 'preview' => 'text', 'raw' => 'txt', 'targets' => ['txt']]],
        // Aucun nom (bloc ordinaire téléchargé depuis son en-tête) : nom par défaut du format.
        ['', 'csv', ['name' => 'tableau.csv', 'ext' => 'csv', 'preview' => 'table', 'raw' => 'csv', 'targets' => ['csv', 'xlsx']]],
        ['', 'python', ['name' => 'script.py', 'ext' => 'py', 'preview' => 'code', 'raw' => 'py', 'targets' => ['py']]],
        // Une présentation : écrite en Markdown, proposée en PDF de slides, Word ou source.
        ['plan.pptx', 'markdown', ['name' => 'plan.pptx', 'ext' => 'pptx', 'preview' => 'markdown', 'raw' => 'md', 'targets' => ['pptx', 'pdf', 'docx', 'md']]],
        ['plan.pptx', 'text', ['name' => 'plan.pptx', 'ext' => 'pptx', 'preview' => 'text', 'raw' => 'txt', 'targets' => ['pptx', 'pdf', 'docx', 'md']]],
        // Formats qu'on ne sait pas fabriquer : leurs issues, et toujours le texte.
        ['ancien.ppt', 'markdown', ['name' => 'ancien.ppt', 'ext' => 'ppt', 'preview' => 'markdown', 'raw' => 'md', 'targets' => ['pptx', 'pdf', 'txt']]],
        ['ancien.xls', 'csv', ['name' => 'ancien.xls', 'ext' => 'xls', 'preview' => 'table', 'raw' => 'csv', 'targets' => ['xlsx', 'csv', 'txt']]],
        ['photo.png', '', ['name' => 'photo.png', 'ext' => 'png', 'preview' => 'markdown', 'raw' => 'md', 'targets' => ['txt']]],
    ];

    /** @var array<string, mixed>|null */
    private static ?array $sortie = null;

    // ------------------------------------------------------- déclaration

    public function testLectureDeLaLigneDOuverture(): void
    {
        $resultats = self::sortie()['parseInfo'];

        foreach (self::DECLARATIONS as $i => [$ligne, $langage, $nom]) {
            $this->assertSame(
                ['lang' => $langage, 'name' => $nom],
                $resultats[$i],
                'Ligne d’ouverture « ' . $ligne . ' »'
            );
        }
    }

    /**
     * Chaque attribut annoncé par le serveur (FileDelivery::ATTRIBUTES) est
     * réellement reconnu par l'écran — dont celui que le prompt enseigne.
     */
    public function testChaqueAttributAnnonceEstReconnu(): void
    {
        $resultats = self::sortie()['attributs'];

        foreach (FileDelivery::ATTRIBUTES as $i => $attribut) {
            $this->assertSame(
                ['lang' => 'csv', 'name' => 'donnees.csv'],
                $resultats[$i],
                "Attribut « $attribut » non reconnu par l'écran."
            );
        }
    }

    public function testCeQueLaCarteRetientDUnFichier(): void
    {
        $resultats = self::sortie()['describe'];

        foreach (self::FICHIERS as $i => [$nom, $langage, $attendu]) {
            $r = $resultats[$i];
            foreach ($attendu as $cle => $valeur) {
                $this->assertSame($valeur, $r[$cle], "« $nom » (bloc « $langage ») : $cle");
            }
        }
    }

    /** Libellé, famille et pastille viennent du catalogue du serveur, jamais d'une liste recopiée. */
    public function testLaCarteLitLeCatalogueDuServeur(): void
    {
        $resultats = self::sortie()['describe'];

        foreach (self::FICHIERS as $i => [$nom, $langage]) {
            $r = $resultats[$i];
            if ($r['refusal'] !== '') {
                $this->assertSame(FileFormats::unsupported($r['ext'])['why'], $r['refusal'], "Motif du refus de « $nom »");
                continue;
            }
            $format = FileFormats::get($r['ext']);
            $this->assertSame($format['label'], $r['label'], "Libellé de « $nom »");
            $this->assertSame($format['family'], $r['family'], "Famille de « $nom »");
            $this->assertSame($format['tone'], $r['tone'], "Pastille de « $nom »");
        }
    }

    /**
     * L'INVARIANT entre les deux langages : tout format qu'une carte propose
     * (bouton principal et menu « Autres formats ») est accepté par le
     * serveur. Rejoue exactement la décision de FileFactory::build().
     */
    public function testToutFormatProposeEstAccepteParLeServeur(): void
    {
        $resultats = self::sortie()['describe'];

        foreach (self::FICHIERS as $i => [$nom, $langage]) {
            $r = $resultats[$i];
            $this->assertNotEmpty($r['targets'], "« $nom » : aucune issue proposée.");
            $origine = FileFactory::resolveExtension($r['name'], $langage);

            foreach ($r['targets'] as $cible) {
                $this->assertNull(
                    FileFormats::unsupported($cible),
                    "« $nom » propose .$cible, que le serveur refuse."
                );
                $syntaxe = FileFormats::unsupported($origine) !== null
                    ? FileFormats::sourceSyntax($cible, $langage)
                    : FileFormats::sourceSyntax($origine, $langage);
                $this->assertTrue(
                    FileFormats::canBuild($syntaxe, $cible, $origine),
                    "« $nom » (bloc « $langage ») propose .$cible, que le serveur ne sait pas fabriquer depuis cette source."
                );
            }

            // Le format du bouton principal est celui du fichier, sauf refus.
            if ($r['refusal'] === '') {
                $this->assertSame($r['ext'], $r['targets'][0]);
                $this->assertSame($origine, $r['ext'], 'Le serveur et la carte lisent la même extension.');
            } else {
                $this->assertNotContains($r['ext'], $r['targets'], 'Un format refusé ne se propose pas lui-même.');
            }
        }
    }

    /**
     * L'issue de secours sans serveur enregistre le contenu TEL QUEL : son
     * extension doit dire ce qu'il est vraiment (du CSV, du Markdown), jamais
     * le format binaire demandé — un « .xlsx » rempli de texte ne s'ouvre pas.
     */
    public function testLIssueDeSecoursNePorteJamaisUneExtensionBinaire(): void
    {
        foreach (self::sortie()['describe'] as $i => $r) {
            $this->assertNotContains($r['raw'], ['xlsx', 'docx', 'pdf', 'rtf', 'pptx'], 'Fichier « ' . self::FICHIERS[$i][0] . ' »');
            $this->assertNull(FileFormats::unsupported($r['raw']));
        }
    }

    public function testNomsNettoyesCommeCoteServeur(): void
    {
        $noms = self::noms();
        $resultats = self::sortie()['names'];

        foreach ($noms as $i => $nom) {
            $r = $resultats[$i];
            $this->assertSame(FileFormats::extensionOf($r['clean']), $r['ext'], "Extension de « $nom »");
            $this->assertSame(FileNaming::stem($nom), $r['stem'], "Radical de « $nom »");
            $this->assertStringNotContainsString('/', $r['clean']);
            $this->assertStringNotContainsString('\\', $r['clean']);
        }
    }

    // ------------------------------------------------------------ tableau

    public function testLApercuDecoupeLeJeuDeCasPartage(): void
    {
        $cas = self::casPartages();
        $resultats = self::sortie()['fixtures'];

        foreach ($cas as $i => $c) {
            $this->assertSame($c['rows'], $resultats[$i]['rows'], 'Cas « ' . $c['name'] . ' »');
        }
    }

    /**
     * Parité PHP / JavaScript sur des entrées QUELCONQUES : les deux analyses
     * sont des miroirs, elles doivent s'accorder même sur un texte qui n'a
     * rien d'un CSV propre. Un aperçu à trois colonnes pour un classeur qui en
     * a quatre serait pire que pas d'aperçu du tout.
     */
    public function testLApercuEtLeClasseurDecoupentPareil(): void
    {
        $entrees   = self::entreesAleatoires();
        $resultats = self::sortie()['fuzz'];
        $this->assertCount(count($entrees), $resultats);

        foreach ($entrees as $i => $source) {
            $php = TabularData::parse($source);
            $this->assertSame(
                $php->rows(),
                $resultats[$i]['rows'],
                'Divergence sur ' . json_encode($source, JSON_UNESCAPED_UNICODE)
            );
            $this->assertSame($php->isFromMarkdown(), $resultats[$i]['markdown'], json_encode($source, JSON_UNESCAPED_UNICODE));
        }
    }

    // --------------------------------------------------------- délimiteurs

    /**
     * Un document livré en Markdown contient souvent un exemple de code,
     * délimité par trois accents graves comme le fichier qui le porte : sans
     * rééquilibrage, la carte se refermerait au premier ``` intérieur.
     */
    public function testUnDocumentQuiContientDuCodeResteEntier(): void
    {
        $r = self::sortie()['balance'];

        // Bloc intérieur avec langage : le délimiteur du fichier est allongé.
        $this->assertSame(
            "Voici :\n````markdown fichier=\"README.md\"\n# Titre\n```bash\nnpm i\n```\nFin.\n````\nSuite.",
            $r['imbrique']
        );
        // Pendant l'écriture (fermeture pas encore arrivée) : seule l'ouverture bouge.
        $this->assertSame(
            "````markdown fichier=\"README.md\"\n# Titre\n```bash\nnpm i\n```\nFin.",
            $r['en_cours']
        );
        // Deux blocs intérieurs, puis un second fichier : chacun garde sa fermeture.
        $this->assertSame(
            "````md fichier=\"a.md\"\n```js\n1\n```\n```py\n2\n```\n````\n```csv fichier=\"b.csv\"\nx;y\n```",
            $r['deux_blocs']
        );
    }

    public function testCeQuiNEstPasUnDocumentDeclareNEstPasTouche(): void
    {
        $r = self::sortie()['balance'];

        foreach (['ordinaire', 'tableur', 'deja_quatre', 'sans_bloc', 'texte_brut'] as $cas) {
            $this->assertSame($r[$cas . '_entree'], $r[$cas], "Cas « $cas » : le texte ne doit pas changer.");
        }
    }

    /** Réponse coupée pendant l'écriture d'un fichier : la note n'est pas une ligne du fichier. */
    public function testLaNoteDInterruptionEstSepareeDuFichier(): void
    {
        $r = self::sortie()['split'];

        $this->assertSame(
            ['source' => "Nom;Montant\nAlpha;10", 'note' => 'Réponse interrompue par l\'utilisateur'],
            $r['coupee']
        );
        $this->assertSame(['source' => "Nom;Montant\nAlpha;10\n", 'note' => ''], $r['entiere']);
        // Une ligne du fichier qui ressemble à de l'italique n'est pas une note.
        $this->assertSame(['source' => "a\n*[x]*\nb", 'note' => ''], $r['au_milieu']);
    }

    public function testBlocRefermeOuEncoreOuvert(): void
    {
        $r = self::sortie()['closed'];

        $this->assertTrue($r['ferme']);
        $this->assertFalse($r['ouvert']);
        $this->assertTrue($r['ferme_long'], 'Quatre accents ouverts, quatre fermés.');
        $this->assertFalse($r['trop_court'], 'Trois accents ne referment pas un bloc ouvert par quatre.');
        $this->assertTrue($r['tildes']);
        $this->assertFalse($r['melange'], 'Des tildes ne referment pas des accents graves.');
    }

    // ------------------------------------------------------------ harnais

    /**
     * Jeu de cas tabulaires partagé avec TabularDataTest (lu ici aussi : une
     * classe de test n'est pas chargée quand on lance celle-ci seule).
     *
     * @return list<array{name: string, source: string, rows: list<list<string>>}>
     */
    private static function casPartages(): array
    {
        $cas = json_decode((string)file_get_contents(ROOT . '/tests/Fixtures/tabular-cases.json'), true);
        if (!is_array($cas) || $cas === []) {
            self::fail('Jeu de cas tabulaires illisible.');
        }

        return $cas;
    }

    /** Noms de fichiers soumis aux deux nettoyages (navigateur et serveur). */
    private static function noms(): array
    {
        return [
            'budget.xlsx', 'src/app/main.py', '..\\..\\etc\\passwd', 'rapport.final.docx', 'v1.2', 'budget.2026',
            '.htaccess', 'a<b>:c?.csv', 'Synthèse été 2026.docx', '  espaces  .txt', 'sans-extension',
        ];
    }

    /**
     * Textes tirés au hasard, graine FIXE (le test est rejouable à l'identique).
     * Alphabet choisi pour les cas qui fâchent : séparateurs, guillemets,
     * sauts de ligne, formules, barres de table Markdown.
     *
     * @return list<string>
     */
    private static function entreesAleatoires(): array
    {
        $jetons = [
            'a', 'b', 'Nom', '1', '2,5', '1 200', 'é', 'Œuvre', '😀', 'x y', ' ', '  ', "\t", ';', ';', ',', '|',
            '"', '"', '""', "\n", "\n", "\r\n", '=', '=SI(', '(', ')', '-', '--', '---', ':', '*', '**', '`', '\\|',
            '<br>', '_', '#',
        ];
        $dernier = count($jetons) - 1;

        mt_srand(20260930);
        $entrees = [];
        for ($i = 0; $i < 600; $i++) {
            $longueur = mt_rand(1, 45);
            $texte    = '';
            for ($j = 0; $j < $longueur; $j++) {
                $texte .= $jetons[mt_rand(0, $dernier)];
            }
            $entrees[] = $texte;
        }
        // Tables Markdown tirées au hasard : la ligne de tirets est imposée.
        for ($i = 0; $i < 150; $i++) {
            $colonnes = mt_rand(1, 4);
            $lignes   = [];
            for ($l = 0; $l < mt_rand(2, 5); $l++) {
                $cellules = [];
                for ($c = 0; $c < $colonnes + mt_rand(-1, 1); $c++) {
                    $cellule = '';
                    for ($j = 0; $j < mt_rand(0, 4); $j++) {
                        $cellule .= $jetons[mt_rand(0, $dernier)];
                    }
                    $cellules[] = str_replace(["\n", "\r"], ' ', $cellule);
                }
                $lignes[] = '| ' . implode(' | ', $cellules) . ' |';
            }
            array_splice($lignes, 1, 0, ['|' . str_repeat(' --- |', $colonnes)]);
            $entrees[] = implode("\n", $lignes);
        }
        mt_srand();

        return $entrees;
    }

    /** @return array<string, mixed> */
    private static function sortie(): array
    {
        if (self::$sortie !== null) {
            return self::$sortie;
        }
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        I18n::use('fr');
        $entree = [
            'parseInfo' => array_column(self::DECLARATIONS, 0),
            'attributs' => array_map(
                static fn (string $a): string => 'csv ' . $a . '="donnees.csv"',
                FileDelivery::ATTRIBUTES
            ),
            'describe'  => array_map(static fn (array $f): array => [$f[0], $f[1]], self::FICHIERS),
            'names'     => self::noms(),
            'fixtures'  => array_column(self::casPartages(), 'source'),
            'fuzz'      => self::entreesAleatoires(),
        ];

        $script = "'use strict';\n"
            . 'globalThis.FILE_FORMATS = ' . json_encode(FileFormats::clientCatalog()) . ";\n"
            . "globalThis.t = (s) => s;\nglobalThis.tn = (n, un, plusieurs) => (n > 1 ? plusieurs : un);\n"
            . (string)file_get_contents(ROOT . '/public/assets/js/generated-files.js') . "\n"
            . 'const ENTREE = ' . json_encode($entree) . ";\n"
            . self::SCENARIOS;

        $fichier = tempnam(sys_get_temp_dir(), 'mia-fichiers-js-');
        file_put_contents($fichier, $script);
        try {
            $processus = proc_open([$node, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            if (!is_resource($processus)) {
                self::fail('Impossible de lancer node.');
            }
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($fichier);
        }

        $resultats = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultats)) {
            self::fail("generated-files.js a échoué sous node (code $code) :\n" . $erreurs);
        }

        return self::$sortie = $resultats;
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }

        return null;
    }

    /**
     * Sous node, le module n'expose que sa partie pure (`globalThis.MiaFiles`).
     * Les scénarios à texte fixe vivent ici, écrits comme ils le seraient dans
     * une réponse : les accents graves y sont littéraux.
     */
    private const SCENARIOS = <<<'JS'
        const M = globalThis.MiaFiles;
        if (!M || typeof M.parseInfo !== 'function') {
            throw new Error('MiaFiles absent : la partie pure du module ne s’est pas chargée.');
        }
        if (typeof M.hydrate === 'function') {
            throw new Error('La partie DOM ne doit pas se charger sans document.');
        }

        const F = '```';
        const balance = {};
        const intact = (nom, texte) => { balance[nom + '_entree'] = texte; balance[nom] = M.balanceFences(texte); };

        balance.imbrique = M.balanceFences(
            'Voici :\n' + F + 'markdown fichier="README.md"\n# Titre\n' + F + 'bash\nnpm i\n' + F + '\nFin.\n' + F + '\nSuite.'
        );
        balance.en_cours = M.balanceFences(
            F + 'markdown fichier="README.md"\n# Titre\n' + F + 'bash\nnpm i\n' + F + '\nFin.'
        );
        balance.deux_blocs = M.balanceFences(
            F + 'md fichier="a.md"\n' + F + 'js\n1\n' + F + '\n' + F + 'py\n2\n' + F + '\n' + F + '\n'
            + F + 'csv fichier="b.csv"\nx;y\n' + F
        );
        // Bloc ordinaire (sans nom) : jamais rééquilibré.
        intact('ordinaire', F + 'markdown\n# Titre\n' + F + 'bash\nnpm i\n' + F + '\nFin.\n' + F);
        // Tableur déclaré : son contenu n'est pas du Markdown, rien à imbriquer.
        intact('tableur', F + 'csv fichier="b.xlsx"\na;b\n' + F + '\nTexte\n' + F + 'js\n1\n' + F);
        // Déjà délimité par quatre accents, comme le prompt le demande.
        intact('deja_quatre', F + '`markdown fichier="README.md"\n' + F + 'bash\nnpm i\n' + F + '\n' + F + '`');
        intact('sans_bloc', 'Un texte sans aucun bloc de code.');
        // Document déclaré en texte brut : ses accents graves sont du texte.
        intact('texte_brut', F + 'text fichier="notes.txt"\n' + F + 'bash\nx\n' + F + '\nfin\n' + F);

        const table = (source) => { const t = M.parseTable(source); return { rows: t.rows, markdown: t.markdown }; };

        const sortie = {
            parseInfo: ENTREE.parseInfo.map((ligne) => M.parseInfo(ligne)),
            attributs: ENTREE.attributs.map((ligne) => M.parseInfo(ligne)),
            describe: ENTREE.describe.map(([nom, langage]) => {
                const d = M.describe(nom, langage);
                return {
                    name: d.name, stem: d.stem, ext: d.ext, lang: d.lang,
                    label: d.format.label, family: d.format.family, tone: d.format.tone,
                    refusal: d.refusal, targets: d.targets, preview: d.preview, raw: M.rawExtension(d),
                };
            }),
            names: ENTREE.names.map((nom) => ({ ext: M.extensionOf(M.cleanName(nom)), clean: M.cleanName(nom), stem: M.stemOf(nom) })),
            fixtures: ENTREE.fixtures.map(table),
            fuzz: ENTREE.fuzz.map(table),
            balance: balance,
            split: {
                coupee: M.splitInterruption('Nom;Montant\nAlpha;10\n\n*[Réponse interrompue par l\'utilisateur]*'),
                entiere: M.splitInterruption('Nom;Montant\nAlpha;10\n'),
                au_milieu: M.splitInterruption('a\n*[x]*\nb'),
            },
            closed: {
                ferme: M.isClosedFence(F + 'csv fichier="a.csv"\na;b\n' + F),
                ouvert: M.isClosedFence(F + 'csv fichier="a.csv"\na;b\n1;2'),
                ferme_long: M.isClosedFence(F + '`md fichier="a.md"\n' + F + '\nx\n' + F + '\n' + F + '`\n'),
                trop_court: M.isClosedFence(F + '`md fichier="a.md"\nx\n' + F),
                tildes: M.isClosedFence('~~~csv fichier="a.csv"\na;b\n~~~'),
                melange: M.isClosedFence(F + 'csv fichier="a.csv"\na;b\n~~~'),
            },
        };
        process.stdout.write(JSON.stringify(sortie));
        JS;
}
