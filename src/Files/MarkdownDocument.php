<?php

declare(strict_types=1);

/**
 * Markdown → structure de document. Analyse PURE, sans bibliothèque.
 *
 * Un document mis en forme (Word, RTF, PDF) est livré par le modèle en
 * Markdown. Trois fabricants en ont besoin, et aucun ne doit relire le
 * Markdown à sa façon : on l'analyse UNE fois en blocs, et chaque format rend
 * ces blocs (MarkdownRtf, MarkdownHtml, MarkdownPlain, DocxFileBuilder). Trois
 * analyses séparées donneraient trois documents différents pour le même
 * texte — un tableau présent dans le PDF et absent du RTF.
 *
 * Ce n'est PAS un moteur CommonMark complet, et c'est assumé : il couvre ce
 * qu'un modèle écrit dans un document (titres, paragraphes, listes imbriquées,
 * tableaux, citations, blocs de code, filets, emphase, liens). Le dépôt
 * n'embarque pas de bibliothèque Markdown (cf. MailHtml, qui en traite un
 * sous-ensemble plus étroit pour les emails) ; celle-ci ne rend d'ailleurs pas
 * de HTML mais une structure, que seuls les fabricants savent écrire.
 *
 * Forme des blocs :
 *   ['type' => 'heading',   'level' => 1..6, 'inlines' => runs]
 *   ['type' => 'paragraph', 'inlines' => runs]
 *   ['type' => 'list',      'ordered' => bool, 'start' => int, 'items' => [
 *        ['inlines' => runs, 'checked' => bool|null, 'children' => blocs]…]]
 *   ['type' => 'table',     'header' => [runs…], 'align' => ['left'|'center'|'right'…],
 *                           'rows' => [[runs…]…]]
 *   ['type' => 'code',      'lang' => string, 'text' => string]
 *   ['type' => 'quote',     'blocks' => blocs]
 *   ['type' => 'rule']
 *
 * Forme d'un « run » (fragment de texte à mise en forme constante) :
 *   ['text' => string, 'bold' => bool, 'italic' => bool, 'strike' => bool,
 *    'code' => bool, 'link' => string|null, 'break' => bool]
 * Un saut de ligne dans un paragraphe est un run `break` (texte vide), comme
 * dans l'aperçu du chat, qui rend les retours à la ligne simples.
 */
final class MarkdownDocument
{
    /** Garde-fou : profondeur d'imbrication (citations, listes). */
    private const MAX_DEPTH = 12;

    /** Schémas d'URL conservés dans un lien. */
    private const SCHEMES = ['http://', 'https://', 'mailto:'];

    /**
     * Motifs de ligne qui ouvrent un bloc. Publics : MarkdownDeck::sourceCards
     * relit la source ligne à ligne et doit couper exactement là où ce
     * lecteur voit un titre, un bloc de code ou un titre souligné.
     */
    public const FENCE_OPEN = '/^ {0,3}(`{3,}|~{3,})\s*([^`]*)$/';
    public const HEADING    = '/^ {0,3}(#{1,6})[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$/';
    /** Soulignement d'un titre (`===` niveau 1, `---` niveau 2), sous un paragraphe. */
    public const SETEXT     = '/^ {0,3}(=+|-{2,})[ \t]*$/';

    /**
     * @return list<array<string, mixed>>
     */
    public static function parse(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = str_replace("\t", '    ', $markdown);

        return self::parseBlocks(explode("\n", $markdown), 0);
    }

    /**
     * Texte brut → paragraphes, SANS interpréter aucune syntaxe. Sert aux
     * fichiers dont le bloc est en ```text : un astérisque y est un astérisque.
     * Une ligne vide sépare deux paragraphes, un retour simple est un saut de
     * ligne.
     *
     * @return list<array<string, mixed>>
     */
    public static function fromPlainText(string $text): array
    {
        $text   = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = [];
        foreach (preg_split('/\n[ \t]*\n+/', trim($text, "\n")) ?: [] as $chunk) {
            if (trim($chunk) === '') {
                continue;
            }
            $runs = [];
            foreach (explode("\n", $chunk) as $i => $line) {
                if ($i > 0) {
                    $runs[] = self::run('', ['break' => true]);
                }
                if ($line !== '') {
                    $runs[] = self::run($line);
                }
            }
            $blocks[] = ['type' => 'paragraph', 'inlines' => $runs];
        }

        return $blocks;
    }

