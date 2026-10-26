<?php

declare(strict_types=1);

/**
 * Images d'un deck côté serveur : résolution du chemin disque et recadrage.
 *
 * Les exports PPTX et PDF placent l'image dans une boîte de proportions
 * imposées par la mise en page, alors que le rendu HTML utilise
 * `object-fit:cover` — c'est-à-dire un recadrage centré. Sans équivalent,
 * une illustration carrée étirée dans une boîte portrait sortirait déformée.
 * On recadre donc nous-mêmes avant l'export : les trois rendus montrent alors
 * la même image.
 */
final class SlideImage
{
    /**
     * Chemin disque d'une URL d'image de slide.
     *
     * La spec ne contient que des URL sous /assets/img/ (DeckSpec), mais on
     * revérifie ici : cette fonction ouvre des fichiers, elle ne fait pas
     * confiance à un contrôle situé ailleurs.
     */
    public static function resolvePath(string $url): ?string
    {
        if (!str_starts_with($url, '/assets/img/')) {
            return null;
        }

        $base = realpath(ROOT . '/public/assets/img');
        $real = realpath(ROOT . '/public' . $url);
        if ($base === false || $real === false || !is_file($real)) {
            return null;
        }
        if (strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            return null;
        }

        return $real;
    }

    /**
     * Boîte de l'image ENTIÈRE dans une boîte donnée (équivalent de
     * `object-fit:contain`) : mêmes unités que la boîte, centrée. Sert aux
     * logos, qu'un recadrage amputerait — et qu'il noircirait au passage :
     * `coverCrop` réécrit l'image sans canal alpha.
     *
     * @param string $anchor `center`, ou `left` : collée au bord gauche de la
     *                       boîte (un logo de pied de page s'aligne sur le texte)
     * @return array{0: float, 1: float, 2: float, 3: float} x, y, largeur, hauteur
     */
    public static function containBox(string $path, float $x, float $y, float $w, float $h, string $anchor = 'center'): array
    {
        $size = @getimagesize($path);
        if (!is_array($size) || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1 || $w <= 0 || $h <= 0) {
            return [$x, $y, $w, $h];
        }
        $scale = min($w / $size[0], $h / $size[1]);
        $fitW  = $size[0] * $scale;
        $fitH  = $size[1] * $scale;

        return [$anchor === 'left' ? $x : $x + ($w - $fitW) / 2, $y + ($h - $fitH) / 2, $fitW, $fitH];
    }

    /**
     * Recadre l'image au ratio demandé (équivalent de `object-fit:cover`) et
     * renvoie un fichier temporaire PNG. Renvoie le chemin d'origine si le
     * recadrage est inutile ou impossible : un export ne doit jamais échouer
     * pour une image.
     *
     * @param float $ratio largeur / hauteur de la boîte cible
     */
    public static function coverCrop(string $path, float $ratio, string $tempDir): string
    {
        if ($ratio <= 0 || !function_exists('imagecreatefromstring')) {
            return $path;
        }

        $data = @file_get_contents($path);
        if (!is_string($data) || $data === '') {
            return $path;
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return $path;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) {
            return $path;
        }

        // Écart de proportions négligeable (< 1 %) : l'étirement ne se verrait
        // pas, on évite une réécriture de fichier pour rien.
        $current = $w / $h;
        if (abs($current - $ratio) / $ratio < 0.01) {
            return $path;
        }

        if ($current > $ratio) {   // trop large → on rogne les côtés
            $cropW = (int)round($h * $ratio);
            $cropH = $h;
        } else {                   // trop haute → on rogne haut et bas
            $cropW = $w;
            $cropH = (int)round($w / $ratio);
        }
        $cropX = (int)max(0, round(($w - $cropW) / 2));
        $cropY = (int)max(0, round(($h - $cropH) / 2));

        $dst = imagecreatetruecolor(max(1, $cropW), max(1, $cropH));
        if ($dst === false) {
            return $path;
        }
        imagecopy($dst, $src, 0, 0, $cropX, $cropY, $cropW, $cropH);

        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        $out = rtrim($tempDir, '/') . '/deck-img-' . bin2hex(random_bytes(6)) . '.png';
        $ok  = @imagepng($dst, $out);

        return $ok && is_file($out) ? $out : $path;
    }
}
