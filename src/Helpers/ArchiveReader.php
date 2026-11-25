<?php

declare(strict_types=1);

/**
 * Archives ZIP : la liste de leur contenu, puis le texte des fichiers que
 * MIA sait lire (documents, tableurs, présentations, emails, texte), comme
 * s'ils avaient été joints un par un. Appelée par FileExtractor.
 *
 * Une archive peut être piégée (« bombe » : quelques Ko qui se décompressent
 * en Go). Rien n'est écrit sous le nom d'une entrée ; chaque fichier lu est
 * borné en taille réelle (lecture coupée, jamais la taille annoncée), le
 * total aussi, le taux de compression aussi, et une archive dans l'archive
 * n'est pas ouverte.
 */
final class ArchiveReader
{
    public const MIME = 'application/zip';

    /** Entrées examinées, au plus. */
    private const MAX_ENTRIES = 5000;

    /** Noms listés dans le texte. */
    private const MAX_LISTED = 200;

    /** Fichiers dont le texte est lu. */
    public const MAX_READ = 20;

    /** Taille décompressée d'un fichier lu, au plus. */
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    /** Total décompressé, au plus. */
    private const MAX_TOTAL_BYTES = 100 * 1024 * 1024;

    /** Taux de compression au-delà duquel une entrée est tenue pour piégée. */
    private const MAX_RATIO = 200;

    /** Texte gardé par fichier (caractères). */
    private const MAX_FILE_CHARS = 100000;

    /** Lecture en cours (une archive dans l'archive n'est pas ouverte). */
    private static bool $reading = false;

    /** Texte d'une archive ZIP, ou null (illisible, chiffrée, vide). */
    public static function zipText(string $path): ?string
    {
        if (self::$reading || !class_exists(ZipArchive::class)) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }
        self::$reading = true;
        try {
            return self::read($zip);
        } finally {
            self::$reading = false;
            $zip->close();
        }
    }

    private static function read(ZipArchive $zip): ?string
    {
        $files = [];
        $count = min($zip->numFiles, self::MAX_ENTRIES);
        for ($i = 0; $i < $count; $i++) {
            $st = $zip->statIndex($i);
            if (!is_array($st)) {
                continue;
            }
            $name = (string)$st['name'];
            if ($name === '' || str_ends_with($name, '/') || self::isNoise($name)) {
                continue;
            }
            $files[] = ['index' => $i, 'name' => $name, 'size' => (int)$st['size'], 'comp' => (int)$st['comp_size'],
                'encrypted' => (int)($st['encryption_method'] ?? 0) !== 0];
        }
        if ($files === []) {
            return null;
        }

        $names = array_map(static fn(array $f): string => $f['name'], array_slice($files, 0, self::MAX_LISTED));
        $more = count($files) - count($names);
        $out = '# Archive ZIP — ' . count($files) . ' fichier' . (count($files) > 1 ? 's' : '') . "\n"
            . 'Contenu : ' . implode(', ', $names) . ($more > 0 ? ' (+' . $more . ' autres)' : '');

        $read = 0;
        $total = 0;
        $skipped = [];
        foreach ($files as $f) {
            if ($read >= self::MAX_READ) {
                break;
            }
            $reason = self::refusal($f, $total);
            if ($reason !== null) {
                if ($reason !== '') {
                    $skipped[] = $f['name'] . ' (' . $reason . ')';
                }
                continue;
            }
            $bytes = self::entryBytes($zip, $f['index']);
            if ($bytes === null) {
                $skipped[] = $f['name'] . ' (trop volumineux une fois décompressé)';
                continue;
            }
            $total += strlen($bytes);
            $text = self::entryText($bytes, $f['name']);
            if ($text === null) {
                continue;
            }
            $read++;
            if (mb_strlen($text) > self::MAX_FILE_CHARS) {
                $text = mb_substr($text, 0, self::MAX_FILE_CHARS) . "\n[… fichier coupé : trop long pour être lu en entier]";
            }
            $out .= "\n\n## Fichier : " . $f['name'] . "\n" . $text;
        }
        if ($skipped !== []) {
            $out .= "\n\nNon lus : " . implode(', ', array_slice($skipped, 0, 50));
        }
        return $out;
    }

    /**
     * Pourquoi une entrée n'est pas lue : '' (silencieusement : image,
     * archive, format non lu), un motif à dire, ou null (elle se lit).
     *
     * @param array{name: string, size: int, comp: int, encrypted: bool} $f
     */
    private static function refusal(array $f, int $total): ?string
    {
        if ($f['encrypted']) {
            return 'protégé par un mot de passe';
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['zip', '7z', 'rar', 'gz', 'tgz', 'tar', 'jar'], true)) {
            return 'archive dans l\'archive';
        }
        if ($f['size'] > self::MAX_FILE_BYTES || $total + $f['size'] > self::MAX_TOTAL_BYTES) {
            return 'trop volumineux';
        }
        if ($f['comp'] > 0 && $f['size'] / $f['comp'] > self::MAX_RATIO && $f['size'] > 1024 * 1024) {
            return 'taux de compression suspect';
        }
        return null;
    }

    /** Octets d'une entrée, lus jusqu'à la borne (la taille annoncée peut mentir). */
    private static function entryBytes(ZipArchive $zip, int $index): ?string
    {
        $stream = $zip->getStreamIndex($index);
        if ($stream === false) {
            return null;
        }
        $bytes = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $bytes .= $chunk;
            if (strlen($bytes) > self::MAX_FILE_BYTES) {
                fclose($stream);
                return null;
            }
        }
        fclose($stream);
        return $bytes;
    }

    private static function entryText(string $bytes, string $name): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mia_zip_');
        if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
            return null;
        }
        try {
            $mime = FileExtractor::detectMime($tmp, basename($name));
            if (str_starts_with($mime, 'image/') || $mime === self::MIME || !FileExtractor::isExtractable($mime)) {
                return null;
            }
            $text = FileExtractor::extract($tmp, $mime);
            return $text !== null && trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /** Fichiers que personne n'a mis là exprès (macOS, Windows). */
    private static function isNoise(string $name): bool
    {
        $base = basename($name);
        return str_starts_with($name, '__MACOSX/') || $base === '.DS_Store' || $base === 'Thumbs.db'
            || $base === 'desktop.ini' || str_starts_with($base, '._');
    }
}
