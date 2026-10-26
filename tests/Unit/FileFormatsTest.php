<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fichiers livrés dans une conversation — le CATALOGUE et ce qui l'entoure :
 * formats fabriqués, formats refusés, noms de fichiers, convention de
 * livraison, échecs présentables.
 *
 * Tout ce qui est testé ici est pur : aucune bibliothèque, aucune base. C'est
 * ce socle qui décide de ce que la carte propose et de ce que le serveur
 * accepte — les deux doivent rester d'accord, d'où les invariants croisés
 * (un menu ne propose jamais un format que le serveur refuserait).
 */
final class FileFormatsTest extends TestCase
{
    /**
     * Libellés courts identiques dans toutes les langues : noms de produits et
     * de formats. Tout autre libellé court doit avoir sa traduction.
     */
    private const NOMS_PROPRES = [
        'Excel', 'CSV', 'TSV', 'Word', 'PDF', 'RTF', 'Markdown', 'JSON', 'JSON Lines', 'XML', 'YAML',
        'TOML', 'INI', 'SQL', 'iCalendar', 'vCard', 'SRT', 'WebVTT', 'Log', 'HTML', 'CSS', 'SVG',
        'JavaScript', 'TypeScript', 'Python', 'PHP', 'Shell', 'Batch', 'PowerShell', 'LaTeX', 'PowerPoint',
    ];

    private const FAMILLES = [
        FileFormats::FAMILY_TABLE, FileFormats::FAMILY_DOCUMENT, FileFormats::FAMILY_PRESENTATION,
        FileFormats::FAMILY_TEXT, FileFormats::FAMILY_CODE,
    ];

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    // ---------------------------------------------------------- catalogue

    /** Les formats nommés dans la demande d'origine, plus ceux qui vont avec. */
    public function testLesFormatsAttendusSontFabriques(): void
    {
        foreach (['csv', 'xlsx', 'docx', 'rtf', 'txt', 'pdf', 'md', 'json', 'pptx'] as $ext) {
            $this->assertTrue(FileFormats::isKnown($ext), ".$ext doit être au catalogue.");
        }
    }

    public function testChaqueFormatDuCatalogueEstComplet(): void
    {
        $this->assertNotEmpty(FileFormats::extensions());

        foreach (FileFormats::extensions() as $ext) {
            $f = FileFormats::get($ext);

            $this->assertSame($ext, $f['ext']);
            $this->assertSame($ext, FileFormats::normalizeExtension($ext), "Clé de catalogue non normalisée : $ext");
            $this->assertMatchesRegularExpression('#^[a-z]+/[a-z0-9.+-]+#', $f['mime'], "Type MIME de .$ext");
            $this->assertNotSame('', $f['label'], "Libellé de .$ext");
            $this->assertNotSame('', $f['short'], "Libellé court de .$ext");
            $this->assertNotSame('', $f['stem'], "Nom par défaut de .$ext");
            $this->assertContains($f['family'], self::FAMILLES, "Famille de .$ext");
            $this->assertGreaterThan(0, $f['max']);
            $this->assertLessThanOrEqual(FileFormats::DEFAULT_MAX_BYTES, $f['max'], "Plafond de .$ext au-delà du plafond de la requête");
            $this->assertContains($f['eol'], ["\n", "\r\n"]);

            $this->assertTrue(class_exists($f['builder']), "Fabricant de .$ext introuvable : {$f['builder']}");
            $this->assertTrue(
                is_subclass_of($f['builder'], FileBuilder::class),
                "{$f['builder']} doit implémenter FileBuilder."
            );
        }
    }

    /** Un texte ne porte pas de BOM « binaire » : seuls les vrais binaires le sont. */
    public function testSeulsLesFormatsFabriquesParUneBibliothequeSontBinaires(): void
    {
        $binaires = array_values(array_filter(
            FileFormats::extensions(),
            static fn (string $ext): bool => FileFormats::get($ext)['binary']
        ));
        sort($binaires);

        $this->assertSame(['docx', 'pdf', 'pptx', 'xlsx'], $binaires);
    }

