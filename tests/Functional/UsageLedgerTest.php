<?php

declare(strict_types=1);

/**
 * Grand livre des opérations IA : calcul des crédits (CreditService),
 * compteur unique (UsageMeter), agrégats par catégorie / quota (UsageLog,
 * CreditQuota). Tout passe par la vraie base : la migration 067 (colonnes
 * quantity / kb_id / priced / quota_exempt / source 40) est exercée.
 */
final class UsageLedgerTest extends DatabaseTestCase
{
    private const FX = 1.08;

    protected function setUp(): void
    {
        parent::setUp();
        // Tarifs de test : gpt-test configuré (2 €/1M in, 6 €/1M out), marge 1,0,
        // valeur du crédit 0,001 €, arrondi supérieur, minimum 1 crédit.
        Settings::set('credits_config_json', json_encode([
            'version' => 1,
            'global'  => [
                'credit_value_eur'          => 0.001,
                'rounding_mode'             => 'ceil',
                'margin_multiplier'         => 1.0,
                'fixed_fee_eur'             => 0.0,
                'min_credits_per_operation' => 1,
                'fx_rate_eur_usd'           => self::FX,
            ],
            'services' => [
                ['id' => 's-in',  'name' => 'in',  'provider' => 'Openai', 'type' => 'llm_text', 'unit' => '1m_input_tokens',  'real_cost_eur' => 2.0, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'llm:openai::gpt-test:input'],
                ['id' => 's-out', 'name' => 'out', 'provider' => 'Openai', 'type' => 'llm_text', 'unit' => '1m_output_tokens', 'real_cost_eur' => 6.0, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'llm:openai::gpt-test:output'],
                // Ligne semée sans prix : ne doit PAS masquer le tarif curé du catalogue.
                ['id' => 's-zero', 'name' => 'zero', 'provider' => 'Openai', 'type' => 'llm_text', 'unit' => '1m_input_tokens', 'real_cost_eur' => 0.0, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'llm:openai::gpt-5.6:input'],
                ['id' => 's-img', 'name' => 'img', 'provider' => 'Openai', 'type' => 'image', 'unit' => 'image_generated', 'real_cost_eur' => 0.04, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'tool:1'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            Settings::set('credits_config_json', '');
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // CreditService
    // ------------------------------------------------------------------

    public function testServiceConfigureCoutReelEtCredits(): void
    {
        $r = CreditService::computeLlmCredits('openai::gpt-test', 1_000_000, 500_000);
        // 2 € + 3 € = 5 € de coût réel → 5 000 crédits à 0,001 €.
        $this->assertEqualsWithDelta(5.0, $r['cost_eur'], 0.000001);
        $this->assertSame(5000, $r['total_credits']);
        $this->assertTrue($r['priced']);
        $this->assertSame('llm:openai::gpt-test:input', $r['components']['input']['catalog_key']);
    }

    public function testMargeFraisFixesEtArrondiUneFoisParOperation(): void
    {
        Settings::set('credits_config_json', json_encode([
            'global' => ['credit_value_eur' => 0.01, 'rounding_mode' => 'ceil', 'margin_multiplier' => 2.0,
                         'fixed_fee_eur' => 0.05, 'min_credits_per_operation' => 0],
            'services' => [
                ['id' => 'a', 'name' => 'a', 'provider' => 'x', 'type' => 'llm_text', 'unit' => '1m_input_tokens',  'real_cost_eur' => 1.0, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'llm:openai::gpt-test:input'],
                ['id' => 'b', 'name' => 'b', 'provider' => 'x', 'type' => 'llm_text', 'unit' => '1m_output_tokens', 'real_cost_eur' => 1.0, 'multiplier' => 1.0, 'active' => true, 'catalog_key' => 'llm:openai::gpt-test:output'],
            ],
        ]));
        // 10 000 tokens in + 10 000 out à 1 €/1M = 0,01 € + 0,01 € = 0,02 € réel.
        // Facturé : 0,02 × 2 + 0,05 (frais fixes UNE fois) = 0,09 € → 9 crédits.
        // Par composante, on aurait eu (0,01×2+0,05)/0,01 = 7 → ceil 7 + 7 = 14.
        $r = CreditService::computeLlmCredits('openai::gpt-test', 10_000, 10_000);
        $this->assertEqualsWithDelta(0.02, $r['cost_eur'], 0.000001);
        $this->assertEqualsWithDelta(0.09, $r['billed_eur'], 0.000001);
        $this->assertSame(9, $r['total_credits']);
    }

    public function testRepliSurLeTarifCureDuCatalogue(): void
    {
        try {
            // gpt-5.6 : 5 $ / 30 $ au catalogue (hors promotion) ; la ligne
            // configurée à 0 € est ignorée.
            LlmCatalog::setToday('2026-11-22');
            $r = CreditService::computeLlmCredits('openai::gpt-5.6', 1_000_000, 0);
            $this->assertTrue($r['priced']);
            $this->assertEqualsWithDelta(5.0 / self::FX, $r['cost_eur'], 0.0001);
            $this->assertSame('catalog:llm:openai::gpt-5.6:input', $r['components']['input']['catalog_key']);

            // Cache lu : tarif `cached_input` du catalogue (0,50 $).
            $cached = CreditService::computeLlmCredits('openai::gpt-5.6', 1_000_000, 0, 1_000_000);
            $this->assertEqualsWithDelta(0.5 / self::FX, $cached['cost_eur'], 0.0001);

            // Promotion d'OpenAI (4 $, cache 0,40 $) jusqu'au 21/11/2026 inclus.
            LlmCatalog::setToday('2026-11-21');
            $promo = CreditService::computeLlmCredits('openai::gpt-5.6', 1_000_000, 0);
            $this->assertEqualsWithDelta(4.0 / self::FX, $promo['cost_eur'], 0.0001);
            $promoLu = CreditService::computeLlmCredits('openai::gpt-5.6', 1_000_000, 0, 1_000_000);
            $this->assertEqualsWithDelta(0.4 / self::FX, $promoLu['cost_eur'], 0.0001);
        } finally {
            LlmCatalog::setToday(null);
        }
    }

    /**
     * Les deux modèles frontière de septembre 2026 sortent des ratios
     * conventionnels : la lecture de cache de Fable 5.1 vaut 0,025× l'entrée
     * (le ratio par défaut, 0,1×, en ferait 1 $), et GPT-6 Astra facture
     * l'écriture de cache. Déclarés au catalogue, ces tarifs priment.
     */
    public function testTarifsCuresDeFable51EtGpt6Astra(): void
    {
        $f = CreditService::computeLlmCredits('anthropic::claude-fable-5-1', 1_000_000, 1_000_000);
        $this->assertTrue($f['priced']);
        $this->assertEqualsWithDelta((10.0 + 50.0) / self::FX, $f['cost_eur'], 0.0001);

        $lu = CreditService::computeLlmCredits('anthropic::claude-fable-5-1', 1_000_000, 0, 1_000_000);
        $this->assertEqualsWithDelta(0.25 / self::FX, $lu['cost_eur'], 0.0001);

        $a = CreditService::computeLlmCredits('openai::gpt-6-astra', 1_000_000, 1_000_000);
        $this->assertTrue($a['priced']);
        $this->assertEqualsWithDelta((10.0 + 50.0) / self::FX, $a['cost_eur'], 0.0001);

        // 1M tokens écrits en cache : 12,50 $ ; 1M lus : 1 $.
        $ecrit = CreditService::computeLlmCredits('openai::gpt-6-astra', 1_000_000, 0, 0, 1_000_000);
        $this->assertEqualsWithDelta(12.5 / self::FX, $ecrit['cost_eur'], 0.0001);
        $luAstra = CreditService::computeLlmCredits('openai::gpt-6-astra', 1_000_000, 0, 1_000_000);
        $this->assertEqualsWithDelta(1.0 / self::FX, $luAstra['cost_eur'], 0.0001);
    }

    /**
     * Gemini 3.8 Flash de bout en bout : jetons tels que l'adapter les lit dans
     * `usageMetadata` (réflexion comprise dans la sortie), tarif de lancement
     * jusqu'au 31/12/2026 puis tarif normal, recherche Google à la requête
     * (14 $) — quand la génération 2.5 la facture encore au prompt ancré (35 $).
     */
    public function testGemini38FlashReflexionLancementEtRecherche(): void
    {
        $jetons = GeminiAdapter::tokensFromUsage([
            'promptTokenCount'     => 1_000_000,
            'candidatesTokenCount' => 600_000,
            'thoughtsTokenCount'   => 400_000,
        ]);

        try {
            LlmCatalog::setToday('2026-09-16');
            $lancement = CreditService::computeLlmCredits('gemini::gemini-3.8-flash', $jetons['input'], $jetons['output'], 0, 0, $jetons['output_reasoning']);
            $this->assertTrue($lancement['priced']);
            // 1M en entrée à 0,75 $ + 1M en sortie (600k réponse + 400k réflexion) à 3,75 $.
            $this->assertEqualsWithDelta((0.75 + 3.75) / self::FX, $lancement['cost_eur'], 0.0001);

            LlmCatalog::setToday('2027-01-01');
            $normal = CreditService::computeLlmCredits('gemini::gemini-3.8-flash', $jetons['input'], $jetons['output'], 0, 0, $jetons['output_reasoning']);
            $this->assertEqualsWithDelta((1.50 + 7.50) / self::FX, $normal['cost_eur'], 0.0001);
        } finally {
            LlmCatalog::setToday(null);
        }

        $recherches = CreditService::computeWebSearchCredits('gemini::gemini-3.8-flash', 3);
        $this->assertTrue($recherches['priced']);
        $this->assertEqualsWithDelta(3 * 14.0 / 1000 / self::FX, $recherches['cost_eur'], 0.0001);

        $ancre = CreditService::computeWebSearchCredits('gemini::gemini-2.5-pro', 1);
        $this->assertEqualsWithDelta(35.0 / 1000 / self::FX, $ancre['cost_eur'], 0.0001);
    }

    public function testOperationNonTarifeeEstSignaleeEtCredteeAuMinimum(): void
    {
        $r = CreditService::computeLlmCredits('zai::modele-inconnu', 1000, 1000);
        $this->assertFalse($r['priced']);
        $this->assertSame(0.0, $r['cost_eur']);
        $this->assertSame(1, $r['total_credits']); // minimum configuré

        // Rien consommé → rien facturé, et ce n'est pas « non tarifé ».
        $vide = CreditService::computeLlmCredits('zai::modele-inconnu', 0, 0);
        $this->assertSame(0, $vide['total_credits']);
        $this->assertTrue($vide['priced']);
    }

    public function testFournisseurLocalGratuitMaisTarife(): void
    {
        $r = CreditService::computeLlmCredits('local::llama', 5000, 5000);
        $this->assertTrue($r['priced']);
        $this->assertSame(0.0, $r['cost_eur']);
        $this->assertSame(1, $r['total_credits']); // minimum par opération
    }

    public function testEmbeddingsRechercheWebEtDictee(): void
    {
        $e = CreditService::computeEmbeddingCredits('openai', 'text-embedding-3-large', 1_000_000);
        $this->assertTrue($e['priced']);
        $this->assertEqualsWithDelta(0.13 / self::FX, $e['cost_eur'], 0.0001);

        $w = CreditService::computeWebSearchCredits('anthropic::claude-opus-5', 3);
        $this->assertTrue($w['priced']);
        $this->assertEqualsWithDelta(3 * 10.0 / 1000 / self::FX, $w['cost_eur'], 0.0001);

        $t = CreditService::computeTranscriptionCredits('gpt-4o-transcribe', 2.5);
        $this->assertTrue($t['priced']);
        $this->assertEqualsWithDelta(2.5 * 0.006 / self::FX, $t['cost_eur'], 0.0001);

        // Fournisseur sans tarif de recherche : non tarifé, minimum facturé.
        $u = CreditService::computeWebSearchCredits('deepseek::deepseek-flash', 1);
        $this->assertFalse($u['priced']);
        $this->assertSame(1, $u['total_credits']);
    }

    public function testOutilConfigureEtOutilSansTarif(): void
    {
        $ok = CreditService::computeToolCredits(1, 'image', ['model' => 'gpt-image-2', 'count' => 1]);
        $this->assertTrue($ok['priced']);
        $this->assertEqualsWithDelta(0.04, $ok['cost_eur'], 0.000001);
        $this->assertSame(40, $ok['total_credits']);

        $ko = CreditService::computeToolCredits(99, 'video', ['seconds' => 8]);
        $this->assertFalse($ko['priced']);
        $this->assertSame(1, $ko['total_credits']);
    }

    // ------------------------------------------------------------------
    // UsageMeter → grand livre
    // ------------------------------------------------------------------

    private function seedUserAndConversation(): array
    {
        $this->insertUser(1);
        $this->db()->exec("INSERT INTO conversations (id, user_id, title) VALUES (10, 1, 'conv')");
        $this->db()->exec("INSERT INTO messages (id, conversation_id, role, content) VALUES (100, 10, 'assistant', 'ok')");
        return ['user_id' => 1, 'conversation_id' => 10, 'message_id' => 100];
    }

    public function testLeCompteurJournaliseUnAppelLlmAvecSesRecherchesWeb(): void
    {
        $ctx = $this->seedUserAndConversation();
        $r = UsageMeter::llm('openai::gpt-test', [
            'input' => 100_000, 'output' => 50_000, 'input_cached' => 20_000, 'web_searches' => 2,
        ], UsageSource::CHAT, $ctx);

        $this->assertTrue($r['logged']);
        $this->assertSame(2, $r['web_searches']);
        $rows = $this->db()->query('SELECT * FROM usage_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);

        $chat = $rows[0];
        $this->assertSame('chat', $chat['source']);
        $this->assertSame('openai::gpt-test', $chat['llm_provider']);
        $this->assertSame('gpt-test', $chat['model']);
        $this->assertSame(100000, (int)$chat['tokens_input']);
        $this->assertSame(20000, (int)$chat['tokens_input_cached']);
        $this->assertSame(100, (int)$chat['message_id']);
        $this->assertSame(0, (int)$chat['quota_exempt']);
        $this->assertSame(1, (int)$chat['priced']);

        $ws = $rows[1];
        $this->assertSame('web_search', $ws['source']);
        $this->assertSame(2.0, (float)$ws['quantity']);
        $this->assertSame(0, (int)$ws['tokens_input']);
        $this->assertSame(100, (int)$ws['message_id']);
        // Le résultat cumule tokens + recherches : c'est le coût du tour.
        $this->assertSame((int)$chat['credits_used'] + (int)$ws['credits_used'], $r['total_credits']);
        $this->assertSame($r['total_credits'], UsageLog::creditsByMessage(10, 1)[100]);
    }

    public function testRienNestJournaliseSansConsommation(): void
    {
        $ctx = $this->seedUserAndConversation();
        $r = UsageMeter::llm('openai::gpt-test', ['input' => 0, 'output' => 0], UsageSource::CHAT, $ctx);
        $this->assertFalse($r['logged']);
        $this->assertSame(0, $r['total_credits']);
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM usage_logs')->fetchColumn());
    }

    public function testLeModeleReellementAppeleEstFacture(): void
    {
        $this->assertSame('openai::gpt-5.6', UsageMeter::effectiveLlmKey('openai::o3', ['model' => 'gpt-5.6']));
        $this->assertSame('openai::o3', UsageMeter::effectiveLlmKey('openai::o3', ['model' => 'o3']));
        $this->assertSame('openai::o3', UsageMeter::effectiveLlmKey('openai::o3', []));

        $ctx = $this->seedUserAndConversation();
        UsageMeter::llm('openai::o3', ['input' => 1000, 'output' => 100, 'model' => 'gpt-test'], UsageSource::CHAT, $ctx);
        $row = $this->db()->query('SELECT llm_provider, model FROM usage_logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('openai::gpt-test', $row['llm_provider']);
        $this->assertSame('gpt-test', $row['model']);
    }

    /**
     * Repli après un refus : Fable 5.1 a décliné EN COURS de réponse (sortie
     * facturée, au tarif de Fable), Opus 4.8 a repris et servi la réponse. Une
     * ligne par modèle, chacune à son tarif, même message — additionner les
     * tokens sous un seul modèle fausserait le coût dans un sens ou l'autre.
     */
    public function testUnRelaisDeModeleFactureChaqueModeleASonTarif(): void
    {
        $ctx = $this->seedUserAndConversation();
        $r = UsageMeter::llm('anthropic::claude-fable-5-1', [
            'input' => 1_000_000, 'output' => 0,
            'model' => 'claude-opus-4-8',
            'additional' => [['model' => 'claude-fable-5-1', 'input' => 0, 'output' => 100_000]],
        ], UsageSource::CHAT, $ctx);

        $rows = $this->db()->query('SELECT llm_provider, model, tokens_input, tokens_output, cost_eur, message_id FROM usage_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('claude-opus-4-8', $rows[0]['model'], 'La réponse est facturée au modèle qui l\'a produite.');
        $this->assertEqualsWithDelta(5.0 / self::FX, (float)$rows[0]['cost_eur'], 0.0001);
        $this->assertSame('claude-fable-5-1', $rows[1]['model']);
        $this->assertSame(100_000, (int)$rows[1]['tokens_output']);
        $this->assertEqualsWithDelta(5.0 / self::FX, (float)$rows[1]['cost_eur'], 0.0001); // 100k × 50 $/M
        $this->assertSame($rows[0]['message_id'], $rows[1]['message_id'], 'Même message : le badge somme les deux lignes.');

        $this->assertEqualsWithDelta(10.0 / self::FX, $r['cost_eur'], 0.0001);
        $this->assertTrue($r['priced']);

        // Une entrée sans modèle n'est jamais journalisée (et ne casse rien).
        UsageMeter::llm('anthropic::claude-opus-5', ['input' => 10, 'output' => 10, 'additional' => [['input' => 5]]], UsageSource::CHAT, $ctx);
        $this->assertSame(3, (int)$this->db()->query('SELECT COUNT(*) FROM usage_logs')->fetchColumn());
    }

    public function testLeCompteurNeFaitJamaisEchouerLoperationMetier(): void
    {
        // user_id inexistant → violation de clé étrangère : tracée, pas levée.
        $r = UsageMeter::llm('openai::gpt-test', ['input' => 10, 'output' => 10], UsageSource::CHAT, ['user_id' => 424242]);
        $this->assertFalse($r['logged']);
        $this->assertGreaterThan(0, $r['total_credits']); // le calcul, lui, est rendu
    }

    public function testAlimentationDuneBaseHorsQuotaImputeeAuCreateur(): void
    {
        $this->insertUser(1);
        $this->db()->exec(
            "INSERT INTO knowledge_bases (id, name, embedding_provider, embedding_model, embedding_dims, created_by)
             VALUES (7, 'Base', 'openai', 'text-embedding-3-large', 1536, 1)"
        );
        $e = UsageMeter::embeddings('openai', 'text-embedding-3-large', 200_000, UsageSource::KB_EMBEDDING, ['user_id' => 1, 'kb_id' => 7]);
        $this->assertTrue($e['logged']);
        $c = UsageMeter::llm('openai::gpt-test', ['input' => 50_000, 'output' => 1_000], UsageSource::KB_CHUNKING, ['user_id' => 1, 'kb_id' => 7]);
        $this->assertTrue($c['logged']);
        // … et une conversation ordinaire du même utilisateur.
        UsageMeter::llm('openai::gpt-test', ['input' => 1_000_000, 'output' => 0], UsageSource::CHAT, ['user_id' => 1]);

        $rows = $this->db()->query('SELECT source, kb_id, quota_exempt, model FROM usage_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame('kb_embedding', $rows[0]['source']);
        $this->assertSame(7, (int)$rows[0]['kb_id']);
        $this->assertSame(1, (int)$rows[0]['quota_exempt']);
        $this->assertSame('text-embedding-3-large', $rows[0]['model']);
        $this->assertSame(1, (int)$rows[1]['quota_exempt']);
        $this->assertSame(0, (int)$rows[2]['quota_exempt']);

        $snap = UsageLog::creditsBreakdownThisMonth(1);
        $this->assertSame(2000, $snap['quota_credits']);            // le chat seul (1M × 2 €)
        $this->assertSame($snap['total_credits'] - 2000, $snap['exempt_credits']);
        $this->assertGreaterThan(0, $snap['exempt_credits']);
        $this->assertSame($snap['exempt_credits'], $snap['categories'][UsageSource::CAT_KNOWLEDGE]['credits']);
        $this->assertTrue($snap['categories'][UsageSource::CAT_KNOWLEDGE]['quota_exempt']);

        // Le quota ne voit que les crédits non exemptés.
        $user = User::findById(1);
        $status = CreditQuota::status($user);
        $this->assertSame(2000, $status['used']);
        $this->assertSame($snap['total_credits'], $status['total_credits']);
        $this->assertSame($snap['exempt_credits'], $status['exempt_credits']);

        // La liste admin lit la même règle.
        $this->assertSame(2000, UsageLog::creditsThisMonthByUser()[1]);
        $this->assertSame(2000, UsageLog::creditsByUser()[1]);

        // Répartition par base.
        $kb = UsageLog::byKnowledgeBase(null, null, null);
        $this->assertCount(1, $kb);
        $this->assertSame('Base', $kb[0]['kb_name']);
        $this->assertSame(2, $kb[0]['requests']);
    }

    public function testOutilsEtCompteurDusage(): void
    {
        $ctx = $this->seedUserAndConversation();
        $this->db()->exec("INSERT INTO chat_tools (id, name, type, provider, model) VALUES (1, 'Images', 'image', 'openai', 'gpt-image-2')");
        $this->db()->exec("INSERT INTO chat_tools (id, name, type, provider, model) VALUES (2, 'Pages', 'html', 'openai', 'gpt-test')");
        $tool = ['id' => 1, 'type' => 'image'];

        $r = UsageMeter::tool($tool, ['model' => 'gpt-image-2', 'size' => '1024x1024', 'count' => 1], $ctx);
        $this->assertTrue($r['logged']);
        $this->assertSame(40, $r['total_credits']);
        $this->assertTrue(UsageMeter::toolUse(['id' => 2, 'type' => 'html'], ['model' => 'gpt-test'], $ctx));
        // Test d'administration : hors quota, catégorie admin.
        $this->assertTrue(UsageMeter::tool($tool, ['model' => 'gpt-image-2', 'count' => 1], ['user_id' => 1], UsageSource::TOOL_TEST)['logged']);

        $rows = $this->db()->query('SELECT tool_id, source, credits_used, quota_exempt, priced, meta_json FROM tool_usage_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $rows);
        $this->assertSame('chat', $rows[0]['source']);
        $this->assertSame(40, (int)$rows[0]['credits_used']);
        $this->assertStringContainsString('1024x1024', (string)$rows[0]['meta_json']);
        $this->assertSame(0, (int)$rows[1]['credits_used']);
        $this->assertSame(2, (int)$rows[1]['tool_id']);
        $this->assertSame('tool_test', $rows[2]['source']);
        $this->assertSame(1, (int)$rows[2]['quota_exempt']);

        $cats = [];
        foreach (UsageLog::byCategory() as $cat) {
            $cats[$cat['category']] = $cat;
        }
        $this->assertSame(40, $cats[UsageSource::CAT_CREATION]['credits']);
        $this->assertSame(2, $cats[UsageSource::CAT_CREATION]['requests']);
        $this->assertSame(40, $cats[UsageSource::CAT_ADMIN]['credits']);
        $this->assertTrue($cats[UsageSource::CAT_ADMIN]['quota_exempt']);

        // Un outil sans identifiant n'est jamais journalisé (et ne casse rien).
        $this->assertFalse(UsageMeter::toolUse(['type' => 'html'], [], $ctx));
    }

    public function testAgregatsParCategorieCoutReelEtNonTarife(): void
    {
        $ctx = $this->seedUserAndConversation();
        UsageMeter::llm('openai::gpt-test', ['input' => 1_000_000, 'output' => 0], UsageSource::CHAT, $ctx);            // 2 € réel, 2000 cr.
        UsageMeter::llm('openai::gpt-test', ['input' => 10_000, 'output' => 0], UsageSource::TITLE, $ctx);               // 0,02 €
        UsageMeter::llm('zai::inconnu', ['input' => 500, 'output' => 500], UsageSource::MEMORY, $ctx);                  // non tarifé
        UsageMeter::webSearch('anthropic::claude-opus-5', 4, $ctx);                                                      // 0,037 €
        UsageMeter::transcription('gpt-4o-transcribe', 90.0, ['user_id' => 1]);                                          // 1,5 min

        $totals = UsageLog::periodTotals();
        $this->assertSame(5, $totals['requests']);
        $this->assertSame(1, $totals['unpriced_ops']);
        $this->assertEqualsWithDelta(2.0 + 0.02 + 4 * 0.01 / self::FX + 1.5 * 0.006 / self::FX, $totals['cost_eur'], 0.0001);
        $this->assertSame(0, $totals['exempt_credits']);
        $this->assertSame($totals['total_credits'], $totals['quota_credits']);

        $cats = [];
        foreach (UsageLog::byCategory() as $cat) {
            $cats[$cat['category']] = $cat;
        }
        $this->assertSame(2000, $cats[UsageSource::CAT_CHAT]['credits']);
        $this->assertSame(2, $cats[UsageSource::CAT_CHAT_ASSIST]['requests']);
        $this->assertSame(1, $cats[UsageSource::CAT_CHAT_ASSIST]['unpriced_ops']);
        $this->assertSame(1, $cats[UsageSource::CAT_WEB_SEARCH]['requests']);
        $this->assertSame(4.0, $cats[UsageSource::CAT_WEB_SEARCH]['quantity']);
        $this->assertSame(1.5, $cats[UsageSource::CAT_VOICE]['quantity']);
        $this->assertSame(UsageSource::UNIT_MINUTES, $cats[UsageSource::CAT_VOICE]['sources'][0]['unit']);
        // Les catégories sans donnée sont présentes, à zéro.
        $this->assertSame(0, $cats[UsageSource::CAT_KNOWLEDGE]['requests']);

        $unpriced = UsageLog::unpricedOperations();
        $this->assertCount(1, $unpriced);
        $this->assertSame('zai::inconnu', $unpriced[0]['name']);
        $this->assertSame('memory', $unpriced[0]['source']);

        $monthly = UsageLog::monthlySummary();
        $this->assertCount(1, $monthly);
        $this->assertSame($totals['total_credits'], $monthly[0]['total_credits']);
        $this->assertSame($totals['total_credits'], array_sum($monthly[0]['families']));
        $this->assertEqualsWithDelta($totals['cost_eur'], $monthly[0]['cost_eur'], 0.0001);

        $top = UsageLog::topOperations(null, null, 1, 3);
        $this->assertSame('chat', $top[0]['source']);
        $this->assertSame(2000, $top[0]['credits_used']);

        $models = UsageLog::byModel(null, null, 1);
        $this->assertSame('gpt-test', $models[0]['model']);
        $this->assertSame('openai', $models[0]['provider']);
    }
}
