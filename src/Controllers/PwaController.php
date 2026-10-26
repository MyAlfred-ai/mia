<?php
declare(strict_types=1);

/**
 * Manifest PWA dynamique : le nom affiché à l'installation (écran d'accueil,
 * barre de titre) suit le paramètre `app_name` — pas de fichier statique à
 * maintenir si l'admin renomme l'application.
 *
 * `lang` ne peut porter que le DÉFAUT D'INSTANCE, jamais la langue de la
 * personne qui installe : le manifeste est demandé par le navigateur SANS
 * cookie de session (`I18n::resolve()` n'aurait donc rien à lire), et il est
 * mis en cache par le système une fois l'application posée sur l'écran
 * d'accueil. Promettre mieux serait promettre faux — cf. GUIDE-ADMIN.md.
 */
class PwaController
{
    public function manifest(): void
    {
        $appName = Settings::get('app_name', 'MIA');
        $isLight = Settings::theme() === 'light';
        $color   = $isLight ? '#f5f5f5' : '#212121';

        $manifest = [
            'name'             => $appName,
            'short_name'       => $appName,
            'description'      => Settings::get('app_tagline', ''),
            'lang'             => I18n::instanceDefault(),
            'start_url'        => '/',
            'scope'            => '/',
            'display'          => 'standalone',
            'background_color' => $color,
            'theme_color'      => $color,
            'icons'            => [
                ['src' => '/assets/img/icons/icon-192.png',          'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/img/icons/icon-512.png',          'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/img/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/assets/img/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: no-cache'); // renommage/re-thème pris en compte sans purge
        echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