    public function testUneExtensionInconnueEstUnFichierTexteRecopie(): void
    {
        $f = FileFormats::get('conf');

        $this->assertSame('conf', $f['ext']);
        $this->assertSame(FileFormats::FAMILY_CODE, $f['family']);
        $this->assertSame('TextFileBuilder', $f['builder']);
        $this->assertSame('Fichier CONF', $f['label']);
        $this->assertStringStartsWith('text/plain', $f['mime']);
        $this->assertFalse(FileFormats::isKnown('conf'));

        // Pas d'extension du tout : du texte s'enregistre toujours.
        $this->assertSame('txt', FileFormats::get('')['ext']);
    }

    public function testNormalisationDUneExtension(): void
    {
        $this->assertSame('xlsx', FileFormats::normalizeExtension('.XLSX'));
        $this->assertSame('xlsx', FileFormats::normalizeExtension('  xlsx '));
        $this->assertSame('v2', FileFormats::normalizeExtension('v2'));
        $this->assertSame('7z', FileFormats::normalizeExtension('7z'));

        // Sans lettre, ce n'est pas une extension : « budget.2026 », « v1.2 ».
        $this->assertSame('', FileFormats::normalizeExtension('2026'));
        $this->assertSame('', FileFormats::normalizeExtension('2'));
        // Ni tiret, ni espace, ni plus de dix caractères.
        $this->assertSame('', FileFormats::normalizeExtension('tar-gz'));
        $this->assertSame('', FileFormats::normalizeExtension('a b'));
        $this->assertSame('', FileFormats::normalizeExtension('abcdefghijk'));
        $this->assertSame('', FileFormats::normalizeExtension(''));
    }

    public function testExtensionDUnNomDeFichier(): void
    {
        $this->assertSame('docx', FileFormats::extensionOf('rapport.final.docx'));
        $this->assertSame('csv', FileFormats::extensionOf('Export.CSV'));
        $this->assertSame('', FileFormats::extensionOf('budget.2026'));
        $this->assertSame('', FileFormats::extensionOf('sans-extension'));
        // Un point en tête n'est pas un séparateur d'extension.
        $this->assertSame('', FileFormats::extensionOf('.htaccess'));
    }

    // ---------------------------------------------------------- langages

    public function testLangageDUnBlocVersExtension(): void
    {
        $this->assertSame('py', FileFormats::extensionForLanguage('python'));
        $this->assertSame('csv', FileFormats::extensionForLanguage(' CSV '));
        $this->assertSame('sh', FileFormats::extensionForLanguage('bash'));
        $this->assertSame('txt', FileFormats::extensionForLanguage('text'));
        // Le format voulu écrit en guise de langage (```xlsx) : une cible.
        $this->assertSame('xlsx', FileFormats::extensionForLanguage('xlsx'));
        $this->assertSame('docx', FileFormats::extensionForLanguage('docx'));
        $this->assertSame('', FileFormats::extensionForLanguage('inconnu'));
        $this->assertSame('', FileFormats::extensionForLanguage(''));
    }

    /**
     * Un bloc ```markdown est déballé par l'écran (texte rédigé), un bloc
     * ```mermaid est rendu en schéma : ni l'un ni l'autre ne doit recevoir le
     * bouton « Télécharger » d'un bloc de code ordinaire.
     */
    public function testMarkdownEtMermaidNeSontPasDesLangagesDeFichier(): void
    {
        foreach (['markdown', 'md', 'mermaid'] as $langage) {
            $this->assertArrayNotHasKey($langage, FileFormats::languages());
        }
        $this->assertSame('', FileFormats::extensionForLanguage('markdown'));
        $this->assertSame('', FileFormats::extensionForLanguage('mermaid'));
    }

    public function testChaqueLangageMeneAUnFormatDuCatalogue(): void
    {
        foreach (FileFormats::languages() as $langage => $ext) {
            $this->assertSame(strtolower($langage), $langage, "Langage non normalisé : $langage");
            $this->assertTrue(FileFormats::isKnown($ext), "Le langage « $langage » mène à .$ext, absent du catalogue.");
        }
    }

