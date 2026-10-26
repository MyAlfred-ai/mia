<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mémoire long terme « MIA pour vous ».
 *
 * On verrouille ici les briques pures dont dépend la fiabilité de la
 * fonctionnalité, sans base ni LLM :
 *   - sanitize / normalizeForCompare : normalisation + déduplication
 *   - promptFragmentFromRows         : bloc injecté dans le system prompt
 *   - looksLikeMemoryRequest         : gate lexical (évite un appel LLM sur
 *                                      chaque message, et surtout les faux
 *                                      positifs type « rappelle-moi demain »)
 *   - parseMemoryExtractionJson      : lecture tolérante de la réponse du LLM
 */
final class UserMemoryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // Normalisation
    // ---------------------------------------------------------------------

    public function testSanitizeCollapsesToASingleLine(): void
    {
        $this->assertSame(
            'Préfère les réunions du matin',
            UserMemory::sanitize("  Préfère les réunions\n\tdu   matin  ")
        );
    }

    public function testSanitizeCapsLength(): void
    {
        $clean = UserMemory::sanitize(str_repeat('a', UserMemory::MAX_LEN + 50));
        $this->assertSame(UserMemory::MAX_LEN, mb_strlen($clean));
        $this->assertStringEndsWith('…', $clean);
    }

    public function testNormalizeIgnoresCaseAccentsAndPunctuation(): void
    {
        $this->assertSame(
            UserMemory::normalizeForCompare('Je préfère les réunions du matin !'),
            UserMemory::normalizeForCompare('je prefere les reunions du matin')
        );
    }

    public function testNormalizeKeepsDistinctFactsDistinct(): void
    {
        $this->assertNotSame(
            UserMemory::normalizeForCompare('Travaille au marketing'),
            UserMemory::normalizeForCompare('Travaille à la comptabilité')
        );
    }

    // ---------------------------------------------------------------------
    // Fragment de system prompt
    // ---------------------------------------------------------------------

    public function testEmptyMemoryIsANoOp(): void
    {
        $this->assertSame('', UserMemory::promptFragmentFromRows([]));
        $this->assertSame('', UserMemory::promptFragmentFromRows([['content' => '   ']]));
    }

    public function testFragmentListsMemoriesAndForbidsSelfDeletion(): void
    {
        $fragment = UserMemory::promptFragmentFromRows([
            ['content' => 'Préfère les réunions du matin'],
            ['content' => 'Travaille au département marketing'],
        ]);

        $this->assertStringStartsWith("\n\n", $fragment);
        $this->assertStringContainsString('- Préfère les réunions du matin', $fragment);
        $this->assertStringContainsString('- Travaille au département marketing', $fragment);
        // L'assistant ne doit jamais prétendre pouvoir supprimer un souvenir.
        $this->assertStringContainsString('MIA pour vous', $fragment);
        $this->assertStringContainsString('Réglages', $fragment);
    }

    public function testFragmentAllowsRestitutionWhenAsked(): void
    {
        $fragment = UserMemory::promptFragmentFromRows([
            ['content' => 'Préfère les réunions du matin'],
        ]);

        // « Que sais-tu de moi ? » est l'exception à la consigne de discrétion :
        // sans elle, l'assistant refuse d'énumérer sa propre mémoire.
        $this->assertStringContainsString('sans les énumérer', $fragment);
        $this->assertStringContainsString('SAUF', $fragment);
        $this->assertStringContainsString('restitue', $fragment);
    }

    public function testFragmentStaysWithinPromptBudget(): void
    {
        $rows = [];
        for ($i = 0; $i < UserMemory::MAX_ENTRIES; $i++) {
            $rows[] = ['content' => str_repeat('x', UserMemory::MAX_LEN)];
        }
        $fragment = UserMemory::promptFragmentFromRows($rows);

        // Le budget borne la liste ; l'en-tête et la consigne s'y ajoutent.
        $this->assertLessThan(UserMemory::MAX_PROMPT_CHARS + 600, mb_strlen($fragment));
        $this->assertStringContainsString('MIA pour vous', $fragment);
    }

    // ---------------------------------------------------------------------
    // « Que sais-tu de moi ? » sur un compte vierge
    // ---------------------------------------------------------------------

    public function testEmptyStateNoteFiresOnlyWhenNothingIsKnown(): void
    {
        $note = UserMemory::emptyStateNote(false, false);
        $this->assertNotSame('', $note);
        $this->assertStringContainsString('MIA pour vous', $note);
        $this->assertStringContainsString('Votre contexte', $note);
        $this->assertStringContainsString('Réglages', $note);

        // Dès qu'il y a quelque chose à restituer, la note ne coûte plus rien.
        $this->assertSame('', UserMemory::emptyStateNote(true, false));
        $this->assertSame('', UserMemory::emptyStateNote(false, true));
        $this->assertSame('', UserMemory::emptyStateNote(true, true));
    }

    public function testBothInjectionPointsCoverTheEmptyState(): void
    {
        $src = file_get_contents(ROOT . '/src/Controllers/ChatController.php') ?: '';
        // send() ET regenerate() : un seul des deux couvert laisserait le modèle
        // sans consigne sur l'autre chemin.
        $this->assertSame(2, substr_count($src, 'UserMemory::emptyStateNote('));
        $this->assertGreaterThanOrEqual(2, substr_count($src, 'UserPreferences::hasContext('));
    }

    // ---------------------------------------------------------------------
    // Gate lexical
    // ---------------------------------------------------------------------

    public static function memoryRequests(): array
    {
        return [
            ["S'il te plaît, souviens-toi que je préfère les réunions du matin"],
            ['Rappelle-toi que je travaille dans le département marketing'],
            ["J'aimerai que tu te souviennes que j'aime les réponses courtes concernant les formations"],
            ['Retiens que mon fuseau horaire est Paris'],
            ['Mémorise mon poste : responsable qualité'],
            ['Garde en mémoire que je code en PHP'],
            ["N'oublie pas que je suis daltonien"],
        ];
    }

    #[DataProvider('memoryRequests')]
    public function testMemoryIntentIsDetected(string $message): void
    {
        $this->assertTrue(ChatController::looksLikeMemoryRequest($message));
    }

    public static function nonMemoryRequests(): array
    {
        return [
            ['Rappelle-moi demain de relancer le fournisseur'],   // rappel d'agenda, pas mémorisation
            ['Quelle est la capitale du Portugal ?'],
            ["Fais-moi un résumé de ce document"],
            ['Merci beaucoup pour ton aide'],
            [''],
        ];
    }

    #[DataProvider('nonMemoryRequests')]
    public function testOrdinaryMessagesDoNotTriggerMemory(string $message): void
    {
        $this->assertFalse(ChatController::looksLikeMemoryRequest($message));
    }

    // ---------------------------------------------------------------------
    // Lecture de la réponse du LLM
    // ---------------------------------------------------------------------

    public function testExtractionReadsFencedJson(): void
    {
        $parsed = ChatController::parseMemoryExtractionJson(
            "Voici :\n```json\n{\"memoriser\": true, \"faits\": [\"Préfère les réunions du matin\"]}\n```"
        );

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['memoriser']);
        $this->assertSame(['Préfère les réunions du matin'], $parsed['faits']);
    }

    public function testExtractionCapsFactsAndDropsEmptyOnes(): void
    {
        $parsed = ChatController::parseMemoryExtractionJson(
            '{"memoriser": true, "faits": ["a", "  ", "b", "c", "d", "e"]}'
        );

        $this->assertNotNull($parsed);
        $this->assertCount(3, $parsed['faits']);
        $this->assertSame(['a', 'b', 'c'], $parsed['faits']);
    }

    public function testExtractionHonoursNegativeVerdict(): void
    {
        $parsed = ChatController::parseMemoryExtractionJson('{"memoriser": false, "faits": []}');
        $this->assertNotNull($parsed);
        $this->assertFalse($parsed['memoriser']);
        $this->assertSame([], $parsed['faits']);
    }

    public function testExtractionReturnsNullOnGarbage(): void
    {
        $this->assertNull(ChatController::parseMemoryExtractionJson(''));
        $this->assertNull(ChatController::parseMemoryExtractionJson('Je ne peux pas répondre.'));
    }
}
