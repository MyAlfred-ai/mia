<?php

declare(strict_types=1);

/**
 * Structure de document (MarkdownDocument) → HTML. PUR, sans bibliothèque.
 *
 * Sert au fabricant PDF (Dompdf met en page du HTML). Le HTML est SÛR PAR
 * CONSTRUCTION : tout texte passe par htmlspecialchars, et les seules balises
 * du document sont celles écrites ici. Aucune image, aucune feuille de style
 * distante : le moteur PDF ne va donc chercher aucune ressource, quoi qu'ait
 * écrit le modèle (une image distante dans un contenu qu'il a recopié d'une
 * page web ferait sinon sortir une requête du serveur).
 */
final class MarkdownHtml
{
    /**
     * Au-delà de ce nombre de colonnes, un tableau ne tient plus lisiblement
     * sur une page A4 en portrait : la page passe en paysage (cf.
     * wantsLandscape) et le tableau en corps réduit. Le même seuil est donné
     * au modèle (FileDelivery) : un document plus large se livre en Excel.
     */
    public const PORTRAIT_MAX_COLUMNS = 6;

    /**
     * Mesure des colonnes (columnWidths), en caractères. Ligne = ce que tient
     * une ligne de tableau en 9,5 pt, sur A4 portrait ou paysage, marges de
     * PdfExportService déduites.
     */
    public const LINE_PORTRAIT  = 88;
    public const LINE_LANDSCAPE = 125;
    /** Caractères de plus par ligne en corps réduit (9,5 pt → 8,5 pt). */
    private const SMALL_GAIN    = 9.5 / 8.5;
    private const SHORT_CELL    = 12;
    private const LONG_CELL     = 40;
    private const LONG_WORD     = 18;
    private const CELL_PADDING  = 3;

    /**
     * Corps HTML du document. `$print` = mise en page pour le PDF : largeur de
     * chaque colonne posée d'après son contenu (tableau en largeur fixe). À
     * l'écran (workflows, tâches programmées), le navigateur s'en charge.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function render(array $blocks, bool $print = false): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $out .= match ($block['type'] ?? '') {
                'heading'   => self::heading($block),
                'paragraph' => '<p>' . self::runs($block['inlines'] ?? []) . "</p>\n",
                'list'      => self::listBlock($block),
                'table'     => self::table($block, $print),
                'code'      => '<pre class="code">' . self::esc((string)($block['text'] ?? '')) . "</pre>\n",
                // `div` et non `blockquote` : la bordure gauche est mieux supportée.
                'quote'     => '<div class="quote">' . self::render($block['blocks'] ?? [], $print) . "</div>\n",
                'rule'      => "<hr>\n",
                default     => '',
            };
        }

        return $out;
    }

    /**
     * Document complet, avec la feuille de style d'impression. Dompdf ne connaît
     * ni flexbox ni grille : la mise en page reste volontairement élémentaire.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function document(array $blocks, string $title = ''): string
    {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . self::esc($title) . '</title>'
            . '<style>' . self::printCss() . '</style></head><body>'
            . self::render($blocks, true)
            . '</body></html>';
    }

    /**
     * Vrai si un tableau du document dépasse PORTRAIT_MAX_COLUMNS : le PDF
     * est alors mis en page en paysage (PdfFileBuilder). En portrait, ses
     * dernières colonnes sortaient de la page.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function wantsLandscape(array $blocks): bool
    {
        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'table' && self::columnCount($block) > self::PORTRAIT_MAX_COLUMNS) {
                return true;
            }
            if ($type === 'quote' && self::wantsLandscape($block['blocks'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Largeur de chaque colonne, en pourcentage, d'après son contenu. PUR.
     *
     * Dompdf calcule mal la largeur automatique d'un tableau : un mot long
     * (référence, adresse, IBAN) élargissait le tableau au-delà de la page.
     * En largeur fixe, il faut lui dire combien chaque colonne prend — sans
     * quoi elles sont toutes égales et une date courte se coupe en deux.
     *
     * Tout se mesure en caractères. Chaque colonne a un MINIMUM (son mot le
     * plus long, celui de son en-tête, ou sa cellule entière si elle est
     * courte : « 12 500 € » ne se coupe pas) et un SOUHAIT (son contenu le
     * plus long, borné : au-delà, la cellule passe sur plusieurs lignes).
     * Si les souhaits tiennent sur la ligne, ils sont pris tels quels ; sinon
     * chaque colonne reçoit son minimum, et la place qui reste va aux colonnes
     * longues, à proportion de ce qui leur manque. Les petites colonnes ne
     * sont donc jamais sacrifiées aux longues.
     *
     * @param list<string>       $header Texte des en-têtes.
     * @param list<list<string>> $rows   Texte des cellules.
     * @param int                $line   Caractères d'une ligne de la page.
     * @return list<float> Une largeur par colonne, en %, somme = 100.
     */
    public static function columnWidths(array $header, array $rows, int $line = self::LINE_PORTRAIT): array
    {
        [$min, $want] = self::measure($header, $rows);
        if ($min === []) {
            return [];
        }

        $widths = $want;
        if (array_sum($want) > $line) {
            $spare  = $line - array_sum($min);
            $gap    = array_sum($want) - array_sum($min);
            $widths = [];
            foreach ($min as $c => $floor) {
                $widths[] = $spare > 0 && $gap > 0
                    ? $floor + $spare * ($want[$c] - $floor) / $gap
                    : $floor;
            }
        }

        $sum = array_sum($widths);

        return array_map(static fn (int|float $w): float => round($w * 100 / $sum, 2), $widths);
    }

