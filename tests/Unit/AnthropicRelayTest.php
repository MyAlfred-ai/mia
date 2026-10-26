<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Claude Fable 5.1 (et Fable 5, Opus 5) : trois contraintes gérées par MIA.
 *
 *  1. REFUS — les classifieurs déclinent certaines demandes. Le repli CÔTÉ
 *     SERVEUR (`fallbacks: "default"`, en-tête bêta) fait reprendre la demande
 *     par Opus dans la même requête ; la réponse dit quel modèle a répondu et
 *     chaque tentative facturée est comptée à SON tarif.
 *  2. CONSERVATION DES DONNÉES — Fable n'est ouvert qu'aux organisations qui
 *     conservent les données 30 jours ; une organisation en « zéro rétention »
 *     reçoit un 400 avant tout traitement. MIA rejoue la demande sur Opus 5.
 *  3. LIMITE DE SORTIE — le raisonnement, toujours actif, compte dans
 *     `max_tokens` : 4 096 tronquait les réponses longues. Relevée pour tous
 *     les modèles Claude (cf. AnthropicOutputCapTest), elle impose de
 *     supporter de longs silences (raisonnement muet) sans que le job ni le
 *     navigateur ne déclarent la réponse morte.
 */
final class AnthropicRelayTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setFallbacksRejected(false);
        LlmAdapterHelpers::setStopProbe(null);
        I18n::use('fr');
        parent::tearDown();
    }

    // ---------- Catalogue ----------

    public function testLesReglagesDesModelesSontAuCatalogue(): void
    {
        foreach (['claude-fable-5-1', 'claude-fable-5'] as $fable) {
            // Le plafond qu'il déclarait (32 000) est désormais en dessous du
            // défaut de l'adapter : il le suit, comme tous les modèles Claude.
            $this->assertNull(LlmCatalog::maxOutputTokens('anthropic', $fable), $fable);
            $this->assertSame(AnthropicAdapter::DEFAULT_MAX_TOKENS, AnthropicAdapter::maxTokensFor($fable), $fable);
            $this->assertSame('default', LlmCatalog::refusalFallback('anthropic', $fable), $fable);
            $this->assertSame('claude-opus-5', LlmCatalog::retentionFallback('anthropic', $fable), $fable);
        }
        // Opus 5 refuse aussi (classifieurs cyber) : même repli serveur, mais
        // aucune exigence de conservation. Même plafond que Fable.
        $this->assertSame('default', LlmCatalog::refusalFallback('anthropic', 'claude-opus-5'));
        $this->assertNull(LlmCatalog::retentionFallback('anthropic', 'claude-opus-5'));
        $this->assertSame(AnthropicAdapter::DEFAULT_MAX_TOKENS, AnthropicAdapter::maxTokensFor('claude-opus-5'));
        $this->assertSame(64000, AnthropicAdapter::DEFAULT_MAX_TOKENS);
        // Sans réglage : aucun repli, plafond par défaut.
        $this->assertNull(LlmCatalog::refusalFallback('anthropic', 'claude-sonnet-4-6'));
        $this->assertNull(LlmCatalog::maxOutputTokens('anthropic', 'modele-absent'));
    }

    public function testLeModeleDeRelaisDoitExisterEtDifferer(): void
    {
        // Un relais vers soi-même ou vers un modèle inconnu du catalogue
        // bouclerait ou tomberait en 404 : il est ignoré.
        $this->assertNull(LlmCatalog::retentionFallback('anthropic', 'modele-absent'));
        foreach (LlmCatalog::models('anthropic') as $id => $info) {
            if (!empty($info['retention_fallback'])) {
                $relais = LlmCatalog::retentionFallback('anthropic', (string)$id);
                $this->assertNotNull($relais, (string)$id);
                $this->assertNotSame($id, $relais);
                $this->assertNull(
                    LlmCatalog::retentionFallback('anthropic', $relais),
                    'Le relais ne doit pas avoir lui-même de relais : pas de chaîne.'
                );
            }
        }
    }

    // ---------- Requête : paramètre, en-tête, plafond ----------

    public function testLeRepliServeurVoyageAvecSonEnTeteBeta(): void
    {
        $adapter = $this->adapter('claude-fable-5-1');
        $payload = $this->payloadFor($adapter, 'claude-fable-5-1', 0.7);

        $this->assertSame('default', $payload['fallbacks']);
        $this->assertSame(AnthropicAdapter::DEFAULT_MAX_TOKENS, $payload['max_tokens']);
        $this->assertArrayNotHasKey('temperature', $payload, 'Fable rejette temperature (400).');
        $this->assertContains('anthropic-beta: server-side-fallback-2026-07-01', $this->headersFor($adapter, $payload));
        $this->assertSame('server-side-fallback-2026-07-01', AnthropicAdapter::REFUSAL_FALLBACK_BETA);

        // Modèle sans repli : ni paramètre ni en-tête (l'un sans l'autre est refusé).
        $sonnet = $this->payloadFor($adapter, 'claude-sonnet-4-6', 0.7);
        $this->assertArrayNotHasKey('fallbacks', $sonnet);
        $this->assertSame(0.7, $sonnet['temperature']);
        $this->assertSame(AnthropicAdapter::DEFAULT_MAX_TOKENS, $sonnet['max_tokens']);
        foreach ($this->headersFor($adapter, $sonnet) as $h) {
            $this->assertStringNotContainsString('anthropic-beta', $h);
        }
    }

    public function testUnRepliRefuseNEstPlusEnvoye(): void
    {
        $adapter = $this->adapter('claude-fable-5-1');
        $this->setFallbacksRejected(true);
        $payload = $this->payloadFor($adapter, 'claude-fable-5-1', null);
        $this->assertArrayNotHasKey('fallbacks', $payload);
        foreach ($this->headersFor($adapter, $payload) as $h) {
            $this->assertStringNotContainsString('anthropic-beta', $h);
        }
    }

    // ---------- Classement des 400 ----------

    /** @return array<string, array{?int, string, bool, bool}> */
    public static function erreurs400(): array
    {
        return [
            'conservation (message officiel)' => [400, 'In order to access this model, your organization or workspace must have data retention enabled.', true, false],
            'repli refusé'                    => [400, 'fallbacks: Extra inputs are not permitted', false, true],
            'en-tête bêta refusé'             => [400, 'Unexpected value(s) `server-side-fallback-2026-07-01` for the `anthropic-beta` header.', false, true],
            'autre 400'                       => [400, 'messages.0.content: Input should be a valid list', false, false],
            'pas un 400'                      => [500, 'data retention fallback', false, false],
            'sans statut'                     => [null, 'data retention', false, false],
        ];
    }

    #[DataProvider('erreurs400')]
    public function testLes400SeClassentSansAmbiguite(?int $status, string $msg, bool $conservation, bool $repli): void
    {
        $this->assertSame($conservation, AnthropicAdapter::isRetentionError($status, $msg));
        $this->assertSame($repli, AnthropicAdapter::isFallbacksRejection($status, $msg));
    }

    // ---------- Facturation par tentative (usage.iterations) ----------

    public function testUnRefusAvantToutTexteNeFactureQueLeRelais(): void
    {
        // Exemple de la documentation Anthropic : Fable décline avant toute
        // sortie (non facturé), Opus 4.8 sert la réponse.
        $u = AnthropicAdapter::relayUsage([
            ['type' => 'message', 'model' => 'claude-fable-5-1', 'input_tokens' => 535, 'output_tokens' => 0,
             'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ['type' => 'fallback_message', 'model' => 'claude-opus-4-8', 'input_tokens' => 412, 'output_tokens' => 264,
             'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 0],
        ], 'claude-fable-5-1');

        $this->assertSame('claude-opus-4-8', $u['model']);
        $this->assertSame(512, $u['tokens']['input'], 'Entrée TOTALE, cache compris — même sémantique que le reste de l\'adapter.');
        $this->assertSame(264, $u['tokens']['output']);
        $this->assertSame(100, $u['tokens']['input_cached']);
        $this->assertSame([], $u['additional'], 'Déclinée avant toute sortie : non facturée.');
    }

    public function testUnRefusEnCoursDeReponseFactureLaTentativeDeclinee(): void
    {
        $u = AnthropicAdapter::relayUsage([
            ['type' => 'message', 'model' => 'claude-fable-5-1', 'input_tokens' => 900, 'output_tokens' => 120],
            ['type' => 'fallback_message', 'model' => 'claude-opus-4-8', 'input_tokens' => 1000, 'output_tokens' => 300],
        ], 'claude-fable-5-1');

        $this->assertSame('claude-opus-4-8', $u['model']);
        $this->assertSame([[
            'model' => 'claude-fable-5-1', 'input' => 900, 'output' => 120, 'input_cached' => 0, 'input_cache_write' => 0,
        ]], $u['additional']);
    }

    public function testUnTourCollantEstServiParLeRelais(): void
    {
        // Routage collant (~1 h) : la conversation a déjà basculé, le relais
        // sert d'emblée — pas de bloc fallback, une seule itération.
        $u = AnthropicAdapter::relayUsage([
            ['type' => 'fallback_message', 'model' => 'claude-opus-4-8', 'input_tokens' => 50, 'output_tokens' => 20],
        ], 'claude-fable-5-1');
        $this->assertSame('claude-opus-4-8', $u['model']);
        $this->assertSame([], $u['additional']);
    }

    public function testSansRelaisLaLectureOrdinaireResteJuste(): void
    {
        $this->assertNull(AnthropicAdapter::relayUsage(null, 'claude-fable-5-1'));
        $this->assertNull(AnthropicAdapter::relayUsage([
            ['type' => 'message', 'model' => 'claude-fable-5-1', 'input_tokens' => 10, 'output_tokens' => 5],
        ], 'claude-fable-5-1'));
    }

    public function testUnIdentifiantInconnuEstTarifeCommeLeModeleDemande(): void
    {
        $u = AnthropicAdapter::relayUsage([
            ['type' => 'message', 'model' => 'claude-fable-5-1-20260901', 'input_tokens' => 10, 'output_tokens' => 5],
            ['type' => 'fallback_message', 'model' => 'claude-opus-4-8', 'input_tokens' => 10, 'output_tokens' => 5],
        ], 'claude-fable-5-1');
        $this->assertSame('claude-fable-5-1', $u['additional'][0]['model'], 'Sinon la ligne tomberait en « non tarifé ».');
    }

    // ---------- Contrat public des jetons ----------

    public function testLeContratPublicNommeLeModeleServiEtLesAutres(): void
    {
        $adapter = $this->adapter('claude-fable-5-1');
        $m = new ReflectionMethod(AnthropicAdapter::class, 'finalizeTokens');

        $byModel = [
            'claude-fable-5-1' => ['input' => 900, 'output' => 120, 'input_cached' => 0, 'input_cache_write' => 0],
            'claude-opus-4-8'  => ['input' => 1000, 'output' => 300, 'input_cached' => 0, 'input_cache_write' => 0],
        ];
        $t = $m->invoke($adapter, $byModel, 2, 'claude-opus-4-8', null);

        $this->assertSame(1000, $t['input']);
        $this->assertSame(300, $t['output']);
        $this->assertSame(2, $t['web_searches']);
        $this->assertSame('claude-opus-4-8', $t['model'], 'Le modèle servi est nommé : c\'est lui que le compteur tarife.');
        $this->assertSame('claude-fable-5-1', $t['additional'][0]['model']);
        $this->assertSame(['from' => 'claude-fable-5-1', 'to' => 'claude-opus-4-8', 'reason' => 'refusal'], $t['relay']);

        // Relais « conservation » : la cause première reste nommée.
        $t = $m->invoke($adapter, ['claude-opus-5' => ['input' => 10, 'output' => 5, 'input_cached' => 0, 'input_cache_write' => 0]], 0, 'claude-opus-5',
            ['from' => 'claude-fable-5-1', 'to' => 'claude-opus-5', 'reason' => 'retention']);
        $this->assertSame('retention', $t['relay']['reason']);
        $this->assertArrayNotHasKey('additional', $t);

        // Réponse du modèle demandé : contrat inchangé, aucune clé en plus.
        $t = $m->invoke($adapter, ['claude-fable-5-1' => ['input' => 10, 'output' => 5, 'input_cached' => 0, 'input_cache_write' => 0]], 0, 'claude-fable-5-1', null);
        $this->assertSame(['input', 'output', 'input_cached', 'input_cache_write', 'output_reasoning', 'web_searches'], array_keys($t));
    }

    // ---------- Trace du message ----------

    public function testLaTraceNommeLesDeuxModeles(): void
    {
        // Le contrôleur n'est pas chargé par le bootstrap des tests : sans ce
        // require, le test ne passait qu'après un autre qui l'avait chargé.
        require_once ROOT . '/src/Controllers/ChatController.php';
        $refus = ChatController::modelRelayEntry(['relay' => ['from' => 'claude-fable-5-1', 'to' => 'claude-opus-4-8', 'reason' => 'refusal']], 'anthropic');
        $this->assertSame('relais_refus', $refus['t']);
        $this->assertSame('Claude Fable 5.1 → Claude Opus 4.8', $refus['d']);
        $this->assertSame('claude-opus-4-8', $refus['to']);

        $conservation = ChatController::modelRelayEntry(['relay' => ['from' => 'claude-fable-5-1', 'to' => 'claude-opus-5', 'reason' => 'retention']], 'anthropic');
        $this->assertSame('relais_conservation', $conservation['t']);
        $this->assertSame('Claude Fable 5.1 → Claude Opus 5', $conservation['d']);

        $this->assertNull(ChatController::modelRelayEntry([], 'anthropic'));
        $this->assertNull(ChatController::modelRelayEntry(['relay' => ['from' => 'x', 'to' => 'x']], 'anthropic'));
        $this->assertSame('relay', ChatController::actionIcon('relais_refus'));
        $this->assertSame('relay', ChatController::actionIcon('relais_conservation'));
    }

    public function testLeMessageEtSonBadgePortentLeModeleQuiARepondu(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        // runSend ET regenerate : trace + modèle du message.
        $this->assertSame(2, substr_count($src, 'self::modelRelayEntry(is_array($tokens) ? $tokens : [], LLMFactory::provider($llm))'));
        $this->assertSame(2, substr_count($src, "\$llmModelForLog = \$relayEntry['to'];"));
    }

    // ---------- Erreur « conservation » quand aucun relais ne reprend ----------

    public function testLErreurDeConservationDitLeRemede(): void
    {
        $chunk = LlmAdapterHelpers::buildHttpErrorMessage('Anthropic', 400,
            'In order to access this model, your organization or workspace must have data retention enabled.');
        $desc = LlmErrorPresenter::parse($chunk);
        $this->assertSame('retention', $desc['kind']);

        $p = LlmErrorPresenter::present($desc);
        $this->assertSame('Modèle indisponible pour votre organisation', $p['title']);
        $this->assertStringContainsString('conserver leurs données 30 jours', $p['message']);
        $this->assertStringContainsString('Prévenez un administrateur', $p['message']);
        $this->assertFalse($p['retryable']);

        I18n::use('en');
        $this->assertSame('Model unavailable for your organization', LlmErrorPresenter::present($desc)['title']);

        // Un autre 400 reste un « bad_request ».
        $this->assertSame('bad_request', LlmErrorPresenter::parse('[Erreur Anthropic (400) : max_tokens: too large]')['kind']);
    }

    // ---------- Longs silences ----------

    public function testLeSigneDeVieBatLeCoeurSansRienEcrireHorsSse(): void
    {
        ob_start();
        $this->assertFalse(LlmAdapterHelpers::keepAlive());
        $this->assertSame('', (string)ob_get_clean(), 'En CLI (worker) : jamais d\'écriture, seulement la sonde.');

        $appels = 0;
        LlmAdapterHelpers::setStopProbe(static function () use (&$appels): bool {
            $appels++;
            return true;
        });
        $this->assertTrue(LlmAdapterHelpers::keepAlive(), 'Un arrêt demandé pendant le silence doit remonter.');
        $this->assertSame(1, $appels, 'La sonde (battement du job) est consultée à chaque signe de vie.');
    }

    public function testLesDeuxBouclesLisentLeFluxParTranches(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertSame(2, substr_count($src, 'stream_set_timeout($stream, self::SILENCE_TICK_SEC);'));
        $this->assertSame(2, substr_count($src, 'self::readSseLine($stream, $pending, $stalled, $stopped)'));
        $this->assertStringContainsString('LlmAdapterHelpers::keepAlive()', $src);
        $this->assertStringNotContainsString("'max_tokens'  => 4096", $src, 'Le plafond se lit au catalogue.');
        $this->assertGreaterThanOrEqual(600, AnthropicAdapter::SILENCE_MAX_SEC, 'Un raisonnement de plusieurs minutes est normal sur Fable.');
    }

    public function testUnSilenceNEcritDansUneReponseJsonJamais(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/LLM/LlmAdapterHelpers.php');
        $this->assertStringContainsString("stripos(\$header, 'text/event-stream')", $src);
        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $src);
    }

    // ---------- Boucle d'outils ----------

    public function testLesAppelsDOutilsAvantLaBasculeSontEcartes(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertMatchesRegularExpression(
            "/if \(\\\$btype === 'fallback'\) \{.*?\\\$blocks = \[\];\s*\\\$replayable = false;/s",
            $src,
            'Les tool_use reçus avant le bloc fallback appartiennent à la sortie déclinée : ni exécutés ni rejoués '
            . '(et le tour n\'est plus rejouable fidèlement).'
        );
    }

    public function testLesDeuxCheminsRejouentSurLeRelais(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/LLM/AnthropicAdapter.php');
        $this->assertSame(2, substr_count($src, "if (\$result['retry'] === 'retention') {") + substr_count($src, "if (\$res['retry'] === 'retention') {"));
        $this->assertSame(2, substr_count($src, "self::isRetentionError(\$httpStatus, \$errMsg)"));
        $this->assertSame(2, substr_count($src, "self::isFallbacksRejection(\$httpStatus, \$errMsg)"));
    }

    // ---------- Outils ----------

    private function adapter(string $model): AnthropicAdapter
    {
        $a = (new ReflectionClass(AnthropicAdapter::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AnthropicAdapter::class, 'apiKey'))->setValue($a, 'cle-de-test');
        (new ReflectionProperty(AnthropicAdapter::class, 'model'))->setValue($a, $model);
        return $a;
    }

    private function payloadFor(AnthropicAdapter $a, string $model, ?float $temperature): array
    {
        return (new ReflectionMethod(AnthropicAdapter::class, 'payloadFor'))
            ->invoke($a, ['stream' => true, 'system' => 's', 'messages' => []], $model, $temperature);
    }

    /** @return list<string> */
    private function headersFor(AnthropicAdapter $a, array $payload): array
    {
        return (new ReflectionMethod(AnthropicAdapter::class, 'headersFor'))->invoke($a, $payload);
    }

    private function setFallbacksRejected(bool $value): void
    {
        (new ReflectionProperty(AnthropicAdapter::class, 'fallbacksRejected'))->setValue(null, $value);
    }
}