    /** Titre du document : son premier titre, sinon '' (l'appelant a le nom du fichier). */
    public static function title(array $blocks): string
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'heading') {
                return trim(self::plainText($block['inlines'] ?? []));
            }
        }

        return '';
    }

    /** Texte d'une suite de runs, sans mise en forme. */
    public static function plainText(array $runs): string
    {
        $out = '';
        foreach ($runs as $run) {
            $out .= !empty($run['break']) ? "\n" : (string)($run['text'] ?? '');
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Blocs
    // ------------------------------------------------------------------

    /**
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private static function parseBlocks(array $lines, int $depth): array
    {
        $blocks = [];
        $count  = count($lines);
        $i      = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Bloc de code délimité (``` ou ~~~).
            if (preg_match(self::FENCE_OPEN, $line, $m) === 1) {
                [$block, $i] = self::parseFence($lines, $i, $m[1], trim($m[2]));
                $blocks[]    = $block;
                continue;
            }

            // Titre ATX.
            if (preg_match(self::HEADING, $line, $m) === 1) {
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'inlines' => self::inline($m[2])];
                $i++;
                continue;
            }

            // Filet horizontal.
            if (self::isRule($line)) {
                $blocks[] = ['type' => 'rule'];
                $i++;
                continue;
            }

            // Citation.
            if (preg_match('/^ {0,3}>/', $line) === 1 && $depth < self::MAX_DEPTH) {
                $inner = [];
                while ($i < $count && preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $q) === 1) {
                    $inner[] = $q[1];
                    $i++;
                }
                $blocks[] = ['type' => 'quote', 'blocks' => self::parseBlocks($inner, $depth + 1)];
                continue;
            }

            // Tableau : ligne à barres suivie d'une ligne de tirets.
            if (str_contains($line, '|') && $i + 1 < $count && self::isTableSeparator($lines[$i + 1])) {
                [$block, $i] = self::parseTable($lines, $i);
                $blocks[]    = $block;
                continue;
            }

            // Liste.
            if (self::listMarker($line) !== null && $depth < self::MAX_DEPTH) {
                [$block, $i] = self::parseList($lines, $i, $depth);
                $blocks[]    = $block;
                continue;
            }

            // Paragraphe (ou titre « souligné »).
            $paragraph = [trim($line)];
            $i++;
            while ($i < $count) {
                $next = $lines[$i];
                if (trim($next) === '') {
                    break;
                }
                // Titre souligné : `===` → niveau 1, `---` → niveau 2.
                if (preg_match(self::SETEXT, $next, $u) === 1) {
                    $blocks[] = [
                        'type'    => 'heading',
                        'level'   => $u[1][0] === '=' ? 1 : 2,
                        'inlines' => self::inline(implode("\n", $paragraph)),
                    ];
                    $paragraph = [];
                    $i++;
                    break;
                }
                if (self::startsBlock($next)
                    || (str_contains($next, '|') && $i + 1 < $count && self::isTableSeparator($lines[$i + 1]))) {
                    break;
                }
                $paragraph[] = trim($next);
                $i++;
            }
            if ($paragraph !== []) {
                $blocks[] = ['type' => 'paragraph', 'inlines' => self::inline(implode("\n", $paragraph))];
            }
        }

        return $blocks;
    }

    /**
     * @param list<string> $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function parseFence(array $lines, int $i, string $fence, string $info): array
    {
        $char   = $fence[0];
        $length = strlen($fence);
        $indent = strlen($lines[$i]) - strlen(ltrim($lines[$i], ' '));
        $count  = count($lines);
        $body   = [];
        $i++;

        while ($i < $count) {
            // Fermeture : même caractère, au moins aussi long, rien derrière.
            if (preg_match('/^ {0,3}(' . preg_quote($char, '/') . '{' . $length . ',})[ \t]*$/', $lines[$i]) === 1) {
                $i++;
                break;
            }
            $current = $lines[$i];
            // Retire l'indentation du délimiteur (bloc de code dans une liste).
            for ($k = 0; $k < $indent && $current !== '' && $current[0] === ' '; $k++) {
                $current = substr($current, 1);
            }
            $body[] = $current;
            $i++;
        }

        $lang = (string)preg_replace('/\s.*$/s', '', $info);

        return [['type' => 'code', 'lang' => strtolower($lang), 'text' => implode("\n", $body)], $i];
    }

    /**
     * @param list<string> $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function parseTable(array $lines, int $i): array
    {
        $header = self::splitTableRow($lines[$i]);
        $align  = [];
        foreach (self::splitTableRow($lines[$i + 1]) as $cell) {
            $cell    = trim($cell);
            $left    = str_starts_with($cell, ':');
            $right   = str_ends_with($cell, ':');
            $align[] = $left && $right ? 'center' : ($right ? 'right' : 'left');
        }
        $width = count($header);
        $align = array_pad(array_slice($align, 0, $width), $width, 'left');
        $i    += 2;

        $rows  = [];
        $count = count($lines);
        while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
            $cells  = array_pad(array_slice(self::splitTableRow($lines[$i]), 0, $width), $width, '');
            $rows[] = array_map(static fn (string $c): array => self::inline($c), $cells);
            $i++;
        }

        return [[
            'type'   => 'table',
            'header' => array_map(static fn (string $c): array => self::inline($c), $header),
            'align'  => $align,
            'rows'   => $rows,
        ], $i];
    }

    /** @return list<string> */
    private static function splitTableRow(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|') && !str_ends_with($line, '\\|')) {
            $line = substr($line, 0, -1);
        }

        return array_map(
            static fn (string $cell): string => trim(str_replace('\\|', '|', $cell)),
            preg_split('/(?<!\\\\)\|/', $line) ?: []
        );
    }

    /**
     * Liste, imbrications comprises. L'imbrication se lit à l'INDENTATION :
     * un marqueur en retrait d'au moins deux espaces par rapport à la liste
     * courante ouvre une sous-liste de l'élément précédent.
     *
     * @param list<string> $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function parseList(array $lines, int $i, int $depth): array
    {
        $first   = self::listMarker($lines[$i]);
        $base    = $first['indent'];
        $ordered = $first['ordered'];
        $items   = [];
        $count   = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                // Ligne vide : la liste continue si la suite lui appartient.
                $next = $i + 1;
                while ($next < $count && trim($lines[$next]) === '') {
                    $next++;
                }
                if ($next >= $count) {
                    break;
                }
                $marker = self::listMarker($lines[$next]);
                $indent = strlen($lines[$next]) - strlen(ltrim($lines[$next], ' '));
                $belongs = ($marker !== null && $marker['indent'] >= $base
                        && ($marker['indent'] >= $base + 2 || $marker['ordered'] === $ordered))
                    || ($marker === null && $items !== [] && $indent >= $base + 2);
                if (!$belongs) {
                    break;
                }
                $i = $next;
                continue;
            }

            $marker = self::listMarker($line);
            if ($marker !== null) {
                if ($marker['indent'] < $base) {
                    break;                          // appartient à une liste parente
                }
                if ($marker['indent'] >= $base + 2 && $items !== []) {
                    // Sous-liste de l'élément précédent.
                    if ($depth + 1 >= self::MAX_DEPTH) {
                        $i++;
                        continue;
                    }
                    [$child, $i] = self::parseList($lines, $i, $depth + 1);
                    $items[count($items) - 1]['children'][] = $child;
                    continue;
                }
                if ($marker['ordered'] !== $ordered) {
                    break;                          // autre type de liste : une nouvelle commence
                }

                $text    = $marker['text'];
                $checked = null;
                if (preg_match('/^\[([ xX])\][ \t]+(.*)$/s', $text, $task) === 1) {
                    $checked = strtolower($task[1]) === 'x';
                    $text    = $task[2];
                }
                $items[] = ['text' => $text, 'checked' => $checked, 'children' => []];
                $i++;
                continue;
            }

            // Ligne sans marqueur : suite de l'élément courant si elle est en
            // retrait, ou collée à lui (continuation « paresseuse »).
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($items === [] || self::startsBlock($line)) {
                break;
            }
            if ($indent < $base + 2 && ($i === 0 || trim($lines[$i - 1]) === '')) {
                break;
            }
            $items[count($items) - 1]['text'] .= "\n" . trim($line);
            $i++;
        }

        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'inlines'  => self::inline($item['text']),
                'checked'  => $item['checked'],
                'children' => $item['children'],
            ];
        }

        return [[
            'type'    => 'list',
            'ordered' => $ordered,
            'start'   => $first['start'],
            'items'   => $out,
        ], $i];
    }

    /**
     * Marqueur de liste en tête de ligne, ou null.
     *
     * Publiques, comme isRule et isTableSeparator : MarkdownDeck::sourceCards
     * relit la source LIGNE À LIGNE avec les mêmes motifs — deux jeux de
     * motifs ne découperaient pas la présentation aux mêmes endroits.
     *
     * @return array{indent: int, ordered: bool, start: int, text: string}|null
     */
    public static function listMarker(string $line): ?array
    {
        if (preg_match('/^( *)([-*+•]|\d{1,9}[.)])[ \t]+(.*)$/su', $line, $m) !== 1) {
            return null;
        }
        // « --- » et « * * * » sont des filets, pas des listes.
        if (self::isRule($line)) {
            return null;
        }
        $ordered = ctype_digit($m[2][0]);

        return [
            'indent'  => strlen($m[1]),
            'ordered' => $ordered,
            'start'   => $ordered ? (int)$m[2] : 1,
            'text'    => $m[3],
        ];
    }

    public static function isRule(string $line): bool
    {
        return preg_match('/^ {0,3}(?:(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})$/', $line) === 1;
    }

    public static function isTableSeparator(string $line): bool
    {
        return str_contains($line, '-')
            && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $line) === 1
            && (str_contains($line, '|') || preg_match('/^\s*:?-+:?\s*$/', $line) !== 1);
    }

    /** La ligne ouvre-t-elle un bloc qui interrompt un paragraphe ? */
    private static function startsBlock(string $line): bool
    {
        return preg_match('/^ {0,3}(?:#{1,6}[ \t]|>|`{3,}|~{3,})/', $line) === 1
            || self::isRule($line)
            || self::listMarker($line) !== null;
    }

    // ------------------------------------------------------------------
    // Mise en forme de ligne
    // ------------------------------------------------------------------

    /**
     * Texte de ligne → runs. Le plus à gauche des motifs l'emporte ; le contenu
     * d'une emphase ou d'un lien est analysé à son tour, avec le style hérité.
     *
     * @return list<array<string, mixed>>
     */
    public static function inline(string $text): array
    {
        $runs = [];
        self::scan($text, [], $runs, 0);

        return self::merge($runs);
    }

    /**
     * @param array<string, mixed>       $style
     * @param list<array<string, mixed>> $runs
     */
    private static function scan(string $text, array $style, array &$runs, int $depth): void
    {
        static $pattern = null;
        if ($pattern === null) {
            // Délimiteur « % » : le motif contient déjà ~, #, / et |.
            $pattern = '%'
                . '(?<esc>\\\\[\\\\`*_{}\[\]()#+\-.!|>~<])'
                . '|(?<code>(?<tick>`+)(?<codetext>.+?)(?<!`)\k<tick>(?!`))'
                . '|(?<img>!\[(?<alt>[^\]]*)\]\((?<imgurl>[^)\s]+)(?:\s+"[^"]*")?\))'
                // URL : un niveau de parenthèses admis (« …/Page_(homonymie) »),
                // sans quoi la parenthèse fermante du lien serait prise trop tôt.
                . '|(?<link>\[(?<label>(?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*<?(?<url>(?:[^()\s<>]|\([^()\s]*\))+)>?(?:\s+(?:"[^"]*"|\'[^\']*\'))?\s*\))'
                . '|(?<auto><(?<autourl>(?:https?://|mailto:)[^>\s]+)>)'
                . '|(?<bare>\bhttps?://[^\s<>\[\]()]*[^\s<>\[\]().,;:!?\'"»])'
                . '|(?<br><br\s*/?>)'
                . '|(?<tag></?[a-zA-Z][a-zA-Z0-9]*(?:\s[^<>]*)?/?>)'
                // « ***texte*** » avant le gras : sinon le gras avale deux des
                // trois astérisques et en laisse un de chaque côté.
                . '|(?<both>\*\*\*(?=\S)(?<bothtext>.+?)(?<=\S)\*\*\*)'
                . '|(?<strong>(?<sm>\*\*|__)(?=\S)(?<strongtext>.+?)(?<=\S)\k<sm>)'
                . '|(?<strike>~~(?=\S)(?<striketext>.+?)(?<=\S)~~)'
                . '|(?<em>(?<![\p{L}\p{N}*])\*(?=[^\s*])(?<emtext>[^*]+?)(?<=[^\s*])\*(?![\p{L}\p{N}*])'
                . '|(?<![\p{L}\p{N}_])_(?=[^\s_])(?<emtext2>[^_]+?)(?<=[^\s_])_(?![\p{L}\p{N}_]))'
                . '|(?<nl>\n)'
                . '%su';
        }

        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            if ($depth > self::MAX_DEPTH
                || preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                self::push($runs, substr($text, $offset), $style);

                return;
            }

            $start = (int)$m[0][1];
            if ($start > $offset) {
                self::push($runs, substr($text, $offset, $start - $offset), $style);
            }
            $offset = $start + strlen($m[0][0]);

            $has = static fn (string $name): bool => isset($m[$name]) && $m[$name][1] !== -1 && $m[$name][0] !== '';

            if ($has('esc')) {
                self::push($runs, substr($m['esc'][0], 1), $style);
            } elseif ($has('code')) {
                self::push($runs, trim($m['codetext'][0], ' '), ['code' => true] + $style, false);
            } elseif ($has('img')) {
                // Image : le texte de remplacement suffit — un document
                // fabriqué ne va chercher aucune ressource distante.
                $alt = trim($m['alt'][0]);
                if ($alt !== '') {
                    self::push($runs, $alt, ['italic' => true] + $style);
                }
            } elseif ($has('link')) {
                $url = self::safeUrl($m['url'][0]);
                self::scan($m['label'][0], $url !== null ? ['link' => $url] + $style : $style, $runs, $depth + 1);
            } elseif ($has('auto')) {
                $url = self::safeUrl($m['autourl'][0]);
                self::push($runs, $m['autourl'][0], $url !== null ? ['link' => $url] + $style : $style);
            } elseif ($has('bare')) {
                $url = self::safeUrl($m['bare'][0]);
                self::push($runs, $m['bare'][0], $url !== null && empty($style['link']) ? ['link' => $url] + $style : $style);
            } elseif ($has('br') || $has('nl')) {
                $runs[] = self::run('', ['break' => true]);
            } elseif ($has('tag')) {
                // Balise HTML de ligne : ignorée, son contenu reste.
                continue;
            } elseif ($has('both')) {
                self::scan($m['bothtext'][0], ['bold' => true, 'italic' => true] + $style, $runs, $depth + 1);
            } elseif ($has('strong')) {
                self::scan($m['strongtext'][0], ['bold' => true] + $style, $runs, $depth + 1);
            } elseif ($has('strike')) {
                self::scan($m['striketext'][0], ['strike' => true] + $style, $runs, $depth + 1);
            } elseif ($has('em')) {
                $inner = $has('emtext') ? $m['emtext'][0] : $m['emtext2'][0];
                self::scan($inner, ['italic' => true] + $style, $runs, $depth + 1);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @param array<string, mixed>       $style
     */
    private static function push(array &$runs, string $text, array $style, bool $decode = true): void
    {
        if ($text === '') {
            return;
        }
        if ($decode && str_contains($text, '&')) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $runs[] = self::run($text, $style);
    }

    /**
     * @param array<string, mixed> $style
     * @return array<string, mixed>
     */
    private static function run(string $text, array $style = []): array
    {
        return [
            'text'   => $text,
            'bold'   => !empty($style['bold']),
            'italic' => !empty($style['italic']),
            'strike' => !empty($style['strike']),
            'code'   => !empty($style['code']),
            'link'   => isset($style['link']) && is_string($style['link']) ? $style['link'] : null,
            'break'  => !empty($style['break']),
        ];
    }

    /**
     * Fusionne les runs voisins de même style ; retire les sauts de ligne de
     * bord (un paragraphe ne commence ni ne finit par un saut).
     *
     * @param list<array<string, mixed>> $runs
     * @return list<array<string, mixed>>
     */
    private static function merge(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            $last = count($out) - 1;
            if ($last >= 0 && !$run['break'] && !$out[$last]['break']
                && $out[$last]['bold'] === $run['bold'] && $out[$last]['italic'] === $run['italic']
                && $out[$last]['strike'] === $run['strike'] && $out[$last]['code'] === $run['code']
                && $out[$last]['link'] === $run['link']) {
                $out[$last]['text'] .= $run['text'];
                continue;
            }
            $out[] = $run;
        }

        while ($out !== [] && $out[0]['break']) {
            array_shift($out);
        }
        while ($out !== [] && $out[count($out) - 1]['break']) {
            array_pop($out);
        }

        return array_values($out);
    }

    /** URL conservée si son schéma est acceptable, null sinon (le lien devient du texte). */
    private static function safeUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (self::SCHEMES as $scheme) {
            if (stripos($url, $scheme) === 0) {
                return $url;
            }
        }

        return null;
    }
}
