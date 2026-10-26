<?php

declare(strict_types=1);

/**
 * Structure de document (MarkdownDocument) → texte brut LISIBLE. PUR.
 *
 * C'est l'entrée « Texte (.txt) » du menu d'un document : le même courrier,
 * sans dièses ni astérisques. Un `.txt` qui garderait la syntaxe Markdown
 * n'est pas un fichier texte, c'est une source — et celle-là existe déjà,
 * c'est l'entrée « Markdown (.md) ».
 *
 * Conventions de la messagerie en texte brut, que tout le monde lit sans
 * mode d'emploi : titres soulignés, puces « - », citations « > », tableaux
 * en colonnes alignées.
 */
final class MarkdownPlain
{
    /** Largeur maximale d'une colonne de tableau (caractères). */
    private const MAX_COLUMN = 40;

    /**
     * @param list<array<string, mixed>> $blocks
     */
    public static function render(array $blocks): string
    {
        return rtrim(self::blocks($blocks, '')) . "\n";
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private static function blocks(array $blocks, string $prefix): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $text = match ($block['type'] ?? '') {
                'heading'   => self::heading($block),
                'paragraph' => self::runs($block['inlines'] ?? []),
                'list'      => self::listBlock($block, 0),
                'table'     => self::table($block),
                'code'      => self::indent((string)($block['text'] ?? ''), '    '),
                'quote'     => self::indent(rtrim(self::blocks($block['blocks'] ?? [], '')), '> '),
                'rule'      => str_repeat('-', 40),
                default     => '',
            };
            if ($text !== '') {
                $parts[] = $prefix === '' ? $text : self::indent($text, $prefix);
            }
        }

        return implode("\n\n", $parts) . "\n";
    }

    private static function heading(array $block): string
    {
        $text  = trim(str_replace("\n", ' ', self::runs($block['inlines'] ?? [])));
        $level = (int)($block['level'] ?? 1);
        if ($text === '') {
            return '';
        }
        if ($level > 2) {
            return $text;
        }

        return $text . "\n" . str_repeat($level === 1 ? '=' : '-', max(3, mb_strwidth($text, 'UTF-8')));
    }

    private static function listBlock(array $block, int $level): string
    {
        $ordered = !empty($block['ordered']);
        $start   = max(1, (int)($block['start'] ?? 1));
        $pad     = str_repeat('  ', $level);
        $lines   = [];

        foreach (($block['items'] ?? []) as $index => $item) {
            $marker  = $ordered ? ($start + $index) . '. ' : '- ';
            $checked = $item['checked'] ?? null;
            $box     = $checked === null ? '' : ($checked ? '[x] ' : '[ ] ');
            $text    = self::runs($item['inlines'] ?? []);
            // Les lignes suivantes de l'élément s'alignent sous son texte.
            $hang    = $pad . str_repeat(' ', mb_strwidth($marker, 'UTF-8'));
            $lines[] = $pad . $marker . $box . str_replace("\n", "\n" . $hang, $text);

            foreach (($item['children'] ?? []) as $child) {
                if (($child['type'] ?? '') === 'list') {
                    $lines[] = self::listBlock($child, $level + 1);
                }
            }
        }

        return implode("\n", $lines);
    }

    private static function table(array $block): string
    {
        $rows = [];
        foreach (array_merge([$block['header'] ?? []], $block['rows'] ?? []) as $row) {
            $rows[] = array_map(
                static fn (array $runs): string => trim(str_replace("\n", ' ', self::runs($runs))),
                $row
            );
        }
        if ($rows === []) {
            return '';
        }

        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $c => $cell) {
                $widths[$c] = min(self::MAX_COLUMN, max($widths[$c] ?? 3, mb_strwidth($cell, 'UTF-8')));
            }
        }

        $line = static function (array $row) use ($widths): string {
            $cells = [];
            foreach ($widths as $c => $width) {
                $cell    = (string)($row[$c] ?? '');
                $cells[] = $cell . str_repeat(' ', max(0, $width - mb_strwidth($cell, 'UTF-8')));
            }

            return rtrim(implode(' | ', $cells));
        };

        $out   = [$line($rows[0])];
        $out[] = implode('-+-', array_map(static fn (int $w): string => str_repeat('-', $w), $widths));
        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $out[] = $line($rows[$i]);
        }

        return implode("\n", $out);
    }

    /**
     * Runs → texte. Un lien garde son adresse : « libellé (https://…) » —
     * écrite UNE fois, à la fin du lien, même quand son libellé est découpé en
     * plusieurs runs (un mot en gras au milieu).
     */
    private static function runs(array $runs): string
    {
        $out   = '';
        $link  = null;   // lien en cours
        $label = '';     // son libellé, accumulé

        $close = static function () use (&$out, &$link, &$label): void {
            if ($link !== null && $link !== $label && $link !== 'mailto:' . $label) {
                $out .= ' (' . $link . ')';
            }
            $link  = null;
            $label = '';
        };

        foreach ($runs as $run) {
            $current = (!empty($run['break']) || !is_string($run['link'] ?? null) || $run['link'] === '')
                ? null
                : $run['link'];
            if ($current !== $link) {
                $close();
                $link = $current;
            }
            if (!empty($run['break'])) {
                $out .= "\n";
                continue;
            }
            $text = (string)($run['text'] ?? '');
            $out .= $text;
            if ($link !== null) {
                $label .= $text;
            }
        }
        $close();

        return $out;
    }

    private static function indent(string $text, string $prefix): string
    {
        return $prefix . str_replace("\n", "\n" . $prefix, $text);
    }
}
