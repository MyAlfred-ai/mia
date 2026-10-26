<?php

declare(strict_types=1);

/**
 * Source du chat pour les tests de CONTRAT (lecture du code, sans exécution).
 *
 * Le registre d'outils a quitté ChatController (src/Agent/) : définitions,
 * consignes et handlers vivent dans un fournisseur par source d'outils, le
 * dispatch unique dans ToolRegistry. Un test qui garde « l'outil X est défini,
 * gardé et exécutable » lit donc le contrôleur ET le registre : c'est un
 * comportement qu'il protège, pas l'emplacement d'une ligne.
 */
final class AgentSource
{
    /** ChatController, puis tout src/Agent/ dans un ordre stable. */
    public static function chat(): string
    {
        return self::read('src/Controllers/ChatController.php') . "\n" . self::agent();
    }

    /** Tout src/Agent/ (socle puis fournisseurs), ordre alphabétique stable. */
    public static function agent(): string
    {
        $files = array_merge(
            glob(ROOT . '/src/Agent/*.php') ?: [],
            glob(ROOT . '/src/Agent/Tools/*.php') ?: []
        );
        sort($files);
        $out = '';
        foreach ($files as $file) {
            $out .= (string)file_get_contents($file) . "\n";
        }
        return $out;
    }

    /** Un fournisseur d'outils : src/Agent/Tools/<Classe>.php. */
    public static function tools(string $class): string
    {
        return self::read('src/Agent/Tools/' . $class . '.php');
    }

    /** Le dispatch unique des appels d'outils. */
    public static function registry(): string
    {
        return self::read('src/Agent/ToolRegistry.php');
    }

    private static function read(string $relative): string
    {
        $src = file_get_contents(ROOT . '/' . $relative);
        if ($src === false) {
            throw new RuntimeException('Source introuvable : ' . $relative);
        }
        return $src;
    }
}