    /**
     * Ce qu'il faut à un tableau, en caractères, pour qu'aucun mot ne se
     * coupe : somme des minimums de columnWidths. Au-delà d'une ligne, le
     * tableau passe en corps réduit (cf. table).
     *
     * @param list<string>       $header
     * @param list<list<string>> $rows
     */
    public static function minimumLine(array $header, array $rows): int
    {
        return (int)array_sum(self::measure($header, $rows)[0]);
    }

    /**
     * @param list<string>       $header
     * @param list<list<string>> $rows
     * @return array{0: list<int>, 1: list<int>} Minimum et souhait de chaque colonne.
     */
    private static function measure(array $header, array $rows): array
    {
        $count = count($header);
        foreach ($rows as $row) {
            $count = max($count, count($row));
        }
        if ($count === 0) {
            return [[], []];
        }

        $longestWord = static function (string $text): int {
            $text = trim($text);
            if (mb_strlen($text) <= self::SHORT_CELL) {
                // Chiffres et barres sont plus larges qu'une lettre moyenne.
                return (int)ceil(mb_strlen($text) * 1.2);
            }
            $word = 0;
            foreach (preg_split('/\s+/u', $text) ?: [] as $piece) {
                $word = max($word, mb_strlen($piece));
            }

            // DejaVu Sans est large : un mot prend un peu plus que sa longueur.
            return (int)ceil($word * 1.1);
        };

        $min  = [];
        $want = [];
        for ($c = 0; $c < $count; $c++) {
            $longest = 0;
            $word    = 0;
            foreach ($rows as $row) {
                $text    = trim((string)($row[$c] ?? ''));
                $longest = max($longest, mb_strlen($text));
                $word    = max($word, $longestWord($text));
            }
            $floor  = self::CELL_PADDING + max(
                3,
                min(self::LONG_WORD, $word),
                // En-tête en gras : un caractère de plus.
                min(self::LONG_WORD, $longestWord((string)($header[$c] ?? ''))) + 1
            );
            $min[]  = $floor;
            $want[] = max($floor, self::CELL_PADDING + min(self::LONG_CELL, $longest));
        }

        return [$min, $want];
    }

