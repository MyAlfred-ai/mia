<?php

declare(strict_types=1);

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style as PhpWordStyle;
use PhpOffice\PhpWord\Style\Language;

/**
 * Document Word (.docx) depuis la structure d'un document Markdown.
 *
 * Le document est CONSTRUIT objet par objet (titres, paragraphes, listes,
 * tableaux) et non importé depuis du HTML, comme le fait l'export d'une
 * réponse (DocxExportService). La différence se voit à l'ouverture : de vrais
 * styles « Titre 1 / Titre 2 » (volet de navigation, table des matières), de
 * vraies listes numérotées qui se poursuivent quand on appuie sur Entrée, des
 * tableaux dont l'en-tête se répète d'une page à l'autre. C'est ce qui sépare
 * un document qu'on retravaille d'une capture d'écran de conversation.
 *
 * Un contenu écrit en HTML par le modèle (bloc ```html) n'a pas de structure
 * Markdown à lire : lui seul passe par le service d'export existant, qui sait
 * importer du HTML.
 */
final class DocxFileBuilder implements FileBuilder
{
    /** Largeur utile d'une page A4 avec 2 cm de marges (twips). */
    private const PAGE_WIDTH = 9638;

    private const COLOR_HEADING = '1F3864';
    private const COLOR_LINK    = '0563C1';
    private const COLOR_SHADE   = 'F2F2F2';
    private const COLOR_MUTED   = '595959';
    private const COLOR_BORDER  = 'BFBFBF';

    /** Tailles des titres (points), niveau 1 → 6. */
    private const HEADING_SIZES = [1 => 18, 2 => 15, 3 => 13, 4 => 12, 5 => 11, 6 => 11];

    private PhpWord $word;

    /** Nombre de listes numérotées déjà déclarées (chacune a sa numérotation). */
    private int $numbered = 0;

    public function build(FileSource $source): string
    {
        if (!FileLibrary::has(PhpWord::class)) {
            throw FileBuildException::unavailable('Word', ['rtf', 'pdf']);
        }

        if ($source->syntax === FileFormats::SYNTAX_HTML && class_exists('DocxExportService')) {
            return CompanyDocx::dress(
                (new DocxExportService())->buildFromHtml($source->content, true),
                $source->title,
                $source->author
            );
        }

        $blocks = $source->documentBlocks();
        if ($blocks === []) {
            throw FileBuildException::emptySource();
        }

        // Les styles de PhpWord sont un registre STATIQUE, commun à tous les
        // documents du processus : sans remise à zéro, les numérotations d'un
        // document précédent (worker, tests) viendraient s'ajouter à celui-ci.
        PhpWordStyle::resetStyles();
        // Échappement XML obligatoire : « & » ou « < » dans le texte rendraient
        // sinon le fichier illisible pour Word (« contenu illisible »).
        PhpWordSettings::setOutputEscapingEnabled(true);

        $this->word     = new PhpWord();
        $this->numbered = 0;
        $this->declareStyles($source->locale);

        $info = $this->word->getDocInfo();
        $info->setTitle($source->documentTitle($blocks));
        $info->setCreator($source->author);
        $info->setLastModifiedBy($source->author);

        $section = $this->word->addSection([
            'pageSizeW'    => 11906,
            'pageSizeH'    => 16838,
            'marginTop'    => 1134,
            'marginBottom' => 1134,
            'marginLeft'   => 1134,
            'marginRight'  => 1134,
        ]);

        // Logo de l'entreprise en en-tête, sa mention et le numéro de page en
        // pied ; un modèle Word déposé remplace tout cela par les siens.
        CompanyDocx::addChrome($section);

        $this->blocks($section, $blocks, 0, false);

        return CompanyDocx::dress(self::save($this->word), $source->documentTitle($blocks), $source->author);
    }

    private function declareStyles(string $locale): void
    {
        $this->word->setDefaultFontName('Calibri');
        $this->word->setDefaultFontSize(11);
        $this->word->setDefaultParagraphStyle(['spaceAfter' => 120, 'lineHeight' => 1.15]);
        $this->word->getSettings()->setThemeFontLang(new Language($locale === 'en' ? Language::EN_US : Language::FR_FR));

        foreach (self::HEADING_SIZES as $level => $size) {
            $this->word->addTitleStyle(
                $level,
                ['bold' => true, 'size' => $size, 'color' => self::COLOR_HEADING],
                ['spaceBefore' => $level === 1 ? 360 : 240, 'spaceAfter' => 120, 'keepNext' => true]
            );
        }

        $this->word->addNumberingStyle('miaBullets', [
            'type'   => 'hybridMultilevel',
            'levels' => self::bulletLevels(),
        ]);
    }

