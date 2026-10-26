<?php

declare(strict_types=1);

/**
 * Tableau écrit par un modèle → lignes et cellules. Analyse PURE, sans
 * bibliothèque : c'est elle qui décide du contenu d'un classeur, elle doit
 * pouvoir être testée partout (poste, CI) sans PhpSpreadsheet.
 *
 * Un modèle n'écrit pas du CSV d'école : le séparateur change d'une réponse à
 * l'autre (« ; » quand on le lui demande, « , » par habitude, tabulation
 * parfois), une ligne de titre précède l'en-tête, des espaces suivent les
 * séparateurs, et il lui arrive de livrer une table Markdown dans un bloc
 * ```csv. Tout cela est accepté ici plutôt que reproché au modèle.
 *
 * Le même algorithme existe en JavaScript (`parseTable`, generated-files.js)
 * pour l'APERÇU de la carte. Les deux sont tenus d'accord par un jeu de cas
 * partagé (`tests/Fixtures/tabular-cases.json`), rejoué des deux côtés : un
 * aperçu qui montrerait trois colonnes pour un classeur qui en a quatre serait
 * pire que pas d'aperçu du tout.
 */
final class TabularData
{
    public const MAX_ROWS  = 20000;
    public const MAX_COLS  = 256;
    public const MAX_CELLS = 150000;

    /** Séparateurs candidats, dans l'ordre de préférence à égalité de score. */
    private const DELIMITERS = ["\t", ';', ',', '|'];

    /** Nombre d'enregistrements examinés pour deviner le séparateur. */
    private const SNIFF_RECORDS = 60;

    /**
     * @param list<list<string>> $rows      Lignes rectangulaires (même nombre de cellules).
     * @param string             $delimiter Séparateur reconnu ('' = une seule colonne).
     */
    private function __construct(
        private array $rows,
        private string $delimiter,
        private bool $fromMarkdown
    ) {
    }

    /**
     * @throws FileBuildException Tableau au-delà des plafonds.
     */
    public static function parse(string $source): self
    {
        $text = self::normalize($source);
        if (trim($text) === '') {
            return new self([], ';', false);
        }

        $markdown = self::parseMarkdownTable($text);
        if ($markdown !== null) {
            return new self(self::rectangular($markdown), ';', true);
        }

        $delimiter = self::detectDelimiter($text);

        return new self(self::rectangular(self::parseDelimited($text, $delimiter)), $delimiter, false);
    }

    /** @return list<list<string>> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        return $this->rows === [] ? 0 : count($this->rows[0]);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** La source était une table Markdown (`| a | b |`), pas du texte délimité. */
    public function isFromMarkdown(): bool
    {
        return $this->fromMarkdown;
    }

    /**
     * Séparateur à employer pour réécrire ce tableau en CSV : celui de la
     * source quand il y en avait un (le modèle a pu suivre une demande
     * explicite de l'utilisateur), le point-virgule sinon.
     */
    public function delimiter(): string
    {
        return in_array($this->delimiter, [';', ',', "\t"], true) ? $this->delimiter : ';';
    }

    /**
     * La première ligne est-elle une ligne d'en-têtes ?
     *
     * Oui dès qu'il y a au moins deux lignes et qu'aucune cellule renseignée de
     * la première n'est un nombre ou une date : « Mois;CA;Marge » est un
     * en-tête, « 2024;12;8 » n'en est pas un. Une table Markdown a toujours un
     * en-tête, par construction.
     */
    public function hasHeader(string $locale = 'fr'): bool
    {
        if (count($this->rows) < 2) {
            return false;
        }
        if ($this->fromMarkdown) {
            return true;
        }

        $filled = 0;
        foreach ($this->rows[0] as $cell) {
            if (trim($cell) === '') {
                continue;
            }
            $filled++;
            if (SpreadsheetCell::classify($cell, $locale)['type'] !== SpreadsheetCell::TEXT) {
                return false;
            }
        }

        return $filled > 0;
    }

    /**
     * Réécriture en CSV conforme (RFC 4180) : guillemets autour d'une cellule
     * qui contient le séparateur, un guillemet ou un saut de ligne.
     *
     * `$neutralize` protège d'une injection de formule (cf. SpreadsheetFormula) :
     * activé par défaut, parce qu'un CSV est presque toujours ouvert dans un
     * tableur et que le contenu peut venir d'une page web ou d'un email lus
     * par le modèle.
     */
    public function toCsv(?string $delimiter = null, string $eol = "\r\n", bool $neutralize = true): string
    {
        $delimiter = $delimiter ?? $this->delimiter();
        $out = '';
        foreach ($this->rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                if ($neutralize) {
                    $cell = SpreadsheetFormula::neutralize($cell);
                }
                $cells[] = self::quoteCsvCell($cell, $delimiter);
            }
            $out .= implode($delimiter, $cells) . $eol;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Analyse
    // ------------------------------------------------------------------

    /** BOM retiré, fins de ligne ramenées à LF, octets nuls écartés. */
    public static function normalize(string $source): string
    {
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3);
        }

