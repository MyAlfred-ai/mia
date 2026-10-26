<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cause d'une erreur de modèle, dite à l'utilisateur.
 *
 * Le défaut corrigé : les erreurs étaient rangées par leur seul statut HTTP.
 * Un compte OpenAI sans crédit (429 `insufficient_quota`) devenait « service
 * saturé, patientez quelques secondes » ; une conversation trop longue (400
 * `context_length_exceeded`) devenait « un paramètre est peut-être invalide » ;
 * un `overloaded_error` Anthropic arrivé au fil du flux, « une erreur est
 * survenue ». Trois messages faux ou muets, et aucun moyen de savoir quoi
 * faire — attendre, raccourcir, prévenir un administrateur.
 *
 * Les échantillons ci-dessous sont les corps d'erreur que renvoient réellement
 * les fournisseurs : ce sont eux qui font foi, pas l'idée qu'on s'en fait.
 */
final class LlmErrorPresenterTest extends TestCase
{
    private string|false $journalAvant = false;

    protected function setUp(): void
    {
        // La garde du flux journalise chaque réponse coupée : hors de la sortie des tests.
        $this->journalAvant = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/mia-erreurs-llm-test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->journalAvant === false ? '' : $this->journalAvant);
        @unlink(sys_get_temp_dir() . '/mia-erreurs-llm-test.log');
        I18n::use('fr');
        ChatController::setEmitSink(null);
        parent::tearDown();
    }

    public static function setUpBeforeClass(): void
    {
        // ChatController dépend de vendor/, il n'est pas dans tests/bootstrap.php
        // — même chargement à la demande que dans ActionTraceTest.
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ------------------------------------------------------------------
    // 1. La cause se lit dans ce que le fournisseur renvoie
    // ------------------------------------------------------------------

    /** @return array<string, array{string, ?int, string}> genre attendu, statut, corps */
    public static function erreursFournisseurs(): array
    {
        return [
            // ---------- OpenAI ----------
            'OpenAI — compte sans crédit (429)' => ['quota', 429,
                '{"error":{"message":"You exceeded your current quota, please check your plan and billing details. For more information on this error, read the docs: https://platform.openai.com/docs/guides/error-codes/api-errors.","type":"insufficient_quota","param":null,"code":"insufficient_quota"}}'],
            'OpenAI — plafond de facturation' => ['quota', 400,
                '{"error":{"message":"Billing hard limit has been reached","type":"invalid_request_error","param":null,"code":"billing_hard_limit_reached"}}'],
            'OpenAI — limite de débit (429)' => ['rate_limit', 429,
                '{"error":{"message":"Rate limit reached for gpt-4o in organization org-abc on tokens per min (TPM): Limit 30000, Used 28000, Requested 4000. Please try again in 4s.","type":"tokens","param":null,"code":"rate_limit_exceeded"}}'],
            // 429 aussi, mais attendre n'y changera rien : la requête dépasse le palier.
            'OpenAI — requête trop lourde pour le palier' => ['context', 429,
                '{"error":{"message":"Request too large for gpt-4o in organization org-abc on tokens per min (TPM): Limit 30000, Requested 45312. The input or output tokens must be reduced in order to run successfully.","type":"tokens","param":null,"code":"rate_limit_exceeded"}}'],
            'OpenAI — contexte dépassé (Chat Completions)' => ['context', 400,
                '{"error":{"message":"This model\'s maximum context length is 128000 tokens. However, your messages resulted in 130812 tokens. Please reduce the length of the messages.","type":"invalid_request_error","param":"messages","code":"context_length_exceeded"}}'],
            'OpenAI — contexte dépassé (Responses)' => ['context', 400,
                '{"error":{"message":"Your input exceeds the context window of this model. Please adjust your input and try again.","type":"invalid_request_error","param":"input","code":"context_length_exceeded"}}'],
            'OpenAI — modèle retiré' => ['model', 404,
                '{"error":{"message":"The model `gpt-4-32k` does not exist or you do not have access to it.","type":"invalid_request_error","param":null,"code":"model_not_found"}}'],
            'OpenAI — clé refusée' => ['auth', 401,
                '{"error":{"message":"Incorrect API key provided: sk-proj-********abcd. You can find your API key at https://platform.openai.com/account/api-keys.","type":"invalid_request_error","param":null,"code":"invalid_api_key"}}'],
            'OpenAI — incident interne' => ['server', 500,
                '{"error":{"message":"The server had an error while processing your request. Sorry about that!","type":"server_error","param":null,"code":null}}'],
            'OpenAI — surcharge' => ['overloaded', 503,
                '{"error":{"message":"The engine is currently overloaded, please try again later.","type":"server_error"}}'],
            'OpenAI — demande signalée' => ['filtered', 400,
                '{"error":{"message":"Invalid prompt: your prompt was flagged as potentially violating our usage policy. Please try again with a different prompt.","type":"invalid_request_error","param":null,"code":"invalid_prompt"}}'],
            'OpenAI — image sur un modèle sans vision' => ['attachment', 400,
                '{"error":{"message":"Invalid content type. image_url is only supported by certain models.","type":"invalid_request_error","param":"messages.[1].content.[0].type","code":null}}'],
            'OpenAI — format d\'image refusé' => ['attachment', 400,
                '{"error":{"message":"You uploaded an unsupported image. Please make sure your image has of one the following formats: [\'png\', \'jpeg\', \'gif\', \'webp\'].","type":"invalid_request_error","param":null,"code":"invalid_image_format"}}'],
            'OpenAI — région non prise en charge' => ['permission', 403,
                '{"error":{"message":"Country, region, or territory not supported","type":"request_forbidden","param":null,"code":"unsupported_country_region_territory"}}'],
            'OpenAI — organisation à vérifier' => ['permission', 400,
                '{"error":{"message":"Your organization must be verified to stream this model. Please go to: https://platform.openai.com/settings/organization/general and click on Verify Organization.","type":"invalid_request_error","param":"stream","code":"unsupported_value"}}'],
            // Un paramètre refusé n'est AUCUNE des causes nommées.
            'OpenAI — paramètre refusé' => ['bad_request', 400,
                '{"error":{"message":"Unsupported parameter: \'temperature\' is not supported with this model.","type":"invalid_request_error","param":"temperature","code":"unsupported_parameter"}}'],
            'OpenAI — valeur refusée' => ['bad_request', 400,
                '{"error":{"message":"Unsupported value: \'temperature\' does not support 0.2 with this model. Only the default (1) value is supported.","type":"invalid_request_error","param":"temperature","code":"unsupported_value"}}'],

            // ---------- Anthropic ----------
            // Un compte sans crédit y est un 400 `invalid_request_error` : seul le message le dit.
            'Anthropic — compte sans crédit (400)' => ['quota', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits."},"request_id":"req_011"}'],
            'Anthropic — plafond d\'usage atteint' => ['quota', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"You have reached your specified API usage limits. You will regain access on 2026-11-01 at 00:00 UTC."}}'],
            'Anthropic — conversation trop longue' => ['context', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"prompt is too long: 215234 tokens > 200000 maximum"}}'],
            'Anthropic — entrée + sortie hors fenêtre' => ['context', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"input length and `max_tokens` exceed context limit: 190000 + 20000 > 200000, decrease input length or `max_tokens` and try again"}}'],
            'Anthropic — requête trop lourde (413)' => ['context', 413,
                '{"type":"error","error":{"type":"request_too_large","message":"Request exceeds the maximum allowed number of bytes."}}'],
            'Anthropic — surcharge (529)' => ['overloaded', 529,
                '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}'],
            // Au fil du flux : aucun statut, seul le type dit la cause.
            'Anthropic — surcharge au fil du flux' => ['overloaded', null,
                '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}'],
            'Anthropic — incident au fil du flux' => ['server', null,
                '{"type":"error","error":{"type":"api_error","message":"Internal server error"}}'],
            'Anthropic — limite de débit' => ['rate_limit', 429,
                '{"type":"error","error":{"type":"rate_limit_error","message":"This request would exceed your organization\'s rate limit of 30,000 input tokens per minute. Please reduce the prompt length or the maximum tokens requested, or try again later."}}'],
            'Anthropic — modèle inconnu' => ['model', 404,
                '{"type":"error","error":{"type":"not_found_error","message":"model: claude-3-opus-20240229"}}'],
            'Anthropic — clé refusée' => ['auth', 401,
                '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}'],
            'Anthropic — clé sans droit' => ['permission', 403,
                '{"type":"error","error":{"type":"permission_error","message":"Your API key does not have permission to use the specified resource."}}'],
            'Anthropic — image trop lourde' => ['attachment', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"messages.0.content.1.image.source.base64: image exceeds 5 MB maximum: 6291456 bytes > 5242880 bytes"}}'],
            'Anthropic — PDF illisible' => ['attachment', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"messages.3.content.0.document.source.base64: The PDF specified was not valid."}}'],
            'Anthropic — PDF trop long' => ['attachment', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"A maximum of 100 PDF pages may be provided."}}'],
            // `max_tokens` trop grand = réglage de l'instance, pas un contenu trop long.
            'Anthropic — plafond de sortie refusé' => ['bad_request', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"max_tokens: 100000 > 64000, which is the maximum allowed number of output tokens for claude-opus-5"}}'],
            'Anthropic — conservation des données' => ['retention', 400,
                '{"type":"error","error":{"type":"invalid_request_error","message":"To use this model, your organization or workspace must have data retention enabled."}}'],

            // ---------- Google ----------
            // Même phrase, même statut : seul l'identifiant du quota les sépare.
            'Gemini — limite à la minute' => ['rate_limit', 429,
                '{"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details.\n* Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 10\nPlease retry in 46.316418014s.","status":"RESOURCE_EXHAUSTED","details":[{"@type":"type.googleapis.com/google.rpc.QuotaFailure","violations":[{"quotaId":"GenerateRequestsPerMinutePerProjectPerModel-FreeTier"}]},{"@type":"type.googleapis.com/google.rpc.RetryInfo","retryDelay":"46s"}]}}'],
            'Gemini — quota journalier épuisé' => ['quota', 429,
                '{"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details.","status":"RESOURCE_EXHAUSTED","details":[{"@type":"type.googleapis.com/google.rpc.QuotaFailure","violations":[{"quotaId":"GenerateRequestsPerDayPerProjectPerModel-FreeTier"}]}]}}'],
            'Gemini — trop de tokens' => ['context', 400,
                '{"error":{"code":400,"message":"The input token count (1200000) exceeds the maximum number of tokens allowed (1048576).","status":"INVALID_ARGUMENT"}}'],
            'Gemini — charge trop lourde' => ['context', 400,
                '{"error":{"code":400,"message":"Request payload size exceeds the limit: 20971520 bytes.","status":"INVALID_ARGUMENT"}}'],
            // Une clé invalide y répond 400, pas 401.
            'Gemini — clé invalide (400)' => ['auth', 400,
                '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT","details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"API_KEY_INVALID"}]}}'],
            'Gemini — modèle coupé' => ['model', 404,
                '{"error":{"code":404,"message":"models/gemini-3.1-flash-lite-preview is not found for API version v1beta, or is not supported for generateContent.","status":"NOT_FOUND"}}'],
            'Gemini — surcharge' => ['overloaded', 503,
                '{"error":{"code":503,"message":"The model is overloaded. Please try again later.","status":"UNAVAILABLE"}}'],
            'Gemini — incident interne' => ['server', 500,
                '{"error":{"code":500,"message":"An internal error has occurred. Please retry.","status":"INTERNAL"}}'],
            'Gemini — délai dépassé' => ['timeout', 504,
                '{"error":{"code":504,"message":"The request timed out. Please try again.","status":"DEADLINE_EXCEEDED"}}'],
            'Gemini — région non prise en charge' => ['permission', 400,
                '{"error":{"code":400,"message":"User location is not supported for the API use.","status":"FAILED_PRECONDITION"}}'],
            'Gemini — API non activée' => ['permission', 403,
                '{"error":{"code":403,"message":"Generative Language API has not been used in project 123 before or it is disabled.","status":"PERMISSION_DENIED","details":[{"reason":"SERVICE_DISABLED"}]}}'],

            // ---------- Mistral ----------
            'Mistral — modèle inconnu' => ['model', 400,
                '{"object":"error","message":"Invalid model: mistral-foo","type":"invalid_model","param":null,"code":"1500"}'],
            'Mistral — capacité dépassée (429)' => ['overloaded', 429,
                '{"object":"error","message":"Service tier capacity exceeded for this model.","type":"service_tier_capacity_exceeded","param":null,"code":"3505"}'],
            'Mistral — limite de débit' => ['rate_limit', 429, '{"message":"Requests rate limit exceeded"}'],
            'Mistral — clé refusée' => ['auth', 401, '{"message":"Unauthorized","request_id":"abc"}'],
            'Mistral — contexte dépassé' => ['context', 400,
                '{"object":"error","message":"Prompt contains 40000 tokens and 0 draft tokens, too large for model with 32768 maximum context length","type":"invalid_request_error","param":null,"code":"3051"}'],
            // « extra_forbidden » est une erreur de validation, pas un refus d'accès.
            'Mistral — champ refusé (422)' => ['bad_request', 422,
                '{"detail":[{"type":"extra_forbidden","loc":["body","foo"],"msg":"Extra inputs are not permitted","input":"x"}]}'],

            // ---------- DeepSeek, Kimi, GLM, Perplexity, modèle local ----------
            'DeepSeek — solde insuffisant (402)' => ['quota', 402,
                '{"error":{"message":"Insufficient Balance","type":"unknown_error","param":null,"code":"invalid_request_error"}}'],
            'DeepSeek — modèle inconnu' => ['model', 400,
                '{"error":{"message":"Model Not Exist","type":"invalid_request_error","param":null,"code":"invalid_request_error"}}'],
            'DeepSeek — surcharge' => ['overloaded', 503, '{"error":{"message":"Server overloaded","type":"server_error"}}'],
            'Kimi — compte suspendu' => ['quota', 429,
                '{"error":{"message":"Your account org-x is suspended, please check your plan and billing details","type":"exceeded_current_quota_error"}}'],
            'Kimi — surcharge (429)' => ['overloaded', 429,
                '{"error":{"message":"The engine is currently overloaded, please try again later","type":"engine_overloaded_error"}}'],
            'Kimi — limite de débit' => ['rate_limit', 429,
                '{"error":{"message":"Your account org-x request reached max request: 3, please try again after 1 seconds","type":"rate_limit_reached_error"}}'],
            'Kimi — demande filtrée' => ['filtered', 400,
                '{"error":{"message":"The request was rejected because it was considered high risk","type":"content_filter"}}'],
            'Kimi — contexte dépassé' => ['context', 400,
                '{"error":{"message":"Invalid request: Your request exceeded model token limit: 8192","type":"invalid_request_error"}}'],
            'GLM — solde insuffisant' => ['quota', 429,
                '{"error":{"code":"1113","message":"Insufficient balance or no resource package. Please recharge."}}'],
            'GLM — modèle inconnu' => ['model', 400,
                '{"error":{"code":"1211","message":"Unknown Model, please check the model code."}}'],
            'GLM — contenu sensible' => ['filtered', 400,
                '{"error":{"code":"1301","message":"System detected potentially unsafe or sensitive content in input or generation."}}'],
            'GLM — trop d\'appels simultanés' => ['rate_limit', 429,
                '{"error":{"code":"1302","message":"High concurrency usage of this API, please reduce concurrency or contact customer service to increase limits"}}'],
            'Perplexity — modèle inconnu' => ['model', 400,
                '{"error":{"message":"Invalid model \'xyz\'. Permitted models can be found in the documentation.","type":"invalid_model","code":400}}'],
            // Ollama : `error` est une simple chaîne.
            'Modèle local — modèle non installé' => ['model', 404,
                '{"error":"model \'llama3\' not found, try pulling it first"}'],
            // Page d'erreur d'un proxy : pas de JSON du tout.
            'Proxy — 502 en HTML' => ['server', 502,
                '<html><head><title>502 Bad Gateway</title></head><body><center><h1>502 Bad Gateway</h1></center></body></html>'],
        ];
    }

    #[DataProvider('erreursFournisseurs')]
    public function testLaCauseSeLitDansLaReponseDuFournisseur(string $attendu, ?int $statut, string $corps): void
    {
        $detail = LlmAdapterHelpers::errorDetail(json_decode($corps, true), $corps, $statut);

        $this->assertSame($attendu, LlmErrorPresenter::classify($statut, $detail), 'Détail lu : ' . $detail);
    }

    /** Le chemin complet : ce qu'un adaptateur émet, relu par le présentateur. */
    #[DataProvider('erreursFournisseurs')]
    public function testLeChunkDUnAdaptateurPorteLaMemeCause(string $attendu, ?int $statut, string $corps): void
    {
        $detail = LlmAdapterHelpers::errorDetail(json_decode($corps, true), $corps, $statut);
        $chunk  = $statut !== null
            ? LlmAdapterHelpers::buildHttpErrorMessage('OpenAI', $statut, $detail)
            : '[Erreur OpenAI : ' . $detail . ']';

        $desc = LlmErrorPresenter::parse($chunk);
        $this->assertNotNull($desc, $chunk);
        $this->assertSame($attendu, $desc['kind']);
        $this->assertSame('OpenAI', $desc['provider']);
        $this->assertSame($statut, $desc['status']);
    }

    public function testLeDetailPorteLesCodesMachineEnTete(): void
    {
        $this->assertSame(
            'insufficient_quota — You exceeded your current quota.',
            LlmAdapterHelpers::errorDetail(['error' => ['message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']])
        );
        // Anthropic : le type est dans `error`, l'enveloppe `type: error` n'apprend rien.
        $this->assertSame(
            'overloaded_error — Overloaded',
            LlmAdapterHelpers::errorDetail(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']])
        );
        // Google : statut, raison, identifiant du quota, délai annoncé hors message.
        $google = LlmAdapterHelpers::errorDetail(['error' => [
            'code' => 429, 'message' => 'Quota exceeded.', 'status' => 'RESOURCE_EXHAUSTED',
            'details' => [
                ['violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]],
                ['retryDelay' => '12s'],
            ],
        ]], '', 429);
        $this->assertSame(
            'RESOURCE_EXHAUSTED GenerateRequestsPerDayPerProjectPerModel-FreeTier — Quota exceeded. (retry in 12s)',
            $google,
            'Le statut HTTP recopié par Google (429) ne doit pas passer pour un code.'
        );
        // Responses API : évènement `error` à plat.
        $this->assertSame(
            'rate_limit_exceeded — Trop vite.',
            LlmAdapterHelpers::errorDetail(['type' => 'error', 'code' => 'rate_limit_exceeded', 'message' => 'Trop vite.'])
        );
        // Rien d'exploitable : le statut, plutôt qu'une chaîne vide.
        $this->assertSame('HTTP 503', LlmAdapterHelpers::errorDetail(null, '', 503));
        $this->assertSame('inconnue', LlmAdapterHelpers::errorDetail(['error' => null]));
    }

    public function testUnCorpsHtmlEstDebarrasseDeSonBalisageEtBorne(): void
    {
        $page = '<html><head><title>502 Bad Gateway</title></head><body>' . str_repeat('<p>cloudflare</p>', 400) . '</body></html>';
        $detail = LlmAdapterHelpers::errorDetail(null, $page, 502);

        $this->assertStringNotContainsString('<', $detail);
        $this->assertLessThanOrEqual(601, mb_strlen($detail));
        // Un message d'API garde ses chevrons : ce n'est pas du HTML.
        $this->assertSame(
            'prompt is too long: 215234 tokens > 200000 maximum',
            LlmAdapterHelpers::errorDetail(['error' => ['message' => 'prompt is too long: 215234 tokens > 200000 maximum']])
        );
    }

    // ------------------------------------------------------------------
    // 2. Les autres formats d'adaptateur
    // ------------------------------------------------------------------

    public function testUnFluxVideDerriereUnStatutDErreurEstCetteErreur(): void
    {
        $chunk = LlmAdapterHelpers::buildEmptyStreamMessage('OpenAI', 'gpt-5.6', 503);
        $this->assertSame('server', LlmErrorPresenter::parse($chunk)['kind']);

        $chunk = LlmAdapterHelpers::buildEmptyStreamMessage('OpenAI', 'gpt-5.6', 429);
        $this->assertSame('rate_limit', LlmErrorPresenter::parse($chunk)['kind']);

        // Statut normal ou inconnu : le flux est simplement vide.
        $this->assertSame('empty', LlmErrorPresenter::parse(LlmAdapterHelpers::buildEmptyStreamMessage('OpenAI', 'gpt-5.6', 200))['kind']);
        $this->assertSame('empty', LlmErrorPresenter::parse(LlmAdapterHelpers::buildEmptyStreamMessage('OpenAI', 'gpt-5.6', null))['kind']);
    }

    public function testLeModeleLocalNeFuitNiSonAdresseNiSonNomTechnique(): void
    {
        $desc = LlmErrorPresenter::parse('[Erreur : impossible de contacter le LLM local à http://192.168.1.20:11434/v1/chat/completions]');
        $this->assertSame('network', $desc['kind']);

        $payload = LlmErrorPresenter::present($desc);
        $this->assertStringNotContainsString('192.168', $payload['message']);
        $this->assertStringNotContainsString('http', $payload['message']);
        $this->assertStringContainsString('le modèle local', $payload['message']);

        // Erreur HTTP du même adaptateur : « LLM local » devient « le modèle local ».
        $http = LlmErrorPresenter::present(LlmErrorPresenter::parse('[Erreur LLM local (500) : boom]'));
        $this->assertStringStartsWith('Le modèle local rencontre un incident technique', $http['message']);

        // Adresse non réglée : rien à réessayer, c'est l'instance qui n'est pas configurée.
        $sansUrl = LlmErrorPresenter::present(LlmErrorPresenter::parse('[Erreur : URL du LLM local non configurée]'));
        $this->assertSame('missing_key', $sansUrl['kind']);
        $this->assertSame('Configuration requise', $sansUrl['title']);
        $this->assertStringContainsString("Le modèle local n'est pas encore configuré", $sansUrl['message']);
        $this->assertStringContainsString('Prévenez un administrateur', $sansUrl['message']);
        $this->assertFalse($sansUrl['retryable']);
    }

    public function testLesFormatsDejaReconnusLeRestent(): void
    {
        $this->assertSame('missing_key', LlmErrorPresenter::parse('[Clé API Mistral manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]')['kind']);
        $this->assertSame('network', LlmErrorPresenter::parse('[Erreur : impossible de contacter OpenAI Responses API]')['kind']);
        $this->assertSame('OpenAI', LlmErrorPresenter::parse('[Erreur : impossible de contacter OpenAI Responses API]')['provider']);
        $this->assertSame('empty', LlmErrorPresenter::parse('[Aucune réponse renvoyée par le modèle.]')['kind']);
        $this->assertSame('generic', LlmErrorPresenter::parse('[Erreur OpenAI : requête invalide (encodage payload)]')['kind']);
        $this->assertSame('generic', LlmErrorPresenter::parse("[Erreur : impossible d'ouvrir le stream]")['kind']);
        $this->assertSame('timeout', LlmErrorPresenter::parse('[Erreur Anthropic : timeout — aucune donnée reçue depuis 900 s]')['kind']);

        // Du texte ordinaire n'est pas une erreur.
        $this->assertNull(LlmErrorPresenter::parse('Bonjour, voici la réponse.'));
        $this->assertNull(LlmErrorPresenter::parse('[1] Première source'));
        $this->assertNull(LlmErrorPresenter::parse(''));
    }

    // ------------------------------------------------------------------
    // 3. Ce que lit l'utilisateur
    // ------------------------------------------------------------------

    public function testChaqueGenreAUnTitreEtUnMessageDansLesDeuxLangues(): void
    {
        foreach (LlmErrorPresenter::KINDS as $kind) {
            I18n::use('fr');
            $fr = LlmErrorPresenter::present(['kind' => $kind, 'provider' => 'openai', 'status' => 400]);
            I18n::use('en');
            $en = LlmErrorPresenter::present(['kind' => $kind, 'provider' => 'openai', 'status' => 400]);

            $this->assertSame($kind, $fr['kind']);
            foreach (['title', 'message'] as $champ) {
                $this->assertNotSame('', trim($fr[$champ]), "$kind : $champ vide.");
                $this->assertNotSame($fr[$champ], $en[$champ], "$kind : $champ non traduit en anglais.");
                $this->assertStringNotContainsString('{', $fr[$champ] . $en[$champ], "$kind : substituant non remplacé.");
            }
            $this->assertIsBool($fr['retryable']);
        }
    }

    public function testSansFournisseurConnuLaPhraseResteCorrecteDansLesDeuxLangues(): void
    {
        foreach (LlmErrorPresenter::KINDS as $kind) {
            foreach (['fr', 'en'] as $langue) {
                I18n::use($langue);
                $p = LlmErrorPresenter::present(['kind' => $kind, 'provider' => null, 'status' => null]);
                $texte = $p['title'] . ' ' . $p['message'];
                $this->assertStringNotContainsString('{', $texte, "$kind/$langue");
                // Le substituant « du fournisseur » donnait « The for the provider API key ».
                $this->assertStringNotContainsString('for the provider', $texte, "$kind/$langue");
                $this->assertStringNotContainsString('  ', $texte, "$kind/$langue : double espace.");
            }
        }
    }

    public function testUnCompteSansCreditNEstPlusUnServiceSature(): void
    {
        $desc = LlmErrorPresenter::parse(LlmAdapterHelpers::buildHttpErrorMessage(
            'OpenAI', 429, 'insufficient_quota — You exceeded your current quota, please check your plan and billing details.'
        ));
        $p = LlmErrorPresenter::present($desc);

        $this->assertSame('quota', $p['kind']);
        $this->assertSame('Crédit épuisé chez OpenAI', $p['title']);
        $this->assertStringContainsString('Le crédit de votre organisation chez OpenAI est épuisé', $p['message']);
        // Le quota, dans MIA, est celui de l'utilisateur : la confusion est
        // écartée — et sans un mot technique (instance, quota, API).
        $this->assertStringContainsString("Ce n'est lié ni à votre message ni à votre compte", $p['message']);
        $this->assertStringContainsString('Prévenez un administrateur', $p['message']);
        foreach (['instance', 'quota', 'API', 'HTTP'] as $jargon) {
            $this->assertStringNotContainsString($jargon, $p['message'], "Mot technique : $jargon");
        }
        $this->assertFalse($p['retryable'], 'Attendre ne rechargera pas le compte.');
        $this->assertTrue($p['contact_admin'], 'L\'encadré propose de prévenir un administrateur.');
    }

    public function testUneConversationTropLongueDitQuoiFaire(): void
    {
        $p = LlmErrorPresenter::present(LlmErrorPresenter::parse(
            '[Erreur Anthropic (400) : invalid_request_error — prompt is too long: 215234 tokens > 200000 maximum]'
        ));

        $this->assertSame('context', $p['kind']);
        $this->assertSame('Trop de contenu pour ce modèle', $p['title']);
        $this->assertStringContainsString('nouvelle conversation', $p['message']);
        $this->assertFalse($p['retryable'], 'Réessayer tel quel échouerait à l\'identique.');
    }

    public function testUnIncidentInterneDitQueLaDemandeNYEstPourRien(): void
    {
        $p = LlmErrorPresenter::present(LlmErrorPresenter::parse('[Erreur Anthropic : overloaded_error — Overloaded]'));
        $this->assertSame('overloaded', $p['kind']);
        $this->assertSame('Service surchargé', $p['title']);
        $this->assertStringContainsString("Ce n'est pas lié à votre message", $p['message']);
        $this->assertTrue($p['retryable']);

        $serveur = LlmErrorPresenter::present(LlmErrorPresenter::parse('[Erreur OpenAI (500) : server_error — boom]'));
        $this->assertSame('Service temporairement indisponible', $serveur['title']);
        $this->assertStringContainsString('(erreur 500)', $serveur['message']);
        $this->assertStringContainsString("Ce n'est pas lié à votre message", $serveur['message']);
    }

    /** Le message brut du fournisseur ne sort JAMAIS du serveur : il peut porter la clé. */
    #[DataProvider('erreursFournisseurs')]
    public function testAucunDetailBrutNAtteintLEcran(string $attendu, ?int $statut, string $corps): void
    {
        $detail = LlmAdapterHelpers::errorDetail(json_decode($corps, true), $corps, $statut);
        $chunk  = $statut !== null
            ? LlmAdapterHelpers::buildHttpErrorMessage('OpenAI', $statut, $detail)
            : '[Erreur OpenAI : ' . $detail . ']';
        $payload = LlmErrorPresenter::fromChunk($chunk);

        $sortie = json_encode($payload, JSON_UNESCAPED_UNICODE) . LlmErrorPresenter::toPlainText($payload)
            . LlmErrorPresenter::interruptionNote(LlmErrorPresenter::parse($chunk));
        foreach (['sk-proj', 'req_011', 'org-abc', 'org-x', 'platform.openai.com', 'docs.perplexity', 'cloudflare', 'Bad Gateway'] as $fuite) {
            $this->assertStringNotContainsString($fuite, $sortie);
        }
        $this->assertArrayNotHasKey('detail', $payload);
    }

    // ------------------------------------------------------------------
    // 4. Délai annoncé par le fournisseur
    // ------------------------------------------------------------------

    /** @return array<string, array{string, ?int}> */
    public static function delais(): array
    {
        return [
            'secondes'            => ['Please try again in 20s.', 20],
            'fraction'            => ['Please retry in 46.316418014s.', 47],
            'minutes et secondes' => ['Rate limit reached. Please try again in 6m0s.', 360],
            'millisecondes'       => ['Please try again in 172ms.', 1],
            'heures'              => ['try again in 1h2m3s', 3723],
            'Google RetryInfo'    => ['Quota exceeded. (retry in 12s)', 12],
            'aucun délai'         => ['Rate limit reached.', null],
            // Un délai de plus d'un jour n'aide personne.
            'au-delà d\'un jour'  => ['Please try again in 31h0m0s.', null],
        ];
    }

    #[DataProvider('delais')]
    public function testLeDelaiAnnonceEstLu(string $detail, ?int $attendu): void
    {
        $this->assertSame($attendu, LlmErrorPresenter::retryDelaySeconds($detail));
    }

    public function testLaLimiteDeDebitDitCombienAttendre(): void
    {
        $desc = LlmErrorPresenter::parse('[Erreur OpenAI (429) : rate_limit_exceeded — Rate limit reached. Please try again in 20s.]');
        $this->assertSame(20, $desc['retry_after']);
        $this->assertStringContainsString('Réessayez dans 20 secondes ;', LlmErrorPresenter::present($desc)['message']);

        $long = LlmErrorPresenter::parse('[Erreur OpenAI (429) : rate_limit_exceeded — Please try again in 6m0s.]');
        $this->assertStringContainsString('Réessayez dans 6 minutes ;', LlmErrorPresenter::present($long)['message']);

        I18n::use('en');
        $this->assertStringContainsString('Try again in 20 seconds;', LlmErrorPresenter::present($desc)['message']);
        I18n::use('fr');

        // Sans délai connu : la formule d'origine.
        $sans = LlmErrorPresenter::parse('[Erreur OpenAI (429) : rate_limit_exceeded — Rate limit reached.]');
        $this->assertArrayNotHasKey('retry_after', $sans);
        $this->assertStringContainsString('Patientez quelques secondes', LlmErrorPresenter::present($sans)['message']);

        // Un compte sans crédit ne promet aucun délai, même si le message en cite un.
        $credit = LlmErrorPresenter::parse('[Erreur OpenAI (429) : insufficient_quota — You exceeded your current quota. Please try again in 20s.]');
        $this->assertArrayNotHasKey('retry_after', $credit);
    }

    // ------------------------------------------------------------------
    // 5. LLM de secours
    // ------------------------------------------------------------------

    public function testLeSecoursPrendLeRelaisDesPannesDuFournisseur(): void
    {
        $oui = [
            '[Erreur OpenAI (503) : server_error — boom]',
            '[Erreur OpenAI (429) : rate_limit_exceeded — Rate limit reached]',
            '[Erreur Anthropic (529) : overloaded_error — Overloaded]',
            '[Erreur : impossible de contacter Mistral]',
            '[Erreur Anthropic : timeout — aucune donnée reçue depuis 900 s]',
            LlmAdapterHelpers::buildEmptyStreamMessage('OpenAI', 'gpt-5.6', 502),
            // Compte sans crédit : le fournisseur est indisponible pour toute
            // l'instance, comme en panne — quel que soit le statut qui le dit.
            '[Erreur OpenAI (429) : insufficient_quota — You exceeded your current quota]',
            '[Erreur Anthropic (400) : invalid_request_error — Your credit balance is too low to access the Anthropic API.]',
            '[Erreur DeepSeek (402) : unknown_error — Insufficient Balance]',
        ];
        foreach ($oui as $chunk) {
            $this->assertTrue(LlmErrorPresenter::warrantsFailover(LlmErrorPresenter::parse($chunk)), $chunk);
        }

        $non = [
            // Un autre modèle refuserait pareil, ou la bascule masquerait un défaut de configuration.
            '[Erreur OpenAI (400) : context_length_exceeded — too long]',
            '[Erreur OpenAI (401) : invalid_api_key — Incorrect API key provided]',
            '[Erreur OpenAI (404) : model_not_found — The model does not exist]',
            '[Erreur OpenAI (400) : invalid_prompt — flagged]',
            '[Clé API OpenAI manquante. Configurez-la dans Admin → Clés API avant d\'utiliser cet assistant.]',
            '[Refus Anthropic : demande déclinée par le modèle.]',
            '[Erreur OpenAI : stop_reason=truncated (length) — aucune réponse produite]',
            LlmAdapterHelpers::buildEmptyStreamMessage('OpenAI', 'gpt-5.6', 200),
        ];
        foreach ($non as $chunk) {
            $this->assertFalse(LlmErrorPresenter::warrantsFailover(LlmErrorPresenter::parse($chunk)), $chunk);
        }
    }

    /**
     * L'ancienne heuristique cherchait « timeout » dans n'importe quel premier
     * fragment : une réponse qui en parlait partait sur le modèle de secours.
     */
    public function testUneReponseQuiParleDeTimeoutNEstPasUnePanne(): void
    {
        $controleur = (new ReflectionClass(ChatController::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(ChatController::class, 'isRetryableLlmChunk');

        $this->assertFalse($m->invoke($controleur, 'Un timeout est une limite de temps.'));
        $this->assertFalse($m->invoke($controleur, 'connection refused signifie que le port est fermé'));
        $this->assertTrue($m->invoke($controleur, '[Erreur OpenAI (503) : server_error — boom]'));
        $this->assertTrue($m->invoke($controleur, '[Erreur OpenAI (429) : insufficient_quota — quota]'));
    }

    // ------------------------------------------------------------------
    // 6. Garde du flux de chat (avant / après le premier mot)
    // ------------------------------------------------------------------

    /** @return array{0: ?string, 1: bool, 2: ?array, 3: ?array, 4: list<array>} texte, premier fragment parti, erreur, interruption, évènements */
    private function filtrer(string $chunk, bool $commence): array
    {
        $emis = [];
        ChatController::setEmitSink(static function (array $e) use (&$emis): void { $emis[] = $e; });

        $controleur = (new ReflectionClass(ChatController::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(ChatController::class, 'filterLlmChunk');
        $args = [$chunk, $commence, null, null];
        $texte = $m->invokeArgs($controleur, [$chunk, &$args[1], &$args[2], &$args[3]]);

        return [$texte, $args[1], $args[2], $args[3], $emis];
    }

    public function testAvantLePremierMotUnePanneDeclencheLeSecours(): void
    {
        $this->expectException(LlmRetryableException::class);
        $this->filtrer('[Erreur OpenAI (429) : insufficient_quota — You exceeded your current quota]', false);
    }

    public function testAvantLePremierMotUneAutreErreurDevientUnBlocPropre(): void
    {
        [$texte, $commence, $erreur, $interruption, $emis] = $this->filtrer(
            '[Erreur OpenAI (400) : context_length_exceeded — This model\'s maximum context length is 128000 tokens.]',
            false
        );

        $this->assertNull($texte, 'Le chunk brut ne rejoint jamais la réponse.');
        $this->assertFalse($commence);
        $this->assertNull($interruption);
        $this->assertSame('context', $erreur['kind']);
        $this->assertSame([['llm_error' => $erreur]], $emis);
        $this->assertStringNotContainsString('128000', json_encode($emis, JSON_UNESCAPED_UNICODE));
    }

    public function testApresLePremierMotLErreurEstRetenueEtNonAffichee(): void
    {
        [$texte, $commence, $erreur, $interruption, $emis] = $this->filtrer(
            '[Erreur Anthropic : overloaded_error — Overloaded]',
            true
        );

        $this->assertNull($texte, 'Le message brut du fournisseur terminait la réponse, en anglais.');
        $this->assertTrue($commence);
        $this->assertNull($erreur, 'Une réponse déjà affichée ne devient pas un bloc d\'erreur.');
        $this->assertSame('overloaded', $interruption['kind']);
        $this->assertSame([], $emis, 'La note part à la clôture, avec le reste.');
    }

    public function testLeTexteDuModeleNEstJamaisPrisPourUneErreur(): void
    {
        // Le modèle écrit lui-même des crochets : sans fournisseur connu, ou
        // sans être un fragment entier, ce n'est pas un adaptateur qui parle.
        foreach ([
            '[Erreur : le fichier est introuvable]',
            '[Erreur Inconnue : message du modèle]',
            '[Erreur OpenAI : la suite au prochain fragment',
            'Voici le journal : [Erreur OpenAI (500) : boom]',
            '[1]',
        ] as $fragment) {
            [$texte, , $erreur, $interruption] = $this->filtrer($fragment, true);
            $this->assertSame($fragment, $texte, $fragment);
            $this->assertNull($erreur);
            $this->assertNull($interruption);
        }

        // Premier fragment ordinaire : il ouvre la réponse.
        [$texte, $commence] = $this->filtrer('Bonjour', false);
        $this->assertSame('Bonjour', $texte);
        $this->assertTrue($commence);
    }

    public function testLaNoteDInterruptionNommeLaCauseSansRessemblerAUneErreur(): void
    {
        $attendus = [
            'overloaded' => 'Anthropic est surchargé',
            'server'     => 'Anthropic a rencontré un incident technique',
            'quota'      => 'le crédit de votre organisation chez Anthropic est épuisé',
            'timeout'    => 'Anthropic a cessé de répondre',
            'truncated'  => 'longueur maximale de réponse',
            'filtered'   => 'filtre de sécurité',
            'context'    => 'dépasse ce que ce modèle peut traiter',
            'generic'    => 'Anthropic a rencontré une erreur',
        ];
        foreach ($attendus as $kind => $extrait) {
            $note = LlmErrorPresenter::interruptionNote(['kind' => $kind, 'provider' => 'anthropic']);
            $this->assertStringStartsWith("\n\n*[Réponse interrompue : ", $note, $kind);
            $this->assertStringEndsWith(']*', $note, $kind);
            $this->assertStringContainsString($extrait, $note, $kind);
            // Rejouée dans l'historique ou relue, la note ne doit pas repasser pour une panne.
            $this->assertNull(LlmErrorPresenter::parse($note), $kind);
            $this->assertNull(LlmErrorPresenter::parseMidStream($note), $kind);
        }

        // La longueur maximale propose la suite : c'est le seul arrêt qui se reprend d'un mot.
        $this->assertStringContainsString('« continue »', LlmErrorPresenter::interruptionNote(['kind' => 'truncated', 'provider' => 'openai']));

        I18n::use('en');
        $this->assertStringContainsString(
            'Response interrupted: Anthropic is overloaded',
            LlmErrorPresenter::interruptionNote(['kind' => 'overloaded', 'provider' => 'anthropic'])
        );
    }

    public function testLArretRapporteParLAdaptateurSeLitDansSesJetons(): void
    {
        $this->assertSame(
            ['kind' => 'truncated', 'provider' => 'anthropic', 'status' => null],
            LlmErrorPresenter::stopDescriptor(['input' => 10, 'output' => 4096, 'stop' => ['kind' => 'truncated', 'raw' => 'max_tokens']], 'anthropic')
        );
        $this->assertNull(LlmErrorPresenter::stopDescriptor(['input' => 10, 'output' => 20], 'anthropic'));
        $this->assertNull(LlmErrorPresenter::stopDescriptor(null, 'anthropic'));
        // Genre hors de la liste fermée : jamais un problème inventé.
        $this->assertNull(LlmErrorPresenter::stopDescriptor(['stop' => ['kind' => 'quota']], 'anthropic'));
        $this->assertNull(LlmErrorPresenter::stopDescriptor(['stop' => 'length'], 'anthropic'));
    }

    // ------------------------------------------------------------------
    // 7. Ce qui reste dans la conversation
    // ------------------------------------------------------------------

    public function testLeTexteEnregistrePorteLeTitre(): void
    {
        $payload = LlmErrorPresenter::present(['kind' => 'quota', 'provider' => 'openai', 'status' => 429]);
        $texte   = LlmErrorPresenter::toPlainText($payload);

        $this->assertSame("⚠️ Crédit épuisé chez OpenAI\n\n" . $payload['message'], $texte);

        // Sans titre (échec d'une action de connecteur) : message seul, comme avant.
        $this->assertSame('⚠️ Échec.', LlmErrorPresenter::toPlainText(['message' => 'Échec.']));
        // Titre démesuré ou sur plusieurs lignes : chat.js ne le relirait pas comme un titre.
        $this->assertSame('⚠️ Échec.', LlmErrorPresenter::toPlainText(['title' => str_repeat('x', 121), 'message' => 'Échec.']));
        $this->assertSame("⚠️ Titre sur deux lignes\n\nÉchec.", LlmErrorPresenter::toPlainText(['title' => "Titre sur\ndeux lignes", 'message' => 'Échec.']));
        $this->assertSame('⚠️ Une erreur est survenue.', LlmErrorPresenter::toPlainText([]));
    }

    /**
     * chat.js relit ce texte au rechargement : le VRAI code est exécuté sous
     * node. Sans node (conteneur applicatif), le test est sauté.
     */
    public function testLeBlocRechargeRetrouveSonTitre(): void
    {
        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $chat = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $payload = LlmErrorPresenter::present(['kind' => 'context', 'provider' => 'anthropic', 'status' => 400]);
        $cas = [
            'titre'    => LlmErrorPresenter::toPlainText($payload),
            'seul'     => '⚠️ Échec de l\'action Google Drive (Création) : fichier introuvable',
            'ancien'   => '⚠️ La réponse n\'a pas pu être générée. Réessayez dans un instant.',
            'longue'   => "⚠️ " . str_repeat('x', 200) . "\n\nSuite",
        ];

        // Erreur enregistrée en anglais, relue par un lecteur francophone : le
        // ton de l'encadré ne dépend pas de la langue courante.
        I18n::use('en');
        $cas['anglais'] = LlmErrorPresenter::toPlainText(LlmErrorPresenter::present(['kind' => 'quota', 'provider' => 'openai', 'status' => 429]));
        I18n::use('fr');

        $script = "const t = (s) => s;\n"
            . 'const window = { LLM_ERROR_TITLES: ' . json_encode(LlmErrorPresenter::titleKinds(), JSON_UNESCAPED_UNICODE)
            . ', LLM_ERROR_ACTIONS: ' . json_encode(LlmErrorPresenter::clientActions(), JSON_UNESCAPED_UNICODE) . " };\n"
            . self::fonctionJs($chat, 'looksLikeErrorMessage') . "\n"
            . self::fonctionJs($chat, 'errorPayloadFromText') . "\n"
            . self::fonctionJs($chat, 'errorKindFromTitle') . "\n"
            . self::fonctionJs($chat, 'knownErrorTitleKind') . "\n"
            . self::fonctionJs($chat, 'errorContactKinds') . "\n"
            . self::fonctionJs($chat, 'errorPlainText') . "\n"
            . 'const cas = ' . json_encode($cas, JSON_UNESCAPED_UNICODE) . ";\n"
            . "const out = {};\n"
            . "for (const [nom, texte] of Object.entries(cas)) {\n"
            . "  const p = errorPayloadFromText(texte);\n"
            . "  out[nom] = { erreur: looksLikeErrorMessage(texte), genre: p.kind, titre: p.title, message: p.message, retour: errorPlainText(p), contact: p.contact_admin === true };\n"
            . "}\n"
            . "process.stdout.write(JSON.stringify(out));\n";

        $r = self::executerJs($node, $script);

        $this->assertTrue($r['titre']['erreur']);
        $this->assertSame($payload['title'], $r['titre']['titre'], 'Le titre nomme la cause : il doit survivre au rechargement.');
        $this->assertSame($payload['message'], $r['titre']['message']);
        $this->assertSame($cas['titre'], $r['titre']['retour'], 'Copier un bloc rechargé rend le texte enregistré.');
        // Le genre n'est pas enregistré : c'est le titre qui rend à l'encadré son ton.
        $this->assertSame('context', $r['titre']['genre'], 'Ambre en direct, l\'encadré redevenait rouge à la relecture.');
        $this->assertSame('quota', $r['anglais']['genre']);
        $this->assertSame('Out of credit at OpenAI', $r['anglais']['titre']);
        $this->assertSame('generic', $r['seul']['genre']);
        // « Prévenir un administrateur » suit la cause relue : oui pour un
        // crédit épuisé, non pour trop de contenu (l'utilisateur y peut
        // quelque chose) ni pour l'échec d'une action de connecteur.
        $this->assertTrue($r['anglais']['contact']);
        $this->assertFalse($r['titre']['contact']);
        $this->assertFalse($r['seul']['contact']);
        $this->assertFalse($r['ancien']['contact']);

        // Un seul paragraphe : message seul sous le titre générique, comme avant.
        $this->assertSame('Une erreur est survenue', $r['seul']['titre']);
        $this->assertSame("Échec de l'action Google Drive (Création) : fichier introuvable", $r['seul']['message']);
        $this->assertSame('Une erreur est survenue', $r['ancien']['titre']);
        // Une première ligne démesurée n'est pas un titre.
        $this->assertSame('Une erreur est survenue', $r['longue']['titre']);
    }

    /**
     * En conversation vocale, la personne écoute : le message qui clôt la
     * conversation reprend la cause affichée dans le fil, au lieu d'un « la
     * réponse est en erreur » qui n'apprend rien. Le VRAI écouteur de chat.js
     * est exécuté sous node.
     */
    public function testLaConversationVocaleDitLaCauseEnSArretant(): void
    {
        $chat = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');

        // Les deux clôtures d'un tour (payload `done`, fermeture du flux) transmettent le titre.
        $this->assertSame(2, substr_count($chat, 'errorTitle: turnErrorTitle(state)'));
        $client = (array)(require ROOT . '/lang/en.client.php');
        $this->assertArrayHasKey('Conversation vocale interrompue — {cause}', $client);
        $this->assertStringContainsString('{cause}', (string)$client['Conversation vocale interrompue — {cause}']);

        $node = self::node();
        if ($node === null) {
            $this->markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $titre  = LlmErrorPresenter::present(['kind' => 'quota', 'provider' => 'openai', 'status' => 429])['title'];
        // La réponse est lue PENDANT qu'elle s'écrit : l'écouteur de fin de tour
        // dit seulement comment elle s'est terminée, et la lecture en cours
        // (lue ou non, finie ou non) décide avec lui de la suite.
        $script = "const t = (s, v) => s.replace(/\\{(\\w+)\\}/g, (m, k) => (v && k in v ? v[k] : m));\n"
            . "let mode = 'thinking', turnConvId = '42', turnJobId = 'J1', turnOutcome = null, progressTick = 0;\n"
            . "let readerDone = true, readerSpoke = false;\n"
            . "const log = [];\n"
            . "let wakeReader = () => {};\n"
            . "const stopReading = () => { readerDone = true; };\n"
            . "const startListening = () => log.push(['ecouter']);\n"
            . "const endConversation = (m) => log.push(['fin', m ?? null]);\n"
            . "let ecouteur = null;\n"
            . "const document = { addEventListener: (nom, fn) => { ecouteur = fn; } };\n"
            . self::fonctionInterneJs($chat, 'isTurnEvent') . "\n"
            . self::fonctionInterneJs($chat, 'afterReply') . "\n"
            . self::ecouteurJs($chat, 'mia:chat-turn-ended') . "\n"
            . self::fonctionJs($chat, 'turnErrorTitle') . "\n"
            . "const jouer = (d, lecture) => {\n"
            . "  log.length = 0; mode = 'thinking'; turnOutcome = null;\n"
            . "  readerDone = !!(lecture && lecture.finie); readerSpoke = !!(lecture && lecture.parle);\n"
            . "  ecouteur({ detail: Object.assign({ convId: '42', jobId: 'J1' }, d) }); return log.slice();\n"
            . "};\n"
            . 'const titre = ' . json_encode($titre, JSON_UNESCAPED_UNICODE) . ";\n"
            . "const out = {\n"
            . "  cause:  jouer({ status: 'done', msgId: 7, hadError: true, errorTitle: turnErrorTitle({ errorPayload: { title: ' ' + titre + ' ' } }) }),\n"
            . "  sans:   jouer({ status: 'done', msgId: 7, hadError: true, errorTitle: turnErrorTitle({}) }),\n"
            . "  perdue: jouer({ status: 'lost', msgId: null, hadError: false, errorTitle: '' }),\n"
            . "  enCours: jouer({ status: 'done', msgId: 7, hadError: false, errorTitle: '' }, { parle: true }),\n"
            . "  lue:    jouer({ status: 'done', msgId: 7, hadError: false, errorTitle: '' }, { parle: true, finie: true }),\n"
            . "  vide:   jouer({ status: 'done', msgId: 7, hadError: false, errorTitle: '' }, { finie: true }),\n"
            . "  perdueApres: jouer({ status: 'lost', msgId: null, hadError: false, errorTitle: '' }, { parle: true }),\n"
            . "  ailleurs: jouer({ convId: '43', status: 'done', msgId: 7, hadError: true, errorTitle: titre }),\n"
            . "  autreReponse: jouer({ jobId: 'J0', status: 'cancelled', msgId: null, hadError: false, errorTitle: '' }),\n"
            . "  titres: [turnErrorTitle(null), turnErrorTitle({ errorPayload: { title: 42 } })],\n"
            . "};\n"
            . "process.stdout.write(JSON.stringify(out));\n";

        $r = self::executerJs($node, $script);

        $this->assertSame([['fin', 'Conversation vocale interrompue — Crédit épuisé chez OpenAI']], $r['cause']);
        $this->assertSame([['fin', 'Conversation vocale interrompue : la réponse est en erreur.']], $r['sans']);
        $this->assertSame([['fin', 'Conversation vocale interrompue : la réponse a été perdue.']], $r['perdue']);
        $this->assertSame([], $r['enCours'], 'Une réponse complète en cours de lecture est lue jusqu\'au bout.');
        $this->assertSame([['ecouter']], $r['lue'], 'Lue jusqu\'au bout : MIA écoute.');
        $this->assertSame([['ecouter']], $r['vide'], 'Rien à lire : MIA écoute.');
        $this->assertSame([], $r['perdueApres'], 'Déjà commencée, la lecture finit ce qui est écrit.');
        $this->assertSame([], $r['ailleurs'], 'Le tour d\'une autre conversation ne clôt rien.');
        $this->assertSame([], $r['autreReponse'], 'La clôture d\'une réponse arrêtée ne passe pas pour celle du tour.');
        $this->assertSame(['', ''], $r['titres']);
    }

    // ------------------------------------------------------------------
    // 8. Câblage
    // ------------------------------------------------------------------

    public function testLesDeuxFluxDeChatPassentParLaMemeGarde(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');

        $this->assertSame(
            2,
            substr_count($src, '$this->filterLlmChunk($chunk, $firstRealChunkSent, $streamError, $interruption)'),
            'runSend() et regenerate() partagent la garde du flux.'
        );
        // Clôture : cause connue plutôt que « réponse vide », note sous une réponse coupée.
        $this->assertSame(2, substr_count($src, '$interruption ??= LlmErrorPresenter::stopDescriptor('));
        $this->assertSame(2, substr_count($src, '$note = LlmErrorPresenter::interruptionNote($interruption);'));
        $this->assertSame(2, substr_count($src, '? LlmErrorPresenter::present($interruption)'));
    }

    public function testLaBasculeNEnvoiePlusLeMessageBrutAuNavigateur(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');

        $this->assertStringNotContainsString(
            'buildFallbackSseEvent($currentLlm, $fallback, $err->getMessage())',
            $src,
            'Le chunk de l\'adaptateur porte le message brut du fournisseur : il reste dans les journaux.'
        );
        $this->assertStringContainsString(
            "buildFallbackSseEvent(\$currentLlm, \$fallback, (string)\$cause['title'], (string)\$cause['kind'])",
            $src
        );
        // … et la bascule reste visible après coup, dans la trace de la réponse.
        $this->assertStringContainsString("\$this->traceAction('relais_secours', self::failoverTraceDetail(", $src);
        $this->assertArrayHasKey('relais_secours', ChatController::ACTION_LABELS);
        $this->assertSame('relay', ChatController::actionIcon('relais_secours'));
        $this->assertLessThanOrEqual(Message::ACTION_LABEL_MAX, mb_strlen(ChatController::ACTION_LABELS['relais_secours']));
    }

    public function testLeDetailDeLaTraceNommeLesDeuxModelesPuisLaRaison(): void
    {
        $this->assertSame(
            'GPT-5.6 → Claude Opus 5 · Crédit épuisé chez OpenAI',
            ChatController::failoverTraceDetail([
                'from' => 'openai::gpt-5.6', 'to' => 'anthropic::claude-opus-5',
                'from_label' => 'GPT-5.6', 'to_label' => 'Claude Opus 5',
                'reason' => 'Crédit épuisé chez OpenAI',
            ])
        );
        // Sans libellé ni raison : les identifiants, rien d'inventé.
        $this->assertSame('a → b', ChatController::failoverTraceDetail(['from' => 'a', 'to' => 'b']));
    }

    public function testLaTableDesTitresCouvreChaqueGenreDansChaqueLangue(): void
    {
        I18n::use('en');
        $table = LlmErrorPresenter::titleKinds();
        $this->assertSame('en', I18n::locale(), 'La locale est un statique de process : la table doit la rendre.');

        foreach (array_keys(I18n::LOCALES) as $langue) {
            I18n::use($langue);
            foreach (LlmErrorPresenter::KINDS as $kind) {
                foreach (['openai', null] as $fournisseur) {
                    $titre = LlmErrorPresenter::present(['kind' => $kind, 'provider' => $fournisseur, 'status' => null])['title'];
                    $trouve = null;
                    foreach ($table as $prefixe => $genre) {
                        if (str_starts_with($titre, $prefixe)) {
                            $trouve = $genre;
                            break;
                        }
                    }
                    $this->assertSame($kind, $trouve, "$langue : « $titre » n'est pas rendu à son genre.");
                }
            }
        }

        // Injectée dans la page du chat, relue par errorPayloadFromText().
        $this->assertStringContainsString(
            'window.LLM_ERROR_TITLES = <?= json_encode(LlmErrorPresenter::titleKinds(), JSON_UNESCAPED_UNICODE) ?>;',
            (string)file_get_contents(ROOT . '/templates/chat/index.php')
        );
    }

    public function testLEchecDUnJobGardeSonTitreDansLeFil(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Jobs/JobQueue.php');
        $this->assertStringContainsString("Message::create(\$convId, 'assistant', LlmErrorPresenter::toPlainText(", $src);
        $this->assertStringNotContainsString("'⚠️ ' . (string)(\$error['message']", $src);
    }

    public function testLeChargementDeLaPageRendAussiLeBlocDErreur(): void
    {
        $chat = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');

        // Le rendu initial des messages servis par PHP ignorait le préfixe ⚠ :
        // le bloc n'apparaissait qu'en direct et au changement de conversation.
        $debut = strpos($chat, '// ---- Rendu markdown des messages existants ----');
        $this->assertNotFalse($debut);
        $bloc = substr($chat, $debut, 1200);
        $this->assertStringContainsString('looksLikeErrorMessage(raw)', $bloc);
        $this->assertStringContainsString('buildErrorBlockHtml(errorPayloadFromText(raw))', $bloc);

        // Bloc en direct : même texte brut que celui que le serveur enregistre.
        $this->assertStringContainsString('contentEl.dataset.raw = errorPlainText(p);', $chat);
        // Bascule annoncée avec sa raison, à l'envoi comme à la régénération.
        $this->assertSame(2, substr_count($chat, 'announceLlmFallback(json.llm_fallback)'));
    }

    public function testLesNouveauxGenresOntLeurTon(): void
    {
        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');

        // Ambre : quelque chose à régler, pas une panne.
        foreach (['permission', 'quota', 'model', 'context', 'attachment', 'filtered', 'truncated'] as $kind) {
            foreach (['', ' .msg-error-icon', ' .msg-error-title'] as $suffixe) {
                $this->assertStringContainsString('.msg-error[data-kind="' . $kind . '"]' . $suffixe, $css, $kind);
            }
        }
        // Rouge (défaut) : le fournisseur ne répond pas.
        foreach (['overloaded', 'server', 'network', 'timeout', 'rate_limit', 'empty'] as $kind) {
            $this->assertStringNotContainsString('.msg-error[data-kind="' . $kind . '"]', $css, $kind);
        }
    }

    // ------------------------------------------------------------------
    // 9. Les outils disent la même chose
    // ------------------------------------------------------------------

    public function testUnePageWebCoupeeLeDit(): void
    {
        $avis = HtmlPageJob::incompleteNotice(['kind' => 'truncated', 'provider' => 'anthropic']);
        $this->assertSame('Page incomplète', $avis['title']);
        $this->assertStringContainsString('longueur maximale', $avis['message']);
        $this->assertStringContainsString('la fin manque', $avis['message']);

        // Autre cause : la phrase du présentateur, derrière le constat.
        $panne = HtmlPageJob::incompleteNotice(['kind' => 'overloaded', 'provider' => 'anthropic']);
        $this->assertStringStartsWith("La rédaction de la page s'est arrêtée avant la fin. Anthropic est surchargé", $panne['message']);

        // Persisté sous la carte de page, au format que chat.js relit.
        $this->assertStringStartsWith("⚠️ Page incomplète\n\n", LlmErrorPresenter::toPlainText($avis));

        $job = (string)file_get_contents(ROOT . '/src/Jobs/HtmlPageJob.php');
        // Une erreur arrivée après le début de la page n'entre pas dans le document.
        $this->assertStringContainsString('LlmErrorPresenter::parseMidStream($chunk)', $job);
        $this->assertStringContainsString("\$interruption ??= LlmErrorPresenter::stopDescriptor(\$tokens, (string)\$tool['provider']);", $job);
        $this->assertStringContainsString('LlmErrorPresenter::toPlainText(self::incompleteNotice($interruption))', $job);
    }

    public function testUnePresentationCoupeeNEstPasReparee(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $debut = strpos($src, 'private function runDeckJsonCall(');
        $this->assertNotFalse($debut);
        $corps = substr($src, $debut, 5000);

        $coupe  = strpos($corps, 'LlmErrorPresenter::stopDescriptor($tokens, $provider)');
        $repare = strpos($corps, '// Réparation : on renvoie la sortie fautive');
        $this->assertNotFalse($coupe, 'Un JSON coupé par la longueur maximale doit être reconnu.');
        $this->assertNotFalse($repare);
        $this->assertLessThan($repare, $coupe, 'La « réparation » rejouerait la même coupe, facturée une seconde fois.');
    }

    public function testLOutilImageNommeLesMemesCauses(): void
    {
        $m = new ReflectionMethod(ImageGenerator::class, 'formatApiError');

        // Tout 429 devenait « quota dépassé (tier gratuit) », le reste partait en anglais.
        $credit = $m->invoke(null, ['message' => 'Billing hard limit has been reached', 'code' => 'billing_hard_limit_reached'], 'OpenAI', 400);
        $this->assertStringContainsString('Le crédit de votre organisation chez OpenAI est épuisé', $credit);
        $this->assertStringNotContainsString('Billing', $credit);

        $filtre = $m->invoke(null, ['message' => 'Your request was rejected as a result of our safety system.', 'code' => 'moderation_blocked'], 'OpenAI', 400);
        $this->assertStringContainsString('filtre de sécurité', $filtre);

        // Google recopie le statut dans l'erreur : il sert quand l'appelant ne le connaît pas.
        $debit = $m->invoke(null, ['code' => 429, 'message' => 'Quota exceeded. Please retry in 46s.', 'status' => 'RESOURCE_EXHAUSTED'], 'Gemini', null);
        $this->assertStringContainsString('Réessayez dans 46 secondes ;', $debit);
        $this->assertStringNotContainsString('tier gratuit', $debit);

        // Un paramètre refusé garde le message du fournisseur : il nomme le réglage en cause.
        $taille = $m->invoke(null, ['message' => "Invalid value: '1024x2048'. Supported values are: '1024x1024'.", 'code' => 'invalid_value'], 'OpenAI', 400);
        $this->assertSame("Invalid value: '1024x2048'. Supported values are: '1024x1024'.", $taille);
    }

    /**
     * Bouton « Tester » d'un outil : l'administrateur garde le message d'origine
     * (il nomme le code, le paramètre), précédé de la cause en clair.
     */
    public function testLeTestDUnOutilNommeLaCausePourLAdministrateur(): void
    {
        $chunk = '[Erreur OpenAI (429) : insufficient_quota — You exceeded your current quota.]';
        $this->assertSame('Crédit épuisé chez OpenAI — ' . $chunk, LlmErrorPresenter::adminLine($chunk));
        // Un texte qui n'est pas une erreur d'adaptateur passe tel quel.
        $this->assertSame('Réponse HTML reçue.', LlmErrorPresenter::adminLine(' Réponse HTML reçue. '));

        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertSame(3, substr_count($admin, "'message' => LlmErrorPresenter::adminLine(\$trimmed)"));
    }

    public function testLaDocumentationNommeLesNouveauxEncadres(): void
    {
        $page = (string)file_get_contents(ROOT . '/docs/user/fonctionnalites/modeles/choisir-un-modele.md');
        foreach ([
            'Crédit épuisé chez', 'Trop de contenu pour ce modèle', 'Service surchargé', 'Modèle indisponible',
            'Pièce jointe refusée', 'Demande bloquée par le filtre de sécurité', 'Limite de longueur atteinte',
            'Délai de réponse dépassé', 'Accès au modèle refusé', 'Réponse interrompue',
        ] as $titre) {
            $this->assertStringContainsString($titre, $page, "Encadré non documenté : $titre");
        }
        // Les titres cités par la page sont ceux que le présentateur écrit réellement.
        foreach (['quota', 'context', 'overloaded', 'model', 'attachment', 'filtered', 'truncated', 'timeout', 'permission'] as $kind) {
            $titre = LlmErrorPresenter::present(['kind' => $kind, 'provider' => null, 'status' => null])['title'];
            $titre = str_replace(' le fournisseur', '', $titre);
            $this->assertStringContainsString($titre, $page, $kind);
        }

        foreach (['docs/GUIDE-UTILISATEUR.md', 'docs/GUIDE-ADMIN.md', 'docs/user/fonctionnalites/resoudre-un-probleme.md'] as $chemin) {
            $this->assertStringContainsString('Crédit épuisé', (string)file_get_contents(ROOT . '/' . $chemin), $chemin);
        }
        $this->assertStringContainsString('erreurs-modeles-claires', (string)file_get_contents(ROOT . '/docs/NOUVEAUTES.md'));
    }

    // ------------------------------------------------------------------

    /** Source d'une fonction de premier niveau de chat.js (accolades équilibrées). */
    private static function fonctionJs(string $source, string $nom): string
    {
        $debut = strpos($source, "\nfunction " . $nom . '(');
        self::assertNotFalse($debut, "Fonction $nom introuvable dans chat.js.");
        $ouvre = strpos($source, '{', $debut);
        $niveau = 0;
        for ($i = $ouvre, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $niveau++;
            } elseif ($source[$i] === '}') {
                $niveau--;
                if ($niveau === 0) {
                    return substr($source, $debut + 1, $i - $debut);
                }
            }
        }
        self::fail("Fonction $nom : accolades non équilibrées.");
    }

    /** Source d'une fonction déclarée DANS un bloc de chat.js (IIFE), accolades équilibrées. */
    private static function fonctionInterneJs(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertNotFalse($debut, "Fonction $nom introuvable dans chat.js.");
        $ouvre = strpos($source, '{', $debut);
        $niveau = 0;
        for ($i = $ouvre, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $niveau++;
            } elseif ($source[$i] === '}') {
                $niveau--;
                if ($niveau === 0) {
                    return substr($source, $debut, $i + 1 - $debut);
                }
            }
        }
        self::fail("Fonction $nom : accolades non équilibrées.");
    }

    /**
     * Source de l'écouteur `document.addEventListener('<évènement>', …);` de
     * chat.js, de son ouverture à la parenthèse qui le ferme.
     */
    private static function ecouteurJs(string $source, string $evenement): string
    {
        $debut = strpos($source, "document.addEventListener('" . $evenement . "'");
        self::assertNotFalse($debut, "Écouteur $evenement introuvable dans chat.js.");
        $ouvre = strpos($source, '{', $debut);
        $niveau = 0;
        for ($i = $ouvre, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $niveau++;
            } elseif ($source[$i] === '}') {
                $niveau--;
                if ($niveau === 0) {
                    $fin = strpos($source, ');', $i);
                    self::assertNotFalse($fin, "Écouteur $evenement : fermeture introuvable.");
                    return substr($source, $debut, $fin + 2 - $debut);
                }
            }
        }
        self::fail("Écouteur $evenement : accolades non équilibrées.");
    }

    /** @return array<string, mixed> */
    private static function executerJs(string $node, string $script): array
    {
        $fichier = tempnam(sys_get_temp_dir(), 'mia-erreur-js-');
        file_put_contents($fichier, $script);
        try {
            $processus = proc_open([$node, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            if (!is_resource($processus)) {
                self::fail('Impossible de lancer node.');
            }
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($fichier);
        }
        $resultat = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultat)) {
            self::fail("chat.js a échoué sous node (code $code) :\n" . $erreurs);
        }
        return $resultat;
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }
}
