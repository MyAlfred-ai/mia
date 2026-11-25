<?php

declare(strict_types=1);

/**
 * Anciens formats Office binaires — Word 97-2003 (.doc), Excel 97-2003
 * (.xls, Excel 95 compris) et PowerPoint 97-2003 (.ppt) — lus en PHP pur,
 * sans bibliothèque ni outil externe. Source unique de leur détection (kind)
 * et de leur texte (docText, xlsText, pptText), appelée par FileExtractor.
 * Le conteneur sert aussi aux emails Outlook (.msg, cf. MailFile).
 *
 * Pourquoi pas PhpWord ? Son lecteur MsDoc suppose tout le texte en UTF-16 et
 * ignore la table des morceaux (« piece table ») : un .doc ordinaire, dont le
 * texte latin est stocké sur un octet, en sortait vide ou en caractères
 * chinois. PhpSpreadsheet lit bien les .xls, mais n'est pas toujours là (le
 * vendor/ versionné est partiel, cf. FileLibrary) ; ici rien ne manque jamais.
 *
 * Deux couches :
 *  - le conteneur (Compound File Binary, MS-CFB) : secteurs, FAT, mini-flux,
 *    répertoire — les flux de la racine, et à part ceux de ses dossiers ;
 *  - le contenu : table des morceaux de Word (MS-DOC) ; enregistrements BIFF
 *    d'Excel (MS-XLS), chaînes partagées coupées par les CONTINUE comprises ;
 *    enregistrements de PowerPoint (MS-PPT), diapositives dans l'ordre de la
 *    présentation par le répertoire de persistance.
 *
 * Tout est défensif : un fichier abîmé, chiffré ou malveillant rend null,
 * jamais une exception, jamais une boucle sans fin (chaînes de secteurs
 * bornées et visitées une fois).
 */
final class LegacyOffice
{
    public const MIME_DOC = 'application/msword';
    public const MIME_XLS = 'application/vnd.ms-excel';
    public const MIME_PPT = 'application/vnd.ms-powerpoint';

    /** Au-delà, le fichier n'est pas ouvert (le plus grand envoi accepté : 50 Mo). */
    public const MAX_BYTES = 64 * 1024 * 1024;

    private const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const END_OF_CHAIN = 0xFFFFFFFE;
    /** Au-dessus : marqueurs (secteur libre, de FAT, de DIFAT…), jamais un numéro. */
    private const MAX_REGULAR = 0xFFFFFFFA;
    private const NO_STREAM = 0xFFFFFFFF;

    // ==================================================================
    // Détection
    // ==================================================================

    /**
     * 'doc' | 'xls' | null, d'après les flux du conteneur — jamais d'après
     * l'extension : un .doc renommé .xls se lit pour ce qu'il est.
     */
    public static function kind(string $path): ?string
    {
        $head = @file_get_contents($path, false, null, 0, 8);
        if (!is_string($head) || $head !== self::SIGNATURE) {
            return null;
        }
        $cfb = self::openFile($path);
        if ($cfb === null) {
            return null;
        }
        if (isset($cfb['streams']['worddocument'])) {
            return 'doc';
        }
        if (isset($cfb['streams']['workbook']) || isset($cfb['streams']['book'])) {
            return 'xls';
        }
        if (isset($cfb['streams']['powerpoint document'])) {
            return 'ppt';
        }
        if (isset($cfb['streams']['__properties_version1.0'])) {
            return 'msg';
        }
        return null;
    }

    // ==================================================================
    // Word 97-2003
    // ==================================================================

    /**
     * Texte d'un .doc : document principal, puis notes de bas de page, notes
     * de fin et zones de texte (en-têtes, pieds et commentaires écartés).
     * Champs réduits à leur résultat (le texte d'un lien, pas son code),
     * cellules de tableau séparées par « | », une ligne par rangée. Null :
     * pas un document Word lisible (chiffré, Word 95 ou antérieur, abîmé).
     */
    public static function docText(string $path): ?string
    {
        $cfb = self::openFile($path);
        if ($cfb === null) {
            return null;
        }
        $wd = self::stream($cfb, 'WordDocument');
        if ($wd === null || strlen($wd) < 0x22 || self::u16($wd, 0) !== 0xA5EC) {
            return null;
        }
        $nFib  = self::u16($wd, 2);
        $flags = self::u16($wd, 0x0A);
        // Word 95 et antérieurs : autre structure, non lue. Chiffré : illisible.
        if ($nFib < 101 || ($flags & 0x0100) !== 0) {
            return null;
        }
        $table = self::stream($cfb, ($flags & 0x0200) !== 0 ? '1Table' : '0Table');
        if ($table === null) {
            return null;
        }

        // FIB de longueur variable : on suit ses compteurs plutôt que des
        // positions fixes (les versions récentes l'allongent).
        $csw  = self::u16($wd, 32);
        $pos  = 34 + $csw * 2;
        if (strlen($wd) < $pos + 2) {
            return null;
        }
        $cslw = self::u16($wd, $pos);
        $lw   = $pos + 2;
        if ($cslw < 11 || strlen($wd) < $lw + $cslw * 4 + 2) {
            return null;
        }
        $ccp = static fn(int $i): int => self::u32($wd, $lw + $i * 4);
        [$text, $ftn, $hdd, $atn, $edn, $txbx] = [$ccp(3), $ccp(4), $ccp(5), $ccp(7), $ccp(8), $ccp(9)];

        $pos = $lw + $cslw * 4;
        $cbRgFcLcb = self::u16($wd, $pos);
        $blob = $pos + 2;
        if ($cbRgFcLcb < 68 || strlen($wd) < $blob + 68 * 4) {
            return null;
        }
        $fcClx  = self::u32($wd, $blob + 66 * 4);
        $lcbClx = self::u32($wd, $blob + 67 * 4);
        $pieces = self::pieces(substr($table, $fcClx, $lcbClx));
        if ($pieces === null) {
            return null;
        }

        // Ordre des textes dans l'espace des positions : principal, notes de
        // bas de page, en-têtes, commentaires, notes de fin, zones de texte.
        $ranges = [[0, $text]];
        $at = $text;
        $ranges[] = [$at, $at + $ftn];
        $at += $ftn + $hdd + $atn;
        $ranges[] = [$at, $at + $edn];
        $at += $edn;
        $ranges[] = [$at, $at + $txbx];

        $parts = [];
        foreach ($ranges as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }
            $raw = self::wordRange($wd, $pieces, $from, $to);
            $part = trim(self::wordToText($raw));
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return $parts !== [] ? implode("\n\n", $parts) : null;
    }

