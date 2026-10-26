<?php

declare(strict_types=1);

/**
 * Détection de l'application mobile native (wrapper Capacitor).
 *
 * L'app mobile charge le site distant dans sa WebView avec un User-Agent
 * marqué via `appendUserAgent` (capacitor.config.ts) : `myLLM-App/ios` ou
 * `myLLM-App/android`. Côté serveur, ce marqueur pilote :
 *  - la classe `in-native-app` sur <html> (ajustements CSS : safe areas,
 *    masquage du bouton OAuth web bloqué par Google en WebView) ;
 *  - le chargement conditionnel de `native-bridge.js` (pont plugins Capacitor).
 */
final class NativeApp
{
    /** Marqueur ajouté au User-Agent par la WebView de l'app mobile. */
    private const UA_MARKER = 'myLLM-App';

    /** Vrai si la requête provient de la WebView de l'app mobile. */
    public static function isNative(): bool
    {
        return str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', self::UA_MARKER);
    }

    /** Plateforme de l'app ('ios' | 'android'), null hors app native. */
    public static function platform(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('~' . self::UA_MARKER . '/(ios|android)~', $ua, $m)) {
            return $m[1];
        }
        return self::isNative() ? 'android' : null;
    }
}
