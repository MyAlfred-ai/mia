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
 */
final class ImageBytes
{
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
}
