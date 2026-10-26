<?php

declare(strict_types=1);

/**
 * Lecture des FICHIERS qu'une boucle parcourt — sans IA, sans réseau.
 *
 *  - sheet()    : les lignes d'un classeur (xlsx lu ici, sans bibliothèque ;
 *                 xls / ods par PhpSpreadsheet quand il est là ; csv, tsv,
 *                 txt par TabularData) — onglet au choix, ligne d'en-tête
 *                 détectée, dates Excel converties, lignes vides retirées ;
 *  - slides()   : le titre, le texte et les notes de chaque slide d'un pptx,
 *                 dans l'ordre de la présentation ;
 *  - pages()    : le texte de chaque page d'un PDF ;
 *  - sections() : un texte coupé à ses titres (Markdown, Word converti).
 *
 * Tout lève une \RuntimeException au message traduit, que la boucle affiche
 * telle quelle (« Le fichier n'est pas un classeur… »).
 */
final class LoopFiles
{
    /** Taille d'un fichier lu (octets). */
    public const MAX_BYTES = 25 * 1024 * 1024;
    /** Lignes examinées pour trouver l'en-tête. */
    private const HEADER_SCAN = 20;
    /** Texte d'une page, d'une slide ou d'une section (caractères). */
    public const PART_MAX = 20000;

    // ==================================================================
    // Classeurs
    // ==================================================================

