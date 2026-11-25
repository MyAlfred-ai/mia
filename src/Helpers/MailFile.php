<?php

declare(strict_types=1);

/**
 * Emails enregistrés en fichier — `.eml` (MIME, RFC 5322) et `.msg`
 * (Outlook, conteneur MS-CFB lu par LegacyOffice) — lus en PHP pur. Source
 * unique de leur texte, appelée par FileExtractor : en-têtes (objet,
 * expéditeur, destinataires, date), corps (texte, sinon HTML ou RTF
 * converti), liste des pièces jointes, puis le texte de celles que MIA sait
 * lire — un email joint à un email compris, sur quelques niveaux.
 *
 * Tout est défensif : un fichier abîmé rend null, jamais une exception ; les
 * pièces jointes lues et la profondeur sont bornées.
 */
final class MailFile
{
    public const MIME_EML = 'message/rfc822';
    public const MIME_MSG = 'application/vnd.ms-outlook';

    /** Au-delà, un fichier .eml n'est pas lu. */
    public const MAX_BYTES = 40 * 1024 * 1024;

    /** Pièces jointes dont le texte est lu (les autres sont seulement nommées). */
    public const MAX_ATTACHMENTS_READ = 10;

    /** Texte gardé d'une pièce jointe (caractères). */
    public const MAX_ATTACHMENT_CHARS = 100000;

    /** Emails joints à des emails, au plus. */
    private const MAX_DEPTH = 3;

    /** Profondeur de lecture en cours (un email joint est lu par extract, qui revient ici). */
    private static int $depth = 0;

    // ==================================================================
    // Lecture
    // ==================================================================

