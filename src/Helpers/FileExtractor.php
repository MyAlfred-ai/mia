<?php

declare(strict_types=1);

class FileExtractor
{
    /**
     * Extensions → MIME canonique forcé (cf. detectMime). Couvre les formats où
     * libmagic est ambigu : conteneurs ZIP (OOXML/ODF/EPUB) et fichiers texte
     * (code, données structurées) souvent vus comme application/zip ou text/plain.
     */
    private const EXT_FORCED_MIME = [
        // Web / texte enrichi
        'html' => 'text/html', 'htm' => 'text/html',
        'rtf'  => 'application/rtf',
        // Données structurées + code → lus comme texte brut (chunkables tels quels)
        'csv' => 'text/plain', 'tsv' => 'text/plain', 'json' => 'text/plain',
        'xml' => 'text/plain', 'yaml' => 'text/plain', 'yml' => 'text/plain',
        'log' => 'text/plain', 'ini' => 'text/plain', 'conf' => 'text/plain',
        'toml' => 'text/plain', 'env' => 'text/plain',
        'php' => 'text/plain', 'js' => 'text/plain', 'mjs' => 'text/plain',
        'ts' => 'text/plain', 'jsx' => 'text/plain', 'tsx' => 'text/plain',
        'py' => 'text/plain', 'rb' => 'text/plain', 'go' => 'text/plain',
        'rs' => 'text/plain', 'java' => 'text/plain', 'kt' => 'text/plain',
        'c' => 'text/plain', 'cpp' => 'text/plain', 'cc' => 'text/plain',
        'h' => 'text/plain', 'hpp' => 'text/plain', 'cs' => 'text/plain',
        'sh' => 'text/plain', 'bash' => 'text/plain', 'sql' => 'text/plain',
        'css' => 'text/plain', 'scss' => 'text/plain', 'less' => 'text/plain',
        // OOXML présentation + OpenDocument + EPUB (conteneurs ZIP)
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'epub' => 'application/epub+zip',
    ];

    /**
     * Détecte le type MIME d'un fichier uploadé de façon robuste.
     *
     * `mime_content_type()` s'appuie sur libmagic et classifie souvent les DOCX
     * (et parfois XLSX) comme `application/zip` parce qu'ils reposent sur le
     * conteneur ZIP. Cette méthode :
     *   1. détecte via libmagic,
     *   2. si ZIP ou octet-stream, inspecte le contenu pour trouver le marqueur
     *      OOXML (`word/document.xml` ou `xl/worksheets/sheet*.xml`),
     *   3. en dernier recours, se rabat sur l'extension du nom fourni.
     */
    public static function detectMime(string $path, string $clientName = ''): string
    {
        $clientExt = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

        // Markdown : libmagic le rapporte presque toujours comme text/plain.
        // On force text/markdown quand l'extension est explicite, pour distinguer
        // du txt brut côté UI/extraction.
        if (in_array($clientExt, ['md', 'markdown'], true)) {
            return 'text/markdown';
        }

        // Extension fiable (upload admin) + libmagic ambigu → MIME canonique forcé.
        if (isset(self::EXT_FORCED_MIME[$clientExt])) {
            return self::EXT_FORCED_MIME[$clientExt];
        }

        $mime = (string)(@mime_content_type($path) ?: '');

        $ambiguous = [
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
            'application/octet-stream',
            '',
        ];
        if (in_array($mime, $ambiguous, true)) {
            $refined = self::detectOfficeOpenXmlMime($path);
            if ($refined !== null) {
                return $refined;
            }
        }

        if ($mime === '' || $mime === 'application/octet-stream') {
            $byExt = [
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pdf'  => 'application/pdf',
                'txt'  => 'text/plain',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
            ];
            if (isset($byExt[$clientExt])) {
                return $byExt[$clientExt];
            }
        }

        return $mime;
    }