    /**
     * Style d'un tableau, posé EN LIGNE sur chaque tableau et non déclaré
     * comme style nommé : l'aperçu de macOS et d'iOS (Coup d'œil, pièce jointe
     * d'un mail) ne résout pas les styles de tableau, et affichait des
     * cellules sans bordure. En ligne, les bordures voyagent avec le tableau.
     *
     * @return array<string, mixed>
     */
    private static function tableStyle(string $borderColor, int $margin): array
    {
        return [
            'borderSize'  => 6,
            'borderColor' => $borderColor,
            'cellMargin'  => $margin,
            'unit'        => TblWidth::PERCENT,
            'width'       => 100 * 50,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function bulletLevels(): array
    {
        $marks  = ["\u{2022}", "\u{25E6}", "\u{25AA}"];
        $levels = [];
        for ($i = 0; $i < 9; $i++) {
            $left     = 720 + 360 * $i;
            $levels[] = [
                'format' => 'bullet', 'text' => $marks[$i % 3],
                'left' => $left, 'hanging' => 360, 'tabPos' => $left,
            ];
        }

        return $levels;
    }

    /**
     * Déclare une numérotation PROPRE à une liste : sans cela deux listes
     * numérotées du même document se suivraient (1, 2, 3 puis 4, 5…).
     */
    private function numberingStyle(int $start): string
    {
        $this->numbered++;
        $name   = 'miaNumbers' . $this->numbered;
        $levels = [];
        for ($i = 0; $i < 9; $i++) {
            $left     = 720 + 360 * $i;
            $levels[] = [
                'format' => 'decimal', 'text' => '%' . ($i + 1) . '.',
                'start' => $i === 0 ? max(1, $start) : 1,
                'left' => $left, 'hanging' => 360, 'tabPos' => $left,
            ];
        }
        $this->word->addNumberingStyle($name, ['type' => 'multilevel', 'levels' => $levels]);

        return $name;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param int  $indent Retrait hérité (twips) — citation.
     * @param bool $quoted Le bloc est-il dans une citation ?
     */
    private function blocks(AbstractContainer $container, array $blocks, int $indent, bool $quoted): void
    {
        foreach ($blocks as $block) {
            switch ($block['type'] ?? '') {
                case 'heading':
                    $level = max(1, min(6, (int)($block['level'] ?? 1)));
                    // Texte simple : la mise en forme de ligne d'un titre (gras
                    // partiel) n'apporte rien sous un style déjà gras, et un
                    // titre en texte simple alimente la table des matières.
                    $container->addTitle(
                        str_replace("\n", ' ', MarkdownDocument::plainText($block['inlines'] ?? [])),
                        $level
                    );
                    break;

                case 'paragraph':
                    $this->runs(
                        $container->addTextRun(self::paragraphStyle($indent, $quoted)),
                        $block['inlines'] ?? [],
                        $quoted
                    );
                    break;

                case 'list':
                    $style = !empty($block['ordered'])
                        ? $this->numberingStyle((int)($block['start'] ?? 1))
                        : 'miaBullets';
                    $this->listItems($container, $block, $style, 0, $quoted);
                    break;

                case 'table':
                    $this->table($container, $block);
                    break;

                case 'code':
                    $this->code($container, (string)($block['text'] ?? ''));
                    break;

                case 'quote':
                    $this->blocks($container, $block['blocks'] ?? [], $indent + 567, true);
                    break;

                case 'rule':
                    $container->addText('', [], [
                        'borderBottomSize'  => 6,
                        'borderBottomColor' => self::COLOR_BORDER,
                        'spaceAfter'        => 160,
                    ]);
                    break;
            }
        }
    }

    /** @return array<string, mixed> */
    private static function paragraphStyle(int $indent, bool $quoted): array
    {
        $style = [];
        if ($indent > 0) {
            $style['indentation'] = ['left' => $indent];
        }
        if ($quoted) {
            $style['borderLeftSize']  = 18;
            $style['borderLeftColor'] = self::COLOR_BORDER;
        }

        return $style;
    }

    /**
     * Éléments d'une liste, sous-listes comprises. Une sous-liste d'un AUTRE
     * type que sa parente (puces sous une numérotation) reçoit sa propre
     * définition : dans le format de Word, le type est porté par le niveau de
     * la définition, pas par l'élément.
     */
    private function listItems(AbstractContainer $container, array $block, string $style, int $level, bool $quoted): void
    {
        $level = min($level, 8);
        foreach (($block['items'] ?? []) as $item) {
            $run     = $container->addListItemRun($level, $style);
            $checked = $item['checked'] ?? null;
            if ($checked !== null) {
                $run->addText($checked ? "\u{2611} " : "\u{2610} ");
            }
            $this->runs($run, $item['inlines'] ?? [], $quoted);

            foreach (($item['children'] ?? []) as $child) {
                if (($child['type'] ?? '') !== 'list') {
                    continue;
                }
                $sameKind   = !empty($child['ordered']) === !empty($block['ordered']);
                $childStyle = $sameKind
                    ? $style
                    : (!empty($child['ordered']) ? $this->numberingStyle((int)($child['start'] ?? 1)) : 'miaBullets');
                $this->listItems($container, $child, $childStyle, $level + 1, $quoted);
            }
        }
    }

    private function table(AbstractContainer $container, array $block): void
    {
        $header = $block['header'] ?? [];
        $rows   = $block['rows'] ?? [];
        $align  = $block['align'] ?? [];
        if ($header === []) {
            return;
        }

        $widths = self::columnWidths($header, $rows);
        $table  = $container->addTable(self::tableStyle(self::COLOR_BORDER, 90));

        foreach (array_merge([$header], $rows) as $rowIndex => $cells) {
            $isHeader = $rowIndex === 0;
            // `tblHeader` : l'en-tête se répète en haut de chaque page.
            $table->addRow(null, $isHeader ? ['tblHeader' => true, 'cantSplit' => true] : ['cantSplit' => true]);

            foreach ($widths as $c => $width) {
                $cell = $table->addCell(
                    $width,
                    $isHeader ? ['valign' => 'top', 'bgColor' => self::COLOR_SHADE] : ['valign' => 'top']
                );
                $run  = $cell->addTextRun([
                    'alignment'  => match ($align[$c] ?? 'left') {
                        'center' => 'center',
                        'right'  => 'right',
                        default  => 'left',
                    },
                    'spaceAfter' => 0,
                ]);
                $this->runs($run, $cells[$c] ?? [], false, $isHeader);
            }
        }

        // Paragraphe vide : sans lui, le texte qui suit colle au tableau.
        $container->addText('', ['size' => 4], ['spaceAfter' => 80]);
    }

    /**
     * Largeurs des colonnes (twips), proportionnelles au plus long contenu de
     * chacune — borné, pour qu'une cellule bavarde n'écrase pas ses voisines.
     *
     * @return list<int>
     */
    private static function columnWidths(array $header, array $rows): array
    {
        $weights = [];
        foreach (array_merge([$header], $rows) as $row) {
            foreach ($row as $c => $runs) {
                $len         = mb_strlen(MarkdownDocument::plainText($runs), 'UTF-8');
                $weights[$c] = max($weights[$c] ?? 0, min(40, max(4, $len)));
            }
        }
        $weights = array_slice(array_pad($weights, count($header), 4), 0, count($header));
        $sum     = array_sum($weights) ?: 1;

        return array_map(
            static fn (int $weight): int => (int)round(self::PAGE_WIDTH * $weight / $sum),
            $weights
        );
    }

    /**
     * Bloc de code : tableau d'une seule cellule, fond gris, police à chasse
     * fixe. Une cellule plutôt qu'un paragraphe ombré par ligne : le bloc reste
     * d'un seul tenant quand on le déplace ou qu'on le copie dans Word.
     */
    private function code(AbstractContainer $container, string $text): void
    {
        $table = $container->addTable(self::tableStyle('D9D9D9', 110));
        $table->addRow();
        $cell = $table->addCell(self::PAGE_WIDTH, ['bgColor' => self::COLOR_SHADE]);
        $run  = $cell->addTextRun(['spaceAfter' => 0, 'lineHeight' => 1.0]);

        foreach (explode("\n", $text) as $i => $line) {
            if ($i > 0) {
                $run->addTextBreak();
            }
            if ($line !== '') {
                $run->addText($line, ['name' => 'Courier New', 'size' => 9]);
            }
        }

        $container->addText('', ['size' => 4], ['spaceAfter' => 80]);
    }

    /**
     * Runs → éléments d'un paragraphe Word.
     *
     * @param \PhpOffice\PhpWord\Element\TextRun|\PhpOffice\PhpWord\Element\ListItemRun $target
     */
    private function runs(AbstractContainer $target, array $runs, bool $quoted, bool $bold = false): void
    {
        foreach ($runs as $run) {
            if (!empty($run['break'])) {
                $target->addTextBreak();
                continue;
            }
            $text = (string)($run['text'] ?? '');
            if ($text === '') {
                continue;
            }

            $font = [];
            if ($bold || !empty($run['bold'])) {
                $font['bold'] = true;
            }
            if ($quoted || !empty($run['italic'])) {
                $font['italic'] = true;
            }
            if ($quoted) {
                $font['color'] = self::COLOR_MUTED;
            }
            if (!empty($run['strike'])) {
                $font['strikethrough'] = true;
            }
            if (!empty($run['code'])) {
                $font['name']    = 'Courier New';
                $font['size']    = 10;
                $font['bgColor'] = self::COLOR_SHADE;
            }

            $link = $run['link'] ?? null;
            if (is_string($link) && $link !== '') {
                $target->addLink($link, $text, ['color' => self::COLOR_LINK, 'underline' => 'single'] + $font);
                continue;
            }

            $target->addText($text, $font);
        }
    }

    /**
     * Deux retouches du fichier écrit par PhpWord, sans lesquelles l'aperçu de
     * macOS et d'iOS (Coup d'œil, pièce jointe d'un mail, application
     * Fichiers) affiche un document amputé — alors que Word et LibreOffice,
     * plus tolérants, le lisent bien :
     *
     *  1. NUMÉROTATIONS. PhpWord numérote ses définitions de liste d'après le
     *     rang du style dans son registre (8, 10…). L'aperçu d'Apple les
     *     cherche par POSITION : hors de la plage 0…n-1, la liste est ignorée
     *     et ses éléments s'affichent sans puce ni numéro. On renumérote donc
     *     de façon compacte (définitions à partir de 0, instances à partir
     *     de 1), dans `numbering.xml` et dans le document.
     *  2. SAUTS DE LIGNE. PhpWord écrit `<w:br/>` directement dans le
     *     paragraphe, hors de tout « run » — contraire au schéma, et ignoré
     *     par l'aperçu d'Apple : deux lignes d'une citation ou d'un bloc de
     *     code se retrouvaient collées. On l'enveloppe dans son run.
     *
     * Best-effort : en cas d'échec le fichier de PhpWord reste tel quel, il
     * est valide pour Word.
     */
    public static function normalizeForReaders(string $path): void
    {
        if (!class_exists(ZipArchive::class)) {
            return;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return;
        }

        try {
            $document  = $zip->getFromName('word/document.xml');
            $numbering = $zip->getFromName('word/numbering.xml');
            if (!is_string($document)) {
                return;
            }

            if (is_string($numbering)
                && preg_match_all('/<w:abstractNum w:abstractNumId="(\d+)"/', $numbering, $m) > 0) {
                $map = array_flip(array_values(array_unique($m[1])));   // ancien id → rang (0…)

                $numbering = (string)preg_replace_callback(
                    '/<w:abstractNum w:abstractNumId="(\d+)"/',
                    static fn (array $x): string => '<w:abstractNum w:abstractNumId="' . ($map[$x[1]] ?? $x[1]) . '"',
                    $numbering
                );
                $numbering = (string)preg_replace_callback(
                    '/<w:num w:numId="(\d+)"><w:abstractNumId w:val="(\d+)"\/>/',
                    static fn (array $x): string => isset($map[$x[1]], $map[$x[2]])
                        ? '<w:num w:numId="' . ($map[$x[1]] + 1) . '"><w:abstractNumId w:val="' . $map[$x[2]] . '"/>'
                        : $x[0],
                    $numbering
                );
                $document = (string)preg_replace_callback(
                    '/<w:numId w:val="(\d+)"\/>/',
                    static fn (array $x): string => isset($map[$x[1]])
                        ? '<w:numId w:val="' . ($map[$x[1]] + 1) . '"/>'
                        : $x[0],
                    $document
                );
                $zip->addFromString('word/numbering.xml', $numbering);
            }

            // Le saut de PAGE porte un attribut (`<w:br w:type="page"/>`) et
            // vit déjà dans un run : seul le saut de ligne nu est concerné.
            $document = str_replace('<w:br/>', '<w:r><w:br/></w:r>', $document);
            $zip->addFromString('word/document.xml', $document);
        } finally {
            $zip->close();
        }
    }

    private static function save(PhpWord $word): string
    {
        $path = FileScratch::path('document', 'docx');

        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);
            self::normalizeForReaders($path);
            $bytes = (string)file_get_contents($path);
        } finally {
            FileScratch::discard($path);
        }

        if ($bytes === '') {
            throw new RuntimeException('Document vide après écriture.');
        }

        return $bytes;
    }
}
