<?php

declare(strict_types=1);

/**
 * Page publique de téléchargement de l'application mobile (GET /app).
 *
 * HORS authentification (comme /manifest.webmanifest) : le lien doit être
 * partageable tel quel — un nouvel utilisateur installe l'app AVANT d'avoir
 * un compte. Aucune donnée sensible : l'APK et le lien TestFlight sont
 * publics par nature.
 */
class MobileAppController
{
    /** Chemin de l'APK publié par la CI (bind mount public/downloads). */
    private const APK_PATH = '/public/downloads/mia.apk';
    private const APK_URL  = '/downloads/mia.apk';

    public function download(): void
    {
        $apk     = null;
        $apkFile = ROOT . self::APK_PATH;
        if (is_file($apkFile)) {
            $apk = [
                'url'   => self::APK_URL,
                'size'  => (int)filesize($apkFile),
                'mtime' => (int)filemtime($apkFile),
            ];
        }

        $testflightUrl = trim((string)Settings::get('mobile_testflight_url', ''));
        $appName       = Settings::get('app_name', 'MIA');

        require ROOT . '/templates/app/download.php';
    }
}
