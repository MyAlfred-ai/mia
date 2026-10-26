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
     * Corps HTML du document.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function render(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $out .= match ($block['type'] ?? '') {
                'heading'   => self::heading($block),
                'paragraph' => '<p>' . self::runs($block['inlines'] ?? []) . "</p>\n",
                'list'      => self::listBlock($block),
                'table'     => self::table($block),
                'code'      => '<pre class="code">' . self::esc((string)($block['text'] ?? '')) . "</pre>\n",
                // `div` et non `blockquote` : la bordure gauche est mieux supportée.
                'quote'     => '<div class="quote">' . self::render($block['blocks'] ?? []) . "</div>\n",
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
            . self::render($blocks)
            . '</body></html>';
    }

    private static function printCss(): string
    {
        return 'body{font-family:dejavusans,sans-serif;font-size:10.5pt;line-height:1.45;color:#1a1a1a;}'
            . 'h1{font-size:19pt;color:#1f3864;margin:0 0 8pt 0;}'
            . 'h2{font-size:15pt;color:#1f3864;margin:16pt 0 6pt 0;}'
            . 'h3{font-size:12.5pt;color:#1f3864;margin:12pt 0 5pt 0;}'
            . 'h4,h5,h6{font-size:11pt;color:#1f3864;margin:10pt 0 4pt 0;}'
            . 'p{margin:0 0 7pt 0;}'
            . 'ul,ol{margin:0 0 7pt 0;padding-left:18pt;}'
            . 'li{margin:0 0 2.5pt 0;}'
            . 'table{width:100%;border-collapse:collapse;margin:4pt 0 10pt 0;}'
            . 'th,td{border:0.5pt solid #bfbfbf;padding:4pt 6pt;vertical-align:top;}'
            . 'th{background-color:#f2f2f2;font-weight:bold;text-align:left;}'
            . 'pre.code{font-family:dejavusansmono,monospace;font-size:8.5pt;background-color:#f2f2f2;'
            . 'border:0.5pt solid #d9d9d9;padding:6pt 8pt;margin:4pt 0 10pt 0;white-space:pre-wrap;}'
            . 'code{font-family:dejavusansmono,monospace;font-size:9pt;background-color:#f2f2f2;}'
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

    private static function table(array $block): string
    {
        $align = $block['align'] ?? [];
        $cell  = static function (string $tag, array $runs, int $c) use ($align): string {
            $side = $align[$c] ?? 'left';

            return '<' . $tag . ($side !== 'left' ? ' style="text-align:' . $side . '"' : '') . '>'
                . self::runs($runs) . '</' . $tag . '>';
        };

        $out = '<table><thead><tr>';
        foreach (($block['header'] ?? []) as $c => $runs) {
            $out .= $cell('th', $runs, (int)$c);
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
