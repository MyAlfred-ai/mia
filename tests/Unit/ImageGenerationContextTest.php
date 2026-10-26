<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Génération d'image contextuelle.
 *
 * Le modèle image ne voit NI la conversation NI la persona : il ne reçoit
 * qu'un prompt. On verrouille ici les trois briques pures qui rendent la
 * génération contextuelle fiable :
 *   - buildImageContextDigest  : résumé compact des derniers échanges
 *   - parseImageRequestJson    : lecture tolérante de la réponse du LLM
 *   - looksLikeImageEditRequest: heuristique de secours (pas de clé LLM)
 * plus la matrice des modèles capables de partir d'une image existante
 * (ImageGenerator::supportsImageEdit) — y joindre une source sur un modèle
 * incapable produisait une erreur dure côté chat.
 */
final class ImageGenerationContextTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // Résumé de conversation
    // ---------------------------------------------------------------------

    public function testDigestLabelsRolesAndKeepsOrder(): void
    {
        $digest = ChatController::buildImageContextDigest([
            ['role' => 'user',      'content' => 'Je prépare une affiche pour le festival Ondes.'],
            ['role' => 'assistant', 'content' => 'Palette bleu nuit et corail, typo condensée.'],
        ]);

        $this->assertSame(
            "Utilisateur : Je prépare une affiche pour le festival Ondes.\n"
            . "Assistant : Palette bleu nuit et corail, typo condensée.",
            $digest
        );
    }

    public function testDigestStripsMarkdownImagesAndCodeBlocks(): void
    {
        $digest = ChatController::buildImageContextDigest([
            ['role' => 'assistant', 'content' => "Voici le rendu :\n![Image générée](/assets/img/generated/abc.png)\nQu'en penses-tu ?"],
            ['role' => 'user',      'content' => "Regarde ce bout :\n```php\n\$x = 1;\n```\nc'est ok"],
        ]);

        $this->assertStringNotContainsString('/assets/img/generated/', $digest);
        $this->assertStringNotContainsString('$x = 1', $digest);
        $this->assertStringContainsString("Qu'en penses-tu ?", $digest);
        $this->assertStringContainsString('[code]', $digest);
    }

    public function testDigestKeepsOnlyTheMostRecentMessages(): void
    {
        $history = [];
        for ($i = 1; $i <= 10; $i++) {
            $history[] = ['role' => 'user', 'content' => 'message ' . $i];
        }

        $digest = ChatController::buildImageContextDigest($history, 3);
        $lines  = explode("\n", $digest);

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('message 8', $lines[0]);
        $this->assertStringContainsString('message 10', $lines[2]);
    }

    public function testDigestTruncatesLongMessagesAndTotalBudget(): void
    {
        $long = str_repeat('a', 900);

        $perMessage = ChatController::buildImageContextDigest([['role' => 'user', 'content' => $long]], 8, 100);
        $this->assertSame('Utilisateur : ' . str_repeat('a', 100) . '…', $perMessage);

        // Budget global : la 2e ligne fait déborder → elle est écartée.
        $capped = ChatController::buildImageContextDigest(
            [
                ['role' => 'user',      'content' => str_repeat('b', 80)],
                ['role' => 'assistant', 'content' => str_repeat('c', 80)],
            ],
            8,
            500,
            120
        );
        $this->assertStringContainsString('bbb', $capped);
        $this->assertStringNotContainsString('ccc', $capped);
    }

    public function testDigestIgnoresEmptyAndNonChatRoles(): void
    {
        $digest = ChatController::buildImageContextDigest([
            ['role' => 'system', 'content' => 'consigne interne'],
            ['role' => 'user',   'content' => '   '],
            ['role' => 'user',   'content' => 'un logo de pieuvre'],
        ]);

        $this->assertSame('Utilisateur : un logo de pieuvre', $digest);
    }

    // ---------------------------------------------------------------------
    // Lecture de la réponse du LLM
    // ---------------------------------------------------------------------

    public function testParsesPlainJson(): void
    {
        $parsed = ChatController::parseImageRequestJson('{"prompt":"un renard roux, aquarelle","modification":false}');

        $this->assertSame('un renard roux, aquarelle', $parsed['prompt']);
        $this->assertFalse($parsed['edit']);
    }

    public function testParsesFencedJsonAndSurroundingText(): void
    {
        $raw = "Voici le JSON demandé :\n```json\n{\"prompt\": \"ajoute un chapeau rouge\", \"modification\": true}\n```\nVoilà.";
        $parsed = ChatController::parseImageRequestJson($raw);

        $this->assertSame('ajoute un chapeau rouge', $parsed['prompt']);
        $this->assertTrue($parsed['edit']);
    }

    public function testParsesStringBooleans(): void
    {
        $parsed = ChatController::parseImageRequestJson('{"prompt":"en bleu","modification":"true"}');
        $this->assertTrue($parsed['edit']);

        $parsed = ChatController::parseImageRequestJson('{"prompt":"en bleu","modification":"false"}');
        $this->assertFalse($parsed['edit']);
    }

    public function testMissingModificationDefaultsToNewImage(): void
    {
        $parsed = ChatController::parseImageRequestJson('{"prompt":"un phare la nuit"}');
        $this->assertFalse($parsed['edit']);
    }

    public function testTruncatesOversizedPrompt(): void
    {
        $parsed = ChatController::parseImageRequestJson(json_encode(['prompt' => str_repeat('x', 4000)]));
        $this->assertSame(3000, mb_strlen($parsed['prompt']));
    }

    /** @return array<string, array{string}> */
    public static function unusableLlmAnswers(): array
    {
        return [
            'vide'                => [''],
            'texte libre'         => ['Je ne peux pas faire ça.'],
            'erreur adapter'      => ['[Erreur OpenAI (503) : service indisponible]'],
            'json invalide'       => ['{"prompt": '],
            'prompt vide'         => ['{"prompt":"   ","modification":true}'],
            'json sans le champ'  => ['{"autre":"valeur"}'],
        ];
    }

    #[DataProvider('unusableLlmAnswers')]
    public function testUnusableAnswerFallsBackToNull(string $raw): void
    {
        $this->assertNull(ChatController::parseImageRequestJson($raw));
    }

    // ---------------------------------------------------------------------
    // Heuristique de secours (aucune clé LLM disponible)
    // ---------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function editIntents(): array
    {
        return [
            'référence explicite'   => ['modifie cette image en ajoutant un chien', true],
            'ajout implicite'       => ['ajoute un chapeau rouge', true],
            'mets-lui'              => ['mets-lui des lunettes', true],
            'rends'                 => ['rends-la plus lumineuse', true],
            'suppression'           => ['enlève le texte en bas', true],
            'anglais'               => ['make it more colorful', true],
            'création simple'       => ['un renard roux dans la neige', false],
            'création explicite'    => ['génère une nouvelle image de voiture', false],
            'dessine'               => ['dessine un phare la nuit', false],
            'vide'                  => ['   ', false],
        ];
    }

    #[DataProvider('editIntents')]
    public function testEditIntentHeuristic(string $prompt, bool $expected): void
    {
        $this->assertSame($expected, ChatController::looksLikeImageEditRequest($prompt));
    }

    // ---------------------------------------------------------------------
    // Capacité image-to-image par modèle
    // ---------------------------------------------------------------------

    /** @return array<string, array{string, string, bool}> */
    public static function editCapableModels(): array
    {
        return [
            'gpt-image-2 (défaut OpenAI)' => ['openai', 'gpt-image-2', true],
            'gpt-image-1 legacy'          => ['openai', 'gpt-image-1', true],
            'GPT-5.4 natif (Responses)'   => ['openai', 'gpt-5.4', true],
            'DALL-E 3'                    => ['openai', 'dall-e-3', false],
            'DALL-E 2'                    => ['openai', 'dall-e-2', false],
            'Nano Banana 2'               => ['gemini', 'gemini-3.1-flash-image', true],
            'Nano Banana Pro'             => ['gemini', 'gemini-3-pro-image', true],
            'Imagen 4 (predict)'          => ['gemini', 'imagen-4.0-generate-001', false],
            'modèle vide'                 => ['openai', '', false],
            'fournisseur inconnu'         => ['stability', 'sd-3', false],
        ];
    }

    #[DataProvider('editCapableModels')]
    public function testSupportsImageEdit(string $provider, string $model, bool $expected): void
    {
        $this->assertSame(
            $expected,
            ImageGenerator::supportsImageEdit(['provider' => $provider, 'model' => $model])
        );
    }
}