    // ------------------------------------------------ syntaxe de la source

    public function testLaSyntaxeVientDeLOrigine(): void
    {
        // Un tableur est écrit en tableau, quel que soit le langage du bloc.
        foreach (['xlsx', 'csv', 'tsv'] as $ext) {
            foreach (['csv', 'markdown', '', 'text'] as $langage) {
                $this->assertSame(FileFormats::SYNTAX_TABLE, FileFormats::sourceSyntax($ext, $langage));
            }
        }

        // Un document mis en forme — ou une présentation — est écrit en Markdown…
        foreach (['docx', 'pdf', 'rtf', 'pptx'] as $ext) {
            $this->assertSame(FileFormats::SYNTAX_MARKDOWN, FileFormats::sourceSyntax($ext, 'markdown'));
            $this->assertSame(FileFormats::SYNTAX_MARKDOWN, FileFormats::sourceSyntax($ext, ''));
            // … sauf si son bloc dit autre chose : ses astérisques sont alors du texte.
            $this->assertSame(FileFormats::SYNTAX_TEXT, FileFormats::sourceSyntax($ext, 'text'));
            $this->assertSame(FileFormats::SYNTAX_TEXT, FileFormats::sourceSyntax($ext, 'PLAINTEXT'));
            $this->assertSame(FileFormats::SYNTAX_HTML, FileFormats::sourceSyntax($ext, 'html'));
        }
        $this->assertSame(FileFormats::SYNTAX_MARKDOWN, FileFormats::sourceSyntax('md', 'markdown'));

        // Tout le reste est recopié.
        foreach (['txt', 'json', 'py', 'html', 'conf', ''] as $ext) {
            $this->assertSame(FileFormats::SYNTAX_RAW, FileFormats::sourceSyntax($ext, 'markdown'));
        }
    }

    public function testConversionsPossibles(): void
    {
        $table = FileFormats::SYNTAX_TABLE;
        foreach (['xlsx', 'csv', 'tsv'] as $ext) {
            $this->assertTrue(FileFormats::canBuild($table, $ext, 'xlsx'));
        }
        foreach (['docx', 'pdf', 'txt', 'json'] as $ext) {
            $this->assertFalse(FileFormats::canBuild($table, $ext, 'xlsx'), "Un tableau ne devient pas un .$ext");
        }

        foreach ([FileFormats::SYNTAX_MARKDOWN, FileFormats::SYNTAX_TEXT, FileFormats::SYNTAX_HTML] as $document) {
            foreach (['docx', 'pdf', 'rtf', 'md', 'txt'] as $ext) {
                $this->assertTrue(FileFormats::canBuild($document, $ext, 'docx'));
            }
            $this->assertFalse(FileFormats::canBuild($document, 'xlsx', 'docx'), 'Un courrier ne devient pas un classeur.');
            $this->assertFalse(FileFormats::canBuild($document, 'csv', 'docx'));
            // Le PowerPoint ne se fabrique que depuis une présentation : un
            // compte rendu découpé en slides à chaque titre n'en est pas une.
            $this->assertFalse(FileFormats::canBuild($document, 'pptx', 'docx'), 'Un courrier ne devient pas une présentation.');
            foreach (['pptx', 'ppt', 'odp', 'key'] as $presentation) {
                $this->assertTrue(FileFormats::canBuild($document, 'pptx', $presentation), "Présentation .$presentation → .pptx");
            }
        }

        // Recopie : la même extension, ou du texte — rien d'autre.
        $brut = FileFormats::SYNTAX_RAW;
        $this->assertTrue(FileFormats::canBuild($brut, 'json', 'json'));
        $this->assertTrue(FileFormats::canBuild($brut, 'txt', 'json'));
        $this->assertFalse(FileFormats::canBuild($brut, 'xlsx', 'json'));
        $this->assertFalse(FileFormats::canBuild($brut, 'py', 'json'));
    }

