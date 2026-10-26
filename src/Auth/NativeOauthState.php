<?php

declare(strict_types=1);

/**
 * State OAuth « natif » pour l'app mobile — Drive et Gmail personnels.
 *
 * Dans l'app, le consentement Google s'ouvre dans le NAVIGATEUR SYSTÈME
 * (Google interdit ses écrans OAuth en WebView) : le retour sur
 * /auth/callback arrive donc dans un navigateur qui n'a PAS la session PHP
 * de la WebView. Le state classique (aléa stocké en session) est inopérant.
 *
 * Ici le state est PORTEUR : `{prefix}native_{userId}_{expiration}_{hmac}`,
 * signé HMAC-SHA256 avec APP_SECRET. Le callback peut identifier
 * l'utilisateur initiateur sans session. Garde-fous : TTL court, signature
 * vérifiée en temps constant, usage unique (marqueur en storage/cache —
 * un state rejoué est refusé).
 */
final class NativeOauthState
{
    /** Durée de validité d'un state (le consentement Google prend < 10 min). */
    private const TTL = 600;

    /** Le state appartient-il au flux natif de ce préfixe ? */
    public static function isNative(string $prefix, string $state): bool
    {
        return str_starts_with($state, $prefix . 'native_');
    }

    /** Génère un state signé pour l'utilisateur initiateur. */
    public static function make(string $prefix, int $userId): string
    {
        $exp = time() + self::TTL;
        $sig = hash_hmac('sha256', $prefix . '|' . $userId . '|' . $exp, APP_SECRET);
        return $prefix . 'native_' . $userId . '_' . $exp . '_' . $sig;
    }

    /**
     * Valide un state natif (format, expiration, signature, usage unique).
     * Retourne l'id utilisateur, ou null si invalide/expiré/déjà consommé.
     */
    public static function consume(string $prefix, string $state): ?int
    {
        if (!preg_match(
            '/^' . preg_quote($prefix . 'native_', '/') . '(\d{1,10})_(\d{10,11})_([a-f0-9]{64})$/',
            $state,
            $m
        )) {
            return null;
        }
        [, $uid, $exp, $sig] = $m;
        if ((int)$exp < time()) {
            return null;
        }
        $expected = hash_hmac('sha256', $prefix . '|' . $uid . '|' . $exp, APP_SECRET);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        // Usage unique : un state déjà consommé est refusé (anti-rejeu).
        $dir = ROOT . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $marker = $dir . '/oauth_state_' . sha1($state) . '.used';
        if (file_exists($marker)) {
            return null;
        }
        @file_put_contents($marker, (string)time());

        return (int)$uid;
    }
}
