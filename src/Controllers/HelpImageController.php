<?php

declare(strict_types=1);

/**
 * Les captures de la documentation, servies à l'assistant « MIA : mode d'emploi ».
 *
 * Les guides que lit l'assistant (docs/GUIDE-*.md) illustrent certaines procédures
 * par des captures d'écran, écrites `![description](/aide/images/<nom>.png)` ;
 * l'assistant les recopie dans sa réponse et le chat les affiche. Les fichiers sont
 * CEUX du wiki public (docs/user/images/) : une seule copie, publiée des deux côtés
 * — une seconde, sous public/, finirait par montrer un autre écran que le wiki.
 *
 * Aucune session exigée : ces images sont déjà publiques sur docs.myalfred.ai.
 * Le nom est confiné par motif (aucun chemin, aucune extension hors image) puis
 * par realpath : rien d'autre que ce dossier ne peut être lu.
 */
class HelpImageController
{
    public const DIR = 'docs/user/images';
    public const URL_PREFIX = '/aide/images/';

    private const TYPES = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
    ];

    /** Nom accepté : minuscules, chiffres, tirets, une extension d'image. Pur. */
    public static function isValidName(string $nom): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,80}\.(png|jpe?g|webp|svg)$/', $nom);
    }

    /** Chemin du fichier sur le disque, ou null s'il n'existe pas ou sort du dossier. */
    public static function pathFor(string $nom): ?string
    {
        if (!self::isValidName($nom)) {
            return null;
        }
        $dir  = realpath(ROOT . '/' . self::DIR);
        $file = realpath(ROOT . '/' . self::DIR . '/' . $nom);
        if ($dir === false || $file === false || !is_file($file)
            || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $file;
    }

    public function show(string $nom): void
    {
        $file = self::pathFor($nom);
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }
        $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mtime = (int) filemtime($file);
        $size  = (int) filesize($file);
        $etag  = '"' . substr(sha1($nom . '|' . $mtime . '|' . $size), 0, 20) . '"';

        header('Content-Type: ' . self::TYPES[$ext]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);
        if ($ext === 'svg') {
            // Une image SVG ouverte seule ne doit rien exécuter.
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . $size);
        readfile($file);
    }
}
