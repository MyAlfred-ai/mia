<?php

declare(strict_types=1);

/**
 * Lancement d'un script de `scripts/` en tâche de fond.
 *
 * Un job n'est jamais perdu : si l'environnement interdit le fork (Windows,
 * `exec` désactivé), le travail est exécuté SYNCHRONEMENT via le callback de
 * secours fourni par l'appelant — un document ne reste jamais bloqué en
 * 'pending'. Le prix est alors une requête HTTP longue, ce qui reste
 * préférable à une ingestion qui n'aboutit jamais.
 */
class BackgroundJob
{
    /** Le fork POSIX (`&`) est-il utilisable dans cet environnement ? */
    public static function canSpawn(): bool
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            return false; // `&` POSIX uniquement → secours synchrone sous Windows
        }
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    /**
     * Lance `php <script> <arg>` détaché. Retombe sur $fallback si le fork est
     * indisponible.
     *
     * @param string        $script   chemin absolu du script de scripts/
     * @param int           $arg      identifiant passé au script
     * @param callable|null $fallback exécution synchrone de secours
     */
    public static function dispatch(string $script, int $arg, ?callable $fallback = null): void
    {
        if (self::canSpawn()) {
            // `php` depuis le PATH (PHP_BINARY pointe sur php-fpm en contexte web).
            @exec('php ' . escapeshellarg($script) . ' ' . $arg . ' > /dev/null 2>&1 &');
            return;
        }
        if ($fallback !== null) {
            $fallback($arg);
        }
    }
}