    /** Texte d'un fichier .eml, ou null s'il n'en est pas un. */
    public static function emlText(string $path): ?string
    {
        $size = @filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            return null;
        }
        $raw = @file_get_contents($path);
        $mail = is_string($raw) ? self::parseEml($raw) : null;
        return $mail !== null ? self::render($mail) : null;
    }

    /** Texte d'un fichier .msg (Outlook), ou null s'il n'en est pas un. */
    public static function msgText(string $path): ?string
    {
        $size = @filesize($path);
        if ($size === false || $size < 512 || $size > LegacyOffice::MAX_BYTES) {
            return null;
        }
        $raw = @file_get_contents($path);
        $cfb = is_string($raw) ? LegacyOffice::open($raw) : null;
        $mail = $cfb !== null ? self::parseMsg($cfb, null, false) : null;
        return $mail !== null ? self::render($mail) : null;
    }

    /**
     * Un email lu, rendu en texte : en-têtes, corps, pièces jointes nommées,
     * puis le texte de celles qui se lisent.
     *
     * @param array{headers: array<string, string>, body: string, attachments: list<array{name: string, mime: string, bytes: string}>} $mail
     */
    public static function render(array $mail): string
    {
        $h = $mail['headers'];
        $out = ['# Email : ' . (($h['subject'] ?? '') !== '' ? $h['subject'] : '(sans objet)')];
        foreach (['from' => 'De', 'to' => 'À', 'cc' => 'Cc', 'date' => 'Date'] as $k => $label) {
            if (($h[$k] ?? '') !== '') {
                $out[] = $label . ' : ' . $h[$k];
            }
        }
        $body = trim($mail['body']);
        $text = implode("\n", $out) . "\n\n" . ($body !== '' ? $body : '(corps vide)');

        $atts = $mail['attachments'];
        if ($atts !== []) {
            $names = array_map(static fn(array $a): string => $a['name'], $atts);
            $text .= "\n\nPièces jointes : " . implode(', ', $names);
            $read = 0;
            foreach ($atts as $a) {
                if ($read >= self::MAX_ATTACHMENTS_READ) {
                    break;
                }
                $inner = self::attachmentText($a);
                if ($inner === null) {
                    continue;
                }
                $read++;
                if (mb_strlen($inner) > self::MAX_ATTACHMENT_CHARS) {
                    $inner = mb_substr($inner, 0, self::MAX_ATTACHMENT_CHARS)
                        . "\n[… pièce jointe coupée : trop longue pour être lue en entier]";
                }
                $text .= "\n\n## Pièce jointe : " . $a['name'] . "\n" . $inner;
            }
        }
        return $text;
    }

    /**
     * Texte d'une pièce jointe que MIA sait lire (document, texte, email
     * joint), ou null (image, format non lu, profondeur atteinte).
     *
     * @param array{name: string, mime: string, bytes: string} $a
     */
    private static function attachmentText(array $a): ?string
    {
        if ($a['bytes'] === '' || self::$depth >= self::MAX_DEPTH) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mia_mail_');
        if ($tmp === false || @file_put_contents($tmp, $a['bytes']) === false) {
            return null;
        }
        self::$depth++;
        try {
            $mime = $a['mime'] === self::MIME_EML ? self::MIME_EML : FileExtractor::detectMime($tmp, $a['name']);
            if (str_starts_with($mime, 'image/') || !FileExtractor::isExtractable($mime)) {
                return null;
            }
            $text = FileExtractor::extract($tmp, $mime);
            return $text !== null && trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable) {
            return null;
        } finally {
            self::$depth--;
            @unlink($tmp);
        }
    }

    // ==================================================================
    // .eml (MIME)
    // ==================================================================

    /**
     * Email MIME → en-têtes, corps (texte, sinon HTML converti), pièces
     * jointes. Null : pas un email (aucun en-tête reconnu). Pure.
     *
     * @return array{headers: array<string, string>, body: string, attachments: list<array{name: string, mime: string, bytes: string}>}|null
     */
    public static function parseEml(string $raw): ?array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headers, $body] = self::splitHeaders($raw);
        if (!isset($headers['from']) && !isset($headers['subject']) && !isset($headers['date']) && !isset($headers['to'])) {
            return null;
        }
        $parts = ['plain' => [], 'html' => [], 'attachments' => []];
        self::walkPart($headers, $body, $parts, 0);

        $text = implode("\n\n", array_filter($parts['plain'], static fn(string $s): bool => trim($s) !== ''));
        if (trim($text) === '' && $parts['html'] !== []) {
            $text = FileExtractor::htmlToPlain(implode("\n", $parts['html']));
        }
        return [
            'headers' => [
                'subject' => self::decodeHeader($headers['subject'] ?? ''),
                'from'    => self::decodeHeader($headers['from'] ?? ''),
                'to'      => self::decodeHeader($headers['to'] ?? ''),
                'cc'      => self::decodeHeader($headers['cc'] ?? ''),
                'date'    => self::formatDate($headers['date'] ?? ''),
            ],
            'body'        => self::cleanBody($text),
            'attachments' => $parts['attachments'],
        ];
    }

    /**
     * En-têtes (noms en minuscules, lignes de suite repliées) et corps.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function splitHeaders(string $raw): array
    {
        $at = strpos($raw, "\n\n");
        $head = $at === false ? $raw : substr($raw, 0, $at);
        $body = $at === false ? '' : substr($raw, $at + 2);
        $headers = [];
        $name = null;
        foreach (explode("\n", $head) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $name !== null) {
                $headers[$name] .= ' ' . trim($line);
                continue;
            }
            if (preg_match('/^([!-9;-~]+):\s*(.*)$/', $line, $m)) {
                $name = strtolower($m[1]);
                // Le premier l'emporte (un « Received » répété ne compte pas, un « Subject » non plus).
                if (!isset($headers[$name])) {
                    $headers[$name] = trim($m[2]);
                } else {
                    $name = '_';
                    $headers['_'] = '';
                }
            }
        }
        unset($headers['_']);
        return [$headers, $body];
    }

    /**
     * Une partie MIME : texte, HTML, pièce jointe, email joint ou conteneur
     * multipart (parcouru).
     *
     * @param array<string, string> $headers
     * @param array{plain: list<string>, html: list<string>, attachments: list<array{name: string, mime: string, bytes: string}>} $parts
     */
    private static function walkPart(array $headers, string $body, array &$parts, int $depth): void
    {
        [$type, $params] = self::parseValue($headers['content-type'] ?? 'text/plain');
        [$disposition, $dParams] = self::parseValue($headers['content-disposition'] ?? '');
        $name = self::paramName($dParams, 'filename') ?? self::paramName($params, 'name');

        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < 12) {
            foreach (self::splitMultipart($body, $params['boundary']) as $chunk) {
                [$h, $b] = self::splitHeaders($chunk);
                self::walkPart($h, $b, $parts, $depth + 1);
            }
            return;
        }

        $data = self::decodeTransfer($body, strtolower(trim($headers['content-transfer-encoding'] ?? '')));
        if ($type === 'message/rfc822') {
            $sub = self::parseEml($data);
            $subject = $sub !== null ? ($sub['headers']['subject'] ?? '') : '';
            $parts['attachments'][] = [
                'name'  => $name ?? (($subject !== '' ? $subject : 'email joint') . '.eml'),
                'mime'  => self::MIME_EML,
                'bytes' => $data,
            ];
            return;
        }
        $isAttachment = $disposition === 'attachment' || ($name !== null && !str_starts_with($type, 'text/'));
        if (!$isAttachment && ($type === 'text/plain' || $type === 'text/html')) {
            $text = self::toUtf8($data, $params['charset'] ?? '');
            $parts[$type === 'text/plain' ? 'plain' : 'html'][] = $text;
            return;
        }
        // Image collée dans le corps (sans nom) : ni pièce jointe ni texte.
        if ($name === null && ($disposition === 'inline' || str_starts_with($type, 'image/'))) {
            return;
        }
        if ($name === null && $data === '') {
            return;
        }
        $parts['attachments'][] = ['name' => $name ?? ('fichier.' . (explode('/', $type)[1] ?? 'bin')), 'mime' => $type, 'bytes' => $data];
    }

    /** @return list<string> */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $out = [];
        $current = null;
        foreach (explode("\n", $body) as $line) {
            $trim = rtrim($line);
            if ($trim === '--' . $boundary . '--') {
                break;
            }
            if ($trim === '--' . $boundary) {
                if ($current !== null) {
                    $out[] = $current;
                }
                $current = '';
                continue;
            }
            if ($current !== null) {
                $current .= $line . "\n";
            }
        }
        if ($current !== null) {
            $out[] = $current;
        }
        return array_map(static fn(string $p): string => substr($p, 0, -1) ?: '', $out);
    }

    /**
     * « type/sous-type; nom=valeur; … » → [valeur en minuscules, paramètres].
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function parseValue(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['', []];
        }
        $semi = strpos($value, ';');
        $main = strtolower(trim($semi === false ? $value : substr($value, 0, $semi)));
        $params = [];
        if ($semi !== false && preg_match_all('/;\s*([^=;\s]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', $value, $m, PREG_SET_ORDER)) {
            foreach ($m as $p) {
                $v = trim($p[2]);
                if (str_starts_with($v, '"')) {
                    $v = stripcslashes(substr($v, 1, -1));
                }
                $params[strtolower($p[1])] = $v;
            }
        }
        return [$main, $params];
    }

    /**
     * Nom de fichier d'un paramètre : RFC 2231 (`filename*=utf-8''…`, coupé en
     * `filename*0*=`, `filename*1*=`…) d'abord, sinon la forme simple (encodée
     * en RFC 2047).
     *
     * @param array<string, string> $params
     */
    private static function paramName(array $params, string $key): ?string
    {
        $pieces = [];
        foreach ($params as $k => $v) {
            if (preg_match('/^' . preg_quote($key, '/') . '\*(\d+)?(\*)?$/', $k, $m)) {
                $pieces[(int)($m[1] ?? 0)] = [$v, isset($m[2]) || !isset($m[1])];
            }
        }
        if ($pieces !== []) {
            ksort($pieces);
            $charset = 'UTF-8';
            $out = '';
            foreach ($pieces as $i => [$v, $encoded]) {
                if ($i === array_key_first($pieces) && $encoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
                    $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
                    $v = $m[2];
                }
                $out .= $encoded ? rawurldecode($v) : $v;
            }
            $name = self::toUtf8($out, $charset);
        } elseif (isset($params[$key])) {
            $name = self::decodeHeader($params[$key]);
        } else {
            return null;
        }
        $name = trim(basename(str_replace('\\', '/', $name)));
        return $name !== '' ? $name : null;
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    /** En-tête encodé (RFC 2047) ou en 8 bits → UTF-8. */
    public static function decodeHeader(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '=?')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $value = $decoded;
            }
        }
        return trim(preg_replace('/\s+/', ' ', self::toUtf8($value, '')) ?? $value);
    }

    private static function formatDate(string $value): string
    {
        $value = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? $value);
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);
        return $ts !== false ? gmdate('Y-m-d H:i', $ts) . ' UTC' : self::decodeHeader($value);
    }

    // ==================================================================
    // .msg (Outlook)
    // ==================================================================

    /**
     * Email Outlook → même forme que parseEml. `$node` : dossier d'un email
     * joint (null = la racine du fichier). Null : pas un email.
     *
     * @return array{headers: array<string, string>, body: string, attachments: list<array{name: string, mime: string, bytes: string}>}|null
     */
    public static function parseMsg(array $cfb, ?array $node, bool $embedded): ?array
    {
        $at = $node ?? $cfb;
        if (!isset($at['streams']['__properties_version1.0'])) {
            return null;
        }
        $str = static fn(string $tag): string => self::msgString($cfb, $at, $tag);

        $body = $str('1000');
        if (trim($body) === '') {
            $html = self::msgBinary($cfb, $at, '1013') ?? $str('1013');
            if ($html !== '') {
                $body = FileExtractor::htmlToPlain(self::toUtf8($html, ''));
            }
        }
        if (trim($body) === '') {
            $rtf = self::msgBinary($cfb, $at, '1009');
            $plain = $rtf !== null ? self::decompressRtf($rtf) : null;
            if ($plain !== null) {
                $body = FileExtractor::rtfToPlain($plain);
            }
        }

        [$to, $cc] = self::msgRecipients($cfb, $at);
        $from = $str('0c1a');
        $email = $str('5d01') ?: $str('0c1f') ?: $str('0065');
        if ($email !== '' && str_contains($email, '@') && !str_contains($from, $email)) {
            $from = $from !== '' ? $from . ' <' . $email . '>' : $email;
        }
        $date = self::msgTime($cfb, $at, $embedded ? 24 : 32, [0x00390040, 0x0E060040]);

        $attachments = [];
        foreach ($at['storages'] ?? [] as $name => $storage) {
            if (!str_starts_with($name, '__attach_version1.0_')) {
                continue;
            }
            $aStr = static fn(string $tag): string => self::msgString($cfb, $storage, $tag);
            $fileName = $aStr('3707') ?: $aStr('3704') ?: $aStr('3001');
            $inner = $storage['storages']['__substg1.0_3701000d'] ?? null;
            if ($inner !== null) {
                $sub = self::parseMsg($cfb, $inner, true);
                if ($sub !== null) {
                    $attachments[] = [
                        'name'  => $fileName !== '' ? $fileName : (($sub['headers']['subject'] ?: 'email joint') . '.eml'),
                        'mime'  => self::MIME_EML,
                        'bytes' => self::toEml($sub),
                    ];
                }
                continue;
            }
            $data = self::msgBinary($cfb, $storage, '3701');
            if ($data === null && $fileName === '') {
                continue;
            }
            $attachments[] = [
                'name'  => trim(basename(str_replace('\\', '/', $fileName))) ?: 'fichier joint',
                'mime'  => strtolower($aStr('370e')),
                'bytes' => (string)$data,
            ];
        }

        return [
            'headers' => [
                'subject' => trim($str('0037')),
                'from'    => trim($from),
                'to'      => $to !== '' ? $to : trim($str('0e04')),
                'cc'      => $cc !== '' ? $cc : trim($str('0e03')),
                'date'    => $date,
            ],
            'body'        => self::cleanBody($body),
            'attachments' => $attachments,
        ];
    }

    /** Propriété texte d'un email Outlook (Unicode, sinon 8 bits). */
    private static function msgString(array $cfb, array $node, string $tag): string
    {
        $u = LegacyOffice::stream($cfb, '__substg1.0_' . $tag . '001f', $node);
        if ($u !== null) {
            return rtrim(self::toUtf8($u, 'UTF-16LE'), "\0");
        }
        $a = LegacyOffice::stream($cfb, '__substg1.0_' . $tag . '001e', $node);
        return $a !== null ? rtrim(self::toUtf8($a, ''), "\0") : '';
    }

    private static function msgBinary(array $cfb, array $node, string $tag): ?string
    {
        return LegacyOffice::stream($cfb, '__substg1.0_' . $tag . '0102', $node);
    }

    /**
     * Destinataires d'un email Outlook, « Nom <adresse> », séparés en À et Cc.
     *
     * @return array{0: string, 1: string}
     */
    private static function msgRecipients(array $cfb, array $node): array
    {
        $to = [];
        $cc = [];
        foreach ($node['storages'] ?? [] as $name => $storage) {
            if (!str_starts_with($name, '__recip_version1.0_')) {
                continue;
            }
            $display = self::msgString($cfb, $storage, '3001');
            $email = self::msgString($cfb, $storage, '39fe') ?: self::msgString($cfb, $storage, '3003');
            if (!str_contains($email, '@')) {
                $email = '';
            }
            $who = $display !== '' && $email !== '' && $display !== $email ? $display . ' <' . $email . '>' : ($email ?: $display);
            if ($who === '') {
                continue;
            }
            $kind = self::msgLong($cfb, $storage, 8, 0x0C150003);
            if ($kind === 2) {
                $cc[] = $who;
            } elseif ($kind !== 3) {
                $to[] = $who;
            }
        }
        return [implode(', ', $to), implode(', ', $cc)];
    }

    /** Valeur 32 bits d'une propriété de taille fixe (flux `__properties_version1.0`). */
    private static function msgLong(array $cfb, array $node, int $headerSize, int $tag): ?int
    {
        $props = LegacyOffice::stream($cfb, '__properties_version1.0', $node);
        if ($props === null) {
            return null;
        }
        for ($o = $headerSize; $o + 16 <= strlen($props); $o += 16) {
            if (unpack('V', substr($props, $o, 4))[1] === $tag) {
                return unpack('V', substr($props, $o + 8, 4))[1];
            }
        }
        return null;
    }

    /**
     * Date d'un email Outlook (première des propriétés données, FILETIME), en UTC.
     *
     * @param list<int> $tags
     */
    private static function msgTime(array $cfb, array $node, int $headerSize, array $tags): string
    {
        $props = LegacyOffice::stream($cfb, '__properties_version1.0', $node);
        if ($props === null) {
            return '';
        }
        foreach ($tags as $tag) {
            for ($o = $headerSize; $o + 16 <= strlen($props); $o += 16) {
                if (unpack('V', substr($props, $o, 4))[1] !== $tag) {
                    continue;
                }
                $v = unpack('Plow', substr($props, $o + 8, 8))['low'];
                $unix = intdiv($v, 10000000) - 11644473600;
                if ($unix > 0 && $unix < 4102444800) {
                    return gmdate('Y-m-d H:i', $unix) . ' UTC';
                }
            }
        }
        return '';
    }

    /** Email lu → .eml minimal (texte seul) : lu à son tour comme pièce jointe. */
    private static function toEml(array $mail): string
    {
        $h = $mail['headers'];
        $lines = [];
        foreach (['subject' => 'Subject', 'from' => 'From', 'to' => 'To', 'cc' => 'Cc', 'date' => 'Date'] as $k => $name) {
            if (($h[$k] ?? '') !== '') {
                $lines[] = $name . ': ' . preg_replace('/[\r\n]+/', ' ', $h[$k]);
            }
        }
        return implode("\n", $lines) . "\nContent-Type: text/plain; charset=utf-8\n\n" . $mail['body'] . "\n";
    }

    /** Dictionnaire initial de la compression RTF d'Outlook (MS-OXRTFCP). */
    private const RTF_PREBUF = '{\rtf1\ansi\mac\deff0\deftab720{\fonttbl;}{\f0\fnil \froman \fswiss \fmodern \fscript \fdecor MS Sans SerifSymbolArialTimes New RomanCourier{\colortbl\red0\green0\blue0' . "\r\n" . '\par \pard\plain\f0\fs20\b\i\u\tab\tx';

    /**
     * Corps RTF compressé d'Outlook (« LZFu », ou « MELA » non compressé) →
     * RTF. Null si le flux est abîmé. Pure.
     */
    public static function decompressRtf(string $data): ?string
    {
        if (strlen($data) < 16) {
            return null;
        }
        $rawSize = unpack('V', substr($data, 4, 4))[1];
        $magic = substr($data, 8, 4);
        if ($magic === 'MELA') {
            return substr($data, 16, $rawSize);
        }
        if ($magic !== 'LZFu' || $rawSize > 64 * 1024 * 1024) {
            return null;
        }
        $dict = str_pad(self::RTF_PREBUF, 4096, "\0");
        $write = strlen(self::RTF_PREBUF);
        $out = '';
        $p = 16;
        $n = strlen($data);
        while ($p < $n) {
            $flags = ord($data[$p++]);
            for ($bit = 0; $bit < 8 && $p < $n; $bit++) {
                if (($flags >> $bit) & 1) {
                    if ($p + 1 >= $n) {
                        return $out;
                    }
                    $v = (ord($data[$p]) << 8) | ord($data[$p + 1]);
                    $p += 2;
                    $offset = $v >> 4;
                    $len = ($v & 0xF) + 2;
                    if ($offset === $write) {
                        return $out;
                    }
                    for ($i = 0; $i < $len; $i++) {
                        $c = $dict[($offset + $i) & 0xFFF];
                        $out .= $c;
                        $dict[$write] = $c;
                        $write = ($write + 1) & 0xFFF;
                    }
                } else {
                    $c = $data[$p++];
                    $out .= $c;
                    $dict[$write] = $c;
                    $write = ($write + 1) & 0xFFF;
                }
                if (strlen($out) > $rawSize + 4096) {
                    return $out;
                }
            }
        }
        return $out;
    }

    // ==================================================================
    // Formes communes
    // ==================================================================

    private static function toUtf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \t\"'"));
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            try {
                $c = @mb_convert_encoding($s, 'UTF-8', $charset);
                if (is_string($c)) {
                    $s = $c;
                }
            } catch (\ValueError) {
            }
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $s;
    }

    /** Corps : espaces de fin retirés, lignes vides en série ramenées à une. */
    private static function cleanBody(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