    /**
     * Table des morceaux (Clx) : la liste des passages du texte, chacun sur
     * un octet (Windows-1252) ou deux (UTF-16). Un document enregistré
     * « rapidement » ou retouché en a beaucoup, dans le désordre du fichier.
     *
     * @return list<array{cp:int, end:int, fc:int, compressed:bool}>|null
     */
    public static function pieces(string $clx): ?array
    {
        $i = 0;
        $len = strlen($clx);
        while ($i < $len && $clx[$i] === "\x01") {
            // Prc : propriétés de mise en forme, sautées.
            if ($i + 3 > $len) {
                return null;
            }
            $cb = self::u16($clx, $i + 1);
            $i += 3 + $cb;
        }
        if ($i + 5 > $len || $clx[$i] !== "\x02") {
            return null;
        }
        $lcb = self::u32($clx, $i + 1);
        $plc = substr($clx, $i + 5, $lcb);
        if (strlen($plc) < $lcb || $lcb < 16 || ($lcb - 4) % 12 !== 0) {
            return null;
        }
        $n = intdiv($lcb - 4, 12);
        $out = [];
        for ($k = 0; $k < $n; $k++) {
            $cp  = self::u32($plc, $k * 4);
            $end = self::u32($plc, ($k + 1) * 4);
            $raw = self::u32($plc, ($n + 1) * 4 + $k * 8 + 2);
            if ($end <= $cp) {
                continue;
            }
            $compressed = ($raw & 0x40000000) !== 0;
            $fc = $raw & 0x3FFFFFFF;
            $out[] = ['cp' => $cp, 'end' => $end, 'fc' => $compressed ? intdiv($fc, 2) : $fc, 'compressed' => $compressed];
        }
        return $out;
    }

    /** Caractères [from, to) du document, bruts (marques de Word comprises), en UTF-8. */
    public static function wordRange(string $wd, array $pieces, int $from, int $to): string
    {
        $out = '';
        foreach ($pieces as $p) {
            $a = max($from, $p['cp']);
            $b = min($to, $p['end']);
            if ($b <= $a) {
                continue;
            }
            $k = $a - $p['cp'];
            if ($p['compressed']) {
                $bytes = substr($wd, $p['fc'] + $k, $b - $a);
                $out .= self::convert($bytes, 'Windows-1252');
            } else {
                $bytes = substr($wd, $p['fc'] + 2 * $k, 2 * ($b - $a));
                if (strlen($bytes) % 2 === 1) {
                    $bytes = substr($bytes, 0, -1);
                }
                $out .= self::convert($bytes, 'UTF-16LE');
            }
        }
        return $out;
    }

    /**
     * Marques de Word → texte lisible. Champs : le code (entre 0x13 et 0x14)
     * disparaît, le résultat (entre 0x14 et 0x15) reste, imbrications
     * comprises. Tableaux : 0x07 ferme une cellule ; deux 0x07 de suite
     * ferment la rangée (dernière cellule, puis marque de rangée). Une
     * cellule VIDE en milieu de rangée produit aussi deux 0x07 : la rangée
     * se coupe alors en deux lignes — la marque de rangée ne se distingue
     * qu'avec les propriétés de paragraphe, que l'on ne lit pas.
     */
    public static function wordToText(string $raw): string
    {
        // Champs (octets uniques en UTF-8 : un parcours par octet est sûr).
        $out = '';
        $stack = [];
        $hidden = 0;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            if ($c === "\x13") {
                $stack[] = 'code';
                $hidden++;
                continue;
            }
            if ($c === "\x14") {
                if ($stack !== [] && end($stack) === 'code') {
                    $stack[count($stack) - 1] = 'result';
                    $hidden--;
                }
                continue;
            }
            if ($c === "\x15") {
                if ($stack !== [] && array_pop($stack) === 'code') {
                    $hidden--;
                }
                continue;
            }
            if ($hidden === 0) {
                $out .= $c;
            }
        }

