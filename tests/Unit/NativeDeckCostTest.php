<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Coût d'une « Version soignée » réalisée par Claude (NativeDeck).
 *
 * Le constat qui a fait naître ce test (constaté chez un client, 2026-11-24) : 5 slides par
 * Claude Opus 5.5 = 10 234 765 jetons d'entrée, AUCUN relu en cache, 38 € réels,
 * 49 890 crédits — quand la fenêtre en annonçait 800. La doc d'Anthropic
 * (« Server tool results are cached automatically », relue le 2026-11-24) :
 * pendant la boucle d'exécution de code, un point de cache est posé sur chaque
 * résultat d'outil SEULEMENT si la requête porte déjà un `cache_control` ;
 * sans lui, chaque itération relit tout le contexte au prix fort.
 *
 * Trois remèdes, gardés ici : le cache demandé à chaque requête, une
 * estimation qui compte le cache et apprend des réalisations de toute
 * l'organisation, et un plafond qui arrête les reprises au-delà.
 */
final class NativeDeckCostTest extends TestCase
{
    private const PPTX = "PK\x03\x04....ppt/presentation.xml....";

    private string $dir = '';

    /** @var list<array{method: string, url: string, headers: array, body: mixed}> */
    private array $calls = [];

    protected function setUp(): void
    {
        I18n::use('fr');
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, ['company_name' => 'Acme']);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, true);
        CompanyProfile::overrideTemplate(sys_get_temp_dir() . '/aucun-modele-' . bin2hex(random_bytes(4)) . '.pptx');
        $this->dir = sys_get_temp_dir() . '/mia-native-cost-' . bin2hex(random_bytes(6));
        NativeDeck::overrideUploadRoot($this->dir . '/uploads');
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        NativeDeck::overrideTransport(null);
        NativeDeck::overrideUploadRoot(null);
        CompanyProfile::overrideTemplate(null);
        Settings::refresh();
    }

    /** @param list<array{0: string, 1: string, 2: int, 3: string}> $script méthode, motif d'URL, statut, corps */
    private function transport(array $script): void
    {
        NativeDeck::overrideTransport(function (string $method, string $url, array $headers, $body) use (&$script): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            foreach ($script as $i => [$m, $pattern, $status, $response]) {
                if ($m === $method && preg_match($pattern, $url) === 1) {
                    unset($script[$i]);

                    return ['status' => $status, 'body' => $response];
                }
            }

            return ['status' => 200, 'body' => '{}'];
        });
    }

    /** @return list<array<string, mixed>> corps des appels à /v1/messages */
    private function messageCalls(): array
    {
        return array_values(array_map(
            static fn (array $c): array => (array)json_decode((string)$c['body'], true),
            array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'POST' && str_ends_with($c['url'], '/messages'))
        ));
    }

    // ------------------------------------------------------------- cache

    public function testChaqueRequeteDemandeLeCache(): void
    {
        $first = NativeDeck::anthropicPayload('claude-opus-5-5', 'Fais-la', 'file_tpl');
        $this->assertSame(['type' => 'ephemeral'], $first['cache_control'] ?? null,
            'Sans cache_control, la boucle d’exécution de code relit tout au prix fort (doc Anthropic).');

        $suite = NativeDeck::anthropicPayload('claude-opus-5-5', 'Fais-la', null,
            [['role' => 'user', 'content' => 'x'], ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'en cours']]]], 'cntr_1');
        $this->assertSame(['type' => 'ephemeral'], $suite['cache_control'] ?? null, 'La reprise d’un tour en pause aussi.');
    }

    public function testLaRealisationEnvoieLeCacheAChaqueAller(): void
    {
        $pause = json_encode(['stop_reason' => 'pause_turn', 'container' => ['id' => 'cntr_9'], 'content' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10]]);
        $fin = json_encode(['stop_reason' => 'end_turn', 'container' => ['id' => 'cntr_9'], 'content' => [
            ['type' => 'bash_code_execution_tool_result', 'content' => ['content' => [['file_id' => 'file_final']]]],
        ], 'usage' => ['input_tokens' => 200, 'output_tokens' => 20]]);
        $this->transport([
            ['POST', '#/v1/messages$#', 200, $pause],
            ['POST', '#/v1/messages$#', 200, $fin],
            ['GET', '#/v1/files/file_final$#', 200, json_encode(['id' => 'file_final', 'filename' => 'offre.pptx'])],
            ['GET', '#/v1/files/file_final/content$#', 200, self::PPTX],
        ]);

        NativeDeck::make('anthropic::claude-opus-5-5', "# Offre\n\n## A\n\n- un", 'Offre', 'offre.pptx', null, 'sk-ant-test');

        $posts = $this->messageCalls();
        $this->assertCount(2, $posts);
        foreach ($posts as $i => $p) {
            $this->assertSame(['type' => 'ephemeral'], $p['cache_control'] ?? null, "Aller $i : cache demandé.");
        }
    }

    // -------------------------------------------------------- estimation

    public function testLeTarifTypiqueDeClaudeCompteLeCache(): void
    {
        foreach (NativeDeck::TYPICAL_TOKENS['anthropic'] as $kind => $t) {
            $this->assertArrayHasKey('cached', $t, "$kind : une réalisation par Claude relit son contexte en cache.");
            $this->assertArrayHasKey('write', $t, $kind);
            $this->assertGreaterThan(0.8 * $t['input'], $t['cached'], "$kind : l'essentiel de l'entrée est relu.");
            $this->assertLessThan($t['input'], $t['cached'] + $t['write'], $kind);
        }
        // Une présentation par Claude relit au moins un million de jetons (constaté chez un client : 0,29 et 10,2 millions).
        $this->assertGreaterThanOrEqual(1000000, NativeDeck::TYPICAL_TOKENS['anthropic']['pptx']['input']);

        // Au tarif, le cache rend la réalisation bien moins chère qu'au prix fort.
        $t = NativeDeck::TYPICAL_TOKENS['anthropic']['pptx'];
        $avec = (int)NativeDeck::tariffCredits('anthropic::claude-opus-5-5', 'pptx');
        $sans = (int)CreditService::computeLlmCredits('anthropic::claude-opus-5-5', $t['input'], $t['output'])['total_credits'];
        $this->assertGreaterThan(0, $avec);
        $this->assertLessThan($sans / 3, $avec);
    }

    public function testLEstimationApprendDesRealisationsDeLOrganisation(): void
    {
        $this->assertSame(['credits' => 2100, 'basis' => 'instance', 'count' => 2], NativeDeck::estimate([], 1700, [2000, 2200]));
        $this->assertSame(['credits' => 900, 'basis' => 'history', 'count' => 1], NativeDeck::estimate([900], 1700, [2000]),
            'Les réalisations de la personne passent avant celles de l’organisation.');
        $this->assertSame(['credits' => 1700, 'basis' => 'tariff', 'count' => 0], NativeDeck::estimate([], 1700, []));
    }

    // ----------------------------------------------------------- plafond

    public function testLePlafondSuitLEstimation(): void
    {
        $this->assertSame(NativeDeck::CAP_FACTOR * 1700, NativeDeck::capCredits(1700));
        $this->assertSame(NativeDeck::CAP_MIN_CREDITS, NativeDeck::capCredits(300), 'Jamais en dessous du plancher.');
        $this->assertNull(NativeDeck::capCredits(null), 'Sans tarif, aucun plafond à calculer.');
        $this->assertNull(NativeDeck::capCredits(0));
    }

    /** Au-delà du plafond, la reprise n'est pas relancée ; le fichier déjà produit est livré. */
    public function testAuDelaDuPlafondLaRepriseNEstPasRelancee(): void
    {
        $pause = json_encode(['stop_reason' => 'pause_turn', 'container' => ['id' => 'cntr_7'], 'content' => [
            ['type' => 'bash_code_execution_tool_result', 'content' => ['content' => [['file_id' => 'file_v1']]]],
        ], 'usage' => ['input_tokens' => 3000000, 'output_tokens' => 2000]]);
        $this->transport([
            ['POST', '#/v1/messages$#', 200, $pause],
            ['GET', '#/v1/files/file_v1$#', 200, json_encode(['id' => 'file_v1', 'filename' => 'offre.pptx'])],
            ['GET', '#/v1/files/file_v1/content$#', 200, self::PPTX],
        ]);

        $made = NativeDeck::make('anthropic::claude-opus-5-5', "# Offre\n\n## A\n\n- un", 'Offre', 'offre.pptx', null, 'sk-ant-test',
            ['cap' => 500]);

        $this->assertCount(1, $this->messageCalls(), 'Plafond dépassé : aucune reprise.');
        $this->assertSame(self::PPTX, $made['bytes']);
        $this->assertSame(500, $made['capped'] ?? null, 'La livraison dit que le plafond a arrêté le travail.');
        $this->assertSame(3000000, $made['tokens']['input'], 'Les jetons consommés restent comptés.');
    }

    public function testAuPlafondSansFichierLaCauseEstDite(): void
    {
        $pause = json_encode(['stop_reason' => 'pause_turn', 'container' => ['id' => 'cntr_7'], 'content' => [],
            'usage' => ['input_tokens' => 3000000, 'output_tokens' => 2000]]);
        $this->transport([['POST', '#/v1/messages$#', 200, $pause]]);

        try {
            NativeDeck::make('anthropic::claude-opus-5-5', '# T', 'T', 't.pptx', null, 'sk-ant-test', ['cap' => 500]);
            $this->fail('Une exception était attendue.');
        } catch (NativeDeckException $e) {
            $this->assertStringContainsString('plafond', $e->getMessage());
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertSame(3000000, $e->tokens()['input'] ?? null, 'Les jetons payés sont facturés quand même.');
        }
        $this->assertCount(1, $this->messageCalls());
    }

    public function testSousLePlafondLaRepriseContinue(): void
    {
        $pause = json_encode(['stop_reason' => 'pause_turn', 'container' => ['id' => 'cntr_9'], 'content' => [],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);
        $fin = json_encode(['stop_reason' => 'end_turn', 'container' => ['id' => 'cntr_9'], 'content' => [
            ['type' => 'bash_code_execution_tool_result', 'content' => ['content' => [['file_id' => 'file_final']]]],
        ], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);
        $this->transport([
            ['POST', '#/v1/messages$#', 200, $pause],
            ['POST', '#/v1/messages$#', 200, $fin],
            ['GET', '#/v1/files/file_final$#', 200, json_encode(['id' => 'file_final', 'filename' => 'offre.pptx'])],
            ['GET', '#/v1/files/file_final/content$#', 200, self::PPTX],
        ]);

        $made = NativeDeck::make('anthropic::claude-opus-5-5', '# Offre', 'Offre', 'offre.pptx', null, 'sk-ant-test', ['cap' => 50000]);

        $this->assertCount(2, $this->messageCalls());
        $this->assertNull($made['capped'] ?? null);
    }

    // ------------------------------------------------------------ câblage

    public function testLePlafondVaDuLancementALaRealisation(): void
    {
        $src  = static fn (string $f): string => (string)file_get_contents(ROOT . '/' . $f);
        $chat = $src('src/Controllers/ChatController.php');
        $job  = $src('src/Jobs/NativeDeckJob.php');

        $this->assertSame(0, substr_count($chat, 'NativeDeck::estimate('), 'Une seule règle d’estimation : NativeDeck::estimateFor.');
        $this->assertGreaterThanOrEqual(3, substr_count($chat, 'NativeDeck::estimateFor('), 'Fenêtre, offre et lancement.');
        $this->assertStringContainsString("'cap'      => NativeDeck::capCredits(", $chat, 'Le lancement fixe le plafond.');
        $this->assertStringContainsString("'cap'      => \$cap,", $job, 'Le job le transmet à la réalisation.');
        $this->assertStringContainsString("'cap'      => \$cap !== null", $chat, 'La fenêtre l’annonce.');
        $this->assertStringContainsString('chosen.cap', $src('public/assets/js/generated-files.js'));
        $this->assertStringContainsString('NativeDeck::capped(', $job, 'Le message d’issue dit le plafond atteint.');
    }
}
