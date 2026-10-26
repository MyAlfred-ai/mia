<?php

declare(strict_types=1);

/**
 * Structure de document (MarkdownDocument) → RTF. PUR, sans bibliothèque.
 *
 * POURQUOI À LA MAIN. PhpWord sait écrire du RTF, mais son écrivain ignore les
 * listes à mise en forme (aucun rendu pour ListItemRun) et pose les puces
 * comme du texte sans retrait : un document à listes — c'est-à-dire presque
 * tous — en sortait aplati. Le RTF est un format TEXTE, stable depuis trente
 * ans ; l'écrire directement donne des titres que Word reconnaît comme tels
 * (volet de navigation, table des matières), de vraies listes, de vrais
 * tableaux, et un fabricant testable partout, sans dépendance.
 *
 * TROIS FAMILLES DE LECTEURS, et le fichier doit s'ouvrir juste dans les
 * trois : Word, LibreOffice, et le moteur de texte de macOS (TextEdit, Coup
 * d'œil, Pages). Les deux points où elles divergent ont chacun coûté un rendu
 * faux avant d'être réglés :
 *
 *  - LISTES. L'ancienne écriture (`{\pntext…}{\*\pn…}`) est comprise de Word et
 *    LibreOffice, mais macOS l'ignore ENTIÈREMENT : les puces disparaissaient.
 *    On écrit donc la forme moderne — une table des listes en tête
 *    (`\listtable`), puis `\lsN\ilvlN` sur chaque élément — doublée de
 *    `{\listtext…}`, le marqueur en toutes lettres pour qui ne connaît pas les
 *    listes. Les taquets de tabulation sont placés pour que les trois lectures
 *    alignent le texte au même endroit (cf. listItem()).
 *  - TABLEAUX. `\intbl` suffit à Word ; macOS exige aussi le niveau
 *    d'imbrication `\itap1`, sans quoi chaque cellule devient un paragraphe
 *    isolé.
 *
 * Trois règles du format, toutes payées par un fichier illisible quand on les
 * oublie :
 *   1. `\`, `{` et `}` structurent le fichier : dans le texte, ils s'échappent ;
 *   2. tout caractère hors ASCII s'écrit `\uN?` — N est un entier SIGNÉ sur
 *      16 bits (au-delà de 32767 on retranche 65536), et un caractère hors du
 *      plan de base (emoji) s'écrit en DEUX unités, comme en UTF-16 ;
 *   3. un mot de commande avale l'espace qui le suit : `\b texte` écrit
 *      « texte », pas « ␣texte ».
 */
final class MarkdownRtf
{
    /** Largeur utile de la page A4 avec 2 cm de marges (twips : 1/1440 pouce). */
    private const PAGE_WIDTH = 9638;

    /** Tailles des titres, en demi-points (niveau 1 → 6). */
    private const HEADING_SIZES = [1 => 36, 2 => 30, 3 => 26, 4 => 24, 5 => 22, 6 => 22];

    /** Indices de la table des couleurs. */
    private const COLOR_HEADING = 2;
    private const COLOR_LINK    = 3;
    private const COLOR_SHADE   = 4;
    private const COLOR_MUTED   = 5;
    private const COLOR_BORDER  = 6;

    /** Puces par niveau d'imbrication : rond plein, rond vide, carré. */
    private const BULLETS = [
        ['name' => 'disc',   'code' => 8226],
        ['name' => 'circle', 'code' => 9702],
        ['name' => 'square', 'code' => 9642],
    ];

    /** Niveaux d'une liste RTF (le format en admet neuf). */
    private const MAX_LEVELS = 9;

    /**
     * Listes rencontrées pendant le rendu : une entrée par liste de premier
     * niveau, chacune décrivant ses niveaux. Écrites en tête du fichier une
     * fois le corps rendu.
     *
     * @var list<list<array{ordered: bool, start: int}>>
     */
    private array $lists = [];

