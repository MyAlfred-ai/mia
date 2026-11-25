<?php

declare(strict_types=1);

/**
 * Octets d'une image : leur type réel, et une version JPEG allégée.
 *
 * Une image générée arrive en PNG de 1 à 3 Mo : jointe telle quelle à un
 * email, elle remplit à elle seule la limite d'une pièce jointe
 * (OutgoingAttachments::MAX_BYTES). La version JPEG (fond blanc sous la
 * transparence, plus grand côté borné) pèse dix fois moins pour un rendu
 * identique à l'écran. Sans GD, ou sur une image illisible : null — l'appelant
 * garde l'original.
 *
 * Et une COPIE BORNÉE d'un fichier image (`boundedFile`), pour Dompdf : son
 * traitement d'un PNG transparent sépare le canal alpha pixel par pixel, en
 * PHP, dans deux images GD de la taille de l'original. Un logo d'entreprise de
 * 6 319 × 3 224 pixels (cas réel, recette du 2026-11-04) épuisait les 256 Mo
 * de PHP — erreur FATALE, que rien ne rattrape : le téléchargement d'un PDF
 * finissait sur « Une erreur interne est survenue », quel que soit le texte.
 */
final class ImageBytes
{
    /** Plus grand côté d'un logo remis à Dompdf (en-tête de 9 mm, logo de slide). */
    public const PRINT_LOGO_SIDE = 800;

    /** Plus grand côté d'une illustration remise à Dompdf (pleine slide comprise). */
    public const PRINT_IMAGE_SIDE = 2000;

    /** Au-delà, l'image n'est jamais décodée, quelle que soit la mémoire. */
    public const DECODE_MAX_PIXELS = 50_000_000;

    /**
     * Mémoire de PHP consommée par pixel au décodage d'un PNG par GD, pic
     * compris (mesuré : 176 Mo pour 6 319 × 3 224 pixels, soit 8,6 octets).
     */
    private const DECODE_BYTES_PER_PIXEL = 9;

    /** Marge laissée au reste de la requête pendant le décodage. */
    private const DECODE_MARGIN_BYTES = 24 * 1024 * 1024;

    /**
     * Le fichier lui-même s'il tient déjà dans `$maxSide`, sinon une copie
     * réduite (proportions et transparence gardées), mise en cache disque
     * d'après le fichier d'origine (chemin, taille, date) : un logo n'est
     * réduit qu'une fois. Null si l'image ne se lit pas ou ne peut pas être
     * réduite (GD absent, image démesurée, cache non inscriptible) :
     * l'appelant s'en passe — un document sans logo vaut mieux qu'un échec.
     */
    public static function boundedFile(string $path, int $maxSide, ?string $cacheDir = null): ?string
    {
        $size = is_file($path) ? @getimagesize($path) : false;
        if (!is_array($size) || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
            return null;
        }
        [$w, $h] = [(int)$size[0], (int)$size[1]];
        $maxSide = max(16, $maxSide);
        if (max($w, $h) <= $maxSide) {
            return $path;
        }
        if (!self::decodable($w, $h) || !function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            error_log('[Fichiers] image écartée (' . $w . '×' . $h . ' px, non réductible) : ' . basename($path));

            return null;
        }

        $jpeg = ($size[2] ?? 0) === IMAGETYPE_JPEG;
        $dir = rtrim($cacheDir ?? (defined('ROOT') ? ROOT : dirname(__DIR__, 2)) . '/storage/cache/print-images', '/');
        $key = sha1((string)(realpath($path) ?: $path) . '|' . (int)@filesize($path) . '|' . (int)@filemtime($path) . '|' . $maxSide);
        $out = $dir . '/' . substr($key, 0, 24) . ($jpeg ? '.jpg' : '.png');
        if (is_file($out) && (int)@filesize($out) > 0) {
            return $out;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('[Fichiers] cache des images non inscriptible : ' . $dir);

            return null;
        }

        $src = @imagecreatefromstring((string)@file_get_contents($path));
        if ($src === false) {
            return null;
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        $scale = $maxSide / max($w, $h);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return null;
        }
        if ($jpeg) {
            imagefill($dst, 0, 0, (int)imagecolorallocate($dst, 255, 255, 255));
        } else {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, (int)imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        unset($src);

        // Écriture atomique : deux requêtes simultanées ne lisent jamais une copie à moitié écrite.
        $tmp = $out . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = $jpeg ? @imagejpeg($dst, $tmp, 90) : @imagepng($dst, $tmp, 6);
        if (!$ok || !@rename($tmp, $out)) {
            @unlink($tmp);
            error_log('[Fichiers] copie réduite impossible à écrire : ' . $out);

            return null;
        }

        return $out;
    }

    /** Type MIME réel d'une image (lu dans ses octets), sinon `image/png`. */
    public static function mime(string $bytes): string
    {
        if ($bytes !== '' && function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f !== false) {
                $mime = (string)finfo_buffer($f, $bytes);
                if (str_starts_with($mime, 'image/')) {
                    return $mime;
                }
            }
        }
        return 'image/png';
    }

    /** Extension de fichier d'un type d'image. */
    public static function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'png',
        };
    }

    /** JPEG de l'image, plus grand côté ramené à `$maxSide`. */
    public static function jpeg(string $bytes, int $maxSide = 1600, int $quality = 82): ?string
    {
        if ($bytes === '' || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }
        // Une image démesurée épuiserait la mémoire au décodage (erreur
        // FATALE, cf. decodable) : elle est écartée, jamais un plantage.
        $size = @getimagesizefromstring($bytes);
        if (is_array($size) && !self::decodable((int)($size[0] ?? 0), (int)($size[1] ?? 0))) {
            error_log('[Fichiers] image écartée (' . (int)$size[0] . '×' . (int)$size[1] . ' px, trop grande pour être réduite)');
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxSide / max(1, $w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return null;
        }
        imagefill($dst, 0, 0, (int)imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        $ok = imagejpeg($dst, null, max(40, min(95, $quality)));
        $out = (string)ob_get_clean();
        // Pas d'imagedestroy() : les objets GdImage se libèrent seuls (PHP 8).
        return $ok && $out !== '' ? $out : null;
    }

    /**
     * L'image peut-elle être décodée sans dépasser la mémoire de PHP ? Un
     * dépassement est une erreur FATALE : mieux vaut s'en passer.
     */
    public static function decodable(int $w, int $h): bool
    {
        if ($w < 1 || $h < 1 || $w * $h > self::DECODE_MAX_PIXELS) {
            return false;
        }
        $limit = self::bytes((string)ini_get('memory_limit'));
        if ($limit <= 0) {
            return true;
        }

        return memory_get_usage(true) + $w * $h * self::DECODE_BYTES_PER_PIXEL + self::DECODE_MARGIN_BYTES < $limit;
    }

    /** « 256M », « 1G », « 524288 » → octets ; -1 (illimité) → -1. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $n = (int)$value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $n * 1024 * 1024 * 1024,
            'm'     => $n * 1024 * 1024,
            'k'     => $n * 1024,
            default => $n,
        };
    }
}