    private static function columnCount(array $block): int
    {
        $count = count($block['header'] ?? []);
        foreach (($block['rows'] ?? []) as $row) {
            $count = max($count, count($row));
        }

        return $count;
    }

    /**
     * Polices : les NOMS de Dompdf (« DejaVu Sans »), pas ceux des fichiers.
     * `dejavusans` n'existe pas pour lui : il retombait sur Helvetica — police
     * non intégrée, sans « → », « ≥ » ni la plupart des symboles — pour tout
     * ce qui est en gras (titres, en-têtes de tableau) et en Courier pour le
     * code. Les marges de page, elles, sont celles de PdfExportService.
     */
    private static function printCss(): string
    {
        return 'body{font-family:"DejaVu Sans",sans-serif;font-size:10.5pt;line-height:1.45;color:#1a1a1a;}'
            . 'h1{font-size:19pt;color:#1f3864;margin:0 0 8pt 0;}'
            . 'h2{font-size:15pt;color:#1f3864;margin:16pt 0 6pt 0;}'
            . 'h3{font-size:12.5pt;color:#1f3864;margin:12pt 0 5pt 0;}'
            . 'h4,h5,h6{font-size:11pt;color:#1f3864;margin:10pt 0 4pt 0;}'
            // Un titre ne reste pas seul en bas de page.
            . 'h1,h2,h3,h4,h5,h6{page-break-after:avoid;}'
            . 'p{margin:0 0 7pt 0;}'
            // Une adresse ou un mot plus long que la ligne se coupe au lieu
            // de sortir de la page.
            . 'p,li,td,th{word-wrap:break-word;}'
            . 'ul,ol{margin:0 0 7pt 0;padding-left:18pt;}'
            . 'li{margin:0 0 2.5pt 0;}'
            . 'table{width:100%;border-collapse:collapse;margin:4pt 0 10pt 0;table-layout:fixed;font-size:9.5pt;}'
            . 'table.wide{font-size:8.5pt;}'
            . 'table.wide th,table.wide td{padding:3pt 4pt;}'
            . 'tr{page-break-inside:avoid;}'
            . 'th,td{border:0.5pt solid #bfbfbf;padding:4pt 6pt;vertical-align:top;}'
            . 'th{background-color:#f2f2f2;font-weight:bold;text-align:left;}'
            . 'pre.code{font-family:"DejaVu Sans Mono",monospace;font-size:8.5pt;background-color:#f2f2f2;'
            . 'border:0.5pt solid #d9d9d9;padding:6pt 8pt;margin:4pt 0 10pt 0;white-space:pre-wrap;word-wrap:break-word;}'
            . 'code{font-family:"DejaVu Sans Mono",monospace;font-size:9pt;background-color:#f2f2f2;}'
            . 'div.quote{margin:4pt 0 10pt 0;padding:2pt 0 2pt 10pt;border-left:2pt solid #bfbfbf;'
            . 'color:#595959;font-style:italic;}'
            . 'div.quote p{margin:0 0 3pt 0;}'
            . 'hr{border:0;border-top:0.5pt solid #bfbfbf;margin:10pt 0;}'
            . 'a{color:#0563c1;text-decoration:underline;}';
    }

    private static function heading(array $block): string
    {
        $level = max(1, min(6, (int)($block['level'] ?? 1)));

        return '<h' . $level . '>' . self::runs($block['inlines'] ?? []) . '</h' . $level . ">\n";
    }