        $out = preg_replace_callback('/\x07+/', static function (array $m): string {
            $n = strlen($m[0]);
            return $n === 1 ? ' | ' : str_repeat(' | ', $n - 2) . "\n";
        }, $out) ?? $out;
        $out = strtr($out, [
            "\r"   => "\n",   // fin de paragraphe
            "\x0B" => "\n",   // saut de ligne
            "\x0C" => "\n\n", // saut de page ou de section
            "\x0E" => "\n",   // saut de colonne
            "\x1E" => '-',    // trait d'union insécable
            "\x1F" => '',     // trait d'union conditionnel
        ]);
        // Objets, images, renvois de notes : rien à lire.
        $out = preg_replace('/[\x00-\x08\x0F-\x1D]/', '', $out) ?? $out;
        $out = preg_replace('/ \| *\n/', "\n", $out) ?? $out;
        return preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;
    }

    // ==================================================================
    // Excel 97-2003 (et 95)
    // ==================================================================

    /**
     * Texte d'un .xls : chaque feuille de calcul sous « # Nom », une ligne
     * par rangée, cellules séparées par des tabulations (même forme que la
     * lecture des .xlsx). Dates rendues en AAAA-MM-JJ d'après leur format,
     * pourcentages en %, booléens en TRUE/FALSE. Null : pas un classeur
     * lisible (chiffré, abîmé) ou sans aucune valeur.
     */
    public static function xlsText(string $path): ?string
    {
        $book = self::workbook($path);
        if ($book === null) {
            return null;
        }
        $pieces = [];
        foreach ($book as $sheet) {
            $lines = array_map(static fn(array $r): string => implode("\t", $r), $sheet['rows']);
            if ($lines !== []) {
                $pieces[] = '# ' . $sheet['name'] . "\n" . implode("\n", $lines);
            }
        }
        return $pieces !== [] ? implode("\n\n", $pieces) : null;
    }

    /**
     * Feuilles de calcul d'un .xls (graphiques et macros écartés), valeurs
     * rendues en texte, rangées vides retirées.
     *
     * @return list<array{name:string, rows:list<list<string>>}>|null
     */
    public static function workbook(string $path): ?array
    {
        $cfb = self::openFile($path);
        if ($cfb === null) {
            return null;
        }
        $wb = self::stream($cfb, 'Workbook') ?? self::stream($cfb, 'Book');
        return $wb === null ? null : self::parseWorkbook($wb);
    }

    /**
     * Le flux « Workbook » d'un classeur, enregistrement par enregistrement.
     *
     * @return list<array{name:string, rows:list<list<string>>}>|null
     */
    public static function parseWorkbook(string $wb): ?array
    {
        $first = self::record($wb, 0);
        if ($first === null || $first['type'] !== 0x0809 || strlen($first['data']) < 4) {
            return null;
        }
        $biff8 = self::u16($first['data'], 0) >= 0x0600;
        $g = [
            'biff8'   => $biff8,
            'charset' => 'Windows-1252',
            'sst'     => [],
            'formats' => [],
            'xf'      => [],
            'date1904' => false,
        ];
        $sheets = [];

        // Paramètres globaux, jusqu'au premier EOF.
        $pos = 0;
        while (($rec = self::record($wb, $pos)) !== null) {
            $pos = $rec['next'];
            $d = $rec['data'];
            switch ($rec['type']) {
                case 0x000A: // EOF
                    break 2;
                case 0x002F: // FILEPASS : classeur chiffré
                    return null;
                case 0x0042: // CODEPAGE
                    $cp = strlen($d) >= 2 ? self::u16($d, 0) : 1252;
                    if ($cp >= 1250 && $cp <= 1258) {
                        $g['charset'] = 'Windows-' . $cp;
                    }
                    break;
                case 0x0022: // DATEMODE
                    $g['date1904'] = strlen($d) >= 2 && self::u16($d, 0) === 1;
                    break;
                case 0x041E: // FORMAT
                    if (strlen($d) >= 3) {
                        $cur = self::cursor($d, $rec['continues']);
                        $id = self::readU16($cur);
                        $g['formats'][$id] = $biff8
                            ? self::readXlString($cur, 2)
                            : self::readByteString($cur, 1, $g['charset']);
                    }
                    break;
                case 0x00E0: // XF : le format de nombre de chaque style de cellule
                    $g['xf'][] = strlen($d) >= 4 ? self::u16($d, 2) : 0;
                    break;
                case 0x0085: // BOUNDSHEET
                    if (strlen($d) >= 7) {
                        $cur = self::cursor(substr($d, 6), []);
                        $sheets[] = [
                            'at'   => self::u32($d, 0),
                            'type' => ord($d[5]),
                            'name' => $biff8
                                ? self::readXlString($cur, 1)
                                : self::readByteString($cur, 1, $g['charset']),
                        ];
                    }
                    break;
                case 0x00FC: // SST : les chaînes partagées
                    $g['sst'] = self::readSst($d, $rec['continues']);
                    break;
            }
        }

        $out = [];
        foreach ($sheets as $s) {
            if ($s['type'] !== 0) {
                continue; // graphique, macro, module VB
            }
            $rows = self::readSheet($wb, $s['at'], $g);
            if ($rows === null) {
                continue;
            }
            $out[] = ['name' => $s['name'] !== '' ? $s['name'] : 'Feuille ' . (count($out) + 1), 'rows' => $rows];
        }
        return $out;
    }

    /** @return list<list<string>>|null */
    private static function readSheet(string $wb, int $at, array $g): ?array
    {
        $bof = self::record($wb, $at);
        if ($bof === null || $bof['type'] !== 0x0809 || strlen($bof['data']) < 4 || self::u16($bof['data'], 2) !== 0x0010) {
            return null;
        }
        $cells = [];
        $put = static function (int $r, int $c, string $v) use (&$cells): void {
            if ($v !== '' && $r >= 0 && $c >= 0 && $c < 16384) {
                $cells[$r][$c] = $v;
            }
        };
        $pendingString = null; // FORMULA dont la valeur texte suit en STRING
        $pos = $bof['next'];
        while (($rec = self::record($wb, $pos)) !== null) {
            $pos = $rec['next'];
            $d = $rec['data'];
            $len = strlen($d);
            if ($rec['type'] === 0x000A) {
                break;
            }
            if ($rec['type'] === 0x0207) { // STRING : résultat texte d'une formule
                if ($pendingString !== null) {
                    $cur = self::cursor($d, $rec['continues']);
                    $put($pendingString[0], $pendingString[1], $g['biff8']
                        ? self::readXlString($cur, 2)
                        : self::readByteString($cur, 2, $g['charset']));
                }
                $pendingString = null;
                continue;
            }
            // Entre une formule et son résultat texte peuvent s'intercaler
            // SHRFMLA, ARRAY ou TABLE (formules partagées ou matricielles).
            if (!in_array($rec['type'], [0x04BC, 0x0221, 0x0236], true)) {
                $pendingString = null;
            }
            if ($len < 6) {
                continue;
            }
            $r = self::u16($d, 0);
            $c = self::u16($d, 2);
            switch ($rec['type']) {
                case 0x00FD: // LABELSST
                    if ($len >= 10) {
                        $put($r, $c, (string)($g['sst'][self::u32($d, 6)] ?? ''));
                    }
                    break;
                case 0x0204: // LABEL
                case 0x00D6: // RSTRING (Excel 95)
                    $cur = self::cursor(substr($d, 6), $rec['continues']);
                    $put($r, $c, $g['biff8']
                        ? self::readXlString($cur, 2)
                        : self::readByteString($cur, 2, $g['charset']));
                    break;
                case 0x0203: // NUMBER
                    if ($len >= 14) {
                        $put($r, $c, self::formatNumber(self::f64(substr($d, 6, 8)), self::u16($d, 4), $g));
                    }
                    break;
                case 0x027E: // RK
                    if ($len >= 10) {
                        $put($r, $c, self::formatNumber(self::rk(self::u32($d, 6)), self::u16($d, 4), $g));
                    }
                    break;
                case 0x00BD: // MULRK : plusieurs nombres d'une même rangée
                    for ($k = 0; 4 + $k * 6 + 6 <= $len - 2; $k++) {
                        $o = 4 + $k * 6;
                        $put($r, $c + $k, self::formatNumber(self::rk(self::u32($d, $o + 2)), self::u16($d, $o), $g));
                    }
                    break;
                case 0x0205: // BOOLERR
                    if ($len >= 8) {
                        $put($r, $c, ord($d[7]) === 1 ? self::errorText(ord($d[6])) : (ord($d[6]) !== 0 ? 'TRUE' : 'FALSE'));
                    }
                    break;
                case 0x0006: // FORMULA : la valeur calculée, gardée par le fichier
                    if ($len >= 14) {
                        if (substr($d, 12, 2) === "\xFF\xFF") {
                            match (ord($d[6])) {
                                0       => $pendingString = [$r, $c],
                                1       => $put($r, $c, ord($d[8]) !== 0 ? 'TRUE' : 'FALSE'),
                                2       => $put($r, $c, self::errorText(ord($d[8]))),
                                default => null,
                            };
                        } else {
                            $put($r, $c, self::formatNumber(self::f64(substr($d, 6, 8)), self::u16($d, 4), $g));
                        }
                    }
                    break;
            }
        }

        ksort($cells);
        $rows = [];
        foreach ($cells as $line) {
            $row = [];
            $last = max(array_keys($line));
            for ($k = 0; $k <= $last; $k++) {
                $row[] = str_replace(["\t", "\r\n", "\r", "\n"], ' ', $line[$k] ?? '');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Table des chaînes partagées. Une chaîne peut être coupée par un
     * enregistrement CONTINUE au milieu de ses caractères : la suite commence
     * alors par un octet d'options qui redit sa largeur (1 ou 2 octets par
     * caractère) — c'est tout le piège du format.
     *
     * @param list<string> $continues
     * @return list<string>
     */
    public static function readSst(string $data, array $continues): array
    {
        if (strlen($data) < 8) {
            return [];
        }
        $unique = self::u32($data, 4);
        $cur = self::cursor(substr($data, 8), $continues);
        $out = [];
        for ($i = 0; $i < $unique && !self::exhausted($cur); $i++) {
            $out[] = self::readXlString($cur, 2, true);
        }
        return $out;
    }

    /**
     * Chaîne Unicode d'Excel 97 : longueur (1 ou 2 octets), options, puis
     * caractères compressés (ISO-8859-1) ou UTF-16, éventuellement suivis
     * des mises en forme (rich) et données phonétiques (ext), sautées.
     */
    private static function readXlString(array &$cur, int $lenBytes, bool $extended = false): string
    {
        $cch = $lenBytes === 1 ? self::readU8($cur) : self::readU16($cur);
        $opt = self::readU8($cur);
        $runs = 0;
        $ext = 0;
        if ($extended) {
            if ($opt & 0x08) {
                $runs = self::readU16($cur);
            }
            if ($opt & 0x04) {
                $ext = self::readU32($cur);
            }
        }
        $high = ($opt & 0x01) !== 0;
        $text = '';
        $left = $cch;
        while ($left > 0) {
            $avail = strlen($cur['parts'][$cur['p']] ?? '') - $cur['o'];
            if ($avail <= 0) {
                // Caractères coupés par un CONTINUE : nouvel octet d'options.
                if (!self::nextPart($cur)) {
                    break;
                }
                $high = (self::readU8($cur) & 0x01) !== 0;
                continue;
            }
            $take = min($left, intdiv($avail, $high ? 2 : 1));
            if ($take <= 0) {
                $cur['o'] = strlen($cur['parts'][$cur['p']]);
                continue;
            }
            $bytes = substr($cur['parts'][$cur['p']], $cur['o'], $take * ($high ? 2 : 1));
            $cur['o'] += strlen($bytes);
            $text .= $high ? self::convert($bytes, 'UTF-16LE') : self::convert($bytes, 'ISO-8859-1');
            $left -= $take;
        }
        self::skip($cur, $runs * 4 + $ext);
        return $text;
    }

    /** Chaîne d'Excel 95 : longueur puis octets dans la page de code du classeur. */
    private static function readByteString(array &$cur, int $lenBytes, string $charset): string
    {
        $cch = $lenBytes === 1 ? self::readU8($cur) : self::readU16($cur);
        return self::convert(self::readBytes($cur, $cch), $charset);
    }

    /** Valeur d'un nombre RK : entier sur 30 bits ou haut d'un double, éventuellement ÷ 100. */
    public static function rk(int $rk): float
    {
        if (($rk & 0x02) !== 0) {
            $v = $rk >> 2;
            if ($v & 0x20000000) {
                $v -= 0x40000000;
            }
            $num = (float)$v;
        } else {
            $num = self::f64(pack('V', 0) . pack('V', $rk & 0xFFFFFFFC));
        }
        return ($rk & 0x01) !== 0 ? $num / 100 : $num;
    }

    /** Nombre rendu selon le format de sa cellule : date, heure, pourcentage, sinon tel quel. */
    private static function formatNumber(float $v, int $xf, array $g): string
    {
        $fmt = (int)($g['xf'][$xf] ?? 0);
        $kind = self::formatKind($fmt, $fmt >= 164 ? (string)($g['formats'][$fmt] ?? '') : '');
        if ($kind === 'date' || $kind === 'datetime' || $kind === 'time') {
            $date = self::excelDate($v, $g['date1904'], $kind);
            if ($date !== null) {
                return $date;
            }
        }
        if ($kind === 'percent') {
            return self::number($v * 100) . '%';
        }
        return self::number($v);
    }

    /**
     * Nature d'un format de nombre : intégré (identifiant < 164, défini par
     * Excel) ou écrit dans le fichier. Un format écrit est une date s'il
     * porte j/m/a, une heure s'il porte h/s — hors texte entre guillemets,
     * caractères échappés et crochets ([Red], [$€-40C]).
     */
    public static function formatKind(int $id, string $code): string
    {
        if ($id < 164) {
            return match (true) {
                in_array($id, [14, 15, 16, 17], true)                              => 'date',
                $id === 22                                                          => 'datetime',
                in_array($id, [18, 19, 20, 21, 45, 46, 47], true)                  => 'time',
                in_array($id, [27, 28, 29, 30, 31, 34, 35, 36, 50, 51, 52, 53, 54, 57, 58], true) => 'date',
                in_array($id, [32, 33, 55, 56], true)                              => 'time',
                in_array($id, [9, 10], true)                                        => 'percent',
                default                                                             => 'number',
            };
        }
        $code = explode(';', $code)[0];
        $code = preg_replace('/"[^"]*"|\\\\.|_.|\*./u', '', $code) ?? $code;
        $elapsed = (bool)preg_match('/\[(h+|m+|s+)\]/i', $code);
        $code = preg_replace('/\[[^\]]*\]/', '', $code) ?? $code;
        // « m » vaut minute à côté d'une heure, mois sinon.
        $hasTime = $elapsed || (bool)preg_match('/[hs]/i', $code);
        $hasDate = (bool)preg_match('/[dyj]/i', $code) || (!$hasTime && (bool)preg_match('/m/i', $code));
        if ($hasDate && $hasTime) {
            return 'datetime';
        }
        if ($hasDate) {
            return 'date';
        }
        if ($hasTime) {
            return 'time';
        }
        return str_contains($code, '%') ? 'percent' : 'number';
    }

    /** Numéro de série Excel → « AAAA-MM-JJ », « AAAA-MM-JJ HH:MM » ou « HH:MM ». */
    public static function excelDate(float $serial, bool $date1904, string $kind): ?string
    {
        if (!is_finite($serial) || $serial < 0 || $serial > 2958465) {
            return null;
        }
        $days = (int)floor($serial);
        $secs = (int)round(($serial - $days) * 86400);
        if ($secs >= 86400) {
            $days++;
            $secs -= 86400;
        }
        $time = sprintf('%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60));
        if ($secs % 60 !== 0) {
            $time .= sprintf(':%02d', $secs % 60);
        }
        if ($kind === 'time' && $days === 0) {
            return $time;
        }
        if ($date1904) {
            $base = new DateTimeImmutable('1904-01-01', new DateTimeZone('UTC'));
        } else {
            // Excel tient 1900 pour bissextile (29/02/1900 n'existe pas) :
            // les numéros d'avant mars 1900 sont décalés d'un jour.
            $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
            if ($days < 61) {
                $days++;
            }
        }
        $date = $base->modify('+' . $days . ' days')->format('Y-m-d');
        return $kind === 'date' && $secs === 0 ? $date : $date . ' ' . $time;
    }

    /** Nombre sans format : entier sans décimale, sinon 15 chiffres significatifs, point décimal. */
    public static function number(float $v): string
    {
        if (!is_finite($v)) {
            return '';
        }
        if ($v == floor($v) && abs($v) < 1e15) {
            $s = sprintf('%.0F', $v);
            return $s === '-0' ? '0' : $s;
        }
        return sprintf('%.15H', $v);
    }

    private static function errorText(int $code): string
    {
        return [0x00 => '#NULL!', 0x07 => '#DIV/0!', 0x0F => '#VALUE!', 0x17 => '#REF!',
            0x1D => '#NAME?', 0x24 => '#NUM!', 0x2A => '#N/A'][$code] ?? '#N/A';
    }

    /**
     * Enregistrement BIFF à une position, avec les CONTINUE qui le suivent.
     *
     * @return array{type:int, data:string, continues:list<string>, next:int}|null
     */
    private static function record(string $wb, int $pos): ?array
    {
        if ($pos < 0 || $pos + 4 > strlen($wb)) {
            return null;
        }
        $type = self::u16($wb, $pos);
        $len  = self::u16($wb, $pos + 2);
        $data = (string)substr($wb, $pos + 4, $len);
        $next = $pos + 4 + $len;
        $continues = [];
        while ($next + 4 <= strlen($wb) && self::u16($wb, $next) === 0x003C) {
            $clen = self::u16($wb, $next + 2);
            $continues[] = (string)substr($wb, $next + 4, $clen);
            $next += 4 + $clen;
        }
        return ['type' => $type, 'data' => $data, 'continues' => $continues, 'next' => $next];
    }

    // ---- Lecture d'un enregistrement et de ses CONTINUE --------------

    private static function cursor(string $data, array $continues): array
    {
        return ['parts' => array_merge([$data], $continues), 'p' => 0, 'o' => 0];
    }

    private static function exhausted(array $cur): bool
    {
        for ($p = $cur['p']; $p < count($cur['parts']); $p++) {
            if (strlen($cur['parts'][$p]) > ($p === $cur['p'] ? $cur['o'] : 0)) {
                return false;
            }
        }
        return true;
    }

    private static function nextPart(array &$cur): bool
    {
        if ($cur['p'] + 1 >= count($cur['parts'])) {
            $cur['o'] = strlen($cur['parts'][$cur['p']]);
            return false;
        }
        $cur['p']++;
        $cur['o'] = 0;
        return true;
    }

    /** Octets qui peuvent enjamber deux enregistrements, sans octet d'options. */
    private static function readBytes(array &$cur, int $n): string
    {
        $out = '';
        while ($n > 0) {
            $part = $cur['parts'][$cur['p']];
            $avail = strlen($part) - $cur['o'];
            if ($avail <= 0) {
                if (!self::nextPart($cur)) {
                    break;
                }
                continue;
            }
            $take = min($n, $avail);
            $out .= substr($part, $cur['o'], $take);
            $cur['o'] += $take;
            $n -= $take;
        }
        return $out;
    }

    private static function skip(array &$cur, int $n): void
    {
        if ($n > 0) {
            self::readBytes($cur, $n);
        }
    }

    private static function readU8(array &$cur): int
    {
        $b = self::readBytes($cur, 1);
        return $b === '' ? 0 : ord($b);
    }

    private static function readU16(array &$cur): int
    {
        $b = self::readBytes($cur, 2);
        return strlen($b) === 2 ? self::u16($b, 0) : 0;
    }

    private static function readU32(array &$cur): int
    {
        $b = self::readBytes($cur, 4);
        return strlen($b) === 4 ? self::u32($b, 0) : 0;
    }

    // ==================================================================
    // PowerPoint 97-2003
    // ==================================================================

    private const PPT_DOCUMENT = 0x03E8;
    private const PPT_SLIDE = 0x03EE;
    private const PPT_SLIDE_ATOM = 0x03EF;
    private const PPT_NOTES = 0x03F0;
    private const PPT_SLIDE_PERSIST = 0x03F3;
    private const PPT_SLIDE_LIST = 0x0FF0;
    private const PPT_USER_EDIT = 0x0FF5;
    private const PPT_PERSIST_DIR = 0x1772;
    private const PPT_TEXT_CHARS = 0x0FA0;
    private const PPT_TEXT_BYTES = 0x0FA8;

    /** Diapositives lues au plus (une présentation abîmée ne boucle pas). */
    private const PPT_MAX_SLIDES = 2000;

    /**
     * Texte d'un .ppt, diapositive par diapositive, dans l'ordre de la
     * présentation : « ## Diapositive N », ses zones de texte, puis ses notes.
     * Null : pas une présentation lisible (chiffrée, abîmée, sans texte).
     */
    public static function pptText(string $path): ?string
    {
        $cfb = self::openFile($path);
        if ($cfb === null || isset($cfb['streams']['encryptedsummary'])) {
            return null;
        }
        $doc = self::stream($cfb, 'PowerPoint Document');
        if ($doc === null || strlen($doc) < 8) {
            return null;
        }
        $slides = self::pptSlides($doc, self::stream($cfb, 'Current User'));
        $out = [];
        foreach ($slides as $i => $slide) {
            $lines = self::dedupeLines($slide['text']);
            $notes = self::dedupeLines($slide['notes']);
            if ($lines === [] && $notes === []) {
                continue;
            }
            $block = '## Diapositive ' . ($i + 1) . "\n" . implode("\n", $lines);
            if ($notes !== []) {
                $block .= "\n\nNotes : " . implode("\n", $notes);
            }
            $out[] = rtrim($block);
        }
        return $out !== [] ? implode("\n\n", $out) : null;
    }

    /**
     * Diapositives d'un flux « PowerPoint Document » : texte et notes de
     * chacune. Ordre et contenus par le répertoire de persistance (dernière
     * modification enregistrée d'abord) ; à défaut, les diapositives dans
     * l'ordre du flux.
     *
     * @return list<array{text: list<string>, notes: list<string>}>
     */
    public static function pptSlides(string $doc, ?string $currentUser): array
    {
        $dir = self::pptPersist($doc, $currentUser);
        if ($dir !== null) {
            $slides = self::pptSlidesFromDocument($doc, $dir['persist'], $dir['doc']);
            if ($slides !== null) {
                return $slides;
            }
        }
        // Repli : chaque diapositive du flux, dans l'ordre où elle est écrite.
        $out = [];
        foreach (self::pptRecords($doc, 0, strlen($doc)) as $r) {
            if ($r['type'] === self::PPT_SLIDE && count($out) < self::PPT_MAX_SLIDES) {
                $out[] = ['text' => self::pptTexts($doc, $r['data'], $r['end']), 'notes' => []];
            }
        }
        return $out;
    }

    /**
     * Répertoire de persistance : identifiant → position dans le flux, en
     * remontant les modifications (« UserEditAtom ») de la plus récente à la
     * plus ancienne — la première vue l'emporte.
     *
     * @return array{persist: array<int, int>, doc: int}|null
     */
    private static function pptPersist(string $doc, ?string $currentUser): ?array
    {
        if ($currentUser === null || strlen($currentUser) < 20) {
            return null;
        }
        $offset = self::u32($currentUser, 16);
        $persist = [];
        $docRef = null;
        $seen = [];
        while ($offset > 0 && $offset + 28 <= strlen($doc) && !isset($seen[$offset]) && count($seen) < 4096) {
            $seen[$offset] = true;
            if (self::u16($doc, $offset + 2) !== self::PPT_USER_EDIT) {
                return null;
            }
            $docRef ??= self::u32($doc, $offset + 24);
            $dirAt = self::u32($doc, $offset + 20);
            if ($dirAt + 8 <= strlen($doc) && self::u16($doc, $dirAt + 2) === self::PPT_PERSIST_DIR) {
                $p = $dirAt + 8;
                $end = min(strlen($doc), $p + self::u32($doc, $dirAt + 4));
                while ($p + 4 <= $end) {
                    $v = self::u32($doc, $p);
                    $p += 4;
                    $first = $v & 0xFFFFF;
                    $count = $v >> 20;
                    for ($k = 0; $k < $count && $p + 4 <= $end; $k++, $p += 4) {
                        $persist[$first + $k] ??= self::u32($doc, $p);
                    }
                }
            }
            $offset = self::u32($doc, $offset + 16);
        }
        return $docRef !== null && $persist !== [] ? ['persist' => $persist, 'doc' => $docRef] : null;
    }

    /**
     * Diapositives par le document : la liste « SlideListWithText » (instance
     * 0) donne l'ordre et le texte des espaces réservés ; chaque diapositive
     * ajoute ses zones de texte, et ses notes.
     *
     * @param array<int, int> $persist
     * @return list<array{text: list<string>, notes: list<string>}>|null
     */
    private static function pptSlidesFromDocument(string $doc, array $persist, int $docRef): ?array
    {
        $at = $persist[$docRef] ?? null;
        $docRec = $at !== null ? self::pptRecord($doc, $at) : null;
        if ($docRec === null || $docRec['type'] !== self::PPT_DOCUMENT) {
            return null;
        }
        $slides = [];
        $notesById = [];
        foreach (self::pptRecords($doc, $docRec['data'], $docRec['end']) as $list) {
            if ($list['type'] === self::PPT_SLIDE_LIST && $list['inst'] === 2) {
                // Liste des notes : identifiant de page → identifiant de persistance.
                foreach (self::pptRecords($doc, $list['data'], $list['end']) as $r) {
                    if ($r['type'] === self::PPT_SLIDE_PERSIST && $r['len'] >= 16) {
                        $notesById[self::u32($doc, $r['data'] + 12)] = self::u32($doc, $r['data']);
                    }
                }
                continue;
            }
            if ($list['type'] !== self::PPT_SLIDE_LIST || $list['inst'] !== 0) {
                continue;
            }
            $current = null;
            foreach (self::pptRecords($doc, $list['data'], $list['end']) as $r) {
                if ($r['type'] === self::PPT_SLIDE_PERSIST && $r['len'] >= 4) {
                    if ($current !== null) {
                        $slides[] = $current;
                    }
                    if (count($slides) >= self::PPT_MAX_SLIDES) {
                        break 2;
                    }
                    $current = ['ref' => self::u32($doc, $r['data']), 'text' => []];
                } elseif ($current !== null && ($text = self::pptAtomText($doc, $r)) !== null) {
                    $current['text'][] = $text;
                }
            }
            if ($current !== null) {
                $slides[] = $current;
            }
        }
        if ($slides === []) {
            return null;
        }
        $out = [];
        foreach ($slides as $sl) {
            $text = $sl['text'];
            $notes = [];
            $slideAt = $persist[$sl['ref']] ?? null;
            $slide = $slideAt !== null ? self::pptRecord($doc, $slideAt) : null;
            if ($slide !== null && $slide['type'] === self::PPT_SLIDE) {
                array_push($text, ...self::pptTexts($doc, $slide['data'], $slide['end']));
                foreach (self::pptRecords($doc, $slide['data'], $slide['end']) as $r) {
                    if ($r['type'] === self::PPT_SLIDE_ATOM && $r['len'] >= 20) {
                        // notesIdRef désigne une page de notes, retrouvée par la liste des notes.
                        $notesRef = $notesById[self::u32($doc, $r['data'] + 16)] ?? null;
                        $notesAt = $notesRef !== null ? ($persist[$notesRef] ?? null) : null;
                        $n = $notesAt !== null ? self::pptRecord($doc, $notesAt) : null;
                        if ($n !== null && $n['type'] === self::PPT_NOTES) {
                            $notes = self::pptTexts($doc, $n['data'], $n['end']);
                        }
                        break;
                    }
                }
            }
            $out[] = ['text' => $text, 'notes' => $notes];
        }
        return $out;
    }

    /**
     * Textes (« TextCharsAtom », « TextBytesAtom ») d'une plage, conteneurs
     * parcourus sur quelques niveaux, dans l'ordre.
     *
     * @return list<string>
     */
    private static function pptTexts(string $doc, int $from, int $to, int $depth = 0): array
    {
        $out = [];
        foreach (self::pptRecords($doc, $from, $to) as $r) {
            if ($r['ver'] === 0xF && $depth < 12) {
                array_push($out, ...self::pptTexts($doc, $r['data'], $r['end'], $depth + 1));
            } elseif (($text = self::pptAtomText($doc, $r)) !== null) {
                $out[] = $text;
            }
        }
        return $out;
    }

    /** Texte d'un atome de texte, ou null (autre enregistrement, texte vide, simple champ « * »). */
    private static function pptAtomText(string $doc, array $r): ?string
    {
        if ($r['type'] === self::PPT_TEXT_CHARS) {
            $text = self::convert(substr($doc, $r['data'], $r['len']), 'UTF-16LE');
        } elseif ($r['type'] === self::PPT_TEXT_BYTES) {
            $text = self::convert(substr($doc, $r['data'], $r['len']), 'ISO-8859-1');
        } else {
            return null;
        }
        // Paragraphes séparés par \r, sauts de ligne par \v.
        $text = trim(str_replace(["\r", "\v"], "\n", $text));
        return $text === '' || $text === '*' ? null : $text;
    }

    /**
     * Enregistrements d'une plage (sans descendre) : type, instance, version,
     * début et fin des données. Un enregistrement qui déborde arrête la
     * lecture.
     *
     * @return list<array{type:int, inst:int, ver:int, data:int, len:int, end:int}>
     */
    private static function pptRecords(string $doc, int $from, int $to): array
    {
        $out = [];
        $to = min($to, strlen($doc));
        for ($p = $from; $p + 8 <= $to && count($out) < 65536;) {
            $r = self::pptRecord($doc, $p);
            if ($r === null || $r['end'] > $to) {
                break;
            }
            $out[] = $r;
            $p = $r['end'];
        }
        return $out;
    }

    /** @return array{type:int, inst:int, ver:int, data:int, len:int, end:int}|null */
    private static function pptRecord(string $doc, int $at): ?array
    {
        if ($at < 0 || $at + 8 > strlen($doc)) {
            return null;
        }
        $vi = self::u16($doc, $at);
        $len = self::u32($doc, $at + 4);
        $end = $at + 8 + $len;
        if ($end > strlen($doc)) {
            return null;
        }
        return ['type' => self::u16($doc, $at + 2), 'inst' => $vi >> 4, 'ver' => $vi & 0xF,
            'data' => $at + 8, 'len' => $len, 'end' => $end];
    }

    /**
     * Lignes sans doublon (le même texte écrit deux fois — espace réservé et
     * zone de texte —, des lignes vides).
     *
     * @param list<string> $texts
     * @return list<string>
     */
    private static function dedupeLines(array $texts): array
    {
        $out = [];
        $seen = [];
        foreach ($texts as $t) {
            $t = trim(preg_replace("/[ \t]+\n/", "\n", $t) ?? $t);
            if ($t === '' || isset($seen[$t])) {
                continue;
            }
            $seen[$t] = true;
            $out[] = $t;
        }
        return $out;
    }

    // ==================================================================
    // Conteneur (Compound File Binary)
    // ==================================================================

    /** @return array{bytes:string, ssz:int, fat:list<int>, minifat:list<int>, mini:string, cutoff:int, streams:array<string,array{start:int,size:int}>}|null */
    private static function openFile(string $path): ?array
    {
        $size = @filesize($path);
        if ($size === false || $size < 512 || $size > self::MAX_BYTES) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return is_string($bytes) ? self::open($bytes) : null;
    }

    /**
     * Conteneur MS-CFB : en-tête, DIFAT, FAT, répertoire, mini-flux. Seuls
     * les flux posés à la RACINE sont retenus — un document Word incorporé
     * dans un autre porte lui aussi un flux « WordDocument », dans son propre
     * dossier.
     */
    public static function open(string $bytes): ?array
    {
        if (strlen($bytes) < 512 || !str_starts_with($bytes, self::SIGNATURE)) {
            return null;
        }
        $shift = self::u16($bytes, 0x1E);
        if (($shift !== 9 && $shift !== 12) || self::u16($bytes, 0x20) !== 6) {
            return null;
        }
        $ssz = 1 << $shift;
        $major = self::u16($bytes, 0x1A);
        $sectors = intdiv(strlen($bytes) - $ssz + $ssz - 1, $ssz);

        // DIFAT : où sont les secteurs de la FAT.
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $s = self::u32($bytes, 0x4C + 4 * $i);
            if ($s < self::MAX_REGULAR) {
                $fatSectors[] = $s;
            }
        }
        $next = self::u32($bytes, 0x44);
        $seen = [];
        while ($next < self::MAX_REGULAR) {
            if (isset($seen[$next]) || count($seen) > $sectors) {
                return null;
            }
            $seen[$next] = true;
            $sec = self::sector($bytes, $next, $ssz);
            if ($sec === null) {
                return null;
            }
            $per = intdiv($ssz, 4) - 1;
            for ($i = 0; $i < $per; $i++) {
                $s = self::u32($sec, 4 * $i);
                if ($s < self::MAX_REGULAR) {
                    $fatSectors[] = $s;
                }
            }
            $next = self::u32($sec, $ssz - 4);
        }
        if ($fatSectors === []) {
            return null;
        }
        $fatBytes = '';
        foreach ($fatSectors as $s) {
            $sec = self::sector($bytes, $s, $ssz);
            if ($sec === null) {
                return null;
            }
            $fatBytes .= $sec;
        }
        $fat = array_values(unpack('V*', $fatBytes) ?: []);

        // Répertoire.
        $dir = self::readChain($bytes, $fat, self::u32($bytes, 0x30), $ssz);
        if ($dir === null || strlen($dir) < 128) {
            return null;
        }
        $entries = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $nameLen = self::u16($dir, $o + 64);
            $size = self::u32($dir, $o + 120);
            if ($major === 4) {
                $size += self::u32($dir, $o + 124) * 4294967296;
            }
            $entries[] = [
                'name'  => $nameLen >= 2 && $nameLen <= 64 ? self::convert(substr($dir, $o, $nameLen - 2), 'UTF-16LE') : '',
                'type'  => ord($dir[$o + 66]),
                'left'  => self::u32($dir, $o + 68),
                'right' => self::u32($dir, $o + 72),
                'child' => self::u32($dir, $o + 76),
                'start' => self::u32($dir, $o + 116),
                'size'  => (int)$size,
            ];
        }
        $root = $entries[0];
        if ($root['type'] !== 5) {
            return null;
        }

        // Flux de la racine : l'arbre de ses enfants (gauche / droite). Les
        // dossiers (pièces jointes d'un email Outlook, objets incorporés) sont
        // gardés à part, sur quelques niveaux, sans jamais mêler leurs flux à
        // ceux de la racine.
        $visited = [];
        $node = self::children($entries, $root['child'], $visited, 0);
        $streams = $node['streams'];

        // Mini-flux : petits flux (< 4096 octets) rangés par blocs de 64.
        $mini = '';
        $minifat = [];
        if ($root['size'] > 0 && $root['start'] < self::MAX_REGULAR) {
            $mini = (string)self::readChain($bytes, $fat, $root['start'], $ssz);
            $mini = substr($mini, 0, $root['size']);
            $mfStart = self::u32($bytes, 0x3C);
            if ($mfStart < self::MAX_REGULAR) {
                $mf = self::readChain($bytes, $fat, $mfStart, $ssz);
                $minifat = $mf !== null ? array_values(unpack('V*', $mf) ?: []) : [];
            }
        }
        $cutoff = self::u32($bytes, 0x38) ?: 4096;

        return ['bytes' => $bytes, 'ssz' => $ssz, 'fat' => $fat, 'minifat' => $minifat,
            'mini' => $mini, 'cutoff' => $cutoff, 'streams' => $streams, 'storages' => $node['storages']];
    }

    /** Profondeur de dossiers lue (un email joint à un email joint…). */
    private const MAX_DEPTH = 4;

    /**
     * Enfants d'une entrée du répertoire (arbre gauche / droite) : ses flux
     * par nom (sans casse) et ses dossiers, eux-mêmes décrits de la même
     * façon. Une entrée n'est visitée qu'une fois (répertoire bouclé).
     *
     * @param list<array<string, mixed>> $entries
     * @param array<int, true> $visited
     * @return array{streams: array<string, array{start:int,size:int}>, storages: array<string, array>}
     */
    private static function children(array $entries, int $first, array &$visited, int $depth): array
    {
        $streams = [];
        $storages = [];
        $stack = [$first];
        while ($stack !== []) {
            $id = array_pop($stack);
            if ($id === self::NO_STREAM || !isset($entries[$id]) || isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $e = $entries[$id];
            $key = mb_strtolower((string)$e['name']);
            if ($e['type'] === 2) {
                $streams[$key] ??= ['start' => $e['start'], 'size' => $e['size']];
            } elseif ($e['type'] === 1 && $depth < self::MAX_DEPTH && !isset($storages[$key])) {
                $storages[$key] = self::children($entries, $e['child'], $visited, $depth + 1);
            }
            $stack[] = $e['left'];
            $stack[] = $e['right'];
        }
        return ['streams' => $streams, 'storages' => $storages];
    }

    /**
     * Un flux par son nom (sans casse), ou null : à la racine, ou dans le
     * dossier `$node` (une entrée de `storages`).
     *
     * @param array{streams: array<string, array{start:int,size:int}>}|null $node
     */
    public static function stream(array $cfb, string $name, ?array $node = null): ?string
    {
        $e = ($node ?? $cfb)['streams'][mb_strtolower($name)] ?? null;
        if ($e === null) {
            return null;
        }
        if ($e['size'] === 0) {
            return '';
        }
        if ($e['size'] > strlen($cfb['bytes'])) {
            return null;
        }
        $data = $e['size'] < $cfb['cutoff']
            ? self::readMiniChain($cfb, $e['start'])
            : self::readChain($cfb['bytes'], $cfb['fat'], $e['start'], $cfb['ssz']);
        if ($data === null || strlen($data) < $e['size']) {
            return null;
        }
        return substr($data, 0, $e['size']);
    }

    private static function sector(string $bytes, int $n, int $ssz): ?string
    {
        $off = ($n + 1) * $ssz;
        if ($n < 0 || $off + $ssz > strlen($bytes) + $ssz) {
            return null;
        }
        $s = substr($bytes, $off, $ssz);
        return strlen($s) === $ssz ? $s : str_pad($s, $ssz, "\0");
    }

    /** Chaîne de secteurs : suivie dans la FAT, chaque secteur une seule fois. */
    private static function readChain(string $bytes, array $fat, int $start, int $ssz): ?string
    {
        $out = '';
        $seen = [];
        $cur = $start;
        $max = count($fat);
        while ($cur !== self::END_OF_CHAIN) {
            if ($cur >= self::MAX_REGULAR || $cur >= $max || isset($seen[$cur])) {
                return null;
            }
            $seen[$cur] = true;
            $sec = self::sector($bytes, $cur, $ssz);
            if ($sec === null) {
                return null;
            }
            $out .= $sec;
            $cur = $fat[$cur];
        }
        return $out;
    }

    private static function readMiniChain(array $cfb, int $start): ?string
    {
        $out = '';
        $seen = [];
        $cur = $start;
        $max = count($cfb['minifat']);
        while ($cur !== self::END_OF_CHAIN) {
            if ($cur >= self::MAX_REGULAR || $cur >= $max || isset($seen[$cur])) {
                return null;
            }
            $seen[$cur] = true;
            $block = substr($cfb['mini'], $cur * 64, 64);
            if (strlen($block) < 64) {
                return null;
            }
            $out .= $block;
            $cur = $cfb['minifat'][$cur];
        }
        return $out;
    }

    // ==================================================================
    // Octets
    // ==================================================================

    private static function u16(string $s, int $o): int
    {
        return $o >= 0 && $o + 2 <= strlen($s) ? unpack('v', $s, $o)[1] : 0;
    }

    private static function u32(string $s, int $o): int
    {
        return $o >= 0 && $o + 4 <= strlen($s) ? unpack('V', $s, $o)[1] : 0;
    }

    private static function f64(string $eight): float
    {
        return strlen($eight) === 8 ? (float)unpack('e', $eight)[1] : 0.0;
    }

    private static function convert(string $bytes, string $from): string
    {
        if ($bytes === '') {
            return '';
        }
        try {
            $s = mb_convert_encoding($bytes, 'UTF-8', $from);
        } catch (\ValueError) {
            $s = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }
        return is_string($s) ? $s : '';
    }
}