    /**
     * L'invariant qui tient la carte et le serveur d'accord : toute entrée du
     * menu « Autres formats » est fabricable depuis la source du fichier. Un
     * menu qui proposerait un format que le serveur refuse serait un bouton
     * qui ne mène qu'à une erreur.
     */
    public function testLeMenuNeProposeQueDesFormatsFabricables(): void
    {
        foreach (FileFormats::extensions() as $ext) {
            $alternatives = FileFormats::alternatives($ext);
            if ($alternatives === []) {
                continue;
            }
            $this->assertSame($ext, $alternatives[0], "Le format demandé ouvre son propre menu (.$ext).");
            $this->assertSame($alternatives, array_values(array_unique($alternatives)), "Doublon au menu de .$ext");

            foreach (['', 'csv', 'markdown', 'text', 'html'] as $langage) {
                $syntaxe = FileFormats::sourceSyntax($ext, $langage);
                foreach ($alternatives as $cible) {
                    $this->assertTrue(FileFormats::isKnown($cible), "Menu de .$ext : .$cible hors catalogue");
                    $this->assertTrue(
                        FileFormats::canBuild($syntaxe, $cible, $ext),
                        "Menu de .$ext (bloc « $langage ») : .$cible est proposé mais serait refusé."
                    );
                }
            }
        }
    }

    public function testMenusDesDeuxFamilles(): void
    {
        $this->assertSame(['xlsx', 'csv'], FileFormats::alternatives('xlsx'));
        $this->assertSame(['csv', 'xlsx'], FileFormats::alternatives('csv'));
        // .tsv n'est pas proposé d'office, mais il n'est pas perdu quand on le demande.
        $this->assertSame(['tsv', 'xlsx', 'csv'], FileFormats::alternatives('tsv'));
        $this->assertSame(['docx', 'pdf', 'rtf', 'md', 'txt'], FileFormats::alternatives('docx'));
        $this->assertSame(['rtf', 'docx', 'pdf', 'md', 'txt'], FileFormats::alternatives('rtf'));
        // Une présentation : PowerPoint, PDF de slides, Word, ou sa source — jamais de RTF.
        $this->assertSame(['pptx', 'pdf', 'docx', 'md'], FileFormats::alternatives('pptx'));
        // Texte et code : un seul format, donc pas de menu.
        $this->assertSame([], FileFormats::alternatives('txt'));
        $this->assertSame([], FileFormats::alternatives('json'));
        $this->assertSame([], FileFormats::alternatives('conf'));
    }

    // ---------------------------------------------------- formats refusés

    public function testFormatsQuOnNeSaitPasFabriquer(): void
    {
        // Le PowerPoint se fabrique ; ses anciens formats et ses cousins se
        // proposent en .pptx.
        $this->assertNull(FileFormats::unsupported('pptx'));
        foreach (['ppt', 'odp', 'key'] as $ext) {
            $refus = FileFormats::unsupported($ext);
            $this->assertNotNull($refus, ".$ext");
            $this->assertStringContainsString('.pptx', $refus['why']);
            $this->assertSame(['pptx', 'pdf'], $refus['use']);
            $this->assertTrue(FileFormats::isPresentation($ext));
        }
        $this->assertTrue(FileFormats::isPresentation('.PPTX'));
        $this->assertFalse(FileFormats::isPresentation('docx'));
        $this->assertFalse(FileFormats::isPresentation(''));

        $this->assertSame(['xlsx', 'csv'], FileFormats::unsupported('.XLS')['use']);
        $this->assertSame([], FileFormats::unsupported('zip')['use']);
        $this->assertNull(FileFormats::unsupported('xlsx'));
        $this->assertNull(FileFormats::unsupported('conf'));
    }

    public function testUnFormatRefuseProposeDesIssuesFabricables(): void
    {
        $refuses = FileFormats::clientCatalog()['unsupported'];
        $this->assertNotEmpty($refuses);

        foreach ($refuses as $ext => $refus) {
            $this->assertFalse(FileFormats::isKnown((string)$ext), ".$ext est à la fois fabriqué et refusé.");
            $this->assertNotSame('', trim($refus['why']), "Refus de .$ext sans explication.");

            foreach ($refus['use'] as $issue) {
                $this->assertTrue(FileFormats::isKnown($issue), "Issue .$issue de .$ext hors catalogue.");
                $this->assertNull(FileFormats::unsupported($issue), "Issue .$issue de .$ext elle-même refusée.");
            }
        }
    }

