<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Écritures de cache OpenAI (GPT-5.6 et suivants, dont GPT-6 Astra).
 *
 * OpenAI facture l'écriture de cache 1,25× le tarif d'entrée et la rapporte
 * dans `input_tokens_details.cache_write_tokens` (Responses API) —
 * `prompt_tokens_details.cache_write_tokens` en Chat Completions. L'adapter
 * renvoyait `input_cache_write => 0` en dur : ces tokens passaient au tarif
 * d'entrée simple, 20 % sous le coût réel. Mesuré le 10/09/2026 sur une
 * recherche web GPT-6 Astra : 4 394 tokens écrits sur 12 609 en entrée.
 */
final class OpenAiCacheWriteTest extends TestCase
{
    /** @return array<string, array{array, int}> */
    public static function usages(): array
    {
        return [
            // Réponse réelle de la Responses API (GPT-6 Astra + web_search).
            'Responses API' => [
                ['input_tokens' => 12609, 'input_tokens_details' => ['cache_write_tokens' => 4394, 'cached_tokens' => 0],
                 'output_tokens' => 85, 'output_tokens_details' => ['reasoning_tokens' => 42]],
                4394,
            ],
            'Chat Completions' => [
                ['prompt_tokens' => 2000, 'prompt_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 1500]],
                1500,
            ],
            // Modèles antérieurs : le champ n'existe pas.
            'champ absent' => [['input_tokens' => 21, 'input_tokens_details' => ['cached_tokens' => 0]], 0],
            'usage vide'   => [[], 0],
        ];
    }

    #[DataProvider('usages')]
    public function testLesTokensEcritsEnCacheSontLus(array $usage, int $attendu): void
    {
        $m = new ReflectionMethod(OpenAIAdapter::class, 'cacheWriteTokens');
        $this->assertSame($attendu, $m->invoke(null, $usage));
    }

    /**
     * Les quatre lectures d'usage (Chat Completions, Responses API, et leurs
     * deux boucles d'outils) passent par le helper, et aucun résultat ne
     * renvoie plus un zéro en dur — hors échec et jetons vides, où rien n'a
     * été consommé.
     */
    public function testChaqueLectureDUsageRapporteLesEcritures(): void
    {
        $src = file_get_contents(ROOT . '/src/LLM/OpenAIAdapter.php');

        $this->assertSame(4, substr_count($src, '= self::cacheWriteTokens($usage);'));
        $this->assertSame(
            3,
            substr_count($src, "'input_cache_write' => 0"),
            'Seuls emptyTokens() et les deux $fail des boucles d\'outils peuvent encore renvoyer 0 en dur.'
        );
        $this->assertSame(2, substr_count($src, '$totCacheWrite += $res[\'input_cache_write\'];'));
    }

    /**
     * CreditService attend un SOUS-ENSEMBLE de l'entrée (plein tarif = total −
     * lu − écrit) : c'est bien ce que rapporte OpenAI, et ce que fait déjà
     * l'adapter Anthropic. Le tarif d'écriture de GPT-6 Astra est déclaré.
     */
    public function testLeTarifDEcritureEstAuCatalogue(): void
    {
        $prix = LlmCatalog::pricing('openai', 'gpt-6-astra');
        $this->assertSame(12.5, $prix['cache_write'] ?? null);
        $this->assertSame(1.25 * $prix['input'], $prix['cache_write']);
    }
}