    /**
     * @param list<array<string, mixed>> $blocks Blocs rendus par MarkdownDocument.
     * @param string                     $title  Titre du document (propriétés du fichier).
     * @param string                     $locale Langue de relecture orthographique par défaut.
     */
    public static function render(array $blocks, string $title = '', string $locale = 'fr'): string
    {
        $writer = new self();
        // Le corps d'abord : c'est lui qui découvre les listes à déclarer en tête.
        $body = $writer->blocks($blocks, 0, false);
        $lang = $locale === 'en' ? 1033 : 1036;

        return '{\rtf1\ansi\ansicpg1252\deff0\deflang' . $lang . '\uc1' . "\n"
            . '{\fonttbl{\f0\fswiss\fcharset0 Calibri;}{\f1\fmodern\fcharset0 Courier New;}}' . "\n"
            . '{\colortbl;\red0\green0\blue0;\red31\green56\blue100;\red5\green99\blue193;'
            . '\red242\green242\blue242;\red89\green89\blue89;\red191\green191\blue191;}' . "\n"
            . self::stylesheet() . "\n"
            . $writer->listTables()
            . '{\info{\title ' . self::text($title) . '}}' . "\n"
            . '\paperw11906\paperh16838\margl1134\margr1134\margt1134\margb1134\widowctrl' . "\n"
            . $body . '}';
    }

    /** Feuille de styles : c'est elle qui fait d'un titre un VRAI titre pour Word. */
    private static function stylesheet(): string
    {
        $out = '{\stylesheet{\s0\sa120\sl276\slmult1\f0\fs22 Normal;}';
        foreach (array_keys(self::HEADING_SIZES) as $level) {
            $out .= '{\s' . $level . '\sbasedon0\snext0' . self::headingFormat($level) . ' heading ' . $level . ';}';
        }

        return $out . '}';
    }

    private static function headingFormat(int $level): string
    {
        $size   = self::HEADING_SIZES[$level] ?? 22;
        $before = $level === 1 ? 360 : ($level === 2 ? 280 : 240);

        return '\sb' . $before . '\sa120\keepn\b\f0\fs' . $size . '\cf' . self::COLOR_HEADING;
    }

    /**
     * Table des listes et table des renvois. Chaque liste du document a la
     * sienne : c'est ce qui fait repartir une numérotation à 1 — deux listes
     * numérotées partageant une définition se suivraient (1, 2, 3 puis 4, 5).
     */
    private function listTables(): string
    {
        if ($this->lists === []) {
            return '';
        }

        $table     = '{\*\listtable';
        $overrides = '{\*\listoverridetable';

        foreach ($this->lists as $index => $levels) {
            $id     = $index + 1;
            $table .= '{\list\listtemplateid' . $id . '\listhybrid';

            for ($level = 0; $level < self::MAX_LEVELS; $level++) {
                // Niveau non rencontré dans cette liste : hérite du dernier connu.
                $spec   = $levels[$level] ?? ($levels[count($levels) - 1] ?? ['ordered' => false, 'start' => 1]);
                $indent = 720 + 360 * $level;
                $tpl    = $id * 100 + $level;

                if ($spec['ordered']) {
                    // `\'02\'0N.` : deux caractères — le numéro du niveau N, puis un point.
                    $table .= '{\listlevel\levelnfc0\levelnfcn0\leveljc0\leveljcn0\levelfollow0'
                        . '\levelstartat' . max(1, $spec['start']) . '\levelspace360\levelindent0'
                        . '{\*\levelmarker \{decimal\}.}'
                        . '{\leveltext\leveltemplateid' . $tpl . sprintf("\\'02\\'%02x.;", $level) . '}'
                        . '{\levelnumbers\\\'01;}'
                        . '\fi-360\li' . $indent . '\lin' . $indent . ' }';
                } else {
                    $bullet = self::BULLETS[$level % count(self::BULLETS)];
                    $table .= '{\listlevel\levelnfc23\levelnfcn23\leveljc0\leveljcn0\levelfollow0'
                        . '\levelstartat1\levelspace360\levelindent0'
                        . '{\*\levelmarker \{' . $bullet['name'] . '\}}'
                        . '{\leveltext\leveltemplateid' . $tpl . '\\\'01\u' . $bullet['code'] . '?;}'
                        . '{\levelnumbers;}'
                        . '\fi-360\li' . $indent . '\lin' . $indent . ' }';
                }
            }

            $table     .= '{\listname ;}\listid' . $id . '}';
            $overrides .= '{\listoverride\listid' . $id . '\listoverridecount0\ls' . $id . '}';
        }

        return $table . "}\n" . $overrides . "}\n";
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param int  $indent Retrait gauche hérité (twips) — citation.
     * @param bool $quoted Le bloc est-il dans une citation ?
     */
    private function blocks(array $blocks, int $indent, bool $quoted): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $out .= match ($block['type'] ?? '') {
                'heading'   => self::heading($block, $indent),
                'paragraph' => self::paragraph($block['inlines'] ?? [], $indent, $quoted),
                'list'      => $this->listBlock($block, $indent, $quoted),
                'table'     => self::table($block, $indent),
                'code'      => self::code((string)($block['text'] ?? ''), $indent),
                'quote'     => $this->blocks($block['blocks'] ?? [], $indent + 567, true),
                'rule'      => self::rule($indent),
                default     => '',
            };
        }

