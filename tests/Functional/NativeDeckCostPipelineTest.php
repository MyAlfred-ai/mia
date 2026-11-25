<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Estimation d'une « Version soignée » sur la vraie base : les réalisations
 * par Claude faites SANS cache (avant le correctif du 2026-11-24, jusqu'à
 * 50 fois plus chères) ne servent plus de référence, et l'organisation
 * renseigne qui n'a encore rien réalisé avec ce modèle.
 */
final class NativeDeckCostPipelineTest extends DatabaseTestCase
{
    private const MARTIN = 4201;
    private const AUTRE  = 4202;
    private const OPUS   = 'anthropic::claude-opus-5-5';
    private const GPT    = 'openai::gpt-5.6-terra';

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertUser(self::MARTIN);
        $this->insertUser(self::AUTRE);
    }

    /** Une réalisation au grand livre, rattachée à son message ✅ (qui cite le fichier). */
    private function realisation(int $userId, string $llm, int $credits, int $cached, string $file = 'deck.pptx'): void
    {
        $conv = (int)Conversation::create($userId, null, $llm)['id'];
        $msg  = Message::create($conv, 'assistant', '✅ Présentation réalisée : [' . $file . '](/chat/' . $conv . '/presentation/x)');
        UsageLog::insertLlm([
            'user_id' => $userId, 'conversation_id' => $conv, 'message_id' => (int)$msg['id'],
            'llm_provider' => $llm, 'model' => explode('::', $llm)[1],
            'tokens_input' => 1000000, 'tokens_input_cached' => $cached, 'tokens_output' => 10000,
            'credits_used' => $credits, 'cost_eur' => 1.0, 'source' => UsageSource::NATIVE_DECK,
        ]);
    }

    public function testUneRealisationClaudeSansCacheNeSertPlusDeReference(): void
    {
        $this->realisation(self::MARTIN, self::OPUS, 49890, 0);
        $this->realisation(self::MARTIN, self::OPUS, 1800, 900000);

        $this->assertSame([1800], NativeDeck::historyCredits(self::MARTIN, self::OPUS, 'pptx'));
    }

    public function testGptNEstPasConcerneParLeCache(): void
    {
        $this->realisation(self::MARTIN, self::GPT, 300, 0);

        $this->assertSame([300], NativeDeck::historyCredits(self::MARTIN, self::GPT, 'pptx'),
            'Une réalisation GPT sans cache relu reste une référence.');
    }

    public function testLOrganisationRenseigneQuiNARienRealise(): void
    {
        $this->realisation(self::AUTRE, self::OPUS, 2000, 900000);
        $this->realisation(self::AUTRE, self::OPUS, 2400, 900000);
        $this->realisation(self::AUTRE, self::OPUS, 49890, 0);
        $this->realisation(self::AUTRE, self::OPUS, 700, 900000, 'budget.xlsx');

        $this->assertSame([], NativeDeck::historyCredits(self::MARTIN, self::OPUS, 'pptx'));
        $this->assertSame([2400, 2000], NativeDeck::instanceHistoryCredits(self::OPUS, 'pptx'),
            'Les plus récentes d’abord, du même genre, jamais une réalisation sans cache.');

        $estimate = NativeDeck::estimateFor(self::MARTIN, self::OPUS, 'pptx');
        $this->assertSame('instance', $estimate['basis']);
        $this->assertSame(2200, $estimate['credits']);
    }
}
