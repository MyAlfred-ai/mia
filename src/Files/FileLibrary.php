<?php

declare(strict_types=1);

/**
 * Présence d'une bibliothèque de fabrication (PhpSpreadsheet, PhpWord, Dompdf).
 *
 * Un simple `class_exists()` ne suffit pas : le dépôt versionne une PARTIE de
 * vendor/, et son chargeur connaît des classes dont les fichiers manquent. Le
 * chargeur tente alors un `include` qui échoue en AVERTISSEMENT — écrit dans
 * la réponse quand les erreurs sont affichées (poste de développement), donc
 * au milieu du JSON qui devait expliquer l'échec. Ici la question est posée
 * en silence : oui, ou non, et le fabricant propose ses formats de rechange.
 */
final class FileLibrary
{
    public static function has(string $class): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return class_exists($class);
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
