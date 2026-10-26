<?php

declare(strict_types=1);

/**
 * Sert les médias générés (`/assets/img/generated/`, `/assets/video/generated/`)
 * sous contrôle d'accès. Ces dossiers ne sont plus servis en statique par Apache
 * (cf. public/.htaccess) : chaque requête passe ici.
 *
 * Deux preuves d'accès acceptées, l'une OU l'autre :
 *   1. Session valide — le trafic same-origin (chat inline, galerie, page de
 *      partage, WebView mobile) porte le cookie. Tout utilisateur CONNECTÉ peut
 *      lire un média : c'est exactement la promesse de l'interface (« visible
 *      uniquement par les utilisateurs connectés »), et déjà le modèle de /share.
 *   2. URL signée (`?e=&s=`, cf. MediaUrl) — le SEUL mécanisme pour les aperçus
 *      en iframe `blob:` (origine opaque → aucun cookie), et pour les liens qui
 *      doivent voyager sans devenir un accès permanent.
 *
 * Sinon 403. Jamais de redirection vers /login : une `<img>` ne la suivrait pas,
 * et un 302 vers une page HTML dans un flux binaire n'a aucun sens.
 */
class MediaController
{
    /** Types d'URL → sous-dossier réel sous public/assets/. */
    private const DOSSIERS = [
        'img'   => '/public/assets/img/generated',
        'video' => '/public/assets/video/generated',
    ];

    public function serve(string $type, string $name): void
    {
        $dossierRel = self::DOSSIERS[$type] ?? null;
        if ($dossierRel === null) {
            $this->notFound();
            return;
        }

        // Nom de fichier : un seul segment, jeu de caractères clos. Écarte les
        // `..`, les `/` et l'octet nul avant toute résolution de chemin.
        if (preg_match('#^[A-Za-z0-9._-]+$#', $name) !== 1 || str_contains($name, '..')) {
            $this->notFound();
            return;
        }

        $pathWeb = '/assets/' . $type . '/generated/' . $name;

        // Contrôle d'accès : session OU signature. Rien d'autre.
        $autorise = Session::isAuthenticated()
            || MediaUrl::verify($pathWeb, $_GET['e'] ?? null, $_GET['s'] ?? null);
        if (!$autorise) {
            $this->forbidden();
            return;
        }

        // Confinement dur : realpath des deux côtés, le fichier doit être un
        // enfant réel du dossier (un lien symbolique sortant est rejeté).
        $base = realpath(ROOT . $dossierRel);
        $real = realpath(ROOT . $dossierRel . '/' . $name);
        if ($base === false || $real === false || !is_file($real)
            || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            $this->notFound();
            return;
        }

        $this->stream($real, $type);
    }

    /**
     * Envoie le fichier. Les vidéos honorent l'en-tête `Range` (lecture avec
     * déplacement dans un `<video>`) ; sans lui, certains navigateurs refusent
     * de lire un flux non seekable.
     */
    private function stream(string $real, string $type): void
    {
        $size = @filesize($real);
        if ($size === false) {
            $this->notFound();
            return;
        }

        $mime = $this->mime($real, $type);

        // Contrôle d'accès → jamais de cache partagé. `private` autorise le cache
        // du navigateur (une URL signée reste valide le temps de son TTL).
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=' . MediaUrl::TTL);
        header('Accept-Ranges: bytes');
        // Neutralise le `X-Frame-Options: DENY` global : ces médias sont
        // légitimement affichés dans l'iframe d'aperçu (blob:, même origine
        // logique). L'aperçu reste sandboxé, la CSP de la page mère tient.
        header_remove('X-Frame-Options');

        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($type === 'video' && is_string($range) && preg_match('#^bytes=(\d*)-(\d*)$#', $range, $m)) {
            $this->streamRange($real, $size, $m[1], $m[2]);
            return;
        }

        header('Content-Length: ' . $size);
        if ($this->clientHasFreshCopy($real, $size)) {
            http_response_code(304);
            return;
        }
        $this->sendFile($real, 0, $size - 1);
    }

    /** Réponse 206 partielle pour un en-tête Range (une seule plage gérée). */
    private function streamRange(string $real, int $size, string $rawStart, string $rawEnd): void
    {
        $start = $rawStart === '' ? null : (int)$rawStart;
        $end   = $rawEnd === '' ? null : (int)$rawEnd;

        if ($start === null) {
            // Suffixe « bytes=-N » : les N derniers octets.
            $length = $end ?? 0;
            if ($length <= 0) {
                $this->rangeNotSatisfiable($size);
                return;
            }
            $start = max(0, $size - $length);
            $end   = $size - 1;
        } else {
            $end = $end === null ? $size - 1 : min($end, $size - 1);
        }

        if ($start > $end || $start >= $size) {
            $this->rangeNotSatisfiable($size);
            return;
        }

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . ($end - $start + 1));
        $this->sendFile($real, $start, $end);
    }

    /** Envoie une plage d'octets par blocs, sans charger le fichier en mémoire. */
    private function sendFile(string $real, int $start, int $end): void
    {
        $fh = @fopen($real, 'rb');
        if ($fh === false) {
            return;
        }
        // Les buffers de sortie retiendraient un gros binaire en mémoire.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($start > 0) {
            fseek($fh, $start);
        }
        $reste = $end - $start + 1;
        $bloc  = 262144; // 256 Ko
        while ($reste > 0 && !feof($fh) && !connection_aborted()) {
            $lu = fread($fh, (int)min($bloc, $reste));
            if ($lu === false || $lu === '') {
                break;
            }
            echo $lu;
            $reste -= strlen($lu);
            flush();
        }
        fclose($fh);
    }

    private function rangeNotSatisfiable(int $size): void
    {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
    }

    /**
     * Revalidation conditionnelle par ETag (taille + mtime). Le nom d'un média
     * généré est déjà unique par contenu, mais un `If-None-Match` évite de
     * renvoyer le corps quand le navigateur a déjà le fichier.
     */
    private function clientHasFreshCopy(string $real, int $size): bool
    {
        $etag = '"' . dechex($size) . '-' . dechex((int)@filemtime($real)) . '"';
        header('ETag: ' . $etag);
        $ifNone = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        return $ifNone !== '' && $ifNone === $etag;
    }

    private function mime(string $real, string $type): string
    {
        if ($type === 'video') {
            return 'video/mp4';
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = (string)finfo_buffer($finfo, (string)@file_get_contents($real, false, null, 0, 512));
                if (str_starts_with($detected, 'image/')) {
                    return $detected;
                }
            }
        }
        return 'application/octet-stream';
    }

    private function forbidden(): void
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Accès refusé.';
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Introuvable.';
    }
}