        return $out;
    }

    private static function heading(array $block, int $indent): string
    {
        $level = max(1, min(6, (int)($block['level'] ?? 1)));

        return '\pard\plain\s' . $level . '\outlinelevel' . ($level - 1)
            . ($indent > 0 ? '\li' . $indent : '')
            . self::headingFormat($level) . ' '
            . self::runs($block['inlines'] ?? [], true) . '\par' . "\n";
    }

    private static function paragraph(array $runs, int $indent, bool $quoted): string
    {
        return '\pard\plain\s0' . self::quoteFormat($indent, $quoted)
            . '\sa120\sl276\slmult1\f0\fs22 '
            . ($quoted ? '\i\cf' . self::COLOR_MUTED . ' ' : '')
            . self::runs($runs) . '\par' . "\n";
    }

    /** Retrait et filet de gauche d'un paragraphe cité. */
    private static function quoteFormat(int $indent, bool $quoted): string
    {
        if ($indent <= 0) {
            return '';
        }

        return '\li' . $indent
            . ($quoted ? '\brdrl\brdrs\brdrw30\brsp160\brdrcf' . self::COLOR_BORDER : '');
    }

    /** Liste de premier niveau : déclare sa définition, puis rend ses éléments. */
    private function listBlock(array $block, int $indent, bool $quoted): string
    {
        $levels = [];
        self::collectLevels($block, 0, $levels);
        $this->lists[] = $levels;
        $id = count($this->lists);

        // Un peu d'air après la liste (ses éléments n'ont que 3 pt entre eux).
        return $this->listItems($block, $id, 0, $indent, $quoted)
            . '\pard\plain\s0\sa0\sl120\slmult1\fs8\par' . "\n";
    }

    /**
     * Type (numérotée ou à puces) et premier numéro de chaque niveau d'une
     * liste : ceux de la PREMIÈRE sous-liste rencontrée à cette profondeur.
     *
     * @param list<array{ordered: bool, start: int}> $levels
     */
    private static function collectLevels(array $block, int $level, array &$levels): void
    {
        if ($level >= self::MAX_LEVELS) {
            return;
        }
        if (!isset($levels[$level])) {
            $levels[$level] = [
                'ordered' => !empty($block['ordered']),
                'start'   => max(1, (int)($block['start'] ?? 1)),
            ];
        }
        foreach (($block['items'] ?? []) as $item) {
            foreach (($item['children'] ?? []) as $child) {
                if (($child['type'] ?? '') === 'list') {
                    self::collectLevels($child, $level + 1, $levels);
                }
            }
        }
    }

    private function listItems(array $block, int $id, int $level, int $indent, bool $quoted): string
    {
        $level   = min($level, self::MAX_LEVELS - 1);
        $ordered = !empty($block['ordered']);
        $start   = max(1, (int)($block['start'] ?? 1));
        $out     = '';

        foreach (($block['items'] ?? []) as $index => $item) {
            if ($ordered) {
                $marker = ($start + $index) . '.';
            } else {
                $marker = '\u' . self::BULLETS[$level % count(self::BULLETS)]['code'] . '?';
            }
            $out .= self::listItem($item, $marker, $id, $level, $indent, $quoted);

            foreach (($item['children'] ?? []) as $child) {
                if (($child['type'] ?? '') === 'list') {
                    $out .= $this->listItems($child, $id, $level + 1, $indent, $quoted);
                }
            }
        }

        return $out;
    }

    /**
     * Un élément de liste.
     *
     * Les retraits tiennent en trois nombres, choisis pour que les TROIS
     * lectures posent le texte à `$text` :
     *   - la ligne commence 20 twips avant le taquet du marqueur ;
     *   - un taquet à `$text - 360` (le marqueur), un autre à `$text`.
     * Word écrit le numéro au début de la ligne puis saute au taquet suivant ;
     * macOS et les lecteurs sans listes suivent `\tab marqueur \tab` — les
     * deux tabulations tombent sur les deux taquets. Sans ce décalage de
     * 20 twips, la première tabulation de macOS sauterait le taquet du
     * marqueur (déjà atteint) et enverrait la puce à la place du texte.
     */
    private static function listItem(array $item, string $marker, int $id, int $level, int $indent, bool $quoted): string
    {
        $text    = $indent + 720 + 360 * $level;
        $checked = $item['checked'] ?? null;
        $box     = $checked === null ? '' : self::text($checked ? "\u{2611} " : "\u{2610} ");

        return '\pard\plain\s0\fi-380\li' . $text . '\tx' . ($text - 360) . '\tx' . $text
            . '\sa60\sl276\slmult1\ls' . $id . '\ilvl' . $level . '\f0\fs22 '
            . ($quoted ? '\i\cf' . self::COLOR_MUTED . ' ' : '')
            . '{\listtext\tab ' . $marker . '\tab }'
            . $box . self::runs($item['inlines'] ?? []) . '\par' . "\n";
    }

    private static function table(array $block, int $indent): string
    {
        $header = $block['header'] ?? [];
        $rows   = $block['rows'] ?? [];
        $align  = $block['align'] ?? [];
        $width  = count($header);
        if ($width === 0) {
            return '';
        }

        $edges = self::columnEdges($header, $rows, max(2000, self::PAGE_WIDTH - $indent));
        $all   = array_merge([$header], $rows);
        $last  = count($all) - 1;
        $out   = '';

        foreach ($all as $rowIndex => $row) {
            $isHeader = $rowIndex === 0;
            $border   = '\brdrs\brdrw10\brdrcf' . self::COLOR_BORDER;

            $out .= '\itap1\trowd\trgaph100\trleft' . $indent . ($isHeader ? '\trhdr' : '');
            $previous = 0;
            foreach ($edges as $edge) {
                $out .= '\clvertalt'
                    . ($isHeader ? '\clcbpat' . self::COLOR_SHADE : '')
                    . '\clwWidth' . ($edge - $previous) . '\clftsWidth3'
                    . '\clbrdrt' . $border . '\clbrdrl' . $border . '\clbrdrb' . $border . '\clbrdrr' . $border
                    . '\clpadl100\clpadr100\cellx' . ($indent + $edge);
                $previous = $edge;
            }
            $out .= "\n";

            for ($c = 0; $c < $width; $c++) {
                $mode = match ($align[$c] ?? 'left') {
                    'center' => '\qc',
                    'right'  => '\qr',
                    default  => '\ql',
                };
                $out .= '\pard\plain\intbl\itap1' . $mode . '\sb40\sa40\f0\fs20 '
                    . ($isHeader ? '\b ' : '')
                    . self::runs($row[$c] ?? [], $isHeader) . '\cell' . "\n";
            }
            $out .= ($rowIndex === $last ? '\lastrow' : '') . '\row' . "\n";
        }

        // Paragraphe vide : sans lui, le texte qui suit colle au tableau.
        return $out . '\pard\plain\s0\sa120\fs12\par' . "\n";
    }

    /**
     * Bords droits des colonnes (twips), proportionnels au plus long contenu de
     * chacune — borné, pour qu'une cellule bavarde n'écrase pas ses voisines.
     *
     * @return list<int>
     */
    private static function columnEdges(array $header, array $rows, int $total): array
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

        $edges = [];
        $pos   = 0;
        foreach ($weights as $weight) {
            $pos    += (int)round($total * $weight / $sum);
            $edges[] = $pos;
        }
        // L'arrondi ne doit pas faire déborder la dernière colonne de la page.
        if ($edges !== []) {
            $edges[count($edges) - 1] = $total;
        }

        return $edges;
    }

    private static function code(string $text, int $indent): string
    {
        $lines = explode("\n", $text);

        return '\pard\plain\s0\li' . ($indent + 200) . '\ri200\sb120\sa160\sl240\slmult1\keep'
            . '\cbpat' . self::COLOR_SHADE . '\f1\fs18 '
            . implode('\line ', array_map([self::class, 'text'], $lines))
            . '\par' . "\n";
    }

    private static function rule(int $indent): string
    {
        return '\pard\plain\s0' . ($indent > 0 ? '\li' . $indent : '')
            . '\sb120\sa120\brdrb\brdrs\brdrw10\brsp20\brdrcf' . self::COLOR_BORDER . '\fs4\par' . "\n";
    }

    /**
     * Runs → RTF. Chaque run mis en forme vit dans son propre groupe `{…}` :
     * la mise en forme ne déborde jamais sur le texte suivant.
     *
     * @param bool $plain Titres et en-têtes de tableau : le gras est déjà posé, on ne le répète pas.
     */
    private static function runs(array $runs, bool $plain = false): string
    {
        $out = '';
        foreach ($runs as $run) {
            if (!empty($run['break'])) {
                $out .= '\line ';
                continue;
            }
            $text = self::text((string)($run['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $format = '';
            if (!empty($run['bold']) && !$plain) {
                $format .= '\b';
            }
            if (!empty($run['italic'])) {
                $format .= '\i';
            }
            if (!empty($run['strike'])) {
                $format .= '\strike';
            }
            if (!empty($run['code'])) {
                $format .= '\f1\fs20\chcbpat' . self::COLOR_SHADE;
            }

            $link = $run['link'] ?? null;
            if (is_string($link) && $link !== '') {
                $out .= '{\field{\*\fldinst{HYPERLINK "' . self::fieldUrl($link) . '"}}'
                    . '{\fldrslt{\ul\cf' . self::COLOR_LINK . $format . ' ' . $text . '}}}';
                continue;
            }

            $out .= $format === '' ? $text : '{' . $format . ' ' . $text . '}';
        }

        return $out;
    }

    /**
     * Texte → RTF. Public : c'est LE point du format où une erreur rend le
     * fichier illisible, il est donc testé pour lui-même.
     */
    public static function text(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $out = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');

            if ($char === '\\' || $char === '{' || $char === '}') {
                $out .= '\\' . $char;
            } elseif ($char === "\n") {
                $out .= '\line ';
            } elseif ($char === "\t") {
                $out .= '\tab ';
            } elseif ($code < 0x20 || $code === 0x7F) {
                continue;                              // autres caractères de contrôle : écartés
            } elseif ($code < 0x7F) {
                $out .= $char;
            } elseif ($code === 0xA0) {
                $out .= '\~';                          // espace insécable
            } elseif ($code > 0xA0 && $code <= 0xFF) {
                // Latin-1 « haut » : mêmes octets qu'en Windows-1252, la page
                // de codes déclarée en tête. Lisible même par un très vieux
                // lecteur, qui ignorerait `\u`.
                $out .= sprintf("\\'%02x", $code);
            } elseif ($code <= 0xFFFF) {
                $out .= '\u' . ($code > 32767 ? $code - 65536 : $code) . '?';
            } else {
                // Hors plan de base : paire de substitution, comme en UTF-16.
                $code -= 0x10000;
                $high  = 0xD800 + ($code >> 10);
                $low   = 0xDC00 + ($code & 0x3FF);
                $out  .= '\u' . ($high - 65536) . '?\u' . ($low - 65536) . '?';
            }
        }

        return $out;
    }

    /** URL dans une instruction de champ : pas de guillemet ni d'accolade. */
    private static function fieldUrl(string $url): string
    {
        $url = strtr($url, ['"' => '%22', '{' => '%7B', '}' => '%7D', '\\' => '%5C', ' ' => '%20']);

        return self::text($url);
    }
}
