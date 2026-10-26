<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Estimations de crédits : réalistes et cohérentes partout.
 *
 * La règle : une estimation est un CALCUL DE FACTURATION (CreditService, mêmes
 * tarifs, même coefficient, même arrondi) appliqué à une consommation réelle
 * — jamais une échelle à part. Et ce qui est affiché après coup (badge d'une
 * réponse, total d'une conversation, solde) est ce que le rechargement
 * retrouvera.
 */
final class CreditEstimateTest extends TestCase
{
    private array $settingsAvant = [];

    protected function setUp(): void
    {
        // Réglages vides (tarifs du catalogue) sans lire la table.
        $cache  = new ReflectionProperty(Settings::class, 'cache');
        $loaded = new ReflectionProperty(Settings::class, 'loaded');
        $this->settingsAvant = [$cache->getValue(), $loaded->getValue()];
        $cache->setValue(null, []);
        $loaded->setValue(null, true);
        CreditEstimate::useLedger([]);
    }

    protected function tearDown(): void
    {
        CreditEstimate::forget();
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $this->settingsAvant[0]);
        (new ReflectionProperty(Settings::class, 'loaded'))->setValue(null, $this->settingsAvant[1]);
        I18n::use('fr');
    }

    private static function lire(string $chemin): string
    {
        $contenu = file_get_contents(ROOT . '/' . $chemin);
        if (!is_string($contenu)) {
            self::fail("Fichier introuvable : $chemin");
        }
        return $contenu;
    }

    private static function groupe(string $source, string $cle, int $n, int $in, int $out, int $cached = 0, int $reasoning = 0): array
    {
        return ['source' => $source, 'k' => $cle, 'n' => $n, 'i' => $in, 'c' => $cached, 'w' => 0, 'o' => $out, 'r' => $reasoning];
    }

    // ------------------------------------------------------------------
    // Consommation type
    // ------------------------------------------------------------------

    public function testLaMoyenneDuModeleFaitFoiPuisCelleDeLInstancePuisUneReponseType(): void
    {
        $rows = [
            self::groupe('chat', 'openai::gpt-5.6', 8, 12000, 900, 4000, 200),
            self::groupe('chat', 'anthropic::claude-opus-5', 2, 2000, 300),
            self::groupe('html_tool', 'openai::gpt-5.6', 20, 3000, 9000),
        ];
        $propre = CreditEstimate::profileFrom($rows, 'chat', 'openai::gpt-5.6', CreditEstimate::DEFAULT_CHAT_TOKENS);
        $this->assertSame(['input' => 12000, 'input_cached' => 4000, 'input_cache_write' => 0, 'output' => 900, 'output_reasoning' => 200, 'basis' => 'model', 'samples' => 8], $propre);

        // Trop peu de réponses de ce modèle : la moyenne de l'instance, pondérée.
        $instance = CreditEstimate::profileFrom($rows, 'chat', 'anthropic::claude-opus-5', CreditEstimate::DEFAULT_CHAT_TOKENS);
        $this->assertSame('instance', $instance['basis']);
        $this->assertSame(10, $instance['samples']);
        $this->assertSame((int)round((8 * 12000 + 2 * 2000) / 10), $instance['input']);
        $this->assertSame((int)round((8 * 900 + 2 * 300) / 10), $instance['output']);

        // Une autre source ne compte pas ; rien du tout : la réponse type.
        $vide = CreditEstimate::profileFrom($rows, 'map_tool', 'openai::gpt-5.6', ['input' => 3000, 'output' => 2500]);
        $this->assertSame('default', $vide['basis']);
        $this->assertSame(3000, $vide['input']);
        $this->assertSame(['input' => 5000, 'output' => 600], CreditEstimate::DEFAULT_CHAT_TOKENS, 'Une consigne système de MIA pèse déjà ~2 000 jetons.');
    }

    public function testUneEstimationEstUnCalculDeFacturation(): void
    {
        // Grand livre vide : la réponse type, au tarif que la facturation appliquerait.
        $attendu = CreditService::computeLlmCredits('anthropic::claude-opus-5', 5000, 600);
        $this->assertSame((int)$attendu['total_credits'], CreditEstimate::chatReply('anthropic::claude-opus-5')['credits']);
        $this->assertSame('default', CreditEstimate::chatReply('anthropic::claude-opus-5')['basis']);

        // Réponses réelles de ce modèle : leurs jetons moyens, cache et raisonnement compris.
        CreditEstimate::useLedger([self::groupe('chat', 'openai::gpt-5.6', 6, 20000, 1200, 15000, 300)]);
        $reel = CreditService::computeLlmCredits('openai::gpt-5.6', 20000, 1200, 15000, 0, 300);
        $this->assertSame((int)$reel['total_credits'], CreditEstimate::chatReply('openai::gpt-5.6')['credits']);

        // Un modèle sans aucun tarif : aucune estimation, jamais un chiffre inventé.
        $this->assertNull(CreditEstimate::chatReply('openai::modele-inconnu'));
        // Une recherche Internet : le tarif de la facturation, une seule recherche.
        $this->assertSame((int)CreditService::computeWebSearchCredits('anthropic::claude-opus-5', 1)['total_credits'], CreditEstimate::webSearch('anthropic::claude-opus-5'));
        $this->assertNull(CreditEstimate::webSearch('deepseek::deepseek-flash'), 'Pas de recherche native : rien à annoncer.');
    }

    public function testLesModelesSontRangesParCeQuIlsCoutentVraiment(): void
    {
        $cher   = CreditEstimate::chatReply('anthropic::claude-fable-5-1')['credits'];
        $econome = CreditEstimate::chatReply('openai::gpt-6-luna')['credits'];
        $this->assertGreaterThan($econome * 10, $cher, 'Fable coûte bien plus que Luna par réponse.');
        $stars = CreditEstimate::stars(['fable' => $cher, 'luna' => $econome, 'opus' => CreditEstimate::chatReply('anthropic::claude-opus-5')['credits'], 'sans' => null]);
        $this->assertSame(5, $stars['fable']);
        $this->assertSame(1, $stars['luna']);
        $this->assertGreaterThan(1, $stars['opus']);
        $this->assertLessThan(5, $stars['opus']);
        $this->assertArrayNotHasKey('sans', $stars, 'Sans estimation, pas de « € » inventés.');
        $this->assertSame(['seul' => 3], CreditEstimate::stars(['seul' => 12]));
        $this->assertSame([], CreditEstimate::stars([]));
        // Échelle logarithmique : un modèle hors norme n'écrase pas tous les autres au premier niveau.
        $this->assertSame(['a' => 1, 'b' => 3, 'c' => 5], CreditEstimate::stars(['a' => 3, 'b' => 30, 'c' => 300]));
    }

    public function testLesOrdresDeGrandeurSontRonds(): void
    {
        $this->assertSame(0, CreditEstimate::round(0));
        $this->assertSame(7, CreditEstimate::round(7));
        $this->assertSame(35, CreditEstimate::round(37));
        $this->assertSame(810, CreditEstimate::round(812));
        $this->assertSame(1200, CreditEstimate::round(1234));
        $this->assertSame(CreditEstimate::round(812), NativeDeck::roundCredits(812), 'Une seule règle d\'arrondi.');
    }

    public function testChaqueModeleDuCatalogueAUnTarif(): void
    {
        // Un modèle sans tarif est compté au MINIMUM par opération (1 crédit) :
        // une réponse d'o3 ou de GPT-5.4 valait 1 crédit au lieu de plusieurs
        // dizaines, et ni le sélecteur ni les estimations ne pouvaient le dire.
        $catalogue = require ROOT . '/config/llm_catalog.php';
        $sansTarif = [];
        foreach ($catalogue as $provider => $bloc) {
            foreach ((array)($bloc['models'] ?? []) as $id => $modele) {
                $p = $modele['pricing'] ?? null;
                if (!is_array($p) || !is_numeric($p['input'] ?? null) || !is_numeric($p['output'] ?? null)) {
                    $sansTarif[] = $provider . '::' . $id;
                } else {
                    $this->assertNotSame('', (string)($p['source_url'] ?? ''), "$provider::$id : un tarif sans source se périme en silence.");
                }
            }
        }
        $this->assertSame([], $sansTarif);
        // Les tarifs relus le 2026-10-17 (page Pricing d'OpenAI, traitement standard).
        $this->assertSame(['input' => 2.0, 'output' => 8.0, 'cached' => 0.5], self::tarif('o3'));
        $this->assertSame(['input' => 2.5, 'output' => 10.0, 'cached' => 1.25], self::tarif('gpt-4o'), 'Cache à 50 %, pas 10 %.');
        $this->assertSame(['input' => 2.5, 'output' => 15.0, 'cached' => 0.25], self::tarif('gpt-5.4'));
        $this->assertSame(['input' => 30.0, 'output' => 180.0, 'cached' => null], self::tarif('gpt-5.5-pro'));
    }

    private static function tarif(string $model): array
    {
        $p = LlmCatalog::pricing('openai', $model);
        $f = static fn($v): ?float => is_numeric($v) ? (float)$v : null;
        return ['input' => $f($p['input'] ?? null), 'output' => $f($p['output'] ?? null), 'cached' => $f($p['cached_input'] ?? null)];
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    public function testUneImageEstChiffreeAvecLeModeleDeSonOutil(): void
    {
        // Modèle facturé au jeton : une image type (1024×1024, qualité moyenne).
        $flare = ['id' => 901, 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2.5-flare', 'config_json' => '{}'];
        $attendu = CreditService::computeToolCredits(901, 'image', ['model' => 'gpt-image-2.5-flare', 'size' => '1024x1024', 'quality' => '', 'count' => 1, 'usage' => CreditEstimate::TYPICAL_IMAGE_USAGE]);
        $this->assertTrue($attendu['priced']);
        $this->assertSame(['credits' => (int)$attendu['total_credits'], 'basis' => 'tariff', 'samples' => 0], CreditEstimate::toolUse($flare));
        $this->assertGreaterThan(10, (int)$attendu['total_credits'], 'Une image GPT n\'est pas « la moins chère » faute de tarif.');

        // Modèle à l'image : son prix public.
        $nano = ['id' => 902, 'type' => 'image', 'provider' => 'gemini', 'model' => 'gemini-3.1-flash-image', 'config_json' => '{}'];
        $this->assertSame('tariff', CreditEstimate::toolUse($nano)['basis']);

        // Générations réelles de l'outil : leur moyenne fait foi.
        CreditEstimate::useLedger([], [901 => ['n' => 4, 'avg' => 52.4]]);
        $this->assertSame(['credits' => 52, 'basis' => 'history', 'samples' => 4], CreditEstimate::toolUse($flare));
        CreditEstimate::useLedger([], [901 => ['n' => 2, 'avg' => 52.4]]);
        $this->assertSame('tariff', CreditEstimate::toolUse($flare)['basis'], 'Deux générations ne suffisent pas.');

        // Une page web : la rédaction par le modèle de l'outil ; un outil externe : rien.
        $page = ['id' => 903, 'type' => 'html', 'provider' => 'anthropic', 'model' => 'claude-opus-5', 'config_json' => '{}'];
        $this->assertSame((int)CreditService::computeLlmCredits('anthropic::claude-opus-5', 4000, 9000)['total_credits'], CreditEstimate::toolUse($page)['credits']);
        $this->assertNull(CreditEstimate::toolUse(['id' => 904, 'type' => 'n8n', 'provider' => 'n8n', 'model' => 'webhook']));
    }

    // ------------------------------------------------------------------
    // Tarifs & crédits : le tarif public qu'applique la facturation
    // ------------------------------------------------------------------

    public function testLEcranDesTarifsConnaitLeTarifPublicApplique(): void
    {
        $opus = CreditService::catalogUnitCost('llm:anthropic::claude-opus-5:input');
        $this->assertSame('1m_tokens', $opus['unit']);
        $this->assertEqualsWithDelta(CreditService::usdToEur((float)LlmCatalog::pricing('anthropic', 'claude-opus-5')['input']), $opus['eur'], 1e-9);
        $this->assertNotNull(CreditService::catalogUnitCost('llm:openai::gpt-5.6:input_cached'));
        $this->assertSame('request', CreditService::catalogUnitCost('websearch:anthropic')['unit']);
        $this->assertSame(['eur' => 0.0, 'unit' => '1m_tokens'], CreditService::catalogUnitCost('llm:local::mon-modele:output'), 'Gratuit, pas « non tarifé ».');
        $this->assertNull(CreditService::catalogUnitCost('llm:openai::modele-inconnu:input'));
        $this->assertNull(CreditService::catalogUnitCost('n_importe_quoi'));

        $page = self::lire('templates/admin/credits.php');
        $this->assertStringContainsString('function effectiveCost(service)', $page);
        $this->assertStringContainsString('const real = effectiveCost(service).eur * qty;', $page, 'Crédits par unité, simulateur et rapport : le coût de la facturation.');
        $this->assertStringContainsString('Tarif public appliqué', $page);
        $this->assertStringContainsString('Non tarifé : compté au minimum', $page);
        $admin = self::lire('src/Controllers/AdminController.php');
        $this->assertStringContainsString("'catalog_costs' => (object)\$catalogCosts,", $admin);
        $this->assertStringContainsString('CreditService::catalogUnitCost($key)', $admin);
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLeChatAfficheLesEstimationsDeLaFacturation(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertStringNotContainsString('computeLlmStars', $chat, 'Plus d\'échelle maison lue dans les seules lignes renseignées.');
        $this->assertStringNotContainsString('choosePreferredLlmCostService', $chat);
        $this->assertSame(2, substr_count($chat, '[$llmStars, $llmCredits, $llmSearchCredits] = self::llmCostEstimates($llms);'));
        $this->assertSame(2, substr_count($chat, '[$toolStars, $toolCredits] = self::toolCostEstimates('));
        $this->assertStringContainsString('CreditEstimate::chatReply((string)$llmKey)', $chat);
        $this->assertStringContainsString('CreditEstimate::toolUse($tool)', $chat);

        $page = self::lire('templates/chat/index.php');
        $this->assertStringContainsString('data-cost-title="<?= htmlspecialchars($llmCostTitle((string)$key)) ?>"', $page);
        $this->assertStringContainsString("t('≈ {n} crédits par réponse'", $page);
        $this->assertStringContainsString("t('≈ {n} crédits par génération'", $page);
        $this->assertStringContainsString("\$llmInfoData[\$llmKey]['credits']", $page);
        $this->assertStringContainsString("te('Crédits utilisés dans cette conversation :')", $page, 'Ce qui a été débité n\'est pas une « estimation ».');
        $this->assertStringNotContainsString('Estimation totale sur la conversation', $page);

        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("t('≈ {n} crédits par réponse', { n: formatInt(credits) })", $js);
        $this->assertStringContainsString("modelCostTitle: item.dataset.costTitle || ''", $js);
    }

    public function testLeBadgeDUneReponseDitToutCeQueLeTourACoute(): void
    {
        $this->assertSame(['title', 'memory', 'task_rules', 'image_prompt', 'kb_query', 'context_summary', 'tool_condense'], UsageSource::TURN_HELPERS);
        $this->assertNotContains(UsageSource::FOLLOW_UP, UsageSource::TURN_HELPERS, 'Demandée après coup : au compte de la conversation.');
        $this->assertNotContains(UsageSource::MAIL_ASSIST, UsageSource::TURN_HELPERS);

        $chat = self::lire('src/Controllers/ChatController.php');
        // Envoi : repère au début du tour, rattachement et lecture APRÈS le titre.
        $send = substr($chat, (int)strpos($chat, '    public function runSend('));
        $this->assertLessThan(800, strpos($send, '$turnLedgerStart = UsageLog::lastId();'));
        $titre  = strpos($send, '$this->generateConversationTitle($adapter');
        $lien   = strpos($send, "UsageLog::attachTurn(\$convId, (int)\$user['id'], (int)\$msg['id'], \$turnLedgerStart);\n        \$turnCredits = max(\$turnCredits, UsageLog::creditsForMessage((int)\$msg['id']));");
        $total  = strrpos(substr($send, 0, (int)strpos($send, "'done' => true")), 'UsageLog::conversationCreditsTotal(');
        $this->assertIsInt($titre);
        $this->assertIsInt($lien);
        $this->assertGreaterThan($titre, $lien, 'Le titre fait partie du coût affiché en direct.');
        $this->assertGreaterThan($lien, $total);
        // Régénération et image : même règle.
        $this->assertStringContainsString("\$regenCredits = max((int)\$creditResult['total_credits'], UsageLog::creditsForMessage((int)\$msg['id']));", $chat);
        $this->assertStringContainsString("UsageLog::attachTurn(\$convId, \$userId, (int)\$msg['id'], \$turnLedgerStart);\n        \$imageCredits = max(\$imageCredits, UsageLog::creditsForMessage((int)\$msg['id']));", $chat);
        // Ce qui coûte hors d'un tour remet le total et le solde à jour aussitôt.
        $this->assertStringContainsString("'conversation_credits_total' => UsageLog::conversationCreditsTotal(\$convId, (int)\$user['id']),\n                'quota_status'               => \$this->currentCreditQuotaStatus(\$user),", $chat);
        $this->assertStringContainsString("\$reply['conversation_credits_total'] = UsageLog::conversationCreditsTotal(\$convId, (int)\$user['id']);", $chat);
        $js = self::lire('public/assets/js/chat.js');
        $this->assertSame(2, substr_count($js, "applyConversationCreditsTotal(data.conversation_credits_total);\n"));
    }

    public function testLeCoutDUnWorkflowLitLaMoyenneDeSonModele(): void
    {
        $wf = self::lire('src/Workflow/WorkflowCatalog.php');
        $this->assertStringNotContainsString("\$r['llm_provider'] . '::' . \$r['model']", $wf, 'La clé « fournisseur::modèle::modèle » ne désignait aucun modèle.');
        foreach (['CreditEstimate::chatReply($llm)', 'CreditEstimate::webSearch($llm)', 'CreditEstimate::searchesPerReply()',
                  'CreditEstimate::toolUse($tool)', 'CreditEstimate::helperCall(UsageSource::WORKFLOW_JUDGE'] as $appel) {
            $this->assertStringContainsString($appel, $wf, $appel);
        }
    }

    public function testLesTextesSontTraduits(): void
    {
        I18n::use('en');
        $this->assertSame('≈ 35 credits per answer', t('≈ {n} crédits par réponse', ['n' => 35]));
        $this->assertSame('Credits used in this conversation:', t('Crédits utilisés dans cette conversation :'));
        $this->assertSame('≈ 12 credits per generation', t('≈ {n} crédits par génération', ['n' => 12]));
    }
}
