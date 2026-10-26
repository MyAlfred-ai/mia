<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Câblage du grand livre des opérations IA : UN seul chemin d'écriture
 * (UsageMeter → UsageLog::insertLlm/insertTool), et chaque appel
 * fournisseur identifié lors de l'audit passe par le compteur — titres,
 * juges mémoire/tâches, préparation d'image, embeddings de question,
 * alimentation des bases, tests d'outils, recherche des prix, dictée,
 * recherches web rapportées par les adapters.
 *
 * Lu sur le CODE (source des fichiers) : la régression qu'on garde est
 * « quelqu'un rajoute un appel LLM sans le compter », pas un comportement
 * observable sans clé API.
 */
final class UsageMeterContractTest extends TestCase
{
    private static function src(string $relative): string
    {
        $path = ROOT . '/' . $relative;
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    /** @return list<string> fichiers PHP de src/ et scripts/ */
    private static function phpFiles(): array
    {
        $out = [];
        foreach (['src', 'scripts', 'public'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/' . $dir));
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }
        return $out;
    }

    public function testLEcritureDuGrandLivreNaQuUnSeulChemin(): void
    {
        foreach (self::phpFiles() as $path) {
            $code = (string)file_get_contents($path);
            $isMeter = str_ends_with($path, '/src/Billing/UsageMeter.php');
            if (!$isMeter) {
                self::assertStringNotContainsString('UsageLog::insertLlm(', $code, basename($path) . ' écrit usage_logs sans passer par UsageMeter');
                self::assertStringNotContainsString('UsageLog::insertTool(', $code, basename($path) . ' écrit tool_usage_logs sans passer par UsageMeter');
            }
            // Les anciens points d'entrée n'existent plus : un appel résiduel serait une erreur fatale.
            self::assertStringNotContainsString('UsageLog::record(', $code, basename($path));
            self::assertStringNotContainsString('UsageLog::recordToolUsage(', $code, basename($path));
            // Aucun INSERT direct dans les deux tables hors du modèle (et de son backfill).
            if (!str_ends_with($path, '/src/Models/UsageLog.php')) {
                self::assertDoesNotMatchRegularExpression('/INSERT\s+INTO\s+(usage_logs|tool_usage_logs)/i', $code, basename($path));
            }
        }
        self::assertStringNotContainsString('public static function record(', self::src('src/Models/UsageLog.php'));
    }

    public function testLesCalculsDeCreditsNeSontPlusAppelesHorsDuCompteur(): void
    {
        // Seules exceptions : l'estimation de cherté des outils (étoiles du menu « + »)
        // et l'écran Crédits — des estimations, pas des opérations.
        $chat = self::src('src/Controllers/ChatController.php');
        preg_match_all('/CreditService::compute\w+\(/', $chat, $m);
        self::assertLessThanOrEqual(3, count($m[0]), 'ChatController ne doit appeler CreditService que pour estimer (étoiles de coût)');
        foreach (['src/Jobs/HtmlPageJob.php', 'src/Helpers/HtmlIllustrator.php', 'src/Jobs/GammaGenerationJob.php', 'src/Controllers/AdminController.php'] as $f) {
            self::assertStringNotContainsString('CreditService::computeLlmCredits(', self::src($f), $f);
            self::assertStringNotContainsString('CreditService::computeToolCredits(', self::src($f), $f);
        }
    }

    public function testChaqueAppelFournisseurIdentifieParLAuditEstCompte(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $expected = [
            'UsageSource::CHAT'              => 'réponse de chat',
            'UsageSource::REGENERATE'        => 'régénération',
            'UsageSource::TITLE'             => 'titre automatique',
            'UsageSource::MEMORY'            => 'juge mémoire',
            'UsageSource::TASK_RULES'        => 'juge règles de tâches',
            'UsageSource::IMAGE_PROMPT'      => 'préparation de demande d\'image',
            'UsageSource::MAIL_ASSIST'       => 'amélioration d\'un brouillon d\'email (carte de relecture)',
            'UsageSource::KB_QUERY'          => 'embedding de la question (RAG)',
            'UsageSource::CONTEXT_SUMMARY'   => 'résumé du début d\'une longue conversation',
            'UsageSource::TOOL_CONDENSE'     => 'condensation d\'un résultat d\'outil démesuré',
            'UsageSource::MAP_TOOL'          => 'itinéraire',
            'UsageSource::PRESENTATION_TOOL' => 'présentation',
            'UsageMeter::transcription('     => 'dictée vocale',
        ];
        foreach ($expected as $needle => $what) {
            self::assertStringContainsString($needle, $chat, "ChatController ne compte plus : $what");
        }
        // Chaque helper LLM rattache sa consommation juste après l'appel.
        foreach (['generateConversationTitle', 'captureUserMemory', 'captureTaskRule', 'prepareImageRequest', 'improveMailDraft', 'summarizeConversationStart', 'condenseToolResult'] as $fn) {
            $pos = strpos($chat, 'function ' . $fn . '(');
            self::assertNotFalse($pos, $fn);
            $body = substr($chat, $pos, 6000);
            self::assertStringContainsString('UsageMeter::llm(', $body, "$fn appelle le LLM sans compter");
        }
        // Images : un seul chemin (logImageToolUsage → UsageMeter::tool), quel que soit l'outil.
        self::assertStringContainsString("return (int)UsageMeter::tool(\$tool, \$imageParams, \$billingCtx)['total_credits'];", $chat);
        // n8n : compteur d'usage à l'exécution approuvée.
        self::assertStringContainsString("UsageMeter::toolUse(\$n8nTool", $chat);

        self::assertStringContainsString('UsageSource::HTML_TOOL', self::src('src/Jobs/HtmlPageJob.php'));
        self::assertStringContainsString('UsageMeter::toolUse(', self::src('src/Jobs/GammaGenerationJob.php'));

        $admin = self::src('src/Controllers/AdminController.php');
        self::assertStringContainsString('UsageSource::PROMPT_ASSIST', $admin);
        self::assertStringContainsString('UsageSource::PRICE_REFRESH', $admin);
        // Les 6 tests d'outils à consommation réelle (image, vidéo ×2, HTML, carte, présentation).
        self::assertStringContainsString('UsageSource::TOOL_TEST', $admin);
        self::assertSame(3, substr_count($admin, '$this->billToolTestLlm($tool, $tokens);'), 'HTML, carte, présentation');
        self::assertSame(2, substr_count($admin, '$this->billToolTestVideo($tool, $result);'), 'vidéo sans et avec image');
        self::assertStringContainsString("UsageSource::TOOL_TEST);", $admin); // image
        self::assertStringContainsString('$ctx->userId()', self::src('src/Jobs/CreditsRefreshJob.php'));

        $kbDoc = self::src('src/Models/KnowledgeBaseDocument.php');
        foreach (['UsageSource::KB_CHUNKING', 'UsageSource::KB_VISION', 'UsageSource::KB_EMBEDDING'] as $needle) {
            self::assertStringContainsString($needle, $kbDoc);
        }
        self::assertStringContainsString('embedBatchWithUsage(', $kbDoc);
        self::assertStringContainsString('SemanticChunker::split($text, $chunkingUsage)', $kbDoc);
        self::assertStringContainsString('public static function split(string $text, ?array &$usage = null): array', self::src('src/RAG/SemanticChunker.php'));
        self::assertStringContainsString('?array &$usage = null): string', self::src('src/RAG/KbVisionDescriber.php'));
    }

    public function testLesAdaptersRapportentLesRecherchesWebEtLeModeleReel(): void
    {
        $openai = self::src('src/LLM/OpenAIAdapter.php');
        self::assertStringContainsString("'web_searches' => \$webSearches,", $openai);
        self::assertStringContainsString("'web_searches' => \$totSearches,", $openai);
        self::assertStringContainsString("'model' => \$model,", $openai);
        self::assertStringContainsString('web_search_call', $openai);

        $anthropic = self::src('src/LLM/AnthropicAdapter.php');
        self::assertStringContainsString("['server_tool_use']['web_search_requests']", $anthropic);
        // Les deux chemins (stream et boucle d'outils) passent par finalizeTokens,
        // qui rapporte les recherches ET le modèle réellement servi (relais).
        self::assertMatchesRegularExpression("/'web_searches'\s*=>\s*\\\$searches,/", $anthropic);
        self::assertStringContainsString('$this->finalizeTokens($byModel, $totSearches, $finalModel, $relay)', $anthropic);
        self::assertStringContainsString("\$this->finalizeTokens(\$byModel, (int)(\$result['web_searches'] ?? 0), \$finalModel, \$relay)", $anthropic);
        self::assertStringContainsString("\$tokens['model'] = \$finalModel;", $anthropic);

        // Gemini : chaque requête Google en 3.x, une par réponse ancrée en 2.5.
        self::assertStringContainsString("'web_searches' => self::billedWebSearches(\$this->model, count(\$searchQueries)),", self::src('src/LLM/GeminiAdapter.php'));
        self::assertStringContainsString('public static function effectiveLlmKey(', self::src('src/Billing/UsageMeter.php'));
    }

    public function testLeQuotaNeCompteQueLesLignesNonExemptees(): void
    {
        self::assertStringContainsString("\$snapshot['quota_credits']", self::src('src/Helpers/CreditQuota.php'));
        self::assertStringContainsString('CreditQuota::status($currentUser)', self::src('templates/partials/user-account-trigger.php'));
        self::assertStringContainsString('json_encode($creditQuotaStatus', self::src('templates/chat/index.php'));
        $usageLog = self::src('src/Models/UsageLog.php');
        self::assertStringContainsString('quota_exempt = 0', $usageLog);
    }

    public function testMigrationEtChargementDesClasses(): void
    {
        $migration = self::src('database/migrations/067_usage_ledger.sql');
        foreach (['ADD COLUMN quantity', 'ADD COLUMN kb_id', 'ADD COLUMN priced', 'ADD COLUMN quota_exempt',
                  'MODIFY COLUMN source VARCHAR(40)', 'cost_eur / @mia_margin', 'JSON_VALID(`value`)'] as $needle) {
            self::assertStringContainsString($needle, $migration);
        }
        $classes = self::src('config/classes.php');
        $posCredit = strpos($classes, "src/Models/CreditService.php");
        $posSource = strpos($classes, "src/Billing/UsageSource.php");
        $posMeter  = strpos($classes, "src/Billing/UsageMeter.php");
        self::assertNotFalse($posSource);
        self::assertNotFalse($posMeter);
        self::assertLessThan($posSource, $posCredit, 'UsageMeter lit CreditService : à charger après');
        self::assertLessThan($posMeter, $posSource);

        $bootstrap = self::src('tests/bootstrap.php');
        self::assertStringContainsString("'/src/Billing/UsageSource.php'", $bootstrap);
        self::assertStringContainsString("'/src/Billing/UsageMeter.php'", $bootstrap);
    }

    public function testDicteeVocaleRouteEtRapportClient(): void
    {
        self::assertStringContainsString("'/chat/realtime-transcription/usage'", self::src('public/index.php'));
        self::assertStringContainsString('public function realtimeTranscriptionUsage(): void', self::src('src/Controllers/ChatController.php'));
        $js = self::src('public/assets/js/chat.js');
        self::assertStringContainsString('function reportDictationUsage()', $js);
        self::assertStringContainsString("navigator.sendBeacon('/chat/realtime-transcription/usage'", $js);
        self::assertStringContainsString("fd.append('csrf_token', csrfToken);", $js);
        // Chaque chemin de fermeture rapporte : teardown, la reconnexion (les
        // deux causes — état WebRTC, canal fermé — passent par reconnectLater),
        // pagehide. Le socle est PARTAGÉ par la dictée et la conversation vocale.
        self::assertGreaterThanOrEqual(3, substr_count($js, 'reportDictationUsage();'));
        self::assertStringContainsString('function reconnectLater(', $js);
        self::assertSame(2, substr_count($js, 'createRealtimeTranscriber({'), 'dictée ET conversation vocale sur le même socle');
    }

    /**
     * Lecture à voix haute (conversation vocale) : une opération IA de plus,
     * comptée comme les autres — route dédiée, compteur, tarif curé, ligne
     * de l'écran Crédits.
     */
    public function testLectureAVoixHauteRouteEtCompteur(): void
    {
        self::assertStringContainsString("'/chat/message/{id}/speak'", self::src('public/index.php'));
        $chat = self::src('src/Controllers/ChatController.php');
        self::assertStringContainsString('public function speakMessage(string $id): void', $chat);
        self::assertStringContainsString('UsageMeter::speech(', $chat);
        self::assertStringContainsString('public static function speech(string $model, float $seconds, array $ctx = []): array', self::src('src/Billing/UsageMeter.php'));
        self::assertStringContainsString('UsageSource::SPEECH', self::src('src/Billing/UsageMeter.php'));
        self::assertStringContainsString('public static function computeSpeechCredits(string $model, float $minutes): array', self::src('src/Models/CreditService.php'));
        $llm = require ROOT . '/config/llm_catalog.php';
        self::assertArrayHasKey('default', $llm['openai']['speech_pricing_usd_per_minute']);
        self::assertArrayHasKey(SpeechSynthesizer::MODEL, $llm['openai']['speech_pricing_usd_per_minute']);
        $admin = self::src('src/Controllers/AdminController.php');
        self::assertStringContainsString("'catalog_key' => 'speech:openai::' . SpeechSynthesizer::MODEL,", $admin);
        self::assertStringContainsString("str_starts_with(\$key, 'speech:openai::')", $admin);
    }

    public function testLesCataloguesPortentLesTarifsCuresDeRepli(): void
    {
        $llm = require ROOT . '/config/llm_catalog.php';
        self::assertSame(10.0, (float)$llm['openai']['web_search_pricing_usd_per_1k']);
        self::assertSame(10.0, (float)$llm['anthropic']['web_search_pricing_usd_per_1k']);
        self::assertSame(14.0, (float)$llm['gemini']['web_search_pricing_usd_per_1k']);
        self::assertArrayHasKey('default', $llm['openai']['transcription_pricing_usd_per_minute']);
        $emb = require ROOT . '/config/embeddings_catalog.php';
        foreach ($emb as $provider => $entry) {
            foreach ($entry['models'] as $model => $info) {
                self::assertArrayHasKey('pricing_usd_per_1m', $info, "$provider/$model sans tarif curé");
            }
        }
    }

    public function testLEcranCreditsConnaitLesNouveauxServices(): void
    {
        $admin = self::src('src/Controllers/AdminController.php');
        self::assertStringContainsString("'catalog_key' => 'embedding:' . \$provider . '::' . \$modelName,", $admin);
        self::assertStringContainsString("'catalog_key' => 'websearch:' . \$provider,", $admin);
        self::assertStringContainsString("'catalog_key' => 'transcribe:openai::' . \$modelName,", $admin);
        self::assertStringContainsString("\$allowedTypes = ['llm_text', 'embedding', 'web_search', 'image', 'video', 'audio', 'other'];", $admin);
        self::assertStringContainsString('private function resolveCuratedNonLlmUsdPrice(', $admin);
        $tpl = self::src('templates/admin/credits.php');
        self::assertStringContainsString("{ value: 'embedding', label: 'Embeddings' }", $tpl);
        self::assertStringContainsString("{ value: '1m_tokens', label: '1M tokens' }", $tpl);
    }

    public function testLesPagesUsagesDistinguentCoutReelEtValorisation(): void
    {
        $admin = self::src('templates/admin/usage.php');
        self::assertStringContainsString('Coût réel IA', $admin);
        self::assertStringContainsString('Valorisation', $admin);
        self::assertStringContainsString('data-tab="categories"', $admin);
        self::assertStringContainsString('unpriced_ops', $admin);
        self::assertStringContainsString('UsageSource::families()', $admin);

        $user = self::src('templates/settings/usage.php');
        self::assertStringContainsString('Ce qui a consommé vos crédits', $user);
        self::assertStringContainsString('hors quota', $user);
        // L'utilisateur voit la valorisation de ses crédits, jamais le coût fournisseur brut.
        self::assertStringNotContainsString('Coût réel IA', $user);

        $pdf = self::src('templates/admin/usage-pdf.php');
        self::assertStringContainsString('Coût réel IA', $pdf);
        self::assertStringContainsString("billed_eur", $pdf);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocEstAJour(): void
    {
        $admin = self::src('docs/GUIDE-ADMIN.md');
        self::assertStringContainsString('Par catégorie', $admin);
        self::assertStringContainsString('hors quota', $admin);
        self::assertStringContainsString('Coût réel IA', $admin);
        self::assertStringNotContainsString('ils ne sont pas décomptés des crédits utilisateurs', $admin);
        $user = self::src('docs/GUIDE-UTILISATEUR.md');
        self::assertStringContainsString('Ce qui a consommé vos crédits', $user);
        $claude = self::src('CLAUDE.md');
        self::assertStringContainsString('UsageMeter', $claude);
        self::assertStringContainsString('UsageSource', $claude);
    }
}
