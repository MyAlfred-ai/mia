<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * RAG des assistants — logique pure (sans base ni appel API) :
 *   - découpage en chunks (tailles, paragraphe géant, overlap),
 *   - budget de tokens (bornes, plafond 50 %, petite fenêtre non nulle),
 *   - matrice fenêtre de contexte par provider/modèle (axe « API »),
 *   - extraction de texte par type de fichier et par taille.
 */
final class RagDocumentTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ragtest_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    // ---------- Chunking ----------

    public function testEmptyAndBlankYieldNoChunk(): void
    {
        $this->assertSame([], ProfileDocument::splitIntoChunks(''));
        $this->assertSame([], ProfileDocument::splitIntoChunks("   \n\n  "));
    }

    public function testShortTextIsSingleChunkAndKeepsAccents(): void
    {
        $c = ProfileDocument::splitIntoChunks('Première phrase sur la sécurité. Deuxième.');
        $this->assertCount(1, $c);
        $this->assertStringContainsString('sécurité', $c[0]);
    }

    public function testGiantParagraphIsHardSplitUnderCeiling(): void
    {
        $ceil = (ProfileDocument::CHUNK_MAX_TOKENS + ProfileDocument::CHUNK_OVERLAP_TOKENS + 50) * 4;
        $c = ProfileDocument::splitIntoChunks(str_repeat('A', 12000));
        $this->assertGreaterThanOrEqual(3, count($c), 'paragraphe géant → plusieurs chunks');
        foreach ($c as $i => $piece) {
            $this->assertLessThanOrEqual($ceil, mb_strlen($piece), "chunk #$i sous le plafond");
        }
    }

    public function testOverlapIsCarriedToNextChunk(): void
    {
        $paras = '';
        for ($i = 0; $i < 12; $i++) {
            $paras .= "Bloc $i : " . str_repeat("mot$i ", 80) . "\n\n";
        }
        $c = ProfileDocument::splitIntoChunks($paras);
        $this->assertGreaterThanOrEqual(2, count($c));
        $tail = mb_substr($c[0], -40);
        $this->assertStringContainsString(mb_substr($tail, 10), $c[1], 'overlap reporté');
    }

    #[DataProvider('sizeProvider')]
    public function testChunkingAcrossSizes(int $bytes): void
    {
        $txt = RagFixtures::inflate('Phrase de test sur le sujet ABC123. ', $bytes);
        $c   = ProfileDocument::splitIntoChunks($txt);
        $this->assertNotEmpty($c);
        foreach ($c as $piece) {
            $this->assertNotSame('', trim($piece), 'aucun chunk vide');
        }
    }

    public static function sizeProvider(): array
    {
        return ['1KB' => [1000], '100KB' => [100000], '1MB' => [1000000]];
    }

    // ---------- Budget ----------

    public function testBudgetZeroOrNegative(): void
    {
        $this->assertSame(0, ProfileDocument::ragTokenBudget(0));
        $this->assertSame(0, ProfileDocument::ragTokenBudget(-5));
    }

    public function testSmallWindowStillGetsNonZeroBudget(): void
    {
        $this->assertGreaterThan(0, ProfileDocument::ragTokenBudget(8192));
    }

    #[DataProvider('windowProvider')]
    public function testBudgetIsCappedAtHalfWindow(int $cw): void
    {
        $b = ProfileDocument::ragTokenBudget($cw);
        $this->assertGreaterThanOrEqual(0, $b);
        $this->assertLessThanOrEqual((int) round($cw * 0.5), $b, 'budget ≤ 50% fenêtre');
    }

    public static function windowProvider(): array
    {
        return [[8192], [16000], [32000], [128000], [200000], [1000000]];
    }

    public function testBudgetIsMonotonic(): void
    {
        $prev = -1;
        foreach ([8192, 32000, 128000, 1000000] as $cw) {
            $b = ProfileDocument::ragTokenBudget($cw);
            $this->assertGreaterThanOrEqual($prev, $b);
            $prev = $b;
        }
    }

    // ---------- Matrice fenêtre de contexte (axe API) ----------

    #[DataProvider('contextWindowProvider')]
    public function testContextWindowMatrix(string $provider, string $model, int $expected): void
    {
        $this->assertSame($expected, LlmCatalog::contextWindow($provider, $model));
        // Toute combinaison produit un budget RAG exploitable.
        $this->assertGreaterThan(0, ProfileDocument::ragTokenBudget(LlmCatalog::contextWindow($provider, $model)));
    }

    public static function contextWindowProvider(): array
    {
        return [
            'openai gpt-6 astra'=> ['openai', 'gpt-6-astra', 1050000],
            'openai flagship'   => ['openai', 'gpt-5.6', 1000000],
            'openai 5.6 sol'    => ['openai', 'gpt-5.6-sol', 1000000],
            'openai 5.6 terra'  => ['openai', 'gpt-5.6-terra', 1000000],
            'openai 5.6 luna'   => ['openai', 'gpt-5.6-luna', 1000000],
            'openai 1M'         => ['openai', 'gpt-4.1', 1000000],
            'openai mini défaut'=> ['openai', 'gpt-4o-mini', 128000],
            'openai inconnu'    => ['openai', 'modele-inconnu', 128000],
            'anthropic opus 1M' => ['anthropic', 'claude-opus-4-8', 1000000],
            'anthropic fable 5.1' => ['anthropic', 'claude-fable-5-1', 1000000],
            'anthropic haiku'   => ['anthropic', 'claude-haiku-4-5-20251001', 200000],
            'mistral large 3'   => ['mistral', 'mistral-large-latest', 256000],
            'mistral medium 3.5'=> ['mistral', 'mistral-medium-2604', 256000],
            'mistral small 4'   => ['mistral', 'mistral-small-2603', 256000],
            // Codestral ne déclare pas de fenêtre : repli sur le provider.
            'mistral repli'     => ['mistral', 'codestral-latest', 128000],
            'mistral inconnu'   => ['mistral', 'mistral-inconnu', 128000],
            'gemini défaut'     => ['gemini', 'gemini-3-flash-preview', 1000000],
            'gemini 3.8 flash'  => ['gemini', 'gemini-3.8-flash', 1048576],
            'deepseek défaut'   => ['deepseek', 'deepseek-flash', 1000000],
            'deepseek inconnu'  => ['deepseek', 'deepseek-inconnu', 1000000],
            'moonshot kimi k3'  => ['moonshot', 'kimi-k3', 1048576],
            'moonshot kimi k2.6'=> ['moonshot', 'kimi-k2.6', 262144],
            'moonshot inconnu'  => ['moonshot', 'kimi-inconnu', 128000],
            'zai glm 5.2'       => ['zai', 'glm-5.2', 1000000],
            'zai glm 5.3 flash' => ['zai', 'glm-5.3-flash', 1000000],
            'zai inconnu'       => ['zai', 'glm-inconnu', 128000],
            'local plancher'    => ['local', 'local', 8192],
            'inconnu plancher'  => ['inconnu', 'x', 8192],
        ];
    }

    // ---------- Extraction par type × taille ----------

    private const KEY = 'MARQUEURUNIQUE7788';

    #[DataProvider('txtSizeProvider')]
    public function testExtractTxtSizes(string $label, int $bytes): void
    {
        $p = "$this->tmp/sample_$label.txt";
        RagFixtures::txt($p, self::KEY . ' ' . RagFixtures::inflate('Contenu brut répété. ', $bytes) . ' ' . self::KEY);
        $this->assertSame('text/plain', FileExtractor::detectMime($p, "s_$label.txt"));
        $txt = FileExtractor::extract($p, 'text/plain');
        $this->assertIsString($txt);
        $this->assertStringContainsString(self::KEY, $txt);
    }

    public static function txtSizeProvider(): array
    {
        return [['tiny', 50], ['medium', 50000], ['large', 2000000]];
    }

    public function testExtractMarkdown(): void
    {
        $p = "$this->tmp/n.md";
        RagFixtures::txt($p, "# Titre\n\nParagraphe avec " . self::KEY . " et du **gras**.\n");
        $this->assertSame('text/markdown', FileExtractor::detectMime($p, 'n.md'));
        $this->assertStringContainsString(self::KEY, (string) FileExtractor::extract($p, 'text/markdown'));
    }

    public function testExtractDocxSmallAndLarge(): void
    {
        $p = "$this->tmp/s.docx";
        RagFixtures::docx($p, ['Intro ' . self::KEY . '.', 'Deuxième paragraphe.', 'Conclusion.']);
        $mime = FileExtractor::detectMime($p, 's.docx');
        $this->assertStringContainsString('wordprocessingml', $mime);
        $docx = (string) FileExtractor::extract($p, $mime);
        $this->assertStringContainsString(self::KEY, $docx);
        $this->assertStringContainsString('Conclusion', $docx);

        $big = [];
        for ($i = 0; $i < 2000; $i++) {
            $big[] = "Paragraphe $i détaillant un point réglementaire chiffré.";
        }
        $big[] = 'Fin ' . self::KEY . '.';
        $p2 = "$this->tmp/big.docx";
        RagFixtures::docx($p2, $big);
        $this->assertStringContainsString(
            self::KEY,
            (string) FileExtractor::extract($p2, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        );
    }

    public function testExtractXlsx(): void
    {
        $p = "$this->tmp/d.xlsx";
        RagFixtures::xlsx($p, [['Nom', 'Ville', 'Code'], ['Alice', 'Paris', self::KEY], ['Bob', 'Lyon', 'ZZZ']]);
        $mime = FileExtractor::detectMime($p, 'd.xlsx');
        $this->assertStringContainsString('spreadsheetml', $mime);
        $xlsx = (string) FileExtractor::extract($p, $mime);
        $this->assertStringContainsString(self::KEY, $xlsx);
        $this->assertStringContainsString('Alice', $xlsx);
        $this->assertStringContainsString('Lyon', $xlsx);
    }

    public function testExtractPdfViaFallback(): void
    {
        $p = "$this->tmp/doc.pdf";
        RagFixtures::pdf($p, ['Rapport ' . self::KEY, 'Ligne deux.', 'Ligne trois.']);
        $this->assertSame('application/pdf', FileExtractor::detectMime($p, 'doc.pdf'));
        $this->assertStringContainsString(self::KEY, (string) FileExtractor::extract($p, 'application/pdf'));
    }

    public function testExtractHtml(): void
    {
        $p = "$this->tmp/page.html";
        RagFixtures::html($p, '<h1>Titre ' . self::KEY . '</h1><p>Paragraphe <b>gras</b>.</p><script>var x=1;</script>');
        $this->assertSame('text/html', FileExtractor::detectMime($p, 'page.html'));
        $html = (string) FileExtractor::extract($p, 'text/html');
        $this->assertStringContainsString(self::KEY, $html);
        $this->assertStringNotContainsString('<h1>', $html, 'balises retirées');
        $this->assertStringNotContainsString('var x=1', $html, 'script retiré');
    }

    public function testExtractRtf(): void
    {
        $p = "$this->tmp/note.rtf";
        RagFixtures::rtf($p, "Ligne une " . self::KEY . "\nLigne deux.");
        $this->assertSame('application/rtf', FileExtractor::detectMime($p, 'note.rtf'));
        $rtf = (string) FileExtractor::extract($p, 'application/rtf');
        $this->assertStringContainsString(self::KEY, $rtf);
        $this->assertStringNotContainsString('\\rtf1', $rtf, 'en-tête RTF retiré');
    }

    public function testExtractPptx(): void
    {
        $p = "$this->tmp/deck.pptx";
        RagFixtures::pptx($p, ['Diapo une ' . self::KEY, 'Diapo deux contenu', 'Diapo trois fin']);
        $mime = FileExtractor::detectMime($p, 'deck.pptx');
        $this->assertStringContainsString('presentationml', $mime);
        $pptx = (string) FileExtractor::extract($p, $mime);
        $this->assertStringContainsString(self::KEY, $pptx);
        $this->assertStringContainsString('Diapo trois', $pptx, 'toutes les diapos extraites');
    }

    public function testExtractOpenDocumentTextAndSpreadsheet(): void
    {
        $odt = "$this->tmp/doc.odt";
        RagFixtures::odf($odt, 'application/vnd.oasis.opendocument.text', ['Intro ' . self::KEY, 'Suite.']);
        $mimeOdt = FileExtractor::detectMime($odt, 'doc.odt');
        $this->assertStringContainsString('opendocument', $mimeOdt);
        $this->assertStringContainsString(self::KEY, (string) FileExtractor::extract($odt, $mimeOdt));

        $ods = "$this->tmp/data.ods";
        RagFixtures::odf($ods, 'application/vnd.oasis.opendocument.spreadsheet', ['Cellule ' . self::KEY]);
        $mimeOds = FileExtractor::detectMime($ods, 'data.ods');
        $this->assertStringContainsString('opendocument', $mimeOds);
        $this->assertStringContainsString(self::KEY, (string) FileExtractor::extract($ods, $mimeOds));
    }

    public function testExtractEpub(): void
    {
        $p = "$this->tmp/book.epub";
        RagFixtures::epub($p, ['<h1>Chapitre 1</h1><p>Début ' . self::KEY . '.</p>', '<p>Chapitre 2 fin.</p>']);
        $this->assertSame('application/epub+zip', FileExtractor::detectMime($p, 'book.epub'));
        $epub = (string) FileExtractor::extract($p, 'application/epub+zip');
        $this->assertStringContainsString(self::KEY, $epub);
        $this->assertStringContainsString('Chapitre 2', $epub);
    }

    public function testCsvAndJsonExtractAsText(): void
    {
        $csv = "$this->tmp/data.csv";
        RagFixtures::txt($csv, "nom,ville,code\nAlice,Paris," . self::KEY . "\nBob,Lyon,ZZZ");
        $this->assertSame('text/plain', FileExtractor::detectMime($csv, 'data.csv'));
        $this->assertStringContainsString(self::KEY, (string) FileExtractor::extract($csv, 'text/plain'));

        $json = "$this->tmp/config.json";
        RagFixtures::txt($json, '{"clef":"' . self::KEY . '","n":42}');
        $this->assertSame('text/plain', FileExtractor::detectMime($json, 'config.json'));
        $this->assertStringContainsString(self::KEY, (string) FileExtractor::extract($json, 'text/plain'));
    }

    #[DataProvider('forcedMimeProvider')]
    public function testDetectMimeForcedByExtension(string $filename, string $expectedMime): void
    {
        $p = "$this->tmp/$filename";
        file_put_contents($p, 'contenu quelconque');
        $this->assertSame($expectedMime, FileExtractor::detectMime($p, $filename));
    }

    public static function forcedMimeProvider(): array
    {
        return [
            'csv'  => ['data.csv', 'text/plain'],
            'json' => ['c.json', 'text/plain'],
            'xml'  => ['f.xml', 'text/plain'],
            'yaml' => ['conf.yaml', 'text/plain'],
            'py'   => ['script.py', 'text/plain'],
            'sql'  => ['dump.sql', 'text/plain'],
            'html' => ['p.html', 'text/html'],
            'rtf'  => ['n.rtf', 'application/rtf'],
            'pptx' => ['d.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'odt'  => ['d.odt', 'application/vnd.oasis.opendocument.text'],
            'epub' => ['b.epub', 'application/epub+zip'],
        ];
    }

    public function testImageYieldsNoText(): void
    {
        $p = "$this->tmp/i.png";
        file_put_contents($p, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'
        ));
        $this->assertNull(FileExtractor::extract($p, 'image/png'));
    }

    public function testExtractThenChunkKeepsMarker(): void
    {
        $big = [];
        for ($i = 0; $i < 2000; $i++) {
            $big[] = "Paragraphe $i détaillant un point réglementaire chiffré.";
        }
        $big[] = 'Fin ' . self::KEY . '.';
        $p = "$this->tmp/big.docx";
        RagFixtures::docx($p, $big);
        $txt    = (string) FileExtractor::extract($p, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $chunks = ProfileDocument::splitIntoChunks($txt);
        $this->assertGreaterThan(1, count($chunks));
        $found = array_reduce($chunks, static fn($ok, $c) => $ok || str_contains($c, self::KEY), false);
        $this->assertTrue($found, 'le marqueur survit au pipeline extraction→chunking');
    }
}
