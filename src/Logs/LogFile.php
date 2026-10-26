<?php

declare(strict_types=1);

/**
 * Accès au fichier `storage/logs/errors.log`, en lecture seule et BORNÉE.
 *
 * L'ancienne page faisait `file()` sur le fichier entier puis gardait les
 * 5 000 dernières lignes : un journal de 200 Mo était chargé en mémoire à
 * chaque affichage. On lit désormais la FIN du fichier (au plus MAX_BYTES),
 * et le suivi en direct ne relit que ce qui a été écrit depuis la lecture
 * précédente (position en octets).
 */
final class LogFile
{
    /** Ce qui est analysé à chaque affichage : la fin du fichier. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Suivi en direct : au-delà, on recharge la page plutôt que d'envoyer un pavé. */
    public const LIVE_MAX_BYTES = 1024 * 1024;

    /** Taille au-delà de laquelle l'écran suggère de faire tourner le journal. */
    public const LARGE_BYTES = 100 * 1024 * 1024;

    /** Fichier lu à la place du vrai journal (tests, banc d'essai). */
    private static ?string $pathOverride = null;

    public static function path(): string
    {
        return self::$pathOverride ?? LOG_DIR . 'errors.log';
    }

    public static function overridePath(?string $path): void
    {
        self::$pathOverride = $path;
    }

    /**
     * @return array{exists:bool,readable:bool,size:int,mtime:int}
     */
    public static function stat(string $path): array
    {
        clearstatcache(true, $path);
        $exists = is_file($path);
        return [
            'exists'   => $exists,
            'readable' => $exists && is_readable($path),
            'size'     => $exists ? (int)(@filesize($path) ?: 0) : 0,
            'mtime'    => $exists ? (int)(@filemtime($path) ?: 0) : 0,
        ];
    }

    /**
     * Fin du fichier.
     *
     * @return array{text:string,from:int,size:int,truncated:bool}
     */
    public static function tail(string $path, int $maxBytes = self::MAX_BYTES): array
    {
        $stat = self::stat($path);
        if (!$stat['readable'] || $stat['size'] === 0) {
            return ['text' => '', 'from' => 0, 'size' => $stat['size'], 'truncated' => false];
        }
        $size = $stat['size'];
        $from = max(0, $size - max(1, $maxBytes));
        $text = self::read($path, $from, $size - $from);
        return ['text' => $text, 'from' => $from, 'size' => $from + strlen($text), 'truncated' => $from > 0];
    }

    /**
     * Ce qui a été écrit depuis la position `$offset` — suivi en direct.
     * `reset` : le fichier a rétréci (vidé, remplacé) ; `overflow` : trop
     * écrit depuis, mieux vaut recharger la page.
     *
     * @return array{text:string,size:int,reset:bool,overflow:bool}
     */
    public static function since(string $path, int $offset, int $maxBytes = self::LIVE_MAX_BYTES): array
    {
        $stat = self::stat($path);
        $size = $stat['size'];
        if (!$stat['readable'] || $offset < 0 || $size < $offset) {
            return ['text' => '', 'size' => $size, 'reset' => true, 'overflow' => false];
        }
        if ($size === $offset) {
            return ['text' => '', 'size' => $size, 'reset' => false, 'overflow' => false];
        }
        if ($size - $offset > $maxBytes) {
            return ['text' => '', 'size' => $size, 'reset' => false, 'overflow' => true];
        }
        $text = self::read($path, $offset, $size - $offset);
        // Une ligne en cours d'écriture attend le prochain passage.
        $cut = strrpos($text, "\n");
        if ($cut === false) {
            return ['text' => '', 'size' => $offset, 'reset' => false, 'overflow' => false];
        }
        $text = substr($text, 0, $cut + 1);
        return ['text' => $text, 'size' => $offset + strlen($text), 'reset' => false, 'overflow' => false];
    }

    private static function read(string $path, int $from, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        try {
            if ($from > 0 && fseek($handle, $from) !== 0) {
                return '';
            }
            $text = stream_get_contents($handle, $length);
            return is_string($text) ? $text : '';
        } finally {
            fclose($handle);
        }
    }
}