        return str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $source);
    }

    /**
     * Séparateur le plus vraisemblable, '' si le texte n'a qu'une colonne.
     *
     * Pour chaque candidat on compte les cellules de chaque enregistrement :
     * le bon séparateur est celui qui donne le PLUS SOUVENT le même nombre de
     * cellules, ce nombre valant au moins 2. À régularité égale, le plus grand
     * nombre de colonnes l'emporte (« Nom;Adresse, Ville;Âge » : le
     * point-virgule découpe en trois, la virgule en deux).
     *
     * La régularité protège du piège classique : un CSV français à
     * point-virgule dont les nombres portent une virgule décimale. La virgule
     * y apparaît sur presque chaque ligne, mais pas sur l'en-tête — elle perd.
     */
    public static function detectDelimiter(string $text): string
    {
        $sample = self::firstRecordsText($text);
        $best      = '';
        $bestScore = 0.0;
        $bestCols  = 0;

        foreach (self::DELIMITERS as $candidate) {
            if (!str_contains($sample, $candidate)) {
                continue;
            }
            $records = self::parseDelimited($sample, $candidate);
            if ($records === []) {
                continue;
            }

            $frequency = [];
            foreach ($records as $record) {
                $n = count($record);
                $frequency[$n] = ($frequency[$n] ?? 0) + 1;
            }
            // Nombre de cellules le plus fréquent ; à égalité, le plus grand.
            $mode = 0;
            $hits = 0;
            foreach ($frequency as $n => $count) {
                if ($count > $hits || ($count === $hits && $n > $mode)) {
                    $mode = (int)$n;
                    $hits = $count;
                }
            }
            if ($mode < 2) {
                continue;
            }

            $score = $hits / count($records);
            if ($score > $bestScore + 1e-9 || (abs($score - $bestScore) <= 1e-9 && $mode > $bestCols)) {
                $best      = $candidate;
                $bestScore = $score;
                $bestCols  = $mode;
            }
        }

        return $best;
    }

    /**
     * Texte délimité → enregistrements. Automate à états, octet par octet :
     * les séparateurs et le guillemet sont ASCII, donc jamais confondus avec
     * un octet d'un caractère UTF-8 multi-octets.
     *
     * Tolérances assumées : des espaces avant le guillemet ouvrant (« a, "b" »),
     * un guillemet au milieu d'une cellule non citée (`5" écran`), un guillemet
     * jamais refermé (le reste du texte devient la cellule). Les cellules non
     * citées sont débarrassées de leurs espaces de bord ; les lignes vides sont
     * ignorées.
     *
     * @return list<list<string>>
     * @throws FileBuildException
     */
    public static function parseDelimited(string $text, string $delimiter): array
    {
        $rows   = [];
        $row    = [];
        $cell   = '';
        $quoted = false;   // la cellule courante a été ouverte par un guillemet
        $inside = false;   // on est entre les guillemets
        $cells  = 0;
        $length = strlen($text);

        $endCell = static function () use (&$row, &$cell, &$quoted): void {
            $row[]  = $quoted ? $cell : trim($cell, " \t");
            $cell   = '';
            $quoted = false;
        };
        $endRow = static function () use (&$rows, &$row, &$cells, $endCell): void {
            $endCell();
            // Ligne vide (une seule cellule vide) : ignorée.
            if (!(count($row) === 1 && $row[0] === '')) {
                $rows[] = $row;
                $cells += count($row);
                if (count($rows) > self::MAX_ROWS || $cells > self::MAX_CELLS) {
                    throw FileBuildException::tableTooLarge(count($rows), count($row));
                }
            }
            $row = [];
        };

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($inside) {
                if ($char === '"') {
                    if ($i + 1 < $length && $text[$i + 1] === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $inside = false;
                    }
                } else {
                    $cell .= $char;
                }
                continue;
            }

            if ($char === '"' && !$quoted && trim($cell, " \t") === '') {
                $cell   = '';
                $quoted = true;
                $inside = true;
            } elseif ($quoted && ($char === ' ' || $char === "\t") && $char !== $delimiter) {
                // Blancs entre le guillemet fermant et le séparateur : ils ne
                // font pas partie de la cellule (`"abc"  ;` vaut « abc »).
                continue;
            } elseif ($delimiter !== '' && $char === $delimiter) {
                if (!$quoted && self::formulaIsOpen($cell)) {
                    // Séparateur DANS une formule non citée : `=SI(A1>0;"oui";"non")`
                    // dans un CSV à point-virgule. Il appartient à la formule.
                    $cell .= $char;
                } else {
                    $endCell();
                }
            } elseif ($char === "\n") {
                $endRow();
            } else {
                $cell .= $char;
            }
        }
        if ($cell !== '' || $quoted || $row !== []) {
            $endRow();
        }

        return $rows;
    }

    /**
     * La cellule en cours est-elle une formule dont un appel de fonction (ou
     * une chaîne) n'est pas refermé ?
     *
     * Les modèles écrivent `=SI(A1>0;"oui";"non")` sans guillemets autour,
     * dans un CSV dont le point-virgule est justement le séparateur : la
     * formule serait coupée en trois cellules, toutes fausses. Tant que ses
     * parenthèses ne sont pas refermées, le séparateur lui appartient. Borné à
     * la ligne : un saut de ligne termine toujours l'enregistrement.
     */
    private static function formulaIsOpen(string $cell): bool
    {
        $cell = ltrim($cell, " \t");
        if ($cell === '' || $cell[0] !== '=') {
            return false;
        }

        $depth    = 0;
        $inString = false;
        for ($i = 1, $n = strlen($cell); $i < $n; $i++) {
            $char = $cell[$i];
            if ($char === '"') {
                $inString = !$inString;
            } elseif (!$inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
            }
        }

        return $depth > 0 || $inString;
    }

    /**
     * Table Markdown (`| a | b |` + ligne de tirets) → lignes, ou null si le
     * texte n'en est pas une. La mise en forme de cellule (gras, code) est
     * retirée : dans un classeur, « **Total** » doit s'écrire « Total ».
     *
     * @return list<list<string>>|null
     */
    public static function parseMarkdownTable(string $text): ?array
    {
        $lines = array_values(array_filter(
            explode("\n", $text),
            static fn (string $line): bool => trim($line) !== ''
        ));
        if (count($lines) < 2 || !str_contains($lines[0], '|')) {
            return null;
        }
        if (preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[1]) !== 1
            || !str_contains($lines[1], '-')) {
            return null;
        }

        $rows = [self::splitMarkdownRow($lines[0])];
        for ($i = 2, $n = count($lines); $i < $n; $i++) {
            if (!str_contains($lines[$i], '|')) {
                break;
            }
            $rows[] = self::splitMarkdownRow($lines[$i]);
            if (count($rows) > self::MAX_ROWS) {
                throw FileBuildException::tableTooLarge(count($rows), count($rows[0]));
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private static function splitMarkdownRow(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|') && !str_ends_with($line, '\\|')) {
            $line = substr($line, 0, -1);
        }

        $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return array_map(static function (string $cell): string {
            $cell = trim(str_replace('\\|', '|', $cell));
            $cell = (string)preg_replace('#<br\s*/?>#i', "\n", $cell);
            // Emphase et code en ligne : on garde le texte, pas la syntaxe.
            $cell = (string)preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/u', '$2', $cell);
            $cell = (string)preg_replace('/(?<![\w*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\w*])/u', '$1', $cell);
            $cell = (string)preg_replace('/`([^`]+)`/u', '$1', $cell);

            return $cell;
        }, $cells);
    }

    // ------------------------------------------------------------------
    // Mise en forme
    // ------------------------------------------------------------------

    /**
     * Lignes ramenées au même nombre de cellules ; colonnes de FIN entièrement
     * vides retirées (un séparateur en bout de chaque ligne en fabrique une).
     *
     * @param list<list<string>> $rows
     * @return list<list<string>>
     * @throws FileBuildException
     */
    private static function rectangular(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, count($row));
        }

        // Retire les colonnes finales vides sur TOUTES les lignes.
        while ($width > 1) {
            $empty = true;
            foreach ($rows as $row) {
                if (isset($row[$width - 1]) && trim($row[$width - 1]) !== '') {
                    $empty = false;
                    break;
                }
            }
            if (!$empty) {
                break;
            }
            $width--;
        }

        if ($width > self::MAX_COLS || $width * count($rows) > self::MAX_CELLS) {
            throw FileBuildException::tableTooLarge(count($rows), $width);
        }

        $out = [];
        foreach ($rows as $row) {
            $row   = array_slice($row, 0, $width);
            $out[] = array_pad(array_map('strval', $row), $width, '');
        }

        return $out;
    }

    private static function quoteCsvCell(string $cell, string $delimiter): string
    {
        $mustQuote = $cell !== '' && (
            str_contains($cell, $delimiter)
            || str_contains($cell, '"')
            || str_contains($cell, "\n")
            || str_contains($cell, "\r")
            || $cell !== trim($cell)
        );

        return $mustQuote ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
    }

    /** Début du texte, borné : deviner le séparateur n'exige pas de tout lire. */
    private static function firstRecordsText(string $text): string
    {
        $offset = 0;
        $length = strlen($text);
        for ($i = 0; $i < self::SNIFF_RECORDS; $i++) {
            $next = strpos($text, "\n", $offset);
            if ($next === false) {
                return $text;
            }
            $offset = $next + 1;
            if ($offset >= $length) {
                return $text;
            }
        }

        return substr($text, 0, $offset);
    }
}
