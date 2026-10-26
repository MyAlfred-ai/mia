<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Plafond de sortie des modèles Claude (`max_tokens`).
 *
 * Il valait 4 096 hors Fable, raisonnement compris : les modèles qui pensent
 * par défaut coupaient les réponses longues, les pages web et les
 * présentations. Il vaut désormais 64 000 pour tous — le plus petit plafond
 * que l'API accepte parmi les modèles Claude actifs.
 *
 * Deux gardes, parce qu'un plafond trop haut ne se voit qu'en production :
 * chaque modèle du catalogue reste sous le plafond documenté de SON API, et un
 * modèle qui accepte moins (hors catalogue, génération future) est rejoué au
 * plafond que l'API annonce au lieu d'échouer à chaque message.
 */
final class AnthropicOutputCapTest extends TestCase
{
    /**
     * Plafonds de l'API Messages (synchrone), page « Output limits » de la
     * documentation Anthropic (build-with-claude/thinking), relue le
     * 2026-09-30. Un modèle ajouté au catalogue doit y figurer : c'est ce qui
     * oblige à vérifier son plafond avant de le proposer.
     */
    private const PLAFONDS_API = [
        'claude-opus-5-5'           => 128000,
        'claude-sonnet-5-5'         => 128000,
        'claude-fable-5-1'          => 128000,
        'claude-fable-5'            => 128000,
        'claude-opus-5'             => 128000,
        'claude-sonnet-5'           => 128000,
        'claude-opus-4-8'           => 128000,
        'claude-opus-4-7'           => 128000,
        'claude-opus-4-6'           => 128000,
        'claude-sonnet-4-6'         => 128000,
        'claude-haiku-4-5-20251001' => 64000,
    ];

    private string|false $journalAvant = false;

    protected function setUp(): void
    {
        // Un plafond appris est journalisé : hors de la sortie des tests.
        $this->journalAvant = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/mia-plafond-claude-test.log');
    }

    protected function tearDown(): void
    {
        $this->plafondsAppris([]);
        ini_set('error_log', $this->journalAvant === false ? '' : $this->journalAvant);
        @unlink(sys_get_temp_dir() . '/mia-plafond-claude-test.log');
        parent::tearDown();
    }

    public function testChaqueModeleDuCatalogueResteSousLePlafondDeSonApi(): void
    {
        $this->assertSame(64000, AnthropicAdapter::DEFAULT_MAX_TOKENS);
        foreach (array_keys(LlmCatalog::models('anthropic')) as $id) {
            $id = (string)$id;
            $this->assertArrayHasKey(
                $id,
                self::PLAFONDS_API,
                "Plafond de l'API non vérifié pour $id : le relire dans la documentation Anthropic et l'ajouter ici."
            );
            $plafond = AnthropicAdapter::maxTokensFor($id);
            $this->assertLessThanOrEqual(self::PLAFONDS_API[$id], $plafond, "$id : l'API refuserait ce plafond (400).");
            // Jamais de retour à un plafond qui coupe les réponses longues :
            // le raisonnement compte dedans.
            $this->assertGreaterThanOrEqual(32000, $plafond, $id);
        }
        // Le défaut ne dépasse aucun plafond d'API connu : un modèle qui ne
        // déclare rien reste accepté.
        $this->assertLessThanOrEqual(min(self::PLAFONDS_API), AnthropicAdapter::DEFAULT_MAX_TOKENS);
    }

    public function testUnRefusDePlafondDonneLePlafondAnnonce(): void
    {
        $message = 'invalid_request_error — max_tokens: 64000 > 32000, which is the maximum allowed number of output tokens for claude-futur-1';
        $this->assertSame(32000, AnthropicAdapter::outputCeilingFromError(400, $message));

        // Rien d'autre n'est pris pour un plafond.
        $this->assertNull(AnthropicAdapter::outputCeilingFromError(429, $message), 'Seul un 400 le porte.');
        $this->assertNull(AnthropicAdapter::outputCeilingFromError(null, $message));
        $this->assertNull(AnthropicAdapter::outputCeilingFromError(400, 'invalid_request_error — prompt is too long: 215234 tokens > 200000 maximum'));
        $this->assertNull(AnthropicAdapter::outputCeilingFromError(400, 'invalid_request_error — max_tokens: 64000 > 32000'));
        $this->assertNull(
            AnthropicAdapter::outputCeilingFromError(400, 'max_tokens: 1000 > 2000, which is the maximum allowed number of output tokens'),
            'Un « plafond » plus haut que la demande ne décrit pas ce refus.'
        );
        $this->assertNull(AnthropicAdapter::outputCeilingFromError(400, 'max_tokens: 1000 > 0, which is the maximum allowed number of output tokens'));
    }

    public function testLePlafondAppriNeVautQuePourSonModele(): void
    {
        $message = 'invalid_request_error — max_tokens: 64000 > 32000, which is the maximum allowed number of output tokens for claude-futur-1';
        $apprendre = new ReflectionMethod(AnthropicAdapter::class, 'learnOutputCeiling');

        $this->assertTrue($apprendre->invoke(null, 'claude-futur-1', 400, $message, 64000));
        $this->assertSame(32000, AnthropicAdapter::maxTokensFor('claude-futur-1'), 'Les demandes suivantes partent au plafond annoncé.');
        $this->assertSame(AnthropicAdapter::DEFAULT_MAX_TOKENS, AnthropicAdapter::maxTokensFor('claude-opus-5'), 'Les autres modèles ne sont pas touchés.');

        // Déjà envoyé à ce plafond (ou plus bas) : rejouer ne changerait rien,
        // l'erreur doit remonter telle quelle au lieu de tourner en boucle.
        $this->assertFalse($apprendre->invoke(null, 'claude-futur-1', 400, $message, 32000));
        $this->assertFalse($apprendre->invoke(null, 'claude-futur-2', 400, 'invalid_request_error — prompt is too long', 64000));
        $this->assertSame(AnthropicAdapter::DEFAULT_MAX_TOKENS, AnthropicAdapter::maxTokensFor('claude-futur-2'));
    }

    public function testLesDeuxBouclesRejouentAuPlafondAnnonce(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        // Le refus est lu aux deux lectures d'une réponse 400 (chemin simple,
        // boucle d'outils)…
        $this->assertSame(
            2,
            substr_count($src, "self::learnOutputCeiling(\$model, \$httpStatus, \$errMsg, (int)(\$payload['max_tokens'] ?? 0))")
        );
        // … et les deux boucles rejouent la même demande sans rien émettre.
        $this->assertSame(1, substr_count($src, "\$result['retry'] === 'max_tokens'"));
        $this->assertSame(1, substr_count($src, "\$res['retry'] === 'max_tokens'"));
        // Le plafond envoyé passe toujours par maxTokensFor() : jamais en dur.
        $this->assertSame(1, substr_count($src, "'max_tokens' => self::maxTokensFor(\$model)"));
    }

    /** @param array<string, int> $plafonds */
    private function plafondsAppris(array $plafonds): void
    {
        (new ReflectionProperty(AnthropicAdapter::class, 'learnedOutputCeilings'))->setValue(null, $plafonds);
    }
}
