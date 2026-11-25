<?php

declare(strict_types=1);

/**
 * Anciens fichiers Office de TEST, écrits octet par octet — pour éprouver
 * LegacyOffice sur ce qu'un fichier réel ne montre pas à coup sûr : un texte
 * Word en plusieurs morceaux rangés dans le désordre, sur un octet et sur
 * deux ; une chaîne Excel coupée par un CONTINUE qui change de largeur de
 * caractères ; un classeur Excel 95 ; un conteneur piégé.
 *
 * Les fichiers réels (tests/Fixtures/legacy-office) viennent de LibreOffice ;
 * ceux-ci gardent la STRUCTURE de chaque cas difficile.
 */
final class LegacyOfficeFixture
{
    private const SECTOR = 512;
    private const END = 0xFFFFFFFE;
    private const FREE = 0xFFFFFFFF;
    private const FATSECT = 0xFFFFFFFD;

    /**
     * Conteneur MS-CFB (version 3, secteurs de 512 octets, sans mini-flux :
     * chaque flux est complété à 4096 octets). `$storages` : dossiers sous
     * la racine, chacun avec ses propres flux — et ses dossiers (une valeur
     * tableau est un dossier).
     *
     * @param array<string, string> $streams
     * @param array<string, array<string, string|array>> $storages
     */
    public static function cfb(array $streams, array $storages = []): string
    {
        // Entrées du répertoire : racine, puis flux et dossiers.
        $entries = [['name' => 'Root Entry', 'type' => 5, 'data' => null, 'children' => []]];
        $add = static function (string $name, int $type, ?string $data) use (&$entries): int {
            $entries[] = ['name' => $name, 'type' => $type, 'data' => $data, 'children' => []];
            return count($entries) - 1;
        };
        $fill = static function (int $parent, array $items) use (&$fill, &$entries, $add): void {
            foreach ($items as $name => $value) {
                if (is_array($value)) {
                    $id = $add((string)$name, 1, null);
                    $entries[$parent]['children'][] = $id;
                    $fill($id, $value);
                } else {
                    $entries[$parent]['children'][] = $add((string)$name, 2, str_pad($value, max(4096, strlen($value)), "\0"));
                }
            }
        };
        $fill(0, $streams);
        $fill(0, $storages);

        // Données : chaque flux sur des secteurs consécutifs.
        $body = '';
        $fat = [];
        foreach ($entries as $i => $e) {
            if ($e['data'] === null) {
                continue;
            }
            $n = intdiv(strlen($e['data']) + self::SECTOR - 1, self::SECTOR);
            $start = count($fat);
            for ($k = 0; $k < $n; $k++) {
                $fat[] = $k === $n - 1 ? self::END : $start + $k + 1;
            }
            $entries[$i]['start'] = $start;
            $body .= str_pad($e['data'], $n * self::SECTOR, "\0");
        }

        // Répertoire : les enfants d'un dossier chaînés par la droite.
        $dir = '';
        foreach ($entries as $i => $e) {
            $right = self::FREE;
            foreach ($entries as $parent) {
                $pos = array_search($i, $parent['children'], true);
                if ($pos !== false && isset($parent['children'][$pos + 1])) {
                    $right = $parent['children'][$pos + 1];
                }
            }
            $name = mb_convert_encoding($e['name'], 'UTF-16LE', 'UTF-8');
            $dir .= str_pad($name, 64, "\0")
                . pack('v', strlen($name) + 2)
                . chr($e['type']) . chr(1)
                . pack('VVV', self::FREE, $right, $e['children'][0] ?? self::FREE)
                . str_repeat("\0", 16 + 4 + 16)
                . pack('V', $e['data'] === null ? self::END : $e['start'])
                . pack('VV', $e['data'] === null ? 0 : strlen($e['data']), 0);
        }
        $dirSectors = intdiv(strlen($dir) + self::SECTOR - 1, self::SECTOR);
        $dirStart = count($fat);
        for ($k = 0; $k < $dirSectors; $k++) {
            $fat[] = $k === $dirSectors - 1 ? self::END : $dirStart + $k + 1;
        }
        $body .= str_pad($dir, $dirSectors * self::SECTOR, "\0");

        // FAT : assez de secteurs pour se décrire elle-même.
        $fatSectors = 1;
        while ((count($fat) + $fatSectors) > $fatSectors * 128) {
            $fatSectors++;
        }
        $fatStart = count($fat);
        for ($k = 0; $k < $fatSectors; $k++) {
            $fat[] = self::FATSECT;
        }
        $fatBytes = '';
        foreach ($fat as $v) {
            $fatBytes .= pack('V', $v);
        }
        $body .= str_pad($fatBytes, $fatSectors * self::SECTOR, pack('V', self::FREE));

        $difat = '';
        for ($k = 0; $k < 109; $k++) {
            $difat .= pack('V', $k < $fatSectors ? $fatStart + $k : self::FREE);
        }
        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16)
            . pack('vvvvv', 0x003E, 3, 0xFFFE, 9, 6) . str_repeat("\0", 6)
            . pack('VVVVVVVVV', 0, $fatSectors, $dirStart, 0, 4096, self::END, 0, self::END, 0)
            . $difat;
        return $header . $body;
    }

    /**
     * Flux « WordDocument » et « 1Table » d'un document Word 97 dont le texte
     * est fait des morceaux donnés, écrits dans le fichier en ordre INVERSE.
     * `compressed` : un octet par caractère (Windows-1252), sinon UTF-16.
     *
     * @param list<array{text:string, compressed:bool}> $pieces
     * @param array{text?:int, ftn?:int, hdd?:int, atn?:int, edn?:int, txbx?:int} $ccp
     * @return array{WordDocument:string, '1Table':string}
     */
    public static function word(array $pieces, array $ccp = [], bool $encrypted = false): array
    {
        $total = 0;
        foreach ($pieces as $p) {
            $total += mb_strlen($p['text']);
        }
        $cps = [];
        $at = 0;
        foreach ($pieces as $p) {
            $cps[] = $at;
            $at += mb_strlen($p['text']);
        }
        $cps[] = $at;

        $text = '';
        $fcs = [];
        foreach (array_reverse(array_keys($pieces)) as $k) {
            $p = $pieces[$k];
            $offset = 1024 + strlen($text);
            if ($p['compressed']) {
                $text .= mb_convert_encoding($p['text'], 'Windows-1252', 'UTF-8');
                $fcs[$k] = ($offset * 2) | 0x40000000;
            } else {
                $text .= mb_convert_encoding($p['text'], 'UTF-16LE', 'UTF-8');
                $fcs[$k] = $offset;
            }
        }
        ksort($fcs);

        $plc = '';
        foreach ($cps as $cp) {
            $plc .= pack('V', $cp);
        }
        foreach ($fcs as $fc) {
            $plc .= pack('vVv', 0, $fc, 0);
        }
        $clx = "\x01" . pack('v', 2) . "\x00\x00" . "\x02" . pack('V', strlen($plc)) . $plc;
        $table = str_repeat("\xAB", 16) . $clx;

        $lw = array_fill(0, 22, 0);
        $lw[3] = $ccp['text'] ?? $total;
        $lw[4] = $ccp['ftn'] ?? 0;
        $lw[5] = $ccp['hdd'] ?? 0;
        $lw[7] = $ccp['atn'] ?? 0;
        $lw[8] = $ccp['edn'] ?? 0;
        $lw[9] = $ccp['txbx'] ?? 0;
        $fcLcb = array_fill(0, 93 * 2, 0);
        $fcLcb[66] = 16;
        $fcLcb[67] = strlen($clx);

        $fib = pack('vv', 0xA5EC, 0x00C1) . str_repeat("\0", 6)
            . pack('v', 0x0200 | ($encrypted ? 0x0100 : 0)) . str_repeat("\0", 20)
            . pack('v', 14) . str_repeat("\0", 28)
            . pack('v', 22) . pack('V*', ...$lw)
            . pack('v', 93) . pack('V*', ...$fcLcb)
            . pack('v', 0);
        $wd = str_pad($fib, 1024, "\0") . $text;
        return ['WordDocument' => $wd, '1Table' => $table];
    }

    // ---- Excel : enregistrements BIFF -------------------------------

    public static function rec(int $type, string $data): string
    {
        return pack('vv', $type, strlen($data)) . $data;
    }

    /** Début de flux ou de feuille (BIFF8 : 0x0600, BIFF5 : 0x0500). */
    public static function bof(int $dt, bool $biff8 = true): string
    {
        return self::rec(0x0809, pack('vv', $biff8 ? 0x0600 : 0x0500, $dt) . str_repeat("\0", $biff8 ? 12 : 4));
    }

    public static function eof(): string
    {
        return self::rec(0x000A, '');
    }

    /** XLUnicodeString : longueur (2 octets), options, caractères. */
    public static function xlString(string $s, bool $wide = false): string
    {
        return pack('v', mb_strlen($s)) . ($wide
            ? "\x01" . mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')
            : "\x00" . mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8'));
    }

    /** RK d'un entier (sur 30 bits), éventuellement × 100. */
    public static function rkInt(int $v, bool $div100 = false): int
    {
        return (($v << 2) & 0xFFFFFFFC) | 0x02 | ($div100 ? 0x01 : 0);
    }

    /** RK d'un double dont les 34 bits de poids faible sont nuls. */
    public static function rkDouble(float $v, bool $div100 = false): int
    {
        $hi = unpack('V', substr(pack('e', $v), 4, 4))[1];
        return ($hi & 0xFFFFFFFC) | ($div100 ? 0x01 : 0);
    }
}