    /** Le fichier se lit-il comme un classeur ? */
    public static function isSheet(string $mime, string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['xlsx', 'xlsm', 'xls', 'ods', 'csv', 'tsv', 'txt'], true)
            || str_contains($mime, 'spreadsheet') || str_contains($mime, 'ms-excel') || in_array($mime, ['text/csv', 'text/tab-separated-values'], true);
    }

    /**
     * Les lignes d'un classeur. `$sheet` : nom de l'onglet ('' = le premier) ;
     * `$header` : numéro de la ligne d'en-tête (0 = détectée).
     *
     * @return array{sheets: list<string>, sheet: string, header: list<string>, rows: list<list<string>>, total: int, header_row: int}
     */
    public static function sheet(string $path, string $mime, string $name, string $sheet = '', int $header = 0): array
    {
        self::assertReadable($path, $name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'tsv', 'txt'], true) || in_array($mime, ['text/csv', 'text/plain', 'text/tab-separated-values'], true)) {
            try {
                $grid = TabularData::parse((string)file_get_contents($path))->rows();
            } catch (\Throwable) {
                throw new \RuntimeException(t('« {nom} » est trop grand pour être lu comme un tableau.', ['nom' => $name]));
            }
            return self::grid([$name], $name, $grid, $header);
        }
        if ($ext === 'xlsx' || $ext === 'xlsm' || str_contains($mime, 'spreadsheetml')) {
            [$sheets, $chosen, $grid] = self::readXlsx($path, $name, $sheet);
            return self::grid($sheets, $chosen, $grid, $header);
        }
        if (in_array($ext, ['xls', 'ods'], true) || str_contains($mime, 'ms-excel') || str_contains($mime, 'opendocument.spreadsheet')) {
            [$sheets, $chosen, $grid] = self::readWithLibrary($path, $name, $sheet);
            return self::grid($sheets, $chosen, $grid, $header);
        }
        throw new \RuntimeException(t('« {nom} » n’est pas un classeur : joignez un fichier Excel (.xlsx) ou CSV.', ['nom' => $name]));
    }

    /**
     * Grille brute → en-tête et lignes. Lignes entièrement vides retirées,
     * colonnes vides de fin retirées, en-tête sans nom → « Colonne N ».
     */
    public static function grid(array $sheets, string $chosen, array $grid, int $header): array
    {
        $grid = array_values(array_map(static fn($r): array => array_map(static fn($c): string => self::cell($c), array_values((array)$r)), $grid));
        $nonEmpty = static fn(array $r): int => count(array_filter($r, static fn(string $c): bool => $c !== ''));
        $at = -1;
        if ($header > 0) {
            $at = min($header - 1, max(0, count($grid) - 1));
        } else {
            $width = 0;
            foreach (array_slice($grid, 0, self::HEADER_SCAN) as $r) {
                $width = max($width, $nonEmpty($r));
            }
            foreach (array_slice($grid, 0, self::HEADER_SCAN, true) as $i => $r) {
                // L'en-tête : la première ligne aussi remplie que le tableau le
                // plus large (un titre seul au-dessus n'en est pas un).
                if ($nonEmpty($r) > 0 && ($nonEmpty($r) >= min($width, 2) || $width <= 1)) {
                    $at = (int)$i;
                    break;
                }
            }
        }
        if ($at < 0 || $grid === []) {
            return ['sheets' => $sheets, 'sheet' => $chosen, 'header' => [], 'rows' => [], 'total' => 0, 'header_row' => 0];
        }
        $head = $grid[$at];
        $rows = [];
        foreach (array_slice($grid, $at + 1) as $r) {
            if ($nonEmpty($r) > 0) {
                $rows[] = $r;
            }
        }
        $cols = 0;
        foreach (array_merge([$head], $rows) as $r) {
            for ($c = count($r) - 1; $c >= 0; $c--) {
                if ($r[$c] !== '') {
                    $cols = max($cols, $c + 1);
                    break;
                }
            }
        }
        $cols = min($cols, TabularData::MAX_COLS);
        $labels = [];
        $seen = [];
        for ($c = 0; $c < $cols; $c++) {
            $l = trim((string)($head[$c] ?? ''));
            $l = $l !== '' ? mb_substr($l, 0, WorkflowSpec::LABEL_MAX) : t('Colonne {n}', ['n' => $c + 1]);
            $base = $l;
            $i = 2;
            while (isset($seen[mb_strtolower($l)])) {
                $l = $base . ' ' . $i++;
            }
            $seen[mb_strtolower($l)] = true;
            $labels[] = $l;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = array_map(static fn(int $c): string => (string)($r[$c] ?? ''), range(0, max(0, $cols - 1)));
        }
        return ['sheets' => $sheets, 'sheet' => $chosen, 'header' => $labels, 'rows' => $out, 'total' => count($out), 'header_row' => $at + 1];
    }

    private static function cell(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'oui' : 'non';
        }
        $s = is_scalar($v) ? (string)$v : '';
        $s = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $s);
        return trim(mb_substr($s, 0, 2000));
    }

    /**
     * Un xlsx, lu dans son archive : onglets (dans l'ordre du classeur),
     * chaînes partagées, cellules placées par leur référence (une cellule
     * vide ne décale pas les suivantes), valeur en cache des formules, dates
     * reconnues par leur format.
     *
     * @return array{0: list<string>, 1: string, 2: list<list<string>>}
     */
    public static function readXlsx(string $path, string $name, string $wanted = ''): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException(t('« {nom} » n’a pas pu être ouvert : le fichier est peut-être abîmé.', ['nom' => $name]));
        }
        try {
            $book = self::xml($zip->getFromName('xl/workbook.xml'));
            if ($book === null) {
                throw new \RuntimeException(t('« {nom} » n’est pas un classeur Excel lisible.', ['nom' => $name]));
            }
            $targets = self::rels($zip, 'xl/_rels/workbook.xml.rels', 'xl/');
            $sheets = [];
            $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($book->xpath('//m:sheets/m:sheet') ?: [] as $s) {
                $rid = (string)($s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                $sheets[] = ['name' => (string)$s['name'], 'path' => $targets[$rid] ?? ''];
            }
            if ($sheets === []) {
                throw new \RuntimeException(t('« {nom} » ne contient aucun onglet.', ['nom' => $name]));
            }
            $chosen = $sheets[0];
            if (trim($wanted) !== '') {
                $found = null;
                foreach ($sheets as $s) {
                    if (mb_strtolower(trim($s['name'])) === mb_strtolower(trim($wanted))) {
                        $found = $s;
                    }
                }
                if ($found === null) {
                    throw new \RuntimeException(t('L’onglet « {onglet} » n’existe pas dans « {nom} ». Onglets : {liste}.', [
                        'onglet' => $wanted, 'nom' => $name, 'liste' => implode(', ', array_column($sheets, 'name')),
                    ]));
                }
                $chosen = $found;
            }

            $shared = [];
            $ss = self::xml($zip->getFromName('xl/sharedStrings.xml'));
            foreach ($ss?->si ?? [] as $si) {
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                    $text .= (string)$t;
                }
                $shared[] = $text;
            }
            $dateStyles = self::dateStyles(self::xml($zip->getFromName('xl/styles.xml')));
            $date1904 = (string)($book->workbookPr['date1904'] ?? '') === '1' || (string)($book->workbookPr['date1904'] ?? '') === 'true';

            $raw = $chosen['path'] !== '' ? $zip->getFromName($chosen['path']) : false;
            if ($raw === false) {
                throw new \RuntimeException(t('L’onglet « {onglet} » de « {nom} » est illisible.', ['onglet' => $chosen['name'], 'nom' => $name]));
            }
            $sheetXml = self::xml($raw);
            $grid = [];
            $n = 0;
            foreach ($sheetXml?->sheetData->row ?? [] as $row) {
                if ($n++ > LoopSources::MAX_ROWS_READ + self::HEADER_SCAN) {
                    break;
                }
                $cells = [];
                $next = 0;
                foreach ($row->c ?? [] as $c) {
                    $ref = (string)$c['r'];
                    $col = $ref !== '' ? self::columnIndex($ref) : $next;
                    $next = $col + 1;
                    if ($col >= TabularData::MAX_COLS) {
                        continue;
                    }
                    $type = (string)$c['t'];
                    $style = (int)($c['s'] ?? 0);
                    $value = '';
                    if ($type === 'inlineStr') {
                        foreach ($c->is->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                            $value .= (string)$t;
                        }
                    } elseif (isset($c->v)) {
                        $v = (string)$c->v;
                        $value = match ($type) {
                            's'       => $shared[(int)$v] ?? '',
                            'b'       => $v === '1' ? 'oui' : 'non',
                            'str', 'e' => $v,
                            default   => isset($dateStyles[$style]) && is_numeric($v) ? self::excelDate((float)$v, $date1904) : self::number($v),
                        };
                    }
                    $cells[$col] = $value;
                }
                if ($cells === []) {
                    $grid[] = [];
                    continue;
                }
                $line = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) {
                    $line[$i] = $v;
                }
                $grid[] = $line;
            }
            return [array_column($sheets, 'name'), $chosen['name'], $grid];
        } finally {
            $zip->close();
        }
    }

    /** Index (0) de la colonne d'une référence « AB12 ». */
    public static function columnIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/i', $ref, $m);
        $letters = strtoupper($m[0] ?? 'A');
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    /** Numéro de série Excel → « AAAA-MM-JJ » (heure si elle n'est pas minuit). */
    public static function excelDate(float $serial, bool $date1904 = false): string
    {
        if ($serial < 1) {
            // Une heure seule.
            $sec = (int)round($serial * 86400);
            return sprintf('%02d:%02d', intdiv($sec, 3600) % 24, intdiv($sec % 3600, 60));
        }
        $days = (int)floor($serial);
        $base = $date1904 ? new \DateTimeImmutable('1904-01-01', new \DateTimeZone('UTC')) : new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC'));
        $d = $base->modify('+' . $days . ' days');
        $sec = (int)round(($serial - $days) * 86400);
        if ($sec > 0 && $sec < 86400) {
            return $d->modify('+' . $sec . ' seconds')->format('Y-m-d H:i');
        }
        return $d->format('Y-m-d');
    }

    /** Un nombre tel qu'Excel l'a stocké, sans les artefacts binaires (0.1+0.2). */
    private static function number(string $v): string
    {
        if (!is_numeric($v)) {
            return $v;
        }
        $f = (float)$v;
        if (floor($f) === $f && abs($f) < 1e15) {
            return (string)(int)$f;
        }
        return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    }

    /** Styles de cellule (index) dont le format est une date. */
    private static function dateStyles(?\SimpleXMLElement $styles): array
    {
        if ($styles === null) {
            return [];
        }
        $custom = [];
        foreach ($styles->numFmts->numFmt ?? [] as $f) {
            $custom[(int)$f['numFmtId']] = (string)$f['formatCode'];
        }
        $out = [];
        $i = 0;
        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int)$xf['numFmtId'];
            $isDate = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || ($id >= 27 && $id <= 36) || ($id >= 50 && $id <= 58);
            if (isset($custom[$id])) {
                $code = (string)preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $custom[$id]);
                $isDate = (bool)preg_match('/[dmy]/i', $code) && !preg_match('/^[#0.,% ]+$/', $code);
            }
            if ($isDate) {
                $out[$i] = true;
            }
            $i++;
        }
        return $out;
    }

    /** xls, ods : par PhpSpreadsheet (valeurs affichées), quand il est installé. */
    private static function readWithLibrary(string $path, string $name, string $wanted): array
    {
        if (!FileLibrary::has(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException(t('« {nom} » : ce format de classeur n’est pas lu ici. Enregistrez-le en .xlsx ou .csv.', ['nom' => $name]));
        }
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            $book = $reader->load($path);
            $names = $book->getSheetNames();
            $sheet = $book->getSheet(0);
            if (trim($wanted) !== '') {
                $found = null;
                foreach ($names as $i => $n) {
                    if (mb_strtolower(trim($n)) === mb_strtolower(trim($wanted))) {
                        $found = $book->getSheet($i);
                    }
                }
                if ($found === null) {
                    throw new \RuntimeException(t('L’onglet « {onglet} » n’existe pas dans « {nom} ». Onglets : {liste}.', ['onglet' => $wanted, 'nom' => $name, 'liste' => implode(', ', $names)]));
                }
                $sheet = $found;
            }
            $grid = [];
            foreach ($sheet->getRowIterator(1, LoopSources::MAX_ROWS_READ + self::HEADER_SCAN) as $row) {
                $line = [];
                foreach ($row->getCellIterator() as $cell) {
                    $line[] = (string)$cell->getFormattedValue();
                }
                $grid[] = $line;
            }
            return [$names, $sheet->getTitle(), $grid];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[Workflows] classeur « ' . $name . ' » : ' . $e->getMessage());
            throw new \RuntimeException(t('« {nom} » n’a pas pu être ouvert : le fichier est peut-être abîmé.', ['nom' => $name]));
        }
    }

    // ==================================================================
    // Présentations
    // ==================================================================

    /** Le fichier est-il une présentation PowerPoint ? */
    public static function isSlides(string $mime, string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext === 'pptx' || str_contains($mime, 'presentationml');
    }

    /**
     * Les slides d'un pptx, dans l'ordre de la présentation (pas celui des
     * fichiers de l'archive) : titre (l'espace réservé titre), texte (les
     * autres paragraphes), notes du présentateur.
     *
     * @return list<array{numero: int, titre: string, texte: string, notes: string}>
     */
    public static function slides(string $path, string $name): array
    {
        self::assertReadable($path, $name);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException(t('« {nom} » n’a pas pu être ouvert : le fichier est peut-être abîmé.', ['nom' => $name]));
        }
        try {
            $pres = self::xml($zip->getFromName('ppt/presentation.xml'));
            $rels = self::rels($zip, 'ppt/_rels/presentation.xml.rels', 'ppt/');
            if ($pres === null) {
                throw new \RuntimeException(t('« {nom} » n’est pas une présentation PowerPoint (.pptx) lisible.', ['nom' => $name]));
            }
            $order = [];
            $pres->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            foreach ($pres->xpath('//p:sldIdLst/p:sldId') ?: [] as $s) {
                $rid = (string)($s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                if (isset($rels[$rid])) {
                    $order[] = $rels[$rid];
                }
            }
            $out = [];
            foreach ($order as $i => $slidePath) {
                $xml = $zip->getFromName($slidePath);
                if ($xml === false) {
                    continue;
                }
                [$title, $text] = self::slideText($xml);
                $notes = '';
                $sRels = self::rels($zip, dirname($slidePath) . '/_rels/' . basename($slidePath) . '.rels', dirname($slidePath) . '/');
                foreach ($sRels as $target) {
                    if (str_contains($target, 'notesSlide')) {
                        $nx = $zip->getFromName($target);
                        if ($nx !== false) {
                            $notes = self::notesText($nx);
                        }
                    }
                }
                $out[] = [
                    'numero' => $i + 1,
                    'titre'  => mb_substr($title, 0, 300),
                    'texte'  => mb_substr($text, 0, self::PART_MAX),
                    'notes'  => mb_substr($notes, 0, self::PART_MAX),
                ];
            }
            return $out;
        } finally {
            $zip->close();
        }
    }

    /** Titre et texte d'une slide (XML) : le titre vient de l'espace réservé titre. */
    public static function slideText(string $xml): array
    {
        $doc = self::xml($xml);
        if ($doc === null) {
            return ['', ''];
        }
        $doc->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $doc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $title = '';
        $lines = [];
        foreach ($doc->xpath('//p:sp') ?: [] as $sp) {
            $sp->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $sp->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $ph = $sp->xpath('.//p:nvPr/p:ph');
            $phType = $ph ? (string)($ph[0]['type'] ?? '') : '';
            $paras = [];
            foreach ($sp->xpath('.//a:p') ?: [] as $p) {
                $p->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $t = '';
                foreach ($p->xpath('.//a:t') ?: [] as $r) {
                    $t .= (string)$r;
                }
                $t = trim($t);
                if ($t !== '') {
                    $paras[] = $t;
                }
            }
            if (in_array($phType, ['title', 'ctrTitle'], true) && $title === '') {
                $title = implode(' ', $paras);
                continue;
            }
            if (in_array($phType, ['sldNum', 'dt', 'ftr'], true)) {
                continue;
            }
            foreach ($paras as $p) {
                $lines[] = $p;
            }
        }
        // Tableaux : une ligne par rangée.
        foreach ($doc->xpath('//a:tbl/a:tr') ?: [] as $tr) {
            $tr->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $cells = [];
            foreach ($tr->xpath('./a:tc') ?: [] as $tc) {
                $tc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $cells[] = trim(implode(' ', array_map('strval', $tc->xpath('.//a:t') ?: [])));
            }
            $lines[] = implode(' | ', $cells);
        }
        if ($title === '' && $lines !== []) {
            $title = mb_substr($lines[0], 0, 120);
        }
        return [$title, implode("\n", $lines)];
    }

    private static function notesText(string $xml): string
    {
        $doc = self::xml($xml);
        if ($doc === null) {
            return '';
        }
        $doc->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $doc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $lines = [];
        foreach ($doc->xpath('//p:sp') ?: [] as $sp) {
            $sp->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $sp->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $ph = $sp->xpath('.//p:nvPr/p:ph');
            if ($ph && in_array((string)($ph[0]['type'] ?? ''), ['sldImg', 'sldNum', 'hdr', 'ftr', 'dt'], true)) {
                continue;
            }
            foreach ($sp->xpath('.//a:p') ?: [] as $p) {
                $p->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $t = trim(implode('', array_map('strval', $p->xpath('.//a:t') ?: [])));
                if ($t !== '') {
                    $lines[] = $t;
                }
            }
        }
        return implode("\n", $lines);
    }

    /** Slides d'une présentation du dossier (Markdown : « # » couverture, « ## » une slide). */
    public static function slidesFromMarkdown(string $markdown): array
    {
        $out = [];
        foreach (self::sectionsOf($markdown) as $i => $s) {
            $out[] = ['numero' => $i + 1, 'titre' => $s['titre'], 'texte' => $s['contenu'], 'notes' => ''];
        }
        return $out;
    }

    // ==================================================================
    // PDF
    // ==================================================================

    /** Le fichier est-il un PDF ? */
    public static function isPdf(string $mime, string $name): bool
    {
        return $mime === 'application/pdf' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * Le texte de chaque page d'un PDF : smalot/pdfparser, sinon pdftotext
     * (pages séparées par un saut de page), sinon une seule « page ».
     *
     * @return list<array{numero: int, texte: string}>
     */
    public static function pages(string $path, string $name): array
    {
        self::assertReadable($path, $name);
        $texts = [];
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $pdf = (new \Smalot\PdfParser\Parser())->parseFile($path);
                foreach ($pdf->getPages() as $page) {
                    $texts[] = (string)$page->getText();
                }
            } catch (\Throwable $e) {
                error_log('[Workflows] PDF « ' . $name . ' » (pdfparser) : ' . $e->getMessage());
                $texts = [];
            }
        }
        if ($texts === [] && function_exists('proc_open')) {
            $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
            if ($bin !== '') {
                $out = (string)@shell_exec(escapeshellarg($bin) . ' -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
                if (trim($out) !== '') {
                    $texts = explode("\f", rtrim($out, "\f"));
                }
            }
        }
        if ($texts === []) {
            $all = (string)(FileExtractor::extract($path, 'application/pdf') ?? '');
            if (trim($all) === '') {
                throw new \RuntimeException(t('Aucun texte lisible dans « {nom} » : un PDF scanné ne se lit pas page par page.', ['nom' => $name]));
            }
            $texts = [$all];
        }
        $out = [];
        foreach ($texts as $i => $t) {
            $t = trim((string)preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $t)));
            $out[] = ['numero' => $i + 1, 'texte' => mb_substr($t, 0, self::PART_MAX)];
        }
        return $out;
    }

    // ==================================================================
    // Sections
    // ==================================================================

    /** Le fichier se lit-il comme un document à sections (Word, Markdown, texte) ? */
    public static function isDocument(string $mime, string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['docx', 'md', 'markdown', 'txt', 'odt', 'rtf', 'html', 'htm'], true)
            || str_contains($mime, 'wordprocessingml') || in_array($mime, ['text/markdown', 'text/plain', 'text/html'], true);
    }

    /** Texte à sections d'un fichier : Word converti en Markdown (titres gardés), le reste extrait. */
    public static function documentMarkdown(string $path, string $mime, string $name): string
    {
        self::assertReadable($path, $name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'docx' || str_contains($mime, 'wordprocessingml')) {
            $md = self::docxMarkdown($path);
            if ($md !== null) {
                return $md;
            }
        }
        if (in_array($ext, ['md', 'markdown', 'txt'], true) || in_array($mime, ['text/markdown', 'text/plain'], true)) {
            return (string)file_get_contents($path);
        }
        $text = (string)(FileExtractor::extract($path, $mime) ?? '');
        if (trim($text) === '') {
            throw new \RuntimeException(t('Aucun texte lisible dans « {nom} ».', ['nom' => $name]));
        }
        return $text;
    }

    /**
     * Un .docx en Markdown : les paragraphes de style Titre 1, 2, 3 (Heading,
     * Titre, Title) deviennent « # », « ## », « ### » ; les autres restent
     * des paragraphes. Null si le fichier ne se lit pas.
     */
    public static function docxMarkdown(string $path): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
            $styles = self::xml($zip->getFromName('word/styles.xml'));
        } finally {
            $zip->close();
        }
        $doc = $xml !== false ? self::xml($xml) : null;
        if ($doc === null) {
            return null;
        }
        // Style → niveau de titre : par son nom (« heading 1 », « Titre 1 », « Title »).
        $levels = [];
        foreach ($styles?->xpath('//*[local-name()="style"]') ?: [] as $st) {
            $id = (string)($st->attributes('http://schemas.openxmlformats.org/wordprocessingml/2006/main')['styleId'] ?? '');
            $nameNode = $st->xpath('./*[local-name()="name"]');
            $sname = $nameNode ? mb_strtolower((string)($nameNode[0]->attributes('http://schemas.openxmlformats.org/wordprocessingml/2006/main')['val'] ?? '')) : '';
            if (preg_match('/^(?:heading|titre)\s*([1-6])$/u', $sname, $m)) {
                $levels[$id] = (int)$m[1];
            } elseif (in_array($sname, ['title', 'titre'], true)) {
                $levels[$id] = 1;
            }
        }
        $out = [];
        foreach ($doc->xpath('//*[local-name()="body"]/*[local-name()="p"]') ?: [] as $p) {
            $style = $p->xpath('./*[local-name()="pPr"]/*[local-name()="pStyle"]');
            $sid = $style ? (string)($style[0]->attributes('http://schemas.openxmlformats.org/wordprocessingml/2006/main')['val'] ?? '') : '';
            $text = trim(implode('', array_map('strval', $p->xpath('.//*[local-name()="t"]') ?: [])));
            if ($text === '') {
                continue;
            }
            $level = $levels[$sid] ?? (preg_match('/^(?:Heading|Titre)([1-6])$/i', $sid, $m) ? (int)$m[1] : 0);
            $out[] = $level > 0 ? str_repeat('#', $level) . ' ' . $text : $text;
        }
        return implode("\n\n", $out);
    }

    /**
     * Un texte coupé à ses titres Markdown. Le niveau retenu est le plus haut
     * présent (« # » s'il y en a, sinon « ## »…) ; un titre de niveau
     * inférieur reste dans sa section. Le texte avant le premier titre forme
     * une section « Introduction » ; sans aucun titre, le texte entier est une
     * seule section.
     *
     * @return list<array{titre: string, contenu: string}>
     */
    public static function sectionsOf(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $level = 7;
        $inFence = false;
        foreach ($lines as $l) {
            if (preg_match('/^\s*(```|~~~)/', $l)) {
                $inFence = !$inFence;
                continue;
            }
            if (!$inFence && preg_match('/^(#{1,6})\s+\S/', $l, $m)) {
                $level = min($level, strlen($m[1]));
            }
        }
        $trimmed = trim($text);
        if ($level === 7) {
            return $trimmed !== '' ? [['titre' => mb_substr(self::firstLine($trimmed), 0, 120), 'contenu' => mb_substr($trimmed, 0, self::PART_MAX)]] : [];
        }
        $out = [];
        $title = null;
        $buf = [];
        $inFence = false;
        $flush = static function () use (&$out, &$title, &$buf): void {
            $body = trim(implode("\n", $buf));
            if ($title === null && $body === '') {
                return;
            }
            $out[] = ['titre' => $title ?? t('Introduction'), 'contenu' => mb_substr($body, 0, self::PART_MAX)];
        };
        foreach ($lines as $l) {
            if (preg_match('/^\s*(```|~~~)/', $l)) {
                $inFence = !$inFence;
            }
            if (!$inFence && preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $l, $m) && strlen($m[1]) === $level) {
                $flush();
                $title = mb_substr(trim($m[2]), 0, 300);
                $buf = [];
                continue;
            }
            $buf[] = $l;
        }
        $flush();
        return $out;
    }

    /** Les éléments d'un texte, une ligne = un élément (puces et numéros retirés). */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $l) {
            $l = trim((string)preg_replace('/^\s*(?:[-*•–]|\d{1,3}[.)])\s+/u', '', $l));
            if ($l !== '' && !preg_match('/^#{1,6}\s/', $l)) {
                $out[] = $l;
            }
        }
        return $out;
    }

    // ==================================================================
    // Outils
    // ==================================================================

    private static function firstLine(string $s): string
    {
        return trim((string)strtok($s, "\n"));
    }

    private static function assertReadable(string $path, string $name): void
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(t('Le fichier « {nom} » n’est plus disponible.', ['nom' => $name]));
        }
        if ((int)filesize($path) > self::MAX_BYTES) {
            throw new \RuntimeException(t('« {nom} » est trop lourd pour être parcouru (25 Mo au plus).', ['nom' => $name]));
        }
    }

    private static function xml(string|false|null $xml): ?\SimpleXMLElement
    {
        if (!is_string($xml) || $xml === '') {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            return $doc === false ? null : $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** Relations d'une partie : id → chemin dans l'archive. */
    private static function rels(\ZipArchive $zip, string $relsPath, string $baseDir): array
    {
        $doc = self::xml($zip->getFromName($relsPath));
        $out = [];
        foreach ($doc?->Relationship ?? [] as $rel) {
            $t = (string)$rel['Target'];
            if ((string)($rel['TargetMode'] ?? '') === 'External') {
                continue;
            }
            $path = str_starts_with($t, '/') ? ltrim($t, '/') : $baseDir . $t;
            // Résout « ../ ».
            $parts = [];
            foreach (explode('/', $path) as $seg) {
                if ($seg === '..') {
                    array_pop($parts);
                } elseif ($seg !== '.' && $seg !== '') {
                    $parts[] = $seg;
                }
            }
            $out[(string)$rel['Id']] = implode('/', $parts);
        }
        return $out;
    }
}
