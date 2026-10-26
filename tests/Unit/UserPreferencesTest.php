<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fragment de préférences IA injecté dans le system prompt : doit être un no-op
 * (0 token) quand l'utilisateur n'a rien configuré, et contenir le contexte
 * libre sinon.
 */
final class UserPreferencesTest extends TestCase
{
    public function testNullUserGivesEmptyFragment(): void
    {
        $this->assertSame('', UserPreferences::promptFragment(null));
    }

    public function testEmptyPreferencesGiveEmptyFragment(): void
    {
        $this->assertSame('', UserPreferences::promptFragment([
            'ai_style'        => '',
            'ai_user_context' => '',
        ]));
    }

    public function testUserContextIsInjected(): void
    {
        $fragment = UserPreferences::promptFragment([
            'ai_style'        => '',
            'ai_user_context' => 'Je code en PHP',
        ]);
        $this->assertStringContainsString('Je code en PHP', $fragment);
        // Le bloc porte le titre EXACT de la carte des paramètres : c'est ce
        // vocabulaire que l'assistant reprendra pour répondre « ce que je sais de toi ».
        $this->assertStringContainsString('Votre contexte', $fragment);
        $this->assertStringStartsWith("\n\n", $fragment);
    }

    public function testUserContextCanBeRestitutedOnDemand(): void
    {
        $fragment = UserPreferences::promptFragment([
            'ai_style'        => '',
            'ai_user_context' => 'Je code en PHP',
        ]);
        // « Que sais-tu de moi ? » doit ressortir le contexte, pas l'ignorer.
        $this->assertStringContainsString('restitue', $fragment);
        $this->assertStringContainsString('Réglages', $fragment);
    }

    public function testStyleAloneCostsNoRestitutionInstruction(): void
    {
        $fragment = UserPreferences::promptFragment([
            'ai_style'        => 'concis',
            'ai_user_context' => '',
        ]);
        // Rien à restituer sans contexte renseigné → aucun token dépensé pour ça.
        $this->assertStringNotContainsString('Votre contexte', $fragment);
        $this->assertStringContainsString('Style :', $fragment);
    }

    public function testHasContextIgnoresStyleAndWhitespace(): void
    {
        $this->assertFalse(UserPreferences::hasContext(null));
        $this->assertFalse(UserPreferences::hasContext(['ai_user_context' => "  \n "]));
        // Un style ne dit rien sur l'utilisateur : il ne compte pas.
        $this->assertFalse(UserPreferences::hasContext(['ai_style' => 'concis', 'ai_user_context' => '']));
        $this->assertTrue(UserPreferences::hasContext(['ai_user_context' => 'Je code en PHP']));
    }

    /**
     * Les cartes de Réglages → Préférences IA affichent `label` + `hint` ;
     * l'aide est écrite pour l'utilisateur, jamais recopiée du fragment de
     * prompt (la carte disait « Style : réponses courtes… », une consigne
     * adressée au modèle). Mêmes slugs que STYLES : la constante reste la
     * source, l'accesseur ne fait que traduire.
     */
    public function testStylesAccessorExposesScreenLabelsNeverThePromptFragment(): void
    {
        $styles = UserPreferences::styles();
        $this->assertSame(array_keys(UserPreferences::STYLES), array_keys($styles));
        foreach ($styles as $slug => $info) {
            $this->assertSame(['label', 'hint'], array_keys($info), $slug);
            $this->assertNotSame('', $info['label'], $slug);
            $this->assertNotSame('', $info['hint'], $slug);
            $this->assertNotSame(UserPreferences::STYLES[$slug]['fragment'], $info['hint'], $slug);
            $this->assertStringStartsNotWith('Style :', $info['hint'], $slug);
        }
    }

    public function testIsValidStyle(): void
    {
        $this->assertTrue(UserPreferences::isValidStyle(''));     // « aucun style » est valide
        $this->assertFalse(UserPreferences::isValidStyle('style-inexistant-xyz'));
    }
}