    // ------------------------------------------------- catalogue du client

    public function testCatalogueLivreAuNavigateur(): void
    {
        $client = FileFormats::clientCatalog();

        $this->assertSame('/files/build', $client['endpoint']);
        $this->assertSame(FileFormats::ENDPOINT, $client['endpoint']);
        $this->assertSame(FileDelivery::ATTRIBUTES, $client['attributes']);
        $this->assertSame(FileFormats::languages(), $client['languages']);
        $this->assertSame(FileFormats::DEFAULT_MAX_BYTES, $client['maxBytes']);
        $this->assertSame(['xlsx', 'csv'], $client['alternatives'][FileFormats::FAMILY_TABLE]);
        $this->assertSame(['docx', 'pdf', 'rtf', 'md', 'txt'], $client['alternatives'][FileFormats::FAMILY_DOCUMENT]);
        $this->assertSame(['pptx', 'pdf', 'docx', 'md'], $client['alternatives'][FileFormats::FAMILY_PRESENTATION]);
        $this->assertStringContainsString('{type}', $client['generic']['label']);

        $this->assertSame(FileFormats::extensions(), array_map('strval', array_keys($client['formats'])));
        foreach ($client['formats'] as $ext => $f) {
            $this->assertSame(['label', 'menu', 'family', 'tone', 'stem'], array_keys($f));
            $this->assertStringEndsWith('(.' . $ext . ')', $f['menu']);
        }
        $this->assertSame('Excel (.xlsx)', $client['formats']['xlsx']['menu']);
        $this->assertSame('Classeur Excel', $client['formats']['xlsx']['label']);

        // Injecté dans un <script> : doit s'encoder sans erreur.
        $this->assertIsString(json_encode($client, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** La carte d'un anglophone ne doit pas annoncer un « Classeur Excel ». */
    public function testLeCatalogueSuitLaLangue(): void
    {
        I18n::use('en');
        $client = FileFormats::clientCatalog();

        $this->assertSame('Excel workbook', $client['formats']['xlsx']['label']);
        $this->assertSame('Word document', $client['formats']['docx']['label']);
        $this->assertSame('Text (.txt)', $client['formats']['txt']['menu']);
        $this->assertSame('{type} file', $client['generic']['label']);
        $this->assertSame('CONF file', FileFormats::get('conf')['label']);
        $this->assertSame('PowerPoint presentation', $client['formats']['pptx']['label']);
        $this->assertSame('PowerPoint (.pptx)', $client['formats']['pptx']['menu']);
        $this->assertSame('Legacy PowerPoint format: MIA creates .pptx presentations.', $client['unsupported']['ppt']['why']);
    }

    /**
     * Les libellés du catalogue passent par t() avec une clé CALCULÉE : le
     * test de couverture des traductions, qui ne lit que les appels littéraux,
     * ne les voit pas. Sans ce contrôle, un format ajouté resterait en français
     * dans une interface anglaise, sans qu'aucun test ne le dise.
     */
    public function testChaqueLibelleDuCatalogueEstTraduit(): void
    {
        /** @var array<string, string> $anglais */
        $anglais = require ROOT . '/lang/en.php';
        $classe  = new ReflectionClass(FileFormats::class);

        foreach ($classe->getConstant('CATALOG') as $ext => $entree) {
            $this->assertArrayHasKey($entree['label'], $anglais, "Libellé de .$ext sans traduction.");
            $this->assertArrayHasKey($entree['stem'], $anglais, "Nom par défaut de .$ext sans traduction.");
            if (!in_array($entree['short'], self::NOMS_PROPRES, true)) {
                $this->assertArrayHasKey($entree['short'], $anglais, "Libellé court de .$ext sans traduction.");
            }
        }
        foreach ($classe->getConstant('UNSUPPORTED') as $ext => $entree) {
            $this->assertArrayHasKey($entree['why'], $anglais, "Refus de .$ext sans traduction.");
        }
    }

    // ------------------------------------------------------ noms de fichiers

    public function testLeNomNeGardeQueSonDernierSegment(): void
    {
        $this->assertSame('main', FileNaming::stem('src/app/main.py'));
        $this->assertSame('passwd', FileNaming::stem('..\\..\\etc\\passwd'));
        $this->assertSame('passwd.xlsx', FileNaming::build('../../etc/passwd.xlsx', 'xlsx', 'tableau'));
        $this->assertSame('rapport.csv', FileNaming::build('C:\\Users\\moi\\rapport.csv', 'csv'));
    }

    public function testLExtensionEstCelleDuFormatFabrique(): void
    {
        // Un classeur nommé « budget.csv » s'ouvrirait comme du texte.
        $this->assertSame('budget.xlsx', FileNaming::build('budget.csv', 'xlsx'));
        $this->assertSame('rapport.final.pdf', FileNaming::build('rapport.final.docx', 'pdf'));
        // Un point qui n'introduit pas une extension fait partie du nom.
        $this->assertSame('v1.2.txt', FileNaming::build('v1.2', 'txt'));
        $this->assertSame('budget.2026.xlsx', FileNaming::build('budget.2026', 'xlsx'));
    }

    public function testNomParDefautQuandRienNEstExploitable(): void
    {
        $this->assertSame('tableau.csv', FileNaming::build('', 'csv', 'tableau'));
        $this->assertSame('texte.txt', FileNaming::build('???', 'txt', 'texte'));
        $this->assertSame('fichier.txt', FileNaming::build(' .. ', 'txt', ''));
        $this->assertSame('', FileNaming::stem('  ..  '));
    }

    public function testCaracteresRefusesParLesSystemes(): void
    {
        $this->assertSame('a-b-c', FileNaming::stem('a<b>:c?.csv'));
        // Fichier caché sous Unix, point final retiré par Windows.
        $this->assertSame('htaccess', FileNaming::stem('.htaccess'));
        $this->assertSame('notes', FileNaming::stem('notes. .txt'));
        // Noms réservés par Windows, avec ou sans extension.
        $this->assertSame('_CON', FileNaming::stem('CON.txt'));
        $this->assertSame('_nul', FileNaming::stem('nul'));
        $this->assertSame('console', FileNaming::stem('console.txt'));
    }

    /** Le nom finit dans un en-tête HTTP : aucun retour à la ligne n'y entre. */
    public function testAucunCaractereDeControleDansUnNom(): void
    {
        $nom = FileNaming::build("a\r\nSet-Cookie: x.csv", 'csv');

        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $nom);
        $this->assertStringNotContainsString(':', $nom);
        $this->assertStringEndsWith('.csv', $nom);
    }

    public function testLongueurBornee(): void
    {
        $nom = FileNaming::build(str_repeat('é', 300) . '.docx', 'docx');

        $this->assertSame(FileNaming::MAX_LENGTH, mb_strlen($nom, 'UTF-8'));
        $this->assertStringEndsWith('.docx', $nom);
        $this->assertTrue(mb_check_encoding($nom, 'UTF-8'), 'La troncature ne doit pas couper un caractère.');
    }

    public function testUtf8InvalideNettoye(): void
    {
        $nom = FileNaming::build("rapport\xC3.docx", 'docx');

        $this->assertTrue(mb_check_encoding($nom, 'UTF-8'));
        $this->assertStringEndsWith('.docx', $nom);
    }

    public function testEnTeteDeTelechargement(): void
    {
        $this->assertSame(
            'attachment; filename="Synthese ete 2026.docx"; filename*=UTF-8\'\'Synth%C3%A8se%20%C3%A9t%C3%A9%202026.docx',
            FileNaming::contentDisposition('Synthèse été 2026.docx')
        );
        $this->assertSame(
            'attachment; filename="budget.xlsx"; filename*=UTF-8\'\'budget.xlsx',
            FileNaming::contentDisposition('budget.xlsx')
        );
    }

    public function testVarianteAscii(): void
    {
        $this->assertSame('OEuvre - lete.pdf', FileNaming::asciiFallback('Œuvre — l’été.pdf'));
        $this->assertSame('a b.csv', FileNaming::asciiFallback("a\u{00A0}b.csv"));
        // Autre alphabet : le nom de repli garde l'EXTENSION, sans quoi le
        // fichier enregistré par un vieux client ne s'ouvrirait avec rien.
        $this->assertSame('fichier.xlsx', FileNaming::asciiFallback('予算.xlsx'));
        $this->assertSame('fichier', FileNaming::asciiFallback('日本語'));

        foreach (['Synthèse été.docx', '予算.xlsx', 'a"b.csv', "x\r\ny.txt"] as $nom) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9._ -]+$/', FileNaming::asciiFallback($nom));
        }
    }