    private static function detectOfficeOpenXmlMime(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $hasDoc   = $zip->locateName('word/document.xml') !== false;
        $hasSheet = false;
        $hasPpt   = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n === false) {
                continue;
            }
            if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $n)) {
                $hasSheet = true;
            } elseif (preg_match('#^ppt/slides/slide[0-9]+\.xml$#', $n)) {
                $hasPpt = true;
            }
        }
        // ODF / EPUB : le fichier `mimetype` (premier membre du ZIP) porte le MIME exact.
        $odfMime = $zip->getFromName('mimetype');
        $zip->close();

        if ($hasDoc)   return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        if ($hasSheet) return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        if ($hasPpt)   return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
        if (is_string($odfMime)) {
            $odfMime = trim($odfMime);
            if (str_starts_with($odfMime, 'application/vnd.oasis.opendocument.')
                || $odfMime === 'application/epub+zip') {
                return $odfMime;
            }
        }
        return null;
    }

    /**
     * Extensions à proposer dans un sélecteur de fichiers, par type MIME accepté
     * (cf. acceptAttribute). Un type absent d'ici est proposé par son MIME seul.
     */
    private const MIME_EXTENSIONS = [
        'image/jpeg'      => ['.jpg', '.jpeg'],
        'image/png'       => ['.png'],
        'image/gif'       => ['.gif'],
        'image/webp'      => ['.webp'],
        'application/pdf' => ['.pdf'],
        'text/plain'      => ['.txt'],
        'text/markdown'   => ['.md'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['.docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => ['.xlsx'],
    ];

    /**
     * Valeur de l'attribut `accept` d'un <input type="file"> pour une liste de
     * types MIME acceptés par le serveur (UPLOAD_ALLOWED_TYPES) — pure, testée.
     * Un seul endroit décide de ce que le sélecteur propose, et il suit la liste
     * du serveur : un type ajouté côté serveur apparaît dans le sélecteur.
     *
     * Forme : les types MIME d'abord, IMAGES EN TÊTE, puis les extensions.
     *
     *  - Des types MIME, pas des extensions seules : la WebView Android convertit
     *    les extensions par une table figée (`.md` en est absent) et Chrome
     *    Android par celle du système (qui ignore `md` avant Android 10) — un
     *    type déclaré en MIME est compris partout.
     *  - Images EN TÊTE, et ce n'est pas cosmétique : dans l'application Android
     *    (WebView Capacitor), le PREMIER type MIME devient le type de l'intent
     *    système ACTION_GET_CONTENT et décide des applications proposées. Un
     *    type document en tête (application/pdf) n'appelle que les explorateurs
     *    de fichiers — la galerie et le sélecteur de photos, qui ne déclarent
     *    que image/* et video/*, n'apparaissent pas. Un type image en tête fait
     *    venir galerie ET explorateurs (leur filtre * / * accepte tout), les
     *    autres types restant portés par EXTRA_MIME_TYPES. Les autres moteurs
     *    ignorent l'ordre : Chrome Android ouvre toujours un sélecteur générique,
     *    iOS une feuille Photothèque / Fichiers, un navigateur de bureau un
     *    filtre.
     *  - Jamais `image/*` (un joker est écarté) : sur iOS, WebKit livre alors les
     *    photos HEIC dans leur format d'origine au lieu de les convertir en
     *    JPEG — format que le serveur refuse.
     *  - Les extensions en plus : les sélecteurs de bureau (Windows, macOS)
     *    filtrent par extension.
     *
     * @param list<string> $allowedMimes
     */
    public static function acceptAttribute(array $allowedMimes): string
    {
        $mimes = [];
        foreach ($allowedMimes as $mime) {
            $mime = strtolower(trim((string)$mime));
            if ($mime === '' || str_contains($mime, '*') || in_array($mime, $mimes, true)) {
                continue;
            }
            $mimes[] = $mime;
        }
        $isImage = static fn(string $m): bool => str_starts_with($m, 'image/');
        $ordered = array_merge(
            array_values(array_filter($mimes, $isImage)),
            array_values(array_filter($mimes, static fn(string $m): bool => !$isImage($m))),
        );
        $extensions = [];
        foreach ($ordered as $mime) {
            foreach (self::MIME_EXTENSIONS[$mime] ?? [] as $ext) {
                if (!in_array($ext, $extensions, true)) {
                    $extensions[] = $ext;
                }
            }
        }
        return implode(',', array_merge($ordered, $extensions));
    }

    public static function extract(string $filePath, string $mimeType): ?string
    {
        return match(true) {
            $mimeType === 'text/plain'                      => @file_get_contents($filePath) ?: null,
            $mimeType === 'text/markdown'                   => @file_get_contents($filePath) ?: null,
            $mimeType === 'text/html'                       => self::extractHtml($filePath),
            $mimeType === 'application/rtf',
            $mimeType === 'text/rtf'                         => self::extractRtf($filePath),
            str_contains($mimeType, 'wordprocessingml')     => self::extractDocx($filePath),
            str_contains($mimeType, 'spreadsheetml')        => self::extractXlsx($filePath),
            str_contains($mimeType, 'presentationml')       => self::extractPptx($filePath),
            str_contains($mimeType, 'opendocument')         => self::extractOpenDocument($filePath),
            $mimeType === 'application/epub+zip'            => self::extractEpub($filePath),
            $mimeType === 'application/pdf'                 => self::extractPdf($filePath),
            // Images : envoyées directement à l'API, pas d'extraction texte
            default => null,
        };
    }

    private static function extractHtml(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        return self::cleanText(self::htmlToText($raw));
    }

    /** Convertit un fragment HTML en texte : retire script/style, garde les sauts. */
    private static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr|/table)\s*>#i', "\n", $html) ?? $html;
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Extraction texte d'un RTF (best-effort, sans dépendance) : décode les
     * octets \'hh (codepage Windows-1252), supprime groupes et mots de contrôle.
     */
    private static function extractRtf(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        // Groupes ignorables {\*\... } (polices, infos) → supprimés.
        $raw = preg_replace('/\{\\\\\*[^{}]*\}/s', ' ', $raw) ?? $raw;
        // \'hh → octet (codepage), converti ensuite en UTF-8.
        $raw = preg_replace_callback('/\\\\\'([0-9a-fA-F]{2})/', static fn(array $m): string => chr(hexdec($m[1])), $raw) ?? $raw;
        // Sauts de paragraphe/ligne.
        $raw = preg_replace('/\\\\(par|line|sect)\b ?/', "\n", $raw) ?? $raw;
        // Autres mots de contrôle \word, \word123 + accolades restantes.
        $raw = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', '', $raw) ?? $raw;
        $raw = str_replace(['{', '}'], '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            if (is_string($converted)) {
                $raw = $converted;
            }
        }
        return self::cleanText($raw);
    }

    /** PPTX : concatène le texte (<a:t>) de chaque diapositive, dans l'ordre. */
    private static function extractPptx(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n !== false && preg_match('#^ppt/slides/slide[0-9]+\.xml$#', $n)) {
                $slides[] = $n;
            }
        }
        sort($slides, SORT_NATURAL);

        $out = [];
        foreach ($slides as $n) {
            $xml = $zip->getFromName($n);
            if ($xml === false) {
                continue;
            }
            if (preg_match_all('#<a:t>(.*?)</a:t>#s', $xml, $m)) {
                $txt = implode(' ', array_map(
                    static fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    $m[1]
                ));
                if (trim($txt) !== '') {
                    $out[] = $txt;
                }
            }
        }
        $zip->close();

        return $out ? self::cleanText(implode("\n\n", $out)) : null;
    }

    /** OpenDocument (ODT/ODS/ODP) : texte de content.xml, balises retirées. */
    private static function extractOpenDocument(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            return null;
        }
        $xml = preg_replace('#</text:(p|h)>|</table:table-row>#', "\n", $xml) ?? $xml;
        $xml = str_replace('</table:table-cell>', "\t", $xml);
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return self::cleanText($text);
    }

    /** EPUB : concatène le texte des chapitres XHTML, dans l'ordre des noms. */
    private static function extractEpub(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n !== false && preg_match('#\.(xhtml|html|htm)$#i', $n)) {
                $files[] = $n;
            }
        }
        sort($files, SORT_NATURAL);

        $out = [];
        foreach ($files as $n) {
            $html = $zip->getFromName($n);
            if ($html === false) {
                continue;
            }
            $t = self::htmlToText($html);
            if (trim($t) !== '') {
                $out[] = $t;
            }
        }
        $zip->close();

        return $out ? self::cleanText(implode("\n\n", $out)) : null;
    }

    private static function extractDocx(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return null;

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) return null;

        $xml = str_replace(['</w:r>', '</w:p>'], [' ', "\n"], $xml);
        return self::cleanText(strip_tags($xml));
    }

    /**
     * Extraction texte depuis un XLSX (format Office Open XML).
     * Parcourt toutes les feuilles, résout les chaînes partagées (sharedStrings.xml),
     * produit un rendu tabulaire ligne par ligne, feuille par feuille.
     */
    private static function extractXlsx(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return null;

        // Chaînes partagées
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = @simplexml_load_string($ssXml);
            if ($ss !== false) {
                foreach ($ss->si as $si) {
                    // Concaténation de toutes les runs de texte dans l'élément <si>
                    $text = '';
                    foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                        $text .= (string)$t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // Feuilles
        $pieces = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || !preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $name)) {
                continue;
            }
            $sheetXml = $zip->getFromName($name);
            if ($sheetXml === false) continue;

            $sheet = @simplexml_load_string($sheetXml);
            if ($sheet === false) continue;

            $rows = [];
            foreach ($sheet->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c ?? [] as $c) {
                    $type  = (string)($c['t'] ?? '');
                    $value = '';
                    if (isset($c->v)) {
                        $raw = (string)$c->v;
                        if ($type === 's') {
                            $idx = (int)$raw;
                            $value = $sharedStrings[$idx] ?? '';
                        } elseif ($type === 'b') {
                            $value = $raw === '1' ? 'TRUE' : 'FALSE';
                        } else {
                            $value = $raw;
                        }
                    } elseif (isset($c->is)) {
                        // Inline string
                        foreach ($c->is->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                            $value .= (string)$t;
                        }
                    }
                    $value = str_replace(["\t", "\r", "\n"], ' ', $value);
                    $cells[] = $value;
                }
                if ($cells) {
                    $rows[] = implode("\t", $cells);
                }
            }
            if ($rows) {
                $label = preg_replace('#^xl/worksheets/#', '', $name);
                $pieces[] = '# ' . $label . "\n" . implode("\n", $rows);
            }
        }
        $zip->close();

        if (!$pieces) return null;
        return self::cleanText(implode("\n\n", $pieces));
    }

    /**
     * Extraction texte d'un PDF.
     * Essaie dans l'ordre :
     *   1. la lib PHP `smalot/pdfparser` (gère les encodages + CMap),
     *   2. le binaire `pdftotext` (poppler-utils) s'il est disponible,
     *   3. un fallback natif best-effort (ASCII seulement, perd les accents).
     */
    private static function extractPdf(string $path): ?string
    {
        $text = self::extractPdfViaSmalot($path);
        if ($text !== null && trim($text) !== '') {
            return self::cleanText($text);
        }

        $text = self::extractPdfViaPdftotext($path);
        if ($text !== null && trim($text) !== '') {
            return self::cleanText($text);
        }

        $text = self::extractPdfFallback($path);
        if ($text !== null && trim($text) !== '') {
            return self::cleanText($text);
        }
        return null;
    }

    private static function extractPdfViaSmalot(string $path): ?string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return null;
        }
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($path);
            $text   = $pdf->getText();
            return is_string($text) && $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            error_log('[FileExtractor] smalot/pdfparser a échoué : ' . $e->getMessage());
            return null;
        }
    }

    private static function extractPdfViaPdftotext(string $path): ?string
    {
        // Cherche le binaire; désactivé si exec/proc_open est interdit.
        if (!function_exists('proc_open')) return null;

        $which = @shell_exec('command -v pdftotext 2>/dev/null');
        if (!$which || trim((string)$which) === '') return null;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = 'pdftotext -layout -nopgbrk ' . escapeshellarg($path) . ' -';
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) return null;

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $stdout !== '' ? $stdout : null;
    }

    /**
     * Fallback natif PHP : extrait les chaînes encapsulées dans les flux PDF
     * (opérateurs Tj, TJ). Pas parfait pour les PDF complexes / compressés lourds,
     * mais suffisant pour la majorité des PDF texte bureautiques.
     */
    private static function extractPdfFallback(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return null;

        // Décompresse les flux FlateDecode si possible.
        $decoded = preg_replace_callback(
            '#<<[^>]*?/Filter\s*/FlateDecode[^>]*?>>\s*stream\r?\n(.*?)\r?\nendstream#s',
            static function (array $m): string {
                $data = @gzuncompress($m[1]);
                return $data !== false ? $data : $m[1];
            },
            $raw
        );
        if (!is_string($decoded)) $decoded = $raw;

        $out = [];

        // Tj : ( ... ) Tj
        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/s', $decoded, $m)) {
            foreach ($m[1] as $s) $out[] = self::unescapePdfString($s);
        }
        // TJ : [(...) ... (...)] TJ
        if (preg_match_all('/\[((?:[^\[\]]|(?R))*)\]\s*TJ/s', $decoded, $m)) {
            foreach ($m[1] as $arr) {
                if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/s', $arr, $mm)) {
                    $buf = '';
                    foreach ($mm[1] as $s) $buf .= self::unescapePdfString($s);
                    $out[] = $buf;
                }
            }
        }

        if (!$out) return null;
        return implode("\n", $out);
    }

    private static function unescapePdfString(string $s): string
    {
        $replacements = [
            '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
            '\\b' => "\x08", '\\f' => "\x0C",
            '\\(' => '(',  '\\)' => ')', '\\\\' => '\\',
        ];
        return strtr($s, $replacements);
    }

    private static function cleanText(string $text): string
    {
        // Garantit un encodage UTF-8 valide. Les PDF extraits via smalot/pdfparser
        // ou via le fallback natif peuvent contenir des séquences d'octets invalides
        // qui feraient échouer silencieusement json_encode() côté adapter LLM.
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Convertit depuis UTF-8 avec substitution des octets invalides.
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            mb_substitute_character($prev);
            if (is_string($converted)) {
                $text = $converted;
            }
        }

        // Retire les caractères de contrôle non imprimables (y compris NUL).
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    public static function toBase64(string $filePath, string $mimeType): string
    {
        return 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($filePath));
    }
}
