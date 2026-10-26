<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Zone de saisie sur téléphone : deux lignes au repos (trois sur l'accueil),
 * et un plafond de croissance lu dans la feuille de style par chat.js — sinon
 * le plafond de 200 px de l'ordinateur continuerait de s'appliquer en silence.
 */
final class ComposerMobileHeightTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $contenu = file_get_contents(ROOT . '/' . $chemin);
        if (!is_string($contenu)) {
            self::fail("Fichier introuvable : $chemin");
        }

        return $contenu;
    }

    public function testLeChampEstPlusHautSurTelephone(): void
    {
        $css = self::lire('public/assets/css/main.css');
        $bloc = substr($css, strpos($css, 'font-size: 16px; /* Empêche le zoom iOS */'), 900);
        $this->assertStringContainsString('min-height: calc(16px * 1.6 * 2 + 14px);', $bloc, 'Deux lignes au repos.');
        $this->assertStringContainsString('.chat-main.is-home .msg-textarea { min-height: calc(16px * 1.6 * 3 + 14px); }', $bloc, 'Trois sur l\'accueil.');
        // Unité d'écran divisée par la taille d'affichage (cf. DisplayPreferencesTest).
        $this->assertStringContainsString('max-height: min(300px, calc(40dvh / var(--ui-zoom, 1)));', $bloc);
        $this->assertStringNotContainsString('min-height: 0;   /* une seule ligne', $css);
    }

    public function testLaCroissanceSuitLePlafondDeLaFeuilleDeStyle(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("const COMPOSER_MOBILE = window.matchMedia('(max-width: 768px)');", $js);
        $fonction = substr($js, strpos($js, 'function composerMaxHeight()'), 300);
        $this->assertStringContainsString('if (!COMPOSER_MOBILE.matches) return 200;', $fonction, 'Ordinateur inchangé.');
        $this->assertStringContainsString('getComputedStyle(msgInput).maxHeight', $fonction);
        $this->assertStringContainsString('Math.min(msgInput.scrollHeight, composerMaxHeight())', $js);
    }

    /** Le bandeau d'infos du chat est replié par défaut — rendu ainsi par le serveur, sans clignotement. */
    public function testLeBandeauDuHautEstReplieParDefaut(): void
    {
        $page = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('<div class="chat-toolbar is-collapsed" id="chatToolbar"', $page);
        $bouton = substr($page, strpos($page, 'id="toolbarCollapseBtn"'), 300);
        $this->assertStringContainsString('aria-expanded="false"', $bouton);
        $js = self::lire('public/assets/js/chat.js');
        $bloc = substr($js, strpos($js, "const KEY = 'toolbarCollapsed';"), 900);
        $this->assertStringContainsString("apply(stored !== '0');", $bloc, 'Seul un dépliage mémorisé le rouvre.');
    }
}