    // --------------------------------------------- convention de livraison

    public function testLePromptEnseigneLaConvention(): void
    {
        $prompt = FileDelivery::promptFragment();

        $this->assertStringContainsString('```csv fichier="budget-2026.xlsx"', $prompt);
        $this->assertStringContainsString('« Télécharger »', $prompt);
        foreach (['.xlsx', '.csv', '.docx', '.rtf', '.pdf', '.txt', '.pptx'] as $ext) {
            $this->assertStringContainsString($ext, $prompt, "Le prompt doit nommer $ext.");
        }
        // La convention des slides : celle que MarkdownDeck lit (cf. MarkdownDeckTest).
        foreach (['un titre `#` pour la couverture', 'un titre `##` par slide', 'Notes : …', '« — Auteur »'] as $convention) {
            $this->assertStringContainsString($convention, $prompt);
        }
        // Le PowerPoint n'est plus un format impossible.
        $this->assertStringNotContainsString('Formats impossibles ici (PowerPoint', $prompt);
        // Le document mis en forme est la SEULE exception à l'interdiction des
        // blocs ```markdown (cf. MarkdownFenceRenderingTest) : elle doit être dite.
        $this->assertStringContainsString('seule exception', $prompt);
        $this->assertStringContainsString('quatre accents graves', $prompt);
        $this->assertStringContainsString("N'invente JAMAIS de lien de téléchargement", $prompt);
        // Ce qui ne se livre pas ainsi renvoie aux libellés EXACTS du menu.
        foreach (['menu « + »', '« Créer »', '« Image »', '« Page web »', '« Vidéo »'] as $libelle) {
            $this->assertStringContainsString($libelle, $prompt);
        }
        // L'outil Présentation a quitté le menu : la retouche passe par la carte.
        $this->assertStringContainsString('« Modifier les slides »', $prompt);
        $this->assertStringNotContainsString('« Présentation » du bouton « Outils »', $prompt);
    }

