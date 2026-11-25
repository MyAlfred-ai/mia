<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pastille d'INFO « Votre contexte est vide » (UserPreferences::contextDot) :
 *   - présente tant que le contexte est vide, absente dès qu'il est rempli ;
 *   - son texte explique à quoi sert le contexte (et, sur le chemin, où le
 *     remplir), traduit, en bulle — jamais en texte permanent ;
 *   - posée jusqu'à l'élément : bouton Réglages, entrée Mémoire du menu Mon
 *     compte, sommaire et carte de la page Mémoire ;
 *   - effacée dès la première lettre sur la page ; bulle bleue.
 */
final class ContextInfoDotTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    public function testPresenteTantQueLeContexteEstVide(): void
    {
        foreach ([['ai_user_context' => ''], ['ai_user_context' => "  \n "], ['id' => 3]] as $user) {
            $dot = UserPreferences::contextDot($user);
            $this->assertStringContainsString('class="info-dot"', $dot);
            $this->assertStringContainsString('data-context-dot', $dot);
            $this->assertStringContainsString('data-tip-tone="info"', $dot);
            $this->assertStringContainsString('role="img"', $dot);
        }
        $this->assertSame('', UserPreferences::contextDot(['ai_user_context' => 'Responsable qualité chez Durand']));
        $this->assertSame('', UserPreferences::contextDot(null), 'Personne de connecté : rien.');
    }

    public function testLeTexteExpliqueAQuoiSertLeContexte(): void
    {
        $hint = UserPreferences::contextHint();
        foreach (['Votre contexte est vide', 'métier', 'outils', 'au début de chaque conversation', 'adapter ses réponses'] as $attendu) {
            $this->assertStringContainsString($attendu, $hint);
        }
        $this->assertStringNotContainsString('Réglages', $hint, 'Sur la carte elle-même : inutile de dire où.');
        $this->assertStringContainsString('Réglages → Mémoire', UserPreferences::contextHint(true), 'Sur le chemin : où le remplir.');

        $dot = UserPreferences::contextDot(['ai_user_context' => ''], true, true, 'is-anchor');
        $this->assertStringContainsString('class="info-dot is-anchor"', $dot);
        $this->assertStringContainsString('tabindex="0"', $dot);
        $this->assertStringNotContainsString('tabindex', UserPreferences::contextDot(['ai_user_context' => '']), 'Dans un lien, le lien porte le focus.');
        $this->assertStringNotContainsString('title=', $dot, 'Une seule bulle : jamais de title natif.');

        I18n::use('en');
        $this->assertStringContainsString('Your context is empty', UserPreferences::contextHint(true));
        $this->assertStringContainsString('Settings → Memory', UserPreferences::contextHint(true));
    }

    public function testPoseeJusquALaCarte(): void
    {
        $actions = (string)file_get_contents(ROOT . '/templates/partials/user-account-actions.php');
        $this->assertStringContainsString('UserPreferences::contextDot($uaUser, true)', $actions, 'Bouton Réglages.');

        $nav = (string)file_get_contents(ROOT . '/templates/partials/admin-nav.php');
        $this->assertMatchesRegularExpression("/\\\$navKey === 'memory'\\): \\?><\\?= UserPreferences::contextDot\\(\\\$navUser, true\\)/", $nav, 'Entrée Mémoire du menu Mon compte.');

        $page = (string)file_get_contents(ROOT . '/templates/settings/memory.php');
        $this->assertStringContainsString('<a href="#contexte"><?= te(\'Votre contexte\') ?><?= UserPreferences::contextDot($user) ?></a>', $page, 'Sommaire.');
        $this->assertStringContainsString('<h3 id="memCtxTitle"><?= te(\'Votre contexte\') ?><?= UserPreferences::contextDot($user, false, true, \'is-anchor\') ?></h3>', $page, 'Carte.');
    }

    public function testEffaceeDesLaPremiereLettre(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/memory.js');
        $this->assertStringContainsString("document.querySelectorAll('[data-context-dot]').forEach(function (dot) { dot.hidden = !empty; });", $js);
        $this->assertStringContainsString("const empty = els.ctx.value.trim() === '';", $js);

        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        $this->assertStringContainsString('.info-dot[hidden] { display: none !important; }', $css, 'Sinon display: inline-block l\'emporte sur hidden.');
        $this->assertStringContainsString('.nav-hint-bubble.is-info', $css);
        $this->assertMatchesRegularExpression('/\.info-dot \{[^}]*background: var\(--info-blue\)/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.info-dot \{[^}]*animation/s', $css, 'Une invitation, pas une alerte : rien ne bouge.');

        $signals = (string)file_get_contents(ROOT . '/public/assets/js/nav-signals.js');
        $this->assertStringContainsString("const TONS = ['alert', 'warn', 'news', 'info'];", $signals);
        $this->assertStringContainsString('.info-dot', $signals, 'Au doigt, toucher la pastille ouvre sa bulle.');
    }

    public function testTraduitEtDocumente(): void
    {
        $dico = require ROOT . '/lang/en.php';
        $this->assertArrayHasKey('Votre contexte est vide. Présentez-vous en une ou deux phrases (votre métier, vos outils, votre public) : MIA le relit au début de chaque conversation pour adapter ses réponses à votre situation.', $dico);
        $this->assertArrayHasKey('À remplir dans Réglages → Mémoire, « Votre contexte ».', $dico);

        $guide = (string)file_get_contents(ROOT . '/docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString('pastille bleue', $guide);
        $wiki = (string)file_get_contents(ROOT . '/docs/user/fonctionnalites/personnalisation/gerer-sa-memoire.md');
        $this->assertStringContainsString('pastille bleue', $wiki);
        $nouveautes = (string)file_get_contents(ROOT . '/docs/NOUVEAUTES.md');
        $this->assertStringContainsString('contexte-pastille', $nouveautes);
    }
}
