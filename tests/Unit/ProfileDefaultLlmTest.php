<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * « Défaut application » sur la fiche d'un assistant.
 *
 * La fiche promettait « le modèle par défaut de MIA s'applique », mais
 * l'enregistrement recopiait le défaut DU MOMENT dans `profiles.default_llm`
 * (AdminController::resolveDefaultLlmForProfile) : l'assistant ne suivait plus
 * un changement ultérieur du modèle par défaut, et sa fiche rouverte montrait
 * un modèle précis au lieu de « Défaut application ». Le chat, lui, savait
 * déjà suivre le défaut pour un assistant sans modèle
 * (ChatController::resolveProfileDefaultLlm) — seul l'enregistrement mentait.
 */
final class ProfileDefaultLlmTest extends TestCase
{
    private const AUTORISES = [
        'openai::gpt-5.6'          => 'GPT-5.6',
        'anthropic::claude-opus-5' => 'Claude Opus 5',
    ];

    public function testDefautApplicationSEnregistreSansModele(): void
    {
        $this->assertNull(Profile::normalizeDefaultLlm('', self::AUTORISES));
        $this->assertNull(Profile::normalizeDefaultLlm('   ', self::AUTORISES));
    }

    public function testUnModeleAutoriseEstEnregistreTelQuel(): void
    {
        $this->assertSame(
            'anthropic::claude-opus-5',
            Profile::normalizeDefaultLlm(' anthropic::claude-opus-5 ', self::AUTORISES)
        );
    }

    public function testUnModeleHorsPerimetreRetombeSurLeDefautApplication(): void
    {
        // Modèle retiré entre l'affichage et l'enregistrement, ou POST forgé
        // par un créateur : jamais enregistré, l'assistant suit le défaut.
        $this->assertNull(Profile::normalizeDefaultLlm('openai::gpt-6-astra', self::AUTORISES));
    }

    public function testLaCreationEtLaModificationNeFigentPlusLeDefaut(): void
    {
        require_once ROOT . '/src/Controllers/AdminController.php';
        foreach (['createProfile', 'updateProfile'] as $methode) {
            $corps = $this->corps(AdminController::class, $methode);
            $this->assertStringContainsString('Profile::normalizeDefaultLlm(', $corps, $methode);
            $this->assertStringNotContainsString('resolveDefaultLlmForProfile', $corps, $methode);
        }
    }

    public function testLeChatSuitLeDefautPourUnAssistantSansModele(): void
    {
        // La moitié qui marchait déjà — verrouillée : sans elle, enregistrer
        // NULL ne servirait à rien.
        require_once ROOT . '/src/Controllers/ChatController.php';
        $corps = $this->corps(ChatController::class, 'resolveProfileDefaultLlm');
        $this->assertMatchesRegularExpression(
            "/if \(\\\$profileDefaultLlm === ''\) \{\s*return \\\$this->resolveAppDefaultLlm\(/",
            $corps
        );
    }

    public function testLaFicheDitQueLAssistantSuitLeDefaut(): void
    {
        $gabarit = (string)file_get_contents(ROOT . '/templates/admin/profiles/edit.php');
        $this->assertStringContainsString('$appDefaultLlmLabel', $gabarit);
        // Phrase traduite (teb()) : l'apostrophe est échappée dans la source PHP.
        $this->assertStringContainsString("y compris quand l\\'administrateur le change", $gabarit);
    }

    private function corps(string $classe, string $methode): string
    {
        $m      = new ReflectionMethod($classe, $methode);
        $lignes = file((string)$m->getFileName()) ?: [];
        return implode('', array_slice($lignes, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }
}