    /**
     * L'attribut que le prompt enseigne doit être de ceux que l'écran accepte,
     * dans les deux langues — sinon le modèle obéit et aucune carte n'apparaît.
     */
    public function testLAttributEnseigneEstAccepteParLEcran(): void
    {
        $this->assertSame(1, preg_match('/```csv (\w+)="budget-2026\.xlsx"/', FileDelivery::promptFragment(), $fr));
        $this->assertContains($fr[1], FileDelivery::ATTRIBUTES);
        $this->assertSame('fichier', $fr[1]);

        I18n::use('en');
        $anglais = FileDelivery::promptFragment();
        $this->assertSame(1, preg_match('/```csv (\w+)="budget-2026\.xlsx"/', $anglais, $en));
        $this->assertContains($en[1], FileDelivery::ATTRIBUTES);
        $this->assertSame('file', $en[1]);

        // Les libellés cités sont ceux de l'interface anglaise.
        $this->assertStringContainsString('“Download”', $anglais);
        foreach (['“Create”', '“Image”', '“Web page”', '“Video”', '“Edit slides”'] as $libelle) {
            $this->assertStringContainsString($libelle, $anglais);
        }
        $this->assertStringNotContainsString('Télécharger', $anglais);
    }

    public function testAttributsAcceptes(): void
    {
        $this->assertSame(FileDelivery::ATTRIBUTES, array_values(array_unique(FileDelivery::ATTRIBUTES)));
        foreach (FileDelivery::ATTRIBUTES as $attribut) {
            // Ils finissent dans une expression régulière côté navigateur.
            $this->assertMatchesRegularExpression('/^[a-z]+$/', $attribut);
        }
        foreach (['fichier', 'file', 'filename', 'title'] as $attendu) {
            $this->assertContains($attendu, FileDelivery::ATTRIBUTES);
        }
    }

