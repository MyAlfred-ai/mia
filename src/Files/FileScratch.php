<?php

declare(strict_types=1);

/**
 * Fichier de travail d'un fabricant — SOURCE UNIQUE de son emplacement.
 *
 * Les bibliothèques de classeurs et de documents n'écrivent que dans un
 * fichier : il en faut un, le temps de le relire. Il vit sous `storage/cache`
 * (volume Docker, donc hors de la couche éphémère du conteneur) et ne survit
 * jamais à la requête : `discard()` est appelé dans un `finally`, échec
 * compris.
 *
 * Dossier absent ou non inscriptible (volume monté en root, poste de
 * développement) → dossier temporaire du système. Un classeur qui ne se
 * fabrique pas parce qu'un dossier de cache appartient au mauvais compte
 * serait une panne sans rapport avec la demande — et sans message utile.
 */
final class FileScratch
{
    private const DIR = '/storage/cache/files';

    /** Chemin d'un fichier de travail neuf, au nom non devinable. */
    public static function path(string $prefix, string $ext): string
    {
        $dir = (defined('ROOT') ? ROOT : dirname(__DIR__, 2)) . self::DIR;

        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || !is_writable($dir)) {
            $dir = sys_get_temp_dir();
        }

        return rtrim($dir, '/\\') . '/' . $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    }

    /** Supprime le fichier de travail, s'il existe. Ne lève jamais. */
    public static function discard(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