    private static function listBlock(array $block): string
    {
        $ordered = !empty($block['ordered']);
        $start   = max(1, (int)($block['start'] ?? 1));
        $tag     = $ordered ? 'ol' : 'ul';
        $out     = '<' . $tag . ($ordered && $start !== 1 ? ' start="' . $start . '"' : '') . '>';

        foreach (($block['items'] ?? []) as $item) {
            $checked = $item['checked'] ?? null;
            $box     = $checked === null ? '' : ($checked ? "\u{2611} " : "\u{2610} ");
            $out    .= '<li>' . self::esc($box) . self::runs($item['inlines'] ?? []);
            foreach (($item['children'] ?? []) as $child) {
                if (($child['type'] ?? '') === 'list') {
                    $out .= self::listBlock($child);
                }
            }
            $out .= '</li>';
        }

        return $out . '</' . $tag . ">\n";
    }

    private static function table(array $block, bool $print = false): string
    {
        $align = $block['align'] ?? [];
        // Largeurs posées sur les cellules d'EN-TÊTE : en largeur fixe, c'est
        // la première rangée qui décide, et Dompdf ignore `<col>`.
        $class  = '';
        $widths = [];
        if ($print) {
            $heads = array_map(self::plain(...), $block['header'] ?? []);
            $cells = array_map(static fn (array $row): array => array_map(self::plain(...), $row), $block['rows'] ?? []);
            $line  = self::columnCount($block) > self::PORTRAIT_MAX_COLUMNS ? self::LINE_LANDSCAPE : self::LINE_PORTRAIT;
            // Tableau trop dense pour sa ligne (page en paysage, ou mots trop
            // longs pour le portrait) : corps réduit, qui loge plus de texte.
            if ($line === self::LINE_LANDSCAPE || self::minimumLine($heads, $cells) > $line) {
                $class = ' class="wide"';
                $line  = (int)round($line * self::SMALL_GAIN);
            }
            $widths = self::columnWidths($heads, $cells, $line);
        }
        $cell = static function (string $tag, array $runs, int $c) use ($align, $widths): string {
            $side  = $align[$c] ?? 'left';
            $style = [];
            if ($side !== 'left') {
                $style[] = 'text-align:' . $side;
            }
            if ($tag === 'th' && isset($widths[$c])) {
                $style[] = 'width:' . $widths[$c] . '%';
            }

            return '<' . $tag . ($style !== [] ? ' style="' . implode(';', $style) . '"' : '') . '>'
                . self::runs($runs) . '</' . $tag . '>';
        };

        $header = $block['header'] ?? [];
        $out    = '<table' . $class . '><thead><tr>';
        foreach (array_keys($widths !== [] ? $widths : $header) as $c) {
            $out .= $cell('th', $header[$c] ?? [], (int)$c);
        }
        $out .= '</tr></thead><tbody>';
        foreach (($block['rows'] ?? []) as $row) {
            $out .= '<tr>';
            foreach ($row as $c => $runs) {
                $out .= $cell('td', $runs, (int)$c);
            }
            $out .= '</tr>';
        }

        return $out . "</tbody></table>\n";
    }

    /** Texte d'une suite de fragments, sans mise en forme (mesure des colonnes). */
    private static function plain(array $runs): string
    {
        $text = '';
        foreach ($runs as $run) {
            $text .= !empty($run['break']) ? ' ' : (string)($run['text'] ?? '');
        }

        return $text;
    }

    private static function runs(array $runs): string
    {
        $out = '';
        foreach ($runs as $run) {
            if (!empty($run['break'])) {
                $out .= '<br>';
                continue;
            }
            $html = self::esc((string)($run['text'] ?? ''));
            if ($html === '') {
                continue;
            }
            if (!empty($run['code'])) {
                $html = '<code>' . $html . '</code>';
            }
            if (!empty($run['strike'])) {
                $html = '<s>' . $html . '</s>';
            }
            if (!empty($run['italic'])) {
                $html = '<em>' . $html . '</em>';
            }
            if (!empty($run['bold'])) {
                $html = '<strong>' . $html . '</strong>';
            }
            $link = $run['link'] ?? null;
            if (is_string($link) && $link !== '') {
                $html = '<a href="' . self::esc($link) . '">' . $html . '</a>';
            }
            $out .= $html;
        }

        return $out;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