    // ------------------------------------------------- échecs présentables

    public function testStatutHttpDeChaqueEchec(): void
    {
        $this->assertSame(422, FileBuildException::emptySource()->httpStatus());
        $this->assertSame(413, FileBuildException::tooLarge(2_000_000)->httpStatus());
        $this->assertSame(413, FileBuildException::tableTooLarge(30000, 4)->httpStatus());
        $this->assertSame(413, FileBuildException::tooManySlides(31, 30)->httpStatus());
        $this->assertSame(415, FileBuildException::unsupported('Pourquoi')->httpStatus());
        $this->assertSame(415, FileBuildException::incompatible('Word')->httpStatus());
        $this->assertSame(503, FileBuildException::unavailable('Excel')->httpStatus());
        $this->assertSame(422, FileBuildException::invalid('Illisible')->httpStatus());
        $this->assertSame(422, FileBuildException::noTable()->httpStatus());
        $this->assertSame(500, FileBuildException::failed('PDF')->httpStatus());
    }

    public function testChargeUtileRendueAuNavigateur(): void
    {
        $echec = FileBuildException::unavailable('Excel', ['csv', 'csv', 'xlsx']);

        $this->assertSame(
            [
                'error'        => 'La création de ce format (Excel) n’est pas disponible sur ce serveur.',
                'code'         => FileBuildException::UNAVAILABLE,
                'alternatives' => ['csv', 'xlsx'],
            ],
            $echec->toPayload()
        );
    }

    public function testLesIssuesPeuventEtreAjouteesParLAppelant(): void
    {
        $origine = FileBuildException::tableTooLarge(30000, 4);
        $enrichi = $origine->withAlternatives(['csv']);

        $this->assertSame([], $origine->alternatives());
        $this->assertSame(['csv'], $enrichi->alternatives());
        $this->assertSame($origine->kind(), $enrichi->kind());
        $this->assertSame($origine->getMessage(), $enrichi->getMessage());
        $this->assertStringContainsString('30000 lignes × 4 colonnes', $enrichi->getMessage());
    }

    public function testTailleLisibleDansLeMessage(): void
    {
        $this->assertStringContainsString('plus de 2 Mo', FileBuildException::tooLarge(2_000_000)->getMessage());
        $this->assertStringContainsString('plus de 1,5 Mo', FileBuildException::tooLarge(1_500_000)->getMessage());
        $this->assertStringContainsString('plus de 400 Ko', FileBuildException::tooLarge(400_000)->getMessage());
    }

    /** Le détail technique part au journal : il ne doit jamais atteindre l'écran. */
    public function testUnePanneNeFuitAucunDetailTechnique(): void
    {
        $cause = new RuntimeException('ZipArchive::close(): /var/www/html/storage/cache/files/x.xlsx: Permission denied');
        $echec = FileBuildException::failed('Excel', ['csv'], $cause);

        $this->assertSame('Le fichier Excel n’a pas pu être créé.', $echec->getMessage());
        $this->assertSame($cause, $echec->getPrevious());
        $this->assertStringNotContainsString('storage', json_encode($echec->toPayload(), JSON_UNESCAPED_UNICODE));
    }

    public function testLesEchecsSuiventLaLangue(): void
    {
        I18n::use('en');

        $this->assertSame('This file is empty: there is nothing to download.', FileBuildException::emptySource()->getMessage());
        $this->assertSame('This content cannot be converted to Word.', FileBuildException::incompatible('Word')->getMessage());
        $this->assertStringContainsString('2 MB', FileBuildException::tooLarge(2_000_000)->getMessage());
    }
}
