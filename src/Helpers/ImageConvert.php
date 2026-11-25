<?php

declare(strict_types=1);

/**
 * Images que les modèles ne lisent pas (HEIC/HEIF des iPhone et des Mac,
 * AVIF, TIFF des scanners, BMP) converties en JPEG à l'envoi, AVANT
 * l'enregistrement : la suite (conversation, base de connaissances, documents
 * d'assistant, workflow) ne voit qu'une image JPEG ordinaire.
 *
 * Deux moteurs : GD pour le BMP (et l'AVIF s'il le lit), ImageMagick en ligne
 * de commande pour le reste — installé dans l'image Docker. Sans lui (poste
 * de développement), la conversion échoue proprement et l'envoi est refusé
 * avec un message qui dit quoi faire.
 *
 * ImageMagick lit des centaines de formats, dont certains exécutent du code
 * (MVG, MSL, SVG…) : il n'est appelé que sur un fichier que libmagic a
 * reconnu, avec le CODEUR IMPOSÉ en préfixe (`heic:`, `tiff:`…) — jamais
 * deviné d'après le contenu —, sans shell, et des ressources bornées.
 */
final class ImageConvert
{
    /** Type reconnu → codeur ImageMagick imposé. */
    private const CODERS = [
        'image/heic'          => 'heic',
        'image/heif'          => 'heic',
        'image/heic-sequence' => 'heic',
        'image/heif-sequence' => 'heic',
        'image/avif'          => 'avif',
        'image/tiff'          => 'tiff',
        'image/bmp'           => 'bmp',
        'image/x-ms-bmp'      => 'bmp',
        'image/x-bmp'         => 'bmp',
    ];

    /** Plus grand côté de l'image convertie (comme les images de référence). */
    public const MAX_SIDE = 4096;

    /** Qualité JPEG. */
    private const QUALITY = 88;

    /** Durée d'une conversion, au plus (secondes). */
    private const TIMEOUT = 30;

    /** JPEG produit, au plus. */
    private const MAX_OUTPUT = 32 * 1024 * 1024;

    /** Commande ImageMagick de substitution (tests), ou null. */
    private static ?string $binary = null;

    public static function isConvertible(string $mime): bool
    {
        return isset(self::CODERS[strtolower($mime)]);
    }

    /**
     * Fichier envoyé : converti en JPEG s'il le faut (sur place), avec son
     * nouveau type et son nouveau nom. Erreur présentable si la conversion
     * échoue ; rien ne change pour une image ordinaire ou un document.
     *
     * @return array{0: string, 1: string, 2: ?string} type, nom, erreur
     */
    public static function forUpload(string $path, string $name, string $mime): array
    {
        if (!self::isConvertible($mime)) {
            return [$mime, $name, null];
        }
        if (!self::toJpegInPlace($path, $mime)) {
            return [$mime, $name, t('L’image « {nom} » n’a pas pu être convertie en JPEG : enregistrez-la en JPEG ou en PNG, puis joignez-la à nouveau.', ['nom' => $name])];
        }
        return ['image/jpeg', self::jpegName($name), null];
    }

    /** « photo.HEIC » → « photo.jpg ». */
    public static function jpegName(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        return ($base !== '' ? $base : 'image') . '.jpg';
    }

    /** Convertit le fichier en JPEG, sur place. Faux si aucun moteur n'y arrive. */
    public static function toJpegInPlace(string $path, string $mime): bool
    {
        $jpeg = self::viaGd($path, strtolower($mime)) ?? self::viaImageMagick($path, strtolower($mime));
        if ($jpeg === null || !str_starts_with($jpeg, "\xFF\xD8")) {
            return false;
        }
        return @file_put_contents($path, $jpeg) !== false;
    }

    /** Tests : commande ImageMagick à employer ('' = aucune), null = recherche normale. */
    public static function overrideBinary(?string $binary): void
    {
        self::$binary = $binary;
    }

    private static function viaGd(string $path, string $mime): ?string
    {
        $coder = self::CODERS[$mime] ?? '';
        $open = match ($coder) {
            'bmp'  => function_exists('imagecreatefrombmp') ? 'imagecreatefrombmp' : null,
            'avif' => function_exists('imagecreatefromavif') ? 'imagecreatefromavif' : null,
            default => null,
        };
        if ($open === null || !function_exists('imagejpeg')) {
            return null;
        }
        $img = @$open($path);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, self::MAX_SIDE / max(1, $w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        // Fond blanc : un JPEG n'a pas de transparence.
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, (int)imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($out, null, self::QUALITY);
        $bytes = (string)ob_get_clean();
        return $bytes !== '' ? $bytes : null;
    }

    private static function viaImageMagick(string $path, string $mime): ?string
    {
        $coder = self::CODERS[$mime] ?? null;
        $bin = self::binary();
        if ($coder === null || $bin === null || !function_exists('proc_open')) {
            return null;
        }
        $cmd = [
            $bin,
            '-limit', 'memory', '256MiB', '-limit', 'map', '512MiB', '-limit', 'disk', '1GiB',
            '-limit', 'width', '30KP', '-limit', 'height', '30KP', '-limit', 'time', (string)self::TIMEOUT,
            // Codeur imposé, première image seulement (TIFF de plusieurs pages, séquence HEIC).
            $coder . ':' . $path . '[0]',
            '-auto-orient', '-strip',
            '-resize', self::MAX_SIDE . 'x' . self::MAX_SIDE . '>',
            '-background', 'white', '-flatten',
            '-quality', (string)self::QUALITY,
            'jpeg:-',
        ];
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + self::TIMEOUT + 5;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $r) {
                $chunk = (string)fread($r, 65536);
                if ($r === $pipes[1]) {
                    $out .= $chunk;
                }
            }
            if (strlen($out) > self::MAX_OUTPUT || microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $out = '';
                break;
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || $out === '') {
            error_log('[Fichiers] conversion d\'image ' . $coder . ' impossible (code ' . $code . ')');
            return null;
        }
        return $out;
    }

    /** Commande ImageMagick disponible (`magick`, sinon `convert`), ou null. */
    private static function binary(): ?string
    {
        if (self::$binary !== null) {
            return self::$binary !== '' ? self::$binary : null;
        }
        foreach (['/usr/bin/magick', '/usr/local/bin/magick', '/opt/homebrew/bin/magick', '/usr/bin/convert', '/usr/local/bin/convert'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
